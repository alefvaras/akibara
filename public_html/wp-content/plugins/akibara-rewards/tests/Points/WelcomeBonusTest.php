<?php
/**
 * Contrato del bono de bienvenida.
 *
 * Fija las lecciones del diseño original: se paga UNA vez, sobre la primera
 * compra acreditada (nunca por registro), viaja DENTRO del earned del pedido
 * para que las anulaciones lo arrastren, y la anulación del pedido pagador
 * devuelve la elegibilidad.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Accrual;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\WelcomeBonus;
use Akibara\Rewards\Tests\Support\RewardsTestCase;

/**
 * @since 0.1.0
 */
final class WelcomeBonusTest extends RewardsTestCase {

	private WelcomeBonus $bonus;

	protected function setUp(): void {
		parent::setUp();
		$this->bonus = new WelcomeBonus();
	}

	public function test_first_award_pays_the_bonus_inside_the_order_earned(): void {
		$order                              = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Accrual::META_EARNED ] = 238;

		$this->bonus->maybe_pay( $order, 7, 238 );

		$this->assertSame( 500, Ledger::balance( 7 ) );
		// El bono queda DENTRO del earned: la anulación lo arrastra gratis.
		$this->assertSame( 738, $order->saved_meta[ Accrual::META_EARNED ] );
		$this->assertSame( 500, $order->saved_meta[ Accrual::META_WELCOME_BONUS ] );
		$this->assertSame( 100, $this->wpdb->user_meta[7][ WelcomeBonus::META_WELCOME_PAID ] );
	}

	public function test_the_bonus_is_paid_only_once(): void {
		$first                               = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$first->meta[ Accrual::META_EARNED ] = 238;
		$this->bonus->maybe_pay( $first, 7, 238 );

		$second                               = $this->make_order( 200, 7, 5000.0, 950.0 );
		$second->meta[ Accrual::META_EARNED ] = 119;
		$this->bonus->maybe_pay( $second, 7, 119 );

		$this->assertSame( 500, Ledger::balance( 7 ) );
		$this->assertArrayNotHasKey( Accrual::META_WELCOME_BONUS, $second->meta );
		// La marca sigue apuntando al pedido pagador original.
		$this->assertSame( 100, $this->wpdb->user_meta[7][ WelcomeBonus::META_WELCOME_PAID ] );
	}

	public function test_a_zero_bonus_disables_the_program(): void {
		\Brain\Monkey\Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'akb_rewards_welcome_bonus' === $hook ? 0 : $value;
			}
		);

		$order                              = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Accrual::META_EARNED ] = 238;

		$this->bonus->maybe_pay( $order, 7, 238 );

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertCount( 0, $this->wpdb->inserts );
	}

	public function test_reversal_of_the_paying_order_restores_eligibility(): void {
		$order                              = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Accrual::META_EARNED ] = 238;
		$this->bonus->maybe_pay( $order, 7, 238 );

		// La reversa recuperó el earned completo (bono incluido).
		$this->bonus->maybe_restore_eligibility( $order, 7, 738 );

		$this->assertArrayNotHasKey( WelcomeBonus::META_WELCOME_PAID, $this->wpdb->user_meta[7] );
		$this->assertSame( 0, $order->saved_meta[ Accrual::META_WELCOME_BONUS ] );

		// Y la próxima compra vuelve a pagar el bono.
		$next                               = $this->make_order( 300, 7, 4000.0, 760.0 );
		$next->meta[ Accrual::META_EARNED ] = 95;
		$this->bonus->maybe_pay( $next, 7, 95 );

		$this->assertSame( 300, $this->wpdb->user_meta[7][ WelcomeBonus::META_WELCOME_PAID ] );
	}

	public function test_reversal_of_another_order_keeps_the_mark(): void {
		$order                              = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Accrual::META_EARNED ] = 238;
		$this->bonus->maybe_pay( $order, 7, 238 );

		$other = $this->make_order( 999, 7, 2000.0, 380.0 );
		$this->bonus->maybe_restore_eligibility( $other, 7, 475 );

		$this->assertSame( 100, $this->wpdb->user_meta[7][ WelcomeBonus::META_WELCOME_PAID ] );
	}

	public function test_a_clamped_reversal_never_restores_eligibility(): void {
		// El ciclo farmeable que atrapó la célula: bono pagado, saldo gastado,
		// pedido anulado → el débito clampa a 0. Devolver la elegibilidad
		// regalaría un SEGUNDO bono sin haber recuperado el primero.
		$order                              = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Accrual::META_EARNED ] = 238;
		$this->bonus->maybe_pay( $order, 7, 238 );

		$this->bonus->maybe_restore_eligibility( $order, 7, 0 );

		$this->assertSame(
			100,
			$this->wpdb->user_meta[7][ WelcomeBonus::META_WELCOME_PAID ],
			'Con la reversa clampada, la marca se queda: el cliente NO vuelve a ser elegible.'
		);
		$this->assertStringContainsString( 'NO se devuelve', implode( ' ', $order->notes ) );

		// Y una compra posterior no re-paga el bono.
		$next                               = $this->make_order( 300, 7, 4000.0, 760.0 );
		$next->meta[ Accrual::META_EARNED ] = 95;
		$this->bonus->maybe_pay( $next, 7, 95 );

		$this->assertSame( 500, Ledger::balance( 7 ), 'Un solo bono en toda la historia.' );
	}

	public function test_a_partial_recovery_below_the_bonus_keeps_the_mark(): void {
		$order                              = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Accrual::META_EARNED ] = 238;
		$this->bonus->maybe_pay( $order, 7, 238 );

		// La reversa solo recuperó 300 de un bono de 500.
		$this->bonus->maybe_restore_eligibility( $order, 7, 300 );

		$this->assertSame( 100, $this->wpdb->user_meta[7][ WelcomeBonus::META_WELCOME_PAID ] );
	}

	public function test_reviving_the_paying_order_repays_the_bonus_with_real_ledger_movement(): void {
		// Re-auditoría: la clave fija usuario+pedido deduplicaba en silencio y
		// dejaba la meta earned inflada con 500 fantasmas.
		$order                              = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Accrual::META_EARNED ] = 238;
		$this->bonus->maybe_pay( $order, 7, 238 );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		// Anulación completa que recuperó todo (fila de reversa en el ledger).
		Ledger::debit( 7, 738, 'order_reversal', 'order', 100 );
		$this->bonus->maybe_restore_eligibility( $order, 7, 738 );
		$this->assertSame( 0, Ledger::balance( 7 ) );

		// Revival del MISMO pedido: re-acreditación de la compra y bono.
		$order->meta[ Accrual::META_EARNED ] = 238;
		$this->bonus->maybe_pay( $order, 7, 238 );

		$this->assertSame( 500, Ledger::balance( 7 ), 'El bono del revival mueve el ledger de verdad.' );
		$this->assertSame( 738, $order->saved_meta[ Accrual::META_EARNED ], 'Y la meta refleja puntos que EXISTEN.' );

		$claves = array_column( array_column( $this->wpdb->inserts, 1 ), 'event_key' );
		$this->assertContains( 'welcome_7_100_1', $claves );
		$this->assertContains( 'welcome_7_100_2', $claves, 'Cada ocurrencia lleva su clave.' );
	}

	public function test_a_crash_window_seals_without_a_second_credit(): void {
		// El crédito del bono existe en el ledger pero la marca no se selló.
		Ledger::credit( 7, 500, 'welcome_bonus', 'order', 100 );
		$this->assertSame( 500, Ledger::balance( 7 ) );

		$order                              = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Accrual::META_EARNED ] = 238;
		$this->bonus->maybe_pay( $order, 7, 238 );

		$this->assertSame( 500, Ledger::balance( 7 ), 'Cero crédito nuevo.' );
		$this->assertSame( 100, $this->wpdb->user_meta[7][ WelcomeBonus::META_WELCOME_PAID ] );
		$this->assertSame( 738, $order->saved_meta[ Accrual::META_EARNED ] );
		$this->assertStringContainsString( 'reconciliado', implode( ' ', $order->notes ) );
	}

	public function test_the_crash_window_reconciliation_is_reachable_via_already_done(): void {
		// Hallazgo BAJA del confirmador: la conciliación era código muerto
		// porque after_order_award no se re-dispara en pedidos ya acreditados.
		// El camino already_done la vuelve alcanzable.
		Ledger::credit( 7, 500, 'welcome_bonus', 'order', 100 );

		$order                              = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Accrual::META_EARNED ] = 238; // Acreditado; el +bono nunca llegó.

		$this->bonus->reconcile_from_already_done( $order );

		$this->assertSame( 500, Ledger::balance( 7 ), 'Cero crédito nuevo.' );
		$this->assertSame( 100, $this->wpdb->user_meta[7][ WelcomeBonus::META_WELCOME_PAID ] );
		$this->assertSame( 738, $order->saved_meta[ Accrual::META_EARNED ] );
	}

	public function test_the_pending_banner_reads_from_cache_not_from_raw_sql(): void {
		// Es una lectura MERAMENTE informativa: usaba el lector crudo del
		// Ledger, que además VACÍA la caché de usermeta del cliente y obliga a
		// re-leer todo lo demás de esa pantalla.
		$this->assertTrue( WelcomeBonus::is_pending_for( 7 ) );

		$crudas = array_filter(
			$this->wpdb->var_log,
			static fn( string $sql ): bool => false !== strpos( $sql, 'SELECT meta_value FROM wp_usermeta' )
		);
		$this->assertSame( array(), $crudas, 'El banner no baja a SQL: le basta la caché.' );

		$this->wpdb->user_meta[7][ WelcomeBonus::META_WELCOME_PAID ] = 1;
		$this->assertFalse( WelcomeBonus::is_pending_for( 7 ), 'Pagado ya no se promete.' );
	}

	public function test_without_lock_no_bonus_is_paid(): void {
		$this->wpdb->lock_result = '0';

		$order                              = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Accrual::META_EARNED ] = 238;

		$this->bonus->maybe_pay( $order, 7, 238 );

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertArrayNotHasKey( WelcomeBonus::META_WELCOME_PAID, $this->wpdb->user_meta[7] ?? array() );
	}
}
