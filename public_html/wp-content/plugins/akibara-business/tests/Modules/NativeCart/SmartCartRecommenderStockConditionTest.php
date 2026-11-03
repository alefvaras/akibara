<?php
/**
 * Test de condición inteligente para recomendaciones de carrito ($60k / Cross-sells).
 *
 * Reglas de negocio:
 * 1. Con 2 series en el carrito, los siguientes tomos deben INTERCALARSE por serie (round-robin).
 * 2. Si el carrito es 100% entrega inmediata: sugerir ÚNICAMENTE productos en stock físico.
 * 3. Si el carrito ya lleva productos a pedido/preventa: un candidato a pedido SÓLO se acepta si no empeora la llegada del carrito.
 * 4. Si no hay renderer disponible: comportamiento conservador (descartar candidatos a pedido).
 * 5. Evaluación de ETA de candidatos a pedido cacheada por request (máximo 1 cálculo por candidato).
 * 6. Productos sin stock (outofstock) nunca se recomiendan.
 *
 * @package Akibara\Business\Tests
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use Akibara\Business\Modules\EstimatedDelivery\BannerRenderer;
use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\Settings;
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

	private function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/NativeCart/SmartCartRecommender.php' );
	}

	private function create_renderer(): BannerRenderer {
		$tz = new \DateTimeZone( 'America/Santiago' );
		return new BannerRenderer(
			new DateCalculator(
				new Settings(),
				static fn(): int => time(),
				$tz
			)
		);
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
	 * @testdox Carrito inmediato: rechaza candidatos a pedido y acepta en stock físico.
	 */
	public function test_immediate_cart_rejects_backorders_and_accepts_instock(): void {
		$instock_cart_item = new \WC_Product( 'instock' );
		$cart = new \WC_Cart( array(
			array( 'product_id' => 100, 'quantity' => 1, 'data' => $instock_cart_item ),
		) );

		$instock_candidate = new \WC_Product( 'instock' );
		$backorder_candidate = new \WC_Product( 'onbackorder' );

		Functions\when( 'wc_get_product' )->alias( function ( int $id ) use ( $instock_candidate, $backorder_candidate ) {
			if ( 101 === $id ) return $instock_candidate;
			if ( 102 === $id ) return $backorder_candidate;
			return null;
		} );

		$recommender = new SmartCartRecommender();
		$results = $recommender->inject_smart_recommendations( array( 101, 102 ), $cart );

		self::assertContains( 101, $results, 'Candidato en stock físico debe ser aceptado en carrito inmediato.' );
		self::assertNotContains( 102, $results, 'Candidato a pedido debe ser rechazado en carrito inmediato.' );
	}

	/**
	 * @testdox Carrito con a pedido: acepta candidato a pedido que no empeora la llegada del carrito.
	 */
	public function test_cart_with_backorder_accepts_candidate_that_does_not_worsen_eta(): void {
		$cart_backorder_prod = new \WC_Product( 'onbackorder' );
		$cart = new \WC_Cart( array(
			array( 'product_id' => 100, 'quantity' => 1, 'data' => $cart_backorder_prod ),
		) );

		$candidate_prod = new \WC_Product( 'onbackorder' );

		Functions\when( 'wc_get_product' )->alias( function ( int $id ) use ( $cart_backorder_prod, $candidate_prod ) {
			if ( 100 === $id ) return $cart_backorder_prod;
			if ( 101 === $id ) return $candidate_prod;
			return null;
		} );

		\Patchwork\redefine(
			array( BannerRenderer::class, 'summarize_cart' ),
			function ( array $items ) {
				// Carrito actual (1 item) y carrito + candidato (2 items) tienen max_ts 1000 -> NO empeora
				return array(
					'label'  => 'Tu pedido completo llega',
					'value'  => '30 nov – 10 dic',
					'is_tba' => false,
					'nota'   => 'Sale en tanda',
					'max_ts' => 1000,
				);
			}
		);

		$recommender = new SmartCartRecommender( $this->create_renderer() );
		$results = $recommender->inject_smart_recommendations( array( 101 ), $cart );

		self::assertContains( 101, $results, 'Candidato a pedido que no retrasa la llegada debe ser aceptado.' );
	}

	/**
	 * @testdox Carrito con a pedido: rechaza candidato a pedido que retrasa la llegada del carrito.
	 */
	public function test_cart_with_backorder_rejects_candidate_that_worsens_eta(): void {
		$cart_backorder_prod = new \WC_Product( 'onbackorder' );
		$cart = new \WC_Cart( array(
			array( 'product_id' => 100, 'quantity' => 1, 'data' => $cart_backorder_prod ),
		) );

		$candidate_prod = new \WC_Product( 'onbackorder' );

		Functions\when( 'wc_get_product' )->alias( function ( int $id ) use ( $cart_backorder_prod, $candidate_prod ) {
			if ( 100 === $id ) return $cart_backorder_prod;
			if ( 200 === $id ) return $candidate_prod;
			return null;
		} );

		\Patchwork\redefine(
			array( BannerRenderer::class, 'summarize_cart' ),
			function ( array $items ) {
				if ( 1 === count( $items ) ) {
					// Carrito actual (España) llega 10 Dic
					return array(
						'label'  => 'Tu pedido completo llega',
						'value'  => '30 nov – 10 dic',
						'is_tba' => false,
						'nota'   => 'Sale en tanda España',
						'max_ts' => 1000,
					);
				}
				// Carrito + candidato (Argentina) llega 14 Dic -> Empeora
				return array(
					'label'  => 'Tu pedido completo llega',
					'value'  => '8 – 14 dic',
					'is_tba' => false,
					'nota'   => 'Sale en tanda Argentina',
					'max_ts' => 2000,
				);
			}
		);

		$recommender = new SmartCartRecommender( $this->create_renderer() );
		$results = $recommender->inject_smart_recommendations( array( 200 ), $cart );

		self::assertNotContains( 200, $results, 'Candidato a pedido que empeora la fecha máxima debe ser descartado.' );
	}

	/**
	 * @testdox Comportamiento conservador: si no hay renderer disponible, se descartan candidatos a pedido.
	 */
	public function test_conservative_behavior_without_renderer_rejects_backorders(): void {
		$cart_backorder_prod = new \WC_Product( 'onbackorder' );
		$cart = new \WC_Cart( array(
			array( 'product_id' => 100, 'quantity' => 1, 'data' => $cart_backorder_prod ),
		) );

		$candidate_prod = new \WC_Product( 'onbackorder' );

		Functions\when( 'wc_get_product' )->alias( function ( int $id ) use ( $cart_backorder_prod, $candidate_prod ) {
			if ( 100 === $id ) return $cart_backorder_prod;
			if ( 101 === $id ) return $candidate_prod;
			return null;
		} );

		// Sin renderer inyectado y sin Plugin global
		$recommender = new SmartCartRecommender( null );
		$results = $recommender->inject_smart_recommendations( array( 101 ), $cart );

		self::assertNotContains( 101, $results, 'Sin renderer disponible debe descartar candidatos a pedido por seguridad.' );
	}

	/**
	 * @testdox Cache por request: el cálculo de ETA por candidato a pedido se realiza a lo sumo una vez.
	 */
	public function test_candidate_eta_is_cached_per_request(): void {
		$cart_backorder_prod = new \WC_Product( 'onbackorder' );
		$cart = new \WC_Cart( array(
			array( 'product_id' => 100, 'quantity' => 1, 'data' => $cart_backorder_prod ),
		) );

		$candidate_prod = new \WC_Product( 'onbackorder' );

		Functions\when( 'wc_get_product' )->alias( function ( int $id ) use ( $cart_backorder_prod, $candidate_prod ) {
			if ( 100 === $id ) return $cart_backorder_prod;
			if ( 101 === $id ) return $candidate_prod;
			return null;
		} );

		$candidate_call_count = 0;
		\Patchwork\redefine(
			array( BannerRenderer::class, 'summarize_cart' ),
			function ( array $items ) use ( &$candidate_call_count ) {
				if ( count( $items ) > 1 ) {
					$candidate_call_count++;
				}
				return array(
					'label'  => 'Tu pedido completo llega',
					'value'  => '30 nov – 10 dic',
					'is_tba' => false,
					'nota'   => 'Sale en tanda',
					'max_ts' => 1000,
				);
			}
		);

		$recommender = new SmartCartRecommender( $this->create_renderer() );
		// Primera inyección con candidato 101 repetido en la lista de entrada
		$recommender->inject_smart_recommendations( array( 101, 101 ), $cart );
		// Segunda llamada en el mismo request
		$recommender->inject_smart_recommendations( array( 101 ), $cart );

		// El cálculo para el candidato (evaluación combinada) debe ocurrir exactamente 1 vez
		self::assertSame( 1, $candidate_call_count, 'El candidato a pedido no debe recalcularse más de 1 vez.' );
	}

	/**
	 * @testdox Static sanity check: SmartCartRecommender evalúa StockStatus::is_reservable.
	 */
	public function test_detects_cart_backorders(): void {
		$src = $this->source();

		self::assertStringContainsString(
			'$cart_has_backorders = false;',
			$src,
			'Debe inicializar la bandera para detectar si el carro tiene pedidos a espera.'
		);
		self::assertStringContainsString(
			'StockStatus::is_reservable( $cart_prod )',
			$src,
			'Debe evaluar StockStatus::is_reservable sobre los productos del carrito.'
		);
	}
}
