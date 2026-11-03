<?php
/**
 * Contratos fail-closed de los lectores públicos del índice Search.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace {
	defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
	defined( 'OBJECT_K' ) || define( 'OBJECT_K', 'OBJECT_K' );
	defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
	defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
	defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

	// WC_Product vive como doble ÚNICO en Support/wp-function-stubs.php: tres
	// copias distintas tras `class_exists` hacían que la forma ganadora dependiera
	// del orden de ejecución (flake medido con --order-by=random).

	if ( ! class_exists( 'WP_REST_Request' ) ) {
		/** Request REST mínimo con params y query params separables. */
		class WP_REST_Request {
			/** @var array<string, mixed> */
			private array $params = array();

			/** @var array<string, mixed> */
			private array $query_params = array();

			/** @param mixed $value Valor. */
			public function set_param( string $key, $value ): void {
				$this->params[ $key ] = $value;
			}

			/** @return mixed */
			public function get_param( string $key ) {
				return $this->params[ $key ] ?? $this->query_params[ $key ] ?? null;
			}

			/** @param array<string, mixed> $params Query params. */
			public function set_query_params( array $params ): void {
				$this->query_params = $params;
			}

			/** @return array<string, mixed> */
			public function get_query_params(): array {
				return $this->query_params;
			}
		}
	}

	// WP_Error vive como doble ÚNICO en Support/wp-function-stubs.php: las copias
	// por archivo hacían que la forma ganadora dependiera del orden de ejecución.

	// WP_Post vive como doble ÚNICO en Support/wp-function-stubs.php: las copias
	// por archivo hacían que la forma ganadora dependiera del orden de ejecución.

	// WP_Term vive como doble ÚNICO en Support/wp-function-stubs.php: las copias
	// por archivo hacían que la forma ganadora dependiera del orden de ejecución.

	// WP_REST_Response vive como doble ÚNICO en Support/wp-function-stubs.php: las copias
	// por archivo hacían que la forma ganadora dependiera del orden de ejecución.

	if ( ! class_exists( 'WP_Query' ) ) {
		/** Main product query mínimo para comprobar que no se muta al fallar. */
		class WP_Query {
			public int $found_posts = 0;

			/** @var array<string, mixed> */
			public array $query_vars = array();

			public function is_main_query(): bool {
				return true;
			}

			public function is_feed(): bool {
				return false;
			}

			public function is_post_type_archive( string $type ): bool {
				return 'product' === $type;
			}

			/** @param mixed $taxonomies Taxonomías. */
			public function is_tax( $taxonomies = '' ): bool {
				unset( $taxonomies );
				return false;
			}

			public function is_search(): bool {
				return false;
			}

			/** @param mixed $value Valor. */
			public function set( string $key, $value ): void {
				$this->query_vars[ $key ] = $value;
			}
		}
	}

	if ( ! class_exists( 'WC_Cache_Helper' ) ) {
		/** Doble de la API oficial de namespace de caches WooCommerce. */
		class WC_Cache_Helper {
			public static function get_cache_prefix( string $group ): string {
				if ( ! isset( $GLOBALS['akb_runtime_wc_prefixes'][ $group ] ) ) {
					$GLOBALS['akb_runtime_wc_prefixes'][ $group ] = 1;
				}
				return 'wc_cache_' . $GLOBALS['akb_runtime_wc_prefixes'][ $group ] . '_';
			}

			public static function invalidate_cache_group( string $group ): bool {
				$GLOBALS['akb_runtime_wc_prefix_calls'][] = $group;
				if ( false === ( $GLOBALS['akb_runtime_wc_prefix_result'] ?? true ) ) {
					return false;
				}
				$GLOBALS['akb_runtime_wc_prefixes'][ $group ] = ( $GLOBALS['akb_runtime_wc_prefixes'][ $group ] ?? 1 ) + 1;
				return true;
			}
		}
	}

}

namespace Akibara\Search {
	// Brain Monkey no conserva parámetros by-reference en aliases de funciones.
	// Este bridge existe sólo en el proceso PHPUnit y deja que el contrato pruebe
	// el cuarto parámetro `$found` real de wp_cache_get().
	if ( ! function_exists( __NAMESPACE__ . '\\wp_cache_get' ) ) {
		function wp_cache_get( $id, string $group = '', bool $force = false, &$found = null ) {
			$callback = $GLOBALS['akb_runtime_wp_cache_get'] ?? null;
			if ( is_callable( $callback ) ) {
				return $callback( $id, $group, $force, $found );
			}
			$found = false;
			return false;
		}
	}
}

namespace Akibara\Search\Tests {

	use Akibara\Search\DocumentBuilder;
	use Akibara\Search\FacetRegistry;
	use Akibara\Search\FacetQueryIntegration;
	use Akibara\Search\FacetService;
	use Akibara\Search\EditorialDiscountPublication;
	use Akibara\Search\FilterSet;
	use Akibara\Search\Fuzzy;
	use Akibara\Search\Install;
	use Akibara\Search\Novedad;
	use Akibara\Search\ProductPresenter;
	use Akibara\Search\ProductSourceAuthority;
	use Akibara\Search\PopularQueries;
	use Akibara\Search\QueryEngine;
	use Akibara\Search\QueryLogger;
	use Akibara\Search\Search;
	use Akibara\Search\SemanticPublication;
	use Akibara\Search\Stopwords;
	use Akibara\Search\SuggestController;
	use Akibara\Search\Synonyms;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use ReflectionMethod;

	require_once dirname( __DIR__ ) . '/src/Normalizer.php';
	require_once dirname( __DIR__ ) . '/src/Scorer.php';
	require_once dirname( __DIR__ ) . '/src/FilterSet.php';
	require_once dirname( __DIR__ ) . '/src/StockLabels.php';
	require_once dirname( __DIR__ ) . '/src/QueryEngine.php';
	require_once dirname( __DIR__ ) . '/src/FacetService.php';
	require_once dirname( __DIR__ ) . '/src/ProductPresenter.php';
	require_once dirname( __DIR__ ) . '/src/QueryLogger.php';
	require_once dirname( __DIR__ ) . '/src/PopularQueries.php';
	require_once dirname( __DIR__ ) . '/src/Highlighter.php';
	require_once dirname( __DIR__ ) . '/src/FacetsView.php';
	require_once dirname( __DIR__ ) . '/src/Support/RateLimit.php';
	require_once dirname( __DIR__ ) . '/src/EditorialDiscountPublication.php';
	require_once dirname( __DIR__ ) . '/src/SuggestController.php';
	require_once dirname( __DIR__ ) . '/src/FacetQueryIntegration.php';
	require_once dirname( __DIR__ ) . '/src/Search.php';

	/** Producto vivo configurable para el contrato del presenter. */
	final class RuntimeLiveProduct extends \WC_Product {

		public string $name = 'Manga vivo';
		public string $status = 'publish';
		public bool $visible = true;
		public string $sku = 'ISBN-7';
		public string $stock_status = 'instock';
		public string $price_html = '<b>$12.990</b>';
		/** @var mixed */
		public $price = '9990';
		public string $short_description = '';
		public string $description = '';
		public int $menu_order = 0;
		public string $post_name = '';
		public string $post_date = '2026-01-01 00:00:00';
		public string $post_date_gmt = '2026-01-01 00:00:00';
		public string $post_modified = '2026-01-01 00:00:00';
		public string $post_modified_gmt = '2026-01-01 00:00:00';
		public string $post_password = '';
		public string $comment_status = 'open';
		/** @var mixed */
		public $image_id = 70;
		public bool $variable = false;
		public bool $variation = false;
		public int $parent_id = 0;

		public function get_name(): string {
			return $this->name;
		}

		public function get_status(): string {
			return $this->status;
		}

		public function is_visible(): bool {
			return $this->visible;
		}

		public function get_sku(): string {
			return $this->sku;
		}

		public function get_stock_status(): string {
			return $this->stock_status;
		}

		public function get_price_html(): string {
			$GLOBALS['akb_runtime_price_events'][] = 'price_html';
			return $this->price_html;
		}

		public function get_price() {
			$GLOBALS['akb_runtime_price_events'][] = 'price';
			return $this->price;
		}

		public function get_short_description(): string {
			return $this->short_description;
		}

		public function get_description(): string {
			return $this->description;
		}

		public function get_menu_order(): int {
			return $this->menu_order;
		}

		public function is_type( string $type ): bool {
			return ( $this->variable && 'variable' === $type ) || ( $this->variation && 'variation' === $type );
		}

		public function get_parent_id(): int {
			return $this->parent_id;
		}

		public function get_image_id() {
			return $this->image_id;
		}

		public function canonical_post_name(): string {
			return '' !== $this->post_name ? $this->post_name : 'manga-' . $this->get_id();
		}
	}

	/** Doble verificable de ProductCache (feature product_instance_caching). */
	final class RuntimeProductObjectCache {
		public function remove( int $id ): bool {
			$GLOBALS['akb_runtime_product_cache_remove_calls'][] = $id;
			if ( empty( $GLOBALS['akb_runtime_product_cache_noop'] ) ) {
				unset( $GLOBALS['akb_runtime_product_object_cache'][ $id ] );
			}
			return (bool) ( $GLOBALS['akb_runtime_product_cache_remove_result'] ?? true );
		}

		public function is_cached( int $id ): bool {
			return isset( $GLOBALS['akb_runtime_product_object_cache'][ $id ] );
		}
	}

	/** Contenedor mínimo para resolver ProductCache por su class-string público. */
	final class RuntimeWooContainer {
		public function get( string $class ): RuntimeProductObjectCache {
			unset( $class );
			return new RuntimeProductObjectCache();
		}
	}

	/** Doble configurable para cada familia de lectura wpdb. */
	final class RuntimeReadersWpdb {

		public string $prefix = 'wp_';

		public string $posts = 'wp_posts';

		public string $options = 'wp_options';

		public string $termmeta = 'wp_termmeta';

		public string $terms = 'wp_terms';

		public string $term_taxonomy = 'wp_term_taxonomy';

		public string $term_relationships = 'wp_term_relationships';

		public string $last_error = '';

		public string $fail_method = '';

		public string $fail_contains = '';

		public bool $fail_as_false = false;

		public string $throw_method = '';

		public string $throw_contains = '';

		/** @var array<string, mixed> */
		public array $results = array();

		/** @var array<int, int> */
		public array $candidate_ids = array();

		/** @var array<int, array<string, mixed>> */
		public array $documents = array();

		private bool $last_call_failed = false;

		/** @var array<int, string> */
		public array $queries = array();

		/** @param mixed ...$args Valores a interpolar. */
		public function prepare( string $query, ...$args ) {
			if ( 'prepare' === $this->throw_method
				&& ( '' === $this->throw_contains || false !== stripos( $query, $this->throw_contains ) ) ) {
				throw new \RuntimeException( 'forced throwable' );
			}
			if ( array_key_exists( 'prepare', $this->results ) ) {
				return is_callable( $this->results['prepare'] ) ? ( $this->results['prepare'] )( $query, $args ) : $this->results['prepare'];
			}
			if ( 'prepare' === $this->fail_method
				&& ( '' === $this->fail_contains || false !== stripos( $query, $this->fail_contains ) ) ) {
				$this->last_error = $this->fail_as_false ? '' : 'forced prepare failure';
				if ( $this->fail_as_false ) {
					return false;
				}
			}
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) || is_float( $arg )
					? (string) $arg
					: "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query       = (string) preg_replace( '/%[sdf]/', $replacement, $query, 1 );
			}
			return $query;
		}

		public function esc_like( string $value ): string {
			return addcslashes( $value, '_%\\' );
		}

		/** @return array<int, mixed> */
		public function get_col( string $query ) {
			$this->record( 'get_col', $query );
			if ( array_key_exists( 'get_col', $this->results ) ) {
				return is_callable( $this->results['get_col'] ) ? ( $this->results['get_col'] )( $query ) : $this->results['get_col'];
			}
			if ( $this->last_call_failed ) {
				return $this->fail_as_false ? false : array();
			}
			if ( false !== stripos( $query, 'SELECT DISTINCT d.post_id' ) ) {
				return $this->candidate_ids;
			}
			return array();
		}

		/** @return array<int|string, mixed> */
		public function get_results( string $query, $output = null ) {
			$this->record( 'get_results', $query );
			if ( array_key_exists( 'get_results', $this->results ) ) {
				return is_callable( $this->results['get_results'] ) ? ( $this->results['get_results'] )( $query, $output ) : $this->results['get_results'];
			}
			if ( $this->last_call_failed ) {
				return $this->fail_as_false ? false : array();
			}
			if ( false !== stripos( $query, 'FROM wp_posts' ) && false !== stripos( $query, 'post_title' ) ) {
				$callback = $GLOBALS['akb_runtime_raw_product_posts'] ?? null;
				return is_callable( $callback ) ? $callback( $query ) : array();
			}
			if ( false !== stripos( $query, 'FROM wp_term_relationships tr' ) ) {
				$callback = $GLOBALS['akb_runtime_raw_term_relationships'] ?? null;
				return is_callable( $callback ) ? $callback( $query ) : array();
			}
			if ( false !== stripos( $query, 'FROM wp_terms t' ) ) {
				$callback = false !== stripos( $query, 't.slug =' )
					? ( $GLOBALS['akb_runtime_raw_term_slug'] ?? null )
					: ( $GLOBALS['akb_runtime_raw_term_ancestors'] ?? null );
				return is_callable( $callback ) ? $callback( $query ) : array();
			}
			if ( ARRAY_A === $output && false !== stripos( $query, 'title_norm' ) ) {
				return $this->documents;
			}
			return array();
		}

		/** @return array<string, mixed>|object|null */
		public function get_row( string $query, $output = null ) {
			$this->record( 'get_row', $query );
			if ( array_key_exists( 'get_row', $this->results ) ) {
				return is_callable( $this->results['get_row'] ) ? ( $this->results['get_row'] )( $query, $output ) : $this->results['get_row'];
			}
			if ( $this->last_call_failed && $this->fail_as_false ) {
				return false;
			}
			if ( null !== $output ) {
				return array();
			}
			return (object) array(
				'c' => '0',
				'm' => '0',
			);
		}

		/** @return string|null */
		public function get_var( string $query ) {
			$this->record( 'get_var', $query );
			if ( array_key_exists( 'get_var', $this->results ) ) {
				return is_callable( $this->results['get_var'] ) ? ( $this->results['get_var'] )( $query ) : $this->results['get_var'];
			}
			if ( $this->last_call_failed && $this->fail_as_false ) {
				return false;
			}
			if ( false !== stripos( $query, 'COUNT(*)' ) ) {
				return '0';
			}
			return null;
		}

		/** @return int|false */
		public function query( string $query ) {
			$this->record( 'query', $query );
			return $this->last_call_failed ? false : 1;
		}

		private function record( string $method, string $query ): void {
			$this->queries[] = $query;
			if ( $this->throw_method === $method
				&& ( '' === $this->throw_contains || false !== stripos( $query, $this->throw_contains ) ) ) {
				throw new \RuntimeException( 'forced throwable' );
			}
			$this->last_call_failed = $this->fail_method === $method
				&& ( '' === $this->fail_contains || false !== stripos( $query, $this->fail_contains ) );
			$this->last_error = $this->last_call_failed && ! $this->fail_as_false ? 'forced read failure' : '';
		}
	}

	/** @covers \Akibara\Search\QueryEngine @covers \Akibara\Search\FacetService @covers \Akibara\Search\ProductPresenter */
	final class RuntimeReadersFailClosedTest extends TestCase {

		/** @var array<string, mixed> */
		private array $options = array();

		private int $transient_writes = 0;

		/** @var array<int, RuntimeLiveProduct> */
		private array $live_products = array();

		/** @var array<int, string> */
		private array $product_read_errors = array();

		/** @var array<int, string> Foto cruda de status independiente del objeto WC. */
		private array $raw_post_statuses = array();

		/** @var array<int, int> Foto cruda de identidad independiente del objeto WC. */
		private array $raw_post_ids = array();

		/** @var array<int, string> Foto cruda de tipo independiente del objeto WC. */
		private array $raw_post_types = array();

		/** @var array<int, int> Foto cruda de parent independiente del objeto WC. */
		private array $raw_post_parents = array();

		/** @var array<int, array<string, mixed>> Campos de la fila SQL cruda. */
		private array $raw_post_fields = array();

		/** @var array<int, array<string, mixed>> Campos entregados por get_post tras el prime. */
		private array $cached_post_fields = array();

		/** @var array<int, string> */
		private array $permalink_read_errors = array();

		/** @var array<int, string> */
		private array $permalink_values = array();

		/** @var array<string, string> */
		private array $prime_errors = array();

		/** @var array<int, string> */
		private array $prime_calls = array();

		private bool $meta_prime_returns_false = false;

		/** @var mixed */
		private $term_prime_result = null;

		/** @var array<string, array<int, mixed>> Object cache simulado. */
		private array $source_cache = array();

		/** @var mixed */
		private $cache_delete_result = true;

		private bool $cache_delete_noop = false;

		private bool $cache_get_throw = false;

		private bool $cache_get_wp_error = false;

		private string $cache_get_error = '';

		/** @var array<string, array<int, array<string,mixed>>> */
		private array $cache_multiple_overrides = array();

		/** @var array<int, array<string, mixed>> */
		private array $object_term_args = array();

		/** @var array<int, array<string, mixed>> */
		private array $term_query_args = array();

		private bool $taxonomy_available = false;

		/** @var array<int, string> */
		private array $product_taxonomies = array();

		/** @var array<int, array<int, \WP_Term>> */
		private array $product_terms = array();

		/** @var array<int, \WP_Term> Ancestros que no son relación directa. */
		private array $term_catalog = array();

		/** @var array<int, array<int, int>> Respuesta pública filtrable de get_ancestors. */
		private array $public_ancestor_ids = array();

		/** @var array<string, array<int, array<string, mixed>>> */
		private array $structural_term_rows = array();

		/** @var array<int, array<string, mixed>> */
		private array $term_meta_values = array();

		/** @var array<int, string> */
		private array $price_events = array();

		/** @var array<int, int> */
		private array $hydrated_ids = array();

		/** @var array<int, string> */
		private array $litespeed_actions = array();

		/** @var array<string, callable> */
		private array $filter_callbacks = array();

		/** @var array<string, bool> */
		private array $throw_actions = array();

		/** @var mixed */
		private $transient_value = false;

		private bool $throw_transient_read = false;

		private bool $throw_transient_write = false;

		/** @var array<string, mixed> */
		private array $named_transients = array();

		/** @var array<string, bool> */
		private array $transient_delete_noop_names = array();

		/** @var array<int, string> */
		private array $deleted_transient_names = array();

		/** @var array{registered: bool, runtime: mixed, test_override: bool|null} */
		private array $publication_static = array();

		/** @var mixed */
		private $previous_wpdb;

		private bool $had_previous_wpdb = false;

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			Functions\when( 'nocache_headers' )->justReturn( null );
			$this->had_previous_wpdb = array_key_exists( 'wpdb', $GLOBALS );
			$this->previous_wpdb     = $GLOBALS['wpdb'] ?? null;
			$GLOBALS['wpdb']         = new RuntimeReadersWpdb();
			SemanticPublication::reset_authoritative_state_for_tests();
			$this->options = array(
				'akb_search_fuzzy' => 'no',
				'akb_search_index_gen' => 'generation-test',
				'akb_search_suggest' => 'yes',
				'akb_search_facets' => 'yes',
				'akb_search_precio_buckets' => array(
					array(
						'min'   => null,
						'max'   => 10000,
						'label' => 'Hasta $10.000',
					),
					array(
						'min'   => 10000,
						'max'   => null,
						'label' => 'Desde $10.000',
					),
				),
			);
			$this->transient_writes = 0;
			$this->live_products = array();
			$this->product_read_errors = array();
			$this->raw_post_statuses = array();
			$this->raw_post_ids = array();
			$this->raw_post_types = array();
			$this->raw_post_parents = array();
			$this->raw_post_fields = array();
			$this->cached_post_fields = array();
			$this->permalink_read_errors = array();
			$this->permalink_values = array();
			$this->prime_errors = array(
				'posts' => '',
				'meta'  => '',
				'terms' => '',
			);
			$this->prime_calls = array();
			$this->meta_prime_returns_false = false;
			$this->term_prime_result = null;
			$this->source_cache = array();
			$this->cache_delete_result = true;
			$this->cache_delete_noop = false;
			$this->cache_get_throw = false;
			$this->cache_get_wp_error = false;
			$this->cache_get_error = '';
			$this->cache_multiple_overrides = array();
			$this->object_term_args = array();
			$this->term_query_args = array();
			$this->taxonomy_available = false;
			$this->product_taxonomies = array( 'product_cat' );
			$this->product_terms = array();
			$this->term_catalog = array();
			$this->public_ancestor_ids = array();
			$this->structural_term_rows = array();
			$this->term_meta_values = array();
			$this->price_events = array();
			$this->hydrated_ids = array();
			$this->litespeed_actions = array();
			$this->filter_callbacks = array(
				'akb_search_suggest_enabled' => static fn(): bool => true,
				'akb_search_facets_enabled'  => static fn(): bool => true,
			);
			$this->throw_actions = array();
			$this->transient_value = false;
			$this->throw_transient_read = false;
			$this->throw_transient_write = false;
			$this->named_transients = array();
			$this->transient_delete_noop_names = array();
			$this->deleted_transient_names = array();
			$this->publication_static = array(
				'registered' => $this->publication_static_value( 'registered' ),
				'runtime'    => $this->publication_static_value( 'runtime' ),
				'test_override' => $this->publication_static_value( 'test_serving_override' ),
			);
			$this->set_publication_static_value( 'registered', false );
			$this->set_publication_static_value( 'runtime', null );
			EditorialDiscountPublication::set_test_serving_override( true );
			$GLOBALS['akb_runtime_get_terms'] = static fn() => array();
			$GLOBALS['akb_runtime_price_events'] = &$this->price_events;
			$GLOBALS['akb_runtime_wc_prefixes'] = array();
			$GLOBALS['akb_runtime_wc_prefix_calls'] = array();
			$GLOBALS['akb_runtime_wc_prefix_result'] = true;
			$GLOBALS['akb_runtime_product_object_cache'] = array();
			$GLOBALS['akb_runtime_product_cache_remove_calls'] = array();
			$GLOBALS['akb_runtime_product_cache_remove_result'] = true;
			$GLOBALS['akb_runtime_product_cache_noop'] = false;

			$options = &$this->options;
			$writes  = &$this->transient_writes;
			$products = &$this->live_products;
			$product_errors = &$this->product_read_errors;
			$raw_post_statuses = &$this->raw_post_statuses;
			$raw_post_ids = &$this->raw_post_ids;
			$raw_post_types = &$this->raw_post_types;
			$raw_post_parents = &$this->raw_post_parents;
			$raw_post_fields = &$this->raw_post_fields;
			$cached_post_fields = &$this->cached_post_fields;
			$permalink_errors = &$this->permalink_read_errors;
			$permalink_values = &$this->permalink_values;
			$prime_errors = &$this->prime_errors;
			$prime_calls = &$this->prime_calls;
			$meta_prime_returns_false = &$this->meta_prime_returns_false;
			$term_prime_result = &$this->term_prime_result;
			$source_cache = &$this->source_cache;
			$cache_delete_result = &$this->cache_delete_result;
			$cache_delete_noop = &$this->cache_delete_noop;
			$cache_get_throw = &$this->cache_get_throw;
			$cache_get_wp_error = &$this->cache_get_wp_error;
			$cache_get_error = &$this->cache_get_error;
			$cache_multiple_overrides = &$this->cache_multiple_overrides;
			$object_term_args = &$this->object_term_args;
			$term_query_args = &$this->term_query_args;
			$taxonomy_available = &$this->taxonomy_available;
			$product_taxonomies = &$this->product_taxonomies;
			$product_terms = &$this->product_terms;
			$term_catalog = &$this->term_catalog;
			$public_ancestor_ids = &$this->public_ancestor_ids;
			$structural_term_rows = &$this->structural_term_rows;
			$term_meta_values = &$this->term_meta_values;
			$price_events = &$this->price_events;
			$hydrated = &$this->hydrated_ids;
			$actions = &$this->litespeed_actions;
			$filter_callbacks = &$this->filter_callbacks;
			$throw_actions = &$this->throw_actions;
			$transient_value = &$this->transient_value;
			$throw_transient_read = &$this->throw_transient_read;
			$throw_transient_write = &$this->throw_transient_write;
			$named_transients = &$this->named_transients;
			$transient_delete_noop_names = &$this->transient_delete_noop_names;
			$deleted_transient_names = &$this->deleted_transient_names;
			Functions\when( 'get_option' )->alias(
				static function ( string $name, $default = false ) use ( &$options ) {
					return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
				}
			);
			Functions\when( 'wc_get_container' )->justReturn( new RuntimeWooContainer() );
			Functions\when( 'delete_transient' )->alias(
				static function ( string $name ) use ( &$price_events, &$named_transients, &$transient_delete_noop_names, &$deleted_transient_names ): bool {
					$deleted_transient_names[] = $name;
					$price_events[] = 'variable_price_transient_delete';
					if ( empty( $transient_delete_noop_names[ $name ] ) ) {
						unset( $named_transients[ $name ] );
					}
					return true;
				}
			);
			Functions\when( 'get_transient' )->alias(
				static function ( string $name ) use ( &$transient_value, &$throw_transient_read, &$named_transients ) {
					if ( $throw_transient_read ) {
						throw new \RuntimeException( 'forced transient read throwable' );
					}
					if ( array_key_exists( $name, $named_transients ) ) {
						return $named_transients[ $name ];
					}
					return $transient_value;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function () use ( &$writes, &$throw_transient_write ): bool {
					if ( $throw_transient_write ) {
						throw new \RuntimeException( 'forced transient write throwable' );
					}
					++$writes;
					return true;
				}
			);
			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, ...$args ) use ( &$price_events, &$filter_callbacks ) {
					if ( isset( $filter_callbacks[ $hook ] ) ) {
						return $filter_callbacks[ $hook ]( $value, ...$args );
					}
					if ( 'akb_search_effective_price' === $hook ) {
						$price_events[] = 'provider';
					}
					return $value;
				}
			);
			$GLOBALS['akb_runtime_raw_product_posts'] = static function ( string $query ) use ( &$products, &$raw_post_statuses, &$raw_post_ids, &$raw_post_types, &$raw_post_parents, &$raw_post_fields ): array {
				preg_match( '/\bID IN \(([^)]*)\)/', $query, $match );
				$requested = array_fill_keys( array_map( 'intval', preg_split( '/\s*,\s*/', $match[1] ?? '', -1, PREG_SPLIT_NO_EMPTY ) ?: array() ), true );
				$rows = array();
				foreach ( $products as $id => $product ) {
					if ( ! isset( $requested[ $id ] ) ) {
						continue;
					}
					$fields = array_merge(
						array(
							'ID'           => (string) ( $raw_post_ids[ $id ] ?? $id ),
							'post_type'    => $raw_post_types[ $id ] ?? ( $product->variation ? 'product_variation' : 'product' ),
							'post_status'  => $raw_post_statuses[ $id ] ?? $product->status,
							'post_title'   => $product->name,
							'post_excerpt' => $product->short_description,
							'post_content' => $product->description,
							'menu_order'   => (string) $product->menu_order,
							'post_name'    => $product->canonical_post_name(),
							'post_date'    => $product->post_date,
							'post_date_gmt' => $product->post_date_gmt,
							'post_modified' => $product->post_modified,
							'post_modified_gmt' => $product->post_modified_gmt,
							'post_password' => $product->post_password,
							'comment_status' => $product->comment_status,
						),
						$raw_post_fields[ $id ] ?? array()
					);
					$fields['post_parent'] = (string) ( $raw_post_parents[ $id ] ?? $product->parent_id );
					$rows[] = $fields;
				}
				return $rows;
			};
			Functions\when( 'get_post' )->alias(
				static function ( int $id ) use ( &$products, &$raw_post_statuses, &$raw_post_ids, &$raw_post_types, &$raw_post_parents, &$raw_post_fields, &$cached_post_fields ) {
					if ( ! isset( $products[ $id ] ) ) {
						return null;
					}
					$fields = array_merge(
						array(
							'post_title'   => $raw_post_fields[ $id ]['post_title'] ?? $products[ $id ]->name,
							'post_excerpt' => $raw_post_fields[ $id ]['post_excerpt'] ?? $products[ $id ]->short_description,
							'post_content' => $raw_post_fields[ $id ]['post_content'] ?? $products[ $id ]->description,
							'menu_order'   => isset( $raw_post_fields[ $id ]['menu_order'] ) ? (int) $raw_post_fields[ $id ]['menu_order'] : $products[ $id ]->menu_order,
							'post_name'    => $raw_post_fields[ $id ]['post_name'] ?? $products[ $id ]->canonical_post_name(),
							'post_date'    => $raw_post_fields[ $id ]['post_date'] ?? $products[ $id ]->post_date,
							'post_date_gmt' => $raw_post_fields[ $id ]['post_date_gmt'] ?? $products[ $id ]->post_date_gmt,
							'post_modified' => $raw_post_fields[ $id ]['post_modified'] ?? $products[ $id ]->post_modified,
							'post_modified_gmt' => $raw_post_fields[ $id ]['post_modified_gmt'] ?? $products[ $id ]->post_modified_gmt,
							'post_password' => $raw_post_fields[ $id ]['post_password'] ?? $products[ $id ]->post_password,
							'comment_status' => $raw_post_fields[ $id ]['comment_status'] ?? $products[ $id ]->comment_status,
						),
						$cached_post_fields[ $id ] ?? array()
					);
					$status  = $cached_post_fields[ $id ]['post_status'] ?? ( $raw_post_statuses[ $id ] ?? $products[ $id ]->get_status() );
					$type    = $cached_post_fields[ $id ]['post_type'] ?? ( $raw_post_types[ $id ] ?? ( $products[ $id ]->variation ? 'product_variation' : 'product' ) );
					$parent  = $cached_post_fields[ $id ]['post_parent'] ?? ( $raw_post_parents[ $id ] ?? $products[ $id ]->parent_id );
					$post_id = $cached_post_fields[ $id ]['ID'] ?? ( $raw_post_ids[ $id ] ?? $id );
					return new \WP_Post(
						$type,
						$parent,
						$status,
						$post_id,
						$fields['post_title'],
						$fields['post_excerpt'],
						$fields['post_content'],
						$fields['menu_order'],
						$fields['post_name'],
						$fields['post_date'],
						$fields['post_date_gmt'],
						$fields['post_modified'],
						$fields['post_modified_gmt'],
						$fields['post_password'],
						$fields['comment_status']
					);
				}
			);
			Functions\when( 'wc_get_product' )->alias(
				static function ( int $id ) use ( &$products, &$product_errors, &$hydrated ) {
					$hydrated[] = $id;
					if ( isset( $product_errors[ $id ] ) && isset( $GLOBALS['wpdb']->last_error ) ) {
						$GLOBALS['wpdb']->last_error = $product_errors[ $id ];
					}
					return $products[ $id ] ?? false;
				}
			);
			Functions\when( 'get_permalink' )->alias(
				static function ( int $id ) use ( &$permalink_errors, &$permalink_values ): string {
					if ( isset( $permalink_errors[ $id ] ) && isset( $GLOBALS['wpdb']->last_error ) ) {
						$GLOBALS['wpdb']->last_error = $permalink_errors[ $id ];
					}
					return $permalink_values[ $id ] ?? 'https://akibara.test/producto/' . $id . '/';
				}
			);
			// `ProductPresenter::prime_thumbnail_sources()` lee la portada de cada
			// producto desde el meta ya cacheado para calentar los adjuntos en un
			// solo viaje. Sin este doble, el presentador se cae con «undefined
			// function» y todas las pruebas del lote fallan por el andamio, no por
			// el comportamiento que vigilan.
			Functions\when( 'get_post_meta' )->alias(
				static function ( int $id, string $key = '', bool $single = false ): string {
					unset( $id, $single );

					// El harness no modela portadas: devolver vacío deja el prime
					// como no-op y las pruebas siguen midiendo lo suyo. Lo que se
					// verifica aquí es que el presentador no se caiga por leerlo.
					return '_thumbnail_id' === $key ? '' : '';
				}
			);
			Functions\when( '_prime_post_caches' )->alias(
				static function ( array $ids, bool $terms = true, bool $meta = true ) use ( &$prime_errors, &$prime_calls ): void {
					unset( $ids );
					$prime_calls[] = 'posts:' . ( $terms ? '1' : '0' ) . ':' . ( $meta ? '1' : '0' );
					$GLOBALS['wpdb']->last_error = $prime_errors['posts'];
				}
			);
			Functions\when( 'update_postmeta_cache' )->alias(
				static function ( array $ids ) use ( &$prime_errors, &$prime_calls, &$meta_prime_returns_false, &$source_cache ) {
					$prime_calls[] = 'meta';
					$GLOBALS['wpdb']->last_error = $prime_errors['meta'];
					if ( $meta_prime_returns_false || '' !== $prime_errors['meta'] ) {
						return false;
					}
					$source_cache['post_meta'] = array_fill_keys( $ids, array() );
					return $source_cache['post_meta'];
				}
			);
			Functions\when( 'wp_get_object_terms' )->alias(
				static function ( array $ids, array $taxonomies, array $args ) use ( &$prime_errors, &$prime_calls, &$term_prime_result, &$product_terms, &$object_term_args ) {
					$prime_calls[] = 'terms';
					$object_term_args[] = $args;
					$GLOBALS['wpdb']->last_error = $prime_errors['terms'];
					if ( null !== $term_prime_result ) {
						return $term_prime_result;
					}
					$callback = $GLOBALS['akb_runtime_wp_get_object_terms'] ?? null;
					if ( is_callable( $callback ) ) {
						return $callback( $ids, $taxonomies, $args );
					}
					$out = array();
					foreach ( $ids as $id ) {
						$out = array_merge( $out, $product_terms[ $id ] ?? array() );
					}
					return $out;
				}
			);
			Functions\when( 'wp_cache_delete' )->alias(
				static function ( int $id, string $group = '' ) use ( &$source_cache, &$cache_delete_result, &$cache_delete_noop ) {
					if ( ! $cache_delete_noop ) {
						unset( $source_cache[ $group ][ $id ] );
					}
					return $cache_delete_result;
				}
			);
			$GLOBALS['akb_runtime_wp_cache_get'] = static function ( $id, string $group = '', bool $force = false, &$found = null ) use ( &$source_cache, &$cache_get_throw, &$cache_get_wp_error, &$cache_get_error ) {
					unset( $force );
					if ( $cache_get_throw ) {
						throw new \RuntimeException( 'forced cache verification throwable' );
					}
					$GLOBALS['wpdb']->last_error = $cache_get_error;
					$found = array_key_exists( $id, $source_cache[ $group ] ?? array() );
					if ( $cache_get_wp_error ) {
						return new \WP_Error();
					}
					return $found ? $source_cache[ $group ][ $id ] : false;
				};
			Functions\when( 'wp_cache_set' )->alias(
				static function ( $key, $value, string $group = '', int $expire = 0 ) use ( &$source_cache ): bool {
					unset( $expire );
					$source_cache[ $group ][ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'wp_cache_set_multiple' )->alias(
				static function ( array $values, string $group = '' ) use ( &$source_cache ): array {
					$written = array();
					foreach ( $values as $id => $value ) {
						$source_cache[ $group ][ (int) $id ] = $value;
						$written[ (int) $id ] = true;
					}
					return $written;
				}
			);
			Functions\when( 'wp_cache_get_multiple' )->alias(
				static function ( array $ids, string $group = '' ) use ( &$source_cache, &$cache_multiple_overrides ): array {
					$out = array();
					foreach ( $ids as $id ) {
						$out[ $id ] = $cache_multiple_overrides[ $group ][ $id ]
							?? ( $source_cache[ $group ][ $id ] ?? false );
					}
					return $out;
				}
			);
			Functions\when( 'update_meta_cache' )->alias(
				static function ( string $type, array $ids ) use ( &$source_cache, &$term_meta_values ) {
					if ( 'term' !== $type ) {
						return false;
					}
					foreach ( $ids as $id ) {
						$source_cache['term_meta'][ $id ] = $term_meta_values[ $id ] ?? array();
					}
					return $source_cache['term_meta'];
				}
			);
			Functions\when( 'get_terms' )->alias(
				static function ( array $args ) use ( &$product_terms, &$term_catalog, &$term_query_args ) {
					$term_query_args[] = $args;
					if ( isset( $args['include'] ) && is_array( $args['include'] ) ) {
						$include_callback = $GLOBALS['akb_runtime_get_included_terms'] ?? null;
						if ( is_callable( $include_callback ) ) {
							return $include_callback( $args );
						}
						$include = array_fill_keys( array_map( 'intval', $args['include'] ), true );
						$out = array();
						foreach ( $product_terms as $terms ) {
							foreach ( $terms as $term ) {
								if ( isset( $include[ (int) $term->term_id ] ) ) {
									$out[ (int) $term->term_id ] = $term;
								}
							}
						}
						foreach ( $term_catalog as $term ) {
							if ( isset( $include[ (int) $term->term_id ] ) ) {
								$out[ (int) $term->term_id ] = $term;
							}
						}
						return array_values( $out );
					}
					$callback = $GLOBALS['akb_runtime_get_terms'] ?? null;
					return is_callable( $callback ) ? $callback( $args ) : array();
				}
			);
			Functions\when( 'get_the_terms' )->alias(
				static function ( int $product_id, string $taxonomy ) use ( &$product_terms ) {
					$terms = array_values(
						array_filter(
							$product_terms[ $product_id ] ?? array(),
							static fn( $term ): bool => $term instanceof \WP_Term && $term->taxonomy === $taxonomy
						)
					);
					return array() === $terms ? false : $terms;
				}
			);
			Functions\when( 'get_ancestors' )->alias(
				static function ( int $term_id ) use ( &$public_ancestor_ids ): array {
					return $public_ancestor_ids[ $term_id ] ?? array();
				}
			);
			Functions\when( 'get_term' )->alias(
				static function ( int $term_id ) use ( &$term_catalog ) {
					return $term_catalog[ $term_id ] ?? null;
				}
			);
			$GLOBALS['akb_runtime_raw_term_relationships'] = static function () use ( &$prime_errors, &$prime_calls, &$product_terms, &$term_prime_result ) {
				$prime_calls[] = 'terms';
				$GLOBALS['wpdb']->last_error = $prime_errors['terms'];
				if ( null !== $term_prime_result ) {
					return $term_prime_result;
				}
				$rows = array();
				foreach ( $product_terms as $product_id => $terms ) {
					foreach ( $terms as $term ) {
						$rows[] = array(
							'object_id' => (string) $product_id,
							'term_id'   => (string) $term->term_id,
							'name'      => (string) $term->name,
							'slug'      => (string) $term->slug,
							'taxonomy'  => (string) $term->taxonomy,
							'parent'    => (string) $term->parent,
						);
					}
				}
				return $rows;
			};
			$GLOBALS['akb_runtime_raw_term_ancestors'] = static function () use ( &$product_terms, &$term_catalog ): array {
				$all = $term_catalog;
				foreach ( $product_terms as $terms ) {
					foreach ( $terms as $term ) {
						$all[ (int) $term->term_id ] = $term;
					}
				}
				$rows = array();
				foreach ( $all as $term ) {
					$rows[] = array(
						'term_id'  => (string) $term->term_id,
						'name'     => (string) $term->name,
						'slug'     => (string) $term->slug,
						'taxonomy' => (string) $term->taxonomy,
						'parent'   => (string) $term->parent,
					);
				}
				return $rows;
			};
			$GLOBALS['akb_runtime_raw_term_slug'] = static function ( string $query ) use ( &$structural_term_rows ) {
				foreach ( $structural_term_rows as $slug => $rows ) {
					if ( false !== strpos( $query, "t.slug = '" . str_replace( "'", "''", $slug ) . "'" ) ) {
						return $rows;
					}
				}
				$callback = $GLOBALS['akb_runtime_get_terms'] ?? null;
				if ( is_callable( $callback ) ) {
					preg_match( "/t\\.slug = '((?:''|[^'])*)'/", $query, $slug_match );
					preg_match( "/tt\\.taxonomy = '((?:''|[^'])*)'/", $query, $taxonomy_match );
					$result = $callback(
						array(
							'slug' => str_replace( "''", "'", $slug_match[1] ?? '' ),
							'taxonomy' => str_replace( "''", "'", $taxonomy_match[1] ?? '' ),
						)
					);
					if ( ! is_array( $result ) ) {
						return $result;
					}
					return array_map(
						static fn( \WP_Term $term ): array => array(
							'term_id' => (string) $term->term_id,
							'taxonomy' => (string) $term->taxonomy,
							'parent' => (string) $term->parent,
							'slug' => (string) $term->slug,
							'name' => (string) $term->name,
						),
						$result
					);
				}
				return array();
			};
			Functions\when( 'wp_get_attachment_image_url' )->alias(
				static fn( int $id, string $size ): string => 'https://akibara.test/media/' . $id . '-' . $size . '.jpg'
			);
			Functions\when( 'wc_placeholder_img_src' )->justReturn( '' );
			Functions\when( 'wp_kses_post' )->alias( static fn( string $html ): string => $html );
			Functions\when( 'is_wp_error' )->alias( static fn( $value ): bool => $value instanceof \WP_Error );
			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'is_admin' )->justReturn( false );
			Functions\when( 'is_tax' )->justReturn( false );
			Functions\when( 'taxonomy_exists' )->alias(
				static function ( string $taxonomy ) use ( &$taxonomy_available ): bool {
					return 'product_cat' === $taxonomy && $taxonomy_available;
				}
			);
			Functions\when( 'get_object_taxonomies' )->alias( static function () use ( &$product_taxonomies ): array {
				return $product_taxonomies;
			} );
			Functions\when( 'wp_unslash' )->alias( static fn( $value ) => $value );
			Functions\when( 'sanitize_title' )->alias(
				static fn( string $value ): string => strtolower( trim( preg_replace( '/[^a-z0-9-]+/i', '-', $value ) ?? '', '-' ) )
			);
			Functions\when( 'sanitize_text_field' )->alias( static fn( string $value ): string => trim( $value ) );
			Functions\when( 'esc_html' )->alias( static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ) );
			Functions\when( 'add_action' )->justReturn( true );
			Functions\when( 'add_filter' )->justReturn( true );
			Functions\when( 'do_action' )->alias(
				static function ( string $hook ) use ( &$actions, &$throw_actions ): void {
					if ( ! empty( $throw_actions[ $hook ] ) ) {
						throw new \RuntimeException( 'forced action throwable' );
					}
					if ( str_starts_with( $hook, 'litespeed_' ) ) {
						$actions[] = $hook;
					}
				}
			);
			Fuzzy::flush();
			Synonyms::flush();
			Stopwords::flush();
			Novedad::reset_cache();
			FacetRegistry::reset_cache();
		}

		protected function tearDown(): void {
			// La foto semántica es estado ESTÁTICO de la clase: una prueba que
			// adopta un snapshot inválido (p. ej. la de los hooks de flags, que
			// adopta 'invalid-generation' con sinónimos rotos) la dejaba así para
			// la clase siguiente. Con la semilla 424242 eso tumbaba 50 pruebas de
			// IndexerProtocolV2Test y DrenajeVivoSinPublicarTest (QA 2026-09-24,
			// H2-search-contaminacion-semantica). Se deja como se encontró.
			SemanticPublication::reset_authoritative_state_for_tests();
			Fuzzy::flush();
			Synonyms::flush();
			Stopwords::flush();
			Novedad::reset_cache();
			FacetRegistry::reset_cache();
			$this->set_publication_static_value( 'registered', $this->publication_static['registered'] );
			$this->set_publication_static_value( 'runtime', $this->publication_static['runtime'] );
			EditorialDiscountPublication::set_test_serving_override( $this->publication_static['test_override'] );
			unset( $GLOBALS['akb_runtime_get_terms'], $GLOBALS['akb_runtime_wp_get_object_terms'], $GLOBALS['akb_runtime_get_included_terms'], $GLOBALS['akb_runtime_wp_cache_get'], $GLOBALS['akb_runtime_price_events'], $GLOBALS['akb_runtime_raw_product_posts'], $GLOBALS['akb_runtime_raw_term_relationships'], $GLOBALS['akb_runtime_raw_term_ancestors'], $GLOBALS['akb_runtime_raw_term_slug'], $GLOBALS['akb_runtime_wc_prefixes'], $GLOBALS['akb_runtime_wc_prefix_calls'], $GLOBALS['akb_runtime_wc_prefix_result'], $GLOBALS['akb_runtime_product_object_cache'], $GLOBALS['akb_runtime_product_cache_remove_calls'], $GLOBALS['akb_runtime_product_cache_remove_result'], $GLOBALS['akb_runtime_product_cache_noop'] );
			$_GET = array();
			if ( $this->had_previous_wpdb ) {
				$GLOBALS['wpdb'] = $this->previous_wpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
			Monkey\tearDown();
			parent::tearDown();
		}

		/** @testdox Cada lectura SQL del motor distingue vacío real de last_error y limpia Fuzzy. */
		public function test_query_engine_sql_readers_fail_closed_and_flush_fuzzy(): void {
			$cases = array(
				'candidates' => array(
					'method'   => 'candidates',
					'args'     => array( array( 'manga' ), false ),
					'db_method' => 'get_col',
					'needle'   => 'SELECT DISTINCT d.post_id FROM',
				),
				'synonyms' => array(
					'method'   => 'synonym_candidates',
					'args'     => array(
						array(
							array(
								array( 'tokens' => array( 'manga' ) ),
								array( 'tokens' => array( 'comic' ) ),
							),
						),
						false,
					),
					'db_method' => 'get_col',
					'needle'   => 'UNION',
				),
				'sku' => array(
					'method'   => 'sku_fast_path',
					'args'     => array( '9781234567890' ),
					'db_method' => 'get_var',
					'needle'   => 'sku_norm',
				),
				'documents' => array(
					'method'   => 'load_docs',
					'args'     => array( array( 7 ) ),
					'db_method' => 'get_results',
					'needle'   => 'title_norm',
				),
				'vocabulary' => array(
					'method'   => 'title_vocabulary',
					'args'     => array(),
					'db_method' => 'get_col',
					'needle'   => 'SELECT DISTINCT token',
				),
			);

			foreach ( $cases as $name => $case ) {
				$db                = new RuntimeReadersWpdb();
				$db->fail_method   = $case['db_method'];
				$db->fail_contains = $case['needle'];
				$engine            = new QueryEngine( $db );
				$GLOBALS['wpdb']   = $db;
				Fuzzy::note_correction( array( 'jujustu' => 'jujutsu' ) );

				$this->invoke_private( $engine, $case['method'], $case['args'] );
				$queries_after_failure = count( $db->queries );
				$this->invoke_private( $engine, 'title_vocabulary', array() );

				self::assertFalse( $engine->is_available(), $name . ' debe cerrar el motor.' );
				self::assertSame( 'forced read failure', $engine->last_error(), $name . ' debe conservar el diagnóstico.' );
				self::assertSame( array(), Fuzzy::last_correction(), $name . ' no puede dejar corrección stale.' );
				self::assertCount( $queries_after_failure, $db->queries, $name . ' no puede leer de nuevo tras el primer fallo.' );
			}

			self::assertSame( 0, $this->transient_writes, 'El vocabulario fallido jamás se transienta como array vacío.' );
		}

		/** @testdox Un resultado vacío sin last_error sigue siendo una lectura válida del motor. */
		public function test_query_engine_real_empty_result_remains_available(): void {
			$db             = new RuntimeReadersWpdb();
			$db->last_error = 'stale error from another query';
			$engine         = new QueryEngine( $db );
			$GLOBALS['wpdb'] = $db;

			$result = $this->invoke_private( $engine, 'candidates', array( array( 'sinresultado' ), false ) );

			self::assertSame( array(), $result );
			self::assertTrue( $engine->is_available(), 'begin_read debe limpiar errores anteriores.' );
			self::assertSame( '', $engine->last_error() );
		}

		/** @testdox False sin last_error también cierra todos los lectores públicos. */
		public function test_false_without_last_error_fails_closed(): void {
			$engine_db                = new RuntimeReadersWpdb();
			$engine_db->fail_method   = 'get_col';
			$engine_db->fail_contains = 'SELECT DISTINCT d.post_id';
			$engine_db->fail_as_false = true;
			$GLOBALS['wpdb']          = $engine_db;
			$engine                   = new QueryEngine( $engine_db );
			self::assertSame( array(), $this->invoke_private( $engine, 'candidates', array( array( 'manga' ), false ) ) );
			self::assertFalse( $engine->is_available() );

			$facet_db                = new RuntimeReadersWpdb();
			$facet_db->fail_method   = 'get_col';
			$facet_db->fail_contains = 'SELECT DISTINCT d.post_id';
			$facet_db->fail_as_false = true;
			$GLOBALS['wpdb']         = $facet_db;
			$facets                  = new FacetService( $facet_db );
			self::assertSame( array(), $facets->filter( new FilterSet( array() ) ) );
			self::assertFalse( $facets->is_available() );

		}

		/** @testdox Cada lectura SQL de facetas falla cerrado sin memo ni transient de cero. */
		public function test_facet_service_sql_readers_fail_closed_without_caching_zero(): void {
			$filters = new FilterSet( array() );
			$cases   = array(
				'filter' => array( 'method' => 'filter', 'args' => array( $filters, null ), 'db_method' => 'get_col', 'needle' => 'SELECT DISTINCT d.post_id' ),
				'facet counts' => array( 'method' => 'count_facets', 'args' => array( array( 'categoria' ), $filters, null ), 'db_method' => 'get_results', 'needle' => 'GROUP BY fx.facet' ),
				'price counts' => array( 'method' => 'price_counts', 'args' => array( $filters, array(), null ), 'db_method' => 'get_row', 'needle' => "facet = 'precio'" ),
				'novelty counts' => array( 'method' => 'novedad_counts', 'args' => array( $filters, array(), null ), 'db_method' => 'get_row', 'needle' => "facet = 'novedad'" ),
				'empty probe' => array( 'method' => 'index_is_empty', 'args' => array(), 'db_method' => 'get_var', 'needle' => 'LIMIT 1' ),
				'cache stamp' => array( 'method' => 'counts_cache_stamp', 'args' => array(), 'db_method' => 'get_row', 'needle' => 'COUNT(*) c' ),
				'visible count' => array( 'method' => 'visible_docs_count', 'args' => array(), 'db_method' => 'get_var', 'needle' => 'COUNT(*)' ),
			);

			foreach ( $cases as $name => $case ) {
				$db                = new RuntimeReadersWpdb();
				$db->fail_method   = $case['db_method'];
				$db->fail_contains = $case['needle'];
				$facets            = new FacetService( $db );
				$GLOBALS['wpdb']   = $db;

				$this->invoke_private( $facets, $case['method'], $case['args'] );

				self::assertFalse( $facets->is_available(), $name . ' debe cerrar facetas.' );
				self::assertSame( 'forced read failure', $facets->last_error(), $name . ' debe conservar el diagnóstico.' );
			}

			$db                = new RuntimeReadersWpdb();
			$db->fail_method   = 'get_row';
			$db->fail_contains = 'COUNT(*) c';
			$facets            = new FacetService( $db );
			$GLOBALS['wpdb']   = $db;
			self::assertSame( array(), $facets->counts( $filters ) );
			self::assertSame( 0, $this->transient_writes, 'Un stamp fallido jamás cachea counts vacíos.' );
		}

		/** @testdox Un filtro realmente vacío sin error conserva el servicio disponible. */
		public function test_facet_service_real_empty_filter_remains_available(): void {
			$db             = new RuntimeReadersWpdb();
			$db->last_error = 'stale error from another query';
			$facets         = new FacetService( $db );
			$GLOBALS['wpdb'] = $db;

			self::assertSame( array(), $facets->filter( new FilterSet( array() ) ) );
			self::assertTrue( $facets->is_available() );
			self::assertSame( '', $facets->last_error() );
		}

		/** @testdox QueryEngine y FacetService rechazan IDs y filas no canónicas y absorben Throwables. */
		public function test_runtime_readers_reject_adversarial_shapes_and_throwables(): void {
			$engine_db                = new RuntimeReadersWpdb();
			$engine_db->candidate_ids = array( '7', '07' );
			$engine                    = new QueryEngine( $engine_db );
			$GLOBALS['wpdb']           = $engine_db;

			self::assertSame( array(), $engine->search( 'manga', false, 20, false ) );
			self::assertFalse( $engine->is_available(), 'IDs equivalentes/no canónicos no pueden deduplicarse por cast.' );

			$engine_db                 = new RuntimeReadersWpdb();
			$engine_db->throw_method   = 'get_col';
			$engine_db->throw_contains = 'SELECT DISTINCT d.post_id';
			$engine                    = new QueryEngine( $engine_db );
			self::assertSame( array(), $engine->search( 'manga', false, 20, false ) );
			self::assertFalse( $engine->is_available() );

			$facet_db                 = new RuntimeReadersWpdb();
			$facet_db->results['get_col'] = array( '7', '07' );
			$facets                   = new FacetService( $facet_db );
			$GLOBALS['wpdb']          = $facet_db;
			self::assertSame( array(), $facets->filter( new FilterSet( array() ) ) );
			self::assertFalse( $facets->is_available() );

			$facet_db = new RuntimeReadersWpdb();
			$facet_db->results['get_results'] = array(
				(object) array( 'facet' => 'categoria', 'value' => 'manga', 'label' => 'Manga', 'c' => '01' ),
			);
			$facets          = new FacetService( $facet_db );
			$GLOBALS['wpdb'] = $facet_db;
			self::assertSame(
				array(),
				$this->invoke_private( $facets, 'count_facets', array( array( 'categoria' ), new FilterSet( array() ), null ) )
			);
			self::assertFalse( $facets->is_available() );

			$facet_db = new RuntimeReadersWpdb();
			$facet_db->results['get_row'] = (object) array( 'c' => '01', 'm' => '0' );
			$facets          = new FacetService( $facet_db );
			$GLOBALS['wpdb'] = $facet_db;
			self::assertSame( array(), $facets->counts( new FilterSet( array() ) ) );
			self::assertFalse( $facets->is_available() );
		}

		/** @testdox Populares valida cache/SQL y trata los transients como optimización blanda. */
		public function test_popular_queries_fail_closed_on_bad_rows_but_ignore_transient_failures(): void {
			self::assertTrue(
				SemanticPublication::adopt_authoritative_snapshot(
					array(
						Install::OPTION_INDEX_GEN      => 'popular-generation',
						PopularQueries::OPTION_ENABLED => 'yes',
					)
				)
			);

			$db = new RuntimeReadersWpdb();
			$db->results['get_results'] = array( array( 'query_norm' => 'manga', 's' => '02' ) );
			$popular          = new PopularQueries( $db );
			$GLOBALS['wpdb'] = $db;
			self::assertSame( array(), $popular->top() );
			self::assertFalse( $popular->is_available() );

			$db = new RuntimeReadersWpdb();
			$db->results['get_results'] = array( array( 'query_norm' => 'manga', 's' => '2' ) );
			$popular                      = new PopularQueries( $db );
			$GLOBALS['wpdb']              = $db;
			$this->throw_transient_read   = true;
			$this->throw_transient_write  = true;
			self::assertSame( array( 'manga' ), $popular->top() );
			self::assertTrue( $popular->is_available() );
			self::assertSame( 0, $this->transient_writes );
		}

		/** @testdox Los hooks de flags exigen bool exacto y nunca reviven autoridad semántica inválida. */
		public function test_search_flag_hooks_are_exact_and_cannot_revive_invalid_semantics(): void {
			self::assertTrue(
				SemanticPublication::adopt_authoritative_snapshot(
					array(
						Install::OPTION_INDEX_GEN => 'flags-generation',
						Search::OPTION_INDEXING   => 'yes',
						Search::OPTION_SUGGEST    => 'yes',
						Search::OPTION_FACETS     => 'yes',
					)
				)
			);
			foreach (
				array(
					'akb_search_indexing_enabled' => 'indexing_enabled',
					'akb_search_suggest_enabled'  => 'suggest_configured',
					'akb_search_facets_enabled'   => 'facets_configured',
				) as $hook => $method
			) {
				$this->filter_callbacks[ $hook ] = static fn() => '1';
				self::assertFalse( Search::$method(), $hook . ' no acepta truthy no booleano.' );
				$this->filter_callbacks[ $hook ] = static function () {
					throw new \RuntimeException( 'forced flag hook throwable' );
				};
				self::assertFalse( Search::$method(), $hook . ' absorbe Throwable y apaga.' );
			}

			SemanticPublication::reset_authoritative_state_for_tests();
			self::assertFalse(
				SemanticPublication::adopt_authoritative_snapshot(
					array( Synonyms::OPTION => 'a:1:{broken', Install::OPTION_INDEX_GEN => 'invalid-generation' )
				)
			);
			$this->filter_callbacks['akb_search_suggest_enabled'] = static fn() => true;
			self::assertFalse( Search::suggest_configured(), 'El hook no revive un snapshot semántico corrupto.' );
		}

		/** @testdox Adoptar B invalida todos los memos derivados precargados con A. */
		public function test_semantic_snapshot_adoption_replaces_precached_consumers(): void {
			Functions\when( 'taxonomy_exists' )->justReturn( false );
			$a = array(
				Install::OPTION_INDEX_GEN      => 'semantic-a',
				Synonyms::OPTION               => serialize( array( array( 'opm', 'one punch man' ) ) ),
				Stopwords::OPTION              => serialize( array( 'de' ) ),
				FacetRegistry::OPTION_KEY      => serialize( array( 'categoria' => array( 'label' => 'Categoría A' ) ) ),
				Novedad::OPTION                => 'no',
			);
			$b = array(
				Install::OPTION_INDEX_GEN      => 'semantic-b',
				Synonyms::OPTION               => serialize( array( array( 'jjk', 'jujutsu kaisen' ) ) ),
				Stopwords::OPTION              => serialize( array( 'para' ) ),
				FacetRegistry::OPTION_KEY      => serialize( array( 'categoria' => array( 'label' => 'Categoría B' ) ) ),
				Novedad::OPTION                => 'yes',
			);

			self::assertTrue( SemanticPublication::adopt_authoritative_snapshot( $a ) );
			self::assertSame( array( array( 'opm', 'one punch man' ) ), Synonyms::groups() );
			self::assertTrue( Stopwords::is_stopword( 'de' ) );
			self::assertSame( 'Categoría A', FacetRegistry::definitions()['categoria']['label'] );
			self::assertFalse( Novedad::enabled() );

			self::assertTrue( SemanticPublication::adopt_authoritative_snapshot( $b ) );
			self::assertSame( array( array( 'jjk', 'jujutsu kaisen' ) ), Synonyms::groups() );
			self::assertFalse( Stopwords::is_stopword( 'de' ) );
			self::assertTrue( Stopwords::is_stopword( 'para' ) );
			self::assertSame( 'Categoría B', FacetRegistry::definitions()['categoria']['label'] );
			self::assertTrue( Novedad::enabled() );
		}

		/**
		 * R3-4199bd7c (2026-09-19): vaciar el textarea de «Palabras ignoradas» y
		 * guardar persiste `akb_search_stopwords = array()` — un valor válido y
		 * distinto de «la option no existe». Antes, `Stopwords::all()` trataba
		 * ese array vacío igual que una option corrupta y revivía los 25
		 * defaults de fábrica en la siguiente búsqueda (y en el propio recargo
		 * del panel). Se prueba con el MISMO camino que usa producción para
		 * publicar cambios (adopt_authoritative_snapshot con bytes serializados),
		 * no con un mock del método bajo prueba.
		 *
		 * @testdox Stopwords vacío a propósito se respeta, y ningún lector directo se rompe con la lista vacía.
		 */
		public function test_stopwords_vacio_no_revive_defaults_y_lectores_no_se_rompen(): void {
			self::assertTrue(
				SemanticPublication::adopt_authoritative_snapshot(
					array(
						Install::OPTION_INDEX_GEN => 'stopwords-vacio',
						Stopwords::OPTION         => serialize( array() ),
					)
				)
			);

			self::assertSame( array(), Stopwords::all(), 'El array vacío guardado a propósito se respeta; no se cae a defaults().' );
			self::assertFalse( Stopwords::is_stopword( 'el' ), 'Vacío es vacío: ni siquiera un default de fábrica ("el") se ignora ahora.' );
			self::assertFalse( Stopwords::is_stopword( 'la' ) );

			// Synonyms::significant_tokens() (Synonyms.php:481) es el otro lector
			// directo de Stopwords::is_stopword(): con la lista vacía no debe
			// fatalear, quedar en bucle, ni perder "la" (antes filtrada como
			// stopword de fábrica).
			$lector = new ReflectionMethod( Synonyms::class, 'significant_tokens' );
			$lector->setAccessible( true );
			self::assertSame( array( 'la', 'espada' ), $lector->invoke( null, 'la espada' ) );
		}

		/** @testdox La etiqueta legada Serie hereda el copy nuevo sin borrar personalizaciones reales. */
		public function test_legacy_series_label_inherits_current_copy_and_preserves_custom_label(): void {
			Functions\when( 'taxonomy_exists' )->justReturn( false );

			self::assertTrue(
				SemanticPublication::adopt_authoritative_snapshot(
					array(
						Install::OPTION_INDEX_GEN => 'series-copy-legacy',
						FacetRegistry::OPTION_KEY => serialize( array( 'serie' => array( 'label' => 'Serie' ) ) ),
					)
				)
			);
			self::assertSame( 'Serie o edición', FacetRegistry::definitions()['serie']['label'] );

			self::assertTrue(
				SemanticPublication::adopt_authoritative_snapshot(
					array(
						Install::OPTION_INDEX_GEN => 'series-copy-custom',
						FacetRegistry::OPTION_KEY => serialize( array( 'serie' => array( 'label' => 'Obras' ) ) ),
					)
				)
			);
			self::assertSame( 'Obras', FacetRegistry::definitions()['serie']['label'] );
		}

		/** @testdox El snapshot semántico rechaza sinónimos largos, overrides null y cambios de contrato seed. */
		public function test_semantic_snapshot_rejects_invalid_synonyms_and_facet_overrides(): void {
			self::assertNull(
				SemanticPublication::fingerprints_from_raw_bundle(
					array( Synonyms::OPTION => serialize( array( array( 'uno dos tres cuatro cinco', 'manga' ) ) ) )
				)
			);
			foreach ( array( 'visible', 'order', 'label', 'ui', 'parent_term', 'hierarchical', 'root_only' ) as $field ) {
				self::assertNull(
					SemanticPublication::fingerprints_from_raw_bundle(
						array( FacetRegistry::OPTION_KEY => serialize( array( 'categoria' => array( $field => null ) ) ) )
					),
					$field . ' no acepta null como si estuviera ausente.'
				);
			}
			self::assertNull(
				SemanticPublication::fingerprints_from_raw_bundle(
					array( FacetRegistry::OPTION_KEY => serialize( array( 'categoria' => array( 'type' => 'stock' ) ) ) )
				)
			);
		}

		/** @testdox Términos estructurales exigen una fila exacta, ID positivo y consulta uncached. */
		public function test_structural_terms_are_unique_uncached_and_sticky_on_absence_or_duplicates(): void {
			$this->taxonomy_available = true;
			$this->structural_term_rows = array(
				'manga' => array( array( 'term_id' => '28', 'taxonomy' => 'product_cat', 'parent' => '0', 'slug' => 'manga', 'name' => 'Manga' ) ),
				'comics' => array( array( 'term_id' => '81', 'taxonomy' => 'product_cat', 'parent' => '0', 'slug' => 'comics', 'name' => 'Comics' ) ),
			);
			FacetRegistry::reset_cache();
			$definitions = FacetRegistry::definitions();
			self::assertTrue( FacetRegistry::is_available() );
			self::assertSame( 28, $definitions['demografia']['parent_term'] ?? null );
			self::assertSame( 81, $definitions['sello']['parent_term'] ?? null );
			$slug_reads = array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( string $sql ): bool => false !== strpos( $sql, 't.slug =' ) ) );
			self::assertCount( 2, $slug_reads );
			self::assertStringContainsString( 'LIMIT 2', $slug_reads[0] );

			foreach (
				array(
					'empty'     => array(),
					'duplicate' => array(
						new \WP_Term( 'product_cat', 28, 0, 'manga', 'Manga' ),
						new \WP_Term( 'product_cat', 29, 0, 'manga', 'Manga duplicado' ),
					),
				) as $case => $rows
			) {
				$calls = count( $GLOBALS['wpdb']->queries );
				$this->structural_term_rows = array(
					'manga' => array_map(
						static fn( \WP_Term $term ): array => array(
							'term_id' => (string) $term->term_id,
							'taxonomy' => (string) $term->taxonomy,
							'parent' => (string) $term->parent,
							'slug' => (string) $term->slug,
							'name' => (string) $term->name,
						),
						$rows
					),
				);
				FacetRegistry::reset_cache();
				FacetRegistry::definitions();
				self::assertFalse( FacetRegistry::is_available(), $case );
				$calls_after_failure = count( $GLOBALS['wpdb']->queries );
				self::assertNull( FacetRegistry::authoritative_term_by_slug( 'manga', 'product_cat' ), $case );
				self::assertSame( $calls_after_failure, count( $GLOBALS['wpdb']->queries ), $case . ' queda sticky sin reabrir SQL.' );
			}
		}

		/** @testdox ProductPresenter usa solo WC vivo, preserva ranking y omite no publicables. */
		public function test_product_presenter_hydrates_live_fields_in_rank_order(): void {
			$db                 = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']    = $db;
			$first              = new RuntimeLiveProduct( 7 );
			$first->name        = 'Manga vivo 7';
			$first->sku         = ' ISBN-ABC ';
			$first->price_html  = '<span>$12.990</span>';
			$first->stock_status = 'onbackorder';
			$second             = new RuntimeLiveProduct( 8 );
			$second->name       = 'Manga vivo 8';
			$hidden             = new RuntimeLiveProduct( 9 );
			$hidden->status     = 'draft';
			$this->live_products = array(
				7 => $first,
				8 => $second,
				9 => $hidden,
			);

			$presenter = new ProductPresenter( $db );
			$cards    = $presenter->cards( array( 8, 7, 9, 8, 0 ) );

			self::assertTrue( $presenter->is_available() );
			self::assertSame( array( 8, 7 ), $this->hydrated_ids, 'Deduplica y excluye draft antes de hidratar WC.' );
			self::assertSame( array( 8, 7 ), array_column( $cards, 'id' ) );
			self::assertSame( 'Manga vivo 7', $cards[1]['title'] );
			self::assertSame( 'https://akibara.test/producto/7/', $cards[1]['url'] );
			self::assertSame( 'https://akibara.test/media/70-' . DocumentBuilder::THUMB_SIZE . '.jpg', $cards[1]['thumb'] );
			self::assertSame( '<span>$12.990</span>', $cards[1]['price_html'] );
			self::assertSame( 'isbn-abc', $cards[1]['sku'] );
			// «A pedido»: la palabra default del grupo reservable (separación
			// 1.109.0 de business); «Preventa» exige el filtro con lanzamiento
			// pendiente, que este runtime aislado no engancha.
			self::assertSame( 'A pedido', $cards[1]['badge'] );
			self::assertSame( 'order', $cards[1]['badge_tone'] );
			self::assertSame( 'a_pedido', $cards[1]['availability'] );
			self::assertSame( array( 'posts:0:0', 'meta', 'terms' ), $this->prime_calls );
			self::assertNotEmpty( $db->queries, 'La autoridad sí debe leer relaciones crudas.' );
			self::assertSame(
				array(),
				array_filter( $db->queries, static fn( string $sql ): bool => false !== strpos( $sql, 'title_norm' ) || false !== strpos( $sql, 'price_html FROM' ) ),
				'El presenter no relee columnas presentacionales del índice.'
			);
		}

		/** @testdox DocumentBuilder materializa la faceta «disponibilidad» con el valor comercial y su palabra. */
		public function test_document_builder_materializes_the_commercial_availability_facet(): void {
			$db                    = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']       = $db;
			$preventa              = new RuntimeLiveProduct( 74 );
			$preventa->stock_status = 'onbackorder';
			$this->live_products   = array( 74 => $preventa );
			$this->filter_callbacks['akb_search_availability_value'] = static fn( $value, $id ) => 74 === (int) $id ? 'preventa' : $value;

			Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
			$built = ( new DocumentBuilder() )->build( $preventa );

			$filas = array_values( array_filter( $built['facets'], static fn( array $f ): bool => 'disponibilidad' === $f['facet'] ) );
			self::assertCount( 1, $filas, 'Una sola fila de disponibilidad por producto.' );
			self::assertSame( 'preventa', $filas[0]['value'], 'La faceta filtra por el valor comercial; con el stock crudo el checkbox «Preventa» no encontraba nada.' );
			self::assertSame( 'Preventa', $filas[0]['label'] );

			$this->filter_callbacks['akb_search_availability_value'] = static fn( $value ) => $value;
			$built = ( new DocumentBuilder() )->build( $preventa );
			$filas = array_values( array_filter( $built['facets'], static fn( array $f ): bool => 'disponibilidad' === $f['facet'] ) );
			self::assertSame( array( 'a_pedido', 'A pedido' ), array( $filas[0]['value'], $filas[0]['label'] ), 'Sin business enganchado, todo reservable es «a pedido».' );
		}

		/** @testdox El badge vivo dice «Preventa» cuando business lo resuelve por ficha, igual que la faceta. */
		public function test_product_presenter_badge_follows_the_availability_provider(): void {
			$db                        = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']           = $db;
			$preventa                  = new RuntimeLiveProduct( 71 );
			$preventa->stock_status    = 'onbackorder';
			$pedido                    = new RuntimeLiveProduct( 72 );
			$pedido->stock_status      = 'onbackorder';
			$this->live_products       = array( 71 => $preventa, 72 => $pedido );
			$this->filter_callbacks['akb_search_availability_value'] = static fn( $value, $id ) => 71 === (int) $id ? 'preventa' : $value;

			$presenter = new ProductPresenter( $db );
			$cards     = $presenter->cards( array( 71, 72 ) );

			self::assertTrue( $presenter->is_available() );
			self::assertSame( array( 'Preventa', 'A pedido' ), array_column( $cards, 'badge' ), 'El badge decía «A pedido» sobre una preventa que la faceta del mismo índice ya distinguía.' );
			self::assertSame( array( 'pre', 'order' ), array_column( $cards, 'badge_tone' ) );
			self::assertSame( array( 'preventa', 'a_pedido' ), array_column( $cards, 'availability' ) );
		}

		/** @testdox Un estado de stock que WooCommerce no define invalida la respuesta viva en vez de inventar un badge. */
		public function test_product_presenter_fails_closed_on_an_unknown_stock_status(): void {
			$db                    = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']       = $db;
			$raro                  = new RuntimeLiveProduct( 73 );
			$raro->stock_status    = 'onrequest';
			$this->live_products   = array( 73 => $raro );

			$presenter = new ProductPresenter( $db );
			$cards     = $presenter->cards( array( 73 ) );

			self::assertFalse( $presenter->is_available() );
			self::assertSame( array(), $cards );
		}

		/** @testdox Un precio Woo vacío es ausencia válida y no invalida la tarjeta viva. */
		public function test_product_presenter_accepts_empty_woocommerce_price(): void {
			$db                      = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']         = $db;
			$product                 = new RuntimeLiveProduct( 79 );
			$product->price          = '';
			$this->live_products[79] = $product;

			$presenter = new ProductPresenter( $db );
			$cards     = $presenter->cards( array( 79 ) );

			self::assertTrue( $presenter->is_available() );
			self::assertSame( array( 79 ), array_column( $cards, 'id' ) );
			self::assertSame( '<b>$12.990</b>', $cards[0]['price_html'] ?? null );
			self::assertSame( array( 'price', 'provider', 'price_html' ), $this->price_events );
		}

		/** @testdox La imagen Woo acepta ausencia vacía e ID decimal canónico, pero no coerciones ambiguas. */
		public function test_product_presenter_canonicalizes_woocommerce_image_identity(): void {
			foreach (
				array(
					'empty'     => array( '', '', true ),
					'digit-id'  => array( '70', 'https://akibara.test/media/70-' . DocumentBuilder::THUMB_SIZE . '.jpg', true ),
					'zero-fill' => array( '070', '', false ),
					'malformed' => array( array( 70 ), '', false ),
				) as $case => list( $raw_id, $expected_thumb, $expected_available )
			) {
				$db                 = new RuntimeReadersWpdb();
				$GLOBALS['wpdb']    = $db;
				$product            = new RuntimeLiveProduct( 78 );
				$product->image_id  = $raw_id;
				$this->live_products = array( 78 => $product );
				$this->hydrated_ids = array();

				$presenter = new ProductPresenter( $db );
				$cards     = $presenter->cards( array( 78 ) );

				self::assertSame( $expected_available, $presenter->is_available(), $case );
				if ( $expected_available ) {
					self::assertSame( $expected_thumb, $cards[0]['thumb'] ?? null, $case );
				} else {
					self::assertSame( array(), $cards, $case );
				}
			}
		}

		/** @testdox DocumentBuilder comparte la normalización estricta de imagen con el presenter. */
		public function test_document_builder_canonicalizes_woocommerce_image_identity(): void {
			$method = new ReflectionMethod( DocumentBuilder::class, 'thumb_url' );
			$method->setAccessible( true );
			$builder = new DocumentBuilder();

			foreach (
				array(
					array( '', '' ),
					array( '70', 'https://akibara.test/media/70-' . DocumentBuilder::THUMB_SIZE . '.jpg' ),
				) as list( $raw_id, $expected )
			) {
				$product           = new RuntimeLiveProduct( 79 );
				$product->image_id = $raw_id;
				self::assertSame( $expected, $method->invoke( $builder, $product ), 'image=' . var_export( $raw_id, true ) );
			}

			$product           = new RuntimeLiveProduct( 79 );
			$product->image_id = '070';
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'invalid shape' );
			$method->invoke( $builder, $product );
		}

		/** @testdox ProductPresenter invalida la foto variable antes del primer getter de precio y sólo una vez. */
		public function test_product_presenter_prepares_variable_price_before_any_price_read(): void {
			$db              = new RuntimeReadersWpdb();
			$GLOBALS['wpdb'] = $db;
			$product         = new RuntimeLiveProduct( 71 );
			$product->variable = true;
			$child           = new RuntimeLiveProduct( 72 );
			$child->variation = true;
			$child->parent_id = 71;
			$this->live_products = array( 71 => $product, 72 => $child );
			$db->results['get_col'] = static fn( string $sql ): array => false !== strpos( $sql, 'post_status IN' ) ? array( '72' ) : array();
			$this->source_cache['posts'][72] = 'snapshot-a';
			$this->source_cache['post_meta'][72] = array( '_price' => array( '1' ) );
			$GLOBALS['akb_runtime_product_object_cache'][72] = 'snapshot-a';
			$this->named_transients = array(
				'wc_var_prices_71'       => array( 'snapshot-a' ),
				'wc_product_children_71' => array( 'all' => array( 99 ) ),
				'wc_var_prices_999'      => array( 'unrelated' ),
			);

			$presenter = new ProductPresenter( $db );
			self::assertCount( 1, $presenter->cards( array( 71 ) ) );
			self::assertTrue( $presenter->is_available() );
			$delete = array_search( 'variable_price_transient_delete', $this->price_events, true );
			self::assertIsInt( $delete );
			self::assertLessThan( array_search( 'price', $this->price_events, true ), $delete );
			self::assertLessThan( array_search( 'price_html', $this->price_events, true ), $delete );
			self::assertSame( 2, count( array_keys( $this->price_events, 'variable_price_transient_delete', true ) ) );
			self::assertSame( array( 'wc_var_prices_71', 'wc_product_children_71' ), $this->deleted_transient_names );
			self::assertArrayHasKey( 'wc_var_prices_999', $this->named_transients, 'La preparación selectiva no toca transients globales ni de otro producto.' );
			self::assertNotContains( 72, array_keys( $GLOBALS['akb_runtime_product_object_cache'] ) );
			self::assertContains( 72, $GLOBALS['akb_runtime_product_cache_remove_calls'] );
			self::assertContains( 'product_72', $GLOBALS['akb_runtime_wc_prefix_calls'] );
			self::assertSame( array(), $this->source_cache['post_meta'][72] ?? null, 'La meta stale A fue reemplazada por la foto primada B.' );
		}

		/** @testdox Un children transient que el backend no elimina cierra antes de leer precio. */
		public function test_variable_children_transient_delete_noop_fails_closed_before_price(): void {
			$db              = new RuntimeReadersWpdb();
			$GLOBALS['wpdb'] = $db;
			$product         = new RuntimeLiveProduct( 73 );
			$product->variable = true;
			$this->live_products[73] = $product;
			$this->named_transients['wc_product_children_73'] = array( 'all' => array( 999 ) );
			$this->transient_delete_noop_names['wc_product_children_73'] = true;

			$presenter = new ProductPresenter( $db );
			self::assertSame( array(), $presenter->cards( array( 73 ) ) );
			self::assertFalse( $presenter->is_available() );
			self::assertNotContains( 'price', $this->price_events );
			self::assertNotContains( 'price_html', $this->price_events );
			self::assertSame( array( 'wc_var_prices_73', 'wc_product_children_73' ), $this->deleted_transient_names );
		}

		/** @testdox La foto WP_Post exige ID exacto y strings crudos antes de hidratar WooCommerce. */
		public function test_product_source_rejects_wrong_post_identity_and_non_string_status(): void {
			foreach ( array( 'wrong_id', 'status_array' ) as $case ) {
				$db              = new RuntimeReadersWpdb();
				$GLOBALS['wpdb'] = $db;
				$this->live_products = array( 74 => new RuntimeLiveProduct( 74 ) );
				$this->raw_post_ids = 'wrong_id' === $case ? array( 74 => 999 ) : array();
				$this->raw_post_statuses = 'status_array' === $case ? array( 74 => array( 'publish' ) ) : array();
				$this->hydrated_ids = array();

				$presenter = new ProductPresenter( $db );
				self::assertSame( array(), $presenter->cards( array( 74 ) ), $case );
				self::assertFalse( $presenter->is_available(), $case );
				self::assertSame( array(), $this->hydrated_ids, $case . ' falla antes de wc_get_product.' );
			}
		}

		/** @testdox La fila posts B debe coincidir literalmente con el objeto re-primado antes de hidratar WC. */
		public function test_product_source_rejects_stale_post_cache_fields_against_raw_row(): void {
			foreach (
				array(
					'status'  => array( 'post_status' => 'draft' ),
					'title'   => array( 'post_title' => 'Snapshot A' ),
					'excerpt' => array( 'post_excerpt' => 'Snapshot A' ),
					'content' => array( 'post_content' => 'Snapshot A' ),
					'order'   => array( 'menu_order' => 99 ),
					'slug'    => array( 'post_name' => 'snapshot-a' ),
					'date'    => array( 'post_date' => '2025-12-31 23:59:59' ),
					'date_gmt' => array( 'post_date_gmt' => '2025-12-31 23:59:59' ),
					'modified' => array( 'post_modified' => '2025-12-31 23:59:59' ),
					'modified_gmt' => array( 'post_modified_gmt' => '2025-12-31 23:59:59' ),
					'parent'  => array( 'post_parent' => 12 ),
					'password' => array( 'post_password' => 'private' ),
					'comments' => array( 'comment_status' => 'closed' ),
				) as $case => $stale_fields
			) {
				$db                         = new RuntimeReadersWpdb();
				$GLOBALS['wpdb']            = $db;
				$this->live_products        = array( 75 => new RuntimeLiveProduct( 75 ) );
				$this->cached_post_fields   = array( 75 => $stale_fields );
				$this->hydrated_ids         = array();

				$presenter = new ProductPresenter( $db );
				self::assertSame( array(), $presenter->cards( array( 75 ) ), $case );
				self::assertFalse( $presenter->is_available(), $case );
				self::assertSame( array(), $this->hydrated_ids, $case . ' falla antes de wc_get_product y no parece ausencia.' );
			}
			$this->cached_post_fields = array();
		}

		/** @testdox Un getter WC filtrado no puede sustituir el título luego de verificar posts B. */
		public function test_product_presenter_rejects_title_substitution_after_raw_post_snapshot(): void {
			$db                         = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']            = $db;
			$product                    = new RuntimeLiveProduct( 76 );
			$product->name              = 'Snapshot A';
			$this->live_products[76]    = $product;
			$this->raw_post_fields[76]  = array( 'post_title' => 'Snapshot B' );

			$presenter = new ProductPresenter( $db );
			self::assertSame( array(), $presenter->cards( array( 76 ) ) );
			self::assertFalse( $presenter->is_available() );
			self::assertSame( array( 76 ), $this->hydrated_ids, 'La sustitución se detecta inmediatamente tras get_name.' );
			self::assertStringContainsString( 'field disagrees', $presenter->last_error() );
		}

		/** @testdox ProductPresenter acota a 14 hidrataciones y un ID ausente es vacío real. */
		public function test_product_presenter_caps_live_hydration_and_accepts_missing_product(): void {
			$db              = new RuntimeReadersWpdb();
			$GLOBALS['wpdb'] = $db;
			for ( $id = 1; $id <= 20; ++$id ) {
				if ( 4 !== $id ) {
					$this->live_products[ $id ] = new RuntimeLiveProduct( $id );
				}
			}

			$presenter = new ProductPresenter( $db );
			$cards    = $presenter->cards( range( 1, 20 ) );

			self::assertTrue( $presenter->is_available() );
			self::assertCount( 13, $this->hydrated_ids );
			self::assertCount( 13, $cards, 'El ID 4 borrado se omite sin fabricar un error.' );
			self::assertSame( 14, max( $this->hydrated_ids ) );
		}

		/** @testdox Delete=false sólo es éxito con miss verificado; un valor A residual cierra sticky. */
		public function test_product_source_cache_eviction_is_observable_and_sticky(): void {
			$db                      = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']         = $db;
			$this->live_products[7]  = new RuntimeLiveProduct( 7 );
			$this->cache_delete_result = false;
			$presenter               = new ProductPresenter( $db );

			self::assertSame( array( 7 ), array_column( $presenter->cards( array( 7 ) ), 'id' ) );
			self::assertTrue( $presenter->is_available(), 'delete=false con key ausente es un miss verificable.' );

			$this->hydrated_ids         = array();
			$this->source_cache         = array( 'posts' => array( 7 => 'snapshot-a' ) );
			$this->cache_delete_noop    = true;
			$presenter                  = new ProductPresenter( $db );
			self::assertSame( array(), $presenter->cards( array( 7 ) ) );
			self::assertFalse( $presenter->is_available() );
			self::assertSame( array(), $this->hydrated_ids, 'Nunca hidrata WC tras observar la key A residual.' );

			$this->cache_delete_noop = false;
			$this->cache_delete_result = true;
			$this->source_cache = array();
			self::assertSame( array(), $presenter->cards( array( 7 ) ), 'La misma autoridad no puede revivir después del primer fallo.' );
			self::assertSame( array(), $this->hydrated_ids );
		}

		/** @testdox Un cache meta stale con cobertura correcta no puede hacerse pasar por la foto recién primada. */
		public function test_product_source_requires_literal_post_and_term_meta_cache_equality(): void {
			foreach ( array( 'post_meta', 'term_meta' ) as $case ) {
				$db              = new RuntimeReadersWpdb();
				$GLOBALS['wpdb'] = $db;
				$this->live_products = array( 77 => new RuntimeLiveProduct( 77 ) );
				$this->hydrated_ids = array();
				if ( 'post_meta' === $case ) {
					$this->cache_multiple_overrides['post_meta'][77] = array( '_price' => array( 'snapshot-a' ) );
				} else {
					$this->product_terms[77] = array( new \WP_Term( 'product_cat', 55, 0, 'manga', 'Manga', 77 ) );
					$this->term_meta_values[55] = array( '_akb_taxonomy_discount_pct' => array( '15' ) );
					$this->cache_multiple_overrides['term_meta'][55] = array( '_akb_taxonomy_discount_pct' => array( '10' ) );
				}

				$presenter = new ProductPresenter( $db );
				self::assertSame( array(), $presenter->cards( array( 77 ) ), $case );
				self::assertFalse( $presenter->is_available(), $case );
				self::assertSame( array(), $this->hydrated_ids, $case . ' falla antes de wc_get_product.' );
				$this->cache_multiple_overrides = array();
				$this->product_terms = array();
				$this->term_meta_values = array();
			}
		}

		/** @testdox Throwable, WP_Error y last_error del cache-verifier cierran la autoridad sin retry. */
		public function test_product_source_cache_verifier_failures_are_sticky(): void {
			$this->live_products[7] = new RuntimeLiveProduct( 7 );
			foreach ( array( 'throw', 'wp_error', 'db_error' ) as $case ) {
				$db              = new RuntimeReadersWpdb();
				$GLOBALS['wpdb'] = $db;
				$this->cache_get_throw    = 'throw' === $case;
				$this->cache_get_wp_error = 'wp_error' === $case;
				$this->cache_get_error    = 'db_error' === $case ? 'forced cache verification SQL error' : '';
				$this->hydrated_ids       = array();
				$presenter                = new ProductPresenter( $db );

				self::assertSame( array(), $presenter->cards( array( 7 ) ), $case );
				self::assertFalse( $presenter->is_available(), $case );
				self::assertNotSame( '', $presenter->last_error(), $case );
				$this->cache_get_throw    = false;
				$this->cache_get_wp_error = false;
				$this->cache_get_error    = '';
				self::assertSame( array(), $presenter->cards( array( 7 ) ), $case . ' no revive.' );
				self::assertSame( array(), $this->hydrated_ids, $case . ' no hidrata.' );
			}
		}

		/** @testdox Prepare de relaciones es una frontera sticky para Throwable WP_Error last_error y false. */
		public function test_product_term_prepare_failures_are_sticky(): void {
			$this->live_products[7] = new RuntimeLiveProduct( 7 );
			foreach ( array( 'throw', 'wp_error', 'db_error', 'false' ) as $case ) {
				$db = new RuntimeReadersWpdb();
				$GLOBALS['wpdb'] = $db;
				if ( 'throw' === $case ) {
					$db->throw_method = 'prepare';
				} elseif ( 'wp_error' === $case ) {
					$db->results['prepare'] = new \WP_Error();
				} else {
					$db->fail_method = 'prepare';
					$db->fail_as_false = 'false' === $case;
				}
				$this->hydrated_ids = array();
				$presenter = new ProductPresenter( $db );

				self::assertSame( array(), $presenter->cards( array( 7 ) ), $case );
				self::assertFalse( $presenter->is_available(), $case );
				self::assertSame( array(), $this->hydrated_ids, $case );
				self::assertSame( array(), $presenter->cards( array( 7 ) ), $case . ' queda sticky.' );
			}
		}

		/** @testdox Un registry vacío de taxonomías producto no se acepta como foto autoritativa. */
		public function test_empty_product_taxonomy_registry_fails_closed(): void {
			$db = new RuntimeReadersWpdb();
			$GLOBALS['wpdb'] = $db;
			$this->live_products[7] = new RuntimeLiveProduct( 7 );
			$this->product_taxonomies = array();
			$presenter = new ProductPresenter( $db );

			self::assertSame( array(), $presenter->cards( array( 7 ) ) );
			self::assertFalse( $presenter->is_available() );
			self::assertSame( array(), $this->hydrated_ids );
		}

		/** @testdox Relaciones SQL crudas y rehidratación expulsan un objeto de término A. */
		public function test_product_term_priming_bypasses_stale_term_query_caches(): void {
			$db                     = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']        = $db;
			$this->live_products[7] = new RuntimeLiveProduct( 7 );
			$term                   = new \WP_Term( 'product_cat', 28, 0, 'manga', 'Manga', 7 );
			$this->product_terms[7] = array( $term );
			$this->source_cache['terms'][28] = 'snapshot-a';
			$GLOBALS['akb_runtime_get_included_terms'] = static function ( array $args ) use ( $term ) {
				return false === ( $args['cache_results'] ?? null ) ? array( $term ) : new \WP_Error();
			};

			$presenter = new ProductPresenter( $db );
			self::assertSame( array( 7 ), array_column( $presenter->cards( array( 7 ) ), 'id' ) );
			self::assertTrue( $presenter->is_available() );
			self::assertArrayNotHasKey( 28, $this->source_cache['terms'] ?? array() );
			self::assertNotEmpty( array_filter( $db->queries, static fn( string $sql ): bool => false !== strpos( $sql, 'FROM wp_term_relationships tr' ) ) );
			$include_reads = array_values( array_filter( $this->term_query_args, static fn( array $args ): bool => isset( $args['include'] ) ) );
			self::assertSame( false, $include_reads[0]['cache_results'] ?? null );
		}

		/** @testdox Un filtro público no puede sustituir la relación 28 por 81 después de fijar la foto SQL. */
		public function test_document_builder_rejects_filtered_term_substitution_after_authority_snapshot(): void {
			$db                     = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']        = $db;
			$this->live_products[7] = new RuntimeLiveProduct( 7 );
			$this->product_taxonomies = FacetRegistry::indexed_taxonomies();
			$this->product_terms[7] = array( new \WP_Term( 'product_cat', 28, 0, 'manga', 'Manga', 7 ) );
			$authority = new ProductSourceAuthority( $db );

			$presenter = new ProductPresenter( $db, $authority );
			self::assertCount( 1, $presenter->cards( array( 7 ) ) );
			self::assertTrue( $authority->is_available() );

			// La capa pública cambia después del priming, pero la autoridad conserva
			// exactamente IDs y campos de la relación cruda anterior.
			$this->product_terms[7] = array( new \WP_Term( 'product_cat', 81, 0, 'comics', 'Comics', 7 ) );
			$builder = new DocumentBuilder();
			$builder->set_source_authority( $authority );
			$collect = new ReflectionMethod( DocumentBuilder::class, 'collect_terms' );
			$collect->setAccessible( true );

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'disagree' );
			$collect->invoke( $builder, 7 );
		}

		/** @testdox get_ancestors no puede omitir ni inyectar IDs frente al cierre SQL crudo. */
		public function test_document_builder_rejects_filtered_ancestor_omission_and_injection(): void {
			$child = new \WP_Term( 'product_cat', 55, 44, 'shonen', 'Shonen', 7 );
			$root  = new \WP_Term( 'product_cat', 44, 0, 'manga', 'Manga', 0 );
			$this->product_taxonomies = FacetRegistry::indexed_taxonomies();
			$this->product_terms[7]   = array( $child );
			$this->term_catalog[44]   = $root;

			foreach ( array( 'omission' => array(), 'injection' => array( 44, 99 ) ) as $case => $public_ids ) {
				$db                      = new RuntimeReadersWpdb();
				$GLOBALS['wpdb']         = $db;
				$this->live_products[7]  = new RuntimeLiveProduct( 7 );
				$this->public_ancestor_ids[55] = $public_ids;
				$authority = new ProductSourceAuthority( $db );
				$presenter = new ProductPresenter( $db, $authority );
				self::assertCount( 1, $presenter->cards( array( 7 ) ), $case );

				$builder = new DocumentBuilder();
				$builder->set_source_authority( $authority );
				$method = new ReflectionMethod( DocumentBuilder::class, 'add_taxonomy_rows' );
				$method->setAccessible( true );
				try {
					$method->invoke(
						$builder,
						static function (): void {},
						'categoria',
						array( 'taxonomy' => 'product_cat', 'hierarchical' => true ),
						array( 'product_cat' => array( $child ) ),
						7
					);
					self::fail( $case . ' debía fallar cerrado.' );
				} catch ( \RuntimeException $exception ) {
					self::assertStringContainsString( 'ancestors disagree', $exception->getMessage(), $case );
					self::assertFalse( $authority->is_available(), $case );
				}
			}
		}

		/** @testdox Error de hidratación o de una fuente tardía descarta el lote completo. */
		public function test_product_presenter_fails_closed_on_live_source_error(): void {
			$db                         = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']            = $db;
			$this->live_products[7]     = new RuntimeLiveProduct( 7 );
			$this->product_read_errors[7] = 'forced hydration failure';
			$presenter                  = new ProductPresenter( $db );

			self::assertSame( array(), $presenter->cards( array( 7 ) ) );
			self::assertFalse( $presenter->is_available() );
			self::assertSame( 'forced hydration failure', $presenter->last_error() );

			$db                             = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']                = $db;
			$this->product_read_errors      = array();
			$this->permalink_read_errors[7] = 'forced permalink failure';
			$presenter                      = new ProductPresenter( $db );

			self::assertSame( array(), $presenter->cards( array( 7 ) ) );
			self::assertFalse( $presenter->is_available() );
			self::assertSame( 'forced permalink failure', $presenter->last_error() );
		}

		/** @testdox Publish crudo seguido de status WC incompatible descarta todo el lote. */
		public function test_product_presenter_rejects_wc_status_that_disagrees_with_authority(): void {
			$db                      = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']         = $db;
			$stale                   = new RuntimeLiveProduct( 7 );
			$stale->status           = 'draft';
			$this->live_products[7]  = $stale;
			$this->raw_post_statuses[7] = 'publish';
			$presenter               = new ProductPresenter( $db );

			self::assertSame( array(), $presenter->cards( array( 7 ) ) );
			self::assertFalse( $presenter->is_available() );
			self::assertStringContainsString( 'disagrees', $presenter->last_error() );
		}

		/** @testdox wc_get_product del ID pedido no puede devolver silenciosamente otro producto. */
		public function test_product_presenter_rejects_wrong_hydrated_product_identity(): void {
			$db = new RuntimeReadersWpdb();
			$GLOBALS['wpdb'] = $db;
			$this->live_products[7] = new RuntimeLiveProduct( 999 );
			$this->raw_post_statuses[7] = 'publish';
			$presenter = new ProductPresenter( $db );

			self::assertSame( array(), $presenter->cards( array( 7 ) ) );
			self::assertFalse( $presenter->is_available() );
			self::assertStringContainsString( 'identity disagrees', $presenter->last_error() );
		}

		/** @testdox Un fallo de meta se observa antes de que terms pueda limpiarlo. */
		public function test_product_presenter_stops_between_priming_stages_on_meta_error(): void {
			$db                      = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']         = $db;
			$this->live_products[7]  = new RuntimeLiveProduct( 7 );
			$this->prime_errors['meta'] = 'forced metadata failure';
			$this->prime_errors['terms'] = '';
			$presenter               = new ProductPresenter( $db );

			self::assertSame( array(), $presenter->cards( array( 7 ) ) );
			self::assertFalse( $presenter->is_available() );
			self::assertSame( 'forced metadata failure', $presenter->last_error() );
			self::assertSame( array( 'posts:0:0', 'meta' ), $this->prime_calls, 'Terms no puede correr ni borrar el error de meta.' );
			self::assertSame( array(), $this->hydrated_ids, 'Nunca hidrata desde un cache de meta potencialmente envenenado.' );
		}

		/** @testdox Priming directo rechaza false, WP_Error y meta incompleta. */
		public function test_product_presenter_honors_core_cache_return_semantics(): void {
			$db                       = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']          = $db;
			$this->live_products[7]   = new RuntimeLiveProduct( 7 );
			$this->term_prime_result  = false;
			$presenter                = new ProductPresenter( $db );

			self::assertSame( array(), $presenter->cards( array( 7 ) ), 'false directo jamás significa cache-hit autoritativo.' );
			self::assertFalse( $presenter->is_available() );

			$this->prime_calls              = array();
			$this->term_prime_result        = null;
			$this->meta_prime_returns_false = true;
			$presenter                      = new ProductPresenter( $db );

			self::assertSame( array(), $presenter->cards( array( 7 ) ) );
			self::assertFalse( $presenter->is_available(), 'meta=false no representa un cache válido para IDs positivos.' );
			self::assertSame( array( 'posts:0:0', 'meta' ), $this->prime_calls );

			$this->prime_calls              = array();
			$this->meta_prime_returns_false = false;
			$this->term_prime_result        = new \WP_Error();
			$presenter                      = new ProductPresenter( $db );

			self::assertSame( array(), $presenter->cards( array( 7 ) ) );
			self::assertFalse( $presenter->is_available(), 'WP_Error de términos nunca puede parecer cache-hit.' );
			self::assertSame( array( 'posts:0:0', 'meta', 'terms' ), $this->prime_calls );
		}

		/** @testdox Una tarjeta sin título o permalink vivo falla cerrada. */
		public function test_product_presenter_rejects_non_navigable_live_card(): void {
			$db                      = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']         = $db;
			$product                 = new RuntimeLiveProduct( 7 );
			$product->name           = '';
			$this->live_products[7]  = $product;
			$presenter               = new ProductPresenter( $db );

			self::assertSame( array(), $presenter->cards( array( 7 ) ) );
			self::assertFalse( $presenter->is_available() );
			self::assertStringContainsString( 'title or permalink', $presenter->last_error() );

			$db                         = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']            = $db;
			$product->name              = 'Manga vivo';
			$this->permalink_values[7]  = '';
			$presenter                  = new ProductPresenter( $db );

			self::assertSame( array(), $presenter->cards( array( 7 ) ) );
			self::assertFalse( $presenter->is_available() );
			self::assertStringContainsString( 'title or permalink', $presenter->last_error() );
		}

		/** @testdox Los endpoints verifican receipt y lectores antes de cachear o loguear. */
		public function test_rest_surface_orders_authority_checks_before_side_effects(): void {
			$suggest = $this->method_source( SuggestController::class, 'handle_suggest' );
			$track   = $this->method_source( SuggestController::class, 'handle_track' );
			$failure = $this->method_source( SuggestController::class, 'suggest_unavailable' );

			self::assertLessThan( strpos( $suggest, '$this->rate_limited()' ), strpos( $suggest, 'self::publication_token()' ) );
			self::assertGreaterThan( strpos( $suggest, '$this->engine->suggest' ), strpos( $suggest, '$this->engine->is_available()' ) );
			self::assertGreaterThan( strpos( $suggest, '$this->presenter->cards' ), strpos( $suggest, '$this->presenter->is_available()' ) );
			self::assertLessThan( strrpos( $suggest, 'RestCache::apply' ), strrpos( $suggest, 'self::publication_token_matches' ) );
			self::assertStringContainsString( 'RestCache::no_store', $failure );
			self::assertStringContainsString( 'set_status( 503 )', $failure );
			self::assertStringContainsString( "'code'  => 'index_unavailable'", $failure );
			self::assertStringContainsString( 'RestCache::retry_after', $failure, 'El 503 del suggest se anuncia transitorio.' );
			self::assertStringContainsString(
				'RestCache::retry_after',
				$this->method_source( FacetQueryIntegration::class, 'count_unavailable' ),
				'El 503 del count también.'
			);
			self::assertLessThan( strpos( $track, '$this->engine->search' ), strpos( $track, 'self::publication_token()' ) );
			self::assertLessThan( strpos( $track, '$this->logger->log' ), strpos( $track, 'self::publication_token_matches' ) );
		}

		/** @testdox Suggest ejecutado devuelve 503 no-store y no habilita cache tras un fallo SQL. */
		public function test_suggest_handler_returns_uncacheable_503_on_engine_read_error(): void {
			$engine_db                = new RuntimeReadersWpdb();
			$engine_db->fail_method   = 'get_col';
			$engine_db->fail_contains = 'SELECT DISTINCT d.post_id';
			$GLOBALS['wpdb']          = $engine_db;
			$logger_db                = new RuntimeReadersWpdb();
			$controller               = $this->controller( $engine_db, $logger_db );
			$request                  = new \WP_REST_Request();
			$request->set_param( 'q', 'manga' );
			Fuzzy::note_correction( array( 'mnga' => 'manga' ) );

			$response = $controller->handle_suggest( $request );

			self::assertSame( 503, $response->get_status() );
			self::assertSame( array( 'items' => array(), 'code' => 'index_unavailable' ), $response->get_data() );
			self::assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] ?? null );
			// 0.1.64: el 503 del gate se anuncia transitorio; es la base del backoff del cliente.
			self::assertSame( (string) \Akibara\Search\Support\RestCache::RETRY_AFTER_SECONDS, $response->get_headers()['Retry-After'] ?? null );
			self::assertSame( array(), Fuzzy::last_correction() );
			self::assertNoPublicRestCacheWasApplied( $this->litespeed_actions );
			self::assertSame( array(), $logger_db->queries );
		}

		/** @testdox Suggest exitoso sigue no-store y un label de stock inválido degrada a 503 no-store. */
		public function test_suggest_success_is_no_store_and_stock_hook_failure_returns_no_store_503(): void {
			$db                = new RuntimeReadersWpdb();
			$db->candidate_ids = array( 7 );
			$db->documents     = array(
				array(
					'post_id'      => 7,
					'title_norm'   => 'manga siete',
					'serie_norm'   => 'manga',
					'autores_norm' => '',
					'tomo'         => 7,
					'stock_rank'   => 0,
					'menu_order'   => 0,
				),
			);
			$this->live_products[7] = new RuntimeLiveProduct( 7 );
			$GLOBALS['wpdb']         = $db;
			$this->throw_actions['litespeed_control_set_nocache'] = true;
			$controller = $this->controller( $db, new RuntimeReadersWpdb() );
			$request    = new \WP_REST_Request();
			$request->set_param( 'q', 'manga' );

			$response = $controller->handle_suggest( $request );
			self::assertSame( 200, $response->get_status() );
			self::assertSame( 7, $response->get_data()['items'][0]['id'] ?? null );
			self::assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] ?? null );
			self::assertArrayNotHasKey( 'Retry-After', $response->get_headers(), 'Un 200 no pide reintentar nada.' );

			$db                = new RuntimeReadersWpdb();
			$db->candidate_ids = array( 7 );
			$db->documents     = array(
				array(
					'post_id'      => 7,
					'title_norm'   => 'manga siete',
					'serie_norm'   => 'manga',
					'autores_norm' => '',
					'tomo'         => 7,
					'stock_rank'   => 0,
					'menu_order'   => 0,
				),
			);
			$GLOBALS['wpdb'] = $db;
			$this->filter_callbacks['akb_stock_status_label'] = static fn() => array( 'not-a-label' );
			$controller = $this->controller( $db, new RuntimeReadersWpdb() );
			$response   = $controller->handle_suggest( $request );

			self::assertSame( 503, $response->get_status() );
			self::assertSame( array( 'items' => array(), 'code' => 'index_unavailable' ), $response->get_data() );
			self::assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] ?? null );
			self::assertNoPublicRestCacheWasApplied( $this->litespeed_actions );
		}

		/** @testdox Count ejecutado devuelve 503 no-store y desactiva current ante fallo del motor. */
		public function test_count_handler_returns_uncacheable_503_and_clears_current_on_read_error(): void {
			$engine_db                = new RuntimeReadersWpdb();
			$engine_db->fail_method   = 'get_col';
			$engine_db->fail_contains = 'SELECT DISTINCT d.post_id';
			$GLOBALS['wpdb']          = $engine_db;
			$integration              = new FacetQueryIntegration( new QueryEngine( $engine_db ), new FacetService( $engine_db ) );
			$integration->handle_init();
			$request = new \WP_REST_Request();
			$request->set_query_params( array( '_buscar' => 'manga' ) );

			$response = $integration->handle_count( $request );

			self::assertSame( 503, $response->get_status() );
			self::assertSame( array( 'total' => null, 'code' => 'index_unavailable' ), $response->get_data() );
			self::assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] ?? null );
			self::assertSame( (string) \Akibara\Search\Support\RestCache::RETRY_AFTER_SECONDS, $response->get_headers()['Retry-After'] ?? null );
			self::assertNull( FacetQueryIntegration::current() );
			self::assertNoPublicRestCacheWasApplied( $this->litespeed_actions );
		}

		/** @testdox Count falla 503 antes de filtrar cuando get_terms observa error SQL. */
		public function test_count_handler_propagates_filter_normalization_read_error(): void {
			$db              = new RuntimeReadersWpdb();
			$GLOBALS['wpdb'] = $db;
			$GLOBALS['akb_runtime_get_terms'] = static function () use ( $db ) {
				$db->last_error = 'forced term lookup failure';
				return array();
			};
			$facets      = new FacetService( $db );
			$engine      = new QueryEngine( $db );
			$integration = new FacetQueryIntegration( $engine, $facets );
			$integration->handle_init();
			$request = new \WP_REST_Request();
			$request->set_query_params( array( '_categoria' => 'manga' ) );

			$response = $integration->handle_count( $request );

			self::assertSame( 503, $response->get_status() );
			self::assertSame( array( 'total' => null, 'code' => 'index_unavailable' ), $response->get_data() );
			self::assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] ?? null );
			self::assertNull( FacetQueryIntegration::current() );
			self::assertNoPublicRestCacheWasApplied( $this->litespeed_actions );
		}

		/** @testdox Suggest total propaga error de normalización y nunca cachea el catálogo sin filtro. */
		public function test_suggest_total_propagates_filter_normalization_read_error(): void {
			$db                = new RuntimeReadersWpdb();
			$db->candidate_ids = range( 1, 14 );
			foreach ( range( 1, 14 ) as $id ) {
				$db->documents[] = array(
					'post_id'      => $id,
					'title_norm'   => 'manga ' . $id,
					'serie_norm'   => 'manga',
					'autores_norm' => '',
					'tomo'         => $id,
					'stock_rank'   => 0,
					'menu_order'   => $id,
				);
				$product       = new RuntimeLiveProduct( $id );
				$product->name = 'Manga ' . $id;
				$this->live_products[ $id ] = $product;
			}
			$GLOBALS['wpdb'] = $db;
			$GLOBALS['akb_runtime_get_terms'] = static function () use ( $db ) {
				$db->last_error = 'forced suggest term failure';
				return array();
			};
			$controller = $this->controller( $db, new RuntimeReadersWpdb() );
			$request    = new \WP_REST_Request();
			$request->set_param( 'q', 'manga' );
			$request->set_query_params( array( '_categoria' => 'manga' ) );

			$response = $controller->handle_suggest( $request );

			self::assertSame( 503, $response->get_status() );
			self::assertSame( array( 'items' => array(), 'code' => 'index_unavailable' ), $response->get_data() );
			self::assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] ?? null );
			self::assertNoPublicRestCacheWasApplied( $this->litespeed_actions );
		}

		/** @testdox Fallo A de slug cierra FacetRegistry; un éxito B no reabre current, count ni suggest. */
		public function test_term_slug_authority_failure_is_sticky_across_public_surfaces(): void {
			$db              = new RuntimeReadersWpdb();
			$GLOBALS['wpdb'] = $db;
			$calls           = 0;
			$seen_args       = array();
			$GLOBALS['akb_runtime_get_terms'] = static function ( array $args ) use ( &$calls, &$seen_args, $db ): array {
				++$calls;
				$seen_args[] = $args;
				if ( 1 === $calls ) {
					$db->last_error = 'snapshot-a term lookup failed';
					return array();
				}
				return array( new \WP_Term( 'product_cat', 28, 0, (string) ( $args['slug'] ?? 'manga' ), 'Manga' ) );
			};

			$integration = new FacetQueryIntegration( new QueryEngine( $db ), new FacetService( $db ) );
			$integration->handle_init();
			$_GET = array( '_categoria' => 'manga' );
			self::assertNull( $integration->current_filters() );
			self::assertFalse( FacetRegistry::is_available() );
			self::assertSame( 'product_cat', $seen_args[0]['taxonomy'] ?? null );
			self::assertSame( 'manga', $seen_args[0]['slug'] ?? null );

			$db->last_error = '';
			$count_integration = new FacetQueryIntegration( new QueryEngine( $db ), new FacetService( $db ) );
			$count_integration->handle_init();
			$count_request = new \WP_REST_Request();
			$count_request->set_query_params( array( '_categoria' => 'manga' ) );
			self::assertSame( 503, $count_integration->handle_count( $count_request )->get_status() );

			$controller = $this->controller( $db, new RuntimeReadersWpdb() );
			$suggest_request = new \WP_REST_Request();
			$suggest_request->set_param( 'q', 'manga' );
			$suggest_request->set_query_params( array( '_categoria' => 'manga' ) );
			self::assertSame( 503, $controller->handle_suggest( $suggest_request )->get_status() );
			self::assertSame( 1, $calls, 'El supuesto éxito B jamás relee ni revive la autoridad A fallida.' );
		}

		/** @testdox Wiring A=yes no sirve ni muta después de adoptar la foto B=no. */
		public function test_runtime_flags_are_revalidated_after_snapshot_adoption(): void {
			unset( $this->filter_callbacks['akb_search_suggest_enabled'], $this->filter_callbacks['akb_search_facets_enabled'] );
			$a = array(
				Install::OPTION_INDEX_GEN => 'wiring-a',
				Search::OPTION_SUGGEST   => 'yes',
				Search::OPTION_FACETS    => 'yes',
			);
			$b = array(
				Install::OPTION_INDEX_GEN => 'wiring-b',
				Search::OPTION_SUGGEST   => 'no',
				Search::OPTION_FACETS    => 'no',
			);
			self::assertTrue( SemanticPublication::adopt_authoritative_snapshot( $a ) );
			self::assertTrue( Search::suggest_configured() );
			self::assertTrue( Search::facets_configured() );

			$db        = new RuntimeReadersWpdb();
			$logger_db = new RuntimeReadersWpdb();
			$GLOBALS['wpdb'] = $db;
			$controller  = $this->controller( $db, $logger_db );
			$integration = new FacetQueryIntegration( new QueryEngine( $db ), new FacetService( $db ) );
			self::assertTrue( SemanticPublication::adopt_authoritative_snapshot( $b ) );

			$integration->handle_init();
			self::assertNull( FacetQueryIntegration::current() );
			$query = new \WP_Query();
			$query->query_vars = array( 'sentinel' => 'keep' );
			$_GET = array( '_buscar' => 'manga' );
			$integration->apply( $query );
			self::assertSame( array( 'sentinel' => 'keep' ), $query->query_vars );
			self::assertSame( 503, $integration->handle_count( new \WP_REST_Request() )->get_status() );

			$suggest_request = new \WP_REST_Request();
			$suggest_request->set_param( 'q', 'manga' );
			self::assertSame( 503, $controller->handle_suggest( $suggest_request )->get_status() );
			self::assertSame( 503, $controller->handle_popular( new \WP_REST_Request() )->get_status() );
			$track_request = new \WP_REST_Request();
			$track_request->set_param( 'q', 'manga' );
			self::assertSame( 204, $controller->handle_track( $track_request )->get_status() );
			self::assertSame( array(), $db->queries, 'B=no corta antes del motor y del facet SQL.' );
			self::assertSame( array(), $logger_db->queries, 'B=no corta /track antes de mutar qlog.' );
		}

		/** @testdox Track ejecutado conserva 204 no-store y no loguea un cero falso. */
		public function test_track_handler_returns_204_without_logging_on_engine_read_error(): void {
			$engine_db                = new RuntimeReadersWpdb();
			$engine_db->fail_method   = 'get_col';
			$engine_db->fail_contains = 'SELECT DISTINCT d.post_id';
			$GLOBALS['wpdb']          = $engine_db;
			$logger_db                = new RuntimeReadersWpdb();
			$controller               = $this->controller( $engine_db, $logger_db );
			$request                  = new \WP_REST_Request();
			$request->set_param( 'q', 'manga' );
			$request->set_param( 'source', QueryLogger::SOURCE_SEARCH );

			$response = $controller->handle_track( $request );

			self::assertSame( 204, $response->get_status() );
			self::assertNull( $response->get_data() );
			self::assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] ?? null );
			self::assertSame( array(), $logger_db->queries, 'No debe persistir hits=0 ante una lectura no autoritativa.' );
			self::assertNoPublicRestCacheWasApplied( $this->litespeed_actions );
		}

		/** @testdox Gate cerrado corta suggest/count y track antes de tocar motor o logger. */
		public function test_closed_publication_gate_short_circuits_all_rest_readers(): void {
			$db               = new RuntimeReadersWpdb();
			$GLOBALS['wpdb']  = $db;
			$logger_db        = new RuntimeReadersWpdb();
			$controller       = $this->controller( $db, $logger_db );
			$integration      = new FacetQueryIntegration( new QueryEngine( $db ), new FacetService( $db ) );
			$this->close_publication_gate( $db );
			$integration->handle_init();

			$suggest_request = new \WP_REST_Request();
			$suggest_request->set_param( 'q', 'manga' );
			$suggest = $controller->handle_suggest( $suggest_request );
			$count   = $integration->handle_count( new \WP_REST_Request() );
			$track_request = new \WP_REST_Request();
			$track_request->set_param( 'q', 'manga' );
			$track = $controller->handle_track( $track_request );

			self::assertSame( 503, $suggest->get_status() );
			self::assertSame( 503, $count->get_status() );
			self::assertSame( 204, $track->get_status() );
			self::assertSame( 'no-store, max-age=0', $suggest->get_headers()['Cache-Control'] ?? null );
			self::assertSame( 'no-store, max-age=0', $count->get_headers()['Cache-Control'] ?? null );
			self::assertSame( 'no-store, max-age=0', $track->get_headers()['Cache-Control'] ?? null );
			self::assertSame( array(), $logger_db->queries );
			self::assertNull( FacetQueryIntegration::current() );
			self::assertNoPublicRestCacheWasApplied( $this->litespeed_actions );
		}

		/** @testdox Gate cerrado conserva Agotado mediante stock nativo en vez de abrir todo el catálogo. */
		public function test_closed_gate_preserves_explicit_outofstock_in_native_query(): void {
			$db = new RuntimeReadersWpdb();
			$GLOBALS['wpdb'] = $db;
			$integration = new FacetQueryIntegration( new QueryEngine( $db ), new FacetService( $db ) );
			$this->close_publication_gate( $db );
			$integration->handle_init();
			$_GET = array( '_disponibilidad' => 'outofstock' );
			$query = new \WP_Query();
			$query->query_vars = array( 'product_brand' => 'milky-way', 'post__not_in' => array( 14 ) );
			$integration->apply( $query );
			self::assertSame( 'milky-way', $query->query_vars['product_brand'] );
			self::assertSame( array( 14 ), $query->query_vars['post__not_in'] );
			self::assertSame( array( 'outofstock' ), $query->query_vars['meta_query'][0]['value'] );
			self::assertTrue( $query->query_vars['_akb_native_availability_fallback'] );
			self::assertNull( FacetQueryIntegration::current() );
			self::assertContains( 'litespeed_control_set_nocache', $this->litespeed_actions );
		}

		/** @testdox Un criterio de índice no reproducible devuelve indisponibilidad explícita, no un filtro ignorado. */
		public function test_closed_gate_rejects_unreproducible_combination_with_503(): void {
			$db = new RuntimeReadersWpdb();
			$GLOBALS['wpdb'] = $db;
			$integration = new FacetQueryIntegration( new QueryEngine( $db ), new FacetService( $db ) );
			$this->close_publication_gate( $db );
			$integration->handle_init();
			$_GET = array( '_disponibilidad' => 'outofstock', '_precio' => '1' );
			Functions\when( 'wp_die' )->alias(
				static function ( string $message, string $title, array $args ): void {
					self::assertSame( 503, $args['response'] );
					self::assertStringContainsString( 'actualizando los filtros', $message );
					throw new \RuntimeException( 'explicit-temporary-503' );
				}
			);
			$this->expectExceptionMessage( 'explicit-temporary-503' );
			$integration->apply( new \WP_Query() );
		}

		/** @testdox Apply y view consumen una sola foto de query vars y counts sin releer tras el check final. */
		public function test_apply_and_view_reuse_one_filter_and_counts_snapshot(): void {
			$db              = new RuntimeReadersWpdb();
			$GLOBALS['wpdb'] = $db;
			$calls           = 0;
			$this->filter_callbacks['akb_search_query_vars'] = static function ( $value ) use ( &$calls ) {
				++$calls;
				return 1 === $calls ? $value : array( '_buscar' => 'snapshot-b' );
			};
			$facets      = new FacetService( $db );
			$engine      = new QueryEngine( $db );
			$integration = new FacetQueryIntegration( $engine, $facets );
			$integration->handle_init();
			$initial_token_property = new \ReflectionProperty( FacetQueryIntegration::class, 'request_publication_token' );
			$initial_token_property->setAccessible( true );
			// Antes fijaba que el token estuviera capturado ya en `handle_init()`.
			// La invariante que importa NO es el momento sino la unicidad: una
			// captura por request, la misma foto para apply() y view(). Capturar
			// en init:24 costaba 4 consultas crudas (2 bundles de options + 2
			// UNION termmeta/attributes; medido el 2026-08-23 en Docker:
			// `capture_serving_token = 4 q, 4.023 ms`) en TODA petición, incluidas
			// wp-admin, WP-Cron, admin-ajax y REST, donde nadie consume el token.
			// Ahora init:24 sólo arma y el primer consumidor real resuelve.
			self::assertNull( $initial_token_property->getValue( $integration ), 'init:24 no puede pagar la captura del token.' );
			$query = new \WP_Query();
			$query->query_vars = array( 'sentinel' => 'keep' );

			$integration->apply( $query );
			$reads_after_apply = count( $db->queries );
			self::assertSame( 'akb-search-test-publication-token', $initial_token_property->getValue( $integration ), 'El primer consumidor resuelve el serving.' );
			self::assertSame( 1, $calls );
			self::assertSame( array( 'sentinel' => 'keep' ), $query->query_vars );
			self::assertTrue( $facets->is_available(), $facets->last_error() );
			self::assertSame( $integration, FacetQueryIntegration::current() );

			$db->throw_method = 'get_results';
			$view = $integration->view( 0 );
			self::assertIsArray( $view );
			self::assertSame( 1, $calls, 'El filtro stateful no puede producir una foto B en view().' );
			self::assertSame( $reads_after_apply, count( $db->queries ), 'view() no abre SQL después del final-check.' );
		}

		/**
		 * @testdox Lo que encuentra el texto se cuenta donde está el cliente: en un archivo, dentro del archivo, que no es un chip (REV-FS1-1)
		 */
		public function test_search_total_counts_the_text_inside_the_archive(): void {
			// «frieren» encuentra 7 y 8 en la tienda y el 7 está disponible. En el
			// archivo de Ivrea, `$en_el_archivo` es lo que encuentra ahí.
			$vista = function ( ?array $en_el_archivo, array $get ): array {
				$db                = new RuntimeReadersWpdb();
				$db->candidate_ids = array( 7, 8 );
				foreach ( array( 7, 8 ) as $id ) {
					$db->documents[] = array(
						'post_id'      => $id,
						'title_norm'   => 'frieren ' . $id,
						'serie_norm'   => 'frieren',
						'autores_norm' => '',
						'tomo'         => $id,
						'stock_rank'   => 0,
						'menu_order'   => $id,
					);
				}
				$db->results['get_col'] = static function ( string $query ) use ( $en_el_archivo ): array {
					if ( false === stripos( $query, 'SELECT DISTINCT d.post_id' ) ) {
						return array();
					}
					if ( false !== strpos( $query, "'ivrea'" ) ) {
						return (array) $en_el_archivo;
					}
					return false !== strpos( $query, "'instock'" ) ? array( 7 ) : array( 7, 8 );
				};
				$GLOBALS['wpdb'] = $db;
				if ( null !== $en_el_archivo ) {
					$this->product_taxonomies = array( 'product_cat', 'product_brand' );
					Functions\when( 'is_tax' )->justReturn( true );
					Functions\when( 'get_queried_object' )->justReturn( new \WP_Term( 'product_brand', 55, 0, 'ivrea', 'Ivrea' ) );
				}
				$_GET        = $get;
				$integration = new FacetQueryIntegration( new QueryEngine( $db ), new FacetService( $db ) );
				$integration->handle_init();
				$integration->apply( new \WP_Query() );
				$lecturas = count( $db->queries );
				$view     = $integration->view( 0 );
				self::assertIsArray( $view );
				self::assertSame( $lecturas, count( $db->queries ), 'view() no abre SQL después del final-check.' );
				$view['_lo_del_archivo'] = count(
					array_filter( $db->queries, static fn( string $q ): bool => 0 === stripos( $q, 'SELECT DISTINCT d.post_id' ) && false !== strpos( $q, "'ivrea'" ) )
				);
				return $view;
			};
			$con_filtro = array(
				'_buscar'         => 'frieren',
				'_disponibilidad' => 'instock',
			);

			// En la tienda, lo que encontró el motor.
			self::assertSame( 2, $vista( null, $con_filtro )['search_total'] );
			// En el archivo, lo que el texto encuentra ahí: quitar el chip trae eso.
			self::assertSame( 1, $vista( array( 8 ), $con_filtro )['search_total'] );
			// /comics/?_buscar=frieren&_disponibilidad=instock: en el archivo el
			// texto no encuentra nada, así que el cero es de la búsqueda aunque
			// en la tienda haya dos. Antes contaba 2 y el aviso culpaba al filtro.
			self::assertSame( 0, $vista( array(), $con_filtro )['search_total'] );
			self::assertSame( 1, $vista( array(), $con_filtro )['_lo_del_archivo'] );
			// Sin chips que quitar el aviso habla de la búsqueda igual: ni se mide
			// ni se paga la consulta.
			$sin_chips = $vista( array( 8 ), array( '_buscar' => 'frieren' ) );
			self::assertSame( 0, $sin_chips['_lo_del_archivo'] );
			self::assertSame( 0, $sin_chips['search_total'] );
			self::assertSame( 0, $vista( null, array( '_buscar' => 'frieren' ) )['search_total'] );
		}

		/**
		 * @testdox En un archivo sin faceta dueña (Sin categorizar) no se sabe qué encuentra el texto ahí: el cero es de la búsqueda, sin SQL nuevo (REV-FS-1-1)
		 */
		public function test_search_total_is_zero_in_an_archive_without_owner_facet(): void {
			// «frieren» encuentra 7 y 8 en la tienda. /uncategorized/ es la
			// categoría por defecto: `is_excluded_root_term()` la deja sin faceta
			// dueña y `archive_context()` da null, pero WP_Query sigue limitando
			// el listado a ese archivo. Contar lo de la tienda entera culpaba al
			// filtro y quitar el chip llevaba a otro cero.
			$vista = function ( bool $sin_categorizar ): array {
				$db                = new RuntimeReadersWpdb();
				$db->candidate_ids = array( 7, 8 );
				foreach ( array( 7, 8 ) as $id ) {
					$db->documents[] = array(
						'post_id'      => $id,
						'title_norm'   => 'frieren ' . $id,
						'serie_norm'   => 'frieren',
						'autores_norm' => '',
						'tomo'         => $id,
						'stock_rank'   => 0,
						'menu_order'   => $id,
					);
				}
				$db->results['get_col'] = static fn( string $query ): array => false === stripos( $query, 'SELECT DISTINCT d.post_id' )
					? array()
					: ( false !== strpos( $query, "'instock'" ) ? array() : array( 7, 8 ) );
				// El índice no está vacío: un listado filtrado en cero no es fail-open.
				$db->results['get_var'] = static fn( string $query ) => false !== stripos( $query, 'COUNT(*)' ) ? '0' : '7';
				// La foto de options trae la categoría por defecto (término 15).
				$db->results['get_results'] = static function ( string $query, $output ) use ( $db ): array {
					if ( false !== stripos( $query, 'SELECT option_name, option_value' ) ) {
						return array( (object) array( 'option_name' => 'default_product_cat', 'option_value' => '15' ) );
					}
					return ARRAY_A === $output && false !== stripos( $query, 'title_norm' ) ? $db->documents : array();
				};
				$GLOBALS['wpdb'] = $db;
				SemanticPublication::reset_authoritative_state_for_tests();
				FacetRegistry::reset_cache();
				$this->product_taxonomies = array( 'product_cat', 'product_brand' );
				Functions\when( 'is_tax' )->justReturn( $sin_categorizar );
				Functions\when( 'get_queried_object' )->justReturn( $sin_categorizar ? new \WP_Term( 'product_cat', 15, 0, 'uncategorized', 'Sin categorizar' ) : null );
				$_GET        = array(
					'_buscar'         => 'frieren',
					'_disponibilidad' => 'instock',
				);
				$integration = new FacetQueryIntegration( new QueryEngine( $db ), new FacetService( $db ) );
				$integration->handle_init();
				$integration->apply( new \WP_Query() );
				$lecturas = count( $db->queries );
				$view     = $integration->view( 0 );
				self::assertIsArray( $view );
				self::assertNull( $integration->archive_context(), 'Sin categorizar no tiene faceta dueña.' );
				self::assertSame( $lecturas, count( $db->queries ), 'view() no abre SQL después del final-check.' );
				$view['_lecturas_del_indice'] = count(
					array_filter( $db->queries, static fn( string $q ): bool => 0 === stripos( $q, 'SELECT DISTINCT d.post_id' ) )
				);
				return $view;
			};

			// En la tienda, lo que encontró el motor: quitar el chip trae los dos.
			$tienda = $vista( false );
			self::assertSame( 2, $tienda['search_total'] );
			// En Sin categorizar, 0: el aviso vuelve a hablar de la búsqueda.
			// Antes contaba los 2 de la tienda y decía «Ningún producto coincide
			// con estos filtros.» sin «Quizá buscabas».
			$archivo = $vista( true );
			self::assertSame( 0, $archivo['search_total'] );
			// Y sin preguntarle nada nuevo al índice.
			self::assertSame( $tienda['_lecturas_del_indice'], $archivo['_lecturas_del_indice'] );
		}

		/** @testdox init:24 arma sin capturar y el primer consumidor resuelve el serving una sola vez. */
		public function test_serving_capture_is_deferred_to_the_first_consumer(): void {
			$db              = new RuntimeReadersWpdb();
			$GLOBALS['wpdb'] = $db;
			$integration     = new FacetQueryIntegration( new QueryEngine( $db ), new FacetService( $db ) );

			$token_property = new \ReflectionProperty( FacetQueryIntegration::class, 'request_publication_token' );
			$token_property->setAccessible( true );
			$armed_property = new \ReflectionProperty( FacetQueryIntegration::class, 'armed' );
			$armed_property->setAccessible( true );
			$resolved_property = new \ReflectionProperty( FacetQueryIntegration::class, 'serving_resolved' );
			$resolved_property->setAccessible( true );

			$integration->handle_init();

			// La invariante: init:24 no puede tocar la autoridad de serving. La
			// captura son 4 consultas crudas a prueba de object cache (medido el
			// 2026-08-23: 4 q / 4,02 ms) y en wp-admin, WP-Cron, admin-ajax y el
			// resto de REST nadie consume el token.
			self::assertNull( $token_property->getValue( $integration ) );
			self::assertFalse( $resolved_property->getValue( $integration ) );
			self::assertSame( $integration, $armed_property->getValue() );
			self::assertStringNotContainsString(
				'capture_publication_token',
				$this->method_source( FacetQueryIntegration::class, 'handle_init' ),
				'handle_init() sólo arma: capturar ahí devuelve el coste a toda petición.'
			);

			self::assertSame( $integration, FacetQueryIntegration::current() );
			self::assertSame( 'akb-search-test-publication-token', $token_property->getValue( $integration ) );
			self::assertTrue( $resolved_property->getValue( $integration ) );
			self::assertNull( $armed_property->getValue(), 'Resuelto una vez: el puntero armado se consume.' );

			// Un request desactivado no puede resucitar por el puntero armado.
			$deactivate = new \ReflectionMethod( FacetQueryIntegration::class, 'deactivate' );
			$deactivate->setAccessible( true );
			$deactivate->invoke( $integration );
			self::assertNull( FacetQueryIntegration::current() );
			self::assertNull( $token_property->getValue( $integration ) );

			// Una instancia que nunca pasó por init:24 no puede capturar por su
			// cuenta desde un consumidor: es el mismo fail-closed que daba el
			// token null cuando la captura vivía en el hook.
			$huerfana = new FacetQueryIntegration( new QueryEngine( $db ), new FacetService( $db ) );
			self::assertNull( $huerfana->view( 0 ) );
			self::assertNull( $token_property->getValue( $huerfana ) );
			self::assertNull( FacetQueryIntegration::current() );
		}

		/** @testdox El main query no se muta hasta validar gate, engine y facetas. */
		public function test_main_query_mutation_is_after_all_authority_checks(): void {
			$register = $this->method_source( FacetQueryIntegration::class, 'register' );
			$apply    = $this->method_source( FacetQueryIntegration::class, 'apply' );
			$disable  = $this->method_source( FacetQueryIntegration::class, 'deactivate' );
			$first_set = strpos( $apply, '$query->set' );

			self::assertStringContainsString( "add_action( 'init', array( \$this, 'handle_init' ), 24 )", $register );
			self::assertLessThan( $first_set, strpos( $apply, '$this->engine->is_available()' ) );
			self::assertLessThan( $first_set, strpos( $apply, '$this->facets->is_available()' ) );
			self::assertLessThan( $first_set, strpos( $apply, 'self::serving_ready()' ) );
			self::assertStringContainsString( 'self::$current = null', $disable );
			self::assertStringContainsString( 'Fuzzy::flush()', $disable );
		}

		/** @testdox Apply ejecutado deja el main query intacto y current null al fallar una lectura. */
		public function test_apply_keeps_main_query_untouched_and_deactivates_on_engine_error(): void {
			$db                = new RuntimeReadersWpdb();
			$db->fail_method   = 'get_col';
			$db->fail_contains = 'SELECT DISTINCT d.post_id';
			$GLOBALS['wpdb']   = $db;
			$integration       = new FacetQueryIntegration( new QueryEngine( $db ), new FacetService( $db ) );
			$integration->handle_init();
			$_GET = array( '_buscar' => 'manga' );
			$query = new \WP_Query();
			$query->query_vars = array( 'sentinel' => 'keep' );
			Fuzzy::note_correction( array( 'mnga' => 'manga' ) );

			$integration->apply( $query );

			self::assertSame( array( 'sentinel' => 'keep' ), $query->query_vars );
			self::assertNull( FacetQueryIntegration::current() );
			self::assertNull( ( static function ( FacetQueryIntegration $integracion ) {
				// `last_result()` no tenía llamador en producción (retirado en 0.1.64): se
				// mira el memo privado, que es lo que la garantía protege.
				$memo = new \ReflectionProperty( FacetQueryIntegration::class, 'last_result' );
				$memo->setAccessible( true );
				return $memo->getValue( $integracion );
			} )( $integration ), 'El memo de resultado no sobrevive al fallo.' );
			self::assertSame( array(), Fuzzy::last_correction() );
		}

		/** @testdox Apply no descarta una faceta ni muta el main query si falla get_terms. */
		public function test_apply_keeps_main_query_untouched_on_filter_normalization_error(): void {
			$db              = new RuntimeReadersWpdb();
			$GLOBALS['wpdb'] = $db;
			$GLOBALS['akb_runtime_get_terms'] = static function () use ( $db ) {
				$db->last_error = 'forced apply term failure';
				return array();
			};
			$integration = new FacetQueryIntegration( new QueryEngine( $db ), new FacetService( $db ) );
			$integration->handle_init();
			$_GET = array( '_categoria' => 'manga' );
			$query = new \WP_Query();
			$query->query_vars = array( 'sentinel' => 'keep' );

			$integration->apply( $query );

			self::assertSame( array( 'sentinel' => 'keep' ), $query->query_vars );
			self::assertNull( FacetQueryIntegration::current() );
			self::assertNull( ( static function ( FacetQueryIntegration $integracion ) {
				// `last_result()` no tenía llamador en producción (retirado en 0.1.64): se
				// mira el memo privado, que es lo que la garantía protege.
				$memo = new \ReflectionProperty( FacetQueryIntegration::class, 'last_result' );
				$memo->setAccessible( true );
				return $memo->getValue( $integracion );
			} )( $integration ), 'El memo de resultado no sobrevive al fallo.' );
		}

		/**
		 * @testdox Con el gate de publicación cerrado, apply() deja el fail-open MARCADO y el request siguiente arranca limpio.
		 *
		 * [B3 de la revisión de 0.1.64] El cliente sólo reintenta una página sin
		 * facetas si el servidor la marca como fail-open por el gate; un archivo
		 * vacío sin filtros omite el form a propósito y no se marca.
		 */
		public function test_apply_con_gate_cerrado_marca_el_fail_open(): void {
			$db              = new RuntimeReadersWpdb();
			$GLOBALS['wpdb'] = $db;
			$integration     = new FacetQueryIntegration( new QueryEngine( $db ), new FacetService( $db ) );
			$integration->handle_init();
			self::assertFalse( FacetQueryIntegration::fail_open_por_gate(), 'Cada request empieza sin marca.' );
			$this->close_publication_gate( $db );
			$query             = new \WP_Query();
			$query->query_vars = array( 'sentinel' => 'keep' );

			$integration->apply( $query );

			self::assertNull( FacetQueryIntegration::current() );
			self::assertSame( array( 'sentinel' => 'keep' ), $query->query_vars, 'Fail-open: el main query queda intacto.' );
			self::assertTrue( FacetQueryIntegration::fail_open_por_gate(), 'Facetas configuradas y publicación cerrada: fail-open por el gate.' );

			$integration->handle_init();
			self::assertFalse( FacetQueryIntegration::fail_open_por_gate(), 'La marca no sobrevive al request.' );
		}

		/**
		 * @testdox La marca de fail-open sólo se imprime con la bandera del gate y en superficie de producto.
		 */
		public function test_la_marca_de_fail_open_depende_sólo_del_gate(): void {
			$frontend    = (string) file_get_contents( dirname( __DIR__ ) . '/frontend/facets-render.php' );
			$bridge      = (string) file_get_contents( dirname( __DIR__ ) . '/akibara-search.php' );
			$integration = (string) file_get_contents( dirname( __DIR__ ) . '/src/FacetQueryIntegration.php' );

			self::assertMatchesRegularExpression(
				"/function akibara_gp_akb_print_fail_open_marker\(\): void \{\s*if \( ! function_exists\( 'akibara_search_facets_fail_open' \) \|\| ! akibara_search_facets_fail_open\(\) \) \{\s*return;\s*\}\s*if \( ! akibara_gp_akb_is_product_surface\(\) \) \{\s*return;\s*\}\s*echo '<meta name=\"akb-search-facets\" content=\"indisponible\">'/",
				$frontend
			);
			self::assertStringContainsString( "add_action( 'wp_head', 'akibara_gp_akb_print_fail_open_marker', 1 );", $frontend );
			self::assertStringContainsString( 'FacetQueryIntegration::fail_open_por_gate()', $bridge );
			// La bandera sale del token de publicación con facetas configuradas —no
			// de «faltan facetas»— y del lease que deja de casar a mitad de request.
			self::assertStringContainsString( 'self::$fail_open_por_gate = null === $this->request_publication_token && Search::facets_configured();', $integration );
			self::assertMatchesRegularExpression( '/if \( ! \$this->lease_accepted \) \{\s*(?:\/\/[^\n]*\s*)*self::\$fail_open_por_gate = true;/', $integration );
		}

		/** @testdox RankMath y los helpers solo anuncian la superficie con serving efectivo. */
		public function test_rankmath_and_public_helper_require_effective_serving(): void {
			$frontend = (string) file_get_contents( dirname( __DIR__ ) . '/frontend/facets-render.php' );
			$bridge   = (string) file_get_contents( dirname( __DIR__ ) . '/akibara-search.php' );

			// UN solo predicado en el frontend: convivían tres funciones para la
			// misma pregunta y cualquiera podía moverse sin las otras. Lo que la
			// invariante fija es la FUENTE del veredicto —el receipt
			// materializado— y que ninguna superficie lo resuelva a mano.
			self::assertSame(
				1,
				preg_match_all( '/function akibara_gp_akb_(facets_active|search_serving_ready)\(/', $frontend ),
				'El frontend declara un único predicado de actividad del filtrado.'
			);
			self::assertStringContainsString( 'akibara_search_facets_active()', $frontend );
			self::assertStringContainsString( 'null !== \\Akibara\\Search\\FacetQueryIntegration::current()', $bridge );
			self::assertStringContainsString( '! akibara_gp_akb_facets_active()', $frontend );
			self::assertStringNotContainsString( 'EditorialDiscountPublication::serving_ready()', $frontend );
			self::assertStringContainsString( '\\Akibara\\Search\\Search::suggest_enabled()', $bridge );
			self::assertStringNotContainsString( 'EditorialDiscountPublication::serving_ready()', $bridge );
		}

		/**
		 * @param object             $object Objeto bajo prueba.
		 * @param string             $method Método público o privado.
		 * @param array<int, mixed>  $args   Argumentos.
		 * @return mixed
		 */
		private function invoke_private( object $object, string $method, array $args ) {
			$reflection = new ReflectionMethod( $object, $method );
			$reflection->setAccessible( true );
			return $reflection->invokeArgs( $object, $args );
		}

		/** Construye la superficie REST real con dependencias observables. */
		private function controller( RuntimeReadersWpdb $engine_db, RuntimeReadersWpdb $logger_db ): SuggestController {
			return new SuggestController(
				new QueryEngine( $engine_db ),
				new ProductPresenter( $engine_db ),
				new QueryLogger( $logger_db ),
				new FacetService( $engine_db ),
				new PopularQueries( $engine_db )
			);
		}

		/** Fuerza el seam PHPUnit del protocolo a cerrado. */
		private function close_publication_gate( RuntimeReadersWpdb $db ): void {
			unset( $db );
			EditorialDiscountPublication::set_test_serving_override( false );
			self::assertFalse( EditorialDiscountPublication::serving_ready() );
		}

		/** @param array<int, string> $actions Hooks LiteSpeed observados. */
		private static function assertNoPublicRestCacheWasApplied( array $actions ): void {
			self::assertNotContains( 'litespeed_control_set_cacheable', $actions );
			self::assertNotContains( 'litespeed_control_set_ttl', $actions );
			self::assertNotContains( 'litespeed_tag_add', $actions );
		}

		/** @return mixed Valor static privado del publication rail. */
		private function publication_static_value( string $name ) {
			$property = new \ReflectionProperty( EditorialDiscountPublication::class, $name );
			$property->setAccessible( true );
			return $property->getValue();
		}

		/** @param mixed $value Valor static privado del publication rail. */
		private function set_publication_static_value( string $name, $value ): void {
			$property = new \ReflectionProperty( EditorialDiscountPublication::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}

		/** Devuelve el cuerpo fuente exacto de un método para contratos de orden. */
		private function method_source( string $class, string $method ): string {
			$reflection = new ReflectionMethod( $class, $method );
			$lines      = file( (string) $reflection->getFileName() );
			return implode(
				'',
				array_slice( $lines ?: array(), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 )
			);
		}
	}
}
