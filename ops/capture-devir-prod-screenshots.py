#!/usr/bin/env python3
"""
ops/capture-devir-prod-screenshots.py
Captura capturas de pantalla de alta fidelidad en producción (akibara.cl) para certificar visualmente:
  1. Home (Carrusel de marcas con Devir)
  2. Marca Devir (/marca/devir/)
  3. Serie Boticaria Devir (/serie/los-diarios-de-la-boticaria-devir/)
  4. PDP Tomo 1 y Tomo 15 (/los-diarios-de-la-boticaria-1-devir/ y 15-devir)
"""

import os
from playwright.sync_api import sync_playwright

OUTPUT_DIR = "/Users/alefvaras/.gemini/antigravity/brain/45593591-4eb2-4893-b932-5409716ac763/prod_screenshots"
os.makedirs(OUTPUT_DIR, exist_ok=True)

PAGES = [
    ("01_prod_home.png", "https://akibara.cl/"),
    ("02_prod_marca_devir.png", "https://akibara.cl/marca/devir/"),
    ("03_prod_serie_boticaria.png", "https://akibara.cl/serie/los-diarios-de-la-boticaria-devir/"),
    ("04_prod_pdp_tomo1.png", "https://akibara.cl/los-diarios-de-la-boticaria-1-devir/"),
    ("05_prod_pdp_tomo15.png", "https://akibara.cl/los-diarios-de-la-boticaria-15-devir/"),
]

def main():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            viewport={"width": 1440, "height": 900},
            user_agent="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 AkibaraQA/1.0"
        )
        page = context.new_page()

        for filename, url in PAGES:
            print(f"Navegando a {url}...")
            try:
                page.goto(url, wait_until="networkidle", timeout=25000)
            except Exception as e:
                print(f"Timeout o advertencia cargando {url}: {e}, continuando...")

            page.wait_for_timeout(1000)
            out_path = os.path.join(OUTPUT_DIR, filename)
            page.screenshot(path=out_path, full_page=False)
            print(f"  [SAVED] {out_path}")

        browser.close()
    print("Capturas en producción completadas.")

if __name__ == "__main__":
    main()
