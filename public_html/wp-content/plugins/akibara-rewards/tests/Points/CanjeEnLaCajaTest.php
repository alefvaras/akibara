<?php
/**
 * El canje sin saldo se frena en la caja, y lo que falla se reintenta solo.
 *
 * Hallado el 2026-09-13 al escribir el manual del dueño:
 *
 *   - Dos compras simultáneas con el mismo saldo: la segunda cobraba lo que
 *     quedaba y CONSERVABA el descuento entero. La diferencia la perdía la
 *     tienda, con una nota que nadie leía.
 *   - Con el candado ocupado, la devolución de los puntos de un pedido anulado
 *     esperaba «el próximo cambio de estado». Un pedido anulado no vuelve a
 *     cambiar: el cliente perdía sus puntos.
 *
 * En la caja de bloques la captura corre DOS veces por diseño de WooCommerce:
 * al pasar el borrador a «pendiente» (que dispara el cambio de estado) y en
 * `woocommerce_store_api_checkout_order_processed`. Sólo la segunda puede
 * detener la compra, así que la primera se la deja.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

require_once dirname( __DIR__ ) . '/Support/route-exception-stub.php';

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.4.1
 */
final class CanjeEnLaCajaTest extends RewardsTestCase {

	private Redemption $redemption;

	/** @var array<int,\WC_Order> */
	private array $orders = array();

	/** @var array<int,array{hook:string,args:array<int,mixed>,ts:int}> */
	private array $scheduled = array();

	protected function setUp(): void {
		parent::setUp();

		$this->redemption = new Redemption();
		$this->install_fake_wc();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wc_price' )->alias(
			static fn( $amount ) => '$' . number_format( (float) $amount, 0, ',', '.' )
		);
		Functions\when( 'wc_get_order' )->alias( fn( $id ) => $this->orders[ (int) $id ] ?? false );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $ts, string $hook, array $args = array() ): int {
				$this->scheduled[] = array(
					'hook' => $hook,
					'args' => $args,
					'ts'   => $ts,
				);
				return count( $this->scheduled );
			}
		);
		Functions\when( 'as_next_scheduled_action' )->alias(
			function ( string $hook, ?array $args = null ) {
				foreach ( $this->scheduled as $job ) {
					if ( $job['hook'] === $hook && ( null === $args || $job['args'] === $args ) ) {
						return $job['ts'];
					}
				}
				return false;
			}
		);
	}

	/**
	 * Pedido con el fee de canje horneado: $1.000 brutos.
	 */
	private function order_with_fee(): \WC_Order {
		$order                 = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->items['fee'][] = new \WC_Order_Item( Redemption::FEE_NAME, -840.34, -159.66 );
		$order->status         = 'pending';
		$this->orders[100]     = $order;

		return $order;
	}

	/** @testdox Si el saldo ya no alcanza, la caja detiene la compra en vez de regalar el descuento. */
	public function test_la_caja_detiene_la_compra_si_el_saldo_ya_no_alcanza(): void {
		Ledger::credit( 7, 400, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();

		try {
			$this->redemption->capture_from_order( $order );
			$this->fail( 'La compra tenía que detenerse.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'saldo', $e->getMessage(), 'El cliente tiene que entender por qué.' );
		}

		$this->assertSame( 400, Ledger::balance( 7 ), 'No se cobra nada: el pedido no sigue.' );
		$this->assertArrayNotHasKey( Redemption::META_REDEEMED_POINTS, $order->meta );
		$this->assertSame( 1000, Redemption::intent_points(), 'La intención queda: el carrito recalcula con el saldo real.' );
		$this->assertSame( array(), $this->wpdb->held_locks, 'El candado se suelta aunque la compra se detenga.' );
	}

	/**
	 * @testdox El pedido detenido vuelve a borrador: no queda un pedido pendiente con el descuento entero.
	 *
	 * Revisión independiente 2026-09-13: WooCommerce pasa el borrador a
	 * «pendiente» antes de avisar y lo guarda así aunque la caja se detenga. Ese
	 * pedido se podía pagar después desde Mi cuenta, con el descuento gratis.
	 */
	public function test_el_pedido_detenido_vuelve_a_borrador(): void {
		Ledger::credit( 7, 400, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();

		$order->status = 'pending';
		$this->redemption->on_status_changed( 100, 'checkout-draft', 'pending', $order );

		try {
			$this->redemption->capture_from_order( $order );
			$this->fail( 'La compra tenía que detenerse.' );
		} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
			$this->assertSame( 'akb_rewards_balance_changed', $e->getErrorCode() );
			$this->assertSame( 409, $e->getCode() );
		}

		$this->assertSame( 'checkout-draft', $order->status, 'WooCommerce reutiliza el borrador en el siguiente intento, con el descuento recalculado.' );
		$this->assertSame( 400, Ledger::balance( 7 ) );
	}

	/** @testdox Pagar un pedido ya existente cuyo descuento el saldo no cubre se detiene y lo explica. */
	public function test_pagar_por_la_store_api_un_pedido_existente_sin_saldo_se_detiene(): void {
		Ledger::credit( 7, 400, 'order', 'order', 50 );
		$order = $this->order_with_fee();

		try {
			$this->redemption->capture_from_order( $order );
			$this->fail( 'El pago tenía que detenerse.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'ya no cubre el descuento de este pedido', $e->getMessage() );
		}

		$this->assertSame( 'pending', $order->status, 'Un pedido existente no se toca: sólo se detiene el pago.' );
		$this->assertStringContainsString( 'se detuvo un pago', implode( ' ', $order->notes ) );
		$this->assertSame( 400, Ledger::balance( 7 ) );
	}

	/** @testdox La página «pagar pedido» no cobra un descuento que el saldo no cubre. */
	public function test_la_pagina_pagar_pedido_no_regala_el_descuento(): void {
		$avisos = array();
		Functions\when( 'wc_add_notice' )->alias(
			static function ( string $mensaje, string $tipo = 'success' ) use ( &$avisos ): void {
				$avisos[] = array( $tipo, $mensaje );
			}
		);
		Ledger::credit( 7, 400, 'order', 'order', 50 );
		$order = $this->order_with_fee();

		$this->redemption->guard_order_pay( $order );

		$this->assertSame( 'pending', $order->status, 'Anular aquí haría que WooCommerce lo diera por pagado (payment_complete).' );
		$this->assertSame( 'error', $avisos[0][0] ?? '', 'Con un aviso de error, WooCommerce no procesa el pago.' );
		$this->assertStringContainsString( 'no se puede pagar', $avisos[0][1] ?? '' );
		$this->assertSame( 400, Ledger::balance( 7 ) );
	}

	/** @testdox La página «pagar pedido» captura el canje si el saldo alcanza. */
	public function test_la_pagina_pagar_pedido_captura_si_hay_saldo(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		$order = $this->order_with_fee();

		$this->redemption->guard_order_pay( $order );

		$this->assertSame( 'pending', $order->status );
		$this->assertSame( 1000, $order->saved_meta[ Redemption::META_REDEEMED_POINTS ] );
		$this->assertSame( 500, Ledger::balance( 7 ) );
	}

	/** @testdox Un borrador que un administrador pasa directo a Procesando sí se captura. */
	public function test_un_borrador_que_pasa_a_procesando_se_captura(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		$order = $this->order_with_fee();

		$order->status = 'processing';
		$this->redemption->on_status_changed( 100, 'checkout-draft', 'processing', $order );

		$this->assertSame( 500, Ledger::balance( 7 ) );
	}

	/** @testdox Una corrida de reintento gasta un solo cupo y, si todo sale bien, lo devuelve. */
	public function test_una_corrida_de_reintento_gasta_un_solo_cupo(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		$order                                        = $this->order_with_fee();
		$order->status                                = 'processing';
		$order->meta[ Redemption::META_REVIVAL_DEBT ] = 200;
		$order->refunds[]                             = new \WC_Order_Refund( array(), 100.0 );

		// Action Scheduler cuenta la acción en curso como agendada.
		Functions\when( 'as_next_scheduled_action' )->justReturn( time() );
		$this->wpdb->lock_result = '0';
		$this->redemption->run_retry( 100 );

		$this->assertCount( 1, $this->scheduled, 'Captura, re-cobro y devolución fallan en la misma corrida: un solo reintento.' );
		$this->assertSame( 1, (int) $order->meta[ Redemption::META_RETRIES ] );

		$this->wpdb->lock_result = '1';
		$this->redemption->run_retry( 100 );

		$this->assertSame( 0, (int) $order->meta[ Redemption::META_RETRIES ], 'La corrida que sale bien devuelve el cupo.' );
	}

	/**
	 * Carrito de $10.000 + $1.900 de IVA.
	 */
	private function cart(): \WC_Cart {
		$cart           = new \WC_Cart();
		$cart->contents = array(
			array(
				'line_total' => 10000.0,
				'line_tax'   => 1900.0,
			),
		);

		return $cart;
	}

	/**
	 * Lo que la caja de bloques responde al cliente, pasado por el gancho REST.
	 *
	 * @param array<string,mixed> $data Cuerpo de la respuesta.
	 */
	private function respuesta_de_caja( array $data, string $metodo = 'POST' ): void {
		$this->redemption->watch_checkout_response(
			new \WP_REST_Response( $data ),
			null,
			new \WP_REST_Request( $metodo, '/wc/store/v1/checkout' )
		);
	}

	/**
	 * Primer intento: se captura el canje y la pasarela rechaza el pago sin
	 * tocar el estado (MercadoPago con tarjeta devuelve «fail» y el pedido sigue
	 * pendiente). WooCommerce deja el pedido anotado en la sesión.
	 */
	private function first_attempt_rejected( int $saldo ): \WC_Order {
		// Sin carrito mínimo: estas pruebas miden el saldo, no el umbral.
		Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => 'akb_rewards_redeem_min_cart' === $option ? 0 : $default_value
		);
		Ledger::credit( 7, $saldo, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order               = $this->order_with_fee();
		$order->date_created = new \WC_DateTime( '@' . time() ); // Nace después de fijar la intención.
		WC()->session->set( 'store_api_draft_order', 100 );

		$order->status = 'pending';
		$this->redemption->on_status_changed( 100, 'checkout-draft', 'pending', $order );
		$this->redemption->capture_from_order( $order );
		// La Store API traduce el «fail» de la pasarela a payment_status failure.
		$this->respuesta_de_caja(
			array(
				'order_id'       => 100,
				'payment_result' => array( 'payment_status' => 'failure' ),
			)
		);

		return $order;
	}

	/**
	 * @testdox Tras un pago rechazado con todo el saldo canjeado, el reintento ve el mismo descuento y no cobra dos veces.
	 *
	 * Revisión final 2026-09-13: el hash del carrito incluye el total, así que
	 * WooCommerce sólo reutiliza el pedido si el descuento sale idéntico. Con el
	 * saldo ya cobrado por el primer intento, el descuento salía 0, WooCommerce
	 * creaba otro pedido a precio completo y los puntos quedaban retenidos en
	 * el pedido rechazado.
	 */
	public function test_el_reintento_tras_un_rechazo_ve_el_mismo_descuento(): void {
		$order = $this->first_attempt_rejected( 1000 );
		$this->assertSame( 0, Ledger::balance( 7 ) );

		$this->assertSame( 1000, Redemption::effective_points( $this->cart() ), 'Los puntos que retiene el pedido impago de esta compra siguen disponibles para ella.' );

		// Mismo descuento → mismo hash → WooCommerce reutiliza el pedido.
		$this->redemption->capture_from_order( $order );

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertSame( 1000, (int) $order->meta[ Redemption::META_REDEEMED_POINTS ], 'Una sola captura.' );
	}

	/** @testdox Con saldo de sobra, el reintento no cobra la diferencia otra vez. */
	public function test_el_reintento_con_saldo_de_sobra_no_cobra_de_mas(): void {
		$this->first_attempt_rejected( 1500 );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		$this->assertSame( 1000, Redemption::effective_points( $this->cart() ), 'Antes salía 500: pedido nuevo que cobraba 500 más por menos descuento.' );
	}

	/** @testdox Un canje ya capturado no se borra en los reintentos siguientes. */
	public function test_la_intencion_sobrevive_a_varios_reintentos(): void {
		$order = $this->first_attempt_rejected( 3000 );

		$this->redemption->capture_from_order( $order );
		$this->redemption->capture_from_order( $order );

		$this->assertSame( 1000, Redemption::intent_points(), 'Antes el segundo intento la borraba y el tercero salía sin descuento.' );

		( new Redemption() )->clear_intent();
		$this->assertSame( 0, Redemption::intent_points(), 'Vaciar el carrito la borra.' );
	}

	/**
	 * @testdox Si el cliente cambia el carrito, el pedido nuevo usa los puntos y el rechazado los suelta.
	 */
	public function test_un_pedido_nuevo_de_la_misma_compra_libera_el_anterior(): void {
		$rechazado = $this->first_attempt_rejected( 1000 );

		$nuevo                 = $this->make_order( 101, 7, 12000.0, 2280.0 );
		$nuevo->items['fee'][] = new \WC_Order_Item( Redemption::FEE_NAME, -840.34, -159.66 );
		$nuevo->status         = 'checkout-draft';
		$this->orders[101]     = $nuevo;

		// WooCommerce avisa del pedido nuevo ANTES de anotarlo en la sesión.
		$this->redemption->remember_superseded( $nuevo );
		WC()->session->set( 'store_api_draft_order', 101 );
		$nuevo->status = 'pending';
		$this->redemption->on_status_changed( 101, 'checkout-draft', 'pending', $nuevo );
		$this->redemption->capture_from_order( $nuevo );

		$this->assertSame( 1000, (int) $nuevo->meta[ Redemption::META_REDEEMED_POINTS ], 'El pedido nuevo cobra el canje.' );
		$this->assertSame( 0, Ledger::balance( 7 ), 'Con los mismos 1.000 puntos: no se gastan dos veces.' );
		$this->assertSame( 1000, (int) $rechazado->meta[ Redemption::META_RESTORED_POINTS ] );
		$this->assertSame( 1000, (int) $rechazado->meta[ Redemption::META_REVIVAL_DEBT ], 'Si el rechazado se pagara igual, se vuelven a cobrar.' );
		$this->assertStringContainsString( 'otra compra (pedido #101)', implode( ' ', $rechazado->notes ) );
	}

	/**
	 * Fee de canje con el bruto indicado, como lo reescribe WooCommerce al
	 * reutilizar el pedido.
	 */
	private function set_fee( \WC_Order $order, float $bruto ): void {
		$neto                  = round( $bruto / 1.19, 2 );
		$order->items['fee']   = array( new \WC_Order_Item( Redemption::FEE_NAME, -$neto, -( $bruto - $neto ) ) );
	}

	/**
	 * Segunda revisión 2026-09-13 (ALTA): WooCommerce reutiliza el pedido si el
	 * carrito y el TOTAL coinciden, no el descuento. Otro canje con otro
	 * despacho que lo compense reutilizaba el pedido con más descuento y la
	 * captura, al ver puntos ya cobrados, no hacía nada.
	 *
	 * @testdox Un pedido reutilizado con más descuento cobra la diferencia.
	 */
	public function test_un_pedido_reutilizado_con_mas_descuento_cobra_la_diferencia(): void {
		$order = $this->first_attempt_rejected( 3000 );
		$this->assertSame( 2000, Ledger::balance( 7 ) );

		$this->set_fee( $order, 3000.0 );
		$this->redemption->capture_from_order( $order );

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertSame( 3000, (int) $order->meta[ Redemption::META_REDEEMED_POINTS ] );
		$this->assertEqualsWithDelta( 3000.0, (float) $order->meta[ Redemption::META_REDEEMED_VALUE ], 0.01 );
		$this->assertStringContainsString( 'cambió de 1000 a 3000 puntos', implode( ' ', $order->notes ) );
	}

	/** @testdox Si el saldo no cubre el descuento mayor, la compra se detiene sin cobrar la diferencia. */
	public function test_un_pedido_reutilizado_sin_saldo_para_la_diferencia_se_detiene(): void {
		$order = $this->first_attempt_rejected( 1500 );

		$this->set_fee( $order, 3000.0 );
		try {
			$this->redemption->capture_from_order( $order );
			$this->fail( 'Sin saldo para la diferencia, la compra tenía que detenerse.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'saldo', $e->getMessage() );
		}

		$this->assertSame( 500, Ledger::balance( 7 ) );
		$this->assertSame( 1000, (int) $order->meta[ Redemption::META_REDEEMED_POINTS ] );
	}

	/** @testdox Un pedido reutilizado con menos descuento devuelve la diferencia. */
	public function test_un_pedido_reutilizado_con_menos_descuento_devuelve_la_diferencia(): void {
		$order = $this->first_attempt_rejected( 1500 );

		$this->set_fee( $order, 400.0 );
		$this->redemption->capture_from_order( $order );

		$this->assertSame( 1100, Ledger::balance( 7 ) );
		$this->assertSame( 400, (int) $order->meta[ Redemption::META_REDEEMED_POINTS ] );
		$this->assertEqualsWithDelta( 400.0, (float) $order->meta[ Redemption::META_REDEEMED_VALUE ], 0.01 );
	}

	/**
	 * Pedido nuevo de la misma compra, con el fee indicado.
	 */
	private function nuevo_pedido( float $bruto ): \WC_Order {
		$nuevo         = $this->make_order( 101, 7, 12000.0, 2280.0 );
		$nuevo->status = 'checkout-draft';
		$this->set_fee( $nuevo, $bruto );
		$this->orders[101] = $nuevo;

		return $nuevo;
	}

	/**
	 * Segunda revisión (MEDIA): el vínculo con el pedido reemplazado vivía en
	 * memoria. Si la caja fallaba después de crear el pedido nuevo, el
	 * siguiente intento reutilizaba ese borrador sin avisar que se creó.
	 *
	 * @testdox Si la caja falla tras crear el pedido nuevo, el siguiente intento igual cuenta y libera los puntos del rechazado.
	 */
	public function test_el_vinculo_con_el_pedido_reemplazado_sobrevive_a_una_caja_fallida(): void {
		$rechazado = $this->first_attempt_rejected( 2000 );
		$this->assertSame( 1000, Ledger::balance( 7 ) );

		$nuevo = $this->nuevo_pedido( 2000.0 );
		$this->redemption->remember_superseded( $nuevo );
		WC()->session->set( 'store_api_draft_order', 101 );
		// La caja falla aquí (despacho, stock): no hay captura.

		$this->assertSame( 2000, Redemption::available_points( 7 ), 'El borrador reutilizado sigue viendo los puntos del rechazado.' );

		$nuevo->status = 'pending';
		$this->redemption->capture_from_order( $nuevo );

		$this->assertSame( 2000, (int) $nuevo->meta[ Redemption::META_REDEEMED_POINTS ] );
		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertSame( 1000, (int) $rechazado->meta[ Redemption::META_RESTORED_POINTS ] );
	}

	/**
	 * Tercera revisión 2026-09-13: liberar sólo «lo que falta» se pensó para un
	 * pedido que podía seguir pagándose en la pasarela. Ese pedido ya no se
	 * libera nunca (sólo los rechazados), y a medias dejaba puntos invisibles
	 * hasta que WooCommerce anulaba el rechazado.
	 *
	 * @testdox Del pedido rechazado se libera todo: si después se paga, «pagar pedido» vuelve a cobrarlo.
	 */
	public function test_del_rechazado_se_libera_todo(): void {
		$rechazado = $this->first_attempt_rejected( 1300 );
		$this->assertSame( 300, Ledger::balance( 7 ) );

		$nuevo = $this->nuevo_pedido( 500.0 );
		$this->redemption->remember_superseded( $nuevo );
		WC()->session->set( 'store_api_draft_order', 101 );
		$nuevo->status = 'pending';
		$this->redemption->capture_from_order( $nuevo );

		$this->assertSame( 500, (int) $nuevo->meta[ Redemption::META_REDEEMED_POINTS ] );
		$this->assertSame( 800, Ledger::balance( 7 ) );
		$this->assertSame( 1000, (int) $rechazado->meta[ Redemption::META_RESTORED_POINTS ] );
		$this->assertSame( 1000, (int) $rechazado->meta[ Redemption::META_REVIVAL_DEBT ] );

		// El cliente intenta pagar igual el rechazado desde Mi cuenta: la deuda
		// completa se cobra antes de ir a la pasarela, y con 800 no alcanza.
		$avisos = array();
		Functions\when( 'wc_add_notice' )->alias(
			static function ( string $mensaje, string $tipo = 'success' ) use ( &$avisos ): void {
				$avisos[] = $tipo;
			}
		);
		$this->redemption->guard_order_pay( $rechazado );
		$this->assertSame( array( 'error' ), $avisos, 'Sin saldo para la deuda, WooCommerce no cobra.' );
		$this->assertSame( 800, Ledger::balance( 7 ) );
		$this->assertSame( 1000, (int) $rechazado->meta[ Redemption::META_REVIVAL_DEBT ] );
	}

	/** @testdox Una intención fijada después del pedido pagado es para la compra siguiente. */
	public function test_una_intencion_nueva_tras_el_pedido_pagado_se_conserva(): void {
		$order         = $this->first_attempt_rejected( 1500 );
		$order->status = 'processing';
		// Fijada después de la caja: otro `set_at` que el horneado en el pedido.
		WC()->session->set( Redemption::SESSION_KEY, array( 'points' => 500, 'set_at' => time() + 30 ) );

		$this->redemption->apply_fee( $this->cart() );

		$this->assertSame( 500, Redemption::intent_points() );
	}

	/**
	 * Revisión independiente 2026-09-13 (BAJA): tras un rechazo el cliente
	 * vuelve a canjear —la intención queda posterior a la creación del
	 * pedido—, WooCommerce reutiliza ESE pedido y se paga. Comparada con la
	 * creación, la intención sobrevivía y la compra siguiente heredaba el
	 * descuento.
	 *
	 * @testdox La intención que se volvió a fijar y se horneó en el pedido reutilizado se da por gastada al pagarlo.
	 */
	public function test_la_intencion_reusada_en_el_mismo_pedido_se_gasta_al_pagarlo(): void {
		$order = $this->first_attempt_rejected( 3000 );
		WC()->session->set( Redemption::SESSION_KEY, array( 'points' => 1000, 'set_at' => time() + 30 ) );

		$this->redemption->capture_from_order( $order ); // Reintento sobre el mismo pedido.
		$order->status = 'processing';
		$this->redemption->apply_fee( $this->cart() );

		$this->assertSame( 0, Redemption::intent_points() );
	}

	/**
	 * Segunda revisión (BAJA): quien cambia el estado pudo cargar el pedido
	 * antes de que otra caja le liberara los puntos.
	 *
	 * @testdox El paso a pagado relee la deuda aunque la copia en memoria no la muestre.
	 */
	public function test_el_paso_a_pagado_relee_la_deuda(): void {
		$order = $this->first_attempt_rejected( 1000 );
		$copia = clone $order;

		$order->meta[ Redemption::META_RESTORED_POINTS ] = 1000; // Otra caja lo liberó.
		$order->meta[ Redemption::META_REVIVAL_DEBT ]    = 1000;
		Ledger::credit( 7, 1000, 'redeem_refund', 'order', 100, '', false );

		$copia->status = 'processing';
		$order->status = 'processing';
		$this->redemption->on_status_changed( 100, 'pending', 'processing', $copia );

		$this->assertSame( 0, Ledger::balance( 7 ), 'Se re-cobra: el pedido se pagó con ese descuento.' );
		$this->assertSame( 0, (int) $order->meta[ Redemption::META_REVIVAL_DEBT ] );
	}

	/** @testdox Una devolución que llega tarde sobre un pedido que ya revivió no devuelve. */
	public function test_una_devolucion_tardia_sobre_un_pedido_vivo_no_devuelve(): void {
		$order = $this->first_attempt_rejected( 1000 );
		$copia = clone $order;

		$order->status = 'processing'; // El webhook lo pagó entretanto.
		$copia->status = 'cancelled';
		$this->redemption->on_status_changed( 100, 'pending', 'cancelled', $copia );

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertArrayNotHasKey( Redemption::META_RESTORED_POINTS, $order->meta );
	}

	/** @testdox Volver de fallido a «pendiente» no cobra recortado un canje sin capturar. */
	public function test_volver_a_pendiente_no_cobra_recortado(): void {
		Ledger::credit( 7, 300, 'order', 'order', 50 );
		$order         = $this->order_with_fee();
		$order->status = 'pending';

		$this->redemption->on_status_changed( 100, 'failed', 'pending', $order );

		$this->assertSame( 300, Ledger::balance( 7 ) );
		$this->assertArrayNotHasKey( Redemption::META_REDEEMED_POINTS, $order->meta );
	}

	/** @testdox El pedido anterior que ya no está pendiente no se toca. */
	public function test_un_pedido_anterior_ya_pagado_no_se_libera(): void {
		$anterior         = $this->first_attempt_rejected( 1500 );
		$anterior->status = 'processing';

		$nuevo                 = $this->make_order( 101, 7, 12000.0, 2280.0 );
		$nuevo->items['fee'][] = new \WC_Order_Item( Redemption::FEE_NAME, -84.03, -15.97 );
		$nuevo->status         = 'pending';
		$this->orders[101]     = $nuevo;

		$this->redemption->remember_superseded( $nuevo );
		$this->redemption->capture_from_order( $nuevo );

		$this->assertArrayNotHasKey( Redemption::META_RESTORED_POINTS, $anterior->meta, 'Un pedido pagado conserva su canje.' );
		$this->assertSame( 400, Ledger::balance( 7 ) );
	}

	/** @testdox Los puntos de un pedido pendiente de otro cliente no cuentan. */
	public function test_el_pedido_de_otro_cliente_no_suma_saldo(): void {
		$order              = $this->first_attempt_rejected( 1000 );
		$order->customer_id = 8;

		$this->assertSame( 0, Redemption::effective_points( $this->cart() ) );
	}

	/**
	 * @testdox Pagado el pedido de la compra, la intención se da por gastada aunque el cliente no vuelva a la tienda.
	 *
	 * Con una pasarela que redirige, quien paga y cierra la pestaña no pasa por
	 * «pedido recibido», y WooCommerce no vacía el carrito.
	 */
	public function test_pagado_el_pedido_la_intencion_se_da_por_gastada(): void {
		$order         = $this->first_attempt_rejected( 1500 );
		$order->status = 'processing';

		$cart = $this->cart();
		$this->redemption->apply_fee( $cart );

		$this->assertSame( 0, Redemption::intent_points() );
		$this->assertSame( array(), $cart->fees, 'La compra siguiente no hereda el descuento.' );
	}

	/** @testdox Un pedido rechazado y anulado no gasta la intención: el cliente la sigue queriendo. */
	public function test_un_pedido_anulado_no_gasta_la_intencion(): void {
		$order         = $this->first_attempt_rejected( 1500 );
		$order->status = 'cancelled';

		$this->redemption->apply_fee( $this->cart() );

		$this->assertSame( 1000, Redemption::intent_points() );
	}

	/**
	 * @testdox El reintento de un pedido fallido re-cobra en la caja: los mismos puntos no sirven para dos compras.
	 *
	 * Revisión final 2026-09-13: volver a «pendiente» sólo COMPROBABA el saldo.
	 * Mientras el cliente pagaba en la pasarela, podía gastar esos puntos en
	 * otra compra, y el pedido revivido se pagaba igual con el descuento.
	 */
	public function test_el_reintento_de_un_fallido_recobra_en_la_caja(): void {
		$order         = $this->first_attempt_rejected( 1500 );
		$order->status = 'failed';
		$this->redemption->on_status_changed( 100, 'pending', 'failed', $order );
		$this->assertSame( 1500, Ledger::balance( 7 ) );

		$order->status = 'pending';
		$this->redemption->on_status_changed( 100, 'failed', 'pending', $order );
		$this->redemption->capture_from_order( $order );

		$this->assertSame( 500, Ledger::balance( 7 ), 'La caja re-cobra los 1.000 completos antes de ir a la pasarela.' );
		$this->assertSame( 0, (int) $order->meta[ Redemption::META_REVIVAL_DEBT ] );
		$this->assertFalse( Ledger::debit( 7, 1000, 'redemption', 'order', 101, '', true ), 'Otra compra ya no puede gastarlos.' );

		$order->status = 'processing';
		$this->redemption->on_status_changed( 100, 'pending', 'processing', $order );
		$this->assertSame( 500, Ledger::balance( 7 ), 'Al pagarse no se cobra otra vez.' );
	}

	/** @testdox Un reintento de la cola no re-cobra la deuda de un pedido que sigue pendiente. */
	public function test_la_cola_no_recobra_un_pedido_pendiente(): void {
		Ledger::credit( 7, 300, 'order', 'order', 50 );
		$order                                          = $this->order_with_fee();
		$order->meta[ Redemption::META_REDEEMED_POINTS ] = 1000;
		$order->meta[ Redemption::META_RESTORED_POINTS ] = 1000;
		$order->meta[ Redemption::META_REVIVAL_DEBT ]    = 1000;

		$this->redemption->run_retry( 100 );

		$this->assertSame( 300, Ledger::balance( 7 ), 'Recortado a 300, la deuda quedaba saldada y la caja ya no frenaba el pago.' );
		$this->assertSame( 1000, (int) $order->meta[ Redemption::META_REVIVAL_DEBT ] );
	}

	/** @testdox Un pedido fallido que vuelve a «pendiente» no re-cobra hasta pagarse. */
	public function test_un_pedido_fallido_que_vuelve_a_pendiente_no_recobra_ni_se_paga_sin_saldo(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );

		$order->status = 'failed';
		$this->redemption->on_status_changed( 100, 'pending', 'failed', $order );
		$this->assertSame( 1500, Ledger::balance( 7 ), 'El fallo repone el canje.' );

		Ledger::debit( 7, 1200, 'redemption', 'order', 999, '', true ); // Lo gasta en otra compra.

		$order->status = 'pending';
		$this->redemption->on_status_changed( 100, 'failed', 'pending', $order );
		$this->assertSame( 300, Ledger::balance( 7 ), 'Volver a pendiente no cobra lo que no hay.' );

		try {
			$this->redemption->capture_from_order( $order );
			$this->fail( 'Sin saldo para la deuda, el pago no puede seguir.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'ya no cubre el descuento', $e->getMessage() );
		}
		$this->assertSame( 'pending', $order->status );
		$this->assertSame( 300, Ledger::balance( 7 ) );
	}

	/** @testdox «Pagar pedido» sobre un pedido fallido cuya deuda el saldo no cubre se detiene. */
	public function test_pagar_un_pedido_fallido_con_deuda_sin_saldo_se_detiene(): void {
		$avisos = array();
		Functions\when( 'wc_add_notice' )->alias(
			static function ( string $mensaje, string $tipo = 'success' ) use ( &$avisos ): void {
				$avisos[] = array( $tipo, $mensaje );
			}
		);
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );
		$order->status = 'failed';
		$this->redemption->on_status_changed( 100, 'pending', 'failed', $order );
		Ledger::debit( 7, 1200, 'redemption', 'order', 999, '', true );

		$this->redemption->guard_order_pay( $order );

		$this->assertSame( 'error', $avisos[0][0] ?? '' );
		$this->assertSame( 'failed', $order->status );
	}

	/** @testdox Con saldo para la deuda, «pagar pedido» la re-cobra antes de cobrar y no otra vez al pagarse. */
	public function test_con_saldo_para_la_deuda_el_pago_sigue_y_recobra(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );
		$order->status = 'failed';
		$this->redemption->on_status_changed( 100, 'pending', 'failed', $order );

		$this->redemption->guard_order_pay( $order );
		$this->assertSame( 500, Ledger::balance( 7 ), 'Se re-cobran los 1.000 antes de ir a la pasarela.' );

		$order->status = 'on-hold';
		$this->redemption->on_status_changed( 100, 'failed', 'on-hold', $order );
		$this->assertSame( 500, Ledger::balance( 7 ), 'Al pagarse no se cobra otra vez.' );
	}

	/**
	 * @testdox Si «pagar pedido» vuelve a fallar sin cambiar el estado, los puntos re-cobrados vuelven solos.
	 *
	 * WooCommerce no pasa el pedido fallido por «pendiente» al pagarlo desde Mi
	 * cuenta: un rechazo sin cambio de estado dejaba los puntos en un pedido
	 * muerto, sin transición que los devolviera.
	 */
	public function test_pagar_pedido_que_vuelve_a_fallar_devuelve_lo_recobrado(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );
		$order->status = 'failed';
		$this->redemption->on_status_changed( 100, 'pending', 'failed', $order );

		$this->redemption->guard_order_pay( $order );
		$this->assertSame( 500, Ledger::balance( 7 ) );
		$this->assertCount( 1, $this->scheduled, 'Queda agendada la revisión del pedido.' );
		$this->assertSame( array( 100, 'impago' ), $this->scheduled[0]['args'] );
		$this->assertGreaterThanOrEqual( time() + HOUR_IN_SECONDS - 5, $this->scheduled[0]['ts'], 'Espera lo que WooCommerce le da a un pedido impago: a los 5 minutos el cliente puede seguir en la pasarela.' );

		// Con la ventana todavía abierta, la revisión NO devuelve: se reagenda.
		// Un segundo intento de pago la alarga y, con los mismos argumentos, la
		// cola no admite otra revisión (segunda revisión independiente).
		$this->redemption->run_retry( 100, 'impago' );
		$this->assertSame( 500, Ledger::balance( 7 ), 'El cliente puede seguir pagando: no se devuelve nada.' );
		$this->assertCount( 2, $this->scheduled, 'Queda agendada de nuevo para cuando cierre la ventana.' );

		// Cerrada la ventana, que es lo que ve la acción una hora después.
		$order->meta[ Redemption::META_PAY_WINDOW ] = time() - 1;
		$this->redemption->run_retry( 100, 'impago' ); // Sigue fallido.

		$this->assertSame( 1500, Ledger::balance( 7 ) );
		$this->assertArrayNotHasKey( Redemption::META_PAY_WINDOW, $order->meta, 'Cerrada la revisión, la ventana se borra.' );
		$this->assertSame( 1000, (int) $order->meta[ Redemption::META_REVIVAL_DEBT ], 'Y si después se paga, se re-cobra.' );
	}

	/** @testdox El paso del borrador a «pendiente» deja la captura a la caja. */
	public function test_el_borrador_que_pasa_a_pendiente_no_captura(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();

		$order->status = 'pending';
		$this->redemption->on_status_changed( 100, 'checkout-draft', 'pending', $order );
		$this->assertSame( 1500, Ledger::balance( 7 ) );
		$this->assertArrayNotHasKey( Redemption::META_REDEEMED_POINTS, $order->meta );

		$this->redemption->capture_from_order( $order );
		$this->assertSame( 500, Ledger::balance( 7 ), 'La caja captura enseguida.' );
	}

	/** @testdox Fuera de la caja, un saldo corto cobra lo que hay y avisa. */
	public function test_fuera_de_la_caja_un_saldo_corto_cobra_lo_que_hay_y_avisa(): void {
		Ledger::credit( 7, 400, 'order', 'order', 50 );
		$order = $this->order_with_fee();

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_redemption_shortfall' )->once();

		$order->status = 'processing';
		$this->redemption->on_status_changed( 100, 'pending', 'processing', $order );

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertSame( 400, $order->saved_meta[ Redemption::META_REDEEMED_POINTS ] );
	}

	/** @testdox Una devolución con el candado ocupado se reintenta sola. */
	public function test_una_devolucion_con_el_candado_ocupado_se_reintenta_sola(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		$this->wpdb->lock_result = '0';
		$order->status           = 'cancelled';
		$this->redemption->on_status_changed( 100, 'processing', 'cancelled', $order );
		$order->status = 'cancelled';
		$this->redemption->on_status_changed( 100, 'processing', 'cancelled', $order );

		$this->assertSame( 500, Ledger::balance( 7 ) );
		$this->assertCount( 1, $this->scheduled, 'Un solo reintento en la cola.' );
		$this->assertSame( Redemption::HOOK_RETRY, $this->scheduled[0]['hook'] );
		$this->assertSame( array( 100 ), $this->scheduled[0]['args'] );

		$this->wpdb->lock_result = '1';
		$this->redemption->run_retry( 100 );

		$this->assertSame( 1500, Ledger::balance( 7 ), 'Un pedido anulado no vuelve a cambiar de estado: el reintento es lo único que le devuelve los puntos.' );
	}

	/** @testdox Un reintento que vuelve a fallar agenda el siguiente aunque la cola lo cuente como en curso. */
	public function test_un_reintento_que_falla_agenda_el_siguiente(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );

		// Action Scheduler cuenta la acción EN CURSO como agendada.
		Functions\when( 'as_next_scheduled_action' )->justReturn( time() );

		$this->wpdb->lock_result = '0';
		$order->status           = 'cancelled';
		$this->redemption->run_retry( 100 );

		$this->assertCount( 1, $this->scheduled );
	}

	/** @testdox Tras cinco reintentos se deja de insistir y se avisa al dueño. */
	public function test_tras_cinco_reintentos_se_avisa_al_dueno(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );

		$order->meta[ Redemption::META_RETRIES ] = 5;
		$order->status                           = 'cancelled';
		$this->wpdb->lock_result                 = '0';

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_redemption_stuck' )->once();

		$this->redemption->run_retry( 100 );

		$this->assertSame( array(), $this->scheduled );
		$this->assertSame( 0, (int) $order->meta[ Redemption::META_RETRIES ], 'Tras avisar, un fallo futuro nuevo tiene cupo otra vez.' );
	}

	/**
	 * Revisión independiente 2026-09-13 (ALTA): con una pasarela que redirige,
	 * el pedido queda pendiente mientras el cliente paga allí. Contarlo como
	 * «pago rechazado» dejaba usar sus puntos en otra compra, y al terminar de
	 * pagar el primero había dos descuentos con los mismos puntos.
	 *
	 * @testdox Un pedido que redirigió a la pasarela no presta sus puntos a otra compra.
	 */
	public function test_un_pedido_en_la_pasarela_no_presta_sus_puntos_a_otra_compra(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => 'akb_rewards_redeem_min_cart' === $option ? 0 : $default_value
		);
		Ledger::credit( 7, 1000, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$en_pasarela = $this->order_with_fee();
		WC()->session->set( 'store_api_draft_order', 100 );
		$this->redemption->capture_from_order( $en_pasarela );
		$this->respuesta_de_caja(
			array(
				'order_id'       => 100,
				'payment_result' => array( 'payment_status' => 'success' ),
			)
		);

		$this->assertSame( 0, Redemption::available_points( 7 ), 'Sus puntos siguen retenidos mientras el cliente paga.' );
		$this->assertSame( 0, Redemption::effective_points( $this->cart() ) );

		// El cliente cambia el carrito en otra pestaña con un descuento que ya no tiene.
		$nuevo = $this->nuevo_pedido( 1000.0 );
		$this->redemption->remember_superseded( $nuevo );
		$this->assertArrayNotHasKey( Redemption::META_SUPERSEDES, $nuevo->meta );
		WC()->session->set( 'store_api_draft_order', 101 );
		$nuevo->status = 'pending';
		try {
			$this->redemption->capture_from_order( $nuevo );
			$this->fail( 'Sin saldo propio, el pedido nuevo no puede llevarse el descuento.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'saldo', $e->getMessage() );
		}

		// Y el primero, pagado en la pasarela, no deja alarma de saldo corto.
		\Brain\Monkey\Actions\expectDone( 'akb_rewards_redemption_shortfall' )->never();
		$en_pasarela->status = 'processing';
		$this->redemption->on_status_changed( 100, 'pending', 'processing', $en_pasarela );
		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertArrayNotHasKey( Redemption::META_RESTORED_POINTS, $en_pasarela->meta );
	}

	/** @testdox Un rechazo seguido de un intento que sí llega a la pasarela vuelve a retener los puntos. */
	public function test_un_intento_que_llega_a_la_pasarela_borra_la_marca_de_rechazo(): void {
		$order = $this->first_attempt_rejected( 1000 );
		$this->assertSame( 1000, Redemption::available_points( 7 ) );

		$this->respuesta_de_caja(
			array(
				'order_id'       => 100,
				'payment_result' => array( 'payment_status' => 'success' ),
			)
		);
		$this->assertArrayNotHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );
		$this->assertSame( 0, Redemption::available_points( 7 ) );

		// «Pagar pedido» y la caja clásica pasan por el filtro de pago exitoso.
		$order->meta[ Redemption::META_PAYMENT_REJECTED ] = time();
		$this->redemption->forget_rejection_on_success( array( 'result' => 'success' ), 100 );
		$this->assertArrayNotHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );
	}

	/** @testdox Un error de la caja que no es del pago (dirección, stock, el propio saldo) no marca el pedido. */
	public function test_un_error_que_no_es_del_pago_no_marca_el_pedido(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		WC()->session->set( 'store_api_draft_order', 100 );
		$this->redemption->capture_from_order( $order );

		$this->respuesta_de_caja( array( 'code' => 'woocommerce_rest_invalid_address' ) );
		$this->respuesta_de_caja( array( 'code' => 'akb_rewards_balance_changed' ) );
		$this->respuesta_de_caja( array( 'code' => 'woocommerce_rest_payment_error' ), 'GET' );
		$this->assertArrayNotHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );

		$this->respuesta_de_caja( array( 'code' => 'woocommerce_rest_payment_error' ) );
		$this->assertArrayHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta, 'El rechazo con mensaje llega como error y sin order_id: se lee de la sesión.' );
	}

	/** @testdox Si el rechazado volvió a la pasarela antes de liberar, el pedido nuevo no se lleva sus puntos. */
	public function test_la_liberacion_se_niega_si_el_rechazado_volvio_a_la_pasarela(): void {
		$rechazado = $this->first_attempt_rejected( 1000 );

		$nuevo = $this->nuevo_pedido( 1000.0 );
		$this->redemption->remember_superseded( $nuevo );
		$this->assertSame( array( 100 ), $nuevo->meta[ Redemption::META_SUPERSEDES ] );

		// En otra pestaña, «pagar pedido» del rechazado llega a la pasarela.
		$this->redemption->forget_rejection_on_success( array( 'result' => 'success' ), 100 );

		WC()->session->set( 'store_api_draft_order', 101 );
		$nuevo->status = 'pending';
		try {
			$this->redemption->capture_from_order( $nuevo );
			$this->fail( 'Los puntos siguen en el pedido que se está pagando.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'saldo', $e->getMessage() );
		}
		$this->assertArrayNotHasKey( Redemption::META_RESTORED_POINTS, $rechazado->meta );
		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	/**
	 * Revisión independiente 2026-09-13 (MEDIA): el candado del cliente ocupado
	 * al re-cobrar la deuda se trataba como «seguir»; la conciliación cobraba
	 * la diferencia y el paso a pagado volvía a cobrar la deuda.
	 *
	 * @testdox Un candado ocupado al re-cobrar la deuda detiene la caja y no cobra dos veces.
	 */
	public function test_un_candado_ocupado_al_recobrar_la_deuda_detiene_la_caja(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );
		$order->status = 'failed';
		$this->redemption->on_status_changed( 100, 'pending', 'failed', $order );
		$order->status = 'pending';
		$this->assertSame( 1500, Ledger::balance( 7 ) );

		$this->wpdb->busy_locks = array( 'akb_rewards_user_7' );
		try {
			$this->redemption->capture_from_order( $order );
			$this->fail( 'Sin poder cobrar la deuda, la caja no sigue.' );
		} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
			$this->assertSame( 'akb_rewards_busy', $e->getErrorCode() );
		}
		$this->assertSame( 1500, Ledger::balance( 7 ) );
		$this->assertSame( 1000, (int) $order->meta[ Redemption::META_REVIVAL_DEBT ] );

		$this->wpdb->busy_locks = array();
		$this->redemption->capture_from_order( $order );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		$order->status = 'processing';
		$this->redemption->on_status_changed( 100, 'pending', 'processing', $order );
		$this->assertSame( 500, Ledger::balance( 7 ), 'Una sola vez.' );
	}

	/** @testdox Con la deuda viva, la conciliación no cobra la diferencia. */
	public function test_la_conciliacion_no_cobra_con_la_deuda_viva(): void {
		Ledger::credit( 7, 3000, 'order', 'order', 50 );
		$order                                          = $this->order_with_fee();
		$order->meta[ Redemption::META_REDEEMED_POINTS ] = 1000;
		$order->meta[ Redemption::META_RESTORED_POINTS ] = 1000;
		$order->meta[ Redemption::META_REVIVAL_DEBT ]    = 1000;

		$conciliar = new \ReflectionMethod( Redemption::class, 'reconcile_reused' );
		$conciliar->setAccessible( true );

		$this->assertSame( 'ocupado', $conciliar->invoke( $this->redemption, $order ) );
		$this->assertSame( 3000, Ledger::balance( 7 ) );
	}

	/** @testdox La caja clásica también salda la deuda antes de conciliar un pedido reutilizado. */
	public function test_la_caja_clasica_salda_la_deuda_antes_de_conciliar(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );
		$order->status = 'failed';
		$this->redemption->on_status_changed( 100, 'pending', 'failed', $order );
		$order->status = 'pending';

		$this->redemption->capture( 100 );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		$order->status = 'processing';
		$this->redemption->on_status_changed( 100, 'pending', 'processing', $order );
		$this->assertSame( 500, Ledger::balance( 7 ) );
	}

	/**
	 * Revisión independiente 2026-09-13 (MEDIA): un rechazo sin redirección en
	 * «pagar pedido» dejaba los puntos re-cobrados en un pedido fallido,
	 * fuera de la vista del cliente, hasta la revisión de una hora.
	 *
	 * @testdox Si «pagar pedido» vuelve a rechazarse sin salir de la tienda, lo re-cobrado vuelve al instante.
	 */
	public function test_pagar_pedido_rechazado_sin_redireccion_devuelve_al_instante(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );
		$order->status = 'failed';
		$this->redemption->on_status_changed( 100, 'pending', 'failed', $order );

		$this->redemption->guard_order_pay( $order );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		$this->redemption->after_order_pay( $order ); // WooCommerce sólo llega aquí sin redirigir.

		$this->assertSame( 1500, Ledger::balance( 7 ) );
		$this->assertArrayNotHasKey( Redemption::META_PAY_WINDOW, $order->meta );
	}

	/** @testdox «Pagar pedido» rechazado sobre un pedido pendiente lo deja marcado como rechazado. */
	public function test_pagar_pedido_pendiente_rechazado_queda_marcado(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		$order = $this->order_with_fee();
		$this->redemption->guard_order_pay( $order );

		$this->redemption->after_order_pay( $order );

		$this->assertArrayHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );
	}

	/**
	 * Revisión independiente 2026-09-13 (BAJA): con los mismos argumentos, un
	 * reintento de 5 minutos ya en la cola impedía agendar la revisión de la
	 * ventana de pago, y devolvía los puntos con el cliente en la pasarela.
	 *
	 * @testdox Un reintento de 5 minutos no devuelve lo re-cobrado mientras el cliente puede seguir pagando.
	 */
	public function test_un_reintento_corto_no_devuelve_con_el_cliente_en_la_pasarela(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );
		$order->status = 'failed';
		$this->redemption->on_status_changed( 100, 'pending', 'failed', $order );

		$this->scheduled[] = array(
			'hook' => Redemption::HOOK_RETRY,
			'args' => array( 100 ),
			'ts'   => time() + 300,
		);

		$this->redemption->guard_order_pay( $order );
		$this->assertSame( 500, Ledger::balance( 7 ) );
		$this->assertCount( 2, $this->scheduled, 'La revisión de la ventana se agenda aunque haya un reintento corto.' );
		$this->assertSame( array( 100, 'impago' ), $this->scheduled[1]['args'] );

		$this->redemption->run_retry( 100 );
		$this->assertSame( 500, Ledger::balance( 7 ), 'El reintento corto no devuelve con la ventana abierta.' );

		// La revisión con motivo tampoco, hasta que la ventana cierre: se reagenda.
		$this->redemption->run_retry( 100, 'impago' );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		$order->meta[ Redemption::META_PAY_WINDOW ] = time() - 1;
		$this->redemption->run_retry( 100, 'impago' );
		$this->assertSame( 1500, Ledger::balance( 7 ) );
	}

	/** @testdox Mi cuenta ve lo apartado en todos los pedidos pendientes del cliente, no sólo en el de la compra en curso. */
	public function test_lo_apartado_se_cuenta_en_todos_los_pedidos_pendientes(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );

		$ajeno                                          = $this->make_order( 102, 8, 5000.0, 950.0 );
		$ajeno->status                                  = 'pending';
		$ajeno->meta[ Redemption::META_REDEEMED_POINTS ] = 300;
		$pagado                                          = $this->make_order( 103, 7, 5000.0, 950.0 );
		$pagado->status                                  = 'processing';
		$pagado->meta[ Redemption::META_REDEEMED_POINTS ] = 400;

		$consultas = array();
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( &$consultas, $order, $ajeno, $pagado ): array {
				$consultas[] = $args;
				return array( $order, $ajeno, $pagado );
			}
		);

		$this->assertSame( 1000, Redemption::held_in_unpaid_orders( 7 ), 'Sólo cuenta lo del cliente y pendiente.' );
		$this->assertSame( array( 'pending' ), $consultas[0]['status'] );
		$this->assertSame( 7, $consultas[0]['customer_id'] );
	}

	/**
	 * Segunda revisión independiente 2026-09-14 (MEDIA): «pagar pedido» se iba
	 * sin mirar el fee cuando ya había captura, así que un pedido con el
	 * descuento cambiado se pagaba con la diferencia sin cobrar.
	 *
	 * @testdox «Pagar pedido» concilia el descuento que el pedido lleva HOY.
	 */
	public function test_pagar_pedido_concilia_el_descuento_de_hoy(): void {
		Ledger::credit( 7, 3000, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );
		$this->assertSame( 2000, Ledger::balance( 7 ) );

		// El pedido termina con un descuento mayor que el canje cobrado.
		$this->set_fee( $order, 1500.0 );

		$this->redemption->guard_order_pay( $order );

		$this->assertSame( 1500, (int) $order->meta[ Redemption::META_REDEEMED_POINTS ], 'Se cobra la diferencia antes de pagar.' );
		$this->assertSame( 1500, Ledger::balance( 7 ) );
	}

	/**
	 * Segunda revisión independiente (MEDIA): la caja clásica comparte
	 * `available_points()`, que promete los puntos del pedido rechazado, pero
	 * no tenía quien los liberara.
	 *
	 * @testdox La caja clásica también libera el pedido rechazado que reemplaza.
	 */
	public function test_la_caja_clasica_libera_el_pedido_rechazado(): void {
		$rechazado = $this->first_attempt_rejected( 1000 );

		$nuevo = $this->nuevo_pedido( 1000.0 );
		$this->redemption->remember_superseded( $nuevo );
		$nuevo->status = 'pending';
		WC()->session->set( 'order_awaiting_payment', 101 );

		$this->redemption->capture( 101 );

		$this->assertSame( 1000, (int) $nuevo->meta[ Redemption::META_REDEEMED_POINTS ], 'El pedido nuevo cobra el canje.' );
		$this->assertSame( 1000, (int) $rechazado->meta[ Redemption::META_RESTORED_POINTS ], 'Y el rechazado lo soltó.' );
		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	/**
	 * Segunda revisión independiente (MEDIA): la devolución que fallaba por
	 * candado quedaba en una nota y el pedido, ya pagado, no volvía a pasar.
	 *
	 * @testdox Si la devolución del ajuste falla, se reintenta sola en vez de perderse.
	 */
	public function test_una_devolucion_del_ajuste_que_falla_se_reintenta(): void {
		Ledger::credit( 7, 3000, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );

		$this->set_fee( $order, 400.0 ); // El cliente bajó su canje.
		$this->wpdb->busy_locks = array( 'akb_rewards_user_7' );

		$this->redemption->capture_from_order( $order );

		$this->wpdb->busy_locks = array();
		$this->assertSame( 2000, Ledger::balance( 7 ), 'La devolución no pudo hacerse todavía.' );
		$this->assertNotSame( array(), $this->scheduled, 'Pero queda un reintento en la cola.' );
		$this->assertSame( Redemption::HOOK_RETRY, $this->scheduled[0]['hook'] );

		// Y el reintento la HACE. Hasta el 2026-09-19 esta prueba acababa en la
		// línea de arriba: sólo miraba que hubiera un trabajo en la cola, nunca lo
		// ejecutaba, y por eso no vio que ese trabajo no podía rehacer la
		// devolución — ningún camino de `run_retry()` volvía a entrar al ajuste, y
		// los 600 puntos quedaban cobrados para siempre (reapertura S-bc55ef27).
		$this->redemption->run_retry( $order->get_id() );

		$this->assertSame( 2600, Ledger::balance( 7 ), 'Los 600 de diferencia vuelven al cliente.' );
		$this->assertSame( 400, (int) $order->get_meta( Redemption::META_REDEEMED_POINTS ), 'Y el pedido dice lo que de verdad quedó cobrado.' );
		$this->assertSame( '', (string) $order->get_meta( Redemption::META_PENDING_GIVEBACK ), 'Sin deuda pendiente.' );
	}

	/**
	 * Revisión independiente de la 0.4.10 (BAJA): con el dato ilegible o el
	 * pedido sin cliente, el drenaje salía callado y la promesa de «se devuelve
	 * sola» no la cumplía nadie.
	 *
	 * @testdox Una devolución pendiente que no se puede completar sola queda escrita en el pedido, con su dato, y deja de reintentarse.
	 */
	public function test_una_devolucion_pendiente_ilegible_no_se_calla(): void {
		Ledger::credit( 7, 3000, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );
		$order->meta[ Redemption::META_PENDING_GIVEBACK ] = '{"points":600,"key":'; // JSON cortado.

		$this->redemption->run_retry( $order->get_id() );

		$this->assertSame( 2000, Ledger::balance( 7 ), 'No se inventa una devolución que no sabe leer.' );
		$this->assertSame( '', (string) $order->get_meta( Redemption::META_PENDING_GIVEBACK ), 'No se reintenta para siempre.' );
		$nota = implode( ' ', $order->notes );
		$this->assertStringContainsString( 'no se pudo completar sola', $nota );
		$this->assertStringContainsString( '{"points":600,"key":', $nota, 'El dato queda a la vista de quien lo resuelva.' );
	}

	/** @testdox Sin nada pendiente, el reintento no deja ninguna nota sobre devoluciones. */
	public function test_sin_devolucion_pendiente_no_hay_nota(): void {
		Ledger::credit( 7, 3000, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );

		$this->redemption->run_retry( $order->get_id() );

		$this->assertStringNotContainsString( 'devolución de puntos pendiente', implode( ' ', $order->notes ) );
	}

	/** @testdox Reintentar dos veces la devolución pendiente no la paga dos veces. */
	public function test_la_devolucion_pendiente_no_se_paga_dos_veces(): void {
		Ledger::credit( 7, 3000, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );

		$this->set_fee( $order, 400.0 );
		$this->wpdb->busy_locks = array( 'akb_rewards_user_7' );
		$this->redemption->capture_from_order( $order );
		$this->wpdb->busy_locks = array();

		$this->redemption->run_retry( $order->get_id() );
		$this->redemption->run_retry( $order->get_id() );

		$this->assertSame( 2600, Ledger::balance( 7 ) );
	}

	/** @testdox Si la devolución sigue fallando, los reintentos se AGOTAN y el dueño recibe la alerta. */
	public function test_una_devolucion_que_no_sale_termina_avisando_al_dueno(): void {
		Ledger::credit( 7, 3000, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );

		$this->set_fee( $order, 400.0 );
		$this->wpdb->busy_locks = array( 'akb_rewards_user_7' ); // El ledger no responde, y sigue sin responder.
		$this->redemption->capture_from_order( $order );

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_redemption_stuck' )->atLeast()->once();

		for ( $i = 0; $i < 8; $i++ ) {
			$this->redemption->run_retry( $order->get_id() );
		}

		$this->assertSame( 2000, Ledger::balance( 7 ), 'Nada se acreditó: el ledger nunca respondió.' );
		$this->assertNotSame( '', (string) $order->get_meta( Redemption::META_PENDING_GIVEBACK ), 'La deuda sigue escrita: no se pierde.' );
	}

	/** @testdox Si el pedido se anula con la devolución pendiente, el cliente recupera todo UNA vez. */
	public function test_anular_con_devolucion_pendiente_repone_todo_una_sola_vez(): void {
		Ledger::credit( 7, 3000, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );

		$this->set_fee( $order, 400.0 );
		$this->wpdb->busy_locks = array( 'akb_rewards_user_7' );
		$this->redemption->capture_from_order( $order );
		$this->wpdb->busy_locks = array();

		$order->status = 'cancelled';
		$this->redemption->run_retry( $order->get_id() ); // Pedido caído: reposición total.
		$this->assertSame( 3000, Ledger::balance( 7 ), 'Vuelven los 1.000 cobrados.' );
		$this->assertSame( '', (string) $order->get_meta( Redemption::META_PENDING_GIVEBACK ) );

		$this->redemption->run_retry( $order->get_id() );
		$this->assertSame( 3000, Ledger::balance( 7 ), 'Y un reintento tardío no acredita los 600 otra vez.' );
	}

	/**
	 * Segunda revisión independiente (MEDIA): la marca se borraba cuando la
	 * pasarela contestaba, y en esos segundos otra pestaña podía llevarse los
	 * puntos del pedido que se estaba pagando.
	 *
	 * @testdox «Pagar pedido» borra la marca de rechazo ANTES de llamar a la pasarela.
	 */
	public function test_pagar_pedido_borra_la_marca_antes_de_cobrar(): void {
		$order = $this->first_attempt_rejected( 1000 );
		$this->assertArrayHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );

		$this->redemption->guard_order_pay( $order );

		$this->assertArrayNotHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta, 'Mientras se paga, sus puntos no se prestan.' );
		$this->assertSame( 0, Redemption::available_points( 7 ) );
	}

	/**
	 * Reapertura S-1a84b15c (2026-09-19): el arreglo de «pagar pedido» no llegó
	 * a la caja de bloques, que es la superficie principal. Allí la marca sólo
	 * se borraba al contestar la pasarela.
	 *
	 * @testdox La caja de bloques borra la marca de rechazo ANTES de llamar a la pasarela.
	 */
	public function test_la_caja_de_bloques_borra_la_marca_antes_de_cobrar(): void {
		$order = $this->first_attempt_rejected( 1000 );
		$this->assertArrayHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );

		// El cliente reintenta en la misma caja: WooCommerce reutiliza el pedido.
		$this->redemption->capture_from_order( $order );

		$this->assertArrayNotHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta, 'Mientras se paga, sus puntos no se prestan.' );
		$this->assertSame( 0, Redemption::available_points( 7 ) );
	}

	/** @testdox Un segundo rechazo vuelve a marcar el pedido y el reintento siguiente conserva su descuento. */
	public function test_un_segundo_rechazo_vuelve_a_marcar_el_pedido(): void {
		$order = $this->first_attempt_rejected( 1000 );
		$this->redemption->capture_from_order( $order );
		$this->assertArrayNotHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );

		$this->respuesta_de_caja(
			array(
				'order_id'       => 100,
				'payment_result' => array( 'payment_status' => 'failure' ),
			)
		);

		$this->assertArrayHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );
		$this->assertSame( 1000, Redemption::effective_points( $this->cart() ), 'Sin la marca repuesta, el tercer intento salía sin descuento y a precio completo.' );
	}

	/**
	 * Revisión independiente de la 0.4.10: borrar la marca antes de cobrar abría
	 * un hueco que la 0.4.9 no tenía. Si la caja se detiene por algo que no es
	 * «de pago» —otra extensión que lanza, `woocommerce_rest_unknown_server_error`—,
	 * nadie la reponía: pedido pendiente, puntos retenidos y el reintento a
	 * precio completo.
	 *
	 * @testdox Si la caja se detiene por algo ajeno al pago, la marca que esta petición borró vuelve a su sitio.
	 */
	public function test_un_error_ajeno_al_pago_repone_la_marca_que_esta_peticion_borro(): void {
		$order = $this->first_attempt_rejected( 1000 );
		$this->redemption->capture_from_order( $order );
		$this->assertArrayNotHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );

		$this->respuesta_de_caja( array( 'code' => 'woocommerce_rest_unknown_server_error' ) );

		$this->assertArrayHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );
		$this->assertSame( 1000, Redemption::effective_points( $this->cart() ), 'El reintento conserva su descuento.' );
	}

	/** @testdox Si PHP muere sin contestar, el cierre de la petición repone la marca. */
	public function test_un_cierre_sin_respuesta_repone_la_marca(): void {
		$order = $this->first_attempt_rejected( 1000 );

		\Brain\Monkey\Actions\expectAdded( 'shutdown' )->once();
		$this->redemption->capture_from_order( $order );
		$this->assertArrayNotHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );

		$this->redemption->restore_unsettled_marks(); // Lo que corre en `shutdown`.

		$this->assertArrayHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );
	}

	/** @testdox Un pago que SÍ llegó a la pasarela no se vuelve a marcar al cerrar la petición. */
	public function test_un_pago_que_llego_a_la_pasarela_no_se_remarca_al_cerrar(): void {
		$order = $this->first_attempt_rejected( 1000 );
		$this->redemption->capture_from_order( $order );

		// Flow redirige: la Store API contesta «pending» y el cliente paga allá.
		$this->respuesta_de_caja(
			array(
				'order_id'       => 100,
				'payment_result' => array( 'payment_status' => 'pending' ),
			)
		);
		$this->redemption->restore_unsettled_marks();

		$this->assertArrayNotHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta, 'Con el cliente en la pasarela, sus puntos no se prestan.' );
	}

	/** @testdox Un pedido que se pagó entretanto no se marca al cerrar. */
	public function test_un_pedido_pagado_entretanto_no_se_marca_al_cerrar(): void {
		$order = $this->first_attempt_rejected( 1000 );
		$this->redemption->capture_from_order( $order );

		$order->status = 'processing';
		$this->redemption->restore_unsettled_marks();

		$this->assertArrayNotHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );
	}

	/**
	 * No vale marcar ante cualquier error: puede venir de otra pestaña mientras
	 * el pedido de la sesión está en una pasarela con redirección.
	 *
	 * @testdox Un error ajeno al pago NO marca un pedido que esta petición no desmarcó.
	 */
	public function test_un_error_ajeno_no_marca_lo_que_esta_peticion_no_desmarco(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order         = $this->order_with_fee();
		$order->status = 'pending';
		WC()->session->set( 'store_api_draft_order', 100 );
		$this->redemption->capture_from_order( $order ); // Nunca tuvo marca: va camino de la pasarela.

		$this->respuesta_de_caja( array( 'code' => 'woocommerce_rest_unknown_server_error' ) );
		$this->redemption->restore_unsettled_marks();

		$this->assertArrayNotHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );
	}

	/**
	 * La marca se borra DESPUÉS de la captura a propósito: la detención lanza
	 * con el carrito recalculado, y sin la marca ese carrito ya no prometía los
	 * puntos que retiene el pedido rechazado.
	 *
	 * @testdox Si la captura detiene la caja, la marca de rechazo sigue puesta y el reintento conserva su descuento.
	 */
	public function test_una_caja_detenida_conserva_la_marca(): void {
		$order = $this->first_attempt_rejected( 1000 );

		$this->wpdb->busy_locks = array( 'akb_rewards_order_100' );
		try {
			$this->redemption->capture_from_order( $order );
			$this->fail( 'Con el candado del pedido ocupado, la caja no sigue.' );
		} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
			$this->assertSame( 'akb_rewards_busy', $e->getErrorCode() );
		}

		$this->assertArrayHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta, 'La pasarela no llegó a llamarse: el pedido sigue siendo un rechazado.' );
		$this->assertSame( 1000, Redemption::effective_points( $this->cart() ) );
	}

	/**
	 * Segunda revisión independiente (BAJA): si el proceso muere entre el
	 * asiento y la meta, el reintento recibía 0 («ya aplicado») y lo escribía
	 * como si no se hubiera cobrado nada.
	 *
	 * @testdox Un asiento ya aplicado se anota por lo que se cobró, no como 0.
	 */
	public function test_un_asiento_ya_aplicado_no_se_anota_como_cero(): void {
		Ledger::credit( 7, 3000, 'order', 'order', 50 );
		$order = $this->order_with_fee();

		// El asiento existe con su event_key, pero la meta no se guardó.
		Ledger::debit( 7, 1000, 'redemption', 'order', 100, 'Canje', true, 'redeem_100' );
		$this->assertSame( 2000, Ledger::balance( 7 ) );

		$order->status = 'processing';
		$this->redemption->on_status_changed( 100, 'pending', 'processing', $order );

		$this->assertSame( 1000, (int) $order->meta[ Redemption::META_REDEEMED_POINTS ], 'Se reconstruye desde el libro.' );
		$this->assertSame( 2000, Ledger::balance( 7 ), 'Sin cobrar dos veces.' );
	}

	/** @testdox Sin `order_id` en la respuesta, la marca va al borrador de la caja, no a un pedido viejo. */
	public function test_la_marca_va_al_borrador_de_la_caja(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		WC()->session->set( 'store_api_draft_order', 100 );
		// Un pedido viejo de la caja clásica quedó anotado en la sesión.
		$viejo                                          = $this->make_order( 99, 7, 5000.0, 950.0 );
		$viejo->status                                  = 'pending';
		$viejo->meta[ Redemption::META_REDEEMED_POINTS ] = 500;
		$this->orders[99]                               = $viejo;
		WC()->session->set( 'order_awaiting_payment', 99 );

		$this->redemption->capture_from_order( $order );
		$this->respuesta_de_caja( array( 'code' => 'woocommerce_rest_payment_error' ) );

		$this->assertArrayHasKey( Redemption::META_PAYMENT_REJECTED, $order->meta );
		$this->assertArrayNotHasKey( Redemption::META_PAYMENT_REJECTED, $viejo->meta, 'El pedido viejo no se marca: sus puntos no se prestan.' );
	}
}
