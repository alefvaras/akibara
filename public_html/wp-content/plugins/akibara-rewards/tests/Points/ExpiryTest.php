<?php
/**
 * Contrato del vencimiento de puntos (orden del dueño 2026-08-30).
 *
 * Fija la contabilidad FIFO contra el ledger: vence EXACTAMENTE lo ganado
 * hace más de N meses que nunca se usó (los débitos consumen primero lo más
 * viejo), la corrida es idempotente por día (event_key), el aviso de piedad
 * sale una vez al mes, y 0 meses = jamás vence.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Expiry;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * @since 0.2.0
 */
final class ExpiryTest extends RewardsTestCase {

	private Expiry $expiry;

	/**
	 * Modo del cron de vencimiento para ESTA prueba. Se elige por el seam, no
	 * por `function_exists`: en cuanto una prueba nombra
	 * as_enqueue_async_action, la función queda declarada en el PROCESO y
	 * todas las demás «ven» Action Scheduler — con la suite en orden
	 * aleatorio, el modo dependía de la suerte (contagio de function_exists,
	 * lección ya documentada).
	 */
	private bool $batches = false;

	/** @var array<int,array{0:string,1:array<int,mixed>}> Lotes encolados. */
	private array $enqueued = array();

	/** @var array<int,array{0:string,1:array<int,mixed>}> Acciones asíncronas. */
	private array $async = array();

	protected function setUp(): void {
		parent::setUp();

		$this->expiry  = new Expiry();
		$this->batches = false;

		\Brain\Monkey\Filters\expectApplied( 'akb_rewards_expiry_use_batches' )
			->zeroOrMoreTimes()
			->andReturnUsing( fn(): bool => $this->batches );

		// Action Scheduler declarado SIEMPRE: el modo lo manda $this->batches.
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $ts, $hook, $args ) {
				unset( $ts );
				$this->enqueued[] = array( $hook, $args );
				return 1;
			}
		);
		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( $hook, $args ) {
				$this->async[] = array( $hook, $args );
				return 1;
			}
		);
		// Reapertura S-aaf18a42 (2026-09-19): sin este stub, function_exists()
		// en Expiry.php:414 es falso y la rama del dedupe NUNCA corre en la
		// suite — misma técnica que InvitacionDisparadorTest.php usa para
		// as_next_scheduled_action, respondiendo contra el array ya encolado.
		Functions\when( 'as_has_scheduled_action' )->alias(
			function ( string $hook, ?array $args = null ): bool {
				foreach ( $this->async as $job ) {
					if ( $job[0] === $hook && ( null === $args || $job[1] === $args ) ) {
						return true;
					}
				}
				return false;
			}
		);
		$this->enqueued = array();
		$this->async    = array();

		Functions\when( 'get_users' )->alias(
			function ( array $args ): array {
				unset( $args );
				$ids = array();
				foreach ( $this->wpdb->user_meta as $uid => $metas ) {
					if ( (int) ( $metas[ Ledger::META_BALANCE ] ?? 0 ) > 0 ) {
						$ids[] = $uid;
					}
				}
				return $ids;
			}
		);
	}

	/**
	 * Prefijo de la SQL de «ganado antes del corte» para un corte dado, con
	 * la MISMA aritmética de fechas que Expiry (needle por día, no por
	 * segundo: estable dentro de la corrida del test).
	 *
	 * @param string $modifiers Modificadores encadenados, p. ej. '-12 months'.
	 * @return string
	 */
	private function earned_needle( string $modifiers ): string {
		$date = new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Santiago' ) );
		foreach ( explode( '|', $modifiers ) as $mod ) {
			$date = $date->modify( $mod );
		}

		return "AND delta > 0 AND ( created_at < '" . $date->format( 'Y-m-d' );
	}

	public function test_only_old_unspent_points_expire_fifo(): void {
		// Ganó 1.000 hace 13 meses y 500 hace un mes; gastó 300 (consumen de
		// los viejos primero): vencible = 1.000 − 300 = 700.
		$this->wpdb->var_answers['AND delta > 0 AND ( created_at <'] = '1000';
		$this->wpdb->var_answers['AND delta < 0']                  = '300';
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ]          = 1200;

		$this->assertSame( 700, Expiry::expirable_for( 7, '2025-08-30 00:00:00' ) );
	}

	public function test_expirable_never_exceeds_the_live_balance(): void {
		$this->wpdb->var_answers['AND delta > 0 AND ( created_at <'] = '5000';
		$this->wpdb->var_answers['AND delta < 0']                  = '0';
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ]          = 400;

		$this->assertSame( 400, Expiry::expirable_for( 7, '2025-08-30 00:00:00' ), 'Jamás vence más de lo que existe.' );
	}

	public function test_the_daily_run_debits_old_points_with_a_per_day_event_key(): void {
		$this->wpdb->var_answers['AND delta > 0 AND ( created_at <'] = '700';
		$this->wpdb->var_answers['AND delta < 0']                  = '0';
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ]          = 700;

		$this->expiry->run_daily();

		$this->assertSame( 0, Ledger::balance( 7 ), 'Los 700 viejos vencieron.' );
		$rows = array_values( array_filter( $this->wpdb->inserts, static fn( array $i ): bool => 'expiry' === $i[1]['action'] ) );
		$this->assertCount( 1, $rows );
		$this->assertStringStartsWith( 'expiry_7_', (string) $rows[0][1]['event_key'] );
		$this->assertSame( -700, $rows[0][1]['delta'] );
	}

	public function test_the_formula_self_corrects_after_expiring(): void {
		$this->wpdb->var_answers['AND delta > 0 AND ( created_at <'] = '700';
		$this->wpdb->var_answers['AND delta < 0']                  = '0';
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ]          = 700;

		$this->expiry->run_daily();

		// Segunda corrida: el vencimiento de ayer YA cuenta como débito
		// (700 − 700 = 0) y no hay nada nuevo que vencer.
		$this->wpdb->var_answers['AND delta < 0'] = '700';
		$this->expiry->run_daily();

		$rows = array_filter( $this->wpdb->inserts, static fn( array $i ): bool => 'expiry' === $i[1]['action'] );
		$this->assertCount( 1, $rows, 'El propio vencimiento consume la antigüedad: no re-vence.' );
		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	public function test_the_same_day_rerun_is_idempotent_by_event_key(): void {
		$this->wpdb->var_answers['AND delta > 0 AND ( created_at <'] = '700';
		$this->wpdb->var_answers['AND delta < 0']                  = '0';
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ]          = 700;

		$this->expiry->run_daily();

		// Re-disparo del cron el MISMO día con los débitos aún «invisibles»
		// (réplica atrasada): el UNIQUE de event_key es la última línea.
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 700;
		$this->expiry->run_daily();

		$rows = array_filter( $this->wpdb->inserts, static fn( array $i ): bool => 'expiry' === $i[1]['action'] );
		$this->assertCount( 1, $rows, 'Mismo día = mismo event_key = un solo débito.' );
	}

	public function test_zero_months_means_points_never_expire(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_expiry_months' === $option ? 0 : $default_value
		);
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 700;

		$this->expiry->run_daily();

		$this->assertSame( 700, Ledger::balance( 7 ), 'Con 0 meses, nada vence jamás.' );
		$this->assertSame( array(), $this->wpdb->inserts );
	}

	public function test_migrated_points_are_protected_until_the_epoch_ages(): void {
		// El cliente llega del cutover con 600 puntos de un log SPAR de 2023.
		// La época se selló HOY: la corrida del día uno NO puede vencerlos.
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_migration_epoch' === $option
				? '2026-08-31 03:00:00'
				: $default_value
		);
		$this->wpdb->inserts[] = array( 'wp_akb_rewards_ledger', array(
			'user_id'    => 7,
			'delta'      => 600,
			'action'     => 'migration',
			'created_at' => '2023-01-15 10:00:00',
		) );
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 600;

		$this->assertSame( 0, Expiry::expirable_for( 7, '2025-08-31 03:00:00' ), 'Migrados envejecen desde la época, no desde su fecha SPAR.' );
	}

	public function test_migrated_points_do_expire_n_months_after_the_epoch(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_migration_epoch' === $option
				? '2024-06-01 03:00:00'
				: $default_value
		);
		$this->wpdb->inserts[] = array( 'wp_akb_rewards_ledger', array(
			'user_id'    => 7,
			'delta'      => 600,
			'action'     => 'migration',
			'created_at' => '2023-01-15 10:00:00',
		) );
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 600;

		$this->assertSame( 600, Expiry::expirable_for( 7, '2025-08-31 03:00:00' ), 'Pasados N meses desde la época, sí vencen.' );
	}

	public function test_without_a_sealed_epoch_migrated_points_never_expire(): void {
		$this->wpdb->inserts[] = array( 'wp_akb_rewards_ledger', array(
			'user_id'    => 7,
			'delta'      => 600,
			'action'     => 'migration',
			'created_at' => '2023-01-15 10:00:00',
		) );
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 600;

		$this->assertSame( 0, Expiry::expirable_for( 7, '2025-08-31 03:00:00' ), 'Sin época: fallar hacia NO quitarle puntos a nadie.' );
	}

	public function test_the_mercy_warning_retries_until_the_email_seals_the_marker(): void {
		// HOY no vence nada, pero dentro de la ventana de 30 días vencen 500:
		// el needle se distingue por FECHA del corte (mismo shape de SQL).
		$this->wpdb->var_answers[ $this->earned_needle( '-12 months' ) ]           = '0';
		$this->wpdb->var_answers[ $this->earned_needle( '+30 days|-12 months' ) ]  = '500';
		$this->wpdb->var_answers['AND delta < 0']                                  = '0';
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ]                          = 500;

		// El sello lo pone el CORREO al confirmar transporte; sin sello, la
		// corrida siguiente REINTENTA (célula 2026-08-31: sellar antes de
		// enviar perdía el aviso para siempre con el SMTP caído).
		Actions\expectDone( 'akb_rewards_expiry_warning' )
			->twice()
			->with( 7, 500, 30 );

		$this->expiry->run_daily();
		$this->expiry->run_daily(); // Sin transporte confirmado: reintenta.

		$marker = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Santiago' ) ) )->format( 'Y-m' );
		$this->wpdb->user_meta[7][ Expiry::META_WARNED ] = $marker;
		$this->expiry->run_daily(); // Sellado por el correo: este mes ya no.

		$this->assertSame( array(), $this->wpdb->inserts, 'Avisar no debita.' );
	}

	public function test_months_before_clamps_the_month_end_instead_of_overflowing(): void {
		$tz = new \DateTimeZone( 'America/Santiago' );

		$this->assertSame(
			'2026-02-28 03:00:00',
			Expiry::months_before( new \DateTimeImmutable( '2026-08-31 03:00:00', $tz ), 6 )->format( 'Y-m-d H:i:s' ),
			'31-08 − 6 meses recorta a fin de febrero, no desborda al 3 de marzo.'
		);
		$this->assertSame(
			'2024-02-29 12:30:00',
			Expiry::months_before( new \DateTimeImmutable( '2024-03-31 12:30:00', $tz ), 1 )->format( 'Y-m-d H:i:s' ),
			'Bisiesto: recorta al 29.'
		);
		$this->assertSame(
			'2025-07-15 00:00:00',
			Expiry::months_before( new \DateTimeImmutable( '2026-07-15 00:00:00', $tz ), 12 )->format( 'Y-m-d H:i:s' ),
			'El caso normal queda idéntico.'
		);
	}

	public function test_a_large_run_is_chopped_into_scheduler_batches(): void {
		$this->batches = true;
		$enqueued      = &$this->enqueued;
		for ( $u = 1; $u <= 101; $u++ ) {
			$this->wpdb->user_meta[ $u ][ Ledger::META_BALANCE ] = 10;
		}

		$this->expiry->run_daily();

		$this->assertCount( 2, $enqueued, '101 usuarios → lotes de 100 y de 1.' );
		$this->assertSame( Expiry::HOOK_BATCH, $enqueued[0][0] );
		$this->assertCount( 100, $enqueued[0][1][0] );
		$this->assertCount( 1, $enqueued[1][1][0] );
		$expiraciones = array_filter( $this->wpdb->inserts, static fn( array $i ): bool => 'expiry' === $i[1]['action'] );
		$this->assertCount( 0, $expiraciones, 'Encolar no debita: debitan los lotes al ejecutarse.' );
	}

	public function test_run_batch_expires_each_user_of_the_lot(): void {
		$this->wpdb->var_answers['AND delta > 0 AND ( created_at <'] = '700';
		$this->wpdb->var_answers['AND delta < 0']                  = '0';
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ]          = 700;

		$this->expiry->run_batch( array( 7 ), '2025-08-30 00:00:00' );

		$this->assertSame( 0, Ledger::balance( 7 ), 'El lote vence igual que la corrida directa.' );
	}

	public function test_the_expiry_amount_is_computed_under_the_user_lock(): void {
		// Regresión de la carrera canje-vs-vencimiento (RX 2026-08-31): el
		// monto a vencer debe decidirse DESPUÉS de tomar el candado del
		// usuario, no antes — el orden de las SQL reales lo demuestra.
		$this->wpdb->var_answers['AND delta > 0 AND ( created_at <'] = '700';
		$this->wpdb->var_answers['AND delta < 0']                  = '0';
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ]          = 700;

		$this->expiry->run_daily();

		$lock_pos = $sum_pos = null;
		foreach ( $this->wpdb->var_log as $i => $sql ) {
			if ( null === $lock_pos && false !== strpos( $sql, 'GET_LOCK' ) ) {
				$lock_pos = $i;
			}
			if ( null === $sum_pos && false !== strpos( $sql, 'AND delta > 0 AND ( created_at <' ) ) {
				$sum_pos = $i;
			}
		}
		$this->assertNotNull( $lock_pos );
		$this->assertNotNull( $sum_pos );
		$this->assertLessThan( $sum_pos, $lock_pos, 'Candado primero, cálculo después.' );
	}

	public function test_warnings_travel_as_their_own_async_actions_when_available(): void {
		$this->batches = true;
		$async         = &$this->async;
		$this->wpdb->var_answers[ $this->earned_needle( '-12 months' ) ]          = '0';
		$this->wpdb->var_answers[ $this->earned_needle( '+30 days|-12 months' ) ] = '500';
		$this->wpdb->var_answers['AND delta < 0']                                 = '0';
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ]                         = 500;

		Actions\expectDone( 'akb_rewards_expiry_warning' )->once()->with( 7, 500, 30 );

		$this->expiry->run_daily();
		$this->assertCount( 1, $async, 'El aviso viaja en su propia acción asíncrona.' );
		$this->assertSame( Expiry::HOOK_WARN_ASYNC, $async[0][0] );
		// UN solo argumento: con tres, el dedupe de Action Scheduler (que
		// compara argumentos) nunca coincide entre corridas (S-aaf18a42).
		$this->assertSame( array( 7 ), $async[0][1] );

		// El receptor asíncrono re-dispara el hook público (el correo escucha ahí).
		$this->expiry->fire_warning( ...$async[0][1] );
	}

	/**
	 * @testdox Los oyentes se cuelgan aunque el vencimiento esté apagado.
	 *
	 * La acción diaria puede llevar meses en Action Scheduler desde antes de
	 * apagar el carril: sin oyente, fallaba en la cola cada día (QA
	 * akibara-3f, 2026-09-12).
	 */
	public function test_listeners_are_hooked_even_with_expiry_off(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_expiry_enabled' === $option ? 'no' : $default_value
		);

		$this->expiry->register();

		$this->assertSame( 10, has_action( Expiry::HOOK, array( $this->expiry, 'run_daily' ) ) );
		$this->assertSame( 10, has_action( Expiry::HOOK_BATCH, array( $this->expiry, 'run_batch' ) ) );
		$this->assertSame( 10, has_action( Expiry::HOOK_WARN_ASYNC, array( $this->expiry, 'fire_warning' ) ) );
		$this->assertSame( 20, has_action( 'init', array( $this->expiry, 'ensure_scheduled' ) ) );
	}

	/** @testdox Con el vencimiento apagado, la agenda RETIRA la acción diaria y lo memoriza. */
	public function test_scheduling_unschedules_the_daily_action_when_expiry_is_off(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_expiry_enabled' === $option ? 'no' : $default_value
		);
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( 'yes' ); // Quedó agendada de cuando estaba encendido.
		$memo = array();
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$memo ): bool {
				$memo[ $key ] = $value;
				return true;
			}
		);
		$retiradas = array();
		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( string $hook ) use ( &$retiradas ): void {
				$retiradas[] = $hook;
			}
		);
		Functions\when( 'as_next_scheduled_action' )->justReturn( 123 );
		Functions\expect( 'as_schedule_recurring_action' )->never();

		$this->expiry->ensure_scheduled();

		$this->assertSame( array( Expiry::HOOK ), $retiradas );
		$this->assertSame( array( 'akb_rewards_expiry_scheduled' => 'off' ), $memo, 'El memo guarda el ESTADO dejado en la cola, no sólo «ya miré».' );
	}

	/** @testdox Con el vencimiento encendido y el memo en «off», se vuelve a agendar sin esperar. */
	public function test_scheduling_reschedules_when_expiry_is_turned_back_on(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( 'off' );
		$memo = array();
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$memo ): bool {
				$memo[ $key ] = $value;
				return true;
			}
		);
		Functions\expect( 'as_unschedule_all_actions' )->never();
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_schedule_recurring_action' )->once()->andReturn( 77 );

		$this->expiry->ensure_scheduled();

		$this->assertSame( array( 'akb_rewards_expiry_scheduled' => 'yes' ), $memo );
	}

	/** @testdox Si el memo ya dice lo que la cola tiene, no se consulta Action Scheduler. */
	public function test_a_matching_memo_skips_the_scheduler_entirely(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( 'yes' );
		Functions\expect( 'as_next_scheduled_action' )->never();
		Functions\expect( 'as_schedule_recurring_action' )->never();
		Functions\expect( 'as_unschedule_all_actions' )->never();
		Functions\expect( 'set_transient' )->never();

		$this->expiry->ensure_scheduled();
		$this->addToAssertionCount( 1 ); // Las expectativas «never» de arriba son la prueba.
	}

	/**
	 * @testdox El aviso de vencimiento se encola UNA vez por cliente.
	 *
	 * Hallazgo S-aaf18a42: el marcador del mes lo sella el correo —a propósito,
	 * para no perder el aviso si el SMTP está caído—, así que dos corridas
	 * seguidas antes de que la cola drene (un `wp action-scheduler run` a mano
	 * junto al cron de las 05:00) encolaban dos acciones y el cliente recibía
	 * el mismo correo dos veces. La acción viaja con UN solo argumento para que
	 * el dedupe de Action Scheduler, que compara argumentos, coincida.
	 */
	public function test_el_aviso_no_se_encola_dos_veces(): void {
		$this->batches = true;
		$this->wpdb->var_answers[ $this->earned_needle( '-12 months' ) ]          = '0';
		$this->wpdb->var_answers[ $this->earned_needle( '+30 days|-12 months' ) ] = '500';
		$this->wpdb->var_answers['AND delta < 0']                                 = '0';
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ]                         = 500;

		$this->expiry->run_daily();
		$this->expiry->run_daily(); // Segunda corrida antes de que la cola drene.

		$this->assertCount( 1, $this->async, 'Dos corridas antes de drenar la cola: UNA sola acción encolada.' );
		$this->assertSame(
			array( 7 ),
			$this->async[0][1],
			'La acción viaja con UN argumento: con tres, el dedupe de Action Scheduler nunca coincide (S-aaf18a42).'
		);
	}

	/** @testdox El monto se recalcula al enviar, no se arrastra desde la cola. */
	public function test_el_monto_se_recalcula_al_avisar(): void {
		$this->batches = true;
		$this->wpdb->var_answers[ $this->earned_needle( '-12 months' ) ]          = '0';
		$this->wpdb->var_answers[ $this->earned_needle( '+30 days|-12 months' ) ] = '500';
		$this->wpdb->var_answers['AND delta < 0']                                 = '0';
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ]                         = 500;

		$this->expiry->run_daily();
		$this->assertCount( 1, $this->async, 'Se encoló la acción con el usuario.' );

		// Entremedio, el cliente canjeó todo su saldo: al momento de avisar ya
		// no vence nada (expirable_for topa contra el saldo vivo).
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 0;

		Actions\expectDone( 'akb_rewards_expiry_warning' )->never();

		$this->expiry->fire_warning( ...$this->async[0][1] );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}
}
