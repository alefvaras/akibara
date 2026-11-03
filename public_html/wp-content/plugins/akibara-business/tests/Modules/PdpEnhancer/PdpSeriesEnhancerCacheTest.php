<?php
/**
 * Tests de la invalidación del caché de serie del PDP series nav.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
}

namespace Akibara\Business\Tests\Modules\PdpEnhancer {

use Akibara\Business\Modules\PdpEnhancer\PdpSeriesEnhancer;
use Akibara\Business\Services\VolumeVariant;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Term;

/**
 * La lista de la serie se cachea 12 h en object cache persistente: sin estos
 * hooks, publicar tomos nuevos deja el nav prev/siguiente ciego hasta el TTL.
 */
final class PdpSeriesEnhancerCacheTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox register() engancha la invalidación en save, delete y set_object_terms
	 */
	public function test_register_wires_cache_invalidation_hooks(): void {
		$module = new PdpSeriesEnhancer();

		Monkey\Actions\expectAdded( 'save_post_product' )
			->once()
			->with( array( $module, 'flush_series_cache_for_product' ) );
		Monkey\Actions\expectAdded( 'before_delete_post' )
			->once()
			->with( array( $module, 'flush_series_cache_for_product' ) );
		Monkey\Actions\expectAdded( 'set_object_terms' )
			->once()
			->with( array( $module, 'flush_series_cache_on_terms_change' ), 10, 6 );
		Monkey\Actions\expectAdded( 'wp_enqueue_scripts' )->once();
		Monkey\Actions\expectAdded( 'woocommerce_single_product_summary' )->once();

		// La marca de variante viaja DENTRO del valor cacheado y se pone por
		// WP-CLI —`wp post meta update <id> _akb_variante_tomo …`, el comando del
		// runbook—, que no dispara ninguno de los tres hooks de arriba.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $meta_hook ) {
			Monkey\Actions\expectAdded( $meta_hook )
				->once()
				->with( array( $module, 'flush_series_cache_on_variant_meta' ), 10, 3 );
		}

		$module->register();
		self::addToAssertionCount( 8 );
	}

	/**
	 * @testdox flush_series_cache_on_variant_meta() borra la lista al marcar la variante
	 */
	public function test_variant_meta_change_flushes_the_series_list(): void {
		$term                   = new WP_Term( 435, 'demon-slayer', 'Demon Slayer' );
		$term->term_taxonomy_id = 435;

		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_the_terms' )->justReturn( array( $term ) );
		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( 'akb_series_products_435_v3', 'akb_pdp' );

		( new PdpSeriesEnhancer() )->flush_series_cache_on_variant_meta( 99, 27916, VolumeVariant::META_KEY );
		self::addToAssertionCount( 1 );
	}

	/**
	 * Un producto de WooCommerce reescribe decenas de metas en cada guardado de
	 * stock o de precio: si este hook no filtrara por su clave, purgaría la lista
	 * de la serie entera varias veces por request.
	 *
	 * @testdox flush_series_cache_on_variant_meta() ignora cualquier otra meta
	 */
	public function test_variant_meta_hook_ignores_other_keys(): void {
		Functions\expect( 'wp_cache_delete' )->never();
		Functions\expect( 'get_the_terms' )->never();

		( new PdpSeriesEnhancer() )->flush_series_cache_on_variant_meta( 99, 27916, '_price' );
		self::addToAssertionCount( 1 );
	}

	/**
	 * @testdox flush_series_cache_for_product() borra la key de cada serie del producto
	 */
	public function test_flush_for_product_deletes_each_series_key(): void {
		$term                   = new WP_Term( 600, 'prison-school', 'Prison School' );
		$term->term_taxonomy_id = 600;

		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_the_terms' )->justReturn( array( $term ) );
		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( 'akb_series_products_600_v3', 'akb_pdp' );

		( new PdpSeriesEnhancer() )->flush_series_cache_for_product( 27250 );
		self::addToAssertionCount( 1 );
	}

	/**
	 * @testdox flush_series_cache_for_product() ignora posts que no son productos
	 */
	public function test_flush_for_product_skips_non_products(): void {
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\expect( 'get_the_terms' )->never();
		Functions\expect( 'wp_cache_delete' )->never();

		( new PdpSeriesEnhancer() )->flush_series_cache_for_product( 123 );
		self::addToAssertionCount( 1 );
	}

	/**
	 * @testdox flush_series_cache_on_terms_change() ignora taxonomías ajenas
	 */
	public function test_terms_change_ignores_other_taxonomies(): void {
		Functions\expect( 'wp_cache_delete' )->never();

		( new PdpSeriesEnhancer() )->flush_series_cache_on_terms_change(
			1,
			array( 5 ),
			array( 5 ),
			'product_cat',
			false,
			array()
		);
		self::addToAssertionCount( 1 );
	}

	/**
	 * @testdox flush_series_cache_on_terms_change() borra términos nuevos y anteriores
	 */
	public function test_terms_change_deletes_new_and_old_series_keys(): void {
		$old_term                   = new WP_Term( 700, 'otra-serie', 'Otra Serie' );
		$old_term->term_taxonomy_id = 700;

		Functions\expect( 'get_term_by' )
			->once()
			->with( 'term_taxonomy_id', 700, 'pa_serie' )
			->andReturn( $old_term );
		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( 'akb_series_products_600_v3', 'akb_pdp' );
		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( 'akb_series_products_700_v3', 'akb_pdp' );

		( new PdpSeriesEnhancer() )->flush_series_cache_on_terms_change(
			27250,
			array( 600 ),
			array( 600 ),
			'pa_serie',
			false,
			array( 700 )
		);
		self::addToAssertionCount( 2 );
	}
}
}
