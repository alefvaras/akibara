<?php
/**
 * Test de condición inteligente para recomendaciones de carrito ($60k / Cross-sells).
 *
 * Reglas de negocio actuales (catálogo unificado solo instock / outofstock):
 * 1. Con 2 series en el carrito, los siguientes tomos deben INTERCALARSE por serie (round-robin).
 * 2. Catálogo 100% entrega inmediata: sugerir ÚNICAMENTE productos en stock físico comprables.
 * 3. Productos sin stock (outofstock) y productos a pedido/reservables nunca se recomiendan.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use Akibara\Business\Modules\NativeCart\SmartCartRecommender;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SmartCartRecommenderStockConditionTest extends TestCase {

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
					public function get_cart_hash(): string { return "test_cart_hash_" . count( $this->items ); }
				} }'
			);
		}

		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'update_object_term_cache' )->justReturn( null );
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_the_title' )->justReturn( 'Test Title 01' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Round-robin: con 2 series en el carrito, los siguientes tomos se intercalan por serie.
	 */
	public function test_round_robin_interleaving_with_two_series(): void {
		global $wpdb;
		$wpdb = new class() {
			public string $posts = 'wp_posts';
			public string $term_relationships = 'wp_term_relationships';
			public function get_results( string $query, string $output = ARRAY_A ): array {
				return array(
					array( 'term_taxonomy_id' => 10, 'id' => 101, 'title' => 'Dan Da Dan 01' ),
					array( 'term_taxonomy_id' => 10, 'id' => 102, 'title' => 'Dan Da Dan 02' ),
					array( 'term_taxonomy_id' => 10, 'id' => 103, 'title' => 'Dan Da Dan 03' ),
					array( 'term_taxonomy_id' => 10, 'id' => 104, 'title' => 'Dan Da Dan 04' ),
					array( 'term_taxonomy_id' => 20, 'id' => 201, 'title' => 'Black Clover 01' ),
					array( 'term_taxonomy_id' => 20, 'id' => 202, 'title' => 'Black Clover 02' ),
					array( 'term_taxonomy_id' => 20, 'id' => 203, 'title' => 'Black Clover 03' ),
					array( 'term_taxonomy_id' => 20, 'id' => 204, 'title' => 'Black Clover 04' ),
				);
			}
		};

		$recommender = new SmartCartRecommender();
		$ref = new \ReflectionClass( $recommender );
		$method = $ref->getMethod( 'find_next_volume_ids' );
		$method->setAccessible( true );

		$series = array(
			10 => array( 101 => 'Dan Da Dan 01' ),
			20 => array( 201 => 'Black Clover 01' ),
		);
		$owned = array( 101, 201 );

		$result = $method->invoke( $recommender, $series, $owned );

		// Debe intercalar: Dan Da Dan 02, Black Clover 02, Dan Da Dan 03, Black Clover 03, Dan Da Dan 04, Black Clover 04
		self::assertSame(
			array( 102, 202, 103, 203, 104, 204 ),
			$result,
			'Los tomos siguientes deben intercalarse (round-robin) para que una serie no acapare las plazas.'
		);
	}

	/**
	 * @testdox Solo recomienda productos en stock físico comprables (rechaza outofstock y onbackorder).
	 */
	public function test_recommends_strictly_purchasable_instock_products(): void {
		$instock_item = new \WC_Product( 'instock' );
		$cart = new \WC_Cart( array(
			array( 'product_id' => 100, 'quantity' => 1, 'data' => $instock_item ),
		) );

		$instock_candidate    = new \WC_Product( 'instock' );
		$backorder_candidate  = new \WC_Product( 'onbackorder' );
		$outofstock_candidate = new \WC_Product( 'outofstock' );

		Functions\when( 'wc_get_product' )->alias( function ( int $id ) use ( $instock_candidate, $backorder_candidate, $outofstock_candidate ) {
			if ( 101 === $id ) return $instock_candidate;
			if ( 102 === $id ) return $backorder_candidate;
			if ( 103 === $id ) return $outofstock_candidate;
			return null;
		} );

		$recommender = new SmartCartRecommender();
		$results = $recommender->inject_smart_recommendations( array( 101, 102, 103 ), $cart );

		self::assertContains( 101, $results, 'Candidato en stock físico debe ser aceptado.' );
		self::assertNotContains( 102, $results, 'Candidato onbackorder debe ser rechazado.' );
		self::assertNotContains( 103, $results, 'Candidato outofstock debe ser rechazado.' );
	}
}
