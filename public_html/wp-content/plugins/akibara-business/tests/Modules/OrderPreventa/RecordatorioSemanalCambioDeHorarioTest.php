<?php
/**
 * El recordatorio semanal de preventa corre el domingo a las 03:00 de Chile,
 * también después del cambio de horario.
 *
 * Hallazgo S-ff99475d (2026-09-19): el ancla inicial se calculaba bien con el
 * calendario de la zona, pero `weekly` es un intervalo FIJO de 604.800 s y
 * WordPress re-agenda sumándolo al instante UTC guardado. Agendado en invierno
 * (03:00 = 07:00 UTC), desde el primer domingo de septiembre corría a las 04:00;
 * agendado en verano, desde abril a las 02:00. `maybe_schedule()` salía apenas
 * encontraba el evento, así que nada lo devolvía a su sitio.
 *
 * Reloj inyectado y las dos fechas del cambio (2026-09-06 y 2026-04-05): una
 * prueba con la hora de hoy caduca sola dos veces al año.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\RemindersCron;
use Akibara\Business\Modules\OrderPreventa\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class RecordatorioSemanalCambioDeHorarioTest extends TestCase {

	/** @var mixed */
	private $options_previas;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// N-storetime (2026-09-19): RemindersCron ya no hardcodea la zona, la
		// pide a StoreTime::zone() (Utilities), que sin este stub caería a UTC
		// y desalinearía TODO este test de cambio de horario.
		Functions\when( 'wp_timezone' )->alias( static fn (): \DateTimeZone => new \DateTimeZone( 'America/Santiago' ) );

		$this->options_previas           = $GLOBALS['akibara_test_options'] ?? null;
		$GLOBALS['akibara_test_options'] = array();
	}

	protected function tearDown(): void {
		if ( null === $this->options_previas ) {
			unset( $GLOBALS['akibara_test_options'] );
		} else {
			$GLOBALS['akibara_test_options'] = $this->options_previas;
		}

		Monkey\tearDown();
		parent::tearDown();
	}

	private static function utc( string $momento ): int {
		return ( new \DateTimeImmutable( $momento, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
	}

	private static function hora_chile( int $ts ): string {
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( new \DateTimeZone( 'America/Santiago' ) )->format( 'D Y-m-d H:i' );
	}

	private function cron_a_las( string $ahora_utc ): RemindersCron {
		$ahora = self::utc( $ahora_utc );

		return new RemindersCron( static fn(): int => $ahora );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function eventos_corridos_por_el_cambio(): array {
		return array(
			'agendado en invierno, llega septiembre: 04:00 → 03:00' => array( '2026-08-30 12:00:00', '2026-09-06 07:00:00', '2026-09-06 06:00:00' ),
			'agendado en verano, llega abril: 02:00 → 03:00'        => array( '2026-03-29 12:00:00', '2026-04-05 06:00:00', '2026-04-05 07:00:00' ),
		);
	}

	/**
	 * @dataProvider eventos_corridos_por_el_cambio
	 * @testdox El evento que el cambio de horario corrió una hora vuelve al domingo 03:00 de Chile
	 */
	public function test_el_evento_corrido_vuelve_a_su_sitio( string $ahora, string $agendado, string $esperado ): void {
		$agendado_ts = self::utc( $agendado );
		$esperado_ts = self::utc( $esperado );
		self::assertNotSame( 'Sun ' . substr( $agendado, 0, 10 ) . ' 03:00', self::hora_chile( $agendado_ts ), 'El caso no reproduce el corrimiento.' );
		self::assertSame( 'Sun ' . substr( $esperado, 0, 10 ) . ' 03:00', self::hora_chile( $esperado_ts ) );

		Functions\when( 'wp_next_scheduled' )->justReturn( $agendado_ts );
		Functions\expect( 'wp_unschedule_event' )->once()->with( $agendado_ts, RemindersCron::HOOK );
		Functions\expect( 'wp_schedule_event' )->once()->with( $esperado_ts, 'weekly', RemindersCron::HOOK );

		$this->cron_a_las( $ahora )->maybe_schedule();
	}

	/** @testdox Bien anclado, no se toca: ni una escritura en la option de cron por petición */
	public function test_bien_anclado_no_se_toca(): void {
		$agendado_ts = self::utc( '2026-09-20 06:00:00' );
		self::assertSame( 'Sun 2026-09-20 03:00', self::hora_chile( $agendado_ts ) );

		Functions\when( 'wp_next_scheduled' )->justReturn( $agendado_ts );
		Functions\expect( 'wp_unschedule_event' )->never();
		Functions\expect( 'wp_schedule_event' )->never();

		$this->cron_a_las( '2026-09-15 12:00:00' )->maybe_schedule();
		self::addToAssertionCount( 1 );
	}

	/**
	 * Los dos casos en que la guarda de «vencido» es lo ÚNICO que lo impide: en
	 * ambos el ancla corregida cae en el futuro, así que sin ella el evento se
	 * movería con su pasada pendiente.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function eventos_vencidos(): array {
		return array(
			'corrido a las 02:00 de abril, vencido hace 10 min' => array( '2026-04-05 06:10:00', '2026-04-05 06:00:00' ),
			'fuera de domingo, vencido hace 10 min'             => array( '2026-09-23 15:10:00', '2026-09-23 15:00:00' ),
		);
	}

	/**
	 * @dataProvider eventos_vencidos
	 * @testdox Un evento vencido espera a WP-Cron: moverlo sería saltarse la pasada de esta semana
	 */
	public function test_un_evento_vencido_no_se_mueve( string $ahora, string $agendado ): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( self::utc( $agendado ) );
		Functions\expect( 'wp_unschedule_event' )->never();
		Functions\expect( 'wp_schedule_event' )->never();

		$this->cron_a_las( $ahora )->maybe_schedule();
		self::addToAssertionCount( 1 );
	}

	/** @testdox Dentro de la hora corrida (03:30, evento a las 04:00) corre tarde esta vez, pero corre */
	public function test_en_la_hora_corrida_no_se_salta_la_semana(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( self::utc( '2026-09-06 07:00:00' ) );
		Functions\expect( 'wp_unschedule_event' )->never();
		Functions\expect( 'wp_schedule_event' )->never();

		$this->cron_a_las( '2026-09-06 06:30:00' )->maybe_schedule();
		self::addToAssertionCount( 1 );
	}

	/** @testdox Un evento que ni cae en domingo se re-ancla al próximo domingo 03:00 */
	public function test_un_evento_fuera_de_domingo_se_re_ancla(): void {
		$agendado_ts = self::utc( '2026-09-23 15:00:00' );
		$esperado_ts = self::utc( '2026-09-27 06:00:00' );

		Functions\when( 'wp_next_scheduled' )->justReturn( $agendado_ts );
		Functions\expect( 'wp_unschedule_event' )->once()->with( $agendado_ts, RemindersCron::HOOK );
		Functions\expect( 'wp_schedule_event' )->once()->with( $esperado_ts, 'weekly', RemindersCron::HOOK );

		$this->cron_a_las( '2026-09-21 12:00:00' )->maybe_schedule();
		self::addToAssertionCount( 1 ); // Las expectativas las verifica Mockery al cerrar.
	}

	/**
	 * El docblock de `next_sunday_03_ts()` decía «pública por tests (verificar
	 * offset DST correcto en abril/septiembre)» y esa prueba no existía.
	 *
	 * @testdox Sin evento, agenda el próximo domingo 03:00 de Chile a ambos lados de cada cambio de horario
	 */
	public function test_sin_evento_agenda_el_proximo_domingo_a_las_tres(): void {
		$casos = array(
			'2026-09-05 12:00:00' => '2026-09-06 06:00:00', // Víspera del horario de verano: el domingo ya es −03.
			'2026-04-04 12:00:00' => '2026-04-05 07:00:00', // Víspera del horario de invierno: el domingo ya es −04.
			'2026-07-01 12:00:00' => '2026-07-05 07:00:00',
			'2026-12-01 12:00:00' => '2026-12-06 06:00:00',
		);

		foreach ( $casos as $ahora => $esperado ) {
			$cron = $this->cron_a_las( $ahora );
			self::assertSame( 'Sun ' . substr( $esperado, 0, 10 ) . ' 03:00', self::hora_chile( $cron->next_sunday_03_ts() ), "Desde {$ahora} UTC." );
			self::assertSame( self::utc( $esperado ), $cron->next_sunday_03_ts() );
		}

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_unschedule_event' )->never();
		Functions\expect( 'wp_schedule_event' )->once()->with( self::utc( '2026-09-06 06:00:00' ), 'weekly', RemindersCron::HOOK );

		$this->cron_a_las( '2026-09-05 12:00:00' )->maybe_schedule();
	}

	/** @testdox Con el recordatorio apagado en el panel se retira el evento y no se re-ancla nada */
	public function test_apagado_retira_y_no_re_ancla(): void {
		$GLOBALS['akibara_test_options'] = array( Settings::OPT_T7_ENABLED => 'no' );
		$agendado_ts                     = self::utc( '2026-09-06 07:00:00' );

		Functions\when( 'wp_next_scheduled' )->justReturn( $agendado_ts );
		Functions\expect( 'wp_unschedule_event' )->once()->with( $agendado_ts, RemindersCron::HOOK );
		Functions\expect( 'wp_schedule_event' )->never();

		$this->cron_a_las( '2026-08-30 12:00:00' )->maybe_schedule();
		self::addToAssertionCount( 1 );
	}
}
