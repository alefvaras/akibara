#!/bin/bash
# QA · cambia el stock por la API de WooCommerce (dispara los ganchos), no por
# meta suelta. AKB_ID, AKB_ESTADO=instock|outofstock, AKB_CANT.
cd ~/domains/akibara.cl/public_html || exit 1
cat > /tmp/qa-stock.php <<'PHPEOF'
<?php
$id     = (int) getenv( 'AKB_ID' );
$estado = getenv( 'AKB_ESTADO' ) ?: 'instock';
$cant   = (int) getenv( 'AKB_CANT' );
$p = wc_get_product( $id );
if ( ! $p ) { echo "no existe el producto $id\n"; return; }
echo 'antes:  status=' . $p->get_stock_status() . ' cantidad=' . var_export( $p->get_stock_quantity(), true ) . "\n";
$p->set_manage_stock( true );
$p->set_stock_quantity( $cant );
$p->set_stock_status( $estado );
$p->save();
wc_delete_product_transients( $id );
$q = wc_get_product( $id );
echo 'después: status=' . $q->get_stock_status() . ' cantidad=' . var_export( $q->get_stock_quantity(), true ) . "\n";
global $wpdb;
$rows = $wpdb->get_results( "SELECT action_id, status, scheduled_date_gmt, args FROM {$wpdb->prefix}actionscheduler_actions WHERE hook='akb_bis_notify_product' ORDER BY action_id DESC LIMIT 3" );
foreach ( $rows as $r ) { echo "  accion {$r->action_id} {$r->status} {$r->scheduled_date_gmt} {$r->args}\n"; }
$s = $wpdb->get_results( $wpdb->prepare( "SELECT id, email, status, notified_at FROM {$wpdb->prefix}akb_bis_subscribers WHERE product_id=%d", $id ) );
foreach ( $s as $x ) { echo "  suscriptor {$x->id} {$x->email} {$x->status} avisado=" . var_export( $x->notified_at, true ) . "\n"; }
PHPEOF
AKB_ID="${1:-13384}" AKB_ESTADO="${2:-instock}" AKB_CANT="${3:-2}" /usr/local/bin/wp eval-file /tmp/qa-stock.php 2>&1 | tail -12
rm -f /tmp/qa-stock.php
