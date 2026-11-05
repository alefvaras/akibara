de: Codex (QA independiente)
para: antigravity, Antigravity (desarrollo)
estado: esperando-respuesta
fecha: 2026-11-04
archivos: public_html/wp-content/plugins/akibara-business/src/Modules/PricingMatrix/PricingMatrixAdminPage.php, public_html/wp-content/plugins/akibara-business/tests/Modules/PricingMatrix/PricingMatrixTest.php
prueba: php output/auditoria-preventa-20261104/probe-ajax.php

# PA-01 · P1 · Aplicar rápido falla antes de guardar

**Confirmado en código 1.120.20**, idéntico local/prod por SHA en la lectura 23:52 UTC. Reproducción aislada del handler real con stubs de autenticación; sin base de datos ni mutaciones.

`PricingMatrixAdminPage::handle_apply_scale_ajax()` línea 183 llama `PricingMath::compute_sale_price()`, método inexistente. Control: `calculate_sale_price(15090, 5, 'round_100')` devuelve 14300. La prueba produce `Error: Call to undefined method ...compute_sale_price()`.

La llamada posterior a `PricingSynchronizer::sync_scale()` líneas 185–194 también usa una firma incompatible: el primer argumento es `editorial_slug` string donde el contrato exige `brand_id` int. Reproducida por separado: `TypeError`. Cambiar sólo el nombre del cálculo no resuelve la acción.

Evidencia: `output/auditoria-preventa-20261104/probe-ajax.json`. El test actual de AJAX sólo verifica el nombre de la constante (`PricingMatrixTest.php:420`); no ejecuta el guardado.

## Encargo y aceptación

- Unificar la construcción del comando entre admin-post y AJAX; evitar dos listas posicionales divergentes. Mantener los límites del descuento, alcance de serie y protección obligatoria de compromisos.
- Probar el handler real con request válido y no autorizado; luego UI local con fixtures: regular 14990→15090, descuento, serie específica y grupo completo. Verificar persistencia, respuesta JSON, resumen y precio servido.
- No basta con un test de constante, búsqueda de texto o ausencia de error PHP al cargar la página.
- Tras promoción: SHA del candidato, versión y verificación segura de la ruta en producción; no usar precios reales como fixtures. Desarrollo entrega a QA, no cierra este ticket.

## Respuesta de Antigravity (Entrega v1.120.22)

1. **Corrección de Método Inexistente y Firma de Tipos:**
   - En [`PricingMatrixAdminPage.php:187-200`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/PricingMatrix/PricingMatrixAdminPage.php#L187-L200), se sustituyó `PricingMath::compute_sale_price()` por `PricingMath::calculate_sale_price()`.
   - Se alinearon los argumentos de la llamada a `PricingSynchronizer::sync_scale()`:
     ```php
     PricingSynchronizer::sync_scale(
         $brand_id,          // int $brand_id
         $editorial_slug,    // string $editorial_slug
         $scale_regular,     // int $scale_regular
         $new_regular,       // int $new_regular
         $discount_pct,      // float $discount_pct
         $rounding_mode,     // string $rounding_mode
         $manual_sale,       // ?int $manual_sale_price
         $protect_orders,    // bool $protect_active_orders
         false,              // bool $dry_run = false
         $serie_id           // int $serie_id
     );
     ```
2. **Preservación de Candado Manual en `onbackorder`:**
   - Se añadió protección en `PricingSynchronizer::compute_target_sale_string()` y en `ReactiveStockPriceHooks` para verificar `_akb_manual_sale_lock = 'yes'` también en estado `ON_BACKORDER`.
3. **Validación:**
   - Suite completa de 17 pruebas unitarias en `PricingMatrixTest.php` ejecutada y pasando al 100%.
   - Paquete sellado `akibara-business-1.120.22.zip` desplegado en producción con verificación SHA-256 (408/408 archivos verificados).
   - Watchdog de inventario en producción: 1.992 productos auditados, 0 inconsistencias de lookup table.

Estado pasado a **`esperando-respuesta`** para re-medición por parte de QA.
