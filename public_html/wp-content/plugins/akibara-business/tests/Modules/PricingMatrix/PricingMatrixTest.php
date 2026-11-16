<?php
/**
 * Pruebas unitarias para el módulo PricingMatrix (Motor Matemático CLP, Guardias y Aliases).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\PricingMatrix;

if ( ! class_exists( 'wpdb' ) ) {
	require_once dirname( __DIR__ ) . '/BackInStock/CaptureRestControllerTest.php';
}

use Akibara\Business\Modules\PricingMatrix\BatchGuard;
use Akibara\Business\Modules\PricingMatrix\PricingMath;
use Akibara\Business\Modules\PricingMatrix\PricingMatrixAdminPage;
use Akibara\Business\Modules\PricingMatrix\PricingSelfTest;
use Akibara\Business\Modules\PricingMatrix\PricingSynchronizer;
use Akibara\Business\Modules\PricingMatrix\ReactiveStockPriceHooks;
use Akibara\Business\Modules\SiteSpeed\EdgePurge;
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
	 * @testdox Expone el alias global AKB_Batch_Guard y protege las claves singleton contra duplicación
	 */
	public function test_guards_and_singleton_meta_keys_contract(): void {
		self::assertTrue( class_exists( BatchGuard::class ) );
		self::assertTrue( class_exists( '\\AKB_Batch_Guard' ) );

		$hooks    = new ReactiveStockPriceHooks();
		$excluded = $hooks->exclude_duplicate_meta( array( '_custom_existing' ) );

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
	}

	/**
	 * @testdox PricingMatrixAdminPage define la constante de acción AJAX para guardado rápido
	 */
	public function test_pricing_matrix_admin_page_defines_ajax_action(): void {
		self::assertSame( 'akb_pricing_matrix_apply_scale_ajax', PricingMatrixAdminPage::ACTION_APPLY_SCALE_AJAX );
	}

	/**
	 * @testdox PricingSynchronizer no bloquea productos y permite actualizar precios en cualquier momento
	 */
	public function test_pricing_synchronizer_does_not_block_pricing_updates(): void {
		Functions\when( 'wc_get_product' )->justReturn( null );

		// apply_product_price retorna not_found si el producto no existe sin bloqueos de ordenes
		$res = PricingSynchronizer::apply_product_price(
			999,
			12990,
			0,
			false,
			null,
			null,
			true
		);

		self::assertSame( 'not_found', $res['status'] );
	}

	/**
	 * @testdox Bloque 1: PricingMatrixAdminPage renderiza contador accesible, filtro de pendientes, botón reset y regla content-visibility
	 */
	public function test_pricing_matrix_dom_optimizations_and_filter_ux(): void {
		Functions\stubs(
			array(
				'wp_nonce_field'      => '',
				'sanitize_html_class' => static function ( $class ) {
					return preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $class );
				},
				'esc_attr'            => static fn( $val ) => htmlspecialchars( (string) $val, ENT_QUOTES ),
				'esc_html'            => static fn( $val ) => htmlspecialchars( (string) $val, ENT_QUOTES ),
				'esc_url'             => static fn( $val ) => (string) $val,
				'admin_url'           => static fn( $path = '' ) => 'https://example.test/wp-admin/' . $path,
			)
		);

		// 1. Verificación de regla CSS de alto rendimiento
		$css_path = AKIBARA_BUSINESS_DIR . 'assets/css/pricing-matrix-admin.css';
		self::assertFileExists( $css_path );
		$css = (string) file_get_contents( $css_path );
		self::assertStringContainsString( 'tr.akb-scale-searchable-row', $css );
		self::assertMatchesRegularExpression( '/tr\.akb-scale-searchable-row\s*\{[^}]*content-visibility:\s*auto;/', $css );
		self::assertMatchesRegularExpression( '/contain-intrinsic-size:\s*auto 48px;/', $css );

		// 2. Verificación de elementos DOM y UX en render_tab_scales
		$page   = new PricingMatrixAdminPage();
		$ref    = new \ReflectionClass( $page );
		$method = $ref->getMethod( 'render_tab_scales' );
		$method->setAccessible( true );

		$scales_fixture = array(
			array(
				'brand_id'              => 230,
				'editorial'             => 'Ivrea España',
				'editorial_slug'        => 'ivrea-espana',
				'pais_codigo'           => 'es',
				'escala_precio_regular' => 10600,
				'nuevo_precio_regular'  => 11500, // Hay diferencia con el actual
				'min_sale_actual'       => null,
				'total_tomos'           => 12,
				'en_stock'              => 8,
				'agotados'              => 4,
				'series_count'          => 2,
				'series_names'          => array( 'Chainsaw Man', 'Spy x Family' ),
				'series_items'          => array(),
			),
			array(
				'brand_id'              => 230,
				'editorial'             => 'Ivrea España',
				'editorial_slug'        => 'ivrea-espana',
				'pais_codigo'           => 'es',
				'escala_precio_regular' => 14500,
				'nuevo_precio_regular'  => 14500, // Sin diferencia
				'min_sale_actual'       => null,
				'total_tomos'           => 5,
				'en_stock'              => 5,
				'agotados'              => 0,
				'series_count'          => 1,
				'series_names'          => array( 'Vagabond' ),
				'series_items'          => array(),
			),
		);

		ob_start();
		$method->invoke( $page, $scales_fixture );
		$html = (string) ob_get_clean();

		// Contador de estado accesible
		self::assertStringContainsString( 'id="akb-scale-filter-count"', $html );
		self::assertStringContainsString( 'aria-live="polite"', $html );
		self::assertStringContainsString( 'Mostrando <strong>2</strong> de 2 escalas', $html );

		// Filtro rápido de pendientes y botón de reset
		self::assertStringContainsString( 'id="akb-scale-filter-pending"', $html );
		self::assertStringContainsString( 'Solo con diferencias / pendientes', $html );
		self::assertStringContainsString( 'id="akb-scale-filter-reset"', $html );
		self::assertStringContainsString( 'Limpiar filtros', $html );

		// Atributo data-* de pendientes / diferencias en las filas
		self::assertStringContainsString( 'data-has-difference="1"', $html );
		self::assertStringContainsString( 'data-has-difference="0"', $html );

		// Campos de edición de Precio Normal y Precio Oferta (0 = Sin oferta)
		self::assertStringContainsString( 'Precio Normal ($)', $html );
		self::assertStringContainsString( 'name="new_regular"', $html );
		self::assertStringContainsString( 'akb-pm-quick-step', $html );
		self::assertStringContainsString( 'data-step="-500"', $html );
		self::assertStringContainsString( 'data-step="500"', $html );
		self::assertStringContainsString( 'data-step="1000"', $html );
		self::assertStringContainsString( 'Precio Oferta ($)', $html );
		self::assertStringContainsString( 'name="manual_sale"', $html );
		self::assertStringContainsString( 'placeholder="0 = Sin oferta"', $html );
		self::assertStringContainsString( 'Sin oferta', $html );
	}

	/**
	 * @testdox ScaleScanner::scan_editorial_scales usa consulta SQL agrupada para series y construye series_items
	 */
	public function test_scan_editorial_scales_uses_grouped_series_query(): void {
		global $wpdb;
		$orig_wpdb = $wpdb ?? null;

		$mock_wpdb = new class() {
			public string $prefix = 'wp_';
			public string $posts = 'wp_posts';
			public string $postmeta = 'wp_postmeta';
			public string $termmeta = 'wp_termmeta';
			public string $terms = 'wp_terms';
			public string $term_relationships = 'wp_term_relationships';
			public string $term_taxonomy = 'wp_term_taxonomy';
			public string $last_error = '';
			/** @var list<string> */
			public array $queries = array();

			public function prepare( $query, ...$args ) {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			public function get_results( $query, $output = null ) {
				$this->queries[] = (string) $query;
				if ( false !== strpos( (string) $query, 'AS total_tomos' ) ) {
					return array(
						array(
							'brand_id'              => '230',
							'editorial'             => 'Ivrea Argentina',
							'editorial_slug'        => 'ivrea-argentina',
							'pais_codigo'           => 'ar',
							'escala_precio_regular' => '19500',
							'total_tomos'           => '15',
							'en_stock'              => '10',
							'agotados'              => '5',
							'min_sale_actual'       => null,
							'max_sale_actual'       => null,
						),
					);
				}

				return array(
					array(
						'brand_id'              => '230',
						'escala_precio_regular' => '19500',
						'serie_id'              => '801',
						'serie_name'            => 'Blue Period',
						'serie_count'           => '15',
					),
				);
			}
		};

		$wpdb = $mock_wpdb;
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_title' )->returnArg( 1 );

		try {
			$scales = \Akibara\Business\Modules\PricingMatrix\ScaleScanner::scan_editorial_scales();

			self::assertCount( 1, $scales );
			self::assertSame( 1, $scales[0]['series_count'] );
			self::assertSame( array( 'Blue Period' ), $scales[0]['series_names'] );
			self::assertSame(
				array(
					array(
						'id'    => 801,
						'name'  => 'Blue Period',
						'count' => 15,
					),
				),
				$scales[0]['series_items']
			);
			self::assertCount( 2, $mock_wpdb->queries );
			self::assertStringContainsString( 'COUNT(DISTINCT p.ID) AS serie_count', $mock_wpdb->queries[1] );
			self::assertStringContainsString( 'GROUP BY tt_brand.term_id', $mock_wpdb->queries[1] );
		} finally {
			$wpdb = $orig_wpdb;
		}
	}

	/**
	 * @testdox Soporta fijar 0 para dejar productos sin oferta y ofertas numéricas exactas
	 */
	public function test_sale_price_zero_and_custom_values(): void {
		// 1. Motor matemático: 0 retorna 0 explícito (sin oferta)
		self::assertSame( 0, PricingMath::calculate_sale_price( 10000, 5.0, PricingMath::MODE_ROUND_100, 0 ) );
		self::assertSame( 8000, PricingMath::calculate_sale_price( 10000, 5.0, PricingMath::MODE_ROUND_100, 8000 ) );

		// 2. PricingSynchronizer::compute_target_sale_string vía reflection
		$ref = new \ReflectionClass( PricingSynchronizer::class );
		$method = $ref->getMethod( 'compute_target_sale_string' );
		$method->setAccessible( true );

		// Caso: manual_sale = 0 -> debe retornar cadena vacía '' (elimina oferta en WooCommerce)
		self::assertSame( '', $method->invoke( null, 101, 'instock', 10000, 0, '8500' ) );

		// Caso: manual_sale >= new_regular -> debe retornar '' (oferta inválida/mayor al regular)
		self::assertSame( '', $method->invoke( null, 101, 'instock', 10000, 10000, '8500' ) );
		self::assertSame( '', $method->invoke( null, 101, 'instock', 10000, 12000, '8500' ) );

		// Caso: manual_sale > 0 y < new_regular -> retorna string exacto
		self::assertSame( '8000', $method->invoke( null, 101, 'instock', 10000, 8000, '' ) );

		// Caso: manual_sale = null -> conserva el valor previo
		self::assertSame( '7500', $method->invoke( null, 101, 'instock', 10000, null, '7500' ) );
		self::assertSame( '', $method->invoke( null, 101, 'instock', 10000, null, '' ) );
	}

	public function test_batch_guard_transaction_lifecycle_commit_and_rollback(): void {
		$prev_wpdb      = $GLOBALS['wpdb'] ?? null;
		$prev_wp_filter = $GLOBALS['wp_filter'] ?? null;

		$fake_wpdb = new class() extends \wpdb {
			/** @var list<string> */
			public array $queries = array();

			/**
			 * @return int|false
			 */
			public function query( string $query ) {
				$this->queries[] = $query;
				return 1;
			}
		};

		$GLOBALS['wpdb'] = $fake_wpdb;

		$sentinel_hook         = new \stdClass();
		$GLOBALS['wp_filter']  = array( 'woocommerce_update_product' => $sentinel_hook );
		$cache_deleted         = array();
		$post_caches_cleaned   = array();

		Functions\when( 'wp_cache_delete' )->alias(
			static function ( int $key, string $group = '' ) use ( &$cache_deleted ): bool {
				$cache_deleted[] = array( $key, $group );
				return true;
			}
		);
		Functions\when( 'clean_post_cache' )->alias(
			static function ( int $post_id ) use ( &$post_caches_cleaned ): void {
				$post_caches_cleaned[] = $post_id;
			}
		);

		try {
			// Caso 1: Commit y anidamiento seguro sin ejecutar efectos consolidados cuando no hay IDs modificados.
			$result = BatchGuard::run(
				static function (): string {
					self::assertTrue( BatchGuard::is_active() );
					self::assertArrayNotHasKey( 'woocommerce_update_product', $GLOBALS['wp_filter'] );
					return BatchGuard::run(
						static fn(): string => 'nested_ok'
					);
				}
			);

			self::assertSame( 'nested_ok', $result );
			self::assertFalse( BatchGuard::is_active() );
			self::assertSame( array( 'START TRANSACTION', 'COMMIT' ), $fake_wpdb->queries );
			self::assertArrayHasKey( 'woocommerce_update_product', $GLOBALS['wp_filter'] );
			self::assertSame( $sentinel_hook, $GLOBALS['wp_filter']['woocommerce_update_product'] );
			self::assertSame( array(), $cache_deleted );
			self::assertSame( array(), $post_caches_cleaned );

			// Caso 2: Rollback ante excepción con restauración de hooks e invalidación de caché para el ID 321.
			$fake_wpdb->queries = array();
			$thrown             = false;

			try {
				BatchGuard::run(
					static function (): void {
						self::assertTrue( BatchGuard::is_active() );
						BatchGuard::mark_product_modified( 321 );
						throw new \RuntimeException( 'Fallo simulado en lote' );
					}
				);
			} catch ( \RuntimeException $e ) {
				$thrown = 'Fallo simulado en lote' === $e->getMessage();
			}

			self::assertTrue( $thrown );
			self::assertFalse( BatchGuard::is_active() );
			self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $fake_wpdb->queries );
			self::assertArrayHasKey( 'woocommerce_update_product', $GLOBALS['wp_filter'] );
			self::assertSame( $sentinel_hook, $GLOBALS['wp_filter']['woocommerce_update_product'] );
			self::assertSame( array( array( 321, 'post_meta' ) ), $cache_deleted );
			self::assertSame( array( 321 ), $post_caches_cleaned );
		} finally {
			if ( null === $prev_wpdb ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $prev_wpdb;
			}

			if ( null === $prev_wp_filter ) {
				unset( $GLOBALS['wp_filter'] );
			} else {
				$GLOBALS['wp_filter'] = $prev_wp_filter;
			}
		}
	}

	/**
	 * @testdox El commit del fast-path de BatchGuard + PricingSynchronizer limpia transients, caché de post, LiteSpeed y EdgePurge
	 */
	public function test_batch_guard_commit_clears_caches_and_purges_series_and_edge(): void {
		$prev_wpdb      = $GLOBALS['wpdb'] ?? null;
		$prev_wp_filter = $GLOBALS['wp_filter'] ?? null;

		$fake_wpdb = new class() extends \wpdb {
			/** @var list<string> */
			public array $queries = array();

			/** @var array<int, array<string, mixed>> */
			public array $updates = array();

			public string $postmeta = 'wp_postmeta';

			public string $prefix = 'wp_';

			/** @return int|false */
			public function query( string $query ) {
				$this->queries[] = $query;
				return 1;
			}

			public function get_var( string $query ): int {
				unset( $query );
				return 0;
			}

			/**
			 * @param string               $table        Tabla.
			 * @param array<string, mixed> $data         Datos.
			 * @param array<string, mixed> $where        Where.
			 * @param list<string>|null    $format       Formatos datos.
			 * @param list<string>|null    $where_format Formatos where.
			 * @return int|false
			 */
			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				unset( $format, $where_format );
				$this->updates[] = array(
					'table' => $table,
					'data'  => $data,
					'where' => $where,
				);
				return 1;
			}
		};

		$GLOBALS['wpdb']      = $fake_wpdb;
		$GLOBALS['wp_filter'] = array();

		$product_id        = 456;
		$transients_cleared = array();
		$post_caches_cleaned = array();
		$updated_meta      = array();
		$dispatched_actions = array();
		$enqueued_async    = array();
		$updated_options   = array();

		$product                    = new class( 'instock' ) extends \WC_Product {
			public function get_name(): string {
				return 'Dorohedoro 1';
			}
		};
		$product->akb_id            = $product_id;
		$product->akb_regular_price = '20000';
		$product->akb_sale_price    = '19000';
		$product->akb_type          = 'simple';

		Functions\when( 'wc_get_product' )->alias(
			static fn( int $id ) => $id === $product_id ? $product : null
		);
		Functions\when( 'esc_sql' )->returnArg( 1 );
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $post_id, string $key, $value ) use ( &$updated_meta ): bool {
				$updated_meta[ $post_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static fn() => ''
		);
		Functions\when( 'wc_delete_product_transients' )->alias(
			static function ( int $pid ) use ( &$transients_cleared ): void {
				$transients_cleared[] = $pid;
			}
		);
		Functions\when( 'clean_post_cache' )->alias(
			static function ( int $pid ) use ( &$post_caches_cleaned ): void {
				$post_caches_cleaned[] = $pid;
			}
		);
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );
		Functions\when( 'get_the_terms' )->alias(
			static fn( int $id, string $tax ) => ( $id === $product_id && 'pa_serie' === $tax )
				? array( (object) array( 'term_id' => 77, 'term_taxonomy_id' => 77 ) )
				: false
		);
		Functions\when( 'home_url' )->justReturn( 'https://akibara.test' );
		Functions\when( 'get_term_link' )->alias(
			static fn( int $tt_id ): string => 'https://akibara.test/serie/dorohedoro-' . $tt_id . '/'
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( int $pid ): string => 'https://akibara.test/producto/dorohedoro-' . $pid . '/'
		);
		Functions\when( 'do_action' )->alias(
			static function ( ...$args ) use ( &$dispatched_actions ): void {
				$dispatched_actions[] = $args;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $option, $value, $autoload = null ) use ( &$updated_options ): bool {
				$updated_options[] = array( $option, $value, $autoload );
				return true;
			}
		);
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( string $hook, array $args = array(), string $group = '' ) use ( &$enqueued_async ): int {
				$enqueued_async[] = array( $hook, $args, $group );
				return 1;
			}
		);

		try {
			$res = BatchGuard::run(
				static fn(): array => PricingSynchronizer::apply_product_price( $product_id, 25000, 23800 ),
				false
			);

			self::assertSame( 'updated', $res['status'] );
			self::assertSame( array( 'START TRANSACTION', 'COMMIT' ), $fake_wpdb->queries );
			self::assertCount( 1, $fake_wpdb->updates );
			self::assertSame( 'wp_wc_product_meta_lookup', $fake_wpdb->updates[0]['table'] );
			self::assertSame( 23800.0, $fake_wpdb->updates[0]['data']['min_price'] );
			self::assertSame( 1, $fake_wpdb->updates[0]['data']['onsale'] );
			self::assertSame( '25000', $updated_meta[ $product_id ]['_regular_price'] );
			self::assertSame( '23800', $updated_meta[ $product_id ]['_sale_price'] );
			self::assertSame( '23800', $updated_meta[ $product_id ]['_price'] );

			self::assertSame( array( $product_id ), $transients_cleared );
			self::assertSame( array( $product_id ), $post_caches_cleaned );
			self::assertContains( array( 'akb_search_reindex_product', $product_id ), $dispatched_actions );
			self::assertContains( array( 'akb_search_pump_live' ), $dispatched_actions );
			self::assertContains( array( 'litespeed_purge', 'akb_serie.77' ), $dispatched_actions );
			self::assertContains( array( 'litespeed_purge', 'akb_muestra.456' ), $dispatched_actions );
			self::assertContains( array( 'litespeed_purge_post', $product_id ), $dispatched_actions );
			self::assertCount( 1, $updated_options );
			self::assertSame(
				array(
					array(
						EdgePurge::HOOK,
						array(
							array(
								'https://akibara.test/serie/dorohedoro-77/',
								'https://akibara.test/producto/dorohedoro-456/',
							),
						),
						'akibara-business',
					),
				),
				$enqueued_async
			);
		} finally {
			$pendientes = new \ReflectionProperty( EdgePurge::class, 'pendientes' );
			$pendientes->setAccessible( true );
			$pendientes->setValue( null, array() );

			if ( null === $prev_wpdb ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $prev_wpdb;
			}

			if ( null === $prev_wp_filter ) {
				unset( $GLOBALS['wp_filter'] );
			} else {
				$GLOBALS['wp_filter'] = $prev_wp_filter;
			}
		}
	}
}
