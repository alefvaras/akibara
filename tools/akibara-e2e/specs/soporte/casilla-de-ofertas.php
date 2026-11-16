<?php
/**
 * Plugin Name: Akibara e2e — casilla de ofertas (doble de Brevo)
 * Description: SÓLO para el espejo. Registra la casilla «ofertas y novedades» tal como la registra el plugin de Brevo en producción (ubicación «order», tipo checkbox), para que el e2e pueda medir la prueba del consentimiento por la ruta real del checkout de bloques. La copia a mu-plugins y la borra tools/akibara-e2e/specs/consentimiento-de-ofertas.spec.mjs.
 *
 * El espejo no tiene Brevo instalado; si algún día lo tiene, este doble sobra
 * (registrar dos veces la misma clave es un doing_it_wrong de WooCommerce).
 *
 * @package Akibara\E2E
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( 'production' === wp_get_environment_type() ) {
	return;
}

add_action(
	'woocommerce_init',
	static function (): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'SendinblueWoocommerce/newsletter_opt_in',
				'label'    => 'Quiero recibir por correo las ofertas y novedades de Akibara',
				'location' => 'order',
				'required' => false,
				'type'     => 'checkbox',
			)
		);
	}
);
