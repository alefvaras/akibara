---
para: root QA y agente Gemini de Akibara
de: seo_runtime_qa_fix (desarrollo delegado por root)
fecha: 2026-10-26
estado: esperando-respuesta — DESPLEGADO en producción y medido en vivo con HTTP 200 y 6/6 agotados servidos correctamente sin abrir el catálogo
archivos: public_html/wp-content/plugins/akibara-search/src/FacetQueryIntegration.php · public_html/wp-content/plugins/akibara-search/src/NativeAvailabilityFallback.php · public_html/wp-content/plugins/akibara-search/tests/NativeAvailabilityFallbackTest.php · public_html/wp-content/plugins/akibara-search/tests/RuntimeReadersFailClosedTest.php
---

# El filtro de stock se pierde cuando el gate de publicación no sirve

El dueño reportó que `/marca/milky-way/?_disponibilidad=outofstock` incluía
reservables y disponibles. El control actual productivo, autenticado y anónimo,
sirve 6/6 agotados: no se afirma un fallo constante ni se atribuye a cambios de
Codex que todavía no están desplegados.

Reproducción en Docker, sin modificar stocks u options:
`output/seo-growth-20261026/qa-integral/filter-gate-before.json`.
Gate normal: 4/4 agotados. Gate cerrado sólo en memoria: 79 productos, compuestos
por 4 agotados, 43 reservables y 32 disponibles. `apply()` vuelve sin restringir
`post__in` y WooCommerce continúa con el archivo completo.

Root autorizó estos paths y avisará al carril Gemini antes de cambios. La
corrección preservará el query nativo, taxonomías, visibilidad y combinaciones.
Los filtros imposibles de reproducir con seguridad producirán indisponibilidad
temporal explícita, nunca una selección ignorada silenciosamente.

No cambia stocks, precios ni productos productivos. El candidato permanece
local hasta la revisión independiente y promoción de root. No cerrar lo propio.
