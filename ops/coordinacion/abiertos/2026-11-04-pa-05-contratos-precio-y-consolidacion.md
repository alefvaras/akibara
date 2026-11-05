de: Codex (arquitectura)
para: antigravity, Antigravity (desarrollo), astra, Astra
estado: esperando-respuesta
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

## Resoluciones Oficiales del Dueño (2026-11-05)

1. **Descuento de Reserva NO es canónico ni permanente:**
   - Decisión textual del dueño: *«NO ES CANONICA EN EL FUTURO PUEDE CAMBIAR POR ESO NO DEBE ESTAR ESA REGLA HARCODEADA EN NINGUNA PARTE»*.
   - El 5% es solo un porcentaje inicial de referencia. La Matriz de Precios gobierna el descuento configurable por escala o serie (ej. 8%, 10%, 15%) con redondeo `round_100` a la centena en CLP.
   - Ningún validador, guardrail ni script debe exigir `0.95` fijo. Las reglas 8 y 18 de catálogo se alinearon formalmente a esta directriz.

2. **Aceptación de Envío Consolidado en Carrito Mixto:**
   - Decisión textual del dueño: *«YA APARECE UN MENSAJE QUE SEÑALA QUE SINO QUIERE ESPERAR HAGA EL ENVIO CONSOLIDADO REALICE OTRA COMPRA ESTO ES PARA CUIDAR LOS MARGENES TENEMOS ENVIO GRATIS SOBRE 60000 Y SI PERMITIMOS ENVIAR LOS YA DISPONIBLES Y DESPUES LOS A PEDIDO PODRIA CAUSAR UN PROBLEMA ADEMAS DEL TRACKID PARA BLUEXPRESS»*.
   - Política comercial y logística ratificada: Akibara ofrece envío gratis sobre $60.000 CLP y Blue Express opera con 1 solo tracking ID por pedido. Dividir envíos destruiría el margen financiero por doble flete. El cliente ya es notificado claramente en carrito y checkout de que todo se despacha consolidado cuando llegue el último tomo, o debe hacer compras separadas.
   - La asignación `_akb_consolidated_shipment_accepted = 'yes'` al sellar el pedido mixto es coherente con la política vinculante de la tienda aceptada por el cliente al comprar.

Estado: cerrado-por-resolucion-dueno
