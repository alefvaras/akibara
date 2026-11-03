<?php
/**
 * Qué fichero del theme se SIRVE cuando lo encola akibara-business (T4, auditoría
 * del tema 2026-09-22; lote de cruce X1).
 *
 * Forms (forms.css, akibara-forms.js) y BrandsDirectory (brands.css,
 * akibara-brands-filter.js) encolan ficheros del theme. Hasta este lote pedían
 * siempre la FUENTE comentada: el build del theme no generaba su `.min` y, aunque
 * la hubiera generado, business no la pedía. akibara-brands-filter.js además va
 * fuera de LiteSpeed (`data-no-optimize`), así que nadie la minificaba nunca.
 *
 * Las pruebas se afirman sobre la URL y la versión que quedan ENCOLADAS, contra
 * el árbol real del theme (donde el build dejó las `.min`) y contra un theme de
 * juguete para los bordes (copia ausente, SCRIPT_DEBUG, helper del theme).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Utilities;

use Akibara\Business\Modules\BrandsDirectory\BrandsDirectory;
use Akibara\Business\Modules\Forms\Forms;
use Akibara\Business\Utilities\ThemeAssetVersion;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ThemeAssetVersionTest extends TestCase {

	private const URI = 'https://akibara.test/wp-content/themes/akibara-gp';

	/** @var string Directorio del theme que ven las funciones. */
	private string $theme_dir = '';

	/** @var array<string, array{0: string, 1: string}> Encolados: 'css:handle'|'js:handle' => [src, ver]. */
	private array $encolados = array();

	/** @var string[] Temas de juguete a borrar. */
	private array $juguetes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->theme_dir = dirname( __DIR__, 4 ) . '/themes/akibara-gp';
		$this->encolados = array();

		Functions\when( 'get_stylesheet_directory' )->alias(
			function (): string {
				return $this->theme_dir;
			}
		);
		Functions\when( 'get_stylesheet_directory_uri' )->justReturn( self::URI );
		// Hoja y guion pueden compartir handle (Forms usa `akibara-forms` para los dos).
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( string $handle, string $src = '', array $deps = array(), $ver = false ): void {
				$this->encolados[ 'css:' . $handle ] = array( $src, (string) $ver );
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( string $handle, string $src = '', array $deps = array(), $ver = false ): void {
				$this->encolados[ 'js:' . $handle ] = array( $src, (string) $ver );
			}
		);
	}

	protected function tearDown(): void {
		foreach ( $this->juguetes as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $f ) {
				$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
			}
			rmdir( $dir );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Theme de juguete con los ficheros pedidos (ruta => mtime).
	 *
	 * @param array<string, int> $ficheros Rutas relativas y su mtime.
	 */
	private function tema_de_juguete( array $ficheros ): void {
		$dir = sys_get_temp_dir() . '/akb-theme-asset-' . uniqid( '', true );
		foreach ( $ficheros as $rel => $mtime ) {
			if ( ! is_dir( dirname( $dir . '/' . $rel ) ) ) {
				mkdir( dirname( $dir . '/' . $rel ), 0777, true );
			}
			file_put_contents( $dir . '/' . $rel, 'x' );
			touch( $dir . '/' . $rel, $mtime );
		}
		$this->juguetes[] = $dir;
		$this->theme_dir  = $dir;
	}

	/**
	 * @testdox Se sirve la copia .min cuando existe (con o sin barra inicial) y la fuente cuando falta.
	 */
	public function test_sirve_la_min_y_cae_a_la_fuente_si_falta(): void {
		$this->tema_de_juguete(
			array(
				'assets/css/forms.css'     => 1700000000,
				'assets/css/forms.min.css' => 1700000100,
				'assets/js/akibara-forms.js' => 1700000000,
			)
		);

		self::assertSame( 'assets/css/forms.min.css', ThemeAssetVersion::served_path( 'assets/css/forms.css' ) );
		self::assertSame( 'assets/css/forms.min.css', ThemeAssetVersion::served_path( '/assets/css/forms.css' ) );
		self::assertSame( 'assets/js/akibara-forms.js', ThemeAssetVersion::served_path( 'assets/js/akibara-forms.js' ), 'Sin copia (despliegue a medias) se sirve la fuente, nunca un 404.' );
		self::assertSame( 'assets/img/logo.svg', ThemeAssetVersion::served_path( 'assets/img/logo.svg' ), 'Sólo .css y .js tienen copia.' );
	}

	/**
	 * @testdox La versión se calcula sobre el fichero servido: regenerar la .min invalida aunque la fuente no cambie.
	 */
	public function test_la_version_es_la_del_fichero_servido(): void {
		$this->tema_de_juguete(
			array(
				'assets/css/brands.css'     => 1700000000,
				'assets/css/brands.min.css' => 1700000555,
			)
		);

		$servido = ThemeAssetVersion::served_path( 'assets/css/brands.css' );
		$min     = filemtime( $this->theme_dir . '/assets/css/brands.min.css' );

		// Bajo Patchwork (su envoltorio de ficheros) touch() puede no dejar el segundo
		// exacto pedido: se compara contra lo que quedó en disco, que es lo que ve PHP.
		self::assertNotSame( filemtime( $this->theme_dir . '/assets/css/brands.css' ), $min );
		// Sin wp_get_theme en la suite la versión del theme cae a «1» (fallback de for_path()).
		self::assertSame( '1.' . $min, ThemeAssetVersion::for_path( $servido ) );
	}

	/**
	 * @testdox BrandsDirectory encola brands.min.css y akibara-brands-filter.min.js del árbol real, versionados por la copia.
	 */
	public function test_el_directorio_de_editoriales_encola_las_copias_min(): void {
		Functions\when( 'get_terms' )->justReturn( array( 1, 2, 3, 4, 5, 6 ) );

		( new BrandsDirectory() )->enqueue_assets();

		self::assertSame( self::URI . '/assets/css/brands.min.css', $this->encolados['css:akibara-brands'][0] ?? null );
		self::assertSame( self::URI . '/assets/js/akibara-brands-filter.min.js', $this->encolados['js:akibara-brands-filter'][0] ?? null );
		self::assertSame( '1.' . filemtime( $this->theme_dir . '/assets/css/brands.min.css' ), $this->encolados['css:akibara-brands'][1] );
		self::assertSame( '1.' . filemtime( $this->theme_dir . '/assets/js/akibara-brands-filter.min.js' ), $this->encolados['js:akibara-brands-filter'][1] );
	}

	/**
	 * @testdox El formulario de contacto encola forms.min.css y akibara-forms.min.js del árbol real, con su configuración.
	 */
	public function test_el_formulario_de_contacto_encola_las_copias_min(): void {
		$localizado = array();
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => '[akb_form_contacto]' ) );
		Functions\when( 'has_shortcode' )->justReturn( true );
		Functions\when( 'rest_url' )->alias( static fn( string $path ): string => 'https://akibara.test/wp-json/' . $path );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_option' )->alias( static fn( $option, $default_value = false ) => $default_value );
		Functions\when( 'wp_localize_script' )->alias(
			static function ( string $handle, string $objeto ) use ( &$localizado ): bool {
				$localizado[] = $handle . ':' . $objeto;
				return true;
			}
		);

		( new Forms() )->maybe_enqueue_assets();

		self::assertSame( self::URI . '/assets/css/forms.min.css', $this->encolados['css:akibara-forms'][0] ?? null );
		self::assertSame( self::URI . '/assets/js/akibara-forms.min.js', $this->encolados['js:akibara-forms'][0] ?? null );
		self::assertSame( '1.' . filemtime( $this->theme_dir . '/assets/css/forms.min.css' ), $this->encolados['css:akibara-forms'][1] );
		self::assertSame( '1.' . filemtime( $this->theme_dir . '/assets/js/akibara-forms.min.js' ), $this->encolados['js:akibara-forms'][1] );
		self::assertSame( array( 'akibara-forms:AkibaraFormsConfig' ), $localizado, 'La configuración sigue colgando del mismo handle.' );
	}

	/**
	 * Una constante contamina el proceso: PHP no permite redefinirla.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testdox Con SCRIPT_DEBUG se sirve la fuente legible, versionada por la fuente.
	 */
	public function test_con_script_debug_se_sirve_la_fuente(): void {
		define( 'SCRIPT_DEBUG', true );
		Functions\when( 'get_terms' )->justReturn( array( 1, 2, 3, 4, 5, 6 ) );

		( new BrandsDirectory() )->enqueue_assets();

		self::assertSame( self::URI . '/assets/css/brands.css', $this->encolados['css:akibara-brands'][0] ?? null );
		self::assertSame( self::URI . '/assets/js/akibara-brands-filter.js', $this->encolados['js:akibara-brands-filter'][0] ?? null );
		self::assertSame( '1.' . filemtime( $this->theme_dir . '/assets/css/brands.css' ), $this->encolados['css:akibara-brands'][1] );
	}

	/**
	 * Una función global contamina el proceso: sólo se declara aquí dentro.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testdox Con el theme akibara-gp activo decide su helper akibara_gp_asset_servido(), no la copia local de la regla.
	 */
	public function test_con_el_theme_activo_decide_su_helper(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- doble del helper del theme, sólo en este proceso.
		eval( 'function akibara_gp_asset_servido( string $rel_path, string $min_path = "" ): string { return "assets/servida-por-el-theme/" . basename( $rel_path ); }' );

		self::assertSame( 'assets/servida-por-el-theme/forms.css', ThemeAssetVersion::served_path( '/assets/css/forms.css' ) );
	}
}
