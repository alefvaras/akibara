<?php
/**
 * Cart Block helper shortcodes.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the title and empty-cart template used by the Cart page.
 *
 * @since 2.0.0
 */
final class Shortcodes {

	/**
	 * Registers Cart page shortcodes.
	 *
	 * @since 2.0.0
	 */
	public function register(): void {
		add_shortcode( 'akibara_cart_blocks_title', array( $this, 'render_cart_title' ) );
		add_shortcode( 'akibara_cart_blocks_empty', array( $this, 'render_cart_empty' ) );
	}

	/**
	 * Renders the Cart page title.
	 *
	 * @internal
	 *
	 * @return string
	 */
	public function render_cart_title(): string {
		return sprintf(
			'<header class="akb-page-title"><h1>%s</h1></header>',
			esc_html( 'Tu carrito' )
		);
	}

	/**
	 * Renders the canonical empty-cart template.
	 *
	 * @internal
	 *
	 * @return string
	 */
	public function render_cart_empty(): string {
		ob_start();
		wc_get_template( 'cart/cart-empty.php' );

		return (string) ob_get_clean();
	}
}
