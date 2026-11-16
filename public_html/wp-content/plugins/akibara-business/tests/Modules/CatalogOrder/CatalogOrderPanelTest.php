<?php
/**
 * Candado del retiro de la pestaña Vitrina (catalog_order) del Centro de Mando.
 *
 * Certifica que la ruta retirada redirige transparentemente al panel principal,
 * que no figura en las superficies exentas de caché, y que la telemetría para CLI
 * (last_run) continúa funcionando.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\CatalogOrder;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\CatalogOrder\CatalogOrder;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CatalogOrderPanelTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_GET = array();

		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( static fn( string $v ): string => strtolower( $v ) );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'admin_url' )->alias(
			static fn( string $path = '' ): string => 'https://akibara.test/wp-admin/' . $path
		);
	}

	protected function tearDown(): void {
		$_GET = array();
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox La pestaña retirada tab=catalog_order redirige al Centro de Mando
	 */
	public function test_retired_tab_redirects_to_main_panel(): void {
		$_GET = array(
			'page' => 'akibara-panel',
			'tab'  => 'catalog_order',
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
			self::fail( 'Debió ejecutar redirect' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'REDIRECT_EXECUTED', $e->getMessage() );
		}

		self::assertSame( 'https://akibara.test/wp-admin/admin.php?page=akibara-panel', $redirect_target );
	}

	/**
	 * @testdox La sección catalog_order ya no figura en SECTIONS_WITHOUT_CACHED_SURFACE
	 */
	public function test_catalog_order_not_in_uncached_surfaces(): void {
		$ref      = new \ReflectionClassConstant( AdminPanel::class, 'SECTIONS_WITHOUT_CACHED_SURFACE' );
		$surfaces = $ref->getValue();
		self::assertIsArray( $surfaces );
		self::assertArrayNotHasKey( 'catalog_order', $surfaces );
	}

	/**
	 * @testdox last_run devuelve telemetría válida para WP-CLI cuando existe
	 */
	public function test_last_run_telemetry_reads_option(): void {
		$GLOBALS['akibara_test_options'] = array(
			'akb_catalog_order_last_run' => array(
				'time'    => 1756700000,
				'rows'    => 1568,
				'applied' => 1122,
			),
		);

		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default = false ) => $GLOBALS['akibara_test_options'][ $option ] ?? $default
		);

		$last = CatalogOrder::last_run();
		self::assertSame( 1756700000, $last['time'] );
		self::assertSame( 1568, $last['rows'] );
		self::assertSame( 1122, $last['applied'] );
	}

	/**
	 * @testdox last_run devuelve array vacío cuando no hay registros
	 */
	public function test_last_run_empty_when_no_option(): void {
		$GLOBALS['akibara_test_options'] = array();

		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default = false ) => $GLOBALS['akibara_test_options'][ $option ] ?? $default
		);

		self::assertSame( array(), CatalogOrder::last_run() );
	}
}
