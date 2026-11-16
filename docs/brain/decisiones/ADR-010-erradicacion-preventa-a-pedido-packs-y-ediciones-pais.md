# ADR-010: Erradicación Radical de Preventa, A Pedido, Arma tu Pack y Distinción de Ediciones por País (Modelo 100% Stock Físico)

- **Estado:** Aceptado e Implementado en Local (2026-11-05)
- **Fecha:** 2026-11-05
- **Autor / Supervisor:** Argon (High) (`it-tech-lead`)
- **Reemplaza (`Superseded`):** `ADR-001`, `ADR-004`, `ADR-007` y `ADR-008` en todo lo relativo al estado comercial `onbackorder` («A pedido» / «Preventa»), promesas de entrega estimada (`EstimatedDelivery`), despacho consolidado mixto (`MixedCartShipmentGuard`), «Arma tu Pack» (`SeriesBundles` / BYP), páginas comparativas y conmutadores de familia editorial (`SeriesEditions`, `BrandFamilyLanding`, `BrandLandingCallout`, `BrandLandings`) e integraciones de Google Listings & Ads y Microsoft Clarity.

## 1. Contexto y Decisión de Negocio

Por instrucción directa del dueño de Akibara (2026-11-05), la tienda abandona de forma definitiva y permanente tres dominios que agregaban complejidad transaccional, ruido en la interfaz y sobrecarga operativa:

1. **Dominio A — Preventa, «A Pedido», Entrega Estimada (ETA) y Despacho Consolidado Mixto:**
   - Se elimina por completo el estado comercial intermedio `onbackorder` («A pedido» / «Preventa»), los descuentos de reserva, el cálculo de ventanas de llegada (`EstimatedDelivery`), las colas y correos de recepción (`OrderPreventa`, `ReadyToShipCron`, `RemindersCron`), la ruta `/a-pedido/` (`PreventasRoute`) y el bloqueo de carritos mixtos (`MixedCartShipmentGuard`).
   - La ruta `/disponibles/` (`DisponiblesRoute`) pasa a emitir una redirección `301 Moved Permanently` hacia el catálogo general (`/catalogo/`), preservando el SEO acumulado.

2. **Dominio B — Distinción de Serie / Edición por País y Conmutadores de Familia (`BrandFamilyLanding` / `BrandLandingCallout`):**
   - Se erradican `SeriesEditions` (`EditionsPanel`, `EditionFacts`, `EditionCopy`), `BrandFamilyLanding`, `BrandLandingCallout`, `BrandLandingSchema` y `BrandLandings`.
   - Desaparecen las páginas comparativas intermedias `/panini/` e `/ivrea/` y la barra conmutadora `EDICIONES DE PANINI / IVREA [AR Argentina ->] [ES España]` en `/marca/<slug>/`.
   - Se **conservan** `BrandRegion`, `RegionMeta` y `MenuInjector` para mantener el mega-menú desplegable `EDITORIALES` en la cabecera (`EDITORIALES ARGENTINAS` | `EDITORIALES ESPAÑOLAS` con distintivos `AR` y `ES` y enlace `Ver todas →`) y la agrupación por país en `/editoriales/`.

3. **Dominio C — Módulo «Arma tu Pack» (`SeriesBundles` / BYP):**
   - Se elimina por completo el armador de packs interactivo, sus cupones dinámicos (`akb_sb_`), sus reglas de top-up y el guard de reembolsos parciales (`PartialRefundBundleGuard`).
   - Se rescata `CustomerCollection` dentro de `Akibara\Business\Modules\AccountCollection\CustomerCollection` para mantener intacta la sección privada «Mi colección» del cliente, conectándola directamente con `BackInStock` (suscripción 1-clic a tomos agotados) y con `NativeCart` (adición directa de tomos faltantes en stock físico, sin descuentos por pack).

4. **Dominio D — Erradicación de Google Listings & Ads (`google-listings-and-ads`), Microsoft Clarity (`microsoft-clarity`) y Puentes Propios:**
   - Se desinstalan y eliminan los plugins `google-listings-and-ads` y `microsoft-clarity`.
   - Se eliminan todos los puentes propios hacia ellos: `mu-plugins/akibara-clarity-production-only.php`, `assets/js/clarity-commerce-events.js`, `Modules/SiteSpeed/GoogleAdsTag.php`, `Modules/ProductSchemaShopping/AdultContentFeed.php`, y los hooks asociados en `FrontendCompatibility`, `SiteSpeed`, `AnalyticsAfterLoad` y `CrossOriginScripts`.
   - Se eliminan las tablas `wp_gla_*`, las opciones `gla_*`, `wc_gla_*`, `clarity_*`, `akb_google_ads_tag` y los metadatos `_wc_gla_*`.

## 2. Contrato Canónico de Inventario (100% Stock Físico vs. Agotado)

A partir de este ADR, **todo el catálogo de Akibara opera exclusivamente bajo dos estados físicos (`_backorders = 'no'`)**:

1. **`instock` (Disponible en bodega física de Santiago):**
   - `_manage_stock = 'yes'`
   - `_stock > 0` (entero positivo correspondiente a las unidades reales en estantería)
   - `_backorders = 'no'`
   - Sin término `outofstock` en `product_visibility`.

2. **`outofstock` (Agotado / Sin existencias físicas):**
   - `_manage_stock = 'no'`
   - `_stock = null` (o `0`)
   - `_backorders = 'no'`
   - Sin `_sale_price` residual (`_price = _regular_price`).
   - Término `outofstock` asignado en `product_visibility` y sincronizado en `wp_wc_product_meta_lookup`.

El estado `onbackorder` queda **terminantemente prohibido** en toda la tienda y es auditado como violación crítica (`forbidden_onbackorder`) por `ops/guardrail-inventario-akibara.py`.

## 3. Resguardo de Pedidos Históricos y Optimizaciones Operativas

- **Migración Forense de Notas de Pedido (`ops/migrar-notas-preventa-ordenes.php`):** Antes de purgar los metadatos de preventa, cualquier pedido activo (`wc-processing`, `wc-on-hold`, `wc-pending`) que contuviera ítems históricos bajo modalidad preventa/a pedido recibe una nota privada inmutable en WooCommerce con el detalle exacto de SKUs, títulos y promesas originales para atención al cliente.
- **Debounce de Action Scheduler:** Se incorpora ventana deslizante de 600 segundos en `CacheWarmer` (`akibara_business_cache_warm`) y `CatalogOrder` (`akibara_catalog_order_recompute`), y se optimiza `akb_search_rebuild_watchdog` en `akibara-search` para no encolar ejecuciones redundantes cuando no existe un rebuild activo.
