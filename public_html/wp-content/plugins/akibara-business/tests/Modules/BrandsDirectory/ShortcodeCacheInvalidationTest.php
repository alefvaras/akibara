<?php
/**
 * Candado: invalidar el directorio de editoriales no puede depender de wp_options.
 *
 * Defecto original (medido el 2026-08-23): `Shortcode::flush_cache()` borraba las
 * filas `_transient_akb_brands_grid_%` de `wp_options`. Producción corre un object
 * cache persistente (drop-in de LiteSpeed en `wp-content/object-cache.php`), donde
 * los transients NO viven en esa tabla. Reproducción en Docker con
 * `wp_using_ext_object_cache(true)`: `SELECT COUNT(*) ... LIKE
 * '_transient_akb_brands_grid_%'` = 0 filas tras `set_transient()`, y
 * `get_transient()` seguía devolviendo el HTML viejo después de `flush_cache()`.
 *
 * La INVARIANTE que fija este archivo no es «se llama a delete_transient» ni «se
 * incrementa una opción concreta»: es que después de invalidar, `render()` vuelve
 * a CONSTRUIR el HTML en vez de servir el anterior, con los transients viviendo
 * fuera de `wp_options`. Se mide contando las lecturas de términos, no espiando
 * la implementación: cualquier forma de invalidar que funcione pasa, y la que
 * borraba filas de `wp_options` falla.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
}

namespace Akibara\Business\Tests\Modules\BrandsDirectory {

	use Akibara\Business\Modules\BrandsDirectory\Shortcode;
	use Akibara\Business\Services\BrandsHubUrl;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class ShortcodeCacheInvalidationTest extends TestCase {

		/** Almacén que imita un object cache persistente: nunca toca wp_options. */
		private array $object_cache = array();

		/** Opciones en memoria, para la generación de la caché. */
		private array $options = array();

		/** Cuántas veces se leyeron los términos: 1 lectura = 1 construcción real. */
		private int $term_reads = 0;

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			BrandsHubUrl::reset();

			$this->object_cache = array();
			$this->options      = array();
			$this->term_reads   = 0;

			Functions\when( 'shortcode_atts' )->alias(
				static fn( array $defaults, array $atts, string $tag ): array => array_merge( $defaults, $atts )
			);
			Functions\when( 'wp_json_encode' )->alias(
				static fn( $value ): string => (string) json_encode( $value )
			);
			Functions\when( 'get_transient' )->alias(
				fn( string $key ) => $this->object_cache[ $key ] ?? false
			);
			Functions\when( 'set_transient' )->alias(
				function ( string $key, $value ): bool {
					$this->object_cache[ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'get_option' )->alias(
				fn( string $key, $default = false ) => $this->options[ $key ] ?? $default
			);
			Functions\when( 'update_option' )->alias(
				function ( string $key, $value ): bool {
					$this->options[ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'add_shortcode' )->justReturn( true );
			Functions\when( 'do_action' )->justReturn( null );
			// 2026-09-19 (R3-editoriales-hub): `purge_hub_page_cache()` ya no
			// resuelve con `get_page_by_path`, sino con `BrandsHubUrl` (busca por
			// shortcode vía `get_posts`/`get_post_field`/`has_shortcode`). Sin
			// páginas candidatas, cae al mismo literal `/editoriales/` que antes.
			Functions\when( 'get_posts' )->justReturn( array() );
			Functions\when( 'home_url' )->alias(
				static fn( string $path = '' ): string => 'https://example.test' . $path
			);

			$term = new \WP_Term( 41, 'ivrea-argentina', 'Ivrea' );

			Functions\when( 'get_terms' )->alias(
				function () use ( $term ): array {
					++$this->term_reads;
					return array( $term );
				}
			);
			Functions\when( 'get_term_meta' )->justReturn( '' );
			Functions\when( 'apply_filters' )->alias(
				static fn( string $hook, $value ) => $value
			);
			Functions\when( 'get_term_link' )->justReturn( 'https://example.test/marca/ivrea-argentina/' );
			Functions\when( 'locate_template' )->justReturn( '' );
			Functions\when( 'esc_html' )->alias( static fn( $text ): string => (string) $text );
			Functions\when( 'esc_url' )->alias( static fn( $url ): string => (string) $url );
			Functions\when( 'is_wp_error' )->justReturn( false );
		}

		protected function tearDown(): void {
			BrandsHubUrl::reset();
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * @testdox Invalidar hace que render() vuelva a construir el HTML aunque
		 *          wp_options no contenga ningún transient.
		 */
		public function test_flush_cache_forces_a_rebuild(): void {
			$shortcode = new Shortcode();

			self::assertSame( 0, $this->term_reads, 'Precondición: nada leído todavía.' );

			$primero = $shortcode->render( array() );
			self::assertSame( 1, $this->term_reads, 'El primer render construye.' );
			self::assertStringContainsString( 'Ivrea', $primero );

			$shortcode->render( array() );
			self::assertSame( 1, $this->term_reads, 'El segundo render sale de la caché.' );

			$shortcode->flush_cache();

			$shortcode->render( array() );
			self::assertSame(
				2,
				$this->term_reads,
				'Tras invalidar, render() TIENE que volver a construir. Un DELETE sobre wp_options no lo consigue con object cache externo.'
			);
		}

		/**
		 * @testdox Las variantes de atributos que nadie enumeró también se invalidan.
		 */
		public function test_flush_cache_covers_arbitrary_attribute_variants(): void {
			$shortcode = new Shortcode();

			// `shortcode_atts()` devuelve el VALOR que escriba quien pone el
			// shortcode, así que el espacio de claves es abierto: una lista fija de
			// `delete_transient()` dejaría fuera precisamente ésta.
			$atts = array( 'show_empty' => 'quizás', 'group' => 'no' );

			$shortcode->render( $atts );
			self::assertSame( 1, $this->term_reads );

			$shortcode->flush_cache();
			$shortcode->render( $atts );

			self::assertSame( 2, $this->term_reads );
		}

		/**
		 * El directorio ya NO arma la lista plana de editoriales.
		 *
		 * Se publicaba entera como JSON en un `data-attr` —2.233 B por render de
		 * /editoriales/— y ningún JS la parseaba: el filtro en cliente lee las
		 * tarjetas del DOM, donde cada `.akb-brand-card` ya trae slug, región y
		 * nombre normalizado. Armarla costaba además un `get_term_link()` por
		 * marca sólo para rellenar un campo que nadie leía.
		 *
		 * @testdox Should not build the flat brand list nor resolve a link per brand for it.
		 */
		public function test_does_not_build_the_unread_flat_brand_list(): void {
			$enlaces = 0;
			Functions\when( 'get_term_link' )->alias(
				static function () use ( &$enlaces ): string {
					++$enlaces;
					return 'https://example.test/marca/ivrea-argentina/';
				}
			);

			$html = ( new Shortcode() )->render( array() );

			self::assertStringNotContainsString( 'data-akb-brands', $html );
			self::assertStringNotContainsString( '"initials"', $html );
			self::assertSame(
				1,
				$enlaces,
				'Un enlace por marca RENDERIZADA; la lista plana pedía uno más por marca para nadie.'
			);

			$metodos = get_class_methods( Shortcode::class );
			self::assertNotContains( 'build_searchable', $metodos );
			self::assertFalse(
				method_exists( Shortcode::class, 'build_searchable' ),
				'El productor del payload muerto se borra, no se comenta.'
			);
		}

		/**
		 * R2-c3e7997b: el `count` por marca lo mueve `wp_set_object_terms()` vía
		 * `wp_update_term_count_now()`, que en WordPress core NO dispara
		 * `edited_product_brand` — sólo `clean_term_cache()`. Sin este hook, una
		 * editorial nueva con su primer producto quedaba invisible en
		 * /editoriales/ (hide_empty) hasta 1 h.
		 *
		 * @testdox Should register the term-relationship invalidation boundaries.
		 */
		public function test_registers_catalog_relationship_invalidation_hooks(): void {
			$shortcode = new Shortcode();

			Monkey\Actions\expectAdded( 'set_object_terms' )
				->once()
				->with( array( $shortcode, 'flush_on_term_change' ), 10, 6 );
			Monkey\Actions\expectAdded( 'deleted_term_relationships' )
				->once()
				->with( array( $shortcode, 'flush_on_deleted_term_relationships' ), 10, 3 );
			Monkey\Actions\expectAdded( 'transition_post_status' )
				->once()
				->with( array( $shortcode, 'flush_on_status_change' ), 10, 3 );

			$shortcode->register();

			self::assertTrue( true );
		}

		/** @testdox La papelera y la publicación de un producto también invalidan el directorio; un «Actualizar» no. */
		public function test_flushes_when_a_product_enters_or_leaves_the_public_catalog(): void {
			$shortcode = new Shortcode();
			$producto  = (object) array( 'post_type' => 'product' );

			$shortcode->render( array() );
			self::assertSame( 1, $this->term_reads );

			$shortcode->flush_on_status_change( 'publish', 'publish', $producto );
			$shortcode->flush_on_status_change( 'trash', 'publish', (object) array( 'post_type' => 'page' ) );
			$shortcode->render( array() );
			self::assertSame( 1, $this->term_reads, 'Un «Actualizar» o una página a la papelera no mueven el conteo de ninguna editorial.' );

			$shortcode->flush_on_status_change( 'trash', 'publish', $producto );
			$shortcode->render( array() );
			self::assertSame( 2, $this->term_reads, 'La última editorial con catálogo puede haberse quedado vacía.' );
		}

		/** @testdox A product joining a brand invalidates the cache; any other taxonomy does not. */
		public function test_flushes_when_a_product_joins_or_leaves_a_brand(): void {
			$shortcode = new Shortcode();

			$primero = $shortcode->render( array() );
			self::assertSame( 1, $this->term_reads );

			$shortcode->flush_on_term_change( 1, array(), array( 20 ), 'pa_serie', false, array() );
			$shortcode->render( array() );
			self::assertSame( 1, $this->term_reads, 'Otra taxonomía no invalida nada.' );

			// Un «Actualizar» del producto que deja las marcas como estaban.
			$shortcode->flush_on_term_change( 1, array(), array( 20, 21 ), Shortcode::TAXONOMY, false, array( '21', '20' ) );
			$shortcode->render( array() );
			self::assertSame( 1, $this->term_reads, 'Mismas marcas: ni se vacía ni se purga la página del directorio.' );

			$shortcode->flush_on_term_change( 1, array(), array( 20 ), Shortcode::TAXONOMY, false, array( 20, 21 ) );
			$shortcode->render( array() );
			self::assertSame( 2, $this->term_reads, 'Salir de una marca SÍ invalida y reconstruye.' );
			unset( $primero );
		}

		/** @testdox Should cover direct relationship removals that skip set_object_terms. */
		public function test_flushes_on_direct_relationship_removal(): void {
			$shortcode = new Shortcode();

			$shortcode->render( array() );
			self::assertSame( 1, $this->term_reads );

			$shortcode->flush_on_deleted_term_relationships( 1, array( 20 ), Shortcode::TAXONOMY );
			$shortcode->render( array() );

			self::assertSame( 2, $this->term_reads );
		}

		/**
		 * @testdox Invalidar purga también el HTML de página del hub.
		 */
		public function test_flush_cache_purges_the_hub_page(): void {
			$purgadas = array();
			Functions\when( 'do_action' )->alias(
				static function ( string $hook, ...$args ) use ( &$purgadas ): void {
					if ( 'litespeed_purge_url' === $hook ) {
						$purgadas[] = (string) ( $args[0] ?? '' );
					}
				}
			);

			$shortcode = new Shortcode();
			$shortcode->flush_cache();
			$shortcode->purge_hub_page_cache();

			self::assertSame( array( 'https://example.test/editoriales/' ), $purgadas );
		}
	}
}
