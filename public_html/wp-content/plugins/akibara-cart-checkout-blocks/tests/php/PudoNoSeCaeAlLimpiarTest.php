<?php
/**
 * Limpiar la selección de punto de retiro no puede tumbar la petición.
 *
 * Hallazgo S-3b736e47: `invalidate_selection()` pasa por el guardián de
 * mutaciones, que LANZA cuando hay un checkout en curso o cuando el estado
 * sigue en disputa. Llamarlo suelto convertía un error explicable —«espera a
 * que termine de confirmarse»— en un 500 genérico en medio del pago.
 *
 * Cada llamada tiene que ir por `best_effort()` (registra y sigue) o dentro de
 * un `try` que traduzca el conflicto a un mensaje para el cliente.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use PHPUnit\Framework\TestCase;

final class PudoNoSeCaeAlLimpiarTest extends TestCase {

	/** @testdox Ninguna llamada a invalidate_selection() queda a la intemperie. */
	public function test_toda_llamada_esta_protegida(): void {
		$lineas = file( dirname( __DIR__, 2 ) . '/src/PudoIntegration.php', FILE_IGNORE_NEW_LINES );
		self::assertIsArray( $lineas );

		$desprotegidas = array();
		foreach ( $lineas as $n => $linea ) {
			if ( false === strpos( $linea, 'invalidate_selection' ) ) {
				continue;
			}
			// La declaración del método en el servicio no es una llamada.
			if ( false !== strpos( $linea, 'function invalidate_selection' ) ) {
				continue;
			}
			// `PudoLog::failure( 'invalidate_selection', ... )` es el registro, no la llamada.
			if ( false !== strpos( $linea, 'PudoLog::failure' ) ) {
				continue;
			}

			if ( false !== strpos( $linea, 'best_effort(' ) ) {
				continue;
			}

			// Si no va por best_effort, tiene que estar dentro de un try abierto
			// en las tres líneas anteriores.
			$contexto = implode( "\n", array_slice( $lineas, max( 0, $n - 3 ), 3 ) );
			if ( false !== strpos( $contexto, 'try {' ) ) {
				continue;
			}

			$desprotegidas[] = sprintf( '%d: %s', $n + 1, trim( $linea ) );
		}

		self::assertSame(
			array(),
			$desprotegidas,
			"Una limpieza que lanza en medio del pago sale como 500 genérico:\n" . implode( "\n", $desprotegidas )
		);
	}

	/** @testdox El conflicto de mutación se le explica al cliente con un 409, no con un 500. */
	public function test_el_conflicto_se_explica(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/PudoIntegration.php' );

		self::assertStringContainsString( 'is_mutation_conflict( $throwable )', $src );
		self::assertStringContainsString( '409', $src, 'El conflicto es esperable: merece su propio estado.' );
	}
}
