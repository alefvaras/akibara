# Registro de Lecciones Aprendidas de Ingeniería (Akibara)

Este registro documenta errores reales encontrados y resueltos en producción para evitar regresiones.

---

### Lección 1: Nunca depender de la distribución de datos como especificación
- **Problema:** En varias ocasiones, agentes asumieron reglas de negocio al ver valores comunes en la base de datos (por ejemplo, que todos los tomos con `onbackorder` debían tener `notify`), cuando en realidad WooCommerce restablece `notify` a `no` al guardar productos sin gestión de stock.
- **Regla:** Si una regla no está escrita explícitamente en la documentación o código de negocio, no deducirla a ciegas: formular la propuesta técnica al dueño.

### Lección 2: Inmutabilidad estricta de compromisos de clientes (FIFO)
- **Problema:** Un barrido masivo de precios o estados de inventario nunca debe alterar líneas de órdenes ya pagadas o pedidos pendientes. La relación contractual con el cliente al momento de la compra es sagrada.
- **Solución:** `OrderLockGuard` bloquea automáticamente cualquier post que pertenezca a órdenes activas (`wc-processing`, `wc-on-hold`, `wc-completed`, y `wc-pending` de las últimas 24h).

### Lección 3: Limpieza y respaldos en producción
- **Problema:** Dejar archivos de respaldo (`.bak`, `.zip`, `.tar.gz`) o volcados de opciones (`akb_*_backup`) en el servidor remoto expone vectores de seguridad, ensucia el filesystem y puede provocar desajustes de almacenamiento.
- **Regla:** Los respaldos y volcados se descargan inmediatamente a la máquina local y se purgan del servidor remoto antes de cerrar cualquier tarea.

### Lección 4: Espejo Local vs Producción
- **Problema:** El espejo Docker local difiere en latencias de red, cola de Action Scheduler, cron de 5 minutos, caché de Cloudflare y LiteSpeed.
- **Regla:** Lo probado en local es sólo la primera mitad. Todo despliegue DEBE ser verificado directamente en producción con mediciones del mundo real (HTML servido, headers, correo saliente, checkout).

### Lección 5: Monolingüe es-CL Estricto
- **Problema:** Usar llamadas como `__('Texto', 'dominio')` en clases sin inicialización completa de gettext o en flujos donde se exige consistencia provoca fallos en pruebas de `I18nPolicyTest`. Akibara opera exclusivamente en español de Chile (`es-CL`).
- **Regla:** Mantener textos directos en español limpio sin macros innecesarias cuando no aplique internacionalización dinámica.
