<?php
/**
 * Candado del aviso «¿No estás en Santiago?» en el lado del panel.
 *
 * El texto lo pinta `akibara-cart-checkout-blocks`, que no tiene pantalla
 * propia; el campo vive en Akibara → Carrito y ambos plugins se encuentran por
 * el NOMBRE de la option. Si ese nombre cambiara acá, el carrito seguiría
 * sirviendo el default en silencio y nadie vería el guardado.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use Akibara\Business\Modules\NativeCart\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class BlocksShippingHintSettingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $value ): string => strip_tags( $value ) );
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( string $value ): string => preg_replace( '/\s+/', ' ', strip_tags( $value ) ) ?? ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox El aviso se edita en el tab Carrito con el literal que ya servía
	 */
	public function test_the_hint_is_editable_with_the_current_literal(): void {
		$campo = null;
		foreach ( ( new Settings() )->get_settings() as $field ) {
			if ( is_array( $field ) && ( $field['id'] ?? '' ) === Settings::OPTION_BLOCKS_SHIPPING_HINT ) {
				$campo = $field;
			}
		}

		self::assertIsArray( $campo, 'El aviso del carrito de bloques desapareció de la pantalla.' );
		self::assertSame( 'akibara_blocks_shipping_hint_text', Settings::OPTION_BLOCKS_SHIPPING_HINT );
		self::assertSame(
			'¿Prefieres despacho a domicilio? Se calcula en el siguiente paso al ingresar tu comuna.',
			$campo['default']
		);
	}

	/**
	 * @testdox Guardarlo vacío lo APAGA: el sanitizador no repone el default
	 */
	public function test_saving_it_blank_switches_the_hint_off(): void {
		$sanitizado = ( new Settings() )->sanitize_admin_option(
			'lo que sea',
			array( 'id' => Settings::OPTION_BLOCKS_SHIPPING_HINT ),
			'   '
		);

		self::assertSame( '', $sanitizado );
	}

	/**
	 * @testdox Un aviso reescrito se guarda limpio de etiquetas
	 */
	public function test_a_rewritten_hint_is_stored_sanitized(): void {
		$sanitizado = ( new Settings() )->sanitize_admin_option(
			'',
			array( 'id' => Settings::OPTION_BLOCKS_SHIPPING_HINT ),
			' ¿Vives fuera de la RM? <script>alert(1)</script>Escribe tu dirección. '
		);

		self::assertSame( '¿Vives fuera de la RM? alert(1)Escribe tu dirección.', $sanitizado );
	}
}
