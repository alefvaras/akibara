<?php
/** Native availability constraints when the asynchronous index cannot serve. */
declare( strict_types = 1 );

namespace {
	// Reuse the existing WP_Query double rather than declaring a second shape.
	require_once __DIR__ . '/RuntimeReadersFailClosedTest.php';
}

namespace Akibara\Search\Tests {
	use Akibara\Search\FilterSet;
	use Akibara\Search\NativeAvailabilityFallback;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class NativeAvailabilityTestProduct extends \WC_Product {
		private string $stock;
		public function __construct( int $id, string $stock ) {
			parent::__construct( $id );
			$this->stock = $stock;
		}
		public function get_stock_status(): string { return $this->stock; }
	}

	final class NativeAvailabilityFallbackTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			Functions\when( 'taxonomy_exists' )->justReturn( true );
			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, ...$args ) {
					return 'akb_search_availability_value' === $hook && 20 === ( $args[0] ?? 0 ) ? 'preventa' : $value;
				}
			);
		}
		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}
		/** @testdox Agotado preserves the original metadata and visibility clauses under AND. */
		public function test_outofstock_preserves_existing_constraints(): void {
			$query = new \WP_Query();
			$old_meta = array( 'relation' => 'OR', array( 'key' => 'one', 'value' => 'yes' ), array( 'key' => 'two', 'value' => 'yes' ) );
			$visibility = array( array( 'taxonomy' => 'product_visibility', 'terms' => array( 9 ), 'operator' => 'NOT IN' ) );
			$query->query_vars = array( 'product_brand' => 'milky-way', 'paged' => 3, 'meta_query' => $old_meta, 'tax_query' => $visibility, 'post__not_in' => array( 41 ) );
			self::assertTrue( NativeAvailabilityFallback::apply( $query, new FilterSet( array( 'disponibilidad' => array( 'outofstock' ) ) ), array() ) );
			self::assertSame( 'milky-way', $query->query_vars['product_brand'] );
			self::assertSame( 3, $query->query_vars['paged'] );
			self::assertSame( array( 41 ), $query->query_vars['post__not_in'] );
			self::assertSame( $old_meta, $query->query_vars['meta_query'][0] );
			self::assertSame( array( 'key' => '_stock_status', 'value' => array( 'outofstock' ), 'compare' => 'IN' ), $query->query_vars['meta_query'][1] );
			self::assertSame( $visibility, $query->query_vars['tax_query'][0] );
		}
		/** @testdox OR within each facet remains AND between stock and taxonomy facets. */
		public function test_multiple_stock_and_taxonomy_values_keep_boolean_contract(): void {
			$query = new \WP_Query();
			$filters = new FilterSet( array( 'disponibilidad' => array( 'outofstock', 'instock' ), 'serie' => array( 'given', 'skip-and-loafer' ), 'genero' => array( 'romance' ) ) );
			$defs = array( 'serie' => array( 'type' => 'taxonomy', 'taxonomy' => 'pa_serie' ), 'genero' => array( 'type' => 'taxonomy', 'taxonomy' => 'pa_genero' ) );
			self::assertTrue( NativeAvailabilityFallback::apply( $query, $filters, $defs ) );
			self::assertSame( array( 'outofstock', 'instock' ), $query->query_vars['meta_query'][0]['value'] );
			self::assertSame( 'AND', $query->query_vars['tax_query']['relation'] );
			self::assertSame( array( 'given', 'skip-and-loafer' ), $query->query_vars['tax_query'][0]['terms'] );
			self::assertSame( 'IN', $query->query_vars['tax_query'][0]['operator'] );
			self::assertSame( 'pa_genero', $query->query_vars['tax_query'][1]['taxonomy'] );
		}
		/** @testdox Unsupported availability values like backorders or unknown keys are rejected fail-closed. */
		public function test_unsupported_availability_values_are_rejected(): void {
			foreach ( array( 'a_pedido', 'preventa', 'onbackorder', 'unknown' ) as $invalid ) {
				$query = new \WP_Query();
				self::assertFalse( NativeAvailabilityFallback::apply( $query, new FilterSet( array( 'disponibilidad' => array( $invalid ) ) ), array() ) );
			}
		}

		/** @testdox Instock applies cleanly to meta_query without per-product scans. */
		public function test_instock_applies_cleanly(): void {
			$query = new \WP_Query();
			self::assertTrue( NativeAvailabilityFallback::apply( $query, new FilterSet( array( 'disponibilidad' => array( 'instock' ) ) ), array() ) );
			self::assertSame( array( 'instock' ), $query->query_vars['meta_query'][0]['value'] );
		}

		/** @testdox Index-only criteria are rejected before any query is broadened or mutated. */
		public function test_unsupported_criteria_do_not_silently_disappear(): void {
			foreach ( array( 'precio', 'oferta', 'novedad' ) as $facet ) {
				$query = new \WP_Query(); $query->query_vars = array( 'sentinel' => 'keep' );
				$filters = new FilterSet( array( 'disponibilidad' => array( 'outofstock' ), $facet => array( '1' ) ) );
				self::assertFalse( NativeAvailabilityFallback::apply( $query, $filters, array( $facet => array( 'type' => $facet ) ) ) );
				self::assertSame( array( 'sentinel' => 'keep' ), $query->query_vars );
			}
			$query = new \WP_Query();
			self::assertFalse( NativeAvailabilityFallback::apply( $query, new FilterSet( array( 'disponibilidad' => array( 'outofstock' ) ), 'given' ), array() ) );
			self::assertSame( array(), $query->query_vars );
		}
	}
}
