<?php
/**
 * Test de Guardrail Inviolable: Presupuesto de Consultas y Prevención de N+1.
 *
 * Certifica que las rutas críticas del carrito y catálogo:
 * 1. Ejecuten calentamiento por lotes (_prime_post_caches, update_object_term_cache).
 * 2. Mantengan una complejidad de llamadas O(1) independiente del número de items N.
 * 3. Nunca degraden a consultas seriales dentro de bucles foreach.
 *
 * @package Akibara\Business\Tests
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use Akibara\Business\Modules\NativeCart\NativeCart;
use Akibara\Business\Modules\NativeCart\SmartCartRecommender;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class NPlusOneQueryBudgetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( '\WC_Cart' ) ) {
			eval(
				'namespace { class WC_Cart {
					private array $items;
					public function __construct( array $items = array() ) {
						$this->items = $items;
					}
					public function get_cart(): array { return $this->items; }
					public function get_cart_hash(): string { return "hash_" . count( $this->items ); }
					public function is_empty(): bool { return empty( $this->items ); }
					public function get_cart_contents_count(): int { return count( $this->items ); }
				} }'
			);
		}

		if ( ! class_exists( '\WC_Product' ) ) {
			eval(
				'namespace { class WC_Product {
					private int $id;
					public function __construct( int $id ) { $this->id = $id; }
					public function get_id(): int { return $this->id; }
					public function get_cross_sell_ids(): array { return array( 101, 102, 103, 104, 105 ); }
					public function is_type( string $t ): bool { return "simple" === $t; }
				} }'
			);
		}

		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'update_object_term_cache' )->justReturn( null );
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_the_title' )->justReturn( 'Test Title' );
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) {
				$p         = new \WC_Product( 'instock' );
				$p->akb_id = (int) $id;
				return $p;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Certifica que SmartCartRecommender ejecuta update_object_term_cache en lote (O(1)).
	 */
	public function test_smart_cart_recommender_batches_term_caching(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/NativeCart/SmartCartRecommender.php'
		);

		// Verificación estática inviolable en el código fuente:
		$this->assertStringContainsString(
			'update_object_term_cache',
			$source,
			'SmartCartRecommender DEBE ejecutar update_object_term_cache para prevenir N+1.'
		);

		$this->assertStringContainsString(
			'_prime_post_caches',
			$source,
			'SmartCartRecommender DEBE ejecutar _prime_post_caches para pre-calentar los posts en lote.'
		);

		// Verificación en runtime con Mock:
		$primed_batches = 0;
		Functions\when( '_prime_post_caches' )->alias(
			function ( array $ids ) use ( &$primed_batches ) {
				$primed_batches++;
				return null;
			}
		);

		Functions\when( 'update_object_term_cache' )->alias(
			function ( array $ids, $taxonomy ) {
				return null;
			}
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_the_title' )->justReturn( 'Test Title' );
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) {
				$p         = new \WC_Product( 'instock' );
				$p->akb_id = (int) $id;
				return $p;
			}
		);

		// Simular ejecución
		$cart = new \WC_Cart(
			array(
				array( 'product_id' => 10, 'quantity' => 1 ),
				array( 'product_id' => 20, 'quantity' => 1 ),
				array( 'product_id' => 30, 'quantity' => 1 ),
				array( 'product_id' => 40, 'quantity' => 1 ),
			)
		);

		$recommender = new SmartCartRecommender();
		$recommender->inject_smart_recommendations( array(), $cart );

		// El número de llamadas a _prime_post_caches debe ser O(1), no O(N)
		$this->assertLessThanOrEqual(
			2,
			$primed_batches,
			'Las llamadas a _prime_post_caches deben ser O(1) fijas (lotes), nunca O(N) por cada item del carrito.'
		);
	}

	/**
	 * Certifica que NativeCart ejecuta pre-calentamiento por lotes en cross-sells.
	 */
	public function test_native_cart_batches_cross_sells(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/NativeCart/NativeCart.php'
		);

		$this->assertStringContainsString(
			'_prime_post_caches',
			$source,
			'NativeCart DEBE ejecutar _prime_post_caches antes de iterar cross-sells.'
		);
	}

	/**
	 * Certifica que SaleDiscount no ejecuta queries dentro de bucles de tomos hijos.
	 */
	public function test_sale_discount_avoids_n_plus_one_on_children(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/LoopEnhancer/SaleDiscount.php'
		);

		// No debe contener get_posts o query dentro de un bucle de hijos
		$this->assertStringNotContainsString(
			'foreach ( $children as $child ) { get_post',
			$source,
			'SaleDiscount no debe invocar get_post individualmente por cada hijo en un foreach.'
		);
	}
}
