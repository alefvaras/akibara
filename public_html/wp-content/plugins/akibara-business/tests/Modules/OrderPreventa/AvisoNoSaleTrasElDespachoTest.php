<?php
/**
 * El correo «tu pedido ya llegó a Akibara» no puede salir cuando el pedido YA
 * salió de la tienda.
 *
 * Hallazgo S-9d03b9e1: dos superficies con dos criterios sobre el mismo hecho.
 * El CARTEL de la ficha y de Mi cuenta calla en cuanto el pedido está
 * «entregado al transportista» (`CustomerVisibility::order_left()`), porque ahí
 * ya salió. El CORREO, en cambio, se permitía en los mismos estados «activos»
 * de preventa, y Blue Express añade a esa lista su estado operativo
 * (`shipping-progress`): bastaba con que el aviso se reenganchara ahí para
 * mandarle al cliente «Tu pedido ya llegó a Akibara · Despachamos en 1-3 días
 * hábiles» cuando el paquete ya iba en camino.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\FulfillmentCapabilities;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AvisoNoSaleTrasElDespachoTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ?? '' )
		);

		// El mundo de producción: Blue Express declara su estado operativo como
		// activo Y como entrega al transportista (Integrations\Bluex\OrderPreventaAdapter).
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $valor ) {
				if ( 'akb_order_preventa_active_order_statuses' === $hook ) {
					return array( 'processing', 'shipping-progress', 'completed' );
				}
				if ( 'akb_order_preventa_carrier_handoff_statuses' === $hook ) {
					return array( 'shipping-progress' );
				}

				return $valor;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function pedido( string $estado ): \WC_Order {
		$order             = new \WC_Order();
		$order->akb_status = $estado;

		return $order;
	}

	/** @testdox Con el pedido entregado al transportista, el aviso «ya llegó» no sale. */
	public function test_a_handed_over_order_cannot_send_the_ready_email(): void {
		self::assertFalse( FulfillmentCapabilities::can_send_ready_email( $this->pedido( 'shipping-progress' ) ) );
	}

	/** @testdox Ni con el pedido completado: esa parte ya la cuenta el estado. */
	public function test_a_completed_order_cannot_send_the_ready_email(): void {
		self::assertFalse( FulfillmentCapabilities::can_send_ready_email( $this->pedido( 'completed' ) ) );
	}

	/** @testdox En «procesando», que es cuando el tomo llega a bodega, sí sale. */
	public function test_a_processing_order_still_sends_the_ready_email(): void {
		self::assertTrue( FulfillmentCapabilities::can_send_ready_email( $this->pedido( 'processing' ) ) );
	}

	/** @testdox La lista del aviso es la de estados activos menos los de salida. */
	public function test_the_ready_email_statuses_drop_the_ones_that_left(): void {
		$estados = FulfillmentCapabilities::ready_email_order_statuses( $this->pedido( 'processing' ) );

		self::assertSame( array( 'processing' ), $estados );
	}
}
