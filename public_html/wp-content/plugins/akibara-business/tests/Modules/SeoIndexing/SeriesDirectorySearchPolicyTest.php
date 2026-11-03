<?php
/**
 * Tests for the client-side series-directory search indexing policy.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeoIndexing;

use Akibara\Business\Modules\SeoIndexing\SeriesDirectorySearchPolicy;
use Akibara\Business\Modules\SeriesDirectory\Shortcode;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SeriesDirectorySearchPolicyTest extends TestCase {

	private SeriesDirectorySearchPolicy $sut;

	/** @var array<string,mixed> */
	private array $original_get = array();

	private bool $is_page = true;

	private object $post;

	private int $page = 1;

	private int $paged = 1;

	private object $wp_query;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->sut          = new SeriesDirectorySearchPolicy();
		$this->original_get = $_GET;
		$this->is_page      = true;
		$this->page         = 1;
		$this->paged        = 1;
		$this->post         = (object) array(
			'ID'           => 88,
			'post_content' => '[' . Shortcode::TAG . ']',
		);
		$this->wp_query     = new class() {
			public bool $was_set_404 = false;

			public function set_404(): void {
				$this->was_set_404 = true;
			}
		};
		$GLOBALS['wp_query'] = $this->wp_query;
		$_GET               = array();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_page' )->alias( fn(): bool => $this->is_page );
		Functions\when( 'get_post' )->alias( fn(): object => $this->post );
		Functions\when( 'has_shortcode' )->alias(
			static fn( string $content, string $tag ): bool => Shortcode::TAG === $tag
				&& false !== strpos( $content, '[' . $tag )
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( int $post_id ): string => 88 === $post_id ? 'https://akibara.cl/series/' : ''
		);
		Functions\when( 'get_query_var' )->alias(
			fn( string $key ): int => 'page' === $key
				? $this->page
				: ( 'paged' === $key ? $this->paged : 0 )
		);
		Functions\when( 'esc_url' )->returnArg();
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		unset( $GLOBALS['wp_query'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should register robots, both canonical integration points and the early response gate.
	 */
	public function test_registers_metadata_and_the_early_response_gate(): void {
		Monkey\Filters\expectAdded( 'wp_robots' )
			->once()
			->with( array( $this->sut, 'handle_wordpress_robots' ), 99 );
		Monkey\Filters\expectAdded( 'rank_math/frontend/robots' )
			->once()
			->with( array( $this->sut, 'handle_rank_math_robots' ), 99 );
		Monkey\Filters\expectAdded( 'rank_math/frontend/canonical' )
			->once()
			->with( array( $this->sut, 'handle_rank_math_canonical' ), 99 );
		Monkey\Filters\expectAdded( 'redirect_canonical' )
			->once()
			->with( array( $this->sut, 'handle_redirect_canonical' ), 99, 2 );
		Monkey\Actions\expectAdded( 'rank_math/head' )
			->once()
			->with( array( $this->sut, 'handle_rank_math_head_canonical' ), 20 );
		Monkey\Actions\expectAdded( 'template_redirect' )
			->once()
			->with( array( $this->sut, 'handle_template_redirect' ), 0 );

		$this->sut->register();
		$this->addToAssertionCount( 6 );
	}

	/**
	 * @testdox Should noindex the directory search and canonicalize it to the clean permalink.
	 */
	public function test_noindexes_search_and_uses_the_clean_directory_canonical(): void {
		$_GET = array(
			'buscar'     => 'one piece',
			'utm_source' => 'directorio',
		);

		$wp_robots = $this->sut->handle_wordpress_robots(
			array(
				'index'    => true,
				'nofollow' => true,
			)
		);
		$rank_math = $this->sut->handle_rank_math_robots(
			array(
				'index'  => 'index',
				'follow' => 'nofollow',
			)
		);

		self::assertTrue( $this->sut->applies_to_current_request() );
		self::assertSame( array( 'noindex' => true, 'follow' => true ), $wp_robots );
		self::assertSame( array( 'index' => 'noindex', 'follow' => 'follow' ), $rank_math );
		self::assertSame(
			'https://akibara.cl/series/',
			$this->sut->handle_rank_math_canonical( 'https://akibara.cl/series/?buscar=one%20piece' )
		);
	}

	/**
	 * @testdox Should treat an empty buscar value as a duplicate URL variant.
	 */
	public function test_noindexes_an_empty_search_parameter(): void {
		$_GET = array( 'buscar' => '' );

		self::assertTrue( $this->sut->applies_to_current_request() );
		self::assertSame(
			array( 'index' => 'noindex', 'follow' => 'follow' ),
			$this->sut->handle_rank_math_robots( array( 'index' => 'index', 'follow' => 'follow' ) )
		);
	}

	/**
	 * @testdox Should preserve unrelated parameters and pages without the directory shortcode.
	 */
	public function test_preserves_other_parameters_and_pages(): void {
		$robots   = array( 'index' => 'index', 'follow' => 'follow' );
		$canonical = 'https://akibara.cl/series/?utm_source=menu';
		$_GET      = array( 'utm_source' => 'menu' );

		self::assertSame( $robots, $this->sut->handle_rank_math_robots( $robots ) );
		self::assertSame( $canonical, $this->sut->handle_rank_math_canonical( $canonical ) );

		$_GET                    = array( 'buscar' => 'berserk' );
		$this->post->post_content = '[gallery]';
		self::assertSame( $robots, $this->sut->handle_rank_math_robots( $robots ) );

		$this->post->post_content = '[' . Shortcode::TAG . ']';
		$this->is_page            = false;
		self::assertSame( $robots, $this->sut->handle_rank_math_robots( $robots ) );
	}

	/**
	 * @testdox Should preserve unsupported Rank Math values and administration requests.
	 */
	public function test_preserves_unsupported_values_and_administration(): void {
		$_GET = array( 'buscar' => 'berserk' );

		self::assertSame( 'noindex,follow', $this->sut->handle_rank_math_robots( 'noindex,follow' ) );

		Functions\when( 'is_admin' )->justReturn( true );
		self::assertFalse( $this->sut->applies_to_current_request() );
	}

	/**
	 * @testdox Should recover a missing Rank Math canonical only inside the directory-search scope.
	 */
	public function test_recovers_false_and_null_canonical_values_only_inside_scope(): void {
		$_GET = array( 'buscar' => 'berserk' );

		foreach ( array( false, null ) as $canonical ) {
			self::assertSame( 'https://akibara.cl/series/', $this->sut->handle_rank_math_canonical( $canonical ) );
		}

		$this->is_page = false;
		self::assertFalse( $this->sut->handle_rank_math_canonical( false ) );
		self::assertNull( $this->sut->handle_rank_math_canonical( null ) );
	}

	/**
	 * @testdox Should print the scoped canonical after Rank Math removes its native noindex canonical.
	 */
	public function test_prints_a_rank_math_head_canonical_only_inside_scope(): void {
		$_GET = array( 'buscar' => 'berserk' );

		ob_start();
		$this->sut->handle_rank_math_head_canonical();
		$output = (string) ob_get_clean();

		self::assertSame( '<link rel="canonical" href="https://akibara.cl/series/" />' . "\n", $output );

		$this->is_page = false;
		ob_start();
		$this->sut->handle_rank_math_head_canonical();
		self::assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @testdox Should turn both WordPress pagination variables into a real noindex 404 without canonical.
	 * @dataProvider invalid_pagination_data
	 */
	public function test_rejects_fake_directory_pagination( int $page, int $paged ): void {
		$this->page  = $page;
		$this->paged = $paged;

		Functions\expect( 'status_header' )->once()->with( 404 );
		Functions\expect( 'nocache_headers' )->once();
		Functions\expect( 'do_action' )
			->once()
			->with( 'litespeed_control_set_nocache', 'akibara_series_directory_invalid_page' );

		self::assertTrue( $this->sut->has_invalid_pagination_request() );
		$this->sut->handle_template_redirect();

		self::assertTrue( $this->wp_query->was_set_404 );
		self::assertSame(
			array( 'index' => 'noindex', 'follow' => 'follow' ),
			$this->sut->handle_rank_math_robots( array( 'index' => 'index', 'follow' => 'follow' ) )
		);
		self::assertFalse( $this->sut->handle_rank_math_canonical( 'https://akibara.cl/series/' ) );
		self::assertFalse(
			$this->sut->handle_redirect_canonical(
				'https://akibara.cl/series/',
				'https://akibara.cl/series/page/999/'
			)
		);

		ob_start();
		$this->sut->handle_rank_math_head_canonical();
		self::assertSame( '', (string) ob_get_clean() );
	}

	/** @return array<string,array{int,int}> */
	public static function invalid_pagination_data(): array {
		return array(
			'singular page var' => array( 2, 1 ),
			'archive paged var' => array( 1, 999 ),
		);
	}

	/** @testdox Should preserve page one and every non-directory pagination surface. */
	public function test_preserves_page_one_and_non_directory_pagination(): void {
		Functions\expect( 'status_header' )->never();
		Functions\expect( 'nocache_headers' )->never();
		Functions\expect( 'do_action' )->never();

		$this->sut->handle_template_redirect();
		self::assertFalse( $this->sut->has_invalid_pagination_request() );
		self::assertFalse( $this->wp_query->was_set_404 );

		$this->page    = 3;
		$this->is_page = false;
		$this->sut->handle_template_redirect();
		self::assertFalse( $this->sut->has_invalid_pagination_request() );
		self::assertFalse( $this->wp_query->was_set_404 );
		self::assertSame(
			'https://akibara.cl/otra/page/3/',
			$this->sut->handle_redirect_canonical(
				'https://akibara.cl/otra/page/3/',
				'https://akibara.cl/otra/3/'
			)
		);
	}

	/** @testdox Should close a directory search to origin caches without changing its 200 status. */
	public function test_marks_search_response_no_store_without_changing_status(): void {
		$_GET = array( 'buscar' => 'one piece' );

		Functions\expect( 'status_header' )->never();
		Functions\expect( 'nocache_headers' )->once();
		Functions\expect( 'do_action' )
			->once()
			->with( 'litespeed_control_set_nocache', 'akibara_series_directory_search' );

		$this->sut->handle_template_redirect();

		self::assertTrue( defined( 'DONOTCACHEPAGE' ) );
		self::assertSame(
			array(
				'Cloudflare-CDN-Cache-Control: no-store',
				'CDN-Cache-Control: no-store',
			),
			SeriesDirectorySearchPolicy::edge_no_store_headers()
		);
		self::assertFalse( $this->wp_query->was_set_404 );
	}
}
