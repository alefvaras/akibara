<?php
/**
 * La regla de qué fecha de lanzamiento es aceptable tiene UNA dueña.
 *
 * Hasta 1.114.11 la ficha del producto la aplicaba (formato + día real + cota
 * de 5 años) y la pantalla «Preventas» sólo el formato: `2206-05-04` pasaba,
 * se guardaba, y como el tomo no tenía fecha previa el listener lo clasificaba
 * «anunciada» y mandaba al cliente un correo con una fecha de hace 180 años.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Stock;

use Akibara\Business\Modules\Stock\Availability;
use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AvailabilityReleaseDateTest extends TestCase {

	private const HOY = '2026-09-02';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'America/Santiago' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider fechas
	 */
	public function test_la_regla_unica( string $ymd, bool $esperado, string $porque ): void {
		self::assertSame( $esperado, Availability::is_valid_release_date( $ymd, self::HOY ), $porque );
	}

	/** @return array<string, array{0:string,1:bool,2:string}> */
	public static function fechas(): array {
		return array(
			'una fecha normal'              => array( '2026-11-07', true, 'Día real, dentro de la cota.' ),
			'el mismo día de la cota'       => array( '2031-09-02', true, 'La cota es inclusiva.' ),
			'el año con un dígito de más'   => array( '2206-05-04', false, 'El typo que ya se sufrió una vez: pasa checkdate y sólo lo para la cota.' ),
			'un día después de la cota'     => array( '2031-09-03', false, 'Más de 5 años en el futuro.' ),
			'un día que no existe'          => array( '2026-02-30', false, 'checkdate.' ),
			'un mes que no existe'          => array( '2026-13-01', false, 'checkdate.' ),
			'formato distinto'              => array( '07/11/2026', false, 'Sólo Y-m-d.' ),
			'vacío'                         => array( '', false, 'Vacío no es una fecha; quitar la fecha es otra operación.' ),
			'fecha pasada'                  => array( '2020-01-01', true, 'NO hay cota inferior a propósito: re-guardar un tomo ya lanzado no debe borrarle la fecha.' ),
		);
	}

	public function test_la_cota_se_calcula_desde_el_dia_base(): void {
		self::assertSame( '2031-09-02', Availability::max_release_date( self::HOY ) );
		self::assertSame( 5, Availability::MAX_RELEASE_DATE_YEARS );
	}
}
