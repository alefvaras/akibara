#!/usr/bin/env python3
"""Guardrail y Watchdog de Integridad de Inventario de Akibara.

Previene que el inventario se ensucie por:
1. Volcados indebidos de Local a Producción.
2. Confusión entre disponibilidad de distribuidor (A pedido / onbackorder) y stock físico (instock).
3. Inconsistencias entre _stock_status, _manage_stock, _stock y product_visibility.
4. Productos en reserva (onbackorder) sin el 5% obligatorio de descuento.
"""

import argparse
import json
import subprocess
import sys
from typing import Any, Dict, List

def run_inventory_audit(target: str = "prod") -> Dict[str, Any]:
    print(f"============================================================")
    print(f"  WATCHDOG & GUARDRAIL DE INTEGRIDAD DE INVENTARIO ({target.upper()})")
    print(f"============================================================\n")

    php_script = """<?php
    global $wpdb;

    // Obtener todos los productos publicados con sus metadatos de inventario
    $sql = "
    SELECT p.ID, p.post_title, p.post_name,
           COALESCE(pm_sku.meta_value, '') as sku,
           COALESCE(pm_stock.meta_value, '') as stock_status,
           COALESCE(pm_manage.meta_value, 'no') as manage_stock,
           COALESCE(pm_stock_qty.meta_value, '') as stock_qty,
           COALESCE(pm_backorders.meta_value, 'no') as backorders,
           COALESCE(pm_reg.meta_value, '0') as regular_price,
           COALESCE(pm_sale.meta_value, '') as sale_price,
           COALESCE(pm_price.meta_value, '0') as price
    FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
    LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status'
    LEFT JOIN {$wpdb->postmeta} pm_manage ON p.ID = pm_manage.post_id AND pm_manage.meta_key = '_manage_stock'
    LEFT JOIN {$wpdb->postmeta} pm_stock_qty ON p.ID = pm_stock_qty.post_id AND pm_stock_qty.meta_key = '_stock'
    LEFT JOIN {$wpdb->postmeta} pm_backorders ON p.ID = pm_backorders.post_id AND pm_backorders.meta_key = '_backorders'
    LEFT JOIN {$wpdb->postmeta} pm_reg ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price'
    LEFT JOIN {$wpdb->postmeta} pm_sale ON p.ID = pm_sale.post_id AND pm_sale.meta_key = '_sale_price'
    LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
    WHERE p.post_type = 'product' AND p.post_status = 'publish'
    GROUP BY p.ID;
    ";

    $products = $wpdb->get_results($sql, ARRAY_A);
    if (!is_array($products) || $wpdb->last_error !== '') {
        throw new RuntimeException('No se pudo leer el catálogo completo');
    }
    $terms = $products ? wp_get_object_terms(array_column($products, 'ID'), 'product_visibility', ['fields' => 'all_with_object_id']) : [];
    if (is_wp_error($terms) || $wpdb->last_error !== '') {
        throw new RuntimeException('No se pudo leer la visibilidad del catálogo');
    }
    $outofstock_ids = [];
    foreach ($terms as $term) {
        if ($term->slug === 'outofstock') $outofstock_ids[(int)$term->object_id] = true;
    }

    $report = [
        'total_audited' => count($products),
        'instock_count' => 0,
        'onbackorder_count' => 0,
        'outofstock_count' => 0,
        'anomalies' => [
            'onbackorder_missing_5pct_discount' => [],
            'onbackorder_with_managed_stock_number' => [],
            'invalid_stock_contract' => [],
            'visibility_mismatch' => [],
        ]
    ];

    foreach ($products as $p) {
        $id = (int)$p['ID'];
        $title = $p['post_title'];
        $status = $p['stock_status'];
        $manage = $p['manage_stock'];
        $qty = $p['stock_qty'];
        $reg = (float)$p['regular_price'];
        $sale = $p['sale_price'] !== '' ? (float)$p['sale_price'] : null;
        $price = (float)$p['price'];

        // Visibility term check
        $has_vis_oos = isset($outofstock_ids[$id]);
        if (!is_numeric($p['regular_price']) || !is_numeric($p['price']) || ($p['sale_price'] !== '' && !is_numeric($p['sale_price']))) {
            $report['anomalies']['invalid_stock_contract'][] = [
                'id' => $id, 'title' => $title, 'issue' => 'metadatos de precio inválidos'
            ];
        }

        if ($status === 'instock') {
            $report['instock_count']++;
            if ($has_vis_oos) {
                $report['anomalies']['visibility_mismatch'][] = [
                    'id' => $id, 'title' => $title, 'status' => $status, 'issue' => 'instock pero tiene término outofstock'
                ];
            }
            // Una oferta del 5% no demuestra una reserva. El stock físico se valida por cantidad y política.
            if ($manage !== 'yes' || !is_numeric($qty) || (float)$qty <= 0 || $p['backorders'] !== 'no') {
                $report['anomalies']['invalid_stock_contract'][] = [
                    'id' => $id, 'title' => $title, 'issue' => 'instock requiere cantidad positiva gestionada y backorders=no'
                ];
            }
        } elseif ($status === 'onbackorder') {
            $report['onbackorder_count']++;
            if ($has_vis_oos) {
                $report['anomalies']['visibility_mismatch'][] = [
                    'id' => $id, 'title' => $title, 'status' => $status, 'issue' => 'onbackorder pero tiene término outofstock'
                ];
            }
            // Control Regla 8: Onbackorder DEBE tener 5% de descuento con redondeo a la centena (round_100 obligatorio)
            $expected_sale_100 = round(($reg * 0.95) / 100.0, 0, PHP_ROUND_HALF_UP) * 100;
            if ($reg <= 0 || $sale !== (float)$expected_sale_100 || $price !== $sale) {
                $report['anomalies']['onbackorder_missing_5pct_discount'][] = [
                    'id' => $id, 'title' => $title, 'reg' => $reg, 'sale' => $sale, 'expected_sale' => $expected_sale_100
                ];
            }
            // La reserva no lleva inventario físico, incluso cuando su cantidad es cero.
            // validate_props() guarda backorders=no sin gestión de cantidades; onbackorder mantiene la reserva comprable.
            if ($manage !== 'no' || ($qty !== '' && $qty !== null && (!is_numeric($qty) || (float)$qty != 0)) || $p['backorders'] !== 'no') {
                $report['anomalies']['onbackorder_with_managed_stock_number'][] = [
                    'id' => $id, 'title' => $title, 'qty' => $qty, 'manage' => $manage, 'backorders' => $p['backorders']
                ];
            }
        } elseif ($status === 'outofstock') {
            $report['outofstock_count']++;
            if ($manage !== 'no' || ($qty !== '' && $qty !== null && (!is_numeric($qty) || (float)$qty != 0)) || $p['backorders'] !== 'no' || $sale !== null || $price !== $reg) {
                $report['anomalies']['invalid_stock_contract'][] = [
                    'id' => $id, 'title' => $title, 'issue' => 'agotado requiere cantidad no física, sin reserva ni oferta y precio regular'
                ];
            }
            if (!$has_vis_oos) {
                $report['anomalies']['visibility_mismatch'][] = [
                    'id' => $id, 'title' => $title, 'status' => $status, 'issue' => 'outofstock pero falta término outofstock'
                ];
            }
        } else {
            $report['anomalies']['invalid_stock_contract'][] = [
                'id' => $id, 'title' => $title, 'issue' => 'estado de stock desconocido'
            ];
        }
    }

    echo json_encode($report);
    """

    if target == "prod":
        cmd = ["ssh", "-o", "BatchMode=yes", "akibara", "cd domains/akibara.cl/public_html && wp eval-file -"]
    else:
        cmd = ["docker", "exec", "-i", "akibara-cron-1", "wp", "eval-file", "-"]

    proc = subprocess.run(cmd, input=php_script, text=True, capture_output=True, timeout=120)
    if proc.returncode != 0:
        raise RuntimeError("No se pudo leer el inventario")

    try:
        report = json.loads(proc.stdout)
    except Exception as exc:
        raise RuntimeError("La lectura no devolvió un inventario JSON válido") from exc

    required = ('total_audited', 'instock_count', 'onbackorder_count', 'outofstock_count', 'anomalies')
    if (not isinstance(report, dict) or any(key not in report for key in required)
            or type(report['total_audited']) is not int or report['total_audited'] <= 0
            or any(type(report[key]) is not int or report[key] < 0 for key in required[1:4])
            or sum(report[key] for key in required[1:4]) != report['total_audited']
            or not isinstance(report['anomalies'], dict)
            or set(report['anomalies']) != {'onbackorder_missing_5pct_discount', 'onbackorder_with_managed_stock_number', 'invalid_stock_contract', 'visibility_mismatch'}
            or any(not isinstance(rows, list) for rows in report['anomalies'].values())):
        raise RuntimeError("Inventario vacío o incompleto: no se puede aprobar")

    print(f"Total productos auditados: {report.get('total_audited', 0)}")
    print(f"  • En Stock (físico en tienda): {report.get('instock_count', 0)}")
    print(f"  • A Pedido / Reserva (distribuidor): {report.get('onbackorder_count', 0)}")
    print(f"  • Agotados: {report.get('outofstock_count', 0)}")
    print()

    anomalies = report.get("anomalies", {})
    has_issues = False
    for k, items in anomalies.items():
        if items:
            has_issues = True
            print(f"[ALERTA] {k}: {len(items)} productos")
            for it in items[:5]:
                print(f"   * [{it.get('id')}] {it.get('title')}: {it}")
            if len(items) > 5:
                print(f"   ... y {len(items) - 5} más.")

    if not has_issues:
        print("[APROBADO] Inventario medido: se cumplen las reglas verificadas.")

    return report

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Watchdog de Higiene de Inventario de Akibara")
    parser.add_argument("--target", choices=["local", "prod"], default="prod", help="Destino (local o prod)")
    args = parser.parse_args()
    try:
        result = run_inventory_audit(target=args.target)
    except (RuntimeError, OSError, subprocess.SubprocessError) as exc:
        print(f"[NO MEDIDO] {exc}", file=sys.stderr)
        sys.exit(2)
    sys.exit(1 if any(result['anomalies'].values()) else 0)
