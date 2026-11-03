<?php
/**
 * Los días del registro de búsquedas se cuentan con el reloj de la tienda, y
 * los descuentos no se redondean hacia arriba.
 *
 * - `gmdate()` contaba en UTC: en Chile, lo buscado desde las 20:00 o 21:00 se
 *   anotaba al día siguiente (hallazgo S-6b2e35c0, 2026-09-19).
 * - `round()` metía un 9,5 % en el tramo «10 % o más» (hallazgo S-6ce737f5).
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\DocumentBuilder;
use Akibara\Search\QueryLogger;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

require_once dirname( __DIR__ ) . '/src/QueryLogger.php';

final class ElDiaDelRegistroEsElDeLaTiendaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox A las 22:30 de Santiago sigue siendo HOY, aunque en UTC ya sea mañana. */
	public function test_de_noche_en_chile_sigue_siendo_hoy(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $n, $d = false ) => 'timezone_string' === $n ? 'America/Santiago' : $d
		);

		// 2026-06-15 22:30 en Santiago (UTC-4 en invierno) = 2026-06-16 02:30 UTC.
		$instante = ( new \DateTimeImmutable( '2026-06-15 22:30:00', new \DateTimeZone( 'America/Santiago' ) ) )->getTimestamp();
		self::assertSame( '2026-06-16', gmdate( 'Y-m-d', $instante ), 'Precondición: en UTC ya es el 16.' );
		self::assertSame( '2026-06-15', QueryLogger::day( 0, $instante ), 'Para la tienda sigue siendo el 15.' );
		self::assertSame( '2026-06-14', QueryLogger::day( 1, $instante ) );
	}

	/** @testdox Sin zona configurada cae al desfase numérico, y sin nada, a UTC. */
	public function test_sin_zona_usa_el_desfase_y_luego_utc(): void {
		$instante = gmmktime( 1, 0, 0, 6, 16, 2026 );
		Functions\when( 'get_option' )->alias( static fn( string $n, $d = false ) => 'gmt_offset' === $n ? -4 : $d );
		self::assertSame( '2026-06-15', QueryLogger::day( 0, $instante ) );

		Functions\when( 'get_option' )->alias( static fn( string $n, $d = false ) => $d );
		self::assertSame( '2026-06-16', QueryLogger::day( 0, $instante ) );
	}

	/** @testdox Ningún lector del registro vuelve a contar los días en UTC por su cuenta. */
	public function test_nadie_cuenta_dias_del_registro_por_su_cuenta(): void {
		foreach ( array( 'PopularQueries', 'DemandGaps', 'SearchAdminSection', 'Reconciler', 'QueryLogger' ) as $clase ) {
			$fuente = (string) file_get_contents( dirname( __DIR__ ) . '/src/' . $clase . '.php' );
			self::assertStringNotContainsString( "gmdate( 'Y-m-d'", $fuente, $clase . ' tiene que pedirle el día a QueryLogger::day().' );
		}
	}

	/** @testdox Un 9,5 % no entra en «10 % o más»; un 10 % exacto sí. */
	public function test_el_descuento_se_redondea_hacia_abajo(): void {
		require_once dirname( __DIR__ ) . '/src/DocumentBuilder.php';

		$tiers = new ReflectionMethod( DocumentBuilder::class, 'oferta_tiers' );
		$tiers->setAccessible( true );
		$builder = ( new ReflectionClass( DocumentBuilder::class ) )->newInstanceWithoutConstructor();

		self::assertNotContains( 10, $tiers->invoke( $builder, 9050.0, 10000.0 ), 'Un 9,5 %.' );
		self::assertContains( 10, $tiers->invoke( $builder, 9000.0, 10000.0 ), 'Un 10 % exacto.' );
		self::assertContains( 10, $tiers->invoke( $builder, 12591.0, 13990.0 ), 'Un 10 % real de catálogo ($13.990 → $12.591).' );
		self::assertNotContains( 20, $tiers->invoke( $builder, 8050.0, 10000.0 ), 'Un 19,5 % no es «20 % o más».' );
	}
}
