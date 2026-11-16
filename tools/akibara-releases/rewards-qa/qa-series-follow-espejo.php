<?php
// QA · ¿quién sigue alguna serie en el espejo? Solo lee.
global $wpdb;
echo 'sf_enabled = ' . get_option( 'akb_sf_enabled', '(ausente)' ) . "\n";
$t = $wpdb->prefix . 'akb_series_follow_queue';
echo "cola $t = " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ) . " filas\n";
foreach ( $wpdb->get_results( "SELECT * FROM $t ORDER BY id DESC LIMIT 3" ) as $r ) {
	echo '  ' . wp_json_encode( $r ) . "\n";
}
echo "--- pedidos pagados por serie (top 5) ---\n";
$rows = $wpdb->get_results(
	"SELECT tt.term_id, t.name, COUNT(DISTINCT o.customer_id) clientes, COUNT(*) lineas
	 FROM {$wpdb->prefix}wc_order_product_lookup l
	 JOIN {$wpdb->prefix}wc_orders o ON o.id = l.order_id
	 JOIN {$wpdb->prefix}term_relationships tr ON tr.object_id = l.product_id
	 JOIN {$wpdb->prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'pa_serie'
	 JOIN {$wpdb->prefix}terms t ON t.term_id = tt.term_id
	 WHERE o.status IN ('wc-processing','wc-completed','wc-shipping-progress')
	 GROUP BY tt.term_id, t.name ORDER BY clientes DESC LIMIT 5"
);
foreach ( $rows as $r ) {
	echo "  term {$r->term_id} «{$r->name}» clientes={$r->clientes} lineas={$r->lineas}\n";
}
if ( ! $rows ) { echo "  (ninguno)\n"; }
