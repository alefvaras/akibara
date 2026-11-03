<?php
/**
 * Minimal WooCommerce function fixture for isolated adapter tests.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

if ( ! function_exists( 'WC' ) ) {
	/**
	 * Returns the current WooCommerce runtime fixture.
	 *
	 * @return object|null
	 */
	function WC(): ?object {
		$woocommerce = $GLOBALS['akibara_test_woocommerce'] ?? null;

		return is_object( $woocommerce ) ? $woocommerce : null;
	}
}

if ( ! function_exists( 'is_cart' ) ) {
	/**
	 * Returns whether the fixture is rendering the cart page.
	 */
	function is_cart(): bool {
		$context = $GLOBALS['akibara_test_blocks_page'] ?? array();

		return is_array( $context ) && ! empty( $context['cart'] );
	}
}

if ( ! function_exists( 'is_checkout' ) ) {
	/**
	 * Returns whether the fixture is rendering the checkout page.
	 */
	function is_checkout(): bool {
		$context = $GLOBALS['akibara_test_blocks_page'] ?? array();

		return is_array( $context ) && ! empty( $context['checkout'] );
	}
}

if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
	/**
	 * Keeps checkout endpoint exclusions disabled in the fixture.
	 *
	 * La firma es la de WooCommerce (`is_wc_endpoint_url( $endpoint = false )`):
	 * con el argumento obligatorio, quien la llamara sin él —como hace el propio
	 * WooCommerce— reventaba según qué prueba hubiera cargado antes esta fixture
	 * (rojos sólo con `--order-by=reverse`/`random`, 2026-09-19).
	 *
	 * @param string|false $endpoint Endpoint slug, o false para «cualquiera».
	 */
	function is_wc_endpoint_url( $endpoint = false ): bool {
		unset( $endpoint );

		return false;
	}
}

if ( ! function_exists( 'wc_get_page_id' ) ) {
	/**
	 * Returns deterministic WooCommerce page IDs.
	 *
	 * @param string $page Page slug.
	 */
	function wc_get_page_id( string $page ): int {
		return 'checkout' === $page ? 22 : 11;
	}
}

if ( ! function_exists( 'has_block' ) ) {
	/**
	 * Mirrors whether the requested fixture page contains its WooCommerce Block.
	 *
	 * @param string $block_name Block name.
	 * @param int    $page_id    Page ID.
	 */
	function has_block( string $block_name, int $page_id ): bool {
		$context = $GLOBALS['akibara_test_blocks_page'] ?? array();
		if ( ! is_array( $context ) ) {
			return false;
		}

		if ( 'woocommerce/cart' === $block_name && 11 === $page_id ) {
			return ! empty( $context['cart'] );
		}

		return 'woocommerce/checkout' === $block_name
			&& 22 === $page_id
			&& ! empty( $context['checkout'] );
	}
}
