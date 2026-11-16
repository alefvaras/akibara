<?php
/**
 * Pruebas unitarias de StockWatcher: encolado y escalonamiento defensivo de reposición masiva.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	if ( ! class_exists( 'wpdb' ) ) {
		require_once __DIR__ . '/CaptureRestControllerTest.php';
	}
}

namespace Akibara\Business\Tests\Modules\BackInStock {

use Akibara\Business\Modules\BackInStock\StockWatcher;
use Akibara\Business\Modules\BackInStock\SubscriberRepository;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class StockWatcherTest extends TestCase {

	/** @var array<int, array{hook:string, args:array<string,mixed>, group:string}> */
	private array $async_enqueued = array();

	/** @var array<int, array{timestamp:int, hook:string, args:array<string,mixed>, group:string}> */
	private array $single_scheduled = array();

	/** @var array<int, int> Cuánta gente espera cada producto. */
	private array $esperando = array();

	/** @var array<int, object> Acciones pendientes simuladas en Action Scheduler. */
	private array $as_pending_actions = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		StockWatcher::reset_stagger();

		$this->async_enqueued     = array();
		$this->single_scheduled   = array();
		$this->esperando          = array();
		$this->as_pending_actions = array();

		Functions\when( 'apply_filters' )->alias( static fn ( string $hook, $val ) => $val );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );

		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( string $hook, array $args = array(), string $group = '' ): int {
				$this->async_enqueued[] = compact( 'hook', 'args', 'group' );
				return count( $this->async_enqueued );
			}
		);

		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
				$this->single_scheduled[] = compact( 'timestamp', 'hook', 'args', 'group' );
				return count( $this->single_scheduled );
			}
		);

		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $args = array(), string $return_format = 'objects' ): array {
				return $this->as_pending_actions;
			}
		);
	}

	protected function tearDown(): void {
		StockWatcher::reset_stagger();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function watcher(): StockWatcher {
		$db = new class( $this->esperando ) extends \wpdb {
			public function __construct( private array $esperando ) {}

			public function get_var( string $query ): int {
				preg_match( '/product_id = (\d+)/', $query, $m );
				return (int) ( $this->esperando[ (int) ( $m[1] ?? 0 ) ] ?? 0 );
			}

			public function prepare( string $query, ...$args ): string {
				foreach ( $args as $valor ) {
					$query = (string) preg_replace( '/%[ds]/', is_int( $valor ) ? (string) $valor : "'" . $valor . "'", $query, 1 );
				}
				return $query;
			}
		};

		return new StockWatcher( new SubscriberRepository( $db, 'wp_akb_back_in_stock' ) );
	}

	/**
	 * @testdox Encolado único: Un solo producto con suscriptores se encola de inmediato.
	 */
	public function test_encolado_unico_de_un_producto_se_despacha_inmediatamente(): void {
		$this->esperando = array( 701 => 5 );

		$this->watcher()->on_stock_status_change( 701, 'instock' );

		self::assertCount( 1, $this->async_enqueued );
		self::assertSame( StockWatcher::ASYNC_HOOK, $this->async_enqueued[0]['hook'] );
		self::assertSame( array( 'product_id' => 701 ), $this->async_enqueued[0]['args'] );
		self::assertSame( StockWatcher::AS_GROUP, $this->async_enqueued[0]['group'] );
		self::assertSame( array(), $this->single_scheduled );
	}

	/**
	 * @testdox Escalonamiento masivo: Múltiples productos distintos se espacian de 10 en 10 segundos.
	 */
	public function test_escalonamiento_de_multiples_productos_distintos_en_reposicion_masiva(): void {
		$this->esperando = array(
			701 => 3,
			702 => 2,
			703 => 4,
		);

		$now     = time();
		$watcher = $this->watcher();

		$watcher->on_stock_status_change( 701, 'instock' );
		$watcher->on_stock_status_change( 702, 'instock' );
		$watcher->on_stock_status_change( 703, 'instock' );

		// El primer producto se encola inmediatamente
		self::assertCount( 1, $this->async_enqueued );
		self::assertSame( array( 'product_id' => 701 ), $this->async_enqueued[0]['args'] );

		// Los siguientes dos productos se programan escalonados con as_schedule_single_action
		self::assertCount( 2, $this->single_scheduled );

		$item2 = $this->single_scheduled[0];
		self::assertSame( array( 'product_id' => 702 ), $item2['args'] );
		self::assertGreaterThanOrEqual( $now + 10, $item2['timestamp'] );

		$item3 = $this->single_scheduled[1];
		self::assertSame( array( 'product_id' => 703 ), $item3['args'] );
		self::assertGreaterThanOrEqual( $item2['timestamp'] + 10, $item3['timestamp'] );
	}

	/**
	 * @testdox Escalonamiento defensivo: Respeta acciones pendientes preexistentes en Action Scheduler.
	 */
	public function test_escalonamiento_respeta_acciones_existentes_en_action_scheduler(): void {
		$this->esperando = array( 705 => 2 );
		$future_ts       = time() + 30;

		$mock_action = new class( $future_ts ) {
			public function __construct( private int $ts ) {}
			public function get_schedule(): object {
				return new class( $this->ts ) {
					public function __construct( private int $ts ) {}
					public function get_date(): object {
						return new class( $this->ts ) {
							public function __construct( private int $ts ) {}
							public function getTimestamp(): int {
								return $this->ts;
							}
						};
					}
				};
			}
		};

		$this->as_pending_actions = array( $mock_action );

		$this->watcher()->on_stock_status_change( 705, 'instock' );

		// Al detectar acción pendiente futura, programa después de ella (+10s)
		self::assertSame( array(), $this->async_enqueued );
		self::assertCount( 1, $this->single_scheduled );
		self::assertSame( $future_ts + 10, $this->single_scheduled[0]['timestamp'] );
		self::assertSame( array( 'product_id' => 705 ), $this->single_scheduled[0]['args'] );
	}

	/**
	 * @testdox NullSchedule: Cuando Action Scheduler tiene una acción pendiente con get_date() null (NullSchedule), programa en now + 10s.
	 */
	public function test_escalonamiento_maneja_null_schedule_de_acciones_asincronas(): void {
		StockWatcher::reset_stagger();
		$this->esperando = array( 706 => 1 );
		$now             = time();

		$null_schedule_action = new class() {
			public function get_schedule(): object {
				return new class() {
					public function get_date(): ?object {
						return null; // ActionScheduler_NullSchedule
					}
				};
			}
		};

		$this->as_pending_actions = array( $null_schedule_action );

		$this->watcher()->on_stock_status_change( 706, 'instock' );

		self::assertSame( array(), $this->async_enqueued );
		self::assertCount( 1, $this->single_scheduled );
		self::assertSame( $now + 10, $this->single_scheduled[0]['timestamp'] );
		self::assertSame( array( 'product_id' => 706 ), $this->single_scheduled[0]['args'] );
	}

	/**
	 * @testdox Filtro akb_bis_stagger_seconds: Permite personalizar los segundos de separación.
	 */
	public function test_filtro_akb_bis_stagger_seconds_permite_ajustar_intervalo(): void {
		$this->esperando = array(
			801 => 1,
			802 => 1,
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $val ) {
				if ( 'akb_bis_stagger_seconds' === $hook ) {
					return 5; // 5 segundos
				}
				return $val;
			}
		);

		$now     = time();
		$watcher = $this->watcher();

		$watcher->on_stock_status_change( 801, 'instock' );
		$watcher->on_stock_status_change( 802, 'instock' );

		self::assertCount( 1, $this->async_enqueued );
		self::assertCount( 1, $this->single_scheduled );
		self::assertGreaterThanOrEqual( $now + 5, $this->single_scheduled[0]['timestamp'] );
	}

	/**
	 * @testdox Deduplicación: Si ya hay un job agendado para ese mismo producto, no se reencola.
	 */
	public function test_mismo_producto_no_se_duplica_si_ya_esta_programado(): void {
		$this->esperando = array( 700 => 3 );
		Functions\when( 'as_has_scheduled_action' )->alias(
			static fn( string $hook, $args = null, string $grupo = '' ): bool => is_array( $args ) && 700 === ( $args['product_id'] ?? 0 )
		);

		$this->watcher()->on_stock_status_change( 700, 'instock' );

		self::assertSame( array(), $this->async_enqueued );
		self::assertSame( array(), $this->single_scheduled );
	}
}
}
