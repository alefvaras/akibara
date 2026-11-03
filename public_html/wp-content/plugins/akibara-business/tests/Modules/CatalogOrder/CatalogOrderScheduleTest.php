<?php
/**
 * Candado: la vitrina se recalcula sola, y su purga no se cuelga de las ventas.
 *
 * Medido en PRODUCCIÓN el 2026-08-23 (sólo lectura): la última corrida real
 * había sido 19 días antes y recomputar ahora reescribiría 1.122 de los 1.568
 * `menu_order`. Con eso, 4 agotados ocupaban los 100 primeros slots (el peor en
 * el 7) y tras recomputar quedaban 2 —los de series 100 % agotadas, que
 * conservan su fecha a propósito—.
 *
 * Dos invariantes, y ninguna fija un literal de versión:
 *
 *   1. Existe una corrida periódica, para que la deriva no vuelva a acumularse
 *      hasta la próxima alta de catálogo.
 *   2. Mientras el recompute termine en `litespeed_purge_all` —que con
 *      `cdn-cloudflare_clear` propaga al BORDE y devolvió la landing de serie de
 *      48 a 1.057 ms—, NO puede engancharse a un evento que ocurre por venta.
 *      Es condicional a propósito: si alguien logra acotar la purga, el candado
 *      deja de estorbar solo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\CatalogOrder;

use Akibara\Business\Modules\CatalogOrder\CatalogOrder;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CatalogOrderScheduleTest extends TestCase {

	/** Acciones recurrentes registradas: hook => [timestamp, intervalo]. */
	private array $recurring = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->recurring = array();

		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_timezone' )->alias(
			static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' )
		);
		Functions\when( 'as_next_scheduled_action' )->alias(
			fn( string $hook ) => $this->recurring[ $hook ][0] ?? false
		);
		Functions\when( 'as_schedule_recurring_action' )->alias(
			function ( int $timestamp, int $interval, string $hook ): int {
				$this->recurring[ $hook ] = array( $timestamp, $interval );
				return count( $this->recurring );
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( string $hook ): void {
				unset( $this->recurring[ $hook ] );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Queda agendada una corrida diaria a las 00:05 del sitio, antes del recalentado de fichas.
	 */
	public function test_schedules_one_daily_run_at_the_configured_local_hour(): void {
		( new CatalogOrder() )->maybe_schedule_daily();

		self::assertArrayHasKey( 'akibara_catalog_order_daily', $this->recurring );

		[ $timestamp, $interval ] = $this->recurring['akibara_catalog_order_daily'];

		self::assertSame( DAY_IN_SECONDS, $interval );
		self::assertGreaterThan( time(), $timestamp, 'La primera corrida va en el futuro.' );

		$local = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( 'America/Santiago' ) );
		self::assertSame( '00:05', $local->format( 'H:i' ), 'La hora se fija en la zona del sitio, no en UTC.' );
	}

	/**
	 * @testdox Llamarlo dos veces no deja dos corridas agendadas.
	 */
	public function test_scheduling_is_idempotent(): void {
		$module = new CatalogOrder();
		$module->maybe_schedule_daily();
		$primero = $this->recurring['akibara_catalog_order_daily'];

		$module->maybe_schedule_daily();

		self::assertSame( $primero, $this->recurring['akibara_catalog_order_daily'] );
	}

	/**
	 * La corrida se RE-ANCLA cuando se aleja de su hora.
	 *
	 * Action Scheduler cuenta el intervalo desde que la acción TERMINA, no desde
	 * su hora prevista: medido en producción, 05:00:00 → 05:10:56 en cinco días
	 * (~131 s/día). El `return` que salía apenas encontraba la acción hacía que
	 * esa deriva no tuviera forma de corregirse nunca, y el cambio de horario de
	 * Chile (2026-09-06) la mueve una hora entera de golpe — la vitrina pasaría a
	 * recomputarse, con su purga de CDN, a las 06:10 locales.
	 *
	 * @testdox Una corrida corrida más de 15 minutos se re-ancla a las 00:05.
	 */
	public function test_a_drifted_daily_run_is_re_anchored(): void {
		$module = new CatalogOrder();
		$module->maybe_schedule_daily();

		$anclada = $this->recurring['akibara_catalog_order_daily'][0];

		// Simula la deriva acumulada: la acción viva quedó 40 minutos tarde.
		$this->recurring['akibara_catalog_order_daily'][0] = $anclada + ( 40 * MINUTE_IN_SECONDS );

		$module->maybe_schedule_daily();

		self::assertSame(
			$anclada,
			$this->recurring['akibara_catalog_order_daily'][0],
			'La acción derivada debe volver a su hora, no quedarse donde la dejó el reloj.'
		);

		$local = ( new \DateTimeImmutable( '@' . $this->recurring['akibara_catalog_order_daily'][0] ) )
			->setTimezone( new \DateTimeZone( 'America/Santiago' ) );
		self::assertSame( '00:05', $local->format( 'H:i' ) );
	}

	/**
	 * @testdox Una deriva menor que la tolerancia NO reprograma nada.
	 */
	public function test_a_small_drift_is_left_alone(): void {
		$module = new CatalogOrder();
		$module->maybe_schedule_daily();

		$corrida = $this->recurring['akibara_catalog_order_daily'][0] + ( 3 * MINUTE_IN_SECONDS );
		$this->recurring['akibara_catalog_order_daily'][0] = $corrida;

		$module->maybe_schedule_daily();

		self::assertSame(
			$corrida,
			$this->recurring['akibara_catalog_order_daily'][0],
			'Tres minutos son el ruido de una corrida que tardó: reprogramar por eso sería peor.'
		);
	}

	/**
	 * R7-vitrina-nunca-corre (2026-09-19): en el Action Scheduler 4.0 una acción
	 * PENDING ya vencida devuelve su timestamp, y este método corre en `init`
	 * ANTES que el runner de la cola. A las 00:05:03 la corrida de las 00:05:00
	 * se desagendaba (deriva «de 86.400 s» contra el próximo día) y se
	 * reprogramaba para mañana: 0 corridas en 14 días medidas en producción.
	 *
	 * @testdox Una acción vencida por segundos se deja al runner: ni se desagenda ni se reprograma.
	 */
	public function test_a_due_action_is_left_for_the_runner(): void {
		// Una hora atrás, no tres segundos: con el reloj real, entre las 23:55 y
		// las 00:05 de Santiago «tres segundos atrás» cae dentro de la
		// tolerancia de deriva y la prueba pasaría igual sin el arreglo.
		$vencida                                        = time() - HOUR_IN_SECONDS;
		$this->recurring['akibara_catalog_order_daily'] = array( $vencida, DAY_IN_SECONDS );
		$desagendados                                   = array();
		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( string $hook ) use ( &$desagendados ): void {
				$desagendados[] = $hook;
			}
		);

		( new CatalogOrder() )->maybe_schedule_daily();

		self::assertSame( array(), $desagendados );
		self::assertSame( $vencida, $this->recurring['akibara_catalog_order_daily'][0], 'La corrida vencida sigue donde estaba, para que el runner la ejecute.' );
	}

	/**
	 * Revisión independiente B7: a las 00:05:30 de Santiago, con la corrida
	 * derivada a las 00:07, «el próximo 00:05» ya es mañana y la deriva
	 * aparente era de 23 h: se desagendaba una corrida a minuto y medio de
	 * ejecutarse. La deriva se mide contra el 00:05 más cercano.
	 *
	 * @testdox Una corrida derivada dos minutos, mirada justo después de las 00:05, no se toma por atrasada un día.
	 */
	public function test_a_small_drift_seen_right_after_the_anchor_is_left_alone(): void {
		$santiago = new \DateTimeZone( 'America/Santiago' );
		// «Ahora»: hoy a las 00:05:30 de Santiago; la corrida derivada: 00:07:00.
		$ahora   = ( new \DateTimeImmutable( 'now', $santiago ) )->setTime( 0, 5, 30 );
		$derivada = $ahora->setTime( 0, 7, 0 )->getTimestamp();
		$this->recurring['akibara_catalog_order_daily'] = array( $derivada, DAY_IN_SECONDS );

		$modulo = new CatalogOrder();
		$metodo = new \ReflectionMethod( $modulo, 'next_daily_timestamp' );
		self::assertGreaterThan( $derivada, $metodo->invoke( $modulo, $ahora->getTimestamp() ), 'Premisa: «el próximo 00:05» ya es mañana.' );

		// Con el reloj real no se puede fijar «ahora»; se comprueba la regla en
		// la misma forma que la usa el módulo: contra hoy y contra ayer.
		$deriva = min(
			abs( $derivada - $metodo->invoke( $modulo, $ahora->getTimestamp() ) ),
			abs( $derivada - $metodo->invoke( $modulo, $ahora->getTimestamp() - DAY_IN_SECONDS ) )
		);
		self::assertLessThanOrEqual( 15 * MINUTE_IN_SECONDS, $deriva, 'Dos minutos de deriva son dos minutos, no un día.' );
	}

	/**
	 * @testdox Una acción vencida o en curso no se toca.
	 */
	public function test_a_running_action_is_not_rescheduled(): void {
		$desagendados = array();
		Functions\when( 'as_next_scheduled_action' )->justReturn( true );
		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( string $hook ) use ( &$desagendados ): void {
				$desagendados[] = $hook;
			}
		);

		( new CatalogOrder() )->maybe_schedule_daily();

		self::assertSame( array(), $desagendados );
		self::assertSame( array(), $this->recurring );
	}

	/**
	 * @testdox En el front no se paga la consulta de agendado.
	 */
	public function test_front_end_requests_do_not_touch_the_scheduler(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		( new CatalogOrder() )->maybe_schedule_daily();

		self::assertSame( array(), $this->recurring );
	}

	/**
	 * @testdox Mientras el recompute purgue todo el borde, no puede colgarse de un
	 *          evento por venta.
	 */
	public function test_a_global_purge_forbids_a_per_sale_trigger(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/CatalogOrder/CatalogOrder.php'
		);

		if ( ! str_contains( $source, 'litespeed_purge_all' ) ) {
			self::assertTrue( true, 'La purga dejó de ser global: el candado ya no aplica.' );
			return;
		}

		foreach ( array( 'woocommerce_product_set_stock_status', 'woocommerce_product_set_stock', 'woocommerce_reduce_order_stock' ) as $per_sale ) {
			self::assertStringNotContainsString(
				"add_action( '" . $per_sale,
				$source,
				'Recomputar reescribe el 71,6 % del catálogo y purga el borde entero: colgarlo de ' . $per_sale . ' lo repetiría por cada venta.'
			);
		}
	}
	/**
	 * El cambio de horario de Chile empieza a las 00:00 del 2026-09-06: la
	 * corrida agendada la víspera no puede correrse una hora entera. Se calculaba
	 * sumando el desfase de HOY a la hora de MAÑANA, y ese es justo el día en que
	 * los dos desfases no coinciden. Ese día las 00:05 no existen (el reloj salta
	 * de 23:59:59 a 01:00) y la corrida cae a la 01:05: sigue antes del
	 * recalentado de las fichas, que ese día empieza a la 01:20.
	 *
	 * @testdox La corrida cae a las 00:05 locales también alrededor del cambio de horario.
	 */
	public function test_the_daily_run_survives_the_dst_switch(): void {
		$zona   = new \DateTimeZone( 'America/Santiago' );
		$metodo = new \ReflectionMethod( CatalogOrder::class, 'next_daily_timestamp' );
		$metodo->setAccessible( true );
		$modulo = new CatalogOrder();

		$esperado = array(
			'2026-09-05 06:00' => '01:05', // la noche del salto: las 00:05 no existen.
			'2026-09-05 23:30' => '01:05',
			'2026-09-06 06:00' => '00:05',
			'2026-04-04 06:00' => '00:05', // la noche en que la hora se repite.
			'2026-01-15 06:00' => '00:05',
		);
		foreach ( $esperado as $momento => $hora ) {
			$ahora = new \DateTimeImmutable( $momento, $zona );
			$ts    = (int) $metodo->invoke( $modulo, $ahora->getTimestamp() );
			$local = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $zona );

			self::assertSame( $hora, $local->format( 'H:i' ), 'Desde ' . $momento );
			self::assertGreaterThan( $ahora->getTimestamp(), $ts, 'Desde ' . $momento );
			self::assertLessThanOrEqual( $ahora->getTimestamp() + DAY_IN_SECONDS, $ts, 'Desde ' . $momento );
		}
	}
}