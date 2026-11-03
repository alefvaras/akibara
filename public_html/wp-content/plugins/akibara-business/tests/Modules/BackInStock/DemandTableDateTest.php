<?php
/**
 * La columna «Última alta» de la tabla de demanda de la lista de espera.
 *
 * `SubscriberRepository` guarda con `gmdate()`, o sea UTC, y la tabla imprimía
 * ese literal rotulado «(UTC)». No mentía, pero en Chile (UTC−4 / −3) obligaba
 * a restar horas de cabeza y, para las altas de madrugada, cambiaba el DÍA: de
 * las 11 filas que hay en producción el 2026-08-23, 2 se firmaron entre las
 * 00:00 y las 04:00 UTC. La más reciente, `2026-08-22 03:00:52` UTC, ocurrió el
 * 21 a las 23:00 en America/Santiago.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BackInStock;

use Akibara\Business\Modules\BackInStock\BackInStock;
use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DemandTableDateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// `wp_date()` de verdad formatea en la zona del sitio; se replica aquí
		// con America/Santiago para que la prueba fije la CONVERSIÓN, no el
		// literal que devuelva WordPress.
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, int $timestamp ): string {
				return ( new DateTimeImmutable( '@' . $timestamp ) )
					->setTimezone( new DateTimeZone( 'America/Santiago' ) )
					->format( $format );
			}
		);
		Functions\when( 'human_time_diff' )->justReturn( '2 días' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function formatear( string $utc ): string {
		$method = new ReflectionMethod( BackInStock::class, 'format_signup' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $utc );
	}

	public function test_convierte_la_madrugada_utc_al_dia_anterior_del_sitio(): void {
		// El caso real de producción: la fila más reciente de wp_akb_bis_subscribers.
		self::assertSame( '21/08/2026 23:00 (hace 2 días)', $this->formatear( '2026-08-22 03:00:52' ) );
	}

	public function test_no_imprime_nunca_el_literal_almacenado(): void {
		$salida = $this->formatear( '2026-08-18 03:21:49' );

		self::assertStringNotContainsString( '2026-08-18 03:21:49', $salida );
		self::assertStringContainsString( '17/08/2026', $salida );
	}

	public function test_una_fecha_vacia_o_nula_no_pinta_basura(): void {
		self::assertSame( '—', $this->formatear( '' ) );
		self::assertSame( '—', $this->formatear( '0000-00-00 00:00:00' ) );
	}
}
