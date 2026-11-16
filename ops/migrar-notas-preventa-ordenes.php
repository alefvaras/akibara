<?php
/**
 * Paso 0: Auditoría e inyección de Notas en WooCommerce para órdenes con Preventa / A Pedido
 * Ejecutar ANTES de purgar metadatos de OrderPreventa.
 * Uso: wp eval-file ops/migrar-notas-preventa-ordenes.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$statuses = array( 'wc-processing', 'wc-on-hold', 'wc-pending' );
$orders   = wc_get_orders( array(
	'status' => $statuses,
	'limit'  => -1,
	'return' => 'objects',
) );

$marked_count = 0;

foreach ( $orders as $order ) {
	$preventa_lines = array();

	foreach ( $order->get_items() as $item_id => $item ) {
		if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
			continue;
		}

		// Revisar metadatos de ítem (LineItemSnapshot / MetaKeys) y estado del producto
		$item_metas = $item->get_meta_data();
		$meta_dump  = array();
		foreach ( $item_metas as $meta ) {
			$data = $meta->get_data();
			if ( false !== stripos( $data['key'], 'preventa' )
				|| false !== stripos( $data['key'], 'pedido' )
				|| false !== stripos( $data['key'], 'eta' )
				|| false !== stripos( $data['key'], 'promise' )
				|| false !== stripos( $data['key'], 'backorder' ) ) {
				$meta_dump[] = $data['key'] . ': ' . wp_json_encode( $data['value'] );
			}
		}

		$product              = $item->get_product();
		$is_backorder_product = $product && ( $product->is_on_backorder() || 'no' !== $product->get_backorders() );

		if ( ! empty( $meta_dump ) || $is_backorder_product ) {
			$preventa_lines[] = sprintf(
				'- %s (ID: %d x%d) [%s]',
				$item->get_name(),
				$item->get_product_id(),
				$item->get_quantity(),
				! empty( $meta_dump ) ? implode( ' | ', $meta_dump ) : 'Producto en backorder'
			);
		}
	}

	// Revisar también metadatos a nivel de orden
	$order_preventa_meta = array();
	foreach ( $order->get_meta_data() as $meta ) {
		$data = $meta->get_data();
		if ( false !== stripos( $data['key'], 'preventa' ) || false !== stripos( $data['key'], 'promise' ) ) {
			$order_preventa_meta[] = $data['key'] . ': ' . wp_json_encode( $data['value'] );
		}
	}

	if ( ! empty( $preventa_lines ) || ! empty( $order_preventa_meta ) ) {
		// Evitar duplicar la nota si el script se corre más de una vez (idempotencia)
		$already_noted = $order->get_meta( '_akb_legacy_preventa_audit_noted', true );
		if ( $already_noted ) {
			continue;
		}

		$note  = "📌 [AUDITORÍA DEPRECACIÓN PREVENTA / A PEDIDO]\n";
		$note .= "Esta orden contenía ítems o metadatos bajo la modalidad legada de Preventa / A Pedido al momento del cierre del módulo:\n";
		if ( ! empty( $preventa_lines ) ) {
			$note .= implode( "\n", $preventa_lines ) . "\n";
		}
		if ( ! empty( $order_preventa_meta ) ) {
			$note .= 'Metadatos de orden: ' . implode( ' | ', $order_preventa_meta );
		}

		$order->add_order_note( $note, 0, false ); // 0 = nota privada interna
		$order->update_meta_data( '_akb_legacy_preventa_audit_noted', current_time( 'mysql' ) );
		$order->save();
		$marked_count++;

		if ( class_exists( 'WP_CLI' ) ) {
			\WP_CLI::log( sprintf( 'Orden #%d marcada con nota de auditoría.', $order->get_id() ) );
		}
	}
}

if ( class_exists( 'WP_CLI' ) ) {
	\WP_CLI::success( sprintf( 'Proceso completado. Total de órdenes marcadas: %d', $marked_count ) );
}
