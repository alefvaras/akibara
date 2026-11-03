<?php
/**
 * Tests for Checkout Blocks commune canonicalization and validation.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use Akibara\Business\Contracts\CheckoutDataProvider;
use Akibara\CartCheckoutBlocks\CheckoutFields;
use Akibara\CartCheckoutBlocks\ComunaDirectory;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Product;
use WP_Error;

final class CheckoutFieldsComunaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once __DIR__ . '/fixtures/checkout-runtime.php';
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( string $value ): string => trim( $value )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function directory(): ComunaDirectory {
		return new ComunaDirectory(
			static fn(): array => array(
				'CL-RM' => array(
					array( 'name' => 'Ñuñoa' ),
					array( 'name' => 'San Miguel' ),
					array( 'name' => 'Providencia' ),
				),
				'CL-VS' => array(
					array( 'name' => 'Viña del Mar' ),
				),
			)
		);
	}

	private function fields( ?ComunaDirectory $directory = null ): CheckoutFields {
		return new CheckoutFields(
			new ComunaCheckoutDataProviderStub(),
			$directory ?? $this->directory()
		);
	}

	/**
	 * @testdox Should register commune hooks on the Store API customer, order and validation stages.
	 */
	public function test_commune_hook_wiring(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/CheckoutFields.php' );

		self::assertIsString( $source );
		self::assertStringContainsString(
			"'woocommerce_store_api_cart_update_customer_from_request', array( \$this, 'canonicalize_customer_comuna' )",
			$source,
			'Customer canonicalization must run before shipping recalculation so zone matching sees canonical accents.'
		);
		self::assertStringContainsString(
			"'woocommerce_store_api_checkout_update_order_from_request', array( \$this, 'canonicalize_order_comuna' )",
			$source
		);
		self::assertStringContainsString(
			"'woocommerce_checkout_validate_order_before_payment', array( \$this, 'validate_chilean_comuna' ), 10, 2",
			$source
		);
	}

	/**
	 * @testdox Should keep Región ordered before Comuna in the Chilean locale.
	 */
	public function test_region_precedes_comuna(): void {
		$fields  = $this->fields();
		$locales = $fields->localize_chilean_checkout_fields( array() );

		$state_priority = $locales['CL']['state']['priority'] ?? null;
		$city_priority  = $locales['CL']['city']['priority'] ?? 70;

		self::assertIsInt( $state_priority );
		self::assertLessThan(
			$city_priority,
			$state_priority,
			'The commune combobox filters by region, so Región must render first.'
		);
	}

	/**
	 * @testdox Should canonicalize accent-less communes on orders and customers.
	 */
	public function test_canonicalizes_typed_communes(): void {
		$fields = $this->fields();

		$order = new ComunaOrderStub(
			array(
				'billing_country'  => 'CL',
				'billing_city'     => 'nunoa',
				'shipping_country' => 'CL',
				'shipping_city'    => 'SAN MIGUEL ',
			)
		);
		$fields->canonicalize_order_comuna( $order );
		self::assertSame( 'Ñuñoa', $order->get_billing_city() );
		self::assertSame( 'San Miguel', $order->get_shipping_city() );

		$customer = new ComunaCustomerStub(
			array(
				'shipping_country' => 'CL',
				'shipping_city'    => 'vina del mar',
			)
		);
		$fields->canonicalize_customer_comuna( $customer );
		self::assertSame( 'Viña del Mar', $customer->get_shipping_city() );
	}

	/**
	 * @testdox Should leave foreign addresses, unknown communes and canonical values untouched.
	 */
	public function test_canonicalization_is_conservative(): void {
		$fields = $this->fields();

		$foreign = new ComunaOrderStub(
			array(
				'billing_country' => 'AR',
				'billing_city'    => 'nunoa',
			)
		);
		$fields->canonicalize_order_comuna( $foreign );
		self::assertSame( 'nunoa', $foreign->get_billing_city() );

		$unknown = new ComunaOrderStub(
			array(
				'billing_country' => 'CL',
				'billing_city'    => 'Villa Inexistente',
			)
		);
		$fields->canonicalize_order_comuna( $unknown );
		self::assertSame( 'Villa Inexistente', $unknown->get_billing_city() );

		$partial = new ComunaOrderStub(
			array(
				'billing_country' => 'CL',
				'billing_city'    => 'san mig',
			)
		);
		$fields->canonicalize_order_comuna( $partial );
		self::assertSame( 'san mig', $partial->get_billing_city(), 'Partial input must never be rewritten mid-typing.' );
	}

	/**
	 * @testdox Should reject an unknown commune with a did-you-mean suggestion.
	 */
	public function test_validation_suggests_closest_commune(): void {
		$fields = $this->fields();
		$errors = new WP_Error();

		$order = new ComunaOrderStub(
			array(
				'shipping_country' => 'CL',
				'shipping_state'   => 'CL-RM',
				'shipping_city'    => 'Probidencia',
			)
		);
		$fields->validate_chilean_comuna( $order, $errors );

		$messages = $errors->get_error_messages( 'akibara_comuna_invalid' );
		self::assertCount( 1, $messages );
		self::assertStringContainsString( 'Providencia', $messages[0] );
	}

	/**
	 * @testdox Should reject a commune that belongs to another region with a targeted message.
	 */
	public function test_validation_flags_region_mismatch(): void {
		$fields = $this->fields();
		$errors = new WP_Error();

		$order = new ComunaOrderStub(
			array(
				'shipping_country' => 'CL',
				'shipping_state'   => 'CL-VS',
				'shipping_city'    => 'San Miguel',
			)
		);
		$fields->validate_chilean_comuna( $order, $errors );

		$messages = $errors->get_error_messages( 'akibara_comuna_region_mismatch' );
		self::assertCount( 1, $messages );
		self::assertStringContainsString( 'San Miguel', $messages[0] );
	}

	/**
	 * @testdox Should accept canonicalizable and canonical communes without errors.
	 */
	public function test_validation_accepts_valid_communes(): void {
		$fields = $this->fields();
		$errors = new WP_Error();

		$order = new ComunaOrderStub(
			array(
				'billing_country'  => 'CL',
				'billing_state'    => 'CL-RM',
				'billing_city'     => 'nunoa',
				'shipping_country' => 'CL',
				'shipping_state'   => 'CL-RM',
				'shipping_city'    => 'Providencia',
			)
		);
		$fields->validate_chilean_comuna( $order, $errors );

		self::assertSame( array(), $errors->get_error_messages( 'akibara_comuna_invalid' ) );
		self::assertSame( array(), $errors->get_error_messages( 'akibara_comuna_region_mismatch' ) );
	}

	/**
	 * @testdox Should fail open when the vendor catalog is unavailable.
	 */
	public function test_validation_fails_open_without_catalog(): void {
		$unavailable = new ComunaDirectory(
			static function (): array {
				throw new \RuntimeException( 'vendor missing' );
			}
		);
		$fields = $this->fields( $unavailable );
		$errors = new WP_Error();

		$order = new ComunaOrderStub(
			array(
				'shipping_country' => 'CL',
				'shipping_state'   => 'CL-RM',
				'shipping_city'    => 'Cualquier Cosa',
			)
		);
		$fields->validate_chilean_comuna( $order, $errors );
		$fields->canonicalize_order_comuna( $order );

		self::assertSame( array(), $errors->get_error_messages( 'akibara_comuna_invalid' ) );
		self::assertSame( 'Cualquier Cosa', $order->get_shipping_city() );
	}
}

/**
 * Order stub with address parts; independent of whichever global WC_Order
 * stub loaded first in the shared suite (theirs lacks address methods).
 */
final class ComunaOrderStub extends WC_Order {

	/** @var array<string,string> */
	private array $parts;

	/**
	 * @param array<string,string> $parts Address parts keyed `billing_city`, `shipping_state`…
	 */
	public function __construct( array $parts = array() ) {
		$this->parts = $parts;
	}

	public function get_billing_country(): string {
		return $this->parts['billing_country'] ?? '';
	}

	public function get_billing_state(): string {
		return $this->parts['billing_state'] ?? '';
	}

	public function get_billing_city(): string {
		return $this->parts['billing_city'] ?? '';
	}

	public function set_billing_city( string $value ): void {
		$this->parts['billing_city'] = $value;
	}

	public function get_shipping_country(): string {
		return $this->parts['shipping_country'] ?? '';
	}

	public function get_shipping_state(): string {
		return $this->parts['shipping_state'] ?? '';
	}

	public function get_shipping_city(): string {
		return $this->parts['shipping_city'] ?? '';
	}

	public function set_shipping_city( string $value ): void {
		$this->parts['shipping_city'] = $value;
	}
}

/**
 * Customer stub for the untyped canonicalization path.
 */
final class ComunaCustomerStub {

	/** @var array<string,string> */
	private array $parts;

	/**
	 * @param array<string,string> $parts Address parts keyed `shipping_city`…
	 */
	public function __construct( array $parts = array() ) {
		$this->parts = $parts;
	}

	public function get_billing_country(): string {
		return $this->parts['billing_country'] ?? '';
	}

	public function get_billing_state(): string {
		return $this->parts['billing_state'] ?? '';
	}

	public function get_billing_city(): string {
		return $this->parts['billing_city'] ?? '';
	}

	public function set_billing_city( string $value ): void {
		$this->parts['billing_city'] = $value;
	}

	public function get_shipping_country(): string {
		return $this->parts['shipping_country'] ?? '';
	}

	public function get_shipping_state(): string {
		return $this->parts['shipping_state'] ?? '';
	}

	public function get_shipping_city(): string {
		return $this->parts['shipping_city'] ?? '';
	}

	public function set_shipping_city( string $value ): void {
		$this->parts['shipping_city'] = $value;
	}
}

/**
 * Minimal Business contract stub for commune-focused tests.
 */
final class ComunaCheckoutDataProviderStub implements CheckoutDataProvider {

	public function structured_cart_data(): array {
		return array();
	}

	public function product_availability( WC_Product $product ): array {
		unset( $product );

		return array();
	}

	public function is_valid_rut( string $rut ): bool {
		unset( $rut );

		return true;
	}

	public function format_rut( string $rut ): string {
		return $rut;
	}

	public function phone_validation_error( string $phone ): ?array {
		unset( $phone );

		return null;
	}

	public function address_autocomplete_config(): array {
		return array( 'enabled' => false );
	}
}
