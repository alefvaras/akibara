<?php
/**
 * Los @keyframes del plugin no se pisan entre hojas.
 *
 * Los nombres de `@keyframes` no tienen scope: si dos hojas cargadas en la
 * misma página declaran el mismo nombre, gana la última para TODO el documento.
 * Pasó dos veces: el «Cargando…» del cajón usaba `pulse` y lo pisaba otro
 * plugin, y el 2026-09-23 el badge de stock de la ficha (pdp-enhancer.css)
 * heredaba el escalado del armador de packs (series-bundles.css) porque las
 * dos declaraban `akb-pulse`, en las fichas de las 134 series con pack.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

final class CssKeyframesSonUnicosTest extends TestCase {

	/**
	 * @testdox Cada nombre de @keyframes se declara en una sola hoja del plugin.
	 */
	public function test_every_keyframes_name_is_declared_once(): void {
		$dueños = array();
		foreach ( glob( dirname( __DIR__ ) . '/assets/css/*.css' ) ?: array() as $hoja ) {
			$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $hoja ) );
			preg_match_all( '/@keyframes\s+([A-Za-z0-9_-]+)/', $css, $m );
			foreach ( $m[1] as $nombre ) {
				$dueños[ $nombre ][] = basename( $hoja );
			}
		}

		self::assertNotEmpty( $dueños, 'No se encontró ningún @keyframes: el patrón dejó de leer las hojas.' );
		foreach ( $dueños as $nombre => $hojas ) {
			self::assertCount( 1, $hojas, sprintf( '«%s» se declara en %s: la última hoja cargada pisa a las demás.', $nombre, implode( ', ', $hojas ) ) );
		}
	}
}
