<?php
/**
 * Guardar Buscador → Ajustes sin tocar nada no convierte los valores de fábrica
 * en filas fijas (QA-PANEL-6, QA integral del 2026-09-23).
 *
 * El guardado escribía las pistas, los rótulos, el aviso de la editorial y el
 * interruptor de las salidas aunque nadie los hubiera cambiado: tras un solo
 * «Guardar», `akb_search_empty_series_label` pasaba de no existir a valer
 * «Quizá buscabas», y un rótulo de fábrica mejorado en una versión futura ya no
 * le llegaba a esa tienda. En producción las dos pistas ya tienen fila con su
 * texto de fábrica. La lista de palabras de formato ya se escribía sólo si
 * cambiaba; esto fija la misma regla para todo lo que tiene valor de fábrica.
 *
 * Segunda vuelta (revisión REV-QFS-1 y REV-QFS-2, 2026-09-24): volver a fábrica
 * —vaciar un texto, apagar y volver a encender las salidas, restaurar la lista—
 * seguía dejando el literal de fábrica como fila, y las dos pistas de
 * producción no había forma de soltarlas. La regla ahora es una sola: lo que
 * vale lo de fábrica no tiene fila. Y una pestaña de Ajustes cargada con 0.1.74
 * —sin el interruptor ni los rótulos nuevos— ya no apaga las salidas ni pisa
 * los rótulos al guardarse después del despliegue.
 *
 * Tercera vuelta (QA integral, 2026-09-24): la lista de palabras de formato se
 * compara como la usa el motor —el mismo conjunto en otro orden ES la de
 * fábrica— y tiene casilla para volver a ella (QA-SRCH-2); un texto guardado
 * recortado o sin sus etiquetas lo dice el aviso (QA2-PANEL-5); y cambiar sólo
 * textos purga el origen, no la tienda entera (QA-SRCH-3).
 *
 * Se ejercita el handler REAL: el formulario se rellena con lo que la pantalla
 * pintó —lo que envía un formulario que nadie tocó— y el `exit` de la
 * redirección se corta con una excepción.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\AuditLog;
use Akibara\Search\BrandRelax;
use Akibara\Search\EmptyStateCopy;
use Akibara\Search\EmptyStateSuggestions;
use Akibara\Search\SearchAdminSection;
use Akibara\Search\SemanticPublication;
use Akibara\Search\Stopwords;
use Akibara\Search\Synonyms;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

require_once dirname( __DIR__ ) . '/src/Search.php';
require_once dirname( __DIR__ ) . '/src/FilterSet.php';
require_once dirname( __DIR__ ) . '/src/AuditLog.php';
require_once dirname( __DIR__ ) . '/src/SearchAdminSection.php';

defined( 'AKIBARA_SEARCH_DIR' ) || define( 'AKIBARA_SEARCH_DIR', dirname( __DIR__ ) . '/' );
defined( 'AKIBARA_SEARCH_URL' ) || define( 'AKIBARA_SEARCH_URL', 'https://example.test/wp-content/plugins/akibara-search/' );
defined( 'AKIBARA_SEARCH_VERSION' ) || define( 'AKIBARA_SEARCH_VERSION', '0.0.0-test' );

/** @covers \Akibara\Search\SearchAdminSection */
final class GuardarAjustesSinTocarNoCongelaLoDeFabricaTest extends TestCase {

	/** Las options con valor de fábrica que el guardado no debe congelar. */
	private const DE_FABRICA = array(
		EmptyStateCopy::OPTION_CATALOG,
		EmptyStateCopy::OPTION_FILTERS,
		EmptyStateCopy::OPTION_SUGGEST,
		BrandRelax::OPTION_NOTICE,
		EmptyStateSuggestions::OPTION_ENABLED,
		EmptyStateCopy::OPTION_SERIES_LABEL,
		EmptyStateCopy::OPTION_POPULAR_LABEL,
		EmptyStateSuggestions::OPTION_TAIL_WORDS,
		EmptyStateCopy::OPTION_NETWORK,
	);

	/**
	 * La tabla wp_options del doble.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Options que el doble se niega a borrar: la base que no acepta el borrado.
	 *
	 * @var array<int, string>
	 */
	private array $borrado_falla = array();

	/** @var mixed */
	private $previous_wpdb;

	private bool $had_previous_wpdb = false;

	/**
	 * Acciones disparadas (`do_action`), en orden: [hook, ...args].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $acciones = array();

	/**
	 * Callbacks enganchados a `shutdown`, que es donde el guardado purga.
	 *
	 * @var array<int, callable>
	 */
	private array $al_cerrar = array();

	/**
	 * Transients escritos (el detalle del aviso viaja por uno).
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options       = array();
		$this->borrado_falla = array();

		// Sin `$wpdb`, persist_options_exact() fotografía por get_option(): el
		// mismo contrato que su camino SQL, contra este doble.
		$this->had_previous_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb     = $GLOBALS['wpdb'] ?? null;
		unset( $GLOBALS['wpdb'] );

		SemanticPublication::reset_authoritative_state_for_tests();
		Synonyms::flush();
		Stopwords::flush();

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$changed                = ! array_key_exists( $name, $this->options ) || $this->options[ $name ] !== $value;
				$this->options[ $name ] = $value;
				return $changed;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				if ( in_array( $name, $this->borrado_falla, true ) || ! array_key_exists( $name, $this->options ) ) {
					return false;
				}
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'admin_url' )->alias( static fn( string $p = '' ): string => 'https://example.test/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ): void {
				throw new RuntimeException( $url );
			}
		);

		Functions\when( 'esc_html' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_attr' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_url' )->alias( static fn( $v ): string => (string) $v );
		Functions\when( 'esc_textarea' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'checked' )->alias(
			static fn( $checked, $current = true, $echo = true ): string => (string) $checked === (string) $current ? " checked='checked'" : ''
		);

		$this->acciones   = array();
		$this->al_cerrar  = array();
		$this->transients = array();
		Functions\when( 'do_action' )->alias(
			function ( ...$args ): void {
				$this->acciones[] = $args;
			}
		);
		Functions\when( 'add_action' )->alias(
			function ( string $hook, callable $callback ): bool {
				if ( 'shutdown' === $hook ) {
					$this->al_cerrar[] = $callback;
				}
				return true;
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			fn( string $key ) => $this->transients[ $key ] ?? false
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'sanitize_key' )->alias( static fn( string $v ): string => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $v ) ) );
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		if ( $this->had_previous_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Guardar Ajustes sin tocar nada no crea filas de fábrica y suelta las dos pistas que producción tiene congeladas.
	 */
	public function test_guardar_sin_tocar_no_crea_filas_de_fabrica(): void {
		// Producción el 2026-09-24: los interruptores del motor con fila y las dos
		// pistas con fila y su texto de fábrica.
		$this->options = $this->produccion();
		$antes         = $this->options;
		$se_leia       = $this->lo_que_lee_la_tienda();

		$aviso = $this->guardar( $this->formulario_sin_tocar() );

		self::assertSame( 'synonyms_saved', $aviso, 'Un guardado sin cambios es un guardado correcto.' );
		foreach ( self::DE_FABRICA as $option ) {
			self::assertArrayNotHasKey( $option, $this->options, $option . ': un valor de fábrica no se queda como fila fija.' );
		}
		unset( $antes[ EmptyStateCopy::OPTION_CATALOG ], $antes[ EmptyStateCopy::OPTION_SUGGEST ] );
		self::assertSame( $antes, $this->options, 'Fuera de soltar las dos pistas de fábrica, la tabla queda byte a byte como estaba: ni filas nuevas ni registro de cambios.' );
		self::assertSame( $se_leia, $this->lo_que_lee_la_tienda(), 'Soltar una fila que vale lo de fábrica no cambia ni una letra de lo que ve el cliente.' );
	}

	/**
	 * @testdox Volver a fábrica por cualquier camino del panel suelta la fila: vaciar un texto, apagar y encender las salidas, restaurar las palabras.
	 */
	public function test_volver_a_fabrica_por_cualquier_camino_suelta_la_fila(): void {
		$this->options                                         = $this->produccion();
		$this->options[ EmptyStateCopy::OPTION_POPULAR_LABEL ] = 'Lo que más se busca';
		$this->options[ EmptyStateCopy::OPTION_CATALOG ]       = 'Escríbenos por WhatsApp o [limpia todo].';
		$this->options[ EmptyStateCopy::OPTION_FILTERS ]       = 'Hay tomos: [sin filtros].';
		$this->options[ EmptyStateCopy::OPTION_NETWORK ]       = 'Sin señal. [Otra vez]';

		// 1) Vaciar los cuatro textos propios y apagar las salidas.
		$form                              = $this->formulario_sin_tocar();
		$form['texto_empty_popular_label'] = '';
		$form['texto_empty_hint_catalog']  = '   ';
		$form['texto_empty_hint_filters']  = '';
		$form['texto_network_notice']      = '';
		unset( $form['toggle_empty_suggestions'] );
		$this->guardar( $form );
		self::assertSame( 'no', $this->options[ EmptyStateSuggestions::OPTION_ENABLED ] ?? null, 'Apagadas, las salidas sí tienen fila.' );

		// 2) Volver a encenderlas.
		$this->guardar( $this->formulario_sin_tocar() + array( 'toggle_empty_suggestions' => 'yes' ) );

		// 3) Editar las palabras de formato y restaurarlas.
		$de_fabrica = $this->formulario_sin_tocar();
		$this->guardar( array( 'palabras_empty_tail_words' => "tomo\nvol" ) + $de_fabrica );
		self::assertSame( array( 'tomo', 'vol' ), $this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] ?? null, 'La lista propia sí se escribe.' );
		$this->guardar( $de_fabrica );

		foreach ( self::DE_FABRICA as $option ) {
			self::assertArrayNotHasKey( $option, $this->options, $option . ': de vuelta en fábrica, la fila tiene que irse o el literal de hoy queda congelado.' );
		}
		self::assertSame(
			array(
				'catalog'     => EmptyStateCopy::DEFAULT_CATALOG,
				'filters'     => EmptyStateCopy::DEFAULT_FILTERS,
				'suggest'     => EmptyStateCopy::DEFAULT_SUGGEST,
				'notice'      => BrandRelax::DEFAULT_NOTICE,
				'suggestions' => true,
				'series'      => EmptyStateCopy::DEFAULT_SERIES_LABEL,
				'popular'     => EmptyStateCopy::DEFAULT_POPULAR_LABEL,
				'tail'        => EmptyStateSuggestions::default_tail_words(),
				'network'     => EmptyStateCopy::DEFAULT_NETWORK,
			),
			$this->lo_que_lee_la_tienda()
		);
		$registro = (string) json_encode( $this->options[ AuditLog::OPTION_KEY ] ?? null, JSON_UNESCAPED_UNICODE );
		self::assertStringContainsString( 'Rótulo de lo más buscado', $registro, 'Volver a fábrica es un cambio: queda en el registro.' );
		self::assertStringContainsString( 'Aviso de conexión del catálogo', $registro );
		self::assertStringContainsString( 'Salidas en «sin resultados»: se activó', $registro );
	}

	/**
	 * @testdox Una pestaña de Ajustes cargada con 0.1.74 no apaga las salidas ni pisa los rótulos ni las palabras al guardarse con 0.1.75.
	 */
	public function test_formulario_de_0_1_74_conserva_lo_que_no_trae(): void {
		// Producción: salidas encendidas por defecto, sin fila.
		$this->options = $this->produccion();
		$this->guardar( $this->formulario_de_0_1_74() );

		self::assertArrayNotHasKey( EmptyStateSuggestions::OPTION_ENABLED, $this->options, 'La casilla que el formulario viejo no pintaba no es una casilla desmarcada.' );
		self::assertArrayNotHasKey( AuditLog::OPTION_KEY, $this->options, 'Nada cambió: el registro no puede decir «se apagó».' );

		// El dueño ya las apagó y personalizó los rótulos y la lista en otra pestaña.
		$this->options = $this->produccion() + array(
			EmptyStateSuggestions::OPTION_ENABLED    => 'no',
			EmptyStateCopy::OPTION_SERIES_LABEL      => '¿Era esta serie?',
			EmptyStateCopy::OPTION_POPULAR_LABEL     => 'Lo que más se busca',
			EmptyStateSuggestions::OPTION_TAIL_WORDS => array( 'tomo', 'vol' ),
		);
		$form = $this->formulario_de_0_1_74();
		unset( $form['toggle_fuzzy'] );
		$this->guardar( $form );

		self::assertSame( 'no', $this->options['akb_search_fuzzy'] ?? null, 'Lo que el formulario viejo sí trae se sigue guardando.' );
		self::assertSame( 'no', $this->options[ EmptyStateSuggestions::OPTION_ENABLED ] ?? null );
		self::assertSame( '¿Era esta serie?', $this->options[ EmptyStateCopy::OPTION_SERIES_LABEL ] ?? null );
		self::assertSame( 'Lo que más se busca', $this->options[ EmptyStateCopy::OPTION_POPULAR_LABEL ] ?? null );
		self::assertSame( array( 'tomo', 'vol' ), $this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] ?? null );
		$registro = (string) json_encode( $this->options[ AuditLog::OPTION_KEY ] ?? null, JSON_UNESCAPED_UNICODE );
		self::assertStringContainsString( 'Corrección de errores de tipeo: se apagó', $registro );
		self::assertStringNotContainsString( 'Salidas', $registro );
		self::assertStringNotContainsString( 'Rótulo', $registro );
		self::assertStringNotContainsString( 'Palabras de formato', $registro );
	}

	/**
	 * @testdox Si la base no suelta una fila de fábrica, el guardado avisa del fallo y deshace lo que ya había escrito.
	 */
	public function test_si_la_fila_no_se_suelta_el_guardado_falla_y_se_deshace(): void {
		$this->options                                         = $this->produccion();
		$this->options[ EmptyStateCopy::OPTION_SERIES_LABEL ]  = '¿Era esta serie?';
		$this->options[ EmptyStateCopy::OPTION_POPULAR_LABEL ] = 'Lo que más se busca';
		$this->borrado_falla                                   = array( EmptyStateCopy::OPTION_POPULAR_LABEL );
		$antes                                                 = $this->options;

		$form                              = $this->formulario_sin_tocar();
		$form['texto_empty_series_label']  = 'Quizá era esta';
		$form['texto_empty_popular_label'] = '';
		$aviso                             = $this->guardar( $form );

		self::assertSame( 'save_failed', $aviso, 'Un borrado que no ocurrió no puede anunciarse como «guardado».' );
		ksort( $antes );
		$despues = $this->options;
		ksort( $despues );
		self::assertSame( $antes, $despues, 'El rótulo ya escrito vuelve a su valor, las pistas ya soltadas vuelven a su fila y no queda registro de un cambio que no se hizo.' );
	}

	/**
	 * @testdox En una tienda sin ninguna fila, guardar sin tocar nada no escribe ningún valor de fábrica.
	 */
	public function test_tienda_sin_filas_no_escribe_ningun_valor_de_fabrica(): void {
		$this->guardar( $this->formulario_sin_tocar() );

		foreach ( self::DE_FABRICA as $option ) {
			self::assertArrayNotHasKey( $option, $this->options, $option . ' no debe nacer de un guardado sin cambios.' );
		}
		self::assertArrayNotHasKey( AuditLog::OPTION_KEY, $this->options, 'Nada cambió: nada que registrar.' );
	}

	/**
	 * @testdox Lo que el dueño cambia sí se escribe, también el interruptor de vuelta a encendido.
	 */
	public function test_lo_que_cambia_si_se_escribe(): void {
		$this->options = $this->produccion();

		$form                             = $this->formulario_sin_tocar();
		$form['texto_empty_series_label'] = '¿Era esta serie?';
		unset( $form['toggle_empty_suggestions'] );
		$this->guardar( $form );

		self::assertSame( '¿Era esta serie?', $this->options[ EmptyStateCopy::OPTION_SERIES_LABEL ] ?? null );
		self::assertSame( 'no', $this->options[ EmptyStateSuggestions::OPTION_ENABLED ] ?? null, 'Apagar las salidas es un cambio: se escribe.' );
		self::assertArrayNotHasKey( EmptyStateCopy::OPTION_POPULAR_LABEL, $this->options, 'El rótulo que no se tocó sigue sin fila.' );
		self::assertStringContainsString( 'Rótulo de la serie sugerida', (string) json_encode( $this->options[ AuditLog::OPTION_KEY ] ?? null, JSON_UNESCAPED_UNICODE ), 'El cambio queda en el registro.' );

		$this->guardar( $this->formulario_sin_tocar() + array( 'toggle_empty_suggestions' => 'yes' ) );
		self::assertTrue( EmptyStateSuggestions::enabled(), 'Volver a encender también es un cambio: la fila «no» no puede quedar pegada.' );
		self::assertArrayNotHasKey( EmptyStateSuggestions::OPTION_ENABLED, $this->options, 'Encendidas es lo de fábrica: sin fila, no un «yes» fijo.' );
		self::assertSame( '¿Era esta serie?', $this->options[ EmptyStateCopy::OPTION_SERIES_LABEL ] ?? null, 'Un rótulo propio sobrevive a un guardado que no lo tocó.' );
	}

	/**
	 * @testdox Vaciar un rótulo propio lo devuelve al de fábrica.
	 */
	public function test_vaciar_un_rotulo_propio_lo_devuelve_al_de_fabrica(): void {
		$this->options                                        = $this->produccion();
		$this->options[ EmptyStateCopy::OPTION_POPULAR_LABEL ] = 'Lo que más se busca';

		$form                              = $this->formulario_sin_tocar();
		$form['texto_empty_popular_label'] = '   ';
		$this->guardar( $form );

		self::assertSame( EmptyStateCopy::DEFAULT_POPULAR_LABEL, EmptyStateCopy::popular_label() );
		self::assertArrayNotHasKey( EmptyStateCopy::OPTION_POPULAR_LABEL, $this->options, 'Leer el de fábrica no basta: con el literal guardado como fila, el rótulo de una versión futura no llegaría.' );
	}

	/**
	 * @testdox Las palabras de fábrica en otro orden son la lista de fábrica: la fila se suelta y no queda congelada.
	 */
	public function test_la_lista_de_fabrica_en_otro_orden_suelta_la_fila(): void {
		$this->options = $this->produccion();
		$fabrica       = EmptyStateSuggestions::default_tail_words();

		// 1) Quitar «usado»: es una lista propia y se escribe.
		$sin_usado = array_values( array_diff( $fabrica, array( 'usado' ) ) );
		$this->guardar( array( 'palabras_empty_tail_words' => implode( "\n", $sin_usado ) ) + $this->formulario_sin_tocar() );
		self::assertSame( $sin_usado, $this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] ?? null );

		// 2) Volver a escribirlo AL FINAL: mismo conjunto que fábrica, otro orden.
		$this->guardar( array( 'palabras_empty_tail_words' => implode( "\n", $sin_usado ) . "\nusado" ) + $this->formulario_sin_tocar() );
		self::assertArrayNotHasKey( EmptyStateSuggestions::OPTION_TAIL_WORDS, $this->options, 'El conjunto de fábrica en otro orden es la lista de fábrica: sin fila, o una mejora futura ya no llega.' );
		self::assertSame( $fabrica, EmptyStateSuggestions::tail_words() );

		// 3) Barajar la de fábrica, con mayúsculas y tildes, sin fila previa: ni fila ni registro.
		$registro_antes  = $this->options[ AuditLog::OPTION_KEY ] ?? null;
		$this->al_cerrar = array();
		$barajada        = array_reverse( $fabrica );
		$barajada[0]    = 'Disponible';
		$barajada[]     = 'edición';
		$this->guardar( array( 'palabras_empty_tail_words' => implode( ', ', $barajada ) ) + $this->formulario_sin_tocar() );
		self::assertArrayNotHasKey( EmptyStateSuggestions::OPTION_TAIL_WORDS, $this->options );
		self::assertSame( $registro_antes, $this->options[ AuditLog::OPTION_KEY ] ?? null, 'Reordenar no es un cambio: no se registra.' );
		self::assertSame( array(), $this->al_cerrar, 'Ni purga.' );
	}

	/**
	 * @testdox Con una lista propia, la fila ofrece volver a la de fábrica, dice qué difiere, y la casilla suelta la fila.
	 */
	public function test_la_casilla_vuelve_a_la_lista_de_fabrica(): void {
		$this->options = $this->produccion();
		$html          = $this->pantalla();
		self::assertStringNotContainsString( 'fabrica_empty_tail_words', $html, 'Con la lista de fábrica no hay nada a lo que volver.' );

		$this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] = array_merge( array_values( array_diff( EmptyStateSuggestions::default_tail_words(), array( 'usado' ) ) ), array( 'saga' ) );
		$html = $this->pantalla();
		self::assertMatchesRegularExpression( '/<label for="akb-words-empty_tail_words-fabrica"><input type="checkbox" id="akb-words-empty_tail_words-fabrica" name="fabrica_empty_tail_words" value="1" aria-describedby="akb-words-empty_tail_words-fabrica-desc"> Volver a la lista de fábrica<\/label>/', $html );
		self::assertStringContainsString( 'faltan de la de fábrica: usado; agregaste: saga', $html, 'El dueño ve qué perdería y qué recuperaría antes de marcarla.' );

		$form                             = $this->formulario_sin_tocar();
		$form['fabrica_empty_tail_words'] = '1';
		$aviso                            = $this->guardar( $form );

		self::assertSame( 'synonyms_saved', $aviso );
		self::assertArrayNotHasKey( EmptyStateSuggestions::OPTION_TAIL_WORDS, $this->options, 'La casilla gana a lo que diga el cuadro y suelta la fila.' );
		self::assertSame( EmptyStateSuggestions::default_tail_words(), EmptyStateSuggestions::tail_words() );
		self::assertStringContainsString( 'Palabras de formato', (string) json_encode( $this->options[ AuditLog::OPTION_KEY ] ?? null, JSON_UNESCAPED_UNICODE ), 'Volver a fábrica es un cambio: queda en el registro.' );

		// Una lista vaciada entera no se enumera palabra a palabra.
		$this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] = array();
		self::assertMatchesRegularExpression( '/\(faltan \d+ palabras de la de fábrica\)/', $this->pantalla() );
	}

	/**
	 * @testdox El registro describe el cambio de las palabras de formato como diferencia acotada, no como la lista entera (F-S-2).
	 */
	public function test_el_registro_dice_que_palabras_cambiaron(): void {
		$this->options = $this->produccion();
		$fabrica       = EmptyStateSuggestions::default_tail_words();
		$linea         = function (): string {
			$entradas = (array) ( $this->options[ AuditLog::OPTION_KEY ] ?? array() );
			self::assertNotSame( array(), $entradas, 'El cambio tiene que quedar en el registro.' );

			return (string) ( $entradas[0]['detail'] ?? '' );
		};

		// Quitar una y agregar otra: se nombran las dos, no las 124.
		$propia = array_merge( array_values( array_diff( $fabrica, array( 'usado' ) ) ), array( 'saga' ) );
		$this->guardar( array( 'palabras_empty_tail_words' => implode( "\n", $propia ) ) + $this->formulario_sin_tocar() );
		self::assertSame( 'Palabras de formato de «Quizá buscabas»: agregaste saga; quitaste usado', $linea(), 'Medido en el espejo: una fila de 994 caracteres con la lista entera.' );

		// Vaciarla.
		$this->guardar( array( 'palabras_empty_tail_words' => '' ) + $this->formulario_sin_tocar() );
		self::assertSame( 'Palabras de formato de «Quizá buscabas»: se vació', $linea(), 'Antes: «se reescribió («»)».' );

		// Volver a la de fábrica con la casilla: lo que pasó, no las 124 palabras.
		$this->guardar( array( 'fabrica_empty_tail_words' => '1' ) + $this->formulario_sin_tocar() );
		self::assertSame( 'Palabras de formato de «Quizá buscabas»: volvió a la de fábrica', $linea() );

		// Reemplazarla por 30 propias: más de veinte palabras se cuentan.
		$nuevas = array();
		foreach ( range( 'a', 'z' ) as $letra ) {
			$nuevas[] = 'zq' . $letra;
		}
		$nuevas = array_merge( $nuevas, array( 'zzqa', 'zzqb', 'zzqc', 'zzqd' ) );
		$this->guardar( array( 'palabras_empty_tail_words' => implode( ', ', $nuevas ) ) + $this->formulario_sin_tocar() );
		$cuenta = count( explode( ', ', EmptyStateSuggestions::tail_words_key( $fabrica ) ) );
		self::assertSame( 'Palabras de formato de «Quizá buscabas»: agregaste 30 palabras; quitaste ' . $cuenta . ' palabras', $linea() );

		// Y desde una lista propia, una palabra más: sólo esa.
		$this->guardar( array( 'palabras_empty_tail_words' => implode( ', ', $nuevas ) . ', manhwa' ) + $this->formulario_sin_tocar() );
		self::assertSame( 'Palabras de formato de «Quizá buscabas»: agregaste manhwa', $linea() );

		foreach ( (array) $this->options[ AuditLog::OPTION_KEY ] as $entrada ) {
			self::assertStringNotContainsString( 'se reescribió', (string) $entrada['detail'] );
			self::assertLessThan( 200, mb_strlen( (string) $entrada['detail'] ), 'Una fila del cuaderno se lee de un vistazo.' );
		}
	}

	/**
	 * @testdox Un texto que dice «no» o «yes» queda en el registro como reescrito, no como un interruptor que se apagó o se activó (REV-FS2-1o-1).
	 *
	 * El registro decidía por el VALOR: «no» era «se apagó» y «yes», «se activó»,
	 * aunque la fila fuera una pista. Se recorre cada texto que la pantalla
	 * pinta, así que una fila de texto nueva entra sola a la prueba.
	 */
	public function test_un_texto_que_dice_no_se_registra_como_reescrito(): void {
		$this->options = $this->produccion();
		$textos        = array_values(
			array_filter(
				array_keys( $this->formulario_sin_tocar() ),
				static fn( string $campo ): bool => str_starts_with( $campo, 'texto_' )
			)
		);
		self::assertContains( 'texto_empty_hint_filters', $textos, 'La fila que destapó el fallo tiene que estar entre las recorridas.' );
		self::assertGreaterThanOrEqual( 6, count( $textos ), 'Las pistas, los rótulos y el aviso de la editorial: si salen menos, el barrido dejó de verlos.' );

		$ultima = function (): string {
			$entradas = (array) ( $this->options[ AuditLog::OPTION_KEY ] ?? array() );
			self::assertNotSame( array(), $entradas, 'El cambio tiene que quedar en el registro.' );

			return (string) ( $entradas[0]['detail'] ?? '' );
		};

		foreach ( $textos as $campo ) {
			foreach ( array( 'no', 'yes' ) as $valor ) {
				$this->options = $this->produccion();
				$this->guardar( array( $campo => $valor ) + $this->formulario_sin_tocar() );

				$detalle = $ultima();
				self::assertStringEndsWith( ': se reescribió («' . $valor . '»)', $detalle, $campo . ' = «' . $valor . '»: un texto no se enciende ni se apaga.' );
				self::assertStringNotContainsString( 'se apagó', $detalle, $campo );
				self::assertStringNotContainsString( 'se activó', $detalle, $campo );
			}
		}

		$this->options = $this->produccion();
		$this->guardar( array( 'texto_empty_hint_filters' => 'no' ) + $this->formulario_sin_tocar() );
		self::assertSame( 'Pista sin resultados (filtros sobre una búsqueda): se reescribió («no»)', $ultima(), 'Medido en el espejo antes del arreglo: «…: se apagó».' );

		// Los interruptores siguen diciendo que se apagaron, también el de las
		// salidas, que purga como un texto pero es una casilla.
		$this->options = $this->produccion();
		$form          = $this->formulario_sin_tocar();
		unset( $form['toggle_fuzzy'] );
		$this->guardar( $form );
		self::assertSame( 'Corrección de errores de tipeo: se apagó', $ultima() );

		$this->options = $this->produccion();
		$form          = $this->formulario_sin_tocar();
		unset( $form['toggle_empty_suggestions'] );
		$this->guardar( $form );
		self::assertSame( 'Salidas en «sin resultados»: se apagó', $ultima() );
	}

	/**
	 * @testdox Un rótulo recortado al tope o sin sus etiquetas se guarda y el aviso dice cuál y por qué.
	 */
	public function test_un_texto_alterado_al_guardar_lo_dice_el_aviso(): void {
		$this->options = $this->produccion();

		$form                              = $this->formulario_sin_tocar();
		$form['texto_empty_series_label']  = str_repeat( 'a', 375 );
		$form['texto_empty_popular_label'] = '<b>esto</b>';
		$aviso                             = $this->guardar( $form );

		self::assertSame( 'settings_saved_altered', $aviso, '«Cambios guardados.» a secas escondía que el texto no quedó como se escribió.' );
		self::assertSame( str_repeat( 'a', EmptyStateCopy::MAX_LENGTH ), $this->options[ EmptyStateCopy::OPTION_SERIES_LABEL ] ?? null );
		self::assertSame( 'esto', $this->options[ EmptyStateCopy::OPTION_POPULAR_LABEL ] ?? null );

		$_GET['akb_search'] = 'settings_saved_altered';
		$aviso_html         = $this->aviso_pintado();
		self::assertStringContainsString( 'notice-warning', $aviso_html );
		self::assertStringContainsString( 'Ajustes guardados, pero no todo quedó como lo escribiste.', $aviso_html );
		self::assertStringContainsString( '«Rótulo de la serie sugerida» quedó recortado a 300 caracteres.', $aviso_html );
		self::assertStringContainsString( '«Rótulo de lo más buscado» se guardó sin las etiquetas HTML', $aviso_html );
		self::assertSame( array(), $this->transients, 'El detalle se lee una vez y se borra.' );

		// Palabras de más de 64 caracteres: el mismo aviso, con su frase.
		$form                              = $this->formulario_sin_tocar();
		$form['palabras_empty_tail_words'] = "tomo\n" . str_repeat( 'x', 65 );
		self::assertSame( 'settings_saved_altered', $this->guardar( $form ) );
		self::assertStringContainsString( 'De las palabras de formato se descartaron las de más de 64 caracteres.', $this->aviso_pintado() );

		// Un texto que cabe y no lleva etiquetas: el aviso de siempre.
		$form                             = $this->formulario_sin_tocar();
		$form['texto_empty_series_label'] = '  ¿Era   esta serie?  ';
		self::assertSame( 'synonyms_saved', $this->guardar( $form ), 'Aplanar espacios no es alterar lo escrito.' );
	}

	/**
	 * @testdox Cambiar sólo textos purga el origen con «*»; un interruptor del motor, la tienda entera; nada, ninguna.
	 */
	public function test_cada_cambio_purga_lo_justo(): void {
		$this->options = $this->produccion();

		$casos = array(
			'rótulo'          => array( array( 'texto_empty_series_label' => '¿Era esta serie?' ), array( 'litespeed_purge', '*' ) ),
			'pista'           => array( array( 'texto_empty_hint_suggest' => 'Escríbenos por WhatsApp.' ), array( 'litespeed_purge', '*' ) ),
			'pista de filtros' => array( array( 'texto_empty_hint_filters' => 'Hay tomos: [sin filtros].' ), array( 'litespeed_purge', '*' ) ),
			// Viaja dentro del HTML de cada listado, como las pistas: purga el
			// origen, no la tienda entera (C-S-c1).
			'aviso de conexión' => array( array( 'texto_network_notice' => 'Sin señal. [Otra vez]' ), array( 'litespeed_purge', '*' ) ),
			'palabras'        => array( array( 'palabras_empty_tail_words' => 'tomo' ), array( 'litespeed_purge', '*' ) ),
			'motor'           => array( array( 'quitar' => 'toggle_fuzzy' ), array( 'litespeed_purge_all' ) ),
			'motor y rótulo'  => array( array( 'quitar' => 'toggle_suggest', 'texto_empty_popular_label' => 'Lo que más se busca' ), array( 'litespeed_purge_all' ) ),
		);
		foreach ( $casos as $caso => [ $cambios, $purga ] ) {
			$form = $this->formulario_sin_tocar();
			if ( isset( $cambios['quitar'] ) ) {
				unset( $form[ $cambios['quitar'] ], $cambios['quitar'] );
			}
			$this->acciones  = array();
			$this->al_cerrar = array();
			$this->guardar( $cambios + $form );

			self::assertCount( 1, $this->al_cerrar, $caso . ': una purga, en shutdown.' );
			( $this->al_cerrar[0] )();
			$purgas = array_values( array_filter( $this->acciones, static fn( array $a ): bool => str_starts_with( (string) $a[0], 'litespeed_purge' ) ) );
			self::assertSame( array( $purga ), $purgas, $caso );
		}

		$this->al_cerrar = array();
		$this->guardar( $this->formulario_sin_tocar() );
		self::assertSame( array(), $this->al_cerrar, 'Guardar sin cambios no purga nada.' );
	}

	/** Pantalla de Ajustes pintada con las options actuales. */
	private function pantalla(): string {
		SemanticPublication::reset_authoritative_state_for_tests();
		$method = new ReflectionMethod( SearchAdminSection::class, 'render_settings' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( new SearchAdminSection() );

		return (string) ob_get_clean();
	}

	/** El aviso que pinta la pantalla tras la redirección. */
	private function aviso_pintado(): string {
		$_GET['akb_search'] = $_GET['akb_search'] ?? 'settings_saved_altered';
		$method             = new ReflectionMethod( SearchAdminSection::class, 'render_notice' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( new SearchAdminSection() );

		return (string) ob_get_clean();
	}

	/**
	 * Lo que lee la tienda de cada ajuste con valor de fábrica, por sus lectores reales.
	 *
	 * @return array<string, mixed>
	 */
	private function lo_que_lee_la_tienda(): array {
		return array(
			'catalog'     => EmptyStateCopy::catalog(),
			'filters'     => EmptyStateCopy::filters(),
			'suggest'     => EmptyStateCopy::suggest(),
			'notice'      => BrandRelax::template(),
			'suggestions' => EmptyStateSuggestions::enabled(),
			'series'      => EmptyStateCopy::series_label(),
			'popular'     => EmptyStateCopy::popular_label(),
			'tail'        => EmptyStateSuggestions::tail_words(),
			'network'     => EmptyStateCopy::network(),
		);
	}

	/**
	 * Lo que envía una pestaña de Ajustes cargada con 0.1.74 si nadie la toca.
	 *
	 * 0.1.74 (el ZIP de producción) pintaba siete casillas y tres textos; el
	 * interruptor de las salidas, los dos rótulos, las palabras de formato y su
	 * marcador de presencia nacieron en 0.1.75. Se parte del formulario de hoy y
	 * se deja sólo lo que aquel traía, con lista cerrada: un campo nuevo mañana
	 * no puede colarse en el formulario viejo.
	 *
	 * @return array<string, string>
	 */
	private function formulario_de_0_1_74(): array {
		$campos = array(
			'toggle_fuzzy',
			'toggle_sku_fastpath',
			'toggle_novedad',
			'toggle_indexing',
			'toggle_suggest',
			'toggle_popular',
			'toggle_facets',
			'texto_empty_hint_catalog',
			'texto_empty_hint_suggest',
			'texto_relaxed_notice',
		);
		$form = array_intersect_key( $this->formulario_sin_tocar(), array_flip( $campos ) );
		self::assertArrayHasKey( 'texto_relaxed_notice', $form, 'El formulario viejo sí traía los tres textos.' );

		return $form;
	}

	/**
	 * Filas de producción medidas el 2026-09-24 (`wp option list --search=akb_search_*`).
	 *
	 * @return array<string, mixed>
	 */
	private function produccion(): array {
		return array(
			'akb_search_fuzzy'             => 'yes',
			'akb_search_novedad'           => 'yes',
			'akb_search_popular_enabled'   => 'yes',
			'akb_search_sku_fastpath'      => 'yes',
			'akb_search_indexing'          => 'yes',
			'akb_search_suggest'           => 'yes',
			'akb_search_facets'            => 'yes',
			EmptyStateCopy::OPTION_CATALOG => EmptyStateCopy::DEFAULT_CATALOG,
			EmptyStateCopy::OPTION_SUGGEST => EmptyStateCopy::DEFAULT_SUGGEST,
		);
	}

	/**
	 * Lo que envía la pantalla de Ajustes si nadie toca nada: cada campo con el
	 * valor que PINTÓ, los checkbox sólo si salieron marcados y los campos
	 * ocultos del formulario.
	 *
	 * @return array<string, string>
	 */
	private function formulario_sin_tocar(): array {
		SemanticPublication::reset_authoritative_state_for_tests();
		$method = new ReflectionMethod( SearchAdminSection::class, 'render_settings' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( new SearchAdminSection() );
		$html = (string) ob_get_clean();

		$form = array();
		preg_match_all( '/<input type="hidden" name="([a-z_]+)" value="([^"]*)"/', $html, $ocultos, PREG_SET_ORDER );
		foreach ( $ocultos as $oculto ) {
			$form[ $oculto[1] ] = html_entity_decode( $oculto[2], ENT_QUOTES, 'UTF-8' );
		}
		preg_match_all( '/<input type="checkbox"[^>]*name="(toggle_[a-z_]+)"[^>]*>/', $html, $toggles, PREG_SET_ORDER );
		foreach ( $toggles as $toggle ) {
			if ( str_contains( $toggle[0], 'checked' ) ) {
				$form[ $toggle[1] ] = 'yes';
			}
		}
		preg_match_all( '/name="(texto_[a-z_]+)" value="([^"]*)"/', $html, $textos, PREG_SET_ORDER );
		foreach ( $textos as $texto ) {
			$form[ $texto[1] ] = html_entity_decode( $texto[2], ENT_QUOTES, 'UTF-8' );
		}
		preg_match_all( '/name="(palabras_[a-z_]+)"[^>]*>([^<]*)<\/textarea>/', $html, $listas, PREG_SET_ORDER );
		foreach ( $listas as $lista ) {
			$form[ $lista[1] ] = html_entity_decode( $lista[2], ENT_QUOTES, 'UTF-8' );
		}

		self::assertArrayHasKey( 'texto_empty_series_label', $form, 'El formulario pintado trae el rótulo: si no, esta prueba no prueba nada.' );
		self::assertArrayHasKey( 'palabras_empty_tail_words', $form );

		return $form;
	}

	/**
	 * Envía el formulario al handler real y devuelve el aviso de la redirección.
	 *
	 * @param array<string, string> $form Campos del POST.
	 */
	private function guardar( array $form ): string {
		$_POST = $form;
		SemanticPublication::reset_authoritative_state_for_tests();

		try {
			( new SearchAdminSection() )->handle_save_settings();
		} catch ( RuntimeException $redirect ) {
			parse_str( (string) parse_url( $redirect->getMessage(), PHP_URL_QUERY ), $query );

			return (string) ( $query['akb_search'] ?? '' );
		}

		self::fail( 'handle_save_settings() tenía que redirigir.' );
	}
}

