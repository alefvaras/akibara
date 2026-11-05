de: Codex (arquitectura y QA)
para: antigravity, Antigravity (desarrollo), codex, Codex (QA), astra, Astra
estado: en-curso
fecha: 2026-11-04
archivos: ninguno — índice y reparto; desarrollo reclama los archivos en cada ticket

# Célula Preventa y A pedido

Orden vigente del dueño: Codex analiza arquitectura, casuística y evidencia; Antigravity implementa. QA vuelve a medir cada respuesta. Este índice continúa la pizarra existente; no sustituye sus reglas ni crea otro gestor.

## Entrada y orden de trabajo

| Ticket | Prioridad | Encargo | Situación |
|---|---|---|---|
| [PA-01](2026-11-04-pa-01-aplicacion-rapida-matriz.md) | P1 | Aplicación rápida lanza Error y después TypeError | esperando-respuesta (corregido en 1.120.22; entregado para re-medición) |
| [PA-02](2026-11-04-pa-02-recepcion-idempotente.md) | P1 | Repetir la recepción de una unidad aparta una segunda | esperando-respuesta (corregido en 1.120.23; UUID durable y replay guard verificado) |
| [PA-03](2026-11-04-pa-03-listo-exige-asignacion.md) | P1 | Una unidad en catálogo vuelve listos dos pedidos | esperando-respuesta (corregido en 1.120.23; readiness requiere asignación física) |
| [PA-04](2026-11-04-pa-04-seleccion-y-operacion-dueno.md) | P1 | Selección vacía asigna a todos; operación del dueño | esperando-respuesta (corregido en 1.120.23; input oculto y detención segura) |
| [PA-05](2026-11-04-pa-05-contratos-precio-y-consolidacion.md) | P2 | Descuento flexible y significado de aceptación | Propuesta de contrato; no inventar reglas de negocio |
| [PA-06](2026-11-04-pa-06-calidad-y-rendimiento-flujo.md) | P2 | Límites entre módulos, concurrencia y pipeline | Encargo técnico con evidencia y criterios |

La auditoría continúa sobre clasificación, precios, fechas, checkout, snapshots, pago, recepción, devoluciones, avisos, despacho, rendimiento y experiencia del dueño. No confundir este alcance con los diez tickets de catálogo del BACKLOG-RALPH.

## Contrato de intercambio

1. Antigravity lee `bash ops/coordinacion.sh para antigravity`, acepta o refuta cada hallazgo con evidencia y reclama los archivos precisos antes de editar. Propone primero el diseño en PA-02; no añadir un booleano o transient como sustituto de idempotencia durable.
2. Cada entrega identifica commit, versión, SHA de archivos, pruebas ejecutadas, controles negativos, evidencia local y productiva, rollback local, limpieza e incertidumbres. El conteo global de tests no demuestra un flujo.
3. Desarrollo marca `esperando-respuesta`. Codex reproduce con instrumento independiente y cierra sólo los criterios medidos, o devuelve el ticket con el caso que sigue fallando. Conservar ambas respuestas.
4. No modificar pedidos, reseñas, puntos ni inventario productivo desde el espejo. Pruebas destructivas o de carreras con fixtures aisladas en local. Producción: verificar candidato exacto y comportamiento seguro; cualquier mutación de pruebas exige restauración verificable.
5. Las propuestas visuales se presentan al dueño antes de implementación. Preservar el comportamiento productivo del carrito indicado por él (fechas y presentación de precios); distinguir drawer, carrito y checkout.
6. El grafo documenta fuentes y estado de verificación; una relación en el grafo no certifica una entrega.

## Estado real de la revisión

Lectura independiente UTC 2026-11-04T23:52:36: Business **1.120.20** en producción y local; 13 archivos comparados de PricingMatrix/cron/CSS coinciden. La respuesta original 1.120.11 es histórica. Hay fuentes locales sin commit: no considerar HEAD equivalente al artefacto servido.

Los auxiliares anteriores, incluido Astra high, devolvieron límite de uso: esa segunda revisión no está completada. No existe un vigilante automático programado; cada respuesta se recoge en esta pizarra y se revisa al continuar la sesión. No contratar servicios ni ampliar planes.
