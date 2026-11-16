#!/bin/bash
# QA · ¿a cuántos productos de la campaña les sale un precio MAYOR que el de campaña
# por tener rebaja manual? Solo lee.
cd ~/domains/akibara.cl/public_html || exit 1
cat > /tmp/qa-caros.php <<'PHPEOF'
<?php
$estado = json_decode( (string) get_option( 'akb_search_editorial_discount_state' ), true );
$fuente = array_map( 'intval', (array) ( $estado['source_term_ids'] ?? array() ) );
if ( ! $fuente ) { echo "sin términos fuente\n"; return; }
$pct = (int) get_term_meta( $fuente[0], '_akb_taxonomy_discount_pct', true );
echo "término ".$fuente[0]."  pct=$pct  política=".get_option('akibara_taxonomy_discount_sale_policy')."\n\n";

$ids = get_objects_in_term( $fuente, array( 'product_brand' ) );
echo "productos en el término: ".count( $ids )."\n";
$caros = array(); $ok = 0; $fuera = 0; $suma = 0.0;
foreach ( $ids as $id ) {
  $p = wc_get_product( (int) $id );
  if ( ! $p || 'publish' !== get_post_status( $id ) ) { continue; }
  if ( ! $p->is_in_stock() ) { $fuera++; continue; }
  $reg  = (float) $p->get_regular_price( 'edit' );
  $serv = (float) $p->get_price();
  if ( $reg <= 0 ) { continue; }
  $campana = round( $reg * ( 100 - $pct ) / 100 );
  if ( $serv > $campana + 0.5 ) {
    $caros[] = sprintf( '%d | %s | regular=%s | campaña=%s | SERVIDO=%s | de más=%s',
      $id, substr( $p->get_name(), 0, 32 ), $reg, $campana, $serv, $serv - $campana );
    $suma += ( $serv - $campana );
  } else { $ok++; }
}
echo "con el precio de campaña o mejor: $ok\n";
echo "agotados (fuera por instock_only): $fuera\n";
echo "MÁS CAROS QUE LA CAMPAÑA: ".count( $caros )."   sobreprecio sumado: $".number_format( $suma, 0, ',', '.' )."\n";
foreach ( array_slice( $caros, 0, 25 ) as $c ) { echo "   $c\n"; }
if ( count( $caros ) > 25 ) { echo "   … y ".( count( $caros ) - 25 )." más\n"; }
PHPEOF
/usr/local/bin/wp eval-file /tmp/qa-caros.php 2>&1
rm -f /tmp/qa-caros.php
