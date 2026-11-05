<?php
/**
 * Pruebas unitarias para el módulo PricingMatrix (Motor Matemático CLP, Guardias y Aliases).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\PricingMatrix;

use Akibara\Business\Modules\PricingMatrix\BatchGuard;
use Akibara\Business\Modules\PricingMatrix\OrderLockGuard;
use Akibara\Business\Modules\PricingMatrix\PricingMath;
use Akibara\Business\Modules\PricingMatrix\PricingMatrixAdminPage;
use Akibara\Business\Modules\PricingMatrix\PricingSelfTest;
use Akibara\Business\Modules\PricingMatrix\PricingSynchronizer;
use Akibara\Business\Modules\PricingMatrix\ReactiveStockPriceHooks;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PricingMatrixTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs(
			array(
				'is_user_logged_in' => false,
				'wp_get_post_terms' => array(),
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox El redondeo comercial a la centena (round_100) con 5% de descuento coincide exactamente con la tabla maestra CLP
	 */
	public function test_canonical_clp_scales_with_round_100(): void {
		$cases = array(
			10600 => 10100,
			10900 => 10400,
			11500 => 10900,
			13500 => 12800,
			14500 => 13800,
			21300 => 20200,
			27500 => 26100,
			29500 => 28000,
		);

		foreach ( $cases as $regular => $expected_sale ) {
			self::assertSame(
				$expected_sale,
				PricingMath::calculate_sale_price( $regular, 5.0, PricingMath::MODE_ROUND_100 ),
				sprintf( 'Falló escala CLP $%d con round_100', $regular )
			);
		}
	}

	/**
	 * @testdox Soporta modos alternativos de redondeo CLP (floor_100, round_50, exact_1 y manual) sin decimales
	 */
	public function test_alternative_rounding_modes_never_produce_decimals(): void {
		self::assertSame( 10000, PricingMath::calculate_sale_price( 10600, 5.0, PricingMath::MODE_FLOOR_100 ) );
		self::assertSame( 10050, PricingMath::calculate_sale_price( 10600, 5.0, PricingMath::MODE_ROUND_50 ) );
		self::assertSame( 10070, PricingMath::calculate_sale_price( 10600, 5.0, PricingMath::MODE_EXACT_1 ) );
		self::assertSame( 9990, PricingMath::calculate_sale_price( 10600, 5.0, PricingMath::MODE_MANUAL, 9990 ) );
		self::assertSame( PricingMath::MODE_ROUND_100, PricingMath::normalize_mode( 'modo-invalido' ) );
	}

	/**
	 * @testdox Expone los aliases globales AKB_Batch_Guard y AKB_Order_Lock_Guard y protege las claves singleton contra duplicación
	 */
	public function test_guards_and_singleton_meta_keys_contract(): void {
		self::assertTrue( class_exists( BatchGuard::class ) );
		self::assertTrue( class_exists( OrderLockGuard::class ) );
		self::assertTrue( class_exists( '\\AKB_Batch_Guard' ) );
		self::assertTrue( class_exists( '\\AKB_Order_Lock_Guard' ) );
		self::assertContains( 'pending', OrderLockGuard::TERMINAL_STATUSES );
		self::assertContains( 'cancelled', OrderLockGuard::TERMINAL_STATUSES );
		self::assertContains( 'failed', OrderLockGuard::TERMINAL_STATUSES );

		$hooks    = new ReactiveStockPriceHooks();
		$excluded = $hooks->exclude_duplicate_meta( array( '_custom_existing' ) );

		self::assertContains( '_akb_onbackorder_at', $excluded );
		self::assertContains( '_akb_pdp_specs_generation', $excluded );
		self::assertContains( 'rank_math_internal_links_processed', $excluded );
		self::assertContains( '_akb_tramo_prev_regular', $excluded );
		self::assertContains( '_akb_tramo_prev_sale', $excluded );
	}

	/**
	 * @testdox El cálculo del precio promedio ponderado redondea a la centena más cercana (round_100)
	 */
	public function test_average_price_rounding(): void {
		// Dorohedoro hipotético: 10 tomos a 29500 y 2 tomos a 36500 = (295000 + 73000)/12 = 30666.6 -> 30700
		$prices = array_merge( array_fill( 0, 10, 29500 ), array_fill( 0, 2, 36500 ) );
		$avg    = (int) ( round( ( array_sum( $prices ) / count( $prices ) ) / 100 ) * 100 );
		self::assertSame( 30700, $avg );

		// 17 tomos a 26500 y 1 tomo a 27500 = (450500 + 27500)/18 = 26555.5 -> 26600
		$prices2 = array_merge( array_fill( 0, 17, 26500 ), array( 27500 ) );
		$avg2    = (int) ( round( ( array_sum( $prices2 ) / count( $prices2 ) ) / 100 ) * 100 );
		self::assertSame( 26600, $avg2 );
	}

	/**
	 * @testdox OrderLockGuard aplica Fail-Closed y lanza RuntimeException si la base de datos reporta un error
	 */
	public function test_order_lock_guard_fail_closed_on_db_error(): void {
		global $wpdb;
		$orig_wpdb = $wpdb ?? null;

		$mock_wpdb = new class() {
			public string $prefix = 'wp_';
			public string $postmeta = 'wp_postmeta';
			public string $posts = 'wp_posts';
			public string $last_error = 'Simulated DB Error';

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function get_results( $query, $output = null ) {
				return false;
			}

			public function get_var( $query ) {
				return null;
			}
		};

		$wpdb = $mock_wpdb;

		try {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessageMatches( '/Fail-Closed|Error al consultar órdenes bloqueadas/' );
			OrderLockGuard::get_locked_orders_audit();
		} finally {
			$wpdb = $orig_wpdb;
		}
	}

	/**
	 * @testdox OrderLockGuard aplica Fail-Closed cuando wpdb devuelve array vacío pero last_error no está vacío
	 */
	public function test_order_lock_guard_fail_closed_on_empty_array_with_last_error(): void {
		global $wpdb;
		$orig_wpdb = $wpdb ?? null;

		$mock_wpdb = new class() {
			public string $prefix = 'wp_';
			public string $postmeta = 'wp_postmeta';
			public string $posts = 'wp_posts';
			public string $last_error = 'WordPress DB error: MySQL server has gone away';

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function get_results( $query, $output = null ) {
				return array(); // Simula fallo donde wpdb devuelve array vacío pero reporta last_error
			}

			public function get_var( $query ) {
				return null;
			}
		};

		$wpdb = $mock_wpdb;

		try {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessageMatches( '/Error al consultar órdenes bloqueadas/' );
			OrderLockGuard::get_locked_orders_audit();
		} finally {
			$wpdb = $orig_wpdb;
		}
	}

	/**
	 * @testdox OrderLockGuard aplica Fail-Closed en consulta de stock negativo cuando last_error no está vacío
	 */
	public function test_order_lock_guard_fail_closed_on_negative_stock_last_error(): void {
		global $wpdb;
		$orig_wpdb = $wpdb ?? null;

		$mock_wpdb = new class() {
			public string $prefix = 'wp_';
			public string $postmeta = 'wp_postmeta';
			public string $posts = 'wp_posts';
			public string $last_error = '';

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function get_results( $query, $output = null ) {
				return array(); // get_locked_orders_audit pasa sin error
			}

			public function get_col( $query, $x = 0 ) {
				$this->last_error = 'Table postmeta was crashed';
				return array();
			}

			public function get_var( $query ) {
				return null;
			}
		};

		$wpdb = $mock_wpdb;

		try {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessageMatches( '/Error al consultar productos con stock negativo/' );
			OrderLockGuard::get_locked_product_ids();
		} finally {
			$wpdb = $orig_wpdb;
		}
	}

	/**
	 * @testdox PricingSelfTest aborta inmediatamente en entorno de producción viva para prevenir mutaciones
	 */
	public function test_pricing_self_test_refuses_to_run_in_production(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		$res = PricingSelfTest::run_all();
		self::assertFalse( $res['ok'] );
		self::assertSame( 0, $res['passed_count'] );
		self::assertSame( 0, $res['total_count'] );
		self::assertStringContainsString( 'producción viva', $res['error'] );
	}

	/**
	 * @testdox El rescate en vivo rechaza ejecución sin autorización explícita de distribuidora (Reglas 15 y 18)
	 */
	public function test_rescue_outofstock_rejects_live_without_distributor_auth(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/Reglas 15 y 18/' );
		PricingSynchronizer::rescue_outofstock_products( array( 1001 ), false, array() );
	}

	/**
	 * @testdox El rescate en vivo rechaza distribuidora no autorizada según Reglas 15 y 18
	 */
	public function test_rescue_outofstock_rejects_unapproved_supplier(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/distribuidora oficial autorizada/i' );
		PricingSynchronizer::rescue_outofstock_products(
			array( 1001 ),
			false,
			array(
				'supplier'  => 'distribuidor-no-autorizado',
				'reference' => 'Factura-999',
				'confirmed' => true,
			)
		);
	}

	/**
	 * @testdox El rescate en simulación (dry-run) no requiere distribuidora obligatoria
	 */
	public function test_rescue_outofstock_allows_dry_run_without_distributor_auth(): void {
		global $wpdb;
		$orig_wpdb = $wpdb ?? null;

		$mock_wpdb = new class() {
			public string $prefix = 'wp_';
			public string $postmeta = 'wp_postmeta';
			public string $posts = 'wp_posts';
			public string $last_error = '';

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function get_results( $query, $output = null ) {
				return array();
			}

			public function get_col( $query, $x = 0 ) {
				return array();
			}

			public function get_var( $query ) {
				return null;
			}
		};

		$wpdb = $mock_wpdb;

		try {
			$res = PricingSynchronizer::rescue_outofstock_products( array(), true );
			self::assertTrue( $res['dry_run'] );
			self::assertSame( 0, $res['rescued_count'] );
		} finally {
			$wpdb = $orig_wpdb;
		}
	}

	/**
	 * @testdox ScaleScanner::get_product_ids_for_scale acota la consulta a pa_serie cuando se especifica serie_id
	 */
	public function test_get_product_ids_for_scale_with_serie_id(): void {
		global $wpdb;
		$orig_wpdb = $wpdb ?? null;

		$mock_wpdb = new class() {
			public string $prefix = 'wp_';
			public string $postmeta = 'wp_postmeta';
			public string $posts = 'wp_posts';
			public string $term_relationships = 'wp_term_relationships';
			public string $term_taxonomy = 'wp_term_taxonomy';
			public string $last_error = '';
			public string $captured_query = '';

			public function prepare( $query, ...$args ) {
				$this->captured_query = $query;
				return vsprintf( str_replace( '%d', '%s', $query ), $args );
			}

			public function get_col( $query, $x = 0 ) {
				return array( '101', '102' );
			}
		};

		$wpdb = $mock_wpdb;

		try {
			$ids = \Akibara\Business\Modules\PricingMatrix\ScaleScanner::get_product_ids_for_scale( 230, 21500, 555 );
			self::assertSame( array( 101, 102 ), $ids );
			self::assertStringContainsString( "tt_serie.taxonomy = 'pa_serie'", $mock_wpdb->captured_query );
			self::assertStringContainsString( 'tt_serie.term_id = %d', $mock_wpdb->captured_query );

			// Consulta sin serie_id
			$ids_all = \Akibara\Business\Modules\PricingMatrix\ScaleScanner::get_product_ids_for_scale( 230, 21500, 0 );
			self::assertSame( array( 101, 102 ), $ids_all );
			self::assertStringNotContainsString( 'tt_serie', $mock_wpdb->captured_query );
		} finally {
			$wpdb = $orig_wpdb;
		}
	}

	/**
	 * @testdox PricingSynchronizer::sync_scale preserva serie_id en el reporte de auditoría
	 */
	public function test_sync_scale_preserves_serie_id_in_summary(): void {
		global $wpdb;
		$orig_wpdb = $wpdb ?? null;

		$mock_wpdb = new class() {
			public string $prefix = 'wp_';
			public string $postmeta = 'wp_postmeta';
			public string $posts = 'wp_posts';
			public string $term_relationships = 'wp_term_relationships';
			public string $term_taxonomy = 'wp_term_taxonomy';
			public string $last_error = '';

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function get_results( $query, $output = null ) {
				return array();
			}

			public function get_col( $query, $x = 0 ) {
				return array();
			}

			public function get_var( $query ) {
				return null;
			}
		};

		$wpdb = $mock_wpdb;

		try {
			$summary = PricingSynchronizer::sync_scale(
				230,
				'ivrea-argentina',
				21500,
				22500,
				5.0,
				PricingMath::MODE_ROUND_100,
				null,
				false, // protect_active_orders = false
				true,  // dry_run = true
				777    // serie_id
			);

			self::assertSame( 777, $summary['serie_id'] );
			self::assertSame( 230, $summary['brand_id'] );
			self::assertSame( 22500, $summary['new_regular'] );
			self::assertSame( 21400, $summary['target_sale'] );
			self::assertTrue( $summary['dry_run'] );
		} finally {
			$wpdb = $orig_wpdb;
		}
	}

	/**
	 * @testdox ReactiveStockPriceHooks incluye _akb_out_of_stock_at en las claves singleton excluidas
	 */
	public function test_excluded_duplicate_meta_keys_includes_out_of_stock_at(): void {
		self::assertContains( '_akb_out_of_stock_at', ReactiveStockPriceHooks::EXCLUDED_DUPLICATE_META_KEYS );
		self::assertContains( '_akb_onbackorder_at', ReactiveStockPriceHooks::EXCLUDED_DUPLICATE_META_KEYS );
	}

	/**
	 * @testdox PricingMatrixAdminPage define la constante de acción AJAX para guardado rápido
	 */
	public function test_pricing_matrix_admin_page_defines_ajax_action(): void {
		self::assertSame( 'akb_pricing_matrix_apply_scale_ajax', PricingMatrixAdminPage::ACTION_APPLY_SCALE_AJAX );
	}

	/**
	 * @testdox sync_scale respeta el candado manual _akb_manual_sale_lock en productos onbackorder
	 */
	public function test_sync_scale_respects_manual_sale_lock_on_backorder(): void {
		Functions\when( 'get_post_meta' )->alias( function( $id, $key, $single = false ) {
			if ( '_akb_eta_type' === $key ) {
				return 'auto';
			}
			if ( '_akb_manual_sale_lock' === $key ) {
				return 'yes';
			}
			return '';
		} );

		$product = new class() extends \WC_Product {
			public function __construct() {
				parent::__construct( 'onbackorder' );
				$this->akb_id = 999;
				$this->akb_regular_price = '10600';
				$this->akb_sale_price = '8900'; // Manual sale price previa
			}
			public function get_name(): string { return 'Manga Manual Lock'; }
		};

		Functions\when( 'wc_get_product' )->alias( function( $id ) use ( $product ) {
			return $product;
		} );

		$res = PricingSynchronizer::apply_product_price(
			999,
			10600,
			10100, // target_backorder_sale calculada por la escala (5% estándar)
			false, // protect_active_orders
			array(),
			array(),
			true  // dry_run
		);

		// Debe mantener 8900 (precio manual del dueño) y NO sobreescribirlo a 10100
		self::assertSame( 8900, $res['new_sale'] );
	}

	/**
	 * @testdox rescue_outofstock_products soporta porcentaje de reserva personalizado (grupal) e individual con manual lock
	 */
	public function test_rescue_outofstock_products_supports_custom_discount_and_individual_overrides(): void {
		global $wpdb;
		$orig_wpdb = $wpdb ?? null;

		$mock_wpdb = new class() {
			public string $prefix             = 'wp_';
			public string $postmeta           = 'wp_postmeta';
			public string $posts              = 'wp_posts';
			public string $term_relationships = 'wp_term_relationships';
			public string $term_taxonomy      = 'wp_term_taxonomy';
			public string $last_error         = '';

			public function prepare( $query, ...$args ) {
				return $query;
			}
			public function get_results( $query, $output = null ) {
				return array();
			}
			public function get_col( $query, $x = 0 ) {
				return array();
			}
			public function get_var( $query ) {
				return '0';
			}
		};

		$wpdb = $mock_wpdb;

		try {
			Functions\when( 'wc_get_product' )->alias( function( $id ) {
				$product = new class( $id ) extends \WC_Product {
					public function __construct( int $id ) {
						parent::__construct( 'outofstock' );
						$this->akb_id = $id;
						$this->akb_sku = 'SKU-' . $id;
						$this->akb_regular_price = '10600';
					}
					public function get_name(): string {
						return 'Manga #' . $this->akb_id;
					}
				};
				return $product;
			} );

			Functions\when( 'get_post_meta' )->alias( function( $id, $key, $single = false ) {
				if ( '_akb_eta_type' === $key ) {
					return 'auto';
				}
				return '';
			} );

			$summary = PricingSynchronizer::rescue_outofstock_products(
				array( 101, 102 ),
				true, // dry_run
				array(
					'supplier'  => 'ivrea-argentina',
					'reference' => 'Planilla test',
					'confirmed' => true,
				),
				10.0, // 10% grupal
				PricingMath::MODE_ROUND_100,
				array(
					101 => array( 'sale_price' => 8900 ), // Individual manual
				)
			);

			self::assertTrue( $summary['dry_run'] );
			self::assertSame( 2, $summary['rescued_count'] );
			self::assertSame( 10.0, $summary['discount_pct'] );

			// Tomo 101: override individual a $8.900
			self::assertSame( 101, $summary['items'][0]['product_id'] );
			self::assertSame( 8900, $summary['items'][0]['sale_price'] );
			self::assertTrue( $summary['items'][0]['manual_locked'] );

			// Tomo 102: 10% grupal con round_100 ($10.600 -> $9.500)
			self::assertSame( 102, $summary['items'][1]['product_id'] );
			self::assertSame( 9500, $summary['items'][1]['sale_price'] );
			self::assertFalse( $summary['items'][1]['manual_locked'] );
		} finally {
			$wpdb = $orig_wpdb;
		}
	}
}
