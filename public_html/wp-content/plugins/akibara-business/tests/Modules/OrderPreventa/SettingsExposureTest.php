<?php
/**
 * Cero options invisibles: cada valor de `Settings::DEFAULTS` tiene un campo
 * en alguna sub-pestaña del panel.
 *
 * Una option que sólo se puede tocar por WP-CLI es, para quien administra la
 * tienda, una que no existe. El tope de antigüedad del aviso «revisar»
 * (`stale_max_age_days`) nació como constante y se convirtió en option
 * precisamente para que se pueda mover sin código; esta prueba impide que la
 * siguiente se quede a medio camino.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SettingsExposureTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['akibara_test_options'] = array();
		Functions\when( 'sanitize_key' )->alias( static fn( string $v ): string => strtolower( $v ) );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		$_GET = array();
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Todas las options con default tienen su campo en el panel.
	 */
	public function test_every_default_has_a_field(): void {
		$ids = array();
		foreach ( array( 'recordatorio', 'cambio-fecha', 'stale', 'fechas', 'cortes' ) as $subtab ) {
			$_GET = array( 'subtab' => $subtab );
			foreach ( ( new Settings() )->get_panel_settings() as $field ) {
				if ( isset( $field['id'] ) ) {
					$ids[] = (string) $field['id'];
				}
			}
		}

		$sin_campo = array_diff( array_keys( Settings::DEFAULTS ), $ids );

		self::assertSame( array(), array_values( $sin_campo ), 'Options sin campo en el panel: sólo se pueden tocar por código.' );
	}

	/**
	 * @testdox El tope del aviso «revisar» se edita en la pestaña de avisos internos.
	 */
	public function test_the_stale_cap_lives_in_the_stale_tab(): void {
		$_GET = array( 'subtab' => 'stale' );
		$ids  = array_column( ( new Settings() )->get_panel_settings(), 'id' );

		self::assertContains( Settings::OPT_STALE_MAX_AGE_DAYS, $ids );
		self::assertContains( Settings::OPT_STALE_PENDING_DAYS, $ids );
	}
}
