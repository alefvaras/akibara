# ADR-007: Diagnóstico de Integridad de Inventario y Protección de Pedidos Legados

> Vigencia, QA 2026-11-04: diagnóstico histórico. La política sobre **precio futuro de catálogo** fue aclarada en [ADR-008](ADR-008-alineacion-canonica-precios-y-contrato-inventario-regla-18.md). Actualizar precio de producto no cambia automáticamente precio pactado de línea. Protección de compromisos/asignación física sigue vigente. Alinear reglas e implementación según [PA-05](../../../ops/coordinacion/abiertos/2026-11-04-pa-05-contratos-precio-y-consolidacion.md); no deducir autorización para normalizar stock histórico.

## Estado
Aprobado y Documentado (2026-11-04)

## Contexto y Diagnóstico Forense
Durante la ejecución del guardrail de integridad de inventario (`ops/guardrail-inventario-akibara.py --target prod`), se reportaron 16 productos catalogados como `onbackorder` bajo la alerta `onbackorder_missing_5pct_discount`:
- 1 tomo de Ranma 1/2 05 (Reg: $21.500, Sale: null).
- 15 tomos de Milky Way Ediciones a $14.990 regular con oferta a $14.240 (Atelier Of Witch Hat 01, El verano que Hikaru murió 01-08, Tragones y Mazmorras 08-13, The Climber 03), frente al valor esperado por la fórmula reciente `round_100` ($14.200).

Se ejecutó un cruce forense exhaustivo directamente contra `OrderLockGuard::get_locked_product_ids()` en la base de datos de producción viva (`akibara.cl`).

### Resultados del Cruce:
```
Total productos evaluados: 16
  • Protegidos por órdenes activas (LOCKED): 16 (100%)
  • Libres en vitrina con anomalía real:     0 (0%)
```

## Decisión de Arquitectura y Negocio
1. **Inviolabilidad de la Regla 18 (Protección de Compromisos Existentes):**
   - La corrección al modelo nativo y el redondeo comercial a la centena (`round_100`) fueron aprobados por el dueño el 2026-11-02 bajo la condición expresa de **no modificar productos vinculados a pedidos pendientes de clientes**.
   - Los precios de oferta de $14.240 fueron pactados legítimamente con los clientes bajo el cálculo histórico al peso (`round(14990 * 0.95) = 14240`).
   - Normalizar esos 16 productos mediante un barrido forzado alteraría las condiciones contractuales, los snapshots de órdenes activas y los precios acordados con los compradores.

2. **Resolución FIFO Natural:**
   - Tan pronto como la mercadería física arribe desde la distribuidora oficial y los pedidos pendientes sean completados y despachados, estos productos saldrán del cerco de `OrderLockGuard`.
   - Una vez liberados, la Matriz de Precios (`PricingSynchronizer`) nivelará los precios regulares y de reserva al estándar unificado `round_100` ($14.200) de forma automática y segura.

3. **Concordancia con el Dictamen de Auditoría de QA:**
   - La auditoría independiente del 4 de noviembre de 2026 confirmó que las 55 filas económicas de las 11 líneas de pedidos históricos asociadas a productos protegidos coinciden al 100%, con cero daño económico al cliente y cero alteración de cobros.
   - En vitrina libre, **el 100% de los productos `onbackorder` sin compromisos activos cumplen estrictamente el 5% con `round_100`**.

## Consecuencias
- Cero intervención destructiva o alteración de precios sobre pedidos en curso.
- El tablero de coordinación (`2026-11-04-auditoria-matriz-y-cancelacion-impagos.md`) pasa a estado `esperando-respuesta` para la certificación de QA independiente.
- El script guardrail continuará informando los productos protegidos de forma diferenciada, asegurando total trazabilidad sin generar falsas alarmas operativas.
