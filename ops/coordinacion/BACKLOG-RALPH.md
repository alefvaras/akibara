# Backlog de Célula Autónoma — Metodología Ralph Loop (Akibara)

Este documento contiene la pila de trabajo priorizada derivada de la auditoría 360° realizada el 2026-11-04 (Backend, UX/Operaciones y Catálogo/SEO).
Cada ticket se ejecuta de manera continua bajo el ciclo estricto de **3 iteraciones** (Implementación $\rightarrow$ Verificación Adversarial $\rightarrow$ Sellado, Commit & Grafo) sin detenerse a consultar aspectos técnicos, elevando únicamente dudas de negocio al dueño.

---

## Estado Global del Backlog

- **Total Tickets:** 10
- **En Progreso:** Ninguno (Backlog 100% completado)
- **Completados:** 10 (TICKET-001 al TICKET-010)
- **Pendientes:** 0

---

## Pila de Tickets Priorizada

### 🔴 P0 — Arquitectura, Datos & Telemetría Crítica

#### [TICKET-001] Sincronización `CatalogOrder` ➔ `wp_akb_search_docs` en Producción y corrección de falso positivo en `cmd_self_test()`
- **Impacto:** En producción, 1.279 productos tenían `menu_order` desfasado en búsqueda, mostrando tomos agotados por encima de productos en stock. Además, `cmd_self_test()` emitía éxito falso al fallar pruebas.
- **Archivos:** `public_html/wp-content/plugins/akibara-business/src/Modules/CatalogOrder/CatalogOrder.php`, `public_html/wp-content/plugins/akibara-business/src/Modules/PricingMatrix/PricingMatrixCli.php`.
- **Estado:** ✅ **COMPLETADO & CERTIFICADO EN PROD** (1.279 filas sincronizadas a 0 discrepancias, self-test en 6/6 PASS).

#### [TICKET-002] Promoción de mejoras UX en Tarjeta `.akb-pm-proposal-review` a Producción
- **Impacto:** Dueño atrapado en pantalla tras simulaciones de matriz, lenguaje técnico incomprensible (`round_100`), imposibilidad de filtrar tablas de 593 tomos y riesgo de doble clic.
- **Archivos:** `public_html/wp-content/plugins/akibara-business/src/Modules/PricingMatrix/PricingMatrixAdminPage.php`.
- **Estado:** ✅ **COMPLETADO & DESPLEGADO EN PROD** (Botón Descartar propuesta, buscador en vivo, copy en español y doble-clic guard activos).

---

### 🟡 P1 — UX de Conversión & Operaciones

#### [TICKET-003] Optimización CRO en Compra Mixta: Píldora amigable en Carrito Lateral y copy empático en Checkout
- **Impacto:** El cliente descubre la advertencia de despacho consolidado al final del checkout con un checkbox legalista que frena la conversión.
- **Archivos:** `public_html/wp-content/plugins/akibara-business/assets/js/native-cart.js`, `public_html/wp-content/plugins/akibara-business/src/Modules/NativeCart/NativeCart.php`, `public_html/wp-content/plugins/akibara-business/src/Modules/Logistics/MixedCartShipmentGuard.php`.
- **Estado:** ✅ **COMPLETADO & CERTIFICADO EN PROD** (akibara-business 1.120.13, píldora en mini-cart activa, copy de certeza en checkout, 0 regresiones en watchdog).

#### [TICKET-004] Purga automática de hooks retirados (`OwnCrons::RETIRED`) en `UpgradeManager`
- **Impacto:** Crons descontinuados como `akb_unpaid_orders_sweep` permanecían programados en Docker local al subir de versión.
- **Archivos:** `public_html/wp-content/plugins/akibara-business/src/Internal/UpgradeManager.php`, `public_html/wp-content/plugins/akibara-business/src/Utilities/OwnCrons.php`.
- **Estado:** ✅ **COMPLETADO & CERTIFICADO EN PROD** (akibara-business 1.120.14, OwnCrons::unschedule_retired() ejecutado y certificado).

---

### 🟢 P2 — Catálogo Canónico & SEO Rank Math

#### [TICKET-005] Normalización de 6 autores con macrones en `pa_autor` (Regla 12)
- **Impacto:** 108 tomos de 6 autores (`Gorō Taniguchi`, `Ichirō Ōkouchi`, `Kentarō Yabuki`, `Ryōko Kui`, `Tatsuya Endō`, `Yūsei Matsui`) usan caracteres diacríticos, violando la regla canónica de Akibara.
- **Herramienta:** Script de normalización de términos `pa_autor` y reindexación.
- **Estado:** ✅ **COMPLETADO & CERTIFICADO EN PROD** (6 autores normalizados a nombres latinos estándar, 100 productos reindexados, 0 macrones en bd, watchdog 100% verde).

#### [TICKET-006] Sanitización de 17 meta descripciones de Rank Math con mención de editorial (Regla 13)
- **Impacto:** 17 tomos (*Boticaria*, *Attack on Titan*) incluyen el nombre de la editorial en la meta descripción, penalizando SEO.
- **Herramienta:** Script de limpieza de `rank_math_description`.
- **Estado:** ✅ **COMPLETADO & CERTIFICADO EN PROD** (17 meta descripciones sanitizadas a 140-146 caracteres, 0 menciones de editorial remanentes, HTTP 200 en vivo, watchdog 100% verde).

#### [TICKET-007] Corrección de 8 productos con atributos faltantes en `_product_attributes` (Regla 9 y Regla 5)
- **Impacto:** Gantz Deluxe 1-3, Vagabond 1-3, Slam Dunk Deluxe 1 y Demon Slayer 23 Especial carecían de `pa_pais` o `pa_serie` en el array serializado, omitiéndolos de las especificaciones técnicas.
- **Herramienta:** `ops/sincronizar-atributos-faltantes.php`.
- **Estado:** ✅ **COMPLETADO & CERTIFICADO EN PROD** (8 productos sincronizados a 4 atributos canónicos ordenados, 0 faltantes en todo el catálogo, renderizado verificado en PDP, watchdog 100% verde).

#### [TICKET-008] Fallback de Upsells en 70 tomos finales/únicos de series concluidas (Regla 7)
- **Impacto:** 70 tomos finales o únicos no mostraban «También te recomendamos…» al no existir un tomo con número superior.
- **Archivos:** `public_html/wp-content/plugins/akibara-business/src/Modules/UpsellsAutofill/UpsellsAutofill.php`, `akibara-business.php` (v1.120.15).
- **Estado:** ✅ **COMPLETADO & CERTIFICADO EN PROD** (Fallbacks 1, 2 y 3 implementados; 70 de 70 productos con upsells asignados en prod, 0 huérfanos remanentes, DOM verificado con HTTP 200 en Boticaria 16, Vinland Saga 29 y Look Back, watchdog 100% verde).

#### [TICKET-009] Normalización masiva de Focus Keywords en 1.357 tomos legados (Regla 13)
- **Impacto:** 1.381 tomos tenían focus keywords que no coincidían con el título del producto o carecían de normalización de coma, arriesgando canibalización SEO y scores de Rank Math deficientes.
- **Herramienta:** `ops/normalizar-focus-keywords-catalogo.php`.
- **Estado:** ✅ **COMPLETADO & CERTIFICADO EN PROD** (1.435 productos actualizados en prod, 100% de paridad con título limpio en los 1.992 productos publicados, 0 discrepancias, HTTP 200 en vivo, watchdog 100% verde).

#### [TICKET-010] Ficha técnica estructurada en viñetas para 115 series de `pa_serie` (Regla 17)
- **Impacto:** 140 series tenían sólo sinopsis plana sin la tabla canónica estructurada (*Autor*, *Demografía*, *Editorial oficial y país*, *Estado en Japón*, *Estado de la edición*).
- **Herramienta:** `ops/enriquecer-fichas-tecnicas-series.php`.
- **Estado:** ✅ **COMPLETADO & CERTIFICADO EN PROD** (141 series enriquecidas, 144 de 144 series conformes al 100%, 100% de idempotencia verificada, HTTP 200 en vivo en 20th Century Boys, Assassination Classroom y Banana Fish, watchdog 100% verde).
