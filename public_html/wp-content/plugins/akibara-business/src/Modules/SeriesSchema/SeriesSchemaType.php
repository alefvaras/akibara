<?php
/**
 * Explicit schema classification for `pa_serie` terms.
 *
 * Product categories are not a reliable content-type source: a novel can be
 * catalogued under Manga/Shonen for merchandising without becoming a comic.
 * `_akb_serie_schema_type` is therefore the reusable source of truth for schema
 * classification. Existing terms with no value retain the historical
 * `ComicSeries` behavior; once a value is stored, only the exact allowlisted
 * `comic_series` value opts in and every unknown value fails closed.
 *
 * Operational values:
 *   - `comic_series`: explicit ComicSeries.
 *   - `non_comic`: keep the public archive/CollectionPage, but never identify
 *     the term or its products as ComicSeries.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves whether one `pa_serie` term may emit `ComicSeries` semantics.
 *
 * @since 1.108.8
 */
final class SeriesSchemaType {

	/** Durable term-meta key that owns the schema classification. */
	public const META_KEY = '_akb_serie_schema_type';

	/** Explicit value for a comic or manga series. */
	public const COMIC_SERIES = 'comic_series';

	/** Explicit value for novels and any other non-comic work. */
	public const NON_COMIC = 'non_comic';

	/** This class only exposes static classification policy. */
	private function __construct() {}

	/**
	 * Whether a term may be represented as a schema.org `ComicSeries`.
	 *
	 * An empty value preserves the pre-1.108.8 catalogue contract. Invalid IDs,
	 * unreadable metadata and unrecognized stored values fail closed so a typo
	 * cannot manufacture the wrong creative-work type.
	 *
	 * @param int $term_id `pa_serie` term ID.
	 * @return bool
	 *
	 * @since 1.108.8
	 */
	public static function supports_comic_series( int $term_id ): bool {
		if ( $term_id < 1 || ! function_exists( 'get_term_meta' ) ) {
			return false;
		}

		$value = get_term_meta( $term_id, self::META_KEY, true );
		if ( '' === $value || null === $value ) {
			return true;
		}

		return is_string( $value ) && self::COMIC_SERIES === $value;
	}
}
