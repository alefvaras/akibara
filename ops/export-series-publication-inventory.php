<?php
/**
 * Export the live, indexable `pa_serie` inventory for publication research.
 *
 * Usage:
 *   wp eval-file - < ops/export-series-publication-inventory.php
 *
 * The script is deliberately read-only. It emits JSON to stdout and never
 * creates options, term meta, posts, files, or remote artifacts.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) {
	fwrite( STDERR, "Run this file through WP-CLI: wp eval-file -\n" );
	exit( 1 );
}

/**
 * Normalize a Rank Math robots term-meta value.
 *
 * @param mixed $raw Stored term-meta value.
 * @return array<int, string>
 */
function akb_series_publication_robot_directives( $raw ): array {
	if ( is_string( $raw ) ) {
		$raw = maybe_unserialize( $raw );
	}

	if ( ! is_array( $raw ) ) {
		return array();
	}

	$directives = array_values(
		array_filter(
			array_map( 'strval', $raw ),
			static fn ( string $value ): bool => '' !== $value
		)
	);
	sort( $directives, SORT_STRING );

	return $directives;
}

/**
 * Product identity fields used to match the exact publisher edition.
 *
 * @param int $product_id Product post ID.
 * @return array<string, mixed>
 */
function akb_series_publication_product_identity( int $product_id ): array {
	$post = get_post( $product_id );
	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	$publishers = wp_get_post_terms(
		$product_id,
		'product_brand',
		array( 'fields' => 'names' )
	);
	$publishers = is_wp_error( $publishers ) ? array() : array_values( array_map( 'strval', $publishers ) );
	sort( $publishers, SORT_STRING );

	return array(
		'id'         => $product_id,
		'title'      => get_the_title( $product_id ),
		'slug'       => $post->post_name,
		'sku'        => (string) get_post_meta( $product_id, '_sku', true ),
		'gtin'       => (string) get_post_meta( $product_id, '_global_unique_id', true ),
		'publishers' => $publishers,
	);
}

$terms = get_terms(
	array(
		'taxonomy'   => 'pa_serie',
		'hide_empty' => true,
		'orderby'    => 'name',
		'order'      => 'ASC',
	)
);

if ( is_wp_error( $terms ) ) {
	WP_CLI::error( 'Could not read pa_serie terms: ' . $terms->get_error_message() );
}

$rows     = array();
$excluded = array();

foreach ( $terms as $term ) {
	if ( ! $term instanceof WP_Term ) {
		continue;
	}

	$robots = akb_series_publication_robot_directives(
		get_term_meta( $term->term_id, 'rank_math_robots', true )
	);

	if ( in_array( 'noindex', $robots, true ) ) {
		$excluded[] = array(
			'term_id' => (int) $term->term_id,
			'name'    => $term->name,
			'slug'    => $term->slug,
			'robots'  => $robots,
		);
		continue;
	}

	$product_ids = get_posts(
		array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
			'tax_query'              => array(
				array(
					'taxonomy' => 'pa_serie',
					'field'    => 'term_id',
					'terms'    => array( (int) $term->term_id ),
				),
			),
		)
	);

	$products = array_values(
		array_filter(
			array_map( 'akb_series_publication_product_identity', array_map( 'intval', $product_ids ) )
		)
	);
	$edition_publishers = array_values(
		array_unique(
			array_merge(
				...array_map(
					static fn ( array $product ): array => array_map( 'strval', $product['publishers'] ?? array() ),
					$products
				)
			)
		)
	);
	sort( $edition_publishers, SORT_STRING );
	if ( 1 !== count( $edition_publishers ) ) {
		WP_CLI::error(
			sprintf(
				'Expected one edition publisher for pa_serie term %d; observed %d.',
				$term->term_id,
				count( $edition_publishers )
			)
		);
	}

	$link = get_term_link( $term, 'pa_serie' );
	if ( is_wp_error( $link ) ) {
		WP_CLI::error(
			sprintf( 'Could not resolve pa_serie term %d: %s', $term->term_id, $link->get_error_message() )
		);
	}

	$rows[] = array(
		'term_id'           => (int) $term->term_id,
		'name'              => $term->name,
		'slug'              => $term->slug,
		'landing_url'       => $link,
		'catalog_count'      => count( $products ),
		'term_count'         => (int) $term->count,
		'description'        => term_description( $term, 'pa_serie' ),
		'robots'            => $robots,
		'edition_publisher'  => $edition_publishers[0],
		'estado'            => (string) get_term_meta( $term->term_id, '_akb_serie_estado', true ),
		'tomos_obra'        => (string) get_term_meta( $term->term_id, '_akb_serie_tomos_obra', true ),
		'estado_edicion'    => (string) get_term_meta( $term->term_id, '_akb_serie_estado_edicion', true ),
		'tomos_edicion'     => (string) get_term_meta( $term->term_id, '_akb_serie_tomos_edicion', true ),
		'products'          => $products,
	);
}

$output = array(
	'schema_version' => 2,
	'generated_at'   => gmdate( 'c' ),
	'environment'    => wp_get_environment_type(),
	'site_url'       => site_url( '/' ),
	'taxonomy'       => 'pa_serie',
	'summary'        => array(
		'nonempty_terms'  => count( $terms ),
		'indexable_terms' => count( $rows ),
		'excluded_noindex' => count( $excluded ),
		'products'        => array_sum( array_column( $rows, 'catalog_count' ) ),
	),
	'excluded_noindex' => $excluded,
	'rows'             => $rows,
);

WP_CLI::line(
	wp_json_encode(
		$output,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	)
);
