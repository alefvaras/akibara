<?php
/**
 * Vuelca el estado comercial del catálogo por editorial, para el barrido semanal.
 * Uso: wp eval-file dump-disponibilidad.php  → JSON por stdout (no escribe nada).
 */
global $wpdb;

$marcas = array();
foreach ( get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => false ) ) as $t ) {
	$marcas[ (int) $t->term_id ] = $t->name;
}

$ids = $wpdb->get_col(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN ('publish','future')"
);

$out = array();
foreach ( $ids as $id ) {
	$p = wc_get_product( (int) $id );
	if ( ! $p ) {
		continue;
	}
	$marca_ids = wp_get_object_terms( $id, 'product_brand', array( 'fields' => 'ids' ) );
	$marca     = ( ! is_wp_error( $marca_ids ) && $marca_ids ) ? ( $marcas[ (int) $marca_ids[0] ] ?? '' ) : '';
	$out[]     = array(
		'id'         => (int) $id,
		'titulo'     => $p->get_name(),
		'slug'       => $p->get_slug(),
		'estado'     => $p->get_status(),
		'sku'        => (string) $p->get_sku(),
		'stock'      => $p->get_stock_quantity(),
		'stock_st'   => $p->get_stock_status(),
		'backorders' => $p->get_backorders(),
		'precio'     => (string) $p->get_regular_price(),
		'oferta'     => (string) $p->get_sale_price(),
		'marca'      => $marca,
		'eta'        => (string) get_post_meta( $id, '_akb_eta_type', true ),
		'disp'       => class_exists( '\Akibara\Business\Modules\Stock\Availability' )
			? \Akibara\Business\Modules\Stock\Availability::of( $p )
			: null,
	);
}
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
