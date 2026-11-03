<?php
/**
 * Contrato de la auditoría saldo-vs-ledger (RX fiabilidad 2026-08-31).
 *
 * La caída entre el INSERT del asiento y el UPDATE de la meta desincroniza
 * el saldo en silencio; la reparación re-deriva la meta de la SUMA del
 * ledger bajo candado — jamás escribe asientos para igualar una caché.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Support\Audit;

/**
 * @since 0.3.0
 */
final class AuditTest extends RewardsTestCase {

	public function test_repair_rederives_the_meta_from_the_ledger_under_lock(): void {
		Ledger::credit( 9, 500, 'order', 'order', 1 );
		Ledger::debit( 9, 200, 'redeem', 'order', 2 );
		$this->assertSame( 300, Ledger::balance( 9 ) );

		// La caché se corrompe (la caída clásica)…
		$this->wpdb->user_meta[9][ Ledger::META_BALANCE ] = 120;

		$this->assertSame( 300, Ledger::balance_from_ledger( 9 ), 'La suma del ledger es la verdad.' );
		$this->assertSame( 300, Audit::repair( 9 ) );
		$this->assertSame( 300, Ledger::balance( 9 ), 'La meta quedó re-derivada.' );
		$expiraciones = array_filter( $this->wpdb->inserts, static fn( array $i ): bool => 'wp_akb_rewards_ledger' === $i[0] && ! in_array( $i[1]['action'], array( 'order', 'redeem' ), true ) );
		$this->assertCount( 0, $expiraciones, 'Reparar NO escribe asientos: el ledger ya era correcto.' );
	}

	/**
	 * R7-auditoria-irreparable (2026-09-19): el usuario 1 en producción tiene
	 * ledger −500 y meta 0; `repair()` clampa a 0 y escribía el mismo valor,
	 * así que «1 desviación» no se iba nunca. Un negativo no es desviación: se
	 * informa aparte.
	 */
	public function test_a_negative_ledger_is_reported_not_treated_as_a_mismatch(): void {
		$this->wpdb->results_answers['HAVING ledger < 0'] = array(
			array(
				'user_id' => '1',
				'ledger'  => '-500',
			),
		);

		$this->assertSame( array( array( 'user_id' => 1, 'ledger' => -500 ) ), Audit::negatives() );

		Audit::mismatches();
		$this->assertStringContainsString( 'HAVING meta <> GREATEST(0, ledger)', (string) end( $this->wpdb->results_log ) );
	}

	public function test_repair_without_lock_reports_null(): void {
		$this->wpdb->lock_result = '0';
		$this->assertNull( Audit::repair( 9 ), 'Sin candado no se toca nada.' );
	}
}
