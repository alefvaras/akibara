<?php
/**
 * SubscriberRepository — persistencia de suscripciones BIS sobre $wpdb.
 *
 * $wpdb INYECTADO (no global) → unit-testeable con Mockery sin WordPress.
 * Todas las mutaciones de estado retornan por FILAS REALMENTE AFECTADAS:
 * esa es la primitiva de idempotencia del módulo (spec BIS, decisión fijada):
 *
 *   - mark_notified(): claim atómico `UPDATE … WHERE id AND status='subscribed'`.
 *     El email se envía SOLO si este método retorna true (at-most-once bajo
 *     workers concurrentes de Action Scheduler). NO confiar en dedup de AS.
 *   - reactivate(): re-alta atómica (status + notified_at NULL) para
 *     re-suscripciones y para devolver la fila al pool tras un fallo de envío.
 *   - record_failed_attempt(): reintento ACOTADO — attempts+1 y vuelta a
 *     'subscribed' hasta MAX; al superarlo, estado terminal 'failed'.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BackInStock;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.53.0
 */
final class SubscriberRepository {

	/** Intentos de envío antes del estado terminal 'failed'. */
	public const MAX_ATTEMPTS = 3;

	/** @var \wpdb */
	private \wpdb $db;

	/** @var string */
	private string $table;

	/**
	 * @param \wpdb  $db    Conexión (inyectada; en runtime el global).
	 * @param string $table Nombre completo de tabla (Install::table_name()).
	 */
	public function __construct( \wpdb $db, string $table ) {
		$this->db    = $db;
		$this->table = $table;
	}

	/**
	 * Alta de suscripción. Race-safe: el UNIQUE (product_id, email) absorbe
	 * el doble-submit; ante duplicado retorna 0 sin error fatal.
	 *
	 * @param int      $product_id Producto agotado.
	 * @param string   $email      Email ya validado/sanitizado por el caller.
	 * @param int|null $user_id    Usuario WP o null (invitado).
	 * @param string   $token      Token hex de 64 chars (el caller lo genera).
	 * @return int ID de la fila nueva, o 0 si el INSERT falló. El caller debe
	 *             releer para distinguir una carrera de UNIQUE de otro error.
	 */
	public function insert( int $product_id, string $email, ?int $user_id, string $token ): int {
		$inserted = $this->db->insert(
			$this->table,
			array(
				'product_id'      => $product_id,
				'email'           => $email,
				'user_id'         => $user_id,
				'token'           => $token,
				'status'          => Subscriber::STATUS_SUBSCRIBED,
				'notify_attempts' => 0,
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Busca la suscripción de un email para un producto (cualquier estado).
	 *
	 * @param int    $product_id Producto.
	 * @param string $email      Email.
	 * @return Subscriber|null
	 */
	public function find( int $product_id, string $email ): ?Subscriber {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE product_id = %d AND email = %s",
				$product_id,
				$email
			),
			ARRAY_A
		);

		return $row ? Subscriber::from_row( $row ) : null;
	}

	/**
	 * Busca por token de baja.
	 *
	 * @param string $token Token hex.
	 * @return Subscriber|null
	 */
	public function find_by_token( string $token ): ?Subscriber {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE token = %s",
				$token
			),
			ARRAY_A
		);

		return $row ? Subscriber::from_row( $row ) : null;
	}

	/**
	 * Cuántos esperan aviso para un producto. Es el guard barato del
	 * StockWatcher (no-op cuando nadie espera) y el corte del throttle.
	 *
	 * @param int $product_id Producto.
	 * @return int
	 */
	public function count_waiting( int $product_id ): int {
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE product_id = %d AND status = %s",
				$product_id,
				Subscriber::STATUS_SUBSCRIBED
			)
		);
	}

	/**
	 * Lote de suscriptores en espera para un producto (orden FIFO).
	 *
	 * @param int $product_id Producto.
	 * @param int $limit      Tamaño de lote (>0).
	 * @return array<int, Subscriber>
	 */
	public function for_product( int $product_id, int $limit ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE product_id = %d AND status = %s ORDER BY created_at ASC, id ASC LIMIT %d",
				$product_id,
				Subscriber::STATUS_SUBSCRIBED,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_map( array( Subscriber::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * CLAIM atómico: gana quien pasa la fila subscribed→notified. Retorna
	 * true SOLO si esta llamada afectó la fila — el caller envía el email
	 * únicamente en ese caso (at-most-once real del módulo).
	 *
	 * @param int $id ID de suscripción.
	 * @return bool
	 */
	public function mark_notified( int $id ): bool {
		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table} SET status = %s, notified_at = %s WHERE id = %d AND status = %s",
				Subscriber::STATUS_NOTIFIED,
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				Subscriber::STATUS_SUBSCRIBED
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Re-alta atómica: vuelve la fila a 'subscribed' y limpia notified_at.
	 * Usada al re-suscribirse (producto se agotó de nuevo) y como rollback
	 * del claim cuando el envío falla y quedan intentos.
	 *
	 * @param int $id ID de suscripción.
	 * @return bool True si afectó la fila.
	 */
	public function reactivate( int $id ): bool {
		// Una fila failed que vuelve a suscribirse reinicia notify_attempts y
		// recupera su presupuesto completo de reintentos.
		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table} SET status = %s, notified_at = NULL, notify_attempts = 0 WHERE id = %d",
				Subscriber::STATUS_SUBSCRIBED,
				$id
			)
		);

		return (int) $affected >= 1;
	}

	/**
	 * Registra un fallo de envío con reintento ACOTADO:
	 *   attempts+1 < $max  → vuelve a 'subscribed' (reintentará el próximo lote)
	 *   attempts+1 >= $max → 'failed' terminal (drena count_waiting).
	 *
	 * @param int $id  ID de suscripción.
	 * @param int $max Máximo de intentos (default MAX_ATTEMPTS).
	 * @return string Estado resultante ('subscribed' o 'failed').
	 */
	public function record_failed_attempt( int $id, int $max = self::MAX_ATTEMPTS ): string {
		// Un UPDATE con CASE incrementa attempts y decide el estado sin ventana
		// intermedia. MySQL/MariaDB evalúa SET de izquierda a derecha: el CASE ya
		// ve el incremento y no debe sumar otro. El guard status=notified impide
		// resucitar una baja ocurrida entre el claim y el fallo.
		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table}
				SET notify_attempts = notify_attempts + 1,
					notified_at = NULL,
					status = CASE WHEN notify_attempts >= %d THEN %s ELSE %s END
				WHERE id = %d AND status = %s",
				$max,
				Subscriber::STATUS_FAILED,
				Subscriber::STATUS_SUBSCRIBED,
				$id,
				Subscriber::STATUS_NOTIFIED
			)
		);

		$status = (string) $this->db->get_var(
			$this->db->prepare(
				"SELECT status FROM {$this->table} WHERE id = %d",
				$id
			)
		);

		return '' !== $status ? $status : Subscriber::STATUS_FAILED;
	}

	/**
	 * Todas las suscripciones de un email (cualquier estado) — para el
	 * exporter de privacidad de WP.
	 *
	 * @param string $email Email del titular.
	 * @return array<int, Subscriber>
	 */
	public function for_email( string $email ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE email = %s ORDER BY created_at ASC",
				$email
			),
			ARRAY_A
		);

		return array_map( array( Subscriber::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * Borrado físico de todas las suscripciones de un email — para el eraser
	 * de privacidad (no hay nada que retener: sin el email la fila no sirve).
	 *
	 * @param string $email Email del titular.
	 * @return int Filas borradas.
	 */
	public function erase_email( string $email ): int {
		return (int) $this->db->delete( $this->table, array( 'email' => $email ), array( '%s' ) );
	}

	/**
	 * Borra todas las filas de un producto eliminado.
	 *
	 * @param int $product_id Producto o variación.
	 * @return int Filas borradas.
	 */
	public function erase_product( int $product_id ): int {
		return (int) $this->db->delete( $this->table, array( 'product_id' => $product_id ), array( '%d' ) );
	}

	/**
	 * Purga filas terminales anteriores al corte. Las suscripciones activas
	 * nunca se eliminan por antigüedad: representan demanda aún pendiente.
	 *
	 * @param string $cutoff_utc Fecha UTC `Y-m-d H:i:s`.
	 * @return int Filas borradas.
	 */
	public function purge_terminal_before( string $cutoff_utc ): int {
		return (int) $this->db->query(
			$this->db->prepare(
				"DELETE FROM {$this->table}
				WHERE status IN (%s, %s, %s)
				AND COALESCE(notified_at, created_at) < %s",
				Subscriber::STATUS_NOTIFIED,
				Subscriber::STATUS_UNSUBSCRIBED,
				Subscriber::STATUS_FAILED,
				$cutoff_utc
			)
		);
	}

	/**
	 * Resumen de demanda por producto para el panel admin: cuántos esperan,
	 * cuántos ya fueron notificados y la última alta. UNA sola query agregada
	 * sobre la tabla propia (idx_product_status cubre el hot path del COUNT
	 * condicional); los títulos/links de producto los resuelve el caller
	 * (con prime de caches — doctrina woocommerce-performance).
	 *
	 * @param int $limit Máximo de productos (>0).
	 * @return array<int, array{product_id: int, waiting: int, notified: int, failed: int, last_signup: string}>
	 */
	public function demand_summary( int $limit = 50 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT product_id,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS waiting,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS notified,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed,
					MAX(created_at) AS last_signup
				FROM {$this->table}
				GROUP BY product_id
				ORDER BY waiting DESC, last_signup DESC
				LIMIT %d",
				Subscriber::STATUS_SUBSCRIBED,
				Subscriber::STATUS_NOTIFIED,
				Subscriber::STATUS_FAILED,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $r ): array => array(
				'product_id'  => (int) $r['product_id'],
				'waiting'     => (int) $r['waiting'],
				'notified'    => (int) $r['notified'],
				'failed'      => (int) $r['failed'],
				'last_signup' => (string) $r['last_signup'],
			),
			$rows ?: array()
		);
	}

	/**
	 * Cuántas OTRAS esperas vivas tiene ese correo, sin contar la fila dada.
	 *
	 * Sirve para que la pantalla de baja pueda decir «tus avisos de otros 2
	 * productos siguen activos»: quien espera varios tomos y se da de baja de uno
	 * cree, si no, que lo hemos borrado de todo.
	 *
	 * @since 1.114.2
	 *
	 * @param string $email    Correo del suscriptor.
	 * @param int    $excepto  Id de la fila que se está dando de baja.
	 * @return int
	 */
	public function count_other_waiting( string $email, int $excepto ): int {
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table}
				WHERE email = %s AND status = %s AND id <> %d",
				$email,
				Subscriber::STATUS_SUBSCRIBED,
				$excepto
			)
		);
	}

	/**
	 * Cuánta gente espera y en cuántos productos, contado sobre la tabla ENTERA.
	 *
	 * El titular del panel salía de sumar las filas que se pintan, y ésas vienen
	 * con `LIMIT 50`: pasado ese punto el número de personas se quedaba corto sin
	 * decirlo. Aquí no hay techo posible — son dos agregados de una sola query.
	 *
	 * @since 1.114.2
	 *
	 * @return array{personas: int, productos: int}
	 */
	public function waiting_totals(): array {
		$fila = $this->db->get_row(
			$this->db->prepare(
				"SELECT COUNT(*) AS personas, COUNT(DISTINCT product_id) AS productos
				FROM {$this->table}
				WHERE status = %s",
				Subscriber::STATUS_SUBSCRIBED
			),
			ARRAY_A
		);

		return array(
			'personas'  => (int) ( $fila['personas'] ?? 0 ),
			'productos' => (int) ( $fila['productos'] ?? 0 ),
		);
	}

	/**
	 * Lista todos los productos con al menos un suscriptor pendiente, sin techo.
	 * No aplica el LIMIT de demand_summary() en el panel. La reactivación
	 * del despacho debe alcanzar también los productos de la cola larga.
	 *
	 * @since 1.114.1
	 *
	 * @return array<int, int> IDs de producto con al menos un suscriptor en espera.
	 */
	public function products_with_waiting(): array {
		$rows = $this->db->get_col(
			$this->db->prepare(
				"SELECT DISTINCT product_id
				FROM {$this->table}
				WHERE status = %s",
				Subscriber::STATUS_SUBSCRIBED
			)
		);

		return array_map( 'intval', $rows ?: array() );
	}

	/**
	 * Baja one-click (por ID; el caller resuelve el token antes). Idempotente:
	 * repetir la baja no es error.
	 *
	 * @param int $id ID de suscripción.
	 * @return bool True si la fila quedó (o ya estaba) dada de baja.
	 */
	public function unsubscribe( int $id ): bool {
		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table} SET status = %s WHERE id = %d",
				Subscriber::STATUS_UNSUBSCRIBED,
				$id
			)
		);

		return true;
	}
}
