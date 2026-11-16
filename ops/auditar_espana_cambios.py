#!/usr/bin/env python3
"""Auditoría de cambios necesarios para España en Local y Producción."""

import subprocess
import json
import sys

def get_spain_products_data(target="prod"):
    php_code = r"""<?php
    global $wpdb;

    // Obtener todos los productos publicados con término 'espana' en pa_pais
    $term_espana = get_term_by('slug', 'espana', 'pa_pais');
    if (!$term_espana) {
        echo json_encode([]);
        exit;
    }

    $product_ids = get_objects_in_term($term_espana->term_id, 'pa_pais');
    if (empty($product_ids)) {
        echo json_encode([]);
        exit;
    }

    $results = [];
    foreach ($product_ids as $id) {
        $p = get_post($id);
        if (!$p || $p->post_type !== 'product' || $p->post_status !== 'publish') {
            continue;
        }

        $sku = get_post_meta($id, '_sku', true);
        $reg = get_post_meta($id, '_regular_price', true);
        $sale = get_post_meta($id, '_sale_price', true);
        $price = get_post_meta($id, '_price', true);
        $stock_status = get_post_meta($id, '_stock_status', true);
        $stock = get_post_meta($id, '_stock', true);
        $manage_stock = get_post_meta($id, '_manage_stock', true);
        $attrs = get_post_meta($id, '_product_attributes', true);
        $series = wp_get_post_terms($id, 'pa_serie', ['fields' => 'names']);
        $brands = wp_get_post_terms($id, 'product_brand', ['fields' => 'names']);

        $results[] = [
            'id' => $id,
            'title' => $p->post_title,
            'sku' => $sku,
            'regular_price' => $reg,
            'sale_price' => $sale,
            'price' => $price,
            'stock_status' => $stock_status,
            'stock' => $stock,
            'manage_stock' => $manage_stock,
            'series' => $series,
            'brands' => $brands,
            'attrs' => $attrs,
        ];
    }

    echo json_encode($results);
    """

    if target == "prod":
        cmd = ["ssh", "akibara", "cd domains/akibara.cl/public_html && wp eval-file -"]
    else:
        cmd = ["docker", "exec", "-i", "akibara-cron-1", "wp", "eval-file", "-"]

    p = subprocess.run(cmd, input=php_code, text=True, capture_output=True)
    if p.returncode != 0:
        print(f"Error [{target}]: {p.stderr}", file=sys.stderr)
        return []
    try:
        return json.loads(p.stdout)
    except Exception as e:
        print(f"JSON Error [{target}]: {e}\nOutput: {p.stdout[:300]}", file=sys.stderr)
        return []

if __name__ == '__main__':
    target = sys.argv[1] if len(sys.argv) > 1 else "prod"
    data = get_spain_products_data(target)
    print(f"Total productos España en {target}: {len(data)}")
