# mu-plugins (A) · abilities/MCP, Blue Express, Brevo, Clarity

> Leído contra el snapshot congelado de producción: `akibara-business` 1.116.4, `akibara-search` 0.1.60,
> `akibara-cart-checkout-blocks` 2.2.17, `akibara-rewards` 0.3.13, tema `akibara-gp` 2.1.118 y los
> mu-plugins de producción. Los plugins de terceros se citan desde el árbol del repositorio, sólo para
> comprobar nombres de ganchos y líneas. Cada afirmación técnica lleva su fichero y su línea.
>
> **Retirado el 2026-09-13: el barrido de disponibilidad.** `akibara-catalogo-abilities.php` publicaba la ability
> `akibara/disponibilidad-set`, que cruzaba el listado de una editorial con el catálogo. Fue de una sola vez y el dueño
> lo retiró: se borraron el mu-plugin (local y producción), sus pruebas `tools/test-dispo-*.php`, `ops/barrido-disponibilidad.php`,
> el prompt de su tarea en la nube y, en producción, las metas `_akb_sweep_incentivo` y `_akb_sweep_prev_stock_status`.
>
> **Cómo leer las rutas.** Salvo que se diga otra cosa, **todas las rutas son relativas a
> `public_html/wp-content/`**: `mu-plugins/akibara-x.php` vive en
> `public_html/wp-content/mu-plugins/akibara-x.php`, y `plugins/woocommerce/…` en
> `public_html/wp-content/plugins/woocommerce/…`. Las que empiezan por `tools/` o por `ops/` son
> relativas a la raíz del repositorio.
>
> **Cómo leer `gancho:prioridad`.** `plugins_loaded:20` se lee «enganchado en el gancho
> `plugins_loaded` con prioridad 20». La prioridad decide el orden entre las funciones enganchadas al
> mismo punto: el número menor corre primero y, **a igualdad de número, corre primero quien se enganchó
> antes**. Ese empate decide un defecto entero de este capítulo (§5).
>
> **Cómo ver los diagramas.** Cada diagrama se publica **dos veces**: el `.svg` ya renderizado —que se
> abre con doble clic en cualquier navegador— y el `.mmd` de al lado, que es la fuente editable en
> formato Mermaid. Si se toca un `.mmd` hay que volver a generar su `.svg`:
> `mmdc -i diagramas/<fichero>.mmd -o diagramas/<fichero>.svg -b transparent`.

---

## Para qué existe (en dos párrafos que entienda un novato)

Akibara es una tienda construida sobre **WordPress** (el programa que sirve las páginas) y
**WooCommerce** (el añadido que le pone carrito, pedidos y envíos). Encima de esos dos viven programas
que compramos o instalamos hechos, que aquí llamamos **terceros**: Blue Express para el despacho,
Brevo para los correos de marketing, Microsoft Clarity para ver cómo navega la gente, Rank Math para
el posicionamiento en buscadores. Nada de eso lo escribimos nosotros, y la regla de la casa prohíbe
editarlo: si tocáramos su código, la siguiente actualización del tercero se llevaría el arreglo por
delante y nadie se enteraría hasta que algo se rompiera. Para cambiar la conducta de un tercero sin
editarlo, WordPress ofrece los **ganchos**: puntos concretos del programa donde uno deja apuntada una
función propia para que se ejecute justo ahí. Hay dos clases: las **acciones**, que sólo avisan de que
algo pasó («se añadió un producto al carrito»), y los **filtros**, que dejan pasar un valor y permiten
devolverlo cambiado («ésta es la dirección de la imagen»).

Este capítulo cubre **diez ficheros PHP** que no hacen otra cosa que eso: engancharse en un punto
preciso y corregir una sola conducta. Viven en una carpeta especial llamada **mu-plugins** (de *must
use*, «de uso obligatorio»): WordPress los carga siempre, antes que los plugins normales, y **no se
pueden desactivar desde el panel**. Es el lugar correcto para un parche que debe estar puesto antes de
que el tercero arranque, y el lugar peligroso para cualquier otra cosa, porque el dueño no los ve en
ninguna pantalla. Los diez cuentan cinco historias: **Blue Express** (que no grite cuando no puede
cotizar, que avise si su paquete llega roto, que no acumule registros, que el número de seguimiento
del cliente no se pierda, y que su correo le llegue al cliente **en español**), **Brevo** (que la
portada del manga llegue al correo en un formato que Gmail sepa pintar), **Clarity** (que las pruebas
de calidad no ensucien las métricas reales), **la cola de tareas de fondo** (que no guarde un mes de
historial cuando bastan dos semanas) y **las abilities** (un veto sobre tres operaciones peligrosas
—dos de Rank Math y la de borrar productos de WooCommerce— y un rastro de quién ejecuta qué).

Una palabra que aparece todo el rato: **ability**. Es la forma nueva que tiene WordPress de decir «aquí
hay una operación que un asistente automático puede ejecutar». Tiene nombre, descripción, un esquema de
qué datos acepta, una función que comprueba permisos y otra que hace el trabajo. Un adaptador llamado
**MCP** publica esas operaciones por internet para que un agente de inteligencia artificial las use.
Para entrar por esa puerta hace falta una **contraseña de aplicación** de un usuario administrador: una
clave larga que WordPress genera aparte de la contraseña normal, sirve sólo para la API y se puede
retirar sin cambiar la contraseña de la persona. Se crea con
`wp user application-password create <usuario> "<etiqueta>" --porcelain` y se revoca en Perfil →
Contraseñas de aplicación. En este área está la cara del veto; lo que la casa publica por esa misma
puerta se cuenta en el capítulo de mu-plugins (B).

---

## Cómo encaja con el resto

![Piezas del área y con quién habla cada una](diagramas/14-mu-plugins-abilities-bluex-brevo-clarity-piezas.mmd)

La idea que une a los diez ficheros es la **regla de oro de la casa: el pegamento muere con el
tercero**. Cada uno comprueba primero que el tercero exista —que su clase esté cargada, que su carpeta
esté ahí, que su función exista— y, si no está, no engancha nada y no hace daño. Eso se ve literal en
`akibara-bluex-cotiza-solo-con-comuna.php:73` (`class_exists( 'WC_Correios_Webservice' )`) y en
`akibara-brevo-portada-jpg-en-el-carrito.php:128-133` (dos `class_exists`, uno del tercero y otro
nuestro).

**Con quién habla cada dominio:**

| Dominio | Habla con | Qué pasa si el mu-plugin se apaga |
| --- | --- | --- |
| Blue Express, cotización | El plugin `bluex-for-woocommerce` y el motor de envíos de WooCommerce | Vuelven las líneas de error del hosting —113 en un día con 4 pedidos— y el día del fallo real nadie lo distingue. El cliente no nota nada, ni antes ni después |
| Blue Express, vigía | El árbol de ficheros del plugin del tercero y el correo del dueño | Se pierde el aviso temprano. El 2026-08-30 una actualización con dos ficheros ausentes dejó el checkout caído casi un día |
| Blue Express, registros | La tabla `wp_bluex_logs` y Action Scheduler | Los registros vuelven a los 7 días del tercero y se borran con un `DELETE` sin lotes |
| Blue Express, seguimiento | La API autenticada `wc/v3` de WooCommerce y la función `wc_correios_updateTrackingCode` del tercero | El código de seguimiento no se guarda y **el cliente no recibe su aviso de despacho** por esta vía |
| Brevo | El plugin de Brevo y el módulo `SocialImageDerivatives` de `akibara-business` | El correo de carrito abandonado vuelve a llegar con un hueco donde va la portada: Gmail no pinta AVIF y las portadas de la tienda sólo existen en ese formato (§5) |
| Clarity | El plugin `microsoft-clarity` | Las sesiones de pruebas del espejo vuelven a mezclarse con las del cliente en las métricas reales |
| Cola de fondo | Action Scheduler (que viene dentro de WooCommerce) | El historial vuelve a 31 días; medido el 2026-08-31, eran 7.721 acciones de más y unos 10,7 MB |
| Abilities, veto | El registro de abilities de Rank Math y el adaptador MCP | Un agente con permisos de administración podría encender las auto-actualizaciones de Rank Math o apagar el mapa del sitio |

Una arista que sale de este capítulo hacia otro y conviene tener presente:

- `akibara-rankmath-redirections-abilities.php` **escribe metas de un tercero**: `rank_math_focus_keyword`
  sobre el producto (`:242`, `:257`). No es asunto de este capítulo —se cuenta en el de mu-plugins (B)—,
  pero conviene saberlo, porque este capítulo se apoya en ese fichero para el contexto del veto.

---

## Piezas

### 1. `akibara-bluex-cotiza-solo-con-comuna.php` — que Blue no grite antes de tiempo

**Qué hace en una frase.** Le dice a WooCommerce que los métodos de Blue Express que cotizan contra la
API del transportista no están disponibles mientras el cliente no haya escrito su región y su comuna.

**Ficheros.** `mu-plugins/akibara-bluex-cotiza-solo-con-comuna.php` (82 líneas).
Prueba automatizada: `plugins/akibara-business/tests/MuPlugins/BluexCotizaSoloConComunaTest.php`.

**En qué momento actúa.** Se registra en la acción `plugins_loaded` a **prioridad 20** (`:82`), porque
el plugin de Blue incluye sus clases desde `WC_Correios::init()`, colgado del mismo `plugins_loaded` a
prioridad 10 (`plugins/bluex-for-woocommerce/woocommerce-bluex.php:42`). Una vez cargado, engancha el
filtro `woocommerce_shipping_<id>_is_available` a prioridad 10 para tres identificadores de método:
`bluex-ex`, `bluex-py` y `bluex-md` (`:41-43`, `:77-79`). Ese filtro lo ofrece el propio WooCommerce en
`plugins/woocommerce/includes/abstracts/abstract-wc-shipping-method.php:456`, y WooCommerce pregunta
por él **antes** de pedirle tarifa a cada método.

**Qué datos lee y escribe.** Lee `destination['state']` y `destination['city']` del paquete del carrito
(`:60-61`). **No escribe nada.** La decisión es: `return '' !== $region && '' !== $comuna;` (`:63`). Si
WooCommerce ya había dicho que no, se respeta ese no sin más (`:53-55`).

**Dónde lo opera el dueño.** En ningún sitio: no tiene ajuste. Lo único configurable por el dueño es,
indirectamente, qué métodos de Blue tiene habilitados en WooCommerce → Ajustes → Envío.

**Qué ve el cliente.** Exactamente lo mismo que antes. Sin comuna nunca hubo tarifa de Blue: el tercero
la descartaba igual. `Erro -888` es el código con el que el propio plugin de Blue marca internamente
«no pude cotizar» cuando le falta el destino
(`plugins/bluex-for-woocommerce/includes/class-wc-correios-webservice.php:533`, comentado en `:726`);
cada uno de esos intentos dejaba una línea en el registro de errores del hosting. Lo único que cambia
es que ese registro deja de llenarse.

**Lo que conviene saber.** `bluex-pudo` queda **fuera a propósito**: ofrece tarifa fija sin dirección y
sólo cotiza cuando ya hay punto de retiro elegido. Está verificado en el tercero, donde la recuperación
de geografía desde la sesión de la agencia está condicionada a `$this->id === 'bluex-pudo'`
(`plugins/bluex-for-woocommerce/includes/class-wc-correios-webservice.php:518` y `:573`).

---

### 2. `akibara-bluex-integrity-watch.php` — el vigía que avisa antes que el cliente

**Qué hace en una frase.** Después de cada actualización de plugins, y una vez por semana de respaldo,
lee todos los ficheros `.php` del plugin de Blue Express buscando cargas (`include` / `require`) de
ficheros que no existen, y le manda un correo al dueño en el momento.

**Ficheros.** `mu-plugins/akibara-bluex-integrity-watch.php` (150 líneas). Prueba de calidad que lo
ejercita de verdad: `tools/akibara-releases/rewards-qa/gate-bordes/01-vigia-bluex.mjs`, que siembra una
carga rota, mide los dos correos en Mailpit y restaura las options.

**En qué momento actúa.** Dos disparos:

1. `upgrader_process_complete`, prioridad 10, dos argumentos (`:119-129`). **Importante: el gatillo no
   distingue de qué plugin se trata.** La guarda mira `'plugin' === ( $extra['type'] ?? '' )` (`:123`),
   no el nombre del plugin actualizado, así que actualizar WooCommerce, Rank Math o LiteSpeed abre y
   lee entero el árbol de Blue Express aunque Blue no haya cambiado.
2. La acción recurrente semanal `akibara_bluex_integrity_weekly` (`:145-150`), que se arma desde `init`
   a prioridad **30** (`:132-144`).

**Qué datos lee y escribe.** Lee el árbol de `WP_PLUGIN_DIR . '/bluex-for-woocommerce'` (`:29`) con un
recorrido recursivo y una expresión regular sobre el texto crudo de cada fichero (`:43`). Escribe dos
options sin autocarga: `akb_bluex_watch_baseline_seen` (`:89`) y `akb_bluex_watch_resolved_notified`
(`:109`), que son las que deciden qué correo sale. Escribe también una línea en el registro del hosting
con `error_log()` (`:88`) y manda correo a `get_option( 'admin_email' )` (`:84`).

**Dónde lo opera el dueño.** En ningún sitio. Las dos options no aparecen en ninguna pantalla y sólo se
escriben desde código; el propio gate de calidad tiene que manipularlas y restaurarlas a mano. Para
mirarlas o volver a dejar el vigía «virgen» hay que ir por línea de comandos:

```bash
wp option get akb_bluex_watch_baseline_seen        # 'yes' = alguna vez hubo rotura
wp option get akb_bluex_watch_resolved_notified    # 'yes' = la buena noticia ya salió
wp option delete akb_bluex_watch_baseline_seen     # vuelve al estado «nunca hubo roturas»
wp option delete akb_bluex_watch_resolved_notified # permite que la buena noticia salga de nuevo
```

Esas cuatro líneas son exactamente lo que hace y deshace el gate de calidad para poder probar el ciclo
de alarma entero.

**Qué ve el cliente.** Nada. Es un aviso interno. Lo que el cliente se ahorra, si el aviso llega a
tiempo, es un checkout caído.

**Los dos correos:**

| Asunto | Cuándo sale |
| --- | --- |
| `[Akibara] ⚠ Blue Express embarcó includes rotos — checkout en riesgo` | Inmediato, en **cada** chequeo mientras dure la rotura. La repetición es deliberada desde el 2026-09-01: es un incendio sin apagar, no fatiga (`:71-77`) |
| `[Akibara] ✅ Blue Express arregló su paquete` | Una sola vez, el primer chequeo que ya no encuentra roturas (`:106-115`) |

El cuerpo de la alarma lleva escritos los pasos del **rearme exprés**: bajar las clases ausentes del
repositorio público de Blue a `mu-plugins/bluex-fallback/` y recrear el guard de carga condicional en
`plugins_loaded:99` (`:96-99`).

**Lo que conviene saber.**

- La acción semanal se programa **sin grupo y sin `unique`** (`:138-140`). Su mu-plugin hermano, del
  mismo autor, sí usa grupo propio y `unique = true` (`akibara-bluex-log-retention.php:112-119`). Sin
  grupo, la acción tampoco se puede localizar ni cancelar desde WooCommerce → Estado → Acciones
  programadas, que es donde el dueño la buscaría.
- La reprogramación exige `is_admin()` o `wp_doing_cron()` (`:135`), y conviene no leerlo como «no se
  programa nunca»: **la acción sí se arma**. `is_admin()` es verdadera en cualquier página de
  `/wp-admin/`, así que la primera vez que el dueño entra al escritorio la acción queda programada, y
  desde ahí Action Scheduler la reprograma sola cada vez que la ejecuta. Lo que falta es la **red de
  reparación**: en producción el cron lo empuja el hosting por WP-CLI, donde `is_admin()` y
  `wp_doing_cron()` son **ambas falsas**, así que si alguien borra la acción, sólo una visita al
  escritorio la vuelve a crear. El fichero hermano documenta esa misma medición y por eso sí tiene la
  rama `defined( 'WP_CLI' ) && WP_CLI` (`akibara-bluex-log-retention.php:85-87`).
- Si la carpeta del plugin no existe, el escáner devuelve lista vacía (`:30-32`), que es exactamente lo
  mismo que devuelve un árbol sano. Plugin borrado + marca `baseline_seen` puesta = correo de «Blue
  arregló su paquete» mientras la tienda se quedó sin método de envío.
- **La buena noticia puede llegar sin que la mala haya llegado nunca.** La marca
  `akb_bluex_watch_baseline_seen = yes` se escribe en `:89`, **antes** del `wp_mail()` de `:93`, y nadie
  mira lo que ese `wp_mail()` devuelve. Si el correo no sale —que es exactamente lo que pasó cuando el
  transporte de correo del sitio desapareció durante tres días— el estado queda diciendo «hubo rotura»
  sin que nadie haya visto la alarma, y el siguiente chequeo sano manda el «✅ Blue Express arregló su
  paquete» como única señal de un incendio que el dueño nunca supo que existía.
- **El vigía no vigila el único fichero del tercero que nosotros cargamos por ruta.** Lo que recorre son
  los `include`/`require` que **Blue Express se hace a sí mismo** (`:28-69`). Pero el acoplamiento que
  nos puede tumbar el checkout es otro: el directorio de comunas del checkout hace `require_once` por
  ruta fija de `plugins/bluex-for-woocommerce/includes/data/chile-communes.php` y depende de la función
  `bluex_get_communes_by_region()` (`plugins/akibara-cart-checkout-blocks/src/ComunaDirectory.php:26` y
  `:377-393`). Si Blue deja de embarcar **ese** fichero, el vigía no dice ni una palabra; el aviso llega
  por la otra punta, con el selector de comuna degradado a campo de texto libre.
- **Desinstalar `akibara-business` borra las dos options.** Su `uninstall.php` barre
  `option_name LIKE 'akb\_%'` (`plugins/akibara-business/uninstall.php:95-104`) y su lista de
  exclusiones sólo nombra prefijos de plugins hermanos (`akb_search_`, `akb_rewards_`, `akb_blocks_`…),
  no `akb_bluex_`. Tras una desinstalación el vigía olvida que hubo rotura: si la rotura sigue ahí,
  vuelve a avisar; si ya se arregló, la buena noticia no sale nunca.

![Estados del vigía de integridad](diagramas/14-mu-plugins-abilities-bluex-brevo-clarity-estados-vigia.mmd)

---

### 3. `akibara-bluex-log-retention.php` — dos días de registros, no siete

**Qué hace en una frase.** Acorta a 2 días la vida de los registros que Blue Express guarda en su
propia tabla, y los borra de mil en mil en vez de todos de una vez.

**Ficheros.** `mu-plugins/akibara-bluex-log-retention.php` (124 líneas).

**En qué momento actúa.** Tres enganches (`:122-124`):

- `bluex_clean_logs` — el evento diario de WP-Cron del propio tercero, que él mismo programa en
  `plugins/bluex-for-woocommerce/includes/class-wc-correios.php:121-122`. O sea que nuestro borrado por
  lotes corre **dentro del mismo evento** que el `DELETE` sin lotes ni límite del tercero
  (`includes/logger/wc-logs-cron.php:5-12`). Detalle de fragilidad ajena: el tercero declara esa misma
  función **dos veces**, en `wc-logs-cron.php` y en `wc-logs-deactive-cron.php`.
- `akibara_bluex_log_retention_cleanup` — nuestra propia acción diaria de Action Scheduler.
- `action_scheduler_init` a prioridad 20, que es donde se comprueba si esa acción sigue programada.

**Qué datos lee y escribe.** Comprueba que exista la tabla `{$wpdb->prefix}bluex_logs` (`:28-38`) y
borra de ella en bucle: `DELETE ... WHERE log_timestamp < DATE_SUB(NOW(), INTERVAL 2 DAY) ORDER BY
log_timestamp LIMIT 1000` (`:42-51`). No escribe options.

**Dónde lo opera el dueño.** La retención en sí no es configurable (son constantes, `:13-16`). Pero hay
una palanca del tercero que decide si esa tabla se llena siquiera: el ajuste `active_logs` dentro de la
option `woocommerce_correios-integration_settings`. Con ese ajuste apagado, el tercero sólo escribe los
errores en el registro del hosting y esta limpieza no tiene nada que borrar
(`plugins/bluex-for-woocommerce/includes/logger/helper.php:13-25`). Es justo la palanca que hay que
mirar antes de concluir que la limpieza «funciona»: cero filas puede significar que limpió bien o que
nadie está escribiendo.

**Qué ve el cliente.** Nada.

**Lo que conviene saber.** Lo más valioso de este fichero es su comentario (`:64-84`), que documenta
con medición por qué sólo reprograma donde alguien podría repararlo. `action_scheduler_init` se dispara
en **todas** las peticiones, y la comprobación costaba 3 consultas en la portada anónima, en
`/carrito/`, en `/checkout/` y en la Store API. De ahí la guarda de tres ramas:

```php
$puede_reparar = wp_doing_cron()
    || ( defined( 'WP_CLI' ) && WP_CLI )
    || ( is_admin() && ! wp_doing_ajax() );   // líneas 85-87
```

Las tres están justificadas: `is_admin()` también es verdadera en `admin-ajax.php`, que es por donde se
hidrata el cajón del carrito; y en producción `DISABLE_WP_CRON` está activo, así que el disparo viene
de WP-CLI, donde `wp_doing_cron()` devuelve falso. Además prefiere `as_has_scheduled_action()` (1
consulta, 0,41 ms) sobre `as_next_scheduled_action()` (3 consultas, 1,82 ms), porque aquí sólo importa
**si** la acción existe, no cuándo corre (`:97-99` el argumento, `:100-110` el código). Ese es el
patrón que le falta al vigía de integridad.

Dos cosas que la etiqueta «inofensivo» de la tabla de trabajos programados no dice:

- **El bucle de borrado no tiene techo.** El `do { … } while` de `:41-51` sigue pidiendo lotes de 1.000
  hasta que uno devuelva menos de 1.000; no hay presupuesto de tiempo ni de iteraciones. Con la tabla
  muy crecida —justo el caso que este fichero existe para evitar— eso es una petición larga metida
  dentro del evento diario del tercero.
- **El mismo callback está enganchado a dos ganchos distintos y sin candado** (`:122-123`):
  `bluex_clean_logs`, que es del tercero, y `akibara_bluex_log_retention_cleanup`, que es nuestro. Nada
  impide que los dos coincidan y que el borrado se solape consigo mismo. No corrompe datos —es un
  `DELETE` por antigüedad—, pero duplica el trabajo sobre la misma tabla.

---

### 4. `akibara-bluex-rest-tracking.php` — que el número de seguimiento no se pierda

**Qué hace en una frase.** Sustituye la función con la que el plugin de Blue guarda el código de
seguimiento, porque la suya estaba escrita para la API vieja de WooCommerce y esperaba un objeto que ya
no llega.

**Ficheros.** `mu-plugins/akibara-bluex-rest-tracking.php` (84 líneas). Es el único fichero del área
escrito en inglés.

**En qué momento actúa.** Por **dos caminos**, y conviene ser exacto, porque no son alternativos:

1. `rest_api_init` a prioridad **101** (`:65`): reemplaza el `update_callback` del campo
   `correios_tracking_code` dentro de `$wp_rest_additional_fields` (`:58-64`). Blue lo registra a
   prioridad 100.
2. `woocommerce_rest_insert_shop_order_object` a prioridad **20**, tres argumentos (`:84`).

El comentario del fichero llama al segundo «fallback», pero en un `PUT` a `/wp-json/wc/v3/orders/<id>`
corren **los dos, en la misma petición**: WooCommerce llama primero a
`update_additional_fields_for_object()` y **después** dispara la acción
(`plugins/woocommerce/includes/rest-api/Controllers/Version3/class-wc-rest-crud-controller.php:259` y
`:268`). O sea que `akibara_bluex_store_tracking_codes()` se ejecuta dos veces por petición. No duplica
correos ni códigos porque la función del tercero es idempotente —sólo añade el código si no estaba,
`!in_array( $tracking_code, $tracking_codes, true )`
(`plugins/bluex-for-woocommerce/includes/wc-correios-functions.php:195`)—, pero sí repite el trabajo.

**Qué datos lee y escribe.** Lee el parámetro `correios_tracking_code` de la petición. Parte el valor
por comas con `preg_split( '/\s*,\s*/', $tracking_code )` (`:32`), limpia cada trozo y llama a
`wc_correios_updateTrackingCode()` una vez por código (`:35`). El que escribe es el tercero: guarda la
meta `_correios_tracking_code` del pedido, añade una nota al pedido y **dispara la notificación al
cliente** (`wc-correios-functions.php:199-211`).

**Dónde lo opera el dueño.** El correo que sale de aquí se configura en WooCommerce → Ajustes →
Correos, en los ajustes de la notificación de Blue (option `woocommerce_correios_tracking_settings`).

**Qué ve el cliente.** Su aviso de despacho, **en español**. El asunto que trae el tercero es
`[{site_title}] Your order {order_number} has been sent by BlueX`, y salía tal cual —una clienta
recibió el 2026-09-17 un «YOUR ORDER HAS BEEN SENT»— porque el plugin pide su dominio de traducción
desde un directorio `languages/` que no existe en el paquete instalado. **Desde el 2026-09-17 lo
traduce entero `akibara-bluex-en-espanol.php` (§10)**, que también manda el número de seguimiento a la
página real de Blue; si el dueño escribe asunto y cuerpo a mano en los ajustes, su texto manda sobre
esa traducción (corregido 2026-09-20, ronda 10: aquí se decía que el correo sale en inglés). La carga
del dominio ausente está en
`plugins/bluex-for-woocommerce/includes/class-wc-correios.php:131`
(`load_plugin_textdomain( 'woocommerce-correios', false, … . '/languages/' )`), enganchada en `init` a
prioridad **-1** (`:24`), y la raíz del plugin sólo trae `LICENSE.txt`, `assets`, `debug-config.php`,
`includes`, `readme.txt`, `templates`, `uninstall.php` y `woocommerce-bluex.php`: no hay `languages/`
en ninguna parte. Como nuestro mu-plugin es el que hace que ese correo vuelva a salir, el texto es
responsabilidad práctica de la casa.

**Lo que conviene saber.** Si Blue manda dos códigos separados por coma, el cliente recibe **dos
correos** casi idénticos, uno por código nuevo.

---

### 5. `akibara-brevo-portada-jpg-en-el-carrito.php` — la portada que Gmail sí sabe pintar

**Qué hace en una frase.** Durante los pocos milisegundos en que el plugin de Brevo arma el evento de
carrito, cambia las portadas AVIF por su hermana JPG, escribiéndola en el acto si falta.

**Ficheros.** `mu-plugins/akibara-brevo-portada-jpg-en-el-carrito.php` (141 líneas). Módulo del que
depende: `plugins/akibara-business/src/Modules/SeoSocial/SocialImageDerivatives.php`. Prueba amplia:
`plugins/akibara-business/tests/MuPlugins/BrevoPortadaJpgEnElCarritoTest.php`.

**El problema.** Medido el 2026-09-11 con el primer correo real de carrito abandonado en la bandeja del
dueño: las 12.644 portadas de la tienda existen **sólo en AVIF**, Gmail no pinta AVIF, y el correo
llegaba con un hueco.

**Estuvo un mes escrito sin llegar a correr una sola vez.** Se escribió el 2026-09-11 y hasta el
2026-09-12 no enganchó nada: se registraba a la misma prioridad que el autocargador del que depende.
Eso se cuenta abajo, en «Por qué la prioridad es 25 y no 20», porque es el modo de fallo más caro de
esta casa —una pieza correcta que nadie llama— y conviene reconocerlo en la siguiente.

**En qué momento actúa.** Se registra en `plugins_loaded` a prioridad **25** (`:141`) y, si están los dos
terceros, engancha en los tres ganchos de carrito de Brevo (`:43-49`, `:135-138`): abre a prioridad **9**
y cierra a **11**, dejando en medio la prioridad 10 donde corren los oyentes del tercero. Dentro de esa
ventana, y sólo dentro, se engancha el filtro `wp_get_attachment_image_src` a prioridad 20 (`:98`).
Medido en producción el 2026-09-15 volcando `$wp_filter`: en los tres ganchos está
`akibara_brevo_abrir_ventana_jpg` en la 9, `CartEventsManagers::handle_cart_update_event` en la 10 y
`akibara_brevo_cerrar_ventana_jpg` en la 11.

**Por qué la prioridad es 25 y no 20.** Antes de enganchar nada, la función de registro comprueba dos
clases (`:128` y `:131`). La segunda es nuestra:
`class_exists( '\Akibara\Business\Modules\SeoSocial\SocialImageDerivatives' )`. Y esa clase la resuelve
el autocargador PSR-4 de `akibara-business`, que se registra **dentro** de
`akibara_business_bootstrap()` (`plugins/akibara-business/akibara-business.php:138`), función que cuelga
de `plugins_loaded` a prioridad **20** (`:159`). El fichero nació apuntado a esa misma prioridad 20, y a
igualdad de prioridad manda el orden de registro: **los mu-plugins se cargan antes que los plugins**, así
que corría primero, preguntaba por una clase que todavía no tenía autocargador, el `class_exists`
respondía que no y los seis filtros **no se registraban nunca**. Lo midió QA akibara-3f el 2026-09-12
volcando `$wp_filter['plugins_loaded']`, tras una noche entera de correos con la portada rota. Desde
entonces el registro va a **25** (`:141`) y los dos `class_exists` dicen la verdad.

Ese fallo no lo podía cazar una prueba de la suite —ahí las clases ya están cargadas antes de ejecutar
nada, así que `class_exists` responde que sí—. Lo que sí se puede fijar, y está fijado, es **el par**:
`BrevoPortadaJpgEnElCarritoTest::test_el_registro_espera_a_plugins_loaded_tarde()` lee los dos ficheros
y exige que el arranque de business siga en 20 y este registro en 25. Mover uno sin el otro pone la
prueba en rojo.

**Qué datos lee y escribe.** Lee la dirección que devuelve `wp_get_attachment_image_src`. Si encaja con
`#/uploads/portadas/([a-z0-9-]+?)(?:-\d+x\d+)?\.avif$#` (`:67`), extrae el nombre del fichero y pide
`SocialImageDerivatives::ensure_now()` (`:70`), que **escribe el JPG en el disco** si falta. Si no se
puede escribir, la dirección **no se toca**: un enlace roto es peor que un AVIF (`:70-72`). El módulo de
derivados deja además un transient de fallo, `akb_og_fail_<slug>`, que dura 6 horas
(`SocialImageDerivatives.php:75-78`, `:156`).

**Dónde lo opera el dueño.** En ningún sitio. Es un arreglo invisible.

**Qué ve el cliente.** El correo de carrito abandonado de Brevo, que lo compone y lo manda **Brevo
desde su plataforma**, horas después, con la dirección de imagen que se guardó en el instante del
carrito. Esa dirección es la JPG, y la portada se ve.

**Lo que conviene saber.**

- La conversión es **en el acto y no encolada** a propósito, y la razón está bien argumentada
  (`:20-25`): Brevo guarda la dirección de este instante y el correo sale horas después con ella, así
  que una conversión encolada para el cron llegaría tarde.
- **La conversión** no se multiplica por los ítems del carrito, pero **el chequeo sí**. `ensure_now()`
  sale antes de convertir por tres caminos —AVIF ilegible, hermana ya existente y más nueva que el
  original, o transient de fallo vigente (`SocialImageDerivatives.php:352-360`)—, así que el coste del
  códec se paga **una vez en la vida de cada portada**. Lo que se paga en **cada evento de carrito y
  por cada imagen de cada ítem** es lo que hay antes de esa decisión: resolver el directorio de
  portadas, un `file_exists`, dos `filemtime` y una lectura de transient (`:343-360`). Son accesos a
  disco baratos, pero no son gratis y se repiten.
- Dos matices del propio fichero. El comentario dice que «dos de los tres ganchos son filtros» (`:87`)
  y sólo uno lo es: `woocommerce_add_to_cart` y `woocommerce_cart_item_removed` son acciones
  (`plugins/woocommerce/includes/class-wc-cart.php:1447` y `:1476`), mientras que
  `woocommerce_update_cart_action_cart_updated` sí es filtro
  (`plugins/woocommerce/includes/class-wc-form-handler.php:828`). Y ese tercer gancho es del carrito
  **clásico**: la tienda usa bloques, donde cambiar la cantidad dispara
  `woocommerce_after_cart_item_quantity_update` (`class-wc-cart.php:1525`), que no está en la lista. El
  añadir sí está cubierto, porque la Store API también dispara `woocommerce_add_to_cart`
  (`plugins/woocommerce/src/StoreApi/Utilities/CartController.php:216`).
- El filtro cambia sólo el primer elemento de la tupla, `$image[0]` (`:74`). El `srcset` que
  `wp_get_attachment_image()` construye aparte no pasa por este filtro, así que dentro de la ventana el
  HTML completo saldría con la imagen principal en JPG y el resto en AVIF.

---

### 5b. `akibara-brevo-welcome-coupon.php` — el puente agnóstico de automatización de marketing

**Qué hace en una frase.** Desacopla por completo `akibara-business` de Brevo (o cualquier ESP): escucha los eventos de ciclo de vida de bienvenida emitidos por el módulo `WelcomeCoupon` y sincroniza las altas, cupones dinámicos, listas y atributos de marketing en Brevo sin acoplamiento rígido.

**Ficheros.** `mu-plugins/akibara-brevo-welcome-coupon.php`. Contrato: `AkibaraMarketingAdapterInterface`. Adaptador concreto: `AkibaraBrevoMarketingAdapter`.

**Arquitectura.**
- `akibara-business` emite únicamente eventos estándar de WordPress:
  - `akb_welcome_coupon_issued`: nuevo suscriptor y cupón emitido.
  - `akb_welcome_coupon_reminder_due`: tercer día sin compra, para enviar el recordatorio de vencimiento.
  - `akb_welcome_coupon_used`: cupón canjeado en el checkout.
  - `akb_welcome_existing_buyer_subscribed`: cliente habitual que suscribe su correo para novedades.
- El adaptador `AkibaraBrevoMarketingAdapter` implementa `AkibaraMarketingAdapterInterface`:
  - Registra el contacto en Brevo y lo asigna a la lista configurada (por defecto la lista de suscriptores del checkout/boletín).
  - Almacena los atributos dinámicos del contacto (`CUPON_BIENVENIDA_CODIGO`, `CUPON_BIENVENIDA_VENCE`, `CUPON_BIENVENIDA_DESCUENTO`).
  - Envía el evento transaccional o desencadena la automatización de bienvenida en Brevo.
- Si en el futuro Akibara migra a Klaviyo, Mailchimp o webhooks propios, basta con sustituir el adaptador en este mu-plugin sin modificar una sola línea del núcleo del plugin de negocio.

---

### 6. `akibara-clarity-production-only.php` — que las pruebas no ensucien las métricas

**Qué hace en una frase.** Fuera de producción, quita las tres inyecciones de código de Microsoft
Clarity en el frente del sitio.

**Ficheros.** `mu-plugins/akibara-clarity-production-only.php` (28 líneas; nueve de trabajo real).

**En qué momento actúa.** En `init` a prioridad **1** (`:15-28`). Si `wp_get_environment_type()`
devuelve `production`, retorna sin tocar nada (`:18-20`).

**Qué datos lee y escribe.** Lee el tipo de entorno. No escribe nada. Ejecuta tres `remove_action`
(`:23-25`) cuyos nombres y ganchos están verificados contra el tercero: `clarity_add_script_to_header`
en `wp_head` (`plugins/microsoft-clarity/clarity.php:348`),
`brand_agent_enqueue_frontend_module` en `wp_enqueue_scripts` (`:390`) y
`brand_agent_add_script_to_header` en `wp_head` (`:416`). Los tres están registrados a prioridad por
defecto, así que los `remove_action` aciertan.

**Dónde lo opera el dueño.** Indirectamente, con la constante `WP_ENVIRONMENT_TYPE` del entorno.

**Qué ve el cliente.** En producción, nada cambia. En el espejo de pruebas, la página se sirve sin el
script de `clarity.ms`.

**Lo que conviene saber.** `wp_get_environment_type()` devuelve `production` cuando **nadie declara el
entorno**: el guardián falla abierto. Hoy el espejo se salva por dos cosas que viven fuera de este
fichero: el `?: 'local'` del `wp-config.php` del repositorio y la variable `WP_ENVIRONMENT_TYPE: local`
declarada en los **tres servicios de `compose.yaml` que ejecutan PHP** — `wordpress` (la web),
`cron` (el que empuja las tareas de fondo) y `cli` (la línea de comandos); los otros dos servicios,
`db` y `mailpit`, no ejecutan WordPress. Cualquier copia del sitio que no se declare vuelve a hablar
con la cuenta real de Clarity, y el único síntoma son métricas sucias semanas después.

**Hay un segundo interruptor, y no está aquí.** El módulo `FrontendCompatibility` de `akibara-business`
hace el **mismo** `remove_action( 'wp_head', 'clarity_add_script_to_header' )`
(`plugins/akibara-business/src/Modules/FrontendCompatibility/FrontendCompatibility.php:44-46`), pero con
una condición **más estricta**: exige entorno `production` **y** que el host sea `akibara.cl` o
`www.akibara.cl` (`:202-210`). Y actúa en otro momento: dentro de `register()`, que corre en el arranque
del plugin en `plugins_loaded:20`, no en `init:1`. Para depurar «por qué el espejo sigue mandando
sesiones» hay que conocer los dos. Y hay que saber cuál sobrevive si `akibara-business` se desactiva:
el del mu-plugin, que es justamente **el más laxo de los dos**.

Y lo que **no** quita este mu-plugin: los endpoints REST de Brand Agent
(`plugins/microsoft-clarity/clarity.php:666`), la ruta `/.well-known/ucp` (que se registra en `:634`,
dentro de la función enganchada en `init` en `:636`), el manejador de esa ruta en `template_redirect`
(`:657`), el borrado de webhooks en `init:20` (`:673`) y dos rutinas de `admin_init` (`:479`, `:503`),
una de las cuales puede **volver a encender** el indicador de sesión válida. Como un refresco de base de
datos desde producción trae esas credenciales armadas (ver **espejo** en el glosario), el espejo puede
acabar hablando con la cuenta real.

---

### 7. `akibara-as-retention.php` — catorce días de historial

**Qué hace en una frase.** Le dice a Action Scheduler —la cola de tareas de fondo de WooCommerce— que
guarde 14 días de acciones terminadas en vez de los 31 que trae de fábrica.

**Ficheros.** `mu-plugins/akibara-as-retention.php` (18 líneas; cuatro de trabajo real).

**En qué momento actúa.** Engancha el filtro `action_scheduler_retention_period` a prioridad por
defecto (`:13-18`), que Action Scheduler consulta cuando hace su propia limpieza
(`plugins/woocommerce/packages/action-scheduler/classes/ActionScheduler_QueueCleaner.php:112`).

**Qué datos lee y escribe.** Devuelve `14 * DAY_IN_SECONDS` y nada más. **No hay ningún `DELETE`
manual**: el purgado lo hace Action Scheduler por lotes en su acción programada.

**Dónde lo opera el dueño.** En ningún sitio; es una constante en el código.

**Qué ve el cliente.** Nada.

**Lo que conviene saber.** Medido en producción el 2026-08-31: **7.721 de 15.719 acciones completadas**
superaban los 14 días, unos 10,7 MB con sus registros. El recorte es acotado a propósito: el filtro
gobierna sólo lo que el limpiador purga —completadas y canceladas—, mientras las **fallidas conservan
sus 3 meses** por su propio filtro (`ActionScheduler_QueueCleaner.php:121`). El fichero además explica
por qué esto se hace por gancho y no editando el código: hay **dos copias** de Action Scheduler en el
árbol (`woocommerce/packages` y `seo-by-rank-math/vendor`) y sólo una se carga; editar la equivocada no
haría nada.

---

### 8. `akibara-abilities-denylist.php` — el veto

**Qué hace en una frase.** Intercepta el registro de tres abilities peligrosas —dos de Rank Math y la
de **borrar productos** de WooCommerce— y las deja inservibles.

**Ficheros.** `mu-plugins/akibara-abilities-denylist.php` (73 líneas, **versión 1.2.0**, en producción
—copia local y copia de producción byte a byte idénticas, verificado el 2026-09-20).

**En qué momento actúa.** En el filtro `wp_register_ability_args` a prioridad 10, dos argumentos
(`:16-73`). Ese filtro lo ofrece la propia Abilities API en el momento del registro, así que el veto
ocurre antes de que la ability exista del todo.

**Qué veta y por qué.** Tres nombres literales (`:26-38`):

- `rank-math/set-plugin-preferences` — existe **sólo** para encender las auto-actualizaciones del
  plugin. Eso rompería el **carril de publicación**: el procedimiento con el que la casa despliega
  código compara, fichero a fichero, el paquete publicado contra lo que hay en producción, y sólo sigue
  si coinciden **byte a byte**. Un plugin que se actualiza solo cambia ficheros por su cuenta, la
  comparación deja de cuadrar y el despliegue se detiene — a veces sin un mensaje que explique por qué.
  El carril y sus contratos se cuentan en el capítulo de despliegue.
- `rank-math/set-module-status` — puede apagar redirecciones, mapa del sitio o `llms.txt` con un
  permiso de administración remoto.
- `woocommerce/product-delete` (`:37`) — manda la ficha a la papelera, o la borra sin vuelta, y con
  ella su historial de ventas, sus metas de precio y su sitio en la serie. Es la **decisión del dueño
  del 2026-09-13**, preguntada y respondida: de las siete abilities que WooCommerce 11.1 publica por
  su cuenta se veta **sólo la de borrar**; crear y editar productos y cambiar el estado de un pedido
  siguen disponibles, porque son el trabajo que un agente hace a diario y dejan rastro reversible
  (implementado en la versión 1.2.0 y desplegado; corregido 2026-09-20, ronda 10 — este capítulo y el
  21 lo daban por pendiente).

**Cómo lo hace.** Doble capa (`:51` y `:65-66`): pone `permission_callback` en un cierre que devuelve
`false` y, si existe, baja `meta.mcp.public` a `false`.

**Y además deja rastro del intento.** Desde la reapertura de S-31b76a50 (2026-09-19) ese
`permission_callback` no es un `__return_false` pelado: antes de devolver `false` llama a
`akibara_abilities_audit_anota()` y escribe «VETADA `<ability>` · por `<usuario>` · la lista de la casa
la bloqueó» (`:51-63`). Es el **único** punto que ve todas las denegaciones, porque
`check_permissions()` corta ahí antes de que los ganchos de auditoría de la pieza 9 puedan anotar
nada, y por él pasan REST, MCP y `WP_Ability::execute()`.

**Dónde lo opera el dueño.** En ningún sitio: es una lista literal en el código.

**Qué ve el cliente.** Nada.

**Lo que conviene saber — y aquí hay una precisión importante:**

- **La primera capa es la que manda.** `permission_callback = '__return_false'` basta por sí sola: el
  controlador REST revalida el permiso de la ability destino antes de ejecutarla, así que un `POST` a
  `.../abilities/<nombre>/run` devuelve 403.
- **La segunda capa sólo afecta a MCP.** El adaptador exige `meta.mcp.public === true` para listar una
  ability (`plugins/seo-by-rank-math/vendor/wordpress/mcp-adapter/includes/Abilities/McpAbilityHelperTrait.php:39-44`
  y `:61-64`), así que por MCP desaparecen. **Por REST siguen listadas**, con su etiqueta, su
  descripción y su esquema, porque la denylist no toca `meta.show_in_rest` y el listado sólo filtra por
  ese campo, no por permisos
  (`plugins/woocommerce/vendor/wordpress/abilities-api/includes/rest-api/endpoints/class-wp-rest-abilities-v1-list-controller.php:88-94`).
  Quien quiera comprobar el veto debe medir el `POST` de ejecución, no el listado.
- **El veto cubre 3 de al menos 36 operaciones públicas por MCP, y cinco de esas 36 son nuestras.** Rank
  Math registra **24** abilities y su `shared_meta()` pone `show_in_rest => true` y
  `mcp => ['public' => true]` en **todas**
  (`plugins/seo-by-rank-math/includes/abilities/class-abilities.php:88-97`). **WooCommerce registra
  siete propias** —`orders-query`, `order-add-note`, `order-update-status`, `products-query`,
  `product-create`, `product-update` y `product-delete`— todas públicas por MCP
  (`plugins/woocommerce/src/Internal/Abilities/AbilitiesLoader.php:38-45`). Y **la casa publica cinco**,
  todas con `mcp.public => true` y `show_in_rest => true`: `akibara/set-focus-keyword` y las cuatro de
  redirecciones —`status`, `snapshot`, `trash`, `restore`—
  (`akibara-rankmath-redirections-abilities.php:204-210`, `:328-334`, `:365-371`, `:410-416`,
  `:449-455`). 24 + 7 + 5 = **36** por lectura del código; una medición viva del servidor por defecto el
  2026-09-05 listó **47 herramientas**, así que el número del código es un suelo, no un techo.
- **La más peligrosa ya está vetada; lo nuestro sigue fuera.** La más peligrosa publicada en este sitio
  no es ninguna de Rank Math: es «Delete, trash, or restore a product», que además se anuncia a sí
  misma como destructiva (`Domain/ProductDelete.php:53-63`) — y **desde la versión 1.2.0 la denylist sí
  la mira** (`:37`). Lo que la lista sigue sin cubrir es lo **nuestro**: que un cliente con cuenta pueda
  leer la descripción y el esquema de nuestras cinco abilities (§11, punto 1) es verdad **porque nosotros
  declaramos `show_in_rest => true`** en ellas. Y de WooCommerce quedan fuera por decisión escrita
  `product-create`, `product-update` y las tres de pedidos (corregido 2026-09-20, ronda 10: este bullet
  decía que la denylist «ni ve lo nuestro ni ve lo de WooCommerce»).
- Es el patrón «candados que caducan»: falta fijar la invariante —avisar cuando la lista viva
  cambie— en vez de los literales.

---

### 9. `akibara-abilities-audit.php` — el rastro de quién ejecutó qué

**Qué hace en una frase.** Anota en el registro de WooCommerce cada ejecución de una ability: qué se
ejecutó, quién lo pidió y con qué **claves** de entrada — nunca con qué valores.

**Ficheros.** `mu-plugins/akibara-abilities-audit.php` (113 líneas, **versión 1.1.0**, en producción
—copia local y copia de producción byte a byte idénticas, verificado el 2026-09-20).

**En qué momento actúa.** En los dos ganchos que la propia Abilities API dispara alrededor de cada
ejecución: `wp_before_execute_ability` a prioridad 10 con dos argumentos (`:77-91`) y
`wp_after_execute_ability` a prioridad 10 con tres (`:93-113`). No hay condición de entorno ni
interruptor: si el fichero está, escucha.

**Qué escribe, y dónde se lee.** Una línea por gancho, con `wc_get_logger()->info()` y la fuente
`akibara-abilities` (`:67-75`). Se leen en **WooCommerce → Estado → Registros**, eligiendo esa fuente.
El formato es `INICIO <ability> · por <usuario> · entrada: <claves>` y `FIN <ability> · por <usuario> ·
ok`. El usuario se escribe como `#<id> <login>`, o «sin sesión» si no hay ninguna (`:28-39`). De la
entrada sólo salen las **claves**, ordenadas y como mucho veinte (`:50-59`): una ability puede recibir
un correo, una dirección o una clave de proveedor, y el rastro tiene que decir *qué* se ejecutó, no
filtrar *con qué*.

**Cómo se lee un desenlace.** Un éxito completo deja **INICIO y FIN**. Una ability que falla deja
**INICIO sin FIN**, y eso es deliberado: `WP_Ability::execute()` devuelve antes de disparar
`wp_after_execute_ability` cuando `do_execute()` da `WP_Error`, así que a ese gancho nunca le llega un
error y prometer un desenlace que no puede ver sería mentir (`:98-105`). Un intento **vetado** no pasa
por aquí en absoluto: lo anota la denylist en su propio `permission_callback` (§8), que es el único
punto que ve todas las denegaciones.

**Dónde lo opera el dueño.** En ningún sitio: no tiene pantalla ni interruptor. Lo que sí es suyo es
dónde mirar, que es la pantalla de registros que ya usa.

**Qué ve el cliente.** Nada.

**Qué pasa si se cae.** Se pierde el rastro, y nada más: ninguna ability deja de funcionar, ningún
correo deja de salir. Es el caso contrario al de la guarda de indexación del capítulo 15 — aquí perder
la señal es reversible, así que la pieza falla **abierta**. Si WooCommerce no estuviera cargado,
escribe igual por `error_log()` con el prefijo `[akibara-abilities]` (`:73-74`): el rastro no puede
perderse.

**Lo que conviene saber.** Nació del hallazgo S-31b76a50 —había abilities que cambian el catálogo y
las redirecciones del sitio sin dejar una sola línea— y se reabrió el 2026-09-19 por dos motivos que
hoy están escritos en el código: la rama de `WP_Error` era código muerto, y las denegaciones no se
anotaban en ninguna parte.

---

### 10. `akibara-bluex-en-espanol.php` — el correo de Blue, en español

**Qué hace en una frase.** Traduce al español lo que el plugin de Blue Express le muestra al cliente
—el correo de despacho entero y el bloque de seguimiento de *Mi cuenta*— y hace que el número del
correo lleve a la página de seguimiento **real** de Blue.

**Ficheros.** `mu-plugins/akibara-bluex-en-espanol.php` (160 líneas, **versión 1.1.0**, en producción
desde el 2026-09-17 —copia local y copia de producción byte a byte idénticas, verificado el
2026-09-20).

**Por qué existe.** El plugin de Blue **no trae catálogo de traducción**: no existe su carpeta
`languages/` ni un `.mo` en `wp-content/languages/`, así que todas sus cadenas salen en inglés aunque
el sitio esté en `es_ES`. El 2026-09-17 una clienta recibió «YOUR ORDER HAS BEEN SENT / Hi there. Your
recent order on Akibara has been sent by BlueX».

**En qué momento actúa.** Tres enganches, todos por filtro y sin tocar el código del tercero:

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `gettext` | 10, 3 argumentos | Traduce las cadenas simples (`:125-134`) |
| `ngettext` | 10, 5 argumentos | Traduce el plural «Tracking code(s):» (`:146-160`) |
| `woocommerce_correios_email_tracking_core_url` | 10, 2 argumentos | Reescribe el enlace del número en el correo (`:103-115`) |

**Lo que mantiene acotado el efecto** es que los dos filtros de traducción salen inmediatamente si el
text domain no es `woocommerce-correios` (`:126-128`, `:149-151`). Ese dominio no es «bluex»: el plugin
es un derivado del de Correios de Brasil y conserva el original.

**Qué cadenas cubre.** Las del correo de seguimiento —asunto, encabezado y cuerpo, incluido el
`[{site_title}] Your order {order_number} has been sent by BlueX` que salía en mayúsculas— y las del
bloque de *Mi cuenta*: título, códigos, botón e historial (`:48-70`). Los marcadores `{site_title}`,
`{order_number}`, `{tracking_code}` y `%s` se conservan tal cual, porque los sustituye el propio plugin
**después** de traducir.

**El enlace, que es el otro arreglo.** El número del correo apunta a
`https://www.blue.cl/enviar/seguimiento?n_seguimiento=<código>` (`:89`, `:109-113`). `n_seguimiento` es
el parámetro que precarga el número **y** ejecuta la búsqueda: medido con el navegador el 2026-09-17,
con `?n=` el campo sale vacío, y el formulario que trae el plugin —un POST heredado de Correios— deja
al cliente en la portada de Blue. El botón «Ver el pedido» de la plantilla de WooCommerce sigue
llevando a *Mi cuenta*.

**Dónde lo opera el dueño.** En ningún sitio, y **eso es lo que conviene saber**: la traducción vive en
código, sin pantalla. Si el dueño escribe asunto y cuerpo a mano en WooCommerce → Ajustes → Correos
(option `woocommerce_correios_tracking_settings`), **su texto manda**, porque un ajuste guardado no
pasa por el filtro de traducción. Las frases de este mu-plugin son justamente las que ya estaban
escritas a mano en los ajustes del espejo y nunca se llevaron a producción, así que los dos entornos
dicen lo mismo. Es candidato declarado a la regla de oro de «cero ajustes sin pantalla».

**Qué ve el cliente.** Su aviso de despacho en español, y un número de seguimiento que al pulsarlo
muestra dónde va el paquete.

**Qué pasa si se cae.** El correo de despacho y el bloque de *Mi cuenta* **vuelven al inglés** —al
«YOUR ORDER HAS BEEN SENT» del 17 de septiembre— y el número vuelve a enlazar a *Mi cuenta* en vez de a
Blue. No se pierde ningún dato ni se rompe el despacho: es una pieza de idioma. Por eso no conviene
borrarla creyéndola prescindible.

**Lo que conviene saber.** Las llaves del mapa son los originales **exactos** del tercero
(`includes/emails/class-wc-correios-tracking-email.php`, `templates/emails/`, `templates/myaccount/`):
si Blue cambia una cadena, esa vuelve a salir en inglés — no se rompe nada, simplemente deja de
traducirse.

---

### 11. Contexto: la superficie de abilities y MCP (terceros)

No es código nuestro, pero sin él el capítulo no se entiende.

**Con qué credencial se entra.** Por las tres puertas se entra con una **contraseña de aplicación** de
un usuario de WordPress (`wp user application-password create <usuario> "<etiqueta>" --porcelain`, se
revoca en Perfil → Contraseñas de aplicación), mandada como autenticación básica de HTTP. La de un
administrador equivale a control total del sitio, así que se crea una por uso y se retira cuando ese uso
termina. Dos trampas del transporte, medidas: Cloudflare responde **403 con `error code: 1010`** a los
agentes de usuario por defecto de las librerías comunes, así que hay que mandar uno propio siempre; y la
Abilities API **envuelve la entrada** en `{"input": {…}}` — con el objeto pelado responde «input no es
del tipo object».

| Puerta | Quién puede entrar | Qué permite |
| --- | --- | --- |
| `GET /wp-json/wp-abilities/v1/abilities` | cualquiera con la capacidad `read` | listar **todas** las abilities con `show_in_rest`, con su descripción y su esquema completos |
| `POST /wp-json/wp-abilities/v1/abilities/<nombre>/run` | lo que diga el `permission_callback` de cada ability | ejecutar |
| `POST /wp-json/mcp/mcp-adapter-default-server` | por defecto, capacidad `read` | protocolo MCP: descubrir, consultar y ejecutar cualquier ability con `mcp.public = true` |
| `/wp-json/woocommerce/mcp` | **apagado hoy** | el servidor MCP propio de WooCommerce, detrás de la funcionalidad `mcp_integration` (`plugins/woocommerce/src/Internal/MCP/MCPAdapterProvider.php:29-36`, `:62`, `:119-134`). Si alguien la enciende desde WooCommerce → Ajustes → Avanzado → Funciones, aparece una **segunda puerta** con su propia lista de herramientas y su propio transporte, sin pasar por nada de la casa |

Cinco detalles que importan:

1. La **ejecución** está bien cerrada (cada ability revalida su propio permiso) pero la **lectura** no:
   `get_items_permissions_check()` es literalmente `return current_user_can( 'read' )`
   (`class-wp-rest-abilities-v1-list-controller.php:181-183`), que es la capacidad que tiene cualquier
   cliente registrado de la tienda. Un cliente con cuenta puede leer la descripción y el esquema de
   cualquier ability con `show_in_rest`, las de la casa incluidas.
2. Hay **dos copias del adaptador MCP** en el árbol (`woocommerce/vendor/wordpress/mcp-adapter` y
   `seo-by-rank-math/vendor/wordpress/mcp-adapter`), igual que con Action Scheduler. Cuál gana depende
   de quién cargue primero y de las versiones: las líneas citadas pueden no ser las que corren. Para
   saber cuál corre de verdad, en el sitio que sea, hay un comando de una línea:

   ```bash
   wp eval 'echo ( new ReflectionClass( "WP\\MCP\\Core\\McpAdapter" ) )->getFileName();'
   ```

   Devuelve la ruta del fichero que WordPress cargó realmente. El mismo truco sirve para Action
   Scheduler con `ActionScheduler_QueueCleaner`.
3. El servidor por defecto **no publica una herramienta por ability**: publica **tres**
   (`mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info` y `mcp-adapter/execute-ability`),
   y la tercera es el ejecutor genérico de cualquier ability con `mcp.public = true`
   (`plugins/seo-by-rank-math/vendor/wordpress/mcp-adapter/includes/Servers/DefaultServerFactory.php:43-57`).
   Por eso vetar una ability por su indicador `mcp.public` la hace desaparecer entera del catálogo MCP:
   no hay una puerta por operación que haya que cerrar una a una.
4. Existen dos ganchos de auditoría, `wp_before_execute_ability` y `wp_after_execute_ability`, y
   **los escucha la pieza 9 de este capítulo**, `akibara-abilities-audit.php` (v1.1.0, en
   producción): cada ejecución deja su rastro en WooCommerce → Estado → Registros, fuente
   `akibara-abilities`. Lo escribió el hallazgo S-31b76a50; hasta entonces, en efecto, no los
   escuchaba nadie (corregido 2026-09-20, ronda 10).
5. El orden real de las comprobaciones es **primero el esquema y después el permiso**
   (`plugins/woocommerce/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php:565`
   y `:570`). Importa al depurar: una llamada con el cuerpo mal formado falla por el esquema aunque
   quien llame no tuviera permiso para nada.

---

## Lo programado

![Cada cuánto corre cada trabajo del área](diagramas/14-mu-plugins-abilities-bluex-brevo-clarity-cron.mmd)

Aviso sobre las horas: **ninguno de los trabajos que viven dentro de WordPress tiene una hora fija de
Chile**. Todos se arman con `time() + HOUR_IN_SECONDS`, así que la hora a la que corren cada día o cada
semana es la que quedó fijada la primera vez que se armaron. La única cadencia estable es la del
empujón: en producción `DISABLE_WP_CRON` está activo y el cron del hosting empuja la cola **cada 5
minutos**.

| Trabajo | Mecanismo | Frecuencia y hora Chile | Quién lo programa | Qué hace | Si corre dos veces |
| --- | --- | --- | --- | --- | --- |
| `akibara_bluex_log_retention_cleanup` | Acción recurrente de Action Scheduler, grupo `akibara-bluex`, `unique = true` | Diaria; primera a la hora de armado + 1 h | `akibara_bluex_schedule_log_retention()` desde `action_scheduler_init:20` (`akibara-bluex-log-retention.php:112-124`) | Borra de `wp_bluex_logs` lo anterior a 2 días, en lotes de 1.000 | Inofensivo: es un `DELETE` por antigüedad; la segunda pasada no encuentra nada. Además no puede duplicarse, porque se programa con grupo y `unique` |
| `bluex_clean_logs` | Evento diario de WP-Cron, **del tercero** | Diaria, desde la instalación del plugin de Blue (`plugins/bluex-for-woocommerce/includes/class-wc-correios.php:121-122`) | El propio plugin de Blue Express | El tercero borra lo anterior a 7 días con un `DELETE` sin lotes; **nosotros añadimos nuestro borrado por lotes al mismo evento** (`akibara-bluex-log-retention.php:122`) | Inofensivo por el mismo motivo, pero es el `DELETE` sin lotes del tercero el que puede bloquear la tabla mientras corre |
| `akibara_bluex_integrity_weekly` | Acción recurrente de Action Scheduler, **sin grupo y sin `unique`** | Semanal; primera a la hora de armado + 1 h | Un bloque en `init:30` que sólo corre si `is_admin()` o `wp_doing_cron()` (`akibara-bluex-integrity-watch.php:132-144`). En la práctica la arma **la primera visita del dueño al escritorio**; el cron por WP-CLI de producción no puede rearmarla si alguien la borra | Relee el árbol de Blue Express buscando cargas rotas y manda correo si las hay | **Sí importa.** Al no haber `unique`, dos peticiones simultáneas pueden dejar dos acciones recurrentes idénticas; desde ahí el chequeo corre dos veces por semana y, como la alarma se manda siempre que haya rotura, el dueño recibe los correos por duplicado justo el día del incendio |
| Limpieza de Action Scheduler | Acción propia de Action Scheduler | La que él decida | Action Scheduler | Purga por lotes las acciones completadas y canceladas; pregunta por el filtro cuánto guardar y recibe **14 días** (`akibara-as-retention.php:13-18`) | Inofensivo: purga por antigüedad |
| Chequeo de integridad tras actualizar | Acción `upgrader_process_complete`, prioridad 10 | No es periódico: cada vez que se actualiza **cualquier** plugin | WordPress | El mismo chequeo de cargas rotas y el mismo correo | Inofensivo en cuanto a datos, pero cada actualización de cualquier plugin abre y lee entero el árbol de Blue Express, a mitad de una actualización desde el escritorio |

Dos precisiones sobre el **«grupo»** y el **`unique`** que aparecen en la tabla, porque son la
diferencia entre un trabajo localizable y uno que no lo es:

- **grupo** es una etiqueta que se le pone a la acción al programarla, y es por la que se filtra en
  WooCommerce → Estado → Acciones programadas. Una acción **sin grupo** se programa igual y corre igual,
  pero el dueño no la puede encontrar ni cancelar desde esa pantalla.
- **`unique = true`** significa «si ya hay una acción igual programada, no crees otra». Sin ese
  indicador, dos peticiones que coincidan pueden dejar dos acciones recurrentes idénticas, y desde ahí
  el trabajo corre el doble de veces para siempre.

---

## Casos de uso

### Caso 1 · Un cliente deja un tomo en el carrito y el correo le llega con la portada visible

![La portada llega en JPG al correo de carrito abandonado](diagramas/14-mu-plugins-abilities-bluex-brevo-clarity-caso-portada-carrito.mmd)

> **Este caso es el diseño, no lo que pasa hoy.** Como se explica en el §5, la pieza no llega a
> engancharse en producción por un empate de prioridades en `plugins_loaded`, así que hoy el paso 2
> nunca ocurre y el correo sigue saliendo con la portada en AVIF —es decir, con el hueco—. Se cuenta
> entero igualmente porque es lo que volverá a pasar en cuanto se corrija esa línea, y porque sin
> entender la secuencia no se entiende por qué el arreglo es una línea.

1. El cliente pulsa **Añadir al carrito**. El carrito de bloques llama a la Store API, que añade la
   línea y dispara `woocommerce_add_to_cart`
   (`plugins/woocommerce/src/StoreApi/Utilities/CartController.php:216`).
2. A prioridad **9** corre `akibara_brevo_abrir_ventana_jpg()`
   (`akibara-brevo-portada-jpg-en-el-carrito.php:92-96`), que engancha el filtro de direcciones de
   imagen.
3. A prioridad **10** corre el oyente de Brevo, que recorre los ítems del carrito y pide la imagen de
   cada producto.
4. Cada dirección pasa por `akibara_brevo_portada_en_jpg()` (`:63-81`). Si es una portada AVIF de
   `/uploads/portadas/`, se extrae el nombre del fichero.
5. `SocialImageDerivatives::ensure_now()` escribe la hermana JPG **en ese instante** si falta: decodifica
   el AVIF y codifica un JPEG de calidad 82 (`SocialImageDerivatives.php:56`). El coste de esa
   conversión no está medido en ninguna parte del código ni de las notas de trabajo: lo único sellado es
   que se paga una sola vez por portada.
6. Brevo se queda con la dirección terminada en `-og.jpg`.
7. A prioridad **11** se cierra la ventana (`:109-113`); el resto de la petición vuelve a servir AVIF a
   todo el mundo.

**Bifurcaciones.** Sin el plugin de Brevo o sin el módulo de derivados, no se engancha nada
(`:128-133`). Además, el plugin de Brevo **no está en el árbol del repositorio**, así que en el espejo
local esta ventana no se abre nunca: esta pieza sólo se puede comprobar en producción. Si el AVIF está
corrupto o el directorio no deja escribir, la dirección **no se toca**. Si ese nombre de fichero ya
falló, el transient `akb_og_fail_<slug>` evita reintentar durante 6 horas. Y si el cliente cambia la
**cantidad** desde el carrito de bloques, el gancho es `woocommerce_after_cart_item_quantity_update`,
que no está en la lista: la ventana no se abre.

**Correo.** El de carrito abandonado lo compone y lo manda **Brevo desde su plataforma**, horas después,
con la dirección guardada en el paso 6. Asunto y momento los decide Brevo, no nosotros.

---

### Caso 2 · Un visitante mira el carrito sin haber escrito su dirección

1. WooCommerce recalcula los envíos en cuanto cambia el carrito.
2. Antes de pedir tarifa, pregunta `is_available()` a cada método, y esa respuesta pasa por el filtro
   `woocommerce_shipping_<id>_is_available`
   (`plugins/woocommerce/includes/abstracts/abstract-wc-shipping-method.php:456`).
3. Nuestro filtro mira `state` y `city` del destino
   (`akibara-bluex-cotiza-solo-con-comuna.php:60-61`).
4. Si falta alguna de las dos, responde «no disponible» (`:63`).
5. Blue nunca llega a `get_shipping()`, así que no se escribe la línea de error en el registro del
   hosting.

**Bifurcaciones.** Si WooCommerce ya había dicho que no —método apagado, zona equivocada—, se respeta
ese no sin mirar nada (`:53-55`). `bluex-pudo` no está en la lista y sigue ofreciendo su tarifa fija.
En cuanto el cliente escribe su comuna, todo vuelve a la normalidad y Blue cotiza igual que siempre.

**Correos.** Ninguno.

---

### Caso 3 · Blue despacha y el cliente recibe su número de seguimiento

![Blue despacha y el cliente recibe su número de seguimiento](diagramas/14-mu-plugins-abilities-bluex-brevo-clarity-caso-numero-de-seguimiento.mmd)

1. El sistema de Blue Express manda un `PUT /wp-json/wc/v3/orders/<id>` con el campo
   `correios_tracking_code`.
2. WooCommerce guarda el pedido y procesa los campos adicionales: ahí corre nuestra función sustituta
   (`akibara-bluex-rest-tracking.php:49-51`), porque la reemplazamos en `rest_api_init:101` (`:65`).
3. Se parte el valor por comas, se limpia cada código y se llama a `wc_correios_updateTrackingCode()`
   una vez por cada uno (`:32-37`).
4. El tercero guarda la meta `_correios_tracking_code`, añade una nota al pedido y **dispara la
   notificación al cliente** (`wc-correios-functions.php:199-211`).
5. **En la misma petición**, WooCommerce dispara después `woocommerce_rest_insert_shop_order_object`
   (`class-wc-rest-crud-controller.php:268`) y nuestra función corre por segunda vez (`:84`). El
   `in_array` del tercero la hace inofensiva: ni repite el guardado ni el correo.

**Bifurcaciones.** Si Blue manda dos códigos separados por coma, el paso 4 ocurre dos veces y salen
**dos correos** al mismo cliente. Si la notificación está desactivada en WooCommerce → Ajustes →
Correos, no sale ninguno. Fuera de producción, el correo lo intercepta
`akibara-correo-no-sale-a-clientes.php`, que se cuenta en otro capítulo.

**Correo.** El original del tercero es
`[{site_title}] Your order {order_number} has been sent by BlueX`; lo que le llega al cliente es
**«Tu pedido {order_number} ha sido despachado por Blue Express»**, porque
`akibara-bluex-en-espanol.php` (§10) traduce el correo entero por `gettext`. Sale al correo de
facturación del pedido, **inmediato**, dentro de la misma petición REST. Si el dueño rellenó asunto y
encabezado en los ajustes, manda su texto (corregido 2026-09-20, ronda 10: aquí se decía que sale en
inglés salvo que el dueño lo rellene).

---

### Caso 4 · Blue publica una actualización a la que le faltan ficheros

1. Alguien actualiza un plugin —**cualquiera**— desde el escritorio de WordPress o por línea de
   comandos.
2. WordPress dispara `upgrader_process_complete`; nuestra guarda comprueba que el tipo sea `plugin`
   (`akibara-bluex-integrity-watch.php:123`) y llama al chequeo con origen «post-actualización».
3. Se recorre todo el árbol de `bluex-for-woocommerce` (`:35-37`) y, de cada fichero `.php`, se sacan
   las cargas resolubles: literales, o `__DIR__` / `dirname(__FILE__)` / `plugin_dir_path(__FILE__)`
   más un literal (`:43`).
4. Se comprueba si esa ruta existe, probando dos candidatos: relativa al fichero que la carga y
   relativa a la raíz del plugin (`:50-60`). Las que llevan una variable en la ruta se descartan,
   porque no se pueden resolver leyendo (`:61`).

**Bifurcación A — hay cargas rotas.** Se escribe una línea en el registro del hosting (`:88`), se pone
`akb_bluex_watch_baseline_seen = yes` (`:89`), se borra la marca de resolución (`:90`) y **sale la
alarma** (`:93-100`), con la lista de fichero, línea y ruta ausente, y los pasos del rearme exprés.
Asunto: `[Akibara] ⚠ Blue Express embarcó includes rotos — checkout en riesgo`. Este correo **se repite
en cada chequeo** mientras dure la rotura, a propósito.

**Bifurcación B — no hay ninguna y antes sí las hubo.** Sale **una sola vez** la buena noticia
(`:106-115`): `[Akibara] ✅ Blue Express arregló su paquete`, y se marca
`akb_bluex_watch_resolved_notified = yes` para no repetirla.

**Bifurcación C — no hay ninguna y nunca las hubo.** Silencio absoluto.

**Bifurcación D — alguien borró o renombró la carpeta del plugin.** El escáner devuelve lista vacía
(`:30-32`), que es lo mismo que devuelve un árbol sano, así que si la marca `baseline_seen` estaba
puesta sale el correo de **buena noticia** mientras la tienda se quedó sin método de envío. Es el peor
momento posible para una buena noticia falsa.

---

### Caso 5 · Un agente intenta apagar las redirecciones de Rank Math

1. Rank Math registra sus 24 abilities en `wp_abilities_api_init`, todas con `mcp.public = true`
   (`plugins/seo-by-rank-math/includes/abilities/class-abilities.php:88-97`).
2. Cada registro pasa por el filtro `wp_register_ability_args`, donde nuestra denylist mira el nombre
   (`akibara-abilities-denylist.php:40`).
3. Para `rank-math/set-module-status` y `rank-math/set-plugin-preferences` se pone el permiso en un
   cierre que devuelve `false` (`:51`) y se baja `mcp.public` a `false` (`:65-66`).
4. **Por MCP**: el adaptador ni las lista, porque exige ese indicador
   (`McpAbilityHelperTrait.php:61-64`). El agente no las ve.
5. **Por REST**: siguen apareciendo en el listado con su descripción completa, porque el listado sólo
   filtra por `show_in_rest`
   (`class-wp-rest-abilities-v1-list-controller.php:88-94`). Lo que falla es la ejecución: el `POST` a
   `.../run` devuelve 403.

**Bifurcación.** Si el agente pide en cambio `woocommerce/product-delete`, **cae en la misma doble
capa antes de llegar al tercero**: desde la versión 1.2.0 de la denylist (decisión del dueño del
2026-09-13, desplegada) ese nombre está en la lista (`akibara-abilities-denylist.php:37`), así que por
MCP no se lista y por REST el `POST` a `.../run` devuelve 403 — sin que el `permission_callback` de
WooCommerce llegue a opinar. Lo que **sí** sigue disponible por decisión escrita es crear y editar
productos y cambiar el estado de un pedido (corregido 2026-09-20, ronda 10: aquí se decía que no había
ninguna lista que lo parara).

**Correos.** Ninguno. **Registro, sí**: el intento vetado lo anota la propia denylist en su
`permission_callback` —«VETADA `woocommerce/product-delete` · por `<usuario>` · la lista de la casa la
bloqueó»— y las ejecuciones que sí pasan las anota `akibara-abilities-audit.php` por
`wp_before_execute_ability` / `wp_after_execute_ability`. Todo ello en WooCommerce → Estado →
Registros, fuente `akibara-abilities`.

---

### Caso 6 · Limpieza de fondo, un martes cualquiera

1. El cron del hosting empuja la cola cada 5 minutos, porque en producción el cron por tráfico está
   apagado.
2. Le toca el turno a la acción diaria `akibara_bluex_log_retention_cleanup`: borra de `wp_bluex_logs`
   todo lo anterior a 2 días, de mil en mil, hasta que un lote devuelva menos de mil
   (`akibara-bluex-log-retention.php:41-51`).
3. Por otro lado, el evento diario `bluex_clean_logs` del tercero corre su propio `DELETE` de 7 días
   sin lotes, **y en el mismo evento corre otra vez el nuestro** (`:122`).
4. Cuando Action Scheduler hace su propia limpieza, pregunta por el filtro cuánto historial guardar y
   recibe **14 días** (`akibara-as-retention.php:13-18`). Las acciones fallidas conservan sus 3 meses.

**Correos.** Ninguno.

---

### Caso 7 · Alguien de calidad navega el espejo local

1. En `init` prioridad 1, `wp_get_environment_type()` devuelve `local`
   (`akibara-clarity-production-only.php:18`).
2. Se quitan las tres inyecciones del plugin de Clarity (`:23-25`).
3. La página se sirve sin el script de `clarity.ms`, así que la sesión de pruebas no entra en las
   métricas del sitio real.

**Bifurcación.** En producción el mu-plugin retorna antes de tocar nada y Clarity funciona con
normalidad. Si un tercer entorno se levantara **sin declarar** `WP_ENVIRONMENT_TYPE`, la función
devolvería `production` y la etiqueta se inyectaría en el proyecto real sin ninguna señal.

**Correos.** Ninguno.

---

## Reglas de negocio

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
| --- | --- | --- | --- |
| `set-plugin-preferences` y `set-module-status` no las ejecuta nadie | `akibara-abilities-denylist.php:25-28` | No — lista literal | — |
| Blue no cotiza sin región **y** comuna | `akibara-bluex-cotiza-solo-con-comuna.php:52-64` | No | — |
| Métodos afectados: `bluex-ex`, `bluex-py`, `bluex-md`; `bluex-pudo` queda fuera | `akibara-bluex-cotiza-solo-con-comuna.php:41-43` | No | — |
| Cualquier carga rota es alarma, y la alarma se repite | `akibara-bluex-integrity-watch.php:71-102` | No | — |
| La buena noticia se manda una sola vez | `akibara-bluex-integrity-watch.php:106-115` | No | — |
| El chequeo se dispara tras actualizar **cualquier** plugin | `akibara-bluex-integrity-watch.php:121-128` | No | — |
| Registros de Blue: 2 días, lotes de 1.000 | `akibara-bluex-log-retention.php:15-16` | No | — |
| Que la tabla de registros se llene siquiera | `plugins/bluex-for-woocommerce/includes/logger/helper.php:13-25` | Sí | WooCommerce → Ajustes → **Integración** → **Blue Express**, casilla de registros (option `woocommerce_correios-integration_settings`, clave `active_logs`) |
| Historial de la cola de fondo: 14 días para completadas y canceladas | `akibara-as-retention.php:13-18` | No | — |
| Las acciones fallidas conservan 3 meses | `ActionScheduler_QueueCleaner.php:121` (tercero, no lo tocamos) | No | — |
| Códigos de seguimiento separados por comas = varios códigos y varios correos | `akibara-bluex-rest-tracking.php:32-37` | No | — |
| El texto del correo de despacho | `woocommerce_correios_tracking_settings` | Sí | WooCommerce → Ajustes → Correos |
| Clarity sólo se inyecta en producción | `akibara-clarity-production-only.php:18-25` | Sí | Constante `WP_ENVIRONMENT_TYPE` del entorno |
| Y además, sólo en el host canónico (segundo interruptor, más estricto) | `akibara-business/src/Modules/FrontendCompatibility/FrontendCompatibility.php:44-46`, `:202-210` | No | — (lista literal de hosts) |
| Sólo se cambia a JPG dentro de `/uploads/portadas/` | `akibara-brevo-portada-jpg-en-el-carrito.php:67` | No | — |
| Si no se puede escribir la JPG, la dirección no se toca | `akibara-brevo-portada-jpg-en-el-carrito.php:70-72` | No | — |
| Un nombre de fichero que ya falló no se reintenta en 6 horas | `SocialImageDerivatives.php:75-78`, `:156` | No | — |

---

## Datos que deja

| Dato | Tipo | Quién lo escribe | Para qué | ¿Se ve en alguna pantalla? |
| --- | --- | --- | --- | --- |
| `akb_bluex_watch_baseline_seen` | Option, sin autocarga | `akibara-bluex-integrity-watch.php:89` | Recordar que alguna vez hubo cargas rotas, para que la buena noticia tenga sentido | **No**; se mira con `wp option get akb_bluex_watch_baseline_seen`. **La borra desinstalar `akibara-business`** (`uninstall.php:95-104`, `LIKE 'akb\_%'` sin exclusión para `akb_bluex_`) |
| `akb_bluex_watch_resolved_notified` | Option, sin autocarga | `akibara-bluex-integrity-watch.php:109` | Que la buena noticia salga una sola vez | **No**; mismo comando y **misma desaparición** al desinstalar `akibara-business` |
| `admin_email` | Option de WordPress | Se **lee** (`akibara-bluex-integrity-watch.php:84`) | Destinatario de los dos correos del vigía | Sí, Ajustes → Generales |
| `woocommerce_correios-integration_settings` | Option del tercero | Se **lee** indirectamente (`logger/helper.php:13-25`) | Su ajuste `active_logs` decide si la tabla de registros se llena | Sí: WooCommerce → Ajustes → **Integración** → **Blue Express** (el identificador de la integración es `correios-integration`, `includes/integrations/class-wc-correios-integration.php:72-73`) |
| `woocommerce_correios_tracking_settings` | Option del tercero | Se **lee** por el tercero | Asunto y encabezado del correo de despacho | Sí, WooCommerce → Ajustes → Correos |
| `wp_bluex_logs` | Tabla de base de datos, del tercero | La escribe el tercero; **la limpiamos nosotros** (`akibara-bluex-log-retention.php:42-51`) | Registro de las llamadas a la API de Blue | Sí, en la pantalla de registros del plugin de Blue |
| `wp_actionscheduler_actions` y `wp_actionscheduler_logs` | Tablas de Action Scheduler | Action Scheduler; nosotros sólo acotamos su retención | Cola de tareas de fondo e historial | Sí, WooCommerce → Estado → Acciones programadas |
| `_correios_tracking_code` | Meta de pedido | El tercero, disparado por nosotros (`wc-correios-functions.php:199-203`) | El número de seguimiento del envío | Sí, en la ficha del pedido |
| `akb_og_fail_<slug>` | Transient, 6 horas | `SocialImageDerivatives.php:156` | No insistir sobre una portada que ya falló al convertirse | **No** |
| Nota de pedido «Agregar BlueX tracking code: …» | Nota de pedido | El tercero (`wc-correios-functions.php:208`) | Dejar constancia en el historial del pedido | Sí, en la ficha del pedido |
| Línea `[akibara-bluex-watch] Includes rotos …` | Registro de errores del hosting | `akibara-bluex-integrity-watch.php:88` | Que la integridad de los pagos no quede muda | Sí, en el registro de errores del hosting |
| Líneas `INICIO …` / `FIN …` de cada ability | Registro de WooCommerce, fuente `akibara-abilities` | `akibara-abilities-audit.php:67-75` (desde `:77` y `:93`) | Saber quién ejecutó qué, con las **claves** de la entrada y nunca sus valores | Sí, WooCommerce → Estado → Registros, eligiendo la fuente `akibara-abilities` |
| Línea `VETADA <ability> · por <usuario> …` | Registro de WooCommerce, misma fuente | `akibara-abilities-denylist.php:51-63` | Que un intento bloqueado por la lista de la casa no quede mudo | Sí, en la misma pantalla de registros |

Ninguno de los diez mu-plugins crea tablas propias, y sólo dos escriben options. Las **dos únicas
options** del capítulo, además, no son suyas del todo: las barre el desinstalador de `akibara-business`,
que es un plugin distinto.

---

## Glosario del capítulo

- **ability** — Una operación que WordPress publica con nombre, descripción, esquema de entrada, una
  función que comprueba permisos y otra que hace el trabajo, para que un asistente automático la pueda
  ejecutar.
- **Abilities API** — La pieza de WordPress que registra las abilities y las publica por su propia API
  web. En este sitio viene dentro de WooCommerce.
- **acción** (hook) — Punto del programa que avisa de que algo pasó. Quien se engancha no devuelve
  nada; sólo hace su trabajo.
- **Action Scheduler** — La cola de tareas de fondo que trae WooCommerce. Guarda cada tarea en la base
  de datos y la ejecuta cuando le toca.
- **AVIF** — Formato de imagen moderno, muy liviano, que la tienda usa para todas las portadas. Los
  clientes de correo como Gmail todavía no lo pintan.
- **`admin-ajax.php`** — La puerta antigua de WordPress para peticiones de fondo desde el navegador.
  Importa porque `is_admin()` devuelve verdadero ahí, aunque el visitante no sea administrador.
- **carril de publicación** — El procedimiento con el que la casa despliega código. Compara el paquete
  publicado contra lo que hay en producción **byte a byte**; si algo no coincide —por ejemplo porque un
  plugin se actualizó solo—, el despliegue se detiene.
- **carrito de bloques** — La versión moderna del carrito y el checkout de WooCommerce, hecha con
  bloques y que habla con el servidor por la Store API. Es lo que usa Akibara.
- **contraseña de aplicación** — Clave larga que WordPress genera aparte de la contraseña normal de una
  persona, sirve sólo para entrar por la API y se puede revocar sin cambiar la contraseña de esa
  persona. Es la credencial con la que se entra a las abilities y a MCP.
- **capacidad** (`read`, `manage_woocommerce`, `manage_options`) — Permisos de WordPress. `read` la
  tiene cualquier cliente registrado; las otras dos, sólo quien administra la tienda o el sitio.
- **cron** — El mecanismo que ejecuta tareas a una hora o cada cierto tiempo. En producción lo empuja el
  hosting cada 5 minutos, porque el disparo por tráfico de WordPress está desactivado.
- **denylist** — Lista de cosas prohibidas. Aquí, tres abilities que se vetan por nombre: dos de Rank Math y la de borrar productos de WooCommerce.
- **espejo** — La copia local del sitio, levantada con Docker desde el `compose.yaml` del repositorio y
  con datos traídos de producción. Sirve para probar antes de tocar la tienda de verdad. Que traiga
  datos reales es lo que hace peligroso que se le olvide declarar el entorno: llegan también las
  credenciales de los servicios externos, armadas.
- **filtro** (hook) — Punto del programa que deja pasar un valor y permite devolverlo cambiado.
- **`gancho:prioridad`** — Forma abreviada de escribir dónde y con qué orden se engancha una función:
  `init:30` es «en el gancho `init`, con prioridad 30». El número menor corre primero y, a igualdad de
  número, corre primero quien se enganchó antes.
- **grupo** (de Action Scheduler) — Etiqueta que se le pone a una tarea al programarla y por la que se
  la busca en WooCommerce → Estado → Acciones programadas. Una tarea sin grupo corre igual, pero el
  dueño no la encuentra en esa pantalla.
- **idempotente** — Que ejecutarlo dos veces deja el mismo resultado que ejecutarlo una.
- **MCP** — El protocolo por el que un agente de inteligencia artificial descubre y ejecuta operaciones
  publicadas por un servidor. Aquí lo sirve un adaptador que viene dentro de Rank Math y de WooCommerce.
- **meta** (de producto o de pedido) — Dato suelto guardado junto a un producto o un pedido, con nombre
  y valor.
- **mu-plugin** — Plugin de uso obligatorio: WordPress lo carga siempre, antes que los normales, y no
  se puede desactivar desde el panel.
- **option** — Ajuste guardado en la base de datos de WordPress, con nombre y valor. «Sin autocarga»
  significa que no se lee en todas las peticiones.
- **prioridad** (de un gancho) — Número que decide el orden en que corren las funciones enganchadas al
  mismo punto: el menor corre primero.
- **`srcset`** — Lista de versiones de una imagen en distintos tamaños que el navegador elige según la
  pantalla. Se construye aparte del `src`.
- **Store API** — La API web que usan el carrito y el checkout de bloques para hablar con el servidor.
- **transient** — Dato guardado con fecha de caducidad. Se usa para no repetir un trabajo caro durante
  un rato.
- **`unique`** (de Action Scheduler) — Indicador que se pone al programar una tarea recurrente y
  significa «si ya hay una igual, no crees otra». Sin él, dos peticiones simultáneas pueden dejar dos
  tareas idénticas corriendo para siempre.
- **`wc/v3`** — La versión actual de la API autenticada de WooCommerce, por donde Blue Express escribe
  los códigos de seguimiento.
