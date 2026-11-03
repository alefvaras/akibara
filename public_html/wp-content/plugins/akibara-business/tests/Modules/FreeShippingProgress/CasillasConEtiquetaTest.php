<?php
/**
 * Cada casilla del tab «Envío gratis» tiene nombre accesible.
 *
 * WooCommerce pinta el `desc` de un checkbox DENTRO de su <label>; sin `desc`
 * el label queda vacío y la casilla no tiene nombre para un lector de
 * pantalla. QA lo midió el 2026-09-12: cuatro casillas de este tab, las únicas
 * del panel sin etiqueta.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\FreeShippingProgress;

use Akibara\Business\Modules\FreeShippingProgress\FreeShippingProgress;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CasillasConEtiquetaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( '0' );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => (string) $n );
		Functions\when( 'wc_price' )->alias( static fn( $n ): string => '$' . $n );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Toda casilla del tab lleva `desc`, que es el texto del label que WooCommerce pinta.
	 */
	public function test_toda_casilla_lleva_desc(): void {
		$metodo = new ReflectionMethod( FreeShippingProgress::class, 'get_settings' );
		$metodo->setAccessible( true );
		$casillas = array();
		foreach ( $metodo->invoke( new FreeShippingProgress() ) as $campo ) {
			if ( 'checkbox' === ( $campo['type'] ?? '' ) ) {
				$casillas[ (string) $campo['id'] ] = trim( (string) ( $campo['desc'] ?? '' ) );
			}
		}

		self::assertGreaterThanOrEqual( 6, count( $casillas ), 'El tab tiene al menos seis casillas; si no se leen, la prueba no mide nada.' );
		foreach ( $casillas as $id => $desc ) {
			self::assertNotSame( '', $desc, "La casilla {$id} no tiene `desc`: su label queda vacío y no tiene nombre accesible." );
		}
	}
}
