# Crítica de lector novato — `16-todo-lo-programado.md`

Quién escribe: alguien que llegó hoy, no sabe qué es WordPress ni WooCommerce, no conoce el negocio,
y tiene que ser capaz de **operar y depurar** esto sin preguntarle a nadie.
Contrasté cada duda contra el snapshot de producción
(`…/scratchpad/snapshot-prod/`) y contra `mapas-b/cross-cron/verificacion.json`.

Veredicto corto: el capítulo es el mejor inventario de trabajos programados que he visto en el
proyecto y casi todo lo que afirma se sostiene línea a línea. Falla en otra cosa: **está escrito
para quien ya sabe operar la tienda**. No trae un solo comando, ni una sola ruta de pantalla real, ni
dice dónde se apaga o se edita un correo. Un novato lo entiende y aun así no puede tocar nada.

---

## 1 · Lo que NO entendí

**1.1 · «Las diez fases» y solo aparecen ocho.**
§18 dice: «Una reconstrucción pasa por `PREPARING`, `BUILDING`, `CATCHING_UP`, `SWAP_INTENT`,
`SWAPPED`, `PUBLISHING`, `CLEANUP` y `DONE` (`RebuildJob.php:87-96`). Terminales hay tres: `DONE`,
`BLOCKED` y `ABORTED`». Cuento ocho, luego tres, y una se repite. **Debería decir:** «Diez fases: las
ocho del camino normal, más `BLOCKED` y `ABORTED`, que son salidas de emergencia. Tres son
terminales: `DONE`, `BLOCKED` y `ABORTED`.»

**1.2 · Tres palabras para tres cerrojos distintos, y solo dos están en el glosario.**
El capítulo usa «candado» (§7, §14, §15, caso 6), «permiso» (§14 «un permiso con token y caducidad de
30 minutos»; §18 «recupera permisos vencidos»; tabla «los lotes se reclaman con permiso») y «reclamo
atómico» (§6, §10, §11). El glosario define *candado* y *reclamo atómico*, **no *permiso***.
**Debería decir** qué es cada uno y cuál es cuál: el «permiso» de un lote del buscador es un
*lease* de 120 s (`akibara-search/src/RebuildJob.php:61`), cosa distinta del candado de 15 min de
`_akb_preventa_datechg_lock` y distinta del `UPDATE … WHERE estado='pendiente'` de `mark_notified()`.

**1.3 · «La bomba».**
§17 se titula «la bomba viva del buscador» y el caso 4 dice «despierta la bomba del índice». Sin
definición, «bomba» se lee como explosivo. El hook real es `akb_search_pump_live` — *pump*, bomba de
achique: **saca** deuda acumulada. **Debería decir** eso en la primera frase de §17 y entrar al
glosario, que hoy tiene «Deuda» pero no «Bomba».

**1.4 · El vocabulario del buscador entra sin presentación.**
«foto de precios», «el recibo», «la huella», «la secuencia», «publicaciones de generación»,
«topología», «el cambiazo», «facetas». Aparecen en §18, §20, caso 4 y en dos diagramas. Ninguna está
en el glosario. La frase que no logré traducir es la del caso 4, paso 1: «consume publicaciones de
generación y recupera permisos vencidos». **Debería** haber un párrafo de cuatro líneas al empezar
§17 explicando qué es un índice, qué son las tablas sombra, qué es una «generación» y qué es el
«recibo» de precios — o, si eso vive en el capítulo del buscador, un enlace explícito a él.

**1.5 · §2, «`run()` (dos pasadas)».**
¿Qué dos pasadas? No se dice ni aquí ni en el caso 1. Leyendo el código son dos
`OrderQueryPaginator::process_all()` seguidas (`ReadyToShipCron.php:131` y `:136`): una para marcar
listos y otra para dejar las notas de atraso. **Debería decirlo**, porque explica por qué este cron
es el más caro del día.

**1.6 · Clases que escriben datos y nunca se presentan.**
La tabla «Datos que deja» atribuye metas a `ReminderEmail`, `ReadyToShipEmail` y `SendFailureRearm`;
§5 apoya su trampa «alta» en `SendFailureRearm` contando hasta 3. Ninguna de las tres aparece en
«Piezas» ni con ruta de fichero. Existen: `…/OrderPreventa/ReminderEmail.php`,
`ReadyToShipEmail.php`, `SendFailureRearm.php`. Lo mismo con `PreventaItems` (caso 1, paso 6),
`FulfillmentCapabilities` (§8), `CompletionPolicy` (§23), `MetaKeys`, `Arrival.php` (listado en los
«Ficheros» de §19 sin una palabra), `HealthReport.php` y `SearchAdminSection.php` (§18).
**Debería** haber una lista de «piezas de apoyo» con una línea cada una, o al menos la ruta.

**1.7 · §10 anuncia tres piezas y lista cinco ficheros.**
«Tres piezas que van juntas: el vigía de stock, el despachador por tandas y la retención» seguido de
`StockWatcher.php`, `NotificationDispatcher.php`, `InstockEmail.php`, `Retention.php`,
`SubscriptionNotice.php`. Luego el texto describe cuatro comportamientos (los tres anunciados más el
correo de alta). **Debería decir** «cuatro piezas» y nombrar `SubscriptionNotice` en la enumeración.

**1.8 · §16, la «valla de propietario», es el párrafo más opaco del capítulo.**
«Una cadena de limpieza que borra, al cabo de unos días, las filas temporales que se crean al
vincular un pedido hecho como invitado con una cuenta.» No sé: qué es «vincular un pedido de
invitado», qué «filas temporales» (dos líneas más abajo resultan ser *options*, no filas), qué cerca
(*valla*) y de qué, ni qué pasaría si no se borraran. «Vencimiento blando (2 días) y tope duro (7
días)» tampoco se explica. **Debería** empezar por el hecho de negocio: «un cliente compró sin cuenta
y después se registra; hay que probar que ese pedido es suyo sin dejar abierta la puerta para
siempre».

**1.9 · §20, «se apagan buscador y facetas».**
No sé qué ve el cliente cuando eso pasa. El dato existe pero está escondido en el diagrama del caso
4 («la sugerencia de búsqueda responde 503»). **Debería** estar en el texto de §20 y en el caso 4.

**1.10 · «El interruptor de despacho» sin motivo.**
§10 y el caso 2 giran sobre `akb_bis_dispatch_enabled`, pero en ningún lado se dice **por qué** el
dueño lo apagaría ni qué ve mientras está apagado. Sin eso, la bifurcación A del caso 2 es una rama
sin causa.

**1.11 · «Inmutable hacia atrás».**
Aparece tres veces (§2, caso 1, Reglas) y nunca se traduce. **Debería decir**: «una vez que dice
`yes`, ningún proceso lo vuelve a poner en `no`».

**1.12 · El capítulo enseña que `as_next_scheduled_action()` y `as_has_scheduled_action()` son
trampas, y luego las usa sin avisar.**
§17 dice: «No usa `as_has_scheduled_action`, porque esa función también cuenta la acción **en
curso**». Pero el guard de alta de la lista de espera la usa (`BackInStock/StockWatcher.php:105-107`)
y el reintento del correo «ya llegó» también (`ReadyEmailDispatcher.php:352-354`), y el capítulo no
lo menciona en ninguna de sus dos fichas. Como lector concluyo o que la regla no es una regla, o que
hay dos defectos más sin inventariar. **Debería** resolverse la contradicción explícitamente.

---

## 2 · Lo que FALTA para poder operar o depurar esto sin ayuda

**2.1 · Ni un solo comando, en un capítulo que remite a la línea de comandos cinco veces.**
§1: «Solo se ve por línea de comandos». Caso 4, paso 5: el correo trae «los dos comandos a probar» —
que no se escriben. §18 describe el guion de rescate («suelta la sombra **primero**») y **nunca lo
nombra**: es `ops/rescate-buscador.sh produccion`. Y el código propio expone **trece** comandos de
WP-CLI que el capítulo ignora por completo: `akb-search rebuild|status|reindex|reconcile`
(`akibara-search/src/Cli.php:52-55`), `akibara order-preventa-backfill|order-preventa-recompute-ready|
order-preventa-cumplimiento` (`…/OrderPreventa/WpCli.php:54-56`), los dos de la vitrina
(`CatalogOrder.php:73, :81`), `akibara:upsells-fill` (`UpsellsAutofill.php:79`) y los de
`akibara-rewards` (`Support/Audit.php:91`, `Points/Migration.php:57, :65`).
**Debería** haber una sección «Cómo se mira y cómo se fuerza» con: `ssh akibara`, `wp cron event
list`, `wp action-scheduler list --hook=… --status=pending|failed --format=count`,
`wp action-scheduler run --hooks=…`, la tabla de los trece comandos propios y
`ops/rescate-buscador.sh produccion`.

**2.2 · Ninguna hora se puede cambiar con lo que dice el capítulo.**
La tabla da 13 horas y el diagrama del reloj las pinta, pero no hay una línea sobre **cómo mover
una**. Y es lo primero que pide un operador («los correos de puntos salen a las 17:15, quiero que
salgan a las 03:00»). El procedimiento real es: dar de baja la acción recurrente y dejar que el
`ensure_scheduled()` de `init` la recree a `time() + 1 h` (o `+2 h`). Peor: el único sitio donde el
capítulo casi lo explica, §18, copia del código un comando **con el grupo equivocado** y lo señala
como trampa… sin escribir el comando correcto. **Debería** decirlo: `as_unschedule_all_actions(
'akb_search_rebuild_watchdog', array(), 'akibara-search' )`, y el equivalente para cada carril.

**2.3 · Los correos: no se dice cuál se puede apagar, cuál se puede editar y dónde.**
El capítulo lista diez asuntos de correo en seis tablas «Correos que salen» y no dice de ninguno si
es un `WC_Email` (apagable y editable desde el panel de correos de WooCommerce) o un `wp_mail()`
plano. Comprobado en el código: `ReminderEmail` es `WC_Email` y **su asunto y encabezado son campos
de formulario** (`…/OrderPreventa/ReminderEmail.php:700-712`, con el aviso de que los modos
«cambio de fecha» y «fecha anunciada» usan texto propio); los dos correos de puntos salen por
`WC()->mailer()->send()` **sin ser `WC_Email`** (`akibara-rewards/src/Points/Emails.php:142-170`), o
sea que no se pueden apagar desde el panel de correos; el de alta de la lista de espera es
`wp_mail()` plano a propósito (esto sí se dice). **Debería** haber una columna «tipo / dónde se
edita / cómo se apaga» en cada tabla de correos.

**2.4 · «El correo está deshabilitado en WooCommerce» es una causa de avería tres veces y nunca una
ruta.**
Aparece en §11 (trampa media, congela la cadena de avisos de tomo nuevo), en el caso 2 (bifurcación
C) y en el caso 3. Un novato no sabe dónde está esa casilla. **Debería decir**: *WooCommerce →
Ajustes → Correos electrónicos*, y nombrar la casilla exacta por correo.

**2.5 · Las pantallas no tienen URL.**
«*Akibara → Pedidos y preventa*», «*Akibara → Buscador*», «*Akibara → Salud SEO*», «panel de Akibara
Puntos»… trece menciones, cero direcciones. El propio código citado en §14 lleva la respuesta:
`'page' => 'akibara-panel'` (`LlmsManifest.php:523`). **Debería** dar
`/wp-admin/admin.php?page=akibara-panel&tab=<id>` y el `tab` de cada pestaña — más aún sabiendo que
el slug corto `akibara` devuelve 403 y parece un problema de permisos.

**2.6 · Las cinco options que deciden si sale un correo: se denuncian, no se enseñan a manejar.**
El recuadro final dice que `akb_search_serving_alarm`, `akb_bluex_watch_baseline_seen` y
`akb_bluex_watch_resolved_notified` no tienen pantalla y que el dueño «no tiene forma de verlo ni de
reiniciarlo sin acceso al servidor». **Falta justo eso**: qué forma tienen esos valores, qué
significa cada uno y las dos líneas que los leen y los reinician (`wp option get …`,
`wp option delete …`). Solo de la primera se da la forma (`{down_since, notified_at}`).

**2.7 · «Las alarmas no salen fuera de producción» sin decir qué decide «producción».**
Es `wp_get_environment_type()` (`akibara-search/src/RebuildJob.php:828`), que se alimenta de
`WP_ENVIRONMENT_TYPE` en `wp-config.php`. Quien depure «no me llegó la alarma» necesita ese dato
antes que ningún otro. Y la «bandeja de captura» del espejo no se nombra: en el snapshot hay
`mu-plugins/akibara-correo-no-sale-a-clientes.php`, que el capítulo no menciona ni en su lista de
mu-plugins.

**2.8 · No hay «señales de vida»: cómo saber si un trabajo corrió.**
§28 deja claro que la única pantalla vigila 3 eventos y **nada** de Action Scheduler. Pero entonces
el capítulo debe dar el sustituto manual: por cada trabajo, qué option/transitorio/nota escribe y
qué valor sano se espera (`akb_catalog_order_last_run`, `akb_search_reconcile_last`,
`akibara_business_llms_manifest_last_success`, `akb_upsells_autofill_last_run`…). Los datos están
dispersos en la tabla «Datos que deja»; falta la vista «diagnóstico».

**2.9 · La tabla de grupos que se necesita a las 3 de la mañana no existe.**
Los grupos salen de a uno por ficha: `akibara-order-preventa`, `akibara-bis`,
`akibara-series-follow`, `akb-catalog-order`, `akibara-business`, `akibara-search`, `akibara-bluex`
y **sin grupo**. La advertencia de las bajas depende entera de esa lista. **Debería** ir una tabla
«grupo → trabajos → cómo se cancela el carril completo» en un solo sitio.

**2.10 · La advertencia de las bajas no dice qué hacer hoy.**
Diagnostica muy bien que falta `as_unschedule_all_actions()`, y no da la maniobra manual para limpiar
un grupo huérfano con lo que hay instalado. Para un operador es la mitad útil.

**2.11 · Piezas del snapshot que el capítulo no menciona y que el lector va a encontrar solo.**
Comparando `ls` del snapshot con las piezas:
- `mu-plugins/akibara-discovery-warming.php` **existe en producción**. El capítulo lo despacha en
  una fila de tabla como «retirado en la 1.6.0 · Comprobado ausente en producción». El *evento* se
  retiró; el *fichero* sigue, y hoy solo fija cabeceras de caché de los sitemaps
  (`akibara-discovery-warming.php:27-43`). Quien haga `ls mu-plugins/` va a creer que la tabla miente.
- `mu-plugins/akibara-correo-no-sale-a-clientes.php` y `akibara-clarity-production-only.php`: el
  primero es la «bandeja de captura» del punto 2.7; el segundo explica por qué
  `clarity_collect_batch_cron` corre en producción y no en el espejo, dato de la sección «lo que no
  es nuestro».
- `akibara-search/src/SearchAdminSection.php:272-276` encola `Reconciler::HOOK` desde el panel. El
  capítulo dice «más el botón del panel» sin fichero ni línea, y es el único camino manual para
  auditar el índice.
- `…/OrderPreventa/PreventasScreen.php:263, :366` corre el mismo `OrderQueryPaginator` desde una
  pantalla. §3 dice «Dónde lo opera el dueño: en ninguna parte», y es engañoso: la pantalla de
  preventas dispara pasadas completas sobre pedidos a demanda.
- `BackInStock/Install.php` y `SeriesFollow/Install.php` crean las dos tablas que el capítulo
  declara como datos; sin ellos no se sabe de dónde salen ni cómo se recrean.

**2.12 · La trampa «alta» del reintento sin tope no trae la comprobación que la cierra.**
§5 afirma «una acción fallida cada cinco minutos por pedido, para siempre». Es exactamente el tipo de
cosa que hay que **medir en producción** antes de creerla. El comando cabe en una línea:
`wp action-scheduler list --hook=akb_order_preventa_dispatch_ready_email --status=failed
--format=count`. Sin él, el capítulo deja una alarma encendida y sin instrumento.

**2.13 · `'weekly'` sin origen.**
§4 dice «el intervalo `'weekly'` es fijo». No dice de dónde sale (es un intervalo del núcleo de
WordPress) ni que Akibara **no** define ningún intervalo propio. Es la primera pregunta de quien
quiera cambiarlo.

**2.14 · Falta el «qué hago si» de la retención.**
§26 dice que se pasó de 31 a 14 días y que se midió el ahorro. No dice dónde se ve la cola
(*WooCommerce → Estado → Acciones programadas*, dato que solo aparece sepultado en la última fila de
la tabla de datos) ni cómo se revierte el filtro si un día hace falta el historial largo.

---

## 3 · Lo que CONTRADICE al código

**3.1 · El «efecto lateral» de la retención a 14 días es falso.**
§26 dice: «también borra el rastro de las acciones que **fallaron** y se re-encolaron, así que un
ciclo de reintentos sin tope pierde su historia a los 14 días», y §5 lo repite («con la retención de
la cola a 14 días, el rastro de cuándo empezó se borra solo»). El propio mu-plugin dice lo contrario:
«Acota la retención de acciones **COMPLETADAS/CANCELADAS** … **NO toca pending ni failed** (las
fallidas van por su propio filtro de 3 meses)» (`mu-plugins/akibara-as-retention.php:4`), y el código
es un único `add_filter( 'action_scheduler_retention_period', … )` (`:14-19`). Las acciones fallidas
del reintento sin tope **sí** conservan su historia. Hay que borrar las dos frases, porque además
son las que quitan el instrumento del punto 2.12.

**3.2 · El barrido semanal de Blue Express no está en el grupo `akibara-bluex`: no tiene grupo.**
Tabla «Lo programado»: «`akibara_bluex_integrity_weekly` | AS (`akibara-bluex`)». El código lo agenda
sin grupo: `as_schedule_recurring_action( time() + HOUR_IN_SECONDS, WEEK_IN_SECONDS,
'akibara_bluex_integrity_weekly' );` (`mu-plugins/akibara-bluex-integrity-watch.php:140`; el chequeo
previo, `as_next_scheduled_action('akibara_bluex_integrity_weekly')`, tampoco lleva grupo, `:139`).
El único que usa `akibara-bluex` es la purga de registros (`akibara-bluex-log-retention.php:14`,
`:112-118`). Importa porque el propio capítulo enseña que «sin grupo» es lo que vuelve incancelable
un carril: los trabajos sin grupo son **cuatro**, no tres.

**3.3 · Guardar la identidad de la tienda no encola las portadas sociales.**
Tabla «Lo programado», fila `akibara_business_social_image_derivatives`, columna «Quién lo programa»:
«`register()`; **el guardado de identidad**; el botón «Regenerar portadas»». El guardado de identidad
encola **solo el manifiesto**: `queue_refresh_after_identity_change()` hace
`wp_schedule_single_event( ( $this->clock )(), self::HOOK )` (`LlmsManifest.php:495-497`) y
`self::HOOK` es `'akibara_business_llms_manifest_refresh'` (`:41`). El botón sí bifurca hacia las
portadas (`:520-521`). **Debería decir**: «`register()`; el botón «Regenerar portadas»».

**3.4 · Las options del manifiesto no comparten prefijo.**
§14: «las opciones `akibara_llms_identity`, `..._last_success`, `..._last_error`, `..._lock` (…) y
`..._managed_files`». Con esa notación, el lector construye `akibara_llms_last_success` y no existe.
Los nombres reales son `akibara_business_llms_manifest_last_success` / `_last_error` / `_lock` /
`_managed_files` (`LlmsManifest.php:44, :47, :50, :53`) frente a `akibara_llms_identity` (`:63`). La
tabla «Datos que deja» los escribe bien; §14 no. Escribirlos completos.

**3.5 · «Tres de los ocho» no suma siete.**
§28: «**Tres de los ocho** eventos propios de WP-Cron. Quedan fuera `akb_upsells_autofill_cron`,
`akibara_business_llms_manifest_refresh`, `akibara_business_social_image_derivatives` y
`akb_preventa_vencida_sweep`». 3 + 4 = 7. El octavo es `akibara_business_social_image_for_slug`
(`uninstall.php:47`), que es puntual y por eso nunca podría vigilarse igual. **Debería decir**: «tres
de los siete recurrentes; el octavo evento propio es puntual».

**3.6 · `ABORTED` se lee como terminal en ocho sitios, no en cinco.**
§18: «se lee como terminal en cinco sitios (`HealthReport.php:300`, `SearchAdminSection.php:993`,
`EditorialDiscountPublication.php:2571` **y dos del propio `RebuildJob`**)». En `RebuildJob` son
cinco: `:458`, `:699`, `:1057`, `:2290`, `:2780`; además figura en el validador de forma del estado
(`:2769`). La afirmación importante —que **ningún fichero propio la escribe**— sí se sostiene: no hay
una sola asignación en todo `akibara-search/src/`.

**3.7 · La verificación declarada del tema no cubre el tema.**
«El tema `akibara-gp` **no programa nada**: verificado con búsqueda exhaustiva sobre `inc/`». El tema
tiene más de veinte ficheros PHP fuera de `inc/`: `functions.php`, `home.php`, `single.php`,
`archive.php`, `404.php`, `template-parts/**`, `woocommerce/**`. La **conclusión** es correcta (una
búsqueda de `wp_schedule_*` / `as_schedule_*` sobre todo el árbol del tema no devuelve nada), pero el
método declarado no la respalda, y este capítulo vive de que sus verificaciones se puedan repetir.
Cambiar «sobre `inc/`» por «sobre todo el árbol del tema».

**3.8 · La tipografía de las horas hace creer que cinco están escritas en el código, y no lo están.**
La tabla marca «(hora heredada del primer agendado)» solo en las de WP-Cron, y pone en **negrita**
`12:50`, `16:10`, `17:15`, `21:55` y `lunes 19:23` como si fueran fijas. Las cinco se agendan a
`time() + 1 h` o `time() + 2 h` la primera vez, igual que las heredadas: `Reconciler.php:115`,
`Birthday.php:72`, `Expiry.php:93`, `akibara-bluex-log-retention.php:112-118`,
`akibara-bluex-integrity-watch.php:140`. Las **únicas dos** horas escritas en el código son el domingo
03:00 de `RemindersCron` y las 05:00 de `CatalogOrder` (`CatalogOrder.php:44-45`, `:167-184`).
Marcar las cinco como heredadas o quitar la negrita.

**3.9 · §16 llama «filas» a lo que son *options*.**
«borra … las **filas temporales** que se crean al vincular un pedido» y, seis líneas después, «Lee y
escribe: **opciones** `akb_byp_owner_fence_{pedido}_{hash}` sin autocarga». Son options de
`wp_options`, no filas de una tabla propia; el capítulo distingue cuidadosamente ambas cosas en todo
el resto del texto.

---

## 4 · Lo que contradice o deja fuera a `verificacion.json` del área

**4.1 · La confirmación de la ventana ciega de 12 h no llegó al capítulo.**
`verificacion.json` → `sospechas_confirmadas_por_lectura[12]`: «Tanto `Birthday::ensure_scheduled()`
(:59-77) como `Expiry::ensure_scheduled()` (:80-98) salen en seco si el transient vale `'yes'`, y ese
transient se escribe por 12 h aunque la acción se borre a los cinco minutos (…) `Reconciler` comparte
el patrón pero el buscador tiene el vigilante recurrente y el hook
`action_scheduler_ensure_recurring_actions`; **los dos de puntos no tienen red**». El capítulo
menciona los transitorios cuatro veces como dato neutro («memorizado 12 h», «memoria de 12 h del
agendado») y **nunca dice que sean una ventana ciega**. Es el hallazgo confirmado más grave que el
capítulo dejó fuera, y pega justo donde el capítulo ya avisa (los puntos son lo que no puede
perderse). Debería ser una trampa conocida de §21 y §22.

**4.2 · La consecuencia práctica del error de la desactivación se perdió por el camino.**
`verificacion.json` → `errores[0]` cierra con: «Los huérfanos de desactivación son **dos** (los dos
de imágenes sociales)». El capítulo cuenta bien los números (seis al desactivar, ocho al desinstalar)
pero no dice la conclusión: **desactivar** el plugin deja vivos `akibara_business_social_image_derivatives`
y `akibara_business_social_image_for_slug` apuntando a callbacks que ya no existen — que es
literalmente lo que el comentario del propio código advierte (`uninstall.php:36-38`).

**4.3 · La trampa de `has_outstanding_items()` vs `pending_items()` se pidió expresamente y no está.**
`verificacion.json` → `sospechas_refutadas[2]` termina así: «Las dos funciones se parecen lo bastante
como para que ésta sea **la trampa que más falsos positivos va a producir en esta área**»
(`PreventaItems.php:50-87` y `:102-107`). El capítulo usa `PreventaItems::pending_items` en el caso 1
paso 6 y no advierte de la hermana que significa otra cosa. Quien depure «por qué no se marcó listo»
va a caer ahí.

**4.4 · «Inventario completo» frente a una cobertura declarada del 88 %.**
El capítulo se presenta como «El inventario **completo** de todo lo que la tienda hace sola» y remata
con «**Ningún evento agendado en producción quedó sin dueño identificado**». `verificacion.json`
declara `cobertura_estimada: 88` y sus `notas` dicen qué no se pudo verificar desde el snapshot: «la
lista real de acciones de Action Scheduler en producción (pendientes, fallidas y su antigüedad)».
**Debería** acotarse: «completo respecto de los hooks medidos en producción el 2026-09-11», y decir
qué quedó sin medir.

**4.5 · El dato de que no hay intervalos propios no se aprovechó.**
`verificacion.json` → `notas`: «no hay ningún filtro `cron_schedules` propio — `'weekly'` es el del
núcleo desde WP 5.4». Eso responde la pregunta del punto 2.13 y además cierra la tabla «Lo
programado» (nadie puede inventar un intervalo raro). No aparece en el capítulo.

---

## 5 · Diagramas

Los seis validan con `mmdc` sin un solo error (probado uno a uno). Como material de lectura, tres se
entienden solos —`estados-rebuild`, `caso-buscador-caido`, `caso-lista-de-espera`— y los otros tres
tienen problemas concretos.

**5.1 · `piezas.mmd` dibuja el mu-plugin de retención como un trabajo de la cola, y el capítulo dice
que no lo es.** Hay `ASQUEUE --> ASRET` (o sea: la cola lo ejecuta) y
`ASRET -. "poda también las filas de" .-> AJENOS` (o sea: él borra). §26 dice literalmente: «filtro
`action_scheduler_retention_period` … **No hay ningún borrado propio**: la purga la hace Action
Scheduler en su propia acción programada», y el fichero entero es un `add_filter`
(`akibara-as-retention.php:14-19`). **Debería ser** una flecha punteada `ASRET -. "acorta la
retención a 14 días" .-> ASQUEUE`, sin arista de entrada desde la cola.

**5.2 · `piezas.mmd` mete un fichero propio dentro de la caja «Terceros».**
`SMTP["akibara-correo-smtp.php…"]:::propio` vive en `subgraph TERCEROS["Terceros que reciben o
conviven"]`. Está pintado como propio (naranja) y colocado entre terceros: para un novato es una
contradicción visual. Moverlo al subgrafo `MU`, junto a `MAILW`, que es quien lo vigila.

**5.3 · `piezas.mmd` deja el carril de cumpleaños sin correo.**
`BIRTH --> METAS` es su única salida, mientras `EXPIRY`, `INVIT`, `BIS` y `SF` van a `WOO → SMTP →
CLIENTE`. El cumpleaños manda correo por el mismo camino
(`akibara-rewards/src/Points/Emails.php:325-340`, vía `send_branded()` en `:142-170`), y el caso 5 del
capítulo trata exactamente de ese correo. Falta `BIRTH --> WOO`.

**5.4 · `piezas.mmd` no dibuja la dependencia que hace peligroso al adaptador de Blue Express.**
§9 se sostiene entera sobre «si el plugin de envíos se desactiva, las acciones antiguas corren sin
manejador», y en el diagrama `BLUEXAD` no tiene ninguna arista con `BLUEX`. Añadir
`BLUEX -. "si no está, BLUEXAD no se registra y las acciones legadas corren sin manejador" .-> BLUEXAD`.

**5.5 · `piezas.mmd` no tiene nodo para `action_scheduler_ensure_recurring_actions`,** que el texto
nombra como el segundo camino de reparación del vigilante (§18) y que es la «red» que, según
`verificacion.json`, los carriles de puntos no tienen. Es un nodo, y explica una asimetría central.

**5.6 · `piezas.mmd`: `DASH` no está conectado a nada de lo que vigila.** Solo `DASH --> DUENO`. La
información («vigila 3 de los 8 eventos propios de WP-Cron y nada de Action Scheduler») va dentro de
la etiqueta, donde el ojo no la compara con nada. Una punteada `WPCRON -. "3 de 8" .-> DASH` y la
ausencia deliberada de punteada desde `ASQUEUE` contarían el hueco de un vistazo.

**5.7 · `cron.mmd` afirma que hay una sola hora escrita en el código, y el propio diagrama muestra la
otra dos líneas más abajo.** «Domingo 03:00 : … **única hora fija escrita en el código**», y en la
sección siguiente «05:00 : akibara_catalog_order_daily … re-anclada si deriva más de 15 min» — que
está fijada en `CatalogOrder.php:44-45`, como dice §12 («Es el único que ancla bien su hora. Fija las
05:00 de Chile»). **Debería decir**: «una de las dos horas escritas en el código».

**5.8 · `cron.mmd` marca «hora heredada» solo en la mitad de las que lo son.** Lleva la marca en
02:37, 19:05, 19:30, 20:30 y 21:45, y no en 12:50, 16:10, 17:15, 21:55 ni lunes 19:23, que se agendan
igual (ver 3.8). Como el encabezado del diagrama explica que las heredadas «tras una reinstalación
cambian», la marca parcial hace daño: promete estabilidad donde no la hay.

**5.9 · `cron.mmd`: la sección «Noche» está desordenada.** 19:05 → 19:30 → 19:30 → **lunes 19:23** →
20:30 → 21:45 → 21:55. En una línea de tiempo el lector lee de arriba abajo como un reloj. Poner el
semanal en su hora, o sacarlo a una sección «semanales» junto al domingo 03:00.

**5.10 · `estados-rebuild.mmd`: `ABORTED` aparece sin ninguna flecha de entrada.** Es coherente con el
hallazgo (ningún fichero propio la escribe), pero visualmente parece un nodo olvidado. Una arista
punteada desde fuera —`[*] -. rescate manual o CLI .-> ABORTED`— convierte la ausencia en una
afirmación. Y falta resaltar en rojo, como hace el diagrama del caso 4, que `BUILDING` y
`CATCHING_UP` son las fases en las que la alarma no se evalúa: hoy eso solo vive en una nota de
texto al lado de `DONE`.

**5.11 · `caso-lista-de-espera.mmd`: la tabla manda el correo.** El intercambio es
`Tabla->>Correo: SubscriptionNotice manda «Te anotamos…»`. El emisor real es `SubscriptionNotice`
colgado del aviso `akb_bis_subscribed` (`BackInStock/SubscriptionNotice.php:42`), no la tabla de
inscripciones. Con un participante propio para el alta, el diagrama diría la verdad y de paso
mostraría el hook, que el texto sí nombra.

**5.12 · `caso-preventa-lista.mmd`: mezcla dos piezas en un participante y esconde el camino
alternativo.** `Ready as ReadyToShipCron<br/>+ OrderQueryPaginator` junta el cron y su motor de
paginación, que el capítulo se molestó en separar en §2 y §3; y el barrido diario de las ~19:30 —el
camino por el que pasa el pedido cuando **nadie** repone stock— aparece solo como nota al pie, no
como rama. Un `alt` con «el dueño repone / no repone nadie: lo hace el barrido de las 19:30» cerraría
el caso.

**5.13 · Cuatro de los siete casos no tienen diagrama, y son los más difíciles.** Tienen dibujo el 1,
el 2 y el 4. No lo tienen el 3 (la cadena de avisos de tomo nuevo, que es la única que **se congela
sin señal**), el 5, el 6 (el vencimiento en dos fases, con candado de base de datos y aviso en acción
aparte) y el 7. El criterio de por qué unos sí y otros no tampoco se explica.

---

## Recuento

- **No entendí:** 12 puntos (1.1–1.12). **Falta para operar o depurar:** 14 puntos (2.1–2.14).
- **Contradice al código:** 9 puntos (3.1–3.9), de los cuales tres cambian una instrucción operativa:
  la retención no borra las fallidas (3.1), el barrido semanal de Blue Express no tiene grupo (3.2) y
  cinco horas «fijas» son heredadas (3.8).
- **Contradice o deja fuera `verificacion.json`:** 5 puntos (4.1–4.5). **Diagramas:** 13 puntos
  (5.1–5.13); los seis `.mmd` validan con `mmdc` sin error, y los casos 3, 5, 6 y 7 no tienen diagrama.

---

## Respuesta del redactor

Aplicados **todos** los puntos salvo los cuatro matices de abajo. Lo grande que entró: una sección
nueva entera, «Cómo se mira, cómo se fuerza y cómo se cambia una hora» (comandos, tabla de pantallas
con dirección, tabla de grupos, señales de vida, los trece comandos propios, el guion de rescate por
su nombre, qué decide «producción»); una subsección «Piezas de apoyo» con las dieciséis clases que
faltaban y el recuadro de `has_outstanding_items()` vs `pending_items()`; el vocabulario del buscador
al abrir §17 y once entradas nuevas de glosario; §16 reescrita desde el hecho de negocio; la ventana
ciega de 12 h como trampa de §21 y §22; y dos diagramas nuevos (casos 3 y 6), con el criterio
explicado. Las nueve contradicciones de la sección 3 se comprobaron una a una contra el snapshot y
**las nueve son correctas**; todas corregidas.

**1. El punto 2.11, segunda viñeta, se equivoca sobre `akibara-clarity-production-only.php`.** No
explica «por qué `clarity_collect_batch_cron` corre en producción y no en el espejo». Ese fichero no
toca ninguna tarea programada: quita tres enganches de pintado
(`remove_action('wp_head', 'clarity_add_script_to_header')` y dos más, `:22-25`), o sea el código de
rastreo que se inyecta en las páginas. La tarea es del plugin del tercero y sigue agendada igual en
los dos entornos. El mu-plugin **sí** entró al capítulo, pero descrito por lo que hace. El resto del
punto 2.11 era correcto y está aplicado: `akibara-discovery-warming.php` (el fichero sigue
instalado, solo se retiró el evento), la bandeja de captura, `SearchAdminSection:272-276`,
`PreventasScreen:263/366` y los dos `Install.php`.

**2. El punto 3.2 acierta en el hallazgo y falla en la cuenta.** Es verdad que
`akibara_bluex_integrity_weekly` se agenda **sin grupo** (`akibara-bluex-integrity-watch.php:139-140`)
y que el único que usa `akibara-bluex` es la purga de registros: la tabla ya está corregida. Pero
«los trabajos sin grupo son cuatro, no tres» no corresponde a ninguna frase del capítulo — el
capítulo nunca dio un número. Y el número correcto tampoco es cuatro: contando avisos son **seis**
(los cinco de `akibara-rewards` más este). Para que no haya que contarlos nunca más, la cuenta vive
ahora en la tabla «Los grupos: cómo se cancela un carril entero», con su fila «sin grupo».

**3. El punto 1.12 plantea un dilema que no existe.** «O la regla no es una regla, o hay dos defectos
más sin inventariar»: ninguna de las dos. `as_has_scheduled_action` cuenta las pendientes **y la que
está en curso**, y eso solo es un problema cuando **quien pregunta es un manejador del mismo aviso
preguntando por sí mismo** — que es exactamente el caso de la bomba del índice y de ningún otro. En
`StockWatcher.php:105-107` la llamada ocurre dentro de la petición web que cambia el stock, nunca
dentro de `akb_bis_notify_product`. En `ReadyEmailDispatcher.php:352-354` ocurre desde los avisos de
fallo de Action Scheduler, con la acción ya marcada como fallida; ahí el peor caso es perder un
reintento, no duplicar trabajo. La regla completa, con los cuatro sitios y su veredicto, está escrita
en §17: era el punto que faltaba, y por eso el punto se da por aplicado aunque su conclusión fuera
equivocada.

**4. Detalle de línea en el punto 3.1.** El hallazgo es correcto y las dos frases falsas están
borradas. Pero la cita `akibara-as-retention.php:14-19` está desplazada una línea: el `add_filter`
empieza en la **13** y cierra en la **18**, que es justo lo que decía la tabla de reglas del capítulo
(`:13-18`). El `14` de los días está en la línea **16**, y así se escribe ahora en §26 para quien
quiera cambiarlo.

**Sobre 5.13 (los casos sin diagrama).** Entraron los dos que la crítica señala como más difíciles: el
caso 3 (`16-todo-lo-programado-caso-tomo-nuevo.mmd`, con la congelación sin señal como rama propia) y
el caso 6 (`16-todo-lo-programado-caso-puntos-por-vencer.mmd`, con las dos fases, el troceo en lotes y
el candado de base de datos). Los casos 5 y 7 se quedan sin dibujo **a propósito**, y el criterio está
ahora escrito en el capítulo: llevan diagrama los casos con tres piezas o más y bifurcaciones que
cambian el resultado; los otros dos son cadenas de un solo carril y su lista numerada ya es el
diagrama.

Los ocho `.mmd` del capítulo —los seis de antes y los dos nuevos— vuelven a validar con `mmdc` sin un
solo error.
