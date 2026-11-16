<?php
/**
 * Fail-closed, Docker-local closure for the reviewed blog editorial batch.
 *
 * Exact mutation set:
 * - move obsolete LOCAL Rank Math redirection 589 from active to trashed;
 * - move post 24977 from publish to draft;
 * - move post 27460 from draft to publish.
 *
 * The payload deliberately does not write content, SEO meta, taxonomies,
 * attachments, featured images, captions or ALT text. Rank Math is changed
 * through its official DB/Cache classes and posts through wp_update_post().
 *
 * Audit (default, required explicit mode):
 *   AKB_APPLY=0 wp eval-file - \
 *     /tmp/akb-blog-editorial-docker-close-012345abcdef.json \
 *     --allow-root < ops/close-blog-editorial-docker-local.php
 *
 * Apply:
 *   AKB_APPLY=1 wp eval-file - \
 *     /tmp/akb-blog-editorial-docker-close-012345abcdef.json \
 *     --allow-root < ops/close-blog-editorial-docker-local.php
 *
 * @package Akibara\Operations
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit( 1 );

final class Akibara_Blog_Editorial_Docker_Close {
	private const HOME = 'http://localhost:8080';
	private const INTERNAL_HTTP_ORIGIN = 'http://wordpress';

	private const OBSOLETE_REDIRECT_ID = 589;
	private const OBSOLETE_REDIRECT_SOURCE = 'atelier-of-witch-hat-la-obra-maestra-de-kamome-shirahama/';
	private const OBSOLETE_REDIRECT_DESTINATION = 'http://localhost:8080/atelier-of-witch-hat-guia/';

	private const CONSOLIDATED_REDIRECT_ID = 561;
	private const CONSOLIDATED_REDIRECT_SOURCE = 'que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile';
	private const CONSOLIDATED_REDIRECT_DESTINATION = 'http://localhost:8080/que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber/';

	private const ATELIER_POST_ID = 22353;
	private const ATELIER_SLUG = 'atelier-of-witch-hat-la-obra-maestra-de-kamome-shirahama';
	private const ATELIER_H1 = 'Atelier of Witch Hat: magia, arte y edición en español';

	private const CONSOLIDATION_TARGET_POST_ID = 23334;
	private const CONSOLIDATION_TARGET_SLUG = 'que-son-las-preventas-de-manga-todo-lo-que-necesitas-saber';
	private const CONSOLIDATION_TARGET_H1 = 'Qué es una preventa de manga y qué revisar antes de reservar';

	private const ARCHIVE_POST_ID = 24977;
	private const ARCHIVE_POST_SLUG = 'que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile';
	private const ARCHIVE_EDITORIAL_SHA256 = '1368c6e1d5391834d2b74e841f19bb750f408c31f8bf7c636b350d8cba50d2b0';

	private const PUBLISH_POST_ID = 27460;
	private const PUBLISH_POST_SLUG = 'mangas-mas-buscados-en-chile-2026';
	private const PUBLISH_POST_H1 = 'Manga en Chile: 15 series comparadas en Google Trends';
	private const PUBLISH_EDITORIAL_SHA256 = '75f6899e0d89a65f8f08e7d526834c9690277a1a38bbc3329c2da0df2921aed1';

	/**
	 * Execute the dry-run or the exact local transaction.
	 *
	 * @param bool $apply Whether to apply the reviewed three mutations.
	 * @return array<string,mixed>
	 */
	public static function run( bool $apply ): array {
		self::assert_runtime();
		self::load_rank_math();

		$started_at = gmdate( 'c' );
		$before = self::preflight();
		$receipt = array(
			'schema_version' => 1,
			'receipt_type' => 'akibara_blog_editorial_docker_close',
			'environment' => 'local',
			'target_origin' => self::HOME,
			'mode' => $apply ? 'apply' : 'dry_run',
			'status' => 'PASS',
			'scope' => array(
				'post_status_writes' => 2,
				'rank_math_redirect_status_writes' => 1,
				'post_content_writes' => 0,
				'post_meta_writes' => 0,
				'taxonomy_writes' => 0,
				'image_writes' => 0,
				'media_writes' => 0,
				'caption_writes' => 0,
				'image_alt_writes' => 0,
				'direct_sql_data_writes' => 0,
			),
			'planned_operations' => array(
				array(
					'type' => 'rank_math_redirection_status',
					'id' => self::OBSOLETE_REDIRECT_ID,
					'from' => 'active',
					'to' => 'trashed',
					'api' => 'RankMath\\Redirections\\DB::change_status',
				),
				array(
					'type' => 'post_status',
					'post_id' => self::ARCHIVE_POST_ID,
					'from' => 'publish',
					'to' => 'draft',
					'api' => 'wp_update_post',
				),
				array(
					'type' => 'post_status',
					'post_id' => self::PUBLISH_POST_ID,
					'from' => 'draft',
					'to' => 'publish',
					'api' => 'wp_update_post',
				),
			),
			'before' => $before['public'],
			'after' => null,
			'post_commit_validation' => $apply
				? array( 'status' => 'PENDING' )
				: array(
					'status' => 'PLANNED',
					'checks' => self::planned_http_checks(),
				),
			'mutations_applied' => false,
			'started_at_gmt' => $started_at,
			'completed_at_gmt' => null,
		);

		if ( ! $apply ) {
			$receipt['completed_at_gmt'] = gmdate( 'c' );
			return $receipt;
		}

		try {
			$after = self::apply_transaction( $before );
			$receipt['mutations_applied'] = true;
			$receipt['after'] = $after['public'];
			$receipt['post_commit_validation'] = self::validate_http_and_sitemap();
		} catch ( Throwable $error ) {
			$receipt['status'] = 'FAIL';
			$receipt['error'] = array(
				'class' => get_class( $error ),
				'message' => $error->getMessage(),
			);
			$receipt['completed_at_gmt'] = gmdate( 'c' );
			throw new Akibara_Blog_Editorial_Docker_Close_Failure( $receipt, $error );
		}

		$receipt['completed_at_gmt'] = gmdate( 'c' );
		return $receipt;
	}

	/**
	 * Fail closed unless this is the exact Akibara Docker clone.
	 */
	private static function assert_runtime(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			throw new RuntimeException( 'This payload must run through WP-CLI.' );
		}
		if ( 'local' !== wp_get_environment_type() ) {
			throw new RuntimeException( 'Refusing to run outside wp_get_environment_type()=local.' );
		}
		if (
			self::HOME !== untrailingslashit( home_url( '/' ) )
			|| self::HOME !== untrailingslashit( site_url( '/' ) )
		) {
			throw new RuntimeException( 'Unexpected local home/site URL.' );
		}
		if (
			! defined( 'DB_HOST' )
			|| ! defined( 'DB_NAME' )
			|| 'db' !== DB_HOST
			|| 'akibara' !== DB_NAME
		) {
			throw new RuntimeException( 'Unexpected Docker database target.' );
		}
	}

	/**
	 * Load only Rank Math's official autoloader when WP-CLI did not load it.
	 */
	private static function load_rank_math(): void {
		if ( ! class_exists( '\\RankMath\\Redirections\\DB' ) ) {
			$autoload = WP_PLUGIN_DIR . '/seo-by-rank-math/vendor/autoload.php';
			if ( is_readable( $autoload ) ) {
				require_once $autoload;
			}
		}
		if (
			! class_exists( '\\RankMath\\Redirections\\DB' )
			|| ! class_exists( '\\RankMath\\Redirections\\Cache' )
			|| ! class_exists( '\\RankMath\\Sitemap\\Cache' )
		) {
			throw new RuntimeException( 'Required Rank Math official classes are unavailable.' );
		}
	}

	/**
	 * Read and validate every before-state before a write can start.
	 *
	 * @return array<string,mixed>
	 */
	private static function preflight(): array {
		$counts = self::post_counts();
		self::assert_same( array( 'draft' => 3, 'publish' => 33 ), $counts, 'unexpected_blog_inventory' );

		$archive = self::post_snapshot(
			self::ARCHIVE_POST_ID,
			self::ARCHIVE_POST_SLUG,
			'publish'
		);
		$publish = self::post_snapshot(
			self::PUBLISH_POST_ID,
			self::PUBLISH_POST_SLUG,
			'draft'
		);
		$atelier = self::post_snapshot(
			self::ATELIER_POST_ID,
			self::ATELIER_SLUG,
			'publish'
		);
		$destination = self::post_snapshot(
			self::CONSOLIDATION_TARGET_POST_ID,
			self::CONSOLIDATION_TARGET_SLUG,
			'publish'
		);

		self::assert_same(
			self::ARCHIVE_EDITORIAL_SHA256,
			$archive['editorial_sha256'],
			'archive_editorial_drift'
		);
		self::assert_same(
			self::PUBLISH_EDITORIAL_SHA256,
			$publish['editorial_sha256'],
			'publish_editorial_drift'
		);

		$redirections = self::redirection_snapshot();
		$obsolete = self::redirection_by_id( $redirections, self::OBSOLETE_REDIRECT_ID );
		$preserved = self::redirection_by_id( $redirections, self::CONSOLIDATED_REDIRECT_ID );
		self::assert_obsolete_redirect( $obsolete, 'active' );
		self::assert_consolidated_redirect( $preserved );

		$matching_obsolete_sources = array_values(
			array_filter(
				$redirections,
				static function ( array $row ): bool {
					foreach ( $row['sources'] as $source ) {
						if ( (string) ( $source['pattern'] ?? '' ) === self::OBSOLETE_REDIRECT_SOURCE ) {
							return true;
						}
					}
					return false;
				}
			)
		);
		self::assert_same( 1, count( $matching_obsolete_sources ), 'obsolete_redirect_source_not_unique' );
		self::assert_same(
			self::OBSOLETE_REDIRECT_ID,
			$matching_obsolete_sources[0]['id'],
			'obsolete_redirect_source_wrong_id'
		);

		return array(
			'counts' => $counts,
			'archive' => $archive,
			'publish' => $publish,
			'atelier' => $atelier,
			'destination' => $destination,
			'redirections' => $redirections,
			'other_redirections_sha256' => self::redirection_hash_without_obsolete( $redirections ),
			'public' => array(
				'post_counts' => $counts,
				'posts' => array(
					self::public_post_snapshot( $archive ),
					self::public_post_snapshot( $publish ),
					self::public_post_snapshot( $atelier ),
					self::public_post_snapshot( $destination ),
				),
				'obsolete_redirect' => $obsolete,
				'preserved_consolidation_redirect' => $preserved,
				'other_redirections_sha256' => self::redirection_hash_without_obsolete( $redirections ),
			),
		);
	}

	/**
	 * Apply the three writes in a database transaction and verify exact scope.
	 *
	 * @param array<string,mixed> $before Preflight state.
	 * @return array<string,mixed>
	 */
	private static function apply_transaction( array $before ): array {
		global $wpdb;

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new RuntimeException( 'Unable to start the local transaction.' );
		}

		try {
			self::update_post_status( self::ARCHIVE_POST_ID, 'draft' );
			self::update_post_status( self::PUBLISH_POST_ID, 'publish' );

			$updated = \RankMath\Redirections\DB::change_status(
				array( self::OBSOLETE_REDIRECT_ID ),
				'trashed'
			);
			self::assert_same( 1, (int) $updated, 'unexpected_redirect_update_count' );
			\RankMath\Redirections\Cache::purge( array( self::OBSOLETE_REDIRECT_ID ) );

			clean_post_cache( self::ARCHIVE_POST_ID );
			clean_post_cache( self::PUBLISH_POST_ID );

			$archive = self::post_snapshot(
				self::ARCHIVE_POST_ID,
				self::ARCHIVE_POST_SLUG,
				'draft'
			);
			$publish = self::post_snapshot(
				self::PUBLISH_POST_ID,
				self::PUBLISH_POST_SLUG,
				'publish'
			);
			$atelier = self::post_snapshot(
				self::ATELIER_POST_ID,
				self::ATELIER_SLUG,
				'publish'
			);
			$destination = self::post_snapshot(
				self::CONSOLIDATION_TARGET_POST_ID,
				self::CONSOLIDATION_TARGET_SLUG,
				'publish'
			);

			self::assert_preserved_post( $before['archive'], $archive, 'archive_post' );
			self::assert_preserved_post( $before['publish'], $publish, 'publish_post' );
			self::assert_preserved_post( $before['atelier'], $atelier, 'atelier_post' );
			self::assert_preserved_post( $before['destination'], $destination, 'destination_post' );

			$redirections = self::redirection_snapshot();
			$obsolete = self::redirection_by_id( $redirections, self::OBSOLETE_REDIRECT_ID );
			$preserved = self::redirection_by_id( $redirections, self::CONSOLIDATED_REDIRECT_ID );
			self::assert_obsolete_redirect( $obsolete, 'trashed' );
			self::assert_consolidated_redirect( $preserved );
			self::assert_same(
				$before['other_redirections_sha256'],
				self::redirection_hash_without_obsolete( $redirections ),
				'unrelated_redirection_changed'
			);

			$counts = self::post_counts();
			self::assert_same( array( 'draft' => 3, 'publish' => 33 ), $counts, 'post_counts_changed' );

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Unable to commit the local transaction.' );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			clean_post_cache( self::ARCHIVE_POST_ID );
			clean_post_cache( self::PUBLISH_POST_ID );
			\RankMath\Redirections\Cache::purge( array( self::OBSOLETE_REDIRECT_ID ) );
			throw $error;
		}

		self::invalidate_public_caches();

		return array(
			'public' => array(
				'post_counts' => $counts,
				'posts' => array(
					self::public_post_snapshot( $archive ),
					self::public_post_snapshot( $publish ),
					self::public_post_snapshot( $atelier ),
					self::public_post_snapshot( $destination ),
				),
				'obsolete_redirect' => $obsolete,
				'preserved_consolidation_redirect' => $preserved,
				'other_redirections_sha256' => self::redirection_hash_without_obsolete( $redirections ),
				'preservation' => array(
					'taxonomy_mutations' => 0,
					'image_mutations' => 0,
					'media_mutations' => 0,
					'caption_mutations' => 0,
					'image_alt_mutations' => 0,
				),
			),
		);
	}

	/**
	 * Write a post status through the official WordPress API.
	 */
	private static function update_post_status( int $post_id, string $status ): void {
		$result = wp_update_post(
			array(
				'ID' => $post_id,
				'post_status' => $status,
			),
			true
		);
		if ( is_wp_error( $result ) || $post_id !== (int) $result ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : 'wrong post ID';
			throw new RuntimeException( 'Post status update failed for ' . $post_id . ': ' . $message );
		}
	}

	/**
	 * Snapshot all state that must remain immutable across a status transition.
	 *
	 * @return array<string,mixed>
	 */
	private static function post_snapshot( int $post_id, string $slug, string $status ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			throw new RuntimeException( 'Missing post ' . $post_id );
		}
		if (
			'post' !== $post->post_type
			|| $slug !== $post->post_name
			|| $status !== $post->post_status
		) {
			throw new RuntimeException( 'Post identity/status drift for ' . $post_id );
		}
		$by_slug = get_page_by_path( $slug, OBJECT, 'post' );
		if ( ! $by_slug instanceof WP_Post || $post_id !== (int) $by_slug->ID ) {
			throw new RuntimeException( 'Slug is not unique for post ' . $post_id );
		}

		$editorial = array(
			'post_title' => self::normalize( $post->post_title ),
			'post_content' => self::normalize( $post->post_content ),
			'post_excerpt' => self::normalize( $post->post_excerpt ),
			'rank_math_focus_keyword' => self::normalize( get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ),
			'rank_math_title' => self::normalize( get_post_meta( $post_id, 'rank_math_title', true ) ),
			'rank_math_description' => self::normalize( get_post_meta( $post_id, 'rank_math_description', true ) ),
		);
		$core = array(
			'ID' => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'post_name' => (string) $post->post_name,
			'post_title' => self::normalize( $post->post_title ),
			'post_content' => self::normalize( $post->post_content ),
			'post_excerpt' => self::normalize( $post->post_excerpt ),
			'post_author' => (int) $post->post_author,
			'post_parent' => (int) $post->post_parent,
			'menu_order' => (int) $post->menu_order,
			'comment_status' => (string) $post->comment_status,
			'ping_status' => (string) $post->ping_status,
			'post_date' => (string) $post->post_date,
			'post_date_gmt' => (string) $post->post_date_gmt,
			'guid' => (string) $post->guid,
		);
		$taxonomies = self::taxonomy_snapshot( $post_id );
		$image = self::image_snapshot( $post_id );

		return array(
			'post_id' => $post_id,
			'slug' => $slug,
			'post_status' => $status,
			'editorial_sha256' => self::hash_value( $editorial ),
			'core_immutable_sha256' => self::hash_value( $core ),
			'taxonomies_sha256' => self::hash_value( $taxonomies ),
			'image_sha256' => self::hash_value( $image ),
			'featured_image_id' => $image['thumbnail_id'],
		);
	}

	/**
	 * Return a stable taxonomy snapshot without writing terms.
	 *
	 * @return array<string,mixed>
	 */
	private static function taxonomy_snapshot( int $post_id ): array {
		$taxonomies = get_object_taxonomies( 'post', 'names' );
		sort( $taxonomies, SORT_STRING );
		$result = array();
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms(
				$post_id,
				$taxonomy,
				array( 'orderby' => 'term_id', 'order' => 'ASC' )
			);
			if ( is_wp_error( $terms ) ) {
				throw new RuntimeException( 'Unable to read taxonomy ' . $taxonomy );
			}
			$result[ $taxonomy ] = array_map(
				static function ( WP_Term $term ): array {
					return array(
						'term_id' => (int) $term->term_id,
						'term_taxonomy_id' => (int) $term->term_taxonomy_id,
						'slug' => (string) $term->slug,
						'name' => (string) $term->name,
					);
				},
				$terms
			);
		}
		return $result;
	}

	/**
	 * Return featured-image and attachment metadata needed to prove no media write.
	 *
	 * @return array<string,mixed>
	 */
	private static function image_snapshot( int $post_id ): array {
		$thumbnail_id = (int) get_post_thumbnail_id( $post_id );
		if ( 0 === $thumbnail_id ) {
			return array( 'thumbnail_id' => 0, 'attachment' => null );
		}
		$attachment = get_post( $thumbnail_id );
		if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
			throw new RuntimeException( 'Invalid featured image for post ' . $post_id );
		}
		return array(
			'thumbnail_id' => $thumbnail_id,
			'attachment' => array(
				'ID' => (int) $attachment->ID,
				'post_title' => (string) $attachment->post_title,
				'post_excerpt' => (string) $attachment->post_excerpt,
				'post_content' => (string) $attachment->post_content,
				'post_mime_type' => (string) $attachment->post_mime_type,
				'attached_file' => (string) get_post_meta( $thumbnail_id, '_wp_attached_file', true ),
				'attachment_metadata' => get_post_meta( $thumbnail_id, '_wp_attachment_metadata', true ),
				'alt' => (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ),
			),
		);
	}

	/**
	 * Prove that only post_status and modified timestamps could change.
	 *
	 * @param array<string,mixed> $before Before snapshot.
	 * @param array<string,mixed> $after  After snapshot.
	 */
	private static function assert_preserved_post( array $before, array $after, string $label ): void {
		foreach (
			array(
				'post_id',
				'slug',
				'editorial_sha256',
				'core_immutable_sha256',
				'taxonomies_sha256',
				'image_sha256',
				'featured_image_id',
			) as $field
		) {
			self::assert_same( $before[ $field ], $after[ $field ], $label . '_' . $field . '_changed' );
		}
	}

	/**
	 * Return safe receipt fields only.
	 *
	 * @param array<string,mixed> $snapshot Post snapshot.
	 * @return array<string,mixed>
	 */
	private static function public_post_snapshot( array $snapshot ): array {
		return array(
			'post_id' => $snapshot['post_id'],
			'slug' => $snapshot['slug'],
			'post_status' => $snapshot['post_status'],
			'editorial_sha256' => $snapshot['editorial_sha256'],
			'core_immutable_sha256' => $snapshot['core_immutable_sha256'],
			'taxonomies_sha256' => $snapshot['taxonomies_sha256'],
			'image_sha256' => $snapshot['image_sha256'],
			'featured_image_id' => $snapshot['featured_image_id'],
		);
	}

	/**
	 * Read normalized redirections through Rank Math's official reader.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function redirection_snapshot(): array {
		$rows = array();
		foreach ( array( 'active', 'inactive', 'trashed' ) as $status ) {
			$result = \RankMath\Redirections\DB::get_redirections(
				array( 'limit' => 1000, 'status' => $status )
			);
			foreach ( (array) ( $result['redirections'] ?? array() ) as $raw ) {
				$sources = maybe_unserialize( $raw['sources'] ?? '' );
				if ( ! is_array( $sources ) ) {
					throw new RuntimeException( 'Malformed Rank Math sources for redirection.' );
				}
				$normalized_sources = array();
				foreach ( $sources as $source ) {
					if ( ! is_array( $source ) ) {
						throw new RuntimeException( 'Malformed Rank Math source row.' );
					}
					$normalized_sources[] = array(
						'ignore' => (string) ( $source['ignore'] ?? '' ),
						'pattern' => (string) ( $source['pattern'] ?? '' ),
						'comparison' => (string) ( $source['comparison'] ?? '' ),
					);
				}
				$rows[] = array(
					'id' => (int) ( $raw['id'] ?? 0 ),
					'sources' => $normalized_sources,
					'url_to' => (string) ( $raw['url_to'] ?? '' ),
					'header_code' => (int) ( $raw['header_code'] ?? 0 ),
					'status' => (string) ( $raw['status'] ?? '' ),
				);
			}
		}
		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return $left['id'] <=> $right['id'];
			}
		);
		return $rows;
	}

	/**
	 * Find exactly one normalized redirection.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<string,mixed>
	 */
	private static function redirection_by_id( array $rows, int $id ): array {
		$matches = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $id ): bool {
					return $id === $row['id'];
				}
			)
		);
		if ( 1 !== count( $matches ) ) {
			throw new RuntimeException( 'Expected redirection ID exactly once: ' . $id );
		}
		return $matches[0];
	}

	/**
	 * Validate the local-only obsolete redirect fingerprint.
	 *
	 * @param array<string,mixed> $row    Redirection.
	 * @param string              $status Expected state.
	 */
	private static function assert_obsolete_redirect( array $row, string $status ): void {
		self::assert_same( self::OBSOLETE_REDIRECT_ID, $row['id'], 'obsolete_redirect_id' );
		self::assert_same(
			array(
				array(
					'ignore' => '',
					'pattern' => self::OBSOLETE_REDIRECT_SOURCE,
					'comparison' => 'exact',
				),
			),
			$row['sources'],
			'obsolete_redirect_sources'
		);
		self::assert_same( self::OBSOLETE_REDIRECT_DESTINATION, $row['url_to'], 'obsolete_redirect_destination' );
		self::assert_same( 301, $row['header_code'], 'obsolete_redirect_header' );
		self::assert_same( $status, $row['status'], 'obsolete_redirect_status' );
	}

	/**
	 * Validate the consolidation redirect that must remain unchanged.
	 *
	 * @param array<string,mixed> $row Redirection.
	 */
	private static function assert_consolidated_redirect( array $row ): void {
		self::assert_same( self::CONSOLIDATED_REDIRECT_ID, $row['id'], 'consolidated_redirect_id' );
		self::assert_same(
			array(
				array(
					'ignore' => 'case',
					'pattern' => self::CONSOLIDATED_REDIRECT_SOURCE,
					'comparison' => 'exact',
				),
			),
			$row['sources'],
			'consolidated_redirect_sources'
		);
		self::assert_same( self::CONSOLIDATED_REDIRECT_DESTINATION, $row['url_to'], 'consolidated_redirect_destination' );
		self::assert_same( 301, $row['header_code'], 'consolidated_redirect_header' );
		self::assert_same( 'active', $row['status'], 'consolidated_redirect_status' );
	}

	/**
	 * Hash every other Rank Math row to prove the operation touched only 589.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 */
	private static function redirection_hash_without_obsolete( array $rows ): string {
		return self::hash_value(
			array_values(
				array_filter(
					$rows,
					static function ( array $row ): bool {
						return self::OBSOLETE_REDIRECT_ID !== $row['id'];
					}
				)
			)
		);
	}

	/**
	 * Return the exact publish/draft blog inventory.
	 *
	 * @return array<string,int>
	 */
	private static function post_counts(): array {
		$counts = wp_count_posts( 'post' );
		return array(
			'draft' => (int) ( $counts->draft ?? 0 ),
			'publish' => (int) ( $counts->publish ?? 0 ),
		);
	}

	/**
	 * Invalidate only affected post/URL and sitemap caches.
	 */
	private static function invalidate_public_caches(): void {
		foreach (
			array(
				self::ARCHIVE_POST_ID,
				self::PUBLISH_POST_ID,
				self::ATELIER_POST_ID,
				self::CONSOLIDATION_TARGET_POST_ID,
			) as $post_id
		) {
			clean_post_cache( $post_id );
			do_action( 'litespeed_purge_post', $post_id );
		}
		foreach (
			array(
				'/' . self::ATELIER_SLUG . '/',
				'/' . self::ARCHIVE_POST_SLUG . '/',
				'/' . self::PUBLISH_POST_SLUG . '/',
				'/' . self::CONSOLIDATION_TARGET_SLUG . '/',
				'/post-sitemap.xml',
			) as $path
		) {
			do_action( 'litespeed_purge_url', $path );
		}
		\RankMath\Sitemap\Cache::invalidate_storage( 'post' );
	}

	/**
	 * Return human-readable post-commit checks for a dry-run receipt.
	 *
	 * @return array<int,string>
	 */
	private static function planned_http_checks(): array {
		return array(
			'Atelier historical URL: 200, indexable, one expected H1, self-canonical and BlogPosting/Article schema.',
			'Archived 24977 pretty URL: Rank Math 301 to 23334; direct ?p=24977: 404.',
			'Consolidation target 23334: 200, indexable, one expected H1 and self-canonical.',
			'Published ranking 27460: 200, indexable, one expected H1, self-canonical and BlogPosting/Article schema.',
			'post-sitemap.xml: 33 post URLs; includes 22353, 23334 and 27460; excludes 24977.',
		);
	}

	/**
	 * Validate fresh public behavior after the committed local transaction.
	 *
	 * @return array<string,mixed>
	 */
	private static function validate_http_and_sitemap(): array {
		$cache_key = substr( hash( 'sha256', gmdate( 'c' ) . wp_generate_uuid4() ), 0, 16 );
		$atelier_url = self::HOME . '/' . self::ATELIER_SLUG . '/';
		$archive_url = self::HOME . '/' . self::ARCHIVE_POST_SLUG . '/';
		$destination_url = self::HOME . '/' . self::CONSOLIDATION_TARGET_SLUG . '/';
		$publish_url = self::HOME . '/' . self::PUBLISH_POST_SLUG . '/';

		$atelier = self::http_get( '/' . self::ATELIER_SLUG . '/', $cache_key, 0 );
		self::assert_page( $atelier, $atelier_url, self::ATELIER_H1, true );

		$archive = self::http_get( '/' . self::ARCHIVE_POST_SLUG . '/', $cache_key, 0 );
		self::assert_same( 301, $archive['status'], 'archive_pretty_status' );
		self::assert_same( 'Rank Math', $archive['x_redirect_by'], 'archive_redirect_provider' );
		self::assert_same(
			'/' . self::CONSOLIDATION_TARGET_SLUG . '/',
			(string) wp_parse_url( $archive['location'], PHP_URL_PATH ),
			'archive_redirect_target'
		);

		$archive_id = self::http_get( '/?p=' . self::ARCHIVE_POST_ID, $cache_key, 0 );
		self::assert_same( 404, $archive_id['status'], 'archive_id_query_exposed' );

		$destination = self::http_get( '/' . self::CONSOLIDATION_TARGET_SLUG . '/', $cache_key, 0 );
		self::assert_page(
			$destination,
			$destination_url,
			self::CONSOLIDATION_TARGET_H1,
			true
		);

		$publish = self::http_get( '/' . self::PUBLISH_POST_SLUG . '/', $cache_key, 0 );
		self::assert_page( $publish, $publish_url, self::PUBLISH_POST_H1, true );

		$sitemap = self::http_get( '/post-sitemap.xml', $cache_key, 0 );
		self::assert_same( 200, $sitemap['status'], 'post_sitemap_status' );
		preg_match_all( '#<url>\s*<loc>([^<]+)</loc>#i', $sitemap['body'], $matches );
		$urls = array_values(
			array_unique(
				array_map(
					static function ( string $url ): string {
						return html_entity_decode( trim( $url ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
					},
					$matches[1] ?? array()
				)
			)
		);
		sort( $urls, SORT_STRING );
		self::assert_same( 33, count( $urls ), 'post_sitemap_url_count' );
		foreach ( array( $atelier_url, $destination_url, $publish_url ) as $required ) {
			if ( ! in_array( $required, $urls, true ) ) {
				throw new RuntimeException( 'Missing required post sitemap URL: ' . $required );
			}
		}
		if ( in_array( $archive_url, $urls, true ) ) {
			throw new RuntimeException( 'Archived 24977 remains in post-sitemap.xml.' );
		}

		return array(
			'status' => 'PASS',
			'cache_buster' => $cache_key,
			'http' => array(
				'atelier' => self::public_http_result( $atelier ),
				'archive_redirect' => self::public_http_result( $archive ),
				'archive_id_query' => self::public_http_result( $archive_id ),
				'consolidation_target' => self::public_http_result( $destination ),
				'published_ranking' => self::public_http_result( $publish ),
			),
			'post_sitemap' => array(
				'status' => $sitemap['status'],
				'post_url_count' => count( $urls ),
				'atelier_present' => true,
				'consolidation_target_present' => true,
				'published_ranking_present' => true,
				'archived_24977_absent' => true,
			),
		);
	}

	/**
	 * Make a no-follow internal HTTP request through Docker service DNS.
	 *
	 * @return array<string,mixed>
	 */
	private static function http_get( string $path, string $cache_key, int $redirection ): array {
		$separator = false === strpos( $path, '?' ) ? '?' : '&';
		$url = self::INTERNAL_HTTP_ORIGIN . $path . $separator . 'akbqa=' . rawurlencode( $cache_key );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'redirection' => $redirection,
				'reject_unsafe_urls' => false,
				'headers' => array(
					'Host' => 'localhost:8080',
					'Cache-Control' => 'no-cache',
					'User-Agent' => 'Akibara-Docker-Blog-Close/1.0',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Docker HTTP validation failed: ' . $response->get_error_message() );
		}
		return array(
			'path' => $path,
			'status' => wp_remote_retrieve_response_code( $response ),
			'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
			'x_redirect_by' => (string) wp_remote_retrieve_header( $response, 'x-redirect-by' ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Validate core SEO/frontend invariants for one rendered article.
	 *
	 * @param array<string,mixed> $response       HTTP response.
	 * @param string              $canonical      Expected canonical.
	 * @param string              $expected_h1    Expected H1.
	 * @param bool                $require_schema Require Article-family schema.
	 */
	private static function assert_page(
		array $response,
		string $canonical,
		string $expected_h1,
		bool $require_schema
	): void {
		self::assert_same( 200, $response['status'], 'page_http_status_' . $response['path'] );
		$body = $response['body'];

		preg_match_all( '#<h1\b[^>]*>(.*?)</h1>#is', $body, $h1_matches );
		$h1s = array_map(
			static function ( string $html ): string {
				return self::normalize_visible_text( $html );
			},
			$h1_matches[1] ?? array()
		);
		self::assert_same( array( $expected_h1 ), $h1s, 'page_h1_' . $response['path'] );

		preg_match_all( '#<link\b[^>]*\brel=(["\'])canonical\1[^>]*>#i', $body, $canonical_tags );
		$canonicals = array();
		foreach ( $canonical_tags[0] ?? array() as $tag ) {
			if ( preg_match( '#\bhref=(["\'])(.*?)\1#i', $tag, $href ) ) {
				$canonicals[] = html_entity_decode( $href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		self::assert_same( array( $canonical ), $canonicals, 'page_canonical_' . $response['path'] );

		if ( preg_match( '#<meta\b[^>]*\bname=(["\'])robots\1[^>]*>#i', $body, $robots_tag ) ) {
			$robots = preg_match( '#\bcontent=(["\'])(.*?)\1#i', $robots_tag[0], $content )
				? strtolower( $content[2] )
				: '';
			if ( false !== strpos( $robots, 'noindex' ) ) {
				throw new RuntimeException( 'Page is noindex: ' . $response['path'] );
			}
		}

		if (
			$require_schema
			&& ! preg_match( '#"@type"\s*:\s*(?:\[\s*)?"(?:Article|BlogPosting|NewsArticle)"#', $body )
		) {
			throw new RuntimeException( 'Article-family schema missing: ' . $response['path'] );
		}
	}

	/**
	 * Remove bodies from receipt HTTP rows.
	 *
	 * @param array<string,mixed> $response Response.
	 * @return array<string,mixed>
	 */
	private static function public_http_result( array $response ): array {
		return array(
			'path' => $response['path'],
			'status' => $response['status'],
			'location' => $response['location'],
			'x_redirect_by' => $response['x_redirect_by'],
			'body_sha256' => hash( 'sha256', $response['body'] ),
		);
	}

	/**
	 * Normalize visible HTML text.
	 */
	private static function normalize_visible_text( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) ?? $text );
	}

	/**
	 * Normalize line endings used by the editorial receipt hash.
	 *
	 * @param mixed $value Value.
	 */
	private static function normalize( $value ): string {
		return str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
	}

	/**
	 * Canonical SHA-256 for structured snapshots.
	 *
	 * @param mixed $value Value.
	 */
	private static function hash_value( $value ): string {
		$value = self::canonicalize( $value );
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			throw new RuntimeException( 'Unable to encode a guarded snapshot.' );
		}
		return hash( 'sha256', $encoded );
	}

	/**
	 * Recursively sort object-like arrays while preserving list order.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::canonicalize( $child );
		}
		return $value;
	}

	/**
	 * Strict equality helper.
	 *
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $code     Stable failure code.
	 */
	private static function assert_same( $expected, $actual, string $code ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $code );
		}
	}
}

/**
 * Carries the receipt when an apply failed after entering the guarded lane.
 */
final class Akibara_Blog_Editorial_Docker_Close_Failure extends RuntimeException {
	/** @var array<string,mixed> */
	private $receipt;

	/**
	 * @param array<string,mixed> $receipt Receipt.
	 * @param Throwable           $previous Original failure.
	 */
	public function __construct( array $receipt, Throwable $previous ) {
		parent::__construct( $previous->getMessage(), 0, $previous );
		$this->receipt = $receipt;
	}

	/** @return array<string,mixed> */
	public function receipt(): array {
		return $this->receipt;
	}
}

/**
 * Write a new receipt with exclusive creation and mode 0600.
 *
 * @param string              $path    Receipt path.
 * @param array<string,mixed> $receipt Receipt.
 */
function akibara_blog_editorial_docker_close_write_receipt( string $path, array $receipt ): void {
	$encoded = wp_json_encode( $receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( false === $encoded ) {
		throw new RuntimeException( 'Unable to encode closure receipt.' );
	}
	$old_umask = umask( 0077 );
	$handle = fopen( $path, 'x' );
	umask( $old_umask );
	if ( false === $handle ) {
		throw new RuntimeException( 'Unable to create exclusive receipt.' );
	}
	$ok = flock( $handle, LOCK_EX )
		&& false !== fwrite( $handle, $encoded . "\n" )
		&& fflush( $handle );
	flock( $handle, LOCK_UN );
	fclose( $handle );
	if ( ! $ok || ! chmod( $path, 0600 ) || 0600 !== ( fileperms( $path ) & 0777 ) ) {
		throw new RuntimeException( 'Unable to seal closure receipt at mode 0600.' );
	}
}

if ( ! defined( 'AKB_BLOG_EDITORIAL_DOCKER_CLOSE_LIBRARY_ONLY' ) ) {
	if ( ! isset( $args ) || 1 !== count( $args ) ) {
		WP_CLI::error( 'Expected exactly one receipt path argument.' );
	}
	$receipt_path = (string) $args[0];
	if (
		1 !== preg_match(
			'#^/tmp/akb-blog-editorial-docker-close-[a-f0-9]{12}\.json$#D',
			$receipt_path
		)
		|| file_exists( $receipt_path )
		|| ! is_writable( dirname( $receipt_path ) )
	) {
		WP_CLI::error( 'Unsafe, existing or unwritable receipt path.' );
	}

	$apply_raw = getenv( 'AKB_APPLY' );
	if ( '0' !== $apply_raw && '1' !== $apply_raw ) {
		WP_CLI::error( 'AKB_APPLY must be explicitly 0 or 1.' );
	}

	try {
		$receipt = Akibara_Blog_Editorial_Docker_Close::run( '1' === $apply_raw );
		akibara_blog_editorial_docker_close_write_receipt( $receipt_path, $receipt );
		WP_CLI::log( wp_json_encode( $receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	} catch ( Akibara_Blog_Editorial_Docker_Close_Failure $failure ) {
		akibara_blog_editorial_docker_close_write_receipt( $receipt_path, $failure->receipt() );
		WP_CLI::error( 'Closure failed; inspect the sealed receipt: ' . $failure->getMessage() );
	} catch ( Throwable $failure ) {
		WP_CLI::error( 'Closure preflight failed before mutation: ' . $failure->getMessage() );
	}
}
