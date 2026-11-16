<?php
/**
 * Read-only commerce fingerprint for the Akibara code-promotion gate.
 *
 * This file is passed inline to `wp eval`; it is never copied to the remote
 * filesystem. Each line is: dataset<TAB>stable-key<TAB>SHA-256(row JSON).
 * The local comparator requires the complete before/after snapshot to remain
 * byte-identical while the site is under verified maintenance.
 */

defined( 'ABSPATH' ) || exit( 1 );

global $wpdb;

$legacy_orders           = "`{$wpdb->posts}` WHERE post_type IN ('shop_order','shop_order_refund')";
$legacy_order_ids        = "SELECT ID FROM {$legacy_orders}";
$legacy_notes            = "`{$wpdb->comments}` WHERE comment_type = 'order_note' OR comment_post_ID IN ({$legacy_order_ids})";
$legacy_note_ids         = "SELECT comment_ID FROM {$legacy_notes}";
$coupon_posts            = "`{$wpdb->posts}` WHERE post_type = 'shop_coupon'";
$coupon_post_ids         = "SELECT ID FROM {$coupon_posts}";
$order_item_ids          = "SELECT order_item_id FROM `{$wpdb->prefix}woocommerce_order_items`";
$download_permission_ids = "SELECT permission_id FROM `{$wpdb->prefix}woocommerce_downloadable_product_permissions`";
$fulfillment_ids         = "SELECT fulfillment_id FROM `{$wpdb->prefix}wc_order_fulfillments`";

$specs = array(
	array( 'legacy_orders', $wpdb->posts, array( 'ID' ), "post_type IN ('shop_order','shop_order_refund')" ),
	array( 'legacy_order_meta', $wpdb->postmeta, array( 'meta_id' ), "post_id IN ({$legacy_order_ids})" ),
	array( 'legacy_order_notes', $wpdb->comments, array( 'comment_ID' ), "comment_type = 'order_note' OR comment_post_ID IN ({$legacy_order_ids})" ),
	array( 'legacy_order_note_meta', $wpdb->commentmeta, array( 'meta_id' ), "comment_id IN ({$legacy_note_ids})" ),
	array( 'coupon_posts', $wpdb->posts, array( 'ID' ), "post_type = 'shop_coupon'" ),
	array( 'coupon_post_meta', $wpdb->postmeta, array( 'meta_id' ), "post_id IN ({$coupon_post_ids})" ),
	array( 'wordpress_users', $wpdb->users, array( 'ID' ), '1=1' ),
	array( 'wordpress_user_meta', $wpdb->usermeta, array( 'umeta_id' ), '1=1' ),
	array( 'hpos_orders', $wpdb->prefix . 'wc_orders', array( 'id' ), '1=1' ),
	array( 'hpos_order_addresses', $wpdb->prefix . 'wc_order_addresses', array( 'id' ), '1=1' ),
	array( 'hpos_order_operational_data', $wpdb->prefix . 'wc_order_operational_data', array( 'id' ), '1=1' ),
	array( 'hpos_order_meta', $wpdb->prefix . 'wc_orders_meta', array( 'id' ), '1=1' ),
	array( 'woocommerce_order_items', $wpdb->prefix . 'woocommerce_order_items', array( 'order_item_id' ), '1=1' ),
	array( 'woocommerce_order_itemmeta', $wpdb->prefix . 'woocommerce_order_itemmeta', array( 'meta_id' ), "order_item_id IN ({$order_item_ids})" ),
	array( 'woocommerce_download_permissions', $wpdb->prefix . 'woocommerce_downloadable_product_permissions', array( 'permission_id' ), '1=1' ),
	array( 'woocommerce_download_log', $wpdb->prefix . 'wc_download_log', array( 'download_log_id' ), "permission_id IN ({$download_permission_ids})" ),
	array( 'woocommerce_payment_tokens', $wpdb->prefix . 'woocommerce_payment_tokens', array( 'token_id' ), '1=1' ),
	array( 'woocommerce_payment_token_meta', $wpdb->prefix . 'woocommerce_payment_tokenmeta', array( 'meta_id' ), '1=1' ),
	array( 'woocommerce_customers', $wpdb->prefix . 'wc_customer_lookup', array( 'customer_id' ), '1=1' ),
	array( 'woocommerce_order_notes', $wpdb->prefix . 'wc_order_notes', array( 'order_note_id' ), '1=1' ),
	array( 'woocommerce_order_stats', $wpdb->prefix . 'wc_order_stats', array( 'order_id' ), '1=1' ),
	array( 'woocommerce_order_product_lookup', $wpdb->prefix . 'wc_order_product_lookup', array( 'order_item_id', 'order_id' ), '1=1' ),
	array( 'woocommerce_order_tax_lookup', $wpdb->prefix . 'wc_order_tax_lookup', array( 'order_id', 'tax_rate_id' ), '1=1' ),
	array( 'woocommerce_order_coupon_lookup', $wpdb->prefix . 'wc_order_coupon_lookup', array( 'order_id', 'coupon_id' ), '1=1' ),
	array( 'woocommerce_tax_rates', $wpdb->prefix . 'woocommerce_tax_rates', array( 'tax_rate_id' ), '1=1' ),
	array( 'woocommerce_tax_rate_locations', $wpdb->prefix . 'woocommerce_tax_rate_locations', array( 'location_id' ), '1=1' ),
	array( 'woocommerce_tax_rate_classes', $wpdb->prefix . 'wc_tax_rate_classes', array( 'tax_rate_class_id' ), '1=1' ),
	array( 'woocommerce_shipping_zones', $wpdb->prefix . 'woocommerce_shipping_zones', array( 'zone_id' ), '1=1' ),
	array( 'woocommerce_shipping_zone_locations', $wpdb->prefix . 'woocommerce_shipping_zone_locations', array( 'location_id' ), '1=1' ),
	array( 'woocommerce_shipping_zone_methods', $wpdb->prefix . 'woocommerce_shipping_zone_methods', array( 'instance_id' ), '1=1' ),
	array( 'woocommerce_api_keys', $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' ), '1=1' ),
	array( 'woocommerce_webhooks', $wpdb->prefix . 'wc_webhooks', array( 'webhook_id' ), '1=1' ),
	array( 'woocommerce_reserved_stock', $wpdb->prefix . 'wc_reserved_stock', array( 'order_id', 'product_id' ), '1=1' ),
	array( 'woocommerce_order_fulfillments', $wpdb->prefix . 'wc_order_fulfillments', array( 'fulfillment_id' ), '1=1' ),
	array( 'woocommerce_order_fulfillment_meta', $wpdb->prefix . 'wc_order_fulfillment_meta', array( 'meta_id' ), "fulfillment_id IN ({$fulfillment_ids})" ),
);

foreach ( $specs as $spec ) {
	list( $dataset, $table, $key_columns, $where ) = $spec;
	$exists = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
	);

	if ( $exists !== $table ) {
		echo "@dataset\t{$dataset}\tabsent\n";
		continue;
	}

	$quoted_table = '`' . str_replace( '`', '``', $table ) . '`';
	$quoted_keys  = array();
	foreach ( $key_columns as $key_column ) {
		$quoted_key   = '`' . str_replace( '`', '``', $key_column ) . '`';
		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$quoted_table} LIKE %s",
				$wpdb->esc_like( $key_column )
			)
		);
		if ( $column_exists !== $key_column ) {
			fwrite( STDERR, "Missing key column {$key_column} for {$dataset}\n" );
			exit( 2 );
		}
		$quoted_keys[] = $quoted_key;
	}

	echo "@dataset\t{$dataset}\tpresent\n";
	$last_key = array_fill( 0, count( $key_columns ), -1 );
	do {
		$cursor_parts  = array();
		$cursor_values = array();
		foreach ( $quoted_keys as $key_index => $quoted_key ) {
			$part = array();
			for ( $prefix_index = 0; $prefix_index < $key_index; $prefix_index++ ) {
				$part[]          = "{$quoted_keys[$prefix_index]} = %d";
				$cursor_values[] = $last_key[ $prefix_index ];
			}
			$part[]          = "{$quoted_key} > %d";
			$cursor_values[] = $last_key[ $key_index ];
			$cursor_parts[]  = '(' . implode( ' AND ', $part ) . ')';
		}

		$cursor_where = '(' . implode( ' OR ', $cursor_parts ) . ')';
		$order_by     = implode( ', ', $quoted_keys );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$quoted_table}
				 WHERE ({$where}) AND {$cursor_where}
				 ORDER BY {$order_by}
				 LIMIT 500",
				...$cursor_values
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			fwrite( STDERR, "Fingerprint query failed for {$dataset}\n" );
			exit( 2 );
		}

		foreach ( $rows as $row ) {
			$row_key = array();
			foreach ( $key_columns as $key_column ) {
				if ( ! array_key_exists( $key_column, $row ) ) {
					fwrite( STDERR, "Missing key {$key_column} for {$dataset}\n" );
					exit( 3 );
				}

				$key_value = (string) $row[ $key_column ];
				if ( 1 !== preg_match( '/^-?[0-9]+$/D', $key_value ) ) {
					fwrite( STDERR, "Non-integer key {$key_column} for {$dataset}\n" );
					exit( 3 );
				}
				$row_key[] = $key_value;
			}

			$json = wp_json_encode(
				$row,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
			);
			if ( ! is_string( $json ) ) {
				fwrite( STDERR, "Could not encode {$dataset} row\n" );
				exit( 4 );
			}

			echo $dataset . "\t" . implode( ':', $row_key ) . "\t" . hash( 'sha256', $json ) . "\n";
			$last_key = array_map( 'intval', $row_key );
		}
	} while ( count( $rows ) === 500 );
}
