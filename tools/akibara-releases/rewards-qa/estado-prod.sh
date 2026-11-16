#!/bin/bash
# QA · foto del estado editorial vivo en produccion. Solo lee.
cd ~/domains/akibara.cl/public_html || exit 1
WP=/usr/local/bin/wp
cat > /tmp/qa-estado.php <<'PHPEOF'
<?php
$raw = get_option('akb_search_editorial_discount_state');
$s = is_string($raw) ? json_decode($raw, true) : $raw;
if (!is_array($s)) { $s = null; }
$r = get_option('akb_search_editorial_discount_receipt');
$b = is_array($s) && isset($s['next_boundary_at']) ? (int) $s['next_boundary_at'] : 0;
echo "--- ESTADO (search) ---\n";
if (!is_array($s)) { echo "  NO HAY ESTADO\n"; } else {
  foreach ($s as $k => $v) { if (is_scalar($v)) { echo "  $k = ".(is_bool($v)?var_export($v,true):$v)."\n"; } }
}
if ($b) { echo "  next_boundary_at => ".gmdate('Y-m-d H:i:s',$b)." UTC  /  ".wp_date('Y-m-d H:i:s T',$b)." Chile\n"; }
echo "--- RECIBO ---\n";
if (!is_array($r)) { echo "  NO HAY RECIBO\n"; } else {
  foreach ($r as $k => $v) { if (is_scalar($v)) { echo "  $k = ".(is_bool($v)?var_export($v,true):$v)."\n"; } }
}
echo "--- VENTANA ---\n";
echo "  from=".get_option('akibara_taxonomy_discount_from')."  to=".get_option('akibara_taxonomy_discount_to');
echo "  enabled=".var_export(get_option('akibara_taxonomy_discount_enabled'),true);
echo "  apply_to=".get_option('akibara_taxonomy_discount_apply_to');
echo "  sale_policy=".get_option('akibara_taxonomy_discount_sale_policy')."\n";
echo "  tz=".get_option('timezone_string')."  gmt_offset=".get_option('gmt_offset')."  ahora=".wp_date('Y-m-d H:i:s T')."\n";
echo "  max_pct=".var_export(get_option('akb_discount_max_pct','(ausente)'),true)."\n";

global $wpdb;
$rows = $wpdb->get_results("SELECT action_id, scheduled_date_gmt, args FROM {$wpdb->prefix}actionscheduler_actions WHERE hook='akb_search_editorial_discount_boundary' AND status='pending' ORDER BY scheduled_date_gmt");
echo "--- BOUNDARY pendientes: ".count($rows)." ---\n";
$hits = array(); $agenda = array();
foreach ($rows as $row) {
  $agenda[$row->scheduled_date_gmt] = (isset($agenda[$row->scheduled_date_gmt]) ? $agenda[$row->scheduled_date_gmt] : 0) + 1;
  $args = json_decode($row->args, true);
  if (!is_array($args)) { continue; }
  $vals = array_values($args);
  $seq = isset($vals[0]) ? (int) $vals[0] : -1;
  $fp  = isset($vals[1]) ? (string) $vals[1] : 'x';
  $bat = isset($vals[2]) ? (int) $vals[2] : -1;
  if ($seq === (int)($s['wanted_seq'] ?? -2) && $fp === (string)($s['wanted_fingerprint'] ?? 'y') && $bat === $b) {
    $hits[] = $row->action_id.' @ '.$row->scheduled_date_gmt.' UTC';
  }
}
foreach ($agenda as $when => $n) { echo "   $when UTC  x$n\n"; }
echo "COINCIDEN CON EL ESTADO VIVO: ".count($hits)."\n";
foreach ($hits as $h) { echo "   -> ".$h."\n"; }
echo "--- COLA ACTION SCHEDULER ---\n";
$g = $wpdb->get_results("SELECT status, COUNT(*) c FROM {$wpdb->prefix}actionscheduler_actions GROUP BY status");
foreach ($g as $x) { echo "  ".$x->status." = ".$x->c."\n"; }
$fail = $wpdb->get_results("SELECT hook, COUNT(*) c, MAX(scheduled_date_gmt) ult FROM {$wpdb->prefix}actionscheduler_actions WHERE status='failed' GROUP BY hook ORDER BY c DESC LIMIT 10");
echo "--- FALLIDAS por hook ---\n";
foreach ($fail as $x) { echo "  ".$x->hook." x".$x->c."  ultima ".$x->ult."\n"; }
PHPEOF
$WP eval-file /tmp/qa-estado.php 2>&1
rm -f /tmp/qa-estado.php
