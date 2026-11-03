<?php
/**
 * Lecturas del catálogo que necesitan las recomendaciones, en lotes.
 *
 * El N+1 de los relacionados ya se pagó una vez (una veintena de consultas para
 * ordenar cuatro tarjetas). Todo lo de aquí trabaja por lotes: una
 * consulta para los tomos de VARIAS series y un solo `_prime_post_caches()`
 * para los productos que de verdad se van a mirar.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Recommendations;

use Akibara\Business\Services\VolumeTitle;
use WC_Product;

defined( 'ABSPATH' ) || exit;

final class SeriesShelf {

	/** Taxonomía de las series. */
	public const TAXONOMY = 'pa_serie';

	/**
	 * Serie (`term_taxonomy_id` de `pa_serie`) principal de un producto, o null.
	 *
	 * Lee del term cache: quien llama tiene que haberlo primado con
	 * `self::prime()` para los ids en lote.
	 *
	 * @param int $product_id Producto.
	 */
	public static function serie_of( int $product_id ): ?int {
		$series = self::series_of( $product_id );

		return array() === $series ? null : $series[0];
	}

	/**
	 * Todas las series (`term_taxonomy_id`) de un producto, del term cache.
	 *
	 * @param int $product_id Producto.
	 * @return array<int, int>
	 */
	public static function series_of( int $product_id ): array {
		$terms = get_the_terms( $product_id, self::TAXONOMY );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$ids = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$ids[] = (int) $term->term_taxonomy_id;
			}
		}
		sort( $ids );

		return $ids;
	}

	/**
	 * Posts, metadatos y términos de un lote de productos en tres consultas.
	 *
	 * @param array<int, int> $ids Productos.
	 */
	public static function prime( array $ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( array() !== $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, true, true );
		}
	}

	/**
	 * ¿Se puede comprar hoy? El mismo criterio que usa el relleno de upsells
	 * (`UpsellsAutofill::sequence_for_term()`): visible, comprable y con stock
	 * (las preventas y «a pedido» cuentan: se compran).
	 *
	 * @param int $id Producto.
	 */
	public static function comprable( int $id ): bool {
		$product = wc_get_product( $id );

		return $product instanceof WC_Product
			&& $product->is_visible()
			&& $product->is_purchasable()
			&& $product->is_in_stock();
	}

	/**
	 * Tomos publicados de varias series, en orden de lectura, en UNA consulta.
	 *
	 * Los especiales sin número inequívoco van al final de cada serie (orden de
	 * `VolumeTitle::sort_records()`).
	 *
	 * @param array<int, int> $series `term_taxonomy_id` de `pa_serie`.
	 * @return array<int, array<int, array{id:int, numero:int|null}>> Por serie.
	 */
	public static function volumes( array $series ): array {
		$series = array_values( array_unique( array_filter( array_map( 'intval', $series ) ) ) );
		if ( array() === $series ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $series ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- lectura en lote; los placeholders son `%d` generados arriba (uno por serie) y los valores pasan por prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID AS id, p.post_title AS title, tr.term_taxonomy_id AS serie FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID WHERE p.post_type = 'product' AND p.post_status = 'publish' AND tr.term_taxonomy_id IN ( {$placeholders} )", $series ), ARRAY_A );

		$por_serie = array();
		foreach ( (array) $rows as $row ) {
			$por_serie[ (int) $row['serie'] ][] = array(
				'id'    => (int) $row['id'],
				'title' => (string) $row['title'],
			);
		}

		$estantes = array();
		foreach ( $por_serie as $serie => $registros ) {
			foreach ( VolumeTitle::sort_records( $registros ) as $registro ) {
				$estantes[ $serie ][] = array(
					'id'     => (int) $registro['id'],
					'numero' => isset( $registro['volume_number'] ) ? (int) $registro['volume_number'] : null,
				);
			}
		}

		return $estantes;
	}
}
