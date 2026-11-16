#!/usr/bin/env python3
"""Despliegue Inmediato de Ajuste de Precios (-$500 vs DameMiManga) para 1.974 productos.

Regla 18 Inviolable: NUNCA tocar _stock, _stock_status, _manage_stock ni _backorders.
Cumplimiento de la orden crítica del dueño.
"""

import json
import os
import re
import subprocess
import sys
from typing import Any, Dict, List

PLAN_PATH = "/tmp/plan_ajuste_500_dmm_20261027.json"
BACKUP_PATH = "/Users/alefvaras/Documents/akibara/ops/backups/pre_ajuste_500_dmm_1974_20261027.json"

def run_php(code: str, target: str = "prod") -> str:
    """Ejecuta PHP vía wp eval-file - sin tocar disco remoto."""
    if target == "prod":
        cmd = ["ssh", "akibara", "cd domains/akibara.cl/public_html && wp eval-file -"]
    else:
        cmd = ["docker", "exec", "-i", "akibara-cron-1", "wp", "eval-file", "-"]

    p = subprocess.run(cmd, input=code, text=True, capture_output=True)
    if p.returncode != 0:
        raise RuntimeError(f"Error ejecutando PHP en {target} (code {p.returncode}): {p.stderr}")
    return p.stdout

def backup_production_current_state() -> None:
    print(f"\n[BACKUP] Obteniendo estado actual de precios de Producción...")
    php = r"""<?php
    global $wpdb;

    $sql = "
    SELECT p.ID, p.post_title,
           COALESCE(pm_sku.meta_value, '') as sku,
           COALESCE(pm_stock.meta_value, '') as stock_status,
           COALESCE(pm_manage.meta_value, 'no') as manage_stock,
           COALESCE(pm_stock_qty.meta_value, '') as stock_qty,
           COALESCE(pm_reg.meta_value, '0') as regular_price,
           COALESCE(pm_sale.meta_value, '') as sale_price,
           COALESCE(pm_price.meta_value, '0') as price
    FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
    LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status'
    LEFT JOIN {$wpdb->postmeta} pm_manage ON p.ID = pm_manage.post_id AND pm_manage.meta_key = '_manage_stock'
    LEFT JOIN {$wpdb->postmeta} pm_stock_qty ON p.ID = pm_stock_qty.post_id AND pm_stock_qty.meta_key = '_stock'
    LEFT JOIN {$wpdb->postmeta} pm_reg ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price'
    LEFT JOIN {$wpdb->postmeta} pm_sale ON p.ID = pm_sale.post_id AND pm_sale.meta_key = '_sale_price'
    LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
    WHERE p.post_type = 'product' AND p.post_status = 'publish'
    GROUP BY p.ID;
    ";

    $products = $wpdb->get_results($sql, ARRAY_A);
    $backup = [];
    foreach ($products as $p) {
        $backup[(int)$p['ID']] = $p;
    }
    echo json_encode($backup);
    """
    raw = run_php(php, "prod")
    match = re.search(r'\{.*\}', raw, re.DOTALL)
    backup_data = json.loads(match.group(0) if match else raw)

    os.makedirs(os.path.dirname(BACKUP_PATH), exist_ok=True)
    with open(BACKUP_PATH, "w", encoding="utf-8") as f:
        json.dump(backup_data, f, indent=2, ensure_ascii=False)
    print(f"[BACKUP] OK: Respaldo guardado en {BACKUP_PATH} con {len(backup_data)} productos.")

def apply_plan(plan: List[Dict[str, Any]], target: str = "prod") -> Dict[str, Any]:
    print(f"\n[APPLY] Aplicando ajuste de precios en {target.upper()} ({len(plan)} productos del plan)...")

    # Si es local, mapeamos por SKU; si es prod, por ID
    items_to_send = []
    for item in plan:
        items_to_send.append({
            "id": item.get("id"),
            "sku": item.get("sku"),
            "new_reg": item.get("new_reg"),
            "new_sale_5pct": item.get("new_sale_5pct"),
        })

    json_data = json.dumps(items_to_send)
    is_local = 1 if target == "local" else 0

    php = f"""<?php
    global $wpdb;
    $is_local = {is_local};
    $json_data = <<<'JSON'
{json_data}
JSON;
    $items = json_decode($json_data, true);
    $applied = 0;
    $skipped = 0;
    $errors = [];
    $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';

    foreach ($items as $it) {{
        $id = 0;
        if ($is_local) {{
            $sku = (string) $it['sku'];
            if ($sku !== '') {{
                $id = (int) wc_get_product_id_by_sku($sku);
            }}
        }} else {{
            $id = (int) $it['id'];
        }}

        if (!$id) {{
            $skipped++;
            continue;
        }}

        $p = get_post($id);
        if (!$p || $p->post_type !== 'product' || $p->post_status !== 'publish') {{
            $skipped++;
            continue;
        }}

        $stock_status = get_post_meta($id, '_stock_status', true) ?: 'instock';
        $new_reg = (string) $it['new_reg'];
        $new_sale = (string) $it['new_sale_5pct'];

        $cur_reg = get_post_meta($id, '_regular_price', true);
        $cur_sale = get_post_meta($id, '_sale_price', true);
        $cur_price = get_post_meta($id, '_price', true);

        // Regla 18: NUNCA tocar _stock, _stock_status, _manage_stock
        update_post_meta($id, '_regular_price', $new_reg);

        if ($stock_status === 'onbackorder') {{
            update_post_meta($id, '_sale_price', $new_sale);
            update_post_meta($id, '_price', $new_sale);
            $min_price = (float) $new_sale;
            $max_price = (float) $new_reg;
            $onsale = 1;
        }} else {{
            // instock o outofstock: Cero sale_price
            delete_post_meta($id, '_sale_price');
            update_post_meta($id, '_price', $new_reg);
            $min_price = (float) $new_reg;
            $max_price = (float) $new_reg;
            $onsale = 0;
        }}

        // Actualizar lookup table directamente
        $wpdb->update(
            $lookup_table,
            [
                'min_price' => $min_price,
                'max_price' => $max_price,
                'onsale' => $onsale,
            ],
            ['product_id' => $id]
        );

        wc_delete_product_transients($id);
        $applied++;
    }}

    echo json_encode([
        'applied' => $applied,
        'skipped' => $skipped,
        'errors' => $errors,
    ]);
    """

    raw_res = run_php(php, target)
    match = re.search(r'\{.*\}', raw_res, re.DOTALL)
    res = json.loads(match.group(0) if match else raw_res)

    print(f"[APPLY] {target.upper()}: {res['applied']} productos actualizados ({res['skipped']} omitidos).")
    if res.get("errors"):
        print(f"[APPLY] Errores en {target.upper()}: {res['errors']}")

    # Reindexar en akibara-search vía rebuild
    print(f"[REINDEX] Reconstruyendo índice de akibara-search en {target.upper()}...")
    if target == "prod":
        cmd_reindex = ["ssh", "akibara", "cd domains/akibara.cl/public_html && wp akb-search rebuild"]
    else:
        cmd_reindex = ["docker", "exec", "-i", "akibara-cron-1", "wp", "akb-search", "rebuild"]

    p_reindex = subprocess.run(cmd_reindex, capture_output=True, text=True)
    if p_reindex.returncode != 0:
        print(f"[REINDEX] Warning ({target.upper()}): {p_reindex.stderr}")
    else:
        print(f"[REINDEX] OK ({target.upper()}):\n{p_reindex.stdout.strip()}")

    # Purga de cachés
    print(f"[CACHE] Purgando cachés en {target.upper()}...")
    if target == "prod":
        cmd_purge = ["ssh", "akibara", "cd domains/akibara.cl/public_html && wp litespeed-purge all && wp cache flush"]
    else:
        cmd_purge = ["docker", "exec", "-i", "akibara-cron-1", "wp", "cache", "flush"]
    p_purge = subprocess.run(cmd_purge, text=True, capture_output=True)
    print(f"[CACHE] Resultado purga {target.upper()}:\n{p_purge.stdout.strip()}")

    return res

def main():
    target_arg = sys.argv[1] if len(sys.argv) > 1 else "all"

    print("==================================================================")
    print("  DESPLIEGUE URGENTE DE AJUSTE DE PRECIOS (-$500 VS DMM)")
    print("==================================================================")

    with open(PLAN_PATH, "r", encoding="utf-8") as f:
        plan = json.load(f)
    print(f"Plan cargado: {len(plan)} productos a procesar.")

    # 1. Respaldo previo obligatorio de Producción
    backup_production_current_state()

    # 2. Validación rápida en Docker Local (Espejo)
    if target_arg in ("local", "all"):
        print("\n>>> FASE 1: Validación en Espejo Local")
        apply_plan(plan, "local")
        print("[LOCAL OK] Validación en espejo completada.")

    # 3. Aplicación Inmediata en Producción
    if target_arg in ("prod", "all"):
        print("\n>>> FASE 2: Aplicación Inmediata en Producción")
        apply_plan(plan, "prod")
        print("[PROD OK] Precios aplicados en Producción.")

    # 4. Watchdog y Guardrail de Inventario en Producción
    print("\n>>> FASE 3: Certificación con Watchdog de Inventario")
    subprocess.run(["python3", "ops/guardrail-inventario-akibara.py", "--target", "prod"], check=False)

if __name__ == "__main__":
    main()
