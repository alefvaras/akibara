<?php
/**
 * Candado del aviso «¿No estás en Santiago?»: qué texto sale y cuándo se apaga.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use Akibara\CartCheckoutBlocks\Assets;
use PHPUnit\Framework\TestCase;

final class ShippingHintCopyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['akibara_test_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		parent::tearDown();
	}

	/**
	 * @testdox Sin nada guardado, el carrito dice exactamente lo que decía antes
	 */
	public function test_the_default_is_the_literal_the_cart_had(): void {
		self::assertSame(
			'¿Prefieres despacho a domicilio? Se calcula en el siguiente paso al ingresar tu comuna.',
			Assets::shipping_hint_copy()
		);
	}

	/**
	 * @testdox El texto guardado en Akibara → Carrito es el que se sirve
	 */
	public function test_the_saved_copy_wins(): void {
		$GLOBALS['akibara_test_options']['akibara_blocks_shipping_hint_text'] = '¿Vives fuera de la RM? Escribe tu dirección.';

		self::assertSame( '¿Vives fuera de la RM? Escribe tu dirección.', Assets::shipping_hint_copy() );
	}

	/**
	 * @testdox Vaciar el campo apaga el aviso en vez de reponer el de fábrica
	 */
	public function test_a_saved_blank_switches_the_hint_off(): void {
		$GLOBALS['akibara_test_options']['akibara_blocks_shipping_hint_text'] = '   ';

		// El bundle ya trata la cadena vacía como «no pintar» (contrato fijado en
		// cart-checkout-blocks.test.js); acá se fija que el servidor la deje
		// llegar vacía en vez de rellenarla con el default.
		self::assertSame( '', Assets::shipping_hint_copy() );
	}
}
