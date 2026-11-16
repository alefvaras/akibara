<?php
/**
 * Read-only fingerprint of every persistent Akibara Search data surface.
 *
 * The promotion pipeline passes this payload inline to `wp eval`; it is never
 * copied to the remote filesystem. The output deliberately covers both the
 * derived index and the irrecoverable/operator-owned Search state so a code
 * upgrade cannot silently rebuild, reseed, or rewrite either category.
 */

defined( 'ABSPATH' ) || exit( 1 );

global $wpdb;

$encode_row = static function ( array $row ): string {
	$json = wp_json_encode(
		$row,
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
	);
	if ( ! is_string( $json ) ) {
		fwrite( STDERR, "Could not encode Search fingerprint row\n" );
		exit( 2 );
	}

	return hash( 'sha256', $json );
};

$emit_hashed_rows = static function ( string $dataset, array $rows ) use ( $encode_row ): void {
	$hashes = array_map( $encode_row, $rows );
	sort( $hashes, SORT_STRING );

	$occurrences = array();
	foreach ( $hashes as $hash ) {
		$occurrences[ $hash ] = ( $occurrences[ $hash ] ?? 0 ) + 1;
		echo $dataset . "\t" . $hash . ':' . $occurrences[ $hash ] . "\t" . $hash . "\n";
	}
};

$table_pattern = $wpdb->esc_like( $wpdb->prefix . 'akb_search_' ) . '%';
$tables        = $wpdb->get_col(
	$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_pattern )
);
if ( ! is_array( $tables ) ) {
	fwrite( STDERR, "Could not enumerate Akibara Search tables\n" );
	exit( 3 );
}
sort( $tables, SORT_STRING );

$required_tables = array(
	$wpdb->prefix . 'akb_search_docs',
	$wpdb->prefix . 'akb_search_tokens',
	$wpdb->prefix . 'akb_search_facet_index',
	$wpdb->prefix . 'akb_search_qlog',
);
foreach ( $required_tables as $required_table ) {
	if ( ! in_array( $required_table, $tables, true ) ) {
		fwrite( STDERR, "Required Akibara Search table is absent\n" );
		exit( 4 );
	}
}

foreach ( $tables as $table ) {
	$prefix_pattern = '/^' . preg_quote( $wpdb->prefix, '/' ) . '(akb_search_[a-z0-9_]+)$/D';
	if ( 1 !== preg_match( $prefix_pattern, (string) $table, $matches ) ) {
		fwrite( STDERR, "Unsafe Akibara Search table name\n" );
		exit( 5 );
	}
	$logical_name = $matches[1];

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- validated internal table name; read-only fingerprint.
	$schema_rows = $wpdb->get_results( "SHOW CREATE TABLE `{$table}`", ARRAY_A );
	if ( ! is_array( $schema_rows ) || 1 !== count( $schema_rows ) ) {
		fwrite( STDERR, "Could not read Akibara Search table schema\n" );
		exit( 6 );
	}

	echo "@table\t{$logical_name}\t" . $encode_row( $schema_rows[0] ) . "\n";

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- validated internal table name; read-only fingerprint.
	$table_rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
	if ( ! is_array( $table_rows ) ) {
		fwrite( STDERR, "Could not read Akibara Search table rows\n" );
		exit( 7 );
	}
	$emit_hashed_rows( $logical_name, $table_rows );
}

$option_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT option_name, option_value, autoload
		FROM `{$wpdb->options}`
		WHERE option_name LIKE %s
		ORDER BY option_name ASC",
		$wpdb->esc_like( 'akb_search_' ) . '%'
	),
	ARRAY_A
);
if ( ! is_array( $option_rows ) ) {
	fwrite( STDERR, "Could not read Akibara Search options\n" );
	exit( 8 );
}
echo "@dataset\tsearch_options\tpresent\n";
foreach ( $option_rows as $option_row ) {
	$option_name = (string) ( $option_row['option_name'] ?? '' );
	echo 'search_options' . "\t" . base64_encode( $option_name ) . "\t" . $encode_row( $option_row ) . "\n";
}

$arrival_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT meta_id, post_id, meta_key, meta_value
		FROM `{$wpdb->postmeta}`
		WHERE meta_key IN (%s, %s)
		ORDER BY meta_id ASC",
		'_akb_onbackorder_at',
		'_akb_first_instock_at'
	),
	ARRAY_A
);
if ( ! is_array( $arrival_rows ) ) {
	fwrite( STDERR, "Could not read Akibara Search arrival history\n" );
	exit( 9 );
}
echo "@dataset\tsearch_arrival_postmeta\tpresent\n";
foreach ( $arrival_rows as $arrival_row ) {
	$meta_id = (string) ( $arrival_row['meta_id'] ?? '' );
	if ( 1 !== preg_match( '/^[0-9]+$/D', $meta_id ) ) {
		fwrite( STDERR, "Invalid Search arrival meta id\n" );
		exit( 10 );
	}
	echo 'search_arrival_postmeta' . "\t" . $meta_id . "\t" . $encode_row( $arrival_row ) . "\n";
}

$actions_table = $wpdb->prefix . 'actionscheduler_actions';
$groups_table  = $wpdb->prefix . 'actionscheduler_groups';
foreach ( array( $actions_table, $groups_table ) as $scheduler_table ) {
	$present_table = $wpdb->get_var(
		$wpdb->prepare(
			'SHOW TABLES LIKE %s',
			$wpdb->esc_like( $scheduler_table )
		)
	);
	if ( $scheduler_table !== $present_table ) {
		fwrite( STDERR, "Required Action Scheduler table is absent\n" );
		exit( 11 );
	}
}

// The queue is persistent Search state too. A code deploy must not silently
// enqueue, consume, retry, or otherwise rewrite Search jobs while claiming
// exact database/index preservation.
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- trusted internal table name; read-only fingerprint.
$search_group_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT group_id, slug
		FROM `{$groups_table}`
		WHERE slug = %s
		ORDER BY group_id ASC",
		'akibara-search'
	),
	ARRAY_A
);
if ( ! is_array( $search_group_rows ) ) {
	fwrite( STDERR, "Could not read Search Action Scheduler group\n" );
	exit( 12 );
}
echo "@dataset\tsearch_action_scheduler_groups\tpresent\n";
$emit_hashed_rows( 'search_action_scheduler_groups', $search_group_rows );

// Include both the canonical group and the hook prefix so a malformed or
// legacy Search action cannot escape the release fingerprint.
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- trusted internal table names; read-only fingerprint.
$search_action_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT actions.*, COALESCE(groups.slug, '') AS akb_group_slug
		FROM `{$actions_table}` AS actions
		LEFT JOIN `{$groups_table}` AS groups
			ON groups.group_id = actions.group_id
		WHERE groups.slug = %s OR actions.hook LIKE %s
		ORDER BY actions.action_id ASC",
		'akibara-search',
		$wpdb->esc_like( 'akb_search_' ) . '%'
	),
	ARRAY_A
);
if ( ! is_array( $search_action_rows ) ) {
	fwrite( STDERR, "Could not read Search Action Scheduler actions\n" );
	exit( 13 );
}
echo "@dataset\tsearch_action_scheduler_actions\tpresent\n";
$emit_hashed_rows( 'search_action_scheduler_actions', $search_action_rows );
