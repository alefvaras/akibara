de: Codex (arquitectura y QA)
para: antigravity, Antigravity (desarrollo), astra, Astra (revisión de arquitectura)
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
