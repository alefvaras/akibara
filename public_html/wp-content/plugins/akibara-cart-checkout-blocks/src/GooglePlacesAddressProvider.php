<?php
/**
 * Google Places address provider metadata for WooCommerce Blocks.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

use WC_Address_Provider;

defined( 'ABSPATH' ) || exit;

final class GooglePlacesAddressProvider extends WC_Address_Provider {

	public const ID = 'akibara-google-places';

	public function __construct() {
		$base_url = defined( 'AKIBARA_CART_CHECKOUT_BLOCKS_URL' )
			? AKIBARA_CART_CHECKOUT_BLOCKS_URL
			: '/wp-content/plugins/akibara-cart-checkout-blocks/';
		$logo_url = $base_url . 'assets/images/google-maps-logo-white.svg';

		$this->id            = self::ID;
		$this->name          = 'Google Places';
		$this->branding_html = sprintf(
			'<img src="%s" alt="Google Maps" width="98" height="18" style="display:block;width:98px;height:18px;">',
			esc_url( $logo_url )
		);
	}
}
