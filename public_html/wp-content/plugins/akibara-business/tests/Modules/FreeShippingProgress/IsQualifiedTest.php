<?php
/**
 * R3-2cf1a611 (2026-09-19): `is_qualified()` es la fuente única que otras
 * superficies (hoy `NativeCart::ajax_get_cart()`, vía el filtro
 * `akb_native_cart_fsp_qualified`) consultan para saber si YA corresponde
 * decir "envío gratis" sobre este carrito, en vez de reinventar la pregunta
 * con su propio proxy. Tiene que coincidir exactamente con el estado que la
 * barra (`structured_state()`) ya calcula.
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
final class IsQualifiedTest extends TestCase {

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
			eval( 'namespace { function wc_get_price_decimals(): int { return 0; } }' );
		}
		if ( ! function_exists( 'wc_price' ) ) {
			eval(
				'namespace { function wc_price( $amount ): string {
					return "<span><bdi>$" . number_format( (float) $amount, 0, ",", "." ) . "</bdi></span>";
				} }'
			);
		}
		if ( ! function_exists( 'wc_shipping_enabled' ) ) {
			eval( 'namespace { function wc_shipping_enabled(): bool { return true; } }' );
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

	protected function tearDown(): void {
		unset(
			$GLOBALS['akibara_test_options'],
			$GLOBALS['akibara_test_woocommerce'],
			$GLOBALS['akibara_test_shipping_zone']
		);

		parent::tearDown();
	}

	/**
	 * @testdox Debajo del umbral, `is_qualified()` dice que no — igual que la barra.
	 */
	public function test_is_not_qualified_below_the_threshold(): void {
		// `is_available()` refleja lo que WooCommerce YA decidió sobre el
		// `min_amount` real del método (55.000): con 50.000 el método core no
		// está disponible, igual que en producción.
		$this->configure_runtime( 50000.0, false );

		self::assertFalse( ( new FreeShippingProgress() )->is_qualified() );
	}

	/**
	 * @testdox En o sobre el umbral del transportista, `is_qualified()` dice que sí — igual que la barra.
	 */
	public function test_is_qualified_at_the_carrier_threshold(): void {
		$this->configure_runtime( 60000.0, true );

		self::assertTrue( ( new FreeShippingProgress() )->is_qualified() );
	}

	private function configure_runtime( float $subtotal, bool $available ): void {
		$GLOBALS['akibara_test_options'] = array(
			'akibara_fsp_enabled'       => 'yes',
			'akibara_fsp_show_cart'     => 'yes',
			'akibara_fsp_show_checkout' => 'yes',
		);

		$GLOBALS['akibara_test_shipping_zone'] = new IsQualifiedZone(
			new IsQualifiedFreeShippingMethod( $available )
		);

		$rate     = new IsQualifiedRate( 4990.0, 'Envío a domicilio' );
		$packages = array(
			array(
				'rates' => array( 'bluex-ex:1' => $rate ),
			),
		);

		$GLOBALS['akibara_test_woocommerce'] = (object) array(
			'cart'     => new IsQualifiedCart( $subtotal ),
			'customer' => new IsQualifiedCustomer(),
			'session'  => new IsQualifiedSession(),
			'shipping' => new IsQualifiedShipping( $packages ),
		);
	}
}

final class IsQualifiedCart {

	private float $subtotal;

	public function __construct( float $subtotal ) {
		$this->subtotal = $subtotal;
	}

	public function needs_shipping(): bool {
		return true;
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

final class IsQualifiedFreeShippingMethod {

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

final class IsQualifiedZone {

	private IsQualifiedFreeShippingMethod $method;

	public function __construct( IsQualifiedFreeShippingMethod $method ) {
		$this->method = $method;
	}

	/**
	 * @return array<int,IsQualifiedFreeShippingMethod>
	 */
	public function get_shipping_methods( bool $enabled_only ): array {
		unset( $enabled_only );

		return array( $this->method );
	}
}

final class IsQualifiedCustomer {

	public function has_shipping_address(): bool {
		return true;
	}
}

final class IsQualifiedSession {

	/**
	 * @return array<int,string>|null
	 */
	public function get( string $key ): ?array {
		return 'chosen_shipping_methods' === $key ? array( 'bluex-ex:1' ) : null;
	}
}

final class IsQualifiedShipping {

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

final class IsQualifiedRate {

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
