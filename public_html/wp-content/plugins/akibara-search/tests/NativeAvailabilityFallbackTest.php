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
		/** @testdox Reservable states remain distinct using the same StockLabels business hook. */
		public function test_preventa_and_a_pedido_are_not_merged(): void {
			$products = array( 10 => new NativeAvailabilityTestProduct( 10, 'instock' ), 20 => new NativeAvailabilityTestProduct( 20, 'onbackorder' ), 30 => new NativeAvailabilityTestProduct( 30, 'onbackorder' ), 40 => new NativeAvailabilityTestProduct( 40, 'outofstock' ) );
			Functions\when( 'get_posts' )->justReturn( array_keys( $products ) );
			Functions\when( 'wc_get_product' )->alias( static fn( int $id ) => $products[ $id ] ?? null );
			foreach ( array( array( array( 'a_pedido' ), array( 30 ) ), array( array( 'preventa' ), array( 20 ) ), array( array( 'instock', 'a_pedido' ), array( 10, 30 ) ), array( array( 'outofstock', 'preventa' ), array( 20, 40 ) ) ) as [ $selected, $expected ] ) {
				$query = new \WP_Query();
				self::assertTrue( NativeAvailabilityFallback::apply( $query, new FilterSet( array( 'disponibilidad' => $selected ) ), array() ) );
				self::assertSame( $expected, $query->query_vars['post__in'] );
			}
		}
		/** @testdox Selecting both reservable states uses onbackorder without a per-product scan. */
		public function test_both_reservable_states_need_no_scan(): void {
			Functions\expect( 'get_posts' )->never();
			$query = new \WP_Query();
			self::assertTrue( NativeAvailabilityFallback::apply( $query, new FilterSet( array( 'disponibilidad' => array( 'a_pedido', 'preventa' ) ) ), array() ) );
			self::assertSame( array( 'onbackorder' ), $query->query_vars['meta_query'][0]['value'] );
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
		/** @testdox Empty reservable matches use a nonempty no-match sentinel. */
		public function test_empty_business_selection_cannot_widen_post_in(): void {
			Functions\when( 'get_posts' )->justReturn( array() );
			$query = new \WP_Query();
			self::assertTrue( NativeAvailabilityFallback::apply( $query, new FilterSet( array( 'disponibilidad' => array( 'preventa' ) ) ), array() ) );
			self::assertSame( array( -1 ), $query->query_vars['post__in'] );
		}
		/** @testdox Candidate enumeration preserves archive, exclusions and metadata while removing pagination. */
		public function test_candidate_scan_preserves_existing_restrictions(): void {
			$captured = null;
			Functions\when( 'get_posts' )->alias( static function ( array $args ) use ( &$captured ): array { $captured = $args; return array(); } );
			$query = new \WP_Query();
			$query->query_vars = array( 'product_brand' => 'milky-way', 'paged' => 3, 'posts_per_page' => 24, 'post__in' => array( 20, 30 ), 'post__not_in' => array( 40 ) );
			self::assertTrue( NativeAvailabilityFallback::apply( $query, new FilterSet( array( 'disponibilidad' => array( 'a_pedido' ) ) ), array() ) );
			self::assertSame( 'milky-way', $captured['product_brand'] );
			self::assertSame( array( 20, 30 ), $captured['post__in'] );
			self::assertSame( array( 40 ), $captured['post__not_in'] );
			self::assertSame( 1, $captured['paged'] );
			self::assertSame( 3, $query->query_vars['paged'] );
		}
	}
}
