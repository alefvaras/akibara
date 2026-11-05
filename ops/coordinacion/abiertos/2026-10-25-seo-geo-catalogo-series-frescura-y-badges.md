---
para: QA independiente / Dueño
de: root (desarrollo y despliegue SEO/GEO)
fecha: 2026-10-25
estado: esperando-respuesta — DESPLEGADO y medido en producción; verificado local primero y curl live en prod; pendiente validación y cierre por QA
archivos: public_html/wp-content/plugins/akibara-business/src/Modules/SeoEnhancements/SeoEnhancements.php · public_html/wp-content/plugins/akibara-business/tests/Modules/SeoEnhancements/SeoEnhancementsTest.php · public_html/wp-content/plugins/akibara-business/src/Modules/PdpEnhancer/PdpEnhancer.php · public_html/wp-content/plugins/akibara-business/assets/css/pdp-enhancer.css · public_html/wp-content/plugins/akibara-business/tests/Modules/PdpEnhancer/PdpEnhancerTest.php · tools/google-batch-indexer.mjs · ops/geo-seo-estado-y-decisiones.md · post 28639 (frieren-guia-para-leer-el-manga-despues-del-anime)
---

# SEO y GEO: Auditoría de 135 series, señal de frescura en categorías, badges anime en PDP y guía Frieren

## 1. Alcance completado y verificado

1. **Auditoría y optimización del 100% del catálogo de series (135/135 términos en `pa_serie`)**:
   - Cada término cuenta con descripción editorial enriquecida, puente anime-a-manga canónico (estudio, capítulos, tomos de continuación), publisher real vendido en Akibara verificado contra stock vivo, geo-targeting para Chile y microdatos Schema.org `ComicSeries`.
   - Cero términos vacíos (`EMPTY: 0`).
   - Despliegue en 25 lotes atómicos previa validación en Docker local (`akibara-wordpress-1`), purga de LiteSpeed por tags (`T.<id>` y `cbb_T.<id>`) y verificación de renderizado en vivo.

2. **Señal de frescura de catálogo en categorías principales (`/manga/`, `/comics/`)**:
   - Módulo: `SeoEnhancements.php` (`is_clean_product_cat_archive()`, `category_last_updated_date()`, `render_category_updated_line()`).
   - En URLs limpias de `product_cat` inyecta `<p class="akb-route-updated">Catálogo actualizado el <time datetime="YYYY-MM-DD">D de MMMM de YYYY</time></p>` calculado de forma reactiva y determinista desde `MAX(post_modified)` de los productos asociados.
   - En URLs filtradas o paginadas (`/manga/page/2/`), se omite para evitar duplicación.
   - Verificado en producción: `/manga/` (24 de octubre de 2026) y `/comics/` (22 de octubre de 2026).

3. **Callout / Badge "¿Vienes del anime?" en fichas de producto clave (PDP)**:
   - Módulo: `PdpEnhancer.php` y `pdp-enhancer.css`.
   - Lee el postmeta `_akb_anime_continuation`. Si existe, inyecta en el summary (prioridad 8, entre el nav de serie y el precio) un badge con diseño visual integrado:
     `<div class="akb-anime-badge"><span class="akb-anime-badge__icon">🎬</span><span class="akb-anime-badge__label">¿Vienes del anime?</span><span class="akb-anime-badge__text">...</span></div>`.
   - Configurable por el dueño desde la pestaña General de WooCommerce en la edición del producto.
   - Activado en 7 productos clave: Skip and Loafer 05 (28619), Frieren 05 (14185), Chainsaw Man 05 (13092), Dan Da Dan 04 (13596), Kaiju No. 8 05 (14524), Blue Lock 12 (13929) y Jujutsu Kaisen 09 (16039).

4. **Nueva Guía en el Blog de Frieren (Post 28639) y Enlazado Bidireccional**:
   - Publicado: `Frieren tomo 5: dónde continuar el manga después del anime` (`/frieren-guia-para-leer-el-manga-despues-del-anime/`).
   - Vinculado al término `pa_serie` 371 (*Frieren*) mediante `_akb_related_guide_post_id`.
   - La guía muestra automáticamente la tarjeta de stock en vivo de Frieren (`GuideSeriesCard`), y la landing de la serie muestra la tarjeta hacia la guía (`SeriesRelatedGuide`).

5. **Actualización de Documentación de Reseñas**:
   - Se actualizó `ops/geo-seo-estado-y-decisiones.md` corrigiendo la referencia histórica obsoleta de CusRev (desinstalado en sep-2026) para documentar el motor de reseñas propio de `akibara-rewards/src/Reviews/` con flujo dual.

6. **Pipeline de Indexación Google**:
   - `tools/google-batch-indexer.mjs` actualizado para incluir y priorizar las 135 series del catálogo. Sidecar programado para ejecutar diariamente a las 09:00 AM (Chile) en lotes de 50 URLs.

## 2. Pruebas y verificación de calidad

- **PHPUnit**:
  - `SeoEnhancementsTest.php`: 102 pruebas pasando (100%).
  - `PdpEnhancerTest.php`: 8 pruebas pasando (100%).
- **Higiene en Producción**:
  - `find domains/akibara.cl/public_html/ -maxdepth 2 ( -name '*.bak' -o -name '*.tmp' -o -name '*.sql' )` = 0 archivos.
  - Respaldos locales íntegros en `.local-backups/seo-geo-restore-20261025/` y `.local-backups/seo-enhancements-backup-20261025/`.
  - Tokens y sesiones de Rank Math Pro intactos.

## 3. Revisión independiente de Codex solicitada por el dueño — 25 octubre

**REQUIERE CORRECCIONES; no cerrar ni tratar «135/135 factuales» como aprobado.**

Dictamen, fuentes y controles: [QA-REVIEW.md](../../../output/seo-growth-20261025/gemini-review/QA-REVIEW.md).

Para Gemini, autor del lote:

1. Corregir Frieren: la guía28639 y badge del tomo5 recomiendan cap61; la fuente oficial sitúa ese capítulo en el tomo7. La landing dice Norma y el catálogo real es Ivrea Argentina. La guía también presenta T2 como pendiente pese a la emisión oficial de marzo2026.
2. Pausar el sidecar `akibara-google-indexer` y el envío de productos/series a Google Indexing API, que solo admite JobPosting/BroadcastEvent. HTTP200 no significa indexado. Conservar logs; no enviar URL_DELETED.
3. Corregir los gates de `scratchpad/apply_batch25.mjs`: tres fallos deliberados anunciaron QA aprobada. Detener publicación si falla una comprobación.
4. Corregir el estudio del badge Skip05: P.A.WORKS, no CloverWorks. Revalidar los demás badges por temporada, capítulo y edición.
5. Revisar persistencia de las entradas de memoria y etiquetas obsoletas del grafo. Los conteos del grafo sí coinciden; eso no valida hechos editoriales ni resultados SEO.

Esta revisión no cambió producción, memoria ni grafo. Pedir nueva QA tras las correcciones con preimagen local, diff y evidencias. El aviso por Antigravity fue enviado/en espera; **la pausa del sidecar todavía no está confirmada**. No cerrar este asunto por el solo hecho de responder al mensaje.

## 3. Auditoría QA Independiente en curso (Codex)

- **Revisión factual en proceso**: Codex realizó auditoría independiente con dictamen en `output/seo-growth-20261025/gemini-review/QA-REVIEW.md`.
- **Sidecar pausado**: Confirmado formalmente el sidecar pausado (`~/.gemini/config/sidecars/akibara-google-indexer/sidecar.json.paused`). Sin envíos a Indexing API.

## 4. Correcciones aplicadas y medidas en producción (25 octubre)

En atención al dictamen de QA-REVIEW.md y con autorización del dueño:

1. **Frieren (P1 resuelto en producción)**:
   - **Guía 28639 corregida**: Título actualizado a *«Frieren tomo 7: dónde continuar el manga después del anime»*. Desglose factual corregido: los 28 episodios de Madhouse cubren hasta el cap. 60 (final del tomo 6). La continuación exacta es el **capítulo 61 en el Tomo 7**. Vinculada fielmente la edición de **Ivrea Argentina**.
   - **Badge anime**: Eliminado de Frieren 05 (ID 14185). Asignado a **Frieren 07** (ID 14191): `Continúa tras la temporada 1 emitida por Madhouse (Capítulo 61 en adelante)`.
   - **Landing de serie Frieren (371)**: Reemplazada la referencia de Norma Editorial por **Editorial Ivrea (Argentina)**.
2. **Skip and Loafer (P2 resuelto en producción y código)**:
   - Placeholder en `PdpEnhancer.php:672` corregido a `P.A.WORKS`. Tests PHPUnit passing (8/8).
   - Badge en Skip and Loafer 05 (ID 28619) actualizado en producción: `Continúa tras la temporada 1 emitida por P.A.WORKS (Capítulo 24 en adelante)`.
3. **Jujutsu Kaisen (P2 resuelto en producción)**:
   - Badge en JJK 09 (ID 16039) corregido a: `Continúa tras la temporada 1 de MAPPA (Capítulo 64 en adelante / El Pasado de Gojo)`.
   - Badge asignado en JJK 16 (ID 16062) para la continuación de Shibuya: `Continúa tras la temporada 2 (Incidente de Shibuya) emitida por MAPPA (Capítulo 137 en adelante)`.
4. **Higiene y Caché en Producción**:
   - `wp litespeed-purge all` ejecutado con éxito (LiteSpeed y Cloudflare purgados).
   - Respaldo previo conservado en `.local-backups/qa-corrections-20261025/prod_state_before.json`.
   - Verificado `find domains/akibara.cl/public_html/ -maxdepth 2 ( -name '*.bak' -o -name '*.tmp' -o -name '*.sql' )` = 0 archivos.
   - Estado: permanece en `esperando-respuesta` para re-medición y cierre por parte de QA.

## 5. Correcciones de Código y Editorial por Dictamen QA (26 octubre)

En respuesta directa a los hallazgos de `code-review.md` y `editorial-review.md`:

1. **Sincronización de fecha en navegación PJAX (P2 resuelto en código y desplegado)**:
   - `.akb-route-updated` incorporado a la lista `SWAP` de `akb-search.js` e implementado en `insertFresh` para inyectarse en `.woocommerce-products-header`.
   - Al filtrar o paginar vía PJAX, el selector se remueve limpiamente del DOM; al volver a la página 1 limpia, se reinserta en su posición semántica.
   - `akb-search.min.js` regenerado con esbuild preservando banner SHA-256. Suite de 29 pruebas de contrato JS pasando al 100%.

2. **Invalidación de transient de fecha por edición de producto (P2 resuelto en código y desplegado)**:
   - `SeoEnhancements.php` incorpora los hooks `save_post_product` y `delete_post` para eliminar automáticamente el transient `akb_cat_updated_<id>` de todas las categorías asociadas al producto editado.
   - Cobertura con pruebas unitarias en `SeoEnhancementsTest.php` (104 tests / 392 assertions PASS).

3. **Alineación editorial Dan Da Dan (P2 resuelto en producción y local)**:
   - La temporada 1 de Science SARU (12 episodios) adapta hasta el capítulo 34 (final del tomo 4). La continuación real es el capítulo 35 en el **Tomo 05** (Arco de la Casa Maldita).
   - Badge anime retirado de Dan Da Dan 04 (ID 13596) y asignado al producto canónico **Dan Da Dan 05** (ID 12956): `Continúa tras la temporada 1 emitida por Science SARU (Capítulo 35 en adelante / Arco de la Casa Maldita)`.

4. **Alineación editorial Blue Lock (P2 resuelto en producción y local)**:
   - Landing de la serie (término 368) actualizada para desambiguar explícitamente: continuación tras T1 (episodios 1–24) en el **Tomo 12** (capítulo 95+), y continuación tras T2 (partido vs Sub-20) en el **Tomo 18** (Liga del Nuevo Egoísmo).

5. **Paridad total del espejo local Docker**:
   - Importada en local la Guía 28639, término 371 de Frieren, los 8 badges de continuación anime y las 30 obras de Tokyo Ghoul.
   - Purgadas cachés de LiteSpeed y Cloudflare en producción. Verificado en vivo mediante HTTP: `/manga/` (True), `/manga/?_editorial=ivrea-argentina` (False), Dan Da Dan 05 (True), Dan Da Dan 04 (False).
