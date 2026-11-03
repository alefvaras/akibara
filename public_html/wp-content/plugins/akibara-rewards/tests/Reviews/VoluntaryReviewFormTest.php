<?php
/** @package Akibara\Rewards\Tests */
declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\Media;
use Akibara\Rewards\Reviews\Moderation;
use Akibara\Rewards\Reviews\ReviewForm;
use Akibara\Rewards\Reviews\ReviewPoints;
use Akibara\Rewards\Reviews\VoluntaryReviewForm;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class VoluntaryReviewFormTest extends RewardsTestCase {

	/** The System Under Test. */
	private VoluntaryReviewForm $sut;
	private \WC_Order $order;
	private array $deleted = array();
	private ?string $previous_uri = null;

	protected function setUp(): void {
		parent::setUp();
		$this->sut = new VoluntaryReviewForm();
		$this->previous_uri = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/wp-comments-post.php';
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component ) => parse_url( $url, $component ) );
		$this->wpdb->users[42] = array( 'user_email' => 'cliente@example.com' );
		$this->order = $this->make_order( 100, 42, 10000, 1900 );
		$this->order->status = 'completed';
		$this->order->billing_email = 'cliente@example.com';
		$this->order->items['line_item'] = array( new \WC_Order_Item_Fake( 777, 'Tomo comprado' ) );
		Functions\when( 'wc_get_orders' )->alias( fn() => array( $this->order ) );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_the_ID' )->justReturn( 777 );
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'wp_nonce_field' )->justReturn( '<input name="_akb_vr_nonce" value="test">' );
		Functions\when( 'wp_get_image_mime' )->justReturn( 'image/jpeg' );
		Functions\when( 'media_handle_upload' )->justReturn( 8801 );
		Functions\when( 'wp_attachment_is' )->justReturn( true );
		Functions\when( 'wp_update_post' )->justReturn( 1 );
		Functions\when( 'wp_delete_attachment' )->alias( function( int $id, bool $force ): bool { $this->deleted[] = array( $id, $force ); return true; } );
		Functions\when( 'wp_die' )->alias( static function( string $message ): void { throw new \RuntimeException( $message ); } );
		$_POST = array( '_akb_vr_nonce' => 'test', 'akb_rf_points_requested' => '1' );
		$_FILES = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		$_FILES = array();
		if ( null === $this->previous_uri ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $this->previous_uri; }
		parent::tearDown();
	}

	private function data(): array {
		return array( 'comment_post_ID' => 777, 'user_id' => 42, 'comment_content' => 'Una edición excelente para mi colección.', 'comment_meta' => array( ReviewPoints::META_POINTS_REQUESTED => 1, 'rating' => 5 ) );
	}

	private function photo(): array {
		return array( 'name' => 'tomo.jpg', 'tmp_name' => '/tmp/tomo.jpg', 'size' => 100, 'error' => UPLOAD_ERR_OK );
	}

	/** @testdox La ficha ofrece foto opcional y envía un no explícito sin casillas de premio. */
	public function test_native_form_fields_are_optional_and_explicit(): void {
		$args = $this->sut->handle_form_args( array( 'comment_field' => '<textarea name="comment"></textarea>' ) );
		self::assertStringContainsString( '<textarea name="comment"></textarea>', $args['comment_field'] );
		self::assertStringContainsString( 'name="akb_rf_points_requested" value="0"', $args['comment_field'] );
		self::assertStringContainsString( 'name="akb_vr_photo"', $args['comment_field'] );
		self::assertStringContainsString( '_akb_vr_nonce', $args['comment_field'] );
		self::assertStringNotContainsString( 'required', $args['comment_field'] );
	}

	/** @testdox El formulario real de Core recibe multipart y conserva su destino sin JavaScript. */
	public function test_native_form_has_real_multipart_encoding(): void {
		ob_start();
		$this->sut->handle_form_before();
		echo '<div id="respond"><form action="/wp-comments-post.php" method="post" id="commentform"><textarea name="comment"></textarea></form></div>';
		$this->sut->handle_form_after();
		$html = ob_get_clean();
		self::assertStringContainsString( 'enctype="multipart/form-data"', $html );
		self::assertStringContainsString( 'action="/wp-comments-post.php"', $html );
		self::assertStringNotContainsString( '<script', $html );
	}

	/** @testdox Una reseña sin foto guarda compra verificada, pedido y no premio antes de insertar. */
	public function test_text_only_review_cannot_request_points(): void {
		Functions\expect( 'media_handle_upload' )->never();
		$data = $this->sut->handle_preprocess_comment( $this->data() );
		self::assertSame( 0, $data['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ] );
		self::assertSame( 100, $data['comment_meta'][ ReviewForm::META_ORDER ] );
		self::assertSame( 1, $data['comment_meta']['verified'] );
		self::assertSame( 0, $this->sut->handle_pre_comment_approved( 1, $data ) );
		self::assertSame( 'spam', $this->sut->handle_pre_comment_approved( 'spam', $data ) );
	}

	/** @testdox Una foto voluntaria conserva el no y se adjunta antes de premiar, sin residuo al cerrar. */
	public function test_photo_review_is_still_voluntary_and_attaches(): void {
		$_FILES['akb_vr_photo'] = $this->photo();
		$data = $this->sut->handle_preprocess_comment( $this->data() );
		self::assertSame( 0, $data['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ] );
		$this->wpdb->comment_meta[901][ ReviewPoints::META_POINTS_REQUESTED ] = array( 0 );
		$this->sut->handle_inserted_comment( 901, new \WP_Comment( array( 'comment_ID' => 901, 'comment_post_ID' => 777 ) ) );
		self::assertSame( array( 8801 ), $this->wpdb->comment_meta[901][ Media::META_LOCAL_IMAGE ] );
		$this->sut->discard_orphan_photo();
		self::assertSame( array(), $this->deleted );
	}

	/** @testdox Un rechazo posterior de Core por duplicado, flood o INSERT limpia sólo la foto huérfana. */
	public function test_shutdown_discards_upload_when_core_does_not_insert(): void {
		$_FILES['akb_vr_photo'] = $this->photo();
		$this->sut->handle_preprocess_comment( $this->data() );
		$this->sut->discard_orphan_photo();
		$this->sut->discard_orphan_photo();
		self::assertSame( array( array( 8801, true ) ), $this->deleted );
	}

	/** @testdox La sesión caducada no permite subir ni insertar una reseña. */
	public function test_bad_nonce_rejects_before_upload(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\expect( 'media_handle_upload' )->never();
		$this->expectExceptionMessage( 'sesión del formulario caducó' );
		$this->sut->handle_preprocess_comment( $this->data() );
	}

	/** @testdox Una cuenta ajena o una compra devuelta no permite subir ni insertar. */
	public function test_refunded_purchase_is_rejected(): void {
		$this->order->refunded_quantities[0] = -1;
		Functions\expect( 'media_handle_upload' )->never();
		$this->expectExceptionMessage( 'no hayas devuelto' );
		$this->sut->handle_preprocess_comment( $this->data() );
	}

	/** @testdox Comprar como invitado permite reseñar desde la cuenta del mismo correo. */
	public function test_guest_purchase_is_verified_for_matching_account(): void {
		$this->order->customer_id = 0;
		$data = $this->sut->handle_preprocess_comment( $this->data() );
		self::assertSame( 1, $data['comment_meta']['verified'] );
		self::assertSame( 0, $data['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ] );
	}

	/** @testdox Una foto falsa, excesiva o fallida rechaza la reseña completa antes del INSERT. */
	public function test_invalid_mime_size_and_upload_error_fail_closed(): void {
		Functions\expect( 'media_handle_upload' )->never();
		foreach ( array( 'mime', 'size', 'error', 'malformed' ) as $case ) {
			Functions\when( 'wp_get_image_mime' )->justReturn( 'mime' === $case ? 'text/plain' : 'image/jpeg' );
			$file = $this->photo();
			if ( 'size' === $case ) { $file['size'] = 5242881; }
			if ( 'error' === $case ) { $file['error'] = UPLOAD_ERR_PARTIAL; }
			if ( 'malformed' === $case ) { $file['name'] = array( 'tomo.jpg' ); }
			$_FILES['akb_vr_photo'] = $file;
			try {
				$this->sut->handle_preprocess_comment( $this->data() );
				self::fail( 'La foto inválida debe impedir el envío: ' . $case );
			} catch ( \RuntimeException $error ) {
				self::assertStringContainsString( 'La foto no se pudo subir', $error->getMessage(), $case );
			}
		}
		self::assertSame( array(), $this->deleted );
	}

	/** @testdox Otros comentarios y respuestas conservan el flujo normal del núcleo. */
	public function test_other_comments_are_not_intercepted(): void {
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		self::assertSame( $this->data(), $this->sut->handle_preprocess_comment( $this->data() ) );
	}
	/** @testdox REST, importaciones y moderación del dueño no se confunden con el formulario nativo. */
	public function test_non_native_request_is_not_intercepted(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/products/reviews';
		Functions\expect( 'media_handle_upload' )->never();
		self::assertSame( $this->data(), $this->sut->handle_preprocess_comment( $this->data() ) );
	}

	/** @testdox Quitar los campos propios no permite a un invitado eludir el control nativo. */
	public function test_anonymous_native_request_is_rejected_even_without_custom_fields(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$_POST = array();
		Functions\expect( 'media_handle_upload' )->never();
		$this->expectExceptionMessage( 'Inicia sesión con la cuenta de tu compra' );
		$this->sut->handle_preprocess_comment( $this->data() );
	}

	/** @testdox ON retiene y OFF conserva el veredicto de Core para reseñas nativas con y sin foto, siempre sin premio. */
	public function test_native_moderation_toggle_controls_text_and_photo_reviews_without_reward(): void {
		$moderation = new Moderation();
		foreach ( array( 'yes', 'no' ) as $enabled ) {
			Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => 'akb_rewards_moderate_reviews' === $key ? $enabled : $default );
			foreach ( array( false, true ) as $photo ) {
				$_FILES = $photo ? array( 'akb_vr_photo' => $this->photo() ) : array();
				$sut = new VoluntaryReviewForm();
				$data = $sut->handle_preprocess_comment( $this->data() );
				$core_status = $moderation->hold_product_reviews( 1, $data );
				$status = $sut->handle_pre_comment_approved( $core_status, $data );
				self::assertSame( 'yes' === $enabled ? 0 : 1, $status, 'Interruptor ' . $enabled . ', foto ' . (int) $photo );
				self::assertSame( 0, $data['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ], 'La aprobación automática nunca inscribe en premios.' );
				$this->wpdb->comment_meta[901][ ReviewPoints::META_POINTS_REQUESTED ] = array( 0 );
				$comment = new \WP_Comment( array( 'comment_ID' => 901, 'comment_post_ID' => 777, 'comment_approved' => (string) $status ) );
				( new ReviewPoints() )->maybe_pay( $comment );
				self::assertSame( array(), $this->wpdb->inserts, 'Ni ON ni OFF acredita puntos a una reseña voluntaria.' );
				$sut->discard_orphan_photo();
			}
		}
	}

	/** @testdox Con moderación nativa apagada se conservan también espera, papelera, spam y errores de Core. */
	public function test_disabled_native_moderation_preserves_all_core_verdicts(): void {
		Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => 'akb_rewards_moderate_reviews' === $key ? 'no' : $default );
		$data = $this->sut->handle_preprocess_comment( $this->data() );
		foreach ( array( 0, 1, 'spam', 'trash', new \WP_Error( 'blocked', 'Core rechazó el comentario.' ) ) as $verdict ) {
			self::assertSame( $verdict, $this->sut->handle_pre_comment_approved( $verdict, $data ) );
		}
	}

}
