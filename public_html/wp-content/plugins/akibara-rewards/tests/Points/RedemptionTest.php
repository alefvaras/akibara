<?php
/**
 * Contrato del canje.
 *
 * Fija los cierres de la célula adversarial 2026-08-30: la captura cobra LO
 * QUE EL FEE DEL PEDIDO CONCEDIÓ (no la intención cruda de la sesión), bajo
 * candado y con idempotencia (doble click cobra UNA vez); el fallo de candado
 * jamás se lee como saldo insuficiente; los puntos canjeados SE DEVUELVEN al
 * anular (todo) y al reembolsar parcial (proporcional, acumulado); y un
 * pedido repuesto que revive re-cobra lo repuesto (el hoyo que SPAR deja).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

// `test_a_lock_timeout_is_not_read_as_insufficient_balance` espera la
// RouteException de la Store API. Sin este require la clase pasaba sólo cuando
// CanjeEnLaCajaTest había cargado el doble antes; aislada caía (2026-09-19).
require_once dirname( __DIR__ ) . '/Support/route-exception-stub.php';

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.1.0
 */
final class RedemptionTest extends RewardsTestCase {

	private Redemption $redemption;

	/** @var array<int,\WC_Order> */
	private array $orders = array();

	protected function setUp(): void {
		parent::setUp();

		$this->redemption = new Redemption();
		$this->install_fake_wc();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wc_price' )->alias(
			static fn( $amount ) => '$' . number_format( (float) $amount, 0, ',', '.' )
		);

		$orders = &$this->orders;
		Functions\when( 'wc_get_order' )->alias(
			static function ( int $id ) use ( &$orders ) {
				return $orders[ $id ] ?? false;
			}
		);
	}

	/**
	 * Carrito estándar: $10.000 + $1.900 de IVA.
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
	 * Pedido registrado en el mapa, con el fee de canje YA horneado con los
	 * valores que WooCommerce persiste (neto + IVA repartido = bruto $1.000).
	 *
	 * @param float $fee_total Neto del fee.
	 * @param float $fee_tax   IVA repartido del fee.
	 */
	private function order_with_fee( float $fee_total = -840.34, float $fee_tax = -159.66 ): \WC_Order {
		$order                 = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->items['fee'][] = new \WC_Order_Item( Redemption::FEE_NAME, $fee_total, $fee_tax );
		$this->orders[100]     = $order;

		return $order;
	}

	public function test_intent_is_capped_by_balance(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => 'akb_rewards_redeem_min_cart' === $option ? 0 : $default_value
		);
		Ledger::credit( 7, 300, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );

		$this->assertSame( 300, Redemption::effective_points( $this->cart() ) );
	}

	public function test_intent_is_capped_by_the_cart_gross(): void {
		// Sin tope configurado (0), el único techo son las líneas del carrito.
		Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => array(
					'akb_rewards_redeem_max_pct'    => 0,
					'akb_rewards_redeem_max_amount' => 0,
					// El carrito mínimo para canjear tiene su contrato aparte
					// (PrimeraCompraTest); aquí se mide el tope, no el mínimo.
					'akb_rewards_redeem_min_cart'   => 0,
				)[ $option ] ?? $default_value
		);
		Ledger::credit( 7, 50000, 'order', 'order', 50 );
		Redemption::set_intent( 50000 );

		// Las líneas suman $11.900: no se pueden canjear más de 11.900 pts.
		$this->assertSame( 11900, Redemption::effective_points( $this->cart() ) );
	}

	/**
	 * Tope de canje por pedido (orden del dueño 2026-09-02: «coloca tope de
	 * canje de puntos»). Sin él un cliente con saldo se llevaba la mercadería
	 * sin pagar un peso y encima cruzaba el umbral de envío gratis con plata
	 * que nunca entró a caja.
	 *
	 * @testdox El tope del panel acota el canje al porcentaje configurado.
	 */
	public function test_redemption_is_capped_by_the_configured_percentage(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => array(
					'akb_rewards_redeem_max_pct'    => 50,
					'akb_rewards_redeem_max_amount' => 0,
					'akb_rewards_redeem_min_cart'   => 0,
				)[ $option ] ?? $default_value
		);
		Ledger::credit( 7, 50000, 'order', 'order', 50 );
		Redemption::set_intent( 50000 );

		// Líneas $11.900 con tope del 50 % → como mucho 5.950 pts.
		$this->assertSame( 5950, Redemption::effective_points( $this->cart() ) );
	}

	/** @testdox Bajo el tope, el cliente canjea lo que pidió. */
	public function test_a_request_under_the_cap_is_untouched(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => array(
					'akb_rewards_redeem_max_pct'    => 50,
					'akb_rewards_redeem_max_amount' => 0,
					'akb_rewards_redeem_min_cart'   => 0,
				)[ $option ] ?? $default_value
		);
		Ledger::credit( 7, 50000, 'order', 'order', 50 );
		Redemption::set_intent( 2000 );

		$this->assertSame( 2000, Redemption::effective_points( $this->cart() ) );
	}

	/**
	 * @dataProvider topes
	 * @testdox La parte canjeable de $11.900 con tope $_dataName
	 */
	public function test_redeemable_gross_por_tope( int $pct, float $esperado ): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => array(
					'akb_rewards_redeem_max_pct'    => $pct,
					'akb_rewards_redeem_max_amount' => 0,
				)[ $option ] ?? $default_value
		);

		$this->assertSame( $esperado, Redemption::redeemable_gross( 11900.0 ) );
	}

	/** @return array<string, array{0:int, 1:float}> */
	public static function topes(): array {
		return array(
			'0 (sin tope)' => array( 0, 11900.0 ),
			'30 %'         => array( 30, 3570.0 ),
			'50 %'         => array( 50, 5950.0 ),
			'100 %'        => array( 100, 11900.0 ),
			'negativo'     => array( -10, 11900.0 ),
			'sobre 100'    => array( 150, 11900.0 ),
		);
	}

	public function test_guests_cannot_redeem(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Redemption::set_intent( 100 );

		$this->assertSame( 0, Redemption::effective_points( $this->cart() ) );
	}

	public function test_the_fee_lands_net_of_tax_and_non_taxable(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => 'akb_rewards_redeem_min_cart' === $option ? 0 : $default_value
		);
		Ledger::credit( 7, 1000, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );

		$cart = $this->cart();
		$this->redemption->apply_fee( $cart );

		$this->assertCount( 1, $cart->fees );
		$fee = $cart->fees[0];
		$this->assertSame( Redemption::FEE_NAME, $fee['name'] );
		$this->assertFalse( $fee['taxable'] );
		// Neto: 1000/1,19. Cuando WooCommerce le reparta el IVA, el descuento
		// bruto será exactamente $1.000.
		$this->assertEqualsWithDelta( -840.336134, $fee['amount'], 0.000001 );
	}

	public function test_no_intent_no_fee(): void {
		$cart = $this->cart();
		$this->redemption->apply_fee( $cart );

		$this->assertCount( 0, $cart->fees );
	}

	public function test_capture_charges_what_the_fee_granted_not_the_raw_intent(): void {
		// El hallazgo ALTA: intención 5.000, pero el carrito topó el fee en
		// $1.000. Cobrar la intención evaporaba 4.000 puntos.
		Ledger::credit( 7, 5000, 'order', 'order', 50 );
		Redemption::set_intent( 5000 );

		$order = $this->order_with_fee(); // Fee horneado: bruto $1.000.
		$this->redemption->capture_from_order( $order );

		$this->assertSame( 4000, Ledger::balance( 7 ), 'Solo se cobran los 1.000 que el fee concedió.' );
		$this->assertSame( 1000, $order->saved_meta[ Redemption::META_REDEEMED_POINTS ] );
		$this->assertEqualsWithDelta( 1000.0, $order->saved_meta[ Redemption::META_REDEEMED_VALUE ], 0.01 );
		$this->assertSame( 5000, Redemption::intent_points(), 'La intención dura hasta que el carrito se vacía: un pago rechazado reintenta con el mismo descuento.' );
	}

	public function test_capture_is_idempotent_under_the_order_lock(): void {
		// Doble click en «Pagar»: dos hooks sobre el mismo pedido.
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );

		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );
		$this->redemption->capture_from_order( $order );

		$this->assertSame( 500, Ledger::balance( 7 ), 'UNA captura, no dos.' );
		$this->assertSame( 1000, Redemption::intent_points(), 'La segunda pasada tampoco borra la intención.' );
		$this->assertGreaterThanOrEqual( 2, $order->meta_reloads, 'Cada captura re-lee las metas frescas.' );
	}

	public function test_a_lock_timeout_is_not_read_as_insufficient_balance(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();

		$this->wpdb->lock_result = '0';
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_redemption_shortfall' )->never();

		// Sin candado de PEDIDO la caja se detiene con «vuelve a intentar», no
		// con «tu saldo cambió»: seguir dejaba ir a la pasarela un descuento sin
		// cobrar (revisión independiente 2026-09-13).
		try {
			$this->redemption->capture_from_order( $order );
			$this->fail( 'La caja tenía que detenerse.' );
		} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
			$this->assertSame( 'akb_rewards_busy', $e->getErrorCode() );
			$this->assertStringContainsString( 'vuelve a confirmar', $e->getMessage() );
		}

		// Ni cobro, ni alarma de saldo corto, ni intención consumida.
		$this->assertSame( 1500, Ledger::balance( 7 ) );
		$this->assertArrayNotHasKey( Redemption::META_REDEEMED_POINTS, $order->meta );
		$this->assertSame( 1000, Redemption::intent_points() );
	}

	public function test_capture_with_short_balance_charges_what_exists_and_raises_the_alarm(): void {
		// Fuera de la caja (la captura pendiente que reintenta un cambio de
		// estado): el pedido ya existe y no se puede detener. En la caja, un
		// saldo que no alcanza detiene la compra (CanjeEnLaCajaTest).
		Ledger::credit( 7, 400, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_redemption_shortfall' )
			->once()
			->whenHappen(
				function ( $order, $points, $debited, $shortfall ): void {
					$this->assertSame( 1000, $points );
					$this->assertSame( 400, $debited );
					$this->assertSame( 600, $shortfall );
				}
			);

		$order = $this->order_with_fee();
		$order->status = 'processing';
		$this->redemption->on_status_changed( 100, 'pending', 'processing', $order );

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertSame( 400, $order->saved_meta[ Redemption::META_REDEEMED_POINTS ] );
		// El descuento CONCEDIDO fue $1.000 aunque el saldo cubriera 400: la
		// base puntuable del pedido debe restar lo que no se pagó con plata.
		$this->assertEqualsWithDelta( 1000.0, $order->saved_meta[ Redemption::META_REDEEMED_VALUE ], 0.01 );
		$this->assertStringContainsString( 'saldo solo cubrió 400', implode( ' ', $order->notes ) );
	}

	public function test_capture_without_our_fee_charges_nothing(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );

		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 ); // Sin fee.
		$this->orders[100] = $order;
		$this->redemption->capture_from_order( $order );

		$this->assertSame( 1500, Ledger::balance( 7 ) );
		$this->assertArrayNotHasKey( Redemption::META_REDEEMED_POINTS, $order->meta );
		$this->assertSame( 1000, Redemption::intent_points(), 'Sin fee (bajo el mínimo, por ejemplo) la intención espera: se aplica al llegar.' );
	}

	public function test_cancellation_restores_all_remaining_redeemed_points(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		$order->status = 'cancelled';
		$this->redemption->on_status_changed( 100, 'pending', 'cancelled', $order );

		$this->assertSame( 1500, Ledger::balance( 7 ), 'El canje vuelve completo al saldo.' );
		$this->assertSame( 1000, $order->saved_meta[ Redemption::META_RESTORED_POINTS ] );

		// Y el evento repetido (refunded tras cancelled) no repone dos veces.
		$order->status = 'refunded';
		$this->redemption->on_status_changed( 100, 'cancelled', 'refunded', $order );
		$this->assertSame( 1500, Ledger::balance( 7 ) );
	}

	public function test_restoration_does_not_inflate_the_historic_total(): void {
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );

		$order->status = 'cancelled';
		$this->redemption->on_status_changed( 100, 'pending', 'cancelled', $order );

		$this->assertSame( 1500, Ledger::total_earned( 7 ), 'Devolver un canje no es ganancia nueva.' );
	}

	public function test_partial_refund_restores_proportionally_by_items(): void {
		Ledger::credit( 7, 2000, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );
		$this->assertSame( 1000, Ledger::balance( 7 ) );

		// Ítems: $11.900. Reembolsan la MITAD de la mercadería ($5.950) →
		// floor(1000 × 0,5) = 500 puntos de vuelta.
		$order->refunds[] = new \WC_Order_Refund(
			array( new \WC_Order_Item( 'Tomo devuelto', -5000.0, -950.0 ) )
		);

		$this->redemption->on_order_refunded( 100, 900 );
		$this->assertSame( 1500, Ledger::balance( 7 ) );
		$this->assertSame( 500, $order->saved_meta[ Redemption::META_RESTORED_POINTS ] );
		// Reposición de pedido VIVO: jamás alimenta la deuda de revival.
		$this->assertArrayNotHasKey( Redemption::META_REVIVAL_DEBT, $order->saved_meta );

		// El MISMO evento re-disparado no duplica (acumulador idempotente).
		$this->redemption->on_order_refunded( 100, 900 );
		$this->assertSame( 1500, Ledger::balance( 7 ) );
	}

	public function test_a_shipping_only_refund_restores_nothing(): void {
		Ledger::credit( 7, 2000, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );

		// Reembolsan SOLO el envío: la mercadería y su descuento siguen
		// íntegros — cero puntos de vuelta.
		$order->refunds[] = new \WC_Order_Refund(
			array(),
			3500.0,
			array( new \WC_Order_Item( 'Envío', -2941.18, -558.82 ) )
		);

		$this->redemption->on_order_refunded( 100, 900 );

		$this->assertSame( 1000, Ledger::balance( 7 ) );
	}

	public function test_normal_fulfillment_never_steals_a_partial_restoration(): void {
		// EL hallazgo de la re-auditoría: reembolso parcial con el pedido
		// VIVO y luego el avance normal processing → «Listo para enviar».
		Ledger::credit( 7, 2000, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );

		$order->refunds[] = new \WC_Order_Refund(
			array( new \WC_Order_Item( 'Tomo devuelto', -5000.0, -950.0 ) )
		);
		$this->redemption->on_order_refunded( 100, 900 );
		$this->assertSame( 1500, Ledger::balance( 7 ) );

		$order->status = 'shipping-progress';
		$this->redemption->on_status_changed( 100, 'processing', 'shipping-progress', $order );

		$this->assertSame( 1500, Ledger::balance( 7 ), 'El fulfillment normal NO re-cobra lo devuelto por un parcial.' );
	}

	public function test_a_migrated_restoration_is_never_recaptured(): void {
		// La trampa de cutover: pedidos históricos entran con RESTORED > 0
		// (espejo de SPAR) y CERO deuda de revival.
		Ledger::credit( 7, 1000, 'order', 'order', 50 );
		$order = $this->order_with_fee();
		$order->meta[ Redemption::META_REDEEMED_POINTS ]  = 700;
		$order->meta[ Redemption::META_REDEEMED_VALUE ]   = 700.0;
		$order->meta[ Redemption::META_RESTORED_POINTS ]  = 200;

		$order->status = 'completed';
		$this->redemption->on_status_changed( 100, 'processing', 'completed', $order );

		$this->assertSame( 1000, Ledger::balance( 7 ), 'El histórico migrado no pierde puntos al cambiar de estado.' );
	}

	public function test_a_clamped_recapture_does_not_rearm_the_restoration(): void {
		// La imprenta de puntos de la re-auditoría: failed repone, el cliente
		// gasta lo repuesto, revive (cobro clampado en 0), y el siguiente
		// failed NO puede reponer el canje completo otra vez.
		Ledger::credit( 7, 1000, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );
		$this->assertSame( 0, Ledger::balance( 7 ) );

		$order->status = 'failed';
		$this->redemption->on_status_changed( 100, 'pending', 'failed', $order );
		$this->assertSame( 1000, Ledger::balance( 7 ), 'El fallo repone.' );

		// El cliente gasta lo repuesto en otro pedido.
		Ledger::debit( 7, 1000, 'redemption', 'order', 999, '', true );
		$this->assertSame( 0, Ledger::balance( 7 ) );

		// Revive y se paga: el re-cobro clampa en 0 (con alarma), pero RESTORED
		// conserva la verdad. (Volver a «pendiente» ya no re-cobra: el pago lo
		// frena la caja si el saldo no cubre la deuda; el cobro va al pagarse.)
		$order->status = 'pending';
		$this->redemption->on_status_changed( 100, 'failed', 'pending', $order );
		$order->status = 'processing';
		$this->redemption->on_status_changed( 100, 'pending', 'processing', $order );
		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertSame( 1000, $order->saved_meta[ Redemption::META_RESTORED_POINTS ] );

		// Segundo fallo: remaining = redeemed − RESTORED = 0 → NADA que
		// acuñar.
		$order->status = 'failed';
		$this->redemption->on_status_changed( 100, 'processing', 'failed', $order );
		$this->assertSame( 0, Ledger::balance( 7 ), 'El bucle failed→pending no imprime puntos.' );
	}

	public function test_an_uncaptured_fee_is_captured_on_the_next_alive_transition(): void {
		// El candado ocupado al crear el pedido no puede dejar el descuento
		// regalado: la transición viva siguiente reintenta la captura.
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		$order = $this->order_with_fee(); // Fee horneado, SIN captura previa.

		$order->status = 'processing';
		$this->redemption->on_status_changed( 100, 'pending', 'processing', $order );

		$this->assertSame( 500, Ledger::balance( 7 ) );
		$this->assertSame( 1000, $order->saved_meta[ Redemption::META_REDEEMED_POINTS ] );
	}

	public function test_a_revived_order_recaptures_what_was_restored(): void {
		// El hoyo de SPAR: failed repone, el cliente re-paga con el descuento
		// horneado en los totales del pedido → hay que re-cobrar.
		Ledger::credit( 7, 1500, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );

		$order->status = 'failed';
		$this->redemption->on_status_changed( 100, 'pending', 'failed', $order );
		$this->assertSame( 1500, Ledger::balance( 7 ), 'El fallo de pago repone.' );

		$order->status = 'processing';
		$this->redemption->on_status_changed( 100, 'failed', 'processing', $order );

		$this->assertSame( 500, Ledger::balance( 7 ), 'El revival re-cobra los 1.000 repuestos.' );
		$this->assertSame( 0, $order->saved_meta[ Redemption::META_RESTORED_POINTS ] );
	}

	public function test_a_clamped_recapture_marks_unsettled_so_the_award_base_does_not_forgive_it(): void {
		// Hallazgo MEDIA del confirmador: con el re-cobro clampado, RESTORED
		// quedaba arriba y la base puntuable del pedido revivido «perdonaba»
		// un descuento que el cliente nunca repagó.
		Ledger::credit( 7, 1000, 'order', 'order', 50 );
		Redemption::set_intent( 1000 );
		$order = $this->order_with_fee();
		$this->redemption->capture_from_order( $order );

		$order->status = 'cancelled';
		$this->redemption->on_status_changed( 100, 'pending', 'cancelled', $order );
		Ledger::debit( 7, 1000, 'redemption', 'order', 999, '', true ); // Se lo gasta.

		$order->status = 'processing';
		$this->redemption->on_status_changed( 100, 'cancelled', 'processing', $order );

		$this->assertSame( 1000, $order->saved_meta[ Redemption::META_UNSETTLED ] );
		// La base del pedido revivido sigue restando el canje completo.
		$this->assertEqualsWithDelta(
			1000.0,
			\Akibara\Rewards\Points\Calculator::net_redeemed_value( $order ),
			0.01,
			'El canje no repagado NO se perdona en la base puntuable.'
		);
	}

	public function test_plain_transitions_pay_no_lock_when_there_is_nothing_to_do(): void {
		// Hallazgo BAJA: cada transición de CUALQUIER pedido pagaba candado y
		// relectura para descubrir que no había deuda.
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 ); // Sin fee, sin metas.
		$this->orders[100] = $order;

		$order->status = 'processing';
		$this->redemption->on_status_changed( 100, 'pending', 'processing', $order );

		$locks = array_filter(
			$this->wpdb->queries,
			static fn( string $sql ): bool => false !== strpos( $sql, 'GET_LOCK' )
		);
		$this->assertSame( array(), array_values( $locks ), 'Sin fee y sin deuda no se toca ni un candado.' );
	}

	public function test_capture_for_a_guest_order_charges_nothing(): void {
		Redemption::set_intent( 1000 );

		$order              = $this->order_with_fee();
		$order->customer_id = 0;
		$this->redemption->capture_from_order( $order );

		$this->assertCount( 0, $this->wpdb->inserts );
		$this->assertSame( 1000, Redemption::intent_points(), 'La captura nunca borra la intención; la borran vaciar el carrito o pagar el pedido.' );
	}

	public function test_emptying_the_cart_clears_the_intent(): void {
		Redemption::set_intent( 1000 );
		$this->redemption->clear_intent();

		$this->assertSame( 0, Redemption::intent_points() );
	}

	public function test_an_intent_below_the_minimum_never_applies(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 1000;
		Redemption::set_intent( 50 ); // Bajo el mínimo (100 default).

		$this->assertSame( 0, Redemption::effective_points( $this->cart() ), 'Bajo el mínimo, el canje no aplica ni un peso.' );
	}
}
