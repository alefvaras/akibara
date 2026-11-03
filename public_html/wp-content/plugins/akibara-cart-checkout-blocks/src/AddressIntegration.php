<?php
/**
 * Google Places adapter for Checkout Blocks.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

use Akibara\Business\Contracts\CheckoutDataProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Connects Business address configuration to WooCommerce's address provider.
 *
 * @since 2.0.0
 */
final class AddressIntegration {

	private CheckoutDataProvider $data_provider;

	private BlocksPage $blocks_page;

	/**
	 * Initializes the address adapter.
	 *
	 * @param CheckoutDataProvider $data_provider Stable Business contract.
	 * @param BlocksPage           $blocks_page   Blocks page resolver.
	 *
	 * @since 2.0.0
	 */
	public function __construct( CheckoutDataProvider $data_provider, BlocksPage $blocks_page ) {
		$this->data_provider = $data_provider;
		$this->blocks_page   = $blocks_page;
	}

	/**
	 * Registers the address provider and the core feature gate.
	 *
	 * @since 2.0.0
	 */
	public function register(): void {
		add_filter( 'woocommerce_address_providers', array( $this, 'register_provider' ) );
		add_filter( 'pre_option_woocommerce_address_autocomplete_enabled', array( $this, 'enable_core_address_autocomplete' ) );
	}

	/**
	 * Registers Akibara's Google Places provider when configured.
	 *
	 * @internal
	 *
	 * @param array<int,string|\WC_Address_Provider> $providers Registered providers.
	 * @return array<int,string|\WC_Address_Provider>
	 */
	public function register_provider( array $providers ): array {
		$config = $this->data_provider->address_autocomplete_config();
		if ( empty( $config['enabled'] ) || ! class_exists( 'WC_Address_Provider' ) ) {
			return $providers;
		}

		foreach ( $providers as $provider ) {
			$class_name = is_object( $provider ) ? get_class( $provider ) : (string) $provider;
			if (
				ltrim( $class_name, '\\' ) === ltrim( GooglePlacesAddressProvider::class, '\\' )
				|| ( $provider instanceof \WC_Address_Provider && GooglePlacesAddressProvider::ID === (string) $provider->id )
			) {
				return array_values( $providers );
			}
		}

		$providers[] = GooglePlacesAddressProvider::class;

		return array_values( $providers );
	}

	/**
	 * Enables WooCommerce address autocomplete only on the Blocks checkout.
	 *
	 * @internal
	 *
	 * @param mixed $pre_option Existing short-circuit value.
	 * @return mixed
	 */
	public function enable_core_address_autocomplete( $pre_option ) {
		$config = $this->data_provider->address_autocomplete_config();

		return $this->blocks_page->is_checkout() && ! empty( $config['enabled'] )
			? 'yes'
			: $pre_option;
	}

	/**
	 * Enqueues the Google Places bridge with server-authoritative configuration.
	 *
	 * @since 2.0.0
	 */
	public function enqueue(): void {
		$config = $this->data_provider->address_autocomplete_config();
		if (
			empty( $config['enabled'] )
			|| '' === (string) $config['api_key']
			|| ! wp_script_is( 'wc-address-autocomplete-common', 'registered' )
		) {
			return;
		}

		$script_path = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . 'assets/js/google-places-address-provider.js';
		wp_enqueue_script(
			'akibara-cart-checkout-blocks-google-places',
			AKIBARA_CART_CHECKOUT_BLOCKS_URL . 'assets/js/google-places-address-provider.js',
			array( 'wc-address-autocomplete-common', 'wc-blocks-data-store', 'wp-data' ),
			AKIBARA_CART_CHECKOUT_BLOCKS_VERSION . ( file_exists( $script_path ) ? '.' . filemtime( $script_path ) : '' ),
			true
		);
		wp_script_add_data( 'akibara-cart-checkout-blocks-google-places', 'strategy', 'defer' );
		wp_add_inline_script(
			'akibara-cart-checkout-blocks-google-places',
			'window.akbCartCheckoutBlocksGoogle = ' . wp_json_encode(
				array(
					'loaderUrl'  => add_query_arg(
						array(
							'key'       => (string) $config['api_key'],
							'libraries' => 'places',
							'loading'   => 'async',
							'v'         => 'weekly',
							'language'  => 'es',
							'region'    => 'CL',
						),
						'https://maps.googleapis.com/maps/api/js'
					),
					// Dueño único del id: el propio proveedor. El literal estaba
					// copiado aquí, y una divergencia dejaba al JS sin
					// emparejar con el proveedor que WooCommerce registra —sin
					// error de PHP, sólo autocompletado muerto.
					'providerId' => GooglePlacesAddressProvider::ID,
					'regionMap'  => $config['region_map'],
				)
			) . ';',
			'before'
		);
	}
}
