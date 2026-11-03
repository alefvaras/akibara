<?php
/** Recuperación privada de invitaciones desde Mis pedidos. */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\AccountReviewLink;
use Akibara\Rewards\Reviews\Invitations;
use Akibara\Rewards\Reviews\ReviewForm;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class AccountReviewLinkTest extends RewardsTestCase {

	private AccountReviewLink $sut;

	private $database;

	protected function setUp(): void {
		parent::setUp();
		$this->sut = new AccountReviewLink();
		$this->database = new class() extends \FakeWpdb {
			public ?array $row = null;
			public array $reads = array();
			public function get_row( string $sql, string $mode = 'OBJECT' ) {
				$this->reads[] = $sql;
				return $this->row;
			}
		};
		$GLOBALS['wpdb'] = $this->database;
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\expect( 'wp_mail' )->never();
		Functions\expect( 'wp_generate_password' )->never();
		$this->database->row = array(
			'order_id' => 100,
			'token' => str_repeat( 'Tk', 20 ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			'used_at' => null,
		);
	}

	private function own_order(): \WC_Order {
		$order = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Invitations::META_SENT ] = '2026-10-17 12:00:00';
		return $order;
	}

	/** @testdox El cliente recupera el token existente mediante una lectura sin emitir correo ni enlaces nuevos. */
	public function test_owned_sent_valid_invitation_is_recoverable_read_only(): void {
		$before = $this->database->row;
		$actions = $this->sut->order_actions( array( 'view' => array( 'url' => '/pedido' ) ), $this->own_order() );
		$this->assertSame( ReviewForm::url_for( str_repeat( 'Tk', 20 ) ), $actions['akb_review_invitation']['url'] );
		$this->assertSame( 'Reseñar y obtener puntos', $actions['akb_review_invitation']['name'] );
		$this->assertArrayHasKey( 'view', $actions );
		$this->assertCount( 1, $this->database->reads );
		$this->assertStringContainsString( 'WHERE order_id = 100 AND used_at IS NULL', $this->database->reads[0] );
		$this->assertSame( $before, $this->database->row );
		$this->assertSame( array(), $this->database->inserts );
		$this->assertSame( array(), $this->database->updates );
		$this->assertSame( array(), $this->database->queries );
	}

	/** @testdox Un visitante, otro titular y los pedidos de invitado no revelan ni consultan tokens. */
	public function test_wrong_owner_and_guest_are_rejected_before_lookup(): void {
		$order = $this->own_order();
		foreach ( array( 8, 0 ) as $owner ) {
			$order->customer_id = $owner;
			$this->assertSame( '', $this->sut->url_for_order( $order ) );
		}
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->assertSame( '', $this->sut->url_for_order( $this->own_order() ) );
		$this->assertSame( array(), $this->database->reads );
	}

	/** @testdox Un pedido sin invitación enviada no consulta ni crea tokens. */
	public function test_unsent_invitation_has_no_recovery_link(): void {
		$order = $this->own_order();
		unset( $order->meta[ Invitations::META_SENT ] );
		$this->assertSame( '', $this->sut->url_for_order( $order ) );
		$this->assertSame( array(), $this->database->reads );
	}

	/** @testdox Los tokens usados, caducados, de otro pedido o inexistentes no se exponen. */
	public function test_invalid_token_rows_are_hidden(): void {
		$valid = $this->database->row;
		foreach ( array(
			null,
			array_merge( $valid, array( 'used_at' => '2026-10-17 12:00:00' ) ),
			array_merge( $valid, array( 'expires_at' => '2000-01-01 00:00:00' ) ),
			array_merge( $valid, array( 'order_id' => 101 ) ),
			array_merge( $valid, array( 'token' => '<malformed>' ) ),
		) as $row ) {
			$this->database->row = $row;
			$this->assertSame( '', $this->sut->url_for_order( $this->own_order() ) );
		}
	}

	/** @testdox Con el carril apagado el acceso ofrece la reseña voluntaria vigente. */
	public function test_disabled_lane_has_voluntary_label(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => 'akb_rewards_reviews_enabled' === $key ? 'no' : $default
		);
		$actions = $this->sut->order_actions( array(), $this->own_order() );
		$this->assertSame( 'Escribir mi reseña', $actions['akb_review_invitation']['name'] );
	}

	/** @testdox El detalle del pedido muestra exclusivamente el enlace de su titular. */
	public function test_view_order_uses_the_same_owner_gate(): void {
		$order = $this->own_order();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		ob_start();
		$this->sut->view_order( 100 );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Reseñar y obtener puntos', $html );
		$this->assertStringContainsString( str_repeat( 'Tk', 20 ), $html );
		$order->customer_id = 8;
		ob_start();
		$this->sut->view_order( 100 );
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
