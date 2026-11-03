<?php
/**
 * Tests for stable `pa_serie` sitemap modification dates.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeoIndexing;

use Akibara\Business\Modules\SeoIndexing\SeriesSitemapLastmod;
use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\SeriesSchema\SeriesSchemaType;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/** wpdb-compatible mutable count source for the real eligibility service. */
final class SeriesSitemapEligibilityWpdb {

	public string $posts              = 'wp_posts';
	public string $postmeta           = 'wp_postmeta';
	public string $terms              = 'wp_terms';
	public string $term_taxonomy      = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $options            = 'wp_options';

	public string $last_error = '';

	public bool $ready = true;

	/** @var array<int,int> */
	public array $counts = array();

	/** @var string[] */
	public array $queries = array();

	/** @var string[] */
	public array $editorial_queries = array();

	/** @var array<int,array<int,array<string,mixed>>> */
	public array $editorial_rows = array();

	/** @var string[] */
	public array $guide_queries = array();

	/** @var array<int,array<string,mixed>> */
	public array $guide_rows = array();

	public int $default_count = 1;

	public bool $fail = false;

	public bool $fail_editorial = false;

	public bool $editorial_empty_database_error = false;

	public bool $fail_guide = false;

	public bool $guide_empty_database_error = false;

	/** @var \Closure(string,array<string,mixed>,array<int,string>):int|false|null */
	public ?\Closure $option_insert = null;

	/** @var \Closure(string,array<string,mixed>,array<string,mixed>,array<int,string>,array<int,string>):int|false|null */
	public ?\Closure $option_update = null;

	/** @var \Closure(string,array<string,mixed>,array<int,string>):int|false|null */
	public ?\Closure $option_delete = null;

	public ?\Closure $before_option_insert = null;

	public ?\Closure $before_option_update = null;

	public ?\Closure $before_option_delete = null;

	/** @var array<int,int> */
	private array $requested_ids = array();

	/** Records a prepared aggregate query. */
	public function prepare( string $query, ...$args ): string {
		$this->requested_ids = array_values( array_filter( $args, 'is_int' ) );
		return $query;
	}

	/** @return array<int,array{term_taxonomy_id:int,product_count:int}>|null */
	public function get_results( string $query, string $output = 'OBJECT' ) {
		unset( $output );
		$is_editorial = false !== strpos( $query, 'akb_series_editorial_state' );
		$is_guide     = false !== strpos( $query, 'akb_series_guide_state' );
		if ( $is_editorial ) {
			$this->editorial_queries[] = $query;
		} elseif ( $is_guide ) {
			$this->guide_queries[] = $query;
		} else {
			$this->queries[] = $query;
		}
		if ( $this->fail || ( $is_editorial && $this->fail_editorial ) || ( $is_guide && $this->fail_guide ) ) {
			return null;
		}
		if ( $is_editorial && $this->editorial_empty_database_error ) {
			$this->last_error = 'Simulated editorial aggregate failure.';
			return array();
		}
		if ( $is_guide && $this->guide_empty_database_error ) {
			$this->last_error = 'Simulated guide aggregate failure.';
			return array();
		}
		$this->last_error = '';
		if ( $is_editorial ) {
			$rows = array();
			foreach ( $this->requested_ids as $term_taxonomy_id ) {
				foreach ( $this->editorial_rows[ $term_taxonomy_id ] ?? array() as $row ) {
					$rows[] = $row;
				}
			}

			return $rows;
		}
		if ( $is_guide ) {
			$rows = array();
			foreach ( $this->requested_ids as $post_id ) {
				if ( isset( $this->guide_rows[ $post_id ] ) ) {
					$rows[] = $this->guide_rows[ $post_id ];
				}
			}

			return $rows;
		}

		$rows = array();
		foreach ( $this->requested_ids as $term_taxonomy_id ) {
			$rows[] = array(
				'term_taxonomy_id' => $term_taxonomy_id,
				'product_count'     => $this->counts[ $term_taxonomy_id ] ?? $this->default_count,
			);
		}

		return $rows;
	}

	/** Simulates wpdb::insert() for the owned pending option. */
	public function insert( string $table, array $data, array $format ) {
		$before                    = $this->before_option_insert;
		$this->before_option_insert = null;
		if ( null !== $before ) {
			$before();
		}

		return null === $this->option_insert ? false : ( $this->option_insert )( $table, $data, $format );
	}

	/** Simulates wpdb::update() for compare-and-swap. */
	public function update( string $table, array $data, array $where, array $format, array $where_format ) {
		$before                    = $this->before_option_update;
		$this->before_option_update = null;
		if ( null !== $before ) {
			$before();
		}

		return null === $this->option_update ? false : ( $this->option_update )( $table, $data, $where, $format, $where_format );
	}

	/** Simulates wpdb::delete() for compare-and-delete. */
	public function delete( string $table, array $where, array $where_format ) {
		$before                    = $this->before_option_delete;
		$this->before_option_delete = null;
		if ( null !== $before ) {
			$before();
		}

		return null === $this->option_delete ? false : ( $this->option_delete )( $table, $where, $where_format );
	}
}

final class SeriesSitemapLastmodTest extends TestCase {

	private const OPTION_KEY = 'akb_pa_serie_renderer_state';

	private const LASTMOD_KEY = '_akb_serie_sitemap_lastmod_gmt';

	private const FINGERPRINT_KEY = '_akb_serie_sitemap_fingerprint';

	private const PENDING_KEY = 'akb_pa_serie_sitemap_pending_changes';

	private const RELATED_EDITIONS_KEY = '_akb_serie_ediciones_relacionadas';

	private const RELATED_GUIDE_KEY = '_akb_related_guide_post_id';

	private const ROBOTS_KEY = 'rank_math_robots';

	/** @var array<string,mixed> */
	private array $options = array();

	/** @var array<int,array<string,mixed>> */
	private array $term_meta = array();

	/** @var array<int,object> */
	private array $terms = array();

	/** @var array<int,array{int,string,mixed}> */
	private array $term_writes = array();

	/** @var array<int,array{string,mixed,bool}> */
	private array $option_writes = array();

	/** @var string[] */
	private array $option_deletes = array();

	/** @var string[] */
	private array $sitemap_invalidations = array();

	/** @var array<int,int> */
	private array $term_page_invalidations = array();

	/** @var array<int,array{string,array<int,int>}> */
	private array $meta_cache_primes = array();

	private int $sitemap_flushes = 0;

	private int $now = 1786392000;

	private int $get_terms_queries = 0;

	private bool $term_query_returns_database_error = false;

	private SeriesSitemapEligibilityWpdb $eligibility_database;

	/** @var array<int,array<int,int>> */
	private array $product_series_term_taxonomy_ids = array();

	/** @var array<int,array{array<int,int>,string,array<string,string>}> */
	private array $object_term_queries = array();

	private bool $object_term_query_fails = false;

	private bool $object_term_query_returns_database_error = false;

	/** @var array<int,array<string,int>> */
	private array $term_write_failures = array();

	private int $pending_token_counter = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options       = array();
		$this->term_meta     = array();
		$this->terms         = array( 900 => $this->series_term() );
		$this->term_writes   = array();
		$this->option_writes = array();
		$this->option_deletes = array();
		$this->sitemap_invalidations = array();
		$this->term_page_invalidations = array();
		$this->meta_cache_primes      = array();
		$this->sitemap_flushes         = 0;
		$this->now           = 1786392000;
		$this->get_terms_queries = 0;
		$this->term_query_returns_database_error = false;
		$this->eligibility_database         = new SeriesSitemapEligibilityWpdb();
		$this->eligibility_database->counts = array();
		$this->eligibility_database->editorial_rows = array();
		$this->eligibility_database->editorial_queries = array();
		$this->eligibility_database->fail_editorial = false;
		$this->eligibility_database->editorial_empty_database_error = false;
		$this->eligibility_database->guide_rows = array();
		$this->eligibility_database->guide_queries = array();
		$this->eligibility_database->fail_guide = false;
		$this->eligibility_database->guide_empty_database_error = false;
		$this->product_series_term_taxonomy_ids = array();
		$this->object_term_queries               = array();
		$this->object_term_query_fails           = false;
		$this->object_term_query_returns_database_error = false;
		$this->term_write_failures               = array();
		$this->pending_token_counter             = 0;
		$this->eligibility_database->option_insert = function ( string $table, array $data, array $format ) {
			unset( $format );
			if ( 'wp_options' !== $table || array_key_exists( (string) $data['option_name'], $this->options ) ) {
				return false;
			}

			$key                   = (string) $data['option_name'];
			$value                 = $this->unserialize_option_value( (string) $data['option_value'] );
			$this->options[ $key ] = $value;
			$this->option_writes[] = array( $key, $value, false );

			return 1;
		};
		$this->eligibility_database->option_update = function ( string $table, array $data, array $where, array $format, array $where_format ) {
			unset( $format, $where_format );
			$key = (string) ( $where['option_name'] ?? '' );
			if (
				'wp_options' !== $table
				|| ! array_key_exists( $key, $this->options )
				|| $this->serialize_option_value( $this->options[ $key ] ) !== (string) ( $where['option_value'] ?? '' )
			) {
				return 0;
			}

			$value                 = $this->unserialize_option_value( (string) $data['option_value'] );
			$this->options[ $key ] = $value;
			$this->option_writes[] = array( $key, $value, false );

			return 1;
		};
		$this->eligibility_database->option_delete = function ( string $table, array $where, array $where_format ) {
			unset( $where_format );
			$key = (string) ( $where['option_name'] ?? '' );
			if (
				'wp_options' !== $table
				|| ! array_key_exists( $key, $this->options )
				|| $this->serialize_option_value( $this->options[ $key ] ) !== (string) ( $where['option_value'] ?? '' )
			) {
				return 0;
			}

			unset( $this->options[ $key ] );
			$this->option_deletes[] = $key;

			return 1;
		};

		Functions\when( 'get_option' )->alias(
			fn( string $key, $default = false ) => $this->options[ $key ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value, bool $autoload = false ): bool {
				$this->options[ $key ] = $value;
				$this->option_writes[] = array( $key, $value, $autoload );
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $key ): bool {
				$existed = array_key_exists( $key, $this->options );
				unset( $this->options[ $key ] );
				$this->option_deletes[] = $key;
				return $existed;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_terms' )->alias(
			function ( array $args ): array {
				++$this->get_terms_queries;
				if ( $this->term_query_returns_database_error ) {
					$this->eligibility_database->last_error = 'Simulated term query failure.';
					return array();
				}
				$this->eligibility_database->last_error = '';

				return 'pa_serie' === ( $args['taxonomy'] ?? '' ) ? array_values( $this->terms ) : array();
			}
		);
		Functions\when( 'get_term' )->alias(
			fn( int $term_id, string $taxonomy ) => 'pa_serie' === $taxonomy ? ( $this->terms[ $term_id ] ?? null ) : null
		);
		Functions\when( 'get_term_meta' )->alias(
			fn( int $term_id, string $key, bool $single = false ) => $this->term_meta[ $term_id ][ $key ] ?? ( $single ? '' : array() )
		);
		Functions\when( 'update_term_meta' )->alias(
			function ( int $term_id, string $key, $value ) {
				if ( ( $this->term_write_failures[ $term_id ][ $key ] ?? 0 ) > 0 ) {
					--$this->term_write_failures[ $term_id ][ $key ];
					return false;
				}
				$this->term_meta[ $term_id ][ $key ] = $value;
				$this->term_writes[]                 = array( $term_id, $key, $value );
				return count( $this->term_writes );
			}
		);
		Functions\when( 'update_meta_cache' )->alias(
			function ( string $meta_type, array $object_ids ): bool {
				$this->meta_cache_primes[] = array( $meta_type, $object_ids );
				return true;
			}
		);
		Functions\when( 'wp_get_object_terms' )->alias(
			function ( array $object_ids, string $taxonomy, array $args ) {
				$this->object_term_queries[] = array( $object_ids, $taxonomy, $args );
				if ( $this->object_term_query_fails ) {
					return new \WP_Error( 'relation_read_failed' );
				}
				if ( $this->object_term_query_returns_database_error ) {
					$this->eligibility_database->last_error = 'Simulated relation query failure.';
					return array();
				}
				$this->eligibility_database->last_error = '';
				$term_taxonomy_ids            = array();
				foreach ( $object_ids as $object_id ) {
					foreach ( $this->product_series_term_taxonomy_ids[ $object_id ] ?? array() as $term_taxonomy_id ) {
						$term_taxonomy_ids[ $term_taxonomy_id ] = $term_taxonomy_id;
					}
				}

				return array_values( $term_taxonomy_ids );
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $value, int $flags = 0 ) => json_encode( $value, $flags )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should register durable data tracking and the Rank Math sitemap filter.
	 */
	public function test_registers_all_stable_change_sources(): void {
		$sut = $this->module();

		Monkey\Actions\expectAdded( 'init' )->once()->with( array( $sut, 'handle_init_renderer_state' ), 20 );
		Monkey\Actions\expectAdded( 'created_pa_serie' )->once()->with( array( $sut, 'handle_series_term_changed' ), 100 );
		Monkey\Actions\expectAdded( 'edited_pa_serie' )->once()->with( array( $sut, 'handle_series_term_changed' ), 100 );
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			Monkey\Actions\expectAdded( $hook )
				->once()
				->with( array( $sut, 'handle_term_meta_changed' ), 100, 4 );
		}
		Monkey\Actions\expectAdded( 'clean_term_cache' )
			->once()
			->with( array( $sut, 'handle_term_cache_cleaned' ), 100, 3 );
		Monkey\Actions\expectAdded( 'set_object_terms' )
			->once()
			->with( array( $sut, 'handle_object_terms_set' ), 100, 6 );
		Monkey\Actions\expectAdded( 'deleted_term_relationships' )
			->once()
			->with( array( $sut, 'handle_term_relationships_deleted' ), 100, 3 );
		Monkey\Actions\expectAdded( 'transition_post_status' )
			->once()
			->with( array( $sut, 'handle_product_status_transition' ), 100, 3 );
		Monkey\Actions\expectAdded( 'post_updated' )
			->once()
			->with( array( $sut, 'handle_product_post_updated' ), 100, 3 );
		Monkey\Actions\expectAdded( 'before_delete_post' )
			->once()
			->with( array( $sut, 'handle_guide_before_delete' ), 100, 2 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			Monkey\Actions\expectAdded( $hook )
				->once()
				->with( array( $sut, 'handle_product_meta_changed' ), 100, 4 );
		}
		foreach ( array( 'product_brand', 'pa_autor' ) as $taxonomy ) {
			Monkey\Actions\expectAdded( 'edited_' . $taxonomy )
				->once()
				->with( array( $sut, 'handle_catalog_facet_term_changed' ), 100 );
			Monkey\Actions\expectAdded( 'delete_' . $taxonomy )
				->once()
				->with( array( $sut, 'handle_catalog_facet_term_changed' ), 100 );
		}
		Monkey\Actions\expectAdded( 'shutdown' )
			->once()
			->with( array( $sut, 'handle_shutdown_sitemap_cache_flush' ), PHP_INT_MAX );
		Monkey\Filters\expectAdded( 'rank_math/sitemap/entry' )
			->once()
			->with( array( $sut, 'handle_sitemap_entry' ), 20, 3 );
		Monkey\Filters\expectAdded( 'rank_math/sitemap/index/entry' )
			->once()
			->with( array( $sut, 'handle_sitemap_index_entry' ), 20, 3 );

		$sut->register();
		$this->addToAssertionCount( 22 );
	}

	/**
	 * @testdox Should flush an enqueued Rank Math invalidation once at late shutdown.
	 */
	public function test_flushes_a_queued_invalidation_once_at_shutdown(): void {
		$sut = $this->module();

		$sut->handle_init_renderer_state();

		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
		self::assertSame( 0, $this->sitemap_flushes, 'Invalidation must remain queued until shutdown.' );

		$sut->handle_shutdown_sitemap_cache_flush();
		self::assertSame( 1, $this->sitemap_flushes );

		$sut->handle_shutdown_sitemap_cache_flush();
		self::assertSame( 1, $this->sitemap_flushes, 'A repeated shutdown callback must be a no-op.' );
	}

	/**
	 * @testdox Should seed fingerprints and persist one non-autoload renderer date.
	 */
	public function test_bootstraps_renderer_state_once_without_faking_term_dates(): void {
		$sut = $this->module( 'renderer-v2' );

		$sut->handle_init_renderer_state();

		self::assertSame( 'renderer-v2', $this->options[ self::OPTION_KEY ]['version'] );
		self::assertSame( '2026-08-10 20:00:00', $this->options[ self::OPTION_KEY ]['modified_gmt'] );
		self::assertFalse( $this->option_writes[0][2], 'Renderer state must not autoload on every request.' );
		self::assertNotSame( '', $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertArrayNotHasKey( self::LASTMOD_KEY, $this->term_meta[900], 'Seeding is not a term modification.' );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations, 'A new renderer version must invalidate only the series sitemap.' );

		$option_writes = count( $this->option_writes );
		$term_writes   = count( $this->term_writes );
		$this->now    += 86400;
		$sut->handle_init_renderer_state();

		self::assertCount( $option_writes, $this->option_writes, 'Stable requests must not rewrite the date.' );
		self::assertCount( $term_writes, $this->term_writes, 'Stable requests must not reseed terms.' );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations, 'An idempotent init must not invalidate the sitemap again.' );
	}

	/**
	 * @testdox Should advance the global date only when the explicit renderer version changes.
	 */
	public function test_advances_the_global_date_for_a_new_renderer_version(): void {
		$this->options[ self::OPTION_KEY ] = array(
			'version'      => 'renderer-v1',
			'modified_gmt' => '2026-08-01 10:00:00',
		);

		$this->module( 'renderer-v2' )->handle_init_renderer_state();

		self::assertSame( 'renderer-v2', $this->options[ self::OPTION_KEY ]['version'] );
		self::assertSame( '2026-08-10 20:00:00', $this->options[ self::OPTION_KEY ]['modified_gmt'] );
		self::assertArrayNotHasKey( self::LASTMOD_KEY, $this->term_meta[900] );
	}

	/**
	 * @testdox Should timestamp an actual term description change but ignore a no-op save.
	 */
	public function test_records_only_actual_visible_term_changes(): void {
		$sut = $this->module();
		$sut->handle_init_renderer_state();

		$this->term_writes         = array();
		$this->now                += 3600;
		$this->terms[900]->description = 'Una descripción editorial nueva y visible.';
		$sut->handle_series_term_changed( 900 );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( '2026-08-10 21:00:00', $this->term_meta[900][ self::LASTMOD_KEY ] );
		self::assertSame( array( self::LASTMOD_KEY, self::FINGERPRINT_KEY ), array_column( $this->term_writes, 1 ) );

		$writes = count( $this->term_writes );
		$this->now += 3600;
		$sut->handle_series_term_changed( 900 );
		$sut->handle_shutdown_sitemap_cache_flush();
		self::assertCount( $writes, $this->term_writes, 'A no-op term save must preserve the prior date.' );
	}

	/**
	 * @testdox Should track publication and SEO metadata but ignore volatile stock metadata.
	 */
	public function test_tracks_only_the_durable_meta_allowlist(): void {
		$this->terms[41] = $this->series_term( 41, 'one-piece-ivrea', 'One Piece — Ivrea' );
		$this->terms[52] = $this->series_term( 52, 'one-piece-3-en-1', 'One Piece 3 en 1' );
		$this->module()->handle_init_renderer_state();
		$this->term_writes           = array();
		$this->sitemap_invalidations = array();
		$sut                         = $this->module();

		$this->term_meta[900]['_akb_serie_estado'] = 'completa';
		$sut->handle_term_meta_changed( 1, 900, '_akb_serie_estado', 'completa' );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertArrayHasKey( self::LASTMOD_KEY, $this->term_meta[900] );
		self::assertSame(
			array( 'pa_serie' ),
			$this->sitemap_invalidations,
			'A meta-only mutation must invalidate the series sitemap without an edited-term hook.'
		);
		self::assertSame( array( 900 => 900 ), $this->term_page_invalidations, 'The changed landing HTML/meta cache must be purged exactly once.' );

		$writes_after_first_change = count( $this->term_writes );
		$sut->handle_term_meta_changed( 2, 900, '_akb_serie_estado', 'completa' );
		$sut->handle_shutdown_sitemap_cache_flush();
		self::assertCount( $writes_after_first_change, $this->term_writes, 'A no-op meta callback must not rewrite tracking state.' );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations, 'A no-op must not enqueue another invalidation.' );

		$this->now += 3600;
		$this->term_meta[900][ self::RELATED_EDITIONS_KEY ] = array( 41, 52 );
		$sut->handle_term_meta_changed( 3, 900, self::RELATED_EDITIONS_KEY, array( 41, 52 ) );
		$sut->handle_shutdown_sitemap_cache_flush();
		self::assertSame(
			'2026-08-10 21:00:00',
			$this->term_meta[900][ self::LASTMOD_KEY ],
			'Changing the visible related-editions selector must advance lastmod.'
		);
		self::assertSame( array( 'pa_serie', 'pa_serie' ), $this->sitemap_invalidations, 'Separate committed requests must each invalidate the sitemap.' );
		$writes = count( $this->term_writes );

		$this->term_meta[900]['_stock'] = 2;
		$sut->handle_term_meta_changed( 4, 900, '_stock', 2 );
		$sut->handle_shutdown_sitemap_cache_flush();
		self::assertCount( $writes, $this->term_writes, 'Stock churn must not rewrite an editorial sitemap date.' );
		self::assertSame( array( 'pa_serie', 'pa_serie' ), $this->sitemap_invalidations, 'Volatile metadata must not add an editorial sitemap invalidation.' );

		$this->now += 3600;
		$this->term_meta[900][ SeriesSchemaType::META_KEY ] = SeriesSchemaType::NON_COMIC;
		$sut->handle_term_meta_changed( 5, 900, SeriesSchemaType::META_KEY, SeriesSchemaType::NON_COMIC );
		$sut->handle_shutdown_sitemap_cache_flush();
		self::assertSame( '2026-08-10 22:00:00', $this->term_meta[900][ self::LASTMOD_KEY ] );
		self::assertSame(
			array( 'pa_serie', 'pa_serie', 'pa_serie' ),
			$this->sitemap_invalidations,
			'Changing the durable schema type must invalidate the landing and series sitemap.'
		);
	}

	/**
	 * @testdox Should date a selected guide title, slug and public-status transition through one reverse term scan.
	 */
	public function test_selected_guide_identity_and_visibility_change_the_series_fingerprint(): void {
		$this->term_meta[900][ self::RELATED_GUIDE_KEY ] = 501;
		$this->eligibility_database->guide_rows[501] = $this->guide_row( 501, 'Cómo leer Tokyo Ghoul', 'como-leer-tokyo-ghoul' );
		$this->module()->handle_init_renderer_state();
		$before = $this->term_meta[900][ self::FINGERPRINT_KEY ];
		$this->eligibility_database->guide_rows[501] = $this->guide_row( 501, 'Orden para leer Tokyo Ghoul', 'orden-leer-tokyo-ghoul' );
		$this->eligibility_database->guide_queries = array();
		$this->term_writes             = array();
		$this->term_page_invalidations = array();
		$this->sitemap_invalidations   = array();
		$this->now                    += 3600;
		$sut                           = $this->module();

		$sut->handle_product_post_updated(
			501,
			(object) array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Orden para leer Tokyo Ghoul', 'post_name' => 'orden-leer-tokyo-ghoul' ),
			(object) array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Cómo leer Tokyo Ghoul', 'post_name' => 'como-leer-tokyo-ghoul' )
		);
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertNotSame( $before, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( array( 900 => 900 ), $this->term_page_invalidations );
		self::assertCount( 1, $this->eligibility_database->guide_queries );

		$before = $this->term_meta[900][ self::FINGERPRINT_KEY ];
		$this->eligibility_database->guide_rows[501] = $this->guide_row( 501, 'Orden para leer Tokyo Ghoul', 'orden-leer-tokyo-ghoul', 'draft' );
		$this->term_writes             = array();
		$this->term_page_invalidations = array();
		$this->sitemap_invalidations   = array();
		$this->now                    += 3600;
		$sut                           = $this->module();
		$sut->handle_product_status_transition( 'draft', 'publish', (object) array( 'ID' => 501, 'post_type' => 'post' ) );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertNotSame( $before, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( array( 900 => 900 ), $this->term_page_invalidations );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should remove a published guide block after hard deletion but ignore unavailable-state churn.
	 */
	public function test_selected_guide_hard_delete_tracks_only_a_public_block_change(): void {
		$this->term_meta[900][ self::RELATED_GUIDE_KEY ] = 501;
		$this->eligibility_database->guide_rows[501] = $this->guide_row( 501, 'Guía pública', 'guia-publica' );
		$this->module()->handle_init_renderer_state();
		unset( $this->eligibility_database->guide_rows[501] );
		$this->term_writes             = array();
		$this->term_page_invalidations = array();
		$this->now                    += 3600;
		$sut                           = $this->module();

		$sut->handle_guide_before_delete( 501, (object) array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( array( 900 => 900 ), $this->term_page_invalidations );

		$this->eligibility_database->guide_rows[501] = $this->guide_row( 501, 'Borrador', 'borrador', 'draft' );
		$this->module()->handle_init_renderer_state();
		unset( $this->eligibility_database->guide_rows[501] );
		$this->term_writes = array();
		$sut = $this->module();
		$sut->handle_guide_before_delete( 501, (object) array( 'post_type' => 'post', 'post_status' => 'draft' ) );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array(), $this->term_writes, 'Draft and missing both render no guide block.' );
	}

	/**
	 * @testdox Should preserve a guide change durably when the series term map fails as an empty database result.
	 */
	public function test_selected_guide_retry_survives_empty_term_query_database_error(): void {
		$this->term_meta[900][ self::RELATED_GUIDE_KEY ] = 501;
		$this->eligibility_database->guide_rows[501] = $this->guide_row( 501, 'Guía original', 'guia-original' );
		$this->module()->handle_init_renderer_state();
		$before = $this->term_meta[900][ self::FINGERPRINT_KEY ];
		$this->eligibility_database->guide_rows[501] = $this->guide_row( 501, 'Guía corregida', 'guia-corregida' );
		$this->term_query_returns_database_error = true;
		$this->term_writes                       = array();
		$this->sitemap_invalidations             = array();
		$sut                                     = $this->module();

		$sut->handle_product_post_updated(
			501,
			(object) array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Guía corregida', 'post_name' => 'guia-corregida' ),
			(object) array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Guía original', 'post_name' => 'guia-original' )
		);
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 501 ), $this->options[ self::PENDING_KEY ]['guide_post_ids'] );
		self::assertSame( $before, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertSame( array(), $this->term_writes );

		$this->term_query_returns_database_error = false;
		$retry = $this->module();
		$retry->handle_init_renderer_state();
		$retry->handle_shutdown_sitemap_cache_flush();

		self::assertNotSame( $before, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertArrayNotHasKey( self::PENDING_KEY, $this->options );
	}

	/**
	 * @testdox Should never seal a missing guide when its batch query returned empty with a database error.
	 */
	public function test_selected_guide_aggregate_database_error_retries_durably(): void {
		$this->term_meta[900][ self::RELATED_GUIDE_KEY ] = 501;
		$this->eligibility_database->guide_rows[501] = $this->guide_row( 501, 'Guía original', 'guia-original' );
		$this->module()->handle_init_renderer_state();
		$before = $this->term_meta[900][ self::FINGERPRINT_KEY ];
		$this->eligibility_database->guide_rows[501] = $this->guide_row( 501, 'Guía corregida', 'guia-corregida' );
		$this->eligibility_database->guide_empty_database_error = true;
		$this->term_writes = array();
		$sut = $this->module();

		$sut->handle_product_post_updated(
			501,
			(object) array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Guía corregida', 'post_name' => 'guia-corregida' ),
			(object) array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Guía original', 'post_name' => 'guia-original' )
		);
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( $before, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertSame( array(), $this->term_writes );
		self::assertArrayHasKey( self::PENDING_KEY, $this->options );

		$this->eligibility_database->guide_empty_database_error = false;
		$retry = $this->module();
		$retry->handle_init_renderer_state();
		$retry->handle_shutdown_sitemap_cache_flush();

		self::assertNotSame( $before, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertArrayNotHasKey( self::PENDING_KEY, $this->options );
	}

	/**
	 * @testdox Should fingerprint the visible related-edition order selected for an owner.
	 */
	public function test_tracks_the_effective_related_edition_order(): void {
		$this->terms[901] = $this->series_term( 901, 'one-piece-3-en-1', 'One Piece 3 en 1' );
		$this->terms[902] = $this->series_term( 902, 'one-piece-kanzenban', 'One Piece Kanzenban' );
		$this->term_meta[900][ self::RELATED_EDITIONS_KEY ] = array( 901, 902 );
		$this->module()->handle_init_renderer_state();
		$before = $this->term_meta[900][ self::FINGERPRINT_KEY ];

		$this->term_writes           = array();
		$this->sitemap_invalidations = array();
		$this->meta_cache_primes     = array();
		$this->now                  += 3600;
		$this->term_meta[900][ self::RELATED_EDITIONS_KEY ] = array( 902, 901 );

		$sut = $this->module();
		$sut->handle_term_meta_changed( 6, 900, self::RELATED_EDITIONS_KEY, array( 902, 901 ) );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( '2026-08-10 21:00:00', $this->term_meta[900][ self::LASTMOD_KEY ] );
		self::assertNotSame( $before, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should advance an owner landing when a visible peer name or slug changes.
	 */
	public function test_propagates_visible_peer_renames_to_related_owners(): void {
		$this->terms[901] = $this->series_term( 901, 'one-piece-3-en-1', 'One Piece 3 en 1' );
		$this->term_meta[900][ self::RELATED_EDITIONS_KEY ] = array( 901 );
		$this->module()->handle_init_renderer_state();
		$owner_fingerprint = $this->term_meta[900][ self::FINGERPRINT_KEY ];

		$this->term_writes           = array();
		$this->sitemap_invalidations = array();
		$this->meta_cache_primes     = array();
		$this->now                  += 3600;
		$this->terms[901]->name       = 'One Piece Omnibus';
		$this->terms[901]->slug       = 'one-piece-omnibus';
		$sut                         = $this->module();

		$sut->handle_series_term_changed( 901 );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( '2026-08-10 21:00:00', $this->term_meta[901][ self::LASTMOD_KEY ] );
		self::assertSame( '2026-08-10 21:00:00', $this->term_meta[900][ self::LASTMOD_KEY ] );
		self::assertNotSame( $owner_fingerprint, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertSame( array( 901, 901, 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
		self::assertSame( array( array( 'term', array( 900, 901 ) ) ), $this->meta_cache_primes, 'The reverse scan must prime all term metadata in one batch.' );

		$writes = count( $this->term_writes );
		$this->now += 3600;
		$sut->handle_series_term_changed( 901 );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertCount( $writes, $this->term_writes, 'A no-op peer edit must preserve both stable dates.' );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations, 'A no-op peer edit must not enqueue another invalidation.' );
	}

	/**
	 * @testdox Should remove a noindex peer from an owner fingerprint and ignore hidden peer copy changes.
	 */
	public function test_propagates_peer_noindex_meta_only_when_public_html_changes(): void {
		$this->terms[901] = $this->series_term( 901, 'one-piece-3-en-1', 'One Piece 3 en 1' );
		$this->term_meta[900][ self::RELATED_EDITIONS_KEY ] = array( 901 );
		$this->module()->handle_init_renderer_state();
		$visible_fingerprint = $this->term_meta[900][ self::FINGERPRINT_KEY ];

		$this->term_writes                       = array();
		$this->sitemap_invalidations             = array();
		$this->now                              += 3600;
		$this->term_meta[901][ self::ROBOTS_KEY ] = array( 'noindex', 'follow' );
		$sut                                     = $this->module();

		$sut->handle_term_meta_changed( 7, 901, self::ROBOTS_KEY, array( 'noindex', 'follow' ) );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( '2026-08-10 21:00:00', $this->term_meta[900][ self::LASTMOD_KEY ] );
		self::assertNotSame( $visible_fingerprint, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );

		$owner_lastmod     = $this->term_meta[900][ self::LASTMOD_KEY ];
		$owner_fingerprint = $this->term_meta[900][ self::FINGERPRINT_KEY ];
		$this->term_writes = array();
		$this->now        += 3600;
		$this->terms[901]->name = 'Nombre oculto por noindex';
		$sut->handle_series_term_changed( 901 );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( $owner_lastmod, $this->term_meta[900][ self::LASTMOD_KEY ], 'Hidden peer copy is not part of the owner HTML.' );
		self::assertSame( $owner_fingerprint, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertSame( array( 901, 901 ), array_column( $this->term_writes, 0 ), 'Only the peer itself changed publicly.' );
	}

	/**
	 * @testdox Should date a peer and its owner when eligibility changes from one to zero.
	 */
	public function test_propagates_core_normalized_peer_eligibility_transition(): void {
		$this->terms[901] = $this->series_term( 901, 'one-piece-3-en-1', 'One Piece 3 en 1' );
		$this->term_meta[900][ self::RELATED_EDITIONS_KEY ] = array( 901 );
		$this->module()->handle_init_renderer_state();

		$this->term_writes           = array();
		$this->sitemap_invalidations = array();
		$this->now                  += 3600;
		$this->eligibility_database->counts[901] = 0;
		$sut                         = $this->module();

		// Core may start with tt_ids plus an empty taxonomy, but the action resolves
		// them to term IDs and `pa_serie` before invoking our callback.
		$sut->handle_term_cache_cleaned( array( '901' ), 'pa_serie', false );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( '2026-08-10 21:00:00', $this->term_meta[901][ self::LASTMOD_KEY ] );
		self::assertSame( '2026-08-10 21:00:00', $this->term_meta[900][ self::LASTMOD_KEY ] );
		self::assertSame( array( 901, 901, 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );

		$writes = count( $this->term_writes );
		$this->now += 3600;
		$sut->handle_term_cache_cleaned( array( 901 ), 'pa_serie', false );
		$sut->handle_shutdown_sitemap_cache_flush();
		self::assertCount( $writes, $this->term_writes, 'An unchanged count state must be a complete no-op.' );
	}

	/**
	 * @testdox Should coalesce a peer-count batch into one scoped sitemap invalidation.
	 */
	public function test_coalesces_related_peer_count_batches(): void {
		$this->terms[901] = $this->series_term( 901, 'one-piece-3-en-1', 'One Piece 3 en 1' );
		$this->terms[902] = $this->series_term( 902, 'one-piece-kanzenban', 'One Piece Kanzenban' );
		$this->term_meta[900][ self::RELATED_EDITIONS_KEY ] = array( 901, 902 );
		$this->module()->handle_init_renderer_state();

		$this->term_writes           = array();
		$this->sitemap_invalidations = array();
		$this->now                  += 3600;
		$this->eligibility_database->counts[901] = 0;
		$this->eligibility_database->counts[902] = 0;
		$this->eligibility_database->queries     = array();
		$sut                         = $this->module();

		$sut->handle_term_cache_cleaned( array( 901, 902, 901 ), 'pa_serie', false );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 901, 901, 902, 902, 900, 900 ), array_column( $this->term_writes, 0 ), 'Each final peer and owner state must be persisted only once.' );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations, 'A batch must enqueue one taxonomy-scoped invalidation.' );
		self::assertCount( 1, $this->eligibility_database->queries, 'All changed terms must share one aggregate eligibility query.' );

		$writes = count( $this->term_writes );
		$sut->handle_term_cache_cleaned( array( 901 ), 'product_brand', false );
		$sut->handle_shutdown_sitemap_cache_flush();
		self::assertCount( $writes, $this->term_writes, 'Other taxonomies must remain outside this policy.' );
	}

	/**
	 * @testdox Should date the term for one-to-two count changes without dating its related owner.
	 */
	public function test_visible_count_changes_date_only_the_changed_series(): void {
		$this->terms[901] = $this->series_term( 901, 'one-piece-3-en-1', 'One Piece 3 en 1' );
		$this->term_meta[900][ self::RELATED_EDITIONS_KEY ] = array( 901 );
		$this->module()->handle_init_renderer_state();
		$owner_fingerprint = $this->term_meta[900][ self::FINGERPRINT_KEY ];

		$this->eligibility_database->counts[901] = 2;
		$this->eligibility_database->queries     = array();
		$this->term_writes                       = array();
		$this->sitemap_invalidations             = array();
		$this->now                              += 3600;
		$sut                                     = $this->module();

		$sut->handle_term_cache_cleaned( array( 901 ), 'pa_serie', false );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 901, 901 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( '2026-08-10 21:00:00', $this->term_meta[901][ self::LASTMOD_KEY ] );
		self::assertArrayNotHasKey( self::LASTMOD_KEY, $this->term_meta[900] );
		self::assertSame( $owner_fingerprint, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );

		$this->eligibility_database->counts[901] = 1;
		$this->term_writes                       = array();
		$this->sitemap_invalidations             = array();
		$this->now                              += 3600;
		$sut->handle_term_cache_cleaned( array( 901 ), 'pa_serie', false );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 901, 901 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( '2026-08-10 22:00:00', $this->term_meta[901][ self::LASTMOD_KEY ] );
		self::assertArrayNotHasKey( self::LASTMOD_KEY, $this->term_meta[900] );
		self::assertSame( $owner_fingerprint, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
		self::assertCount( 2, $this->eligibility_database->queries );
	}

	/**
	 * @testdox Should date a peer and owner when eligibility changes from zero to one.
	 */
	public function test_eligibility_zero_to_one_changes_peer_and_related_owner(): void {
		$this->terms[901] = $this->series_term( 901, 'one-piece-3-en-1', 'One Piece 3 en 1' );
		$this->term_meta[900][ self::RELATED_EDITIONS_KEY ] = array( 901 );
		$this->eligibility_database->counts[901] = 0;
		$this->module()->handle_init_renderer_state();

		$this->eligibility_database->counts[901] = 1;
		$this->term_writes                       = array();
		$this->sitemap_invalidations             = array();
		$this->now                              += 3600;
		$sut                                     = $this->module();

		$sut->handle_term_cache_cleaned( array( 901 ), 'pa_serie', false );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 901, 901, 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( '2026-08-10 21:00:00', $this->term_meta[901][ self::LASTMOD_KEY ] );
		self::assertSame( '2026-08-10 21:00:00', $this->term_meta[900][ self::LASTMOD_KEY ] );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should process old and new series relationships once at shutdown.
	 */
	public function test_set_object_terms_coalesces_old_and_new_series(): void {
		$this->terms[901] = $this->series_term( 901, 'tokyo-ghoul-re', 'Tokyo Ghoul:re' );
		$this->eligibility_database->counts = array( 900 => 1, 901 => 0 );
		$this->module()->handle_init_renderer_state();

		$this->eligibility_database->counts = array( 900 => 0, 901 => 1 );
		$this->eligibility_database->queries = array();
		$this->term_writes                   = array();
		$this->sitemap_invalidations         = array();
		$sut                                 = $this->module();

		$sut->handle_object_terms_set( 77, array( 901 ), array( 901 ), 'pa_serie', false, array( 900 ) );
		$sut->handle_object_terms_set( 77, array( 901 ), array( 901 ), 'pa_serie', false, array( 900 ) );
		$sut->handle_shutdown_sitemap_cache_flush();

		$writes_by_term = array_count_values( array_column( $this->term_writes, 0 ) );
		self::assertSame( array( 901 => 2, 900 => 2 ), $writes_by_term );
		self::assertCount( 1, $this->eligibility_database->queries );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should batch product-visibility callbacks into one final series lookup.
	 */
	public function test_product_visibility_set_and_direct_removal_are_coalesced(): void {
		$this->module()->handle_init_renderer_state();
		$this->eligibility_database->counts[900] = 0;
		$this->eligibility_database->queries     = array();
		$this->product_series_term_taxonomy_ids[77] = array( 900 );
		$this->term_writes                       = array();
		$this->sitemap_invalidations             = array();
		$sut                                     = $this->module();

		$sut->handle_object_terms_set( 77, array( 'exclude-from-catalog' ), array( 333 ), 'product_visibility', false, array() );
		$sut->handle_term_relationships_deleted( 77, array( 333 ), 'product_visibility' );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( array( array( array( 77 ), 'pa_serie', array( 'fields' => 'tt_ids' ) ) ), $this->object_term_queries );
		self::assertCount( 1, $this->eligibility_database->queries );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should date series when author/editorial assignments change through set or remove APIs.
	 */
	public function test_catalog_editorial_relationship_changes_update_the_series_fingerprint(): void {
		$this->eligibility_database->editorial_rows[900] = array(
			$this->editorial_row( 'product_brand', 21, 'Planeta España', 'planeta-espana' ),
			$this->editorial_row( 'pa_autor', 31, 'Sui Ishida', 'sui-ishida' ),
		);
		$this->product_series_term_taxonomy_ids[77] = array( 900 );
		$this->module()->handle_init_renderer_state();
		$before = $this->term_meta[900][ self::FINGERPRINT_KEY ];
		$this->eligibility_database->editorial_queries = array();
		$this->object_term_queries    = array();
		$this->term_writes            = array();
		$this->sitemap_invalidations  = array();
		$this->now                   += 3600;
		$this->eligibility_database->editorial_rows[900][0] = $this->editorial_row( 'product_brand', 22, 'Ivrea España', 'ivrea-espana' );
		$sut = $this->module();

		$sut->handle_object_terms_set( 77, array( 22 ), array( 222 ), 'product_brand', false, array( 221 ) );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertNotSame( $before, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertCount( 1, $this->object_term_queries );
		self::assertCount( 1, $this->eligibility_database->editorial_queries );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );

		$before = $this->term_meta[900][ self::FINGERPRINT_KEY ];
		$this->term_writes                         = array();
		$this->sitemap_invalidations               = array();
		$this->eligibility_database->editorial_queries = array();
		$this->object_term_queries                 = array();
		$this->now                                += 3600;
		$this->eligibility_database->editorial_rows[900] = array(
			$this->editorial_row( 'product_brand', 22, 'Ivrea España', 'ivrea-espana' ),
		);

		$sut->handle_term_relationships_deleted( 77, array( 331 ), 'pa_autor' );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertNotSame( $before, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertCount( 1, $this->object_term_queries );
		self::assertCount( 1, $this->eligibility_database->editorial_queries );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should track product title, slug and thumbnail without dating price or stock churn.
	 */
	public function test_stable_product_card_identity_changes_the_series_fingerprint(): void {
		$this->eligibility_database->editorial_rows[900] = array(
			$this->editorial_row( 'product_brand', 21, 'Planeta España', 'planeta-espana' ),
		);
		$this->product_series_term_taxonomy_ids[77] = array( 900 );
		$this->module()->handle_init_renderer_state();
		$this->eligibility_database->editorial_rows[900][0]['product_title'] = 'One Piece 01 — Nueva edición';
		$this->eligibility_database->editorial_rows[900][0]['product_slug']  = 'one-piece-01-nueva-edicion';
		$this->term_writes             = array();
		$this->term_page_invalidations = array();
		$this->sitemap_invalidations   = array();
		$this->now                    += 3600;
		$sut                           = $this->module();

		$sut->handle_product_post_updated(
			77,
			(object) array( 'post_type' => 'product', 'post_title' => 'One Piece 01 — Nueva edición', 'post_name' => 'one-piece-01-nueva-edicion' ),
			(object) array( 'post_type' => 'product', 'post_title' => 'One Piece 01', 'post_name' => 'one-piece-01' )
		);
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( array( 900 => 900 ), $this->term_page_invalidations );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );

		$writes = count( $this->term_writes );
		$sut = $this->module();
		$sut->handle_product_post_updated(
			77,
			(object) array( 'post_type' => 'product', 'post_title' => 'One Piece 01 — Nueva edición', 'post_name' => 'one-piece-01-nueva-edicion', 'post_modified' => '2026-08-11 00:00:00' ),
			(object) array( 'post_type' => 'product', 'post_title' => 'One Piece 01 — Nueva edición', 'post_name' => 'one-piece-01-nueva-edicion', 'post_modified' => '2026-08-10 00:00:00' )
		);
		$sut->handle_product_meta_changed( 1, 77, '_price', '10990' );
		$sut->handle_product_meta_changed( 2, 77, '_stock', '3' );
		$sut->handle_shutdown_sitemap_cache_flush();
		self::assertCount( $writes, $this->term_writes, 'Volatile product saves must not even enter the stable fingerprint commit.' );

		$this->eligibility_database->editorial_rows[900][0]['product_thumbnail_id'] = 701;
		$this->term_writes             = array();
		$this->term_page_invalidations = array();
		$this->sitemap_invalidations   = array();
		$this->now                    += 3600;
		$sut                           = $this->module();
		$sut->handle_product_meta_changed( 3, 77, '_thumbnail_id', 701 );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( array( 900 => 900 ), $this->term_page_invalidations );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should date a constant-count product swap because the public grid identity changed.
	 */
	public function test_constant_count_product_swap_changes_the_series_fingerprint(): void {
		$this->eligibility_database->editorial_rows[900] = array(
			$this->editorial_row( 'product_brand', 21, 'Planeta España', 'planeta-espana', 900, 77 ),
		);
		$this->module()->handle_init_renderer_state();
		$this->eligibility_database->editorial_rows[900][0] = $this->editorial_row( 'product_brand', 21, 'Planeta España', 'planeta-espana', 900, 88, 'One Piece 01', 'one-piece-01-reemplazo', 700 );
		$this->term_writes           = array();
		$this->sitemap_invalidations = array();
		$this->now                  += 3600;
		$sut                         = $this->module();

		$sut->handle_object_terms_set( 88, array( 900 ), array( 900 ), 'pa_serie', false, array() );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( '2026-08-10 21:00:00', $this->term_meta[900][ self::LASTMOD_KEY ] );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should rescan stable series fingerprints when an author/editorial identity is renamed.
	 */
	public function test_catalog_facet_term_rename_uses_one_bounded_rescan(): void {
		$this->eligibility_database->editorial_rows[900] = array(
			$this->editorial_row( 'pa_autor', 31, 'Sui Ishida', 'sui-ishida' ),
		);
		$this->module()->handle_init_renderer_state();
		$this->eligibility_database->editorial_rows[900][0] = $this->editorial_row( 'pa_autor', 31, 'Ishida Sui', 'ishida-sui' );
		$this->eligibility_database->editorial_queries = array();
		$this->term_writes           = array();
		$this->sitemap_invalidations = array();
		$this->now                  += 3600;
		$sut                         = $this->module();

		$sut->handle_catalog_facet_term_changed();
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( '2026-08-10 21:00:00', $this->term_meta[900][ self::LASTMOD_KEY ] );
		self::assertCount( 1, $this->eligibility_database->editorial_queries );
		self::assertSame( array(), $this->object_term_queries, 'A rare term rename must not reverse-query every product.' );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should durably retry author/editorial changes when their aggregate is unreadable.
	 */
	public function test_catalog_editorial_aggregate_error_never_seals_an_empty_fingerprint(): void {
		$this->eligibility_database->editorial_rows[900] = array(
			$this->editorial_row( 'product_brand', 21, 'Planeta España', 'planeta-espana' ),
		);
		$this->product_series_term_taxonomy_ids[77] = array( 900 );
		$this->module()->handle_init_renderer_state();
		$before = $this->term_meta[900][ self::FINGERPRINT_KEY ];
		$this->eligibility_database->editorial_rows[900][0] = $this->editorial_row( 'product_brand', 22, 'Ivrea España', 'ivrea-espana' );
		$this->eligibility_database->editorial_empty_database_error = true;
		$this->term_writes           = array();
		$this->sitemap_invalidations = array();
		$sut                         = $this->module();

		$sut->handle_object_terms_set( 77, array( 22 ), array( 222 ), 'product_brand', false, array( 221 ) );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( $before, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertSame( array(), $this->term_writes );
		self::assertSame( array( 900 ), $this->options[ self::PENDING_KEY ]['term_taxonomy_ids'] );

		$this->eligibility_database->editorial_empty_database_error = false;
		$retry = $this->module();
		$retry->handle_init_renderer_state();
		$retry->handle_shutdown_sitemap_cache_flush();

		self::assertNotSame( $before, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertArrayNotHasKey( self::PENDING_KEY, $this->options );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should not consume pending product changes when the relation batch fails.
	 */
	public function test_product_relation_read_failure_remains_a_no_op_and_retryable(): void {
		$this->module()->handle_init_renderer_state();
		$this->eligibility_database->counts[900] = 0;
		$this->product_series_term_taxonomy_ids[77] = array( 900 );
		$this->object_term_query_fails          = true;
		$this->term_writes                     = array();
		$this->sitemap_invalidations           = array();
		$sut                                   = $this->module();

		$sut->handle_object_terms_set( 77, array(), array(), 'product_visibility', false, array() );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array(), $this->term_writes );
		self::assertSame( array(), $this->sitemap_invalidations );
		self::assertSame( array( 77 ), $this->options[ self::PENDING_KEY ]['product_ids'] );

		$this->object_term_query_fails = false;
		$retry = $this->module();
		$retry->handle_init_renderer_state();
		$retry->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertCount( 2, $this->object_term_queries );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
		self::assertArrayNotHasKey( self::PENDING_KEY, $this->options );
	}

	/**
	 * @testdox Should capture a removed series relationship after hard product deletion.
	 */
	public function test_direct_series_relationship_removal_covers_hard_delete(): void {
		$this->module()->handle_init_renderer_state();
		$this->eligibility_database->counts[900] = 0;
		$this->term_writes                       = array();
		$this->sitemap_invalidations             = array();
		$sut                                     = $this->module();

		$sut->handle_term_relationships_deleted( 77, array( 900 ), 'pa_serie' );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( array(), $this->object_term_queries, 'Removed pa_serie tt_ids must survive after the product row is gone.' );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should react only when a product crosses the publish boundary.
	 */
	public function test_transition_post_status_filters_non_catalog_boundaries(): void {
		$this->terms[901] = $this->series_term( 901, 'tokyo-ghoul', 'Tokyo Ghoul' );
		$this->eligibility_database->counts[901] = 0;
		$this->module()->handle_init_renderer_state();
		$this->eligibility_database->counts[900] = 0;
		$this->product_series_term_taxonomy_ids[77] = array( 900 );
		$this->product_series_term_taxonomy_ids[88] = array( 901 );
		$this->term_writes                       = array();
		$this->sitemap_invalidations             = array();
		$sut                                     = $this->module();
		$product                                 = (object) array( 'ID' => 77, 'post_type' => 'product' );

		$sut->handle_product_status_transition( 'pending', 'draft', $product );
		$sut->handle_product_status_transition( 'publish', 'publish', $product );
		$sut->handle_product_status_transition( 'publish', 'draft', (object) array( 'ID' => 88, 'post_type' => 'post' ) );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array(), $this->object_term_queries );
		self::assertSame( array(), $this->term_writes );

		$sut->handle_product_status_transition( 'trash', 'publish', $product );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertCount( 1, $this->object_term_queries );
		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );

		$this->eligibility_database->counts[901] = 1;
		$this->term_writes                       = array();
		$this->sitemap_invalidations             = array();
		$sut->handle_product_status_transition(
			'publish',
			'draft',
			(object) array( 'ID' => 88, 'post_type' => 'product' )
		);
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertCount( 2, $this->object_term_queries );
		self::assertSame( array( 901, 901 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should process a bulk catalogue mutation without per-product or per-series reads.
	 */
	public function test_bulk_catalogue_mutations_use_constant_query_count(): void {
		for ( $offset = 0; $offset < 100; ++$offset ) {
			$term_id                         = 1000 + $offset;
			$product_id                      = 5000 + $offset;
			$this->terms[ $term_id ]          = $this->series_term( $term_id, 'serie-' . $term_id, 'Serie ' . $term_id );
			$this->product_series_term_taxonomy_ids[ $product_id ] = array( $term_id );
		}
		$this->module()->handle_init_renderer_state();

		foreach ( array_keys( $this->terms ) as $term_id ) {
			if ( $term_id >= 1000 ) {
				$this->eligibility_database->counts[ $term_id ] = 0;
			}
		}
		$this->eligibility_database->queries = array();
		$this->eligibility_database->editorial_queries = array();
		$this->object_term_queries            = array();
		$this->meta_cache_primes              = array();
		$this->term_writes                    = array();
		$this->sitemap_invalidations          = array();
		$this->get_terms_queries              = 0;
		$sut                                  = $this->module();

		for ( $offset = 0; $offset < 100; ++$offset ) {
			$product_id = 5000 + $offset;
			$sut->handle_object_terms_set( $product_id, array(), array(), 'product_visibility', false, array() );
			$sut->handle_product_status_transition(
				'trash',
				'publish',
				(object) array( 'ID' => $product_id, 'post_type' => 'product' )
			);
		}
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertCount( 1, $this->object_term_queries, 'All products must share one relation lookup.' );
		self::assertCount( 1, $this->eligibility_database->queries, 'All terms must share one aggregate eligibility query.' );
		self::assertCount( 1, $this->eligibility_database->editorial_queries, 'All author/editorial state must share one aggregate query.' );
		self::assertSame( 1, $this->get_terms_queries, 'The complete series map must load once.' );
		self::assertCount( 1, $this->meta_cache_primes, 'Term metadata must be primed once.' );
		self::assertCount( 200, $this->term_writes, 'All 100 final eligibility transitions must write one date and fingerprint.' );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should not seed fingerprints or renderer state from an unreliable eligibility query.
	 */
	public function test_renderer_seed_retries_without_persisting_false_zeroes(): void {
		$this->eligibility_database->fail = true;
		$sut = $this->module( 'renderer-v2' );

		$sut->handle_init_renderer_state();

		self::assertArrayNotHasKey( self::OPTION_KEY, $this->options );
		self::assertSame( array(), $this->term_writes );
		self::assertSame( array(), $this->sitemap_invalidations );

		$this->eligibility_database->fail = false;
		$sut->handle_init_renderer_state();

		self::assertSame( 'renderer-v2', $this->options[ self::OPTION_KEY ]['version'] );
		self::assertArrayHasKey( self::FINGERPRINT_KEY, $this->term_meta[900] );
		self::assertArrayNotHasKey( self::LASTMOD_KEY, $this->term_meta[900] );
		self::assertCount( 2, $this->eligibility_database->queries, 'A failed aggregate must remain retryable.' );
	}

	/**
	 * @testdox Should not persist an eligibility transition until its aggregate is reliable.
	 */
	public function test_shutdown_keeps_unreliable_transitions_as_no_ops(): void {
		$this->module()->handle_init_renderer_state();
		$this->eligibility_database->counts[900] = 0;
		$this->eligibility_database->queries     = array();
		$this->eligibility_database->fail        = true;
		$this->term_writes                       = array();
		$this->sitemap_invalidations             = array();
		$sut                                     = $this->module();

		$sut->handle_term_cache_cleaned( array( 900 ), 'pa_serie', false );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array(), $this->term_writes );
		self::assertSame( array(), $this->sitemap_invalidations );
		self::assertArrayNotHasKey( self::LASTMOD_KEY, $this->term_meta[900] );
		self::assertSame( array( 900 ), $this->options[ self::PENDING_KEY ]['term_taxonomy_ids'] );
		self::assertFalse( $this->option_writes[ count( $this->option_writes ) - 1 ][2], 'The retry queue must never autoload.' );

		$this->eligibility_database->fail = false;
		$retry = $this->module();
		$retry->handle_init_renderer_state();
		$retry->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
		self::assertCount( 2, $this->eligibility_database->queries );
		self::assertArrayNotHasKey( self::PENDING_KEY, $this->options );
		self::assertSame( array( self::PENDING_KEY ), $this->option_deletes );
	}

	/**
	 * @testdox Should fail open without retry storms when sitemap eligibility is unreliable.
	 */
	public function test_sitemap_preserves_entries_when_eligibility_is_unreliable(): void {
		$this->eligibility_database->fail = true;
		$sut = $this->module();
		$entry = array(
			'loc' => 'https://akibara.cl/serie/one-piece/',
			'mod' => '2026-08-10 23:59:59',
		);
		$stable_entry = array( 'loc' => 'https://akibara.cl/serie/one-piece/' );

		self::assertSame( $stable_entry, $sut->handle_sitemap_entry( $entry, 'term', $this->terms[900] ) );
		self::assertSame( $stable_entry, $sut->handle_sitemap_entry( $entry, 'term', $this->terms[900] ) );
		self::assertCount( 1, $this->eligibility_database->queries, 'One failed aggregate must not become one query per sitemap entry.' );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations, 'The fail-open XML must be purged instead of cached.' );
		self::assertSame( array(), $this->term_writes );
	}

	/**
	 * @testdox Should replace a volatile product date in the sitemap index with the newest stable series date.
	 */
	public function test_sitemap_index_uses_only_stable_series_dates(): void {
		$this->options[ self::OPTION_KEY ] = array(
			'version'      => 'renderer-v1',
			'modified_gmt' => '2026-08-10 20:00:00',
		);
		$this->term_meta[900][ self::LASTMOD_KEY ] = '2026-08-10 21:00:00';
		$entry = array(
			'loc'     => 'https://akibara.cl/pa_serie-sitemap.xml',
			'lastmod' => '2026-08-10 23:59:59',
		);
		$sut = $this->module();

		$result = $sut->handle_sitemap_index_entry( $entry, 'term', 'pa_serie' );

		self::assertIsArray( $result );
		self::assertSame( '2026-08-10 21:00:00', $result['lastmod'] );
		self::assertSame( $entry['loc'], $result['loc'] );
		self::assertSame( $entry, $sut->handle_sitemap_index_entry( $entry, 'term', 'product_brand' ) );
		self::assertCount( 1, $this->eligibility_database->queries );
		self::assertSame( array(), $this->term_writes );
	}

	/**
	 * @testdox Should remove a reliably hidden-only child sitemap and purge an unreliable stable fallback.
	 */
	public function test_sitemap_index_handles_hidden_and_unreliable_catalogues(): void {
		$this->eligibility_database->counts[900] = 0;
		$entry = array(
			'loc'     => 'https://akibara.cl/pa_serie-sitemap.xml',
			'lastmod' => '2026-08-10 23:59:59',
		);

		self::assertFalse( $this->module()->handle_sitemap_index_entry( $entry, 'term', 'pa_serie' ) );

		$this->eligibility_database->fail = true;
		$this->eligibility_database->queries = array();
		$this->term_meta[900][ self::LASTMOD_KEY ] = '2026-08-10 21:00:00';
		$sut = $this->module();
		$result = $sut->handle_sitemap_index_entry( $entry, 'term', 'pa_serie' );

		self::assertIsArray( $result );
		self::assertSame( '2026-08-10 21:00:00', $result['lastmod'] );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
		$sut->handle_shutdown_sitemap_cache_flush();
		self::assertSame( 1, $this->sitemap_flushes );
	}

	/**
	 * @testdox Should remove a known all-noindex child sitemap even when eligibility is unavailable.
	 */
	public function test_sitemap_index_does_not_fail_open_past_known_noindex_terms(): void {
		$this->term_meta[900][ self::ROBOTS_KEY ] = array( 'noindex', 'follow' );
		$this->eligibility_database->fail = true;
		$entry = array(
			'loc'     => 'https://akibara.cl/pa_serie-sitemap.xml',
			'lastmod' => '2026-08-10 23:59:59',
		);

		self::assertFalse( $this->module()->handle_sitemap_index_entry( $entry, 'term', 'pa_serie' ) );
	}

	/**
	 * @testdox Should fail open and avoid renderer seeding when a term query returns empty with a database error.
	 */
	public function test_empty_term_query_database_error_is_never_authoritative(): void {
		$this->term_query_returns_database_error = true;
		$entry = array(
			'loc'     => 'https://akibara.cl/pa_serie-sitemap.xml',
			'lastmod' => '2026-08-10 23:59:59',
		);
		$sut = $this->module( 'renderer-v2' );

		$sut->handle_init_renderer_state();
		$result = $sut->handle_sitemap_index_entry( $entry, 'term', 'pa_serie' );

		self::assertArrayNotHasKey( self::OPTION_KEY, $this->options );
		self::assertArrayNotHasKey( self::FINGERPRINT_KEY, $this->term_meta[900] ?? array() );
		self::assertIsArray( $result );
		self::assertSame( $entry['loc'], $result['loc'] );
		self::assertArrayNotHasKey( 'lastmod', $result );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should keep a hidden-term transition as the stable child-sitemap modification date.
	 */
	public function test_sitemap_index_includes_stable_removal_dates_without_relisting_hidden_terms(): void {
		$this->terms[901] = $this->series_term( 901, 'tokyo-ghoul', 'Tokyo Ghoul' );
		$this->eligibility_database->counts = array( 900 => 0, 901 => 1 );
		$this->term_meta[900][ self::LASTMOD_KEY ] = '2026-08-10 22:00:00';
		$this->term_meta[901][ self::LASTMOD_KEY ] = '2026-08-10 20:00:00';
		$entry = array( 'loc' => 'https://akibara.cl/pa_serie-sitemap.xml' );

		$result = $this->module()->handle_sitemap_index_entry( $entry, 'term', 'pa_serie' );

		self::assertIsArray( $result );
		self::assertSame( '2026-08-10 22:00:00', $result['lastmod'] );
	}

	/**
	 * @testdox Should collapse an oversized durable retry queue to one bounded full rescan.
	 */
	public function test_durable_retry_queue_bounds_overflow_with_full_rescan(): void {
		$this->module()->handle_init_renderer_state();
		$this->terms[900]->description = 'Descripción que debe sobrevivir al fallo.';
		$this->eligibility_database->fail = true;
		$this->term_writes               = array();
		$this->sitemap_invalidations     = array();
		$sut                             = $this->module();

		$sut->handle_series_term_changed( 900 );
		$sut->handle_object_terms_set( 77, array(), range( 1, 2050 ), 'pa_serie', false, array() );
		$sut->handle_shutdown_sitemap_cache_flush();

		$pending = $this->options[ self::PENDING_KEY ];
		self::assertTrue( $pending['full_rescan'] );
		self::assertSame( array(), $pending['term_ids'] );
		self::assertSame( array(), $pending['term_taxonomy_ids'] );
		self::assertSame( array(), $pending['product_ids'] );
		self::assertSame( array(), $pending['guide_post_ids'] );
		self::assertFalse( $this->option_writes[ count( $this->option_writes ) - 1 ][2] );

		$this->eligibility_database->fail = false;
		$retry = $this->module();
		$retry->handle_init_renderer_state();
		$retry->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertArrayNotHasKey( self::PENDING_KEY, $this->options );
	}

	/**
	 * @testdox Should retry an atomic option conflict and preserve both writers' dirty work.
	 */
	public function test_durable_persist_merges_a_concurrent_writer_with_cas_retry(): void {
		$this->eligibility_database->fail = true;
		$this->eligibility_database->before_option_insert = function (): void {
			$this->options[ self::PENDING_KEY ] = $this->pending_payload( 'concurrent-writer', array( 901 ) );
		};
		$sut = $this->module();

		$sut->handle_term_cache_cleaned( array( 900 ), 'pa_serie', false );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 900 ), $this->options[ self::PENDING_KEY ]['term_ids'] );
		self::assertSame( array( 901, 900 ), $this->options[ self::PENDING_KEY ]['term_taxonomy_ids'] );
		self::assertSame( 'pending-2', $this->options[ self::PENDING_KEY ]['token'] );
		self::assertCount( 1, $this->option_writes, 'Only the successful compare-and-swap is an owned option write.' );
	}

	/**
	 * @testdox Should preserve a product retry when WordPress returns an empty relation result with a database error.
	 */
	public function test_product_relation_empty_database_error_retries_in_a_new_request(): void {
		$this->module()->handle_init_renderer_state();
		$this->term_writes           = array();
		$this->sitemap_invalidations = array();
		$this->eligibility_database->counts[900] = 2;
		$this->product_series_term_taxonomy_ids[77] = array( 900 );
		$this->object_term_query_returns_database_error = true;
		$sut = $this->module();

		$sut->handle_product_status_transition( 'publish', 'draft', (object) array( 'ID' => 77, 'post_type' => 'product' ) );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 77 ), $this->options[ self::PENDING_KEY ]['product_ids'] );
		self::assertSame( array(), $this->term_writes );

		$this->object_term_query_returns_database_error = false;
		$retry = $this->module();
		$retry->handle_init_renderer_state();
		$retry->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );
		self::assertArrayNotHasKey( self::PENDING_KEY, $this->options );
	}

	/**
	 * @testdox Should leave a newer concurrent retry generation for the next request.
	 */
	public function test_durable_cleanup_never_deletes_a_newer_generation(): void {
		$this->terms[901] = $this->series_term( 901, 'tokyo-ghoul', 'Tokyo Ghoul' );
		$this->module()->handle_init_renderer_state();
		$this->options[ self::PENDING_KEY ] = $this->pending_payload( 'generation-a', array( 900 ) );
		$this->eligibility_database->counts[900] = 0;
		$this->term_writes                       = array();
		$this->sitemap_invalidations             = array();
		$sut                                     = $this->module();
		$sut->handle_init_renderer_state();

		$this->eligibility_database->before_option_delete = function (): void {
			$this->options[ self::PENDING_KEY ] = $this->pending_payload( 'generation-b', array( 901 ) );
		};
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( 'generation-b', $this->options[ self::PENDING_KEY ]['token'] );
		self::assertSame( array( 900, 900 ), array_column( $this->term_writes, 0 ) );

		$this->eligibility_database->counts[901] = 0;
		$this->term_writes                       = array();
		$retry                                   = $this->module();
		$retry->handle_init_renderer_state();
		$retry->handle_shutdown_sitemap_cache_flush();

		self::assertSame( array( 901, 901 ), array_column( $this->term_writes, 0 ) );
		self::assertArrayNotHasKey( self::PENDING_KEY, $this->options );
		self::assertSame( array( self::PENDING_KEY ), $this->option_deletes );
	}

	/**
	 * @testdox Should normalize malformed retry IDs and clean the owned option idempotently.
	 */
	public function test_durable_retry_normalization_and_cleanup_are_idempotent(): void {
		$this->module()->handle_init_renderer_state();
		$this->options[ self::PENDING_KEY ] = array(
			'token'             => 'invalid token',
			'full_rescan'       => false,
			'term_ids'          => array( '900', 900, 'bad', -4, null ),
			'term_taxonomy_ids' => '900',
			'product_ids'       => array( 0, '' ),
			'guide_post_ids'    => array( 0, '' ),
		);
		$this->term_writes           = array();
		$this->sitemap_invalidations = array();
		$sut                         = $this->module();

		$sut->handle_init_renderer_state();
		$sut->handle_shutdown_sitemap_cache_flush();
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertArrayNotHasKey( self::PENDING_KEY, $this->options );
		self::assertSame( array( self::PENDING_KEY ), $this->option_deletes );
		self::assertSame( array(), $this->term_writes, 'Normalized duplicate work with an unchanged fingerprint is a no-op.' );
		self::assertSame( array(), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should keep dirty work when lastmod fails instead of sealing only the fingerprint.
	 */
	public function test_lastmod_write_failure_keeps_fingerprint_and_retries_next_request(): void {
		$this->module()->handle_init_renderer_state();
		$before = $this->term_meta[900][ self::FINGERPRINT_KEY ];
		$this->eligibility_database->counts[900] = 0;
		$this->term_write_failures[900][ self::LASTMOD_KEY ] = 1;
		$this->term_writes                       = array();
		$this->sitemap_invalidations             = array();
		$sut                                     = $this->module();

		$sut->handle_term_cache_cleaned( array( 900 ), 'pa_serie', false );
		$sut->handle_shutdown_sitemap_cache_flush();

		self::assertSame( $before, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertArrayNotHasKey( self::LASTMOD_KEY, $this->term_meta[900] );
		self::assertArrayHasKey( self::PENDING_KEY, $this->options );
		self::assertSame( array(), $this->sitemap_invalidations );

		$retry = $this->module();
		$retry->handle_init_renderer_state();
		$retry->handle_shutdown_sitemap_cache_flush();

		self::assertNotSame( $before, $this->term_meta[900][ self::FINGERPRINT_KEY ] );
		self::assertArrayHasKey( self::LASTMOD_KEY, $this->term_meta[900] );
		self::assertArrayNotHasKey( self::PENDING_KEY, $this->options );
		self::assertSame( array( 'pa_serie' ), $this->sitemap_invalidations );
	}

	/**
	 * @testdox Should not advance renderer state until every seeded fingerprint is verified.
	 */
	public function test_renderer_seed_verifies_fingerprint_writes_before_advancing(): void {
		$this->term_write_failures[900][ self::FINGERPRINT_KEY ] = 1;

		$this->module( 'renderer-v2' )->handle_init_renderer_state();

		self::assertArrayNotHasKey( self::OPTION_KEY, $this->options );
		self::assertArrayNotHasKey( self::FINGERPRINT_KEY, $this->term_meta[900] ?? array() );

		$this->module( 'renderer-v2' )->handle_init_renderer_state();

		self::assertSame( 'renderer-v2', $this->options[ self::OPTION_KEY ]['version'] );
		self::assertArrayHasKey( self::FINGERPRINT_KEY, $this->term_meta[900] );
	}

	/**
	 * @testdox Should remove hidden-only terms from the sitemap without N plus one queries.
	 */
	public function test_sitemap_filters_hidden_only_terms_with_one_batch_query(): void {
		$this->terms[901] = $this->series_term( 901, 'tokyo-ghoul', 'Tokyo Ghoul' );
		$this->eligibility_database->counts = array( 900 => 0, 901 => 2 );
		$sut = $this->module();
		$entry = array( 'loc' => 'https://akibara.cl/serie/one-piece/' );

		self::assertFalse( $sut->handle_sitemap_entry( $entry, 'term', $this->terms[900] ) );
		$result = $sut->handle_sitemap_entry( $entry, 'term', $this->terms[901] );

		self::assertIsArray( $result );
		self::assertSame( 'https://akibara.cl/serie/one-piece/', $result['loc'] );
		self::assertCount( 1, $this->eligibility_database->queries, 'Every sitemap entry must reuse the first batch query.' );
	}

	/**
	 * @testdox Should replace product churn with the newest durable renderer or term date.
	 */
	public function test_sitemap_uses_the_newest_stable_date_only(): void {
		$this->options[ self::OPTION_KEY ] = array(
			'version'      => 'renderer-v1',
			'modified_gmt' => '2026-08-10 20:00:00',
		);
		$this->term_meta[900][ self::LASTMOD_KEY ] = '2026-08-10 21:00:00';
		$writes_before = array( count( $this->term_writes ), count( $this->option_writes ) );

		$result = $this->module()->handle_sitemap_entry(
			array(
				'loc' => 'https://akibara.cl/serie/one-piece/',
				'mod' => '2026-08-10 23:59:59',
			),
			'term',
			$this->terms[900]
		);

		self::assertIsArray( $result );
		self::assertSame( '2026-08-10 21:00:00', $result['mod'] );
		self::assertSame( 'https://akibara.cl/serie/one-piece/', $result['loc'] );
		self::assertSame( $writes_before, array( count( $this->term_writes ), count( $this->option_writes ) ), 'Sitemap rendering must be read-only.' );

		$this->term_meta[900][ self::LASTMOD_KEY ] = '2026-08-09 21:00:00';
		$result = $this->module()->handle_sitemap_entry( $result, 'term', $this->terms[900] );
		self::assertSame( '2026-08-10 20:00:00', $result['mod'], 'A newer renderer version applies to every term.' );
	}

	/**
	 * @testdox Should omit an untrustworthy lastmod and leave unrelated entries untouched.
	 */
	public function test_omits_untrustworthy_dates_and_preserves_other_entries(): void {
		$this->options[ self::OPTION_KEY ] = array(
			'version'      => 'renderer-old',
			'modified_gmt' => '2026-02-31 25:61:00',
		);
		$entry = array(
			'loc' => 'https://akibara.cl/serie/one-piece/',
			'mod' => '2026-08-10 23:59:59',
		);

		$result = $this->module()->handle_sitemap_entry( $entry, 'term', $this->terms[900] );
		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'mod', $result );

		$brand           = clone $this->terms[900];
		$brand->taxonomy = 'product_brand';
		self::assertSame( $entry, $this->module()->handle_sitemap_entry( $entry, 'term', $brand ) );
		self::assertSame( 'invalid', $this->module()->handle_sitemap_entry( 'invalid', 'term', $this->terms[900] ) );
	}

	/**
	 * @testdox Should reject an empty renderer version.
	 */
	public function test_rejects_an_empty_renderer_version(): void {
		$this->expectException( \InvalidArgumentException::class );

		new SeriesSitemapLastmod( '   ' );
	}

	private function module( string $renderer_version = 'renderer-v1' ): SeriesSitemapLastmod {
		return new SeriesSitemapLastmod(
			$renderer_version,
			fn(): int => $this->now,
			function ( string $taxonomy ): void {
				$this->sitemap_invalidations[] = $taxonomy;
			},
			function (): void {
				++$this->sitemap_flushes;
			},
			new SeriesCatalogEligibility( $this->eligibility_database ),
			function (): string {
				++$this->pending_token_counter;
				return 'pending-' . $this->pending_token_counter;
			},
			$this->eligibility_database,
			function ( int $term_id ): void {
				$this->term_page_invalidations[ $term_id ] = $term_id;
			}
		);
	}

	/** Serializes values exactly as the owned option fake stores them. */
	private function serialize_option_value( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return serialize( $value );
		}
		if ( true === $value ) {
			return '1';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/** Restores the array payload written by the owned option fake. */
	private function unserialize_option_value( string $value ) {
		$restored = unserialize( $value, array( 'allowed_classes' => false ) );

		return false === $restored && 'b:0;' !== $value ? $value : $restored;
	}

	/**
	 * Builds one normalized durable retry generation.
	 *
	 * @param array<int,int> $term_taxonomy_ids Dirty term-taxonomy IDs.
	 * @return array<string,mixed>
	 */
	private function pending_payload( string $token, array $term_taxonomy_ids ): array {
		return array(
			'version'           => 1,
			'token'             => $token,
			'full_rescan'       => false,
			'term_ids'          => array(),
			'term_taxonomy_ids' => $term_taxonomy_ids,
			'product_ids'       => array(),
			'guide_post_ids'    => array(),
		);
	}

	/** Builds one row returned by the editorial identity aggregate. */
	private function editorial_row( string $taxonomy, int $term_id, string $name, string $slug, int $series_term_taxonomy_id = 900, int $product_id = 77, string $product_title = 'One Piece 01', string $product_slug = 'one-piece-01', int $thumbnail_id = 700 ): array {
		return array(
			'series_term_taxonomy_id' => $series_term_taxonomy_id,
			'facet_taxonomy'           => $taxonomy,
			'facet_term_id'            => $term_id,
			'facet_name'               => $name,
			'facet_slug'               => $slug,
			'product_id'               => $product_id,
			'product_title'            => $product_title,
			'product_slug'             => $product_slug,
			'product_thumbnail_id'     => $thumbnail_id,
		);
	}

	/** Builds one selected-guide post row. */
	private function guide_row( int $post_id, string $title, string $slug, string $status = 'publish', string $post_type = 'post' ): array {
		return array(
			'ID'          => $post_id,
			'post_type'   => $post_type,
			'post_status' => $status,
			'post_title'  => $title,
			'post_name'   => $slug,
		);
	}

	private function series_term( int $term_id = 900, string $slug = 'one-piece', string $name = 'One Piece' ): object {
		$term              = new \WP_Term( $term_id, $slug, $name );
		$term->taxonomy    = 'pa_serie';
		$term->description = 'La aventura pirata de Eiichiro Oda.';

		return $term;
	}
}
