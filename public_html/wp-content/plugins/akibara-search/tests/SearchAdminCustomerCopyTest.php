<?php
/**
 * Candados de las dos palancas nuevas de «Buscador → Ajustes»: el interruptor
 * de «Populares» y los mensajes de «sin resultados».
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\EmptyStateCopy;
use Akibara\Search\PopularQueries;
use Akibara\Search\SearchAdminSection;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname( __DIR__ ) . '/src/Search.php';
require_once dirname( __DIR__ ) . '/src/FilterSet.php';
require_once dirname( __DIR__ ) . '/src/SearchAdminSection.php';

defined( 'AKIBARA_SEARCH_DIR' ) || define( 'AKIBARA_SEARCH_DIR', dirname( __DIR__ ) . '/' );
defined( 'AKIBARA_SEARCH_URL' ) || define( 'AKIBARA_SEARCH_URL', 'https://example.test/wp-content/plugins/akibara-search/' );
defined( 'AKIBARA_SEARCH_VERSION' ) || define( 'AKIBARA_SEARCH_VERSION', '0.0.0-test' );

final class SearchAdminCustomerCopyTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();

		Functions\when( 'esc_html' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_attr' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_url' )->alias( static fn( $v ): string => (string) $v );
		Functions\when( 'esc_textarea' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'admin_url' )->alias( static fn( string $p = '' ): string => 'https://example.test/wp-admin/' . $p );
		Functions\when( 'wp_nonce_url' )->alias( static fn( string $url ): string => $url . '&_wpnonce=test' );
		Functions\when( 'date_i18n' )->alias( static fn( string $f, int $ts ): string => gmdate( 'd M', $ts ) );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'checked' )->alias(
			static fn( $checked, $current = true, $echo = true ): string => (string) $checked === (string) $current ? " checked='checked'" : ''
		);
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => (string) $n );
		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render_settings(): string {
		$method = new ReflectionMethod( SearchAdminSection::class, 'render_settings' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( new SearchAdminSection() );

		return (string) ob_get_clean();
	}

	/**
	 * @testdox «Populares» aparece encendido, que es como lo lee el motor
	 */
	public function test_popular_toggle_paints_the_engine_default(): void {
		$html = $this->render_settings();

		self::assertMatchesRegularExpression(
			'/name="toggle_popular"[^>]*checked/',
			$html,
			'Sin fila guardada el motor muestra Populares: pintarlo apagado lo apagaría en el primer guardado.'
		);
	}

	/**
	 * @testdox Apagarlo desde la pantalla apaga el bloque, sin tocar el autocompletado
	 */
	public function test_turning_popular_off_is_painted_off(): void {
		$this->options[ PopularQueries::OPTION_ENABLED ] = 'no';

		$html = $this->render_settings();

		self::assertDoesNotMatchRegularExpression( '/name="toggle_popular"[^>]*checked/', $html );
		self::assertStringContainsString( 'name="toggle_suggest"', $html, 'El autocompletado sigue siendo una palanca aparte.' );
	}

	/**
	 * @testdox Los dos mensajes de «sin resultados» se editan con su literal de siempre
	 */
	public function test_the_two_empty_hints_are_editable(): void {
		$html = $this->render_settings();

		self::assertStringContainsString(
			'name="texto_empty_hint_catalog" value="' . htmlspecialchars( EmptyStateCopy::DEFAULT_CATALOG, ENT_QUOTES, 'UTF-8' ) . '"',
			$html
		);
		self::assertStringContainsString(
			'name="texto_empty_hint_suggest" value="' . htmlspecialchars( EmptyStateCopy::DEFAULT_SUGGEST, ENT_QUOTES, 'UTF-8' ) . '"',
			$html
		);
	}

	/**
	 * @testdox La pista de una búsqueda vaciada por los filtros se edita en su propia fila, con su botón que conserva la búsqueda
	 */
	public function test_the_filters_hint_is_editable_and_keeps_the_search(): void {
		$html = $this->render_settings();

		self::assertStringContainsString(
			'<label for="akb-text-empty_hint_filters">Pista cuando los filtros vacían una búsqueda</label></th><td><input type="text" class="large-text" id="akb-text-empty_hint_filters" name="texto_empty_hint_filters" value="' . htmlspecialchars( EmptyStateCopy::DEFAULT_FILTERS, ENT_QUOTES, 'UTF-8' ) . '" maxlength="300" aria-describedby="akb-text-empty_hint_filters-desc">',
			$html,
			'Etiqueta real, el literal de fábrica y el mismo tope que el guardado.'
		);
		self::assertStringContainsString( 'conserva la búsqueda', $html, 'La ayuda dice qué hace su botón, distinto del de la pista del catálogo.' );
		self::assertLessThan(
			strpos( $html, 'name="texto_empty_hint_suggest"' ),
			strpos( $html, 'name="texto_empty_hint_filters"' ),
			'Va junto a la pista del catálogo, antes que la del desplegable.'
		);

		self::assertSame(
			'Tu búsqueda sí tiene resultados: <button type="button" class="akb-link" data-akb-clear="filtros">quita los filtros</button> para verlos.',
			EmptyStateCopy::filters_html()
		);
		$this->options[ EmptyStateCopy::OPTION_FILTERS ] = 'Sin corchetes & sin botón';
		self::assertSame( 'Sin corchetes &amp; sin botón', EmptyStateCopy::filters_html(), 'Sin corchetes, texto escapado y sin botón: una decisión del dueño.' );
	}

	/**
	 * @testdox El aviso de conexión del catálogo se edita en su propia fila, al final de los mensajes, con su literal de fábrica
	 */
	public function test_the_network_notice_is_editable(): void {
		$html = $this->render_settings();

		self::assertStringContainsString(
			'<label for="akb-text-network_notice">Cuando un filtro no carga por la conexión</label></th><td><input type="text" class="large-text" id="akb-text-network_notice" name="texto_network_notice" value="' . htmlspecialchars( EmptyStateCopy::DEFAULT_NETWORK, ENT_QUOTES, 'UTF-8' ) . '" maxlength="300" aria-describedby="akb-text-network_notice-desc">',
			$html,
			'Etiqueta real, el literal de fábrica y el mismo tope que el guardado.'
		);
		self::assertStringContainsString( 'vuelve a pedir lo que eligió', $html, 'La ayuda dice qué hace su botón.' );
		self::assertStringContainsString( 'volver a tocar el filtro también lo aplica', $html, 'Y qué pasa sin él: quitarlo es una decisión legítima.' );
		self::assertLessThan(
			strpos( $html, 'Guardar ajustes' ) ?: PHP_INT_MAX,
			strpos( $html, 'name="texto_network_notice"' ),
			'Dentro del formulario de Ajustes, que es el que lo guarda.'
		);
		self::assertGreaterThan( strpos( $html, 'name="texto_relaxed_notice"' ), strpos( $html, 'name="texto_network_notice"' ) );

		$this->options[ EmptyStateCopy::OPTION_NETWORK ] = 'Se cortó la señal & no cargó. [Probar otra vez]';
		self::assertStringContainsString( 'value="Se cortó la señal &amp; no cargó. [Probar otra vez]"', $this->render_settings(), 'Pinta lo guardado, escapado.' );
	}

	/**
	 * @testdox Sin nada guardado, el catálogo sirve el MISMO HTML de siempre
	 */
	public function test_the_catalog_hint_html_is_byte_identical_by_default(): void {
		self::assertSame(
			'Prueba con menos filtros, el nombre de la serie, o <button type="button" class="akb-link" data-akb-clear>limpia todo</button>.',
			EmptyStateCopy::catalog_html()
		);
	}

	/**
	 * @testdox Lo que va entre corchetes es el botón que limpia los filtros
	 */
	public function test_the_bracketed_span_becomes_the_clear_button(): void {
		$this->options[ EmptyStateCopy::OPTION_CATALOG ] = '¿Nada? [Empieza de nuevo] o escríbenos por WhatsApp.';

		self::assertSame(
			'¿Nada? <button type="button" class="akb-link" data-akb-clear>Empieza de nuevo</button> o escríbenos por WhatsApp.',
			EmptyStateCopy::catalog_html()
		);
	}

	/**
	 * @testdox Sin corchetes el aviso queda sin botón, y el texto escapado
	 */
	public function test_without_brackets_there_is_no_button(): void {
		$this->options[ EmptyStateCopy::OPTION_CATALOG ] = 'Escríbenos a <hola@akibara.cl> & te lo buscamos';

		$html = EmptyStateCopy::catalog_html();

		self::assertStringNotContainsString( '<button', $html );
		self::assertStringNotContainsString( '<hola@akibara.cl>', $html );
		self::assertStringContainsString( '&amp;', $html );
	}

	/**
	 * @testdox Una pista vacía vuelve a la de fábrica: sin salida es peor que genérica
	 */
	public function test_a_blank_hint_falls_back(): void {
		self::assertSame( EmptyStateCopy::DEFAULT_CATALOG, EmptyStateCopy::sanitize( '   ', EmptyStateCopy::DEFAULT_CATALOG ) );
		self::assertSame( EmptyStateCopy::DEFAULT_SUGGEST, EmptyStateCopy::sanitize( null, EmptyStateCopy::DEFAULT_SUGGEST ) );
		self::assertSame( EmptyStateCopy::DEFAULT_SUGGEST, EmptyStateCopy::sanitize( '<b></b>', EmptyStateCopy::DEFAULT_SUGGEST ) );
	}

	/**
	 * @testdox El HTML pegado a mano se retira: la pista del desplegable viaja a JavaScript
	 */
	public function test_html_is_stripped_on_save(): void {
		// `wp_strip_all_tags()` se lleva el CUERPO de script/style, no sólo las
		// etiquetas: lo que queda es la frase, en una línea.
		self::assertSame(
			'Prueba con la serie.',
			EmptyStateCopy::sanitize(
				"<script>alert(1)</script>\n Prueba con la serie.",
				EmptyStateCopy::DEFAULT_SUGGEST
			)
		);
		self::assertSame(
			'Prueba con la serie o escríbenos.',
			EmptyStateCopy::sanitize(
				"<b>Prueba</b> con la serie\n o escríbenos.",
				EmptyStateCopy::DEFAULT_SUGGEST
			)
		);
	}
}
