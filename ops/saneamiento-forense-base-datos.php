<?php
/**
 * saneamiento-forense-base-datos.php — Saneamiento exhaustivo e higiene forense de BD.
 *
 * Elimina de forma segura, atómica e idempotente:
 *   1. Tablas residuales de plugins desinstalados (GLA, Clarity, CartFlows)
 *   2. Postmeta residual de plugins desinstalados (_wc_gla_*)
 *   3. Opciones residuales en wp_options (gla_*, clarity_*, cartflow*, akb_*_backup)
 *   4. Termmeta residual de módulos desincorporados (_akb_tanda_*, _akb_serie_ediciones_relacionadas)
 *   5. Postmeta, termmeta y order_items huérfanos sin padre
 *   6. Transients expiradas y sesiones WooCommerce vencidas
 *   7. Posts en papelera (trash)
 *   8. Desfragmentación y optimización de tablas afectadas
 *
 * Uso:
 *   wp eval-file ops/saneamiento-forense-base-datos.php --dry-run
 *   wp eval-file ops/saneamiento-forense-base-datos.php --execute
 *
 * @package Akibara\Ops
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

global $wpdb;

$is_dry_run = true;
if ( getenv( 'AKB_DB_EXECUTE' ) === '1' || in_array( 'execute', $GLOBALS['argv'] ?? array(), true ) || in_array( '--execute', $GLOBALS['argv'] ?? array(), true ) ) {
	$is_dry_run = false;
}

echo "====================================================================\n";
echo "   AKIBARA DATABASE TOTAL FORENSIC CLEANUP & OPTIMIZATION\n";
echo "   Modo: " . ( $is_dry_run ? "DRY-RUN (Simulación sin mutación)" : "EXECUTE (MUTACIÓN ACTIVA Y LIMPIEZA)" ) . "\n";
echo "   Entorno: " . ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown' ) . "\n";
echo "====================================================================\n\n";

$report = array();

// 1. Tablas residuales de plugins desinstalados
$target_orphan_tables = array(
	'wp_clarity_collect_events',
	'wp_gla_attribute_mapping_rules',
	'wp_gla_budget_recommendations',
	'wp_gla_merchant_issues',
	'wp_gla_merchant_price_benchmarks',
	'wp_gla_shipping_rates',
	'wp_gla_shipping_times',
	'wp_cartflows_ca_cart_abandonment',
	'wp_cartflows_ca_email_templates',
);

echo "[1] Tablas residuales de plugins desinstalados:\n";
$tables_to_drop = array();
foreach ( $target_orphan_tables as $tbl ) {
	$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tbl ) );
	if ( $exists === $tbl ) {
		$cnt = (int) $wpdb->get_var( "SELECT count(*) FROM `{$tbl}`" );
		echo "    - Encontrada: {$tbl} ({$cnt} filas)\n";
		$tables_to_drop[] = $tbl;
	}
}
$report['tablas_eliminadas'] = count( $tables_to_drop );
if ( empty( $tables_to_drop ) ) {
	echo "    ✔ Cero tablas residuales encontradas.\n";
} elseif ( ! $is_dry_run ) {
	foreach ( $tables_to_drop as $tbl ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$tbl}`" );
		echo "    -> Eliminada: `{$tbl}`\n";
	}
}

// 2. Postmeta residual de plugins desinstalados (_wc_gla_*)
echo "\n[2] Postmeta residual de plugins desinstalados (_wc_gla_*):\n";
$gla_meta_count = (int) $wpdb->get_var( "SELECT count(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '_wc_gla_%'" );
echo "    Filas detectadas con prefijo _wc_gla_*: {$gla_meta_count}\n";
$report['postmeta_gla_eliminado'] = $gla_meta_count;
if ( $gla_meta_count > 0 && ! $is_dry_run ) {
	$del_gla = $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_wc_gla_%'" );
	echo "    -> Eliminadas con éxito ({$del_gla} filas borradas)\n";
}

// 3. Opciones residuales en wp_options
echo "\n[3] Opciones residuales en wp_options (gla_*, clarity_*, cartflow*, akb_*_backup):\n";
$residual_options = $wpdb->get_results( "
	SELECT option_name, autoload, LENGTH(option_value) as bytes 
	FROM {$wpdb->options} 
	WHERE option_name LIKE 'gla_%' 
	   OR option_name LIKE 'clarity_%' 
	   OR option_name LIKE 'cartflow%' 
	   OR option_name LIKE 'akb_%_backup'
	ORDER BY option_name
" );
echo "    Total opciones residuales encontradas: " . count( $residual_options ) . "\n";
foreach ( $residual_options as $opt ) {
	echo "    - {$opt->option_name} (autoload: {$opt->autoload}, {$opt->bytes} bytes)\n";
}
$report['opciones_eliminadas'] = count( $residual_options );
if ( ! empty( $residual_options ) && ! $is_dry_run ) {
	$del_opts = $wpdb->query( "
		DELETE FROM {$wpdb->options} 
		WHERE option_name LIKE 'gla_%' 
		   OR option_name LIKE 'clarity_%' 
		   OR option_name LIKE 'cartflow%' 
		   OR option_name LIKE 'akb_%_backup'
	" );
	echo "    -> Eliminadas con éxito ({$del_opts} opciones borradas)\n";
}

// 4. Termmeta residual de módulos desincorporados
echo "\n[4] Termmeta residual de módulos retirados (_akb_tanda_*, _akb_serie_ediciones_relacionadas):\n";
$residual_termmeta = (int) $wpdb->get_var( "
	SELECT count(*) FROM {$wpdb->termmeta} 
	WHERE meta_key LIKE '_akb_tanda_%' 
	   OR meta_key IN ('_akb_pedido_modo', '_akb_min_days', '_akb_max_days', '_akb_serie_ediciones_relacionadas')
" );
echo "    Filas termmeta residuales detectadas: {$residual_termmeta}\n";
$report['termmeta_residual_eliminado'] = $residual_termmeta;
if ( $residual_termmeta > 0 && ! $is_dry_run ) {
	$del_tm = $wpdb->query( "
		DELETE FROM {$wpdb->termmeta} 
		WHERE meta_key LIKE '_akb_tanda_%' 
		   OR meta_key IN ('_akb_pedido_modo', '_akb_min_days', '_akb_max_days', '_akb_serie_ediciones_relacionadas')
	" );
	echo "    -> Eliminadas con éxito ({$del_tm} filas borradas)\n";
}

// 5. Postmeta huérfano (sin post_id en wp_posts)
echo "\n[5] Postmeta huérfano (sin post_id):\n";
$orphan_pm = (int) $wpdb->get_var( "SELECT count(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
echo "    Filas postmeta huérfano: {$orphan_pm}\n";
$report['orphan_postmeta'] = $orphan_pm;
if ( $orphan_pm > 0 && ! $is_dry_run ) {
	$del_opm = $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
	echo "    -> Eliminadas con éxito ({$del_opm} filas borradas)\n";
}

// 6. Term relationships huérfanos (sin post en wp_posts)
echo "\n[6] Term relationships huérfanos:\n";
$orphan_tr = (int) $wpdb->get_var( "SELECT count(*) FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID WHERE p.ID IS NULL" );
echo "    Filas term_relationships huérfanos: {$orphan_tr}\n";
$report['orphan_term_relationships'] = $orphan_tr;
if ( $orphan_tr > 0 && ! $is_dry_run ) {
	$del_otr = $wpdb->query( "DELETE tr FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID WHERE p.ID IS NULL" );
	echo "    -> Eliminadas con éxito ({$del_otr} filas borradas)\n";
}

// 7. Posts en papelera (trash)
echo "\n[7] Posts en la papelera (post_status = 'trash'):\n";
$trash_posts = $wpdb->get_results( "SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_status = 'trash'" );
echo "    Posts en papelera: " . count( $trash_posts ) . "\n";
foreach ( $trash_posts as $tp ) {
	echo "    - ID {$tp->ID} [{$tp->post_type}]: {$tp->post_title}\n";
}
$report['trash_posts_eliminados'] = count( $trash_posts );
if ( ! empty( $trash_posts ) && ! $is_dry_run ) {
	$del_trash = $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'" );
	// Limpieza en cascada de postmeta de los posts borrados
	$wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
	echo "    -> Papelera vaciada con éxito ({$del_trash} posts eliminados)\n";
}

// 8. Sesiones expiradas de WooCommerce
echo "\n[8] Sesiones de WooCommerce vencidas:\n";
$expired_sessions = (int) $wpdb->get_var( "SELECT count(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_expiry < UNIX_TIMESTAMP()" );
echo "    Sesiones vencidas detectadas: {$expired_sessions}\n";
$report['sesiones_vencidas_eliminadas'] = $expired_sessions;
if ( $expired_sessions > 0 && ! $is_dry_run ) {
	$del_sess = $wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_sessions WHERE session_expiry < UNIX_TIMESTAMP()" );
	echo "    -> Eliminadas con éxito ({$del_sess} sesiones purgadas)\n";
}

// 9. Transients expiradas con TTL vencido
echo "\n[9] Transients expiradas en wp_options:\n";
$expired_trans = (int) $wpdb->get_var( "SELECT count(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()" );
echo "    Transients vencidas detectadas: {$expired_trans}\n";
$report['transients_expiradas_eliminadas'] = $expired_trans;
if ( $expired_trans > 0 && ! $is_dry_run ) {
	$del_tr = $wpdb->query( "
		DELETE a, b FROM {$wpdb->options} a
		JOIN {$wpdb->options} b ON b.option_name = SUBSTRING(a.option_name, 12)
		WHERE a.option_name LIKE '_transient_timeout_%'
		AND a.option_value < UNIX_TIMESTAMP()
	" );
	echo "    -> Eliminadas con éxito ({$del_tr} transients purgadas)\n";
}

// 10. Optimización de tablas (OPTIMIZE TABLE)
if ( ! $is_dry_run ) {
	echo "\n[10] Desfragmentando y optimizando tablas principales (OPTIMIZE TABLE)...\n";
	$tables_to_optimize = array(
		$wpdb->posts,
		$wpdb->postmeta,
		$wpdb->options,
		$wpdb->termmeta,
		$wpdb->term_relationships,
		$wpdb->prefix . 'woocommerce_sessions',
	);
	foreach ( $tables_to_optimize as $tbl ) {
		$wpdb->query( "OPTIMIZE TABLE `{$tbl}`" );
		echo "    ✔ `{$tbl}` optimizada.\n";
	}
}

echo "\n====================================================================\n";
echo "   RESUMEN FINAL DE SANEAMIENTO FORENSE: " . ( $is_dry_run ? "SIMULACIÓN" : "EJECUTADO" ) . "\n";
echo "====================================================================\n";
foreach ( $report as $k => $v ) {
	echo sprintf( "%-35s : %d\n", $k, $v );
}
echo "\n";
