<?php
/**
 * Sincronización de atributos de producto en _product_attributes (Regla 9 y Regla 5).
 *
 * Corrige productos que tienen términos asignados en taxonomías canónicas
 * (pa_pais, pa_autor, pa_genero, pa_serie) pero no los tienen registrados en el array
 * postmeta '_product_attributes', impidiendo su renderizado en la ficha técnica (.akb-pdp-specs-grid).
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

echo "============================================================\n";
echo "  SINCRONIZACIÓN DE ATRIBUTOS EN _product_attributes (REGLA 9)\n";
echo "============================================================\n\n";

$canonical_taxonomies = array(
	'pa_pais'   => 0,
	'pa_autor'  => 1,
	'pa_genero' => 2,
	'pa_serie'  => 3,
);

$products = get_posts(
	array(
		'post_type'      => 'product',
		'posts_per_page' => -1,
		'post_status'    => array( 'publish', 'draft', 'pending' ),
	)
);

$fixed_count = 0;

foreach ( $products as $p ) {
	$pid     = (int) $p->ID;
	$attrs   = get_post_meta( $pid, '_product_attributes', true );
	if ( ! is_array( $attrs ) ) {
		$attrs = array();
	}

	$modified = false;
	$added    = array();

	// Limpiar product_brand si existiera (Regla 9)
	if ( isset( $attrs['product_brand'] ) ) {
		unset( $attrs['product_brand'] );
		$modified = true;
	}

	// Verificar taxonomías canónicas
	foreach ( $canonical_taxonomies as $tax => $pos ) {
		$terms = wp_get_post_terms( $pid, $tax );
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			if ( ! isset( $attrs[ $tax ] ) ) {
				$attrs[ $tax ] = array(
					'name'         => $tax,
					'value'        => '',
					'position'     => $pos,
					'is_visible'   => 1,
					'is_variation' => 0,
					'is_taxonomy'  => 1,
				);
				$modified      = true;
				$added[]       = $tax;
			} else {
				// Asegurar que position y flags sean canónicos
				if ( (int) $attrs[ $tax ]['is_visible'] !== 1 || (int) $attrs[ $tax ]['is_taxonomy'] !== 1 ) {
					$attrs[ $tax ]['is_visible']  = 1;
					$attrs[ $tax ]['is_taxonomy'] = 1;
					$modified                     = true;
				}
			}
		}
	}

	if ( $modified ) {
		// Reordenar según las posiciones canónicas
		$sorted_attrs = array();
		foreach ( $canonical_taxonomies as $tax => $pos ) {
			if ( isset( $attrs[ $tax ] ) ) {
				$attrs[ $tax ]['position'] = $pos;
				$sorted_attrs[ $tax ]      = $attrs[ $tax ];
				unset( $attrs[ $tax ] );
			}
		}
		// Preservar cualquier otro atributo adicional al final
		foreach ( $attrs as $k => $v ) {
			$sorted_attrs[ $k ] = $v;
		}

		update_post_meta( $pid, '_product_attributes', $sorted_attrs );
		clean_post_cache( $pid );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $pid );
		}
		do_action( 'akb_search_reindex_product', $pid );

		++$fixed_count;
		echo "• ID {$pid} [{$p->post_title}]: sincronizado " . implode( ', ', $added ) . "\n";
		echo "   Atributos finales: " . implode( ', ', array_keys( $sorted_attrs ) ) . "\n\n";
	}
}

echo "Total productos sincronizados: {$fixed_count}\n";
do_action( 'litespeed_purge_all' );
echo "[RESULTADO]: Sincronización de atributos completada exitosamente.\n";
