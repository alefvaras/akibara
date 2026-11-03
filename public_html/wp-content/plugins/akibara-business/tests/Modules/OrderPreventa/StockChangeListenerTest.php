<?php
/**
 * Tests del disparador de recálculo cuando un producto vuelve a stock (D2).
 *
 * Es la pieza que convierte "llegó la mercadería" en "el pedido queda listo
 * para despachar". Sus tres decisiones importan: filtrar el estado correcto,
 * no encolar dos veces el mismo producto en un restock masivo, y degradar a
 * ejecución sincrónica si Action Scheduler no está.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\SummaryRecalculator;

use Akibara\Business\Tests\Support\NullEtaProvider;

use Akibara\Business\Modules\OrderPreventa\ReadyToShipCron;
use Akibara\Business\Modules\OrderPreventa\StockChangeListener;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class StockChangeListenerTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	public static array $scheduled = array();

	/** @var bool Respuesta simulada de as_has_scheduled_action(). */
	public static bool $already_scheduled = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		self::$scheduled         = array();
		self::$already_scheduled = false;

		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( $timestamp, $hook, $args = array(), $group = '' ) {
				StockChangeListenerTest::$scheduled[] = array(
					'timestamp' => $timestamp,
					'hook'      => $hook,
					'args'      => $args,
					'group'     => $group,
				);
				return 1;
			}
		);
		Functions\when( 'as_has_scheduled_action' )->alias(
			static fn() => StockChangeListenerTest::$already_scheduled
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Cron espía: cuenta las ejecuciones sincrónicas del fallback.
	 */
	private function spy_cron(): ReadyToShipCron {
		return new class( new SummaryRecalculator( new NullEtaProvider() ) ) extends ReadyToShipCron {
			public int $processed = 0;

			public function run(): void {
				++$this->processed;
			}
		};
	}

	/**
	 * @testdox Should queue a recompute when a product returns to stock.
	 */
	public function test_restock_queues_a_debounced_job(): void {
		( new StockChangeListener( $this->spy_cron() ) )->on_stock_status_change( 13629, 'instock' );

		self::assertCount( 1, self::$scheduled );
		self::assertSame( StockChangeListener::ASYNC_HOOK, self::$scheduled[0]['hook'] );
		self::assertSame( array( 'product_id' => 13629 ), self::$scheduled[0]['args'] );
	}

	/**
	 * @testdox Should ignore stock transitions other than back-in-stock.
	 *
	 * @dataProvider non_instock_statuses
	 *
	 * @param string $status Estado de stock entrante.
	 */
	public function test_other_stock_statuses_are_ignored( string $status ): void {
		( new StockChangeListener( $this->spy_cron() ) )->on_stock_status_change( 13629, $status );

		self::assertSame( array(), self::$scheduled );
	}

	/**
	 * @return array<int, array<int, string>>
	 */
	public static function non_instock_statuses(): array {
		return array( array( 'outofstock' ), array( 'onbackorder' ), array( '' ) );
	}

	/**
	 * @testdox Should not queue the same product twice during a bulk restock.
	 */
	public function test_duplicate_product_is_not_queued_twice(): void {
		self::$already_scheduled = true;

		( new StockChangeListener( $this->spy_cron() ) )->on_stock_status_change( 13629, 'instock' );

		self::assertSame( array(), self::$scheduled );
	}

	/**
	 * @testdox Should reject a non-positive product id.
	 */
	public function test_invalid_product_id_is_ignored(): void {
		$listener = new StockChangeListener( $this->spy_cron() );
		$listener->on_stock_status_change( 0, 'instock' );
		$listener->on_stock_status_change( -5, 'instock' );

		self::assertSame( array(), self::$scheduled );
	}

	/**
	 * @testdox Should debounce the job instead of firing it immediately.
	 */
	public function test_job_is_debounced_into_the_future(): void {
		( new StockChangeListener( $this->spy_cron() ) )->on_stock_status_change( 13629, 'instock' );

		// El debounce agrupa el burst de un restock masivo; sin él, cada guardado
		// de producto dispararía su propio recálculo completo.
		self::assertGreaterThan( time(), self::$scheduled[0]['timestamp'] );
	}

	/**
	 * @testdox Should carry the dedicated queue group.
	 */
	public function test_job_uses_its_own_queue_group(): void {
		( new StockChangeListener( $this->spy_cron() ) )->on_stock_status_change( 13629, 'instock' );

		self::assertSame( 'akibara-order-preventa', self::$scheduled[0]['group'] );
	}
}
