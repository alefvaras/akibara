<?php
/**
 * La portada que representa a una serie: el tomo más bajo con stock, o el
 * más bajo a secas si la serie entera está agotada.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Services\SeriesCover;
use Akibara\Business\Tests\Support\TitulosDeTomosWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SeriesCoverTest extends TestCase {

	/** Doble de $wpdb: «Frieren N» para el id 500 + N, y anota cada consulta. */
	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_the_post_thumbnail_url' )->alias( static fn( int $id ): string => 'https://akibara.test/p/frieren-' . ( $id - 500 ) . '.jpg' );

		$this->wpdb      = new TitulosDeTomosWpdb( static fn( int $id ): string => 'Frieren ' . ( $id - 500 ) );
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		\WP_Query::$akb_resolver = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Revisión de 1.116.8: `_prime_post_caches()` cargaba la fila entera de cada
	 * tomo —`post_content` incluido— para leer un título.
	 *
	 * @testdox Los títulos salen de UNA consulta que trae sólo el id y el título.
	 */
	public function test_titles_come_from_one_narrow_query(): void {
		SeriesCover::lowest_tomo_cover( array( 503, 502, 507 ) );

		self::assertCount( 1, $this->wpdb->consultas );
		self::assertStringStartsWith( 'SELECT ID, post_title FROM wp_posts WHERE ID IN (', $this->wpdb->consultas[0] );
		self::assertStringNotContainsString( 'post_content', $this->wpdb->consultas[0] );
		self::assertStringNotContainsString( '*', $this->wpdb->consultas[0] );
	}

	/** @testdox Con dos tomos del mismo número gana el primero de la lista, no el que devuelva antes la base. */
	public function test_ties_keep_the_order_of_the_ids(): void {
		Functions\when( 'get_the_post_thumbnail_url' )->alias( static fn( int $id ): string => 'https://akibara.test/p/' . $id . '.jpg' );
		$GLOBALS['wpdb'] = new TitulosDeTomosWpdb( static fn( int $id ): string => 611 === $id ? 'Frieren 01 (variante)' : 'Frieren 01' );

		self::assertSame( 'https://akibara.test/p/610.jpg', SeriesCover::lowest_tomo_cover( array( 610, 611 ) ) );
	}

	/** @testdox Entre varios tomos, la portada es la del número más bajo. */
	public function test_lowest_tomo_wins(): void {
		self::assertSame( 'https://akibara.test/p/frieren-2.jpg', SeriesCover::lowest_tomo_cover( array( 503, 502, 507 ) ) );
		self::assertSame( '', SeriesCover::lowest_tomo_cover( array() ) );
		self::assertSame( '', SeriesCover::lowest_tomo_cover( array( 0, -1 ) ) );
	}

	/** @testdox Con stock manda el stock; sin stock, el más bajo a secas; sin tomos, nada. */
	public function test_stock_first_then_anything(): void {
		$serie = array( array( 'taxonomy' => 'pa_serie', 'field' => 'term_id', 'terms' => array( 77 ) ) );

		\WP_Query::$akb_resolver = static fn( array $args ): array => isset( $args['meta_query'] ) ? array( 503 ) : array( 503, 501 );
		self::assertSame( 'https://akibara.test/p/frieren-3.jpg', SeriesCover::for_tax_query( $serie ), 'El tomo 1 está agotado: la portada es el 3, que sí tiene stock.' );

		\WP_Query::$akb_resolver = static fn( array $args ): array => isset( $args['meta_query'] ) ? array() : array( 503, 501 );
		self::assertSame( 'https://akibara.test/p/frieren-1.jpg', SeriesCover::for_tax_query( $serie ), 'Serie agotada entera: cae al más bajo (Frieren 2026-09-04).' );

		\WP_Query::$akb_resolver = static fn(): array => array();
		self::assertSame( '', SeriesCover::for_tax_query( $serie ) );
	}

	/** @testdox La consulta excluye lo oculto del catálogo y acota por la serie pedida. */
	public function test_query_excludes_hidden_products(): void {
		$seen = array();
		\WP_Query::$akb_resolver = static function ( array $args ) use ( &$seen ): array {
			$seen[] = $args;
			return array();
		};
		SeriesCover::for_tax_query( array( array( 'taxonomy' => 'pa_serie', 'field' => 'term_id', 'terms' => array( 77 ) ) ) );

		self::assertCount( 2, $seen );
		$taxonomias = array_column( $seen[0]['tax_query'], 'taxonomy' );
		self::assertContains( 'product_visibility', $taxonomias );
		self::assertContains( 'pa_serie', $taxonomias );
		self::assertSame( 'ids', $seen[0]['fields'] );
		self::assertArrayHasKey( 'meta_query', $seen[0] );
		self::assertArrayNotHasKey( 'meta_query', $seen[1] );
	}

	/**
	 * Hallazgo S-3832cae1: con tope de 40 y sin orden, WP_Query traía los 40
	 * publicados MÁS RECIENTES; en una serie importada por tandas son los de
	 * número más alto, y la portada salía del tomo 12 en vez del 1.
	 *
	 * @testdox Una serie de más de 40 tomos considera todos: la portada sigue siendo la del tomo más bajo.
	 */
	public function test_a_long_series_considers_every_tomo(): void {
		$seen = array();
		// 45 tomos, del más reciente (45) al más antiguo (1), como los ordena WP_Query por fecha.
		\WP_Query::$akb_resolver = static function ( array $args ) use ( &$seen ): array {
			$seen[] = $args;
			$ids    = range( 545, 501 );
			$tope   = (int) ( $args['posts_per_page'] ?? 10 );

			return $tope > 0 ? array_slice( $ids, 0, $tope ) : $ids;
		};

		self::assertSame(
			'https://akibara.test/p/frieren-1.jpg',
			SeriesCover::for_tax_query( array( array( 'taxonomy' => 'pa_serie', 'field' => 'term_id', 'terms' => array( 77 ) ) ) )
		);
		self::assertSame( -1, $seen[0]['posts_per_page'] ?? null, 'Sin tope: el número de tomo vive en el título y no se puede ordenar en SQL.' );
	}
}
