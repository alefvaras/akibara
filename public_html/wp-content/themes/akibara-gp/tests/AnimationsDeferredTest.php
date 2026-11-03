<?php
/**
 * Contrato ejecutable de la carga diferida de GSAP fuera de la portada
 * (PC-8/PM-11, auditoría del tema 2026-09-22).
 *
 * GSAP + ScrollTrigger + animations.js son ~59 KB comprimidos que se bajaban y
 * ejecutaban dentro de la ventana del LCP en toda superficie con rejilla. El
 * A/B de producción R11-gsap-en-ficha-medido midió −312 ms de LCP en la ficha
 * y −204 ms en el catálogo sin ellos. Desde este cambio:
 *
 *   - la PORTADA los sigue encolando en el HTML (el intro del hero corre al
 *     cargar);
 *   - el RESTO sólo los registra, y animations-loader.js los inyecta tarde con
 *     las URLs que PHP le deja en `data-akb-diferidos`;
 *   - el cargador va en las dos, porque es el dueño de «agregar → cajón».
 *
 * Aquí se ejecuta `akibara_gp_enqueue()` con dobles del registro de scripts de
 * WordPress y se afirma sobre lo que queda registrado, encolado e impreso.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class AnimationsDeferredTest extends TestCase {

	private const URI = 'https://akibara.test/wp-content/themes/akibara-gp';

	/** @var \stdClass Doble de `wp_scripts()`: `->registered` y `->queue`. */
	private \stdClass $scripts;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->scripts             = new \stdClass();
		$this->scripts->registered = array();
		$this->scripts->queue      = array();

		$theme_dir = dirname( __DIR__ );
		Functions\when( 'get_stylesheet_directory' )->justReturn( $theme_dir );
		Functions\when( 'get_stylesheet_directory_uri' )->justReturn( self::URI );
		Functions\when( 'get_template' )->justReturn( 'generatepress' );
		// akibara_gp_enqueue() pregunta por el buscador si la función existe, y
		// existe en cuanto otra clase de la suite la dobló (AssetServidoTest):
		// sin este doble, con `--order-by=random` estas pruebas caían (QF-gp).
		Functions\when( 'akibara_search_facets_active' )->justReturn( false );
		Functions\when( 'wp_get_theme' )->justReturn(
			new class() {
				/**
				 * @param string $header Cabecera pedida.
				 * @return string
				 */
				public function get( $header ) {
					return 'Version' === $header ? '2.1.142' : '';
				}
			}
		);
		foreach ( array( 'wp_register_style', 'wp_enqueue_style', 'wp_add_inline_style' ) as $noop ) {
			Functions\when( $noop )->justReturn( true );
		}

		Functions\when( 'wp_register_script' )->alias(
			function ( $handle, $src, $deps = array(), $ver = false ): bool {
				$this->scripts->registered[ $handle ] = (object) array(
					'src'  => $src,
					'deps' => $deps,
					'ver'  => $ver,
				);
				return true;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = '', $deps = array(), $ver = false ): void {
				if ( '' !== $src && ! isset( $this->scripts->registered[ $handle ] ) ) {
					$this->scripts->registered[ $handle ] = (object) array(
						'src'  => $src,
						'deps' => $deps,
						'ver'  => $ver,
					);
				}
				$this->scripts->queue[] = $handle;
			}
		);
		Functions\when( 'wp_script_is' )->alias(
			function ( $handle, $status = 'enqueued' ): bool {
				return 'registered' === $status
					? isset( $this->scripts->registered[ $handle ] )
					: in_array( $handle, $this->scripts->queue, true );
			}
		);
		Functions\when( 'wp_scripts' )->alias(
			function (): \stdClass {
				return $this->scripts;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value, $url ): string {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);

		require_once dirname( __DIR__ ) . '/inc/page-targets.php';
		require_once dirname( __DIR__ ) . '/inc/enqueue.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Todas las condicionales de ruta en falso salvo las pedidas.
	 *
	 * @param array<string, bool> $verdaderas Condicionales que deben dar true.
	 */
	private function ruta( array $verdaderas ): void {
		foreach ( array(
			'is_woocommerce', 'is_cart', 'is_checkout', 'is_account_page', 'is_front_page',
			'is_404', 'is_page', 'is_search', 'is_shop', 'is_product', 'is_product_category',
			'is_product_tag', 'is_product_taxonomy', 'is_tax', 'is_home', 'is_category',
			'is_tag', 'is_author', 'is_date', 'is_singular',
		) as $condicional ) {
			Functions\when( $condicional )->justReturn( $verdaderas[ $condicional ] ?? false );
		}
		Functions\when( 'get_query_var' )->justReturn( '' );
	}

	/**
	 * Etiqueta del cargador como la imprime WordPress, pasada por el filtro del tema.
	 */
	private function etiqueta_del_cargador(): string {
		$tag = '<script src="' . self::URI . '/assets/js/animations-loader.js?ver=1" id="akibara-animations-loader-js" defer data-wp-strategy="defer"></script>' . "\n";
		return akibara_gp_main_script_loader_tag( $tag, 'akibara-animations-loader' );
	}

	/**
	 * @return array<int, string>
	 */
	private function diferidos( string $tag ): array {
		if ( ! preg_match( '/data-akb-diferidos="([^"]*)"/', $tag, $m ) ) {
			return array();
		}
		$lista = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		return is_array( $lista ) ? $lista : array();
	}

	public function test_en_la_ficha_gsap_queda_registrado_pero_lo_trae_el_cargador(): void {
		$this->ruta( array( 'is_woocommerce' => true, 'is_product' => true ) );

		akibara_gp_enqueue();

		foreach ( array( 'gsap', 'gsap-scrolltrigger', 'akibara-animations' ) as $handle ) {
			$this->assertArrayHasKey( $handle, $this->scripts->registered, "{$handle} tiene que seguir registrado: rewards lo pide como dependencia." );
			$this->assertNotContains( $handle, $this->scripts->queue, "{$handle} no puede viajar en el HTML de la ficha: compite con el LCP." );
		}
		$this->assertContains( 'akibara-animations-loader', $this->scripts->queue, 'El cargador abre el cajón al agregar: tiene que estar.' );
		$this->assertSame(
			array( 'akibara-wc-event-bridge' ),
			$this->scripts->registered['akibara-animations-loader']->deps,
			'El cargador oye akb:added_to_cart: el puente tiene que existir antes.'
		);

		$tag = $this->etiqueta_del_cargador();
		$this->assertStringContainsString( 'data-no-optimize="1"', $tag, 'Combinado o atrasado por LiteSpeed, el cajón no abriría hasta la primera interacción.' );

		$urls = $this->diferidos( $tag );
		$this->assertCount( 3, $urls, 'El cargador necesita las tres URLs.' );
		$this->assertMatchesRegularExpression( '#/assets/js/vendor/gsap\.min\.js\?ver=2\.1\.142\.\d+$#', $urls[0] );
		$this->assertMatchesRegularExpression( '#/assets/js/vendor/ScrollTrigger\.min\.js\?ver=2\.1\.142\.\d+$#', $urls[1] );
		// Se inyecta la copia que se SIRVE (akibara_gp_asset_servido(): la `.min`
		// generada, salvo SCRIPT_DEBUG), leída del registro con su propio `?ver`.
		$this->assertMatchesRegularExpression( '#/assets/js/animations\.min\.js\?ver=2\.1\.142\.\d+$#', $urls[2], 'Orden de ejecución: gsap → ScrollTrigger → animations.' );
	}

	public function test_en_la_portada_las_animaciones_viajan_en_el_html(): void {
		$this->ruta( array( 'is_front_page' => true ) );

		akibara_gp_enqueue();

		$this->assertContains( 'akibara-animations', $this->scripts->queue, 'El intro del hero corre al cargar: la portada no difiere GSAP.' );
		$this->assertSame( array( 'gsap', 'gsap-scrolltrigger' ), $this->scripts->registered['akibara-animations']->deps );
		$this->assertContains( 'akibara-animations-loader', $this->scripts->queue, 'El cajón al agregar también se abre en la portada.' );
		$this->assertSame( array(), $this->diferidos( $this->etiqueta_del_cargador() ), 'Con animations.js en el HTML, el cargador no inyecta nada.' );
	}

	public function test_carrito_y_mi_cuenta_no_cargan_animaciones_ni_cargador(): void {
		foreach ( array( 'is_cart', 'is_account_page' ) as $condicional ) {
			$this->scripts->registered = array();
			$this->scripts->queue      = array();
			$this->ruta( array( 'is_woocommerce' => true, $condicional => true ) );

			akibara_gp_enqueue();

			$this->assertArrayNotHasKey( 'gsap', $this->scripts->registered, "{$condicional}: la rejilla es cross-sell, no descubrimiento." );
			$this->assertNotContains( 'akibara-animations-loader', $this->scripts->queue, "{$condicional}: sin animaciones no hay cargador." );
		}
	}

	public function test_el_cargador_queda_fuera_del_combine_y_el_delay_de_litespeed(): void {
		$lista = akibara_gp_litespeed_main_js_exclusions( array() );

		$this->assertContains( 'akibara-animations-loader', $lista );
		$this->assertContains( 'assets/js/animations-loader.js', $lista );
		// LiteSpeed compara por SUBCADENA: `animations-loader.js` no casa con la
		// copia `.min` que de verdad se sirve.
		$this->assertContains( 'assets/js/animations-loader.min.js', $lista );
		$this->assertContains( 'wp-content/themes/akibara-gp/assets/js/animations-loader.min.js', $lista );
		// akibara-brands-filter lo encola akibara-business y desde T4 pide la copia
		// `.min` (ThemeAssetVersion::served_path()): lee `?region=` antes del primer
		// pintado, así que ninguna de sus dos rutas puede atrasarla LiteSpeed.
		foreach ( array( 'main', 'wc-event-bridge', 'animations', 'atc-guard', 'akibara-brands-filter' ) as $guion ) {
			$this->assertContains( "assets/js/{$guion}.min.js", $lista, "{$guion}.min.js tiene que quedar fuera del optimizador igual que su fuente." );
			$this->assertContains( "assets/js/{$guion}.js", $lista, "{$guion}.js (SCRIPT_DEBUG) también." );
		}
	}

	public function test_sin_registro_no_hay_urls_que_diferir(): void {
		$this->assertSame( array(), akibara_gp_deferred_animation_sources(), 'Página sin animaciones: nada que inyectar.' );
	}
}
