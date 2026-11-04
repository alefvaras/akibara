# ADR-008: Alineación Canónica de Precios A Pedido y Blindaje de Contrato de Inventario (Regla 18)

- **Estado:** Aceptado, Implementado y Medido en Producción
- **Fecha:** 2026-11-04
- **Autor / Supervisor:** Astra / Antigravity
- **Aprobación del Dueño:** Orden expresa del 2026-11-04 ("diferenciar esos tomos que están a pedidos no se modifica el precio porque están pagados ya en estado procesado pero de aquí en adelante sí cambiar").

## Contexto y Diagnóstico Forense
1. **Diferenciación entre Historial de Pedidos y Catálogo Público:**
   - La **Regla 18** protege de forma absoluta las órdenes comprometidas (`processing`, `on-hold`) y sus líneas contables (`wp_woocommerce_order_items` y `order_itemmeta`).
   - Anteriormente, el script de sincronización de precios (`PricingSynchronizer`) omitía actualizar el precio del producto en el catálogo si dicho producto pertenecía a cualquier orden activa. Esto provocó que 91 productos en el catálogo público continuaran mostrando precios sin redondear a la centena (ej. $14.240 en vez de $14.200, $28.025 en vez de $28.000, $10.070 en vez de $10.100, y Ranma 05 sin oferta de reserva).
   - El dueño aclaró el requerimiento de negocio: **los pedidos pagados y procesados jamás se tocan (su total pagado es inmutable), pero el catálogo público para futuras compras DEBE actualizarse al 5% con `round_100`**.

2. **Contrato de Inventario para Productos A Pedido (`onbackorder`):**
   - El 90.2% del catálogo A Pedido (700 productos) operaba con el modelo canónico de Akibara: `_manage_stock = 'no'`, `_stock = null`, `_backorders = 'no'`.
   - 76 productos históricos conservaban residuos de importaciones pasadas (`manage_stock = 'yes'` con stock 0 y `backorders = 'notify'`).
   - Activar la gestión numérica de stock (`manage_stock = 'yes'`) en productos A Pedido provocaba números negativos al venderse, confusión en recepciones de bodega y rechazos de feeds en Google Merchant Center.

3. **Blindaje de Stock Físico y Agotados:**
   - 16 tomos en stock físico (bodega Santiago) tenían `backorders = 'yes'`, arriesgando sobreventa descontrolada al agotarse las unidades físicas.
   - 36 tomos agotados conservaban `manage_stock = 'yes'` tras agotarse a 0.

## Decisión Técnica Implementada
Se implementó y ejecutó el script [`ops/alinear-inventario-y-precios.php`](file:///Users/alefvaras/Documents/akibara/ops/alinear-inventario-y-precios.php):
1. **Fase 1 (Precios de Catálogo A Pedido):** Se actualizaron 91 productos en `wp_postmeta` (`_sale_price` y `_price`) al 5% con `round_100` exacto ($14.200, $28.000, $10.100, $20.400). Cero modificaciones a las tablas de órdenes.
2. **Fase 2 (Contrato A Pedido):** Se alinearon 76 productos a `_manage_stock = 'no'`, `_backorders = 'no'` y `_stock = null`.
3. **Fase 3 (Blindaje de Stock Físico):** Se blindaron 16 productos en stock físico fijando `_backorders = 'no'`, garantizando que al venderse la última unidad física pasen automáticamente a `outofstock` sin sobreventa.
4. **Fase 4 (Blindaje de Agotados):** Se blindaron 36 productos agotados a `_manage_stock = 'no'`, `_backorders = 'no'`, `_stock = null` y supresión de `_sale_price`.

## Medición y Validación en Vivo
- **Entorno Local Docker:** Ensayado y verificado primero en local.
- **Producción:** Ejecutado en vivo.
- **Watchdog `guardrail-inventario-akibara.py` en Producción:**
  - `Total productos auditados: 1992`
  - `• En Stock (físico en tienda): 1078`
  - `• A Pedido / Reserva (distribuidor): 776`
  - `• Agotados: 138`
  - **`[APROBADO] Inventario medido: se cumplen las reglas verificadas. (Exit Code 0)`**
- **Cachés:** Purgado total en Cloudflare y LiteSpeed.
- **Buscador:** `akibara-search reconcile` ejecutado (0 desajustes, 0 divergencias).
