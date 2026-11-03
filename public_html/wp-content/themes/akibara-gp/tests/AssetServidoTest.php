<?php
/**
 * Contrato ejecutable de lo que el tema SIRVE (auditoría del tema 2026-09-22).
 *
 *   - PC-1 = PM-4 + PC-7: la copia `.min` generada, salvo SCRIPT_DEBUG; si la
 *     copia falta, la fuente (más pesada, nunca sin estilos).
 *   - DS-10 = PC-5 = PM-13: woocommerce.css partida por contexto.
 *   - PC-15: empty-states.css en un listado sólo si la consulta vino vacía y
 *     el aviso es la plantilla del tema (no el de akibara-search).
 *
 * Se ejecuta `akibara_gp_enqueue()` con dobles de WordPress y se afirma sobre las
 * URLs que quedan encoladas, no sobre el texto del fichero.
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
final class AssetServidoTest extends TestCase {

	private const URI = 'https://akibara.test/wp-content/themes/akibara-gp';

	/** @var string Directorio del tema que ven las funciones. */
	private string $theme_dir = '';

	/** @var array<string, string> Hojas encoladas: handle => src. */
	private array $estilos = array();

	/** @var array<string, string> Guiones encolados o registrados: handle => src. */
	private array $guiones = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->theme_dir = dirname( __DIR__ );
		$this->estilos   = array();
		$this->guiones   = array();

		Functions\when( 'get_stylesheet_directory' )->alias(
			function (): string {
				return $this->theme_dir;
			}
		);
		Functions\when( 'get_stylesheet_directory_uri' )->justReturn( self::URI );
		Functions\when( 'get_template' )->justReturn( 'generatepress' );
		// El buscador apagado por defecto, en TODAS las pruebas. Una de ellas lo
		// define con Brain Monkey y, desde ahí, `function_exists()` da true para el
		// resto del proceso: con `--order-by=random`, las que corrían después
		// llamaban a una función sin doble y caían (semilla 1790296794, QF-gp).
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
		foreach ( array( 'wp_register_style', 'wp_add_inline_style' ) as $noop ) {
			Functions\when( $noop )->justReturn( true );
		}
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle, $src = '' ): void {
				$this->estilos[ $handle ] = (string) $src;
			}
		);
		$guion = function ( $handle, $src = '' ): bool {
			if ( '' !== (string) $src ) {
				$this->guiones[ $handle ] = (string) $src;
			}
			return true;
		};
		Functions\when( 'wp_register_script' )->alias( $guion );
		Functions\when( 'wp_enqueue_script' )->alias( $guion );

		require_once dirname( __DIR__ ) . '/inc/page-targets.php';
		require_once dirname( __DIR__ ) . '/inc/enqueue.php';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_query'] );
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
	 * @param int $resultados post_count de la consulta principal.
	 */
	private function consulta( int $resultados ): void {
		$GLOBALS['wp_query'] = (object) array( 'post_count' => $resultados );
	}

	/**
	 * Crea un tema de juguete con los ficheros pedidos y lo usa como directorio.
	 *
	 * @param string[] $ficheros Rutas relativas.
	 */
	private function tema_de_juguete( array $ficheros ): string {
		$dir = sys_get_temp_dir() . '/akb-servido-' . uniqid( '', true );
		foreach ( $ficheros as $rel ) {
			if ( ! is_dir( dirname( $dir . '/' . $rel ) ) ) {
				mkdir( dirname( $dir . '/' . $rel ), 0777, true );
			}
			file_put_contents( $dir . '/' . $rel, 'x' );
		}
		$this->theme_dir = $dir;
		return $dir;
	}

	/**
	 * @param string $dir Directorio a borrar.
	 */
	private function borrar( string $dir ): void {
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $dir );
	}

	/**
	 * @testdox Se sirve la copia .min cuando existe, y la fuente cuando falta.
	 */
	public function test_sirve_la_min_y_cae_a_la_fuente_si_falta(): void {
		$dir = $this->tema_de_juguete( array( 'assets/css/loop.css', 'assets/css/loop.min.css', 'assets/js/main.js' ) );

		self::assertSame( 'assets/css/loop.min.css', akibara_gp_asset_servido( 'assets/css/loop.css' ) );
		self::assertSame( 'assets/js/main.js', akibara_gp_asset_servido( 'assets/js/main.js' ), 'sin .min (despliegue a medias) va la fuente, nunca una URL rota' );
		self::assertSame(
			'assets/css/loop.css',
			akibara_gp_asset_servido( 'assets/css/loop.css', 'assets/css/no-existe.min.css' ),
			'una copia explícita que falta también cae a la fuente'
		);

		list( $src, $ver ) = akibara_gp_asset( 'assets/css/loop.css' );
		self::assertSame( self::URI . '/assets/css/loop.min.css', $src );
		self::assertSame( '2.1.142.' . filemtime( $dir . '/assets/css/loop.min.css' ), $ver, 'el ?ver es el del fichero SERVIDO' );

		$this->borrar( $dir );
	}

	/**
	 * @testdox Con SCRIPT_DEBUG se sirven las fuentes legibles.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_script_debug_sirve_las_fuentes(): void {
		define( 'SCRIPT_DEBUG', true );
		$dir = $this->tema_de_juguete( array( 'assets/css/loop.css', 'assets/css/loop.min.css', 'assets/css/woocommerce.css', 'assets/css/woocommerce-ficha.min.css' ) );

		self::assertTrue( akibara_gp_servir_fuentes() );
		self::assertSame( 'assets/css/loop.css', akibara_gp_asset_servido( 'assets/css/loop.css' ) );
		self::assertSame( 'assets/css/woocommerce.css', akibara_gp_asset_servido( 'assets/css/woocommerce.css', 'assets/css/woocommerce-ficha.min.css' ) );

		$this->borrar( $dir );
	}

	/**
	 * @testdox Cada página recibe su parte de woocommerce.css.
	 */
	public function test_woocommerce_css_partida_por_contexto(): void {
		$casos = array(
			'ficha'   => array( 'is_woocommerce' => true, 'is_product' => true ),
			'carrito' => array( 'is_cart' => true ),
			'cuenta'  => array( 'is_account_page' => true ),
			'listado' => array( 'is_woocommerce' => true, 'is_shop' => true ),
		);
		foreach ( $casos as $contexto => $ruta ) {
			$this->estilos = array();
			$this->ruta( $ruta );
			$this->consulta( 5 );

			self::assertSame( $contexto, akibara_gp_woocommerce_css_contexto() );
			akibara_gp_enqueue();
			self::assertSame(
				self::URI . '/assets/css/woocommerce-' . $contexto . '.min.css',
				strtok( $this->estilos['akibara-woocommerce'] ?? '', '?' ),
				"{$contexto}: se sirve su parte, no la hoja entera"
			);
		}

		// Checkout comparte parte con el carrito; la portada y el 404, con los listados.
		$this->ruta( array( 'is_checkout' => true ) );
		self::assertSame( 'carrito', akibara_gp_woocommerce_css_contexto() );
		$this->ruta( array( 'is_front_page' => true ) );
		self::assertSame( 'listado', akibara_gp_woocommerce_css_contexto() );
		$this->ruta( array( 'is_404' => true ) );
		self::assertSame( 'listado', akibara_gp_woocommerce_css_contexto() );
	}

	/**
	 * @testdox Las hojas y guiones propios se encolan minificados.
	 */
	public function test_hojas_y_guiones_del_tema_van_minificados(): void {
		$this->ruta( array( 'is_woocommerce' => true, 'is_product' => true ) );
		$this->consulta( 1 );

		akibara_gp_enqueue();

		foreach ( array( 'akibara-core', 'akibara-loop' ) as $handle ) {
			self::assertMatchesRegularExpression( '#\.min\.css\?ver=|\.min\.css$#', $this->estilos[ $handle ], "{$handle} va minificada" );
		}
		foreach ( array( 'akibara-wc-event-bridge', 'akibara-main', 'akibara-franja-inferior', 'akibara-sticky-atc', 'akibara-pdp-ajax-atc', 'akibara-atc-guard', 'akibara-animations', 'akibara-animations-loader' ) as $handle ) {
			self::assertArrayHasKey( $handle, $this->guiones );
			self::assertStringEndsWith( '.min.js', $this->guiones[ $handle ], "{$handle} va minificado" );
		}
		// GSAP ya viene minificado de su proveedor: no hay una segunda copia.
		self::assertStringEndsWith( '/assets/js/vendor/gsap.min.js', $this->guiones['gsap'] );
	}

	/**
	 * @testdox empty-states.css sólo en el listado sin resultados que pinta el tema (PC-15).
	 */
	public function test_empty_states_solo_donde_hay_estado_vacio(): void {
		$this->ruta( array( 'is_woocommerce' => true, 'is_shop' => true ) );
		$this->consulta( 24 );
		akibara_gp_enqueue();
		self::assertArrayNotHasKey( 'akibara-empty-states', $this->estilos, 'con resultados no aplica ni una regla y bloqueaba igual' );

		// Sin resultados y con el filtrado de akibara-search activo (lo normal): el
		// aviso es el `.akb-empty-state` del buscador (woocommerce.css), que además
		// retira no-products-found.php. Medido: 0 de las 31 reglas de la hoja
		// casaban en /tienda/ y /serie/…/ con `?_buscar=` vacío (rev-T4-3-D2).
		Functions\when( 'akibara_search_facets_active' )->justReturn( true );
		$this->estilos = array();
		$this->consulta( 0 );
		akibara_gp_enqueue();
		self::assertArrayNotHasKey( 'akibara-empty-states', $this->estilos, 'el aviso vacío es el del buscador: la hoja no aplicaba ni una regla' );

		// Con las facetas apagadas (fail-open) sale no-products-found.php del tema,
		// que sí la usa.
		Functions\when( 'akibara_search_facets_active' )->justReturn( false );
		$this->estilos = array();
		akibara_gp_enqueue();
		self::assertArrayHasKey( 'akibara-empty-states', $this->estilos, 'sin el buscador, el listado vacío pinta la plantilla del tema' );

		// 404, carrito y Mi cuenta la llevan siempre (el carrito, además, reserva el
		// alto del bloque mientras hidrata).
		foreach ( array( 'is_404', 'is_cart', 'is_account_page' ) as $condicional ) {
			$this->estilos = array();
			$this->ruta( array( $condicional => true ) );
			$this->consulta( 3 );
			akibara_gp_enqueue();
			self::assertArrayHasKey( 'akibara-empty-states', $this->estilos, "{$condicional}: lleva la hoja" );
		}

		// Sin consulta principal (nunca debería pasar) no se inventa un estado vacío.
		unset( $GLOBALS['wp_query'] );
		self::assertFalse( akibara_gp_consulta_principal_vacia() );
	}
}
