<?php
/**
 * Tests del mapa editorial → landing paraguas.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	if ( ! class_exists( 'WP_Term' ) ) {
		class WP_Term {
			public int $term_id;
			public string $taxonomy;
			public string $slug;
			public string $name;
			public int $count;

			public function __construct( int $term_id, string $slug, string $name ) {
				$this->term_id  = $term_id;
				$this->taxonomy = 'product_brand';
				$this->slug     = $slug;
				$this->name     = $name;
				$this->count    = 1;
			}
		}
	}

	// WP_Post: stub canónico en tests/wp-stubs.php (fuente única).
}

namespace Akibara\Business\Tests\Modules\BrandsDirectory {

	use Akibara\Business\Modules\BrandsDirectory\BrandLandings;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use WP_Post;
	use WP_Term;

	final class BrandLandingsTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			Functions\when( 'apply_filters' )->alias(
				static fn( string $hook, $value, ...$args ) => $value
			);
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * La razón de existir del mapa: los dos archivos hermanos de una misma
		 * editorial tienen que resolver a la MISMA landing. Si esto se rompe,
		 * vuelve la canibalización que el módulo vino a cerrar.
		 */
		public function test_ambas_ediciones_de_una_editorial_resuelven_a_la_misma_landing(): void {
			$argentina = BrandLandings::for_slug( 'ivrea-argentina' );
			$espana    = BrandLandings::for_slug( 'ivrea-espana' );

			self::assertNotNull( $argentina );
			self::assertNotNull( $espana );
			self::assertSame( $argentina['key'], $espana['key'] );
			self::assertSame( 'ivrea', $argentina['key'] );

			$panini_ar = BrandLandings::for_slug( 'panini-argentina' );
			$panini_es = BrandLandings::for_slug( 'panini-espana' );

			self::assertNotNull( $panini_ar );
			self::assertNotNull( $panini_es );
			self::assertSame( $panini_ar['key'], $panini_es['key'] );
		}

		/**
		 * Una editorial con un solo archivo no tiene landing paraguas: no hay
		 * nada que consolidar y el destino correcto sigue siendo /marca/.
		 */
		public function test_editorial_sin_landing_devuelve_null(): void {
			self::assertNull( BrandLandings::for_slug( 'planeta-espana' ) );
			self::assertNull( BrandLandings::for_slug( 'milky-way' ) );
			self::assertNull( BrandLandings::for_slug( '' ) );
		}

		public function test_for_term_ignora_lo_que_no_sea_un_termino(): void {
			self::assertNull( BrandLandings::for_term( null ) );
			self::assertNull( BrandLandings::for_term( 'ivrea-argentina' ) );

			$term = new WP_Term( 7, 'ivrea-espana', 'Ivrea España' );
			$resolved = BrandLandings::for_term( $term );
			self::assertNotNull( $resolved );
			self::assertSame( 'ivrea', $resolved['key'] );
		}

		/**
		 * Degradación: sin página publicada, url() devuelve '' y quien llama
		 * omite el enlace. Nunca un href roto.
		 */
		public function test_url_vacia_cuando_la_pagina_no_existe(): void {
			Functions\when( 'get_page_by_path' )->justReturn( null );

			self::assertSame( '', BrandLandings::url( 'ivrea' ) );
			self::assertSame( '', BrandLandings::url( 'inexistente' ) );
		}

		public function test_url_vacia_cuando_la_pagina_no_esta_publicada(): void {
			$draft              = new WP_Post();
			$draft->ID          = 27449;
			$draft->post_status = 'draft';

			Functions\when( 'get_page_by_path' )->justReturn( $draft );
			Functions\when( 'get_permalink' )->justReturn( 'https://example.test/ivrea/' );

			self::assertSame( '', BrandLandings::url( 'ivrea' ) );
		}

		public function test_url_resuelve_la_pagina_publicada(): void {
			$page              = new WP_Post();
			$page->ID          = 27449;
			$page->post_status = 'publish';

			Functions\when( 'get_page_by_path' )->justReturn( $page );
			Functions\when( 'get_permalink' )->justReturn( 'https://example.test/ivrea/' );

			self::assertSame( 'https://example.test/ivrea/', BrandLandings::url( 'ivrea' ) );
		}

		/**
		 * El filtro permite sumar una editorial sin tocar el plugin — es la vía
		 * de extensión declarada en el docblock de la clase.
		 */
		public function test_el_filtro_permite_agregar_una_editorial(): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, ...$args ) {
					if ( 'akibara_brand_landings' !== $hook ) {
						return $value;
					}

					$value['norma'] = array(
						'page_slug'      => 'norma',
						'menu_label'     => 'Norma · todas las ediciones',
						'callout_label'  => 'Norma',
						'callout_detail' => 'España',
						'brands'         => array( 'norma-espana' ),
					);

					return $value;
				}
			);

			$resolved = BrandLandings::for_slug( 'norma-espana' );
			self::assertNotNull( $resolved );
			self::assertSame( 'norma', $resolved['key'] );
		}

		/** @testdox Las etiquetas salen de una option si el dueño la escribió; vacía o igual al literal, no se guarda. */
		public function test_labels_are_editable_options_with_literal_defaults(): void {
			Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $t ): string => strip_tags( $t ) );
			$GLOBALS['akibara_test_options'] = array( 'akb_brand_landing_panini_callout_label' => 'Panini Manga' );

			self::assertSame( 'Panini Manga', BrandLandings::all()['panini']['callout_label'] );
			self::assertSame( 'Ivrea', BrandLandings::all()['ivrea']['callout_label'], 'Sin option, el literal.' );
			self::assertSame( 'Panini · todas las ediciones', BrandLandings::sanitize_labels( array( 'menu_label' => '  ' ), 'panini' )['menu_label'] );
			self::assertSame( array( 'menu_label', 'callout_label' ), array_keys( BrandLandings::label_fields() ), 'Sólo etiquetas que alguien lee: «detalle» dejó de pintarse con el aviso viejo.' );

			unset( $GLOBALS['akibara_test_options'] );
		}
	}
}
