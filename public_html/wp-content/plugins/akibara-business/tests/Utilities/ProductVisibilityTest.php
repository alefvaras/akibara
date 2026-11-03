<?php
/**
 * La regla única de «este producto entró o salió del catálogo público».
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Utilities;

use Akibara\Business\Utilities\ProductVisibility;
use PHPUnit\Framework\TestCase;

final class ProductVisibilityTest extends TestCase {

	/**
	 * @testdox Sólo cuenta cuando exactamente uno de los dos estados es «publish», y sólo para productos.
	 * @dataProvider transiciones
	 *
	 * @param mixed $post Post de la transición.
	 */
	public function test_changed( string $nuevo, string $anterior, $post, bool $esperado ): void {
		self::assertSame( $esperado, ProductVisibility::changed( $nuevo, $anterior, $post ) );
	}

	/** @return array<string, array{0:string,1:string,2:mixed,3:bool}> */
	public static function transiciones(): array {
		$producto = (object) array( 'post_type' => 'product' );

		return array(
			'se publica'                    => array( 'publish', 'draft', $producto, true ),
			'a la papelera'                 => array( 'trash', 'publish', $producto, true ),
			'a privado'                     => array( 'private', 'publish', $producto, true ),
			'restaurado como borrador'      => array( 'draft', 'trash', $producto, false ),
			'un «Actualizar»'               => array( 'publish', 'publish', $producto, false ),
			'borrador que sigue en borrador' => array( 'draft', 'draft', $producto, false ),
			'una página'                    => array( 'trash', 'publish', (object) array( 'post_type' => 'page' ), false ),
			'sin post'                      => array( 'trash', 'publish', null, false ),
			'post sin tipo'                 => array( 'trash', 'publish', (object) array(), false ),
		);
	}
}
