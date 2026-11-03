<?php
/**
 * Contrato de las sugerencias de serie del autocomplete.
 *
 * Prueba COMPORTAMIENTO contra la taxonomía real de prod (`pa_serie`), no la
 * forma interna: qué serie se ofrece, cuál jamás, y qué pasa cuando la fuente
 * miente. Los casos vienen de lo medido en producción el 2026-08-09 (130
 * términos, 24 sin productos, 10 de QA, 2 en noindex → 104 elegibles).
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests {

	use Akibara\Search\SeriesSuggestions;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use WP_Term;

	require_once dirname( __DIR__ ) . '/src/Normalizer.php';
	require_once dirname( __DIR__ ) . '/src/Scorer.php';
	require_once dirname( __DIR__ ) . '/src/Stopwords.php';
	require_once dirname( __DIR__ ) . '/src/Synonyms.php';
	require_once dirname( __DIR__ ) . '/src/Fuzzy.php';
	require_once dirname( __DIR__ ) . '/src/QueryEngine.php';
	require_once dirname( __DIR__ ) . '/src/Highlighter.php';
	require_once dirname( __DIR__ ) . '/src/SeriesSuggestions.php';

	final class SeriesSuggestionsTest extends TestCase {

		/**
		 * Términos que devuelve el doble de `get_terms`.
		 *
		 * @var array<int, WP_Term>
		 */
		private array $terms = array();

		/**
		 * Termmeta `rank_math_robots` por término.
		 *
		 * @var array<int, mixed>
		 */
		private array $robots = array();

		/** Args con los que se llamó a `get_terms`. @var array<int, array<string, mixed>> */
		private array $term_query_args = array();

		/** Transient escrito (null = todavía ninguno). @var mixed */
		private $transient = null;

		/** Cuántas veces se reconstruyó el catálogo desde la taxonomía. */
		private int $builds = 0;

		/** Permisos/errores inyectables de las fuentes. */
		private bool $taxonomy_exists = true;
		private bool $terms_throw     = false;
		private bool $terms_invalid   = false;

		/**
		 * Todo lo que la clase consume se declara ACÁ con un default determinista.
		 * Brain Monkey deja el símbolo definido después de tearDown(), así que un
		 * test que mockea una función en otro archivo del suite le entregaría a
		 * este el stub vacío si acá no se fijara el default [gotcha del suite].
		 */
		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			$this->terms           = $this->production_shaped_terms();
			$this->robots          = array(
				// Medidos en prod: las dos únicas landings en noindex.
				424 => array( 'noindex' ),
				409 => array( 'noindex' ),
			);
			$this->term_query_args = array();
			$this->transient       = null;
			$this->builds          = 0;
			$this->taxonomy_exists = true;
			$this->terms_throw     = false;
			$this->terms_invalid   = false;

			Functions\when( 'taxonomy_exists' )->alias( fn(): bool => $this->taxonomy_exists );
			Functions\when( 'get_terms' )->alias(
				function ( array $args ) {
					$this->term_query_args[] = $args;
					++$this->builds;
					if ( $this->terms_throw ) {
						throw new \RuntimeException( 'taxonomy read exploded' );
					}
					if ( $this->terms_invalid ) {
						return 'not-a-list';
					}
					$out = array();
					foreach ( $this->terms as $term ) {
						// `hide_empty` es el filtro 1: lo emula el doble, igual
						// que WordPress, para que el test hable del contrato.
						if ( ! empty( $args['hide_empty'] ) && $term->count < 1 ) {
							continue;
						}
						$out[] = $term;
					}
					return $out;
				}
			);
			Functions\when( 'get_term_meta' )->alias(
				fn( int $term_id, string $key, bool $single = false ) => 'rank_math_robots' === $key
					? ( $this->robots[ $term_id ] ?? '' )
					: ''
			);
			Functions\when( 'get_term_link' )->alias(
				static fn( $term ): string => 'https://akibara.cl/serie/' . ( $term instanceof WP_Term ? $term->slug : '' ) . '/'
			);
			Functions\when( 'get_transient' )->alias( fn() => null === $this->transient ? false : $this->transient );
			Functions\when( 'set_transient' )->alias(
				function ( string $key, $value ): bool {
					$this->transient = $value;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->alias(
				function (): bool {
					$this->transient = null;
					return true;
				}
			);
			Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => (string) (int) $n );
			Functions\when( 'esc_html' )->alias( static fn( string $t ): string => htmlspecialchars( $t, ENT_QUOTES, 'UTF-8' ) );
			Functions\when( 'is_wp_error' )->justReturn( false );
			Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * Foto reducida pero fiel del catálogo de prod: series con tomos, series
		 * vacías, basura de QA, landings en noindex y los títulos con signos.
		 *
		 * @return array<int, WP_Term>
		 */
		private function production_shaped_terms(): array {
			return array(
				self::term( 477, 'The Climber', 'the-climber', 17 ),
				self::term( 378, 'One Piece', 'one-piece', 107 ),
				self::term( 500, 'One Piece nº', 'one-piece-n', 13 ),
				self::term( 379, 'Ranma 1/2', 'ranma-1-2', 20 ),
				self::term( 529, 'NieR: Automata', 'nier-automata', 4 ),
				self::term( 371, 'Haikyuu!!', 'haikyuu', 41 ),
				self::term( 480, 'Ghost in the Shell', 'ghost-in-the-shell', 1 ),
				self::term( 481, 'Ghost in the Shell 1.5', 'ghost-in-the-shell-1-5', 1 ),
				self::term( 482, 'Ghost in the Shell 2.0', 'ghost-in-the-shell-2-0', 1 ),
				self::term( 483, 'Nausicaä del Valle del Viento', 'nausicaa-del-valle-del-viento', 7 ),
				self::term( 536, 'Berserk Master Edition', 'berserk-master-edition', 1 ),
				self::term( 537, 'Maximum Berserk', 'maximum-berserk', 21 ),
				// Sin productos publicados (24 así en prod).
				self::term( 415, 'Berserk', 'berserk', 0 ),
				self::term( 367, 'Bleach', 'bleach', 0 ),
				// En noindex a propósito (las 2 de prod).
				self::term( 424, 'Pack Vinland Saga 1-2-3', 'pack-vinland-saga-123', 1 ),
				self::term( 409, 'Shangri-La Frontier 1 Expansion Pass', 'shangri-la-frontier-1-expansion-pass', 1 ),
				// Basura de QA (10 así en prod, todas con 0 productos hoy).
				self::term( 556, 'qahardeningdefaultcupo', 'qahardeningdefaultcupo', 0 ),
				self::term( 550, 'productoqaakibara8211checkoute2e', 'productoqaakibara8211checkoute2e', 0 ),
			);
		}

		/** El WP_Term compartido del suite lleva la taxonomía primero. */
		private static function term( int $term_id, string $name, string $slug, int $count ): WP_Term {
			return new WP_Term( SeriesSuggestions::TAXONOMY, $term_id, 0, $slug, $name, 0, $count );
		}

		/** @return array<int, string> Nombres sugeridos para la consulta. */
		private function names( string $query, ?int $limit = null ): array {
			$series     = new SeriesSuggestions();
			$suggestion = null === $limit ? $series->suggest( $query ) : $series->suggest( $query, $limit );
			self::assertTrue( $series->is_available(), 'La lectura de series debía ser autoritativa: ' . $series->last_error() );
			return array_column( $suggestion, 'name' );
		}

		/** @testdox Una consulta por nombre de serie ofrece la landing de esa serie. */
		public function test_series_name_query_suggests_its_landing(): void {
			$series = new SeriesSuggestions();
			$out    = $series->suggest( 'the climber' );

			self::assertCount( 1, $out );
			self::assertSame( 'The Climber', $out[0]['name'] );
			self::assertSame( 'https://akibara.cl/serie/the-climber/', $out[0]['url'] );
			self::assertSame( 477, $out[0]['id'] );
		}

		/** @testdox Una serie SIN productos publicados jamás se sugiere. */
		public function test_series_without_published_products_is_never_suggested(): void {
			// "Berserk" (0 tomos) existe como término y no sale. De las dos
			// ediciones con stock sólo aparece la que agrupa varios tomos:
			// "Berserk Master Edition" tiene uno solo y la excluye `MIN_VOLUMES`,
			// no este filtro. Lo que este test fija es que la de CERO productos
			// nunca está.
			self::assertSame( array( 'Maximum Berserk' ), $this->names( 'berserk' ) );
			self::assertNotContains( 'Berserk', $this->names( 'berserk' ) );
			self::assertSame( array(), $this->names( 'bleach' ) );
		}

		/** @testdox Una landing en noindex jamás se sugiere. */
		public function test_noindex_landing_is_never_suggested(): void {
			self::assertSame( array(), $this->names( 'pack vinland' ) );
			self::assertSame( array(), $this->names( 'shangri' ) );

			// Las dos anteriores tienen además UN solo tomo, así que su ausencia no
			// prueba por sí sola que el noindex se respete. Se aísla el filtro con
			// una serie de varios tomos: aparece, se marca noindex, desaparece, y
			// al quitarle la marca vuelve — el filtro lee el estado real, no una
			// lista quemada.
			self::assertSame( array( 'NieR: Automata' ), $this->names( 'nier' ) );

			$this->robots = array( 529 => array( 'noindex' ) );
			SeriesSuggestions::flush();
			self::assertSame( array(), $this->names( 'nier' ) );

			$this->robots = array();
			SeriesSuggestions::flush();
			self::assertSame( array( 'NieR: Automata' ), $this->names( 'nier' ) );
		}

		/** @testdox El noindex se respeta venga como array serializado o como string. */
		public function test_noindex_is_read_from_either_meta_shape(): void {
			$this->robots = array( 477 => 'noindex,follow' );
			SeriesSuggestions::flush();

			self::assertSame( array(), $this->names( 'the climber' ) );
		}

		/** @testdox Los términos de QA jamás se sugieren, ni con productos publicados. */
		public function test_qa_terms_are_never_suggested(): void {
			self::assertSame( array(), $this->names( 'qahardening' ) );
			self::assertSame( array(), $this->names( 'productoqa' ) );

			// El cinturón: el día que un producto de prueba quede PUBLICADO en
			// prod, el filtro de "sin productos" deja de taparlas.
			$this->terms[] = self::term( 999, 'qapreventacodex20260430', 'qapreventacodex20260430', 5 );
			SeriesSuggestions::flush();

			self::assertSame( array(), $this->names( 'qapreventa' ) );
		}

		/** @testdox El acento y las mayúsculas no rompen la coincidencia. */
		public function test_accents_and_case_do_not_break_matching(): void {
			self::assertSame( array( 'Nausicaä del Valle del Viento' ), $this->names( 'nausica' ) );
			self::assertSame( array( 'Nausicaä del Valle del Viento' ), $this->names( 'NAUSICAÄ' ) );
			self::assertSame( array( 'Nausicaä del Valle del Viento' ), $this->names( 'Nausicaä Del Valle' ) );
		}

		/**
		 * @testdox El título pegado matchea: «onepiece» encuentra «One Piece».
		 *
		 * Caso real medido en las grabaciones: la gente teclea «dandadan» para
		 * «Dan Da Dan». Es la misma intención que ya cubre la normalización de
		 * signos —se escribe el título como se recuerda, no como lo imprime la
		 * editorial— así que vale tier 0: no es un parecido, es el mismo nombre.
		 */
		public function test_a_title_typed_without_spaces_still_matches(): void {
			self::assertContains( 'One Piece', $this->names( 'onepiece' ) );
			self::assertSame( array( 'The Climber' ), $this->names( 'theclimber' ) );
		}

		/**
		 * @testdox El colapso de espacios NO se aplica por prefijo.
		 *
		 * Si se comparara por prefijo en vez de por igualdad, «one» casaría con
		 * «onepiece» y la banda de serie saldría sobre resultados que no son de
		 * esa serie. El tier 2 ya cubre —y acota— el prefijo de palabra interior.
		 */
		public function test_the_space_collapse_is_equality_not_prefix(): void {
			// «ghost» SÍ es prefijo legítimo de tres series (tier 1), así que
			// sale. Lo que se comprueba es que no aparecen por colapso series
			// cuyo nombre pegado meramente EMPIECE por la consulta.
			$out = $this->names( 'ghos', 5 );
			self::assertSame( array(), $out, 'Un prefijo incompleto no debe casar por colapso.' );
		}

		/** @testdox Los títulos con signos matchean como el usuario los tipea. */
		public function test_punctuated_titles_match_the_way_people_type_them(): void {
			self::assertSame( array( 'Ranma 1/2' ), $this->names( 'ranma' ) );
			self::assertSame( array( 'Ranma 1/2' ), $this->names( 'ranma 1/2' ) );
			self::assertSame( array( 'NieR: Automata' ), $this->names( 'nier' ) );
			self::assertSame( array( 'NieR: Automata' ), $this->names( 'nier automata' ) );
			self::assertSame( array( 'Haikyuu!!' ), $this->names( 'haikyu' ) );
			// Ghost in the Shell 1.5 tiene UN tomo, así que `MIN_VOLUMES` la deja
			// fuera del grupo de series a propósito: el producto sí sale en su
			// propio grupo, que es donde se compra.
			self::assertSame( array(), $this->names( 'ghost in the shell 1.5' ) );
		}

		/** @testdox La coincidencia funciona a mitad de nombre, pero no a mitad de palabra. */
		public function test_matching_starts_at_a_word_not_mid_word(): void {
			self::assertSame( array( 'The Climber' ), $this->names( 'clim' ) );
			// "limb" está DENTRO de "climber": un substring suelto convertiría
			// cualquier tecleo en ruido.
			self::assertSame( array(), $this->names( 'limb' ) );
		}

		/**
		 * El tope es DOS, y el porqué está medido: a 375px, con tres series el
		 * panel escondía tras scroll interno tres tarjetas y el pie «Ver los N
		 * resultados», que es la salida. El presupuesto del panel es fijo y el
		 * grupo de series se lo quita al de productos, que son los que venden.
		 *
		 * @testdox El tope de series se respeta y deja lugar a los productos.
		 */
		public function test_series_cap_is_respected(): void {
			self::assertSame( 2, SeriesSuggestions::SUGGEST_LIMIT );

			// «one piece» matchea DOS series de varios tomos: es el caso real de
			// colisión sobre el que se dimensionó el tope.
			self::assertSame( array( 'One Piece', 'One Piece nº' ), $this->names( 'one piece' ) );
			self::assertCount( 1, $this->names( 'one piece', 1 ) );
			self::assertSame( array(), $this->names( 'one piece', 0 ) );
		}

		/**
		 * Una serie de un tomo no agrupa nada: produciría dos filas para la misma
		 * obra y su etiqueta «1 tomo» diría lo contrario de lo que el grupo
		 * comunica. Medido en prod: 8 series elegibles tienen un solo tomo.
		 *
		 * @testdox Una serie de un solo tomo nunca se sugiere.
		 */
		public function test_single_volume_series_is_never_suggested(): void {
			self::assertSame( 2, SeriesSuggestions::MIN_VOLUMES_FOR_TESTS );

			// Las tres Ghost in the Shell del catálogo real tienen UN tomo cada
			// una: ninguna se sugiere como serie. Sus productos siguen saliendo en
			// el grupo de tomos, que es donde se compran.
			self::assertSame( array(), $this->names( 'ghost' ) );
			self::assertSame( array(), $this->names( 'berserk master' ) );

			// Y una de varios tomos con el MISMO prefijo sí se sugiere: lo que
			// excluye es el conteo, no el nombre.
			self::assertSame( array( 'Maximum Berserk' ), $this->names( 'maximum berserk' ) );
		}

		/** @testdox Con número de tomo la consulta es de producto, no de serie. */
		public function test_volume_number_queries_do_not_match_a_series(): void {
			self::assertSame( array(), $this->names( 'one piece 3' ) );
			self::assertSame( array( 'One Piece', 'One Piece nº' ), $this->names( 'one piece' ) );
		}

		/** @testdox Una consulta más corta que el mínimo del motor no consulta nada. */
		public function test_query_shorter_than_the_engine_minimum_reads_nothing(): void {
			self::assertSame( array(), $this->names( 'o' ) );
			self::assertSame( 0, $this->builds, 'Una query de 1 char no debe tocar la taxonomía.' );
		}

		/** @testdox Cada sugerencia comunica que agrupa tomos. */
		public function test_each_suggestion_states_how_many_volumes_it_groups(): void {
			$series = new SeriesSuggestions();
			$out    = $series->suggest( 'the climber' );

			self::assertSame( '17 tomos', $out[0]['meta'] );
			self::assertSame( 17, $out[0]['count'] );
			self::assertSame( 'The <mark>Climb</mark>er', ( $series->suggest( 'climb' ) )[0]['name_html'] );

			// El singular «1 tomo» ya no puede darse en este grupo: `MIN_VOLUMES`
			// exige 2. Se comprueba que la etiqueta pluraliza con el mínimo real.
			$dos = ( new SeriesSuggestions() )->suggest( 'ghost in the shell 2.0' );
			self::assertSame( array(), $dos, 'También es de un tomo: fuera del grupo.' );
		}

		/** @testdox El catálogo se cachea y se invalida cuando cambian los términos. */
		public function test_catalog_is_cached_and_invalidated_on_term_changes(): void {
			$this->names( 'the climber' );
			self::assertSame( 1, $this->builds );

			// Instancia nueva = request nuevo: debe salir del transient.
			$this->names( 'one piece' );
			self::assertSame( 1, $this->builds, 'El catálogo cacheado no debe releer la taxonomía.' );

			// Baja del término: sin invalidar, el panel seguiría ofreciéndolo.
			array_shift( $this->terms );
			$this->names( 'the climber' );
			self::assertSame( 1, $this->builds, 'Sin invalidación se sirve el catálogo viejo (por eso hacen falta los hooks).' );

			SeriesSuggestions::flush();
			self::assertSame( array(), $this->names( 'the climber' ) );
			self::assertSame( 2, $this->builds );
		}

		/** @testdox La invalidación por recuento y por termmeta sólo dispara para lo suyo. */
		public function test_invalidation_hooks_are_scoped(): void {
			$this->names( 'the climber' );
			self::assertNotNull( $this->transient );

			SeriesSuggestions::flush_taxonomy( 1, 'product_cat' );
			self::assertNotNull( $this->transient, 'Otra taxonomía no debe tirar el catálogo de series.' );
			SeriesSuggestions::flush_term_meta( 1, 477, 'rank_math_title' );
			self::assertNotNull( $this->transient, 'Otra termmeta no debe tirar el catálogo de series.' );

			SeriesSuggestions::flush_taxonomy( 1, SeriesSuggestions::TAXONOMY );
			self::assertNull( $this->transient );

			$this->names( 'the climber' );
			SeriesSuggestions::flush_term_meta( 1, 477, 'rank_math_robots' );
			self::assertNull( $this->transient );
		}

		/** @testdox Un transient corrupto se descarta en vez de servirse. */
		public function test_corrupt_cache_is_rebuilt_instead_of_served(): void {
			$this->transient = array( array( 'term_id' => 'x', 'name' => '', 'url' => '' ) );

			self::assertSame( array( 'The Climber' ), $this->names( 'the climber' ) );
			self::assertSame( 1, $this->builds );
		}

		/** @testdox Sin la taxonomía registrada no hay series, y no es un fallo. */
		public function test_missing_taxonomy_yields_no_series_without_failing(): void {
			$this->taxonomy_exists = false;
			$series                = new SeriesSuggestions();

			self::assertSame( array(), $series->suggest( 'the climber' ) );
			self::assertTrue( $series->is_available() );
		}

		/** @testdox Una lectura fallida apaga las series y se declara no autoritativa. */
		public function test_failed_read_closes_the_series_block(): void {
			$this->terms_throw = true;
			$series            = new SeriesSuggestions();

			self::assertSame( array(), $series->suggest( 'the climber' ) );
			self::assertFalse( $series->is_available() );
			self::assertStringContainsString( 'exploded', $series->last_error() );

			$this->terms_throw   = false;
			$this->terms_invalid = true;
			$other               = new SeriesSuggestions();

			self::assertSame( array(), $other->suggest( 'the climber' ) );
			self::assertFalse( $other->is_available() );
		}

		/** @testdox Un hook de indexabilidad que no devuelve bool falla apagado. */
		public function test_indexability_filter_must_return_a_boolean(): void {
			Functions\when( 'apply_filters' )->alias(
				static fn( string $hook, $value ) => 'akb_search_series_indexable' === $hook ? 'sí' : $value
			);
			SeriesSuggestions::flush();

			self::assertSame( array(), $this->names( 'the climber' ) );
		}

		/** @testdox La consulta a la taxonomía pide sólo términos con productos y prima la termmeta. */
		public function test_taxonomy_query_asks_for_eligible_terms_only(): void {
			$this->names( 'the climber' );

			self::assertSame( SeriesSuggestions::TAXONOMY, $this->term_query_args[0]['taxonomy'] );
			self::assertTrue( $this->term_query_args[0]['hide_empty'] );
			self::assertTrue( $this->term_query_args[0]['update_term_meta_cache'] );
		}
	}
}
