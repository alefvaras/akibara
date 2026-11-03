<?php
/**
 * Tests for PDP gallery accessibility enhancements.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\PdpEnhancer;

use Akibara\Business\Modules\PdpEnhancer\PdpEnhancer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
	require_once ABSPATH . 'wp-includes/class-wp-token-map.php';
	require_once ABSPATH . 'wp-includes/html-api/html5-named-character-references.php';
	require_once ABSPATH . 'wp-includes/html-api/class-wp-html-attribute-token.php';
	require_once ABSPATH . 'wp-includes/html-api/class-wp-html-span.php';
	require_once ABSPATH . 'wp-includes/html-api/class-wp-html-doctype-info.php';
	require_once ABSPATH . 'wp-includes/html-api/class-wp-html-text-replacement.php';
	require_once ABSPATH . 'wp-includes/html-api/class-wp-html-decoder.php';
	require_once ABSPATH . 'wp-includes/html-api/class-wp-html-tag-processor.php';
}

final class PdpEnhancerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $value ): string => strip_tags( $value )
		);
		Functions\when( 'wp_has_noncharacters' )->justReturn( false );
		Functions\when( 'wp_kses_uri_attributes' )->justReturn( array( 'href', 'src' ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['product'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_adds_localized_name_to_main_gallery_link_when_image_alt_is_empty(): void {
		$GLOBALS['product'] = new GalleryProductStub( 'Claymore <em>27</em> & Edición' );
		$html               = '<div class="woocommerce-product-gallery__image"><a href="/full.avif"><img class="wp-post-image" alt=""></a></div>';

		$result = ( new PdpEnhancer() )->add_main_gallery_link_accessible_name( $html, 24702 );

		self::assertStringContainsString(
			'aria-label="Ampliar imagen principal de Claymore 27 &amp; Edición"',
			$result
		);
		self::assertStringContainsString( 'alt=""', $result );
	}

	public function test_uses_generic_localized_name_without_product_context(): void {
		$html = '<div class="woocommerce-product-gallery__image"><a href="/full.avif"><img class="wp-post-image" alt=""></a></div>';

		$result = ( new PdpEnhancer() )->add_main_gallery_link_accessible_name( $html, 24702 );

		self::assertStringContainsString(
			'aria-label="Ampliar imagen principal del producto"',
			$result
		);
	}

	public function test_does_not_change_gallery_thumbnails(): void {
		$html = '<div class="woocommerce-product-gallery__image"><a href="/full.avif"><img class="attachment-woocommerce_thumbnail" alt=""></a></div>';

		$result = ( new PdpEnhancer() )->add_main_gallery_link_accessible_name( $html, 24703 );

		self::assertSame( $html, $result );
	}

	public function test_preserves_an_existing_accessible_name(): void {
		$html = '<div class="woocommerce-product-gallery__image"><a href="/full.avif" aria-label="Nombre provisto por otro plugin"><img class="wp-post-image" alt=""></a></div>';

		$result = ( new PdpEnhancer() )->add_main_gallery_link_accessible_name( $html, 24702 );

		self::assertSame( $html, $result );
	}

	/**
	 * WooCommerce le pasa a este filtro el STRING de get_image_id() — y es ''
	 * cuando el producto no tiene imagen destacada. Con la firma tipada a int,
	 * el string vacío no coerciona y la FICHA ENTERA caía en 500 (reproducido
	 * en local 2026-08-30; prod se salvaba solo porque todas las portadas
	 * existen). El filtro debe tolerar ambos: '' y '123'.
	 */
	public function test_survives_the_string_attachment_ids_woocommerce_actually_sends(): void {
		$placeholder = '<div class="woocommerce-product-gallery__image--placeholder"><img src="/ph.png" alt=""></div>';

		// Producto SIN imagen: id '' y markup sin <a> — pasa intacto, sin TypeError.
		self::assertSame(
			$placeholder,
			( new PdpEnhancer() )->add_main_gallery_link_accessible_name( $placeholder, '' )
		);

		// Y el id numérico como STRING (la forma real de get_image_id()).
		$html = '<div class="woocommerce-product-gallery__image"><a href="/full.avif"><img class="wp-post-image" alt=""></a></div>';
		$result = ( new PdpEnhancer() )->add_main_gallery_link_accessible_name( $html, '24703' );
		self::assertStringContainsString( 'aria-label', $result );
	}

	public function test_detect_publication_type_distinguishes_manga_and_comic(): void {
		Functions\when( 'get_the_terms' )->alias(
			static function ( int $product_id, string $tax ) {
				if ( 'product_cat' !== $tax ) {
					return false;
				}
				if ( 100 === $product_id ) {
					return array( (object) array( 'term_id' => 10, 'slug' => 'shonen' ) );
				}
				if ( 200 === $product_id ) {
					return array( (object) array( 'term_id' => 20, 'slug' => 'dc-comics' ) );
				}
				return array( (object) array( 'term_id' => 30, 'slug' => 'figuras' ) );
			}
		);

		Functions\when( 'get_ancestors' )->alias(
			static function ( int $term_id, string $tax ) {
				if ( 10 === $term_id ) {
					return array( 1 ); // ancestor manga
				}
				if ( 20 === $term_id ) {
					return array( 2 ); // ancestor comics
				}
				return array();
			}
		);

		Functions\when( 'get_term' )->alias(
			static function ( int $term_id, string $tax ) {
				if ( 1 === $term_id ) {
					return (object) array( 'term_id' => 1, 'slug' => 'manga' );
				}
				if ( 2 === $term_id ) {
					return (object) array( 'term_id' => 2, 'slug' => 'comics' );
				}
				return null;
			}
		);

		self::assertSame( 'manga', PdpEnhancer::detect_publication_type( 100 ) );
		self::assertSame( 'comic', PdpEnhancer::detect_publication_type( 200 ) );
		self::assertNull( PdpEnhancer::detect_publication_type( 300 ) );
	}

	public function test_render_anime_badge_prints_nothing_when_no_meta(): void {
		$GLOBALS['product'] = new GalleryProductStub( 'Producto Sin Anime' );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		ob_start();
		( new PdpEnhancer() )->render_anime_badge();
		$html = (string) ob_get_clean();

		self::assertSame( '', $html );
	}

	public function test_render_anime_badge_prints_callout_when_meta_exists(): void {
		$GLOBALS['product'] = new GalleryProductStub( 'Skip and Loafer 05' );
		Functions\when( 'get_post_meta' )->justReturn( 'Continúa tras la T1 emitida por P.A.WORKS (Capítulo 24+)' );

		ob_start();
		( new PdpEnhancer() )->render_anime_badge();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'akb-anime-badge', $html );
		self::assertStringContainsString( '¿Vienes del anime?', $html );
		self::assertStringContainsString( 'Continúa tras la T1 emitida por P.A.WORKS (Capítulo 24+)', $html );
	}
}

final class GalleryProductStub extends \WC_Product {

	private string $name;

	public function __construct( string $name ) {
		parent::__construct();
		$this->name = $name;
	}

	public function get_name(): string {
		return $this->name;
	}
}
