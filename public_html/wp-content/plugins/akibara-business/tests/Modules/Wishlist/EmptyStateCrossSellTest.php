<?php
/**
 * Candado: el bloque «Más vendidos» tiene un solo dueño.
 *
 * El theme lo extrajo a `template-parts/empty/cross-sell.php` justamente para
 * matar la triplicación (404, carrito vacío, sin resultados). El estado vacío de
 * Favoritos era la CUARTA copia, escrita a mano en PHP, y ya había drifteado:
 * su `<section>` no llevaba la clase `woocommerce` que sí ponen las otras tres.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Wishlist;

use PHPUnit\Framework\TestCase;

final class EmptyStateCrossSellTest extends TestCase {

	private function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/Wishlist/Render.php' );
	}

	/**
	 * @testdox El estado vacío consume el template-part en vez de repetir el marcado.
	 */
	public function test_it_consumes_the_shared_template_part(): void {
		$src = $this->source();

		self::assertStringContainsString( "get_template_part(\n\t\t\t\t\t'template-parts/empty/cross-sell'", $src );
		self::assertStringContainsString( "locate_template( 'template-parts/empty/cross-sell.php' )", $src );

		self::assertStringNotContainsString(
			'akb-empty__cross-sell-grid',
			$src,
			'La grilla es marcado del theme: repetirla acá es lo que produjo la deriva de clases.'
		);
		self::assertStringNotContainsString( 'akibara_get_popular_cross_sell_ids', $src );
		self::assertStringNotContainsString(
			'akb-wl-empty-popular-title" class=',
			$src,
			'El encabezado lo imprime el template-part; acá sólo se le pasa el id.'
		);
	}

	/**
	 * @testdox El template-part que se consume acepta los argumentos que se le pasan.
	 */
	public function test_the_template_part_honours_the_arguments(): void {
		$parte = dirname( __DIR__, 5 ) . '/themes/akibara-gp/template-parts/empty/cross-sell.php';
		if ( ! is_readable( $parte ) ) {
			self::markTestSkipped( 'El árbol del theme no está disponible en esta corrida.' );
		}

		$fuente = (string) file_get_contents( $parte );
		foreach ( array( 'heading_id', 'heading_tag', 'heading_text' ) as $arg ) {
			self::assertStringContainsString(
				"\$args['" . $arg . "']",
				$fuente,
				'Si el template-part deja de leer este argumento, el encabezado del estado vacío cambia en silencio.'
			);
		}
	}
}
