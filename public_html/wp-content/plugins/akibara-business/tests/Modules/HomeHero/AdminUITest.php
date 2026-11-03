<?php
/**
 * Tests for the HomeHero admin preview shell.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\HomeHero;

use Akibara\Business\Modules\HomeHero\AdminUI;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );
defined( 'AKIBARA_BUSINESS_URL' ) || define( 'AKIBARA_BUSINESS_URL', 'https://example.test/wp-content/plugins/akibara-business/' );
defined( 'AKIBARA_BUSINESS_VERSION' ) || define( 'AKIBARA_BUSINESS_VERSION', 'test-version' );

final class AdminUITest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'get_stylesheet_directory' )->justReturn( '/nonexistent-theme' );
		Functions\when( 'get_stylesheet_directory_uri' )->justReturn( 'https://example.test/theme' );
		// Doble de core para TODAS las pruebas de la clase: una vez declarada por
		// Brain Monkey la función existe en el proceso, y una prueba que no la
		// dobla moriría al llamarla (function_exists() miente en la suite).
		Functions\when( 'wp_print_font_faces' )->alias(
			static function (): void {
				echo "<style class='wp-fonts-local'>@font-face{font-family:\"Bebas Neue\";src:url('bebas.woff2')}</style>";
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_preview_iframe_versions_home_hero_assets_by_plugin_version_and_mtime(): void {
		$method = new ReflectionMethod( AdminUI::class, 'preview_wrap_html' );
		$method->setAccessible( true );

		$html = (string) $method->invoke( new AdminUI(), '<section data-preview-fixture></section>' );

		$css_version = AKIBARA_BUSINESS_VERSION . '.' . filemtime( AKIBARA_BUSINESS_DIR . 'assets/css/home-hero.css' );
		$js_version  = AKIBARA_BUSINESS_VERSION . '.' . filemtime( AKIBARA_BUSINESS_DIR . 'assets/js/home-hero.js' );

		self::assertStringContainsString( 'assets/css/home-hero.css?ver=' . $css_version, $html );
		self::assertStringContainsString( 'assets/js/home-hero.js?ver=' . $js_version, $html );
		self::assertStringContainsString( '<section data-preview-fixture></section>', $html );
	}

	/**
	 * PC-4 (auditoría del tema 2026-09-22): Bebas Neue se declara sólo en el
	 * theme.json del theme. El srcdoc de la vista previa no pasa por wp_head, así
	 * que imprime él mismo las @font-face de theme.json, como el front; si no, el
	 * título del hero caería a Impact en la vista previa.
	 *
	 * @testdox La vista previa del hero imprime las @font-face de theme.json en su head, antes de las hojas del theme.
	 */
	public function test_preview_prints_theme_json_font_faces(): void {
		$method = new ReflectionMethod( AdminUI::class, 'preview_wrap_html' );
		$method->setAccessible( true );

		$html = (string) $method->invoke( new AdminUI(), '<section data-preview-fixture></section>' );
		$head = (string) strstr( $html, '</head>', true );

		self::assertStringContainsString( "<style class='wp-fonts-local'>@font-face{font-family:\"Bebas Neue\"", $head );
		self::assertLessThan( strpos( $head, 'home-hero.css' ), strpos( $head, 'wp-fonts-local' ), 'Las caras van antes de las hojas, como en el front.' );
	}

	public function test_number_inputs_accept_existing_non_multiple_values(): void {
		$source = (string) file_get_contents(
			AKIBARA_BUSINESS_DIR . 'templates/admin/home-hero/slide-fieldset.php'
		) . (string) file_get_contents(
			AKIBARA_BUSINESS_DIR . 'templates/admin/home-hero/carousel-fieldset.php'
		);

		self::assertMatchesRegularExpression(
			'/name="slides\\[<\\?php echo \\(int\\) \\$idx; \\?>\\]\\[vignette_opacity\\]"[^\\r\\n]+step="1"/',
			$source
		);
		self::assertMatchesRegularExpression(
			'/name="carousel\\[hero_height_custom_mobile\\]"[^\\r\\n]+step="1"/',
			$source
		);
	}

	public function test_url_fields_allow_environment_portable_relative_paths(): void {
		$source = (string) file_get_contents(
			AKIBARA_BUSINESS_DIR . 'src/Modules/HomeHero/AdminUI.php'
		) . (string) file_get_contents(
			AKIBARA_BUSINESS_DIR . 'templates/admin/home-hero/slide-fieldset.php'
		);

		self::assertStringNotContainsString( '<input type="url"', $source );
		self::assertMatchesRegularExpression(
			'/<input type="text" inputmode="url" autocomplete="url" name="slides\\[/',
			$source
		);
	}

	/**
	 * El gate del enqueue: sólo la pestaña del hero carga sus assets.
	 *
	 * Hasta 1.108.11 el guard era `'home_hero' !== $tab && '' !== $tab`, o sea
	 * que el tab VACÍO —la pantalla por defecto del panel, que es «Dashboard»—
	 * también cargaba `wp_enqueue_media()` (29 scripts, 627 KB) más los 72 KB
	 * del editor del hero. El dueño pagaba ~700 KB cada vez que abría el panel
	 * desde el móvil, en una pantalla donde nada de eso se usa.
	 *
	 * @dataProvider enqueueGateCases
	 */
	public function test_hero_assets_load_only_on_their_own_tab( ?string $tab, string $hook, bool $expected ): void {
		$loaded = false;
		Functions\when( 'sanitize_key' )->alias( static fn( string $v ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $v ) ?? '' ) );
		Functions\when( 'wp_enqueue_media' )->alias( static function () use ( &$loaded ): void { $loaded = true; } );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'rest_url' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_parse_args' )->alias( static fn( $a, $b = array() ) => array_merge( (array) $b, (array) $a ) );

		if ( null === $tab ) {
			unset( $_GET['tab'] );
		} else {
			$_GET['tab'] = $tab;
		}

		$buffers = ob_get_level();
		try {
			( new AdminUI() )->enqueue_admin_assets( $hook );
		} catch ( \Throwable $exception ) {
			// El contrato bajo prueba es el GATE, no el render completo: pasado el
			// guard, el método sigue hacia el editor y pide media docena de
			// funciones de wp-admin que este doble no simula. Si la ejecución
			// llegó hasta ahí, el gate ya dejó pasar — que es justo lo que se
			// afirma abajo con `$loaded`. Un fallo del gate retorna ANTES y no
			// lanza nada.
			unset( $exception );
		} finally {
			// La excepción puede dejar abierto un buffer del código bajo prueba;
			// sin esto PHPUnit marca el test como «risky» y el gate corre con
			// failOnRisky="true".
			while ( ob_get_level() > $buffers ) {
				ob_end_clean();
			}
		}
		unset( $_GET['tab'] );

		self::assertSame( $expected, $loaded );
	}

	/** @return array<string, array{0: ?string, 1: string, 2: bool}> */
	public static function enqueueGateCases(): array {
		return array(
			'pestaña del hero'          => array( 'home_hero', 'toplevel_page_akibara-panel', true ),
			'pestaña por defecto (sin tab)' => array( null, 'toplevel_page_akibara-panel', false ),
			'tab vacío explícito'       => array( '', 'toplevel_page_akibara-panel', false ),
			'otra pestaña del panel'    => array( 'announcement', 'toplevel_page_akibara-panel', false ),
			'fuera del panel'           => array( 'home_hero', 'edit.php', false ),
		);
	}
}
