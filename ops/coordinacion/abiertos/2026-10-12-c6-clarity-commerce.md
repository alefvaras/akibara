de: architecture_audit C6, completado por clarity_candidate_finish coordinado con root
para: root y QA independiente
estado: resuelto — QA independiente links_qa_final aprobó local y producción el 2026-10-14
archivos: public_html/wp-content/plugins/akibara-business/src/Modules/FrontendCompatibility/FrontendCompatibility.php public_html/wp-content/plugins/akibara-business/assets/js/clarity-commerce-events.js public_html/wp-content/plugins/akibara-business/tests/Modules/FrontendCompatibility/FrontendCompatibilityTest.php public_html/wp-content/plugins/akibara-business/tests/Modules/FrontendCompatibility/clarity-commerce-events.test.js
prueba: node --test public_html/wp-content/plugins/akibara-business/tests/Modules/FrontendCompatibility/clarity-commerce-events.test.js

C6 autorizado por dueño: distinguir pedido creado de pago confirmado; excluir QA y visitas staff de eventos comerciales sin PII. Sólo candidato local. No versión, bootstrap ni promoción. Copia previa local en output/clarity-c6-20261012/before/.

## Entrega local — 2026-10-13

- Validación pedido/clave y sesión de cliente registrado antes de emitir `order_created`. `purchase` exige además fecha y estado pagado WooCommerce o la fuente existente `OrderStates::PAID` de Rewards (incluye `shipping-progress`, sin modificar estados globales Woo).
- Correos QA exactos, procedencia QA o metadatos explícitos activos; nombre de destinatario QA y variantes de correo no bastan. Staff por `manage_woocommerce`. Etiqueta cerrada `akb_traffic_type=qa|staff`; se suprimen eventos comerciales de estas visitas.
- Deduplicación por evento y token HMAC opaco, sólo sessionStorage/memoria del navegador. Ninguna clave, correo ni token llega al API Clarity. Distingue transición `order_created` → `purchase`.
- Docker PHP: **55 tests / 131 assertions**. Node JS: **30 tests**. WooCommerce real en memoria: **42 checks**, cero pedidos guardados. PHPCS, PHP/JS syntax y diff-check verdes.
- Evidencia y comandos repetibles: `output/clarity-c6-20261012/README.md`; manifiesto `candidate-manifest.json`; hashes `candidate.sha256`. Backup de parciales recibidos también en `resume-before/`.
- Límites: estado/fecha Woo no es conciliación bancaria; no reclasifica histórico; pago asíncrono se reconoce al volver al recibo; dedup por pestaña, no cross-device. Sin Rewards se conserva contrato paid Woo.
- Pendiente: revisión y gate independiente local; luego promoción/medición productiva y actualización final combinada de Graphify por root. Desarrollo no cierra este asunto.

## Gate independiente local — 2026-10-13

QA `links_qa_final` **aprueba la promoción limitada a los dos archivos C6**, sin hallazgos bloqueantes y sin editar implementación. No se cierra producción.

- Hashes firmados comprobados: PHP `f41bce03c8b37b7fa03493edbfb6c2c56c2ecbdf6c17446a4b4ba3a6063aed76`; JS `637716374951ed520d0869f78148b43356aa2d5a60a4dbbe537d87fff87a854f`.
- Diff íntegro contrastado contra las copias de producción en `output/ux-clarity-p1-20261011/rollback/production-commerce/`: alcance correspondiente al contrato de recibos y exclusiones; sin cambios ajenos en estos dos archivos.
- Reejecutados por QA: PHPUnit Docker **55 tests / 131 assertions**, Node **30 tests**, WooCommerce real **42 comprobaciones**, cero objetos guardados. PHP/JS syntax, PHPCS y diff-check verdes. Evidencia `output/clarity-c6-20261012/qa-independent-*`.
- Verificado pedido + clave y sesión de su cliente registrado; `purchase` exige estado pagado y fecha. `shipping-progress` con fecha sí genera compra; estados no pagados, cancelados, reembolsados o fallidos no la generan aunque mantengan fecha anterior.
- Verificadas identidades QA exactas y variantes negativas, marcas explícitas activas/inactivas y capacidad staff. Un nombre QA no basta. QA/staff sólo emiten `akb_traffic_type=qa|staff` y no eventos comerciales ni de atribución del puente.
- Payloads a la API de Clarity revisados: sólo nombres/enums, sin ID, clave, correo, monto ni token. HMAC se usa sólo en almacenamiento local. Dedupe distinto para `order_created` y `purchase`, reintentos, configuración antigua y almacenamiento bloqueado cubiertos.
- Referencia oficial consultada tras fallo del MCP de documentación: https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-api. El retorno de la API cliente sin excepción no prueba ingestión remota; tampoco hay reclasificación retrospectiva ni garantía entre dispositivos. Una visita QA anónima previa al recibo identificable no se clasifica retroactivamente.
- **Pendiente para cierre**: promoción por desarrollo, comprobar hashes/asset servido y volver a medir en producción. No se ha simulado una compra real ni se ha emitido correo. La validación del recibo pagado aquí combina pruebas del contrato y objetos WooCommerce sin guardar.


## Cierre independiente en producción — 2026-10-14

QA `links_qa_final` **cierra C6** después de la promoción por desarrollo y la purga oficial de LiteSpeed. No hay bloqueos pendientes dentro del alcance del contrato aprobado. No se editó la implementación ni se desplegó desde QA.

- **44 comprobaciones PASS** en WooCommerce productivo, mediante `wp eval-file -` y objetos sólo en memoria: estados pagados e impagos, `shipping-progress`, fechas, correos QA exactos/variantes, metadata activa/inactiva y payload cerrado QA/staff. Cero pedidos o usuarios guardados y cero archivos remotos creados.
- El probe exige los dos SHA256 exactos aprobados antes de medir. Se contrastaron además los hashes del JS original servido públicamente. Evidencias: `output/clarity-c6-20261012/qa-production-runtime-result.json` y `qa-production-browser-result.json`.
- **Navegador público aislado PASS**: PDP y recibo inválido responden 200. La PDP conserva `view_product`; el recibo inválido no expone token/eventos de pedido y no emite `order_created` ni `purchase`. Las solicitudes a Clarity se bloquearon y las llamadas se capturaron localmente: **cero eventos de prueba enviados**. Contextos cerrados al terminar.
- LiteSpeed sirve el bridge de la PDP con nombre derivado. El contenido descargado contiene el contrato nuevo y pasó los 30 casos del instrumento JS: 29 inicialmente; el restante pasó al tolerar espacios en una aserción de texto de la copia QA, porque la minificación los elimina. No fue una falla de comportamiento. Se volvió a ejecutar sólo ese caso; los logs inicial y de control se conservan.
- Veredicto reproducible: `output/clarity-c6-20261012/qa-production-verdict.json`. Promoción y purga: `output/ux-clarity-p1-20261011/release-production-promotion.json` y `release-litespeed-purge.log`.
- **Límites explícitos**: no se creó un pedido pagado real ni se probó su recibo en navegador. El contrato pagado se verifica con objetos WooCommerce productivos sin guardar y las suites locales aprobadas. No se mide ingestión remota de Clarity porque la red fue bloqueada deliberadamente. Estado/fecha Woo no equivale a conciliación bancaria; dedupe por pestaña/sesión; pago asíncrono exige volver al recibo válido; sin reclasificación histórica o retroactiva de visitas QA anónimas.
- No quedaron fixtures de pedidos/usuarios, archivos temporales remotos ni correos/eventos ficticios que limpiar. La actualización combinada final de Graphify corresponde a root.
