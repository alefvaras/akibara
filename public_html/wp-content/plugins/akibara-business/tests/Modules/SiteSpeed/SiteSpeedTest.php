<?php
/**
 * Retiro definitivo de SiteSpeed / pestaña «Velocidad» y certificación
 * de los 5 módulos de rendimiento operando 100% por código (Always-On).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SiteSpeed;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\ScriptDiagnostics\CrossOriginScripts;
use Akibara\Business\Modules\SiteSpeed\AnalyticsAfterLoad;
use Akibara\Business\Modules\SiteSpeed\CacheWarmer;
use Akibara\Business\Modules\SiteSpeed\EdgePurge;
use Akibara\Business\Modules\SiteSpeed\LeanCart;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SiteSpeedTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d, ',', '.' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $f, int $t ) => gmdate( 'd/m/Y H:i', $t ) );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ) => 'https://akibara.cl/wp-admin/' . $path );
	}

	protected function tearDown(): void {
		$_GET = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, int|string> $cambios Claves del estado.
	 * @return array<string, int|string>
	 */
	private function estado( array $cambios ): array {
		return array_merge(
			array(
				'started'      => 0,
				'finished'     => 0,
				'day'          => '',
				'manual'       => 0,
				'cursor'       => 0,
				'total'        => 0,
				'calentadas'   => 0,
				'ya_calientes' => 0,
				'sin_cache'    => 0,
				'errores'      => 0,
				'cortada'      => 0,
				'ultimo_error' => '',
			),
			$cambios
		);
	}

	/** @testdox SiteSpeed.php fue eliminado físicamente del árbol de código. */
	public function test_sitespeed_class_file_is_eradicated(): void {
		$file = dirname( __DIR__, 3 ) . '/src/Modules/SiteSpeed/SiteSpeed.php';
		self::assertFileDoesNotExist( $file, 'SiteSpeed.php debe estar completamente eliminado.' );
		self::assertFalse( class_exists( 'Akibara\Business\Modules\SiteSpeed\SiteSpeed' ) );
	}

	/** @testdox CacheWarmer::describe_state: nunca recalentado. */
	public function test_never_started(): void {
		self::assertSame( 'Todavía no se ha recalentado ninguna ficha.', CacheWarmer::describe_state( $this->estado( array() ), true ) );
		self::assertSame( 'Apagado. Todavía no se ha recalentado ninguna ficha.', CacheWarmer::describe_state( $this->estado( array() ), false ) );
	}

	/** @testdox CacheWarmer::describe_state: una pasada en curso dice cuánto lleva. */
	public function test_open_pass(): void {
		$frase = CacheWarmer::describe_state( $this->estado( array( 'started' => 1789450000, 'cursor' => 120, 'total' => 1639, 'calentadas' => 200, 'ya_calientes' => 40 ) ), true );
		self::assertStringStartsWith( 'En curso desde el ', $frase );
		self::assertStringContainsString( '120 de 1.639 fichas', $frase );
		self::assertStringContainsString( '200 guardadas ahora', $frase );
		self::assertStringContainsString( '40 ya estaban guardadas', $frase );
		self::assertStringNotContainsString( 'error', $frase );
	}

	/** @testdox CacheWarmer::describe_state: una pasada cortada a las 06:00 lo dice, y los errores traen el último. */
	public function test_cut_pass_with_errors(): void {
		$frase = CacheWarmer::describe_state(
			$this->estado( array( 'started' => 1789450000, 'finished' => 1789470000, 'cortada' => 1, 'cursor' => 900, 'total' => 1639, 'errores' => 2, 'ultimo_error' => 'https://akibara.cl/x/: respondió 503' ) ),
			true
		);
		self::assertStringStartsWith( 'Se cortó a las 06:00 el ', $frase );
		self::assertStringContainsString( '900 de 1.639 fichas', $frase );
		self::assertStringContainsString( '2 con error. Último: https://akibara.cl/x/: respondió 503', $frase );
	}

	/** @testdox CacheWarmer::describe_state: una pasada terminada lo dice. */
	public function test_finished_pass(): void {
		$frase = CacheWarmer::describe_state( $this->estado( array( 'started' => 1789450000, 'finished' => 1789460000, 'cursor' => 1639, 'total' => 1639 ) ), true );
		self::assertStringStartsWith( 'Última pasada terminada el ', $frase );
	}

	/** @testdox Todos los 5 módulos satélite operan 100% por código incondicionalmente (Always-On). */
	public function test_all_modules_operate_always_on(): void {
		self::assertTrue( CacheWarmer::enabled() );
		self::assertTrue( EdgePurge::is_enabled() );
		self::assertTrue( LeanCart::enabled() );
		self::assertTrue( AnalyticsAfterLoad::enabled() );
		self::assertTrue( CrossOriginScripts::enabled() );
	}

	/** @testdox La ruta administrativa ?page=akibara-panel&tab=velocidad redirige a akibara-panel. */
	public function test_tab_velocidad_redirects_to_akibara_panel(): void {
		$_GET = array(
			'page' => 'akibara-panel',
			'tab'  => 'velocidad',
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
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'REDIRECT_EXECUTED', $e->getMessage() );
		}

		self::assertSame( 'https://akibara.cl/wp-admin/admin.php?page=akibara-panel', $redirect_target );
	}

	/** @testdox La sección velocidad ya no figura en SECTIONS_WITHOUT_CACHED_SURFACE. */
	public function test_velocidad_not_in_uncached_surfaces(): void {
		$ref     = new \ReflectionClassConstant( AdminPanel::class, 'SECTIONS_WITHOUT_CACHED_SURFACE' );
		$surfaces = $ref->getValue();
		self::assertIsArray( $surfaces );
		self::assertArrayNotHasKey( 'velocidad', $surfaces );
	}
}
