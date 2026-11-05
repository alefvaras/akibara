de: brevo_images_qa / brevo_adapter_review (QA independiente)
para: root (desarrollo)
estado: esperando-respuesta — arreglo desplegado; gates técnicos local y producción aprobados; pendiente comprobar correo recibido
archivos: public_html/wp-content/mu-plugins/akibara-brevo-portada-jpg-en-el-carrito.php (desarrollo root); QA sólo modifica este informe y output/brevo-images-20261004/
prueba: imagen real del producto 28046, adjunto 28047, respuesta pública y callback productivo

# Brevo: portada rota en carrito abandonado

El dueño solicita corregir y mejorar el adaptador de imágenes, desplegarlo y comprobarlo en producción. La evidencia recibida muestra la imagen de Kagurabachi 10 rota en Gmail. No se autoriza por este hallazgo cambiar políticas comerciales, consentimiento, precios, plantillas o destinatarios.

## Reproducción y control del instrumento

QA independiente comprobó por WP-CLI de sólo lectura que el producto 28046 usa el adjunto 28047, cuya imagen completa es `https://akibara.cl/wp-content/uploads/2026/10/kagurabachi10.avif` (400 × 587). El callback productivo `akibara_brevo_portada_en_jpg()` devuelve esa misma URL: el patrón vigente sólo reconoce `/uploads/portadas/`, por lo que el archivo mensual queda fuera.

Dos GET públicos, con Accept que prioriza JPEG y con Accept que incluye AVIF/WebP, respondieron HTTP 200, `Content-Type: image/avif`, 95.062 bytes y firma binaria AVIF. Ambos SHA256 son `71c40a5b20b04deb518bf5efdfd09448ef01b2888da4ba1a478b74bab9bbdaab`. No es una conclusión basada sólo en extensión o en un 404.

Control positivo: la hermana histórica `https://akibara.cl/wp-content/uploads/portadas/kagurabachi-9-ivrea-argentina-og.jpg` responde HTTP 200, MIME JPEG y firma JFIF, 231.093 bytes, mismo SHA256 `a337ee9a49bfdb1a6b3ec1deae2dfe863bcf5ed22b5b2af9f63eea665726e778` con ambos Accept. Cloudflare pasó de MISS a HIT manteniendo bytes JPEG. Así se descarta que el instrumento esté clasificando toda imagen como AVIF o que haya un bloqueo general de las imágenes.

Desarrollo aportó además la comprobación del proxy firmado de la imagen del correo existente: HTTP 401, cuerpo `unknown image format`. QA no duplicó ni guardó la URL firmada ni tokens del correo. Esa evidencia se atribuye a desarrollo; los GET de origen y el callback anteriores sí fueron medidos por QA.

## Criterios del arreglo

1. Convertir la imagen mensual real a JPEG válido antes de entregar el dato de carrito a Brevo; mantener la imagen optimizada de la tienda fuera de esa ventana.
2. Cubrir archivos locales AVIF/WebP en uploads y variantes con query/crop según la interfaz definida; JPEG/PNG compatibles no deben degradarse gratuitamente.
3. Resolver archivos dentro del uploads autorizado, sin fetch de URL arbitraria ni acceso fuera del directorio por traversal, URL codificada, host extraño o symlink. Validar el formato de los bytes, no sólo la extensión.
4. Conversión acotada, caché válida y atómica, reparación de derivado corrupto, invalidación al reemplazar la fuente y fallback explícito seguro. No repetir trabajo caro en cada petición ni bloquear el carrito por una imagen corrupta.
5. Primero Docker: encoder real, matriz de seguridad y respuesta HTTP pública local. Después producción: callback real y bytes servidos por CDN con Accept variados, MISS/HIT y peso/dimensiones. No crear pedidos ni enviar emails desde QA.
6. El cierre del camino de correo requiere comprobar lo recibido. Un helper que devuelve `.jpg` por sí solo no demuestra que Brevo/Gmail muestre la imagen; si no hay correo nuevo autorizado, se documentará expresamente esa limitación sin declarar resuelto el correo completo.

## Evidencia inicial

- `output/brevo-images-20261004/production-baseline.json`: producto/adjunto, URL original y retorno del adaptador productivo.
- `output/brevo-images-20261004/public-baseline.json`: respuestas HTTP y firmas binarias, sin tokens.

Estado: se entrega a desarrollo. QA no ha editado implementación ni enviado correos. No hay cambios de datos que restaurar en esta reproducción.

## Gate local independiente — 2026-10-04

`brevo_adapter_review` revisó el candidato sin editar implementación y aprobó su despliegue. SHA256 del helper `EmailImageDerivatives.php`: `0687bf9a6c77dbd268d65e58e93bfbe9f25d5dd589feb41674c2ee1e3fcb3582`; SHA256 del MU 2.0.0: `7261c890a427afee00d9697ca843145dcb8b2ed0c645775b8e959902a156d8cb`.

Se reejecutaron personalmente 44 comprobaciones Docker (20 payload, 13 seguridad y 11 formatos), todas verdes y con limpieza de fixtures confirmada. Se revisaron los 18 checks adicionales de caché de `brevo_images_qa` y el JPEG local real de Kagurabachi 10. No se detectaron bloqueantes en confinamiento de rutas, symlinks, ausencia de fetch externo, límites de conversión, publicación atómica ni conservación de datos comerciales. El espejo no tiene Brevo: su prueba de pipeline registra explícitamente el callback; la inscripción real quedó pendiente para producción.

## Gate técnico de producción independiente — 2026-10-04

Tras el despliegue, QA comprobó por SSH/WP-CLI los SHA exactos aprobados y Brevo **4.0.60**. El adaptador está registrado en prioridad 20, precálculo en 30 y tarea diferida en 10. No queda el filtro global antiguo de imágenes de la tienda. No hay temporales `.email-*`; el recibo de despliegue confirma limpieza de sus archivos temporales y rollback guardado únicamente en local.

QA revisó y volvió a ejecutar el probe de producción: **10/10 comprobaciones verdes**, transporte interceptado antes de enviar y cero eventos/correos despachados. El método real `get_dynamic_img()` del vendor obtiene `kagurabachi10-350x514.avif`; el payload final recibe el JPEG derivado del original, **400 × 587, 94.681 bytes**. Precios, enlaces y demás datos comerciales se conservan exactamente. La llamada con caché preparada tomó **0,293 ms** en esta repetición (desarrollo midió 21,472 ms en la primera conversión).

Cuatro GET públicos independientes con dos variantes de `Accept` (JPEG y navegador que prefiere AVIF/WebP) devolvieron **HTTP 200, `image/jpeg`, Cloudflare HIT y bytes idénticos**. SHA256 público y en disco: `b0ba2775b393b6486851d2c0cb079bb8f7233e0cb46700e67b9041e2a3992739`. Se revisó además el MISS inicial capturado por desarrollo: coincide con el HIT y el mismo SHA. El PNG de fallback respondió 200, `image/png`, 44.977 bytes. El AVIF original de la tienda sigue con el SHA de la reproducción inicial, sin modificación.

Evidencia adicional:

- `output/brevo-images-20261004/qa-production-registration.json`: comprobación independiente de versión, hooks y SHA.
- `output/brevo-images-20261004/qa-production-runtime.json`: repetición independiente del pipeline real, interceptado antes del transporte.
- `output/brevo-images-20261004/qa-production-public.json`: cuatro GET de CDN, firmas JPEG y comparación de hashes.
- `output/brevo-images-20261004/qa-production-controls.json`: fallback PNG y original AVIF intacto.
- `output/brevo-images-20261004/production-public.json`: MISS/HIT inicial medido por desarrollo y revisado por QA.

Control del instrumento: `wp eval-file /dev/stdin` no funciona en este host y puede salir 0 sin ejecutar el PHP; se descartó esa medición y se usó `wp eval` con quoting seguro, validando el JSON de salida. Los callbacks se consultaron con la cadena exacta de clase que utiliza su registro, incluida la barra inicial.

**Alcance del visto bueno:** implementación y comportamiento técnico en producción aprobados. El informe permanece en `esperando-respuesta`: todavía falta recibir y comprobar un correo nuevo de Brevo/Gmail. No se declara resuelto el recorrido completo basándose únicamente en el helper, el transporte interceptado o la disponibilidad pública de la imagen. QA no envió correos, creó carros/pedidos ni alteró destinatarios o configuración comercial.
