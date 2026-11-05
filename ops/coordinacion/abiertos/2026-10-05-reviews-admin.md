# Reseñas: operación del administrador y promesas al cliente

estado: esperando-respuesta — correcciones funcionales verificadas por QA en local y producción; pendiente sólo comprobar recepción del aviso de puntos en la bandeja productiva de la clienta
de: reviews_audit (desarrollo del flujo de reseñas), coordinado por root
para: root y reviews_qa
archivos: public_html/wp-content/plugins/akibara-rewards/src/Reviews/{Invitations,ReviewNudge,ReviewForm,RewardMessage,VerifiedPurchase,ReviewPoints,AdminReviews,AdminOverview}.php; src/Support/{Alerts,SettingsPage}.php; tests relacionados

## Hallazgos reproducidos antes de implementar

1. El recordatorio de reseña en el correo de pedido promete foto y puntos pero enlaza a la ficha `#reviews` (ReviewNudge::donde_resenar), cuyo formulario nativo no ofrece foto. Control: búsqueda de campos de foto en plugin/tema/business sólo encuentra ReviewForm y AdminReviews; no existe upload en la ruta enlazada. Solución: explicar el envío separado según estado/plazo configurado, sin fabricar tokens ni adelantar invitaciones.
2. Invitación, formulario y agradecimiento prometen puntos por cada aprobación. El motor aplica cadencia, límite por cliente, compra verificada y cuenta pendiente. Control: ReviewPointsTest prueba cada N, tope y pending_account; los mensajes no consultan esas condiciones. Solución: texto único de requisitos acorde al motor, sin cambiar montos ni política.
3. Invitations agota tres fallos sin disparar la alerta `akb_rewards_email_gave_up`, y Alerts ignora el hook de invitación. Control: lectura de salida después de META_ATTEMPTS y switch de Alerts. Solución: nota de pedido y alarma compatible con el circuito existente.
4. Borde adicional enviado a root/QA: con cada 3, aprobar reseña A, desaprobar A (todavía no premiada), aprobar B y C acredita 500 con sólo dos reseñas aprobadas. Reproducción en memoria usando fixture ReviewPointsTest y FakeWpdb: contador tras aprobar A=1, tras desaprobar A=1, saldo tras B+C=500. La salida de maybe_reverse por puntos<=0 conserva META_COUNTED. Root toma ReviewPoints; este carril no lo modifica.

## Evidencia basal

- Suite existente de Reviews: 147 pruebas / 388 aserciones en verde.
- Esta suite usa dobles y no reemplaza la validación Docker/UI/correo ni la medición posterior en producción.

## Validación de implementación

Núcleo implementado por reviews_audit:

- RewardMessage::text comparte monto, cadencia, tope, compra verificada y requisito de cuenta entre invitación, formulario, agradecimiento y recordatorio.
- Recordatorio sin CTA a la ficha nativa, sin emitir token; explica correo separado con días/estado vigentes y espera de preventa. Con invitación ya enviada identifica el asunto del correo. Con invitación apagada no promete un envío.
- Tras tres fallos, nota del pedido + alerta existente al dueño; un job duplicado no vuelve a enviar un cuarto correo.
- No se modificaron montos, plazo, estado disparador ni ajustes productivos.

Validación:

- Pruebas dirigidas de InvitationFlow, RewardMessage, AccountSurfaces, Alerts y opt-out: 63 pruebas, 225 aserciones, verde.
- PHPCS de los cinco archivos de producción de este carril: verde.
- git diff --check de archivos modificados de este carril: verde.
- Suite total durante cambios concurrentes: 732 pruebas; sólo fallaron dos expectativas antiguas en EvidenciaDeEstadosTest por la corrección de SettingsPage de root. Informado a root; no modifica pruebas ajenas este carril.
- Pendiente: Docker/UI/correo real local y medición posterior al despliegue por QA. Desarrollo no cierra este asunto.

## Reapertura QA: compra verificada en Listo para enviar

- Estado del hallazgo: en-curso, asignado a reviews_audit; no cerrado.
- QA reprodujo en Docker con configuración equivalente a producción: shipping-progress pagado, foto y cuenta correctas, aprobación deja verified=0 y no acredita 500. Control: WooCommerce sólo consulta processing/completed en wc_customer_bought_product.
- Corrección delimitada: prueba específica del pedido enlazado por el formulario, usando WC_Order CRUD (HPOS), identidad, producto vigente, estados pagados propios y fecha de pago para shipping-progress. Sin ampliar los estados pagados globales de WooCommerce. Revalidación al aprobar para no fiarse de verified antiguo; la insignia se escribe también al crear desde token.
- ReviewPoints tiene cambios anteriores de cadencia de root que deben preservarse.

### Implementación de compra verificada — esperando-respuesta

- Implementado VerifiedPurchase::order_confirms y ::for_review, sólo para el pedido enlazado por ReviewForm::META_ORDER. Requiere correo coincidente, cuenta coincidente (o invitado con cuenta posterior de ese correo), estado de OrderStates::PAID, date_paid para shipping-progress, cantidad conservada del producto y ausencia de reembolso total del pedido.
- ReviewForm escribe la insignia antes de comment_post: WooCommerce usa add_comment_meta unique y no la sustituye por su falso 0. ReviewPoints revalida el pedido al aprobar aunque verified anterior diga 0 o 1; reseñas sin vínculo conservan cálculo nativo.
- Se preservaron los cambios de cadencia de root y no se cambiaron estados pagados globales ni configuración productiva.
- Regresiones: pago único 500 con shipping-progress/verified0 y hook de correo, falta fecha pago, pedido ajeno por cuenta o correo, producto ajeno, devoluciones de línea/importe, pending/on-hold/cancelled/failed/refunded, invitado y cuenta posterior, devolución parcial y sello1 antiguo tras anular, formulario pendiente con insignia1.
- Suite completa actual: 756 pruebas / 6650 aserciones verde. PHPCS de VerifiedPurchase, ReviewPoints y ReviewForm verde; diff-check verde.
- QA informado para repetir reproducción Docker y buzón. No aprobado ni desplegado desde desarrollo.

## Reparación administrable de una reseña ya aprobada

- Estado: en-curso, reviews_audit toma únicamente AdminReviews.php y tests nuevos.
- Root verificó una reseña real aprobada con foto y pedido pagado enlazado, pero verified=0/sin premio por el defecto de Listo para enviar. Se necesita reintentar sin ocultar la reseña ni acreditar a mano.
- Control nativo: Woo ReviewsListTable::handle_row_actions usa comment_row_actions (línea 581); WordPress wp_transition_comment_status sólo avisa a nuestro hook si cambia el estado (línea 1949), por lo que editar/guardar aprobada no reintenta.
- Solución: acción «Revisar puntos» protegida por permiso y nonce, sobre reseña aprobada de producto; llama al motor idempotente y muestra resultado. No cambia publicación ni salta requisitos. Core liberado para root.

## Aclaraciones del dueño y comprobación actual — 5 de octubre

- El dueño pidió asesoría antes de seguir cambiando reglas: reseña sin premio, foto condicionada al premio, cuenta y administración de clientes. La propuesta está en `output/reviews-admin-20261005/propuesta-operativa.md`; no es implementación.
- Procesando significa pago comprobado. Listo para enviar significa entrega al transportista, con tránsito aproximado de cinco días. Retiro o entrega personal puede pasar directamente a Completado. La configuración debe cubrir ambas rutas sin depender de Blue Express y sin duplicar o reiniciar invitaciones.
- El código actual de CompletionPolicy exige completed para a pedido/preventa aunque ya estén listos: no basta ajustar el disparador de Rewards para cumplir el despacho + seis días también en esos pedidos. Mantener la protección de artículos pendientes al adaptar la regla.
- Verificación productiva sólo lectura: la autora de #2254 sí tiene cuenta #197, creada el 18 de julio; pedido #28030 y comentario apuntan a esa cuenta. Rol subscriber no significa ausencia de cuenta. No se investigó el método original de registro. No se debe negar el premio por supuesta ausencia de cuenta.
- Relectura productiva: delay=6, trigger=shipping-progress, versión0.4.18. Root no hizo esa mutación de options en esta continuación. No hay despliegue ni acreditación manual de root.
- Suite local host después de retomar: 771 pruebas /6702 aserciones verdes, incluye AdminReviewPointsTest. Falta QA integral independiente de las nuevas reglas; el último informe HTTP conservado corresponde a la reproducción fallida anterior al arreglo (limpieza exacta completada).
- Sesión efímera del administrador QA local revocada y archivo temporal de credencial eliminado. No se creó usuario ni sesión de producción.
- Desarrollo no cierra este asunto.

## QA independiente — reglas operativas nuevas

Responsable: `reviews_qa`. Flujo HTTP, correo, programación y cadencia locales aprobados independientemente; falta terminar comprobación de interfaz administrativa y medir producción después del despliegue. No hay cierre productivo todavía.

- `qa-scheduling-report.json`: **29/29 verdes** en Docker real. Pago en Procesando no agenda; despacho y retiro agendan seis días; paso posterior a Completado y evento repetido conservan fecha y un único job; cambiar ajustes conserva la cola. A pedido pendiente bloquea ambos caminos, listo permite ambos; retroceso, cancelación y falta de pago impiden enviar. Cuatro invitaciones recibidas una vez cada una en Mailpit; cero en los tres caminos bloqueados. Options y conteos de pedidos, productos, comentarios y tokens restaurados; Mailpit previo conservado.
- `qa-cadence-report.json`: **11/11 verdes**. Con cada tres, A aprobada y retirada antes de premio libera avance; B y C no pagan; D paga 500; reaprobación de A no duplica. Conteos, options y buzón restaurados.
- **Borde corregido y vuelto a medir por QA:** `qa-cadence-after-paid-report.json`, **11/11 verdes**. El fallo inicial reproducido fue premio extra al retirar A después de pagar a C. Tras arreglo de root: A+B+C pagan 500; retirar A y aprobar D/E/F conserva total 500; G completa seis reseñas vigentes y entonces llega el segundo premio. Conteos, options y buzón restaurados. QA no escribió la implementación.
- `qa-policy-report.json`: **51/51 verdes** con HTTP y Docker real. Procesando pagado seguido de shipping-progress agenda seis días; cuatro invitaciones recibidas en Mailpit. Opinión normal sin foto válida y sin puntos; foto sin solicitar tampoco paga; foto+solicitud espera aprobación y acredita 500 una vez. Todas las opiniones son de dos estrellas. Invitado espera cuenta y verificación oficial de correo, y ni reaprobar antes de verificar permite cobrar. Pendiente no visible, aprobada visible, token usado no duplica, correo de premio exactamente uno. Limpieza contada exacta de usuarios/comentarios/metas/pedidos/tokens/ledger/fotos/productos; options restauradas y correo anterior preservado.
- `qa-error-retention-report.json`: **8/8 verdes** en HTTP después del último ajuste del formulario. Solicitar sin foto conserva producto, calificación, contenido escapado y casilla al mostrar error; token disponible y cero reseñas nuevas; nuevo GET desmarca. Token temporal eliminado inmediatamente.
- Filtro de ediciones: QA CUA independiente en alta y editor de serie 416. Naruto muestra sólo Naruto y Naruto Jump Remix; tokyo encuentra Tokyo Revengers; sin coincidencias oculta todo; vaciar restaura lista. Selecciones ocultas se conservan, incluida edición609 previamente guardada. No se guardaron cambios de series.
- La reproducción anterior `qa-integration-report.json` corresponde al defecto de compra verificada en shipping-progress antes de corregirlo. Su limpieza terminó exacta; no es evidencia del estado final de la implementación.

Todos los guiones e informes de esta ronda están en `output/reviews-admin-20261005/`. No se crearon fixtures, correos ni movimientos QA en producción.


## Desarrollo: reseña normal y solicitud explícita de premio — esperando-respuesta

Implementación local por reviews_audit, pendiente QA independiente:

- Foto opcional para opinión normal. Casilla `akb_rf_points_requested` sin preselección; si se marca exige foto y registra `_akb_rewards_review_points_requested=1`; sin marcar registra 0. La meta se incluye al insertar, antes de hooks. La ruta normal no acredita ni crea pending_account al aprobar, crear cuenta, verificar o reintentar. Meta ausente conserva política histórica (incluido el caso real ya documentado).
- «Todavía no lo recibí» es un aviso desplegable fuera del formulario: informa que vuelva con el mismo enlace y su vencimiento real UTC, no registra reseña, consume token ni programa recordatorios.
- RewardMessage explica premio opcional y requisitos, agradecimiento distingue solicitud/no solicitud. Nudge describe despacho y retiro con plazos configurados y no emite enlaces anticipados. Root conserva ownership del copy de Invitations.
- Verificación de compra admite estado propio configurado de despacho/retiro o el disparador almacenado en ese pedido, sólo con date_paid. Pending/on-hold/checkout-draft/caídos siempre rechazados, incluso si se configurasen o guardasen como disparador. Processing/completed conservan tratamiento nativo.
- Control pedido por QA confirmó un bypass anterior: resolución de invitado por correo aceptaba cuenta recién creada sin verificar. Ahora consulta EmailVerificationService::is_verified del Woo actual; reintentar como admin tampoco evita la verificación. Esto sólo afecta resolución por correo (user_id=0), no cambia identidad ya ligada a cuenta en el pedido.
- «Revisar puntos» y badge explican cuando el cliente no los solicitó; no publica/despublica, no fuerza requisitos ni duplica premio/correo.

Validación de desarrollo: 114 pruebas focales, 383 aserciones verdes; PHPCS de ReviewForm, RewardMessage, ReviewNudge, ReviewPoints, VerifiedPurchase y AdminReviews verde. Suite completa en paralelo: 786 pruebas, fallos de mocks update_meta_cache en CarouselTest (14), fixtures sin pago en AdminOverviewTest y LaInvitacionTieneSalidaTest; owners informados y fixture de LaInvitacion actualizado aquí. No se tocó producción ni se cerró el hallazgo. QA ejecuta qa-policy.mjs sobre HTTP/Docker y servicio oficial de verificación.


### Corrección UX adicional y lectura antifraude

- QA señaló pérdida de campos al rechazar una solicitud de puntos sin foto. ReviewForm ahora conserva texto, estrellas, producto y decisión de puntos del POST con nonce válido; la renderización escapa el contenido. En GET nuevo la casilla permanece sin seleccionar. Test funcional de fallo/reintento verifica los cuatro campos y token disponible; 24 pruebas /91 aserciones verdes, PHPCS verde.
- Lectura independiente de referidos/token/dinero: Referrals rechaza referencia a cuenta propia o mismo correo al registrar, compara RUT/teléfono/correo configurados y compras previas al acreditar; no paga por mero registro. Ledger mantiene event_key único y candado por usuario. ReviewForm valida pertenencia del producto, token aleatorio de 40 caracteres, vencimiento y toma condicional del único uso antes de insertar; fallo de insert devuelve el token y limpia foto huérfana. No se confirmó otro bypass monetario en esta lectura limitada. El bypass de cuenta de invitado sin correo verificado sí fue reproducido conceptualmente por QA, corregido y cubierto por regresión arriba; QA mide servicio real.
- Suite dirigida FirstPurchase/Referrals/InvitationFlow/ReviewPoints/VerifiedPurchase: 106 pruebas, 320 aserciones verdes. Esto no sustituye concurrencia real ni constituye una garantía total contra cuentas con identidades falsas.

## QA de cierre: gate local del artefacto final

Responsable independiente: `qa_cierre`. **GO local** del artefacto `akibara-rewards-0.4.19.zip`; continúa esperando medición productiva, sin cierre todavía.

- Inventario de 54 archivos coincide con las fuentes finales. SHA-256 del ZIP `8a78b8c8258ce49d0090613258ef4293a697bb9ca6ee5bc6e29597dd59d88906` coincide con su checksum e informe de paquete.
- Se revisaron los informes finales: política 51/51, programación 29/29, cadencia 11/11 y 11/11, conservación ante error 8/8. Suite final 796 pruebas /7187 aserciones verde y paquete reproducible PASS.
- Restaban dos notas de los pedidos de prueba del reprogramador, IDs25500/25501. Antes de eliminarlas se verificó que pertenecían exactamente a los pedidos fixture32515/32516 ya borrados, eran `order_note` y contenían el cambio de estado esperado. Se eliminaron únicamente esas dos notas locales.
- Conteos restaurados exactamente al punto anterior a QA UI: usuarios91, comentarios17962, commentmeta10321, productos1632, pedidos335, ledger43, tokens14. Usuario469, producto32513, pedido32514 y reseña25499 ya no existen. El informe de limpieza conserva el conteo inicial incompleto y registra la reparación.
- Evidencia: `output/reviews-admin-20261005/qa-final-local.json` y `qa-ui-cleanup.json`. No se tocó producción en este cierre local.

## QA independiente posterior al despliegue

Responsable: `qa_cierre`. **Correcciones funcionales aprobadas en producción.** La coordinación permanece esperando-respuesta únicamente por la recepción en bandeja real del aviso de puntos, conforme a la regla de AGENTS. No se declara entrega a partir de un log o sello de envío.

- Rewards0.4.19:54/54 hashes del runtime productivo coinciden con el artefacto final que pasó el gate local.
- Reseña real2254 permanece aprobada, foto presente, autora/cuenta197 y pedido28030 pagado coinciden. Insignia `verified` cambia0→1. Acreditación autorizada exactamente500; saldo1980→2480. Una única fila de ledgerID81/event_key`review_u197_p16026`.
- Tras repetir «Revisar puntos» en la UI, la nueva lectura deja invariables saldo, fila de ledger, sello500 y marca de aviso1791254869. Root observó el aviso «no se acreditaron puntos nuevos».
- Despacho`shipping-progress`6 días y retiro`completed`6 días explícitos persistidos. Disclosure activado. Diez invitaciones pendientes conservan exactamente ID, fecha y argumentos frente al respaldo fresco anterior al despliegue.
- Ficha pública anónima HTTP y UI: opinión real, insignia de propietario verificado, aviso de que recibió puntos y foto servidos. CUA confirma opinión y foto visibles al abrir el enlace público#reviews. El primer control de visibilidad fue falso por pestaña nativa cerrada: se descartó con el control correcto; no era un defecto.
- Filtro de ediciones633 en CUA productivo: Naruto sólo Naruto/Naruto Jump Remix; tok sólo Tokyo Ghoul/Tokyo Revengers; inexistente0; vaciar114/114. Selección631 conservada aunque se oculta y relación persistida[631]. No se guardaron series. El hash del archivo productivo coincide con el local. Filtro cerrado en su coordinación propia.
- No quedan `.maintenance`, directorio temporal del filtro ni directorios temporales de Rewards. Respaldo únicamente local. QA no creó fixtures, movimientos ni correos productivos.
- Correo de premio recibido exactamente una vez en Mailpit durante QA local. En producción el sello acredita aceptación del transporte y permanece constante; **la bandeja real de la clienta no fue comprobada**.
- Evidencia: `output/reviews-admin-20261005/qa-final-production.json`, `qa-production-final.json`, `qa-production-operations.json`, `qa-production-runtime-hashes.txt`, `qa-production-series-naruto.jpg`, `qa-production-review-visible.jpg`.
