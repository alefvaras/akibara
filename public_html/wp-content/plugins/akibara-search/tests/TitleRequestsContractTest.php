<?php
/**
 * Contrato de TitleRequests y modelo local de solicitudes/encargos.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\StockLabels;
use Akibara\Search\TitleRequests;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Akibara\Search\TitleRequests
 * @covers \Akibara\Search\StockLabels
 */
final class TitleRequestsContractTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Los estados comerciales separan preventa (pre) y a pedido (order) */
	public function test_stock_labels_separate_preventa_and_a_pedido_tones(): void {
		$pedido = StockLabels::availability( 'onbackorder', 10 );
		self::assertSame( 'a_pedido', $pedido['value'] );
		self::assertSame( 'order', $pedido['tone'] );

		$agotado = StockLabels::availability( 'outofstock', 10 );
		self::assertSame( 'outofstock', $agotado['value'] );
		self::assertSame( 'out', $agotado['tone'] );

		$instock = StockLabels::availability( 'instock', 10 );
		self::assertSame( 'instock', $instock['value'] );
		self::assertSame( 'ok', $instock['tone'] );
	}

	/** @testdox La definición SQL de la tabla incluye product_id, updated_at y los índices requeridos */
	public function test_install_table_schema_contract(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/TitleRequests.php' );

		self::assertStringContainsString( 'product_id BIGINT(20) UNSIGNED NULL DEFAULT NULL', $source );
		self::assertStringContainsString( 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $source );
		self::assertStringContainsString( 'KEY idx_product_id (product_id)', $source );
		self::assertStringContainsString( 'KEY idx_rl_ip (ip_hash, created_at)', $source );
	}

	/** @testdox schedule_async_notification usa as_enqueue_async_action si está disponible */
	public function test_schedule_async_notification_dispatches_action_scheduler(): void {
		$enqueued = array();

		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( string $hook, array $args = array(), string $group = '' ) use ( &$enqueued ): int {
				$enqueued[] = array(
					'hook'  => $hook,
					'args'  => $args,
					'group' => $group,
				);
				return 42;
			}
		);

		TitleRequests::schedule_async_notification( 123 );

		self::assertCount( 1, $enqueued );
		self::assertSame( 'akb_search_send_request_email', $enqueued[0]['hook'] );
		self::assertSame( array( 'request_id' => 123 ), $enqueued[0]['args'] );
		self::assertSame( 'akibara-search', $enqueued[0]['group'] );
	}
}
