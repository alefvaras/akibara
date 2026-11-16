#!/bin/bash
cd ~/domains/akibara.cl/public_html || exit 1
cat > /tmp/qa-ls.php <<'PHPEOF'
<?php
$claves = array(
  'litespeed.conf.cache','litespeed.conf.cache-priv','litespeed.conf.cache-commenter',
  'litespeed.conf.cache-rest','litespeed.conf.cache-page_login','litespeed.conf.cache-resi',
  'litespeed.conf.cache-ttl_pub','litespeed.conf.cache-ttl_priv','litespeed.conf.cache-ttl_frontpage',
  'litespeed.conf.cache-exc','litespeed.conf.cache-force_uri','litespeed.conf.cache-exc_roles',
  'litespeed.conf.cache-exc_qs','litespeed.conf.cache-exc_cat','litespeed.conf.cache-drop_qs',
  'litespeed.conf.esi','litespeed.conf.esi-cache_admbar','litespeed.conf.cache-vary_group',
);
foreach ( $claves as $k ) {
  $v = get_option( $k, '(ausente)' );
  if ( is_array( $v ) ) { $v = '[' . implode( ' | ', array_map( 'strval', $v ) ) . ']'; }
  echo str_pad( $k, 40 ) . ' = ' . var_export( $v, true ) . "\n";
}
echo "\n--- opciones litespeed que mencionan wp-json o store ---\n";
global $wpdb;
$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'litespeed%' AND (option_value LIKE '%wp-json%' OR option_value LIKE '%store%' OR option_value LIKE '%cart%')" );
foreach ( $rows as $r ) { echo $r->option_name . ' = ' . substr( $r->option_value, 0, 300 ) . "\n"; }
if ( ! $rows ) { echo "(ninguna)\n"; }
PHPEOF
/usr/local/bin/wp eval-file /tmp/qa-ls.php 2>&1
rm -f /tmp/qa-ls.php
