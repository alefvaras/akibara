<?php
/**
 * Un rebuild sano no sale «incompleto» porque alguien publicó una ficha mientras
 * corría.
 *
 * El comando comparaba los docs indexados contra un conteo tomado DESPUÉS de
 * terminar, con una «tolerancia» de 1.0: una sola ficha publicada durante los
 * ~25 s de la ventana daba exit != 0, y el runbook del cutover confía en ese
 * código (hallazgo S-09cc0b65, 2026-09-19). Esa ficha no es un agujero del
 * rebuild: su oyente anotó deuda viva y el bombeo la indexa aparte.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\Cli;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/Cli.php';

final class UnRebuildSanoNoSaleIncompletoTest extends TestCase {

	/** @testdox Indexó todo lo que tenía que indexar y entre medio se publicó una ficha: ÉXITO. */
	public function test_una_ficha_publicada_durante_la_ventana_no_es_un_fallo(): void {
		$resultado = array( 'docs' => 1632, 'expected' => 1632, 'error' => '' );

		self::assertNull( Cli::rebuild_failure_message( $resultado, 1633 ), 'El conteo posterior (1.633) incluye una ficha que el rebuild nunca prometió.' );
	}

	/** @testdox Un rebuild que indexó menos de lo que se comprometió SÍ falla, con los dos números. */
	public function test_un_rebuild_con_agujeros_sigue_fallando(): void {
		$mensaje = Cli::rebuild_failure_message( array( 'docs' => 900, 'expected' => 1503, 'error' => '' ), 1503 );

		self::assertIsString( $mensaje );
		self::assertStringContainsString( '900', (string) $mensaje );
		self::assertStringContainsString( '1503', (string) $mensaje );
	}

	/** @testdox Sin `expected` en el resultado (recibo de una versión anterior) se usa el conteo posterior. */
	public function test_sin_expected_cae_al_conteo_posterior(): void {
		self::assertIsString( Cli::rebuild_failure_message( array( 'docs' => 10, 'error' => '' ), 20 ) );
		self::assertNull( Cli::rebuild_failure_message( array( 'docs' => 20, 'error' => '' ), 20 ) );
	}

	/** @testdox Cero docs con catálogo, o un error del motor, siguen siendo fallo. */
	public function test_los_fallos_de_siempre_siguen_siendo_fallos(): void {
		self::assertIsString( Cli::rebuild_failure_message( array( 'docs' => 0, 'expected' => 0, 'error' => '' ), 1500 ) );
		self::assertIsString( Cli::rebuild_failure_message( array( 'docs' => 1500, 'expected' => 1500, 'error' => 'swap_failed' ), 1500 ) );
	}
}
