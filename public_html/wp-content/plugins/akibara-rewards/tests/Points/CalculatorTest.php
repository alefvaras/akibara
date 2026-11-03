<?php
/**
 * Contrato de la matemática del programa.
 *
 * La promesa del carrito y la acreditación del pedido deben usar LA MISMA
 * base (spec req. 4): acá se fija con el mismo escenario evaluado por las dos
 * vías. Y el fee de canje debe descontar EXACTAMENTE el valor facial IVA
 * incluido (req. 3): el neto se fija contra el caso real que motivó el
 * mu-plugin de integridad (1.000 pts descontaban $1.190).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Calculator;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.1.0
 */
final class CalculatorTest extends RewardsTestCase {

	public function test_points_use_standard_rounding_like_prod_config(): void {
		// 2 pts / $100: $12.690 → 253,8 → 254 (rounding_mode=round de prod).
		$this->assertSame( 254, Calculator::points_for_base( 12690.0, 2, 100 ) );
		// $12.674 → 253,48 → 253.
		$this->assertSame( 253, Calculator::points_for_base( 12674.0, 2, 100 ) );
	}

	/**
	 * @testdox La razón del programa cuenta las TRES patas, no una.
	 *
	 * Ningún campo por separado delata una devolución absurda: con el «2» de
	 * siempre y un tramo de $1 la tienda devuelve el 200 % y los dos números
	 * se ven normales. Por eso la baranda del panel mira esta cuenta.
	 */
	public function test_giveback_ratio_counts_the_three_legs_together(): void {
		// La configuración vigente en producción: 2 puntos por cada $100, a $1.
		$this->assertSame( 2.0, Calculator::giveback_pct( 2, 100, 1 ) );

		// El tramo de $1: el mismo «2», y la tienda devuelve el doble de lo
		// que cobra.
		$this->assertSame( 200.0, Calculator::giveback_pct( 2, 1, 1 ) );

		$this->assertSame( 50.0, Calculator::giveback_pct( 50, 100, 1 ) );

		// El punto de diez pesos, con la acumulación intacta.
		$this->assertSame( 20.0, Calculator::giveback_pct( 2, 100, 10 ) );
	}

	/** @testdox Los apagados documentados dan razón cero, no infinita. */
	public function test_giveback_ratio_treats_the_documented_off_switches_as_zero(): void {
		// Tramo 0 es el APAGADO de la acumulación (Options::earn_amount): una
		// baranda que lo leyera como división por cero rompería la única
		// forma limpia de apagar el carril.
		$this->assertSame( 0.0, Calculator::giveback_pct( 2, 0, 1 ) );
		$this->assertSame( 0.0, Calculator::giveback_pct( 0, 100, 1 ) );
		$this->assertSame( 0.0, Calculator::giveback_pct( 2, 100, 0 ) );
		$this->assertSame( 0.0, Calculator::giveback_pct( -5, -5, -5 ) );
	}

	public function test_points_reject_degenerate_inputs(): void {
		$this->assertSame( 0, Calculator::points_for_base( 0.0, 2, 100 ) );
		$this->assertSame( 0, Calculator::points_for_base( -500.0, 2, 100 ) );
		$this->assertSame( 0, Calculator::points_for_base( 1000.0, 0, 100 ) );
		$this->assertSame( 0, Calculator::points_for_base( 1000.0, 2, 0 ) );
	}

	public function test_order_base_sums_items_with_tax_and_subtracts_refunds_and_redemption(): void {
		$order = $this->make_order( 100, 7, 10000.0, 1900.0 );

		// Reembolso de un ítem: WooCommerce entrega los totales NEGATIVOS.
		$order->refunds[] = new \WC_Order_Refund(
			array( new \WC_Order_Item( 'Tomo devuelto', -2000.0, -380.0 ) )
		);

		// Canje activo: $1.000 pagados con puntos no puntúan.
		$order->meta[ Redemption::META_REDEEMED_VALUE ] = 1000.0;

		// 11.900 − 2.380 − 1.000 = 8.520.
		$this->assertSame( 8520.0, Calculator::award_base_for_order( $order ) );
	}

	public function test_cart_base_mirrors_order_base_for_the_same_scenario(): void {
		$cart           = new \WC_Cart();
		$cart->contents = array(
			array(
				'line_total' => 10000.0,
				'line_tax'   => 1900.0,
			),
		);

		$order = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Redemption::META_REDEEMED_VALUE ] = 1000.0;

		// Mismo escenario, mismas dos vías, mismo número: la promesa del
		// carrito no puede divergir de lo acreditado (req. 4).
		$this->assertSame(
			Calculator::award_base_for_order( $order ),
			Calculator::award_base_for_cart( $cart, 1000.0 )
		);
	}

	/**
	 * R6-canje-redondeo (2026-09-19). Un tomo de $9.990 con IVA incluido
	 * llega del carrito como `line_total` 8.394,81 (neto SIN redondear) y
	 * `line_tax` 1.595 (YA redondeado): la suma cruda es 9.989,81 y el
	 * cliente ve $9.990. Con cinco de ellos más un tomo de $50 el carrito
	 * marca exactamente $50.000 y el canje se bloqueaba en 49.999,81; y
	 * «te faltan» decía 40.011 donde la resta real era 40.010.
	 */
	public function test_the_cart_base_is_the_amount_the_customer_sees(): void {
		$cart           = new \WC_Cart();
		$cart->contents = array(
			array(
				'line_total' => 8394.81,
				'line_tax'   => 1595.0,
			),
		);

		$this->assertSame( 9990.0, Calculator::award_base_for_cart( $cart ) );
		$this->assertSame( 40010, Redemption::missing_for_minimum( Calculator::award_base_for_cart( $cart ) ) );

		// Exactamente $50.000 en pantalla: la suma cruda da 49.999,81.
		$cart->contents = array(
			array(
				'line_total' => 42016.81,
				'line_tax'   => 7983.0,
			),
		);
		$this->assertSame( 50000.0, Calculator::award_base_for_cart( $cart ) );
		$this->assertSame( 0, Redemption::missing_for_minimum( Calculator::award_base_for_cart( $cart ) ) );
	}

	public function test_the_order_base_rounds_like_the_order_total(): void {
		$order = $this->make_order( 100, 7, 8394.81, 1595.0 );

		$this->assertSame( 9990.0, Calculator::award_base_for_order( $order ) );

		// Y sigue siendo la misma cifra que promete el carrito (req. 4).
		$cart           = new \WC_Cart();
		$cart->contents = array(
			array(
				'line_total' => 8394.81,
				'line_tax'   => 1595.0,
			),
		);
		$this->assertSame( Calculator::award_base_for_order( $order ), Calculator::award_base_for_cart( $cart ) );
	}

	public function test_order_base_subtracts_only_the_net_redemption_still_applied(): void {
		// Re-auditoría: restar el canje BRUTO castigaba la base dos veces
		// cuando la reposición proporcional ya devolvió parte de los puntos.
		$order = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Redemption::META_REDEEMED_VALUE ]  = 1000.0;
		$order->meta[ Redemption::META_RESTORED_POINTS ] = 400;

		// 11.900 − (1.000 − 400×$1) = 11.300.
		$this->assertSame( 11300.0, Calculator::award_base_for_order( $order ) );
	}

	public function test_order_base_never_goes_negative(): void {
		$order = $this->make_order( 100, 7, 500.0, 95.0 );
		$order->meta[ Redemption::META_REDEEMED_VALUE ] = 5000.0;

		$this->assertSame( 0.0, Calculator::award_base_for_order( $order ) );
	}

	public function test_cart_tax_rate_measures_real_lines_including_mixed_exempt(): void {
		$cart           = new \WC_Cart();
		$cart->contents = array(
			array(
				'line_total' => 10000.0,
				'line_tax'   => 1900.0,
			),
			array(
				'line_total' => 10000.0,
				'line_tax'   => 0.0, // Exento.
			),
		);

		// 1.900 / 20.000 = 0,095: la tasa EFECTIVA, no un 19% asumido.
		$this->assertEqualsWithDelta( 0.095, Calculator::cart_tax_rate( $cart ), 0.00001 );
	}

	public function test_redemption_fee_is_net_of_tax_so_gross_discount_equals_face_value(): void {
		// El caso que motivó el mu-plugin de integridad: 1.000 pts NO pueden
		// descontar $1.190. El fee va en neto (1000/1,19) y WooCommerce, al
		// repartirle el IVA, deja el descuento bruto en exactamente $1.000.
		$fee = Calculator::redemption_fee_amount( 1000.0, 0.19 );

		$this->assertEqualsWithDelta( -840.336134, $fee, 0.000001 );
		$this->assertEqualsWithDelta( -1000.0, $fee * 1.19, 0.0001 );
	}

	public function test_redemption_fee_on_exempt_cart_is_the_gross(): void {
		$this->assertSame( -1000.0, Calculator::redemption_fee_amount( 1000.0, 0.0 ) );
	}

	public function test_a_zero_tranche_disables_accrual_instead_of_crediting_100x(): void {
		// Célula 2026-08-31: el max(1) viejo convertía el campo vaciado en
		// tramo de $1 y una compra de $19.900 acuñaba 39.800 puntos.
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_earn_amount' === $option ? 0 : $default_value
		);

		$this->assertSame( 0, \Akibara\Rewards\Support\Options::earn_amount() );
		$this->assertSame( 0, Calculator::points_for_base( 19900.0, 2, \Akibara\Rewards\Support\Options::earn_amount() ), 'Tramo 0 = acumulación APAGADA.' );
	}

	public function test_returned_redeem_points_are_valued_at_the_orders_own_rate(): void {
		// El pedido canjeó 1.500 puntos por $3.000 ($2 el punto) y se le
		// repusieron 500. La config vigente dice $1: con ella la devolución
		// valía $500 y el neto quedaba en $2.500 — la tasa se DERIVA del
		// propio pedido (RX 2026-08-31).
		$order = new \WC_Order();
		$order->meta[ Redemption::META_REDEEMED_VALUE ]  = 3000.0;
		$order->meta[ Redemption::META_REDEEMED_POINTS ] = 1500;
		$order->meta[ Redemption::META_RESTORED_POINTS ] = 500;

		$this->assertSame( 2000.0, Calculator::net_redeemed_value( $order ) );
	}

}
