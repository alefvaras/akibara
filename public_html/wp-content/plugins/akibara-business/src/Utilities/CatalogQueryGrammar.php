<?php
/**
 * Gramática pública de la cadena de consulta del catálogo.
 *
 * Es la lista ÚNICA de parámetros que convierten una URL de colección en una
 * variante facetada: los del buscador first-party (`_disponibilidad`,
 * `_editorial`, `_buscar`…), los de WooCommerce (`filter_*`, `min_price`,
 * `orderby`…) y nada más.
 *
 * Existe porque el mismo criterio lo necesitan al menos dos módulos con
 * consecuencias muy distintas —`SeoIndexing\FilteredUrlsPolicy` decide robots y
 * 404, y `RelatedGuides\SeriesRelatedGuide` decide si la landing sigue siendo su
 * página canónica— y el segundo lo había aproximado con `! empty( $_GET)`. Esa
 * aproximación trata `?utm_source=…`, `?gclid=…` o `?fbclid=…` como si fueran
 * facetas: la misma landing, compartida desde una campaña o desde Google Ads,
 * perdía el bloque editorial sin que nada cambiara en la colección.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

/** Reconoce los parámetros que definen una variante facetada del catálogo. */
final class CatalogQueryGrammar {

	/**
	 * Parámetro reservado de la búsqueda por texto del buscador first-party.
	 *
	 * Es una faceta a efectos de robots pero no de colección: una búsqueda de
	 * texto vacía es una respuesta útil, no una colección ausente.
	 */
	public const TEXT_SEARCH_PARAMETER = '_buscar';

	/**
	 * Parámetros de WooCommerce sin prefijo reconocible.
	 *
	 * `_paged` queda fuera a propósito: paginar no crea una variante, sólo
	 * avanza dentro de la misma colección.
	 *
	 * @var array<int,string>
	 */
	private const WOOCOMMERCE_PARAMETERS = array(
		'orderby',
		'min_price',
		'max_price',
		'stock',
		'sale',
		'per_page',
		'rating',
		'rating_filter',
		'layout',
	);

	/** ¿El parámetro pertenece a la gramática de filtrado del catálogo? */
	public static function is_filter_parameter( string $key ): bool {
		if ( 0 === strpos( $key, '_' ) ) {
			return '_paged' !== $key;
		}

		if ( 0 === strpos( $key, 'filter_' ) || 0 === strpos( $key, 'query_type_' ) ) {
			return true;
		}

		return in_array( $key, self::WOOCOMMERCE_PARAMETERS, true );
	}

	/**
	 * ¿Alguna clave de la consulta pertenece a la gramática?
	 *
	 * @param array<array-key,mixed> $query Claves crudas de la petición.
	 */
	public static function has_filter_parameter( array $query ): bool {
		foreach ( array_keys( $query ) as $key ) {
			if ( is_string( $key ) && self::is_filter_parameter( $key ) ) {
				return true;
			}
		}

		return false;
	}
}
