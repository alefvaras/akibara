<?php
/**
 * Contrato ejecutable del cache-busting de assets.
 *
 * La cobertura de `akibara_gp_asset_ver()` era `asset-version.test.js`, que hace
 * `assert.match` sobre el TEXTO de `inc/enqueue.php`. Entre sus aserciones había
 * una —`doesNotMatch(enqueue, /2\.1\.30\./)`— que clavaba una versión de 2026 que
 * el tema dejó atrás hace ~80 releases: no podía volver a fallar, así que no
 * frenaba nada. Aquí se ejecuta la función y se afirma sobre lo que devuelve.
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
final class AssetVersionTest extends TestCase {

	private const THEME_VERSION = '2.1.112';

	/** @var string Raíz temporal que hace de directorio del tema. */
	private string $theme_dir = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->theme_dir = sys_get_temp_dir() . '/akb-asset-ver-' . uniqid( '', true );
		mkdir( $this->theme_dir . '/assets/css', 0777, true );

		Functions\when( 'get_stylesheet_directory' )->justReturn( $this->theme_dir );
		Functions\when( 'wp_get_theme' )->justReturn(
			new class() {
				/**
				 * @param string $header Cabecera pedida.
				 * @return string
				 */
				public function get( $header ) {
					return 'Version' === $header ? AssetVersionTest::theme_version() : '';
				}
			}
		);

		// `inc/enqueue.php` registra sus hooks al cargarse; Brain Monkey intercepta
		// add_action/add_filter, así que el require es seguro e idempotente.
		require_once dirname( __DIR__ ) . '/inc/enqueue.php';
	}

	protected function tearDown(): void {
		$this->remove_tree( $this->theme_dir );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Expuesto para la clase anónima del doble de `wp_get_theme()`.
	 *
	 * @return string
	 */
	public static function theme_version(): string {
		return self::THEME_VERSION;
	}

	/**
	 * Crea un asset con el mtime pedido y devuelve su ruta relativa.
	 *
	 * @param string $rel   Ruta relativa dentro del tema.
	 * @param int    $mtime Marca de tiempo a fijar.
	 * @return string
	 */
	private function write_asset( string $rel, int $mtime ): string {
		$absolute = $this->theme_dir . '/' . $rel;
		file_put_contents( $absolute, 'body{color:#fff}' );
		touch( $absolute, $mtime );
		// `file_put_contents()` deja el mtime de la escritura en la caché de stat
		// de PHP y `touch()` no la invalida: sin esto, `filemtime()` devuelve el
		// valor viejo y la prueba mide su propia caché, no el fichero.
		clearstatcache( true, $absolute );

		return $rel;
	}

	/**
	 * @param string $path Ruta a borrar.
	 */
	private function remove_tree( string $path ): void {
		if ( '' === $path || ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			$child = $path . '/' . $entry;
			is_dir( $child ) ? $this->remove_tree( $child ) : unlink( $child );
		}
		rmdir( $path );
	}

	/**
	 * El mtime esperado se lee del disco en vez de repetir el literal que se pidió
	 * al escribir: lo que se afirma es la REGLA (versión + mtime real), no la
	 * capacidad del sistema de ficheros de conservar el segundo exacto.
	 *
	 * @testdox El token es la versión del tema más el mtime del fichero.
	 */
	public function test_token_joins_theme_version_and_file_mtime(): void {
		$rel   = $this->write_asset( 'assets/css/base.css', 1750000000 );
		$mtime = filemtime( $this->theme_dir . '/' . $rel );

		self::assertSame( self::THEME_VERSION . '.' . $mtime, akibara_gp_asset_ver( $rel ) );
		self::assertStringStartsWith( self::THEME_VERSION . '.', akibara_gp_asset_ver( $rel ) );
	}

	/**
	 * ESTA es la conducta que la aserción muerta pretendía cubrir: que tocar el
	 * fichero cambie el token que se sirve.
	 *
	 * @testdox Cambiar el fichero cambia el token; dejarlo quieto lo conserva.
	 */
	public function test_touching_the_file_moves_the_token(): void {
		$rel    = $this->write_asset( 'assets/css/base.css', 1750000000 );
		$antes  = akibara_gp_asset_ver( $rel );
		$otra   = akibara_gp_asset_ver( $rel );

		self::assertSame( $antes, $otra, 'Sin cambios en disco el token no puede moverse.' );

		touch( $this->theme_dir . '/' . $rel, 1750000001 );
		clearstatcache( true, $this->theme_dir . '/' . $rel );

		self::assertNotSame(
			$antes,
			akibara_gp_asset_ver( $rel ),
			'Un asset editado tiene que servirse con un `?ver=` nuevo.'
		);
	}

	/**
	 * @testdox Dos ficheros con mtimes distintos se invalidan por separado.
	 */
	public function test_each_file_carries_its_own_token(): void {
		$uno = $this->write_asset( 'assets/css/base.css', 1750000000 );
		$dos = $this->write_asset( 'assets/css/loop.css', 1760000000 );

		self::assertNotSame( akibara_gp_asset_ver( $uno ), akibara_gp_asset_ver( $dos ) );
	}

	/**
	 * Durante un despliegue incompleto el `<link>` tiene que salir igual, con la
	 * versión del tema como único token, en vez de fatalear por `filemtime()`.
	 *
	 * @testdox Un asset que no existe cae a la versión del tema, sin sufijo.
	 */
	public function test_missing_asset_falls_back_to_the_theme_version(): void {
		self::assertSame( self::THEME_VERSION, akibara_gp_asset_ver( 'assets/css/no-existe.css' ) );
	}

	/**
	 * @testdox La ruta se acepta con o sin barra inicial.
	 */
	public function test_leading_slash_is_tolerated(): void {
		$rel = $this->write_asset( 'assets/css/base.css', 1750000000 );

		self::assertSame( akibara_gp_asset_ver( $rel ), akibara_gp_asset_ver( '/' . $rel ) );
	}

	/* --- Compactado del CSS crítico ------------------------------------ */

	/**
	 * El bloque crítico viaja INLINE en cada documento HTML del sitio, así que
	 * sus comentarios se pagan en todas las respuestas: 3.646 de 5.322 bytes.
	 *
	 * @testdox Los comentarios y el espacio sobrante no viajan en el HTML.
	 */
	public function test_comments_and_whitespace_are_dropped(): void {
		$css = "/* Un comentario largo que explica la cascada */\n"
			. ".site-header {\n\tposition: sticky;\n\ttop: 0;\n}\n";

		$compacto = akibara_gp_compact_css( $css );

		self::assertStringNotContainsString( 'comentario', $compacto );
		self::assertStringNotContainsString( "\n", $compacto );
		self::assertLessThan( strlen( $css ), strlen( $compacto ) );
	}

	/**
	 * Lo que un minificador casero rompe es la cascada. Se comprueba sobre la
	 * hoja REAL: mismas reglas, mismos selectores, llaves balanceadas.
	 *
	 * @testdox El CSS crítico real conserva todas sus reglas y selectores.
	 */
	public function test_real_critical_sheet_survives_intact(): void {
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/critical.css' );
		self::assertNotSame( '', $css, 'La hoja crítica tiene que existir para poder compactarla.' );

		$compacto = akibara_gp_compact_css( $css );

		self::assertSame(
			substr_count( $compacto, '{' ),
			substr_count( $compacto, '}' ),
			'Las llaves quedaron desbalanceadas: el compactado se comió una regla.'
		);

		// Los selectores del primer paint —fondo oscuro y drawers cerrados— son
		// la razón de que este bloque exista.
		foreach ( array( 'html', 'body', '.site-header', '.akb-cart-drawer' ) as $selector ) {
			self::assertStringContainsString( $selector, $compacto );
		}

		// Cada declaración `propiedad: valor;` del original sobrevive. La esperada
		// se pasa por el MISMO compactador, que es lo único que puede tocarle el
		// espaciado: así la prueba compara contenido, no formato.
		preg_match_all( '/[a-z-]+\s*:\s*[^;{}]+;/', (string) preg_replace( '#/\*.*?\*/#s', '', $css ), $matches );
		self::assertNotEmpty( $matches[0] );
		foreach ( $matches[0] as $declaracion ) {
			self::assertStringContainsString(
				akibara_gp_compact_css( $declaracion ),
				$compacto,
				sprintf( 'Se perdió la declaración «%s».', trim( $declaracion ) )
			);
		}
	}

	/**
	 * @testdox Compactar dos veces da lo mismo que compactar una.
	 */
	public function test_compacting_is_idempotent(): void {
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/critical.css' );

		$una = akibara_gp_compact_css( $css );

		self::assertSame( $una, akibara_gp_compact_css( $una ) );
	}

	/**
	 * R8-css-vacio (2026-09-20): el `style.css` del child es pura cabecera y
	 * GeneratePress lo encolaba igual —una petición por página a un fichero que
	 * LiteSpeed minificaba a 0 bytes—. Se apaga con el interruptor del padre.
	 * El enganche vive a nivel de fichero y el require es único por proceso:
	 * se contrasta el fuente.
	 *
	 * @testdox The parent's switch for the child stylesheet is off and the old cache-bust is gone.
	 */
	public function test_the_empty_child_stylesheet_is_switched_off(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__ ) . '/inc/enqueue.php' );

		self::assertStringContainsString( "add_filter( 'generate_load_child_theme_stylesheet', '__return_false' );", $fuente );
		self::assertStringNotContainsString( 'akibara_gp_fix_child_style_version', $fuente, 'El cache-bust del child murió con el <link>: cero código muerto.' );
	}

	/**
	 * La trampa de mantenimiento del caso anterior: si algún día alguien
	 * escribe reglas en `style.css`, no cargarían y nada avisaría. Este test
	 * es el aviso.
	 *
	 * @testdox The child style.css stays header-only, because it is no longer served.
	 */
	public function test_the_child_style_css_has_no_rules(): void {
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/style.css' );
		$sin_cabecera = trim( (string) preg_replace( '#/\*.*?\*/#s', '', $css ) );

		self::assertStringContainsString( 'Theme Name:', $css );
		self::assertSame( '', $sin_cabecera, 'style.css del child ya no se sirve: las reglas van en assets/css/.' );
	}
}
