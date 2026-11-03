<?php
/**
 * El carrito sin lo que sólo usa la página de pago (PR-2): encendido de fábrica,
 * sólo en el carrito de bloques, sólo la lista literal y nada sin Mercado Pago;
 * y lo que saca, lo deja en la caché para la página de pago con la misma URL y
 * el mismo `crossorigin` con que ella lo pide (revisión C-N).
 *
 * Las pruebas con Mercado Pago presente definen `MP_VERSION`, que contamina el
 * proceso: van aparte, como las de Google for WooCommerce en `SiteSpeedTest`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SiteSpeed;

use Akibara\Business\Modules\SiteSpeed\LeanCart;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
	require_once ABSPATH . 'wp-includes/class-wp-token-map.php';
	require_once ABSPATH . 'wp-includes/html-api/html5-named-character-references.php';
	require_once ABSPATH . 'wp-includes/html-api/class-wp-html-attribute-token.php';
	require_once ABSPATH . 'wp-includes/html-api/class-wp-html-span.php';
	require_once ABSPATH . 'wp-includes/html-api/class-wp-html-doctype-info.php';
	require_once ABSPATH . 'wp-includes/html-api/class-wp-html-text-replacement.php';
	require_once ABSPATH . 'wp-includes/html-api/class-wp-html-decoder.php';
	require_once ABSPATH . 'wp-includes/html-api/class-wp-html-tag-processor.php';
}

final class LeanCartTest extends TestCase {

	/**
	 * Lo que imprime `do_item()` en producción para cada uno (copia del carrito
	 * de akibara.cl del 2026-09-26): el SDK con el `crossorigin` que le pone
	 * `CrossOriginScripts`; las hojas con comillas simples. Cada URL lleva sólo
	 * `?ver=` con la versión de Mercado Pago: la decodificación de `&#038;` la
	 * prueba un caso inventado en `test_la_entrada_sale_de_la_etiqueta_con_su_id`.
	 */
	private const ETIQUETAS = array(
		'wc_mercadopago_sdk'                          => "<script crossorigin=\"anonymous\" src=\"https://sdk.mercadopago.com/js/v2?ver=8.9.3\" id=\"wc_mercadopago_sdk-js\"></script>\n",
		'wc_mercadopago_security_session'             => "<script src=\"https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/js/session.min.js?ver=8.9.3\" id=\"wc_mercadopago_security_session-js\"></script>\n",
		'wc_mercadopago_custom_card_form_error_codes' => "<script id=\"wc_mercadopago_custom_card_form_error_codes-js-extra\">\nvar x = {\"src\":\"otro.js\"};\n</script>\n<script src=\"https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/js/checkouts/custom/entities/mp-card-form-error-codes.min.js?ver=8.9.3\" id=\"wc_mercadopago_custom_card_form_error_codes-js\"></script>\n",
		'mercadopago_vars_css'                        => "<link rel='stylesheet' id='mercadopago_vars_css-css' href='https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/css/public/mp-vars.min.css?ver=8.9.3' media='all' />\n",
		'wc_mercadopago_checkout_components'          => "<link rel='stylesheet' id='wc_mercadopago_checkout_components-css' href='https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/css/checkouts/mp-plugins-components.min.css?ver=8.9.3' media='all' />\n",
	);

	/**
	 * The System Under Test.
	 *
	 * @var LeanCart
	 */
	private LeanCart $sut;

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, string> */
	private array $scripts_fuera = array();

	/** @var array<int, string> */
	private array $hojas_fuera = array();

	/** @var array<int, array<int, mixed>> */
	private array $acciones = array();

	/** @var array<int, array<int, mixed>> */
	private array $filtros = array();

	/**
	 * Lo que el carrito iba a descargar (`wp_script_is`/`wp_style_is` 'enqueued').
	 *
	 * @var array<int, string>
	 */
	private array $encolados = array();

	/** @var array<int, array{0: string, 1: array<string, string>}> */
	private array $impresos = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->sut           = new LeanCart();
		$this->options       = array();
		$this->scripts_fuera = array();
		$this->hojas_fuera   = array();
		$this->acciones      = array();
		$this->filtros       = array();
		$this->encolados     = array_merge( LeanCart::SCRIPT_HANDLES, LeanCart::STYLE_HANDLES );
		$this->impresos      = array();

		// Una cola que imprime lo que imprimiría WordPress en producción.
		$cola = new class() {
			public function do_item( string $handle ): bool {
				echo LeanCartTest::etiqueta( $handle ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML de prueba.
				return '' !== LeanCartTest::etiqueta( $handle );
			}
		};
		Functions\when( 'wp_scripts' )->justReturn( $cola );
		Functions\when( 'wp_styles' )->justReturn( $cola );
		Functions\when( 'wp_script_is' )->alias( fn( string $handle, string $estado ): bool => 'enqueued' === $estado && in_array( $handle, $this->encolados, true ) );
		Functions\when( 'wp_style_is' )->alias( fn( string $handle, string $estado ): bool => 'enqueued' === $estado && in_array( $handle, $this->encolados, true ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( $valor ) => json_encode( $valor ) );
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			function ( string $codigo, array $atributos ): void {
				$this->impresos[] = array( $codigo, $atributos );
			}
		);

		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'wp_dequeue_script' )->alias(
			function ( string $handle ): void {
				$this->scripts_fuera[] = $handle;
			}
		);
		Functions\when( 'wp_dequeue_style' )->alias(
			function ( string $handle ): void {
				$this->hojas_fuera[] = $handle;
			}
		);
		Functions\when( 'add_action' )->alias(
			function ( ...$args ): bool {
				$this->acciones[] = $args;
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( ...$args ): bool {
				$this->filtros[] = $args;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Lo que imprime la cola de prueba para `$handle`. */
	public static function etiqueta( string $handle ): string {
		return self::ETIQUETAS[ $handle ] ?? '';
	}

	/**
	 * La lista que lleva el guion impreso.
	 *
	 * @return array<int, mixed>
	 */
	private function lista_impresa(): array {
		self::assertCount( 1, $this->impresos, 'Un solo guion en línea.' );
		[ $codigo, $atributos ] = $this->impresos[0];
		self::assertSame( array( 'id' => LeanCart::WARMUP_ID, 'data-no-optimize' => '1' ), $atributos, 'Con su id y fuera del optimizador de LiteSpeed.' );

		[ $antes, $despues ] = explode( LeanCart::WARMUP_SLOT, LeanCart::WARMUP_JS );
		self::assertStringStartsWith( $antes, $codigo );
		self::assertStringEndsWith( $despues, $codigo );

		return json_decode( substr( $codigo, strlen( $antes ), - strlen( $despues ) ), true );
	}

	/**
	 * La página que se está sirviendo.
	 *
	 * @param bool $carrito  `is_cart()`.
	 * @param bool $bloques  La página del carrito lleva el bloque de WooCommerce.
	 */
	private function pagina( bool $carrito, bool $bloques ): void {
		Functions\when( 'is_cart' )->justReturn( $carrito );
		Functions\when( 'wc_get_page_id' )->justReturn( 9082 );
		Functions\when( 'has_block' )->alias( static fn( string $bloque, $post ): bool => $bloques && 'woocommerce/cart' === $bloque && 9082 === $post );
	}

	private function con_mercado_pago(): void {
		define( 'MP_VERSION', '8.9.3' );
	}

	/** @testdox Sin Mercado Pago no quita nada, ni siquiera en el carrito. */
	public function test_sin_mercado_pago_no_quita_nada(): void {
		$this->pagina( true, true );

		$this->sut->dequeue_checkout_only_assets();

		self::assertFalse( LeanCart::glue_active() );
		self::assertSame( array(), $this->scripts_fuera );
		self::assertSame( array(), $this->hojas_fuera );
		$this->sut->print_warmup();
		self::assertSame( array(), $this->impresos );
		self::assertSame( array(), $this->sut->add_prefetch_hints( array(), 'prefetch' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testdox De fábrica, en el carrito de bloques quita exactamente los tres guiones y las dos hojas de cobro.
	 */
	public function test_en_el_carrito_de_bloques_quita_la_lista_literal(): void {
		$this->con_mercado_pago();
		$this->pagina( true, true );

		$this->sut->dequeue_checkout_only_assets();

		self::assertTrue( LeanCart::enabled(), 'Sin fila: encendido.' );
		self::assertSame(
			array( 'wc_mercadopago_sdk', 'wc_mercadopago_security_session', 'wc_mercadopago_custom_card_form_error_codes' ),
			$this->scripts_fuera
		);
		self::assertSame( array( 'mercadopago_vars_css', 'wc_mercadopago_checkout_components' ), $this->hojas_fuera );
	}

	/**
	 * Revisión C-N (REV-CN-1o-1): sin esto, quien pasaba del carrito a pagar
	 * bajaba el SDK de la red en la página de pago, que hoy lo saca de la caché
	 * que deja el carrito. La URL y el `crossorigin` salen de la etiqueta que
	 * imprimiría WordPress, que es la misma que imprime la página de pago.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testdox Lo que quita lo deja en la caché en Safari: un guion en línea con la URL decodificada, el tipo y el crossorigin de cada etiqueta.
	 */
	public function test_lo_que_quita_lo_deja_en_la_cache_con_la_url_y_el_crossorigin_de_su_etiqueta(): void {
		$this->con_mercado_pago();
		$this->pagina( true, true );

		$this->sut->dequeue_checkout_only_assets();
		self::assertSame( array(), $this->impresos, 'En la cola no se imprime nada: el guion va después, en el <head>.' );
		$this->sut->print_warmup();

		self::assertSame(
			array(
				array( 'https://sdk.mercadopago.com/js/v2?ver=8.9.3', 'script', 'anonymous' ),
				array( 'https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/js/session.min.js?ver=8.9.3', 'script', null ),
				array( 'https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/js/checkouts/custom/entities/mp-card-form-error-codes.min.js?ver=8.9.3', 'script', null ),
				array( 'https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/css/public/mp-vars.min.css?ver=8.9.3', 'style', null ),
				array( 'https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/css/checkouts/mp-plugins-components.min.css?ver=8.9.3', 'style', null ),
			),
			$this->lista_impresa()
		);
	}

	/**
	 * Revisión C-N (REV-CN-2o-1): la precarga esperaba al `load`, y quien pasaba a
	 * pagar antes volvía a bajar el SDK en la página de pago. Ahora van en el
	 * `<head>` como `prefetch` (Chromium, Firefox), con la URL, el `as` y el
	 * `crossorigin` de su etiqueta; sin él si ella no lo lleva.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testdox Lo que quita va en el <head> como prefetch, con su tipo y el crossorigin de su etiqueta; las demás pistas no cambian.
	 */
	public function test_lo_que_quita_va_en_el_head_como_prefetch(): void {
		$this->con_mercado_pago();
		$this->pagina( true, true );
		$ajena = array( 'href' => 'https://otro.test/x.js' );

		self::assertSame( array( $ajena ), $this->sut->add_prefetch_hints( array( $ajena ), 'prefetch' ), 'Antes de sacar nada, no añade nada.' );
		$this->sut->dequeue_checkout_only_assets();

		self::assertSame(
			array(
				$ajena,
				array(
					'href'        => 'https://sdk.mercadopago.com/js/v2?ver=8.9.3',
					'as'          => 'script',
					'crossorigin' => 'anonymous',
				),
				array(
					'href' => 'https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/js/session.min.js?ver=8.9.3',
					'as'   => 'script',
				),
				array(
					'href' => 'https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/js/checkouts/custom/entities/mp-card-form-error-codes.min.js?ver=8.9.3',
					'as'   => 'script',
				),
				array(
					'href' => 'https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/css/public/mp-vars.min.css?ver=8.9.3',
					'as'   => 'style',
				),
				array(
					'href' => 'https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/css/checkouts/mp-plugins-components.min.css?ver=8.9.3',
					'as'   => 'style',
				),
			),
			$this->sut->add_prefetch_hints( array( $ajena ), 'prefetch' ),
			'Los cinco detrás de lo que ya había.'
		);

		foreach ( array( 'dns-prefetch', 'preconnect', 'prerender' ) as $tipo ) {
			self::assertSame( array( $ajena ), $this->sut->add_prefetch_hints( array( $ajena ), $tipo ), "Las pistas «{$tipo}» no se tocan." );
		}
		self::assertSame( 'roto', $this->sut->add_prefetch_hints( 'roto', 'prefetch' ), 'Lo que otro filtro rompió, se devuelve tal cual.' );
	}

	/**
	 * El `crossorigin` sin valor de una etiqueta es anónimo: la pista lo lleva
	 * igual, vacío (`wp_resource_hints` imprime `crossorigin=''`).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testdox Un crossorigin sin valor viaja vacío a la pista.
	 */
	public function test_el_crossorigin_sin_valor_viaja_vacio(): void {
		$this->con_mercado_pago();
		$this->pagina( true, true );
		$this->encolados = array( 'wc_mercadopago_sdk' );
		Functions\when( 'wp_scripts' )->justReturn(
			new class() {
				public function do_item( string $handle ): bool {
					echo '<script crossorigin src="https://sdk.mercadopago.com/js/v2?ver=8.9.3" id="' . $handle . '-js"></script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML de prueba.
					return true;
				}
			}
		);

		$this->sut->dequeue_checkout_only_assets();

		self::assertSame(
			array(
				array(
					'href'        => 'https://sdk.mercadopago.com/js/v2?ver=8.9.3',
					'as'          => 'script',
					'crossorigin' => '',
				),
			),
			$this->sut->add_prefetch_hints( array(), 'prefetch' )
		);
	}

	/**
	 * REV-CN-2f-1: la copia decía venir de producción y llevaba en la hoja un
	 * `&v=2` que Mercado Pago nunca imprime. Se ata a lo que sí imprime: `?ver=`
	 * con la versión del Mercado Pago instalado (`Url::assetVersion()`, fuera de
	 * un entorno de desarrollo), y nada más.
	 *
	 * @testdox La copia de producción lleva en cada URL sólo ?ver= con la versión del Mercado Pago instalado.
	 */
	public function test_la_copia_de_produccion_lleva_solo_la_version_de_mercado_pago(): void {
		$principal = (string) file_get_contents( dirname( __DIR__, 4 ) . '/woocommerce-mercadopago/src/WoocommerceMercadoPago.php' );
		self::assertSame( 1, preg_match( "/PLUGIN_VERSION\\s*=\\s*'([^']+)'/", $principal, $m ), 'Fixture: la versión del Mercado Pago instalado.' );
		self::assertCount( count( LeanCart::SCRIPT_HANDLES ) + count( LeanCart::STYLE_HANDLES ), self::ETIQUETAS, 'Una etiqueta por cada uno.' );

		foreach ( self::ETIQUETAS as $handle => $html ) {
			$entrada = LeanCart::warmup_entry( $html, $handle, in_array( $handle, LeanCart::STYLE_HANDLES, true ) ? 'style' : 'script' );
			self::assertNotNull( $entrada, "«{$handle}»: la etiqueta se reconoce." );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Sin WordPress cargado.
			self::assertSame( 'ver=' . $m[1], parse_url( $entrada[0], PHP_URL_QUERY ), "«{$handle}»: {$entrada[0]}" );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testdox Sólo precarga lo que el carrito iba a descargar; si no iba a descargar nada, no imprime nada.
	 */
	public function test_solo_precarga_lo_que_el_carrito_iba_a_descargar(): void {
		$this->con_mercado_pago();
		$this->pagina( true, true );
		$this->encolados = array( 'wc_mercadopago_sdk', 'mercadopago_vars_css' );

		$this->sut->dequeue_checkout_only_assets();
		$this->sut->print_warmup();

		self::assertCount( 3, $this->scripts_fuera, 'Saca de la cola los tres igual.' );
		self::assertSame( array( 'https://sdk.mercadopago.com/js/v2?ver=8.9.3', 'https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/css/public/mp-vars.min.css?ver=8.9.3' ), array_column( $this->lista_impresa(), 0 ) );

		$otra            = new LeanCart();
		$this->impresos  = array();
		$this->encolados = array();
		$otra->dequeue_checkout_only_assets();
		$otra->print_warmup();
		self::assertSame( array(), $this->impresos );
	}

	/**
	 * @testdox La etiqueta se reconoce por su id y da su URL decodificada y su crossorigin tal cual (null si no lo lleva).
	 */
	public function test_la_entrada_sale_de_la_etiqueta_con_su_id(): void {
		self::assertSame( array( 'https://sdk.mercadopago.com/js/v2?ver=8.9.3', 'script', 'anonymous' ), LeanCart::warmup_entry( self::ETIQUETAS['wc_mercadopago_sdk'], 'wc_mercadopago_sdk', 'script' ) );
		self::assertSame( 'https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/js/checkouts/custom/entities/mp-card-form-error-codes.min.js?ver=8.9.3', LeanCart::warmup_entry( self::ETIQUETAS['wc_mercadopago_custom_card_form_error_codes'], 'wc_mercadopago_custom_card_form_error_codes', 'script' )[0], 'Los datos en línea que la acompañan no cuentan.' );
		self::assertSame( array( 'https://x.test/a.js', 'script', '' ), LeanCart::warmup_entry( '<script crossorigin src="https://x.test/a.js" id="a-js"></script>', 'a', 'script' ), 'Sin valor es anónimo.' );
		self::assertSame( 'use-credentials', LeanCart::warmup_entry( '<script crossorigin="use-credentials" src="https://x.test/a.js" id="a-js"></script>', 'a', 'script' )[2] );
		self::assertSame( array( 'https://x.test/a.css?ver=1&v=2', 'style', null ), LeanCart::warmup_entry( "<link rel='stylesheet' id='a-css' href='https://x.test/a.css?ver=1&#038;v=2' media='all' />", 'a', 'style' ) );

		self::assertNull( LeanCart::warmup_entry( '', 'a', 'script' ), 'Sin etiqueta (no registrado): nada.' );
		self::assertNull( LeanCart::warmup_entry( '<script src="https://x.test/b.js" id="b-js"></script>', 'a', 'script' ), 'La de otro handle, no.' );
		self::assertNull( LeanCart::warmup_entry( '<script id="a-js"></script>', 'a', 'script' ), 'Sin URL no hay nada que pedir.' );
		self::assertNull( LeanCart::warmup_entry( "<link rel='stylesheet' id='a-css' href='' />", 'a', 'style' ), 'Ni con la URL vacía: un `href` vacío pediría la propia página.' );
		self::assertNull( LeanCart::warmup_entry( "<link rel='stylesheet' id='a-css' href='https://x.test/a.css' />", 'a', 'script' ), 'Un guion se busca en `<script>`.' );
	}

	/**
	 * La lista entra con `str_replace` en el marcador: dos marcadores la
	 * meterían dos veces, y ninguno dejaría el guion sin lista.
	 *
	 * @testdox El guion en línea lleva un solo hueco para la lista.
	 */
	public function test_el_guion_lleva_un_solo_hueco(): void {
		self::assertSame( 1, substr_count( LeanCart::WARMUP_JS, LeanCart::WARMUP_SLOT ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testdox Apagado en el panel, el carrito queda como lo encola Mercado Pago.
	 */
	public function test_apagado_no_quita_nada(): void {
		$this->con_mercado_pago();
		$this->pagina( true, true );

		foreach ( array( 'no', '', '0' ) as $valor ) {
			$this->options[ LeanCart::OPTION ] = $valor;
			$this->sut->dequeue_checkout_only_assets();
			self::assertFalse( LeanCart::enabled(), "«{$valor}» apaga." );
		}

		self::assertSame( array(), $this->scripts_fuera );
		self::assertSame( array(), $this->hojas_fuera );
		$this->sut->print_warmup();
		self::assertSame( array(), $this->impresos, 'Ni precarga.' );
		self::assertSame( array(), $this->sut->add_prefetch_hints( array(), 'prefetch' ), 'Ni pistas.' );

		$this->options[ LeanCart::OPTION ] = 'yes';
		$this->sut->dequeue_checkout_only_assets();
		self::assertCount( 3, $this->scripts_fuera, 'Y encendido de nuevo, vuelve a quitar.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testdox Fuera del carrito —la página de pago incluida— no quita nada.
	 */
	public function test_fuera_del_carrito_no_quita_nada(): void {
		$this->con_mercado_pago();
		$this->pagina( false, true );

		$this->sut->dequeue_checkout_only_assets();

		self::assertSame( array(), $this->scripts_fuera, 'La página de pago necesita el SDK, la huella del dispositivo y los errores de la tarjeta.' );
		self::assertSame( array(), $this->hojas_fuera );
		$this->sut->print_warmup();
		self::assertSame( array(), $this->impresos, 'Ni precarga: allí se cargan de verdad.' );
		self::assertSame( array(), $this->sut->add_prefetch_hints( array(), 'prefetch' ), 'Ni pistas.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testdox Con el carrito clásico (sin el bloque) no quita nada.
	 */
	public function test_carrito_clasico_no_quita_nada(): void {
		$this->con_mercado_pago();
		$this->pagina( true, false );

		$this->sut->dequeue_checkout_only_assets();

		self::assertSame( array(), $this->scripts_fuera );
		self::assertSame( array(), $this->hojas_fuera );
		$this->sut->print_warmup();
		self::assertSame( array(), $this->impresos );
		self::assertSame( array(), $this->sut->add_prefetch_hints( array(), 'prefetch' ) );
	}

	/**
	 * REV-CN-2o-1: la precarga iba en el pie y esperaba al `load`. Ahora las pistas
	 * salen con las de WordPress y el guion de Safari justo detrás, los dos en el
	 * `<head>` y antes de las hojas (un guion en línea detrás de una hoja espera a
	 * que ésta baje). Las prioridades de WordPress se leen de su código.
	 *
	 * @testdox Se engancha después de Mercado Pago y del retiro de akibara-cart-checkout-blocks, precarga desde el <head> antes de las hojas, y nunca en el admin.
	 */
	public function test_se_engancha_al_final_y_solo_en_el_front(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$this->sut->register();

		self::assertSame(
			array(
				array( 'wp_enqueue_scripts', array( $this->sut, 'dequeue_checkout_only_assets' ), LeanCart::PRIORITY ),
				array( 'wp_head', array( $this->sut, 'print_warmup' ), LeanCart::WARMUP_PRIORITY ),
			),
			$this->acciones
		);
		self::assertSame( array( array( 'wp_resource_hints', array( $this->sut, 'add_prefetch_hints' ), 10, 2 ) ), $this->filtros );

		$bloques = (string) file_get_contents( dirname( __DIR__, 4 ) . '/akibara-cart-checkout-blocks/src/RuntimeCompatibility.php' );
		self::assertSame( 1, preg_match( "/add_action\(\s*'wp_enqueue_scripts',\s*array\(\s*\\\$this,\s*'dequeue_classic_mercado_pago_assets'\s*\),\s*(\d+)\s*\)/", $bloques, $m ), 'Fixture: el retiro de akibara-cart-checkout-blocks.' );
		self::assertGreaterThan( (int) $m[1], LeanCart::PRIORITY );
		self::assertGreaterThan( 10, LeanCart::PRIORITY, 'Mercado Pago encola en la 10.' );

		$nucleo = (string) file_get_contents( ABSPATH . 'wp-includes/default-filters.php' );
		$en_head = static function ( string $funcion ) use ( $nucleo ): int {
			self::assertSame( 1, preg_match( "/add_action\\(\\s*'wp_head',\\s*'{$funcion}',\\s*(\\d+)\\s*\\)/", $nucleo, $p ), "Fixture: «{$funcion}» en wp_head." );
			return (int) $p[1];
		};
		self::assertGreaterThan( $en_head( 'wp_enqueue_scripts' ), $en_head( 'wp_resource_hints' ), 'Las pistas salen con la lista ya hecha.' );
		self::assertGreaterThan( $en_head( 'wp_enqueue_scripts' ), LeanCart::WARMUP_PRIORITY, 'Y el guion también.' );
		self::assertGreaterThan( $en_head( 'wp_resource_hints' ), LeanCart::WARMUP_PRIORITY, 'Detrás de las pistas.' );
		self::assertLessThan( $en_head( 'wp_print_styles' ), LeanCart::WARMUP_PRIORITY, 'Antes de las hojas.' );

		$this->acciones = array();
		$this->filtros  = array();
		Functions\when( 'is_admin' )->justReturn( true );
		$this->sut->register();
		self::assertSame( array(), $this->acciones );
		self::assertSame( array(), $this->filtros );
	}

	/**
	 * La lista es literal: se lleva sólo lo que el carrito no usa. La telemetría
	 * y los guiones de bloques (dependencias del carrito de WooCommerce) se
	 * quedan, y no se repite nada de lo que ya retira el plugin de bloques.
	 *
	 * @testdox La lista no toca la telemetría ni los guiones de bloques de Mercado Pago, ni repite los de akibara-cart-checkout-blocks.
	 */
	public function test_la_lista_es_literal_y_acotada(): void {
		$todos = array_merge( LeanCart::SCRIPT_HANDLES, LeanCart::STYLE_HANDLES );

		foreach ( array( 'wc_mercadopago_woocommerce_scripts', 'wc_mercadopago_health_monitor', 'wc_mercadopago_basic_blocks', 'wc_mercadopago_custom_blocks' ) as $se_queda ) {
			self::assertNotContains( $se_queda, $todos );
		}
		foreach ( $todos as $handle ) {
			self::assertDoesNotMatchRegularExpression( '/_blocks$/', $handle );
		}

		$bloques = (string) file_get_contents( dirname( __DIR__, 4 ) . '/akibara-cart-checkout-blocks/src/RuntimeCompatibility.php' );
		self::assertSame( 1, preg_match( '/MERCADO_PAGO_CLASSIC_HANDLES\s*=\s*array\((.*?)\);/s', $bloques, $m ), 'Fixture: la lista del plugin de bloques.' );
		preg_match_all( "/'([a-z0-9_]+)'/", $m[1], $suyos );
		self::assertCount( 15, $suyos[1], 'Fixture: los quince del plugin de bloques.' );
		// Su lista es de GUIONES: `wc_mercadopago_checkout_components` es allí un
		// guion y aquí la HOJA del mismo nombre (WordPress lleva las dos colas por
		// separado), así que se comparan sólo los guiones.
		self::assertSame( array(), array_values( array_intersect( LeanCart::SCRIPT_HANDLES, $suyos[1] ) ), 'Dos plugins retirando lo mismo es un parche que nadie sabe quién mantiene.' );

		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/SiteSpeed/LeanCart.php' );
		$fuente = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $fuente );
		self::assertStringNotContainsString( '->queue', $fuente, 'Nada de barrer la cola por prefijo.' );
		self::assertStringNotContainsString( '->registered', $fuente );
		self::assertStringNotContainsString( 'str_starts_with', $fuente );
	}

	/**
	 * Si Mercado Pago renombra un handle, el retiro deja de hacer nada sin que
	 * nadie se entere. Se comprueba contra el código del plugin instalado.
	 *
	 * @testdox Cada handle de la lista existe hoy en el código de Mercado Pago.
	 */
	public function test_cada_handle_existe_en_mercado_pago(): void {
		$raiz = dirname( __DIR__, 4 ) . '/woocommerce-mercadopago/src';
		self::assertDirectoryExists( $raiz, 'Fixture: Mercado Pago instalado en el árbol.' );

		$codigo   = '';
		$iterador = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $raiz, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterador as $fichero ) {
			if ( 'php' === $fichero->getExtension() ) {
				$codigo .= (string) file_get_contents( $fichero->getPathname() );
			}
		}

		foreach ( array_merge( LeanCart::SCRIPT_HANDLES, LeanCart::STYLE_HANDLES ) as $handle ) {
			self::assertMatchesRegularExpression( "/'" . preg_quote( $handle, '/' ) . "'/", $codigo, "Mercado Pago ya no declara «{$handle}»: el retiro sobra o hay que actualizarlo." );
		}
		self::assertStringContainsString( "define('MP_VERSION'", $codigo, 'La constante con la que se detecta el plugin.' );
	}
}
