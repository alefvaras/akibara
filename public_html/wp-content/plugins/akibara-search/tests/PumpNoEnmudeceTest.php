<?php
/**
 * El bombeo del carril live no puede quedarse mudo, ni inundar la cola.
 *
 * DOS DEFECTOS QUE SE TAPABAN EL UNO AL OTRO, y por eso se arreglan juntos:
 *
 * 1. `wake_live_work_after()` memoizaba en un array de INSTANCIA comparando
 *    contra `time() + $delay + 2`. El array nace vacío en cada petición PHP y el
 *    listón sube con el reloj: el dedup era insatisfacible por partida doble, así
 *    que cada petición encolaba una fila nueva. Medido en el espejo: la cola de
 *    `akb_search_pump_live` pasó de 25 a 1.268 pendientes en una hora sin que
 *    nadie tocara nada, y el retraso de `akb_search_rebuild_batch` respecto de su
 *    hora programada subió de 8-29 s a 433-450 s. La deuda nunca se pierde —vive
 *    en la tabla de trabajo, no en la cola—, pero el TURNO para drenarla sí.
 *
 * 2. `handle_live_pump()` tenía dos `return` que no re-armaban nada. El de
 *    `runtime_schema_unready` sólo llama a `Install::request_rebuild()`, que
 *    escribe una señal y no encola nada en Action Scheduler. Y el de
 *    `null === $claim` confunde «no hay deuda» con «la hay y aún no ha
 *    madurado», porque `claim_one_for_update()` filtra
 *    `available_at <= UTC_TIMESTAMP()` y toda escritura de precio estampa una
 *    barrera de 10 s sobre las filas que ensucia.
 *
 * Por qué juntos: hoy la inundación del defecto 1 ES la capacidad de drenaje del
 * carril, y tapa el defecto 2. Arreglar sólo el 1 pone el techo en una acción por
 * barrera y deja cada escape costando los 900 s del watchdog
 * (`RebuildJob::WATCHDOG_INTERVAL`). Arreglar sólo el 2 multiplica el re-armado
 * por petición. La pareja es el arreglo; cada mitad por su cuenta es una
 * regresión.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\Indexer;
use Akibara\Search\WorkRepository;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** wpdb mínimo: sólo lo que `WorkRepository::summary()` toca. */
final class PumpNoEnmudeceWpdb {

	public string $prefix = 'wp_';

	public string $last_error = '';

	/** @var array<int, object>|null Filas que devuelve el GROUP BY, o null para simular fallo. */
	public $rows = array();

	/** @param mixed ...$args Ignorados. @return string */
	public function prepare( string $query, ...$args ): string {
		unset( $args );

		return $query;
	}

	/** @return array<int, object>|null */
	public function get_results( string $query ) {
		unset( $query );

		return $this->rows;
	}
}

final class PumpNoEnmudeceTest extends TestCase {

	private PumpNoEnmudeceWpdb $wpdb;

	private Indexer $indexer;

	/** @var array<int, array{hook: string, args: array<string, mixed>, delayed: bool}> */
	private array $encoladas = array();

	/** @var array<int, array{hook: string, args: array<string, mixed>}> Pendientes que ya existen en la cola. */
	private array $pendientes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb       = new PumpNoEnmudeceWpdb();
		$this->encoladas  = array();
		$this->pendientes = array();

		Functions\when( 'sanitize_key' )->alias(
			static function ( string $key ): string {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) ?? '' );
			}
		);
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $args ): array {
				foreach ( $this->pendientes as $pendiente ) {
					if ( $pendiente['hook'] === ( $args['hook'] ?? '' )
						&& $pendiente['args'] === ( $args['args'] ?? array() ) ) {
						return array( 1 );
					}
				}

				return array();
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp, string $hook, array $args ): int {
				unset( $timestamp );
				$this->encoladas[] = array(
					'hook'    => $hook,
					'args'    => $args,
					'delayed' => true,
				);

				return count( $this->encoladas );
			}
		);

		// `Install::work_table()` resuelve el prefijo por el global; `backupGlobals`
		// lo restaura al salir de cada test.
		$GLOBALS['wpdb'] = $this->wpdb;

		$work = new WorkRepository( $this->wpdb, 'wp_akb_search_work' );

		$reflexion     = new ReflectionClass( Indexer::class );
		$this->indexer = $reflexion->newInstanceWithoutConstructor();

		$propiedad = new ReflectionProperty( Indexer::class, 'work' );
		$propiedad->setAccessible( true );
		$propiedad->setValue( $this->indexer, $work );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox El dedup del wake diferido vive en la BASE, no en la petición.
	 *
	 * Ésta es la prueba que el memo de instancia no podía pasar ni en teoría: dos
	 * INSTANCIAS distintas de Indexer, como dos peticiones PHP distintas, y una
	 * sola acción en la cola.
	 */
	public function test_dos_peticiones_no_encolan_dos_veces(): void {
		self::assertTrue( $this->indexer->wake_live_work_after( 10, 'source_write' ) );
		self::assertCount( 1, $this->encoladas, 'La primera petición sí encola.' );

		// Lo que la primera dejó en la cola, visible para cualquier proceso.
		$this->pendientes[] = array(
			'hook' => Indexer::HOOK_PUMP,
			'args' => array( 'barrier' => 'source_write' ),
		);

		$otra = ( new ReflectionClass( Indexer::class ) )->newInstanceWithoutConstructor();
		self::assertTrue( $otra->wake_live_work_after( 10, 'source_write' ) );

		self::assertCount(
			1,
			$this->encoladas,
			'Una segunda petición con la misma barrera NO puede encolar otra fila.'
		);
	}

	/** @testdox Cada barrera conserva su propia acción: el dedup no es global. */
	public function test_barreras_distintas_no_se_deduplican_entre_si(): void {
		$this->indexer->wake_live_work_after( 10, 'source_write' );
		$this->pendientes[] = array(
			'hook' => Indexer::HOOK_PUMP,
			'args' => array( 'barrier' => 'source_write' ),
		);

		$this->indexer->wake_live_work_after( 10, 'editorial_discount' );

		self::assertCount( 2, $this->encoladas );
		self::assertSame( 'editorial_discount', $this->encoladas[1]['args']['barrier'] );
	}

	/** @testdox Con deuda live pendiente, el pump se re-arma. */
	public function test_se_rearma_con_deuda_pendiente(): void {
		$this->wpdb->rows = array(
			(object) array(
				'scope'  => WorkRepository::SCOPE_LIVE,
				'status' => WorkRepository::STATUS_PENDING,
				'total'  => 1628,
			),
		);

		$this->rearmar( 'live_debt_immature' );

		self::assertCount( 1, $this->encoladas );
		self::assertSame( Indexer::HOOK_PUMP, $this->encoladas[0]['hook'] );
		self::assertSame( 'live_debt_immature', $this->encoladas[0]['args']['barrier'] );
	}

	/** @testdox Un lease vivo también cuenta: sus filas vuelven a estar disponibles al vencer. */
	public function test_se_rearma_con_leases_vivos(): void {
		$this->wpdb->rows = array(
			(object) array(
				'scope'  => WorkRepository::SCOPE_LIVE,
				'status' => WorkRepository::STATUS_LEASED,
				'total'  => 3,
			),
		);

		$this->rearmar( 'live_debt_immature' );

		self::assertCount( 1, $this->encoladas );
	}

	/**
	 * @testdox Una fila BLOQUEADA no re-arma nada.
	 *
	 * `blocked` no madura nunca. Re-armar por ella sería un wake cada 12 segundos
	 * para siempre: exactamente la inundación que este cambio viene a cerrar. Por
	 * eso no vale `outstanding_count()`, que cuenta todo lo que no está ackeado.
	 */
	public function test_una_fila_bloqueada_no_rearma(): void {
		$this->wpdb->rows = array(
			(object) array(
				'scope'  => WorkRepository::SCOPE_LIVE,
				'status' => WorkRepository::STATUS_BLOCKED,
				'total'  => 7,
			),
		);

		$this->rearmar( 'live_debt_immature' );

		self::assertSame( array(), $this->encoladas );
	}

	/** @testdox Sin deuda live, no se re-arma: el carril está al día. */
	public function test_sin_deuda_no_rearma(): void {
		$this->wpdb->rows = array(
			(object) array(
				'scope'  => WorkRepository::SCOPE_LIVE,
				'status' => WorkRepository::STATUS_ACKED,
				'total'  => 1628,
			),
			(object) array(
				'scope'  => WorkRepository::SCOPE_BASELINE,
				'status' => WorkRepository::STATUS_PENDING,
				'total'  => 400,
			),
		);

		$this->rearmar( 'live_debt_immature' );

		self::assertSame(
			array(),
			$this->encoladas,
			'La deuda de baseline no es del carril live: la drena el rebuild, no el pump.'
		);
	}

	/**
	 * @testdox Si la lectura falla, degrada ABIERTO y re-arma igual.
	 *
	 * Un wake de más no cuesta nada: el dedup lo topa en uno por barrera. Uno de
	 * menos congela los precios del escaparate un cuarto de hora.
	 */
	public function test_un_fallo_de_lectura_degrada_abierto(): void {
		$this->wpdb->rows       = null;
		$this->wpdb->last_error = 'MySQL server has gone away';

		$this->rearmar( 'live_debt_immature' );

		self::assertCount( 1, $this->encoladas );
	}

	/**
	 * @testdox Ningún `return` de handle_live_pump se va sin re-armar.
	 *
	 * Candado estructural, no de redacción: se cuentan los `return` del cuerpo del
	 * método sobre la fuente SIN COMENTARIOS —si no, el propio docblock que
	 * explica el arreglo tumbaría la prueba— y se exige que cada uno venga
	 * precedido por la llamada al re-armado.
	 */
	public function test_ningun_return_se_va_sin_rearmar(): void {
		$cuerpo = self::sin_comentarios( self::cuerpo_de( 'handle_live_pump' ) );

		$returns = preg_match_all( '/\breturn\s*;/', $cuerpo );
		self::assertSame( 2, $returns, 'handle_live_pump tiene dos salidas tempranas.' );

		$rearmes = preg_match_all(
			'/\$this->rearm_if_live_debt_pending\([^)]*\);\s*return\s*;/',
			$cuerpo
		);
		self::assertSame(
			$returns,
			$rearmes,
			'Cada salida temprana del pump debe re-armar antes de irse, o el carril'
				. ' se queda mudo hasta el watchdog (900 s).'
		);
	}

	/**
	 * @testdox El dedup del wake diferido no puede volver a ser un memo de proceso.
	 *
	 * El memo anterior era invisible entre peticiones Y comparaba contra un listón
	 * que sube con el reloj. Se fija la propiedad estructural: el dedup pregunta a
	 * `has_pending_action()`, como sus dos hermanas.
	 */
	public function test_el_dedup_pregunta_a_la_cola(): void {
		$cuerpo = self::sin_comentarios( self::cuerpo_de( 'wake_live_work_after' ) );

		self::assertStringContainsString(
			'$this->has_pending_action( self::HOOK_PUMP, $args )',
			$cuerpo,
			'El dedup tiene que consultar la cola, no la memoria del proceso.'
		);
		self::assertStringNotContainsString(
			'$run_at',
			$cuerpo,
			'Un predicado con fecha es insatisfacible: el listón sube con el reloj.'
		);
		self::assertStringNotContainsString(
			'deferred_wakes',
			(string) file_get_contents( dirname( __DIR__ ) . '/src/Indexer.php' ),
			'El memo de proceso queda muerto: se barre, no se deja de adorno.'
		);
	}

	/**
	 * @param string $barrera Barrera con la que se pide el re-armado.
	 * @return void
	 */
	private function rearmar( string $barrera ): void {
		$metodo = new ReflectionMethod( Indexer::class, 'rearm_if_live_debt_pending' );
		$metodo->setAccessible( true );
		$metodo->invoke( $this->indexer, $barrera );
	}

	/**
	 * @param string $metodo Nombre del método.
	 * @return string Sólo su cuerpo.
	 */
	private static function cuerpo_de( string $metodo ): string {
		$reflexion = new ReflectionMethod( Indexer::class, $metodo );
		$fuente    = (array) file( (string) $reflexion->getFileName() );

		return implode(
			'',
			array_slice(
				$fuente,
				$reflexion->getStartLine() - 1,
				$reflexion->getEndLine() - $reflexion->getStartLine() + 1
			)
		);
	}

	/**
	 * @param string $codigo Fuente PHP.
	 * @return string Sin comentarios de bloque ni de línea.
	 */
	private static function sin_comentarios( string $codigo ): string {
		return (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $codigo );
	}
}
