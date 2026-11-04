#!/usr/bin/env bash
# run-db-hygiene.sh — Ejecutor de higiene forense de base de datos para Akibara.
set -euo pipefail

TARGET="${1:-local}"
ACTION="${2:-dry-run}"

if [[ "$TARGET" != "local" && "$TARGET" != "prod" ]]; then
    echo "Uso: $0 <local|prod> [dry-run|execute]"
    exit 1
fi

PHP_CODE=$(cat << 'PHP_EOF'
global $wpdb;

$is_dry_run = ('execute' !== ($argv[0] ?? ''));

$report = [];

// 1. akb_rankmath_repoint_backup
$backup_opt = $wpdb->get_row("SELECT option_id, option_name, LENGTH(option_value) as bytes FROM {$wpdb->options} WHERE option_name = 'akb_rankmath_repoint_backup'");
$report['backup_option'] = $backup_opt ? "{$backup_opt->bytes} bytes (id={$backup_opt->option_id})" : "limpio";
if ($backup_opt && !$is_dry_run) {
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name = 'akb_rankmath_repoint_backup'");
}

// 2. Orphan postmeta
$orphan_pm = $wpdb->get_col("SELECT pm.meta_id FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL");
$report['orphan_postmeta'] = count($orphan_pm);
if (!empty($orphan_pm) && !$is_dry_run) {
    $ids = implode(',', array_map('intval', $orphan_pm));
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ($ids)");
}

// 3. Orphan termmeta
$orphan_tm = $wpdb->get_col("SELECT tm.meta_id FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id WHERE t.term_id IS NULL");
$report['orphan_termmeta'] = count($orphan_tm);
if (!empty($orphan_tm) && !$is_dry_run) {
    $ids = implode(',', array_map('intval', $orphan_tm));
    $wpdb->query("DELETE FROM {$wpdb->termmeta} WHERE meta_id IN ($ids)");
}

// 4. Orphan order items & itemmeta
$has_hpos = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_orders'") === "{$wpdb->prefix}wc_orders";
$oi_sql = $has_hpos
    ? "SELECT oi.order_item_id FROM {$wpdb->prefix}woocommerce_order_items oi LEFT JOIN {$wpdb->posts} p ON oi.order_id = p.ID LEFT JOIN {$wpdb->prefix}wc_orders o ON oi.order_id = o.id WHERE p.ID IS NULL AND o.id IS NULL"
    : "SELECT oi.order_item_id FROM {$wpdb->prefix}woocommerce_order_items oi LEFT JOIN {$wpdb->posts} p ON oi.order_id = p.ID WHERE p.ID IS NULL";
$orphan_oi = $wpdb->get_col($oi_sql);
$report['orphan_order_items'] = count($orphan_oi);
if (!empty($orphan_oi)) {
    $ids = implode(',', array_map('intval', $orphan_oi));
    $oim_count = (int) $wpdb->get_var("SELECT count(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id IN ($ids)");
    $report['orphan_order_itemmeta'] = $oim_count;
    if (!$is_dry_run) {
        $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id IN ($ids)");
        $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id IN ($ids)");
    }
} else {
    $report['orphan_order_itemmeta'] = 0;
}

// 5. Failed actions of uninstalled plugins in Action Scheduler
$as_failed = $wpdb->get_col("
    SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions
    WHERE status = 'failed'
    AND (hook LIKE 'pmw_%' OR hook LIKE 'cartflows_%' OR hook = 'fetch_patterns' OR hook = 'action_scheduler/migration_hook')
");
$report['dead_as_actions'] = count($as_failed);
if (!empty($as_failed) && !$is_dry_run) {
    $ids = implode(',', array_map('intval', $as_failed));
    $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ($ids)");
    $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN ($ids)");
}

// 6. Expired transients
$expired_trans = (int) $wpdb->get_var("SELECT count(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()");
$report['expired_transients'] = $expired_trans;
if ($expired_trans > 0 && !$is_dry_run) {
    $wpdb->query("
        DELETE a, b FROM {$wpdb->options} a
        JOIN {$wpdb->options} b ON b.option_name = SUBSTRING(a.option_name, 12)
        WHERE a.option_name LIKE '_transient_timeout_%'
        AND a.option_value < UNIX_TIMESTAMP()
    ");
}

echo "RESULT:" . json_encode($report);
PHP_EOF
)

B64_CODE=$(echo "$PHP_CODE" | base64 | tr -d '\n')
RUN_SNIPPET="\$argv = array('$ACTION'); eval(base64_decode('$B64_CODE'));"

echo "=== HIGIENE DE BASE DE DATOS ($TARGET - $ACTION) ==="

if [[ "$TARGET" == "local" ]]; then
    RAW_OUT=$(docker compose exec -T cron wp eval "$RUN_SNIPPET")
else
    RAW_OUT=$(ssh akibara "wp --path=domains/akibara.cl/public_html eval '\$argv = array(\"$ACTION\"); eval(base64_decode(\"$B64_CODE\"));'")
fi

JSON_LINE=$(echo "$RAW_OUT" | grep "RESULT:" | sed 's/.*RESULT://' || echo "{}")

if [[ -n "$JSON_LINE" && "$JSON_LINE" != "{}" ]]; then
    echo "$JSON_LINE" | python3 -m json.tool
else
    echo "Salida cruda:"
    echo "$RAW_OUT"
fi
