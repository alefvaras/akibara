<?php
/**
 * QA · ¿cuántas series configuradas tienen tramos que piden más tomos de los que
 * la serie tiene? Solo lee.
 */
global $wpdb;
$raw = get_option( 'akb_series_bundles_byp' );
$cfg = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
if ( ! is_array( $cfg ) ) { echo "no puedo leer la config\n"; return; }
echo 'series con configuración: ' . count( $cfg ) . "\n\n";

$problema = array(); $ok = 0; $apagadas = 0; $sin_tramos = 0;
foreach ( $cfg as $term_id => $c ) {
	$tid = (int) $term_id;
	if ( empty( $c['enabled'] ) ) { $apagadas++; continue; }
	$tiers = isset( $c['tiers'] ) && is_array( $c['tiers'] ) ? $c['tiers'] : array();
	if ( ! $tiers ) { $sin_tramos++; continue; }
	$mayor = 0;
	foreach ( $tiers as $t ) { $mayor = max( $mayor, (int) ( $t['min'] ?? 0 ) ); }

	// tomos publicados y comprables de esa serie
	$ids = get_objects_in_term( array( $tid ), array( 'pa_serie' ) );
	$tomos = 0;
	foreach ( (array) $ids as $pid ) {
		if ( 'publish' !== get_post_status( (int) $pid ) ) { continue; }
		$p = wc_get_product( (int) $pid );
		if ( $p && $p->is_purchasable() ) { $tomos++; }
	}
	$t = get_term( $tid );
	$nombre = ( $t && ! is_wp_error( $t ) ) ? $t->name : "term $tid";
	if ( $mayor > $tomos ) {
		$problema[] = sprintf( '%-34s tramo mayor pide %2d · tomos comprables %2d', substr( $nombre, 0, 34 ), $mayor, $tomos );
	} else { $ok++; }
}
echo 'encendidas y alcanzables: ' . $ok . "\n";
echo 'encendidas con TRAMOS INALCANZABLES: ' . count( $problema ) . "\n";
echo 'apagadas: ' . $apagadas . " · sin tramos: " . $sin_tramos . "\n\n";
foreach ( array_slice( $problema, 0, 20 ) as $p ) { echo "  $p\n"; }
if ( count( $problema ) > 20 ) { echo '  … y ' . ( count( $problema ) - 20 ) . " más\n"; }
