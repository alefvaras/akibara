de: qa-branding (QA independiente)
para: branding-prod (desarrollo)
estado: resuelto — aprobado por QA independiente tras gate local y medición de producción (2026-10-03)
archivos: (ninguno — QA no reclama implementación)
prueba: reproducción de pickup con markup del renderer sobre CSS servido por /mi-cuenta/; dropdown mediante checkout local y Google Places controlado

# Superficies claras ajenas al branding en retiro y direcciones

El dueño reporta una tarjeta blanca de coordinación de retiro con texto casi blanco en la página de pedido y el autocompletado de Google con la misma falta de coherencia. Referencia: captura aportada IMG_1379.PNG (no duplicar datos personales en evidencias).

## Control y reproducción, 2026-10-02

- La tarjeta comparte renderer de web/correo: `PickupCoordination::pintar_html()` incluye fondo inline `#f7f7f7` y enlaces inline `#0d0d0d`.
- Control independiente sobre CSS actualmente servido por producción en `/mi-cuenta/`: fixture DOM equivalente al renderer, dentro de `.woocommerce .woocommerce-order .woocommerce-order-details`. Se sustituyó sólo el DOM del navegador de QA; sin crear pedido, sin entrar a una cuenta ni escribir servidor.
- Medición a 390 × 844: fondo `rgb(247,247,247)`, párrafos `rgb(224,224,224)`, enlaces `rgb(13,13,13)`, sin overflow horizontal. El fallo persiste con la sección correcta de WooCommerce; coincide con la captura real del dueño. Esto confirma colisión de estilos, no un fallo del instrumento por contexto incorrecto.
- Evidencia local: `output/playwright/branding-2026-10-02/prod-pickup-baseline-dom.png`. Es fixture visual sobre assets reales, no un pedido productivo.

## Criterio de aceptación

- Tarjeta coherente con las superficies oscuras y acentos de la web; título, cuerpo y enlaces legibles, incluido Safari `-webkit-text-fill-color`.
- Verificar cuenta y pedido recibido, móvil y escritorio, sin overflow.
- Autocompletado Blocks y componente Google clásico: fondo/texto/selección legibles; atribución Google visible; flechas/Enter/Escape y selección preservadas.
- Correos sin cambios visuales colaterales: el CSS corrector queda limitado al contexto web.
- Primero gate local independiente, luego assets efectivamente servidos por producción y control de residuos. Sólo QA cierra.

## Gate local independiente completado, 2026-10-03

QA (sesión `qa_final`) autoriza desplegar las superficies probadas. Espejo con paridad verificada en `.local-backups/branding-checkout-20261002/paridad-20261003.log`.

- **Blocks**: checkout real local conservado por QA anterior. Flecha abajo cambia la opción activa; Enter aplica calle, región y comuna (`Avenida Pro 2000`, `CL-RM`, `Providencia`) y cierra la lista. Escape cierra sin alterar el texto libre. La sesión heredada tenía forced-colors activo: se controló antes de juzgar colores y se restableció al modo normal; foco/selección siguen distinguibles en colores forzados.
- **Google nativo real**: `/mi-cuenta/edit-address/billing/` carga CSS, JS y configuración reales; se usó únicamente formulario DOM de QA (cuenta sin autenticar, sin guardar). SDK oficial cargó `PlaceAutocompleteElement`, consultó predicciones reales y mostró superficie oscura + atribución Google Maps blanca. Flecha abajo + Enter seleccionó la sugerencia real y dejó input nativo `Avenida Providencia 1000`, comuna `Providencia`, región `CL-RM`. Escritura libre también se refleja en el input nativo. Evidencia: `output/playwright/branding-2026-10-02/local-google-native-open.png`.
- **Pedido recibido**: renderer PHP exacto ya generado sin guardar `WC_Order`, montado sólo en DOM de `/checkout/order-received/`. Se verificó que la hoja efectiva es `woocommerce-carrito.min.css`: superficie `#1a1a1a`, título blanco, cuerpo `#e0e0e0`, enlaces y `-webkit-text-fill-color` `#ff4756`. 390 px sin desborde (tarjeta 376 px). Evidencia: `local-thankyou-mobile.png`. La cuenta ya tenía evidencia móvil/escritorio en la carpeta de QA.
- **Límite del instrumento**: los formularios privados y tarjeta de pedido se montaron en DOM de QA con render/activos reales; no se creó pedido, no se modificó cuenta ni se enviaron correos. El widget y sus predicciones sí son Google real. El foco interno Google mantiene borde azul propio (2 px) dentro del foco amarillo Akibara; visible y accesible, no bloquea el arreglo solicitado.

Pendiente: medición de assets y superficies efectivamente servidas por producción; QA cerrará únicamente después del despliegue.

## Medición de producción y cierre QA, 2026-10-03

Despliegue recibido de desarrollo: `.local-backups/branding-checkout-20261002/deploy-receipt.json`, publicación de 9/9 archivos con comprobación SHA y limpieza de temporales remotos.

**Resultado: APROBADO por QA independiente.**

- Los **8/8 assets públicos** respondieron HTTP 200 desde el navegador de QA y sus SHA-256 coinciden exactamente con el plan aprobado (incluido logo Google). Evidencia: `output/playwright/branding-2026-10-02/prod-public-asset-hashes.json`. La petición HTTP de consola sin sesión recibió 403; el control con navegador real descartó un fallo de despliegue.
- **Checkout Blocks real de producción**, sin mocks: Google devolvió predicciones reales; superficie `#1a1a1a`, texto `#e0e0e0`, selección `#222` con texto blanco y foco amarillo de 3 px. Flecha abajo + Enter rellenó calle/comuna/región y cerró la lista. Escape conservó texto libre y cerró. Logo blanco oficial cargado, ancho natural 98 px. Móvil 390 px sin overflow. Evidencias `prod-blocks-desktop-open.png` y `prod-blocks-mobile-open.png`.
- **Google nativo real de producción**: widget y SDK oficiales con el puente/CSS servidos por `/mi-cuenta/edit-address/billing/`; formulario DOM QA sin guardar cuenta. Lista oscura, atribución blanca visible tanto escritorio como móvil, escritura libre y selección con teclado reflejadas correctamente en los campos de WooCommerce. Evidencias `prod-google-native-open-desktop.png` y `prod-google-native-open-mobile.png`.
- **Retiro en cuenta y pedido recibido**: hojas separadas `woocommerce-cuenta.min.css` y `woocommerce-carrito.min.css`, versionadas tras publicación, producen la tarjeta oscura `#1a1a1a`, cuerpo `#e0e0e0`, título blanco y enlaces `#ff4756` incluyendo `-webkit-text-fill-color`. Móvil/escritorio legibles y sin desborde. Evidencias `prod-account-{mobile,desktop}.png` y `prod-thankyou-{mobile,desktop}.png`.
- **Límite documentado**: el pedido real requiere verificación del correo del cliente; se respetó esa barrera y no se completó. Las capturas de tarjeta usan el renderer PHP exacto sin guardar pedido dentro de los endpoints y CSS reales. El resultado visual del CSS está verificado; no se afirma acceso autenticado al pedido del cliente.
- **Restitución**: la sesión propia de QA empezó sin productos, añadió 1 producto para medir el checkout y lo retiró al terminar. Se comprobó nuevamente el carrito vacío. No se creó pedido, no se guardó dirección de cliente, no se enviaron correos y no se tocaron precios/stock/opciones.

Cierre hecho por QA (`qa_final`), distinto de quien implementó/desplegó.
