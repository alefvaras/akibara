#!/usr/bin/env python3
"""
ops/capture-focused-prod-evidence.py
Capturas visuales enfocadas de alta resolución en producción (akibara.cl)
"""

import os
from playwright.sync_api import sync_playwright

OUTPUT_DIR = "/Users/alefvaras/.gemini/antigravity/brain/45593591-4eb2-4893-b932-5409716ac763/visual_evidence_prod"
os.makedirs(OUTPUT_DIR, exist_ok=True)

def main():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            viewport={"width": 1440, "height": 900},
            device_scale_factor=2, # Retina HD
            user_agent="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 AkibaraQA/1.0"
        )
        page = context.new_page()

        # 1. Home - Carrusel de marcas enfocado en Devir
        print("1. Capturando carrusel de marcas en Home...")
        try:
            page.goto("https://akibara.cl/", wait_until="domcontentloaded", timeout=25000)
            page.wait_for_timeout(1500)
            carousel = page.locator(".akb-brands-home, section.brands-carousel, .akb-brands-section").first
            if carousel.is_visible():
                carousel.scroll_into_view_if_needed()
                page.wait_for_timeout(500)
                carousel.screenshot(path=os.path.join(OUTPUT_DIR, "01_home_marcas_carrusel.png"))
            else:
                # Fallback: capturar el viewport con scroll
                page.evaluate("window.scrollBy(0, 1200)")
                page.wait_for_timeout(500)
                page.screenshot(path=os.path.join(OUTPUT_DIR, "01_home_marcas_carrusel.png"))
        except Exception as e:
            print("Error en home:", e)

        # 2. Catálogo de Marca Devir (Cabecera + primeras 4 tarjetas con badges)
        print("2. Capturando marca Devir...")
        try:
            page.goto("https://akibara.cl/marca/devir/", wait_until="domcontentloaded", timeout=25000)
            page.wait_for_timeout(1000)
            page.screenshot(path=os.path.join(OUTPUT_DIR, "02_marca_devir_catalogo.png"), clip={"x": 0, "y": 140, "width": 1440, "height": 760})
        except Exception as e:
            print("Error en marca:", e)

        # 3. Serie Boticaria Devir (Hero, 'También en:', ficha técnica estructurada)
        print("3. Capturando serie Boticaria Devir...")
        try:
            page.goto("https://akibara.cl/serie/los-diarios-de-la-boticaria-devir/", wait_until="domcontentloaded", timeout=25000)
            page.wait_for_timeout(1000)
            page.screenshot(path=os.path.join(OUTPUT_DIR, "03_serie_boticaria_devir.png"), clip={"x": 0, "y": 140, "width": 1440, "height": 760})
        except Exception as e:
            print("Error en serie:", e)

        # 4. Ficha de Producto (PDP) Tomo 01 - Portada HD, Precio reserva, CTA y Facts
        print("4. Capturando PDP Tomo 01...")
        try:
            page.goto("https://akibara.cl/los-diarios-de-la-boticaria-1-devir/", wait_until="domcontentloaded", timeout=25000)
            page.wait_for_timeout(1000)
            page.screenshot(path=os.path.join(OUTPUT_DIR, "04_pdp_tomo01_hero.png"), clip={"x": 0, "y": 140, "width": 1440, "height": 760})
        except Exception as e:
            print("Error en PDP Tomo 01:", e)

        # 5. Ficha de Producto (PDP) Tomo 15 - Comparador de Ediciones cruzadas en vivo
        print("5. Capturando Comparador de Ediciones en Tomo 15...")
        try:
            page.goto("https://akibara.cl/los-diarios-de-la-boticaria-15-devir/", wait_until="domcontentloaded", timeout=25000)
            page.wait_for_timeout(1000)
            editions_box = page.locator(".akb-series-editions-card, .akb-pdp-other-editions, [data-akb-component='series-editions']").first
            if editions_box.is_visible():
                editions_box.scroll_into_view_if_needed()
                page.wait_for_timeout(500)
                editions_box.screenshot(path=os.path.join(OUTPUT_DIR, "05_pdp_tomo15_comparador_ediciones.png"))
            else:
                page.evaluate("window.scrollBy(0, 750)")
                page.wait_for_timeout(500)
                page.screenshot(path=os.path.join(OUTPUT_DIR, "05_pdp_tomo15_comparador_ediciones.png"), clip={"x": 0, "y": 300, "width": 1440, "height": 600})
        except Exception as e:
            print("Error en PDP Tomo 15 ediciones:", e)

        # 6. Ficha de Producto (PDP) Tomo 15 - Especificaciones estructuradas y pestaña de Información Adicional
        print("6. Capturando Specs Grid en Tomo 15...")
        try:
            page.evaluate("window.scrollBy(0, 600)")
            page.wait_for_timeout(500)
            # click en pestaña Especificaciones si existe
            specs_tab = page.locator("#tab-title-additional_information a, a[href='#tab-additional_information']").first
            if specs_tab.is_visible():
                specs_tab.click()
                page.wait_for_timeout(400)
            page.screenshot(path=os.path.join(OUTPUT_DIR, "06_pdp_tomo15_specs_tabs.png"), clip={"x": 0, "y": 300, "width": 1440, "height": 600})
        except Exception as e:
            print("Error en specs tabs:", e)

        browser.close()
    print("Todas las evidencias visuales capturadas.")

if __name__ == "__main__":
    main()
