<?php
/**
 * La cola de Action Scheduler entra en «Alertas del Sistema».
 *
 * R7-cola-sin-vigilar (2026-09-19): la tarjeta sólo miraba WP-Cron; la vitrina
 * llevó 14 días sin reordenarse (su acción diaria se desagendaba en el mismo
 * tick que debía correr) y la tarjeta decía «todo en verde».
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AdminDashboard;

use Akibara\Business\Utilities\OwnCrons;
use Akibara\Business\Utilities\QueueHealth;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ColaVigiladaTest extends TestCase {

	/** @var array<int, string> */
	private array $sql = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->sql = array();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_sql' )->returnArg();
		Functions\when( 'apply_filters' )->alias( static fn ( string $hook, $value ) => $value );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, array<string, mixed>> $failed   Filas de fallidas.
	 * @param array<int, array<string, mixed>> $past_due Filas de vencidas.
	 * @return QueueHealth
	 */
	private function cola( array $failed, array $past_due ): QueueHealth {
		return new QueueHealth(
			function ( string $sql ) use ( $failed, $past_due ): array {
				$this->sql[] = $sql;
				return str_contains( $sql, "status = 'failed'" ) ? $failed : $past_due;
			},
			'wp_actionscheduler_actions'
		);
	}

	/**
	 * La lista esperada es LITERAL, no derivada de `OwnCrons::QUEUE` (revisión
	 * independiente, B5): que `QUEUE` esté completa lo vigila
	 * `CronsQueNoQuedanHuerfanosTest` contra el código.
	 *
	 * @testdox Vigila los doce hooks propios de la cola, sin repetidos, y admite los de otros plugins por filtro.
	 */
	public function test_vigila_todos_los_hooks_propios(): void {
		Functions\when( 'apply_filters' )->alias( static fn ( string $hook, $value ) => $value );
		$esperados = array(
			'akb_bis_notify_product',
			'akb_order_preventa_dispatch_ready_email',
			'akb_order_preventa_recompute_for_product',
			'akb_order_preventa_release_date_changed_batch',
			'akb_order_preventa_verify_carrier_handoff',
			'akb_sf_digest_run',
			'akb_sf_scan_product',
			'akibara_catalog_order_daily',
			'akibara_catalog_order_recompute',
			'akibara_business_image_recompress',
			'akibara_business_image_recompress_purge',
			'akibara_business_cache_warm',
			'akb_welcome_coupon_gc_expired',
			'akibara_business_cleanup_collection_owner_fence',
			'akibara_edge_purge_run',
			'akb_bacs_send_reminder_email',
			'akb_bacs_auto_expire_order',
		);

		self::assertSame( $esperados, QueueHealth::own_hooks() );
		self::assertSame( count( $esperados ), count( array_unique( array_merge( ...array_values( OwnCrons::QUEUE ) ) ) ) );

		$this->cola( array(), array() )->alerts( 6 * HOUR_IN_SECONDS );
		foreach ( $esperados as $hook ) {
			self::assertStringContainsString( "'" . $hook . "'", $this->sql[0], 'Cada hook propio va en el IN de la consulta.' );
		}
		self::assertStringContainsString( "last_attempt_gmt >= UTC_TIMESTAMP() - INTERVAL 7 DAY", $this->sql[0], 'La ventana se mide por la hora del FALLO, no por la prevista.' );
		self::assertStringContainsString( 'INTERVAL 21600 SECOND', $this->sql[1] );

		// Otro plugin de la casa suma los suyos por filtro (akibara-rewards).
		Functions\when( 'apply_filters' )->alias( static fn ( string $hook, $value ) => 'akibara_queue_health_hooks' === $hook ? array_merge( (array) $value, array( 'akb_rewards_accrual_sweep_daily' ) ) : $value );
		self::assertContains( 'akb_rewards_accrual_sweep_daily', QueueHealth::own_hooks() );
	}

	/** @testdox Una acción propia fallida en la ventana sale como alerta con el hook, las veces y la última. */
	public function test_una_fallida_propia_avisa(): void {
		$alertas = $this->cola(
			array(
				array(
					'hook'     => 'akb_sf_digest_run',
					'c'        => '2',
					'last_gmt' => '2026-09-06 20:00:05',
				),
			),
			array()
		)->alerts( 6 * HOUR_IN_SECONDS );

		self::assertCount( 1, $alertas );
		self::assertSame( 'warning', $alertas[0]['level'] );
		self::assertStringContainsString( 'akb_sf_digest_run', $alertas[0]['html'] );
		self::assertStringContainsString( '2 veces', $alertas[0]['html'] );
		self::assertStringContainsString( '2026-09-06 20:00:05', $alertas[0]['html'] );
	}

	/** @testdox Una acción propia pendiente y vencida más allá del margen sale como «cola atrasada». */
	public function test_una_pendiente_vencida_avisa(): void {
		$alertas = $this->cola(
			array(),
			array(
				array(
					'hook'      => 'akibara_catalog_order_daily',
					'c'         => '3',
					'since_gmt' => '2026-09-05 03:05:00',
				),
			)
		)->alerts( 6 * HOUR_IN_SECONDS );

		self::assertCount( 1, $alertas );
		self::assertStringContainsString( 'Cola atrasada', $alertas[0]['html'] );
		self::assertStringContainsString( 'akibara_catalog_order_daily', $alertas[0]['html'] );
		self::assertStringContainsString( '3 pendientes', $alertas[0]['html'], 'Cuántas, no sólo la más vieja (revisión B6).' );
		self::assertStringContainsString( '2026-09-05 03:05:00', $alertas[0]['html'] );
	}

	/** @testdox [NEG] Sin filas no hay alertas; y sin base (suite) tampoco se consulta nada. */
	public function test_sin_filas_no_hay_alertas(): void {
		self::assertSame( array(), $this->cola( array(), array() )->alerts( 60 ) );

		$sin_base = new QueueHealth(
			function ( string $sql ): array {
				$this->sql[] = $sql;
				return array();
			},
			''
		);
		self::assertSame( array(), $sin_base->alerts( 60 ) );
		self::assertSame( 2, count( $this->sql ), 'Sin tabla no se lanza ninguna consulta más.' );
	}

	/** @testdox La tarjeta de salud incorpora la cola con el mismo margen que WP-Cron. */
	public function test_la_tarjeta_incorpora_la_cola(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/AdminDashboard/AdminDashboard.php' );

		self::assertStringContainsString( '( new QueueHealth() )->alerts( self::CRON_GRACE_SECONDS )', $fuente );
	}
}
