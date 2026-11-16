# ADR-012: Optimizaciones Financieras, Carencia de 7 Días en Rewards, Paridad de Envío Gratis y Certificación Batch ATC en Colección

- **Estado:** Aceptado e Implementado en Producción (2026-11-06)
- **Fecha:** 2026-11-06
- **Autor / Supervisor:** Antigravity (`it-tech-lead`)
- **Afecta a:** `akibara-business` (v1.120.29), `akibara-rewards` (v0.4.29), Colección Hondana, Checkout y Matriz de Precios.

---

## 1. Contexto y Objetivos

En el marco de la simplificación arquitectónica y optimización financiera del ecosistema Akibara, se implementaron cuatro directivas clave orientadas a proteger la caja, erradicar arbitrajes de puntos y garantizar fluidez en la compra por lotes:

1. **Protección Liviana de Precios en Matriz (`PricingMatrix`):**
   Las órdenes pagadas y cerradas en WooCommerce conservan snapshots inmutables en `wp_woocommerce_order_items` y `wp_woocommerce_order_itemmeta`. El único riesgo real al actualizar precios de catálogo en `wp_postmeta` es mutar el valor que un cliente está tramitando activamente en la pasarela. Se requería evitar dependencias pesadas de guardrails globales cuando un filtro enfocado a pedidos pendientes recientes (`wc-pending` dentro de 24 horas) resuelve el problema con exactitud.

2. **Paridad de Envío Gratis vs. Cupones Porcentuales (`popup10`):**
   Al aplicar cupones de descuento como el 10% de bienvenida (`popup10`), el subtotal monetario de productos podía caer temporalmente por debajo del umbral de \$60.000 CLP, penalizando involuntariamente al cliente al agregarle costos de flete Blue Express. Se requería asegurar que la calificación de flete gratuito evalúe el subtotal de productos **previo a los cupones** (`requires => 'either'` con `ignore_discounts => 'yes'`), en paridad absoluta con `Redemption::preserve_free_shipping()`.

3. **Periodo de Carencia de Puntos (7 Días en `Accrual.php`):**
   Para prevenir arbitrajes donde un cliente acumula puntos por una compra, los canjea inmediatamente en un segundo pedido y posteriormente solicita la devolución del primer pedido, los puntos ganados deben retenerse en estado `pending` durante 7 días exactos tras completarse el pedido (`woocommerce_order_status_completed`). La liberación se coordina vía Action Scheduler, y ante anulaciones o reembolsos dentro de la ventana de carencia, los puntos retenidos se ajustan o revocan sin impactar el saldo disponible previo del usuario.

4. **Auditoría y Certificación de "Batch Add To Cart" (Colección Hondana):**
   El módulo de estantería `AccountCollection` permite al coleccionista añadir en un solo clic todos los tomos faltantes disponibles en bodega de una serie. Se requería certificar que la transacción en backend invoque `WC()->cart->calculate_totals()` exactamente una sola vez al final del lote, despache los eventos `wc_fragment_refresh` y `akb:cart:updated`, y active el drawer lateral `.akb-cart-trigger` sin recarga de página.

---

## 2. Decisiones Técnicas y Arquitectura

### 2.1 Filtro Ligero de Pedidos Pendientes en `PricingSynchronizer`
- Se implementó `PricingSynchronizer::get_recent_pending_orders_map()`, el cual consulta con índice y soporte dual (HPOS nativo `wp_wc_orders` y fallback `wp_posts`) las órdenes creadas en las últimas 24 horas con estado `wc-pending` o `pending`.
- Si un `product_id` figura en este mapa, los métodos `apply_product_price()`, `apply_scale_to_brand()` y `apply_scale_to_serie()` omiten la actualización de precios con log explícito, garantizando cero modificaciones a las órdenes en curso y manteniendo inmutables los ítems históricos.

### 2.2 Evaluación Pre-Cupón en Envío Gratis
- En `FreeShippingThreshold::carrier_fallback_for_enabled_methods()`, el método de envío declara formalmente `requires => 'either'` e `ignore_discounts => 'yes'`.
- En `FreeShippingProgress::filter_free_shipping_is_available()`, se intercepta el filtro nativo de WooCommerce para comparar `$cart->get_subtotal() + $cart->get_subtotal_tax()` contra `$threshold` (\$60.000 CLP).
- Se certificó paridad estricta con `Redemption::preserve_free_shipping()`, de modo que ni los cupones de descuento ni los canjes de puntos priven al cliente del beneficio de envío gratis pactado sobre el catálogo.

### 2.3 Periodo de Carencia (Escrow) de 7 Días vía Action Scheduler
- En `Options::escrow_days()` se configuró la ventana canónica de 7 días (ajustable en `SettingsPage` de 0 a 60 días).
- En `Accrual.php`:
  - Al recibir una compra en `processing`, los puntos calculados se asientan en el meta `_akb_rewards_points_pending` con estado `'pending'`, sin pasar al libro mayor (`Ledger`).
  - Al transicionar el pedido a `completed`, `maybe_schedule_escrow_release()` agenda una acción única con el hook `akb_rewards_release_accrual_escrow` para ejecutarse en `time() + 7 * DAY_IN_SECONDS`.
  - Al cumplirse el plazo, `release_escrow()` toma el candado reentrante del pedido, acredita los puntos en el `Ledger`, marca el meta como `'released'` y limpia la retención.
  - Si el pedido se cancela o reembolsa durante la carencia, `deduct_all_locked()` y `on_order_refunded()` desprograman el job y revocan o ajustan únicamente los puntos pendientes, protegiendo al 100% el saldo previo del cliente.
  - El hook fue registrado en `QueueHooks::ALL` y `uninstall.php` para cumplir la política de desinstalación limpia.

### 2.4 Transacción Atómica y Eventos en Colección Hondana
- En `Endpoint::ajax_batch_add_to_cart()`, se validó que los productos se agreguen en un bucle secuencial eficiente y que `WC()->cart->calculate_totals()` se ejecute exclusivamente al final.
- En `account-collection.js`, se implementó el despacho de los eventos `wc_fragment_refresh`, `akb:cart:updated` y `added_to_cart`, seguido de la apertura automática del drawer de carrito (`.akb-cart-trigger`).
- Se amplió la suite headless en `account-collection-dom.test.js` utilizando `tools/testing/dom-harness.js` para certificar el flujo completo de cliente.

---

## 3. Verificación y Despliegue en Producción

1. **Suites de Pruebas Automatizadas:**
   - `akibara-business`: 3.606 tests pasados (11.171 aserciones).
   - `akibara-rewards`: 908 tests pasados (7.806 aserciones).
   - `akibara-search`: 900 tests pasados (5.069 aserciones).
   - `tema akibara-gp`: 706 tests pasados.
   - `DOM Test Harness (AccountCollection)`: 10 tests pasados.
   - Pipeline CI `npm test`: 6 estadios en verde.

2. **Promoción a Producción:**
   - Paquetes sellados y verificados: `akibara-business-1.120.29.zip` (307 archivos) y `akibara-rewards-0.4.29.zip` (60 archivos).
   - Despliegue ejecutado vía `ops/akibara-deploy-runtime.sh` con respaldo previo local, verificación SHA-256 remota, cambio atómico bajo `.maintenance`, purga de LiteSpeed Cache y validación de claves de acceso y salud SMTP.
   - Paridad certificada con `tools/akibara-releases/rewards-qa/qa-paridad-espejo.mjs`.
   - Watchdog de inventario (`ops/guardrail-inventario-akibara.py --target prod`) en 100% de cumplimiento (0 en backorder).
