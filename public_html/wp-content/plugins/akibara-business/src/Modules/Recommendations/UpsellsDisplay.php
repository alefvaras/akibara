<?php
/**
 * Presenta upsells por orden de lectura y comprabilidad.
 *
 * La lista elegida se entrega a WooCommerce con un orden que no la baraja.
 * El filtro sólo modifica la presentación de la ficha: no reescribe _upsell_ids,
 * por lo que editor, API y UpsellsAutofill conservan los datos guardados.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Recommendations;

use Akibara\Business\Services\VolumeTitle;
use WC_Product;

defined( 'ABSPATH' ) || exit;

final class UpsellsDisplay {

	/** Acción de la plantilla donde WooCommerce pinta upsells (15) y relacionados (20). */
	private const RENDER_HOOK = 'woocommerce_after_single_product_summary';

	/** Tomos que se evalúan por tanda al buscar los siguientes comprables. */
	private const TANDA = 6;

	/**
	 * Lista ya resuelta por ficha: el bloque de upsells y la exclusión de los
	 * relacionados la piden dos veces en el mismo render.
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $memo = array();

	public function register(): void {
		add_filter( 'woocommerce_product_get_upsell_ids', array( $this, 'arrange' ), 10, 2 );
		// Sin estos dos, WooCommerce baraja la lista que acabamos de ordenar. Sólo
		// los lee `woocommerce_upsell_display()`: no tocan ningún otro listado.
		add_filter( 'woocommerce_upsells_orderby', array( self::class, 'keep_order' ) );
		add_filter( 'woocommerce_upsells_order', array( self::class, 'ascending' ) );
	}

	/**
	 * `none`: WooCommerce conserva el orden de la lista.
	 *
	 * @internal Callback de `woocommerce_upsells_orderby`.
	 */
	public static function keep_order(): string {
		return 'none';
	}

	/**
	 * `asc`: con `desc` (el de fábrica) WooCommerce invierte la lista al final.
	 *
	 * @internal Callback de `woocommerce_upsells_order`.
	 */
	public static function ascending(): string {
		return 'asc';
	}

	/**
	 * Ordena los upsells de la ficha que se está pintando.
	 *
	 * @internal Callback de `woocommerce_product_get_upsell_ids` (contexto `view`).
	 *
	 * @param mixed $ids     Ids guardados.
	 * @param mixed $product Producto dueño de la lista.
	 * @return mixed
	 */
	public function arrange( $ids, $product ) {
		if ( ! is_array( $ids ) || array() === $ids || ! $product instanceof WC_Product ) {
			return $ids;
		}
		if ( ! doing_action( self::RENDER_HOOK ) ) {
			return $ids;
		}

		$product_id = (int) $product->get_id();
		if ( $product_id !== (int) get_queried_object_id() ) {
			return $ids;
		}
		if ( isset( $this->memo[ $product_id ] ) ) {
			return $this->memo[ $product_id ];
		}

		try {
			$guardados = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
			SeriesShelf::prime( $guardados );

			$registros  = array();
			$hay_compra = false;
			foreach ( $guardados as $id ) {
				$comprable   = SeriesShelf::comprable( $id );
				$hay_compra  = $hay_compra || $comprable;
				$registros[] = array(
					'id'        => $id,
					'numero'    => VolumeTitle::extract_number( (string) get_post_field( 'post_title', $id, 'raw' ) ),
					'comprable' => $comprable,
				);
			}

			$modo       = Settings::current( Settings::OPTION_SERIE_AGOTADA );
			$siguientes = ( ! $hay_compra && Settings::AGOTADA_COMPLETAR === $modo )
				? $this->next_purchasable( $product, count( $guardados ), $guardados )
				: array();

			$this->memo[ $product_id ] = Picker::upsells( $registros, $siguientes, $modo );
		} catch ( \Throwable $throwable ) {
			return $ids;
		}

		return $this->memo[ $product_id ];
	}

	/**
	 * Próximos tomos de la serie de la ficha que se pueden comprar, en orden de
	 * lectura: los que ocupan el bloque cuando los guardados están todos
	 * agotados y el dueño eligió «cambiarlos».
	 *
	 * Una consulta por los tomos de la serie y lotes de TANDA productos hasta
	 * juntar los que hacen falta; nunca la serie entera de una vez.
	 *
	 * @param WC_Product      $product  Ficha.
	 * @param int             $cuantos  Huecos del bloque.
	 * @param array<int, int> $excluir  Ids que ya están en el bloque.
	 * @return array<int, int>
	 */
	private function next_purchasable( WC_Product $product, int $cuantos, array $excluir ): array {
		$actual = VolumeTitle::extract_number( (string) get_post_field( 'post_title', $product->get_id(), 'raw' ) );
		if ( null === $actual || $cuantos < 1 ) {
			return array();
		}

		$fuera      = array_fill_keys( array_merge( $excluir, array( (int) $product->get_id() ) ), true );
		$candidatos = array();
		foreach ( SeriesShelf::volumes( SeriesShelf::series_of( (int) $product->get_id() ) ) as $tomos ) {
			foreach ( $tomos as $tomo ) {
				if ( null !== $tomo['numero'] && $tomo['numero'] > $actual && ! isset( $fuera[ $tomo['id'] ] ) ) {
					$candidatos[ $tomo['id'] ] = array(
						'id'            => $tomo['id'],
						'title'         => '',
						'volume_number' => $tomo['numero'],
					);
				}
			}
		}

		$elegidos = array();
		$numeros  = array();
		foreach ( array_chunk( VolumeTitle::sort_records( array_values( $candidatos ) ), self::TANDA ) as $tanda ) {
			SeriesShelf::prime( array_column( $tanda, 'id' ) );
			foreach ( $tanda as $tomo ) {
				// Un número, un tomo: dos ediciones del mismo número no ocupan
				// dos huecos.
				if ( isset( $numeros[ $tomo['volume_number'] ] ) || ! SeriesShelf::comprable( (int) $tomo['id'] ) ) {
					continue;
				}
				$numeros[ $tomo['volume_number'] ] = true;
				$elegidos[]                        = (int) $tomo['id'];
				if ( count( $elegidos ) >= $cuantos ) {
					return $elegidos;
				}
			}
		}

		return $elegidos;
	}
}
