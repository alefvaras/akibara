<?php
/** Búsqueda administrativa de productos para la edición de reseñas. */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

final class AdminProductSearch {

	public const ACTION_SEARCH = 'akb_rewards_search_products';
	public const NONCE_SEARCH = 'akb_rewards_search_products';

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION_SEARCH, array( $this, 'handle_product_search' ), 10, 0 );
	}

	public function handle_product_search(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
		}

		if ( ! check_ajax_referer( self::NONCE_SEARCH, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Nonce inválido.' ), 403 );
		}

		$term = isset( $_GET['q'] ) && is_string( $_GET['q'] )
			? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) )
			: '';

		if ( mb_strlen( $term ) < 2 && ! ctype_digit( $term ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$like       = '%' . $wpdb->esc_like( $term ) . '%';
		$numeric_id = ctype_digit( $term ) ? (int) $term : 0;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_title, pm.meta_value AS sku
				 FROM %i p
				 LEFT JOIN %i pm
				   ON pm.post_id = p.ID AND pm.meta_key = '_sku'
				 WHERE p.post_type = 'product'
				   AND p.post_status = 'publish'
				   AND (p.ID = %d OR p.post_title LIKE %s OR pm.meta_value LIKE %s)
				 ORDER BY p.post_title ASC
				 LIMIT 12",
				$wpdb->posts,
				$wpdb->postmeta,
				$numeric_id,
				$like,
				$like
			)
		);
		$items = array();

		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || empty( $row->ID ) ) {
				continue;
			}
			$id      = (int) $row->ID;
			$title   = isset( $row->post_title ) ? (string) $row->post_title : ( '#' . $id );
			$sku     = isset( $row->sku ) && is_string( $row->sku ) ? trim( $row->sku ) : '';
			$items[] = array(
				'id'    => $id,
				'title' => $title,
				'sku'   => $sku,
				'label' => sprintf(
					'#%d — %s%s',
					$id,
					$title,
					'' !== $sku ? ' (SKU: ' . $sku . ')' : ''
				),
			);
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

}
