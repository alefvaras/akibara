<?php
/**
 * Tests for the structured free-shipping presentation state.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\FreeShippingProgress;

use Akibara\Business\Modules\FreeShippingProgress\FreeShippingProgress;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class StructuredStateTest extends TestCase {

	/**
	 * Loads isolated WooCommerce shipping fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/fixtures/wordpress-functions.php';

		if ( ! function_exists( 'WC' ) ) {
			eval(
				'namespace { function WC() {
					return $GLOBALS["akibara_test_woocommerce"];
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
		if ( ! function_exists( 'wc_price' ) ) {
			eval(
				'namespace { function wc_price( $amount ): string {
					return "<span><bdi>$" . number_format( (float) $amount, 0, ",", "." ) . "</bdi></span>";
				} }'
			);
		}
		if ( ! function_exists( 'wc_shipping_enabled' ) ) {
			eval(
				'namespace { function wc_shipping_enabled(): bool {
					return true;
				} }'
			);
		}
		if ( ! function_exists( 'wc_get_shipping_method_count' ) ) {
			eval(
				'namespace { function wc_get_shipping_method_count( bool $include_legacy = false ): int {
					unset( $include_legacy );
					return 3;
				} }'
			);
		}
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			eval(
				'namespace { class WC_Shipping_Zones {
					public static function get_zone_matching_package( array $package ) {
						unset( $package );
						return $GLOBALS["akibara_test_shipping_zone"];
					}
				} }'
			);
		}
	}

	/**
	 * Clears isolated shipping fixtures.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['akibara_test_options'],
			$GLOBALS['akibara_test_woocommerce'],
			$GLOBALS['akibara_test_shipping_zone']
		);

		parent::tearDown();
	}

	/**
	 * @testdox Should expose exact progress numbers and plain text.
	 */
	public function test_progress_state_is_numeric_and_html_free(): void {
		$this->configure_runtime( 50000.0, false );

		$result = ( new FreeShippingProgress() )->structured_state();

		self::assertSame( 'progress', $result['state'] );
		self::assertSame( 'order_amount', $result['reason'] );
		self::assertSame( 55000.0, $result['threshold'] );
		self::assertSame( 50000.0, $result['total'] );
		self::assertSame( 5000.0, $result['remaining'] );
		self::assertSame( 90.91, $result['progress_percent'] );
		self::assertTrue( $result['almost_there'] );
		self::assertStringContainsString( '$55.000', $result['title'] );
		self::assertStringContainsString( '$5.000', $result['message'] );
		self::assertStringNotContainsString( '<', json_encode( $result, JSON_THROW_ON_ERROR ) );
	}

	/**
	 * @testdox Should hide qualified copy when the operator disabled it.
	 */
	public function test_qualified_state_respects_visibility_setting(): void {
		$this->configure_runtime( 60000.0, true );
		$GLOBALS['akibara_test_options']['akibara_fsp_show_qualified'] = 'no';

		$result = ( new FreeShippingProgress() )->structured_state();

		self::assertSame( 'hidden', $result['state'] );
		self::assertSame( 'order_amount', $result['reason'] );
		self::assertFalse( $result['show_qualified'] );
		self::assertSame( 55000.0, $result['threshold'] );
	}

	/**
	 * @testdox Should keep showing progress without a full shipping address.
	 *
	 * Regresión: `cart_ready()` delegaba en `WC_Cart::show_shipping()`, que en el
	 * contexto Blocks exige `has_full_shipping_address()`. Con eso, /carro/ y el
	 * checkout Blocks no mostraban nada hasta que el cliente escribía calle y
	 * ciudad — justo cuando el aviso ya no sirve para motivar la compra. El doble
	 * de carrito devuelve `show_shipping() === false` de forma permanente, así
	 * que este test falla si alguien reintroduce esa dependencia.
	 */
	public function test_progress_does_not_require_a_full_shipping_address(): void {
		$this->configure_runtime( 50000.0, false );

		$result = ( new FreeShippingProgress() )->structured_state();

		self::assertSame( 'progress', $result['state'] );
		self::assertSame( 5000.0, $result['remaining'] );
	}

	private function configure_runtime( float $subtotal, bool $available ): void {
		$GLOBALS['akibara_test_options'] = array(
			'akibara_fsp_enabled'       => 'yes',
			'akibara_fsp_show_cart'     => 'yes',
			'akibara_fsp_show_checkout' => 'yes',
		);

		$GLOBALS['akibara_test_shipping_zone'] = new StructuredStateZone(
			new StructuredStateFreeShippingMethod( $available )
		);

		$rate     = new StructuredStateRate( 4990.0, 'Envío a domicilio' );
		$packages = array(
			array(
				'rates' => array( 'bluex-ex:1' => $rate ),
			),
		);

		$GLOBALS['akibara_test_woocommerce'] = (object) array(
			'cart'     => new StructuredStateCart( $subtotal ),
			'customer' => new StructuredStateCustomer(),
			'session'  => new StructuredStateSession(),
			'shipping' => new StructuredStateShipping( $packages ),
		);
	}
}

final class StructuredStateCart {

	private float $subtotal;

	public function __construct( float $subtotal ) {
		$this->subtotal = $subtotal;
	}

	public function needs_shipping(): bool {
		return true;
	}

	/**
	 * Contexto Blocks: WooCommerce devuelve `false` aquí mientras el cliente no
	 * haya escrito la dirección COMPLETA. La barra no puede depender de eso, así
	 * que el doble lo deja en `false` a propósito: si `cart_ready()` volviera a
	 * consultarlo, estos tests fallarían.
	 */
	public function show_shipping(): bool {
		return false;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function get_cart_contents(): array {
		return array( 'item-key' => array( 'quantity' => 1 ) );
	}

	public function get_shipping_total(): float {
		return 4990.0;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_shipping_packages(): array {
		return array(
			array(
				'destination' => array( 'country' => 'CL' ),
			),
		);
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

	public function get_coupons(): array {
		return array();
	}
}

final class StructuredStateFreeShippingMethod {

	public string $id = 'free_shipping';
	public string $requires = 'min_amount';
	public float $min_amount = 55000.0;
	public string $ignore_discounts = 'no';

	private bool $available;

	public function __construct( bool $available ) {
		$this->available = $available;
	}

	/**
	 * @param array<string,mixed> $package Shipping package.
	 */
	public function is_available( array $package ): bool {
		unset( $package );

		return $this->available;
	}
}

final class StructuredStateZone {

	private StructuredStateFreeShippingMethod $method;

	public function __construct( StructuredStateFreeShippingMethod $method ) {
		$this->method = $method;
	}

	/**
	 * @return array<int,StructuredStateFreeShippingMethod>
	 */
	public function get_shipping_methods( bool $enabled_only ): array {
		unset( $enabled_only );

		return array( $this->method );
	}
}

final class StructuredStateCustomer {

	public function has_shipping_address(): bool {
		return true;
	}
}

final class StructuredStateSession {

	/**
	 * @return array<int,string>|null
	 */
	public function get( string $key ): ?array {
		return 'chosen_shipping_methods' === $key ? array( 'bluex-ex:1' ) : null;
	}
}

final class StructuredStateShipping {

	/** @var array<int,array<string,mixed>> */
	private array $packages;

	/**
	 * @param array<int,array<string,mixed>> $packages Shipping packages.
	 */
	public function __construct( array $packages ) {
		$this->packages = $packages;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_packages(): array {
		return $this->packages;
	}
}

final class StructuredStateRate {

	private float $cost;
	private string $label;

	public function __construct( float $cost, string $label ) {
		$this->cost  = $cost;
		$this->label = $label;
	}

	public function get_cost(): float {
		return $this->cost;
	}

	public function get_label(): string {
		return $this->label;
	}
}
