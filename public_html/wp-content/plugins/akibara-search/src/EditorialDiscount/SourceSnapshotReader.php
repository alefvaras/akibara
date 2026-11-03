<?php
declare( strict_types=1 );

namespace Akibara\Search\EditorialDiscount;

use Akibara\Search\EditorialDiscountPublication;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Domain service responsible for reading raw database rows, validating
 * option schemas, parsing timezones, and constructing authoritative source snapshots.
 *
 * All operations bypass WordPress object cache to guarantee physical consistency
 * during snapshot adoption and index publication.
 */
final class SourceSnapshotReader {

	/**
	 * Constructs the canonical source snapshot from parsed options and database metadata.
	 *
	 * @param array<string, array{present: bool, raw: string}> $options
	 * @param array<string, mixed>|null                        $database_source
	 * @return array<string, mixed>|null
	 */
	public static function source_snapshot_from_options( array $options, ?array $database_source = null ): ?array {
		$options = self::canonical_source_options( $options );
		$meta    = null === $database_source ? self::raw_term_meta_rows() : ( $database_source['meta'] ?? null );
		$taxes   = null === $database_source
			? self::effective_taxonomies()
			: self::effective_taxonomies_from_attribute_rows( $database_source['attributes'] ?? null );
		$provider = self::provider_descriptor();
		$timezone = is_array( $options ) ? self::effective_timezone( $options ) : null;

		if ( ! is_array( $options ) || ! is_array( $meta ) || ! is_array( $taxes )
			|| ! self::source_options_are_scalar( $options )
			|| ! is_array( $provider ) || ! is_array( $timezone ) ) {
			EditorialDiscountPublication::mark_read_unavailable( 'Editorial source snapshot is not authoritative.' );
			return null;
		}

		if ( ! self::validate_provider_contract( $provider, $options ) ) {
			return null;
		}

		$pricing = self::resolve_effective_pricing_parameters( $options );
		if ( null === $pricing ) {
			return null;
		}

		$enabled = self::option_effective( $options, EditorialDiscountPublication::OPTION_ENABLED, 'no' );
		$from    = self::option_effective( $options, EditorialDiscountPublication::OPTION_FROM, '' );
		$to      = self::option_effective( $options, EditorialDiscountPublication::OPTION_TO, '' );
		$window  = self::window_snapshot( $enabled, $from, $to, $timezone['zone'] );
		if ( ! is_array( $window ) ) {
			return null;
		}

		$payload = array(
			'protocol'       => EditorialDiscountPublication::PROTOCOL,
			'contract'       => EditorialDiscountPublication::CONTRACT,
			'options'        => $options,
			'term_meta'      => $meta,
			'window_active'  => $window['active'],
			'max_pct'        => $pricing['max_pct'],
			'price_decimals' => $pricing['decimals'],
			'taxonomies'     => $taxes,
			'provider'       => $provider,
			'timezone'       => $timezone['signature'],
		);

		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return null;
		}

		return array_merge(
			$payload,
			array(
				'fingerprint' => hash( 'sha256', $encoded ),
				'boundary_at' => $window['boundary_at'],
			)
		);
	}

	/**
	 * Validates contract compliance between search and the effective price provider.
	 *
	 * @param array<string, mixed>                             $provider
	 * @param array<string, array{present: bool, raw: string}> $options
	 * @return bool
	 */
	private static function validate_provider_contract( array $provider, array $options ): bool {
		$provider_absent = 'provider_absent' === (string) ( $provider['kind'] ?? '' );
		$enabled         = self::option_effective( $options, EditorialDiscountPublication::OPTION_ENABLED, 'no' );
		$feed_present    = isset( $options[ EditorialDiscountPublication::OPTION_FEED ] )
			&& $options[ EditorialDiscountPublication::OPTION_FEED ]['present'];

		if ( $provider_absent && ( 'yes' === $enabled || $feed_present ) ) {
			EditorialDiscountPublication::mark_read_unavailable( 'Editorial price provider is missing for an active Business contract.' );
			return false;
		}

		if ( ! $provider_absent
			&& ( ! isset( $options[ EditorialDiscountPublication::OPTION_FEED ] )
				|| ! $options[ EditorialDiscountPublication::OPTION_FEED ]['present']
				|| EditorialDiscountPublication::REQUIRED_FEED_CONTRACT !== self::decoded_option_string( (string) $options[ EditorialDiscountPublication::OPTION_FEED ]['raw'] ) ) ) {
			EditorialDiscountPublication::mark_read_unavailable( 'Editorial feed contract is missing or incompatible.' );
			return false;
		}

		return true;
	}

	/**
	 * Resolves clamped max percentage and WooCommerce price decimals.
	 *
	 * @param array<string, array{present: bool, raw: string}> $options
	 * @return array{max_pct: int, decimals: int}|null
	 */
	private static function resolve_effective_pricing_parameters( array $options ): ?array {
		try {
			$max_pct_default_raw = self::option_effective( $options, EditorialDiscountPublication::OPTION_MAX_PCT, '15' );
			$max_pct_default     = self::canonical_filter_integer( $max_pct_default_raw );
			$max_pct_default     = null === $max_pct_default ? 15 : max( 1, min( 90, $max_pct_default ) );

			$max_pct_raw = apply_filters( 'akibara_taxonomy_discount_max_pct', $max_pct_default );
			$max_pct     = self::canonical_filter_integer( $max_pct_raw );
			if ( null === $max_pct ) {
				return null;
			}
			$max_pct = max( 1, min( 90, $max_pct ) );

			$raw_decimals = self::option_effective( $options, EditorialDiscountPublication::OPTION_DECIMALS, '2' );
			if ( 1 !== preg_match( '/^(?:0|[1-8])$/D', $raw_decimals ) ) {
				return null;
			}

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce native filter.
			$decimals = self::canonical_filter_integer( apply_filters( 'wc_get_price_decimals', (int) $raw_decimals ) );
		} catch ( Throwable $throwable ) {
			return null;
		}

		if ( null === $decimals || $decimals < 0 || $decimals > 8 ) {
			return null;
		}

		return array(
			'max_pct'  => $max_pct,
			'decimals' => $decimals,
		);
	}

	/**
	 * Reads editorial termmeta and WooCommerce attributes in a single database query.
	 *
	 * @return array{meta: array<int, array{meta_id: int, term_id: int, raw: string}>, attributes: array<int, array{id: int, name: string, label: string}>}|null
	 */
	public static function raw_editorial_database_source(): ?array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->termmeta, $wpdb->prefix ) || ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'get_results' ) ) {
			EditorialDiscountPublication::mark_read_unavailable( 'Editorial database source is unavailable.' );
			return null;
		}

		$attributes = (string) $wpdb->prefix . 'woocommerce_attribute_taxonomies';
		try {
			$wpdb->last_error = '';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$wpdb->termmeta} and {$attributes} are table names derived internally.
			$sql = $wpdb->prepare(
				"SELECT 'meta' source_kind, meta_id source_id, term_id parent_id, meta_value value_one, '' value_two
				FROM {$wpdb->termmeta} WHERE meta_key = %s
				UNION ALL
				SELECT 'attribute' source_kind, attribute_id source_id, 0 parent_id, attribute_name value_one, attribute_label value_two
				FROM {$attributes}
				ORDER BY source_kind ASC, parent_id ASC, source_id ASC",
				EditorialDiscountPublication::META_KEY
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above.
			$rows = is_string( $sql ) ? $wpdb->get_results( $sql ) : null;
		} catch ( Throwable $throwable ) {
			EditorialDiscountPublication::mark_read_unavailable( $throwable->getMessage() ?: 'Editorial database source read threw.' );
			return null;
		}

		if ( ! is_array( $rows ) || '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			EditorialDiscountPublication::mark_read_unavailable( 'Editorial database source read failed.' );
			return null;
		}

		return self::parse_database_source_rows( $rows );
	}

	/**
	 * Parses rows returned by the combined database query.
	 *
	 * @param array<int, mixed> $rows
	 * @return array{meta: array<int, array{meta_id: int, term_id: int, raw: string}>, attributes: array<int, array{id: int, name: string, label: string}>}|null
	 */
	private static function parse_database_source_rows( array $rows ): ?array {
		$out = array(
			'meta'       => array(),
			'attributes' => array(),
		);

		foreach ( $rows as $row ) {
			$kind   = is_object( $row ) ? ( $row->source_kind ?? null ) : null;
			$id     = is_object( $row ) ? ( $row->source_id ?? null ) : null;
			$parent = is_object( $row ) ? ( $row->parent_id ?? null ) : null;
			$one    = is_object( $row ) ? ( $row->value_one ?? null ) : null;
			$two    = is_object( $row ) ? ( $row->value_two ?? null ) : null;

			if ( ! is_string( $kind ) || ! self::canonical_positive_int( $id )
				|| ! self::canonical_nonnegative_int( $parent ) || ! is_string( $one ) || ! is_string( $two ) ) {
				EditorialDiscountPublication::mark_read_unavailable( 'Editorial database source rows have an invalid shape.' );
				return null;
			}

			if ( 'meta' === $kind && (int) $parent > 0 && '' === $two ) {
				$out['meta'][] = array(
					'meta_id' => (int) $id,
					'term_id' => (int) $parent,
					'raw'     => $one,
				);
			} elseif ( 'attribute' === $kind && 0 === (int) $parent ) {
				$out['attributes'][] = array(
					'id'    => (int) $id,
					'name'  => $one,
					'label' => $two,
				);
			} else {
				EditorialDiscountPublication::mark_read_unavailable( 'Editorial database source row kind is invalid.' );
				return null;
			}
		}

		return $out;
	}

	/**
	 * Reads all guarded options directly from the database table in a single query.
	 *
	 * @return array<string, array{present: bool, raw: string}>|null
	 */
	public static function raw_source_options(): ?array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options )
			|| ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			EditorialDiscountPublication::mark_read_unavailable( 'Editorial option source is unavailable.' );
			return null;
		}

		$names = EditorialDiscountPublication::options();
		$marks = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
		try {
			$wpdb->last_error = '';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $marks matches count($names).
			$sql = $wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ({$marks}) ORDER BY option_name ASC",
				...$names
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above.
			$rows = is_string( $sql ) ? $wpdb->get_results( $sql ) : null;
		} catch ( Throwable $throwable ) {
			EditorialDiscountPublication::mark_read_unavailable( $throwable->getMessage() ?: 'Editorial option source read threw.' );
			return null;
		}

		if ( ! is_array( $rows ) || '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			EditorialDiscountPublication::mark_read_unavailable( 'Editorial option source read failed.' );
			return null;
		}

		$found = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! is_string( $row->option_name ?? null ) || ! is_string( $row->option_value ?? null ) ) {
				EditorialDiscountPublication::mark_read_unavailable( 'Editorial option rows have an invalid shape.' );
				return null;
			}
			$found[ $row->option_name ] = $row->option_value;
		}

		$out = array();
		foreach ( $names as $name ) {
			$present       = array_key_exists( $name, $found );
			$out[ $name ] = array(
				'present' => $present,
				'raw'     => $present ? $found[ $name ] : '',
			);
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Reads all editorial term metadata rows directly from wp_termmeta.
	 *
	 * @return array<int, array{meta_id: int, term_id: int, raw: string}>|null
	 */
	public static function raw_term_meta_rows(): ?array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->termmeta ) || ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'get_results' ) ) {
			EditorialDiscountPublication::mark_read_unavailable( 'Editorial termmeta is unavailable.' );
			return null;
		}

		try {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$wpdb->termmeta} is table name.
			$sql = $wpdb->prepare(
				"SELECT meta_id, term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s ORDER BY term_id ASC, meta_id ASC",
				EditorialDiscountPublication::META_KEY
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above.
			$rows = is_string( $sql ) ? $wpdb->get_results( $sql ) : null;
		} catch ( Throwable $throwable ) {
			EditorialDiscountPublication::mark_read_unavailable( $throwable->getMessage() ?: 'Editorial termmeta read threw.' );
			return null;
		}

		if ( ! is_array( $rows ) || '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			EditorialDiscountPublication::mark_read_unavailable( 'Editorial termmeta read failed.' );
			return null;
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! self::canonical_positive_int( $row->meta_id ?? null )
				|| ! self::canonical_positive_int( $row->term_id ?? null ) || ! is_string( $row->meta_value ?? null ) ) {
				EditorialDiscountPublication::mark_read_unavailable( 'Editorial termmeta rows have an invalid shape.' );
				return null;
			}
			$out[] = array(
				'meta_id' => (int) $row->meta_id,
				'term_id' => (int) $row->term_id,
				'raw'     => (string) $row->meta_value,
			);
		}
		return $out;
	}

	/**
	 * Reads a single term metadata row directly from wp_termmeta by meta_id.
	 *
	 * @param int $meta_id
	 * @return array<string, mixed>|null
	 */
	public static function term_meta_row( int $meta_id ): ?array {
		global $wpdb;
		EditorialDiscountPublication::set_term_meta_row_failed( false );
		if ( $meta_id <= 0 || ! isset( $wpdb->termmeta ) ) {
			EditorialDiscountPublication::set_term_meta_row_failed( true );
			return null;
		}

		try {
			$wpdb->last_error = '';
			$sql              = $wpdb->prepare(
				"SELECT meta_id, term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_id = %d",
				$meta_id
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above.
			$row = is_string( $sql ) ? $wpdb->get_row( $sql ) : null;
		} catch ( Throwable $throwable ) {
			EditorialDiscountPublication::set_term_meta_row_failed( true );
			EditorialDiscountPublication::mark_read_unavailable( $throwable->getMessage() ?: 'Editorial termmeta row read threw.' );
			return null;
		}

		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			EditorialDiscountPublication::set_term_meta_row_failed( true );
			EditorialDiscountPublication::mark_read_unavailable( 'Editorial termmeta row read failed.' );
			return null;
		}

		if ( ! is_object( $row ) ) {
			return null;
		}

		return array(
			'meta_id'    => (int) ( $row->meta_id ?? 0 ),
			'term_id'    => (int) ( $row->term_id ?? 0 ),
			'meta_key'   => (string) ( $row->meta_key ?? '' ),
			'meta_value' => (string) ( $row->meta_value ?? '' ),
		);
	}

	/**
	 * Resolves effective taxonomies configured for editorial discounts.
	 *
	 * @return array<int, string>|null
	 */
	public static function effective_taxonomies(): ?array {
		if ( ! function_exists( 'taxonomy_exists' ) ) {
			return null;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) || ! method_exists( $wpdb, 'get_results' ) ) {
			EditorialDiscountPublication::mark_read_unavailable( 'WooCommerce attribute source is unavailable.' );
			return null;
		}

		$table = (string) $wpdb->prefix . 'woocommerce_attribute_taxonomies';
		try {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from wpdb prefix.
			$attributes = $wpdb->get_results( "SELECT attribute_name, attribute_label FROM {$table} ORDER BY attribute_id ASC" );
		} catch ( Throwable $throwable ) {
			EditorialDiscountPublication::mark_read_unavailable( $throwable->getMessage() ?: 'WooCommerce attribute read threw.' );
			return null;
		}

		if ( ! is_array( $attributes ) || '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			EditorialDiscountPublication::mark_read_unavailable( 'WooCommerce attribute read failed.' );
			return null;
		}

		$rows = array();
		foreach ( $attributes as $index => $attribute ) {
			if ( ! is_object( $attribute ) || ! is_string( $attribute->attribute_name ?? null )
				|| ! is_string( $attribute->attribute_label ?? null ) ) {
				EditorialDiscountPublication::mark_read_unavailable( 'WooCommerce attribute rows have an invalid shape.' );
				return null;
			}
			$rows[] = array(
				'id'    => $index + 1,
				'name'  => (string) $attribute->attribute_name,
				'label' => (string) $attribute->attribute_label,
			);
		}
		return self::effective_taxonomies_from_attribute_rows( $rows );
	}

	/**
	 * Normalizes taxonomy list from attribute rows and filters.
	 *
	 * @param mixed $attributes
	 * @return array<int, string>|null
	 */
	public static function effective_taxonomies_from_attribute_rows( $attributes ): ?array {
		if ( ! is_array( $attributes ) || ! function_exists( 'taxonomy_exists' ) ) {
			return null;
		}

		$map = array();
		foreach ( array( 'product_brand' => 'Editoriales', 'product_cat' => 'Categorías', 'product_tag' => 'Etiquetas' ) as $slug => $label ) {
			if ( taxonomy_exists( $slug ) ) {
				$map[ $slug ] = $label;
			}
		}

		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) || ! isset( $attribute['id'], $attribute['name'], $attribute['label'] )
				|| ! is_int( $attribute['id'] ) || $attribute['id'] <= 0
				|| ! is_string( $attribute['name'] ) || ! is_string( $attribute['label'] ) ) {
				return null;
			}
			$name = trim( $attribute['name'] );
			if ( '' === $name ) {
				EditorialDiscountPublication::mark_read_unavailable( 'WooCommerce attribute name is invalid.' );
				return null;
			}
			$slug = 'pa_' . $name;
			if ( taxonomy_exists( $slug ) ) {
				$map[ $slug ] = '' !== trim( $attribute['label'] ) ? $attribute['label'] : $slug;
			}
		}

		try {
			$filtered = apply_filters( 'akibara_taxonomy_discount_taxonomies', $map );
		} catch ( Throwable $throwable ) {
			return null;
		}

		if ( ! is_array( $filtered ) ) {
			return null;
		}

		$slugs = array();
		foreach ( array_keys( $filtered ) as $slug ) {
			if ( ! is_string( $slug ) || '' === trim( $slug ) ) {
				return null;
			}
			$slugs[] = trim( $slug );
		}

		$slugs = array_values( array_unique( $slugs, SORT_STRING ) );
		sort( $slugs, SORT_STRING );
		return $slugs;
	}

	/**
	 * Describes the active price provider contract.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function provider_descriptor(): ?array {
		$provider = self::resolve_price_provider();
		if ( false === $provider ) {
			return null;
		}
		if ( null === $provider ) {
			return array( 'kind' => 'provider_absent' );
		}
		return array(
			'kind'          => 'akibara_business_search_price_provider_v2',
			'class'         => get_class( $provider ),
			'feed_contract' => EditorialDiscountPublication::REQUIRED_FEED_CONTRACT,
			'priority'      => 10,
			'accepted_args' => 2,
		);
	}

	/**
	 * Resolves registered pricing provider callback on akb_search_effective_price.
	 *
	 * @return object|null|false Provider object, null for valid absence, or false for invalid hook shape.
	 */
	public static function resolve_price_provider() {
		global $wp_filter;
		$hook = $wp_filter['akb_search_effective_price'] ?? null;
		if ( null === $hook ) {
			return null;
		}

		$callbacks = is_object( $hook ) && isset( $hook->callbacks ) && is_array( $hook->callbacks )
			? $hook->callbacks
			: ( is_array( $hook ) ? $hook : null );
		if ( ! is_array( $callbacks ) ) {
			return false;
		}

		$flat = array();
		foreach ( $callbacks as $priority => $rows ) {
			if ( ! is_array( $rows ) ) {
				return false;
			}
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['function'] ) ) {
					return false;
				}
				$flat[] = array(
					'priority' => (int) $priority,
					'row'      => $row,
				);
			}
		}

		if ( array() === $flat ) {
			return null;
		}

		if ( 1 !== count( $flat ) || 10 !== $flat[0]['priority']
			|| 2 !== (int) ( $flat[0]['row']['accepted_args'] ?? 0 ) ) {
			return false;
		}

		$callable = $flat[0]['row']['function'];
		if ( ! is_object( $callable )
			|| 'Akibara\\Business\\Modules\\TaxonomyDiscount\\SearchPriceProvider' !== get_class( $callable )
			|| ! is_callable( $callable )
			|| ! is_callable( array( $callable, 'adopt_search_snapshot' ) )
			|| ! is_callable( array( $callable, 'snapshot_is_fresh' ) )
			|| ! is_callable( array( $callable, 'authoritative_snapshot' ) ) ) {
			return false;
		}

		return $callable;
	}

	/**
	 * Extracts price snapshot payload for provider consumption.
	 *
	 * @param array<string, mixed> $source
	 * @return array<string, mixed>|null
	 */
	public static function price_snapshot_from_source( array $source ): ?array {
		$required = array( 'fingerprint', 'options', 'timezone', 'window_active', 'boundary_at', 'max_pct', 'price_decimals', 'taxonomies', 'term_meta' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $source ) ) {
				return null;
			}
		}

		if ( ! is_string( $source['fingerprint'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source['fingerprint'] )
			|| ! is_array( $source['options'] ) || ! is_string( $source['timezone'] )
			|| ! is_bool( $source['window_active'] )
			|| ( null !== $source['boundary_at'] && ! is_int( $source['boundary_at'] ) )
			|| ! is_int( $source['max_pct'] ) || ! is_int( $source['price_decimals'] )
			|| ! is_array( $source['taxonomies'] ) || ! is_array( $source['term_meta'] ) ) {
			return null;
		}

		return array(
			'contract'           => 'akibara_search_price_snapshot_v1',
			'source_fingerprint' => $source['fingerprint'],
			'options'            => $source['options'],
			'timezone'           => $source['timezone'],
			'window_active'      => $source['window_active'],
			'boundary_at'        => $source['boundary_at'],
			'max_pct'            => $source['max_pct'],
			'decimals'           => $source['price_decimals'],
			'taxonomies'         => $source['taxonomies'],
			'term_meta'          => $source['term_meta'],
		);
	}

	/**
	 * Evaluates date window and nearest campaign boundary timestamp.
	 *
	 * @param string       $enabled
	 * @param string       $from
	 * @param string       $to
	 * @param DateTimeZone $timezone
	 * @return array{active: bool, boundary_at: int|null}|null
	 */
	public static function window_snapshot( string $enabled, string $from, string $to, DateTimeZone $timezone ): ?array {
		try {
			$now = new DateTimeImmutable( 'now', $timezone );
			if ( ! $now instanceof \DateTimeInterface ) {
				return null;
			}

			$today = $now->format( 'Y-m-d' );
			if ( ( '' !== $from && ! self::valid_date( $from, $timezone ) )
				|| ( '' !== $to && ! self::valid_date( $to, $timezone ) ) ) {
				return null;
			}

			$active = 'yes' === $enabled
				&& ( '' === $from || $today >= $from )
				&& ( '' === $to || $today <= $to );

			$boundaries = array();
			if ( 'yes' === $enabled && '' !== $from ) {
				$from_at = new DateTimeImmutable( $from . ' 00:00:00', $timezone );
				if ( $from_at->getTimestamp() > $now->getTimestamp() ) {
					$boundaries[] = $from_at->getTimestamp();
				}
			}

			if ( 'yes' === $enabled && '' !== $to ) {
				$next_day = ( new DateTimeImmutable( $to, new DateTimeZone( 'UTC' ) ) )->modify( '+1 day' );
				$to_at    = new DateTimeImmutable( $next_day->format( 'Y-m-d' ) . ' 00:00:00', $timezone );
				if ( $to_at->getTimestamp() > $now->getTimestamp() ) {
					$boundaries[] = $to_at->getTimestamp();
				}
			}

			return array(
				'active'      => $active,
				'boundary_at' => array() === $boundaries ? null : min( $boundaries ),
			);
		} catch ( Throwable $throwable ) {
			return null;
		}
	}

	/**
	 * Computes effective DateTimeZone object and string signature from options.
	 *
	 * @param array<string, array{present: bool, raw: string}> $options
	 * @return array{zone: DateTimeZone, signature: string}|null
	 */
	public static function effective_timezone( array $options ): ?array {
		try {
			$string = self::option_effective( $options, EditorialDiscountPublication::OPTION_TIMEZONE, '' );
			if ( '' === $string ) {
				$raw_offset = self::option_effective( $options, EditorialDiscountPublication::OPTION_GMT_OFFSET, '0' );
				if ( ! self::valid_source_option_value( EditorialDiscountPublication::OPTION_GMT_OFFSET, $raw_offset ) ) {
					return null;
				}
				$offset  = (float) $raw_offset;
				$hours   = (int) $offset;
				$minutes = $offset - $hours;
				$string  = sprintf(
					'%s%02d:%02d',
					$offset < 0 ? '-' : '+',
					abs( $hours ),
					abs( (int) round( $minutes * 60 ) )
				);
			}

			$zone = new DateTimeZone( $string );
			if ( '' === $string ) {
				return null;
			}

			return array(
				'zone'      => $zone,
				'signature' => $string,
			);
		} catch ( Throwable $throwable ) {
			return null;
		}
	}

	/**
	 * Validates ISO date format Y-m-d against the store timezone.
	 *
	 * @param string       $date
	 * @param DateTimeZone $timezone
	 * @return bool
	 */
	public static function valid_date( string $date, DateTimeZone $timezone ): bool {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
		$errors = DateTimeImmutable::getLastErrors();
		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $date
			&& ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) );
	}

	/**
	 * Decodes raw option value if present or returns default.
	 *
	 * @param array<string, array{present: bool, raw: string}> $options
	 * @param string                                           $name
	 * @param string                                           $default
	 * @return string
	 */
	public static function option_effective( array $options, string $name, string $default ): string {
		return isset( $options[ $name ] ) && $options[ $name ]['present']
			? self::decoded_option_string( $options[ $name ]['raw'] )
			: $default;
	}

	/**
	 * Decodes string scalar from raw option bytes.
	 *
	 * @param string $raw
	 * @return string
	 */
	public static function decoded_option_string( string $raw ): string {
		$decoded = self::decode_raw_scalar( $raw );
		return $decoded['valid'] ? (string) $decoded['value'] : '';
	}

	/**
	 * Validates that all options in the bundle are scalar values conforming to domain schemas.
	 *
	 * @param array<string, array{present: bool, raw: string}> $options
	 * @return bool
	 */
	public static function source_options_are_scalar( array $options ): bool {
		$decoded_options = array();
		$valid_names     = EditorialDiscountPublication::options();

		foreach ( $options as $name => $row ) {
			if ( ! is_array( $row ) || ! array_key_exists( 'present', $row ) || ! array_key_exists( 'raw', $row )
				|| ! is_string( $name ) || ! in_array( $name, $valid_names, true )
				|| ! is_bool( $row['present'] ) || ! is_string( $row['raw'] ) ) {
				return false;
			}
			if ( ! $row['present'] ) {
				continue;
			}

			$decoded = self::decode_raw_scalar( $row['raw'] );
			if ( ! $decoded['valid'] ) {
				return false;
			}
			$decoded_options[ $name ] = (string) $decoded['value'];

			$uses_legacy_empty_default = '' === $row['raw']
				&& in_array( $name, array( EditorialDiscountPublication::OPTION_ENABLED, EditorialDiscountPublication::OPTION_APPLY_TO, EditorialDiscountPublication::OPTION_SALE_POLICY ), true );

			if ( EditorialDiscountPublication::OPTION_GMT_OFFSET !== $name && ! $uses_legacy_empty_default
				&& ! self::valid_source_option_value( $name, $decoded_options[ $name ] ) ) {
				return false;
			}
		}

		$timezone = $decoded_options[ EditorialDiscountPublication::OPTION_TIMEZONE ] ?? '';
		if ( '' === $timezone && isset( $decoded_options[ EditorialDiscountPublication::OPTION_GMT_OFFSET ] )
			&& ! self::valid_source_option_value( EditorialDiscountPublication::OPTION_GMT_OFFSET, $decoded_options[ EditorialDiscountPublication::OPTION_GMT_OFFSET ] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Decodes serialized or literal scalar values.
	 *
	 * @param string $raw
	 * @return array{valid: bool, value: scalar|null}
	 */
	public static function decode_raw_scalar( string $raw ): array {
		$decoded          = self::decode_raw_value( $raw );
		$decoded['valid'] = $decoded['valid'] && ( is_scalar( $decoded['value'] ) || null === $decoded['value'] );
		return $decoded;
	}

	/**
	 * Safe unserialization for option values without class instantiation.
	 *
	 * @param string $raw
	 * @return array{valid: bool, value: mixed}
	 */
	public static function decode_raw_value( string $raw ): array {
		if ( 'N;' === $raw ) {
			return array(
				'valid' => true,
				'value' => null,
			);
		}
		if ( 1 === preg_match( '/^(?:a|O|C|s|i|d|b):/', $raw ) ) {
			$value = @unserialize( $raw, array( 'allowed_classes' => false ) );
			return array(
				'valid' => ( false !== $value || 'b:0;' === $raw )
					&& serialize( $value ) === $raw
					&& ! is_object( $value ),
				'value' => $value,
			);
		}
		return array(
			'valid' => true,
			'value' => $raw,
		);
	}

	/**
	 * Enforces strict domain validation for all editorial options.
	 *
	 * @param string $name
	 * @param string $value
	 * @return bool
	 */
	public static function valid_source_option_value( string $name, string $value ): bool {
		return self::valid_editorial_policy_option( $name, $value )
			|| self::valid_technical_option( $name, $value );
	}

	/**
	 * Validates editorial policy options (enabled, dates, policy, feed).
	 *
	 * @param string $name
	 * @param string $value
	 * @return bool
	 */
	private static function valid_editorial_policy_option( string $name, string $value ): bool {
		if ( EditorialDiscountPublication::OPTION_ENABLED === $name ) {
			return in_array( $value, array( 'yes', 'no' ), true );
		}
		if ( EditorialDiscountPublication::OPTION_FROM === $name || EditorialDiscountPublication::OPTION_TO === $name ) {
			return '' === $value || 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value );
		}
		if ( EditorialDiscountPublication::OPTION_APPLY_TO === $name ) {
			return in_array( $value, array( 'instock_only', 'instock_and_onbackorder', 'all' ), true );
		}
		if ( EditorialDiscountPublication::OPTION_SALE_POLICY === $name ) {
			return in_array( $value, array( 'greatest_wins', 'skip_if_on_sale', 'from_regular', 'stack_on_sale' ), true );
		}
		if ( EditorialDiscountPublication::OPTION_FEED === $name ) {
			return EditorialDiscountPublication::REQUIRED_FEED_CONTRACT === $value;
		}
		return false;
	}

	/**
	 * Validates technical configuration options (max percentage, decimals, timezone).
	 *
	 * @param string $name
	 * @param string $value
	 * @return bool
	 */
	private static function valid_technical_option( string $name, string $value ): bool {
		if ( EditorialDiscountPublication::OPTION_MAX_PCT === $name ) {
			if ( 1 !== preg_match( '/^(?:[1-9]|[1-8]\d|90)$/D', $value ) ) {
				return false;
			}
			$pct = (int) $value;
			return $pct >= 1 && $pct <= 90;
		}
		if ( EditorialDiscountPublication::OPTION_DECIMALS === $name ) {
			return 1 === preg_match( '/^(?:0|[1-8])$/D', $value );
		}
		if ( EditorialDiscountPublication::OPTION_TIMEZONE === $name ) {
			return strlen( $value ) <= 100 && false === strpos( $value, "\0" );
		}
		if ( EditorialDiscountPublication::OPTION_GMT_OFFSET === $name ) {
			if ( '' === $value || 1 !== preg_match( '/^-?(?:0|[1-9]\d*)(?:\.(?:25|5|75))?$/D', $value ) ) {
				return false;
			}
			$offset = (float) $value;
			if ( str_starts_with( $value, '-' ) && 0.0 === $offset ) {
				return false;
			}
			return is_finite( $offset ) && $offset >= -14.0 && $offset <= 14.0
				&& abs( $offset * 4 - round( $offset * 4 ) ) < 0.000001;
		}
		return false;
	}

	/**
	 * Sorts and validates canonical options dictionary.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, array{present: bool, raw: string}>|null
	 */
	public static function canonical_source_options( array $options ): ?array {
		$names = EditorialDiscountPublication::options();
		if ( count( $options ) !== count( $names ) ) {
			return null;
		}

		$out = array();
		foreach ( $names as $name ) {
			if ( ! array_key_exists( $name, $options ) || ! is_array( $options[ $name ] ) ) {
				return null;
			}
			$out[ $name ] = $options[ $name ];
		}

		ksort( $out, SORT_STRING );
		return self::source_options_are_scalar( $out ) ? $out : null;
	}

	/**
	 * Validates positive non-zero integer representation.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	public static function canonical_positive_int( $value ): bool {
		return ( is_int( $value ) && $value > 0 )
			|| ( is_string( $value ) && 1 === preg_match( '/^[1-9]\d*$/D', $value ) );
	}

	/**
	 * Validates non-negative integer representation including zero.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	public static function canonical_nonnegative_int( $value ): bool {
		return ( is_int( $value ) && $value >= 0 )
			|| ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9]\d*)$/D', $value ) );
	}

	/**
	 * Validates and converts integer pre-cast filter values.
	 *
	 * @param mixed $value
	 * @return int|null
	 */
	public static function canonical_filter_integer( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^-?(?:0|[1-9]\d*)$/D', $value ) || '-0' === $value ) {
			return null;
		}
		$integer = (int) $value;
		return (string) $integer === $value ? $integer : null;
	}
}
