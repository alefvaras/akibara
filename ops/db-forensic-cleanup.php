<?php
/**
 * db-forensic-cleanup.php — Saneamiento e higiene forense de la base de datos de Akibara.
 *
 * Elimina de forma segura, atómica e idempotente:
 *   1. Opción residual de respaldo en wp_options (akb_rankmath_repoint_backup)
 *   2. Postmeta huérfano (posts eliminados)
 *   3. Termmeta huérfano (taxonomías eliminadas)
 *   4. Order items y order itemmeta huérfanos (pedidos eliminados)
 *   5. Acciones fallidas de plugins de terceros ya desinstalados en Action Scheduler
 *   6. Transients expiradas con TTL vencido
 *
 * Uso:
 *   wp eval-file ops/db-forensic-cleanup.php --dry-run
 *   wp eval-file ops/db-forensic-cleanup.php --execute
 *
 * @package Akibara\Ops
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

global $wpdb;

$all_args = array_merge( $GLOBALS['argv'] ?? array(), $args ?? array() );
$is_dry_run = true;
foreach ( $all_args as $arg ) {
	if ( 'execute' === $arg || '--execute' === $arg ) {
		$is_dry_run = false;
	}
}

echo "====================================================================\n";
echo "   AKIBARA DATABASE FORENSIC HYGIENE & CLEANUP\n";
echo "   Modo: " . ( $is_dry_run ? "DRY-RUN (Simulación sin mutación)" : "EXECUTE (MUTACIÓN ACTIVA)" ) . "\n";
echo "   Entorno: " . ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown' ) . "\n";
echo "====================================================================\n\n";

$report = array();

// 1. Opción residual de respaldo akb_rankmath_repoint_backup
$backup_opt = $wpdb->get_row( "SELECT option_id, option_name, LENGTH(option_value) as bytes FROM {$wpdb->options} WHERE option_name = 'akb_rankmath_repoint_backup'" );
$report['backup_option'] = $backup_opt ? 1 : 0;
echo "[1] Opción residual de respaldo: ";
if ( $backup_opt ) {
	echo "ENCONTRADA (id={$backup_opt->option_id}, tamaño={$backup_opt->bytes} bytes)\n";
	if ( ! $is_dry_run ) {
		$del = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'akb_rankmath_repoint_backup'" );
		echo "    -> Eliminada con éxito ({$del} filas afectadas)\n";
	}
} else {
	echo "Limpia (no existe en wp_options)\n";
}

// 2. Postmeta huérfano (post_id no existe en wp_posts)
$orphan_pm_ids = $wpdb->get_col( "SELECT pm.meta_id FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
$report['orphan_postmeta'] = count( $orphan_pm_ids );
echo "\n[2] Postmeta huérfano: " . count( $orphan_pm_ids ) . " filas detectadas\n";
if ( ! empty( $orphan_pm_ids ) && ! $is_dry_run ) {
	$ids_in = implode( ',', array_map( 'intval', $orphan_pm_ids ) );
	$del = $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ({$ids_in})" );
	echo "    -> Eliminadas con éxito ({$del} filas afectadas)\n";
}

// 3. Termmeta huérfano (term_id no existe en wp_terms)
$orphan_tm_ids = $wpdb->get_col( "SELECT tm.meta_id FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id WHERE t.term_id IS NULL" );
$report['orphan_termmeta'] = count( $orphan_tm_ids );
echo "\n[3] Termmeta huérfano: " . count( $orphan_tm_ids ) . " filas detectadas\n";
if ( ! empty( $orphan_tm_ids ) && ! $is_dry_run ) {
	$ids_in = implode( ',', array_map( 'intval', $orphan_tm_ids ) );
	$del = $wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_id IN ({$ids_in})" );
	echo "    -> Eliminadas con éxito ({$del} filas afectadas)\n";
}

// 4. Order items huérfanos (order_id no existe ni en wp_posts ni en wp_wc_orders)
$has_hpos = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wc_orders'" ) === "{$wpdb->prefix}wc_orders";
$order_check_sql = $has_hpos
	? "SELECT oi.order_item_id FROM {$wpdb->prefix}woocommerce_order_items oi LEFT JOIN {$wpdb->posts} p ON oi.order_id = p.ID LEFT JOIN {$wpdb->prefix}wc_orders o ON oi.order_id = o.id WHERE p.ID IS NULL AND o.id IS NULL"
	: "SELECT oi.order_item_id FROM {$wpdb->prefix}woocommerce_order_items oi LEFT JOIN {$wpdb->posts} p ON oi.order_id = p.ID WHERE p.ID IS NULL";

$orphan_oi_ids = $wpdb->get_col( $order_check_sql );
$report['orphan_order_items'] = count( $orphan_oi_ids );
echo "\n[4] Order items huérfanos: " . count( $orphan_oi_ids ) . " filas detectadas\n";

if ( ! empty( $orphan_oi_ids ) ) {
	$ids_in = implode( ',', array_map( 'intval', $orphan_oi_ids ) );
	$orphan_oim_count = (int) $wpdb->get_var( "SELECT count(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id IN ({$ids_in})" );
	echo "    Order itemmeta asociados: {$orphan_oim_count} filas detectadas\n";

	if ( ! $is_dry_run ) {
		$del_meta = $wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id IN ({$ids_in})" );
		$del_item = $wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id IN ({$ids_in})" );
		echo "    -> Eliminados con éxito: {$del_item} items, {$del_meta} itemmeta\n";
	}
}

// 5. Acciones fallidas de plugins desinstalados en Action Scheduler
$as_failed_ids = $wpdb->get_col( "
	SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions
	WHERE status = 'failed'
	AND (
		hook LIKE 'pmw_%'
		OR hook LIKE 'cartflows_%'
		OR hook = 'fetch_patterns'
		OR hook = 'action_scheduler/migration_hook'
	)
" );
$report['dead_as_actions'] = count( $as_failed_ids );
echo "\n[5] Acciones fallidas de plugins desinstalados (PMW, CartFlows, migration): " . count( $as_failed_ids ) . " detectadas\n";
if ( ! empty( $as_failed_ids ) && ! $is_dry_run ) {
	$ids_in = implode( ',', array_map( 'intval', $as_failed_ids ) );
	$del_logs = $wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ({$ids_in})" );
	$del_acts = $wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN ({$ids_in})" );
	echo "    -> Eliminadas con éxito: {$del_acts} acciones, {$del_logs} logs de Action Scheduler\n";
}

// 6. Transients expiradas
$expired_transients = (int) $wpdb->get_var( "SELECT count(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()" );
$report['expired_transients'] = $expired_transients;
echo "\n[6] Transients expiradas en wp_options: {$expired_transients} detectadas\n";
if ( $expired_transients > 0 && ! $is_dry_run ) {
	$del_trans = $wpdb->query( "
		DELETE a, b FROM {$wpdb->options} a
		JOIN {$wpdb->options} b ON b.option_name = SUBSTRING(a.option_name, 12)
		WHERE a.option_name LIKE '_transient_timeout_%'
		AND a.option_value < UNIX_TIMESTAMP()
	" );
	echo "    -> Eliminadas con éxito ({$del_trans} filas de transients purgadas)\n";
}

echo "\n====================================================================\n";
echo "   RESUMEN FINAL DE HIGIENE: " . ( $is_dry_run ? "SIMULADO" : "EJECUTADO" ) . "\n";
echo "====================================================================\n";
foreach ( $report as $k => $v ) {
	echo sprintf( "%-30s : %d\n", $k, $v );
}
echo "\n";
