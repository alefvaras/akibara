<?php
/**
 * Plugin Name: Akibara — Blue Express cotiza sólo con región y comuna
 * Description: Habilita las cotizaciones API de Blue Express sólo cuando el paquete incluye región y comuna. Usa los filtros de disponibilidad de WooCommerce sin modificar el tercero. Bluex PUDO conserva su tarifa fija y su validación propia del punto de retiro.
 * Version: 1.0.0
 * Author: Akibara
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Métodos de Blue Express que cotizan contra su API en cada cálculo.
 *
 * Los ids salen de `includes/shipping/class-wc-bluex-express.php` del plugin.
 *
 * @return array<int, string>
 */
function akibara_bluex_metodos_que_cotizan(): array {
	return array( 'bluex-ex', 'bluex-py', 'bluex-md' );
}

/**
 * Sin región y comuna no hay cotización posible: el método no está disponible.
 *
 * @param mixed $disponible Lo que WooCommerce ya decidió.
 * @param mixed $package    Paquete del carrito, con `destination`.
 * @return bool
 */
function akibara_bluex_disponible_solo_con_comuna( $disponible, $package = array() ): bool {
	if ( ! $disponible ) {
		return false;
	}

	$destino = is_array( $package ) && isset( $package['destination'] ) && is_array( $package['destination'] )
		? $package['destination']
		: array();
	$region  = trim( (string) ( $destino['state'] ?? '' ) );
	$comuna  = trim( (string) ( $destino['city'] ?? '' ) );

	return '' !== $region && '' !== $comuna;
}

/**
 * Engancha la disponibilidad sólo si el plugin de Blue Express está cargado.
 *
 * `plugins_loaded` a prioridad 20: Blue Express incluye sus clases en
 * `WC_Correios::init()`, colgado de `plugins_loaded` a prioridad 10.
 */
function akibara_bluex_registrar_cotiza_solo_con_comuna(): void {
	if ( ! class_exists( 'WC_Correios_Webservice' ) ) {
		return;
	}

	foreach ( akibara_bluex_metodos_que_cotizan() as $id ) {
		add_filter( 'woocommerce_shipping_' . $id . '_is_available', 'akibara_bluex_disponible_solo_con_comuna', 10, 2 );
	}
}

add_action( 'plugins_loaded', 'akibara_bluex_registrar_cotiza_solo_con_comuna', 20 );
