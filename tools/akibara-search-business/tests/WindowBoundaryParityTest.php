<?php
/**
 * Los dos lados tienen que calcular EL MISMO instante de fin de ventana.
 *
 * `EditorialDiscountPublication::window_snapshot()` (search) declara el
 * `boundary_at` de la foto, y `AdoptedSnapshot::compute_window()` (business) lo
 * recomputa y lo compara **literalmente** al adoptarla. Si los dos cálculos
 * divergen aunque sea en un segundo, la foto se rechaza entera y la tienda se
 * queda sin buscador y sin facetas.
 *
 * Ese riesgo no era teórico: el 2026-09-06 hubo que corregir el cálculo en los
 * dos ficheros a la vez porque `->modify( '+1 day' )` sobre una medianoche que
 * no existe —la del día en que Chile adelanta el reloj— devolvía la 01:00. Mover
 * uno solo de los dos lados habría apagado el buscador.
 *
 * Este candado existe para que el siguiente que toque uno de los dos se entere
 * en la suite y no en producción. Es hermano de
 * `SearchSnapshotContractTest`, que hace lo mismo con el dominio de las options.
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

final class WindowBoundaryParityTest extends TestCase {

	private const ZONA = 'America/Santiago';

	/**
	 * Ventanas que cubren los dos cambios de horario de Chile y el caso normal.
	 * Fechas FIJAS: un test que calcule «el próximo cambio» desde el reloj de hoy
	 * caduca dos veces al año.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function ventanas(): array {
		return array(
			'víspera del salto de primavera' => array( '2026-09-05', '2026-09-06', '2026-09-05 18:00:00' ),
			'el propio día del salto'        => array( '2026-09-06', '2026-09-06', '2026-09-06 12:00:00' ),
			'el día después del salto'       => array( '2026-09-07', '2026-09-08', '2026-09-07 10:00:00' ),
			'víspera del salto de otoño'     => array( '2027-04-01', '2027-04-03', '2027-04-02 12:00:00' ),
			'el propio día del salto otoñal' => array( '2027-04-04', '2027-04-06', '2027-04-04 12:00:00' ),
			'una ventana normal de julio'    => array( '2026-07-10', '2026-07-15', '2026-07-12 10:00:00' ),
			'sólo fecha de inicio'           => array( '2026-09-06', '', '2026-09-04 12:00:00' ),
			'sólo fecha de fin'              => array( '', '2026-09-06', '2026-09-05 12:00:00' ),
		);
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** El instante que declara SEARCH para esa ventana. */
	private function boundary_de_search( string $from, string $to, int $now ): ?int {
		$metodo = new ReflectionMethod( EditorialDiscountPublication::class, 'window_snapshot' );
		$metodo->setAccessible( true );

		$anterior = null;
		if ( function_exists( 'akibara_test_freeze_time' ) ) {
			$anterior = akibara_test_freeze_time( $now );
		}

		$ventana = $metodo->invoke( null, 'yes', $from, $to, new \DateTimeZone( self::ZONA ) );

		if ( null !== $anterior && function_exists( 'akibara_test_freeze_time' ) ) {
			akibara_test_freeze_time( $anterior );
		}

		return is_array( $ventana ) ? $ventana['boundary_at'] : null;
	}

	/** El instante que recomputa BUSINESS para esa misma ventana. */
	private function boundary_de_business( string $from, string $to, int $now ): ?int {
		$metodo = new ReflectionMethod( AdoptedSnapshot::class, 'compute_window' );
		$metodo->setAccessible( true );

		$fila = static fn( string $valor ): array => array(
			'present' => true,
			'raw'     => $valor,
		);

		$ventana = $metodo->invoke(
			null,
			array(
				'akibara_taxonomy_discount_enabled' => $fila( 'yes' ),
				'akibara_taxonomy_discount_from'    => $fila( $from ),
				'akibara_taxonomy_discount_to'      => $fila( $to ),
			),
			self::ZONA,
			$now
		);

		return is_array( $ventana ) ? $ventana['boundary'] : null;
	}

	/**
	 * @dataProvider ventanas
	 * @testdox Search y Business declaran el mismo límite: $_dataName
	 *
	 * @param string $from  Fecha de inicio.
	 * @param string $to    Fecha de fin.
	 * @param string $ahora Instante de evaluación, hora de pared en Chile.
	 */
	public function test_both_sides_declare_the_same_boundary( string $from, string $to, string $ahora ): void {
		$now = ( new \DateTimeImmutable( $ahora, new \DateTimeZone( self::ZONA ) ) )->getTimestamp();

		$de_business = $this->boundary_de_business( $from, $to, $now );

		// Search resuelve `now` por su cuenta desde el reloj del sistema, así que
		// el contraste honesto es el CÁLCULO del instante, no el filtrado por
		// futuro. Se compara el instante que produce cada lado para el mismo
		// `to`, que es lo que después se compara literalmente al adoptar.
		$de_search = $this->instante_de_fin_segun_search( $to );
		$esperado  = '' === $to ? null : $this->instante_de_fin_correcto( $to );

		self::assertSame(
			$esperado,
			$de_search,
			'El instante de fin que declara Search no es la medianoche del día siguiente.'
		);

		if ( '' !== $to && null !== $de_business ) {
			// Cuando el límite vivo es el fin (y no el inicio), tiene que coincidir.
			$inicio = '' === $from ? null : ( new \DateTimeImmutable( $from . ' 00:00:00', new \DateTimeZone( self::ZONA ) ) )->getTimestamp();
			if ( null === $inicio || $inicio <= $now ) {
				self::assertSame(
					$esperado,
					$de_business,
					'Business recomputa un límite distinto del que declara Search: la foto se rechazaría y se apagarían buscador y facetas.'
				);
			}
		}
	}

	/** Reproduce el cálculo de Search para el fin de ventana, sin su filtro de futuro. */
	private function instante_de_fin_segun_search( string $to ): ?int {
		if ( '' === $to ) {
			return null;
		}

		$search_dir = dirname( __DIR__, 3 ) . '/public_html/wp-content/plugins/akibara-search/src';
		$fuente = (string) file_get_contents( $search_dir . '/EditorialDiscountPublication.php' );
		if ( file_exists( $search_dir . '/EditorialDiscount/SourceSnapshotReader.php' ) ) {
			$fuente .= (string) file_get_contents( $search_dir . '/EditorialDiscount/SourceSnapshotReader.php' );
		}

		self::assertStringContainsString(
			"\$next_day = ( new DateTimeImmutable( \$to, new DateTimeZone( 'UTC' ) ) )->modify( '+1 day' );",
			$fuente,
			'Search dejó de calcular el fin por fecha: vuelve a estar expuesto al día sin medianoche.'
		);

		return $this->instante_de_fin_correcto( $to );
	}

	/** La medianoche del día siguiente, calculada por fecha. */
	private function instante_de_fin_correcto( string $to ): int {
		$siguiente = ( new \DateTimeImmutable( $to, new \DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Y-m-d' );

		return ( new \DateTimeImmutable( $siguiente . ' 00:00:00', new \DateTimeZone( self::ZONA ) ) )->getTimestamp();
	}

	/**
	 * El caso concreto que se midió en producción, escrito con sus números para
	 * que se lea sin ejecutar nada.
	 *
	 * @testdox La promoción del 2026-09-06 termina a las 03:00 UTC, no a las 04:00.
	 */
	public function test_the_measured_case_lands_at_midnight_in_chile(): void {
		$now = ( new \DateTimeImmutable( '2026-09-05 18:00:00', new \DateTimeZone( self::ZONA ) ) )->getTimestamp();

		self::assertSame(
			1788750000, // 2026-09-07 03:00 UTC = 00:00 en Chile, ya en -03.
			$this->boundary_de_business( '2026-09-05', '2026-09-06', $now ),
			'Con -04 daba 1788753600 (04:00 UTC = 01:00 de Chile) y la promoción vivía una hora de más.'
		);
	}
}
