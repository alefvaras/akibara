<?php
/**
 * BrandsHubUrl resuelve por el shortcode, no por el slug `/editoriales/` (R3-editoriales-hub).
 *
 * Mismo candado que `SeriesHubUrlTest` para el hermano de series (R2-8951cc1e):
 * la fuente única tiene que sobrevivir a que el dueño renombre o mueva la
 * página desde Páginas → Editar.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Modules\BrandsDirectory\Shortcode;
use Akibara\Business\Services\BrandsHubUrl;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class BrandsHubUrlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		BrandsHubUrl::reset();

		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test' . $path );
	}

	protected function tearDown(): void {
		BrandsHubUrl::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Sin ninguna página que contenga el shortcode, cae al literal /editoriales/. */
	public function test_falls_back_to_the_literal_when_no_page_has_the_shortcode(): void {
		Functions\when( 'get_posts' )->justReturn( array() );

		self::assertSame( 'https://akibara.test/editoriales/', BrandsHubUrl::url() );
	}

	/**
	 * El dueño movió el directorio a otro slug (Páginas → Editar, sin tocar
	 * código): la URL real, no `/editoriales/`, tiene que ganar.
	 *
	 * @testdox Should resolve the real page when the owner renamed the slug.
	 */
	public function test_resolves_the_real_page_when_the_slug_moved(): void {
		Functions\when( 'get_posts' )->justReturn( array( 601 ) );
		Functions\when( 'get_post_field' )->alias(
			static fn( string $field, int $id ): string => 601 === $id ? '[' . Shortcode::TAG . ']' : ''
		);
		Functions\when( 'has_shortcode' )->alias(
			static fn( string $content, string $tag ): bool => false !== strpos( $content, '[' . $tag . ']' )
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( int $id ): string => 601 === $id ? 'https://akibara.test/todas-las-editoriales/' : ''
		);

		self::assertSame( 'https://akibara.test/todas-las-editoriales/', BrandsHubUrl::url() );
	}

	/** @testdox Una página que sólo MENCIONA el shortcode en prosa no cuenta; sigue buscando. */
	public function test_a_page_that_only_mentions_the_shortcode_is_skipped(): void {
		Functions\when( 'get_posts' )->justReturn( array( 401, 601 ) );
		Functions\when( 'get_post_field' )->alias(
			static fn( string $field, int $id ): string => match ( $id ) {
				401     => 'Usa el shortcode ' . Shortcode::TAG . ' para mostrar el directorio.',
				601     => '[' . Shortcode::TAG . ']',
				default => '',
			}
		);
		Functions\when( 'has_shortcode' )->alias(
			static fn( string $content, string $tag ): bool => false !== strpos( $content, '[' . $tag . ']' )
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( int $id ): string => 601 === $id ? 'https://akibara.test/todas-las-editoriales/' : 'https://akibara.test/mencion/'
		);

		self::assertSame( 'https://akibara.test/todas-las-editoriales/', BrandsHubUrl::url() );
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

		BrandsHubUrl::url();
		BrandsHubUrl::url();

		self::assertSame( 1, $consultas );
	}
}
