<?php
/**
 * Candado: cada interruptor de aviso del carrito va pegado al texto que enciende.
 *
 * El interruptor «Aviso preventa» y su textarea «Copy preventa» estaban a ~55
 * líneas de distancia en el mismo formulario. Con esa separación la descripción
 * del interruptor se volvió un resumen de la política —«se despacha cuando llegan
 * todos los productos»— que dejó de coincidir con el copy realmente publicado
 * —«revisa las opciones de despacho antes de pagar»—: el dueño leía en el admin
 * una promesa distinta de la que veía el cliente en el carrito. Verificado vivo:
 * en producción no existen `akibara_native_cart_show_preventa_policy` ni
 * `akibara_native_cart_preventa_policy_copy`, o sea corren los dos defaults.
 *
 * La INVARIANTE no es el texto exacto de ninguna descripción: es que el campo de
 * texto sea el campo SIGUIENTE a su interruptor, y que la descripción del
 * interruptor hable del campo (que no puede desincronizarse) y no del contenido
 * del campo (que sí).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use Akibara\Business\Modules\NativeCart\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PolicySettingsLayoutTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function policy_pairs(): array {
		return array(
			'preventa'      => array( Settings::OPTION_SHOW_PREVENTA_POLICY, Settings::OPTION_PREVENTA_POLICY_COPY ),
			'arma tu pack'  => array( Settings::OPTION_SHOW_BYP_POLICY, Settings::OPTION_BYP_POLICY_COPY ),
		);
	}

	/**
	 * @dataProvider policy_pairs
	 * @testdox El copy de la política va inmediatamente debajo de su interruptor.
	 */
	public function test_copy_field_follows_its_toggle( string $toggle_id, string $copy_id ): void {
		$fields = array_values(
			array_filter(
				( new Settings() )->get_settings(),
				static fn( $field ): bool => is_array( $field ) && isset( $field['id'] )
			)
		);

		$ids            = array_column( $fields, 'id' );
		$toggle_position = array_search( $toggle_id, $ids, true );
		$copy_position   = array_search( $copy_id, $ids, true );

		self::assertIsInt( $toggle_position, 'El interruptor tiene que existir en el formulario.' );
		self::assertIsInt( $copy_position, 'El campo de texto tiene que existir en el formulario.' );
		self::assertSame(
			$toggle_position + 1,
			$copy_position,
			'El texto que enciende el interruptor tiene que ser el campo siguiente: separados, sus descripciones se desincronizan.'
		);
	}

	/**
	 * @dataProvider policy_pairs
	 * @testdox La descripción del interruptor nombra su campo de texto y no repite
	 *          el contenido de ese texto.
	 */
	public function test_toggle_description_points_at_the_copy_field( string $toggle_id, string $copy_id ): void {
		$fields = ( new Settings() )->get_settings();
		$toggle = null;
		$copy   = null;
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'] ) ) {
				continue;
			}
			if ( $toggle_id === $field['id'] ) {
				$toggle = $field;
			}
			if ( $copy_id === $field['id'] ) {
				$copy = $field;
			}
		}

		self::assertIsArray( $toggle );
		self::assertIsArray( $copy );

		$titulo = (string) ( $copy['title'] ?? '' );
		self::assertNotSame( '', $titulo );
		self::assertStringContainsString(
			$titulo,
			(string) ( $toggle['desc'] ?? '' ),
			'La descripción del interruptor tiene que remitir al campo de texto; describir la política a mano es lo que se desincronizó.'
		);

		// Y no puede volver a ser una paráfrasis del texto por defecto: eso es
		// exactamente lo que quedó divergente.
		$default = (string) ( $copy['default'] ?? '' );
		self::assertNotSame( $default, (string) ( $toggle['desc'] ?? '' ) );
	}

	public function test_the_policy_copy_resolves_tipo_and_honors_free_text(): void {
		// Orden del dueño 2026-08-31: el carrito ELIGE la palabra; un texto
		// del dueño sin {tipo} manda tal cual.
		$settings = new Settings();
		\Brain\Monkey\Functions\when( 'wp_strip_all_tags' )->returnArg();
		\Brain\Monkey\Functions\when( 'sanitize_text_field' )->returnArg();
		\Brain\Monkey\Functions\when( 'sanitize_textarea_field' )->returnArg();

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			static fn( string $o, $d = false ) => $d
		);
		$this->assertSame(
			'Si mezclas preventas y pedidos con stock listo, se enviará en un despacho único al reunirse todos los tomos de tu pedido.',
			$settings->build_preventa_policy_copy( 'preventas y pedidos' )
		);
		$this->assertSame(
			'Si mezclas pedidos con stock listo, se enviará en un despacho único al reunirse todos los tomos de tu pedido.',
			$settings->build_preventa_policy_copy( 'pedidos' )
		);

		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			static fn( string $o, $d = false ) => 'akibara_native_cart_preventa_policy_copy' === $o ? 'Texto libre del dueno.' : $d
		);
		$this->assertSame( 'Texto libre del dueno.', $settings->build_preventa_policy_copy( 'preventas' ) );
	}
}
