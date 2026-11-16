<?php
/**
 * Limpieza total de lo sembrado por el gate de correos. Reporta antes/después.
 */

global $wpdb;

$usuarios = array( 274, 275, 276, 277, 278 );
$pedidos  = array( 27850, 27851, 27853, 27854, 27855 );
$resenas  = array( 2280 );

echo "=== ANTES ===\n";
$cuenta = static function () use ( $wpdb, $usuarios, $pedidos ): array {
	$ul = implode( ',', $usuarios );
	$ol = implode( ',', $pedidos );
	return array(
		'usuarios_qa'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID IN ({$ul})" ),
		'pedidos_qa'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id IN ({$ol})" ),
		'notas_pedido'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID IN ({$ol}) OR comment_type='order_note' AND comment_post_ID IN ({$ol})" ),
		'cola_series'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_series_follow_queue WHERE user_id IN ({$ul})" ),
		'bis_subs'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_bis_subscribers WHERE email LIKE 'qa-gate3-%'" ),
		'ledger'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_rewards_ledger WHERE user_id IN ({$ul})" ),
		'review_forms'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_rewards_review_forms WHERE order_id IN ({$ol})" ),
		'resena_2280'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_ID=2280" ),
		'as_invitacion'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook='akb_rewards_send_review_invite' AND status='pending'" ),
	);
};
$antes = $cuenta();
foreach ( $antes as $k => $v ) {
	echo "  {$k} = {$v}\n";
}

// 1. Acciones de Action Scheduler de los pedidos del gate.
foreach ( $pedidos as $oid ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'akb_rewards_send_review_invite', array( $oid ) );
		as_unschedule_all_actions( 'akb_order_preventa_dispatch_ready_email', array( 'order_id' => $oid ) );
	}
}
$ol = implode( ',', $pedidos );
$ids_as = $wpdb->get_col(
	"SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions
	 WHERE (hook LIKE 'akb_rewards_send_review_invite' OR hook LIKE 'akb_order_preventa%' OR hook='akb_bis_notify_product')
	   AND (args LIKE '%27850%' OR args LIKE '%27851%' OR args LIKE '%27853%' OR args LIKE '%27854%' OR args LIKE '%27855%' OR args LIKE '%15574%')"
);
if ( $ids_as ) {
	$in = implode( ',', array_map( 'intval', $ids_as ) );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ({$in})" );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN ({$in})" );
	echo 'acciones AS borradas: ' . count( $ids_as ) . "\n";
}

// 2. Reseña del gate (con sus metas).
foreach ( $resenas as $cid ) {
	wp_delete_comment( $cid, true );
}

// 3. Notas de pedido en wp_comments + los pedidos.
$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN (SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ({$ol}))" );
$borradas_notas = $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ({$ol})" );
echo "notas de pedido borradas: {$borradas_notas}\n";

foreach ( $pedidos as $oid ) {
	$o = wc_get_order( $oid );
	if ( $o instanceof WC_Order ) {
		$o->delete( true );
	}
}

// 4. Tablas propias.
$ul = implode( ',', $usuarios );
echo 'cola series borrada: ' . (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}akb_series_follow_queue WHERE user_id IN ({$ul})" ) . "\n";
echo 'bis subs borradas: ' . (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}akb_bis_subscribers WHERE email LIKE 'qa-gate3-%'" ) . "\n";
echo 'ledger borrado: ' . (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}akb_rewards_ledger WHERE user_id IN ({$ul})" ) . "\n";
echo 'review_forms borrados: ' . (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}akb_rewards_review_forms WHERE order_id IN ({$ol})" ) . "\n";

// 5. Usuarios del gate.
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $usuarios as $uid ) {
	if ( get_userdata( $uid ) ) {
		wp_delete_user( $uid );
		echo "usuario {$uid} borrado\n";
	}
}

echo "\n=== DESPUES ===\n";
$despues = $cuenta();
foreach ( $despues as $k => $v ) {
	echo "  {$k} = {$v}\n";
}
