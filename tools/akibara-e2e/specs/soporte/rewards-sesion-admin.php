<?php
/**
 * Sesión de administrador para `rewards-titulo-de-resenas.spec.mjs`: mirar el
 * panel Puntos y Reseñas SIN teclear ninguna contraseña.
 *
 * Usa un administrador de PRUEBAS ya existente del espejo (login que empieza
 * por «qa» o «test»), nunca la cuenta del dueño, y no crea usuarios. Lo único
 * que escribe es un token de sesión, que el `afterAll` del spec destruye.
 * Se ejecuta por STDIN (`wp eval-file -`): nada se copia al docroot.
 *
 * @package Akibara\E2E
 */

defined( 'ABSPATH' ) || exit;

$akb_uid    = 0;
$akb_admins = get_users(
	array(
		'role'    => 'administrator',
		'orderby' => 'ID',
		'order'   => 'ASC',
	)
);
foreach ( $akb_admins as $akb_admin ) {
	if ( preg_match( '/^(qa|test)/i', (string) $akb_admin->user_login ) ) {
		$akb_uid = (int) $akb_admin->ID;
		break;
	}
}

if ( 0 === $akb_uid ) {
	// Falta el escenario, no hay nada roto: la spec se salta con este motivo.
	echo wp_json_encode(
		array(
			'sin_escenario' => true,
			'error'         => 'El espejo no tiene un administrador de pruebas (qa* / test*).',
		)
	);
	return;
}

$akb_exp   = time() + HOUR_IN_SECONDS;
$akb_token = WP_Session_Tokens::get_instance( $akb_uid )->create( $akb_exp );

echo wp_json_encode(
	array(
		'uid'     => $akb_uid,
		'token'   => $akb_token,
		'cookies' => array(
			array(
				'name'  => AUTH_COOKIE,
				'value' => wp_generate_auth_cookie( $akb_uid, $akb_exp, 'auth', $akb_token ),
			),
			array(
				'name'  => LOGGED_IN_COOKIE,
				'value' => wp_generate_auth_cookie( $akb_uid, $akb_exp, 'logged_in', $akb_token ),
			),
		),
	)
);
