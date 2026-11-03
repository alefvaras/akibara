<?php
/**
 * Tests de las compuertas de finalización de preventa.
 *
 * D2 de la auditoría 2026-07-28: OrderPreventa era el módulo más complejo del
 * plugin (25 archivos) sin un solo test propio. Estas compuertas deciden si un
 * pedido con preventas puede darse por completado y si se le puede pedir una
 * reseña al cliente — completarlo antes de tiempo dispara el aviso de "ya
 * llegó" y el recordatorio de reseña sobre algo que aún no se despachó.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\CompletionPolicy;
use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\PreventaItems;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CompletionPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Los filtros del módulo son puntos de extensión: en los tests devuelven
		// la decisión del núcleo sin alterarla.
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Construye una orden doble con sus líneas.
	 *
	 * @param array<string, string>              $meta   Meta de la orden.
	 * @param array<int, array<string, mixed>>   $items  Líneas: was_preventa/qty/refunded.
	 * @param string                             $status Estado de la orden.
	 */
	private function order( array $meta, array $items = array(), string $status = 'processing' ): \WC_Order {
		$order = new \WC_Order();
		$order->akb_meta   = $meta;
		$order->akb_status = $status;

		$id = 1;
		foreach ( $items as $item_spec ) {
			$item                 = new \WC_Order_Item_Product();
			$item->akb_id         = $id;
			$item->akb_quantity   = (int) ( $item_spec['qty'] ?? 1 );
			$item->akb_meta       = array(
				MetaKeys::ITEM_WAS_PREVENTA => ( $item_spec['preventa'] ?? true ) ? 'yes' : 'no',
			);
			$order->akb_items[]   = $item;
			$order->akb_refunded[ $id ] = (int) ( $item_spec['refunded'] ?? 0 );
			++$id;
		}

		return $order;
	}

	/**
	 * @testdox Should not allow completing an order with outstanding preorders.
	 */
	public function test_order_with_outstanding_preorder_cannot_complete(): void {
		$order = $this->order(
			array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'no' ),
			array( array( 'qty' => 1 ) )
		);

		self::assertFalse( CompletionPolicy::can_complete( $order ) );
	}

	/**
	 * @testdox Should allow completing once the order is flagged ready to ship.
	 */
	public function test_ready_to_ship_order_can_complete(): void {
		$order = $this->order(
			array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'yes' ),
			array( array( 'qty' => 1 ) )
		);

		self::assertTrue( CompletionPolicy::can_complete( $order ) );
	}

	/**
	 * @testdox Should not gate an order that never had preorders.
	 */
	public function test_order_without_preventa_is_never_gated(): void {
		$order = $this->order( array(), array( array( 'qty' => 1, 'preventa' => false ) ) );

		self::assertTrue( CompletionPolicy::can_complete( $order ) );
		self::assertTrue( CompletionPolicy::can_request_review( $order ) );
	}

	/**
	 * @testdox Should allow completing when every preorder line was refunded.
	 */
	public function test_fully_refunded_preorder_line_stops_blocking(): void {
		$order = $this->order(
			array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'no' ),
			array( array( 'qty' => 2, 'refunded' => 2 ) )
		);

		self::assertFalse( PreventaItems::has_outstanding_items( $order ) );
		self::assertTrue( CompletionPolicy::can_complete( $order ) );
	}

	/**
	 * @testdox Should keep blocking on a partially refunded preorder line.
	 */
	public function test_partially_refunded_line_still_blocks(): void {
		$order = $this->order(
			array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'no' ),
			array( array( 'qty' => 3, 'refunded' => 1 ) )
		);

		self::assertTrue( PreventaItems::has_outstanding_items( $order ) );
		self::assertFalse( CompletionPolicy::can_complete( $order ) );
	}

	/**
	 * @testdox Should keep blocking a mixed order while one preorder is pending.
	 */
	public function test_mixed_order_blocks_until_the_preorder_arrives(): void {
		$order = $this->order(
			array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'no' ),
			array(
				array( 'qty' => 1, 'preventa' => false ), // stock normal
				array( 'qty' => 1, 'preventa' => true ),  // preventa pendiente
			)
		);

		self::assertFalse( CompletionPolicy::can_complete( $order ) );
	}

	/**
	 * @testdox Should not request a review while the preorder is still pending.
	 */
	public function test_review_is_blocked_before_the_preorder_arrives(): void {
		$order = $this->order(
			array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'no' ),
			array( array( 'qty' => 1 ) )
		);

		self::assertFalse( CompletionPolicy::can_request_review( $order ) );
	}

	/**
	 * @testdox Should not request a review while a ready order is not completed yet.
	 */
	public function test_review_needs_the_completed_status_not_just_ready(): void {
		$order = $this->order(
			array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'yes' ),
			array( array( 'qty' => 1 ) ),
			'processing'
		);

		self::assertTrue( CompletionPolicy::can_complete( $order ) );
		self::assertFalse( CompletionPolicy::can_request_review( $order ) );
	}

	/**
	 * @testdox Should request a review once the preorder shipped and completed.
	 */
	public function test_review_is_allowed_after_a_valid_completion(): void {
		$order = $this->order(
			array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'yes' ),
			array( array( 'qty' => 1 ) ),
			'completed'
		);

		self::assertTrue( CompletionPolicy::can_request_review( $order ) );
	}

	/**
	 * @testdox Should ignore a zero-quantity line.
	 */
	public function test_zero_quantity_line_is_not_outstanding(): void {
		$order = $this->order(
			array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'no' ),
			array( array( 'qty' => 0 ) )
		);

		self::assertFalse( PreventaItems::has_outstanding_items( $order ) );
	}
}
