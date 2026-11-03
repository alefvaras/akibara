<?php
/**
 * El bombeo del rebuild encadena lotes en vez de volver a la cola por cada uno.
 *
 * MEDIDO sobre un rebuild real (1.568 docs): 37 s de trabajo repartidos en 21
 * acciones de Action Scheduler que tardaron 158 s de reloj — el 66 % era espera
 * de cola, no CPU. Y ese reloj es exactamente la ventana en que el autocomplete
 * responde 503, porque el gate de servido cuelga del mismo lease.
 *
 * Las tres invariantes: se encadena sólo con el lote LLENO y presupuesto vivo,
 * el wake-up se encola únicamente en la vuelta que cierra la invocación
 * (encolarlo en cada vuelta reintroduce el ruido de cola que esto elimina), y
 * el presupuesto es de RELOJ, así que ninguna invocación corre sin techo.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\RebuildJob;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PumpChainingTest extends TestCase {

	/** @testdox Un lote LLENO con presupuesto vivo encadena otra vuelta. */
	public function test_un_lote_lleno_encadena(): void {
		self::assertTrue( $this->should_chain( RebuildJob::BATCH_SIZE, microtime( true ) + 10 ) );
		self::assertTrue( $this->should_chain( RebuildJob::BATCH_SIZE + 5, microtime( true ) + 10 ) );
	}

	/**
	 * @testdox Un lote CORTO cierra la invocación aunque sobre presupuesto.
	 *
	 * Un lote corto significa que la deuda del scope se acabó: encadenar sería
	 * una vuelta entera de fence y estado para reclamar cero.
	 */
	public function test_un_lote_corto_cierra_la_invocacion(): void {
		self::assertFalse( $this->should_chain( RebuildJob::BATCH_SIZE - 1, microtime( true ) + 10 ) );
		self::assertFalse( $this->should_chain( 0, microtime( true ) + 10 ) );
	}

	/** @testdox Agotado el presupuesto de reloj, no se encadena aunque quede deuda. */
	public function test_sin_presupuesto_no_se_encadena(): void {
		self::assertFalse( $this->should_chain( RebuildJob::BATCH_SIZE, microtime( true ) - 1 ) );
	}

	/** @testdox El presupuesto es de reloj y tiene techo. */
	public function test_el_presupuesto_es_de_reloj_y_acotado(): void {
		self::assertGreaterThan( 0, RebuildJob::PUMP_BUDGET_SECONDS );
		self::assertLessThanOrEqual(
			30,
			RebuildJob::PUMP_BUDGET_SECONDS,
			'Tiene que caber holgado bajo cualquier max_execution_time razonable.'
		);
	}

	/**
	 * @testdox El wake-up se encola sólo en la vuelta que cierra la invocación.
	 *
	 * `pump_scope()` no se puede doblar (la clase es final y sus colaboradores
	 * privados), así que la invariante se fija donde se decide: el argumento
	 * `allow_wakeup` de `advance_run()` exige que la vuelta NO encadene.
	 */
	public function test_el_wakeup_va_solo_en_la_vuelta_que_cierra(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/RebuildJob.php' );

		self::assertMatchesRegularExpression(
			'/\$deadline\s*=\s*microtime\( true \) \+ self::PUMP_BUDGET_SECONDS;/',
			$source,
			'El presupuesto arranca una vez por invocación, no por vuelta.'
		);
		self::assertMatchesRegularExpression(
			'/\$continua\s*=\s*self::should_chain\( \$processed, \$deadline \);\s*\n\s*\n?\s*\$this->advance_run\( \$run_id, \$async, \$processed > 0, \$processed > 0 && ! \$continua \);/',
			$source
		);
	}

	/**
	 * @param int   $processed Claims de la vuelta.
	 * @param float $deadline  Instante límite.
	 * @return bool
	 */
	private function should_chain( int $processed, float $deadline ): bool {
		$method = new ReflectionMethod( RebuildJob::class, 'should_chain' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $processed, $deadline );
	}
}
