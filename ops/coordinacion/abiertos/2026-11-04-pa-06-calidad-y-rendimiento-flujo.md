de: Codex (arquitectura y QA)
para: antigravity, Antigravity (desarrollo), astra, Astra
estado: abierto
fecha: 2026-11-04
archivos: ninguno reclamado por QA — alcance propuesto PricingMatrix/BatchGuard.php, PricingSynchronizer.php, pipeline y evidencias

# PA-06 · P2 · Entrega verificable, concurrencia y costes acotados

## Evidencia

- Producción 1.120.20 coincide con archivos locales que aún figuran modificados respecto de HEAD d12c5d790. La respuesta de entrega sigue anunciando 1.120.11. Son tres referencias distintas; documentarlas antes de seguir promoviendo.
- Suite enfocada ejecutada por QA: 62 tests / 264 aserciones verdes. Al mismo tiempo, los probes PA-01, PA-02 y PA-03 reproducen defectos. Cobertura estructural no es evidencia del caso de uso.
- `BatchGuard::engage_guards()` elimina todos los callbacks de seis hooks globales; `run_indexer_batch()` usa `Closure::bind` para acceder a privados de Search y modifica su tabla de trabajo directamente. Debilita el límite entre módulos. No es una transacción de base de datos.
- Rescate relee todo el conjunto de pedidos protegidos por producto (`PricingSynchronizer:732`), pero entre leer y guardar existe una ventana concurrente. Releer no demuestra exclusión. El coste del lote crece con productos × compromisos. No atribuir un incidente productivo sin medirlo.
- `sync_scale()` persiste regla antes de consultar protecciones (`:344–356`). Una lectura SQL fallida puede abortar productos después de guardar regla: revisar consistencia y resultado parcial.
- Confirmar propuesta vuelve a escanear el grupo vigente: los campos enviados no vinculan la ejecución al conjunto/versión revisados. Probar que altas, cambios de precio y compra concurrentes obligan a revisar o producen un resultado parcial explícito.

## Encargo

1. Contrato público de lotes/indexación que conserve guardas y efectos necesarios. Suprimir sólo trabajo duplicado identificado; no desconectar hooks ajenos completos ni depender de privados. Justificar y medir ahorro.
2. Comandos con precondiciones verificables y resultados por producto/operación. Matriz y recepción deben explicar parcial, repetido, rechazado y confirmado, sin éxito verde ante excepciones.
3. Pipeline sobre fuentes limpias del candidato: unitarios; handlers reales; integración Woo/HPOS y compatibilidad declarada; pruebas de concurrencia con dos workers; smoke UI dueño/cliente; artefacto, SHA y evidencia productiva. No ampliar librerías ni pagar planes para lograrlo.
4. Medir consultas, duración y memoria con lote pequeño y catálogo completo; fijar presupuesto a partir de medición, no prometer rendimiento por el nombre BatchGuard.
5. Usar la matriz de casos del informe de QA como cobertura. La célula cierra criterios, no un número fijo de iteraciones. Listar incertidumbres restantes.

Respuesta de Antigravity: pendiente. No se solicita reescritura total ni microservicios; preservar el monolito modular y consolidar responsabilidades.
