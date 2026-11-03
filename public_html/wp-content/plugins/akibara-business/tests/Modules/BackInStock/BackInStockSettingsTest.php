<?php
/**
 * Tests for BackInStock No-Code settings and flag compatibility.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BackInStock;

use Akibara\Business\Modules\BackInStock\BackInStock;
use Akibara\Business\Modules\BackInStock\InstockEmail;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Doble mínimo: sólo `is_enabled()` importa para este candado. */
final class MismatchNoticeSpyEmail extends InstockEmail {

	public bool $habilitado = false;

	// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	public function __construct() {}

	public function is_enabled(): bool {
		return $this->habilitado;
	}
}

final class BackInStockSettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_wc_yes_no_flags_are_parsed_without_truthy_no_bug(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => BackInStock::OPTION_CAPTURE === $key ? 'no' : 'yes'
		);

		self::assertFalse( BackInStock::capture_enabled() );
		self::assertTrue( BackInStock::dispatch_enabled() );
	}

	public function test_legacy_numeric_flags_remain_compatible(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => BackInStock::OPTION_CAPTURE === $key ? '1' : '0'
		);

		self::assertTrue( BackInStock::capture_enabled() );
		self::assertFalse( BackInStock::dispatch_enabled() );
	}

	public function test_settings_expose_capture_dispatch_and_demand_without_constructor_side_effects(): void {
		$module   = ( new ReflectionClass( BackInStock::class ) )->newInstanceWithoutConstructor();
		$settings = $module->get_settings();
		$ids      = array_column( $settings, 'id' );
		$types    = array_column( $settings, 'type', 'id' );

		self::assertContains( BackInStock::OPTION_CAPTURE, $ids );
		self::assertContains( BackInStock::OPTION_DISPATCH, $ids );
		self::assertContains( BackInStock::OPTION_NOTIFY_BACKORDER, $ids );
		self::assertSame( 'checkbox', $types[ BackInStock::OPTION_CAPTURE ] );
		self::assertSame( 'checkbox', $types[ BackInStock::OPTION_DISPATCH ] );
		self::assertSame( 'checkbox', $types[ BackInStock::OPTION_NOTIFY_BACKORDER ] );
		self::assertSame( 'akb_bis_demand', $types['akb_bis_demand'] );
		self::assertFalse( $settings[ array_search( 'akb_bis_demand', $ids, true ) ]['is_option'] );
	}

	public function test_notify_on_backorder_defaults_to_enabled_and_respects_no(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => $default
		);
		self::assertTrue( BackInStock::notify_on_backorder_enabled() );
		self::assertTrue( BackInStock::is_notifiable_status( 'onbackorder' ) );

		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => BackInStock::OPTION_NOTIFY_BACKORDER === $key ? 'no' : $default
		);
		self::assertFalse( BackInStock::notify_on_backorder_enabled() );
		self::assertFalse( BackInStock::is_notifiable_status( 'onbackorder' ) );
		self::assertTrue( BackInStock::is_notifiable_status( 'instock' ) );
	}

	/**
	 * EL DEFECTO QUE CIERRA (S-c2a19ef3, 2026-09-19): «la promesa contra el
	 * hecho» — el panel de Akibara mostraba «envío activo» leyendo SOLO su
	 * propia option, sin saber que el `WC_Email` real estaba apagado en la
	 * pantalla de WooCommerce. Ya costó tres días sin correo en producción sin
	 * que el panel dijera nada.
	 *
	 * @testdox [NEG] Con envío activo en Akibara y el correo apagado en WC, el panel avisa del desacuerdo.
	 */
	public function test_panel_avisa_si_wc_dice_apagado_y_akibara_dice_activo(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $key, $default = false ) => BackInStock::OPTION_DISPATCH === $key ? 'yes' : $default
		);
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'https://akibara.test/wp-admin/' . $path );
		Functions\when( 'esc_url' )->returnArg();

		$email = new MismatchNoticeSpyEmail();
		$email->habilitado = false;

		self::assertStringContainsString( 'desactivado', $this->notice_html( $email ) );
	}

	/** @testdox Con los dos interruptores de acuerdo, el panel NO avisa de nada. */
	public function test_panel_no_avisa_si_los_dos_interruptores_coinciden(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $key, $default = false ) => BackInStock::OPTION_DISPATCH === $key ? 'yes' : $default
		);
		Functions\when( 'admin_url' )->justReturn( '' );
		Functions\when( 'esc_url' )->returnArg();

		$email = new MismatchNoticeSpyEmail();
		$email->habilitado = true;

		self::assertSame( '', $this->notice_html( $email ) );
	}

	/** @testdox Con el envío ya apagado en Akibara, no hay nada que avisar (el corte seguro ya lo cubre). */
	public function test_panel_no_avisa_con_el_envio_apagado_en_akibara(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $key, $default = false ) => BackInStock::OPTION_DISPATCH === $key ? 'no' : $default
		);

		// Con el ajuste apagado, `wc_email_mismatch_notice_html()` corta ANTES
		// de resolver ningún email — pasar `null` prueba justo eso: nunca
		// llega a necesitar la factory.
		self::assertSame( '', $this->notice_html( null ) );
	}

	/**
	 * Invoca el método privado con una factory inyectada en vez de stubear la
	 * función global `WC()`: stubearla CONTAGIA — una vez que
	 * `Functions\when('WC')` la declara redefinible, `function_exists('WC')`
	 * queda `true` para el resto del proceso PHP y rompe pruebas de otros
	 * módulos que no la esperan (medido: tumbaba
	 * `GoogleLoginButton\AccessWallTest` al correr la suite completa).
	 *
	 * @param InstockEmail|null $email Email a resolver, o null para «no resoluble».
	 */
	private function notice_html( ?InstockEmail $email ): string {
		$metodo = new ReflectionMethod( BackInStock::class, 'wc_email_mismatch_notice_html' );
		$metodo->setAccessible( true );

		return (string) $metodo->invoke( null, static fn () => $email );
	}
}
