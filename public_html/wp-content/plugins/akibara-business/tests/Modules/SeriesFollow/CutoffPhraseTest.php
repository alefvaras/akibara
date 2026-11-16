<?php
/**
 * Frase de corte para correos de seguimiento de series.
 *
 * En Akibara todo el catálogo opera únicamente en stock inmediato (`instock`)
 * y agotado (`outofstock`), por lo que no existen frases de corte de tanda ni
 * reservas a pedido.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesFollow\CutoffPhrase;
use PHPUnit\Framework\TestCase;
use WC_Product;

final class CutoffPhraseTest extends TestCase {

	/**
	 * @testdox En el modelo actual sin preventas ni a pedido, for_product devuelve siempre cadena vacía.
	 */
	public function test_for_product_returns_empty_string(): void {
		$phrase  = new CutoffPhrase();
		$product = new class() extends WC_Product {
			public function get_id(): int {
				return 4242;
			}
		};

		self::assertSame( '', $phrase->for_product( $product ) );
	}
}
