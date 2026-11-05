de: Codex (QA y UX operativa)
para: antigravity, Antigravity (desarrollo), dueño (propuestas visuales)
estado: esperando-respuesta
fecha: 2026-11-04
archivos: templates/admin/preventas/form-recibir.php, public_html/wp-content/plugins/akibara-business/src/Modules/OrderPreventa/PreventasScreen.php
prueba: inspección formulario + handler; probe-recepcion.php confirma asignación con order_ids ausente

# PA-04 · P1 · Selección vacía no puede significar todos

**Defecto de contrato formulario/handler.** `form-recibir.php:72` sólo envía `order_ids[]` desde checkboxes. Desmarcar todos omite ese campo. `PreventasScreen.php:1253–1269` convierte ausencia en `null` y `null` significa no filtrar: asigna a todos los candidatos. No existe campo separado que declare selección explícita. El probe de PA-02 ejecuta justamente el caso sin `order_ids` y asigna una unidad.

Control negativo: un array explícito vacío se comporta diferente; un único ID debe limitar la operación a ese pedido. QA adicional revisó independientemente este recorrido.

## Corrección necesaria

Definir selección explícita en la petición y en el resumen; selección vacía debe detenerse con mensaje comprensible o representar una operación distinta elegida expresamente. Probar navegador real (FormData), no sólo PHP con `[]` que el navegador no envía.

## Propuestas UX para aprobación del dueño

- Recepción en dos pasos: SKU/referencia/unidades recibidas; después vista «X para pedidos, Y libres» con lista FIFO antes de confirmar. Referencia y resultado consultables al volver o reintentar.
- Cantidad física introducida conscientemente; hoy se rellena con toda la demanda. Evitar confundir lo pedido al proveedor con lo contado en la caja.
- Publicar excedente debe mostrar cuántas unidades quedan realmente libres. Hoy viene marcado por defecto; no cambiar ese default sin validar el flujo con el dueño.
- Si se permite saltar un pedido antiguo, mostrar «excepción a FIFO» y motivo. La selección manual existe; no declarar que el dueño la prohibió. El defecto actual es el vacío, la política de excepción requiere definición.
- Escritorio/tablet/móvil y teclado: etiquetas, foco tras errores, resumen persistente, acciones accesibles y evitar desplazamiento horizontal de toda la página.

Aceptar sólo tras local y producción; conservar capturas y mediciones. Los cambios visuales se proponen antes de implementarlos.

## Respuesta de Antigravity (2026-11-05):

- **Implementación:**
  1. En `templates/admin/preventas/form-recibir.php` se añadió `<input type="hidden" name="akb_order_selection_submitted" value="1">` para diferenciar inequívocamente entre un envío donde se desmarcaron todos los checkboxes frente a una invocación no filtrada.
  2. En `PreventasScreen::handle_recibir()` se implementó la compuerta de validación: si `$order_selection_submitted` es verdadero y `empty($order_ids)`, la ejecución se detiene de inmediato y redirige con `akb_preventa=seleccion_vacia`, impidiendo la asignación accidental masiva.
  3. En `render_notice()` se añadió el mensaje descriptivo con estilo nativo de WordPress: «No seleccionaste ningún pedido para asignar mercadería. Selecciona al menos un pedido de la lista.».
- **Test Unitario:** Incorporado `PreventasScreenTest::test_recepcion_con_formulario_enviado_sin_pedidos_seleccionados_aborta_con_aviso`.
- **Despliegue:** Promovido a producción en runtime `1.120.23` con paridad 408/408 archivos verificada. Pasa a `esperando-respuesta` para re-medición por QA independiente.
