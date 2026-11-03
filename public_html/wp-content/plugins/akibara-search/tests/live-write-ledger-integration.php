<?php
/**
 * Gate MariaDB del protocolo durable de Akibara Search 0.1.14.
 *
 * El wrapper crea una base clon efimera por modo. Este archivo vuelve a
 * comprobar ambiente, nombre y conexion activa antes de cualquier DROP/DDL.
 * Baseline es un control negativo del ledger read-modify-write anterior;
 * candidate prueba el outbox/manifest v2 y la recuperacion del cutover.
 *
 * @package Akibara\Search\Tests
 */

// WP-CLI `eval-file` ejecuta este fixture dentro de un wrapper propio; una
// declaración strict_types aquí deja de ser la primera sentencia y fatalearía
// antes del primer guard de base efímera.

use Akibara\Business\Modules\TaxonomyDiscount\SearchPriceProvider;
use Akibara\Search\DocumentBuilder;
use Akibara\Search\Arrival;
use Akibara\Search\EditorialDiscountPublication;
use Akibara\Search\Indexer;
use Akibara\Search\Install;
use Akibara\Search\PriceBuckets;
use Akibara\Search\ProductSourceAuthority;
use Akibara\Search\Reconciler;
use Akibara\Search\RebuildJob;
use Akibara\Search\SearchAdminSection;
use Akibara\Search\SemanticPublication;
use Akibara\Search\Synonyms;
use Akibara\Search\WorkRepository;
use Akibara\Search\WriteFence;
use Akibara\Search\Support\RestCache;

$mode     = (string) getenv( 'AKB_ITEST_MODE' );
$database = defined( 'DB_NAME' ) ? (string) DB_NAME : '';

if ( '1' !== (string) getenv( 'AKB_ITEST_EPHEMERAL_DB' ) ) {
	WP_CLI::error( 'Este gate solo puede correr sobre la DB efimera de su wrapper.' );
}
if ( function_exists( 'wp_get_environment_type' ) && 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Este gate exige WP_ENVIRONMENT_TYPE=local.' );
}
if ( ! in_array( $mode, array( 'baseline', 'candidate' ), true ) ) {
	WP_CLI::error( 'AKB_ITEST_MODE debe ser baseline o candidate.' );
}
if ( 1 !== preg_match( '/^akb_search_itest_[0-9]+_[0-9a-f]{12}_' . preg_quote( $mode, '/' ) . '_[12]$/D', $database ) ) {
	WP_CLI::error( 'DB_NAME no es una fixture efimera valida para este modo.' );
}

global $wpdb;

$active_database = (string) $wpdb->get_var( 'SELECT DATABASE()' );
if ( $database !== $active_database ) {
	WP_CLI::error( 'La conexion WordPress no apunta a la fixture declarada.' );
}

$failures = array();
$log      = static function ( string $line ): void {
	WP_CLI::log( $line );
};
$check    = static function ( string $name, bool $ok ) use ( &$failures, $log ): void {
	$log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name );
	if ( ! $ok ) {
		$failures[] = $name;
	}
};
$must     = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$sql_must = static function ( $result, string $message, ?object $connection = null ) use ( $must, $wpdb ): void {
	$connection = $connection ?? $wpdb;
	$detail     = property_exists( $connection, 'last_error' ) ? trim( (string) $connection->last_error ) : '';
	$must( false !== $result, $message . ( '' !== $detail ? ': ' . $detail : '' ) );
};

$quote_identifier = static function ( string $identifier ): string {
	if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $identifier ) ) {
		throw new InvalidArgumentException( 'Identificador SQL inseguro en fixture.' );
	}
	return '`' . $identifier . '`';
};

$table_exists = static function ( object $connection, string $table ): bool {
	$connection->last_error = '';
	$exists                 = $connection->get_var(
		$connection->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		)
	);
	return '' === trim( (string) $connection->last_error ) && 1 === (int) $exists;
};

$delete_search_actions = static function (): void {
	if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler' ) ) {
		return;
	}

	$statuses = array(
		ActionScheduler_Store::STATUS_PENDING,
		ActionScheduler_Store::STATUS_RUNNING,
		ActionScheduler_Store::STATUS_COMPLETE,
		ActionScheduler_Store::STATUS_FAILED,
		ActionScheduler_Store::STATUS_CANCELED,
	);
	$store = ActionScheduler::store();
	foreach ( $statuses as $status ) {
		for ( $page = 0; $page < 100; ++$page ) {
			$ids = as_get_scheduled_actions(
				array(
					'group'    => Indexer::AS_GROUP,
					'status'   => $status,
					'per_page' => 250,
				),
				'ids'
			);
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $action_id ) {
				$store->delete_action( (int) $action_id );
			}
		}
	}
};

$search_action_count = static function (): int {
	if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
		return 0;
	}

	$total = 0;
	foreach (
		array(
			ActionScheduler_Store::STATUS_PENDING,
			ActionScheduler_Store::STATUS_RUNNING,
			ActionScheduler_Store::STATUS_COMPLETE,
			ActionScheduler_Store::STATUS_FAILED,
			ActionScheduler_Store::STATUS_CANCELED,
		) as $status
	) {
		$ids    = as_get_scheduled_actions(
			array(
				'group'    => Indexer::AS_GROUP,
				'status'   => $status,
				'per_page' => 10000,
			),
			'ids'
		);
		$total += is_array( $ids ) ? count( $ids ) : 0;
	}
	return $total;
};

/**
 * Qué acciones quedaron y en qué estado. El contador de arriba dice CUÁNTAS y
 * eso, ante un rojo, no alcanza para saber si sobra una acción o sobra el
 * aserto: sin el hook y el estado hay que reproducir la corrida entera para
 * averiguarlo.
 *
 * @return array<string, int> "hook@estado" => cuántas.
 */
$search_action_detail = static function (): array {
	if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
		return array();
	}

	$detalle = array();
	foreach (
		array(
			ActionScheduler_Store::STATUS_PENDING,
			ActionScheduler_Store::STATUS_RUNNING,
			ActionScheduler_Store::STATUS_COMPLETE,
			ActionScheduler_Store::STATUS_FAILED,
			ActionScheduler_Store::STATUS_CANCELED,
		) as $status
	) {
		$acciones = as_get_scheduled_actions(
			array(
				'group'    => Indexer::AS_GROUP,
				'status'   => $status,
				'per_page' => 10000,
			),
			'OBJECT'
		);
		if ( ! is_array( $acciones ) ) {
			continue;
		}
		foreach ( $acciones as $accion ) {
			$hook             = is_object( $accion ) && method_exists( $accion, 'get_hook' ) ? (string) $accion->get_hook() : '?';
			$clave            = $hook . '@' . $status;
			$detalle[ $clave ] = ( $detalle[ $clave ] ?? 0 ) + 1;
		}
	}

	return $detalle;
};

/**
 * Hace vencer únicamente en la fixture efímera la gracia del pending rebuild.
 *
 * Usa el mismo CAS privado de Install para no convertir el gate en una ruta de
 * escritura alternativa. Sólo cambia not_before: identidad, secuencia, causa,
 * versión y requested_at deben permanecer byte-a-byte equivalentes.
 *
 * @return array{before: array<int, array<string, mixed>>, after: array<int, array<string, mixed>>}
 */
$mature_pending_rebuild = static function () use ( $must ): array {
	$reflector = new ReflectionClass( Install::class );
	$read      = $reflector->getMethod( 'read_option_uncached' );
	$normalise = $reflector->getMethod( 'normalise_rebuild_signal' );
	$envelope  = $reflector->getMethod( 'rebuild_signal_envelope' );
	$replace   = $reflector->getMethod( 'replace_pending_signal' );

	foreach ( array( $read, $normalise, $envelope, $replace ) as $method ) {
		$method->setAccessible( true );
	}

	$raw      = $read->invoke( null, Install::OPTION_PENDING_REBUILD, false );
	$before   = $normalise->invoke( null, $raw );
	$must( ! empty( $before ), 'La fixture no encontró pending rebuild que madurar.' );
	$requests = $before;
	$now      = time();
	foreach ( $requests as &$request ) {
		$request['not_before'] = min( (int) $request['not_before'], $now );
	}
	unset( $request );

	if ( $requests !== $before ) {
		$replacement = $envelope->invoke( null, $requests );
		$must(
			true === $replace->invoke( null, $raw, $replacement ),
			'No se pudo madurar por CAS el pending rebuild efímero.'
		);
	}

	$stored_raw = $read->invoke( null, Install::OPTION_PENDING_REBUILD, false );
	$after      = $normalise->invoke( null, $stored_raw );
	$identity   = static function ( array $queue ): array {
		return array_map(
			static function ( array $request ): array {
				return array_intersect_key(
					$request,
					array_flip( array( 'token', 'seq', 'version', 'reason', 'requested_at' ) )
				);
			},
			$queue
		);
	};
	$must( $identity( $before ) === $identity( $after ), 'Madurar la gracia alteró la identidad durable.' );
	$must( Install::pending_rebuild_is_ready(), 'La gracia efímera no quedó vencida.' );

	return array(
		'before' => $before,
		'after'  => $after,
	);
};

$drop_suffix_sets = static function () use ( $wpdb, $quote_identifier, $sql_must ): void {
	foreach ( Install::swappable_tables() as $table ) {
		foreach ( array( Install::SHADOW_SUFFIX, Install::OLD_SUFFIX ) as $suffix ) {
			$sql_must(
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $quote_identifier( $table . $suffix ) ),
				'No se pudo normalizar una tabla derivada de la fixture'
			);
		}
	}
};

// SÓLO constantes: las options que el protocolo VIGENTE escribe. Hasta 0.1.73
// la lista arrastraba además dos nombres sueltos del protocolo retirado de
// 0.1.14, que ningún fichero del plugin lee ni escribe desde entonces. Su
// `delete_option()` no limpiaba nada de esta fixture efímera y, peor, era la
// única aparición viva de esos nombres en todo el árbol: el barrido de options
// huérfanas los dio por «usados» y la fila quedó en producción [QA ronda 10].
// Regla para quien añada aquí: si no hay una constante del plugin que lo
// nombre, no va — un literal suelto en esta lista esconde una option muerta.
$clear_coordination = static function () use ( $wpdb, $sql_must, $delete_search_actions ): void {
	$delete_search_actions();
	foreach (
		array(
			RebuildJob::OPTION_STATE,
			RebuildJob::OPTION_LAST,
			RebuildJob::OPTION_HISTORY,
			Install::OPTION_PENDING_REBUILD,
		) as $option
	) {
		delete_option( $option );
	}

	if ( Install::work_schema_ready() ) {
		$sql_must( $wpdb->query( 'DELETE FROM ' . Install::work_table() ), 'No se pudo vaciar work en la fixture' );
	}
};

$with_zero_enqueue = static function ( callable $callback ): array {
	$hits         = array();
	$async_filter = static function ( $pre, $hook, $args, $group ) use ( &$hits ) {
		if ( Indexer::AS_GROUP !== (string) $group ) {
			return $pre;
		}
		$hits[] = (string) $hook;
		return 0;
	};
	$single_filter = static function ( $pre, $timestamp, $hook, $args, $group ) use ( &$hits ) {
		unset( $timestamp, $args );
		if ( Indexer::AS_GROUP !== (string) $group ) {
			return $pre;
		}
		$hits[] = (string) $hook;
		return 0;
	};

	add_filter( 'pre_as_enqueue_async_action', $async_filter, PHP_INT_MAX, 6 );
	add_filter( 'pre_as_schedule_single_action', $single_filter, PHP_INT_MAX, 7 );
	try {
		$result = $callback();
	} finally {
		remove_filter( 'pre_as_enqueue_async_action', $async_filter, PHP_INT_MAX );
		remove_filter( 'pre_as_schedule_single_action', $single_filter, PHP_INT_MAX );
	}

	return array(
		'result' => $result,
		'hits'   => $hits,
	);
};

$new_run_id = static function ( string $label ): string {
	return 'gate-' . $label . '-' . substr( hash( 'sha256', $label . '|' . microtime( true ) . '|' . wp_rand() ), 0, 24 );
};

/** Hash lógico que excluye receipts propios del Reconciler y su retención qlog. */
$logical_reconciler_hash = static function () use ( $wpdb, $must ): string {
	$snapshot = array();
	foreach (
		array(
			'docs'   => Install::docs_table(),
			'tokens' => Install::tokens_table(),
			'facets' => Install::facets_table(),
			'work'   => Install::work_table(),
		) as $label => $table
	) {
		$wpdb->last_error = '';
		$rows             = $wpdb->get_results( 'SELECT * FROM ' . $table, ARRAY_A );
		$must( is_array( $rows ) && '' === trim( (string) $wpdb->last_error ), 'No se pudo leer ' . $label . ' para el hash lógico.' );
		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return strcmp( (string) wp_json_encode( $left ), (string) wp_json_encode( $right ) );
			}
		);
		$snapshot[ $label ] = $rows;
	}

	$wpdb->last_error = '';
	$arrival = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
			Arrival::META_ONBACKORDER_AT,
			Arrival::META_FIRST_INSTOCK_AT
		),
		ARRAY_A
	);
	$must( is_array( $arrival ) && '' === trim( (string) $wpdb->last_error ), 'No se pudo leer Arrival para el hash lógico.' );
	usort(
		$arrival,
		static function ( array $left, array $right ): int {
			return strcmp( (string) wp_json_encode( $left ), (string) wp_json_encode( $right ) );
		}
	);
	$snapshot['arrival'] = $arrival;

	$encoded = wp_json_encode( $snapshot );
	$must( is_string( $encoded ) && '' !== $encoded, 'No se pudo serializar el hash lógico del Reconciler.' );

	return hash( 'sha256', $encoded );
};

$global_outstanding_count = static function () use ( $wpdb, $must ): int {
	$wpdb->last_error = '';
	$count            = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . Install::work_table() . ' WHERE status <> %s',
			WorkRepository::STATUS_ACKED
		)
	);
	$must( null !== $count && is_numeric( $count ) && '' === trim( (string) $wpdb->last_error ), 'No se pudo contar deuda global.' );

	return max( 0, (int) $count );
};

$state_for = static function ( string $run_id, string $phase, string $target = '', array $acks = array() ): array {
	return array(
		'protocol'          => 2,
		'run_id'            => $run_id,
		'phase'             => $phase,
		'started_at'        => time(),
		'updated_at'        => time(),
		'heartbeat_at'      => time(),
		'expected'          => 0,
		'schema_seal'       => Install::schema_seal(),
		'shadow_schema_verified' => true,
		'generation_target' => $target,
		'semantic_fingerprint' => '',
		'ack_generation'    => (bool) ( $acks['generation'] ?? false ),
		'ack_rest_purge'    => (bool) ( $acks['rest'] ?? false ),
		'ack_price_buckets' => (bool) ( $acks['price'] ?? false ),
		'ack_semantic_fingerprint' => (bool) ( $acks['semantic'] ?? false ),
		'ack_history'       => (bool) ( $acks['history'] ?? false ),
		'error'             => '',
	);
};

$persist_state = static function ( array $state ) use ( $must ): void {
	$encoded = (string) wp_json_encode( $state );
	update_option( RebuildJob::OPTION_STATE, $encoded, false );
	wp_cache_delete( RebuildJob::OPTION_STATE, 'options' );
	$must( $encoded === (string) get_option( RebuildJob::OPTION_STATE, '' ), 'No se pudo persistir el estado controlado.' );
};

$delete_run_work = static function ( string $run_id ) use ( $wpdb, $sql_must ): void {
	$sql_must(
		$wpdb->delete( Install::work_table(), array( 'run_id' => $run_id ), array( '%s' ) ),
		'No se pudo limpiar work del run controlado'
	);
};

$secondary       = null;
$fence_primary   = null;
$fence_secondary = null;
$runtime_fence   = null;
$price_fence_primary = null;
$price_fence_secondary = null;
$price_crash_connection = null;
$thrown          = null;

try {
	$log( "=== Gate MariaDB Search v2 — {$mode} — {$database} ===" );

	// Normalizacion permitida solo por los guards de DB efimera del encabezado.
	$clear_coordination();
	$drop_suffix_sets();
	delete_option( Install::OPTION_KEY );
	update_option( Install::OPTION_KEY, '1.6.0', false );
	$sql_must(
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $quote_identifier( Install::work_table() ) ),
		'No se pudo retirar la tabla work heredada de la fixture'
	);
	Install::maybe_install();

	$check( 'migracion sella DB_VERSION 1.7.0', Install::DB_VERSION === (string) get_option( Install::OPTION_KEY, '' ) );
	$check( 'schema fisico work 1.7 verificado', Install::work_schema_ready() );
	$check( 'topologia inicial limpia', Install::TOPOLOGY_CLEAN_PRE === Install::classify_topology() );
	$must( Install::work_schema_ready(), 'La migracion no materializo work 1.7.' );
	delete_option( Install::OPTION_PENDING_REBUILD );
	$sql_must( $wpdb->query( 'DELETE FROM ' . Install::work_table() ), 'No se pudo iniciar work vacio' );

	$secondary = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$secondary->set_prefix( $wpdb->prefix );
	$secondary->suppress_errors( true );
	$must( $database === (string) $secondary->get_var( 'SELECT DATABASE()' ), 'La segunda conexion no apunta a la fixture.' );

	$connection_primary   = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
	$connection_secondary = (int) $secondary->get_var( 'SELECT CONNECTION_ID()' );
	$check(
		'dos conexiones MariaDB fisicas e independientes',
		$connection_primary > 0 && $connection_secondary > 0 && $connection_primary !== $connection_secondary
	);

	$repo_primary   = new WorkRepository( $wpdb );
	$repo_secondary = new WorkRepository( $secondary );
	$lock_name      = 'akb-search-gate:' . substr( hash( 'sha256', $database ), 0, 40 );
	$fence_primary  = new WriteFence( $wpdb, $lock_name );
	$fence_secondary = new WriteFence( $secondary, $lock_name );

	$must( $fence_primary->acquire( 0 ), 'La conexion primaria no adquirio el fence.' );
	$check( 'fence excluye a la segunda conexion', ! $fence_secondary->acquire( 0 ) );
	$must( $fence_primary->release(), 'La conexion primaria no libero el fence.' );
	$must( $fence_secondary->acquire( 0 ), 'La segunda conexion no adquirio el fence liberado.' );
	$check( 'fence cambia de propietario despues de release', $fence_secondary->is_owned() );
	$must( $fence_secondary->release(), 'La segunda conexion no libero el fence.' );

	$commit_started = $wpdb->query( 'START TRANSACTION' );
	$commit_result  = false === $commit_started ? false : $wpdb->query( 'COMMIT' );
	$check( 'COMMIT=0 se reconoce como exito MariaDB', 0 === $commit_result );

	if ( 'baseline' === $mode ) {
		$legacy_option = 'akb_search_itest_legacy_ledger';
		delete_option( $legacy_option );
		$must( add_option( $legacy_option, (string) wp_json_encode( array( 'ids' => array() ) ), '', false ), 'No se pudo crear el control legacy.' );

		$sql_read = $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $legacy_option );
		$raw_a    = (string) $wpdb->get_var( $sql_read );
		$raw_b    = (string) $secondary->get_var( $sql_read );
		$state_a  = json_decode( $raw_a, true );
		$state_b  = json_decode( $raw_b, true );
		$must( is_array( $state_a ) && is_array( $state_b ), 'El control legacy no pudo leer ambos snapshots.' );

		$state_a['ids'][] = 700001;
		$state_b['ids'][] = 700002;
		$sql_must(
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
					(string) wp_json_encode( $state_a ),
					$legacy_option
				)
			),
			'Fallo el primer write legacy'
		);
		$sql_must(
			$secondary->query(
				$secondary->prepare(
					"UPDATE {$secondary->options} SET option_value = %s WHERE option_name = %s",
					(string) wp_json_encode( $state_b ),
					$legacy_option
				)
			),
			'Fallo el segundo write legacy',
			$secondary
		);
		wp_cache_delete( $legacy_option, 'options' );
		$legacy_final = json_decode( (string) get_option( $legacy_option, '' ), true );
		$legacy_ids   = is_array( $legacy_final ) ? array_map( 'intval', (array) ( $legacy_final['ids'] ?? array() ) ) : array();
		$check(
			'control negativo reproduce lost update read-modify-write sin CAS',
			! in_array( 700001, $legacy_ids, true ) && in_array( 700002, $legacy_ids, true )
		);

		$capped = array_slice( range( 1, 501 ), -500 );
		$check( 'control negativo reproduce la cota destructiva de 500', 500 === count( $capped ) && 2 === $capped[0] );

		$legacy_wakeup = $with_zero_enqueue(
			static function (): int {
				return (int) as_enqueue_async_action( 'akb_search_itest_legacy_wakeup', array(), Indexer::AS_GROUP );
			}
		);
		$check(
			'control negativo reproduce wake-up=0 sin persistencia propia',
			0 === (int) $legacy_wakeup['result'] && array( 'akb_search_itest_legacy_wakeup' ) === $legacy_wakeup['hits']
		);

		delete_option( $legacy_option );
		wp_cache_delete( $legacy_option, 'options' );
	} else {
		$builder       = new DocumentBuilder();
		$runtime_fence = new WriteFence( $wpdb );
		$indexer       = new Indexer( $builder, $repo_primary, $runtime_fence );
		$rebuild       = new RebuildJob( $indexer, $repo_primary, $runtime_fence );
		// Reproduce la composición productiva: RebuildJob y la publicación
		// editorial comparten exactamente la misma instancia de WriteFence. Crear
		// sólo el RebuildJob de prueba reemplazaría su runtime estático y haría que
		// active_pre_swap_run_id() viera un fence distinto al adquirido.
		$editorial_runtime = new EditorialDiscountPublication( $repo_primary, $indexer, $runtime_fence );

		// El par mode+buckets es una sola unidad de consistencia. Estas pruebas se
		// ejecutan sólo en candidate: baseline sigue siendo el control del ledger
		// legacy y no conoce ninguna API introducida en 0.1.14.
		$price_transition = new ReflectionMethod( PriceBuckets::class, 'transition' );
		$price_transition->setAccessible( true );
		$with_price_connection = static function ( object $connection, callable $callback ) use ( &$wpdb ) {
			$original = $wpdb;
			$wpdb     = $connection;
			try {
				return $callback();
			} finally {
				$wpdb = $original;
			}
		};
		$transition_price = static function ( object $connection, array $rows, string $policy, bool $only_if_auto ) use ( $price_transition, $with_price_connection ) {
			return $with_price_connection(
				$connection,
				static fn() => $price_transition->invoke( null, $rows, $policy, $only_if_auto, true )
			);
		};
		$price_rows = static function ( int $cut ): array {
			return array(
				array( 'min' => null, 'max' => $cut, 'label' => PriceBuckets::derive_label( null, $cut ) ),
				array( 'min' => $cut, 'max' => null, 'label' => PriceBuckets::derive_label( $cut, null ) ),
			);
		};
		$read_price_pair = static function ( object $connection ): ?array {
			$names = array( PriceBuckets::OPTION_MODE, \Akibara\Search\FacetRegistry::OPTION_PRECIO_BUCKETS );
			$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
			$connection->last_error = '';
			$rows = $connection->get_results(
				$connection->prepare(
					"SELECT option_name, option_value, autoload FROM {$connection->options}
					 WHERE option_name IN ({$placeholders}) ORDER BY option_name",
					...$names
				),
				ARRAY_A
			);
			if ( '' !== trim( (string) $connection->last_error ) || ! is_array( $rows ) ) {
				return null;
			}
			$pair = array();
			foreach ( $rows as $row ) {
				$pair[ (string) $row['option_name'] ] = array(
					'raw'      => (string) $row['option_value'],
					'autoload' => (string) $row['autoload'],
				);
			}
			return 2 === count( $pair ) ? $pair : null;
		};
		$price_pair_matches = static function ( ?array $pair, string $mode_expected, array $rows_expected ): bool {
			return is_array( $pair )
				&& array( 'raw' => $mode_expected, 'autoload' => 'no' ) === ( $pair[ PriceBuckets::OPTION_MODE ] ?? null )
				&& array( 'raw' => maybe_serialize( array_values( $rows_expected ) ), 'autoload' => 'no' )
					=== ( $pair[ \Akibara\Search\FacetRegistry::OPTION_PRECIO_BUCKETS ] ?? null );
		};
		$write_price_pair_sql = static function ( object $connection, string $target_mode, array $target_rows ) use ( $sql_must ): void {
			$desired = array(
				\Akibara\Search\FacetRegistry::OPTION_PRECIO_BUCKETS => maybe_serialize( array_values( $target_rows ) ),
				PriceBuckets::OPTION_MODE => $target_mode,
			);
			ksort( $desired, SORT_STRING );
			$names = array_keys( $desired );
			$sql_must(
				$connection->query(
					$connection->prepare(
						"INSERT INTO {$connection->options} (option_name, option_value, autoload)
						 VALUES (%s, %s, %s), (%s, %s, %s)
						 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
						$names[0],
						$desired[ $names[0] ],
						'no',
						$names[1],
						$desired[ $names[1] ],
						'no'
					)
				),
				'No se pudo escribir el par de precio en la TX de crash',
				$connection
			);
		};

		$rows_a = $price_rows( 10000 );
		$rows_b = $price_rows( 20000 );
		$rows_c = $price_rows( 30000 );
		$must(
			null === \Akibara\Search\FacetRegistry::validate_precio_buckets( $rows_a )
			&& null === \Akibara\Search\FacetRegistry::validate_precio_buckets( $rows_b )
			&& null === \Akibara\Search\FacetRegistry::validate_precio_buckets( $rows_c ),
			'Los pares controlados de precio no son válidos.'
		);
		delete_option( Install::OPTION_GENERATION_OUTBOX );
		delete_option( SemanticPublication::OPTION_FINGERPRINT );
		wp_cache_delete( Install::OPTION_GENERATION_OUTBOX, 'options' );
		wp_cache_delete( SemanticPublication::OPTION_FINGERPRINT, 'options' );

		$must( false !== $transition_price( $wpdb, $rows_a, 'auto', false ), 'No se pudo sembrar el par AUTO controlado.' );
		$must( false !== $transition_price( $wpdb, $rows_b, 'manual_if_changed', false ), 'El writer MANUAL controlado falló.' );
		$auto_after_manual = $transition_price( $secondary, $rows_c, 'auto', true );
		$check(
			'AUTO condicional pierde ante MANUAL observado bajo lock',
			0 === $auto_after_manual
			&& $price_pair_matches( $read_price_pair( $secondary ), 'manual', $rows_b )
		);

		$price_fence_primary   = new WriteFence( $wpdb );
		$price_fence_secondary = new WriteFence( $secondary );
		$must( $price_fence_primary->acquire( 0 ), 'El writer de precio primario no adquirió el fence real.' );
		$check( 'fence real de precio excluye al segundo writer', ! $price_fence_secondary->acquire( 0 ) );
		$must( $price_fence_primary->release(), 'El writer de precio primario no liberó el fence real.' );
		$price_fence_primary = null;

		$interleavings_ok = true;
		for ( $price_iteration = 1; $price_iteration <= 20; ++$price_iteration ) {
			$must( false !== $transition_price( $wpdb, $rows_a, 'auto', false ), 'No se pudo normalizar el par antes del interleaving.' );
			if ( 0 === $price_iteration % 2 ) {
				$must( false !== $transition_price( $wpdb, $rows_b, 'manual_if_changed', false ), 'Falló writer MANUAL primario.' );
				$must( false !== $transition_price( $secondary, $rows_c, 'auto', false ), 'Falló writer AUTO secundario.' );
				$interleavings_ok = $interleavings_ok
					&& $price_pair_matches( $read_price_pair( $secondary ), 'auto', $rows_c );
			} else {
				$must( false !== $transition_price( $secondary, $rows_c, 'auto', false ), 'Falló writer AUTO secundario.' );
				$must( false !== $transition_price( $wpdb, $rows_b, 'manual_if_changed', false ), 'Falló writer MANUAL primario.' );
				$interleavings_ok = $interleavings_ok
					&& $price_pair_matches( $read_price_pair( $secondary ), 'manual', $rows_b );
			}
		}
		$check( '20/20 interleavings de dos writers conservan par completo y gana el último', $interleavings_ok );

		$must( false !== $transition_price( $wpdb, $rows_a, 'auto', false ), 'No se pudo preparar baseline del crash de precio.' );
		$price_crash_connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$price_crash_connection->set_prefix( $wpdb->prefix );
		$price_crash_connection->suppress_errors( true );
		$must( $database === (string) $price_crash_connection->get_var( 'SELECT DATABASE()' ), 'La conexión de crash no apunta a la fixture.' );
		$sql_must( $price_crash_connection->query( 'START TRANSACTION' ), 'No se pudo iniciar la TX de crash de precio', $price_crash_connection );
		$price_names = array( PriceBuckets::OPTION_MODE, \Akibara\Search\FacetRegistry::OPTION_PRECIO_BUCKETS );
		sort( $price_names, SORT_STRING );
		foreach ( $price_names as $price_name ) {
			$price_crash_connection->last_error = '';
			$price_crash_connection->get_row(
				$price_crash_connection->prepare(
					"SELECT option_value, autoload FROM {$price_crash_connection->options}
					 WHERE option_name = %s LIMIT 1 FOR UPDATE",
					$price_name
				)
			);
			$must( '' === trim( (string) $price_crash_connection->last_error ), 'No se pudo bloquear el par antes del crash.' );
		}
		$write_price_pair_sql( $price_crash_connection, 'manual', $rows_c );
		$must( $price_crash_connection->close(), 'No se pudo cerrar la conexión con TX abierta.' );
		$price_crash_connection = null;
		$check(
			'cerrar conexión con TX abierta revierte mode+buckets juntos',
			$price_pair_matches( $read_price_pair( $secondary ), 'auto', $rows_a )
		);

		$restored_price_count = $with_price_connection(
			$wpdb,
			static fn() => PriceBuckets::refresh( false, true )
		);
		$must( false !== $restored_price_count, 'No se pudo restaurar el modo AUTO derivado tras el fixture de precio.' );

		// Rama SQL real del rollback compensatorio de formularios admin. La
		// conexión primaria se instrumenta sólo para inyectar una escritura desde
		// la segunda conexión justo después de la foto y antes del CAS.
		$cas_primary = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
			/** @var callable|null */
			public $before_cas_query = null;
			public bool $force_cas_error = false;
			/** @var int|false|null */
			public $last_cas_affected = null;

			/** @param string $query SQL. @return int|bool */
			public function query( $query ) {
				$is_cas = is_string( $query )
					&& false !== strpos( $query, 'BINARY option_value = BINARY' );
				if ( ! $is_cas ) {
					return parent::query( $query );
				}
				if ( is_callable( $this->before_cas_query ) ) {
					$callback               = $this->before_cas_query;
					$this->before_cas_query = null;
					$callback();
				}
				if ( $this->force_cas_error ) {
					$this->last_error        = 'simulated MariaDB CAS error';
					$this->last_cas_affected = false;
					return false;
				}
				$result                  = parent::query( $query );
				$this->last_cas_affected = $result;
				return $result;
			}
		};
		$cas_primary->set_prefix( $wpdb->prefix );
		$cas_primary->suppress_errors( true );
		$must( $database === (string) $cas_primary->get_var( 'SELECT DATABASE()' ), 'La conexión CAS no apunta a la fixture.' );

		$restore_cas = new ReflectionMethod( SearchAdminSection::class, 'restore_option_snapshot_cas' );
		$restore_cas->setAccessible( true );
		$restore_group = new ReflectionMethod( SearchAdminSection::class, 'restore_option_snapshots' );
		$restore_group->setAccessible( true );
		$state = static function ( bool $present, string $raw = '', string $autoload = '' ): array {
			return array(
				'present'  => $present,
				'value'    => $present ? maybe_unserialize( $raw ) : null,
				'raw'      => $raw,
				'autoload' => $autoload,
			);
		};
		$set_raw_option = static function ( object $connection, string $name, string $raw, string $autoload ) use ( $sql_must ): void {
			$sql_must(
				$connection->query(
					$connection->prepare(
						"INSERT INTO {$connection->options} (option_name, option_value, autoload)
						 VALUES (%s, %s, %s)
						 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
						$name,
						$raw,
						$autoload
					)
				),
				'No se pudo preparar la option CAS',
				$connection
			);
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		};
		$read_raw_option = static function ( object $connection, string $name ): ?array {
			$connection->last_error = '';
			$row                    = $connection->get_row(
				$connection->prepare(
					"SELECT option_value, autoload FROM {$connection->options} WHERE option_name = %s",
					$name
				),
				ARRAY_A
			);
			if ( '' !== trim( (string) $connection->last_error ) || ! is_array( $row ) ) {
				return null;
			}
			return array( 'raw' => (string) $row['option_value'], 'autoload' => (string) $row['autoload'] );
		};
		$with_cas_connection = static function ( callable $callback ) use ( &$wpdb, $cas_primary ) {
			$original = $wpdb;
			$wpdb     = $cas_primary;
			try {
				return $callback();
			} finally {
				$wpdb = $original;
			}
		};

		$cas_update_option = 'akb_search_itest_admin_cas_update';
		$set_raw_option( $wpdb, $cas_update_option, 'C', 'off' );
		$cas_update_result = $with_cas_connection(
			static fn() => $restore_cas->invoke(
				new SearchAdminSection(),
				$cas_update_option,
				$state( true, 'A', 'on' ),
				$state( true, 'C', 'off' )
			)
		);
		$check(
			'CAS admin UPDATE real restaura bytes y autoload exactos',
			array( 'ok' => true, 'changed' => true ) === $cas_update_result
			&& 1 === $cas_primary->last_cas_affected
			&& array( 'raw' => 'A', 'autoload' => 'on' ) === $read_raw_option( $secondary, $cas_update_option )
		);

		$cas_delete_option = 'akb_search_itest_admin_cas_delete';
		$set_raw_option( $wpdb, $cas_delete_option, 'C', 'off' );
		$cas_delete_result = $with_cas_connection(
			static fn() => $restore_cas->invoke(
				new SearchAdminSection(),
				$cas_delete_option,
				$state( false ),
				$state( true, 'C', 'off' )
			)
		);
		$check(
			'CAS admin DELETE real restaura ausencia exacta',
			array( 'ok' => true, 'changed' => true ) === $cas_delete_result
			&& 1 === $cas_primary->last_cas_affected
			&& null === $read_raw_option( $secondary, $cas_delete_option )
			&& '' === trim( (string) $secondary->last_error )
		);

		$cas_race_option = 'akb_search_itest_admin_cas_race';
		$set_raw_option( $wpdb, $cas_race_option, 'C', 'off' );
		$cas_primary->before_cas_query = static function () use ( $secondary, $cas_race_option, $set_raw_option ): void {
			$set_raw_option( $secondary, $cas_race_option, 'D', 'on' );
		};
		$cas_race_result = $with_cas_connection(
			static fn() => $restore_cas->invoke(
				new SearchAdminSection(),
				$cas_race_option,
				$state( true, 'A', 'on' ),
				$state( true, 'C', 'off' )
			)
		);
		$check(
			'CAS admin affected_rows=0 preserva writer concurrente D',
			array( 'ok' => false, 'changed' => false ) === $cas_race_result
			&& 0 === $cas_primary->last_cas_affected
			&& array( 'raw' => 'D', 'autoload' => 'on' ) === $read_raw_option( $secondary, $cas_race_option )
		);

		$cas_autoload_option = 'akb_search_itest_admin_cas_autoload';
		$set_raw_option( $wpdb, $cas_autoload_option, 'C', 'off' );
		$cas_primary->before_cas_query = static function () use ( $secondary, $cas_autoload_option, $set_raw_option ): void {
			$set_raw_option( $secondary, $cas_autoload_option, 'C', 'on' );
		};
		$cas_autoload_result = $with_cas_connection(
			static fn() => $restore_cas->invoke(
				new SearchAdminSection(),
				$cas_autoload_option,
				$state( true, 'A', 'on' ),
				$state( true, 'C', 'off' )
			)
		);
		$check(
			'CAS admin compara autoload y no pisa cambio concurrente',
			array( 'ok' => false, 'changed' => false ) === $cas_autoload_result
			&& 0 === $cas_primary->last_cas_affected
			&& array( 'raw' => 'C', 'autoload' => 'on' ) === $read_raw_option( $secondary, $cas_autoload_option )
		);

		$cas_error_option = 'akb_search_itest_admin_cas_error';
		$set_raw_option( $wpdb, $cas_error_option, 'C', 'off' );
		$cas_primary->force_cas_error = true;
		$cas_error_result = $with_cas_connection(
			static fn() => $restore_cas->invoke(
				new SearchAdminSection(),
				$cas_error_option,
				$state( true, 'A', 'on' ),
				$state( true, 'C', 'off' )
			)
		);
		$cas_primary->force_cas_error = false;
		$check(
			'CAS admin error SQL falla cerrado y conserva fila',
			array( 'ok' => false, 'changed' => false ) === $cas_error_result
			&& false === $cas_primary->last_cas_affected
			&& array( 'raw' => 'C', 'autoload' => 'off' ) === $read_raw_option( $secondary, $cas_error_option )
		);

		// La compensación de una option semántica usa SQL directo; después debe
		// publicar de nuevo el fingerprint restaurado y dejar receipt de purge.
		$semantic_old = maybe_serialize( array( array( 'gate-old', 'gate-alias-old' ) ) );
		$semantic_new = maybe_serialize( array( array( 'gate-new', 'gate-alias-new' ) ) );
		$set_raw_option( $wpdb, Synonyms::OPTION, $semantic_new, 'off' );
		$must( ( new SemanticPublication() )->reconcile_fingerprint(), 'No se pudo publicar el fingerprint semántico previo al rollback.' );
		$generation_before_restore = (string) get_option( Install::OPTION_INDEX_GEN, '' );
		$semantic_restore_result   = $with_cas_connection(
			static fn() => $restore_group->invoke(
				new SearchAdminSection(),
				array( Synonyms::OPTION => $state( true, $semantic_old, 'off' ) ),
				array( Synonyms::OPTION ),
				array( Synonyms::OPTION => $state( true, $semantic_new, 'off' ) )
			)
		);
		$semantic_fingerprint = SemanticPublication::current_fingerprint();
		$semantic_receipt     = get_option( SemanticPublication::OPTION_FINGERPRINT, array() );
		$generation_restored  = (string) get_option( Install::OPTION_INDEX_GEN, '' );
		$check(
			'rollback semántico real reconcilia fingerprint generación y purge',
			true === $semantic_restore_result
			&& array( 'raw' => $semantic_old, 'autoload' => 'off' ) === $read_raw_option( $secondary, Synonyms::OPTION )
			&& is_string( $semantic_fingerprint )
			&& is_array( $semantic_receipt )
			&& hash_equals( $semantic_fingerprint, (string) ( $semantic_receipt['fingerprint'] ?? '' ) )
			&& '' !== $generation_restored
			&& $generation_before_restore !== $generation_restored
			&& $generation_restored === (string) ( $semantic_receipt['generation'] ?? '' )
			&& RestCache::has_purge_receipt( $generation_restored )
		);

		foreach ( array( $cas_update_option, $cas_delete_option, $cas_race_option, $cas_autoload_option, $cas_error_option ) as $cas_option ) {
			delete_option( $cas_option );
			wp_cache_delete( $cas_option, 'options' );
		}
		$cas_primary->close();

		// wanted n+1 nunca queda cubierto por el ACK de n.
		$n1_product = 900000001;
		$must( $repo_primary->mark_live_dirty( $n1_product ), 'No se pudo marcar n1.' );
		$n1_claim = $repo_primary->claim_product( WorkRepository::SCOPE_LIVE, '', $n1_product, 60, 'gate-n1-a' );
		$must( is_array( $n1_claim ), 'No se pudo reclamar n1.' );
		$must( $repo_secondary->mark_live_dirty( $n1_product ), 'No se pudo marcar n+1 en conexion independiente.' );
		$must( $repo_primary->ack_claim( $n1_claim, WorkRepository::OUTCOME_INDEXED ), 'No se pudo confirmar n.' );
		$n1_after = $repo_secondary->find( WorkRepository::SCOPE_LIVE, '', $n1_product );
		$check(
			'ACK n conserva n+1 pendiente',
			2 === (int) ( $n1_after['wanted_seq'] ?? 0 )
			&& 1 === (int) ( $n1_after['acked_seq'] ?? 0 )
			&& WorkRepository::STATUS_PENDING === (string) ( $n1_after['status'] ?? '' )
		);
		$n1_claim_2 = $repo_secondary->claim_product( WorkRepository::SCOPE_LIVE, '', $n1_product, 60, 'gate-n1-b' );
		$must( is_array( $n1_claim_2 ), 'No se pudo reclamar n+1.' );
		$check( 'claim/token stale no puede ACK', ! $repo_primary->ack_claim( $n1_claim, WorkRepository::OUTCOME_INDEXED ) );
		$must( $repo_secondary->ack_claim( $n1_claim_2, WorkRepository::OUTCOME_ABSENT ), 'No se pudo confirmar n+1.' );

		// Un ACK dentro de una TX abortada no se filtra fuera del ROLLBACK.
		$rollback_product = 900000002;
		$must( $repo_primary->mark_live_dirty( $rollback_product ), 'No se pudo marcar rollback.' );
		$rollback_claim = $repo_primary->claim_product( WorkRepository::SCOPE_LIVE, '', $rollback_product, 60, 'gate-rollback' );
		$must( is_array( $rollback_claim ), 'No se pudo reclamar rollback.' );
		$sql_must( $wpdb->query( 'START TRANSACTION' ), 'No se pudo iniciar la TX de rollback' );
		$must( $repo_primary->validate_claim_for_update( $rollback_claim ), 'El claim no valido dentro de TX.' );
		$must( $repo_primary->ack_claim( $rollback_claim, WorkRepository::OUTCOME_INDEXED ), 'El ACK dentro de TX fallo.' );
		$sql_must( $wpdb->query( 'ROLLBACK' ), 'No se pudo forzar ROLLBACK' );
		$rollback_after = $repo_secondary->find( WorkRepository::SCOPE_LIVE, '', $rollback_product );
		$check(
			'ROLLBACK revierte ACK y conserva lease original',
			0 === (int) ( $rollback_after['acked_seq'] ?? -1 )
			&& WorkRepository::STATUS_LEASED === (string) ( $rollback_after['status'] ?? '' )
			&& 'gate-rollback' === (string) ( $rollback_after['lease_token'] ?? '' )
		);
		$must( $repo_primary->ack_claim( $rollback_claim, WorkRepository::OUTCOME_ABSENT ), 'No se pudo ACK despues del rollback.' );

		// AS retorna 0, pero enqueue_product ya persistio la deuda.
		$delete_search_actions();
		$enqueue_product = 900000003;
		$zero_enqueue    = $with_zero_enqueue(
			static function () use ( $indexer, $enqueue_product ): bool {
				$indexer->enqueue_product( $enqueue_product );
				return true;
			}
		);
		$enqueue_row = $repo_primary->find( WorkRepository::SCOPE_LIVE, '', $enqueue_product );
		$check(
			'enqueue=0 deja deuda durable pending',
			in_array( Indexer::HOOK_PUMP, $zero_enqueue['hits'], true )
			&& WorkRepository::STATUS_PENDING === (string) ( $enqueue_row['status'] ?? '' )
			&& 0 === $search_action_count()
		);
		$enqueue_claim = $repo_primary->claim_product( WorkRepository::SCOPE_LIVE, '', $enqueue_product, 60, 'gate-enqueue-zero' );
		$must( is_array( $enqueue_claim ) && $repo_primary->ack_claim( $enqueue_claim, WorkRepository::OUTCOME_ABSENT ), 'No se pudo cerrar deuda enqueue=0.' );

		// Lease expirada se recupera; el token viejo no puede confirmar el nuevo claim.
		$lease_product = 900000004;
		$must( $repo_primary->mark_live_dirty( $lease_product ), 'No se pudo marcar lease.' );
		$lease_old = $repo_primary->claim_product( WorkRepository::SCOPE_LIVE, '', $lease_product, 60, 'gate-lease-old' );
		$must( is_array( $lease_old ), 'No se pudo reclamar lease viejo.' );
		$sql_must(
			$secondary->query(
				$secondary->prepare(
					'UPDATE ' . Install::work_table() . ' SET lease_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = %d AND lease_token = %s',
					(int) $lease_old['id'],
					(string) $lease_old['lease_token']
				)
			),
			'No se pudo expirar el lease controlado',
			$secondary
		);
		$check( 'watchdog recupera exactamente un lease vencido', 1 === $repo_secondary->recover_expired( WorkRepository::SCOPE_LIVE, '' ) );
		$lease_new = $repo_secondary->claim_product( WorkRepository::SCOPE_LIVE, '', $lease_product, 60, 'gate-lease-new' );
		$must( is_array( $lease_new ), 'No se pudo reclamar lease recuperado.' );
		$check( 'token de lease vencido no puede ACK', ! $repo_primary->ack_claim( $lease_old, WorkRepository::OUTCOME_INDEXED ) );
		$must( $repo_secondary->ack_claim( $lease_new, WorkRepository::OUTCOME_ABSENT ), 'No se pudo ACK lease nuevo.' );
		$check( 'deuda live de primitivas converge a cero', 0 === $repo_primary->outstanding_count( '', WorkRepository::SCOPE_LIVE ) );

		// Acota el universo a exactamente 100 productos dentro del clon.
		$catalog_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s ORDER BY ID ASC LIMIT 100",
				'product',
				'publish'
			)
		);
		$catalog_ids = array_values( array_map( 'intval', is_array( $catalog_ids ) ? $catalog_ids : array() ) );
		$must( 100 === count( $catalog_ids ), 'La fixture requiere al menos 100 productos publicados.' );
		$id_list = implode( ', ', $catalog_ids );
		$sql_must(
			$wpdb->query(
				"UPDATE {$wpdb->posts} SET post_status = 'draft' WHERE post_type = 'product' AND post_status = 'publish' AND ID NOT IN ({$id_list})"
			),
			'No se pudo acotar el universo de la fixture'
		);
		$check( 'universo fuente controlado de 100 productos', 100 === RebuildJob::eligible_product_count() );

		$delete_search_actions();
		delete_option( RebuildJob::OPTION_STATE );
		// El gate no puede depender de que la DB fuente traiga una publicación
		// editorial legacy o ya reconciliada. En el clon efímero, ausencia de state
		// y receipt obliga a sembrar exactamente el catálogo controlado de 100.
		delete_option( EditorialDiscountPublication::OPTION_STATE );
		delete_option( EditorialDiscountPublication::OPTION_RECEIPT );
		wp_cache_delete( EditorialDiscountPublication::OPTION_STATE, 'options' );
		wp_cache_delete( EditorialDiscountPublication::OPTION_RECEIPT, 'options' );
		$must( Install::TOPOLOGY_CLEAN_PRE === Install::classify_topology(), 'La topologia no esta limpia antes del rebuild.' );

		// WordPress ejecutó init antes de que este eval-file normalizara el clon.
		// La DB fuente puede traer un rebuild bloqueado y una intención editorial
		// sin staged debt; después de limpiar esa topología hay que dar al runtime
		// real una oportunidad explícita de reconciliar la fuente vigente. El
		// retorno false también representa una intención nueva correctamente
		// staged (todavía no existe receipt), por eso la prueba es la foto durable.
		$editorial_reconcile_first = $with_zero_enqueue(
			static function (): bool {
				return EditorialDiscountPublication::reconcile_now();
			}
		);
		// La primera pasada publica PRE y deuda antes de observar otra vez la
		// fuente; la segunda confirma POST. Es el mismo protocolo que dos requests
		// consecutivos tras recuperar un crash entre ambas mitades.
		$editorial_reconcile_second = $with_zero_enqueue(
			static function (): bool {
				return EditorialDiscountPublication::reconcile_now();
			}
		);
		$editorial_rebuild_snapshot = EditorialDiscountPublication::snapshot_for_rebuild();
		$editorial_reconcile_ok       = is_bool( $editorial_reconcile_first['result'] )
			&& is_bool( $editorial_reconcile_second['result'] )
			&& is_array( $editorial_rebuild_snapshot )
			&& 0 === $search_action_count();
		$check(
			'reconcile editorial deja foto durable apta para rebuild',
			$editorial_reconcile_ok
		);
		if ( ! $editorial_reconcile_ok ) {
			$log(
				'  INFO  reconcile editorial: ' . (string) wp_json_encode(
					array(
						'first'          => $editorial_reconcile_first,
						'second'         => $editorial_reconcile_second,
						'snapshot'       => $editorial_rebuild_snapshot,
						'action_count'   => $search_action_count(),
						'pending_rebuild'=> Install::pending_rebuild_request(),
					)
				)
			);
		}
		if ( ! is_array( $editorial_rebuild_snapshot ) ) {
			$editorial_reflector   = new ReflectionClass( EditorialDiscountPublication::class );
			$read_state_method     = $editorial_reflector->getMethod( 'read_state' );
			$authority_property    = $editorial_reflector->getProperty( 'read_authority_available' );
			$authority_error       = $editorial_reflector->getProperty( 'read_authority_error' );
			$editorial_diagnostic  = array(
				'first'            => $editorial_reconcile_first,
				'second'           => $editorial_reconcile_second,
				'status'           => EditorialDiscountPublication::status(),
				'state'            => $read_state_method->invoke( null ),
				'read_authority'   => (bool) $authority_property->getValue(),
				'read_error'       => (string) $authority_error->getValue(),
				'pending_rebuild'  => Install::pending_rebuild_request(),
				'rebuild_status'   => RebuildJob::status(),
				'topology'         => Install::classify_topology(),
				'live_outstanding' => $repo_primary->outstanding_count( '', WorkRepository::SCOPE_LIVE ),
				'search_actions'   => $search_action_count(),
			);
			throw new RuntimeException(
				'La fuente editorial no quedó staged tras normalizar el clon: ' . (string) wp_json_encode( $editorial_diagnostic )
			);
		}

		$matured_rebuild = $mature_pending_rebuild();
		$check(
			'fixture simula gracia vencida sin alterar identidad durable',
			count( $matured_rebuild['before'] ) === count( $matured_rebuild['after'] )
			&& Install::pending_rebuild_is_ready()
		);
		$live_before_rebuild     = $repo_primary->outstanding_count( '', WorkRepository::SCOPE_LIVE );
		$live_ids_before_rebuild = array_values(
			array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						'SELECT product_id FROM ' . Install::work_table() . ' WHERE scope = %s AND run_id = %s AND status <> %s ORDER BY product_id ASC',
						WorkRepository::SCOPE_LIVE,
						'',
						WorkRepository::STATUS_ACKED
					)
				)
			)
		);
		$expected_live_ids = $catalog_ids;
		sort( $expected_live_ids, SORT_NUMERIC );
		$must(
			100 === $live_before_rebuild && $expected_live_ids === $live_ids_before_rebuild,
			'La publicación editorial no sembró exactamente los 100 productos controlados.'
		);
		$sql_must(
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . Install::work_table() . ' SET available_at = UTC_TIMESTAMP() WHERE scope = %s AND run_id = %s AND status = %s',
					WorkRepository::SCOPE_LIVE,
					'',
					WorkRepository::STATUS_PENDING
				)
			),
			'No se pudo simular el vencimiento de la deuda live editorial'
		);
		$live_pump_hits = array();
		for ( $pump = 0; $pump < 8 && 0 < $repo_primary->outstanding_count( '', WorkRepository::SCOPE_LIVE ); ++$pump ) {
			$pump_result    = $with_zero_enqueue(
				static function () use ( $indexer ): bool {
					$indexer->handle_live_pump();
					return true;
				}
			);
			$live_pump_hits = array_merge( $live_pump_hits, $pump_result['hits'] );
		}
		$remaining_live = $repo_primary->outstanding_count( '', WorkRepository::SCOPE_LIVE );
		if ( 0 !== $remaining_live ) {
			$first_live = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . Install::work_table() . ' WHERE scope = %s AND run_id = %s AND status <> %s ORDER BY product_id ASC LIMIT 1',
					WorkRepository::SCOPE_LIVE,
					'',
					WorkRepository::STATUS_ACKED
				),
				ARRAY_A
			);
			throw new RuntimeException(
				'La deuda live editorial no convergió: ' . (string) wp_json_encode(
					array(
						'remaining' => $remaining_live,
						'first'     => $first_live,
						'work_error'=> $repo_primary->last_error(),
						'wpdb_error'=> (string) $wpdb->last_error,
					)
				)
			);
		}
		$check(
			'deuda live editorial vence y converge antes del rebuild',
			100 === $live_before_rebuild
			&& 0 === $remaining_live
			&& 0 === $repo_primary->active_lease_count( '', WorkRepository::SCOPE_LIVE )
			&& in_array( Indexer::HOOK_PUMP, $live_pump_hits, true )
			&& 0 === $search_action_count()
		);

		/*
		 * La señal de rebuild trae su propia gracia: el protocolo la pide con
		 * `not_before = time() + PREWRITE_DELAY` (10 s) para que la escritura se
		 * asiente antes de que nadie la consuma, y `Install::runtime_ready()` la
		 * respeta. Este gate la pedía y la arrancaba en el MISMO instante, así
		 * que `schedule()` devolvía false por una espera legítima y el fallo se
		 * leía como «no se pudo arrancar el rebuild durable».
		 *
		 * Antes no se notaba porque este punto se alcanzaba sin ninguna petición
		 * pendiente: el re-probado cortaba mucho antes al leer la ausencia del
		 * recibo como lectura fallida. Al arreglarlo, el gate llegó por primera
		 * vez hasta aquí con una señal viva y honesta. En producción quien llega
		 * después es el cron; aquí se espera igual, sin saltarse la gracia.
		 */
		$espera_hasta = time() + 30;
		while ( ! Install::pending_rebuild_is_ready() && time() < $espera_hasta ) {
			usleep( 250000 );
		}

		$scheduled = $with_zero_enqueue(
			static function (): bool {
				return RebuildJob::schedule();
			}
		);
		if ( true !== $scheduled['result'] ) {
			$publication_reflector = new ReflectionClass( EditorialDiscountPublication::class );
			$authority_property    = $publication_reflector->getProperty( 'read_authority_available' );
			$error_property        = $publication_reflector->getProperty( 'read_authority_error' );
			$price_hook            = $GLOBALS['wp_filter']['akb_search_effective_price'] ?? null;
			$price_callbacks       = array();
			$callback_groups       = is_object( $price_hook ) && isset( $price_hook->callbacks ) && is_array( $price_hook->callbacks )
				? $price_hook->callbacks
				: ( is_array( $price_hook ) ? $price_hook : array() );
			foreach ( $callback_groups as $priority => $callbacks ) {
				foreach ( is_array( $callbacks ) ? $callbacks : array() as $callback ) {
					$callable = is_array( $callback ) ? ( $callback['function'] ?? null ) : null;
					$price_callbacks[] = array(
						'priority'      => (int) $priority,
						'accepted_args' => is_array( $callback ) ? (int) ( $callback['accepted_args'] ?? 0 ) : 0,
						'callable'      => is_object( $callable )
							? get_class( $callable )
							: ( is_array( $callable ) && isset( $callable[0], $callable[1] )
								? ( is_object( $callable[0] ) ? get_class( $callable[0] ) : (string) $callable[0] ) . '::' . (string) $callable[1]
								: get_debug_type( $callable ) ),
					);
				}
			}
			$active_plugins = get_option( 'active_plugins', array() );
			$diagnostic = array(
				'runtime_ready'       => Install::runtime_ready(),
				'live_writer_ready'   => Install::live_writer_schema_ready(),
				'topology'            => Install::classify_topology(),
				'rebuild_status'       => RebuildJob::status(),
				'business_version'     => defined( 'AKIBARA_BUSINESS_VERSION' ) ? AKIBARA_BUSINESS_VERSION : null,
				'woocommerce_version'  => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'business_active'      => is_array( $active_plugins ) && in_array( 'akibara-business/akibara-business.php', $active_plugins, true ),
				'provider_filter'      => false !== has_filter( 'akb_search_effective_price' ),
				'provider_callbacks'   => $price_callbacks,
				'feed_contract_option' => get_option( 'akibara_business_search_feed_contract_version', '__missing__' ),
				'discount_enabled'     => get_option( 'akibara_taxonomy_discount_enabled', '__missing__' ),
				'read_authority'       => (bool) $authority_property->getValue(),
				'read_error'           => (string) $error_property->getValue(),
				'editorial_status'     => EditorialDiscountPublication::status(),
				'editorial_snapshot'   => EditorialDiscountPublication::snapshot_for_rebuild(),
				'pending_rebuild'      => Install::pending_rebuild_request(),
				'zero_enqueue_hooks'   => $scheduled['hits'],
			);
			throw new RuntimeException(
				'No se pudo arrancar el rebuild durable: ' . (string) wp_json_encode( $diagnostic )
			);
		}
		$status = RebuildJob::status();
		$must( is_array( $status ), 'El rebuild no persistio estado.' );
		$run_id = (string) $status['run_id'];
		$check(
			'wake-up inicial=0 no borra manifest de 100',
			in_array( RebuildJob::HOOK_BATCH, $scheduled['hits'], true )
			&& 100 === $repo_primary->outstanding_count( $run_id, WorkRepository::SCOPE_BASELINE )
			&& Install::TOPOLOGY_READY_SWAP === Install::classify_topology()
		);

		$title_product  = $catalog_ids[0];
		$delete_product = $catalog_ids[1];
		$held_product   = $catalog_ids[2];

		foreach ( array( $title_product, $delete_product ) as $baseline_product ) {
			$claim = $repo_primary->claim_product(
				WorkRepository::SCOPE_BASELINE,
				$run_id,
				$baseline_product,
				RebuildJob::LEASE_SECONDS,
				'gate-baseline-' . $baseline_product
			);
			$must( is_array( $claim ), 'No se pudo reclamar el baseline mutado.' );
			$must( WorkRepository::OUTCOME_INDEXED === $indexer->process_shadow_claim( $claim ), 'No se pudo materializar baseline previo a mutacion.' );
		}

		$held_claim = $repo_primary->claim_product(
			WorkRepository::SCOPE_BASELINE,
			$run_id,
			$held_product,
			RebuildJob::LEASE_SECONDS,
			'gate-held-99-of-100'
		);
		$must( is_array( $held_claim ), 'No se pudo reservar el producto 100.' );

		$new_title = 'Gate durable ' . substr( hash( 'sha256', $run_id ), 0, 12 );
		$sql_must(
			$wpdb->update(
				$wpdb->posts,
				array( 'post_title' => $new_title ),
				array( 'ID' => $title_product ),
				array( '%s' ),
				array( '%d' )
			),
			'No se pudo mutar el titulo fuente'
		);
		clean_post_cache( $title_product );
		// Envuelta, como el resto del flujo: una escritura live encola el pump y,
		// por la publicación editorial, la acción del boundary. Sin envolver,
		// esas dos acciones sobreviven hasta la precondición del Reconciler y la
		// tumban — el gate llevaba días en rojo por aquí.
		$title_indexado = $with_zero_enqueue(
			static function () use ( $indexer, $title_product ): string {
				return (string) $indexer->index_product_outcome( $title_product );
			}
		);
		$title_outcome = (string) $title_indexado['result'];
		if ( WorkRepository::OUTCOME_INDEXED !== $title_outcome ) {
			$price_probe = EditorialDiscountPublication::capture_price_authority();
			throw new RuntimeException(
				'La escritura live del titulo no convergio: ' . (string) wp_json_encode(
					array(
						'outcome'            => $title_outcome,
						'work'               => $repo_primary->find( WorkRepository::SCOPE_LIVE, '', $title_product ),
						'work_error'         => $repo_primary->last_error(),
						'wpdb_error'         => (string) $wpdb->last_error,
						'runtime_ready'      => Install::runtime_ready(),
						'writer_ready'       => Install::live_writer_schema_ready(),
						'topology'           => Install::classify_topology(),
						'rebuild_status'      => RebuildJob::status(),
						'price_capture'       => $price_probe,
						'price_matches'       => is_array( $price_probe ) && EditorialDiscountPublication::price_authority_matches( $price_probe ),
						'generation_outbox'  => get_option( Install::OPTION_GENERATION_OUTBOX, '__missing__' ),
						'index_generation'   => get_option( Install::OPTION_INDEX_GEN, '__missing__' ),
						'fence_owned'        => $runtime_fence->is_owned(),
					)
				)
			);
		}

		$sql_must(
			$wpdb->update(
				$wpdb->posts,
				array( 'post_status' => 'trash' ),
				array( 'ID' => $delete_product ),
				array( '%s' ),
				array( '%d' )
			),
			'No se pudo mutar el borrado fuente'
		);
		clean_post_cache( $delete_product );
		$baja_live = $with_zero_enqueue(
			static function () use ( $indexer, $delete_product ): bool {
				return (bool) $indexer->delete_product( $delete_product );
			}
		);
		$must( (bool) $baja_live['result'], 'La baja live no convergio.' );
		$check(
			'mutacion de contenido y borrado crean dos repairs durables',
			2 === $repo_primary->outstanding_count( $run_id, WorkRepository::SCOPE_REPAIR )
		);

		$first_pump = $with_zero_enqueue(
			static function () use ( $rebuild, $run_id ): bool {
				$rebuild->handle_batch( $run_id, WorkRepository::SCOPE_BASELINE );
				return true;
			}
		);
		$acked_99 = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Install::work_table() . ' WHERE run_id = %s AND scope = %s AND status = %s',
				$run_id,
				WorkRepository::SCOPE_BASELINE,
				WorkRepository::STATUS_ACKED
			)
		);
		$status_99 = RebuildJob::status();
		$check(
			'exactitud 99/100 bloquea el RENAME',
			99 === $acked_99
			&& 1 === $repo_primary->outstanding_count( $run_id, WorkRepository::SCOPE_BASELINE )
			&& 1 === $repo_primary->active_lease_count( $run_id, WorkRepository::SCOPE_BASELINE )
			&& is_array( $status_99 )
			&& RebuildJob::PHASE_BUILDING === (string) $status_99['phase']
			&& Install::TOPOLOGY_READY_SWAP === Install::classify_topology()
			&& in_array( RebuildJob::HOOK_BATCH, $first_pump['hits'], true )
		);

		$sql_must(
			$secondary->query(
				$secondary->prepare(
					'UPDATE ' . Install::work_table() . ' SET lease_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = %d AND lease_token = %s',
					(int) $held_claim['id'],
					(string) $held_claim['lease_token']
				)
			),
			'No se pudo expirar la lease 100',
			$secondary
		);
		$must( 1 === $repo_secondary->recover_expired( WorkRepository::SCOPE_BASELINE, $run_id ), 'No se recupero la lease 100.' );

		$catchup = $with_zero_enqueue(
			static function () use ( $rebuild, $run_id ): bool {
				$rebuild->handle_batch( $run_id, WorkRepository::SCOPE_BASELINE );
				return true;
			}
		);
		$status_catchup = RebuildJob::status();
		$check(
			'100/100 converge a CATCHING_UP pero enqueue=0 conserva repairs',
			0 === $repo_primary->outstanding_count( $run_id, WorkRepository::SCOPE_BASELINE )
			&& 2 === $repo_primary->outstanding_count( $run_id, WorkRepository::SCOPE_REPAIR )
			&& is_array( $status_catchup )
			&& RebuildJob::PHASE_CATCHING_UP === (string) $status_catchup['phase']
			&& Install::TOPOLOGY_READY_SWAP === Install::classify_topology()
			&& in_array( RebuildJob::HOOK_BATCH, $catchup['hits'], true )
		);

		$repair_before_stale = $repo_primary->outstanding_count( $run_id, WorkRepository::SCOPE_REPAIR );
		// Los dos `handle_batch` que cierran el run van envueltos, igual que los
		// anteriores. NO es cosmética: el gate afirma cinco veces que el protocolo
		// durable NO se apoya en Action Scheduler (`0 === $search_action_count()`),
		// y esa afirmación sólo es cierta si TODO el flujo declara lo que habría
		// encolado en vez de encolarlo. Sin envolver, el batch que publica dispara
		// `wake_frame()` y deja dos acciones vivas —`akb_search_pump_live` y
		// `akb_search_editorial_discount_boundary`—, que tumbaban la precondición
		// del Reconciler ~150 líneas más abajo. El gate llevaba días en rojo por
		// esto y nadie lo vio, porque el riel de empaquetado no lo invoca.
		$stale_batch = $with_zero_enqueue(
			static function () use ( $rebuild, $run_id ): bool {
				$rebuild->handle_batch( 'stale-' . $run_id, WorkRepository::SCOPE_REPAIR );
				return true;
			}
		);
		unset( $stale_batch );
		$status_after_stale = RebuildJob::status();
		$check(
			'accion stale no avanza ni ACKea el run vigente',
			$repair_before_stale === $repo_primary->outstanding_count( $run_id, WorkRepository::SCOPE_REPAIR )
			&& is_array( $status_after_stale )
			&& $run_id === (string) $status_after_stale['run_id']
		);

		$final_batch = $with_zero_enqueue(
			static function () use ( $rebuild, $run_id ): bool {
				$rebuild->handle_batch( $run_id, WorkRepository::SCOPE_REPAIR );
				return true;
			}
		);
		unset( $final_batch );
		$final_doc_title = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT title FROM ' . Install::docs_table() . ' WHERE post_id = %d', $title_product )
		);
		$deleted_docs = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Install::docs_table() . ' WHERE post_id = %d', $delete_product )
		);
		$last_receipt = get_option( RebuildJob::OPTION_LAST, array() );
		$check(
			'rebuild converge con contenido vigente, borrado y recibo DONE',
			null === RebuildJob::status()
			&& $new_title === $final_doc_title
			&& 0 === $deleted_docs
			&& is_array( $last_receipt )
			&& RebuildJob::PHASE_DONE === (string) ( $last_receipt['phase'] ?? '' )
			&& Install::TOPOLOGY_CLEAN_PRE === Install::classify_topology()
		);
		$check(
			'manifest baseline/repair del run queda sin residuo',
			0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Install::work_table() . ' WHERE run_id = %s', $run_id ) )
		);

		// El gate integrado debe activar el objeto Business real por el filtro
		// público, no sólo adoptar su snapshot. Se hidrata el producto antes de
		// medir: el presupuesto cubre captura Search + invoke Business + final-check.
		$price_hook      = $GLOBALS['wp_filter']['akb_search_effective_price'] ?? null;
		$callback_groups = is_object( $price_hook ) && isset( $price_hook->callbacks ) && is_array( $price_hook->callbacks )
			? $price_hook->callbacks
			: ( is_array( $price_hook ) ? $price_hook : array() );
		$price_callbacks = array();
		foreach ( $callback_groups as $priority => $callbacks ) {
			foreach ( is_array( $callbacks ) ? $callbacks : array() as $callback ) {
				$price_callbacks[] = array(
					'priority'      => (int) $priority,
					'accepted_args' => is_array( $callback ) ? (int) ( $callback['accepted_args'] ?? 0 ) : 0,
					'function'      => is_array( $callback ) ? ( $callback['function'] ?? null ) : null,
				);
			}
		}
		$price_provider = 1 === count( $price_callbacks ) ? $price_callbacks[0]['function'] : null;
		$must(
			1 === count( $price_callbacks )
			&& 10 === $price_callbacks[0]['priority']
			&& 2 === $price_callbacks[0]['accepted_args']
			&& $price_provider instanceof SearchPriceProvider,
			'El filtro de precio no contiene exactamente el proveedor Business esperado.'
		);

		$provider_product = wc_get_product( $title_product );
		$must( $provider_product instanceof WC_Product, 'No se pudo hidratar el producto del gate de precio.' );
		$base_price_raw = $provider_product->get_price();
		$must(
			is_string( $base_price_raw ) || is_int( $base_price_raw ) || is_float( $base_price_raw ),
			'El producto del gate devolvió un precio base con forma inválida.'
		);
		$base_price = '' === $base_price_raw ? 0.0 : (float) $base_price_raw;
		$must(
			( '' === $base_price_raw || is_numeric( $base_price_raw ) )
			&& is_finite( $base_price )
			&& 0.0 <= $base_price,
			'El producto del gate devolvió un precio base inválido.'
		);
		$publication_reflector = new ReflectionClass( EditorialDiscountPublication::class );
		$invalidate_memo       = $publication_reflector->getMethod( 'invalidate_runtime_memo' );
		$invalidate_memo->setAccessible( true );
		$invalidate_memo->invoke( null );
		$queries_before = (int) $wpdb->num_queries;
		$price_capture  = EditorialDiscountPublication::capture_price_authority();
		$must( is_array( $price_capture ), 'Search no pudo capturar la autoridad real de precio.' );
		try {
			$effective_price = apply_filters( 'akb_search_effective_price', $base_price, $provider_product );
		} catch ( Throwable $throwable ) {
			throw new RuntimeException( 'El proveedor Business real lanzó: ' . $throwable->getMessage(), 0, $throwable );
		}
		$price_matches = EditorialDiscountPublication::price_authority_matches( $price_capture );
		$price_queries = (int) $wpdb->num_queries - $queries_before;
		$check(
			'proveedor Business ACTIVE conserva snapshot y presupuesto SQL <=11',
			is_float( $effective_price )
			&& is_finite( $effective_price )
			&& 0.0 <= $effective_price
			&& SearchPriceProvider::STATE_ACTIVE === $price_provider->state()
			&& (string) $price_capture['provider_token'] === $price_provider->snapshot_token()
			&& (array) $price_capture['price_snapshot'] === $price_provider->authoritative_snapshot()
			&& $price_matches
			&& 10 <= $price_queries
			&& 11 >= $price_queries
		);
		if ( 10 > $price_queries || 11 < $price_queries || ! $price_matches ) {
			$log(
				'  INFO  gate proveedor: ' . (string) wp_json_encode(
					array(
						'queries' => $price_queries,
						'state'   => $price_provider->state(),
						'matches' => $price_matches,
						'price'   => $effective_price,
					)
				)
			);
		}

		// Arrival puede traer backfill pendiente desde la copia fuente. Se hace
		// converger primero por su API real y el hash se toma después; la corrida
		// bajo prueba debe ser una auditoría limpia, no una migración encubierta.
		$arrival_preflight_last = -1;
		for ( $arrival_pass = 0; $arrival_pass < 4; ++$arrival_pass ) {
			$arrival_preflight_last = Arrival::seed_pending();
			if ( 0 === $arrival_preflight_last ) {
				break;
			}
		}
		$must( 0 === $arrival_preflight_last, 'Arrival no convergió antes del Reconciler.' );
		$wpdb->last_error = '';
		$arrival_pending = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 WHERE p.post_type = %s AND p.post_status = %s
				   AND EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} stock
					 WHERE stock.post_id = p.ID AND stock.meta_key = %s AND stock.meta_value = %s
				   )
				   AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} arrival
					 WHERE arrival.post_id = p.ID AND arrival.meta_key = %s
				   )",
				'product',
				'publish',
				'_stock_status',
				'onbackorder',
				Arrival::META_ONBACKORDER_AT
			)
		);
		$must(
			null !== $arrival_pending
			&& is_numeric( $arrival_pending )
			&& 0 === (int) $arrival_pending
			&& '' === trim( (string) $wpdb->last_error ),
			'La fuente cruda todavía muestra backfill Arrival pendiente.'
		);
		// La publicación editorial deja su propia solicitud de rebuild cuando el
		// reproof físico no casa, y NADIE la retira si la situación se arregla
		// después: en producción la consume el cron cada 5 min, pero aquí no corre
		// ningún cron. Medido: tras cerrar el run el serving está LISTO y las
		// huellas casan, y aun así la solicitud sigue en pie.
		//
		// Se consume por la API real —`Install::consume_pending_rebuild()`, la
		// misma que llama el init— con un planificador inyectado que ANOTA la
		// intención en vez de arrancar un rebuild entero: lo que se va a medir
		// abajo es el Reconciler, no un segundo rebuild. No se borra la option a
		// mano; y si la solicitud fuera legítima, el aserto de serving que sigue
		// lo delataría.
		$rebuild_pedido_al_cerrar = null;
		$with_zero_enqueue(
			static function () use ( &$rebuild_pedido_al_cerrar, $mature_pending_rebuild ): bool {
				if ( null === Install::pending_rebuild_request() ) {
					return true;
				}
				$mature_pending_rebuild();
				Install::consume_pending_rebuild(
					static function ( string $token, int $seq ) use ( &$rebuild_pedido_al_cerrar ): string {
						$rebuild_pedido_al_cerrar = array(
							'token' => $token,
							'seq'   => $seq,
						);
						return 'gate-consumo-' . substr( $token, 0, 8 );
					}
				);
				return true;
			}
		);
		$check(
			'la solicitud editorial de cierre se consume y el serving queda listo',
			null === Install::pending_rebuild_request()
			&& EditorialDiscountPublication::serving_ready()
		);

		delete_option( Reconciler::OPTION_LAST );
		delete_option( Reconciler::OPTION_LAST_ERROR );
		delete_transient( Reconciler::ALERT_TRANSIENT );
		// Un aserto de seis condiciones que sólo dice «no se cumplió» cuesta una
		// tarde: el mensaje enumera cuál de las seis falla y con qué valor.
		$precondicion = array(
			'rebuild_status'         => RebuildJob::status(),
			'topology'               => Install::classify_topology(),
			'pending_rebuild'        => Install::pending_rebuild_request(),
			'pending_rebuild_ready'  => Install::pending_rebuild_is_ready(),
			'global_outstanding'     => $global_outstanding_count(),
			'search_actions'         => $search_action_count(),
			'search_actions_detalle' => $search_action_detail(),
		);
		$must(
			null === $precondicion['rebuild_status']
			&& Install::TOPOLOGY_CLEAN_PRE === $precondicion['topology']
			&& null === $precondicion['pending_rebuild']
			&& $precondicion['pending_rebuild_ready']
			&& 0 === $precondicion['global_outstanding']
			&& 0 === $precondicion['search_actions'],
			'La precondición limpia del Reconciler no se cumplió: ' . (string) wp_json_encode( $precondicion )
		);

		$logical_before    = $logical_reconciler_hash();
		$reconcile_sources = new ProductSourceAuthority( $wpdb );
		$reconcile_builder = new DocumentBuilder();
		$reconcile_indexer = new Indexer( $reconcile_builder, $repo_primary, $runtime_fence, $reconcile_sources );
		$reconciler        = new Reconciler( $reconcile_indexer, $reconcile_builder, $reconcile_sources );
		$reconcile_summary = $reconciler->run();
		$logical_after     = $logical_reconciler_hash();
		$reconcile_last    = get_option( Reconciler::OPTION_LAST, null );
		$reconcile_alert   = get_transient( Reconciler::ALERT_TRANSIENT );
		$summary_keys      = array(
			'ran_at',
			'missing',
			'orphaned',
			'stale',
			'orphan_facets',
			'divergent',
			'checked',
			'arrival_seeded',
			'repair_queued',
			'ok',
			'error',
		);
		$check(
			'Reconciler real verifica 10 productos sin divergencia ni mutación lógica',
			$summary_keys === array_keys( $reconcile_summary )
			&& isset( $reconcile_summary['ran_at'] )
			&& is_int( $reconcile_summary['ran_at'] )
			&& 0 < $reconcile_summary['ran_at']
			&& true === $reconcile_summary['ok']
			&& '' === $reconcile_summary['error']
			&& 10 === $reconcile_summary['checked']
			&& 0 === $reconcile_summary['missing']
			&& 0 === $reconcile_summary['orphaned']
			&& 0 === $reconcile_summary['stale']
			&& 0 === $reconcile_summary['orphan_facets']
			&& 0 === $reconcile_summary['divergent']
			&& 0 === $reconcile_summary['arrival_seeded']
			&& 0 === $reconcile_summary['repair_queued']
			&& $reconcile_summary === $reconcile_last
			&& false === get_option( Reconciler::OPTION_LAST_ERROR, false )
			&& false === $reconcile_alert
			&& hash_equals( $logical_before, $logical_after )
			&& null === Install::pending_rebuild_request()
			&& Install::pending_rebuild_is_ready()
			&& 0 === $global_outstanding_count()
			&& 0 === $search_action_count()
		);
		if ( true !== ( $reconcile_summary['ok'] ?? false ) || ! hash_equals( $logical_before, $logical_after ) ) {
			$log(
				'  INFO  gate Reconciler: ' . (string) wp_json_encode(
					array(
						'summary'        => $reconcile_summary,
						'logical_before' => $logical_before,
						'logical_after'  => $logical_after,
						'alert'          => $reconcile_alert,
					)
				)
			);
		}

		// Aunque un estado CLEANUP recuperado tenga publicación exacta, nunca
		// puede retirarse mientras reaparezca deuda del run: sin OPTION_STATE esa
		// fila quedaría sin owner ni vía de reconciliación.
		$must( $repo_primary->mark_repair_dirty( $run_id, $title_product ), 'No se pudo preparar deuda terminal controlada.' );
		$debt_state = $state_for(
				$run_id,
				RebuildJob::PHASE_CLEANUP,
				(string) ( $last_receipt['generation_target'] ?? '' ),
				array(
					'generation' => true,
					'rest'       => true,
					'price'      => true,
					'semantic'   => true,
					'history'    => true,
				)
		);
		$debt_state['semantic_fingerprint'] = (string) ( $last_receipt['semantic_fingerprint'] ?? '' );
		$persist_state( $debt_state );
		$rebuild->handle_recovery();
		$debt_status = RebuildJob::status();
		$check(
			'estado CLEANUP no desaparece con deuda repair no ACK',
			is_array( $debt_status )
			&& RebuildJob::PHASE_CLEANUP === (string) ( $debt_status['phase'] ?? '' )
			&& 'done_work_debt_unresolved' === (string) ( $debt_status['error'] ?? '' )
			&& 1 === $repo_primary->outstanding_count( $run_id, WorkRepository::SCOPE_REPAIR )
		);
		$delete_run_work( $run_id );
		delete_option( RebuildJob::OPTION_STATE );
		wp_cache_delete( RebuildJob::OPTION_STATE, 'options' );

		// Crash pre-RENAME: READY_SWAP + SWAP_INTENT vuelve a CATCHING_UP.
		$must( Install::create_shadow_tables(), 'No se pudo crear sombra para recovery pre-RENAME.' );
		$pre_run = $new_run_id( 'pre-rename' );
		$must( $repo_primary->mark_repair_dirty( $pre_run, $title_product ), 'No se pudo crear repair pre-RENAME.' );
		$persist_state( $state_for( $pre_run, RebuildJob::PHASE_SWAP_INTENT, 'gate-' . $pre_run ) );
		$pre_recovery = $with_zero_enqueue(
			static function () use ( $rebuild ): bool {
				$rebuild->handle_recovery();
				return true;
			}
		);
		$pre_status = RebuildJob::status();
		$check(
			'recovery pre-RENAME revalida sin cortar deuda',
			is_array( $pre_status )
			&& RebuildJob::PHASE_CATCHING_UP === (string) $pre_status['phase']
			&& 1 === $repo_primary->outstanding_count( $pre_run, WorkRepository::SCOPE_REPAIR )
			&& Install::TOPOLOGY_READY_SWAP === Install::classify_topology()
			&& in_array( RebuildJob::HOOK_BATCH, $pre_recovery['hits'], true )
		);
		delete_option( RebuildJob::OPTION_STATE );
		$delete_run_work( $pre_run );
		$must( Install::drop_shadow_tables(), 'No se pudo limpiar READY_SWAP controlado.' );

		// Crash despues del RENAME y antes de persistir SWAPPED.
		$must( Install::create_shadow_tables(), 'No se pudo crear sombra post-RENAME.' );
		$must( $runtime_fence->acquire( 0 ), 'No se pudo cercar RENAME post-crash.' );
		try {
			$must(
				Install::RENAME_RESULT_SWAPPED === Install::rename_keep_old_result( null, array( $runtime_fence, 'assert_owned' ) ),
				'No se pudo preparar topologia SWAPPED.'
			);
		} finally {
			$runtime_fence->release();
		}
		$post_run    = $new_run_id( 'post-rename' );
		$post_target = 'gate-' . $post_run;
		$persist_state( $state_for( $post_run, RebuildJob::PHASE_SWAP_INTENT, $post_target ) );
		// `index_product()` se retiró en 0.1.64 (sin llamador en producción): el camino vivo.
		$post_writer_ok = WorkRepository::OUTCOME_INDEXED === $indexer->index_product_outcome( $title_product );
		$check(
			'writer live post-RENAME no crea repair contra sombra inexistente',
			$post_writer_ok
			&& 0 === $repo_primary->outstanding_count( $post_run, WorkRepository::SCOPE_REPAIR )
		);
		$rebuild->handle_recovery();
		$post_receipt = get_option( RebuildJob::OPTION_LAST, array() );
		$check(
			'recovery post-RENAME completa publicacion y cleanup idempotente',
			null === RebuildJob::status()
			&& Install::TOPOLOGY_CLEAN_PRE === Install::classify_topology()
			&& is_array( $post_receipt )
			&& $post_run === (string) ( $post_receipt['run_id'] ?? '' )
			&& RebuildJob::PHASE_DONE === (string) ( $post_receipt['phase'] ?? '' )
		);

		// Un estado CLEANUP de una versión anterior no trae el sello semántico.
		// Debe reabrirse a PUBLISHING conservando _old, jamás aceptar los cuatro
		// flags legacy y ejecutar DROP antes de obtener evidencia nueva.
		$must( Install::create_shadow_tables(), 'No se pudo crear sombra para CLEANUP legacy.' );
		$must( $runtime_fence->acquire( 0 ), 'No se pudo cercar RENAME de CLEANUP legacy.' );
		try {
			$must(
				Install::RENAME_RESULT_SWAPPED === Install::rename_keep_old_result( null, array( $runtime_fence, 'assert_owned' ) ),
				'No se pudo preparar SWAPPED para CLEANUP legacy.'
			);
		} finally {
			$runtime_fence->release();
		}
		$legacy_cleanup_run = $new_run_id( 'legacy-cleanup' );
		$persist_state(
			$state_for(
				$legacy_cleanup_run,
				RebuildJob::PHASE_CLEANUP,
				'gate-' . $legacy_cleanup_run,
				array(
					'generation' => true,
					'rest'       => true,
					'price'      => true,
					'history'    => true,
				)
			)
		);
		$rebuild->handle_recovery();
		$legacy_cleanup_status = RebuildJob::status();
		$check(
			'CLEANUP legacy sin fingerprint conserva _old y vuelve a PUBLISHING',
			is_array( $legacy_cleanup_status )
			&& RebuildJob::PHASE_PUBLISHING === (string) ( $legacy_cleanup_status['phase'] ?? '' )
			&& 'cleanup_publication_revalidation_required' === (string) ( $legacy_cleanup_status['error'] ?? '' )
			&& Install::TOPOLOGY_SWAPPED === Install::classify_topology()
			&& Install::old_schema_ready()
		);
		delete_option( RebuildJob::OPTION_STATE );
		wp_cache_delete( RebuildJob::OPTION_STATE, 'options' );
		$must( $runtime_fence->acquire( 0 ), 'No se pudo cercar limpieza de CLEANUP legacy.' );
		try {
			$must(
				Install::cleanup_old( array( $runtime_fence, 'assert_owned' ) ),
				'No se pudo retirar _old de CLEANUP legacy tras verificarlo.'
			);
		} finally {
			$runtime_fence->release();
		}

		// Crash a mitad de los ACK de PUBLISHING: los flags ya verificados no se
		// repiten y el receipt faltante se completa antes de retirar _old.
		$must( Install::create_shadow_tables(), 'No se pudo crear sombra para recovery de ACK.' );
		$must( $runtime_fence->acquire( 0 ), 'No se pudo cercar RENAME de ACK.' );
		try {
			$must(
				Install::RENAME_RESULT_SWAPPED === Install::rename_keep_old_result( null, array( $runtime_fence, 'assert_owned' ) ),
				'No se pudo preparar SWAPPED para ACK.'
			);
		} finally {
			$runtime_fence->release();
		}
		$ack_run    = $new_run_id( 'partial-ack' );
		$ack_target = 'gate-' . $ack_run;
		$must( Install::bump_index_generation( $ack_target ), 'No se pudo preparar ACK generation.' );
		$persist_state(
			$state_for(
				$ack_run,
				RebuildJob::PHASE_PUBLISHING,
				$ack_target,
				array(
					'generation' => true,
					'rest'       => true,
					'price'      => true,
					'history'    => false,
				)
			)
		);
		$rebuild->handle_recovery();
		$ack_receipt = get_option( RebuildJob::OPTION_LAST, array() );
		$check(
			'recovery de ACK parcial persiste receipt antes de cleanup',
			null === RebuildJob::status()
			&& Install::TOPOLOGY_CLEAN_PRE === Install::classify_topology()
			&& is_array( $ack_receipt )
			&& $ack_run === (string) ( $ack_receipt['run_id'] ?? '' )
			&& RebuildJob::PHASE_DONE === (string) ( $ack_receipt['phase'] ?? '' )
		);

		// Una topologia parcial es BLOCKED y recovery no destruye el unico _new.
		$ambiguous_run   = $new_run_id( 'ambiguous' );
		$ambiguous_table = Install::docs_table() . Install::SHADOW_SUFFIX;
		$sql_must(
			$wpdb->query(
				'CREATE TABLE ' . $quote_identifier( $ambiguous_table ) . ' LIKE ' . $quote_identifier( Install::docs_table() )
			),
			'No se pudo preparar topologia ambigua controlada'
		);
		$persist_state( $state_for( $ambiguous_run, RebuildJob::PHASE_BUILDING ) );
		$rebuild->handle_recovery();
		$ambiguous_status = RebuildJob::status();
		$check(
			'topologia ambigua queda BLOCKED sin DROP ni RENAME',
			is_array( $ambiguous_status )
			&& RebuildJob::PHASE_BLOCKED === (string) $ambiguous_status['phase']
			&& Install::TOPOLOGY_AMBIGUOUS === Install::classify_topology()
			&& $table_exists( $wpdb, $ambiguous_table )
			&& ! $table_exists( $wpdb, Install::docs_table() . Install::OLD_SUFFIX )
		);
		delete_option( RebuildJob::OPTION_STATE );
		$sql_must( $wpdb->query( 'DROP TABLE ' . $quote_identifier( $ambiguous_table ) ), 'No se pudo retirar el artefacto ambiguo de la fixture' );
		$check( 'fixture vuelve a topologia limpia tras inspeccion', Install::TOPOLOGY_CLEAN_PRE === Install::classify_topology() );
	}

	$delete_search_actions();
	$global_outstanding = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Install::work_table() . ' WHERE status <> %s', WorkRepository::STATUS_ACKED )
	);
	$global_leases = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . Install::work_table() . ' WHERE status = %s AND lease_until IS NOT NULL AND lease_until > UTC_TIMESTAMP()',
			WorkRepository::STATUS_LEASED
		)
	);
	$check( 'sin deuda work outstanding', 0 === $global_outstanding );
	$check( 'sin leases work activas', 0 === $global_leases );
	$check( 'sin estado de rebuild', null === RebuildJob::status() );
	$check( 'sin tablas _new/_old', Install::TOPOLOGY_CLEAN_PRE === Install::classify_topology() );
	$check( 'sin acciones Search residuales', 0 === $search_action_count() );

	$sql_must( $wpdb->query( 'DELETE FROM ' . Install::work_table() ), 'No se pudo retirar filas ACK de la fixture' );
	$check( 'tabla work sin filas residuales', 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Install::work_table() ) );

	$default_lock_name = ( new WriteFence( $wpdb ) )->name();
	$install_lock_name = 'akb_search_install_' . md5( $database . '|' . $wpdb->prefix );
	$check(
		'sin named locks residuales',
		null === $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $lock_name ) )
		&& null === $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $default_lock_name ) )
		&& null === $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $install_lock_name ) )
	);
} catch ( Throwable $throwable ) {
	$thrown = $throwable;
} finally {
	if ( $fence_primary instanceof WriteFence && $fence_primary->is_owned() ) {
		$fence_primary->release();
	}
	if ( $fence_secondary instanceof WriteFence && $fence_secondary->is_owned() ) {
		$fence_secondary->release();
	}
	if ( $runtime_fence instanceof WriteFence && $runtime_fence->is_owned() ) {
		$runtime_fence->release();
	}
	if ( $price_fence_primary instanceof WriteFence && $price_fence_primary->is_owned() ) {
		$price_fence_primary->release();
	}
	if ( $price_fence_secondary instanceof WriteFence && $price_fence_secondary->is_owned() ) {
		$price_fence_secondary->release();
	}
	if ( $price_crash_connection instanceof wpdb ) {
		$price_crash_connection->close();
	}
	$delete_search_actions();
	if ( $secondary instanceof wpdb ) {
		$secondary->close();
	}
}

if ( $thrown instanceof Throwable ) {
	WP_CLI::error( get_class( $thrown ) . ': ' . $thrown->getMessage() );
}
if ( ! empty( $failures ) ) {
	WP_CLI::error( sprintf( "%d comprobacion(es) fallaron:\n  - %s", count( $failures ), implode( "\n  - ", $failures ) ) );
}

WP_CLI::success( "Gate MariaDB Search v2 OK ({$mode})." );
