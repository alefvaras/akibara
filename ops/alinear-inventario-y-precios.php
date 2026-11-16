<?php
/**
 * alinear-inventario-y-precios.php — Alineación Canónica de Inventario y Precios (ADR-010).
 *
 * Propósito:
 * 1. Respetar 100% las órdenes activas: NUNCA toca wp_woocommerce_order_items ni order_itemmeta.
 * 2. Convertir cualquier producto residual en onbackorder a outofstock (_manage_stock = 'yes',
 *    _stock = 0, _backorders = 'no'), eliminando precios de reserva (_sale_price) y restaurando _price = _regular_price.
 * 3. Blindar productos instock (_backorders = 'no') y outofstock (sin oferta, _backorders = 'no').
 *
 * Modos:
 *   php ops/alinear-inventario-y-precios.php [--dry-run]
 *
 * @package Akibara\Ops
 */

declare( strict_types = 1 );

$dry_run = in_array( '--dry-run', $argv ?? array(), true )
	|| in_array( 'dry-run', $argv ?? array(), true )
	|| in_array( 'dry-run', $args ?? array(), true );

global $wpdb;

echo "============================================================\n";
echo "  ALINEACIÓN CANÓNICA DE INVENTARIO Y PRECIOS (ADR-010)\n";
echo "  Modo: " . ( $dry_run ? "SIMULACIÓN (--dry-run)" : "EN VIVO (ESCRITURA)" ) . "\n";
echo "============================================================\n\n";

// -------------------------------------------------------------
// FASE 1: Erradicación de onbackorder residuales -> outofstock (stock 0)
// -------------------------------------------------------------
echo "[1/3] Verificando ausencia de tomos en onbackorder...\n";

$onbackorder_products = $wpdb->get_results(
	"SELECT p.ID, p.post_title, pm_reg.meta_value as regular_price
	 FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} pm_st ON p.ID = pm_st.post_id AND pm_st.meta_key = '_stock_status' AND pm_st.meta_value = 'onbackorder'
	 LEFT JOIN {$wpdb->postmeta} pm_reg ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price'
	 WHERE p.post_type = 'product' AND p.post_status = 'publish'
	 ORDER BY p.ID ASC",
	ARRAY_A
);

echo "  • Productos en onbackorder residuales: " . count( $onbackorder_products ) . "\n";
foreach ( $onbackorder_products as $p ) {
	$pid = (int) $p['ID'];
	$reg = (string) ( $p['regular_price'] ?? '' );
	echo sprintf( "    * [%d] %s -> Migrando a outofstock (stock=0, backorders=no, sin oferta)\n", $pid, $p['post_title'] );

	if ( ! $dry_run ) {
		update_post_meta( $pid, '_stock_status', 'outofstock' );
		update_post_meta( $pid, '_manage_stock', 'yes' );
		update_post_meta( $pid, '_stock', '0' );
		update_post_meta( $pid, '_backorders', 'no' );
		delete_post_meta( $pid, '_sale_price' );
		if ( '' !== $reg ) {
			update_post_meta( $pid, '_price', $reg );
		}
		wp_set_object_terms( $pid, 'outofstock', 'product_visibility', true );
	}
}

// -------------------------------------------------------------
// FASE 2: Blindaje de Stock Físico (instock -> backorders=no)
// -------------------------------------------------------------
echo "\n[2/3] Verificando blindaje de stock físico (instock)...\n";

$instock_products = $wpdb->get_results(
	"SELECT p.ID, p.post_title, pm_bo.meta_value as backorders, pm_stk.meta_value as stock_qty
	 FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} pm_st ON p.ID = pm_st.post_id AND pm_st.meta_key = '_stock_status' AND pm_st.meta_value = 'instock'
	 LEFT JOIN {$wpdb->postmeta} pm_bo ON p.ID = pm_bo.post_id AND pm_bo.meta_key = '_backorders'
	 LEFT JOIN {$wpdb->postmeta} pm_stk ON p.ID = pm_stk.post_id AND pm_stk.meta_key = '_stock'
	 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND pm_bo.meta_value != 'no'
	 ORDER BY p.ID ASC",
	ARRAY_A
);

echo "  • Productos instock con backorders permitidos: " . count( $instock_products ) . "\n";
foreach ( $instock_products as $p ) {
	$pid = (int) $p['ID'];
	echo sprintf(
		"    * [%d] %s: stock=%s, backorders='%s' -> Fijando backorders=no\n",
		$pid,
		$p['post_title'],
		(string) $p['stock_qty'],
		(string) $p['backorders']
	);

	if ( ! $dry_run ) {
		update_post_meta( $pid, '_backorders', 'no' );
	}
}

// -------------------------------------------------------------
// FASE 3: Blindaje de Agotados (outofstock -> backorders=no, sin oferta)
// -------------------------------------------------------------
echo "\n[3/3] Verificando blindaje de tomos Agotados (outofstock)...\n";

$outofstock_products = $wpdb->get_results(
	"SELECT p.ID, p.post_title,
	        pm_reg.meta_value as regular_price,
	        pm_sale.meta_value as sale_price,
	        pm_bo.meta_value as backorders
	 FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} pm_st ON p.ID = pm_st.post_id AND pm_st.meta_key = '_stock_status' AND pm_st.meta_value = 'outofstock'
	 LEFT JOIN {$wpdb->postmeta} pm_reg ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price'
	 LEFT JOIN {$wpdb->postmeta} pm_sale ON p.ID = pm_sale.post_id AND pm_sale.meta_key = '_sale_price'
	 LEFT JOIN {$wpdb->postmeta} pm_bo ON p.ID = pm_bo.post_id AND pm_bo.meta_key = '_backorders'
	 WHERE p.post_type = 'product' AND p.post_status = 'publish'
	   AND (
	       pm_bo.meta_value != 'no'
	       OR (pm_sale.meta_value IS NOT NULL AND pm_sale.meta_value != '')
	   )
	 ORDER BY p.ID ASC",
	ARRAY_A
);

echo "  • Productos outofstock desalineados: " . count( $outofstock_products ) . "\n";
foreach ( $outofstock_products as $p ) {
	$pid = (int) $p['ID'];
	$reg = (string) ( $p['regular_price'] ?? '' );
	echo sprintf(
		"    * [%d] %s: backorders='%s', sale='%s' -> Alineando a backorders=no, sin oferta\n",
		$pid,
		$p['post_title'],
		(string) $p['backorders'],
		(string) $p['sale_price']
	);

	if ( ! $dry_run ) {
		update_post_meta( $pid, '_backorders', 'no' );
		delete_post_meta( $pid, '_sale_price' );
		if ( '' !== $reg ) {
			update_post_meta( $pid, '_price', $reg );
		}
	}
}

echo "\n============================================================\n";
echo "  RESULTADO: " . ( $dry_run ? "Simulación completada sin modificaciones." : "Alineación aplicada con éxito." ) . "\n";
echo "  Onbackorder erradicados: " . count( $onbackorder_products ) . "\n";
echo "  Stock físico blindado:   " . count( $instock_products ) . "\n";
echo "  Agotados blindados:      " . count( $outofstock_products ) . "\n";
echo "============================================================\n";
