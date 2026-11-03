<?php
/**
 * El fin de la ventana cae a MEDIANOCHE, también el día que Chile cambia la hora.
 *
 * La ventana de la promoción termina «al acabar el día `to`», es decir, en la
 * medianoche del día siguiente. El código lo calculaba sumándole un día al
 * instante de la medianoche del `to`, y eso falla justo cuando el día siguiente
 * es aquel en que el reloj se adelanta: en Chile, esa madrugada el reloj salta
 * de las 23:59:59 a la 01:00, así que **la medianoche de ese día no existe** y
 * el resultado se va una hora tarde.
 *
 * Medido el 2026-09-06 en producción: con `to = 2026-09-06` la acción del
 * boundary quedó programada a las 04:00 UTC (01:00 de Chile) en vez de a las
 * 03:00 UTC (00:00 de Chile). Las acciones de los días 8 y 9, que no cruzan la
 * transición, sí estaban en su hora.
 *
 * Las fechas de este test son FIJAS a propósito. Un test que calcule «el próximo
 * cambio de horario» desde el reloj de hoy caduca solo dos veces al año.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\AdoptedSnapshot;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WindowBoundaryDstTest extends TestCase {

	private const ZONA = 'America/Santiago';

	/**
	 * @param string $from Fecha de inicio, o cadena vacía.
	 * @param string $to   Fecha de fin, o cadena vacía.
	 * @param string $ahora Instante de evaluación, en la zona del sitio.
	 * @return array{active: bool, boundary: int|null}|null
	 */
	private function ventana( string $from, string $to, string $ahora ): ?array {
		$metodo = new ReflectionMethod( AdoptedSnapshot::class, 'compute_window' );
		$metodo->setAccessible( true );

		$now = ( new \DateTimeImmutable( $ahora, new \DateTimeZone( self::ZONA ) ) )->getTimestamp();

		return $metodo->invoke(
			null,
			array(
				'akibara_taxonomy_discount_enabled' => self::fila( 'yes' ),
				'akibara_taxonomy_discount_from'    => self::fila( $from ),
				'akibara_taxonomy_discount_to'      => self::fila( $to ),
			),
			self::ZONA,
			$now
		);
	}

	/**
	 * Una fila de la foto tiene la forma que publica Search, no una cadena
	 * suelta: modelarla mal dejaba el módulo apagado y el test verde por el
	 * motivo equivocado.
	 *
	 * @param string $valor Valor crudo de la option.
	 * @return array{present: bool, raw: string}
	 */
	private static function fila( string $valor ): array {
		return array(
			'present' => true,
			'raw'     => $valor,
		);
	}

	/** El instante que corresponde a una hora de pared concreta en Chile. */
	private function instante( string $cuando ): int {
		return ( new \DateTimeImmutable( $cuando, new \DateTimeZone( self::ZONA ) ) )->getTimestamp();
	}

	/**
	 * El caso vivo: la promoción del fin de semana del 2026-09-06, cuya víspera
	 * es la noche en que Chile adelanta el reloj.
	 *
	 * @testdox La ventana que termina la víspera del cambio de hora se apaga a medianoche, no a la 1.
	 */
	public function test_the_window_closes_at_midnight_even_when_the_clock_jumps(): void {
		$ventana = $this->ventana( '2026-09-05', '2026-09-06', '2026-09-05 18:00:00' );

		self::assertIsArray( $ventana, 'Precondición: la ventana se recomputa.' );
		self::assertTrue( $ventana['active'], 'Precondición: la promoción está viva ese sábado.' );

		self::assertSame(
			$this->instante( '2026-09-07 00:00:00' ),
			$ventana['boundary'],
			'La promoción termina cuando acaba el domingo. Sumar un día a una medianoche que no existe la deja viva una hora de más.'
		);
	}

	/**
	 * El control que separa el defecto del ruido: los días que no cruzan la
	 * transición ya salían bien, y tienen que seguir saliendo bien.
	 *
	 * @testdox Una ventana normal, lejos del cambio de hora, sigue cerrando a medianoche.
	 */
	public function test_an_ordinary_window_still_closes_at_midnight(): void {
		$ventana = $this->ventana( '2026-07-10', '2026-07-15', '2026-07-12 10:00:00' );

		self::assertIsArray( $ventana );
		self::assertSame(
			$this->instante( '2026-07-16 00:00:00' ),
			$ventana['boundary']
		);
	}

	/**
	 * La otra transición, la de otoño, en la que el reloj se atrasa. Aquí la
	 * medianoche del día siguiente sí existe —de hecho ocurre dos veces— y el
	 * cálculo viejo ya acertaba. Está para que el arreglo no la estropee.
	 *
	 * @testdox La transición de otoño no se ve afectada, ni antes ni después del arreglo.
	 */
	public function test_the_autumn_transition_is_unaffected(): void {
		$ventana = $this->ventana( '2027-04-01', '2027-04-03', '2027-04-02 12:00:00' );

		self::assertIsArray( $ventana );
		self::assertSame(
			$this->instante( '2027-04-04 00:00:00' ),
			$ventana['boundary']
		);
	}

	/**
	 * Con la ventana aún por empezar, el límite que se espera es el INICIO. Ese
	 * también se sitúa en la zona, así que entra en el mismo contrato.
	 *
	 * @testdox Antes de empezar, el límite es el inicio de la ventana.
	 */
	public function test_before_the_window_the_boundary_is_the_start(): void {
		$ventana = $this->ventana( '2026-09-10', '2026-09-20', '2026-09-08 12:00:00' );

		self::assertIsArray( $ventana );
		self::assertFalse( $ventana['active'], 'Todavía no ha empezado.' );
		self::assertSame(
			$this->instante( '2026-09-10 00:00:00' ),
			$ventana['boundary']
		);
	}

	/**
	 * La medianoche del propio día del cambio NO EXISTE en Chile. Si alguien
	 * pone la ventana para que empiece ese día, el instante tiene que ser el del
	 * salto —el primer momento real de ese día— y no algo inventado.
	 *
	 * @testdox Una ventana que empieza el día del salto arranca en el instante del salto.
	 */
	public function test_a_window_starting_on_the_skipped_midnight_starts_at_the_jump(): void {
		$ventana = $this->ventana( '2026-09-06', '2026-09-20', '2026-09-04 12:00:00' );

		self::assertIsArray( $ventana );
		self::assertSame(
			1788667200, // 2026-09-06 04:00 UTC = el instante exacto en que Chile pasa a -03.
			$ventana['boundary'],
			'La medianoche del 6 de septiembre no existe en Chile: el primer instante de ese día es el del salto.'
		);
	}
}
