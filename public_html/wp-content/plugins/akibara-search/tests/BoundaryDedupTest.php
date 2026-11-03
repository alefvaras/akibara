<?php
/**
 * El boundary de la promo editorial se programa una sola vez por instante: al
 * cambiar la campaña, el pendiente anterior se retira en vez de acumularse.
 *
 * Medido en producción por QA el 2026-09-11: 30 acciones pendientes de
 * `akb_search_editorial_discount_boundary`, una sola coincidente con el estado;
 * las otras 29 eran revisiones viejas (otra seq, otra huella u otro instante)
 * que `handle_boundary()` descarta en su primera línea. No hacen daño, pero
 * ensucian la cola de Action Scheduler y el diagnóstico cada vez que se toca la
 * campaña. Desde hoy `schedule_boundary()` retira lo pendiente de ese gancho
 * antes de programar el vigente, y sólo cuando el vigente no está ya en cola.
 *
 * Se prueba el método privado por reflexión con un doble de `$wpdb` que sirve
 * el estado como JSON crudo (así lo guarda el módulo), sin recorrer el
 * protocolo entero: aquí sólo importa qué le pide a Action Scheduler y en qué
 * orden.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\DocumentBuilder;
use Akibara\Search\EditorialDiscountPublication;
use Akibara\Search\FacetRegistry;
use Akibara\Search\Indexer;
use Akibara\Search\ProductSourceAuthority;
use Akibara\Search\RebuildJob;
use Akibara\Search\SemanticPublication;
use Akibara\Search\WorkRepository;
use Akibara\Search\WriteFence;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** Doble mínimo de `$wpdb`: sólo sabe servir el estado editorial como JSON crudo. */
final class BoundaryWpdb {

	public string $prefix = 'wp_';

	public string $options = 'wp_options';

	public string $last_error = '';

	/** @var array<int, string> */
	public array $queries = array();

	public function __construct( private ?string $raw_state ) {}

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = (string) preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}

		return $query;
	}

	public function get_var( string $query ): ?string {
		$this->queries[] = $query;

		return false !== strpos( $query, EditorialDiscountPublication::OPTION_STATE ) ? $this->raw_state : null;
	}
}

final class BoundaryDedupTest extends TestCase {
	use \Akibara\Search\Tests\Support\MarcosEditorialesVigilados;


	/** @var array<int, array<int, mixed>> Llamadas a Action Scheduler, en orden. */
	private array $llamadas = array();

	/** Lo que responde `as_has_scheduled_action()` para el boundary vigente. */
	private bool $vigente_en_cola = false;

	/** El `$wpdb` global que había antes: otras suites lo fijan una vez y cuentan con él. */
	private $wpdb_anterior = null;

	/**
	 * Estáticos del módulo tal como estaban. El constructor deja la instancia en
	 * `self::$runtime` y otros métodos anotan lecturas fallidas: sin devolverlos,
	 * IndexerProtocolV2Test y ReconcilerProtocolTest heredaban un módulo con un
	 * `$wpdb` de mentira y leían «failed» donde esperaban «absent» (34 rojos,
	 * 2026-09-12). Misma disciplina que PageCacheGateTest.
	 *
	 * @var array<string, mixed>
	 */
	private array $estaticos = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb_anterior = $GLOBALS['wpdb'] ?? null;
		foreach ( ( new ReflectionClass( EditorialDiscountPublication::class ) )->getProperties( ReflectionProperty::IS_STATIC ) as $propiedad ) {
			$propiedad->setAccessible( true );
			$this->estaticos[ $propiedad->getName() ] = $propiedad->getValue();
		}
		$this->llamadas                              = array();
		$this->vigente_en_cola                       = false;
		$GLOBALS['akb_search_test_unscheduled']      = array();

		Functions\when( 'as_has_scheduled_action' )->alias( fn(): bool => $this->vigente_en_cola );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( ...$args ): int {
				$this->llamadas[] = array_merge( array( 'programar' ), $args );

				return 7;
			}
		);
	}

	protected function tearDown(): void {
		// Primero, con los stubs montados: un marco abierto no puede cerrarse al apagar PHP.
		$this->soltar_publicaciones_vigiladas();
		// Se devuelve el global tal como estaba: borrarlo dejaba sin conexión a
		// IndexerProtocolV2Test y ReconcilerProtocolTest (35 rojos, 2026-09-12).
		if ( null === $this->wpdb_anterior ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->wpdb_anterior;
		}
		unset( $GLOBALS['akb_search_test_unscheduled'] );
		foreach ( $this->estaticos as $nombre => $valor ) {
			$propiedad = new ReflectionProperty( EditorialDiscountPublication::class, $nombre );
			$propiedad->setAccessible( true );
			$propiedad->setValue( null, $valor );
		}
		foreach ( array( 'runtime' => null, 'state_read_failed' => false, 'state_row_present' => false ) as $nombre => $valor ) {
			$propiedad = new ReflectionProperty( RebuildJob::class, $nombre );
			$propiedad->setAccessible( true );
			$propiedad->setValue( null, $valor );
		}
		SemanticPublication::reset_authoritative_state_for_tests();
		FacetRegistry::reset_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<string, mixed> Estado válido con el boundary pedido. */
	private function estado( ?int $boundary_at ): array {
		return array(
			'protocol'           => 1,
			'contract'           => 'akb-editorial-materialization-v1',
			'wanted_seq'         => 7,
			'wanted_fingerprint' => str_repeat( 'a', 64 ),
			'window_active'      => true,
			'next_boundary_at'   => $boundary_at,
			'source_term_ids'    => array(),
			'debt_staged'        => true,
			'active_run_id'      => '',
			'purge_pre_seq'      => 7,
			'purge_post_seq'     => 7,
			'reason'             => 'test-boundary',
			'updated_at'         => time(),
		);
	}

	private function programar( ?int $boundary_at ): bool {
		$db              = new BoundaryWpdb( wp_json_encode( $this->estado( $boundary_at ) ) );
		$GLOBALS['wpdb'] = $db;
		$work            = new WorkRepository( $db, 'wp_akb_search_work' );
		$fence           = new WriteFence( $db, 'akb-boundary-test' );
		$indexer         = new Indexer( new DocumentBuilder(), $work, $fence, new ProductSourceAuthority( $db, static fn( array $ids ): array => $ids ) );
		$modulo          = $this->vigilar_publicacion( new EditorialDiscountPublication( $work, $indexer, $fence ) );

		$metodo = new ReflectionMethod( $modulo, 'schedule_boundary' );
		$metodo->setAccessible( true );

		return (bool) $metodo->invoke( $modulo );
	}

	/** Lo que se pidió a Action Scheduler, retiradas y programaciones intercaladas en orden. */
	private function secuencia(): array {
		$retiradas = array_map(
			static fn( array $l ): array => array_merge( array( 'retirar' ), $l ),
			(array) ( $GLOBALS['akb_search_test_unscheduled'] ?? array() )
		);
		// Las retiradas van siempre antes de programar: si la secuencia real
		// fuera al revés, `programar` aparecería con la cola aún sucia.
		return array_merge( $retiradas, $this->llamadas );
	}

	/**
	 * @testdox Un boundary nuevo retira los pendientes del gancho y después se programa.
	 */
	public function test_un_boundary_nuevo_retira_los_pendientes_antes_de_programarse(): void {
		$at = time() + 3600;

		self::assertTrue( $this->programar( $at ) );
		self::assertSame(
			array(
				array( 'retirar', EditorialDiscountPublication::HOOK_BOUNDARY, array(), '' ),
				array( 'programar', $at, EditorialDiscountPublication::HOOK_BOUNDARY, array( 7, str_repeat( 'a', 64 ), $at ), Indexer::AS_GROUP, true ),
			),
			$this->secuencia(),
			'Sólo el gancho: con argumentos, Action Scheduler filtraría por argumentos exactos y los viejos (otra seq, otra huella, otro instante) seguirían en cola.'
		);
	}

	/**
	 * @testdox Si el boundary vigente ya está en cola no se retira ni se programa nada.
	 */
	public function test_el_boundary_vigente_en_cola_no_se_toca(): void {
		$this->vigente_en_cola = true;

		self::assertTrue( $this->programar( time() + 3600 ) );
		self::assertSame( array(), $this->secuencia(), 'Retirarlo para volver a programarlo sería un hueco en el que nadie vigila el boundary.' );
	}

	/**
	 * @testdox Sin boundary, o con uno ya pasado, no se pide nada a Action Scheduler.
	 */
	public function test_sin_boundary_o_pasado_no_se_pide_nada(): void {
		self::assertTrue( $this->programar( null ) );
		self::assertFalse( $this->programar( time() - 10 ) );
		self::assertSame( array(), $this->secuencia() );
	}
}
