<?php
/**
 * Los comentarios de Plugin.php no citan números de línea propios (S-e0775800).
 *
 * `Plugin.php` es el mapa de arranque del plugin y sus comentarios son la
 * única documentación del orden de registro. Hasta el 2026-09-19 cuatro
 * comentarios apuntaban con un `(L1234)` literal a "dónde se construye X",
 * pero el fichero se edita constantemente y las líneas se corren: los cuatro
 * punteros ya señalaban a bloques de código distintos del que describían.
 *
 * El arreglo de raíz no es corregir los números — eso vuelve a pudrirse en el
 * próximo cambio — sino dejar de citar líneas propias por número y describir
 * el sitio por lo que hace (`estimated_delivery`, `$customer_collection`,
 * etc.), que no se mueve aunque el fichero crezca. Este candado impide que
 * vuelva a colarse un puntero de línea fija propio.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

final class PluginStaleLinePointersTest extends TestCase {

	/**
	 * @testdox Plugin.php no cita sus propias líneas con un puntero (Lnúmero) que se pudre con el próximo cambio.
	 */
	public function test_no_self_referential_line_pointers(): void {
		$codigo = (string) file_get_contents( dirname( __DIR__ ) . '/src/Plugin.php' );

		$infractores = array();
		foreach ( explode( "\n", $codigo ) as $indice => $linea ) {
			if ( 1 !== preg_match( '/\(L\d+\b(?!\s+de\s+WP)/', $linea ) ) {
				continue;
			}
			$infractores[] = ( $indice + 1 ) . ': ' . trim( $linea );
		}

		self::assertSame(
			array(),
			$infractores,
			'Un puntero "(L1234)" a una línea de este mismo fichero se desactualiza con el próximo cambio '
				. '(S-e0775800). Describe el sitio por lo que hace, no por su número de línea.'
		);
	}

	/**
	 * Control: el detector tiene que cazar el patrón que arregló este hallazgo,
	 * y no la cita legítima a una línea de WordPress core (fuera de este árbol,
	 * versionada explícitamente con «de WP X.Y»).
	 *
	 * @testdox El detector reconoce un puntero de línea propio y perdona la cita a WP core.
	 */
	public function test_the_detector_actually_detects(): void {
		self::assertSame( 1, preg_match( '/\(L\d+\b(?!\s+de\s+WP)/', '// ya construido en (L913), así que...' ) );
		self::assertSame( 0, preg_match( '/\(L\d+\b(?!\s+de\s+WP)/', '// en wp-settings.php (L779 de WP 6.8) en TODA petición' ) );
	}
}
