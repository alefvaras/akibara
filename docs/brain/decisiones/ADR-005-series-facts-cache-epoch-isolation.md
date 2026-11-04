# ADR-005: Aislamiento por Fila de las Generaciones de Caché en SeriesFacts

- **Estado:** Aceptado y Validado
- **Fecha:** 2026-11-04
- **Contexto:**
  `SeriesFactsCache` mantiene la consistencia de caché frente a invalidaciones concurrentes utilizando un modelo generacional (epoch) con 144 opciones individuales `akb_series_facts_generation_{ttid}` en `wp_options` (una por cada serie en el catálogo de Akibara).
  Durante la auditoría forense se analizó si consolidar estas 144 filas en un único array serializado dentro de una sola opción global `akb_series_facts_generations`.

- **Decisión:**
  Se mantiene la arquitectura de **filas individuales no-autoload** (`autoload = 'off'`) por las siguientes razones arquitectónicas:
  1. **Aislamiento de Concurrencia (Zero Lock Contention):** Cuando se actualiza o invalida una única serie, solo se muta su fila específica. Si todas las series compartieran un único array, cualquier actualización de stock o precio provocaría sobreescrituras concurrentes y condiciones de carrera (race conditions).
  2. **Index Lookup Ultrarrápido:** La tabla `wp_options` posee un índice único en `option_name`. La consulta `SELECT option_name, option_value FROM wp_options WHERE option_name IN ('akb_series_facts_generation', 'akb_series_facts_generation_{ttid}')` tarda menos de 0.2 ms en MariaDB.
  3. **Zero Impacto en Autoload:** Todas las 144 opciones están configuradas con `autoload = 'off'`. No se cargan en la memoria de WordPress al iniciar peticiones de otras páginas de la tienda.
  4. **Salto Intencional del Object Cache:** La consulta directa a MySQL garantiza que la invalidación de una serie sea durable e inmediata a través de múltiples procesos PHP, sin depender de la expiración de Redis/Memcached.

- **Consecuencias:**
  - Se descarta consolidar en una sola opción para preservar la seguridad de concurrencia.
  - La tabla `wp_options` permanece estable y saludable con 1.058 opciones totales (~143 KB).
