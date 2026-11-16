<?php
/**
 * Disable a stale Docker-only Rank Math redirect that has no production peer.
 *
 * The rule intercepts the real Atelier of Witch Hat post and points to a 404.
 * It is retained as inactive, so the local change remains reversible.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This script must run through WP-CLI.' );
}
if (
	wp_get_environment_type() !== 'local'
	|| untrailingslashit( home_url( '/' ) ) !== 'http://localhost:8080'
) {
	throw new RuntimeException( 'Refusing to run outside Akibara Docker.' );
}

global $wpdb;
$table = $wpdb->prefix . 'rank_math_redirections';
$row   = $wpdb->get_row(
	$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", 589 ),
	ARRAY_A
);
if (
	! is_array( $row )
	|| $row['status'] !== 'active'
	|| (string) $row['header_code'] !== '301'
	|| $row['url_to'] !== 'http://localhost:8080/atelier-of-witch-hat-guia/'
	|| strpos(
		$row['sources'],
		'atelier-of-witch-hat-la-obra-maestra-de-kamome-shirahama/'
	) === false
) {
	throw new RuntimeException( 'Redirect 589 guard failed.' );
}
$updated = $wpdb->update(
	$table,
	array( 'status' => 'inactive' ),
	array( 'id' => 589 ),
	array( '%s' ),
	array( '%d' )
);
if ( $updated !== 1 ) {
	throw new RuntimeException( 'Redirect 589 update failed.' );
}
echo wp_json_encode(
	array(
		'id'                     => 589,
		'before'                 => 'active',
		'after'                  => 'inactive',
		'production_counterpart' => false,
		'url_to'                 => $row['url_to'],
	),
	JSON_UNESCAPED_SLASHES
);
