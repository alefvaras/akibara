#!/usr/bin/env python3
"""Remediación de Precios de España e Higiene de Regla 8 y 18.3.

Aplica los cambios primero en Docker Local y luego en Producción vía SSH wp eval-file -.
Guarda respaldo previo en ops/backups/pre_auditoria_espana_precios_20261027.json.
Respeta estrictamente la Regla 18 (cero toques de stock físico ni cantidades).
"""

import json
import os
import re
import subprocess
import sys
from typing import Any, Dict, List

BACKUP_PATH = "/Users/alefvaras/Documents/akibara/ops/backups/pre_auditoria_espana_precios_20261027.json"

SPECIFIC_SKUS = {
    "9791370136970": {
        "title": "El chico que me gusta no es un chico 03 - Edición Especial",
        "reg": 24990,
        "sale": 23741,
        "price": 23741,
    },
    "9791388178979": {
        "title": "One Punch-Man 35",
        "reg": 13500,
        "sale": 12825,
        "price": 12825,
    },
    "9791388450341": {
        "title": "One Punch-Man 36",
        "reg": 13500,
        "sale": 12825,
        "price": 12825,
    },
    "9791388487361": {
        "title": "One Punch-Man 37",
        "reg": 13500,
        "sale": 12825,
        "price": 12825,
    },
    "9788419536655": {
        "title": "El verano que Hikaru murió 01",
        "reg": 15500,
        "sale": 14725,
        "price": 14725,
    },
    "9788419914095": {
        "title": "El verano que Hikaru murió 02",
        "reg": 15500,
        "sale": 14725,
        "price": 14725,
    },
    "9788419914798": {
        "title": "El verano que Hikaru murió 03",
        "reg": 15500,
        "sale": 14725,
        "price": 14725,
    },
    "9791387506445": {
        "title": "The Climber 11",
        "reg": 15500,
        "sale": 14725,
        "price": 14725,
    },
}

ARTBOOKS = {
    "9791388055010": {
        "title": "El arte de Atelier of Witch Hat",
        "series_slug": "atelier-of-witch-hat",
        "series_name": "Atelier Of Witch Hat",
    },
    "9791370134693": {
        "title": "El arte de Berserk",
        "series_slug": "maximum-berserk",
        "series_name": "Maximum Berserk",
    },
}

def run_php(code: str, target: str = "prod") -> str:
    """Ejecuta código PHP vía wp eval-file - sin tocar disco en el servidor remoto."""
    if target == "prod":
        cmd = ["ssh", "akibara", "cd domains/akibara.cl/public_html && wp eval-file -"]
    else:
        cmd = ["docker", "exec", "-i", "akibara-cron-1", "wp", "eval-file", "-"]

    p = subprocess.run(cmd, input=code, text=True, capture_output=True)
    if p.returncode != 0:
        raise RuntimeError(f"Error ejecutando PHP en {target} (code {p.returncode}): {p.stderr}")
    return p.stdout

def get_spain_products(target: str = "prod") -> List[Dict[str, Any]]:
    php = r"""<?php
    global $wpdb;
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

        $sku = (string) get_post_meta($id, '_sku', true);
        $reg = (string) get_post_meta($id, '_regular_price', true);
        $sale = (string) get_post_meta($id, '_sale_price', true);
        $price = (string) get_post_meta($id, '_price', true);
        $stock_status = (string) get_post_meta($id, '_stock_status', true);
        $stock = (string) get_post_meta($id, '_stock', true);
        $manage_stock = (string) get_post_meta($id, '_manage_stock', true);
        $series = wp_get_post_terms($id, 'pa_serie', ['fields' => 'names']);
        $brands = wp_get_post_terms($id, 'product_brand', ['fields' => 'names']);

        $results[] = [
            'id' => (int) $id,
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
        ];
    }
    echo json_encode($results);
    """
    out = run_php(php, target)
    match = re.search(r'\[.*\]', out, re.DOTALL)
    if match:
        return json.loads(match.group(0))
    return json.loads(out)

def calculate_changes(products: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    changes = []
    for p in products:
        pid = p["id"]
        sku = p["sku"]
        title = p["title"]
        status = p["stock_status"]
        cur_reg = int(float(p["regular_price"] or 0))
        cur_sale = int(float(p["sale_price"] or 0)) if p["sale_price"] != "" and p["sale_price"] is not None else None
        cur_price = int(float(p["price"] or 0))

        change = {
            "id": pid,
            "sku": sku,
            "title": title,
            "stock_status": status,
            "reasons": [],
            "updates": {},
        }

        # 1. SKUs específicos
        if sku in SPECIFIC_SKUS:
            spec = SPECIFIC_SKUS[sku]
            if cur_reg != spec["reg"]:
                change["updates"]["_regular_price"] = spec["reg"]
            if cur_sale != spec["sale"]:
                change["updates"]["_sale_price"] = spec["sale"]
            if cur_price != spec["price"]:
                change["updates"]["_price"] = spec["price"]
            if change["updates"]:
                change["reasons"].append(f"Precios específicos {spec['title']}")

        # 2. Artbooks huérfanos de serie
        if sku in ARTBOOKS:
            art = ARTBOOKS[sku]
            if art["series_name"] not in p.get("series", []):
                change["reasons"].append(f"Asignar pa_serie: {art['series_name']}")
                change["assign_series"] = art

        # 3. Out of stock con sale_price residual (Regla 18.3)
        if status == "outofstock" and cur_sale is not None:
            change["updates"]["_sale_price"] = None  # Eliminar
            change["updates"]["_price"] = cur_reg
            change["reasons"].append("Regla 18.3: outofstock con _sale_price residual")

        # 4. Onbackorder descuento 5% exacto (Regla 8)
        if status == "onbackorder" and sku not in SPECIFIC_SKUS:
            exp_sale = round(cur_reg * 0.95)
            if cur_sale != exp_sale:
                change["updates"]["_sale_price"] = exp_sale
                change["updates"]["_price"] = exp_sale
                change["reasons"].append(f"Regla 8: onbackorder 5% (era {cur_sale}, debe ser {exp_sale})")

        if change["reasons"]:
            changes.append(change)
    return changes

ALL_MODIFIED_PROD_IDS = [
    16520, 16523, 16640, 16643, 16646, 16648, 16652, 16655, 16658, 16661, 16664, 16667,
    16720, 16723, 16726, 16735, 16738, 16931, 17196, 17222, 17225, 17228, 17232, 17234,
    17237, 17240, 17243, 17246, 17249, 17252, 17255, 17258, 22207, 22213, 22221, 22227,
    22236, 22248, 27213, 27281, 27308, 27318, 27335, 27926, 27949, 28099, 28102, 28104,
    28106, 28112, 28114, 28116, 28118, 28122, 28473, 28547, 28581, 28590, 28597, 28607,
    28621, 28623, 28635, 28642, 28708, 28709, 28810
]

def backup_production_state(product_ids: List[int]) -> None:
    if not product_ids:
        product_ids = ALL_MODIFIED_PROD_IDS
    print(f"\n[BACKUP] Generando respaldo de {len(product_ids)} productos en Producción...")
    json_ids = json.dumps(product_ids)
    php = f"""<?php
    $json_ids = <<<'JSON'
{json_ids}
JSON;
    $ids = json_decode($json_ids, true);
    $backup = [];
    foreach ($ids as $id) {{
        $p = get_post($id);
        if (!$p) continue;
        $meta = get_post_meta($id);
        $taxonomies = get_object_taxonomies('product');
        $terms = [];
        foreach ($taxonomies as $tax) {{
            $terms[$tax] = wp_get_post_terms($id, $tax, ['fields' => 'all']);
        }}
        $backup[$id] = [
            'post' => [
                'ID' => $p->ID,
                'post_title' => $p->post_title,
                'post_name' => $p->post_name,
                'post_status' => $p->post_status,
                'post_date' => $p->post_date,
            ],
            'meta' => $meta,
            'terms' => $terms,
        ];
    }}
    echo json_encode($backup);
    """
    raw_backup = run_php(php, "prod")
    backup_data = json.loads(raw_backup)
    os.makedirs(os.path.dirname(BACKUP_PATH), exist_ok=True)
    with open(BACKUP_PATH, "w", encoding="utf-8") as f:
        json.dump(backup_data, f, indent=2, ensure_ascii=False)
    print(f"[BACKUP] OK: Guardado exitoso en {BACKUP_PATH} con {len(backup_data)} productos.")

def apply_remediation(changes: List[Dict[str, Any]], target: str = "prod") -> Dict[str, Any]:
    print(f"\n[APPLY] Aplicando remediación en {target.upper()} ({len(changes)} productos)...")
    json_changes = json.dumps(changes)
    php = f"""<?php
    $json_changes = <<<'JSON'
{json_changes}
JSON;
    $changes = json_decode($json_changes, true);
    $applied = [];
    $errors = [];

    foreach ($changes as $item) {{
        $id = (int) $item['id'];
        $p = get_post($id);
        if (!$p) {{
            $errors[] = "Producto $id no encontrado";
            continue;
        }}

        // 1. Modificar postmeta de precios
        if (!empty($item['updates'])) {{
            foreach ($item['updates'] as $key => $val) {{
                if ($val === null) {{
                    delete_post_meta($id, $key);
                }} else {{
                    update_post_meta($id, $key, (string) $val);
                }}
            }}
        }}

        // 2. Modificar pa_serie y _product_attributes si aplica
        if (!empty($item['assign_series'])) {{
            $series_slug = $item['assign_series']['series_slug'];
            $series_name = $item['assign_series']['series_name'];

            $term = get_term_by('slug', $series_slug, 'pa_serie');
            if (!$term) {{
                $term = get_term_by('name', $series_name, 'pa_serie');
            }}
            if ($term) {{
                wp_set_object_terms($id, (int) $term->term_id, 'pa_serie', false);

                // Higiene de _product_attributes: solo pa_pais, pa_autor, pa_genero, pa_serie (NUNCA product_brand)
                $attrs = get_post_meta($id, '_product_attributes', true);
                if (!is_array($attrs)) {{
                    $attrs = [];
                }}
                unset($attrs['product_brand']); // Regla 9
                $attrs['pa_serie'] = [
                    'name' => 'pa_serie',
                    'value' => '',
                    'position' => 3,
                    'is_visible' => 1,
                    'is_variation' => 0,
                    'is_taxonomy' => 1,
                ];
                update_post_meta($id, '_product_attributes', $attrs);
            }} else {{
                $errors[] = "Term pa_serie no encontrado para $series_slug / $series_name";
            }}
        }}

        // 3. Sincronizar transients y lookup tables de WooCommerce
        wc_delete_product_transients($id);
        if (function_exists('wc_update_product_lookup_tables_column')) {{
            wc_update_product_lookup_tables_column('min_max_price', $id);
        }}
        if (function_exists('wc_update_product_lookup_tables')) {{
            wc_update_product_lookup_tables($id, 'price');
        }}

        $applied[] = $id;
    }}

    echo json_encode([
        'applied' => $applied,
        'errors' => $errors,
        'count' => count($applied),
    ]);
    """
    raw_res = run_php(php, target)
    try:
        # Extraer el JSON si hay texto adicional como "Success: Purgar la URL..."
        match = re.search(r'\{.*\}', raw_res, re.DOTALL)
        if match:
            res = json.loads(match.group(0))
        else:
            res = json.loads(raw_res)
    except Exception as e:
        print(f"[ERROR JSON {target}]: {e}\nRaw output:\n{raw_res}")
        raise

    print(f"[APPLY] {target.upper()}: {res['count']} productos actualizados en DB.")
    if res.get("errors"):
        print(f"[APPLY] Errores en {target.upper()}: {res['errors']}")

    applied_ids = res.get("applied", [])
    if applied_ids:
        print(f"[REINDEX] Reindexando {len(applied_ids)} productos en akibara-search ({target.upper()})...")
        ids_str = " ".join(str(i) for i in applied_ids)
        if target == "prod":
            cmd_reindex = ["ssh", "akibara", f"cd domains/akibara.cl/public_html && for id in {ids_str}; do wp akb-search reindex $id --quiet; done"]
        else:
            cmd_reindex = ["docker", "exec", "-i", "akibara-cron-1", "sh", "-c", f"for id in {ids_str}; do wp akb-search reindex $id --quiet; done"]
        p_reindex = subprocess.run(cmd_reindex, capture_output=True, text=True)
        if p_reindex.returncode != 0:
            print(f"[REINDEX] Warning en reindex ({target.upper()}): {p_reindex.stderr}")
        else:
            print(f"[REINDEX] OK: {len(applied_ids)} productos reindexados en {target.upper()}.")

    # Purga de cachés
    print(f"[CACHE] Purgando cachés en {target.upper()}...")
    if target == "prod":
        cmd_purge = ["ssh", "akibara", "cd domains/akibara.cl/public_html && wp litespeed-purge all && wp cache flush"]
    else:
        cmd_purge = ["docker", "exec", "-i", "akibara-cron-1", "wp", "cache", "flush"]
    p_purge = subprocess.run(cmd_purge, text=True, capture_output=True)
    print(f"[CACHE] Resultado purga {target.upper()}:\n{p_purge.stdout.strip()}")
    return res

def verify_all_rules(target: str = "prod") -> bool:
    print(f"\n[VERIFY] Verificando reglas en {target.upper()}...")
    prods = get_spain_products(target)
    remaining_changes = calculate_changes(prods)
    if remaining_changes:
        print(f"[VERIFY] FALLA en {target.upper()}: Aún hay {len(remaining_changes)} productos con inconsistencias:")
        for ch in remaining_changes[:10]:
            print(f"  * [{ch['id']}] SKU: {ch['sku']} - {ch['title']} -> {ch['reasons']}")
        return False
    print(f"[VERIFY] EXCELENTE: 0 inconsistencias de precios, Regla 8 o Regla 18.3 en {target.upper()} ({len(prods)} productos de España).")
    return True

def main():
    target_arg = sys.argv[1] if len(sys.argv) > 1 else "all"

    print("==================================================================")
    print("  EJECUTOR DE REMEDIACIÓN DE PRECIOS E HIGIENE DE ESPAÑA")
    print("==================================================================")

    # 1. Auditoría Local y Prod
    print("\n>>> FASE 1: Auditoría inicial")
    local_prods = get_spain_products("local")
    prod_prods = get_spain_products("prod")
    local_changes = calculate_changes(local_prods)
    prod_changes = calculate_changes(prod_prods)

    print(f"Productos España en Local: {len(local_prods)} (Cambios detectados: {len(local_changes)})")
    print(f"Productos España en Prod:  {len(prod_prods)} (Cambios detectados: {len(prod_changes)})")

    # 2. Respaldo previo de Prod
    print("\n>>> FASE 2: Respaldo previo obligatorio de Producción")
    prod_ids_to_modify = [ch["id"] for ch in prod_changes]
    backup_production_state(prod_ids_to_modify)

    # 3. Aplicar PRIMERO en Docker Local
    if target_arg in ("local", "all"):
        print("\n>>> FASE 3: Aplicar en Local (Regla del dueño: Local primero)")
        apply_remediation(local_changes, "local")
        ok_local = verify_all_rules("local")
        if not ok_local:
            print("[ERROR] Falla en la verificación local. Abortando antes de tocar producción.")
            sys.exit(1)
        print("[LOCAL VERDE] El espejo local está 100% probado y consistente.")

    # 4. Aplicar DESPUÉS en Producción
    if target_arg in ("prod", "all"):
        print("\n>>> FASE 4: Aplicar en Producción (DESPUÉS del espejo local)")
        if prod_changes:
            apply_remediation(prod_changes, "prod")
        else:
            print("[INFO] Los cambios de DB en Producción ya están aplicados.")
            prod_ids = ALL_MODIFIED_PROD_IDS
            print(f"[REINDEX] Reindexando {len(prod_ids)} productos en akibara-search (PROD)...")
            ids_str = " ".join(str(i) for i in prod_ids)
            cmd_reindex = ["ssh", "akibara", f"cd domains/akibara.cl/public_html && for id in {ids_str}; do wp akb-search reindex $id --quiet; done"]
            p_reindex = subprocess.run(cmd_reindex, capture_output=True, text=True)
            if p_reindex.returncode != 0:
                print(f"[REINDEX] Warning en reindex (PROD): {p_reindex.stderr}")
            else:
                print(f"[REINDEX] OK: {len(prod_ids)} productos reindexados en PROD.")

            print("[CACHE] Purgando cachés en PROD...")
            cmd_purge = ["ssh", "akibara", "cd domains/akibara.cl/public_html && wp litespeed-purge all && wp cache flush"]
            p_purge = subprocess.run(cmd_purge, text=True, capture_output=True)
            print(f"[CACHE] Resultado purga PROD:\n{p_purge.stdout.strip()}")

        ok_prod = verify_all_rules("prod")
        if not ok_prod:
            print("[ERROR] Falla en la verificación en producción.")
            sys.exit(1)
        print("[PROD VERDE] Producción 100% remediada y consistente.")

    # 5. Ejecutar guardrail de inventario
    print("\n>>> FASE 5: Watchdog de inventario en Producción")
    subprocess.run(["python3", "ops/guardrail-inventario-akibara.py", "--target", "prod"], check=False)

if __name__ == "__main__":
    main()
