<?php
/**
 * saneamiento-776-onbackorder.php — Normalización binaria atómica de 776 productos en onbackorder.
 *
 * Contrato:
 * 1. Respeto absoluto a OrderLockGuard: NUNCA toca pedidos ni order_itemmeta.
 * 2. Transición atómica de 776 productos onbackorder -> outofstock:
 *    - _stock_status = 'outofstock'
 *    - _manage_stock = 'no'
 *    - _stock = '0'
 *    - _backorders = 'no'
 *    - Eliminar _sale_price y fijar _price = _regular_price.
 *    - Vincular término outofstock en product_visibility (term_taxonomy_id = 9).
 *    - Sincronizar wp_wc_product_meta_lookup (stock_status='outofstock', stock_quantity=0, onsale=0, min_price=regular_price, max_price=regular_price).
 *
 * @package Akibara\Ops
 */

declare( strict_types = 1 );

global $wpdb;

$dry_run = in_array( '--dry-run', $argv ?? array(), true )
	|| in_array( 'dry-run', $argv ?? array(), true );

echo "============================================================\n";
echo "  SANEAMIENTO ATÓMICO DE PRODUCTOS ONBACKORDER (PROD)\n";
echo "  Modo: " . ( $dry_run ? "SIMULACIÓN (--dry-run)" : "EN VIVO (ESCRITURA ATÓMICA)" ) . "\n";
echo "============================================================\n\n";

// 1. Obtener los 776 IDs exactos
$products = $wpdb->get_results(
	"SELECT p.ID, p.post_title,
	        pm_reg.meta_value as regular_price,
	        pm_stk.meta_value as stock_qty
	 FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} pm_st ON p.ID = pm_st.post_id AND pm_st.meta_key = '_stock_status' AND pm_st.meta_value = 'onbackorder'
	 LEFT JOIN {$wpdb->postmeta} pm_reg ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price'
	 LEFT JOIN {$wpdb->postmeta} pm_stk ON p.ID = pm_stk.post_id AND pm_stk.meta_key = '_stock'
	 WHERE p.post_type = 'product' AND p.post_status = 'publish'
	 ORDER BY p.ID ASC",
	ARRAY_A
);

$count = count( $products );
echo "Productos detectados en onbackorder: {$count}\n";

if ( 0 === $count ) {
	echo "No hay productos en onbackorder. Saneamiento ya aplicado o no requerido.\n";
	exit( 0 );
}

// Obtener term_taxonomy_id de outofstock
$tt_id = (int) $wpdb->get_var(
	"SELECT tt.term_taxonomy_id
	 FROM {$wpdb->terms} t
	 JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
	 WHERE t.slug = 'outofstock' AND tt.taxonomy = 'product_visibility'"
);

if ( $tt_id <= 0 ) {
	throw new RuntimeException( "No se encontró el term_taxonomy_id de outofstock en product_visibility." );
}

echo "Term taxonomy ID para 'outofstock': {$tt_id}\n\n";

$pids = array_map( fn( $p ) => (int) $p['ID'], $products );
$pid_list = implode( ',', $pids );

if ( $dry_run ) {
	echo "[SIMULACIÓN] Se normalizarían {$count} productos a outofstock sin alterar pedidos.\n";
	echo "[SIMULACIÓN] Primeros 5 IDs: " . implode( ', ', array_slice( $pids, 0, 5 ) ) . "\n";
	exit( 0 );
}

// INICIO TRANSACCIÓN ATÓMICA
$wpdb->query( 'START TRANSACTION' );

try {
	// A) Actualizar _stock_status = 'outofstock'
	$wpdb->query(
		"UPDATE {$wpdb->postmeta}
		 SET meta_value = 'outofstock'
		 WHERE post_id IN ({$pid_list}) AND meta_key = '_stock_status'"
	);

	// B) Actualizar _manage_stock = 'no'
	$wpdb->query(
		"UPDATE {$wpdb->postmeta}
		 SET meta_value = 'no'
		 WHERE post_id IN ({$pid_list}) AND meta_key = '_manage_stock'"
	);

	// Asegurar que exista _manage_stock para los que no tenían la clave
	$wpdb->query(
		"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
		 SELECT p.ID, '_manage_stock', 'no'
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_manage_stock'
		 WHERE p.ID IN ({$pid_list}) AND pm.meta_id IS NULL"
	);

	// C) Actualizar _stock = '0'
	$wpdb->query(
		"UPDATE {$wpdb->postmeta}
		 SET meta_value = '0'
		 WHERE post_id IN ({$pid_list}) AND meta_key = '_stock'"
	);

	// D) Actualizar _backorders = 'no'
	$wpdb->query(
		"UPDATE {$wpdb->postmeta}
		 SET meta_value = 'no'
		 WHERE post_id IN ({$pid_list}) AND meta_key = '_backorders'"
	);

	// Asegurar que exista _backorders para los que no tenían la clave
	$wpdb->query(
		"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
		 SELECT p.ID, '_backorders', 'no'
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_backorders'
		 WHERE p.ID IN ({$pid_list}) AND pm.meta_id IS NULL"
	);

	// E) Eliminar _sale_price (antiguo descuento del 5% de reserva)
	$wpdb->query(
		"DELETE FROM {$wpdb->postmeta}
		 WHERE post_id IN ({$pid_list}) AND meta_key = '_sale_price'"
	);

	// F) Restaurar _price = _regular_price
	$wpdb->query(
		"UPDATE {$wpdb->postmeta} pm_p
		 JOIN {$wpdb->postmeta} pm_r ON pm_p.post_id = pm_r.post_id AND pm_r.meta_key = '_regular_price'
		 SET pm_p.meta_value = pm_r.meta_value
		 WHERE pm_p.post_id IN ({$pid_list}) AND pm_p.meta_key = '_price'"
	);

	// G) Asignar término 'outofstock' en product_visibility
	$wpdb->query(
		"INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order)
		 SELECT p.ID, {$tt_id}, 0
		 FROM {$wpdb->posts} p
		 WHERE p.ID IN ({$pid_list})"
	);

	// Recalcular contador del término outofstock
	$real_oos_count = (int) $wpdb->get_var(
		"SELECT count(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = {$tt_id}"
	);
	$wpdb->update(
		$wpdb->term_taxonomy,
		array( 'count' => $real_oos_count ),
		array( 'term_taxonomy_id' => $tt_id )
	);

	// H) Sincronizar tabla de búsqueda rápida wp_wc_product_meta_lookup
	$wpdb->query(
		"UPDATE {$wpdb->prefix}wc_product_meta_lookup l
		 LEFT JOIN {$wpdb->postmeta} pm_r ON l.product_id = pm_r.post_id AND pm_r.meta_key = '_regular_price'
		 SET l.stock_status = 'outofstock',
		     l.stock_quantity = 0,
		     l.onsale = 0,
		     l.min_price = CAST(pm_r.meta_value AS DECIMAL(10,2)),
		     l.max_price = CAST(pm_r.meta_value AS DECIMAL(10,2))
		 WHERE l.product_id IN ({$pid_list})"
	);

	$wpdb->query( 'COMMIT' );
	echo "✓ Transacción confirmada (COMMIT): {$count} productos normalizados a outofstock.\n";

} catch ( Throwable $e ) {
	$wpdb->query( 'ROLLBACK' );
	echo "✗ ERROR: " . $e->getMessage() . "\n";
	echo "Transacción revertida (ROLLBACK).\n";
	exit( 1 );
}
