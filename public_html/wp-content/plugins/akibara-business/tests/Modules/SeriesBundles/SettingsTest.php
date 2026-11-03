<?php
/**
 * Pruebas de elegibilidad de stock para packs por serie.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Una preventa no cuenta cuando el switch está apagado
	 */
	public function test_excludes_preorders_when_option_is_disabled(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => Settings::OPTION_ALLOW_BACKORDER === $key ? 'no' : $default
		);

		self::assertFalse( ( new Settings() )->is_stock_eligible( new \WC_Product( 'onbackorder' ) ) );
	}

	/**
	 * @testdox Una preventa cuenta cuando el switch está encendido
	 */
	public function test_includes_preorders_when_option_is_enabled(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => Settings::OPTION_ALLOW_BACKORDER === $key ? 'yes' : $default
		);

		self::assertTrue( ( new Settings() )->is_stock_eligible( new \WC_Product( 'onbackorder' ) ) );
	}

	/**
	 * @testdox El stock disponible cuenta sin depender del switch de preventa
	 */
	public function test_keeps_in_stock_products_eligible(): void {
		Functions\when( 'get_option' )->justReturn( 'no' );

		self::assertTrue( ( new Settings() )->is_stock_eligible( new \WC_Product( 'instock' ) ) );
	}
}
