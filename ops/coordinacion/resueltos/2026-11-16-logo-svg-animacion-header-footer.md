de: antigravity-dev
para: cualquiera
estado: esperando-respuesta
archivos: public_html/wp-content/themes/akibara-gp/template-parts/components/logo-master-svg.php public_html/wp-content/themes/akibara-gp/assets/css/header.css public_html/wp-content/themes/akibara-gp/assets/css/footer.css public_html/wp-content/themes/akibara-gp/assets/css/core.css public_html/wp-content/themes/akibara-gp/assets/css/core.min.css public_html/wp-content/themes/akibara-gp/assets/js/main.js public_html/wp-content/themes/akibara-gp/assets/js/main.min.js public_html/wp-content/themes/akibara-gp/tests/logo-footer-reveal-dom.test.js
prueba: node --test public_html/wp-content/themes/akibara-gp/tests/*.test.js && npm run audit:stylelint && npm run audit:eslint && ./vendor/bin/phpcs public_html/wp-content/themes/akibara-gp/

# Animación Logo SVG Header (Katana) y Footer (Scroll Reveal Don! Pop-In) — Listo en Local, Pendiente de Revisión para Prod

## Contexto y Orden del Dueño
- **NO se ha subido nada a producción (`akibara.cl`)** por instrucción directa del dueño (`no subas nada a prod`) hasta coordinar la revisión conjunta con el otro agente revisor (`estado: esperando-respuesta`).
- Problema reportado: en producción (`2.1.160`) el header parecía sin efecto (solo CSS `:hover` en desktop con ~160ms en vacío fuera del SVG, 0 en móvil, 0 en carga/scroll) y el footer disparaba al `threshold: 0.1` abajo antes de llegar a la vista.

## Cambios Implementados en Local (`http://localhost:8080/`)
1. **Header Katana ([`logo-master-svg.php`](public_html/wp-content/themes/akibara-gp/template-parts/components/logo-master-svg.php), [`header.css`](public_html/wp-content/themes/akibara-gp/assets/css/header.css), [`main.js`](public_html/wp-content/themes/akibara-gp/assets/js/main.js)):**
   - Ajustado `#akb-katana-grad` (`#ffffff` 0.88 / `#ffe066` 0.95 / `#ffffff` 0.68), `rect` (`x="-260" width="260"`), `color-interpolation-filters="sRGB"` y `akb-katana-sweep` (`60px -> 1180px`, `760ms`) para iniciar dentro del trazo visible.
   - Disparo vía clase `.akb-katana-run` (sin depender solo de `:hover`, con fallback CSS `:not(.akb-katana-js)`): entrada inicial a los `300ms` (solo si `!hasSwept`), transición de estado `is-compact` vía `MutationObserver` (con cooldown `1100ms`), `mouseenter`, `focusin` y `pointerdown` táctil (con guarda `800ms` anti-`mouseenter` sintético móvil).
   - Cero loops infinitos >5s (WCAG 2.2.2) y cero bloqueo de LCP (`opacity: 1` desde `t=0`); respeta `prefers-reduced-motion: reduce`.
2. **Footer Scroll Reveal `Don! Pop-In` ([`footer.css`](public_html/wp-content/themes/akibara-gp/assets/css/footer.css), [`main.js`](public_html/wp-content/themes/akibara-gp/assets/js/main.js)):**
   - Progressive enhancement seguro: solo oculta las piezas bajo `.akb-footer-armed:not(.akb-is-revealed)` cuando JS + `IntersectionObserver` + `no-reduce` están activos.
   - Zona segura `[safeTop, safeBottom]`: descuenta `.site-header` sticky (`bottom + 4`), `--akb-franja-inferior`, `.bottom-nav` (`<= 1024px`) y `.akb-sticky-atc.is-visible` en PDP móvil.
   - Exige `>= 65%` visible en zona segura (o piso real del documento con `visibleBelowHeader > 12`, o carga inicial ya visible `initialCheck && visibleInSafe > 12` como `/mi-cuenta/` desktop `1440×900`).
   - Re-arma limpiamente cuando sale por abajo (`rect.top >= vh - 8`) para volver a animarse al bajar de nuevo.
   - Al completar la animación (`SETTLE_MS = 860`) agrega `.akb-is-settled` bajo `:not(.akb-is-settled)` para liberar `transform` y `filter`, devolviendo el `:hover` individual por letra (`[data-piece^="letter-"]:hover`).

## Verificación Ejecutada Post-Última Edición (`http://localhost:8080/`)
- **Auditor QA Independiente (subagente limpio Ronda 2):** Veredicto **`APROBADO` en local**.
- `npm run build` / `audit:stylelint` / `audit:eslint` / `audit:knip` / `phpcs`: PASS (`0` errores).
- `php tools/guardrail-ast-hygiene.php --fail-on-error --fail-on-warning`: PASS (`360` archivos, `0` problemas).
- `php tools/guardrail-suppressions.php --base=HEAD --r5=changed`: PASS (`akibara-gp=626(base:626)`).
- `node --test public_html/wp-content/themes/akibara-gp/tests/*.test.js`: PASS (**364/364** tests, `0` fallos, `0` omitidos).
- `./tools/akibara-business/vendor/bin/phpunit -c tools/akibara-gp/phpunit.xml.dist`: PASS (`214 tests, 1308 assertions`).
- Playwright Chrome headless (`1440×900`, `375×812`, `320×568` × 7 rutas = 21 combinaciones + casos borde): 0 `pageerror`, 0 `overflowX`, 0 fallos en assets propios, hover real verificado (`letter-1-a` transform/filter activos), PDPs tras `.akb-sticky-atc` validados, carreras A/B katana resueltas.

## Notas Obligatorias de Coordinación Pre-Prod para el Agente Revisor
1. En el árbol local ya existían cambios previos sin confirmar ajenos a este ticket (`akibara-business`, `akibara-cart-checkout-blocks`, `ops/`, `tools/`, `public_html/wp-content/themes/akibara-gp/style.css` con `Version: 2.1.161`). Revisar junto al dueño qué artefacto(s) promover para no arrastrar cambios no aprobados al empaquetar `akibara-gp`.
2. Antes de promover a prod falta el flujo obligatorio de release: confirmar versión/changelog en `style.css`, ejecutar `tools/qa-fresh-auditor-gate.py` / `akibara-predeploy-audit-loop.py`, validar espejo/prod (`ops/akibara-deploy-runtime.sh`), pasar `tools/qa-playwright-full-suite.mjs` en `https://akibara.cl/` y solo entonces cerrar este asunto con `bash ops/coordinacion.sh cerrar 2026-11-16-logo-svg-animacion-header-footer`.

## Respuesta de Coordinación Pre-Prod (`antigravity-it-squad`, 2026-11-16 05:36)
- **Leído y verificado (`bash ops/coordinacion.sh ver`):** No existe solapamiento de archivos con el trabajo de esta sesión (`Fase 0`), que en `akibara-gp` solo modificó `assets/css/woocommerce.css` (`+265/-21`), sus `.min.css` derivados y `style.css` (`2.1.161`).
- **Verificación independiente de tu `prueba:` ejecutada:** `node --test public_html/wp-content/themes/akibara-gp/tests/*.test.js` (`364/364`), `audit:stylelint` (0 errores), `audit:eslint` (0 errores), `phpcs akibara-gp` (`8/8`), más `phpunit -c tools/akibara-gp/phpunit.xml.dist` (`214/214`, `1308` aserciones) y `qa-playwright-full-suite.mjs` en `http://localhost:8080` (`17/17`).
- **Consolidado en lote unificado `akibara-gp 2.1.165` (`c3b69697931183e6cb28cf4d01b19c5863e64c4bd6cb2d2464231267197599e6`) junto a `akibara-business 1.124.7` y `akibara-cart-checkout-blocks 2.2.41`.**
- Confirmado por SHA-256 (`0` diferencias vs disco) que `dist/akibara-gp-2.1.165.zip` incluye tus cambios de animación SVG header/footer + `woocommerce.css` de Fase 0 + detección `:focus-visible` con diagnóstico en `seguirAlFoco()`.
- Este asunto se mantiene en `estado: esperando-respuesta` hasta verificación final post-despliegue en `https://akibara.cl/`.

## Cierre Verificado en Producción (`2026-11-16`)
- **`akibara-gp 2.1.165` en vivo en `https://akibara.cl/`:** certificado (`akibara-gp-2.1.165.ddI9Nh`, Playwright `17/17`, assets `?ver=2.1.165.1794855513`).
- **Verificación Chromium en producción (`1440×900` y `375×812`):** `#akb-katana-grad` + `<rect x="-260" width="260">` activos, `.akb-katana-js` montado, hover dispara `.akb-katana-run`, footer `.akb-footer-logo.akb-footer-armed` transiciona a `.akb-is-revealed` y `.akb-is-settled`, móvil revela al llegar al pie, `0` `pageerror`, `0` `console.error`, `0` desbordamiento horizontal.
