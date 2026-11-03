<?php
/**
 * El rescate del buscador suelta la sombra ANTES de retirar el estado, siempre.
 *
 * Con la option de estado borrada y las tablas `_new` todavía presentes, el
 * primer arranque de WordPress acuña `BLOCKED / orphan_topology_without_state`:
 * un pestillo que ni el panel ni `wp akb-search rebuild` deshacen. El orden
 * correcto sólo vivía en un guion de `ops/`, y nada en el código impedía
 * teclearlo al revés a las tres de la mañana (hallazgo S-937170c1; el
 * 2026-09-05 costó tres horas). `RebuildJob::rescue()` lo lleva al código.
 *
 * Los colaboradores estáticos se sustituyen con Patchwork (el bootstrap lo carga
 * antes que `src/`). Lo que se afirma es el ORDEN y las compuertas, no SQL.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\AuditLog;
use Akibara\Search\Indexer;
use Akibara\Search\Install;
use Akibara\Search\RebuildJob;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ElRescateNoAcunaElPestilloTest extends TestCase {

	/** Todo lo que pasa, en el orden en que pasa. */
	private \stdClass $mundo;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$m                 = new \stdClass();
		$m->pasos          = array();
		$m->hay_old        = false;
		$m->hay_new        = true;
		$m->topologia_ok   = true;
		$m->sombra_se_deja = true;  // ¿drop_shadow_tables() consigue soltarla?
		$m->estado         = array( 'phase' => RebuildJob::PHASE_BLOCKED, 'stalled' => false );
		$this->mundo       = $m;

		\Patchwork\redefine(
			Install::class . '::inspect_table_topology',
			static fn(): array => array(
				'valid'    => $m->topologia_ok,
				'topology' => array(
					'live' => array( 'docs' => true, 'tokens' => true, 'facets' => true ),
					'new'  => array( 'docs' => $m->hay_new, 'tokens' => $m->hay_new, 'facets' => $m->hay_new ),
					'old'  => array( 'docs' => $m->hay_old, 'tokens' => false, 'facets' => false ),
				),
			)
		);
		\Patchwork\redefine( RebuildJob::class . '::status', static fn(): ?array => $m->estado );
		\Patchwork\redefine(
			Install::class . '::drop_shadow_tables',
			static function () use ( $m ): bool {
				$m->pasos[] = 'soltar_sombra';
				if ( $m->sombra_se_deja ) {
					$m->hay_new = false;
				}
				return $m->sombra_se_deja;
			}
		);
		\Patchwork\redefine(
			Install::class . '::classify_topology',
			static fn(): string => $m->hay_new ? Install::TOPOLOGY_READY_SWAP : Install::TOPOLOGY_CLEAN_PRE
		);
		\Patchwork\redefine(
			AuditLog::class . '::record',
			static function ( string $accion ) use ( $m ): void {
				$m->pasos[] = 'cuaderno:' . $accion;
			}
		);

		Functions\when( 'delete_option' )->alias(
			static function ( string $nombre ) use ( $m ): bool {
				$m->pasos[] = 'retirar:' . $nombre;
				return true;
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( string $hook, array $args = array(), string $grupo = '' ) use ( $m ): void {
				$m->pasos[] = 'limpiar:' . $hook . '@' . $grupo;
			}
		);
	}

	protected function tearDown(): void {
		\Patchwork\restoreAll();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox La sombra se suelta PRIMERO y el estado se retira DESPUÉS: el orden que no acuña el pestillo. */
	public function test_el_orden_es_sombra_primero_estado_despues(): void {
		$r = RebuildJob::rescue();

		self::assertTrue( $r['ok'], $r['mensaje'] );
		self::assertSame(
			array(
				'soltar_sombra',
				'retirar:' . RebuildJob::OPTION_STATE,
				'limpiar:' . Indexer::HOOK_PUMP . '@' . Indexer::AS_GROUP,
				'cuaderno:rescate',
			),
			$this->mundo->pasos
		);
	}

	/** @testdox Si la sombra no se deja soltar, el estado NO se retira: hacerlo es lo que acuña el pestillo. */
	public function test_si_la_sombra_no_se_suelta_el_estado_se_queda(): void {
		$this->mundo->sombra_se_deja = false;

		$r = RebuildJob::rescue();

		self::assertFalse( $r['ok'] );
		self::assertSame( 'sombra_no_suelta', $r['codigo'] );
		self::assertSame( array( 'soltar_sombra' ), $this->mundo->pasos, 'Ni la option ni la cola se tocan.' );
	}

	/** @testdox Con tablas `_old` no toca nada: ahí `_old` es la vuelta atrás, no un residuo. */
	public function test_con_tablas_old_no_toca_nada(): void {
		$this->mundo->hay_old = true;

		$r = RebuildJob::rescue();

		self::assertSame( 'hay_old', $r['codigo'] );
		self::assertSame( array(), $this->mundo->pasos );
	}

	/** @testdox Un rebuild que avanza no se rescata. */
	public function test_un_run_vivo_que_avanza_no_se_toca(): void {
		$this->mundo->estado = array( 'phase' => RebuildJob::PHASE_BUILDING, 'stalled' => false );

		$r = RebuildJob::rescue();

		self::assertSame( 'run_vivo', $r['codigo'] );
		self::assertSame( array(), $this->mundo->pasos );
	}

	/** @testdox Un run vivo pero DETENIDO sí se rescata: es el «Ya hay un rebuild en curso» que no avanza. */
	public function test_un_run_detenido_si_se_rescata(): void {
		$this->mundo->estado = array( 'phase' => RebuildJob::PHASE_BUILDING, 'stalled' => true );

		self::assertTrue( RebuildJob::rescue()['ok'] );
	}

	/**
	 * Revisión independiente 2026-09-19 (ALTA): el latido se toca una vez por
	 * pasada de 200 productos. Con la base disputada una pasada tarda más de
	 * cinco minutos, el run sigue vivo y el rescate le tiraba las tablas `_new`
	 * con el pump escribiendo en ellas.
	 *
	 * @testdox Un latido viejo con trabajo reclamado hace menos de dos minutos es un run LENTO, no detenido.
	 */
	public function test_un_run_lento_no_es_un_run_detenido(): void {
		$viejo = RebuildJob::STALLED_SECONDS + 60;

		self::assertFalse( RebuildJob::stalled_verdict( false, $viejo, true, 1 ), 'Hay un lease vigente: alguien reclamó hace menos de LEASE_SECONDS.' );
		self::assertTrue( RebuildJob::stalled_verdict( false, $viejo, true, 0 ), 'Sin leases vigentes y sin latido: detenido de verdad.' );
		self::assertTrue( RebuildJob::stalled_verdict( false, $viejo, true, false ), 'Un conteo que falló no es prueba de vida: el rescate sigue operable.' );
	}

	/**
	 * El cableado: `status()` tiene que PREGUNTAR por los leases vigentes del run
	 * y pasárselos al veredicto. Se mide con la tabla de trabajo de verdad
	 * (`WorkRepository`) sobre un `wpdb` que contesta lo que una base contestaría.
	 *
	 * @testdox status() no reporta detenido un run con el latido viejo y un lease vigente.
	 */
	public function test_status_pregunta_por_los_leases_vigentes(): void {
		\Patchwork\restoreAll(); // Aquí `status()` es el de verdad.

		$wpdb = new class() {
			public string $last_error = '';
			public int $leases_vigentes = 1;

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $sql ), $args );
			}

			public function get_var( string $sql ) {
				return false !== strpos( $sql, 'lease_until > UTC_TIMESTAMP()' ) ? (string) $this->leases_vigentes : '7';
			}

			public function get_results( string $sql ): array {
				unset( $sql );
				return array(
					(object) array( 'scope' => 'baseline', 'status' => 'pending', 'total' => 6 ),
					(object) array( 'scope' => 'baseline', 'status' => 'leased', 'total' => 1 ),
				);
			}
		};

		$job  = ( new \ReflectionClass( RebuildJob::class ) )->newInstanceWithoutConstructor();
		$work = new \ReflectionProperty( RebuildJob::class, 'work' );
		$work->setAccessible( true );
		$work->setValue( $job, new \Akibara\Search\WorkRepository( $wpdb, 'wp_akb_search_work' ) );
		$runtime = new \ReflectionProperty( RebuildJob::class, 'runtime' );
		$runtime->setAccessible( true );
		$anterior = $runtime->getValue();
		$runtime->setValue( null, $job );

		\Patchwork\redefine( Install::class . '::classify_topology', static fn(): string => Install::TOPOLOGY_READY_SWAP );
		\Patchwork\redefine(
			RebuildJob::class . '::read_state',
			static fn(): array => array(
				'protocol'     => 2,
				'run_id'       => 'run-lento',
				'phase'        => RebuildJob::PHASE_BUILDING,
				'expected'     => 10,
				'started_at'   => time() - 900,
				'updated_at'   => time() - RebuildJob::STALLED_SECONDS - 60,
				'heartbeat_at' => time() - RebuildJob::STALLED_SECONDS - 60,
			)
		);

		try {
			$lento = RebuildJob::status();
			self::assertSame( 1, $lento['active_leases'] );
			self::assertFalse( $lento['stalled'], 'Alguien reclamó hace menos de LEASE_SECONDS: va lento, no está muerto.' );

			$wpdb->leases_vigentes = 0;
			$muerto                = RebuildJob::status();
			self::assertSame( 0, $muerto['active_leases'] );
			self::assertTrue( $muerto['stalled'], 'Sin latido y sin leases vigentes: detenido de verdad.' );
		} finally {
			$runtime->setValue( null, $anterior );
		}
	}

	/** @testdox El veredicto de detenido conserva sus otras tres condiciones. */
	public function test_el_veredicto_de_detenido_conserva_sus_condiciones(): void {
		$viejo = RebuildJob::STALLED_SECONDS + 60;

		self::assertFalse( RebuildJob::stalled_verdict( true, $viejo, true, 0 ), 'Una fase terminal no avanza por diseño.' );
		self::assertFalse( RebuildJob::stalled_verdict( false, RebuildJob::STALLED_SECONDS - 1, true, 0 ), 'El latido todavía es reciente.' );
		self::assertFalse( RebuildJob::stalled_verdict( false, $viejo, false, 0 ), 'Sin deuda no hay nada detenido.' );
	}

	/** @testdox Sin estado y con la sombra huérfana también rescata: es la antesala exacta del pestillo. */
	public function test_sin_estado_y_con_sombra_huerfana_rescata(): void {
		$this->mundo->estado = null;

		self::assertTrue( RebuildJob::rescue()['ok'] );
		self::assertSame( 'soltar_sombra', $this->mundo->pasos[0] );
	}

	/** @testdox Si no se puede leer qué tablas existen, no se toca nada. */
	public function test_topologia_ilegible_no_toca_nada(): void {
		$this->mundo->topologia_ok = false;

		self::assertSame( 'topologia_ilegible', RebuildJob::rescue()['codigo'] );
		self::assertSame( array(), $this->mundo->pasos );
	}
}
