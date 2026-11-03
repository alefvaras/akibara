<?php
/**
 * Candado: el endpoint del drawer no repite trabajo que WooCommerce ya hizo.
 *
 * Cada mutación del carrito pagaba DOS `calculate_totals()` completos: el que
 * dispara WooCommerce por dentro (`remove_cart_item()` vía
 * `woocommerce_cart_item_removed`, `set_quantity()` directo) y el que
 * `ajax_get_cart()` hacía otra vez al recibir la delegación. Y cada línea del
 * payload resolvía DOS veces la misma URL de miniatura.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use Akibara\Business\Modules\NativeCart\NativeCart;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class RequestEconomyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/NativeCart/NativeCart.php' );
	}

	/**
	 * @testdox El módulo se entera de que los totales ya están calculados.
	 */
	public function test_it_listens_to_woocommerces_own_totals_hook(): void {
		Actions\expectAdded( 'woocommerce_after_calculate_totals' )->once();

		( new NativeCart() )->register();

		self::assertTrue( true, 'La expectativa la verifica Brain Monkey al cerrar.' );
	}

	/**
	 * @testdox El recálculo del handler queda detrás del testigo.
	 */
	public function test_the_recalculation_is_guarded(): void {
		self::assertMatchesRegularExpression(
			'/if \(\s*! \$this->totals_calculated\s*\)\s*\{\s*WC\(\)->cart->calculate_totals\(\);/',
			$this->source(),
			'Sin la guarda, cada mutación del drawer paga dos cálculos completos de totales.'
		);
		self::assertSame(
			1,
			substr_count( $this->source(), 'WC()->cart->calculate_totals();' ),
			'Un segundo recálculo sin guarda devuelve el gasto por otra puerta.'
		);
	}

	/**
	 * @testdox La miniatura de cada línea se resuelve una sola vez.
	 */
	public function test_the_thumbnail_is_resolved_once_per_product(): void {
		$src = $this->source();

		$llamadas = 0;
		foreach ( explode( "\n", $src ) as $linea ) {
			$linea = ltrim( $linea );
			if ( '' === $linea || str_starts_with( $linea, '*' ) || str_starts_with( $linea, '//' ) || str_starts_with( $linea, '/*' ) ) {
				continue;
			}
			$llamadas += substr_count( $linea, 'wp_get_attachment_image_url(' );
		}

		self::assertSame(
			1,
			$llamadas,
			'Las dos claves del payload (`thumbnail` y `thumbnail_url`) tienen que salir del mismo resultado.'
		);
		self::assertStringContainsString( 'private function thumbnail_url( \WC_Product $product ): string', $src );
	}

	/**
	 * @testdox La rama que MUTA la cola de avisos vive separada de la lectura.
	 */
	public function test_the_mutating_branch_is_its_own_method(): void {
		$src = $this->source();

		self::assertStringContainsString( 'private function drain_error_notices(): array', $src );
		self::assertMatchesRegularExpression(
			'/\$pide_drenaje \? \$this->drain_error_notices\(\) : array\(\)/',
			$src,
			'El drenaje tiene que seguir siendo opt-in: drenar sin que nadie lo pinte deja al cliente sin explicación.'
		);
		self::assertStringNotContainsString(
			'lectura idempotente del propio carrito de la sesión',
			$src,
			'La justificación del phpcs:ignore describía como idempotente una rama que vacía la cola de avisos.'
		);
	}

	/**
	 * @testdox El payload no transporta el `cart_hash` que nadie leía.
	 */
	public function test_the_unread_cart_hash_is_gone(): void {
		self::assertStringNotContainsString(
			"'cart_hash'",
			$this->source(),
			'Viajaba en cada respuesta bajo el rótulo «Performance Optimization» sin un solo consumidor en el JS.'
		);
	}

	/**
	 * @testdox El contador del carrito alcanza a los activadores configurados.
	 */
	public function test_the_counter_reaches_configured_triggers(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/native-cart.js' );

		self::assertStringContainsString( 'function countTargetSelector()', $js );
		self::assertMatchesRegularExpression(
			'/querySelectorAll\(countTargetSelector\(\)\)/',
			$js,
			'Con `.akb-cart-trigger` en duro, un activador con otra clase abría el carrito y jamás mostraba su contador.'
		);
	}
}
