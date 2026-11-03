<?php
/**
 * Contrato ejecutable del título de la tarjeta y de la ficha.
 *
 * El eyebrow de editorial pedía la taxonomía con `wp_get_post_terms()`, que NO
 * consulta el term-cache: una consulta por tarjeta, 24 por página de catálogo,
 * para leer un nombre que la consulta principal del loop ya había cebado. Esta
 * prueba fija la fuente de datos, no el texto del archivo.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Term;

/**
 * @coversNothing
 */
final class TitleParserTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Defaults deterministas de los guards de contexto: Brain Monkey define el
		// símbolo la primera vez que un test lo mockea y el símbolo sobrevive al
		// tearDown; sin default, la siguiente llamada explota por «not mocked».
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_paged' )->justReturn( false );

		Functions\when( 'esc_html' )->alias(
			static function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		require_once dirname( __DIR__ ) . '/inc/title-parser.php';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['product'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Doble del producto: el código sólo le pide el id.
	 *
	 * @param int $id Id del producto.
	 * @return object
	 */
	private function producto( int $id ) {
		return new class( $id ) {
			/** @var int */
			private $id;

			/**
			 * @param int $id Id.
			 */
			public function __construct( int $id ) {
				$this->id = $id;
			}

			/**
			 * @return int
			 */
			public function get_id(): int {
				return $this->id;
			}
		};
	}

	/**
	 * @param string $taxonomy Taxonomía.
	 * @param string $name     Nombre del término.
	 * @return WP_Term
	 */
	private function termino( string $taxonomy, string $name ): WP_Term {
		return new WP_Term(
			array(
				'term_id'  => 3,
				'slug'     => sanitize_title_stub( $name ),
				'taxonomy' => $taxonomy,
				'name'     => $name,
			)
		);
	}

	/**
	 * @testdox La tarjeta lee la editorial del term-cache, nunca con wp_get_post_terms.
	 */
	public function test_card_reads_the_brand_from_the_term_cache(): void {
		$GLOBALS['product'] = $this->producto( 55 );
		Functions\when( 'get_the_title' )->justReturn( 'Berserk 12' );
		Functions\expect( 'wp_get_post_terms' )->never();
		Functions\expect( 'get_the_terms' )
			->once()
			->with( 55, 'product_brand' )
			->andReturn( array( $this->termino( 'product_brand', 'Panini España' ) ) );

		ob_start();
		akb_template_loop_product_title();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<span class="akb-editorial-eyebrow">Panini España</span>', $html );
		// Sin contexto de portada/ficha/serie, la rejilla cuelga del h1: h2.
		self::assertStringContainsString( '<h2 class="woocommerce-loop-product__title">', $html );
	}

	/**
	 * R12-listados-saltan-h1-h3 (2026-09-20): en /tienda/, /a-pedido/ y /manga/
	 * la rejilla cuelga del h1 sin sección intermedia (axe heading-order);
	 * bajo una sección con h2 (portada, ficha, landing de serie) sigue el h3.
	 *
	 * @testdox El nivel del título de la card es h2 en los archivos y h3 bajo una sección h2.
	 */
	public function test_el_nivel_del_titulo_depende_del_contexto(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		self::assertSame( 'h2', akb_loop_title_level(), 'archivo de catálogo: h2' );

		Functions\when( 'is_tax' )->alias( static fn( $tax = '' ): bool => 'pa_serie' === $tax );
		self::assertSame( 'h3', akb_loop_title_level(), 'landing de serie: h3 bajo su h2' );

		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( true );
		self::assertSame( 'h3', akb_loop_title_level(), 'ficha (relacionados): h3' );

		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		self::assertSame( 'h3', akb_loop_title_level(), 'portada: h3' );
	}

	/**
	 * @testdox La ficha usa la misma fuente que la tarjeta.
	 */
	public function test_single_reads_the_brand_from_the_term_cache(): void {
		$GLOBALS['product'] = $this->producto( 77 );
		Functions\when( 'get_the_title' )->justReturn( 'Nausicaä 4' );
		Functions\expect( 'wp_get_post_terms' )->never();
		Functions\expect( 'get_the_terms' )
			->once()
			->with( 77, 'product_brand' )
			->andReturn( array( $this->termino( 'product_brand', 'Planeta Cómic' ) ) );

		ob_start();
		akb_template_single_title();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'akb-editorial-eyebrow--single', $html );
		self::assertStringContainsString( 'Planeta Cómic', $html );
		self::assertStringContainsString( '<h1 class="product_title entry-title">', $html );
	}

	/**
	 * `get_the_terms()` devuelve un array con claves arbitrarias (no siempre 0),
	 * así que leer `[0]` podía dar aviso de índice indefinido.
	 *
	 * @testdox Un array de términos sin índice cero no rompe el eyebrow.
	 */
	public function test_terms_without_a_zero_index_still_render(): void {
		$GLOBALS['product'] = $this->producto( 88 );
		Functions\when( 'get_the_title' )->justReturn( 'Ranma 1/2 3' );
		Functions\when( 'get_the_terms' )->justReturn(
			array( 17 => $this->termino( 'product_brand', 'Ivrea Argentina' ) )
		);

		ob_start();
		akb_template_loop_product_title();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Ivrea Argentina', $html );
	}

	/**
	 * @testdox Sin editorial no se pinta eyebrow, y el título sigue saliendo.
	 */
	public function test_no_brand_means_no_eyebrow(): void {
		$GLOBALS['product'] = $this->producto( 99 );
		Functions\when( 'get_the_title' )->justReturn( 'The Climber 5' );
		Functions\when( 'get_the_terms' )->justReturn( false );

		ob_start();
		akb_template_loop_product_title();
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'akb-editorial-eyebrow', $html );
		self::assertStringContainsString( '<span class="akb-title-name">The Climber</span> <span class="akb-vol">5</span>', $html );
	}

	/**
	 * @testdox El número final del título se separa para darle peso de lomo.
	 */
	public function test_volume_number_is_split_out(): void {
		self::assertSame(
			'<span class="akb-title-name">Berserk</span> <span class="akb-vol">12</span>',
			akb_loop_title_markup( 'Berserk 12' )
		);
		self::assertSame(
			'<span class="akb-title-name">Akira</span>',
			akb_loop_title_markup( 'Akira' )
		);
	}

	/**
	 * El número puede llevar detrás un sufijo entre paréntesis. Sin reconocerlo,
	 * la card de «Demon Slayer 23 (Edición Especial)» era la ÚNICA de su serie
	 * sin el lomo amarillo: el título entero caía en el nombre.
	 *
	 * @testdox Un sufijo entre paréntesis no le quita el lomo al número.
	 * @dataProvider sufijos_provider
	 */
	public function test_volume_number_survives_a_parenthesised_suffix( string $title, string $expected ): void {
		self::assertSame( $expected, akb_loop_title_markup( $title ) );
	}

	/**
	 * Los casos son los títulos REALES del catálogo con paréntesis: los 13
	 * omnibus de One Piece, la portada alternativa de Maximum Berserk, los
	 * integrales sin número y el «Biblioteca Vertigo», que ya funcionaba.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function sufijos_provider(): array {
		return array(
			'edición especial'    => array(
				'Demon Slayer 23 (Edición Especial)',
				'<span class="akb-title-name">Demon Slayer (Edición Especial)</span> <span class="akb-vol">23</span>',
			),
			'ordinal colgando'    => array(
				'One Piece nº 01 (3 en 1)',
				'<span class="akb-title-name">One Piece (3 en 1)</span> <span class="akb-vol">01</span>',
			),
			'portada alternativa' => array(
				'Maximum Berserk 21 (Portada alternativa + 4 postales )',
				'<span class="akb-title-name">Maximum Berserk (Portada alternativa + 4 postales )</span> <span class="akb-vol">21</span>',
			),
			'integral sin número' => array(
				'Uzumaki (Integral)',
				'<span class="akb-title-name">Uzumaki (Integral)</span>',
			),
			'nueva edición'       => array(
				'From Hell (nueva edición)',
				'<span class="akb-title-name">From Hell (nueva edición)</span>',
			),
			'paréntesis al medio' => array(
				'John Constantine: Hellblazer (Biblioteca Vertigo) 01',
				'<span class="akb-title-name">John Constantine: Hellblazer (Biblioteca Vertigo)</span> <span class="akb-vol">01</span>',
			),
			'tres dígitos'        => array(
				'Mob Psycho 100',
				'<span class="akb-title-name">Mob Psycho</span> <span class="akb-vol">100</span>',
			),
			'pack sumado'         => array(
				'Pack Vinland Saga 1+2+3',
				'<span class="akb-title-name">Pack Vinland Saga 1+2+3</span>',
			),
		);
	}
}

/**
 * Slug de andar por casa para el doble de término.
 *
 * @param string $text Texto.
 * @return string
 */
function sanitize_title_stub( string $text ): string {
	return strtolower( str_replace( ' ', '-', $text ) );
}
