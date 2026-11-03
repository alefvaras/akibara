<?php
/** Checkout, cuenta y motor deben publicar el mismo descuento aplicable. */
declare( strict_types = 1 );
namespace Akibara\Rewards\Tests\Blocks;

require_once dirname( __DIR__ ) . '/Support/route-exception-stub.php';

use Akibara\Rewards\Blocks\CheckoutIntegration;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Brain\Monkey\Functions;

final class CheckoutRedemptionTest extends RewardsTestCase {
	private CheckoutIntegration $sut;
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install_fake_wc();
		$this->sut = new CheckoutIntegration();
		$this->options = array( 'akb_rewards_redeem_min_cart' => 50000, 'akb_rewards_redeem_min_points' => 100, 'akb_rewards_redeem_max_pct' => 50, 'akb_rewards_redeem_max_amount' => 5000 );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_option' )->alias( fn( $k, $default = false ) => $this->options[$k] ?? $default );
		Functions\when( 'wc_get_order' )->justReturn( false );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'wc_price' )->alias( static fn( $n ) => '$' . number_format( $n, 0, ',', '.' ) );
		$this->wpdb->user_meta[7][Ledger::META_BALANCE] = 10500;
		WC()->cart = new class() extends \WC_Cart {
			public ?float $next_total = null;
			public function get_fees(): array { return array(); }
			public function calculate_totals(): void {
				if ( null !== $this->next_total ) { $this->contents[0]['line_total'] = $this->next_total; }
			}
		};
		WC()->cart->contents = array( array( 'line_total' => 50000.0, 'line_tax' => 0.0 ) );
	}

	/** @testdox Un saldo bajo el mínimo conserva la intención pero nunca anuncia descuento activo. */
	public function test_reduced_balance_cannot_be_active(): void {
		Redemption::set_intent( 100 );
		$this->wpdb->user_meta[7][Ledger::META_BALANCE] = 50;
		$data = $this->sut->cart_data();
		self::assertSame( 0, $data['max'] );
		self::assertSame( 0, $data['effective'] );
		self::assertSame( 100, $data['intent'] );
		self::assertStringContainsString( 'faltan 50 puntos', $data['unavailable'] );
		self::assertCount( 0, $this->wpdb->inserts );
	}

	/** @testdox Un tope menor que el mínimo bloquea igual que en Mi cuenta. */
	public function test_low_cap_is_unavailable(): void {
		$this->options['akb_rewards_redeem_max_amount'] = 50;
		$data = $this->sut->cart_data();
		self::assertSame( 0, $data['max'] );
		self::assertStringContainsString( 'topes', $data['unavailable'] );
	}

	/** @testdox Elegir 5000 requiere una actualización explícita; leer el máximo no aplica nada. */
	public function test_explicit_valid_request(): void {
		$data = $this->sut->cart_data();
		self::assertSame( 5000, $data['max'] );
		self::assertSame( 0, $data['effective'] );
		self::assertSame( 0, Redemption::intent_points() );
		$this->sut->handle_update( array( 'points' => 5000 ) );
		self::assertSame( 5000, $this->sut->cart_data()['effective'] );
		self::assertSame( 10500, Ledger::balance( 7 ) );
	}

	/** @dataProvider invalid_requests */
	public function test_invalid_requests_preserve_previous_intent( $request ): void {
		Redemption::set_intent( 300 );
		$before = WC()->session->data;
		try {
			$this->sut->handle_update( array( 'points' => $request ) );
			self::fail( 'Debe rechazar sin cambiar el canje anterior.' );
		} catch ( RouteException $e ) {
			self::assertSame( 400, $e->getCode() );
		}
		self::assertSame( $before, WC()->session->data );
	}

	public static function invalid_requests(): array {
		return array( array( 50 ), array( 5001 ), array( -1 ), array( '100.5' ), array( array( 100 ) ), array( null ) );
	}

	/** @testdox Un cambio a49999 se recalcula y rechaza; quitar siempre funciona. */
	public function test_stale_cart_and_removal(): void {
		Redemption::set_intent( 300 );
		WC()->cart->next_total = 49999.0;
		try {
			$this->sut->handle_update( array( 'points' => 5000 ) );
			self::fail( 'No debe aceptar el total viejo.' );
		} catch ( RouteException $e ) {
			self::assertStringContainsString( '$1 en productos', $e->getMessage() );
		}
		self::assertSame( 300, Redemption::intent_points() );
		WC()->cart->contents = array();
		$this->wpdb->user_meta[7][Ledger::META_BALANCE] = 0;
		$this->sut->handle_update( array( 'points' => 0 ) );
		self::assertSame( 0, Redemption::intent_points() );
	}
}
