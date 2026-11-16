import subprocess
import json

script = """<?php
if (file_exists("/var/www/html/wp-load.php")) {
    require_once "/var/www/html/wp-load.php";
} else {
    require_once "domains/akibara.cl/public_html/wp-load.php";
}
global $wpdb;
$rows = $wpdb->get_results("
    SELECT p.ID, p.post_title, pm_sku.meta_value as sku, pm_st.meta_value as stock_status, pm_qty.meta_value as stock_qty, pm_man.meta_value as manage_stock
    FROM wp_posts p
    LEFT JOIN wp_postmeta pm_sku ON p.ID=pm_sku.post_id AND pm_sku.meta_key='_sku'
    LEFT JOIN wp_postmeta pm_st ON p.ID=pm_st.post_id AND pm_st.meta_key='_stock_status'
    LEFT JOIN wp_postmeta pm_qty ON p.ID=pm_qty.post_id AND pm_qty.meta_key='_stock'
    LEFT JOIN wp_postmeta pm_man ON p.ID=pm_man.post_id AND pm_man.meta_key='_manage_stock'
    WHERE p.post_type='product' AND p.post_status='publish'
", ARRAY_A);
$map = [];
foreach ($rows as $r) {
    $sku = $r["sku"] ?: "id_" . $r["ID"];
    $map[$sku] = $r;
}
echo json_encode($map);
"""

proc_loc = subprocess.run(["docker", "exec", "-i", "akibara-cron-1", "wp", "eval-file", "-"], input=script, text=True, capture_output=True)
data_loc = json.loads(proc_loc.stdout)

proc_prd = subprocess.run(["ssh", "akibara", "php"], input=script, text=True, capture_output=True)
data_prd = json.loads(proc_prd.stdout)

print(f"Total productos en Local: {len(data_loc)} | En Prod: {len(data_prd)}")

discrepancies = []
for sku, loc in data_loc.items():
    if sku in data_prd:
        prd = data_prd[sku]
        loc_st = loc["stock_status"]
        prd_st = prd["stock_status"]
        loc_qty = float(loc["stock_qty"] or 0)
        prd_qty = float(prd["stock_qty"] or 0)
        
        # Local tiene stock (instock o qty > 0), pero Prod está outofstock
        if (loc_st == "instock" or loc_qty > 0) and prd_st == "outofstock":
            discrepancies.append({
                "sku": sku,
                "local_id": loc["ID"],
                "prod_id": prd["ID"],
                "title": loc["post_title"],
                "local_status": loc_st,
                "local_qty": loc_qty,
                "prod_status": prd_st,
                "prod_qty": prd_qty
            })

print(f"\nTotal productos con stock en LOCAL pero OUTOFSTOCK en PRODUCCIÓN: {len(discrepancies)}")
for d in discrepancies:
    pid = d["prod_id"]
    t = d["title"]
    s = d["sku"]
    ls = d["local_status"]
    lq = d["local_qty"]
    ps = d["prod_status"]
    pq = d["prod_qty"]
    print(f"[{pid}] {t} (SKU: {s}) | Local: {ls} (qty: {lq}) | Prod: {ps} (qty: {pq})")
