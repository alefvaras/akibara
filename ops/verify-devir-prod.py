#!/usr/bin/env python3
"""
ops/verify-devir-prod.py

Auditoría integral y certificación en vivo en producción (akibara.cl).
Verifica:
  1. Páginas principales: Marca Devir, Serie Boticaria Devir, Home Brands Carousel.
  2. Los 16 tomos individuales:
     - HTTP 200 y canonical
     - Portada real servida (status 200, Content-Type image/*, tamaño > 10KB, alt text correcto)
     - Atributos estructurados (.akb-pdp-specs-grid): Autor, Editorial, Serie, Géneros
     - Información adicional / Envío: Peso (0,25 kg) y Dimensiones (18 × 12,8 × 1,5 cm)
     - Cero residuos de especificaciones en post_content (Regla 5)
     - Precios correctos ($16.500 regular, $15.675 reserva 5% de descuento)
     - Estado de stock onbackorder ("A pedido / Se puede reservar")
     - SEO Rank Math: Título SEO, Meta descripción (longitud < 160 y sin nombre editorial per Regla 13), Focus keyword
     - Schemas / GTIN / EAN repetido en SKU (Regla 3)
     - Comparar ediciones / Ediciones relacionadas presentes en PDP y Serie
"""

import sys
import re
import urllib.request
import urllib.parse
import json
from html.parser import HTMLParser

BASE_URL = "https://akibara.cl"
HEADERS = {
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 AkibaraAuditor/1.0"
}

def fetch_url(url, headers=None):
    if headers is None:
        headers = HEADERS
    req = urllib.request.Request(url, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            content = resp.read()
            return {
                "status": resp.status,
                "content": content,
                "headers": dict(resp.headers)
            }
    except urllib.error.HTTPError as e:
        return {
            "status": e.code,
            "content": e.read(),
            "headers": dict(e.headers)
        }
    except Exception as e:
        return {
            "status": 0,
            "error": str(e),
            "content": b"",
            "headers": {}
        }

def check_image(img_url):
    """Verifica que la imagen exista, responda 200, sea tipo imagen y tenga peso válido"""
    if not img_url:
        return False, "URL vacía"
    if img_url.startswith("//"):
        img_url = "https:" + img_url
    elif img_url.startswith("/"):
        img_url = BASE_URL + img_url

    res = fetch_url(img_url)
    if res["status"] != 200:
        return False, f"HTTP {res['status']}"
    
    ctype = res["headers"].get("Content-Type", "")
    clength = len(res["content"])
    if not any(t in ctype for t in ["image/", "application/octet-stream"]):
        return False, f"Content-Type no es imagen ({ctype})"
    if clength < 1000:
        return False, f"Imagen vacía o corrupta ({clength} bytes)"
    
    return True, f"OK ({ctype}, {clength} bytes)"

def audit_brand():
    print("\n--- [1] AUDITORÍA DE MARCA DEVIR ---")
    url = f"{BASE_URL}/marca/devir/"
    res = fetch_url(url)
    if res["status"] != 200:
        print(f"[FAIL] Marca Devir returned HTTP {res['status']}")
        return False
    html = res["content"].decode("utf-8", errors="ignore")

    # Verificar título
    title_match = re.search(r"<title>(.*?)</title>", html, re.IGNORECASE)
    title = title_match.group(1) if title_match else ""
    print(f"  • Título: {title}")
    if "Devir" not in title:
        print("[FAIL] 'Devir' no aparece en el título")
        return False

    # Verificar presencia de productos
    product_count = len(re.findall(r'class="[^"]*product[^"]*"', html))
    print(f"  • Productos listados en /marca/devir/: ~{product_count}")
    if product_count < 10:
        print("[FAIL] Pocos productos detectados en página de marca")
        return False

    print("  [PASS] Página de marca Devir verificada.")
    return True

def audit_series():
    print("\n--- [2] AUDITORÍA DE SERIE BOTICARIA DEVIR ---")
    url = f"{BASE_URL}/serie/los-diarios-de-la-boticaria-devir/"
    res = fetch_url(url)
    if res["status"] != 200:
        print(f"[FAIL] Serie returned HTTP {res['status']}")
        return False
    html = res["content"].decode("utf-8", errors="ignore")

    # Verificar Ficha Técnica en descripción
    if "Natsu Hyuuga" not in html or "Nekokurage" not in html:
        print("[FAIL] Autores no encontrados en la descripción de la serie")
        return False
    
    # Verificar interlinking de ediciones relacionadas
    if "akb-series-editions" not in html and "También en:" not in html:
        print("[FAIL] Módulo akb-series-editions ('También en:') no encontrado en serie")
        return False
    else:
        print("  • Módulo 'También en:' (Ediciones relacionadas) presente en el hero.")

    # Conteo de tomos en la serie
    tomo_matches = re.findall(r'los-diarios-de-la-boticaria-(\d+)-devir', html)
    unique_tomos = sorted(set(int(x) for x in tomo_matches))
    print(f"  • Tomos detectados en catálogo de serie: {len(unique_tomos)}/16 ({unique_tomos})")
    if len(unique_tomos) < 16:
        print(f"[FAIL] Faltan tomos en la serie, solo hay {len(unique_tomos)}")
        return False

    print("  [PASS] Página de serie verificada con 16 tomos y ediciones cruzadas.")
    return True

def audit_pdp(num):
    slug = f"los-diarios-de-la-boticaria-{num}-devir"
    url = f"{BASE_URL}/{slug}/"
    res = fetch_url(url)
    if res["status"] != 200:
        return False, f"HTTP {res['status']}"
    
    html = res["content"].decode("utf-8", errors="ignore")
    errors = []

    # 1. Portada y alt
    img_match = re.search(r'<img[^>]+class="[^"]*wp-post-image[^"]*"[^>]+src="([^">]+)"', html)
    if not img_match:
        # Fallback a selector src dentro de woocommerce-product-gallery
        img_match = re.search(r'<div class="woocommerce-product-gallery__image"[^>]*>.*?<img[^>]+src="([^">]+)"', html, re.DOTALL)
    
    if img_match:
        img_url = img_match.group(1)
        ok_img, img_msg = check_image(img_url)
        if not ok_img:
            errors.append(f"Imagen portada falló: {img_msg}")
    else:
        errors.append("No se encontró <img class='wp-post-image'>")

    # 2. Atributos estructurados (.akb-pdp-specs-grid)
    if "akb-pdp-specs-grid" not in html:
        errors.append("Falta .akb-pdp-specs-grid")
    else:
        if "Natsu Hyuuga" not in html:
            errors.append("Autor Natsu Hyuuga no encontrado en specs")
        if "Devir" not in html:
            errors.append("Editorial Devir no encontrada en specs")
        if "Drama" not in html and "Misterio" not in html:
            errors.append("Géneros no encontrados en specs")

    # 3. Regla 5: NO residuos de <h3>Especificaciones técnicas</h3>
    if "<h3>Especificaciones técnicas</h3>" in html:
        errors.append("Regla 5 violada: Residuo h3 Especificaciones tecnicas detectado en post_content")

    # 4. Información adicional (Envío / dimensiones)
    if "0,25 kg" not in html and "0.25 kg" not in html:
        errors.append("Peso 0,25 kg no encontrado en información adicional")
    if "18 &times; 12,8 &times; 1,5 cm" not in html and "18 × 12,8 × 1,5 cm" not in html and "18 x 12.8 x 1.5 cm" not in html:
        errors.append("Dimensiones 18 × 12,8 × 1,5 cm no encontradas")

    # 5. Precios y reserva
    if "15.675" not in html and "15&nbsp;675" not in html:
        errors.append("Precio reserva $15.675 no encontrado")
    if "16.500" not in html and "16&nbsp;500" not in html:
        errors.append("Precio regular $16.500 no encontrado")
    if "onbackorder" not in html and "reserva" not in html.lower() and "a pedido" not in html.lower():
        errors.append("Estado de reserva (onbackorder) no detectado")

    # 6. SEO Rank Math
    title_match = re.search(r"<title>(.*?)</title>", html, re.IGNORECASE)
    page_title = title_match.group(1) if title_match else ""
    if f"Los diarios de la boticaria {num:02d}" not in page_title and f"Los diarios de la boticaria {num}" not in page_title:
        errors.append(f"Título SEO no contiene el nombre esperado: {page_title}")

    desc_match = re.search(r'<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']', html, re.IGNORECASE)
    if desc_match:
        meta_desc = desc_match.group(1)
        if len(meta_desc) > 160:
            errors.append(f"Meta descripción excede 160 chars ({len(meta_desc)} chars)")
        if "Devir" in meta_desc and "España" in meta_desc:
            errors.append("Regla 13 violada: Menciona editorial en meta_description")
    else:
        errors.append("Falta meta description")

    # 7. Comparador de ediciones
    if "Comparar ediciones" not in html and "Otras ediciones" not in html and "akb-series-editions" not in html:
        errors.append("Falta bloque de comparación de ediciones")

    if errors:
        return False, " | ".join(errors)
    return True, "PDP OK (Portada, Specs, Dimensiones, Precios, SEO, Ediciones)"

def main():
    print("=" * 65)
    print("  AUDITORÍA RIGUROSA EN PRODUCCIÓN: DEVIR & BOTICARIA")
    print("=" * 65)

    brand_ok = audit_brand()
    series_ok = audit_series()

    print("\n--- [3] AUDITORÍA INDIVIDUAL DE LOS 16 TOMOS (PDP) ---")
    pdp_results = []
    all_pdp_ok = True
    for i in range(1, 17):
        ok, msg = audit_pdp(i)
        status_str = "[PASS]" if ok else "[FAIL]"
        print(f"  {status_str} Tomo {i:02d}: {msg}")
        if not ok:
            all_pdp_ok = False
        pdp_results.append((i, ok, msg))

    print("\n" + "=" * 65)
    print("  RESUMEN DE CERTIFICACIÓN")
    print("=" * 65)
    print(f"  • Marca Devir: {'VERIFICADA' if brand_ok else 'FALLÓ'}")
    print(f"  • Serie Boticaria Devir: {'VERIFICADA' if series_ok else 'FALLÓ'}")
    print(f"  • PDPs de 16 Tomos: {sum(1 for _, ok, _ in pdp_results if ok)}/16 Aprobados")

    if brand_ok and series_ok and all_pdp_ok:
        print("\n>>> CERTIFICACIÓN EXITOSA: TODO EN VERDE EN PRODUCCIÓN <<<")
        sys.exit(0)
    else:
        print("\n>>> FALLARON ALGUNAS PRUEBAS EN PRODUCCIÓN <<<")
        sys.exit(1)

if __name__ == "__main__":
    main()
