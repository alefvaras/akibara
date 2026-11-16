#!/bin/bash
# QA · borde de dinero: rebaja manual + descuento editorial con greatest_wins.
# Solo lee.
cd ~/domains/akibara.cl/public_html || exit 1
cat > /tmp/qa-rebaja.php <<'PHPEOF'
<?php
$ids = array( 27832, 27834, 27836, 27838, 27840, 27842, 27844, 27846, 27848, 27850, 27852, 27854 );
$term_ids = get_option( 'akibara_taxonomy_discount_terms', null );
echo "sale_policy = ".get_option('akibara_taxonomy_discount_sale_policy')."\n";
echo "apply_to    = ".get_option('akibara_taxonomy_discount_apply_to')."\n\n";
printf( "%-6s %-22s %9s %9s %9s %9s  %-7s %s\n", 'ID', 'nombre', 'regular', '_sale', 'servido', 'esperado', 'stock', 'terminos con %' );
foreach ( $ids as $id ) {
  $p = wc_get_product( $id );
  if ( ! $p ) { continue; }
  $reg  = (float) get_post_meta( $id, '_regular_price', true );
  $sale = get_post_meta( $id, '_sale_price', true );
  $serv = (float) $p->get_price();
  // términos con porcentaje editorial
  $marcas = array();
  foreach ( array( 'product_cat', 'pa_serie', 'pa_editorial', 'product_tag' ) as $tax ) {
    if ( ! taxonomy_exists( $tax ) ) { continue; }
    foreach ( wp_get_object_terms( $id, $tax, array( 'fields' => 'all' ) ) as $t ) {
      $pct = get_term_meta( $t->term_id, 'akibara_discount_pct', true );
      if ( '' === $pct || null === $pct ) { $pct = get_term_meta( $t->term_id, '_akibara_discount_pct', true ); }
      if ( '' !== $pct && null !== $pct && (float) $pct > 0 ) {
        $marcas[] = $t->taxonomy.':'.$t->slug.'='.$pct.'%';
      }
    }
  }
  $mejor = $reg;
  if ( $marcas ) { $mejor = min( $mejor, round( $reg * ( 1 - (float) preg_replace('/\D/','',end($marcas)) / 100 ) ) ); }
  if ( '' !== $sale ) { $mejor = min( $mejor, (float) $sale ); }
  printf( "%-6d %-22s %9s %9s %9s %9s  %-7s %s\n",
    $id, substr( $p->get_name(), 0, 22 ), $reg, ( '' === $sale ? '-' : $sale ), $serv, $mejor,
    $p->is_in_stock() ? 'instock' : 'agotado', $marcas ? implode( ' ', $marcas ) : '(ninguno)' );
}
PHPEOF
/usr/local/bin/wp eval-file /tmp/qa-rebaja.php 2>&1
rm -f /tmp/qa-rebaja.php
