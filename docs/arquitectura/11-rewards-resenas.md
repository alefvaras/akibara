# akibara-rewards · reseñas con foto: invitación, moderación, puntos y carrusel

> Leído contra el snapshot congelado de producción: `akibara-rewards` 0.3.13, `akibara-business`
> 1.116.4, tema `akibara-gp` 2.1.118 y los 18 mu-plugins de producción. Cada afirmación técnica lleva
> su fichero y su línea. El código se leyó dos veces, por dos manos distintas, y lo que aquí se
> escribe es lo que quedó en pie después de la segunda; donde el código a la vista no alcanzaba para
> cerrar un asunto queda dicho que **hay que medirlo**, con el comando para hacerlo, en vez de
> adivinarlo.
>
> **Este capítulo es la segunda mitad de un plugin.** La primera —los Puntos Akibara: el libro
> contable, el vencimiento, los correos y la página del programa— está en
> [`10-rewards-akibara-puntos.md`](10-rewards-akibara-puntos.md). La compuerta de preventa que
> aparece varias veces es de otro plugin y se cuenta entera en
> [`04-business-disponibilidad-preventa-y-avisos.md`](04-business-disponibilidad-preventa-y-avisos.md).
>
> **Cómo leer las rutas.** Salvo que se diga otra cosa, las rutas de este capítulo son relativas a
> `public_html/wp-content/plugins/akibara-rewards/`: `src/Reviews/Invitations.php` vive en
> `public_html/wp-content/plugins/akibara-rewards/src/Reviews/Invitations.php`. Las de otros plugins
> o del tema se escriben enteras desde `plugins/` o `themes/`.
>
> **Cómo leer `gancho:prioridad`.** `pre_comment_approved:50` se lee «enganchado al gancho
> `pre_comment_approved` con prioridad 50». El número menor corre primero; a igualdad de número corre
> primero quien se enganchó antes. En este capítulo esa prioridad decide si una reseña se publica sola
> o espera al dueño.
>
> **Cómo ver los diagramas.** Cada diagrama se publica **dos veces**: el `.svg` ya renderizado —que se
> abre con doble clic en cualquier navegador— y el `.mmd` de al lado, que es la fuente editable en
> formato Mermaid. Si se toca un `.mmd` hay que volver a generar su `.svg`:
> `mmdc -i diagramas/<fichero>.mmd -o diagramas/<fichero>.svg -b transparent`.
>
> **Cómo leer las etiquetas `D1`, `D2`, `D3`…** Son los defectos abiertos que este capítulo numera al
> final, en «Defectos abiertos y preguntas para el dueño»: `D3` es el defecto nº 3 de esa lista. Los
> diagramas los citan con esa misma etiqueta, y cada uno la explica al pie.

---

## Para qué existe (en dos párrafos que entienda un novato)

Akibara vende manga por internet. La tienda está hecha con **WordPress** (el programa que sirve las
páginas) y **WooCommerce** (el añadido que le pone carrito, pedidos y clientes). Encima de esos dos
hay código propio repartido en cuatro plugins; uno de ellos, **akibara-rewards**, hace dos cosas: los
**Puntos Akibara** (el programa de fidelidad) y las **reseñas con foto**. Este capítulo cuenta sólo la
segunda mitad, y la idea de negocio cabe en una frase: *el cliente compra, unos días después recibe un
correo pidiéndole su opinión, entra a una página propia, elige el tomo, pone estrellas, escribe unas
líneas y **sube obligatoriamente una foto** del tomo en sus manos; la reseña queda esperando, el dueño
la lee y la aprueba, y en ese momento —no antes— se le acreditan 500 Puntos Akibara y le llega un
correo diciéndoselo*. Si con el tiempo hay ocho o más reseñas buenas, la portada estrena una sección
llamada «Voces del Distrito». Y en la ficha de cada tomo, Google recibe las estrellas y las cinco
reseñas más nuevas en el formato que entiende, que es lo que le permite pintar las estrellitas
amarillas en sus resultados.

Por qué tanto aparato para algo que suena simple: porque una reseña es a la vez **contenido de
marketing**, **dinero** (500 puntos son descuento real en la próxima compra) y **una declaración
pública** sujeta a normas de publicidad. Eso obliga a tres cosas que explican casi todo el diseño. La
primera: nada se publica ni se paga sin que el dueño lo apruebe a mano, y el propio código lo deja
escrito como la primera de sus decisiones de diseño (`src/Reviews/Moderation.php:3-5`). La segunda: el
premio se paga **una sola vez por cliente y producto**. Eso lo garantiza un índice único de la base de
datos: el código arma una clave de evento del tipo `review_u{cliente}_p{tomo}`
(`src/Reviews/ReviewPoints.php:268`) y la columna que la guarda está declarada
`UNIQUE KEY event_key (event_key)` en la tabla de contabilidad `{prefijo}akb_rewards_ledger`
(`src/Support/Schema.php:181`, tabla creada en `:166-183`). Sin eso, re-reseñar el mismo tomo sería
una máquina de fabricar descuentos. La tercera: la foto es obligatoria desde el 2026-09-06 por decisión del dueño
(`src/Reviews/ReviewForm.php:190-203`), y todas las superficies que hablan del premio están atadas a
un mismo interruptor para que **ninguna prometa un premio que ningún código puede pagar**
(`src/Support/Options.php:172-179`).

Un dato que ordena la lectura de todo lo que sigue: **hoy la tienda no tiene ninguna reseña
publicada**. Ojo con ese dato, porque no sale del código: es contenido de la base de datos, y este
capítulo se escribió leyendo ficheros. Sale de la lista de mediciones del final (puntos 8 y 9), y se
comprueba en dos líneas:

```bash
wp db query "SELECT COUNT(*) FROM wp_comments WHERE comment_type='review'"   # reseñas publicadas
wp post list --post_type=product --format=count                              # fichas de producto
```

Mientras nadie los vuelva a medir, «cero reseñas» y «unas 1.600 fichas» son las cifras con que se
escribió este texto, no una verdad permanente. Con esa salvedad, el capítulo describe lo que el código
promete y señala, con nombre y apellido, los puntos donde el camino se puede estar cortando.

Cuatro palabras que van a aparecer todo el rato:

- **Hook** («gancho»): un punto donde WordPress se detiene y avisa «acaba de pasar esto, ¿alguien
  quiere hacer algo?». Ejemplo: `woocommerce_order_status_changed` avisa cada vez que un pedido cambia
  de estado.
- **Option**: un ajuste guardado en la base de datos, con nombre. Los de este plugin empiezan por
  `akb_rewards_`. Regla de la casa: cada option tiene que tener una casilla en una pantalla.
- **Meta**: un dato pegado a algo. «comment meta» = un dato pegado a una reseña; «order meta» = un dato
  pegado a un pedido. Es la forma de anotar al margen: «a este pedido ya le mandé la invitación».
- **Action Scheduler**: la cola de trabajos de fondo que trae WooCommerce. Se le dice «dentro de tres
  días ejecuta esto con este número de pedido» y ella se encarga, con reintentos.

Y una quinta, histórica, sin la cual medio fichero no se entiende: hasta hace poco las reseñas las
manejaba un plugin comprado llamado **Customer Reviews for WooCommerce** (en el código aparece como
*CusRev*, `CR_Reviews`, o metas que empiezan por `ivole_` y `cr_`). akibara-rewards lo reemplaza, pero
con cuidado de anticuario: **todo el motor propio duerme mientras CusRev siga instalado**
(`src/Support/Options.php:167`) y, cuando despierta, escribe las fotos en las mismas claves que usaba
el plugin viejo (`src/Reviews/Media.php:29-33`) para no perder nada de lo que ya existía.

---

## Cómo encaja con el resto

![Piezas del capítulo 11](diagramas/11-rewards-resenas-piezas.mmd)

**Con quién habla, hacia adentro de la casa:**

| Con quién | Por dónde | Para qué |
| --- | --- | --- |
| **akibara-business** (preventa) | `CompletionPolicy::can_request_review()` — `plugins/akibara-business/src/Modules/OrderPreventa/CompletionPolicy.php:170-175`, llamada desde `src/Reviews/Invitations.php:131-134` y `:175-178` | Que un pedido con preventa pendiente no reciba la invitación antes de tener el tomo en la mano |
| **akibara-business** (preventa) | evento `akb_order_preventa_marked_ready` — `plugins/akibara-business/src/Modules/OrderPreventa/ReadyToShipCron.php:196` | **Existe y nadie lo escucha desde reseñas.** Es la puerta de reentrada que hoy falta (ver defecto D2) |
| **El programa de puntos** (mismo plugin) | `Points\Ledger::credit()` con `action='review'`, `ref_type='review'`, `ref_id` = ID de la reseña — `src/Reviews/ReviewPoints.php:256-269` | El premio de 500 puntos es una fila más del libro contable |
| **El programa de puntos** (mismo plugin) | evento `akb_rewards_review_points_paid` → `Points\Emails::send_review_email()` — `src/Points/Emails.php:245` | El correo «Tu reseña sumó puntos» |
| **El programa de puntos** (mismo plugin) | `Points\AccountRewards::rules_signature()` — `src/Points/AccountRewards.php:87-89` | Es quien hace que la dirección `/resena/{token}/` exista de verdad |
| **El programa de puntos** (mismo plugin) | `Points\Expiry::run_daily()` — `src/Points/Expiry.php:235` | El único cron que limpia los enlaces de invitación caducados |
| **El tema `akibara-gp`** | `assets/css/reviews-p5-v4.css`, encolado en toda ficha de producto — `themes/akibara-gp/functions.php:76-83` | Da estilo de marca a la lista **nativa** de reseñas de WooCommerce; no hay campo de foto en esa lista |
| **El tema `akibara-gp`** | librería GSAP ya registrada — `src/Reviews/Carousel.php:214-231` | El carrusel anima la aparición de las tarjetas sólo si el tema ya registró GSAP en esa página. **En la portada sí lo hace**: el tema registra `gsap` y `gsap-scrolltrigger` en toda superficie con rejilla de productos (`themes/akibara-gp/inc/enqueue.php:433-437`) y esa lista incluye la portada (`akibara_gp_surface_has_product_loop()`, `themes/akibara-gp/inc/page-targets.php:101-118`, vía `akibara_gp_is_home_surface()` en `:64-66`) |

**Con terceros:**

| Tercero | Por dónde | Qué pasa |
| --- | --- | --- |
| **WooCommerce** | `woocommerce_order_status_changed:20`, `woocommerce_email_order_details:25`, `woocommerce_customer_email_verified:20`, `wc_customer_bought_product()`, Action Scheduler | Dispara la invitación, mete el empujón en el correo de entrega, resuelve el invitado que se hace cuenta y decide la «compra verificada» |
| **Rank Math** | filtro `rank_math/snippet/rich_snippet_product_entity:50` — `src/Reviews/Schema.php:53` | Las estrellas y las cinco reseñas más nuevas entran **dentro** del bloque de datos que Rank Math ya publica; nunca como un bloque suelto (`src/Reviews/Schema.php:20-25`) |
| **LiteSpeed** | acción `litespeed_purge_url` — `src/Reviews/Carousel.php:74-76` | Cada vez que cambia una reseña se purga la copia cacheada de la portada |
| **Customer Reviews for WooCommerce (CusRev)** | `class_exists('\CR_Reviews')` — `src/Support/Options.php:167` | **Su sola presencia duerme el motor entero.** Además se heredan sus claves de datos (`ivole_*`, `cr_*`) para no romper lo viejo |

**Qué pasa si se apaga.** Hay cinco interruptores de distinto calibre, y conviene no confundirlos:

1. **Si *Simple Points and Rewards* (el plugin de puntos viejo) sigue activo**, akibara-rewards **no
   arranca en absoluto** y deja un aviso en el escritorio con el runbook del cambio
   (`akibara-rewards.php:127-140`).
2. **Si CusRev está instalado**, el plugin arranca pero **los siete módulos de reseñas no existen**
   (`src/Plugin.php:98-104`). Los siete, por nombre, son `Moderation`, `ReviewPoints`, `Carousel`,
   `Schema`, `ReviewNudge`, `Invitations` y `ReviewForm`: sin ellos no hay invitaciones, no hay página
   `/resena/`, no hay carrusel, no hay datos para Google y no hay moderación forzada. Aparece un aviso
   explicándolo (`src/Plugin.php:106-115`). Lo que **sí** sobrevive no es sólo `AdminReviews`: fuera
   del gate siguen vivos `SettingsPage` (`src/Plugin.php:71`), `Points\Emails` (`src/Plugin.php:75`,
   que mantiene enganchado `akb_rewards_review_points_paid` en `src/Points/Emails.php:245`) y
   `Support\Privacy` (`src/Plugin.php:85`). Eso significa que el dueño puede marcar y desmarcar las
   **dieciocho** casillas de reseñas y carrusel, y guardarlas, sin que ningún módulo las lea: una
   palanca que miente, que es justo lo que la regla de oro del dueño prohíbe.
3. **Si el dueño apaga «Dar puntos por reseña con foto»** (`akb_rewards_reviews_enabled`), el motor
   sigue funcionando pero `Options::review_points()` devuelve 0 (`src/Support/Options.php:284-289`) y
   con él `reviews_lane_on()` (`:177-179`): desaparecen las promesas de premio de todas las
   superficies. El formulario **sigue exigiendo la foto**, sin explicar por qué.
4. **Si el dueño apaga «Invitar a reseñar por correo»** (`akb_rewards_invite_enabled`), no se agenda ni
   se envía nada (`src/Reviews/Invitations.php:103-105` y `:157-159`), pero la página `/resena/` sigue
   viva para los enlaces ya enviados.
5. **Si el dueño apaga «Carrusel del home»** (`akb_rewards_carousel_enabled`), la sección de la portada
   deja de pintarse y **nada más cambia** (`src/Reviews/Carousel.php:187-189`): las reseñas se siguen
   recibiendo, aprobando y pagando igual.

**Los ganchos propios que este carril emite.** Son **cuatro** —más uno que emite el otro plugin y
que aquí se consulta—, y conviene verlos juntos por lo contrario de lo que decía esta tabla hasta
la ronda 10: los dos fallos de pago **sí** tienen quien los escuche, y quien los escucha le manda
un correo al dueño. El único de la casa que sigue sin oyente es `akb_rewards_reviews_engine_on`, y
es a propósito (corregido 2026-09-20, ronda 10):

| Gancho | Tipo | Dónde se emite | Quién lo escucha hoy |
| --- | --- | --- | --- |
| `akb_rewards_review_points_paid` | acción | `src/Reviews/ReviewPoints.php:384`, sólo si el saldo se movió de verdad | `Points\Emails::send_review_email()` (`src/Points/Emails.php:290`, prioridad 100). Es el correo del premio |
| `akb_rewards_review_pay_failed` | acción | `src/Reviews/ReviewPoints.php:310` (candado ocupado) y `:362` (el libro contable no respondió) | `Support\Alerts::on_review_pay_failed()` (`src/Support/Alerts.php:47`, manejador en `:197`): **avisa al dueño por correo**, con enlace a la reseña y la instrucción de desaprobar y volver a aprobar. `Alerts` se registra siempre, fuera del gate de reseñas (`src/Plugin.php:89`) |
| `akb_rewards_reversal_failed` | acción | `src/Reviews/ReviewPoints.php:461`, cuando una reseña retirada no pudo descontar sus puntos | `Support\Alerts::on_reversal_failed()` (`src/Support/Alerts.php:45`) |
| `akb_rewards_reviews_engine_on` | filtro | `src/Support/Options.php:167` | **Nadie.** Es un punto de enganche para pruebas: permite forzar el motor despierto sin desinstalar CusRev |
| `akb_order_preventa_can_request_review` | filtro (lo emite el **otro** plugin) | `plugins/akibara-business/src/Modules/OrderPreventa/CompletionPolicy.php:174` | **Nadie.** Es la única forma documentada de relajar la compuerta de preventa sin tocar código sellado |

Y uno más, en la dirección contraria: `akb_order_preventa_marked_ready`, que **emite**
akibara-business cada vez que el cron marca un pedido de preventa como listo
(`plugins/akibara-business/src/Modules/OrderPreventa/ReadyToShipCron.php:196`). Su único oyente en
todo el árbol es `ReadyEmailDispatcher`, del propio akibara-business: reseñas no lo escucha, y ésa es
exactamente la puerta que le falta al defecto D2.

---

## Piezas

### 1. `Plugin` y `akibara-rewards.php` — el interruptor maestro

**Qué hace en una frase:** decide qué módulos existen en esta petición.

- **Ficheros:** `akibara-rewards.php`, `src/Plugin.php`.
- **Cuándo actúa:** en el arranque de WordPress (`plugins_loaded`); el gate de convivencia con el
  plugin de puntos viejo está en `akibara-rewards.php:127-140`, y el de reseñas en `src/Plugin.php:97`.
- **Qué lee:** la existencia de las clases `\CR_Reviews` y de la función `spar_update_user_points()`.
- **Qué escribe:** nada. Sólo pinta avisos de escritorio (`src/Plugin.php:106-115`).
- **Dónde lo opera el dueño:** en ningún sitio directamente — se opera **activando o desactivando
  plugins** en Plugins → Instalados. Un filtro de código, `akb_rewards_reviews_engine_on`
  (`src/Support/Options.php:167`), permite forzarlo en pruebas.
- **Qué ve el cliente:** nada.
- **Trampa documentada en el propio código** (`src/Support/Options.php:155-158`): el arranque consulta
  `reviews_engine_on()` en `plugins_loaded`, así que un filtro registrado más tarde **cambia el texto
  de las páginas pero no cambia qué módulos se cargaron**.

### 2. `Support\Options` — la autoridad única de los interruptores

**Qué hace en una frase:** es el único sitio del plugin donde se pregunta «¿esto está encendido?».

- **Fichero:** `src/Support/Options.php`.
- **Cuándo actúa:** no se engancha a nada; son funciones que se llaman desde los demás módulos.
- **Qué lee:** las options `akb_rewards_*`. Tres mandan sobre reseñas:

| Función | Qué decide | Línea |
| --- | --- | --- |
| `reviews_engine_on()` | ¿El motor propio está despierto? = *CusRev no está instalado*, con filtro para forzarlo | `:161-168` |
| `review_points()` | Cuántos puntos paga una reseña (500), y 0 si `akb_rewards_reviews_enabled` está en «no» | `:284-289` |
| `reviews_lane_on()` | ¿Se puede **prometer** el premio? = monto > 0 **y** motor despierto | `:177-179` |

`reviews_lane_on()` tiene exactamente **seis** consumidores en todo el árbol, y conviene tenerlos
listados porque son las superficies que hablan del premio: el correo de invitación
(`src/Reviews/Invitations.php:250`), el recuadro del formulario (`src/Reviews/ReviewForm.php:504`), la
página de agradecimiento (`:546`), dos fragmentos de Mi cuenta → Puntos Akibara
(`src/Points/AccountRewards.php:248` y `:271`) y la tabla «cómo ganas puntos» de la página pública del
programa (`src/Points/ProgramPage.php:274`).

> **Cuidado con una que parece la séptima y no lo es.** El párrafo del correo de entrega **no**
> consulta `reviews_lane_on()`: `ReviewNudge` mira `Options::review_points() > 0` más su propio
> interruptor (`src/Reviews/ReviewNudge.php:57-60`). El efecto coincide sólo porque ese módulo se
> registra dentro del gate del motor (`src/Plugin.php:102`), no porque el accesor lo gobierne. La
> diferencia importa: si alguien forzara el filtro del motor, las seis superficies cambiarían de texto
> y el correo de entrega no.

### 3. `Support\SettingsPage` — la pantalla del dueño

**Qué hace en una frase:** pinta y guarda, desde un único catálogo, todos los ajustes del programa.

- **Fichero:** `src/Support/SettingsPage.php`.
- **Cuándo actúa:** `admin_menu` para el menú (`:356-365`) y `admin_post_akb_rewards_save_settings`
  para el guardado.
- **Dónde lo opera el dueño:** **WooCommerce → Puntos y Reseñas** (`:356-365`, permiso
  `manage_woocommerce`). La dirección exacta es
  `/wp-admin/admin.php?page=akb-rewards-settings` — el identificador de la pantalla es
  `akb-rewards-settings` (`:27`), y conviene copiarlo tal cual: en este proyecto ya costó tiempo un
  identificador de panel equivocado, que devuelve un 403 y parece un problema de permisos.
- **Qué lee y escribe:** las options del catálogo `FIELDS` (`:39-329`), que es a la vez la pantalla y
  el saneado al guardar.

Los campos de la sección «Reseñas» son **doce**:

| Option | Etiqueta en pantalla | Valor de fábrica |
| --- | --- | --- |
| `akb_rewards_invite_enabled` | Invitar a reseñar por correo | sí |
| `akb_rewards_invite_status` | Estado del pedido que dispara la invitación | `completed` |
| `akb_rewards_invite_delay_days` | Días de espera antes de invitar | 3 |
| `akb_rewards_invite_token_days` | Vida del enlace de reseña (días) | 30 |
| `akb_rewards_reviews_enabled` | Dar puntos por reseña con foto | sí |
| `akb_rewards_review_points` | Puntos por reseña con foto aprobada | 500 |
| `akb_rewards_review_every_n` | Premiar cada cuántas reseñas | 1 |
| `akb_rewards_review_max_per_user` | Tope de premios por cliente | 0 (sin tope) |
| `akb_rewards_review_require_verified` | Exigir compra verificada | sí |
| `akb_rewards_moderate_reviews` | Moderación obligatoria | sí |
| `akb_rewards_review_nudge_enabled` | Invitación a reseñar en el correo | sí |
| `akb_rewards_nudge_email_ids` | Correos que llevan la invitación | `customer_completed_order` |

Y **seis** más en la sección «Carrusel del home» (`:276-321`), con sus etiquetas de pantalla, que no
se parecen a los nombres de las options:

| Option | Etiqueta en pantalla | Valor de fábrica |
| --- | --- | --- |
| `akb_rewards_carousel_enabled` | Carrusel del home | sí |
| `akb_rewards_carousel_min` | Mínimo de reseñas para aparecer | 8 |
| `akb_rewards_carousel_min_chars` | Largo mínimo del texto | 40 |
| `akb_rewards_carousel_cards` | Tarjetas máximas | 8 |
| `akb_rewards_carousel_title` | Título de la sección | «Voces del Distrito» |
| `akb_rewards_carousel_exclude_rewarded` | Excluir reseñas premiadas con puntos | no |

Dos cosas bien resueltas y una mal:

- **Bien:** el selector de estado se llena con los estados **reales** de la tienda
  (`order_status_choices()`, `:560-583`) y, al guardar, un *slug* que no exista cae al valor de fábrica,
  con el motivo escrito: *«un slug desconocido dejaría las invitaciones mudas para siempre sin que
  nadie lo vea»* (`:509-514`).
- **Bien:** al guardar se tira el caché del carrusel para que el clic rija al instante (`:482-485`).
- **Mal:** los campos de tipo texto guardan la cadena vacía tal cual (`:523-524`). Vaciar «Correos que
  llevan la invitación» deja el empujón mudo con la casilla en «Activado» (defecto D7).

Ninguna option del área se queda sin pantalla. Las dos que no aparecen (`akb_rewards_db_version`,
`akb_rewards_rewrite_version`) son marcas internas de instalación, no ajustes.

### 4. `Support\Schema` — la tabla de los enlaces personales

**Qué hace en una frase:** crea y mantiene la tabla donde vive cada enlace de invitación emitido.

- **Fichero:** `src/Support/Schema.php`.
- **Cuándo actúa:** en la activación del plugin y en `init:5` (`:83-86`), de forma idempotente.
- **Qué escribe:** dos tablas. La del carril de reseñas es
  `{prefijo}akb_rewards_review_forms` (`:192-206`), con una fila por enlace: `order_id`, `token`
  (único), `expires_at`, `used_at`, `created_at`. La otra es `{prefijo}akb_rewards_ledger`
  (`:166-183`), el libro contable de los puntos, que es del capítulo 10 pero donde aterriza el premio
  de cada reseña.
- **La tabla admite varias invitaciones por pedido** (`:188-191`), pero eso es capacidad, no función:
  **hoy nada la usa**. En cuanto un pedido lleva la meta `_akb_rewards_invite_sent`, el código corta
  para siempre (`src/Reviews/Invitations.php:124-126` y `:166-168`) y **no hay ningún botón en ninguna
  pantalla que borre esa meta**. Re-invitar a un cliente hoy exige borrarla a mano
  (`wp post meta delete <id> _akb_rewards_invite_sent`, o su equivalente en HPOS) y volver a llevar el
  pedido al estado disparador. Es un pendiente, no una función.
- **Higiene:** `purge_stale_review_tokens()` (`:71-76`) borra de 500 en 500 los tokens que **vencieron
  hace más de seis meses y nunca se usaron**. Los usados se conservan a propósito, para poder auditar
  qué reseña vino de qué pedido.
- **Quién llama a esa higiene:** `Reviews\TokenCleanup`, con su propia acción diaria
  `akb_rewards_review_tokens_cleanup`, registrada fuera de todo interruptor (0.4.9). Hasta 0.4.8 la
  llamaba, de paso, el cron de **vencimiento de puntos**, y apagar ese vencimiento la apagaba también
  (era el defecto D10, cerrado).
- **Si la tabla no se crea, todo el circuito de reseñas muere en silencio** salvo una línea en el
  registro, que escribe `maybe_install()` (`:100-112`). Quien busque la causa tiene que saber que esa
  línea existe — **y que nombra la tabla equivocada**: el mensaje imprime siempre
  `self::ledger_table()` (`:106`), aunque la que falte sea `akb_rewards_review_forms`. La
  comprobación, `schema_ready()` (`:214-219`), sí mira las dos; el mensaje sólo sabe nombrar una. El
  operador lee «`akb_rewards_ledger` no existe» y se pone a buscar donde no es. Ver defecto D24.

### 5. `Reviews\Invitations` — el correo que empieza todo

**Qué hace en una frase:** agenda y envía el correo con el enlace personal de reseña.

- **Fichero:** `src/Reviews/Invitations.php`.
- **Cuándo actúa** (`:87-90`): `woocommerce_order_status_changed:20` → `on_status_changed`; y el
  trabajo diferido `akb_rewards_send_review_invite` → `send_invite`.
- **Qué lee:** las cuatro options de invitación, las metas del pedido y la compuerta de preventa.
- **Qué escribe:** las metas de pedido `_akb_rewards_invite_scheduled` (`:145`), `_akb_rewards_invite_sent`
  (`:193`) y `_akb_rewards_invite_attempts` (`:199`); y una fila en la tabla de tokens (`:218-227`).
- **Dónde lo opera el dueño:** WooCommerce → Puntos y Reseñas, sección «Reseñas».
- **Qué ve el cliente:** un correo con asunto **`[Akibara] ¿Qué te pareció tu pedido?`**.

**Agendar** (`on_status_changed`, `:100-147`) son **ocho** cortes, en este orden exacto:

1. Carril apagado o sin Action Scheduler → nada (`:103-105`).
2. El pedido cayó (cancelado, reembolsado, fallido, papelera — `Support\OrderStates`) → **desagenda** lo
   que hubiera pendiente (`:110-113`).
3. El estado nuevo no es el configurado → nada (`:115-117`).
4. **El id del pedido ya no resuelve a un pedido** (está en la papelera, se borró en firme, el id
   llegó mal) → nada (`:119-122`). Es una salida silenciosa de verdad, y es justo la que no queda
   anotada en ningún sitio cuando alguien depura un «se agendó y no salió».
5. El pedido ya tiene `_akb_rewards_invite_sent` → nada, «ya invitado en su historia» (`:124-126`).
6. **Compuerta de preventa:** si `CompletionPolicy::can_request_review()` dice que no, se sale sin
   agendar, confiando en que *«la transición válida futura re-entra por este hook»* (`:131-134`).
7. Ya hay un trabajo en cola para ese pedido → nada, uno por pedido (`:136-138`).
8. Agenda para dentro de `delay_days` × 24 h y anota la marca (`:140-146`).

**Enviar** (`send_invite`, `:156-205`) repite seis comprobaciones: el interruptor (`:157-159`), que el
id **siga** resolviendo a un pedido (`:161-164` — el mismo corte silencioso del punto 4), la marca de
ya enviado (`:166-168`), que el pedido **siga** en el estado disparador (`:172-174`), la compuerta de
preventa otra vez (`:175-178`) y que haya correo de facturación (`:180-183`). Entonces emite el token — 40 caracteres alfanuméricos de
`wp_generate_password` (`:216`) — y manda el correo. El sellado es la parte cuidada: **`_akb_rewards_invite_sent`
sólo se escribe si el transporte de correo confirmó** (`:192-195`); si falló, suma un intento y
reagenda a 6 horas, con tope de 3 intentos (`:198-204`, constante en `:43`).

El correo (`deliver`, `:245-280`):

- **Asunto:** `[Akibara] ¿Qué te pareció tu pedido?` (`:275`).
- **Encabezado:** «Cuéntanos qué te pareció» (`:276`).
- **Cuándo sale:** con la cola de Action Scheduler, es decir **tres días después** de que el pedido
  entre al estado disparador, no al instante.
- Si el carril de premio está vivo, el cuerpo pide la foto y promete los 500 puntos (`:250-260`). Ese
  texto se escribió el 2026-09-06, cuando la foto dejó de ser opcional, precisamente para que el correo
  no prometa una cosa y el formulario exija otra.
- Un botón de marca (`Emails::cta`) y la línea «El enlace es personal y vence en 30 días» (`:266`).
  Cuidado: los 30 no son texto fijo. La plantilla dice `'…vence en %d días.'` y el número se lo pone
  `self::token_days()` (`:270`), es decir la option «Vida del enlace de reseña (días)». Si el dueño la
  baja a 15, el correo dice 15. Pasa lo mismo con el «500» del asunto del correo del premio
  (`src/Points/Emails.php:389`): sale de «Puntos por reseña con foto aprobada», no de una constante.

**Dónde se mira si un trabajo está agendado**, que es la pregunta que uno se hace apenas algo no sale:
**WooCommerce → Estado → Acciones programadas**, filtrando por el gancho
`akb_rewards_send_review_invite`. Por línea de comandos,
`wp action-scheduler list --hook=akb_rewards_send_review_invite`.

### 5 bis. `Reviews\InviteOptOut` — la salida del cliente *(0.4.9)*

**Qué hace en una frase:** deja que un cliente diga «no me pidan reseñas» sin escribirle a la tienda.

- **Fichero:** `src/Reviews/InviteOptOut.php`. Se registra **fuera** del gate del motor: los correos ya
  enviados llevan su enlace, y apagar el motor no puede volverlo un 404.
- **El enlace:** `/mi-cuenta/?akb_rw_baja={pedido}-{firma}`. La firma es un HMAC del número de pedido con
  la sal del sitio: no vive en ninguna tabla (no caduca ni se purga), no abre nada más que esta página y
  no expone el correo. **No** es el token del formulario de reseña —ese publica reseñas y acredita
  puntos, y este enlace viaja en una cabecera que leen pasarelas de correo—.
- **Cómo se comporta:** abrir el enlace **pregunta**; el botón (POST) da de baja; el «un clic» de Gmail
  (RFC 8058) da de baja directo; la misma página deja **volver atrás**. Un enlace que no se reconoce
  responde **404**, nunca 200: Gmail lee cualquier 2xx como «hecho».
- **Qué guarda:** la option `akb_rewards_invite_optout`, con la huella SHA-256 del correo (nunca la
  dirección). La baja es por **correo**, no por cuenta: la mitad de los pedidos son de invitado.
- **Quién la respeta:** `Invitations::send_invite()` (al ENVIAR, no al agendar: la baja puede llegar en
  los días de espera) y `ReviewNudge::render()`.
- **Qué ve el dueño:** cuántos la usaron, bajo «Invitar a reseñar por correo».

### 6. `Reviews\ReviewForm` — la página del token

**Qué hace en una frase:** sirve y procesa la página del enlace personal de reseña.

- **Dónde vive la página (0.4.9):** `/mi-cuenta/?akb_review_token={token}`. Hasta 0.4.8 era
  `/resena/{token}/`, y se midió en producción que el borde la **cacheaba** (`cf-cache-status: HIT`
  con `no-store` en la misma respuesta: la regla de Cloudflare pisa las cabeceras del origen). La ruta
  de Mi cuenta es de las que la zona deja pasar sin cachear. `/resena/{token}/` sigue viva y **redirige
  con 302**, para los correos ya enviados. La mecánica común está en `Support\PrivatePage`.

- **Fichero:** `src/Reviews/ReviewForm.php`.
- **Cuándo actúa** (`:57-61`): `init` para las rutas, `query_vars` para las variables,
  `template_redirect` para pintar.
- **Rutas** (`:68-71`): `^resena/([A-Za-z0-9]{20,64})/?$` (el formulario) y `^cusrev/([^/]+)/?$` (una
  página amable para los enlaces del plugin viejo que ya se enviaron por correo y nunca podrán
  validar).
- **Quién hace que esas rutas existan de verdad:** `Points\AccountRewards`, que refresca los enlaces
  permanentes una vez por **firma de reglas** e incluye el estado del motor en esa firma
  (`src/Points/AccountRewards.php:87-106`), justamente para que al despertar el motor entre un refresco
  más y los `/resena/{token}` ya enviados por correo no caigan en un 404.
- **Y si aun así da 404**, hay una prueba de un minuto que separa «la página está rota» de «las reglas
  no se refrescaron»: abrir `https://akibara.cl/?akb_review_token=<token>`. Esa dirección **no usa las
  reglas de reescritura**, porque la variable está registrada aparte, en
  `ReviewForm::register_query_vars()` (`src/Reviews/ReviewForm.php:37`, `:77-80`). Si por ahí el
  formulario aparece, el problema son las reglas, y se arreglan entrando a **Ajustes → Enlaces
  permanentes → Guardar cambios**, o borrando la option `akb_rewards_rewrite_version`
  (`src/Points/AccountRewards.php:105`) para que el refresco vuelva a correr.
- **Qué ve el cliente:** una página propia, autocontenida, sin JavaScript, en modo oscuro de marca y
  con `noindex` (`render_shell`, `:585-611`), que además pide no cachearse (`nocache_headers()`, `:97`).
- **Quien tenga que retocarla, que no busque hoja de estilos:** todo el CSS de esta página va escrito
  **dentro del PHP**, en la cadena que devuelve `render_shell()` (`:585-611`), y en la carpeta
  `assets/` del plugin no hay ningún fichero de reseñas (sólo `carousel.js` y `checkout-blocks.js`).
  Cambiar los colores de marca de este formulario es editar ese PHP.

La página muestra el título «Tu reseña del pedido #NNNN» (`:512`), un radio por cada producto del
pedido **que no haya sido devuelto entero** (`fue_devuelto`, `:318-327`), cinco estrellas pintadas al
revés con CSS para el efecto de relleno (`:496-501`), un área de texto con mínimo 10 caracteres
(`:518`), un **campo de archivo obligatorio** con su explicación (`:519`) y, si el carril está vivo, el
recuadro del premio (`:504-509`).

El comentario de `fue_devuelto` cuenta el caso real que lo motivó: *pedido #27782, Frieren 06 — la
editorial no tenía el tomo, se devolvió el dinero, y el formulario iba a pedirle al cliente que opinara
de un libro que nunca tuvo en las manos* (`:302-306`).

**El envío** (`process`, `:160-272`) valida en este orden, devolviendo un aviso en castellano en cada
corte: token válido (`:161-164`) → nonce (`:166-168`) → el pedido existe (`:170-173`) → el producto es
de ese pedido (`:175-178`) → estrellas 1-5 (`:180-183`) → texto de 10 o más caracteres (`:185-188`) →
**foto presente** (`:205-207`) → la foto sube (`:209-212`).

Después viene lo más fino del fichero: **el token se marca como usado —«se quema»— antes de crear la
reseña**, con una escritura condicional (`claim_token`, `:398-412`):

```sql
UPDATE {prefijo}akb_rewards_review_forms SET used_at = %s WHERE token = %s AND used_at IS NULL
```

El comentario lo explica sin rodeos (`:230-234`): *dos envíos casi simultáneos pasaban las dos
validaciones y creaban dos reseñas (y dos premios); el `UPDATE … WHERE used_at IS NULL` deja pasar a
uno solo — la carrera la resuelve la base, no el orden de llegada*. El perdedor se queda con una foto
ya subida, así que el código la borra (`discard_orphan_photo`, `:244` y `:426-430`). Y si el INSERT de
la reseña falla, **devuelve el token al ruedo** y borra también la foto (`:255-256`).

La reseña nace con `comment_approved => 0` (`:226`), se le guarda el vínculo al pedido en la meta
`_akb_rewards_review_order` (`:262`), se le engancha la foto (`:265`) y se dispara a mano `comment_post`
(`:269`) para que WooCommerce escriba su marca de «compra verificada».

### 7. `Reviews\Media` — dónde vive la foto

**Qué hace en una frase:** pega la foto a la reseña con las claves heredadas del plugin viejo.

- **Fichero:** `src/Reviews/Media.php`.
- **Cuándo actúa:** no se engancha a nada; la llaman `ReviewForm` (`:265`) y `AdminReviews` (`:163`).
- **Qué escribe:** una fila de comment-meta por adjunto en `ivole_review_image2`
  (`:78`, `unique = false`) y el reparentado del adjunto al producto (`:80-87`). Nada más.
- **Las DOS claves, y las tres que se fueron.** La clase declara hoy **dos** constantes
  (`:34-35`): `ivole_review_image2` (foto local, la que escribe este plugin) e `ivole_review_image`
  (foto **remota** del plugin viejo, que sólo tiene lectores: la escribía el tercero, hoy
  desinstalado). **Hasta rewards 0.4.16** había cuatro constantes —las dos de vídeo,
  `ivole_review_video2` e `ivole_review_video`— y un recuento agregado `ivole_media_count` que las
  sumaba todas, más el método `refresh_media_count()` que lo mantenía. **Desde 0.4.17 no existe
  ninguna de las cinco cosas**: nadie del árbol propio las escribía ni las leía, así que se
  retiraron por la regla de cero código muerto (el fichero pasó a 91 líneas; lo cuenta su propio
  docblock, `:14-15`). Si alguien busca el contador en el código, no lo va a encontrar — y en las
  reseñas viejas de CusRev la meta puede seguir en la base de datos, sin que nadie la lea
  (corregido 2026-09-20, ronda 10).
- **La vara del premio tiene dos ramas, no una** (`has_real_photo()`, `:47-61`). La primera exige que
  el adjunto exista **y siga siendo una imagen** (`wp_attachment_is('image', …)`): un vídeo no cuenta.
  Pero la segunda acepta cualquier fila de la meta remota heredada `ivole_review_image` con un `url`
  no vacío — **sin comprobar que exista, ni que sea una imagen, ni que esté en el servidor**:

```php
foreach ( (array) get_comment_meta( $comment_id, self::META_REMOTE_IMAGE ) as $remote ) {
    if ( is_array( $remote ) && ! empty( $remote['url'] ) ) { return true; }
}
```

  La consecuencia es real: una reseña heredada de CusRev que sólo tenga foto remota **pasa la
  compuerta de la foto y cobra los 500 puntos** sin que haya ningún adjunto. Y el carrusel, que se
  hace la pregunta parecida «¿tiene foto que pintar?», usa **sólo** la rama local
  (`src/Reviews/Carousel.php:312-320`): esa misma reseña sale en la portada con la carátula del tomo,
  como si no tuviera foto. Dos preguntas distintas, dos respuestas distintas para la misma reseña.
  Ver defecto D23.
- **Dónde acaba la foto:** como el adjunto se reparenta al producto, la foto queda archivada **bajo
  ese tomo en la Biblioteca de Medios** (Medios, en el panel); lo que falta no es la foto, es el enlace
  desde la reseña.
- **La clase no escucha `delete_attachment`** y no tiene siquiera un método `register()`. Si el
  adjunto se borra, la fila `ivole_review_image2` queda mintiendo. El premio no se cae
  —`has_real_photo()` revalida con `wp_attachment_is('image', …)`—, pero el dato queda inconsistente.
  (Hasta 0.4.16 aquí también quedaba desfasado el contador `ivole_media_count`; desde 0.4.17 ese
  contador ya no existe.)

### 8. `Reviews\Moderation` — nada se publica sin el dueño

**Qué hace en una frase:** retiene toda reseña de producto hasta que el dueño la apruebe.

- **Fichero:** `src/Reviews/Moderation.php`.
- **Cuándo actúa:** un solo filtro, `pre_comment_approved:50` (`:37`).
- **Por qué la prioridad 50 y no una temprana:** en producción WordPress tiene encendido
  `comment_previously_approved`, que auto-aprueba a quien ya tenía un comentario aprobado; este filtro
  corre **después** y lo revierte (`:13-15`).
- **A qué alcanza** (`:52-75`): jamás rescata spam ni pisa un error; acepta los tipos `review`,
  `comment` y vacío —porque las rutas REST y XML-RPC no llegan etiquetadas como reseña y esquivaban la
  retención—; ignora las respuestas de un hilo; y sólo actúa si el destino es un producto.
- **Límite documentado y real:** `wp_insert_comment` directo **no pasa** por este filtro (`:9-12`). Por
  eso la ruta del formulario propio pone el 0 a mano.
- **Dónde lo opera el dueño:** casilla «Moderación obligatoria» (`akb_rewards_moderate_reviews`).

### 9. `Reviews\ReviewPoints` — quién cobra y cuándo

> **La reseña que deja de estar aprobada devuelve sus puntos (R4-144b3d33, ALTA, 0.4.13).** `maybe_reverse()`
> cuelga de `transition_comment_status` (a pendiente, spam o papelera) y de `delete_comment`: debita EXACTAMENTE lo
> pagado (`META_POINTS_PAID`), topado al saldo (nunca negativo; si falta, `akb_rewards_reversal_failed` → alerta al
> dueño), sella la reseña como `reversed`, descuenta el contador de la cadencia «cada N» y borra sus metas de
> conteo. Claves de evento por ocurrencia (`review_reversal_u_p_rN`, `review_u_p_rN`): re-aprobar vuelve a pagar
> una vez, y una reseña NUEVA del mismo tomo puede pagar tras la reversa (la clave antigua bloqueaba para siempre).
> Comparado con la competencia: myCred usa el mismo mecanismo de transición pero con una multa configurable y saldo
> negativo; WooCommerce Points and Rewards y YITH no documentan reversa. Prueba del ciclo aprobar → papelera →
> restaurar → papelera: termina en cero con cuatro movimientos.

**Qué hace en una frase:** decide, tras la aprobación, si una reseña paga 500 puntos.

- **Fichero:** `src/Reviews/ReviewPoints.php`.
- **Cuándo actúa** (`:51-60`): `transition_comment_status:10`, `comment_post:20`, `wp_insert_comment:20`
  y `woocommerce_customer_email_verified:20`.
- **Qué escribe:** el sello `_akb_rewards_review_points` (`:186`, `:197`, `:212`, `:248`, `:283`), el
  pin de cadencia `_akb_rewards_review_counted` (`:232`), el avance `_akb_rewards_review_progress`
  (`:249`), la marca `verified` (`:318`), la usermeta `_akb_rewards_review_qualifying` (`:231`) y la
  fila del ledger (`:256-269`).
- **Qué ve el cliente:** el saldo en Mi cuenta, y el correo del premio.

«Dejar huella» es una expresión que va a aparecer varias veces de aquí en adelante, y significa una
cosa muy concreta: **escribir el sello `_akb_rewards_review_points`, aunque sea con un 0**. Es lo
único que distingue una reseña que se evaluó y se descartó de una que nadie ha mirado todavía.

`maybe_pay()` (`:154-297`) es una escalera de **doce** compuertas, en el orden en que corren:

| # | Compuerta | Línea | Qué pasa si corta |
| --- | --- | --- | --- |
| 1 | El programa está encendido (`review_points() > 0`) | `:155-158` | Sale sin sello |
| 2 | Es de tipo `review` | `:160-162` | Sale sin sello |
| 3 | No es una respuesta dentro de un hilo | `:164-166` | Sale sin sello |
| 4 | El producto existe y está **publicado** | `:171-173` | Sale sin sello |
| 5 | No está ya resuelta (sello presente y distinto de `pending_account`) | `:175-178` | Sale |
| 6 | No la escribió la tienda (`_akb_rewards_admin_created`) | `:180-182` | Sale sin sello |
| 7 | No cobró ya el cupón del plugin viejo (`cr_coupon_code`) | `:184-188` | Sella con 0 |
| 8 | **Tiene foto real** | `:190-192` | Sale **sin sello y sin huella** |
| 9 | Hay una cuenta detrás (por `user_id` o por el correo del autor) | `:194-199` | Sella `pending_account`, que es reversible |
| 10 | **Compra verificada**, si el interruptor lo exige | `:201-203`, cálculo en `:309-325` | Sale sin sello |
| 11 | Tope histórico de premios por cliente | `:210-214` | Sella con 0 |
| 12 | Cadencia «cada N reseñas» | `:216-252` | Sella con 0 si aún no toca; **sale sin sello** si el candado por usuario está ocupado (`:237-242`) |

Las dos últimas son dos compuertas de verdad, en dos bloques distintos y con consecuencias distintas,
aunque la pantalla las presente juntas: el tope siempre deja huella, la cadencia puede no dejarla.

**Y hay una salida más, que no es una compuerta pero se le parece mucho:** ya pasadas las doce, si el
libro contable no responde con un número —una caída de la base de datos, por ejemplo— el código sale
en `:272-275` **también sin sellar**. Es decir: una reseña impecable y una caída de infraestructura
quedan exactamente igual de indistinguibles desde fuera.

Recién entonces `Ledger::credit(...)` con la clave de evento `review_u{usuario}_p{producto}` (`:268`).
Esa clave tiene un índice **único** en la tabla del libro contable
(`UNIQUE KEY event_key`, `src/Support/Schema.php:181`): **re-reseñar el mismo tomo no vuelve a pagar,
nunca**. Si de verdad se movió saldo (`:282`), se dispara `akb_rewards_review_points_paid` (`:296`),
que es lo que enciende el correo.

Dos finezas que vale la pena entender:

- La cadencia «cada N» corre **bajo un candado por usuario** (`Ledger::with_user_lock`, `:218-235`) y
  con un pin por reseña, porque el nacimiento dispara `maybe_pay` dos veces (`wp_insert_comment` y
  `comment_post`) y sin el pin la misma reseña consumía dos posiciones de la cadencia y perdía el
  premio (`:221-224`). **Todo ese bloque vive dentro de `if ( $every > 1 )` (`:216`)**, y
  `akb_rewards_review_every_n` vale **1** de fábrica: con la tienda como está hoy, el candado no se
  pide nunca y la rama «candado ocupado» es inalcanzable. Sólo existe si el dueño pone «premiar cada
  N» en 2 o más.
- El gancho del invitado que se hace cuenta es `woocommerce_customer_email_verified` y no
  `user_register`, **a propósito**: *pagar al registrarse permitía cosechar los puntos de otro
  registrando su email* (`:55-59`). La decisión de seguridad es correcta; la consecuencia práctica está
  en el defecto D5.

### 10. `Points\Emails` — el correo del premio

**Qué hace en una frase:** avisa al cliente de que su reseña sumó puntos.

- **Fichero:** `src/Points/Emails.php:355-398`; el enganche, en `:245`.
- **Cuándo actúa:** en el evento `akb_rewards_review_points_paid`, prioridad 100 — es decir
  **inmediatamente, en la misma petición en que el dueño aprueba la reseña**.
- **Asunto:** `[Akibara] Tu reseña sumó 500 Puntos Akibara` (`:389`). **Encabezado:** «Tu reseña sumó
  puntos» (`:390`).
- **A quién:** al correo de la cuenta; si no hay cuenta, al del autor de la reseña (`:365-369`).
- **Qué dice:** el título del tomo, los puntos sumados, el saldo actual, cuánto vale cada punto y un
  botón «Ver mis puntos» (`:376-385`). El comentario anota que *«este era el único correo del programa
  que llegaba sin un solo enlace»* (`:374-375`).
- **Qué escribe:** el sello `_akb_rewards_review_email_sent`, **sólo si el transporte confirmó**
  (`:395-397`).

Todos los correos del programa salen en dos partes, HTML y texto plano derivado del mismo HTML, para
que las dos versiones no puedan contradecirse.

### 11. `Reviews\ReviewNudge` — el empujón dentro del correo de entrega

**Qué hace en una frase:** añade un párrafo amarillo a los correos de WooCommerce que el dueño elija.

- **Fichero:** `src/Reviews/ReviewNudge.php`.
- **A quién NO le sale (0.4.9):** a quien pidió «no me pidan reseñas» (`InviteOptOut::opted_out()`): el
  propio panel llama a este párrafo «Invitación a reseñar en el correo».
- **Cuándo actúa:** `woocommerce_email_order_details:25` (`:33`).
- **Qué lee:** `akb_rewards_nudge_email_ids` (`:51`), `Options::review_points()` y
  `akb_rewards_review_nudge_enabled` (`:57-60`).
- **Qué ve el cliente:** *«📸 ¿Te gustó tu pedido? Cuéntanos con una foto: tu reseña con imagen vale 500
  Puntos Akibara (los acreditamos al aprobarla).»* (`:62-65`), con versión en texto plano (`:67-70`).
- **Nunca sale al admin** (`:53`).
- El propio docblock del módulo deja escrita la advertencia que abre el defecto D2: *«el estado
  terminal real de muchos pedidos es “Listo para enviar”, no completed — el dueño decide qué avisos lo
  llevan»* (`:48-50`).
- **Ese párrafo no lleva ni un enlace**, ni en HTML (`:72-77`) ni en texto plano (`:68-70`). Promete 500 puntos y no dice dónde reclamarlos. El
  objeto del pedido sí llega al método y se descarta a propósito en la primera línea, `unset( $order )`
  (`:46`). Ver defecto D8.

### 12. `Reviews\Carousel` — «Voces del Distrito» en la portada

**Qué hace en una frase:** pinta una pista horizontal de tarjetas de reseña en la portada.

- **Fichero:** `src/Reviews/Carousel.php`; animación opcional en `assets/js/carousel.js`.
- **Cuándo actúa:** registra el shortcode `akibara_home_reviews` (`:37`, `:49`) — **el mismo nombre que
  usaba el mu-plugin viejo, a propósito**, para no tener que tocar la portada en el cambio (`:16-19`).
- **Qué lee:** una sola consulta a la base (`:268-287`). Es **elegible** una reseña aprobada, de tipo
  `review`, de primer nivel, de un producto **publicado**, con estrellas entre 1 y 5, con al menos 40
  caracteres de texto y sin la meta `cr_coupon_code`.
- **Qué escribe:** el transient `akb_rewards_carousel_v1`, una hora (`:194`). Se tira a mano con
  `wp transient delete akb_rewards_carousel_v1`, o guardando la pantalla de ajustes, que hace
  exactamente lo mismo (`src/Support/SettingsPage.php:482-485`).
- **La compuerta que hoy lo apaga todo:** si hay menos de 8 elegibles, el shortcode devuelve cadena
  vacía (`:197-199`). Es una decisión explícita del dueño («cuando sobrepase las 7»): la portada no
  muestra una sección a medio llenar.
- **Invalidación:** seis ganchos (`:55-63`) — transición de estado del comentario, edición, inserción,
  borrado, cambio de estado del producto y borrado en firme de un producto. Cada invalidación tira
  también la copia de la portada en LiteSpeed (`:71-77`).
- **Animación:** sólo si el tema ya registró GSAP (`:214-231`); el carrusel jamás carga la librería por
  su cuenta y sin ella las tarjetas se ven igual. La pista funciona con `scroll-snap` de CSS puro. En
  la portada el tema **sí** la registra (`themes/akibara-gp/inc/enqueue.php:433-437` y la lista de
  superficies con rejilla de productos en `themes/akibara-gp/inc/page-targets.php:101-118`), así que
  ahí la animación corre.
- **Si una reseña no tiene foto propia**, la tarjeta usa la carátula del tomo, para que todas midan
  igual. Ojo con qué cuenta como «foto propia» aquí: el carrusel mira **sólo** la meta local
  `ivole_review_image2` (`:312-320`), mientras la compuerta del premio acepta además las fotos remotas
  heredadas. Ver defecto D23.
- **Y el dato que lo cambia todo:** el shortcode **no tiene un solo llamador en todo el árbol
  *first-party*** — o sea en el código propio de Akibara: los cuatro plugins, los 18 mu-plugins y el
  tema. El único resultado del barrido es su propia definición (`:37`), y el mu-plugin
  `akibara-home-reviews.php` que lo servía **ya no existe** en `mu-plugins/`. Si el post de la portada tampoco lo lleva, el carrusel no aparecerá ni con ochenta
  reseñas, y el umbral de 8 no sería la razón de que no se vea. Eso es contenido de la base de datos:
  **hay que medirlo**, el código no puede decidirlo.

### 13. `Reviews\Schema` — lo que ve Google

**Qué hace en una frase:** mete las estrellas y las cinco reseñas más nuevas en el bloque de datos que
Rank Math publica en cada ficha.

- **Fichero:** `src/Reviews/Schema.php`.
- **Cuándo actúa:** filtro `rank_math/snippet/rich_snippet_product_entity:50` (`:53`). La prioridad
  tardía es deliberada: los enriquecedores de akibara-business (libro, GTIN) corren antes y esto sólo
  pisa las claves de reseñas (`:42-44`).
- **Qué escribe:** `aggregateRating` con **exactamente tres claves** — `@type`, `ratingValue` (el texto
  crudo de dos decimales de WooCommerce, sin redondear) y `reviewCount` (`:80-84`) — y `review[]` con
  las **cinco más recientes** con estrellas mayores que cero (`:86-91`, consulta en `:106-124`).
- **Por qué entra por Rank Math y no imprime un bloque suelto:** un segundo nodo `Product` suelto sería
  una entidad duplicada a ojos de Google (`:20-25`).
- **La compuerta honesta:** si el producto no tiene ni un rating, **se borran `aggregateRating` y
  `review` de la entidad** (`:75-78`). Con cero reseñas en la tienda, hoy ninguna ficha publica
  estrellas — que es lo correcto, y a la vez la razón de que no haya estrellitas en Google.
- **Lo que filtra desde 0.4.1:** con `akb_rewards_schema_exclude_rewarded = yes` (panel → *Reseñas* →
  «Sacar de Google el texto de las reseñas premiadas»), `latest_reviews()` añade al `meta_query` dos
  cláusulas OR, una por `_akb_rewards_review_points` y otra por `_akb_rewards_review_counted` (NOT
  EXISTS, o NUMERIC <= 0: el sello `pending_account` castea a 0). Saca su **texto** de `review[]`;
  `aggregateRating` las sigue contando, porque es la misma nota y el mismo total de la ficha. De fábrica
  `no`, y entonces la consulta es idéntica a la del tercero. No excluye las transcritas por la tienda
  (ya se leen con su leyenda). Ver defecto D9.

### 13 bis. `Reviews\Disclosure` — la reseña incentivada lo dice *(0.4.1)*

**Qué hace en una frase:** es la única autoridad de «¿esta reseña fue incentivada?» y pone el aviso
donde el cliente la lee.

- **Fichero:** `src/Reviews/Disclosure.php`. Se registra en `src/Plugin.php` **fuera** de la compuerta
  del motor de reseñas, junto a `AdminReviews`: sólo lee la meta de lo ya pagado, y si Customer Reviews
  vuelve y el motor se duerme, una reseña premiada no puede perder su aviso.
- **Criterio (`notice_for()`):** `(int) _akb_rewards_review_points > 0` → `TEXTO_PREMIADA` («Quien
  escribió esta reseña ganó Puntos Akibara por publicarla con foto.»). Si no cobró pero
  `_akb_rewards_review_counted > 0` («premiar cada N»: sumó para el premio) → `TEXTO_SUMA` («…sumó para
  un premio de Puntos Akibara.»). `pending_account` y `0` sin avance → nada. La reseña que llegó
  después del tope no cobró ni sumó: no lleva aviso.
- **Superficies:** la ficha (`comment_text:24`, entre la leyenda de transcrita a 20 y la foto a 26), la
  tarjeta del carrusel del home (`Carousel::build_data()` guarda `aviso`; transient
  `akb_rewards_carousel_v2`) y la exclusión del JSON-LD y del carrusel, que usan el mismo criterio.
  También aparece en las pantallas de moderación y en `content:encoded` de los feeds (pasan por
  `comment_text`); los correos, el JSON-LD y el widget del escritorio usan el contenido crudo. Los
  bloques viejos de reseñas de la Store API no lo llevan, y en producción no hay ninguna página que los
  use (0 publicaciones, medido 2026-09-13).
- **Caché:** cuando el pago llega al verificar el correo del invitado (`on_email_verified()`), la reseña
  ya se veía sin aviso y no hay cambio de estado del comentario: se purga todo (`litespeed_purge_all`),
  porque la copia de invitado de la ficha no la retira otra purga. Cambiar
  `akb_rewards_schema_exclude_rewarded` en el panel también purga todo, sólo si cambió.
- **Interruptor `akb_rewards_disclosure_enabled`** (panel → *Reseñas* → «Avisar en la reseña que ganó
  puntos»), de fábrica **apagado**: decisión del dueño del 2026-09-13, tomada sabiendo que la guía de
  Google prohíbe las incentivadas no divulgadas y que el riesgo es perder los fragmentos de reseña.
  `visible_notice_for()` respeta el interruptor; `notice_for()` NO, porque el criterio de «fue
  incentivada» sigue gobernando las dos exclusiones del panel y la purga del pago tardío.
- **Pruebas:** `tests/Reviews/DisclosureTest.php`, `CarouselTest`, `SchemaTest`, `SettingsPageTest`,
  `ReviewPointsTest`; en el espejo, `tools/akibara-releases/rewards-qa/qa-divulgacion-resena.mjs`
  (ficha como invitado, «sumó», carrusel del home e interruptor del panel en los dos sentidos; el JSON-LD
  no se mide en local porque Rank Math está inactivo).

### 14. `Reviews\AdminReviews` — el dueño transcribe una reseña

**Qué hace en una frase:** permite publicar a mano la reseña y la foto que un cliente mandó por
Instagram o WhatsApp.

- **Fichero:** `src/Reviews/AdminReviews.php`.
- **Cuándo actúa:** `admin_menu`, `admin_post_akb_rewards_create_review` y `comment_text:20` (`:45-53`).
- **Dónde lo opera el dueño:** **Comentarios → Crear reseña** (`:61-69`), permiso `manage_woocommerce`.
  La dirección exacta es `/wp-admin/edit-comments.php?page=akb-rewards-new-review`; el identificador
  de la pantalla es `akb-rewards-new-review` (`:66`).
- **Qué pide** (`:290-301`): ID de producto, nombre a mostrar, estrellas, título opcional, texto, **foto
  obligatoria**, correo del cliente (opcional) y «¿dónde te la compartió?».
- **Sobrevive aunque el motor duerma** (`src/Plugin.php:92`): es la única superficie de reseñas que no
  está dentro del gate.

**Por qué esta pantalla cuelga de «Comentarios» y las reseñas se aprueban en «Productos».** Una reseña
es, técnicamente, un comentario de WordPress; pero WooCommerce las saca de la pantalla *Comentarios* y
las lista aparte, en **Productos → Valoraciones**. El propio texto de ayuda del plugin lo dice:
*«Toda reseña queda en espera hasta tu aprobación, en Productos → Valoraciones (WooCommerce no las
lista en Comentarios)»* (`src/Support/SettingsPage.php:217`). Esta pantalla de transcribir, en cambio,
es propia de Akibara y se cuelga del menú *Comentarios* porque ahí es donde WordPress deja colgar
pantallas nuevas de ese tipo. De ahí que el mismo circuito use dos menús distintos.

Tres decisiones importantes:

- La marca «compra verificada» **nunca es una casilla**: se calcula contra los pedidos reales del correo
  (`:176-185`). El comentario explica que marcarla a mano viola la normativa de publicidad y que
  Judge.me y Site Reviews eliminaron esa opción a propósito.
- Estas reseñas **jamás pagan puntos**: la meta `_akb_rewards_admin_created` las descarta en la
  compuerta 6 (`src/Reviews/ReviewPoints.php:180-182`).
- Llevan una **divulgación pública** en el texto (`:88-89`): *«📩 Reseña compartida con la tienda y
  publicada por Akibara a nombre del cliente.»*

Una nota fina: al crear la reseña, el módulo retira dos oyentes de WordPress para no mandarse un correo a sí mismo y luego los **repone incondicionalmente** (`:168-174`),
en vez de restaurar el estado previo. En un sitio donde alguien los hubiera retirado a propósito, esta
pantalla se los devuelve para el resto de la petición.

### 15. `Support\Privacy` — borrado y exportación *(rehecho en 0.4.9)*

**Qué hace en una frase:** engancha el flujo estándar de datos personales de WordPress y dice la verdad
completa de lo que el programa guarda.

- **Fichero:** `src/Support/Privacy.php`.
- **Cuándo actúa:** `delete_user`, `wp_privacy_personal_data_exporters` y
  `wp_privacy_personal_data_erasers`.
- **Dónde lo opera el dueño:** Herramientas → Exportar datos personales / Borrar datos personales.
- **Qué exporta:** saldo e historial (paginado de 200 en 200), el **día de cumpleaños** en castellano
  («14 de marzo»), el **código de padrino**, **quién invitó** a la persona —por nombre público, nunca
  por correo— y las **fotos que subió con sus reseñas**, también si compró como **invitada** (sin
  cuenta) y aunque la reseña esté en spam o en la papelera. A quién invitó ella **no** se lista: son
  terceros.
- **Qué borra:** lo que sólo describe a la persona — cumpleaños, código de padrino y vínculo con quien
  la invitó. Una sola lista (`CAMPOS_PERSONALES`) gobierna el exportador y el borrador, para que un
  campo nuevo no entre a uno y falte en el otro.
- **Qué retiene, y lo dice:** el historial contable, los **sellos de «ya se pagó»** (bono, cumpleaños,
  premio de referido: sin ellos el mismo premio se paga dos veces) y las fotos publicadas, con el
  correo de contacto para pedir su retiro. **Si se retiran o no es decisión del dueño** (la foto es
  requisito de la reseña).
- **La supresión se respeta después:** `erase()` deja el sello `_akb_rewards_privacy_erased`, y
  `stamp_identity()` —que al borrar una cuenta congela el correo en las notas del libro para que siga
  legible— **no estampa el correo** de quien pidió la supresión: escribe «cuenta borrada a petición de
  su titular». La primera versión de 0.4.9 prometía en el informe «sin tu identidad» y hacía lo
  contrario; lo cazó la revisión independiente antes de desplegar.

### 16. Piezas de apoyo

- **`Support\Log`** (`src/Support/Log.php:38-50`): manda los eventos críticos al registro de WooCommerce
  (WooCommerce → Estado → Registros) con origen `akibara-rewards`, y si el registrador de Woo no está,
  cae a `error_log` para que el evento no quede mudo. En el área de reseñas lo usan **dos** sitios: el
  token que no se pudo emitir (`src/Reviews/Invitations.php:230`) y el fallo de creación de la tabla
  (`src/Support/Schema.php:100-112`).
- **`Support\Assets`**: el `?ver=` del `carousel.js` combina versión del plugin y fecha del fichero,
  para que un arreglo sin subir la versión igual llegue al navegador.
- **`Support\OrderStates`**: la lista única de «pedido caído» que comparten tres módulos, para que una
  anulación no revierta los puntos y a la vez mande la invitación.
- **`Points\AccountRewards`** (capítulo 10): dueño del refresco de enlaces permanentes del que depende
  que `/resena/{token}/` exista (`:87-106`), y una de las superficies que prometen el premio (`:248`,
  `:271`).
- **`Points\ProgramPage`** (capítulo 10): la página pública `/akibara-puntos/`, con la fila «Reseña
  con foto aprobada» condicionada a `reviews_lane_on()` (`:274-282`).
- **`Points\Ledger`** (capítulo 10): la contabilidad, en la tabla `{prefijo}akb_rewards_ledger`. El
  premio de reseña es una fila con `action='review'`, `ref_type='review'` y `ref_id` = el ID de la
  reseña.
- **Tema `akibara-gp`**: encola `assets/css/reviews-p5-v4.css` en **toda** ficha de producto
  (`themes/akibara-gp/functions.php:76-83`). Ese CSS da estilo de marca a la lista **nativa** de
  reseñas de WooCommerce (`#reviews .commentlist li.review`, `.star-rating`, …). Dicho de otro modo:
  la ficha **sí** pinta una lista de reseñas, y ahí no hay ni hueco para la foto ni campo para
  subirla. Hoy son unos 3,9 KB cargados en las ~1.600 fichas de una tienda con cero reseñas.
- **`themes/akibara-gp/customer-reviews-woocommerce/form.css`**: 203 líneas que sobrescribían la
  pantalla del plugin comprado, y cuya primera línea es un `@import` a una ruta dentro de ese plugin.
  Si CusRev ya no está —que es la condición para que el motor propio despierte—, este fichero es código
  muerto y su importación apunta a un 404.
- **`Akibara\Business\…\CompletionPolicy`** (otro plugin nuestro, capítulo 04): la compuerta de
  preventa. `can_request_review()` (`plugins/akibara-business/src/Modules/OrderPreventa/CompletionPolicy.php:170-175`)
  exige, para un pedido con preventa, **dos cosas a la vez**: `can_complete( $order )` **y** que el
  pedido esté en `completed`. Y la primera es más floja de lo que suena: `can_complete()` (`:156-162`)
  se cumple con que **no queden ítems pendientes _o_ que la marca `_akb_eta_ready_to_ship` valga
  `yes`**. Dicho de otro modo: después de que el cron voltea esa marca, la primera mitad ya está
  satisfecha y lo único que falta es el estado. Esa precisión es la que explica por qué el arreglo del
  defecto D2 es de verdad una línea.
- **`Akibara\Business\…\ReadyToShipCron`** (otro plugin nuestro, capítulo 04): el cron que voltea la marca
  `_akb_eta_ready_to_ship` a «sí» **sin cambiar el estado del pedido** y dispara el evento
  `akb_order_preventa_marked_ready` (`plugins/akibara-business/src/Modules/OrderPreventa/ReadyToShipCron.php:184-196`).
  Ese evento es la puerta de reentrada que hoy nadie usa desde reseñas.

---

## Lo programado

![Reloj del capítulo 11](diagramas/11-rewards-resenas-cron.mmd)

Este área **no tiene ninguna corrida con hora fija de Chile**. Tiene dos trabajos de fondo: uno que se
agenda por un hecho (la invitación) y la limpieza diaria de los enlaces, con acción propia desde 0.4.9.

| Trabajo | Mecanismo | Frecuencia y hora Chile | Quién lo programa | Qué hace | Si corre dos veces |
| --- | --- | --- | --- | --- | --- |
| `akb_rewards_send_review_invite` | Action Scheduler, acción única con argumento `[order_id]` | **Sin hora fija**: a los `delay_days` × 24 h (3 días de fábrica) del momento en que el pedido entró al estado disparador. En fallo de correo, reintento a +6 h, tope 3 | `Invitations::on_status_changed` (`src/Reviews/Invitations.php:140-144`) | Emite el token y manda `[Akibara] ¿Qué te pareció tu pedido?` | **Seguro**: sale a la primera línea si el pedido ya tiene `_akb_rewards_invite_sent` (`:166-168`). Y antes de agendar comprueba que no haya otro trabajo en cola (`:136-138`) |
| `akb_rewards_review_tokens_cleanup` | Action Scheduler, acción **recurrente** | Cada 24 h, anclada a tres horas después del primer arranque que la armó | `TokenCleanup::ensure_scheduled()` en `init:20`, sólo en el panel, el cron o la línea de comandos, con memo de 12 h. Se registra **fuera** del gate del motor de reseñas | Borra hasta 500 tokens vencidos hace más de seis meses y nunca usados (`Schema::purge_stale_review_tokens()`) | **Seguro**: el `DELETE` es idempotente y acotado con `LIMIT 500` |
| `action_scheduler_run_queue` | El latido de WooCommerce | **Declarado** cada minuto; **en la práctica, cada 5 minutos**, porque `DISABLE_WP_CRON` está encendido en producción y el único que despierta a WordPress es la tarea de Hostinger cada 5 minutos | WooCommerce | Es el motor que ejecuta los dos de arriba | — |

**Cómo se leen esas dos frecuencias juntas**, porque parecen contradecirse: Action Scheduler se
declara a sí mismo «cada minuto», pero sólo corre cuando alguien despierta a WordPress, y en esta
tienda el único que lo hace es Hostinger cada 5 minutos. Así que «a los 3 días» significa, en la
práctica, «en la primera corrida después de cumplirse el plazo», con hasta cinco minutos de retraso.

**Lo que cambió en 0.4.9:** la limpieza de los enlaces dejó de colgar del vencimiento de puntos. Tiene su
propia acción y no la apaga ningún interruptor del panel (era el defecto D10).

---

## Casos de uso

### Caso 1 — «Compré y tres días después me pidieron mi opinión» *(cliente)*

![Caso: la invitación](diagramas/11-rewards-resenas-caso-invitacion.mmd)

María compra el tomo 12 de *Jujutsu Kaisen*. Paga, el pedido se prepara y sale. Cuando el pedido
**entra al estado que el dueño configuró** (de fábrica «Completado»), WordPress avisa y
`Invitations::on_status_changed` se despierta (`src/Reviews/Invitations.php:100`).

- ¿Las invitaciones están encendidas? Sí → sigue.
- ¿El pedido cayó? No → sigue. (Si hubiera caído, se desagenda lo pendiente, `:110-113`.)
- ¿El estado nuevo es el configurado? Sí → sigue.
- ¿El id todavía resuelve a un pedido de verdad? Sí → sigue. (Si el pedido está en la papelera, aquí
  se acaba en silencio, `:119-122`.)
- ¿Ya se le invitó alguna vez en la historia del pedido? No → sigue.
- ¿Tiene preventa pendiente? Si la tuviera, aquí se acabaría el camino (Caso 11 y defecto D2).
- ¿Ya hay un trabajo en cola? No → **agenda para dentro de 3 días** y anota
  `_akb_rewards_invite_scheduled`.

Tres días después, la cola ejecuta `send_invite` (`:156`). Vuelve a comprobar todo, porque el pedido
pudo caerse en esos tres días; crea un enlace único de 40 caracteres que vence en 30 días
(`:213-235`); y manda el correo.

**Correo que sale:** asunto **`[Akibara] ¿Qué te pareció tu pedido?`**, encabezado «Cuéntanos qué te
pareció», tres días después del cambio de estado. Si el transporte no confirma, el pedido **no** se
marca como invitado y el trabajo se reagenda a 6 horas, hasta 3 intentos (`:198-204`).

*Resultado:* María tiene en su bandeja un enlace personal a `akibara.cl/resena/xxxxxxxx/`.

*Bifurcación que hoy importa mucho:* con el valor de fábrica (`completed`), un pedido que muere en
«Listo para enviar» **nunca entra a este camino**. Ver defecto D1.

### Caso 2 — «Escribí mi reseña y subí la foto» *(cliente)*

![Caso: reseña y premio](diagramas/11-rewards-resenas-caso-resena-y-premio.mmd)

María entra al enlace. La página es oscura, con el logo AKIBARA arriba, y dice «Tu reseña del pedido
#12345». Ve los tomos de su pedido como opciones, cinco estrellas, un cuadro de texto y un campo de
foto marcado como obligatorio.

Las bifurcaciones que el código contempla, con el mensaje exacto que María lee:

| Qué pasa | Qué lee | Línea |
| --- | --- | --- |
| El enlace ya se usó o venció | «Este enlace ya no sirve» + botón «Ir a mis pedidos» | `src/Reviews/ReviewForm.php:556-563` |
| Devolvieron el pedido entero | La misma página de enlace inválido (antes se pintaba un formulario imposible de enviar) | `:488-493` |
| El nonce no valida | «La sesión del formulario caducó: vuelve a intentarlo.» | `:166-168` |
| Elige un producto que no es de su pedido | «Elige uno de los productos de tu pedido.» | `:175-178` |
| No elige estrellas válidas | «Elige una calificación de 1 a 5 estrellas.» | `:180-183` |
| Pone menos de 10 caracteres | «Cuéntanos un poco más: al menos 10 caracteres.» | `:185-188` |
| **No sube foto** | «Falta la foto de tu compra. La pedimos siempre: una foto real del tomo en tus manos es lo que hace útil tu reseña para quien duda si comprarlo.» | `:205-207` |
| La foto pesa más de 5 MB | «La foto no se pudo subir: la imagen supera los 5 MB.» | `:209-212`, `:358-360` |
| Pulsa «Enviar» dos veces seguidas | El segundo envío pierde la carrera y lee «Este enlace ya se usó o caducó»; su foto huérfana se borra sola | `:235-246` |
| El guardado de la reseña falla | «No pudimos guardar tu reseña; inténtalo otra vez.» — y el enlace vuelve a servir | `:249-258` |

Cuando todo va bien: se sube la foto, se quema el token, nace la reseña **en espera**, se le pega la
foto y se anota de qué pedido vino. María lee (`:537-549`):

> ¡Gracias! 🎉
> Tu reseña quedó en revisión: la publicamos apenas la aprobemos **y ahí mismo se acreditan tus
> puntos**.

*Correos en este caso:* **ninguno al cliente**. Al dueño le puede llegar el aviso de moderación estándar
de WordPress («hay un comentario esperando aprobación»), inmediatamente, porque el formulario dispara
`comment_post` a mano (`:269`) — pero **sólo si las opciones de aviso del propio WordPress están
encendidas**, que no están en la pantalla del dueño. Ver defecto D6.

### Caso 3 — «Aprobé la reseña y se le pagaron los puntos» *(dueño)*

![Caso: reseña y premio](diagramas/11-rewards-resenas-caso-resena-y-premio.mmd)

El dueño entra a **Productos → Valoraciones** —ahí es donde WooCommerce lista las reseñas, no en
*Comentarios*—, lee la reseña de María y pulsa «Aprobar». WordPress dispara
`transition_comment_status` y `ReviewPoints::maybe_pay` recorre sus doce compuertas.

Bifurcaciones reales:

- Si **no tiene foto real**: no paga, y **no queda ninguna huella ni aviso** (`ReviewPoints.php:190-192`).
- Si María **no tiene cuenta**: se sella `pending_account` y se queda esperando (Caso 4 y defecto D5).
- Si María **no compró ese tomo** y la compuerta de compra verificada está encendida: no paga
  (`:201-203`).
- Si el dueño puso «premiar cada 3 reseñas» y ésta es la primera: se sella con 0 y se anota «1/3», un
  dato que **nadie ve en ninguna pantalla** (`:248-249`, defecto D11).
- **Sólo si el dueño puso «premiar cada N» mayor que 1** —con el 1 de fábrica esta rama no existe— y
  el candado por usuario está ocupado: sale **sin sellar** y queda indistinguible de una reseña sin
  evaluar (`:237-242`). Ahí se dispara el gancho `akb_rewards_review_pay_failed`, y ese gancho **sí
  tiene oyente**: `Support\Alerts::on_review_pay_failed()` (`src/Support/Alerts.php:47`) escribe
  siempre la línea en el registro de WooCommerce y, si «Alertas» está encendida y hay destinatarios,
  le manda al dueño un correo —una vez al día por reseña— con el enlace y la instrucción de
  desaprobar y volver a aprobar (`:295` y siguientes). Lo que **no** hay es aviso en pantalla.
- Si el **libro contable no responde** —una caída de base de datos— después de las doce compuertas:
  también sale sin sellar (`:272-275`), con el mismo gancho, que avisa igual. Lo que se parecen desde
  la pantalla de reseñas son una reseña perfecta y una caída de infraestructura: la diferencia está
  en el registro y en el correo de alerta, no en la lista (corregido 2026-09-20, ronda 10 — este
  párrafo daba el gancho por mudo).
- Si ya cobró por ese mismo tomo: el índice único de la tabla del libro contable lo bloquea
  (`src/Support/Schema.php:181`), se sella con 0 y el saldo no se mueve (`:278-283`).

Si pasa todo: **+500 puntos** en el ledger y sale de inmediato el correo **`[Akibara] Tu reseña sumó
500 Puntos Akibara`** (`src/Points/Emails.php:389`), con el título del tomo, el saldo nuevo y el botón
«Ver mis puntos». En paralelo, la aprobación tira el caché del carrusel y purga la copia de la portada
en LiteSpeed (`src/Reviews/Carousel.php:55`, `:71-77`).

**Y lo que no pasa nunca:** si el dueño después des-aprueba, marca como spam o borra esa reseña, **los
500 puntos se quedan pagados**. No hay ninguna reversa. Ver defecto D4.

### Caso 4 — «Reseñé como invitada y después me hice cuenta» *(cliente)*

María compró sin crear cuenta. Su reseña se aprueba, pero no hay usuario detrás: queda sellada
`pending_account` (`src/Reviews/ReviewPoints.php:194-199`). Meses después crea su cuenta con el mismo
correo.

El código espera aquí el gancho `woocommerce_customer_email_verified` (`:59`), elegido a propósito en
vez de `user_register` *porque pagar al registrarse permitiría cosechar los puntos de otro registrando
su email*. Cuando ese gancho llega, busca todas sus reseñas aprobadas con el sello pendiente y las
vuelve a evaluar.

**El problema es cuándo llega ese gancho**, y es la mitad del caso que el snapshot no puede cerrar: en
WooCommerce 11 ese aviso sale cuando el cliente completa un restablecimiento de contraseña, o cuando
pide y pulsa un enlace de verificación que sólo aparece como invitación opcional en Mi cuenta →
Pedidos. Crear la cuenta en el checkout **no lo dispara**. Hay que medirlo. Ver defecto D5.

*Correos en este caso:* ninguno, mientras el sello siga puesto. Ése es exactamente el problema.

### Caso 5 — «Me llegó una reseña por Instagram y la publiqué yo» *(dueño)*

El dueño va a **Comentarios → Crear reseña**, pone el ID del tomo, el nombre del cliente, las
estrellas, el texto, **sube la foto que el cliente le mandó**, y anota en «¿dónde te la compartió?»
algo como «Instagram DM 30-08».

El código comprueba que el producto exista (`src/Reviews/AdminReviews.php:100-103`), valida las
estrellas 1-5 (`:105-108`) —a diferencia del plugin viejo, que aceptaba cualquier número—, exige nombre
y texto (`:110-118`), exige la **foto** (`:120-124`), crea la reseña **ya aprobada** (`:141`, dentro
del `wp_insert_comment` de `:151`), calcula
solo la marca de compra verificada contra los pedidos reales del correo (`:176-185`), guarda la
procedencia (`:187-192`) y recalcula el promedio de estrellas del producto (`:195`).

Esa reseña **no paga puntos** y lleva pegada, en la ficha del producto, la línea «📩 Reseña compartida
con la tienda y publicada por Akibara a nombre del cliente» (`:88-89`). Si el formulario falla a mitad,
la foto que ya se había subido se borra sola.

*Correos en este caso:* ninguno. El módulo retira a propósito los dos avisos de WordPress para que el
dueño no se mande un correo a sí mismo (`:168-174`).

*Bifurcación conocida:* la pantalla del dueño sólo ofrece `image/jpeg,image/png,image/webp` (`:299`),
mientras el formulario público acepta además HEIC. Ver defecto D13.

### Caso 6 — «Entré a la portada» *(cliente)*

El shortcode `akibara_home_reviews` se ejecuta. Mira su caché de una hora; si no hay, hace una sola
consulta que trae hasta 8 filas elegibles (`src/Reviews/Carousel.php:186-204`). **Si vuelven menos de
8, no pinta absolutamente nada** — la portada queda como si la sección no existiera. Hoy, con cero
reseñas, eso es exactamente lo que pasa.

Con 8 o más: pinta el título «Voces del Distrito», el subtítulo «La comunidad Akibara opina», y una
pista horizontal con tarjetas de foto + estrellas + texto recortado + nombre + enlace al tomo. La pista
es alcanzable con el teclado y cada tarjeta lleva su texto para lectores de pantalla.

**Antes de medir el umbral hay que comprobar otra cosa:** que el post de la portada lleve el shortcode.
Nadie lo invoca desde el código, y el mu-plugin que lo servía ya no existe. Si el contenido de la
portada tampoco lo lleva, la sección no aparecerá nunca y el umbral no tendría nada que ver.

### Caso 7 — «Google indexó la ficha» *(sistema)*

Rank Math construye el bloque de datos estructurados de la ficha del tomo. Justo antes de imprimirlo,
`Reviews\Schema` entra (`:53`) y, si el tomo tiene al menos un rating, reemplaza `aggregateRating` y
`review[]` por la forma exacta que servía el plugin viejo. Si no tiene ninguno, **los borra**
(`:75-78`). Con cero reseñas, ninguna ficha publica estrellas.

Cuando sí haya reseñas, esta ruta publica las cinco más recientes **sin distinguir** si fueron
premiadas con 500 puntos o transcritas por la tienda. Ver defecto D9.

### Caso 8 — «Apagué el carril desde el panel» *(dueño)*

El dueño desmarca «Dar puntos por reseña con foto» y guarda. A partir de ese instante: el correo de
invitación deja de prometer puntos, el formulario deja de mostrar el recuadro, la página de
agradecimiento deja de decir «y ahí mismo se acreditan tus puntos», Mi cuenta y la página pública del
programa borran esa fila, y el párrafo del correo de entrega desaparece (porque
`Options::review_points()` devuelve 0 y `ReviewNudge` lo comprueba en `:57-60`).

**Pero el formulario sigue exigiendo la foto sin explicar por qué**, porque esa guarda no depende del
carril (`src/Reviews/ReviewForm.php:205-207`). Es una pregunta de negocio abierta.

El guardado también tira el caché del carrusel, para que el clic rija al instante
(`src/Support/SettingsPage.php:482-485`).

### Caso 9 — «Pedí que borraran mis datos» *(cliente)*

El flujo estándar de WordPress le entrega saldo, historial, cumpleaños, código de padrino, quién la
invitó y las fotos de sus reseñas (tenga cuenta o no). Al borrar, se van el cumpleaños, el código y el
vínculo de referido; se quedan —y el informe lo dice con su porqué— el historial contable, las marcas
de premio ya pagado y las fotos publicadas, con el correo para pedir que se retiren. Si después el dueño
elimina la cuenta, el libro no guarda su correo.

### Caso 10 — «Hice clic en un enlace viejo del sistema anterior» *(cliente)*

Cae en `/cusrev/{id}/` y lee: «Cambiamos de sistema de reseñas… puedes reseñar desde la ficha del
producto o desde tus pedidos» (`src/Reviews/ReviewForm.php:570-577`), con un botón a sus pedidos. Nunca
un 404.

Pero esa ficha del producto **no tiene campo de foto** —el tema sólo estiliza la lista nativa de
WooCommerce—, así que una reseña escrita ahí nunca pasará `Media::has_real_photo()` y nunca cobrará los
500 puntos que las otras seis superficies prometen, sin que el cliente reciba ninguna explicación. Ver
defecto D14.

### Caso 11 — «Mi pedido era de preventa» *(cliente, y es el callejón sin salida)*

![Caso: la preventa que se pierde](diagramas/11-rewards-resenas-caso-preventa.mmd)

El pedido de María incluye un tomo en preventa. La compuerta de akibara-business
(`CompletionPolicy::can_request_review()`) exige, para un pedido con preventa, **dos** cosas a la vez
(`plugins/akibara-business/src/Modules/OrderPreventa/CompletionPolicy.php:170-175`): que
`can_complete()` diga que sí **y** que el pedido esté en `completed`. La primera es la floja:
`can_complete()` (`:156-162`) se cumple si **no quedan ítems pendientes _o_ si la marca
`_akb_eta_ready_to_ship` vale `yes`**. O sea que, apenas el cron de preventa voltea esa marca, la
mitad de la exigencia ya está cumplida y lo único que falta es el estado del pedido.

- **Con el disparador en `completed`** (el valor de fábrica): los pedidos de preventa que nunca llegan
  a `completed` no se invitan nunca.
- **Con el disparador en `shipping-progress`** (que es el estado terminal real de muchos pedidos): en
  ese instante la compuerta devuelve «no», porque el pedido no está en `completed`. Se sale sin
  agendar. Y la única forma de volver a entrar sería que el pedido entrase **otra vez** al estado
  disparador — que ya pasó.

Encima, el texto de ayuda de la pantalla promete lo contrario: *«Las preventas siempre esperan su
entrega, sea cual sea el estado elegido»* (`src/Support/SettingsPage.php:161`). No esperan: se pierden.

**Y lo que cambia el arreglo entero:** cuando el cron de preventa marca el pedido como listo, dispara
un evento con el id del pedido —
`do_action( 'akb_order_preventa_marked_ready', $order->get_id() )`
(`plugins/akibara-business/src/Modules/OrderPreventa/ReadyToShipCron.php:196`)— y el único oyente en
todo el árbol es `ReadyEmailDispatcher` del propio akibara-business. **akibara-rewards no lo escucha.**
El arreglo no es rediscutir la compuerta de otro plugin con contrato sellado: es añadir
`add_action( 'akb_order_preventa_marked_ready', … )` en `Invitations` y re-evaluar ahí.

### Caso 12 — «Limpieza nocturna» *(sistema)*

Una vez al día, la acción propia `akb_rewards_review_tokens_cleanup` (`src/Reviews/TokenCleanup.php`)
borra hasta 500 tokens de invitación que vencieron hace más de seis meses y nunca se usaron. Los usados
se conservan para la auditoría. Desde 0.4.9 **no depende de ningún otro interruptor**: antes era una
línea dentro del cron de vencimiento de puntos, y apagar «Hacer vencer los puntos» dejaba la tabla
creciendo para siempre.

### Caso 13 — «No quiero que me pidan reseñas» *(cliente, 0.4.9)*

Cada invitación trae al pie «¿Prefieres que no te pidamos reseñas? Dínoslo aquí», y la cabecera
`List-Unsubscribe` que hace aparecer el «Cancelar suscripción» de Gmail. El enlace abre una página
bajo Mi cuenta que **pregunta antes de hacer nada** (un GET que cambia estado lo dispara cualquier
antivirus de enlaces); el botón da de baja, y en la misma página se puede deshacer. El «un clic» de
Gmail da de baja directo. Desde ahí, a esa dirección no le llega la invitación **ni el párrafo «tu
reseña vale N puntos»** de los correos de pedido. Los correos del pedido y de los puntos siguen.

El dueño ve cuántos la usaron en WooCommerce → Puntos y Reseñas, bajo «Invitar a reseñar por correo».

---

## Estados por los que pasa una reseña y su enlace

![Máquina de estados](diagramas/11-rewards-resenas-estados.mmd)

Dos ciclos distintos conviven y conviene no mezclarlos:

- **El enlace (token)**: nace *emitido* con fecha de caducidad; pasa a *usado* cuando el `UPDATE …
  WHERE used_at IS NULL` lo gana (`src/Reviews/ReviewForm.php:398-412`); puede volver a *emitido* si la
  reseña no llegó a nacer (`release_token`, `:438-449`); se vuelve *vencido* por el paso del tiempo
  (`:147-149`); y desaparece si estaba vencido, sin usar y con más de seis meses.
- **La reseña**: nace *en espera* (`comment_approved = 0`); pasa a *aprobada* cuando el dueño la acepta;
  al aprobarse se **resuelve el premio** en uno de cuatro sellos — *pagada* (el valor en puntos),
  *descartada con huella* (0), *pendiente de cuenta* (`pending_account`, reversible) o **sin sello**
  (cuando la corta una compuerta que no deja huella). Ese cuarto estado es el que hace invisible un
  problema: una reseña sin foto, sin compra verificada, de un producto despublicado, con el candado
  ocupado —sólo si «premiar cada N» es mayor que 1— o víctima de una caída del libro contable, es
  indistinguible de una que nadie ha evaluado todavía.
- **Y la vuelta atrás que sorprende:** una reseña rechazada puede volver a aprobarse, pero si el sello
  ya está escrito, la segunda aprobación **no vuelve a evaluar el premio** — la compuerta 5 corta
  antes (`src/Reviews/ReviewPoints.php:175-178`). Arrepentirse sale gratis; arrepentirse dos veces, no.

---

## Reglas de negocio

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
| --- | --- | --- | --- |
| El motor propio de reseñas duerme mientras *CusRev* esté instalado | `src/Support/Options.php:167` | Sólo por filtro de código `akb_rewards_reviews_engine_on` | — |
| Con *Simple Points and Rewards* activo, el plugin entero no arranca | `akibara-rewards.php:127-140` | No | — |
| La invitación sale N días después de que el pedido entre a un estado | `src/Reviews/Invitations.php:140-144` | Sí | «Días de espera» y «Estado que dispara» |
| Una sola invitación por pedido en toda su historia | `src/Reviews/Invitations.php:124-126` | No | — |
| Un solo trabajo en cola por pedido | `src/Reviews/Invitations.php:136-138` | No | — |
| Un pedido caído (cancelado/reembolsado/fallido/papelera) desagenda la invitación | `src/Reviews/Invitations.php:110-113` | No | — |
| Un pedido con preventa pendiente no se invita | `src/Reviews/Invitations.php:131-134`; `plugins/akibara-business/.../CompletionPolicy.php:170-175` | Por filtro `akb_order_preventa_can_request_review` | — |
| Máximo 3 intentos de envío, reintentando cada 6 horas | `src/Reviews/Invitations.php:43`, `:198-204` | No | — |
| El pedido sólo se marca «invitado» si el correo salió de verdad | `src/Reviews/Invitations.php:192-195` | No | — |
| El enlace personal vence a los 30 días | `src/Reviews/Invitations.php:78-80`; `src/Reviews/ReviewForm.php:147-149` | Sí | «Vida del enlace de reseña (días)» |
| El enlace es de un solo uso, resuelto por la base de datos | `src/Reviews/ReviewForm.php:398-412` | No | — |
| La reseña necesita estrellas de 1 a 5 | `src/Reviews/ReviewForm.php:180-183` | No | — |
| La reseña necesita al menos 10 caracteres | `src/Reviews/ReviewForm.php:185-188` | No | — |
| **La foto es obligatoria** | `src/Reviews/ReviewForm.php:205-207` | No | — |
| La foto pesa como máximo 5 MB | `src/Reviews/ReviewForm.php:40`, `:358-360` | No | — |
| Tipos aceptados en el formulario público: jpg, jpeg, jpe, png, webp, heic | `src/Reviews/ReviewForm.php:374-379` | No | — |
| Un tomo devuelto entero no se puede reseñar | `src/Reviews/ReviewForm.php:318-327`, `:475-478` | No | — |
| Toda reseña del formulario nace pendiente de aprobación | `src/Reviews/ReviewForm.php:226` | No — el interruptor no la gobierna | — |
| Todo comentario de primer nivel sobre un producto se retiene | `src/Reviews/Moderation.php:47-75` | Sí | «Moderación obligatoria» |
| El premio se paga **sólo tras la aprobación** | `src/Reviews/ReviewPoints.php:70-78` | No | — |
| El premio son 500 puntos | `src/Support/Options.php:284-289` | Sí | «Puntos por reseña con foto aprobada» |
| Sin foto real no hay premio (un vídeo no cuenta; una foto remota heredada de CusRev **sí**, sin validarse) | `src/Reviews/ReviewPoints.php:190-192`; `src/Reviews/Media.php:45-59` | No | — |
| Una respuesta dentro de un hilo nunca cobra | `src/Reviews/ReviewPoints.php:164-166` | No | — |
| El producto debe estar publicado | `src/Reviews/ReviewPoints.php:171-173` | No | — |
| Las reseñas escritas por la tienda no cobran | `src/Reviews/ReviewPoints.php:180-182` | No | — |
| Una reseña que ya cobró el cupón del plugin viejo no cobra puntos | `src/Reviews/ReviewPoints.php:184-188` | No | — |
| Hace falta compra verificada | `src/Reviews/ReviewPoints.php:309-325` | Sí | «Exigir compra verificada» |
| «Compra verificada» se calcula, nunca se marca a mano | `src/Reviews/ReviewPoints.php:316-322`; `src/Reviews/AdminReviews.php:176-185` | No | — |
| Un solo premio por cliente-y-producto en toda la historia | `src/Reviews/ReviewPoints.php:268` arma la clave; el índice único está en `src/Support/Schema.php:181` | No | — |
| Se puede premiar «cada N reseñas» | `src/Reviews/ReviewPoints.php:216-252`; `src/Support/Options.php:343-345` | Sí | «Premiar cada cuántas reseñas» |
| Se puede topar el número de premios por cliente | `src/Reviews/ReviewPoints.php:210-214`; `src/Support/Options.php:352-354` | Sí | «Tope de premios por cliente» |
| Sin cuenta todavía, el premio queda en espera reversible | `src/Reviews/ReviewPoints.php:194-199` | No | — |
| El invitado cobra al **verificar** su correo, no al registrarse | `src/Reviews/ReviewPoints.php:55-59` | No | — |
| Los puntos de una reseña **no se revierten nunca** | `src/Reviews/ReviewPoints.php:51-60` (no hay hook de reversa) | No | — |
| El correo del premio se sella sólo con transporte confirmado | `src/Points/Emails.php:395-397` | No | — |
| El carrusel aparece sólo con 8 o más reseñas elegibles | `src/Reviews/Carousel.php:197-199`; `src/Support/Options.php:363-365` | Sí | «Mínimo de reseñas para aparecer» |
| Elegible = aprobada, review de primer nivel, producto publicado, estrellas 1-5, texto ≥ 40 caracteres, sin cupón viejo | `src/Reviews/Carousel.php:262-287` | Sólo el largo mínimo | «Largo mínimo del texto» |
| Se pintan como mucho 8 tarjetas, con tope duro de 20 | `src/Support/Options.php:321-323` | Sí | «Tarjetas máximas» |
| Las reseñas premiadas con puntos sí entran al carrusel por defecto | `src/Reviews/Carousel.php:249-260` | Sí | «Excluir reseñas premiadas con puntos» |
| Sin foto de reseña, la tarjeta usa la carátula del tomo | `src/Reviews/Carousel.php:327-333` | No | — |
| El bloque para Google publica 5 reseñas y un `aggregateRating` de 3 claves | `src/Reviews/Schema.php:80-91`, `:106-124` | No | — |
| Sin ratings no se publica ni `aggregateRating` ni `review` | `src/Reviews/Schema.php:75-78` | No | — |
| La reseña transcrita por el dueño exige foto y estrellas 1-5 | `src/Reviews/AdminReviews.php:105-124` | No | — |
| Las reseñas transcritas llevan divulgación pública en la ficha | `src/Reviews/AdminReviews.php:88-89` | No | — |
| Tokens vencidos hace más de 6 meses y sin usar se borran, 500 por corrida | `src/Support/Schema.php:71-76` | No | — |
| Desinstalar el plugin no borra nada salvo constante explícita en `wp-config.php` | `uninstall.php:18-20` | No | — |

---

## Datos que deja

**Options** (todas con casilla en WooCommerce → Puntos y Reseñas):

| Option | Tipo | Fábrica | Quién la lee |
| --- | --- | --- | --- |
| `akb_rewards_invite_enabled` | sí/no | `yes` | `Invitations::enabled()` (`:50-52`) |
| `akb_rewards_invite_status` | estado de pedido | `completed` | `Invitations::trigger_status()` (`:59-62`) |
| `akb_rewards_invite_delay_days` | entero | 3 | `Invitations::delay_days()` (`:69-71`) |
| `akb_rewards_invite_token_days` | entero | 30 | `Invitations::token_days()` (`:78-80`) |
| `akb_rewards_reviews_enabled` | sí/no | `yes` | `Options::review_points()` (`:284-286`) |
| `akb_rewards_review_points` | entero | 500 | `Options::review_points()` (`:288`) |
| `akb_rewards_review_every_n` | entero | 1 | `Options::review_every_n()` (`:343-345`) |
| `akb_rewards_review_max_per_user` | entero | 0 | `Options::review_max_per_user()` (`:352-354`) |
| `akb_rewards_review_require_verified` | sí/no | `yes` | `ReviewPoints::verified_purchase()` (`:310-312`) |
| `akb_rewards_moderate_reviews` | sí/no | `yes` | `Moderation::hold_product_reviews()` (`:48-50`) |
| `akb_rewards_review_nudge_enabled` | sí/no | `yes` | `ReviewNudge::render()` (`:58`) |
| `akb_rewards_nudge_email_ids` | texto | `customer_completed_order` | `ReviewNudge::render()` (`:51`) |
| `akb_rewards_carousel_enabled` | sí/no | `yes` | `Options::carousel_on()` (`:76-78`) |
| `akb_rewards_carousel_min` | entero | 8 | `Options::carousel_min()` (`:363-365`) |
| `akb_rewards_carousel_min_chars` | entero | 40 | la consulta de `Carousel::build_data()` (`:284`) |
| `akb_rewards_carousel_cards` | entero | 8 | `Options::carousel_cards()` (`:321-323`) |
| `akb_rewards_carousel_title` | texto | «Voces del Distrito» | `Options::carousel_title()` (`:330-333`) |
| `akb_rewards_carousel_exclude_rewarded` | sí/no | `no` | `Carousel::build_data()` (`:254`) |

Más dos marcas internas, sin pantalla y correctamente así: `akb_rewards_db_version`
(`src/Support/Schema.php:118`) y `akb_rewards_rewrite_version`
(`src/Points/AccountRewards.php:105`).

**Tabla propia:**

| Tabla | Columnas | Quién escribe | Quién borra |
| --- | --- | --- | --- |
| `{prefijo}akb_rewards_review_forms` | `id`, `order_id`, `token` (único), `expires_at`, `used_at`, `created_at` (`src/Support/Schema.php:194-204`) | `Invitations::issue_token()` (`:218-227`), `ReviewForm::claim_token()` y `release_token()` | `Schema::purge_stale_review_tokens()` (`:71-76`), sólo los vencidos sin usar de más de 6 meses. **`uninstall.php` no la borra ni con la constante de purga** |
| `{prefijo}akb_rewards_ledger` (el «libro contable»; su dueño es el capítulo 10, pero aquí aterriza cada premio) | `id`, `user_id`, `delta`, `balance_after`, `action`, `ref_type`, `ref_id`, `event_key` (**único**), `note`, `created_at` (`src/Support/Schema.php:169-184`) | `Points\Ledger::credit()`, llamado desde `ReviewPoints` (`:256-269`) | Nada la limpia. Para ver sólo los premios de reseña: `wp db query "SELECT * FROM wp_akb_rewards_ledger WHERE ref_type='review'"` |

**Metas de pedido:**

| Meta | Valor | Quién la escribe |
| --- | --- | --- |
| `_akb_rewards_invite_scheduled` | fecha en hora **local** | `Invitations::on_status_changed()` (`:145`) |
| `_akb_rewards_invite_sent` | fecha en hora local | `Invitations::send_invite()` (`:193`) |
| `_akb_rewards_invite_attempts` | entero, nunca se reinicia | `Invitations::send_invite()` (`:199`) |

> El verificador refutó la sospecha de que la mezcla de relojes fuera un defecto: la tabla propia
> escribe `created_at` en UTC explícito (`:224`) y la meta de pedido en hora local, que es la
> convención de toda la meta de pedido de WooCommerce y lo que el dueño ve en la pantalla del pedido.
> Cada artefacto usa el reloj de su mundo y los dos están declarados.

**Metas de reseña (comment meta):**

| Meta | Valor | Quién la escribe | Quién la lee |
| --- | --- | --- | --- |
| `rating` | 1-5 | `ReviewForm::process()` (`:227`) | WooCommerce, el carrusel, el bloque para Google |
| `verified` | 0 o 1 | `ReviewPoints::verified_purchase()` (`:318`), `AdminReviews` (`:185`) | La compuerta 10 y la insignia de la ficha |
| `_akb_rewards_review_order` | ID del pedido | `ReviewForm::process()` (`:262`) | Nadie lo consume hoy; es el vínculo auditable |
| `_akb_rewards_review_points` | puntos, `0` o `pending_account` | `ReviewPoints` (`:186`, `:197`, `:212`, `:248`, `:283`) | La compuerta 5 y el filtro opcional del carrusel |
| `_akb_rewards_review_counted` | entero (pin de cadencia) | `ReviewPoints` (`:232`) | El propio candado (`:225-228`) |
| `_akb_rewards_review_progress` | «2/3» | `ReviewPoints` (`:249`) | **Nadie** (defecto D11) |
| `_akb_rewards_review_email_sent` | marca de tiempo | `Emails::send_review_email()` (`:396`) | El propio correo, para no repetirse |
| `_akb_rewards_admin_created` | 1 | `AdminReviews::create_review()` (`:147`) | La compuerta 6 y la insignia de divulgación |
| `_akb_rewards_source_note` | texto libre | `AdminReviews::create_review()` (`:191`) | **Nadie** (defecto D11) |
| `cr_rev_title` | texto | `AdminReviews::create_review()` (`:161`) | Heredada del plugin viejo |
| `ivole_review_image2` | ID de adjunto, una fila por foto | `Media::attach_photo()` (`:78`) | `has_real_photo()` y el carrusel (`:313`) |
| `ivole_review_image` | foto **remota** (una fila con `url`) | Nadie desde aquí: la dejó CusRev (`Media.php:35`) | `has_real_photo()` — **es la segunda rama de la vara del premio, y no se valida** (D23) |
| ~~`ivole_media_count`~~, ~~`ivole_review_video2`~~, ~~`ivole_review_video`~~ | El contador agregado de CusRev y sus dos metas de vídeo | **Ya no las declara ni las toca nadie.** Estuvieron hasta rewards 0.4.16; se retiraron en **0.4.17** porque ningún fichero propio las escribía ni las leía | Nadie. Pueden quedar filas antiguas en la base de datos de reseñas importadas de CusRev, ya sin lector (corregido 2026-09-20, ronda 10) |
| `cr_coupon_code` | código del cupón viejo | El plugin de terceros | La compuerta 7 y el filtro del carrusel |

**Usermeta:** `_akb_rewards_review_qualifying`, el contador de la cadencia «cada N»
(`src/Reviews/ReviewPoints.php:231`). **Sobrevive al borrado consentido**: `uninstall.php:26` sólo
limpia tres claves y ésta no está.

**Transient:** `akb_rewards_carousel_v1`, una hora (`src/Reviews/Carousel.php:38`, `:194`), invalidado
por seis ganchos y por cada guardado de la pantalla de ajustes.

**Ficheros:** cada foto aprobada queda en `uploads/` como adjunto de WordPress, con dirección pública,
y colgada del producto (`src/Reviews/Media.php:78-85`). En el panel se ve en **Medios**, bajo la ficha
del tomo. No hay ningún flujo que la borre cuando la reseña se rechaza o se borra, y **tampoco hay
ninguna pantalla que liste las fotos de reseñas rechazadas**. Para contarlas, o para saber cuáles son
antes de decidir qué hacer con ellas (pregunta 5 al dueño), hay que preguntárselo a la base:

```sql
SELECT cm.comment_id, cm.meta_value AS adjunto
FROM wp_commentmeta cm
INNER JOIN wp_comments c ON c.comment_ID = cm.comment_id
WHERE cm.meta_key = 'ivole_review_image2' AND c.comment_approved <> '1';
```

---

## Defectos abiertos y preguntas para el dueño

Lo que sigue no son opiniones: cada línea sale del código citado. **El número de cada defecto es
fijo** —los diagramas los citan por ese número—, así que la lista no está ordenada estrictamente por
gravedad: las de severidad **alta** son D1, D2, D3, D4 y D9.

**D1 · alta — El disparador de fábrica apunta a un estado que muchos pedidos nunca alcanzan.**
`src/Reviews/Invitations.php:60` usa `completed`, pero el propio plugin deja escrito dos ficheros más
allá que *«el estado terminal real de muchos pedidos es “Listo para enviar”»*
(`src/Reviews/ReviewNudge.php:48-50`), y `src/Support/Options.php:96-99` explica que el carril de
puntos acredita en `shipping-progress` porque *«completed llegaría tarde»*. Si ni la invitación ni el
empujón salen, no hay ninguna puerta de entrada al formulario. **Hay que medir el reparto real de
estados terminales en producción.**

**D2 · alta — La compuerta de preventa pierde el pedido, y la puerta de reentrada ya existe.** Caso 11.
El arreglo cabe en una línea: `add_action( 'akb_order_preventa_marked_ready', … )` en `Invitations`.

**D3 · alta — «Compra verificada» puede estar bloqueando todos los premios.**
`ReviewPoints::verified_purchase()` (`:316-317`) usa `wc_customer_bought_product()`, que sólo mira los
estados **pagados** de WooCommerce (de fábrica `processing` y `completed`). En todo el árbol
first-party no hay ni un `add_filter( 'woocommerce_order_is_paid_statuses', … )` que añada
`shipping-progress`. Si ese estado no está dentro, todo pedido que muera ahí produce `verified = 0`,
el valor **se persiste** (`:318`) y la reseña no cobra, sin sello, sin huella y sin aviso — con el
interruptor encendido de fábrica y el cliente habiendo leído «500 Puntos Akibara» en tres sitios.
Conviene saber además qué es `shipping-progress`: **no es un estado de WooCommerce**. «Listo para
enviar» lo aporta la integración del transportista **Blue Express**
(`plugins/akibara-business/src/Modules/OrderTimeline/OrderTimeline.php:134` lo documenta como estado
propio de BlueExpress), y por eso justamente puede no estar en la lista de «estados pagados» del
núcleo, que de fábrica trae sólo `processing` y `completed`.

**Se mide en un minuto:** `wp eval 'print_r( wc_get_is_paid_statuses() );'`.

**Y el arreglo son dos pasos, no uno.** `verified_purchase()` calcula la marca **una sola vez** y la
persiste (`:314-322`): si la comment-meta `verified` ya existe, no se recalcula nunca más. El día que
`shipping-progress` entre en la lista de estados pagados, las reseñas que ya quedaron con
`verified = 0` **siguen sin cobrar**. Hay que añadir el estado a la lista **y** barrer las
comment-meta `verified = 0` ya escritas para que se vuelvan a calcular. Sin el segundo paso el arreglo
no repara nada de lo pasado.

**D4 · alta — Los puntos de una reseña no se revierten nunca.** `ReviewPoints::register()` (`:51-60`)
no escucha la transición a *no aprobada*, ni *spam*, ni `deleted_comment`, y nada llama a
`Ledger::debit` con `action='review'`. El módulo hermano `Accrual` **sí** revierte cuando un pedido se
cae, así que no es una política escrita: es un olvido. Si el dueño borra una reseña ya premiada, en el
libro contable queda una fila apuntando a un comentario inexistente, y el índice único impide que el
cliente honesto pueda recuperar su premio.

**D5 · alta — El invitado que reseña y luego se hace cuenta casi nunca cobra.** Caso 4. Y no hay
ninguna pantalla que liste las reseñas atascadas en `pending_account`, ni comando para resolverlas.
Además, el docblock del módulo se contradice: `:23-24` y el comentario de `:196` siguen diciendo
`user_register`, que no es lo que engancha `:59`.

**D6 · media — Nada del plugin avisa al dueño de que hay una reseña esperando.** El único aviso posible
sale porque `ReviewForm` dispara `comment_post` a mano (`:269`) y WordPress engancha ahí su propio
notificador, que depende de dos options del núcleo (`moderation_notify`, `comments_notify`) que **no
están en la pantalla del dueño** (catálogo completo en `src/Support/SettingsPage.php:39-329`). Todo el
modelo depende de que él apruebe, y la palanca que decide si se entera no es suya.

**D7 · media — Vaciar «Correos que llevan la invitación» apaga el empujón en silencio.** El guardado de
los campos de texto acepta la cadena vacía (`src/Support/SettingsPage.php:523-524`), y en el consumo
eso deja una lista vacía con la que ningún correo coincide (`src/Reviews/ReviewNudge.php:51-53`). La
casilla sigue marcada y la pantalla no dice nada. El arreglo es simétrico al que ya existe para la
fecha de vigencia: rechazar el vacío y conservar lo anterior.

**D8 · media — El empujón del correo de entrega promete 500 puntos y no lleva ni un enlace.** El
párrafo completo es *«📸 ¿Te gustó tu pedido? Cuéntanos con una foto: tu reseña con imagen vale 500
Puntos Akibara (los acreditamos al aprobarla).»* y no contiene ningún `<a>` ni ninguna dirección, ni en
la rama HTML (`src/Reviews/ReviewNudge.php:72-77`) ni en la de texto plano (`:68-70`). La única
superficie con campo de foto es `/resena/{token}/`, que llega en **otro** correo tres días después — o
nunca (D1, D2). El cliente lee la promesa en el momento de máxima intención, acaba de recibir su tomo,
y el único camino que se le ocurre es la ficha del producto, que es justo donde la reseña nunca podrá
cobrar (D14). Es el mismo defecto que el propio plugin ya diagnosticó y arregló para el correo del
premio: *«este era el único correo del programa que llegaba SIN un solo enlace»*
(`src/Points/Emails.php:374-375`). Enlazar al pedido o a Mi cuenta no cuesta nada: el objeto del pedido
llega al método y el código lo descarta a propósito con `unset( $order )` en `:46`.

**D9 · alta — ✅ corregido en 0.4.1 (divulgación en la ficha + interruptor del JSON-LD, piezas 13 y
13 bis. El cupón viejo no aplica: producción tiene 0 comment-meta `cr_coupon_code` y 0 reseñas, medido
el 2026-09-13) — Tres superficies aplican tres criterios
distintos a la misma pregunta, y se publican reseñas incentivadas sin declararlo.** El carrusel
excluye siempre el cupón viejo y, por option, las premiadas; el bloque para Google
(`src/Reviews/Schema.php:106-124`) no excluye nada. `Carousel.php:252` deja escrito que *«la
divulgación del incentivo va en el listado del PDP»* —«PDP» es como el código abrevia la **ficha del
producto**— y esa superficie **no existe**: un barrido por todo el árbol no encuentra ninguna
divulgación del incentivo impresa en ningún sitio. Se publican reseñas incentivadas con 500 puntos, y
transcritas por la tienda, sin declararlo. Con cero reseñas publicadas el riesgo todavía es futuro,
pero es de los que no avisan: empieza a correr con la primera reseña aprobada.

**D10 · ✅ cerrado en 0.4.9 (2026-09-19) — La limpieza de los enlaces colgaba del vencimiento de
puntos.** Ahora vive en `Reviews\TokenCleanup` con acción propia (`akb_rewards_review_tokens_cleanup`),
fuera del gate del motor de reseñas. Medido en producción: la acción quedó agendada al desplegar.
Hallazgos S-961547f2 y S-bd6ecf5c del inventario.

**D11 · media — Datos que se escriben y nadie lee.** `_akb_rewards_review_progress` (el «2 de 3» que el
cliente nunca ve) y `_akb_rewards_source_note`: la procedencia de una reseña transcrita —«Instagram DM
30-08»— que sólo se puede leer por consulta a la base. El código la guarda por dos razones que deja
anotadas (`src/Reviews/AdminReviews.php:187-188`): el **artículo 20 del Código Chileno de Ética
Publicitaria (CONAR)**, que obliga a que un testimonio publicado sea auténtico y se pueda respaldar; y
la **Ley 21.719**, la ley chilena de protección de datos personales, fiscalizable desde el 2026-12-01,
que exige poder acreditar con qué consentimiento se publicó un dato de una persona. Si el respaldo hay
que enseñárselo a alguien, hoy no hay pantalla donde enseñarlo.

**D12 · ✅ cerrado en 0.4.9 (2026-09-19) — Privacidad: reseñas, fotos y tokens quedaban fuera del
borrado y del wipe.** El exportador entrega cumpleaños, código de padrino, quién invitó y las fotos de
las reseñas (también a compradores invitados); el borrador borra lo personal y declara lo que retiene;
la purga consentida tira también `akb_rewards_review_forms` y borra la usermeta por prefijo
`_akb_rewards_%` (conserva sólo la lista de bajas, que son huellas sin identidad). **Queda para el
dueño:** si una solicitud de borrado debe retirar también las fotos publicadas — hoy se declaran
retenidas con el correo de contacto. Hallazgos S-24de6093, S-1c3746bb, S-4365d38c y S-64da5c8d.

**D13 · media — El formulario público acepta HEIC y el del dueño no.** `ReviewForm.php:374-379` frente a
`AdminReviews.php:299`. Un iPhone de fábrica sube HEIC; WordPress lo acepta y pasa la compuerta del
premio, pero sin soporte HEIC en el servidor no se generan los tamaños intermedios y la tarjeta del
carrusel queda con un hueco en todo navegador que no sea Safari. **Depende de la configuración del
servidor: hay que medirlo.**

**D14 · media — El código manda al cliente a reseñar donde no hay campo de foto.**
`ReviewForm.php:559` y `:573`. La ficha **sí** pinta una lista de reseñas —el tema la estiliza en
`themes/akibara-gp/assets/css/reviews-p5-v4.css`— y ahí no hay ni foto que ver ni campo donde subirla.

**D15 · media — Los reintentos acumulan intentos que nunca se reinician y dejan un token vivo por
vuelta.** `Invitations.php:185-204`: el token se emite **antes** de intentar el envío y la meta de
intentos nunca se borra, ni siquiera en el camino de éxito. Tres días sin transporte de correo —algo
que ya pasó en esta tienda— dejarían cada pedido con tres intentos grabados para siempre y hasta tres
enlaces válidos a la vez.

**D16 · media — Cambiar el estado disparador mata lo ya agendado.** `Invitations.php:172-174` sale sin
marcar, sin reagendar y sin registro. **Matiz del verificador:** como el pedido no se marca, una
entrada futura al estado **nuevo** sí vuelve a agendar; lo que se pierde de verdad son los pedidos
terminales, que ya no volverán a entrar a ningún estado.

**D17 · media — Una foto demasiado grande da un mensaje que habla de otra cosa.** Si el envío supera el
`post_max_size` de PHP, `$_POST` llega vacío, no hay nonce y el cliente lee «la sesión del formulario
caducó» (`ReviewForm.php:166-168`) en vez de «la foto pesa demasiado».

**D18 · baja — La reseña del cliente se des-escapa dos veces.** `ReviewForm.php:185` y `:248` frente a
`AdminReviews.php:151`, que sí hace `wp_slash`. **El verificador bajó la severidad de media a baja:** el
contenido ya pasó por saneado y filtrado, así que lo único que se pierde es una barra invertida
literal. Lo que sí vale es la asimetría entre dos rutas del mismo plugin.

**D19 · baja — Los dos avisos de WordPress se reponen incondicionalmente.** `AdminReviews.php:168-174`
repone siempre en vez de restaurar el estado previo.

**D20 · baja — Código muerto en el tema.** `themes/akibara-gp/customer-reviews-woocommerce/form.css`
importa un fichero del plugin de terceros que, si ya no está instalado, es un 404. A la misma cola de
barrido entra `assets/css/reviews-p5-v4.css`, que hoy estiliza una lista siempre vacía en ~1.600
fichas.

**D21 · baja — Higiene de tokens con dos relojes.** `src/Support/Schema.php:75` compara con el reloj de
MySQL (`NOW()`) mientras las fechas se escriben en UTC. En una ventana de seis meses da igual; queda
anotado porque en Chile el desfase cambia dos veces al año.

**D22 · baja — El interruptor «Moderación obligatoria» no gobierna la reseña del formulario propio.**
`src/Reviews/ReviewForm.php:226` fija el `0` a mano y no pasa por `pre_comment_approved`, que es el
único sitio donde ese interruptor manda (`src/Reviews/Moderation.php:37`). Apagarlo **no** publica
automáticamente lo que llega por `/resena/{token}/`. Es baja porque ninguna pantalla promete lo
contrario —la ayuda describe el estado encendido, que es el de fábrica
(`src/Support/SettingsPage.php:217`)—, pero es una asimetría real entre dos rutas del mismo plugin, y
sube de golpe el día que el dueño pida la auto-publicación.

**D23 · media — La vara de «tiene foto» tiene dos ramas, y la otra superficie usa sólo una.**
`Media::has_real_photo()` (`src/Reviews/Media.php:45-59`) da por buena cualquier fila de la meta
remota heredada `ivole_review_image` con un `url` no vacío, **sin comprobar que exista, que sea una
imagen ni que esté en el servidor**. Una reseña heredada de CusRev con sólo foto remota cobra los 500
puntos sin que haya ningún adjunto. Y el carrusel, que mira **sólo** la rama local
(`src/Reviews/Carousel.php:312-320`, con un comentario que además afirma usar «la misma vara»), pinta
esa reseña con la carátula del tomo, como si no tuviera foto. Con el catálogo heredado vacío hoy no
cobra a nadie; con una importación de reseñas viejas, sí.

**D24 · baja — El aviso de «la tabla no se creó» nombra siempre la tabla equivocada.**
`Schema::maybe_install()` comprueba las dos tablas (`schema_ready()`, `src/Support/Schema.php:214-219`)
pero el mensaje de registro imprime siempre `self::ledger_table()` (`:106`). Si la que falta es
`akb_rewards_review_forms` —la que sostiene todo el circuito de invitaciones— el operador lee
«`akb_rewards_ledger` no existe» y se pone a buscar donde no es. Es un arreglo de una línea.

> **Tres cosas que parecían defectos y no lo son.** (a) La guarda
> `if ( function_exists( 'do_action' ) )` de `Carousel.php:74` no es un olvido: es una convención
> deliberada y consistente del fichero, con el mismo patrón en otros cinco sitios, y sus docblocks
> explican que son los puntos donde la suite de pruebas ejerce el código sin WordPress cargado. (b)
> «No hay tope de invitaciones por cliente» no es un defecto sino una decisión de negocio no tomada: la
> ayuda de la pantalla dice exactamente lo que el código hace, «un correo por pedido»
> (`src/Support/SettingsPage.php:154`). Pertenece a las preguntas de abajo. (c) Los dos relojes de
> `_akb_rewards_invite_scheduled` tampoco son una inconsistencia; está explicado en «Datos que deja».
> Una cuarta sospecha —el interruptor de moderación y el formulario propio— **no quedó descartada**,
> sólo bajó de gravedad: está arriba como **D22**.

**Preguntas que sólo puede responder el dueño:**

1. ¿Con qué estado quiere que salga la invitación? Antes de tocarlo hay que resolver D2, porque
   cambiarlo a «Listo para enviar» rompe las preventas de otra manera.
2. ¿Cuántos correos de invitación por cliente y en qué ventana? Hoy no hay más tope que uno por pedido.
3. ¿La foto de la reseña tiene que verse en la ficha del producto? Todo el diseño del programa apunta a
   que sí, y hoy esa superficie no existe.
4. ¿Las reseñas que transcribe el dueño deben entrar al carrusel y a lo que ve Google? Hoy entran, y
   sin la divulgación que sí llevan en la ficha.
5. ¿Qué pasa con la foto cuando el dueño rechaza o borra una reseña? Hoy el adjunto sobrevive en
   `uploads/` con dirección pública.
6. ¿Se premia de alguna forma a quien reseña desde la ficha del producto, sin foto? Hoy no, y no se le
   dice.
7. Con la cadencia «cada N reseñas» encendida, ¿cómo se le cuenta al cliente que va 2 de 3?
8. ¿El formulario debe seguir exigiendo la foto cuando el carril de puntos está apagado?
9. ¿Es aceptable que un «bórrame» deje el correo del cliente escrito en las notas del ledger? Es
   deliberado y está justificado contablemente, pero conviene que la decisión sea suya y esté escrita.

**Lo que este capítulo no puede cerrar con el código a la vista, en orden de valor** (es medición, no
lectura). Cada punto va con el comando que lo cierra:

1. **¿Qué estados cuentan como pagados?** Si `shipping-progress` no está dentro, D3 explica por sí
   solo que ninguna reseña cobre jamás.
   `wp eval 'print_r( wc_get_is_paid_statuses() );'`
2. **¿El post de la portada sigue llevando el shortcode `akibara_home_reviews`?** Si no, el carrusel
   no aparecerá ni con ochenta reseñas y el umbral de 8 no tiene nada que ver.
   `wp option get page_on_front` y después
   `wp post get <id> --field=content | grep -c akibara_home_reviews`
3. **¿Dónde mueren de verdad los pedidos?** (D1).
   `wp db query "SELECT status, COUNT(*) FROM wp_wc_orders GROUP BY status"`
4. **¿WordPress avisa al dueño de que hay una reseña esperando?** (D6).
   `wp option get moderation_notify` y `wp option get comments_notify`
5. **¿Sigue instalado *customer-reviews-woocommerce*?** Es lo que decide si el motor está despierto y
   si D20 se barre o no.
   `wp plugin list | grep customer-reviews-woocommerce`
6. **¿Cuándo dispara WooCommerce `woocommerce_customer_email_verified`?** De eso depende que el
   invitado que se hace cuenta cobre alguna vez (Caso 4 y D5). Es comportamiento de un tercero: hay
   que provocarlo en el espejo —crear cuenta en el checkout, pedir restablecimiento de contraseña,
   pulsar el enlace de verificación— y ver con cuál de los tres se dispara.
7. **¿El servidor sabe leer HEIC?** De eso depende que la foto de un iPhone se vea fuera de Safari
   (D13). `wp eval 'var_dump( wp_image_editor_supports( array( "mime_type" => "image/heic" ) ) );'`
8. **¿Cuántas reseñas hay realmente?** Todo este capítulo se escribió sobre «cero reseñas en
   producción», y ese dato caduca.
   `wp db query "SELECT COUNT(*) FROM wp_comments WHERE comment_type='review'"`
9. **¿Cuántas fichas de producto hay?** Es la otra cifra prestada («unas 1.600»), y de ella cuelga el
   cálculo de peso muerto de D20. `wp post list --post_type=product --format=count`

---

## Glosario del capítulo

- **Action Scheduler**: la cola de trabajos de fondo que trae WooCommerce. Guarda «qué hacer y cuándo»
  en la base de datos y lo ejecuta con reintentos.
- **Adjunto (*attachment*)**: la forma que tiene WordPress de guardar un fichero subido. Tiene su propia
  ficha en la Biblioteca de Medios y puede colgar de otra ficha (aquí, del producto).
- **Carril**: en este plugin, cada una de las formas de ganar puntos (compra, bienvenida, reseña,
  referido, cumpleaños). Cada carril tiene su interruptor.
- **Comment meta**: un dato pegado a un comentario. Una reseña es un comentario, así que sus estrellas,
  su foto y su sello de premio son comment metas.
- **Compuerta (*gate*)**: una comprobación que corta el paso. `maybe_pay()` es una escalera de once.
- **Cron**: un trabajo que se ejecuta solo, más tarde, sin que nadie esté mirando.
- **CusRev**: apodo del plugin comprado *Customer Reviews for WooCommerce*, al que este código
  reemplaza y cuyas claves de datos hereda.
- **Filtro**: un gancho que deja pasar un valor y permite devolverlo cambiado. `pre_comment_approved`
  deja pasar el veredicto «aprobado/en espera» y aquí se devuelve siempre «en espera».
- **Gancho (*hook*)**: punto del programa donde WordPress avisa de que algo pasó y permite intervenir.
- **JSON-LD**: el formato en que una página le declara a Google datos estructurados («esto es un
  producto, vale tanto, tiene estas estrellas»). Es lo que permite las estrellitas en los resultados.
- **Ledger**: el libro contable de los puntos. Una fila por movimiento, con su clave de evento única.
- **`meta_key` / `option`**: dos formas de guardar un dato en la base de datos de WordPress. La option
  es global de la tienda; la meta cuelga de algo concreto (un pedido, un comentario, un usuario).
- **Moderación**: retener un comentario hasta que una persona lo apruebe.
- **Nonce**: un código de un solo uso que un formulario incluye para demostrar que el envío viene de la
  página que la tienda sirvió, y no de otro sitio.
- **Prioridad**: el número que decide el orden entre funciones enganchadas al mismo gancho. Menor
  primero.
- **Reparentar**: colgar un adjunto de otra ficha. Aquí, la foto de una reseña se cuelga del producto.
- **Sello**: en este capítulo, la meta `_akb_rewards_review_points`, que registra el veredicto del
  premio para que no se vuelva a evaluar.
- **Shortcode**: una etiqueta entre corchetes que se escribe dentro del contenido de una página y que
  WordPress reemplaza por lo que devuelve una función. `[akibara_home_reviews]` es el del carrusel.
- **Token**: la cadena larga y aleatoria del enlace personal de reseña. Un enlace, un pedido, un solo
  uso.
- **Transient**: un dato guardado con fecha de caducidad, para no recalcularlo en cada visita.
- **UTC**: la hora de referencia mundial. Chile va tres o cuatro horas por detrás según la época del
  año.
