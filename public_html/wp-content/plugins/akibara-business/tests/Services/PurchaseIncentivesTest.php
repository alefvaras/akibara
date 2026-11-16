<?php
/** Cache and configuration boundaries for the shared purchase preview. */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Services\PurchaseIncentives;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PurchaseIncentivesTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Cacheable configuration never reads a user's tier or cart. */
	public function test_public_configuration_only_contains_reference_settings(): void {
		Functions\when( 'get_option' )->alias( static fn ( $key, $default = false ) => 'akibara_fsp_carrier_threshold' === $key ? 75000 : $default );
		Functions\expect( 'get_user_meta' )->never();
		Functions\expect( 'WC' )->never();
		$result = PurchaseIncentives::configuration();
		self::assertSame( 1.0, $result['rewards']['multiplier'] );
		self::assertSame( array( 'enabled' => true, 'threshold' => 75000 ), $result['shipping'] );
		self::assertArrayNotHasKey( 'product_ids', $result );
	}

	/** @testdox Turning off the shipping widget also removes its selection reference. */
	public function test_shipping_switch_and_missing_threshold_are_not_replaced_by_a_magic_number(): void {
		Functions\when( 'get_option' )->alias( static fn ( $key, $default = false ) => 'akibara_fsp_enabled' === $key ? 'no' : $default );
		$result = PurchaseIncentives::configuration();
		self::assertFalse( $result['shipping']['enabled'] );
		self::assertSame( 0, $result['shipping']['threshold'] );
	}

	/** @testdox Only logged-in series pages receive private estimate headers. */
	public function test_personal_series_response_is_private_even_when_tiers_are_disabled(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'is_tax' )->justReturn( true );
		$calls = array();
		Functions\when( 'nocache_headers' )->alias( static function () use ( &$calls ) { $calls[] = 'private'; } );
		Functions\when( 'do_action' )->alias( static function ( ...$args ) use ( &$calls ) { $calls[] = $args; } );
		PurchaseIncentives::private_series_headers();
		self::assertSame( array( 'private', array( 'litespeed_control_set_nocache', 'Estimación personal de compra Akibara' ) ), $calls );
	}

	/** @testdox Cacheable guest series and unrelated pages retain their existing cache policy. */
	public function test_unrelated_or_guest_pages_do_not_change_cache_policy(): void {
		$calls = 0;
		Functions\when( 'nocache_headers' )->alias( static function () use ( &$calls ) { $calls++; } );
		Functions\when( 'do_action' )->alias( static function () use ( &$calls ) { $calls++; } );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		PurchaseIncentives::private_series_headers();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'is_tax' )->justReturn( false );
		PurchaseIncentives::private_series_headers();
		self::assertSame( 0, $calls );
	}

	/** @testdox An uninitialized WooCommerce cart is unknown rather than an invented empty cart. */
	public function test_uninitialized_cart_returns_unknown_context(): void {
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => null ) );
		self::assertNull( PurchaseIncentives::current_cart_state() );
	}
}
