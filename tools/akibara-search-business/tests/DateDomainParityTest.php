<?php
/**
 * Los dos lados tienen que aceptar y rechazar EXACTAMENTE las mismas fechas.
 *
 * La ventana de la promoción la valida Search al publicar la foto
 * (`valid_date()`) y la revalida Business al adoptarla (`valid_option_value()`).
 * No son el mismo código: Search construye la fecha CON la zona del sitio y mira
 * los avisos de PHP; Business exige antes un patrón y construye SIN zona.
 *
 * Una fecha que un lado acepte y el otro rechace no da un error visible: hace
 * que la foto se descarte entera, y la tienda se queda sin buscador y sin
 * facetas. Es la misma familia de fallo que el dominio de las options y que el
 * instante del boundary, los dos ya cerrados con su candado.
 *
 * Los casos de abajo son bordes a propósito: años no bisiestos, meses de 30
 * días, el día en que Chile adelanta el reloj —cuya medianoche NO existe— y
 * basura que pasa el patrón pero no es una fecha.
 *
 * @package Akibara\SearchBusiness\Tests
 */

declare( strict_types = 1 );

namespace Akibara\SearchBusiness\Tests;

use Akibara\Business\Modules\TaxonomyDiscount\AdoptedSnapshot;
use Akibara\Search\EditorialDiscountPublication;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DateDomainParityTest extends TestCase {

	private const ZONA = 'America/Santiago';

	/** @return array<string, array{0: string}> */
	public static function fechas(): array {
		$casos = array(
			'normal'                          => '2026-07-15',
			'primer día del año'              => '2026-01-01',
			'último día del año'              => '2026-12-31',
			'29 de febrero BISIESTO'          => '2024-02-29',
			'29 de febrero NO bisiesto'       => '2027-02-29',
			'31 de un mes de 30'              => '2026-04-31',
			'32 de enero'                     => '2026-01-32',
			'mes 13'                          => '2026-13-01',
			'mes 00'                          => '2026-00-10',
			'día 00'                          => '2026-05-00',
			'el día del salto de primavera'   => '2026-09-06',
			'la víspera del salto'            => '2026-09-05',
			'el día del salto de otoño'       => '2027-04-04',
			'cadena vacía'                    => '',
			'sólo el año'                     => '2026',
			'con hora pegada'                 => '2026-07-15 00:00:00',
			'con espacio delante'             => ' 2026-07-15',
			'con espacio detrás'              => '2026-07-15 ',
			'año de cinco cifras'             => '20260-07-15',
			'sin ceros a la izquierda'        => '2026-7-5',
			'separadores de barra'            => '2026/07/15',
			'texto'                           => 'mañana',
			'negativo'                        => '-2026-07-15',
			'año cero'                        => '0000-01-01',
			'fecha muy lejana'                => '9999-12-31',
		);

		$salida = array();
		foreach ( $casos as $nombre => $valor ) {
			$salida[ $nombre ] = array( $valor );
		}

		return $salida;
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function search_acepta( string $fecha ): bool {
		$metodo = new ReflectionMethod( EditorialDiscountPublication::class, 'valid_date' );
		$metodo->setAccessible( true );

		return (bool) $metodo->invoke( null, $fecha, new \DateTimeZone( self::ZONA ) );
	}

	private function business_acepta( string $fecha ): bool {
		$metodo = new ReflectionMethod( AdoptedSnapshot::class, 'valid_option_value' );
		$metodo->setAccessible( true );

		return (bool) $metodo->invoke( null, 'akibara_taxonomy_discount_to', $fecha );
	}

	/**
	 * @dataProvider fechas
	 * @testdox Los dos lados coinciden sobre: $_dataName
	 *
	 * @param string $fecha Valor a validar.
	 */
	public function test_both_sides_agree( string $fecha ): void {
		$search   = $this->search_acepta( $fecha );
		$business = $this->business_acepta( $fecha );

		// La cadena vacía es el caso legítimo de «sin fecha»: Business la acepta
		// como ausencia de límite, y Search no la pasa por `valid_date()` porque
		// la filtra antes (`'' !== $to`). No es una divergencia real.
		if ( '' === $fecha ) {
			self::assertTrue( $business, 'La ventana sin fecha es una configuración válida.' );
			return;
		}

		self::assertSame(
			$search,
			$business,
			sprintf(
				'«%s»: Search %s y Business %s. Una fecha que un lado acepta y el otro rechaza descarta la foto entera y apaga el buscador.',
				$fecha,
				$search ? 'la acepta' : 'la rechaza',
				$business ? 'la acepta' : 'la rechaza'
			)
		);
	}

	/**
	 * Control: si los dos rechazaran todo, la prueba de arriba pasaría sin
	 * probar nada. Esto fija que el universo tiene casos de los dos signos.
	 *
	 * @testdox El universo de prueba tiene fechas buenas y fechas malas.
	 */
	public function test_the_universe_has_both_kinds(): void {
		$aceptadas = 0;
		$rechazadas = 0;
		foreach ( self::fechas() as $caso ) {
			if ( '' === $caso[0] ) {
				continue;
			}
			if ( $this->business_acepta( $caso[0] ) ) {
				++$aceptadas;
			} else {
				++$rechazadas;
			}
		}

		self::assertGreaterThan( 3, $aceptadas, 'Sin fechas válidas, la paridad se cumpliría por vacío.' );
		self::assertGreaterThan( 3, $rechazadas, 'Sin fechas inválidas, no se estaría probando el rechazo.' );
	}
}
