<?php
/**
 * Tests for the series navigation shown on product detail pages.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\PdpEnhancer;

use Akibara\Business\Modules\PdpEnhancer\PdpSeriesEnhancer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );
defined( 'AKIBARA_BUSINESS_URL' ) || define( 'AKIBARA_BUSINESS_URL', 'https://example.test/wp-content/plugins/akibara-business/' );
defined( 'AKIBARA_BUSINESS_VERSION' ) || define( 'AKIBARA_BUSINESS_VERSION', 'test-version' );

final class PdpSeriesEnhancerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_asset_is_not_loaded_outside_product_pages(): void {
		$enqueue_calls = 0;
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'wp_enqueue_style' )->alias(
			static function () use ( &$enqueue_calls ): void {
				++$enqueue_calls;
			}
		);

		( new PdpSeriesEnhancer() )->enqueue_assets();

		self::assertSame( 0, $enqueue_calls );
	}

	public function test_asset_is_versioned_and_loaded_on_product_pages(): void {
		$captured_args = array();
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( ...$args ) use ( &$captured_args ): void {
				$captured_args = $args;
			}
		);

		( new PdpSeriesEnhancer() )->enqueue_assets();

		self::assertSame(
			array(
				'akb-pdp-series-nav',
				AKIBARA_BUSINESS_URL . 'assets/css/pdp-series-nav.css',
				array(),
				AKIBARA_BUSINESS_VERSION . '.' . filemtime( AKIBARA_BUSINESS_DIR . 'assets/css/pdp-series-nav.css' ),
			),
			$captured_args
		);
	}

	public function test_legacy_duplicate_cross_sell_is_fully_removed(): void {
		$source = (string) file_get_contents(
			AKIBARA_BUSINESS_DIR . 'src/Modules/PdpEnhancer/PdpSeriesEnhancer.php'
		);

		self::assertStringNotContainsString( 'render_series_cross_sells', $source );
		self::assertStringNotContainsString( 'akb-pdp-cross-sells', $source );
		self::assertStringNotContainsString( '<style>', $source );
	}
}
