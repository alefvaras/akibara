<?php
/**
 * PartialRefundBundleGuard — Re-liquidación de Packs en Reembolsos Parciales (v1.1).
 *
 * Evita el exploit de relleno con reserva y cancelación parcial posterior:
 * si una orden beneficiada con Arma tu Pack sufre un reembolso parcial que
 * reduce los tomos de la serie bajo el umbral mínimo (5 tomos), se calcula
 * y registra la deducción del descuento de pack perdido en los tomos conservados.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

use Akibara\Business\Support\BusinessOptions;

defined( 'ABSPATH' ) || exit;

final class PartialRefundBundleGuard {

	public const META_CLAWBACK = '_akb_sb_partial_refund_clawback_clp';

	public function register(): void {
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 20, 2 );
	}

	public function on_order_refunded( $order_id, $refund_id = null ): void {
		if ( ! BusinessOptions::sb_clawback_on_partial_refund() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$has_bundle_coupon = false;
		foreach ( $order->get_coupon_codes() as $code ) {
			if ( 0 === strpos( (string) $code, BundleCouponEngine::COUPON_PREFIX ) ) {
				$has_bundle_coupon = true;
				break;
			}
		}

		if ( ! $has_bundle_coupon ) {
			return;
		}

		$clawback_clp = self::calculate_clawback( $order );
		if ( $clawback_clp > 0.0 ) {
			$order->update_meta_data( self::META_CLAWBACK, $clawback_clp );

			$retained_count = self::get_retained_distinct_count( $order );
			if ( $retained_count < 5 ) {
				$note = sprintf(
					'Auditoría Reembolso Parcial (Arma tu Pack): Los tomos conservados bajaron del umbral mínimo del pack (< 5 tomos). Descuento de pack revocado en los tomos conservados: -$%s CLP. Deducción contable recomendada en el reembolso.',
					number_format( $clawback_clp, 0, ',', '.' )
				);
			} else {
				$note = sprintf(
					'Auditoría Reembolso Parcial (Arma tu Pack): Los tomos conservados bajaron del tramo de 7+ tomos (10%% OFF) al tramo de 5-6 tomos (8%% OFF). Ajuste de descuento en los tomos conservados: -$%s CLP. Deducción contable recomendada en el reembolso.',
					number_format( $clawback_clp, 0, ',', '.' )
				);
			}

			$order->add_order_note( $note );
			$order->save_meta_data();
		}
	}

	public static function get_retained_distinct_count( \WC_Order $order ): int {
		$retained_count = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$original_qty = (int) $item->get_quantity();
			$refunded_qty = absint( $order->get_qty_refunded_for_item( $item->get_id() ) );
			$net_qty      = max( 0, $original_qty - $refunded_qty );
			if ( $net_qty > 0 ) {
				++$retained_count;
			}
		}
		return $retained_count;
	}

	public static function calculate_clawback( \WC_Order $order ): float {
		$original_pack_discount = (float) $order->get_discount_total();
		if ( $original_pack_discount <= 0.0 ) {
			return 0.0;
		}

		// Agrupar líneas del pedido por serie (o grupo único si no hay taxonomía disponible en entorno sintético)
		$series_groups = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$original_qty = (int) $item->get_quantity();
			if ( $original_qty <= 0 ) {
				continue;
			}

			$refunded_qty = absint( $order->get_qty_refunded_for_item( $item->get_id() ) );
			$net_qty      = max( 0, $original_qty - $refunded_qty );

			// Determinar precio unitario base real de la línea (o fallback de tankobon $9.990 si la línea sintética no trae subtotal)
			$unit_price = 9990.0;
			try {
				if ( method_exists( $item, 'get_subtotal' ) && (float) $item->get_subtotal() > 0.0 ) {
					$unit_price = ( (float) $item->get_subtotal() + ( method_exists( $item, 'get_subtotal_tax' ) ? (float) $item->get_subtotal_tax() : 0.0 ) ) / $original_qty;
				} elseif ( method_exists( $item, 'get_product' ) ) {
					$prod = $item->get_product();
					if ( $prod instanceof \WC_Product && (float) $prod->get_regular_price() > 0.0 ) {
						$unit_price = (float) $prod->get_regular_price();
					}
				}
			} catch ( \Throwable $e ) {
				$unit_price = 9990.0;
			}

			$serie_key  = 'default';
			$product_id = 0;
			try {
				$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
			} catch ( \Throwable $e ) {
				$product_id = 0;
			}
			if ( $product_id > 0 && function_exists( 'get_the_terms' ) ) {
				$terms = get_the_terms( $product_id, 'pa_serie' );
				if ( is_array( $terms ) && ! empty( $terms ) && isset( $terms[0]->term_id ) ) {
					$serie_key = 'serie_' . (int) $terms[0]->term_id;
				}
			}

			if ( ! isset( $series_groups[ $serie_key ] ) ) {
				$series_groups[ $serie_key ] = array(
					'orig_items'     => array(),
					'retained_items' => array(),
				);
			}

			$series_groups[ $serie_key ]['orig_items'][] = $unit_price;
			if ( $net_qty > 0 ) {
				$series_groups[ $serie_key ]['retained_items'][] = $unit_price;
			}
		}

		$tier1_min = BusinessOptions::sb_tier1_min_qty();
		$tier1_pct = BusinessOptions::sb_tier1_pct();
		$tier2_min = BusinessOptions::sb_tier2_min_qty();
		$tier2_pct = BusinessOptions::sb_tier2_pct();

		$total_clawback = 0.0;
		foreach ( $series_groups as $group ) {
			$orig_count     = count( $group['orig_items'] );
			$retained_count = count( $group['retained_items'] );

			$orig_pct = $orig_count >= $tier2_min ? $tier2_pct : ( $orig_count >= $tier1_min ? $tier1_pct : 0 );
			if ( 0 === $orig_pct || 0 === $retained_count ) {
				continue;
			}

			$new_pct = $retained_count >= $tier2_min ? $tier2_pct : ( $retained_count >= $tier1_min ? $tier1_pct : 0 );
			if ( $new_pct < $orig_pct ) {
				foreach ( $group['retained_items'] as $u_price ) {
					$orig_benefit    = round( $u_price * ( $orig_pct / 100.0 ) );
					$new_benefit     = round( $u_price * ( $new_pct / 100.0 ) );
					$total_clawback += max( 0.0, $orig_benefit - $new_benefit );
				}
			}
		}

		return min( $original_pack_discount, max( 0.0, $total_clawback ) );
	}
}
