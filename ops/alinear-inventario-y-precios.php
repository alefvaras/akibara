<?php
/**
 * alinear-inventario-y-precios.php — Alineación Canónica de Precios y Contratos A Pedido (Regla 18).
 *
 * Propósito:
 * 1. Respetar 100% las órdenes activas: NUNCA toca wp_woocommerce_order_items ni order_itemmeta.
 * 2. Alinear los precios de catálogo público (_sale_price y _price) para tomos en onbackorder
 *    al 5% de descuento con redondeo a la centena (round_100), según instruyó el dueño.
 * 3. Alinear el contrato de inventario de tomos en onbackorder al modelo canónico de Akibara
 *    (_manage_stock = 'no', _backorders = 'no', _stock = null), eliminando residuos históricos.
 *
 * Modos:
 *   php ops/alinear-inventario-y-precios.php [--dry-run]
 *
 * @package Akibara\Ops
 */

declare( strict_types = 1 );

$is_cli  = ( 'cli' === php_sapi_name() || defined( 'WP_CLI' ) && WP_CLI );
$dry_run = in_array( '--dry-run', $argv ?? array(), true )
	|| in_array( 'dry-run', $argv ?? array(), true )
	|| in_array( 'dry-run', $args ?? array(), true );

global $wpdb;

echo "============================================================\n";
echo "  ALINEACIÓN CANÓNICA DE INVENTARIO Y PRECIOS (REGLA 18)\n";
echo "  Modo: " . ( $dry_run ? "SIMULACIÓN (--dry-run)" : "EN VIVO (ESCRITURA)" ) . "\n";
echo "============================================================\n\n";

// -------------------------------------------------------------
// FASE 1: Alineación de Precios de Catálogo (5% round_100)
// -------------------------------------------------------------
echo "[1/2] Verificando precios de tomos A Pedido (onbackorder)...\n";

$onbackorder_products = $wpdb->get_results(
	"SELECT p.ID, p.post_title,
	        pm_reg.meta_value as regular_price,
	        pm_sale.meta_value as sale_price,
	        pm_price.meta_value as price
	 FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} pm_st ON p.ID = pm_st.post_id AND pm_st.meta_key = '_stock_status' AND pm_st.meta_value = 'onbackorder'
	 JOIN {$wpdb->postmeta} pm_reg ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price'
	 LEFT JOIN {$wpdb->postmeta} pm_sale ON p.ID = pm_sale.post_id AND pm_sale.meta_key = '_sale_price'
	 LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
	 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND CAST(pm_reg.meta_value AS SIGNED) > 0
	 ORDER BY p.ID ASC",
	ARRAY_A
);

$price_fixes = array();
foreach ( $onbackorder_products as $p ) {
	$pid         = (int) $p['ID'];
	$reg         = (float) $p['regular_price'];
	$current_sal = null !== $p['sale_price'] && '' !== $p['sale_price'] ? (float) $p['sale_price'] : null;
	$current_prc = null !== $p['price'] && '' !== $p['price'] ? (float) $p['price'] : null;
	$target_sal  = (float) ( round( ( $reg * 0.95 ) / 100.0 ) * 100 );

	if ( $current_sal !== $target_sal || $current_prc !== $target_sal ) {
		$price_fixes[] = array(
			'id'          => $pid,
			'title'       => $p['post_title'],
			'reg'         => $reg,
			'current_sal' => $current_sal,
			'target_sal'  => $target_sal,
		);
	}
}

echo "  • Productos A Pedido con discrepancia de precio: " . count( $price_fixes ) . "\n";
foreach ( $price_fixes as $fix ) {
	echo sprintf(
		"    * [%d] %s: Reg $%s | Actual $%s -> Objetivo 5%% $%s\n",
		$fix['id'],
		$fix['title'],
		number_format( $fix['reg'], 0, ',', '.' ),
		null !== $fix['current_sal'] ? number_format( $fix['current_sal'], 0, ',', '.' ) : 'Sin oferta',
		number_format( $fix['target_sal'], 0, ',', '.' )
	);

	if ( ! $dry_run ) {
		update_post_meta( $fix['id'], '_sale_price', (string) ( (int) $fix['target_sal'] ) );
		update_post_meta( $fix['id'], '_price', (string) ( (int) $fix['target_sal'] ) );
	}
}

// -------------------------------------------------------------
// FASE 2: Alineación de Contrato de Inventario (manage=no, backorders=no, stock=null)
// -------------------------------------------------------------
echo "\n[2/2] Verificando contrato de inventario de tomos A Pedido...\n";

$contract_products = $wpdb->get_results(
	"SELECT p.ID, p.post_title,
	        pm_man.meta_value as manage_stock,
	        pm_bo.meta_value as backorders,
	        pm_stk.meta_value as stock_qty
	 FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} pm_st ON p.ID = pm_st.post_id AND pm_st.meta_key = '_stock_status' AND pm_st.meta_value = 'onbackorder'
	 LEFT JOIN {$wpdb->postmeta} pm_man ON p.ID = pm_man.post_id AND pm_man.meta_key = '_manage_stock'
	 LEFT JOIN {$wpdb->postmeta} pm_bo ON p.ID = pm_bo.post_id AND pm_bo.meta_key = '_backorders'
	 LEFT JOIN {$wpdb->postmeta} pm_stk ON p.ID = pm_stk.post_id AND pm_stk.meta_key = '_stock'
	 WHERE p.post_type = 'product' AND p.post_status = 'publish'
	   AND (
	       pm_man.meta_value != 'no'
	       OR pm_bo.meta_value != 'no'
	       OR (pm_stk.meta_value IS NOT NULL AND pm_stk.meta_value != '' AND pm_stk.meta_value != '0')
	   )
	 ORDER BY p.ID ASC",
	ARRAY_A
);

echo "  • Productos A Pedido con contrato no canónico: " . count( $contract_products ) . "\n";
foreach ( $contract_products as $p ) {
	$pid = (int) $p['ID'];
	echo sprintf(
		"    * [%d] %s: manage='%s', backorders='%s', stock='%s' -> Alineando a manage=no, backorders=no, stock=null\n",
		$pid,
		$p['post_title'],
		(string) $p['manage_stock'],
		(string) $p['backorders'],
		(string) $p['stock_qty']
	);

	if ( ! $dry_run ) {
		update_post_meta( $pid, '_manage_stock', 'no' );
		update_post_meta( $pid, '_backorders', 'no' );
		delete_post_meta( $pid, '_stock' );
	}
}

// -------------------------------------------------------------
// FASE 3: Blindaje de Stock Físico (instock -> backorders=no)
// -------------------------------------------------------------
echo "\n[3/4] Verificando blindaje de stock físico (instock)...\n";

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
		"    * [%d] %s: stock=%s, backorders='%s' -> Fijando backorders=no para evitar sobreventa\n",
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
// FASE 4: Blindaje de Agotados (outofstock -> manage=no, stock=null, backorders=no, sale=null)
// -------------------------------------------------------------
echo "\n[4/4] Verificando blindaje de tomos Agotados (outofstock)...\n";

$outofstock_products = $wpdb->get_results(
	"SELECT p.ID, p.post_title,
	        pm_reg.meta_value as regular_price,
	        pm_sale.meta_value as sale_price,
	        pm_man.meta_value as manage_stock,
	        pm_bo.meta_value as backorders
	 FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} pm_st ON p.ID = pm_st.post_id AND pm_st.meta_key = '_stock_status' AND pm_st.meta_value = 'outofstock'
	 LEFT JOIN {$wpdb->postmeta} pm_reg ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price'
	 LEFT JOIN {$wpdb->postmeta} pm_sale ON p.ID = pm_sale.post_id AND pm_sale.meta_key = '_sale_price'
	 LEFT JOIN {$wpdb->postmeta} pm_man ON p.ID = pm_man.post_id AND pm_man.meta_key = '_manage_stock'
	 LEFT JOIN {$wpdb->postmeta} pm_bo ON p.ID = pm_bo.post_id AND pm_bo.meta_key = '_backorders'
	 WHERE p.post_type = 'product' AND p.post_status = 'publish'
	   AND (
	       pm_man.meta_value != 'no'
	       OR pm_bo.meta_value != 'no'
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
		"    * [%d] %s: manage='%s', backorders='%s', sale='%s' -> Alineando a manage=no, backorders=no, sin oferta\n",
		$pid,
		$p['post_title'],
		(string) $p['manage_stock'],
		(string) $p['backorders'],
		(string) $p['sale_price']
	);

	if ( ! $dry_run ) {
		update_post_meta( $pid, '_manage_stock', 'no' );
		update_post_meta( $pid, '_backorders', 'no' );
		delete_post_meta( $pid, '_stock' );
		delete_post_meta( $pid, '_sale_price' );
		if ( '' !== $reg ) {
			update_post_meta( $pid, '_price', $reg );
		}
	}
}

echo "\n============================================================\n";
echo "  RESULTADO: " . ( $dry_run ? "Simulación completada sin modificaciones." : "Alineación aplicada con éxito." ) . "\n";
echo "  Precios actualizados:   " . count( $price_fixes ) . "\n";
echo "  Contratos A Pedido:     " . count( $contract_products ) . "\n";
echo "  Stock físico blindado:  " . count( $instock_products ) . "\n";
echo "  Agotados blindados:     " . count( $outofstock_products ) . "\n";
echo "============================================================\n";
