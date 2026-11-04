# ADR-004: Despacho Único Consolidado en Store API, Gutenberg Blocks y Guardado Genérico

- **Estado:** Aceptado e Implementado (`akibara-business 1.120.9`)
- **Fecha:** 2026-11-04
- **Contexto:**
  Akibara opera un modelo de catálogo con tomos disponibles para entrega inmediata en bodega física (`instock`) y tomos "A pedido / Reserva" (`onbackorder`).
  La política de negocio (`BusinessOptions::mixed_cart_single_shipment_policy()`) estipula que los pedidos mixtos se despachan en un envío consolidado único cuando todos los tomos llegan a bodega.
  En el checkout moderno con WooCommerce Blocks (`wp:woocommerce/checkout`), el aviso visual de preventa y despacho consolidado se muestra al cliente en React (`akibara/checkout-preventa-summary`). Sin embargo, el backend `MixedCartShipmentGuard` sólo escuchaba `woocommerce_checkout_create_order`, por lo que las órdenes creadas a través del Store API (`/wc/store/v1/checkout`) no estaban persistiendo los metadatos `_akb_consolidated_shipment_accepted` y `_akb_order_has_backorder` en `wp_wc_orders_meta`.

- **Decisión:**
  Se replica el patrón de 4 superficies establecido en `OrderTagging`:
  1. `woocommerce_checkout_create_order`: Checkout clásico tradicional.
  2. `woocommerce_store_api_checkout_update_order_meta`: Reconciliación en borradores del Store API.
  3. `woocommerce_store_api_checkout_order_processed`: Confirmación final en Checkout Blocks Store API.
  4. `woocommerce_before_order_object_save`: Guardado genérico de `WC_Order` para capturar órdenes manuales de wp-admin, REST o integraciones externas.

  Se incorporan los métodos de introspección directa en la orden:
  - `MixedCartShipmentGuard::order_is_mixed( \WC_Order $order ): bool`
  - `MixedCartShipmentGuard::order_has_backorder( \WC_Order $order ): bool`
  - `MixedCartShipmentGuard::cart_has_backorders(): bool`

- **Consecuencias:**
  - 100% de los pedidos mixtos o con productos a pedido quedan etiquetados con `_akb_consolidated_shipment_accepted = 'yes'` y `_akb_order_has_backorder = 'yes'`.
  - Idempotencia total: no sobreescribe ni ensucia metadatos si el valor ya está presente.
  - Compatibilidad certificada con HPOS (`wp_wc_orders_meta`) y Gutenberg Blocks.
