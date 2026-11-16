#!/bin/bash
# QA · emite un token de reseña para un pedido existente (AKB_PEDIDO), para
# probar el formulario sin crear un pedido nuevo.
cd ~/domains/akibara.cl/public_html || exit 1
cat > /tmp/qa-emitir.php <<'PHPEOF'
<?php
$order_id = (int) getenv( 'AKB_PEDIDO' );
if ( $order_id <= 0 ) { echo "falta AKB_PEDIDO\n"; return; }
global $wpdb;
$t = \Akibara\Rewards\Support\Schema::review_forms_table();
echo "tabla = $t\n";
$vivos = $wpdb->get_results( "SELECT token, order_id, expires_at, used_at FROM {$t} ORDER BY id DESC LIMIT 5" );
foreach ( $vivos as $v ) {
	echo '  existente: token=' . substr( $v->token, 0, 10 ) . "… pedido={$v->order_id} expira={$v->expires_at} usado=" . var_export( $v->used_at, true ) . "\n";
}
$tok = \Akibara\Rewards\Reviews\Invitations::issue_token( $order_id );
echo "TOKEN=$tok\n";
echo 'URL=' . home_url( '/resena/' . $tok . '/' ) . "\n";
PHPEOF
AKB_PEDIDO="${1:-27958}" /usr/local/bin/wp eval-file /tmp/qa-emitir.php 2>&1 | tail -12
rm -f /tmp/qa-emitir.php
