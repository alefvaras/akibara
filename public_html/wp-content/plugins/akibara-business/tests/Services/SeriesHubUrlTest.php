<?php
/**
 * SeriesHubUrl resuelve por el shortcode, no por el slug `/series/` (R2-8951cc1e).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Modules\SeriesDirectory\Shortcode;
use Akibara\Business\Services\SeriesHubUrl;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SeriesHubUrlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		SeriesHubUrl::reset();

		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test' . $path );
	}

	protected function tearDown(): void {
		SeriesHubUrl::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Sin ninguna página que contenga el shortcode, cae al literal /series/. */
	public function test_falls_back_to_the_literal_when_no_page_has_the_shortcode(): void {
		Functions\when( 'get_posts' )->justReturn( array() );

		self::assertSame( 'https://akibara.test/series/', SeriesHubUrl::url() );
	}

	/**
	 * El dueño movió el directorio a otro slug (Páginas → Editar, sin tocar
	 * código): la URL real, no `/series/`, tiene que ganar.
	 *
	 * @testdox Should resolve the real page when the owner renamed the slug.
	 */
	public function test_resolves_the_real_page_when_the_slug_moved(): void {
		Functions\when( 'get_posts' )->justReturn( array( 501 ) );
		Functions\when( 'get_post_field' )->alias(
			static fn( string $field, int $id ): string => 501 === $id ? '[' . Shortcode::TAG . ']' : ''
		);
		Functions\when( 'has_shortcode' )->alias(
			static fn( string $content, string $tag ): bool => false !== strpos( $content, '[' . $tag . ']' )
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( int $id ): string => 501 === $id ? 'https://akibara.test/todas-las-series/' : ''
		);

		self::assertSame( 'https://akibara.test/todas-las-series/', SeriesHubUrl::url() );
	}

	/** @testdox Una página que sólo MENCIONA el shortcode en prosa no cuenta; sigue buscando. */
	public function test_a_page_that_only_mentions_the_shortcode_is_skipped(): void {
		Functions\when( 'get_posts' )->justReturn( array( 401, 501 ) );
		Functions\when( 'get_post_field' )->alias(
			static fn( string $field, int $id ): string => match ( $id ) {
				401     => 'Usa el shortcode ' . Shortcode::TAG . ' para mostrar el directorio.',
				501     => '[' . Shortcode::TAG . ']',
				default => '',
			}
		);
		Functions\when( 'has_shortcode' )->alias(
			static fn( string $content, string $tag ): bool => false !== strpos( $content, '[' . $tag . ']' )
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( int $id ): string => 501 === $id ? 'https://akibara.test/todas-las-series/' : 'https://akibara.test/mencion/'
		);

		self::assertSame( 'https://akibara.test/todas-las-series/', SeriesHubUrl::url() );
	}

	/** @testdox Resuelve una sola vez por petición: la segunda llamada no vuelve a consultar. */
	public function test_resolves_once_per_request(): void {
		$consultas = 0;
		Functions\when( 'get_posts' )->alias(
			function () use ( &$consultas ): array {
				++$consultas;
				return array();
			}
		);

		SeriesHubUrl::url();
		SeriesHubUrl::url();

		self::assertSame( 1, $consultas );
	}
}
