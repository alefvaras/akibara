<?php
/**
 * Blue Express tracking compatibility for the modern WooCommerce REST API.
 *
 * The Blue Express plugin was built around WooCommerce's retired legacy API.
 * Blue Express still updates orders through the authenticated wc/v3 API, where
 * the REST field callback receives a WC_Order instead of a WP_Post. Persist the
 * canonical tracking field after WooCommerce has saved the order.
 *
 * @package Akibara
 * @since 1.0.0
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Store tracking codes received through the authenticated WooCommerce REST API.
 *
 * @param mixed $order         Order updated through REST; anything else is ignored.
 * @param mixed $tracking_code Comma-separated tracking codes supplied by Blue Express.
 * @return bool Whether at least one code was persisted.
 */
function akibara_bluex_store_tracking_codes( $order, $tracking_code ): bool {
	if ( ! $order instanceof WC_Order || ! is_string( $tracking_code ) || '' === trim( $tracking_code ) ) {
		return false;
	}

	// El tercero es el ÚNICO que sabe persistir el código. Si desaparece —se
	// desactiva, se actualiza, lo renombra— la API sigue respondiendo 200 y el
	// número de seguimiento del cliente se pierde sin una línea en ningún lado.
	// Antes de dejar rastro, este `return false` costaba un pedido mudo.
	if ( ! function_exists( 'wc_correios_updateTrackingCode' ) ) {
		akibara_bluex_log_discarded_tracking( $order, $tracking_code );

		return false;
	}

	$updated = false;
	foreach ( preg_split( '/\s*,\s*/', $tracking_code ) as $code ) {
		$code = sanitize_text_field( $code );
		if ( '' !== $code ) {
			$updated = wc_correios_updateTrackingCode( $order, $code ) || $updated;
		}
	}

	return $updated;
}

/**
 * Leave a trace when a tracking code is dropped because the bridge lost its peer.
 *
 * @param WC_Order $order         Order whose tracking code could not be stored.
 * @param string   $tracking_code Code received and discarded.
 * @return void
 */
function akibara_bluex_log_discarded_tracking( WC_Order $order, string $tracking_code ): void {
	if ( ! function_exists( 'wc_get_logger' ) ) {
		return;
	}

	wc_get_logger()->error(
		sprintf(
			'wc_correios_updateTrackingCode() no existe: se descartó el seguimiento «%1$s» del pedido #%2$d. Revisar bluex-for-woocommerce.',
			$tracking_code,
			$order->get_id()
		),
		array( 'source' => 'akibara-bluex' )
	);
}

/**
 * Modern REST field callback, replacing Blue Express' WP_Post-only callback.
 *
 * @param string   $tracking_code Tracking code supplied by Blue Express.
 * @param WC_Order $order         REST order object.
 * @return bool
 */
function akibara_bluex_update_rest_tracking_field( $tracking_code, $order ): bool {
	return akibara_bluex_store_tracking_codes( $order, $tracking_code );
}

/**
 * Replace the third-party callback after it registers its REST field.
 *
 * @return void
 */
function akibara_bluex_register_rest_tracking_compatibility(): void {
	global $wp_rest_additional_fields;

	if ( isset( $wp_rest_additional_fields['shop_order']['correios_tracking_code'] ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $wp_rest_additional_fields es un global de WP core (wp-includes/rest-api.php); no lo definimos, sólo sustituimos dentro el update_callback que registró el plugin de terceros. Prefijarlo escribiría en un global que core no lee.
		$wp_rest_additional_fields['shop_order']['correios_tracking_code']['update_callback'] = 'akibara_bluex_update_rest_tracking_field';
	}
}
add_action( 'rest_api_init', 'akibara_bluex_register_rest_tracking_compatibility', 101 );

/**
 * Fallback for WooCommerce REST controllers that do not process additional fields.
 *
 * @param WC_Order        $order    Order updated through REST.
 * @param WP_REST_Request $request  Request that updated the order.
 * @param bool            $creating Whether the order was created.
 * @return void
 */
function akibara_bluex_sync_rest_tracking_code( $order, $request, bool $creating ): void {
	unset( $creating );

	if ( ! $request instanceof WP_REST_Request ) {
		return;
	}

	akibara_bluex_store_tracking_codes( $order, $request->get_param( 'correios_tracking_code' ) );
}
add_action( 'woocommerce_rest_insert_shop_order_object', 'akibara_bluex_sync_rest_tracking_code', 20, 3 );
