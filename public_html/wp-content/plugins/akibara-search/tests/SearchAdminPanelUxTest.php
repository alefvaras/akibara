<?php
/**
 * Candados de la pantalla «Buscador»: que los controles no prometan acciones
 * que no existen, que el registro de cambios no invente cambios y que los
 * textos hablen en la variante del país.
 *
 * Cada prueba fija una INVARIANTE (la propiedad que importa), no una cadena
 * literal ni una versión: las etiquetas se pueden reescribir sin romper nada,
 * el defecto no.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\RebuildJob;
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

/** @covers \Akibara\Search\SearchAdminSection */
final class SearchAdminPanelUxTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();

		// La configuración del buscador se sirve desde UNA foto por proceso, y los
		// sinónimos y stopwords llevan su memo: sin soltarlos, cada prueba leía lo
		// que dejó la anterior. `test_prefill_…` pasaba igual porque su grupo
		// («aot, attack on titan») era un trozo literal de la línea de FÁBRICA; al
		// reescribir esa línea (R2-741f74eb) el orden inverso la puso en rojo y
		// enseñó que llevaba meses pasando por la razón equivocada.
		\Akibara\Search\SemanticPublication::reset_authoritative_state_for_tests();
		\Akibara\Search\Synonyms::flush();
		\Akibara\Search\Stopwords::flush();

		Functions\when( 'esc_html' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_attr' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_url' )->alias( static fn( $v ): string => (string) $v );
		Functions\when( 'esc_textarea' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'admin_url' )->alias( static fn( string $p = '' ): string => 'https://example.test/wp-admin/' . $p );
		Functions\when( 'wp_nonce_url' )->alias( static fn( string $url ): string => $url . '&_wpnonce=test' );
		Functions\when( 'date_i18n' )->alias( static fn( string $f, int $ts ): string => gmdate( 'd M', $ts ) );
		Functions\when( 'wp_nonce_field' )->alias( static function (): void {
			echo '<input type="hidden" name="_wpnonce" value="nonce">';
		} );
		Functions\when( 'submit_button' )->alias( static function ( string $label = 'Guardar' ): void {
			printf( '<button type="submit" class="button button-primary">%s</button>', $label );
		} );
		Functions\when( 'checked' )->alias(
			static fn( $checked, $current = true, $echo = true ): string => (string) $checked === (string) $current ? " checked='checked'" : ''
		);
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => (string) $n );
		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		// GapDismissals::dismiss()/restore() relee de la base bajo candado desde
		// 2026-09-19 (R3-558b3ea7); sin `$wpdb` en este doble, OptionLock no toma
		// candado (mejor esfuerzo) y esto sólo evita el fatal por función no
		// definida en test_los_handlers_del_radar_sanean_despues_de_decodificar().
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox En fase terminal el panel NO ofrece un botón de reconstrucción que el protocolo va a ignorar.
	 *
	 * RebuildJob::schedule() sale temprano ante una fase terminal (ni force la
	 * reanima) y HealthReport devuelve esa razón con cta '' (nunca 'retry'), así
	 * que el botón «Reconstruir índice» que se pintaba ahí POSTeaba y no pasaba
	 * nada, con un aviso que prometía un «Reintentar» inexistente.
	 */
	public function test_fase_terminal_no_ofrece_boton_de_reconstruccion(): void {
		foreach ( array( RebuildJob::PHASE_BLOCKED, RebuildJob::PHASE_ABORTED ) as $phase ) {
			$html = $this->render_maintenance( $phase );

			self::assertStringNotContainsString(
				'akb_search_rebuild',
				$html,
				'Fase ' . $phase . ': el panel no debe ofrecer una acción de rebuild que schedule() ignora.'
			);
			self::assertStringContainsString( 'akb_search_reconcile_run', $html, 'La verificación de consistencia sí sigue disponible.' );
		}
	}

	/** @testdox Un estado sano conserva el botón de reconstrucción. */
	public function test_estado_sano_conserva_el_boton(): void {
		self::assertStringContainsString( 'akb_search_rebuild', $this->render_maintenance( RebuildJob::PHASE_BUILDING ) );
	}

	/**
	 * @testdox Guardar Ajustes sin tocar nada no registra ningún cambio, aunque las options no existan.
	 *
	 * Invariante estructural: el estado que pintan los checkboxes y el estado
	 * contra el que se hace el diff salen de la MISMA lectura. Cuando no lo
	 * hacían (`sku_fastpath` se pintaba con default 'yes' y se comparaba con
	 * default 'no'), el primer guardado en producción —donde la fila no
	 * existe— registraba el cambio falso «sku_fastpath: no→yes».
	 */
	public function test_guardar_sin_tocar_nada_no_inventa_cambios(): void {
		$this->options = array(); // producción: ninguna de estas filas existe.

		$html     = $this->render_settings();
		$marcados = $this->checked_toggles( $html );
		$textos   = $this->rendered_texts( $html );
		$after    = array();
		foreach ( array_keys( $this->current_values() ) as $key ) {
			// Ajustes dejó de ser sólo booleanos: los campos de texto se envían
			// con el valor que la pantalla PINTÓ, que es lo que hace un
			// formulario pre-rellenado que nadie tocó.
			$after[ $key ] = array_key_exists( $key, $textos )
				? $textos[ $key ]
				: ( in_array( $key, $marcados, true ) ? 'yes' : 'no' );
		}

		self::assertSame( array(), $this->settings_changes( $this->current_values(), $after ) );
	}

	/** @testdox Un cambio real sí se registra, y con etiqueta legible (no el slug). */
	public function test_un_cambio_real_se_registra_en_lenguaje_humano(): void {
		$before = $this->current_values();
		$after  = $before;
		$after['sku_fastpath'] = 'no';

		$changes = $this->settings_changes( $before, $after );

		self::assertCount( 1, $changes );
		self::assertStringNotContainsString( 'sku_fastpath', $changes[0], 'El registro se lee en pantalla: nada de slugs.' );
	}

	/**
	 * @testdox Toda acción que el panel escribe en el registro tiene etiqueta legible.
	 *
	 * `ajustes_guardados` se imprimía como slug crudo porque el mapa sólo tenía
	 * cuatro códigos. La invariante es que el mapa cubra lo que el propio
	 * fichero registra, hoy y cuando se agregue el próximo.
	 */
	public function test_todas_las_acciones_registradas_tienen_etiqueta(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/SearchAdminSection.php' );
		preg_match_all( "/AuditLog::record\(\s*'([a-z_]+)'/", $source, $matches );
		$codes = array_unique( $matches[1] );

		self::assertNotEmpty( $codes );
		$method = new ReflectionMethod( SearchAdminSection::class, 'audit_action_label' );
		$method->setAccessible( true );
		foreach ( $codes as $code ) {
			self::assertNotSame(
				$code,
				$method->invoke( new SearchAdminSection(), $code ),
				'La acción «' . $code . '» se imprime como slug técnico en la columna Acción.'
			);
		}
	}

	/**
	 * @testdox Los textos del panel están en tuteo, sin formas voseantes.
	 *
	 * «acá» NO entra en la lista: es uso corriente en Chile (y aparece en
	 * comentarios internos), no rioplatense. Lo que se vigila son las formas
	 * verbales voseantes, que sí se colaron en dos cadenas visibles.
	 */
	public function test_sin_voseo_en_los_textos_del_panel(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/SearchAdminSection.php' );

		preg_match_all( '/\b(querés|editás|podés|tenés|querías|apagalo|prendelo|activalo|revisalo|mirá|elegí|hacé|andá|poné|sacá)\b/u', $source, $matches );

		self::assertSame( array(), $matches[0], 'Formas voseantes en el panel: ' . implode( ', ', $matches[0] ) );
	}

	/**
	 * @testdox Un servicio configurado pero no efectivo explica la causa y ofrece a dónde ir.
	 *
	 * «No disponible» a secas no distingue «esperá a que termine» de «esto no
	 * arranca solo». La única compuerta entre configurado y efectivo es la
	 * publicación editorial, así que la causa sale de su propio status().
	 */
	public function test_servicio_no_efectivo_explica_la_causa(): void {
		$method = new ReflectionMethod( SearchAdminSection::class, 'render_services' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke(
			new SearchAdminSection(),
			array(
				'indexing' => array( 'configured' => true, 'published' => true, 'effective' => true ),
				'suggest'  => array( 'configured' => true, 'published' => true, 'effective' => false ),
				'facets'   => array( 'configured' => true, 'published' => true, 'effective' => true ),
			),
			array( 'ready' => false, 'error' => 'materialization_stale' ),
			array( 'available' => true, 'pending' => false )
		);
		$html = (string) ob_get_clean();

		// Invariante: la fila que dice «No disponible» trae causa Y destino.
		$fila = (string) strstr( $html, 'no disponible ahora' );
		$fila = (string) substr( $fila, 0, (int) strpos( $fila, '</tr>' ) );
		self::assertStringContainsString( '#akb-search-maintenance', $fila );
		self::assertGreaterThan( 80, mb_strlen( wp_strip_all_tags_local( $fila ) ), 'La causa tiene que ser una frase, no un adjetivo.' );
	}

	/**
	 * Hallazgo M2: toda fila «no disponible» salía con la causa de la publicación
	 * editorial, así que el panel culpaba a «precios y facetas» de cosas que no
	 * lo son. La indexación NUNCA depende de ellos: su efectivo es la foto
	 * semántica más el filtro de código.
	 *
	 * @testdox La indexación apagada nunca se explica con los precios.
	 */
	public function test_la_indexacion_no_se_explica_con_los_precios(): void {
		$html = $this->render_services(
			array(
				'indexing' => array( 'configured' => true, 'published' => false, 'effective' => false ),
				'suggest'  => array( 'configured' => false, 'published' => false, 'effective' => false ),
				'facets'   => array( 'configured' => false, 'published' => false, 'effective' => false ),
			),
			array( 'ready' => false, 'error' => 'materialization_stale' ),
			array( 'available' => true, 'pending' => false )
		);

		$fila = $this->service_row( $html, 'Indexación automática' );
		self::assertStringContainsString( 'akb_search_indexing_enabled', $fila, 'La causa real es el filtro de código.' );
		self::assertStringNotContainsString( 'precios', $fila );
	}

	/**
	 * @testdox Un ajuste guardado y sin publicar se dice así, no como un problema de precios.
	 */
	public function test_un_ajuste_sin_publicar_no_culpa_a_los_precios(): void {
		$html = $this->render_services(
			array(
				'indexing' => array( 'configured' => true, 'published' => true, 'effective' => true ),
				'suggest'  => array( 'configured' => true, 'published' => true, 'effective' => false ),
				'facets'   => array( 'configured' => true, 'published' => true, 'effective' => true ),
			),
			array( 'ready' => false, 'error' => 'materialization_stale' ),
			array( 'available' => true, 'pending' => true )
		);

		$fila = $this->service_row( $html, 'Autocompletado' );
		self::assertStringContainsString( 'se aplica al publicar', $fila );
		self::assertStringNotContainsString( 'precios', $fila );
	}

	/** @testdox Con la configuración del buscador ilegible, la fila lo dice en vez de culpar al interruptor. */
	public function test_la_configuracion_ilegible_se_nombra(): void {
		$html = $this->render_services(
			array(
				'indexing' => array( 'configured' => true, 'published' => false, 'effective' => false ),
				'suggest'  => array( 'configured' => true, 'published' => false, 'effective' => false ),
				'facets'   => array( 'configured' => true, 'published' => false, 'effective' => false ),
			),
			array( 'ready' => false, 'error' => 'materialization_stale' ),
			array( 'available' => false, 'pending' => null )
		);

		$fila = $this->service_row( $html, 'Autocompletado' );
		self::assertStringContainsString( 'no puede leer sus ajustes', $fila );
		self::assertStringNotContainsString( 'flag OFF', $fila, 'El interruptor está encendido: no puede insinuarse lo contrario.' );
	}

	/**
	 * Hallazgo A1 de la revisión de 0.1.63: «No se pudo leer la configuración»
	 * sin decir cuál dejaba al dueño reintentando a ciegas.
	 *
	 * @testdox Con la configuración ilegible, el panel nombra el dato y dónde se corrige.
	 */
	public function test_la_configuracion_ilegible_nombra_el_dato_y_donde_se_corrige(): void {
		$method = new ReflectionMethod( SearchAdminSection::class, 'editorial_gate_reason' );
		$method->setAccessible( true );

		$texto = (string) $method->invoke(
			new SearchAdminSection(),
			array(
				'error'          => 'source_snapshot_unreadable',
				'broken_options' => array(
					array( 'label' => 'la fecha de término del descuento', 'where' => 'Akibara → Descuentos' ),
					array( 'label' => 'la zona horaria del sitio', 'where' => 'Ajustes → Generales' ),
				),
			)
		);

		self::assertStringContainsString( 'la fecha de término del descuento (Akibara → Descuentos)', $texto );
		self::assertStringContainsString( 'la zona horaria del sitio (Ajustes → Generales)', $texto );
		self::assertStringContainsString( 'no lo arregla', $texto, 'Tiene que decir que reintentar otra cosa no sirve.' );
	}

	/**
	 * @param array<string, mixed> $flags     Flags de HealthReport::collect().
	 * @param array<string, mixed> $editorial Estado editorial.
	 * @param array<string, mixed> $semantic  Autoridad y publicación semántica.
	 */
	private function render_services( array $flags, array $editorial, array $semantic ): string {
		$method = new ReflectionMethod( SearchAdminSection::class, 'render_services' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( new SearchAdminSection(), $flags, $editorial, $semantic );

		return (string) ob_get_clean();
	}

	/** Fila de un servicio, de su encabezado al cierre. */
	private function service_row( string $html, string $servicio ): string {
		$fila = (string) strstr( $html, $servicio );

		return (string) substr( $fila, 0, (int) strpos( $fila, '</tr>' ) );
	}

	/**
	 * @testdox La lista de «sin resultados» distingue el hueco real del typo que el buscador ya corrige.
	 *
	 * Medido contra producción el 2026-08-23: 7 de las 20 filas ya las resuelve
	 * el corrector, así que leerlas todas como «contenido que falta» mandaba a
	 * crear sinónimos de cosas que ya funcionan.
	 */
	public function test_cero_rescatado_no_se_lee_igual_que_un_hueco_real(): void {
		$method = new ReflectionMethod( SearchAdminSection::class, 'zero_result_verdict_html' );
		$method->setAccessible( true );
		$panel = new SearchAdminSection();

		$hueco    = (string) $method->invoke( $panel, array( 'hits' => 0, 'fix' => '' ) );
		$corregido = (string) $method->invoke( $panel, array( 'hits' => 12, 'fix' => 'hellblazer' ) );
		$sin_medir = (string) $method->invoke( $panel, null );

		self::assertNotSame( $hueco, $corregido );
		self::assertStringContainsString( 'hellblazer', $corregido, 'La corrección concreta es el dato accionable.' );
		self::assertStringNotContainsString( 'hellblazer', $hueco );
		self::assertNotSame( $hueco, $sin_medir, 'Sin medición no se afirma que sea un hueco.' );
	}

	/** @testdox La precarga de Sinónimos agrega una línea nueva y no toca los grupos existentes. */
	public function test_prefill_de_sinonimos_agrega_linea_sin_pisar(): void {
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ): string => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'wp_unslash' )->alias( static fn( $v ) => $v );
		$this->options['akb_search_synonyms'] = array( array( 'aot', 'attack on titan' ) );

		$_GET['akb_prefill'] = 'bakemonogatari';
		$method              = new ReflectionMethod( SearchAdminSection::class, 'render_synonyms' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( new SearchAdminSection() );
		$html = (string) ob_get_clean();
		unset( $_GET['akb_prefill'] );

		$textarea = (string) strstr( $html, '<textarea' );
		$textarea = (string) substr( $textarea, 0, (int) strpos( $textarea, '</textarea>' ) );
		self::assertStringContainsString( 'aot, attack on titan', $textarea, 'Los grupos guardados no se tocan.' );
		self::assertStringContainsString( "\nbakemonogatari, ", $textarea, 'La búsqueda huérfana llega escrita, en una línea nueva.' );
	}

	/**
	 * R2-741f74eb (2026-09-19): dos grupos que comparten un término son uno solo
	 * para el motor —y «la espada» y «espada» son el mismo término, porque las
	 * palabras de relleno no cuentan—. El panel los guardaba sin decir nada y
	 * quien buscaba «blade» recibía también lo de «sword».
	 *
	 * @testdox La pestaña Sinónimos avisa, con los números de línea, cuando dos grupos comparten un término.
	 */
	public function test_sinonimos_avisa_de_los_grupos_que_se_unen(): void {
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ): string => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'wp_unslash' )->alias( static fn( $v ) => $v );
		$pintar = function ( array $grupos ): string {
			$this->options['akb_search_synonyms'] = $grupos;
			// La configuración del buscador se sirve desde UNA foto por petición:
			// entre un guardado y otro hay que soltarla, como haría otra petición.
			\Akibara\Search\SemanticPublication::reset_authoritative_state_for_tests();
			\Akibara\Search\Synonyms::flush();
			$method = new ReflectionMethod( SearchAdminSection::class, 'render_synonyms' );
			$method->setAccessible( true );
			ob_start();
			$method->invoke( new SearchAdminSection() );

			return (string) ob_get_clean();
		};

		$unidos = $pintar( array( array( 'jjk', 'jujutsu kaisen' ), array( 'la espada', 'sword' ), array( 'espada', 'blade' ) ) );
		self::assertStringContainsString( '«la espada» / «espada»', $unidos );
		self::assertStringContainsString( 'líneas 2 y 3', $unidos, 'El número es el de la línea del cuadro de texto.' );

		$separados = $pintar( array( array( 'jjk', 'jujutsu kaisen' ), array( 'espada', 'blade' ) ) );
		self::assertStringNotContainsString( 'un solo grupo', $separados );
	}

	/** @testdox Los sinónimos de fábrica no se avisan a sí mismos: ningún término repetido entre grupos. */
	public function test_los_sinonimos_de_fabrica_no_comparten_terminos(): void {
		self::assertSame( array(), \Akibara\Search\Synonyms::shared_terms( \Akibara\Search\Synonyms::sanitize_groups( \Akibara\Search\Synonyms::defaults() ) ) );
	}

	/**
	 * R3-4199bd7c (2026-09-19): el dueño vacía el textarea y guarda para que el
	 * buscador no ignore ninguna palabra. Antes, `Stopwords::all()` revivía los
	 * 25 defaults de fábrica en el PROPIO recargo del panel, así que el textarea
	 * mostraba lo contrario de lo que se acababa de guardar.
	 *
	 * @testdox Con la lista vacía guardada, el panel recarga el textarea vacío y explica cómo volver a fábrica.
	 */
	public function test_stopwords_vacio_recarga_textarea_vacio_con_ayuda_de_fabrica(): void {
		$this->options['akb_search_stopwords'] = array(); // lo que persist_options_exact() escribe al vaciar y guardar.
		\Akibara\Search\SemanticPublication::reset_authoritative_state_for_tests();
		\Akibara\Search\Stopwords::flush();

		$method = new ReflectionMethod( SearchAdminSection::class, 'render_stopwords' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( new SearchAdminSection() );
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '#<textarea[^>]*>(</textarea>)#', $html, 'El textarea tiene que recargar VACÍO: nada de revivir los 25 defaults.' );

		// La línea de ayuda existe y usa la MISMA lista que defaults(), no un
		// literal aparte que se desincroniza si alguien cambia la fuente única.
		self::assertStringContainsString( 'volver a la lista de fábrica', $html );
		self::assertStringContainsString( implode( ', ', \Akibara\Search\Stopwords::defaults() ), $html );
	}

	/** @param string $phase Fase durable. @return string */
	private function render_maintenance( string $phase ): string {
		$data = array(
			'rebuild' => array(
				'phase'      => $phase,
				'error'      => '',
				'done'       => 10,
				'total'      => 100,
				'topology'   => 'clean_pre',
				'started_at' => 0,
				'updated_at' => 0,
			),
		);
		$verdict = array( 'verdict' => 'atencion', 'reasons' => array( array( 'code' => 'x', 'message' => 'y', 'cta' => '' ) ) );

		$method = new ReflectionMethod( SearchAdminSection::class, 'render_maintenance' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( new SearchAdminSection(), $data, $verdict );

		return (string) ob_get_clean();
	}

	/** @return string */
	private function render_settings(): string {
		$method = new ReflectionMethod( SearchAdminSection::class, 'render_settings' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( new SearchAdminSection() );

		return (string) ob_get_clean();
	}

	/** @param string $html HTML de Ajustes. @return array<int, string> */
	private function checked_toggles( string $html ): array {
		preg_match_all( '/name="toggle_([a-z_]+)"[^>]*/', $html, $matches, PREG_SET_ORDER );
		$on = array();
		foreach ( $matches as $match ) {
			if ( str_contains( $match[0], 'checked' ) ) {
				$on[] = $match[1];
			}
		}

		return $on;
	}

	/**
	 * Valor pintado de cada campo de texto de Ajustes.
	 *
	 * @param string $html HTML de Ajustes.
	 * @return array<string, string>
	 */
	private function rendered_texts( string $html ): array {
		preg_match_all( '/name="texto_([a-z_]+)" value="([^"]*)"/', $html, $matches, PREG_SET_ORDER );
		$valores = array();
		foreach ( $matches as $match ) {
			$valores[ $match[1] ] = html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' );
		}
		// Las listas de palabras (textarea) viajan como las compara
		// `handle_save_settings()`: saneadas palabra a palabra y por su huella
		// (`EmptyStateSuggestions::tail_words_key()`, sin orden ni tildes).
		preg_match_all( '/name="palabras_([a-z_]+)"[^>]*>([^<]*)<\/textarea>/', $html, $listas, PREG_SET_ORDER );
		foreach ( $listas as $lista ) {
			Functions\when( 'sanitize_text_field' )->alias( static fn( $v ): string => trim( strip_tags( (string) $v ) ) );
			$valores[ $lista[1] ] = \Akibara\Search\EmptyStateSuggestions::tail_words_key( \Akibara\Search\Stopwords::sanitize_words( html_entity_decode( $lista[2], ENT_QUOTES, 'UTF-8' ) ) );
		}

		return $valores;
	}

	/** @return array<string, string> */
	private function current_values(): array {
		$method = new ReflectionMethod( SearchAdminSection::class, 'settings_current_values' );
		$method->setAccessible( true );

		/** @var array<string, string> $values */
		$values = $method->invoke( new SearchAdminSection() );

		return $values;
	}

	/**
	 * @param array<string, string> $before Antes.
	 * @param array<string, string> $after  Después.
	 * @return array<int, string>
	 */
	private function settings_changes( array $before, array $after ): array {
		$method = new ReflectionMethod( SearchAdminSection::class, 'settings_changes' );
		$method->setAccessible( true );

		/** @var array<int, string> $changes */
		$changes = $method->invoke( new SearchAdminSection(), $before, $after );

		return $changes;
	}

	/**
	 * El ranking «Los más buscados que no tienes» es una lista de COMPRA: una
	 * búsqueda que el corrector de tipeo ya rescata (typo de un título
	 * existente) no puede aparecer ahí como si faltara contenido. El veredicto
	 * se siembra por el transient del rescatador — el mismo camino cacheado que
	 * usa la pantalla real — para no acoplar el test al motor.
	 *
	 * El recorrido probado es el REAL de la pantalla: DemandGaps consulta y
	 * filtra, el renderer pinta lo que le llega.
	 *
	 * @testdox Should list only real catalog gaps, with a prefilled create-product action.
	 */
	public function test_missing_titles_excludes_rescued_typos_and_prefills_the_create_link(): void {
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => (string) $n );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'add_query_arg' )->alias(
			static function ( ...$args ): string {
				// Dos firmas reales: (array, url) y (key, value, url).
				if ( is_array( $args[0] ?? null ) ) {
					return (string) $args[1] . '?' . http_build_query( $args[0] );
				}
				return (string) $args[2] . '?' . rawurlencode( (string) $args[0] ) . '=' . rawurlencode( (string) $args[1] );
			}
		);
		$queries = array( 'bakemonogatari', 'rannma' );
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( $queries ) {
				// Por prefijo y no por clave exacta: desde el 2026-09-19 la clave
				// lleva además la generación del índice (S-6829ad5f), y lo que esta
				// prueba mide es el panel, no cómo se nombra la caché.
				if ( 0 === strpos( $key, 'akb_search_zero_rescue_' ) ) {
					return array(
						'bakemonogatari' => array( 'hits' => 0, 'fix' => '' ),
						'rannma'         => array( 'hits' => 12, 'fix' => 'ranma' ),
					);
				}
				return false;
			}
		);
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://example.test/tienda/' );

		$GLOBALS['wpdb'] = new PanelDemandGapsWpdb(
			array(
				(object) array( 'query_norm' => 'bakemonogatari', 's' => 6, 's30' => 2 ),
				(object) array( 'query_norm' => 'rannma', 's' => 9, 's30' => 3 ),
			)
		);

		$ref = new \ReflectionClass( SearchAdminSection::class );
		$sut = $ref->newInstanceWithoutConstructor();
		$m   = $ref->getMethod( 'render_missing_titles' );
		$m->setAccessible( true );

		ob_start();
		$m->invoke( $sut, \Akibara\Search\DemandGaps::rows( $GLOBALS['wpdb'], 90, 8 ) );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Los más buscados que no tienes', $html );
		self::assertStringContainsString( '<strong>bakemonogatari</strong>', $html );
		self::assertStringNotContainsString( 'rannma', $html, 'Un typo rescatado no es un título que falte.' );
		self::assertStringContainsString( 'post-new.php?post_type=product&post_title=bakemonogatari', $html );
	}

	/**
	 * El guardado recorta los textos del dueño a 300 caracteres. Sin el tope a la
	 * vista, un aviso largo se guardaba con «Cambios guardados» y salía cortado a
	 * media frase (revisión independiente 2026-09-19).
	 *
	 * @testdox Los textos del dueño enseñan su tope mientras se escriben: el mismo con el que se guardan.
	 */
	public function test_los_textos_del_dueno_ensenan_su_tope(): void {
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$ref = new \ReflectionClass( SearchAdminSection::class );
		$m   = $ref->getMethod( 'settings_text' );
		$m->setAccessible( true );

		ob_start();
		$m->invoke( $ref->newInstanceWithoutConstructor(), 'relaxed_notice', 'Aviso', 'Lo que lee el cliente.', 'Hola' );
		$html = (string) ob_get_clean();

		$tope = \Akibara\Search\EmptyStateCopy::MAX_LENGTH;
		self::assertStringContainsString( 'maxlength="' . $tope . '"', $html );
		self::assertStringContainsString( 'Máximo ' . $tope . ' caracteres.', $html );
	}

	/**
	 * El panel pasó de menú top-level a submenú del hub «Akibara» y el guard de
	 * `enqueue_admin` seguía comparando contra `toplevel_page_…`: en producción
	 * el CSS y el JS del panel NO se encolaban nunca. La invariante es que el
	 * guard use el hook que devolvió el propio registro del menú, sea cual sea
	 * la rama.
	 *
	 * @testdox Los assets del panel se encolan en el hook real del menú, como submenú y como top-level.
	 */
	public function test_los_assets_del_panel_se_encolan_en_el_hook_real_del_menu(): void {
		global $admin_page_hooks;

		$cases = array(
			// Con business presente: submenú colgado de «Akibara».
			'akibara_page_akibara-search' => array( 'akibara-panel' => 'akibara' ),
			// Sin business: fallback a top-level.
			'toplevel_page_akibara-search' => array(),
		);

		foreach ( $cases as $expected_hook => $hooks ) {
			$admin_page_hooks = $hooks;

			$enqueued = $this->enqueue_on_hooks( array( $expected_hook, 'edit.php', 'toplevel_page_akibara-panel' ) );

			self::assertSame(
				array( $expected_hook ),
				$enqueued,
				'Los assets del panel se encolan exactamente en la pantalla propia del buscador.'
			);
		}
	}

	/**
	 * @testdox Los handlers del radar sanean DESPUÉS de decodificar, no antes.
	 *
	 * `sanitize_text_field()` corría sobre la cadena todavía percent-encoded, o
	 * sea sobre una cadena que ya no tiene nada que sanear: cualquier carga
	 * peligrosa la reponía el `rawurldecode()` posterior. Lo que persiste es el
	 * valor decodificado, así que el saneo tiene que ser el último paso.
	 */
	public function test_los_handlers_del_radar_sanean_despues_de_decodificar(): void {
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias(
			// Aproximación fiel a lo que importa: core quita etiquetas y
			// caracteres de control de la cadena que recibe.
			static fn( string $v ): string => trim( (string) preg_replace( '/[\x00-\x1F\x7F]|<[^>]*>/', '', $v ) )
		);
		Functions\when( 'wp_get_referer' )->justReturn( 'https://example.test/wp-admin/' );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): void {
				throw new \RuntimeException( 'redirect' );
			}
		);
		$_GET['query'] = '%3Cscript%3Enaruto%3C%2Fscript%3E';

		try {
			( new SearchAdminSection() )->handle_gap_dismiss();
		} catch ( \RuntimeException $expected ) {
			unset( $expected );
		}
		unset( $_GET['query'] );

		$stored = array_keys( \Akibara\Search\GapDismissals::all() );

		self::assertSame( array( 'naruto' ), $stored, 'Se persiste el valor decodificado y saneado.' );
	}

	/**
	 * Registra el menú con los stubs de `add_menu_page`/`add_submenu_page` y
	 * corre `enqueue_admin()` para cada hook, devolviendo aquellos en los que
	 * el plugin encoló algo.
	 *
	 * @param array<int, string> $hooks Hooks de pantalla a probar.
	 * @return array<int, string>
	 */
	private function enqueue_on_hooks( array $hooks ): array {
		global $admin_page_hooks;

		Functions\when( 'add_submenu_page' )->alias(
			static function ( string $parent, string $page_title, string $menu_title, string $cap, string $slug ) use ( $admin_page_hooks ): string {
				return ( $admin_page_hooks[ $parent ] ?? sanitize_title( $parent ) ) . '_page_' . $slug;
			}
		);
		Functions\when( 'add_menu_page' )->alias(
			static fn( string $page_title, string $menu_title, string $cap, string $slug ): string => 'toplevel_page_' . $slug
		);
		Functions\when( 'sanitize_title' )->alias( static fn( string $v ): string => strtolower( $v ) );

		$fired = array();
		$sink  = static function () use ( &$fired ): void {
			$fired[] = true;
		};
		Functions\when( 'wp_enqueue_script' )->alias( $sink );
		Functions\when( 'wp_enqueue_style' )->alias( $sink );

		$section = new SearchAdminSection();
		$section->register_menu();

		$enqueued = array();
		foreach ( $hooks as $hook ) {
			$fired = array();
			$section->enqueue_admin( $hook );
			if ( array() !== $fired ) {
				$enqueued[] = $hook;
			}
		}

		return $enqueued;
	}
}

/** wpdb mínimo: devuelve el ranking del qlog que el caso quiera. */
final class PanelDemandGapsWpdb {

	public string $prefix = 'wp_';

	public string $last_error = '';

	/** @var array<int, object> */
	private array $ranking;

	/** @param array<int, object> $ranking Filas ordenadas por demanda. */
	public function __construct( array $ranking ) {
		$this->ranking = $ranking;
	}

	/**
	 * @param string $query   Sentencia.
	 * @param mixed  ...$args Valores.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		return $query;
	}

	/**
	 * @param string $query Sentencia.
	 * @return array<int, object>
	 */
	public function get_results( string $query ): array {
		return $this->ranking;
	}
}

/**
 * Texto plano de un fragmento HTML (el harness no carga wp-includes).
 *
 * @param string $html Fragmento.
 * @return string
 */
function wp_strip_all_tags_local( string $html ): string {
	return trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $html ) ) );
}
