<?php
/**
 * Tests for SEO social metadata compatibility.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeoSocial;

use Akibara\Business\Modules\SeoSocial\SeoSocial;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the SeoSocial module.
 */
final class SeoSocialTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should register the official Rank Math Open Graph filter.
	 */
	public function test_registers_rank_math_open_graph_filter(): void {
		$module = new SeoSocial();

		Monkey\Filters\expectAdded( 'rank_math/opengraph/type' )
			->once()
			->with( array( $module, 'handle_open_graph_type' ) );
		Monkey\Filters\expectAdded( 'rank_math/opengraph/facebook/og_locale' )
			->once()
			->with( array( $module, 'handle_facebook_locale' ) );

		$module->register();
		self::addToAssertionCount( 2 );
	}

	/**
	 * @testdox Should represent the shop archive as a website collection.
	 */
	public function test_returns_website_for_shop(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'is_product_taxonomy' )->justReturn( false );

		self::assertSame( 'website', ( new SeoSocial() )->handle_open_graph_type( 'article' ) );
	}

	/**
	 * @testdox Should represent product taxonomies as website collections.
	 */
	public function test_returns_website_for_product_taxonomy(): void {
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_product_taxonomy' )->justReturn( true );

		self::assertSame( 'website', ( new SeoSocial() )->handle_open_graph_type( 'article' ) );
	}

	/**
	 * @testdox Should preserve the Rank Math type outside commerce collections.
	 */
	public function test_preserves_type_outside_commerce_collections(): void {
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_product_taxonomy' )->justReturn( false );

		self::assertSame( 'article', ( new SeoSocial() )->handle_open_graph_type( 'article' ) );
	}

	public function test_uses_supported_latin_american_facebook_locale(): void {
		$module = new SeoSocial();

		self::assertSame( 'es_LA', $module->handle_facebook_locale( 'es_ES' ) );
		self::assertSame( 'en_US', $module->handle_facebook_locale( 'en_US' ) );
	}
}
