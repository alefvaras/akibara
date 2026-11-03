<?php
/**
 * HomeCarousel también debe enterarse cuando un producto entra o sale de una
 * marca, no sólo cuando se edita/crea/borra el TÉRMINO (R2-c3e7997b).
 *
 * Defecto original (medido 2026-09-19): `register()` sólo enganchaba
 * `edited_product_brand`/`created_product_brand`/`delete_product_brand`. El
 * `count` por marca —el que decide si pasa `hide_empty` en `fetch_brands()`—
 * lo mueve `wp_set_object_terms()` vía `wp_update_term_count_now()`, que en
 * WordPress core NO dispara ninguno de esos tres hooks: sólo llama
 * `clean_term_cache()`. Una editorial nueva con su primer producto quedaba
 * invisible en el carrusel del home hasta 12 h (CACHE_TTL), aunque el
 * producto ya estuviera publicado y comprable.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
}

namespace Akibara\Business\Tests\Modules\BrandsDirectory {

	use Akibara\Business\Modules\BrandsDirectory\HomeCarousel;
	use Akibara\Business\Modules\BrandsDirectory\Shortcode;
	use Akibara\Business\Services\BrandsHubUrl;
	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use WP_Term;

	final class HomeCarouselTest extends TestCase {

		private const CACHE_KEY = 'akibara_home_carousel_brands_v4';

		/** @var array<string, mixed> */
		private array $transients = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			BrandsHubUrl::reset();

			$this->transients = array();

			Functions\when( 'get_transient' )->alias( fn( string $k ) => $this->transients[ $k ] ?? false );
			Functions\when( 'set_transient' )->alias(
				function ( string $k, $v ): bool {
					$this->transients[ $k ] = $v;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->alias(
				function ( string $k ): bool {
					unset( $this->transients[ $k ] );
					return true;
				}
			);
			Functions\when( 'add_shortcode' )->justReturn( true );
		}

		protected function tearDown(): void {
			BrandsHubUrl::reset();
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * R3-editoriales-hub: el CTA «Ver editoriales» del carrusel comparte fuente
		 * única con `MenuInjector` y `Shortcode::purge_hub_page_cache()` vía
		 * `BrandsHubUrl` — antes `render()` repetía su propio
		 * `get_page_by_path('editoriales')` y, si el dueño renombraba la página,
		 * el CTA del home seguía apuntando al slug viejo mientras el resto ya se
		 * había corregido solo.
		 *
		 * @testdox Should build the CTA link from BrandsHubUrl, not from a slug lookup.
		 */
		public function test_more_url_comes_from_brands_hub_url(): void {
			$term = new WP_Term( 41, 'ivrea-argentina', 'Ivrea' );

			Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value, ...$args ) => $value );
			Functions\when( 'get_terms' )->justReturn( array( $term ) );
			Functions\when( 'get_term_meta' )->alias(
				static fn( int $id, string $key, bool $single ) => 'thumbnail_id' === $key ? '99' : ''
			);
			Functions\when( 'wp_get_attachment_image_src' )->justReturn( array( 'https://example.test/logo.png', 200, 100 ) );
			Functions\when( 'get_term_link' )->justReturn( 'https://example.test/marca/ivrea-argentina/' );
			Functions\when( 'wp_count_terms' )->justReturn( 1 );
			Functions\when( 'locate_template' )->justReturn(
				dirname( __DIR__, 2 ) . '/fixtures/home-carousel-probe.php'
			);

			// El dueño renombró la página del directorio: ya no vive en el slug
			// `editoriales`. `get_page_by_path` no la encontraría; `BrandsHubUrl`
			// la ubica por el shortcode `akibara_brands_grid`.
			Functions\when( 'get_posts' )->justReturn( array( 909 ) );
			Functions\when( 'get_post_field' )->justReturn( '[akibara_brands_grid]' );
			Functions\when( 'has_shortcode' )->justReturn( true );
			Functions\when( 'get_permalink' )->justReturn( 'https://example.test/todas-las-editoriales/' );

			$html = ( new HomeCarousel() )->render_shortcode( array() );

			self::assertSame( 'https://example.test/todas-las-editoriales/', $html );
		}

		/** @testdox Should register both the term hooks and the relationship-change boundaries. */
		public function test_registers_the_invalidation_hooks(): void {
			$carousel = new HomeCarousel();

			foreach ( array( 'edited_product_brand', 'created_product_brand', 'delete_product_brand' ) as $hook ) {
				Actions\expectAdded( $hook )->once()->with( array( $carousel, 'flush_cache' ) );
			}
			Actions\expectAdded( 'set_object_terms' )
				->once()
				->with( array( $carousel, 'flush_on_term_change' ), 10, 6 );
			Actions\expectAdded( 'deleted_term_relationships' )
				->once()
				->with( array( $carousel, 'flush_on_deleted_term_relationships' ), 10, 3 );
			Actions\expectAdded( 'transition_post_status' )
				->once()
				->with( array( $carousel, 'flush_on_status_change' ), 10, 3 );

			$carousel->register();

			self::assertTrue( true, 'Los seis hooks quedaron colgados.' );
		}

		/**
		 * Publicar o mandar a la papelera un producto mueve el `count` de su
		 * editorial sin pasar por `set_object_terms`: WordPress lo recalcula en la
		 * propia transición (revisión independiente de R2-c3e7997b).
		 *
		 * @testdox La papelera y la publicación de un producto también vacían el carrusel; lo demás no.
		 */
		public function test_a_product_status_transition_invalidates_the_cache(): void {
			$carousel = new HomeCarousel();
			$producto = (object) array( 'post_type' => 'product' );

			$this->transients[ self::CACHE_KEY ] = array( array( 'name' => 'viejo' ) );
			$carousel->flush_on_status_change( 'draft', 'pending', $producto );
			$carousel->flush_on_status_change( 'publish', 'publish', $producto );
			$carousel->flush_on_status_change( 'trash', 'publish', (object) array( 'post_type' => 'post' ) );
			self::assertArrayHasKey( self::CACHE_KEY, $this->transients, 'Ni un borrador que sigue en borrador, ni un «Actualizar», ni una entrada del blog.' );

			$carousel->flush_on_status_change( 'trash', 'publish', $producto );
			self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients, 'A la papelera: la editorial puede haberse quedado sin catálogo.' );

			$this->transients[ self::CACHE_KEY ] = array( array( 'name' => 'viejo' ) );
			$carousel->flush_on_status_change( 'publish', 'draft', $producto );
			self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients, 'Publicado: una editorial nueva puede estrenar catálogo.' );
		}

		/** @testdox A product joining or leaving a brand invalidates the cache; any other taxonomy does not. */
		public function test_a_product_change_invalidates_the_cache_only_for_the_brand_taxonomy(): void {
			$carousel = new HomeCarousel();

			$this->transients[ self::CACHE_KEY ] = array( array( 'name' => 'viejo' ) );
			$carousel->flush_on_term_change( 1, array(), array( 20 ), 'pa_serie', false, array() );
			self::assertArrayHasKey( self::CACHE_KEY, $this->transients, 'Otra taxonomía no invalida nada.' );

			// WordPress dispara `set_object_terms` en CADA guardado del producto,
			// con las marcas de siempre: eso no es un cambio y no vacía nada.
			$carousel->flush_on_term_change( 1, array(), array( 20, 21 ), Shortcode::TAXONOMY, false, array( '21', '20' ) );
			self::assertArrayHasKey( self::CACHE_KEY, $this->transients, 'Mismas marcas (en otro orden y como texto): no es un cambio.' );

			$carousel->flush_on_term_change( 1, array(), array( 20 ), Shortcode::TAXONOMY, false, array() );
			self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients, 'Entrar a product_brand SÍ invalida.' );
		}

		/** @testdox Should cover direct relationship removals that skip set_object_terms. */
		public function test_flushes_on_direct_relationship_removal(): void {
			$carousel = new HomeCarousel();

			$this->transients[ self::CACHE_KEY ] = array( array( 'name' => 'viejo' ) );
			$carousel->flush_on_deleted_term_relationships( 1, array( 20 ), 'pa_serie' );
			self::assertArrayHasKey( self::CACHE_KEY, $this->transients, 'Otra taxonomía no invalida nada.' );

			$carousel->flush_on_deleted_term_relationships( 1, array( 20 ), Shortcode::TAXONOMY );
			self::assertArrayNotHasKey( self::CACHE_KEY, $this->transients );
		}
	}
}
