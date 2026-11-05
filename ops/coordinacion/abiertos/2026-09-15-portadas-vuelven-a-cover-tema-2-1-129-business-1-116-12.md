# tema 2.1.129 + business 1.116.12 — las portadas vuelven a `cover`

- **Abierto:** 2026-09-15 · **estado:** **desplegado en producción el 2026-09-15** (business primero, tema después), verificado con WebKit y Chromium; dos vueltas de revisión independiente (Explore, sólo lectura) cerradas.
- **Reclama:** `public_html/wp-content/themes/akibara-gp/assets/css/{loop,woocommerce}.css`, `themes/akibara-gp/tests/catalog-cover-sizes.test.js`,
  `plugins/akibara-business/assets/css/{native-cart-v2,quick-view,account-collection,home-series-destacadas,brand-family,wishlist}.css`,
  `plugins/akibara-business/tests/CoverObjectFitContractTest.php` (nuevo).
- **Sustituye** la parte «portadas enteras» de `2026-09-15-business-1-116-11-candados-y-portadas.md` y de la 2.1.128. Los candados de 1.116.11 siguen igual.

## Por qué

Con `contain` (2.1.128 / 1.116.11) las portadas nuevas —proporción A5 0,707, no 2:3— dejaban una franja oscura de
2 a 12 px arriba y abajo (medido en producción; 50 px en «Demon Slayer 23 Edición Especial»). El dueño la vio en su
iPhone. Se le mostraron cuatro opciones sobre portadas reales de producción (inyectadas sólo en el navegador de
prueba): franja, relleno con el color del borde, `cover` con excepción, `cover` para todas. **Eligió `cover` para
todas.** Coste aceptado: 1-4 % por lado.

Recorte inteligente (`object-position` según el detalle de cada borde) medido sobre 16 portadas y descartado: sin
diferencia visible a tamaño de tarjeta y no salva el «1» de «Bleach Remix 17».

## Qué cambió

- CSS de portadas igual al de 2.1.127 / 1.116.10 (se conserva la caja con `padding-top: 150%` que corrige WebKit),
  sin las excepciones de `woocommerce-placeholder` que sólo existían para `contain`.
- Deuda previa levantada por la revisión: `prefers-reduced-motion` no anulaba el zoom de `woocommerce.css`
  (0,5,3 contra 0,4,3). Anulación propia después de la regla.
- Comentarios desactualizados corregidos (carrito, corazón de la lista de deseos, zoom).
- Pruebas: el tema recorre todas las reglas de la tarjeta en `loop.css` y `woocommerce.css` (y exige el orden del
  reduced-motion); el plugin fija `cover` en sus seis superficies. Verificadas por mutación.

## Verificación

- Local: node 96/96, phpunit tema 125, `qa.sh` business 3.309 OK, `qa-theme.sh 2.1.129` PASS.
- Producción: 200 en `/`, `/tienda/`, `/serie/one-piece/`, `/mi-cuenta/`, `/carrito/`; `object-fit` computado `cover` en
  catálogo (caja 187 × 280 a 393 px en WebKit), vitrina, vista rápida y carrito; hover `matrix(1.02…)` sin
  preferencia y `none` con `reduced-motion: reduce`.
- «Demon Slayer 23 Edición Especial» (foto del pack, 800 × 765) **se deja así por decisión del dueño** (2026-09-15), tras ver en la tarjeta real la portada del tomo y la foto encuadrada a la izquierda. Nada pendiente.
