<?php
/**
 * `PromiseWindow`: la promesa de compra y la que rige, con UNA regla para el
 * comando de consola y para la pantalla «Preventas».
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\PromiseWindow;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;

final class PromiseWindowTest extends TestCase {

	private function linea( int $id, int $min, int $max, string $pais, bool $espera = true ): WC_Order_Item_Product {
		$item           = new WC_Order_Item_Product();
		$item->akb_id   = $id;
		$item->akb_meta = array(
			MetaKeys::ITEM_WAS_PREVENTA            => $espera ? 'yes' : '',
			MetaKeys::ITEM_MIN_AT_PURCHASE         => (string) $min,
			MetaKeys::ITEM_MAX_AT_PURCHASE         => (string) $max,
			MetaKeys::ITEM_COUNTRY_NAME_AT_PURCHASE => $pais,
		);
		return $item;
	}

	/**
	 * @testdox La ventana del pedido es la del tomo que MÁS demora, con SU país.
	 */
	public function test_window_is_the_latest_line_with_its_own_country(): void {
		$order            = new WC_Order();
		$order->akb_items = array(
			$this->linea( 1, 100, 200, 'Argentina' ),
			$this->linea( 2, 300, 900, 'España' ),
			$this->linea( 3, 0, 0, 'Chile', false ), // no esperaba: no cuenta
		);

		$w = PromiseWindow::of( $order );

		self::assertSame( 300, $w['min'] );
		self::assertSame( 900, $w['max'] );
		self::assertSame( 'España', $w['pais'], 'Atribuirle a Argentina el atraso de España arruina el informe por país.' );
		self::assertNull( $w['marca'] );
	}

	/**
	 * @testdox Sin ventana en ninguna línea, el país igual se conoce.
	 */
	public function test_country_is_known_even_without_a_window(): void {
		$order            = new WC_Order();
		$order->akb_items = array( $this->linea( 1, 0, 0, 'Argentina' ) );

		$w = PromiseWindow::of( $order );

		self::assertNull( $w['max'] );
		self::assertSame( 'Argentina', $w['pais'] );
	}

	/**
	 * @testdox La promesa VIGENTE gana sobre la de compra cuando se recalculó.
	 */
	public function test_the_current_promise_wins_over_the_purchase_one(): void {
		$order            = new WC_Order( array( MetaKeys::ORDER_SUMMARY_MAX => '1500' ) );
		$order->akb_items = array( $this->linea( 1, 100, 900, 'Argentina' ) );

		$w = PromiseWindow::vigente( $order );

		self::assertSame( 1500, $w['max'] );
		self::assertTrue( $w['recalcada'] );
		self::assertSame( 100, $w['min'] );
	}

	/**
	 * @testdox Sin recálculo, `vigente()` es exactamente la promesa de compra.
	 */
	public function test_without_recalculation_it_is_the_purchase_promise(): void {
		$order            = new WC_Order();
		$order->akb_items = array( $this->linea( 1, 100, 900, 'Argentina' ) );

		$w = PromiseWindow::vigente( $order );

		self::assertSame( 900, $w['max'] );
		self::assertFalse( $w['recalcada'] );
	}

	/**
	 * @testdox Un pedido vivo con todas sus esperas reembolsadas no cuenta como promesa cumplida.
	 */
	public function test_a_refunded_wait_is_not_auditable(): void {
		$order               = new WC_Order();
		$order->akb_status   = 'processing';
		$order->akb_items    = array( $this->linea( 1, 100, 900, 'Argentina' ) );
		self::assertTrue( PromiseWindow::auditable( $order ) );

		$order->akb_refunded = array( 1 => 1 );
		self::assertFalse( PromiseWindow::auditable( $order ), 'La rama de reembolso del cron lo marca «listo» al instante: entraba como entregado en plazo.' );

		$cancelado             = new WC_Order();
		$cancelado->akb_status = 'cancelled';
		$cancelado->akb_items  = array( $this->linea( 1, 100, 900, 'Argentina' ) );
		self::assertFalse( PromiseWindow::auditable( $cancelado ) );
	}

	/**
	 * @testdox Los estados sin promesa son los de siempre, sin prefijo `wc-`.
	 */
	public function test_states_without_a_promise(): void {
		self::assertSame( array( 'cancelled', 'refunded', 'failed', 'trash' ), PromiseWindow::ESTADOS_SIN_PROMESA );
	}
}
