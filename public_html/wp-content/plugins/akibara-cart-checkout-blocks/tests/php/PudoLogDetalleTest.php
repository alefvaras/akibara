<?php
/**
 * El registro de un rechazo de punto de retiro dice QUÉ condición falló.
 *
 * R7-pudo-registro-mudo (2026-09-19): dos rechazos reales el 2026-09-15 dejaron
 * «select_agency: No pudimos confirmar el punto de retiro seleccionado.
 * (PudoValidationException en PickupPointProvider.php:370)» y nada más: el
 * código y el detalle de la validación se descartaban.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use Akibara\Business\Integrations\Bluex\Pudo\PudoValidationException;
use Akibara\CartCheckoutBlocks\PudoLog;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PudoLogDetalleTest extends TestCase {

	/** @var array<int, string> */
	private array $registrado = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->registrado = array();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( static fn ( $data, int $flags = 0 ) => json_encode( $data, $flags ) );
		Functions\when( 'wc_get_logger' )->alias(
			fn () => new class( $this->registrado ) {
				/** @var array<int, string> */
				private array $sink;

				/** @param array<int, string> $sink Acumulador. */
				public function __construct( array &$sink ) {
					$this->sink = &$sink;
				}

				/** @param array<string, mixed> $context Contexto. */
				public function error( string $message, array $context = array() ): void {
					unset( $context );
					$this->sink[] = $message;
				}
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Con una excepción de validación, el registro lleva el código y el detalle; el mensaje del cliente no cambia. */
	public function test_el_registro_lleva_codigo_y_detalle(): void {
		$excepcion = new PudoValidationException(
			'bluex_geolocation_schema',
			'No pudimos confirmar el punto de retiro seleccionado.',
			null,
			array(
				'fallos'    => array( 'region_codigo', 'region_nombre' ),
				'confianza' => 92.5,
			)
		);

		PudoLog::failure( 'select_agency', $excepcion );

		self::assertCount( 1, $this->registrado );
		self::assertStringContainsString( 'select_agency: No pudimos confirmar el punto de retiro seleccionado.', $this->registrado[0] );
		self::assertStringContainsString( 'código=bluex_geolocation_schema', $this->registrado[0] );
		self::assertStringContainsString( '"fallos":["region_codigo","region_nombre"]', $this->registrado[0] );
		self::assertSame( 'No pudimos confirmar el punto de retiro seleccionado.', $excepcion->getMessage(), 'El cliente sigue viendo el mismo texto.' );
	}

	/** @testdox [NEG] Una excepción corriente se registra como antes, sin código ni detalle. */
	public function test_una_excepcion_corriente_no_cambia(): void {
		PudoLog::failure( 'select_agency', new \RuntimeException( 'Se cayó la API.' ) );

		self::assertCount( 1, $this->registrado );
		self::assertStringNotContainsString( 'código=', $this->registrado[0] );
		self::assertStringNotContainsString( 'detalle=', $this->registrado[0] );
	}
}
