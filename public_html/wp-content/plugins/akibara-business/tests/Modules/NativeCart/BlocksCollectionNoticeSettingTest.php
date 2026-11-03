<?php
/**
 * Candado del aviso de colección del checkout de bloques, en el lado del panel.
 *
 * Mismo contrato que el aviso «¿No estás en Santiago?»: el texto lo pinta
 * `akibara-cart-checkout-blocks`, que lo lee por `get_option()` con este
 * NOMBRE; el campo vive en Akibara → Carrito; y el vacío se GUARDA (apagado),
 * no se repone con el default.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use Akibara\Business\Modules\NativeCart\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class BlocksCollectionNoticeSettingTest extends TestCase {

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

	/** @return array<string, mixed>|null */
	private function campo(): ?array {
		foreach ( ( new Settings() )->get_settings() as $field ) {
			if ( is_array( $field ) && ( $field['id'] ?? '' ) === Settings::OPTION_BLOCKS_COLLECTION_NOTICE ) {
				return $field;
			}
		}

		return null;
	}

	/** @testdox El aviso se edita en el tab Carrito con el nombre de option que lee el plugin de bloques */
	public function test_the_notice_is_editable_under_the_shared_option_name(): void {
		$campo = $this->campo();

		self::assertIsArray( $campo, 'El aviso de colección desapareció de la pantalla: quedaría una option invisible.' );
		self::assertSame( 'akibara_blocks_collection_notice_text', Settings::OPTION_BLOCKS_COLLECTION_NOTICE );
		self::assertSame( 'textarea', $campo['type'] );
		self::assertSame(
			'Con tu cuenta Akibara te avisamos por correo cuando salga el siguiente tomo de las series que compras. No es publicidad: lo apagas cuando quieras en Mi cuenta, pestaña Colección.',
			$campo['default']
		);
		self::assertSame(
			'Se ve en el checkout, encima del botón de pagar, a todos los clientes. Déjalo vacío para apagarlo.',
			$campo['desc']
		);
	}

	/** @testdox Va justo debajo del aviso de Santiago: los dos avisos del checkout, juntos */
	public function test_it_sits_right_after_the_shipping_hint(): void {
		$ids = array();
		foreach ( ( new Settings() )->get_settings() as $field ) {
			if ( is_array( $field ) && isset( $field['id'] ) ) {
				$ids[] = $field['id'];
			}
		}

		$santiago = array_search( Settings::OPTION_BLOCKS_SHIPPING_HINT, $ids, true );
		self::assertIsInt( $santiago );
		self::assertSame( Settings::OPTION_BLOCKS_COLLECTION_NOTICE, $ids[ $santiago + 1 ] ?? null );
	}

	/** @testdox Guardarlo vacío lo APAGA: el sanitizador no repone el default */
	public function test_saving_it_blank_switches_the_notice_off(): void {
		$sanitizado = ( new Settings() )->sanitize_admin_option(
			'lo que sea',
			array( 'id' => Settings::OPTION_BLOCKS_COLLECTION_NOTICE ),
			'   '
		);

		self::assertSame( '', $sanitizado );
	}

	/** @testdox Un aviso reescrito se guarda limpio de etiquetas */
	public function test_a_rewritten_notice_is_stored_sanitized(): void {
		$sanitizado = ( new Settings() )->sanitize_admin_option(
			'',
			array( 'id' => Settings::OPTION_BLOCKS_COLLECTION_NOTICE ),
			' Te avisamos del siguiente tomo. <script>alert(1)</script>Lo apagas en Mi cuenta. '
		);

		self::assertSame( 'Te avisamos del siguiente tomo. alert(1)Lo apagas en Mi cuenta.', $sanitizado );
	}
}
