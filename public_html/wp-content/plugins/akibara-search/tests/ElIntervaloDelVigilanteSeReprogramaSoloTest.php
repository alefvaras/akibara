<?php
/**
 * Cambiar el intervalo del watchdog en un despliegue se aplica solo.
 *
 * `as_schedule_recurring_action()` no toca una acción que ya existe: cambiar
 * `RebuildJob::WATCHDOG_INTERVAL` dejaba corriendo el intervalo viejo para
 * siempre. El remedio era un comando a mano escrito en el docblock de la
 * constante… con el grupo `'akb-search'`, que no existe (el real es
 * `Indexer::AS_GROUP = 'akibara-search'`): quien lo seguía no cancelaba nada
 * (hallazgos S-0ac7abaa y S-0b7858a7, 2026-09-19).
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\Indexer;
use Akibara\Search\RebuildJob;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ElIntervaloDelVigilanteSeReprogramaSoloTest extends TestCase {

	/** Intervalo con el que «vive» la acción en la cola. */
	private int $intervalo_vivo = 300;

	/** @var array<int,array{hook:string,grupo:string}> */
	private array $bajas = array();

	/** @var array<int,array{hook:string,intervalo:int,grupo:string}> */
	private array $altas = array();

	private int $consultas_de_agenda = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'as_has_scheduled_action' )->justReturn( true );
		Functions\when( 'as_next_scheduled_action' )->justReturn( 1234567890 );
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $args ): array {
				++$this->consultas_de_agenda;
				TestCase::assertSame( Indexer::AS_GROUP, $args['group'] ?? '', 'Se pregunta por el grupo REAL.' );

				$intervalo = $this->intervalo_vivo;
				$agenda    = new class( $intervalo ) {
					private int $i;
					public function __construct( int $i ) {
						$this->i = $i;
					}
					public function get_recurrence(): int {
						return $this->i;
					}
				};

				return array(
					7 => new class( $agenda ) {
						private object $a;
						public function __construct( object $a ) {
							$this->a = $a;
						}
						public function get_schedule(): object {
							return $this->a;
						}
					},
				);
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( string $hook, array $args = array(), string $grupo = '' ): void {
				$this->bajas[] = array(
					'hook'  => $hook,
					'grupo' => $grupo,
				);
			}
		);
		Functions\when( 'as_schedule_recurring_action' )->alias(
			function ( int $cuando, int $intervalo, string $hook, array $args = array(), string $grupo = '' ): int {
				$this->altas[] = array(
					'hook'      => $hook,
					'intervalo' => $intervalo,
					'grupo'     => $grupo,
				);
				return 99;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function job(): RebuildJob {
		return ( new ReflectionClass( RebuildJob::class ) )->newInstanceWithoutConstructor();
	}

	/** @testdox La reparación diaria da de baja la acción con intervalo viejo y la recrea con el vigente, en el grupo real. */
	public function test_la_reparacion_diaria_reprograma_un_intervalo_viejo(): void {
		$this->job()->repair_watchdog();

		self::assertSame(
			array(
				array(
					'hook'  => RebuildJob::HOOK_WATCHDOG,
					'grupo' => Indexer::AS_GROUP,
				),
			),
			$this->bajas,
			'Con el grupo equivocado no se cancela nada: es justo el defecto.'
		);
		self::assertSame(
			array(
				array(
					'hook'      => RebuildJob::HOOK_WATCHDOG,
					'intervalo' => RebuildJob::WATCHDOG_INTERVAL,
					'grupo'     => Indexer::AS_GROUP,
				),
			),
			$this->altas
		);
	}

	/** @testdox Si el intervalo vivo ya es el vigente, la reparación no toca nada. */
	public function test_un_intervalo_correcto_no_se_toca(): void {
		$this->intervalo_vivo = RebuildJob::WATCHDOG_INTERVAL;

		$this->job()->repair_watchdog();

		self::assertSame( array(), $this->bajas );
		self::assertSame( array(), $this->altas );
	}

	/** @testdox Una visita corriente no paga la consulta del intervalo: eso es de la reparación diaria. */
	public function test_la_visita_corriente_no_consulta_la_agenda(): void {
		$this->job()->ensure_watchdog();

		self::assertSame( 0, $this->consultas_de_agenda );
		self::assertSame( array(), $this->bajas );
	}

	/** @testdox El comentario de la constante ya no manda a cancelar en un grupo que no existe. */
	public function test_el_docblock_nombra_el_grupo_real(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__ ) . '/src/RebuildJob.php' );

		self::assertStringNotContainsString( "array(), 'akb-search' )", $fuente );
		self::assertStringContainsString( "array(), '" . Indexer::AS_GROUP . "' )", $fuente );
	}
}
