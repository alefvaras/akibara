<?php
/**
 * Renderea la información de "preventa al comprar" debajo de cada line item
 * en la vista detalle de orden (wp-admin → orden individual).
 *
 * Ejemplo de salida (Yu Yu Hakusho Kanzenban 08, AR, ETA 28may-1jun):
 *
 *   📦 Preventa al comprar · ETA 28 may – 1 jun · Argentina
 *
 * Si ya pasó a instock pero la orden sigue sin despacharse:
 *
 *   📦 Preventa al comprar · ETA 28 may – 1 jun · Argentina · ✓ Disponible hoy
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Modules\Stock\StockStatus;
use WC_Order_Item;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Inyecta info de preventa en la vista admin de cada line item.
 *
 * Display name del país viene del snapshot (`ITEM_COUNTRY_NAME_AT_PURCHASE`)
 * — capturado al checkout desde `$term->name` del taxonomy pa_pais. Esto evita
 * tabla hardcoded de mappings y sobrevive a rename del término por el owner.
 *
 * @since 1.14.0
 */
final class OrderItemDisplay {

	public function register(): void {
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render' ), 10, 2 );
	}

	/**
	 * Renderiza la info debajo del nombre del producto.
	 *
	 * @param int           $item_id Order item id.
	 * @param WC_Order_Item $item    Order item.
	 * @return void
	 */
	public function render( $item_id, $item ): void {
		unset( $item_id );

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}
		if ( 'yes' !== (string) $item->get_meta( MetaKeys::ITEM_WAS_PREVENTA ) ) {
			return;
		}

		// La palabra sale del SNAPSHOT de compra (nunca del estado de hoy): la
		// línea describe lo que el cliente aceptó en ese momento. Mismo criterio
		// que la línea del cliente, el correo y el rastreador.
		$parts = array( PreventaItems::era_preventa( $item ) ? '📦 <strong>Preventa al comprar</strong>' : '📦 <strong>A pedido al comprar</strong>' );

		$is_tba       = 'yes' === (string) $item->get_meta( MetaKeys::ITEM_IS_TBA_AT_PURCHASE );
		$min_ts       = (int) $item->get_meta( MetaKeys::ITEM_MIN_AT_PURCHASE );
		$max_ts       = (int) $item->get_meta( MetaKeys::ITEM_MAX_AT_PURCHASE );
		$country_name = (string) $item->get_meta( MetaKeys::ITEM_COUNTRY_NAME_AT_PURCHASE );

		if ( $is_tba ) {
			$parts[] = 'Sin fecha al comprar';
		} elseif ( $min_ts > 0 && $max_ts > 0 ) {
			$parts[] = sprintf(
				'ETA %s &ndash; %s',
				esc_html( wp_date( 'j M', $min_ts ) ),
				esc_html( wp_date( 'j M', $max_ts ) )
			);
		}

		// Fallback al slug si el name no fue capturado (snapshots pre-refactor o
		// productos sin término al checkout).
		if ( '' === $country_name ) {
			$country_name = (string) $item->get_meta( MetaKeys::ITEM_COUNTRY_AT_PURCHASE );
			$country_name = '' !== $country_name ? ucfirst( $country_name ) : '';
		}
		if ( '' !== $country_name ) {
			$parts[] = esc_html( $country_name );
		}

		// Si la línea ya fue sellada físicamente en bodega, mostramos "🔒 Apartado en casillero".
		if ( 'yes' === (string) $item->get_meta( MetaKeys::ITEM_ARRIVED ) ) {
			$arrived_at = (int) $item->get_meta( MetaKeys::ITEM_ARRIVED_AT );
			$date_str   = $arrived_at > 0 ? ' (' . esc_html( wp_date( 'j M', $arrived_at ) ) . ')' : '';
			$parts[]    = '<span style="color:#006505;font-weight:600;">🔒 Apartado en casillero' . $date_str . '</span>';
		} elseif ( (int) $item->get_meta( MetaKeys::ITEM_ARRIVED_QTY ) > 0 ) {
			$partial = (int) $item->get_meta( MetaKeys::ITEM_ARRIVED_QTY );
			$total   = (int) $item->get_quantity();
			$parts[] = sprintf(
				'<span style="color:#006505;font-weight:600;">🔒 Apartado parcial (%d/%d uds en casillero)</span>',
				$partial,
				$total
			);
		} else {
			// Si producto ya está instock hoy en catálogo pero no sellado, marcamos "disponible hoy".
			$product = $item->get_product();
			if ( $product && StockStatus::is_instock( $product ) ) {
				// La palabra "Disponible" sale de la fuente única StockStatus::label();
				// el sufijo " hoy" se concatena aparte (es contextual de esta vista de
				// orden, no parte del vocabulario de estado). Color inline #1e7e34: es
				// pantalla admin sin CSS de frontend — NO lleva clase del theme.
				$parts[] = '<span style="color:#1e7e34;">&check; ' . esc_html( StockStatus::label( StockStatus::IN_STOCK ) ) . ' hoy</span>';
			}
		}

		echo '<div style="margin-top:4px;font-size:12px;color:#50575e;">';
		echo implode( ' · ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- parts are individually escaped above.
		echo '</div>';
	}
}
