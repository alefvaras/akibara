<?php
/**
 * Plugin Name: Akibara — imágenes compatibles en el carrito de Brevo
 * Description: Entrega JPEG de las imágenes AVIF/WebP de WooCommerce al evento de carrito de Brevo, incluidas las subidas por año y mes.
 * Version: 2.0.0
 * Author: Akibara
 *
 * Se adapta el payload final: cubre tanto las mutaciones de carrito como la
 * captura de correo por AJAX. Las antiguas ventanas de hooks no cubrían AJAX
 * y su regex sólo reconocía /portadas/. No cambia imágenes de la tienda,
 * importes, consentimiento, destinatarios ni enlaces de recuperación.
 *
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Adapta exclusivamente las imágenes del evento cart_updated de Brevo.
 *
 * @param array  $args Argumentos HTTP.
 * @param string $url Destino HTTP.
 * @return array
 */
function akibara_brevo_imagenes_del_evento( array $args, string $url ): array {
	if ( 'https://in-automate.brevo.com/api/v2/trackEvent' !== $url
		|| 'POST' !== strtoupper( (string) ( $args['method'] ?? '' ) )
		|| ! is_string( $args['body'] ?? null )
		|| ! class_exists( '\Akibara\Business\Modules\SeoSocial\EmailImageDerivatives' ) ) {
		return $args;
	}

	$body = json_decode( $args['body'] );
	if ( ! is_object( $body ) || 'cart_updated' !== ( $body->event ?? null )
		|| ! is_array( $body->eventdata->data->items ?? null ) ) {
		return $args;
	}

	$changed = false;
	foreach ( $body->eventdata->data->items as $item ) {
		if ( ! is_object( $item ) || ! is_string( $item->image ?? null ) ) {
			continue;
		}
		$image = \Akibara\Business\Modules\SeoSocial\EmailImageDerivatives::url( $item->image );
		if ( $image !== $item->image ) {
			$item->image = $image;
			$changed     = true;
		}
	}
	if ( $changed ) {
		$encoded = wp_json_encode( $body, JSON_PRESERVE_ZERO_FRACTION );
		if ( is_string( $encoded ) ) {
			$args['body'] = $encoded;
		}
	}

	return $args;
}

/** Registra el adaptador después del autoloader de Akibara Business (20). */
function akibara_brevo_registrar_imagenes(): void {
	if ( ! class_exists( '\SendinblueWoocommerce\Managers\CartEventsManagers' )
		|| ! class_exists( '\Akibara\Business\Modules\SeoSocial\EmailImageDerivatives' ) ) {
		return;
	}
	add_filter( 'http_request_args', 'akibara_brevo_imagenes_del_evento', 20, 2 );
	add_filter( 'wp_generate_attachment_metadata', array( '\Akibara\Business\Modules\SeoSocial\EmailImageDerivatives', 'on_upload' ), 30, 2 );
	add_action( 'akibara_brevo_email_image', array( '\Akibara\Business\Modules\SeoSocial\EmailImageDerivatives', 'url' ) );
}

add_action( 'plugins_loaded', 'akibara_brevo_registrar_imagenes', 25 );
