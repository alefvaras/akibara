#!/bin/bash
# QA · busca productos donde el descuento editorial produce decimales.
# Solo lee. El borde: ¿la ficha y el carrito redondean igual?
cd ~/domains/akibara.cl/public_html || exit 1
cat > /tmp/qa-bordes.php <<'PHPEOF'
<?php
global $wpdb;
$decimales = (int) get_option( 'woocommerce_price_num_decimals', 0 );
echo "woocommerce_price_num_decimals = $decimales\n";
echo "ventana: ".get_option('akibara_taxonomy_discount_from')." → ".get_option('akibara_taxonomy_discount_to');
echo "  apply_to=".get_option('akibara_taxonomy_discount_apply_to')."\n\n";

$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' LIMIT 900" );
$con_decimal = array();
$revisados = 0;
foreach ( $ids as $id ) {
  $p = wc_get_product( $id );
  if ( ! $p || ! $p->is_in_stock() || ! $p->is_purchasable() ) { continue; }
  $revisados++;
  $reg = (float) $p->get_regular_price();
  $pri = (float) $p->get_price();
  if ( $reg <= 0 || $pri <= 0 || abs( $reg - $pri ) < 0.001 ) { continue; }   // sin descuento
  $exacto = $reg * ( $pri / $reg );
  // ¿el precio servido es entero?  ¿y el 90% del regular lo es?
  $noventa = $reg * 0.9;
  $sucio = ( abs( $noventa - round( $noventa ) ) > 0.0001 ) || ( abs( $pri - round( $pri ) ) > 0.0001 );
  if ( $sucio ) {
    $con_decimal[] = sprintf( '%d | regular=%s | servido=%s | 90%%=%s | %s | %s',
      $id, $reg, $pri, $noventa, substr( $p->get_name(), 0, 34 ), get_permalink( $id ) );
  }
  if ( count( $con_decimal ) >= 8 ) { break; }
}
echo "productos comprables revisados: $revisados\n";
echo $con_decimal ? "CON DECIMALES:\n  ".implode("\n  ", $con_decimal)."\n"
                  : "NINGUNO con decimales entre los revisados\n";

// Muestra de control: tres con descuento y precio limpio, para comparar ficha vs carrito igualmente.
$muestra = array();
foreach ( $ids as $id ) {
  $p = wc_get_product( $id );
  if ( ! $p || ! $p->is_in_stock() || ! $p->is_purchasable() ) { continue; }
  $reg = (float) $p->get_regular_price(); $pri = (float) $p->get_price();
  if ( $reg > 0 && $pri > 0 && abs( $reg - $pri ) > 0.001 ) {
    $muestra[] = sprintf( '%d | regular=%s | servido=%s | %s', $id, $reg, $pri, get_permalink( $id ) );
  }
  if ( count( $muestra ) >= 5 ) { break; }
}
echo "\nMUESTRA con descuento (para comparar ficha vs carrito):\n  ".implode("\n  ", $muestra)."\n";
PHPEOF
/usr/local/bin/wp eval-file /tmp/qa-bordes.php 2>&1
rm -f /tmp/qa-bordes.php
