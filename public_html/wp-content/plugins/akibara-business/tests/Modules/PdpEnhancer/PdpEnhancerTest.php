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

	public function test_render_edition_summary_renders_country_format_and_dimensions_without_reading_direction(): void {
		$GLOBALS['product'] = new EditionSummaryProductStub(
			'Chainsaw Man 01',
			array(
				'pa_pais'    => 'España',
				'pa_formato' => 'Tankoubon (B6)',
			),
			true,
			array( 'length' => '18', 'width' => '13', 'height' => '1.5' )
		);
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wc_format_dimensions' )->justReturn( '18 × 13 × 1.5 cm' );

		ob_start();
		( new PdpEnhancer() )->render_edition_summary();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'class="akb-pdp-edition-summary"', $html );
		self::assertStringContainsString( '<div class="akb-edition-field"><dt>País de edición</dt><dd>España</dd></div>', $html );
		self::assertStringContainsString( '<div class="akb-edition-field"><dt>Formato</dt><dd>Tankoubon (B6)</dd></div>', $html );
		self::assertStringContainsString( '<div class="akb-edition-field"><dt>Dimensiones</dt><dd>18 × 13 × 1.5 cm</dd></div>', $html );
		self::assertStringNotContainsString( 'Lectura', $html );
		self::assertStringNotContainsString( 'Sentido oriental', $html );
		self::assertStringNotContainsString( 'Sentido occidental', $html );
		self::assertStringNotContainsString( 'akb-edition-field--reading', $html );
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

final class EditionSummaryProductStub extends \WC_Product {

	private string $name;
	/** @var array<string, string> */
	private array $attr_values;
	private bool $has_dims;
	/** @var array<string, string> */
	private array $dims;

	/**
	 * @param string                $name        Product name.
	 * @param array<string, string> $attr_values Attribute key => string value.
	 * @param bool                  $has_dims    Whether product has dimensions.
	 * @param array<string, string> $dims        Dimensions array.
	 */
	public function __construct( string $name, array $attr_values, bool $has_dims = false, array $dims = array() ) {
		parent::__construct();
		$this->name        = $name;
		$this->attr_values = $attr_values;
		$this->has_dims    = $has_dims;
		$this->dims        = $dims;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_attributes(): array {
		return array();
	}

	public function get_attribute( $attr ): string {
		return $this->attr_values[ (string) $attr ] ?? '';
	}

	public function has_dimensions(): bool {
		return $this->has_dims;
	}

	public function get_dimensions( $formatted = true ) {
		return $this->dims;
	}
}
