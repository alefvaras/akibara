#!/bin/bash
cd ~/domains/akibara.cl/public_html || exit 1
cat > /tmp/qa-prod.php <<'PHPEOF'
<?php
$q = new WP_Query( array(
  'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 60,
  'fields' => 'ids', 'no_found_rows' => true,
) );
$out = array();
foreach ( $q->posts as $id ) {
  $p = wc_get_product( $id );
  if ( ! $p || ! $p->is_in_stock() || ! $p->is_purchasable() ) { continue; }
  $price = (float) $p->get_price();
  if ( $price >= 3000 && $price <= 9500 ) {
    $out[] = sprintf( '%d | %s | precio=%s | stock=%s | %s', $id, substr($p->get_name(),0,45), $price, $p->get_stock_quantity(), get_permalink($id) );
  }
  if ( count($out) >= 6 ) { break; }
}
echo $out ? implode("\n", $out)."\n" : "SIN CANDIDATOS en los primeros 60\n";
PHPEOF
/usr/local/bin/wp eval-file /tmp/qa-prod.php 2>&1
rm -f /tmp/qa-prod.php
