<?php
/**
 * Candado del encolado de los assets de favoritos.
 *
 * Por qué existe: `Wishlist::enqueue_assets()` encolaba wishlist.css (9.499 B)
 * y wishlist.js (8.502 B) en TODO el sitio salvo el checkout. Medido en Docker
 * el 2026-08-23 sobre el HTML servido: /blog/ y /mi-cuenta/ los traían con CERO
 * ocurrencias de `akb-wl-toggle`.
 *
 * Las dos invariantes que se fijan, y que valen más que la lista concreta de
 * superficies:
 *  1. donde NO hay corazones no se encola nada por adelantado (y el checkout
 *     sigue excluido);
 *  2. donde SÍ sale un corazón, la hoja llega igual — `Button::html()` y el
 *     endpoint de favoritos piden los assets antes de imprimir. Sin hoja,
 *     `.akb-wl-anchor` pierde el `pointer-events:none` y el shim se traga el
 *     click de toda la portada, que es un bug ya documentado en Button.php.
 *
 * @package akibara-business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Wishlist;

use Akibara\Business\Modules\Wishlist\Wishlist;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AssetGatingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['akibara_wl_surface'] = array();
		$GLOBALS['akibara_wl_enqueued'] = array();
		$GLOBALS['akibara_wl_registered'] = true;
		$GLOBALS['akibara_wl_did_enqueue_scripts'] = 1;

		foreach ( array( 'is_checkout', 'is_shop', 'is_product', 'is_product_taxonomy', 'is_cart', 'is_search', 'is_front_page' ) as $conditional ) {
			if ( function_exists( $conditional ) ) {
				continue;
			}
			eval(
				'namespace { function ' . $conditional . '(): bool {
					return ! empty( $GLOBALS["akibara_wl_surface"]["' . $conditional . '"] );
				} }'
			);
		}
		if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
			eval(
				'namespace { function is_wc_endpoint_url( $endpoint = false ): bool {
					return isset( $GLOBALS["akibara_wl_surface"]["endpoint"] )
						&& $GLOBALS["akibara_wl_surface"]["endpoint"] === $endpoint;
				} }'
			);
		}
		if ( ! function_exists( 'wp_style_is' ) ) {
			eval(
				'namespace { function wp_style_is( $handle, $list = "enqueued" ): bool {
					unset( $handle, $list );
					return (bool) $GLOBALS["akibara_wl_registered"];
				} }'
			);
		}
		if ( ! function_exists( 'did_action' ) ) {
			eval(
				'namespace { function did_action( $hook ): int {
					unset( $hook );
					return (int) $GLOBALS["akibara_wl_did_enqueue_scripts"];
				} }'
			);
		}
		if ( ! function_exists( 'wp_enqueue_style' ) ) {
			eval(
				'namespace { function wp_enqueue_style( $handle, ...$rest ): void {
					unset( $rest );
					$GLOBALS["akibara_wl_enqueued"][] = "style:" . $handle;
				} }'
			);
		}
		if ( ! function_exists( 'wp_enqueue_script' ) ) {
			eval(
				'namespace { function wp_enqueue_script( $handle, ...$rest ): void {
					unset( $rest );
					$GLOBALS["akibara_wl_enqueued"][] = "script:" . $handle;
				} }'
			);
		}
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['akibara_wl_surface'],
			$GLOBALS['akibara_wl_enqueued'],
			$GLOBALS['akibara_wl_registered'],
			$GLOBALS['akibara_wl_did_enqueue_scripts']
		);

		parent::tearDown();
	}

	/**
	 * @testdox Should not preload the wishlist assets on surfaces without hearts.
	 *
	 * @dataProvider superficies_sin_corazones
	 *
	 * @param array<string,mixed> $surface Banderas condicionales activas.
	 */
	public function test_superficies_sin_corazones_no_encolan( array $surface ): void {
		$GLOBALS['akibara_wl_surface'] = $surface;

		self::assertFalse( $this->surface_renders_hearts() );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function superficies_sin_corazones(): array {
		return array(
			'blog y páginas sueltas' => array( array() ),
			// El checkout ya estaba excluido antes del cambio; que siga estándolo
			// aunque el carrito conteste que sí.
			'checkout'               => array( array( 'is_checkout' => true, 'is_cart' => true ) ),
			// Mi cuenta sin el endpoint de favoritos: el formulario de acceso
			// tiene 0 corazones y es página de tráfico.
			'mi cuenta sin endpoint' => array( array( 'endpoint' => 'pedidos' ) ),
		);
	}

	/**
	 * @testdox Should preload the wishlist assets on every surface that prints hearts.
	 *
	 * @dataProvider superficies_con_corazones
	 *
	 * @param array<string,mixed> $surface Banderas condicionales activas.
	 */
	public function test_superficies_con_corazones_encolan( array $surface ): void {
		$GLOBALS['akibara_wl_surface'] = $surface;

		self::assertTrue( $this->surface_renders_hearts() );
	}

	/**
	 * Conteos medidos en Docker el 2026-08-23 (`akb-wl-toggle` en el HTML
	 * servido): portada 96, /tienda/ 72, búsqueda de productos 48, /carrito/ 12.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function superficies_con_corazones(): array {
		return array(
			'portada'              => array( array( 'is_front_page' => true ) ),
			'tienda'               => array( array( 'is_shop' => true ) ),
			'ficha de producto'    => array( array( 'is_product' => true ) ),
			'landing de serie'     => array( array( 'is_product_taxonomy' => true ) ),
			'carrito'              => array( array( 'is_cart' => true ) ),
			'búsqueda'             => array( array( 'is_search' => true ) ),
			'endpoint favoritos'   => array( array( 'endpoint' => 'favoritos' ) ),
		);
	}

	/**
	 * @testdox Should enqueue both handles when the assets are already registered.
	 */
	public function test_ensure_assets_encola_los_dos_handles(): void {
		Wishlist::ensure_assets();

		self::assertSame(
			array( 'style:akb-wishlist', 'script:akb-wishlist' ),
			$GLOBALS['akibara_wl_enqueued']
		);
	}

	/**
	 * @testdox Should stay silent when the assets were never registered.
	 *
	 * En admin no se registra nada (`register()` cuelga el encolado de
	 * `! is_admin()`), y encolar un handle inexistente sólo emitiría un aviso.
	 */
	public function test_ensure_assets_no_encola_sin_registro(): void {
		$GLOBALS['akibara_wl_registered'] = false;

		Wishlist::ensure_assets();

		self::assertSame( array(), $GLOBALS['akibara_wl_enqueued'] );
	}

	/**
	 * @testdox Should ask for the assets before printing a heart or the favourites endpoint.
	 */
	public function test_las_superficies_perezosas_piden_los_assets(): void {
		$boton = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/Wishlist/Button.php' );
		$posicion_llamada = strpos( $boton, 'Wishlist::ensure_assets();' );
		// El marcado real, no la mención a `<button>` del docblock de cabecera.
		$posicion_markup  = strpos( $boton, '<button type="button"' );

		self::assertIsInt( $posicion_llamada, 'el corazón tiene que pedir su hoja: sin ella el shim se traga el click del cover' );
		self::assertIsInt( $posicion_markup );
		self::assertLessThan( $posicion_markup, $posicion_llamada, 'los assets se piden ANTES de imprimir el botón' );

		$render = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/Wishlist/Render.php' );
		self::assertStringContainsString(
			'Wishlist::ensure_assets();',
			$render,
			'el estado vacío de /mi-cuenta/favoritos/ usa .akb-wl-empty__icon y también necesita la hoja'
		);
	}

	/**
	 * QA integral 2026-09-24 (QA-RC-4): el diálogo del aviso para invitados viajaba
	 * en wishlist.css, bloqueante en cada página con corazones, con el aviso
	 * apagado de fábrica. Su hoja ya no se encola: el servidor sólo pasa la
	 * dirección y wishlist.js la pide cuando el aviso aplica al visitante
	 * (tests/Modules/Wishlist/wishlist-guest-style.test.js).
	 *
	 * @testdox Should hand the guest prompt sheet to the script instead of enqueueing it.
	 */
	public function test_la_hoja_del_aviso_la_pide_el_script(): void {
		Wishlist::ensure_assets();
		self::assertSame( array( 'style:akb-wishlist', 'script:akb-wishlist' ), $GLOBALS['akibara_wl_enqueued'], 'Sólo la hoja y el script de siempre.' );

		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/Wishlist/Wishlist.php' );
		self::assertStringContainsString( "'guestStyle'  => AKIBARA_BUSINESS_URL . 'assets/css/wishlist-guest-prompt.css?ver=' . PluginAssetVersion::for_path( 'assets/css/wishlist-guest-prompt.css' )", $fuente );
		self::assertDoesNotMatchRegularExpression( '/wp_(?:register|enqueue)_style\([^;]*wishlist-guest-prompt/s', $fuente, 'Ningún otro camino la encola.' );
	}

	/**
	 * @testdox Should keep every guest prompt rule out of the sheet that every heart page loads.
	 */
	public function test_las_reglas_del_aviso_viven_en_su_hoja(): void {
		$raiz    = dirname( __DIR__, 3 ) . '/assets/css/';
		$general = (string) file_get_contents( $raiz . 'wishlist.css' );
		$aviso   = (string) file_get_contents( $raiz . 'wishlist-guest-prompt.css' );

		self::assertStringNotContainsString( 'akb-wl-login', $general );
		foreach ( array( '.akb-wl-login {', '.akb-wl-login::backdrop', 'html.akb-wl-login-open', '.akb-wl-login .akb-wl-login__cta {', '@keyframes akb-wl-login-rise' ) as $regla ) {
			self::assertStringContainsString( $regla, $aviso, $regla );
		}
		self::assertMatchesRegularExpression( '/@media \(prefers-reduced-motion: reduce\) \{\s*\.akb-wl-login \{\s*animation: none;/', $aviso );
	}

	private function surface_renders_hearts(): bool {
		$instancia = ( new ReflectionClass( Wishlist::class ) )->newInstanceWithoutConstructor();
		$metodo    = new ReflectionMethod( Wishlist::class, 'surface_renders_hearts' );
		$metodo->setAccessible( true );

		return (bool) $metodo->invoke( $instancia );
	}
}
