<?php
/**
 * Contrato de los interruptores de carril (directiva del dueño 2026-08-31:
 * TODO activable/desactivable con un clic, «ahora o después», por alguien
 * sin programación).
 *
 * El interruptor vive en el ACCESSOR: apagarlo apaga a la vez el motor, los
 * correos, Mi cuenta, la ficha y la página pública — y los montos guardados
 * se conservan para re-encender.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

require_once __DIR__ . '/route-exception-stub.php';

use Akibara\Rewards\Points\Expiry;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Points\ProgramPage;
use Akibara\Rewards\Points\Referrals;
use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Blocks\CheckoutIntegration;
use Akibara\Rewards\Points\AdminAdjust;
use Brain\Monkey\Functions;

/**
 * @since 0.2.0
 */
final class LaneTogglesTest extends RewardsTestCase {

	/**
	 * Apaga por option los carriles indicados (el resto queda en default).
	 *
	 * @param string ...$apagados Options yes/no a poner en 'no'.
	 */
	private function apagar( string ...$apagados ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => in_array( $option, $apagados, true ) ? 'no' : $default_value
		);
	}

	public function test_every_lane_dies_with_its_own_single_click(): void {
		$this->apagar(
			'akb_rewards_earn_enabled',
			'akb_rewards_welcome_enabled',
			'akb_rewards_reviews_enabled',
			'akb_rewards_birthday_enabled',
			'akb_rewards_referral_enabled',
			'akb_rewards_expiry_enabled'
		);

		$this->assertSame( 0, Options::earn_points(), 'Compra apagada: nadie acumula.' );
		$this->assertSame( 0, Options::welcome_bonus(), 'Bono apagado.' );
		$this->assertSame( 0, Options::review_points(), 'Reseñas apagadas.' );
		$this->assertSame( 0, Options::birthday_points(), 'Cumpleaños apagado.' );
		$this->assertSame( 0, Options::referral_points(), 'Referidos apagados.' );
		$this->assertSame( 0, Expiry::months(), 'Vencimiento apagado: no vencen jamás.' );
	}

	public function test_switching_back_on_restores_the_saved_amounts(): void {
		// Los montos NO se pierden al apagar: re-encender vuelve a los valores
		// guardados (acá, los defaults del catálogo).
		$this->assertSame( 2, Options::earn_points() );
		$this->assertSame( 500, Options::welcome_bonus() );
		$this->assertSame( 500, Options::review_points() );
		$this->assertSame( 1000, Options::birthday_points() );
		$this->assertSame( 500, Options::referral_points() );
		$this->assertSame( 12, Expiry::months() );
	}

	public function test_expiry_toggle_silences_the_daily_run_instantly(): void {
		$this->apagar( 'akb_rewards_expiry_enabled' );
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 700;
		Functions\when( 'get_users' )->justReturn( array( 7 ) );

		( new Expiry() )->run_daily();

		$this->assertSame( 700, Ledger::balance( 7 ), 'Con el interruptor apagado nada vence, aunque months siga en 12.' );
		$this->assertSame( array(), $this->wpdb->inserts );
	}

	public function test_the_public_page_reflects_the_clicks_without_editing_anything(): void {
		$this->apagar( 'akb_rewards_welcome_enabled', 'akb_rewards_birthday_enabled', 'akb_rewards_expiry_enabled' );

		$html = ( new ProgramPage() )->render();

		$this->assertStringNotContainsString( 'bienvenida', $html );
		$this->assertStringNotContainsString( 'cumpleaños', $html );
		$this->assertStringContainsString( 'no vencen', $html );
		$this->assertStringContainsString( '2 puntos por cada $100', $html, 'Los carriles encendidos siguen intactos.' );
	}

	public function test_the_redeem_minimum_gates_engine_account_and_page(): void {
		// Directiva 2026-08-31: mínimo para canjear, configurable (default 100).
		$this->assertSame( 100, Options::redeem_min_points() );

		$frase = ProgramPage::redeem_sentence();
		$this->assertStringContainsString( 'desde 100 puntos', $frase );

		// Con 0 vuelve el «sin mínimo» honesto.
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_redeem_min_points' === $option ? 0 : $default_value
		);
		$this->assertStringContainsString( 'No hay un mínimo', ProgramPage::redeem_sentence() );
	}

	public function test_checkout_blocks_update_requires_valid_explicit_amount(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$this->install_fake_wc();
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 1000;
		WC()->cart = new class() extends \WC_Cart { public function calculate_totals(): void {} };
		WC()->cart->contents = array( array( 'line_total' => 50000.0, 'line_tax' => 0.0 ) );
		$integracion = new CheckoutIntegration();
		Redemption::set_intent( 300 );
		foreach ( array( 50, 5000 ) as $requested ) {
			try {
				$integracion->handle_update( array( 'points' => $requested ) );
				$this->fail( 'No debe recortar una solicitud inválida.' );
			} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $error ) {
				$this->assertSame( 400, $error->getCode() );
			}
			$this->assertSame( 300, Redemption::intent_points() );
		}
		$integracion->handle_update( array( 'points' => 1000 ) );
		$this->assertSame( 1000, Redemption::intent_points() );
		$integracion->handle_update( array( 'points' => 0 ) );
		$this->assertSame( 0, Redemption::intent_points() );
	}

	public function test_checkout_blocks_guest_cannot_set_intent(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->install_fake_wc();
		\Akibara\Rewards\Points\Redemption::set_intent( 0 );

		( new CheckoutIntegration() )->handle_update( array( 'points' => 500 ) );

		$this->assertSame( 0, \Akibara\Rewards\Points\Redemption::intent_points(), 'El canje exige cuenta.' );
	}

	public function test_checkout_blocks_data_respects_the_lane_clicks(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->install_fake_wc();
		$this->apagar( 'akb_rewards_welcome_enabled', 'akb_rewards_earn_enabled' );

		$data = ( new CheckoutIntegration() )->cart_data();

		$this->assertSame( 0, $data['welcome_bonus'], 'Apagado no promete en el checkout.' );
		$this->assertSame( 0, $data['earn_points'] );
		$this->assertFalse( $data['logged_in'] );
		$this->assertSame( 100, $data['min'] );
	}

	public function test_manual_adjust_credits_with_audit_and_clamps_debits(): void {
		Functions\when( 'wp_get_current_user' )->alias( static function () {
			$u = new \WP_User( 1 );
			$u->user_login = 'ale.fvaras';
			return $u;
		} );
		$this->wpdb->user_meta[9][ Ledger::META_BALANCE ] = 100;
		$adjust = new AdminAdjust();

		// Otorgar 400 como compensación: asiento con admin y motivo.
		list( $ok, $msg ) = $adjust->apply( 9, 400, 1, 'Compensación por demora — pedido #123' );
		$this->assertTrue( $ok, $msg );
		$this->assertSame( 500, Ledger::balance( 9 ) );
		$fila = end( $this->wpdb->inserts )[1];
		$this->assertSame( 'admin_adjust', $fila['action'] );
		$this->assertStringContainsString( 'por ale.fvaras', $fila['note'] );
		$this->assertStringContainsString( 'Compensación por demora', $fila['note'] );

		// Quitar 9999: clampa al saldo, jamás negativo.
		list( $ok2, $msg2 ) = $adjust->apply( 9, 9999, -1, 'Corrección' );
		$this->assertTrue( $ok2, $msg2 );
		$this->assertSame( 0, Ledger::balance( 9 ) );

		// Sin motivo: rechazado — la auditoría es obligatoria.
		list( $ok3, ) = $adjust->apply( 9, 100, 1, '' );
		$this->assertFalse( $ok3 );

		// Cliente inexistente: rechazado.
		list( $ok4, ) = $adjust->apply( 0, 100, 1, 'x' );
		$this->assertFalse( $ok4 );
	}

	public function test_manual_adjust_is_idempotent_against_double_submit(): void {
		Functions\when( 'wp_get_current_user' )->alias( static function () {
			$u = new \WP_User( 1 );
			$u->user_login = 'ale.fvaras';
			return $u;
		} );
		$adjust = new AdminAdjust();

		list( $ok, $msg )   = $adjust->apply( 9, 400, 1, 'Compensación por demora' );
		list( $ok2, $msg2 ) = $adjust->apply( 9, 400, 1, 'Compensación por demora' );

		$this->assertTrue( $ok, $msg );
		$this->assertFalse( $ok2, 'Un doble click o F5 sobre el POST asienta UNA vez.' );
		$this->assertStringContainsString( 'doble envío', $msg2 );
		$this->assertSame( 400, Ledger::balance( 9 ) );
	}

}
