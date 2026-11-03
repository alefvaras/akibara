<?php
/**
 * Tests for filtered catalog URL indexing policy.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeoIndexing;

use Akibara\Business\Modules\SeoIndexing\FilteredUrlsPolicy;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class FilteredUrlsPolicyTest extends TestCase {

	/** @var array<string,mixed> */
	private array $original_get = array();

	private bool $had_wp_query = false;

	/** @var mixed */
	private $original_wp_query;

	private FilteredUrlsPolicy $policy;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_get = $_GET;
		$_GET              = array();
		$this->policy      = new FilteredUrlsPolicy();
		$this->had_wp_query = array_key_exists( 'wp_query', $GLOBALS );
		$this->original_wp_query = $GLOBALS['wp_query'] ?? null;
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		if ( $this->had_wp_query ) {
			$GLOBALS['wp_query'] = $this->original_wp_query;
		} else {
			unset( $GLOBALS['wp_query'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_registers_robots_filters_and_empty_collection_status_handler(): void {
		Monkey\Filters\expectAdded( 'wp_robots' )
			->once()
			->with( array( $this->policy, 'handle_wordpress_robots' ) );
		Monkey\Filters\expectAdded( 'rank_math/frontend/robots' )
			->once()
			->with( array( $this->policy, 'handle_rank_math_robots' ), 99 );
		Monkey\Actions\expectAdded( 'template_redirect' )
			->once()
			->with( array( $this->policy, 'handle_template_redirect' ), 20 );

		$this->policy->register();
		$this->addToAssertionCount( 3 );
	}

	/**
	 * @dataProvider product_surface_data
	 * @param array<string,mixed> $context Conditional values for the request.
	 */
	public function test_detects_underscore_facets_on_supported_product_surfaces( array $context ): void {
		$this->configure_request_context( $context );
		$_GET = array( '_future_facet' => 'valor' );

		self::assertTrue( $this->policy->has_catalog_filters() );
	}

	/**
	 * @return array<string,array{array<string,mixed>}>
	 */
	public static function product_surface_data(): array {
		return array(
			'Shop archive'      => array( array( 'shop' => true ) ),
			'Product taxonomy'  => array( array( 'taxonomy' => true ) ),
			'Product search'    => array( array( 'search' => true, 'post_type' => 'product' ) ),
			'Mixed type search' => array( array( 'search' => true, 'post_type' => array( 'post', 'product' ) ) ),
		);
	}

	public function test_ignores_underscore_parameters_outside_product_listings(): void {
		$this->configure_request_context();
		$_GET = array( '_tracking_state' => '1' );

		self::assertFalse( $this->policy->has_catalog_filters() );
	}

	public function test_ignores_underscore_parameters_on_generic_content_search(): void {
		$this->configure_request_context( array( 'search' => true, 'post_type' => 'post' ) );
		$_GET = array( '_tracking_state' => '1' );

		self::assertFalse( $this->policy->has_catalog_filters() );
	}

	public function test_excludes_catalog_pagination_parameter(): void {
		$this->configure_request_context( array( 'shop' => true ) );
		$_GET = array( '_paged' => '2' );

		self::assertFalse( $this->policy->has_catalog_filters() );
	}

	/**
	 * @dataProvider woocommerce_filter_parameter_data
	 */
	public function test_detects_woocommerce_filter_parameters( string $key ): void {
		$this->configure_request_context( array( 'shop' => true ) );
		$_GET = array( $key => 'valor' );

		self::assertTrue( $this->policy->has_catalog_filters(), "{$key} should be noindex on catalog listings." );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function woocommerce_filter_parameter_data(): array {
		return array(
			'Sort order'         => array( 'orderby' ),
			'Minimum price'      => array( 'min_price' ),
			'Maximum price'      => array( 'max_price' ),
			'Stock filter'       => array( 'stock' ),
			'Sale filter'        => array( 'sale' ),
			'Items per page'     => array( 'per_page' ),
			'Rating filter'      => array( 'rating_filter' ),
			'Layout'             => array( 'layout' ),
			'Woo attribute'      => array( 'filter_color' ),
			'Woo query operator' => array( 'query_type_color' ),
		);
	}

	public function test_noindexes_transactional_parameters_on_every_public_surface(): void {
		$this->configure_request_context();
		$_GET = array( 'add-to-cart' => '123' );

		self::assertFalse( $this->policy->has_catalog_filters() );
		self::assertTrue( $this->policy->has_nonindexable_query_action() );
		self::assertSame(
			array(
				'index'  => 'noindex',
				'follow' => 'follow',
			),
			$this->policy->handle_rank_math_robots(
				array(
					'index'  => 'index',
					'follow' => 'nofollow',
				)
			)
		);

		$_GET = array( 'add-to-wishlist' => '123' );
		self::assertTrue( $this->policy->has_nonindexable_query_action() );
	}

	/**
	 * @testdox MailPoet utility URLs remain crawlable but are not indexable.
	 */
	public function test_noindexes_mailpoet_utility_urls_on_every_public_surface(): void {
		$this->configure_request_context();
		$_GET = array( 'mailpoet_page' => 'subscriptions' );

		self::assertFalse( $this->policy->has_catalog_filters() );
		self::assertTrue( $this->policy->has_nonindexable_query_action() );
		self::assertSame(
			array(
				'noindex' => true,
				'follow'  => true,
			),
			$this->policy->handle_wordpress_robots(
				array(
					'index'    => true,
					'nofollow' => true,
				)
			)
		);
		self::assertSame(
			array(
				'index'  => 'noindex',
				'follow' => 'follow',
			),
			$this->policy->handle_rank_math_robots(
				array(
					'index'  => 'index',
					'follow' => 'nofollow',
				)
			)
		);

		$_GET = array( 'mailpoet_page' => '' );
		self::assertTrue( $this->policy->has_nonindexable_query_action() );
	}

	/**
	 * @testdox Unrelated public query parameters preserve their robots directives.
	 */
	public function test_preserves_indexing_for_unrelated_public_query_parameters(): void {
		$this->configure_request_context();
		$_GET = array( 'utm_source' => 'newsletter' );

		$wp_robots = array( 'index' => true );
		$rank_math = array(
			'index'  => 'index',
			'follow' => 'follow',
		);

		self::assertFalse( $this->policy->has_catalog_filters() );
		self::assertFalse( $this->policy->has_nonindexable_query_action() );
		self::assertSame( $wp_robots, $this->policy->handle_wordpress_robots( $wp_robots ) );
		self::assertSame( $rank_math, $this->policy->handle_rank_math_robots( $rank_math ) );
	}

	public function test_ignores_all_query_policies_in_administration(): void {
		$this->configure_request_context( array( 'admin' => true, 'shop' => true ) );
		$_GET = array(
			'_autor'         => 'akira-toriyama',
			'add-to-cart'    => '123',
		);

		self::assertFalse( $this->policy->has_catalog_filters() );
		self::assertFalse( $this->policy->has_nonindexable_query_action() );
	}

	public function test_updates_wordpress_and_rank_math_robots_for_facets(): void {
		$this->configure_request_context( array( 'shop' => true ) );
		$_GET = array( '_autor' => 'akira-toriyama' );

		$wp_robots = $this->policy->handle_wordpress_robots(
			array(
				'index'    => true,
				'nofollow' => true,
			)
		);
		$rank_math = $this->policy->handle_rank_math_robots(
			array(
				'index'  => 'index',
				'follow' => 'nofollow',
			)
		);

		self::assertArrayNotHasKey( 'index', $wp_robots );
		self::assertArrayNotHasKey( 'nofollow', $wp_robots );
		self::assertTrue( $wp_robots['noindex'] );
		self::assertTrue( $wp_robots['follow'] );
		self::assertSame( 'noindex', $rank_math['index'] );
		self::assertSame( 'follow', $rank_math['follow'] );
	}

	public function test_preserves_non_array_rank_math_value(): void {
		$this->configure_request_context( array( 'shop' => true ) );
		$_GET = array( '_autor' => 'akira-toriyama' );

		self::assertSame( 'invalid', $this->policy->handle_rank_math_robots( 'invalid' ) );
	}

	public function test_returns_404_for_empty_woocommerce_price_filter(): void {
		$this->configure_request_context( array( 'shop' => true ) );
		$_GET = array(
			'min_price' => '0',
			'max_price' => '8000',
		);
		$GLOBALS['wp_query'] = (object) array( 'found_posts' => 0 );

		Functions\expect( 'status_header' )->once()->with( 404 );
		Functions\expect( 'nocache_headers' )->once();

		$this->policy->handle_template_redirect();
		$this->addToAssertionCount( 2 );
	}

	public function test_preserves_200_for_filtered_collection_with_results(): void {
		$this->configure_request_context( array( 'taxonomy' => true ) );
		$_GET = array( '_editorial' => 'ivrea-argentina' );
		$GLOBALS['wp_query'] = (object) array( 'found_posts' => 12 );

		Functions\expect( 'status_header' )->never();
		Functions\expect( 'nocache_headers' )->never();

		$this->policy->handle_template_redirect();
		$this->addToAssertionCount( 2 );
	}

	public function test_does_not_convert_unfiltered_empty_archive_to_404(): void {
		$this->configure_request_context( array( 'taxonomy' => true ) );
		$GLOBALS['wp_query'] = (object) array( 'found_posts' => 0 );

		Functions\expect( 'status_header' )->never();
		Functions\expect( 'nocache_headers' )->never();

		$this->policy->handle_template_redirect();
		$this->addToAssertionCount( 2 );
	}

	public function test_does_not_convert_transactional_query_to_404(): void {
		$this->configure_request_context( array( 'shop' => true ) );
		$_GET = array( 'add-to-cart' => '123' );
		$GLOBALS['wp_query'] = (object) array( 'found_posts' => 0 );

		Functions\expect( 'status_header' )->never();
		Functions\expect( 'nocache_headers' )->never();

		$this->policy->handle_template_redirect();
		$this->addToAssertionCount( 2 );
	}

	/**
	 * @testdox MailPoet utility URLs are not converted to empty-collection 404 responses.
	 */
	public function test_does_not_convert_mailpoet_utility_query_to_404(): void {
		$this->configure_request_context();
		$_GET = array( 'mailpoet_page' => 'subscriptions' );
		$GLOBALS['wp_query'] = (object) array( 'found_posts' => 0 );

		Functions\expect( 'status_header' )->never();
		Functions\expect( 'nocache_headers' )->never();

		$this->policy->handle_template_redirect();
		$this->addToAssertionCount( 2 );
	}

	public function test_empty_internal_text_search_stays_200_and_noindex(): void {
		$this->configure_request_context( array( 'shop' => true ) );
		$_GET = array( '_buscar' => 'resultado-inexistente' );
		$GLOBALS['wp_query'] = (object) array( 'found_posts' => 0 );

		Functions\expect( 'status_header' )->never();
		Functions\expect( 'nocache_headers' )->never();

		self::assertSame(
			array(
				'index'  => 'noindex',
				'follow' => 'follow',
			),
			$this->policy->handle_rank_math_robots(
				array(
					'index'  => 'index',
					'follow' => 'nofollow',
				)
			)
		);

		$this->policy->handle_template_redirect();
		$this->addToAssertionCount( 2 );
	}

	public function test_blank_internal_text_search_stays_200_and_noindex(): void {
		$this->configure_request_context( array( 'shop' => true ) );
		$_GET = array( '_buscar' => '' );
		$GLOBALS['wp_query'] = (object) array( 'found_posts' => 0 );

		Functions\expect( 'status_header' )->never();
		Functions\expect( 'nocache_headers' )->never();

		self::assertSame(
			array(
				'index'  => 'noindex',
				'follow' => 'follow',
			),
			$this->policy->handle_rank_math_robots(
				array(
					'index'  => 'index',
					'follow' => 'nofollow',
				)
			)
		);

		$this->policy->handle_template_redirect();
		$this->addToAssertionCount( 2 );
	}

	public function test_empty_text_search_with_a_real_facet_returns_404(): void {
		$this->configure_request_context( array( 'shop' => true ) );
		$_GET = array(
			'_buscar'    => 'resultado-inexistente',
			'_editorial' => 'ivrea-argentina',
		);
		$GLOBALS['wp_query'] = (object) array( 'found_posts' => 0 );

		Functions\expect( 'status_header' )->once()->with( 404 );
		Functions\expect( 'nocache_headers' )->once();

		$this->policy->handle_template_redirect();
		$this->addToAssertionCount( 2 );
	}

	/**
	 * @param array<string,mixed> $context Conditional values for the request.
	 */
	private function configure_request_context( array $context = array() ): void {
		Functions\when( 'is_admin' )->justReturn( (bool) ( $context['admin'] ?? false ) );
		Functions\when( 'wp_doing_ajax' )->justReturn( (bool) ( $context['ajax'] ?? false ) );
		Functions\when( 'wp_doing_cron' )->justReturn( (bool) ( $context['cron'] ?? false ) );
		Functions\when( 'is_shop' )->justReturn( (bool) ( $context['shop'] ?? false ) );
		Functions\when( 'is_product_taxonomy' )->justReturn( (bool) ( $context['taxonomy'] ?? false ) );
		Functions\when( 'is_search' )->justReturn( (bool) ( $context['search'] ?? false ) );
		Functions\when( 'get_query_var' )->alias(
			static fn( string $key ) => 'post_type' === $key ? ( $context['post_type'] ?? '' ) : ''
		);
	}
}
