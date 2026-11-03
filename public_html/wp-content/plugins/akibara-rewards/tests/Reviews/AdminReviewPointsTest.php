<?php
/**
 * El administrador reintenta un premio sin retirar la reseña publicada.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Reviews\AdminReviews;
use Akibara\Rewards\Reviews\Media;
use Akibara\Rewards\Reviews\ReviewPoints;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class AdminReviewPointsTest extends RewardsTestCase {

	/** @var AdminReviews The System Under Test. */
	private AdminReviews $sut;
	private \WP_Comment $review;
	private bool $allowed = true;
	/** @var array<string,mixed> */
	private array $options = array();
	/** @var array<string,mixed> */
	private array $notices = array();

	protected function setUp(): void {
		parent::setUp();
		$this->sut = new AdminReviews();
		$this->review = new \WP_Comment( array( 'comment_ID' => 901, 'comment_post_ID' => 777, 'comment_type' => 'review', 'comment_approved' => '1', 'user_id' => 42, 'comment_author_email' => 'cliente@example.com' ) );
		$this->wpdb->comment_meta[901] = array( 'verified' => array( 1 ), Media::META_LOCAL_IMAGE => array( 9010 ) );
		Functions\when( 'current_user_can' )->alias( fn(): bool => $this->allowed );
		Functions\when( 'get_comment' )->alias( fn( int $id ) => 901 === $id ? $this->review : null );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'wp_attachment_is' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'get_option' )->alias( fn( string $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_nonce_url' )->alias( static fn( string $url, string $action ): string => $url . '&_wpnonce=' . $action );
		Functions\when( 'absint' )->alias( static fn( $n ): int => abs( (int) $n ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$admin = new \WP_User( 7 );
		$admin->user_login = 'qa-admin';
		Functions\when( 'wp_get_current_user' )->justReturn( $admin );
		Functions\when( 'wp_die' )->alias( static function (): void { throw new \RuntimeException( 'denied' ); } );
		Functions\when( 'set_transient' )->alias( function ( string $key, $value ): bool { $this->notices[ $key ] = $value; return true; } );
		Functions\when( 'get_transient' )->alias( fn( string $key ) => $this->notices[ $key ] ?? false );
		Functions\when( 'delete_transient' )->alias( function ( string $key ): bool { unset( $this->notices[ $key ] ); return true; } );
	}

	protected function tearDown(): void {
		unset( $_GET['comment_id'], $_GET['page'] );
		parent::tearDown();
	}

	/** @testdox La fila de Woo muestra Revisar puntos con nonce ligado a esa reseña. */
	public function test_row_action_has_review_bound_nonce(): void {
		$actions = $this->sut->points_row_action( array( 'edit' => 'editar' ), $this->review );
		$this->assertSame( 'editar', $actions['edit'] );
		$this->assertStringContainsString( 'Revisar puntos', $actions['akb_review_points'] );
		$this->assertStringContainsString( 'admin-post.php?action=' . AdminReviews::ACTION_RECHECK, $actions['akb_review_points'] );
		$this->assertStringContainsString( '_wpnonce=' . AdminReviews::ACTION_RECHECK . '_901', $actions['akb_review_points'] );
	}

	/** @testdox Revisar dos veces paga una sola vez, no modifica la publicación ni repite el aviso de premio. */
	public function test_recheck_is_idempotent_and_never_changes_publication(): void {
		\Brain\Monkey\Actions\expectDone( 'akb_rewards_review_points_paid' )->once()->with( $this->review, 42, 500 );
		\Brain\Monkey\Actions\expectDone( 'litespeed_purge_all' )->once();
		Functions\expect( 'wp_set_comment_status' )->never();
		$first = $this->sut->recheck_points( 901 );
		$second = $this->sut->recheck_points( 901 );
		$this->assertSame( 'success', $first['type'] );
		$this->assertStringContainsString( 'se acreditaron 500', $first['message'] );
		$this->assertSame( 'info', $second['type'] );
		$this->assertStringContainsString( 'no se acreditaron puntos nuevos', $second['message'] );
		$this->assertStringContainsString( '500 Puntos Akibara ya acreditados', $second['message'] );
		$this->assertSame( 500, Ledger::balance( 42 ) );
		$this->assertSame( '1', $this->review->comment_approved );
	}

	/**
	 * @testdox La acción rechaza reseñas no autorizadas o no elegibles ($case).
	 * @dataProvider rejected_cases
	 * @param string $case Restricción a ejercer.
	 */
	public function test_recheck_gates( string $case ): void {
		switch ( $case ) {
			case 'permission': $this->allowed = false; break;
			case 'lane_off': $this->options['akb_rewards_reviews_enabled'] = 'no'; break;
			case 'engine_off': \Brain\Monkey\Filters\expectApplied( 'akb_rewards_reviews_engine_on' )->andReturn( false ); break;
			case 'comment': $this->review->comment_type = 'comment'; break;
			case 'reply': $this->review->comment_parent = 1; break;
			case 'non_product': Functions\when( 'get_post_type' )->justReturn( 'post' ); break;
			default: $this->review->comment_approved = $case;
		}
		$this->assertSame( array(), $this->sut->points_row_action( array(), $this->review ) );
		$this->assertSame( 'error', $this->sut->recheck_points( 901 )['type'] );
		$this->assertSame( 0, Ledger::balance( 42 ) );
	}

	/** @return array<string,array{0:string}> Restricciones independientes. */
	public static function rejected_cases(): array {
		$out = array();
		foreach ( array( 'permission', 'lane_off', 'engine_off', 'comment', 'reply', 'non_product', '0', 'spam', 'trash' ) as $case ) {
			$out[ $case ] = array( $case );
		}
		return $out;
	}

	/** @testdox Una foto ausente o la cadencia no se saltan por usar la acción del administrador. */
	public function test_normal_reward_requirements_still_apply(): void {
		unset( $this->wpdb->comment_meta[901][ Media::META_LOCAL_IMAGE ] );
		$this->assertSame( 'info', $this->sut->recheck_points( 901 )['type'] );
		$this->assertSame( 0, Ledger::balance( 42 ) );
		$this->wpdb->comment_meta[901][ Media::META_LOCAL_IMAGE ] = array( 9010 );
		$this->options['akb_rewards_review_every_n'] = 3;
		$result = $this->sut->recheck_points( 901 );
		$this->assertStringContainsString( 'Avance: 1 de 3', $result['message'] );
		$this->assertSame( 0, Ledger::balance( 42 ) );
	}

	/** @testdox Una petición con nonce inválido no llega al motor de puntos. */
	public function test_bad_nonce_stops_handler(): void {
		$_GET['comment_id'] = '901';
		Functions\expect( 'check_admin_referer' )->once()->with( AdminReviews::ACTION_RECHECK . '_901' )->andThrow( new \RuntimeException( 'bad_nonce' ) );
		try { $this->sut->handle_recheck(); } catch ( \RuntimeException $e ) { $this->assertSame( 'bad_nonce', $e->getMessage() ); }
		$this->assertSame( 0, Ledger::balance( 42 ) );
		$this->assertSame( array(), $this->notices );
	}

	/** @testdox El handler vuelve a la tabla oficial y el aviso real se consume una sola vez. */
	public function test_handler_redirect_and_private_notice(): void {
		$_GET['comment_id'] = '901';
		Functions\expect( 'check_admin_referer' )->once()->with( AdminReviews::ACTION_RECHECK . '_901' )->andReturn( 1 );
		Functions\expect( 'wp_safe_redirect' )->once()->with( 'https://akibara.test/wp-admin/edit.php?post_type=product&page=product-reviews' )->andThrow( new \RuntimeException( 'redirect' ) );
		try { $this->sut->handle_recheck(); } catch ( \RuntimeException $e ) { $this->assertSame( 'redirect', $e->getMessage() ); }
		$_GET['page'] = 'product-reviews';
		ob_start(); $this->sut->recheck_notice(); $html = (string) ob_get_clean();
		$this->assertStringContainsString( 'se acreditaron 500', $html );
		ob_start(); $this->sut->recheck_notice(); $this->assertSame( '', ob_get_clean() );
	}

	/** @testdox El estado del premio sólo aparece al administrador y no acredita al leer. */
	public function test_badge_is_read_only_and_admin_only(): void {
		$this->assertStringContainsString( 'Sin premio acreditado', $this->sut->points_badge( 'Texto', $this->review ) );
		$this->assertSame( 0, Ledger::balance( 42 ) );
		$this->wpdb->comment_meta[901][ ReviewPoints::META_POINTS_PAID ] = array( ReviewPoints::SEAL_PENDING_USER );
		$this->assertStringContainsString( 'Premio pendiente', $this->sut->points_badge( 'Texto', $this->review ) );
		Functions\when( 'is_admin' )->justReturn( false );
		$this->assertSame( 'Texto', $this->sut->points_badge( 'Texto', $this->review ) );
	}
	/** @testdox Revisar puntos respeta el no explícito sin retirar la reseña. */
	public function test_recheck_respects_explicit_no_request(): void {
		$this->wpdb->comment_meta[901][ ReviewPoints::META_POINTS_REQUESTED ] = array( 0 );
		\Brain\Monkey\Actions\expectDone( 'akb_rewards_review_points_paid' )->never();
		$result = $this->sut->recheck_points( 901 );
		$this->assertSame( 'info', $result['type'] );
		$this->assertStringContainsString( 'no solicitó puntos', $result['message'] );
		$this->assertSame( 0, Ledger::balance( 42 ) );
		$this->assertSame( '1', $this->review->comment_approved );
	}

}
