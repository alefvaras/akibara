<?php
/**
 * Checkout privacy architecture regression tests.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules;

use PHPUnit\Framework\TestCase;

final class CheckoutDataPrivacyTest extends TestCase {

	/**
	 * @testdox Business must not retain the retired browser address cache.
	 */
	public function test_business_has_no_checkout_address_storage_module(): void {
		$plugin_root = dirname( __DIR__, 2 );
		$plugin      = file_get_contents( $plugin_root . '/src/Plugin.php' );

		self::assertIsString( $plugin );
		self::assertStringNotContainsString( 'CheckoutAutofill', $plugin );
		self::assertFileDoesNotExist( $plugin_root . '/src/Modules/CheckoutAutofill/CheckoutAutofill.php' );
		self::assertFileDoesNotExist( $plugin_root . '/assets/js/checkout-autofill.js' );
	}

	/**
	 * @testdox Business exposes checkout domain data but owns no classic Cart or Checkout presentation.
	 */
	public function test_business_has_no_classic_cart_checkout_presentation(): void {
		$plugin_root = dirname( __DIR__, 2 );
		$removed     = array(
			'/src/Modules/CheckoutPudo/CheckoutPudo.php',
			'/src/Modules/SeriesBundles/CartIntegration.php',
			'/src/Modules/SeriesBundles/BypCartNoticeInjector.php',
			'/assets/js/checkout-pudo.js',
			'/assets/css/checkout-pudo.css',
			'/assets/js/phone.js',
			'/assets/css/phone.css',
			'/assets/js/rut.js',
			'/assets/css/rut.css',
		);
		foreach ( $removed as $path ) {
			self::assertFileDoesNotExist( $plugin_root . $path );
		}

		$presentation_sources = array(
			'/src/Modules/EstimatedDelivery/BannerRenderer.php',
			'/src/Modules/FreeShippingProgress/FreeShippingProgress.php',
			'/src/Modules/OrderPreventa/PreventaUX.php',
			'/src/Modules/SeriesBundles/BypCartNoticeProvider.php',
		);
		$classic_hooks        = array(
			'woocommerce_before_cart',
			'woocommerce_after_cart_table',
			'woocommerce_before_checkout_form',
			'woocommerce_review_order_before_payment',
			'woocommerce_review_order_before_submit',
			'woocommerce_update_order_review_fragments',
		);

		foreach ( $presentation_sources as $path ) {
			$source = file_get_contents( $plugin_root . $path );
			self::assertIsString( $source );
			foreach ( $classic_hooks as $hook ) {
				self::assertStringNotContainsString( $hook, $source, $path . ' must not own classic presentation.' );
			}
		}
	}
}
