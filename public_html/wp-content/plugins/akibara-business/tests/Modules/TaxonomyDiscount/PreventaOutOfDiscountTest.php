<?php
/**
 * Verificación de elegibilidad de stock para el descuento por taxonomía.
 *
 * Mandato expreso del dueño: «ESE DESCUENTO POR TAXONOMIA NO DEBE RESTRINGIRSE
 * POR ONBOARD DEBE PERMITIRME CAMBIAR TODO».
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class PreventaOutOfDiscountTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param int    $id     Id del producto.
	 * @param string $estado `_stock_status` crudo de WooCommerce.
	 */
	private function producto( int $id, string $estado ): \WC_Product {
		return new class( $id, $estado ) extends \WC_Product {
			private int $pid;
			private string $estado;

			public function __construct( int $id, string $estado ) {
				$this->pid    = $id;
				$this->estado = $estado;
			}

			public function get_id(): int {
				return $this->pid;
			}

			public function get_stock_status(): string {
				return $this->estado;
			}
		};
	}

	private function settings_con( string $alcance ): Settings {
		$GLOBALS['akibara_test_options'] = array( Settings::OPTION_APPLY_TO => $alcance );

		return new Settings();
	}

	/**
	 * @testdox Un producto en onbackorder entra plenamente en «Disponibles + a pedido» sin bloqueos.
	 */
	public function test_onbackorder_product_is_eligible_in_instock_and_onbackorder(): void {
		$producto = $this->producto( 12945, 'onbackorder' );
		self::assertTrue(
			$this->settings_con( Settings::APPLY_INSTOCK_AND_ONBACKORDER )->is_stock_eligible( $producto ),
			'El producto en onbackorder no debe ser bloqueado ni restringido.'
		);
	}

	/**
	 * @testdox Un producto disponible (instock) entra en «Disponibles + a pedido».
	 */
	public function test_instock_product_is_eligible_in_instock_and_onbackorder(): void {
		$producto = $this->producto( 555, 'instock' );
		self::assertTrue( $this->settings_con( Settings::APPLY_INSTOCK_AND_ONBACKORDER )->is_stock_eligible( $producto ) );
	}

	/**
	 * @testdox Un producto agotado (outofstock) queda fuera de «Disponibles + a pedido».
	 */
	public function test_outofstock_product_is_excluded_from_instock_and_onbackorder(): void {
		$producto = $this->producto( 2, 'outofstock' );
		self::assertFalse( $this->settings_con( Settings::APPLY_INSTOCK_AND_ONBACKORDER )->is_stock_eligible( $producto ) );
	}

	/**
	 * @testdox «Solo disponibles» deja fuera a onbackorder y outofstock.
	 */
	public function test_in_stock_only_excludes_everything_else(): void {
		$ajustes = $this->settings_con( Settings::APPLY_INSTOCK_ONLY );

		self::assertTrue( $ajustes->is_stock_eligible( $this->producto( 1, 'instock' ) ) );
		self::assertFalse( $ajustes->is_stock_eligible( $this->producto( 12945, 'onbackorder' ) ) );
		self::assertFalse( $ajustes->is_stock_eligible( $this->producto( 2, 'outofstock' ) ) );
	}

	/**
	 * @testdox «Todos» alcanza a instock, onbackorder y outofstock (permite cambiar todo).
	 */
	public function test_all_still_means_all(): void {
		$ajustes = $this->settings_con( Settings::APPLY_ALL );

		self::assertTrue( $ajustes->is_stock_eligible( $this->producto( 1, 'instock' ) ) );
		self::assertTrue( $ajustes->is_stock_eligible( $this->producto( 12945, 'onbackorder' ) ) );
		self::assertTrue( $ajustes->is_stock_eligible( $this->producto( 2, 'outofstock' ) ) );
	}
}
