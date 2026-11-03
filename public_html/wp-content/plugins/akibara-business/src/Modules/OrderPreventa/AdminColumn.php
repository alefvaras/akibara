<?php
/**
 * Columna "Preventa" en la lista admin de órdenes (HPOS).
 *
 * Estados visuales (prioridad descendente):
 *   - sin preventa            → vacío (no decora pedidos normales).
 *   - preventa lista          → 🟢 "Lista para despachar".
 *   - preventa pendiente      → 🟡 "Pendiente · llega ~28 may" (o "fecha por
 *     confirmar" si no hay estimado vigente).
 *
 * Regla de display: decide EXCLUSIVAMENTE el estimado vigente
 * (`SummaryRecalculator::current_max_ts`) — el snapshot TBA ya no participa.
 * Enumeración any_tba × summary: (1) puro-TBA sin anunciar → sin summary →
 * "por confirmar"; (2) TBA anunciado/recomputado (o mixto) → summary presente
 * → fecha (el caso mixto cambia de display a sabiendas: indistinguible del
 * puro-TBA-anunciado sin N+1 de productos, y el `~` comunica estimación);
 * (3) orden normal con fecha → fecha; (4) snapshot sin ETA o post-retracted →
 * sin summary → "por confirmar" (jamás una fecha 1970).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Utilities\StoreTime;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Columna nueva en /wp-admin/admin.php?page=wc-orders.
 *
 * @since 1.14.0
 */
final class AdminColumn {

	private const COLUMN_ID = 'akb_preventa';

	public function register(): void {
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'register_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_print_styles', array( $this, 'print_styles' ) );
	}

	/**
	 * Inserta la columna "Reserva" antes de la columna "total".
	 *
	 * @param array<string, string> $columns Map column_id → label.
	 * @return array<string, string>
	 */
	public function register_column( array $columns ): array {
		$out = array();
		foreach ( $columns as $id => $label ) {
			if ( 'order_total' === $id ) {
				$out[ self::COLUMN_ID ] = 'Reserva';
			}
			$out[ $id ] = $label;
		}
		// Fallback: si no encontramos order_total, agregamos al final.
		if ( ! isset( $out[ self::COLUMN_ID ] ) ) {
			$out[ self::COLUMN_ID ] = 'Reserva';
		}
		return $out;
	}

	/**
	 * Renderiza el badge para la celda.
	 *
	 * @param string   $column_id Id de columna actual.
	 * @param WC_Order $order     Orden de la fila.
	 * @return void
	 */
	public function render_column( $column_id, $order ): void {
		if ( self::COLUMN_ID !== $column_id ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}

		// Cada tramo va en nowrap y el contenedor envuelve: cuando la columna
		// se estrecha, el badge se parte entre «Pendiente» y la fecha, nunca
		// entre el emoji y la palabra ni dentro de «~28 may». Las palabras
		// marcadas `__w` son las que la versión compacta (tablet, ver
		// print_styles) esconde para lectores visuales sin sacarlas del DOM.
		if ( 'yes' === (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) ) {
			echo '<span class="akb-op-badge akb-op-badge--lista">';
			echo '<span class="akb-op-badge__k"><span aria-hidden="true">🟢</span> Lista</span> ';
			echo '<span class="akb-op-badge__l akb-op-badge__w">para despachar</span>';
			echo '</span>';
			return;
		}

		$summary_max = SummaryRecalculator::current_max_ts( $order );

		echo '<span class="akb-op-badge akb-op-badge--pendiente">';
		echo '<span class="akb-op-badge__k"><span aria-hidden="true">🟡</span> <span class="akb-op-badge__w">Pendiente</span></span> ';
		if ( null === $summary_max ) {
			echo '<span class="akb-op-badge__l"><span class="akb-op-badge__w">· fecha </span>por confirmar</span>';
		} elseif ( $summary_max < StoreTime::start_of_today() ) {
			// El mismo corte que ve el cliente («Llegaba ~25 Ago · atrasado»):
			// si escribe citando su pantalla, el dueño tiene que ver lo mismo.
			$label = esc_html( wp_date( 'j M', $summary_max ) );
			echo '<span class="akb-op-badge__l" style="color:#b32d2e;font-weight:600;"><span class="akb-op-badge__w">· llegaba ~</span>' . $label . '<span class="akb-op-badge__w"> · atrasado</span></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $label ya viene de esc_html().

			// Distinguir el atraso operativo del vencimiento del plazo de reembolso
			// publicado, compartiendo la señal con ReadyToShipCron sin añadir otra columna.

			if ( 'yes' === (string) $order->get_meta( MetaKeys::ORDER_REFUND_ELIGIBLE_NOTED ) ) {
				echo ' <span class="akb-op-badge__l akb-op-badge--reembolso" style="color:#b32d2e;font-weight:700;">· reembolso 100%</span>';
			}
		} else {
			$label = esc_html( wp_date( 'j M', $summary_max ) );
			echo '<span class="akb-op-badge__l"><span class="akb-op-badge__w">· llega ~</span>' . $label . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $label ya viene de esc_html().
		}
		echo '</span>';

		if ( 'yes' === (string) $order->get_meta( '_akb_mixed_consolidation_required' ) ) {
			$stock_count   = (int) $order->get_meta( '_akb_mixed_instock_count' );
			$pending_items = (int) $order->get_meta( '_akb_mixed_pending_count' );
			echo '<div style="margin-top:4px;"><span class="akb-op-badge" style="background:#fef3c7;border-color:#f59e0b;color:#92400e;font-size:11px;font-weight:600;padding:2px 6px;">📦 Consolidar en bodega (' . (int) $stock_count . ' stock + ' . (int) $pending_items . ' a pedido)</span></div>';
		}
	}

	/**
	 * Acota el badge a la tabla de pedidos de WooCommerce.
	 *
	 * Por debajo de 1100 px la columna usa el 7% del ancho y presenta el badge
	 * compacto con emoji y fecha. El texto completo se conserva en el DOM sin
	 * forzar el desbordamiento de una tabla con table-layout: fixed.
	 *
	 * @internal Callback de `admin_print_styles`.
	 */
	public function print_styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'woocommerce_page_wc-orders' !== $screen->id ) {
			return;
		}
		echo '<style id="akb-op-badge">'
			. '.akb-op-badge{display:inline-flex;flex-wrap:wrap;column-gap:4px;align-items:center;font-weight:600}'
			. '.akb-op-badge--lista{color:#1e7e34}.akb-op-badge--pendiente{color:#856404}'
			. '.akb-op-badge__k,.akb-op-badge__l{white-space:nowrap}'
			. '@media (max-width:1100px){th#akb_preventa{width:7%}'
			. '.akb-op-badge__w{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}}'
			. '</style>';
	}
}
