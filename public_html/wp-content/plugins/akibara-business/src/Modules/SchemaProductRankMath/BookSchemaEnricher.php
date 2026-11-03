<?php
/**
 * BookSchemaEnricher — semántica editorial compartida del Product schema.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SchemaProductRankMath;

use Akibara\Business\Modules\SeriesSchema\SeriesSchemaType;
use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Services\VolumeVariant;
use WC_Product;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Adds catalog-backed Book properties without choosing the JSON-LD emitter.
 *
 * @since 1.93.1
 */
final class BookSchemaEnricher {

	/**
	 * Product categories whose catalog entries represent books or comics.
	 *
	 * @var string[]
	 */
	private const BOOK_CATEGORY_SLUGS = array(
		'manga',
		'shonen',
		'seinen',
		'shojo',
		'manhwa',
		'comics',
		'vertigo',
		'independiente',
	);

	/**
	 * Adds catalog-backed Book properties to a Product entity.
	 *
	 * `additionalType` is emitter-neutral and remains compatible with the native
	 * WooCommerce schema validator. The Rank Math owner converts it to the
	 * Product+Book multi-type after this service returns.
	 *
	 * @param array<string,mixed> $entity  Product entity.
	 * @param WC_Product          $product Catalog product.
	 * @return array<string,mixed>
	 *
	 * @since 1.93.1
	 */
	public function enrich( array $entity, WC_Product $product ): array {
		$product_id = (int) $product->get_id();
		$cat_terms  = get_the_terms( $product_id, 'product_cat' );
		$cat_slugs  = is_array( $cat_terms ) ? wp_list_pluck( $cat_terms, 'slug' ) : array();
		if ( empty( array_intersect( $cat_slugs, self::BOOK_CATEGORY_SLUGS ) ) ) {
			return $entity;
		}

		$entity['additionalType'] = 'https://schema.org/Book';

		$author_terms = get_the_terms( $product_id, 'pa_autor' );
		$authors      = self::build_schema_authors( is_array( $author_terms ) ? $author_terms : array() );
		if ( 1 === count( $authors ) ) {
			$entity['author'] = $authors[0];
		} elseif ( count( $authors ) > 1 ) {
			$entity['author'] = $authors;
		}

		$brand_terms = get_the_terms( $product_id, 'product_brand' );
		$brands      = is_array( $brand_terms ) ? array_values( $brand_terms ) : array();
		if ( ! empty( $brands ) && $brands[0] instanceof WP_Term ) {
			$entity['publisher'] = array(
				'@type' => 'Organization',
				'name'  => $brands[0]->name,
			);
		}

		$isbn = self::normalize_isbn( (string) $product->get_sku() );
		if ( null !== $isbn ) {
			$entity['isbn'] = $isbn;
		}

		$entity['inLanguage'] = 'es';

		$genre_terms = get_the_terms( $product_id, 'pa_genero' );
		if ( is_array( $genre_terms ) && ! empty( $genre_terms ) ) {
			$genres = array_values( array_filter( wp_list_pluck( $genre_terms, 'name' ) ) );
			if ( ! empty( $genres ) ) {
				$entity['genre'] = $genres;
			}
		}

		return $this->restore_series_relationship( $entity, $product );
	}

	/**
	 * Restores the catalog series after Rank Math connects graph entities.
	 *
	 * A real ComicSeries from pa_serie takes precedence over the generic WebPage
	 * isPartOf inserted for a multi-type Product/Book. An explicitly non-comic term
	 * removes upstream ComicSeries relationships while preserving unrelated
	 * WebPage/CreativeWork references. Missing catalog data leaves the relation intact.
	 *
	 * The series @id and url use the same get_term_link() as SeriesSchema. Sharing
	 * @id makes the product reference resolve to the entity published on the landing;
	 * sharing only url would not identify one JSON-LD entity. Without a usable link,
	 * retain the anonymous named relationship rather than invent an identifier.
	 *
	 * @param array<string,mixed> $entity  Existing Product entity.
	 * @param WC_Product          $product Catalog product.
	 * @return array<string,mixed>
	 *
	 * @since 1.93.1
	 */
	public function restore_series_relationship( array $entity, WC_Product $product ): array {
		$serie_terms = get_the_terms( (int) $product->get_id(), 'pa_serie' );
		$series      = is_array( $serie_terms ) ? array_values( $serie_terms ) : array();
		if ( empty( $series ) || ! $series[0] instanceof WP_Term ) {
			return $entity;
		}
		if ( ! SeriesSchemaType::supports_comic_series( (int) $series[0]->term_id ) ) {
			// The taxonomy is also used as a navigation grouping for non-comics.
			// Strip a stale/upstream ComicSeries assertion, but preserve any other
			// relationship that still describes the Product truthfully.
			return self::remove_comic_series_relationship( $entity );
		}

		$series_link        = get_term_link( $series[0] );
		$entity['isPartOf'] = array(
			'@type' => 'ComicSeries',
			'name'  => $series[0]->name,
		);
		if ( ! is_wp_error( $series_link ) && is_string( $series_link ) && '' !== $series_link ) {
			// MISMO fragmento que declara la landing (`SeriesSchema::entity_id`).
			// Sin él, el `@id` sería la URL pelada, que en esa página ya la usan
			// el `CollectionPage` de Rank Math y el último breadcrumb: el
			// consumidor fusionaría la serie con la página.
			$entity['isPartOf']['@id'] = rtrim( $series_link, '#' ) . '#serie';
			$entity['isPartOf']['url'] = $series_link;
		}

		// `position` (CreativeWork): «the position of an item in a series» — el
		// número de tomo machine-readable que cierra «¿qué tomo es este?». La
		// fuente es el MISMO parser compartido que usan upsells y bundles, sobre
		// la convención del pipeline de que todo título termina en nº de tomo.
		// Packs y tomos únicos devuelven null y simplemente no emiten la
		// propiedad: el guard es el contrato del parser, cero dato inventado.
		// Sólo se emite CON serie: la posición es relativa al `isPartOf`.
		// `is_callable`: los dobles de prueba de WC_Product no siempre definen
		// `get_name()`; en Woo real siempre existe.
		//
		// Una VARIANTE del mismo tomo no emite `position`, y `isPartOf` sí se
		// conserva: la edición especial del 23 pertenece a la serie, pero
		// publicar dos `position: 23` bajo el mismo ComicSeries es una
		// contradicción machine-readable — exactamente la señal ambigua que la
		// directiva de autoridad no debe regalar. Sin la propiedad, el
		// consumidor no sabe qué posición ocupa; con dos iguales, sabe algo
		// falso.
		$number = is_callable( array( $product, 'get_name' ) )
			? VolumeTitle::extract_number( (string) $product->get_name() )
			: null;
		if ( null !== $number && ! VolumeVariant::is_variant( (int) $product->get_id() ) ) {
			$entity['position'] = $number;
		}

		return $entity;
	}

	/**
	 * Removes only an `isPartOf` node that asserts ComicSeries semantics.
	 *
	 * `position` is emitted by this class solely as the volume position inside
	 * that series, so it cannot survive after the relationship is removed.
	 *
	 * @param array<string,mixed> $entity Existing Product entity.
	 * @return array<string,mixed>
	 */
	private static function remove_comic_series_relationship( array $entity ): array {
		$relationship = $entity['isPartOf'] ?? null;
		if ( ! is_array( $relationship ) ) {
			return $entity;
		}

		$types = (array) ( $relationship['@type'] ?? array() );
		if ( ! in_array( 'ComicSeries', $types, true ) ) {
			return $entity;
		}

		unset( $entity['isPartOf'], $entity['position'] );

		return $entity;
	}

	/**
	 * Normalizes and validates an ISBN-10 or ISBN-13.
	 */
	private static function normalize_isbn( string $value ): ?string {
		$isbn = strtoupper( (string) preg_replace( '/[\s-]+/', '', trim( $value ) ) );

		if ( 1 === preg_match( '/^(978|979)\d{10}$/', $isbn ) ) {
			$sum = 0;
			for ( $index = 0; $index < 12; $index++ ) {
				$weight = 0 === $index % 2 ? 1 : 3;
				$sum   += (int) $isbn[ $index ] * $weight;
			}
			$check_digit = ( 10 - ( $sum % 10 ) ) % 10;

			return $check_digit === (int) $isbn[12] ? $isbn : null;
		}

		if ( 1 !== preg_match( '/^\d{9}[\dX]$/', $isbn ) ) {
			return null;
		}

		$sum = 0;
		for ( $index = 0; $index < 10; $index++ ) {
			$digit = 'X' === $isbn[ $index ] ? 10 : (int) $isbn[ $index ];
			$sum  += $digit * ( 10 - $index );
		}

		return 0 === $sum % 11 ? $isbn : null;
	}

	/**
	 * Converts every assigned author term to one stable Person entity.
	 *
	 * @param array<int,mixed> $terms Product `pa_autor` terms.
	 * @return array<int,array<string,string>>
	 */
	private static function build_schema_authors( array $terms ): array {
		$unique = array();

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term || '' === trim( (string) $term->name ) ) {
				continue;
			}

			$key = (int) $term->term_id > 0
				? 'id:' . (int) $term->term_id
				: 'name:' . strtolower( trim( (string) $term->name ) );
			$unique[ $key ] = $term;
		}

		usort(
			$unique,
			static function ( WP_Term $left, WP_Term $right ): int {
				$by_name = strcasecmp( (string) $left->name, (string) $right->name );
				return 0 !== $by_name ? $by_name : (int) $left->term_id <=> (int) $right->term_id;
			}
		);

		$authors = array();
		foreach ( $unique as $term ) {
			$person = array(
				'@type' => 'Person',
				'name'  => (string) $term->name,
			);
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) && is_string( $link ) && '' !== $link ) {
				$person['url'] = $link;
			}
			$authors[] = $person;
		}

		return $authors;
	}
}
