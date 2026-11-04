<?php
/**
 * Blindaje de productos comprometidos en Preventa o A Pedido (Órdenes Activas).
 *
 * Reglas inquebrantables:
 * 1. Las tablas wp_wc_orders, wp_wc_orders_meta, wp_woocommerce_order_items y
 *    wp_woocommerce_order_itemmeta son 100% de solo lectura.
 * 2. En cualquier producto presente en get_locked_product_ids(), está prohibido
 *    modificar _stock, _stock_status, _backorders, _akb_onbackorder_at o _akb_sf_restocked_at.
 * 3. Incluye todos los estados activos/comprometidos (wc-processing, wc-on-hold,
 *    wc-shipping-progress) más productos con _stock < 0.
 *    Los estados no pagados/no confirmados (wc-pending) y terminales están excluidos.
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

final class OrderLockGuard {

	/**
	 * Estados terminales o no comprometidos de pedido; pedidos en estos estados no bloquean el catálogo.
	 *
	 * @var list<string>
	 */
	public const TERMINAL_STATUSES = array(
		'completed',
		'cancelled',
		'refunded',
		'failed',
		'trash',
		'checkout-draft',
		'auto-draft',
		'pending',
	);

	/**
	 * Metadatos de stock y ciclo de vida que jamás pueden ser alterados en un producto bloqueado.
	 *
	 * @var list<string>
	 */
	public const PROTECTED_META_KEYS = array(
		'_stock',
		'_stock_status',
		'_backorders',
		'_akb_onbackorder_at',
		'_akb_sf_restocked_at',
		'_akb_eta_type',
		'_akb_release_date',
		'_akb_eta_release_date',
	);

	/**
	 * Devuelve los IDs únicos de productos/variaciones comprometidos en pedidos activos
	 * o con _stock < 0.
	 *
	 * @return list<int>
	 */
	public static function get_locked_product_ids(): array {
		global $wpdb;

		$locked = array();
		foreach ( self::get_locked_orders_audit() as $row ) {
			$pid = (int) ( $row['product_id'] ?? 0 );
			if ( $pid > 0 ) {
				$locked[ $pid ] = true;
			}
			$vid = (int) ( $row['variation_id'] ?? 0 );
			if ( $vid > 0 ) {
				$locked[ $vid ] = true;
			}
		}

		$neg_stock_ids = $wpdb->get_col(
			"SELECT DISTINCT pm.post_id
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type IN ('product', 'product_variation')
			  AND pm.meta_key = '_stock'
			  AND pm.meta_value != ''
			  AND CAST(pm.meta_value AS SIGNED) < 0"
		);
		if ( ! empty( $wpdb->last_error ) || false === $neg_stock_ids ) {
			throw new \RuntimeException( 'Error al consultar productos con stock negativo en la base de datos: ' . $wpdb->last_error );
		}
		if ( is_array( $neg_stock_ids ) ) {
			foreach ( $neg_stock_ids as $nid ) {
				$id = (int) $nid;
				if ( $id > 0 ) {
					$locked[ $id ] = true;
				}
			}
		}

		$ids = array_keys( $locked );
		sort( $ids );
		return $ids;
	}

	/**
	 * Mapa indexado por product_id con el detalle de órdenes comprometidas.
	 *
	 * @return array<int, list<array<string, mixed>>>
	 */
	public static function get_locked_map_by_product(): array {
		$map = array();
		foreach ( self::get_locked_orders_audit() as $row ) {
			$pid = (int) ( $row['product_id'] ?? 0 );
			if ( $pid > 0 ) {
				$map[ $pid ][] = $row;
			}
		}
		return $map;
	}

	/**
	 * Consulta de auditoría en tiempo real de productos comprometidos en órdenes activas (1.3).
	 * Soporta HPOS (wp_wc_orders) y fallback CPT (wp_posts).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function get_locked_orders_audit(): array {
		global $wpdb;

		$hpos           = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
		$orders_table   = $wpdb->prefix . 'wc_orders';
		$has_hpos_table = $hpos && ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_table ) ) === $orders_table );

		if ( $hpos && ! $has_hpos_table ) {
			throw new \RuntimeException( 'HPOS está habilitado pero no se detectó la tabla ' . $orders_table . '. Abortando por seguridad de compromisos (Fail-Closed).' );
		}

		$cutoff_pending = gmdate( 'Y-m-d H:i:s', time() - ( 24 * HOUR_IN_SECONDS ) );

		if ( $has_hpos_table ) {
			$sql = $wpdb->prepare(
				"SELECT
					o.id AS order_id,
					o.status AS order_status,
					oi.order_item_id,
					oi.order_item_name,
					CAST(oim_pid.meta_value AS UNSIGNED) AS product_id,
					CAST(COALESCE(oim_vid.meta_value, '0') AS UNSIGNED) AS variation_id,
					CAST(COALESCE(oim_qty.meta_value, '1') AS UNSIGNED) AS qty_comprada,
					ROUND(CAST(COALESCE(oim_tot.meta_value, '0') AS DECIMAL(12,2)) * 1.19 / GREATEST(CAST(COALESCE(oim_qty.meta_value, '1') AS UNSIGNED), 1), 0) AS precio_unitario_pagado_iva,
					COALESCE(oim_prev.meta_value, 'no') AS era_preventa_al_comprar,
					COALESCE(pm_status.meta_value, '') AS estado_actual_catalogo,
					COALESCE(pm_stock.meta_value, '') AS stock_actual_catalogo
				FROM {$wpdb->prefix}wc_orders o
				INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
					ON o.id = oi.order_id AND oi.order_item_type = 'line_item'
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
					ON oi.order_item_id = oim_pid.order_item_id AND oim_pid.meta_key = '_product_id'
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_vid
					ON oi.order_item_id = oim_vid.order_item_id AND oim_vid.meta_key = '_variation_id'
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
					ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_tot
					ON oi.order_item_id = oim_tot.order_item_id AND oim_tot.meta_key = '_line_total'
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_prev
					ON oi.order_item_id = oim_prev.order_item_id AND oim_prev.meta_key = '_akb_eta_was_preventa'
				LEFT JOIN {$wpdb->postmeta} pm_status
					ON CAST(oim_pid.meta_value AS UNSIGNED) = pm_status.post_id AND pm_status.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_stock
					ON CAST(oim_pid.meta_value AS UNSIGNED) = pm_stock.post_id AND pm_stock.meta_key = '_stock'
				WHERE o.type = 'shop_order'
				  AND (
				      o.status NOT IN ('wc-completed', 'completed', 'wc-cancelled', 'cancelled', 'wc-refunded', 'refunded', 'wc-failed', 'failed', 'wc-trash', 'trash', 'wc-checkout-draft', 'checkout-draft', 'auto-draft', 'wc-pending', 'pending')
				      OR (
				          o.status IN ('wc-pending', 'pending')
				          AND o.date_created_gmt >= %s
				      )
				  )
				ORDER BY o.id DESC, oi.order_item_id ASC",
				'_stock_status',
				$cutoff_pending
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT
					o.ID AS order_id,
					o.post_status AS order_status,
					oi.order_item_id,
					oi.order_item_name,
					CAST(oim_pid.meta_value AS UNSIGNED) AS product_id,
					CAST(COALESCE(oim_vid.meta_value, '0') AS UNSIGNED) AS variation_id,
					CAST(COALESCE(oim_qty.meta_value, '1') AS UNSIGNED) AS qty_comprada,
					ROUND(CAST(COALESCE(oim_tot.meta_value, '0') AS DECIMAL(12,2)) * 1.19 / GREATEST(CAST(COALESCE(oim_qty.meta_value, '1') AS UNSIGNED), 1), 0) AS precio_unitario_pagado_iva,
					COALESCE(oim_prev.meta_value, 'no') AS era_preventa_al_comprar,
					COALESCE(pm_status.meta_value, '') AS estado_actual_catalogo,
					COALESCE(pm_stock.meta_value, '') AS stock_actual_catalogo
				FROM {$wpdb->posts} o
				INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
					ON o.ID = oi.order_id AND oi.order_item_type = 'line_item'
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
					ON oi.order_item_id = oim_pid.order_item_id AND oim_pid.meta_key = '_product_id'
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_vid
					ON oi.order_item_id = oim_vid.order_item_id AND oim_vid.meta_key = '_variation_id'
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
					ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_tot
					ON oi.order_item_id = oim_tot.order_item_id AND oim_tot.meta_key = '_line_total'
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_prev
					ON oi.order_item_id = oim_prev.order_item_id AND oim_prev.meta_key = '_akb_eta_was_preventa'
				LEFT JOIN {$wpdb->postmeta} pm_status
					ON CAST(oim_pid.meta_value AS UNSIGNED) = pm_status.post_id AND pm_status.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_stock
					ON CAST(oim_pid.meta_value AS UNSIGNED) = pm_stock.post_id AND pm_stock.meta_key = '_stock'
				WHERE o.post_type = 'shop_order'
				  AND (
				      o.post_status NOT IN ('wc-completed', 'completed', 'wc-cancelled', 'cancelled', 'wc-refunded', 'refunded', 'wc-failed', 'failed', 'wc-trash', 'trash', 'wc-checkout-draft', 'checkout-draft', 'auto-draft', 'wc-pending', 'pending')
				      OR (
				          o.post_status IN ('wc-pending', 'pending')
				          AND o.post_date_gmt >= %s
				      )
				  )
				ORDER BY o.ID DESC, oi.order_item_id ASC",
				'_stock_status',
				$cutoff_pending
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql solo usa prefijos de $wpdb y constantes de estados terminales.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! empty( $wpdb->last_error ) || false === $rows ) {
			throw new \RuntimeException( 'Error al consultar órdenes bloqueadas en la base de datos: ' . $wpdb->last_error );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Calcula un hash SHA-256 determinista del estado de una orden y de los
	 * metadatos protegidos de stock/preventa de un producto (para auditoría/TEST 2).
	 *
	 * @param int $order_id   ID de la orden.
	 * @param int $product_id ID del producto.
	 * @return string
	 */
	public static function snapshot_order_and_product_integrity( int $order_id, int $product_id ): string {
		global $wpdb;

		$orders_table = $wpdb->prefix . 'wc_orders';
		$has_hpos     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_table ) ) === $orders_table;

		$order_row = $has_hpos
			? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id ), ARRAY_A )
			: $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $order_id ), ARRAY_A );

		$order_meta = $has_hpos
			? $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d ORDER BY id ASC", $order_id ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC", $order_id ), ARRAY_A );

		$item_meta = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT oi.order_item_id, oi.order_item_name, oi.order_item_type, oim.meta_key, oim.meta_value
				FROM {$wpdb->prefix}woocommerce_order_items oi
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
				WHERE oi.order_id = %d
				ORDER BY oi.order_item_id ASC, oim.meta_id ASC",
				$order_id
			),
			ARRAY_A
		);

		$in_keys = "'" . implode( "','", array_map( 'esc_sql', self::PROTECTED_META_KEYS ) ) . "'";
		$product_protected_meta = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in_keys proviene de constante cerrada PROTECTED_META_KEYS.
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ({$in_keys}) ORDER BY meta_key ASC, meta_id ASC",
				$product_id
			),
			ARRAY_A
		);

		$payload = array(
			'order_row'              => $order_row,
			'order_meta'             => $order_meta,
			'item_meta'              => $item_meta,
			'product_protected_meta' => $product_protected_meta,
		);

		return hash( 'sha256', (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
}

if ( ! class_exists( '\\AKB_Order_Lock_Guard', false ) ) {
	class_alias( OrderLockGuard::class, '\\AKB_Order_Lock_Guard' );
}
