<?php
/**
 * Stable lastmod ownership for the `pa_serie` taxonomy sitemap.
 *
 * Rank Math derives taxonomy dates from the newest related product. A stock or
 * price save can therefore rewrite every affected series date even when the
 * landing's durable editorial content did not change. This class replaces that
 * signal with two persisted sources: actual changes to visible series data and
 * the activation time of an explicit renderer version.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoIndexing;

use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\SeriesSchema\SeriesSchemaType;
use Akibara\Business\Utilities\RobotsMeta;
use RankMath\Sitemap\Cache_Watcher;

defined( 'ABSPATH' ) || exit;

/**
 * Maintains trustworthy modification dates for series sitemap entries.
 */
final class SeriesSitemapLastmod {

	private const TAXONOMY = SeriesCatalogEligibility::TAXONOMY;

	private const BRAND_TAXONOMY = 'product_brand';

	private const AUTHOR_TAXONOMY = 'pa_autor';

	private const CATALOG_FACET_TAXONOMIES = array(
		self::BRAND_TAXONOMY,
		self::AUTHOR_TAXONOMY,
	);

	private const RENDERER_STATE_OPTION = 'akb_pa_serie_renderer_state';

	private const LASTMOD_META_KEY = '_akb_serie_sitemap_lastmod_gmt';

	private const FINGERPRINT_META_KEY = '_akb_serie_sitemap_fingerprint';

	private const PENDING_CHANGES_OPTION = 'akb_pa_serie_sitemap_pending_changes';

	private const MAX_PENDING_IDS = 2048;

	private const MAX_PENDING_CAS_ATTEMPTS = 8;

	private const RELATED_EDITIONS_META_KEY = '_akb_serie_ediciones_relacionadas';

	private const RELATED_GUIDE_META_KEY = '_akb_related_guide_post_id';

	private const ROBOTS_META_KEY = 'rank_math_robots';

	private const MAX_RELATED_EDITIONS = 8;

	private const THUMBNAIL_META_KEY = '_thumbnail_id';

	/**
	 * Durable term data that changes the public landing or its search metadata.
	 *
	 * Inventory, price and stock are deliberately absent. They are useful live
	 * commerce signals but too volatile to claim that the editorial landing was
	 * rewritten after every sale or replenishment.
	 *
	 * @var string[]
	 */
	private const WATCHED_META_KEYS = array(
		'rank_math_title',
		'rank_math_description',
		self::ROBOTS_META_KEY,
		'rank_math_canonical_url',
		'_akb_serie_estado',
		'_akb_serie_tomos_obra',
		'_akb_serie_estado_edicion',
		'_akb_serie_tomos_edicion',
		SeriesSchemaType::META_KEY,
		self::RELATED_EDITIONS_META_KEY,
		self::RELATED_GUIDE_META_KEY,
	);

	/**
	 * Explicit public renderer version.
	 *
	 * @var string
	 */
	private string $renderer_version;

	/** Shared catalogue-visibility predicate for every public series surface. */
	private SeriesCatalogEligibility $catalog_eligibility;

	/**
	 * UTC timestamp provider.
	 *
	 * @var \Closure
	 */
	private \Closure $clock;

	/**
	 * Invalidates only the series sitemap cache through Rank Math.
	 *
	 * @var \Closure(string):void
	 */
	private \Closure $sitemap_cache_invalidator;

	/**
	 * Flushes Rank Math's queued invalidations during WordPress shutdown.
	 *
	 * @var \Closure():void
	 */
	private \Closure $sitemap_cache_flusher;

	/** @var \Closure():string */
	private \Closure $pending_token_generator;

	/** @var \Closure(int):void */
	private \Closure $term_page_cache_invalidator;

	/**
	 * Optional wpdb-compatible dependency used by durable-queue tests.
	 *
	 * @var object|null
	 */
	private $database;

	/**
	 * Whether this request already queued the scoped Rank Math invalidation.
	 *
	 * @var bool
	 */
	private bool $sitemap_cache_invalidation_queued = false;

	/** @var array<int,int> */
	private array $invalidated_term_page_ids = array();

	/** @var array<int,int> */
	private array $pending_term_ids = array();

	/** @var array<int,int> */
	private array $pending_term_taxonomy_ids = array();

	/** @var array<int,int> */
	private array $pending_product_ids = array();

	/** @var array<int,int> */
	private array $pending_guide_post_ids = array();

	/** Whether a bounded durable queue overflow requires checking every term. */
	private bool $pending_full_rescan = false;

	/** Whether this request inspected the durable retry option. */
	private bool $durable_pending_loaded = false;

	/** Generation token observed or written by this request. */
	private string $durable_pending_token = '';

	/** @var array<int,object>|null */
	private ?array $series_terms = null;

	/** @var array<int,int>|null */
	private ?array $term_ids_by_term_taxonomy_id = null;

	/** Whether every loaded series count has been requested from the batch service. */
	private bool $catalog_eligibility_primed = false;

	/** @var array<int,array{autores:array<int,array{term_id:int,name:string,slug:string}>,editoriales:array<int,array{term_id:int,name:string,slug:string}>,productos:array<int,array{product_id:int,title:string,slug:string,thumbnail_id:int}>}>|null */
	private ?array $catalog_editorial_state = null;

	/** Whether the aggregate author/editorial snapshot completed reliably. */
	private bool $catalog_editorial_state_reliable = false;

	/** @var array<int,array{post_id:int,post_type:string,title:string,slug:string,status:string}>|null */
	private ?array $related_guide_state = null;

	/** Whether the selected-guide post snapshot completed reliably. */
	private bool $related_guide_state_reliable = false;

	/**
	 * Reverse related-edition graph for this request.
	 *
	 * @var array<int,array<int,int>>|null
	 */
	private ?array $related_dependents = null;

	/**
	 * Builds the tracker with an explicit renderer version.
	 *
	 * @param string        $renderer_version           Explicit version of public series rendering.
	 * @param callable|null $clock                      UTC timestamp provider for deterministic tests.
	 * @param callable|null $sitemap_cache_invalidator Rank Math cache invalidator for deterministic tests.
	 * @param callable|null $sitemap_cache_flusher     Rank Math queued-cache flusher for deterministic tests.
	 * @param SeriesCatalogEligibility|null $catalog_eligibility Shared catalogue eligibility service.
	 * @param callable|null $pending_token_generator Durable-queue generation token provider.
	 * @param object|null   $database                Optional wpdb-compatible durable-queue storage.
	 * @param callable|null $term_page_cache_invalidator Exact term-page cache invalidator.
	 * @throws \InvalidArgumentException When the renderer version is empty.
	 */
	public function __construct( string $renderer_version, ?callable $clock = null, ?callable $sitemap_cache_invalidator = null, ?callable $sitemap_cache_flusher = null, ?SeriesCatalogEligibility $catalog_eligibility = null, ?callable $pending_token_generator = null, ?object $database = null, ?callable $term_page_cache_invalidator = null ) {
		$renderer_version = trim( $renderer_version );
		if ( '' === $renderer_version ) {
			throw new \InvalidArgumentException( 'The series renderer version cannot be empty.' );
		}

		$this->renderer_version    = $renderer_version;
		$this->catalog_eligibility = $catalog_eligibility ?? new SeriesCatalogEligibility();
		$this->clock               = null === $clock
			? static fn(): int => time()
			: \Closure::fromCallable( $clock );

		$this->sitemap_cache_invalidator = null === $sitemap_cache_invalidator
			? static function ( string $taxonomy ): void {
				if ( class_exists( Cache_Watcher::class ) ) {
					Cache_Watcher::invalidate( $taxonomy );
				}
			}
			: \Closure::fromCallable( $sitemap_cache_invalidator );

		$this->sitemap_cache_flusher = null === $sitemap_cache_flusher
			? static function (): void {
				if ( class_exists( Cache_Watcher::class ) ) {
					Cache_Watcher::clear_queued();
				}
			}
			: \Closure::fromCallable( $sitemap_cache_flusher );

		$this->pending_token_generator = null === $pending_token_generator
			? static function (): string {
				return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'akibara-', true );
			}
			: \Closure::fromCallable( $pending_token_generator );
		$this->term_page_cache_invalidator = null === $term_page_cache_invalidator
			? static function ( int $term_id ): void {
				do_action( 'litespeed_purge', 'T.' . $term_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public LiteSpeed Cache hook.
			}
			: \Closure::fromCallable( $term_page_cache_invalidator );
		$this->database = $database;
	}

	/**
	 * Registers stable-data tracking and the Rank Math sitemap override.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'handle_init_renderer_state' ), 20 );
		add_action( 'created_' . self::TAXONOMY, array( $this, 'handle_series_term_changed' ), 100 );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'handle_series_term_changed' ), 100 );
		add_action( 'added_term_meta', array( $this, 'handle_term_meta_changed' ), 100, 4 );
		add_action( 'updated_term_meta', array( $this, 'handle_term_meta_changed' ), 100, 4 );
		add_action( 'deleted_term_meta', array( $this, 'handle_term_meta_changed' ), 100, 4 );
		add_action( 'set_object_terms', array( $this, 'handle_object_terms_set' ), 100, 6 );
		add_action( 'deleted_term_relationships', array( $this, 'handle_term_relationships_deleted' ), 100, 3 );
		add_action( 'transition_post_status', array( $this, 'handle_product_status_transition' ), 100, 3 );
		add_action( 'post_updated', array( $this, 'handle_product_post_updated' ), 100, 3 );
		add_action( 'before_delete_post', array( $this, 'handle_guide_before_delete' ), 100, 2 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'handle_product_meta_changed' ), 100, 4 );
		}
		add_action( 'clean_term_cache', array( $this, 'handle_term_cache_cleaned' ), 100, 3 );
		foreach ( self::CATALOG_FACET_TAXONOMIES as $taxonomy ) {
			add_action( 'edited_' . $taxonomy, array( $this, 'handle_catalog_facet_term_changed' ), 100 );
			add_action( 'delete_' . $taxonomy, array( $this, 'handle_catalog_facet_term_changed' ), 100 );
		}
		add_action( 'shutdown', array( $this, 'handle_shutdown_sitemap_cache_flush' ), PHP_INT_MAX );
		add_filter( 'rank_math/sitemap/entry', array( $this, 'handle_sitemap_entry' ), 20, 3 );
		add_filter( 'rank_math/sitemap/index/entry', array( $this, 'handle_sitemap_index_entry' ), 20, 3 );
	}

	/**
	 * Records the first activation time of each renderer version.
	 *
	 * Existing fingerprints are refreshed before committing the new version.
	 * That baseline prevents a later no-op term save from masquerading as a
	 * content change, while the one global timestamp truthfully covers the
	 * renderer change for every series.
	 *
	 * @internal Callback de `init`.
	 */
	public function handle_init_renderer_state(): void {
		$this->load_persisted_pending_changes();

		$state = $this->renderer_state();
		if (
			(string) ( $state['version'] ?? '' ) === $this->renderer_version
			&& '' !== self::normalize_gmt_datetime( $state['modified_gmt'] ?? '' )
		) {
			return;
		}

		if ( ! $this->seed_term_fingerprints() ) {
			return;
		}

		$updated = update_option(
			self::RENDERER_STATE_OPTION,
			array(
				'version'      => $this->renderer_version,
				'modified_gmt' => $this->now_gmt(),
			),
			false
		);

		if ( $updated ) {
			$this->queue_sitemap_cache_invalidation();
		}
	}

	/**
	 * Detects actual term, slug, name or description changes.
	 *
	 * @internal Callback de `created_pa_serie` y `edited_pa_serie`.
	 *
	 * @param int $term_id Term ID.
	 */
	public function handle_series_term_changed( int $term_id ): void {
		$this->queue_term_ids( array( $term_id ) );
		$this->series_terms                 = null;
		$this->term_ids_by_term_taxonomy_id = null;
		$this->related_dependents           = null;
		$this->catalog_eligibility_primed   = false;
		$this->reset_catalog_editorial_state();
		$this->reset_related_guide_state();
	}

	/**
	 * Detects changes to the durable public metadata allowlist.
	 *
	 * @internal Callback de los hooks genéricos de term meta.
	 *
	 * @param mixed  $meta_ids   Meta ID or IDs supplied by WordPress.
	 * @param int    $term_id    Term ID.
	 * @param string $meta_key   Changed key.
	 * @param mixed  $meta_value Changed value.
	 */
	public function handle_term_meta_changed( $meta_ids, int $term_id, string $meta_key, $meta_value = null ): void {
		unset( $meta_ids, $meta_value );

		if ( ! in_array( $meta_key, self::WATCHED_META_KEYS, true ) ) {
			return;
		}

		if ( self::RELATED_EDITIONS_META_KEY === $meta_key ) {
			$this->related_dependents = null;
		}
		if ( self::RELATED_GUIDE_META_KEY === $meta_key ) {
			$this->reset_related_guide_state();
		}

		$this->queue_term_ids( array( $term_id ) );
	}

	/**
	 * Queues final eligibility checks after a product changes series or visibility.
	 *
	 * @internal Callback de `set_object_terms`.
	 *
	 * @param mixed $object_id  Object whose terms changed.
	 * @param mixed $terms      Requested terms (informational).
	 * @param mixed $tt_ids     Resulting term-taxonomy IDs.
	 * @param mixed $taxonomy   Changed taxonomy.
	 * @param mixed $append     Whether terms were appended (informational).
	 * @param mixed $old_tt_ids Previous term-taxonomy IDs.
	 */
	public function handle_object_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		unset( $terms, $append );

		if ( self::TAXONOMY === (string) $taxonomy ) {
			$this->queue_term_taxonomy_ids( array_merge( (array) $tt_ids, (array) $old_tt_ids ) );
			return;
		}

		if (
			SeriesCatalogEligibility::VISIBILITY_TAXONOMY === (string) $taxonomy
			|| in_array( (string) $taxonomy, self::CATALOG_FACET_TAXONOMIES, true )
		) {
			$this->queue_product_ids( array( $object_id ) );
		}
	}

	/**
	 * Queues direct relationship removals, including hard product deletion.
	 *
	 * @internal Callback de `deleted_term_relationships`.
	 *
	 * @param mixed $object_id Object whose relationships were removed.
	 * @param mixed $tt_ids    Removed term-taxonomy IDs.
	 * @param mixed $taxonomy  Changed taxonomy.
	 */
	public function handle_term_relationships_deleted( $object_id, $tt_ids, $taxonomy ): void {
		if ( self::TAXONOMY === (string) $taxonomy ) {
			$this->queue_term_taxonomy_ids( (array) $tt_ids );
			return;
		}

		if (
			SeriesCatalogEligibility::VISIBILITY_TAXONOMY === (string) $taxonomy
			|| in_array( (string) $taxonomy, self::CATALOG_FACET_TAXONOMIES, true )
		) {
			$this->queue_product_ids( array( $object_id ) );
		}
	}

	/**
	 * Recomputes all fingerprints after a public author/editorial identity changes.
	 *
	 * Term edits are rare and do not identify the affected products in their
	 * callback. One bounded aggregate rescan avoids an N+1 reverse lookup while
	 * still dating only series whose final fingerprint actually changed.
	 *
	 * @internal Callback de `edited_*` y `delete_*` para taxonomías editoriales.
	 */
	public function handle_catalog_facet_term_changed(): void {
		$this->pending_full_rescan = true;
		$this->reset_catalog_editorial_state();
	}

	/**
	 * Queues products or selected guides when they cross the public boundary.
	 *
	 * @internal Callback de `transition_post_status` after Core term recounts.
	 *
	 * @param mixed $new_status New post status.
	 * @param mixed $old_status Previous post status.
	 * @param mixed $post       Changed post object.
	 */
	public function handle_product_status_transition( $new_status, $old_status, $post ): void {
		if (
			! is_object( $post )
			|| ( 'publish' === (string) $new_status ) === ( 'publish' === (string) $old_status )
		) {
			return;
		}

		$post_type = (string) ( $post->post_type ?? '' );
		if ( 'product' === $post_type ) {
			$this->queue_product_ids( array( $post->ID ?? 0 ) );
		} elseif ( 'post' === $post_type ) {
			$this->queue_guide_post_ids( array( $post->ID ?? 0 ) );
		}
	}

	/**
	 * Queues only title or permalink-slug changes from a product post update.
	 *
	 * `post_modified`, menu order and other saves are deliberately ignored. The
	 * final product digest then protects against callbacks whose public identity
	 * remained unchanged.
	 *
	 * @internal Callback de `post_updated`.
	 *
	 * @param mixed $post_id     Updated post ID.
	 * @param mixed $post_after  Final post object.
	 * @param mixed $post_before Previous post object.
	 */
	public function handle_product_post_updated( $post_id, $post_after, $post_before ): void {
		if (
			! is_object( $post_after )
			|| ! is_object( $post_before )
		) {
			return;
		}

		$after_type  = (string) ( $post_after->post_type ?? '' );
		$before_type = (string) ( $post_before->post_type ?? '' );
		$identity_changed =
			(string) ( $post_after->post_title ?? '' ) !== (string) ( $post_before->post_title ?? '' )
			|| (string) ( $post_after->post_name ?? '' ) !== (string) ( $post_before->post_name ?? '' );
		if ( 'product' === $after_type && 'product' === $before_type && $identity_changed ) {
			$this->queue_product_ids( array( $post_id ) );
			return;
		}

		$after_is_public_guide  = 'post' === $after_type && 'publish' === (string) ( $post_after->post_status ?? '' );
		$before_is_public_guide = 'post' === $before_type && 'publish' === (string) ( $post_before->post_status ?? '' );
		if ( $after_is_public_guide !== $before_is_public_guide || ( $identity_changed && ( $after_is_public_guide || $before_is_public_guide ) ) ) {
			$this->queue_guide_post_ids( array( $post_id ) );
		}
	}

	/** Queues a selected guide before its row can disappear permanently. */
	public function handle_guide_before_delete( $post_id, $post ): void {
		if ( is_object( $post ) && 'post' === (string) ( $post->post_type ?? '' ) ) {
			$this->queue_guide_post_ids( array( $post_id ) );
		}
	}

	/**
	 * Queues product-card image changes without reacting to price or stock meta.
	 *
	 * Non-product IDs are harmless: they resolve to no `pa_serie` relationship
	 * in the one final batch, avoiding a per-callback post-type lookup.
	 *
	 * @internal Callback de los hooks genéricos de post meta.
	 *
	 * @param mixed  $meta_ids   Meta ID or IDs supplied by WordPress.
	 * @param mixed  $object_id  Post whose metadata changed.
	 * @param string $meta_key   Changed key.
	 * @param mixed  $meta_value Changed value.
	 */
	public function handle_product_meta_changed( $meta_ids, $object_id, string $meta_key, $meta_value = null ): void {
		unset( $meta_ids, $meta_value );
		if ( self::THUMBNAIL_META_KEY !== $meta_key ) {
			return;
		}

		$this->queue_product_ids( array( $object_id ) );
	}

	/**
	 * Detects fresh catalogue eligibility after Core has cleared term caches.
	 *
	 * The term fingerprint keeps the exact visible count because grid, facts and
	 * ComicSeries schema change when one volume enters or leaves the catalogue.
	 * Related owners keep only `0` versus `>0`, because their peer link does not
	 * print that count.
	 *
	 * When Core starts with term-taxonomy IDs and an empty taxonomy, it resolves
	 * both before firing this action. This callback therefore receives term IDs
	 * alongside the concrete `pa_serie` taxonomy used by the guard below.
	 *
	 * @internal Callback de `clean_term_cache`.
	 *
	 * @param mixed  $term_ids      Term IDs whose cache was cleared.
	 * @param string $taxonomy      Taxonomy whose cache was cleared.
	 * @param bool   $clean_taxonomy Whether the taxonomy-wide cache was cleared.
	 */
	public function handle_term_cache_cleaned( $term_ids, string $taxonomy, bool $clean_taxonomy ): void {
		unset( $clean_taxonomy );

		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}

		$this->queue_term_ids( (array) $term_ids );
		$this->series_terms                 = null;
		$this->term_ids_by_term_taxonomy_id = null;
		$this->related_dependents           = null;
		$this->catalog_eligibility_primed   = false;
		$this->reset_catalog_editorial_state();
		$this->reset_related_guide_state();
	}

	/**
	 * Flushes a queued Rank Math invalidation at the end of the request.
	 *
	 * Rank Math's public `invalidate()` API only enqueues cache types. Its own
	 * watcher is not constructed on every frontend request, so this late and
	 * idempotent callback guarantees that mutations detected here reach storage
	 * without deleting sitemap files inline while the response is rendering.
	 *
	 * @internal Callback de `shutdown`.
	 */
	public function handle_shutdown_sitemap_cache_flush(): void {
		$this->commit_pending_term_changes();

		if ( ! $this->sitemap_cache_invalidation_queued ) {
			return;
		}

		( $this->sitemap_cache_flusher )();
		$this->sitemap_cache_invalidation_queued = false;
	}

	/**
	 * Replaces Rank Math's product-derived modification date for series terms.
	 *
	 * If neither durable source is available, `mod` is removed. Omitting a date
	 * is more accurate than retaining a timestamp driven by an unrelated stock
	 * save, and normal runtime initialization provides the renderer date before
	 * Rank Math builds its sitemap.
	 *
	 * @internal Callback de `rank_math/sitemap/entry`.
	 *
	 * @param mixed $url          Sitemap entry.
	 * @param mixed $type         Rank Math object type.
	 * @param mixed $entry_object Term supplied by Rank Math.
	 * @return mixed
	 */
	public function handle_sitemap_entry( $url, $type, $entry_object ) {
		if (
			! is_array( $url )
			|| 'term' !== $type
			|| ! is_object( $entry_object )
			|| ! isset( $entry_object->term_id, $entry_object->taxonomy )
			|| self::TAXONOMY !== (string) $entry_object->taxonomy
		) {
			return $url;
		}

		$term_taxonomy_id = self::term_taxonomy_id( $entry_object );
		if ( $term_taxonomy_id > 0 ) {
			$this->prime_catalog_eligibility();
			if ( ! $this->catalog_eligibility->is_reliable() ) {
				// Preserve the entry but discard Rank Math's volatile product date below.
				// The late purge prevents this fail-open XML from becoming the cache hit.
				$this->queue_sitemap_cache_invalidation();
			} elseif ( ! $this->catalog_eligibility->is_eligible( $term_taxonomy_id ) ) {
				return false;
			}
		}

		$term_lastmod = get_term_meta( (int) $entry_object->term_id, self::LASTMOD_META_KEY, true );
		$modified_gmt = self::latest_gmt_datetime(
			array(
				$term_lastmod,
				$this->renderer_modified_gmt(),
			)
		);

		if ( '' === $modified_gmt ) {
			unset( $url['mod'] );
			return $url;
		}

		$url['mod'] = $modified_gmt;

		return $url;
	}

	/**
	 * Replaces the product-derived date of the series child-sitemap index entry.
	 *
	 * @internal Callback de `rank_math/sitemap/index/entry`.
	 *
	 * @param mixed $url      Sitemap-index entry.
	 * @param mixed $type     Rank Math object type.
	 * @param mixed $taxonomy Taxonomy supplied by Rank Math.
	 * @return mixed
	 */
	public function handle_sitemap_index_entry( $url, $type, $taxonomy ) {
		if ( ! is_array( $url ) || 'term' !== $type || self::TAXONOMY !== (string) $taxonomy ) {
			return $url;
		}

		$terms = $this->all_series_terms();
		if ( null === $terms ) {
			unset( $url['lastmod'] );
			$this->queue_sitemap_cache_invalidation();
			return $url;
		}

		$this->prime_catalog_eligibility();
		$reliable = $this->catalog_eligibility->is_reliable();
		$dates    = array();
		$has_indexable_term = false;
		$has_public_term = false;
		foreach ( $terms as $term ) {
			$term_id = (int) $term->term_id;
			$dates[] = get_term_meta( $term_id, self::LASTMOD_META_KEY, true );
			if ( $this->term_is_noindex( $term_id ) ) {
				continue;
			}
			$has_indexable_term = true;
			if ( $reliable && ! $this->term_is_catalog_eligible( $term ) ) {
				continue;
			}

			$has_public_term = true;
		}

		if ( ! $has_indexable_term ) {
			return false;
		}
		if ( $reliable && ! $has_public_term ) {
			return false;
		}

		$dates[] = $this->renderer_modified_gmt();
		$lastmod = self::latest_gmt_datetime( $dates );
		if ( '' === $lastmod ) {
			unset( $url['lastmod'] );
		} else {
			$url['lastmod'] = $lastmod;
		}

		if ( ! $reliable ) {
			$this->queue_sitemap_cache_invalidation();
		}

		return $url;
	}

	/**
	 * Stores a durable date only when the public fingerprint really changed.
	 *
	 * @param object $term Series term.
	 */
	private function record_term_change( object $term ): bool {
		$term_id     = (int) $term->term_id;
		$fingerprint = $this->term_fingerprint( $term );
		$stored      = (string) get_term_meta( $term_id, self::FINGERPRINT_META_KEY, true );
		if ( '' !== $stored && hash_equals( $stored, $fingerprint ) ) {
			return true;
		}

		$modified_gmt = $this->now_gmt();
		update_term_meta( $term_id, self::LASTMOD_META_KEY, $modified_gmt );
		if ( $modified_gmt !== (string) get_term_meta( $term_id, self::LASTMOD_META_KEY, true ) ) {
			return false;
		}

		update_term_meta( $term_id, self::FINGERPRINT_META_KEY, $fingerprint );
		$persisted_fingerprint = (string) get_term_meta( $term_id, self::FINGERPRINT_META_KEY, true );
		if ( '' === $persisted_fingerprint || ! hash_equals( $persisted_fingerprint, $fingerprint ) ) {
			return false;
		}

		$this->invalidate_term_page_cache( $term_id );
		$this->queue_sitemap_cache_invalidation();

		return true;
	}

	/** Purges each changed series landing at most once per request. */
	private function invalidate_term_page_cache( int $term_id ): void {
		if ( $term_id < 1 || isset( $this->invalidated_term_page_ids[ $term_id ] ) ) {
			return;
		}

		( $this->term_page_cache_invalidator )( $term_id );
		$this->invalidated_term_page_ids[ $term_id ] = $term_id;
	}

	/**
	 * Queues one scoped Rank Math invalidation per request.
	 *
	 * A WP-CLI `update_term_meta()` does not fire Rank Math's edited-term
	 * watcher. Coalescing here covers that path without enqueueing the same
	 * taxonomy 104 times during a batch update.
	 */
	private function queue_sitemap_cache_invalidation(): void {
		if ( $this->sitemap_cache_invalidation_queued ) {
			return;
		}

		( $this->sitemap_cache_invalidator )( self::TAXONOMY );
		$this->sitemap_cache_invalidation_queued = true;
	}

	/**
	 * Seeds fingerprints without claiming that every term changed today.
	 */
	private function seed_term_fingerprints(): bool {
		if ( ! function_exists( 'get_terms' ) ) {
			return false;
		}

		$terms = $this->all_series_terms();
		if ( null === $terms ) {
			return false;
		}
		if ( $this->catalog_eligibility_primed && ! $this->catalog_eligibility->is_reliable() ) {
			$this->catalog_eligibility_primed = false;
		}
		$this->prime_catalog_eligibility();
		if ( ! $this->catalog_eligibility->is_reliable() ) {
			return false;
		}
		$this->prime_catalog_editorial_state();
		if ( ! $this->catalog_editorial_state_reliable ) {
			return false;
		}
		$this->prime_related_guide_state();
		if ( ! $this->related_guide_state_reliable ) {
			return false;
		}

		foreach ( $terms as $term ) {
			$fingerprint = $this->term_fingerprint( $term );
			$stored      = (string) get_term_meta( (int) $term->term_id, self::FINGERPRINT_META_KEY, true );
			if ( '' === $stored || ! hash_equals( $stored, $fingerprint ) ) {
				update_term_meta( (int) $term->term_id, self::FINGERPRINT_META_KEY, $fingerprint );
				$persisted = (string) get_term_meta( (int) $term->term_id, self::FINGERPRINT_META_KEY, true );
				if ( '' === $persisted || ! hash_equals( $persisted, $fingerprint ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/** Commits every final catalogue mutation once after Core has finished recounting. */
	private function commit_pending_term_changes(): void {
		if (
			! $this->pending_full_rescan
			&&
			array() === $this->pending_term_ids
			&& array() === $this->pending_term_taxonomy_ids
			&& array() === $this->pending_product_ids
			&& array() === $this->pending_guide_post_ids
		) {
			return;
		}

		$terms = $this->all_series_terms();
		if ( null === $terms || null === $this->term_ids_by_term_taxonomy_id ) {
			$this->persist_pending_changes();
			return;
		}

		$term_ids             = $this->pending_term_ids;
		$term_taxonomy_ids    = $this->pending_term_taxonomy_ids;
		if ( $this->pending_full_rescan ) {
			foreach ( $terms as $term_id => $term ) {
				$term_ids[ $term_id ] = $term_id;
				$term_taxonomy_id     = self::term_taxonomy_id( $term );
				if ( $term_taxonomy_id > 0 ) {
					$term_taxonomy_ids[ $term_taxonomy_id ] = $term_taxonomy_id;
				}
			}
		} else {
			$product_term_taxonomy_ids = $this->product_series_term_taxonomy_ids( array_values( $this->pending_product_ids ) );
			if ( null === $product_term_taxonomy_ids ) {
				$this->persist_pending_changes();
				return;
			}

			foreach ( $product_term_taxonomy_ids as $term_taxonomy_id ) {
				$term_taxonomy_ids[ $term_taxonomy_id ] = $term_taxonomy_id;
			}

			foreach ( $terms as $term_id => $term ) {
				$guide_post_id = self::normalize_term_id( get_term_meta( $term_id, self::RELATED_GUIDE_META_KEY, true ) );
				if ( isset( $this->pending_guide_post_ids[ $guide_post_id ] ) ) {
					$term_ids[ $term_id ] = $term_id;
				}
			}
		}

		foreach ( $term_ids as $term_id ) {
			if ( isset( $terms[ $term_id ] ) ) {
				$term_taxonomy_id = self::term_taxonomy_id( $terms[ $term_id ] );
				if ( $term_taxonomy_id > 0 ) {
					$term_taxonomy_ids[ $term_taxonomy_id ] = $term_taxonomy_id;
				}
			}
		}

		foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
			$term_id = $this->term_ids_by_term_taxonomy_id[ $term_taxonomy_id ] ?? 0;
			if ( $term_id > 0 ) {
				$term_ids[ $term_id ] = $term_id;
			}
		}
		$this->pending_term_taxonomy_ids = $term_taxonomy_ids;
		$this->pending_product_ids       = array();
		$this->pending_guide_post_ids    = array();

		$this->catalog_eligibility->reset_cache( array_values( $term_taxonomy_ids ) );
		$this->catalog_eligibility_primed = false;
		$this->prime_catalog_eligibility();
		if ( ! $this->catalog_eligibility->is_reliable() ) {
			$this->persist_pending_changes();
			return;
		}
		$this->prime_catalog_editorial_state();
		if ( ! $this->catalog_editorial_state_reliable ) {
			$this->persist_pending_changes();
			return;
		}
		$this->prime_related_guide_state();
		if ( ! $this->related_guide_state_reliable ) {
			$this->persist_pending_changes();
			return;
		}

		$changed_term_ids = $term_ids;
		foreach ( $term_ids as $term_id ) {
			foreach ( $this->related_dependent_term_ids( $term_id ) as $dependent_id ) {
				$changed_term_ids[ $dependent_id ] = $dependent_id;
			}
		}

		$persisted = true;
		foreach ( $changed_term_ids as $term_id ) {
			if ( isset( $terms[ $term_id ] ) && ! $this->record_term_change( $terms[ $term_id ] ) ) {
				$persisted = false;
			}
		}
		if ( ! $persisted ) {
			$this->persist_pending_changes();
			return;
		}

		$this->pending_term_ids          = array();
		$this->pending_term_taxonomy_ids = array();
		$this->pending_product_ids       = array();
		$this->pending_guide_post_ids    = array();
		$this->pending_full_rescan       = false;
		$this->clear_persisted_pending_changes();
	}

	/**
	 * Resolves all affected products to series term-taxonomy IDs in one query.
	 *
	 * @param array<int,int> $product_ids Product IDs.
	 * @return array<int,int>|null IDs, or null when the relation read failed.
	 */
	private function product_series_term_taxonomy_ids( array $product_ids ): ?array {
		if ( array() === $product_ids ) {
			return array();
		}
		if ( ! function_exists( 'wp_get_object_terms' ) ) {
			return null;
		}

		$term_taxonomy_ids = wp_get_object_terms(
			$product_ids,
			self::TAXONOMY,
			array( 'fields' => 'tt_ids' )
		);
		$database          = $this->database();
		if (
			null !== $database
			&& (
				( isset( $database->last_error ) && '' !== trim( (string) $database->last_error ) )
				|| ( isset( $database->ready ) && ! (bool) $database->ready )
			)
		) {
			return null;
		}

		return is_array( $term_taxonomy_ids ) ? self::normalize_ids( $term_taxonomy_ids ) : null;
	}

	/** @param array<int,mixed> $term_ids Term IDs to coalesce. */
	private function queue_term_ids( array $term_ids ): void {
		foreach ( self::normalize_ids( $term_ids ) as $term_id ) {
			$this->pending_term_ids[ $term_id ] = $term_id;
		}
	}

	/** @param array<int,mixed> $term_taxonomy_ids Term-taxonomy IDs to coalesce. */
	private function queue_term_taxonomy_ids( array $term_taxonomy_ids ): void {
		foreach ( self::normalize_ids( $term_taxonomy_ids ) as $term_taxonomy_id ) {
			$this->pending_term_taxonomy_ids[ $term_taxonomy_id ] = $term_taxonomy_id;
		}
		$this->catalog_eligibility_primed = false;
		$this->reset_catalog_editorial_state();
	}

	/** @param array<int,mixed> $product_ids Product IDs to coalesce. */
	private function queue_product_ids( array $product_ids ): void {
		foreach ( self::normalize_ids( $product_ids ) as $product_id ) {
			$this->pending_product_ids[ $product_id ] = $product_id;
		}
		$this->catalog_eligibility_primed = false;
		$this->reset_catalog_editorial_state();
	}

	/** @param array<int,mixed> $post_ids Related-guide post IDs to coalesce. */
	private function queue_guide_post_ids( array $post_ids ): void {
		foreach ( self::normalize_ids( $post_ids ) as $post_id ) {
			$this->pending_guide_post_ids[ $post_id ] = $post_id;
		}
		$this->reset_related_guide_state();
	}

	/** Loads a bounded durable retry queue once and merges it into request state. */
	private function load_persisted_pending_changes(): void {
		if ( $this->durable_pending_loaded ) {
			return;
		}

		$snapshot = $this->pending_option_snapshot();
		$state    = self::normalize_pending_state( $snapshot['value'] );
		$this->durable_pending_loaded = true;
		$this->durable_pending_token  = $state['token'];

		if ( ! self::pending_state_has_work( $state ) ) {
			if ( $snapshot['exists'] ) {
				$this->compare_and_delete_pending_option( $snapshot );
			}
			return;
		}

		$this->pending_full_rescan = $this->pending_full_rescan || $state['full_rescan'];
		$this->queue_term_ids( $state['term_ids'] );
		$this->queue_term_taxonomy_ids( $state['term_taxonomy_ids'] );
		$this->queue_product_ids( $state['product_ids'] );
		$this->queue_guide_post_ids( $state['guide_post_ids'] );
	}

	/** Persists unresolved work without autoloading or allowing unbounded growth. */
	private function persist_pending_changes(): bool {
		$pending = self::normalize_pending_state(
			array(
				'full_rescan'      => $this->pending_full_rescan,
				'term_ids'         => array_values( $this->pending_term_ids ),
				'term_taxonomy_ids' => array_values( $this->pending_term_taxonomy_ids ),
				'product_ids'      => array_values( $this->pending_product_ids ),
				'guide_post_ids'   => array_values( $this->pending_guide_post_ids ),
			)
		);
		if ( ! self::pending_state_has_work( $pending ) ) {
			return true;
		}

		for ( $attempt = 0; $attempt < self::MAX_PENDING_CAS_ATTEMPTS; ++$attempt ) {
			$snapshot = $this->pending_option_snapshot();
			$current  = self::normalize_pending_state( $snapshot['value'] );
			if ( self::pending_state_contains( $current, $pending ) ) {
				$this->adopt_persisted_pending_state( $current );
				return true;
			}

			$merged          = self::merge_pending_states( $current, $pending );
			$merged['token'] = $this->next_pending_token();
			$payload         = array(
				'version'           => 1,
				'token'             => $merged['token'],
				'full_rescan'       => $merged['full_rescan'],
				'term_ids'          => $merged['term_ids'],
				'term_taxonomy_ids' => $merged['term_taxonomy_ids'],
					'product_ids'       => $merged['product_ids'],
					'guide_post_ids'    => $merged['guide_post_ids'],
			);

			if ( $this->compare_and_swap_pending_option( $snapshot, $payload ) ) {
				$stored = self::normalize_pending_state( $payload );
				$this->adopt_persisted_pending_state( $stored );
				return true;
			}
		}

		return false;
	}

	/** Deletes only the durable generation this request demonstrably processed. */
	private function clear_persisted_pending_changes(): void {
		if ( ! $this->durable_pending_loaded ) {
			return;
		}

		$snapshot = $this->pending_option_snapshot();
		$current  = self::normalize_pending_state( $snapshot['value'] );
		if ( ! self::pending_state_has_work( $current ) ) {
			if ( $snapshot['exists'] ) {
				$this->compare_and_delete_pending_option( $snapshot );
			}
			$this->durable_pending_token = '';
			return;
		}
		if ( ! hash_equals( $this->durable_pending_token, $current['token'] ) ) {
			return;
		}

		if ( $this->compare_and_delete_pending_option( $snapshot ) ) {
			$this->durable_pending_token = '';
		}
	}

	/**
	 * Reads one option generation with a distinct missing-value sentinel.
	 *
	 * @return array{exists:bool,value:mixed}
	 */
	private function pending_option_snapshot(): array {
		$missing = new \stdClass();
		$value   = get_option( self::PENDING_CHANGES_OPTION, $missing );

		return array(
			'exists' => $missing !== $value,
			'value'  => $missing === $value ? null : $value,
		);
	}

	/**
	 * Atomically inserts or replaces exactly the option generation that was read.
	 *
	 * @param array{exists:bool,value:mixed} $snapshot Expected generation.
	 * @param array<string,mixed>            $payload  New normalized generation.
	 */
	private function compare_and_swap_pending_option( array $snapshot, array $payload ): bool {
		$database = $this->database();
		if ( null === $database || ! isset( $database->options ) ) {
			return false;
		}

		$serialized = self::serialize_option_value( $payload );
		if ( $snapshot['exists'] ) {
			if ( ! method_exists( $database, 'update' ) ) {
				return false;
			}
			$result = $database->update(
				(string) $database->options,
				array(
					'option_value' => $serialized,
					'autoload'     => 'no',
				),
				array(
					'option_name'  => self::PENDING_CHANGES_OPTION,
					'option_value' => self::serialize_option_value( $snapshot['value'] ),
				),
				array( '%s', '%s' ),
				array( '%s', '%s' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap prevents lost concurrent retry generations.
		} else {
			if ( ! method_exists( $database, 'insert' ) ) {
				return false;
			}
			$result = $database->insert(
				(string) $database->options,
				array(
					'option_name'  => self::PENDING_CHANGES_OPTION,
					'option_value' => $serialized,
					'autoload'     => 'no',
				),
				array( '%s', '%s', '%s' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The unique option_name key makes the initial generation atomic.
		}

		$this->invalidate_pending_option_cache();

		return 1 === $result;
	}

	/**
	 * Atomically deletes exactly the option generation that this request processed.
	 *
	 * @param array{exists:bool,value:mixed} $snapshot Expected generation.
	 */
	private function compare_and_delete_pending_option( array $snapshot ): bool {
		$database = $this->database();
		if ( ! $snapshot['exists'] || null === $database || ! isset( $database->options ) || ! method_exists( $database, 'delete' ) ) {
			return false;
		}

		$result = $database->delete(
			(string) $database->options,
			array(
				'option_name'  => self::PENDING_CHANGES_OPTION,
				'option_value' => self::serialize_option_value( $snapshot['value'] ),
			),
			array( '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete preserves a concurrently written generation.

		$this->invalidate_pending_option_cache();

		return 1 === $result;
	}

	/**
	 * Invalidates the non-autoload option and its negative-cache marker.
	 *
	 * `alloptions` is deliberately left alone: this option is written with
	 * `autoload = 'no'`, so the autoloaded blob cannot contain it. Evicting the
	 * blob forced every autoloaded option of the site to be rebuilt in order to
	 * invalidate a row that was never in it.
	 */
	private function invalidate_pending_option_cache(): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( self::PENDING_CHANGES_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** Merges a confirmed durable generation back into request-local work. */
	private function adopt_persisted_pending_state( array $stored ): void {
		$this->durable_pending_loaded = true;
		$this->durable_pending_token  = $stored['token'];
		$this->pending_full_rescan    = $this->pending_full_rescan || $stored['full_rescan'];
		$this->queue_term_ids( $stored['term_ids'] );
		$this->queue_term_taxonomy_ids( $stored['term_taxonomy_ids'] );
		$this->queue_product_ids( $stored['product_ids'] );
		$this->queue_guide_post_ids( $stored['guide_post_ids'] );
	}

	/**
	 * Normalizes the owned retry option and collapses oversized payloads to a scan.
	 *
	 * @param mixed $raw Untrusted option value.
	 * @return array{token:string,full_rescan:bool,term_ids:array<int,int>,term_taxonomy_ids:array<int,int>,product_ids:array<int,int>,guide_post_ids:array<int,int>}
	 */
	private static function normalize_pending_state( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$token = is_string( $raw['token'] ?? null ) ? trim( $raw['token'] ) : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9._-]{1,64}$/D', $token ) ) {
			$token = '';
		}

		$state = array(
			'token'             => $token,
			'full_rescan'       => true === ( $raw['full_rescan'] ?? false ),
			'term_ids'          => self::normalize_ids( is_array( $raw['term_ids'] ?? null ) ? $raw['term_ids'] : array() ),
			'term_taxonomy_ids' => self::normalize_ids( is_array( $raw['term_taxonomy_ids'] ?? null ) ? $raw['term_taxonomy_ids'] : array() ),
			'product_ids'       => self::normalize_ids( is_array( $raw['product_ids'] ?? null ) ? $raw['product_ids'] : array() ),
			'guide_post_ids'    => self::normalize_ids( is_array( $raw['guide_post_ids'] ?? null ) ? $raw['guide_post_ids'] : array() ),
		);

		$total = count( $state['term_ids'] ) + count( $state['term_taxonomy_ids'] ) + count( $state['product_ids'] ) + count( $state['guide_post_ids'] );
		if ( $state['full_rescan'] || $total > self::MAX_PENDING_IDS ) {
			$state['full_rescan']       = true;
			$state['term_ids']          = array();
			$state['term_taxonomy_ids'] = array();
			$state['product_ids']       = array();
			$state['guide_post_ids']    = array();
		}

		return $state;
	}

	/**
	 * Merges two normalized retry generations without dropping either request.
	 *
	 * @param array{token:string,full_rescan:bool,term_ids:array<int,int>,term_taxonomy_ids:array<int,int>,product_ids:array<int,int>,guide_post_ids:array<int,int>} $left  Existing state.
	 * @param array{token:string,full_rescan:bool,term_ids:array<int,int>,term_taxonomy_ids:array<int,int>,product_ids:array<int,int>,guide_post_ids:array<int,int>} $right New state.
	 * @return array{token:string,full_rescan:bool,term_ids:array<int,int>,term_taxonomy_ids:array<int,int>,product_ids:array<int,int>,guide_post_ids:array<int,int>}
	 */
	private static function merge_pending_states( array $left, array $right ): array {
		return self::normalize_pending_state(
			array(
				'full_rescan'       => $left['full_rescan'] || $right['full_rescan'],
				'term_ids'          => array_merge( $left['term_ids'], $right['term_ids'] ),
				'term_taxonomy_ids' => array_merge( $left['term_taxonomy_ids'], $right['term_taxonomy_ids'] ),
				'product_ids'       => array_merge( $left['product_ids'], $right['product_ids'] ),
				'guide_post_ids'    => array_merge( $left['guide_post_ids'], $right['guide_post_ids'] ),
			)
		);
	}

	/**
	 * Checks whether a durable generation already covers every local retry item.
	 *
	 * @param array{token:string,full_rescan:bool,term_ids:array<int,int>,term_taxonomy_ids:array<int,int>,product_ids:array<int,int>,guide_post_ids:array<int,int>} $container Stored state.
	 * @param array{token:string,full_rescan:bool,term_ids:array<int,int>,term_taxonomy_ids:array<int,int>,product_ids:array<int,int>,guide_post_ids:array<int,int>} $required  Local state.
	 */
	private static function pending_state_contains( array $container, array $required ): bool {
		if ( $container['full_rescan'] ) {
			return true;
		}
		if ( $required['full_rescan'] ) {
			return false;
		}

		return array() === array_diff( $required['term_ids'], $container['term_ids'] )
			&& array() === array_diff( $required['term_taxonomy_ids'], $container['term_taxonomy_ids'] )
			&& array() === array_diff( $required['product_ids'], $container['product_ids'] )
			&& array() === array_diff( $required['guide_post_ids'], $container['guide_post_ids'] );
	}

	/**
	 * Determines whether a normalized retry payload contains actionable work.
	 *
	 * @param array{token:string,full_rescan:bool,term_ids:array<int,int>,term_taxonomy_ids:array<int,int>,product_ids:array<int,int>,guide_post_ids:array<int,int>} $state Retry state.
	 */
	private static function pending_state_has_work( array $state ): bool {
		return $state['full_rescan']
			|| array() !== $state['term_ids']
			|| array() !== $state['term_taxonomy_ids']
			|| array() !== $state['product_ids']
			|| array() !== $state['guide_post_ids'];
	}

	/** Creates a bounded generation token for compare-before-delete cleanup. */
	private function next_pending_token(): string {
		$token = trim( (string) ( $this->pending_token_generator )() );
		if ( 1 === preg_match( '/^[A-Za-z0-9._-]{1,64}$/D', $token ) ) {
			return $token;
		}

		return hash( 'sha256', $token . '|' . $this->now_gmt() );
	}

	/** Serializes an option value with WordPress-compatible scalar handling. */
	private static function serialize_option_value( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return serialize( $value );
		}
		if ( true === $value ) {
			return '1';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/** Returns the injected wpdb-compatible storage or the WordPress global. */
	private function database(): ?object {
		if ( null !== $this->database ) {
			return $this->database;
		}

		global $wpdb;

		return isset( $wpdb ) && is_object( $wpdb ) ? $wpdb : null;
	}

	/**
	 * Loads and primes every series term once for request-wide batch processing.
	 *
	 * @return array<int,object>|null Terms keyed by term ID, or null on read failure.
	 */
	private function all_series_terms(): ?array {
		if ( null !== $this->series_terms && null !== $this->term_ids_by_term_taxonomy_id ) {
			return $this->series_terms;
		}

		if ( ! function_exists( 'get_terms' ) ) {
			return null;
		}

		$loaded = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);
		$database = $this->database();
		if (
			! is_array( $loaded )
			|| (
				null !== $database
				&& (
					( isset( $database->last_error ) && '' !== trim( (string) $database->last_error ) )
					|| ( isset( $database->ready ) && ! (bool) $database->ready )
				)
			)
		) {
			return null;
		}

		$terms       = array();
		$term_id_map = array();
		foreach ( $loaded as $term ) {
			if ( ! is_object( $term ) || ! isset( $term->term_id, $term->taxonomy ) || self::TAXONOMY !== (string) $term->taxonomy ) {
				continue;
			}

			$term_id          = self::normalize_term_id( $term->term_id );
			$term_taxonomy_id = self::term_taxonomy_id( $term );
			if ( $term_id < 1 || $term_taxonomy_id < 1 ) {
				continue;
			}

			$terms[ $term_id ]                = $term;
			$term_id_map[ $term_taxonomy_id ] = $term_id;
		}

		$this->series_terms                 = $terms;
		$this->term_ids_by_term_taxonomy_id = $term_id_map;
		$this->catalog_eligibility_primed   = false;

		if ( array() !== $terms && function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'term', array_keys( $terms ) );
		}

		return $this->series_terms;
	}

	/** Requests all series counts in one aggregate query and reuses the service cache. */
	private function prime_catalog_eligibility(): void {
		if ( $this->catalog_eligibility_primed ) {
			return;
		}

		$terms = $this->all_series_terms();
		if ( null === $terms ) {
			return;
		}

		$term_taxonomy_ids = array();
		foreach ( $terms as $term ) {
			$term_taxonomy_id = self::term_taxonomy_id( $term );
			if ( $term_taxonomy_id > 0 ) {
				$term_taxonomy_ids[ $term_taxonomy_id ] = $term_taxonomy_id;
			}
		}

		$this->catalog_eligibility->counts( array_values( $term_taxonomy_ids ) );
		$this->catalog_eligibility_primed = true;
	}

	/** Loads the stable author/editorial identity of every public series in one query. */
	private function prime_catalog_editorial_state(): void {
		if ( null !== $this->catalog_editorial_state ) {
			return;
		}

		$terms = $this->all_series_terms();
		if ( null === $terms ) {
			return;
		}

		$state                  = array();
		$term_taxonomy_ids      = array();
		foreach ( $terms as $term ) {
			$term_taxonomy_id = self::term_taxonomy_id( $term );
			if ( $term_taxonomy_id < 1 ) {
				continue;
			}
			$term_taxonomy_ids[]            = $term_taxonomy_id;
			$state[ $term_taxonomy_id ] = array(
				'autores'     => array(),
				'editoriales' => array(),
				'productos'   => array(),
			);
		}

		if ( array() === $term_taxonomy_ids ) {
			$this->catalog_editorial_state          = $state;
			$this->catalog_editorial_state_reliable = true;
			return;
		}

		$database = $this->database();
		if (
			null === $database
			|| ! method_exists( $database, 'prepare' )
			|| ! method_exists( $database, 'get_results' )
			|| ! isset( $database->posts, $database->postmeta, $database->terms, $database->term_taxonomy, $database->term_relationships )
		) {
			return;
		}

		$id_placeholders = implode( ', ', array_fill( 0, count( $term_taxonomy_ids ), '%d' ) );
		$posts_table       = (string) $database->posts;
		$postmeta_table    = (string) $database->postmeta;
		$terms_table       = (string) $database->terms;
		$taxonomy_table    = (string) $database->term_taxonomy;
		$relationships     = (string) $database->term_relationships;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from wpdb and the placeholder list contains only generated `%d` tokens.
		$query = $database->prepare(
			"/* akb_series_editorial_state */
			SELECT DISTINCT
				series_relationship.term_taxonomy_id AS series_term_taxonomy_id,
				facet_taxonomy.taxonomy AS facet_taxonomy,
				facet.term_id AS facet_term_id,
				facet.name AS facet_name,
				facet.slug AS facet_slug,
				product.ID AS product_id,
				product.post_title AS product_title,
				product.post_name AS product_slug,
				COALESCE(product_thumbnail.thumbnail_id, 0) AS product_thumbnail_id
			FROM {$relationships} series_relationship
			INNER JOIN {$taxonomy_table} series_taxonomy
				ON series_taxonomy.term_taxonomy_id = series_relationship.term_taxonomy_id
				AND series_taxonomy.taxonomy = %s
			INNER JOIN {$posts_table} product
				ON product.ID = series_relationship.object_id
				AND product.post_type = %s
				AND product.post_status = %s
			LEFT JOIN {$relationships} facet_relationship
				ON facet_relationship.object_id = product.ID
			LEFT JOIN {$taxonomy_table} facet_taxonomy
				ON facet_taxonomy.term_taxonomy_id = facet_relationship.term_taxonomy_id
				AND facet_taxonomy.taxonomy IN (%s, %s)
			LEFT JOIN {$terms_table} facet
				ON facet.term_id = facet_taxonomy.term_id
			LEFT JOIN (
				SELECT post_id, MAX(CAST(meta_value AS UNSIGNED)) AS thumbnail_id
				FROM {$postmeta_table}
				WHERE meta_key = %s
				GROUP BY post_id
			) product_thumbnail
				ON product_thumbnail.post_id = product.ID
			WHERE series_relationship.term_taxonomy_id IN ({$id_placeholders})
				AND NOT EXISTS (
					SELECT 1
					FROM {$relationships} hidden_relationship
					INNER JOIN {$taxonomy_table} hidden_taxonomy
						ON hidden_taxonomy.term_taxonomy_id = hidden_relationship.term_taxonomy_id
					INNER JOIN {$terms_table} hidden_term
						ON hidden_term.term_id = hidden_taxonomy.term_id
					WHERE hidden_relationship.object_id = product.ID
						AND hidden_taxonomy.taxonomy = %s
						AND hidden_term.slug = %s
				)",
			...array_merge(
				array( self::TAXONOMY, 'product', 'publish', self::BRAND_TAXONOMY, self::AUTHOR_TAXONOMY, self::THUMBNAIL_META_KEY ),
				$term_taxonomy_ids,
				array( SeriesCatalogEligibility::VISIBILITY_TAXONOMY, SeriesCatalogEligibility::HIDDEN_FROM_CATALOG )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_string( $query ) || '' === $query ) {
			return;
		}

		$rows = $database->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One request-local aggregate; query was prepared above.
		if (
			! is_array( $rows )
			|| ( isset( $database->last_error ) && '' !== trim( (string) $database->last_error ) )
			|| ( isset( $database->ready ) && ! (bool) $database->ready )
			|| ( isset( $database->last_query ) && (string) $database->last_query !== $query )
		) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$series_term_taxonomy_id = self::normalize_term_id( $row['series_term_taxonomy_id'] ?? 0 );
			$facet_term_id            = self::normalize_term_id( $row['facet_term_id'] ?? 0 );
			$product_id               = self::normalize_term_id( $row['product_id'] ?? 0 );
			$facet_taxonomy           = (string) ( $row['facet_taxonomy'] ?? '' );
			if ( ! isset( $state[ $series_term_taxonomy_id ] ) ) {
				continue;
			}
			if ( $product_id > 0 ) {
				$state[ $series_term_taxonomy_id ]['productos'][ $product_id ] = array(
					'product_id'   => $product_id,
					'title'        => (string) ( $row['product_title'] ?? '' ),
					'slug'         => (string) ( $row['product_slug'] ?? '' ),
					'thumbnail_id' => self::normalize_term_id( $row['product_thumbnail_id'] ?? 0 ),
				);
			}
			if ( $facet_term_id < 1 ) {
				continue;
			}

			$key = self::AUTHOR_TAXONOMY === $facet_taxonomy ? 'autores' : ( self::BRAND_TAXONOMY === $facet_taxonomy ? 'editoriales' : '' );
			if ( '' === $key ) {
				continue;
			}

			$state[ $series_term_taxonomy_id ][ $key ][ $facet_term_id ] = array(
				'term_id' => $facet_term_id,
				'name'    => (string) ( $row['facet_name'] ?? '' ),
				'slug'    => (string) ( $row['facet_slug'] ?? '' ),
			);
		}

		foreach ( $state as $term_taxonomy_id => $facets ) {
			ksort( $facets['autores'], SORT_NUMERIC );
			ksort( $facets['editoriales'], SORT_NUMERIC );
			ksort( $facets['productos'], SORT_NUMERIC );
			$state[ $term_taxonomy_id ] = array(
				'autores'     => array_values( $facets['autores'] ),
				'editoriales' => array_values( $facets['editoriales'] ),
				'productos'   => array_values( $facets['productos'] ),
			);
		}

		$this->catalog_editorial_state          = $state;
		$this->catalog_editorial_state_reliable = true;
	}

	/** Clears a request-local aggregate after product or facet relationships change. */
	private function reset_catalog_editorial_state(): void {
		$this->catalog_editorial_state          = null;
		$this->catalog_editorial_state_reliable = false;
	}

	/** Loads every selected guide post in one reliable query. */
	private function prime_related_guide_state(): void {
		if ( null !== $this->related_guide_state ) {
			return;
		}

		$terms = $this->all_series_terms();
		if ( null === $terms ) {
			return;
		}

		$guide_post_ids = array();
		foreach ( array_keys( $terms ) as $term_id ) {
			$post_id = self::normalize_term_id( get_term_meta( $term_id, self::RELATED_GUIDE_META_KEY, true ) );
			if ( $post_id > 0 ) {
				$guide_post_ids[ $post_id ] = $post_id;
			}
		}

		$this->related_guide_state = array();
		if ( array() === $guide_post_ids ) {
			$this->related_guide_state_reliable = true;
			return;
		}

		$database = $this->database();
		if ( null === $database || ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'get_results' ) || ! isset( $database->posts ) ) {
			$this->related_guide_state = null;
			return;
		}

		$id_placeholders = implode( ', ', array_fill( 0, count( $guide_post_ids ), '%d' ) );
		$posts_table     = (string) $database->posts;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from wpdb and the placeholder list contains only generated `%d` tokens.
		$query = $database->prepare(
			"/* akb_series_guide_state */
			SELECT ID, post_type, post_status, post_title, post_name
			FROM {$posts_table}
			WHERE ID IN ({$id_placeholders})",
			...array_values( $guide_post_ids )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_string( $query ) || '' === $query ) {
			$this->related_guide_state = null;
			return;
		}

		$rows = $database->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One request-local aggregate; query was prepared above.
		if (
			! is_array( $rows )
			|| ( isset( $database->last_error ) && '' !== trim( (string) $database->last_error ) )
			|| ( isset( $database->ready ) && ! (bool) $database->ready )
			|| ( isset( $database->last_query ) && (string) $database->last_query !== $query )
		) {
			$this->related_guide_state = null;
			return;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$post_id = self::normalize_term_id( $row['ID'] ?? 0 );
			if ( ! isset( $guide_post_ids[ $post_id ] ) ) {
				continue;
			}
			$this->related_guide_state[ $post_id ] = array(
				'post_id'   => $post_id,
				'post_type' => (string) ( $row['post_type'] ?? '' ),
				'status'    => (string) ( $row['post_status'] ?? '' ),
				'title'     => (string) ( $row['post_title'] ?? '' ),
				'slug'      => (string) ( $row['post_name'] ?? '' ),
			);
		}

		$this->related_guide_state_reliable = true;
	}

	/** Clears the selected-guide snapshot after guide or relation changes. */
	private function reset_related_guide_state(): void {
		$this->related_guide_state          = null;
		$this->related_guide_state_reliable = false;
	}

	/**
	 * Returns a validated series term.
	 *
	 * @param int $term_id Term ID.
	 */
	private function series_term( int $term_id ): ?object {
		if ( $term_id < 1 ) {
			return null;
		}
		if ( null !== $this->series_terms ) {
			return $this->series_terms[ $term_id ] ?? null;
		}
		if ( ! function_exists( 'get_term' ) ) {
			return null;
		}

		$term = get_term( $term_id, self::TAXONOMY );

		return is_object( $term )
			&& isset( $term->term_id, $term->taxonomy )
			&& self::TAXONOMY === (string) $term->taxonomy
			? $term
			: null;
	}

	/**
	 * Hashes only durable public fields in a fixed key order.
	 *
	 * @param object $term Series term.
	 */
	private function term_fingerprint( object $term ): string {
		$meta = array();
		foreach ( self::WATCHED_META_KEYS as $meta_key ) {
			if ( self::RELATED_EDITIONS_META_KEY === $meta_key ) {
				continue;
			}
			$meta[ $meta_key ] = get_term_meta( (int) $term->term_id, $meta_key, true );
		}

		$payload = wp_json_encode(
			array(
				'name'              => (string) ( $term->name ?? '' ),
				'slug'              => (string) ( $term->slug ?? '' ),
				'description'       => (string) ( $term->description ?? '' ),
				'catalog_count'     => $this->term_catalog_count( $term ),
				'catalog_editorial' => $this->term_catalog_editorial_state( $term ),
				'related_guide'      => $this->term_related_guide_state( (int) $term->term_id ),
				'meta'              => $meta,
				'related_editions'  => $this->public_related_edition_state( (int) $term->term_id ),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return hash( 'sha256', is_string( $payload ) ? $payload : '' );
	}

	/**
	 * Returns the exact ordered peer data that can affect related-edition HTML.
	 *
	 * @param int $term_id Owner series term ID.
	 * @return array<int,array{term_id:int,name:string,slug:string}>
	 */
	private function public_related_edition_state( int $term_id ): array {
		$state = array();
		foreach ( $this->related_edition_ids( $term_id ) as $related_id ) {
			$related = $this->series_term( $related_id );
			if ( null === $related ) {
				continue;
			}

			if ( ! $this->term_is_catalog_eligible( $related ) || $this->term_is_noindex( $related_id ) ) {
				continue;
			}

			$state[] = array(
				'term_id' => $related_id,
				'name'    => (string) ( $related->name ?? '' ),
				'slug'    => (string) ( $related->slug ?? '' ),
			);
		}

		return $state;
	}

	/**
	 * Returns owners that reference a peer, including one-way historical drift.
	 *
	 * @param int $peer_id Related series term ID.
	 * @return array<int,int>
	 */
	private function related_dependent_term_ids( int $peer_id ): array {
		if ( $peer_id < 1 ) {
			return array();
		}

		if ( null === $this->related_dependents ) {
			$this->related_dependents = $this->build_related_dependents();
		}

		return $this->related_dependents[ $peer_id ] ?? array();
	}

	/**
	 * Builds a reverse graph from every saved outgoing related-edition edge.
	 *
	 * @return array<int,array<int,int>>
	 */
	private function build_related_dependents(): array {
		$terms = $this->all_series_terms();
		if ( null === $terms ) {
			return array();
		}

		$term_ids = array_keys( $terms );
		if ( array() === $term_ids ) {
			return array();
		}

		$dependents = array();
		foreach ( $term_ids as $owner_id ) {
			foreach ( $this->related_edition_ids( $owner_id ) as $peer_id ) {
				$dependents[ $peer_id ][ $owner_id ] = $owner_id;
			}
		}

		foreach ( $dependents as $peer_id => $owner_ids ) {
			$dependents[ $peer_id ] = array_values( $owner_ids );
		}

		return $dependents;
	}

	/**
	 * Normalizes saved peer IDs with the same public contract as SeriesEditions.
	 *
	 * @param int $term_id Owner series term ID.
	 * @return array<int,int>
	 */
	private function related_edition_ids( int $term_id ): array {
		$raw = get_term_meta( $term_id, self::RELATED_EDITIONS_META_KEY, true );
		if ( ! is_array( $raw ) ) {
			$raw = is_numeric( $raw ) ? array( $raw ) : array();
		}

		$ids = array();
		foreach ( $raw as $candidate ) {
			$id = self::normalize_term_id( $candidate );
			if ( $id > 0 && $id !== $term_id ) {
				$ids[ $id ] = $id;
			}
		}

		return array_slice( array_values( $ids ), 0, self::MAX_RELATED_EDITIONS );
	}

	/**
	 * Mirrors the indexability gate used by the public related-edition renderer.
	 *
	 * @param int $term_id Series term ID.
	 */
	private function term_is_noindex( int $term_id ): bool {
		return ! RobotsMeta::is_indexable( get_term_meta( $term_id, self::ROBOTS_META_KEY, true ) );
	}

	/** Determines whether the term has at least one published visible product. */
	private function term_is_catalog_eligible( object $term ): bool {
		return $this->term_catalog_count( $term ) > 0;
	}

	/** Returns the published catalogue-visible product count for one series. */
	private function term_catalog_count( object $term ): int {
		$term_taxonomy_id = self::term_taxonomy_id( $term );

		return $term_taxonomy_id > 0 ? $this->catalog_eligibility->count( $term_taxonomy_id ) : 0;
	}

	/**
	 * Returns stable catalogue-derived author/editorial identities for one series.
	 *
	 * @return array{autores:array<int,array{term_id:int,name:string,slug:string}>,editoriales:array<int,array{term_id:int,name:string,slug:string}>,productos:array<int,array{product_id:int,title:string,slug:string,thumbnail_id:int}>}
	 */
	private function term_catalog_editorial_state( object $term ): array {
		$this->prime_catalog_editorial_state();
		$empty = array(
			'autores'     => array(),
			'editoriales' => array(),
			'productos'   => array(),
		);
		$term_taxonomy_id = self::term_taxonomy_id( $term );

		return $term_taxonomy_id > 0 && null !== $this->catalog_editorial_state
			? ( $this->catalog_editorial_state[ $term_taxonomy_id ] ?? $empty )
			: $empty;
	}

	/** Returns only the guide-post identity that can alter the public block. */
	private function term_related_guide_state( int $term_id ): array {
		$post_id = self::normalize_term_id( get_term_meta( $term_id, self::RELATED_GUIDE_META_KEY, true ) );
		if ( $post_id < 1 ) {
			return array();
		}

		$this->prime_related_guide_state();
		$guide = null === $this->related_guide_state ? null : ( $this->related_guide_state[ $post_id ] ?? null );
		if ( ! is_array( $guide ) ) {
			return array(
				'post_id' => $post_id,
				'status'  => 'unavailable',
			);
		}

		$is_public = 'post' === $guide['post_type'] && 'publish' === $guide['status'];
		$state     = array(
			'post_id' => $post_id,
			'status'  => $is_public ? 'publish' : 'unavailable',
		);
		if ( $is_public ) {
			$state['title'] = $guide['title'];
			$state['slug']  = $guide['slug'];
		}

		return $state;
	}

	/** Returns a term's positive term-taxonomy ID. */
	private static function term_taxonomy_id( object $term ): int {
		return self::normalize_term_id( $term->term_taxonomy_id ?? 0 );
	}

	/**
	 * Normalizes and deduplicates positive scalar IDs in input order.
	 *
	 * @param array<int,mixed> $candidates Candidate IDs.
	 * @return array<int,int>
	 */
	private static function normalize_ids( array $candidates ): array {
		$ids = array();
		foreach ( $candidates as $candidate ) {
			$id = self::normalize_term_id( $candidate );
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Converts a scalar term reference into a positive integer candidate.
	 *
	 * @param mixed $candidate Candidate term ID.
	 */
	private static function normalize_term_id( $candidate ): int {
		return is_scalar( $candidate ) ? abs( (int) $candidate ) : 0;
	}

	/**
	 * Reads the current renderer state without manufacturing a request date.
	 *
	 * @return array<string,mixed>
	 */
	private function renderer_state(): array {
		$state = get_option( self::RENDERER_STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Returns the activation date only for this exact renderer version.
	 */
	private function renderer_modified_gmt(): string {
		$state = $this->renderer_state();
		if ( (string) ( $state['version'] ?? '' ) !== $this->renderer_version ) {
			return '';
		}

		return self::normalize_gmt_datetime( $state['modified_gmt'] ?? '' );
	}

	/**
	 * Formats the injected UTC clock once per real mutation.
	 */
	private function now_gmt(): string {
		return gmdate( 'Y-m-d H:i:s', max( 1, (int) ( $this->clock )() ) );
	}

	/**
	 * Chooses the newest valid stable date.
	 *
	 * @param array<int,mixed> $values Candidate dates.
	 */
	private static function latest_gmt_datetime( array $values ): string {
		$valid = array();
		foreach ( $values as $value ) {
			$normalized = self::normalize_gmt_datetime( $value );
			if ( '' !== $normalized ) {
				$valid[] = $normalized;
			}
		}

		return empty( $valid ) ? '' : max( $valid );
	}

	/**
	 * Accepts only an exact, real UTC MySQL datetime.
	 *
	 * @param mixed $value Candidate value.
	 */
	private static function normalize_gmt_datetime( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $value, $parts ) ) {
			return '';
		}

		$valid = checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] )
			&& (int) $parts[4] < 24
			&& (int) $parts[5] < 60
			&& (int) $parts[6] < 60;

		return $valid ? $value : '';
	}
}
