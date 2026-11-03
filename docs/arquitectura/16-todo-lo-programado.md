# Transversal · todo lo programado: WP-Cron, Action Scheduler y trabajos diferidos, con horas de Chile

> **Qué es este capítulo.** El inventario de todo lo que la tienda hace *sola*, sin que nadie pulse un
> botón en ese momento: correos que salen de madrugada, barridos diarios, reindexados del buscador,
> vencimientos de puntos, purgas de registros. Está escrito para alguien que no conoce WordPress, ni
> WooCommerce, ni el negocio.
>
> **Hasta dónde llega «completo».** Es completo respecto de dos cosas concretas: el **código propio
> leído entero** (los 27 ficheros que agendan trabajo, buscando `wp_schedule_*`, `as_schedule_*`,
> `as_enqueue_async_action` y `cron_schedules` sobre las cinco rutas del snapshot) y los **eventos y
> acciones capturados en producción el 2026-09-11**. Lo que **no** se pudo medir desde esa captura
> queda dicho donde toca: el detalle vivo de la cola de Action Scheduler —cuántas acciones pendientes
> y fallidas hay ahora mismo, y desde cuándo—. La sección «Cómo se mira, cómo se fuerza y cómo se
> cambia una hora» trae los comandos para medirlo en el servidor.
>
> **Sobre qué código habla.** El snapshot congelado de producción: `akibara-business` 1.116.4,
> `akibara-search` 0.1.60, `akibara-cart-checkout-blocks` 2.2.17, `akibara-rewards` 0.3.13, el tema
> hijo `akibara-gp` 2.1.118 y los 18 mu-plugins. Las horas y los conteos de ejecución vienen de la
> lista real de tareas y de la cola de trabajos capturadas en producción el 2026-09-11.

---

## Para qué existe (en dos párrafos que entienda un novato)

Una tienda en internet no solo tiene que responder cuando una persona abre una página. También tiene
que hacer cosas por su cuenta, más tarde: mandar un correo dentro de siete días, revisar cada noche
que ningún pedido se haya quedado atascado, borrar datos personales que ya cumplieron su plazo, o
reconstruir el índice del buscador cuando cambió el catálogo. A todo ese trabajo que ocurre sin que
nadie lo pida *en ese instante* se le llama **trabajo programado**. Existe por dos motivos muy
concretos. El primero es que hay cosas que, por definición, pasan en el futuro: «avísale al cliente
cuando llegue su tomo» no se puede hacer en el momento de la compra. El segundo es que hay cosas que
tardan demasiado: recalcular el orden de la vitrina lee 1.568 productos y reescribe hasta 1.122
posiciones, y si eso se intentara hacer dentro del clic del dueño, él vería una pantalla en blanco
hasta que el servidor se rindiera.

En Akibara ese trabajo se hace de **dos maneras distintas**, y la diferencia importa. La primera se
llama **WP-Cron** y viene con WordPress: es una lista de recados guardada en la propia base de datos
(«el día X a la hora Y, avisa a quien escuche el aviso `akb_bis_cleanup_terminal`»). La segunda se
llama **Action Scheduler** y la trae WooCommerce: es una cola de trabajos mucho más seria, con tablas
propias, estados (pendiente, en curso, completado, fallido), argumentos, reintentos y grupos. La regla
de la casa es simple: lo que puede perderse sin consecuencias graves va en WP-Cron (barridos, higiene,
regeneración de ficheros); lo que **no puede perderse** —un correo al cliente, un movimiento de
puntos, el índice del buscador— va en Action Scheduler. Y hay un detalle que lo condiciona todo: en
akibara.cl WordPress tiene apagado el mecanismo que aprovecha las visitas de los clientes para empujar
la lista (`DISABLE_WP_CRON` está en verdadero). Quien empuja es una tarea del panel de Hostinger que
llama a `wp-cron.php` **cada 5 minutos**. Consecuencia práctica, y hay que decirla así de literal: un
trabajo nunca sale «en la próxima visita al sitio», sale «en los próximos 5 minutos», haya o no haya
visitas.

![Piezas del área y cómo se relacionan](diagramas/16-todo-lo-programado-piezas.mmd)

---

## Cómo encaja con el resto (con quién habla; qué pasa si se apaga)

Este capítulo es **transversal**: no describe un módulo, describe un mecanismo del que cuelgan piezas
de los cuatro plugins propios y de los mu-plugins. Casi ningún capítulo del resto de la documentación
funciona sin esto.

**Con quién habla, hacia arriba (quién lo empuja):**

- **La tarea programada del panel de Hostinger** llama a `wp-cron.php` cada 5 minutos. Es el único
  latido del sistema. Si esa tarea se cae, se cae todo lo de este capítulo, sin una sola señal en la
  tienda: las páginas siguen funcionando perfectamente.
- **Action Scheduler** (dentro de WooCommerce) se drena desde un evento de WP-Cron llamado
  `action_scheduler_run_queue`, agendado **cada minuto** en producción. Es decir: la cola seria
  depende de la lista tonta. Si WP-Cron deja de correr, Action Scheduler deja de correr también.

**Con quién habla, hacia los lados (código propio):**

- `akibara-business` aporta la mayoría: preventa y pedidos, lista de espera «avísame cuando llegue»,
  avisos de tomo nuevo, orden de la vitrina, relleno de recomendaciones, manifiestos para asistentes
  de inteligencia artificial y conversión de portadas.
- `akibara-search` aporta el carril del buscador: la «bomba» que reindexa lo que cambió, el vigilante
  cada 15 minutos, la auditoría diaria y las fronteras de campaña de descuento.
- `akibara-rewards` aporta los dos carriles de puntos (cumpleaños y vencimiento) y la invitación a
  reseñar.
- `akibara-cart-checkout-blocks` **no programa nada**: es el carrito y el checkout, todo en vivo.
- El tema `akibara-gp` **no programa nada**: verificado buscando `wp_schedule_*`, `as_schedule_*` y
  `as_enqueue_async_action` sobre **todo el árbol del tema**, no solo sobre `inc/` — el tema tiene más
  de veinte ficheros PHP fuera de esa carpeta (`functions.php`, `home.php`, `single.php`,
  `archive.php`, `404.php`, `template-parts/`, `woocommerce/`). Cero resultados en todos ellos.
- Los mu-plugins aportan cuatro piezas que **programan o gobiernan** trabajo: la purga de registros de
  Blue Express, el vigía de integridad de Blue Express, la retención de la cola a 14 días y el
  centinela del transporte de correo. Y hay **dos más que no programan nada pero cambian el resultado
  fuera de producción**, y que por eso aparecen en este capítulo:
  `akibara-correo-no-sale-a-clientes.php` (la «bandeja de captura»: en el espejo redirige **todo** el
  correo a una sola dirección, con el destinatario real a la vista en el asunto) y
  `akibara-clarity-production-only.php` (apaga el rastreador de Microsoft Clarity fuera de producción;
  no toca su tarea programada, solo el código que se inyecta en las páginas).

**Con quién habla, hacia terceros:**

WooCommerce (dueño de Action Scheduler y de las clases de correo), LiteSpeed Cache (recibe las órdenes
de purga), Cloudflare (por detrás de LiteSpeed), Blue Express (envíos: le purgamos sus registros y le
vigilamos los ficheros), Rank Math (SEO; trae una segunda copia de Action Scheduler en su árbol),
Microsoft Clarity, Google Listings & Ads y Jetpack, que tienen sus propias tareas y **no se tocan**.

**Qué pasa si se apaga.** Conviene separarlo por nivel, porque el daño no es el mismo:

| Si se apaga… | Qué deja de pasar | Se nota |
| --- | --- | --- |
| La tarea de Hostinger (todo el latido) | Absolutamente todo lo de este capítulo | No: la tienda vende igual; los correos simplemente no salen |
| `action_scheduler_run_queue` | Correos de preventa, avisos de stock, reindexado, puntos | No, salvo por la cola creciendo |
| Un plugin propio (desactivado) | Su parte; y **quedan trabajos huérfanos**, ver la advertencia de más abajo | No |
| El transporte de correo (`akibara-correo-smtp.php`) | **Ningún correo de la tienda**, ni siquiera los de WooCommerce | No: pasó en agosto de 2026 y duró tres días |

> **Advertencia importante sobre las bajas.** Desactivar o desinstalar un plugin propio **no retira sus
> trabajos de Action Scheduler**. `akibara-business` solo llama `wp_clear_scheduled_hook()` sobre seis
> eventos de WP-Cron al desactivar (`akibara-business.php:248-253`) y ocho al desinstalar
> (`uninstall.php:40-47`); en todo el plugin no hay ni una llamada a `as_unschedule_all_actions()`, así
> que sobreviven trabajos de cinco grupos: `akibara-order-preventa`, `akibara-bis`,
> `akibara-series-follow`, `akb-catalog-order` y `akibara-business`. `akibara-rewards` tampoco desagenda
> nada, ni siquiera en la purga consentida con `AKIBARA_REWARDS_ALLOW_WIPE` (`uninstall.php:18-28`):
> borra las opciones `akb_rewards_%` y deja vivas las recurrentes diarias de puntos y de cumpleaños —
> que además se agendan **sin grupo** (`Expiry.php:93`, `Birthday.php:72`), así que ni siquiera una
> limpieza por grupo las alcanzaría. **El único plugin que hace la baja bien es `akibara-search`**, que
> cancela su grupo entero (`uninstall.php:61-68`).
>
> **Y qué significa eso el día que se desactive `akibara-business`**, dicho con nombres: quedan vivos
> en la lista de WordPress los **dos** eventos de portadas sociales —`akibara_business_social_image_derivatives`
> y `akibara_business_social_image_for_slug`— apuntando a manejadores que ya no existen. Lo advierte
> el comentario del propio código (`uninstall.php:36-38`). Los otros seis sí se retiran. Del lado de
> Action Scheduler no se retira **nada**, y ahí la limpieza hay que hacerla a mano: la maniobra está
> en «Cómo se mira, cómo se fuerza y cómo se cambia una hora», con la tabla de grupos.

---

## Piezas

Una subsección por componente. Para cada uno: qué hace en una frase, en qué ficheros vive, en qué
momento actúa (hook y prioridad), qué datos lee y escribe, dónde lo opera el dueño y qué ve el cliente.

Todas las rutas son relativas a `public_html/wp-content/`.

### 1. `Stock\PreventaVencidaCron` — el barrido de preventas vencidas

**Qué hace.** Cada día «toca» hasta 50 fichas cuya fecha de preventa ya pasó, para que el buscador y
la copia en caché dejen de mostrarlas como preventa.

- **Ficheros:** `plugins/akibara-business/src/Modules/Stock/PreventaVencidaCron.php`
- **Cuándo actúa:** `init` prioridad 10 → `maybe_schedule()` (se auto-repara en cualquier petición);
  `akb_preventa_vencida_sweep` prioridad 10 → `handle()`; `akb_eta_release_date_changed` prioridad 10
  → `forget_sweep()`.
- **Lee y escribe:** la meta de producto `_akb_preventa_vencida_swept` (el testigo que hace avanzar el
  cursor, `PreventaVencidaCron.php:61`) y la opción `cron`. Hace un `wp_update_post` vacío que dispara
  `save_post_product` **sin mover `post_modified`**, para no mentirle a Google en el mapa del sitio.
- **Dónde lo opera el dueño:** en ninguna parte. No hay pantalla. Solo se ve por línea de comandos.
- **Qué ve el cliente:** de forma indirecta, que la ficha deje de decir «Preventa».

### 2. `OrderPreventa\ReadyToShipCron` — el flip de «pedido listo»

**Qué hace.** El barrido diario que decide si un pedido con preventa ya se puede despachar, y que de
paso avisa por nota interna de los pedidos que llevan demasiado esperando.

- **Ficheros:** `plugins/akibara-business/src/Modules/OrderPreventa/ReadyToShipCron.php`
- **Cuándo actúa:** `init` prioridad 10 → `maybe_schedule()`; `akb_order_preventa_recompute_ready`
  prioridad 10 → `run()`, que hace **dos** pasadas completas sobre los pedidos (`:130-141`).
- **Lee y escribe (metas de pedido):** `_akb_eta_ready_to_ship` (`yes`/`no`, **inmutable hacia
  atrás** — quiere decir que, una vez que vale `yes`, ningún proceso lo vuelve a poner en `no`, ni
  siquiera si el tomo se agota otra vez),
  `_akb_eta_ready_date`, `_akb_eta_pending_count`, `_akb_eta_stale_noted`,
  `_akb_eta_shipped_stale_noted`.
- **Por qué es el trabajo más caro del día: son dos pasadas, no una** (`:130-141`). La primera
  recorre los pedidos candidatos y marca listos los que ya tienen todos sus tomos. La segunda recorre
  los pedidos **que ya están marcados listos** y llevan demasiados días hábiles sin despacharse, para
  dejar la nota interna de atraso. Cada pasada es un `OrderQueryPaginator::process_all()` sin tope, o
  sea: el conjunto entero de pedidos, dos veces seguidas.
- **Avisa hacia fuera:** dispara `akb_order_preventa_marked_ready` (lo escucha el despachador del
  correo) y ofrece el filtro `akb_order_preventa_holidays_mmdd` para inyectar feriados chilenos.
- **Dónde lo opera el dueño:** las tres cifras de gracia están en *Akibara → Pedidos y preventa ·
  Ajustes* (`/wp-admin/admin.php?page=akibara-panel&tab=preventa`): `akb_preventa_stale_pending_days`
  30, `akb_preventa_stale_max_age_days` 120 y `akb_preventa_stale_post_ready_days` 4 días hábiles. Las
  notas internas se leen en cada pedido.
- **Qué ve el cliente:** el correo «Tu pedido {número} ya llegó a Akibara» nace aquí.
- **Trampa conocida:** la consulta de candidatos pide literalmente `meta_value => 'no'`
  (`:437-447`), mientras su propio guard solo rechaza `'yes'` (`:203-207`). Un pedido **sin la meta**
  (importado, creado por interfaz de programación, hecho a mano en el escritorio) es **invisible**: no
  se marca listo nunca y su cliente no recibe el aviso. `RemindersCron` ya corrigió esto con una
  consulta que acepta también «la meta no existe» (`:230-241`); este consumidor no.

### 3. `OrderPreventa\OrderQueryPaginator` — el motor de las pasadas sobre pedidos

**Qué hace.** Recorre en páginas todos los pedidos que cumplen un filtro, aislando cada pedido para
que uno envenenado no mate la pasada entera.

- **Ficheros:** `plugins/akibara-business/src/Modules/OrderPreventa/OrderQueryPaginator.php`
- **Cuándo actúa:** no se engancha a nada; lo llaman ocho sitios, entre ellos `ReadyToShipCron.php:131`
  y `:136`, `RemindersCron.php:134`, `ReleaseDateChangeListener.php:238` y
  `StockChangeListener.php:150`.
- **Detalle que decide el coste de un tick:** su parámetro `$max_total` existe para acotar una pasada
  y **ningún llamador lo usa**, así que una corrida recorre el conjunto entero de pedidos de una sola
  vez. Además el recorrido va de la **última** página a la primera (`:54-99`), a propósito: si un
  pedido deja de cumplir el filtro mientras se procesa, las páginas de abajo no se desplazan. El propio
  código advierte que por eso un tope bajo dejaría **permanentemente fuera** a los pedidos más
  antiguos — justo los que la nota de «lleva demasiado esperando» debe cazar (`:38-51`).
- **Dónde lo opera el dueño:** no tiene pantalla propia, pero **sí se dispara desde una**, y conviene
  saberlo antes de preguntarse por qué el escritorio va lento: la pantalla *Akibara → Por llegar*
  (`PreventasScreen.php:263` y `:366`) usa este mismo motor para recorrer **todos** los pedidos con
  preventa cada vez que se abre. Abrir esa pantalla lanza pasadas completas sobre pedidos, a demanda.

### 4. `OrderPreventa\RemindersCron` — el recordatorio semanal

**Qué hace.** El único recordatorio intermedio de una preventa: «tu pedido se acerca», una sola vez por
pedido en toda su vida, el domingo de madrugada.

- **Ficheros:** `plugins/akibara-business/src/Modules/OrderPreventa/RemindersCron.php`,
  `Settings.php`
- **Cuándo actúa:** `init` prioridad 10 → `maybe_schedule()` (agenda **o desagenda** según el
  interruptor); `akb_order_preventa_t7_reminder_scan` prioridad 10 → `run()`;
  `update_option_akb_preventa_t7_enabled` prioridad 10 → `Settings::on_t7_enabled_change`.
- **Lee y escribe:** `_akb_eta_t7_reminder_sent` (sello único por pedido) y
  `_akb_eta_t7_reminder_attempts`.
- **Dónde lo opera el dueño:** *Akibara → Pedidos y preventa*: interruptor `akb_preventa_t7_enabled`,
  ventana máxima (10 días), ventana mínima (1 día) y antelación mínima (14 días).
- **Qué ve el cliente:** correo «Tu pedido {número} se acerca».
- **Trampa conocida:** el ancla inicial se calcula bien con el calendario de `America/Santiago`
  (`:114-120`), pero el intervalo `'weekly'` es fijo y `maybe_schedule()` sale en cuanto encuentra el
  evento (`:89-91`): **nadie lo re-ancla**, así que el cambio de horario de Chile lo corre una hora
  entera. Y `modify('next sunday')` ejecutado un domingo devuelve el domingo **siguiente**.
- **De dónde sale `'weekly'`:** es un intervalo del **núcleo de WordPress** (existe desde la versión
  5.4). Akibara **no define ni un solo intervalo propio**: no hay un filtro `cron_schedules` en los
  cuatro plugins, ni en el tema, ni en los mu-plugins. Nadie puede inventarse aquí una cadencia rara;
  quien quiera otra tiene que dar de baja el evento y dejar que se recree, y eso está explicado en
  «Cómo se mira, cómo se fuerza y cómo se cambia una hora».

### 5. `OrderPreventa\ReadyEmailDispatcher` — la máquina del correo «ya llegó»

**Qué hace.** Hace salir una sola vez el correo «tu pedido ya llegó», con reintentos, y apaga el correo
genérico de WooCommerce en los pedidos de preventa.

- **Ficheros:** `plugins/akibara-business/src/Modules/OrderPreventa/ReadyEmailDispatcher.php`
- **Cuándo actúa:** `akb_order_preventa_marked_ready` prioridad 10 → agenda;
  `akb_order_preventa_dispatch_ready_email` prioridad 10 → el trabajador que manda;
  `action_scheduler_failed_execution` y `action_scheduler_failed_action` prioridad 10 → reintento
  (`:73-74`); `woocommerce_order_status_changed` prioridad 20 → reenganche;
  `woocommerce_email_enabled_customer_completed_order` prioridad 10 → supresión del correo nativo.
- **Lee y escribe:** `_akb_eta_ready_email_sent`, `_akb_eta_ready_email_attempts`,
  `_akb_eta_ready_email_too_old_noted`.
- **Dónde lo opera el dueño:** *Akibara → Pedidos y preventa* (`akb_preventa_ready_email_max_age_days`,
  7 días por defecto, 0 lo desactiva) y el selector «Acciones del pedido» con los dos reenvíos manuales.
- **Trampa conocida (alta):** hay **dos** modos de fallo y solo uno tiene techo. Cuando `wp_mail()`
  devuelve falso, `SendFailureRearm` cuenta hasta 3 intentos. Pero cuando la acción de Action Scheduler
  **falla de verdad** (un error fatal de PHP dentro del trabajador, un tiempo agotado, un pedido
  envenenado), `schedule_ready_email_dispatch_retry()` re-agenda a +305 segundos **sin contador
  ninguno** (`:345-362`). Un fallo permanente produce una acción fallida cada cinco minutos por
  pedido, para siempre.
- **Y esto se comprueba en una línea, antes de creérselo.** El rastro no se pierde: la retención
  propia de 14 días solo toca las acciones **completadas y canceladas**, nunca las fallidas (§26). Así
  que la cuenta está entera en el servidor:
  `wp action-scheduler list --hook=akb_order_preventa_dispatch_ready_email --status=failed --format=count`.
  Cero quiere decir que la trampa nunca se disparó en producción; un número que sube día a día quiere
  decir que hay un pedido ciclando **ahora mismo**, y entonces toca mirar cuál:
  `wp action-scheduler list --hook=akb_order_preventa_dispatch_ready_email --status=failed --fields=ID,args,scheduled_date`.

### 6. `OrderPreventa\StockChangeListener` — el atajo cuando llega el stock

**Qué hace.** Cierra la espera de hasta 24 horas entre que el dueño pone stock a un tomo y que el
barrido diario se entere.

- **Ficheros:** `plugins/akibara-business/src/Modules/OrderPreventa/StockChangeListener.php`
- **Cuándo actúa:** `woocommerce_product_set_stock_status` prioridad 10 → encola a +5 segundos;
  `akb_order_preventa_recompute_for_product` prioridad 10 → el recálculo coalescido.
- **Lee y escribe:** consulta y **reclama** directamente la tabla `wp_actionscheduler_actions` para
  juntar en una sola pasada las acciones hermanas de una reposición masiva; siempre libera el reclamo
  en un bloque `finally`.
- **Qué ve el cliente:** adelanta el correo «ya llegó» de horas a minutos.

### 7. `OrderPreventa\ReleaseDateChangeListener` — el cambio de fecha de salida

**Qué hace.** Cuando el dueño mueve la fecha de salida de un tomo, recalcula el arribo prometido de
cada pedido afectado y decide si hay que avisar al cliente.

- **Ficheros:** `plugins/akibara-business/src/Modules/OrderPreventa/ReleaseDateChangeListener.php`
- **Cuándo actúa:** `akb_eta_release_date_changed` prioridad 10 → **solo agenda**, no hace la pasada;
  `akb_order_preventa_release_date_changed_batch` prioridad 10 → la pasada real, bajo candado.
- **Lee y escribe:** la meta `_akb_eta_date_change_sigs` (firmas `vieja__nueva` ya procesadas) y la
  opción `_akb_preventa_datechg_lock`, un candado **global** implementado con `INSERT IGNORE` sobre
  `wp_options` y no con `get_option` (la caché de objetos respondía «no existe» sobre una fila que sí
  estaba, y el correo salía dos veces). Caduca a los 15 minutos.
- **Dónde lo opera el dueño:** *Akibara → Pedidos y preventa*
  (`akb_preventa_date_change_threshold_days` 7, `akb_preventa_date_change_notify_advance` no).
- **Trampa conocida (baja):** el reintento por candado ocupado se re-agenda **cada minuto sin contador**
  (`:344-363`). El techo real hoy lo pone la caducidad del candado: unas 15 acciones por cambio de
  fecha. Si alguien subiera esa caducidad, serían 1.440 al día.

### 8. `OrderPreventa\CarrierHandoffMonitor` — el vigía del transportista

**Qué hace.** Comprueba, dos horas después de entregar el pedido al transportista, que exista número de
seguimiento; si no, deja una nota de alerta.

- **Ficheros:** `plugins/akibara-business/src/Modules/OrderPreventa/CarrierHandoffMonitor.php`
- **Cuándo actúa:** `woocommerce_order_status_changed` prioridad 20 → agenda a +2 h;
  `akb_order_preventa_verify_carrier_handoff` prioridad 10 → la verificación.
- **Lee y escribe:** `_akb_eta_carrier_handoff_alerted`.
- **No conoce a Blue Express por su nombre:** todo pasa por `FulfillmentCapabilities`.

### 9. `Integrations\Bluex\OrderPreventaAdapter` — el adaptador de acciones antiguas

**Qué hace.** Traduce la configuración de Blue Express al contrato neutral de preventa y, sobre todo,
**atiende las acciones de Action Scheduler encoladas antes de esa migración**.

- **Ficheros:** `plugins/akibara-business/src/Integrations/Bluex/OrderPreventaAdapter.php`
- **Cuándo actúa:** `akb_order_preventa_verify_bluex_handoff` → `verify_legacy_handoff()` (`:52, :61-63`),
  que simplemente delega en `CarrierHandoffMonitor::verify_handoff()`.
- **La condición frágil:** el manejador **solo se registra si `is_available()` devuelve verdadero**
  (`:41-53`), y esa comprobación mira `WC_CORREIOS_PLUGIN_FILE` o `class_exists('WC_Correios')`
  (`:183-185`). Si el plugin de envíos se desactiva o cambia el nombre de su clase, las acciones
  antiguas que sigan en cola **corren sin manejador**, y Action Scheduler las marca completadas sin
  haber verificado ningún traspaso. Es el tercer sitio del árbol con este patrón «adaptador previo a la
  migración», y ninguno de los tres tiene escrita una fecha de retirada.

### 10. `BackInStock` — la lista «avísame cuando llegue»

**Cuatro** piezas que van juntas: el vigía de stock (`StockWatcher`), el despachador por tandas
(`NotificationDispatcher`, que manda con la plantilla `InstockEmail`), el correo de alta
(`SubscriptionNotice`) y la retención de inscripciones viejas (`Retention`).

- **Ficheros:** `plugins/akibara-business/src/Modules/BackInStock/StockWatcher.php`,
  `NotificationDispatcher.php`, `InstockEmail.php`, `Retention.php`, `SubscriptionNotice.php`
- **Cuándo actúa:** `woocommerce_product_set_stock_status` y
  `woocommerce_variation_set_stock_status` prioridad **11** (justo después del listener de preventa,
  que va en 10) → `StockWatcher::on_stock_status_change`; `akb_bis_notify_product` prioridad 10 →
  `NotificationDispatcher::dispatch`; `update_option_akb_bis_dispatch_enabled` prioridad 10 → retoma
  las colas; `init` prioridad 10 → `Retention::maybe_schedule`; `akb_bis_cleanup_terminal`
  prioridad 10 → `Retention::run`; `before_delete_post` prioridad 10 → borrado inmediato;
  `akb_bis_subscribed` prioridad 10 → `SubscriptionNotice::send` (`SubscriptionNotice.php:43`).
- **Lee y escribe:** la tabla `{prefijo}akb_bis_subscribers`, con estado y contador de intentos. El
  claim atómico de `mark_notified()` es la garantía real de que nadie recibe dos correos.
- **Dónde lo opera el dueño:** *Akibara → Lista de espera*
  (`/wp-admin/admin.php?page=akibara-panel&tab=back_in_stock`): interruptor `akb_bis_dispatch_enabled`
  y tabla de demanda. La retención (180 días) **no tiene pantalla**: solo el filtro
  `akb_bis_retention_days`.
- **Para qué existe el interruptor de despacho, y qué se ve mientras está apagado.** Es el freno de
  mano de un envío masivo. Se apaga cuando el stock que entró no es el definitivo —una reposición
  parcial, una devolución que volvió a bodega, un error de inventario que hay que corregir antes de
  escribirle a doscientas personas— o cuando hay dudas sobre el transporte de correo. Mientras está
  apagado **no se pierde ningún suscriptor**: el trabajo se re-agenda hasta 4 veces cada 15 minutos
  (aproximadamente una hora de gracia) y, agotada esa gracia, la gente sigue apuntada en la tabla
  hasta la próxima reposición del tomo. El cliente no ve nada distinto: ni correo, ni cambio en la
  ficha. El dueño tampoco recibe alarma; lo único que lo delata es la tabla de demanda de esa misma
  pantalla, donde los apuntados siguen ahí sin notificar.
- **Qué ve el cliente:** dos correos. El de alta, «Te anotamos en la lista de espera de {producto}»,
  sale con `wp_mail()` plano (`SubscriptionNotice.php:80`) — a propósito, para que **no** se pueda
  apagar desde el panel de correos de WooCommerce ni dependa del interruptor de despacho
  (`:110-112`). Y el de reposición, «{producto} está de vuelta en Akibara», que sale con la cola: 2 o 3
  minutos después de reponer, **no** en el instante de guardar.
- **Trampa conocida (baja):** el guard de alta consulta los argumentos `{product_id: N}`
  (`StockWatcher.php:105-107`), pero los trabajos que quedan en pausa llevan
  `{product_id: N, pause_try: K}` (`NotificationDispatcher.php:303-311`). Action Scheduler no los
  empareja, así que apagar y volver a encender el despacho mientras hay trabajos en pausa encola un
  segundo trabajo por producto. *(Dos notas: guardar dos veces el mismo valor **no** duplica nada
  —WordPress no emite el aviso de cambio cuando el valor no cambió—; y sí, este guard usa
  `as_has_scheduled_action`, la función que §17 señala como trampa, pero **aquí no lo es**: el porqué
  está explicado en §17, y el defecto de este sitio es otro, el de los argumentos.)*

### 11. `SeriesFollow` — los avisos de tomo nuevo

**Qué hace.** Detecta la publicación de un tomo, busca quién compró tomos anteriores de esa serie y le
manda **un** correo agrupado, con tope de frecuencia.

- **Ficheros:** `plugins/akibara-business/src/Modules/SeriesFollow/PublishListener.php`,
  `DigestRunner.php`, `SeriesFollow.php`
- **Cuándo actúa:** `transition_post_status` prioridad 10 y `set_object_terms` prioridad 10 (red de
  seguridad para el importador, que asigna la serie *después* de crear el producto) → encolan el
  escaneo; `akb_sf_scan_product` prioridad 10 → `DigestRunner::scan`; `akb_sf_digest_run` prioridad 10
  → `DigestRunner::run`; `update_option_akb_sf_enabled` prioridad 10 → retoma la cola.
- **Lee y escribe:** la tabla `{prefijo}akb_sf_queue` con clave única por evento (la garantía real de
  no avisar dos veces) y metas de usuario con el último envío y las bajas por serie.
- **Dónde lo opera el dueño:** *Akibara → Sigue esta serie* —así se llama la pestaña en el menú—
  (`/wp-admin/admin.php?page=akibara-panel&tab=series_follow`): `akb_sf_enabled` (por defecto **no**),
  `akb_sf_weekly_cap_days` 7, `akb_sf_digest_delay_hours` 6, `akb_sf_max_series_per_email` 5.
- **Cómo se encadena, y por qué importa:** la corrida se pide con `schedule_digest()`, que pregunta
  **solo por lo pendiente** con `as_get_scheduled_actions(status: 'pending')` (`:764-806`). Antes
  preguntaba con `as_next_scheduled_action()`, y esa función devuelve verdadero en cuanto hay una
  acción del mismo aviso **en curso** — que era la propia corrida. Resultado medido en producción el
  2026-09-06: **tres corridas en toda la vida del módulo**, ninguna encadenada.
- **Trampa conocida (media):** si el correo está deshabilitado en WooCommerce, `run()` hace
  `return` sin registrar nada y **sin re-agendar** (`:248-251`). La cadena se congela hasta que alguien
  publique otro tomo. Dos líneas más arriba, la rama del mailer no cargable sí registra y re-agenda.
- **Trampa conocida (baja):** el panel usa `as_next_scheduled_action()` y comprueba `is_int()`
  (`SeriesFollow.php:261-267`): mientras la corrida está **ejecutándose** la función devuelve
  verdadero, que no es entero, y la pantalla dice «sin programar» justo cuando el carril está
  trabajando.

### 12. `CatalogOrder` — el orden de la vitrina

**Qué hace.** Reordena el catálogo intercalando series y empujando lo agotado hacia abajo, una vez al
día de madrugada.

- **Ficheros:** `plugins/akibara-business/src/Modules/CatalogOrder/CatalogOrder.php`
- **Cuándo actúa:** `init` prioridad **20** → `maybe_schedule_daily()`, solo en escritorio, cron o
  línea de comandos; `transition_post_status` prioridad 10 → recálculo con espera de 120 segundos;
  `akibara_catalog_order_daily` y `akibara_catalog_order_recompute` prioridad 10 → `recompute()`;
  `admin_post_akibara_catalog_order_recompute` prioridad 10 → el botón del panel.
- **Lee y escribe:** el campo `menu_order` de la tabla `wp_posts` (**gestionado por la máquina**: el
  arrastre manual del escritorio queda pisado) y la opción `akb_catalog_order_last_run`.
- **Es el único que ancla bien su hora.** Fija las 00:05 de Chile (hasta 1.116.13, las 05:00: su purga
  total borraba cada madrugada lo que el recalentado de fichas acababa de calentar) calculadas con el
  calendario de la zona; el día del cambio de horario de septiembre las 00:05 no existen y cae a la 01:05 y **re-ancla** cuando la deriva pasa de 15 minutos (`:137-163`): Action
  Scheduler cuenta el intervalo desde que la acción *termina*, así que se corría ~131 segundos al día,
  y el cambio de horario la movía una hora entera.
- **Dónde lo opera el dueño:** *Akibara → Vitrina*
  (`/wp-admin/admin.php?page=akibara-panel&tab=catalog_order`): última corrida, cuántos se
  reordenaron y botón de recálculo.
- **Trampa conocida (media):** el guard del recálculo puntual usa `as_next_scheduled_action()`
  (`:252-253`) — la función que el resto de la casa ya abandonó. Si un producto pasa a publicado
  **mientras** el recálculo está corriendo, el guard ve la acción en curso, devuelve `'ya-encolada'` y
  no agenda nada; pero la corrida en curso ya había leído el catálogo sin ese producto. El tomo nuevo
  se queda con el orden viejo hasta las 00:05 del día siguiente. El docblock afirma usar «el mismo
  patrón coalescente que `SeriesFollow\DigestRunner`» (`:230-232`): **ya no es el mismo**.

### 13. `UpsellsAutofill` — el relleno de recomendaciones

**Qué hace.** Rellena solo los «también te puede interesar» de los tomos que no los tienen,
proponiendo los tomos siguientes de la misma serie.

- **Ficheros:** `plugins/akibara-business/src/Modules/UpsellsAutofill/UpsellsAutofill.php`
- **Cuándo actúa:** `init` prioridad 10 → `maybe_schedule()`; `akb_upsells_autofill_cron` prioridad 10
  → `cron_fill()`.
- **Lee y escribe:** la meta nativa `_upsell_ids`, la firma propia `_akb_upsells_autofill_sig` (que
  distingue automatización de curaduría humana y es lo que impide pisar una lista escrita a mano), las
  opciones `akb_upsells_autofill_last_run` / `_last_repair` y dos transitorios de caché de 5 minutos.
- **Dónde lo opera el dueño:** *Akibara → Recomendaciones*
  (`/wp-admin/admin.php?page=akibara-panel&tab=upsells_autofill`): estado, corrida manual, vista
  previa y reparación.

### 14. `LlmsManifest` — los manifiestos para asistentes de inteligencia artificial

**Qué hace.** Genera los ficheros `/llms.txt` y `/llms-full.txt` que leen ChatGPT, Claude y Perplexity
para saber qué es Akibara y qué series vende.

- **Ficheros:** `plugins/akibara-business/src/Modules/LlmsManifest/LlmsManifest.php`
- **Cuándo actúa:** `akibara_business_llms_manifest_refresh` prioridad 10 → `write_manifests()`;
  `add_option_akibara_llms_identity` / `update_option_akibara_llms_identity` prioridad 10 → encola una
  regeneración puntual (`:496`); `admin_post_akibara_salud_seo_regenerar` prioridad 10 →
  `handle_regenerate_request()` (`:175`, `:510`).
- **Lee y escribe:** los dos ficheros físicos en el docroot (reemplazo atómico) y **cinco opciones que
  no comparten prefijo**, así que hay que escribirlas enteras o no se encuentran:
  `akibara_llms_identity` (la descripción de la tienda, `:63`),
  `akibara_business_llms_manifest_last_success` (`:44`),
  `akibara_business_llms_manifest_last_error` (`:47`),
  `akibara_business_llms_manifest_lock` (`:50`, un permiso con token y caducidad de 30 minutos) y
  `akibara_business_llms_manifest_managed_files` (`:53`, los hashes de lo que el módulo puede retirar
  con seguridad). Ojo: `akibara_llms_last_success` **no existe**.
- **Dónde lo opera el dueño:** *Akibara → Salud SEO*
  (`/wp-admin/admin.php?page=akibara-panel&tab=salud_seo`): descripción de la tienda, última
  generación, último error y los botones de regeneración.
- **Detalle que el mapa original omitió:** el botón «Regenerar portadas» de esa misma pestaña encola el
  aviso de las **portadas sociales**, no el del manifiesto (`:521`, `$hook = 'llms' === $target ? … :
  SocialImageDerivatives::HOOK`). Es decir, hay **tres** caminos que encolan la conversión de portadas,
  no uno.
- **Trampa conocida (baja):** `wp_schedule_single_event()` descarta el evento y devuelve falso cuando
  ya hay uno del mismo aviso dentro de una ventana de 10 minutos. Como los dos avisos de este botón
  tienen **siempre** un evento diario agendado, pulsarlo en los 10 minutos previos a la corrida diaria
  no encola nada y la pantalla dice «ya-encolado»: verdad en la letra, mentira en la intención.

### 15. `SeoSocial\SocialImageDerivatives` — las portadas para compartir

**Qué hace.** Convierte cada portada AVIF en una hermana JPG, para que WhatsApp y X muestren la imagen
al compartir una ficha.

- **Ficheros:** `plugins/akibara-business/src/Modules/SeoSocial/SocialImageDerivatives.php`
- **Cuándo actúa:** `akibara_business_social_image_derivatives` prioridad 10 → `generate_missing()`;
  `akibara_business_social_image_for_slug` prioridad 10 → `generate_for_slug()`;
  `wp_generate_attachment_metadata` prioridad 10 → `generate_on_upload()` (el camino barato: convertir
  al subir).
- **Lee y escribe:** los ficheros `wp-content/uploads/portadas/{slug}-og.jpg`, las opciones
  `akb_social_derivatives_lock` (candado de 15 minutos) y `akb_social_derivatives_cursor`, y un
  transitorio de caché negativa de 6 horas por portada corrupta.
- **Trampa conocida (alta):** el docblock promete que el rellenado «se reparte en ~15 corridas
  encadenadas», y **no puede**: `register()` agenda siempre un evento diario para el mismo aviso
  (`:89-91`), así que la condición `! wp_next_scheduled( self::HOOK )` de `:239` **nunca** se cumple y
  el `wp_schedule_single_event( time()+60, … )` es inalcanzable. El rellenado avanza 100 portadas **al
  día**, no 100 por minuto. El propio código mide el daño: «1.699 hermanas escritas de unas 14.900
  portadas» (`:327-329`). A ese ritmo tarda más de cuatro meses.
- **Trampa conocida (baja):** el tope de 100 por corrida solo cuenta los **éxitos** (`:231-233`). Con el
  directorio sin permiso de escritura, el contador no avanza nunca y la corrida intenta decodificar el
  directorio entero en un solo tick.

### 16. `SeriesBundles\CustomerCollection` — la valla de propietario

**El hecho de negocio primero, que sin él no se entiende nada.** «Arma tu pack» y *Mi cuenta* le
muestran al cliente **qué tomos ya tiene comprados**, para no venderle dos veces el mismo. Esa lista
es cara de calcular, así que se guarda en caché, una por cliente. Ahora bien: mucha gente compra **sin
cuenta**, como invitado, y se registra después; cuando eso ocurre, el pedido **cambia de dueño**. En
ese instante hay dos cachés mintiendo: la del dueño anterior, que ya no tiene esos tomos, y la del
nuevo, a la que le faltan. La «valla de propietario» es la nota que se deja escrita para acordarse de
tirar esas dos cachés — y no una sola vez, sino durante unos días, porque el traspaso puede repetirse
y porque una caché puede reconstruirse con datos que todavía no terminaron de sincronizarse.

**Y por qué «valla».** Es la traducción literal de *fence*, y aquí significa la frontera temporal
durante la cual un pedido que cambió de manos sigue vigilado. Si la nota no se borrara nunca,
`wp_options` —la tabla que WordPress más consulta— acumularía una fila por cada pedido que alguna vez
cambió de dueño. Si se borrara de inmediato, una caché equivocada podría sobrevivir y el cliente
vería tomos que no compró, o dejaría de ver los suyos.

- **Ficheros:** `plugins/akibara-business/src/Modules/SeriesBundles/CustomerCollection.php`
- **Cuándo actúa:** `akibara_business_cleanup_collection_owner_fence` prioridad 10 →
  `handle_owner_fence_cleanup()`. La nota se escribe en `append_owner_fence()` cuando un pedido cambia
  de cliente (`:495-497`) o cuando se borra un pedido (`:540`).
- **Cómo se agenda:** **primero en Action Scheduler**, en el grupo `akibara-business`, y solo si eso
  devuelve 0 o lanza una excepción cae a `wp_schedule_single_event` (`:826-853`). Esto importa para las
  bajas: añadir el aviso a las listas de `wp_clear_scheduled_hook` **no retiraría el camino normal**.
- **Lee y escribe: son *options*, no filas de una tabla propia.** Viven en `wp_options`, se llaman
  `akb_byp_owner_fence_{pedido}_{hash}` y están marcadas sin autocarga (no se leen en cada visita).
  Dentro solo hay números internos: el pedido, el cliente y dos instantes.
- **Los dos plazos, en cristiano** (`:54-55`, `:781-805`). **Vencimiento blando, 2 días:** durante las
  primeras 48 horas la nota no hace nada, solo se vuelve a agendar. **Cada 12 horas** a partir de ahí:
  cada corrida invalida la caché del dueño anterior y la del nuevo. **Tope duro, 7 días:** llegado ese
  punto invalida una última vez y **borra la nota**, pase lo que pase. Si algo falla en medio, el
  reintento es a la hora, nunca más allá del tope.
- **Dónde lo opera el dueño:** en ninguna parte. No hay pantalla ni interruptor; tampoco hay nada que
  se rompa si esta cadena no corre, salvo que la tabla de opciones engorde.

### 17. `Search\Indexer` — la bomba viva del buscador

> **Siete palabras del buscador, antes de empezar.** Este carril tiene vocabulario propio y aparece en
> las dos fichas siguientes, en el caso 4 y en dos diagramas. Conviene traducirlo de una vez.
>
> **Índice.** Una copia del catálogo escrita en tablas aparte y preparada para buscar rápido. El
> autocompletado y los filtros leen de ahí, nunca de los productos.
> **Deuda.** La lista de «este producto cambió, hay que volver a copiarlo al índice», guardada en una
> tabla para que sobreviva a un reinicio del servidor.
> **Bomba.** El trabajo que **vacía** esa deuda. Es *bomba de achique*, la de un barco: **saca** agua.
> No hay nada que explote. El aviso se llama literalmente `akb_search_pump_live`, y *pump* es eso.
> **Tablas sombra (`_new`).** Cuando hay que rehacer el índice entero no se toca el que está sirviendo:
> se construye uno nuevo al lado, en tablas con el sufijo `_new`, y al final se intercambian los
> nombres de golpe. A ese intercambio el capítulo lo llama **el cambiazo** (*swap*), y al conjunto de
> tablas vivas y sombra, **la topología**.
> **Generación.** El número de versión del índice vivo. Sirve para que un trabajo encolado antes del
> cambiazo no escriba sobre el índice nuevo; una «publicación de generación» es el aviso de que ese
> número subió.
> **Foto de precios, o «el recibo».** La copia sellada de qué campaña de descuento por editorial
> estaba vigente cuando se construyó el índice, con su **secuencia** (el número de orden, para saber
> cuál es más nueva) y su **huella** (un resumen del contenido, para saber si cambió). Si el recibo no
> calza con las tablas vivas, el buscador prefiere apagarse a servir precios falsos.
> **Facetas.** Los filtros que el cliente ve al lado de los resultados —editorial, serie, precio,
> disponibilidad—, calculados del mismo índice.

**Qué hace.** Cada vez que un producto cambia, anota la deuda en una tabla propia y la bomba
asíncrona la va drenando.

- **Ficheros:** `plugins/akibara-search/src/Indexer.php`
- **Cuándo actúa:** escucha 25 avisos de WordPress y WooCommerce en prioridades 10 a 20 (guardar
  producto, cambiar stock, asignar términos, editar o borrar un término, papelera, rebajas programadas,
  cambio del descuento por editorial); `akb_search_pump_live` prioridad 10 → `handle_live_pump()`.
- **Grupo de la cola:** `akibara-search` (`Indexer.php:50`).
- **Cómo dedupea, y por qué así:** encola la bomba **solo si no hay ya una acción pendiente** con esos
  argumentos. No usa `as_has_scheduled_action`, porque esa función cuenta las pendientes **y la que
  está en curso** — y la que está en curso es la propia bomba, o sea quien pregunta. El cambio nuevo
  se descartaría por «ya hay una igual» y no se reindexaría nunca.
- **La regla completa, porque el capítulo la usa dos veces más y conviene no leerla como una
  contradicción.** `as_has_scheduled_action` y su prima `as_next_scheduled_action` solo mienten
  **cuando quien pregunta es un manejador del mismo aviso, con los mismos argumentos, preguntando por
  sí mismo**. Fuera de ese caso son correctas, y por eso el código propio las usa en otros dos sitios
  sin que sea un defecto: el guard de alta de la lista de espera
  (`BackInStock/StockWatcher.php:105-107`) corre dentro de la petición web que cambia el stock, nunca
  dentro de `akb_bis_notify_product`, así que no puede contarse a sí mismo (su defecto es otro, el de
  los argumentos que no calzan, §10); y el reintento del correo «ya llegó»
  (`ReadyEmailDispatcher.php:352-354`) corre desde los avisos de fallo de Action Scheduler, cuando la
  acción **ya quedó marcada como fallida** y por tanto ya no se cuenta. Ahí el peor caso es el
  contrario al de la bomba: perder un reintento, no duplicar trabajo. Donde sí es un defecto medido es
  en los dos sitios que este capítulo denuncia por su nombre: el panel de avisos de serie (§11) y el
  guard del recálculo de la vitrina (§12).
- **Dos manejadores que nadie agenda:** `akb_search_reindex_product` y `akb_search_reindex_term` están
  registrados y nada en todo el código propio los encola. **No son código muerto por olvido**: el
  código los declara por escrito, en los dos sitios, como adaptadores de acciones encoladas por
  versiones anteriores — «Adaptador de acciones pre-upgrade» (`:430-441`) y «Adaptador de acciones
  legacy» (`:982-995`). Borrarlos sin comprobar antes que la cola no tiene acciones antiguas es el
  cambio que rompe la migración.

### 18. `Search\RebuildJob` — el vigilante del buscador

**Qué hace.** Cada 15 minutos revisa que no haya una reconstrucción colgada, recupera lo que se quedó a
medias y, si el buscador lleva rato caído, manda un correo al dueño.

- **Ficheros:** `plugins/akibara-search/src/RebuildJob.php`
- **Cuándo actúa:** `init` prioridad 20 → `handle_recovery()`; `init` prioridad **21** →
  `register_recurring_guard()`; `action_scheduler_ensure_recurring_actions` prioridad 10 →
  `repair_watchdog()`; `akb_search_rebuild_watchdog` prioridad 10 → `handle_watchdog()`;
  `akb_search_rebuild_batch` prioridad 10 → `handle_batch()`.
- **Lee y escribe:** las opciones `akb_search_rebuild_state`, `akb_search_serving_alarm` (sin pantalla)
  y `akb_search_rebuild_history`; el transitorio `akb_search_watchdog_guard_v2` (memoria de 12 horas); y
  las tablas sombra `_new` donde se construye el índice nuevo.
- **Dónde lo opera el dueño:** *Akibara → Buscador*
  (`/wp-admin/admin.php?page=akibara-search&sub=indice`), y los dos correos de alarma al correo del
  administrador.
- **Trampa conocida (media):** `note_serving_down()` —lo único que manda el correo— solo se alcanza
  desde `maybe_recover_serving()`, y a ese método solo se llega con el estado nulo, BLOCKED o ABORTED
  (`:692-700`). Una reconstrucción parada en BUILDING o CATCHING_UP toma la otra rama (`resume_run()`,
  `:657`) y **la alarma no se evalúa nunca**. Es la misma puerta que dejó tres horas sin buscador el
  2026-09-05.
- **Trampa conocida (media):** el comando que el docblock manda correr al cambiar el intervalo lleva el
  **grupo equivocado**: dice `'akb-search'` (`:75`) cuando el grupo real es `'akibara-search'`. Con el
  grupo equivocado la llamada cancela cero acciones y no dice nada. El mismo docblock nombra además un
  método que no existe (`schedule_watchdog()`; el real es `ensure_watchdog()`, `:165`). **La orden
  correcta, la que hay que escribir en el runbook, es esta** —y después basta con esperar a que la
  siguiente petición la recree con el intervalo nuevo:
  `wp eval "as_unschedule_all_actions( 'akb_search_rebuild_watchdog', array(), 'akibara-search' );"`.
  La misma maniobra, carril por carril, está en «Cómo se mira, cómo se fuerza y cómo se cambia una
  hora».

- **Las diez fases y quién las escribe.** Son **diez** en total (`RebuildJob.php:87-96`): las **ocho
  del camino normal** —`PREPARING`, `BUILDING`, `CATCHING_UP`, `SWAP_INTENT`, `SWAPPED`, `PUBLISHING`,
  `CLEANUP` y `DONE`— más **dos salidas de emergencia**, `BLOCKED` y `ABORTED`. **Terminales** —de las
  que la reconstrucción ya no sale sola— hay tres: `DONE`, `BLOCKED` y `ABORTED` (`:2780`). Detalle
  que conviene saber antes de tocar nada: **`ABORTED` no la escribe ningún fichero del código propio**.
  Se **lee** como terminal en ocho sitios (`HealthReport.php:300`, `SearchAdminSection.php:993`,
  `EditorialDiscountPublication.php:2571` y cinco del propio `RebuildJob`: `:458`, `:699`, `:1057`,
  `:2290` y `:2780`), y además figura en el validador que comprueba que la fase guardada sea un valor
  legal (`:2769`); pero no hay **una sola asignación** en todo `akibara-search/src/`. Solo puede
  llegar desde fuera: un rescate manual o la línea de comandos.
- **El pestillo que no se deshace.** Si se borra la opción del estado dejando vivas las tablas sombra
  `_new`, el primer arranque de WordPress acuña `BLOCKED / orphan_topology_without_state`
  (`RebuildJob.php:2135-2140`), y de ahí no sale ni el panel ni la línea de comandos. Por eso el guion
  de rescate —se llama `ops/rescate-buscador.sh` y se invoca
  **`bash ops/rescate-buscador.sh produccion`**— suelta la sombra **primero** y retira la opción
  **después**. Si encuentra tablas `_old`, el guion **para y no hace nada**: ahí las `_old` son el
  camino de vuelta, no residuo.

![Estados de una reconstrucción del buscador](diagramas/16-todo-lo-programado-estados-rebuild.mmd)

### 19. `Search\Reconciler` — el auditor diario del buscador

**Qué hace.** Compara el índice con el catálogo real, arregla lo que no calza y poda el registro de
búsquedas.

- **Ficheros:** `plugins/akibara-search/src/Reconciler.php`, `Arrival.php`
- **Cuándo actúa:** `init` prioridad 20 → `ensure_scheduled()` (**solo si la indexación está
  encendida**, `Search.php:180-186`); `akb_search_reconcile` prioridad 10 → `run()`; más el botón del
  panel.
- **Lee y escribe:** opciones `akb_search_reconcile_last` y `..._last_error`, transitorios
  `akb_search_reconcile_alert` y `akb_search_reconciler_scheduled` (12 horas), y el registro de
  búsquedas (podado a 180 días y 50.000 filas).
- **Qué pinta hace `Arrival.php` en la lista de ficheros.** Guarda dos fechas por producto que, si no
  se anotan en el momento, no se pueden reconstruir después: cuándo se vio por primera vez en preventa
  (`_akb_onbackorder_at`) y cuándo llegó de verdad a bodega (`_akb_first_instock_at`). La corrida
  diaria del auditor es la que rellena las de los productos que ya estaban en preventa antes de que
  existiera el módulo. Hoy **nadie lee esas fechas**: se acumulan a propósito, para una futura faceta
  de novedades que diga la verdad.
- **Trampa conocida (baja):** `register_handlers()` se registra **siempre** (`Search.php:109`), pero
  `ensure_scheduled` solo con la indexación encendida. Apagar la indexación deja de re-agendar y **no
  desagenda nada**: la recurrente diaria ya creada sigue corriendo con manejador vivo, reparando hasta
  100 documentos al día sobre un índice que el dueño cree congelado. La política de «manejadores
  siempre registrados» es deliberada y correcta para drenar trabajos pendientes; lo que no estaba
  previsto es una **recurrente**, que nunca se drena.

### 20. `Search\EditorialDiscountPublication` — la frontera de campaña

**Qué hace.** Agenda una acción **en el instante exacto** en que una campaña de descuento por editorial
empieza o termina, para que el índice cambie de precio en ese segundo.

- **Ficheros:** `plugins/akibara-search/src/EditorialDiscountPublication.php` y, del otro lado,
  `plugins/akibara-business/src/Modules/TaxonomyDiscount/AdoptedSnapshot.php`
- **Cuándo actúa:** `akb_search_editorial_discount_boundary` prioridad 10 → `handle_boundary()`.
- **La cuenta delicada:** la frontera de fin es la medianoche del día **siguiente** al de fin,
  calculada por fecha y no sumando 24 horas a un instante — en el cambio de horario de Chile la
  medianoche no existe y PHP la resuelve como la 01:00, con lo que la promoción vivía una hora de más.
  La misma cuenta se hace en los dos plugins y **se compara literalmente**: si difieren en un segundo,
  la foto de precios se rechaza entera y **se apagan buscador y facetas**.
- **Qué ve el cliente cuando eso pasa**, dicho sin rodeos, porque es el dato que nadie encuentra
  cuando lo necesita: el autocompletado de la caja de búsqueda **deja de responder** (devuelve un
  error 503), la página de resultados no trae nada y los filtros de la izquierda desaparecen. La
  tienda sigue vendiendo: las fichas de producto, el carrito y el checkout no dependen del índice. Por
  eso una caída del buscador puede durar horas sin que nadie la note, y por eso existe la alarma por
  correo del §18.
- **Cancelación previa:** antes de agendar, cancela todo lo pendiente del mismo aviso. Está bien
  planteado: el estado publicado tiene **una sola** frontera vigente (`:2253-2279`), así que la
  limpieza global retira exactamente las versiones caducadas de esa misma frontera — 29 de 30
  pendientes muertas, medidas en producción el 2026-09-11.

### 21. `Rewards\Points\Expiry` — el vencimiento de puntos

**Qué hace.** Vence los Puntos Akibara que llevan más de N meses sin usarse, y avisa por correo antes.

- **Ficheros:** `plugins/akibara-rewards/src/Points/Expiry.php`, `Emails.php`
- **Cuándo actúa:** `init` prioridad 20 → `ensure_scheduled()` (solo escritorio, cron o línea de
  comandos); `akb_rewards_expiry_daily` prioridad 10 → `run_daily()`; `akb_rewards_expiry_batch`
  prioridad 10 → `run_batch()`; `akb_rewards_expiry_warn_async` prioridad 10 → `fire_warning()`;
  `akb_rewards_expiry_warning` prioridad **100** → `Emails::send_expiry_warning`.
- **Lee y escribe:** el libro mayor de puntos (la verdad contable: el vencimiento es un débito más, con
  clave de evento `expiry_{usuario}_{Ymd}` contra un índice único), la meta de usuario
  `_akb_rewards_expiry_warned` (**la sella el correo**, no el cron, y solo con transporte confirmado) y
  el transitorio `akb_rewards_expiry_scheduled` (12 horas).
- **Dónde lo opera el dueño:** panel de Akibara Puntos, que **no** vive en el menú Akibara sino bajo
  *WooCommerce → Puntos y Reseñas* (`/wp-admin/admin.php?page=akb-rewards-settings`):
  `akb_rewards_expiry_enabled`, `akb_rewards_expiry_months` (12), `akb_rewards_expiry_warn_days` (30).
- **Trampa conocida (media): la ventana ciega de 12 horas.** `ensure_scheduled()` sale en seco si el
  transitorio `akb_rewards_expiry_scheduled` vale `'yes'` (`:85-88`), y ese transitorio se escribe
  **por 12 horas** aunque la acción recurrente se borre cinco minutos después. Encima, el método solo
  corre en el escritorio, en cron o en línea de comandos: el tráfico de los clientes no repara nada.
  Si alguien cancela la acción —una limpieza de la cola, un rescate, una prueba—, el carril de
  vencimiento de puntos queda **muerto medio día** y ninguna pantalla lo dice. El buscador tiene la
  misma memoización, pero además un vigilante recurrente y el aviso
  `action_scheduler_ensure_recurring_actions` que lo repara; **los dos carriles de puntos no tienen
  ninguna red**. Y los puntos son, justamente, lo que no puede perderse.
- **Trampa conocida (media):** `register()` retorna **antes de registrar nada** si los meses son 0
  (`:66-75`), y los meses valen 0 en cuanto el interruptor deja de valer `'yes'` (`:45-49`). No hay
  ningún oyente de `update_option_akb_rewards_expiry_enabled` en el plugin. Apagar el vencimiento deja
  la recurrente diaria viva **sin ningún manejador**, ejecutándose a diario para siempre.

### 22. `Rewards\Points\Birthday` — el regalo de cumpleaños

**Qué hace.** Regala puntos el día del cumpleaños del cliente, del que se guardan **solo día y mes**.

- **Ficheros:** `plugins/akibara-rewards/src/Points/Birthday.php`, `Emails.php`
- **Cuándo actúa:** `init` prioridad 20 → `ensure_scheduled()`; `akb_rewards_birthday_daily`
  prioridad 10 → `run_daily()`; `akb_rewards_birthday_paid` prioridad **100** →
  `Emails::send_birthday_email`; `woocommerce_save_account_details` y `woocommerce_created_customer`
  prioridad 10 → `save_field()`.
- **Lee y escribe:** metas de usuario `_akb_rewards_birthday_md`, `_akb_rewards_birthday_paid_year`,
  `_akb_rewards_birthday_email_year`, y el transitorio `akb_rewards_birthday_scheduled` (12 horas).
- **Trampa conocida (media): la misma ventana ciega de 12 horas que el vencimiento**
  (`Birthday.php:65-77`, transitorio `akb_rewards_birthday_scheduled`). Ver §21: borrada la acción, el
  regalo no se vuelve a agendar solo hasta medio día después. A quien cumpla años ese día lo salva
  únicamente la ventana de recuperación de «hoy y ayer»; si el hueco cae en dos corridas seguidas, se
  queda sin regalo hasta el año que viene.
- **Trampa conocida (media):** `pay_batch()` sella el año pagado en cuanto el libro acredita
  (`:250-252`) y **solo entonces** dispara el aviso que manda el correo. El correo sella su propia meta
  solo si el transporte confirmó (`Emails.php:339-341`), pero **nadie vuelve a leerla**: al día
  siguiente el cron ve el año ya pagado y se salta al usuario. Si el correo falla, el cliente recibe
  los puntos y **no se entera nunca, en todo el año**. El módulo hermano, el aviso de vencimiento, hace
  justo lo contrario a propósito (`Expiry.php:363-366`).

### 23. `Rewards\Reviews\Invitations` — la invitación a reseñar

**Qué hace.** Invita por correo a reseñar, tres días después de que el pedido llegue al estado
configurado, con un enlace de un solo uso.

- **Ficheros:** `plugins/akibara-rewards/src/Reviews/Invitations.php`
- **Cuándo actúa:** `woocommerce_order_status_changed` prioridad 20 → agenda o **desagenda**;
  `akb_rewards_send_review_invite` prioridad 10 → `send_invite()`.
- **Lee y escribe:** la tabla `{prefijo}akb_rewards_review_forms` (tokens con caducidad y un solo uso)
  y las metas `_akb_rewards_invite_scheduled` / `_sent` / `_attempts`.
- **Dónde lo opera el dueño:** panel de Akibara Puntos (*WooCommerce → Puntos y Reseñas*,
  `/wp-admin/admin.php?page=akb-rewards-settings`): `akb_rewards_invite_enabled`,
  `..._invite_status` (`completed`), `..._invite_delay_days` (3), `..._invite_token_days` (30). El
  **texto del correo no es editable**: asunto y cuerpo están fijos en el código (`:245-280`), a
  diferencia del resto de correos de la casa.
- **Compuerta entre plugins:** un pedido con preventa no invita hasta su finalización válida
  (`CompletionPolicy::can_request_review`, que vive en `akibara-business`); si ese plugin no está, se
  falla **hacia invitar**.
- **Trampa conocida (baja):** el token se emite **antes** de saber si el correo sale (`:185` frente a
  `:190`). Con el transporte caído, un pedido acumula hasta 3 tokens válidos de 30 días en la tabla, y
  si el correo nunca sale quedan los tres huérfanos hasta caducar.

### 24. mu-plugin `akibara-bluex-log-retention` — la purga de registros de envíos

**Qué hace.** Borra los registros que el plugin de Blue Express deja en la base de datos y que crecen
sin parar; conserva solo 2 días.

- **Ficheros:** `mu-plugins/akibara-bluex-log-retention.php`
- **Cuándo actúa:** `action_scheduler_init` prioridad **20** → asegura la recurrente, y **solo** desde
  cron, línea de comandos o escritorio que no sea petición asíncrona (ese aviso corre en *todas* las
  peticiones, y la comprobación costaba 3 consultas en la portada, el carrito, el checkout y la interfaz
  de la tienda); `akibara_bluex_log_retention_cleanup` prioridad 10 y **`bluex_clean_logs`**
  prioridad 10 → el mismo callback (`:122-124`).
- **Lee y escribe:** la tabla `{prefijo}bluex_logs`, podada a 2 días en lotes de 1.000 filas.
- **Trampa conocida (baja):** el bucle `do…while` (`:41-51`) no tiene presupuesto de tiempo ni de
  iteraciones: solo para cuando un lote devuelve menos de 1.000 filas. Un atraso largo lo convierte en
  un borrado encadenado sin fin dentro de una sola acción. Y el mismo callback cuelga de **dos**
  disparos independientes sin candado entre ellos; el borrado es idempotente, así que el solape no
  corrompe nada, pero duplica el bloqueo de la tabla en la misma ventana.

### 25. mu-plugin `akibara-bluex-integrity-watch` — el vigía de integridad de envíos

**Qué hace.** Comprueba que el plugin de envíos no haya llegado con ficheros rotos —una actualización
así tumbó el checkout casi un día— y manda un correo al dueño en el momento.

- **Ficheros:** `mu-plugins/akibara-bluex-integrity-watch.php`
- **Cuándo actúa:** `upgrader_process_complete` prioridad 10 → chequeo tras cualquier actualización de
  plugins; `init` prioridad **30** → agenda el barrido semanal (solo escritorio o cron);
  `akibara_bluex_integrity_weekly` prioridad 10 → el barrido.
- **Lee y escribe:** las opciones `akb_bluex_watch_baseline_seen` y `akb_bluex_watch_resolved_notified`,
  las dos **sin pantalla** — y las dos deciden si sale o no un correo.
- **Qué ve el dueño:** los correos «[Akibara] ⚠ Blue Express embarcó includes rotos — checkout en
  riesgo» (se **repite** mientras dure la rotura, a propósito) y «[Akibara] ✅ Blue Express arregló su
  paquete» (una sola vez).

### 26. mu-plugin `akibara-as-retention` — la retención de la cola a 14 días

**Qué hace.** Reduce de 31 a 14 días el tiempo que Action Scheduler guarda las acciones ya completadas
o canceladas.

- **Ficheros:** `mu-plugins/akibara-as-retention.php`
- **Cuándo actúa:** filtro `action_scheduler_retention_period` prioridad 10. No hay ningún borrado
  propio: la purga la hace Action Scheduler en su propia acción programada.
- **Por qué por filtro y no editando al tercero:** hay **dos copias** de Action Scheduler en el árbol
  (dentro de WooCommerce y dentro de Rank Math).
- **Medido el 2026-08-31:** 7.721 de 15.719 acciones completadas superaban los 14 días (unos 10,7 MB
  con sus registros).
- **Lo que NO toca, y es justo lo que la gente cree que toca.** Acorta solo las acciones
  **completadas y canceladas**. Las **pendientes** y las **fallidas** no las mira: lo dice su propia
  cabecera (`akibara-as-retention.php:4`) y el fichero entero es un único `add_filter` (`:13-18`), sin
  una sola sentencia de borrado. Las fallidas se rigen por el plazo propio de Action Scheduler, que es
  de tres meses. Consecuencia práctica: el rastro de un ciclo de reintentos que está fallando **se
  conserva**, y por eso la trampa del §5 se puede contar con un comando en vez de creerla.
- **Dónde se ve la cola, y cómo se revierte esto.** La cola completa está en *WooCommerce → Estado →
  Acciones programadas* (`/wp-admin/admin.php?page=wc-status&tab=action-scheduler`), con pestañas por
  estado. Para volver a los 31 días de fábrica basta con **borrar el fichero**
  `mu-plugins/akibara-as-retention.php`: no deja nada escrito, es solo un filtro. Para un plazo
  intermedio, cambiar el `14` de la línea 16. Lo ya purgado no vuelve.

### 27. mu-plugin `akibara-mail-transport-watch` — el centinela del correo

**Qué hace.** Comprueba, aprovechando latidos que ya existen, que el fichero que hace salir **todos**
los correos de la tienda siga cargado; si desapareció lo repone y deja constancia.

- **Ficheros:** `mu-plugins/akibara-mail-transport-watch.php`
- **Cuándo actúa:** **dos** enganches, no uno: `admin_init` prioridad 1 (`:65`) y —cuando la constante
  `DOING_CRON` está definida— `init` prioridad 1 (`:73-81`). **La rama de cron es la que importa en
  este capítulo**, porque los correos de este inventario salen dentro de una corrida programada, no en
  el escritorio. Además `admin_notices` prioridad 10 pinta los avisos.
- **No crea ningún trabajo nuevo:** es un oyente colgado del latido de 5 minutos que ya existe.
- **Lee y escribe:** la opción `akibara_mail_transport_watch` (historial de reposiciones que sobrevive a
  que el problema se cure) y, si hace falta, copia el fichero desde una carpeta **fuera del docroot**.
- **Por qué existe:** el transporte desapareció y la tienda estuvo **tres días sin ningún correo**, del
  18 al 21 de agosto de 2026.

### 28. `SiteSpeed\CacheWarmer` — el recalentado nocturno de las fichas (business 1.116.14)

- **Qué:** pide cada ficha publicada, una vez (celular y computador comparten copia), directo al origen (`127.0.0.1`
  por `CURLOPT_CONNECT_TO`) para dejarla guardada en LiteSpeed antes del primer cliente del día. La
  ficha vive hasta la medianoche de la tienda desde search 0.1.65.
- **Cuándo:** una acción única de Action Scheduler `akibara_business_cache_warm` (grupo
  `akb-cache-warm`, prioridad 20) que se reprograma sola: a las 00:20 de la tienda abre la pasada
  (después de la vitrina, 00:05); mientras quedan fichas se vuelve a agendar a +60 s (en producción
  corre cada 5 min, lo que empuja el hosting); a las 06:00 corta; al terminar, agenda la noche
  siguiente, o la de esta noche si una pasada manual cerró ya dentro de la ventana. `maybe_schedule()` en `init` (sólo admin, cola o WP-CLI) la repone si falta. El
  botón «Recalentar las fichas ahora» de la pestaña Velocidad abre una pasada a cualquier hora.
- **Cuánto trabaja:** 20 s por corrida, dos fichas a la vez (~50 por corrida; el catálogo en ~35 corridas, unas 3 h); frena ante una tanda de más de 8 s, un 5xx o una petición sin respuesta.
- **Si no corre:** las fichas se arman cuando llega el primer cliente (0,5–1,3 s de servidor en vez de
  10 ms). Nada se rompe.
- **Dónde se ve:** Akibara → Panel → Velocidad (avance, guardadas, ya guardadas, sin caché, errores y
  el último error).

### 28. `AdminDashboard` — la tarjeta de salud

**Qué hace.** La única pantalla que le dice al dueño si las tareas programadas se están ejecutando.

- **Ficheros:** `plugins/akibara-business/src/Modules/AdminDashboard/AdminDashboard.php`
- **Cuándo actúa:** al pintar la pestaña del escritorio → `health_alerts()`.
- **Qué comprueba:** que el evento exista (casi nunca se dispara, porque cada módulo lo re-agenda en
  `init` mucho antes) y —la que importa— que no lleve **más de 6 horas de atraso**, el síntoma de que
  WP-Cron dejó de ejecutarse. Y desde 1.116.31 (`R7-cola-sin-vigilar`) también la **cola de Action
  Scheduler**, que es la vía normal de casi todo el trabajo del plugin y no tenía vigilancia (la
  vitrina llevó 14 días sin reordenarse con la tarjeta en verde): acciones propias **fallidas** en los
  últimos 7 días, por hook y con la última, y acciones propias **pendientes vencidas** más de 6 horas
  (`Utilities\QueueHealth`, sólo lectura sobre `wp_actionscheduler_actions`; «propias» = los hooks de
  `OwnCrons::QUEUE`, así que las fallidas de plugins que ya no existen no cuentan).
- **Qué vigila, exactamente:** `akb_order_preventa_recompute_ready`, `akb_bis_cleanup_terminal` y
  —solo con el interruptor encendido— `akb_order_preventa_t7_reminder_scan` (`:85-95`). **Tres de los
  siete** eventos **recurrentes** propios de WP-Cron. Quedan fuera `akb_upsells_autofill_cron`,
  `akibara_business_llms_manifest_refresh`, `akibara_business_social_image_derivatives` y
  `akb_preventa_vencida_sweep` — y el comentario que justifica el chequeo nombra explícitamente el
  manifiesto, que no está en la lista. (El **octavo** evento propio de WP-Cron,
  `akibara_business_social_image_for_slug`, es **puntual**: se crea, corre una vez y desaparece, así
  que ni siquiera podría vigilarse con una regla de «lleva más de 6 horas de atraso».)
- **Lo que no mira en absoluto:** **nada de Action Scheduler**. El vigilante del buscador, la
  reconciliación diaria, la vitrina del catálogo, los dos carriles de puntos y la retención de Blue
  Express no tienen ninguna pantalla que diga si corren.

### Piezas de apoyo — no programan nada, y aparecen en todo el capítulo

Clases que no agendan ni un solo trabajo y que, sin embargo, escriben los datos, deciden las
bifurcaciones o mandan los correos de los que hablan las fichas de arriba. Una línea cada una, con su
ruta, para que nadie se quede buscándolas. Rutas relativas a `public_html/wp-content/`.

| Pieza | Dónde vive | Qué es |
| --- | --- | --- |
| `OrderPreventa\ReminderEmail` | `plugins/akibara-business/src/Modules/OrderPreventa/ReminderEmail.php` | El correo «tu pedido se acerca». Es un `WC_Email` (identificador `akb_preventa_reminder`): sale en el panel de correos de WooCommerce, y su asunto y su encabezado son campos de formulario (`:700-712`) |
| `OrderPreventa\ReadyToShipEmail` | `…/OrderPreventa/ReadyToShipEmail.php` | El correo «tu pedido ya llegó». También `WC_Email` (`akb_preventa_ready`) |
| `OrderPreventa\SendFailureRearm` | `…/OrderPreventa/SendFailureRearm.php` | El re-armado cuando `wp_mail()` devuelve falso: cuenta los intentos hasta 3 y escribe las metas `_attempts` |
| `OrderPreventa\PreventaItems` | `…/OrderPreventa/PreventaItems.php` | Quien decide qué líneas de un pedido «siguen esperando». **Ojo con sus dos hermanas**: recuadro justo debajo |
| `OrderPreventa\MetaKeys` | `…/OrderPreventa/MetaKeys.php` | El catálogo de nombres de metas. Ningún módulo escribe esas cadenas a mano |
| `OrderPreventa\FulfillmentCapabilities` | `…/OrderPreventa/FulfillmentCapabilities.php` | El contrato neutral de «qué sabe hacer el transportista». Es lo que evita que el vigía del §8 conozca a Blue Express por su nombre |
| `OrderPreventa\CompletionPolicy` | `…/OrderPreventa/CompletionPolicy.php` | Dice si un pedido con preventa se puede dar por terminado. Lo consulta `akibara-rewards` antes de invitar a reseñar (§23) |
| `OrderPreventa\PreventasScreen` | `…/OrderPreventa/PreventasScreen.php` | La pantalla *Por llegar*. Dispara pasadas completas sobre pedidos a demanda (§3) |
| `BackInStock\Install` · `SeriesFollow\Install` | `…/BackInStock/Install.php` · `…/SeriesFollow/Install.php` | Crean —y borran al desinstalar— las dos tablas propias que este capítulo da por existentes: inscripciones a la lista de espera y cola de avisos de serie |
| `BackInStock\InstockEmail` | `…/BackInStock/InstockEmail.php` | El correo «está de vuelta». `WC_Email` (`akb_bis_instock`) |
| `BackInStock\SubscriptionNotice` | `…/BackInStock/SubscriptionNotice.php` | El correo de alta en la lista de espera. **No** es `WC_Email`: `wp_mail()` plano, a propósito |
| `SeriesFollow\SeriesFollowEmail` | `…/SeriesFollow/SeriesFollowEmail.php` | El correo «salió el tomo nuevo». `WC_Email` (`akb_sf_series`) |
| `Search\Arrival` | `plugins/akibara-search/src/Arrival.php` | Sella las dos fechas irreproducibles de un tomo: cuándo entró en preventa y cuándo llegó de verdad |
| `Search\HealthReport` | `plugins/akibara-search/src/HealthReport.php` | Arma lo que se pinta en *Akibara → Buscador*: fase, deuda, última auditoría |
| `Search\SearchAdminSection` | `plugins/akibara-search/src/SearchAdminSection.php` | La pantalla del buscador. Su botón «Verificar consistencia» es el **único** camino manual para encolar la auditoría diaria (`:272-276`) |
| `Rewards\Points\Emails` | `plugins/akibara-rewards/src/Points/Emails.php` | Los dos correos de puntos. **No** son `WC_Email`: salen con `WC()->mailer()->send()` (`:142-170`), así que no aparecen en el panel de correos de WooCommerce y no se pueden apagar desde ahí |

> **La trampa que más falsos positivos va a producir en esta área.** `PreventaItems` tiene dos
> funciones que se parecen mucho y significan cosas distintas.
> `outstanding_items()` / `has_outstanding_items()` (`:56-82`) quiere decir «líneas compradas en
> preventa que **no se han reembolsado**», haya llegado el tomo o no.
> `pending_items()` (`:119-134`) quiere decir «líneas cuyo tomo **todavía no está en bodega**».
> Un pedido ya marcado listo sigue teniendo `outstanding_items` mientras nadie reembolse nada — y por
> eso la segunda pasada del barrido diario, la que deja la nota de «listo hace días y sin despachar»,
> **sí** puede dispararse. Quien depure «¿por qué no se marcó listo este pedido?» y mire la función
> equivocada va a concluir lo contrario de lo que pasa.

---

## Lo programado

Todo el trabajo programado del código propio. **Mecanismo** dice si vive en la lista de WordPress
(WP-Cron) o en la cola de WooCommerce (Action Scheduler, abreviado *AS*). Las horas de Chile salen de
la captura de producción del 2026-09-11.

> **Cómo leer la columna de la hora, porque es donde más se equivoca la gente.** En este capítulo solo
> hay **dos horas escritas en el código** (tres, contando el recalentado de fichas, §28): el domingo a las 03:00 del recordatorio (§4) y las 00:05 de
> la vitrina (`CatalogOrder.php:44-45`, `:167-184`). **Todas las demás son heredadas**: el código dice
> «dentro de una hora» o «dentro de dos horas» y la hora resultante es, para siempre, el instante en
> que se agendó por primera vez. Van marcadas como `hora heredada`. Una reinstalación, un rescate o
> una baja de la acción **cambian esa hora**, y nadie la elige. Es también la única palanca que hay
> para moverla: ver «Cómo se mira, cómo se fuerza y cómo se cambia una hora».

| Trabajo (hook) | Mecanismo | Frecuencia y hora Chile | Quién lo programa | Qué hace | Si corre dos veces |
| --- | --- | --- | --- | --- | --- |
| `akb_preventa_vencida_sweep` | WP-Cron | diaria · ~02:37 · hora heredada | `maybe_schedule()` en `init`, toda petición | Toca hasta 50 fichas de preventa vencida | Inocuo: el testigo `_akb_preventa_vencida_swept` hace avanzar el cursor |
| `akibara_business_social_image_derivatives` | WP-Cron | diaria · ~19:05 · hora heredada | `register()`; el botón «Regenerar portadas» (el guardado de la identidad **no**: ese encola solo el manifiesto, `LlmsManifest.php:495-497`) | Convierte hasta 100 portadas a JPG | Inocuo: el candado de 15 min y el cursor lo impiden |
| `akb_order_preventa_recompute_ready` | WP-Cron | diaria · ~19:30 · hora heredada | `maybe_schedule()` en `init` | Recalcula pendientes, marca listos, deja notas | Inocuo: la marca es inmutable y las notas van selladas |
| `akb_upsells_autofill_cron` | WP-Cron | diaria · ~19:30 · hora heredada (**el mismo instante** que el anterior) | `maybe_schedule()` en `init` | Rellena hasta 100 productos sin recomendaciones | Inocuo: solo llena huecos |
| `akb_bis_cleanup_terminal` | WP-Cron | diaria · ~20:30 · hora heredada | `maybe_schedule()` en `init` | Borra inscripciones terminales de más de 180 días | Inocuo: borrado por fecha |
| `akibara_business_llms_manifest_refresh` | WP-Cron | diaria · ~21:45 · hora heredada, más eventos puntuales | `register()`; guardar la identidad; botón del panel | Regenera `/llms.txt` y `/llms-full.txt` | Inocuo: permiso con token de 30 min |
| `akb_order_preventa_t7_reminder_scan` | WP-Cron | semanal · **domingo 03:00**, una de las dos horas escritas en el código (se corre con el cambio de horario) | `maybe_schedule()` en `init`, solo con el interruptor encendido | Manda «tu pedido se acerca» | Inocuo: `_akb_eta_t7_reminder_sent` es sello único de por vida |
| `akibara_business_social_image_for_slug` | WP-Cron (puntual) | inmediato, uno por portada | `ensure_for_slug()` desde el frente | Convierte una portada concreta | Inocuo: si el JPG existe, no hace nada |
| `akibara_business_cleanup_collection_owner_fence` | **AS** (grupo `akibara-business`), con WP-Cron de respaldo | cadena: +2 d, luego cada 12 h, tope 7 d | `append_owner_fence()` al vincular un pedido de invitado | Vacía cachés y acaba borrando la fila | Inocuo: idempotente por vencimiento |
| `akb_search_rebuild_watchdog` | AS (`akibara-search`) | **recurrente cada 900 s** | `ensure_watchdog()` en `init:21` + `action_scheduler_ensure_recurring_actions` | Vigila, recupera y alarma | Inocuo, pero dos vigilantes duplican el trabajo: por eso el runbook manda cancelar el viejo |
| `akb_search_pump_live` | AS asíncrona (`akibara-search`) | al ensuciar un producto; con barrera, a +demora | Los 25 oyentes del `Indexer`, el vigilante, la publicación editorial | Drena la deuda del índice | Inocuo: la deuda es durable y se reclama |
| `akb_search_rebuild_batch` | AS (`akibara-search`) | por lote de una reconstrucción | `enqueue_wakeup()` | Construye un tramo del índice nuevo | Inocuo: los lotes se reclaman con permiso |
| `akb_search_reconcile` | AS (`akibara-search`) | diaria · 12:50 (15:50 UTC) · hora heredada | `ensure_scheduled()` en `init:20`, solo con la indexación encendida; botón del panel | Audita índice contra catálogo y repara | Inocuo, pero caro: dos auditorías completas |
| `akb_search_editorial_discount_boundary` | AS (`akibara-search`) | en el instante exacto de inicio o fin de una campaña | `schedule_boundary()` tras publicar la foto de precios | Reconcilia precios en el índice | Inocuo: re-verifica secuencia, huella e instante |
| `akb_sf_scan_product` | AS (`akibara-series-follow`) | +120 s de publicar; paginación a +30 s | `PublishListener` | Encola avisos para los seguidores | Inocuo: clave única por evento en la cola |
| `akb_sf_digest_run` | AS (`akibara-series-follow`) | cadena: +6 h tras un escaneo, +60 s mientras quede gente, o hasta liberarse el tope semanal (máx. 1 día) | El escaneo, la propia corrida, el guardado del interruptor | Manda un correo por cliente con todas sus series | Inocuo: reclamo atómico de filas |
| `akb_rewards_expiry_daily` | AS (**sin grupo**) | diaria · 17:15 (20:15 UTC) · hora heredada | `ensure_scheduled()` en `init:20`, memorizado 12 h | Vence puntos y encola avisos | Inocuo: clave de evento `expiry_{usuario}_{Ymd}` contra índice único |
| `akb_rewards_expiry_batch` | AS (sin grupo) | una por cada 100 usuarios, inmediata | `run_daily()` cuando hay más de 100 | Procesa su lote | Inocuo: misma clave de evento |
| `akb_rewards_expiry_warn_async` | AS asíncrona (sin grupo) | una por usuario avisado | `process_user()` | Manda el aviso de puntos por vencer | Inocuo: la meta del mes lo corta |
| `akb_rewards_birthday_daily` | AS (**sin grupo**) | diaria · 16:10 (19:10 UTC) · hora heredada | `ensure_scheduled()` en `init:20`, memorizado 12 h | Acredita puntos de cumpleaños | Inocuo: doble red (meta del año + clave `birthday_{usuario}_{año}`) |
| `akb_rewards_send_review_invite` | AS (sin grupo) | +3 días del estado disparador; reintento +6 h, máx. 3 | `Invitations::on_status_changed` | Manda la invitación a reseñar | **Deja basura**: cada corrida emite un token nuevo sin borrar el anterior |
| `akibara_catalog_order_daily` | AS (`akb-catalog-order`) | diaria · **00:05** (antes 05:00), la otra hora escrita en el código; re-anclada si deriva más de 15 min | `maybe_schedule_daily()` en `init:20` | Reordena la vitrina y purga la caché | Inocuo, pero purga la caché dos veces |
| `akibara_catalog_order_recompute` | AS (`akb-catalog-order`) | +120 s tras publicar o retirar; 0 s desde el botón | `schedule_recompute()` | Lo mismo, a demanda | Inocuo |
| `akb_order_preventa_dispatch_ready_email` | AS (`akibara-order-preventa`) | inmediata al marcar listo; **reintento +305 s sin tope** | El flip diario, el reenganche por estado, el reenvío manual | Manda «tu pedido ya llegó» | Inocuo para el cliente (`_akb_eta_ready_email_sent` lo corta), pero puede ciclar para siempre |
| `akb_order_preventa_recompute_for_product` | AS (`akibara-order-preventa`) | +5 s del cambio de stock | `StockChangeListener::on_stock_status_change` | Marca listos los pedidos cuyos tomos llegaron | Inocuo: reclamo atómico de hermanas |
| `akb_order_preventa_release_date_changed_batch` | AS (`akibara-order-preventa`) | inmediata al cambiar la fecha; reintento +60 s con el candado tomado | `ReleaseDateChangeListener::on_release_date_changed` | Recalcula arribos y notifica | Inocuo: candado global + firma `vieja__nueva` |
| `akb_order_preventa_verify_carrier_handoff` | AS (`akibara-order-preventa`) | +2 h del traspaso al transportista | `CarrierHandoffMonitor::on_status_changed` | Comprueba si hay seguimiento | Inocuo: `_akb_eta_carrier_handoff_alerted` |
| **`akb_order_preventa_verify_bluex_handoff`** | AS (herencia) | **nadie lo agenda hoy**; solo drena lo encolado por versiones anteriores | — | Delega en el vigía del transportista | Inocuo — pero **corre sin manejador** si el plugin de envíos deja de estar disponible |
| `akb_bis_notify_product` | AS asíncrona + cadena (`akibara-bis`) | inmediata al reponer; tandas de 20 cada 60 s; pausa 15 min × 4 | `StockWatcher::enqueue()`, el propio despachador, el guardado del interruptor | Manda «{producto} está de vuelta» | Inocuo: `mark_notified()` es un reclamo atómico |
| `akibara_bluex_log_retention_cleanup` | AS (`akibara-bluex`) | diaria · 21:55 (00:55 UTC) · hora heredada | `action_scheduler_init:20`, solo en contextos reparables | Poda los registros de envíos | Inocuo: borrado por fecha, pero duplica el bloqueo de tabla |
| `bluex_clean_logs` | WP-Cron (evento del tercero) | diaria | El plugin de Blue Express | El mismo callback propio, enganchado encima | Inocuo, mismo motivo |
| `akibara_bluex_integrity_weekly` | AS (**sin grupo**: se agenda sin pasarlo, `akibara-bluex-integrity-watch.php:139-140`) | semanal · lunes 19:23 (22:23 UTC) · hora heredada | `init:30` en escritorio o cron | Escanea el árbol del tercero y avisa por correo | Inocuo: solo lectura; el correo de alarma se repite a propósito |
| `akb_search_reindex_product` | — | **nunca** | **nadie** | Manejador declarado como adaptador de versiones anteriores | — |
| `akb_search_reindex_term` | — | **nunca** | **nadie** | Ídem | — |
| `akibara_discovery_warming` | — | **el evento** se retiró en la 1.6.0 | — | — | Comprobado ausente en producción y en el espejo. **El fichero sigue instalado** (`mu-plugins/akibara-discovery-warming.php`) y hoy solo fija cabeceras de caché para los mapas del sitio (`:27-43`): quien liste la carpeta lo va a encontrar, y no programa nada |

### Lo que corre en producción y **no** es nuestro

No tocar ninguno de estos. En WP-Cron: `action_scheduler_run_queue` (**cada minuto**, el motor de la
cola — sin él no corre nada nuestro de Action Scheduler), `clarity_collect_batch_cron` (cada 5 min),
`litespeed_task_ccss` / `ucss` / `avatar` / `imgoptm_req` / `lqip` / `guest_sync` (cada 15 min),
`bluex_clean_logs`, y las familias `rank_math/*`, `wc_admin_*`, `jetpack_*`, `wp_*` y `adbc_cron_*`.
En Action Scheduler: `gla/*` (Google Listings & Ads, 1.006 acciones completadas en 24 h — el mayor
consumidor con diferencia), `woocommerce_deliver_webhook_async` (359 en 24 h) y tres restos de plugins
que ya no están: `pmw_*` (32 fallidas), `fetch_patterns` y `cartflows_*`. Totales de la cola el
2026-09-11: 14.583 completadas, 50 fallidas, 20 canceladas, 28 pendientes.

**Ningún evento agendado en producción quedó sin dueño identificado** — con el alcance dicho: se
comparó la captura de producción del **2026-09-11** (lista de WP-Cron y acciones de Action Scheduler)
contra los 27 ficheros propios que agendan trabajo, y cada entrada tiene su fila en la tabla de
arriba. No hay huérfanos propios. Sí existe el fenómeno contrario —manejadores registrados que nadie
agenda— y son **tres**, no dos: los dos del buscador y el de Blue Express.

**Lo que esa captura no pudo decir**, y que solo se sabe mirando el servidor hoy: cuántas acciones hay
ahora mismo pendientes o fallidas, y desde cuándo. Ahí es donde se vería si el reintento sin tope del
correo «ya llegó» (§5) está ciclando contra algún pedido. La sección siguiente trae el comando.

![Reloj de Chile: a qué hora corre cada cosa](diagramas/16-todo-lo-programado-cron.mmd)

---

## Cómo se mira, cómo se fuerza y cómo se cambia una hora

Todo lo anterior explica **qué** pasa. Esta sección es lo otro: **dónde se mira, qué se escribe y qué
se toca** cuando algo no salió. Está pensada para leerse a las tres de la mañana.

> **Antes de nada.** Casi nada de esto tiene pantalla, así que se hace por línea de comandos, y para
> eso hay que entrar al servidor de producción: **`ssh akibara`**. Una vez dentro, todos los comandos
> que empiezan por `wp` son de WP-CLI, la herramienta oficial de WordPress para la consola. Regla de
> la casa que no se salta nunca: **`wp eval` en producción jamás debe disparar avisos del escritorio**
> — WooCommerce se cae con un error fatal y al dueño le llega un correo de recuperación. Los `wp eval`
> de esta sección solo dan de baja acciones; no disparan nada.

### 1 · Ver qué hay agendado

```bash
ssh akibara

# La lista de WordPress (WP-Cron): todos los eventos, con su próxima hora.
wp cron event list
wp cron event list --fields=hook,next_run_relative,recurrence --format=table

# ¿Se está ejecutando la lista? (comprueba que el latido llegue)
wp cron test

# La cola de WooCommerce (Action Scheduler), por estado.
wp action-scheduler list --status=pending  --format=count
wp action-scheduler list --status=failed   --format=count
wp action-scheduler list --hook=akb_search_rebuild_watchdog --status=pending
wp action-scheduler list --group=akibara-order-preventa --status=failed \
  --fields=ID,hook,args,status,scheduled_date
```

La misma cola, con pantalla y sin consola, está en **WooCommerce → Estado → Acciones programadas**
(`/wp-admin/admin.php?page=wc-status&tab=action-scheduler`). Es la única pantalla del sitio que la
muestra, y no es nuestra.

### 2 · Forzar un trabajo ahora, sin esperar su hora

```bash
# Un evento de WP-Cron, ya.
wp cron event run akb_order_preventa_recompute_ready

# Una o varias acciones de la cola, ya.
wp action-scheduler run --hooks=akb_search_reconcile
wp action-scheduler run --group=akibara-bis --batch-size=20
```

Forzar una corrida **no** altera la siguiente: la recurrente sigue con su hora.

### 3 · Mover una hora (la pregunta más frecuente, y la respuesta incómoda)

«Los correos de puntos salen a las 17:15 y quiero que salgan a las 03:00.» **No hay ningún campo donde
escribir esa hora.** Salvo las horas escritas en el código (domingo 03:00, vitrina 00:05 y recalentado 00:20), todas las
demás son la hora en que la acción se agendó por primera vez. Así que la maniobra es siempre la
misma, en dos pasos:

1. **Dar de baja la acción recurrente**, con su grupo exacto (si el grupo está mal, la llamada cancela
   cero y no avisa de nada).
2. **Dejar que el propio plugin la recree** la próxima vez que corra `init` — cada carril tiene un
   `ensure_scheduled()` / `maybe_schedule()` que la vuelve a crear **a `time() + 1 h`** (o `+2 h` en el
   vencimiento de puntos). Esa suma es la que decide la hora nueva: hay que dar la baja **una hora
   antes** de la hora deseada, y provocar después una visita al escritorio, una corrida de cron o un
   comando de WP-CLI, que es lo único que ejecuta ese `ensure_scheduled()`.

```bash
# Action Scheduler — hook y GRUPO exactos.
wp eval "as_unschedule_all_actions( 'akb_rewards_expiry_daily',     array(), '' );"
wp eval "as_unschedule_all_actions( 'akb_rewards_birthday_daily',   array(), '' );"
wp eval "as_unschedule_all_actions( 'akb_search_rebuild_watchdog',  array(), 'akibara-search' );"
wp eval "as_unschedule_all_actions( 'akb_search_reconcile',         array(), 'akibara-search' );"
wp eval "as_unschedule_all_actions( 'akibara_catalog_order_daily',  array(), 'akb-catalog-order' );"
wp eval "as_unschedule_all_actions( 'akibara_bluex_log_retention_cleanup', array(), 'akibara-bluex' );"
wp eval "as_unschedule_all_actions( 'akibara_bluex_integrity_weekly',      array(), '' );"

# WP-Cron — basta el aviso.
wp cron event delete akb_preventa_vencida_sweep

# Y después, empujar el ensure_scheduled() que la recrea:
wp cron event run --due-now
```

> **Trampa que cuesta medio día.** Los dos carriles de puntos **no se recrean durante 12 horas** si se
> les borra la acción: su `ensure_scheduled()` sale en seco mientras viva el transitorio
> `akb_rewards_expiry_scheduled` / `akb_rewards_birthday_scheduled` (§21, §22). Al dar la baja hay que
> borrar también el transitorio, o el carril queda muerto media jornada:
> `wp transient delete akb_rewards_expiry_scheduled` y
> `wp transient delete akb_rewards_birthday_scheduled`. Lo mismo aplica al buscador
> (`akb_search_watchdog_guard_v2`, `akb_search_reconciler_scheduled`), con la diferencia de que ahí sí
> hay red: el vigilante recurrente y el aviso `action_scheduler_ensure_recurring_actions` lo reparan
> solos.

### 4 · Los grupos: cómo se cancela un carril entero

Un «grupo» es la etiqueta con la que Action Scheduler junta las acciones de un mismo carril; sin ella
no hay forma de cancelarlas de una vez. Esta es la lista completa del código propio.

| Grupo | Trabajos que lleva | Cómo se cancela el carril entero |
| --- | --- | --- |
| `akibara-order-preventa` | `akb_order_preventa_dispatch_ready_email`, `..._recompute_for_product`, `..._release_date_changed_batch`, `..._verify_carrier_handoff`, `..._verify_bluex_handoff` | `wp eval "as_unschedule_all_actions( '', array(), 'akibara-order-preventa' );"` |
| `akibara-bis` | `akb_bis_notify_product` | igual, con `'akibara-bis'` |
| `akibara-series-follow` | `akb_sf_scan_product`, `akb_sf_digest_run` | igual, con `'akibara-series-follow'` |
| `akb-catalog-order` | `akibara_catalog_order_daily`, `akibara_catalog_order_recompute` | igual, con `'akb-catalog-order'` |
| `akibara-business` | `akibara_business_cleanup_collection_owner_fence` | igual, con `'akibara-business'` |
| `akibara-search` | `akb_search_rebuild_watchdog`, `akb_search_pump_live`, `akb_search_rebuild_batch`, `akb_search_reconcile`, `akb_search_editorial_discount_boundary` | igual, con `'akibara-search'`. **Es el único carril que ya se limpia solo al desinstalar** |
| `akibara-bluex` | **solo** `akibara_bluex_log_retention_cleanup` | igual, con `'akibara-bluex'` |
| **sin grupo** | `akb_rewards_expiry_daily`, `akb_rewards_expiry_batch`, `akb_rewards_expiry_warn_async`, `akb_rewards_birthday_daily`, `akb_rewards_send_review_invite` y `akibara_bluex_integrity_weekly` | **No se pueden cancelar por carril.** Hay que ir aviso por aviso: `wp eval "as_unschedule_all_actions( 'akb_rewards_expiry_daily', array(), '' );"`, y así con cada uno |

**Y esta es la maniobra de limpieza que hoy no hace ningún plugin al desactivarse** (salvo el
buscador). Si se desactiva `akibara-business` o `akibara-rewards` y se quiere dejar la cola limpia, hay
que recorrer esa tabla a mano, grupo por grupo, y después los seis avisos sin grupo. Comprobación
final, que debe devolver cero:

```bash
for g in akibara-order-preventa akibara-bis akibara-series-follow akb-catalog-order akibara-business akibara-search akibara-bluex; do
  echo -n "$g: "; wp action-scheduler list --group="$g" --status=pending --format=count
done
```

### 5 · Señales de vida: cómo saber si un trabajo corrió

La tarjeta de salud del escritorio vigila tres eventos de WP-Cron y **nada** de Action Scheduler
(§28). Para el resto, cada trabajo deja su propio rastro. Esta es la vista de diagnóstico que el
capítulo no tenía en un solo sitio.

| Trabajo | Qué mirar | Qué es sano |
| --- | --- | --- |
| Vitrina del catálogo | `wp option get akb_catalog_order_last_run --format=json` | `{time, rows, applied}` con `time` de hoy o de ayer; también se ve en *Akibara → Vitrina* |
| Auditoría del buscador | `wp option get akb_search_reconcile_last --format=json` y `wp option get akb_search_reconcile_last_error` | Un resumen con fecha de hoy y el error **vacío**; también en *Akibara → Buscador → Índice* |
| Estado del buscador | `wp option get akb_search_rebuild_state --format=json` | `DONE`, o una fase en movimiento. `BLOCKED` o `ABORTED` = parado |
| Alarma del buscador | `wp option get akb_search_serving_alarm --format=json` | **Que no exista.** Si existe, `{down_since, notified_at}` con marcas de tiempo |
| Manifiestos de IA | `wp option get akibara_business_llms_manifest_last_success` y `..._last_error` | Éxito con fecha reciente, error vacío; también en *Akibara → Salud SEO* |
| Recomendaciones | `wp option get akb_upsells_autofill_last_run --format=json` | Corrida de hoy; también en *Akibara → Recomendaciones* |
| Portadas sociales | `wp option get akb_social_derivatives_cursor` | Un cursor que **avanza** entre días. Si no se mueve, el rellenado está atascado |
| Lista de espera | *Akibara → Lista de espera* | Apuntados sin notificar que no crezcan indefinidamente |
| Avisos de serie | *Akibara → Sigue esta serie* | Ojo: la pantalla dice «sin programar» mientras la corrida se **está ejecutando** (§11) |
| Vigía de Blue Express | `wp option get akb_bluex_watch_baseline_seen` y `wp option get akb_bluex_watch_resolved_notified` | Ver el recuadro de abajo |
| Transporte de correo | `wp option get akibara_mail_transport_watch --format=json` | `{missing_since, last_seen_gmt, outcome, restores[]}`; `restores` vacío es la buena noticia |

> **Las tres opciones que deciden si sale un correo y no tienen pantalla.** `akb_search_serving_alarm`
> guarda `{down_since, notified_at}`: `down_since` es cuándo se vio caído el buscador por primera vez
> y `notified_at` marca que la alarma ya salió, para no repetirla; borrarla entera **rearma** la
> alarma. `akb_bluex_watch_baseline_seen` guarda que ya se vio el estado bueno del paquete del
> transportista (sin eso no hay con qué comparar) y `akb_bluex_watch_resolved_notified`, que ya se
> mandó el correo de «arreglado». Si una carrera deja esta última marcada, el correo de buena noticia
> no vuelve a salir nunca. Se leen y se reinician así:
>
> ```bash
> wp option get akb_search_serving_alarm --format=json
> wp option delete akb_search_serving_alarm          # rearma la alarma del buscador
> wp option get akb_bluex_watch_resolved_notified
> wp option delete akb_bluex_watch_resolved_notified # permite que vuelva a salir «arreglado»
> wp option delete akb_bluex_watch_baseline_seen     # obliga a tomar la foto de referencia de nuevo
> ```

### 6 · Las pantallas, con su dirección

Trece menciones a pantallas en este capítulo, y todas viven aquí. El panel propio usa el identificador
**`akibara-panel`**; el corto `akibara` devuelve un error 403 y parece un problema de permisos cuando
no lo es.

| Pantalla (como se llama en el menú) | Dirección |
| --- | --- |
| Akibara → Dashboard (la tarjeta de salud) | `/wp-admin/admin.php?page=akibara-panel&tab=dashboard` |
| Akibara → Pedidos y preventa · Ajustes | `…?page=akibara-panel&tab=preventa` |
| Akibara → Por llegar (la operación del día a día) | `…?page=akibara-panel&tab=preventas` |
| Akibara → Lista de espera | `…?page=akibara-panel&tab=back_in_stock` |
| Akibara → Sigue esta serie (los avisos de tomo nuevo) | `…?page=akibara-panel&tab=series_follow` |
| Akibara → Recomendaciones | `…?page=akibara-panel&tab=upsells_autofill` |
| Akibara → Vitrina | `…?page=akibara-panel&tab=catalog_order` |
| Akibara → Salud SEO | `…?page=akibara-panel&tab=salud_seo` |
| Akibara → Descuentos | `…?page=akibara-panel&tab=taxonomy_discount` |
| Akibara → Editoriales · Ediciones | `…?page=akibara-panel&tab=brand_editions` |
| Akibara → Buscador | `/wp-admin/admin.php?page=akibara-search&sub=indice` (sub-pestañas: `indice`, `busquedas`, `facetas`, `precios`, `sinonimos`, `stopwords`, `ajustes`) |
| WooCommerce → Puntos y Reseñas (el panel de Akibara Puntos) | `/wp-admin/admin.php?page=akb-rewards-settings` |
| WooCommerce → Ajustes → Correos electrónicos | `/wp-admin/admin.php?page=wc-settings&tab=email` |
| WooCommerce → Estado → Acciones programadas | `/wp-admin/admin.php?page=wc-status&tab=action-scheduler` |

### 7 · Los correos: cuál se apaga, cuál se edita y dónde

Hay dos clases de correo en esta tienda, y la diferencia decide todo lo que se puede hacer con él.
Un **`WC_Email`** es un correo registrado en WooCommerce: aparece como una fila en *WooCommerce →
Ajustes → Correos electrónicos*, con su casilla «Habilitar» y sus campos de asunto y encabezado. Un
**`wp_mail()` plano** no aparece en ninguna parte: ni se apaga ahí, ni se edita ahí.

| Correo | Tipo | Dónde se edita | Cómo se apaga |
| --- | --- | --- | --- |
| «Tu pedido {número} se acerca» | `WC_Email` · `akb_preventa_reminder` | *Correos electrónicos → Aviso previo a la llegada*: asunto y encabezado son campos (`ReminderEmail.php:700-712`). **Los modos «cambio de fecha» y «fecha anunciada» usan texto propio** y no se editan ahí | Casilla «Habilitar» de esa fila, o el interruptor propio `akb_preventa_t7_enabled` en *Akibara → Pedidos y preventa* |
| «Tu pedido {número} ya llegó a Akibara» | `WC_Email` · `akb_preventa_ready` | Su fila en *Correos electrónicos* | Casilla «Habilitar» de esa fila |
| «{producto} está de vuelta en Akibara» | `WC_Email` · `akb_bis_instock` | Su fila en *Correos electrónicos* | Casilla «Habilitar», o el interruptor `akb_bis_dispatch_enabled` en *Akibara → Lista de espera* |
| «Te anotamos en la lista de espera de {producto}» | **`wp_mail()` plano**, a propósito | Solo en el código (`SubscriptionNotice.php`) | **No se puede apagar** desde WooCommerce: es una decisión sellada. Tampoco depende del interruptor de despacho |
| «Salió el tomo {n} de {serie}» | `WC_Email` · `akb_sf_series` | Su fila en *Correos electrónicos* | Casilla «Habilitar», o el interruptor `akb_sf_enabled`. **Cuidado:** apagarlo desde WooCommerce **congela la cadena sin señal** (§11) |
| «🎂 {n} Puntos Akibara de regalo por tu cumpleaños» | **No es `WC_Email`**: `WC()->mailer()->send()` (`Points/Emails.php:142-170`) | Solo en el código | **No aparece en el panel de correos.** Se apaga poniendo el monto de cumpleaños en 0, en *WooCommerce → Puntos y Reseñas* |
| «{n} Puntos Akibara por vencer — úsalos antes» | Ídem, no es `WC_Email` | Solo en el código | Ídem: se apaga con `akb_rewards_expiry_enabled` |
| Invitación a reseñar | Ídem, no es `WC_Email` | **No editable**: asunto y cuerpo fijos en `Invitations.php:245-280` | `akb_rewards_invite_enabled` |
| «Akibara: el buscador lleva rato caído» / «volvió» | `wp_mail()` al correo del administrador | Solo en el código | No se apaga; solo sale en producción |
| «⚠ Blue Express embarcó includes rotos» / «✅ arregló su paquete» | `wp_mail()` al correo del administrador | Solo en el código | No se apaga |

> **«El correo está deshabilitado en WooCommerce» es causa de avería tres veces en este capítulo**
> (§11, caso 2 y caso 3). La casilla está siempre en el mismo sitio: *WooCommerce → Ajustes →
> Correos electrónicos* (`/wp-admin/admin.php?page=wc-settings&tab=email`), columna del final de la
> fila correspondiente. Y el efecto no es el mismo en los tres: en la lista de espera el trabajo se
> re-agenda y espera; en los avisos de tomo nuevo **la cadena se congela y no la retoma volver a
> habilitar el correo** — hay que publicar otro tomo o tocar el interruptor propio.

### 8 · Qué decide «producción», y qué pasa en el espejo

Varias alarmas de este capítulo **no salen fuera de producción**. Quien depure «no me llegó la
alarma» tiene que empezar por aquí, antes que por nada.

- Lo decide `wp_get_environment_type()` (`RebuildJob.php:828`), que lee la constante
  `WP_ENVIRONMENT_TYPE` de `wp-config.php`. Con cualquier valor que no sea exactamente `production`,
  la alarma se descarta en silencio: `wp config get WP_ENVIRONMENT_TYPE` y `wp eval "echo wp_get_environment_type();"`.
- En el espejo, además, **ningún correo sale a un cliente real**: el mu-plugin
  `akibara-correo-no-sale-a-clientes.php` redirige todo a una bandeja de captura y deja el
  destinatario original a la vista, en el asunto y en una cabecera. Existe porque el espejo tiene 84
  direcciones reales de clientes.
- Y el rastreador de Microsoft Clarity se apaga fuera de producción con
  `akibara-clarity-production-only.php` (quita el código que se inyecta en las páginas; su tarea
  programada, `clarity_collect_batch_cron`, es del plugin del tercero y no se toca).

### 9 · Rescate del buscador

Si el buscador queda atascado —el autocompletado devuelve 503, o el panel dice «ya hay una
reconstrucción en curso» con la opción de estado vacía— **no** se improvisa:

```bash
bash ops/rescate-buscador.sh produccion
```

El orden importa y el guion lo respeta: suelta las tablas sombra `_new` **primero** y retira la opción
`akb_search_rebuild_state` **después**. Al revés se acuña el pestillo
`BLOCKED / orphan_topology_without_state`, que ni el panel ni la línea de comandos deshacen (§18).

### 10 · Los trece comandos propios

El código propio instala trece comandos de WP-CLI. Ninguno tiene botón equivalente salvo donde se
indica.

| Comando | Qué hace |
| --- | --- |
| `wp akb-search rebuild [--async]` | Reconstruye el índice entero. Medido: 1.631 documentos en 30 s, sin caída del autocompletado |
| `wp akb-search status` | Fase, deuda y salud del índice |
| `wp akb-search reindex` | Reindexa lo que esté marcado como sucio |
| `wp akb-search reconcile` | La auditoría del §19, a mano (también el botón «Verificar consistencia» del panel) |
| `wp akibara order-preventa-backfill` | Rellena las metas de preventa de pedidos antiguos |
| `wp akibara order-preventa-recompute-ready` | El barrido del §2, a mano |
| `wp akibara order-preventa-cumplimiento` | Compara la fecha prometida con la real, pedido por pedido |
| `wp akibara-catalog-order recompute` | Reordena la vitrina, a mano (equivale al botón de *Akibara → Vitrina*) |
| `wp akibara-catalog-order status` | Última corrida de la vitrina |
| `wp akibara:upsells-fill` | Rellena recomendaciones (equivale al botón de *Akibara → Recomendaciones*) |
| `wp akb-rewards audit` | Comprueba que el libro mayor de puntos y los saldos cuadren |
| `wp akb-rewards migrate` | Migración desde el sistema de puntos anterior |
| `wp akb-rewards cleanup` | Retira los restos de ese sistema anterior (exige `--confirm`) |

---

## Casos de uso

### Caso 1 · Llega el tomo que un cliente estaba esperando en su preventa

**Actor:** cliente. **Qué lo dispara:** el dueño pone stock al tomo y el producto pasa de «a pedido» a
«en stock».

1. WooCommerce dispara `woocommerce_product_set_stock_status` con estado `instock`
   (`StockChangeListener.php`, prioridad 10).
2. Se encola una acción a **+5 segundos** con el identificador del producto en los argumentos,
   deduplicando **por argumentos** y nunca con el indicador `unique` de Action Scheduler — ese
   indicador ignora los argumentos, y una reposición de tres tomos habría encolado solo el primero
   (`StockChangeListener.php:109-122`).
3. La cola la drena `action_scheduler_run_queue`, que corre cada minuto: el trabajo arranca en menos de
   un minuto.
4. El trabajador **reclama atómicamente** las acciones hermanas del mismo lote, para que una reposición
   masiva se resuelva en una sola pasada (`claim_pending_product_actions`).
5. Recorre los pedidos activos y llama a `ReadyToShipCron::process_order()` para los que contengan
   alguno de esos productos (`OrderQueryPaginator::process_all`, `StockChangeListener.php:150`).
6. `process_order` cuenta las líneas que siguen sin llegar (`PreventaItems::pending_items`). **Cuidado
   al depurar esto:** la clase tiene otra función con nombre parecido, `has_outstanding_items()`, que
   significa algo distinto —«quedan líneas de preventa sin reembolsar»— y que sigue devolviendo
   verdadero en un pedido ya marcado listo. Mirar la equivocada lleva a la conclusión contraria; el
   recuadro de «Piezas de apoyo» lo explica con las dos definiciones al lado.
7. **Bifurcación A — todavía falta algo:** actualiza el contador, recalcula el arribo **solo si el
   número cambió**, y puede dejar la nota interna «revisar manualmente» si se pasó de la gracia sobre
   la fecha prometida o del tope desde la primera promesa.
8. **Bifurcación B — no falta nada:** marca `_akb_eta_ready_to_ship = 'yes'`, sella la fecha, deja la
   nota «Pedido listo para despachar» y dispara `akb_order_preventa_marked_ready`
   (`ReadyToShipCron.php:184-196`).
9. `ReadyEmailDispatcher` oye ese aviso y agenda `akb_order_preventa_dispatch_ready_email` con
   `order_id` en los argumentos, grupo `akibara-order-preventa` (`:312`).
10. El trabajador instancia el mailer **dentro** de la acción, cuando WooCommerce ya cargó sus clases
    de correo, y dispara el envío.

**Otras bifurcaciones:**

- Si el pedido no tiene la marca `_akb_eta_has_preventa = 'yes'`, no se toca.
- Si ya estaba en `'yes'`, no se re-marca: la política es **inmutable hacia atrás**, aunque el tomo
  vuelva a agotarse.
- Si todo lo pendiente se reembolsó, se marca listo con la nota «Ya no queda nada pendiente tras el
  reembolso — no se envía aviso de llegada».
- Si el pedido quedó listo hace **más de 7 días**, el correo **no sale**: queda una nota para que
  decida el dueño.
- Si el pedido **no tenía la meta** `_akb_eta_ready_to_ship`, es invisible para la consulta
  (`:437-447`) y **nunca** entra en este camino. Es el defecto pendiente más caro del capítulo.
- Si nadie repone stock, el mismo trabajo lo hace el barrido diario de las ~19:30: este listener solo
  adelanta horas.
- Si la acción del correo **falla** (error fatal de PHP dentro del trabajador), se re-agenda a
  **+305 segundos sin contador ninguno**, indefinidamente.

**Correos que salen:**

| Asunto | Cuándo | Tipo · dónde se edita · cómo se apaga |
| --- | --- | --- |
| «Tu pedido {número} ya llegó a Akibara» | con la cola, típicamente menos de un minuto después de reponer el stock | `WC_Email` `akb_preventa_ready` · *WooCommerce → Ajustes → Correos electrónicos* · casilla «Habilitar» de esa fila |
| *(suprimido)* «Tu pedido está completo», el genérico de WooCommerce | no sale: se apaga a propósito en pedidos con preventa vigente | Es de WooCommerce; lo apaga nuestro código, no una casilla |

![Caso: llega el tomo de una preventa](diagramas/16-todo-lo-programado-caso-preventa-lista.mmd)

### Caso 2 · Un tomo agotado vuelve a bodega y hay gente apuntada

**Actor:** cliente. **Qué lo dispara:** el tomo pasa a `instock` y hay al menos una inscripción en
espera.

1. `StockWatcher::on_stock_status_change` corre en **prioridad 11**, justo después del listener de
   preventa, y consulta cuánta gente espera. Si no hay nadie, sale sin hacer nada.
2. Encola `akb_bis_notify_product` con el identificador en los argumentos, deduplicando por argumentos
   (`StockWatcher.php:100-112`).
3. El despachador **re-valida el stock** al correr: pudo volver a caer entre el encolado y el
   trabajador.
4. Dispara `akb_bis_subscribers_ready` con (producto, cuántos esperan) — **nunca** con correos ni
   tokens, para no filtrar datos a oyentes de terceros.
5. **Bifurcación A — el interruptor de despacho está apagado:** no marca ni envía, y se re-agenda como
   máximo **4 veces cada 15 minutos**. Los suscriptores no se pierden. *(Por qué estaría apagado: es
   el freno de mano de un envío masivo — una reposición parcial, una devolución que volvió a bodega,
   un inventario que hay que corregir antes de escribirle a doscientas personas, o una duda sobre el
   transporte de correo. Mientras está apagado el cliente no ve absolutamente nada distinto, y el
   dueño tampoco recibe aviso: lo único que lo delata es la tabla de demanda de «Akibara → Lista de
   espera», donde los apuntados siguen ahí sin notificar.)*
6. **Bifurcación B — las clases de correo de WooCommerce aún no cargan** (carrera de arranque): se
   re-agenda a +60 s con el **mismo** contador acotado, que viaja en los argumentos como `pause_try`.
7. **Bifurcación C — el correo está deshabilitado en WooCommerce** (la casilla «Habilitar» de la fila
   `akb_bis_instock`, en *WooCommerce → Ajustes → Correos electrónicos*,
   `/wp-admin/admin.php?page=wc-settings&tab=email`)**:** no quema el lote marcándolo como notificado;
   se re-agenda acotado y registra el hecho una sola vez.
8. Camino normal: por suscriptor, reclamo atómico (`mark_notified`), envío, y si falla se registra el
   intento y la fila vuelve al conjunto hasta el máximo, tras lo cual queda en `failed`.
9. **Tandas de 20**, y la siguiente a **+60 segundos** mientras quede gente.

**Correos que salen:**

| Asunto | Cuándo | Tipo · dónde se edita · cómo se apaga |
| --- | --- | --- |
| «Te anotamos en la lista de espera de {producto}» | en el momento del alta | `wp_mail()` plano · solo en el código · **no se puede apagar** desde WooCommerce, y tampoco lo detiene el interruptor de despacho |
| «{producto} está de vuelta en Akibara» | con la cola: 2 o 3 minutos tras reponer, **no** al guardar | `WC_Email` `akb_bis_instock` · *WooCommerce → Ajustes → Correos electrónicos* · casilla «Habilitar», o el interruptor `akb_bis_dispatch_enabled` |

![Caso: el tomo vuelve a stock y hay lista de espera](diagramas/16-todo-lo-programado-caso-lista-de-espera.mmd)

### Caso 3 · Se publica el tomo 12 de una serie que varios clientes coleccionan

**Actor:** cliente. **Qué lo dispara:** el dueño publica el producto, o el importador le asigna la serie
después de crearlo.

1. `PublishListener` escucha `transition_post_status` y `set_object_terms` y **no hace ni una
   consulta**: guardar un término es el evento más caro de la casa y este oyente viaja encima.
2. Encola `akb_sf_scan_product` a **+120 segundos**: el importador escribe el producto *antes* de
   asignarle los términos, y escanear en el mismo tick encontraría un producto sin serie y lo
   descartaría en silencio — para siempre, porque el descarte también es idempotente.
3. El escaneo comprueba que el tomo sea anunciable: publicado, no oculto del catálogo y de **menos de
   60 días** (republicar un tomo viejo no es una novedad).
4. Resuelve la serie y pide una página de 200 seguidores. «Seguidor» no es una fila guardada: es un
   hecho **derivado** — compró al menos un tomo de esa serie en un pedido pagado y no se dio de baja.
5. Encola los avisos en la tabla de cola, con **clave única por evento**. Si la página venía llena, se
   auto-pagina a +30 s con un cursor.
6. Pide una corrida del resumen con la ventana de acumulación configurada (6 h por defecto), para que
   todo lo publicado esa tarde viaje en un solo correo.
7. La corrida **arma el contenido antes de reclamar las filas**: si algo revienta armándolo, las filas
   siguen pendientes y se reintenta. Al revés, una excepción las dejaría marcadas como enviadas sin
   correo, y la clave única prohibiría reencolarlas.
8. Re-valida en el último momento: quién se dio de baja, quién ya compró ese tomo, qué tomos dejaron de
   estar publicados.
9. Reclamo atómico, envío, y sello del tope semanal **solo si el correo salió**.

**Bifurcaciones:**

- Interruptor maestro apagado: no se detecta ni se envía nada, y **no hay envío retroactivo** al
  encenderlo.
- Cliente que ya recibió un correo hace menos de 7 días: sus filas quedan pendientes y salen después.
- Lo que no cabe en el máximo de 5 series por correo queda en cola.
- Avisos de más de 60 días: caducan solos en la cola.
- **El correo deshabilitado en WooCommerce congela la cadena sin señal** (`DigestRunner.php:248-251`):
  no se re-agenda nada y no se registra nada. La casilla es la de la fila `akb_sf_series` en
  *WooCommerce → Ajustes → Correos electrónicos*
  (`/wp-admin/admin.php?page=wc-settings&tab=email`). Volver a habilitarla **no** retoma la cadena:
  hay que publicar otro tomo o tocar el interruptor propio `akb_sf_enabled` en *Akibara → Sigue esta
  serie*.

**Correos que salen:**

| Asunto | Cuándo | Tipo · dónde se edita · cómo se apaga |
| --- | --- | --- |
| «Salió el tomo {n} de {serie}» / «Salieron {n} tomos de {serie}» | tras la ventana de acumulación; máximo uno cada 7 días por cliente | `WC_Email` `akb_sf_series` · *WooCommerce → Ajustes → Correos electrónicos* · casilla «Habilitar» (**apagarla ahí congela la cadena**) o el interruptor `akb_sf_enabled` |

![Caso: se publica un tomo nuevo y hay clientes que coleccionan la serie](diagramas/16-todo-lo-programado-caso-tomo-nuevo.mmd)

### Caso 4 · El buscador se cae y el dueño se entera por correo

**Actor:** sistema. **Qué lo dispara:** el vigilante `akb_search_rebuild_watchdog`, cada 15 minutos.

1. La pasada hace tres cosas de higiene antes de mirar nada: anota los avisos de que el índice cambió
   de versión (las «publicaciones de generación»), suelta los **permisos** de lote que caducaron —cada
   trozo de trabajo se reserva por 120 segundos (`RebuildJob.php:61`) y, si el trabajador murió a
   medias, ese permiso hay que devolverlo o el trozo no lo toma nadie— y, **solo si de verdad hay
   deuda viva**, despierta la bomba del índice. (Antes encolaba siempre, y dejó 4.949 acciones vacías
   en producción.)
2. Lee el estado de la reconstrucción.
3. **Bifurcación A — estado nulo, BLOCKED o ABORTED:** le toca al vigilante levantar el servicio.
   Primero la reparación barata, `EditorialDiscountPublication::reconcile_now()`, que re-prueba el
   recibo contra las tablas vivas sin reconstruir nada. Si con eso vuelve, anota que está arriba y —si
   había mandado la alarma— manda el correo de vuelta. Si no vuelve, solicita una reconstrucción
   completa y anota que sigue caído.
4. **Bifurcación B — la reconstrucción está parada en BUILDING o CATCHING_UP:** toma la rama
   `resume_run()` y **la alarma no se evalúa**. Esta es la puerta que sigue abierta.
5. Cuando el servicio lleva **más de 900 segundos** caído, sale un correo al administrador con los
   minutos que lleva, lo que está viendo el cliente y **los dos comandos a probar**, que son estos:
   `wp akb-search status` para ver en qué fase quedó, y `bash ops/rescate-buscador.sh produccion` si
   está atascado. El umbral es a propósito más alto que una reconstrucción normal (unos 6 minutos),
   para que una rutinaria no dispare la alarma.
6. **Qué ve el cliente mientras tanto:** el autocompletado de la caja de búsqueda responde con un
   error 503, la página de resultados no trae nada y los filtros desaparecen. La tienda sigue
   vendiendo: fichas, carrito y checkout no dependen del índice. Por eso una caída puede durar horas
   sin que nadie la note.
7. Las alarmas **no salen fuera de producción**. Lo decide `wp_get_environment_type()`
   (`RebuildJob.php:828`), que lee `WP_ENVIRONMENT_TYPE` de `wp-config.php`; con cualquier valor que
   no sea exactamente `production`, el correo se descarta en silencio. Además, en el espejo el
   mu-plugin `akibara-correo-no-sale-a-clientes.php` desvía **todo** el correo a una bandeja de
   captura: una alarma ahí solo enseñaría a ignorar las alarmas de verdad.

**Correos que salen:**

| Asunto | Cuándo | Tipo · dónde se edita · cómo se apaga |
| --- | --- | --- |
| «Akibara: el buscador lleva rato caído» | una sola vez por episodio, pasados 15 minutos sin servir | `wp_mail()` al correo del administrador · solo en el código · no se apaga; solo sale en producción. Se **rearma** borrando `akb_search_serving_alarm` |
| «Akibara: el buscador volvió» | una sola vez, al recuperarse | Ídem |

![Caso: el vigilante del buscador](diagramas/16-todo-lo-programado-caso-buscador-caido.mmd)

### Caso 5 · Un cliente cumple años

**Actor:** cliente. **Qué lo dispara:** el trabajo diario `akb_rewards_birthday_daily`, a las 16:10 de
Chile.

1. La corrida calcula la fecha de hoy en la zona de la tienda y arma la lista de objetivos: **hoy y
   ayer**. La ventana de recuperación existe porque Action Scheduler corre el intervalo hacia adelante
   y un día perdido dejaría a esa gente sin regalo todo el año.
2. Si toca el 1 de marzo de un año no bisiesto, añade también el 29 de febrero.
3. Por cada `mm-dd` pagina los usuarios con esa meta, ordenados por identificador y **sin truncar**
   (truncar en un número fijo dejaba al usuario 501 sin regalo para siempre).
4. Por usuario: si ya cobró este año, se salta; si no, acredita los puntos con la clave de evento
   `birthday_{usuario}_{año}` contra el índice único del libro mayor.
5. Si el crédito funcionó, **sella la meta del año** y dispara `akb_rewards_birthday_paid`.
6. `Emails::send_birthday_email` (prioridad 100) comprueba que no se haya saludado ya este año, arma el
   correo con el saldo actualizado y lo manda; solo sella su propia meta si el transporte confirmó.

**Bifurcaciones:**

- Monto configurado en 0 o menos: no corre, y el campo ni se pinta en «Mi cuenta».
- Combinación imposible (31 de febrero): se rechaza con un aviso de error.
- Crédito fallido por infraestructura: la meta **no** se sella y mañana se reintenta.
- **Correo fallido:** la meta del año **ya está sellada**, y el único camino que dispara el saludo es la
  acreditación. El cliente **no recibe el saludo nunca**, en todo el año.

**Correos que salen:**

| Asunto | Cuándo | Tipo · dónde se edita · cómo se apaga |
| --- | --- | --- |
| «[Akibara] 🎂 {n} Puntos Akibara de regalo por tu cumpleaños» | el mismo día, con la cola | **No es `WC_Email`**: sale por `WC()->mailer()->send()` (`Points/Emails.php:142-170`), así que **no aparece en el panel de correos de WooCommerce** · solo editable en el código · se apaga poniendo el monto de cumpleaños en 0, en *WooCommerce → Puntos y Reseñas* |

### Caso 6 · A un cliente se le van a vencer los puntos

**Actor:** cliente. **Qué lo dispara:** el trabajo diario `akb_rewards_expiry_daily`, a las 17:15 de
Chile.

1. Se comprueba el interruptor y los meses de vida. Con 0 meses los puntos no vencen jamás y el módulo
   **ni siquiera se registra**.
2. Se calcula el corte restando meses **sin el desbordamiento de fin de mes de PHP** (el 31 de agosto
   menos 6 meses no es el 3 de marzo).
3. Se recolectan en una **primera fase** todos los identificadores de usuarios con saldo mayor que
   cero —solo lectura— porque el propio vencimiento deja saldos en 0 y encogería el conjunto bajo los
   pies del paginador.
4. Si son más de 100, el trabajo se trocea en acciones `akb_rewards_expiry_batch` de 100 usuarios: un
   tiempo agotado no pierde la corrida entera.
5. Por usuario, **bajo candado de base de datos** y releyendo el saldo fresco: lo vencible es lo ganado
   antes del corte menos todo lo debitado en la historia, acotado por el saldo. El candado existe
   porque calcularlo fuera dejaba una carrera con un canje simultáneo en el checkout, y el débito se
   comía puntos nuevos del cliente.
6. Si hay algo que vencer, se debita con la clave de evento `expiry_{usuario}_{Ymd}`.
7. Si no venció nada, se mira lo que vence dentro de la ventana de aviso (30 días) y se encola un aviso
   en su **propia acción asíncrona**, para que un servidor de correo lento no arrastre al resto del
   lote.
8. El correo sella la meta del mes **solo con transporte confirmado**: si el correo está caído, la
   corrida de mañana reintenta. *(Esta es la disciplina correcta, y es la que le falta al cumpleaños.)*

**Bifurcaciones:**

- Los movimientos migrados desde el sistema anterior envejecen desde la fecha del traspaso, no desde su
  fecha original; **sin esa fecha sellada quedan protegidos** (fallar hacia no quitar).
- Un aviso al mes como máximo por cliente.

**Correos que salen:**

| Asunto | Cuándo | Tipo · dónde se edita · cómo se apaga |
| --- | --- | --- |
| «[Akibara] {n} Puntos Akibara por vencer — úsalos antes» | una vez al mes por cliente, el mismo día de la corrida | **No es `WC_Email`**, igual que el de cumpleaños · solo editable en el código · se apaga con `akb_rewards_expiry_enabled`, en *WooCommerce → Puntos y Reseñas* |

![Caso: a un cliente se le van a vencer los puntos](diagramas/16-todo-lo-programado-caso-puntos-por-vencer.mmd)

### Caso 7 · El dueño pulsa «Recalcular la vitrina ahora»

**Actor:** dueño. **Qué lo dispara:** el botón de la pestaña *Vitrina del catálogo*.

1. `handle_admin_recompute` comprueba la capacidad del panel y el testigo de seguridad.
2. **Encola, no ejecuta:** el recálculo lee 1.568 productos y reescribe hasta 1.122 posiciones; hacerlo
   dentro de la petición sería una pantalla en blanco hasta el tiempo límite de PHP.
3. Devuelve **qué pasó** —`encolada`, `ya-encolada` o `ejecutada`— porque un aviso de «listo» sería
   mentira en dos de los tres casos.
4. El aviso dice explícitamente «lo ejecuta el cron del servidor en los próximos minutos» y **no** «en
   la próxima visita al sitio»: producción corre con el empuje por tráfico apagado.
5. La acción calcula el intercalado y escribe **solo** las posiciones que cambian, en trozos de 200.
6. Si se escribió algo, dispara una purga **completa** de la caché: un recálculo cambia el orden de
   todas las listas, y las purgas por etiqueta no propagan al borde de Cloudflare.
7. Deja constancia **siempre**, también cuando no había nada que cambiar: «no corrió» y «corrió y no
   había deriva» son dos diagnósticos opuestos que antes se veían iguales.

**Bifurcaciones:**

- Si ya había un recálculo en cola, no se duplica y se lo dice.
- Si un trozo de escritura falla, se deja traza y **no** se purga esa parte: su orden viejo sigue
  vigente.
- **Si el recálculo está corriendo ahora mismo** y se publica un producto, el guard devuelve
  `'ya-encolada'` y ese producto se queda fuera hasta la corrida de las 00:05 del día siguiente.

> **Por qué unos casos llevan diagrama y otros no.** Llevan dibujo los casos en los que intervienen
> tres piezas o más **y** hay bifurcaciones que cambian el resultado final: el 1, el 2, el 3, el 4 y
> el 6. Los casos 5 y 7 son cadenas de un solo carril —una corrida, una decisión, un correo— y su
> lista numerada ya es el diagrama; dibujarlos añadiría cajas sin añadir información.

---

## Reglas de negocio

Solo lo que está **literalmente escrito en el código**, con fichero y línea. Rutas relativas a
`public_html/wp-content/`.

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
| --- | --- | --- | --- |
| Una vez que un pedido se marca «listo para despachar», nunca se des-marca, aunque el tomo vuelva a agotarse | `plugins/akibara-business/src/Modules/OrderPreventa/ReadyToShipCron.php:5-8, :184-185` | No | — |
| El recordatorio previo a la llegada se manda una sola vez por pedido, y solo si al arribo prometido le quedan entre 1 y 10 días | `…/OrderPreventa/RemindersCron.php:166-183`; `Settings.php:70-72` | Sí | Akibara → Pedidos y preventa |
| Ese recordatorio se salta si la preventa original era corta: menos de 14 días entre la compra y el arribo | `…/OrderPreventa/RemindersCron.php:189-192`; `Settings.php:72` | Sí | Akibara → Pedidos y preventa |
| Un pedido «en espera» (transferencia sin confirmar) no recibe recordatorio ni cuenta como activo para el flip | `…/RemindersCron.php:212-214`; `StockChangeListener.php:283-284` | No | — |
| Retirar la fecha de salida de un tomo **no** manda correo: solo nota interna | `…/ReleaseDateChangeListener.php:20-22, :540` | No | Decisión sellada |
| Anunciar fecha sobre una compra sin fecha **sí** manda correo, sin umbral de días | `…/ReleaseDateChangeListener.php:369, :399-401` | No | Decisión sellada |
| Un adelanto de fecha solo se notifica con el interruptor encendido; por defecto está apagado | `…/ReleaseDateChangeListener.php:415-418`; `Settings.php:74` | Sí | Akibara → Pedidos y preventa |
| Un cambio de fecha por debajo del umbral (7 días) no se notifica ni recalcula el arribo | `…/ReleaseDateChangeListener.php:420-424`; `Settings.php:73` | Sí | Akibara → Pedidos y preventa |
| Una fecha nueva que ya pasó no produce ninguna notificación | `…/ReleaseDateChangeListener.php:395-397` | No | — |
| El correo «tu pedido ya llegó» no se manda si el pedido quedó listo hace más de 7 días; queda nota | `…/ReadyEmailDispatcher.php:255-286`; `Settings.php:78` | Sí | Akibara → Pedidos y preventa (0 lo desactiva) |
| En un pedido con preventa vigente se suprime el correo genérico «tu pedido está completo» | `…/ReadyEmailDispatcher.php:428-437` | No | — |
| El día del lanzamiento todavía cuenta como preventa: solo se barren fechas estrictamente anteriores a hoy en hora de Chile | `…/Stock/PreventaVencidaCron.php:106-133` | No | — |
| El barrido de preventas vencidas toca como máximo 50 fichas al día | `…/Stock/PreventaVencidaCron.php:45` | No | — |
| Las inscripciones terminales de la lista de espera se borran a los 180 días (mínimo 30) | `…/BackInStock/Retention.php:26, :65` | Sí | Solo por el filtro `akb_bis_retention_days`; **sin pantalla** |
| Los avisos de la lista de espera salen en tandas de 20 cada 60 segundos | `…/BackInStock/NotificationDispatcher.php:48, :51` | Sí | Solo por filtros; **sin pantalla** |
| Con el despacho pausado, la cola se reintenta como máximo 4 veces (≈1 h de gracia); los suscriptores no se pierden | `…/NotificationDispatcher.php:60-68, :333-347` | No | — |
| El aviso de alta en la lista de espera sale con `wp_mail()` plano a propósito: no se puede apagar desde WooCommerce | `…/BackInStock/SubscriptionNotice.php:80, :110-112` | No | — |
| Un tomo republicado de más de 60 días no genera aviso de «salió el tomo nuevo» | `…/SeriesFollow/DigestRunner.php:60-65, :695-700` | No | — |
| Un aviso de tomo nuevo caduca a los 60 días en la cola sin enviarse | `…/SeriesFollow/DigestRunner.php:52, :252` | No | — |
| Ningún cliente recibe más de un correo de «salió el tomo nuevo» cada 7 días | `…/SeriesFollow/DigestRunner.php:262-271`; `SeriesFollow.php:172-176` | Sí | Akibara → Avisos de tomo nuevo |
| Al encender los avisos de tomo nuevo no hay envío retroactivo | `…/SeriesFollow/SeriesFollow.php:25-28, :165` | No | — |
| «Seguir una serie» no se almacena: es derivado de haber comprado y no haberse dado de baja | `…/SeriesFollow/SeriesFollow.php:10-18` | No | — |
| La vitrina se recalcula a las 00:05 de Chile (antes del recalentado de fichas), fuera del horario de compra, y además en cada alta o retiro | `…/CatalogOrder/CatalogOrder.php:44-45, :174-188` | No | — |
| El orden del catálogo lo gestiona la máquina: el arrastre manual del escritorio queda pisado | `…/CatalogOrder/CatalogOrder.php:5-7, :356-358` | No | — |
| El relleno de recomendaciones solo llena huecos: nunca pisa una lista escrita a mano | `…/UpsellsAutofill/UpsellsAutofill.php:51-57, :114-116` | No | — |
| Los manifiestos para asistentes de IA no llevan precios ni disponibilidad, porque el fichero puede vivir 24 h | `…/LlmsManifest/LlmsManifest.php:20-23` | No | — |
| Los puntos de cumpleaños se pagan una vez al año, con doble red (meta del año + clave de evento única) | `plugins/akibara-rewards/src/Points/Birthday.php:11-13, :237-250` | No | — |
| Un cumpleaños del 29 de febrero se paga el 1 de marzo en años no bisiestos | `…/Points/Birthday.php:177-180` | No | — |
| El cron de cumpleaños paga a los de hoy **y** a los de ayer: una corrida perdida no deja a nadie sin regalo | `…/Points/Birthday.php:169-175` | No | — |
| Del cumpleaños se guardan solo día y mes; el año se descarta a propósito | `…/Points/Birthday.php:7-8, :152` | No | — |
| Con 0 meses de vencimiento los puntos no vencen jamás, y el módulo ni se registra | `…/Points/Expiry.php:15, :66-69` | Sí | Panel de Akibara Puntos |
| Lo que vence es lo ganado hace más de N meses que nunca se usó: los débitos consumen primero lo más viejo | `…/Points/Expiry.php:6-11, :140-163` | No | — |
| Los movimientos migrados envejecen desde la fecha del traspaso; sin esa fecha quedan protegidos | `…/Points/Expiry.php:131-138` | No | La opción `akb_rewards_migration_epoch`, **sin pantalla** |
| El aviso de puntos por vencer sale una vez al mes como máximo, y el sello lo pone el correo solo con transporte confirmado | `…/Points/Expiry.php:356-363`; `Emails.php:289-297` | No | — |
| La invitación a reseñar sale 3 días después del estado configurado, con enlace de un solo uso que vence a los 30 días | `…/Reviews/Invitations.php:69-80, :100-147` | Sí | Panel de Akibara Puntos |
| Un pedido con preventa no recibe invitación hasta su finalización válida; sin `akibara-business` se falla hacia invitar | `…/Reviews/Invitations.php:128-134` | No | — |
| La invitación a reseñar se reintenta como máximo 3 veces, cada 6 horas | `…/Reviews/Invitations.php:43, :202-204` | No | — |
| El texto de la invitación a reseñar está fijo en el código: no hay formulario que lo edite | `…/Reviews/Invitations.php:245-280` | No | — |
| La alarma por buscador caído sale a los 900 segundos y solo una vez por episodio, con su correo de vuelta | `plugins/akibara-search/src/RebuildJob.php:737-741, :756-797` | No | — |
| Las alarmas por correo del buscador no salen fuera de producción | `plugins/akibara-search/src/RebuildJob.php:823-827` | No | — |
| El vigilante del buscador corre cada 900 segundos, no cada 300: era el 36 % de toda la cola | `plugins/akibara-search/src/RebuildJob.php:64-78` | No | — |
| Los manejadores del buscador se registran **siempre**, con las banderas apagadas, para que los trabajos pendientes puedan drenar | `plugins/akibara-search/src/Search.php:105-109` | No | — |
| La frontera de una campaña editorial es la medianoche del día **siguiente** al de fin, calculada por fecha | `plugins/akibara-search/src/EditorialDiscountPublication.php:2153-2172`; `…/TaxonomyDiscount/AdoptedSnapshot.php:527-549` | Sí | Akibara → Editoriales · Ediciones |
| Action Scheduler conserva las acciones completadas y canceladas 14 días, no los 31 de fábrica | `mu-plugins/akibara-as-retention.php:13-18` | No | — |
| Los registros del plugin de Blue Express se conservan 2 días, en lotes de 1.000 filas | `mu-plugins/akibara-bluex-log-retention.php:15-16` | No | — |
| La alarma de ficheros rotos de Blue Express se **repite** mientras dure la rotura; la buena noticia sale una vez | `mu-plugins/akibara-bluex-integrity-watch.php:71-77` | No | — |
| El adaptador de acciones antiguas de Blue Express solo se registra si el plugin de envíos está disponible | `…/Integrations/Bluex/OrderPreventaAdapter.php:41-53, :183-185` | No | — |
| Ningún plugin propio salvo el buscador retira sus trabajos de Action Scheduler al desactivar o desinstalar | `plugins/akibara-business/uninstall.php:40-47`; `plugins/akibara-rewards/uninstall.php:18-28`; `plugins/akibara-search/uninstall.php:61-68` | No | — |

---

## Datos que deja

Todo lo que este mecanismo escribe fuera del código. Los transitorios son datos de caché que caducan
solos; las metas cuelgan de un pedido, un producto o un usuario.

| Clave | Tipo | Quién la escribe | Para qué sirve | ¿Pantalla? |
| --- | --- | --- | --- | --- |
| `cron` | option | WordPress | La lista de recados: todos los eventos de WP-Cron de este capítulo | Solo la lee la tarjeta de salud |
| `_akb_preventa_vencida_swept` | postmeta | `PreventaVencidaCron` | Testigo de ficha ya refrescada tras vencer su lanzamiento | No |
| `_akb_eta_ready_to_ship` | ordermeta | `ReadyToShipCron` | `yes`/`no`; inmutable hacia atrás | Indirecta, en el pedido |
| `_akb_eta_ready_date` | ordermeta | `ReadyToShipCron` | Instante en que se marcó listo | Indirecta |
| `_akb_eta_pending_count` | ordermeta | `ReadyToShipCron` | Cuántas líneas siguen sin llegar | Indirecta |
| `_akb_eta_stale_noted` | ordermeta | `ReadyToShipCron` | `promesa` o `tope`: por qué se dejó la nota de revisión | No |
| `_akb_eta_shipped_stale_noted` | ordermeta | `ReadyToShipCron` | `yes` cuando ya se avisó del pedido listo sin despachar | No |
| `_akb_eta_t7_reminder_sent` | ordermeta | `ReminderEmail` | Sello único de por vida del recordatorio | No |
| `_akb_eta_t7_reminder_attempts` | ordermeta | `SendFailureRearm` | Intentos, para el re-armado tras fallo de transporte | No |
| `_akb_eta_ready_email_sent` | ordermeta | `ReadyToShipEmail` | Idempotencia del correo «ya llegó» | No |
| `_akb_eta_ready_email_attempts` | ordermeta | `SendFailureRearm` | Intentos (tope 3) | No |
| `_akb_eta_ready_email_too_old_noted` | ordermeta | `ReadyEmailDispatcher` | `yes` cuando ya se dejó la nota de «no enviado por antigüedad» | No |
| `_akb_eta_date_change_sigs` | ordermeta | `ReleaseDateChangeListener` | Firmas `vieja__nueva` ya procesadas | No |
| `_akb_preventa_datechg_lock` | option | `ReleaseDateChangeListener` | Candado global (`INSERT IGNORE`), caduca a 15 min | No |
| `_akb_eta_carrier_handoff_alerted` | ordermeta | `CarrierHandoffMonitor` | `yes` tras avisar una vez | No |
| `_akb_eta_bluex_handoff_alerted` | ordermeta | `OrderPreventaAdapter` | Sello equivalente, de la época anterior | No |
| `{prefijo}akb_bis_subscribers` | tabla | `BackInStock` | Inscripciones a la lista de espera, con estado e intentos | Sí: Akibara → Lista de espera |
| `akb_bis_dispatch_enabled` | option | Panel | Interruptor del despacho de avisos | Sí |
| `{prefijo}akb_sf_queue` | tabla | `SeriesFollow` | Cola de avisos con clave única por evento | Sí: Akibara → Avisos de tomo nuevo |
| `akb_sf_enabled` · `_weekly_cap_days` · `_digest_delay_hours` · `_max_series_per_email` | option | Panel | Interruptor y topes del carril de series | Sí |
| `akb_catalog_order_last_run` | option | `CatalogOrder` | `{time, rows, applied}` de la última corrida | Sí: Akibara → Vitrina del catálogo |
| `wp_posts.menu_order` | tabla | `CatalogOrder` | El orden de la vitrina | Indirecta |
| `akb_upsells_autofill_last_run` · `_last_repair` | option | `UpsellsAutofill` | Resultado de la última corrida | Sí: Akibara → Upsells |
| `_upsell_ids` · `_akb_upsells_autofill_sig` | postmeta | `UpsellsAutofill` | Recomendaciones y firma del bot | Indirecta |
| `akb_upsells_coverage_v2` · `akb_upsells_health_v2` | transient | `UpsellsAutofill` | Caché de la pantalla, 5 min | Indirecta |
| `akibara_llms_identity` | option | Panel | Descripción de la tienda para los manifiestos | Sí: Akibara → Salud SEO |
| `akibara_business_llms_manifest_last_success` · `_last_error` | option | `LlmsManifest` | Resultado de la última generación | Sí |
| `akibara_business_llms_manifest_lock` · `_managed_files` | option | `LlmsManifest` | Permiso de 30 min y hashes de lo que puede retirar | **No** |
| `public_html/llms.txt` · `llms-full.txt` | fichero | `LlmsManifest` | Los manifiestos servidos antes de WordPress | Indirecta |
| `akb_social_derivatives_lock` · `_cursor` | option | `SocialImageDerivatives` | Candado de 15 min y cursor del rellenado | **No** |
| `uploads/portadas/{slug}-og.jpg` | fichero | `SocialImageDerivatives` | La hermana JPG de cada portada | Indirecta |
| `akb_og_fail_{slug}` | transient | `SocialImageDerivatives` | Caché negativa de 6 h por portada corrupta | No |
| `akb_byp_owner_fence_{pedido}_{hash}` | option | `CustomerCollection` | Valla temporal al vincular un pedido de invitado; se borra sola | No |
| tabla de trabajo del buscador | tabla | `Search\Indexer` | La deuda durable: qué reindexar y desde cuándo | Indirecta |
| tabla de documentos del buscador | tabla | `Search\Indexer` | Lo que sirve el autocompletado y las facetas | Indirecta |
| tablas sombra `_new` | tabla | `Search\RebuildJob` | Donde se construye el índice nuevo antes del cambiazo | Indirecta |
| `akb_search_rebuild_state` | option | `Search\RebuildJob` | Estado y fase de la reconstrucción | Solo lectura en Akibara → Buscador |
| `akb_search_serving_alarm` | option | `Search\RebuildJob` | `{down_since, notified_at}` — **decide si sale el correo** | **No** |
| `akb_search_rebuild_history` | option | `Search\RebuildJob` | Últimas 10 corridas | Sí |
| `akb_search_watchdog_guard_v2` | transient | `Search\RebuildJob` | Memoria de 12 h de «el vigilante ya está agendado» | No |
| `akb_search_reconcile_last` · `_last_error` | option | `Search\Reconciler` | Resumen y último fallo de la auditoría | Sí |
| `akb_search_reconcile_alert` · `akb_search_reconciler_scheduled` | transient | `Search\Reconciler` | Alerta del panel y memoria de 12 h | Parcial |
| registro de búsquedas | tabla | `Search\Reconciler` | Consultas de los clientes; podado a 180 días / 50.000 filas | Sí |
| `akibara_taxonomy_discount_enabled` · `_from` · `_to` | option | Panel (business) | Fechas de la campaña editorial que fijan la frontera | Sí |
| libro mayor de puntos | tabla | `Rewards` | La verdad contable; el vencimiento es un débito más | Sí |
| `_akb_rewards_expiry_warned` | usermeta | `Emails` | Mes del último aviso; **lo sella el correo**, no el cron | No |
| `akb_rewards_expiry_scheduled` · `akb_rewards_birthday_scheduled` | transient | `Expiry` · `Birthday` | Memoria de 12 h del agendado | No |
| `_akb_rewards_birthday_md` · `_paid_year` · `_email_year` | usermeta | `Birthday` | Día y mes, año pagado, año saludado | Parcial |
| `akb_rewards_migration_epoch` | option | — | Fecha del traspaso desde el sistema anterior | **No** |
| `{prefijo}akb_rewards_review_forms` | tabla | `Invitations` | Tokens de invitación con caducidad y un solo uso | No |
| `_akb_rewards_invite_scheduled` · `_sent` · `_attempts` | ordermeta | `Invitations` | Trazas de agenda, envío e intentos | No |
| `{prefijo}bluex_logs` | tabla (de tercero) | Blue Express | Registro de envíos; el mu-plugin lo poda a 2 días | No |
| `akb_bluex_watch_baseline_seen` · `akb_bluex_watch_resolved_notified` | option | Vigía de integridad | **Deciden si sale el correo** de rotura o de resolución | **No** |
| `akibara_mail_transport_watch` | option | Centinela del correo | `{missing_since, last_seen_gmt, outcome, restores[]}` | Solo en los avisos del escritorio |
| `wp_actionscheduler_actions` · `_logs` | tabla | Action Scheduler | Toda la cola; retención propia de 14 días | Sí: WooCommerce → Estado → Acciones programadas |

> **Contra la regla de oro «cero opciones invisibles».** Cinco de estas gobiernan si sale o no un
> correo y no tienen ninguna pantalla: `akb_search_serving_alarm`, `akb_bluex_watch_baseline_seen`,
> `akb_bluex_watch_resolved_notified`, y de rebote las dos del candado y el cursor de portadas. Si el
> vigía de Blue se queda con la resolución marcada por una carrera, el dueño no vuelve a recibir el
> correo de buena noticia y **no tiene forma de verlo ni de reiniciarlo sin acceso al servidor**. El
> defecto sigue en pie —debería haber pantalla—, pero mientras tanto **sí hay una manera de mirarlas y
> reiniciarlas**, con su forma y su significado: está en «Cómo se mira…», apartado 5. En corto:
>
> - `akb_search_serving_alarm` guarda `{down_since, notified_at}`. `down_since` es cuándo se vio caído
>   el buscador por primera vez; `notified_at` marca que la alarma ya salió, para no repetirla.
>   Borrarla entera la **rearma**: `wp option delete akb_search_serving_alarm`.
> - `akb_bluex_watch_baseline_seen` dice que ya se tomó la foto de referencia del paquete del
>   transportista (sin ella no hay con qué comparar).
> - `akb_bluex_watch_resolved_notified` dice que ya se mandó el correo de «arreglado».
>   `wp option delete akb_bluex_watch_resolved_notified` permite que vuelva a salir.

---

## Glosario del capítulo

- **Action Scheduler.** La cola de trabajos que trae WooCommerce. Guarda cada trabajo en tablas propias
  con estado, argumentos y grupo. Es donde va todo lo que no puede perderse.
- **Acción.** Una unidad de trabajo dentro de Action Scheduler: un aviso más sus argumentos más su
  hora.
- **Argumentos.** Los datos que viajan con un trabajo (por ejemplo `{order_id: 4821}`). Son lo que
  distingue dos trabajos del mismo tipo.
- **Asíncrono.** Un trabajo que se pide «para lo antes posible», sin hora fija. En Action Scheduler se
  encola con `as_enqueue_async_action`.
- **Barrido.** Una pasada que recorre un conjunto (pedidos, productos, filas) aplicando una regla.
- **Bomba (*pump*).** El trabajo que **vacía** la deuda del buscador. Es bomba de achique, la de un
  barco: saca agua. El aviso se llama `akb_search_pump_live`. No hay nada que explote.
- **Candado (*lock*).** Una marca que un trabajo crea para que otra copia del mismo trabajo no corra a
  la vez. Caduca sola si el trabajo muere a medias.
- **Cadena.** Una secuencia de trabajos de una sola vez en la que cada corrida agenda la siguiente
  mientras quede trabajo. Si una corrida no agenda la siguiente, la cadena se **congela**.
- **Cambiazo (*swap*).** El momento en que las tablas sombra del buscador pasan a ser las vivas, por
  cambio de nombre y de golpe. Al conjunto de tablas vivas y sombra se le llama **topología**.
- **Coalescer.** Juntar varias peticiones idénticas en una sola corrida, para no repetir trabajo.
- **Cron.** Nombre genérico de un trabajo programado. También, la tarea del servidor que lo empuja.
- **Debounce (espera de agrupación).** Retrasar un trabajo unos segundos para que una ráfaga de
  cambios produzca una sola corrida.
- **Deduplicar.** No encolar un trabajo si ya hay otro igual esperando.
- **Deuda (del buscador).** Una anotación durable de «este producto hay que reindexarlo», guardada en
  tabla para que sobreviva a un reinicio.
- **`DISABLE_WP_CRON`.** Ajuste de WordPress que apaga el empuje por tráfico. En akibara.cl está
  encendido, y quien empuja es la tarea de Hostinger cada 5 minutos.
- **Evento recurrente.** Un trabajo que se repite con un intervalo fijo (diario, semanal, cada 900 s).
- **Facetas.** Los filtros que el cliente ve junto a los resultados de búsqueda —editorial, serie,
  precio, disponibilidad—, calculados del mismo índice.
- **Foto de precios (el «recibo»).** La copia sellada de qué campaña de descuento por editorial estaba
  vigente cuando se construyó el índice, con su **secuencia** (número de orden, para saber cuál es más
  nueva) y su **huella** (resumen del contenido, para saber si cambió). Si no calza con las tablas
  vivas, el buscador se apaga antes que servir precios falsos.
- **Generación.** El número de versión del índice vivo. Impide que un trabajo encolado antes del
  cambiazo escriba sobre el índice nuevo; «publicación de generación» es el aviso de que ese número
  subió.
- **Grupo.** Etiqueta que agrupa acciones de Action Scheduler. Permite cancelar todo un carril de una
  vez. Los trabajos de puntos **no tienen grupo**.
- **Hook (aviso o gancho).** Un nombre al que cualquier trozo de código puede engancharse para que lo
  llamen. La pieza básica de WordPress.
- **Idempotencia.** La propiedad de que correr un trabajo dos veces con el mismo estado de partida no
  cause daño. Es la pregunta que hay que hacerle a cada fila de la tabla «Lo programado».
- **Índice (del buscador).** Una copia del catálogo escrita en tablas aparte y preparada para buscar
  rápido. El autocompletado y las facetas leen de ahí, nunca de los productos.
- **Inmutable hacia atrás.** Que un valor, una vez puesto, ya no retrocede: cuando
  `_akb_eta_ready_to_ship` vale `yes`, ningún proceso lo devuelve a `no`, ni aunque el tomo se vuelva
  a agotar.
- **Manejador (*handler*).** El trozo de código que se ejecuta cuando suena un aviso.
- **Meta.** Un dato suelto colgado de un pedido (*ordermeta*), un producto (*postmeta*) o un usuario
  (*usermeta*).
- **Option.** Una fila de configuración o de estado en la tabla `wp_options` de WordPress. **No** es
  lo mismo que una fila de una tabla propia, y el capítulo las distingue siempre.
- **Permiso (*lease*).** Una reserva con caducidad sobre un trozo de trabajo: «este lote es mío
  durante 120 segundos» (`RebuildJob.php:61`). Si el trabajador muere a medias, el permiso vence solo
  y otro puede tomar el lote. Es distinto de un **candado** (que impide que dos copias corran a la
  vez, como los 15 minutos de `_akb_preventa_datechg_lock`) y distinto de un **reclamo atómico** (que
  marca una fila como propia en la misma operación en que la lee, como `mark_notified()`). Los tres
  aparecen en este capítulo y no son intercambiables.
- **Prioridad.** Número que decide el orden en que se ejecutan varios manejadores del mismo aviso. Más
  bajo, antes. Por eso la lista de espera va en 11 y el listener de preventa en 10.
- **Reclamo atómico (*claim*).** Tomar una fila de trabajo marcándola como propia en la misma
  operación, para que dos trabajadores no la procesen a la vez. Es la garantía real de «un correo por
  cliente».
- **Trabajador (*worker*).** El proceso que ejecuta una acción de la cola.
- **Tablas sombra (`_new`).** Las tablas donde se construye el índice nuevo mientras el viejo sigue
  sirviendo, hasta el cambiazo.
- **Transitorio (*transient*).** Un dato de caché con fecha de caducidad. Si desaparece, el sistema
  sigue funcionando; solo pierde una optimización. **Salvo cuando se usa como memoria de «esto ya está
  agendado»**: entonces, si la acción desaparece y el transitorio no, el carril queda muerto hasta que
  el transitorio caduque. Ver **ventana ciega**.
- **Valla de propietario (*owner fence*).** La nota temporal que se deja cuando un pedido cambia de
  dueño, para seguir tirando durante unos días la caché de «qué tomos tiene comprados» del dueño viejo
  y del nuevo (§16).
- **Ventana ciega.** El rato durante el cual un carril no se repara solo porque su memoria de «ya está
  agendado» sigue viva aunque el trabajo ya no exista. En los dos carriles de puntos dura **12 horas**
  y no hay nada que la corrija (§21, §22).
- **Vigilante (*watchdog*).** Un trabajo recurrente cuyo único propósito es comprobar que otro no se
  haya quedado colgado, y repararlo.
- **WP-Cron.** La lista de recados de WordPress, guardada en la opción `cron`. Barata y frágil: si la
  petición que la ejecuta muere, el trabajo se pierde.
