<?php
/**
 * Candado: un producto AGOTADO no puede comportarse como stock ilimitado.
 *
 * `WC_Product::get_max_purchase_quantity()` devuelve TRES cosas distintas:
 *   -1  → sin límite (no gestiona stock, o admite reservas)
 *    N  → tope real de unidades
 *    0  → NO queda nada comprable
 *
 * El guard del drawer era `if ( $max > 0 )`, así que el 0 caía en el mismo saco
 * que el -1 y el clamp se saltaba justo en el caso que más lo necesita.
 * Reproducido por HTTP el 2026-08-23: con el producto en 0, subir la cantidad a
 * 9 daba un carrito de $99.900 y la barra anunciaba «¡Listo! Tu pedido tiene
 * envío gratis» — sobre inventario inexistente. El bloqueo real aparecía recién
 * en /carrito/, con la Store API respondiendo «no tiene existencias».
 * En producción hay 98 productos publicados en ese estado.
 *
 * Este archivo fija la distinción en las DOS superficies donde se decide:
 * el clamp del servidor y el botón «+» del drawer.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use PHPUnit\Framework\TestCase;

/**
 * @since 1.108.16
 */
final class OutOfStockQuantityTest extends TestCase {

	private function backendSource(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/NativeCart/NativeCart.php' );
	}

	private function frontendSource(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/native-cart.js' );
	}

	/**
	 * @testdox El servidor distingue el 0 (nada comprable) del -1 (sin límite).
	 */
	public function test_backend_separates_zero_from_unlimited(): void {
		$src = $this->backendSource();

		self::assertMatchesRegularExpression(
			'/if \(\s*0 === \$max\s*\)/',
			$src,
			'El caso «no queda nada comprable» necesita su propia rama: con `$max > 0` a secas se trataba como ilimitado.'
		);
		self::assertMatchesRegularExpression(
			'/0 === \$max[\s\S]{0,400}?remove_cart_item\(\s*\$key\s*\)/',
			$src,
			'Una línea sin unidades comprables se retira: dejarla garantiza un bloqueo duro en el checkout.'
		);
	}

	/**
	 * @testdox Retirar la línea le explica al cliente por qué desapareció.
	 */
	public function test_removal_tells_the_customer_why(): void {
		$src = $this->backendSource();

		self::assertStringContainsString(
			'se agotó y lo quitamos de tu carrito',
			$src,
			'Sin aviso, al cliente se le esfuma un producto del carrito sin explicación.'
		);
		self::assertMatchesRegularExpression(
			'/public function ajax_get_cart\(\s*string \$notice = \x27\x27\s*\)/',
			$src,
			'El aviso viaja por el payload del drawer, no por un canal aparte.'
		);
		self::assertMatchesRegularExpression(
			"/'notice'\s*=>\s*\\\$notice/",
			$src,
			'El payload tiene que exponer el aviso para que el JS lo anuncie.'
		);
	}

	/**
	 * @testdox El botón «+» del drawer se deshabilita con un producto agotado.
	 */
	public function test_plus_button_is_disabled_when_nothing_is_purchasable(): void {
		$js = $this->frontendSource();

		self::assertMatchesRegularExpression(
			'/var atMax = \(max === 0\) \|\| \(max > 0 && current >= max\)/',
			$js,
			'`max > 0 && ...` dejaba el «+» activo con max === 0, o sea en el producto agotado.'
		);
		self::assertMatchesRegularExpression(
			'/if \(data\.notice\)[\s\S]{0,120}?announce\(data\.notice\)/',
			$js,
			'El aviso del servidor tiene que llegar a la región viva del drawer.'
		);
	}

	/**
	 * @testdox La semántica de los tres valores queda escrita donde se decide.
	 *
	 * No es decoración: el defecto nació de leer `get_max_purchase_quantity()`
	 * como si devolviera «un tope» y nada más.
	 */
	public function test_the_three_valued_contract_is_documented(): void {
		foreach ( array( $this->backendSource(), $this->frontendSource() ) as $src ) {
			self::assertMatchesRegularExpression(
				'/-1/',
				$src,
				'El comentario debe nombrar el -1 como «sin límite» para que nadie vuelva a colapsarlo con el 0.'
			);
		}
		self::assertStringContainsString( 'TRES', $this->backendSource() );
	}
}
