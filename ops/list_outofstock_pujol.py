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

all_items = {}
for path in pujol_files:
    fname = os.path.basename(path)
    wb = xlrd.open_workbook(path)
    for sname in wb.sheet_names():
        ws = wb.sheet_by_name(sname)
        for r in range(ws.nrows):
            for c in range(min(4, ws.ncols)):
                isbn = clean_isbn(ws.cell_value(r, c))
                if len(isbn) in (10, 13) and (isbn.startswith("978") or isbn.startswith("979")):
                    title = ""
                    for c2 in range(ws.ncols):
                        val = str(ws.cell_value(r, c2)).strip()
                        if len(val) > 3 and not re.match(r"^[0-9.]+$", val):
                            title = val
                            break
                    all_items[isbn] = (title, fname)

print(f"Total ISBNs en archivos de Pujol: {len(all_items)}")

# Query prod
isbns_list = list(all_items.keys())
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
            'stock_qty' => get_post_meta($id, '_stock', true),
            'manage_stock' => get_post_meta($id, '_manage_stock', true),
        ];
    }}
}}
echo json_encode($results);
"""

proc = subprocess.run(["ssh", "akibara", "php"], input=php_code, capture_output=True, text=True)
data = json.loads(proc.stdout)
outofstock = [x for x in data if x["stock_status"] == "outofstock"]
print(f"Productos coincidentes en WooCommerce: {len(data)}")
print(f"De los cuales outofstock: {len(outofstock)}")
print("\nLISTA COMPLETA DE PRODUCTOS OUTOFSTOCK:")
for x in sorted(outofstock, key=lambda i: i["title"]):
    p_info = all_items.get(x["sku"], ("", ""))
    pid = x['id']
    title = x['title']
    sku = x['sku']
    reg = x['regular_price']
    fname = p_info[1]
    print(f"[{pid}] {title} | SKU:{sku} | Reg:{reg} | Archivo:{fname}")
