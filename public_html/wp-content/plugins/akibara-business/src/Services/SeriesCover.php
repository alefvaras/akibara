<?php
/**
 * SeriesCover — la portada que representa a un grupo de tomos.
 *
 * Regla única para la vitrina de la portada y las tarjetas de edición: el
 * tomo de número más bajo (el título termina en el nº de tomo, convención del
 * pipeline de imágenes); entre los que tienen stock si hay alguno, y si la
 * serie entera está agotada, el más bajo a secas. Una serie sin foto en la
 * portada de la tienda se lee como un error, no como «sin stock» (Frieren,
 *: 15 tomos, todos agotados, un rectángulo vacío entre portadas).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

use Akibara\Business\Modules\Stock\StockStatus;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.114.18 Extraído de HomeSeriesDestacadas para compartirlo con las landings de editorial.
 */
final class SeriesCover {

	/**
	 * URL de la portada del tomo más bajo entre los ids dados, o '' si ninguno tiene.
	 *
	 * @param array<int, int> $ids Ids de producto de una misma serie.
	 * @return string
	 */
	public static function lowest_tomo_cover( array $ids ): string {
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
		if ( array() === $ids ) {
			return '';
		}

		$titulos  = self::titles( $ids );
		$best_id  = $ids[0];
		$best_num = null;
		// Se recorre en el orden de `$ids`, no en el de la consulta: ante dos
		// tomos con el mismo número gana el primero, como antes.
		foreach ( $ids as $id ) {
			$num = VolumeTitle::extract_number( $titulos[ $id ] ?? '' );
			if ( null !== $num && ( null === $best_num || $num < $best_num ) ) {
				$best_num = $num;
				$best_id  = $id;
			}
		}

		$url = get_the_post_thumbnail_url( $best_id, 'woocommerce_thumbnail' );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Título de cada tomo, en UNA consulta que trae sólo `ID` y `post_title`.
	 *
	 * Revisión de 1.116.8: aquí había `_prime_post_caches()` + `get_the_title()`,
	 * y el comentario de `tomo_ids()` decía «sólo ids». Los ids sí, pero
	 * `_prime_post_caches()` cargaba después la FILA ENTERA de cada tomo
	 * —`post_content` incluido— para leer un título: en una serie larga, las
	 * descripciones de todos sus tomos en memoria para quedarse con un número.
	 * El título crudo basta: `VolumeTitle::extract_number()` decodifica entidades
	 * y quita etiquetas por su cuenta.
	 *
	 * @param array<int, int> $ids Ids de producto, ya validados como enteros positivos.
	 * @return array<int, string> id ⇒ título.
	 */
	private static function titles( array $ids ): array {
		global $wpdb;

		$marcadores = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- la tabla sale de $wpdb y la lista interpolada sólo lleva marcadores `%d` generados.
		$query = $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$marcadores})", ...$ids );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$filas = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- preparada arriba; lectura acotada por ids y los dos llamadores guardan el resultado en transient.

		$titulos = array();
		foreach ( (array) $filas as $fila ) {
			if ( is_object( $fila ) && isset( $fila->ID ) ) {
				$titulos[ (int) $fila->ID ] = (string) ( $fila->post_title ?? '' );
			}
		}

		return $titulos;
	}

	/**
	 * Portada de una serie: primero entre los tomos con stock, después entre todos.
	 *
	 * @param array<string, array<int, mixed>> $tax_query Cláusulas `tax_query` que acotan la serie (y, si se quiere, la editorial).
	 * @return string
	 */
	public static function for_tax_query( array $tax_query ): string {
		$ids = self::tomo_ids( $tax_query, true );
		if ( array() === $ids ) {
			$ids = self::tomo_ids( $tax_query, false );
		}

		return self::lowest_tomo_cover( $ids );
	}

	/**
	 * Tomos publicados y visibles que cumplen la consulta, opcionalmente sólo con stock.
	 *
	 * @param array<string, array<int, mixed>> $tax_query  Cláusulas de taxonomía.
	 * @param bool                             $solo_stock true = sólo `instock`.
	 * @return array<int, int>
	 */
	private static function tomo_ids( array $tax_query, bool $solo_stock ): array {
		$tax_query[] = array(
			'taxonomy' => 'product_visibility',
			'field'    => 'name',
			'terms'    => array( 'exclude-from-catalog' ),
			'operator' => 'NOT IN',
		);
		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			// Resolver sobre todos los IDs de la serie: el número está en el título
			// y el orden por fecha puede ocultar los primeros tomos de una importación.
			// Los títulos se leen sin hidratar filas completas y el resultado se cachea
			// por sus consumidores.

			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => array_values( $tax_query ),
		);
		if ( $solo_stock ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_stock_status',
					'value'   => StockStatus::IN_STOCK,
					'compare' => '=',
				),
			);
		}

		return array_map( 'intval', (array) ( new \WP_Query( $args ) )->posts );
	}
}
