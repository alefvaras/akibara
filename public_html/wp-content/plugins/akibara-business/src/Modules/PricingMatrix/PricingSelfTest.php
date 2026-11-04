<?php
/**
 * Suite de pruebas automatizada agnóstica al entorno (`wp akb-pricing self-test`).
 *
 * Ejecuta y valida los 6 tests del Documento Maestro en Local y Producción,
 * restaurando el estado original de cualquier producto o pedido de prueba en bloques `finally`.
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

use Akibara\Business\Modules\Stock\StockStatus;
use WC_Order_Item_Product;
use WC_Product;

defined( 'ABSPATH' ) || exit;

final class PricingSelfTest {

	/**
	 * Ejecuta los 6 tests de certificación y devuelve el reporte estructurado.
	 *
	 * @return array{ok: bool, passed_count: int, total_count: int, tests: list<array{id: string, name: string, passed: bool, duration_ms: float, details: string}>}
	 */
	public static function run_all(): array {
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : ( defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : '' );
		if ( 'production' === $env ) {
			return array(
				'ok'           => false,
				'passed_count' => 0,
				'total_count'  => 0,
				'tests'        => array(),
				'error'        => 'Self-test mutante deshabilitado en entorno de producción viva por política de seguridad y prevención de carreras (Auditoría 2026-11-04). Ejecutar en Docker local o CI.',
			);
		}

		$tests = array(
			self::run_test_1_math_and_rounding(),
			self::run_test_2_active_order_lock_guard(),
			self::run_test_3_tripartite_coherence(),
			self::run_test_4_catalog_ordering(),
			self::run_test_5_zero_postmeta_duplicates(),
			self::run_test_6_batch_performance_and_hook_storm(),
		);

		$passed_count = 0;
		foreach ( $tests as $t ) {
			if ( $t['passed'] ) {
				++$passed_count;
			}
		}

		return array(
			'ok'           => count( $tests ) === $passed_count,
			'passed_count' => $passed_count,
			'total_count'  => count( $tests ),
			'tests'        => $tests,
		);
	}

	/**
	 * TEST 1 — Motor Matemático y Redondeo CLP:
	 * Verifica que con 5% y round_100:
	 * 10600 -> 10100, 10900 -> 10400, 11500 -> 10900, 13500 -> 12800,
	 * 14500 -> 13800, 21300 -> 20200, 27500 -> 26100, 29500 -> 28000.
	 *
	 * @return array{id: string, name: string, passed: bool, duration_ms: float, details: string}
	 */
	public static function run_test_1_math_and_rounding(): array {
		$t0 = microtime( true );

		$vectors = array(
			10600 => 10100,
			10900 => 10400,
			11500 => 10900,
			13500 => 12800,
			14500 => 13800,
			21300 => 20200,
			27500 => 26100,
			29500 => 28000,
		);

		$failures = array();
		foreach ( $vectors as $reg => $expected ) {
			$actual = PricingMath::calculate_sale_price( $reg, 5.0, PricingMath::MODE_ROUND_100 );
			if ( $actual !== $expected ) {
				$failures[] = sprintf( '%d -> esperado %d, obtenido %d', $reg, $expected, $actual );
			}
		}

		// Verificaciones adicionales de modos floor_100, round_50 y manual.
		if ( 10000 !== PricingMath::calculate_sale_price( 10600, 5.0, PricingMath::MODE_FLOOR_100 ) ) {
			$failures[] = 'floor_100(10600, 5%) !== 10000';
		}
		if ( 10050 !== PricingMath::calculate_sale_price( 10600, 5.0, PricingMath::MODE_ROUND_50 ) ) {
			$failures[] = 'round_50(10600, 5%) !== 10050';
		}
		if ( 9900 !== PricingMath::calculate_sale_price( 10600, 5.0, PricingMath::MODE_MANUAL, 9900 ) ) {
			$failures[] = 'manual(10600, 9900) !== 9900';
		}

		$ms = round( ( microtime( true ) - $t0 ) * 1000.0, 2 );

		return array(
			'id'          => 'TEST 1',
			'name'        => 'Motor Matemático y Redondeo CLP (5% + round_100)',
			'passed'      => empty( $failures ),
			'duration_ms' => $ms,
			'details'     => empty( $failures )
				? '8/8 escalas canónicas round_100 + floor_100/round_50/manual verificadas con exactitud.'
				: implode( '; ', $failures ),
		);
	}

	/**
	 * TEST 2 — Blindaje de Órdenes Activas en Preventa/A Pedido (Adaptativo Local/Prod).
	 *
	 * @return array{id: string, name: string, passed: bool, duration_ms: float, details: string}
	 */
	public static function run_test_2_active_order_lock_guard(): array {
		global $wpdb;

		$t0     = microtime( true );
		$target = self::resolve_or_create_locked_target();
		if ( 0 === $target['order_id'] || 0 === $target['product_id'] ) {
			return array(
				'id'          => 'TEST 2',
				'name'        => 'Blindaje de Órdenes Activas en Preventa/A Pedido',
				'passed'      => false,
				'duration_ms' => round( ( microtime( true ) - $t0 ) * 1000.0, 2 ),
				'details'     => 'No se encontró ningún producto publicado para crear fixture.',
			);
		}

		$target_order_id   = $target['order_id'];
		$target_product_id = $target['product_id'];
		$created_fixture   = $target['created_fixture'];
		$fixture_order_id  = $target['fixture_order_id'];

		try {
			$snapshot      = self::capture_product_snapshot( $target_product_id );
			$hash_0        = OrderLockGuard::snapshot_order_and_product_integrity( $target_order_id, $target_product_id );
			$max_action_id = (int) $wpdb->get_var( "SELECT COALESCE(MAX(action_id), 0) FROM {$wpdb->prefix}actionscheduler_actions" );

			try {
				$orig_reg  = (int) round( (float) ( $snapshot['meta']['_regular_price'] ?? 10600 ) );
				$test_reg  = $orig_reg > 0 ? $orig_reg + 1000 : 11600;
				$test_sale = PricingMath::calculate_sale_price( $test_reg, 5.0, PricingMath::MODE_ROUND_100 );

				// 1. Con protect_active_orders = true (debe omitirse).
				$res_true  = PricingSynchronizer::apply_product_price( $target_product_id, $test_reg, $test_sale, true );
				$hash_true = OrderLockGuard::snapshot_order_and_product_integrity( $target_order_id, $target_product_id );

				// 2. Con protect_active_orders = false (actualiza solo precio de vitrina sin tocar orden ni stock/timestamps).
				$res_false  = PricingSynchronizer::apply_product_price( $target_product_id, $test_reg, $test_sale, false );
				$hash_false = OrderLockGuard::snapshot_order_and_product_integrity( $target_order_id, $target_product_id );

				$new_recompute_actions = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions
						WHERE action_id > %d
						  AND hook = 'akb_order_preventa_recompute_for_product'",
						$max_action_id
					)
				);
			} finally {
				self::restore_product_snapshot( $target_product_id, $snapshot );
			}

			$hash_restored = OrderLockGuard::snapshot_order_and_product_integrity( $target_order_id, $target_product_id );
			$failures      = self::evaluate_test_2_assertions(
				$res_true,
				$res_false,
				$hash_0,
				$hash_true,
				$hash_false,
				$hash_restored,
				$new_recompute_actions
			);

			$ms = round( ( microtime( true ) - $t0 ) * 1000.0, 2 );

			return array(
				'id'          => 'TEST 2',
				'name'        => 'Blindaje de Órdenes Activas en Preventa/A Pedido',
				'passed'      => empty( $failures ),
				'duration_ms' => $ms,
				'details'     => empty( $failures )
					? sprintf(
						'Orden #%d / Producto #%d verificados: SHA-256=%s intacto en true/false, 0 acciones akb_order_preventa_recompute_for_product.',
						$target_order_id,
						$target_product_id,
						substr( $hash_0, 0, 12 )
					)
					: implode( '; ', $failures ),
			);
		} finally {
			if ( $created_fixture && $fixture_order_id > 0 ) {
				$fixture_order = wc_get_order( $fixture_order_id );
				if ( $fixture_order ) {
					$fixture_order->delete( true );
				}
			}
		}
	}

	/**
	 * Resuelve un pedido activo real o crea una orden fixture temporal para TEST 2.
	 *
	 * @return array{order_id: int, product_id: int, created_fixture: bool, fixture_order_id: int}
	 */
	private static function resolve_or_create_locked_target(): array {
		global $wpdb;

		$audit_rows = OrderLockGuard::get_locked_orders_audit();
		foreach ( $audit_rows as $row ) {
			$pid = (int) ( $row['product_id'] ?? 0 );
			$oid = (int) ( $row['order_id'] ?? 0 );
			if ( $pid > 0 && $oid > 0 && wc_get_product( $pid ) instanceof WC_Product ) {
				return array(
					'order_id'         => $oid,
					'product_id'       => $pid,
					'created_fixture'  => false,
					'fixture_order_id' => 0,
				);
			}
		}

		$sample_pid = (int) $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID DESC LIMIT 1"
		);
		if ( $sample_pid <= 0 ) {
			return array(
				'order_id'         => 0,
				'product_id'       => 0,
				'created_fixture'  => false,
				'fixture_order_id' => 0,
			);
		}

		$order = wc_create_order( array( 'status' => 'processing' ) );
		$item  = new WC_Order_Item_Product();
		$item->set_product_id( $sample_pid );
		$item->set_quantity( 1 );
		$item->set_subtotal( '10000' );
		$item->set_total( '10000' );
		$item->add_meta_data( '_akb_eta_was_preventa', 'yes', true );
		$order->add_item( $item );
		$order->save();

		$fixture_order_id = (int) $order->get_id();
		return array(
			'order_id'         => $fixture_order_id,
			'product_id'       => $sample_pid,
			'created_fixture'  => true,
			'fixture_order_id' => $fixture_order_id,
		);
	}

	/**
	 * Evalúa las aserciones de TEST 2.
	 *
	 * @param array<string, mixed> $res_true              Resultado con protect_active_orders=true.
	 * @param array<string, mixed> $res_false             Resultado con protect_active_orders=false.
	 * @param string               $hash_0                Hash inicial.
	 * @param string               $hash_true             Hash tras protect_active_orders=true.
	 * @param string               $hash_false            Hash tras protect_active_orders=false.
	 * @param string               $hash_restored         Hash tras restauración.
	 * @param int                  $new_recompute_actions Acciones encoladas en Action Scheduler.
	 * @return list<string>
	 */
	private static function evaluate_test_2_assertions(
		array $res_true,
		array $res_false,
		string $hash_0,
		string $hash_true,
		string $hash_false,
		string $hash_restored,
		int $new_recompute_actions
	): array {
		$failures = array();
		if ( 'skipped_locked' !== ( $res_true['status'] ?? '' ) ) {
			$failures[] = 'protect_active_orders=true no omitió el producto bloqueado';
		}
		if ( $hash_0 !== $hash_true ) {
			$failures[] = 'SHA-256 cambió con protect_active_orders=true';
		}
		if ( 'updated' !== ( $res_false['status'] ?? '' ) ) {
			$failures[] = 'protect_active_orders=false no actualizó el precio de vitrina';
		}
		if ( $hash_0 !== $hash_false ) {
			$failures[] = 'SHA-256 de orden/metas de stock cambió con protect_active_orders=false';
		}
		if ( $hash_0 !== $hash_restored ) {
			$failures[] = 'SHA-256 post-restauración difiere del original';
		}
		if ( 0 !== $new_recompute_actions ) {
			$failures[] = sprintf( 'Se encolaron %d acciones de akb_order_preventa_recompute_for_product (esperado 0)', $new_recompute_actions );
		}
		return $failures;
	}

	/**
	 * TEST 3 — Coherencia Tripartita (`wp_postmeta` + `wp_wc_product_meta_lookup` + `wp_akb_search_docs`).
	 *
	 * @return array{id: string, name: string, passed: bool, duration_ms: float, details: string}
	 */
	public static function run_test_3_tripartite_coherence(): array {
		$t0  = microtime( true );
		$pid = self::find_sample_unlocked_product_id();

		if ( $pid <= 0 ) {
			return array(
				'id'          => 'TEST 3',
				'name'        => 'Coherencia Tripartita (postmeta + lookup + search_docs)',
				'passed'      => false,
				'duration_ms' => round( ( microtime( true ) - $t0 ) * 1000.0, 2 ),
				'details'     => 'No se encontró un producto libre para la prueba.',
			);
		}

		$snapshot = self::capture_product_snapshot( $pid );
		$failures = array();

		try {
			$orig_reg  = max( 10600, (int) round( (float) ( $snapshot['meta']['_regular_price'] ?? 10600 ) ) );
			$test_reg  = $orig_reg + 1000;
			$test_sale = PricingMath::calculate_sale_price( $test_reg, 5.0, PricingMath::MODE_ROUND_100 );

			$failures = array_merge(
				$failures,
				self::assert_backorder_tripartite_state( $pid, $test_reg, $test_sale ),
				self::assert_instock_tripartite_state( $pid, $test_reg )
			);
		} finally {
			self::restore_product_snapshot( $pid, $snapshot );
			BatchGuard::sync_search_for_products( array( $pid ) );
		}

		$ms = round( ( microtime( true ) - $t0 ) * 1000.0, 2 );

		return array(
			'id'          => 'TEST 3',
			'name'        => 'Coherencia Tripartita (wp_postmeta + lookup + akb_search_docs)',
			'passed'      => empty( $failures ),
			'duration_ms' => $ms,
			'details'     => empty( $failures )
				? sprintf( 'Producto #%d verificado en onbackorder e instock: min_price, price_effective, facet_index y price_html 100%% idénticos.', $pid )
				: implode( '; ', $failures ),
		);
	}

	/**
	 * Obtiene un product_id publicado que no esté comprometido en ninguna orden activa.
	 *
	 * @return int
	 */
	private static function find_sample_unlocked_product_id(): int {
		global $wpdb;

		$locked_ids = OrderLockGuard::get_locked_product_ids();
		$not_in_sql = ! empty( $locked_ids ) ? 'AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $locked_ids ) ) . ')' : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $not_in_sql contiene enteros validados con intval().
		return (int) $wpdb->get_var(
			"SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_reg
				ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price' AND CAST(pm_reg.meta_value AS UNSIGNED) > 0
			WHERE p.post_type = 'product'
			  AND p.post_status = 'publish'
			  {$not_in_sql}
			ORDER BY p.ID DESC
			LIMIT 1"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Verifica la coherencia tripartita en estado `onbackorder`.
	 *
	 * @param int $pid       ID del producto.
	 * @param int $test_reg  Precio regular de prueba.
	 * @param int $test_sale Precio de oferta de prueba.
	 * @return list<string>
	 */
	private static function assert_backorder_tripartite_state( int $pid, int $test_reg, int $test_sale ): array {
		global $wpdb;

		BatchGuard::run(
			static function () use ( $pid, $test_reg, $test_sale ): void {
				$p = wc_get_product( $pid );
				if ( ! $p instanceof WC_Product ) {
					return;
				}
				$p->set_manage_stock( false );
				$p->set_stock_quantity( null );
				$p->set_backorders( 'no' );
				$p->set_stock_status( StockStatus::ON_BACKORDER );
				$p->set_regular_price( (string) $test_reg );
				$p->set_sale_price( (string) $test_sale );
				$p->save();
				delete_post_meta( $pid, '_stock' );
				BatchGuard::mark_product_modified( $pid, false );
			},
			false
		);

		$lookup_min    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT min_price FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d", $pid ) );
		$doc           = $wpdb->get_row( $wpdb->prepare( "SELECT price_effective, price_html FROM {$wpdb->prefix}akb_search_docs WHERE post_id = %d", $pid ), ARRAY_A );
		$facet         = (float) $wpdb->get_var( $wpdb->prepare( "SELECT value_num FROM {$wpdb->prefix}akb_search_facet_index WHERE post_id = %d AND facet = 'precio'", $pid ) );
		$fresh_p       = wc_get_product( $pid );
		$expected_html = $fresh_p instanceof WC_Product ? wp_kses_post( (string) $fresh_p->get_price_html() ) : '';

		$failures = array();
		if ( (int) round( $lookup_min ) !== $test_sale ) {
			$failures[] = sprintf( 'onbackorder lookup.min_price=%d != %d', (int) round( $lookup_min ), $test_sale );
		}
		if ( ! is_array( $doc ) || (int) round( (float) $doc['price_effective'] ) !== $test_sale ) {
			$failures[] = sprintf( 'onbackorder search_docs.price_effective != %d', $test_sale );
		}
		if ( (int) round( $facet ) !== $test_sale ) {
			$failures[] = sprintf( 'onbackorder facet_index.value_num=%d != %d', (int) round( $facet ), $test_sale );
		}
		if ( ! is_array( $doc ) || (string) $doc['price_html'] !== $expected_html ) {
			$failures[] = 'onbackorder search_docs.price_html difiere de $product->get_price_html()';
		}

		return $failures;
	}

	/**
	 * Verifica la coherencia tripartita en estado `instock`.
	 *
	 * @param int $pid      ID del producto.
	 * @param int $test_reg Precio regular de prueba.
	 * @return list<string>
	 */
	private static function assert_instock_tripartite_state( int $pid, int $test_reg ): array {
		global $wpdb;

		$tax_disc_on = 'yes' === (string) get_option( 'akibara_taxonomy_discount_enabled', 'no' );
		BatchGuard::run(
			static function () use ( $pid, $test_reg ): void {
				$p = wc_get_product( $pid );
				if ( ! $p instanceof WC_Product ) {
					return;
				}
				$p->set_manage_stock( true );
				$p->set_stock_quantity( 2 );
				$p->set_backorders( 'no' );
				$p->set_stock_status( StockStatus::IN_STOCK );
				$p->set_regular_price( (string) $test_reg );
				$p->set_sale_price( '' );
				$p->save();
				BatchGuard::mark_product_modified( $pid, false );
			},
			false
		);

		$lookup_min    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT min_price FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d", $pid ) );
		$doc           = $wpdb->get_row( $wpdb->prepare( "SELECT price_effective, price_html FROM {$wpdb->prefix}akb_search_docs WHERE post_id = %d", $pid ), ARRAY_A );
		$facet         = (float) $wpdb->get_var( $wpdb->prepare( "SELECT value_num FROM {$wpdb->prefix}akb_search_facet_index WHERE post_id = %d AND facet = 'precio'", $pid ) );
		$fresh_p       = wc_get_product( $pid );
		$expected_eff  = $fresh_p instanceof WC_Product
			? (int) round( (float) apply_filters( 'akb_search_effective_price', (float) $fresh_p->get_price(), $fresh_p ) )
			: $test_reg;
		$expected_html = $fresh_p instanceof WC_Product ? wp_kses_post( (string) $fresh_p->get_price_html() ) : '';

		$failures = array();
		if ( ! $tax_disc_on && (int) round( $lookup_min ) !== $test_reg ) {
			$failures[] = sprintf( 'instock lookup.min_price=%d != %d', (int) round( $lookup_min ), $test_reg );
		}
		if ( ! is_array( $doc ) || (int) round( (float) $doc['price_effective'] ) !== $expected_eff ) {
			$failures[] = sprintf( 'instock search_docs.price_effective != %d', $expected_eff );
		}
		if ( (int) round( $facet ) !== $expected_eff ) {
			$failures[] = sprintf( 'instock facet_index.value_num=%d != %d', (int) round( $facet ), $expected_eff );
		}
		if ( ! is_array( $doc ) || (string) $doc['price_html'] !== $expected_html ) {
			$failures[] = 'instock search_docs.price_html difiere de $product->get_price_html()';
		}

		return $failures;
	}

	/**
	 * TEST 4 — Ordenamiento de Catálogo (`stock_rank` y `menu_order`):
	 * Verifica que en `wp_akb_search_docs` el máximo `menu_order` de `stock_rank = 0` (`instock`)
	 * sea menor que el mínimo `menu_order` de `stock_rank = 1` (`onbackorder`), y este menor que
	 * el mínimo de `stock_rank = 2` (`outofstock`).
	 *
	 * @return array{id: string, name: string, passed: bool, duration_ms: float, details: string}
	 */
	public static function run_test_4_catalog_ordering(): array {
		global $wpdb;

		$t0   = microtime( true );
		$rows = $wpdb->get_results(
			"SELECT stock_rank, MIN(menu_order) AS min_mo, MAX(menu_order) AS max_mo, COUNT(*) AS cnt
			FROM {$wpdb->prefix}akb_search_docs
			WHERE visible = 1
			GROUP BY stock_rank
			ORDER BY stock_rank ASC",
			ARRAY_A
		);

		$by_rank = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$by_rank[ (int) $r['stock_rank'] ] = array(
					'min' => (int) $r['min_mo'],
					'max' => (int) $r['max_mo'],
					'cnt' => (int) $r['cnt'],
				);
			}
		}

		$failures = array();
		if ( isset( $by_rank[0], $by_rank[1] ) && $by_rank[0]['max'] >= $by_rank[1]['min'] ) {
			$failures[] = sprintf( 'MAX(rank 0)=%d >= MIN(rank 1)=%d', $by_rank[0]['max'], $by_rank[1]['min'] );
		}
		if ( isset( $by_rank[1], $by_rank[2] ) && $by_rank[1]['max'] >= $by_rank[2]['min'] ) {
			$failures[] = sprintf( 'MAX(rank 1)=%d >= MIN(rank 2)=%d', $by_rank[1]['max'], $by_rank[2]['min'] );
		}
		if ( isset( $by_rank[0], $by_rank[2] ) && $by_rank[0]['max'] >= $by_rank[2]['min'] ) {
			$failures[] = sprintf( 'MAX(rank 0)=%d >= MIN(rank 2)=%d', $by_rank[0]['max'], $by_rank[2]['min'] );
		}

		$ms = round( ( microtime( true ) - $t0 ) * 1000.0, 2 );

		$r0 = isset( $by_rank[0] ) ? sprintf( 'rank0[%d..%d]', $by_rank[0]['min'], $by_rank[0]['max'] ) : 'rank0[vacío]';
		$r1 = isset( $by_rank[1] ) ? sprintf( 'rank1[%d..%d]', $by_rank[1]['min'], $by_rank[1]['max'] ) : 'rank1[vacío]';
		$r2 = isset( $by_rank[2] ) ? sprintf( 'rank2[%d..%d]', $by_rank[2]['min'], $by_rank[2]['max'] ) : 'rank2[vacío]';

		return array(
			'id'          => 'TEST 4',
			'name'        => 'Ordenamiento de Catálogo (stock_rank 0 < 1 < 2 en menu_order)',
			'passed'      => empty( $failures ),
			'duration_ms' => $ms,
			'details'     => empty( $failures )
				? sprintf( 'Partición estricta verificada: %s < %s < %s.', $r0, $r1, $r2 )
				: implode( '; ', $failures ),
		);
	}

	/**
	 * TEST 5 — Cero Duplicados en `wp_postmeta`:
	 * Ejecuta 3 cambios consecutivos de estado/precio sobre un producto y verifica con
	 * `SELECT COUNT(*)` en `wp_postmeta` que `_akb_onbackorder_at`, `_akb_pdp_specs_generation`,
	 * `_akb_tramo_prev_regular` y `_akb_tramo_prev_sale` tengan como máximo `1` fila por `post_id`.
	 *
	 * @return array{id: string, name: string, passed: bool, duration_ms: float, details: string}
	 */
	public static function run_test_5_zero_postmeta_duplicates(): array {
		global $wpdb;

		$t0  = microtime( true );
		$pid = self::find_sample_unlocked_product_id();

		if ( $pid <= 0 ) {
			return array(
				'id'          => 'TEST 5',
				'name'        => 'Cero Duplicados en wp_postmeta tras 3 mutaciones consecutivas',
				'passed'      => false,
				'duration_ms' => round( ( microtime( true ) - $t0 ) * 1000.0, 2 ),
				'details'     => 'No se encontró un producto libre para la prueba.',
			);
		}

		$snapshot = self::capture_product_snapshot( $pid );
		$failures = array();

		try {
			$base_reg = max( 10600, (int) round( (float) ( $snapshot['meta']['_regular_price'] ?? 10600 ) ) );

			for ( $step = 1; $step <= 3; $step++ ) {
				$step_reg  = $base_reg + ( $step * 500 );
				$step_sale = PricingMath::calculate_sale_price( $step_reg, 5.0, PricingMath::MODE_ROUND_100 );
				PricingSynchronizer::apply_product_price( $pid, $step_reg, $step_sale, true );
				PricingSynchronizer::update_unique_post_meta( $pid, '_akb_onbackorder_at', (string) ( time() - $step ) );
				PricingSynchronizer::update_unique_post_meta( $pid, '_akb_pdp_specs_generation', (string) ( time() + $step ) );
				// Simular llamada de WC_Data_Store_WP::add_meta() con $unique = false durante clonación/guardado.
				add_metadata( 'post', $pid, '_akb_onbackorder_at', (string) ( time() - $step ), false );
				add_metadata( 'post', $pid, '_akb_pdp_specs_generation', (string) ( time() + $step ), false );
				add_metadata( 'post', $pid, 'rank_math_internal_links_processed', '1', false );
			}

			$keys = array(
				'_akb_onbackorder_at',
				'_akb_pdp_specs_generation',
				'_akb_tramo_prev_regular',
				'_akb_tramo_prev_sale',
				'rank_math_internal_links_processed',
			);

			foreach ( $keys as $meta_key ) {
				$cnt = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
						$pid,
						$meta_key
					)
				);
				if ( $cnt > 1 ) {
					$failures[] = sprintf( 'post_id=%d meta_key=%s tiene %d filas (máximo permitido: 1)', $pid, $meta_key, $cnt );
				}
			}

			// Verificación global en toda la tabla wp_postmeta para las claves auditadas.
			$global_dups = (int) $wpdb->get_var(
				"SELECT COUNT(*) - COUNT(DISTINCT CONCAT(post_id, ':', meta_key))
				FROM {$wpdb->postmeta}
				WHERE meta_key IN ('_akb_onbackorder_at', '_akb_pdp_specs_generation', '_akb_tramo_prev_regular', '_akb_tramo_prev_sale', 'rank_math_internal_links_processed')"
			);
			if ( $global_dups > 0 ) {
				$failures[] = sprintf( 'wp_postmeta global presenta %d filas duplicadas', $global_dups );
			}
		} finally {
			self::restore_product_snapshot( $pid, $snapshot );
			BatchGuard::sync_search_for_products( array( $pid ) );
		}

		$ms = round( ( microtime( true ) - $t0 ) * 1000.0, 2 );

		return array(
			'id'          => 'TEST 5',
			'name'        => 'Cero Duplicados en wp_postmeta',
			'passed'      => empty( $failures ),
			'duration_ms' => $ms,
			'details'     => empty( $failures )
				? sprintf( 'Producto #%d (3 mutaciones consecutivas) y tabla wp_postmeta global con 0 filas duplicadas.', $pid )
				: implode( '; ', $failures ),
		);
	}

	/**
	 * TEST 6 — Rendimiento y Supresión de Hook-Storm:
	 * Mide el tiempo y el conteo de acciones nuevas en `wp_actionscheduler_actions` al procesar
	 * un lote de hasta 50 productos.
	 * ASSERT: Tiempo < 2.5s y 0 acciones individuales por producto de
	 * `akb_order_preventa_recompute_for_product` o `akb_sf_scan_product`.
	 *
	 * @return array{id: string, name: string, passed: bool, duration_ms: float, details: string}
	 */
	public static function run_test_6_batch_performance_and_hook_storm(): array {
		global $wpdb;

		$locked_ids = OrderLockGuard::get_locked_product_ids();
		$not_in_sql = ! empty( $locked_ids ) ? 'AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $locked_ids ) ) . ')' : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $not_in_sql contiene enteros validados con intval().
		$raw_ids = $wpdb->get_col(
			"SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_reg
				ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price' AND CAST(pm_reg.meta_value AS UNSIGNED) > 0
			WHERE p.post_type = 'product'
			  AND p.post_status = 'publish'
			  {$not_in_sql}
			ORDER BY p.ID DESC
			LIMIT 50"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$batch_ids = is_array( $raw_ids ) ? array_values( array_map( 'intval', $raw_ids ) ) : array();
		if ( empty( $batch_ids ) ) {
			return array(
				'id'          => 'TEST 6',
				'name'        => 'Rendimiento (< 2.5s) y Supresión de Hook-Storm (hasta 50 productos)',
				'passed'      => false,
				'duration_ms' => 0.0,
				'details'     => 'No hay productos disponibles para probar el lote.',
			);
		}

		// Precalentar cachés e instancias antes de medir el lote y guardar snapshot exacto.
		$snapshots = array();
		foreach ( $batch_ids as $pid ) {
			$snapshots[ $pid ] = self::capture_product_snapshot( $pid );
		}

		$max_action_id = (int) $wpdb->get_var( "SELECT COALESCE(MAX(action_id), 0) FROM {$wpdb->prefix}actionscheduler_actions" );
		$locked_set    = array_fill_keys( $locked_ids, true );
		$locked_map    = array();

		$elapsed_sec = 0.0;
		$storm_count = 0;

		try {
			$t0 = microtime( true );

			BatchGuard::run(
				static function () use ( $batch_ids, $snapshots, $locked_set, $locked_map ): void {
					foreach ( $batch_ids as $pid ) {
						$orig_reg  = max( 10600, (int) round( (float) ( $snapshots[ $pid ]['meta']['_regular_price'] ?? 10600 ) ) );
						$test_reg  = $orig_reg + 100;
						$test_sale = PricingMath::calculate_sale_price( $test_reg, 5.0, PricingMath::MODE_ROUND_100 );

						PricingSynchronizer::apply_product_price(
							$pid,
							$test_reg,
							$test_sale,
							true,
							$locked_set,
							$locked_map,
							false
						);
					}
				},
				false
			);

			$elapsed_sec = microtime( true ) - $t0;

			$storm_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions
					WHERE action_id > %d
					  AND hook IN ('akb_order_preventa_recompute_for_product', 'akb_sf_scan_product')",
					$max_action_id
				)
			);
		} finally {
			foreach ( $batch_ids as $pid ) {
				if ( isset( $snapshots[ $pid ] ) ) {
					self::restore_product_snapshot( $pid, $snapshots[ $pid ] );
				}
			}
			BatchGuard::sync_search_for_products( $batch_ids );
		}

		$failures = array();
		if ( $elapsed_sec >= 2.5 ) {
			$failures[] = sprintf( 'Tiempo de lote %.3fs excede el presupuesto de 2.500s', $elapsed_sec );
		}
		if ( 0 !== $storm_count ) {
			$failures[] = sprintf( 'Se encolaron %d acciones prohibidas en Action Scheduler (esperado 0)', $storm_count );
		}

		$ms = round( $elapsed_sec * 1000.0, 2 );

		return array(
			'id'          => 'TEST 6',
			'name'        => 'Rendimiento (< 2.5s) y Supresión de Hook-Storm (hasta 50 productos)',
			'passed'      => empty( $failures ),
			'duration_ms' => $ms,
			'details'     => empty( $failures )
				? sprintf( '%d productos procesados y sincronizados en %.3fs (< 2.5s) con 0 acciones AS individuales.', count( $batch_ids ), $elapsed_sec )
				: implode( '; ', $failures ),
		);
	}

	/**
	 * Captura el estado completo de postmeta, lookup y visibilidad de un producto para restauración exacta.
	 *
	 * @param int $product_id ID del producto.
	 * @return array<string, mixed>
	 */
	private static function capture_product_snapshot( int $product_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value
				FROM {$wpdb->postmeta}
				WHERE post_id = %d
				  AND meta_key IN (
					'_regular_price',
					'_sale_price',
					'_price',
					%s,
					'_manage_stock',
					'_stock',
					'_backorders',
					'_akb_onbackorder_at',
					'_akb_first_instock_at',
					'_akb_pdp_specs_generation',
					'_akb_tramo_prev_regular',
					'_akb_tramo_prev_sale'
				  )
				ORDER BY meta_id ASC",
				$product_id,
				'_stock_status'
			),
			ARRAY_A
		);

		$meta = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$meta[ (string) $r['meta_key'] ] = $r['meta_value'];
			}
		}

		$lookup = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d", $product_id ), ARRAY_A );

		$vis_terms = wp_get_object_terms( $product_id, 'product_visibility', array( 'fields' => 'slugs' ) );

		return array(
			'meta'       => $meta,
			'lookup'     => is_array( $lookup ) ? $lookup : null,
			'visibility' => is_array( $vis_terms ) ? $vis_terms : array(),
		);
	}

	/**
	 * Restaura exactamente el snapshot de un producto tras una prueba.
	 *
	 * @param int                  $product_id ID del producto.
	 * @param array<string, mixed> $snapshot   Snapshot capturado.
	 * @return void
	 */
	private static function restore_product_snapshot( int $product_id, array $snapshot ): void {
		global $wpdb;

		$keys = array(
			'_regular_price',
			'_sale_price',
			'_price',
			'_stock_status',
			'_manage_stock',
			'_stock',
			'_backorders',
			'_akb_onbackorder_at',
			'_akb_first_instock_at',
			'_akb_pdp_specs_generation',
			'_akb_tramo_prev_regular',
			'_akb_tramo_prev_sale',
		);

		$meta = isset( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ? $snapshot['meta'] : array();
		foreach ( $keys as $k ) {
			if ( array_key_exists( $k, $meta ) ) {
				PricingSynchronizer::update_unique_post_meta( $product_id, $k, (string) $meta[ $k ] );
			} else {
				delete_post_meta( $product_id, $k );
			}
		}

		if ( isset( $snapshot['lookup'] ) && is_array( $snapshot['lookup'] ) ) {
			$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
			$wpdb->replace( $lookup_table, $snapshot['lookup'] );
		}

		if ( isset( $snapshot['visibility'] ) && is_array( $snapshot['visibility'] ) ) {
			wp_set_object_terms( $product_id, $snapshot['visibility'], 'product_visibility', false );
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		clean_post_cache( $product_id );
	}
}
