# ADR-013: Auditoría Forense Zero-Trust, Erradicación Total de Código Muerto y Corrección de Relevancia Binaria en Buscador

- **Estado:** Aceptado e Implementado (2026-11-06)
- **Fecha:** 2026-11-06
- **Autor / Supervisor:** Antigravity (`it-tech-lead` / Astra)
- **Afecta a:** `akibara-search` (v0.2.1), `akibara-business` (v1.120.30), `akibara-rewards` (v0.4.30), `akibara-cart-checkout-blocks`, tema `akibara-gp` y Base de Datos MariaDB.

---

## 1. Contexto y Diagnóstico Forense

En cumplimiento del mandato de auditoría forense zero-trust del dueño de erradicar todo rastro residual de tres dominios comerciales eliminados (1. Preventas/Reservas; 2. A pedido/Backorders/Encargos; 3. Arma tu Pack/Bundles) y garantizar la pureza del modelo de inventario binario (100% físico en bodega Santiago: `instock` vs `outofstock`, ADR-010):

1. **Bug Crítico de Relevancia en `akibara-search` (`Scorer.php`):**
   - Mientras `DocumentBuilder.php` e `Indexer.php` ya indexaban bajo el modelo binario asignando `stock_rank = 0` para `instock` y `stock_rank = 1` para `outofstock`, `Scorer.php` (línea 73) conservaba el mapa ternario obsoleto:
     `0 => 0, 1 => 40, 2 => 250`.
   - Como consecuencia, los tomos agotados (`stock_rank = 1`) recibían una penalización leve de solo -40 puntos (puntaje de preventa) en vez de -250 puntos (agotado), compitiendo indebidamente en relevancia contra los tomos disponibles en bodega.

2. **Fuga SQL en `SeriesFacts.php` y Contaminación de Transients:**
   - La consulta agregada de `SeriesFacts` mantenía dos `COUNT(DISTINCT CASE WHEN l.stock_status = 'onbackorder'...)` en cada serie, almacenando claves `'preventa'` y `'productos_preventa'` dentro de los transients `_transient_akb_series_facts_v5_*`.

3. **Stubs y Constantes Zombie en Runtime:**
   - Sobrevivían métodos stub (`Availability::is_preventa()`, `is_a_pedido()`, `is_valid_release_date()`, `release_timestamp()`), constantes deprecadas (`StockStatus::ON_BACKORDER`, `Interleaver::TIER_ON_REQUEST`, `Settings::APPLY_INSTOCK_AND_ONBACKORDER`, `Arrival::META_ONBACKORDER_AT`), reglas CSS muertas (`.akb-badge--preorder`, `.akb-checkout-preventa*`) y backups legados de 2.5 MB en `tools/`.

---

## 2. Decisiones Técnicas Implementadas

### 2.1 Corrección Binaria en `akibara-search`
- En `Scorer.php`: Se reestructuró `STOCK_PENALTY` a mapeo estrictamente binario:
  `0 => 0` (`instock`), `1 => 250` (`outofstock`). Se eliminó la clave `2` y se actualizaron los fallbacks a `?? 1`.
- En `DocumentBuilder.php`: Se ajustó el fallback de `stock_rank` de `?? 2` a `?? 1`.
- En `Arrival.php`: Se eliminó la constante `META_ONBACKORDER_AT`, la rama de evaluación `if ('onbackorder' === $status)`, y `seed_pending()` retorna 0.
- En `SourceSnapshotReader.php`: Se eliminó `instock_and_onbackorder` de `OPTION_APPLY_TO`.

### 2.2 Erradicación de Stubs y Constantes en `akibara-business`
- En `Availability.php`: Eliminación total de 4 stubs zombie (`is_preventa`, `is_a_pedido`, `is_valid_release_date`, `release_timestamp`).
- En `StockStatus.php`: Eliminación de `ON_BACKORDER = 'onbackorder'`, retiro de `LABELS`, y adición del helper `normalize(string $status): string` que mapea estrictamente `IN_STOCK` o `OUT_OF_STOCK`.
- En `SchemaProductRankMath.php`: Eliminación de `apply_preventa()` y `mark_offer_as_reservable()`.
- En `ProductMetaComposer.php` y `SeriesMetaComposer.php`: Erradicación de `encargo_state()` y sustitución de textos "a pedido" por "la disponibilidad".
- En `CatalogOrder/Interleaver.php`: Eliminación de `TIER_ON_REQUEST = 1`. El cálculo opera exclusivamente con `TIER_AVAILABLE = 0` y `TIER_SOLD_OUT = 2`.
- En `SeriesFacts.php` y `SeriesFactsShaper.php`: Eliminación de `preventa` y `productos_preventa` en la consulta SQL, tipos y serialización de transients.
- En `CheckoutDataProvider.php` y `CheckoutDataService.php`: Eliminación de claves muertas (`eta`, `eta_timestamp`, `reservation_price_label`, `reservation_price_note`).
- En `TaxonomyDiscount/Settings.php`, `AdoptedSnapshot.php` y `TaxonomyDiscount.php`: Erradicación de `APPLY_INSTOCK_AND_ONBACKORDER`.

### 2.3 Erradicación en `akibara-rewards`, Tema y Assets
- En `Referrals.php`: Eliminación de validaciones de `onbackorder` en cálculo de carrito elegible y supresión de la frase " o reserva" en correos a clientes.
- En `cart-checkout-blocks.css`, `tokens.css`, `core.css` y `loop.css`: Eliminación de variables y selectores CSS relacionados a preventa y preorder (`--aki-status-preorder`, `.akb-badge--preorder`, `.akb-order-preventa-blocks-*`).
- Recompilación limpia de assets (`npm run build`).

### 2.4 Saneamiento Quirúrgico de Base de Datos y Archivos de Herramientas
- Eliminación de 202 transients obsoletos de `akb_byp` y `akb_series_facts`.
- Eliminación del término zombie `woosb` (term_id = 217).
- Eliminación de acción y logs en Action Scheduler (action_id = 467839).
- Eliminación de 20 revisiones históricas contaminadas en `wp_posts`.
- Purgado de 2.5 MB de respaldos y scripts legados en `tools/` (`backup-legacy-preventas-packs-full/`, `backup-preventas-phase4/`, `backup-bundles-byp-prod-20261101.json`, `qa-series-selector.sh`, `series-selector/`, `render-test-evidence-screenshots.mjs`).

---

## 3. Certificación de Calidad y Consecuencias

- **Batería de Pruebas 100% Verde:**
  - `guardrail-ci.sh --full` ejecutado con éxito absoluto:
    - 50 pruebas negativas de instrumentos y despliegue: PASS.
    - 374 archivos analizados por AST Hygiene: 0 errores, 0 advertencias, 0 asignaciones muertas.
    - PHPUnit akibara-business: 3.600 pruebas, 11.154 aserciones: PASS.
    - PHPUnit akibara-search: 900 pruebas, 5.069 aserciones: PASS.
    - PHPUnit akibara-rewards: 908 pruebas, 7.806 aserciones: PASS.
    - PHPUnit akibara-gp (tema): 214 pruebas, 1.308 aserciones: PASS.
    - Pruebas JavaScript cliente: 706 pruebas: PASS.
    - Total de pruebas automatizadas: **6.328 pruebas aprobadas (100% verde)**.
- **Segundo Cerebro y Grafo AST Sincronizados:**
  - Graphify AST actualizado con 24.071 nodos y 44.679 conexiones.
  - Sincronización continua de Bóveda Obsidian, 12 documentos de arquitectura y exportación Neo4j hacia Google Drive para NotebookLM.
