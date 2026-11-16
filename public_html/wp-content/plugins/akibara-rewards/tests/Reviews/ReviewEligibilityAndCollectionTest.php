<?php
/**
 * Pruebas unitarias de ReviewEligibility y CollectionReviewAjax.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\ReviewEligibility;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * Cobertura de elegibilidad unificada, lote cero-N+1 para Mi Colección y envío AJAX.
 */
final class ReviewEligibilityAndCollectionTest extends RewardsTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_permalink' )->alias( static fn( int $id ): string => 'https://akibara.test/producto/' . $id . '/' );
		Functions\when( 'get_userdata' )->alias(
			static function ( int $uid ) {
				if ( $uid <= 0 ) {
					return false;
				}
				$u               = new \WP_User( $uid );
				$u->display_name = 'Camila';
				$u->user_email   = 'cliente@example.com';
				return $u;
			}
		);
	}

	/** @testdox El cálculo por lote para Mi Colección distingue compra verificada de marcado manual sin N+1. */
	public function test_pending_for_collection_distinguishes_order_vs_manual_and_reviewed(): void {
		$this->wpdb->users[42]                      = array( 'user_email' => 'cliente@example.com' );
		$this->wpdb->col_answers['comment_post_ID'] = array( '777' );

		$state = ReviewEligibility::pending_for_collection(
			42,
			array( 777, 778, 779 ), // Todos los poseídos (compras + manuales).
			array( 779 )            // Marcado manual → NUNCA elegible para puntos.
		);

		self::assertArrayHasKey( 777, $state['reviewed_ids'] );
		self::assertArrayNotHasKey( 777, $state['pending_ids'] );

		self::assertArrayHasKey( 778, $state['pending_ids'] );
		self::assertArrayNotHasKey( 778, $state['reviewed_ids'] );

		self::assertArrayNotHasKey( 779, $state['pending_ids'], 'Un tomo marcado manualmente jamás califica como compra verificada pendiente.' );
		self::assertSame( 1, $state['pending_count'] );
		self::assertSame( 500, $state['points_per_review'] );
	}

	/** @testdox El resumen para el banner de Puntos Akibara cuenta sólo compras verificadas pendientes y respeta el tope por cliente. */
	public function test_summary_for_user_counts_pending_verified_purchases_and_respects_cap(): void {
		$this->wpdb->users[42] = array( 'user_email' => 'cliente@example.com' );
		$order                 = $this->make_order( 100, 42, 20000, 3800 );
		$order->status         = 'completed';
		$order->billing_email  = 'cliente@example.com';
		$order->items['line_item'] = array(
			new \WC_Order_Item_Fake( 777, 'Tomo 1' ),
			new \WC_Order_Item_Fake( 778, 'Tomo 2' ),
			new \WC_Order_Item_Fake( 779, 'Tomo 3' ),
		);
		Functions\when( 'wc_get_orders' )->justReturn( array( $order ) );
		$this->wpdb->col_answers['comment_post_ID'] = array( '777' );

		$summary = ReviewEligibility::summary_for_user( 42 );
		self::assertSame( 2, $summary['pending_count'] );
		self::assertSame( 2, $summary['rewardable_count'] );
		self::assertSame( 500, $summary['points_per_review'] );
		self::assertSame( 1000, $summary['total_potential_points'] );
	}

	/** La colección usa el formulario con token, la búsqueda administrativa exige permisos. */
	public function test_admin_search_rejects_customer_before_database_reads(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_send_json_error' )->alias( static function ( array $data, int $code ): void {
			self::assertSame( 403, $code );
			throw new \RuntimeException( 'permission-denied' );
		} );
		$this->expectExceptionMessage( 'permission-denied' );
		( new \Akibara\Rewards\Reviews\AdminProductSearch() )->handle_product_search();
	}
}
