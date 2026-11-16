<?php
/**
 * Pruebas unitarias para el módulo PricingMatrix (Motor Matemático CLP, Guardias y Aliases).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\PricingMatrix;

use Akibara\Business\Modules\PricingMatrix\BatchGuard;
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
		self::assertStringContainsString( 'Precio Oferta ($)', $html );
		self::assertStringContainsString( 'name="manual_sale"', $html );
		self::assertStringContainsString( 'placeholder="0 = Sin oferta"', $html );
		self::assertStringContainsString( 'Sin oferta', $html );
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
}
