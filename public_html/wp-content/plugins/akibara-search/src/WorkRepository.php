<?php
/**
 * WorkRepository — outbox y manifest durable del protocolo de búsqueda v2.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Persiste revisiones, claims y ACK sin depender de Action Scheduler.
 *
 * @since 1.55.0
 */
final class WorkRepository {

	public const SCOPE_LIVE     = 'live';
	public const SCOPE_BASELINE = 'baseline';
	public const SCOPE_REPAIR   = 'repair';

	public const STATUS_PENDING = 'pending';
	public const STATUS_LEASED  = 'leased';
	public const STATUS_ACKED   = 'acked';
	public const STATUS_BLOCKED = 'blocked';

	public const OUTCOME_INDEXED = 'indexed';
	public const OUTCOME_ABSENT  = 'absent';

	/**
	 * Intentos tras los cuales un claim deja de reintentarse y queda BLOQUEADO.
	 *
	 * Con el backoff exponencial acotado a una hora, el 8º intento cae a las
	 * ~4,5 h del primero: pasado ese punto el fallo ya no es transitorio y
	 * seguir girando sólo esconde el problema (el run nunca cierra y el
	 * contador del panel se queda en cero para siempre).
	 */
	public const MAX_ATTEMPTS = 8;

	private const MAX_ERROR_LENGTH = 65535;

	/** @var object Instancia wpdb del caller. */
	private object $wpdb;

	/** Tabla durable delimitada como identificador SQL. */
	private string $table;

	/** Último fallo local/SQL; vacío después de una operación válida. */
	private string $last_error = '';

	/**
	 * @param object      $wpdb  Conexión wpdb.
	 * @param string|null $table Tabla explícita para pruebas.
	 */
	public function __construct( object $wpdb, ?string $table = null ) {
		$this->wpdb  = $wpdb;
		$this->table = $this->quote_identifier( $table ?? Install::work_table() );
	}

	/**
	 * Último fallo del repositorio.
	 *
	 * @return string
	 */
	public function last_error(): string {
		return $this->last_error;
	}

	/**
	 * Incrementa atómicamente la revisión live y la vuelve pendiente.
	 *
	 * @param int $product_id Producto mutado.
	 * @return bool
	 */
	public function mark_live_dirty( int $product_id ): bool {
		return $this->mark_live_dirty_many( array( $product_id ) );
	}

	/**
	 * Incrementa atómicamente la revisión live de varios productos.
	 *
	 * Cada sentencia conserva exactamente la semántica n+1 del camino
	 * individual: un lease vigente mantiene su token/secuencia/vencimiento y el
	 * ACK del claim actual deja la revisión nueva pendiente. Los IDs se validan
	 * antes de escribir para que una entrada inválida no produzca un lote
	 * parcialmente interpretado.
	 *
	 * @param array<int, int> $product_ids  Productos mutados.
	 * @param int             $delay_seconds Barrera mínima para reclamar.
	 * @return bool
	 */
	public function mark_live_dirty_many( array $product_ids, int $delay_seconds = 0 ): bool {
		$this->clear_error();
		if ( 0 > $delay_seconds || 86400 < $delay_seconds ) {
			return $this->reject( 'Invalid live work delay.' );
		}

		foreach ( $product_ids as $product_id ) {
			if ( ! is_int( $product_id ) || 0 >= $product_id ) {
				return $this->reject( 'Invalid live product id.' );
			}
		}

		$ids = array_values( array_unique( $product_ids, SORT_NUMERIC ) );
		sort( $ids, SORT_NUMERIC );
		if ( array() === $ids ) {
			return true;
		}

		foreach ( array_chunk( $ids, 250 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $product_id ) {
				$values[] = "(%s, '', %d, 1, 0, '', %s, DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), '', 0, NULL, 0, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())";
				array_push( $args, self::SCOPE_LIVE, $product_id, self::STATUS_PENDING, $delay_seconds );
			}

			$sql = "INSERT INTO {$this->table}
				(scope, run_id, product_id, wanted_seq, acked_seq, outcome, status, available_at,
				 lease_token, lease_seq, lease_until, attempts, last_error, created_at, updated_at)
			 VALUES " . implode( ', ', $values ) . "
			 ON DUPLICATE KEY UPDATE
				status = IF(status = %s AND lease_until IS NOT NULL AND lease_until > UTC_TIMESTAMP(), %s, %s),
				outcome = IF(status = %s, outcome, ''),
				available_at = GREATEST(available_at, DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND)),
				lease_token = IF(status = %s, lease_token, ''),
				lease_seq = IF(status = %s, lease_seq, 0),
				lease_until = IF(status = %s, lease_until, NULL),
				last_error = IF(status = %s, last_error, NULL),
				wanted_seq = wanted_seq + 1,
				updated_at = UTC_TIMESTAMP()";
			array_push(
				$args,
				self::STATUS_LEASED,
				self::STATUS_LEASED,
				self::STATUS_PENDING,
				self::STATUS_LEASED,
				$delay_seconds,
				self::STATUS_LEASED,
				self::STATUS_LEASED,
				self::STATUS_LEASED,
				self::STATUS_LEASED
			);
			$sql = $this->wpdb->prepare( $sql, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- la plantilla con marcadores se arma arriba en $sql y ésta es justamente la llamada a prepare() que la rellena con ...$args.

			if ( ! $this->write_succeeded( $this->wpdb->query( $sql ), 'Could not mark live work dirty.' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ya viene de $this->wpdb->prepare() dos líneas arriba; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb.
				return false;
			}
		}

		return true;
	}

	/**
	 * Inserta un lote baseline sin reabrir filas ya procesadas del mismo run.
	 *
	 * @param string          $run_id      Run dueño del snapshot.
	 * @param array<int, int> $product_ids Productos del lote.
	 * @return bool
	 */
	public function seed_baseline( string $run_id, array $product_ids ): bool {
		$this->clear_error();
		if ( ! $this->valid_run_id( $run_id ) ) {
			return $this->reject( 'Invalid baseline run id.' );
		}

		$ids = array_values(
			array_unique(
				array_filter( array_map( 'intval', $product_ids ), static fn( int $id ): bool => 0 < $id )
			)
		);
		sort( $ids, SORT_NUMERIC );
		if ( array() === $ids ) {
			return true;
		}

		foreach ( array_chunk( $ids, 250 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $product_id ) {
				$values[] = "(%s, %s, %d, 1, 0, '', %s, UTC_TIMESTAMP(), '', 0, NULL, 0, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())";
				array_push( $args, self::SCOPE_BASELINE, $run_id, $product_id, self::STATUS_PENDING );
			}

			$sql = "INSERT INTO {$this->table}
				(scope, run_id, product_id, wanted_seq, acked_seq, outcome, status, available_at,
				 lease_token, lease_seq, lease_until, attempts, last_error, created_at, updated_at)
			 VALUES " . implode( ', ', $values ) . '
			 ON DUPLICATE KEY UPDATE product_id = VALUES(product_id)';
			$sql = $this->wpdb->prepare( $sql, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- la plantilla con marcadores se arma arriba en $sql y ésta es justamente la llamada a prepare() que la rellena con ...$args.

			if ( ! $this->write_succeeded( $this->wpdb->query( $sql ), 'Could not seed baseline work.' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ya viene de $this->wpdb->prepare() dos líneas arriba; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb.
				return false;
			}
		}

		return true;
	}

	/**
	 * Incrementa la revisión propia de repair dentro de la TX del caller.
	 *
	 * @param string $run_id     Run activo.
	 * @param int    $product_id Producto mutado.
	 * @return bool
	 */
	public function mark_repair_dirty( string $run_id, int $product_id ): bool {
		return $this->mark_repair_dirty_many( $run_id, array( $product_id ) );
	}

	/**
	 * Incrementa de forma batch la revisión repair de un snapshot activo.
	 *
	 * Mantiene exactamente las mismas garantías n+1 de live: un claim vigente
	 * conserva lease/token, el ACK de esa revisión no puede comerse la siguiente
	 * y una barrera futura nunca se acorta. Se usa para mutaciones editoriales
	 * globales, que deben dejar TODO el fan-out durable antes del source write.
	 *
	 * @param string          $run_id       Run shadow activo.
	 * @param array<int, int> $product_ids  Productos afectados.
	 * @param int             $delay_seconds Barrera mínima para leer la fuente.
	 * @return bool
	 */
	public function mark_repair_dirty_many( string $run_id, array $product_ids, int $delay_seconds = 0 ): bool {
		$this->clear_error();
		if ( ! $this->valid_run_id( $run_id ) || 0 > $delay_seconds || 86400 < $delay_seconds ) {
			return $this->reject( 'Invalid repair identity.' );
		}

		foreach ( $product_ids as $product_id ) {
			if ( ! is_int( $product_id ) || 0 >= $product_id ) {
				return $this->reject( 'Invalid repair product id.' );
			}
		}

		$ids = array_values( array_unique( $product_ids, SORT_NUMERIC ) );
		sort( $ids, SORT_NUMERIC );
		if ( array() === $ids ) {
			return true;
		}

		foreach ( array_chunk( $ids, 250 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $product_id ) {
				$values[] = "(%s, %s, %d, 1, 0, '', %s, DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), '', 0, NULL, 0, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())";
				array_push( $args, self::SCOPE_REPAIR, $run_id, $product_id, self::STATUS_PENDING, $delay_seconds );
			}

			$sql = "INSERT INTO {$this->table}
				(scope, run_id, product_id, wanted_seq, acked_seq, outcome, status, available_at,
				 lease_token, lease_seq, lease_until, attempts, last_error, created_at, updated_at)
			 VALUES " . implode( ', ', $values ) . "
			 ON DUPLICATE KEY UPDATE
				status = IF(status = %s AND lease_until IS NOT NULL AND lease_until > UTC_TIMESTAMP(), %s, %s),
				outcome = IF(status = %s, outcome, ''),
				available_at = GREATEST(available_at, DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND)),
				lease_token = IF(status = %s, lease_token, ''),
				lease_seq = IF(status = %s, lease_seq, 0),
				lease_until = IF(status = %s, lease_until, NULL),
				last_error = IF(status = %s, last_error, NULL),
				wanted_seq = wanted_seq + 1,
				updated_at = UTC_TIMESTAMP()";
			array_push(
				$args,
				self::STATUS_LEASED,
				self::STATUS_LEASED,
				self::STATUS_PENDING,
				self::STATUS_LEASED,
				$delay_seconds,
				self::STATUS_LEASED,
				self::STATUS_LEASED,
				self::STATUS_LEASED,
				self::STATUS_LEASED
			);
			$sql = $this->wpdb->prepare( $sql, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- la plantilla con marcadores se arma arriba en $sql y ésta es justamente la llamada a prepare() que la rellena con ...$args.

			if ( ! $this->write_succeeded( $this->wpdb->query( $sql ), 'Could not mark repair work dirty.' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ya viene de $this->wpdb->prepare() dos líneas arriba; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb.
				return false;
			}
		}

		return true;
	}

	/**
	 * Reclama una fila en una transacción corta propia.
	 *
	 * @param string      $scope         Scope solicitado.
	 * @param string      $run_id        Run; vacío sólo para live.
	 * @param int         $lease_seconds Duración del lease.
	 * @param string|null $lease_token   Token explícito para pruebas.
	 * @return array<string, mixed>|null
	 */
	public function claim_one( string $scope, string $run_id = '', int $lease_seconds = 60, ?string $lease_token = null ): ?array {
		return $this->claim_one_in_transaction( $scope, $run_id, $lease_seconds, null, $lease_token );
	}

	/**
	 * Reclama atómicamente un producto específico para una fachada síncrona.
	 *
	 * @param string      $scope         Scope solicitado.
	 * @param string      $run_id        Run; vacío sólo para live.
	 * @param int         $product_id    Producto exacto.
	 * @param int         $lease_seconds Duración del lease.
	 * @param string|null $lease_token   Token explícito para pruebas.
	 * @return array<string, mixed>|null
	 */
	public function claim_product(
		string $scope,
		string $run_id,
		int $product_id,
		int $lease_seconds = 60,
		?string $lease_token = null
	): ?array {
		if ( 0 >= $product_id ) {
			$this->clear_error();
			$this->reject( 'Invalid claim product id.' );
			return null;
		}

		return $this->claim_one_in_transaction( $scope, $run_id, $lease_seconds, null, $lease_token, $product_id );
	}

	/**
	 * Ejecuta guard y claim bajo la misma TX; COMMIT=0 es éxito y false falla.
	 *
	 * @param string        $scope         Scope solicitado.
	 * @param string        $run_id        Run; vacío sólo para live.
	 * @param int           $lease_seconds Duración del lease.
	 * @param callable|null $guard          Guard de fase ejecutado tras START.
	 * @param string|null   $lease_token    Token explícito para pruebas.
	 * @return array<string, mixed>|null
	 */
	public function claim_one_in_transaction(
		string $scope,
		string $run_id = '',
		int $lease_seconds = 60,
		?callable $guard = null,
		?string $lease_token = null,
		?int $product_id = null
	): ?array {
		$this->clear_error();
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			$this->capture_database_error( 'Could not start claim transaction.' );
			return null;
		}

		try {
			if ( null !== $guard && true !== $guard( $this->wpdb ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				$this->reject( 'Claim guard rejected current phase.' );
				return null;
			}

			$claim = $this->claim_one_for_update( $scope, $run_id, $lease_seconds, $lease_token, $product_id );
			if ( null === $claim ) {
				$this->wpdb->query( 'ROLLBACK' );
				return null;
			}

			$committed = $this->wpdb->query( 'COMMIT' );
			if ( false === $committed ) {
				$this->capture_database_error( 'Could not commit claim transaction.' );
				$this->wpdb->query( 'ROLLBACK' );
				return null;
			}

			return $claim;
		} catch ( \Throwable $throwable ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $throwable;
		}
	}

	/**
	 * Reclama una fila bajo una TX que ya pertenece al caller.
	 *
	 * @param string      $scope         Scope solicitado.
	 * @param string      $run_id        Run; vacío sólo para live.
	 * @param int         $lease_seconds Duración del lease.
	 * @param string|null $lease_token   Token explícito para pruebas.
	 * @return array<string, mixed>|null
	 */
	public function claim_one_for_update(
		string $scope,
		string $run_id = '',
		int $lease_seconds = 60,
		?string $lease_token = null,
		?int $product_id = null
	): ?array {
		$this->clear_error();
		if ( ! $this->valid_scope_run( $scope, $run_id ) || 0 >= $lease_seconds || ( null !== $product_id && 0 >= $product_id ) ) {
			$this->reject( 'Invalid claim request.' );
			return null;
		}

		$token = $this->lease_token( $lease_token );
		if ( null === $token ) {
			$this->reject( 'Invalid lease token.' );
			return null;
		}

		$product_filter = null === $product_id ? '' : ' AND product_id = %d';
		$args           = array( $scope, $run_id );
		if ( null !== $product_id ) {
			$args[] = $product_id;
		}
		array_push( $args, self::STATUS_PENDING, self::STATUS_LEASED );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$this->table} es el nombre de tabla propio (Install::work_table()) ya escapado por quote_identifier(); un identificador no admite marcador, y {$product_filter} es un fragmento literal con su propio marcador; los valores van en ...$args, que el sniff no sabe contar.
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->table}
			 WHERE scope = %s AND run_id = %s{$product_filter}
			   AND (
				(status = %s AND available_at <= UTC_TIMESTAMP())
				OR (status = %s AND lease_until IS NOT NULL AND lease_until <= UTC_TIMESTAMP()
					AND available_at <= UTC_TIMESTAMP())
			   )
			 ORDER BY product_id ASC, id ASC
			 LIMIT 1 FOR UPDATE",
			...$args
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$this->prepare_read();
		$row = $this->wpdb->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ya viene de $this->wpdb->prepare() justo arriba; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb.
		if ( null === $row ) {
			$this->capture_database_error( '' );
			return null;
		}

		$row = $this->normalize_row( (array) $row );
		$id  = (int) ( $row['id'] ?? 0 );
		if ( 0 >= $id ) {
			$this->reject( 'Claim row has no valid id.' );
			return null;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- la consulta se arma con $this->wpdb->prepare() en la línea siguiente; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb, y {$this->table} es el nombre de tabla propio (Install::work_table()) ya escapado por quote_identifier(); un identificador no admite marcador.
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table}
				 SET status = %s,
					 lease_token = %s,
					 lease_seq = wanted_seq,
					 lease_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),
					 attempts = attempts + 1,
					 last_error = NULL,
					 updated_at = UTC_TIMESTAMP()
				 WHERE id = %d
				   AND (
					(status = %s AND available_at <= UTC_TIMESTAMP())
					OR (status = %s AND lease_until IS NOT NULL AND lease_until <= UTC_TIMESTAMP()
						AND available_at <= UTC_TIMESTAMP())
				   )",
				self::STATUS_LEASED,
				$token,
				$lease_seconds,
				$id,
				self::STATUS_PENDING,
				self::STATUS_LEASED
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== $updated ) {
			$this->capture_database_error( 'Could not establish exclusive work lease.' );
			return null;
		}

		$this->prepare_read();
		$claimed = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d FOR UPDATE", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepare() envuelve la consulta en esta misma línea; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb, y {$this->table} es el nombre de tabla propio (Install::work_table()) ya escapado por quote_identifier(); un identificador no admite marcador.
		);
		if ( null === $claimed ) {
			$this->capture_database_error( 'Could not reload leased work.' );
			return null;
		}

		$claimed = $this->normalize_row( (array) $claimed );
		if ( $token !== (string) ( $claimed['lease_token'] ?? '' ) ) {
			$this->reject( 'Reloaded lease token does not match.' );
			return null;
		}

		return $claimed;
	}

	/**
	 * Valida identidad completa, token, revisión y vigencia bajo FOR UPDATE.
	 *
	 * @param array<string, mixed> $claim Claim emitido por este repositorio.
	 * @return bool
	 */
	public function validate_claim_for_update( array $claim ): bool {
		$this->clear_error();
		if ( ! $this->valid_claim( $claim ) ) {
			return $this->reject( 'Invalid work claim.' );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$this->table} es el nombre de tabla propio (Install::work_table()) ya escapado por quote_identifier(); un identificador no admite marcador.
		$sql = $this->wpdb->prepare(
			"SELECT id FROM {$this->table}
			 WHERE id = %d AND scope = %s AND run_id = %s AND product_id = %d
			   AND status = %s AND lease_token = %s AND lease_seq = %d
			   AND lease_until IS NOT NULL AND lease_until > UTC_TIMESTAMP()
			 LIMIT 1 FOR UPDATE",
			(int) $claim['id'],
			(string) $claim['scope'],
			(string) $claim['run_id'],
			(int) $claim['product_id'],
			self::STATUS_LEASED,
			(string) $claim['lease_token'],
			(int) $claim['lease_seq']
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->prepare_read();
		$id = $this->wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ya viene de $this->wpdb->prepare() arriba; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb.
		if ( null === $id ) {
			$this->capture_database_error( '' );
			return false;
		}

		return (int) $claim['id'] === (int) $id;
	}

	/**
	 * ACK condicional: una revisión n+1 deja la fila pendiente tras ACK de n.
	 *
	 * @param array<string, mixed> $claim   Claim procesado.
	 * @param string               $outcome indexed o absent explícito.
	 * @return bool
	 */
	public function ack_claim( array $claim, string $outcome ): bool {
		$this->clear_error();
		if ( ! $this->valid_claim( $claim ) || ! in_array( $outcome, array( self::OUTCOME_INDEXED, self::OUTCOME_ABSENT ), true ) ) {
			return $this->reject( 'Invalid work acknowledgement.' );
		}

		$lease_seq = (int) $claim['lease_seq'];
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$this->table} es el nombre de tabla propio (Install::work_table()) ya escapado por quote_identifier(); un identificador no admite marcador.
		$sql       = $this->wpdb->prepare(
			"UPDATE {$this->table}
			 SET acked_seq = GREATEST(acked_seq, %d),
				 outcome = %s,
				 status = IF(wanted_seq > %d, %s, %s),
				 available_at = IF(wanted_seq > %d, GREATEST(available_at, UTC_TIMESTAMP()), available_at),
				 lease_token = '',
				 lease_seq = 0,
				 lease_until = NULL,
				 attempts = 0,
				 last_error = NULL,
				 updated_at = UTC_TIMESTAMP()
			 WHERE id = %d AND scope = %s AND run_id = %s AND product_id = %d
			   AND status = %s AND lease_token = %s AND lease_seq = %d
			   AND lease_until IS NOT NULL AND lease_until > UTC_TIMESTAMP()",
			$lease_seq,
			$outcome,
			$lease_seq,
			self::STATUS_PENDING,
			self::STATUS_ACKED,
			$lease_seq,
			(int) $claim['id'],
			(string) $claim['scope'],
			(string) $claim['run_id'],
			(int) $claim['product_id'],
			self::STATUS_LEASED,
			(string) $claim['lease_token'],
			$lease_seq
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $this->exactly_one_row( $this->wpdb->query( $sql ), 'Work ACK was stale or failed.' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ya viene de $this->wpdb->prepare() arriba; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb.
	}

	/**
	 * Libera un claim como pending con demora, sin convertir fallo en absent.
	 *
	 * @param array<string, mixed> $claim         Claim fallido.
	 * @param string               $error         Diagnóstico acotado.
	 * @param int                  $delay_seconds Segundos antes del retry.
	 * @return bool
	 */
	public function retry_claim( array $claim, string $error, int $delay_seconds = 5 ): bool {
		$this->clear_error();
		if ( ! $this->valid_claim( $claim ) || 0 > $delay_seconds ) {
			return $this->reject( 'Invalid work retry.' );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$this->table} es el nombre de tabla propio (Install::work_table()) ya escapado por quote_identifier(); un identificador no admite marcador.
		$sql = $this->wpdb->prepare(
			"UPDATE {$this->table}
			 SET status = %s,
				 outcome = '',
				 available_at = GREATEST(available_at, DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND)),
				 lease_token = '', lease_seq = 0, lease_until = NULL,
				 last_error = %s, updated_at = UTC_TIMESTAMP()
			 WHERE id = %d AND scope = %s AND run_id = %s AND product_id = %d
			   AND status = %s AND lease_token = %s AND lease_seq = %d",
			self::STATUS_PENDING,
			$delay_seconds,
			$this->truncate_error( $error ),
			(int) $claim['id'],
			(string) $claim['scope'],
			(string) $claim['run_id'],
			(int) $claim['product_id'],
			self::STATUS_LEASED,
			(string) $claim['lease_token'],
			(int) $claim['lease_seq']
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $this->exactly_one_row( $this->wpdb->query( $sql ), 'Work retry was stale or failed.' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ya viene de $this->wpdb->prepare() arriba; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb.
	}

	/**
	 * Aplica backoff exponencial acotado según attempts del claim, y BLOQUEA el
	 * claim cuando los reintentos dejan de serlo.
	 *
	 * Sin el techo, un producto irreparable giraba para siempre con el backoff
	 * clampado a una hora: el run nunca cerraba, nadie lo veía, y el estado
	 * `blocked` —con su contador en el panel y su veredicto en HealthReport— no
	 * tenía escritor. La observabilidad existía y era mentira.
	 *
	 * @param array<string, mixed> $claim       Claim fallido.
	 * @param string               $error       Diagnóstico.
	 * @param int                  $base_seconds Base del backoff.
	 * @param int                  $max_seconds  Cota del backoff.
	 * @return bool
	 */
	public function retry_with_backoff( array $claim, string $error, int $base_seconds = 5, int $max_seconds = 3600 ): bool {
		if ( 0 >= $base_seconds || $base_seconds > $max_seconds ) {
			$this->clear_error();
			return $this->reject( 'Invalid retry backoff.' );
		}

		$attempts = max( 1, (int) ( $claim['attempts'] ?? 1 ) );
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return $this->block_claim(
				$claim,
				sprintf( 'Blocked after %d attempts: %s', $attempts, $error )
			);
		}

		$power = min( 10, $attempts - 1 );
		$delay = min( $max_seconds, $base_seconds * ( 2 ** $power ) );

		return $this->retry_claim( $claim, $error, $delay );
	}

	/**
	 * Deja un claim bloqueado y observable, nunca ACK/absent.
	 *
	 * @param array<string, mixed> $claim Claim que no puede continuar.
	 * @param string               $error Diagnóstico.
	 * @return bool
	 */
	public function block_claim( array $claim, string $error ): bool {
		$this->clear_error();
		if ( ! $this->valid_claim( $claim ) ) {
			return $this->reject( 'Invalid work block request.' );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$this->table} es el nombre de tabla propio (Install::work_table()) ya escapado por quote_identifier(); un identificador no admite marcador.
		$sql = $this->wpdb->prepare(
			"UPDATE {$this->table}
			 SET status = %s, outcome = '', lease_token = '', lease_seq = 0,
				 lease_until = NULL, last_error = %s, updated_at = UTC_TIMESTAMP()
			 WHERE id = %d AND scope = %s AND run_id = %s AND product_id = %d
			   AND status = %s AND lease_token = %s AND lease_seq = %d",
			self::STATUS_BLOCKED,
			$this->truncate_error( $error ),
			(int) $claim['id'],
			(string) $claim['scope'],
			(string) $claim['run_id'],
			(int) $claim['product_id'],
			self::STATUS_LEASED,
			(string) $claim['lease_token'],
			(int) $claim['lease_seq']
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $this->exactly_one_row( $this->wpdb->query( $sql ), 'Work block was stale or failed.' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ya viene de $this->wpdb->prepare() arriba; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb.
	}

	/**
	 * Recupera leases vencidos; devuelve cantidad o false ante fallo SQL.
	 *
	 * @param string|null $scope  Filtro opcional.
	 * @param string|null $run_id Filtro opcional.
	 * @return int|false
	 */
	public function recover_expired( ?string $scope = null, ?string $run_id = null ) {
		$this->clear_error();
		$where = array( 'status = %s', 'lease_until IS NOT NULL', 'lease_until <= UTC_TIMESTAMP()' );
		$args  = array( self::STATUS_LEASED );
		if ( null !== $scope ) {
			if ( ! in_array( $scope, $this->scopes(), true ) ) {
				$this->reject( 'Invalid recovery scope.' );
				return false;
			}
			$where[] = 'scope = %s';
			$args[]  = $scope;
		}
		if ( null !== $run_id ) {
			if ( 64 < strlen( $run_id ) ) {
				$this->reject( 'Invalid recovery run id.' );
				return false;
			}
			$where[] = 'run_id = %s';
			$args[]  = $run_id;
		}

		$sql = "UPDATE {$this->table}
			SET status = %s, outcome = '', available_at = GREATEST(available_at, UTC_TIMESTAMP()),
				lease_token = '', lease_seq = 0, lease_until = NULL,
				last_error = CASE WHEN last_error IS NULL OR last_error = '' THEN %s ELSE last_error END,
				updated_at = UTC_TIMESTAMP()
			WHERE " . implode( ' AND ', $where );
		$result = $this->wpdb->query(
			$this->wpdb->prepare( $sql, self::STATUS_PENDING, 'lease_expired', ...$args ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql es la plantilla con marcadores armada arriba y ésta es la llamada a prepare() que la rellena; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb.
		);
		if ( false === $result ) {
			$this->capture_database_error( 'Could not recover expired work leases.' );
			return false;
		}

		return (int) $result;
	}

	/**
	 * Resume conteos por scope/status para observabilidad.
	 *
	 * @param string|null $run_id Filtro opcional.
	 * @return array<string, array<string, int>>|false
	 */
	public function summary( ?string $run_id = null ) {
		$this->clear_error();
		$where = '';
		if ( null !== $run_id ) {
			if ( 64 < strlen( $run_id ) ) {
				$this->reject( 'Invalid summary run id.' );
				return false;
			}
			$where = $this->wpdb->prepare( ' WHERE run_id = %s', $run_id );
		}

		$this->prepare_read();
		$rows = $this->wpdb->get_results(
			"SELECT scope, status, COUNT(*) AS total FROM {$this->table}{$where} GROUP BY scope, status ORDER BY scope, status" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$this->table} es el nombre de tabla propio (Install::work_table()) ya escapado por quote_identifier(); un identificador no admite marcador, y {$where} sale de $this->wpdb->prepare() unas líneas arriba.
		);
		if ( null === $rows || $this->has_database_error() ) {
			$this->capture_database_error( 'Could not summarize work.' );
			return false;
		}

		$summary = array();
		foreach ( $rows as $row ) {
			$data = (array) $row;
			$summary[ (string) $data['scope'] ][ (string) $data['status'] ] = (int) $data['total'];
		}

		return $summary;
	}

	/**
	 * Cuenta toda deuda no ACK, incluidos blocked y leases vencidos.
	 *
	 * @param string      $run_id Run; vacío para live.
	 * @param string|null $scope  Filtro opcional.
	 * @return int|false
	 */
	public function outstanding_count( string $run_id = '', ?string $scope = null ) {
		return $this->count_where( $run_id, $scope, 'status <> %s', array( self::STATUS_ACKED ), 'Could not count outstanding work.' );
	}

	/**
	 * Cuenta leases vigentes.
	 *
	 * @param string      $run_id Run; vacío para live.
	 * @param string|null $scope  Filtro opcional.
	 * @return int|false
	 */
	public function active_lease_count( string $run_id = '', ?string $scope = null ) {
		return $this->count_where(
			$run_id,
			$scope,
			'status = %s AND lease_until IS NOT NULL AND lease_until > UTC_TIMESTAMP()',
			array( self::STATUS_LEASED ),
			'Could not count active work leases.'
		);
	}

	/**
	 * Busca la identidad funcional de una fila.
	 *
	 * @param string $scope      Scope.
	 * @param string $run_id     Run; vacío para live.
	 * @param int    $product_id Producto.
	 * @return array<string, mixed>|null
	 */
	public function find( string $scope, string $run_id, int $product_id ): ?array {
		$this->clear_error();
		if ( ! $this->valid_scope_run( $scope, $run_id ) || 0 >= $product_id ) {
			$this->reject( 'Invalid work identity.' );
			return null;
		}

		$this->prepare_read();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- la consulta se arma con $this->wpdb->prepare() en la línea siguiente; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb, y {$this->table} es el nombre de tabla propio (Install::work_table()) ya escapado por quote_identifier(); un identificador no admite marcador.
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE scope = %s AND run_id = %s AND product_id = %d LIMIT 1",
				$scope,
				$run_id,
				$product_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $row ) {
			$this->capture_database_error( '' );
			return null;
		}

		return $this->normalize_row( (array) $row );
	}

	/**
	 * Poda sólo filas ACK del run; nunca borra deuda pendiente o bloqueada.
	 *
	 * @param string $run_id Run completado.
	 * @return int|false
	 */
	/**
	 * Borra el trabajo baseline/repair de TODAS las corridas salvo la indicada,
	 * en cualquier estado: una corrida que no es la viva no tiene workers que
	 * reclamen sus filas. El scope `live` no se toca (es por producto, sin
	 * run_id). Se llama al arrancar una corrida nueva (R7-trabajo-huerfano).
	 *
	 * @param string $keep_run_id La corrida que empieza.
	 * @return int|false Filas borradas, o false si falló.
	 */
	public function purge_other_runs( string $keep_run_id ) {
		$this->clear_error();
		if ( ! $this->valid_run_id( $keep_run_id ) ) {
			$this->reject( 'Invalid keep run id.' );
			return false;
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- misma razón que purge_run(): prepare() en la línea siguiente, conexión inyectada y nombre de tabla propio ya escapado.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->table}
				 WHERE scope IN (%s, %s) AND run_id <> %s",
				self::SCOPE_BASELINE,
				self::SCOPE_REPAIR,
				$keep_run_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $result ) {
			$this->capture_database_error( 'Could not purge other runs.' );
			return false;
		}
		return (int) $result;
	}

	public function purge_run( string $run_id ) {
		$this->clear_error();
		if ( ! $this->valid_run_id( $run_id ) ) {
			$this->reject( 'Invalid purge run id.' );
			return false;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- la consulta se arma con $this->wpdb->prepare() en la línea siguiente; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb, y {$this->table} es el nombre de tabla propio (Install::work_table()) ya escapado por quote_identifier(); un identificador no admite marcador.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->table}
				 WHERE run_id = %s AND scope IN (%s, %s) AND status = %s",
				$run_id,
				self::SCOPE_BASELINE,
				self::SCOPE_REPAIR,
				self::STATUS_ACKED
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $result ) {
			$this->capture_database_error( 'Could not purge acknowledged run work.' );
			return false;
		}

		return (int) $result;
	}

	/**
	 * Cuenta por run/scope con predicado interno fijo.
	 *
	 * @param string            $run_id       Run.
	 * @param string|null       $scope        Scope opcional.
	 * @param string            $predicate    SQL interno con placeholders.
	 * @param array<int, mixed> $predicate_args Argumentos del predicado.
	 * @param string            $failure      Mensaje de fallo.
	 * @return int|false
	 */
	private function count_where( string $run_id, ?string $scope, string $predicate, array $predicate_args, string $failure ) {
		$this->clear_error();
		if ( 64 < strlen( $run_id ) || ( null !== $scope && ! in_array( $scope, $this->scopes(), true ) ) ) {
			$this->reject( 'Invalid work count filter.' );
			return false;
		}

		$where = array( 'run_id = %s', $predicate );
		$args  = array_merge( array( $run_id ), $predicate_args );
		if ( null !== $scope ) {
			$where[] = 'scope = %s';
			$args[]  = $scope;
		}
		$this->prepare_read();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- la consulta se arma con $this->wpdb->prepare() en la línea siguiente; el sniff sólo reconoce la variable $wpdb y aquí la conexión es el alias inyectado $this->wpdb, {$this->table} es el nombre de tabla propio (Install::work_table()) ya escapado por quote_identifier(); un identificador no admite marcador, y los marcadores viven dentro del implode() de $where, donde el sniff no los ve.
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE " . implode( ' AND ', $where ),
				...$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( null === $count || false === $count || ! is_numeric( $count ) ) {
			$this->capture_database_error( $failure );
			return false;
		}

		return (int) $count;
	}

	/** @return array<int, string> */
	private function scopes(): array {
		return array( self::SCOPE_LIVE, self::SCOPE_BASELINE, self::SCOPE_REPAIR );
	}

	/**
	 * @param string $scope  Scope.
	 * @param string $run_id Run.
	 * @return bool
	 */
	private function valid_scope_run( string $scope, string $run_id ): bool {
		if ( ! in_array( $scope, $this->scopes(), true ) || 64 < strlen( $run_id ) ) {
			return false;
		}

		return self::SCOPE_LIVE === $scope ? '' === $run_id : $this->valid_run_id( $run_id );
	}

	/** @return bool */
	private function valid_run_id( string $run_id ): bool {
		return '' !== $run_id && 64 >= strlen( $run_id );
	}

	/**
	 * @param array<string, mixed> $claim Claim.
	 * @return bool
	 */
	private function valid_claim( array $claim ): bool {
		$scope = (string) ( $claim['scope'] ?? '' );
		$run   = (string) ( $claim['run_id'] ?? '' );

		return $this->valid_scope_run( $scope, $run )
			&& 0 < (int) ( $claim['id'] ?? 0 )
			&& 0 < (int) ( $claim['product_id'] ?? 0 )
			&& self::STATUS_LEASED === (string) ( $claim['status'] ?? '' )
			&& '' !== (string) ( $claim['lease_token'] ?? '' )
			&& 64 >= strlen( (string) $claim['lease_token'] )
			&& 0 < (int) ( $claim['lease_seq'] ?? 0 );
	}

	/**
	 * @param string|null $requested Token solicitado.
	 * @return string|null
	 */
	private function lease_token( ?string $requested ): ?string {
		if ( null === $requested ) {
			try {
				return bin2hex( random_bytes( 24 ) );
			} catch ( \Throwable $throwable ) {
				return null;
			}
		}

		$requested = trim( $requested );
		return '' !== $requested && 64 >= strlen( $requested ) ? $requested : null;
	}

	/**
	 * Convierte enteros conocidos y conserva el resto de columnas.
	 *
	 * @param array<string, mixed> $row Fila SQL.
	 * @return array<string, mixed>
	 */
	private function normalize_row( array $row ): array {
		foreach ( array( 'id', 'product_id', 'wanted_seq', 'acked_seq', 'lease_seq', 'attempts' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$row[ $field ] = (int) $row[ $field ];
			}
		}

		return $row;
	}

	/**
	 * @param string $identifier Identificador simple.
	 * @return string
	 */
	private function quote_identifier( string $identifier ): string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $identifier ) ) {
			throw new \InvalidArgumentException( 'Invalid Akibara Search work table name.' );
		}

		return '`' . $identifier . '`';
	}

	/**
	 * @param int|false $result  Resultado de wpdb::query().
	 * @param string    $failure Mensaje de fallo.
	 * @return bool
	 */
	private function write_succeeded( $result, string $failure ): bool {
		if ( false !== $result ) {
			return true;
		}

		$this->capture_database_error( $failure );
		return false;
	}

	/**
	 * @param int|false $result  Resultado de wpdb::query().
	 * @param string    $failure Mensaje de fallo/stale.
	 * @return bool
	 */
	private function exactly_one_row( $result, string $failure ): bool {
		if ( 1 === $result ) {
			return true;
		}

		$this->capture_database_error( $failure );
		return false;
	}

	/** @return void */
	private function clear_error(): void {
		$this->last_error = '';
	}

	/**
	 * Limpia el error previo de wpdb para distinguir un set vacío de un read fallido.
	 *
	 * @return void
	 */
	private function prepare_read(): void {
		if ( property_exists( $this->wpdb, 'last_error' ) ) {
			$this->wpdb->last_error = '';
		}
	}

	/**
	 * @param string $message Mensaje local.
	 * @return bool Siempre false.
	 */
	private function reject( string $message ): bool {
		$this->last_error = $message;
		return false;
	}

	/**
	 * Captura last_error de wpdb sin convertir una ausencia normal en fallo.
	 *
	 * @param string $fallback Mensaje si wpdb no reportó detalle; vacío permite not-found.
	 * @return void
	 */
	private function capture_database_error( string $fallback ): void {
		$database_error = property_exists( $this->wpdb, 'last_error' ) ? trim( (string) $this->wpdb->last_error ) : '';
		$this->last_error = '' !== $database_error ? $database_error : $fallback;
	}

	/**
	 * Detecta errores que wpdb puede acompañar con un array de resultados vacío.
	 *
	 * @return bool
	 */
	private function has_database_error(): bool {
		return property_exists( $this->wpdb, 'last_error' ) && '' !== trim( (string) $this->wpdb->last_error );
	}

	/**
	 * @param string $error Error del worker.
	 * @return string
	 */
	private function truncate_error( string $error ): string {
		return substr( $error, 0, self::MAX_ERROR_LENGTH );
	}
}
