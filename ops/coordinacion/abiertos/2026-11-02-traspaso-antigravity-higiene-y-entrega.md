de: root (supervisión), rewards_review y deployment_review (desarrollo)
para: prior_changes_qa (QA independiente) y dueño
estado: en-curso — modelo nativo aprobado y aplicado al control; promoción runtime pendiente
fecha: 2026-11-02
archivos: akibara-business, akibara-search, akibara-rewards, akibara-cart-checkout-blocks, akibara-gp y MU (sólo fuentes propias); ops/akibara-deploy-runtime.sh; tools/guardrail-ci.sh; tools/guardrail-ast-hygiene.php; tools/akibara-releases/package-runtime.sh; ops/guardrail-inventario-akibara.py

# Traspaso local y producción autorizado

El dueño pidió terminar el trabajo de Antigravity, revisar sus últimos cambios y cerrar también en producción. La referencia funcional del carrito es el comportamiento servido actualmente en producción; drawer y página Blocks son superficies distintas.

## Hallazgos reproducidos

- NativeCart local había perdido campos de disponibilidad/ETA, precio efectivo de línea y composición mixta respecto de producción. Prueba independiente: 4 fallos antes, 7/7 casos después de restaurar los tres métodos productivos; browser local 18/18 en 1440, 768 y 390 px.
- Watch::save perdía eventos con escrituras concurrentes. Corrección con candado MySQL por sitio, caché fresca y verificación de persistencia. QA independiente con dos procesos MariaDB reales conservó eventos y timestamps y verificó cero options de prueba residuales.
- El empaquetador de Search omitía las plantillas nuevas. El AST ignoraba SQL interpolado y errores de parseo. El verificador visual podía emitir éxito con errores JS y sin comprobar sincronización del precio. La entrega podía dejar su mantenimiento al fallar el primer rename. Todos tienen controles negativos ejecutados por QA independiente.
- El informe original no demuestra limpieza completa de Business: el barrido de comentarios aún encuentra referencias históricas en fuentes que no habían sido saneadas. La limpieza sólo puede declararse sobre manifiesto verificado, conservando contratos y tokens ejecutables.
- Espejo atrasado: WordPress 7.1 frente a 7.1.4; WooCommerce 11.1.0 frente a 11.2.1; GLA 3.9.3 frente a 3.10.0; BlueX 3.3.1 frente a 3.3.2. Se conserva rollback local de DB y terceros antes de alinear código exacto de producción; no se copia inventario local hacia producción.

## Disponibilidad de Mushoku Tensei 08

El dueño confirmó cero unidades y pidió que apareciera agotado. ID 16295 comprobado en local y guardado mediante UI oficial de WooCommerce en producción. QA independiente confirmó PDP, serie y filtro de agotados, precio regular, ausencia de compra y presencia del formulario de aviso. Evidencia en `output/handover-antigravity-20261102/qa/mushoku08-*`.

## Decisión de inventario aprobada

La regla 18 exigía manage_stock=false y backorders=notify simultáneamente, pero `WC_Product::validate_props()` guarda backorders=no cuando no se gestionan cantidades. El estado onbackorder sigue siendo comprable. Tras investigar grafo, MD, código productivo y pruebas de integración, el dueño aprobó expresamente el modelo nativo: regla y control aceptan no gestionado+onbackorder+backorders=no. Los productos ligados a pedidos pendientes están protegidos; no se aplicó un callback que fuerce una propiedad que el siguiente guardado desharía.

No confundir el 5% de una oferta en stock con un defecto de reserva. Los negativos de HXH tienen origen en un pedido real: cualquier migración debe proteger pedidos, cantidades comprometidas, snapshots y notificaciones; no se modifican por una inferencia del auditor.

## Evidencia y cierre

- `output/handover-antigravity-20261102/`: baseline, tar productivo, paridad, regresiones, pruebas, QA y entrega.
- `output/antigravity-handover-20261102/rewards-review/`: 46 fuentes saneadas y arreglo del vigía, hashes y pruebas.
- `.local-backups/handover-antigravity-20261102-local-parity/`: rollback local de DB y código de terceros.

La implementación no cierra este asunto. Pasa a esperando-respuesta después de desplegar y sólo QA independiente lo resuelve tras medir producción, limpieza remota y coherencia del grafo final. No se afirma ejecución real de GitHub Actions a partir de una validación estática del workflow.
