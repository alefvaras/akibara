# ADR-014: Saneamiento Estructural Zero-Trust, Blindaje de Módulos en Reserva y Consolidación Suite Blue Express

- **Estado:** Aceptado e Implementado (2026-11-06)
- **Fecha:** 2026-11-06
- **Autor / Supervisor:** Antigravity (`it-tech-lead` / Astra)
- **Afecta a:** `akibara-business` (v1.120.31), `akibara-rewards` (v0.4.31), `akibara-cart-checkout-blocks` (v2.2.36), tema `akibara-gp` (v2.1.152), `mu-plugins` (`akibara-bluex-suite.php`) y Base de Datos MariaDB.

---

## 1. Contexto y Diagnóstico

Tras la erradicación del modelo ternario de inventario (ADR-013), una auditoría estructural profunda sobre los 6 vectores del sistema (AST, callers muertos, migraciones en caliente, lecturas fantasma en base de datos, frontend y mu-plugins) detectó acoplamiento residual, opciones huérfanas y código legado de iteraciones pasadas.

Asimismo, se estableció una directiva estricta de **Blindaje de Módulos en Reserva**: los módulos latentes `WelcomeCoupon` (cupón de bienvenida con Brevo) y `TaxonomyDiscount` (descuentos por taxonomía) están temporalmente apagados por estrategia de negocio pero se activarán próximamente, por lo que su infraestructura, contratos REST, endpoints y opciones debieron ser protegidos al 100%.

---

## 2. Decisiones Técnicas Implementadas

### 2.1 Paso 1: Blindaje de Redención, Desacople BYP y Purga de Metadatos
- **`Redemption::filter_free_shipping_availability()`:** Se reemplazó el método zombie por una implementación real enganchada a `woocommerce_shipping_free_shipping_is_available` (prioridad 20, 3 argumentos) que preserva el envío gratuito cuando el subtotal de productos califica antes del descuento del cupón de puntos.
- **Desacople total de `byp` en `CustomerCollection.php`:**
  - Constantes migradas: `TRANSIENT_PREFIX = 'akb_collection_owned_all_v3_'`, `OWNER_FENCE_PREFIX = 'akb_collection_owner_fence_'`, `USER_META_OWNED_GEN = '_akb_owned_gen'`.
  - Migración SQL en MariaDB: 59 registros de `wp_usermeta` migrados de `_akb_byp_owned_generation` a `_akb_owned_gen` en producción (36 en local Docker).
- **Purga de termmeta obsoleto:** Erradicado `_akb_serie_estado_obra` de la base de datos (clave canónica activa: `_akb_serie_tomos_obra`).

### 2.2 Paso 2: Eliminación Quirúrgica de Métodos Muertos Reales
- `saludo()` en `EmailBrand.php`: Eliminado.
- `sibling_prefix()`, `sibling_link_copy()` y sus 4 constantes asociadas en `DisponiblesRoute.php`: Eliminados.
- `label_for_state()` en `Availability.php`: Eliminado.
- `sanitize_state()` en `PublicationStatus.php`: Eliminado.
- `akb_fsp_ignore_bundle_discounts` en `FreeShippingThreshold.php`: Eliminado.
- **Falsos positivos analizados y protegidos con evidencia:**
  - `updateScopeButtons` y `filterProposalRows`: Son funciones JS inline en `PricingMatrixAdminPage.php` requeridas para la interactividad del panel admin.
  - `get_script_handles()` y `get_editor_script_handles()` en `CheckoutBlockIntegration.php`: Métodos obligatorios del contrato de `IntegrationInterface` de WooCommerce Blocks.
  - `toast()` en `Referrals.php`: Función cliente JS embebida para retroalimentación visual al copiar enlaces.

### 2.3 Paso 3: Erradicación de Migraciones en Caliente
- `ContactoLog::migrate_legacy()` y `LEGACY_OPTION`: Eliminados.
- `BackInStock::normalize_legacy_flag_options()`: Eliminado.
- `LlmsManifest::migrate_factory_identity()` y `PREVIOUS_DEFAULT_IDENTITIES`: Eliminados.

### 2.4 Paso 4: Limpieza de Lecturas Fantasma y Base de Datos
- `_akb_manual_sale_lock`: Eliminado de `ReactiveStockPriceHooks.php` y `PricingSynchronizer.php`.
- Fallback `_akibara_serie` en postmeta: Eliminado de `ProductFaq.php` (la serie se resuelve exclusivamente desde la taxonomía canónica `pa_serie`).
- Purga SQL: Eliminada la meta-clave residual `_akb_rewards_redeem_intent` de `wp_usermeta`.
- **Falsos positivos validados:**
  - `_akb_rewards_review_qualifying` es leído activamente por `Ledger::raw_user_meta_int()` para calcular cadencia de puntos por reseña (módulo vivo).
  - Opciones `AccessWall::OPTION_*`: Opciones activas que configuran los textos del muro de acceso de `/mi-cuenta/`.

### 2.5 Paso 5: Frontend e Higiene CSS/JS
- CSS: `admin-eda.css` eliminado. Clases `.akb-cortes-form` y `.akb-account-collection__availability--preorder` erradicadas.
- Tokens: 7 variables `--aki-*` sin consumidores (`--aki-yellow-bright`, `--aki-yellow-dark`, `--aki-accent`, `--aki-info`, `--aki-gray-800`, `--aki-muted`, `--aki-screentone-dense`) eliminadas de `core.css` y `tokens.css`.
- Localize: Parámetros muertos (`wcAjaxUrl` en `Endpoint.php`, `isSeriesArchive`/`wcAjaxUrl` en `CollectionBadges.php`, `checkoutUrl` en `NativeCart.php`) retirados.
- Recompilación limpia de assets (`npm run build`).

### 2.6 Paso 6: Consolidación de MU-Plugins y Eliminación de Hostinger Residual
- Eliminados de producción y local: `hostinger-auto-updates.php` y `hostinger-preview-domain.php`.
- Consolidación de Blue Express: Creado `akibara-bluex-suite.php` unificando cotización por comuna, traducciones en español, vigía de integridad, retención de logs y soporte REST tracking.
- Eliminados los 5 mu-plugins individuales (`akibara-bluex-cotiza-solo-con-comuna.php`, `akibara-bluex-en-espanol.php`, `akibara-bluex-integrity-watch.php`, `akibara-bluex-log-retention.php`, `akibara-bluex-rest-tracking.php`).
- Optimización de rendimiento: Eliminado el fallback obsoleto `as_next_scheduled_action` en la retención de logs de Blue Express (ahorrando 3 consultas por disparo a favor de `as_has_scheduled_action`).

---

## 3. Certificación Técnica y Despliegue en Producción

1. **Guardrail CI Completo (`tools/guardrail-ci.sh --full`):**
   - 50 pruebas negativas de despliegue y herramientas: PASS.
   - 4 pruebas de salud de transporte y SMTP: PASS.
   - 3.597 pruebas unitarias de `akibara-business`: PASS (100%).
   - 910 pruebas unitarias de `akibara-rewards`: PASS (100%).
   - 11 pruebas unitarias de `akibara-search`: PASS (100%).
   - 473 pruebas unitarias de `akibara-cart-checkout-blocks`: PASS (100%).
   - 629 pruebas unitarias de `akibara-gp`: PASS (100%).
   - 706 pruebas DOM headless: PASS (100%).
2. **Despliegue a Producción (Hostinger):**
   - Paquetes sellados y desplegados mediante `ops/akibara-deploy-runtime.sh`:
     - `akibara-business` v1.120.31 (306 archivos coinciden byte a byte).
     - `akibara-search` v0.1.89 (85 archivos coinciden byte a byte).
     - `akibara-rewards` v0.4.31 (60 archivos coinciden byte a byte).
     - `akibara-cart-checkout-blocks` v2.2.36 (32 archivos coinciden byte a byte).
     - `akibara-gp` v2.1.152 (157 archivos coinciden byte a byte).
   - Sustitución atómica de MU-plugins (`akibara-bluex-suite.php` activo; 7 archivos antiguos eliminados).
3. **Base de Datos y Caché:**
   - 59 registros de `_akb_byp_owned_generation` migrados a `_akb_owned_gen`.
   - Cachés purgadas en WordPress, LiteSpeed Web Server y borde Cloudflare.
   - Zero-downtime, HTTP 200 en endpoints críticos y `debug.log` limpio.
4. **Segundo Cerebro y Grafos:**
   - Graphify AST actualizado (24.074 nodos, 44.542 conexiones, 1.659 comunidades).
   - Bóveda de Obsidian y Google Drive sincronizados para NotebookLM (`npm run sync:notebook`).
