<?php
/**
 * Prueba dedicada y fail-closed del recovery/ciclo 0.1.14 <-> 0.1.12.
 *
 * Este archivo sólo se ejecuta con WP-CLI sobre una MariaDB efímera. El modo
 * recover-ready-swap se arranca con --skip-plugins/--skip-themes y carga
 * manualmente el árbol Search sellado para evitar hooks antes de validar el
 * preimage. Los gates posteriores se ejecutan con los plugins normales.
 *
 * @package Akibara\Search\Release
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit( 1 );

use Akibara\Search\EditorialDiscountPublication;
use Akibara\Search\Install;
use Akibara\Search\PriceBuckets;
use Akibara\Search\RebuildJob;
use Akibara\Search\SemanticPublication;
use Akibara\Search\Support\RestCache;
use Akibara\Search\WriteFence;

const AKB_RECOVERY_AUDITED_RUN_ID       = '8dde2d65-6029-4062-89cf-7fdd5af90c77';
const AKB_RECOVERY_AUDITED_STATE_SHA256 = 'b243ddb58271e4dac334ff5df18ca863b352b6119721f3d562c547897838fb0d';
const AKB_RECOVERY_AUDITED_STATE_BYTES  = '{"run_id":"8dde2d65-6029-4062-89cf-7fdd5af90c77","started_at":1785986256,"watermark":"2026-08-06 03:17:36","offset":8,"updated_at":1785988285,"protocol":2,"phase":"BLOCKED","error":"legacy_or_unreadable_state","topology":"ready_swap","heartbeat_at":1785988285}';
const AKB_RECOVERY_PRICE_BUCKETS_SHA256 = 'bbf68fbe0a982bd89e4bc0d1c2e011779029484e88f6a0d926b8f8b8fd4fb7c3';

/**
 * @param bool   $condition Condición requerida.
 * @param string $message   Diagnóstico sin secretos.
 * @return void
 */
function akb_recovery_require( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * @param string $name Nombre de variable requerida.
 * @return string
 */
function akb_recovery_env( string $name ): string {
	$value = getenv( $name );
	akb_recovery_require( is_string( $value ) && '' !== trim( $value ), 'Falta variable requerida: ' . $name );
	return trim( (string) $value );
}

/**
 * @param string $identifier Identificador SQL interno.
 * @return string
 */
function akb_recovery_identifier( string $identifier ): string {
	akb_recovery_require( 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $identifier ), 'Identificador SQL inseguro.' );
	return '`' . $identifier . '`';
}

/**
 * Lee una option sin object cache y distingue fila ausente de fallo SQL.
 *
 * @param string $name Option exacta.
 * @return string|null Bytes persistidos o null si está ausente.
 */
function akb_recovery_raw_option( string $name ): ?string {
	global $wpdb;

	$wpdb->last_error = '';
	$rows              = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s ORDER BY option_id",
			$name
		)
	);
	akb_recovery_require( is_array( $rows ) && '' === trim( (string) $wpdb->last_error ), 'No se pudo leer option SQL: ' . $name );
	akb_recovery_require( count( $rows ) <= 1, 'Option duplicada: ' . $name );
	return array_key_exists( 0, $rows ) ? (string) $rows[0] : null;
}

/**
 * @param string|null $raw Bytes de option.
 * @return mixed
 */
function akb_recovery_decode_option( ?string $raw ) {
	if ( null === $raw ) {
		return null;
	}
	return maybe_unserialize( $raw );
}

/**
 * Decodifica sólo arrays serializados canónicos, sin instanciar clases.
 *
 * @param string|null $raw Bytes de option.
 * @return array<mixed>|null
 */
function akb_recovery_decode_canonical_array( ?string $raw ): ?array {
	if ( ! is_string( $raw ) || 1 !== preg_match( '/^a:[0-9]+:/D', $raw ) ) {
		return null;
	}
	$value = @unserialize( $raw, array( 'allowed_classes' => false ) );
	return is_array( $value ) && serialize( $value ) === $raw ? $value : null;
}

/**
 * @return array{buckets: array{present: bool, raw: string, autoload: string}, mode: array{present: bool, raw: string, autoload: string}}
 */
function akb_recovery_price_pair_snapshot(): array {
	global $wpdb;

	$names = array( 'akb_search_precio_buckets', 'akb_search_precio_mode' );
	$wpdb->last_error = '';
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN (%s, %s) ORDER BY BINARY option_name",
			...$names
		),
		ARRAY_A
	);
	akb_recovery_require( is_array( $rows ) && '' === trim( (string) $wpdb->last_error ), 'No se pudo leer el par derivado de precio.' );
	$snapshot = array(
		'buckets' => array( 'present' => false, 'raw' => '', 'autoload' => '' ),
		'mode'    => array( 'present' => false, 'raw' => '', 'autoload' => '' ),
	);
	$seen = array();
	foreach ( $rows as $row ) {
		$name = (string) ( $row['option_name'] ?? '' );
		akb_recovery_require( in_array( $name, $names, true ) && ! isset( $seen[ $name ] ), 'Par derivado de precio ambiguo.' );
		$key = 'akb_search_precio_buckets' === $name ? 'buckets' : 'mode';
		$snapshot[ $key ] = array(
			'present'  => true,
			'raw'      => (string) ( $row['option_value'] ?? '' ),
			'autoload' => (string) ( $row['autoload'] ?? '' ),
		);
		$seen[ $name ] = true;
	}
	return $snapshot;
}

/**
 * @param array<mixed> $snapshot Foto exacta del par.
 * @param string       $phase    preimage|normalized.
 * @return bool
 */
function akb_recovery_price_pair_matches( array $snapshot, string $phase ): bool {
	if ( array( 'buckets', 'mode' ) !== array_keys( $snapshot ) ) {
		return false;
	}
	foreach ( $snapshot as $state ) {
		if ( ! is_array( $state ) || array( 'present', 'raw', 'autoload' ) !== array_keys( $state )
			|| ! is_bool( $state['present'] ) || ! is_string( $state['raw'] ) || ! is_string( $state['autoload'] ) ) {
			return false;
		}
	}
	if ( ! $snapshot['buckets']['present']
		|| ! hash_equals( AKB_RECOVERY_PRICE_BUCKETS_SHA256, hash( 'sha256', $snapshot['buckets']['raw'] ) ) ) {
		return false;
	}
	if ( 'preimage' === $phase ) {
		return 'off' === $snapshot['buckets']['autoload']
			&& false === $snapshot['mode']['present']
			&& '' === $snapshot['mode']['raw']
			&& '' === $snapshot['mode']['autoload'];
	}
	if ( 'normalized' === $phase ) {
		return 'no' === $snapshot['buckets']['autoload']
			&& true === $snapshot['mode']['present']
			&& 'auto' === $snapshot['mode']['raw']
			&& 'no' === $snapshot['mode']['autoload'];
	}
	return false;
}

/**
 * @param string $phase          preimage|normalized.
 * @param bool   $require_derived Comparar bytes persistidos con el índice v2.
 * @return array<mixed>
 */
function akb_recovery_require_price_pair( string $phase, bool $require_derived ): array {
	$snapshot = akb_recovery_price_pair_snapshot();
	akb_recovery_require( akb_recovery_price_pair_matches( $snapshot, $phase ), 'Par derivado de precio divergió: ' . $phase );
	if ( $require_derived ) {
		$stored  = akb_recovery_decode_canonical_array( $snapshot['buckets']['raw'] );
		$derived = PriceBuckets::derive_from_index();
		akb_recovery_require(
			is_array( $stored ) && is_array( $derived ) && $stored === array_values( $derived ),
			'Buckets persistidos no equivalen a la derivación del índice v2.'
		);
	}
	return $snapshot;
}

/**
 * @return array{live: array<string, bool>, new: array<string, bool>, old: array<string, bool>, classification: string}
 */
function akb_recovery_topology(): array {
	global $wpdb;

	$base = array(
		'docs'   => $wpdb->prefix . 'akb_search_docs',
		'tokens' => $wpdb->prefix . 'akb_search_tokens',
		'facets' => $wpdb->prefix . 'akb_search_facet_index',
	);
	$lookup = array();
	foreach ( array( 'live' => '', 'new' => '_new', 'old' => '_old' ) as $family => $suffix ) {
		foreach ( $base as $logical => $table ) {
			$lookup[ $table . $suffix ] = array( $family, $logical );
		}
	}
	$placeholders      = implode( ', ', array_fill( 0, count( $lookup ), '%s' ) );
	$wpdb->last_error = '';
	$present           = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders}) ORDER BY BINARY TABLE_NAME",
			...array_keys( $lookup )
		)
	);
	akb_recovery_require( is_array( $present ) && '' === trim( (string) $wpdb->last_error ), 'Lectura de topología falló.' );
	$topology = array(
		'live' => array_fill_keys( array_keys( $base ), false ),
		'new'  => array_fill_keys( array_keys( $base ), false ),
		'old'  => array_fill_keys( array_keys( $base ), false ),
	);
	foreach ( $present as $table ) {
		$table = (string) $table;
		akb_recovery_require( isset( $lookup[ $table ] ), 'Topología devolvió tabla inesperada.' );
		list( $family, $logical )       = $lookup[ $table ];
		$topology[ $family ][ $logical ] = true;
	}

	$live_all = ! in_array( false, $topology['live'], true );
	$new_all  = ! in_array( false, $topology['new'], true );
	$old_all  = ! in_array( false, $topology['old'], true );
	$new_none = ! in_array( true, $topology['new'], true );
	$old_none = ! in_array( true, $topology['old'], true );
	$class     = 'ambiguous';
	if ( $live_all && $new_none && $old_none ) {
		$class = 'clean_pre';
	} elseif ( $live_all && $new_all && $old_none ) {
		$class = 'ready_swap';
	} elseif ( $live_all && $new_none && $old_all ) {
		$class = 'swapped';
	}

	return array_merge( $topology, array( 'classification' => $class ) );
}

/**
 * Compara por EXCEPT ambos sentidos; las tablas tienen PK, por lo que igualdad
 * de conjuntos equivale a igualdad de filas. indexed_at se omite sólo en docs.
 *
 * @param string $left    Tabla viva.
 * @param string $right   Tabla sombra.
 * @param string $columns Columnas internas fijas.
 * @return void
 */
function akb_recovery_require_equal_sets( string $left, string $right, string $columns ): void {
	global $wpdb;

	$left  = akb_recovery_identifier( $left );
	$right = akb_recovery_identifier( $right );
	$sql   = "SELECT COUNT(*) FROM ((SELECT {$columns} FROM {$left} EXCEPT SELECT {$columns} FROM {$right}) UNION ALL (SELECT {$columns} FROM {$right} EXCEPT SELECT {$columns} FROM {$left})) AS akb_diff";
	$wpdb->last_error = '';
	$count             = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identificadores/columnas internos.
	akb_recovery_require( '' === trim( (string) $wpdb->last_error ), 'EXCEPT de preimage falló.' );
	akb_recovery_require( is_numeric( $count ) && 0 === (int) $count, 'Live y shadow no son byte-lógicamente equivalentes.' );
}

/**
 * Proyección exacta usada por EXCEPT. Se expone como función pura para que el
 * test dry-run pueda refutar una regresión case/acento sin abrir MariaDB.
 *
 * @return array{docs: string, tokens: string, facets: string}
 */
function akb_recovery_preimage_projections(): array {
	// HEX fuerza igualdad de bytes aun bajo collations *_ci; los escalares
	// numéricos conservan la comparación tipada de MariaDB.
	return array(
		'docs'   => 'post_id,HEX(title),HEX(title_norm),HEX(serie_norm),HEX(autores_norm),HEX(sku),HEX(sku_norm),tomo,stock_rank,menu_order,price_effective,visible,HEX(url),HEX(thumb_url),HEX(price_html)',
		'tokens' => 'HEX(token),field,post_id',
		'facets' => 'HEX(facet),post_id,HEX(value),HEX(label),value_num,term_id,parent_id',
	);
}

/** @return void */
function akb_recovery_require_preimage_equivalence(): void {
	global $wpdb;

	$prefix      = $wpdb->prefix;
	$projections = akb_recovery_preimage_projections();
	akb_recovery_require_equal_sets( $prefix . 'akb_search_docs', $prefix . 'akb_search_docs_new', $projections['docs'] );
	akb_recovery_require_equal_sets( $prefix . 'akb_search_tokens', $prefix . 'akb_search_tokens_new', $projections['tokens'] );
	akb_recovery_require_equal_sets( $prefix . 'akb_search_facet_index', $prefix . 'akb_search_facet_index_new', $projections['facets'] );
}

/**
 * Validador puro de los bytes auditados; el test dry-run ejercita mutaciones.
 *
 * @param string $raw               Bytes exactos de option_value.
 * @param string $expected_run_id   Run auditado.
 * @param string $expected_raw_hash SHA-256 de los bytes exactos.
 * @return array<string, mixed>
 */
function akb_recovery_validate_exact_state_bytes( string $raw, string $expected_run_id, string $expected_raw_hash ): array {
	akb_recovery_require( AKB_RECOVERY_AUDITED_RUN_ID === $expected_run_id, 'run_id solicitado no es el auditado.' );
	akb_recovery_require( AKB_RECOVERY_AUDITED_STATE_SHA256 === $expected_raw_hash, 'SHA solicitado no es el auditado.' );
	akb_recovery_require( hash_equals( AKB_RECOVERY_AUDITED_STATE_BYTES, $raw ), 'Bytes del estado no son el preimage auditado.' );
	akb_recovery_require( hash_equals( $expected_raw_hash, hash( 'sha256', $raw ) ), 'Bytes del estado preimage divergieron.' );
	$state = json_decode( $raw, true );
	akb_recovery_require( is_array( $state ), 'Estado preimage no es JSON objeto.' );
	akb_recovery_require( $expected_run_id === (string) ( $state['run_id'] ?? '' ), 'run_id preimage divergente.' );
	akb_recovery_require( 2 === (int) ( $state['protocol'] ?? 0 ), 'Protocolo preimage no es v2.' );
	akb_recovery_require( 'BLOCKED' === (string) ( $state['phase'] ?? '' ), 'Fase preimage no es BLOCKED.' );
	akb_recovery_require( 'legacy_or_unreadable_state' === (string) ( $state['error'] ?? '' ), 'Error preimage divergente.' );
	akb_recovery_require( 'ready_swap' === (string) ( $state['topology'] ?? '' ), 'Topología persistida preimage divergente.' );
	return $state;
}

/**
 * @param string $expected_run_id   Run auditado.
 * @param string $expected_raw_hash SHA-256 de los bytes exactos.
 * @return array<string, mixed>
 */
function akb_recovery_require_exact_state( string $expected_run_id, string $expected_raw_hash ): array {
	$raw = akb_recovery_raw_option( RebuildJob::OPTION_STATE );
	akb_recovery_require( is_string( $raw ), 'Falta estado preimage de rebuild.' );
	return akb_recovery_validate_exact_state_bytes( $raw, $expected_run_id, $expected_raw_hash );
}

/**
 * @return array<string, mixed>
 */
function akb_recovery_pending_envelope(): array {
	$raw     = akb_recovery_raw_option( Install::OPTION_PENDING_REBUILD );
	$decoded = akb_recovery_decode_option( $raw );
	akb_recovery_require( is_array( $decoded ) && 2 === (int) ( $decoded['protocol'] ?? 0 ), 'Pending rebuild no usa sobre v2.' );
	$requests = $decoded['requests'] ?? null;
	akb_recovery_require( is_array( $requests ) && ! empty( $requests ), 'Pending rebuild v2 no contiene requests.' );
	akb_recovery_require( 1 === count( $requests ), 'Recovery debe producir una sola request schema_migration.' );
	$has_schema_migration = false;
	$max_not_before       = 0;
	foreach ( $requests as $request ) {
		akb_recovery_require( is_array( $request ), 'Request pending inválida.' );
		akb_recovery_require( 1 === preg_match( '/^[a-f0-9-]{16,64}$/D', (string) ( $request['token'] ?? '' ) ), 'Token pending inválido.' );
		akb_recovery_require( (int) ( $request['seq'] ?? 0 ) > 0, 'Seq pending inválido.' );
		$requested_at = (int) ( $request['requested_at'] ?? 0 );
		$not_before   = (int) ( $request['not_before'] ?? 0 );
		$now          = time();
		akb_recovery_require( $requested_at > 0 && $not_before >= $requested_at, 'Reloj pending inválido.' );
		akb_recovery_require( $requested_at <= $now && ( $now - $requested_at ) <= 120, 'Request pending no es inmediata a este rail.' );
		$max_not_before = max( $max_not_before, $not_before );
		if ( 'schema_migration' === (string) ( $request['reason'] ?? '' )
			&& '1.7.0' === (string) ( $request['version'] ?? '' ) ) {
			akb_recovery_require( 300 === $not_before - $requested_at, 'La migración no conserva gracia real de 300s.' );
			akb_recovery_require( $not_before > $now, 'La gracia schema_migration ya estaba vencida al acreditarla.' );
			$has_schema_migration = true;
		}
	}
	akb_recovery_require( $has_schema_migration, 'No existe request schema_migration 1.7.0.' );
	return array(
		'protocol'       => 2,
		'requests'       => count( $requests ),
		'max_not_before' => $max_not_before,
	);
}

/** @return array<string, mixed> */
function akb_recovery_recover_ready_swap(): array {
	global $wpdb;

	akb_recovery_require( defined( 'AKIBARA_SEARCH_VERSION' ) && '0.1.14' === AKIBARA_SEARCH_VERSION, 'Recovery exige Search 0.1.14.' );
	$expected_run_id    = akb_recovery_env( 'AKB_RECOVERY_EXPECTED_RUN_ID' );
	$expected_state_sha = akb_recovery_env( 'AKB_RECOVERY_EXPECTED_STATE_SHA256' );
	$expected_write_lock = akb_recovery_env( 'AKB_RECOVERY_EXPECTED_WRITE_LOCK' );
	$expected_install_lock = akb_recovery_env( 'AKB_RECOVERY_EXPECTED_INSTALL_LOCK' );
	akb_recovery_require( 1 === preg_match( '/^[a-f0-9]{64}$/D', $expected_state_sha ), 'SHA esperado inválido.' );

	$topology = akb_recovery_topology();
	akb_recovery_require( 'ready_swap' === $topology['classification'], 'Preimage no está exactamente ready_swap.' );
	akb_recovery_require_exact_state( $expected_run_id, $expected_state_sha );
	$price_pair = akb_recovery_require_price_pair( 'preimage', false );

	$wpdb->last_error = '';
	$install_free      = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $expected_install_lock ) );
	akb_recovery_require( '' === trim( (string) $wpdb->last_error ) && 1 === (int) $install_free, 'Install lock no está libre.' );

	$fence = new WriteFence( $wpdb );
	akb_recovery_require( $expected_write_lock === $fence->name(), 'Nombre de WriteFence divergente.' );
	akb_recovery_require( $fence->acquire( 0 ), 'No se pudo adquirir WriteFence.' );
	$released = false;
	try {
		$fence->assert_owned();
		akb_recovery_require( 'ready_swap' === akb_recovery_topology()['classification'], 'Topología cambió bajo fence.' );
		akb_recovery_require_exact_state( $expected_run_id, $expected_state_sha );
		akb_recovery_require_price_pair( 'preimage', false );
		akb_recovery_require_preimage_equivalence();

		// Revalidación pegada al DDL. No existe otra ruta DROP en este rail.
		$fence->assert_owned();
		akb_recovery_require( 'ready_swap' === akb_recovery_topology()['classification'], 'Topología cambió justo antes del DROP.' );
		akb_recovery_require_exact_state( $expected_run_id, $expected_state_sha );
		akb_recovery_require_price_pair( 'preimage', false );
		akb_recovery_require_preimage_equivalence();
		akb_recovery_require( Install::drop_shadow_tables( array( $fence, 'assert_owned' ) ), 'No se pudo retirar el set shadow exacto.' );
		akb_recovery_require( 'clean_pre' === akb_recovery_topology()['classification'], 'DROP no convergió a clean_pre.' );

		$legacy_options = array(
			'akb_search_rebuild_state',
			'akb_search_pending_rebuild',
			'akb_search_rebuild_ids',
			'akb_search_rebuild_live_writes',
		);
		$placeholders = implode( ', ', array_fill( 0, count( $legacy_options ), '%s' ) );
		$wpdb->last_error = '';
		$deleted          = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
				...$legacy_options
			)
		);
		akb_recovery_require( false !== $deleted && '' === trim( (string) $wpdb->last_error ), 'No se pudieron retirar sólo las options legacy.' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		foreach ( $legacy_options as $option ) {
			wp_cache_delete( $option, 'options' );
			akb_recovery_require( null === akb_recovery_raw_option( $option ), 'Option legacy sobrevivió: ' . $option );
		}

		$released = $fence->release();
		akb_recovery_require( $released, 'No se pudo liberar WriteFence antes de instalar.' );
	} finally {
		if ( ! $released && $fence->is_owned() ) {
			$fence->release();
		}
	}

	Install::maybe_install();
	akb_recovery_require( '1.7.0' === (string) akb_recovery_decode_option( akb_recovery_raw_option( 'akb_search_db_version' ) ), 'Installer no persistió DB 1.7.0 exacta.' );
	akb_recovery_require( '1.7.0-exact-1' === Install::schema_seal(), 'Installer no persistió sello 1.7.0-exact-1.' );
	akb_recovery_require( Install::runtime_schema_ready(), 'Installer no selló DB 1.7.0.' );
	akb_recovery_require( Install::live_schema_ready(), 'Schema live 1.7.0 inválido.' );
	akb_recovery_require( Install::work_schema_ready(), 'Tabla work 1.7.0 inválida.' );
	akb_recovery_require( 'clean_pre' === akb_recovery_topology()['classification'], 'Installer dejó topología no limpia.' );
	akb_recovery_require( null === akb_recovery_raw_option( RebuildJob::OPTION_STATE ), 'Installer recreó state antes de la gracia.' );
	akb_recovery_require_price_pair( 'preimage', false );
	$pending = akb_recovery_pending_envelope();

	return array(
		'mode'            => 'recover-ready-swap',
		'result'          => 'ok',
		'topology'        => 'clean_pre',
		'db_version'      => '1.7.0',
		'schema_seal'     => Install::schema_seal(),
		'pending'         => $pending,
		'expected_run_id' => $expected_run_id,
		'price_pair'      => array(
			'phase'            => 'preimage',
			'mode'             => 'absent-auto-semantics',
			'buckets_sha256'   => hash( 'sha256', $price_pair['buckets']['raw'] ),
			'buckets_autoload' => $price_pair['buckets']['autoload'],
			'mode_autoload'    => 'absent',
		),
	);
}

/**
 * Valida el receipt histórico del rebuild sin exigir que siga siendo la
 * generación corriente después de escrituras live ya publicadas. Su target es
 * determinístico y queda ligado a run_id + fingerprint semántico.
 *
 * @param mixed $receipt Receipt decodificado de OPTION_LAST.
 * @return bool
 */
function akb_recovery_final_rebuild_receipt_matches( $receipt ): bool {
	if ( ! is_array( $receipt ) ) {
		return false;
	}
	$expected_keys = array(
		'docs',
		'editorial_fingerprint',
		'editorial_receipt_acked',
		'editorial_wanted_seq',
		'expected',
		'finished_at',
		'generation_target',
		'phase',
		'publication_acked',
		'publication_protocol',
		'purge_ack_scope',
		'result',
		'run_id',
		'semantic_fingerprint',
		'semantic_fingerprint_acked',
		'started_at',
	);
	$actual_keys = array_keys( $receipt );
	sort( $expected_keys, SORT_STRING );
	sort( $actual_keys, SORT_STRING );
	if ( $expected_keys !== $actual_keys ) {
		return false;
	}

	$run_id      = $receipt['run_id'];
	$semantic    = $receipt['semantic_fingerprint'];
	$editorial   = $receipt['editorial_fingerprint'];
	$generation  = $receipt['generation_target'];
	$started_at  = $receipt['started_at'];
	$finished_at = $receipt['finished_at'];
	if ( ! is_string( $run_id )
		|| 1 !== preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $run_id )
		|| ! is_string( $semantic ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $semantic )
		|| ! is_string( $editorial ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $editorial )
		|| ! is_string( $generation )
		|| ! hash_equals( substr( 'akb-search-' . $run_id . '-' . $semantic, 0, 191 ), $generation )
		|| ! is_int( $started_at ) || $started_at <= 0
		|| ! is_int( $finished_at ) || $finished_at < $started_at ) {
		return false;
	}

	return 'ok' === $receipt['result']
		&& 'DONE' === $receipt['phase']
		&& 1 === $receipt['publication_protocol']
		&& true === $receipt['publication_acked']
		&& 'local_dispatch_only' === $receipt['purge_ack_scope']
		&& true === $receipt['semantic_fingerprint_acked']
		&& true === $receipt['editorial_receipt_acked']
		&& is_int( $receipt['editorial_wanted_seq'] )
		&& $receipt['editorial_wanted_seq'] > 0
		&& is_int( $receipt['docs'] )
		&& $receipt['docs'] > 0
		&& is_int( $receipt['expected'] )
		&& $receipt['expected'] > 0
		&& $receipt['docs'] === $receipt['expected'];
}

/** @return array<string, mixed> */
function akb_recovery_gate_v2(): array {
	global $wpdb;

	akb_recovery_require( defined( 'AKIBARA_SEARCH_VERSION' ) && '0.1.14' === AKIBARA_SEARCH_VERSION, 'Gate v2 exige Search 0.1.14.' );
	akb_recovery_require( defined( 'AKIBARA_BUSINESS_VERSION' ) && '1.99.16' === AKIBARA_BUSINESS_VERSION, 'Gate v2 exige Business 1.99.16.' );
	akb_recovery_require( '1.7.0' === (string) akb_recovery_decode_option( akb_recovery_raw_option( 'akb_search_db_version' ) ), 'Gate v2 exige DB 1.7.0 exacta.' );
	akb_recovery_require( '1.7.0-exact-1' === Install::schema_seal(), 'Gate v2 exige sello 1.7.0-exact-1.' );
	akb_recovery_require( Install::runtime_schema_ready(), 'DB/sello v2 inválido.' );
	akb_recovery_require( Install::live_schema_ready() && Install::work_schema_ready(), 'Schema físico v2 inválido.' );
	akb_recovery_require( 'clean_pre' === akb_recovery_topology()['classification'], 'Gate v2 exige clean_pre.' );
	akb_recovery_require( null === akb_recovery_raw_option( RebuildJob::OPTION_STATE ), 'State v2 no fue retirado.' );
	akb_recovery_require( null === akb_recovery_raw_option( Install::OPTION_PENDING_REBUILD ), 'Pending v2 no fue retirado.' );
	akb_recovery_require( null === RebuildJob::status(), 'Runtime todavía reporta rebuild activo.' );
	$price_pair = akb_recovery_require_price_pair( 'normalized', true );

	$work_table       = akb_recovery_identifier( $wpdb->prefix . 'akb_search_work' );
	$wpdb->last_error = '';
	$work_rows        = $wpdb->get_results(
		"SELECT status, COUNT(*) AS row_count, SUM(CASE WHEN COALESCE(last_error, '') <> '' THEN 1 ELSE 0 END) AS errors FROM {$work_table} GROUP BY status ORDER BY BINARY status", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla interna delimitada.
		ARRAY_A
	);
	akb_recovery_require( is_array( $work_rows ) && '' === trim( (string) $wpdb->last_error ), 'No se pudo leer deuda work.' );
	$work = array( 'pending' => 0, 'leased' => 0, 'blocked' => 0, 'acked' => 0, 'errors' => 0 );
	foreach ( $work_rows as $row ) {
		$status = (string) ( $row['status'] ?? '' );
		akb_recovery_require( in_array( $status, array( 'pending', 'leased', 'acked', 'blocked' ), true ), 'Status work desconocido.' );
		if ( array_key_exists( $status, $work ) ) {
			$work[ $status ] += (int) $row['row_count'];
		}
		$work['errors'] += (int) $row['errors'];
	}
	akb_recovery_require( 0 === $work['pending'] && 0 === $work['leased'] && 0 === $work['blocked'] && 0 === $work['errors'], 'Work conserva deuda/error.' );

	$eligible = RebuildJob::eligible_product_ids_snapshot();
	akb_recovery_require( is_array( $eligible ) && ! empty( $eligible ), 'Universo elegible v2 no es autoritativo/no vacío.' );
	$wpdb->last_error = '';
	$docs             = $wpdb->get_col( 'SELECT post_id FROM ' . akb_recovery_identifier( $wpdb->prefix . 'akb_search_docs' ) . ' ORDER BY post_id ASC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla interna delimitada.
	akb_recovery_require( is_array( $docs ) && '' === trim( (string) $wpdb->last_error ), 'No se pudieron leer IDs docs v2.' );
	$eligible = array_map( 'intval', $eligible );
	$docs     = array_map( 'intval', $docs );
	akb_recovery_require( $eligible === $docs, 'Cobertura v2 no es exacta.' );
	akb_recovery_require( count( $eligible ) === $work['acked'], 'Work live ACKed no coincide con el universo elegible.' );

	$generation = (string) akb_recovery_decode_option( akb_recovery_raw_option( Install::OPTION_INDEX_GEN ) );
	akb_recovery_require( '' !== $generation && RestCache::has_purge_receipt( $generation ), 'Generación/purge v2 no están acreditados.' );
	$semantic = SemanticPublication::current_fingerprint();
	$semantic_receipt = akb_recovery_decode_option( akb_recovery_raw_option( SemanticPublication::OPTION_FINGERPRINT ) );
	akb_recovery_require( is_string( $semantic ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $semantic ), 'Fingerprint semántico ilegible.' );
	akb_recovery_require(
		is_array( $semantic_receipt )
		&& 1 === (int) ( $semantic_receipt['protocol'] ?? 0 )
		&& hash_equals( $semantic, (string) ( $semantic_receipt['fingerprint'] ?? '' ) )
		&& $generation === (string) ( $semantic_receipt['generation'] ?? '' )
		&& (int) ( $semantic_receipt['published_at'] ?? 0 ) > 0,
		'Receipt semántico no coincide con generación/foto.'
	);
	$editorial = EditorialDiscountPublication::status();
	akb_recovery_require(
		true === ( $editorial['registered'] ?? false )
		&& true === ( $editorial['ready'] ?? false )
		&& true === ( $editorial['source_valid'] ?? false )
		&& true === ( $editorial['state_valid'] ?? false )
		&& true === ( $editorial['receipt_valid'] ?? false )
		&& '' === (string) ( $editorial['error'] ?? '' ),
		'Materialización editorial no está lista.'
	);
	$editorial_receipt = akb_recovery_decode_option( akb_recovery_raw_option( EditorialDiscountPublication::OPTION_RECEIPT ) );
	akb_recovery_require(
		is_array( $editorial_receipt )
		&& $generation === (string) ( $editorial_receipt['generation_target'] ?? '' )
		&& $semantic === (string) ( $editorial_receipt['semantic_fingerprint'] ?? '' )
		&& '1.7.0' === (string) ( $editorial_receipt['db_version'] ?? '' )
		&& Install::schema_seal() === (string) ( $editorial_receipt['schema_seal'] ?? '' )
		&& true === ( $editorial_receipt['publication_acked'] ?? false ),
		'Receipt editorial no coincide con publicación v2.'
	);

	$last = akb_recovery_decode_canonical_array( akb_recovery_raw_option( RebuildJob::OPTION_LAST ) );
	akb_recovery_require(
		akb_recovery_final_rebuild_receipt_matches( $last )
		&& hash_equals( $semantic, (string) $last['semantic_fingerprint'] )
		&& count( $docs ) === $last['docs']
		&& count( $eligible ) === $last['expected'],
		'Receipt final v2 no converge con IDs/publicación.'
	);
	$rebuild_generation = (string) $last['generation_target'];
	akb_recovery_require(
		! hash_equals( $rebuild_generation, $generation ),
		'La publicación live no supersedió la generación histórica del rebuild.'
	);
	$history = akb_recovery_decode_canonical_array( akb_recovery_raw_option( RebuildJob::OPTION_HISTORY ) );
	akb_recovery_require(
		is_array( $history ) && isset( $history[0] ) && $last === $history[0],
		'History no conserva una copia exacta del receipt final.'
	);
	$editorial_run_id      = (string) $last['run_id'];
	$editorial_wanted_seq  = (int) $last['editorial_wanted_seq'];
	$editorial_fingerprint = (string) $last['editorial_fingerprint'];
	akb_recovery_require(
		EditorialDiscountPublication::swap_receipt_exists(
			$editorial_run_id,
			$editorial_wanted_seq,
			$editorial_fingerprint,
			true
		),
		'Receipt editorial inmutable del rebuild no existe o es inválido.'
	);
	$editorial_run_option = 'akb_search_editorial_run_receipt_' . hash(
		'sha256',
		$editorial_run_id . "\0" . $editorial_wanted_seq . "\0" . $editorial_fingerprint
	);
	$editorial_run_receipt = akb_recovery_decode_canonical_array( akb_recovery_raw_option( $editorial_run_option ) );
	akb_recovery_require(
		is_array( $editorial_run_receipt )
		&& $editorial_run_id === (string) ( $editorial_run_receipt['run_id'] ?? '' )
		&& $editorial_wanted_seq === (int) ( $editorial_run_receipt['wanted_seq'] ?? 0 )
		&& hash_equals( $editorial_fingerprint, (string) ( $editorial_run_receipt['fingerprint'] ?? '' ) )
		&& hash_equals( $rebuild_generation, (string) ( $editorial_run_receipt['generation_target'] ?? '' ) )
		&& hash_equals( $semantic, (string) ( $editorial_run_receipt['semantic_fingerprint'] ?? '' ) )
		&& '1.7.0' === (string) ( $editorial_run_receipt['db_version'] ?? '' )
		&& Install::schema_seal() === (string) ( $editorial_run_receipt['schema_seal'] ?? '' )
		&& true === ( $editorial_run_receipt['publication_acked'] ?? false )
		&& is_array( $editorial_run_receipt['coverage'] ?? null )
		&& count( $docs ) === (int) ( $editorial_run_receipt['coverage']['docs'] ?? -1 )
		&& count( $eligible ) === (int) ( $editorial_run_receipt['coverage']['eligible'] ?? -1 )
		&& 0 === (int) ( $editorial_run_receipt['coverage']['missing'] ?? -1 )
		&& 0 === (int) ( $editorial_run_receipt['coverage']['extra'] ?? -1 )
		&& 0 === (int) ( $editorial_run_receipt['coverage']['orphans'] ?? -1 ),
		'Receipt editorial inmutable no queda ligado al rebuild histórico.'
	);

	return array(
		'mode'        => 'gate-v2',
		'result'      => 'ok',
		'topology'    => 'clean_pre',
		'db_version'  => '1.7.0',
		'docs'        => count( $docs ),
		'eligible'    => count( $eligible ),
		'generation'  => $generation,
		'rebuild_generation' => $rebuild_generation,
		'live_generation_superseded_rebuild' => true,
		'semantic'    => $semantic,
		'work'        => $work,
		'price_pair'  => array(
			'phase'            => 'normalized',
			'mode'             => $price_pair['mode']['raw'],
			'buckets_sha256'   => hash( 'sha256', $price_pair['buckets']['raw'] ),
			'buckets_autoload' => $price_pair['buckets']['autoload'],
			'mode_autoload'    => $price_pair['mode']['autoload'],
			'derived'          => true,
		),
	);
}

/** @return array<string, mixed> */
function akb_recovery_gate_v1(): array {
	global $wpdb;

	akb_recovery_require( defined( 'AKIBARA_SEARCH_VERSION' ) && '0.1.12' === AKIBARA_SEARCH_VERSION, 'Gate v1 exige Search 0.1.12.' );
	akb_recovery_require( defined( 'AKIBARA_BUSINESS_VERSION' ) && '1.99.10' === AKIBARA_BUSINESS_VERSION, 'Gate v1 exige Business 1.99.10.' );
	$db_version = akb_recovery_decode_option( akb_recovery_raw_option( 'akb_search_db_version' ) );
	akb_recovery_require( '1.6.0' === $db_version, 'Rollback no persistió DB 1.6.0.' );
	akb_recovery_require( null === akb_recovery_raw_option( 'akb_search_rebuild_state' ), 'Rollback conserva state.' );
	akb_recovery_require( null === akb_recovery_raw_option( 'akb_search_pending_rebuild' ), 'Rollback conserva pending.' );
	akb_recovery_require( 'clean_pre' === akb_recovery_topology()['classification'], 'Rollback no quedó clean_pre.' );
	$price_pair = akb_recovery_require_price_pair( 'normalized', false );

	$wpdb->last_error = '';
	$eligible          = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s ORDER BY ID ASC",
			'product',
			'publish'
		)
	);
	akb_recovery_require( is_array( $eligible ) && '' === trim( (string) $wpdb->last_error ) && ! empty( $eligible ), 'Universo elegible v1 inválido.' );
	$wpdb->last_error = '';
	$docs             = $wpdb->get_col( 'SELECT post_id FROM ' . akb_recovery_identifier( $wpdb->prefix . 'akb_search_docs' ) . ' ORDER BY post_id ASC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla interna delimitada.
	akb_recovery_require( is_array( $docs ) && '' === trim( (string) $wpdb->last_error ), 'IDs docs v1 ilegibles.' );
	$eligible = array_map( 'intval', $eligible );
	$docs     = array_map( 'intval', $docs );
	akb_recovery_require( $eligible === $docs, 'Cobertura v1 no es 100% exacta.' );

	return array(
		'mode'       => 'gate-v1',
		'result'     => 'ok',
		'topology'   => 'clean_pre',
		'db_version' => '1.6.0',
		'docs'       => count( $docs ),
		'eligible'   => count( $eligible ),
		'price_pair' => array(
			'phase'            => 'normalized',
			'mode'             => $price_pair['mode']['raw'],
			'buckets_sha256'   => hash( 'sha256', $price_pair['buckets']['raw'] ),
			'buckets_autoload' => $price_pair['buckets']['autoload'],
			'mode_autoload'    => $price_pair['mode']['autoload'],
			'derived'          => false,
		),
	);
}

if ( '1' === getenv( 'AKB_RECOVERY_LIBRARY_ONLY' ) ) {
	return;
}

try {
	$mode = akb_recovery_env( 'AKB_RECOVERY_MODE' );
	if ( in_array( $mode, array( 'recover-ready-swap', 'pending' ), true ) ) {
		$plugin_file = '/var/www/html/wp-content/plugins/akibara-search/akibara-search.php';
		akb_recovery_require( is_readable( $plugin_file ), 'Árbol Search sellado no está montado.' );
		require_once $plugin_file;
	}

	switch ( $mode ) {
		case 'recover-ready-swap':
			$receipt = akb_recovery_recover_ready_swap();
			break;
		case 'pending':
			akb_recovery_require( defined( 'AKIBARA_SEARCH_VERSION' ) && '0.1.14' === AKIBARA_SEARCH_VERSION, 'Pending exige Search 0.1.14.' );
			$receipt = array(
				'mode'   => 'pending',
				'result' => 'ok',
				'pending' => akb_recovery_pending_envelope(),
			);
			break;
		case 'gate-v2':
			$receipt = akb_recovery_gate_v2();
			break;
		case 'gate-v1':
			$receipt = akb_recovery_gate_v1();
			break;
		default:
			throw new RuntimeException( 'Modo recovery no permitido.' );
	}

	$json = wp_json_encode( $receipt, JSON_UNESCAPED_SLASHES );
	akb_recovery_require( is_string( $json ), 'No se pudo codificar receipt.' );
	fwrite( STDOUT, 'AKB_RECOVERY_RECEIPT=' . $json . PHP_EOL );
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'AKB_RECOVERY_FAIL=' . $throwable->getMessage() . PHP_EOL );
	exit( 1 );
}
