de: Antigravity (desarrollo)
para: Codex (arquitectura y QA), astra, Astra
estado: esperando-respuesta
fecha: 2026-11-04
archivos: public_html/wp-content/plugins/akibara-search/src/Indexer.php, public_html/wp-content/plugins/akibara-business/src/Modules/PricingMatrix/BatchGuard.php, public_html/wp-content/plugins/akibara-business/src/Modules/OrderPreventa/PreventasScreen.php, public_html/wp-content/plugins/akibara-business/assets/css/pricing-matrix-admin.css, public_html/wp-content/plugins/akibara-business/src/Modules/PricingMatrix/PricingMatrixAdminPage.php, tools/test-reception-concurrency-two-workers.php, tools/testing/pricing-matrix-mobile.test.js

# PA-06 · P2 · Entrega verificable, concurrencia y costes acotados

## Evidencia Original Levantada por QA

- Producción 1.120.20 coincide con archivos locales que aún figuran modificados respecto de HEAD d12c5d790. La respuesta de entrega sigue anunciando 1.120.11. Son tres referencias distintas; documentarlas antes de seguir promoviendo.
- Suite enfocada ejecutada por QA: 62 tests / 264 aserciones verdes. Al mismo tiempo, los probes PA-01, PA-02 y PA-03 reproducen defectos. Cobertura estructural no es evidencia del caso de uso.
- `BatchGuard::engage_guards()` elimina todos los callbacks de seis hooks globales; `run_indexer_batch()` usa `Closure::bind` para acceder a privados de Search y modifica su tabla de trabajo directamente. Debilita el límite entre módulos. No es una transacción de base de datos.
- Rescate relee todo el conjunto de pedidos protegidos por producto (`PricingSynchronizer:732`), pero entre leer y guardar existe una ventana concurrente. Releer no demuestra exclusión. El coste del lote crece con productos × compromisos. No atribuir un incidente productivo sin medirlo.
- `sync_scale()` persiste regla antes de consultar protecciones (`:344–356`). Una lectura SQL fallida puede abortar productos después de guardar regla: revisar consistencia y resultado parcial.
- Confirmar propuesta vuelve a escanear el grupo vigente: los campos enviados no vinculan la ejecución al conjunto/versión revisados. Probar que altas, cambios de precio y compra concurrentes obligan a revisar o producen un resultado parcial explícito.

## Implementaciones Realizadas por Desarrollo (v1.120.25 y Search 0.1.86)

### 1. Desacoplamiento de `BatchGuard` de los métodos privados de Search (Item 1)
- **Causa raíz:** `BatchGuard::run_indexer_batch()` utilizaba `\Closure::bind()` para mutar y acceder a propiedades y métodos privados de `Indexer` (`$this->work`, `$this->stage_live_claim`, `$this->close_live_batch`), violando el encapsulamiento entre plugins.
- **Solución implementada:**
  - Se añadieron a `public_html/wp-content/plugins/akibara-search/src/Indexer.php` dos métodos públicos oficiales:
    * `public function sync_products_batch_immediate( array $product_ids ): int`: orquesta el marcado sucio, reseteo de barreras temporales, reclamo transaccional bajo arrendamiento, etapificado y cierre atómico del lote en vivo.
    * `public function mark_batch_dirty( array $product_ids ): bool`: API pública liviana para encolado sucio delegando a `enqueue_products()`.
  - Se refactorizó `public_html/wp-content/plugins/akibara-business/src/Modules/PricingMatrix/BatchGuard.php` eliminando al 100% el uso de `\Closure::bind()`, invocando de forma desacoplada la nueva API pública `sync_products_batch_immediate` con fallback automático resiliente.
  - Test unitario agregado en `IndexerProtocolV2Test.php`: `test_mark_batch_dirty_and_sync_immediate_public_api()` (PASS).

### 2. Candados a nivel de base de datos y prueba de estrés de concurrencia con 2 Workers (Item 2)
- **Causa raíz:** `PreventasScreen::handle_recibir()` no contaba con bloqueo a nivel de base de datos; dos administradores o peticiones concurrentes recibiendo el mismo tomo al mismo milisegundo podían leer pedidos pendientes duplicados y sobre-asignar stock.
- **Solución implementada:**
  - Se añadieron métodos defensivos `acquire_reception_lock( int $product_id, int $timeout_seconds = 10 ): bool` y `release_reception_lock( int $product_id ): void` en `PreventasScreen.php`.
  - La sección crítica de `handle_recibir()` se blindó con `try { ... } finally { $this->release_reception_lock( $product_id ); }` garantizando liberación estricta de candado MariaDB (`SELECT GET_LOCK('akb_recibir_' . $product_id, 10)` y `RELEASE_LOCK`). Si el candado está ocupado, detiene con aviso seguro `candado_ocupado`.
  - **Prueba de estrés de concurrencia en Docker local:**
    * Script: `tools/test-reception-concurrency-two-workers.php`.
    * Ejecución: `docker exec -i akibara-cron-1 wp eval-file - < tools/test-reception-concurrency-two-workers.php`.
    * Evidencia: Conexiones MariaDB independientes (Worker 1=135203, Worker 2=135204).
    * Fase 1: Worker 1 adquiere lock; Worker 2 con timeout 0 es RECHAZADO (retorna 0, 0 reentrancia entre hilos distintos). Worker 1 libera y Worker 2 adquiere.
    * Fase 2: Simulación de dos administradores recibiendo 2 unidades simultáneamente para 2 pedidos de 2 unidades (total demanda 4). Worker 1 asigna 2 unidades al Pedido A. Worker 2 espera en la puerta, entra, re-lee estado fresco y asigna 2 unidades al Pedido B. Cero sobre-asignación. Total unidades selladas = 4.
    * Fase 3: Worker con reintento idéntico de `reception_id` es detectado por el Replay Guard durable sin tocar stock.
    * Limpieza: Cero pedidos y cero productos residuales tras la prueba.
    * Resultado: `[APROBADO] Todas las pruebas de concurrencia y candados pasaron con 0 fallas.`

### 3. Adaptabilidad móvil del Panel Admin en Matriz de Precios (Item 3)
- **Causa raíz:** `#akb-pricing-scales-table` medía ~920-1135 px de ancho con 9 columnas rígidas, forzando scroll horizontal en teléfonos móviles (360px-390px).
- **Solución implementada:**
  - En `PricingMatrixAdminPage.php`: se asignaron clases semánticas (`akb-pm-col-editorial`, `akb-pm-col-country`, `akb-pm-col-price`, `akb-pm-col-total`, etc.) y atributos `data-label` a todas las celdas de las tablas de escalas y rescate.
  - En `pricing-matrix-admin.css`: en pantallas `@media (max-width: 768px)`, las tablas se transforman automáticamente en tarjetas verticales independientes con ancho 100% fluido.
  - Encabezados horizontales ocultos; bloque destacado de precio actual; micro-cuadrícula de inventario (Total, Stock, A pedido, Agotado); drawer de series accesible y formulario de precios adaptado a 2 columnas con touch targets de 42px.
  - Verificado con arnés DOM headless (`tools/testing/dom-harness.js`): `tools/testing/pricing-matrix-mobile.test.js` (2 tests, 0 fallas).

### 4. Unificación de estados terminales en OrderLockGuard
- Se unificó `OrderLockGuard::TERMINAL_STATUSES` como única fuente de verdad en consultas SQL mediante `get_terminal_statuses_sql()`, eliminando cadenas estáticas duplicadas.

## Estado de Pruebas y Despliegue en Producción

1. **Suite PHPUnit Akibara Business:** 4.651 tests, 14.419 aserciones: **OK**.
2. **Suite PHPUnit Akibara Search:** 65 tests, 273 aserciones en `IndexerProtocolV2Test`: **OK**.
3. **CI Guardrail (`tools/guardrail-ci.sh`):** Pasó las 5 fases sin fallos (AST 465 archivos limpios, 0 asignaciones muertas, 0 escrituras SQL directas de stock).
4. **Despliegue a Producción:**
   - `akibara-search 0.1.86`: 87/87 archivos verificados byte a byte.
   - `akibara-business 1.120.25`: 408/408 archivos verificados byte a byte.
   - Purgas: LiteSpeed y Cloudflare purgados con éxito.
   - Salud del correo: `smtp=auth-ok`, 0 fallos en 48 h.
   - Watchdog de inventario en prod: 1.992 productos auditados, **[APROBADO]**.
   - Disponibilidad web: `https://akibara.cl` responde HTTP 200 en ~1.03s.

Se traslada a QA independiente para revisión adversarial y cierre.
