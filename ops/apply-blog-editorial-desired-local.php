<?php
/**
 * Apply the reviewed 36-post editorial contract to Akibara's Docker clone.
 *
 * This script is deliberately local-only and never touches featured images,
 * attachment metadata, captions or image ALT text.
 *
 * Usage (inside the WordPress CLI container):
 *   wp eval-file /tmp/apply-blog-editorial-desired-local.php \
 *     /tmp/blog-editorial-desired.json \
 *     /tmp/production-structure.json \
 *     /tmp/production-snapshot.json \
 *     /tmp/akb-blog-editorial-local-receipt.json --allow-root
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

if ( count( $args ) !== 4 ) {
	throw new InvalidArgumentException( 'Expected desired, structure, snapshot and receipt paths.' );
}

list( $desired_path, $structure_path, $snapshot_path, $receipt_path ) = $args;

foreach ( array( $desired_path, $structure_path, $snapshot_path ) as $input_path ) {
	if ( ! is_string( $input_path ) || strpos( $input_path, '/tmp/' ) !== 0 || ! is_file( $input_path ) ) {
		throw new InvalidArgumentException( 'Inputs must be regular files below /tmp.' );
	}
}

if (
	! is_string( $receipt_path )
	|| ! preg_match( '#^/tmp/akb-blog-editorial-local-receipt-[a-f0-9]{12}\.json$#', $receipt_path )
	|| file_exists( $receipt_path )
) {
	throw new InvalidArgumentException( 'Unsafe or existing receipt path.' );
}

/**
 * Decode a JSON file as an associative array.
 *
 * @param string $path File path.
 * @return array<string,mixed>
 */
function akb_blog_read_json( $path ) {
	$raw = file_get_contents( $path );
	if ( $raw === false ) {
		throw new RuntimeException( 'Unable to read ' . $path );
	}
	$data = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'JSON root must be an object: ' . $path );
	}
	return $data;
}

/**
 * Normalize editorial values exactly as the Node manifest tooling does.
 *
 * @param mixed $value Value.
 * @return string
 */
function akb_blog_normalize( $value ) {
	return str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
}

/**
 * Return the six managed fields for a post.
 *
 * @param int $post_id Post ID.
 * @return array<string,string>
 */
function akb_blog_actual_fields( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		throw new RuntimeException( 'Post disappeared during verification: ' . $post_id );
	}
	return array(
		'post_title'                 => akb_blog_normalize( $post->post_title ),
		'post_content'               => akb_blog_normalize( $post->post_content ),
		'post_excerpt'               => akb_blog_normalize( $post->post_excerpt ),
		'rank_math_focus_keyword'    => akb_blog_normalize( get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ),
		'rank_math_title'            => akb_blog_normalize( get_post_meta( $post_id, 'rank_math_title', true ) ),
		'rank_math_description'      => akb_blog_normalize( get_post_meta( $post_id, 'rank_math_description', true ) ),
	);
}

/**
 * Ensure a production taxonomy term exists locally and return its term ID.
 *
 * @param string              $taxonomy Taxonomy name.
 * @param array<string,mixed> $term     Term contract.
 * @return int
 */
function akb_blog_ensure_term( $taxonomy, $term ) {
	$name = isset( $term['name'] ) ? trim( (string) $term['name'] ) : '';
	$slug = isset( $term['slug'] ) ? trim( (string) $term['slug'] ) : '';
	if ( $name === '' || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
		throw new RuntimeException( 'Invalid ' . $taxonomy . ' term contract.' );
	}
	$existing = term_exists( $slug, $taxonomy );
	if ( $existing ) {
		return (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
	}
	$created = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( 'Term creation failed: ' . $created->get_error_message() );
	}
	return (int) $created['term_id'];
}

$desired   = akb_blog_read_json( $desired_path );
$structure = akb_blog_read_json( $structure_path );
$snapshot  = akb_blog_read_json( $snapshot_path );

if (
	( $desired['schema_version'] ?? null ) !== 1
	|| ( $desired['desired_type'] ?? null ) !== 'akibara_blog_seo_desired'
	|| ( $desired['inventory_post_count'] ?? null ) !== 36
	|| ! isset( $desired['posts'] )
	|| ! is_array( $desired['posts'] )
	|| count( $desired['posts'] ) !== 36
) {
	throw new RuntimeException( 'Desired contract is not the reviewed 36-post inventory.' );
}

if (
	( $structure['schema_version'] ?? null ) !== 1
	|| ( $structure['capture_type'] ?? null ) !== 'akibara_production_blog_structure_read_only'
	|| ( $structure['environment'] ?? null ) !== 'production'
	|| ( $structure['direct_writes'] ?? null ) !== 0
	|| ! isset( $structure['posts'] )
	|| ! is_array( $structure['posts'] )
	|| count( $structure['posts'] ) !== 36
) {
	throw new RuntimeException( 'Production structure contract is invalid.' );
}

if (
	( $snapshot['schema_version'] ?? null ) !== 1
	|| ( $snapshot['snapshot_type'] ?? null ) !== 'akibara_blog_production_read_only'
	|| ( $snapshot['environment'] ?? null ) !== 'production'
	|| ( $snapshot['direct_writes'] ?? null ) !== 0
	|| ! isset( $snapshot['posts'] )
	|| ! is_array( $snapshot['posts'] )
	|| count( $snapshot['posts'] ) !== 36
) {
	throw new RuntimeException( 'Production snapshot contract is invalid.' );
}

$required_fields = array(
	'post_title',
	'post_content',
	'post_excerpt',
	'rank_math_focus_keyword',
	'rank_math_title',
	'rank_math_description',
);

$structure_by_id = array();
foreach ( $structure['posts'] as $row ) {
	$structure_by_id[ (int) $row['post_id'] ] = $row;
}
$snapshot_by_id = array();
foreach ( $snapshot['posts'] as $row ) {
	$snapshot_by_id[ (int) $row['post_id'] ] = $row;
}

$seen_ids        = array();
$seen_slugs      = array();
$seen_local_ids  = array();
$inserted        = array();
$updated         = array();
$records         = array();
$image_mutations = 0;

foreach ( $desired['posts'] as $wanted ) {
	$post_id = isset( $wanted['source_post_id'] ) ? (int) $wanted['source_post_id'] : 0;
	$slug    = isset( $wanted['content_key'] ) ? (string) $wanted['content_key'] : '';
	$fields  = $wanted['fields'] ?? null;
	if (
		$post_id < 1
		|| isset( $seen_ids[ $post_id ] )
		|| ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug )
		|| isset( $seen_slugs[ $slug ] )
		|| ! is_array( $fields )
		|| array_keys( $fields ) !== $required_fields
	) {
		throw new RuntimeException( 'Invalid desired post identity or field set.' );
	}
	$seen_ids[ $post_id ]   = true;
	$seen_slugs[ $slug ]    = true;
	$source                 = $snapshot_by_id[ $post_id ] ?? null;
	$source_structure       = $structure_by_id[ $post_id ] ?? null;
	if ( ! is_array( $source ) || ! is_array( $source_structure ) ) {
		throw new RuntimeException( 'Missing production contract for post ' . $post_id );
	}
	if ( (string) $source['post_slug'] !== $slug ) {
		throw new RuntimeException( 'Slug drift for production post ' . $post_id );
	}
	$status = (string) $source['post_status'];
	if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
		throw new RuntimeException( 'Unexpected production status for post ' . $post_id );
	}

	$normalized_fields = array();
	foreach ( $required_fields as $field ) {
		if ( ! is_string( $fields[ $field ] ) || trim( $fields[ $field ] ) === '' ) {
			throw new RuntimeException( 'Blank desired field ' . $field . ' for post ' . $post_id );
		}
		$normalized_fields[ $field ] = akb_blog_normalize( $fields[ $field ] );
	}

	$source_slot      = get_post( $post_id );
	$existing_by_slug = get_page_by_path( $slug, OBJECT, 'post' );
	if (
		$source_slot instanceof WP_Post
		&& $source_slot->post_type === 'post'
		&& $existing_by_slug instanceof WP_Post
		&& (int) $existing_by_slug->ID !== $post_id
	) {
		throw new RuntimeException( 'Local slug collision for ' . $slug );
	}
	if ( $source_slot instanceof WP_Post && $source_slot->post_type === 'post' ) {
		$existing_target = $source_slot;
	} elseif ( $existing_by_slug instanceof WP_Post ) {
		$existing_target = $existing_by_slug;
	} else {
		$existing_target = null;
	}
	$id_collision = $source_slot instanceof WP_Post && $source_slot->post_type !== 'post';
	$local_post_id = $existing_target instanceof WP_Post ? (int) $existing_target->ID : 0;
	if ( $local_post_id > 0 && isset( $seen_local_ids[ $local_post_id ] ) ) {
		throw new RuntimeException( 'Duplicate local target ID: ' . $local_post_id );
	}

	$thumbnail_before = $existing_target ? (string) get_post_meta( $local_post_id, '_thumbnail_id', true ) : '';
	$before_fields    = $existing_target ? akb_blog_actual_fields( $local_post_id ) : null;
	$date_gmt         = (string) $source_structure['post_date_gmt'];
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date_gmt ) ) {
		throw new RuntimeException( 'Invalid production date for post ' . $post_id );
	}
	$postarr = array(
		'post_type'      => 'post',
		'post_status'    => $status,
		'post_name'      => $slug,
		'post_title'     => $normalized_fields['post_title'],
		'post_content'   => $normalized_fields['post_content'],
		'post_excerpt'   => $normalized_fields['post_excerpt'],
		'post_author'    => 1,
		'post_parent'    => (int) ( $source_structure['post_parent'] ?? 0 ),
		'menu_order'     => (int) ( $source_structure['menu_order'] ?? 0 ),
		'comment_status' => (string) ( $source_structure['comment_status'] ?? 'open' ),
		'ping_status'    => (string) ( $source_structure['ping_status'] ?? 'open' ),
		'post_date_gmt'  => $date_gmt,
		'post_date'      => get_date_from_gmt( $date_gmt ),
	);
	if ( $existing_target ) {
		$postarr['ID'] = $local_post_id;
		$result        = wp_update_post( wp_slash( $postarr ), true );
		$updated[]     = $local_post_id;
	} else {
		if ( ! $id_collision ) {
			$postarr['import_id'] = $post_id;
		}
		$result = wp_insert_post( wp_slash( $postarr ), true );
	}
	if ( is_wp_error( $result ) || (int) $result < 1 ) {
		$message = is_wp_error( $result ) ? $result->get_error_message() : 'missing local ID';
		throw new RuntimeException( 'Post write failed for ' . $post_id . ': ' . $message );
	}
	$local_post_id = (int) $result;
	if ( ! $existing_target ) {
		if ( ! $id_collision && $local_post_id !== $post_id ) {
			throw new RuntimeException( 'Free production ID was not preserved for post ' . $post_id );
		}
		$inserted[] = $local_post_id;
	}
	$seen_local_ids[ $local_post_id ] = true;

	update_post_meta( $local_post_id, 'rank_math_focus_keyword', $normalized_fields['rank_math_focus_keyword'] );
	update_post_meta( $local_post_id, 'rank_math_title', $normalized_fields['rank_math_title'] );
	update_post_meta( $local_post_id, 'rank_math_description', $normalized_fields['rank_math_description'] );

	foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
		$term_ids = array();
		foreach ( $source_structure['taxonomies'][ $taxonomy ] ?? array() as $term ) {
			$term_ids[] = akb_blog_ensure_term( $taxonomy, $term );
		}
		$term_result = wp_set_object_terms( $local_post_id, $term_ids, $taxonomy, false );
		if ( is_wp_error( $term_result ) ) {
			throw new RuntimeException( 'Term assignment failed for post ' . $post_id . ': ' . $term_result->get_error_message() );
		}
	}

	clean_post_cache( $local_post_id );
	$actual_fields = akb_blog_actual_fields( $local_post_id );
	if ( $actual_fields !== $normalized_fields ) {
		throw new RuntimeException( 'Persisted field mismatch for post ' . $post_id );
	}
	$persisted = get_post( $local_post_id );
	if (
		! $persisted instanceof WP_Post
		|| $persisted->post_name !== $slug
		|| $persisted->post_status !== $status
	) {
		throw new RuntimeException( 'Persisted identity mismatch for post ' . $post_id );
	}
	$thumbnail_after = (string) get_post_meta( $local_post_id, '_thumbnail_id', true );
	if ( $thumbnail_after !== $thumbnail_before ) {
		$image_mutations++;
		throw new RuntimeException( 'Featured image changed unexpectedly for post ' . $post_id );
	}

	$records[] = array(
		'source_post_id'     => $post_id,
		'local_post_id'      => $local_post_id,
		'content_key'        => $slug,
		'action'             => $existing_target ? 'updated' : 'inserted',
		'source_id_collision'=> $id_collision ? array(
			'post_type' => (string) $source_slot->post_type,
			'post_name' => (string) $source_slot->post_name,
		) : null,
		'post_status'        => $status,
		'before_sha256'      => $before_fields === null ? null : hash( 'sha256', wp_json_encode( $before_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
		'after_sha256'       => hash( 'sha256', wp_json_encode( $actual_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
		'thumbnail_id_before'=> $thumbnail_before,
		'thumbnail_id_after' => $thumbnail_after,
	);
}

sort( $inserted, SORT_NUMERIC );
sort( $updated, SORT_NUMERIC );
usort(
	$records,
	static function ( $left, $right ) {
		return $left['source_post_id'] <=> $right['source_post_id'];
	}
);

$receipt = array(
	'schema_version'          => 1,
	'receipt_type'            => 'akibara_blog_editorial_local_apply',
	'environment'             => 'local',
	'target_origin'           => $actual_home,
	'applied_at'              => gmdate( 'c' ),
	'desired_file_sha256'     => hash_file( 'sha256', $desired_path ),
	'production_structure_sha256' => hash_file( 'sha256', $structure_path ),
	'production_snapshot_sha256'  => hash_file( 'sha256', $snapshot_path ),
	'posts_processed'         => count( $records ),
	'inserted_post_ids'       => $inserted,
	'updated_post_ids'        => $updated,
	'source_to_local_post_ids'=> array_column( $records, 'local_post_id', 'source_post_id' ),
	'content_seo_fields_per_post' => count( $required_fields ),
	'image_mutations'         => $image_mutations,
	'media_mutations'         => 0,
	'caption_mutations'       => 0,
	'image_alt_mutations'     => 0,
	'records'                 => $records,
);

$encoded = wp_json_encode( $receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
if ( $encoded === false || file_put_contents( $receipt_path, $encoded . "\n", LOCK_EX ) === false ) {
	throw new RuntimeException( 'Unable to write local apply receipt.' );
}
chmod( $receipt_path, 0600 );

WP_CLI::log( $encoded );
