<?php
/**
 * Tests de la píldora de ETA en la card del catálogo (release 1.99.2).
 *
 * Fija los contratos que `BannerRenderer::render_loop_item()` introduce en el
 * "slot de estado" de la card:
 *
 *   1) El label del rango de fechas es el NOMBRE DEL CAMPO ('Llegada'), no un
 *      estado de stock ('Preventa') ni la versión larga que envolvía a dos
 *      líneas ('Llegada estimada').
 *   2) La rama TBA sale SIN label, porque su valor ya es una oración completa
 *      ("Llega ~2 semanas tras su salida en Argentina") y con label se leería
 *      "LLEGADA: Llega ~2 semanas…".
 *   3) La píldora se registra en prioridad 12, ESTRICTAMENTE después del hint de
 *      disponibilidad (11), porque el colapso del placeholder en loop.css usa
 *      `.akb-avail-hint--empty:has(~ .akb-eta-loop)` — hermano POSTERIOR.
 *   4) La hoja de la píldora se encola en todas las superficies donde el theme
 *      carga loop.css, incluidos carrito, /mi-cuenta/ y 404.
 *
 * El ETA no se inyecta: se construye con el `DateCalculator` real (es `final`,
 * no admite doble) alimentado por un clock fijo y por los stubs de la capa de
 * datos de WordPress. Cada prueba afirma primero QUÉ forma de `EtaResult`
 * produjo esa fixture, así una prueba nunca puede quedar verde ejercitando la
 * rama equivocada.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\BannerRenderer;
use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use Akibara\Business\Modules\EstimatedDelivery\Settings;
use Akibara\Business\Modules\LoopEnhancer\LoopEnhancer;
use Akibara\Business\Modules\Stock\StockStatus;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'AKIBARA_BUSINESS_URL' ) || define( 'AKIBARA_BUSINESS_URL', 'https://example.test/wp-content/plugins/akibara-business/' );
defined( 'AKIBARA_BUSINESS_VERSION' ) || define( 'AKIBARA_BUSINESS_VERSION', 'test-version' );

final class BannerRendererLoopTest extends TestCase {

	/**
	 * Timezone del sitio. La de producción a propósito: el cálculo de la ETA
	 * ancla a medianoche local y `format_range()` lee día/mes en la misma tz.
	 */
	private const SITE_TIMEZONE = 'America/Santiago';

	/**
	 * Hook único donde conviven el hint de disponibilidad y esta píldora.
	 */
	private const LOOP_HOOK = 'woocommerce_after_shop_loop_item_title';

	/**
	 * Término pa_pais de la fixture: Argentina, 12–15 días (1.118 productos en
	 * producción usan este mapeo).
	 */
	private const LEAD_MIN_DAYS = 12;
	private const LEAD_MAX_DAYS = 15;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value, ...$args ) => $value
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		// El renderer resuelve el año y la tz del sitio por acá; sin fijarla, el
		// día impreso dependería de la tz del runtime del contenedor.
		Functions\when( 'wp_timezone' )->alias(
			static fn(): \DateTimeZone => new \DateTimeZone( self::SITE_TIMEZONE )
		);
		// Ninguna option está seteada: cada call site recibe su propio default,
		// que es el copy canónico del módulo.
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => $default_value
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['product'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Producto en preventa (`onbackorder`) — el único estado para el que
	 * `DateCalculator::calculate()` devuelve algo distinto de null.
	 */
	private function preventa_product(): \WC_Product {
		return new class( StockStatus::ON_BACKORDER ) extends \WC_Product {
			public function get_id(): int {
				return 4242;
			}
		};
	}

	/**
	 * Stubs de la capa de datos que lee el calculator.
	 *
	 * @param string      $eta_type   Valor de `_akb_eta_type` ('' → tipo auto).
	 * @param string|null $pais_slug  Slug del término pa_pais del producto, o null
	 *                                para simular un producto sin país mapeado
	 *                                (que es lo que produce un TBA sin lead time).
	 */
	private function stub_catalog_data( string $eta_type, ?string $pais_slug ): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key, bool $single = true ) use ( $eta_type ) {
				unset( $post_id, $single );

				return DateCalculator::META_TYPE === $key ? $eta_type : '';
			}
		);
		Functions\when( 'get_the_terms' )->alias(
			static function ( int $post_id, string $taxonomy ) use ( $pais_slug ) {
				unset( $post_id );

				if ( 'pa_pais' !== $taxonomy || null === $pais_slug ) {
					return false;
				}

				return array( (object) array( 'term_id' => 7, 'slug' => $pais_slug ) );
			}
		);
		Functions\when( 'get_term_by' )->alias(
			static function ( string $field, $value, string $taxonomy ) {
				unset( $field );

				return 'pa_pais' === $taxonomy && 'argentina' === $value
					? (object) array(
						'term_id' => 7,
						'name'    => 'Argentina',
					)
					: false;
			}
		);
		Functions\when( 'get_term_meta' )->alias(
			static function ( int $term_id, string $key, bool $single = true ) {
				unset( $term_id, $single );

				if ( Settings::TERM_META_MIN_DAYS === $key ) {
					return self::LEAD_MIN_DAYS;
				}

				return Settings::TERM_META_MAX_DAYS === $key ? self::LEAD_MAX_DAYS : '';
			}
		);
	}

	/**
	 * Calculator con clock fijo: lunes 1 jun 2026, 15:40 CL. Con lead 12–15 días
	 * hábiles el rango cae en mié 17 jun – lun 22 jun 2026.
	 */
	private function calculator(): DateCalculator {
		$timezone = new \DateTimeZone( self::SITE_TIMEZONE );

		return new DateCalculator(
			new Settings(),
			static fn(): int => ( new \DateTimeImmutable( '2026-06-01 15:40:00', $timezone ) )->getTimestamp(),
			$timezone
		);
	}

	/**
	 * Captura lo que la píldora emite para el producto en scope.
	 */
	private function render_pill( BannerRenderer $renderer, \WC_Product $product ): string {
		$GLOBALS['product'] = $product;

		ob_start();
		$renderer->render_loop_item();

		return (string) ob_get_clean();
	}

	/**
	 * Rama de RANGO DE FECHAS: el label es el nombre del campo, no un estado.
	 *
	 * Se afirma el markup COMPLETO (no un `contains` del label): así reintroducir
	 * 'Llegada estimada' —que a 1280px envolvía a dos líneas y dejaba el precio de
	 * las cards de preventa 20,9px más arriba que el de instock en la misma fila—
	 * pone esta prueba en rojo. El año queda opcional en el patrón porque
	 * `format_range()` solo lo imprime cuando el rango no cae en el año en curso,
	 * y "el año en curso" se lee del reloj real, no del inyectado.
	 *
	 * @testdox Should label a concrete date range "Llegada", never "Llegada estimada" nor "Preventa".
	 */
	public function test_date_range_pill_labels_the_field_llegada(): void {
		$this->stub_catalog_data( '', 'argentina' );
		$calculator = $this->calculator();
		$product    = $this->preventa_product();

		$eta = $calculator->calculate( $product );

		self::assertInstanceOf( EtaResult::class, $eta );
		self::assertFalse(
			$eta->is_tba(),
			'Fixture inválida: esta prueba cubre la rama de rango de fechas, no la TBA.'
		);

		$html = $this->render_pill( new BannerRenderer( $calculator ), $product );

		self::assertMatchesRegularExpression(
			'~^<p class="akb-eta-loop"><span class="akb-eta-loop__label">Llegada:</span> 17 – 22 jun( 2026)?</p>$~u',
			$html
		);
		self::assertStringNotContainsString(
			'Llegada estimada',
			$html,
			'El label se acortó a "Llegada" por una razón medida: "LLEGADA ESTIMADA:" + la '
			. 'fecha envolvían a 2 líneas en la grilla de 4 columnas y desalineaban el precio.'
		);
		self::assertStringNotContainsString(
			StockStatus::LABELS[ StockStatus::ON_BACKORDER ],
			$html,
			'"Preventa" es vocabulario de estado de stock (fuente única StockStatus) y ya lo '
			. 'dicen el badge y el CTA de la misma card: acá el label nombra un CAMPO.'
		);
	}

	/**
	 * Rama TBA (las dos formas): sin label, porque el valor ya es una oración.
	 *
	 * @testdox Should render a TBA pill with no label at all, so the copy never stutters "Llegada: Llega…".
	 *
	 * @dataProvider tba_forms
	 *
	 * @param string|null $pais_slug      Slug pa_pais del producto (null → sin país).
	 * @param bool        $expect_lead    Forma esperada de TBA (con o sin lead time).
	 * @param string      $expected_value Valor que debe quedar dentro del `<p>`.
	 */
	public function test_tba_pill_renders_without_a_label( ?string $pais_slug, bool $expect_lead, string $expected_value ): void {
		$this->stub_catalog_data( 'tba', $pais_slug );
		$calculator = $this->calculator();
		$product    = $this->preventa_product();

		$eta = $calculator->calculate( $product );

		self::assertInstanceOf( EtaResult::class, $eta );
		self::assertTrue( $eta->is_tba(), 'Fixture inválida: esta prueba cubre la rama TBA.' );
		self::assertSame(
			$expect_lead,
			$eta->has_tba_lead(),
			'Fixture inválida: la forma de TBA producida no es la que este caso declara cubrir.'
		);

		$html = $this->render_pill( new BannerRenderer( $calculator ), $product );

		self::assertSame( sprintf( '<p class="akb-eta-loop">%s</p>', $expected_value ), $html );
		self::assertStringNotContainsString(
			'akb-eta-loop__label',
			$html,
			'En TBA el valor es una oración completa: el label solo tiene sentido delante de '
			. 'un dato pelado, o sea delante del rango de fechas.'
		);
		self::assertDoesNotMatchRegularExpression(
			'~Llegada:\s*(Llega|Te avisamos)~u',
			$html,
			'Este es exactamente el tartamudeo que 1.99.2 arregla ("LLEGADA: Llega ~4 semanas…").'
		);
	}

	/**
	 * Las dos formas de TBA que `DateCalculator` puede producir para una card:
	 * sin país mapeado (`EtaResult::tba()`) y con lead time del país resuelto
	 * (`EtaResult::tba_with_lead()`, 12–15 días → "~2 semanas").
	 *
	 * @return array<string, array{string|null, bool, string}>
	 */
	public function tba_forms(): array {
		return array(
			'EtaResult::tba() — producto sin pa_pais, sin lead time' => array(
				null,
				false,
				'Te avisamos en cuanto tengamos fecha',
			),
			'EtaResult::tba_with_lead() — lead time del país resuelto' => array(
				'argentina',
				true,
				'Llega ~2 semanas tras su salida en Argentina',
			),
		);
	}

	/**
	 * Contrato de ORDEN, mitad "registro".
	 *
	 * Se lee la prioridad REALMENTE registrada (`Actions\has()` devuelve el entero
	 * almacenado, no un booleano contra un valor adivinado) y se compara una contra
	 * la otra: si alguien las vuelve a empatar en 11 —el estado de 1.99.1— la
	 * comparación estricta cae, incluso si los dos números cambiaran a la vez.
	 *
	 * @testdox Should register the loop pill strictly after the availability hint on the shared loop hook.
	 */
	public function test_loop_pill_registers_strictly_after_the_availability_hint(): void {
		$enhancer = new LoopEnhancer();
		$renderer = new BannerRenderer( $this->calculator() );

		$enhancer->register();
		$renderer->register();

		$hint_priority = Actions\has( self::LOOP_HOOK, array( $enhancer, 'render_availability_hint_loop' ) );
		$pill_priority = Actions\has( self::LOOP_HOOK, array( $renderer, 'render_loop_item' ) );

		self::assertSame( 11, $hint_priority, 'El hint va en 11: después del precio, que WC rinde en 10.' );
		self::assertSame( 12, $pill_priority, 'La píldora de ETA va en 12, no empatada con el hint.' );
		self::assertGreaterThan(
			$hint_priority,
			$pill_priority,
			'Empatadas, el orden del DOM lo decide el orden de instanciación de módulos en '
			. 'Plugin.php y no un contrato: reordenarlos apagaría el colapso del placeholder '
			. 'en silencio (sin error, sin test rojo, solo la grilla desalineada).'
		);
	}

	/**
	 * Contrato de ORDEN, mitad "salida": se disparan los dos callbacks en el orden
	 * que dictan sus prioridades registradas y se mira el DOM resultante.
	 *
	 * Es la mitad que le importa al CSS: loop.css colapsa el hueco del hint con
	 * `.akb-avail-hint--empty:has(~ .akb-eta-loop)`, y `~` solo alcanza hermanos
	 * POSTERIORES. Registrar la píldora primero es deliberado: si las prioridades
	 * volvieran a empatar, el orden de inserción mandaría y este test lo vería.
	 *
	 * @testdox Should emit the hint placeholder before the delivery pill when the hook fires in registered order.
	 */
	public function test_registered_priorities_emit_the_hint_before_the_pill(): void {
		$this->stub_catalog_data( 'tba', null );

		$renderer = new BannerRenderer( $this->calculator() );
		$enhancer = new LoopEnhancer();

		$renderer->register();
		$enhancer->register();

		$hint_priority = Actions\has( self::LOOP_HOOK, array( $enhancer, 'render_availability_hint_loop' ) );
		$pill_priority = Actions\has( self::LOOP_HOOK, array( $renderer, 'render_loop_item' ) );

		self::assertIsInt( $hint_priority, 'El hint debe estar registrado en este hook.' );
		self::assertIsInt( $pill_priority, 'La píldora debe estar registrada en este hook.' );
		self::assertNotSame(
			$hint_priority,
			$pill_priority,
			'Empatadas, WordPress ordena por inserción y el DOM deja de ser un contrato.'
		);

		$callbacks = array(
			$hint_priority => array( $enhancer, 'render_availability_hint_loop' ),
			$pill_priority => array( $renderer, 'render_loop_item' ),
		);
		ksort( $callbacks, SORT_NUMERIC );

		$GLOBALS['product'] = $this->preventa_product();

		ob_start();
		foreach ( $callbacks as $callback ) {
			$callback();
		}
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'akb-avail-hint--empty', $html );
		self::assertStringContainsString( 'akb-eta-loop', $html );
		self::assertLessThan(
			(int) strpos( $html, 'akb-eta-loop' ),
			(int) strpos( $html, 'akb-avail-hint--empty' ),
			'`.akb-avail-hint--empty:has(~ .akb-eta-loop)` solo alcanza hermanos posteriores: '
			. 'con la píldora antes del placeholder, la card de preventa se queda con el hueco '
			. 'del placeholder MÁS el ETA y desalinea justo lo que el placeholder venía a alinear.'
		);
	}

	/**
	 * Guard de encolado: el predicado debe cubrir todas las superficies donde el
	 * theme carga loop.css (inc/enqueue.php, `$has_loop`).
	 *
	 * Carrito, /mi-cuenta/favoritos/ y 404 rinden cards con
	 * `wc_get_template_part( 'content', 'product' )` (cross-sell y grid de
	 * vacío/error). Cuando el guard era más estrecho que el del theme, una card de
	 * preventa ahí emitía el `<p class="akb-eta-loop">` SIN su hoja —párrafo crudo
	 * con márgenes por defecto— y encima el placeholder sí se colapsaba, porque ese
	 * colapso vive en loop.css.
	 *
	 * @testdox Should enqueue the pill stylesheet on every surface where a product card can render.
	 *
	 * @dataProvider card_surfaces
	 *
	 * @param string $conditional Condicional de WordPress que devuelve true.
	 */
	public function test_stylesheet_loads_on_every_surface_that_can_render_cards( string $conditional ): void {
		$handles = array();
		$this->capture_enqueued_styles( $handles );
		$this->stub_surface( $conditional );

		( new BannerRenderer( $this->calculator() ) )->enqueue_assets();

		self::assertSame(
			array( 'akb-estimated-delivery' ),
			$handles,
			sprintf( 'La píldora puede salir en %s() y necesita su hoja ahí.', $conditional )
		);
	}

	/**
	 * Las tres superficies que 1.99.2 agrega al guard van primero y nombradas: son
	 * las que estaban rotas. Las otras cuatro son regresión del comportamiento
	 * previo, que este cambio no debía tocar.
	 *
	 * @return array<string, array{string}>
	 */
	public function card_surfaces(): array {
		return array(
			'carrito (cross-sell del carrito vacío)' => array( 'is_cart' ),
			'/mi-cuenta/favoritos/ (grid de Wishlist)' => array( 'is_account_page' ),
			'404 (cross-sell del error)'             => array( 'is_404' ),
			'single product'                         => array( 'is_product' ),
			'/tienda/'                               => array( 'is_shop' ),
			'archivos de taxonomía de producto'      => array( 'is_product_taxonomy' ),
			'home'                                   => array( 'is_front_page' ),
		);
	}

	/**
	 * Control negativo: sin ninguna superficie de cards no se encola nada. Sin
	 * esto, "cubre carrito/cuenta/404" también lo cumpliría un guard que encola
	 * la hoja en las 1.500 URLs del sitio.
	 *
	 * @testdox Should stay off pages where no product card can render.
	 */
	public function test_stylesheet_stays_off_surfaces_without_cards(): void {
		$handles = array();
		$this->capture_enqueued_styles( $handles );
		$this->stub_surface( 'ninguna' );

		( new BannerRenderer( $this->calculator() ) )->enqueue_assets();

		self::assertSame( array(), $handles );
	}

	/**
	 * Cache-bust = VERSIÓN del plugin + filemtime, igual que quick-view.css y
	 * wishlist.css. Esta hoja era la única del plugin que viajaba con la
	 * constante pelada, y tiene dos cachés delante (LiteSpeed y Cloudflare): si
	 * se edita el CSS sin bumpear la constante, la URL queda byte-idéntica a la
	 * ya cacheada y el estilo nuevo no llega —sólo a los visitantes recurrentes,
	 * que es la peor forma de romperse—. Pesa más desde que `SeriesFacts`
	 * estiliza su ficha desde esta misma hoja.
	 *
	 * @testdox Should enqueue the pill stylesheet busted by plugin version plus filemtime.
	 */
	public function test_stylesheet_is_registered_with_the_plugin_version(): void {
		$captured = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( ...$args ) use ( &$captured ): void {
				$captured = $args;
			}
		);
		$this->stub_surface( 'is_cart' );

		( new BannerRenderer( $this->calculator() ) )->enqueue_assets();

		$css_path = AKIBARA_BUSINESS_DIR . 'assets/css/estimated-delivery.css';
		self::assertFileExists( $css_path, 'Sin el archivo el bust degrada a la constante pelada.' );
		self::assertSame(
			array(
				'akb-estimated-delivery',
				AKIBARA_BUSINESS_URL . 'assets/css/estimated-delivery.css',
				array(),
				AKIBARA_BUSINESS_VERSION . '.' . filemtime( $css_path ),
			),
			$captured
		);
	}

	/**
	 * Acumula en `$handles` los handles de estilo que se encolen.
	 *
	 * @param array<int, string> $handles Acumulador por referencia.
	 */
	private function capture_enqueued_styles( array &$handles ): void {
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( string $handle, ...$args ) use ( &$handles ): void {
				unset( $args );

				$handles[] = $handle;
			}
		);
	}

	/**
	 * Deja en true exactamente una condicional de WordPress; el resto en false.
	 *
	 * @param string $conditional Nombre de la condicional activa ('ninguna' → todas false).
	 */
	private function stub_surface( string $conditional ): void {
		$conditionals = array(
			'is_product',
			'is_shop',
			'is_product_taxonomy',
			'is_cart',
			'is_account_page',
			'is_404',
			'is_front_page',
		);

		self::assertTrue(
			'ninguna' === $conditional || in_array( $conditional, $conditionals, true ),
			'La condicional del caso debe ser una de las que el guard consulta.'
		);

		foreach ( $conditionals as $name ) {
			Functions\when( $name )->justReturn( $name === $conditional );
		}
	}
}
