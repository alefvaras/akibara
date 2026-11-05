# tema 2.1.127 — la portada se recortaba arriba y abajo en Safari (todo iPhone)

- **Abierto:** 2026-09-15 · **estado:** desplegado en producción, borde purgado y re-medido allá. Lo levantó el dueño desde su iPhone («la portada de Maximum Berserk aparece cortada»).
- **Reclama:** `public_html/wp-content/themes/akibara-gp/assets/css/loop.css`,
  `public_html/wp-content/themes/akibara-gp/tests/catalog-cover-sizes.test.js`,
  `public_html/wp-content/themes/akibara-gp/style.css` (versión)

## Qué pasaba

`.akb-product-cover-wrap` armaba la caja 2:3 con `aspect-ratio`. WebKit —Safari, y por tanto **todo
iPhone**— no cuenta la altura de una caja con `aspect-ratio` al medir la tarjeta: dejaba la tarjeta 36 px
más baja que Chromium y, como la portada es un ítem flex, la **encogía**. Con `object-fit: cover` eso
recorta la portada por arriba y por abajo.

Medido el 2026-09-15 a 393 px (DPR 3), en el espejo **y** en producción:

| motor | caja de la portada | proporción | recorte vertical |
| --- | --- | --- | --- |
| WebKit | 170,8 × **220,2** | 0,776 | **8 % – 13 %** |
| Chromium | 170,8 × **256,2** | 0,6667 | 0 % |

Por eso no había salido en ninguna ronda de QA: las sondas medían en Chromium, donde la caja sale bien.

## El arreglo

El 2:3 se hace con `padding-top: 150%` en un `::before` y la imagen pasa a `position: absolute; inset: 0`;
la jaula lleva `flex-shrink: 0`. El padding es layout normal y los dos motores lo miden igual. Las
insignias (`.akb-badge`) y el aviso de «Portada no disponible» ya eran absolutas, así que el marcado no
cambió.

`flex-shrink: 0` **solo** no arregla nada (se probó): el motor ya había medido mal la fila.

## Verificación

- Espejo, WebKit y Chromium, a 380 / 393 / 768 / 1024 / 1440 px, en `/`, `/tienda/`, `/serie/one-piece/`,
  `/mi-cuenta/lista-de-deseos/` y `/tienda/?filtro_precio=1`: proporción 2:3 exacta, cero recorte vertical,
  cero botones cortados, y la altura de tarjeta de WebKit pasó a coincidir con la de Chromium (462,8 contra
  462,7; 520,5 contra 520,6; 517,1 contra 517,5).
- `qa-theme.sh`: rc=0 (96 pruebas + invariantes de empaquetado, ZIP 2.1.127 publicado).
- Producción tras desplegar y purgar el borde: WebKit 393 px da 256,2 y cero recorte; captura de la ficha
  de «Maximum Berserk 01» con el título y el nº de tomo completos.
- Candado de fuente nuevo en `catalog-cover-sizes.test.js`: la jaula **no** puede volver a usar
  `aspect-ratio`, el `::before` lleva `padding-top: 150%` y la imagen es absoluta.

## Lo que quedó medido y NO se tocó

- **Las portadas nuevas de Ivrea no son 2:3.** En producción, de 1.603 portadas la mediana es 0,6667 y sólo
  4 son más altas que 2:3, pero **95 son más anchas**, y son justo las últimas subidas (560×792 = 0,707;
  560×787; 560×781). En la rejilla pierden ~6 % por los **lados**. Decisión de negocio del dueño (la de
  2026-07-06 fue «cover, sin franjas»); si quiere cero recorte hay que corregirlas en la subida, no en CSS.
- **Al espejo le faltan 1.528 derivados `akb-card-2x`** que producción sí tiene (y 170 de otros tamaños):
  su copia de `uploads` es anterior a la regeneración del 2026-08-17. Por eso `qa-series-selector.sh` daba
  404 intermitentes. Se regeneran en el espejo con `wp media regenerate --only-missing
  --image_size=akb-card-2x`; **no** se bajan de producción.

## Pendiente menor (va con el próximo release del tema)

`inc/performance.php:498` lee el interruptor de core `wp_img_tag_add_auto_sizes` y PHPCS lo contaba como
hook sin prefijo: era la **última** deuda del análisis estático del plugin principal. Ya lleva su
`phpcs:ignore` con motivo en el árbol (PHPCS queda en cero), pero es un comentario: **no se desplegó solo**,
viaja con el próximo ZIP del tema.

## Seguimiento — tema 2.1.128 (2026-09-15, desplegado)

- **Las portadas ya no se recortan** (`object-fit: contain`, decisión del dueño tras ver la medición): el
  99 % de lo subido en septiembre no es 2:3 porque Akibara Image Studio conserva la proporción real del tomo
  (0,707). Detalle y tabla por mes en `docs/arquitectura/13-tema-akibara-gp.md`. Verificado en producción con
  WebKit: Beastars, Death Note, Sanda y Dan Da Dan completos; el catálogo 2:3 viejo, idéntico.
- El `phpcs:ignore` de `inc/performance.php` que quedaba pendiente **viajó en este ZIP**: ya no hay deuda
  sin desplegar en el tema.
- Las cajas del plugin principal (carrito, vista rápida, «Mi colección», vitrina, ediciones) van en
  business 1.116.11.
