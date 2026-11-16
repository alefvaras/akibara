#!/usr/bin/env python3
"""Calcula el precio de Akibara para quedar apenas bajo la competencia, con piso de margen.

REGLA DEL DUEÑO (2026-09-04): «tenemos que ser los más económicos pero por poco;
quizás no $500, depende del precio, pero la idea es un poco más económico».

Por eso el descuento no es un monto fijo sino un PORCENTAJE con tope y piso:
un tomo de $9.990 no puede bajar lo mismo que un kanzenban de $24.000.

    rebaja = precio_competencia × 3%,  mínimo $200, máximo $1.000
    precio = redondeado a la centena hacia abajo

PISO DE MARGEN — la parte que evita la carrera al fondo. Nunca se baja de
`costo_usd × RATIO_PISO`. El costo se saca de la lista de la editorial (para
Ivrea: el 40% de su PVP-USD) y el ratio piso es el más bajo que el dueño ya
aceptaba en su propio catálogo. Si la competencia vende bajo ese piso, NO se
iguala: se reporta para que decida una persona. Ganarle a un competidor que
vende a pérdida no es una victoria.

ENTRADA
  --catalogo  JSON del volcado de la tienda (ops/dump-disponibilidad.php)
  --competencia CSV con cabecera: isbn,precio,tienda
  --costos    (opcional) JSON {isbn: costo_usd} para aplicar el piso de margen
  --usd       tipo de cambio CLP/USD para el piso (por defecto 2600, conservador)

SALIDA: CSV de recomendaciones por stdout + resumen por stderr.
"""
from __future__ import annotations
import argparse, csv, json, sys

PCT = 0.03
REBAJA_MIN = 200
REBAJA_MAX = 1000
RATIO_PISO = 2600   # CLP por USD de costo; bajo esto no se compite


def redondear(v: int) -> int:
    """A la centena hacia abajo: un precio de tienda no termina en 37."""
    return int(v // 100 * 100)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--catalogo', required=True)
    ap.add_argument('--competencia', required=True)
    ap.add_argument('--costos')
    ap.add_argument('--usd', type=float, default=RATIO_PISO)
    a = ap.parse_args()

    catalogo = {str(p['sku']): p for p in json.load(open(a.catalogo, encoding='utf-8')) if p.get('sku')}
    costos = json.load(open(a.costos, encoding='utf-8')) if a.costos else {}

    comp: dict[str, tuple[int, str]] = {}
    with open(a.competencia, encoding='utf-8-sig', newline='') as fh:
        for row in csv.DictReader(fh):
            isbn = ''.join(c for c in (row.get('isbn') or '') if c.isdigit())
            precio = ''.join(c for c in (row.get('precio') or '') if c.isdigit())
            if len(isbn) != 13 or not precio:
                continue
            p = int(precio)
            # Si varias tiendas venden el mismo ISBN, manda la más barata.
            if isbn not in comp or p < comp[isbn][0]:
                comp[isbn] = (p, (row.get('tienda') or '').strip())

    w = csv.writer(sys.stdout)
    w.writerow(['isbn', 'producto', 'precio_actual', 'competencia', 'tienda',
                'precio_sugerido', 'delta', 'piso_margen', 'decision'])

    n = {'baja': 0, 'sube': 0, 'igual': 0, 'piso': 0, 'sin_dato': 0}
    for isbn, (pc, tienda) in sorted(comp.items()):
        prod = catalogo.get(isbn)
        if not prod:
            n['sin_dato'] += 1
            continue
        actual = int(prod['precio'] or 0)
        rebaja = min(REBAJA_MAX, max(REBAJA_MIN, round(pc * PCT)))
        sugerido = redondear(pc - rebaja)

        piso = 0
        if isbn in costos:
            piso = redondear(float(costos[isbn]) * a.usd)
        decision = ''
        if piso and sugerido < piso:
            # La competencia está bajo nuestro piso: no se sigue.
            decision = f'NO IGUALAR — quedaría bajo el piso de margen (${piso})'
            sugerido = actual
            n['piso'] += 1
        elif sugerido < actual:
            decision = 'bajar'
            n['baja'] += 1
        elif sugerido > actual:
            # Ya somos más baratos de lo necesario: se puede subir, pero es
            # una decisión comercial, no automática.
            decision = 'podríamos subir (revisar)'
            n['sube'] += 1
        else:
            decision = 'sin cambio'
            n['igual'] += 1

        w.writerow([isbn, prod['titulo'], actual, pc, tienda, sugerido,
                    sugerido - actual, piso or '', decision])

    print(f"\ncruzados {len(comp)} ISBN de la competencia · "
          f"bajar {n['baja']} · podríamos subir {n['sube']} · sin cambio {n['igual']} · "
          f"bajo el piso {n['piso']} · no están en el catálogo {n['sin_dato']}", file=sys.stderr)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
