<?php
/**
 * Sincroniza _global_unique_id (GTIN/EAN/ISBN) para que repita exactamente el SKU
 * en todos los productos de WooCommerce (limpiando sufijos internos como '-1' si existen).
 */

global $wpdb;

echo "=== SINCRONIZANDO GTIN (GTIN, UPC, EAN o ISBN) CON SKU ===\n";

$products = $wpdb->get_results("
    SELECT p.ID, p.post_title,
           pm_sku.meta_value as sku,
           pm_gtin.meta_value as gtin
    FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
    LEFT JOIN {$wpdb->postmeta} pm_gtin ON p.ID = pm_gtin.post_id AND pm_gtin.meta_key = '_global_unique_id'
    WHERE p.post_type = 'product' AND p.post_status = 'publish'
");

$updated = 0;
$already_ok = 0;
$skipped_no_sku = 0;

foreach ($products as $p) {
    $sku = trim((string)$p->sku);
    $current_gtin = trim((string)$p->gtin);

    if (empty($sku)) {
        $skipped_no_sku++;
        continue;
    }

    // El GTIN oficial es el SKU numérico (limpiando posibles sufijos de lote como -1, -2)
    $clean_gtin = preg_replace('/-[0-9]+$/', '', $sku);

    if ($current_gtin === $clean_gtin) {
        $already_ok++;
        continue;
    }

    update_post_meta($p->ID, '_global_unique_id', $clean_gtin);
    echo "  [OK] ID {$p->ID} ({$p->post_title}): SKU='$sku' -> GTIN asignado='$clean_gtin' (anterior: '$current_gtin')\n";
    $updated++;
}

echo "\n=== RESUMEN DE SINCRONIZACIÓN ===\n";
echo "  Total productos analizados: " . count($products) . "\n";
echo "  GTIN actualizados / asignados: $updated\n";
echo "  Ya sincronizados (idénticos): $already_ok\n";
echo "  Sin SKU (omitidos): $skipped_no_sku\n";

// Limpiar cache de WooCommerce y transients si hubo cambios
if ($updated > 0) {
    if (function_exists('wc_delete_product_transients')) {
        foreach ($products as $p) {
            wc_delete_product_transients($p->ID);
        }
    }
    wp_cache_flush();
    echo "Cache de WooCommerce purgada con éxito.\n";
}
