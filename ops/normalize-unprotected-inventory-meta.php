<?php
/**
 * Normalizador controlado de metadatos históricos de inventario para productos NO protegidos.
 *
 * Reglas inviolables (Regla 18 / Entrega):
 * - Excluye estrictamente cualquier producto o variación vinculado a pedidos no terminales
 *   (pending, processing, shipping-progress, on-hold o cualquier estado custom no terminal).
 * - Nunca modifica cantidades físicas positivas (_stock > 0), precios regulares (_regular_price)
 *   ni el estado comercial (Availability::of()).
 * - Utiliza exclusivamente WC_Product CRUD ($product->save()) con verificación CAS por producto
 *   y candado de base de datos (GET_LOCK) para garantizar sincronización de wc_product_meta_lookup
 *   y product_visibility.
 *
 * Uso vía WP-CLI (sin dejar archivos en el servidor remoto):
 *   AKB_INVENTORY_NORMALIZE_MODE=plan wp eval-file - < ops/normalize-unprotected-inventory-meta.php
 *   AKB_INVENTORY_NORMALIZE_MODE=apply wp eval-file - < ops/normalize-unprotected-inventory-meta.php
 *
 * @package Akibara\Ops
 */

declare( strict_types = 1 );

use Akibara\Business\Modules\Stock\Availability;
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) {
	throw new RuntimeException( 'WP-CLI runtime required.' );
}

$mode = getenv( 'AKB_INVENTORY_NORMALIZE_MODE' );
if ( ! is_string( $mode ) || '' === $mode ) {
	$mode = 'plan';
}
if ( ! in_array( $mode, array( 'plan', 'apply' ), true ) ) {
	throw new RuntimeException( 'Invalid AKB_INVENTORY_NORMALIZE_MODE; expected plan or apply.' );
}

if ( ! function_exists( 'akb_inv_norm_fingerprint' ) ) {
	/**
	 * Huella canónica SHA-256 de un estado estructurado.
	 *
	 * @param array<string,mixed> $state Estado a firmar.
	 * @return string
	 */
	function akb_inv_norm_fingerprint( array $state ): string {
		$sort = static function ( &$value ) use ( &$sort ): void {
			if ( ! is_array( $value ) ) {
				return;
			}
			foreach ( $value as &$child ) {
				$sort( $child );
			}
			unset( $child );
			if ( ! array_is_list( $value ) ) {
				ksort( $value );
			}
		};
		$sort( $state );
		return hash( 'sha256', (string) wp_json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
}

if ( ! function_exists( 'akb_inv_norm_crud_state' ) ) {
	/**
	 * Propiedades CRUD crudas (contexto edit) del producto.
	 *
	 * @param WC_Product $product Producto.
	 * @return array<string,mixed>
	 */
	function akb_inv_norm_crud_state( WC_Product $product ): array {
		$from = $product->get_date_on_sale_from( 'edit' );
		$to   = $product->get_date_on_sale_to( 'edit' );
		return array(
			'type'             => $product->get_type(),
			'status'           => $product->get_status( 'edit' ),
			'stock_status'     => $product->get_stock_status( 'edit' ),
			'manage_stock'     => $product->get_manage_stock( 'edit' ),
			'stock_quantity'   => $product->get_stock_quantity( 'edit' ),
			'backorders'       => $product->get_backorders( 'edit' ),
			'regular_price'    => $product->get_regular_price( 'edit' ),
			'sale_price'       => $product->get_sale_price( 'edit' ),
			'price'            => $product->get_price( 'edit' ),
			'sale_from'        => $from ? $from->getTimestamp() : null,
			'sale_to'          => $to ? $to->getTimestamp() : null,
			'low_stock_amount' => $product->get_low_stock_amount( 'edit' ),
		);
	}
}

if ( ! function_exists( 'akb_inv_norm_read_active_orders' ) ) {
	/**
	 * Lee todos los pedidos y construye el mapa de productos protegidos por pedidos no terminales.
	 *
	 * @param wpdb $wpdb Instancia DB.
	 * @return array{
	 *   hpos: bool,
	 *   terminal: list<string>,
	 *   status_counts: array<string,int>,
	 *   total_orders: int,
	 *   active_orders: int,
	 *   protected: array<int,list<array<string,mixed>>>,
	 *   links_fingerprint: string
	 * }
	 */
	function akb_inv_norm_read_active_orders( wpdb $wpdb ): array {
		$hpos   = OrderUtil::custom_orders_table_usage_is_enabled();
		$orders = $hpos
			? $wpdb->get_results( "SELECT id,status FROM {$wpdb->prefix}wc_orders WHERE type='shop_order' ORDER BY id", ARRAY_A )
			: $wpdb->get_results( "SELECT ID AS id,post_status AS status FROM {$wpdb->posts} WHERE post_type='shop_order' ORDER BY ID", ARRAY_A );
		if ( ! is_array( $orders ) || '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read order inventory.' );
		}
		$terminal      = array( 'completed', 'cancelled', 'refunded', 'failed', 'trash' );
		$active        = array();
		$status_counts = array();
		foreach ( $orders as $row ) {
			$status                   = (string) preg_replace( '/^wc-/', '', (string) $row['status'] );
			$status_counts[ $status ] = ( $status_counts[ $status ] ?? 0 ) + 1;
			if ( ! in_array( $status, $terminal, true ) ) {
				$active[ (int) $row['id'] ] = $status;
			}
		}
		ksort( $status_counts );
		$protected = array();
		$links     = array();
		foreach ( array_chunk( array_keys( $active ), 100 ) as $order_ids ) {
			$list = implode( ',', array_map( 'intval', $order_ids ) );
			$rows = $wpdb->get_results(
				"SELECT i.order_id,i.order_item_id,m.meta_key,m.meta_value
				FROM {$wpdb->prefix}woocommerce_order_items i
				JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id=i.order_item_id
				WHERE i.order_id IN ({$list}) AND i.order_item_type='line_item'
				AND m.meta_key IN ('_product_id','_variation_id','_qty')
				ORDER BY i.order_id,i.order_item_id,m.meta_id",
				ARRAY_A
			);
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Could not read active-order product links.' );
			}
			$items = array();
			foreach ( $rows as $row ) {
				$key = (int) $row['order_item_id'];
				if ( isset( $items[ $key ]['meta'][ $row['meta_key'] ] ) ) {
					throw new RuntimeException( 'Duplicate active-order item metadata needs review.' );
				}
				$items[ $key ]['order_id']                 = (int) $row['order_id'];
				$items[ $key ]['meta'][ $row['meta_key'] ] = (string) $row['meta_value'];
			}
			$expected_items = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN ({$list}) AND order_item_type='line_item'" );
			if ( '' !== $wpdb->last_error || null === $expected_items || count( $items ) !== (int) $expected_items ) {
				throw new RuntimeException( 'The active-order line inventory is incomplete.' );
			}
			foreach ( $items as $item_id => $item ) {
				$entry = array(
					'order_id'      => $item['order_id'],
					'order_status'  => $active[ $item['order_id'] ],
					'order_item_id' => $item_id,
					'quantity'      => $item['meta']['_qty'] ?? null,
					'product_id'    => (int) ( $item['meta']['_product_id'] ?? 0 ),
					'variation_id'  => (int) ( $item['meta']['_variation_id'] ?? 0 ),
				);
				if ( $entry['product_id'] <= 0 || ! isset( $item['meta']['_qty'] ) ) {
					throw new RuntimeException( 'An active order line lacks a parent product or quantity.' );
				}
				$links[] = $entry;
				foreach ( array_unique( array( $entry['product_id'], $entry['variation_id'] ) ) as $pid ) {
					if ( $pid > 0 ) {
						$protected[ $pid ][] = $entry;
					}
				}
			}
		}
		return array(
			'hpos'              => $hpos,
			'terminal'          => $terminal,
			'status_counts'     => $status_counts,
			'total_orders'      => count( $orders ),
			'active_orders'     => count( $active ),
			'protected'         => $protected,
			'links_fingerprint' => akb_inv_norm_fingerprint( $links ),
		);
	}
}

if ( ! function_exists( 'akb_inv_norm_snapshot_product' ) ) {
	/**
	 * Captura el estado completo de un producto y calcula su propuesta.
	 *
	 * @param int          $id            ID de producto.
	 * @param list<string> $critical_keys Metadatos críticos.
	 * @param wpdb         $wpdb          Instancia DB.
	 * @return array<string,mixed>
	 */
	function akb_inv_norm_snapshot_product( int $id, array $critical_keys, wpdb $wpdb ): array {
		clean_post_cache( $id );
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			throw new RuntimeException( "Published product {$id} could not be read." );
		}
		$before  = akb_inv_norm_crud_state( $product );
		$meta    = get_post_meta( $id );
		$raw     = array();
		$reasons = array();
		foreach ( $critical_keys as $key ) {
			$raw[ $key ] = $meta[ $key ] ?? array();
			if ( count( $raw[ $key ] ) > 1 ) {
				$reasons[] = 'duplicate_inventory_meta:' . $key;
			}
		}
		$visibility = wp_get_object_terms( $id, 'product_visibility', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $visibility ) ) {
			throw new RuntimeException( "Could not read product visibility for {$id}." );
		}
		sort( $visibility );
		$lookup = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT stock_quantity,stock_status,min_price,max_price,onsale FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d",
				$id
			),
			ARRAY_A
		);
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( "Could not read product lookup for {$id}." );
		}
		$source      = array(
			'crud'           => $before,
			'inventory_meta' => $raw,
			'visibility'     => $visibility,
			'lookup'         => $lookup,
		);
		$fingerprint = akb_inv_norm_fingerprint( $source );

		$candidate    = clone $product;
		$status       = $before['stock_status'];
		$quantity     = $before['stock_quantity'];
		$raw_quantity = $raw['_stock'][0] ?? '';
		if ( '' !== $raw_quantity && null !== $raw_quantity && ! is_numeric( $raw_quantity ) ) {
			$reasons[] = 'invalid_quantity_metadata';
		}
		if ( 'simple' !== $before['type'] ) {
			$reasons[] = 'unsupported_product_type';
		}
		if ( 'instock' === $status ) {
			if ( true !== $before['manage_stock'] || ! is_numeric( $quantity ) || $quantity <= 0 ) {
				$reasons[] = 'instock_without_managed_positive_quantity';
			}
			$candidate->set_backorders( 'no' );
		} elseif ( in_array( $status, array( 'onbackorder', 'outofstock' ), true ) ) {
			if ( is_numeric( $quantity ) && $quantity > 0 ) {
				$reasons[] = 'positive_quantity_must_not_be_erased';
			}
			$candidate->set_manage_stock( false );
			$candidate->set_stock_quantity( null );
			$candidate->set_backorders( 'no' );
			$regular = $before['regular_price'];
			if ( ! is_numeric( $regular ) || ( 'onbackorder' === $status ? (float) $regular <= 0 : (float) $regular < 0 ) ) {
				$reasons[] = 'invalid_regular_price';
			} else {
				$sale  = 'onbackorder' === $status ? (string) round( (float) $regular * 0.95 ) : '';
				$price = 'onbackorder' === $status ? $sale : $regular;
				$candidate->set_sale_price( $sale );
				$candidate->set_price( $price );
				if ( $before['sale_price'] === $sale && $before['price'] !== $price ) {
					$reasons[] = 'price_only_drift_needs_official_resync';
				}
			}
			if ( null !== $before['sale_from'] || null !== $before['sale_to'] ) {
				$reasons[] = 'sale_schedule_requires_review';
			}
		} else {
			$reasons[] = 'unknown_stock_status';
		}

		$candidate->validate_props();
		$proposed = akb_inv_norm_crud_state( $candidate );
		if ( $proposed['stock_status'] !== $status ) {
			$reasons[] = 'native_validation_would_change_commercial_stock_status';
		}
		$comm_before   = Availability::of( $product );
		$comm_proposed = Availability::of( $candidate );
		if ( $comm_before !== $comm_proposed ) {
			$reasons[] = 'commercial_state_would_change';
		}

		$proposed_visibility = array_values( array_diff( $visibility, array( 'outofstock' ) ) );
		if ( 'outofstock' === $status ) {
			$proposed_visibility[] = 'outofstock';
		}
		sort( $proposed_visibility );

		$changes = array();
		foreach ( $proposed as $key => $value ) {
			if ( $value !== $before[ $key ] ) {
				$changes[ $key ] = array(
					'before'   => $before[ $key ],
					'proposed' => $value,
				);
			}
		}
		if ( $visibility !== $proposed_visibility ) {
			$changes['visibility'] = array(
				'before'   => $visibility,
				'proposed' => $proposed_visibility,
			);
		}

		return array(
			'id'                        => $id,
			'title'                     => $product->get_name( 'edit' ),
			'sku'                       => $product->get_sku( 'edit' ),
			'commercial_state'          => $comm_before,
			'proposed_commercial_state' => $comm_proposed,
			'source_fingerprint'        => $fingerprint,
			'before'                    => $source,
			'proposed_crud'             => $proposed,
			'proposed_visibility'       => $proposed_visibility,
			'changes'                   => $changes,
			'blocked_reasons'           => array_values( array_unique( $reasons ) ),
		);
	}
}

global $wpdb;
$lock_name     = 'akb_inventory_normalize_lock';
$lock_acquired = false;
$started_at    = gmdate( 'c' );
$critical_keys = array(
	'_stock_status',
	'_manage_stock',
	'_stock',
	'_backorders',
	'_regular_price',
	'_sale_price',
	'_price',
	'_sale_price_dates_from',
	'_sale_price_dates_to',
	'_low_stock_amount',
	'_akb_eta_type',
	'_akb_release_date',
	'_akb_eta_release_date',
);

try {
	$got_lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock_name ) );
	if ( 1 !== $got_lock ) {
		throw new RuntimeException( 'Could not acquire named lock akb_inventory_normalize_lock.' );
	}
	$lock_acquired = true;

	$order_protection = akb_inv_norm_read_active_orders( $wpdb );
	$protected        = $order_protection['protected'];

	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' ORDER BY ID" );
	if ( ! is_array( $ids ) || array() === $ids || '' !== $wpdb->last_error ) {
		throw new RuntimeException( 'Could not read the published catalog.' );
	}

	$report = array(
		'schema'          => 'akibara.inventory.normalizer.v1',
		'mode'            => $mode,
		'created_at_utc'  => $started_at,
		'environment'     => array(
			'home'                 => home_url(),
			'type'                 => wp_get_environment_type(),
			'database_fingerprint' => hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ),
			'wordpress'            => $GLOBALS['wp_version'],
			'woocommerce'          => WC_VERSION,
			'hpos'                 => $order_protection['hpos'],
		),
		'protection'      => array(
			'terminal_statuses'               => $order_protection['terminal'],
			'orders_by_status'                => $order_protection['status_counts'],
			'total_orders'                    => $order_protection['total_orders'],
			'active_orders'                   => $order_protection['active_orders'],
			'linked_product_or_variation_ids' => count( $protected ),
			'active_order_links_fingerprint'  => $order_protection['links_fingerprint'],
		),
		'summary'         => array(
			'published_products' => count( $ids ),
			'unchanged'          => 0,
			'eligible'           => 0,
			'applied'            => 0,
			'protected_changed'  => 0,
			'protected_total'    => 0,
			'blocked'            => 0,
		),
		'eligible'             => array(),
		'applied'              => array(),
		'protected_exceptions' => array(),
		'blocked'              => array(),
	);

	foreach ( array_chunk( $ids, 50 ) as $batch ) {
		update_meta_cache( 'post', $batch );
		foreach ( $batch as $raw_id ) {
			$id    = (int) $raw_id;
			$entry = akb_inv_norm_snapshot_product( $id, $critical_keys, $wpdb );
			if ( isset( $protected[ $id ] ) ) {
				$entry['protected_reason']       = 'Linked to active or non-terminal orders; strictly excluded from normalization.';
				$entry['orders']                 = $protected[ $id ];
				$report['protected_exceptions'][] = $entry;
				if ( array() !== $entry['changes'] ) {
					$report['summary']['protected_changed']++;
				}
			} elseif ( array() !== $entry['blocked_reasons'] ) {
				$report['blocked'][] = $entry;
				$report['summary']['blocked']++;
			} elseif ( array() !== $entry['changes'] ) {
				$report['eligible'][] = $entry;
				$report['summary']['eligible']++;
			} else {
				$report['summary']['unchanged']++;
			}
		}
	}
	$report['summary']['protected_total'] = count( $report['protected_exceptions'] );

	if ( 'apply' === $mode && array() !== $report['eligible'] ) {
		// Re-verify active orders right before applying writes.
		$fresh_protection = akb_inv_norm_read_active_orders( $wpdb );
		$fresh_protected  = $fresh_protection['protected'];

		foreach ( $report['eligible'] as $candidate_entry ) {
			$id = (int) $candidate_entry['id'];
			if ( isset( $fresh_protected[ $id ] ) ) {
				throw new RuntimeException( "Product {$id} became linked to an active order; aborting." );
			}
			// Re-read and verify CAS fingerprint before modifying.
			$current = akb_inv_norm_snapshot_product( $id, $critical_keys, $wpdb );
			if ( $current['source_fingerprint'] !== $candidate_entry['source_fingerprint'] ) {
				throw new RuntimeException( "CAS mismatch on product {$id}; state changed since plan." );
			}

			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product ) {
				throw new RuntimeException( "Could not instantiate WC_Product {$id} for save." );
			}
			$status = $current['before']['crud']['stock_status'];
			if ( 'instock' === $status ) {
				$product->set_backorders( 'no' );
			} elseif ( in_array( $status, array( 'onbackorder', 'outofstock' ), true ) ) {
				$regular = $current['before']['crud']['regular_price'];
				$sale    = 'onbackorder' === $status ? (string) round( (float) $regular * 0.95 ) : '';
				$price   = 'onbackorder' === $status ? $sale : $regular;
				$product->set_manage_stock( false );
				$product->set_stock_quantity( null );
				$product->set_backorders( 'no' );
				$product->set_sale_price( $sale );
				$product->set_price( $price );
			}
			$product->save();

			// Ensure product_visibility matches proposed_visibility (especially for outofstock term).
			$after_vis = wp_get_object_terms( $id, 'product_visibility', array( 'fields' => 'slugs' ) );
			if ( ! is_wp_error( $after_vis ) ) {
				sort( $after_vis );
				if ( $after_vis !== $candidate_entry['proposed_visibility'] ) {
					wp_set_object_terms( $id, $candidate_entry['proposed_visibility'], 'product_visibility', false );
				}
			}

			// Verify post-save state.
			$verified = akb_inv_norm_snapshot_product( $id, $critical_keys, $wpdb );
			if ( $verified['before']['crud']['stock_status'] !== $candidate_entry['proposed_crud']['stock_status']
				|| $verified['before']['crud']['manage_stock'] !== $candidate_entry['proposed_crud']['manage_stock']
				|| $verified['before']['crud']['stock_quantity'] !== $candidate_entry['proposed_crud']['stock_quantity']
				|| $verified['before']['crud']['backorders'] !== $candidate_entry['proposed_crud']['backorders']
				|| $verified['before']['crud']['sale_price'] !== $candidate_entry['proposed_crud']['sale_price']
				|| $verified['before']['crud']['price'] !== $candidate_entry['proposed_crud']['price']
				|| $verified['before']['visibility'] !== $candidate_entry['proposed_visibility'] ) {
				throw new RuntimeException( "Post-save verification failed on product {$id}." );
			}

			$report['applied'][] = array(
				'id'                 => $id,
				'title'              => $candidate_entry['title'],
				'sku'                => $candidate_entry['sku'],
				'commercial_state'   => $verified['commercial_state'],
				'before_fingerprint' => $candidate_entry['source_fingerprint'],
				'after_fingerprint'  => $verified['source_fingerprint'],
				'changes'            => $candidate_entry['changes'],
				'before'             => $candidate_entry['before'],
				'after'              => $verified['before'],
			);
			$report['summary']['applied']++;
		}
	}

	$report['finished_at_utc'] = gmdate( 'c' );
} finally {
	if ( $lock_acquired ) {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	}
}

echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
