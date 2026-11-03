<?php
/**
 * Install — schema del índice de búsqueda propio (reemplazo Relevanssi+FacetWP).
 *
 * Mismo patrón que BackInStock\Install: guard de versión por option + dbDelta
 * para auto-migrar en deploys vía git/rsync donde el plugin no se reactiva.
 *
 * Decisiones de schema:
 *   - `docs`: 1 fila por producto, denormalizada.
 *   - `tokens`: PK (token, field, post_id) con collation binaria explícita
 *     `utf8mb4_bin` en `token` para prevenir colisiones diacríticas en la clave primaria.
 *   - `facet_index`: espejo de facetas con ancestros materializados.
 *   - `qlog`: historial de demanda agregado (day, query_norm, source) con contadores
 *     para proteger contra saturación por eventos individuales.
 *   - Índices B-Tree sobre prefijos en `tokens` para soportar búsqueda por número de tomo.
 *
 * Rebuild atómico: tablas sombra `_new` + RENAME TABLE multi-tabla atómico.
 *
 * Frontera de persistencia: `docs`, `tokens` y `facet_index` son derivadas.
 * Son fuente y preservadas: `qlog`, opciones de curación editorial y metadatos de Arrival.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class Install {

	// 1.7.0: work durable (live/baseline/repair) + swap recuperable sin DROP.
	// 1.9.0: tokens.idx_post (post_id) → idx_post_token (post_id, token).
	public const DB_VERSION = '1.9.0';
	public const OPTION_KEY = 'akb_search_db_version';

	/** Sello separado: versiones 1.7.0 previas no probaron el contrato exacto. */
	public const OPTION_SCHEMA_SEAL = 'akb_search_schema_seal';
	private const SCHEMA_SEAL       = '1.7.0-exact-1';

	/**
	 * Señal "bump aplicado, falta re-derivar": maybe_install la deja cuando el
	 * claim de versión ganó sobre una versión PREVIA real (no fresh install), y
	 * consume_pending_rebuild() la consume en `init` (AS ya inicializó; encolar
	 * en plugins_loaded es _doing_it_wrong / encolado mudo). Es option y no
	 * propiedad estática a propósito: sobrevive un crash del request que ganó
	 * el claim y la recoge el init de la request siguiente (gate DISEÑO 07-10).
	 */
	public const OPTION_PENDING_REBUILD = 'akb_search_pending_rebuild';

	/** Versión del sobre durable que puede contener el request actual y un follow-up. */
	private const REBUILD_SIGNAL_PROTOCOL = 2;

	/** Cota defensiva: todas las solicitudes posteriores se coalescen en la última. */
	private const REBUILD_SIGNAL_MAX_REQUESTS = 4;

	/**
	 * Ventana para que terminen requests que cargaron el runtime anterior antes
	 * de crear la primera sombra del schema nuevo durante un despliegue rolling.
	 */
	public const REBUILD_GRACE_SECONDS = 300;

	/**
	 * Generación del índice: microtime bumpeado por Indexer en cada escritura
	 * LIVE. La clave del cache de counts del landing (FacetService) la incluye
	 * para invalidar cuando dos reindex del mismo segundo mutan counts sin cambiar
	 * COUNT ni MAX(indexed_at) (granularidad segundo). Vive acá —el hub de
	 * constantes del módulo— para que FacetService no dependa de cargar Indexer.
	 */
	public const OPTION_INDEX_GEN = 'akb_search_index_gen';

	/** Outbox durable para invalidaciones semánticas y publicaciones live. */
	public const OPTION_GENERATION_OUTBOX = 'akb_search_generation_outbox';
	private const GENERATION_OUTBOX_PROTOCOL = 1;
	private const GENERATION_BARRIER_MAX = 32;

	/** Sufijos de las tablas sombra del rebuild atómico. */
	public const SHADOW_SUFFIX = '_new';
	public const OLD_SUFFIX    = '_old';

	/** Clasificaciones cerradas de la topología de las tablas swappeables. */
	public const TOPOLOGY_CLEAN_PRE  = 'clean_pre';
	public const TOPOLOGY_READY_SWAP = 'ready_swap';
	public const TOPOLOGY_SWAPPED    = 'swapped';
	public const TOPOLOGY_AMBIGUOUS  = 'ambiguous';

	/** Resultados cerrados de retirar una familia parcial con owner conocido. */
	public const PARTIAL_RECOVERY_RECOVERED = 'recovered';
	public const PARTIAL_RECOVERY_RETRY     = 'retry';
	public const PARTIAL_RECOVERY_INVALID   = 'invalid';

	/** Resultados cerrados del RENAME atómico. */
	public const RENAME_RESULT_SWAPPED = 'swapped';
	public const RENAME_RESULT_RETRY   = 'retry';
	public const RENAME_RESULT_INVALID = 'invalid';

	/** Resultados cerrados de verificar contratos físicos vía information_schema. */
	public const SCHEMA_CHECK_READY   = 'ready';
	public const SCHEMA_CHECK_RETRY   = 'retry';
	public const SCHEMA_CHECK_INVALID = 'invalid';

	/** Distingue un set realmente ausente de una lectura metadata fallida. */
	private static bool $topology_read_failed = false;

	/** Distingue contrato incompatible de una lectura física fallida. */
	private static bool $schema_read_failed = false;

	/** Resultado de la última lectura directa de una option usada como CAS. */
	private static bool $option_read_failed = false;

	/**
	 * Las dos claves del gate de schema ya se pidieron en lote en este request.
	 *
	 * Es un memo de la LECTURA EN LOTE, no del veredicto: `get_option()` sigue
	 * decidiendo cada vez, así que la comparación antes/después de
	 * `claim_version()` en `maybe_install()` conserva su semántica.
	 */
	private static bool $schema_options_primed = false;

	/**
	 * Punto ÚNICO para bumpear la generación del índice a un valor siempre nuevo
	 * (microtime: dos escrituras en el mismo segundo generan claves distintas).
	 *
	 * El write dispara `update_option_akb_search_index_gen` →
	 * `Support\RestCache::purge()`, así que todo lo que cambie lo que se sirve
	 * DEBE pasar por acá: escrituras live del Indexer, el swap del rebuild
	 * (el RENAME no toca ninguna option por sí solo) y los cambios de
	 * configuración semántica (sinónimos, stopwords, fuzzy, facetas).
	 *
	 * Autoload off: solo se lee al construir el sidebar del landing.
	 *
	 * Con `$target` el write es idempotente y reproducible por recovery: el
	 * mismo generation_target puede reintentarse después de un crash sin acuñar
	 * otra generación. El resultado se relee porque update_option() devuelve
	 * false tanto si el valor no cambió como si falló.
	 *
	 * @param string|null $target Generación exacta, o null para crearla ahora.
	 * @return bool True sólo si el valor objetivo quedó persistido.
	 */
	public static function bump_index_generation( ?string $target = null ): bool {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return false;
		}
		if ( false !== self::database_transaction_is_open() ) {
			return false; // Nunca publicar option/cache antes del COMMIT de filas.
		}

		$generation = null === $target ? (string) microtime( true ) : $target;
		$current    = get_option( self::OPTION_INDEX_GEN, null );
		if ( is_string( $current ) && $generation === $current ) {
			return true;
		}

		update_option( self::OPTION_INDEX_GEN, $generation, false );

		return $generation === get_option( self::OPTION_INDEX_GEN, null );
	}

	/**
	 * Publica una generación exacta después del COMMIT y verifica su purge.
	 *
	 * El caller conserva la deuda (claim LEASED/pending) hasta que este método
	 * devuelve true. Reintentar el mismo target es idempotente.
	 *
	 * @param string $target Generación determinística del claim/run.
	 * @return bool
	 */
	public static function publish_index_generation( string $target ): bool {
		if ( '' === $target || false !== self::database_transaction_is_open() ) {
			return false;
		}
		if ( ! self::bump_index_generation( $target ) ) {
			return false;
		}

		$published = Support\RestCache::purge( $target )
			&& Support\RestCache::has_purge_receipt( $target )
			&& $target === (string) get_option( self::OPTION_INDEX_GEN, '' );
		if ( $published && class_exists( SemanticPublication::class ) ) {
			SemanticPublication::advance_generation_receipt( $target );
		}
		return $published;
	}

	/**
	 * Persiste primero la intención de publicar una generación semántica/live.
	 *
	 * Solicitudes concurrentes incrementan `wanted_seq`; la más nueva subsume
	 * las anteriores porque su invalidación ocurre después de todos esos cambios.
	 * El caller no ACKea su deuda hasta `generation_publication_satisfied()`.
	 *
	 * Un request pre-write puede usar delay y conservar el target devuelto. Su
	 * post-hook vuelve a pedir con el MISMO target y delay 0: así elimina sólo
	 * su barrera, sin adelantar writes concurrentes todavía no confirmados.
	 * Si el proceso cae tras el write, la barrera vence y converge sola.
	 *
	 * @param string      $reason        Causa estable.
	 * @param string|null $target        Target determinístico opcional.
	 * @param int         $delay_seconds Barrera pre-write; 0 confirma ese target.
	 * @return array{token: string, seq: int, target: string, not_before: int}|false
	 */
	public static function request_generation_publication( string $reason, ?string $target = null, int $delay_seconds = 0 ) {
		$reason        = self::normalise_rebuild_reason( $reason );
		$delay_seconds = max( 0, min( 3600, $delay_seconds ) );

		for ( $attempt = 0; $attempt < 10; ++$attempt ) {
			$raw = self::read_option_uncached( self::OPTION_GENERATION_OUTBOX, false );
			if ( self::$option_read_failed ) {
				return false;
			}
			$state = self::normalise_generation_outbox( $raw );
			if ( false !== $raw && null === $state ) {
				return false;
			}
			if ( null === $state ) {
				$state = array(
					'protocol'     => self::GENERATION_OUTBOX_PROTOCOL,
					'token'        => self::new_rebuild_token(),
					'wanted_seq'   => 0,
					'acked_seq'    => 0,
					'target'       => '',
					'reason'       => '',
					'requested_at' => 0,
					'ack_target'   => '',
					'acked_at'     => 0,
					'not_before'   => 0,
					'barriers'     => array(),
				);
			}

			$now                   = time();
			$state['wanted_seq']   = (int) $state['wanted_seq'] + 1;
			$state['reason']       = $reason;
			$state['requested_at'] = $now;
			$state['target']       = null !== $target && '' !== $target
				? substr( $target, 0, 191 )
				: 'akb-gen-' . substr( (string) $state['token'], 0, 32 ) . '-' . (int) $state['wanted_seq'];

			$barriers = array();
			foreach ( (array) ( $state['barriers'] ?? array() ) as $barrier_target => $deadline ) {
				if ( (int) $deadline > $now ) {
					$barriers[ (string) $barrier_target ] = (int) $deadline;
				}
			}
			if ( 0 < $delay_seconds ) {
				$barriers[ (string) $state['target'] ] = max(
					(int) ( $barriers[ (string) $state['target'] ] ?? 0 ),
					$now + $delay_seconds
				);
			} else {
				unset( $barriers[ (string) $state['target'] ] );
			}
			if ( count( $barriers ) > self::GENERATION_BARRIER_MAX ) {
				// Fail-safe acotado: esperar la mayor barrera es más lento pero no
				// publica antes de un write concurrente que aún no terminó.
				$barriers = array( '__coalesced__' => max( $barriers ) );
			}
			$state['barriers']   = $barriers;
			$state['not_before'] = empty( $barriers ) ? 0 : max( $barriers );

			if ( self::replace_option_cas( self::OPTION_GENERATION_OUTBOX, $raw, $state, false ) ) {
				return array(
					'token'      => (string) $state['token'],
					'seq'        => (int) $state['wanted_seq'],
					'target'     => (string) $state['target'],
					'not_before' => (int) $state['not_before'],
				);
			}
		}

		return false;
	}

	/**
	 * Publica y CAS-ACKea la revisión más reciente del outbox.
	 *
	 * @return bool True si no queda deuda o convergió; false conserva outbox.
	 */
	public static function consume_generation_publications(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}

		// Hot path de init: ausencia confirmada requiere sólo una lectura puntual
		// de wp_options. Toda deuda/corrupción se vuelve a validar bajo el fence.
		$initial_raw = self::read_option_uncached( self::OPTION_GENERATION_OUTBOX, false );
		if ( self::$option_read_failed ) {
			return false;
		}
		$initial_state = self::normalise_generation_outbox( $initial_raw );
		if ( false !== $initial_raw && null === $initial_state ) {
			return false;
		}
		if ( null === $initial_state ) {
			return true;
		}
		// Atajo de convergencia: sin deuda lógica y con el efecto físico presente
		// (option de generación y comprobante de purga), el fence no requiere reejecución.
		// Si se detecta deuda, discrepancia de secuencia o comprobante ausente, se ejecuta
		// la vía de sincronización bajo lock.
		if ( self::generation_outbox_is_converged( $initial_state ) ) {
			return true;
		}
		if ( false !== self::database_transaction_is_open() ) {
			return false;
		}

		$fence = new WriteFence( $wpdb );
		if ( ! $fence->acquire( 0 ) ) {
			return false;
		}

		try {
			for ( $attempt = 0; $attempt < 10; ++$attempt ) {
				$raw = self::read_option_uncached( self::OPTION_GENERATION_OUTBOX, false );
				if ( self::$option_read_failed ) {
					return false;
				}
				$state = self::normalise_generation_outbox( $raw );
				if ( false !== $raw && null === $state ) {
					return false;
				}
				if ( null === $state ) {
					return true;
				}
				if ( time() < (int) $state['not_before'] ) {
					return false;
				}
				if ( (int) $state['acked_seq'] >= (int) $state['wanted_seq'] ) {
					if ( 0 === (int) $state['wanted_seq'] ) {
						return true;
					}

					$target     = (string) $state['target'];
					$ack_target = (string) $state['ack_target'];
					if ( '' === $target || $target !== $ack_target ) {
						return false;
					}
					if ( $target === (string) get_option( self::OPTION_INDEX_GEN, '' )
						&& Support\RestCache::has_purge_receipt( $target ) ) {
						return true;
					}

					// El ACK lógico no basta: repara una option/receipt perdido y sólo
					// converge después de verificar otra vez el efecto físico exacto.
					return self::publish_index_generation( $target )
						&& $target === (string) get_option( self::OPTION_INDEX_GEN, '' )
						&& Support\RestCache::has_purge_receipt( $target );
				}

				$target = (string) $state['target'];
				if ( '' === $target || ! self::publish_index_generation( $target )
					|| $target !== (string) get_option( self::OPTION_INDEX_GEN, '' )
					|| ! Support\RestCache::has_purge_receipt( $target ) ) {
					return false;
				}

				$state['acked_seq']  = (int) $state['wanted_seq'];
				$state['ack_target'] = $target;
				$state['acked_at']   = time();
				$state['not_before'] = 0;
				$state['barriers']   = array();
				if ( self::replace_option_cas( self::OPTION_GENERATION_OUTBOX, $raw, $state, false ) ) {
					return true;
				}
				// Una revisión más nueva ganó: el loop publica esa última revisión.
			}
		} finally {
			$fence->release();
		}

		return false;
	}

	/**
	 * Verdicto de convergencia sin fence, idéntico al de la rama fenced.
	 *
	 * Devolver false NO afirma que haya deuda: sólo que este estado no se puede
	 * dar por convergido sin releer bajo el fence.
	 *
	 * @param array<string, mixed> $state Outbox ya normalizado.
	 * @return bool
	 */
	private static function generation_outbox_is_converged( array $state ): bool {
		if ( (int) $state['acked_seq'] < (int) $state['wanted_seq'] ) {
			return false;
		}
		// Una barrera vigente es trabajo pendiente aunque el seq cuadre.
		if ( time() < (int) $state['not_before'] || array() !== (array) $state['barriers'] ) {
			return false;
		}
		if ( 0 === (int) $state['wanted_seq'] ) {
			return true;
		}
		$target = (string) $state['target'];
		if ( '' === $target || $target !== (string) $state['ack_target'] ) {
			return false;
		}

		return $target === (string) get_option( self::OPTION_INDEX_GEN, '' )
			&& Support\RestCache::has_purge_receipt( $target );
	}

	/**
	 * @param array{token: string, seq: int, target?: string} $identity Identidad devuelta al requestor.
	 * @return bool
	 */
	public static function generation_publication_satisfied( array $identity ): bool {
		$state = self::normalise_generation_outbox(
			self::read_option_uncached( self::OPTION_GENERATION_OUTBOX, false )
		);
		return ! self::$option_read_failed
			&& is_array( $state )
			&& (string) ( $identity['token'] ?? '' ) === (string) $state['token']
			&& (int) $state['acked_seq'] >= (int) ( $identity['seq'] ?? PHP_INT_MAX )
			&& time() >= (int) $state['not_before']
			&& '' !== (string) $state['ack_target']
			&& (string) $state['ack_target'] === (string) get_option( self::OPTION_INDEX_GEN, '' )
			&& Support\RestCache::has_purge_receipt( (string) $state['ack_target'] );
	}

	/**
	 * @return bool|null True en TX, false fuera, null si metadata falla.
	 */
	private static function database_transaction_is_open(): ?bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		$wpdb->last_error = '';
		$value             = $wpdb->get_var( 'SELECT @@in_transaction' );
		if ( '' !== (string) ( $wpdb->last_error ?? '' ) || ! in_array( (string) $value, array( '0', '1' ), true ) ) {
			return null;
		}

		return '1' === (string) $value;
	}

	/**
	 * Tabla de documentos (1 fila/producto).
	 *
	 * @return string
	 */
	public static function docs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_search_docs';
	}

	/**
	 * Tabla de tokens (índice de matching por prefijo).
	 *
	 * @return string
	 */
	public static function tokens_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_search_tokens';
	}

	/**
	 * Espejo de facetas.
	 *
	 * @return string
	 */
	public static function facets_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_search_facet_index';
	}

	/**
	 * Log agregado de búsquedas.
	 *
	 * @return string
	 */
	public static function qlog_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_search_qlog';
	}

	/**
	 * Outbox/manifest durable del protocolo de rebuild v2.
	 *
	 * Esta tabla es SIEMPRE viva: no participa del swap ni admite sufijos. Las
	 * acciones de Action Scheduler son sólo wake-ups; la deuda real queda acá.
	 *
	 * @return string
	 */
	public static function work_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_search_work';
	}

	/**
	 * Tabla persistente de solicitudes de títulos no disponibles (demanda real).
	 *
	 * Esta tabla es SIEMPRE viva: no participa del swap ni admite sufijos.
	 *
	 * @return string
	 */
	public static function requests_table(): string {
		global $wpdb;
		return $wpdb->prefix . TitleRequests::TABLE_BASE;
	}

	/**
	 * Las 3 tablas que participan del rebuild atómico (qlog NO — es log vivo).
	 *
	 * @return array<int, string>
	 */
	public static function swappable_tables(): array {
		return array_values( self::swappable_table_map() );
	}

	/**
	 * Índices que el esquema vigente espera por tabla (nombre de KEY, sin PRIMARY).
	 * Permite auditar la integridad estructural del esquema independientemente de la versión sellada.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function expected_indexes(): array {
		return array(
			self::docs_table()   => array( 'idx_visible_stock', 'idx_sku', 'idx_sku_norm' ),
			self::tokens_table() => array( 'idx_post_token', 'idx_field_token' ),
			self::facets_table() => array( 'idx_facet_value', 'idx_facet_num', 'idx_post' ),
			self::work_table()   => array( 'uq_scope_run_product', 'claim', 'run_outstanding' ),
		);
	}

	/**
	 * Índices esperados AUSENTES en la DB real (formato `tabla.indice`), vacío
	 * si el schema está completo. Consulta SHOW INDEX solo cuando se pide
	 * (admin/CLI vía HealthReport::collect, jamás en el front). Una tabla
	 * inexistente reporta todos sus índices como faltantes — mismo veredicto,
	 * peor causa.
	 *
	 * Devuelve `null` cuando la lectura de metadata FALLA: no es lo mismo «no
	 * están los índices» que «no se pudo mirar». Antes un hipo de MySQL en el
	 * `SHOW INDEX` dejaba `$present` vacío y el método declaraba ausentes TODOS
	 * los índices, así que el informe de salud y el gate de esquema veían el
	 * índice entero como no construido y podían mandar a reparar un esquema
	 * sano. Mismo contrato que `HealthReport::normalise_count()`.
	 *
	 * @return array<int, string>|null
	 */
	public static function missing_indexes(): ?array {
		global $wpdb;

		$missing = array();
		foreach ( self::expected_indexes() as $table => $indexes ) {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- nombre de tabla propio, lectura de metadata.
			$present = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 );
			if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || ! is_array( $present ) ) {
				return null;
			}
			foreach ( $indexes as $index ) {
				if ( ! in_array( $index, $present, true ) ) {
					$missing[] = $table . '.' . $index;
				}
			}
		}

		return $missing;
	}

	/**
	 * Verifica el contrato físico de la tabla work, no sólo su nombre.
	 *
	 * dbDelta puede devolver sin lanzar aunque una sentencia haya fallado. Este
	 * gate consulta la DB real y exige InnoDB, todas las columnas del protocolo,
	 * AUTO_INCREMENT y las columnas/orden/unicidad exactas de cada índice.
	 * El set de índices también debe ser exacto: un extra puede cambiar planes,
	 * costo de writes y semántica de unicidad sin quedar cubierto por el sello.
	 *
	 * @return bool
	 */
	public static function work_schema_ready(): bool {
		return self::table_matches_contract( self::work_table(), self::work_table_contract() );
	}

	/**
	 * Verifica las tablas vivas que usan readers y writers, incluida qlog.
	 *
	 * @return bool
	 */
	public static function live_schema_ready(): bool {
		foreach ( self::swappable_table_contracts() as $logical => $contract ) {
			$table = self::swappable_table_map()[ $logical ];
			if ( ! self::table_matches_contract( $table, $contract ) ) {
				return false;
			}
		}

		return self::table_matches_contract( self::qlog_table(), self::qlog_table_contract() );
	}

	/**
	 * Verifica las tres sombras antes de cualquier writer shadow o RENAME.
	 *
	 * @return bool
	 */
	public static function shadow_schema_ready(): bool {
		return self::swappable_schema_ready( self::SHADOW_SUFFIX );
	}

	/**
	 * Verifica el set de rollback post-swap antes de publicar/limpiar.
	 *
	 * @return bool
	 */
	public static function old_schema_ready(): bool {
		return self::swappable_schema_ready( self::OLD_SUFFIX );
	}

	/**
	 * Crea o migra si la versión guardada difiere, sin sellar antes de verificar.
	 *
	 * Un named lock serializa dbDelta entre requests sin persistir un claim que
	 * pueda quedar huérfano por crash. DB_VERSION se escribe al FINAL, sólo si
	 * install() verificó el schema y la misma conexión aún posee el lock. Si el
	 * proceso muere antes, la option conserva la versión anterior y la request
	 * siguiente reintenta la migración idempotente.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		global $wpdb;

		$previous = get_option( self::OPTION_KEY );
		if ( self::DB_VERSION === $previous && self::runtime_schema_ready() ) {
			return;
		}

		$fence = new WriteFence( $wpdb );
		if ( ! $fence->acquire( 0 ) ) {
			return;
		}

		try {
			$lock = self::acquire_install_lock();
			if ( null === $lock ) {
				return; // Otra request migra; esta no toca schema ni versión.
			}

			try {
				// La request que esperó/reintentó debe releer después de ambos locks.
				wp_cache_delete( self::OPTION_KEY, 'options' );
				$previous = get_option( self::OPTION_KEY );
				if ( self::DB_VERSION === $previous && self::runtime_schema_ready() ) {
					return;
				}
				if ( ! self::migration_preconditions_ready( false === $previous ) ) {
					return;
				}

				if ( ! self::install() || ! self::install_lock_is_owned( $lock ) || ! $fence->is_owned() ) {
					return;
				}

				// La señal se persiste antes del sello: un crash posterior no puede
				// dejar la versión final sin el rebuild requerido por bump o drift.
				if ( self::should_flag_rebuild( $previous ) || self::DB_VERSION === $previous ) {
					// `request_rebuild()` acuña requested_at y not_before desde el
					// mismo reloj. Evita que cruzar un segundo entre dos time() reduzca
					// silenciosamente la gracia rolling de 300 a 299 segundos.
					if ( ! self::request_rebuild( 'schema_migration', null, self::DB_VERSION ) ) {
						return;
					}
				}

				// CAS final: la versión nunca representa un schema no verificado.
				if ( self::DB_VERSION !== $previous
					&& self::install_lock_is_owned( $lock )
					&& $fence->is_owned() ) {
					if ( ! self::claim_version( $previous ) ) {
						return;
					}
				}
				if ( self::DB_VERSION !== get_option( self::OPTION_KEY, false ) ) {
					return;
				}
				update_option( self::OPTION_SCHEMA_SEAL, self::SCHEMA_SEAL, false );
				if ( self::SCHEMA_SEAL !== get_option( self::OPTION_SCHEMA_SEAL, false ) ) {
					return;
				}
			} finally {
				self::release_install_lock( $lock );
			}
		} finally {
			$fence->release();
		}
	}

	/**
	 * Sello CAS final de la versión, ejecutado sólo tras verificar el schema.
	 *
	 * Tanto ausencia como bump usan el CAS SQL directo de options. Core implementa
	 * add_option con una ruta UPSERT en algunas versiones; no sirve como insert-only
	 * porque podría pisar una versión aparecida después de nuestra lectura.
	 *
	 * @param mixed $previous Valor leído de la option (false si no existe).
	 * @return bool True si ESTE request persistió el sello.
	 */
	private static function claim_version( $previous ): bool {
		return self::replace_option_cas( self::OPTION_KEY, $previous, self::DB_VERSION, false );
	}

	/**
	 * Impide que una migración rolling altere live mientras un run viejo posee
	 * sombras/estado que luego podría swapear sobre el schema nuevo.
	 *
	 * @param bool $fresh Option de versión realmente ausente.
	 * @return bool
	 */
	private static function migration_preconditions_ready( bool $fresh ): bool {
		global $wpdb;

		$topology = self::table_topology();
		if ( self::$topology_read_failed ) {
			return false;
		}

		if ( $fresh ) {
			if ( in_array( true, $topology['new'], true ) || in_array( true, $topology['old'], true ) ) {
				return false;
			}
		} elseif ( self::TOPOLOGY_CLEAN_PRE !== self::classify_topology_state( $topology ) ) {
			return false;
		}

		$raw_state = self::read_option_uncached( RebuildJob::OPTION_STATE, false );
		if ( self::$option_read_failed ) {
			return false;
		}
		if ( false === $raw_state ) {
			return true;
		}

		$state    = self::decode_rebuild_state_value( $raw_state );
		$decision = self::legacy_migration_decision( $state, self::classify_topology_state( $topology ) );
		if ( 'retire' === $decision ) {
			$receipt = self::legacy_migration_receipt( $state, 'aborted', 'legacy_state_retired_for_schema_migration' );
			update_option( RebuildJob::OPTION_LAST, $receipt, false );
			if ( $receipt !== get_option( RebuildJob::OPTION_LAST, false ) ) {
				return false;
			}
			return self::replace_option_cas( RebuildJob::OPTION_STATE, $raw_state, false, true );
		}
		if ( 'block' === $decision ) {
			$blocked = is_array( $state ) ? $state : array();
			$blocked['protocol']     = 2;
			$blocked['run_id']       = (string) ( $blocked['run_id'] ?? self::new_rebuild_token() );
			$blocked['phase']        = RebuildJob::PHASE_BLOCKED;
			$blocked['error']        = 'legacy_state_blocks_schema_migration';
			$blocked['topology']     = self::classify_topology_state( $topology );
			$blocked['updated_at']   = time();
			$blocked['heartbeat_at'] = time();
			$encoded                 = function_exists( 'wp_json_encode' ) ? wp_json_encode( $blocked ) : json_encode( $blocked );
			if ( is_string( $encoded ) ) {
				self::replace_option_cas( RebuildJob::OPTION_STATE, $raw_state, $encoded, false );
			}
			$receipt = self::legacy_migration_receipt( $blocked, 'blocked', 'legacy_state_blocks_schema_migration' );
			update_option( RebuildJob::OPTION_LAST, $receipt, false );
		}

		return false;
	}

	/**
	 * @param array<string, mixed>|null $state    Estado decodificado.
	 * @param string                    $topology Topología exacta.
	 * @return string retire|block|wait.
	 */
	private static function legacy_migration_decision( ?array $state, string $topology ): string {
		if ( is_array( $state ) && 2 === (int) ( $state['protocol'] ?? 0 ) ) {
			return 'wait';
		}
		return self::TOPOLOGY_CLEAN_PRE === $topology ? 'retire' : 'block';
	}

	/**
	 * @param mixed $raw Option value.
	 * @return array<string, mixed>|null
	 */
	private static function decode_rebuild_state_value( $raw ): ?array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * @param array<string, mixed>|null $state  Estado legacy.
	 * @param string                    $result aborted|blocked.
	 * @param string                    $error  Código estable.
	 * @return array<string, mixed>
	 */
	private static function legacy_migration_receipt( ?array $state, string $result, string $error ): array {
		return array(
			'run_id'      => (string) ( $state['run_id'] ?? '' ),
			'started_at'  => (int) ( $state['started_at'] ?? 0 ),
			'finished_at' => time(),
			'docs'        => 0,
			'result'      => $result,
			'error'       => $error,
		);
	}

	/**
	 * Adquiere el lock de migración y recuerda la conexión propietaria.
	 *
	 * @return array{name: string, owner: int}|null
	 */
	private static function acquire_install_lock(): ?array {
		global $wpdb;

		$name  = self::install_lock_name();
		$owner = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		if ( $owner <= 0 ) {
			return null;
		}

		$acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name )
		);
		if ( 1 !== (int) $acquired ) {
			return null;
		}

		$lock = array(
			'name'  => $name,
			'owner' => $owner,
		);

		if ( ! self::install_lock_is_owned( $lock ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			return null;
		}

		return $lock;
	}

	/**
	 * Verifica que wpdb no se reconectó durante el DDL y perdió el named lock.
	 *
	 * @param array{name: string, owner: int} $lock Lock adquirido.
	 * @return bool
	 */
	private static function install_lock_is_owned( array $lock ): bool {
		global $wpdb;

		$used_by = $wpdb->get_var(
			$wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $lock['name'] )
		);

		return $lock['owner'] === (int) $used_by;
	}

	/**
	 * Libera el named lock sólo si esta conexión aún es su dueña.
	 *
	 * @param array{name: string, owner: int} $lock Lock adquirido.
	 * @return void
	 */
	private static function release_install_lock( array $lock ): void {
		global $wpdb;

		if ( self::install_lock_is_owned( $lock ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock['name'] ) );
		}
	}

	/**
	 * Nombre server-wide acotado a 64 bytes y aislado por DB/prefijo.
	 *
	 * @return string
	 */
	private static function install_lock_name(): string {
		global $wpdb;

		$database = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		return 'akb_search_install_' . md5( $database . '|' . $wpdb->prefix );
	}

	/**
	 * ¿El valor previo de la option amerita re-derivar el índice tras migrar?
	 * Solo un bump real: string no-vacío distinto de DB_VERSION. get_option
	 * devuelve false (no '') cuando la option no existe — fresh install.
	 *
	 * @param mixed $previous Valor previo de la option.
	 * @return bool
	 */
	public static function should_flag_rebuild( $previous ): bool {
		return is_string( $previous ) && '' !== $previous && self::DB_VERSION !== $previous;
	}

	/**
	 * Solicita una re-derivación durable sin reemplazar una solicitud pendiente.
	 *
	 * Si el primer token ya pertenece al run activo, se agrega un segundo token
	 * de follow-up. Las llamadas siguientes coalescen sobre ese follow-up. Una
	 * migración de schema conserva siempre su propio `not_before`, incluso si ya
	 * había una solicitud funcional pendiente.
	 *
	 * @param string      $reason     Causa estable para diagnóstico.
	 * @param int|null    $not_before Instante mínimo; ahora si se omite.
	 * @param string|null $version    Versión que exige la re-derivación.
	 * @return bool True si la solicitud ya existía o quedó persistida.
	 */
	public static function request_rebuild( string $reason = 'manual', ?int $not_before = null, ?string $version = null ): bool {
		$reason     = self::normalise_rebuild_reason( $reason );
		$requested_at = time();
		$not_before = max(
			0,
			null === $not_before
				? $requested_at + ( 'schema_migration' === $reason ? self::REBUILD_GRACE_SECONDS : 0 )
				: $not_before
		);
		$version    = null === $version ? self::DB_VERSION : $version;

		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			$raw      = self::read_option_uncached( self::OPTION_PENDING_REBUILD, false );
			if ( self::$option_read_failed ) {
				return false;
			}
			$requests = self::normalise_rebuild_signal( $raw );
			$assigned = isset( $GLOBALS['wpdb'] ) && class_exists( RebuildJob::class )
				? RebuildJob::current_request_identity()
				: null;
			$requests = self::merge_rebuild_request( $requests, $reason, $version, $not_before, $assigned, $requested_at );
			$replacement = self::rebuild_signal_envelope( $requests );

			if ( self::replace_pending_signal( $raw, $replacement ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Primera solicitud pendiente normalizada, o null si no existe.
	 *
	 * @return array{token: string, seq: int, version: string, reason: string, requested_at: int, not_before: int}|null
	 */
	public static function pending_rebuild_request(): ?array {
		$requests = self::normalise_rebuild_signal( self::read_option_uncached( self::OPTION_PENDING_REBUILD, false ) );
		if ( self::$option_read_failed ) {
			return null;
		}
		return $requests[0] ?? null;
	}

	/**
	 * Gate temporal común: ninguna ruta puede adelantar una gracia posterior.
	 *
	 * @param string|null $token Si se entrega, además debe ser el primer request.
	 * @return bool
	 */
	public static function pending_rebuild_is_ready( ?string $token = null ): bool {
		$requests = self::normalise_rebuild_signal( self::read_option_uncached( self::OPTION_PENDING_REBUILD, false ) );
		if ( self::$option_read_failed ) {
			return false;
		}
		if ( empty( $requests ) ) {
			return null === $token || '' === $token;
		}
		if ( null !== $token && $token !== (string) $requests[0]['token'] ) {
			return false;
		}

		$not_before = 0;
		foreach ( $requests as $request ) {
			$not_before = max( $not_before, (int) $request['not_before'] );
		}

		return time() >= $not_before;
	}

	/**
	 * El runtime sólo puede cablear lectores/escritores sobre el schema sellado.
	 *
	 * @return bool
	 */
	public static function runtime_schema_ready(): bool {
		// Precarga en lote de opciones del esquema sin autoload para reducir consultas a MySQL.
		// Se mantienen fuera de alloptions para permitir invalidación exacta de caché
		// tras la obtención de cerraduras de migración.
		if ( ! self::$schema_options_primed && function_exists( 'wp_prime_option_caches' ) ) {
			// Sólo se cebe una vez: con las claves ya en caché el lote no ahorra
			// nada y sus 27 invocaciones se comerían la ganancia.
			self::$schema_options_primed = true;
			wp_prime_option_caches(
				array(
					self::OPTION_KEY,
					self::OPTION_SCHEMA_SEAL,
					'akb_search_index_gen',
					'akb_search_rest_purge_receipt',
					'akb_search_semantic_fingerprint',
					'akb_search_editorial_reconcile_at',
					'akb_search_requests_db_version',
					'akb_search_empty_hint_suggest',
					'akb_search_network_notice',
				)
			);
		}

		return self::DB_VERSION === get_option( self::OPTION_KEY, false )
			&& self::SCHEMA_SEAL === get_option( self::OPTION_SCHEMA_SEAL, false );
	}

	/** @return string Contrato físico sellado por installer/recovery. */
	public static function schema_seal(): string {
		return self::SCHEMA_SEAL;
	}

	/**
	 * Gate listo para Search::register: schema completo y gracia rolling vencida.
	 *
	 * @return bool
	 */
	public static function runtime_ready(): bool {
		return self::runtime_schema_ready() && self::pending_rebuild_is_ready();
	}

	/**
	 * Gate físico para cada writer live; no confía sólo en el sello durable.
	 *
	 * @return bool
	 */
	public static function live_writer_schema_ready(): bool {
		return self::runtime_schema_ready()
			&& self::swappable_schema_ready( '' )
			&& self::work_schema_ready();
	}

	/**
	 * Verifica el contrato físico del writer sin confundir un blip de metadata
	 * con incompatibilidad comprobada.
	 *
	 * @return string ready|retry|invalid.
	 */
	public static function inspect_live_writer_schema(): string {
		self::$schema_read_failed = false;
		if ( ! self::runtime_schema_ready() ) {
			return self::SCHEMA_CHECK_RETRY;
		}
		$ready = self::swappable_schema_ready( '' )
			&& self::work_schema_ready();

		return self::schema_check_result( $ready );
	}

	/**
	 * Verifica live+work+shadow para PREPARING/READY_SWAP con resultado
	 * triestado. Sólo `invalid` prueba un contrato físico incompatible.
	 *
	 * @return string ready|retry|invalid.
	 */
	public static function inspect_ready_swap_schema(): string {
		self::$schema_read_failed = false;
		if ( ! self::runtime_schema_ready() ) {
			return self::SCHEMA_CHECK_RETRY;
		}
		$ready = self::swappable_schema_ready( '' )
			&& self::work_schema_ready()
			&& self::swappable_schema_ready( self::SHADOW_SUFFIX );

		return self::schema_check_result( $ready );
	}

	/**
	 * @param bool $ready Resultado del contrato.
	 * @return string
	 */
	private static function schema_check_result( bool $ready ): string {
		if ( $ready ) {
			return self::SCHEMA_CHECK_READY;
		}

		return self::$schema_read_failed ? self::SCHEMA_CHECK_RETRY : self::SCHEMA_CHECK_INVALID;
	}

	/**
	 * Retira exclusivamente el primer token, promoviendo cualquier follow-up.
	 *
	 * El UPDATE/DELETE usa el valor serializado previo como CAS; una señal que
	 * cambió entre lectura y ACK nunca puede ser eliminada por un run ajeno.
	 *
	 * @param string $token Token ya correlacionado con un estado durable.
	 * @param mixed  $expected_raw Foto opcional leída antes de crear el run.
	 * @return bool
	 */
	public static function acknowledge_pending_rebuild( string $token, int $seq, $expected_raw = null ): bool {
		if ( '' === $token || $seq <= 0 ) {
			return false;
		}

		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			if ( null !== $expected_raw ) {
				self::$option_read_failed = false;
			}
			$raw      = null === $expected_raw ? self::read_option_uncached( self::OPTION_PENDING_REBUILD, false ) : $expected_raw;
			if ( self::$option_read_failed ) {
				return false;
			}
			$requests = self::normalise_rebuild_signal( $raw );
			if ( empty( $requests ) ) {
				return true;
			}
			if ( $token !== (string) $requests[0]['token'] || $seq !== (int) $requests[0]['seq'] ) {
				return false;
			}

			array_shift( $requests );
			$replacement = empty( $requests ) ? false : self::rebuild_signal_envelope( $requests );
			if ( self::replace_pending_signal( $raw, $replacement ) ) {
				return true;
			}

			$expected_raw = null;
		}

		return false;
	}

	/**
	 * Consume la señal pending_rebuild sólo después de identificar un run cuyo
	 * estado durable contiene exactamente el mismo token.
	 *
	 * Si schedule() devuelve false (reconstrucción en curso o cola ausente),
	 * la señal se re-arma para ser reintentada en el siguiente ciclo de inicialización.
	 *
	 * @param mixed $scheduler Solo tests: callable que reemplaza a
	 *                         RebuildJob::schedule. SIN type-hint a propósito:
	 *                         como callback de `init`, do_action pasa '' como
	 *                         primer argumento — un ?callable tipado fatalea
	 *                         el sitio entero (TypeError en init).
	 * @return void
	 */
	public static function consume_pending_rebuild( $scheduler = null ): void {
		$raw      = self::read_option_uncached( self::OPTION_PENDING_REBUILD, false );
		if ( self::$option_read_failed ) {
			return;
		}
		$requests = self::normalise_rebuild_signal( $raw );
		$request  = $requests[0] ?? null;
		if ( null === $request ) {
			return;
		}

		// Una instalación nueva puede convivir durante unos segundos con requests
		// PHP que ya habían cargado 0.1.12. Esos writers no conocen el fence v2;
		// arrancar la sombra de inmediato abriría una ventana que ningún repair
		// durable podría observar. La señal queda intacta y el init/cron posterior
		// la consumirá una vez transcurrida la gracia. Señales string de releases
		// anteriores se conservan compatibles y se ejecutan de inmediato.
		if ( ! self::runtime_schema_ready() || ! self::pending_rebuild_is_ready( (string) $request['token'] ) ) {
			return;
		}

		$custom_scheduler = is_callable( $scheduler );
		if ( ! is_callable( $scheduler ) ) {
			$scheduler = array( RebuildJob::class, 'schedule_request' );
		}

		$run_id = '';
		try {
			$result = call_user_func( $scheduler, (string) $request['token'], (int) $request['seq'] );
			if ( is_string( $result ) ) {
				$run_id = $result;
			} elseif ( is_array( $result )
				&& (string) ( $result['request_token'] ?? '' ) === (string) $request['token']
				&& (int) ( $result['request_seq'] ?? 0 ) === (int) $request['seq']
				&& (bool) ( $result['durable'] ?? false ) ) {
				$run_id = (string) ( $result['run_id'] ?? '' );
			}
		} catch ( \Throwable $throwable ) {
			return; // El siguiente init correlaciona el estado que pudiera existir.
		}

		if ( '' === $run_id ) {
			return;
		}

		if ( ! $custom_scheduler && $run_id !== RebuildJob::durable_run_id_for_request( (string) $request['token'], (int) $request['seq'] ) ) {
			return;
		}

		self::acknowledge_pending_rebuild( (string) $request['token'], (int) $request['seq'], $raw );
	}

	/**
	 * Normaliza sobre v2 y señales legacy sin exigir una migración destructiva.
	 *
	 * @param mixed $raw Valor de option.
	 * @return array<int, array{token: string, seq: int, version: string, reason: string, requested_at: int, not_before: int}>
	 */
	private static function normalise_rebuild_signal( $raw ): array {
		if ( false === $raw || null === $raw || '' === $raw ) {
			return array();
		}

		$candidates = is_array( $raw )
			&& self::REBUILD_SIGNAL_PROTOCOL === (int) ( $raw['protocol'] ?? 0 )
			&& is_array( $raw['requests'] ?? null )
				? $raw['requests']
				: array( $raw );
		$requests   = array();
		foreach ( $candidates as $offset => $candidate ) {
			$candidate = is_array( $candidate ) ? $candidate : array( 'version' => (string) $candidate );
			$token     = trim( (string) ( $candidate['token'] ?? '' ) );
			if ( '' === $token ) {
				$token = 'legacy-' . substr( hash( 'sha256', Support\OptionCodec::encode( array( $raw, $offset ) ) ), 0, 32 );
			}

			$requests[] = array(
				'token'        => substr( $token, 0, 64 ),
				'seq'          => max( 1, (int) ( $candidate['seq'] ?? 1 ) ),
				'version'      => (string) ( $candidate['version'] ?? '' ),
				'reason'       => self::normalise_rebuild_reason( (string) ( $candidate['reason'] ?? 'legacy' ) ),
				'requested_at' => max( 0, (int) ( $candidate['requested_at'] ?? 0 ) ),
				'not_before'   => max( 0, (int) ( $candidate['not_before'] ?? 0 ) ),
			);
		}

		return array_slice( $requests, 0, self::REBUILD_SIGNAL_MAX_REQUESTS );
	}

	/**
	 * @param array<int, array<string, mixed>> $requests Solicitudes normalizadas.
	 * @return array{protocol: int, requests: array<int, array<string, mixed>>}
	 */
	private static function rebuild_signal_envelope( array $requests ): array {
		return array(
			'protocol' => self::REBUILD_SIGNAL_PROTOCOL,
			'requests' => array_values( $requests ),
		);
	}

	/**
	 * Cada invocación cambia token (append) o seq (coalescing). Así, un ACK
	 * que observó A no puede borrar una intención B llegada durante schedule A.
	 *
	 * @param array<int, array<string, mixed>>       $requests  Cola vigente.
	 * @param string                                 $reason    Causa nueva.
	 * @param string                                 $version   Versión nueva.
	 * @param int                                    $not_before Gate nuevo.
	 * @param array{token: string, seq: int}|null    $assigned  Identidad del run activo.
	 * @param int|null                               $requested_at Instante único de esta solicitud.
	 * @return array<int, array<string, mixed>>
	 */
	private static function merge_rebuild_request( array $requests, string $reason, string $version, int $not_before, ?array $assigned, ?int $requested_at = null ): array {
		$requested_at = null === $requested_at ? time() : max( 0, $requested_at );
		if ( empty( $requests ) ) {
			return array(
				array(
					'token'        => self::new_rebuild_token(),
					'seq'          => 1,
					'version'      => $version,
					'reason'       => $reason,
					'requested_at' => $requested_at,
					'not_before'   => $not_before,
				),
			);
		}

		if ( 'schema_migration' === $reason ) {
			foreach ( $requests as $offset => $request ) {
				if ( 'schema_migration' === (string) ( $request['reason'] ?? '' )
					&& $version === (string) ( $request['version'] ?? '' ) ) {
					$requests[ $offset ]['seq']          = (int) ( $request['seq'] ?? 0 ) + 1;
					$requests[ $offset ]['not_before']   = max( (int) ( $request['not_before'] ?? 0 ), $not_before );
					$requests[ $offset ]['requested_at'] = $requested_at;
					return $requests;
				}
			}
		}

		$last_offset = count( $requests ) - 1;
		$last        = $requests[ $last_offset ];
		$is_assigned = is_array( $assigned )
			&& (string) ( $assigned['token'] ?? '' ) === (string) ( $last['token'] ?? '' )
			&& (int) ( $assigned['seq'] ?? 0 ) === (int) ( $last['seq'] ?? 0 );

		if ( ! $is_assigned ) {
			$previous_requested_at           = (int) ( $last['requested_at'] ?? 0 );
			$preserve_schema_migration_clock = 'schema_migration' === (string) ( $last['reason'] ?? '' )
				&& 'schema_migration' !== $reason
				&& 0 < $previous_requested_at;
			$requests[ $last_offset ]['seq']          = (int) ( $last['seq'] ?? 0 ) + 1;
			$requests[ $last_offset ]['not_before']   = max( (int) ( $last['not_before'] ?? 0 ), $not_before );
			$requests[ $last_offset ]['requested_at'] = $preserve_schema_migration_clock ? $previous_requested_at : $requested_at;
			if ( 'schema_migration' === $reason ) {
				$requests[ $last_offset ]['reason']  = $reason;
				$requests[ $last_offset ]['version'] = $version;
			}
			return $requests;
		}

		$new_request = array(
			'token'        => self::new_rebuild_token(),
			'seq'          => 1,
			'version'      => $version,
			'reason'       => $reason,
			'requested_at' => $requested_at,
			'not_before'   => $not_before,
		);
		if ( count( $requests ) < self::REBUILD_SIGNAL_MAX_REQUESTS ) {
			$requests[] = $new_request;
			return $requests;
		}

		// La cola llena nunca descarta una migración: el último follow-up
		// funcional no asignado queda subsumido por el rebuild de schema.
		$requests[ $last_offset ] = $new_request;
		return $requests;
	}

	/**
	 * CAS de option que evita borrar/reemplazar un token observado por otro run.
	 *
	 * @param mixed $expected    Valor previo exacto.
	 * @param mixed $replacement Nuevo sobre, o false para retirar la option.
	 * @return bool
	 */
	private static function replace_pending_signal( $expected, $replacement ): bool {
		return self::replace_option_cas(
			self::OPTION_PENDING_REBUILD,
			$expected,
			$replacement,
			false === $replacement
		);
	}

	/**
	 * CAS genérico sobre option_value serializado, con lectura directa sin cache.
	 *
	 * @param string $option      Option propia.
	 * @param mixed  $expected    Valor exacto observado.
	 * @param mixed  $replacement Nuevo valor.
	 * @param bool   $delete      Eliminar en vez de actualizar.
	 * @return bool
	 */
	private static function replace_option_cas( string $option, $expected, $replacement, bool $delete ): bool {
		global $wpdb;

		if ( false === $expected ) {
			if ( $delete ) {
				return true;
			}
			if ( isset( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) ) {
				$wpdb->last_error = '';
				$inserted         = $wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
						$option,
						Support\OptionCodec::encode( $replacement ),
						'no'
					)
				);
				self::clear_option_cache( $option );
				if ( false === $inserted || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
					return false;
				}
				$current = self::read_option_uncached( $option, false );
				return ! self::$option_read_failed && 1 === (int) $inserted && $replacement === $current;
			}
			if ( ! function_exists( 'add_option' ) ) {
				return false;
			}
			$added = add_option( $option, $replacement, '', false );
			self::clear_option_cache( $option );
			$current = self::read_option_uncached( $option, false );
			return ! self::$option_read_failed && ( (bool) $added || $replacement === $current );
		}

		if ( isset( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) ) {
			if ( $delete ) {
				$result = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s",
						$option,
						Support\OptionCodec::encode( $expected )
					)
				);
			} else {
				$result = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
						Support\OptionCodec::encode( $replacement ),
						$option,
						Support\OptionCodec::encode( $expected )
					)
				);
			}
			self::clear_option_cache( $option );
			if ( 1 !== (int) $result ) {
				return false;
			}

			$current = self::read_option_uncached( $option, false );
			return ! self::$option_read_failed && ( $delete ? false === $current : $replacement === $current );
		}

		$current = self::read_option_uncached( $option, false );
		if ( self::$option_read_failed || $expected !== $current ) {
			return false;
		}
		if ( $delete ) {
			return delete_option( $option );
		}

		update_option( $option, $replacement, false );
		$current = self::read_option_uncached( $option, false );
		return ! self::$option_read_failed && $replacement === $current;
	}

	/**
	 * @param string $option Option propia.
	 * @param mixed  $default Ausencia.
	 * @return mixed
	 */
	private static function read_option_uncached( string $option, $default = false ) {
		global $wpdb;

		self::$option_read_failed = false;
		if ( isset( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_var' ) ) {
			$wpdb->last_error = '';
			$raw              = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					$option
				)
			);
			if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
				self::$option_read_failed = true;
				return $default;
			}
			if ( null === $raw ) {
				return $default;
			}
			return Support\OptionCodec::decode( (string) $raw );
		}

		return get_option( $option, $default );
	}

	/** @param string $option Option propia. @return void */
	private static function clear_option_cache( string $option ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/**
	 * @param mixed $raw Valor option.
	 * @return array<string, mixed>|null
	 */
	private static function normalise_generation_outbox( $raw ): ?array {
		if ( ! is_array( $raw )
			|| self::GENERATION_OUTBOX_PROTOCOL !== (int) ( $raw['protocol'] ?? 0 )
			|| '' === (string) ( $raw['token'] ?? '' )
			|| (int) ( $raw['wanted_seq'] ?? -1 ) < 0
			|| (int) ( $raw['acked_seq'] ?? -1 ) < 0
			|| (int) ( $raw['acked_seq'] ?? 0 ) > (int) ( $raw['wanted_seq'] ?? 0 ) ) {
			return null;
		}
		if ( isset( $raw['barriers'] ) && ! is_array( $raw['barriers'] ) ) {
			return null;
		}

		$barriers = array();
		foreach ( (array) ( $raw['barriers'] ?? array() ) as $target => $deadline ) {
			$target   = substr( (string) $target, 0, 191 );
			$deadline = (int) $deadline;
			if ( '' === $target || 0 >= $deadline ) {
				return null;
			}
			$barriers[ $target ] = $deadline;
		}
		if ( count( $barriers ) > self::GENERATION_BARRIER_MAX ) {
			return null;
		}
		$not_before = max( 0, (int) ( $raw['not_before'] ?? 0 ) );
		if ( ! empty( $barriers ) ) {
			$not_before = max( $not_before, max( $barriers ) );
		}

		return array(
			'protocol'     => self::GENERATION_OUTBOX_PROTOCOL,
			'token'        => substr( (string) $raw['token'], 0, 64 ),
			'wanted_seq'   => (int) $raw['wanted_seq'],
			'acked_seq'    => (int) $raw['acked_seq'],
			'target'       => substr( (string) ( $raw['target'] ?? '' ), 0, 191 ),
			'reason'       => self::normalise_rebuild_reason( (string) ( $raw['reason'] ?? 'unknown' ) ),
			'requested_at' => max( 0, (int) ( $raw['requested_at'] ?? 0 ) ),
			'ack_target'   => substr( (string) ( $raw['ack_target'] ?? '' ), 0, 191 ),
			'acked_at'     => max( 0, (int) ( $raw['acked_at'] ?? 0 ) ),
			'not_before'   => $not_before,
			'barriers'     => $barriers,
		);
	}

	/**
	 * @param string $reason Causa libre.
	 * @return string
	 */
	private static function normalise_rebuild_reason( string $reason ): string {
		$reason = strtolower( trim( preg_replace( '/[^a-zA-Z0-9_-]+/', '_', $reason ) ?? '' ) );
		return '' === $reason ? 'manual' : substr( $reason, 0, 64 );
	}

	/**
	 * @return string
	 */
	private static function new_rebuild_token(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return (string) wp_generate_uuid4();
		}
		try {
			return bin2hex( random_bytes( 24 ) );
		} catch ( \Throwable $throwable ) {
			return substr( hash( 'sha256', uniqid( 'akb-search-request-', true ) ), 0, 48 );
		}
	}

	/**
	 * dbDelta del schema completo (tablas vivas).
	 *
	 * @return bool True sólo si tablas, índices, collation y work durable
	 *              quedaron verificables en la DB real.
	 */
	public static function install(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::schema_sql() as $sql ) {
			dbDelta( $sql );
		}
		foreach ( array_merge( self::swappable_tables(), array( self::qlog_table(), self::work_table() ) ) as $table ) {
			if ( ! self::ensure_innodb( $table ) ) {
				return false;
			}
		}

		$token_ready = self::assert_token_collation( self::tokens_table() )
			&& self::migrate_tokens_post_index( self::tokens_table() );

		// Backfill idempotente de sku_norm para materializar la normalización
		// de códigos comerciales e ISBN sobre el índice existente.
		if ( ! self::backfill_sku_norm() ) {
			return false;
		}

		self::purge_legacy_rotating_transients();

		return $token_ready
			&& 'utf8mb4_bin' === self::token_collation()
			&& self::live_schema_ready()
			&& self::work_schema_ready()
			&& array() === self::missing_indexes();
	}

	/**
	 * Depuración de transients rotatorios obsoletos generados por versiones previas.
	 * Acotado a los prefijos exactos de Akibara Search en la tabla de options.
	 *
	 * @return void
	 */
	private static function purge_legacy_rotating_transients(): void {
		global $wpdb;

		foreach ( array( 'akb_vocab_', 'akb_lc_', 'akb_rlm_' ) as $prefix ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- purga directa en lote de transients propios.
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- propiedad de tabla segura; patrones vía %s.
					'_transient_' . $like,
					'_transient_timeout_' . $like
				)
			);
		}
	}

	/**
	 * Materializa sku_norm sin aceptar un UPDATE fallido como migración sellada.
	 * Cero filas afectadas es éxito idempotente; false o last_error no lo son.
	 *
	 * @return bool
	 */
	private static function backfill_sku_norm(): bool {
		global $wpdb;

		$docs             = self::docs_table();
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- {$docs} sale de self::docs_table() ($wpdb->prefix + literal fijo) y la sentencia no lleva ningún valor que preparar: los argumentos de REPLACE son las constantes '-' y ' ' escritas aquí mismo.
		$result = $wpdb->query( "UPDATE {$docs} SET sku_norm = REPLACE(REPLACE(sku, '-', ''), ' ', '') WHERE sku_norm = '' AND sku <> ''" );

		return false !== $result && '' === (string) ( $wpdb->last_error ?? '' );
	}

	/**
	 * Foto exacta de las tres familias swappeables en una sola consulta.
	 *
	 * Las claves internas son lógicas (`docs`, `tokens`, `facets`) y cada valor
	 * indica si la tabla concreta existe. Un error de metadata produce todos los
	 * valores en false; classify_topology() lo trata como ambiguo y bloquea toda
	 * mutación. No se usa SHOW TABLES LIKE porque `_` en el prefijo es wildcard.
	 *
	 * @return array{live: array<string, bool>, new: array<string, bool>, old: array<string, bool>}
	 */
	public static function table_topology(): array {
		global $wpdb;
		self::$topology_read_failed = false;

		$topology = array(
			'live' => array( 'docs' => false, 'tokens' => false, 'facets' => false ),
			'new'  => array( 'docs' => false, 'tokens' => false, 'facets' => false ),
			'old'  => array( 'docs' => false, 'tokens' => false, 'facets' => false ),
		);

		$lookup   = array();
		$suffixes = array(
			'live' => '',
			'new'  => self::SHADOW_SUFFIX,
			'old'  => self::OLD_SUFFIX,
		);
		foreach ( $suffixes as $family => $suffix ) {
			foreach ( self::swappable_table_map() as $logical => $table ) {
				$lookup[ $table . $suffix ] = array( $family, $logical );
			}
		}

		$placeholders = implode( ', ', array_fill( 0, count( $lookup ), '%s' ) );
		// `{$placeholders}` son los `%s` que genera esta misma línea, uno por
		// tabla esperada, y `array_keys( $lookup )` los rellena: prepare con
		// número variable de placeholders, no interpolación de datos.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- los marcadores viven dentro de {$placeholders}, generado en la línea 1676, donde el sniff no los ve.
		$query        = $wpdb->prepare(
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
			array_keys( $lookup )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- `$query` ES el resultado del prepare de arriba.
		$present          = $wpdb->get_col( $query );

		if ( ! is_array( $present ) || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			self::$topology_read_failed = true;
			return $topology;
		}

		foreach ( $present as $table ) {
			$table = (string) $table;
			if ( isset( $lookup[ $table ] ) ) {
				list( $family, $logical )       = $lookup[ $table ];
				$topology[ $family ][ $logical ] = true;
			}
		}

		return $topology;
	}

	/**
	 * Lee y clasifica una única foto, conservando error de metadata separado de
	 * una topología realmente ausente o parcial.
	 *
	 * @return array{valid: bool, topology: array{live: array<string, bool>, new: array<string, bool>, old: array<string, bool>}, classification: string}
	 */
	public static function inspect_table_topology(): array {
		$topology = self::table_topology();
		$valid    = ! self::$topology_read_failed;

		return array(
			'valid'          => $valid,
			'topology'       => $topology,
			'classification' => $valid ? self::classify_topology_state( $topology ) : self::TOPOLOGY_AMBIGUOUS,
		);
	}

	/**
	 * Clasifica sólo las tres topologías recuperables del protocolo v2.
	 *
	 * Cualquier set parcial, `new + old`, falta de live o fallo de metadata es
	 * ambiguo. Los callers deben persistir BLOCKED y pedir reparación explícita;
	 * esta clase nunca intenta adivinar qué copia contiene el índice correcto.
	 *
	 * @return string Una constante TOPOLOGY_*.
	 */
	public static function classify_topology(): string {
		return self::classify_topology_state( self::table_topology() );
	}

	/**
	 * Crea las tres tablas `_new` sólo desde una topología limpia.
	 *
	 * No elimina residuos por sí misma. Si dbDelta deja un set parcial devuelve
	 * false; RebuildJob, que conserva el owner PREPARING y el fence, es quien
	 * puede retirarlo de inmediato o dejarlo recuperable para el watchdog.
	 *
	 * @return bool True sólo si termina exactamente en live+new, sin old.
	 */
	public static function create_shadow_tables(): bool {
		if ( self::TOPOLOGY_CLEAN_PRE !== self::classify_topology() ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::schema_sql( self::SHADOW_SUFFIX ) as $sql ) {
			dbDelta( $sql );
		}

		if ( ! self::assert_token_collation( self::tokens_table() . self::SHADOW_SUFFIX )
			|| ! self::shadow_schema_ready() ) {
			return false;
		}

		return self::TOPOLOGY_READY_SWAP === self::classify_topology();
	}

	/**
	 * Ejecuta/reconcilia RENAME sin confundir un ACK o metadata fallidos con una
	 * topología incompatible. El snapshot opcional ya fue validado bajo fence.
	 *
	 * @param array<string, array<string, bool>>|null $topology     Foto pre-RENAME.
	 * @param callable|null                           $assert_owned Comprobación del fence inmediatamente antes/después del DDL.
	 * @return string swapped|retry|invalid.
	 */
	public static function rename_keep_old_result( ?array $topology = null, ?callable $assert_owned = null ): string {
		global $wpdb;
		if ( null === $assert_owned ) {
			return self::RENAME_RESULT_RETRY;
		}

		if ( null === $topology ) {
			$inspection = self::inspect_table_topology();
			if ( ! (bool) $inspection['valid'] ) {
				return self::RENAME_RESULT_RETRY;
			}
			$topology = $inspection['topology'];
		}
		if ( self::TOPOLOGY_READY_SWAP !== self::classify_topology_state( $topology ) ) {
			return self::RENAME_RESULT_INVALID;
		}
		if ( ! self::swappable_schema_ready( '' ) || ! self::shadow_schema_ready() ) {
			return self::RENAME_RESULT_RETRY;
		}

		$pairs = array();
		foreach ( self::swappable_tables() as $table ) {
			$pairs[] = sprintf(
				'%1$s TO %2$s, %3$s TO %1$s',
				self::quote_identifier( $table ),
				self::quote_identifier( $table . self::OLD_SUFFIX ),
				self::quote_identifier( $table . self::SHADOW_SUFFIX )
			);
		}

		if ( ! self::ddl_fence_owned( $assert_owned ) ) {
			return self::RENAME_RESULT_RETRY;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombres internos ya delimitados, sin input externo.
		$wpdb->query( 'RENAME TABLE ' . implode( ', ', $pairs ) );
		if ( ! self::ddl_fence_owned( $assert_owned ) ) {
			return self::RENAME_RESULT_RETRY;
		}
		$inspection = self::inspect_table_topology();
		if ( ! (bool) $inspection['valid'] ) {
			return self::RENAME_RESULT_RETRY;
		}
		$classification = (string) $inspection['classification'];
		if ( self::TOPOLOGY_SWAPPED === $classification ) {
			return self::swappable_schema_ready( '' ) && self::old_schema_ready()
				? self::RENAME_RESULT_SWAPPED
				: self::RENAME_RESULT_RETRY;
		}

		return self::TOPOLOGY_READY_SWAP === $classification
			? self::RENAME_RESULT_RETRY
			: self::RENAME_RESULT_INVALID;
	}

	/**
	 * PREPARING con owner puede retirar un subconjunto `_new` nunca publicable.
	 *
	 * @return bool True sólo si converge a clean_pre.
	 */
	public static function recover_owned_partial_shadow( ?callable $assert_owned = null ): bool {
		if ( null === $assert_owned ) {
			global $wpdb;
			if ( ! isset( $wpdb ) ) {
				return false;
			}
			$fence = new WriteFence( $wpdb );
			if ( ! $fence->acquire( 0 ) ) {
				return false;
			}
			try {
				return self::recover_owned_partial_shadow( array( $fence, 'assert_owned' ) );
			} finally {
				$fence->release();
			}
		}
		return self::PARTIAL_RECOVERY_RECOVERED === self::recover_owned_partial_shadow_result( null, $assert_owned );
	}

	/**
	 * Variante tri-state para que RebuildJob no convierta un blip de metadata en
	 * BLOCKED después de un DROP total o parcial.
	 *
	 * @param array<string, array<string, bool>>|null $topology     Foto ya validada bajo fence.
	 * @param callable|null                           $assert_owned Comprobación del fence pegada al DROP.
	 * @return string recovered|retry|invalid.
	 */
	public static function recover_owned_partial_shadow_result( ?array $topology = null, ?callable $assert_owned = null ): string {
		return self::recover_owned_partial_family_result( 'new', self::SHADOW_SUFFIX, $topology, $assert_owned );
	}

	/**
	 * CLEANUP ya ACKeado puede completar un DROP parcial de `_old` inequívoco.
	 *
	 * @return bool True sólo si converge a clean_pre.
	 */
	public static function recover_owned_partial_old( ?callable $assert_owned = null ): bool {
		if ( null === $assert_owned ) {
			global $wpdb;
			if ( ! isset( $wpdb ) ) {
				return false;
			}
			$fence = new WriteFence( $wpdb );
			if ( ! $fence->acquire( 0 ) ) {
				return false;
			}
			try {
				return self::recover_owned_partial_old( array( $fence, 'assert_owned' ) );
			} finally {
				$fence->release();
			}
		}
		return self::PARTIAL_RECOVERY_RECOVERED === self::recover_owned_partial_old_result( null, $assert_owned );
	}

	/**
	 * Variante tri-state del cleanup parcial `_old`.
	 *
	 * @param array<string, array<string, bool>>|null $topology     Foto ya validada bajo fence.
	 * @param callable|null                           $assert_owned Comprobación del fence pegada al DROP.
	 * @return string recovered|retry|invalid.
	 */
	public static function recover_owned_partial_old_result( ?array $topology = null, ?callable $assert_owned = null ): string {
		return self::recover_owned_partial_family_result( 'old', self::OLD_SUFFIX, $topology, $assert_owned );
	}

	/**
	 * @param array<string, array<string, bool>> $topology Foto exacta.
	 * @param string                            $family   new|old.
	 * @return bool
	 */
	public static function is_owned_partial_family( array $topology, string $family ): bool {
		if ( ! in_array( $family, array( 'new', 'old' ), true )
			|| ! isset( $topology['live'], $topology['new'], $topology['old'] )
			|| in_array( false, $topology['live'], true ) ) {
			return false;
		}
		$other = 'new' === $family ? 'old' : 'new';
		return ! in_array( true, $topology[ $other ], true )
			&& in_array( true, $topology[ $family ], true )
			&& in_array( false, $topology[ $family ], true );
	}

	/**
	 * Aborta una sombra sólo cuando el set live+new es exacto.
	 *
	 * @return bool True si ya estaba limpio o la limpieza quedó verificada.
	 */
	public static function drop_shadow_tables( ?callable $assert_owned = null ): bool {
		if ( null === $assert_owned ) {
			global $wpdb;
			if ( ! isset( $wpdb ) ) {
				return false;
			}
			$fence = new WriteFence( $wpdb );
			if ( ! $fence->acquire( 0 ) ) {
				return false;
			}
			try {
				return self::drop_shadow_tables( array( $fence, 'assert_owned' ) );
			} finally {
				$fence->release();
			}
		}
		$classification = self::classify_topology();
		if ( self::TOPOLOGY_CLEAN_PRE === $classification ) {
			return true;
		}
		if ( self::TOPOLOGY_READY_SWAP !== $classification ) {
			return false;
		}

		if ( ! self::drop_swappable_set( self::SHADOW_SUFFIX, $assert_owned ) ) {
			return false;
		}

		return self::TOPOLOGY_CLEAN_PRE === self::classify_topology();
	}

	/**
	 * Elimina `_old` sólo después de una publicación exacta y verificable.
	 *
	 * @return bool True si ya estaba limpio o la limpieza quedó verificada.
	 */
	public static function cleanup_old( ?callable $assert_owned = null ): bool {
		if ( null === $assert_owned ) {
			global $wpdb;
			if ( ! isset( $wpdb ) ) {
				return false;
			}
			$fence = new WriteFence( $wpdb );
			if ( ! $fence->acquire( 0 ) ) {
				return false;
			}
			try {
				return self::cleanup_old( array( $fence, 'assert_owned' ) );
			} finally {
				$fence->release();
			}
		}
		$classification = self::classify_topology();
		if ( self::TOPOLOGY_CLEAN_PRE === $classification ) {
			return true;
		}
		if ( self::TOPOLOGY_SWAPPED !== $classification
			|| ! self::swappable_schema_ready( '' )
			|| ! self::old_schema_ready() ) {
			return false;
		}

		if ( ! self::drop_swappable_set( self::OLD_SUFFIX, $assert_owned ) ) {
			return false;
		}

		return self::TOPOLOGY_CLEAN_PRE === self::classify_topology();
	}

	/**
	 * Drop completo (uninstall/teardown manual). NO se llama en deactivation.
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;
		$live = array_merge(
			self::swappable_tables(),
			array( self::qlog_table(), self::work_table() )
		);
		foreach ( $live as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} recorre self::swappable_tables() más qlog_table() y work_table(), todas $wpdb->prefix + literal fijo; la sentencia no lleva ningún valor que preparar.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		// Uninstall es la única frontera donde corresponde ignorar topología:
		// el objetivo explícito es eliminar TODO, incluso residuos históricos.
		self::drop_suffixed_unchecked( self::SHADOW_SUFFIX );
		self::drop_suffixed_unchecked( self::OLD_SUFFIX );
		delete_option( self::OPTION_KEY );
		delete_option( self::OPTION_SCHEMA_SEAL );
	}

	/**
	 * SQL de las 5 tablas vivas. El sufijo genera sólo el set swappeable.
	 *
	 * @param string $suffix Sufijo de tabla ('' = vivas, '_new' = sombra).
	 * @return array<int, string>
	 */
	private static function schema_sql( string $suffix = '' ): array {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$docs    = self::docs_table() . $suffix;
		$tokens  = self::tokens_table() . $suffix;
		$facets  = self::facets_table() . $suffix;
		$qlog    = self::qlog_table() . $suffix;
		$work    = self::work_table();

		$sql = array();

		// tomo SMALLINT: tope 65k tomos; One Piece va en 107 (padding a 3
		// dígitos ya resuelto en migrations). stock_rank: 0 instock /
		// 1 onbackorder (preventa) / 2 outofstock — ordenable ASC en ranking.
		// url/thumb/price_html materializados para que el autocomplete arme la
		// tarjeta SIN hidratar WC_Product en cada request (query de índice puro
		// ~10ms vs ~300ms hidratando 7 productos). Se mantienen frescos por el
		// mismo reindex incremental que ya escucha precio/stock/oferta.
		// price_html VARCHAR(1000): holgura para marcado HTML enriquecido con accesibilidad y descuentos.
		// sku_norm + idx_sku_norm: preserva la normalización de códigos comerciales e ISBN sin guiones,
		// asegurando que las búsquedas numéricas continuas resuelvan contra referencias con o sin separadores.
		$sql[] = "CREATE TABLE {$docs} (
			post_id BIGINT(20) UNSIGNED NOT NULL,
			title TEXT NOT NULL,
			title_norm VARCHAR(255) NOT NULL DEFAULT '',
			serie_norm VARCHAR(255) NOT NULL DEFAULT '',
			autores_norm VARCHAR(255) NOT NULL DEFAULT '',
			sku VARCHAR(64) NOT NULL DEFAULT '',
			sku_norm VARCHAR(64) NOT NULL DEFAULT '',
			tomo SMALLINT UNSIGNED NULL DEFAULT NULL,
			stock_rank TINYINT UNSIGNED NOT NULL DEFAULT 2,
			menu_order INT(11) NOT NULL DEFAULT 0,
			price_effective DECIMAL(12,2) NOT NULL DEFAULT 0,
			visible TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
			url VARCHAR(255) NOT NULL DEFAULT '',
			thumb_url VARCHAR(255) NOT NULL DEFAULT '',
			price_html VARCHAR(1000) NOT NULL DEFAULT '',
			indexed_at DATETIME NOT NULL,
			PRIMARY KEY  (post_id),
			KEY idx_visible_stock (visible, stock_rank),
			KEY idx_sku (sku),
			KEY idx_sku_norm (sku_norm)
		) ENGINE=InnoDB {$charset};";

		// token: collation binaria explícita utf8mb4_bin para evitar colisiones diacríticas en la PK.
		// idx_field_token: acelera búsquedas de vocabulario acotadas por campo en fuzzy matching.
		// idx_post_token: índice de cobertura (post_id, token) para resolver intersecciones de múltiples términos.
		$sql[] = "CREATE TABLE {$tokens} (
			token VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			field TINYINT UNSIGNED NOT NULL,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			PRIMARY KEY  (token, field, post_id),
			KEY idx_post_token (post_id, token),
			KEY idx_field_token (field, token)
		) ENGINE=InnoDB {$charset};";

		// value VARCHAR(191): slug de término o valor computado (límite seguro de índice en utf8mb4).
		// value_num: facetas numéricas ('precio' y 'novedad') con rangos calculados en tiempo de consulta.
		// idx_facet_num: índice de rango para filtros numéricos dinámicos.
		$sql[] = "CREATE TABLE {$facets} (
			facet VARCHAR(32) NOT NULL,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			value VARCHAR(191) NOT NULL,
			label VARCHAR(191) NOT NULL DEFAULT '',
			value_num DECIMAL(12,2) NULL DEFAULT NULL,
			term_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			parent_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY  (facet, post_id, value),
			KEY idx_facet_value (facet, value),
			KEY idx_facet_num (facet, value_num),
			KEY idx_post (post_id)
		) ENGINE=InnoDB {$charset};";

		// Registro agregado de demanda (qlog): no participa del swap multi-tabla al ser bitácora transaccional viva.
		if ( '' === $suffix ) {
			$sql[] = "CREATE TABLE {$qlog} (
				day DATE NOT NULL,
				query_norm VARCHAR(191) NOT NULL,
				source VARCHAR(16) NOT NULL DEFAULT 'search',
				hits INT(11) UNSIGNED NOT NULL DEFAULT 0,
				searches INT(11) UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (day, query_norm, source)
			) ENGINE=InnoDB {$charset};";

			// Outbox/manifest durable único. No participa del swap: live guarda la
			// revisión deseada y baseline/repair guardan la deuda de cada run. La PK
			// técnica sigue la convención WordPress; la identidad funcional está en
			// (scope, run_id, product_id). ENGINE explícito porque los ACK/leases y
			// la escritura del índice deben compartir transacciones InnoDB.
			$sql[] = "CREATE TABLE {$work} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				scope VARCHAR(16) NOT NULL,
				run_id VARCHAR(64) NOT NULL DEFAULT '',
				product_id BIGINT(20) UNSIGNED NOT NULL,
				wanted_seq BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				acked_seq BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				outcome VARCHAR(16) NOT NULL DEFAULT '',
				status VARCHAR(16) NOT NULL DEFAULT 'pending',
				available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				lease_token VARCHAR(64) NOT NULL DEFAULT '',
				lease_seq BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				lease_until DATETIME NULL DEFAULT NULL,
				attempts INT(10) UNSIGNED NOT NULL DEFAULT 0,
				last_error TEXT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_scope_run_product (scope, run_id, product_id),
				KEY claim (status, available_at, lease_until),
				KEY run_outstanding (run_id, scope, status)
			) ENGINE=InnoDB {$charset};";

			$requests = self::requests_table();
			$sql[] = "CREATE TABLE {$requests} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				product_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				query_norm VARCHAR(191) NOT NULL DEFAULT '',
				title VARCHAR(255) NOT NULL DEFAULT '',
				editorial VARCHAR(100) NOT NULL DEFAULT '',
				isbn VARCHAR(32) NOT NULL DEFAULT '',
				status_intended VARCHAR(20) NOT NULL DEFAULT 'a_pedido',
				customer_name VARCHAR(100) NOT NULL DEFAULT '',
				customer_contact VARCHAR(100) NOT NULL DEFAULT '',
				contact_type VARCHAR(10) NOT NULL DEFAULT 'email',
				user_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				notes TEXT NULL,
				ip_hash CHAR(64) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY idx_product_id (product_id),
				KEY idx_query_norm (query_norm),
				KEY idx_status (status),
				KEY idx_created (created_at),
				KEY idx_editorial (editorial),
				KEY idx_rl_ip (ip_hash, created_at)
			) ENGINE=InnoDB {$charset};";
		}

		return $sql;
	}

	/**
	 * Asegura la collation binaria (`utf8mb4_bin`) de la columna `token` post-dbDelta.
	 * Aplica un MODIFY idempotente para garantizar que comparaciones de tokens
	 * preserven distinción estricta de mayúsculas/minúsculas y caracteres exactos.
	 *
	 * @param string $table Tabla de tokens (viva o sombra).
	 * @return bool True si la columna quedó (o ya estaba) en utf8mb4_bin.
	 */
	private static function assert_token_collation( string $table ): bool {
		global $wpdb;

		$column = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COLLATION_NAME FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				'token'
			)
		);

		if ( $column && 'utf8mb4_bin' !== $column->COLLATION_NAME ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} es el parámetro tipado de este método privado, y sus dos únicos llamadores le pasan self::tokens_table() con o sin sufijo de sombra; el resto del ALTER son constantes escritas aquí mismo.
			$altered = $wpdb->query( "ALTER TABLE {$table} MODIFY token VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL" );
			return false !== $altered;
		}

		return null !== $column;
	}

	/**
	 * Sustituye `idx_post (post_id)` por `idx_post_token (post_id, token)`.
	 *
	 * dbDelta agrega índices pero NUNCA borra el que sobra, así que sin este
	 * paso la tabla se quedaría con los dos y pagaría escritura doble. Las dos
	 * operaciones van en UNA sola sentencia a propósito: cada ALTER reconstruye
	 * la tabla entera (142k filas), y en dos sentencias se reconstruye dos veces.
	 *
	 * Idempotente por lectura de metadata: si el índice nuevo ya está y el viejo
	 * no, no toca nada. Sólo se aplica a la tabla VIVA — las sombras nacen ya con
	 * el schema nuevo.
	 *
	 * @param string $table Tabla de tokens.
	 * @return bool True si la tabla quedó (o ya estaba) con el índice nuevo.
	 */
	private static function migrate_tokens_post_index( string $table ): bool {
		global $wpdb;

		$indexes = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);
		if ( ! is_array( $indexes ) ) {
			return false;
		}

		$has_old = in_array( 'idx_post', $indexes, true );
		$has_new = in_array( 'idx_post_token', $indexes, true );

		if ( ! $has_old ) {
			return $has_new;
		}

		$statement = $has_new
			? "ALTER TABLE {$table} DROP INDEX idx_post"
			: "ALTER TABLE {$table} DROP INDEX idx_post, ADD KEY idx_post_token (post_id, token)";

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL sobre una tabla propia, sin un solo dato de entrada.
		$altered = $wpdb->query( $statement );

		return false !== $altered && '' === (string) ( $wpdb->last_error ?? '' );
	}

	/**
	 * Collation efectiva de la columna `token`, o cadena vacía si no existe.
	 * Utilizado en diagnósticos de salud del esquema.
	 *
	 * @return string
	 */
	public static function token_collation(): string {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COLLATION_NAME FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				self::tokens_table(),
				'token'
			)
		);

		return $row ? (string) $row->COLLATION_NAME : '';
	}

	/**
	 * Verifica un set swappable completo con el mismo contrato físico.
	 *
	 * @param string $suffix '', SHADOW_SUFFIX u OLD_SUFFIX.
	 * @return bool
	 */
	private static function swappable_schema_ready( string $suffix ): bool {
		$tables = self::swappable_table_map();
		foreach ( self::swappable_table_contracts() as $logical => $contract ) {
			if ( ! self::table_matches_contract( $tables[ $logical ] . $suffix, $contract ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * dbDelta no corrige de forma confiable el engine de una tabla existente.
	 *
	 * @param string $table Tabla propia bajo locks de installer.
	 * @return bool
	 */
	private static function ensure_innodb( string $table ): bool {
		global $wpdb;

		$wpdb->last_error = '';
		$engine           = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);
		if ( '' !== (string) ( $wpdb->last_error ?? '' ) || '' === (string) $engine ) {
			return false;
		}
		if ( 'INNODB' !== strtoupper( (string) $engine ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identificador interno delimitado.
			if ( false === $wpdb->query( 'ALTER TABLE ' . self::quote_identifier( $table ) . ' ENGINE=InnoDB' ) ) {
				return false;
			}
		}

		$wpdb->last_error = '';
		$verified          = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);
		return '' === (string) ( $wpdb->last_error ?? '' ) && 'INNODB' === strtoupper( (string) $verified );
	}

	/**
	 * Sella engine, columnas y todos los índices contra information_schema.
	 *
	 * @param string               $table    Tabla concreta.
	 * @param array<string, mixed> $contract Contrato esperado.
	 * @return bool
	 */
	private static function table_matches_contract( string $table, array $contract ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			self::$schema_read_failed = true;
			return false;
		}

		$wpdb->last_error = '';
		$engine           = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);
		if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			self::$schema_read_failed = true;
			return false;
		}
		if ( 'INNODB' !== strtoupper( (string) $engine ) ) {
			return false;
		}

		$wpdb->last_error = '';
		$column_rows      = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type,
					IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default,
					EXTRA AS extra, COLLATION_NAME AS collation_name
				FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
				ORDER BY ORDINAL_POSITION',
				$table
			)
		);
		$expected_columns = (array) ( $contract['columns'] ?? array() );
		if ( '' !== (string) ( $wpdb->last_error ?? '' ) || ! is_array( $column_rows ) ) {
			self::$schema_read_failed = true;
			return false;
		}
		if ( count( $expected_columns ) !== count( $column_rows ) ) {
			return false;
		}

		foreach ( array_values( $expected_columns ) as $offset => $expected ) {
			$row = $column_rows[ $offset ] ?? null;
			if ( null === $row
				|| (string) ( $expected['name'] ?? '' ) !== (string) self::metadata_value( $row, 'column_name' )
				|| self::normalise_column_type( (string) ( $expected['type'] ?? '' ) ) !== self::normalise_column_type( (string) self::metadata_value( $row, 'column_type' ) )
				|| (bool) ( $expected['nullable'] ?? false ) !== ( 'YES' === strtoupper( (string) self::metadata_value( $row, 'is_nullable' ) ) )
				|| self::normalise_column_default( $expected['default'] ?? null ) !== self::normalise_column_default( self::metadata_value( $row, 'column_default' ) )
				|| self::normalise_extra( (string) ( $expected['extra'] ?? '' ) ) !== self::normalise_extra( (string) self::metadata_value( $row, 'extra' ) ) ) {
				return false;
			}

			$collation = $expected['collation'] ?? null;
			if ( null !== $collation && strtolower( (string) $collation ) !== strtolower( (string) self::metadata_value( $row, 'collation_name' ) ) ) {
				return false;
			}
		}

		$wpdb->last_error = '';
		$index_rows       = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique,
					SEQ_IN_INDEX AS seq_in_index, COLUMN_NAME AS column_name,
					SUB_PART AS sub_part, INDEX_TYPE AS index_type
				FROM information_schema.STATISTICS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
				ORDER BY INDEX_NAME, SEQ_IN_INDEX',
				$table
			)
		);
		if ( '' !== (string) ( $wpdb->last_error ?? '' ) || ! is_array( $index_rows ) ) {
			self::$schema_read_failed = true;
			return false;
		}

		$actual_indexes = array();
		foreach ( $index_rows as $row ) {
			$name = (string) self::metadata_value( $row, 'index_name' );
			if ( '' === $name || 'BTREE' !== strtoupper( (string) self::metadata_value( $row, 'index_type' ) ) ) {
				return false;
			}
			if ( ! isset( $actual_indexes[ $name ] ) ) {
				$actual_indexes[ $name ] = array(
					'unique'  => 0 === (int) self::metadata_value( $row, 'non_unique' ),
					'columns' => array(),
				);
			}
			$actual_indexes[ $name ]['columns'][ (int) self::metadata_value( $row, 'seq_in_index' ) ] = array(
				(string) self::metadata_value( $row, 'column_name' ),
				null === self::metadata_value( $row, 'sub_part' ) ? null : (int) self::metadata_value( $row, 'sub_part' ),
			);
		}
		foreach ( $actual_indexes as &$index ) {
			ksort( $index['columns'] );
			$index['columns'] = array_values( $index['columns'] );
		}
		unset( $index );

		$expected_indexes = (array) ( $contract['indexes'] ?? array() );
		ksort( $actual_indexes );
		ksort( $expected_indexes );

		return $expected_indexes === $actual_indexes;
	}

	/**
	 * @param object|array<string, mixed> $row Metadata row.
	 * @param string                      $key Alias lowercase.
	 * @return mixed
	 */
	private static function metadata_value( $row, string $key ) {
		if ( is_array( $row ) ) {
			return $row[ $key ] ?? $row[ strtoupper( $key ) ] ?? null;
		}
		return $row->{$key} ?? $row->{strtoupper( $key )} ?? null;
	}

	/**
	 * Ignora sólo display widths de enteros, removidos por MySQL 8.
	 *
	 * @param string $type COLUMN_TYPE.
	 * @return string
	 */
	private static function normalise_column_type( string $type ): string {
		$type = strtolower( preg_replace( '/\s+/', ' ', trim( $type ) ) ?? '' );
		return (string) preg_replace( '/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type );
	}

	/**
	 * @param mixed $default COLUMN_DEFAULT.
	 * @return string|null
	 */
	private static function normalise_column_default( $default ): ?string {
		if ( null === $default ) {
			return null;
		}
		$value = trim( (string) $default );
		if ( 'null' === strtolower( $value ) ) {
			return null; // MariaDB expone DEFAULT NULL como el literal `NULL`.
		}
		if ( strlen( $value ) >= 2 && "'" === $value[0] && "'" === $value[ strlen( $value ) - 1 ] ) {
			$value = str_replace( "''", "'", substr( $value, 1, -1 ) );
		}
		$value = strtolower( $value );
		if ( is_numeric( $value ) ) {
			$value = rtrim( rtrim( sprintf( '%.10F', (float) $value ), '0' ), '.' );
			return '-0' === $value ? '0' : $value;
		}
		return 'current_timestamp()' === $value ? 'current_timestamp' : $value;
	}

	/**
	 * @param string $extra EXTRA.
	 * @return string
	 */
	private static function normalise_extra( string $extra ): string {
		$extra = strtolower( str_replace( 'default_generated', '', $extra ) );
		$extra = str_replace( 'current_timestamp()', 'current_timestamp', $extra );
		return trim( preg_replace( '/\s+/', ' ', $extra ) ?? '' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function swappable_table_contracts(): array {
		return array(
			'docs' => array(
				'columns' => self::column_contracts(
					array(
						array( 'post_id', 'bigint unsigned', false, null ),
						array( 'title', 'text', false, null ),
						array( 'title_norm', 'varchar(255)', false, '' ),
						array( 'serie_norm', 'varchar(255)', false, '' ),
						array( 'autores_norm', 'varchar(255)', false, '' ),
						array( 'sku', 'varchar(64)', false, '' ),
						array( 'sku_norm', 'varchar(64)', false, '' ),
						array( 'tomo', 'smallint unsigned', true, null ),
						array( 'stock_rank', 'tinyint unsigned', false, '2' ),
						array( 'menu_order', 'int', false, '0' ),
						array( 'price_effective', 'decimal(12,2)', false, '0' ),
						array( 'visible', 'tinyint unsigned', false, '1' ),
						array( 'url', 'varchar(255)', false, '' ),
						array( 'thumb_url', 'varchar(255)', false, '' ),
						array( 'price_html', 'varchar(1000)', false, '' ),
						array( 'indexed_at', 'datetime', false, null ),
					)
				),
				'indexes' => self::index_contracts(
					array(
						'PRIMARY'          => array( true, array( 'post_id' ) ),
						'idx_sku'          => array( false, array( 'sku' ) ),
						'idx_sku_norm'     => array( false, array( 'sku_norm' ) ),
						'idx_visible_stock' => array( false, array( 'visible', 'stock_rank' ) ),
					)
				),
			),
			'tokens' => array(
				'columns' => self::column_contracts(
					array(
						array( 'token', 'varchar(40)', false, null, '', 'utf8mb4_bin' ),
						array( 'field', 'tinyint unsigned', false, null ),
						array( 'post_id', 'bigint unsigned', false, null ),
					)
				),
				'indexes' => self::index_contracts(
					array(
						'PRIMARY'         => array( true, array( 'token', 'field', 'post_id' ) ),
						'idx_field_token' => array( false, array( 'field', 'token' ) ),
						'idx_post_token'  => array( false, array( 'post_id', 'token' ) ),
					)
				),
			),
			'facets' => array(
				'columns' => self::column_contracts(
					array(
						array( 'facet', 'varchar(32)', false, null ),
						array( 'post_id', 'bigint unsigned', false, null ),
						array( 'value', 'varchar(191)', false, null ),
						array( 'label', 'varchar(191)', false, '' ),
						array( 'value_num', 'decimal(12,2)', true, null ),
						array( 'term_id', 'bigint unsigned', true, null ),
						array( 'parent_id', 'bigint unsigned', true, null ),
					)
				),
				'indexes' => self::index_contracts(
					array(
						'PRIMARY'         => array( true, array( 'facet', 'post_id', 'value' ) ),
						'idx_facet_num'   => array( false, array( 'facet', 'value_num' ) ),
						'idx_facet_value' => array( false, array( 'facet', 'value' ) ),
						'idx_post'        => array( false, array( 'post_id' ) ),
					)
				),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function qlog_table_contract(): array {
		return array(
			'columns' => self::column_contracts(
				array(
					array( 'day', 'date', false, null ),
					array( 'query_norm', 'varchar(191)', false, null ),
					array( 'source', 'varchar(16)', false, 'search' ),
					array( 'hits', 'int unsigned', false, '0' ),
					array( 'searches', 'int unsigned', false, '0' ),
				)
			),
			'indexes' => self::index_contracts(
				array( 'PRIMARY' => array( true, array( 'day', 'query_norm', 'source' ) ) )
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function work_table_contract(): array {
		return array(
			'columns' => self::column_contracts(
				array(
					array( 'id', 'bigint unsigned', false, null, 'auto_increment' ),
					array( 'scope', 'varchar(16)', false, null ),
					array( 'run_id', 'varchar(64)', false, '' ),
					array( 'product_id', 'bigint unsigned', false, null ),
					array( 'wanted_seq', 'bigint unsigned', false, '0' ),
					array( 'acked_seq', 'bigint unsigned', false, '0' ),
					array( 'outcome', 'varchar(16)', false, '' ),
					array( 'status', 'varchar(16)', false, 'pending' ),
					array( 'available_at', 'datetime', false, 'current_timestamp' ),
					array( 'lease_token', 'varchar(64)', false, '' ),
					array( 'lease_seq', 'bigint unsigned', false, '0' ),
					array( 'lease_until', 'datetime', true, null ),
					array( 'attempts', 'int unsigned', false, '0' ),
					array( 'last_error', 'text', true, null ),
					array( 'created_at', 'datetime', false, 'current_timestamp' ),
					array( 'updated_at', 'datetime', false, 'current_timestamp', 'on update current_timestamp' ),
				)
			),
			'indexes' => self::index_contracts(
				array(
					'PRIMARY'              => array( true, array( 'id' ) ),
					'claim'                => array( false, array( 'status', 'available_at', 'lease_until' ) ),
					'run_outstanding'      => array( false, array( 'run_id', 'scope', 'status' ) ),
					'uq_scope_run_product' => array( true, array( 'scope', 'run_id', 'product_id' ) ),
				)
			),
		);
	}

	/**
	 * @param array<int, array<int, mixed>> $rows Tuplas de columnas.
	 * @return array<string, array<string, mixed>>
	 */
	private static function column_contracts( array $rows ): array {
		$columns = array();
		foreach ( $rows as $row ) {
			$columns[ (string) $row[0] ] = array(
				'name'      => (string) $row[0],
				'type'      => (string) $row[1],
				'nullable'  => (bool) $row[2],
				'default'   => $row[3] ?? null,
				'extra'     => (string) ( $row[4] ?? '' ),
				'collation' => $row[5] ?? null,
			);
		}
		return $columns;
	}

	/**
	 * @param array<string, array{0: bool, 1: array<int, string>}> $rows Tuplas de índices.
	 * @return array<string, array{unique: bool, columns: array<int, array{0: string, 1: null}>}>
	 */
	private static function index_contracts( array $rows ): array {
		$indexes = array();
		foreach ( $rows as $name => $row ) {
			$indexes[ $name ] = array(
				'unique'  => (bool) $row[0],
				'columns' => array_map(
					static fn( string $column ): array => array( $column, null ),
					$row[1]
				),
			);
		}
		return $indexes;
	}

	/**
	 * Mapa estable de nombres lógicos a tablas vivas swappeables.
	 *
	 * @return array{docs: string, tokens: string, facets: string}
	 */
	private static function swappable_table_map(): array {
		return array(
			'docs'   => self::docs_table(),
			'tokens' => self::tokens_table(),
			'facets' => self::facets_table(),
		);
	}

	/**
	 * Clasificador puro de una foto ya tomada.
	 *
	 * @param array<string, array<string, bool>> $topology Topología exacta.
	 * @return string Una constante TOPOLOGY_*.
	 */
	private static function classify_topology_state( array $topology ): string {
		$families = array( 'live', 'new', 'old' );
		foreach ( $families as $family ) {
			if ( ! isset( $topology[ $family ] ) || 3 !== count( $topology[ $family ] ) ) {
				return self::TOPOLOGY_AMBIGUOUS;
			}
		}

		$live_all = ! in_array( false, $topology['live'], true );
		$new_all  = ! in_array( false, $topology['new'], true );
		$old_all  = ! in_array( false, $topology['old'], true );
		$new_none = ! in_array( true, $topology['new'], true );
		$old_none = ! in_array( true, $topology['old'], true );

		if ( $live_all && $new_none && $old_none ) {
			return self::TOPOLOGY_CLEAN_PRE;
		}
		if ( $live_all && $new_all && $old_none ) {
			return self::TOPOLOGY_READY_SWAP;
		}
		if ( $live_all && $new_none && $old_all ) {
			return self::TOPOLOGY_SWAPPED;
		}

		return self::TOPOLOGY_AMBIGUOUS;
	}

	/**
	 * DROP de un set swappeable completo en una sola sentencia.
	 *
	 * El caller ya verificó la topología y opera bajo el fence del protocolo.
	 * No usa IF EXISTS: si la precondición dejó de ser cierta, la operación
	 * debe fallar y no aparentar una limpieza exitosa.
	 *
	 * @param string   $suffix       SHADOW_SUFFIX u OLD_SUFFIX.
	 * @param callable $assert_owned Comprobación de ownership pegada al DDL.
	 * @return bool
	 */
	private static function drop_swappable_set( string $suffix, callable $assert_owned ): bool {
		global $wpdb;

		$tables = array_map(
			static fn( string $table ): string => self::quote_identifier( $table . $suffix ),
			self::swappable_tables()
		);

		if ( ! self::ddl_fence_owned( $assert_owned ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombres internos ya delimitados, sin input externo.
		$result = $wpdb->query( 'DROP TABLE ' . implode( ', ', $tables ) );

		return self::ddl_fence_owned( $assert_owned ) && false !== $result;
	}

	/**
	 * Retira sólo una familia parcial poseída y clasifica el resultado sin
	 * confundir una lectura fallida con una topología destructiva.
	 *
	 * @param string                                  $family       new|old.
	 * @param string                                  $suffix       Sufijo físico.
	 * @param array<string, array<string, bool>>|null $topology     Foto ya validada.
	 * @param callable|null                           $assert_owned Comprobación de ownership pegada al DDL.
	 * @return string recovered|retry|invalid.
	 */
	private static function recover_owned_partial_family_result( string $family, string $suffix, ?array $topology, ?callable $assert_owned ): string {
		if ( null === $assert_owned ) {
			return self::PARTIAL_RECOVERY_RETRY;
		}
		if ( null === $topology ) {
			$inspection = self::inspect_table_topology();
			if ( ! (bool) $inspection['valid'] ) {
				return self::PARTIAL_RECOVERY_RETRY;
			}
			$topology = $inspection['topology'];
		}
		if ( ! self::is_owned_partial_family( $topology, $family ) ) {
			return self::PARTIAL_RECOVERY_INVALID;
		}

		// Aun si MariaDB devuelve false, la reinspection manda: la conexión pudo
		// perder el ACK después de ejecutar todo o parte del DDL.
		self::drop_present_family( $suffix, $topology[ $family ], $assert_owned );
		$inspection = self::inspect_table_topology();
		if ( ! (bool) $inspection['valid'] ) {
			return self::PARTIAL_RECOVERY_RETRY;
		}
		$post = $inspection['topology'];
		if ( self::TOPOLOGY_CLEAN_PRE === (string) $inspection['classification'] ) {
			return self::PARTIAL_RECOVERY_RECOVERED;
		}
		if ( self::is_owned_partial_family( $post, $family ) ) {
			return self::PARTIAL_RECOVERY_RETRY;
		}

		return self::PARTIAL_RECOVERY_INVALID;
	}

	/**
	 * @param string              $suffix       SHADOW_SUFFIX u OLD_SUFFIX.
	 * @param array<string, bool> $presence     Presencia por nombre lógico.
	 * @param callable            $assert_owned Comprobación de ownership pegada al DDL.
	 * @return bool
	 */
	private static function drop_present_family( string $suffix, array $presence, callable $assert_owned ): bool {
		global $wpdb;

		$tables = array();
		foreach ( self::swappable_table_map() as $logical => $table ) {
			if ( (bool) ( $presence[ $logical ] ?? false ) ) {
				$tables[] = self::quote_identifier( $table . $suffix );
			}
		}
		if ( empty( $tables ) ) {
			return false;
		}

		if ( ! self::ddl_fence_owned( $assert_owned ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- subset exacto ya observado bajo fence.
		$result = $wpdb->query( 'DROP TABLE ' . implode( ', ', $tables ) );

		return self::ddl_fence_owned( $assert_owned ) && false !== $result;
	}

	/**
	 * Convierte pérdida/reconexión del named lock en un resultado retryable.
	 *
	 * @param callable $assert_owned WriteFence::assert_owned del caller.
	 * @return bool
	 */
	private static function ddl_fence_owned( callable $assert_owned ): bool {
		try {
			$assert_owned();
			return true;
		} catch ( \Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Limpieza destructiva e incondicional reservada al uninstall.
	 *
	 * Incluye qlog_new/qlog_old históricas creadas por versiones antiguas. Work
	 * no se incluye porque por contrato nunca tiene sufijo.
	 *
	 * @param string $suffix SHADOW_SUFFIX u OLD_SUFFIX.
	 * @return void
	 */
	private static function drop_suffixed_unchecked( string $suffix ): void {
		global $wpdb;

		foreach ( array_merge( self::swappable_tables(), array( self::qlog_table() ) ) as $table ) {
			$table = self::quote_identifier( $table . $suffix );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- uninstall explícito; nombres internos delimitados.
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
		}
	}

	/**
	 * Delimita un identificador interno de tabla.
	 *
	 * @param string $identifier Nombre derivado del prefijo y constantes propias.
	 * @return string
	 */
	private static function quote_identifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}
}
