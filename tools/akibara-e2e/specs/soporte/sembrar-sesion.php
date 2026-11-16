<?php
/**
 * Siembra del espejo para `canje-de-puntos.spec.mjs`: un cliente de pruebas con
 * saldo y una sesión válida, SIN teclear ninguna contraseña.
 *
 * La cookie `wordpress_logged_in_*` se genera con las mismas funciones que usa
 * WordPress al iniciar sesión, así que lo que el navegador ve es una sesión de
 * verdad. Sólo corre en el espejo: el spec se salta con AKB_SOLO_LECTURA.
 *
 * @package Akibara\E2E
 */

defined( 'ABSPATH' ) || exit;

$akb_login = 'qa_e2e_puntos';
$akb_user  = get_user_by( 'login', $akb_login );
$akb_uid   = $akb_user
	? (int) $akb_user->ID
	: (int) wp_insert_user(
		array(
			'user_login' => $akb_login,
			'user_email' => 'qa-e2e-puntos@akibara.test',
			'user_pass'  => wp_generate_password( 32 ),
			'role'       => 'customer',
			'first_name' => 'QA',
		)
	);

// Saldo exacto de 5.000: lo que sobre o falte se ajusta, para que cada corrida
// parta del mismo número.
$akb_saldo = \Akibara\Rewards\Points\Ledger::balance( $akb_uid );
if ( $akb_saldo < 5000 ) {
	\Akibara\Rewards\Points\Ledger::credit( $akb_uid, 5000 - $akb_saldo, 'adjust', 'user', $akb_uid, 'Siembra e2e', false, 'qa_e2e_seed_' . time() );
} elseif ( $akb_saldo > 5000 ) {
	\Akibara\Rewards\Points\Ledger::debit( $akb_uid, $akb_saldo - 5000, 'adjust', 'user', $akb_uid, 'Siembra e2e', false, 'qa_e2e_trim_' . time() );
}

$akb_exp   = time() + 2 * HOUR_IN_SECONDS;
$akb_token = WP_Session_Tokens::get_instance( $akb_uid )->create( $akb_exp );

echo wp_json_encode(
	array(
		'saldo'  => \Akibara\Rewards\Points\Ledger::balance( $akb_uid ),
		'nombre' => LOGGED_IN_COOKIE,
		'valor'  => wp_generate_auth_cookie( $akb_uid, $akb_exp, 'logged_in', $akb_token ),
	)
);
