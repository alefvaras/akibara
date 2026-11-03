<?php
/**
 * Tests for defensive BrandsDirectory asset loading.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
}

namespace Akibara\Business\Tests\Modules\BrandsDirectory {

	use Akibara\Business\Modules\BrandsDirectory\BrandsDirectory;
	use Akibara\Business\Modules\BrandsDirectory\Shortcode;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class ShortcodeAssetsTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		public function test_render_requests_assets_even_when_markup_comes_from_cache(): void {
			Functions\expect( 'do_action' )
				->once()
				->with( 'akibara_brands_directory_assets' );
			Functions\when( 'shortcode_atts' )->alias(
				static fn( array $defaults, array $atts, string $tag ): array => array_merge( $defaults, $atts )
			);
			Functions\when( 'wp_json_encode' )->alias(
				static fn( array $value ): string => (string) json_encode( $value )
			);
			// La clave de caché incluye la generación de invalidación (opción
			// `akb_brands_grid_gen`) desde que `flush_cache()` dejó de borrar filas
			// de `wp_options`, que en producción no contiene ningún transient.
			Functions\when( 'get_option' )->alias(
				static fn( string $key, $default = false ) => $default
			);
			Functions\when( 'get_transient' )->justReturn( '<section>cached</section>' );

			self::assertSame( '<section>cached</section>', ( new Shortcode() )->render( array() ) );
		}

		public function test_asset_loader_enqueues_css_and_filter_script(): void {
			$styles  = array();
			$scripts = array();
			$theme   = dirname( __DIR__, 5 ) . '/themes/akibara-gp';

			Functions\when( 'get_stylesheet_directory' )->justReturn( $theme );
			Functions\when( 'get_stylesheet_directory_uri' )->justReturn( 'https://example.test/theme' );
			Functions\when( 'get_terms' )->justReturn( array( 1, 2, 3, 4, 5, 6 ) );
			Functions\when( 'wp_enqueue_style' )->alias(
				static function ( string $handle ) use ( &$styles ): void {
					$styles[] = $handle;
				}
			);
			Functions\when( 'wp_enqueue_script' )->alias(
				static function ( string $handle ) use ( &$scripts ): void {
					$scripts[] = $handle;
				}
			);

			( new BrandsDirectory() )->enqueue_assets();

			self::assertSame( array( 'akibara-brands' ), $styles );
			self::assertSame( array( 'akibara-brands-filter' ), $scripts );
		}
	}
}
