<?php
/**
 * Pruebas de la entidad `ComicSeries` de las landings `pa_serie`.
 *
 * ── Por qué las pruebas usan el SeriesFacts REAL ─────────────────────────────
 * El contrato central de este módulo no es "emite un JSON con ciertas claves":
 * es "declara EXACTAMENTE los mismos hechos que la página muestra". Un doble de
 * `SeriesFacts` probaría el JSON contra números inventados por la propia prueba
 * y dejaría pasar justo el defecto que importa — que el schema y el `<dl>`
 * visible se separen. Por eso acá se construye el `SeriesFacts` de verdad sobre
 * un `$wpdb` doble, y varias pruebas comparan el JSON-LD contra el HTML que ese
 * mismo objeto rinde.
 *
 * ── Por qué setUp() declara TODOS los guards ────────────────────────────────
 * Brain Monkey define el símbolo en el runtime de PHP la primera vez que un test
 * lo mockea y esa definición SOBREVIVE al `tearDown()`: se limpia la
 * expectativa, no la función. Un test que no stubee `is_tax()` no recibe el
 * comportamiento nativo sino el stub vacío (null), así que los guards darían
 * falso y todos los asserts fallarían… pero sólo en las permutaciones donde otro
 * test corre antes. Verde en orden natural, rojo con `--order-by=random`, que es
 * la peor clase de verde. Misma disciplina que `SeriesFactsTest`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesSchema;

use Akibara\Business\Modules\SchemaProductRankMath\BookSchemaEnricher;
use Akibara\Business\Modules\SeriesFacts\SeriesFacts;
use Akibara\Business\Modules\SeriesSchema\SeriesSchema;
use Akibara\Business\Modules\SeriesSchema\SeriesSchemaType;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

/**
 * Doble de `$wpdb` que devuelve UNA fila agregada.
 *
 * Deliberadamente idéntico en forma al de `SeriesFactsTest`: lo que se prueba es
 * el MISMO agregado leído por otra superficie.
 */
final class SeriesSchemaWpdb {

	public string $prefix             = 'wp_';
	public string $posts              = 'wp_posts';
	public string $terms              = 'wp_terms';
	public string $term_taxonomy      = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $postmeta           = 'wp_postmeta';

	/** Fila que devuelve la agregación; null = error de base de datos. */
	public ?array $row = null;

	/** @var array<int,string> */
	public array $queries = array();

	/**
	 * @param string $query Consulta preparada.
	 * @return array<int,string>
	 */
	public function get_col( string $query ): array {
		$this->queries[] = $query;

		return array();
	}

	/**
	 * @param string $query  Consulta preparada.
	 * @param string $output Constante de formato.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $query, string $output = 'OBJECT' ) {
		unset( $output );
		$this->queries[] = $query;

		return $this->row;
	}

	/**
	 * @param string $query   Consulta con placeholders.
	 * @param mixed  ...$args Valores.
	 */
	public function prepare( string $query, ...$args ): string {
		$sql = $query;
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'";
			$sql         = preg_replace( '/%[sd]/', $replacement, $sql, 1 ) ?? $sql;
		}

		return $sql;
	}
}

final class SeriesSchemaTest extends TestCase {

	private const SERIES_URL = 'https://akibara.cl/serie/one-piece/';

	/** Fila "One Piece" verificada contra el catálogo real. */
	private const ONE_PIECE = array(
		'tomos'       => '107',
		'instock'     => '31',
		'preventa'    => '76',
		'agotados'    => '0',
		'price_min'   => '9500.0000',
		'price_max'   => '9990.0000',
		'updated'     => '2026-08-04 11:42:07',
		'editoriales' => "\x1e" . "Ivrea Argentina\x1f230",
	);

	/** @var mixed */
	private $original_wpdb = null;

	private SeriesSchemaWpdb $wpdb;

	/** @var array<string,mixed> */
	private array $transients = array();

	/** @var array<string,int> */
	private array $query_vars = array();

	/** @var array<int,array<int,string>> */
	private array $term_robots = array();

	/** @var array<int,mixed> */
	private array $term_schema_types = array();

	/** ¿Hay una campaña de descuento vigente? */
	private bool $discount_active = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_wpdb   = $GLOBALS['wpdb'] ?? null;
		$this->transients      = array();
		$this->query_vars      = array( 'paged' => 1 );
		$this->term_robots     = array();
		$this->term_schema_types = array();
		$this->discount_active = false;

		$this->wpdb      = new SeriesSchemaWpdb();
		$this->wpdb->row = self::ONE_PIECE;
		$GLOBALS['wpdb'] = $this->wpdb;

		// Guards de contexto: la URL canónica de una serie indexable, que es el
		// único request donde la entidad debe declararse.
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( $this->series_term() );
		Functions\when( 'get_query_var' )->alias(
			fn( string $key ): int => $this->query_vars[ $key ] ?? 0
		);
		Functions\when( 'get_term_meta' )->alias(
			fn( int $term_id, string $key = '', bool $single = false ) => match ( $key ) {
				'rank_math_robots'        => $this->term_robots[ $term_id ] ?? '',
				SeriesSchemaType::META_KEY => $this->term_schema_types[ $term_id ] ?? '',
				default                    => '',
			}
		);

		// Enlaces: `get_term_link()` recibe el WP_Term de la serie o el par
		// (term_id, taxonomía) de la editorial. Las dos formas conviven en el
		// código real, así que el doble las cubre.
		Functions\when( 'get_term_link' )->alias(
			static function ( $term, string $taxonomy = '' ): string {
				if ( is_object( $term ) ) {
					return 'https://akibara.cl/serie/' . $term->slug . '/';
				}

				return 'https://akibara.cl/marca/' . $taxonomy . '-' . (int) $term . '/';
			}
		);

		Functions\when( 'get_woocommerce_currency' )->justReturn( 'CLP' );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $text ): string => strip_tags( $text ) // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		);

		// Escapes y formato: los usa el render visible con el que se comparan
		// varias pruebas. Defaults deterministas, no opcionales.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias(
			static fn( $number ): string => number_format( (float) $number, 0, ',', '.' )
		);
		Functions\when( 'wc_price' )->alias(
			static fn( $price ): string => '$' . number_format( (float) $price, 0, ',', '.' )
		);
		Functions\when( 'wc_format_price_range' )->alias(
			static fn( $from, $to ): string => '$' . number_format( (float) $from, 0, ',', '.' )
				. ' &ndash; $' . number_format( (float) $to, 0, ',', '.' )
		);

		Functions\when( 'get_transient' )->alias(
			fn( string $key ) => $this->transients[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $ttl = 0 ): bool {
				unset( $ttl );
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Término de serie con `term_taxonomy_id` distinto del `term_id`. */
	private function series_term(): object {
		$term                   = new \WP_Term( 900, 'one-piece', 'One Piece' );
		$term->taxonomy         = 'pa_serie';
		$term->term_taxonomy_id = 378;
		$term->description      = 'La <b>gran</b> aventura pirata de Eiichiro Oda.';

		return $term;
	}

	private function facts(): SeriesFacts {
		return new SeriesFacts( fn (): bool => $this->discount_active );
	}

	private function module(): SeriesSchema {
		return new SeriesSchema( $this->facts() );
	}

	/** El `@graph` que Rank Math YA emite en la landing, medido en producción. */
	private function rank_math_graph(): array {
		return array(
			'Organization'    => array(
				'@type' => 'Organization',
				'@id'   => 'https://akibara.cl/#organization',
			),
			'WebSite'         => array(
				'@type' => 'WebSite',
				'@id'   => 'https://akibara.cl/#website',
			),
			'BreadcrumbList'  => array(
				'@type' => 'BreadcrumbList',
				'@id'   => self::SERIES_URL . '#breadcrumb',
			),
			'WebPage'         => array(
				'@type' => 'CollectionPage',
				'@id'   => self::SERIES_URL . '#webpage',
				'url'   => self::SERIES_URL,
			),
		);
	}

	/**
	 * @testdox Should declare the series entity inside the graph Rank Math already emits.
	 */
	public function test_declares_the_series_entity_inside_the_existing_graph(): void {
		$input  = $this->rank_math_graph();
		$result = $this->module()->handle_rank_math_json_ld( $input );

		self::assertArrayHasKey( 'ComicSeries', $result );
		$entity = $result['ComicSeries'];

		self::assertSame( 'ComicSeries', $entity['@type'] );
		// El `@id` lleva `#serie` y la `url` no: sin fragmento, esta entidad
		// colisiona con el `CollectionPage` y con el último breadcrumb, que en el
		// grafo real usan la URL pelada como identificador.
		self::assertSame( self::SERIES_URL . '#serie', $entity['@id'] );
		self::assertSame( self::SERIES_URL, $entity['url'] );
		self::assertNotSame( $entity['@id'], self::SERIES_URL . '#webpage' );
		self::assertNotSame( $entity['@id'], self::SERIES_URL . '#breadcrumb' );
		self::assertSame( 'One Piece', $entity['name'] );
		self::assertSame( 'es', $entity['inLanguage'] );

		// Los nodos que ya existían siguen intactos y son los mismos objetos: el
		// módulo AGREGA al grafo, no lo reescribe.
		foreach ( $this->rank_math_graph() as $key => $node ) {
			self::assertArrayHasKey( $key, $result );
			self::assertSame( $node['@type'], $result[ $key ]['@type'] );
			self::assertSame( $node['@id'], $result[ $key ]['@id'] );
		}
	}

	/**
	 * El autor viene del MISMO agregado que pinta el renglón visible «Autor:»:
	 * JSON-LD y bloque visible no pueden contradecirse.
	 *
	 * @testdox Should declare the author as a Person from the shared aggregate.
	 */
	public function test_declares_the_author_from_the_shared_aggregate(): void {
		$this->wpdb->row = array_merge(
			self::ONE_PIECE,
			array( 'autores' => "\x1e" . "Eiichiro Oda\x1f450" )
		);

		$entity = $this->module()->handle_rank_math_json_ld( $this->rank_math_graph() )['ComicSeries'];

		self::assertSame( 'Person', $entity['author']['@type'] );
		self::assertSame( 'Eiichiro Oda', $entity['author']['name'] );
	}

	/**
	 * @testdox Should omit the author when the aggregate has none.
	 */
	public function test_omits_the_author_without_aggregate_data(): void {
		$entity = $this->module()->handle_rank_math_json_ld( $this->rank_math_graph() )['ComicSeries'];

		self::assertArrayNotHasKey( 'author', $entity );
	}

	/**
	 * El contrato que hace que todo esto sirva para algo.
	 *
	 * @testdox Should use the exact same @id that every product card references.
	 */
	public function test_entity_id_matches_the_product_card_relationship(): void {
		$entity = $this->module()->handle_rank_math_json_ld( $this->rank_math_graph() )['ComicSeries'];

		// El `isPartOf` REAL de una ficha de esa serie, construido por el mismo
		// servicio que corre en las ~1.566 fichas de producto.
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 4242;
			}
		};
		Functions\when( 'get_the_terms' )->justReturn( array( $this->series_term() ) );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$is_part_of = ( new BookSchemaEnricher() )
			->restore_series_relationship( array( '@type' => array( 'Product', 'Book' ) ), $product )['isPartOf'];

		self::assertSame(
			$entity['@id'],
			$is_part_of['@id'],
			'Si el @id de la landing no es el que referencian las fichas, el grafo queda desconectado.'
		);
		// `url` es la dirección y `@id` el identificador: deben coincidir entre
		// superficies cada uno con su par, NO entre sí — el `@id` lleva `#serie`
		// justamente para no chocar con la URL de la página.
		self::assertSame( $entity['url'], $is_part_of['url'] );
		self::assertSame( $entity['@type'], $is_part_of['@type'] );
		self::assertStringEndsWith( '#serie', $is_part_of['@id'] );
		self::assertNotSame( $is_part_of['@id'], $is_part_of['url'] );
	}

	/**
	 * Lo que la entidad declara sí sale del mismo agregado que pinta el bloque.
	 *
	 * @testdox Should share its data with the visible block, editorial link included.
	 */
	public function test_declared_data_matches_the_visible_facts_block(): void {
		$facts  = $this->facts();
		$entity = ( new SeriesSchema( $facts ) )->handle_rank_math_json_ld( $this->rank_math_graph() )['ComicSeries'];

		ob_start();
		$facts->render();
		$html = (string) ob_get_clean();

		self::assertSame( 'One Piece', $entity['name'] );
		self::assertSame( 'es', $entity['inLanguage'] );
		self::assertSame( 'Ivrea Argentina', $entity['publisher']['name'] );
		self::assertSame( 'https://akibara.cl/marca/product_brand-230/', $entity['publisher']['url'] );
		self::assertStringContainsString( 'https://akibara.cl/marca/product_brand-230/', $html );
	}

	/**
	 * El precio NO se declara sobre la serie, y esto lo fija para que nadie lo
	 * reponga sin leer el porqué.
	 *
	 * `AggregateOffer` está definido por schema.org para UN producto ofrecido por
	 * VARIOS comerciantes; acá hay 107 productos y un solo comerciante, y Google
	 * pide expresamente no usarlo para variantes. Colgado de la `ComicSeries`, un
	 * `lowPrice: 9500` afirma que la SERIE cuesta $9.500 cuando comprarla entera
	 * son ~$1.037.000 — y el consumidor al que apunta este módulo es justo el que
	 * lo repetiría: «One Piece cuesta entre $9.500 y $9.990 en Akibara».
	 *
	 * @testdox Should never attach a price to the series entity.
	 */
	public function test_never_declares_a_price_on_the_series(): void {
		foreach ( array( self::ONE_PIECE, array_merge( self::ONE_PIECE, array( 'agotados' => '12' ) ) ) as $row ) {
			$this->wpdb->row = $row;
			$entity          = $this->module()->handle_rank_math_json_ld( $this->rank_math_graph() )['ComicSeries'];

			self::assertArrayNotHasKey( 'offers', $entity );
			foreach ( array( 'lowPrice', 'highPrice', 'price', 'offerCount', 'priceCurrency' ) as $clave ) {
				self::assertArrayNotHasKey( $clave, $entity );
			}
		}
	}

	/**
	 * `MAX(post_modified)` sube con cualquier venta. `SeriesFacts` ya rechazó esa
	 * lectura por escrito al rotular «Catálogo actualizado» en vez de
	 * «Actualizado»: lo que cambió es el inventario, no la obra. `dateModified`
	 * sobre una `ComicSeries` afirmaría justo lo que allí se descartó por falso.
	 *
	 * @testdox Should not claim the work was modified when only stock moved.
	 */
	public function test_does_not_claim_the_work_was_modified(): void {
		$facts  = $this->facts();
		$entity = ( new SeriesSchema( $facts ) )->handle_rank_math_json_ld( $this->rank_math_graph() )['ComicSeries'];

		self::assertArrayNotHasKey( 'dateModified', $entity );
		self::assertArrayNotHasKey( 'datePublished', $entity );

		// Pero el dato SÍ sigue visible, con el rótulo que no miente.
		ob_start();
		$facts->render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( '<time datetime="2026-08-04">', $html );
		self::assertStringContainsString( 'Catálogo actualizado', $html );
	}

	/**
	 * @testdox Should stay stable while a discount campaign overrides the catalog.
	 */
	public function test_omits_the_offer_under_an_active_discount_campaign(): void {
		$this->discount_active = true;

		$facts  = $this->facts();
		$entity = ( new SeriesSchema( $facts ) )->handle_rank_math_json_ld( $this->rank_math_graph() )['ComicSeries'];

		self::assertArrayNotHasKey( 'offers', $entity );

		// El bloque visible calla el precio por su propio motivo (durante una
		// campaña la lookup guarda el precio SIN descuento), y la entidad —que ya
		// no habla de precio en ningún caso— se declara igual que siempre.
		ob_start();
		$facts->render();
		$html = (string) ob_get_clean();
		self::assertStringNotContainsString( '9.500', $html );
		self::assertSame( 'One Piece', $entity['name'] );
		self::assertSame( 'Ivrea Argentina', $entity['publisher']['name'] );
	}

	/**
	 * @testdox Should not declare anything for a series without published catalog.
	 */
	public function test_skips_series_without_catalog(): void {
		$this->wpdb->row = array_merge(
			self::ONE_PIECE,
			array(
				'tomos'       => '0',
				'instock'     => '0',
				'preventa'    => '0',
				'agotados'    => '0',
				'price_min'   => null,
				'price_max'   => null,
				'editoriales' => '',
			)
		);

		self::assertSame(
			$this->rank_math_graph(),
			$this->module()->handle_rank_math_json_ld( $this->rank_math_graph() )
		);
	}

	/**
	 * @testdox Should not declare anything for a series the owner set to noindex.
	 */
	public function test_skips_noindex_series(): void {
		$this->term_robots[900] = array( 'noindex' );

		self::assertSame(
			$this->rank_math_graph(),
			$this->module()->handle_rank_math_json_ld( $this->rank_math_graph() )
		);
	}

	/**
	 * @testdox Should preserve CollectionPage without ComicSeries for an explicitly non-comic term.
	 */
	public function test_preserves_collection_page_for_an_explicitly_non_comic_term(): void {
		$this->term_schema_types[900] = SeriesSchemaType::NON_COMIC;
		$input                        = $this->rank_math_graph();

		$result = $this->module()->handle_rank_math_json_ld( $input );

		self::assertSame( $input, $result );
		self::assertSame( 'CollectionPage', $result['WebPage']['@type'] );
		self::assertArrayNotHasKey( 'ComicSeries', $result );
		self::assertArrayNotHasKey( 'mainEntity', $result['WebPage'] );
	}

	/** @testdox Should fail closed for unreadable robots metadata. */
	public function test_skips_series_with_unreadable_robots_metadata(): void {
		$this->term_robots[900] = 42;

		self::assertSame(
			$this->rank_math_graph(),
			$this->module()->handle_rank_math_json_ld( $this->rank_math_graph() )
		);
	}

	/**
	 * @testdox Should declare the series entity once, on the base series page only.
	 */
	public function test_skips_paginated_pages(): void {
		$this->query_vars['paged'] = 2;

		self::assertSame(
			$this->rank_math_graph(),
			$this->module()->handle_rank_math_json_ld( $this->rank_math_graph() )
		);
	}

	/**
	 * @testdox Should not touch the graph outside series archives.
	 */
	public function test_skips_requests_that_are_not_series_archives(): void {
		Functions\when( 'is_tax' )->justReturn( false );

		self::assertSame(
			$this->rank_math_graph(),
			$this->module()->handle_rank_math_json_ld( $this->rank_math_graph() )
		);
	}

	/**
	 * @testdox Should emit a single node and never a competing JSON-LD block.
	 */
	public function test_emits_one_node_without_a_second_json_ld_block(): void {
		$result = $this->module()->handle_rank_math_json_ld( $this->rank_math_graph() );

		self::assertCount( count( $this->rank_math_graph() ) + 1, $result );
		self::assertArrayNotHasKey( '@context', $result, 'Un @context propio significa un segundo bloque JSON-LD.' );
		self::assertArrayNotHasKey( '@graph', $result );

		// Y ningún `ItemList`: la grilla pagina de a 24 y la reemplaza el PJAX
		// del buscador, así que una lista rendida en el servidor dejaría de
		// describir la página. Ver el docblock del módulo.
		self::assertStringNotContainsString( 'ItemList', wp_json_encode_test( $result['ComicSeries'] ) );
	}

	/**
	 * @testdox Should point the CollectionPage at the series entity.
	 */
	public function test_points_the_collection_page_at_the_series(): void {
		$result = $this->module()->handle_rank_math_json_ld( $this->rank_math_graph() );

		self::assertSame( array( '@id' => self::SERIES_URL . '#serie' ), $result['WebPage']['mainEntity'] );
	}

	/**
	 * @testdox Should never overwrite a mainEntity owned by another provider.
	 */
	public function test_preserves_a_foreign_main_entity(): void {
		$input                          = $this->rank_math_graph();
		$input['WebPage']['mainEntity'] = array( '@id' => 'https://akibara.cl/#otra-cosa' );

		$result = $this->module()->handle_rank_math_json_ld( $input );

		self::assertSame( array( '@id' => 'https://akibara.cl/#otra-cosa' ), $result['WebPage']['mainEntity'] );
		self::assertArrayHasKey( 'ComicSeries', $result );
	}

	/**
	 * @testdox Should declare the curated description as plain text.
	 */
	public function test_declares_the_curated_description_without_markup(): void {
		$entity = $this->module()->handle_rank_math_json_ld( $this->rank_math_graph() )['ComicSeries'];

		self::assertSame( 'La gran aventura pirata de Eiichiro Oda.', $entity['description'] );
	}

	/**
	 * @testdox Should not invent an entity when the term link cannot be resolved.
	 */
	public function test_skips_when_the_term_link_is_unusable(): void {
		// `get_term_link()` devuelve WP_Error si la taxonomía aún no está
		// registrada. Sin `@id` no hay nada que las fichas puedan referenciar.
		Functions\when( 'get_term_link' )->justReturn( new \WP_Error() );

		self::assertSame(
			$this->rank_math_graph(),
			$this->module()->handle_rank_math_json_ld( $this->rank_math_graph() )
		);
	}
}

/**
 * Serialización mínima para inspeccionar el nodo sin depender de `wp_json_encode`.
 *
 * @param mixed $value Nodo.
 */
function wp_json_encode_test( $value ): string {
	return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}
