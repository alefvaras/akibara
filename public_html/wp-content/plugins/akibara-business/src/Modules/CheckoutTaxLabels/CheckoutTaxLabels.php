<?php
/**
 * Canonical Chilean tax labels for WooCommerce totals.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\CheckoutTaxLabels;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps customer-facing tax vocabulary consistent across cart and orders.
 *
 * @since 1.96.0
 */
final class CheckoutTaxLabels {

	/**
	 * Registers tax-label filters.
	 *
	 * @since 1.96.0
	 */
	public function register(): void {
		add_filter( 'woocommerce_countries_tax_or_vat', array( $this, 'handle_tax_or_vat' ) );
		add_filter( 'woocommerce_cart_tax_totals', array( $this, 'handle_cart_tax_totals' ) );
		add_filter( 'woocommerce_order_get_tax_totals', array( $this, 'handle_order_tax_totals' ) );
	}

	/**
	 * Handle the generic WooCommerce tax label.
	 *
	 * @internal
	 *
	 * @param mixed $label Existing tax label.
	 * @return string
	 */
	public function handle_tax_or_vat( $label ): string {
		unset( $label );

		return 'IVA';
	}

	/**
	 * Handle cart tax-total labels without changing their amounts.
	 *
	 * @internal
	 *
	 * @param array<string|int,mixed> $tax_totals Cart tax totals.
	 * @return array<string|int,mixed>
	 */
	public function handle_cart_tax_totals( array $tax_totals ): array {
		return self::normalize_tax_totals( $tax_totals );
	}

	/**
	 * Handle order tax-total labels without changing their amounts.
	 *
	 * @internal
	 *
	 * @param array<string|int,mixed> $tax_totals Order tax totals.
	 * @return array<string|int,mixed>
	 */
	public function handle_order_tax_totals( array $tax_totals ): array {
		return self::normalize_tax_totals( $tax_totals );
	}

	/**
	 * Replaces only labels on WooCommerce tax-total objects.
	 *
	 * @param array<string|int,mixed> $tax_totals Tax totals.
	 * @return array<string|int,mixed>
	 */
	private static function normalize_tax_totals( array $tax_totals ): array {
		foreach ( $tax_totals as $tax_total ) {
			if ( is_object( $tax_total ) && property_exists( $tax_total, 'label' ) ) {
				$tax_total->label = 'IVA';
			}
		}

		return $tax_totals;
	}
}
