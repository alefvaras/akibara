<?php
/**
 * La clave de caché de la grilla de especificaciones lleva TODAS las promesas
 * que la grilla pinta.
 *
 * Hasta 2026-09-22 llevaba sólo dos de tres: la de «a pedido»
 * (`akb_copy_promise_pedido_transit`) entró a la grilla en 1.109.0 pero no a la
 * clave, así que editarla en Akibara → Textos tardaba hasta doce horas (TTL del
 * object cache persistente) en verse en las fichas a pedido ya cacheadas.
 *
 * Candado de fuente: la grilla es un método privado que arma HTML con una
 * docena de funciones de WordPress; lo que se fija aquí es la relación entre
 * dos listas del mismo método, y se comparan SIN comentarios.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\PdpEnhancer;

use PHPUnit\Framework\TestCase;

final class PdpSpecsCacheKeyTest extends TestCase {

	/** Cuerpo de `render_specs_grid()` sin comentarios. */
	private function grid_source(): string {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/PdpEnhancer/PdpEnhancer.php' );
		$code   = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}

		$start = strpos( $code, 'function render_specs_grid' );
		self::assertNotFalse( $start, 'render_specs_grid() tiene que existir.' );
		$end = strpos( $code, 'function render_stacked_tabs_js', (int) $start );
		self::assertNotFalse( $end );

		return substr( $code, (int) $start, (int) $end - (int) $start );
	}

	/**
	 * @testdox Cada promesa que pinta la grilla entra en el hash de la clave de caché.
	 */
	public function test_cada_promesa_pintada_entra_en_la_clave(): void {
		$grid = $this->grid_source();

		self::assertSame( 1, preg_match( '/\$promises\s*=\s*md5\((.*?)\);/s', $grid, $hash ), 'La clave tiene que hashear las promesas.' );
		preg_match_all( "/'(akb_copy_promise_[a-z_]+)'/", $grid, $leidas );
		preg_match_all( "/'(akb_copy_promise_[a-z_]+)'/", $hash[1], $en_la_clave );

		$pintadas = array_values( array_unique( $leidas[1] ) );
		sort( $pintadas );
		$hasheadas = array_values( array_unique( $en_la_clave[1] ) );
		sort( $hasheadas );

		// Dos desde DS-9 (lote de integración): el despacho del tomo disponible
		// salió de la grilla —ya lo dice la línea junto al botón— y, con él, de
		// la clave: una promesa hasheada que no se pinta invalidaría sin motivo.
		self::assertSame(
			array( 'akb_copy_promise_pedido_transit', 'akb_copy_promise_preventa_transit' ),
			$pintadas,
			'La grilla pinta las promesas de preventa y de a pedido.'
		);
		self::assertSame( $pintadas, $hasheadas, 'Una promesa pintada fuera de la clave tarda hasta 12 h en verse tras editarla.' );
	}
}
