<?php
/**
 * DigestQueue — la cola del digest y, en la MISMA tabla, la marca de avisado.
 *
 * Que sean la misma tabla es la decisión central del módulo. `uniq_event`
 * (`u{user_id}:p{product_id}`) es la marca: la fila nace `pending` con
 * `INSERT IGNORE`, pasa a `sent` y se queda. Un tomo no se avisa dos veces al
 * mismo cliente por una RESTRICCIÓN DE LA BASE, no por una lectura previa
 * —que sería check-then-act, con su carrera.
 *
 * El motivo de un `drop` NO es cosmético
 * --------------------------------------
 *   - `owned` y `optout` MARCAN la fila `dropped`: son decisiones terminales
 *     (ya lo tiene, o no lo quiere).
 *   - `unpublished` BORRA la fila. Es transitorio: si se marcara, el
 *     `INSERT IGNORE` de un scan futuro afectaría 0 filas y ese cliente no
 *     sabría NUNCA de ese tomo.
 *
 * `coalesced` no es un motivo de drop: es un ESTADO propio
 * -------------------------------------------------------
 * Cuando salen dos tomos de la misma serie, el aviso enseña la ficha del más
 * nuevo y NOMBRA a los demás en el mismo bloque («Kingdom — tomos 71 al 74»).
 * Esas filas quedan {@see self::STATUS_COALESCED}, no `sent`: `sent` significa
 * «este tomo tuvo su ficha, su precio y su botón», y eso no es lo que les pasó.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 */
final class DigestQueue {

	/**
	 * La fila se absorbió en el aviso de un tomo MÁS NUEVO de la misma serie.
	 *
	 * No es `sent` —no tuvo bloque propio, ni ficha, ni precio, ni botón— y no es
	 * `dropped` —no se descartó por una decisión sobre el cliente, sino porque su
	 * serie ya viajaba en el mismo envío—. Desde 1.115.21 su NÚMERO sí viaja
	 * nombrado dentro del bloque del tomo más nuevo, así que «no se avisó» sería
	 * falso: lo cierto es «no tuvo bloque propio». Tener estado propio es lo que
	 * permite contestar «¿le avisamos del tomo 997?» mirando la tabla.
	 *
	 * Terminal a propósito: NO vuelve a pending. Publicar 33 tomos de Fire Force
	 * un jueves es dar de alta el catálogo, no publicar 33 novedades; reabrirlas
	 * convertiría el aviso en el spam que el colapso existe para evitar.
	 *
	 * Cabe en la columna sin migración: `status` es VARCHAR(20).
	 */
	public const STATUS_COALESCED = 'coalesced';

	public const STATUS_PENDING = 'pending';
	public const STATUS_SENT    = 'sent';
	public const STATUS_DROPPED = 'dropped';
	public const STATUS_EXPIRED = 'expired';
	public const STATUS_FAILED  = 'failed';

	public const DROP_OWNED       = 'owned';
	public const DROP_OPTOUT      = 'optout';
	public const DROP_UNPUBLISHED = 'unpublished';

	/** Intentos de envío antes de dar la fila por perdida. */
	public const MAX_ATTEMPTS = 3;

	/** @var object wpdb o doble compatible. */
	private object $db;

	/** @var string Tabla completa con prefix. */
	private string $table;

	/**
	 * @param object $db    wpdb o doble.
	 * @param string $table Tabla completa.
	 */
	public function __construct( object $db, string $table ) {
		$this->db    = $db;
		$this->table = $table;
	}

	/**
	 * Clave de idempotencia de un aviso.
	 *
	 * @param int $user_id    Cliente.
	 * @param int $product_id Tomo publicado.
	 * @return string
	 */
	public static function event_key( int $user_id, int $product_id ): string {
		return 'u' . $user_id . ':p' . $product_id;
	}

	/**
	 * Alta en lote. `INSERT IGNORE`: las filas que ya existen (pendientes,
	 * enviadas o descartadas) no se tocan.
	 *
	 * @param array<int, array{user_id:int, product_id:int, term_id:int, volume:int}> $rows Avisos a encolar.
	 * @return int Filas realmente insertadas.
	 */
	public function queue( array $rows ): int {
		$values      = array();
		$placeholders = array();
		$created_at  = gmdate( 'Y-m-d H:i:s' );

		foreach ( $rows as $row ) {
			$user_id    = (int) ( $row['user_id'] ?? 0 );
			$product_id = (int) ( $row['product_id'] ?? 0 );
			$term_id    = (int) ( $row['term_id'] ?? 0 );
			$volume     = (int) ( $row['volume'] ?? 0 );

			if ( $user_id < 1 || $product_id < 1 || $term_id < 1 || $volume < 1 ) {
				continue;
			}

			$placeholders[] = '(%d, %d, %d, %d, %s, %s, 0, %s)';
			array_push(
				$values,
				$user_id,
				$product_id,
				$term_id,
				$volume,
				self::event_key( $user_id, $product_id ),
				self::STATUS_PENDING,
				$created_at
			);
		}

		if ( array() === $placeholders ) {
			return 0;
		}

		$tuples = implode( ', ', $placeholders );

		$affected = $this->db->query(
			$this->db->prepare(
				"INSERT IGNORE INTO {$this->table} (user_id, product_id, term_id, volume, event_key, status, attempts, created_at) VALUES {$tuples}",
				...$values
			)
		);

		return max( 0, (int) $affected );
	}

	/**
	 * Clientes con avisos pendientes, los más antiguos primero.
	 *
	 * @param int $limit Tamaño del lote.
	 * @return array<int, int>
	 */
	public function pending_user_ids( int $limit ): array {
		$rows = $this->db->get_col(
			$this->db->prepare(
				"SELECT user_id FROM {$this->table} WHERE status = %s GROUP BY user_id ORDER BY MIN(created_at) ASC, user_id ASC LIMIT %d",
				self::STATUS_PENDING,
				max( 1, $limit )
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'intval', $rows ),
				static fn ( int $id ): bool => $id > 0
			)
		);
	}

	/**
	 * Filas pendientes de un cliente, la serie más nueva primero.
	 *
	 * @param int $user_id Cliente.
	 * @return array<int, array<string, mixed>>
	 */
	public function pending_for_user( int $user_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, user_id, product_id, term_id, volume FROM {$this->table} WHERE user_id = %d AND status = %s ORDER BY volume DESC, id ASC",
				$user_id,
				self::STATUS_PENDING
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * CLAIM atómico: gana quien pasa las filas `pending`→`sent`. Devuelve
	 * cuántas afectó ESTA llamada; `0` significa que otro worker se le adelantó.
	 * Es el mismo patrón que `BackInStock\SubscriberRepository::mark_notified()`.
	 *
	 * @param int             $user_id Cliente.
	 * @param array<int, int> $ids     Filas a reclamar.
	 * @return int
	 */
	public function claim( int $user_id, array $ids ): int {
		$ids = self::normalize_ids( $ids );
		if ( array() === $ids ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table} SET status = %s, sent_at = %s WHERE user_id = %d AND status = %s AND id IN ({$placeholders})",
				...array_merge(
					array( self::STATUS_SENT, gmdate( 'Y-m-d H:i:s' ), $user_id, self::STATUS_PENDING ),
					$ids
				)
			)
		);

		return max( 0, (int) $affected );
	}

	/**
	 * Devuelve las filas reclamadas al pool tras un fallo de envío. Al agotar
	 * los intentos quedan `failed` (terminal), que drena la cola en vez de
	 * reintentar para siempre.
	 *
	 * El orden del SET no es cosmético: MySQL evalúa las asignaciones
	 * izquierda→derecha y el `CASE` ve `attempts` YA incrementado, así que se
	 * compara directo contra el máximo (con `+ 1` sería doble incremento y el
	 * terminal llegaría un intento antes).
	 *
	 * @param array<int, int> $ids Filas a liberar.
	 * @return void
	 */
	public function release( array $ids ): void {
		$ids = self::normalize_ids( $ids );
		if ( array() === $ids ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table}
				SET attempts = attempts + 1,
					sent_at = NULL,
					status = CASE WHEN attempts >= %d THEN %s ELSE %s END
				WHERE status = %s AND id IN ({$placeholders})",
				...array_merge(
					array( self::MAX_ATTEMPTS, self::STATUS_FAILED, self::STATUS_PENDING, self::STATUS_SENT ),
					$ids
				)
			)
		);
	}

	/**
	 * Descarta filas. El motivo decide si se MARCAN o se BORRAN — ver el
	 * docblock de la clase.
	 *
	 * @param array<int, int> $ids    Filas a descartar.
	 * @param string          $reason Uno de DROP_OWNED, DROP_OPTOUT, DROP_UNPUBLISHED.
	 * @return void
	 */
	public function drop( array $ids, string $reason ): void {
		$ids = self::normalize_ids( $ids );
		if ( array() === $ids ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		if ( self::DROP_UNPUBLISHED === $reason ) {
			$this->db->query(
				$this->db->prepare(
					"DELETE FROM {$this->table} WHERE status = %s AND id IN ({$placeholders})",
					...array_merge( array( self::STATUS_PENDING ), $ids )
				)
			);
			return;
		}

		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table} SET status = %s WHERE status = %s AND id IN ({$placeholders})",
				...array_merge( array( self::STATUS_DROPPED, self::STATUS_PENDING ), $ids )
			)
		);
	}

	/**
	 * Caduca lo pendiente que ya no es noticia. Dos meses tarde no es un aviso,
	 * es basura, y eso sí es una decisión terminal. Corre al inicio de cada
	 * digest: no necesita cron propio.
	 *
	 * @param int $days Antigüedad en días.
	 * @return int Filas caducadas.
	 */
	public function expire_older_than( int $days ): int {
		$days   = max( 1, $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table} SET status = %s WHERE status = %s AND created_at < %s",
				self::STATUS_EXPIRED,
				self::STATUS_PENDING,
				$cutoff
			)
		);

		return max( 0, (int) $affected );
	}

	/** ¿Queda algo por enviar? */
	public function has_pending(): bool {
		$count = $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE status = %s",
				self::STATUS_PENDING
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Resumen por tomo para el panel: quién está en cola y a quién ya se le
	 * avisó. Informativo, no operable.
	 *
	 * @param int $limit Filas del listado.
	 * @return array<int, array<string, mixed>>
	 */
	public function pending_summary( int $limit ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT product_id, term_id, volume,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS waiting,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS notified,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS absorbed
				FROM {$this->table}
				GROUP BY product_id, term_id, volume
				HAVING waiting > 0 OR notified > 0 OR absorbed > 0
				ORDER BY waiting DESC, product_id DESC
				LIMIT %d",
				self::STATUS_PENDING,
				self::STATUS_SENT,
				self::STATUS_COALESCED,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Marca como absorbidas las filas que el aviso NO nombró.
	 *
	 * Va DESPUÉS del envío con éxito, sobre filas ya reclamadas (`sent`), y no
	 * antes: si el correo falla, `release()` las devuelve todas a `pending` y el
	 * siguiente intento vuelve a decidir cuál se anuncia. Marcarlas antes del
	 * envío dejaría absorbido lo que nunca llegó a salir.
	 *
	 * @param array<int, int> $ids Filas absorbidas por un tomo más nuevo.
	 * @return int Filas marcadas.
	 */
	public function coalesce( array $ids ): int {
		$ids = self::normalize_ids( $ids );
		if ( array() === $ids ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table} SET status = %s WHERE status = %s AND id IN ({$placeholders})",
				...array_merge( array( self::STATUS_COALESCED, self::STATUS_SENT ), $ids )
			)
		);

		return max( 0, (int) $affected );
	}

	/**
	 * @param array<int, mixed> $ids IDs crudos.
	 * @return array<int, int>
	 */
	private static function normalize_ids( array $ids ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static fn ( int $id ): bool => $id > 0
				)
			)
		);
	}
}
