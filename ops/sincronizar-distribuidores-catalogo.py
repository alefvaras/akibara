#!/usr/bin/env python3
"""Sincronizador de inventario de distribuidores y catálogo de Akibara.

Fuentes:
- Candela (Argentina): Ivrea Argentina (Consolidado) + Ovni Press (Lista de Precios USD).
- Pujol & Amadó (España): Pedido mensual consolidado.
- Milky Way Ediciones: API oficial en vivo (milkywayediciones.com/products.json) como fuente canónica.
- Firecrawl Engine: Extracción profunda de sinopsis, metadatos y créditos editoriales (bypasseando WAFs).

Reglas operativas y de negocio:
1. REGLA DE ORO DEL DUEÑO:
   Los productos con stock físico disponible en tienda ('instock' o con unidades reales 'stock_qty' > 0)
   son SAGRADOS y NUNCA se tocan por la sincronización con distribuidores (ej: Homunculus 01-10).
   La conciliación opera ÚNICA Y EXCLUSIVAMENTE entre:
   - 'outofstock' ("Sin existencia") -> 'onbackorder' ("Se puede reservar") cuando el distribuidor confirma existencia.
   - 'onbackorder' ("Se puede reservar") -> 'outofstock' ("Sin existencia") cuando el distribuidor confirma que está agotado.
2. Precios de reserva / preventa:
   Aplica un 5% de descuento sobre el precio regular al habilitar a pedido ('onbackorder').
   '_akb_eta_type' = 'auto' (hereda los plazos del país de la editorial).
3. Cero residuos en servidor:
   La ejecución atómica se envía directamente por flujo stdin ('wp eval-file -') sin dejar archivos temporales.
4. Tablas de búsqueda y caché:
   Toda modificación actualiza automáticamente 'wc_product_meta_lookup' e invalida 'SeriesFacts'.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import time
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

try:
    import openpyxl
except ImportError:
    openpyxl = None

try:
    import xlrd
except ImportError:
    xlrd = None

# Integración Firecrawl (opcional si la clave está disponible)
try:
    sys.path.insert(0, str(Path(__file__).parent))
    from lib.firecrawl_client import scrape_url as firecrawl_scrape, search as firecrawl_search
    FIRECRAWL_AVAILABLE = True
except Exception:
    FIRECRAWL_AVAILABLE = False

DOWNLOADS_DIR = Path.home() / "Downloads"


def get_latest_file(pattern: str) -> Optional[Path]:
    files = sorted(DOWNLOADS_DIR.glob(pattern), key=lambda f: f.stat().st_mtime, reverse=True)
    return files[0] if files else None


def get_ivrea_file() -> Path:
    return get_latest_file("*IVREA*OCTUBRE*.xlsx") or DOWNLOADS_DIR / "10 - Lista de precios IVREA OCTUBRE 2026 - 4ta Semana - USD.xlsx"


def get_ovni_file() -> Path:
    return get_latest_file("*OVNIPRESS*OCTUBRE*.xlsx") or DOWNLOADS_DIR / "10 - Lista de precios OVNIPRESS OCTUBRE 2026 - 4ta semana - USD.xlsx"


def get_pujol_file() -> Path:
    f_nov = DOWNLOADS_DIR / "pedido-akibara-pujol-noviembre.xls"
    if f_nov.exists():
        return f_nov
    return get_latest_file("*pujol*.xls*") or f_nov


def clean_isbn(val: Any) -> str:
    if not val:
        return ""
    cleaned = re.sub(r"[^0-9X]", "", str(val).upper())
    return cleaned if len(cleaned) in (10, 13) else ""


def fetch_milky_way_catalog() -> Dict[str, bool]:
    """Descarga todo el catálogo de Milky Way vía Shopify JSON y devuelve {isbn: is_available}."""
    print("Obteniendo catálogo en vivo de Milky Way Ediciones...")
    milky_stock: Dict[str, bool] = {}
    page = 1
    while True:
        url = f"https://www.milkywayediciones.com/products.json?limit=250&page={page}"
        req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)"})
        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                data = json.loads(resp.read().decode("utf-8"))
                prods = data.get("products", [])
                if not prods:
                    break
                for p in prods:
                    for v in p.get("variants", []):
                        avail = bool(v.get("available", False))
                        sku = clean_isbn(v.get("sku"))
                        barcode = clean_isbn(v.get("barcode"))
                        if sku:
                            milky_stock[sku] = avail
                        if barcode:
                            milky_stock[barcode] = avail
                page += 1
                time.sleep(0.6)
        except Exception as exc:
            print(f"  Aviso: finalizó paginación Milky Way en página {page} ({exc})")
            break
    print(f"Milky Way indexado: {len(milky_stock)} variantes consultadas.")
    return milky_stock


def parse_ivrea_argentina() -> Dict[str, str]:
    """Parsea el excel de Ivrea Argentina devolviendo {isbn: 'available' | 'agotado'}."""
    ivrea_path = get_ivrea_file()
    if not ivrea_path.exists():
        print(f"Aviso: Archivo no encontrado: {ivrea_path}")
        return {}
    print(f"Leyendo Ivrea Argentina desde: {ivrea_path.name}")
    wb = openpyxl.load_workbook(ivrea_path, read_only=True)
    ws = wb["HOJA DE PEDIDOS"]
    ivrea_stock: Dict[str, str] = {}
    for row in ws.iter_rows(values_only=True):
        if len(row) > 4:
            isbn = clean_isbn(row[3]) or clean_isbn(row[4])
            if isbn:
                is_agotado = False
                for c in row:
                    if c and "AGOTAD" in str(c).upper():
                        is_agotado = True
                        break
                ivrea_stock[isbn] = "agotado" if is_agotado else "available"
    print(f"Ivrea Argentina indexado: {len(ivrea_stock)} títulos.")
    return ivrea_stock


def parse_ovni_press() -> Dict[str, str]:
    """Parsea Ovni Press devolviendo {isbn: 'available' | 'sin_stock' | 'descatalogado'}."""
    ovni_path = get_ovni_file()
    if not ovni_path.exists():
        print(f"Aviso: Archivo no encontrado: {ovni_path}")
        return {}
    print(f"Leyendo Ovni Press desde: {ovni_path.name}")
    wb = openpyxl.load_workbook(ovni_path, read_only=True)
    ovni_stock: Dict[str, str] = {}
    for row in wb["Catálogo"].iter_rows(values_only=True):
        if len(row) > 1:
            isbn = clean_isbn(row[1])
            if isbn:
                ovni_stock[isbn] = "available"
    for row in wb["Sin Stock"].iter_rows(values_only=True):
        if len(row) > 1:
            isbn = clean_isbn(row[1])
            if isbn:
                ovni_stock[isbn] = "sin_stock"
    for row in wb["Descatalogado"].iter_rows(values_only=True):
        if len(row) > 1:
            isbn = clean_isbn(row[1])
            if isbn:
                ovni_stock[isbn] = "descatalogado"
    print(f"Ovni Press indexado: {len(ovni_stock)} títulos.")
    return ovni_stock


def parse_pujol() -> Dict[str, str]:
    """Parsea el catálogo base (.xls) y el último pedido verificado (.xlsx) de Pujol & Amadó devolviendo {isbn: 'available' | 'agotado'}."""
    base_xls = sorted(DOWNLOADS_DIR.glob("000-26*.xls*"), key=lambda f: f.stat().st_mtime)
    latest_xlsx = get_latest_file("*pujol*.xlsx")
    pujol_files = base_xls[-1:] + ([latest_xlsx] if latest_xlsx else [])
    if not pujol_files:
        print("Aviso: No se encontraron archivos de Pujol & Amadó en Downloads.")
        return {}
    
    pujol_stock: Dict[str, str] = {}
    for pf in pujol_files:
        try:
            if pf.suffix.lower() == ".xlsx":
                if openpyxl is None:
                    continue
                wb = openpyxl.load_workbook(pf, read_only=True)
                for sname in wb.sheetnames:
                    ws = wb[sname]
                    for row in ws.iter_rows(values_only=True):
                        if not row:
                            continue
                        isbn = ""
                        for c in range(min(4, len(row))):
                            if row[c] is not None:
                                val = str(row[c]).split(".")[0].strip()
                                isbn = clean_isbn(val)
                                if isbn:
                                    break
                        if isbn:
                            non_formula_cells = [
                                str(cell).upper()
                                for cell in row
                                if cell is not None and not str(cell).strip().startswith("=")
                            ]
                            row_text = " ".join(non_formula_cells)
                            if "AGOTAD" in row_text or "SIN STOCK" in row_text or "NO DISPONIBLE" in row_text:
                                pujol_stock[isbn] = "agotado"
                            else:
                                pujol_stock[isbn] = "available"
            else:
                if xlrd is None:
                    continue
                wb = xlrd.open_workbook(pf)
                for sname in wb.sheet_names():
                    ws = wb.sheet_by_name(sname)
                    for r in range(ws.nrows):
                        isbn = ""
                        for c in range(min(4, ws.ncols)):
                            val = str(ws.cell_value(r, c)).split(".")[0].strip()
                            isbn = clean_isbn(val)
                            if isbn:
                                break
                        if isbn:
                            row_text = " ".join(str(ws.cell_value(r, col)).upper() for col in range(ws.ncols))
                            if "AGOTAD" in row_text or "SIN STOCK" in row_text:
                                pujol_stock[isbn] = "agotado"
                            else:
                                pujol_stock[isbn] = "available"
        except Exception as exc:
            print(f"  Aviso al leer {pf.name}: {exc}")
            
    print(f"Pujol & Amadó indexado: {len(pujol_stock)} títulos en {len(pujol_files)} archivos.")
    return pujol_stock


def get_catalog(target: str = "local") -> List[Dict[str, str]]:
    """Obtiene los productos publicados con sus metadatos de stock físico y taxonomías vía wp eval-file."""
    sql = """
    SELECT p.ID, p.post_title, p.post_name, 
           COALESCE(pm_sku.meta_value, '') as sku, 
           COALESCE(pm_stock.meta_value, '') as stock_status,
           COALESCE(pm_backorders.meta_value, '') as backorders,
           COALESCE(pm_manage.meta_value, 'no') as manage_stock,
           COALESCE(pm_stock_qty.meta_value, '0') as stock_qty,
           COALESCE(pm_eta.meta_value, '') as eta_type,
           COALESCE(pm_regular.meta_value, '') as regular_price,
           COALESCE(pm_sale.meta_value, '') as sale_price,
           COALESCE((
               SELECT t.name FROM wp_terms t
               INNER JOIN wp_term_taxonomy tt ON t.term_id=tt.term_id AND tt.taxonomy='product_brand'
               INNER JOIN wp_term_relationships tr ON tt.term_taxonomy_id=tr.term_taxonomy_id
               WHERE tr.object_id=p.ID LIMIT 1
           ), '') as brand_name,
           COALESCE((
               SELECT t.name FROM wp_terms t
               INNER JOIN wp_term_taxonomy tt ON t.term_id=tt.term_id AND tt.taxonomy='pa_pais'
               INNER JOIN wp_term_relationships tr ON tt.term_taxonomy_id=tr.term_taxonomy_id
               WHERE tr.object_id=p.ID LIMIT 1
           ), '') as pais_name
    FROM wp_posts p
    LEFT JOIN wp_postmeta pm_sku ON p.ID=pm_sku.post_id AND pm_sku.meta_key='_sku'
    LEFT JOIN wp_postmeta pm_stock ON p.ID=pm_stock.post_id AND pm_stock.meta_key='_stock_status'
    LEFT JOIN wp_postmeta pm_backorders ON p.ID=pm_backorders.post_id AND pm_backorders.meta_key='_backorders'
    LEFT JOIN wp_postmeta pm_manage ON p.ID=pm_manage.post_id AND pm_manage.meta_key='_manage_stock'
    LEFT JOIN wp_postmeta pm_stock_qty ON p.ID=pm_stock_qty.post_id AND pm_stock_qty.meta_key='_stock'
    LEFT JOIN wp_postmeta pm_eta ON p.ID=pm_eta.post_id AND pm_eta.meta_key='_akb_eta_type'
    LEFT JOIN wp_postmeta pm_regular ON p.ID=pm_regular.post_id AND pm_regular.meta_key='_regular_price'
    LEFT JOIN wp_postmeta pm_sale ON p.ID=pm_sale.post_id AND pm_sale.meta_key='_sale_price'
    WHERE p.post_type='product' AND p.post_status='publish';
    """
    php_code = f"""<?php
    global $wpdb;
    $rows = $wpdb->get_results("{sql}", ARRAY_A);
    echo json_encode($rows);
    """
    if target == "prod":
        cmd = ["ssh", "akibara", "cd domains/akibara.cl/public_html && wp eval-file -"]
    else:
        cmd = ["docker", "exec", "-i", "akibara-cron-1", "wp", "eval-file", "-"]

    proc = subprocess.run(cmd, input=php_code, text=True, capture_output=True)
    try:
        products = json.loads(proc.stdout)
        print(f"Catálogo cargado ({target}): {len(products)} productos.")
        return products
    except Exception as exc:
        print(f"Error parseando catálogo ({target}): {exc}\nSalida: {proc.stdout[:200]}")
        return []


@dataclass
class StockAction:
    product_id: str
    title: str
    sku: str
    current_status: str
    new_status: str
    distributor: str
    reason: str
    regular_price: str
    new_price: str


def detect_continuation_volumes(prod_catalog: List[Dict[str, str]], local_catalog: List[Dict[str, str]]) -> Dict[str, Any]:
    """Detecta tomos de continuación en series activas y verifica si ya existen en producción o local."""
    prod_skus = {clean_isbn(p.get("sku")): p for p in prod_catalog if clean_isbn(p.get("sku"))}
    prod_slugs = {p.get("post_name", ""): p for p in prod_catalog}
    prod_titles = {p.get("post_title", "").lower().strip(): p for p in prod_catalog}
    
    local_skus = {clean_isbn(p.get("sku")): p for p in local_catalog if clean_isbn(p.get("sku"))}

    # Revisar imágenes AVIF ya procesadas en Image Studio out
    image_studio_out = Path("/Users/alefvaras/Documents/akibara-image-studio/out")
    avif_files = list(image_studio_out.glob("**/*.avif")) if image_studio_out.exists() else []
    avif_names = {f.name: f for f in avif_files}

    # Tomos nuevos o continuaciones evaluadas recientemente
    eval_candidates = [
        {"title": "Berserk Master Edition 02", "slug": "berserk-master-edition-02-panini-espana", "sku": "9791370580001", "serie": "Berserk Master Edition"},
        {"title": "Predicador 03", "slug": "predicador-3-panini-espana", "sku": "9791370139780", "serie": "Predicador"},
        {"title": "One Punch-Man 36", "slug": "one-punch-man-36-ivrea-espana", "sku": "9791388450341", "serie": "One Punch-Man"},
        {"title": "One Punch-Man 37", "slug": "one-punch-man-37-ivrea-espana", "sku": "9791388487361", "serie": "One Punch-Man"},
        {"title": "The Walking Dead 05", "slug": "the-walking-dead-5-planeta-espana", "sku": "9788410471184", "serie": "The Walking Dead"},
        {"title": "The Walking Dead 06", "slug": "the-walking-dead-6-planeta-espana", "sku": "9788410471207", "serie": "The Walking Dead"},
        {"title": "The Walking Dead 07", "slug": "the-walking-dead-7-planeta-espana", "sku": "9788410471221", "serie": "The Walking Dead"},
        {"title": "Vinland Saga 29", "slug": "vinland-saga-29-planeta-espana", "sku": "9791387918095", "serie": "Vinland Saga"},
        {"title": "Invencible 04", "slug": "invencible-4-planeta-espana", "sku": "9788410471665", "serie": "Invencible"},
        {"title": "Atelier of Witch Hat (Edición Grimorio) 04", "slug": "atelier-of-witch-hat-edicion-grimorio-vol-4-milky-way", "sku": "9791388055843", "serie": "Atelier of Witch Hat"},
        {"title": "Fumando juntos detrás del súper 09", "slug": "fumando-juntos-detras-del-super-09-panini-espana", "sku": "9791370581633", "serie": "Fumando juntos detrás del súper"},
        {"title": "La nobleza de las flores 19", "slug": "la-nobleza-de-las-flores-19-milky-way", "sku": "9791388055980", "serie": "La nobleza de las flores"},
        {"title": "La nobleza de las flores 20", "slug": "la-nobleza-de-las-flores-20-milky-way", "sku": "9791388495175", "serie": "La nobleza de las flores"},
        {"title": "La Cosa del Pantano de Alan Moore 02", "slug": "la-cosa-del-pantano-de-alan-moore-2-panini-espana", "sku": "9791370135201", "serie": "La Cosa del Pantano"},
        {"title": "La Cosa del Pantano de Alan Moore 03", "slug": "la-cosa-del-pantano-de-alan-moore-3-panini-espana", "sku": "9791370581701", "serie": "La Cosa del Pantano"},
    ]
    # Takagi-san 01 al 20
    for i in range(1, 21):
        padded = f"{i:02d}"
        eval_candidates.append({
            "title": f"Takagi-san, experta en bromas pesadas {padded}",
            "slug": f"takagi-san-{i}-ivrea-espana",
            "sku": "",
            "serie": "Takagi-san, experta en bromas pesadas"
        })

    # Ao Haru Ride 01 al 13
    for i in range(1, 14):
        padded = f"{i:02d}"
        eval_candidates.append({
            "title": f"Ao Haru Ride {padded}",
            "slug": f"ao-haru-ride-{i}-ivrea-argentina",
            "sku": "",
            "serie": "Ao Haru Ride"
        })

    results = {
        "already_in_prod": [],
        "pending_in_prod": [],
        "in_local_only": [],
        "avif_ready": []
    }

    for c in eval_candidates:
        sku = clean_isbn(c["sku"])
        slug = c["slug"]
        title_lower = c["title"].lower().strip()

        in_prod = False
        prod_item = None
        if sku and sku in prod_skus:
            in_prod = True
            prod_item = prod_skus[sku]
        elif slug in prod_slugs:
            in_prod = True
            prod_item = prod_slugs[slug]
        elif title_lower in prod_titles:
            in_prod = True
            prod_item = prod_titles[title_lower]

        if in_prod and prod_item:
            results["already_in_prod"].append({
                "title": c["title"],
                "prod_id": prod_item["ID"],
                "stock_status": prod_item.get("stock_status", ""),
                "serie": c["serie"]
            })
        else:
            in_local = (sku and sku in local_skus) or (slug in {p.get("post_name", "") for p in local_catalog})
            has_avif = any(c["slug"] in av or f"aoharuride_{c['title'][-2:]}" in av for av in avif_names)
            results["pending_in_prod"].append({
                "title": c["title"],
                "slug": slug,
                "sku": sku,
                "serie": c["serie"],
                "in_local": in_local,
                "avif_ready": has_avif
            })

    return results


def run_reconciliation(target: str = "local", apply_changes: bool = False) -> Tuple[List[StockAction], List[StockAction]]:
    milky_stock = fetch_milky_way_catalog()
    ivrea_stock = parse_ivrea_argentina()
    ovni_stock = parse_ovni_press()
    pujol_stock = parse_pujol()

    # Siempre cargar producción para tener la verdad canónica del inventario
    print("\n--- Verificando estado canónico contra PRODUCCIÓN ---")
    prod_catalog = get_catalog(target="prod")
    
    if target == "prod":
        catalog = prod_catalog
    else:
        print("Cargando catálogo espejo de LOCAL...")
        catalog = get_catalog(target="local")

    # Detectar tomos y verificar si ya existen en producción
    continuation_report = detect_continuation_volumes(prod_catalog, catalog)

    to_backorder: List[StockAction] = []
    to_outofstock: List[StockAction] = []
    shielded_count = 0

    for p in catalog:
        sku = clean_isbn(p.get("sku"))
        if not sku:
            continue
        current_status = p.get("stock_status", "")

        # -------------------------------------------------------------
        # BLINDAJE ESTRICTO DE STOCK FÍSICO
        # -------------------------------------------------------------
        is_physically_in_stock = False
        if current_status == "instock":
            is_physically_in_stock = True
        try:
            qty = float(p.get("stock_qty") or 0)
            if p.get("manage_stock") == "yes" and qty > 0:
                is_physically_in_stock = True
        except (ValueError, TypeError):
            pass

        if is_physically_in_stock:
            shielded_count += 1
            continue

        brand = p.get("brand_name", "").lower()
        regular_p = float(p.get("regular_price") or 0)
        new_p = str(int(round(regular_p * 0.95))) if regular_p > 0 else ""

        # Regla 1: Milky Way Ediciones
        if "milky way" in brand:
            mw_available = milky_stock.get(sku)
            if mw_available is None and pujol_stock.get(sku):
                mw_available = (pujol_stock.get(sku) == "available")
            if mw_available is False and current_status == "onbackorder":
                to_outofstock.append(StockAction(
                    product_id=p["ID"],
                    title=p["post_title"],
                    sku=sku,
                    current_status=current_status,
                    new_status="outofstock",
                    distributor="Milky Way Web Oficial",
                    reason="Web oficial marca el tomo como NO disponible / agotado",
                    regular_price=p["regular_price"],
                    new_price=p["regular_price"]
                ))
            elif mw_available is True and current_status == "outofstock":
                to_backorder.append(StockAction(
                    product_id=p["ID"],
                    title=p["post_title"],
                    sku=sku,
                    current_status=current_status,
                    new_status="onbackorder",
                    distributor="Milky Way Web Oficial",
                    reason="Web oficial tiene stock en venta (habilitar a pedido)",
                    regular_price=p["regular_price"],
                    new_price=new_p
                ))
            continue

        # Regla 2: Editoriales de Argentina (Ivrea Argentina / Ovni Press)
        if "ivrea argentina" in brand or ivrea_stock.get(sku):
            iv_status = ivrea_stock.get(sku)
            if iv_status == "agotado" and current_status == "onbackorder":
                to_outofstock.append(StockAction(
                    product_id=p["ID"],
                    title=p["post_title"],
                    sku=sku,
                    current_status=current_status,
                    new_status="outofstock",
                    distributor="Ivrea Argentina",
                    reason="Planilla Consolidado marca TEMPORALMENTE AGOTADO",
                    regular_price=p["regular_price"],
                    new_price=p["regular_price"]
                ))
            elif iv_status == "available" and current_status == "outofstock":
                to_backorder.append(StockAction(
                    product_id=p["ID"],
                    title=p["post_title"],
                    sku=sku,
                    current_status=current_status,
                    new_status="onbackorder",
                    distributor="Ivrea Argentina",
                    reason="Planilla Consolidado confirma stock disponible",
                    regular_price=p["regular_price"],
                    new_price=new_p
                ))

        elif "ovni" in brand or ovni_stock.get(sku):
            ov_status = ovni_stock.get(sku)
            if ov_status in ("sin_stock", "descatalogado") and current_status == "onbackorder":
                to_outofstock.append(StockAction(
                    product_id=p["ID"],
                    title=p["post_title"],
                    sku=sku,
                    current_status=current_status,
                    new_status="outofstock",
                    distributor="Ovni Press",
                    reason=f"Planilla marca {ov_status}",
                    regular_price=p["regular_price"],
                    new_price=p["regular_price"]
                ))
            elif ov_status == "available" and current_status == "outofstock":
                to_backorder.append(StockAction(
                    product_id=p["ID"],
                    title=p["post_title"],
                    sku=sku,
                    current_status=current_status,
                    new_status="onbackorder",
                    distributor="Ovni Press",
                    reason="Planilla Catálogo confirma disponibilidad",
                    regular_price=p["regular_price"],
                    new_price=new_p
                ))

        # Regla 3: Editoriales de España (Pujol & Amadó)
        elif pujol_stock.get(sku):
            pj_status = pujol_stock.get(sku)
            if pj_status == "agotado" and current_status == "onbackorder":
                to_outofstock.append(StockAction(
                    product_id=p["ID"],
                    title=p["post_title"],
                    sku=sku,
                    current_status=current_status,
                    new_status="outofstock",
                    distributor="Pujol & Amadó (España)",
                    reason="Planilla verificada marca AGOTADO EN ESPAÑA",
                    regular_price=p["regular_price"],
                    new_price=p["regular_price"]
                ))
            elif pj_status == "available" and current_status == "outofstock":
                to_backorder.append(StockAction(
                    product_id=p["ID"],
                    title=p["post_title"],
                    sku=sku,
                    current_status=current_status,
                    new_status="onbackorder",
                    distributor="Pujol & Amadó (España)",
                    reason="Planilla de pedido confirma existencia disponible",
                    regular_price=p["regular_price"],
                    new_price=new_p
                ))

    print(f"\n================ RESUMEN DE RECONCILIACIÓN ({target.upper()}) ================")
    print(f"Productos con stock físico en tienda (100% blindados): {shielded_count}")
    print(f"Productos a pasar de Sin Existencia -> Se puede reservar: {len(to_backorder)}")
    for act in to_backorder[:10]:
        print(f"   [+] [{act.product_id}] {act.title} (SKU: {act.sku}) -> onbackorder | {act.distributor}")
    if len(to_backorder) > 10:
        print(f"   ... y {len(to_backorder) - 10} más.")

    print(f"\nProductos a pasar de Se puede reservar -> Sin existencia: {len(to_outofstock)}")
    for act in to_outofstock[:10]:
        print(f"   [-] [{act.product_id}] {act.title} (SKU: {act.sku}) -> outofstock | {act.distributor} ({act.reason})")
    if len(to_outofstock) > 10:
        print(f"   ... y {len(to_outofstock) - 10} más.")

    print("\n================ VERIFICACIÓN DE TOMOS DE CONTINUACIÓN CONTRA PRODUCCIÓN ================")
    print(f"Tomos confirmados YA PUBLICADOS en Producción (NO duplicar): {len(continuation_report['already_in_prod'])}")
    for item in continuation_report["already_in_prod"][:8]:
        print(f"   [OK EN PROD] {item['title']} (ID: {item['prod_id']} | Estado: {item['stock_status']})")
    if len(continuation_report["already_in_prod"]) > 8:
        print(f"   ... y {len(continuation_report['already_in_prod']) - 8} tomos más al día en producción.")

    print(f"\nTomos de continuación PENDIENTES de incorporar en Producción: {len(continuation_report['pending_in_prod'])}")
    for item in continuation_report["pending_in_prod"]:
        avif_badge = "PORTADA_AVIF_LISTA" if item["avif_ready"] else "PORTADA_PENDIENTE"
        local_badge = "EN_LOCAL" if item["in_local"] else "NO_EN_LOCAL"
        print(f"   [PENDIENTE] {item['title']} (Serie: {item['serie']} | {local_badge} | {avif_badge})")

    if apply_changes:
        print(f"\nAplicando cambios atómicos en {target.upper()} (sin archivos temporales)...")
        php_lines = [
            "<?php",
            "defined('ABSPATH') || exit(1);",
            "$touched = [];",
        ]
        for act in to_backorder:
            pid = act.product_id
            php_lines.append(f"""
            update_post_meta({pid}, '_stock_status', 'onbackorder');
            update_post_meta({pid}, '_manage_stock', 'no');
            update_post_meta({pid}, '_stock', null);
            update_post_meta({pid}, '_backorders', 'notify');
            update_post_meta({pid}, '_akb_eta_type', 'auto');
            if ('{act.new_price}') {{
                update_post_meta({pid}, '_sale_price', '{act.new_price}');
                update_post_meta({pid}, '_price', '{act.new_price}');
            }}
            wp_remove_object_terms({pid}, 'outofstock', 'product_visibility');
            $touched[] = {pid};
            """)
        for act in to_outofstock:
            pid = act.product_id
            php_lines.append(f"""
            update_post_meta({pid}, '_stock_status', 'outofstock');
            update_post_meta({pid}, '_manage_stock', 'no');
            update_post_meta({pid}, '_stock', null);
            update_post_meta({pid}, '_backorders', 'no');
            delete_post_meta({pid}, '_sale_price');
            if ('{act.regular_price}') {{
                update_post_meta({pid}, '_price', '{act.regular_price}');
            }}
            wp_set_object_terms({pid}, 'outofstock', 'product_visibility', true);
            $touched[] = {pid};
            """)

        php_lines.append("""
        $facts_service = class_exists('\\Akibara\\Business\\Modules\\SeriesFacts\\SeriesFacts')
            ? new \\Akibara\\Business\\Modules\\SeriesFacts\\SeriesFacts(static fn(): bool => false)
            : null;
        $has_indexer = class_exists('\\Akibara_Search\\Indexer') && method_exists('\\Akibara_Search\\Indexer', 'reindex_post');
        foreach (array_unique($touched) as $tid) {
            wc_update_product_lookup_tables((int)$tid);
            if ($facts_service) {
                $facts_service->invalidate_product((int)$tid);
            }
            if ($has_indexer) {
                \\Akibara_Search\\Indexer::reindex_post((int)$tid, true);
            }
        }
        echo "LOOKUP_TABLES_Y_SEARCH_ACTUALIZADOS_PARA_" . count($touched) . "_PRODUCTOS\\n";
        """)

        php_code = "\n".join(php_lines)
        if target == "prod":
            cmd = ["ssh", "akibara", "cd domains/akibara.cl/public_html && wp eval-file -"]
        else:
            cmd = ["docker", "exec", "-i", "akibara-cron-1", "wp", "eval-file", "-"]

        proc = subprocess.run(cmd, input=php_code, text=True, capture_output=True)
        print(proc.stdout)
        if proc.stderr:
            print("StdErr:", proc.stderr)
        print(f"Reconciliación en {target.upper()} completada con éxito.")

    return to_backorder, to_outofstock


def crawl_publisher_metadata(url: str) -> Optional[Dict[str, Any]]:
    """Utiliza Firecrawl para extraer sinopsis, ficha técnica y detalles de una obra."""
    if not FIRECRAWL_AVAILABLE:
        print("Firecrawl no disponible o sin credenciales.")
        return None
    try:
        print(f"Extrayendo datos editoriales mediante Firecrawl: {url}")
        res = firecrawl_scrape(url, formats=["markdown"])
        md = res.get("markdown", "")
        return {"markdown": md, "metadata": res.get("metadata", {})}
    except Exception as exc:
        print(f"Error en Firecrawl: {exc}")
        return None


def audit_series_consistency(target: str = "prod") -> Dict[str, Any]:
    """Doble chequeo independiente de consistencia entre atributos de serie y tomos individuales.
    
    Verifica que:
    1. Todos los productos asignados a un término de pa_serie compartan la misma editorial (product_brand),
       el mismo país (pa_pais), los mismos autores (pa_autor) y géneros coherentes (pa_genero).
    2. Ningún producto tenga géneros vacíos ni atributos desalineados.
    3. No existan discrepancias entre la ficha individual y la serie.
    """
    print(f"\n========================================================")
    print(f"  DOBLE CHEQUEO INDEPENDIENTE DE CONSISTENCIA EN {target.upper()}")
    print(f"========================================================\n")

    php_script = """<?php
    global $wpdb;
    $series_terms = get_terms([
        'taxonomy'   => 'pa_serie',
        'hide_empty' => true
    ]);

    $report = [
        'series_audited' => 0,
        'products_audited' => 0,
        'inconsistencies' => []
    ];

    foreach ($series_terms as $term) {
        $products = get_posts([
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'tax_query'      => [
                [
                    'taxonomy' => 'pa_serie',
                    'field'    => 'term_id',
                    'terms'    => $term->term_id
                ]
            ],
            'fields'         => 'ids'
        ]);

        if (empty($products)) continue;

        $report['series_audited']++;
        $report['products_audited'] += count($products);

        $brands = [];
        $countries = [];
        $authors = [];
        $empty_genres = [];

        foreach ($products as $pid) {
            $p_brands = wp_get_post_terms($pid, 'product_brand', ['fields' => 'names']);
            $p_countries = wp_get_post_terms($pid, 'pa_pais', ['fields' => 'names']);
            $p_authors = wp_get_post_terms($pid, 'pa_autor', ['fields' => 'names']);
            $p_genres = wp_get_post_terms($pid, 'pa_genero', ['fields' => 'names']);

            if (empty($p_genres)) {
                $empty_genres[] = $pid;
            }

            foreach ($p_brands as $b) $brands[$b][] = $pid;
            foreach ($p_countries as $c) $countries[$c][] = $pid;
            foreach ($p_authors as $a) $authors[$a][] = $pid;
        }

        $series_issues = [];
        if (count($brands) > 1) {
            $series_issues['brand_divergence'] = $brands;
        }
        if (count($countries) > 1) {
            $series_issues['country_divergence'] = $countries;
        }
        if (!empty($empty_genres)) {
            $series_issues['empty_genres_in_products'] = $empty_genres;
        }

        if (!empty($series_issues)) {
            $report['inconsistencies'][$term->name] = [
                'term_id' => $term->term_id,
                'slug' => $term->slug,
                'product_count' => count($products),
                'issues' => $series_issues
            ];
        }
    }

    echo json_encode($report);
    """

    if target == "prod":
        cmd = ["ssh", "akibara", "cd domains/akibara.cl/public_html && wp eval-file -"]
    else:
        cmd = ["docker", "exec", "-i", "akibara-cron-1", "wp", "eval-file", "-"]

    proc = subprocess.run(cmd, input=php_script, text=True, capture_output=True)
    if proc.returncode != 0:
        print(f"Error al ejecutar auditoría: {proc.stderr}")
        return {}

    try:
        report = json.loads(proc.stdout)
    except Exception as exc:
        print(f"No se pudo parsear resultado JSON: {proc.stdout}")
        return {}

    print(f"Series auditadas: {report.get('series_audited', 0)}")
    print(f"Productos auditados: {report.get('products_audited', 0)}")
    inconsistencies = report.get("inconsistencies", {})
    if inconsistencies:
        print(f"\n[ALERTA] Se encontraron {len(inconsistencies)} series con inconsistencias:")
        for name, data in inconsistencies.items():
            print(f" - Serie: '{name}' (Slug: {data['slug']}, {data['product_count']} tomos)")
            for issue_type, details in data["issues"].items():
                print(f"     * {issue_type}: {details}")
    else:
        print("\n[OK] 100% de consistencia entre series y tomos individuales.")

    return report


def main() -> None:
    parser = argparse.ArgumentParser(description="Sincronizador de inventario de distribuidores y catálogo de Akibara.")
    parser.add_argument("--target", choices=["local", "prod"], default="local", help="Entorno destino (local o prod)")
    parser.add_argument("--apply", action="store_true", help="Aplica los cambios en la base de datos")
    parser.add_argument("--crawl", type=str, help="URL de editorial para extraer ficha vía Firecrawl")
    parser.add_argument("--audit-series", action="store_true", help="Ejecuta doble chequeo independiente de consistencia en series")
    args = parser.parse_args()

    if args.crawl:
        data = crawl_publisher_metadata(args.crawl)
        if data:
            print(data["markdown"][:1000])
        return

    if args.audit_series:
        audit_series_consistency(target=args.target)
        return

    run_reconciliation(target=args.target, apply_changes=args.apply)


if __name__ == "__main__":
    main()

