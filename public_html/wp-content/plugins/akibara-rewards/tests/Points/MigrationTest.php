<?php
/**
 * Contrato de la migración desde SPAR.
 *
 * La fixture replica el esquema REAL de producción (verificado por ssh e
 * ingeniería inversa el 2026-08-30): `action` es la NOTA humana con el número
 * de pedido dentro («Puntos obtenidos por pedido: #27720»), `action_id` es el
 * slug de origen ('order') y el bono no lleva número (se resuelve por la
 * usermeta del mu-plugin). La primera fixture de esta suite codificaba el
 * esquema INVERSO y certificaba el bug en verde — hallazgo de la célula.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Accrual;
use Akibara\Rewards\Points\Emails;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\Migration;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Points\WelcomeBonus;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.1.0
 */
final class MigrationTest extends RewardsTestCase {

	/** @var array<int,\WC_Order> */
	private array $orders = array();

	protected function setUp(): void {
		parent::setUp();

		$orders = &$this->orders;
		Functions\when( 'wc_get_order' )->alias(
			static function ( int $id ) use ( &$orders ) {
				return $orders[ $id ] ?? false;
			}
		);

		// La tabla de SPAR existe salvo que un test diga lo contrario.
		$this->wpdb->var_answers["SHOW TABLES LIKE 'wp_spar_points_logs'"] = 'wp_spar_points_logs';

		// Filas con la forma REAL de prod: nota humana + type add/remove +
		// action_id de origen. Incluye un remove para fijar el signo.
		$this->wpdb->results_answers['FROM wp_spar_points_logs'] = array(
			array(
				'id'        => '1',
				'user_id'   => '233',
				'action'    => 'Puntos obtenidos por pedido: #27720',
				'type'      => 'add',
				'action_id' => 'order',
				'points'    => '4554',
				'date'      => '2026-08-21 10:23:11',
			),
			array(
				'id'        => '2',
				'user_id'   => '233',
				'action'    => 'Bono de bienvenida por tu primera compra',
				'type'      => 'add',
				'action_id' => 'order',
				'points'    => '500',
				'date'      => '2026-08-21 10:23:11',
			),
			array(
				'id'        => '5',
				'user_id'   => '238',
				'action'    => 'Puntos obtenidos por pedido: #27722',
				'type'      => 'add',
				'action_id' => 'order',
				'points'    => '1288',
				'date'      => '2026-08-22 09:29:39',
			),
			array(
				'id'        => '6',
				'user_id'   => '238',
				'action'    => 'Puntos Akibara Deducido por Cancelado Pedido: #27722',
				'type'      => 'remove',
				'action_id' => 'order',
				'points'    => '400',
				'date'      => '2026-08-23 09:00:00',
			),
		);

		$this->wpdb->results_answers["meta_key = '_spar_points'"] = array(
			array(
				'user_id'    => '233',
				'meta_value' => '5054',
			),
			array(
				'user_id'    => '238',
				'meta_value' => '888',
			),
		);
		$this->wpdb->results_answers["meta_key = '_spar_total_earned_points'"] = array(
			array(
				'user_id'    => '233',
				'meta_value' => '5054',
			),
			array(
				'user_id'    => '238',
				'meta_value' => '1288',
			),
		);
		$this->wpdb->results_answers["meta_key = '_akibara_welcome_bonus_paid'"] = array(
			array(
				'user_id'    => '233',
				'meta_value' => '27720',
			),
		);
	}

	/**
	 * Pedidos espejo con las metas que SPAR deja de verdad.
	 */
	private function seed_orders(): void {
		$order233                                   = $this->make_order( 27720, 233, 202700.0, 38513.0 );
		$order233->meta['points_earned']            = 5054;
		$order233->meta['points_first_order_bonus'] = 500;
		$this->orders[27720]                        = $order233;

		$order238                            = $this->make_order( 27722, 238, 54400.0, 10336.0 );
		$order238->meta['points_earned']     = 1288;
		$order238->meta['points_deducted']   = 400;
		$this->orders[27722]                 = $order238;
	}

	public function test_refuses_to_rerun_over_a_non_empty_ledger(): void {
		// El guard mira TODO el ledger, no solo la migración: el plugin nuevo
		// pudo haber movido puntos ya, y pisarían saldos.
		Ledger::credit( 5, 100, 'order', 'order', 900 );

		$report = Migration::migrate( false );

		$this->assertStringContainsString( '1 filas (0 de migración)', $report['error'] );
		$this->assertCount( 1, $this->wpdb->inserts );
	}

	public function test_dry_run_reports_everything_and_writes_nothing(): void {
		$report = Migration::migrate( true );

		$this->assertSame( '', $report['error'] );
		$this->assertCount( 0, $this->wpdb->inserts );
		$this->assertSame( array(), $this->wpdb->user_meta );

		$joined = implode( "\n", $report['lines'] );
		$this->assertStringContainsString( '4 movimientos', $joined );
		$this->assertStringContainsString( '2 saldos', $joined );
	}

	public function test_migrates_the_real_prod_schema_with_signed_running_balance(): void {
		$this->seed_orders();

		$report = Migration::migrate( false );

		$this->assertSame( '', $report['error'] );
		$this->assertCount( 4, $this->wpdb->inserts );

		// El número de pedido sale de la NOTA (#27720), no de action_id.
		[ , $primera ] = $this->wpdb->inserts[0];
		$this->assertSame( 'order', $primera['ref_type'] );
		$this->assertSame( 27720, $primera['ref_id'] );
		$this->assertSame( 4554, $primera['delta'] );

		// El bono no lleva número: se resuelve por la usermeta del mu-plugin.
		[ , $bono ] = $this->wpdb->inserts[1];
		$this->assertSame( 27720, $bono['ref_id'] );

		// El remove entra con delta negativo y saldo corrido correcto.
		[ , $reversa ] = $this->wpdb->inserts[3];
		$this->assertSame( -400, $reversa['delta'] );
		$this->assertSame( 888, $reversa['balance_after'] );
		$this->assertSame( 'migration', $reversa['action'] );

		// Saldos finales: los declarados por SPAR.
		$this->assertSame( 5054, $this->wpdb->user_meta[233][ Ledger::META_BALANCE ] );
		$this->assertSame( 888, $this->wpdb->user_meta[238][ Ledger::META_BALANCE ] );
		$this->assertSame( 27720, $this->wpdb->user_meta[233][ WelcomeBonus::META_WELCOME_PAID ] );

		// Metas de pedido: bono dentro del earned, y correo sellado.
		$order233 = $this->orders[27720];
		$this->assertSame( 5054, $order233->saved_meta[ Accrual::META_EARNED ] );
		$this->assertSame( 500, $order233->saved_meta[ Accrual::META_WELCOME_BONUS ] );
		$this->assertArrayHasKey( Emails::META_EMAIL_SENT, $order233->saved_meta );
	}

	public function test_a_pre_cutover_reversal_imports_as_zero_outstanding(): void {
		// SPAR conserva points_earned tras deducir: lo pendiente es
		// earned − deducted, o el pedido sería deducible otra vez.
		$this->seed_orders();

		Migration::migrate( false );

		$order238 = $this->orders[27722];
		$this->assertSame( 888, $order238->saved_meta[ Accrual::META_EARNED ] );
	}

	public function test_redemption_metas_are_mirrored(): void {
		$this->seed_orders();
		$order = $this->orders[27720];
		$order->meta['_spar_points_redeemed']          = 700;
		$order->meta['_spar_points_redeemed_value']    = 700.0;
		$order->meta['_spar_points_redeemed_refunded'] = 200;

		Migration::migrate( false );

		$this->assertSame( 700, $order->saved_meta[ Redemption::META_REDEEMED_POINTS ] );
		$this->assertSame( 700.0, $order->saved_meta[ Redemption::META_REDEEMED_VALUE ] );
		$this->assertSame( 200, $order->saved_meta[ Redemption::META_RESTORED_POINTS ] );
	}

	public function test_force_cleans_previous_migration_rows_instead_of_duplicating(): void {
		$this->seed_orders();
		Migration::migrate( false );
		$this->assertCount( 4, $this->wpdb->inserts );

		$report = Migration::migrate( false, true );

		$this->assertSame( '', $report['error'] );
		$this->assertCount( 4, $this->wpdb->inserts, '--force re-inserta, JAMÁS duplica.' );
	}

	public function test_failed_inserts_abort_before_touching_balances(): void {
		$this->seed_orders();
		$this->wpdb->fail_insert = true;

		$report = Migration::migrate( false );

		$this->assertStringContainsString( 'FALLARON', $report['error'] );
		$this->assertSame( array(), $this->wpdb->user_meta, 'Con el ledger roto, los saldos no se tocan.' );
	}

	public function test_warns_when_the_declared_balance_diverges_from_the_log(): void {
		// Al usuario 238 el log le reconstruye 888 pero SPAR declara 999.
		$this->wpdb->results_answers["meta_key = '_spar_points'"][1]['meta_value'] = '999';

		$report = Migration::migrate( true );

		$joined = implode( "\n", $report['lines'] );
		$this->assertStringContainsString( 'AVISO user 238', $joined );
		$this->assertStringContainsString( '999 != reconstruido del log 888', $joined );
	}

	public function test_a_log_only_user_gets_the_rebuilt_balance_with_a_warning(): void {
		// El 238 desaparece de los saldos declarados: solo existe en el log.
		$this->wpdb->results_answers["meta_key = '_spar_points'"] = array(
			array(
				'user_id'    => '233',
				'meta_value' => '5054',
			),
		);
		$this->seed_orders();

		$report = Migration::migrate( false );

		$joined = implode( "\n", $report['lines'] );
		$this->assertStringContainsString( 'AVISO user 238', $joined );
		$this->assertStringContainsString( 'no declara saldo', $joined );
		$this->assertSame( 888, $this->wpdb->user_meta[238][ Ledger::META_BALANCE ] );
	}

	public function test_missing_spar_table_aborts_cleanly(): void {
		$this->wpdb->var_answers["SHOW TABLES LIKE 'wp_spar_points_logs'"] = null;

		$report = Migration::migrate( false );

		$this->assertStringContainsString( 'nada que migrar', $report['error'] );
	}

	public function test_a_vanished_order_is_reported_not_fatal(): void {
		// Solo existe el 27720; el 27722 desapareció.
		$order233                        = $this->make_order( 27720, 233, 202700.0, 38513.0 );
		$order233->meta['points_earned'] = 5054;
		$this->orders[27720]             = $order233;

		$report = Migration::migrate( false );

		$this->assertSame( '', $report['error'] );
		$joined = implode( "\n", $report['lines'] );
		$this->assertStringContainsString( 'pedido #27722 del log ya no existe', $joined );
	}
}
