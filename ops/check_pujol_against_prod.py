import xlrd
import re
import json
import subprocess

def clean_isbn(val):
    if not val:
        return ''
    s = re.sub(r'[^0-9X]', '', str(val).split('.')[0].upper())
    return s

files = [
    '/Users/alefvaras/Downloads/000-26 Equipo Akibara - Cómics - 11-05-26.xls',
    '/Users/alefvaras/Downloads/000-26 Equipo Akibara - Cómics.xls',
    '/Users/alefvaras/Downloads/pedido-akibara-pujol-noviembre.xls',
    '/Users/alefvaras/Downloads/pedido-akibara-pujol-septiembre.xls'
]

pujol_items = {}

for f in files:
    wb = xlrd.open_workbook(f)
    for sname in wb.sheet_names():
        ws = wb.sheet_by_name(sname)
        for r in range(ws.nrows):
            c0 = clean_isbn(ws.cell_value(r, 0))
            if len(c0) in (10, 13) and (c0.startswith('978') or c0.startswith('979')):
                qty_raw = ws.cell_value(r, 1) if ws.ncols > 1 else 1.0
                title = str(ws.cell_value(r, 2)).strip() if ws.ncols > 2 else ''
                try:
                    qty = float(qty_raw)
                except:
                    qty = 1.0
                if qty == 0:
                    qty = 1.0
                if c0 not in pujol_items or pujol_items[c0]['qty'] < qty:
                    pujol_items[c0] = {
                        'isbn': c0,
                        'qty': qty,
                        'title': title,
                        'file': f.split('/')[-1]
                    }

print(f'Total ISBNs indexados de Pujol: {len(pujol_items)}')

# Generate PHP script
isbns_json = json.dumps(list(pujol_items.keys()))

php_code = """<?php
require_once 'domains/akibara.cl/public_html/wp-load.php';
global $wpdb;

$isbns = json_decode('""" + isbns_json + """', true);
$escaped = implode("','", array_map('esc_sql', $isbns));

$sql = "
    SELECT p.ID, p.post_title,
           MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) as sku,
           MAX(CASE WHEN pm.meta_key = '_stock_status' THEN pm.meta_value END) as stock_status,
           MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) as stock,
           MAX(CASE WHEN pm.meta_key = '_manage_stock' THEN pm.meta_value END) as manage_stock,
           MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END) as regular_price,
           MAX(CASE WHEN pm.meta_key = '_sale_price' THEN pm.meta_value END) as sale_price,
           MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) as price
    FROM wp_posts p
    INNER JOIN wp_postmeta pm_join ON p.ID = pm_join.post_id AND (
        (pm_join.meta_key = '_sku' AND pm_join.meta_value IN ('" . $escaped . "')) OR
        (pm_join.meta_key = '_global_unique_id' AND pm_join.meta_value IN ('" . $escaped . "'))
    )
    LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id
    WHERE p.post_type = 'product' AND p.post_status = 'publish'
    GROUP BY p.ID
";

$rows = $wpdb->get_results($sql, ARRAY_A);
echo json_encode($rows);
"""

proc = subprocess.run(['ssh', 'akibara', 'php'], input=php_code, text=True, capture_output=True)
if proc.returncode != 0:
    print('SSH Error:', proc.stderr)
else:
    prod_products = json.loads(proc.stdout)
    print(f'Encontrados en WooCommerce Producción: {len(prod_products)}')
    
    by_status = {}
    for p in prod_products:
        st = p['stock_status']
        by_status.setdefault(st, []).append(p)
    
    for st, prods in by_status.items():
        print(f'  Status [{st}]: {len(prods)} productos')
        
    print('\n=== PRODUCTOS DE PUJOL QUE EN PRODUCCIÓN ESTÁN OUTOFSTOCK ===')
    for p in by_status.get('outofstock', []):
        sku = p['sku']
        pujol_info = pujol_items.get(sku, {})
        print(f"ID: {p['ID']} | {p['post_title']} | SKU: {sku} | Factura/Pedido: {pujol_info.get('file')} (qty: {pujol_info.get('qty')}) | Reg: {p['regular_price']}")
