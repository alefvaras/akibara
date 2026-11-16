<?php
/**
 * Unificación del umbral de envío gratis a domicilio (Fuente Única de Verdad).
 *
 * En Akibara, el método core "Envío gratis" está deshabilitado en las zonas de envío
 * porque Blue Express entrega gratis a domicilio sobre $60.000 CLP dentro de su
 * propia tarifa. `FreeShippingProgress::get_settings()` expone un único campo
 * numérico de umbral (`akibara_fsp_carrier_threshold`) para el dueño sin duplicidad.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\FreeShippingProgress;

use Akibara\Business\Modules\FreeShippingProgress\FreeShippingProgress;
use Akibara\Business\Services\FreeShippingThreshold;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class UmbralConEfectoVisibleTest extends TestCase {

	/** @var array<string, mixed> */
	private array $opciones = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! function_exists( 'WC' ) ) {
			eval(
				'namespace { function WC() {
					return $GLOBALS["akibara_test_woocommerce"] ?? null;
				} }'
			);
		}

		$this->opciones = array(
			'akibara_fsp_carrier_threshold' => '60000',
			'akibara_fsp_threshold'         => '0',
		);

		Functions\when( 'get_option' )->alias(
			fn( string $nombre, $default = false ) => $this->opciones[ $nombre ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function( string $nombre, $valor ): bool {
				$this->opciones[ $nombre ] = $valor;
				return true;
			}
		);
		Functions\when( 'number_format_i18n' )->alias(
			static fn( $numero ) => number_format( (float) $numero, 0, ',', '.' )
		);
		Functions\when( 'wc_price' )->alias(
			static fn( $numero ) => '$' . number_format( (float) $numero, 0, ',', '.' )
		);
		Functions\when( 'WC' )->alias(
			static fn() => $GLOBALS['akibara_test_woocommerce'] ?? null
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_woocommerce'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox get_settings() expone un único campo de umbral (akibara_fsp_carrier_threshold) sin duplicidad
	 */
	public function test_get_settings_exposes_single_threshold_field_without_duplication(): void {
		$fsp      = new FreeShippingProgress();
		$settings = $fsp->get_settings();

		$carrier_field = null;
		$legacy_field  = null;

		foreach ( $settings as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$id = $field['id'] ?? '';
			if ( FreeShippingProgress::OPTION_CARRIER_THRESHOLD === $id ) {
				$carrier_field = $field;
			}
			if ( FreeShippingProgress::OPTION_THRESHOLD === $id ) {
				$legacy_field = $field;
			}
		}

		self::assertNotNull( $carrier_field, 'Debe existir el campo OPTION_CARRIER_THRESHOLD en los ajustes.' );
		self::assertNull( $legacy_field, 'El campo duplicado OPTION_THRESHOLD no debe figurar en get_settings().' );

		self::assertSame( 'number', $carrier_field['type'] );
		self::assertSame( 'Umbral de envío gratis a domicilio (CLP)', $carrier_field['title'] );
		self::assertSame( '60000', $carrier_field['default'] );

		$desc = (string) ( $carrier_field['desc'] ?? '' );
		self::assertStringContainsString( 'Blue Express', $desc );
		self::assertStringContainsString( '60.000', $desc );
		self::assertStringContainsString( 'Punto Blue', $desc );
		self::assertStringContainsString( 'retiro en tienda', $desc );
	}

	/**
	 * @testdox Al cambiar akibara_fsp_carrier_threshold se actualiza el umbral en filter_free_shipping_is_available()
	 */
	public function test_changing_carrier_threshold_reflects_in_free_shipping_availability(): void {
		$cart_mock = new class() {
			public float $subtotal = 65000.0;
			public function get_displayed_subtotal(): float {
				return $this->subtotal;
			}
		};

		$GLOBALS['akibara_test_woocommerce'] = (object) array( 'cart' => $cart_mock );

		$fsp = new FreeShippingProgress();

		// Con umbral de 60.000 y subtotal 65.000, está calificado para envío gratis a domicilio
		$this->opciones['akibara_fsp_carrier_threshold'] = '60000';
		self::assertTrue(
			$fsp->filter_free_shipping_is_available( false, array(), null ),
			'Subtotal 65.000 sobre umbral 60.000 debe calificar.'
		);

		// Si el operador sube el umbral a 75.000, subtotal 65.000 ya no califica
		$this->opciones['akibara_fsp_carrier_threshold'] = '75000';
		self::assertFalse(
			$fsp->filter_free_shipping_is_available( false, array(), null ),
			'Subtotal 65.000 bajo nuevo umbral 75.000 no debe calificar.'
		);
	}

	/**
	 * @testdox Al cambiar akibara_fsp_carrier_threshold se actualiza la resolución de FreeShippingThreshold
	 */
	public function test_changing_carrier_threshold_reflects_in_free_shipping_threshold_service(): void {
		$fallback_60k = FreeShippingThreshold::carrier_fallback_for_enabled_methods(
			array( 'bluex-ex' ),
			60000.0
		);
		self::assertNotEmpty( $fallback_60k );
		self::assertSame( 60000.0, $fallback_60k[0]['min_amount'] );

		$fallback_75k = FreeShippingThreshold::carrier_fallback_for_enabled_methods(
			array( 'bluex-ex' ),
			75000.0
		);
		self::assertNotEmpty( $fallback_75k );
		self::assertSame( 75000.0, $fallback_75k[0]['min_amount'] );
	}
}
