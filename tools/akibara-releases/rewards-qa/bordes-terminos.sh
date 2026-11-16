#!/bin/bash
# QA · ¿por qué dos tomos de la misma serie tienen precios distintos?
cd ~/domains/akibara.cl/public_html || exit 1
cat > /tmp/qa-term.php <<'PHPEOF'
<?php
$estado = json_decode( (string) get_option( 'akb_search_editorial_discount_state' ), true );
$fuente = is_array( $estado ) && isset( $estado['source_term_ids'] ) ? $estado['source_term_ids'] : array();
echo "términos fuente del descuento editorial: ".implode( ', ', array_map( 'strval', (array) $fuente ) )."\n";
foreach ( (array) $fuente as $tid ) {
  $t = get_term( (int) $tid );
  $pct = get_term_meta( (int) $tid, '_akb_taxonomy_discount_pct', true );
  echo "   term $tid = ".( $t && ! is_wp_error( $t ) ? $t->taxonomy.':'.$t->slug.' («'.$t->name.'»)' : '???' )."  pct=".var_export( $pct, true )."\n";
}
echo "\n";
$ids = array( 27836 => 'DN 03 (9.090)', 27838 => 'DN 04 (9.600)', 27840 => 'DN 05 (9.600)', 27854 => 'DN 12 (9.090)' );
foreach ( $ids as $id => $etiqueta ) {
  echo "--- $id  $etiqueta ---\n";
  $p = wc_get_product( $id );
  echo "   regular=".get_post_meta($id,'_regular_price',true)."  sale=".var_export(get_post_meta($id,'_sale_price',true),true);
  echo "  desde=".var_export(get_post_meta($id,'_sale_price_dates_from',true),true)." hasta=".var_export(get_post_meta($id,'_sale_price_dates_to',true),true);
  echo "  is_on_sale=".var_export($p->is_on_sale('edit'),true)."  servido=".$p->get_price()."\n";
  foreach ( get_object_taxonomies( 'product' ) as $tax ) {
    $ts = get_the_terms( $id, $tax );
    if ( ! $ts || is_wp_error( $ts ) ) { continue; }
    foreach ( $ts as $t ) {
      $pct = get_term_meta( $t->term_id, '_akb_taxonomy_discount_pct', true );
      $marca = ( '' !== $pct && null !== $pct ) ? "  <<< pct=$pct" : '';
      if ( in_array( (string) $t->term_id, array_map( 'strval', (array) $fuente ), true ) ) { $marca .= '  <<< TERMINO FUENTE'; }
      if ( $marca ) { echo "   $tax:{$t->slug} (id {$t->term_id})$marca\n"; }
    }
  }
}
PHPEOF
/usr/local/bin/wp eval-file /tmp/qa-term.php 2>&1
rm -f /tmp/qa-term.php
