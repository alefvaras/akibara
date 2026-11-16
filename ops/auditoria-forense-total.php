<?php
/**
 * Auditoría forense exhaustiva de 360 grados en producción.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

echo "============================================================\n";
echo "   AUDITORÍA FORENSE 360° DE AKIBARA (PRODUCCIÓN EN VIVO)   \n";
echo "============================================================\n\n";

$issues = array();

// 1. Catálogo: Norma Editorial (Regla 1)
$norma_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND (post_content LIKE '%Norma Editorial%' OR post_excerpt LIKE '%Norma Editorial%')");
$norma_terms = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE description LIKE '%Norma Editorial%'");
if ($norma_count > 0 || $norma_terms > 0) {
    $issues['regla_1_norma'] = "Productos con Norma: {$norma_count}, Taxonomías con Norma: {$norma_terms}";
}

// 2. Catálogo: Enlaces externos a webs de editoriales (Regla 2)
$ext_links = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND (post_content LIKE '%panini.es%' OR post_content LIKE '%editorialivrea.com%' OR post_content LIKE '%planetadelibros.com%' OR post_content LIKE '%milkywayediciones.com%')");
if ($ext_links > 0) {
    $issues['regla_2_ext_links'] = "Productos con enlaces externos a editoriales: {$ext_links}";
}

// 3. Catálogo: Códigos de barras / GTIN / ISBN vacíos (Regla 3)
$no_gtin = (int) $wpdb->get_var("SELECT COUNT(p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_global_unique_id' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND (pm.meta_value IS NULL OR pm.meta_value = '')");
if ($no_gtin > 0) {
    $issues['regla_3_no_gtin'] = "Productos publicados sin _global_unique_id (GTIN/EAN): {$no_gtin}";
}

// 4. Catálogo: Portadas / Thumbnail ausentes (Regla 4)
$no_thumb = (int) $wpdb->get_var("SELECT COUNT(p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND (pm.meta_value IS NULL OR pm.meta_value = '0')");
if ($no_thumb > 0) {
    $issues['regla_4_no_thumb'] = "Productos publicados sin portada destacada: {$no_thumb}";
}

// 5. Catálogo: Descripción corta post_excerpt vacía (Regla 14)
$no_excerpt = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND (post_excerpt IS NULL OR TRIM(post_excerpt) = '')");
if ($no_excerpt > 0) {
    $issues['regla_14_no_excerpt'] = "Productos publicados sin post_excerpt: {$no_excerpt}";
}

// 6. Catálogo: Taxonomía pa_genero ausente (Regla 10)
$no_genero = (int) $wpdb->get_var("SELECT COUNT(p.ID) FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.ID NOT IN (SELECT object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = 'pa_genero')");
if ($no_genero > 0) {
    $issues['regla_10_no_genero'] = "Productos sin género asignado: {$no_genero}";
}

// 7. Catálogo: Taxonomía pa_serie ausente
$no_serie = (int) $wpdb->get_var("SELECT COUNT(p.ID) FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.ID NOT IN (SELECT object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = 'pa_serie')");
if ($no_serie > 0) {
    $issues['cat_no_serie'] = "Productos sin serie asignada: {$no_serie}";
}

// 8. Catálogo: Taxonomía product_brand (Marca/Editorial) sin descripción canónica (Regla 20)
$no_brand_desc = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_brand' AND (description IS NULL OR TRIM(description) = '')");
if ($no_brand_desc > 0) {
    $issues['regla_20_no_brand_desc'] = "Editoriales sin descripción canónica: {$no_brand_desc}";
}

// 9. SEO: Meta descripciones mayores a 160 caracteres (Regla 13)
$long_desc = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'rank_math_description' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND CHAR_LENGTH(pm.meta_value) > 160");
$short_desc = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'rank_math_description' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND CHAR_LENGTH(pm.meta_value) < 130 AND CHAR_LENGTH(pm.meta_value) > 0");
if ($long_desc > 0 || $short_desc > 0) {
    $issues['regla_13_meta_desc_length'] = "Meta descripciones > 160 chars: {$long_desc}, < 130 chars: {$short_desc}";
}

// 10. SEO: Productos sin meta descripción Rank Math
$no_desc = (int) $wpdb->get_var("SELECT COUNT(p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'rank_math_description' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND (pm.meta_value IS NULL OR TRIM(pm.meta_value) = '')");
if ($no_desc > 0) {
    $issues['regla_13_no_desc'] = "Productos sin rank_math_description: {$no_desc}";
}

// 11. Backend: Action Scheduler acciones fallidas (failed)
$failed_as = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'failed'");
if ($failed_as > 0) {
    $issues['action_scheduler_failed'] = "Acciones fallidas en Action Scheduler: {$failed_as}";
}

// 12. Backend: Sincronización akibara-search (precios o visibilidad desfasados)
$search_missing = (int) $wpdb->get_var("SELECT COUNT(p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->prefix}akb_search_docs d ON p.ID = d.post_id WHERE p.post_type = 'product' AND p.post_status = 'publish' AND d.post_id IS NULL");
if ($search_missing > 0) {
    $issues['search_missing'] = "Productos publicados ausentes en akb_search_docs: {$search_missing}";
}

// 13. Backend: Discrepancias de precio entre postmeta (_price) y búsqueda (min_price)
$price_mismatch = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_price' JOIN {$wpdb->prefix}akb_search_docs d ON p.ID = d.post_id WHERE p.post_type = 'product' AND p.post_status = 'publish' AND CAST(pm.meta_value AS DECIMAL(10,2)) != CAST(d.min_price AS DECIMAL(10,2))");
if ($price_mismatch > 0) {
    $issues['search_price_mismatch'] = "Discrepancias de precio entre catálogo y buscador: {$price_mismatch}";
}

// 14. Stock: Discrepancias entre wp_postmeta y wp_wc_product_meta_lookup
$lookup_stock_mismatch = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->prefix}wc_product_meta_lookup l ON pm.post_id = l.product_id WHERE pm.meta_key = '_stock_status' AND pm.meta_value != l.stock_status");
if ($lookup_stock_mismatch > 0) {
    $issues['lookup_stock_mismatch'] = "Discrepancias de stock_status entre postmeta y lookup table: {$lookup_stock_mismatch}";
}

// 15. Stock: Productos onbackorder sin el 5% de descuento (Regla 8)
$no_discount_backorders = (int) $wpdb->get_var("SELECT COUNT(p.ID) FROM {$wpdb->posts} p
    JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_stock_status' AND pm_status.meta_value = 'onbackorder'
    JOIN {$wpdb->postmeta} pm_reg ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price'
    LEFT JOIN {$wpdb->postmeta} pm_sale ON p.ID = pm_sale.post_id AND pm_sale.meta_key = '_sale_price'
    WHERE p.post_type = 'product' AND p.post_status = 'publish'
      AND (pm_sale.meta_value IS NULL OR pm_sale.meta_value = '' OR CAST(pm_sale.meta_value AS UNSIGNED) >= CAST(pm_reg.meta_value AS UNSIGNED))");
if ($no_discount_backorders > 0) {
    $issues['regla_8_backorder_no_discount'] = "Productos en reserva/onbackorder sin precio de oferta con descuento: {$no_discount_backorders}";
}

echo "RESULTADOS DEL BARRIDO:\n";
if (empty($issues)) {
    echo "  [PERFECTO] Cero inconsistencias detectadas en los 15 controles forenses.\n";
} else {
    foreach ($issues as $code => $detail) {
        echo "  • [ALERTA] {$code}: {$detail}\n";
    }
}
echo "\n============================================================\n";
