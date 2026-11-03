<?php
/**
 * Cart and checkout Blocks page detection.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves whether the current WooCommerce page uses the Cart or Checkout Block.
 *
 * @since 2.0.0
 */
final class BlocksPage {

	/**
	 * Returns whether the current page contains the Checkout Block.
	 *
	 * @return bool
	 *
	 * @since 2.0.0
	 */
	public function is_checkout(): bool {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) {
			return false;
		}

		$page_id = wc_get_page_id( 'checkout' );

		return $page_id > 0 && has_block( 'woocommerce/checkout', $page_id );
	}

	/**
	 * Returns whether the current page contains the Cart Block.
	 *
	 * @return bool
	 *
	 * @since 2.0.0
	 */
	public function is_cart(): bool {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return false;
		}

		$page_id = wc_get_page_id( 'cart' );

		return $page_id > 0 && has_block( 'woocommerce/cart', $page_id );
	}
}
