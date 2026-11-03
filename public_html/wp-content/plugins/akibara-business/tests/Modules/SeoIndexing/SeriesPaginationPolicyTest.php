<?php
/**
 * Pruebas de elegibilidad e indexación de /serie/<slug>/page/N/.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeoIndexing;

use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\SeoIndexing\SeriesPaginationPolicy;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/** Minimal aggregate store used by the eligibility authority. */
final class SeriesPaginationEligibilityWpdb {
	public string $posts = 'wp_posts';
	public string $terms = 'wp_terms';
	public string $term_taxonomy = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $postmeta            = 'wp_postmeta';
	public int $count = 1;
	public int $queries = 0;
	public bool $fail = false;

	public function prepare( string $query, ...$args ): string {
		unset( $args );

		return $query;
	}

	/** @return array<int,array<string,string>>|null */
	public function get_results( string $query, string $output_type ) : ?array {
		unset( $query, $output_type );
		++$this->queries;
		if ( $this->fail ) {
			return null;
		}

		return $this->count > 0
			? array(
				array(
					'term_taxonomy_id' => '1900',
					'product_count'     => (string) $this->count,
				),
			)
			: array();
	}
}

final class SeriesPaginationPolicyTest extends TestCase {

	/** The System Under Test. */
	private SeriesPaginationPolicy $sut;

	private string $taxonomy = 'pa_serie';

	private int $paged = 1;

	private object $queried_term;

	private SeriesPaginationEligibilityWpdb $eligibility_database;

	/** @var mixed */
	private $robots_meta = '';

	/** @var array<string,mixed> */
	private array $original_get = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->eligibility_database = new SeriesPaginationEligibilityWpdb();
		$this->sut          = new SeriesPaginationPolicy(
			new SeriesCatalogEligibility( $this->eligibility_database )
		);
		$this->taxonomy     = 'pa_serie';
		$this->paged        = 1;
		$this->queried_term = (object) array(
			'term_id'          => 900,
			'term_taxonomy_id' => 1900,
			'taxonomy'         => 'pa_serie',
		);
		$this->robots_meta  = '';
		$this->original_get = $_GET;
		$_GET               = array();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->alias(
			fn( $requested ): bool => $requested === $this->taxonomy
		);
		Functions\when( 'get_query_var' )->alias(
			fn( string $key ): int => 'paged' === $key ? $this->paged : 0
		);
		Functions\when( 'get_queried_object' )->alias(
			fn(): object => $this->queried_term
		);
		Functions\when( 'get_term_meta' )->alias(
			fn( int $term_id, string $key, bool $single = false ) => 900 === $term_id && 'rank_math_robots' === $key
				? $this->robots_meta
				: ( $single ? '' : array() )
		);
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should register robots and canonical after Rank Math defaults.
	 */
	public function test_registers_robots_and_canonical_filters(): void {
		Monkey\Filters\expectAdded( 'rank_math/frontend/robots' )
			->once()
			->with( array( $this->sut, 'handle_rank_math_robots' ), 98 );
		Monkey\Filters\expectAdded( 'wp_robots' )
			->once()
			->with( array( $this->sut, 'handle_wordpress_robots' ), 98 );
		Monkey\Filters\expectAdded( 'rank_math/frontend/canonical' )
			->once()
			->with( array( $this->sut, 'handle_rank_math_canonical' ), 98 );

		$this->sut->register();
		$this->addToAssertionCount( 3 );
	}

	/**
	 * @testdox Should correct a global noindex setting on clean series pages two and later.
	 */
	public function test_affirms_index_follow_on_clean_series_subpages(): void {
		foreach ( array( 2, 3, 5, 17 ) as $page ) {
			$this->paged = $page;

			$robots = $this->sut->handle_rank_math_robots(
				array(
					'index'             => 'noindex',
					'follow'            => 'nofollow',
					'noindex'           => 'noindex',
					'max-image-preview' => 'max-image-preview:large',
				)
			);

			self::assertIsArray( $robots );
			self::assertSame( 'index', $robots['index'], "Page {$page} should be indexable." );
			self::assertSame( 'follow', $robots['follow'], "Page {$page} should remain crawlable." );
			self::assertArrayNotHasKey( 'noindex', $robots );
			self::assertSame( 'max-image-preview:large', $robots['max-image-preview'] );
		}
	}

	/**
	 * @testdox Should apply the same index verdict to WordPress robots.
	 */
	public function test_affirms_index_follow_in_wordpress_robots(): void {
		$this->paged = 2;

		$robots = $this->sut->handle_wordpress_robots(
			array(
				'noindex'  => true,
				'nofollow' => true,
			)
		);

		self::assertTrue( $robots['index'] );
		self::assertTrue( $robots['follow'] );
		self::assertArrayNotHasKey( 'noindex', $robots );
		self::assertArrayNotHasKey( 'nofollow', $robots );
	}

	/**
	 * INVARIANTE de caché: un catálogo vacío LEÍDO BIEN es un veredicto estable,
	 * y por tanto cacheable. La señal no-cache existe para la lectura incierta
	 * (ver `test_marks_unreliable_reads_as_uncacheable`); aplicarla también al
	 * cero legítimo condenaba a las 13 landings sin productos públicos a un
	 * render completo en cada visita y en cada paso de rastreador, para servir
	 * un `noindex,follow` que no cambia hasta que se publique un tomo — y ese
	 * día lo invalida la purga del catálogo, no el TTL.
	 *
	 * @testdox Should noindex a root or paginated landing whose products are all hidden from catalogue.
	 */
	public function test_noindexes_hidden_only_series_on_every_landing_page(): void {
		$this->eligibility_database->count = 0;
		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )->never();

		foreach ( array( 1, 2 ) as $page ) {
			$this->paged = $page;
			$rank_math   = $this->sut->handle_rank_math_robots(
				array(
					'index'  => 'index',
					'follow' => 'nofollow',
				)
			);
			$wp_robots  = $this->sut->handle_wordpress_robots(
				array(
					'index'    => true,
					'nofollow' => true,
				)
			);

			self::assertSame( 'noindex', $rank_math['index'] );
			self::assertSame( 'follow', $rank_math['follow'] );
			self::assertTrue( $wp_robots['noindex'] );
			self::assertTrue( $wp_robots['follow'] );
			self::assertArrayNotHasKey( 'index', $wp_robots );
		}

		self::assertSame( 1, $this->eligibility_database->queries, 'Both robots filters must share one aggregate result.' );
	}

	/**
	 * @testdox Should preserve an editorial noindex instead of replacing it with the automatic policy.
	 */
	public function test_manual_noindex_always_wins_for_hidden_only_series(): void {
		$this->eligibility_database->count = 0;
		$this->robots_meta                 = array( 'noindex', 'nofollow' );
		$rank_math = array(
			'index'  => 'noindex',
			'follow' => 'nofollow',
		);
		$wp_robots = array(
			'noindex'  => true,
			'nofollow' => true,
		);

		self::assertSame( $rank_math, $this->sut->handle_rank_math_robots( $rank_math ) );
		self::assertSame( $wp_robots, $this->sut->handle_wordpress_robots( $wp_robots ) );
	}

	/**
	 * @testdox Should preserve current robots and bypass page cache when eligibility cannot be read.
	 */
	public function test_database_failure_is_not_misread_as_an_empty_series(): void {
		$this->eligibility_database->fail = true;
		$rank_math = array(
			'index'  => 'index',
			'follow' => 'follow',
		);
		$wp_robots = array(
			'index'  => true,
			'follow' => true,
		);

		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )
			->once()
			->with( 'series catalogue eligibility unavailable' );

		self::assertSame( $rank_math, $this->sut->handle_rank_math_robots( $rank_math ) );
		self::assertSame( $wp_robots, $this->sut->handle_wordpress_robots( $wp_robots ) );
	}

	/**
	 * @testdox Should emit the current paginated permalink as self canonical.
	 */
	public function test_uses_the_current_paginated_permalink_as_canonical(): void {
		$this->paged = 3;
		Functions\expect( 'get_pagenum_link' )
			->once()
			->with( 3 )
			->andReturn( 'https://akibara.cl/serie/one-piece/page/3/' );

		self::assertSame(
			'https://akibara.cl/serie/one-piece/page/3/',
			$this->sut->handle_rank_math_canonical( 'https://akibara.cl/serie/one-piece/' )
		);
	}

	/**
	 * @testdox Should never overwrite noindex or canonical on filtered and sorted variants.
	 */
	public function test_delegates_every_query_variant_to_the_filtered_url_policy(): void {
		$this->paged = 2;

		foreach ( array( 'orderby', 'filter_editorial', '_autor', 'min_price' ) as $parameter ) {
			$_GET = array( $parameter => 'valor' );
			$rank_math = array(
				'index'  => 'noindex',
				'follow' => 'follow',
			);
			$wp_robots = array(
				'noindex' => true,
				'follow'  => true,
			);

			self::assertFalse( $this->sut->applies_to_current_request(), "{$parameter} must stay delegated." );
			self::assertSame( $rank_math, $this->sut->handle_rank_math_robots( $rank_math ) );
			self::assertSame( $wp_robots, $this->sut->handle_wordpress_robots( $wp_robots ) );
			self::assertSame(
				'https://akibara.cl/serie/one-piece/page/2/',
				$this->sut->handle_rank_math_canonical( 'https://akibara.cl/serie/one-piece/page/2/' )
			);
		}
	}

	/**
	 * @testdox Should preserve robots and canonical for a series term explicitly marked noindex.
	 */
	public function test_preserves_noindex_series_subpages_in_every_supported_storage_shape(): void {
		$this->paged = 2;
		$rank_math   = array(
			'index'  => 'noindex',
			'follow' => 'follow',
		);
		$wp_robots = array(
			'noindex' => true,
			'follow'  => true,
		);
		$canonical = 'https://akibara.cl/serie/pack-vinland-saga-123/';

		foreach (
			array(
				array( 'noindex' ),
				'a:1:{i:0;s:7:"noindex";}',
				'noindex,nofollow',
			) as $robots_meta
		) {
			$this->robots_meta = $robots_meta;

			self::assertFalse( $this->sut->applies_to_current_request() );
			self::assertSame( $rank_math, $this->sut->handle_rank_math_robots( $rank_math ) );
			self::assertSame( $wp_robots, $this->sut->handle_wordpress_robots( $wp_robots ) );
			self::assertSame( $canonical, $this->sut->handle_rank_math_canonical( $canonical ) );
		}
	}

	/**
	 * @testdox Should fail closed for corrupt robots metadata while keeping an absent value indexable.
	 */
	public function test_fails_closed_for_non_string_non_array_robots_metadata(): void {
		$this->paged = 2;
		$rank_math   = array(
			'index'  => 'noindex',
			'follow' => 'follow',
		);
		$wp_robots = array(
			'noindex' => true,
			'follow'  => true,
		);
		$canonical = 'https://akibara.cl/serie/one-piece/';

		$this->robots_meta = '';
		self::assertTrue( $this->sut->applies_to_current_request(), 'The normal absent-meta value must remain indexable.' );

		foreach ( array( (object) array(), 7, false, null ) as $robots_meta ) {
			$this->robots_meta = $robots_meta;

			self::assertFalse( $this->sut->applies_to_current_request() );
			self::assertSame( $rank_math, $this->sut->handle_rank_math_robots( $rank_math ) );
			self::assertSame( $wp_robots, $this->sut->handle_wordpress_robots( $wp_robots ) );
			self::assertSame( $canonical, $this->sut->handle_rank_math_canonical( $canonical ) );
		}
	}

	/**
	 * @testdox Should fail closed when any robots-array directive is not a string.
	 */
	public function test_fails_closed_for_mixed_corrupt_robots_arrays(): void {
		$this->paged = 2;

		foreach ( array( array( 'index', 'follow' ), array() ) as $robots_meta ) {
			$this->robots_meta = $robots_meta;
			self::assertTrue( $this->sut->applies_to_current_request(), 'A string-only array without noindex must remain indexable.' );
		}

		foreach (
			array(
				array( 'index', (object) array() ),
				array( 'follow', 7 ),
				array( (object) array() ),
				array( 7 ),
			) as $robots_meta
		) {
			$this->robots_meta = $robots_meta;
			self::assertFalse( $this->sut->applies_to_current_request(), 'Mixed, object and integer directives must fail closed.' );
		}

		$this->robots_meta = array( 'index', 'noindex', 'follow' );
		self::assertFalse( $this->sut->applies_to_current_request(), 'A valid string-array noindex directive must remain excluded.' );
	}

	/**
	 * @testdox Should leave page one and every other taxonomy untouched.
	 */
	public function test_keeps_the_scope_narrow(): void {
		$original = array(
			'index'  => 'noindex',
			'follow' => 'nofollow',
		);

		foreach ( array( 0, 1 ) as $page ) {
			$this->paged = $page;
			self::assertSame( $original, $this->sut->handle_rank_math_robots( $original ) );
		}

		$this->paged = 4;
		foreach ( array( 'product_cat', 'product_brand', 'pa_genero', 'pa_autor', 'category' ) as $taxonomy ) {
			$this->taxonomy = $taxonomy;
			self::assertSame( $original, $this->sut->handle_rank_math_robots( $original ), "{$taxonomy} is outside scope." );
		}
	}

	/**
	 * @testdox Should preserve unsupported Rank Math values and stay out of administration.
	 */
	public function test_preserves_unsupported_values_and_admin_requests(): void {
		$this->paged = 2;
		self::assertSame( 'noindex,follow', $this->sut->handle_rank_math_robots( 'noindex,follow' ) );
		self::assertNull( $this->sut->handle_rank_math_robots( null ) );
		self::assertNull( $this->sut->handle_rank_math_canonical( null ) );

		Functions\when( 'is_admin' )->justReturn( true );
		self::assertFalse( $this->sut->applies_to_current_request() );
	}

	/**
	 * @testdox Should never read or write Rank Math's global subpage option.
	 */
	public function test_never_touches_the_global_rank_math_option(): void {
		$source = (string) file_get_contents(
			(string) ( new \ReflectionClass( SeriesPaginationPolicy::class ) )->getFileName()
		);
		$code   = implode(
			'',
			array_map(
				static fn( $token ): string => is_array( $token )
					? ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? ' ' : $token[1] )
					: (string) $token,
				token_get_all( $source )
			)
		);

		self::assertStringNotContainsString( 'noindex_archive_subpages', $code );
		self::assertStringNotContainsString( 'update_option', $code );
		self::assertStringNotContainsString( 'rank-math-options', $code );
	}
}
