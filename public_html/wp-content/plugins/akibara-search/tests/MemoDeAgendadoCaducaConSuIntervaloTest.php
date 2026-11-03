<?php
/**
 * S-3e1f3f03 — El memo de «ya está agendada» no dura más que la acción que protege.
 *
 * El vigilante del rebuild y la revisión diaria guardan un transient para no
 * preguntarle a Action Scheduler en cada visita. Los dos lo guardaban DOCE HORAS, y
 * lo ponían en cuanto comprobaban UNA vez que la acción existía. Si la acción
 * desaparecía justo después —una limpieza de la tabla de Action Scheduler, un
 * `wp db` de mantenimiento, una restauración parcial—, nadie la reponía hasta que el
 * memo caducara: medio día sin vigilante (y el vigilante es quien rescata un rebuild
 * colgado Y quien manda la alarma del buscador) y sin revisión diaria.
 *
 * Son pruebas de comportamiento con RELOJ: el doble de los transients caduca de
 * verdad, así que lo que se afirma es el hecho —«una vuelta después la acción vuelve
 * a existir»— y no el número que se le pasa a `set_transient()`.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\Reconciler;
use Akibara\Search\RebuildJob;
use Akibara\Search\Support\RecurringMemo;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname( __DIR__ ) . '/src/Arrival.php';
require_once dirname( __DIR__ ) . '/src/Reconciler.php';

defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

final class MemoDeAgendadoCaducaConSuIntervaloTest extends TestCase {

	/** Reloj del doble de transients, en segundos desde el arranque de la prueba. */
	private int $reloj = 0;

	/** @var array<string, array{valor: mixed, caduca: int}> */
	private array $transients = array();

	/** @var array<string, bool> Hook → ¿tiene Action Scheduler una acción pendiente? */
	private array $agendadas = array();

	/** @var array<int, string> Hooks que el código mandó (re)agendar. */
	private array $reagendadas = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reloj       = 0;
		$this->transients  = array();
		$this->agendadas   = array();
		$this->reagendadas = array();

		Functions\when( 'get_transient' )->alias(
			function ( string $nombre ) {
				$fila = $this->transients[ $nombre ] ?? null;
				if ( null === $fila || ( $fila['caduca'] > 0 && $fila['caduca'] <= $this->reloj ) ) {
					return false;
				}
				return $fila['valor'];
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $nombre, $valor, int $ttl = 0 ): bool {
				// Igual que WordPress: TTL 0 = no caduca nunca.
				$this->transients[ $nombre ] = array(
					'valor'  => $valor,
					'caduca' => $ttl > 0 ? $this->reloj + $ttl : 0,
				);
				return true;
			}
		);
		Functions\when( 'as_has_scheduled_action' )->alias( fn( string $hook ): bool => $this->agendadas[ $hook ] ?? false );
		Functions\when( 'as_next_scheduled_action' )->alias( fn( string $hook ) => ( $this->agendadas[ $hook ] ?? false ) ? 1234567890 : false );
		Functions\when( 'as_schedule_recurring_action' )->alias(
			function ( int $cuando, int $intervalo, string $hook ): int {
				$this->reagendadas[]      = $hook;
				$this->agendadas[ $hook ] = true;
				return 77;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox El vigilante que desaparece tras comprobarlo vuelve a existir UNA vuelta después, no medio día después.
	 */
	public function test_el_vigilante_borrado_se_repone_en_una_vuelta(): void {
		$job = ( new ReflectionClass( RebuildJob::class ) )->newInstanceWithoutConstructor();

		// Una visita cualquiera comprueba que el vigilante existe y pone el memo.
		$this->agendadas[ RebuildJob::HOOK_WATCHDOG ] = true;
		$job->ensure_watchdog();
		self::assertSame( array(), $this->reagendadas, 'Precondición: existía, no había nada que agendar.' );

		// Justo después alguien limpia la tabla de Action Scheduler.
		$this->agendadas[ RebuildJob::HOOK_WATCHDOG ] = false;

		// Mientras el memo vive no se pregunta: ése es su trabajo, y está bien.
		$this->reloj += RebuildJob::WATCHDOG_INTERVAL - 1;
		$job->ensure_watchdog();
		self::assertSame( array(), $this->reagendadas, 'Dentro del intervalo el memo ahorra las consultas a Action Scheduler.' );

		// Pero una vuelta del vigilante después, la primera visita lo repone.
		$this->reloj += 1;
		$job->ensure_watchdog();

		self::assertSame(
			array( RebuildJob::HOOK_WATCHDOG ),
			$this->reagendadas,
			'Con el memo de 12 h esta visita —y todas las de medio día— se fiaban de una comprobación vieja: sin vigilante no hay rescate del rebuild ni alarma del buscador.'
		);
	}

	/**
	 * @testdox La revisión diaria que desaparece tras comprobarla se repone en una hora, no en doce.
	 */
	public function test_la_revision_diaria_borrada_se_repone_en_una_hora(): void {
		$reconciler = ( new ReflectionClass( Reconciler::class ) )->newInstanceWithoutConstructor();

		$this->agendadas[ Reconciler::HOOK ] = true;
		$reconciler->ensure_scheduled();
		self::assertSame( array(), $this->reagendadas, 'Precondición: existía.' );

		$this->agendadas[ Reconciler::HOOK ] = false;

		$this->reloj += HOUR_IN_SECONDS - 1;
		$reconciler->ensure_scheduled();
		self::assertSame( array(), $this->reagendadas, 'Dentro de la hora el memo ahorra las consultas.' );

		$this->reloj += 1;
		$reconciler->ensure_scheduled();

		self::assertSame(
			array( Reconciler::HOOK ),
			$this->reagendadas,
			'Una hora después la revisión diaria vuelve a estar agendada; con 12 h de memo se retrasaba medio día.'
		);
	}

	/**
	 * @testdox La regla vive en UN sitio: el memo dura lo que el intervalo, con techo de una hora y sin poder ser eterno.
	 */
	public function test_la_regla_del_memo(): void {
		self::assertSame( RebuildJob::WATCHDOG_INTERVAL, RecurringMemo::ttl( RebuildJob::WATCHDOG_INTERVAL ), 'Una acción de 15 minutos: memo de 15 minutos.' );
		self::assertSame( RecurringMemo::MAX_TTL, RecurringMemo::ttl( DAY_IN_SECONDS ), 'Una acción diaria no deja el memo un día entero: techo de una hora.' );
		self::assertSame( HOUR_IN_SECONDS, RecurringMemo::MAX_TTL );
		self::assertGreaterThan( 0, RecurringMemo::ttl( 0 ), 'TTL 0 en set_transient() es «no caduca nunca»: el defecto, pero para siempre.' );
		self::assertGreaterThan( 0, RecurringMemo::ttl( -900 ) );
	}
}
