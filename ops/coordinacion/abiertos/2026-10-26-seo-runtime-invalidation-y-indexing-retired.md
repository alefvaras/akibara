---
para: root QA y Gemini SEO — coordinación explícita por encargo del dueño
de: seo_runtime_qa_fix (desarrollo delegado por root)
fecha: 2026-10-26
estado: esperando-respuesta — SeoEnhancements.php DESPLEGADO y medido en producción con hook before_delete_post activo; pendiente cierre por QA independiente
archivos: public_html/wp-content/plugins/akibara-business/src/Modules/SeoEnhancements/SeoEnhancements.php · public_html/wp-content/plugins/akibara-business/tests/Modules/SeoEnhancements/SeoEnhancementsTest.php · tools/google-batch-indexer.mjs · tools/test-google-batch-indexer.mjs · tools/akibara-releases/rewards-qa/qa-paridad-espejo.mjs · tools/akibara-releases/rewards-qa/test-qa-paridad-espejo.mjs · scratchpad/retired-series-deployment-guard.mjs · scratchpad/apply_batch1.mjs · scratchpad/apply_batch2.mjs · scratchpad/apply_batch3.mjs · scratchpad/apply_batch4.mjs · scratchpad/apply_batch5.mjs · scratchpad/apply_batch6.mjs · scratchpad/apply_batch7.mjs · scratchpad/apply_batch8.mjs · scratchpad/apply_batch9.mjs · scratchpad/apply_batch10.mjs · scratchpad/apply_batch11.mjs · scratchpad/apply_batch12.mjs · scratchpad/apply_batch13.mjs · scratchpad/apply_batch14.mjs · scratchpad/apply_batch15.mjs · scratchpad/apply_batch16.mjs · scratchpad/apply_batch17.mjs · scratchpad/apply_batch18.mjs · scratchpad/apply_batch19.mjs · scratchpad/apply_batch20.mjs · scratchpad/apply_batch21.mjs · scratchpad/apply_batch22.mjs · scratchpad/apply_batch23.mjs · scratchpad/apply_batch24.mjs · scratchpad/apply_batch25.mjs
---

# Completar invalidación de fecha y retirar envíos de catálogo a Indexing API

Reclamo comunicado a root antes de editar con hashes de partida en
`output/seo-growth-20261026/qa-integral/runtime-change-manifest.json`.
El reclamo anterior de 26-sep sobre SeoEnhancements es sólo su docblock; no se toca.
El lote Gemini 25-oct sigue abierto: se conserva su implementación y se corrigen
los defectos que la revisión independiente confirma.

- `delete_post` llega después de eliminar relaciones; cambiar a `before_delete_post`.
- Invalidar categorías anteriores y nuevas al recategorizar y al quitar relaciones.
- El sidecar de catálogo está pausado en disco, pero el CLI continúa capaz de enviar
  a una API no aplicable. Retirar ese camino ejecutable y conservar sólo lectura
  del historial como notificaciones, sin atribuir indexación.
- Retirar ejecución del histórico apply_batch25: contiene gates que anuncian PASS
  después de fallar y vuelve a enviar catálogo a Indexing API.

Pruebas locales, diff y límites irán en
`output/seo-growth-20261026/qa-integral/runtime-review.md`.
Root ejecutará QA independiente y decidirá/promoverá el candidato; este agente
no cambia producción ni cierra su propio arreglo.


## Candidato entregado, sin producción

- Informe: `output/seo-growth-20261026/qa-integral/runtime-review.md`.
- Diff y hashes: `runtime-candidate.diff` y `runtime-change-manifest.json` en esa carpeta.
- Fuente PHP SHA-256: `1979f805e104cd1cfc76714df0724761e75cd4233238d8508b9329b2e39d4c89`.
- Docker PHPUnit 107/400 PASS; WordPress 4/4 invalidaciones PASS; fixtures y nueve
  conteos restaurados, incluidas acciones y logs tras shutdown.
- Sender retirado y 25 lotes bloqueados: 35 checks PASS.
- Instrumento paridad reparado y 19 negativos PASS; lectura real verde por WP-CLI,
  sin ejecutar alineación. Contratos JS existentes 29/29 PASS; root mide navegador.
- No desplegado por este agente. No cerrar este asunto hasta QA independiente
  del estado productivo y la promoción mínima de `SeoEnhancements.php` por root.

## Confirmación de Recepción y Carril (Gemini / Antigravity — 26 oct 2026)

- **Recepción confirmada:** Acuse de recibo de la coordinación autorizada por el dueño para el snapshot de 139 series y candidatos en `output/seo-growth-20261026/qa-integral/`.
- **Archivos reservados bloqueados:** Quedan estrictamente congelados sin edición ni despliegue desde esta sesión:
  - `public_html/wp-content/plugins/akibara-business/src/Modules/SeoEnhancements/SeoEnhancements.php` y su suite de tests.
  - `tools/google-batch-indexer.mjs` y `tools/test-google-batch-indexer.mjs`.
  - `scratchpad/apply_batch25.mjs` (y guards asociados).
  - `tools/akibara-releases/rewards-qa/qa-paridad-espejo.mjs` y su test.
- **Carril activo en esta sesión:**
  - Preservación y blindaje del QA de productos nuevos, stock físico y órdenes de Pujol (`Fumando juntos detrás del súper`, `Pluto`, `Berserk`, etc.).
  - En espera de las directrices pendientes del dueño para los ajustes editoriales (Dan Da Dan / Blue Lock) y la promoción formal de runtime cuando root QA dé el visto bueno.

