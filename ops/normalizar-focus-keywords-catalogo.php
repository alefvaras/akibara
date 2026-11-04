<?php
/**
 * Normalización masiva de Focus Keywords en Rank Math (Regla 13).
 *
 * Asegura que para cada producto publicado en Akibara:
 * 1. La palabra clave principal (primera de la lista) coincida exactamente con el post_title.
 * 2. Se conserven palabras clave secundarias relevantes (ej. autor, variantes sin caracteres especiales).
 * 3. Se eliminen duplicados redundantes.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

echo "============================================================\n";
echo "  NORMALIZACIÓN DE FOCUS KEYWORDS RANK MATH (REGLA 13)     \n";
echo "============================================================\n\n";

$products = get_posts(
	array(
		'post_type'      => 'product',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
	)
);

echo "Total productos publicados a evaluar: " . count( $products ) . "\n\n";

$updated_count = 0;
$already_ok    = 0;

foreach ( $products as $p ) {
	$pid   = (int) $p->ID;
	$title = trim( (string) $p->post_title );

	// En Rank Math, la coma es el delimitador estricto de palabras clave.
	// Si el título del producto contiene comas, se remueven del keyword principal
	// para que Rank Math no lo mutile en dos fragmentos aislados.
	$primary_kw = preg_replace( '/\s+/', ' ', trim( str_replace( ',', '', $title ) ) );

	$current_raw = (string) get_post_meta( $pid, 'rank_math_focus_keyword', true );
	$current_kws = array_filter( array_map( 'trim', explode( ',', $current_raw ) ) );

	// Construir lista con el keyword principal al frente, sanitizando comas en secundarias
	$new_kws = array( $primary_kw );
	foreach ( $current_kws as $kw ) {
		$clean_kw = preg_replace( '/\s+/', ' ', trim( str_replace( ',', '', $kw ) ) );
		if ( '' !== $clean_kw && strcasecmp( $clean_kw, $primary_kw ) !== 0 && ! in_array( $clean_kw, $new_kws, true ) ) {
			$new_kws[] = $clean_kw;
		}
	}

	// Limitar a máximo 5 focus keywords (estándar óptimo de Rank Math)
	$new_kws = array_slice( $new_kws, 0, 5 );
	$new_raw = implode( ', ', $new_kws );

	if ( $new_raw !== $current_raw ) {
		update_post_meta( $pid, 'rank_math_focus_keyword', $new_raw );
		clean_post_cache( $pid );
		++$updated_count;
		if ( $updated_count <= 10 || 0 === $updated_count % 100 ) {
			echo "• ID {$pid} [{$title}]:\n";
			echo "   Antes : \"{$current_raw}\"\n";
			echo "   Ahora : \"{$new_raw}\"\n\n";
		}
	} else {
		++$already_ok;
	}
}

echo "============================================================\n";
echo "Resumen de normalización:\n";
echo "  - Productos actualizados: {$updated_count}\n";
echo "  - Productos ya conformes: {$already_ok}\n";
echo "============================================================\n";

do_action( 'litespeed_purge_all' );
echo "[RESULTADO]: Normalización de Focus Keywords completada.\n";
