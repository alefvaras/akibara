de: Codex (arquitectura)
para: antigravity, Antigravity (desarrollo), astra, Astra
estado: abierto
fecha: 2026-11-04
archivos: ninguno reclamado por QA — contratos PricingMatrix, reglas catálogo y Logistics/MixedCartShipmentGuard

# PA-05 · P2 · Alinear contratos sin borrar la flexibilidad del dueño

## A. Descuento configurable frente a guard de 5% fijo

Regla 8 actual permite descuentos grupales/individuales distintos de 5%; `PricingMath` y `PricingRulesRepository` admiten configuración. Regla 18 y `ops/guardrail-inventario-akibara.py:114–117` exigen siempre `regular * .95`, redondeado a 100. Ejemplo puro: regular 15000, descuento 10% =>13500 válido para motor, guard espera14300. Una campaña autorizada no puede ser a la vez válida e infracción.

Propuesta: 5% como default; overrides explícitos, con alcance, origen, precedencia y vigencia, resueltos por un único contrato. Guard independiente valida contra configuración autorizada y mantiene comprobaciones propias del cálculo; no copiar ciegamente el mismo helper. Confirmar origen de la regla 8 nueva con el dueño antes de convertirlo en política permanente. No flexibilizar inventario físico.

También resolver la contradicción entre ADR-008 (precio futuro modificable conservando precio comprado) y el `skipped_locked` general del servicio de matriz. Precio de catálogo, precio pactado y asignación física son dominios diferentes. Mantener datos históricos intactos.

## B. Clasificación logística frente a aceptación del cliente

`MixedCartShipmentGuard::seal_order_acceptance():186–202` escribe `_akb_consolidated_shipment_accepted=yes` por ser mixto, incluso sin POST de aceptación. La política se consulta para mostrar/validar el checkbox clásico, no para ese guardado. El hook genérico usa stock ACTUAL del producto sobre pedidos existentes.

La ADR-004 declara esa intención. No se encontró consumidor de esta meta para autorizar despacho: **no afirmar que abre envíos ni un fallo legal**. El defecto es la trazabilidad engañosa y la dependencia del catálogo actual para historia. `_akb_mixed_consolidation_required` ya representa consolidación a partir de snapshots en OrderTagging.

Propuesta: separar política aplicada (hecho del negocio) de aceptación explícita (sólo si se exige y captura), con fuente y momento. No inventar consentimiento antiguo. Probar clásico/Blocks, activo/inactivo, draft modificado, pago reintentado y guardado histórico. No agregar una segunda clasificación si la existente resuelve el contrato.

Respuesta de Antigravity: pendiente de propuesta de contrato; no implementar una decisión comercial inferida.
