<?php
/**
 * Prueba de compra por pedido enlazado, incluido el estado propio de envío.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Reviews\Media;
use Akibara\Rewards\Reviews\ReviewForm;
use Akibara\Rewards\Reviews\ReviewPoints;
use Akibara\Rewards\Reviews\VerifiedPurchase;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class VerifiedPurchaseTest extends RewardsTestCase {

	/** @return \WC_Order Pedido realmente pagado de la cuenta 42. */
	private function paid_order(): \WC_Order {
		$order = $this->make_order( 700, 42, 10000.0, 0.0 );
		$order->status = 'shipping-progress';
		$order->billing_email = 'cliente@example.com';
		$order->date_paid = new \WC_DateTime( '2026-10-01 10:00:00' );
		$order->items['line_item'][0]->product_id = 777;
		return $order;
	}

	/** @testdox Listo para enviar con pago real acredita una vez aunque Woo dejara verified=0. */
	public function test_paid_shipping_order_repairs_verification_and_pays_once(): void {
		$order = $this->paid_order();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'wc_customer_bought_product' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'wp_attachment_is' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => 'akb_rewards_review_max_per_user' === $key ? 5 : $default
		);
		$review = new \WP_Comment(
			array( 'comment_ID' => 901, 'comment_post_ID' => 777, 'comment_type' => 'review', 'user_id' => 42, 'comment_author_email' => 'cliente@example.com' )
		);
		$this->wpdb->comment_meta[901] = array(
			'verified' => array( '0' ),
			ReviewForm::META_ORDER => array( 700 ),
			Media::META_LOCAL_IMAGE => array( 9010 ),
		);
		\Brain\Monkey\Actions\expectDone( 'akb_rewards_review_points_paid' )->once()->with( $review, 42, 500 );
		$sut = new ReviewPoints();
		$sut->on_status_transition( 'approved', 'unapproved', $review );
		$sut->on_status_transition( 'approved', 'unapproved', $review );
		$this->assertSame( 500, Ledger::balance( 42 ) );
		$this->assertSame( array( 1 ), $this->wpdb->comment_meta[901]['verified'] );
	}

	/**
	 * @testdox Un pedido ajeno, impago o devuelto no verifica la compra ($case).
	 * @dataProvider invalid_orders
	 * @param string $case Variante inválida.
	 */
	public function test_invalid_order_cannot_verify( string $case ): void {
		$order = $this->paid_order();
		$email = 'cliente@example.com';
		$user_id = 42;
		$product_id = 777;
		switch ( $case ) {
			case 'unpaid':
				$order->date_paid = null;
				break;
			case 'foreign_account':
				$user_id = 99;
				break;
			case 'foreign_email':
				$email = 'otro@example.com';
				break;
			case 'foreign_product':
				$product_id = 999;
				break;
			case 'refunded_line':
				$order->refunded_quantities[0] = -1.0;
				break;
			case 'refunded_amount':
				$order->refunds = array( new \WC_Order_Refund( array(), 10000.0 ) );
				break;
			default:
				$order->status = $case;
		}
		$this->assertFalse( VerifiedPurchase::order_confirms( $order, $product_id, $email, $user_id ), $case );
	}

	/** @return array<string,array{0:string}> Casos que no son una compra vigente. */
	public static function invalid_orders(): array {
		$out = array();
		foreach ( array( 'unpaid', 'foreign_account', 'foreign_email', 'foreign_product', 'refunded_line', 'refunded_amount', 'pending', 'on-hold', 'cancelled', 'failed', 'refunded' ) as $case ) {
			$out[ $case ] = array( $case );
		}
		return $out;
	}

	/** @testdox Un invitado verifica por el token y su cuenta posterior debe conservar ese correo. */
	public function test_guest_and_later_account_must_match_email(): void {
		$order = $this->paid_order();
		$order->customer_id = 0;
		$this->assertTrue( VerifiedPurchase::order_confirms( $order, 777, 'CLIENTE@example.com', 0 ) );
		$user = new \WP_User( 42 );
		$user->user_email = 'cliente@example.com';
		Functions\when( 'get_user_by' )->justReturn( $user );
		$this->assertTrue( VerifiedPurchase::order_confirms( $order, 777, 'cliente@example.com', 42 ) );
		$user->user_email = 'otro@example.com';
		$this->assertFalse( VerifiedPurchase::order_confirms( $order, 777, 'cliente@example.com', 42 ) );
	}

	/** @testdox Una devolución parcial conserva la compra sólo si queda una unidad del tomo. */
	public function test_partially_refunded_quantity_can_still_qualify(): void {
		$order = $this->paid_order();
		$order->items['line_item'][0]->quantity = 2;
		$order->refunded_quantities[0] = -1.0;
		$this->assertTrue( VerifiedPurchase::order_confirms( $order, 777, 'cliente@example.com', 42 ) );
	}

	/** @testdox El sello antiguo de verificación no permite cobrar por un pedido anulado. */
	public function test_stale_verified_badge_does_not_override_cancelled_order(): void {
		$order = $this->paid_order();
		$order->status = 'cancelled';
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$review = new \WP_Comment( array( 'comment_ID' => 901, 'comment_post_ID' => 777, 'user_id' => 42, 'comment_author_email' => 'cliente@example.com' ) );
		$this->wpdb->comment_meta[901] = array( 'verified' => array( 1 ), ReviewForm::META_ORDER => array( 700 ) );
		$gate = new \ReflectionMethod( ReviewPoints::class, 'verified_purchase' );
		$gate->setAccessible( true );
		$this->assertFalse( $gate->invoke( null, $review, 42, 777 ) );
		$this->assertSame( array( 0 ), $this->wpdb->comment_meta[901]['verified'] );
	}
	/** @testdox Los estados propios necesitan pago real y una ruta actual o almacenada. */
	public function test_custom_status_requires_paid_authorized_route(): void {
		$order = $this->paid_order();
		$order->status = 'courier-dispatched';
		$this->assertFalse( VerifiedPurchase::order_confirms( $order, 777, 'cliente@example.com', 42 ) );
		Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => 'akb_rewards_invite_status' === $key ? 'courier-dispatched' : $default );
		$this->assertTrue( VerifiedPurchase::order_confirms( $order, 777, 'cliente@example.com', 42 ) );
		$order->date_paid = null;
		$this->assertFalse( VerifiedPurchase::order_confirms( $order, 777, 'cliente@example.com', 42 ) );
		$order->date_paid = new \WC_DateTime( '2026-10-01' );
		Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => 'akb_rewards_invite_pickup_status' === $key ? 'courier-dispatched' : $default );
		$this->assertTrue( VerifiedPurchase::order_confirms( $order, 777, 'cliente@example.com', 42 ) );
		Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => $default );
		$order->update_meta_data( \Akibara\Rewards\Reviews\Invitations::META_TRIGGER, 'courier-dispatched' );
		$this->assertTrue( VerifiedPurchase::order_confirms( $order, 777, 'cliente@example.com', 42 ) );
		foreach ( array( 'on-hold', 'pending', 'cancelled', 'refunded' ) as $blocked ) {
			$order->status = $blocked;
			$order->update_meta_data( \Akibara\Rewards\Reviews\Invitations::META_TRIGGER, $blocked );
			$this->assertFalse( VerifiedPurchase::order_confirms( $order, 777, 'cliente@example.com', 42 ) );
		}
	}

}
