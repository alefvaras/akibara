<?php
/**
 * Candado de la memoización por petición del resolver de envío gratis.
 *
 * Por qué existe: `for_current_cart()` se resolvía entero en CADA llamada, y en
 * una petición del carrito la piden dos veces
 * (`FreeShippingProgress::structured_state()` y `::get_html()`; el widget de
 * bundles añade una tercera en su superficie). Medido en Docker el 2026-08-23
 * con carrito de 2 ítems y destino CL/RM: 5 consultas y 3,97 / 5,83 / 3,88 ms
 * por llamada, con resultado idéntico las tres veces.
 *
 * La invariante que se fija NO es «existe una propiedad estática», sino:
 *  1. mismas entradas dentro de la petición ⇒ los paquetes se resuelven una vez;
 *  2. si cambia una entrada (destino, cupones, contenido, parámetros) ⇒ se
 *     recalcula, porque la clave sale de las entradas y no del reloj;
 *  3. si no se puede construir una clave confiable ⇒ NO se memoiza (falla
 *     abierta hacia el cálculo real).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Services\FreeShippingThreshold;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FreeShippingThresholdRequestCacheTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'WC' ) ) {
			eval(
				'namespace { function WC() {
					return $GLOBALS["akibara_fst_wc"];
				} }'
			);
		}
		if ( ! function_exists( 'wp_json_encode' ) ) {
			eval(
				'namespace { function wp_json_encode( $data ) {
					return json_encode( $data );
				} }'
			);
		}
		if ( ! function_exists( 'wc_get_price_decimals' ) ) {
			eval(
				'namespace { function wc_get_price_decimals(): int {
					return 0;
				} }'
			);
		}
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			eval(
				'namespace { class WC_Shipping_Zones {
					public static function get_zone_matching_package( array $package ) {
						unset( $package );
						return $GLOBALS["akibara_fst_zone"];
					}
				} }'
			);
		}

		$GLOBALS['akibara_fst_zone'] = new RequestCacheZone();
		FreeShippingThreshold::reset_request_cache();
	}

	protected function tearDown(): void {
		FreeShippingThreshold::reset_request_cache();
		unset( $GLOBALS['akibara_fst_wc'], $GLOBALS['akibara_fst_zone'] );

		parent::tearDown();
	}

	/**
	 * @testdox Should resolve the shipping packages once per request for identical inputs.
	 */
	public function test_identical_calls_resolve_packages_only_once(): void {
		$cart = $this->configure_runtime( 50000.0 );

		$first  = FreeShippingThreshold::for_current_cart( 0.0, 0.0 );
		$second = FreeShippingThreshold::for_current_cart( 0.0, 0.0 );

		self::assertSame( FreeShippingThreshold::STATE_PROGRESS, $first['state'] );
		self::assertSame( $first, $second );
		self::assertSame( 1, $cart->packages_calls, 'La segunda llamada volvió a resolver los paquetes.' );
		self::assertSame( 1, $GLOBALS['akibara_fst_zone']->methods_calls );
	}

	/**
	 * @testdox Should recalculate when the shipping destination changes mid request.
	 *
	 * El hash de carrito de WooCommerce NO incluye el destino: si la clave se
	 * quedara sólo con él, cambiar de región devolvería el estado de la región
	 * anterior.
	 */
	public function test_destination_change_busts_the_memo(): void {
		$cart = $this->configure_runtime( 50000.0 );

		FreeShippingThreshold::for_current_cart( 0.0, 0.0 );
		$GLOBALS['akibara_fst_wc']->customer->state = 'CL-AP';
		FreeShippingThreshold::for_current_cart( 0.0, 0.0 );

		self::assertSame( 2, $cart->packages_calls );
	}

	/**
	 * @testdox Should recalculate when a free-shipping coupon is applied without changing totals.
	 *
	 * Un cupón de sólo envío gratis no mueve el total, así que el hash del
	 * carrito puede quedar igual. Por eso los códigos aplicados entran en la clave.
	 */
	public function test_applied_coupons_bust_the_memo(): void {
		$cart = $this->configure_runtime( 50000.0 );

		FreeShippingThreshold::for_current_cart( 0.0, 0.0 );
		$cart->applied_coupons = array( 'ENVIOGRATIS' );
		FreeShippingThreshold::for_current_cart( 0.0, 0.0 );

		self::assertSame( 2, $cart->packages_calls );
	}

	/**
	 * @testdox Should recalculate when the operator thresholds differ.
	 */
	public function test_parameters_are_part_of_the_key(): void {
		$cart = $this->configure_runtime( 50000.0 );

		FreeShippingThreshold::for_current_cart( 0.0, 0.0 );
		FreeShippingThreshold::for_current_cart( 60000.0, 0.0 );

		self::assertSame( 2, $cart->packages_calls );
	}

	/**
	 * @testdox Should skip memoization when the cart cannot produce a hash.
	 */
	public function test_cart_without_hash_is_never_memoized(): void {
		$cart = $this->configure_runtime( 50000.0 );
		$cart->cart_hash = '';

		FreeShippingThreshold::for_current_cart( 0.0, 0.0 );
		FreeShippingThreshold::for_current_cart( 0.0, 0.0 );

		self::assertSame( 2, $cart->packages_calls );
	}

	private function configure_runtime( float $subtotal ): RequestCacheCart {
		$cart = new RequestCacheCart( $subtotal );

		$GLOBALS['akibara_fst_wc'] = (object) array(
			'cart'     => $cart,
			'customer' => new RequestCacheCustomer(),
		);

		return $cart;
	}
}

final class RequestCacheCart {

	public int $packages_calls = 0;
	public string $cart_hash   = 'hash-inicial';
	/** @var array<int,string> */
	public array $applied_coupons = array();

	private float $subtotal;

	public function __construct( float $subtotal ) {
		$this->subtotal = $subtotal;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_shipping_packages(): array {
		++$this->packages_calls;

		return array(
			array(
				'destination' => array( 'country' => 'CL' ),
			),
		);
	}

	public function get_cart_hash(): string {
		return $this->cart_hash;
	}

	/**
	 * @return array<int,string>
	 */
	public function get_applied_coupons(): array {
		return $this->applied_coupons;
	}

	public function get_displayed_subtotal(): float {
		return $this->subtotal;
	}

	public function get_discount_total(): float {
		return 0.0;
	}

	public function get_discount_tax(): float {
		return 0.0;
	}

	public function display_prices_including_tax(): bool {
		return true;
	}

	/**
	 * @return array<int,object>
	 */
	public function get_coupons(): array {
		return array();
	}
}

final class RequestCacheCustomer {

	public string $country  = 'CL';
	public string $state    = 'CL-RM';
	public string $postcode = '8320000';
	public string $city     = 'Santiago';

	public function get_shipping_country(): string {
		return $this->country;
	}

	public function get_shipping_state(): string {
		return $this->state;
	}

	public function get_shipping_postcode(): string {
		return $this->postcode;
	}

	public function get_shipping_city(): string {
		return $this->city;
	}
}

final class RequestCacheZone {

	public int $methods_calls = 0;

	/**
	 * @return array<int,object>
	 */
	public function get_shipping_methods( bool $enabled_only = false ): array {
		unset( $enabled_only );
		++$this->methods_calls;

		return array( new RequestCacheFreeShippingMethod() );
	}
}

final class RequestCacheFreeShippingMethod {

	public string $id               = 'free_shipping';
	public string $requires         = 'min_amount';
	public float $min_amount        = 55000.0;
	public string $ignore_discounts = 'no';

	/**
	 * @param array<string,mixed> $package Shipping package.
	 */
	public function is_available( array $package ): bool {
		unset( $package );

		return false;
	}
}
