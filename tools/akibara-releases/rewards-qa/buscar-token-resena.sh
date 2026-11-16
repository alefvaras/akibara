#!/bin/bash
# QA · ¿queda vivo algún token de invitación a reseña? Solo lee.
cd ~/domains/akibara.cl/public_html || exit 1
cat > /tmp/qa-tok.php <<'PHPEOF'
<?php
global $wpdb;
$t = $wpdb->prefix . 'akb_review_invitations';
$existe = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
echo "tabla=".($existe ?: 'NO EXISTE')."\n";
if ( $existe ) {
  $cols = $wpdb->get_col( "DESC $t", 0 );
  echo "columnas: ".implode( ', ', $cols )."\n";
  foreach ( $wpdb->get_results( "SELECT * FROM $t ORDER BY id DESC LIMIT 5" ) as $r ) {
    $a = (array) $r;
    echo '  ' . implode( ' | ', array_map( static fn( $k, $v ) => "$k=" . ( 'token' === $k ? substr( (string) $v, 0, 8 ) . '…' : (string) $v ), array_keys( $a ), $a ) ) . "\n";
  }
}
echo "--- meta de invitación en pedidos ---\n";
foreach ( $wpdb->get_results( "SELECT order_id, meta_key, LEFT(meta_value,20) v FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE '%review%' OR meta_key LIKE '%resena%' ORDER BY order_id DESC LIMIT 8" ) as $r ) {
  echo "  pedido {$r->order_id} {$r->meta_key} = {$r->v}\n";
}
echo "--- versión de rewards ---\n";
$p = get_plugin_data( WP_PLUGIN_DIR . '/akibara-rewards/akibara-rewards.php', false, false );
echo '  ' . ( $p['Version'] ?? '?' ) . "\n";
PHPEOF
/usr/local/bin/wp eval-file /tmp/qa-tok.php 2>&1
rm -f /tmp/qa-tok.php
