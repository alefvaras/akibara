<?php
/**
 * Pruebas unitarias de la clase utilitaria centralizada Dates.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use Akibara\Rewards\Support\Dates;
use Brain\Monkey\Functions;

final class DatesTest extends RewardsTestCase {

	public function test_to_timestamp_handles_integers_strings_and_datetimes(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Santiago' ) );

		$ts = 1794265200;
		$this->assertSame( $ts, Dates::to_timestamp( $ts ) );
		$this->assertSame( $ts, Dates::to_timestamp( (string) $ts ) );

		$dt = new \DateTimeImmutable( '@1794265200' );
		$this->assertSame( $ts, Dates::to_timestamp( $dt ) );

		// String con zona horaria explícita UTC
		$str_utc  = '2026-11-09 20:00:00 UTC';
		$expected = strtotime( $str_utc );
		$this->assertSame( $expected, Dates::to_timestamp( $str_utc ) );

		// String MySQL sin zona (hora local del sitio): NO debe desplazarse a UTC
		$str_local = '2026-11-10 00:29:55';
		$dt_local  = new \DateTimeImmutable( $str_local, new \DateTimeZone( 'America/Santiago' ) );
		$this->assertSame( $dt_local->getTimestamp(), Dates::to_timestamp( $str_local ) );
	}

	public function test_format_delegates_to_wp_date_with_timezone(): void {
		$tz = new \DateTimeZone( 'America/Santiago' );
		Functions\when( 'wp_timezone' )->justReturn( $tz );
		Functions\when( 'wp_date' )->alias(
			static fn( string $format, int $ts, $zone = null ) => ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $zone ?? $tz )->format( $format )
		);

		$ts = ( new \DateTimeImmutable( '2026-11-09 15:30:00', $tz ) )->getTimestamp();
		$this->assertSame( '09/11/2026', Dates::format( $ts ) );
		$this->assertSame( '09/11/2026 15:30', Dates::admin_datetime( $ts ) );
		$this->assertSame( '9 Nov 2026', Dates::format_short( $ts ) );
		$this->assertSame( '9 Nov 2026', Dates::human( $ts ) );
		$this->assertSame( '9 de noviembre de 2026', Dates::format_human( $ts ) );
		$this->assertSame( '9 de noviembre de 2026', Dates::human_full( $ts ) );

		// Caso borde crítico: string MySQL de madrugada (00:29:55 local) no debe retroceder -3h al día anterior
		$midnight_local = '2026-11-10 00:29:55';
		$this->assertSame( '10/11/2026', Dates::format( $midnight_local ) );
		$this->assertSame( '10/11/2026 00:29', Dates::admin_datetime( $midnight_local ) );
		$this->assertSame( '10 Nov 2026', Dates::format_short( $midnight_local ) );
		$this->assertSame( '10 de noviembre de 2026', Dates::format_human( $midnight_local ) );
	}

	public function test_now_db_returns_mysql_datetime_string(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Santiago' ) );

		$now = Dates::now_db();
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now );
		$this->assertSame( $now, Dates::mysql_now() );
	}

	public function test_parse_or_null_returns_datetime_immutable_or_null(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Santiago' ) );

		$valid = Dates::parse_or_null( '2026-08-17 00:00:00' );
		$this->assertInstanceOf( \DateTimeImmutable::class, $valid );
		$this->assertSame( '2026-08-17 00:00:00', $valid->format( 'Y-m-d H:i:s' ) );

		// Con formato Y-m-d resetea la hora a 00:00:00 y soporta prefijo '!'
		$date_only = Dates::parse_or_null( '2026-11-09', 'Y-m-d' );
		$this->assertInstanceOf( \DateTimeImmutable::class, $date_only );
		$this->assertSame( '2026-11-09 00:00:00', $date_only->format( 'Y-m-d H:i:s' ) );

		$date_bang = Dates::parse_or_null( '2026-11-09', '!Y-m-d' );
		$this->assertInstanceOf( \DateTimeImmutable::class, $date_bang );
		$this->assertSame( '2026-11-09 00:00:00', $date_bang->format( 'Y-m-d H:i:s' ) );

		// Alias de compatibilidad
		$this->assertSame( '2026-08-17 00:00:00', Dates::parse( '2026-08-17 00:00:00' )->format( 'Y-m-d H:i:s' ) );

		$this->assertNull( Dates::parse_or_null( 'fecha-invalida' ) );
		$this->assertNull( Dates::parse_or_null( '2026-02-30 12:00:00' ) );
		$this->assertNull( Dates::parse_or_null( null ) );
		$this->assertNull( Dates::parse_or_null( '' ) );
	}

	public function test_diff_days_calculates_integer_difference(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Santiago' ) );

		$from = '2026-11-01 10:00:00';
		$to   = '2026-11-10 10:00:00';

		$this->assertSame( 9, Dates::diff_days( $from, $to ) );
		$this->assertSame( 9, Dates::diff_days( $to, $from ) );

		$dt1 = new \DateTimeImmutable( '2026-01-01 00:00:00' );
		$dt2 = new \DateTimeImmutable( '2026-01-31 00:00:00' );
		$this->assertSame( 30, Dates::diff_days( $dt1, $dt2 ) );
	}

	public function test_to_datetime_normalizes_to_site_timezone(): void {
		$tz = new \DateTimeZone( 'America/Santiago' );
		Functions\when( 'wp_timezone' )->justReturn( $tz );

		$dt = Dates::to_datetime( '2026-11-09 20:00:00' );
		$this->assertInstanceOf( \DateTimeImmutable::class, $dt );
		$this->assertSame( 'America/Santiago', $dt->getTimezone()->getName() );

		$dt_num = Dates::to_datetime( '1794265200' );
		$this->assertSame( 1794265200, $dt_num->getTimestamp() );
		$this->assertSame( 'America/Santiago', $dt_num->getTimezone()->getName() );
	}
}
