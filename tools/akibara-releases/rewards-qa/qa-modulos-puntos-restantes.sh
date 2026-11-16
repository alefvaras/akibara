#!/bin/bash
# QA · estado de los carriles de puntos que aún no he probado. Solo lee.
cd ~/domains/akibara.cl/public_html || exit 1
cat > /tmp/qa-carriles.php <<'PHPEOF'
<?php
$carriles = array(
	'akb_rewards_earn_enabled'          => 'ganar puntos por compra',
	'akb_rewards_welcome_enabled'       => 'bono de bienvenida',
	'akb_rewards_reviews_enabled'       => 'puntos por reseña',
	'akb_rewards_referral_enabled'      => 'referidos',
	'akb_rewards_birthday_enabled'      => 'cumpleaños',
	'akb_rewards_expiry_enabled'        => 'caducidad de puntos',
	'akb_rewards_carousel_enabled'      => 'carrusel de reseñas del home',
	'akb_rewards_pdp_notice_enabled'    => 'aviso «gana N puntos» en la ficha',
	'akb_rewards_review_nudge_enabled'  => 'recordatorio de reseña',
	'akb_rewards_invite_enabled'        => 'invitación a reseñar',
);
foreach ( $carriles as $o => $q ) {
	$v = get_option( $o, '(default yes)' );
	printf( "%-34s %-12s %s\n", $o, is_scalar($v)?$v:'?', $q );
}
echo "\n--- parámetros ---\n";
foreach ( array( 'akb_rewards_referral_points','akb_rewards_referral_cookie_days','akb_rewards_referral_max_per_user','akb_rewards_birthday_points','akb_rewards_expiry_months','akb_rewards_carousel_min_reviews','akb_rewards_carousel_cards','akb_rewards_carousel_min_chars' ) as $o ) {
	printf( "  %-36s %s\n", $o, var_export( get_option( $o, '(ausente → default)' ), true ) );
}
echo "\n--- ¿hay datos que probar? ---\n";
global $wpdb;
echo '  reseñas aprobadas: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved='1'" ) . "\n";
echo '  usuarios con fecha de cumpleaños: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE '%birth%'" ) . "\n";
echo '  usuarios con código de referido: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='_akb_rewards_ref_code'" ) . "\n";
echo '  asientos de referidos en el ledger: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_rewards_ledger WHERE action LIKE '%referr%' OR action LIKE '%referido%'" ) . "\n";
echo '  asientos de cumpleaños: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_rewards_ledger WHERE action LIKE '%birthday%'" ) . "\n";
echo '  asientos de caducidad: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_rewards_ledger WHERE action LIKE '%expir%'" ) . "\n";
echo "\n--- acciones programadas de rewards ---\n";
foreach ( $wpdb->get_results( "SELECT hook, status, COUNT(*) n FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE '%rewards%' OR hook LIKE '%akb_rw%' GROUP BY hook, status" ) as $r ) {
	echo "  {$r->hook} {$r->status} x{$r->n}\n";
}
PHPEOF
/usr/local/bin/wp eval-file /tmp/qa-carriles.php 2>&1
rm -f /tmp/qa-carriles.php
