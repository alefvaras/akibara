<?php
/**
 * El atajo a los ajustes globales de «Arma tu Pack».
 *
 * El `AdminPanel` llama al `header_fn` del módulo ANTES de abrir su `<form>`,
 * así que el interruptor maestro se imprime detrás de la lista completa de
 * series. Medido en local el 2026-08-23 renderizando la pestaña entera: el `id`
 * del interruptor caía en el byte 344.191 de 347.020 (99,2 % de la página),
 * detrás de 115 `<details>` y 343 KB de acordeones. Con el atajo, la primera
 * aparición de ese id queda en el byte 830 (0,2 %).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\SeriesBundles;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

final class AdminHeaderShortcutTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox El atajo apunta al id que WooCommerce le da al interruptor maestro.
	 */
	public function test_shortcut_targets_the_master_switch_field(): void {
		$module = new SeriesBundles();
		$render = ( new ReflectionObject( $module ) )->getMethod( 'render_global_settings_shortcut' );
		$render->setAccessible( true );

		ob_start();
		$render->invoke( $module );
		$html = (string) ob_get_clean();

		// `WC_Admin_Settings::output_fields()` usa el id del campo —que es el
		// nombre de la opción— como id del `<input>`: ése es el ancla.
		self::assertStringContainsString( 'href="#' . Settings::OPTION_ENABLED . '"', $html );
	}

	/**
	 * @testdox El atajo se pinta antes de la configuración por serie.
	 */
	public function test_shortcut_is_rendered_before_the_series_list(): void {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/SeriesBundles/SeriesBundles.php'
		);
		self::assertIsString( $source );

		$header   = strpos( $source, 'admin_section->render_header()' );
		$shortcut = strpos( $source, 'render_global_settings_shortcut()' );
		$byp      = strpos( $source, 'byp_config->render()' );

		self::assertIsInt( $header );
		self::assertIsInt( $shortcut );
		self::assertIsInt( $byp );
		self::assertTrue(
			$header < $shortcut && $shortcut < $byp,
			'El atajo tiene que quedar arriba: debajo de la lista de series no resuelve nada.'
		);
	}
}
