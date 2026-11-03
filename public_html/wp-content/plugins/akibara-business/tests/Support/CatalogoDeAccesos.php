<?php
/**
 * Carga el mu-plugin del catálogo de accesos dentro de la suite.
 *
 * Los mu-plugins no están en el autoload del plugin —viven fuera de `src/`—, así
 * que una prueba que ejercite código que le pregunta al catálogo tiene que
 * cargarlo A PROPÓSITO. Hacerlo aquí, y no con un `require_once` suelto en cada
 * archivo, evita la peor clase de verde: `class_exists()` respondía que sí o que
 * no según qué prueba hubiera corrido antes en el mismo proceso.
 *
 * Se carga DESPUÉS de `Monkey\setUp()`: el archivo llama a `Screen::register()`
 * al final, y eso necesita `add_action()`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Support;

final class CatalogoDeAccesos {

	/**
	 * Deja `Akibara\ProviderAccess\*` disponible. Idempotente.
	 */
	public static function cargar(): void {
		if ( class_exists( '\Akibara\ProviderAccess\Access', false ) ) {
			return;
		}

		require_once dirname( __DIR__, 4 ) . '/mu-plugins/akibara-provider-access.php';
	}
}
