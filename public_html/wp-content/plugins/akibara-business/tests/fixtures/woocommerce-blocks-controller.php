<?php
/**
 * Doble del controlador de WooCommerce Blocks que añade `<input name="redirect">`
 * al final del formulario de acceso.
 *
 * Vive en un fichero aparte porque lo que se comprueba es su NOMBRE DE CLASE: el
 * módulo sólo retira el callback de `Automattic\WooCommerce\Blocks\*`, para no
 * llevarse por delante el de otro plugin que se llame igual.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Blocks;

if ( ! class_exists( BlockTypesController::class ) ) {
	/** Sólo el método que nos interesa. */
	class BlockTypesController {

		public function redirect_to_field(): void {
		}
	}
}
