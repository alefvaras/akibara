<?php
/**
 * Pruebas del marcado del nav de serie de la ficha de producto.
 *
 * ── El defecto medido ────────────────────────────────────────────────────────
 * Desde la ficha, el único enlace a la landing de la serie era una fila de la
 * tabla de especificaciones, abajo del fold y con la misma jerarquía que
 * «Formato». El nav prev/siguiente ya vive bajo el H1 y su celda central sólo
 * tenía un punto decorativo: ese hueco pasa a ser el enlace de alta intención.
 *
 * ── Por qué setUp() declara TODOS los guards ────────────────────────────────
 * Brain Monkey deja el símbolo DEFINIDO tras `tearDown()`. Un test que mockee
 * `get_the_terms()` o `is_wp_error()` en cualquier parte de la suite hace que
 * este archivo reciba el stub vacío en vez del comportamiento esperado: verde en
 * orden natural, rojo con `--order-by=random`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
}

namespace Akibara\Business\Tests\Modules\PdpEnhancer {

use Akibara\Business\Modules\PdpEnhancer\PdpSeriesEnhancer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Term;

defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );

final class PdpSeriesNavRenderTest extends TestCase {

	/** @var mixed */
	private $original_product;

	private bool $had_product = false;

	/** Tomos de la serie, en el orden natural que produce el módulo. */
	private array $series_products = array();

	/** Término `pa_serie` que `get_the_terms()` devuelve, o null. */
	private ?WP_Term $serie_term = null;

	/** Valor que devuelve `get_term_link()`: string o WP_Error. */
	private $term_link = 'https://akibara.cl/serie/one-piece/';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_product      = array_key_exists( 'product', $GLOBALS );
		$this->original_product = $GLOBALS['product'] ?? null;

		$this->serie_term           = new WP_Term( 600, 'one-piece', 'One Piece' );
		$this->serie_term->taxonomy = 'pa_serie';
		$this->term_link            = 'https://akibara.cl/serie/one-piece/';
		$this->series_products      = array(
			101 => array( 'title' => 'One Piece Tomo 1', 'variant' => false ),
			102 => array( 'title' => 'One Piece Tomo 2', 'variant' => false ),
			103 => array( 'title' => 'One Piece Tomo 3', 'variant' => false ),
		);

		// El enlace a la serie lee el estado de la edición desde termmeta
		// (`PublicationStatus::scoped_for_term`). Por defecto SIN dato: es el
		// caso mayoritario en producción —sólo 67 de las series lo tienen— y
		// deja intacta la geometría que estas pruebas fijan. Los casos con
		// estado viven en PdpSeriesEstadoTest.
		//
		// Sin este doble la suite entera erraba aquí y en solitario NO: el guard
		// `function_exists( 'get_term_meta' )` de scoped_for_term() da true por
		// contagio de otra prueba que sí la define.
		Functions\when( 'get_term_meta' )->justReturn( '' );

		// Guards y escapes. Defaults deterministas del caso normal: una ficha en
		// medio de una serie de tres tomos con landing enlazable.
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ): bool => $thing instanceof \WP_Error
		);
		Functions\when( 'get_the_terms' )->alias(
			fn(): array => null === $this->serie_term ? array() : array( $this->serie_term )
		);
		Functions\when( 'get_term_link' )->alias(
			fn() => $this->term_link
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( int $id ): string => 'https://akibara.cl/producto/tomo-' . $id . '/'
		);

		// La lista de tomos se sirve del object cache: el render nunca debe
		// tocar la base en estas pruebas.
		Functions\when( 'wp_cache_get' )->alias(
			fn() => $this->series_products
		);
		Functions\when( 'wp_cache_set' )->justReturn( true );

		// El texto del enlace a la serie es un copy del panel (Textos). Por
		// defecto sin option guardada: sale el de fábrica.
		$GLOBALS['akibara_test_options'] = array();
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default = false ) => $GLOBALS['akibara_test_options'][ $option ] ?? $default
		);
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ): string => strip_tags( (string) $text ) );
	}

	protected function tearDown(): void {
		if ( $this->had_product ) {
			$GLOBALS['product'] = $this->original_product;
		} else {
			unset( $GLOBALS['product'] );
		}
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Texto de la etiqueta del enlace a la serie, sin marcado. */
	private static function label_text( string $html ): string {
		if ( ! preg_match( '#<span class="akb-series-nav__series-label">(.*?)</span>\s*(?:<span class="akb-series-nav__series-estado"|</a>)#s', $html, $m ) ) {
			return '';
		}

		return trim( strip_tags( $m[1] ) );
	}

	private function render( int $product_id = 102 ): string {
		$product            = new \WC_Product();
		$product->akb_id    = $product_id;
		$GLOBALS['product'] = $product;

		ob_start();
		( new PdpSeriesEnhancer() )->render_series_nav();

		return (string) ob_get_clean();
	}

	/**
	 * @testdox Should hook the nav between the title and the rating.
	 */
	public function test_hooks_between_title_and_rating(): void {
		$module = new PdpSeriesEnhancer();

		// Prio 6: el título de WooCommerce va en 5 y el rating propio en 7. El
		// enlace a la serie tiene que leerse DESPUÉS de saber en qué producto
		// se está parado, y antes del precio (10).
		Monkey\Actions\expectAdded( 'woocommerce_single_product_summary' )
			->once()
			->with( array( $module, 'render_series_nav' ), 6 );

		$module->register();
		self::addToAssertionCount( 1 );
	}

	/**
	 * La cadena de lectura la forman los tomos normales. Sin este corte, desde
	 * el tomo 23 el botón «Siguiente» llevaba a otro producto del MISMO número
	 * y el `aria-label` lo anunciaba como «Tomo siguiente».
	 *
	 * @testdox Desde el tomo normal, la variante del mismo número no es «Siguiente».
	 */
	public function test_la_variante_del_mismo_numero_no_es_el_siguiente(): void {
		$this->series_products = array(
			101 => array( 'title' => 'Demon Slayer 22', 'variant' => false ),
			102 => array( 'title' => 'Demon Slayer 23', 'variant' => false ),
			103 => array( 'title' => 'Demon Slayer 23 (Edición Especial)', 'variant' => true ),
		);

		$html = $this->render( 102 );

		self::assertStringContainsString( 'Tomo anterior: Demon Slayer 22', $html );
		self::assertStringNotContainsString( 'Tomo siguiente', $html );
		self::assertStringNotContainsString( 'tomo-103', $html );
	}

	/**
	 * @testdox Desde la variante, el anterior es el tomo 22 y no su gemelo.
	 */
	public function test_desde_la_variante_el_anterior_es_el_tomo_previo(): void {
		$this->series_products = array(
			101 => array( 'title' => 'Demon Slayer 22', 'variant' => false ),
			102 => array( 'title' => 'Demon Slayer 23', 'variant' => false ),
			103 => array( 'title' => 'Demon Slayer 23 (Edición Especial)', 'variant' => true ),
		);

		$html = $this->render( 103 );

		self::assertStringContainsString( 'Tomo anterior: Demon Slayer 22', $html );
		self::assertStringNotContainsString( 'Tomo siguiente', $html );
	}

	/**
	 * @testdox Desde la variante, el siguiente es el tomo posterior al suyo.
	 */
	public function test_desde_la_variante_el_siguiente_salta_a_su_tomo_posterior(): void {
		$this->series_products = array(
			101 => array( 'title' => 'Demon Slayer 22', 'variant' => false ),
			102 => array( 'title' => 'Demon Slayer 23', 'variant' => false ),
			103 => array( 'title' => 'Demon Slayer 23 (Edición Especial)', 'variant' => true ),
			104 => array( 'title' => 'Demon Slayer 24', 'variant' => false ),
		);

		$html = $this->render( 103 );

		self::assertStringContainsString( 'Tomo anterior: Demon Slayer 22', $html );
		self::assertStringContainsString( 'Tomo siguiente: Demon Slayer 24', $html );
	}

	/**
	 * Marcar como variante un tomo que es el ÚNICO de su número —el caso
	 * «Maximum Berserk 21 (Portada alternativa)»— es un error del dueño, no del
	 * código. La ficha no se queda sin salida por eso: cae a su posición
	 * natural, que es el comportamiento de antes de la marca.
	 *
	 * @testdox Una variante sin gemelo conserva su navegación natural.
	 */
	public function test_una_variante_sin_gemelo_conserva_su_navegacion(): void {
		$this->series_products = array(
			101 => array( 'title' => 'Maximum Berserk 20', 'variant' => false ),
			102 => array( 'title' => 'Maximum Berserk 21 (Portada alternativa)', 'variant' => true ),
			103 => array( 'title' => 'Maximum Berserk 22', 'variant' => false ),
		);

		$html = $this->render( 102 );

		self::assertStringContainsString( 'Tomo anterior: Maximum Berserk 20', $html );
		self::assertStringContainsString( 'Tomo siguiente: Maximum Berserk 22', $html );
	}

	/**
	 * @testdox [REG] Sin variantes, la navegación es exactamente la de siempre.
	 */
	public function test_sin_variantes_la_navegacion_no_cambia(): void {
		$html = $this->render( 102 );

		self::assertStringContainsString( 'Tomo anterior: One Piece Tomo 1', $html );
		self::assertStringContainsString( 'Tomo siguiente: One Piece Tomo 3', $html );
	}

	/**
	 * @testdox Should add the series link as the third, central segment.
	 */
	public function test_adds_the_series_link_as_the_central_segment(): void {
		$html = $this->render();

		self::assertStringContainsString(
			'<a
						href="https://akibara.cl/serie/one-piece/"
						class="akb-series-nav__link akb-series-nav__link--series"',
			$html
		);
		// Carril SEO 2026-09-26: el nombre de la serie va en el texto VISIBLE
		// —el ancla que Google lee—, no en un `aria-label` que además hacía que
		// el nombre accesible no contuviera el visible (WCAG 2.5.3).
		self::assertStringContainsString( '<span class="akb-series-nav__series-label">Ver los tomos de <span class="akb-series-nav__series-name">One Piece</span></span>', $html );
		self::assertDoesNotMatchRegularExpression( '/akb-series-nav__link--series[^>]*aria-label/s', $html );
		self::assertStringNotContainsString( 'Ver tomos de esta edición', $html );

		// Y el segmento nuevo va ENTRE anterior y siguiente, no al final.
		$prev   = strpos( $html, 'akb-series-nav__link--prev' );
		$series = strpos( $html, 'akb-series-nav__link--series' );
		$next   = strpos( $html, 'akb-series-nav__link--next' );
		self::assertIsInt( $prev );
		self::assertIsInt( $series );
		self::assertIsInt( $next );
		self::assertGreaterThan( $prev, $series );
		self::assertGreaterThan( $series, $next );
	}

	/**
	 * ── Decisión: el guard `count() <= 1` ya no cierra el nav ────────────────
	 * 10 series tienen un solo producto. No tienen tomo anterior ni siguiente,
	 * pero SÍ tienen landing — y es justo en esas fichas, las que menos contexto
	 * ofrecen, donde la salida hacia el resto del catálogo vale más.
	 *
	 * @testdox Should still offer the series link on a one-volume series.
	 */
	public function test_still_offers_the_series_link_on_a_one_volume_series(): void {
		$this->series_products = array( 101 => 'Nier Automata Tomo único' );

		$html = $this->render( 101 );

		self::assertStringContainsString( 'akb-series-nav__link--series', $html );
		self::assertStringContainsString( 'https://akibara.cl/serie/one-piece/', $html );
		self::assertStringNotContainsString( 'akb-series-nav__link--prev', $html );
		self::assertStringNotContainsString( 'akb-series-nav__link--next', $html );

		// Sin vecinos, la fila de arriba queda vacía en móvil: el modificador es
		// lo que evita que el filete separador flote sobre nada.
		self::assertStringContainsString( 'akb-series-nav__link--series-alone', $html );
	}

	/**
	 * @testdox Should not mark the series link as alone when it has neighbours.
	 */
	public function test_does_not_mark_the_series_link_as_alone_with_neighbours(): void {
		self::assertStringNotContainsString( 'akb-series-nav__link--series-alone', $this->render() );
		// Y tampoco con un solo vecino: ahí la fila de arriba sí tiene contenido.
		self::assertStringNotContainsString( 'akb-series-nav__link--series-alone', $this->render( 101 ) );
	}

	/**
	 * El layout es un grid de tres columnas. Un hueco omitido correría
	 * «siguiente» a la celda del centro y dejaría la flecha derecha en mitad de
	 * la barra.
	 *
	 * @testdox Should always emit three cells, even when two of them are empty.
	 */
	public function test_always_emits_three_cells(): void {
		$this->series_products = array( 101 => 'Nier Automata Tomo único' );

		$html = $this->render( 101 );

		self::assertSame(
			2,
			substr_count( $html, 'akb-series-nav__empty' ) - substr_count( $html, 'akb-series-nav__empty--center' ),
			'Los laterales vacíos siguen ocupando su celda.'
		);
		self::assertStringNotContainsString( '__empty--center', $html, 'El centro lo ocupa el enlace de serie.' );
	}

	/**
	 * @testdox Should keep the decorative separator when the series link cannot be built.
	 */
	public function test_falls_back_to_the_separator_without_a_usable_link(): void {
		$this->term_link = new \WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );

		$html = $this->render();

		self::assertStringNotContainsString( 'akb-series-nav__link--series', $html );
		self::assertStringContainsString( 'akb-series-nav__separator', $html );
		// Prev/siguiente siguen funcionando: la barra vuelve a su forma anterior.
		self::assertStringContainsString( 'akb-series-nav__link--prev', $html );
		self::assertStringContainsString( 'akb-series-nav__link--next', $html );
	}

	/**
	 * @testdox Should emit the central placeholder when there is neither a link nor both neighbours.
	 */
	public function test_emits_the_central_placeholder_when_nothing_fits_the_centre(): void {
		$this->term_link = new \WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );

		// Primer tomo: hay siguiente pero no anterior, así que el separador
		// tampoco corresponde. La celda central igual tiene que existir.
		$html = $this->render( 101 );

		self::assertStringContainsString( 'akb-series-nav__empty--center', $html );
		self::assertSame( 1, substr_count( $html, 'akb-series-nav__empty--center' ) );
	}

	/**
	 * @testdox Should print nothing at all when the product has no series.
	 */
	public function test_prints_nothing_without_a_series(): void {
		$this->serie_term      = null;
		$this->series_products = array();

		self::assertSame( '', $this->render() );
	}

	/**
	 * ── Decisión: el enlace NO anuncia un número de tomos ────────────────────
	 * El conteo honesto lo produce la agregación de `SeriesFacts`, que excluye
	 * los productos `exclude-from-catalog`; esta lista es un `WP_Query` que no
	 * los excluye. Un número puesto acá puede contradecir a la landing a la que
	 * el propio enlace lleva, y un número que se desmiente a un click de
	 * distancia es peor que ningún número.
	 *
	 * @testdox Should not announce a volume count it cannot derive from the same aggregate.
	 */
	public function test_does_not_announce_a_volume_count(): void {
		// Desde 2026-09-26 la etiqueta lleva el nombre de la serie, que puede
		// traer cifras propias («Ranma 1/2», «Kaiju No. 8»): se busca la forma
		// de un conteo, no cualquier dígito.
		foreach ( array( 'One Piece', 'Kaiju No. 8', 'Ranma 1/2' ) as $nombre ) {
			$this->serie_term->name = $nombre;
			$html                   = $this->render();

			$etiqueta = self::label_text( $html );
			self::assertSame( 'Ver los tomos de ' . $nombre, $etiqueta );
			self::assertDoesNotMatchRegularExpression(
				'/\d+\s*tomos?\b/iu',
				(string) preg_replace( '/' . preg_quote( $nombre, '/' ) . '/u', '', $etiqueta ),
				'El número tendría que salir del agregado de SeriesFacts, no de esta lista.'
			);
		}
	}

	/**
	 * ── Variante móvil ──────────────────────────────────────────────────────
	 * Prev/siguiente cambian el título del tomo por «Anterior»/«Siguiente» en
	 * ≤768px: cambian una palabra específica por una genérica porque no entra.
	 * Heredar ese patrón acá daría un enlace que en móvil diría «La serie», que
	 * no le dice nada a nadie. Acá el texto es el MISMO en las dos anchuras y lo
	 * que cambia es el layout: el CSS baja el enlace a una fila propia a todo el
	 * ancho, donde el texto completo cabe.
	 *
	 * @testdox Should keep one single label for the series link at every breakpoint.
	 */
	public function test_keeps_one_label_at_every_breakpoint(): void {
		$html = $this->render();

		self::assertSame( 1, substr_count( $html, 'akb-series-nav__series-label' ) );
		self::assertStringNotContainsString( 'akb-series-nav__series-desktop', $html );
		self::assertStringNotContainsString( 'akb-series-nav__series-mobile', $html );

		// Y el swap de prev/siguiente sigue intacto: esto añade un segmento, no
		// reescribe los que ya funcionaban.
		self::assertStringContainsString( 'akb-series-nav__title-desktop', $html );
		self::assertStringContainsString( 'akb-series-nav__title-mobile', $html );
	}

	/**
	 * El CSS es la otra mitad de la decisión: sin la regla de la segunda fila el
	 * enlace se metería donde prev y siguiente ya ocupan casi todo el ancho.
	 *
	 * Y el corte va en 1024px, no en 768px, por una razón MEDIDA: lo que
	 * restringe esta barra no es el viewport sino la columna de summary de la
	 * ficha, que a 800px de viewport mide 362px. Con tres celdas ahí la central
	 * se lleva 150px fijos y los títulos de tomo se recortan a «ONE PI…», que es
	 * perder el número de tomo — lo único que esos dos enlaces comunican. Cubre
	 * iPad Air (820), iPad Pro 11" (834) y Surface (912).
	 *
	 * @testdox The stylesheet must give the series link its own row from tablet down.
	 */
	public function test_stylesheet_gives_the_series_link_its_own_row_from_tablet_down(): void {
		$css = (string) file_get_contents( AKIBARA_BUSINESS_DIR . 'assets/css/pdp-series-nav.css' );

		$dos_filas = strstr( $css, '@media (max-width: 1024px)' );
		self::assertIsString(
			$dos_filas,
			'El corte de dos filas vive en 1024px: en 768px la banda de tablets se rompe.'
		);

		self::assertMatchesRegularExpression(
			'/\.akb-series-nav__link--series\s*\{[^}]*grid-column:\s*1\s*\/\s*-1/s',
			$dos_filas,
			'El enlace ocupa el ancho completo.'
		);
		self::assertMatchesRegularExpression(
			'/\.akb-series-nav__link--series\s*\{[^}]*grid-row:\s*2/s',
			$dos_filas,
			'…y en su propia fila, no compitiendo con prev/siguiente.'
		);
		self::assertStringContainsString( 'grid-template-columns: 1fr fit-content(44%) 1fr;', $css );
		self::assertStringContainsString( 'grid-template-columns: 1fr 1fr;', $dos_filas );
	}

	/**
	 * Desde que el enlace dice el nombre de la serie, un nombre largo no puede
	 * llevarse la barra (con `auto`, prev y siguiente caían a «ONE PI…») ni
	 * recortarse con «…»: parte línea dentro de una celda con tope.
	 *
	 * @testdox The stylesheet caps the central cell and lets the series link wrap instead of clipping.
	 */
	public function test_stylesheet_caps_the_centre_and_lets_the_series_name_wrap(): void {
		$css = (string) file_get_contents( AKIBARA_BUSINESS_DIR . 'assets/css/pdp-series-nav.css' );

		self::assertDoesNotMatchRegularExpression( '/grid-template-columns:\s*1fr auto 1fr/', $css, 'Sin tope, el nombre largo se come a prev y siguiente.' );
		self::assertMatchesRegularExpression(
			'/\.akb-series-nav__link--series\s*\{[^}]*white-space:\s*normal/s',
			(string) strstr( $css, '@media', true ),
			'Regla base (todos los anchos): el enlace de serie parte línea.'
		);
	}

	/**
	 * En las series de un solo tomo no hay anterior ni siguiente: con el tope
	 * de la celda central, un nombre largo partía en 2-3 líneas con la barra
	 * libre. El enlace ocupa la fila entera también en escritorio.
	 *
	 * @testdox The lone series link spans the whole bar at every width.
	 */
	public function test_stylesheet_lets_a_lone_series_link_span_the_bar(): void {
		$css  = (string) file_get_contents( AKIBARA_BUSINESS_DIR . 'assets/css/pdp-series-nav.css' );
		$base = (string) strstr( $css, '@media', true );

		self::assertMatchesRegularExpression( '/\.akb-series-nav__link--series-alone\s*\{[^}]*grid-column:\s*1\s*\/\s*-1/s', $base );
	}

	/**
	 * Dos medidas del espejo (2026-09-27): el nombre partía en su guion
	 * («KAGUYA- / SAMA») y, en móvil, el peldaño de serie de la miga partía
	 * línea y su zona táctil quedaba en 18 px (AI-7 pide ≥24). Las dos se
	 * arreglan con bloques en línea, en la hoja que sólo se carga en fichas.
	 *
	 * @testdox The series name and each breadcrumb link are inline blocks, so they wrap whole.
	 */
	public function test_stylesheet_keeps_the_series_name_and_breadcrumb_links_whole(): void {
		$css  = (string) file_get_contents( AKIBARA_BUSINESS_DIR . 'assets/css/pdp-series-nav.css' );
		$base = (string) strstr( $css, '@media', true );

		self::assertMatchesRegularExpression( '/\.akb-series-nav__series-name\s*\{[^}]*display:\s*inline-block/s', $base );
		self::assertMatchesRegularExpression( '/\.akb-series-nav__series-name\s*\{[^}]*text-decoration:\s*underline/s', $base, 'El subrayado del enlace no se propaga a un bloque en línea.' );
		self::assertMatchesRegularExpression( '/\.woocommerce-breadcrumb a\s*\{[^}]*display:\s*inline-block/s', $base );
	}

	/**
	 * @testdox The owner's copy with a percent sign renders verbatim: it never goes through sprintf.
	 */
	public function test_un_copy_del_duenno_con_porcentaje_no_rompe(): void {
		$GLOBALS['akibara_test_options'][ PdpSeriesEnhancer::OPTION_SERIES_LINK ] = '100% {serie}';

		self::assertSame( '100% One Piece', self::label_text( $this->render() ) );
	}

	/**
	 * @testdox A series name with an ampersand is escaped exactly once.
	 */
	public function test_el_nombre_con_ampersand_sale_escapado_una_vez(): void {
		Functions\when( 'esc_html' )->alias( static fn( $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false ) );
		$this->serie_term->name = 'Tom & Jerry <b>';

		$html = $this->render();

		self::assertStringContainsString( 'series-label">Ver los tomos de <span class="akb-series-nav__series-name">Tom &amp; Jerry &lt;b&gt;</span></span>', $html );
		self::assertStringNotContainsString( '&amp;amp;', $html );
	}

	/**
	 * @testdox A copy without the {serie} marker renders as written.
	 */
	public function test_un_copy_sin_marcador_sale_tal_cual(): void {
		$GLOBALS['akibara_test_options'][ PdpSeriesEnhancer::OPTION_SERIES_LINK ] = 'Toda la colección';

		$html = $this->render();
		self::assertStringContainsString( 'series-label">Toda la colección</span>', $html );
		self::assertStringNotContainsString( 'akb-series-nav__series-name', $html, 'Sin marcador no hay nombre que envolver.' );
	}

	/**
	 * @testdox An empty saved copy falls back to the factory text.
	 */
	public function test_un_copy_vacio_vuelve_al_de_fabrica(): void {
		$GLOBALS['akibara_test_options'][ PdpSeriesEnhancer::OPTION_SERIES_LINK ] = '   ';

		self::assertSame( 'Ver los tomos de One Piece', self::label_text( $this->render() ) );
	}

	/**
	 * @testdox A one-shot series uses singular 'Ver la edición de' instead of plural 'Ver los tomos de'.
	 */
	public function test_usa_singular_en_tomo_unico_cuando_el_copy_es_el_de_fabrica(): void {
		self::assertStringContainsString(
			'Ver la edición de <span class="akb-series-nav__series-name">Uzumaki</span>',
			PdpSeriesEnhancer::series_link_label( 'Uzumaki', true )
		);
	}

	/**
	 * La degradación del título a la etiqueta corta tiene que vivir en SU PROPIO
	 * corte, por debajo del de dos filas. Si viajara junto, entre 769 y 1024px la
	 * barra diría «ANTERIOR / SIGUIENTE» teniendo espacio de sobra para el número
	 * de tomo — tirando el dato útil sin ninguna necesidad.
	 *
	 * @testdox The short mobile label must live in its own, narrower breakpoint.
	 */
	public function test_the_short_label_lives_in_its_own_breakpoint(): void {
		$css = (string) file_get_contents( AKIBARA_BUSINESS_DIR . 'assets/css/pdp-series-nav.css' );

		$movil = (string) strstr( $css, '@media (max-width: 768px)' );
		self::assertNotSame( '', $movil, 'Sigue existiendo un corte de móvil.' );

		// El corte de móvil es el ÚNICO que puede intercambiar los dos títulos.
		self::assertStringContainsString( '.akb-series-nav__title-mobile', $movil );
		self::assertStringContainsString( '.akb-series-nav__title-desktop', $movil );

		// Y el bloque de 1024 —el tramo entre los dos @media— no los toca. Ojo:
		// la regla BASE `__title-mobile { display: none }` vive antes de los
		// breakpoints y es correcta; lo que no puede pasar es que el corte de
		// tablets la active.
		$bloque_1024 = (string) strstr( strstr( $css, '@media (max-width: 1024px)' ), '@media (max-width: 768px)', true );
		self::assertNotSame( '', $bloque_1024 );
		self::assertStringNotContainsString( '__title-desktop', $bloque_1024 );
		self::assertStringNotContainsString( '__title-mobile', $bloque_1024 );
	}
}
}
