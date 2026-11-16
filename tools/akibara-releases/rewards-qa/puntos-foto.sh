#!/bin/bash
# QA · foto del ledger de puntos en produccion. Solo lee.
cd ~/domains/akibara.cl/public_html || exit 1
cat > /tmp/qa-puntos.php <<'PHPEOF'
<?php
global $wpdb;
$t = $wpdb->prefix . 'akb_rewards_ledger';
$existe = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
echo "tabla=".($existe ?: 'NO EXISTE')."\n";
if ( ! $existe ) { return; }
echo "FILAS TOTALES = ".$wpdb->get_var("SELECT COUNT(*) FROM $t")."\n";
$rows = $wpdb->get_results("SELECT * FROM $t ORDER BY id DESC LIMIT 12");
echo "--- ultimas 12 ---\n";
foreach ($rows as $r) {
  $a = (array) $r;
  echo "  id=".$a['id']." user=".$a['user_id']." delta=".$a['delta']." accion=".($a['action']??'?')." bal=".($a['balance_after']??'?')." ".($a['created_at']??'')." nota=".substr((string)($a['note']??''),0,60)."\n";
}
echo "--- saldos por usuario ---\n";
foreach ($wpdb->get_results("SELECT user_id, SUM(delta) s, COUNT(*) n FROM $t GROUP BY user_id ORDER BY n DESC LIMIT 8") as $u) {
  $usr = get_user_by('id', (int) $u->user_id);
  echo "  user=".$u->user_id." (".($usr? $usr->user_email : '?').")  saldo=".$u->s."  asientos=".$u->n."\n";
}
echo "--- config rewards ---\n";
foreach (array('akb_rewards_earn_points','akb_rewards_earn_amount','akb_rewards_review_points','akb_rewards_welcome_bonus','akb_rewards_redeem_min_points','akb_rewards_redeem_max_pct','akb_rewards_redeem_max_amount','akb_rewards_redeem_value','akb_rewards_review_max_per_user','akb_rewards_award_status') as $o) {
  echo "  $o = ".var_export(get_option($o, '(ausente)'), true)."\n";
}
PHPEOF
/usr/local/bin/wp eval-file /tmp/qa-puntos.php 2>&1
rm -f /tmp/qa-puntos.php
