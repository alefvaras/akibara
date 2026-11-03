<?php
/**
 * Candado: no se canoniza un teléfono que el propio validador rechaza.
 *
 * `ChileanPhone::format()` sólo mira el LARGO, así que anteponía `+569` a
 * cualquier cadena de ocho dígitos —`11111111`, `01234567`— y el pedido quedaba
 * con un número con forma de celular chileno legítimo. Peor que dejarlo crudo:
 * nadie vuelve a mirar un `+56911111111`, mientras que un `11111111` a la vista
 * se corrige llamando al cliente.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\PhoneValidation;

use Akibara\Business\Modules\PhoneValidation\PhoneValidation;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PhoneValidationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $value ): string => trim( $value ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function order( string $billing, string $shipping = '' ): FakePhoneOrder {
		return new FakePhoneOrder( $billing, $shipping );
	}

	/**
	 * @testdox Un celular válido se guarda en forma canónica.
	 */
	public function test_a_valid_phone_is_canonicalized(): void {
		$order = $this->order( '8765 4321', '9 8765 4321' );

		( new PhoneValidation() )->normalize_on_store_api_order( $order );

		self::assertSame( '+56987654321', $order->billing );
		self::assertSame( '+56987654321', $order->shipping );
		self::assertSame( 1, $order->saves, 'Un solo save por pedido, aunque cambien los dos campos.' );
	}

	/**
	 * @dataProvider rejected_phones
	 * @testdox Un celular que el validador rechaza se deja tal cual.
	 */
	public function test_a_rejected_phone_is_left_alone( string $raw ): void {
		$order = $this->order( $raw );

		( new PhoneValidation() )->normalize_on_store_api_order( $order );

		self::assertSame( $raw, $order->billing );
		self::assertSame( 0, $order->saves, 'Sin cambio real no hay que escribir el pedido.' );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function rejected_phones(): array {
		return array(
			'dígitos repetidos' => array( '11111111' ),
			'empieza en cero'   => array( '01234567' ),
			'empieza en uno'    => array( '12345678' ),
			'largo insuficiente' => array( '1234' ),
		);
	}
}

/**
 * Doble mínimo de `WC_Order` con los cuatro accesores que usa el módulo.
 */
final class FakePhoneOrder extends \WC_Order {

	public int $saves = 0;

	public function __construct( public string $billing, public string $shipping = '' ) {}

	public function get_billing_phone(): string {
		return $this->billing;
	}

	public function get_shipping_phone(): string {
		return $this->shipping;
	}

	public function set_billing_phone( string $value ): void {
		$this->billing = $value;
	}

	public function set_shipping_phone( string $value ): void {
		$this->shipping = $value;
	}

	public function save(): int {
		++$this->saves;

		return $this->saves;
	}
}
