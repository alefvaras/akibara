<?php
/**
 * Calcula menu_order mediante round-robin de series: ordena series por la
 * fecha de su tomo más reciente y tomos por fecha descendente, y entrelaza
 * sus posiciones. WooCommerce conserva orden y paginación nativos.
 * compute() mezcla el catálogo vendible; compute_available_first() aplica
 * el mismo intercalado dentro de los grupos disponible, reservable y agotado.
 * El panel permite elegir ambas vitrinas. Clase pura, sin dependencias WP/WC.
 *
 * @package Akibara\Business\Modules\CatalogOrder
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\CatalogOrder;

use Akibara\Business\Modules\Stock\StockStatus;

defined( 'ABSPATH' ) || exit;

final class Interleaver {

	/** Grupo de la vitrina «disponibles primero»: en bodega, se despacha ya. */
	public const TIER_AVAILABLE = 0;

	/** Grupo «a pedido» (y preventa): se vende, pero llega en semanas. */
	public const TIER_ON_REQUEST = 1;

	/** Grupo agotado: al final, fuera del espacio que vende. */
	public const TIER_SOLD_OUT = 2;

	/**
	 * Grupo de disponibilidad de un `_stock_status` crudo.
	 *
	 * Lo desconocido (fila sin meta, valor vacío o un estado que WooCommerce no
	 * conoce) cuenta como disponible porque es lo que el cliente VE:
	 * `WC_Product::set_stock_status()` convierte cualquier estado no válido en
	 * `instock`, así que la tarjeta dice «Disponible» y ofrece el carrito.
	 *
	 * El vocabulario es el de `Stock\StockStatus` y no un literal: un brazo de
	 * `match` compara igual que un `===`, y el candado de
	 * `AvailabilityTest::test_los_literales_de_stock_no_se_comparan_a_mano_fuera_de_stock`
	 * lo vigila así (revisión B1-REV3-1). La clase sigue siendo pura: StockStatus
	 * sólo aporta las constantes.
	 *
	 * @param string|null $stock_status Valor de `_stock_status` (null = sin fila).
	 */
	public static function availability_tier( ?string $stock_status ): int {
		return match ( $stock_status ) {
			StockStatus::OUT_OF_STOCK => self::TIER_SOLD_OUT,
			StockStatus::ON_BACKORDER => self::TIER_ON_REQUEST,
			default                   => self::TIER_AVAILABLE,
		};
	}

	/**
	 * Vitrina «disponibles primero»: el intercalado de {@see self::compute()}
	 * aplicado a cada grupo de disponibilidad por separado, y los grupos uno
	 * detrás de otro (disponible → «a pedido» → agotado).
	 *
	 * Dentro de cada grupo se conserva todo lo que ya hacía el intercalado: el
	 * round-robin de series (la página 1 no es un muro de una sola colección),
	 * las series ordenadas por su tomo más nuevo DE ESE GRUPO y el desempate
	 * determinista. Un grupo vacío no ocupa posiciones.
	 *
	 * @param array<int, array{id:int, serie:string, date:string, tier:int}> $rows
	 *        Como en {@see self::compute()}, más `tier` (una de las constantes
	 *        TIER_*, ver {@see self::availability_tier()}).
	 * @return array<int, int> Mapa product_id => menu_order (1-based, sin huecos).
	 */
	public static function compute_available_first( array $rows ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			$groups[ (int) $row['tier'] ][] = $row;
		}
		ksort( $groups );

		$orders = array();
		$offset = 0;
		foreach ( $groups as $group ) {
			// `sellable`, si viene, es igual para todo el grupo: ni el desempate
			// dentro de la serie ni la recencia cambian por él aquí.
			foreach ( self::compute( $group ) as $id => $position ) {
				$orders[ $id ] = $offset + $position;
			}
			$offset += count( $group );
		}

		return $orders;
	}

	/**
	 * Computa el menu_order intercalado.
	 *
	 * El espacio prime debe VENDER: dentro de cada serie los tomos vendibles
	 * (instock/preventa) van antes que los agotados, y la recencia de la serie
	 * se mide sobre sus tomos vendibles cuando los tiene (un agotado reciente no
	 * le gana la vitrina a lo comprable). Una serie 100% agotada conserva su
	 * fecha real: su lugar en vitrina es honesto y captura waitlist.
	 *
	 * Determinismo: los empates de fecha (lotes de import con el mismo segundo)
	 * se desempatan por ID DESC (el insertado más tarde = más nuevo), y los de
	 * serie por su clave ASC.
	 *
	 * @param array<int, array{id:int, serie:string, date:string, sellable?:bool}> $rows
	 *        Una fila por producto publicado; `serie` es una clave estable
	 *        (term_taxonomy o singleton "p{ID}" para productos sin serie);
	 *        `date` comparable como string (formato MySQL Y-m-d H:i:s);
	 *        `sellable` default true (false = agotado).
	 * @return array<int, int> Mapa product_id => menu_order (1-based).
	 */
	public static function compute( array $rows ): array {
		if ( empty( $rows ) ) {
			return array();
		}

		// Agrupar por serie; recencia = fecha más nueva VENDIBLE (fallback: la
		// más nueva a secas para series completamente agotadas).
		$series        = array();
		$max_any       = array();
		$max_sellable  = array();
		foreach ( $rows as $row ) {
			$key              = (string) $row['serie'];
			$sellable         = $row['sellable'] ?? true;
			$series[ $key ][] = $row;
			if ( ! isset( $max_any[ $key ] ) || $row['date'] > $max_any[ $key ] ) {
				$max_any[ $key ] = $row['date'];
			}
			if ( $sellable && ( ! isset( $max_sellable[ $key ] ) || $row['date'] > $max_sellable[ $key ] ) ) {
				$max_sellable[ $key ] = $row['date'];
			}
		}
		$recency = array();
		foreach ( $series as $key => $_ ) {
			$recency[ $key ] = $max_sellable[ $key ] ?? $max_any[ $key ];
		}

		// Ranking de series: la de tomo (vendible) más nuevo primero; empate → clave ASC.
		uksort(
			$series,
			static function ( string $a, string $b ) use ( $recency ): int {
				return ( $recency[ $b ] <=> $recency[ $a ] ) ?: strcmp( $a, $b );
			}
		);

		// Dentro de cada serie: vendible antes que agotado; después el más
		// nuevo primero; empate → ID DESC.
		foreach ( $series as &$items ) {
			usort(
				$items,
				static function ( array $a, array $b ): int {
					$sa = ( $a['sellable'] ?? true ) ? 1 : 0;
					$sb = ( $b['sellable'] ?? true ) ? 1 : 0;
					return ( $sb <=> $sa )
						?: ( $b['date'] <=> $a['date'] )
						?: ( $b['id'] <=> $a['id'] );
				}
			);
		}
		unset( $items );

		// Round-robin: una pasada por "rank" tomando de cada serie en orden.
		$orders   = array();
		$position = 1;
		$rank     = 0;
		$assigned = 0;
		$total    = count( $rows );
		while ( $assigned < $total ) {
			foreach ( $series as $items ) {
				if ( isset( $items[ $rank ] ) ) {
					$orders[ (int) $items[ $rank ]['id'] ] = $position;
					++$position;
					++$assigned;
				}
			}
			++$rank;
		}

		return $orders;
	}
}
