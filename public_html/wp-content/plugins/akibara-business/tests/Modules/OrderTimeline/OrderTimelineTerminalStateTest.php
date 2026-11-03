<?php
/**
 * S-a9b9bd6a: el banner de pago fallido usaba voseo argentino («escribinos»)
 * en una tienda que le habla al cliente en español de Chile en el resto del
 * sitio («escríbenos», «avísame», «te avisamos»). Aparece justo en el momento
 * de más fricción: un cliente que ya transfirió y ve el pago sin completar.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderTimeline;

use Akibara\Business\Modules\OrderTimeline\OrderTimeline;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class OrderTimelineTerminalStateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox No debe usar voseo argentino en el cartel de pago fallido (S-a9b9bd6a).
	 */
	public function test_failed_order_banner_speaks_chilean_spanish(): void {
		$order              = new \WC_Order();
		$order->akb_status  = 'failed';

		ob_start();
		( new OrderTimeline() )->render( $order );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'escríbenos', $html );
		self::assertStringNotContainsString( 'escribinos', $html );
	}
}
