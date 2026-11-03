<?php
/**
 * La aritmética del descuento, ya fuera del renderizador.
 *
 * Vivía dentro de `LoopEnhancer` —952 líneas que pintan badges, precios y
 * botones—, así que la única forma de preguntarle a la tienda «¿cuánto se rebaja
 * este producto?» era renderizar una tarjeta, y la regla de negocio del umbral
 * (no gritar un −3% encima de una preventa) no se podía leer ni probar sin el
 * HTML alrededor (auditoría 2026-08-31).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\LoopEnhancer;

use Akibara\Business\Modules\LoopEnhancer\SaleDiscount;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SaleDiscountTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider porcentajes
	 */
	public function test_el_porcentaje_se_redondea_sobre_el_precio_regular( string $regular, string $sale, int $esperado ): void {
		self::assertSame( $esperado, SaleDiscount::pct( $this->producto( $regular, $sale ) ) );
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:int}>
	 */
	public static function porcentajes(): array {
		return array(
			'mitad de precio'      => array( '10000', '5000', 50 ),
			'redondeo hacia abajo' => array( '9990', '8990', 10 ),
			'sin oferta'           => array( '10000', '', 0 ),
			'oferta más cara'      => array( '10000', '12000', 0 ),
			'igual al regular'     => array( '10000', '10000', 0 ),
			'sin precio regular'   => array( '', '5000', 0 ),
		);
	}

	/**
	 * @testdox Una oferta standalone muestra su badge desde el 1 %.
	 */
	public function test_una_oferta_suelta_se_muestra_aunque_sea_chica(): void {
		self::assertSame( '−3%', SaleDiscount::label( $this->producto( '10000', '9700' ) ) );
	}

	/**
	 * Apilada sobre «Preventa», una rebaja trivial lee como contradicción: «casi
	 * sin rebaja» encima de un producto que todavía no salió abarata la marca.
	 *
	 * @testdox Apilada sobre preventa, una rebaja bajo el umbral se calla.
	 */
	public function test_apilada_sobre_preventa_una_rebaja_trivial_se_calla(): void {
		self::assertNull( SaleDiscount::label( $this->producto( '10000', '9700' ), true ) );
	}

	/**
	 * @testdox Apilada sobre preventa, una rebaja real sí se muestra.
	 */
	public function test_apilada_sobre_preventa_una_rebaja_real_se_muestra(): void {
		self::assertSame( '−20%', SaleDiscount::label( $this->producto( '10000', '8000' ), true ) );
	}

	/**
	 * @testdox Sin descuento computable no hay etiqueta.
	 */
	public function test_sin_descuento_no_hay_etiqueta(): void {
		self::assertNull( SaleDiscount::label( $this->producto( '10000', '' ) ) );
	}

	/**
	 * El renderizador ya no puede tener su propia aritmética.
	 */
	public function test_el_renderizador_delega_el_calculo(): void {
		$fuente = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/LoopEnhancer/LoopEnhancer.php'
		);

		self::assertStringContainsString( 'SaleDiscount::label(', $fuente );
		self::assertStringNotContainsString( 'get_regular_price()', $fuente );
		self::assertStringNotContainsString( 'akibara_loop_sale_badge_min_pct', $fuente );
	}

	private function producto( string $regular, string $sale ): \WC_Product {
		$producto                    = new \WC_Product( 'instock' );
		$producto->akb_regular_price = $regular;
		$producto->akb_sale_price    = $sale;

		return $producto;
	}
}
