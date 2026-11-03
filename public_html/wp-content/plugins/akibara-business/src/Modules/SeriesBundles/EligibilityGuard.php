<?php
/**
 * Series Bundles Eligibility Guard
 *
 * Determina si un producto puede formar parte de un Bundle. Excluye productos
 * cuya categoría (`product_cat`) esté en la lista configurada por el
 * administrador (ej: Novedades, Preventas). Fail-open: ante lista vacía o
 * `WP_Error` de `wp_get_post_terms`, el producto se considera elegible
 * (Req 7.1, 7.2, 7.4).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

defined( 'ABSPATH' ) || exit;

final class EligibilityGuard {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Verifica si el producto es elegible para descuento por Bundle.
	 *
	 * Excluye productos en categorías configuradas (Req 7.2). Fail-open:
	 * lista de excluidas vacía ⇒ elegible (Req 7.1); `wp_get_post_terms`
	 * devuelve `WP_Error` ⇒ elegible (Req 7.4).
	 */
	public function is_eligible( int $product_id ): bool {
		$excluded_cats = $this->settings->get_excluded_categories();

		if ( empty( $excluded_cats ) ) {
			return true;
		}

		// Object-term cache (0 queries en caliente). false/error → sin terms
		// → elegible, mismo comportamiento que el is_wp_error original.
		$cat_terms    = get_the_terms( $product_id, 'product_cat' );
		$product_cats = is_array( $cat_terms )
			? array_map( 'intval', wp_list_pluck( $cat_terms, 'term_id' ) )
			: array();

		foreach ( $excluded_cats as $cat_id ) {
			if ( in_array( $cat_id, $product_cats, true ) ) {
				return false;
			}
		}

		return true;
	}
}
