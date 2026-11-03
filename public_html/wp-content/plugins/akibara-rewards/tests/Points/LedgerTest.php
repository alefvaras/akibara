<?php
/**
 * Contrato del Ledger: la puerta única de movimientos.
 *
 * Lo que se fija acá es la semántica de dinero: sin candado no hay movimiento
 * (y eso es NULL —infraestructura—, jamás confundible con el false de saldo
 * insuficiente); la captura de canje es todo-o-nada; la reversa clampa en 0 y
 * registra el delta REAL; el total histórico solo sube; y un event_key
 * repetido se declara ya-aplicado sin tocar el saldo.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Tests\Support\RewardsTestCase;

/**
 * @since 0.1.0
 */
final class LedgerTest extends RewardsTestCase {

	public function test_credit_writes_ledger_row_balance_and_total_earned(): void {
		$result = Ledger::credit( 7, 254, 'order', 'order', 100, 'Compra #100' );

		$this->assertSame( 254, $result );
		$this->assertSame( 254, Ledger::balance( 7 ) );
		$this->assertSame( 254, Ledger::total_earned( 7 ) );

		$this->assertCount( 1, $this->wpdb->inserts );
		[ $table, $row ] = $this->wpdb->inserts[0];
		$this->assertSame( 'wp_akb_rewards_ledger', $table );
		$this->assertSame( 254, $row['delta'] );
		$this->assertSame( 254, $row['balance_after'] );
		$this->assertSame( 'order', $row['action'] );
		$this->assertSame( 100, $row['ref_id'] );
	}

	public function test_credit_can_skip_the_historic_total(): void {
		Ledger::credit( 7, 100, 'migration', 'spar', 0, '', false );

		$this->assertSame( 100, Ledger::balance( 7 ) );
		$this->assertSame( 0, Ledger::total_earned( 7 ) );
	}

	public function test_a_repeated_event_key_is_already_applied_and_touches_nothing(): void {
		$first = Ledger::credit( 7, 200, 'order', 'order', 100, '', true, 'award_100_1' );
		$again = Ledger::credit( 7, 200, 'order', 'order', 100, '', true, 'award_100_1' );

		$this->assertSame( 200, $first );
		$this->assertSame( 200, $again, 'El duplicado devuelve el saldo vigente, no un fallo.' );
		$this->assertSame( 200, Ledger::balance( 7 ) );
		$this->assertSame( 200, Ledger::total_earned( 7 ), 'El total histórico tampoco se duplica.' );
		$this->assertCount( 1, $this->wpdb->inserts );

		$debit_once  = Ledger::debit( 7, 50, 'redemption', 'order', 100, '', true, 'redeem_100' );
		$debit_again = Ledger::debit( 7, 50, 'redemption', 'order', 100, '', true, 'redeem_100' );

		$this->assertSame( 50, $debit_once );
		$this->assertSame( 0, $debit_again, 'El débito duplicado cobra cero.' );
		$this->assertSame( 150, Ledger::balance( 7 ) );
	}

	public function test_debit_clamps_at_zero_and_reports_the_real_delta(): void {
		Ledger::credit( 7, 300, 'order', 'order', 100 );

		// El cliente ya gastó parte: la reversa retira lo que HAY, no deuda.
		$debited = Ledger::debit( 7, 500, 'order_reversal', 'order', 100 );

		$this->assertSame( 300, $debited );
		$this->assertSame( 0, Ledger::balance( 7 ) );

		// La fila registra el delta real (−300), no el pedido (−500).
		[ , $row ] = $this->wpdb->inserts[1];
		$this->assertSame( -300, $row['delta'] );
		$this->assertSame( 0, $row['balance_after'] );
	}

	public function test_debit_requiring_sufficient_balance_is_all_or_nothing(): void {
		Ledger::credit( 7, 300, 'order', 'order', 100 );

		// Captura de canje con saldo corto: NI un punto se debita, y el
		// rechazo es FALSE (negocio), no null.
		$result = Ledger::debit( 7, 500, 'redemption', 'order', 200, '', true );

		$this->assertFalse( $result );
		$this->assertSame( 300, Ledger::balance( 7 ) );
		$this->assertCount( 1, $this->wpdb->inserts );
	}

	public function test_no_lock_is_null_never_confusable_with_insufficient_balance(): void {
		$this->wpdb->lock_result = '0';

		$credit = Ledger::credit( 7, 100, 'order', 'order', 100 );
		$debit  = Ledger::debit( 7, 100, 'redemption', 'order', 100, '', true );

		$this->assertNull( $credit );
		$this->assertNull( $debit, 'Un timeout de candado JAMÁS puede leerse como saldo insuficiente.' );
		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertCount( 0, $this->wpdb->inserts );
	}

	public function test_failed_ledger_insert_aborts_without_touching_the_balance(): void {
		$this->wpdb->fail_insert = true;

		$this->assertNull( Ledger::credit( 7, 100, 'order', 'order', 100 ) );
		$this->assertSame( 0, Ledger::balance( 7 ) );
		// Sin fila de auditoría NO puede haber saldo: la tabla es la verdad.
		$this->assertSame( 0, Ledger::total_earned( 7 ) );
	}

	public function test_locks_are_reentrant_and_fully_released(): void {
		// El bono re-lee su marca bajo el candado de usuario y DENTRO llama a
		// credit, que lo vuelve a tomar: la reentrancia es parte del contrato.
		$result = Ledger::with_user_lock(
			7,
			static fn() => Ledger::credit( 7, 500, 'welcome_bonus', 'order', 100 )
		);

		$this->assertSame( 500, $result );
		$this->assertSame(
			array(),
			$this->wpdb->held_locks,
			'Cada GET_LOCK anidado debe tener su RELEASE_LOCK: nada queda tomado.'
		);
	}

	public function test_the_lock_is_always_released_even_on_success_and_failure(): void {
		Ledger::credit( 7, 100, 'order', 'order', 100 );
		$this->wpdb->fail_insert = true;
		Ledger::credit( 7, 100, 'order', 'order', 101 );

		$this->assertSame( array(), $this->wpdb->held_locks );
	}

	public function test_each_movement_records_the_applied_delta_and_balance(): void {
		Ledger::credit( 7, 200, 'order', 'order', 100 );
		Ledger::debit( 7, 50, 'redemption', 'order', 200, '', true );

		$filas = array_column( $this->wpdb->inserts, 1 );
		$this->assertSame( array( 200, -50 ), array_map( 'intval', array_column( $filas, 'delta' ) ) );
		$this->assertSame( array( 200, 150 ), array_map( 'intval', array_column( $filas, 'balance_after' ) ) );
		$this->assertSame( 150, Ledger::balance( 7 ) );
	}

	public function test_rejects_degenerate_arguments(): void {
		$this->assertFalse( Ledger::credit( 0, 100, 'order' ) );
		$this->assertFalse( Ledger::credit( 7, 0, 'order' ) );
		$this->assertFalse( Ledger::credit( 7, -5, 'order' ) );
		$this->assertFalse( Ledger::debit( 0, 100, 'order' ) );
		$this->assertFalse( Ledger::debit( 7, 0, 'order' ) );
		$this->assertCount( 0, $this->wpdb->inserts );
	}

	public function test_the_pre_credit_veto_filter_can_block_any_credit(): void {
		\Brain\Monkey\Filters\expectApplied( 'akb_rewards_pre_credit_points' )
			->once()
			->andReturn( 0 );

		$this->assertFalse(
			Ledger::credit( 7, 500, 'order', 'order', 1, 'nota', true, 'veto_1' ),
			'Un veto (0 puntos) es rechazo de negocio: sin asiento y sin candado gastado.'
		);
		$this->assertSame( array(), $this->wpdb->inserts );
	}

	public function test_the_pre_credit_filter_can_reduce_the_amount(): void {
		\Brain\Monkey\Filters\expectApplied( 'akb_rewards_pre_credit_points' )
			->once()
			->andReturn( 100 );

		$this->assertSame( 100, Ledger::credit( 7, 500, 'order', 'order', 1, 'nota', true, 'veto_2' ) );
		$this->assertSame( 100, (int) $this->wpdb->inserts[0][1]['delta'] );
	}
}
