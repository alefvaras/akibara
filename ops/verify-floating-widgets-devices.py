#!/usr/bin/env python3.14
"""
verify-floating-widgets-devices.py — geometría de los flotantes en dispositivos reales.

Mide, por dispositivo, que los tres flotantes de la tienda no se solapen entre sí
ni tapen la navegación inferior:

  1. `.bottom-nav` del theme (móvil/tablet, 66px de alto).
  2. `.akb-sticky-atc` (solo ficha de producto).
  3. El widget del Brand Agent («Akibara AI»), que vive en el shadow DOM abierto
     de `#ads-agent-host` y se eleva con `inc/brand-agent.php`.
  4. El botón de Joinchat (WhatsApp), elevado por el CSS adicional del Customizer.

Por qué Playwright y no emular a mano el viewport: los perfiles de dispositivo traen
el user-agent, el device scale factor y `has_touch` reales. El widget es de terceros
y podría decidir su layout por UA y no solo por media queries — con un viewport
redimensionado a mano eso no se detecta.

Uso:
    python3.14 ops/verify-floating-widgets-devices.py
    python3.14 ops/verify-floating-widgets-devices.py --shots /ruta/salida
    python3.14 ops/verify-floating-widgets-devices.py --open-chat

Salida: una línea por dispositivo/página con el apilado medido y OK/FALLA.
Código de salida 1 si algún par se solapa, para poder encadenarlo en un gate.
"""

import argparse
import sys
from playwright.sync_api import sync_playwright

BASE = "https://akibara.cl"

# Perfiles de Playwright + un caso extra angosto (iPhone SE 2016 = 320px).
DISPOSITIVOS = [
    "iPhone SE",
    "iPhone 12",
    "Pixel 5",
    "Galaxy S9+",
    "iPad Mini",
]

PAGINAS = [
    ("faq", "/faq/"),
    ("producto", "/nausicaa-4-planeta-espana/"),
    ("home", "/"),
]

# Devuelve los rects de los cuatro actores. El widget está en shadow DOM abierto,
# así que hay que entrar por .shadowRoot; el resto es DOM normal.
MEDIR = """
() => {
  const rect = el => {
    if (!el) return null;
    const b = el.getBoundingClientRect();
    if (b.width === 0 || b.height === 0) return null;
    return {top: Math.round(b.top), bottom: Math.round(b.bottom),
            left: Math.round(b.left), right: Math.round(b.right)};
  };
  const host = document.getElementById('ads-agent-host');
  const root = host && host.shadowRoot;
  const agente = root ? root.querySelector('div[class*="fixed"][class*="bottom-0"]') : null;
  return {
    vw: innerWidth, vh: innerHeight,
    cssInyectado: root ? !!root.getElementById('akb-agent-offset-style') : false,
    agente: rect(agente),
    nav: rect(document.querySelector('.bottom-nav')),
    sticky: rect(document.querySelector('.akb-sticky-atc')),
    whatsapp: rect(document.querySelector('.joinchat__button')),
  };
}
"""

ABRIR_CHAT = """
() => {
  const root = document.getElementById('ads-agent-host')?.shadowRoot;
  if (!root) return false;
  const pill = Array.from(root.querySelectorAll('div')).find(d => /isolate z-10/.test(d.className));
  if (!pill) return false;
  ['pointerdown','mousedown','pointerup','mouseup','click'].forEach(t => {
    const E = t.startsWith('pointer') ? PointerEvent : MouseEvent;
    pill.dispatchEvent(new E(t, {bubbles: true, composed: true, cancelable: true,
                                pointerId: 1, isPrimary: true}));
  });
  return true;
}
"""

# El panel abierto no debe salirse del viewport: es el bug de ancho fijo del widget.
MEDIR_PANEL = """
() => {
  const root = document.getElementById('ads-agent-host')?.shadowRoot;
  if (!root) return null;
  const composer = root.querySelector('div[class*="fixed"][class*="bottom-0"]');
  const ta = composer && composer.querySelector('textarea');
  const paneles = Array.from(root.querySelectorAll('div'))
    .map(d => ({el: d, b: d.getBoundingClientRect()}))
    .filter(x => x.b.width > innerWidth * 0.5 && x.b.height > innerHeight * 0.3)
    .sort((a, b) => b.b.width - a.b.width);
  const p = paneles.length ? paneles[0].b : null;
  return {
    chatAbierto: !!ta,
    panel: p ? {left: Math.round(p.left), right: Math.round(p.right), w: Math.round(p.width)} : null,
    cabe: p ? (p.left >= -2 && p.right <= innerWidth + 2) : null,
  };
}
"""


def solapan(a, b):
    if not a or not b:
        return None
    return not (a["right"] <= b["left"] or b["right"] <= a["left"]
                or a["bottom"] <= b["top"] or b["bottom"] <= a["top"])


def franja(r, vh):
    return "ausente" if not r else f"{vh - r['bottom']}-{vh - r['top']}"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--shots", help="directorio donde guardar capturas")
    ap.add_argument("--open-chat", action="store_true",
                    help="además abre el chat y comprueba que el panel cabe")
    ap.add_argument("--espera", type=int, default=22,
                    help="segundos de espera al montaje de los widgets (default 22)")
    # Un iPhone real corre WebKit, no Chromium: el perfil de dispositivo cambia el
    # user-agent pero no el motor. El offset usa `:has()`, así que la corrida en
    # WebKit es la que representa de verdad a los visitantes de iOS.
    ap.add_argument("--motor", choices=["chromium", "webkit"], default="chromium",
                    help="motor del navegador (default chromium; usar webkit para iOS real)")
    args = ap.parse_args()

    fallas = []

    with sync_playwright() as pw:
        navegador = getattr(pw, args.motor).launch()
        for nombre in DISPOSITIVOS:
            perfil = pw.devices[nombre]
            for etiqueta, ruta in PAGINAS:
                ctx = navegador.new_context(**perfil)
                pagina = ctx.new_page()
                pagina.goto(BASE + ruta, wait_until="load")
                # Los dos widgets son de terceros y montan con retardo propio.
                pagina.wait_for_timeout(args.espera * 1000)

                m = pagina.evaluate(MEDIR)
                vh = m["vh"]
                pares = {
                    "agente/nav": solapan(m["agente"], m["nav"]),
                    "agente/sticky": solapan(m["agente"], m["sticky"]),
                    "agente/whatsapp": solapan(m["agente"], m["whatsapp"]),
                    "whatsapp/nav": solapan(m["whatsapp"], m["nav"]),
                }
                malos = [k for k, v in pares.items() if v]
                estado = "FALLA " + ",".join(malos) if malos else "OK"
                if malos:
                    fallas.append(f"{nombre} {etiqueta}: {','.join(malos)}")

                print(f"{nombre:12s} {etiqueta:9s} {m['vw']}x{vh} "
                      f"css={'si' if m['cssInyectado'] else 'NO'} | "
                      f"nav {franja(m['nav'], vh)} | sticky {franja(m['sticky'], vh)} | "
                      f"agente {franja(m['agente'], vh)} | wa {franja(m['whatsapp'], vh)} | {estado}")

                if args.open_chat:
                    if pagina.evaluate(ABRIR_CHAT):
                        pagina.wait_for_timeout(9000)
                        p = pagina.evaluate(MEDIR_PANEL)
                        if p and p["chatAbierto"]:
                            ok = "OK" if p["cabe"] else "FALLA se sale del viewport"
                            if not p["cabe"]:
                                fallas.append(f"{nombre} {etiqueta}: panel del chat desborda")
                            print(f"{'':12s} {'chat':9s} panel {p['panel']} | {ok}")

                if args.shots:
                    destino = f"{args.shots}/{nombre.replace(' ', '_')}-{etiqueta}.png"
                    pagina.screenshot(path=destino, full_page=False)

                ctx.close()
        navegador.close()

    print()
    if fallas:
        print("FALLAS:")
        for f in fallas:
            print(f"  - {f}")
        sys.exit(1)
    print("Sin solapes en ningún dispositivo.")


if __name__ == "__main__":
    main()
