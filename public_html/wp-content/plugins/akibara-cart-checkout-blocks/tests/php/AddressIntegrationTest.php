<?php
/**
 * Tests for the WooCommerce address-provider adapter.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use Akibara\CartCheckoutBlocks\AddressIntegration;
use Akibara\CartCheckoutBlocks\BlocksPage;
use Akibara\CartCheckoutBlocks\GooglePlacesAddressProvider;
use PHPUnit\Framework\TestCase;

final class AddressIntegrationTest extends TestCase {

	/**
	 * Loads WooCommerce address-provider fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/fixtures/checkout-runtime.php';
		\Brain\Monkey\setUp();
		\Brain\Monkey\Functions\when( 'esc_url' )->returnArg();
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should preserve mixed provider strings and objects without array_unique errors.
	 */
	public function test_register_provider_preserves_mixed_provider_types(): void {
		$provider_object = new \Akibara_Test_Address_Provider();
		$sut             = new AddressIntegration(
			new CheckoutRuntimeDataProviderStub(),
			new BlocksPage()
		);

		$result = $sut->register_provider(
			array(
				'Third_Party_Address_Provider',
				$provider_object,
			)
		);

		self::assertSame( 'Third_Party_Address_Provider', $result[0] );
		self::assertSame( $provider_object, $result[1] );
		self::assertSame( GooglePlacesAddressProvider::class, $result[2] );
	}

	/**
	 * @testdox Should not register a second Akibara provider with the same provider ID.
	 */
	public function test_register_provider_deduplicates_existing_provider_id(): void {
		$existing = new \Akibara_Test_Address_Provider( 'akibara-google-places' );
		$sut      = new AddressIntegration(
			new CheckoutRuntimeDataProviderStub(),
			new BlocksPage()
		);

		$result = $sut->register_provider( array( $existing ) );

		self::assertCount( 1, $result );
		self::assertSame( $existing, $result[0] );
	}

	/**
	 * @testdox Should not register a duplicate Akibara provider class string.
	 */
	public function test_register_provider_deduplicates_existing_class_string(): void {
		$sut = new AddressIntegration(
			new CheckoutRuntimeDataProviderStub(),
			new BlocksPage()
		);

		$result = $sut->register_provider( array( '\\' . GooglePlacesAddressProvider::class ) );

		self::assertSame( array( '\\' . GooglePlacesAddressProvider::class ), $result );
	}

	/**
	 * @testdox Should expose the current Google Maps attribution in the provider metadata.
	 */
	public function test_google_provider_uses_current_maps_attribution(): void {
		$provider = new GooglePlacesAddressProvider();

		self::assertSame( GooglePlacesAddressProvider::ID, $provider->id );
		self::assertStringContainsString( 'google-maps-logo-white.svg', $provider->branding_html );
		self::assertStringContainsString( 'alt="Google Maps"', $provider->branding_html );
		self::assertStringContainsString( 'height="18"', $provider->branding_html );
		self::assertStringNotContainsString( 'Powered by Google', $provider->branding_html );
	}
}
