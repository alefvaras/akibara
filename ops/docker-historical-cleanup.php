<?php
/**
 * Fail-closed Docker-only historical hygiene audit for Akibara.
 *
 * Run with the WordPress CLI container but override its entrypoint to PHP:
 *
 * docker compose run --rm --no-deps -T --entrypoint php \
 *   -v /absolute/repo/ops:/workspace/ops:ro \
 *   -v /absolute/repo/.local-reports:/workspace/reports \
 *   cli /workspace/ops/docker-historical-cleanup.php \
 *   --mode=clean-sessions --output=/workspace/reports/.../sessions.json
 */

declare( strict_types=1 );

chdir( '/var/www/html' );
require_once '/var/www/html/wp-load.php';

global $wpdb;

/**
 * Fail with an audit-friendly exception.
 *
 * @param string $message Failure detail.
 * @return never
 */
function akb_cleanup_fail( string $message ): never {
	throw new RuntimeException( $message );
}

/**
 * Return whether a table exists.
 *
 * @param string $table Fully qualified WordPress table name.
 */
function akb_cleanup_table_exists( string $table ): bool {
	global $wpdb;

	return $table === $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
	);
}

/**
 * Hash an ordered result set without exposing row values.
 *
 * @param string $sql Ordered SELECT statement.
 * @return array{count:int,sha256:string}
 */
function akb_cleanup_fingerprint_query( string $sql ): array {
	global $wpdb;

	$rows = $wpdb->get_results( $sql, ARRAY_A );
	if ( ! is_array( $rows ) ) {
		akb_cleanup_fail( 'Fingerprint query failed: ' . $wpdb->last_error );
	}

	$context = hash_init( 'sha256' );
	foreach ( $rows as $row ) {
		hash_update(
			$context,
			wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"
		);
	}

	return array(
		'count'  => count( $rows ),
		'sha256' => hash_final( $context ),
	);
}

/**
 * Hash every commercial table that this cleanup must preserve.
 *
 * @return array<string,mixed>
 */
function akb_cleanup_commercial_fingerprints(): array {
	global $wpdb;

	$tables = array(
		'orders_hpos'      => array( $wpdb->prefix . 'wc_orders', 'id' ),
		'orders_hpos_meta' => array( $wpdb->prefix . 'wc_orders_meta', 'id' ),
		'customer_lookup'  => array( $wpdb->prefix . 'wc_customer_lookup', 'customer_id' ),
		'users'            => array( $wpdb->users, 'ID' ),
	);
	$result = array();

	foreach ( $tables as $label => $table_config ) {
		list( $table, $order_column ) = $table_config;
		$result[ $label ] = akb_cleanup_table_exists( $table )
			? akb_cleanup_fingerprint_query(
				'SELECT * FROM `' . esc_sql( $table ) . '` ORDER BY `' . esc_sql( $order_column ) . '`'
			)
			: array(
				'count'  => 0,
				'sha256' => hash( 'sha256', '' ),
			);
	}

	$result['customer_usermeta'] = akb_cleanup_fingerprint_query(
		"SELECT *
		FROM `{$wpdb->usermeta}`
		WHERE meta_key IN (
			'first_name',
			'last_name',
			'nickname',
			'description',
			'wp_capabilities',
			'wc_last_active',
			'_order_count',
			'_money_spent'
		)
			OR meta_key LIKE 'billing\\_%'
			OR meta_key LIKE 'shipping\\_%'
			OR meta_key LIKE '\\_woocommerce\\_persistent\\_cart\\_%'
		ORDER BY umeta_id"
	);
	$result['orders_legacy'] = akb_cleanup_fingerprint_query(
		"SELECT * FROM `{$wpdb->posts}`
		WHERE post_type IN ('shop_order','shop_order_refund')
		ORDER BY ID"
	);
	$result['orders_legacy_meta'] = akb_cleanup_fingerprint_query(
		"SELECT meta.*
		FROM `{$wpdb->postmeta}` AS meta
		INNER JOIN `{$wpdb->posts}` AS orders ON orders.ID = meta.post_id
		WHERE orders.post_type IN ('shop_order','shop_order_refund')
		ORDER BY meta.meta_id"
	);
	$result['order_notes'] = akb_cleanup_fingerprint_query(
		"SELECT * FROM `{$wpdb->comments}`
		WHERE comment_type = 'order_note'
		ORDER BY comment_ID"
	);
	$result['order_note_meta'] = akb_cleanup_fingerprint_query(
		"SELECT meta.*
		FROM `{$wpdb->commentmeta}` AS meta
		INNER JOIN `{$wpdb->comments}` AS notes ON notes.comment_ID = meta.comment_id
		WHERE notes.comment_type = 'order_note'
		ORDER BY meta.meta_id"
	);

	$result['combined_sha256'] = hash(
		'sha256',
		wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);
	$result['usermeta_observational'] = akb_cleanup_fingerprint_query(
		"SELECT * FROM `{$wpdb->usermeta}` ORDER BY umeta_id"
	);

	return $result;
}

/**
 * Capture WooCommerce sessions relative to a fixed cutoff.
 *
 * @param int $cutoff Unix timestamp used by the preflight.
 * @return array<string,mixed>
 */
function akb_cleanup_sessions_snapshot( int $cutoff ): array {
	global $wpdb;

	$table = $wpdb->prefix . 'woocommerce_sessions';
	if ( ! akb_cleanup_table_exists( $table ) ) {
		akb_cleanup_fail( 'WooCommerce sessions table is unavailable.' );
	}

	$counts = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT
				COUNT(*) AS total,
				COALESCE(SUM(session_expiry < %d), 0) AS expired,
				COALESCE(SUM(session_expiry >= %d), 0) AS active
			FROM `{$table}`",
			$cutoff,
			$cutoff
		),
		ARRAY_A
	);
	$minimum_active_expiry = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT MIN(session_expiry) FROM `{$table}` WHERE session_expiry >= %d",
			$cutoff
		)
	);

	return array(
		'cutoff'                    => $cutoff,
		'total'                     => (int) $counts['total'],
		'expired'                   => (int) $counts['expired'],
		'active'                    => (int) $counts['active'],
		'minimum_active_expiry'     => null === $minimum_active_expiry ? null : (int) $minimum_active_expiry,
		'all_fingerprint'           => akb_cleanup_fingerprint_query(
			"SELECT session_id,session_key,session_value,session_expiry
			FROM `{$table}`
			ORDER BY session_id"
		),
		'active_fingerprint'        => akb_cleanup_fingerprint_query(
			$wpdb->prepare(
				"SELECT session_id,session_key,session_value,session_expiry
				FROM `{$table}`
				WHERE session_expiry >= %d
				ORDER BY session_id",
				$cutoff
			)
		),
	);
}

/**
 * Capture referential orphans without exposing stored values.
 *
 * @return array<string,int>
 */
function akb_cleanup_orphan_counts(): array {
	global $wpdb;

	return array(
		'postmeta'      => (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM `{$wpdb->postmeta}` AS meta
			LEFT JOIN `{$wpdb->posts}` AS parent ON parent.ID = meta.post_id
			WHERE parent.ID IS NULL"
		),
		'termmeta'      => (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM `{$wpdb->termmeta}` AS meta
			LEFT JOIN `{$wpdb->terms}` AS parent ON parent.term_id = meta.term_id
			WHERE parent.term_id IS NULL"
		),
		'relationships' => (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM `{$wpdb->term_relationships}` AS relationship
			LEFT JOIN `{$wpdb->posts}` AS parent ON parent.ID = relationship.object_id
			WHERE parent.ID IS NULL"
		),
	);
}

/**
 * Capture only non-sensitive fields needed to classify temporary content.
 *
 * @return array<string,mixed>
 */
function akb_cleanup_temporary_inventory(): array {
	global $wpdb;

	$posts = $wpdb->get_results(
		"SELECT ID,post_type,post_status,post_parent,post_date,post_modified,post_title
		FROM `{$wpdb->posts}`
		WHERE post_type = 'revision' OR post_status IN ('trash','auto-draft')
		ORDER BY post_type,post_status,ID",
		ARRAY_A
	);
	$comments = $wpdb->get_results(
		"SELECT
			comment.comment_ID,
			comment.comment_post_ID,
			comment.comment_approved,
			comment.comment_type,
			comment.comment_date,
			parent.post_type,
			parent.post_status,
			parent.post_title
		FROM `{$wpdb->comments}` AS comment
		LEFT JOIN `{$wpdb->posts}` AS parent ON parent.ID = comment.comment_post_ID
		WHERE comment.comment_approved IN ('trash','spam')
		ORDER BY comment.comment_ID",
		ARRAY_A
	);

	return array(
		'posts'    => is_array( $posts ) ? $posts : array(),
		'comments' => is_array( $comments ) ? $comments : array(),
	);
}

/**
 * Capture a complete safety snapshot.
 *
 * @param int $cutoff Session cutoff timestamp.
 * @return array<string,mixed>
 */
function akb_cleanup_snapshot( int $cutoff ): array {
	return array(
		'commercial'          => akb_cleanup_commercial_fingerprints(),
		'sessions'            => akb_cleanup_sessions_snapshot( $cutoff ),
		'orphans'             => akb_cleanup_orphan_counts(),
		'temporary_inventory' => akb_cleanup_temporary_inventory(),
	);
}

$options = getopt( '', array( 'mode:', 'output:', 'baseline::' ) );
$mode    = isset( $options['mode'] ) ? (string) $options['mode'] : '';
$output  = isset( $options['output'] ) ? (string) $options['output'] : '';

if ( ! in_array( $mode, array( 'clean-sessions', 'verify' ), true ) || '' === $output ) {
	akb_cleanup_fail( 'Use --mode=clean-sessions|verify and --output=/absolute/report.json.' );
}

$started_at = gmdate( 'c' );
$cutoff     = time();
$report     = array(
	'schema_version' => 1,
	'environment'    => wp_get_environment_type(),
	'site_url'       => home_url( '/' ),
	'mode'           => $mode,
	'started_at'     => $started_at,
);

if ( 'clean-sessions' === $mode ) {
	if ( ! function_exists( 'wc_cleanup_session_data' ) || ! class_exists( 'WC_Session_Handler' ) ) {
		akb_cleanup_fail( 'Official WooCommerce session cleanup API is unavailable.' );
	}

	$before = akb_cleanup_snapshot( $cutoff );
	if (
		null !== $before['sessions']['minimum_active_expiry']
		&& 300 > $before['sessions']['minimum_active_expiry'] - $cutoff
	) {
		akb_cleanup_fail( 'An active session expires within five minutes; retry after it expires.' );
	}

	wc_cleanup_session_data();
	$after_first = akb_cleanup_snapshot( $cutoff );
	wc_cleanup_session_data();
	$after_second = akb_cleanup_snapshot( $cutoff );

	$assertions = array(
		'environment_is_local'          => 'local' === wp_get_environment_type(),
		'commercial_unchanged_first'    => $before['commercial']['combined_sha256'] === $after_first['commercial']['combined_sha256'],
		'commercial_unchanged_second'   => $after_first['commercial']['combined_sha256'] === $after_second['commercial']['combined_sha256'],
		'active_sessions_preserved'     => $before['sessions']['active_fingerprint'] === $after_first['sessions']['active_fingerprint'],
		'no_expired_after_first'        => 0 === $after_first['sessions']['expired'],
		'second_pass_idempotent'        => $after_first['sessions']['all_fingerprint'] === $after_second['sessions']['all_fingerprint'],
		'no_expired_after_second'       => 0 === $after_second['sessions']['expired'],
	);

	$report['before']       = $before;
	$report['after_first']  = $after_first;
	$report['after_second'] = $after_second;
	$report['assertions']   = $assertions;
	$report['pass']         = ! in_array( false, $assertions, true );
} else {
	$baseline_path = isset( $options['baseline'] ) ? (string) $options['baseline'] : '';
	if ( '' === $baseline_path || ! is_readable( $baseline_path ) ) {
		akb_cleanup_fail( 'Verify mode requires a readable --baseline report.' );
	}

	$baseline = json_decode( (string) file_get_contents( $baseline_path ), true );
	if ( ! is_array( $baseline ) || ! isset( $baseline['before']['commercial']['combined_sha256'] ) ) {
		akb_cleanup_fail( 'Baseline report has an unsupported shape.' );
	}

	$current    = akb_cleanup_snapshot( $cutoff );
	$assertions = array(
		'environment_is_local'        => 'local' === wp_get_environment_type(),
		'commercial_matches_baseline' => $baseline['before']['commercial']['combined_sha256'] === $current['commercial']['combined_sha256'],
		'no_expired_sessions'         => 0 === $current['sessions']['expired'],
		'no_referential_orphans'      => array(
			'postmeta'      => 0,
			'termmeta'      => 0,
			'relationships' => 0,
		) === $current['orphans'],
	);

	$report['baseline_started_at'] = $baseline['started_at'] ?? null;
	$report['current']             = $current;
	$report['assertions']          = $assertions;
	$report['pass']                = ! in_array( false, $assertions, true );
}

$report['finished_at'] = gmdate( 'c' );
$encoded               = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( false === $encoded || false === file_put_contents( $output, $encoded . "\n" ) ) {
	akb_cleanup_fail( 'Could not write the requested report.' );
}

echo $encoded . "\n";
exit( $report['pass'] ? 0 : 1 );
