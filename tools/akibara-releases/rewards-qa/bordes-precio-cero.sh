#!/bin/bash
# QA · borde de dinero: precio 0 y precio vacío dentro de la campaña. Solo lee.
cd ~/domains/akibara.cl/public_html || exit 1
cat > /tmp/qa-cero.php <<'PHPEOF'
<?php
$estado = json_decode( (string) get_option( 'akb_search_editorial_discount_state' ), true );
$fuente = array_map( 'intval', (array) ( $estado['source_term_ids'] ?? array() ) );
$ids = get_objects_in_term( $fuente, array( 'product_brand' ) );
echo "productos en el término de campaña: ".count( $ids )."\n";
$cero = array(); $vacio = array(); $raros = array();
foreach ( $ids as $id ) {
  $id = (int) $id;
  if ( 'publish' !== get_post_status( $id ) ) { continue; }
  $p = wc_get_product( $id );
  if ( ! $p ) { continue; }
  $reg = get_post_meta( $id, '_regular_price', true );
  $pri = get_post_meta( $id, '_price', true );
  $nombre = substr( get_the_title( $id ), 0, 34 );
  if ( '' === (string) $reg ) { $vacio[] = "$id | $nombre | regular VACÍO | _price=".var_export($pri,true)."  servido=".$p->get_price()."  stock=".($p->is_in_stock()?'sí':'no'); }
  elseif ( 0.0 === (float) $reg ) { $cero[] = "$id | $nombre | regular=0 | _price=".var_export($pri,true)."  servido=".$p->get_price(); }
  elseif ( '' === (string) $pri || 0.0 === (float) $pri ) { $raros[] = "$id | $nombre | regular=$reg | _price=".var_export($pri,true)."  servido=".$p->get_price(); }
}
echo "\nREGULAR VACÍO: ".count($vacio)."\n";  foreach ( array_slice($vacio,0,8) as $x ) { echo "   $x\n"; }
echo "REGULAR CERO : ".count($cero)."\n";     foreach ( array_slice($cero,0,8)  as $x ) { echo "   $x\n"; }
echo "_price VACÍO O CERO CON REGULAR PUESTO: ".count($raros)."\n"; foreach ( array_slice($raros,0,8) as $x ) { echo "   $x\n"; }

// ¿algún producto del catálogo entero con precio 0 o vacío y comprable?
$todos = $GLOBALS['wpdb']->get_col( "SELECT ID FROM {$GLOBALS['wpdb']->posts} WHERE post_type='product' AND post_status='publish'" );
$sin = 0; $muestra = array();
foreach ( $todos as $id ) {
  $reg = get_post_meta( (int) $id, '_regular_price', true );
  if ( '' === (string) $reg || 0.0 === (float) $reg ) {
    $sin++;
    if ( count( $muestra ) < 5 ) {
      $p = wc_get_product( (int) $id );
      $muestra[] = "$id | ".substr(get_the_title((int)$id),0,34)." | comprable=".($p && $p->is_purchasable()?'SÍ':'no')." | stock=".($p && $p->is_in_stock()?'sí':'no')." | ".get_permalink((int)$id);
    }
  }
}
echo "\nEN TODO EL CATÁLOGO, productos publicados sin precio de lista: $sin\n";
foreach ( $muestra as $x ) { echo "   $x\n"; }
PHPEOF
/usr/local/bin/wp eval-file /tmp/qa-cero.php 2>&1
rm -f /tmp/qa-cero.php
