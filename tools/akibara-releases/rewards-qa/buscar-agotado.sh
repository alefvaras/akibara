#!/bin/bash
cd ~/domains/akibara.cl/public_html || exit 1
cat > /tmp/qa-agot.php <<'PHPEOF'
<?php
echo 'captura=' . get_option( 'akb_bis_capture_enabled', '(ausente)' );
echo '  envio=' . get_option( 'akb_bis_dispatch_enabled', '(ausente)' ) . "\n";
global $wpdb;
foreach ( array( 'wp_akb_bis_subs', 'wp_akb_back_in_stock', 'wp_akb_bis' ) as $t ) {
	$e = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
	if ( $e ) { echo "tabla $t = " . $wpdb->get_var( "SELECT COUNT(*) FROM $t" ) . " filas\n"; }
}
echo "--- tablas akb ---\n";
foreach ( $wpdb->get_col( "SHOW TABLES LIKE 'wp_akb%'" ) as $t ) { echo "  $t\n"; }
echo "--- productos agotados de verdad (outofstock) ---\n";
$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_stock_status' AND meta_value='outofstock' LIMIT 40" );
$n = 0;
foreach ( $ids as $id ) {
	if ( 'publish' !== get_post_status( (int) $id ) ) { continue; }
	$p = wc_get_product( (int) $id );
	if ( ! $p ) { continue; }
	echo '  ' . $id . ' | ' . substr( $p->get_name(), 0, 34 ) . ' | ' . get_permalink( (int) $id ) . "\n";
	if ( ++$n >= 3 ) { break; }
}
if ( 0 === $n ) { echo "  (ninguno)\n"; }
PHPEOF
/usr/local/bin/wp eval-file /tmp/qa-agot.php 2>&1
rm -f /tmp/qa-agot.php
