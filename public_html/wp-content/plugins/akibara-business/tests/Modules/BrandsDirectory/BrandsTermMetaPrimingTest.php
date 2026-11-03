<?php
/**
 * Certifica el cebado por lotes (O(1)) de `wp_termmeta` antes de recorrer
 * términos de editorial o país en HomeCarousel, Shortcode, MenuInjector y CortesPanel.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BrandsDirectory;

use Akibara\Business\Modules\BrandsDirectory\HomeCarousel;
use Akibara\Business\Modules\BrandsDirectory\MenuInjector;
use Akibara\Business\Modules\BrandsDirectory\Shortcode;
use Akibara\Business\Services\BrandsHubUrl;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Term;

final class BrandsTermMetaPrimingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		BrandsHubUrl::reset();

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value, ...$args ) => $value );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'shortcode_atts' )->alias( static fn( array $pairs, array $atts ): array => array_merge( $pairs, $atts ) );
		Functions\when( 'get_option' )->justReturn( 1 );
		Functions\when( 'get_term_meta' )->justReturn( '99' );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( array( 'https://akibara.test/logo.png', 200, 100 ) );
		Functions\when( 'get_term_link' )->justReturn( 'https://akibara.test/marca/ivrea/' );
		Functions\when( 'wp_count_terms' )->justReturn( 2 );
		Functions\when( 'locate_template' )->justReturn( '' );
		Functions\when( 'get_posts' )->justReturn( array( 909 ) );
		Functions\when( 'get_post_field' )->justReturn( '[akibara_brands_grid]' );
		Functions\when( 'has_shortcode' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.test/editoriales/' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://akibara.test/editoriales/?region=argentina' );
		Functions\when( 'get_queried_object' )->justReturn( null );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
	}

	protected function tearDown(): void {
		BrandsHubUrl::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox HomeCarousel ceba `update_termmeta_cache` una sola vez en lote antes de leer metadatos por editorial. */
	public function test_home_carousel_primes_termmeta_in_single_batch(): void {
		$terms = array(
			new WP_Term( 101, 'ivrea-argentina', 'Ivrea Argentina' ),
			new WP_Term( 102, 'panini-espana', 'Panini España' ),
			new WP_Term( 103, 'planeta-comic', 'Planeta Cómic' ),
		);
		Functions\when( 'get_terms' )->justReturn( $terms );

		$primed_batches = array();
		Functions\when( 'update_termmeta_cache' )->alias(
			static function ( array $ids ) use ( &$primed_batches ): array {
				$primed_batches[] = $ids;
				return array();
			}
		);

		( new HomeCarousel() )->render_shortcode( array() );

		self::assertSame(
			array( array( 101, 102, 103 ) ),
			$primed_batches,
			'HomeCarousel debe cebar update_termmeta_cache en un único lote O(1) con todos los term_id.'
		);
	}

	/** @testdox Shortcode del directorio ceba `update_termmeta_cache` en un único lote antes de agrupar por región. */
	public function test_shortcode_primes_termmeta_in_single_batch(): void {
		$terms = array(
			new WP_Term( 201, 'ivrea-argentina', 'Ivrea Argentina' ),
			new WP_Term( 202, 'milky-way', 'Milky Way' ),
		);
		Functions\when( 'get_terms' )->justReturn( $terms );

		$primed_batches = array();
		Functions\when( 'update_termmeta_cache' )->alias(
			static function ( array $ids ) use ( &$primed_batches ): array {
				$primed_batches[] = $ids;
				return array();
			}
		);

		( new Shortcode() )->render( array() );

		self::assertSame(
			array( array( 201, 202 ) ),
			$primed_batches,
			'Shortcode debe cebar update_termmeta_cache en un único lote O(1) antes de resolver regiones.'
		);
	}

	/** @testdox MenuInjector ceba `update_termmeta_cache` en un único lote antes de construir el submenú de editoriales. */
	public function test_menu_injector_primes_termmeta_in_single_batch(): void {
		$terms = array(
			new WP_Term( 301, 'ivrea-espana', 'Ivrea España' ),
			new WP_Term( 302, 'ovni-press', 'Ovni Press' ),
		);
		Functions\when( 'get_terms' )->justReturn( $terms );

		$primed_batches = array();
		Functions\when( 'update_termmeta_cache' )->alias(
			static function ( array $ids ) use ( &$primed_batches ): array {
				$primed_batches[] = $ids;
				return array();
			}
		);

		$trigger                  = new \stdClass();
		$trigger->ID              = 10;
		$trigger->menu_item_parent = '0';
		$trigger->classes         = array( MenuInjector::TRIGGER_CLASS );

		( new MenuInjector() )->inject_brands( array( $trigger ), new \stdClass() );

		self::assertSame(
			array( array( 301, 302 ) ),
			$primed_batches,
			'MenuInjector debe cebar update_termmeta_cache en un único lote O(1) antes de construir items.'
		);
	}
}
