<?php
/**
 * Review dashboard evidence, privacy and workflow tests.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\AdminOverview;
use Akibara\Rewards\Reviews\Invitations;
use Akibara\Rewards\Support\SettingsPage;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Scheduler function mocks must not leak into unrelated settings render tests.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AdminOverviewTest extends RewardsTestCase {

	/** The System Under Test. */
	private AdminOverview $sut;

	/** Configure isolated WordPress readers. */
	protected function setUp(): void {
		parent::setUp();
		$this->sut = new AdminOverview();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test/wp-admin/' . $path );
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'get_comments' )->justReturn( 0 );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $stamp, \DateTimeZone $zone ): string => ( new \DateTimeImmutable( '@' . $stamp ) )->setTimezone( $zone )->format( $format ) );
	}

	/** @testdox Unauthorized users cannot read order or review data. */
	public function test_requires_management_capability_before_reading_data(): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => false );
		Functions\expect( 'get_comments' )->never();
		Functions\expect( 'wc_get_orders' )->never();
		$this->assertSame( array(), $this->sut->snapshot() );
		$this->assertSame( '', $this->render() );
	}

	/** @testdox Counts exclude replies and non-product comments and keep moderation states separate. */
	public function test_counts_only_top_level_product_reviews(): void {
		$queries = array();
		Functions\when( 'get_comments' )->alias(
			static function ( array $args ) use ( &$queries ): int {
				$queries[] = $args;
				return 'hold' === $args['status'] ? 3 : 8;
			}
		);
		$data = $this->sut->snapshot();
		$this->assertSame( 3, $data['pending'] );
		$this->assertSame( 8, $data['approved'] );
		foreach ( $queries as $query ) {
			$this->assertSame( 'product', $query['post_type'] );
			$this->assertSame( array( 'review', 'comment' ), $query['type__in'] );
			$this->assertSame( 0, $query['parent'] );
			$this->assertTrue( $query['count'] );
		}
	}

	/** @testdox Queue queries stay bounded and use the actual earliest scheduler time. */
	public function test_queue_query_is_bounded_and_does_not_guess_the_send_date(): void {
		$timestamp = time() + 7200;
		$action = new class( $timestamp ) {
			private int $timestamp;
			public function __construct( int $timestamp ) { $this->timestamp = $timestamp; }
			public function get_schedule(): self { return $this; }
			public function get_date(): \DateTimeImmutable { return new \DateTimeImmutable( '@' . $this->timestamp ); }
		};
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $args ) use ( $action ): array {
				$this->assertSame( Invitations::HOOK, $args['hook'] );
				$this->assertSame( 'pending', $args['status'] );
				$this->assertSame( 11, $args['per_page'] );
				$this->assertSame( 'date', $args['orderby'] );
				$this->assertSame( 'ASC', $args['order'] );
				return array_fill( 0, 11, $action );
			}
		);
		$data = $this->sut->snapshot();
		$this->assertSame( $timestamp, $data['next'] );
		$this->assertStringContainsString( 'Más de 10 acciones pendientes.', $this->render() );
	}

	/** @testdox Order evidence uses WooCommerce CRUD, preserves real dates and omits recipient data. */
	public function test_order_reader_uses_crud_and_exposes_no_customer_or_token_data(): void {
		$order = new \WC_Order();
		$order->id = 321;
		$order->billing_email = 'private@example.test';
		$order->meta = array( Invitations::META_SCHEDULED => '2026-10-01 10:00:00', Invitations::META_SENT => '2026-10-03 10:00:00', Invitations::META_ATTEMPTS => 2, '_private_token' => 'never-expose-this-token' );
		Functions\when( 'wc_get_orders' )->alias(
			function ( array $args ) use ( $order ): array {
				$this->assertSame( array( 'type' => 'shop_order', 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC' ), $args );
				return array( $order );
			}
		);
		$html = $this->render();
		$this->assertStringContainsString( 'Enviada al transporte', $html );
		$this->assertStringContainsString( 'Alta en cola: 2026-10-01 10:00:00', $html );
		$this->assertStringContainsString( 'Enviada: 2026-10-03 10:00:00', $html );
		$this->assertStringContainsString( $order->get_edit_order_url(), $html );
		$this->assertStringNotContainsString( 'private@example.test', $html );
		$this->assertStringNotContainsString( 'never-expose-this-token', $html );
		$this->assertStringNotContainsString( 'Próximo envío:', $html );
		$this->assertStringContainsString( 'la recepción se confirma en la bandeja', $html );
		$this->assertSame( array(), $order->saved_meta, 'Opening the dashboard must not mutate the order.' );
	}

	/** @testdox A stale scheduling marker is not presented as an upcoming send. */
	public function test_missing_pending_action_is_visible_instead_of_inventing_a_date(): void {
		$order = new \WC_Order();
		$order->id = 99;
		$order->meta = array( Invitations::META_SCHEDULED => '2026-09-01 10:00:00' );
		Functions\when( 'wc_get_orders' )->justReturn( array( $order ) );
		$html = $this->render();
		$this->assertStringContainsString( 'Esperando confirmación del pago.', $html );
		$this->assertStringNotContainsString( 'Próximo envío:', $html );
		Functions\when( 'as_next_scheduled_action' )->justReturn( true );
		$this->assertStringContainsString( 'En ejecución', $this->render() );
	}

	/** @testdox Engine, moderation and reward switches change the workflow guidance. */
	public function test_guidance_reflects_disabled_engine_and_independent_switches(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => array( 'akb_rewards_invite_enabled' => 'no', 'akb_rewards_moderate_reviews' => 'no', 'akb_rewards_review_every_n' => 3, 'akb_rewards_review_max_per_user' => 5 )[ $key ] ?? $default
		);
		$html = $this->render();
		$this->assertStringContainsString( 'Invitaciones automáticas inactivas.', $html );
		$this->assertStringContainsString( 'Moderación obligatoria inactiva.', $html );
		$this->assertStringContainsString( '500 puntos cada 3 reseñas', $html );
		$this->assertStringContainsString( 'Hasta 5 premios vigentes', $html );
		$this->assertStringContainsString( 'La invitación por correo es para solicitar puntos y exige una foto real', $html );
		$this->assertStringContainsString( 'Las nuevas reseñas voluntarias de la ficha admiten foto opcional y no generan puntos, aunque lleven foto', $html );
		$this->assertStringContainsString( 'Las reseñas anteriores conservan su política vigente', $html );
		$this->assertStringNotContainsString( 'el cliente elige si solicita puntos', $html );
		Filters\expectApplied( 'akb_rewards_reviews_engine_on' )->andReturn( false );
		$html = $this->render();
		$this->assertStringContainsString( 'El motor propio de reseñas está inactivo.', $html );
		$this->assertStringContainsString( 'Los premios por reseña están inactivos.', $html );
		$this->assertStringNotContainsString( 'Transcribir una reseña recibida</a>', $html );
	}

	/** @testdox Operational navigation points to WooCommerce reviews and every settings destination exists. */
	public function test_review_links_and_settings_anchors_are_reachable(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'WC' )->justReturn( false );
		ob_start();
		( new SettingsPage() )->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'page=product-reviews&comment_status=moderated', $html );
		$this->assertStringContainsString( 'edit-comments.php?page=akb-rewards-new-review', $html );
		$this->assertStringContainsString( 'tab=action-scheduler&s=akb_rewards_send_review_invite', $html );
		foreach ( array( 'reviews', 'carousel', 'points' ) as $anchor ) {
			$this->assertStringContainsString( 'id="akb-rewards-section-' . $anchor . '"', $html );
		}
		$this->assertStringContainsString( 'Guardar ajustes conserva las fechas de la cola y no invita retroactivamente', $html );
	}

	/** Capture the overview markup. */
	private function render(): string {
		ob_start();
		try {
			$this->sut->render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}
}
