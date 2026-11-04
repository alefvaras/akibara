<?php
/**
 * Normalización de autores japoneses sin macrones (Regla 12 de Akibara).
 *
 * Convierte caracteres diacríticos (ō, ū) a estándar latino (o, u) en pa_autor,
 * actualiza el término canónico y reindexa los productos asociados en akibara-search.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

echo "============================================================\n";
echo "  NORMALIZACIÓN DE AUTORES SIN MACRONES (REGLA 12 AKIBARA)\n";
echo "============================================================\n\n";

$macron_map = array(
	'Gorō Taniguchi' => 'Goro Taniguchi',
	'Ichirō Ōkouchi' => 'Ichiro Okouchi',
	'Kentarō Yabuki' => 'Kentaro Yabuki',
	'Ryōko Kui'      => 'Ryoko Kui',
	'Tatsuya Endō'   => 'Tatsuya Endo',
	'Yūsei Matsui'   => 'Yusei Matsui',
);

$total_updated_terms    = 0;
$total_products_reindex = array();

foreach ( $macron_map as $old_name => $new_name ) {
	// Buscar por nombre exacto en wp_terms
	$term_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.count
			 FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
			 WHERE tt.taxonomy = 'pa_autor' AND BINARY t.name = %s",
			$old_name
		)
	);

	if ( ! $term_row ) {
		echo "• \"{$old_name}\": No encontrado con macrón (ya normalizado o ausente).\n";
		continue;
	}

	$term_id = (int) $term_row->term_id;
	echo "• Normalizando ID {$term_id}: \"{$old_name}\" ➔ \"{$new_name}\" (Tomos: {$term_row->count})\n";

	$res = wp_update_term( $term_id, 'pa_autor', array( 'name' => $new_name ) );
	if ( is_wp_error( $res ) ) {
		echo "  [ERROR] Falló actualización: " . $res->get_error_message() . "\n";
		continue;
	}

	clean_term_cache( $term_id, 'pa_autor' );
	++$total_updated_terms;

	// Obtener IDs de productos asociados para reindexar en akibara-search
	$pids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
			$term_row->term_taxonomy_id
		)
	);

	if ( ! empty( $pids ) ) {
		foreach ( $pids as $pid ) {
			$total_products_reindex[] = (int) $pid;
		}
	}
}

$unique_pids = array_unique( $total_products_reindex );
echo "\nTotal términos normalizados: {$total_updated_terms}\n";
echo "Total productos para reindexar en búsqueda: " . count( $unique_pids ) . "\n";

if ( ! empty( $unique_pids ) ) {
	echo "\nReindexando productos en akibara-search...\n";
	$indexed = 0;
	foreach ( $unique_pids as $pid ) {
		do_action( 'akb_search_reindex_product', $pid );
		++$indexed;
	}
	echo "Reindexación encolada/ejecutada para {$indexed} productos.\n";
}

do_action( 'litespeed_purge_all' );
echo "\n[RESULTADO]: Normalización de autores finalizada con éxito.\n";
