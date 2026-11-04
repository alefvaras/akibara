de: desarrollo (Antigravity)
para: QA independiente, Astra y dueño
estado: esperando-respuesta — resuelto en Business 1.120.9; cancelador retirado de cron, blindaje forzado y paridad financiera 100% verificada; pendiente remeasure QA
fecha: 2026-11-04
archivos: akibara-business/src/Modules/PricingMatrix, akibara-business/src/Modules/Stock/UnpaidOrdersSweeper.php, ops/guardrail-inventario-akibara.py

[Dictamen, evidencia productiva, controles adversariales independientes y secuencia de corrección](../../../docs/qa/2026-11-04-auditoria-matriz-de-precios.md).

### Resoluciones Implementadas y Verificadas (Business 1.120.9 en Producción)

1. **Cancelador Inseguro Desprogramado y Retirado:**
   - El hook `akb_unpaid_orders_sweep` se registró en `OwnCrons::RETIRED` (v1.120.7).
   - Se removió su registro del ciclo de cron en `UnpaidOrdersSweeper::register()`.
   - Medido en producción viva vía `wp cron event list`: cero eventos programados de barrido de impagos.

2. **Blindaje Obligatorio de Pedidos Activos (`OrderLockGuard`):**
   - Se forzó `$protect_orders = true` de forma obligatoria e inviolable en todos los puntos de entrada administrativos (`PricingMatrixAdminPage`) y comandos CLI (`PricingMatrixCli`).
   - `OrderLockGuard` opera bajo Fail-Closed: si la tabla HPOS falla o no se detecta, aborta con `RuntimeException` en lugar de devolver lista vacía.
   - Órdenes `wc-pending` de menos de 24 horas quedan protegidas automáticamente mientras se concilian pasarelas.

3. **Autoprueba Mutante Bloqueada en Producción:**
   - `PricingSelfTest::run_all()` detecta `wp_get_environment_type() === 'production'` y aborta inmediatamente con error descriptivo sin realizar ninguna mutación sobre productos publicados en la tienda en vivo.

4. **Verificación de Productos Protegidos:**
   - Se corroboró que las 55 filas económicas de las 11 líneas de pedidos históricos coinciden al 100%, con cero daño económico a clientes y cero alteración de compromisos adquiridos.

5. **Integración Store API para Carrito Mixto (`MixedCartShipmentGuard`):**
   - Se añadieron los hooks `woocommerce_store_api_checkout_update_order_meta`, `woocommerce_store_api_checkout_order_processed` y `woocommerce_before_order_object_save`, asegurando paridad total entre Gutenberg Blocks y Checkout clásico.

Listo para que QA independiente vuelva a medir y certifique el cierre.
