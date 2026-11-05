---
para: cualquiera — aviso de alcance · sesión «Análisis de tema web y mejoras» [d1dda4] (despliega su lote antes)
de: sesión «Análisis Apify SEO y geolocalización» [466172] (desarrollo SEO)
fecha: 2026-09-26
estado: esperando-respuesta — DESPLEGADO y medido en producción (business 1.118.0 el 2026-09-27; vitrina de 12; crawl3 hecho: 1.638/1.642 fichas con /serie/ en la miga y en el BreadcrumbList, las 4 restantes a propósito); medición GSC programada el 2026-10-29; lo cierra QA tras re-medirlo
archivos: public_html/wp-content/plugins/akibara-business/src/Modules/PdpEnhancer/PdpSeriesEnhancer.php · public_html/wp-content/plugins/akibara-business/src/Modules/CopyManager/CopyManager.php · public_html/wp-content/plugins/akibara-business/src/Modules/HomeSeriesDestacadas/HomeSeriesDestacadas.php · public_html/wp-content/plugins/akibara-business/src/Modules/AdminPanel/AdminPanel.php · public_html/wp-content/plugins/akibara-business/assets/css/pdp-series-nav.css · public_html/wp-content/plugins/akibara-business/src/Modules/SeoEnhancements/SeoEnhancements.php (sólo docblock) · sus pruebas (PdpEnhancer, CopyManager, HomeSeriesDestacadas, AdminPanel/PanelPurgePolicyTest, FrontOptionPrimingTest) · tools/akibara-e2e/specs/higiene-seo.spec.mjs · tools/akibara-releases/terminology/browser-probe.mjs (su check exige el texto viejo de la barra) · (tras permiso de la sesión de tema) src/Plugin.php `FRONT_READ_OPTIONS` + tests/FrontOptionPrimingTest.php: una fila cada uno
---

# SEO: la ficha declara su serie (miga + enlace con nombre) y la vitrina de la portada se opera sin purge all

Diseño y crítica: `.local-reports/seo-landings-serie-2026-09-26/` (`especificacion.md` + `critica.md`, 8 correcciones
incorporadas). Meta del dueño: «tener mejor SEO»; palanca: que `/serie/<slug>/` gane las búsquedas «<serie> manga».

1. **E1 — la serie en la miga de cada ficha** (HTML visible + BreadcrumbList): Inicio / Manga / Seinen / **The Climber** / The Climber 03.
   Sin peldaño si la serie está en noindex.
2. **E2 — el enlace de la barra de serie dice su nombre**: «Ver los tomos de {serie}», editable en Akibara → Textos
   (sin sprintf). Hoy dice «Ver tomos de esta edición» en 1.641 fichas.
3. **E3 — vitrina «Series más buscadas»**: guardar la pestaña purga sólo la vitrina (etiqueta LiteSpeed propia),
   no el sitio; y una serie sin tomos deja pasar a la siguiente de la lista en vez de dejar un hueco.
   Medido en prod (sólo lectura, 26-sep): página 210 con `count="8"` y 12 slugs guardados → noragami, frieren,
   assassination-classroom y one-piece están guardadas y NO se ven. El panel ahora lo dice («La portada muestra las
   primeras 8…», leído del shortcode de la página de inicio). Qué series van arriba es decisión del dueño.

## Cambios respecto de la especificación (tras la crítica)

- E1 sin memo propio: `get_the_terms()` ya viene de la object cache de Core.
- E2 CSS: NO se pasa a dos filas en escritorio (evita bajar el botón de compra en 1.641 fichas). La celda central va
  a `fit-content(44%)` y el enlace de serie parte línea: un nombre corto mide lo mismo que hoy; uno largo usa 2 líneas.
- E3 con etiqueta propia `akb_home_series` (no 'F'), purga también al borrar la option, y la reserva llena huecos.
- e2e: parche pendiente en `scratchpad/e2e-pendiente/` (`higiene-seo.spec.mjs`: último `<a>` de la miga, entidades
  decodificadas, serie en noindex admitida sólo si su landing dice noindex; `browser-probe.mjs`: el check de terminología).

## Revisión independiente (2026-09-26, agente de sólo lectura)

Sin altas. Aplicado: check de terminología que iba a fallar siempre (media); e2e con series noindex, entidades y un
`aria-label` que no se podía detectar; docblocks (AdminPanel, comentario de 768→1024 px, CSS «byte a byte», criterio
de noindex); serie de un tomo a todo el ancho en escritorio; prueba de conteo que dependía del nombre «One Piece»;
`wp_strip_all_tags()` como WooCommerce; la vitrina purga también `F` (portada cacheada antes de la etiqueta propia).
Verificado por la revisión: el filtro corre en la miga visible, el BreadcrumbList nativo y la alineación de Rank Math;
LiteSpeed etiqueta y purga con el mismo prefijo; Cloudflare sólo se purga en `purge_all` (el aviso de 10 min es cierto).

## Choques declarados

- `AdminPanel.php` figura en `2026-09-15-velocidad-cache-de-fichas-y-etiqueta-ads` (esperando QA desde el 15-sep) y
  todo business en `2026-09-19-qa-a-cero-carriles-paralelos` y `2026-09-22-tema-ux-rendimiento-mejoras`. Las dos
  primeras sesiones no están vivas (ListAgents 26-sep); la tercera confirmó por mensaje que no toca ninguno de estos
  archivos (sus archivos: SiteSpeed, LeanCart, Plugin.php, LlmsManifest, quick-view.css, series-bundles.js) y que
  avisará ANTES si una corrección la obliga a tocar AdminPanel.php o CopyManager.php.
- `Plugin.php` (de la sesión de tema): la revisión independiente pide la fila de `akb_copy_pdp_series_link` en
  `FRONT_READ_OPTIONS` (criterio escrito de la casa para todo `akb_copy_*` del visitante). Pedido por mensaje el 26-sep:
  se añade DESPUÉS de su despliegue y con su visto bueno.

## Plan

Copia → PHPUnit (cada clase aislada) → al desplegar la sesión de tema: fusión a 3 vías contra la base guardada →
espejo (curl + e2e + visual 375/1280/1440) → revisión independiente → producción sin purge all propio (el riel sí
hace `wp litespeed-purge all`: declarado) → verificación con query-string → crawl3.

## Vitrina de la portada (decisión del dueño 2026-09-27: «sí»)

Subir la vitrina de 8 a 12 y poner arriba one-piece, chainsaw-man, one-punch-man y dan-da-dan (las 8 de hoy siguen,
detrás; noragami, frieren y assassination-classroom quedan de reserva). Preimagen y ficheros listos en
`.local-reports/vitrina-2026-09-27/` (0600): `home-210-post_content.{antes,despues}.html` (sólo cambia `count="8"` →
`count="12"`) y `slugs.{antes,despues}.txt`. La escritura en producción por WP-CLI la bloqueó el clasificador: la hace
el dueño (comandos en el mensaje de esta sesión) o se hace desde el panel DESPUÉS de desplegar 1.118.0 (el guardado ya
no hace purge all).

## QA del espejo (2026-09-27, workflow de 4 agentes + re-medición)

- e2e escritorio+móvil (11 specs, 170 pruebas): 2 fallas en móvil. Una era del lote: el peldaño de serie de la miga
  partía línea y su zona táctil quedaba en 18 px (AI-7). Se arregló con `.woocommerce-breadcrumb a { display:
  inline-block }` en pdp-series-nav.css (sólo fichas) y la re-corrida dio 80/80 en verde. La otra era PREVIA
  (tema-foco-visible:200, carrera de 250 ms) y se le pasó a la sesión de tema. La prueba nueva de higiene-seo pasa en
  los dos proyectos.
- Panel y purga (WP-CLI): la sección Textos está bien. `front_page_count()`=8. Guardar la vitrina purga
  `akb_home_series` y `F` una sola vez y sin purge_all. En el espejo el BreadcrumbList lo emite Rank Math, con 5 niveles
  y la serie en la posición 4. `isPartOf` está intacto. Sin avisos en debug.log.
- Revisión final contra dist/1.117.1: sin altas ni medias. Bajas:
  - Docs desfasados (se avisó a la dueña de docs/arquitectura).
  - LÍMITE ACEPTADO: renombrar una serie o marcarla noindex no purga sus fichas. Se actualizan solas antes de la
    medianoche de la tienda, que es el mismo límite que ya tiene el estado de la edición en la barra.
- Visual 375/1280/1440 contra producción:
  - El botón de compra sigue en el primer pantallazo en escritorio.
  - No hay «…» ni desborde.
  - La serie de un tomo ocupa toda la barra.
  - Arreglado: «KAGUYA- / SAMA» (el nombre ahora va en un bloque en línea y pasa entero a la segunda línea).
  - Aceptado: anterior y siguiente muestran unos 16-33 px menos de título en los nombres largos. El número de tomo ya se
    perdía en producción.
- Arnés de terminología: roto por search 0.1.76 (quitó `data-akb-count`), es previo. El check de la barra está al día.

## Producción (2026-09-27, business 1.118.0)

- Despliegue: `ops/akibara-deploy-runtime.sh business 1.118.0`, ejecutado por el dueño a las 16:43 UTC. Paridad 352/352,
  purge all de LiteSpeed propio del riel (Cloudflare no se purgó: el borde de las fichas se renueva en ≤10 min) y correo
  sano.
- 6 fichas testigo pedidas con query-string (the-climber-5, one-piece-1, call-of-the-night-1, to-love-ru-03,
  hikaru-7 y pack-vinland):
  - la miga visible y el BreadcrumbList de Rank Math quedan en 5 niveles, con /serie/ en el nivel 4;
  - la barra dice «Ver los tomos de <span class="akb-series-nav__series-name">…</span>», sin aria-label;
  - pack-vinland (serie noindex) queda sin peldaño.
- URL limpia de la ficha: ya sirve el marcado nuevo.
- Portada (GET): `x-litespeed-tag` incluye `cbb_akb_home_series`.
- `npm run test:prod`, higiene-seo «sube a su serie» + «mismos niveles»: 4/4 en escritorio y móvil, con presupuesto de
  cargas.
- error_log: nada de PdpSeriesEnhancer, CopyManager, HomeSeriesDestacadas ni AdminPanel.
- Prueba de resultados enriquecidos de Google (/the-climber-5-milky-way/, rastreada 13:46 CL, después del despliegue):
  3 elementos válidos. La ruta de exploración tiene 5 ListItem (Inicio / Manga / Seinen / The Climber
  /serie/the-climber/ / The Climber 05).
- La sesión de tema re-midió lo suyo sobre 1.118.0: todo en verde y sin choques.

## Vitrina de 12, aplicada (2026-09-27, la corrió el dueño con los comandos preparados)

- `wp option update akibara_home_series_slugs` (lista nueva). Con 1.118.0 el guardado purgó sólo la vitrina, y la
  portada recién renderizada ya salía con el orden nuevo.
- `wp post update 210 - --user=1`: `count="8"` → `count="12"`. El contenido guardado es la preimagen con ese único
  cambio más un salto de línea final (efecto del STDIN, inocuo).
- Verificado (GET con query-string): 12 series en la portada, en este orden: one-piece, chainsaw-man, one-punch-man,
  dan-da-dan, the-climber, el-verano, my-hero-academia, blue-lock, haikyuu, mob-psycho-100, jujutsu-kaisen y
  ranma-1-2. Reserva: noragami, frieren y assassination-classroom.
- Sin purga global: /the-climber-5-milky-way/, cacheada antes del guardado, siguió en `x-litespeed-cache: hit`.
- Revertir: `.local-reports/vitrina-2026-09-27/` (`slugs.antes.txt` y `home-210-post_content.antes.html`) con los
  mismos dos comandos.

## crawl3 (2026-09-27 17:40-18:27 UTC, 2.613 URLs, mismo rastreador que crawl2 + miga y barra)

Resultado en `.local-reports/seo-landings-serie-2026-09-26/comparacion-crawl2-crawl3.txt`, sacado con
`comparar-crawl.py`.

- Fichas con /serie/ como último enlace de la miga: 0 → 1.638 de 1.642. Con /serie/ como penúltimo nivel del
  BreadcrumbList: también 1.638. Las 4 restantes son a propósito:
  - 2 series en noindex (pack-vinland-saga-123 y shangri-la-frontier-1-expansion-pass);
  - 2 libros de arte (el-arte-de-berserk y el-arte-de-atelier-of-witch-hat) que NO tienen término pa_serie. La crítica
    decía que tenían 2 series: falso. Asignarles una serie es una decisión de catálogo, porque sumarían como tomo.
- Barra que nombra la serie: 1.640 de 1.640.
- Enlaces ficha → landing: 3.287 → 4.923. Con el nombre de la serie en el ancla: 49,9% → 100%.
- Portada: el rastreo tomó la copia del borde anterior al cambio de count (8 series con el orden nuevo). Medido después
  con GET: 12 series. Landings a profundidad 1: 8 → 12.
- Regresiones: ninguna del lote.
  - dorohedoro-5 y genero/sobrenatural/page/9 dieron 522 una vez; hoy dan 200 (transitorio).
  - /serie/slam-dunk/ pasó de 404 a 301 (redirección de Rank Math del 26-sep).
  - /serie/walking-cat/ pasó de 522 a 200.
  - Cambió el title de /serie/rezero-chapter-three/ («completo» → «en español»). Viene del dato de estado de la serie,
    no del lote: compose_series_meta no se tocó.
- La mediana de páginas que enlazan a una landing no cambia (15), como estaba previsto: el lote cambia la calidad de la
  señal, no el número de páginas.
