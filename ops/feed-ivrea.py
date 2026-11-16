#!/usr/bin/env python3
"""Convierte el listado semanal de Ivrea Argentina en un feed de disponibilidad.

La señal autoritativa es el COLOR DE LA FUENTE del título en la hoja de pedidos:
la propia planilla lo dice — «ATENCIÓN: LO QUE ESTÁ EN ROJO ESTÁ AGOTADO
MOMENTÁNEAMENTE. POR FAVOR NO PEDIR AUNQUE PERMITA PONER CANTIDAD.»

  FFFF0000 rojo    → agotado (no pedir)
  FF00B050 verde   → reedición de la semana (disponible)
  FF7030A0 morado  → novedad de la semana (disponible)
  negro/sin color  → fondo editorial (disponible)

Uso: feed-ivrea.py [ruta.xlsx] > feed.json
Sin argumento toma el .xlsx de Ivrea más reciente de ~/Downloads.
"""
from __future__ import annotations
import datetime, glob, json, os, re, sys, zipfile
import xml.etree.ElementTree as ET

M = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
ESTADO = {
    'FFFF0000': 'agotado',
    'FF00B050': 'disponible',   # reedición de la semana
    'FF7030A0': 'disponible',   # novedad de la semana
    'FF000000': 'disponible',
    None: 'disponible',
}


MESES = {'ENERO': 1, 'FEBRERO': 2, 'MARZO': 3, 'ABRIL': 4, 'MAYO': 5, 'JUNIO': 6,
         'JULIO': 7, 'AGOSTO': 8, 'SEPTIEMBRE': 9, 'SETIEMBRE': 9, 'OCTUBRE': 10,
         'NOVIEMBRE': 11, 'DICIEMBRE': 12}


def fecha_lista(path: str) -> datetime.datetime:
    """Fecha real del listado, sacada del NOMBRE del archivo.

    El mtime no sirve como fecha de la lista: copiar el .xlsx (al repo, al
    despliegue de ~/Library, a un pendrive) lo pone en «hoy» y la guarda de los
    10 dias del barrido deja de proteger. El nombre que manda Ivrea sí lleva la
    fecha — «IVREA AGOSTO 2026-3ra Semana-USD PREFERENCIAL.xlsx» — así que se
    lee de ahí y se toma el LUNES de esa semana, que es lo más viejo posible.

    Se devuelve la MENOR entre esa fecha y el mtime: nunca hay que sobrestimar
    lo fresca que es una lista.
    """
    mt = datetime.datetime.fromtimestamp(os.path.getmtime(path))
    nombre = os.path.basename(path).upper()
    m_mes = re.search(r'\b(' + '|'.join(MESES) + r')\b\s*(\d{4})?', nombre)
    if not m_mes:
        return mt
    mes = MESES[m_mes.group(1)]
    anio = int(m_mes.group(2)) if m_mes.group(2) else mt.year
    m_sem = re.search(r'(\d)\s*(?:RA|DA|ER|ERA|TA|MA|VA|NA)?\.?\s*SEMANA', nombre)
    dia = 1 + 7 * (int(m_sem.group(1)) - 1) if m_sem else 1
    try:
        del_nombre = datetime.datetime(anio, mes, min(dia, 28))
    except ValueError:
        return mt
    return min(del_nombre, mt)


def col(ref: str) -> str:
    return re.match(r'[A-Z]+', ref).group()


def extraer(path: str) -> dict:
    z = ET.ElementTree  # noqa: F841  (silencia linters despistados)
    zf = zipfile.ZipFile(path)
    st = ET.fromstring(zf.read('xl/styles.xml'))
    fonts = []
    for f in st.find(M + 'fonts'):
        c = f.find(M + 'color')
        fonts.append(c.get('rgb') if c is not None else None)
    xf_font = [int(x.get('fontId', 0)) for x in st.find(M + 'cellXfs')]
    shared = [''.join(t.text or '' for t in si.iter(M + 't'))
              for si in ET.fromstring(zf.read('xl/sharedStrings.xml')).findall(M + 'si')]

    # La hoja de pedidos es la que trae ISBN por fila; se busca por contenido,
    # no por nombre, porque el título de la hoja cambia mes a mes.
    mejor, mejor_n = None, 0
    for name in zf.namelist():
        if not re.match(r'xl/worksheets/sheet\d+\.xml$', name):
            continue
        root = ET.fromstring(zf.read(name))
        n = len(re.findall(rb'97[89][\d\-]{10,}', zf.read(name)))
        if n > mejor_n:
            mejor, mejor_n = root, n
    if mejor is None:
        raise SystemExit('no se encontró una hoja con ISBN')

    items, vistos = [], set()
    for r in mejor.iter(M + 'row'):
        cells = {col(c.get('r')): c for c in r.findall(M + 'c')}

        def val(k):
            c = cells.get(k)
            if c is None:
                return None
            v = c.find(M + 'v')
            if v is None:
                return None
            return shared[int(v.text)] if c.get('t') == 's' else v.text

        titulo, isbn = val('C'), val('E')
        if not titulo or not isbn:
            continue
        isbn = re.sub(r'\D', '', str(isbn))
        if len(isbn) != 13 or isbn in vistos:
            continue
        vistos.add(isbn)
        s = int(cells['C'].get('s', 0))
        color = fonts[xf_font[s]]
        items.append({
            'isbn': isbn,
            'titulo': titulo.strip(),
            'estado': ESTADO.get(color, 'disponible'),
            'nota': (val('I') or '').strip(),
        })
    return {
        'editorial': 'Ivrea Argentina',
        'archivo': os.path.basename(path),
        'fecha': fecha_lista(path).isoformat(),
        'marca': 'Ivrea Argentina',
        'items': items,
    }


def main() -> int:
    if len(sys.argv) > 1:
        path = sys.argv[1]
    else:
        # El listado semanal se busca primero dentro del repo (visible para la
        # tarea programada) y luego en Descargas, que es donde el dueño lo baja.
        pats = ['~/Library/Application Support/akibara/listas-editoriales/IVREA*.xlsx',
                '~/Documents/akibara/ops/listas-editoriales/IVREA*.xlsx',
                '~/mnt/akibara/ops/listas-editoriales/IVREA*.xlsx',
                '~/Downloads/IVREA*.xlsx']
        # AKB_LISTAS manda. La tarea de launchd NO puede leer ~/Documents ni
        # ~/Downloads (TCC de macOS se lo niega), así que apunta a su propia
        # carpeta bajo ~/Library, que sí es legible para un LaunchAgent.
        if os.environ.get('AKB_LISTAS'):
            pats.insert(0, os.path.join(os.environ['AKB_LISTAS'], 'IVREA*.xlsx'))
        cand = []
        for pat in pats:
            cand += glob.glob(os.path.expanduser(pat))
        if not cand:
            print('no hay listado de Ivrea en listas-editoriales/ ni en ~/Downloads',
                  file=sys.stderr)
            return 1
        path = max(cand, key=os.path.getmtime)
    feed = extraer(path)
    n_ago = sum(1 for i in feed['items'] if i['estado'] == 'agotado')
    # Guarda: una planilla mal leída (0 agotados, o casi todo agotado) no sirve.
    if not feed['items'] or n_ago == 0 or n_ago > len(feed['items']) * 0.5:
        print(f"feed sospechoso: {len(feed['items'])} items, {n_ago} agotados", file=sys.stderr)
        return 2
    json.dump(feed, sys.stdout, ensure_ascii=False)
    print(f"\n-- {feed['archivo']}: {len(feed['items'])} items, {n_ago} agotados", file=sys.stderr)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
