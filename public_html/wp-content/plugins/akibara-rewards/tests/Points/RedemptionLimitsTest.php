<?php
/** El máximo mostrado y el fee obedecen al carrito y al saldo actuales. */
declare( strict_types = 1 );
namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\AccountRewards;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class RedemptionLimitsTest extends RewardsTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->install_fake_wc();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wc_get_order' )->justReturn( false );
		Functions\when( 'wc_add_notice' )->justReturn( null );
		Functions\when( 'wc_price' )->alias( static fn( $n ) => '$' . number_format( $n, 0, ',', '.' ) );
	}

	private function options( array $overrides = array() ): void {
		$values = array_merge( array(
			'akb_rewards_redeem_min_cart' => 50000,
			'akb_rewards_redeem_min_points' => 100,
			'akb_rewards_redeem_max_pct' => 20,
			'akb_rewards_redeem_max_amount' => 5000,
			'akb_rewards_redeem_value' => 1,
		), $overrides );
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $values[ $key ] ?? $default );
	}

	private function cart( float $net, float $tax = 0.0 ): \WC_Cart {
		$cart = new class() extends \WC_Cart {
			public ?float $recalculated_net = null;
			public array $applied_coupons = array();
			public array $coupon_discounts = array();
			public function get_shipping_total(): float { return 10000.0; }
			public function get_applied_coupons(): array { return $this->applied_coupons; }
			public function get_coupon_discount_amount( $code ): float { return $this->coupon_discounts[ $code ] ?? 0.0; }
			public function get_coupon_discount_tax_amount( $code ): float { return 0.0; }
			public function calculate_totals(): void {
				if ( null !== $this->recalculated_net ) {
					$this->contents[0]['line_total'] = $this->recalculated_net;
				}
			}
		};
		$cart->contents = array( array( 'line_subtotal' => 60000.0, 'line_total' => $net, 'line_tax' => $tax ) );
		WC()->cart = $cart;
		return $cart;
	}

	/**
	 * @dataProvider economic_cases
	 * @testdox El máximo canónico y el motor respetan mínimos, IVA, cupones, saldo y topes.
	 */
	public function test_economic_limits( float $net, float $tax, int $balance, array $options, int $max, int $missing ): void {
		$this->options( $options );
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = $balance;
		$cart = $this->cart( $net, $tax );
		Redemption::set_intent( 20000 );
		$session = WC()->session->data;
		$meta = $this->wpdb->user_meta;
		$limits = Redemption::redemption_limits( $cart, 7 );

		self::assertSame( $max, $limits['max_points'] );
		self::assertSame( $missing, $limits['missing'] );
		self::assertSame( $max, Redemption::effective_points( $cart ) );
		self::assertSame( $session, WC()->session->data, 'Una lectura no escribe intención ni fecha.' );
		self::assertSame( $meta, $this->wpdb->user_meta );
		self::assertCount( 0, $this->wpdb->inserts );
	}

	public static function economic_cases(): array {
		return array(
			'49999 tras cupón' => array( 49999.0, 0.0, 20000, array(), 0, 1 ),
			'50000 exactos tope5000' => array( 50000.0, 0.0, 20000, array(), 5000, 0 ),
			'40k productos más10k despacho' => array( 40000.0, 0.0, 20000, array(), 0, 10000 ),
			'IVA cruza mínimo' => array( 42000.0, 8000.0, 20000, array(), 5000, 0 ),
			'cupón reduce hasta49999 conIVA' => array( 42000.0, 7999.0, 20000, array(), 0, 1 ),
			'porcentaje3punto2' => array( 50000.0, 0.0, 20000, array( 'akb_rewards_redeem_max_pct' => 3, 'akb_rewards_redeem_value' => 2 ), 750, 0 ),
			'saldo menor que topes' => array( 50000.0, 0.0, 350, array(), 350, 0 ),
			'tope50 menor que mínimo100' => array( 50000.0, 0.0, 20000, array( 'akb_rewards_redeem_max_amount' => 50 ), 0, 0 ),
			'saldo50 menor que mínimo100' => array( 50000.0, 0.0, 50, array(), 0, 0 ),
			'mínimos y valor distintos' => array( 30000.0, 0.0, 20000, array( 'akb_rewards_redeem_min_cart' => 30000, 'akb_rewards_redeem_min_points' => 500, 'akb_rewards_redeem_value' => 3, 'akb_rewards_redeem_max_amount' => 2100 ), 700, 0 ),
			'sin topes y sin mínimo' => array( 50000.0, 0.0, 20000, array( 'akb_rewards_redeem_min_cart' => 0, 'akb_rewards_redeem_min_points' => 0, 'akb_rewards_redeem_max_pct' => 0, 'akb_rewards_redeem_max_amount' => 0 ), 20000, 0 ),
		);
	}

	/** @testdox La caída de saldo100 a50 no puede aplicar un canje bajo el mínimo100. */
	public function test_minimum_is_checked_after_clamping(): void {
		$this->options();
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 50;
		Redemption::set_intent( 100 );
		self::assertSame( 0, Redemption::effective_points( $this->cart( 50000.0 ) ) );
		self::assertSame( 100, Redemption::intent_points(), 'La lectura conserva la intención para que se pueda quitar.' );
	}

	/** @testdox El máximo incluye sólo la reserva reutilizable del pedido propio y nunca la libera al leer. */
	public function test_reusable_reservation_and_ownership(): void {
		$this->options();
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 150;
		$order = $this->make_order( 99, 7, 50000, 0 );
		$order->status = 'pending';
		$order->meta[ Redemption::META_REDEEMED_POINTS ] = 700;
		$order->meta[ Redemption::META_PAYMENT_REJECTED ] = 1;
		WC()->session->set( 'order_awaiting_payment', 99 );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$cart = $this->cart( 50000.0 );
		$before = $order->meta;
		self::assertSame( 850, Redemption::redemption_limits( $cart, 7 )['max_points'] );
		self::assertSame( $before, $order->meta );
		$order->meta[ Redemption::META_PAYMENT_REJECTED ] = 0;
		self::assertSame( 150, Redemption::redemption_limits( $cart, 7 )['max_points'] );
		$order->meta[ Redemption::META_PAYMENT_REJECTED ] = 1;
		$order->customer_id = 8;
		self::assertSame( 150, Redemption::redemption_limits( $cart, 7 )['max_points'] );
	}

	/** @testdox Confirmar recomputa el carrito y rechaza cambios sin guardar ni borrar la intención anterior. */
	public function test_post_revalidates_and_removal_survives_empty_cart(): void {
		$this->options();
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 20000;
		$cart = $this->cart( 50000.0 );
		$account = new AccountRewards();
		self::assertSame( 5000, $account->process_redeem( 7, 5000 ) );
		$session = WC()->session->data;
		self::assertSame( 0, $account->process_redeem( 7, 20000 ) );
		self::assertSame( $session, WC()->session->data );
		$cart->recalculated_net = 49999.0;
		self::assertSame( 5000, Redemption::redemption_limits( $cart, 7 )['max_points'], 'El snapshot previo sigue50k hasta recalcular.' );
		self::assertSame( 0, $account->process_redeem( 7, 5000 ) );
		self::assertSame( $session, WC()->session->data );
		$cart->recalculated_net = null;
		$cart->contents = array();
		self::assertSame( 0, Redemption::redemption_limits( $cart, 7 )['max_points'] );
		self::assertFalse( Redemption::redemption_limits( null, 7 )['cart_present'] );
		self::assertSame( 0, $account->process_redeem( 7, 5000 ) );
		self::assertSame( $session, WC()->session->data );
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 0;
		self::assertSame( 0, $account->process_redeem( 7, 0 ) );
		self::assertSame( 0, Redemption::intent_points() );
	}

	/** @testdox Sin intención el máximo disponible no crea un fee ni activa un canje. */
	public function test_inactive_flow_and_guest(): void {
		$this->options();
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 20000;
		$cart = $this->cart( 50000.0 );
		self::assertSame( 5000, Redemption::redemption_limits( $cart, 7 )['max_points'] );
		self::assertSame( 0, Redemption::effective_points( $cart ) );
		( new Redemption() )->apply_fee( $cart );
		self::assertCount( 0, $cart->fees );
		self::assertSame( 0, Redemption::intent_points() );
		self::assertSame( 0, Redemption::redemption_limits( $cart, 0 )['max_points'] );
	}

	/** @testdox Los cupones de SeriesBundles muestran un mensaje motivacional de pack y ahorro futuro cuando el pack iguala o supera los puntos. */
	public function test_series_bundle_coupon_message(): void {
		$this->options();
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 20000;
		$cart = $this->cart( 50000.0 );
		$code = 'akb_sb_byp_1234567890abcdef1234567890abcdef';
		$cart->applied_coupons = array( $code );
		$cart->coupon_discounts[ $code ] = 6000.0; // Descuento $6.000 > puntos redimibles ($5.000).

		$limits = Redemption::redemption_limits( $cart, 7 );
		self::assertTrue( $limits['has_coupons'] );
		self::assertTrue( $limits['has_bundle_coupon'] );
		self::assertSame( 0, $limits['max_points'] );
		self::assertFalse( $limits['points_exceed_bundle'] );

		$msg = Redemption::unavailable_message( $limits );
		self::assertStringContainsString( 'descuento directo por pack', $msg );
		self::assertStringContainsString( 'acumularás Puntos Akibara', $msg );
	}

	/** @testdox Cuando los puntos superan al descuento del pack, se ofrece el switch de conmutación en 1 clic sin inyectar HTML crudo en el mensaje de texto. */
	public function test_series_bundle_coupon_switch_message_when_points_exceed_bundle(): void {
		$this->options( array( 'akb_rewards_redeem_min_cart' => 25000 ) );
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 20000;
		$cart = $this->cart( 47000.0 ); // Subtotal de líneas tras pack ($51.000 - $4.000 = $47.000).
		$code = 'akb_sb_byp_1234567890abcdef1234567890abcdef';
		$cart->applied_coupons = array( $code );
		$cart->coupon_discounts[ $code ] = 4000.0; // Sin el pack la base vuelve a $51.000 (Tramo 2 -> 5.000 pts > $4.000).

		$limits = Redemption::redemption_limits( $cart, 7 );
		self::assertTrue( $limits['has_coupons'] );
		self::assertTrue( $limits['has_bundle_coupon'] );
		self::assertSame( 0, $limits['max_points'] );
		self::assertSame( 5000, $limits['max_redeemable_points'] );
		self::assertTrue( $limits['points_exceed_bundle'] );

		$msg = Redemption::unavailable_message( $limits );
		self::assertStringContainsString( 'superan los', $msg );
		self::assertStringContainsString( 'Puedes usar tus $5.000 en puntos en lugar del descuento de pack', $msg );
		self::assertStringNotContainsString( '<a ', $msg );
	}
}
