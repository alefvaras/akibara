<?php
/**
 * Preserve explicit availability when the asynchronous index cannot serve.
 *
 * @package Akibara\Search
 */
declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 */
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
	public static function apply( \WP_Query $query, FilterSet $filters, array $definitions ): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
		$availability = $filters->values( 'disponibilidad' );
		if ( empty( $availability ) ) {
			return true;
		}
		$stock_map = array( 'instock' => 'instock', 'outofstock' => 'outofstock' );
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

		$query->set( 'meta_query', $native_meta );
		$query->set( 'tax_query', $native_tax );
		$query->set( '_akb_native_availability_fallback', true );
		return true;
	}
}
