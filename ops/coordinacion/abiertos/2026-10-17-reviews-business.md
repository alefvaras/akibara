# Reseña voluntaria y reseña incentivada — decisión aprobada

estado: esperando-respuesta — fuentes principales publicadas y verificadas por lectura; cierre pendiente de controles finales y QA de caché
de: root (orquestación y QA), review_flows_dev y review_rewards_copy_dev (desarrollo)
para: QA independiente
archivos: akibara-rewards/src/Reviews/{ReviewForm,VoluntaryReviewForm,RewardMessage,Invitations}.php; src/Points/Emails.php y pruebas dirigidas; conserva candidato previo de carrusel sin cambiar sus reglas.

El dueño aprobó la propuesta de dos recorridos tras consulta: voluntaria con foto opcional/sin premio automático, incentivada por enlace con foto obligatoria y monto/equivalencia visibles; salida hacia voluntaria; puntos potenciales → pendientes → acreditados. Conserva comprador invitado que crea y verifica cuenta después, montos, topes, cadencia, moderación sin sesgo de estrellas, identificación del incentivo y UI existente.

UI productiva leída el 17 de octubre: montos/topes/disclosure configurables; no existe opción para modos del formulario ni equivalencia calculada. Código necesario; sin mutaciones de ajustes para esta decisión. Disclosure ya encendido.

Gate local: pruebas dirigidas, HTTP real/mails Mailpit y navegador móvil/escritorio, restauración contada. Gate productivo: archivos exactos con respaldo solo local, hashes/runtime/rendering, QA independiente y Graphify final. No crear reseñas ni pedidos ficticios en producción.

## Corrección final del dueño — 17 de octubre

La invitación incentivada NO ofrece salida «sin foto/sin puntos»: el formulario del correo requiere foto y solicitud de recompensa. El formulario nativo de la ficha conserva foto opcional y premio desactivado. Se mantiene la cuenta posterior para invitado. Desplegar SOLO reseñas; el otro agente es dueño del carril series/look/precios, documentado en `ops/coordinacion/resueltos/2026-10-17-propuesta1-precios-blancos-coherencia.md`. Los manifiestos P2 anteriores NO autorizan reponer CSS de precios. Revisar su resultado después de terminar reseñas, sin sobrescribirlo.

## Avance verificable — 18 de octubre

Los 11 archivos iniciales de reseñas se promovieron con baselines exactos y rollback local. Un corte de SSH antes de activar el flujo se revirtió correctamente; el segundo despliegue completó. Corrección adicional de moderación ON/OFF y ayuda de AdminOverview publicada después de pruebas host/Docker y aprobación independiente por hashes. Sonda productiva de lectura comprobó formulario nativo real con foto opcional, multipart, nonce y puntos=0; enlace incentivado existente requiere foto, sin salida secundaria y no consume token al renderizar; conteos y ledger inalterados.

El dueño pidió quitar del home el aviso de puntos, conservándolo en la ficha: control independiente en desarrollo final, por defecto apagado. Se confirmó además caché anónima Cloudflare obsoleta tras purga sólo LiteSpeed; se prepara invalidación exclusiva de portada mediante Cache-Tag. No se cambian reglas globales de Cloudflare ni el carril de series/look/precios.

UI productiva: mixed/min1 guardado; prueba photos guardada, sesión de WordPress caducó antes de las siguientes pruebas. Modo photos temporal restaurado de forma acotada a mixed por WP-CLI con guardia de estado, para no dejar el experimento activo. Comprobaciones posteriores de text/off desde la pestaña caducada NO cuentan como evidencia válida. Se solicitó reautenticación al dueño para seguir por UI.
