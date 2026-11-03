<?php
/**
 * Las hojas de business sólo usan tokens `--aki-*` que el tema define.
 *
 * El tema vigila sus propias hojas (tests/sistema-de-diseno.test.js, DS-6), pero
 * ese candado no recorre las de business. QA integral 2026-09-24 (BIZ-QA-5): los
 * dos anillos de foco nuevos de la landing de serie usaban `var(--aki-focus,
 * #ffd400)`, un token que no existe en ningún lado, así que siempre pintaban el
 * respaldo: otro amarillo que el #FFD700 del acento de la casa. El lote T6 había
 * limpiado justo ese token fantasma en el tema.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

final class CssTokensExistenEnElTemaTest extends TestCase {

	/**
	 * @testdox Every --aki-* token a business stylesheet reads is defined by the theme or by that same sheet.
	 */
	public function test_no_business_sheet_reads_a_phantom_token(): void {
		$plugin = dirname( __DIR__ );
		$tema   = dirname( $plugin, 2 ) . '/themes/akibara-gp/assets/css/tokens.css';
		$tokens = (string) file_get_contents( $tema );
		self::assertNotSame( '', $tokens, 'Fixture: se leen los tokens del tema.' );

		preg_match_all( '/(--aki-[\w-]+)\s*:/', $tokens, $m );
		$definidos = array_flip( $m[1] );
		self::assertArrayHasKey( '--aki-focus-outline', $definidos, 'Fixture: el anillo de la casa es un token del tema.' );

		$fantasmas = array();
		foreach ( (array) glob( $plugin . '/assets/css/*.css' ) as $hoja ) {
			$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( (string) $hoja ) );
			preg_match_all( '/(--aki-[\w-]+)\s*:/', $css, $locales );
			preg_match_all( '/var\((--aki-[\w-]+)/', $css, $usados );
			foreach ( array_unique( $usados[1] ) as $token ) {
				if ( ! isset( $definidos[ $token ] ) && ! in_array( $token, $locales[1], true ) ) {
					$fantasmas[] = basename( (string) $hoja ) . ': ' . $token;
				}
			}
		}

		self::assertSame( array(), $fantasmas );
	}

	/**
	 * @testdox The focus rings of the series landing and the route intros use the house ring.
	 */
	public function test_the_focus_rings_use_the_house_tokens(): void {
		$raiz = dirname( __DIR__ ) . '/assets/css/';
		foreach ( array(
			'series-facts.css'       => array( '.akb-series-summary__more:focus-visible', '.akb-series-latest:focus-visible' ),
			'estimated-delivery.css' => array( '.akb-disponibles-intro__sibling a:focus-visible' ),
		) as $hoja => $selectores ) {
			$css = (string) file_get_contents( $raiz . $hoja );
			foreach ( $selectores as $selector ) {
				$inicio = strpos( $css, $selector . ' {' );
				self::assertIsInt( $inicio, $selector );
				$regla = substr( $css, (int) $inicio, (int) strpos( $css, '}', (int) $inicio ) - (int) $inicio );
				self::assertStringContainsString( 'outline: var(--aki-focus-outline, 3px solid #FFD700);', $regla, $selector );
				self::assertStringContainsString( 'outline-offset: var(--aki-focus-offset, 2px);', $regla, $selector );
			}
		}
	}
}
