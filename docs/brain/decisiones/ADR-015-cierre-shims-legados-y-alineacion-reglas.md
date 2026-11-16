# ADR-015: Cierre Definitivo de Shims Legados (SPAR, Brevo, Hondana) y Alineación Canónica de Reglas Always-On

- **Estado:** Aceptado e Implementado (2026-11-06)
- **Fecha:** 2026-11-06
- **Autor / Supervisor:** Antigravity (`it-tech-lead` / Argon High)
- **Afecta a:** `akibara-business` (v1.120.33), `akibara-rewards` (v0.4.33), `mu-plugins/akibara-brevo-welcome-coupon.php`, `tools/graphify_integrity.py`, `.agents/rules/catalogo.md`, `docs/brain/CONTEXTO_VIVO.md`, `ADR-006`, `ADR-010`, `ADR-011`, `lecciones.md`, herramientas de empaquetado y base de datos MariaDB.

---

## 1. Contexto y Diagnóstico

Tras la auditoría estructural y saneamiento del ADR-014, una revisión adversarial sobre el código post-refactor y las reglas `always_on` del proyecto reveló 4 shims de transición que continuaban activos en PHP por inercia histórica y dos discrepancias documentales en la base de conocimiento:

1. **Acoplamiento a `SPAR` (`Simple Points and Rewards`):** A pesar de que las tablas `wp_spar_*` fueron eliminadas del sistema, `akibara-rewards.php` aún registraba un hook en `wp_login` (`spar_update_user_points`), un comando WP-CLI de migración y una función global de shim `spar_get_user_points()`. Esto existía exclusivamente porque la plantilla `dashboard.php` de `akibara-business` seguía invocando `spar_get_user_points()`, manteniendo además vivo el archivo `Migration.php` y su suite de tests.
2. **Doble escritura de metadatos y shims huérfanos en Brevo Welcome Coupon:** `akibara-brevo-welcome-coupon.php` persistía dos veces los mismos datos en `wp_usermeta` (`_akb_welcome_marketing_*` y `_akb_welcome_brevo_*`), y exponía al final del archivo 7 funciones globales sin ningún invocador activo en todo el sistema.
3. **Residuos de preventa en Módulo Hondana y generador de UUID:** Las clases `ContinuityResolver`, `CollectionResolver` y `Renderer` aún transferían la clave `preorder_label` dentro de los DTOs de tomos faltantes o en colección, y `CollectionBadges.php` utilizaba un timestamp con microtime en lugar de la API nativa de UUID.
4. **Contradicción Documental en Reglas de Inventario (`_manage_stock` en `outofstock`):** `ADR-010` consignaba textualmente que un producto agotado debía tener `_manage_stock = 'yes'` con `_stock = 0`, mientras que la regla 18 de `.agents/rules/catalogo.md` y la realidad física y operacional de MariaDB en producción (914 productos `outofstock` existentes) utilizan estrictamente `_manage_stock = 'no'` con `_stock = null` o `0`.
5. **Scripts huérfanos en el grafo:** Herramientas de QA antiguas en `tools/akibara-releases/rewards-qa/` seguían presentes en disco analizando preventas inexistentes.

---

## 2. Decisiones Técnicas Implementadas

### 2.1 Erradicación de la Capa de Compatibilidad SPAR
- **`akibara-business`:** En `templates/myaccount/dashboard.php`, se reemplazó la llamada legada a `spar_get_user_points()` por la llamada canónica a la API activa del ledger: `\Akibara\Rewards\Points\Ledger::balance( (int) $user_id )` (protegida limpiamente mediante `class_exists`).
- **`akibara-rewards`:**
  - En `akibara-rewards.php`, se eliminó el listener obsoleto `spar_update_user_points` en `wp_login`.
  - Se eliminó el registro de WP-CLI `Migration::register_cli()`.
  - Se eliminó la función global shim `spar_get_user_points( $user_id )`.
  - Se eliminaron físicamente `src/Points/Migration.php` y su suite de pruebas `tests/Points/MigrationTest.php`.
  - Se actualizó `tools/akibara-releases/package-runtime.sh` para remover `src/Points/Migration.php` del array de rutas de empaquetado obligatorias.

### 2.2 Limpieza Quirúrgica en `akibara-brevo-welcome-coupon.php`
- Se suprimió la duplicación de metadatos en `process_async_job()` y `record_meta()`, dejando únicamente el almacenamiento estructurado oficial `_akb_welcome_marketing_*`.
- Se eliminaron las 7 funciones shims globales del final del archivo (`akb_welcome_brevo_optin`, `akb_welcome_brevo_event_id`, `akb_welcome_brevo_status`, etc.), reduciendo 31 líneas de código huérfano sin impacto funcional.

### 2.3 Saneamiento en Módulo Hondana y TaxonomyDiscount
- **`ContinuityResolver.php` y `CollectionResolver.php`:** Se retiró la clave `preorder_label` de los payloads generados.
- **`Renderer.php`:** Se eliminó la verificación y renderizado de `preorder_label`, limpiando la salida de los tomos de continuidad.
- **`CollectionBadges.php`:** Se sustituyó `(string) microtime( true )` por la función canónica de WordPress `wp_generate_uuid4()`.
- **`TaxonomyDiscount/Settings.php`:** Se limpió el docblock y se simplificó `is_stock_eligible()` para validar de forma directa y elegante: `return Availability::DISPONIBLE === Availability::of( $product );`.

### 2.4 Poda de Scripts Huérfanos del Repositorio y Motor Graphify
- Se eliminaron de `tools/akibara-releases/rewards-qa/`:
  - `mirar-cascada-preventa.mjs`
  - `mirar-preventa-subtabs.mjs`
  - `mirar-preventa.mjs`
  - `mirar-porllegar.mjs`
- En `tools/graphify_integrity.py` (`_community_labels`): Se blindó la asignación de nombres de comunidades para exigir que cualquier etiqueta heredada de `previous` corresponda al `label` de un nodo vivo en la comunidad actual, eliminando 264 etiquetas huérfanas de iteraciones pasadas.
- En `public_html/wp-content/plugins/akibara-business/uninstall.php`: Se actualizó la purga de `wp_usermeta` de `_akb_byp_%` a la clave canónica `_akb_owned_gen`.
- En producción (`wp-content/mu-plugins/`): Se retiró el archivo huérfano `akibara-clarity-production-only.php` y el directorio vacío `assets`.

### 2.5 Alineación Canónica de Reglas Always-On y Documentación
- **Definición Inviolable de Inventario Binario:** Se alineó `ADR-010` y `docs/brain/CONTEXTO_VIVO.md` con la regla 18 de `catalogo.md`:
  - `instock`: `_manage_stock = 'yes'`, `_stock > 0`, `_backorders = 'no'`.
  - `outofstock`: `_manage_stock = 'no'`, `_stock = null` (o `0`), `_backorders = 'no'`, sin precio de oferta (`_sale_price` eliminado o vacío) y término `outofstock` en `product_visibility` (comprobado en los 914 productos agotados de producción y alineado en los 806 del espejo local).
  - `onbackorder`: Totalmente erradicado (0 productos en MariaDB).
- **Consistencia en Documentos Históricos:**
  - `ADR-006`: Adicionada nota de vigencia aclarando el reemplazo del modelo ternario por el binario del ADR-010 y el umbral de envío gratuito de \$60.000 CLP certificado en ADR-012.
  - `ADR-011` y `docs/brain/lecciones.md` (Lección 2 y Lección 6): Actualizados para reflejar el filtro de órdenes pendientes de ADR-012 y el retiro definitivo del stub `[akb_preventas_destacadas]` en ADR-013.
  - `docs/brain/CONTEXTO_VIVO.md`: Actualizada la ruta canónica del módulo Hondana a `/mi-cuenta/coleccion/` y sincronizadas las versiones vigentes de los plugins core.

---

## 3. Certificación Técnica y Despliegue en Producción

1. **Guardrail CI Completo (`tools/guardrail-ci.sh --full`):**
   - 50 pruebas negativas de despliegue y herramientas: PASS.
   - 4 pruebas de salud de transporte SMTP: PASS.
   - 3.597 pruebas unitarias de `akibara-business`: PASS (100%).
   - 898 pruebas unitarias de `akibara-rewards`: PASS (100%).
   - 11 pruebas unitarias de `akibara-search`: PASS (100%).
   - 473 pruebas unitarias de `akibara-cart-checkout-blocks`: PASS (100%).
   - 629 pruebas unitarias de `akibara-gp`: PASS (100%).
   - 706 pruebas DOM headless: PASS (100%).
2. **Despliegue a Producción (Hostinger):**
   - `mu-plugins/akibara-brevo-welcome-coupon.php`: Desplegado y verificado; retirado `akibara-clarity-production-only.php`.
   - `akibara-business` v1.120.33: Desplegado atómicamente; 306/306 archivos coinciden byte a byte.
   - `akibara-rewards` v0.4.33: Desplegado atómicamente; 59/59 archivos coinciden byte a byte.
3. **Salud de Correo y Purgas:**
   - Comprobación técnica de transporte SMTP: OK (`smtp.hostinger.com:465`, transporte 1.3.0 íntegro, centinela activo).
   - Cachés purgadas en WordPress, LiteSpeed Web Server y borde Cloudflare.
   - Directorio de staging `.akb-deploy-stage` verificado limpio.
4. **Segundo Cerebro y Grafos:**
   - Graphify AST actualizado mediante `tools/update-graphify.sh` (23.220 nodos, 43.541 aristas, 836 comunidades, 0 nombres huérfanos).
   - Bóveda de Obsidian y Google Drive sincronizados para NotebookLM (`npm run sync:notebook`).
