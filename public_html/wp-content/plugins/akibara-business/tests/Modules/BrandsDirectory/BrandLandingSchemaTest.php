<?php
/**
 * Tests del CollectionPage + ItemList de las landings paraguas.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BrandsDirectory {

	use Akibara\Business\Modules\BrandsDirectory\BrandLandingSchema;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use WP_Post;
	use WP_Term;

	final class BrandLandingSchemaTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			Functions\when( 'apply_filters' )->alias(
				static fn( string $hook, $value, ...$args ) => $value
			);
			Functions\when( 'is_admin' )->justReturn( false );
			Functions\when( 'wp_strip_all_tags' )->alias(
				static fn( string $t, bool $b = false ): string => $t
			);
			Functions\when( 'user_trailingslashit' )->alias(
				static fn( string $u ): string => $u
			);
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		/** Prepara una landing de Ivrea resoluble con sus dos ediciones. */
		private function stub_landing_page(): void {
			$page              = new WP_Post();
			$page->ID          = 27449;
			$page->post_status = 'publish';
			$page->post_name   = 'ivrea';

			Functions\when( 'is_singular' )->justReturn( true );
			Functions\when( 'get_queried_object' )->justReturn( $page );
			Functions\when( 'get_permalink' )->justReturn( 'https://example.test/ivrea/' );
			Functions\when( 'get_the_title' )->justReturn( 'Manga Ivrea en Chile' );
			Functions\when( 'get_term_by' )->alias(
				static function ( string $field, string $value, string $tax ) {
					$names = array(
						'ivrea-argentina' => 'Ivrea Argentina',
						'ivrea-espana'    => 'Ivrea España',
					);
					if ( ! isset( $names[ $value ] ) ) {
						return false;
					}
					return new WP_Term( 11, $value, $names[ $value ] );
				}
			);
			Functions\when( 'get_term_link' )->alias(
				static fn( WP_Term $t ): string => 'https://example.test/marca/' . $t->slug . '/'
			);
		}

		public function test_emite_collectionpage_y_itemlist_con_las_dos_ediciones(): void {
			$this->stub_landing_page();

			$out = ( new BrandLandingSchema() )->handle_json_ld( array() );

			$list = null;
			$page = null;
			foreach ( $out as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				if ( 'ItemList' === ( $node['@type'] ?? '' ) ) {
					$list = $node;
				}
				if ( 'CollectionPage' === ( $node['@type'] ?? '' ) ) {
					$page = $node;
				}
			}

			self::assertNotNull( $page );
			self::assertNotNull( $list );
			self::assertSame( 'https://example.test/ivrea/#webpage', $page['@id'] );
			self::assertSame( 'Manga Ivrea en Chile', $page['name'] );
			// El CollectionPage debe apuntar al ItemList, o el grafo queda suelto.
			self::assertSame( $list['@id'], $page['mainEntity']['@id'] );

			self::assertSame( 2, $list['numberOfItems'] );
			self::assertCount( 2, $list['itemListElement'] );
			self::assertSame( 1, $list['itemListElement'][0]['position'] );
			self::assertSame(
				'https://example.test/marca/ivrea-argentina/',
				$list['itemListElement'][0]['item']['url']
			);
			self::assertSame( 'Ivrea España', $list['itemListElement'][1]['item']['name'] );
		}

		/** El schema describe la editorial, no una oferta: nada de precio ni stock. */
		public function test_no_emite_product_ni_offer_ni_precio(): void {
			$this->stub_landing_page();

			$out  = ( new BrandLandingSchema() )->handle_json_ld( array() );
			$json = (string) json_encode( $out );

			self::assertStringNotContainsString( '"Product"', $json );
			self::assertStringNotContainsString( '"Offer"', $json );
			self::assertStringNotContainsString( 'price', $json );
			self::assertStringNotContainsString( 'availability', $json );
		}

		public function test_no_toca_el_grafo_de_una_pagina_cualquiera(): void {
			$page              = new WP_Post();
			$page->ID          = 240;
			$page->post_status = 'publish';
			$page->post_name   = 'nosotros';

			Functions\when( 'is_singular' )->justReturn( true );
			Functions\when( 'get_queried_object' )->justReturn( $page );

			$original = array( 'Breadcrumb' => array( '@type' => 'BreadcrumbList' ) );
			$out      = ( new BrandLandingSchema() )->handle_json_ld( $original );

			self::assertSame( $original, $out );
		}

		/**
		 * Degradación: si las marcas no resuelven, no se emite un ItemList vacío.
		 */
		public function test_sin_ediciones_resolubles_no_emite_nada(): void {
			$this->stub_landing_page();
			Functions\when( 'get_term_by' )->justReturn( false );

			$original = array( 'Breadcrumb' => array( '@type' => 'BreadcrumbList' ) );
			$out      = ( new BrandLandingSchema() )->handle_json_ld( $original );

			self::assertSame( $original, $out );
		}

		public function test_reutiliza_el_nodo_de_pagina_de_rank_math(): void {
			$this->stub_landing_page();

			$original = array(
				'WebPage' => array(
					'@type'      => 'WebPage',
					'@id'        => 'https://example.test/ivrea/#webpage',
					'inLanguage' => 'es-CL',
				),
			);
			$out = ( new BrandLandingSchema() )->handle_json_ld( $original );

			// Conserva lo que Rank Math ya había puesto y sólo reescribe el tipo.
			self::assertSame( 'es-CL', $out['WebPage']['inLanguage'] );
			self::assertSame( 'CollectionPage', $out['WebPage']['@type'] );
			$pages = array_filter(
				$out,
				static fn( $n ): bool => is_array( $n )
					&& in_array( ( $n['@type'] ?? '' ), array( 'WebPage', 'CollectionPage' ), true )
			);
			self::assertCount( 1, $pages, 'No debe quedar un segundo nodo de página.' );
		}
	}
}
