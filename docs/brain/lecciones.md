# Registro de Lecciones Aprendidas de Ingeniería (Akibara)

Este registro documenta errores reales encontrados y resueltos en producción para evitar regresiones.

---

### Lección 1: Nunca depender de la distribución de datos como especificación
- **Problema:** En varias ocasiones, agentes asumieron reglas de negocio al ver valores comunes en la base de datos (por ejemplo, que todos los tomos con `onbackorder` debían tener `notify`), cuando en realidad WooCommerce restablece `notify` a `no` al guardar productos sin gestión de stock.
- **Regla:** Si una regla no está escrita explícitamente en la documentación o código de negocio, no deducirla a ciegas: formular la propuesta técnica al dueño.

### Lección 2: WooCommerce gobierna el inventario y los precios de forma 100% nativa (Cero bloqueos)
- **Regla Oficial del Dueño:** WooCommerce gobierna el inventario y los precios de forma 100% nativa: el código de Akibara jamás fuerza `_manage_stock`, `_backorders`, `_stock` ni borra `_sale_price`. Se erradicó por completo `OrderLockGuard` y cualquier bloqueo de 24h/2h por órdenes `wc-pending`. El dueño de la tienda puede actualizar el precio regular o de oferta de cualquier producto en cualquier momento sin bloqueos.
- **Inmutabilidad de Órdenes:** Las órdenes de compra conservan snapshots inmutables en `wp_woocommerce_order_items` y `wp_woocommerce_order_itemmeta` generados nativamente por WooCommerce al momento del checkout. Actualizar precios o inventario en el catálogo nunca altera órdenes creadas.

### Lección 3: Limpieza y respaldos en producción
- **Problema:** Dejar archivos de respaldo (`.bak`, `.zip`, `.tar.gz`) o volcados de opciones (`akb_*_backup`) en el servidor remoto expone vectores de seguridad, ensucia el filesystem y puede provocar desajustes de almacenamiento.
- **Regla:** Los respaldos y volcados se descargan inmediatamente a la máquina local y se purgan del servidor remoto antes de cerrar cualquier tarea.

### Lección 4: Espejo Local vs Producción
- **Problema:** El espejo Docker local difiere en latencias de red, cola de Action Scheduler, cron de 5 minutos, caché de Cloudflare y LiteSpeed.
- **Regla:** Lo probado en local es sólo la primera mitad. Todo despliegue DEBE ser verificado directamente en producción con mediciones del mundo real (HTML servido, headers, correo saliente, checkout).

### Lección 5: Monolingüe es-CL Estricto (Sin candados restrictivos de I18nPolicyTest)
- **Principio:** Akibara opera en español de Chile (`es-CL`). Se erradicó el test de sobreingeniería `I18nPolicyTest` y sus candados artificiales contra funciones nativas de WordPress como `_n()`.
- **Regla:** Mantener textos directos y naturales en español sin candados innecesarios sobre helpers de traducción de la plataforma.

### Lección 6: Neutralización Defensiva de Shortcodes Retirados y Cierre Definitivo
- **Problema:** Al erradicar funcionalidades legadas (ej. preventas y «a pedido» bajo ADR-010), si se retira el shortcode de PHP pero una página, revisión histórica o borrador en base de datos aún conserva el tag (ej. `[akb_preventas_destacadas ...]`), WordPress no lo procesa y expone el tag crudo como texto visible para los clientes en la portada.
- **Regla en Transición:** Al desincorporar cualquier shortcode del runtime, mientras persistan revisiones o páginas con el tag, registrar temporalmente un stub silencioso: `add_shortcode( 'shortcode_legado', '__return_empty_string' );`.
- **Cierre Definitivo (ADR-013):** Una vez ejecutada la auditoría SQL exhaustiva y saneado el 100% de los posts, páginas y revisiones en la base de datos (0 referencias vivas), el stub debe ser retirado definitivamente para no mantener código zombie en el sistema.

### Lección 7: Carencia en Recompensas y Prevención de Arbitraje
- **Problema:** En programas de fidelidad con acumulación inmediata, un comprador puede pagar un pedido, recibir puntos en el acto, canjearlos en una segunda compra y posteriormente devolver la primera compra, generando arbitraje financiero o saldos truncados.
- **Solución:** Los puntos calculados por compra deben asentarse en estado retenido (`pending`) en los metadatos del pedido. Al transicionar a completado (`wc-completed`), Action Scheduler coordina la liberación a saldo disponible a los 7 días exactos. Ante cancelaciones o reembolsos dentro de la ventana de carencia, los puntos pendientes se revocan o ajustan sin aplicar débitos al saldo disponible legítimo previo del cliente.
