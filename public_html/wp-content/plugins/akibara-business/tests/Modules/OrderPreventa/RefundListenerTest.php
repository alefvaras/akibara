<?php
/**
 * Tests del recálculo de preventa tras un reembolso (D2).
 *
 * El listener existe para que una línea de preventa totalmente reembolsada
 * deje de contar como pendiente en el acto, sin esperar al cron diario. Sus
 * cuatro guardas deciden cuándo NO hay que recalcular; equivocarse ahí o bien
 * deja el pedido trabado (nunca pasa a "listo") o bien recalcula pedidos que
 * no corresponden.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\SummaryRecalculator;

use Akibara\Business\Tests\Support\NullEtaProvider;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\ReadyToShipCron;
use Akibara\Business\Modules\OrderPreventa\RefundListener;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class RefundListenerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);
		// FulfillmentCapabilities normaliza los estados con sanitize_key().
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) ?? '' )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Cron espía: registra si se le pidió recalcular la orden.
	 */
	private function spy_cron(): ReadyToShipCron {
		return new class( new SummaryRecalculator( new NullEtaProvider() ) ) extends ReadyToShipCron {
			public int $calls = 0;

			public function process_order( \WC_Order $order ): void {
				unset( $order );
				++$this->calls;
			}
		};
	}

	/**
	 * @param array<string, string> $meta   Meta de la orden.
	 * @param string                $status Estado del pedido.
	 */
	private function order( array $meta, string $status = 'processing' ): \WC_Order {
		$order             = new \WC_Order( $meta );
		$order->akb_status = $status;

		return $order;
	}

	/**
	 * @testdox Should recalculate an active preorder right after a refund.
	 */
	public function test_active_preorder_is_recalculated(): void {
		$cron  = $this->spy_cron();
		$order = $this->order(
			array(
				MetaKeys::ORDER_HAS_PREVENTA  => 'yes',
				MetaKeys::ORDER_READY_TO_SHIP => 'no',
			)
		);

		Functions\when( 'wc_get_order' )->justReturn( $order );

		( new RefundListener( $cron ) )->on_order_refunded( 100, 200 );

		self::assertSame( 1, $cron->calls );
	}

	/**
	 * @testdox Should ignore a refund on an order without preorders.
	 */
	public function test_order_without_preventa_is_ignored(): void {
		$cron  = $this->spy_cron();
		$order = $this->order( array() );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		( new RefundListener( $cron ) )->on_order_refunded( 100 );

		self::assertSame( 0, $cron->calls );
	}

	/**
	 * @testdox Should ignore a refund on an order already ready to ship.
	 */
	public function test_ready_to_ship_order_is_ignored(): void {
		$cron  = $this->spy_cron();
		$order = $this->order(
			array(
				MetaKeys::ORDER_HAS_PREVENTA  => 'yes',
				MetaKeys::ORDER_READY_TO_SHIP => 'yes',
			)
		);

		Functions\when( 'wc_get_order' )->justReturn( $order );

		( new RefundListener( $cron ) )->on_order_refunded( 100 );

		self::assertSame( 0, $cron->calls );
	}

	/**
	 * @testdox Should ignore a refund on an order outside the active statuses.
	 */
	public function test_inactive_order_status_is_ignored(): void {
		$cron  = $this->spy_cron();
		$order = $this->order(
			array(
				MetaKeys::ORDER_HAS_PREVENTA  => 'yes',
				MetaKeys::ORDER_READY_TO_SHIP => 'no',
			),
			'cancelled'
		);

		Functions\when( 'wc_get_order' )->justReturn( $order );

		( new RefundListener( $cron ) )->on_order_refunded( 100 );

		self::assertSame( 0, $cron->calls );
	}

	/**
	 * @testdox Should not blow up when the order id does not resolve.
	 */
	public function test_missing_order_is_handled(): void {
		$cron = $this->spy_cron();

		Functions\when( 'wc_get_order' )->justReturn( false );

		( new RefundListener( $cron ) )->on_order_refunded( 999 );

		self::assertSame( 0, $cron->calls );
	}
}
