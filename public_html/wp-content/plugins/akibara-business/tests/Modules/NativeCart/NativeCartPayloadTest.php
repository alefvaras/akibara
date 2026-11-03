<?php
/**
 * Customer-visible drawer contracts, independently measured against production.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use Akibara\Business\Modules\NativeCart\NativeCart;
use Akibara\Business\Modules\NativeCart\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class NativeCartPayloadProduct extends \WC_Product {

	public function exists(): bool {
		return true;
	}

	public function get_name(): string {
		return 'Tomo de prueba';
	}

	public function get_image_id(): int {
		return 0;
	}

	public function get_max_purchase_quantity(): int {
		return -1;
	}
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class NativeCartPayloadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( '\WC_Cart' ) ) {
			eval(
				'namespace { class WC_Cart {
					public array $items = array();
					public bool $including_tax = false;
					public function calculate_totals(): void {}
					public function get_discount_total(): float { return 2000.0; }
					public function get_discount_tax(): float { return 380.0; }
					public function display_prices_including_tax(): bool { return $this->including_tax; }
					public function get_cart(): array { return $this->items; }
					public function get_product_subtotal($product, $quantity): string { return "$10000"; }
					public function needs_shipping(): bool { return false; }
					public function get_cart_contents_count(): int { return count($this->items); }
					public function get_cart_subtotal(): string { return "$10000"; }
					public function get_total(): string { return "$8000"; }
				} }'
			);
		}

		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default = false ) {
				if ( in_array( $option, array( Settings::OPTION_SHOW_RECOMMENDATIONS, 'akb_series_bundles_enabled' ), true ) ) {
					return 'no';
				}
				return $default;
			}
		);
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://example.test/tienda/' );
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://example.test/carrito/' );
		Functions\when( 'wc_placeholder_img' )->justReturn( '<img alt="" />' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'drawer-test-nonce' );
		Functions\when( 'wc_price' )->alias( static fn( $amount ): string => '$' . (string) round( $amount ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int,string> $statuses Stock status per product.
	 * @return array<string,mixed>
	 */
	private function payload( array $statuses = array( 'onbackorder' ), bool $including_tax = false ): array {
		$cart = new \WC_Cart();
		$cart->including_tax = $including_tax;
		foreach ( $statuses as $index => $status ) {
			$product                    = new NativeCartPayloadProduct( $status );
			$product->akb_id             = 100 + $index;
			$product->akb_regular_price  = '10000';
			$cart->items[ 'line-' . $index ] = array(
				'data'              => $product,
				'product_id'        => 100 + $index,
				'quantity'          => 1,
				'line_subtotal'     => 10000,
				'line_subtotal_tax' => 1900,
				'line_total'        => 8000,
				'line_tax'          => 1520,
			);
		}
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );
		$payload = array();
		Functions\expect( 'wp_send_json_success' )->once()->andReturnUsing(
			static function ( array $data ) use ( &$payload ): void {
				$payload = $data;
			}
		);

		( new NativeCart() )->ajax_get_cart();
		return $payload;
	}

	/**
	 * @testdox Should retain the drawer availability label and an empty ETA when no renderer is injected.
	 */
	public function test_item_availability_contract(): void {
		$payload = $this->payload();
		self::assertSame( 'A pedido', $payload['items'][0]['stock_label'] ?? null );
		self::assertArrayHasKey( 'eta', $payload['items'][0] );
		self::assertSame( '', $payload['items'][0]['eta'] );
	}

	/**
	 * @testdox Should show the calculated discounted line amount in the drawer before tax.
	 */
	public function test_discounted_line_uses_the_calculated_total(): void {
		$payload = $this->payload();
		self::assertSame( '<del>$10000</del> <ins>$8000</ins>', $payload['items'][0]['price_html'] );
	}

	/**
	 * @testdox Should include calculated line tax when WooCommerce displays tax-inclusive prices.
	 */
	public function test_discounted_line_includes_its_calculated_tax(): void {
		$payload = $this->payload( array( 'onbackorder' ), true );
		self::assertSame( '<del>$11900</del> <ins>$9520</ins>', $payload['items'][0]['price_html'] );
	}

	/**
	 * @testdox Should flag only actual mixtures of immediate stock and reservations.
	 * @dataProvider cart_compositions
	 * @param array<int,string> $statuses Stock status per product.
	 */
	public function test_mixed_delivery_flag_matches_cart_composition( array $statuses, bool $mixed ): void {
		self::assertSame( $mixed, $this->payload( $statuses )['has_preventa'] );
	}

	/** @return array<string,array{0:array<int,string>,1:bool}> */
	public static function cart_compositions(): array {
		return array(
			'only reservations' => array( array( 'onbackorder' ), false ),
			'only ready stock'  => array( array( 'instock' ), false ),
			'mixed delivery'    => array( array( 'instock', 'onbackorder' ), true ),
			'empty cart'        => array( array(), false ),
		);
	}
}
