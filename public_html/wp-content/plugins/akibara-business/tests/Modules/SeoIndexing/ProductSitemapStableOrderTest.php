<?php
/**
 * Tests for deterministic Rank Math product sitemap ordering.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeoIndexing;

use Akibara\Business\Modules\SeoIndexing\ProductSitemapStableOrder;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ProductSitemapStableOrderTest extends TestCase {

	private ProductSitemapStableOrder $module;

	protected function setUp(): void {
		parent::setUp();
		// Brain Monkey entró en esta suite el 2026-08-23: desde que el filtro
		// `query` se engancha perezosamente, marcar el WHERE llama a
		// add_filter() y sin las funciones de WordPress stubbeadas el test
		// moría con "undefined function".
		Monkey\setUp();
		// Default determinista: ninguna ficha declara canónica ajena salvo que
		// el test lo diga (el símbolo sobrevive al tearDown de otras clases).
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->module = new ProductSitemapStableOrder();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should mark only the product sitemap WHERE clause.
	 */
	public function test_marker_is_scoped_to_exact_product_post_type(): void {
		$where = "AND p.ID NOT IN (10)";

		self::assertSame( $where, $this->module->mark_product_sitemap_where( $where, 'post' ) );
		self::assertSame( $where, $this->module->mark_product_sitemap_where( $where, array( 'product' ) ) );
		self::assertSame(
			$where . ' ' . ProductSitemapStableOrder::QUERY_MARKER,
			$this->module->mark_product_sitemap_where( $where, 'product' )
		);
	}

	/**
	 * @testdox Should add the marker idempotently.
	 */
	public function test_product_marker_is_idempotent(): void {
		$once = $this->module->mark_product_sitemap_where( '', 'product' );

		self::assertSame( $once, $this->module->mark_product_sitemap_where( $once, 'product' ) );
		self::assertSame( 1, substr_count( $once, ProductSitemapStableOrder::QUERY_MARKER ) );
	}

	/**
	 * La invariante que fija este test es «el filtro global no existe hasta
	 * que hace falta», no la lista literal de hooks: `query` lo dispara
	 * `wpdb::query()` en cada sentencia de cada petición, y este módulo sólo
	 * tiene algo que reescribir cuando Rank Math está armando el sitemap de
	 * productos. Antes se enganchaba en register(), o sea en el 100% de las
	 * peticiones del sitio.
	 *
	 * @testdox Should not hook the global query filter at registration time.
	 */
	public function test_register_does_not_hook_the_global_query_filter(): void {
		Monkey\Filters\expectAdded( 'rank_math/sitemap/get_posts/where' )->once();
		Monkey\Actions\expectAdded( 'akibara_business_upgraded' )->once();

		$this->module->register();

		self::assertFalse(
			(bool) has_filter( 'query', array( $this->module, 'stabilize_product_sitemap_query' ) ),
			'register() no debe dejar el filtro `query` colgado en peticiones que no son de sitemap.'
		);
	}

	/**
	 * @testdox Should hook the query filter once when the product WHERE is marked.
	 */
	public function test_query_filter_is_hooked_once_when_marking_the_product_where(): void {
		$this->module->register();

		$this->module->mark_product_sitemap_where( '', 'post' );
		self::assertFalse(
			(bool) has_filter( 'query', array( $this->module, 'stabilize_product_sitemap_query' ) ),
			'Un sitemap que no es de productos no debe enganchar el filtro global.'
		);

		$marked = $this->module->mark_product_sitemap_where( '', 'product' );
		self::assertNotFalse(
			has_filter( 'query', array( $this->module, 'stabilize_product_sitemap_query' ) ),
			'El filtro tiene que estar activo ANTES de que Rank Math ejecute la consulta que acaba de marcar.'
		);

		// Rank Math puede construir varias consultas de producto en la misma
		// petición (índice y páginas). El filtro se engancha una vez y NO se
		// desengancha tras la primera sustitución: quitarlo dejaría a las
		// consultas siguientes con el ORDER BY inestable.
		$this->module->mark_product_sitemap_where( $marked, 'product' );
		$this->module->mark_product_sitemap_where( '', 'product' );
		self::assertNotFalse(
			has_filter( 'query', array( $this->module, 'stabilize_product_sitemap_query' ) ),
			'El filtro debe seguir activo para la segunda consulta de productos de la misma petición.'
		);
	}

	/**
	 * @testdox Should leave unmarked queries unchanged.
	 */
	public function test_unmarked_query_is_unchanged(): void {
		$query = 'SELECT * FROM wp_posts p ORDER BY p.post_modified DESC LIMIT 200';

		self::assertSame( $query, $this->module->stabilize_product_sitemap_query( $query ) );
	}

	/**
	 * @testdox Should fail safely when Rank Math changes the expected SQL shape.
	 */
	public function test_marked_query_with_different_order_is_unchanged(): void {
		$query = 'SELECT * FROM wp_posts p '
			. ProductSitemapStableOrder::QUERY_MARKER
			. ' ORDER BY p.post_date DESC LIMIT 200';

		self::assertSame( $query, $this->module->stabilize_product_sitemap_query( $query ) );
	}

	/**
	 * @testdox Should avoid an ambiguous rewrite when the expected token occurs twice.
	 */
	public function test_marked_query_with_multiple_expected_tokens_is_unchanged(): void {
		$query = ProductSitemapStableOrder::QUERY_MARKER
			. ' ORDER BY p.post_modified DESC LIMIT 10 '
			. 'ORDER BY p.post_modified DESC LIMIT 20';

		self::assertSame( $query, $this->module->stabilize_product_sitemap_query( $query ) );
	}

	/**
	 * @testdox Should add an ID tie-breaker to the exact marked Rank Math query.
	 */
	public function test_exact_marked_query_gets_stable_order(): void {
		$query = 'SELECT DISTINCT p.ID FROM wp_posts p '
			. ProductSitemapStableOrder::QUERY_MARKER
			. ' ORDER BY p.post_modified DESC LIMIT 200 OFFSET 200';

		$stable = $this->module->stabilize_product_sitemap_query( $query );

		self::assertStringContainsString( 'ORDER BY p.post_modified DESC, p.ID DESC LIMIT 200 OFFSET 200', $stable );
		self::assertStringNotContainsString( 'ORDER BY p.post_modified DESC LIMIT', $stable );
		self::assertSame( $stable, $this->module->stabilize_product_sitemap_query( $stable ) );
	}

	/**
	 * @testdox Should hook the sitemap index entry filter with three arguments after Rank Math's own.
	 */
	public function test_register_hooks_the_index_entry_filter(): void {
		Filters\expectAdded( 'rank_math/sitemap/index/entry' )->once()->with( \Mockery::type( 'array' ), 20, 3 );
		Filters\expectAdded( 'rank_math/sitemap/get_posts/where' )->once();
		Actions\expectAdded( 'akibara_business_upgraded' )->once();

		$this->module->register();

		self::assertTrue( (bool) has_filter( 'rank_math/sitemap/index/entry', array( $this->module, 'handle_sitemap_index_entry' ) ) );
	}

	/**
	 * R8-lastmod-del-indice (2026-09-20): Rank Math daba a las 9 páginas del
	 * sitemap de producto el `lastmod` de los productos más recientes de TODA
	 * la tienda. El módulo lo recalcula por página con la ventana de Rank Math
	 * (lector inyectado aquí), su descarte posterior y la fecha que escribe en
	 * el hijo: `max( post_modified_gmt, post_date_gmt )`.
	 *
	 * @testdox Should compute each product sitemap page's lastmod from its own window, skipping what Rank Math skips.
	 */
	public function test_index_lastmod_is_computed_per_product_page(): void {
		$this->rank_math_page_size( 200 );
		// La ficha 14 declara canónica ajena: `get_url()` la descarta del hijo.
		Functions\when( 'get_post_meta' )->alias( static fn( int $id, string $key ): string => 14 === $id && 'rank_math_canonical_url' === $key ? 'https://otro.sitio/x/' : '' );
		$ventanas = array();
		$module   = new ProductSitemapStableOrder(
			static function ( int $count, int $offset ) use ( &$ventanas ): array {
				$ventanas[] = array( $count, $offset );
				return array(
					(object) array( 'ID' => 10, 'post_modified_gmt' => '2026-09-10 12:34:56', 'post_date_gmt' => '2026-01-01 00:00:00' ),
					(object) array( 'ID' => 11, 'post_modified_gmt' => '2026-09-19 23:59:59', 'post_date_gmt' => '2026-01-01 00:00:00' ), // Excluido: consume puesto, no fecha.
					(object) array( 'ID' => 12, 'post_modified_gmt' => '2026-08-01 00:00:00', 'post_date_gmt' => '2026-09-11 08:00:00' ), // Publicado después de modificado.
					(object) array( 'ID' => 13, 'post_modified_gmt' => '0000-00-00 00:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ),
					(object) array( 'ID' => 14, 'post_modified_gmt' => '2026-09-18 00:00:00', 'post_date_gmt' => '2026-01-01 00:00:00' ), // Canónica ajena: no sale en el hijo.
					'basura',
				);
			},
			static fn( int $id ): bool => 11 !== $id
		);

		$entry = $module->handle_sitemap_index_entry(
			array( 'loc' => 'https://akibara.cl/product-sitemap3.xml', 'lastmod' => '2026-09-19T20:00:00+00:00' ),
			'post',
			'product'
		);

		self::assertSame( '2026-09-11T08:00:00+00:00', $entry['lastmod'] );
		self::assertSame( array( array( 200, 400 ) ), $ventanas, 'Página 3 con 200 por página empieza en el 400.' );
	}

	/**
	 * Revisión R8 (H1): el tamaño de página sale del ajuste REAL de Rank Math
	 * (`rank-math-options-sitemap`, como lo lee su generador), no de una option
	 * inventada; sin ajuste guardado, 100 como el generador.
	 *
	 * @testdox Should read the page size from Rank Math's real option and treat the unnumbered sitemap as page one.
	 */
	public function test_index_lastmod_honours_rank_math_page_size(): void {
		$this->rank_math_page_size( 50 );
		$ventanas = array();
		$module   = new ProductSitemapStableOrder(
			static function ( int $count, int $offset ) use ( &$ventanas ): array {
				$ventanas[] = array( $count, $offset );
				return array( (object) array( 'ID' => 1, 'post_modified_gmt' => '2026-08-01 00:00:00', 'post_date_gmt' => '2026-07-01 00:00:00' ) );
			},
			static fn( int $id ): bool => true
		);

		$entry = $module->handle_sitemap_index_entry( array( 'loc' => 'https://akibara.cl/product-sitemap.xml' ), 'post', 'product' );
		self::assertSame( '2026-08-01T00:00:00+00:00', $entry['lastmod'] );
		self::assertSame( array( array( 50, 0 ) ), $ventanas );

		$this->rank_math_page_size( null );
		$module->handle_sitemap_index_entry( array( 'loc' => 'https://akibara.cl/product-sitemap4.xml' ), 'post', 'product' );
		self::assertSame( array( 100, 300 ), $ventanas[1], 'Sin ajuste guardado, 100 por página como el generador de Rank Math.' );
	}

	/**
	 * @testdox Should leave other index entries alone and keep Rank Math's lastmod when the window is empty or the reader breaks.
	 */
	public function test_index_lastmod_leaves_foreign_entries_and_empty_pages_alone(): void {
		$this->rank_math_page_size( 200 );
		$leidas = 0;
		$module = new ProductSitemapStableOrder(
			static function () use ( &$leidas ): array {
				++$leidas;
				return array();
			},
			static fn( int $id ): bool => true
		);

		$ajena = array( 'loc' => 'https://akibara.cl/post-sitemap.xml', 'lastmod' => 'x' );
		self::assertSame( $ajena, $module->handle_sitemap_index_entry( $ajena, 'post', 'post' ) );
		self::assertSame( $ajena, $module->handle_sitemap_index_entry( $ajena, 'term', 'product' ) );
		self::assertSame( 'no-array', $module->handle_sitemap_index_entry( 'no-array', 'post', 'product' ) );
		self::assertSame( 0, $leidas, 'Sin entrada de producto no se lee ninguna ventana.' );

		$vacia = array( 'loc' => 'https://akibara.cl/product-sitemap9.xml', 'lastmod' => 'rank-math' );
		self::assertSame( $vacia, $module->handle_sitemap_index_entry( $vacia, 'post', 'product' ) );
		self::assertSame( 1, $leidas );

		$roto = new ProductSitemapStableOrder(
			static function (): array {
				throw new \RuntimeException( 'Rank Math a medio cargar' );
			},
			static fn( int $id ): bool => true
		);
		self::assertSame( $vacia, $roto->handle_sitemap_index_entry( $vacia, 'post', 'product' ), 'Un lector roto deja la fecha de Rank Math, no tumba el índice.' );
	}

	/**
	 * Sin `\RankMath\Helper` en la suite, el módulo lee la option real de Rank
	 * Math; el stub comprueba el NOMBRE para que la prueba no sea verde por
	 * construcción (revisión R8, H9).
	 */
	private function rank_math_page_size( ?int $size ): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( $size ) {
				if ( 'rank-math-options-sitemap' !== $name ) {
					return $default;
				}
				return null === $size ? array() : array( 'items_per_page' => (string) $size );
			}
		);
	}
}
