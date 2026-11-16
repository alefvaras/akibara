<?php
/**
 * Candado de la identidad que llms.txt le declara a los agentes de IA.
 *
 * Dos trampas que este archivo fija:
 *
 *   1. La pestaña «Salud SEO» nació como ACTION SECTION (sin formulario). Con
 *      `is_action = true`, `AdminPanel::handle_save()` vuelve temprano: el campo
 *      se pinta, el dueño escribe, aprieta «Guardar» y no pasa NADA.
 *   2. El texto viaja al bloque `>` del manifiesto. Sanearlo con el normalizador
 *      de enlaces habría escapado los paréntesis de «Flow (Webpay)» y cambiado
 *      el archivo servido el mismo día que se estrenó la pantalla.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\LlmsManifest;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\LlmsManifest\LlmsManifest;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class LlmsIdentitySettingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['akibara_test_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		parent::tearDown();
	}

	/**
	 * @testdox Sin nada guardado, llms.txt declara la identidad de siempre — paréntesis incluidos
	 */
	public function test_the_default_identity_is_served_verbatim(): void {
		$texto = LlmsManifest::render( array(), array(), false );
		$lines = explode( "\n", $texto );

		self::assertSame(
			'> Tienda online chilena especializada en manga y cómics originales en español, con tomos disponibles e importación directa. Despacho a todo Chile y retiro coordinado en Metro San Miguel, Santiago. Pagos con Mercado Pago y Flow (Webpay).',
			$lines[2]
		);
		self::assertStringNotContainsString( '\\(Webpay\\)', $texto, 'El resumen es prosa: escapar sus paréntesis cambiaría el archivo servido.' );
	}

	/**
	 * @testdox La identidad guardada es la que leen los agentes
	 */
	public function test_a_saved_identity_reaches_the_manifest(): void {
		$GLOBALS['akibara_test_options'][ LlmsManifest::OPTION_IDENTITY ] = 'Librería chilena de manga con retiro en Providencia.';

		$lines = explode( "\n", LlmsManifest::render( array(), array(), false ) );

		self::assertSame( '> Librería chilena de manga con retiro en Providencia.', $lines[2] );
	}

	/**
	 * @testdox Una identidad en blanco vuelve a la de fábrica: el formato exige el resumen
	 */
	public function test_a_blank_identity_falls_back(): void {
		$GLOBALS['akibara_test_options'][ LlmsManifest::OPTION_IDENTITY ] = "  \n ";

		self::assertSame( LlmsManifest::DEFAULT_IDENTITY, LlmsManifest::identity() );
	}

	/**
	 * @testdox Los saltos de línea se aplanan al guardar: el blockquote es UNA línea
	 */
	public function test_saving_flattens_the_text_to_one_line(): void {
		$modulo = new LlmsManifest();

		$guardado = $modulo->sanitize_identity(
			'',
			array(),
			"Tienda de manga.\n\nRetiro en San Miguel.\t Pagos con Flow (Webpay)."
		);

		self::assertSame( 'Tienda de manga. Retiro en San Miguel. Pagos con Flow (Webpay).', $guardado );
		self::assertSame( LlmsManifest::DEFAULT_IDENTITY, $modulo->sanitize_identity( '', array(), '   ' ) );
		self::assertSame( LlmsManifest::DEFAULT_IDENTITY, $modulo->sanitize_identity( '', array(), array( 'x' ) ) );
	}

	/**
	 * @testdox La constante AKB_LLMS_IDENTITY gobierna la identidad por encima de cualquier otro valor
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_governs_identity(): void {
		define( 'AKB_LLMS_IDENTITY', 'Akibara Comics y Manga en wp-config.' );
		self::assertSame( 'Akibara Comics y Manga en wp-config.', LlmsManifest::identity() );
	}

	/**
	 * @testdox La ruta administrativa tab=salud_seo está retirada y redirige 302 al panel principal
	 */
	public function test_salud_seo_tab_redirects_to_main_panel(): void {
		Monkey\setUp();
		try {
			$_GET = array(
				'page' => 'akibara-panel',
				'tab'  => LlmsManifest::PANEL_TAB_ID,
			);

			Functions\when( 'is_admin' )->justReturn( true );
			Functions\when( 'wp_doing_ajax' )->justReturn( false );
			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'sanitize_key' )->alias( static fn( string $v ): string => strtolower( $v ) );
			Functions\when( 'wp_unslash' )->returnArg( 1 );
			Functions\when( 'admin_url' )->alias(
				static fn( string $path = '' ): string => 'https://akibara.test/wp-admin/' . $path
			);

			$redirect_target = null;
			Functions\when( 'wp_safe_redirect' )->alias(
				function ( string $url ) use ( &$redirect_target ): void {
					$redirect_target = $url;
					throw new \RuntimeException( 'REDIRECT_EXECUTED' );
				}
			);

			$panel = new AdminPanel();
			try {
				$panel->redirect_retired_routes();
				self::fail( 'Debió lanzar REDIRECT_EXECUTED' );
			} catch ( \RuntimeException $e ) {
				self::assertSame( 'REDIRECT_EXECUTED', $e->getMessage() );
			}

			self::assertSame( 'https://akibara.test/wp-admin/admin.php?page=akibara-panel', $redirect_target );
		} finally {
			$_GET = array();
			Monkey\tearDown();
		}
	}

	/**
	 * @testdox Al actualizar el plugin se asegura que drop_identity_autoload esté enganchado.
	 */
	public function test_la_migracion_se_engancha_a_la_actualizacion(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/LlmsManifest/LlmsManifest.php' );

		self::assertStringContainsString( "add_action( 'akibara_business_upgraded', array( self::class, 'drop_identity_autoload' ), 10, 0 );", $fuente );
	}

	/**
	 * SRV-4 (verificación de producción 2026-09-26): en producción la fila viaja
	 * con autoload = on y sólo la leen la cola de llms.txt y el panel.
	 *
	 * @testdox Al actualizar el plugin, la identidad sale de alloptions; si ya estaba fuera, no escribe.
	 */
	public function test_al_actualizar_la_identidad_sale_de_alloptions(): void {
		Monkey\setUp();
		try {
			$llamadas = array();
			$fuera    = false;
			Functions\when( 'wp_set_option_autoload' )->alias(
				static function ( string $option, $autoload ) use ( &$llamadas, &$fuera ): bool {
					$llamadas[] = array( $option, $autoload );
					// Como el núcleo: sólo devuelve true cuando cambió algo.
					$cambia = ! $fuera;
					$fuera  = true;
					return $cambia;
				}
			);

			self::assertTrue( LlmsManifest::drop_identity_autoload() );
			self::assertFalse( LlmsManifest::drop_identity_autoload(), 'Idempotente.' );
			self::assertSame( array( array( LlmsManifest::OPTION_IDENTITY, false ), array( LlmsManifest::OPTION_IDENTITY, false ) ), $llamadas );
		} finally {
			Monkey\tearDown();
		}
	}
}
