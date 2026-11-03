<?php
/**
 * Tests for final Checkout Blocks field validation.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use Akibara\Business\Contracts\CheckoutDataProvider;
use Akibara\Business\Utilities\RutValidator;
use Akibara\CartCheckoutBlocks\CheckoutFields;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Customer;
use WC_Order;
use WC_Product;
use WP_Error;

final class CheckoutFieldsTest extends TestCase {

	/**
	 * Loads the isolated order and error fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once __DIR__ . '/fixtures/checkout-runtime.php';
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( string $value ): string => trim( $value )
		);
	}

	/**
	 * Restores Brain Monkey after each adapter test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should hang phone validation and RUT persistence on WooCommerce's own hooks.
	 *
	 * Antes esto era un `assertStringContainsString` con el espaciado exacto de
	 * `array( $this, ... )`: cualquier `phpcbf` lo ponía rojo sin cambiar una
	 * conducta, y seguía verde si `register()` dejaba de llamarse. Lo que
	 * importa es que el enganche QUEDE puesto, así que se observa el enganche.
	 */
	public function test_registers_validation_and_persistence_hooks(): void {
		$sut = new CheckoutFields( new CheckoutRuntimeDataProviderStub() );

		Monkey\Filters\expectAdded( 'woocommerce_get_country_locale' )->once();
		Monkey\Filters\expectAdded( 'woocommerce_get_default_value_for_akibara/rut' )->once();
		Monkey\Actions\expectAdded( 'woocommerce_checkout_validate_order_before_payment' )
			->once()
			->with( array( $sut, 'validate_billing_phone' ), 10, 2 );
		Monkey\Actions\expectAdded( 'woocommerce_set_additional_field_value' )
			->once()
			->with( array( $sut, 'sync_rut_to_legacy_meta' ), 10, 4 );
		Monkey\Actions\expectAdded( 'woocommerce_init' )->once();

		$sut->register();

		// Brain Monkey verifica las expectativas al cerrar, pero PHPUnit no las
		// cuenta como aserciones y `failOnRisky` marcaría el test en rojo.
		self::assertTrue( true );
	}

	/**
	 * @testdox Should validate the final order, never a partial address update.
	 *
	 * Candado NEGATIVO sobre el fuente SIN comentarios: lo que se prohíbe es que
	 * el código vuelva a validar sobre una dirección a medio escribir o a
	 * destripar la petición REST a mano. Se leen sólo los tokens de código
	 * porque un docblock que EXPLIQUE por qué no se usa algo bastaba para
	 * ponerlo rojo.
	 */
	public function test_never_validates_a_partial_address_or_parses_the_rest_request(): void {
		$code = self::source_without_comments( dirname( __DIR__, 2 ) . '/src/CheckoutFields.php' );

		self::assertStringNotContainsString(
			'woocommerce_blocks_validate_location_address_fields',
			$code,
			'Phone validation must run against the final order, not a partial address update.'
		);
		self::assertStringNotContainsString(
			"get_param( 'additional_fields' )",
			$code,
			'Blocks must not parse the Checkout REST request manually.'
		);
	}

	/**
	 * @testdox Should register the RUT field with the attributes WooCommerce accepts.
	 */
	public function test_registered_rut_field_definition(): void {
		$registered = array();
		Functions\when( 'woocommerce_register_additional_checkout_field' )->alias(
			static function ( array $definition ) use ( &$registered ): void {
				$registered[] = $definition;
			}
		);

		$sut = new CheckoutFields( new CheckoutRuntimeDataProviderStub() );
		$sut->register_rut_field();
		$sut->register_rut_field();

		self::assertCount( 1, $registered, 'El campo se registra UNA vez aunque WooCommerce llame dos.' );

		$field = $registered[0];
		self::assertSame( 'akibara/rut', $field['id'] );
		self::assertSame( array( $sut, 'sanitize_rut' ), $field['sanitize_callback'] );
		self::assertSame( array( $sut, 'validate_rut' ), $field['validate_callback'] );

		// Un RUT canónico ocupa 12 caracteres («12.345.678-5»); con menos, el
		// campo corta lo que el propio plugin escribe.
		self::assertSame( '13', $field['attributes']['maxLength'] );
		self::assertGreaterThanOrEqual( 12, (int) $field['attributes']['maxLength'] );

		// WooCommerce 10.9 RECHAZA el registro entero si llegan estos atributos.
		self::assertArrayNotHasKey( 'inputmode', $field['attributes'] );
		self::assertArrayNotHasKey( 'spellcheck', $field['attributes'] );
	}

	/**
	 * @testdox Should store the same RUT bytes in the native field and the legacy meta.
	 *
	 * `sanitize_rut()` devolvía el valor tal cual mientras las otras dos rutas
	 * del mismo dato canonizaban con `format_rut()`: teclear «123456785» dejaba
	 * el campo nativo con '123456785' y `_billing_rut` con '12.345.678-5'.
	 */
	public function test_sanitize_rut_canonicalizes_like_the_legacy_meta(): void {
		$sut   = new CheckoutFields( new CheckoutRuntimeDataProviderStub() );
		$order = new WC_Order();

		$native = $sut->sanitize_rut( '123456785', array() );
		$sut->sync_rut_to_legacy_meta( 'akibara/rut', '123456785', 'other', $order );

		self::assertSame( '12.345.678-5', $native );
		self::assertSame( $native, $order->get_meta( '_billing_rut', true ) );
		self::assertSame(
			$native,
			$sut->sanitize_rut( $native, array() ),
			'Canonizar dos veces tiene que dar lo mismo o el valor se degrada en cada guardado.'
		);
	}

	/**
	 * @testdox Should leave an invalid RUT untouched so validation can reject it.
	 */
	public function test_sanitize_rut_never_dresses_up_an_invalid_value(): void {
		$sut = new CheckoutFields( new CheckoutRuntimeDataProviderStub() );

		self::assertSame( 'no-es-un-rut', $sut->sanitize_rut( ' no-es-un-rut ', array() ) );
	}

	/**
	 * Devuelve el fuente de un fichero sin comentarios ni docblocks.
	 *
	 * Un candado negativo sobre el fuente crudo se dispara con el comentario que
	 * explica por qué NO se usa algo; leer sólo tokens de código lo evita.
	 */
	private static function source_without_comments( string $path ): string {
		$source = file_get_contents( $path );
		self::assertIsString( $source );

		$code = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}

	/**
	 * @testdox Should add the canonical Business phone error to the checkout error bag.
	 */
	public function test_invalid_billing_phone_adds_provider_error(): void {
		$provider = new CheckoutRuntimeDataProviderStub(
			array(
				'code'    => 'akibara_invalid_phone',
				'message' => 'Ingresa un celular chileno válido.',
			)
		);
		$sut      = new CheckoutFields( $provider );
		$errors   = new WP_Error();

		$sut->validate_billing_phone( new WC_Order( '1234567890' ), $errors );

		self::assertSame( 1, $provider->phone_validation_calls );
		self::assertSame(
			array( 'Ingresa un celular chileno válido.' ),
			$errors->get_error_messages( 'akibara_invalid_phone' )
		);
	}

	/**
	 * @testdox El celular de ENVÍO también se valida en el servidor, no sólo en el navegador.
	 *
	 * Hallazgo S-4edccf6d: el gemelo en cliente bloqueaba los dos y el servidor
	 * sólo miraba el de facturación. Un cliente sin JavaScript podía dejar un
	 * celular de envío impresentable, que es justo el que usa el transportista.
	 */
	public function test_el_celular_de_envio_tambien_se_valida(): void {
		$provider = new CheckoutRuntimeDataProviderStub(
			array(
				'code'    => 'akibara_invalid_phone',
				'message' => 'Ingresa un celular chileno válido.',
			)
		);
		$sut    = new CheckoutFields( $provider );
		$errors = new WP_Error();

		$order                      = new WC_Order( '' );
		$order->akb_shipping_phone = 'no-es-un-celular';

		$sut->validate_billing_phone( $order, $errors );

		self::assertSame( 1, $provider->phone_validation_calls, 'El de envío tiene que llegar al validador.' );
		self::assertNotSame( array(), $errors->get_error_messages( 'akibara_invalid_phone' ) );
	}

	/**
	 * @testdox El mismo número en los dos campos no genera dos avisos iguales.
	 */
	public function test_un_solo_aviso_cuando_los_dos_son_iguales(): void {
		$provider = new CheckoutRuntimeDataProviderStub(
			array(
				'code'    => 'akibara_invalid_phone',
				'message' => 'Ingresa un celular chileno válido.',
			)
		);
		$sut    = new CheckoutFields( $provider );
		$errors = new WP_Error();

		$order                     = new WC_Order( '1234567890' );
		$order->akb_shipping_phone = '1234567890';

		$sut->validate_billing_phone( $order, $errors );

		self::assertSame( 1, $provider->phone_validation_calls );
		self::assertCount( 1, $errors->get_error_messages( 'akibara_invalid_phone' ) );
	}

	/**
	 * @testdox Should leave an empty phone to WooCommerce's required-field validation.
	 */
	public function test_empty_billing_phone_is_not_revalidated(): void {
		$provider = new CheckoutRuntimeDataProviderStub(
			array(
				'code'    => 'akibara_invalid_phone',
				'message' => 'No debería agregarse.',
			)
		);
		$sut      = new CheckoutFields( $provider );
		$errors   = new WP_Error();

		$sut->validate_billing_phone( new WC_Order(), $errors );

		self::assertSame( 0, $provider->phone_validation_calls );
		self::assertSame( array(), $errors->get_error_messages( 'akibara_invalid_phone' ) );
	}

	/**
	 * @testdox Should synchronize the native RUT to legacy order and customer metadata.
	 */
	public function test_native_rut_persistence_updates_legacy_meta(): void {
		$provider = new CheckoutRuntimeDataProviderStub();
		$sut      = new CheckoutFields( $provider );
		$order    = new WC_Order();
		$customer = new WC_Customer();

		$sut->sync_rut_to_legacy_meta( 'akibara/rut', '12.345.678-5', 'other', $order );
		$sut->sync_rut_to_legacy_meta( 'akibara/rut', '12.345.678-5', 'other', $customer );

		self::assertSame( '12.345.678-5', $order->get_meta( '_billing_rut', true ) );
		self::assertSame( '12.345.678-5', $customer->get_meta( 'billing_rut', true ) );
	}

	/**
	 * @testdox Should supply the native field from legacy metadata.
	 */
	public function test_legacy_rut_is_available_as_native_default(): void {
		$provider = new CheckoutRuntimeDataProviderStub();
		$sut      = new CheckoutFields( $provider );
		$order    = new WC_Order();
		$order->update_meta_data( '_billing_rut', '12.345.678-5' );

		self::assertSame(
			'12.345.678-5',
			$sut->default_rut_from_legacy_meta( null, 'other', $order )
		);
		self::assertSame(
			'existing',
			$sut->default_rut_from_legacy_meta( 'existing', 'other', $order )
		);
	}
}

final class CheckoutRuntimeDataProviderStub implements CheckoutDataProvider {

	/** @var array{code:string,message:string}|null */
	private ?array $phone_error;

	public int $phone_validation_calls = 0;

	/**
	 * @param array{code:string,message:string}|null $phone_error Phone result.
	 */
	public function __construct( ?array $phone_error = null ) {
		$this->phone_error = $phone_error;
	}

	public function structured_cart_data(): array {
		return array( 'schema_version' => 2 );
	}

	public function product_availability( WC_Product $product ): array {
		unset( $product );

		return array();
	}

	/**
	 * Se delega en el validador REAL de Business, no en un doble.
	 *
	 * Un doble que devolviera `true` siempre y `format_rut()` la identidad es
	 * justo lo que dejó pasar el defecto: con él, «el campo nativo guarda lo
	 * mismo que la meta» era cierto por accidente.
	 */
	public function is_valid_rut( string $rut ): bool {
		return RutValidator::is_valid( $rut );
	}

	public function format_rut( string $rut ): string {
		return RutValidator::format( $rut );
	}

	public function phone_validation_error( string $phone ): ?array {
		unset( $phone );
		++$this->phone_validation_calls;

		return $this->phone_error;
	}

	public function address_autocomplete_config(): array {
		return array(
			'enabled'    => true,
			'api_key'    => 'test-key',
			'region_map' => array(),
		);
	}
}
