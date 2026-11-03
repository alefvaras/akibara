<?php
/**
 * Contrato de la acreditación por pedido.
 *
 * Fija las compuertas que costaron dinero en el sistema viejo: la vigencia
 * del programa (pedidos pre-17-08 no acreditan aunque cambien de estado), la
 * idempotencia bajo candado, el sellado del cero, y las reversas (anulación
 * total arrastra todo; reembolso parcial recalcula preservando el bono).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Accrual;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.1.0
 */
final class AccrualTest extends RewardsTestCase {

	private Accrual $accrual;

	/** @var array<int,\WC_Order> */
	private array $orders = array();

	protected function setUp(): void {
		parent::setUp();

		$this->accrual = new Accrual();
		$orders        = &$this->orders;

		Functions\when( 'wc_get_order' )->alias(
			static function ( int $id ) use ( &$orders ) {
				return $orders[ $id ] ?? false;
			}
		);
	}

	public function test_award_credits_points_seals_the_meta_and_fires_the_hook(): void {
		// $10.000 + $1.900 IVA = $11.900 → 238 puntos (2/$100).
		$order               = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100]   = $order;

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_after_order_award' )
			->once()
			->with( $order, 7, 238 );

		$this->accrual->maybe_award( 100 );

		$this->assertSame( 238, Ledger::balance( 7 ) );
		$this->assertSame( 238, $order->saved_meta[ Accrual::META_EARNED ] );
		$this->assertStringContainsString( '238', $order->notes[0] );
	}

	public function test_award_is_idempotent_and_signals_the_retry_path(): void {
		$order                              = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Accrual::META_EARNED ] = 238;
		$this->orders[100]                  = $order;

		// La segunda pasada no acredita, pero avisa (el correo pendiente
		// escucha ahí su reintento).
		\Brain\Monkey\Actions\expectDone( 'akb_rewards_award_already_done' )
			->once()
			->with( $order );

		$this->accrual->maybe_award( 100 );

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertCount( 0, $this->wpdb->inserts );
	}

	public function test_guest_orders_never_accrue(): void {
		$order             = $this->make_order( 100, 0, 10000.0, 1900.0 );
		$this->orders[100] = $order;

		$this->accrual->maybe_award( 100 );

		$this->assertCount( 0, $this->wpdb->inserts );
		$this->assertArrayNotHasKey( Accrual::META_EARNED, $order->meta );
	}

	public function test_pre_program_orders_get_one_note_and_no_points(): void {
		$order               = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->date_created = new \WC_DateTime( '2026-08-10 10:00:00', new \DateTimeZone( 'America/Santiago' ) );
		$this->orders[100]   = $order;

		$this->accrual->maybe_award( 100 );
		$this->accrual->maybe_award( 100 );

		$this->assertCount( 0, $this->wpdb->inserts );
		// Nota ÚNICA aunque el estado se dispare dos veces.
		$this->assertCount( 1, $order->notes );
		$this->assertStringContainsString( 'anterior al inicio del programa', $order->notes[0] );
	}

	public function test_a_forced_award_bypasses_the_program_gate(): void {
		$order               = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->date_created = new \WC_DateTime( '2026-08-10 10:00:00', new \DateTimeZone( 'America/Santiago' ) );
		$this->orders[100]   = $order;

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'akb_rewards_award_allowed' === $hook ? true : $value;
			}
		);

		$this->accrual->maybe_award( 100 );

		$this->assertSame( 238, Ledger::balance( 7 ) );
	}

	public function test_zero_point_orders_seal_a_zero_to_stop_reevaluation(): void {
		$order             = $this->make_order( 100, 7, 10.0, 1.9 );
		$this->orders[100] = $order;

		$this->accrual->maybe_award( 100 );

		$this->assertSame( 0, $order->saved_meta[ Accrual::META_EARNED ] );
		$this->assertCount( 0, $this->wpdb->inserts );
	}

	/**
	 * Un pedido que transita al estado que acredita ANTES de tener líneas
	 * (API/CLI que fija el estado y añade productos después) no es un pedido
	 * de cero puntos: sellarlo dejaba 0 para siempre (QA 2026-09-01, pedido
	 * 27906). Debe quedar virgen para acreditar en la próxima transición.
	 */
	public function test_orders_without_line_items_are_not_sealed_as_zero(): void {
		$order                      = $this->make_order( 100, 7, 10.0, 1.9 );
		$order->items['line_item']  = array();
		$this->orders[100]          = $order;

		$this->accrual->maybe_award( 100 );

		$this->assertArrayNotHasKey( Accrual::META_EARNED, $order->saved_meta );
		$this->assertCount( 0, $this->wpdb->inserts );

		// Con las líneas ya presentes, la siguiente transición sí acredita.
		$order->items['line_item'][] = new \WC_Order_Item( 'Tomo', 10000.0, 1900.0 );
		$this->accrual->maybe_award( 100 );

		$this->assertSame( 238, $order->saved_meta[ Accrual::META_EARNED ] );
	}

	public function test_full_reversal_deducts_everything_and_fires_the_hook(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;
		$this->accrual->maybe_award( 100 );

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_after_order_deduct' )
			->once()
			->with( $order, 7, 238 );

		$this->accrual->deduct_all( 100 );

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertSame( 0, $order->saved_meta[ Accrual::META_EARNED ] );
	}

	public function test_partial_refund_recalculates_and_preserves_the_welcome_bonus(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;
		$this->accrual->maybe_award( 100 );

		// El bono viaja dentro del earned (como lo deja WelcomeBonus).
		Ledger::credit( 7, 500, 'welcome_bonus', 'order', 100 );
		$order->meta[ Accrual::META_EARNED ]        = 238 + 500;
		$order->meta[ Accrual::META_WELCOME_BONUS ] = 500;

		// Reembolsan $5.000 + $950 de IVA: base conservada $5.950 → 119 pts.
		$order->refunds[] = new \WC_Order_Refund(
			array( new \WC_Order_Item( 'Tomo devuelto', -5000.0, -950.0 ) )
		);

		$this->accrual->on_order_refunded( 100, 900 );

		// Objetivo: 119 + bono 500 = 619; diferencia 738−619 = 119 retirados.
		$this->assertSame( 619, $order->saved_meta[ Accrual::META_EARNED ] );
		$this->assertSame( 738 - 119, Ledger::balance( 7 ) );
	}

	/**
	 * Revisión independiente H13 (2026-09-19). Un pedido acreditado ANTES de
	 * 0.4.14 selló sus puntos sobre la base SIN redondear; con según qué tasa,
	 * la base redondeada de hoy da un punto menos. Un reembolso que no
	 * devuelve mercadería (sólo envío) no puede retirar ese punto; uno que sí
	 * la devuelve retira exactamente lo de las líneas que volvieron.
	 */
	public function test_a_shipping_only_refund_on_a_historic_order_does_not_retire_the_rounding_point(): void {
		// Base cruda 83,4 → con tasa 3/100: round(2,502) = 3 (lo sellado).
		// Base redondeada 83 → round(2,49) = 2.
		$order                               = $this->make_order( 100, 7, 70.0, 13.4 );
		$order->meta[ Accrual::META_RATE ]   = '3/100';
		$order->meta[ Accrual::META_EARNED ] = 3;
		$this->orders[100]                   = $order;
		Ledger::credit( 7, 3, 'order', 'order', 100 );

		// Sólo envío devuelto: ninguna línea de mercadería.
		$order->refunds[] = new \WC_Order_Refund( array(), 0.5, array( new \WC_Order_Item( 'Envío', -0.5, 0.0 ) ) );
		$this->accrual->on_order_refunded( 100, 900 );

		$this->assertSame( 3, Ledger::balance( 7 ), 'Sin mercadería devuelta no se retira nada.' );
		$this->assertArrayNotHasKey( Accrual::META_EARNED, $order->saved_meta );

		// La mitad de la mercadería: base íntegra 83 → 2; conservada 83,4 − 41,7 = 41,7 → 42 → round(1,26) = 1; se retira 2 − 1 = 1.
		$order->refunds[] = new \WC_Order_Refund( array( new \WC_Order_Item( 'Tomo devuelto', -35.0, -6.7 ) ), 41.7 );
		$this->accrual->on_order_refunded( 100, 901 );

		$this->assertSame( 2, Ledger::balance( 7 ) );
		$this->assertSame( 1, $order->saved_meta[ Accrual::META_EARNED ] );
	}

	/**
	 * R7-pedido-pagado-sin-puntos (2026-09-19): el pedido #27973 de producción,
	 * pagado y en «Procesando» —el estado que acredita— llevaba 4 días sin un
	 * punto, sin nota y sin alerta: la acreditación sólo cuelga de la transición
	 * de estado, y si en ese instante algo falla nadie vuelve a mirar.
	 */
	public function test_the_daily_sweep_awards_a_paid_order_left_without_points(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$guest             = $this->make_order( 101, 0, 10000.0, 1900.0 );
		$this->orders[100] = $order;
		$this->orders[101] = $guest;
		$consulta          = array();
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( &$consulta ): array {
				$consulta = $args;
				return array( 100, 101 );
			}
		);
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'user_registered' => '2026-08-25 10:00:00' ) );

		$this->accrual->sweep_unawarded();

		$this->assertSame( 238, $order->saved_meta[ Accrual::META_EARNED ] );
		$this->assertSame( 238, Ledger::balance( 7 ) );
		$this->assertStringContainsString( 'barrido diario', wp_json_encode( $order->notes ) );
		$this->assertArrayNotHasKey( Accrual::META_EARNED, $guest->saved_meta, 'Un pedido de invitado no acredita.' );
		// Revisión H1: lo que no acredita queda sellado para no entrar cada día.
		$this->assertSame( 'invitado', $guest->saved_meta[ Accrual::META_SWEEP_SKIPPED ] );
		$claves = array_column( array_filter( $consulta['meta_query'], 'is_array' ), 'key' );
		$this->assertSame( array( Accrual::META_EARNED, Accrual::META_PRE_PROGRAM_NOTED, Accrual::META_SWEEP_SKIPPED ), $claves, 'La consulta excluye lo acreditado, lo anterior al programa y lo sellado.' );
		$this->assertSame( array( 'processing', 'shipping-progress', 'completed' ), $consulta['status'] );
	}

	/**
	 * Revisión H1/M1: un pedido de invitado vinculado a una cuenta creada
	 * DESPUÉS no suma puntos (decisión del dueño R3-89ab0284), y se sella; la
	 * cuenta creada en la propia caja nace en el mismo segundo que el pedido.
	 */
	public function test_the_sweep_seals_an_order_whose_account_was_created_later(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;
		Functions\when( 'wc_get_orders' )->justReturn( array( 100 ) );
		// El pedido es del 2026-08-25 10:00 (make_order); la cuenta, dos días después.
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'user_registered' => '2026-08-27 09:00:00' ) );

		$this->accrual->sweep_unawarded();

		$this->assertArrayNotHasKey( Accrual::META_EARNED, $order->saved_meta );
		$this->assertSame( 'vinculado_despues', $order->saved_meta[ Accrual::META_SWEEP_SKIPPED ] );
		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	public function test_the_sweep_seals_what_maybe_award_refuses_and_says_so(): void {
		$order                       = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->items['line_item']   = array(); // Sin líneas: maybe_award() no acredita a propósito.
		$this->orders[100]           = $order;
		Functions\when( 'wc_get_orders' )->justReturn( array( 100 ) );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'user_registered' => '2026-08-25 10:00:00' ) );

		$this->accrual->sweep_unawarded();

		$this->assertArrayNotHasKey( Accrual::META_EARNED, $order->saved_meta );
		$this->assertSame( 'sin_acreditar', $order->saved_meta[ Accrual::META_SWEEP_SKIPPED ] );
		$this->assertStringContainsString( 'no pudo acreditar', wp_json_encode( $order->notes ) );
	}

	public function test_the_sweep_is_scheduled_daily_when_missing(): void {
		$scheduled = array();
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'as_schedule_recurring_action' )->alias(
			static function ( int $timestamp, int $interval, string $hook ) use ( &$scheduled ): int {
				unset( $timestamp );
				$scheduled[] = array( $hook, $interval );
				return 1;
			}
		);

		$this->accrual->ensure_sweep_scheduled();

		$this->assertSame( array( array( Accrual::HOOK_SWEEP, DAY_IN_SECONDS ) ), $scheduled );

		// Revisión M2: en el front no se paga la consulta de agendado.
		Functions\when( 'is_admin' )->justReturn( false );
		$scheduled = array();
		$this->accrual->ensure_sweep_scheduled();
		$this->assertSame( array(), $scheduled );
	}

	public function test_a_disguised_full_refund_behaves_like_a_reversal(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;
		$this->accrual->maybe_award( 100 );

		// Reembolsan TODO: resto $0 → anulación, con hook de reversa (el bono
		// devuelve la elegibilidad ahí).
		$order->refunds[] = new \WC_Order_Refund(
			array( new \WC_Order_Item( 'Tomo devuelto', -10000.0, -1900.0 ) )
		);

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_after_order_deduct' )
			->once()
			->with( $order, 7, 238 );

		$this->accrual->on_order_refunded( 100, 900 );

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertSame( 0, $order->saved_meta[ Accrual::META_EARNED ] );
	}

	public function test_refund_adjustment_never_credits_back(): void {
		$order                              = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->meta[ Accrual::META_EARNED ] = 100; // Menos de lo que la base daría.
		$this->orders[100]                  = $order;

		$this->accrual->on_order_refunded( 100, 900 );

		// La base sin reembolsos daría 238 > 100, pero un ajuste de reembolso
		// JAMÁS re-acredita.
		$this->assertCount( 0, $this->wpdb->inserts );
	}

	public function test_a_crash_window_is_reconciled_from_the_ledger_never_re_credited(): void {
		// Caída entre el crédito y el sellado de la meta: el ledger tiene la
		// fila, la meta está vacía. La siguiente transición SELLA, no acredita.
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;

		Ledger::credit( 7, 238, 'order', 'order', 100, 'Compra #100' );
		$this->assertSame( 238, Ledger::balance( 7 ) );

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_award_already_done' )->once();

		$this->accrual->maybe_award( 100 );

		$this->assertSame( 238, Ledger::balance( 7 ), 'NI un punto de más.' );
		$this->assertSame( 238, $order->saved_meta[ Accrual::META_EARNED ], 'La meta queda sellada desde el ledger.' );
		$this->assertStringContainsString( 'reconciliada', implode( ' ', $order->notes ) );
	}

	public function test_reconciliation_uses_net_deltas_not_row_counts(): void {
		// Re-auditoría: award 238, el cliente gasta 200, la reversa clampa y
		// recupera solo 38. El revival debe sellar el PENDIENTE neto (200),
		// jamás re-acreditar 238 ni sellar 238.
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;

		$this->accrual->maybe_award( 100 );
		Ledger::debit( 7, 200, 'redemption', 'order', 999, '', true );
		$this->accrual->deduct_all( 100 ); // Clampa: recupera 38 de 238.
		$this->assertSame( 0, Ledger::balance( 7 ) );

		$this->accrual->maybe_award( 100 );

		$this->assertSame( 0, Ledger::balance( 7 ), 'Ni un punto nuevo.' );
		$this->assertSame( 200, $order->saved_meta[ Accrual::META_EARNED ], 'Sella el neto exacto que el cliente retiene.' );
	}

	public function test_a_revived_order_accrues_again_with_a_fresh_event_key(): void {
		// Pedido acreditado → cancelado (reversa) → revivido → listo de nuevo.
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;

		$this->accrual->maybe_award( 100 );
		$this->accrual->deduct_all( 100 );
		$this->assertSame( 0, Ledger::balance( 7 ) );

		$this->accrual->maybe_award( 100 );

		$this->assertSame( 238, Ledger::balance( 7 ), 'El revival vuelve a acreditar.' );
		$claves = array_column( array_column( $this->wpdb->inserts, 1 ), 'event_key' );
		$this->assertContains( 'award_100_1', $claves );
		$this->assertContains( 'award_100_2', $claves, 'Cada ocurrencia lleva su propia clave.' );
	}

	public function test_an_amount_only_refund_also_removes_points(): void {
		// Reembolso POR IMPORTE (sin líneas): el hallazgo ALTA — antes era
		// invisible y el cliente conservaba puntos de plata devuelta.
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;
		$this->accrual->maybe_award( 100 );
		$this->assertSame( 238, Ledger::balance( 7 ) );

		$order->refunds[] = new \WC_Order_Refund( array(), 5950.0 );

		$this->accrual->on_order_refunded( 100, 900 );

		// Base conservada 11.900 − 5.950 = 5.950 → 119 puntos; se retiran 119.
		$this->assertSame( 119, $order->saved_meta[ Accrual::META_EARNED ] );
		$this->assertSame( 119, Ledger::balance( 7 ) );
	}

	public function test_money_paths_always_reread_metas_fresh(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;

		$this->accrual->maybe_award( 100 );
		$this->accrual->deduct_all( 100 );

		$this->assertGreaterThanOrEqual(
			2,
			$order->meta_reloads,
			'Acreditación y reversa re-leen las metas frescas bajo candado (caché de pedidos persistente en prod).'
		);
	}

	public function test_a_malformed_program_start_fails_closed(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_program_start' === $option ? 'esto-no-es-fecha' : $default_value
		);

		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;

		$this->accrual->maybe_award( 100 );

		$this->assertSame( 0, Ledger::balance( 7 ), 'Config rota = NO acreditar (fallar abierta regalaría lo retroactivo).' );
	}

	public function test_refunding_only_the_money_of_a_points_heavy_order_is_partial(): void {
		// Hallazgo BAJA del confirmador: pedido pagado mayormente con puntos
		// (ítems 11.900, canje 11.000, plata 900). Devolver la plata NO es un
		// total encubierto: el canje neto sigue aplicado y el bono debe vivir.
		$order        = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->total = 900.0;
		$order->meta[ Accrual::META_EARNED ]        = 518;
		$order->meta[ Accrual::META_WELCOME_BONUS ] = 500;
		$order->meta[ \Akibara\Rewards\Points\Redemption::META_REDEEMED_POINTS ] = 11000;
		$order->meta[ \Akibara\Rewards\Points\Redemption::META_REDEEMED_VALUE ]  = 11000.0;
		$this->orders[100] = $order;

		Ledger::credit( 7, 518, 'order', 'order', 100 );
		$order->refunds[] = new \WC_Order_Refund( array(), 900.0 );

		$this->accrual->on_order_refunded( 100, 900 );

		$this->assertNotSame( 0, $order->meta[ Accrual::META_EARNED ], 'No es reversa total.' );
		$this->assertSame( 500, $order->meta[ Accrual::META_WELCOME_BONUS ], 'El bono sobrevive.' );
	}

	public function test_status_transition_routes_award_and_reversal(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;

		$this->accrual->on_status_changed( 100, 'processing', 'shipping-progress', $order );
		$this->assertSame( 238, Ledger::balance( 7 ) );

		$this->accrual->on_status_changed( 100, 'shipping-progress', 'cancelled', $order );
		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	/**
	 * Decisión del dueño 2026-09-16: los puntos se pagan cuando la pasarela
	 * confirma el pago, o sea al entrar en «Procesando». Antes había que
	 * esperar a «Listo para enviar» y el cliente recién pagado no veía nada.
	 *
	 * @testdox Al confirmarse el pago (Procesando) se acredita.
	 */
	public function test_acredita_al_confirmarse_el_pago(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;

		$this->accrual->on_status_changed( 100, 'pending', 'processing', $order );

		$this->assertSame( 238, Ledger::balance( 7 ) );
	}

	/**
	 * El disparador no puede exigir pisar EXACTAMENTE ese estado: un pedido que
	 * salta de «Pendiente de pago» a «Completado» —pago manual, retiro en
	 * tienda, o el dueño moviéndolo a mano— pasaba de largo y el cliente no
	 * veía sus puntos nunca. Lo mismo ocurría con los pedidos ya en curso el
	 * día que se cambia el estado en el panel.
	 *
	 * @testdox Un pedido que salta directo a «Completado» también acredita.
	 */
	public function test_acredita_aunque_el_pedido_salte_el_estado_configurado(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;

		$this->accrual->on_status_changed( 100, 'pending', 'completed', $order );

		$this->assertSame( 238, Ledger::balance( 7 ) );
	}

	/** @testdox Pasar por todos los estados pagados acredita UNA sola vez. */
	public function test_no_acredita_dos_veces_al_avanzar_de_estado(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;

		$this->accrual->on_status_changed( 100, 'pending', 'processing', $order );
		$this->accrual->on_status_changed( 100, 'processing', 'shipping-progress', $order );
		$this->accrual->on_status_changed( 100, 'shipping-progress', 'completed', $order );

		$this->assertSame( 238, Ledger::balance( 7 ) );
	}

	/**
	 * Y al revés: si el dueño mueve el disparador MÁS ADELANTE, un estado
	 * anterior no puede adelantar la acreditación.
	 *
	 * @testdox Con el disparador en «Completado», «Procesando» todavía no paga.
	 */
	public function test_no_acredita_antes_del_estado_que_fija_el_panel(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_award_status' === $option ? 'completed' : $default_value
		);

		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;

		$this->accrual->on_status_changed( 100, 'pending', 'processing', $order );
		$this->assertSame( 0, Ledger::balance( 7 ), 'El panel manda: todavía no.' );

		$this->accrual->on_status_changed( 100, 'processing', 'completed', $order );
		$this->assertSame( 238, Ledger::balance( 7 ) );
	}

	/** @testdox Un estado que no es de pedido pagado no acredita. */
	public function test_un_estado_no_pagado_no_acredita(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;

		$this->accrual->on_status_changed( 100, 'pending', 'on-hold', $order );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	public function test_refund_adjustment_uses_the_sealed_rate_not_the_live_config(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;
		$this->accrual->maybe_award( 100 );
		$this->assertSame( '2/100', (string) $order->saved_meta[ Accrual::META_RATE ], 'La tasa aplicada queda sellada en el pedido.' );

		// El dueño SUBE la tasa a 5/$100 después de acreditar…
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_earn_points' === $option ? 5 : $default_value
		);

		$order->refunds[] = new \WC_Order_Refund( array(), 5950.0 );
		$this->accrual->on_order_refunded( 100, 900 );

		// Con la tasa viva el objetivo (297) superaba lo acreditado y no se
		// retiraba NADA; con la sellada se retiran los 119 que corresponden.
		$this->assertSame( 119, $order->saved_meta[ Accrual::META_EARNED ] );
		$this->assertSame( 119, Ledger::balance( 7 ) );
	}

	public function test_cancellation_recovers_from_the_ledger_when_the_meta_was_lost(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;
		// Caída entre acreditar y sellar: el crédito existe, la meta no. Un
		// pedido CANCELADO nunca vuelve por la acreditación, así que la
		// conciliación de maybe_award no aplica: la reversa debe mirar sola.
		Ledger::credit( 7, 238, 'order', 'order', 100, 'Compra #100', true, 'award_100_1' );
		$this->assertSame( 238, Ledger::balance( 7 ) );

		$this->accrual->deduct_all( 100 );

		$this->assertSame( 0, Ledger::balance( 7 ), 'La anulación deduce el PENDIENTE del ledger, no la meta perdida.' );
	}

	public function test_a_replayed_refund_hook_never_deducts_twice(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;
		$this->accrual->maybe_award( 100 );
		$order->refunds[] = new \WC_Order_Refund( array(), 5950.0 );
		$this->accrual->on_order_refunded( 100, 900 );
		$this->assertSame( 119, Ledger::balance( 7 ) );

		// El sellado de la meta «se pierde» (caída tras el débito)…
		$order->meta[ Accrual::META_EARNED ] = 238;
		// …y el MISMO reembolso re-dispara el hook.
		$this->accrual->on_order_refunded( 100, 900 );

		$this->assertSame( 119, Ledger::balance( 7 ), 'El mismo reembolso jamás descuenta dos veces (event_key).' );
		$this->assertSame( 119, $order->saved_meta[ Accrual::META_EARNED ], 'El reintento sella la meta que faltaba.' );
	}

	public function test_a_deleted_refund_leaves_a_manual_review_trail(): void {
		$order             = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$this->orders[100] = $order;
		$this->accrual->maybe_award( 100 );

		// Sin reembolsos que hayan descontado, borrar uno no deja ruido.
		$this->accrual->on_refund_deleted( 900, 100 );
		$this->assertCount( 1, $order->notes );

		$order->refunds[] = new \WC_Order_Refund( array(), 5950.0 );
		$this->accrual->on_order_refunded( 100, 900 );

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_manual_review_needed' )->once();
		$this->accrual->on_refund_deleted( 900, 100 );
		$this->assertStringContainsString( 'no se devuelven solos', strtolower( (string) end( $order->notes ) ) );
	}

}
