<?php
/**
 * Pruebas unitarias para UnpaidOrdersSweeper (cancelación de órdenes pendientes sin pago tras 24h).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Stock;

use Akibara\Business\Modules\Stock\UnpaidOrdersSweeper;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_DateTime;
use WC_Order;

final class UnpaidOrdersSweeperTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Filtro woocommerce_cancel_unpaid_order fuerza cancelación de órdenes pendientes con más de 24 horas
	 */
	public function test_filter_cancels_pending_orders_older_than_24h(): void {
		$sweeper = new UnpaidOrdersSweeper();

		$old_order = new WC_Order();
		$old_order->akb_status = 'pending';
		$old_order->akb_date_created = new WC_DateTime( '@' . ( time() - ( 25 * HOUR_IN_SECONDS ) ) );

		// Pasarela retornó false previamente (ej. Mercado Pago abortando)
		$result = $sweeper->filter_cancel_unpaid_order( false, $old_order );
		self::assertTrue( $result, 'Debe forzar cancelación si tiene más de 24 horas' );

		$recent_order = new WC_Order();
		$recent_order->akb_status = 'pending';
		$recent_order->akb_date_created = new WC_DateTime( '@' . ( time() - ( 2 * HOUR_IN_SECONDS ) ) );

		$result_recent = $sweeper->filter_cancel_unpaid_order( false, $recent_order );
		self::assertFalse( $result_recent, 'No debe cancelar orden reciente si should_cancel es false' );
	}

	/**
	 * @testdox Sweep cancela órdenes encontradas en modo real y las respeta en dry-run
	 */
	public function test_sweep_cancels_orders_or_simulates_dry_run(): void {
		$sweeper = new UnpaidOrdersSweeper();

		$order = new WC_Order();
		$order->akb_id = 28885;
		$order->akb_status = 'pending';

		Functions\when( 'wc_get_orders' )->justReturn( array( $order ) );

		// 1. Dry run
		$dry_result = $sweeper->sweep( true, 24, 50 );
		self::assertSame( 1, $dry_result['cancelled_count'] );
		self::assertSame( array( 28885 ), $dry_result['order_ids'] );
		self::assertSame( 'pending', $order->akb_status, 'En dry-run debe permanecer pending' );
		self::assertEmpty( $order->akb_notes );

		// 2. Ejecución real
		$real_result = $sweeper->sweep( false, 24, 50 );
		self::assertSame( 1, $real_result['cancelled_count'] );
		self::assertSame( 'cancelled', $order->akb_status, 'En ejecución real debe pasar a cancelled' );
		self::assertCount( 1, $order->akb_notes );
	}

	/**
	 * @testdox Filtro woocommerce_cancel_unpaid_order preserva pedidos manuales no originados en checkout
	 */
	public function test_filter_cancel_unpaid_order_preserves_admin_orders(): void {
		$sweeper = new UnpaidOrdersSweeper();

		$admin_order                   = new class() extends WC_Order {
			public function get_created_via(): string {
				return 'admin';
			}
		};
		$admin_order->akb_status       = 'pending';
		$admin_order->akb_date_created = new \DateTimeImmutable( '-2 days' );

		$should_cancel = $sweeper->filter_cancel_unpaid_order( false, $admin_order );
		self::assertFalse( $should_cancel, 'No debe cancelar pedidos manuales de administración aunque tengan más de 24 horas' );
	}
}
