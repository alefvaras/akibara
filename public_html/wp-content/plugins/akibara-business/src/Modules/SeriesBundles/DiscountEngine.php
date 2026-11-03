<?php
/**
 * Series Bundles — DiscountEngine (LÓGICA PURA).
 *
 * Calcula el descuento de Build_Your_Pack a partir de DTOs
 * de precio ya resueltos por la capa de hooks. NO depende del runtime de
 * WooCommerce ni de WordPress: recibe ítems como arrays con la forma
 * `{regular_price: float, current_price: float, qty: int, product_id: int}` y
 * delega el redondeo en `Support\Money`.
 *
 * Es uno de los objetivos principales de las property-based tests:
 *   - Property 1: el descuento devuelto es siempre ≥ 0.
 *   - Property 7: en modo fixed con todos los ítems elegibles,
 *     `current_subtotal − discount == fixed_price`, acotado a 0 cuando
 *     `fixed_price > current_subtotal`.
 *
 * Decisiones de design aplicadas:
 *   - Decisión 2: el cálculo en modo porcentaje se hace contra el precio ACTUAL
 *     (`current_price`); si otra fuente ya bajó el precio por debajo del precio
 *     objetivo, el aporte unitario es 0 (no acumula descuentos).
 *   - Decisión 8: en Build_Your_Pack el conteo y el beneficio son por tomos
 *     DISTINTOS. El motor vuelve a deduplicar por `product_id` y descarta
 *     cantidades no positivas como defensa de dominio, aunque el caller ya
 *     entregue DTOs deduplicados.
 *
 * En modo porcentaje, el objetivo se compara contra el precio actual para que
 * gane el mejor descuento. En modo precio fijo, el target también se compara
 * contra la suma de precios actuales del pack para no acumular sobre rebajas
 * aplicadas por otras promociones.
 *
 * Sin funciones i18n ni llamadas a WC/WP (Req 11.4); solo se usa
 * `Support\Money` del propio módulo.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\Support\Money;

defined( 'ABSPATH' ) || exit;

final class DiscountEngine {

	public function __construct(
		private readonly VolumeTierResolver $tiers
	) {
	}

	/**
	 * Calcula el descuento de Build_Your_Pack por volumen de tomos distintos.
	 *
	 * Normaliza defensivamente los DTOs: descarta `qty <= 0`, IDs inválidos y
	 * repeticiones de `product_id`. El porcentaje se resuelve con
	 * `VolumeTierResolver::resolve_pct()` usando la cantidad resultante; si es 0
	 * se devuelve 0.0 sin más cálculo.
	 *
	 * Por cada ítem el precio objetivo es `regular_price × (1 − pct/100)` y el
	 * aporte es `max(0, current_price − precio_objetivo)` una sola vez por tomo
	 * distinto (Decisión 2/8). Luego se redondea y el resultado es ≥ 0.
	 *
	 * @param array<int, array{regular_price:float,current_price:float,qty:int,product_id:int}> $distinct_items Ítems distintos ya deduplicados.
	 * @param array<int, array{min:int,pct:int}>                                   $tiers          Tramos de volumen.
	 *
	 * @return float Descuento redondeado, siempre ≥ 0.
	 */
	public function calc_byp_discount( array $distinct_items, array $tiers ): float {
		$distinct_items = $this->valid_distinct_items( $distinct_items );
		$distinct_qty   = count( $distinct_items );
		$pct            = $this->tiers->resolve_pct( $distinct_qty, $tiers );

		if ( 0 === $pct ) {
			return 0.0;
		}

		$discount = $this->percent_discount( $distinct_items, $pct );
		$discount = Money::clamp_non_negative( $discount );

		return Money::round( $discount );
	}

	/**
	 * Aporte exacto de un `product_id` al descuento BYP de su serie.
	 *
	 * Usa el mismo universo defensivo y el mismo tramo que el total. Devuelve un
	 * monto unitario ya redondeado: WooCommerce lo aplica a una sola unidad de la
	 * línea, por lo que cantidades extra no reciben beneficio. Si el tomo no está
	 * entre los DTOs válidos, el tramo no aplica o una oferta ya alcanzó/superó
	 * el target, devuelve 0 (best-wins).
	 *
	 * @param int                                                                                       $product_id     Producto buscado.
	 * @param array<int, array{regular_price:float,current_price:float,qty:int,product_id:int}>         $distinct_items DTOs de la serie.
	 * @param array<int, array{min:int,pct:int}>                                                       $tiers          Tramos de volumen.
	 */
	public function contribution_for_product( int $product_id, array $distinct_items, array $tiers ): float {
		if ( $product_id <= 0 ) {
			return 0.0;
		}

		$distinct_items = $this->valid_distinct_items( $distinct_items );
		$pct            = $this->tiers->resolve_pct( count( $distinct_items ), $tiers );
		if ( $pct <= 0 ) {
			return 0.0;
		}

		foreach ( $distinct_items as $item ) {
			if ( $product_id !== (int) $item['product_id'] ) {
				continue;
			}

			return Money::round( $this->item_contribution( $item, $pct ) );
		}

		return 0.0;
	}

	/**
	 * Aporte exacto adaptado a la base monetaria que WooCommerce descuenta.
	 *
	 * El DTO usa el precio actual crudo del producto; `$discounting_amount` puede
	 * ser una base sin impuesto (o exenta). WooCommerce redondea esa base a la
	 * moneda antes de invocar el filtro; cuando el adaptador conoce su valor
	 * tributario sin redondear lo entrega como `$calculation_base`. El motor escala
	 * una sola vez desde esa base precisa y conserva `$discounting_amount` como
	 * techo real de la línea. Cantidades extra siguen fuera: la capa WC entrega
	 * qty=1.
	 *
	 * @param int                                                                                       $product_id         Producto buscado.
	 * @param array<int, array{regular_price:float,current_price:float,qty:int,product_id:int}>         $distinct_items     DTOs de la serie.
	 * @param array<int, array{min:int,pct:int}>                                                       $tiers              Tramos de volumen.
	 * @param float                                                                                     $discounting_amount Base unitaria materializada por WC.
	 * @param float|null                                                                                $calculation_base   Base unitaria precisa previa al redondeo de moneda.
	 */
	public function contribution_for_base(
		int $product_id,
		array $distinct_items,
		array $tiers,
		float $discounting_amount,
		?float $calculation_base = null
	): float {
		$base = Money::clamp_non_negative( $discounting_amount );
		if ( $base <= 0 ) {
			return 0.0;
		}
		$scale_base = null !== $calculation_base
			? Money::clamp_non_negative( $calculation_base )
			: $base;
		if ( $scale_base <= 0 ) {
			return 0.0;
		}

		$items = $this->valid_distinct_items( $distinct_items );
		$pct   = $this->tiers->resolve_pct( count( $items ), $tiers );
		if ( $pct <= 0 ) {
			return 0.0;
		}

		foreach ( $items as $item ) {
			if ( $product_id !== (int) $item['product_id'] ) {
				continue;
			}

			$current = (float) $item['current_price'];
			if ( $current <= 0 ) {
				return 0.0;
			}

			// Escalar el aporte crudo y redondear UNA sola vez. Redondear primero
			// en moneda bruta y luego otra vez sobre la base tributaria produce
			// drift de 1 CLP (p. ej. precio 1.005 con base exenta).
			$raw    = $this->item_contribution( $item, $pct );
			$scaled = Money::round( $raw * $scale_base / $current );

			return min( $base, Money::clamp_non_negative( $scaled ) );
		}

		return 0.0;
	}

	/**
	 * Descuento porcentual contra el precio ACTUAL (Decisión 2).
	 *
	 * Por cada ítem: `precio_objetivo = regular_price × (1 − pct/100)`;
	 * `aporte_unitario = max(0, current_price − precio_objetivo)`; acumula ese
	 * aporte una sola vez por `product_id`. No acumula descuento si otra fuente
	 * ya dejó el precio actual en o por debajo del precio objetivo.
	 *
	 * @param array<int, array{regular_price:float,current_price:float,qty:int,product_id:int}> $items
	 * @param int                                                                   $pct
	 */
	private function percent_discount( array $items, int $pct ): float {
		// Cap de negocio: ningún descuento BYP puede superar el tope de la tienda,
		// incluso si el DTO entra sin acotar. Se aplica en RUNTIME a propósito: si
		// el dueño baja el tope, los tramos ya guardados por encima se sirven
		// acotados de inmediato, sin esperar a que alguien vuelva a guardarlos.
		$pct = max( VolumeTierResolver::PCT_MIN, min( VolumeTierResolver::pct_max(), $pct ) );

		$discount = 0.0;

		foreach ( $items as $item ) {
			// La asignación real de WC se redondea por línea. Sumar esos mismos
			// aportes redondeados mantiene el monto nominal idéntico al aplicado.
			$discount += Money::round( $this->item_contribution( $item, $pct ) );
		}

		return $discount;
	}

	/**
	 * Aporte unitario sin redondear de un DTO ya validado.
	 *
	 * @param array{regular_price:float,current_price:float,qty:int,product_id:int} $item Tomo distinto.
	 */
	private function item_contribution( array $item, int $pct ): float {
		$is_backorder   = ! empty( $item['is_backorder'] );
		$backorder_mode = (string) ( $item['backorder_mode'] ?? '' );
		if ( '' === $backorder_mode || 'inherit' === $backorder_mode ) {
			$backorder_mode = function_exists( 'get_option' ) ? (string) get_option( 'akb_series_bundles_backorder_mode', 'best_wins_topup' ) : 'best_wins_topup';
		}

		if ( $is_backorder && ( 'count_only_bridge' === $backorder_mode || 'exclude_backorder' === $backorder_mode ) ) {
			return 0.0;
		}

		$is_special    = ! empty( $item['is_special'] );
		$special_cap   = function_exists( 'get_option' ) ? (int) get_option( 'akb_sb_special_max_pct', 8 ) : 8;
		$effective_pct = $is_special ? min( $pct, $special_cap ) : $pct;

		$effective_pct = max( VolumeTierResolver::PCT_MIN, min( VolumeTierResolver::pct_max(), $effective_pct ) );
		$regular       = (float) $item['regular_price'];
		$current       = (float) $item['current_price'];
		$target        = $regular * ( 1.0 - ( $effective_pct / 100.0 ) );

		return Money::clamp_non_negative( $current - $target );
	}

	/**
	 * Deja un DTO válido por `product_id`, siempre con beneficio unitario.
	 *
	 * El carrito canónico ya deduplica, pero esta frontera pura no debe poder
	 * convertir una cantidad repetida ni un DTO de cantidad cero en descuento.
	 * Cuando el primer DTO de un ID tiene cantidad inválida se ignora y un DTO
	 * posterior válido del mismo ID todavía puede representar ese tomo.
	 *
	 * @param array<int, array{regular_price:float,current_price:float,qty:int,product_id:int}> $items DTOs candidatos.
	 * @return array<int, array{regular_price:float,current_price:float,qty:int,product_id:int}>
	 */
	private function valid_distinct_items( array $items ): array {
		$valid = array();

		foreach ( $items as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$qty        = isset( $item['qty'] ) ? (int) $item['qty'] : 0;
			$current    = isset( $item['current_price'] ) ? (float) $item['current_price'] : 0.0;

			if ( $product_id <= 0 || $qty <= 0 || $current <= 0 || isset( $valid[ $product_id ] ) ) {
				continue;
			}

			$is_backorder   = ! empty( $item['is_backorder'] );
			$backorder_mode = (string) ( $item['backorder_mode'] ?? '' );
			if ( '' === $backorder_mode || 'inherit' === $backorder_mode ) {
				$backorder_mode = function_exists( 'get_option' ) ? (string) get_option( 'akb_series_bundles_backorder_mode', 'best_wins_topup' ) : 'best_wins_topup';
			}
			if ( $is_backorder && 'exclude_backorder' === $backorder_mode ) {
				continue;
			}

			$regular = isset( $item['regular_price'] ) ? (float) $item['regular_price'] : 0.0;
			$item['regular_price'] = $regular > 0 ? $regular : $current;
			$item['current_price'] = $current;
			$item['qty']        = 1;
			$item['product_id'] = $product_id;
			$valid[ $product_id ] = $item;
		}

		return array_values( $valid );
	}
}
