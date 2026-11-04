# ADR-009: Coherencia de Proyecciones WooCommerce Lookup, Fail-Closed de Base de Datos y UX de Revisión Persistente

- **Estado:** Aceptado, Implementado y Desplegado en Producción (`akibara-business 1.120.11`)
- **Fecha:** 2026-11-04
- **Autor / Supervisor:** Antigravity / Astra / Dueño
- **Visto bueno de QA:** En espera de re-medición independiente (`ops/coordinacion/abiertos/2026-11-04-auditoria-matriz-y-cancelacion-impagos.md`)

---

## 1. Contexto y Diagnóstico Forense

La segunda auditoría adversarial de Business 1.120.10 reveló cuatro encargos críticos en producción y experiencia de usuario:

1. **Desfase en la Tabla de Búsqueda de WooCommerce (`wp_wc_product_meta_lookup`):**
   - Aunque `ops/alinear-inventario-y-precios.php` actualizó correctamente `wp_postmeta` para 91 productos de catálogo, la escritura directa SQL no disparó el hook `update_lookup_table()` de WooCommerce.
   - A su vez, el reparador nativo de WooCommerce `wc_update_product_lookup_tables_column('stock_quantity')` tiene un filtro interno `WHERE meta1.meta_value = 'yes'` que omite deliberadamente los productos con `_manage_stock = 'no'`.
   - Como resultado, 137 productos no gestionados conservaban cantidades residuales (`-1`, `0`, `1`) en `wp_wc_product_meta_lookup`.
   - En particular, Bleach Remix 01 (ID 12997) conservaba `min_price = 28025` y `stock_quantity = -1`, provocando que Store API lo excluyera al filtrar por `max_price=28000` y solo apareciera al elevar el filtro a `max_price=28025`.

2. **Vulnerabilidad Fail-Open en `OrderLockGuard`:**
   - La consulta `$wpdb->get_results()` en WordPress ante un error grave de MySQL puede retornar un array vacío (`array()`) mientras asigna el mensaje de fallo en `$wpdb->last_error`.
   - `OrderLockGuard` solo comprobaba `false === $rows`, lo que permitía que un fallo de base de datos devolviera cero productos protegidos sin lanzar excepción (Fail-Open), arriesgando la modificación accidental de productos comprometidos en órdenes activas.

3. **Trazabilidad Incompleta y Validación de Proveedores en Rescate (`PricingSynchronizer`):**
   - El rescate de catálogo admitía nombres arbitrarios de distribuidores y no capturaba al operador en `_akb_rescue_by` (ADR-003).

4. **Fricción Operativa en la Interfaz de la Matriz (`PricingMatrixAdminPage`):**
   - Tras simular una escala (ej. Arechi de $14.990 a $15.090), la recarga borraba el valor propuesto y el aviso solo informaba "24 afectados", obligando al dueño a escribir de nuevo el precio para poder aplicar.
   - Existía una casilla desmarcable engañosa ("Proteger reservas") que el servidor forzaba internamente.
   - El buscador de la pestaña de rescates no admitía pegar el ID con almohadilla (`#30502`).
   - La tabla desbordaba en pantallas móviles (390 px) requiriendo más de 900 px de desplazamiento horizontal.

---

## 2. Decisiones Técnicas Implementadas

### A. Coherencia Productiva de `wp_wc_product_meta_lookup`
1. Sincronización masiva de precios en la tabla lookup mediante `wc_update_product_lookup_tables_column('min_max_price')` en producción viva, reduciendo las 91 discrepancias a **0**.
2. Corrección del defecto de WooCommerce para stock unmanaged:
   ```sql
   UPDATE wp_wc_product_meta_lookup lookup
   JOIN wp_postmeta meta ON lookup.product_id = meta.post_id 
     AND meta.meta_key = '_manage_stock' 
     AND meta.meta_value = 'no'
   SET lookup.stock_quantity = NULL
   WHERE lookup.stock_quantity IS NOT NULL;
   ```
   137 filas corregidas en producción viva. Discrepancias restantes: **0**.
3. Incorporación de la Fase 5 en [`ops/alinear-inventario-y-precios.php`](file:///Users/alefvaras/Documents/akibara/ops/alinear-inventario-y-precios.php) para automatizar ambas reparaciones en cualquier ejecución futura.
4. **Inmutabilidad de Órdenes:** Las tablas `woocommerce_order_items` y `order_itemmeta` permanecieron 100% de solo lectura. Las 5 cantidades históricas (-1) de pedidos reales se respetan para despacho FIFO.

### B. Fail-Closed Estricto en `OrderLockGuard`
Se blindaron los métodos de consulta:
```php
if ( ! empty( $wpdb->last_error ) || false === $rows ) {
    throw new \RuntimeException( 'Error al consultar órdenes bloqueadas en la base de datos: ' . $wpdb->last_error );
}
```
Si `$wpdb` reporta cualquier error, el sistema aborta inmediatamente impidiendo cualquier mutación de catálogo. Cubierto con tests unitarios en `PricingMatrixTest.php`.

### C. Restricción Canónica de Rescate en `PricingSynchronizer`
1. Lista blanca cerrada `APPROVED_SUPPLIERS`: `candela`, `pujol-amado`, `milky-way`, `panini-espana`, `ivrea-espana`, `arechi`, `distrito-manga`, `devir`. Proveedores no reconocidos lanzan `InvalidArgumentException`.
2. Registro obligatorio del operador en `_akb_rescue_by` junto a fecha, referencia y proveedor.
3. Exclusión defensiva de productos sin SKU (`skipped_no_sku`).
4. Re-evaluación en vivo de pedidos concurrentes dentro del bucle de escritura para mitigar condiciones de carrera.
5. Eliminación de la tolerancia de redondeo exacto al peso en `ops/guardrail-inventario-akibara.py`, forzando `round_100` estricto (`expected_sale_100`).

### D. Rediseño de UX y Resiliencia Móvil en `PricingMatrixAdminPage`
1. **Tarjeta de Revisión Persistente (`.akb-pm-proposal-review`):** Tras simular, se despliega una tarjeta destacada con el desglose de afectados (24), omitidos (0), tabla antes/después y botón directo `[Confirmar y Aplicar a los 24 tomos revisados]` con payload precargado sin requerir reescritura.
2. **Preservación de Borrador:** En la fila de la escala, el valor `$15.090` se mantiene en el campo `new_regular` y se resalta con el badge `Propuesta simulada`.
3. **Protección Transparente:** Sustitución de casillas desmarcables por el indicador informativo fijo `🔒 Pedidos protegidos` con `<input type="hidden" name="protect_active_orders" value="1" />`.
4. **Búsqueda por `#ID`:** `render_tab_rescue()` indexa `#' . $item['product_id']` para admitir copiar y pegar identificadores con formato `#30502`.
5. **Accesibilidad y Móvil (390 px):** Identificadores únicos, etiquetas semánticas `<label for="...">` y reglas `@media (max-width: 600px)` en [`pricing-matrix-admin.css`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/assets/css/pricing-matrix-admin.css) con layout vertical y áreas de pulsación mínimas de 40-44 px.

---

## 3. Verificación y Resultados Medidos en Producción

- **Store API en Vivo (`akibara.cl`):**
  - `/wc/store/v1/products?search=Bleach%20Remix%2001&max_price=28000` $\rightarrow$ ID 12997 (Presente).
  - `/wc/store/v1/products?search=Bleach%20Remix%2001&max_price=27999` $\rightarrow$ Vacío (Filtrado exacto).
- **Lookup Table:** ID 12997 con `min_price = 28000`, `max_price = 28000`, `stock_quantity = NULL`, `stock_status = 'onbackorder'`.
- **Suites de Pruebas:**
  - PHPUnit `akibara-business`: **4.642/4.642 tests (100% OK)**.
  - JavaScript suite: **751/751 tests (100% OK)**.
  - Gates de entrega y pruebas negativas: **50/50 tests (100% OK)**.
  - Watchdog de inventario en vivo: **[APROBADO] 0 anomalías en 1.992 productos**.
- **Versión Runtime Desplegada:** `akibara-business 1.120.11` activa en producción sin archivos temporales ni respaldos remotos.
