<?php
/**
 * Plugin Name: Akibara — rastro de las abilities
 * Description: Registra la ability ejecutada, el usuario y las claves de entrada, sin almacenar valores. Un éxito deja INICIO y FIN; un WP_Error deja INICIO sin FIN porque el núcleo no emite wp_after_execute_ability. Las denegaciones las registra akibara-abilities-denylist.php desde permission_callback.
 * Version: 1.1.0
 * Author: Akibara
 *
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Fuente del log de WooCommerce donde vive el rastro.
 *
 * Se elige el logger de WooCommerce y no un fichero propio porque el dueño ya
 * sabe dónde mirar (WooCommerce → Estado → Registros) y porque rota solo.
 */
const AKIBARA_ABILITIES_AUDIT_SOURCE = 'akibara-abilities';

/**
 * Quién está ejecutando, dicho de forma que sirva para buscar después.
 *
 * @return string
 */
function akibara_abilities_audit_quien(): string {
	$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	if ( $user_id <= 0 ) {
		return 'sin sesión';
	}

	$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;

	return $user && isset( $user->user_login )
		? sprintf( '#%d %s', $user_id, (string) $user->user_login )
		: sprintf( '#%d', $user_id );
}

/**
 * Claves de la entrada, nunca los valores.
 *
 * Una ability puede recibir un correo, una dirección o una clave de proveedor.
 * El rastro tiene que decir QUÉ se ejecutó, no filtrar lo que se ejecutó con.
 *
 * @param mixed $input Entrada de la ability.
 * @return string
 */
function akibara_abilities_audit_claves( $input ): string {
	if ( ! is_array( $input ) || array() === $input ) {
		return 'sin entrada';
	}

	$claves = array_map( 'strval', array_keys( $input ) );
	sort( $claves );

	return implode( ',', array_slice( $claves, 0, 20 ) );
}

/**
 * Escribe una línea del rastro.
 *
 * @param string $mensaje Línea ya compuesta.
 * @return void
 */
function akibara_abilities_audit_anota( string $mensaje ): void {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->info( $mensaje, array( 'source' => AKIBARA_ABILITIES_AUDIT_SOURCE ) );
		return;
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- sin WooCommerce el rastro no puede perderse.
	error_log( '[akibara-abilities] ' . $mensaje );
}

add_action(
	'wp_before_execute_ability',
	static function ( $name, $input = null ): void {
		akibara_abilities_audit_anota(
			sprintf(
				'INICIO %s · por %s · entrada: %s',
				(string) $name,
				akibara_abilities_audit_quien(),
				akibara_abilities_audit_claves( $input )
			)
		);
	},
	10,
	2
);

add_action(
	'wp_after_execute_ability',
	static function ( $name, $input = null, $result = null ): void {
		unset( $input );

		// El núcleo retorna los WP_Error antes de emitir este hook; aquí sólo
		// se observan ejecuciones completadas.
		unset( $result );

		akibara_abilities_audit_anota(
			sprintf( 'FIN    %s · por %s · ok', (string) $name, akibara_abilities_audit_quien() )
		);
	},
	10,
	3
);
