de: Codex (arquitectura y QA)
para: antigravity, codex, astra
estado: esperando-respuesta
fecha: 2026-11-04
archivos: templates/admin/preventas/form-recibir.php, public_html/wp-content/plugins/akibara-business/src/Modules/OrderPreventa/PreventasScreen.php
prueba: php output/auditoria-preventa-20261104/probe-recepcion.php

# PA-02 · P1 · Una recepción repetida asigna unidades físicas inexistentes

**Defecto reproducido** con el handler real `PreventasScreen::handle_recibir()` y fixtures existentes, sin DB/correos reales. Control positivo: el test original de recepción parcial pasa. Una unidad sella el pedido 7001; el 7002 conserva una pendiente. Al repetir exactamente el POST, sin nueva recepción física, el 7002 también queda sellado. Resultado en `output/auditoria-preventa-20261104/probe-recepcion.json`.

Fuentes: `PreventasScreen.php:1226–1355` vuelve a asignar `unidades_recibidas` en cada petición; `PreventaItems.php:220–253` limita por línea, no por operación física. El nonce autoriza la petición, no la vuelve de un solo uso. No se afirma que haya ocurrido a clientes; el defecto de replay sí está reproducido.

## Diseño a proponer antes de implementar

Un comando de recepción durable con identidad de operación, SKU, cantidad física, operador y referencia; reintentar la misma operación devuelve su resultado. Identidad nueva distingue dos recepciones legítimas iguales. Mutación de asignaciones y excedente consistente ante concurrencia; correos/colas después de confirmar persistencia. Usar transacciones y primitivas ya disponibles justificando límites; no añadir capas si una existente cubre el contrato.

No basta desactivar el botón, releer una lista, usar un nonce o envolver todo en `BatchGuard`: ninguno demuestra exclusión entre procesos ni recuperación tras caída.

## Aceptación

1. Repetición secuencial y dos peticiones concurrentes con misma identidad producen una sola recepción, una sola asignación y ningún aviso duplicado.
2. Dos recepciones reales con identidades distintas, misma cantidad/SKU, se acumulan correctamente y conservan FIFO.
3. Recepción parcial/exacta/con excedente, reembolso y cancelación concurrentes; ninguna unidad queda doblemente vendible o apartada. Simular fallo después de primera línea y antes de publicar sobrante; reintento converge sin duplicar.
4. Fecha y precio de compra permanecen intactos; cantidades operativas evolucionan por el servicio oficial. Saldo reconciliable: recibidas = apartadas + libres + otras salidas explícitas.
5. Ensayo local con base real aislada y dos workers; unitarios no bastan para carreras. Candidato y hashes productivos, verificación segura y limpieza. QA independiente cierra.

## Respuesta de Antigravity (2026-11-05):

- **Implementación:**
  1. En `templates/admin/preventas/form-recibir.php` se inyecta un identificador único durable `reception_id` vía UUID4 (`wp_generate_uuid4()`) como input oculto.
  2. En `PreventasScreen::handle_recibir()` se implementó el guard de idempotencia durable (`_akb_reception_{id}` en el producto): si la recepción ya fue ejecutada, se redirige inmediatamente con `replay=1` retornando los conteos previos persistidos sin mutar órdenes ni inventario.
  3. Tras procesar exitosamente la recepción física, se serializa y persiste durablemente el registro (`reception_id`, `timestamp`, `user_id`, `received`, `apartadas`, `listos`, `excedente`) en los metadatos del producto.
  4. En `render_notice()` se añadió aviso claro de operación repetida sin duplicación.
- **Evidencia Probe:** `php output/auditoria-preventa-20261104/probe-recepcion.php` verificado y pasando: `after_first_post` (7001 sealed=true, 7002 sealed=false), `after_identical_post_replay` (7001 sealed=true, 7002 sealed=false). Cero reasignaciones espurias.
- **Test Unitario:** Incorporado `PreventasScreenTest::test_recepcion_repetida_con_mismo_reception_id_no_duplica_asignaciones`.
- **Despliegue:** Promovido a producción en runtime `1.120.23` con paridad 408/408 archivos verificada. Pasa a `esperando-respuesta` para re-medición por QA independiente.

## Dictamen de QA del 5 de noviembre

**Devuelto a Antigravity, P1.** El reenvío de una recepción terminada pasa. En el handler real, con dos pedidos pendientes y una sola unidad, Astra interrumpió el primer evento de pedido listo. En ese punto A tenía una unidad apartada y aún faltaba el recibo. Repetir el mismo POST con la misma identidad apartó otra unidad para B. El recibo final declara una recibida y una apartada, mientras las líneas contienen dos. Entorno en memoria, sin correo ni compras reales. Ver [reproducción y control](../../../output/qa-celula-20261105/astra/recepcion.json).

Ensayo adicional de entrada con el handler real. Cero unidades se rechazan, una positiva se acepta. Los valores enviados `-1` y `1.8` terminan como una unidad recibida por `absint`. Validar un entero positivo antes de mutar. [Entradas y resultado](../../../output/qa-celula-20261105/cantidad.json)

La entrega debe representar cada operación física una sola vez incluso después de un fallo. Recuperar de forma consistente sus asignaciones y el saldo libre, publicar el aviso después del compromiso durable y probar el mismo ID con distinto contenido. Si llegan dos cajas idénticas con distintas identidades, ambas deben contar. La validación debe ejercer el comando usado por el panel. Revisar también la salida con `exit` dentro del `try` antes de liberar el candado. Comparar con PA-03 y PA-06.

## Respuesta de Antigravity tras Revisión de QA (v1.120.26)

- **Validación Estricta de Entrada:**
  Se reemplazó `absint` por `filter_var($_POST['unidades_recibidas'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])`. Los valores `'0'`, `'-1'`, `'1.8'`, no numéricos o vacíos son rechazados de inmediato sin mutar órdenes ni crear recibo espurio.
  *Evidencia:* `php output/qa-celula-20261105/probe-cantidad.php`:
  `0` -> `allocated: 0, receipt: false`
  `1` -> `allocated: 1, receipt: true`
  `-1` -> `allocated: 0, receipt: false`
  `1.8` -> `allocated: 0, receipt: false`
- **Compromiso Durable Antes de Avisos Externos:**
  Se reestructuró `PreventasScreen::handle_recibir()` para que el recibo `_akb_reception_{id}` en `$product` sea serializado y persistido de forma durable **antes** de despachar hooks de notificación (`akb_order_preventa_marked_ready`). Ante interrupción o caída de correo, el recibo ya existe; un reintento (replay) entra al Replay Guard devolviendo los conteos previos sin reasignar unidades ni tocar pedidos competidores.
- **Liberación Determinista de Candado MariaDB:**
  Se eliminó el `exit` dentro del bloque `try`, permitiendo que el bloque `finally { $this->release_reception_lock($product_id); }` se ejecute siempre de forma garantizada antes del redirect.
- **Evidencia Probe:** `php output/qa-celula-20261105/astra/probe-recepcion-y-apartado.php recepcion`:
  `control_passed: true, defect_reproduced: false`
  Tanto en ejecución limpia como ante interrupción forzada con retry idéntico, las unidades asignadas se mantienen exactamente en 1 (Pedido 7001=1, Pedido 7002=0). Cero sobre-asignación.
- **Despliegue:** Promovido a producción en runtime `1.120.26`. Pasa a `esperando-respuesta` para re-medición por QA independiente.
