import xlrd
import re
import os
import subprocess
import json

def clean_isbn(val):
    if not val:
        return ""
    return re.sub(r"[^0-9X]", "", str(val).split(".")[0].upper())

pujol_files = [
    "/Users/alefvaras/Downloads/000-26 Equipo Akibara - Cómics.xls",
    "/Users/alefvaras/Downloads/000-26 Equipo Akibara - Cómics - 11-05-26.xls",
    "/Users/alefvaras/Downloads/pedido-akibara-pujol-septiembre.xls",
    "/Users/alefvaras/Downloads/pedido-akibara-pujol-noviembre.xls"
]

all_isbns = set()
for path in pujol_files:
    wb = xlrd.open_workbook(path)
    for sname in wb.sheet_names():
        ws = wb.sheet_by_name(sname)
        for r in range(ws.nrows):
            for c in range(min(4, ws.ncols)):
                isbn = clean_isbn(ws.cell_value(r, c))
                if len(isbn) in (10, 13) and (isbn.startswith("978") or isbn.startswith("979")):
                    all_isbns.add(isbn)

isbns_list = list(all_isbns)

php_code = f"""<?php
require_once 'domains/akibara.cl/public_html/wp-load.php';
$isbns = {json.dumps(isbns_list)};
$results = [];
foreach ($isbns as $isbn) {{
    $posts = get_posts([
        'post_type' => 'product',
        'posts_per_page' => 1,
        'meta_query' => [
            'relation' => 'OR',
            ['key' => '_sku', 'value' => $isbn],
            ['key' => '_global_unique_id', 'value' => $isbn]
        ]
    ]);
    if ($posts) {{
        $p = $posts[0];
        $id = $p->ID;
        $results[] = [
            'id' => $id,
            'title' => $p->post_title,
            'sku' => $isbn,
            'stock_status' => get_post_meta($id, '_stock_status', true),
            'regular_price' => get_post_meta($id, '_regular_price', true),
            'sale_price' => get_post_meta($id, '_sale_price', true),
            'price' => get_post_meta($id, '_price', true),
        ];
    }}
}}
echo json_encode($results);
"""

proc = subprocess.run(["ssh", "akibara", "php"], input=php_code, capture_output=True, text=True)
if proc.returncode != 0:
    print("Return code:", proc.returncode)
    print("Stderr:", proc.stderr)
    print("Stdout:", proc.stdout)
else:
    try:
        data = json.loads(proc.stdout)
        print("Total productos en WooCommerce que coinciden con Pujol:", len(data))
        outofstock = [x for x in data if x["stock_status"] == "outofstock"]
        onbackorder = [x for x in data if x["stock_status"] == "onbackorder"]
        instock = [x for x in data if x["stock_status"] == "instock"]
        print("  Instock (físico):", len(instock))
        print("  Onbackorder (a pedido):", len(onbackorder))
        print("  Outofstock (AGOTADO):", len(outofstock))
        print(f"\n--- PRODUCTOS EN PUJOL QUE ACTUALMENTE ESTÁN AGOTADOS ({len(outofstock)}) ---")
        for x in outofstock:
            pid = x['id']
            title = x['title']
            sku = x['sku']
            reg = x['regular_price']
            print(f"[{pid}] {title} (sku: {sku}) - reg: {reg}")
    except Exception as e:
        print("Json parse error:", e, proc.stdout[:300])
