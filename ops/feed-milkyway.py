#!/usr/bin/env python3
"""Feed de disponibilidad de Milky Way Ediciones.

La tienda de Milky Way corre sobre Shopify, que publica su catálogo en
`/products.json` con el SKU (que es el ISBN) y el booleano `available` por
variante. Su robots.txt es `Allow: /` para todos los agentes y solo bloquea
admin/cart/checkout, así que esta ruta está permitida. Se pide con pausa entre
páginas para no golpear el sitio.

Uso: feed-milkyway.py > feed-milkyway.json
"""
from __future__ import annotations
import datetime, json, sys, time, urllib.error, urllib.request

BASE = 'https://www.milkywayediciones.com/products.json'
UA = 'Mozilla/5.0 (compatible; AkibaraStockCheck/1.0; +https://akibara.cl)'
PAUSA = 1.0
MAX_PAGINAS = 30


def pedir(page: int) -> list:
    req = urllib.request.Request(f'{BASE}?limit=250&page={page}', headers={'User-Agent': UA})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.load(r).get('products', [])


def main() -> int:
    productos = []
    for page in range(1, MAX_PAGINAS + 1):
        try:
            lote = pedir(page)
        except urllib.error.URLError as e:
            print(f'error pidiendo la página {page}: {e}', file=sys.stderr)
            return 1
        if not lote:
            break
        productos += lote
        time.sleep(PAUSA)

    items, vistos = [], set()
    for p in productos:
        for v in p.get('variants', []):
            isbn = ''.join(c for c in (v.get('sku') or '') if c.isdigit())
            if len(isbn) != 13 or isbn in vistos:
                continue
            vistos.add(isbn)
            items.append({
                'isbn': isbn,
                'titulo': p.get('title', '').strip(),
                'estado': 'disponible' if v.get('available') else 'agotado',
                'nota': f"EUR {v.get('price')}",
            })

    n_ago = sum(1 for i in items if i['estado'] == 'agotado')
    # Misma guarda que el feed de Ivrea: una lectura degenerada no debe aplicarse.
    if len(items) < 200 or n_ago == 0 or n_ago > len(items) * 0.5:
        print(f'feed sospechoso: {len(items)} items, {n_ago} agotados', file=sys.stderr)
        return 2

    json.dump({
        'editorial': 'Milky Way',
        'marca': 'Milky Way',
        'archivo': 'products.json (Shopify)',
        'fecha': datetime.datetime.now().isoformat(),
        'items': items,
    }, sys.stdout, ensure_ascii=False)
    print(f'\n-- Milky Way: {len(items)} items, {n_ago} agotados', file=sys.stderr)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
