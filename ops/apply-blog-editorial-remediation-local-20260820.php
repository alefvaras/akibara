<?php
/**
 * Apply the reviewed active-blog editorial package to Akibara's Docker clone.
 *
 * This local-only script changes the six managed editorial/SEO fields and the
 * reviewed 24977 publish -> draft transition. Slugs, dates, taxonomies, images,
 * attachment metadata and every excluded post remain outside the write scope.
 *
 * Usage inside the WP-CLI container:
 *   wp eval-file /tmp/apply-blog-editorial-remediation-local-20260820.php \
 *     /tmp/blog-editorial-desired-20260820.json \
 *     /tmp/akb-blog-editorial-local-receipt-0123456789ab.json --allow-root
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This script must run through WP-CLI.' );
}
if ( wp_get_environment_type() !== 'local' ) {
	throw new RuntimeException( 'Refusing to run outside the local environment.' );
}
$expected_home = 'http://localhost:8080';
$actual_home   = untrailingslashit( home_url( '/' ) );
if ( $actual_home !== $expected_home ) {
	throw new RuntimeException( 'Unexpected WordPress home URL: ' . $actual_home );
}
if ( count( $args ) !== 2 ) {
	throw new InvalidArgumentException( 'Expected desired and receipt paths.' );
}
list( $desired_path, $receipt_path ) = $args;
if (
	! is_string( $desired_path )
	|| strpos( $desired_path, '/tmp/' ) !== 0
	|| ! is_file( $desired_path )
) {
	throw new InvalidArgumentException( 'Desired input must be a regular file below /tmp.' );
}
if (
	! is_string( $receipt_path )
	|| ! preg_match( '#^/tmp/akb-blog-editorial-local-receipt-[a-f0-9]{12}\.json$#', $receipt_path )
	|| file_exists( $receipt_path )
) {
	throw new InvalidArgumentException( 'Unsafe or existing receipt path.' );
}

/** @return array<string,mixed> */
function akb_blog_20260820_read_json( $path ) {
	$raw = file_get_contents( $path );
	if ( $raw === false ) {
		throw new RuntimeException( 'Unable to read desired input.' );
	}
	$value = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $value ) ) {
		throw new RuntimeException( 'Desired JSON root must be an object.' );
	}
	return $value;
}

function akb_blog_20260820_normalize( $value ) {
	return str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
}

/** @return array<string,string> */
function akb_blog_20260820_fields( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post || $post->post_type !== 'post' ) {
		throw new RuntimeException( 'Missing local post ' . $post_id );
	}
	return array(
		'post_title'              => akb_blog_20260820_normalize( $post->post_title ),
		'post_content'            => akb_blog_20260820_normalize( $post->post_content ),
		'post_excerpt'            => akb_blog_20260820_normalize( $post->post_excerpt ),
		'rank_math_focus_keyword' => akb_blog_20260820_normalize( get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ),
		'rank_math_title'         => akb_blog_20260820_normalize( get_post_meta( $post_id, 'rank_math_title', true ) ),
		'rank_math_description'   => akb_blog_20260820_normalize( get_post_meta( $post_id, 'rank_math_description', true ) ),
	);
}

/** @return array<string,mixed> */
function akb_blog_20260820_preserved( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		throw new RuntimeException( 'Missing post while capturing preservation state.' );
	}
	$taxonomies = array();
	foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
		$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $ids ) ) {
			throw new RuntimeException( $ids->get_error_message() );
		}
		$ids = array_map( 'intval', $ids );
		sort( $ids, SORT_NUMERIC );
		$taxonomies[ $taxonomy ] = $ids;
	}
	return array(
		'post_name'     => $post->post_name,
		'post_date'     => $post->post_date,
		'post_date_gmt' => $post->post_date_gmt,
		'post_author'   => (int) $post->post_author,
		'post_parent'   => (int) $post->post_parent,
		'menu_order'    => (int) $post->menu_order,
		'comment_status'=> $post->comment_status,
		'ping_status'   => $post->ping_status,
		'thumbnail_id'  => (string) get_post_meta( $post_id, '_thumbnail_id', true ),
		'taxonomies'    => $taxonomies,
	);
}

$expected_ids = array(
	22353, 22607, 22610, 22638, 22639, 22640, 22641, 22642, 23323,
	23324, 23325, 23326, 23327, 23329, 23330, 23332, 23333, 23334,
	23335, 23336, 24974, 24975, 24976, 24977, 24978, 25039, 25045,
	27176, 27452, 27454, 27455, 27456, 27457, 27458, 27528,
);
$required_fields = array(
	'post_title',
	'post_content',
	'post_excerpt',
	'rank_math_focus_keyword',
	'rank_math_title',
	'rank_math_description',
);
$desired = akb_blog_20260820_read_json( $desired_path );
if (
	( $desired['schema_version'] ?? null ) !== 1
	|| ( $desired['desired_type'] ?? null ) !== 'akibara_blog_seo_desired'
	|| ( $desired['inventory_post_count'] ?? null ) !== count( $expected_ids )
	|| ( $desired['mutation_scope'] ?? null ) !== 'content_and_seo_only'
	|| ( $desired['direct_wordpress_writes'] ?? null ) !== 0
	|| ! is_array( $desired['posts'] ?? null )
	|| count( $desired['posts'] ) !== count( $expected_ids )
) {
	throw new RuntimeException( 'Desired contract is not the reviewed 35-post active inventory.' );
}
$transitions = $desired['status_transitions'] ?? array();
if (
	count( $transitions ) !== 1
	|| (int) ( $transitions[0]['post_id'] ?? 0 ) !== 24977
	|| ( $transitions[0]['before'] ?? null ) !== 'publish'
	|| ( $transitions[0]['after'] ?? null ) !== 'draft'
) {
	throw new RuntimeException( 'Unexpected status-transition contract.' );
}

// Resolve and validate every local identity before the first write.
$targets = array();
$seen_source_ids = array();
$seen_local_ids  = array();
foreach ( $desired['posts'] as $wanted ) {
	$source_id = (int) ( $wanted['source_post_id'] ?? 0 );
	$slug      = (string) ( $wanted['content_key'] ?? '' );
	$fields    = $wanted['fields'] ?? null;
	if (
		! in_array( $source_id, $expected_ids, true )
		|| isset( $seen_source_ids[ $source_id ] )
		|| ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug )
		|| ! is_array( $fields )
		|| array_keys( $fields ) !== $required_fields
	) {
		throw new RuntimeException( 'Invalid desired identity or fields for ' . $source_id );
	}
	$seen_source_ids[ $source_id ] = true;
	$by_id   = get_post( $source_id );
	$by_slug = get_page_by_path( $slug, OBJECT, 'post' );
	if ( $by_id instanceof WP_Post && $by_id->post_type === 'post' && $by_id->post_name === $slug ) {
		$target = $by_id;
	} elseif ( $by_slug instanceof WP_Post && $by_slug->post_type === 'post' ) {
		$target = $by_slug;
	} else {
		throw new RuntimeException( 'No local post matches reviewed slug ' . $slug );
	}
	$local_id = (int) $target->ID;
	if ( isset( $seen_local_ids[ $local_id ] ) ) {
		throw new RuntimeException( 'Duplicate local target ' . $local_id );
	}
	$seen_local_ids[ $local_id ] = true;
	$before_status = $source_id === 24977
		? array( 'publish', 'draft' )
		: ( in_array( $source_id, array( 24974, 25039 ), true ) ? array( 'draft' ) : array( 'publish' ) );
	if ( ! in_array( $target->post_status, $before_status, true ) ) {
		throw new RuntimeException( 'Unexpected local status for post ' . $source_id . ': ' . $target->post_status );
	}
	$normalized = array();
	foreach ( $required_fields as $field ) {
		if ( ! is_string( $fields[ $field ] ) || trim( $fields[ $field ] ) === '' ) {
			throw new RuntimeException( 'Blank desired field ' . $field . ' for ' . $source_id );
		}
		$normalized[ $field ] = akb_blog_20260820_normalize( $fields[ $field ] );
	}
	$targets[] = array(
		'source_post_id' => $source_id,
		'local_post_id'  => $local_id,
		'content_key'    => $slug,
		'before_status'  => $target->post_status,
		'after_status'   => $source_id === 24977 ? 'draft' : $target->post_status,
		'fields'         => $normalized,
		'before_fields'  => akb_blog_20260820_fields( $local_id ),
		'preserved'      => akb_blog_20260820_preserved( $local_id ),
	);
}
sort( $expected_ids, SORT_NUMERIC );
$actual_source_ids = array_map( static fn( $row ) => $row['source_post_id'], $targets );
sort( $actual_source_ids, SORT_NUMERIC );
if ( $actual_source_ids !== $expected_ids ) {
	throw new RuntimeException( 'Desired source ID set drifted.' );
}

$records = array();
foreach ( $targets as $target ) {
	$postarr = array(
		'ID'           => $target['local_post_id'],
		'post_title'   => $target['fields']['post_title'],
		'post_content' => $target['fields']['post_content'],
		'post_excerpt' => $target['fields']['post_excerpt'],
		'post_status'  => $target['after_status'],
	);
	$result = wp_update_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $result ) || (int) $result !== $target['local_post_id'] ) {
		$message = is_wp_error( $result ) ? $result->get_error_message() : 'unexpected local ID';
		throw new RuntimeException( 'Local post write failed: ' . $message );
	}
	update_post_meta( $target['local_post_id'], 'rank_math_focus_keyword', $target['fields']['rank_math_focus_keyword'] );
	update_post_meta( $target['local_post_id'], 'rank_math_title', $target['fields']['rank_math_title'] );
	update_post_meta( $target['local_post_id'], 'rank_math_description', $target['fields']['rank_math_description'] );
	clean_post_cache( $target['local_post_id'] );
	$after_fields = akb_blog_20260820_fields( $target['local_post_id'] );
	$post_after   = get_post( $target['local_post_id'] );
	if (
		$after_fields !== $target['fields']
		|| ! $post_after instanceof WP_Post
		|| $post_after->post_status !== $target['after_status']
		|| $post_after->post_name !== $target['content_key']
		|| akb_blog_20260820_preserved( $target['local_post_id'] ) !== $target['preserved']
	) {
		throw new RuntimeException( 'Persisted or preserved state mismatch for ' . $target['source_post_id'] );
	}
	$records[] = array(
		'source_post_id' => $target['source_post_id'],
		'local_post_id'  => $target['local_post_id'],
		'content_key'    => $target['content_key'],
		'before_status'  => $target['before_status'],
		'after_status'   => $target['after_status'],
		'before_fields'  => $target['before_fields'],
		'before_sha256'  => hash( 'sha256', wp_json_encode( $target['before_fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
		'after_sha256'   => hash( 'sha256', wp_json_encode( $after_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
		'preserved_sha256' => hash( 'sha256', wp_json_encode( $target['preserved'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
	);
}

$excluded = get_post( 27460 );
$receipt = array(
	'schema_version'       => 1,
	'receipt_type'         => 'akibara_blog_editorial_local_apply_20260820',
	'environment'          => 'local',
	'target_origin'        => $actual_home,
	'applied_at_gmt'       => gmdate( 'c' ),
	'desired_file_sha256'  => hash_file( 'sha256', $desired_path ),
	'posts_processed'      => count( $records ),
	'source_to_local_post_ids' => array_column( $records, 'local_post_id', 'source_post_id' ),
	'status_transition'    => array( 'post_id' => 24977, 'after' => 'draft' ),
	'excluded_post_27460'  => $excluded instanceof WP_Post ? array(
		'local_post_id' => (int) $excluded->ID,
		'post_status'   => $excluded->post_status,
		'post_name'     => $excluded->post_name,
	) : null,
	'content_seo_fields_per_post' => count( $required_fields ),
	'image_mutations'       => 0,
	'media_mutations'       => 0,
	'caption_mutations'     => 0,
	'image_alt_mutations'   => 0,
	'taxonomy_mutations'    => 0,
	'slug_mutations'        => 0,
	'records'               => $records,
);
$encoded = wp_json_encode( $receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
if ( $encoded === false || file_put_contents( $receipt_path, $encoded . "\n", LOCK_EX ) === false ) {
	throw new RuntimeException( 'Unable to write local apply receipt.' );
}
chmod( $receipt_path, 0600 );
WP_CLI::log( $encoded );
