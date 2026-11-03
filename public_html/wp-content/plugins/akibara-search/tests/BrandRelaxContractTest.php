<?php
/**
 * La editorial equivocada no puede costar la venta.
 *
 * Medido en producción el 2026-09-05 contra el endpoint real: «jujutsu kaisen
 * ivrea» devolvía 0 resultados con 31 tomos de Jujutsu Kaisen en catálogo
 * (Panini Argentina), «dandadan panini» 0 con 22 (Ivrea), «naruto ivrea» 0 con
 * 14 (Planeta) y «made in abyss españa» 0 con 14 (Ivrea Argentina). Ninguna de
 * las 106 series del catálogo existe en dos ediciones, así que nombrar la
 * editorial que no es resulta ser el caso NORMAL.
 *
 * Estos candados fijan las tres mitades del arreglo: qué palabras se pueden
 * soltar (BrandTokens), qué se le dice al cliente (BrandRelax) y que el texto
 * lo escribe el dueño sin que un «%» suyo reviente la página.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\BrandRelax;
use Akibara\Search\BrandTokens;
use Akibara\Search\DocumentBuilder;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Doble de `$wpdb` que responde la consulta de tokens usados en contenido.
 */
final class BrandTokensWpdb {

	/** @var string */
	public string $prefix = 'wp_';

	/** @var string */
	public string $last_error = '';

	/** @var array<int, string> Tokens que SÍ aparecen en título/serie/autor/sku. */
	public array $en_contenido = array();

	/** @var array<int, string> Consultas recibidas. */
	public array $queries = array();

	/** @var bool ¿La lectura revienta? */
	public bool $rompe = false;

	/**
	 * @param string $query   Sentencia.
	 * @param mixed  ...$args Valores.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$query = str_replace( '%d', '%s', $query );
		return vsprintf( $query, array_map( static fn( $v ): string => "'" . (string) $v . "'", $args ) );
	}

	/**
	 * @param string $query Sentencia.
	 * @return array<int, string>
	 */
	public function get_col( string $query ): array {
		$this->queries[] = $query;
		if ( $this->rompe ) {
			$this->last_error = 'roto';
			return array();
		}
		$out = array();
		foreach ( $this->en_contenido as $token ) {
			if ( false !== strpos( $query, "'" . $token . "'" ) ) {
				$out[] = $token;
			}
		}
		return $out;
	}
}

final class BrandRelaxContractTest extends TestCase {

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
		Functions\when( 'esc_html' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		BrandTokens::flush();
		BrandRelax::flush();
		$GLOBALS['akb_test_terms']      = array( 'Ivrea Argentina', 'Ivrea España', 'Panini Argentina', 'Panini España', 'Planeta Cómic', 'Milky Way', 'Ovni Press', 'Arechi Manga' );
		$GLOBALS['akb_test_options']    = array();
		$GLOBALS['akb_test_transients'] = array();
		// Install::tokens_table() lee el prefijo del $wpdb global, no del doble
		// que reciben las clases: sin esto los tests fatalean por el prefijo.
		$GLOBALS['wpdb'] = new BrandTokensWpdb();
	}

	protected function tearDown(): void {
		BrandTokens::flush();
		BrandRelax::flush();
		unset( $GLOBALS['akb_test_terms'], $GLOBALS['akb_test_options'], $GLOBALS['akb_test_transients'], $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Se sueltan los nombres de editorial y su país, pero nunca una palabra que esté en un título.
	 */
	public function test_only_brand_words_absent_from_content_are_droppable(): void {
		$db               = new BrandTokensWpdb();
		// «manga» y «way» viven en títulos reales del catálogo, así que no se sueltan.
		$db->en_contenido = array( 'manga', 'way' );

		$soltables = BrandTokens::droppable( $db );

		self::assertContains( 'ivrea', $soltables );
		self::assertContains( 'panini', $soltables );
		self::assertContains( 'argentina', $soltables );
		self::assertContains( 'espana', $soltables, 'La consulta llega normalizada: «España» se teclea y se compara como espana.' );
		self::assertContains( 'planeta', $soltables );
		self::assertContains( 'arechi', $soltables );
		self::assertNotContains( 'manga', $soltables, 'Está en títulos: soltarla rompería búsquedas legítimas.' );
		self::assertNotContains( 'way', $soltables, 'Milky Way aporta «way», pero hay títulos con esa palabra.' );
	}

	/**
	 * @testdox Un token de dos letras no se suelta nunca.
	 */
	public function test_two_letter_tokens_are_never_droppable(): void {
		$GLOBALS['akb_test_terms'] = array( 'Ed AR', 'Ivrea Argentina' );

		$soltables = BrandTokens::droppable( new BrandTokensWpdb() );

		self::assertNotContains( 'ar', $soltables );
		self::assertNotContains( 'ed', $soltables );
		self::assertContains( 'ivrea', $soltables );
	}

	/**
	 * @testdox Con la lectura del índice rota no se suelta nada: sin lista no hay reintento.
	 */
	public function test_a_broken_read_drops_nothing(): void {
		$db        = new BrandTokensWpdb();
		$db->rompe = true;

		self::assertSame( array(), BrandTokens::droppable( $db ) );
		self::assertSame( array(), BrandTokens::found_in( array( 'jujutsu', 'kaisen', 'ivrea' ), $db ) );
	}

	/**
	 * @testdox Sin editoriales en la tienda tampoco se suelta nada.
	 */
	public function test_no_brands_means_no_drops(): void {
		$GLOBALS['akb_test_terms'] = array();

		self::assertSame( array(), BrandTokens::droppable( new BrandTokensWpdb() ) );
	}

	/**
	 * @testdox De «jujutsu kaisen ivrea» sólo sobra «ivrea», en el orden en que se escribió.
	 */
	public function test_found_in_returns_the_brand_words_of_the_query(): void {
		$db = new BrandTokensWpdb();

		self::assertSame( array( 'ivrea' ), BrandTokens::found_in( array( 'jujutsu', 'kaisen', 'ivrea' ), $db ) );
		self::assertSame( array( 'ivrea', 'espana' ), BrandTokens::found_in( array( 'made', 'abyss', 'ivrea', 'espana' ), $db ) );
		self::assertSame( array(), BrandTokens::found_in( array( 'jujutsu', 'kaisen' ), $db ), 'Sin editorial en la consulta no hay nada que soltar.' );
	}

	/**
	 * @testdox El aviso nombra la editorial con su grafía real y lo que sí se buscó.
	 */
	public function test_the_notice_names_the_brand_and_the_query(): void {
		$frase = BrandRelax::sentence( array( 'ivrea' ), 'jujutsu kaisen' );

		self::assertSame( 'No tenemos jujutsu kaisen de Ivrea. Esto es lo que tenemos de jujutsu kaisen.', $frase );
		self::assertSame(
			'No tenemos made abyss de Ivrea España. Esto es lo que tenemos de made abyss.',
			BrandRelax::sentence( array( 'ivrea', 'espana' ), 'made abyss' ),
			'«espana» se le devuelve al cliente escrito como lo escribe la editorial.'
		);
	}

	/**
	 * @testdox Sin editorial o sin búsqueda no se inventa ningún aviso.
	 */
	public function test_no_notice_without_both_halves(): void {
		self::assertSame( '', BrandRelax::sentence( array(), 'jujutsu kaisen' ) );
		self::assertSame( '', BrandRelax::sentence( array( 'ivrea' ), '   ' ) );
		self::assertSame( '', BrandRelax::sentence( array( '  ' ), 'jujutsu' ) );
	}

	/**
	 * @testdox El dueño edita el aviso, y un «%» suyo no revienta nada.
	 */
	public function test_the_owner_writes_the_notice_and_a_percent_is_safe(): void {
		$GLOBALS['akb_test_options'][ BrandRelax::OPTION_NOTICE ] = 'Ojo: {busqueda} no viene de {editorial}. Mira el 100% de lo que sí tenemos.';

		$frase = BrandRelax::sentence( array( 'ivrea' ), 'naruto' );

		self::assertSame( 'Ojo: naruto no viene de Ivrea. Mira el 100% de lo que sí tenemos.', $frase );
	}

	/**
	 * @testdox Un aviso vacío, con etiquetas o kilométrico cae al literal.
	 */
	public function test_a_bad_notice_falls_back_to_the_literal(): void {
		$GLOBALS['akb_test_options'][ BrandRelax::OPTION_NOTICE ] = '   ';
		self::assertSame( BrandRelax::DEFAULT_NOTICE, BrandRelax::template() );

		$GLOBALS['akb_test_options'][ BrandRelax::OPTION_NOTICE ] = '<script>alert(1)</script>Sin {editorial}';
		self::assertSame( 'Sin {editorial}', BrandRelax::template(), 'El script se va entero, con su contenido.' );

		// Un valor kilométrico que llegue a la option por fuera del panel se
		// RECORTA con la misma regla del guardado, no se descarta: el lector y
		// el panel son ya la misma función (S-e9ec3344).
		$GLOBALS['akb_test_options'][ BrandRelax::OPTION_NOTICE ] = str_repeat( 'x', 400 );
		self::assertSame( str_repeat( 'x', 300 ), BrandRelax::template() );
	}

	/**
	 * El lector medía el tope en BYTES (`strlen`) y el guardado en CARACTERES.
	 * Un aviso de 290 letras con tildes pasa de 300 bytes: el panel decía
	 * «Cambios guardados» y la tienda seguía con el texto de fábrica
	 * (hallazgo S-e9ec3344, reproducido).
	 *
	 * @testdox Un aviso con tildes que el panel aceptó es el que lee la tienda.
	 */
	public function test_an_accented_notice_the_panel_accepted_is_the_one_served(): void {
		$aviso = 'Aún no llegó {busqueda} de {editorial}: ' . str_repeat( 'ñandú áéíóú ', 20 ) . 'mira esto.';
		self::assertLessThanOrEqual( 300, mb_strlen( $aviso ), 'El panel lo acepta: cabe en caracteres.' );
		self::assertGreaterThan( 300, strlen( $aviso ), 'Y es justo el caso: en bytes no cabía.' );

		$guardado = \Akibara\Search\EmptyStateCopy::sanitize( $aviso, BrandRelax::DEFAULT_NOTICE );
		$GLOBALS['akb_test_options'][ BrandRelax::OPTION_NOTICE ] = $guardado;

		self::assertSame( $guardado, BrandRelax::template(), 'Lo que el panel guardó es lo que la tienda muestra.' );
		self::assertNotSame( BrandRelax::DEFAULT_NOTICE, BrandRelax::template() );
	}

	/**
	 * Revisión independiente 2026-09-19 (MEDIA): recortar en vez de descartar
	 * dejaba pasar un marcador cortado por el tope, y el cliente leía `{busq`.
	 *
	 * @testdox Un aviso recortado nunca enseña un marcador a medias.
	 */
	public function test_a_cut_notice_never_shows_half_a_placeholder(): void {
		$aviso = str_repeat( 'x', 292 ) . ' {busqueda} de {editorial}';
		$GLOBALS['akb_test_options'][ BrandRelax::OPTION_NOTICE ] = $aviso;

		$plantilla = BrandRelax::template();

		self::assertSame( str_repeat( 'x', 292 ), $plantilla, 'El tope caía en «{busq»: se va la llave abierta con lo que la sigue.' );
		self::assertStringNotContainsString( '{', BrandRelax::sentence( array( 'ivrea' ), 'naruto' ) );
	}

	/** @testdox Un marcador COMPLETO que cabe antes del tope se conserva. */
	public function test_a_whole_placeholder_before_the_cut_survives(): void {
		$aviso = '{editorial}: ' . str_repeat( 'x', 400 );
		$GLOBALS['akb_test_options'][ BrandRelax::OPTION_NOTICE ] = $aviso;

		self::assertStringStartsWith( '{editorial}: xxx', BrandRelax::template() );
		self::assertSame( 300, mb_strlen( BrandRelax::template() ) );
	}

	/**
	 * Revisión del arreglo (2026-09-19): el quitado de llaves corría SIEMPRE, y
	 * un «:{» del dueño en un texto que cabía se llevaba el resto de la frase y
	 * el botón `[…]` de después.
	 *
	 * @testdox Una llave suelta en un texto que CABE se respeta: sólo se toca lo que se recortó.
	 */
	public function test_a_loose_brace_in_a_text_that_fits_is_kept(): void {
		$texto = 'Uy, no hay nada así :{ Prueba con menos filtros o [limpia todo].';

		self::assertSame( $texto, \Akibara\Search\EmptyStateCopy::sanitize( $texto, 'fábrica' ) );
	}

	/** @testdox Si el recorte se lleva todo, cae al literal de fábrica y no a una frase vacía. */
	public function test_a_notice_that_is_only_a_cut_placeholder_falls_back(): void {
		self::assertSame( 'fábrica', \Akibara\Search\EmptyStateCopy::sanitize( '{' . str_repeat( 'e', 400 ), 'fábrica' ) );
	}

	/**
	 * @testdox El side-channel del catálogo se vacía entre búsquedas.
	 */
	public function test_the_catalog_side_channel_is_per_request(): void {
		self::assertNull( BrandRelax::last() );

		BrandRelax::note( array( 'ignored' => array( 'ivrea' ), 'kept' => 'naruto' ) );
		self::assertSame( array( 'ignored' => array( 'ivrea' ), 'kept' => 'naruto' ), BrandRelax::last() );

		BrandRelax::flush();
		self::assertNull( BrandRelax::last(), 'Un aviso viejo sobre una búsqueda nueva mentiría.' );
	}

	/**
	 * @testdox La consulta al índice sólo mira título, serie, autor y SKU.
	 */
	public function test_the_index_read_only_looks_at_content_fields(): void {
		$db = new BrandTokensWpdb();

		BrandTokens::droppable( $db );

		self::assertNotEmpty( $db->queries );
		$sql = $db->queries[0];
		foreach ( array( DocumentBuilder::FIELD_TITLE, DocumentBuilder::FIELD_SERIE, DocumentBuilder::FIELD_AUTOR, DocumentBuilder::FIELD_SKU ) as $campo ) {
			self::assertStringContainsString( "'" . $campo . "'", $sql );
		}
		self::assertStringNotContainsString( "'" . DocumentBuilder::FIELD_TAXONOMY . "'", $sql, 'La taxonomía es justo lo que se suelta: no cuenta como contenido.' );
	}
	/**
	 * @testdox El aviso con énfasis marca la editorial y el título, y escapa lo que escriba el dueño.
	 */
	public function test_the_html_notice_emphasises_both_halves(): void {
		$html = BrandRelax::sentence_html( array( 'ivrea' ), 'jujutsu kaisen' );

		self::assertSame(
			'No tenemos <strong>jujutsu kaisen</strong> de <strong class="akb-relaxed__brand">Ivrea</strong>. Esto es lo que tenemos de <strong>jujutsu kaisen</strong>.',
			$html
		);
	}

	/**
	 * @testdox Ni la plantilla del dueño ni lo que teclee el cliente pueden inyectar etiquetas.
	 */
	public function test_the_html_notice_is_escaped(): void {
		$GLOBALS['akb_test_options'][ BrandRelax::OPTION_NOTICE ] = 'Nada de {editorial} <b>ojo</b> con {busqueda}';

		$html = BrandRelax::sentence_html( array( 'ivrea' ), '<img src=x onerror=alert(1)>' );

		self::assertStringNotContainsString( '<img', $html );
		self::assertStringNotContainsString( '<b>', $html, 'La plantilla se sanea al leerla y se escapa al pintarla.' );
		self::assertStringContainsString( '<strong class="akb-relaxed__brand">Ivrea</strong>', $html );
	}

	/**
	 * @testdox Sin las dos piezas tampoco hay aviso con énfasis.
	 */
	public function test_no_html_notice_without_both_halves(): void {
		self::assertSame( '', BrandRelax::sentence_html( array(), 'jujutsu' ) );
		self::assertSame( '', BrandRelax::sentence_html( array( 'ivrea' ), '' ) );
	}

}
