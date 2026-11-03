<?php
/**
 * Contrato del programa de referidos (función premium absorbida, 2026-08-30).
 *
 * Fija el anti-abuso: nada por registrarse (venta real o nada), imposible
 * auto-referirse (código propio y mismo email), UN premio por invitado en la
 * historia (event_key contra el UNIQUE), tope configurable por padrino, y el
 * vínculo sobrevive a la cookie (usermeta sellada al crear la cuenta).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\Referrals;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.2.0
 */
final class ReferralsTest extends RewardsTestCase {

	private Referrals $referrals;

	/** @var array<int,\WP_User> */
	private array $users = array();

	protected function setUp(): void {
		parent::setUp();

		$this->referrals = new Referrals();

		Functions\when( 'wp_hash' )->alias( static fn( string $data ): string => md5( $data ) );
		Functions\when( 'wp_salt' )->justReturn( 'sal-de-prueba' );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) );
		Functions\when( 'wp_unslash' )->returnArg();

		Functions\when( 'get_user_by' )->alias(
			function ( string $by, $id ) {
				unset( $by );
				return $this->users[ (int) $id ] ?? false;
			}
		);
		Functions\when( 'get_users' )->alias(
			function ( array $args ): array {
				$code = (string) ( $args['meta_value'] ?? '' );
				foreach ( $this->wpdb->user_meta as $uid => $metas ) {
					$v = $metas[ Referrals::META_CODE ] ?? '';
					$v = is_array( $v ) ? ( $v[0] ?? '' ) : $v;
					if ( (string) $v === $code ) {
						return array( $uid );
					}
				}
				return array();
			}
		);
	}

	/**
	 * @param int    $id    Usuario.
	 * @param string $email Correo.
	 */
	private function user( int $id, string $email ): void {
		$u                  = new \WP_User( $id );
		$u->user_email      = $email;
		$this->users[ $id ] = $u;
	}

	public function test_the_code_is_stable_and_resolvable(): void {
		$code = Referrals::code_for( 7 );

		$this->assertSame( 10, strlen( $code ) );
		$this->assertSame( $code, Referrals::code_for( 7 ), 'El código no cambia entre llamadas.' );
		$this->assertSame( 7, Referrals::owner_of( $code ) );
	}

	public function test_the_cookie_seals_into_usermeta_at_registration(): void {
		$this->user( 7, 'padrino@example.com' );
		$this->user( 42, 'invitado@example.com' );
		$_COOKIE[ Referrals::COOKIE ] = Referrals::code_for( 7 );

		$this->referrals->seal_referred( 42 );

		$this->assertSame( 7, (int) $this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] );
		unset( $_COOKIE[ Referrals::COOKIE ] );
	}

	public function test_existing_user_without_prior_purchases_seals_on_login(): void {
		$this->user( 7, 'padrino@example.com' );
		$this->user( 42, 'invitado@example.com' );
		$_COOKIE[ Referrals::COOKIE ] = Referrals::code_for( 7 );

		Functions\when( 'wc_get_orders' )->justReturn( array() );

		$this->referrals->maybe_seal_on_login( 'invitado', $this->users[42] );

		$this->assertSame( 7, (int) $this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] );
		unset( $_COOKIE[ Referrals::COOKIE ] );
	}

	public function test_existing_user_with_prior_purchases_does_not_seal_on_login(): void {
		$this->user( 7, 'padrino@example.com' );
		$this->user( 42, 'antiguo@example.com' );
		$_COOKIE[ Referrals::COOKIE ] = Referrals::code_for( 7 );

		Functions\when( 'wc_get_orders' )->justReturn( array( 88 ) );

		$this->referrals->maybe_seal_on_login( 'antiguo', $this->users[42] );

		$this->assertArrayNotHasKey( Referrals::META_REFERRED_BY, $this->wpdb->user_meta[42] ?? array() );
		unset( $_COOKIE[ Referrals::COOKIE ] );
	}

	public function test_checkout_order_records_referral_order_meta(): void {
		$this->user( 7, 'padrino@example.com' );
		$this->user( 42, 'invitado@example.com' );
		$_COOKIE[ Referrals::COOKIE ] = Referrals::code_for( 7 );

		$order = $this->make_order( 100, 42, 10000.0, 1900.0 );
		$this->referrals->seal_order_referral( $order );

		$this->assertSame( 7, (int) $order->get_meta( Referrals::META_REFERRED_BY ) );
		unset( $_COOKIE[ Referrals::COOKIE ] );
	}

	public function test_self_referral_is_impossible(): void {
		$this->user( 7, 'padrino@example.com' );
		$_COOKIE[ Referrals::COOKIE ] = Referrals::code_for( 7 );

		// Con su propio código…
		$this->referrals->seal_referred( 7 );
		$this->assertArrayNotHasKey( Referrals::META_REFERRED_BY, $this->wpdb->user_meta[7] );

		// …y con otra cuenta del MISMO email.
		$this->user( 43, 'PADRINO@example.com' );
		$this->referrals->seal_referred( 43 );
		$this->assertArrayNotHasKey( Referrals::META_REFERRED_BY, $this->wpdb->user_meta[43] ?? array() );
		unset( $_COOKIE[ Referrals::COOKIE ] );
	}

	/**
	 * El mismo agujero que la acreditación cerró el 2026-09-16 seguía abierto
	 * aquí: el premio exigía pisar EXACTAMENTE el estado del panel. Con «Listo
	 * para enviar» configurado, un pedido que salta a «Completado» dejaba al
	 * padrino sin su premio, para siempre y sin aviso.
	 *
	 * @testdox Un pedido que salta el estado que paga, igual paga al padrino.
	 */
	public function test_un_pedido_que_salta_el_estado_igual_paga(): void {
		$this->user( 7, 'padrino@example.com' );
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_referral_status' === $option ? 'shipping-progress' : $default_value
		);

		$order = $this->make_order( 100, 42, 10000.0, 1900.0 );
		$this->referrals->on_status_changed( 100, 'processing', 'completed', $order );

		$this->assertSame( 500, Ledger::balance( 7 ) );
	}

	/** @testdox Un estado ANTERIOR al configurado no adelanta el premio. */
	public function test_un_estado_anterior_no_paga(): void {
		$this->user( 7, 'padrino@example.com' );
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_referral_status' === $option ? 'completed' : $default_value
		);

		$order = $this->make_order( 100, 42, 10000.0, 1900.0 );
		$this->referrals->on_status_changed( 100, 'pending', 'processing', $order );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	public function test_the_first_accredited_order_pays_the_referrer_once(): void {
		$this->user( 7, 'padrino@example.com' );
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;

		$order = $this->make_order( 100, 42, 10000.0, 1900.0 );

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_referral_paid' )
			->once()
			->with( 7, 42, 500, $order );

		$this->referrals->maybe_pay_referrer( $order, 42 );
		// Una «segunda primera compra» (revival, re-disparo) no re-paga.
		$this->referrals->maybe_pay_referrer( $order, 42 );

		$this->assertSame( 500, Ledger::balance( 7 ), 'UN premio por invitado en la historia.' );
		$claves = array_column( array_column( $this->wpdb->inserts, 1 ), 'event_key' );
		$this->assertContains( 'referral_42', $claves );
	}

	public function test_the_referrer_cap_is_respected(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) {
				if ( 'akb_rewards_referral_max_per_user' === $option ) {
					return 1;
				}
				return $default_value;
			}
		);

		$this->user( 7, 'padrino@example.com' );
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;
		$this->wpdb->user_meta[43][ Referrals::META_REFERRED_BY ] = 7;

		$this->referrals->maybe_pay_referrer( $this->make_order( 100, 42, 10000.0, 1900.0 ), 42 );
		$this->referrals->maybe_pay_referrer( $this->make_order( 101, 43, 10000.0, 1900.0 ), 43 );

		$this->assertSame( 500, Ledger::balance( 7 ), 'El tope del padrino manda: el segundo invitado no paga.' );
	}

	public function test_unreferred_customers_pay_nothing(): void {
		$this->referrals->maybe_pay_referrer( $this->make_order( 100, 42, 10000.0, 1900.0 ), 42 );

		$this->assertCount( 0, $this->wpdb->inserts );
	}

	public function test_a_fallen_paying_order_reverses_the_prize_and_reopens_eligibility(): void {
		$this->user( 7, 'padrino@example.com' );
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;

		$order = $this->make_order( 100, 42, 10000.0, 1900.0 );
		$this->referrals->maybe_pay_referrer( $order, 42 );
		$this->assertSame( 500, Ledger::balance( 7 ) );
		$this->assertSame( 100, (int) ( $this->wpdb->user_meta[42][ Referrals::META_REFERRAL_ORDER ] ?? 0 ) );

		// El pedido del invitado se anula: el premio se revierte…
		$this->referrals->maybe_reverse_referrer( $order, 42 );
		$this->assertSame( 0, Ledger::balance( 7 ), 'El premio no sobrevive a la venta que no existió.' );
		$this->assertArrayNotHasKey( Referrals::META_REFERRAL_ORDER, $this->wpdb->user_meta[42] );

		// …dos anulaciones no revierten dos veces…
		$this->referrals->maybe_reverse_referrer( $order, 42 );
		$this->assertSame( 0, Ledger::balance( 7 ) );
		$reversas = array_filter( $this->wpdb->inserts, static fn( array $x ): bool => 'referral_reversal' === $x[1]['action'] );
		$this->assertCount( 1, $reversas );

		// …y la SIGUIENTE compra real del invitado vuelve a pagar (ocurrencia).
		$orden2 = $this->make_order( 101, 42, 8000.0, 1520.0 );
		$this->referrals->maybe_pay_referrer( $orden2, 42 );
		$this->assertSame( 500, Ledger::balance( 7 ), 'Re-elegible tras la reversa: venta real o nada, en ambos sentidos.' );
		$claves = array_column( array_column( $this->wpdb->inserts, 1 ), 'event_key' );
		$this->assertContains( 'referral_42_r1', $claves );
	}

	public function test_switching_the_lane_off_still_reverses_a_prize_already_paid(): void {
		$this->user( 7, 'padrino@example.com' );
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;

		$order = $this->make_order( 100, 42, 10000.0, 1900.0 );
		$this->referrals->maybe_pay_referrer( $order, 42 );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		// El dueño apaga el carril DESPUÉS de haber pagado…
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_referral_enabled' === $option ? 'no' : $default_value
		);

		// …y recién ahí se cae el pedido que justificaba el premio.
		$this->referrals->maybe_reverse_referrer( $order, 42 );

		$this->assertSame( 0, Ledger::balance( 7 ), 'Apagar el carril no puede regalar puntos de una venta anulada.' );
	}

	public function test_the_reversal_stays_hooked_when_the_lane_is_off_at_boot(): void {
		// El defecto era de REGISTRO, no de lógica: con el carril apagado,
		// register() se iba antes de colgar NADA y la reversa no existía.
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_referral_enabled' === $option ? 'no' : $default_value
		);

		$colgados = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$colgados ): void {
				$colgados[] = $hook;
			}
		);

		$this->referrals->register();

		$this->assertContains( 'woocommerce_order_status_changed', $colgados, 'El pago y la reversa cuelgan SIEMPRE del estado del pedido.' );
		$this->assertContains( 'woocommerce_order_refunded', $colgados, 'Un reembolso total también revierte.' );
		$this->assertNotContains( 'wp_enqueue_scripts', $colgados, 'Apagado no se capta la visita…' );
		$this->assertNotContains( 'akb_rewards_account_extra', $colgados, '…ni se promete nada en Mi cuenta.' );
	}

	public function test_reversal_ignores_orders_that_did_not_pay_the_prize(): void {
		$this->user( 7, 'padrino@example.com' );
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ]    = 7;
		$this->wpdb->user_meta[42][ Referrals::META_REFERRAL_ORDER ] = 100;
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ]            = 500;

		$otro = $this->make_order( 999, 42, 5000.0, 950.0 );
		$this->referrals->maybe_reverse_referrer( $otro, 42 );

		$this->assertSame( 500, Ledger::balance( 7 ), 'Solo el pedido pagador dispara la reversa.' );
	}

	/** @testdox El cupón virtual de referido se fabrica con monto 2000, mínimo 30000, individual_use y exclude_sale_items false (v1.1). */
	public function test_fabricate_referral_coupon(): void {
		$this->user( 7, 'padrino@example.com' );
		$this->wpdb->user_meta[7][ Referrals::META_CODE ] = 'padrino123';

		$coupon_data = $this->referrals->fabricate_referral_coupon( false, 'refreferido-padrino123' );

		$this->assertIsArray( $coupon_data );
		$this->assertSame( 2000.0, $coupon_data['amount'] );
		$this->assertSame( 30000.0, $coupon_data['minimum_amount'] );
		$this->assertTrue( $coupon_data['individual_use'] );
		$this->assertFalse( $coupon_data['exclude_sale_items'] );
		$this->assertSame( 'fixed_cart', $coupon_data['discount_type'] );

		// También con prefijo corto ref-
		$short_data = $this->referrals->fabricate_referral_coupon( false, 'ref-padrino123' );
		$this->assertIsArray( $short_data );
		$this->assertSame( 2000.0, $short_data['amount'] );

		// Código inexistente no fabrica cupón
		$invalid = $this->referrals->fabricate_referral_coupon( false, 'ref-inexistente' );
		$this->assertFalse( $invalid );
	}

	/** @testdox El cupón de referido no permite auto-referencia. */
	public function test_referral_coupon_blocks_self_referral(): void {
		$this->install_fake_wc();
		$this->user( 7, 'padrino@example.com' );
		$this->wpdb->user_meta[7][ Referrals::META_CODE ] = 'padrino123';
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$fake_coupon = new class() extends \WC_Coupon {
			public function get_code(): string { return 'ref-padrino123'; }
		};

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'No puedes usar tu propio código de referido.' );

		$this->referrals->validate_referral_coupon( true, $fake_coupon );
	}

	/** @testdox El bono de bienvenida no se acredita si el pedido usó beneficio de referido. */
	public function test_welcome_bonus_excluded_when_referral_benefit_used(): void {
		$order = $this->make_order( 100, 42, 30000.0, 5700.0 );
		$order->meta['_akb_ref_code'] = 'padrino123';

		$this->assertTrue( \Akibara\Rewards\Points\WelcomeBonus::has_referral_benefit( $order ) );
	}
}
