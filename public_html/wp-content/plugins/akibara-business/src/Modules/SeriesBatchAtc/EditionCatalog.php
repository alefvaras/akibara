<?php
/**
 * Consulta directa de la edición completa (`pa_serie`) y propiedad verificada.
 *
 * Alimenta `akbSeriesBatchConfig.editionProducts` en el cliente para que la
 * selección masiva y por tramo opere sobre toda la serie paginada, omitiendo
 * tomos ya comprados por el cliente sin requerir un snapshot paralelo.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBatchAtc;

use Akibara\Business\Modules\AccountCollection\CustomerCollection;
use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Services\VolumeVariant;

defined( 'ABSPATH' ) || exit;

/**
 * Constructor de mapa completo de edición para `SeriesBatchAtc`.
 */
final class EditionCatalog {

	/**
	 * Devuelve los productos publicados del término `pa_serie` ordenados por tomo.
	 *
	 * @param int $term_id Término `pa_serie`.
	 * @return list<array<string, mixed>>|null
	 */
	public function for_term( int $term_id ): ?array {
		$ids = $this->published_ids( $term_id );
		if ( null === $ids || array() === $ids ) {
			return $ids;
		}
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, true, true );
		}

		$owned_lookup  = $this->owned_product_lookup();
		$resolved      = $this->resolve_entries( $ids, $owned_lookup );
		$owned_volumes = $resolved['owned_volumes'];

		$products = array_map(
			fn ( array $entry ): array => $this->shape_item( $entry['id'], $entry['product'], $entry['volume'], $owned_lookup, $owned_volumes ),
			$resolved['entries']
		);

		usort(
			$products,
			static fn ( array $a, array $b ): int => array( $a['volume'] ?? PHP_INT_MAX, $a['variant'] ? 1 : 0, $a['id'] )
				<=> array( $b['volume'] ?? PHP_INT_MAX, $b['variant'] ? 1 : 0, $b['id'] )
		);

		return $products;
	}

	/**
	 * IDs publicados pertenecientes al término, consultados en lotes acotados.
	 *
	 * @param int $term_id Término `pa_serie`.
	 * @return list<int>|null
	 */
	private function published_ids( int $term_id ): ?array {
		$term_object_ids = function_exists( 'get_objects_in_term' ) ? get_objects_in_term( $term_id, 'pa_serie' ) : array();
		if ( is_wp_error( $term_object_ids ) ) {
			return null;
		}
		$object_ids = array_values(
			array_filter(
				array_map( 'intval', (array) $term_object_ids ),
				static fn ( int $id ): bool => $id > 0
			)
		);
		if ( array() === $object_ids ) {
			return array();
		}

		$ids = array();
		foreach ( array_chunk( $object_ids, 100 ) as $chunk ) {
			$published_ids = get_posts(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'post__in'               => $chunk,
					'posts_per_page'         => 100,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $published_ids as $item ) {
				$id = $item instanceof \WP_Post ? (int) $item->ID : (int) $item;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Carga productos y extrae los números de tomo ya adquiridos en esta edición.
	 *
	 * @param list<int>        $ids          IDs publicados.
	 * @param array<int, true> $owned_lookup Mapa de IDs comprados.
	 * @return array{entries: list<array{id: int, product: \WC_Product, volume: int|null}>, owned_volumes: array<int, true>}
	 */
	private function resolve_entries( array $ids, array $owned_lookup ): array {
		$entries       = array();
		$owned_volumes = array();
		foreach ( $ids as $id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() ) {
				continue;
			}
			$volume    = VolumeTitle::extract_number( $product->get_name() );
			$entries[] = array(
				'id'      => $id,
				'product' => $product,
				'volume'  => $volume,
			);
			if ( isset( $owned_lookup[ $id ] ) && null !== $volume ) {
				$owned_volumes[ $volume ] = true;
			}
		}

		return array(
			'entries'       => $entries,
			'owned_volumes' => $owned_volumes,
		);
	}

	/**
	 * Mapa de IDs comprados verificados para el cliente actual.
	 *
	 * @return array<int, true>
	 */
	private function owned_product_lookup(): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 ) {
			return array();
		}
		$purchased = ( new CustomerCollection() )->owned_product_ids_result( $user_id );
		return array_fill_keys( $purchased['product_ids'], true );
	}

	/**
	 * Serializa un tomo para el selector masivo cliente.
	 *
	 * @param array<int, true> $owned_lookup
	 * @param array<int, true> $owned_volumes
	 * @return array<string, mixed>
	 */
	private function shape_item( int $id, \WC_Product $product, ?int $volume, array $owned_lookup, array $owned_volumes ): array {
		$is_owned = isset( $owned_lookup[ $id ] ) || ( null !== $volume && isset( $owned_volumes[ $volume ] ) );
		$price    = function_exists( 'wc_get_price_to_display' ) ? wc_get_price_to_display( $product ) : $product->get_price();
		return array(
			'id'           => $id,
			'volume'       => $volume,
			'variant'      => VolumeVariant::is_variant( $id ),
			'price'        => (string) $price,
			'stock_status' => $product->get_stock_status(),
			'purchasable'  => SeriesBatchAtc::can_select_product( $product ),
			'type'         => $product->get_type(),
			'owned'        => $is_owned,
		);
	}
}
