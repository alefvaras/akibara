<?php
/**
 * Contrato del contraste promesa/realidad.
 *
 * La aritmética es simple; lo que se fija acá son las decisiones de criterio,
 * que es donde un informe de cumplimiento se vuelve mentiroso sin que se note.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\PromiseAudit;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.108.22
 */
final class PromiseAuditTest extends TestCase {

	private PromiseAudit $sut;

	protected function setUp(): void {
		parent::setUp();
		$this->sut = new PromiseAudit( new \DateTimeZone( 'America/Santiago' ) );
	}

	private function ts( string $fecha ): int {
		return ( new \DateTimeImmutable( $fecha . ' 12:00:00', new \DateTimeZone( 'America/Santiago' ) ) )->getTimestamp();
	}

	/**
	 * @testdox El pedido 24995 sale «tarde por 1 día», que es el caso real que motivó esto.
	 *
	 * Datos exactos de producción: comprado el 4 jun, prometido 22-25 jun por el
	 * snapshot del propio pedido, listo el 26. Si esta prueba cambia de veredicto,
	 * el informe dejó de describir el caso que lo originó.
	 */
	public function test_the_real_late_order_reads_as_one_day_late(): void {
		$v = $this->sut->veredicto(
			$this->ts( '2026-06-04' ),
			$this->ts( '2026-06-22' ),
			$this->ts( '2026-06-25' ),
			$this->ts( '2026-06-26' ),
			$this->ts( '2026-08-24' )
		);

		self::assertSame( PromiseAudit::TARDE, $v['veredicto'] );
		self::assertSame( 1, $v['desvio_dias'] );
		self::assertSame( 22, $v['dias_reales'] );
		self::assertSame( 21, $v['dias_prometidos'] );
	}

	/**
	 * @testdox Llegar EL último día de la ventana es cumplir, no incumplir.
	 *
	 * Es el pedido 25538 (España): aterrizó exactamente en el techo. Contarlo como
	 * atraso inflaría el incumplimiento y llevaría a alargar plazos que sí se
	 * cumplen.
	 */
	public function test_landing_on_the_last_promised_day_counts_as_kept(): void {
		$v = $this->sut->veredicto(
			$this->ts( '2026-07-05' ),
			$this->ts( '2026-07-28' ),
			$this->ts( '2026-07-31' ),
			$this->ts( '2026-07-31' ),
			$this->ts( '2026-08-24' )
		);

		self::assertSame( PromiseAudit::DENTRO, $v['veredicto'] );
		self::assertSame( 0, $v['desvio_dias'] );
	}

	/**
	 * @testdox Llegar a cualquier HORA del último día prometido sigue siendo cumplir.
	 *
	 * Caso real de producción (pedido 25538): la promesa guardada apunta al
	 * COMIENZO del 31 de julio y el pedido estuvo listo ese mismo día a las 18:47.
	 * Comparando marcas de tiempo crudas el informe lo acusaba de «tarde» con
	 * desvío 0 — una promesa cumplida contada como incumplida, que es justo el
	 * error que llevaría a alargar plazos que sí funcionan.
	 *
	 * La prueba anterior no lo veía porque usaba la MISMA hora en los dos lados:
	 * el doble no modelaba producción.
	 */
	public function test_any_hour_of_the_last_promised_day_still_counts_as_kept(): void {
		$tz     = new \DateTimeZone( 'America/Santiago' );
		$compra = ( new \DateTimeImmutable( '2026-07-05 09:00:00', $tz ) )->getTimestamp();
		$min    = ( new \DateTimeImmutable( '2026-07-28 00:00:00', $tz ) )->getTimestamp();
		$max    = ( new \DateTimeImmutable( '2026-07-31 00:00:00', $tz ) )->getTimestamp();

		foreach ( array( '00:00:01', '12:00:00', '18:47:47', '23:59:59' ) as $hora ) {
			$listo = ( new \DateTimeImmutable( '2026-07-31 ' . $hora, $tz ) )->getTimestamp();
			$v     = $this->sut->veredicto( $compra, $min, $max, $listo, $this->ts( '2026-08-24' ) );

			self::assertSame( PromiseAudit::DENTRO, $v['veredicto'], "A las {$hora} del último día sigue siendo cumplir." );
			self::assertSame( 0, $v['desvio_dias'] );
		}

		// Y el día siguiente sí es tarde, aunque sea a las 00:00:01.
		$dia_siguiente = ( new \DateTimeImmutable( '2026-08-01 00:00:01', $tz ) )->getTimestamp();
		$v             = $this->sut->veredicto( $compra, $min, $max, $dia_siguiente, $this->ts( '2026-08-24' ) );

		self::assertSame( PromiseAudit::TARDE, $v['veredicto'] );
		self::assertSame( 1, $v['desvio_dias'] );
	}

	/**
	 * @testdox Un pedido abierto sólo es «vencido» cuando el máximo ya pasó.
	 */
	public function test_an_open_order_becomes_overdue_only_after_the_ceiling(): void {
		$comprado = $this->ts( '2026-08-12' );
		$min      = $this->ts( '2026-08-28' );
		$max      = $this->ts( '2026-09-02' );

		$dentro = $this->sut->veredicto( $comprado, $min, $max, null, $this->ts( '2026-08-24' ) );
		self::assertSame( PromiseAudit::ABIERTO, $dentro['veredicto'] );
		self::assertNull( $dentro['desvio_dias'], 'Un pedido en plazo no acumula atraso.' );

		$fuera = $this->sut->veredicto( $comprado, $min, $max, null, $this->ts( '2026-09-09' ) );
		self::assertSame( PromiseAudit::VENCIDO, $fuera['veredicto'] );
		self::assertSame( 7, $fuera['desvio_dias'] );
	}

	/**
	 * @testdox Sin promesa guardada no se inventa un veredicto.
	 *
	 * Es el pedido 24483: tiene la marca de preventa pero no guardó min/max. Darle
	 * un veredicto exigiría re-derivar la promesa con los plazos de HOY, que no
	 * son los que se le mostraron en mayo.
	 */
	public function test_without_a_stored_promise_no_verdict_is_invented(): void {
		foreach ( array( array( null, null ), array( null, $this->ts( '2026-06-25' ) ), array( $this->ts( '2026-06-22' ), null ) ) as $par ) {
			$v = $this->sut->veredicto( $this->ts( '2026-05-15' ), $par[0], $par[1], $this->ts( '2026-05-29' ), $this->ts( '2026-08-24' ) );

			self::assertSame( PromiseAudit::SIN_PROMESA, $v['veredicto'] );
			self::assertNull( $v['dias_prometidos'] );
			// Los días reales sí se conocen y se reportan: es dato, no veredicto.
			self::assertSame( 14, $v['dias_reales'] );
		}
	}

	/**
	 * @testdox Una ventana invertida se rechaza en vez de producir un veredicto absurdo.
	 */
	public function test_an_inverted_window_is_rejected(): void {
		$v = $this->sut->veredicto(
			$this->ts( '2026-06-04' ),
			$this->ts( '2026-06-25' ),
			$this->ts( '2026-06-22' ),
			$this->ts( '2026-06-26' ),
			$this->ts( '2026-08-24' )
		);

		self::assertSame( PromiseAudit::SIN_PROMESA, $v['veredicto'] );
	}

	/**
	 * @testdox Llegar antes de tiempo se reporta, y con signo negativo.
	 *
	 * No es un incumplimiento, pero sí la señal de que el piso del plazo está
	 * puesto demasiado tarde — la mitad de la decisión que el informe tiene que
	 * poder sostener.
	 */
	public function test_arriving_early_is_reported_with_a_negative_deviation(): void {
		$v = $this->sut->veredicto(
			$this->ts( '2026-06-04' ),
			$this->ts( '2026-06-22' ),
			$this->ts( '2026-06-25' ),
			$this->ts( '2026-06-18' ),
			$this->ts( '2026-08-24' )
		);

		self::assertSame( PromiseAudit::ANTES, $v['veredicto'] );
		self::assertSame( -4, $v['desvio_dias'] );
	}

	/**
	 * @testdox El resumen NO promedia desvíos: un adelanto no compensa un atraso.
	 *
	 * Promediar daría cero y diría que todo va bien mientras un cliente esperó
	 * cinco días de más. Se cuentan los incumplimientos y se reporta el PEOR.
	 */
	public function test_the_summary_never_cancels_a_delay_with_an_early_arrival(): void {
		$resumen = $this->sut->resumen(
			array(
				array( 'veredicto' => PromiseAudit::ANTES,  'dias_reales' => 14, 'dias_prometidos' => 21, 'desvio_dias' => -5 ),
				array( 'veredicto' => PromiseAudit::TARDE,  'dias_reales' => 26, 'dias_prometidos' => 21, 'desvio_dias' => 5 ),
				array( 'veredicto' => PromiseAudit::TARDE,  'dias_reales' => 23, 'dias_prometidos' => 21, 'desvio_dias' => 2 ),
				array( 'veredicto' => PromiseAudit::DENTRO, 'dias_reales' => 20, 'dias_prometidos' => 21, 'desvio_dias' => 0 ),
			)
		);

		self::assertSame( 4, $resumen['cerrados'] );
		self::assertSame( 2, $resumen['tarde'] );
		self::assertSame( 5, $resumen['peor_atraso'], 'El peor atraso es el que llega a soporte, no el promedio.' );
		self::assertSame( 26, $resumen['dias_reales_max'] );
	}

	/**
	 * @testdox Un vencido pesa en el peor atraso aunque todavía no haya llegado.
	 *
	 * Es el que más duele y el único que el cliente está sufriendo AHORA;
	 * dejarlo fuera del resumen haría que el informe se viera mejor cuanto peor
	 * fuera la situación.
	 */
	public function test_an_overdue_order_counts_towards_the_worst_delay(): void {
		$resumen = $this->sut->resumen(
			array(
				array( 'veredicto' => PromiseAudit::DENTRO,  'dias_reales' => 20, 'dias_prometidos' => 21, 'desvio_dias' => 0 ),
				array( 'veredicto' => PromiseAudit::VENCIDO, 'dias_reales' => null, 'dias_prometidos' => 21, 'desvio_dias' => 12 ),
			)
		);

		self::assertSame( 1, $resumen['vencidos'] );
		self::assertSame( 1, $resumen['cerrados'], 'Sólo el que llegó cuenta como cerrado: el vencido todavía no llega.' );
		self::assertSame( 12, $resumen['peor_atraso'] );
	}

	/**
	 * @testdox Un resumen vacío no inventa un cero tranquilizador.
	 */
	public function test_an_empty_summary_reports_nothing_rather_than_zero(): void {
		$resumen = $this->sut->resumen( array() );

		self::assertSame( 0, $resumen['cerrados'] );
		self::assertNull( $resumen['peor_atraso'], 'Sin datos, el peor atraso es desconocido, no cero.' );
		self::assertNull( $resumen['dias_reales_max'] );
	}
}
