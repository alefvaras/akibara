<?php
/**
 * Pruebas unitarias para `OrderItemDisplay`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\OrderItemDisplay;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order_Item_Product;
use WC_Product;

final class OrderItemDisplayTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'wp_date' )->alias( static fn( string $f, ?int $ts = null ): string => gmdate( $f, (int) $ts ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_linea_sellada_total_muestra_apartado_en_casillero(): void {
		$item               = new WC_Order_Item_Product();
		$item->akb_quantity = 1;
		$item->akb_meta     = array(
			MetaKeys::ITEM_WAS_PREVENTA     => 'yes',
			MetaKeys::ITEM_TYPE_AT_PURCHASE => Availability::TYPE_RELEASE_DATE,
			MetaKeys::ITEM_ARRIVED          => 'yes',
			MetaKeys::ITEM_ARRIVED_AT       => '1700000000',
		);

		$display = new OrderItemDisplay();
		ob_start();
		$display->render( 1, $item );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '🔒 Apartado en casillero', $html );
		self::assertStringNotContainsString( 'Disponible hoy', $html );
	}

	public function test_linea_con_arribo_parcial_muestra_conteo_de_unidades_en_casillero(): void {
		$item               = new WC_Order_Item_Product();
		$item->akb_quantity = 3;
		$item->akb_meta     = array(
			MetaKeys::ITEM_WAS_PREVENTA     => 'yes',
			MetaKeys::ITEM_TYPE_AT_PURCHASE => Availability::TYPE_AUTO,
			MetaKeys::ITEM_ARRIVED_QTY      => '2',
		);

		$display = new OrderItemDisplay();
		ob_start();
		$display->render( 1, $item );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '🔒 Apartado parcial (2/3 uds en casillero)', $html );
		self::assertStringNotContainsString( 'Disponible hoy', $html );
	}

	public function test_linea_con_producto_instock_sin_sellar_muestra_disponible_hoy(): void {
		$item               = new WC_Order_Item_Product();
		$item->akb_quantity = 1;
		$item->akb_product  = new WC_Product( StockStatus::IN_STOCK );
		$item->akb_meta     = array(
			MetaKeys::ITEM_WAS_PREVENTA     => 'yes',
			MetaKeys::ITEM_TYPE_AT_PURCHASE => Availability::TYPE_RELEASE_DATE,
		);

		$display = new OrderItemDisplay();
		ob_start();
		$display->render( 1, $item );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Disponible hoy', $html );
		self::assertStringNotContainsString( 'Apartado', $html );
	}
}
