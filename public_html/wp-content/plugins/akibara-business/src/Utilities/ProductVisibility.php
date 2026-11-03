<?php
/**
 * ProductVisibility — ¿esta transición de estado mete o saca un producto del catálogo público?
 *
 * POR QUÉ EXISTE (revisión independiente de). Publicar,
 * despublicar, mandar a la papelera o restaurar un producto mueve el `count`
 * de sus series y de su editorial SIN pasar por `set_object_terms`: WordPress
 * lo recalcula en `_update_term_count_on_transition_post_status()`. La regla
 * «sólo importa si exactamente uno de los dos estados es `publish`» vivía
 * copiada en `SeriesDirectory\Shortcode` y en `SiteSpeed\SeriesCacheTags`, y
 * faltaba en las dos cachés de editoriales (`BrandsDirectory\Shortcode` y
 * `HomeCarousel`): la última editorial que se quedaba sin catálogo por una
 * papelera seguía en la vitrina hasta que venciera el transient. Una sola
 * definición para los cuatro.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

final class ProductVisibility {

	/**
	 * @param mixed $new_status Estado nuevo (`transition_post_status`).
	 * @param mixed $old_status Estado anterior.
	 * @param mixed $post       El post que cambia.
	 */
	public static function changed( $new_status, $old_status, $post ): bool {
		if ( ! is_object( $post ) || 'product' !== (string) ( $post->post_type ?? '' ) ) {
			return false;
		}

		return ( 'publish' === (string) $new_status ) !== ( 'publish' === (string) $old_status );
	}
}
