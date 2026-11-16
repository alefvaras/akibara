<?php
/** The drawer and checkout promise the same calculated cart rewards. */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Calculator;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\Promise;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Points\Tiers;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class CartPromiseTest extends RewardsTestCase {
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		$this->options = array( 'akb_rewards_program_start' => '2020-01-01 00:00:00' );
		Functions\when( 'get_option' )->alias( fn ( $key, $default = false ) => $this->options[ $key ] ?? $default );
	}

	private function cart( array $fees = array() ): \WC_Cart {
		$cart = new class( $fees ) extends \WC_Cart {
			public function __construct( private array $effective_fees ) {}
			public function get_fees(): array { return $this->effective_fees; }
		};
		$cart->contents = array( array( 'line_total' => 10000.0, 'line_tax' => 1900.0 ) );
		return $cart;
	}

	/** @testdox Only an effective redemption fee, including its tax, reduces the cart promise. */
	public function test_effective_fee_reduces_base_without_mutating_cart(): void {
		$cart = $this->cart( array( (object) array( 'name' => Redemption::FEE_NAME, 'amount' => -840.34, 'total' => -840.34, 'tax' => -159.66 ) ) );
		$before = serialize( array( $cart->get_cart(), $cart->get_fees() ) );
		self::assertSame( 218, Promise::for_cart( $cart ) );
		self::assertSame( $before, serialize( array( $cart->get_cart(), $cart->get_fees() ) ), 'Reading a promise must not calculate or mutate a cart.' );
		self::assertSame( 238, Promise::for_cart( $this->cart( array( (object) array( 'name' => 'Otro fee', 'total' => -1000, 'tax' => 0 ) ) ) ) );
	}

	/** @testdox Null carts, disabled earning and closed programs cannot promise points. */
	public function test_gates_are_shared_by_all_cart_consumers(): void {
		self::assertSame( 0, Promise::for_cart( null ) );
		$this->options['akb_rewards_earn_enabled'] = 'no';
		self::assertSame( 0, Promise::for_cart( $this->cart() ) );
		$this->options['akb_rewards_earn_enabled'] = 'yes';
		$this->options['akb_rewards_program_start'] = 'ayer';
		self::assertSame( 0, Promise::for_cart( $this->cart() ) );
		$this->options['akb_rewards_program_start'] = gmdate( 'Y-m-d H:i:s', time() + 86400 );
		self::assertSame( 0, Promise::for_cart( $this->cart() ) );
	}

	/** @testdox The cart uses the user's effective tier and preserves both rounding stages. */
	public function test_personal_tier_matches_accrual_authority(): void {
		$this->wpdb->user_meta[7][Ledger::META_TOTAL_EARNED] = 20000;
		$this->wpdb->user_meta[7][Tiers::META_TIER_EXPIRES_AT] = time() + 86400;
		$cart = $this->cart();
		self::assertGreaterThan( 1.0, Tiers::multiplier_for_user( 7 ) );
		self::assertSame( Calculator::points_for( 11900, 7 ), Promise::for_cart( $cart, 7 ) );
	}
}
