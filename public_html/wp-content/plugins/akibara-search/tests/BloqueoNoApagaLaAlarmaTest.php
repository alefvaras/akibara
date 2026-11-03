<?php
/**
 * Un rebuild trabado no puede apagar la reparación barata del serving.
 *
 * QUÉ DECIDE HOY `watchdog_recovers_serving()`: sólo si la vuelta del watchdog
 * REANUDA un run (fase viva) o REPARA POR SU CUENTA (sin estado, BLOCKED, ABORTED).
 * La alarma ya no pasa por aquí: desde S-190efeef (2026-09-19)
 * `handle_watchdog()` llama a `maybe_recover_serving()` en TODAS las vueltas, y es
 * ese método el que lleva la cuenta del aviso, esté el rebuild como esté.
 *
 * DE DÓNDE VIENE: `handle_watchdog()` sólo miraba el serving cuando el estado del
 * rebuild era NULO, y `maybe_recover_serving()` es el único llamador de
 * `note_serving_down()`, la única puerta al correo de aviso. Bastaba un estado
 * trabado presente para que la tienda se quedara sin buscador ni filtros **y sin
 * el correo que lo cuenta**. Y el estado trabado no es raro:
 * `orphan_topology_without_state` lo acuña el propio comando de rescate si se borra
 * la option dejando las tablas sombra puestas —reproducido en el espejo con dos
 * órdenes, y a los ~40 s aparece solo con el mero tráfico—. BLOCKED y ABORTED
 * tampoco los reanuda nadie (`recovery_decision()` devuelve 'idle'), así que la
 * rama que se ejecutaba en su lugar no hacía absolutamente nada: se cambiaba la
 * única ayuda automática por un no-op. El primer arreglo (2026-09-06) extendió
 * este helper a BLOCKED y ABORTED; el segundo (S-190efeef) sacó la alarma de la
 * decisión, porque un run VIVO colgado tras el swap deja al cliente igual de mal.
 *
 * DONE y CLEANUP quedan fuera a propósito: ésos sí los cierra `resume_run()`.
 *
 * DÓNDE ESTÁ LA RED DE LA ALARMA: en `WatchdogVigilaElServingTest`, con pruebas de
 * comportamiento sobre `handle_watchdog()` entero y TODAS las fases `PHASE_*`
 * leídas por reflexión. Aquí hubo un candado de fuente
 * (`test_el_watchdog_decide_por_el_helper`) que leía el cuerpo de
 * `handle_watchdog()` y prohibía `null === $state`. Se retiró el 2026-09-19: la
 * decisión por estado nulo se mudó a `maybe_recover_serving()` —que el candado no
 * miraba—, así que seguía verde sin vigilar nada, y un `return` temprano allí
 * habría reabierto el agujero con esta clase en verde. Con el arnés de
 * comportamiento ya montado, un candado de fuente no compra nada que la matriz no
 * dé mejor.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\RebuildJob;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class BloqueoNoApagaLaAlarmaTest extends TestCase {

	/** @testdox Sin estado, el watchdog repara por su cuenta: no hay run que reanudar. */
	public function test_sin_estado_recupera(): void {
		self::assertTrue( $this->decide( null ) );
	}

	/**
	 * @testdox Un bloqueo huérfano NO apaga la reparación barata: el watchdog repara por su cuenta en vez de reanudar un run que nadie reanuda.
	 *
	 * Éste es el caso que costó tres horas de tienda sin buscador el 2026-09-05.
	 * (Que además suene la alarma ya no depende de esta decisión: véase
	 * `WatchdogVigilaElServingTest`.)
	 */
	public function test_un_bloqueo_huerfano_sigue_recuperando(): void {
		self::assertTrue(
			$this->decide(
				array(
					'phase'  => RebuildJob::PHASE_BLOCKED,
					'error'  => 'orphan_topology_without_state',
					'run_id' => 'cda09edc',
				)
			)
		);
	}

	/** @testdox Un run abortado tampoco apaga la reparación barata: nadie lo reanuda. */
	public function test_un_run_abortado_sigue_recuperando(): void {
		self::assertTrue( $this->decide( array( 'phase' => RebuildJob::PHASE_ABORTED ) ) );
	}

	/**
	 * @testdox Un run VIVO se reanuda, no se le pide un rebuild por encima.
	 *
	 * @dataProvider fases_vivas
	 */
	public function test_un_run_vivo_se_reanuda( string $fase ): void {
		self::assertFalse(
			$this->decide( array( 'phase' => $fase, 'run_id' => 'abc123' ) ),
			"La fase {$fase} la lleva resume_run(): recuperar el serving por encima sería pedir un rebuild sobre un run sano."
		);
	}

	/** @return array<string, array{0: string}> */
	public static function fases_vivas(): array {
		return array(
			'preparando' => array( RebuildJob::PHASE_PREPARING ),
			'building'   => array( RebuildJob::PHASE_BUILDING ),
			'alcanzando' => array( RebuildJob::PHASE_CATCHING_UP ),
			'swap'       => array( RebuildJob::PHASE_SWAP_INTENT ),
			'swapped'    => array( RebuildJob::PHASE_SWAPPED ),
			'publicando' => array( RebuildJob::PHASE_PUBLISHING ),
			// CLEANUP y DONE los cierra resume_run() por la vía de 'finish_done'.
			'limpiando'  => array( RebuildJob::PHASE_CLEANUP ),
			'hecho'      => array( RebuildJob::PHASE_DONE ),
		);
	}

	/**
	 * @testdox El panel dice QUÉ hacer ante un bloqueo huérfano, no sólo que pasó.
	 *
	 * El aviso ya explicaba bien que la tienda no se cayó y que no hay botón de
	 * reintento. Lo que faltaba era el procedimiento, que existe y está probado —
	 * y sobre todo el ORDEN, porque hacerlo al revés vuelve a acuñar el mismo
	 * bloqueo y quien esté de guardia lo repetiría creyendo que lo arregla.
	 */
	public function test_el_panel_apunta_al_rescate(): void {
		$fuente = (string) file_get_contents(
			dirname( __DIR__ ) . '/src/SearchAdminSection.php'
		);
		$fuente = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $fuente );

		self::assertStringContainsString(
			'orphan_topology_without_state',
			$fuente,
			'El panel tiene que reconocer el bloqueo huérfano para poder guiarlo.'
		);
		self::assertStringContainsString(
			'ops/rescate-buscador.sh produccion',
			$fuente,
			'El aviso debe traer el comando: el repositorio no se lee a las 3 de la mañana.'
		);
	}

	/**
	 * @param array<string, mixed>|null $state Estado persistido.
	 * @return bool
	 */
	private function decide( ?array $state ): bool {
		$metodo = new ReflectionMethod( RebuildJob::class, 'watchdog_recovers_serving' );
		$metodo->setAccessible( true );

		return (bool) $metodo->invoke( null, $state );
	}
}
