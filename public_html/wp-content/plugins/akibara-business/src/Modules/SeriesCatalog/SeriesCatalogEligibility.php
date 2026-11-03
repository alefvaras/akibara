<?php
/**
 * Shared catalogue eligibility for series terms.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesCatalog;

use Akibara\Business\Services\VolumeVariant;

defined( 'ABSPATH' ) || exit;

/**
 * Counts the published, catalogue-visible products assigned to each series.
 *
 * @since 1.102.3
 */
final class SeriesCatalogEligibility {

	/** Series product attribute taxonomy. */
	public const TAXONOMY = 'pa_serie';

	/** Native WooCommerce product-visibility taxonomy. */
	public const VISIBILITY_TAXONOMY = 'product_visibility';

	/** Product-visibility term excluded from catalogue archives. */
	public const HIDDEN_FROM_CATALOG = 'exclude-from-catalog';

	/** Brand taxonomy (`product_brand`). */
	public const BRAND_TAXONOMY = 'product_brand';

	/** Country product attribute taxonomy (`pa_pais`). */
	public const COUNTRY_TAXONOMY = 'pa_pais';

	/**
	 * Optional database dependency used by unit tests.
	 *
	 * @var object|null
	 */
	private $database;

	/** @var array<int,int> */
	private array $count_cache = array();

	/**
	 * Tomos por término: los mismos productos, menos los marcados como otra
	 * versión de un número que la serie ya tiene.
	 *
	 * Son DOS cifras y la distinción no es cosmética. `counts()` cuenta PRODUCTOS
	 * y es la que decide si una serie existe para el catálogo —una serie con un
	 * solo producto es elegible aunque ese producto sea una variante—.
	 * `volume_counts()` cuenta TOMOS y es la que se publica cuando el texto dice
	 * «tomos»: el directorio de series y la portada decían 24 donde la landing de
	 * la misma serie dice «Completa en 23 tomos», a un clic de distancia.
	 *
	 * @var array<int,int>
	 */
	private array $volume_cache = array();

	/**
	 * Editorial (`product_brand`) y país (`pa_pais`) por `term_taxonomy_id`,
	 * resueltos en el mismo agregado único que `counts()` y `volume_counts()`.
	 *
	 * @var array<int,array{brand_name:string,brand_slug:string,country_name:string,country_slug:string}>
	 */
	private array $origin_cache = array();

	/** True only after the most recent aggregate completed with a readable result. */
	private bool $reliable = false;

	/**
	 * Creates the eligibility service.
	 *
	 * Passing no database preserves the normal WordPress `$wpdb` runtime path.
	 *
	 * @param object|null $database Optional wpdb-compatible dependency.
	 *
	 * @since 1.102.3
	 */
	public function __construct( ?object $database = null ) {
		$this->database = $database;
	}

	/**
	 * Returns catalogue-visible product counts keyed by term-taxonomy ID.
	 *
	 * Missing IDs are fetched together in one aggregate query. Valid IDs without
	 * eligible products remain present with a zero count.
	 *
	 * @param array<int,mixed> $term_taxonomy_ids Series term-taxonomy IDs.
	 * @return array<int,int> Counts keyed by normalized term-taxonomy ID.
	 *
	 * @since 1.102.3
	 */
	public function counts( array $term_taxonomy_ids ): array {
		return $this->cached_counts( $term_taxonomy_ids, $this->count_cache );
	}

	/**
	 * Returns catalogue-visible VOLUME counts keyed by term-taxonomy ID.
	 *
	 * Same products as `counts()`, minus the ones marked as another version of a
	 * number the series already has. Use this one wherever the published text
	 * says «tomos»; use `counts()` to decide whether the series exists at all.
	 *
	 * @param array<int,mixed> $term_taxonomy_ids Series term-taxonomy IDs.
	 * @return array<int,int> Volume counts keyed by normalized term-taxonomy ID.
	 *
	 * @since 1.116.0
	 */
	public function volume_counts( array $term_taxonomy_ids ): array {
		return $this->cached_counts( $term_taxonomy_ids, $this->volume_cache );
	}

	/**
	 * Returns publisher (`product_brand`) and country (`pa_pais`) metadata keyed
	 * by term-taxonomy ID, populated from the same aggregate query as `counts()`.
	 *
	 * @param array<int,mixed> $term_taxonomy_ids Series term-taxonomy IDs.
	 * @return array<int,array{brand_name:string,brand_slug:string,country_name:string,country_slug:string}>
	 */
	public function origins( array $term_taxonomy_ids ): array {
		$normalized = self::normalize_ids( $term_taxonomy_ids );
		if ( array() === $normalized ) {
			return array();
		}

		$this->cached_counts( $normalized, $this->count_cache );

		$empty = array(
			'brand_name'   => '',
			'brand_slug'   => '',
			'country_name' => '',
			'country_slug' => '',
		);

		$result = array();
		foreach ( $normalized as $term_taxonomy_id ) {
			$result[ $term_taxonomy_id ] = $this->origin_cache[ $term_taxonomy_id ] ?? $empty;
		}

		return $result;
	}

	/**
	 * Returns the catalogue-visible volume count for one series.
	 *
	 * @param int $term_taxonomy_id Series term-taxonomy ID.
	 * @return int Non-negative volume count.
	 *
	 * @since 1.116.0
	 */
	public function volume_count( int $term_taxonomy_id ): int {
		if ( $term_taxonomy_id < 1 ) {
			return 0;
		}

		$counts = $this->volume_counts( array( $term_taxonomy_id ) );

		return $counts[ $term_taxonomy_id ] ?? 0;
	}

	/**
	 * Shared read path for both counters: one aggregate fills them together.
	 *
	 * Both figures come from the SAME query on purpose. Asking for them
	 * separately would double the aggregate and, worse, would let the two answers
	 * be read at different moments and disagree — which is the very defect these
	 * counters exist to avoid.
	 *
	 * @param array<int,mixed> $term_taxonomy_ids Requested IDs.
	 * @param array<int,int>   $cache             Cache to read, by reference.
	 * @return array<int,int>
	 */
	private function cached_counts( array $term_taxonomy_ids, array &$cache ): array {
		$term_taxonomy_ids = self::normalize_ids( $term_taxonomy_ids );
		if ( array() === $term_taxonomy_ids ) {
			return array();
		}

		$missing_ids = array();
		foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
			if ( ! array_key_exists( $term_taxonomy_id, $cache ) ) {
				$missing_ids[] = $term_taxonomy_id;
			}
		}

		if ( array() !== $missing_ids ) {
			$queried = $this->query_counts( $missing_ids );
			if ( null === $queried ) {
				$this->reliable = false;
			} else {
				$this->reliable = true;
				$empty_origin   = array(
					'brand_name'   => '',
					'brand_slug'   => '',
					'country_name' => '',
					'country_slug' => '',
				);
				foreach ( $missing_ids as $term_taxonomy_id ) {
					$this->count_cache[ $term_taxonomy_id ]  = 0;
					$this->volume_cache[ $term_taxonomy_id ] = 0;
					$this->origin_cache[ $term_taxonomy_id ] = $empty_origin;
				}

				foreach ( $queried as $term_taxonomy_id => $row ) {
					$this->count_cache[ $term_taxonomy_id ]  = $row['products'];
					$this->volume_cache[ $term_taxonomy_id ] = $row['volumes'];
					$this->origin_cache[ $term_taxonomy_id ] = array(
						'brand_name'   => $row['brand_name'],
						'brand_slug'   => $row['brand_slug'],
						'country_name' => $row['country_name'],
						'country_slug' => $row['country_slug'],
					);
				}
			}
		}

		$counts = array();
		foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
			$counts[ $term_taxonomy_id ] = $cache[ $term_taxonomy_id ] ?? 0;
		}

		return $counts;
	}

	/**
	 * Returns the catalogue-visible product count for one series.
	 *
	 * @param int $term_taxonomy_id Series term-taxonomy ID.
	 * @return int Non-negative product count.
	 *
	 * @since 1.102.3
	 */
	public function count( int $term_taxonomy_id ): int {
		if ( $term_taxonomy_id < 1 ) {
			return 0;
		}

		$counts = $this->counts( array( $term_taxonomy_id ) );

		return $counts[ $term_taxonomy_id ] ?? 0;
	}

	/**
	 * Determines whether a series has at least one catalogue-visible product.
	 *
	 * @param int $term_taxonomy_id Series term-taxonomy ID.
	 * @return bool True when the series is eligible for public catalogue surfaces.
	 *
	 * @since 1.102.3
	 */
	public function is_eligible( int $term_taxonomy_id ): bool {
		return $this->count( $term_taxonomy_id ) > 0;
	}

	/**
	 * Reports whether the most recent aggregate returned a readable result.
	 *
	 * Consumers that persist SEO state must fail open/no-op when this is false:
	 * an infrastructure error is not evidence that every series became empty.
	 *
	 * @since 1.102.3
	 */
	public function is_reliable(): bool {
		return $this->reliable;
	}

	/**
	 * Resets all cached counts, or only the supplied term-taxonomy IDs.
	 *
	 * @param array<int,mixed>|null $term_taxonomy_ids IDs to reset, or null for all.
	 *
	 * @since 1.102.3
	 */
	public function reset_cache( ?array $term_taxonomy_ids = null ): void {
		$this->reliable = false;
		if ( null === $term_taxonomy_ids ) {
			$this->count_cache  = array();
			$this->volume_cache = array();
			$this->origin_cache = array();
			return;
		}

		foreach ( self::normalize_ids( $term_taxonomy_ids ) as $term_taxonomy_id ) {
			unset(
				$this->count_cache[ $term_taxonomy_id ],
				$this->volume_cache[ $term_taxonomy_id ],
				$this->origin_cache[ $term_taxonomy_id ]
			);
		}
	}

	/**
	 * Loads all missing counts in one aggregate query.
	 *
	 * @param list<int> $term_taxonomy_ids Series term-taxonomy IDs.
	 * @return array<int,array{products:int,volumes:int}>|null Counts, or null when the database result is unreadable.
	 */
	private function query_counts( array $term_taxonomy_ids ): ?array {
		$database = $this->database();
		if (
			null === $database
			|| ! method_exists( $database, 'prepare' )
			|| ! method_exists( $database, 'get_results' )
			|| ! isset( $database->posts, $database->terms, $database->term_taxonomy, $database->term_relationships, $database->postmeta )
		) {
			return null;
		}

		$posts_table        = (string) $database->posts;
		$terms_table        = (string) $database->terms;
		$taxonomy_table     = (string) $database->term_taxonomy;
		$relationships_table = (string) $database->term_relationships;
		$postmeta_table      = (string) $database->postmeta;
		$id_placeholders     = implode( ', ', array_fill( 0, count( $term_taxonomy_ids ), '%d' ) );
		$normal_volume       = VolumeVariant::sql_normal_volume_condition( 'variant_meta.meta_value' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from wpdb and the placeholder list contains only generated `%d` tokens.
		$query = $database->prepare(
			"SELECT
				series_relationship.term_taxonomy_id,
				COUNT(DISTINCT product.ID) AS product_count,
				COUNT(DISTINCT CASE WHEN {$normal_volume} THEN product.ID END) AS volume_count,
				MIN(brand_term.name) AS brand_name,
				MIN(brand_term.slug) AS brand_slug,
				MIN(country_term.name) AS country_name,
				MIN(country_term.slug) AS country_slug
			FROM {$relationships_table} series_relationship
			INNER JOIN {$taxonomy_table} series_taxonomy
				ON series_taxonomy.term_taxonomy_id = series_relationship.term_taxonomy_id
				AND series_taxonomy.taxonomy = %s
			INNER JOIN {$posts_table} product
				ON product.ID = series_relationship.object_id
				AND product.post_type = %s
				AND product.post_status = %s
			LEFT JOIN {$postmeta_table} variant_meta
				ON variant_meta.post_id = product.ID
				AND variant_meta.meta_key = %s
			LEFT JOIN {$relationships_table} brand_relationship
				ON brand_relationship.object_id = product.ID
			LEFT JOIN {$taxonomy_table} brand_taxonomy
				ON brand_taxonomy.term_taxonomy_id = brand_relationship.term_taxonomy_id
				AND brand_taxonomy.taxonomy = 'product_brand'
			LEFT JOIN {$terms_table} brand_term
				ON brand_term.term_id = brand_taxonomy.term_id
			LEFT JOIN {$relationships_table} country_relationship
				ON country_relationship.object_id = product.ID
			LEFT JOIN {$taxonomy_table} country_taxonomy
				ON country_taxonomy.term_taxonomy_id = country_relationship.term_taxonomy_id
				AND country_taxonomy.taxonomy = 'pa_pais'
			LEFT JOIN {$terms_table} country_term
				ON country_term.term_id = country_taxonomy.term_id
			WHERE series_relationship.term_taxonomy_id IN ({$id_placeholders})
				AND NOT EXISTS (
					SELECT 1
					FROM {$relationships_table} hidden_relationship
					INNER JOIN {$taxonomy_table} hidden_taxonomy
						ON hidden_taxonomy.term_taxonomy_id = hidden_relationship.term_taxonomy_id
					INNER JOIN {$terms_table} hidden_term
						ON hidden_term.term_id = hidden_taxonomy.term_id
					WHERE hidden_relationship.object_id = product.ID
						AND hidden_taxonomy.taxonomy = %s
						AND hidden_term.slug = %s
				)
			GROUP BY series_relationship.term_taxonomy_id",
			// Orden TEXTUAL de los placeholders: primero los tipos de variante del
			// `CASE` de la segunda columna, después la taxonomía, el tipo y el
			// estado del post, la clave del meta, los ids y el par de visibilidad.
			...array_merge(
				VolumeVariant::variant_kinds(),
				array( self::TAXONOMY, 'product', 'publish', VolumeVariant::META_KEY ),
				$term_taxonomy_ids,
				array( self::VISIBILITY_TAXONOMY, self::HIDDEN_FROM_CATALOG )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_string( $query ) || '' === $query ) {
			return null;
		}

		$rows = $database->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One request-local cached aggregate; query was prepared above.
		if (
			! is_array( $rows )
			|| ( isset( $database->last_error ) && '' !== trim( (string) $database->last_error ) )
			|| ( isset( $database->ready ) && ! (bool) $database->ready )
			|| ( isset( $database->last_query ) && (string) $database->last_query !== $query )
		) {
			return null;
		}

		$requested = array_fill_keys( $term_taxonomy_ids, true );
		$counts    = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$term_taxonomy_id = self::normalize_id( $row['term_taxonomy_id'] ?? null );
			if ( $term_taxonomy_id < 1 || ! isset( $requested[ $term_taxonomy_id ] ) ) {
				continue;
			}

			$counts[ $term_taxonomy_id ] = array(
				'products'     => max( 0, (int) ( $row['product_count'] ?? 0 ) ),
				'volumes'      => max( 0, (int) ( $row['volume_count'] ?? 0 ) ),
				'brand_name'   => isset( $row['brand_name'] ) && is_string( $row['brand_name'] ) ? trim( $row['brand_name'] ) : '',
				'brand_slug'   => isset( $row['brand_slug'] ) && is_string( $row['brand_slug'] ) ? trim( $row['brand_slug'] ) : '',
				'country_name' => isset( $row['country_name'] ) && is_string( $row['country_name'] ) ? trim( $row['country_name'] ) : '',
				'country_slug' => isset( $row['country_slug'] ) && is_string( $row['country_slug'] ) ? trim( $row['country_slug'] ) : '',
			);
		}

		return $counts;
	}

	/** Returns the injected database or the normal WordPress global. */
	private function database(): ?object {
		if ( null !== $this->database ) {
			return $this->database;
		}

		global $wpdb;

		return isset( $wpdb ) && is_object( $wpdb ) ? $wpdb : null;
	}

	/**
	 * Normalizes and deduplicates positive term-taxonomy IDs in input order.
	 *
	 * @param array<int,mixed> $values Raw IDs.
	 * @return list<int>
	 */
	private static function normalize_ids( array $values ): array {
		$ids = array();
		foreach ( $values as $value ) {
			$id = self::normalize_id( $value );
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/** Normalizes one positive integer ID. */
	private static function normalize_id( $value ): int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return 0;
		}

		$id = (int) $value;

		return $id > 0 ? $id : 0;
	}
}
