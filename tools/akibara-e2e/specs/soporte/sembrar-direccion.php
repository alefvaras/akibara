<?php
/**
 * Siembra del espejo para `checkout-direccion-guardada.spec.mjs`: un cliente con
 * la facturación guardada y el envío en el estado que pide el escenario, más una
 * sesión válida, SIN teclear ninguna contraseña.
 *
 * El envío se escribe DIRECTO en user meta, a propósito: desde checkout-blocks
 * 2.2.31 todo `WC_Customer::save()` alinea el envío con la facturación, así que
 * sembrar por el objeto borraría el caso que la prueba necesita. Así quedaron las
 * cuentas anteriores al arreglo (reporte de un cliente, 2026-09-28).
 *
 * Uso: wp eval-file akb-e2e-sembrar-direccion.php <vacio|distinto> [proyecto]
 * El proyecto de Playwright da un cliente propio a cada uno: corren en paralelo.
 *
 * @package Akibara\E2E
 */

defined( 'ABSPATH' ) || exit;

$akb_escenario = isset( $args[0] ) ? (string) $args[0] : 'vacio';
$akb_proyecto  = isset( $args[1] ) ? sanitize_key( (string) $args[1] ) : '';
$akb_login     = 'qa_e2e_direccion' . ( '' !== $akb_proyecto ? '_' . $akb_proyecto : '' );
$akb_user      = get_user_by( 'login', $akb_login );
$akb_uid       = $akb_user
	? $akb_user->ID
	: wp_insert_user(
		array(
			'user_login' => $akb_login,
			'user_email' => $akb_login . '@akibara.test',
			'user_pass'  => wp_generate_password( 32 ),
			'role'       => 'customer',
			'first_name' => 'QA',
		)
	);
// `(int)` de un WP_Error da 1, el administrador: la siembra jamás debe tocarlo.
if ( is_wp_error( $akb_uid ) || (int) $akb_uid <= 1 ) {
	WP_CLI::error( 'no se pudo crear el cliente de pruebas: ' . ( is_wp_error( $akb_uid ) ? $akb_uid->get_error_message() : 'id ' . (int) $akb_uid ) );
}
$akb_uid = (int) $akb_uid;

$akb_facturacion = array(
	'first_name' => 'QA',
	'last_name'  => 'Direccion',
	'address_1'  => 'Volcán Llaima 715',
	'address_2'  => '',
	'city'       => 'Curicó',
	'state'      => 'CL-ML',
	'postcode'   => '',
	'country'    => 'CL',
	'phone'      => '87654321',
	'email'      => $akb_login . '@akibara.test',
);
$akb_envio = 'distinto' === $akb_escenario
	? array(
		'first_name' => 'QA',
		'last_name'  => 'Direccion',
		'address_1'  => 'Avenida Providencia 1234',
		'address_2'  => 'Punto Blue Express Antiguo',
		'city'       => 'Providencia',
		'state'      => 'CL-RM',
		'postcode'   => '',
		'country'    => 'CL',
		'phone'      => '87654321',
	)
	: array_fill_keys( array( 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ), '' );

foreach ( $akb_facturacion as $akb_campo => $akb_valor ) {
	update_user_meta( $akb_uid, 'billing_' . $akb_campo, $akb_valor );
}
foreach ( $akb_envio as $akb_campo => $akb_valor ) {
	update_user_meta( $akb_uid, 'shipping_' . $akb_campo, $akb_valor );
}
update_user_meta( $akb_uid, '_wc_other/akibara/rut', '11.111.111-1' );
update_user_meta( $akb_uid, 'billing_rut', '11.111.111-1' );
// Invalida el cliente guardado en sesión: WooCommerce recarga de la cuenta cuando cambia.
update_user_meta( $akb_uid, 'last_update', (string) time() );

global $wpdb;
$wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => (string) $akb_uid ) );
delete_user_meta( $akb_uid, '_woocommerce_persistent_cart_' . get_current_blog_id() );

$akb_exp   = time() + 2 * HOUR_IN_SECONDS;
$akb_token = WP_Session_Tokens::get_instance( $akb_uid )->create( $akb_exp );

echo wp_json_encode(
	array(
		'uid'    => $akb_uid,
		'nombre' => LOGGED_IN_COOKIE,
		'valor'  => wp_generate_auth_cookie( $akb_uid, $akb_exp, 'logged_in', $akb_token ),
	)
);
