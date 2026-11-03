<?php
/**
 * Preserve explicit availability when the asynchronous index cannot serve.
 *
 * @package Akibara\Search
 */
declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

final class NativeAvailabilityFallback {

	/**
	 * Add authoritative WooCommerce constraints without replacing archive scope.
	 *
	 * Unsupported index-only criteria return false before mutating the query.
	 *
	 * @param \WP_Query           $query       Main product query.
	 * @param FilterSet           $filters     Original normalized selections.
	 * @param array<string,array> $definitions Authoritative facet definitions.
	 * @return bool Whether every selected criterion can be preserved safely.
	 */
	public static function apply( \WP_Query $query, FilterSet $filters, array $definitions ): bool {
		$availability = $filters->values( 'disponibilidad' );
		if ( empty( $availability ) ) {
			return true;
		}
		$stock_map = array( 'instock' => 'instock', 'outofstock' => 'outofstock', 'a_pedido' => 'onbackorder', 'preventa' => 'onbackorder' );
		foreach ( $availability as $value ) {
			if ( ! is_string( $value ) || ! isset( $stock_map[ $value ] ) ) {
				return false;
			}
		}
		if ( $filters->has_search() ) {
			return false; // Native WP text search is not equivalent to QueryEngine.
		}
		$vars = $query->query_vars;
		$meta = $vars['meta_query'] ?? array();
		$tax  = $vars['tax_query'] ?? array();
		if ( '' === $meta ) {
			$meta = array();
		}
		if ( '' === $tax ) {
			$tax = array();
		}
		if ( ! is_array( $meta ) || ! is_array( $tax ) ) {
			return false;
		}
		$extra_tax = array();
		foreach ( $filters->all() as $facet => $values ) {
			if ( 'disponibilidad' === $facet ) {
				continue;
			}
			$def = $definitions[ $facet ] ?? array();
			if ( 'taxonomy' !== ( $def['type'] ?? '' ) || ! is_string( $def['taxonomy'] ?? null ) || ! taxonomy_exists( $def['taxonomy'] ) ) {
				return false; // Price, offer and novelty need their own index authority.
			}
			$extra_tax[] = array(
				'taxonomy' => $def['taxonomy'],
				'field' => 'slug',
				'terms' => $values,
				'operator' => 'IN',
				'include_children' => ! empty( $def['hierarchical'] ) || ! empty( $def['parent_term'] ),
			);
		}
		$stocks = array_values( array_unique( array_map( static fn( string $value ): string => $stock_map[ $value ], $availability ) ) );
		$native_meta = array( 'relation' => 'AND' );
		if ( ! empty( $meta ) ) {
			$native_meta[] = $meta;
		}
		$native_meta[] = array( 'key' => '_stock_status', 'value' => $stocks, 'compare' => 'IN' );
		$native_tax = array( 'relation' => 'AND' );
		if ( ! empty( $tax ) ) {
			$native_tax[] = $tax;
		}
		$native_tax = array_merge( $native_tax, $extra_tax );

		// Only a half of onbackorder needs per-product business classification.
		$split_backorders = in_array( 'a_pedido', $availability, true ) !== in_array( 'preventa', $availability, true );
		$ids = null;
		if ( $split_backorders ) {
			$args = array_merge( $vars, array(
				'post_type' => 'product', 'post_status' => 'publish',
				'posts_per_page' => -1, 'nopaging' => true, 'paged' => 1,
				'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'ID',
				'order' => 'ASC', 'suppress_filters' => false,
				'meta_query' => $native_meta, 'tax_query' => $native_tax,
			) );
			unset( $args['offset'] );
			$candidates = get_posts( $args );
			if ( ! is_array( $candidates ) ) {
				return false;
			}
			$candidate_ids = array();
			foreach ( $candidates as $raw_id ) {
				if ( ! is_scalar( $raw_id ) || ! ctype_digit( (string) $raw_id ) || (int) $raw_id < 1 ) {
					return false;
				}
				$candidate_ids[] = (int) $raw_id;
			}
			if ( array() !== $candidate_ids && function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $candidate_ids, false, true );
			}
			$ids = array();
			foreach ( $candidate_ids as $id ) {
				$product = wc_get_product( $id );
				if ( ! $product instanceof \WC_Product ) {
					return false;
				}
				$resolved = StockLabels::availability( $product->get_stock_status(), $id );
				if ( in_array( $resolved['value'] ?? null, $availability, true ) ) {
					$ids[] = $id;
				}
			}
		}
		$query->set( 'meta_query', $native_meta );
		$query->set( 'tax_query', $native_tax );
		if ( null !== $ids ) {
			$query->set( 'post__in', empty( $ids ) ? array( -1 ) : array_values( array_unique( $ids ) ) );
		}
		$query->set( '_akb_native_availability_fallback', true );
		return true;
	}
}
