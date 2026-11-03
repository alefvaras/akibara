<?php
/** Próxima fecha publicada contra el predicado canónico, sin ejecutar cron. */
declare( strict_types = 1 );
namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Expiry;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class NextExpiryTest extends RewardsTestCase {
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		$this->options = array( 'akb_rewards_expiry_months' => 1 );
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
	}

	private function movement( int $delta, string $date, string $action = 'order', int $user = 7 ): void {
		$this->wpdb->insert( 'wp_akb_rewards_ledger', array( 'user_id' => $user, 'delta' => $delta, 'created_at' => $date, 'action' => $action ) );
		$this->wpdb->user_meta[ $user ][ Ledger::META_BALANCE ] = ( $this->wpdb->user_meta[ $user ][ Ledger::META_BALANCE ] ?? 0 ) + $delta;
	}

	/** @testdox La fecha publicada cruza exactamente el predicado mensual estricto. @dataProvider dates */
	public function test_calendar_boundaries( string $earned, int $months, string $expected ): void {
		$this->options['akb_rewards_expiry_months'] = $months;
		$this->movement( 300, $earned );
		$before = $this->wpdb->inserts;
		$result = Expiry::next_expiry( 7 );
		self::assertSame( $expected, $result['date'] );
		self::assertSame( 300, $result['points'] );
		$day = new \DateTimeImmutable( $result['date'], wp_timezone() );
		self::assertSame( 0, Expiry::expirable_for( 7, Expiry::months_before( $day->modify( '-1 day' )->setTime( 23, 59, 59 ), $months )->format( 'Y-m-d H:i:s' ) ) );
		self::assertSame( 300, Expiry::expirable_for( 7, Expiry::months_before( $day->setTime( 23, 59, 59 ), $months )->format( 'Y-m-d H:i:s' ) ) );
		self::assertSame( $before, $this->wpdb->inserts, 'La lectura no vence ni acredita.' );
	}

	public function dates(): array {
		return array(
			'ene31' => array( '2024-01-31 12:00:00', 1, '2024-03-01' ),
			'ene30' => array( '2025-01-30 12:00:00', 1, '2025-03-01' ),
			'ene28' => array( '2025-01-28 12:00:00', 1, '2025-02-28' ),
			'igualdad fin día' => array( '2025-01-28 23:59:59', 1, '2025-03-01' ),
			'bisiesto' => array( '2024-02-29 12:00:00', 12, '2025-03-01' ),
			'normal' => array( '2025-05-20 08:00:00', 1, '2025-06-20' ),
		);
	}

	/** @testdox Todos los débitos consumen primero lo antiguo y sólo se informa el primer día. */
	public function test_fifo_partial_and_fully_consumed_batches(): void {
		$this->movement( 300, '2025-01-10 12:00:00' );
		$this->movement( 500, '2025-01-20 12:00:00' );
		$this->movement( -200, '2025-01-25 12:00:00', 'redemption' );
		$result = Expiry::next_expiry( 7 );
		self::assertSame( array( 'date' => '2025-02-10', 'points' => 100, 'pending' => true ), $result );
		self::assertCount( 4, $this->wpdb->var_log, 'Cuatro consultas sin bucle SQL por fecha o abono.' );
		$this->movement( -100, '2025-01-26 12:00:00', 'order_reversal' );
		self::assertSame( '2025-02-20', Expiry::next_expiry( 7 )['date'] );
		$this->movement( -100, '2025-01-27 12:00:00', 'expiry' );
		self::assertSame( 400, Expiry::next_expiry( 7 )['points'] );
	}

	/** @testdox Migraciones envejecen desde la época y sin ella quedan protegidas. */
	public function test_migration_epoch_and_missing_epoch(): void {
		$this->movement( 500, '2017-01-01 00:00:00', 'migration' );
		self::assertNull( Expiry::next_expiry( 7 ) );
		$this->options['akb_rewards_migration_epoch'] = '2025-01-31 12:00:00';
		self::assertSame( '2025-03-01', Expiry::next_expiry( 7 )['date'] );
		unset( $this->options['akb_rewards_migration_epoch'] );
		$this->movement( 100, '2025-01-10 12:00:00' );
		self::assertSame( 100, Expiry::next_expiry( 7 )['points'] );
	}

	/** @testdox Saldo vivo y carril apagado controlan el aviso. */
	public function test_live_balance_and_off(): void {
		$this->movement( 1000, '2025-01-10 12:00:00' );
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 50;
		self::assertSame( 50, Expiry::next_expiry( 7 )['points'] );
		$this->options['akb_rewards_expiry_enabled'] = 'no';
		self::assertNull( Expiry::next_expiry( 7 ) );
		$this->options['akb_rewards_expiry_enabled'] = 'yes';
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 0;
		self::assertNull( Expiry::next_expiry( 7 ) );
	}

	/** @testdox El vencimiento futuro conserva zona y datos privados sin depender de avisos. */
	public function test_future_timezone_and_privacy(): void {
		$this->options['akb_rewards_expiry_warn_days'] = 0;
		$this->movement( 100, '2099-09-08 12:00:00' );
		$this->movement( 2000, '2000-01-01 00:00:00', 'order', 8 );
		$result = Expiry::next_expiry( 7 );
		self::assertSame( array( 'date' => '2099-10-08', 'points' => 100, 'pending' => false ), $result );
		foreach ( $this->wpdb->var_log as $sql ) {
			self::assertStringContainsString( 'user_id = 7', $sql );
		}
	}
}
