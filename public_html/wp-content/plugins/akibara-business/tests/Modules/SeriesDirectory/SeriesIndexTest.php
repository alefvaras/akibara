<?php
/**
 * Pruebas del CONJUNTO y el ORDEN del directorio de series.
 *
 * ── Por qué setUp() declara todos los stubs, incluso los que un test no usa ──
 * Brain Monkey define la función en el runtime de PHP la primera vez que un test
 * la mockea, y esa definición SOBREVIVE al `Monkey\tearDown()`: lo que se limpia
 * es la expectativa, no el símbolo. Un test que después llame a `get_term_meta()`
 * sin declararla no recibe el comportamiento nativo, recibe el stub vacío que
 * devuelve null — y acá eso significaría «ningún término es noindex», con lo que
 * la prueba del noindex pasaría por el motivo equivocado en unas permutaciones y
 * fallaría en otras. Verde en orden natural, rojo con `--order-by=random`.
 *
 * Por eso el `setUp()` fija defaults deterministas de TODO lo que las reglas
 * tocan (misma disciplina que `SeriesFactsTest` y `DisponiblesRouteTest`).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesDirectory;

use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\SeriesDirectory\SeriesIndex;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Term;

final class SeriesIndexEligibilityWpdb {
	public string $posts = 'wp_posts';
	public string $terms = 'wp_terms';
	public string $term_taxonomy = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $postmeta            = 'wp_postmeta';
	/** @var list<array<string,mixed>> */
	public array $rows = array();
	public int $queries = 0;

	public function prepare( string $query, ...$args ): string {
		unset( $args );
		return $query;
	}

	/** @return list<array<string,mixed>> */
	public function get_results( string $query, string $output = 'OBJECT' ): array {
		unset( $query, $output );
		++$this->queries;
		return $this->rows;
	}
}

final class SeriesIndexTest extends TestCase {

	/**
	 * Términos que `get_terms()` devuelve en este test.
	 *
	 * @var list<WP_Term>
	 */
	private array $terms = array();

	/**
	 * Metas `rank_math_robots` por term_id.
	 *
	 * @var array<int, mixed>
	 */
	private array $robots = array();

	/** Argumentos con los que se llamó a `get_terms()`. */
	private array $get_terms_args = array();

	/** Visible catalogue counts that intentionally differ from raw term counts. */
	private array $catalog_counts = array();

	/**
	 * Tomos por término cuando NO coinciden con los productos: es lo que pasa
	 * cuando una serie tiene dentro una edición especial del mismo número.
	 *
	 * @var array<int,int>
	 */
	private array $volume_counts = array();

	private SeriesIndexEligibilityWpdb $eligibility_database;

	/** @var mixed */
	private $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->terms          = array();
		$this->robots         = array();
		$this->get_terms_args = array();
		$this->catalog_counts = array();
		$this->volume_counts  = array();
		$this->eligibility_database = new SeriesIndexEligibilityWpdb();
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = (object) array(
			'num_queries' => 0,
			'last_error'  => '',
			'ready'       => true,
		);

		Functions\when( 'get_terms' )->alias(
			function ( array $args ) {
				$this->get_terms_args = $args;

				// El doble respeta `hide_empty`, igual que el SQL real: si no lo
				// respetara, la prueba del término vacío estaría comprobando el
				// filtro defensivo de PHP y no la regla que corre en producción.
				$terms = $this->terms;
				if ( ! empty( $args['hide_empty'] ) ) {
					$terms = array_values(
						array_filter( $terms, static fn( WP_Term $t ): bool => $t->count > 0 )
					);
				}

				return $terms;
			}
		);

		Functions\when( 'get_term_meta' )->alias(
			fn( int $term_id, string $key, bool $single = false ) => $this->robots[ $term_id ] ?? ''
		);

		Functions\when( 'get_term_link' )->alias(
			static fn( $term ) => is_object( $term ) ? 'https://akibara.cl/serie/' . $term->slug . '/' : ''
		);

		// `remove_accents()` vive en wp-includes/formatting.php, que estas
		// pruebas no cargan. El doble cubre exactamente los diacríticos que el
		// catálogo real tiene hoy (Murió, Ñ).
		Functions\when( 'remove_accents' )->alias(
			static fn( string $value ): string => strtr(
				$value,
				array(
					'á' => 'a',
					'é' => 'e',
					'í' => 'i',
					'ó' => 'o',
					'ú' => 'u',
					'ñ' => 'n',
					'Á' => 'A',
					'É' => 'E',
					'Í' => 'I',
					'Ó' => 'O',
					'Ú' => 'U',
					'Ñ' => 'N',
				)
			)
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

	/** Construye un término `pa_serie` y lo agrega al conjunto del doble. */
	private function term( int $id, string $slug, string $name, int $count = 5 ): WP_Term {
		$term           = new WP_Term( $id, $slug, $name );
		$term->taxonomy = 'pa_serie';
		$term->count    = $count;
		$this->terms[]  = $term;

		return $term;
	}

	/** @return list<string> Nombres publicados, en orden. */
	private function names(): array {
		return array_map(
			static fn( array $entry ): string => $entry['name'],
			$this->index()->entries()
		);
	}

	private function index(): SeriesIndex {
		$this->eligibility_database->rows = array_map(
			fn( WP_Term $term ): array => array(
				'term_taxonomy_id' => $term->term_taxonomy_id,
				'product_count'     => $this->catalog_counts[ $term->term_id ] ?? $term->count,
				// Por defecto, tantos tomos como productos: hoy ninguna serie de
				// producción tiene dos productos del mismo número, y los casos que
				// sí quieren separar las dos cifras lo dicen con `volume_counts`.
				'volume_count'      => $this->volume_counts[ $term->term_id ] ?? ( $this->catalog_counts[ $term->term_id ] ?? $term->count ),
			),
			$this->terms
		);

		return new SeriesIndex( new SeriesCatalogEligibility( $this->eligibility_database ) );
	}

	/** @testdox Should revoke reliability when get_terms fails after a valid catalogue snapshot. */
	public function test_does_not_reuse_eligibility_reliability_after_a_term_query_failure(): void {
		$this->term( 9, 'tokyo-ghoul', 'Tokyo Ghoul', 1 );
		$index = $this->index();

		self::assertCount( 1, $index->entries() );
		self::assertTrue( $index->is_reliable() );

		Functions\when( 'get_terms' )->alias(
			static function (): array {
				++$GLOBALS['wpdb']->num_queries;
				$GLOBALS['wpdb']->last_error = 'temporary database failure';
				return array();
			}
		);

		self::assertSame( array(), $index->entries() );
		self::assertFalse( $index->is_reliable(), 'Un agregado viejo no vuelve autoritativa una consulta de términos fallida.' );
	}

	/**
	 * La regla es producto publicado y visible, no una lista de slugs prohibidos.
	 *
	 * @testdox Should leave out a series term that has no published products.
	 */
	public function test_leaves_out_a_series_without_products(): void {
		$this->term( 10, 'one-piece', 'One Piece', 107 );
		$this->term( 11, 'bleach', 'Bleach', 0 );

		self::assertSame( array( 'One Piece' ), $this->names() );
		self::assertFalse(
			$this->get_terms_args['hide_empty'],
			'La columna count no puede ocultar la diferencia entre catálogo público y producto oculto.'
		);
	}

	/**
	 * Enlazar una landing que le pedimos a Google que no indexe manda al lector
	 * a un callejón y gasta rastreo. Son 2 términos reales en producción:
	 * `pack-vinland-saga-123` y `shangri-la-frontier-1-expansion-pass`.
	 *
	 * @testdox Should leave out a series term marked noindex by Rank Math.
	 */
	public function test_leaves_out_a_noindex_series(): void {
		$this->term( 20, 'vinland-saga', 'Vinland Saga', 25 );
		$this->term( 21, 'pack-vinland-saga-123', 'Pack Vinland Saga 1+2+3', 1 );

		// Forma real en la base: array serializado que WordPress deserializa.
		$this->robots[21] = array( 'noindex' );

		self::assertSame( array( 'Vinland Saga' ), $this->names() );
	}

	/**
	 * Rank Math escribe esa meta desde su propia UI y este módulo no controla su
	 * forma: se acepta también la variante en cadena.
	 *
	 * @testdox Should also honour a noindex directive stored as a plain string.
	 */
	public function test_honours_a_noindex_stored_as_string(): void {
		$this->term( 30, 'monster', 'Monster', 9 );
		$this->term( 31, 'shangri-la-frontier-1-expansion-pass', 'Shangri La Frontier 1 Expansion Pass', 1 );
		$this->robots[31] = 'noindex,nofollow';

		self::assertSame( array( 'Monster' ), $this->names() );
	}

	/**
	 * Un valor ilegible falla cerrado en todas las superficies SEO. Así el hub
	 * nunca enlaza una landing que paginación, sitemap o ediciones relacionadas
	 * tuvieron que considerar incierta.
	 *
	 * @testdox Should exclude a series whose robots meta is unreadable.
	 */
	public function test_excludes_a_series_with_unreadable_robots_meta(): void {
		$this->term( 40, 'akira', 'Akira', 6 );
		$this->robots[40] = 42;

		self::assertSame( array(), $this->names() );
	}

	/**
	 * Los 10 términos de QA de producción (`qahardening*`, `qapreventacodex*`,
	 * `productoqaakibara*`) tienen todos `count = 0`, verificado el 2026-08-09
	 * contra la base de producción. Salen por la MISMA regla que cualquier otra
	 * serie vacía: no hay ni una lista negra de slugs, porque la próxima campaña
	 * de QA inventará nombres nuevos que ninguna lista contendría.
	 *
	 * @testdox Should leave out every QA term without touching a slug blocklist.
	 */
	public function test_leaves_out_qa_terms_by_the_same_rule(): void {
		$qa = array(
			'qahardeningdefaultcupo',
			'qahardeningdefaultcupopass',
			'qahardeningdefaultcuporecheck',
			'qahardeningdisponible',
			'qahardeningpreventaa',
			'qahardeningpreventab',
			'qamanualslotspreventa',
			'qapreventacodex20260430',
			'qadisponiblecodex20260430',
			'productoqaakibara8211checkoute2e',
		);

		$id = 100;
		foreach ( $qa as $slug ) {
			$this->term( $id++, $slug, $slug, 0 );
		}
		$this->term( 200, 'naruto', 'Naruto', 5 );

		self::assertSame( array( 'Naruto' ), $this->names() );
	}

	/**
	 * Sin URL no hay entrada: un nombre sin enlace no lleva a ninguna parte ni
	 * aporta rastreo. Degrada callado, como `SeriesFacts::term_markup()`.
	 *
	 * @testdox Should drop a series whose term link cannot be built.
	 */
	public function test_drops_a_series_without_a_usable_link(): void {
		$this->term( 50, 'nana', 'Nana', 21 );
		$this->term( 51, 'pluto', 'Pluto', 8 );

		Functions\when( 'get_term_link' )->alias(
			static fn( $term ) => 'pluto' === $term->slug ? null : 'https://akibara.cl/serie/' . $term->slug . '/'
		);

		self::assertSame( array( 'Nana' ), $this->names() );
	}

	/**
	 * El orden es una función del nombre, no del orden de llegada desde la base.
	 *
	 * Se alimenta el doble DESORDENADO a propósito: si `entries()` se limitara a
	 * confiar en el `orderby` del `get_terms()`, esta prueba sería la única que
	 * lo notaría.
	 *
	 * @testdox Should order series by their folded name regardless of input order.
	 */
	public function test_orders_by_folded_name_not_by_input_order(): void {
		$this->term( 60, 'zom-100', 'Zom 100', 12 );
		$this->term( 61, 'el-verano-que-hikaru-murio', 'El Verano que Hikaru Murió', 7 );
		$this->term( 62, 'akira', 'Akira', 6 );
		$this->term( 63, '20th-century-boys', '20th Century Boys', 11 );

		self::assertSame(
			array( '20th Century Boys', 'Akira', 'El Verano que Hikaru Murió', 'Zom 100' ),
			$this->names()
		);
	}

	/**
	 * Dos llamadas seguidas tienen que dar exactamente lo mismo: el HTML se
	 * cachea 6 h, así que un orden que dependa de algo no observable quedaría
	 * congelado sin que nadie sepa de dónde salió.
	 *
	 * @testdox Should produce the same order on every call.
	 */
	public function test_order_is_repeatable(): void {
		$this->term( 70, 'nana', 'Nana', 21 );
		$this->term( 71, 'naruto', 'Naruto', 5 );
		$this->term( 72, 'nausicaa', 'Nausicaa', 3 );

		$index = $this->index();

		self::assertSame( $index->entries(), $index->entries() );
	}

	/**
	 * Nombres que pliegan igual: el desempate explícito por `term_id` es lo que
	 * impide que decida el orden de llegada desde la base.
	 *
	 * @testdox Should break ties between equal folded names by term id.
	 */
	public function test_breaks_ties_by_term_id(): void {
		$this->term( 82, 'monster-b', 'Monster', 4 );
		$this->term( 81, 'monster-a', 'MONSTER', 9 );

		$entries = $this->index()->entries();

		self::assertSame( array( 81, 82 ), array_column( $entries, 'term_id' ) );
	}

	/**
	 * El artículo inicial NO se ignora: la etiqueta visible es la clave de
	 * orden, porque lo único que el lector puede predecir es la letra que ve
	 * escrita. Lo que resuelve «me acuerdo de *verano*» es el filtro por
	 * subcadena, que usa el `haystack` calculado acá.
	 *
	 * @testdox Should file a title with a leading Spanish article under that article.
	 */
	public function test_keeps_the_leading_article_for_bucketing(): void {
		$this->term( 90, 'el-pecado-original-de-takopi', 'El pecado original de Takopi', 2 );
		$this->term( 91, 'la-nobleza-de-las-flores', 'La nobleza de las flores', 11 );

		$grouped = $this->index()->grouped();

		self::assertSame( array( 'E', 'L' ), array_keys( $grouped ) );
		self::assertSame( 'el pecado original de takopi', $grouped['E'][0]['haystack'] );
	}

	/**
	 * @testdox Should fold accents when bucketing and when building the search text.
	 */
	public function test_folds_accents(): void {
		self::assertSame( 'M', SeriesIndex::bucket_of( 'Murió' ) );
		self::assertSame( 'N', SeriesIndex::bucket_of( 'Ñandú' ) );
		self::assertSame( 'el verano que hikaru murio', SeriesIndex::haystack( 'El Verano que Hikaru Murió' ) );
	}

	/**
	 * «20th Century Boys» no tiene letra. Un índice que sólo ofrece A–Z pierde
	 * esa serie; por eso existe el grupo `#`.
	 *
	 * @testdox Should bucket titles that start with a digit or sign under the hash group.
	 */
	public function test_buckets_non_letters_under_the_hash_group(): void {
		self::assertSame( '#', SeriesIndex::bucket_of( '20th Century Boys' ) );
		self::assertSame( '#', SeriesIndex::bucket_of( '¡Ánimo!' ) );
		self::assertSame( '#', SeriesIndex::bucket_of( '' ) );
	}

	/**
	 * @testdox Should list the hash group first and then A to Z.
	 */
	public function test_letters_start_with_the_hash_group(): void {
		$letters = SeriesIndex::letters();

		self::assertSame( '#', $letters[0] );
		self::assertSame( 'A', $letters[1] );
		self::assertSame( 'Z', $letters[26] );
		self::assertCount( 27, $letters );
	}

	/**
	 * Una sección vacía es ruido en el documento y un ancla rota en el índice.
	 *
	 * @testdox Should drop letter groups that have no series.
	 */
	public function test_drops_empty_letter_groups(): void {
		$this->term( 110, '20th-century-boys', '20th Century Boys', 11 );
		$this->term( 111, 'akira', 'Akira', 6 );
		$this->term( 112, 'zom-100', 'Zom 100', 12 );

		self::assertSame( array( '#', 'A', 'Z' ), array_keys( $this->index()->grouped() ) );
	}

	/**
	 * El número publicado usa el mismo universo visible que la landing y el
	 * schema; `term.count` también incluye productos excluidos del catálogo.
	 *
	 * @testdox Should publish the catalogue-visible count as the volume number.
	 */
	public function test_publishes_the_catalog_visible_count_as_the_volume_number(): void {
		$this->term( 120, 'one-piece', 'One Piece', 107 );
		$this->catalog_counts[120] = 31;

		self::assertSame( 31, $this->index()->entries()[0]['tomos'] );
	}

	/**
	 * El rótulo dice «N tomos en catálogo», así que cuenta TOMOS. Medido en vivo
	 * el 2026-09-11: /series/ publica «Demon Slayer … 23 tomos en catálogo». Con
	 * la edición especial dentro de la serie, contar productos dejaba esa fila
	 * diciendo 24 mientras la landing de esa misma serie dice «Completa en 23
	 * tomos» y su bloque de datos suma 12 + 11.
	 *
	 * @testdox Should publish the volume count, not the product count, when they differ.
	 */
	public function test_publishes_volumes_and_not_products(): void {
		$this->term( 435, 'demon-slayer', 'Demon Slayer', 24 );
		$this->catalog_counts[435] = 24;
		$this->volume_counts[435]  = 23;

		self::assertSame( 23, $this->index()->entries()[0]['tomos'] );
	}

	/**
	 * La elegibilidad sigue siendo de PRODUCTOS: una serie cuyo único producto
	 * es una variante existe para el catálogo —tiene una ficha comprable— aunque
	 * su recuento de tomos sea cero. Contarla por tomos la borraría del
	 * directorio y con ella su enlace interno.
	 *
	 * @testdox Should keep a series whose only product is a variant, with a zero volume label.
	 */
	public function test_keeps_a_series_whose_only_product_is_a_variant(): void {
		$this->term( 436, 'maximum-berserk', 'Maximum Berserk', 1 );
		$this->catalog_counts[436] = 1;
		$this->volume_counts[436]  = 0;

		$entries = $this->index()->entries();

		self::assertCount( 1, $entries, 'La serie sigue publicada: tiene una ficha comprable.' );
		self::assertSame( 0, $entries[0]['tomos'] );
	}

	/** @testdox Should exclude a hidden-only series even when WordPress reports a non-zero raw count. */
	public function test_excludes_a_hidden_only_series(): void {
		$this->term( 121, 'tokyo-ghoul', 'Tokyo Ghoul', 3 );
		$this->catalog_counts[121] = 0;

		self::assertSame( array(), $this->index()->entries() );
		self::assertFalse( $this->get_terms_args['hide_empty'] );
	}

	/** @testdox Should resolve all catalogue counts in one batch query. */
	public function test_batches_catalogue_eligibility_for_the_complete_index(): void {
		$this->term( 122, 'tokyo-ghoul', 'Tokyo Ghoul', 14 );
		$this->term( 123, 'tokyo-ghoul-re', 'Tokyo Ghoul:re', 16 );

		$this->index()->entries();

		self::assertSame( 1, $this->eligibility_database->queries );
	}

	/**
	 * El puente de ediciones suma la editorial, el país y los títulos alternativos
	 * de las ediciones relacionadas al `haystack` plegado, permitiendo que quien
	 * busca «fumando detras del super» encuentre también «Fumando atrás del súper con vos».
	 *
	 * @testdox Should enrich entries with brand, country, related edition names and cross-edition haystack.
	 */
	public function test_enriches_entries_with_origin_and_related_edition_haystack(): void {
		$this->term( 607, 'fumando-juntos-detras-del-super', 'Fumando juntos detrás del súper', 5 );
		$this->term( 653, 'fumando-atras-del-super-con-vos', 'Fumando atrás del súper con vos', 9 );

		Functions\when( 'get_term_meta' )->alias(
			function ( int $term_id, string $key, bool $single = false ) {
				unset( $single );
				if ( '_akb_serie_ediciones_relacionadas' === $key ) {
					if ( 607 === $term_id ) {
						return '653';
					}
					if ( 653 === $term_id ) {
						return '607';
					}
				}
				return $this->robots[ $term_id ] ?? '';
			}
		);

		$this->eligibility_database->rows = array(
			array(
				'term_taxonomy_id' => 607,
				'product_count'    => 5,
				'volume_count'     => 5,
				'brand_name'       => 'Panini Manga',
				'brand_slug'       => 'panini-manga',
				'country_name'     => 'España',
				'country_slug'     => 'espana',
			),
			array(
				'term_taxonomy_id' => 653,
				'product_count'    => 9,
				'volume_count'     => 9,
				'brand_name'       => 'Editorial Ivrea',
				'brand_slug'       => 'editorial-ivrea',
				'country_name'     => 'Argentina',
				'country_slug'     => 'argentina',
			),
		);

		$index   = new SeriesIndex( new SeriesCatalogEligibility( $this->eligibility_database ) );
		$entries = $index->entries();

		self::assertCount( 2, $entries );
		// 'Fumando atrás...' ordena antes que 'Fumando juntos...'
		$ivrea  = $entries[0];
		$panini = $entries[1];

		self::assertSame( 653, $ivrea['term_id'] );
		self::assertSame( 'Editorial Ivrea', $ivrea['brand_name'] );
		self::assertSame( 'editorial-ivrea', $ivrea['brand_slug'] );
		self::assertSame( 'Argentina', $ivrea['country_name'] );
		self::assertSame( 'argentina', $ivrea['country_slug'] );
		self::assertSame( array( 'Fumando juntos detrás del súper' ), $ivrea['related_names'] );
		self::assertStringContainsString( 'fumando juntos detras del super', $ivrea['haystack'] );
		self::assertStringContainsString( 'editorial ivrea', $ivrea['haystack'] );
		self::assertStringContainsString( 'argentina', $ivrea['haystack'] );

		self::assertSame( 607, $panini['term_id'] );
		self::assertSame( array( 'Fumando atrás del súper con vos' ), $panini['related_names'] );
		self::assertStringContainsString( 'fumando atras del super con vos', $panini['haystack'] );
	}
}
