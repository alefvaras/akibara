<?php
/**
 * WooCommerce cree que «/lo-que-sea/wp-json/x» es una petición REST y no carga
 * la sesión; WordPress la renderiza como 404 del tema, y Google for WooCommerce
 * muere en wp_head leyendo WC()->session. Medido en producción el 2026-09-12
 * (500 en vez de 404; cuatro fatales entre el 3 y el 12 de septiembre).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class WooRestSoloEnLaRaizTest extends TestCase {

	private const MU_PLUGIN = '/mu-plugins/akibara-woocommerce-rest-solo-en-la-raiz.php';

	/** @var array<string, mixed> */
	private array $server_original;

	/** @var array<string, mixed> */
	private array $get_original;

	/** @var int|false Prioridad con la que el fichero enganchó su filtro al cargarse. */
	private static $prioridad_enganchada = false;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}
		// El fichero se carga UNA vez (declara funciones); lo que enganchó al
		// cargarse se mira aquí, antes de que tearDown limpie los hooks.
		Monkey\setUp();
		require_once dirname( __DIR__, 4 ) . self::MU_PLUGIN;
		self::$prioridad_enganchada = has_filter( 'woocommerce_is_rest_api_request', 'akibara_woo_rest_solo_en_la_raiz' );
		Monkey\tearDown();
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->server_original = $_SERVER;
		$this->get_original    = $_GET;
		unset( $_GET['rest_route'] );

		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ) => wp_parse_url_double( $url, $component ) );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.cl' . $path );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $v ): string => trim( $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_original;
		$_GET    = $this->get_original;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Lo que empieza por /wp-json/ sigue siendo API.
	 * @dataProvider rutas_de_la_api
	 */
	public function test_lo_que_empieza_por_wp_json_sigue_siendo_api( string $uri ): void {
		$_SERVER['REQUEST_URI'] = $uri;

		self::assertTrue( akibara_woo_rest_solo_en_la_raiz( true ), $uri );
	}

	/** @return array<string, array{0: string}> */
	public static function rutas_de_la_api(): array {
		return array(
			'productos wc/v3'    => array( '/wp-json/wc/v3/products?per_page=10' ),
			'índice'             => array( '/wp-json' ),
			'índice con barra'   => array( '/wp-json/' ),
			'suggest del buscador' => array( '/wp-json/akibara/v1/search/suggest?q=demon' ),
			'con index.php'      => array( '/index.php/wp-json/wc/store/v1/cart' ),
		);
	}

	/**
	 * @testdox «wp-json/» en medio de la ruta NO es API: es una página del sitio (la 404 del tema).
	 * @dataProvider rutas_del_sitio_que_contienen_wp_json
	 */
	public function test_wp_json_en_medio_de_la_ruta_no_es_api( string $uri ): void {
		$_SERVER['REQUEST_URI'] = $uri;

		self::assertFalse( akibara_woo_rest_solo_en_la_raiz( true ), $uri );
	}

	/** @return array<string, array{0: string}> */
	public static function rutas_del_sitio_que_contienen_wp_json(): array {
		return array(
			'la que fatalea en prod' => array( '/akbqa-no-existe/wp-json/x' ),
			'bajo una ficha'         => array( '/demon-slayer-kimetsu-no-yaiba-23-ivrea-argentina/wp-json/' ),
			'prefijo parecido'       => array( '/wp-jsonx/' ),
			'como query'             => array( '/serie/akira/?ref=/wp-json/' ),
		);
	}

	/** @testdox ?rest_route= se respeta en cualquier ruta: WordPress sí la atiende ahí. */
	public function test_rest_route_por_query_se_respeta(): void {
		$_SERVER['REQUEST_URI'] = '/no-existe/?rest_route=/wc/v3/products';
		$_GET['rest_route']     = '/wc/v3/products';

		self::assertTrue( akibara_woo_rest_solo_en_la_raiz( true ) );
	}

	/** @testdox Lo que WooCommerce ya no consideraba API no cambia. */
	public function test_el_no_de_woocommerce_no_cambia(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/products';

		self::assertFalse( akibara_woo_rest_solo_en_la_raiz( false ) );
	}

	/** @testdox Engancha el filtro de WooCommerce al cargarse. */
	public function test_engancha(): void {
		self::assertSame( 10, self::$prioridad_enganchada );
	}

	/**
	 * @testdox Muere con el tercero: el core instalado sigue comparando por subcadena; cuando lo arregle, este mu-plugin sobra.
	 */
	public function test_el_core_instalado_sigue_comparando_por_subcadena(): void {
		$core = (string) file_get_contents( dirname( __DIR__, 3 ) . '/woocommerce/includes/class-woocommerce.php' );

		self::assertStringContainsString(
			"strpos( \$_SERVER['REQUEST_URI'], \$rest_prefix )",
			$core,
			'WooCommerce ya no clasifica la petición por subcadena: retira akibara-woocommerce-rest-solo-en-la-raiz.php y esta prueba.'
		);
	}
}

/**
 * Doble de wp_parse_url para la suite: el core no está cargado.
 *
 * @param string $url       URL.
 * @param int    $component Componente PHP_URL_*.
 * @return mixed
 */
function wp_parse_url_double( string $url, int $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- es el doble de la propia wp_parse_url.
}
