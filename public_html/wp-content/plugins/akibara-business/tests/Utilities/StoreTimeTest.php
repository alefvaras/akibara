<?php
/**
 * El corte de «hoy» en la zona de la tienda: un solo lugar para las cuatro
 * superficies que deciden si una promesa «llega» o «llegaba».
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Utilities;

use Akibara\Business\Utilities\StoreTime;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class StoreTimeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Santiago' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox A las 23:00 de Santiago sigue siendo hoy aunque en UTC ya sea mañana. */
	public function test_today_follows_the_store_not_the_server(): void {
		$now = (int) ( new \DateTimeImmutable( '2026-09-05 02:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp(); // 2026-09-04 22:00 en Santiago (UTC-4)

		self::assertSame( '2026-09-04', StoreTime::today_ymd( $now ) );
		self::assertSame(
			(int) ( new \DateTimeImmutable( '2026-09-04 00:00:00', new \DateTimeZone( 'America/Santiago' ) ) )->getTimestamp(),
			StoreTime::start_of_today( $now )
		);
	}

	/** @testdox Una promesa de ayer a las 23:59 (hora local) ya es pasada; la de hoy a las 00:00 no. */
	public function test_start_of_today_is_the_cut(): void {
		$now   = (int) ( new \DateTimeImmutable( '2026-09-04 15:00:00', new \DateTimeZone( 'America/Santiago' ) ) )->getTimestamp();
		$corte = StoreTime::start_of_today( $now );

		self::assertLessThan( $corte, (int) ( new \DateTimeImmutable( '2026-09-03 23:59:59', new \DateTimeZone( 'America/Santiago' ) ) )->getTimestamp() );
		self::assertGreaterThanOrEqual( $corte, (int) ( new \DateTimeImmutable( '2026-09-04 00:00:00', new \DateTimeZone( 'America/Santiago' ) ) )->getTimestamp() );
	}
}
