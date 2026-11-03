<?php
/**
 * Cart and Checkout Blocks plugin composition root.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

use Akibara\Business\Contracts\CheckoutDataProvider;
use Akibara\Business\Plugin as BusinessPlugin;

defined( 'ABSPATH' ) || exit;

/**
 * Composes all dedicated WooCommerce Blocks adapters.
 *
 * @since 2.0.0
 */
final class Plugin {

	/**
	 * Boots the plugin after Business and WooCommerce are available.
	 *
	 * @internal
	 */
	public static function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( BusinessPlugin::class ) ) {
			return;
		}

		$business = BusinessPlugin::instance();
		if ( ! method_exists( $business, 'checkout_data' ) ) {
			return;
		}

		$data_provider = $business->checkout_data();
		if ( ! $data_provider instanceof CheckoutDataProvider ) {
			return;
		}

		$blocks_page = new BlocksPage();
		$address     = new AddressIntegration( $data_provider, $blocks_page );
		$comunas     = new ComunaDirectory();
		$pudo        = method_exists( $business, 'pudo_checkout' )
			? $business->pudo_checkout()
			: null;

		( new CheckoutFields( $data_provider, $comunas ) )->register();
		( new RutPrivacy() )->register();
		( new StoreApiIntegration( $data_provider, $blocks_page ) )->register();
		( new ShippingPromise( $blocks_page ) )->register();
		( new RuntimeCompatibility( $blocks_page ) )->register();
		( new Assets( $blocks_page, $address, $comunas ) )->register();
		( new Shortcodes() )->register();
		$address->register();

		if ( interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
			( new CheckoutBlockIntegration() )->register();
		}

		if ( is_object( $pudo ) ) {
			( new PudoIntegration( $pudo, $blocks_page ) )->register();
		}
	}
}
