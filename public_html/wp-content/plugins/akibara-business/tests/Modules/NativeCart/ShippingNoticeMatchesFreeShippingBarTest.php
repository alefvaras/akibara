<?php
/**
 * R3-2cf1a611 (2026-09-19): el aviso de envío del drawer y la barra de envío
 * gratis tienen que decir lo mismo sobre el MISMO carrito.
 *
 * `ajax_get_cart()` decidía el texto SOLO con `get_shipping_total() > 0`, que
 * vale 0 tanto para "envío gratis" como para "aún no calculado". Un carrito
 * calificado por el umbral del transportista (el mecanismo vivo en
 * producción — las instancias core `free_shipping` de las zonas están
 * apagadas a propósito) podía leer a la vez, en el mismo cajón: la barra
 * diciendo "¡Listo!" y el aviso diciendo "el envío se calcula al finalizar
 * la compra".
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use Akibara\Business\Modules\NativeCart\NativeCart;
use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * `NativeCart::detect_cart_flags()` exige el tipo `\WC_Cart` exacto (no hay
 * stub compartido en `tests/wp-stubs.php`, a diferencia de `WC_Product`), así
 * que el doble se declara con `eval()` como el resto de la suite que necesita
 * uno — de ahí `@runTestsInSeparateProcesses`: dos ficheros declarando la
 * misma clase global en el mismo proceso revientan con "Cannot redeclare".
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ShippingNoticeMatchesFreeShippingBarTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( '\WC_Cart' ) ) {
			eval(
				'namespace { class WC_Cart {
					private float $shipping_total;
					public function __construct( float $shipping_total ) {
						$this->shipping_total = $shipping_total;
					}
					public function calculate_totals(): void {}
					public function get_discount_total(): float { return 0.0; }
					public function display_prices_including_tax(): bool { return false; }
					public function get_cart(): array { return array(); }
					public function needs_shipping(): bool { return true; }
					public function get_shipping_total(): float { return $this->shipping_total; }
					public function get_cart_contents_count(): int { return 0; }
					public function get_cart_subtotal(): string { return "$0"; }
					public function get_total(): string { return "$0"; }
				} }'
			);
		}

		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.cl/tienda/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-test' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string,mixed> El payload capturado de `wp_send_json_success()`.
	 */
	private function runAjaxGetCart( float $shipping_total ): array {
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => new \WC_Cart( $shipping_total ) ) );

		$captured = array();
		Functions\expect( 'wp_send_json_success' )
			->once()
			->andReturnUsing(
				static function ( array $data ) use ( &$captured ): void {
					$captured = $data;
				}
			);

		( new NativeCart() )->ajax_get_cart();

		return $captured;
	}

	/**
	 * @testdox El aviso dice "incluido" cuando el carrito YA calificó para envío gratis, aunque `get_shipping_total()` siga en 0.
	 */
	public function test_notice_matches_the_bar_when_qualified_via_carrier_threshold(): void {
		Filters\expectApplied( 'akb_native_cart_fsp_qualified' )->once()->andReturn( true );

		$payload = $this->runAjaxGetCart( 0.0 );

		self::assertSame(
			'Envío incluido en el total. Impuestos y descuentos finales al pagar.',
			$payload['shipping_notice'],
			'La misma regla que pinta "¡Listo!" en la barra tiene que gobernar también este aviso.'
		);
	}

	/**
	 * @testdox Sin ninguna señal de gratuidad, el aviso se queda con el texto por defecto.
	 */
	public function test_notice_keeps_the_default_copy_when_nothing_is_qualified(): void {
		Filters\expectApplied( 'akb_native_cart_fsp_qualified' )->once()->andReturn( false );

		$payload = $this->runAjaxGetCart( 0.0 );

		self::assertSame( 'El envío se calcula al finalizar la compra.', $payload['shipping_notice'] );
	}

	/**
	 * @testdox Con una tarifa de envío real ya cobrada (`shipping_total > 0`) el aviso sigue funcionando igual que antes del arreglo.
	 */
	public function test_notice_still_reacts_to_a_real_shipping_total_without_asking_the_filter(): void {
		// `get_shipping_total() > 0` ya es verdad: el `||` de PHP no evalúa el
		// segundo operando, así que el filtro NO debe dispararse. Si alguien
		// reordena la condición y rompe ese corto-circuito, esta expectativa cae.
		Filters\expectApplied( 'akb_native_cart_fsp_qualified' )->never();

		$payload = $this->runAjaxGetCart( 4990.0 );

		self::assertSame( 'Envío incluido en el total. Impuestos y descuentos finales al pagar.', $payload['shipping_notice'] );
	}
}
