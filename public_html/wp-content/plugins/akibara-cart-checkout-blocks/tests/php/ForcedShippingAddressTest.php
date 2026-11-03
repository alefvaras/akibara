<?php
/**
 * Tests for the billing-only shipping address fill.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use Akibara\Business\Contracts\CheckoutDataProvider;
use Akibara\CartCheckoutBlocks\CheckoutFields;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ForcedShippingAddressTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once __DIR__ . '/fixtures/checkout-runtime.php';
		// El mismo `WC()` que usan las demás clases: define la función sólo si falta
		// y lee el runtime de un global, así que no le roba el doble a nadie.
		require_once __DIR__ . '/fixtures/woocommerce-functions.php';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_woocommerce'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fields(): CheckoutFields {
		return new CheckoutFields( $this->createMock( CheckoutDataProvider::class ) );
	}

	/**
	 * @return array<string,string>
	 */
	private static function home( string $type ): array {
		return array(
			"{$type}_first_name" => 'Ana',
			"{$type}_last_name"  => 'Rojas',
			"{$type}_company"    => 'Taller Rojas',
			"{$type}_address_1"  => 'Volcán Llaima 715',
			"{$type}_address_2"  => 'Depto 4',
			"{$type}_city"       => 'Curicó',
			"{$type}_state"      => 'CL-ML',
			"{$type}_postcode"   => '3340000',
			"{$type}_country"    => 'CL',
			"{$type}_phone"      => '87654321',
		);
	}

	/**
	 * Customer double with every address getter and setter the fill touches.
	 *
	 * Anonymous so the class resolves after the shared fixture defines `WC_Customer`.
	 *
	 * @param array<string,string> $parts Address parts keyed `billing_city`, `shipping_state`…
	 */
	private static function customer( array $parts ): \WC_Customer {
		return new class( $parts ) extends \WC_Customer {
			/** @var array<string,string> */
			private array $parts;

			public int $saves = 0;

			public function save(): int {
				++$this->saves;
				return 0;
			}

			/**
			 * @param array<string,string> $parts Address parts keyed `billing_city`, `shipping_state`…
			 */
			public function __construct( array $parts ) {
				$this->parts = $parts;
			}

			/**
			 * @param array<int,mixed> $args Setter value.
			 * @return mixed
			 */
			public function __call( string $name, array $args ) {
				if ( 0 === strpos( $name, 'get_' ) ) {
					return $this->parts[ substr( $name, 4 ) ] ?? '';
				}
				if ( 0 === strpos( $name, 'set_' ) ) {
					$this->parts[ substr( $name, 4 ) ] = (string) $args[0];
					return null;
				}
				throw new \BadMethodCallException( $name );
			}

			public function get_billing_country(): string {
				return $this->__call( __FUNCTION__, array() );
			}

			// El stub canónico de akibara-business declara estos accesores con
			// propiedades privadas; sin redirigirlos, `__call` nunca los ve y el
			// nombre de facturación llega vacío cuando ese stub carga primero.
			public function get_billing_first_name(): string {
				return $this->__call( __FUNCTION__, array() );
			}

			public function set_billing_first_name( string $val ): void {
				$this->__call( __FUNCTION__, array( $val ) );
			}

			public function get_billing_last_name(): string {
				return $this->__call( __FUNCTION__, array() );
			}

			public function set_billing_last_name( string $val ): void {
				$this->__call( __FUNCTION__, array( $val ) );
			}

			public function get_billing_state(): string {
				return $this->__call( __FUNCTION__, array() );
			}

			public function get_billing_city(): string {
				return $this->__call( __FUNCTION__, array() );
			}

			public function set_billing_city( string $value ): void {
				$this->__call( __FUNCTION__, array( $value ) );
			}

			public function get_shipping_country(): string {
				return $this->__call( __FUNCTION__, array() );
			}

			public function get_shipping_state(): string {
				return $this->__call( __FUNCTION__, array() );
			}

			public function get_shipping_city(): string {
				return $this->__call( __FUNCTION__, array() );
			}

			public function set_shipping_city( string $value ): void {
				$this->__call( __FUNCTION__, array( $value ) );
			}
		};
	}

	/**
	 * @testdox Should align shipping on cart load and before every customer save.
	 */
	public function test_hook_wiring(): void {
		$sut = $this->fields();

		Monkey\Actions\expectAdded( 'woocommerce_cart_loaded_from_session' )
			->once()
			->with( array( $sut, 'sync_forced_shipping_address' ), 10, 1 );
		Monkey\Actions\expectAdded( 'woocommerce_before_customer_object_save' )
			->once()
			->with( array( $sut, 'mirror_before_customer_save' ), 10, 1 );

		$sut->register();

		// Brain Monkey verifica las expectativas al cerrar; PHPUnit no las cuenta.
		self::assertTrue( true );
	}

	/**
	 * @testdox Should copy a saved billing address into an empty shipping address.
	 */
	public function test_fills_empty_shipping_from_billing(): void {
		$customer = self::customer( self::home( 'billing' ) + array( 'shipping_country' => 'CL', 'shipping_state' => 'CL-RM' ) );

		self::assertTrue( $this->fields()->mirror_billing_into_shipping( $customer ) );
		self::assertSame( 'Volcán Llaima 715', $customer->get_shipping_address_1() );
		self::assertSame( 'Curicó', $customer->get_shipping_city() );
		self::assertSame( 'CL-ML', $customer->get_shipping_state(), 'The default base region must not survive the copy.' );
		foreach ( self::home( 'billing' ) as $clave => $valor ) {
			self::assertSame( $valor, $customer->{'get_shipping_' . substr( $clave, 8 )}(), "La parte {$clave} debe viajar al envío." );
		}
	}

	/**
	 * @testdox Should replace a complete but different shipping address, such as a Blue point saved by a past order.
	 */
	public function test_replaces_divergent_shipping(): void {
		$punto    = array(
			'shipping_address_1' => 'Manuel Montt 248',
			'shipping_address_2' => 'Punto Blue Express',
			'shipping_city'      => 'Talca',
			'shipping_state'     => 'CL-ML',
			'shipping_country'   => 'CL',
		);
		$customer = self::customer( self::home( 'billing' ) + $punto );

		self::assertTrue( $this->fields()->mirror_billing_into_shipping( $customer ) );
		self::assertSame( 'Volcán Llaima 715', $customer->get_shipping_address_1() );
		self::assertSame( 'Depto 4', $customer->get_shipping_address_2(), 'A stale point name must not survive in address_2.' );
		self::assertSame( 'Curicó', $customer->get_shipping_city(), 'The quote must follow the card, not the old comuna.' );
	}

	/**
	 * @testdox Should report no change when shipping already equals billing.
	 */
	public function test_identical_address_is_untouched(): void {
		$customer = self::customer( self::home( 'billing' ) + self::home( 'shipping' ) );

		self::assertFalse( $this->fields()->mirror_billing_into_shipping( $customer ) );
	}

	/**
	 * @testdox Should do nothing while billing itself is incomplete, as for a new guest.
	 */
	public function test_ignores_incomplete_billing(): void {
		$customer = self::customer(
			array(
				'billing_country'    => 'CL',
				'billing_state'      => 'CL-RM',
				'shipping_country'   => 'CL',
				'shipping_state'     => 'CL-RM',
				'shipping_address_1' => 'Otra calle 1',
				'shipping_city'      => 'Ñuñoa',
			)
		);

		self::assertFalse( $this->fields()->mirror_billing_into_shipping( $customer ) );
		self::assertSame( 'Ñuñoa', $customer->get_shipping_city() );
	}

	/**
	 * @testdox Should align the account before saving only while the store ships to billing.
	 */
	public function test_before_save_follows_the_store_setting(): void {
		$encendido = self::customer( self::home( 'billing' ) );
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( true );
		$this->fields()->mirror_before_customer_save( $encendido );
		self::assertSame( 'Curicó', $encendido->get_shipping_city() );

		$apagado = self::customer( self::home( 'billing' ) );
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
		$this->fields()->mirror_before_customer_save( $apagado );
		self::assertSame( '', $apagado->get_shipping_city(), 'A store that ships anywhere keeps its own shipping address.' );

		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( true );
		$this->fields()->mirror_before_customer_save( 'no es un cliente' );
		self::assertSame( '', $apagado->get_shipping_city(), 'Con el interruptor encendido, algo que no es un cliente no se toca ni falla.' );
	}

	/**
	 * @testdox Should keep the account on the customer's address after an order with a Blue point.
	 */
	public function test_order_with_point_does_not_leave_the_point_on_the_account(): void {
		// Lo que deja `OrderController::sync_customer_data_with_order()` antes de guardar:
		// facturación = cliente, envío = el punto del pedido.
		$cuenta = self::customer(
			self::home( 'billing' ) + array(
				'shipping_address_1' => 'Manuel Montt 248',
				'shipping_address_2' => 'Punto Blue Express Quest Loot',
				'shipping_city'      => 'Curicó',
				'shipping_state'     => 'CL-ML',
				'shipping_country'   => 'CL',
			)
		);
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( true );

		$this->fields()->mirror_before_customer_save( $cuenta );

		self::assertSame( 'Volcán Llaima 715', $cuenta->get_shipping_address_1() );
		self::assertSame( 'Depto 4', $cuenta->get_shipping_address_2() );
	}

	/**
	 * @testdox Should fix the session customer on cart load without writing the account.
	 */
	public function test_cart_load_never_saves(): void {
		$customer = self::customer( self::home( 'billing' ) );
		$GLOBALS['akibara_test_woocommerce'] = (object) array( 'customer' => $customer );
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( true );

		$this->fields()->sync_forced_shipping_address();

		self::assertSame( 'Volcán Llaima 715', $customer->get_shipping_address_1() );
		self::assertSame( 0, $customer->saves, 'The copy only sets fields; WooCommerce persists the session on shutdown.' );

		$propio = self::customer( self::home( 'billing' ) + array( 'shipping_city' => 'Talca' ) );
		$GLOBALS['akibara_test_woocommerce'] = (object) array( 'customer' => $propio );
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

		$this->fields()->sync_forced_shipping_address();

		self::assertSame( 'Talca', $propio->get_shipping_city(), 'A store that ships anywhere keeps the customer shipping address.' );
	}
}
