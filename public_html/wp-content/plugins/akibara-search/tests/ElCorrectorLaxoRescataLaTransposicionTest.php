<?php
/**
 * R9-corrector-asimetrico (2026-09-20): la página de resultados (política laxa)
 * medía con `levenshtein()`, que cobra 2 por cambiar dos letras adyacentes, y el
 * desplegable (estricta) con OSA, que cobra 1. «balck clover» (qlog 2026-08-30,
 * fuente `search`) se rescataba en el desplegable y la página servía cero con
 * 37 tomos de Black Clover en catálogo. Desde 0.1.73 las dos políticas miden
 * igual: la laxa no puede ser menos capaz que la estricta.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\Fuzzy;
use PHPUnit\Framework\TestCase;

final class ElCorrectorLaxoRescataLaTransposicionTest extends TestCase {

	private const VOCABULARIO = array( 'black', 'clover', 'blue', 'lock', 'manga', 'naruto', 'berserk', 'chainsaw', 'man' );

	/**
	 * @testdox La rama laxa corrige «balck» → «black» igual que la estricta.
	 */
	public function test_la_transposicion_adyacente_cuesta_uno_en_las_dos_politicas(): void {
		$laxa     = Fuzzy::correct( array( 'balck', 'clover' ), self::VOCABULARIO, false );
		$estricta = Fuzzy::correct( array( 'balck', 'clover' ), self::VOCABULARIO, true );

		self::assertTrue( $laxa['changed'] );
		self::assertSame( array( 'black', 'clover' ), $laxa['tokens'] );
		self::assertSame( $laxa['tokens'], $estricta['tokens'], 'Mismo texto, misma respuesta en el desplegable y en la página.' );
	}

	/**
	 * Sólo cuentan los tokens de ≤5 letras (umbral 1): con «nartuo» (6 letras,
	 * umbral 2) `levenshtein()` ya llegaba y no fija la regresión (revisión R9, 1.6).
	 *
	 * @testdox Los typos cortos de letras cambiadas de lugar («amnga», «lcok») también se rescatan en la página.
	 */
	public function test_otras_transposiciones_cortas(): void {
		self::assertSame( array( 'manga' ), Fuzzy::correct( array( 'amnga' ), self::VOCABULARIO, false )['tokens'] );
		self::assertSame( array( 'blue', 'lock' ), Fuzzy::correct( array( 'blue', 'lcok' ), self::VOCABULARIO, false )['tokens'] );
	}

	/**
	 * El umbral no se relajó: sigue siendo 1 error hasta 5 caracteres y 2 más allá.
	 *
	 * @testdox El umbral por longitud se conserva.
	 */
	public function test_el_umbral_por_longitud_no_cambio(): void {
		self::assertFalse( Fuzzy::correct( array( 'bxxck' ), self::VOCABULARIO, false )['changed'], 'Dos errores en 5 letras siguen fuera del umbral.' );
		self::assertSame( array( 'chainsaw' ), Fuzzy::correct( array( 'chaisaw' ), self::VOCABULARIO, false )['tokens'], 'Un error en 7 letras entra.' );
		self::assertSame( array( 'berserk' ), Fuzzy::correct( array( 'bersek' ), self::VOCABULARIO, false )['tokens'] );
	}
}
