<?php
/** Remove one validated orphaned Docker UI administrator with no authored rows. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This script must run through WP-CLI.' );
}
if (
	wp_get_environment_type() !== 'local'
	|| untrailingslashit( home_url( '/' ) ) !== 'http://localhost:8080'
) {
	throw new RuntimeException( 'Refusing to run outside Akibara Docker.' );
}

$expected_id    = 256;
$expected_login = 'akb_ui_local_20260819021919_7e094ba961d4';
$user           = get_user_by( 'id', $expected_id );
if (
	! $user instanceof WP_User
	|| $user->user_login !== $expected_login
	|| $user->user_email !== $expected_login . '@akibara.invalid'
	|| $user->user_registered !== '2026-08-19 02:19:24'
	|| $user->roles !== array( 'administrator' )
) {
	throw new RuntimeException( 'Orphaned administrator identity guard failed.' );
}
$marker = (string) get_user_meta(
	$expected_id,
	'_akibara_ui_temp_admin_token_sha256',
	true
);
if ( ! preg_match( '/^[a-f0-9]{64}$/', $marker ) ) {
	throw new RuntimeException( 'Temporary-administrator marker is missing.' );
}
global $wpdb;
$authored = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d",
		$expected_id
	)
);
if ( $authored !== 0 ) {
	throw new RuntimeException( 'Refusing to delete an administrator with authored rows.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( ! wp_delete_user( $expected_id ) || get_user_by( 'id', $expected_id ) ) {
	throw new RuntimeException( 'Orphaned administrator deletion failed.' );
}
echo wp_json_encode(
	array(
		'deleted_user_id' => $expected_id,
		'user_login'      => $expected_login,
		'authored_rows'   => 0,
		'marker_present'  => true,
	),
	JSON_UNESCAPED_SLASHES
);
