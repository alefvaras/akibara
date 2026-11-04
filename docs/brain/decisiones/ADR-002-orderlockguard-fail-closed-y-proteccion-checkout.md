# ADR-002: OrderLockGuard Fail-Closed y Protección de Pedidos Activos

- **Estado:** Aceptado e Implementado (`akibara-business 1.120.8`)
- **Fecha:** 2026-11-04
- **Contexto:**
  Durante la auditoría adversarial del módulo de Matriz de Precios, se descubrió que:
  1. Si la tabla HPOS `wp_wc_orders` no existía o la base de datos arrojaba un error SQL transitorio, el guard retornaba silenciosamente un array vacío `[]`, permitiendo que procesos en lote modificaran precios de productos vinculados a órdenes pendientes.
  2. Pedidos en estado `wc-pending` dentro de una sesión de checkout activa de 24 horas podían desprotegerse si no se consideraban dentro del lock de seguridad.
  3. Existía un flag `--unprotect-orders` en CLI que permitía eludir la salvaguarda.

- **Decisión:**
  1. **Fail-Closed Estricto:** Si `$wpdb->last_error` no está vacío o la tabla HPOS no existe, el método lanza inmediatamente una excepción de runtime (`\RuntimeException`), deteniendo en seco cualquier mutación de catálogo.
  2. **Ventana de 24 Horas para wc-pending:** Se extiende el lock para incluir órdenes `wc-pending` y `pending` creadas en las últimas 24 horas (`date_created_gmt >= NOW() - INTERVAL 24 HOUR`), protegiendo los checkouts en curso y carritos de clientes mientras pagan por Webpay/MercadoPago.
  3. **Inviolabilidad Total:** Se removió permanentemente el flag `--unprotect-orders` del CLI y se bloqueó en `$protect_orders = true;` en todos los endpoints de administración.

- **Consecuencias:**
  - Cero riesgo de alterar el precio de un cliente durante el proceso de compra.
  - Blindaje transaccional comprobado por pruebas unitarias automatizadas (`OrderLockGuardTest.php`).
