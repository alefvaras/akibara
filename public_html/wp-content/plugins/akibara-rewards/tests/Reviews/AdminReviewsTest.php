<?php
/**
 * Contrato del motor de reseñas del admin.
 *
 * Decisión del dueño: el admin crea reseñas con FOTO OBLIGATORIA. El motor
 * clona el commentdata de CusRev (type 'review', meta rating en commentdata,
 * comment_post a mano para que WooCommerce escriba 'verified') y dispara el
 * ÚNICO recálculo de ratings de WooCommerce (wp_update_comment_count). A
 * diferencia del tercero, el rating SÍ se valida 1..5.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\AdminReviews;
use Akibara\Rewards\Reviews\Media;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.2.0
 */
final class AdminReviewsTest extends RewardsTestCase {

	/** @var array<string,mixed>|null Commentdata capturado. */
	private ?array $inserted = null;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'wp_attachment_is' )->alias(
			static fn( string $type, int $id ): bool => 'image' === $type && $id > 0 && 555 !== $id
		);
		Functions\when( 'wp_update_post' )->justReturn( 1 );
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'wp_update_comment_count' )->justReturn( true );

		$inserted = &$this->inserted;
		$wpdb     = $this->wpdb;
		Functions\when( 'wp_insert_comment' )->alias(
			static function ( array $commentdata ) use ( &$inserted, $wpdb ): int {
				$inserted = $commentdata;
				// Fiel al core: comment_meta se persiste DENTRO del insert
				// (antes de que dispare su action) — de eso depende que la
				// marca de autoría exista cuando ReviewPoints escuche.
				foreach ( (array) ( $commentdata['comment_meta'] ?? array() ) as $k => $v ) {
					$wpdb->comment_meta[33][ $k ][] = $v;
				}
				return 33;
			}
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function valid_args(): array {
		return array(
			'product_id'    => 777,
			'author'        => 'Akibara',
			'rating'        => 5,
			'title'         => 'Edición preciosa',
			'content'       => 'Papel y traducción impecables.',
			'attachment_id' => 901,
			'verified'      => false,
		);
	}

	public function test_the_commentdata_clones_the_third_party_shape(): void {
		$recounted = array();
		Functions\when( 'wp_update_comment_count' )->alias(
			static function ( int $post_id ) use ( &$recounted ): bool {
				$recounted[] = $post_id;
				return true;
			}
		);
		\Brain\Monkey\Actions\expectDone( 'comment_post' )->once();

		$id = AdminReviews::create_review( $this->valid_args() );

		$this->assertSame( array( 777 ), $recounted, 'El ÚNICO disparador del recálculo de ratings de Woo debe correr.' );

		$this->assertSame( 33, $id );
		$this->assertSame( 'review', $this->inserted['comment_type'] );
		$this->assertSame( 777, $this->inserted['comment_post_ID'] );
		$this->assertSame( 1, $this->inserted['comment_approved'] );
		$this->assertSame(
			array(
				'rating'                          => 5,
				AdminReviews::META_ADMIN_CREATED  => 1,
			),
			$this->inserted['comment_meta'],
			'Rating INT como CusRev + la marca de autoría DENTRO del commentdata: existe cuando wp_insert_comment dispara a ReviewPoints.'
		);

		$meta = $this->wpdb->comment_meta[33];
		$this->assertSame( array( 0 ), $meta['verified'], 'Sin marcar, la reseña del admin NO es compra verificada.' );
		$this->assertSame( array( 'Edición preciosa' ), $meta['cr_rev_title'] );
		$this->assertSame( array( 1 ), $meta[ AdminReviews::META_ADMIN_CREATED ] );
		$this->assertSame( array( 901 ), $meta[ Media::META_LOCAL_IMAGE ] );
	}

	public function test_the_photo_is_mandatory(): void {
		$args = $this->valid_args();

		$args['attachment_id'] = 0;
		$result                = AdminReviews::create_review( $args );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'akb_rw_photo_required', $result->code );

		// Un adjunto que no es imagen tampoco vale.
		$args['attachment_id'] = 555;
		$result                = AdminReviews::create_review( $args );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNull( $this->inserted, 'Sin foto no se inserta NADA.' );
	}

	public function test_the_rating_is_validated_unlike_the_third_party(): void {
		foreach ( array( 0, 6, -3 ) as $bad ) {
			$args           = $this->valid_args();
			$args['rating'] = $bad;
			$result         = AdminReviews::create_review( $args );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'akb_rw_bad_rating', $result->code );
		}
	}

	public function test_product_author_and_content_are_required(): void {
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		$result = AdminReviews::create_review( $this->valid_args() );
		$this->assertSame( 'akb_rw_bad_product', $result->code );

		Functions\when( 'get_post_type' )->justReturn( 'product' );

		$args           = $this->valid_args();
		$args['author'] = '  ';
		$this->assertSame( 'akb_rw_bad_author', AdminReviews::create_review( $args )->code );

		$args            = $this->valid_args();
		$args['content'] = '';
		$this->assertSame( 'akb_rw_bad_content', AdminReviews::create_review( $args )->code );
	}

	public function test_verified_is_computed_against_real_orders_never_a_checkbox(): void {
		// RX+web 2026-08-31: el override manual viola FTC/UE/CONAR. Con el
		// email de un cliente que SÍ compró, la insignia se enciende sola;
		// sin email (o sin compra), queda 0 explícito (backfill Woo 11.2).
		$cliente             = new \WP_User( 42 );
		$cliente->user_email = 'compradora@example.com';
		Functions\when( 'get_user_by' )->alias(
			static fn( string $by, $v ) => 'compradora@example.com' === $v ? $cliente : false
		);
		Functions\when( 'wc_customer_bought_product' )->alias(
			static fn( string $email, int $user_id, int $product ): bool => 42 === $user_id && 777 === $product
		);

		$args          = $this->valid_args();
		$args['email'] = 'compradora@example.com';
		AdminReviews::create_review( $args );

		$this->assertSame( array( 1 ), $this->wpdb->comment_meta[33]['verified'], 'Compró de verdad: insignia computada.' );
	}

	public function test_a_source_note_records_consent_and_provenance(): void {
		$args           = $this->valid_args();
		$args['source'] = 'Instagram DM 30-08, autorizó publicarla';
		AdminReviews::create_review( $args );

		$this->assertSame(
			array( 'Instagram DM 30-08, autorizó publicarla' ),
			$this->wpdb->comment_meta[33]['_akb_rewards_source_note']
		);
	}

	public function test_the_public_transcription_badge_marks_admin_reviews_only(): void {
		$this->wpdb->comment_meta[33][ AdminReviews::META_ADMIN_CREATED ] = array( 1 );
		$transcrita = new \WP_Comment( array( 'comment_ID' => 33 ) );
		$organica   = new \WP_Comment( array( 'comment_ID' => 44 ) );

		$admin = new AdminReviews();
		$this->assertStringContainsString( 'publicada por Akibara', $admin->transcription_badge( 'Texto.', $transcrita ) );
		$this->assertSame( 'Texto.', $admin->transcription_badge( 'Texto.', $organica ), 'La orgánica queda intacta.' );
	}

	/**
	 * @testdox Tras crear una reseña, el aviso lleva a Productos › Reseñas, no a una lista vacía.
	 */
	public function test_the_created_notice_links_where_reviews_are_actually_listed(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test/wp-admin/' . $path );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );

		$_GET['akb_rw_created'] = '33';
		ob_start();
		try {
			( new AdminReviews() )->render_page();
		} finally {
			$html = (string) ob_get_clean();
			unset( $_GET['akb_rw_created'] );
		}

		// WooCommerce excluye las reseñas de Comentarios: ese enlace dejaba al
		// dueño en una lista vacía justo después de crear una (Playwright).
		$this->assertStringContainsString( 'edit.php?post_type=product&page=product-reviews', $html );
		$this->assertStringNotContainsString( 'edit-comments.php?comment_type=review', $html );
	}
}
