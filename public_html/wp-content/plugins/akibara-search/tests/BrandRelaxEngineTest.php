<?php
/**
 * El motor, con un catálogo de mentira pero con el AND de verdad.
 *
 * El doble de `$wpdb` no devuelve ids fijos: lee los tokens que la sentencia
 * pide y aplica la conjunción real, así que si alguien quitara el reintento —o
 * lo dejara soltando tokens de más— estos tests caen. El catálogo reproduce el
 * de producción del 2026-09-05: Jujutsu Kaisen es de Panini Argentina y Made in
 * Abyss de Ivrea Argentina; ninguna serie está en dos ediciones.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\BrandRelax;
use Akibara\Search\Install;
use Akibara\Search\SemanticPublication;
use Akibara\Search\BrandTokens;
use Akibara\Search\QueryEngine;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/QueryEngine.php';

/**
 * Catálogo de prueba: post_id => tokens, más de qué campo viene cada token.
 */
final class RelaxCatalogWpdb {

	/** @var string */
	public string $prefix = 'wp_';

	/** @var string */
	public string $last_error = '';

	/** @var array<int, string> Sentencias recibidas. */
	public array $queries = array();

	/**
	 * Documentos: post_id => lista de tokens buscables.
	 *
	 * @var array<int, array<int, string>>
	 */
	public array $docs = array();

	/**
	 * Tokens que vienen de título/serie/autor/SKU (los que NUNCA se sueltan).
	 *
	 * @var array<int, string>
	 */
	public array $contenido = array();

	/**
	 * @param string $query   Sentencia.
	 * @param mixed  ...$args Valores.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$query = str_replace( array( '%d', '%f' ), '%s', $query );
		return vsprintf( $query, array_map( static fn( $v ): string => "'" . (string) $v . "'", $args ) );
	}

	/**
	 * @param string $text Texto.
	 * @return string
	 */
	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Responde las dos lecturas que importan: la lista de tokens de contenido
	 * (BrandTokens) y los candidatos del motor (AND de tokens).
	 *
	 * @param string $query Sentencia.
	 * @return array<int, string|int>
	 */
	public function get_col( string $query ) {
		$this->queries[] = $query;

		// Lectura de BrandTokens: qué tokens aparecen en contenido.
		if ( false !== strpos( $query, 'SELECT DISTINCT token FROM' ) ) {
			$out = array();
			foreach ( $this->contenido as $token ) {
				if ( false !== strpos( $query, "'" . $token . "'" ) ) {
					$out[] = $token;
				}
			}
			return $out;
		}

		// Lectura de candidatos. La sentencia real tiene DOS niveles: los
		// segmentos van unidos por JOIN (AND) y dentro de cada uno las
		// alternativas de sinónimo van por UNION (OR). Un doble que aplicara AND
		// a todo exigiría «jjk» además de «jujutsu kaisen» y no encontraría nada,
		// que es justo el error que este test debe cazar en el motor, no en sí mismo.
		$segmentos = $this->segments_of( $query );
		if ( array() === $segmentos ) {
			return array();
		}

		$ids = array();
		foreach ( $this->docs as $post_id => $tokens ) {
			$pasa = true;
			foreach ( $segmentos as $alternativas ) {
				$alguna = false;
				foreach ( $alternativas as $exigidos ) {
					if ( $this->doc_has_all( $tokens, $exigidos ) ) {
						$alguna = true;
						break;
					}
				}
				if ( ! $alguna ) {
					$pasa = false;
					break;
				}
			}
			if ( $pasa ) {
				$ids[] = (int) $post_id;
			}
		}

		return $ids;
	}

	/**
	 * ¿El documento tiene todos esos tokens (con prefijo, como el LIKE)?
	 *
	 * @param array<int, string> $tokens   Tokens del documento.
	 * @param array<int, string> $exigidos Tokens exigidos.
	 * @return bool
	 */
	private function doc_has_all( array $tokens, array $exigidos ): bool {
		foreach ( $exigidos as $exigido ) {
			$hay = false;
			foreach ( $tokens as $token ) {
				if ( $token === $exigido || 0 === strpos( $token, $exigido ) ) {
					$hay = true;
					break;
				}
			}
			if ( ! $hay ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Segmentos de la sentencia: lista de segmentos, cada uno con sus
	 * alternativas, cada alternativa con los tokens que exige.
	 *
	 * @param string $query Sentencia.
	 * @return array<int, array<int, array<int, string>>>
	 */
	private function segments_of( string $query ): array {
		$derivadas = array();
		if ( preg_match_all( '/\(([^()]*)\)\s*q\d+/s', $query, $m ) ) {
			$derivadas = $m[1];
		}
		if ( array() === $derivadas ) {
			// Camino sin sinónimos: un único segmento con una alternativa.
			$tokens = $this->tokens_of( $query );
			return array() === $tokens ? array() : array( array( $tokens ) );
		}

		$segmentos = array();
		foreach ( $derivadas as $derivada ) {
			$alternativas = array();
			foreach ( preg_split( '/\bUNION\b/i', $derivada ) ?: array() as $alternativa ) {
				$tokens = $this->tokens_of( $alternativa );
				if ( array() !== $tokens ) {
					$alternativas[] = $tokens;
				}
			}
			if ( array() !== $alternativas ) {
				$segmentos[] = $alternativas;
			}
		}

		return $segmentos;
	}

	/**
	 * @param string $query  Sentencia.
	 * @param mixed  $output Formato.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( string $query, $output = null ): array {
		$this->queries[] = $query;
		$rows            = array();
		foreach ( array_keys( $this->docs ) as $post_id ) {
			if ( false !== strpos( $query, "'" . $post_id . "'" ) ) {
				$rows[] = array(
					'post_id'      => (string) $post_id,
					'title_norm'   => implode( ' ', $this->docs[ $post_id ] ),
					'serie_norm'   => '',
					'autores_norm' => '',
					'tomo'         => '1',
					'stock_rank'   => '1',
					'menu_order'   => '0',
				);
			}
		}
		return $rows;
	}

	/**
	 * @param string $query Sentencia.
	 * @return string|null
	 */
	public function get_var( string $query ) {
		$this->queries[] = $query;
		return null;
	}

	/**
	 * Los tokens que la sentencia exige, sacados de sus LIKE / igualdades.
	 *
	 * @param string $query Sentencia.
	 * @return array<int, string>
	 */
	private function tokens_of( string $query ): array {
		$out = array();
		if ( preg_match_all( "/token (?:LIKE|=) '([^']*)'/", $query, $m ) ) {
			foreach ( $m[1] as $token ) {
				$out[] = rtrim( str_replace( '\\', '', $token ), '%' );
			}
		}
		return array_values( array_unique( $out ) );
	}
}

final class BrandRelaxEngineTest extends TestCase {

	/** @var mixed */
	private $previous_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_terms' )->alias(
			static function ( array $args = array() ) {
				if ( ( $args['taxonomy'] ?? '' ) !== 'product_brand' ) {
					return array();
				}
				$terms = $GLOBALS['akb_test_terms'] ?? array();
				return is_array( $terms ) ? array_values( $terms ) : array();
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				$options = $GLOBALS['akb_test_options'] ?? array();
				return is_array( $options ) && array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
		Functions\when( 'get_transient' )->alias(
			static function ( string $name ) {
				$t = $GLOBALS['akb_test_transients'] ?? array();
				return is_array( $t ) && array_key_exists( $name, $t ) ? $t[ $name ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $name, $value, int $ttl = 0 ): bool {
				$GLOBALS['akb_test_transients'][ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $name ): bool {
				unset( $GLOBALS['akb_test_transients'][ $name ] );
				return true;
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ): string => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		BrandTokens::flush();
		BrandRelax::flush();
		// El motor no lee ni una fila sin una foto autoritativa de sus options
		// (fail-closed): sin esto la búsqueda devuelve vacío por indisponible y
		// el test mediría otra cosa.
		SemanticPublication::reset_authoritative_state_for_tests();
		SemanticPublication::adopt_authoritative_snapshot(
			array(
				Install::OPTION_INDEX_GEN => 'relax-generation',
				'akb_search_fuzzy'        => 'no',
			)
		);
		$this->previous_wpdb            = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['akb_test_terms']      = array( 'Ivrea Argentina', 'Ivrea España', 'Panini Argentina', 'Panini España', 'Planeta Cómic' );
		$GLOBALS['akb_test_options']    = array( 'akb_search_fuzzy' => 'no' );
		$GLOBALS['akb_test_transients'] = array();
	}

	protected function tearDown(): void {
		BrandTokens::flush();
		BrandRelax::flush();
		SemanticPublication::reset_authoritative_state_for_tests();
		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}
		unset( $GLOBALS['akb_test_terms'], $GLOBALS['akb_test_options'], $GLOBALS['akb_test_transients'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * El catálogo del 5 de septiembre de 2026, en pequeño.
	 *
	 * @return RelaxCatalogWpdb
	 */
	private function catalogo(): RelaxCatalogWpdb {
		$db = new RelaxCatalogWpdb();
		// Jujutsu Kaisen 01 y 02 → Panini Argentina. Made in Abyss 01 → Ivrea Argentina.
		$db->docs = array(
			101 => array( 'jujutsu', 'kaisen', '01', 'panini', 'argentina' ),
			102 => array( 'jujutsu', 'kaisen', '02', 'panini', 'argentina' ),
			201 => array( 'made', 'abyss', '01', 'ivrea', 'argentina' ),
		);
		// Ninguna palabra de editorial aparece en títulos de este catálogo.
		$db->contenido   = array();
		$GLOBALS['wpdb'] = $db;
		return $db;
	}

	/**
	 * @param RelaxCatalogWpdb $db  Catálogo.
	 * @param string           $q   Consulta.
	 * @param bool             $sug Modo desplegable.
	 * @return array<string, mixed>
	 */
	private function buscar( RelaxCatalogWpdb $db, string $q, bool $sug = false ): array {
		$engine = new QueryEngine( $db );
		if ( $sug ) {
			return $engine->suggest( $q );
		}
		return array( 'ids' => $engine->search( $q, false ), 'corrected' => null, 'relaxed' => null );
	}

	/**
	 * El caso que costaba la venta.
	 *
	 * @testdox «jujutsu kaisen ivrea» devuelve los tomos de Panini en vez de cero.
	 */
	public function test_the_wrong_publisher_no_longer_returns_zero(): void {
		$db = $this->catalogo();

		$out = $this->buscar( $db, 'jujutsu kaisen ivrea', true );

		self::assertEqualsCanonicalizing( array( 101, 102 ), $out['ids'] );
		self::assertIsArray( $out['relaxed'] );
		self::assertSame( array( 'ivrea' ), $out['relaxed']['ignored'] );
		self::assertSame( 'jujutsu kaisen', $out['relaxed']['kept'] );
	}

	/**
	 * Made in Abyss SÍ es de Ivrea: lo que sobra es el país. Soltar las dos
	 * palabras haría decir al aviso «no tenemos made abyss de Ivrea España»,
	 * que es media mentira.
	 *
	 * @testdox De «made in abyss ivrea espana» sólo se suelta el país, porque la editorial sí acierta.
	 */
	public function test_only_the_country_is_dropped_when_the_publisher_is_right(): void {
		$db = $this->catalogo();

		$out = $this->buscar( $db, 'made in abyss ivrea espana', true );

		self::assertSame( array( 201 ), $out['ids'] );
		self::assertSame( array( 'espana' ), $out['relaxed']['ignored'] );
		self::assertSame( 'made abyss ivrea', $out['relaxed']['kept'] );
	}

	/**
	 * @testdox Si ninguna palabra suelta por separado alcanza, se sueltan todas.
	 */
	public function test_all_brand_words_are_dropped_when_one_is_not_enough(): void {
		$db = $this->catalogo();

		$out = $this->buscar( $db, 'jujutsu kaisen ivrea espana', true );

		self::assertEqualsCanonicalizing( array( 101, 102 ), $out['ids'] );
		self::assertSame( array( 'ivrea', 'espana' ), $out['relaxed']['ignored'], 'Ni «ivrea» ni «espana» solos bastaban: fuera las dos.' );
	}

	/**
	 * @testdox Con la editorial correcta no se suelta nada y no hay aviso.
	 */
	public function test_the_right_publisher_is_left_alone(): void {
		$db = $this->catalogo();

		$out = $this->buscar( $db, 'jujutsu kaisen panini', true );

		self::assertEqualsCanonicalizing( array( 101, 102 ), $out['ids'] );
		self::assertNull( $out['relaxed'], 'La búsqueda funcionó tal cual: nada que explicar.' );
	}

	/**
	 * Que el reintento no se coma el cero legítimo: si la serie no existe, no
	 * existe, y hay que decirlo. Inventar resultados sería peor que el cero.
	 *
	 * @testdox Una serie que no está en catálogo sigue devolviendo cero.
	 */
	public function test_a_real_zero_stays_zero(): void {
		$db = $this->catalogo();

		$out = $this->buscar( $db, 'horimiya ivrea', true );

		self::assertSame( array(), $out['ids'] );
		self::assertNull( $out['relaxed'] );
	}

	/**
	 * @testdox Buscar sólo la editorial no dispara el reintento: eso ya funciona.
	 */
	public function test_a_publisher_only_query_is_not_relaxed(): void {
		$db = $this->catalogo();

		$out = $this->buscar( $db, 'panini argentina', true );

		self::assertEqualsCanonicalizing( array( 101, 102 ), $out['ids'] );
		self::assertNull( $out['relaxed'] );
	}

	/**
	 * El cliente que pide una edición que no traemos merece ver la que sí
	 * traemos de esa editorial, con el aviso delante. Lo que nunca puede pasar
	 * es quedarse sin NINGUNA palabra y volcarle el catálogo entero.
	 *
	 * @testdox Pedir una edición que no existe ofrece la otra edición de esa editorial, avisando.
	 */
	public function test_an_edition_we_do_not_carry_offers_the_one_we_do(): void {
		$db = $this->catalogo();

		$out = $this->buscar( $db, 'ivrea espana', true );

		self::assertSame( array( 201 ), $out['ids'], 'Made in Abyss es lo único de Ivrea en este catálogo.' );
		self::assertSame( array( 'espana' ), $out['relaxed']['ignored'] );
		self::assertSame( 'ivrea', $out['relaxed']['kept'] );
	}

	/**
	 * @testdox Soltar TODAS las palabras dejaría la búsqueda sin nada que buscar: no se hace.
	 */
	public function test_a_query_made_only_of_brand_words_is_never_emptied(): void {
		$db = $this->catalogo();
		// «planeta comic» no casa nada acá; soltar las dos dejaría cero tokens.
		$out = $this->buscar( $db, 'planeta comic', true );

		self::assertSame( array(), $out['ids'] );
		self::assertNull( $out['relaxed'] );
	}

	/**
	 * @testdox En el catálogo el aviso viaja por el side-channel, no en el retorno.
	 */
	public function test_the_catalog_uses_the_side_channel(): void {
		$db = $this->catalogo();

		$ids = ( new QueryEngine( $db ) )->search( 'jujutsu kaisen ivrea', false );

		self::assertEqualsCanonicalizing( array( 101, 102 ), $ids );
		$relaxed = BrandRelax::last();
		self::assertIsArray( $relaxed );
		self::assertSame( array( 'ivrea' ), $relaxed['ignored'] );
		self::assertSame(
			'No tenemos jujutsu kaisen de Ivrea. Esto es lo que tenemos de jujutsu kaisen.',
			BrandRelax::sentence( $relaxed['ignored'], $relaxed['kept'] )
		);
	}

	/**
	 * @testdox Una búsqueda nueva no hereda el aviso de la anterior.
	 */
	public function test_the_side_channel_does_not_leak_between_searches(): void {
		$db = $this->catalogo();

		( new QueryEngine( $db ) )->search( 'jujutsu kaisen ivrea', false );
		self::assertIsArray( BrandRelax::last() );

		( new QueryEngine( $db ) )->search( 'jujutsu kaisen', false );
		self::assertNull( BrandRelax::last(), 'El aviso viejo sobre resultados nuevos mentiría.' );
	}

	/**
	 * Si una palabra de editorial también nombra una serie, exigirla es lo
	 * correcto: soltarla devolvería cualquier cosa.
	 *
	 * @testdox Una palabra de editorial que además está en un título nunca se suelta.
	 */
	public function test_a_brand_word_used_in_titles_is_never_dropped(): void {
		$db              = $this->catalogo();
		$db->docs[301]   = array( 'planeta', 'de', 'los', 'simios', '01' );
		$db->contenido   = array( 'planeta' );

		$out = $this->buscar( $db, 'simios planeta', true );

		self::assertSame( array( 301 ), $out['ids'] );
		self::assertNull( $out['relaxed'] );
	}
}
