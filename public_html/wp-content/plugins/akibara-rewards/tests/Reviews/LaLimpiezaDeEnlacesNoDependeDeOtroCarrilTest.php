<?php
/**
 * La tabla de enlaces de reseña se limpia sola, pase lo que pase con los
 * interruptores de al lado.
 *
 * Hasta la 0.4.8 la purga era una línea dentro del cron de VENCIMIENTO DE
 * PUNTOS: apagar «Hacer vencer los puntos» —un clic que el panel presenta como
 * inofensivo y que el dueño puede querer por razones comerciales— dejaba la
 * tabla creciendo para siempre, con una fila por invitación enviada (hallazgos
 * S-961547f2 y S-bd6ecf5c, 2026-09-18).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Points\Expiry;
use Akibara\Rewards\Reviews\TokenCleanup;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.4.9
 */
final class LaLimpiezaDeEnlacesNoDependeDeOtroCarrilTest extends RewardsTestCase {

	/** @testdox La limpieza tiene su propia acción diaria, no la del vencimiento. */
	public function test_the_cleanup_owns_its_schedule(): void {
		$agendadas = array();

		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'as_schedule_recurring_action' )->alias(
			static function ( int $start, int $interval, string $hook ) use ( &$agendadas ): int {
				$agendadas[ $hook ] = $interval;
				return 77;
			}
		);

		( new TokenCleanup() )->ensure_scheduled();

		self::assertSame( array( TokenCleanup::HOOK => DAY_IN_SECONDS ), $agendadas, 'Una acción diaria propia.' );
		self::assertNotSame( Expiry::HOOK, TokenCleanup::HOOK, 'Si compartieran hook, compartirían interruptor.' );
	}

	/** @testdox El cron de vencimiento ya no es quien limpia la tabla. */
	public function test_expiry_no_longer_owns_the_cleanup(): void {
		$expiry = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Points/Expiry.php' );

		self::assertStringNotContainsString(
			'purge_stale_review_tokens',
			$expiry,
			'La higiene de una tabla de reseñas no puede colgar del interruptor de los puntos.'
		);

		$limpieza = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Reviews/TokenCleanup.php' );
		self::assertStringContainsString( 'purge_stale_review_tokens', $limpieza );
	}

	/** @testdox La limpieza se registra fuera del gate del motor de reseñas. */
	public function test_the_cleanup_lives_outside_the_engine_gate(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );

		$gate  = strpos( $plugin, 'Options::reviews_engine_on()' );
		$linea = strpos( $plugin, 'TokenCleanup() )->register()' );

		self::assertIsInt( $gate );
		self::assertIsInt( $linea );
		self::assertLessThan( $gate, $linea, 'Apagar el motor deja de crear enlaces, pero los viejos siguen caducando.' );
	}

	/** @testdox Apagar el vencimiento retira su acción de la cola. */
	public function test_turning_expiry_off_unschedules_its_action(): void {
		$retiradas = array();

		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'as_next_scheduled_action' )->justReturn( 123 );
		Functions\when( 'as_schedule_recurring_action' )->justReturn( 1 );
		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( string $hook ) use ( &$retiradas ): void {
				$retiradas[] = $hook;
			}
		);
		// Carril apagado: 0 meses.
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_expiry_months' === $option ? 0 : $default_value
		);

		( new Expiry() )->ensure_scheduled();

		self::assertSame( array( Expiry::HOOK ), $retiradas, 'Una acción recurrente sin trabajo es ruido diario en la cola.' );
	}

	/** @testdox La purga sólo borra enlaces caducados y nunca usados. */
	public function test_the_purge_never_touches_used_links(): void {
		\Akibara\Rewards\Support\Schema::purge_stale_review_tokens();

		$sql = implode( ' | ', $this->wpdb->queries );
		self::assertStringContainsString( 'DELETE FROM wp_akb_rewards_review_forms', $sql );
		self::assertStringContainsString( 'used_at IS NULL', $sql, 'Un enlace usado es la auditoría de qué reseña vino de qué pedido.' );
		self::assertStringContainsString( 'LIMIT 500', $sql, 'Sin LIMIT, una tabla grande bloquea el cron.' );
	}
}
