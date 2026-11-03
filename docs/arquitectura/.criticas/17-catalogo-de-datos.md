# Crítica de lector novato — `17-catalogo-de-datos.md`

Quién escribe: alguien que llega hoy al proyecto, no sabe WordPress ni WooCommerce, y
tiene que poder **operar y depurar** con este capítulo en la mano. Leí el capítulo entero
y sus siete diagramas, y después abrí el código del *snapshot* de producción
(`scratchpad/snapshot-prod/`) para contrastar lo que me dio dudas. Todas las líneas que
cito abajo están comprobadas contra ese *snapshot*.

Nota de contexto: el capítulo es bueno, denso y honesto —dice lo que está roto—. Casi
todo lo que sigue son huecos de **operación** (rutas, hooks, nombres de claves) y una
media docena de afirmaciones que el código desmiente.

---

## 1 · Lo que NO entendí

**1.1 · «deuda», «materializar» y «recibo» aparecen como si ya los conociera.**
Frase: «se monta la deuda en `akb_search_editorial_discount_state` y se pide reindexar
los productos de esa editorial. Mientras la deuda no materialice, el gate cierra»
(CU-2, paso 5). El glosario define *cerrojo/gate*, *sombra*, *TTL* y
*compare-and-swap*, pero no **deuda**, **materializar** ni **recibo**. Debería decir, en
una frase de glosario: «**Deuda**: una anotación que dice "el índice todavía no publica
el precio nuevo"; vive en `akb_search_editorial_discount_state`. **Recibo**
(`…_receipt`): la huella de lo último que el índice sí publicó. El buscador sirve sólo
cuando recibo y fuente coinciden; mientras no coinciden, hay deuda y `suggest` responde
503».

**1.2 · «foto estable de la fuente» y «autoritativa»: no sé qué es «la fuente».**
Frases: «pide el candado de MariaDB y saca una foto estable de la fuente» (CU-2, paso 3)
y «la foto de la fuente deja de ser autoritativa» (paso 6). Debería decir explícitamente
qué se fotografía: **las diez options vigiladas más el termmeta
`_akb_taxonomy_discount_pct` de cada editorial** — es decir, exactamente lo que enumera
§5. Y que «autoritativa» significa «el buscador se fía de ella para publicar precios».

**1.3 · «dominio cerrado» sin decir cuál es el dominio.**
Frase: «Bifurcación C — el valor guardado queda fuera del dominio cerrado (por ejemplo,
un `sale_policy` escrito desde la consola con un valor que no existe)». Un novato no
puede reproducir ni descartar esto. Debería nombrar la option completa
(`akibara_taxonomy_discount_sale_policy`) y **sus cuatro valores legales**:
`greatest_wins`, `skip_if_on_sale`, `from_regular`, `stack_on_sale`
(`akibara-search/src/EditorialDiscountPublication.php:3304-3307`), y añadir que
`valid_source_option_value()` es quien los comprueba.

**1.4 · «el contrato del feed» es la única de las diez options vigiladas que nunca se
nombra.** §1 y la tabla de Reglas dicen «seis de descuentos, **el contrato del feed**, y
tres de WordPress y WooCommerce». Las otras nueve sí aparecen con su clave. Debería
decir el nombre real de esa option (la constante `OPTION_FEED` de
`EditorialDiscountPublication.php:199-213`), porque si el cerrojo rechaza una escritura,
lo primero que hace uno es buscar la clave.

**1.5 · «mueve la huella de la fuente editorial»: no entendí nada.**
Frase: CU-1 paso 4 — «crear una fila nueva mueve la huella de la fuente editorial». Ahí
todavía no se ha explicado que el buscador saca una huella de un conjunto de options, ni
que **crear una fila** (aunque el valor sea el de fábrica) cambia esa huella y puede
disparar trabajo del índice. Debería decirlo con esas palabras y remitir a §5, o quitar
la frase de ahí y dejarla en §5.

**1.6 · Falta «hook», «filtro», «acción» y «prioridad» en el glosario, y son la columna
vertebral de todas las secciones «En qué momento actúa».** Frases como «`pre_update_option`
(10) para la guarda que impide crear filas intactas» (§2) o «`pre_update_option` con
prioridad `PHP_INT_MAX`» (§5) son ilegibles sin eso. Debería añadir: «**Hook**: un punto
del programa donde WordPress deja que otro código se enganche. Un **filtro** puede
cambiar el valor que pasa; una **acción** sólo reacciona. La **prioridad** es el orden:
número bajo = antes. `PHP_INT_MAX` = el último de todos, que es como se consigue tener la
última palabra».

**1.7 · «saneador» se usa cuatro veces y no está definido.**
Frase: «El saneador de `CopyManager` aplana el texto a una línea y le quita etiquetas»
(CU-1, paso 5). Debería definirse: «**Saneador**: la función que limpia lo que el dueño
escribió antes de guardarlo».

**1.8 · «topología» es la palabra clave de CU-4 y no está explicada.**
Frases: «topología `clean_pre`», «`BLOCKED / orphan_topology_without_state`». Debería
decir: «**Topología**: qué tablas del índice existen en este momento (sólo las vivas, o
además las `_new`, o además las `_old`). El plugin la clasifica con cuatro nombres:
`clean_pre`, `ready_swap`, `swapped` y `ambiguous`
(`akibara-search/src/Install.php:103-106`)». Sin esto, el «estado sano de reposo» del
paso 6 no se puede comprobar.

**1.9 · Los cuatro cajones se contradicen con el glosario.** El capítulo abre diciendo
que los cajones son cuatro y que uno de ellos son los transients; el glosario dice
«**Transient**: una option con fecha de caducidad» y §11 dice que «con caché de objetos
persistente los transients no tocan `wp_options` en absoluto». Un novato no sabe si son
un cajón aparte o no. Debería resolverse en una frase en la introducción: «los transients
son un cajón por su uso —se pueden perder— aunque físicamente vivan dentro de `wp_options`
o, si hay caché de objetos, fuera de la base de datos».

**1.10 · «escrita sólo cuando el número cambia»: ¿qué número?**
Frase: §7, sobre `akb_blocks_mp_dequeue_observed`. No se dice nunca qué cuenta esa option
(cuántos scripts de Mercado Pago se retiraron), así que no se puede interpretar su valor
al leerla en el Dashboard. Debería decir qué guarda: `{retirados, at}`
(`akibara-cart-checkout-blocks/src/RuntimeCompatibility.php:205`).

**1.11 · «subir el número» de los contadores de generación suena a algo que el dueño
hace, y no hay dónde.** Frase: §11 — «Lo que sí existen son contadores de generación
(`akb_home_destacados_generation`, …): subir el número invalida de golpe todas las claves
que dependen de él». Los cuatro existen (`HomeDestacados.php:66`, `SeriesFacts.php:175`,
`BrandsDirectory/Shortcode.php:54`, `SeriesBundles/BuilderWidget.php:116`) pero los sube
el código solo. Debería decir: «los sube el propio código cuando cambia lo que cachean; no
hay pantalla. A mano, sólo por consola con `wp option update`».

**1.12 · Salto lógico: 30 segundos contra 6 minutos.**
Frase: CU-2 paso 5 — «Una reconstrucción **pedida a mano** son unos 30 segundos; el camino
que la dispara solo puede tardar unos 6 minutos». Se afirma dos veces en el capítulo y
nunca se explica por qué la misma reconstrucción tarda doce veces más según quién la pida.
Debería explicarse en una frase (el camino automático espera a la cola y al ciclo del cron
de Hostinger; el manual arranca ya), o quitarse el número.

**1.13 · Nombres de pestañas sin decir qué hacen.** «Por llegar», «Vitrina», «Arma tu
Pack», «Sigue esta serie» aparecen en la lista de 19 secciones y luego se usan como si el
lector supiera qué son («Se ven en la ficha del pedido y en la pantalla "Por llegar"»).
Debería llevar media línea cada una, al menos para las cuatro que el capítulo después
usa como destino de un dato.

**1.14 · «la regla de oro» se invoca antes de explicarse.** El título de §15 dice «lo que
incumple la regla de oro» y sólo al final del cuerpo se aclara cuál es. Debería estar en
la primera frase de la sección.

---

## 2 · Lo que FALTA para poder operar o depurar esto sin ayuda

**2.1 · Ninguna pantalla tiene ruta.** El capítulo dice «Akibara → Textos», «Akibara →
Descuentos», «WooCommerce → Ajustes → Correos» y nunca da una URL ni un identificador de
pestaña. El menú se llama `akibara-panel` (`AdminPanel.php:183`), no `akibara`. Debería
dar el patrón una vez —`/wp-admin/admin.php?page=akibara-panel&tab=<id>`— y el `id` de
cada sección; el capítulo sólo revela tres (`salud_seo`, `branding`, `copys`).

**2.2 · La pantalla «Buscador» no dice dónde vive.** §4 dice «La pantalla **Buscador**,
con siete sub-pestañas». En el código es un submenú de `akibara-panel`
(`akibara-search/src/SearchAdminSection.php:126`) **y** se convierte en menú de primer
nivel si `akibara-business` no está activo (`:137`). Eso último importa justo el día malo
—business caído— y no está.

**2.3 · «Akibara → Claves y accesos» es una pantalla número 20 que contradice el «19
secciones».** §12 la usa como destino y §2 afirma que el menú tiene 19 secciones. La
pantalla la registra un mu-plugin como submenú de `akibara-panel`
(`akibara-provider-access.php:488` y `:496-503`), fuera del registro de secciones del
panel. Debería decir: «19 secciones del panel **más** dos submenús que no son secciones:
Buscador y Claves y accesos».

**2.4 · Tres crons de la tabla no tienen hook, y sin hook no se pueden buscar ni
relanzar.** Las filas «Vencimiento de puntos», «Cumpleaños» y «Retención de Action
Scheduler» dan sólo la clase. Los nombres reales son `akb_rewards_expiry_daily`
(`akibara-rewards/src/Points/Expiry.php:33`) y `akb_rewards_birthday_daily`
(`Birthday.php:32`). Y falta el dato que más duele: **los dos se agendan sin grupo**
(`Expiry.php:93`, `Birthday.php:72`), a diferencia de los del buscador, que usan
`Indexer::AS_GROUP` — así que cancelarlos «por grupo» no los encuentra.

**2.5 · CU-6 nombra la constante y no el hook.** «Encola con
`as_enqueue_async_action( HOOK_WARN_ASYNC, … )`». El valor es
`akb_rewards_expiry_warn_async` (`Expiry.php:35`), que es lo que hay que buscar en la cola
para ver si hay dos avisos encolados. Además falta la bifurcación previa: ese encolado
**sólo ocurre si `use_batches()`** (`Expiry.php:365-368`); sin lotes, el aviso sale en la
misma pasada y el doble correo no aplica.

**2.6 · La alarma del buscador se menciona dos veces y no se puede operar.** CU-2 y CU-4
dicen «sale la alarma a `admin_email`, una sola vez por episodio». Falta todo lo
operable: la option que recuerda el episodio es `akb_search_serving_alarm`
(`RebuildJob.php:740`), el umbral es `ALARM_AFTER_SECONDS = 900`, es decir 15 minutos
caído (`:737`), y el asunto del correo es «Akibara: el buscador lleva rato caído»
(`:773`). Sin la clave de la option nadie puede rearmar la alarma tras un episodio, y el
capítulo es precisamente el catálogo de options.

**2.7 · Faltan dos options del buscador de las que depende lo que el propio capítulo
cuenta.** `akb_search_rebuild_history` (`RebuildJob::OPTION_HISTORY`, `RebuildJob.php:40`)
y el transient `akb_search_watchdog_guard_v2` con TTL de 43.200 s (`:81-82`). El segundo
explica por qué reprogramar el vigilante puede no surtir efecto hasta 12 horas después —
justo el fallo que el capítulo denuncia en la fila del watchdog.

**2.8 · `ops/rescate-buscador.sh produccion` se cita como único camino y no se explica.**
CU-4 paso 5. Falta: que hay que estar conectado (`ssh akibara`), qué hace el guion en
orden (suelta `_new`, retira la option), y cómo se comprueba después. El propio correo de
alarma da los comandos de verificación: `wp akb-search status` y `wp akb-search rebuild`
(`RebuildJob.php:777-779`). Deberían estar en el capítulo.

**2.9 · El «segundo interruptor» de cada correo no dice a qué sección ir.** §9 y CU-3
dicen «WooCommerce → Ajustes → Correos», pero esa pantalla tiene una sección por correo y
el identificador de sección **es el `EMAIL_ID`**: `akb_bis_instock`
(`InstockEmail.php:36`), `akb_sf_series` (`SeriesFollowEmail.php:42`),
`akb_preventa_reminder` (`ReminderEmail.php:70`), `akb_preventa_ready`
(`ReadyToShipEmail.php:57`). Debería dar la ruta:
`…admin.php?page=wc-settings&tab=email&section=akb_bis_instock`.

**2.10 · «Configurable por filtro» sin decir dónde se escribe el filtro.** Regla: «Las
filas terminales de la lista de espera se borran a los 180 días… sí, por filtro / Filtro
`akb_bis_retention_days` (código)». Para un novato «por filtro» no es operable. Debería
decir que hoy exige escribir un mu-plugin y desplegar, y por tanto que **no** cumple la
regla de oro (igual que §15 hace con las otras).

**2.11 · El fichero que escribe el único dato del tema no está en su lista de
ficheros.** §8 lista `inc/site-copy.php`, `header/shell.php`, `footer/main.php` y
`single.php`, y luego dice «Escribe una sola cosa: el transient `akb_gp_series_hub_url`».
Ese transient se escribe en `inc/series-breadcrumb.php:90-94`, y se olvida en
`save_post`/`delete_post` **sólo si el post guardado es de tipo `page`** (`:107-112`). Las
dos cosas faltan.

**2.12 · `akb_pudo_state_<hmac 64 hex>`: «SIN UI» y nada más.** No se dice cuántas filas
puede haber, cuánto viven, quién las borra ni qué se hace si se acumulan. Es la otra
option escrita con SQL directo (`WpOptionsPudoMutationRepository.php:40` y `:72`,
`autoload = 'off'`), y el capítulo la lista sin ninguna de esas respuestas.

**2.13 · El inventario se declara incompleto y no da la salida.** «el inventario completo
pasa de 200 claves» (encabezado de la tabla de options). Debería cerrar con el comando que
lo obtiene: `wp option list --search='akb_*'` / `--search='akibara_*'`, y avisar de que
`akb_` es compartido por los cuatro plugins.

**2.14 · Los errores de `akibara-rewards` también van a un registro, y no se dice.** §7
enseña que los fallos de PUDO se ven en **WooCommerce → Estado → Registros**; los de
puntos van al mismo sitio con fuente `akibara-rewards`
(`akibara-rewards/src/Support/Log.php:30` y `:39-48`). Para un capítulo de dinero, es el
dato de depuración que falta.

**2.15 · §12 dice «18 mu-plugins» y sólo presenta 8.** El `ls` del *snapshot* da 18
ficheros, y sólo **cinco** escriben estado: `akibara-bluex-integrity-watch.php`,
`akibara-mail-transport-watch.php`, `akibara-correo-smtp.php`,
`akibara-catalogo-abilities.php` y `akibara-rankmath-redirections-abilities.php`. Debería
decirlo así —«de los 18, cinco guardan datos; los demás no dejan nada»— para que nadie
tenga que auditar los otros trece.

**2.16 · La tabla de tokens de reseña no dice quién la poda.** `wp_akb_rewards_review_forms`
aparece en «Tablas propias» sin retención, mientras que la fila de cron de vencimiento
dice «purga tokens de reseña caducados». Debería cruzarse: qué corrida, qué corte.

**2.17 · Falta la trampa operativa más cara del buscador.** §4 nombra las sub-pestañas
«Sinónimos» y «Palabras ignoradas» pero no nombra sus options (`akb_search_synonyms`,
`akb_search_stopwords`) ni dice que guardar ahí dispara una reconstrucción. Es
exactamente lo que el `CLAUDE.md` marca como causa de `suggest` en 503 durante ~6 minutos,
y el capítulo, que sí cuenta ese riesgo para los descuentos, lo omite aquí.

**2.18 · La cola de «Sigue esta serie» crece sin poda y no está señalada donde se mira.**
La Regla de negocio lo dice («no se poda: las filas terminales se marcan»), pero la tabla
de «Tablas propias» —que es la que uno consulta al ver la base crecer— sólo pone
«`UNIQUE (event_key)`». Y no se dice lo que sí importa para privacidad: como la fila no se
borra, el par usuario–producto sobrevive a la baja del seguimiento
(`_akb_sf_optout`).

---

## 3 · Lo que CONTRADICE al código

**3.1 · El vigilante SÍ actúa con el estado bloqueado.** El capítulo lo afirma dos veces:
«`akb_search_rebuild_watchdog` … **sólo actúa si el estado es nulo**» (tabla de «Lo
programado») y «además apaga el vigilante, que sólo actúa cuando el estado es nulo»
(CU-4, paso 3). El código dice lo contrario:

```php
private static function watchdog_recovers_serving( ?array $state ): bool {
    if ( null === $state ) { return true; }
    $phase = (string) ( $state['phase'] ?? '' );
    return self::PHASE_BLOCKED === $phase || self::PHASE_ABORTED === $phase;
}
```

`akibara-search/src/RebuildJob.php:692-700`, invocado en `:653-654`. El docblock de
`:673-684` explica que «colgaba de que el estado fuese nulo» era **el bug** del 2026-09-05
y que por eso se cambió. Debería decir: «con el estado bloqueado o abortado el vigilante
sí entra, intenta el reconcile barato, pide reconstrucción si hace falta y, si sigue
caído 15 minutos, manda la alarma». Esto invalida también el «pestillo permanente» tal
como está redactado: permanente es el bloqueo del *rebuild*, no la falta de rescate del
*serving*.

**3.2 · «unos 40 módulos» — son 61.** §2: «Es el plugin grande —unos 40 módulos—».
`ls snapshot-prod/plugins/akibara-business/src/Modules | wc -l` da **61**. Debería decir 61,
o «más de sesenta».

**3.3 · «36 options / 36 campos» de rewards — son 40.** §6 lo dice dos veces («36 options
en una sola pantalla», «36 campos en seis secciones») y la tabla de options lo repite
(«`akb_rewards_*` (36 claves)»). `SettingsPage::FIELDS` tiene **40** entradas
(`akibara-rewards/src/Support/SettingsPage.php:39-328`; `option_names()` en `:337` devuelve
`array_keys( self::FIELDS )`). Las seis secciones sí son seis. Debería decir «40 campos en
seis secciones».

**3.4 · «Cada correo que la tienda le manda al cliente tiene dos interruptores» es falso
para los correos de puntos.** Título y primera frase de §9. Los correos de
`akibara-rewards` (puntos, vencimiento, cumpleaños, invitación a reseñar) **no son
`WC_Email`** — el propio código lo dice: «Estos correos no son `WC_Email`, así que no
heredan el `handle_multipart()`» (`akibara-rewards/src/Points/Emails.php:125`) — y se
envían con `send_branded()` (`:142-162`), sin fila `woocommerce_*_settings` y sin pantalla
en WooCommerce → Correos. §6, tres páginas antes, los presenta como correos al cliente.
Debería decir: «**los cuatro correos que son de WooCommerce** tienen dos interruptores;
los de puntos tienen uno solo, en Puntos y Reseñas».

**3.5 · «los tres hooks de termmeta» — son ocho.** §5. El registro engancha cinco filtros
(`update_term_metadata`, `add_term_metadata`, `delete_term_metadata`,
`update_term_metadata_by_mid`, `delete_term_metadata_by_mid`) y tres acciones
(`updated_term_meta`, `added_term_meta`, `deleted_term_meta`), todos a `PHP_INT_MAX`:
`EditorialDiscountPublication.php:239-246`. Debería decir «los cinco filtros de termmeta y
sus tres acciones».

**3.6 · La retención de Action Scheduler no es un cron.** La tabla de «Lo programado» la
lista como «wp-cron | Según el mu-plugin». El mu-plugin no agenda nada: es un filtro,
`add_filter( 'action_scheduler_retention_period', … ) → 14 * DAY_IN_SECONDS`
(`mu-plugins/akibara-as-retention.php:13-16`). La poda la sigue haciendo Action Scheduler
en su propia acción. Debería decir: «Mecanismo: filtro sobre el tercero; frecuencia: la
del propio Action Scheduler».

**3.7 · «las dos últimas las lee el plugin de bloques» — son tres.** Tabla de options, fila
`akibara_native_cart_*`. `akibara-cart-checkout-blocks` lee además **`akb_sf_enabled`**
(`src/Assets.php:86` y `:259`). La fila de `akb_sf_enabled` de la misma tabla no lo
menciona, y §7 dice «las tres options de `business`» sin nombrarlas: los tres sitios
deberían decir lo mismo y dar los tres nombres.

**3.8 · El guardado de rewards exige que la constante sea verdadera, no sólo que exista.**
§1, tabla: «salvo que exista la constante `AKIBARA_REWARDS_ALLOW_WIPE`». El código es
`if ( ! defined( 'AKIBARA_REWARDS_ALLOW_WIPE' ) || ! AKIBARA_REWARDS_ALLOW_WIPE ) { return; }`
(`akibara-rewards/uninstall.php:18-19`): definirla en `false` tampoco borra. Debería decir
«definida **y en `true`**».

**3.9 · Dos citas de línea apuntan al sitio equivocado.**
- «las metas de pedido y de producto, respetadas a propósito (`uninstall.php:14-17`)» —
  se repite tres veces. Ese rango cubre el comentario de los *plugins hermanos*; el de las
  metas es `uninstall.php:16-18`.
- «"Tu pedido {order_number} ya llegó a Akibara" (`ReadyToShipEmail.php:79`)» — `:79` es la
  firma del método; el literal está en `:82`.

**3.10 · Tres cifras distintas para la misma cosa: los textos del panel.** La tabla dice
«`akb_copy_*` (~45 claves)», CU-1 paso 4 dice «los **otros 46** textos» y el diagrama dice
«los 46 textos no tocados». En el *snapshot* hay **48** literales `akb_copy_*` distintos.
Debería ser un solo número, con su fuente, o ninguno.

---

## 4 · Lo que contradice a `verificacion.json` del área (`mapas-b/cross-datos/`)

El capítulo absorbió casi todos los hallazgos, lo cual se nota y se agradece. Quedan cinco
divergencias que conviene dejar dichas, porque quien cruce los dos documentos se va a
tropezar.

**4.1 · Líneas distintas para la tabla del libro mayor, y el capítulo es el que acierta.**
`verificacion.json → errores[5]` cita «`Schema.php:187` y `:191` (columna e índice)»; el
capítulo cita `:177` y `:181`. Comprobado: la columna `event_key VARCHAR(191) DEFAULT NULL`
está en `:177` y el `UNIQUE KEY event_key` en `:181`. Bien el capítulo; convendría anotar
la corrección en la verificación para que no se reabra.

**4.2 · El capítulo pierde el nombre real de la meta del RUT.** La verificación
(`omisiones[3]`) dice que Woo persiste el campo como `_wc_other/akibara/rut`; el capítulo
lo resume como «que Woo persiste con su propia convención» (§7 y tabla de metas). Para una
petición de privacidad o una migración, la convención **es** el dato: sin ella no se puede
consultar `wp_wc_orders_meta`. Debería recuperarse el `meta_key`.

**4.3 · La cola de «Sigue esta serie» pierde su arista de privacidad.** La verificación
(`sospechas_nuevas[0]`) señala que la fila sobrevive al `_akb_sf_optout` y por tanto es
dato personal sin poda. El capítulo recoge la falta de poda como regla de negocio, pero
§14 («Privacidad: lo que queda fuera») **no la incluye** entre lo que no se exporta ni se
borra. Debería estar en la lista de §14 junto al RUT, la bitácora de contacto y el `qlog`.

**4.4 · La clave de Google Maps pierde el matiz que la verificación había medido.** La
verificación (`sospechas_refutadas[2]`) explica que con la constante
`AKB_GOOGLE_MAPS_API_KEY` en `wp-config.php` el campo se pinta `readonly` y la clave **no**
viaja en el HTML (`AddressAutocomplete.php:213-227`). El capítulo lista
`akibara_google_maps_api_key` con «se **inventaría** en Claves y accesos» y no dice cuál es
la vía recomendada ni qué pasa si la clave vive en la option. Es un dato de seguridad que
ya estaba comprobado y se cayó.

**4.5 · El capítulo promete fichero y línea en cada afirmación y no lo cumple en la fila
de terceros.** Cabecera: «Cada afirmación técnica lleva su fichero y su línea». La fila
«`joinchat`, `woocommerce_correios_settings`, `clarity_project_id`,
`woocommerce_enable_myaccount_registration`, … , `wp_page_for_privacy_policy`» de la tabla
de options va sin una sola cita, aunque la verificación (`omisiones[2]`) las traía todas
(`templates/myaccount/form-login.php:30` y `:144`, `akibara-business.php:378`,
`themes/akibara-gp/single.php:24`). Y las cifras «medidas» del capítulo (1.631 documentos
en 30 s; la portada en 2,55 s) no dicen de dónde salen; la de 2,55 s está en un comentario
del código (`AdminPanel.php:284`), la otra no tiene fuente en el capítulo. Conviene decir
además que la verificación fue **sólo lectura del snapshot** (`notas`: «No ejecuté nada en
el espejo ni en producción») y que la cobertura estimada fue 72.

---

## 5 · Diagramas

**Validación:** los siete `.mmd` compilan sin error con `mmdc` (probado uno a uno). Ese
frente está limpio.

**5.1 · No se ven.** El capítulo los incrusta como `![Piezas…](diagramas/17-….mmd)`. Un
`.mmd` dentro de una etiqueta de imagen no lo renderiza ni GitHub, ni la vista previa de
VS Code, ni un visor de Markdown normal: el lector ve una imagen rota y siete títulos
huérfanos. Debería incrustarse el contenido en un bloque ```mermaid, o generar y enlazar
el `.svg`.

**5.2 · `estado-rebuild.mmd` mezcla dos vocabularios y hace imposible la comprobación.**
El nodo dice «ListoParaCambiar: **ready_swap**». `ready_swap` no es una fase de la option
`akb_search_rebuild_state`: es una **topología de tablas**
(`Install::TOPOLOGY_READY_SWAP`, `Install.php:104`). Las fases reales que uno lee en la
option son `PREPARING`, `BUILDING`, `CATCHING_UP`, `SWAP_INTENT`, `SWAPPED`, `PUBLISHING`,
`CLEANUP`, `DONE`, `BLOCKED`, `ABORTED` (`RebuildJob.php:87-96`), y el capítulo no nombra
ninguna. Quien abra `wp option get akb_search_rebuild_state` buscando «ready_swap» no lo
encontrará. Debería etiquetar cada estado con **fase + topología**, que es como se
diagnostica de verdad.

**5.3 · `estado-rebuild.mmd` repite el error de fondo.** «Bloqueado: pestillo permanente,
el vigilante **NO** actúa» y la transición `Bloqueado --> Bloqueado`. Ver 3.1: con
`BLOCKED` el vigilante entra cada 15 minutos, intenta recuperar el *serving* y dispara la
alarma. Debería añadirse una transición `Bloqueado --> Bloqueado: el vigilante intenta
recuperar el serving y avisa a los 15 min` y reservar «permanente» para el rebuild.

**5.4 · `cron.mmd` y la tabla del texto no cuadran en los dos sentidos.** El diagrama
incluye un trabajo que el texto no tiene en ninguna parte —«Al borde de una campaña:
`akb_search_editorial_discount_boundary`», que existe
(`EditorialDiscountPublication.php:32`) pero aparece por primera y única vez ahí— y omite
uno que el texto sí tiene: «Retención de Action Scheduler». Debería cuadrar: o el hook de
borde entra en la tabla con su fila (quién lo agenda, qué republica), o sale del diagrama.

**5.5 · `piezas.mmd` introduce un nombre que el texto nunca usa.** El nodo
`SearchAdminSection · pantalla Buscador`. El capítulo habla de «la pantalla Buscador» sin
nombrar nunca esa clase. O se nombra en §4, o se quita del diagrama.

**5.6 · `piezas.mmd` no tiene la moraleja del capítulo.** Están los cuatro cajones y las
tres fronteras, pero no hay ningún nodo para §15 —las options **sin pantalla**, que son la
excepción a la tesis «todo se opera desde una pantalla»— ni para `wp_bluex_logs`, la tabla
de terceros que un mu-plugin poda (única tabla que el código propio toca sin haberla
creado). Faltan los dos.

**5.7 · `piezas.mmd` dibuja mal el flujo de los correos.** La arista
`CORREOS -->|"woocommerce_akb_*_settings"| BIZ` sugiere que la pantalla de WooCommerce
escribe dentro de `akibara-business`. Lo que hace es escribir cuatro filas de
`wp_options`, que las clases de correo de business **leen** al despachar. Debería ser
`CORREOS --> OPT` y `OPT --> BIZ`, o la etiqueta debería decirlo.

**5.8 · `piezas.mmd` deja al cliente sin la arista del CU-3.** Hay
`CLIENTE --> SEARCH` y `CLIENTE --> BLOCKS`, pero el cliente también **escribe** cuando
deja su correo en la lista de espera (fila en `wp_akb_bis_subscribers`, que es de
`business`). Falta esa flecha, y es el caso de uso 3 del propio capítulo.

**5.9 · `caso-desinstalar-business.mmd` manda el `DELETE` de metas al participante
equivocado.** `Unins->>Opts: DELETE de termmeta _akb_% y usermeta _akb_sf_%`: `Opts` es
`wp_options`; ese borrado es sobre `wp_termmeta` y `wp_usermeta`. El diagrama ya tiene el
participante `Metas`. Enseña mal justo la distinción entre cajones que el capítulo quiere
dejar clara.

**5.10 · `caso-lista-de-espera.mmd` convierte dos puertas en serie en ramas
excluyentes.** El bloque `alt / else / else / else` pone `akb_bis_dispatch_enabled`, el
mailer no cargable y el `enabled` del correo como alternativas. En el código son
comprobaciones **secuenciales** dentro de la misma pasada
(`NotificationDispatcher.php:209`, mailer en `:224`, correo en `:233`). Con las dos
palancas apagadas, el diagrama sugiere que sólo actúa la primera. Debería ser una cadena
de pasos con una salida por cada puerta. Además, el `Ficha->>Ficha: ¿akb_bis_capture_enabled
vale yes?` no tiene rama de «no»: no se dice qué ve el cliente si la captura está apagada.

**5.11 · `caso-descuento-editorial.mmd` nombra una clase que el capítulo no presenta y le
falta la bifurcación cara.** El diagrama menciona `AdminFieldType::save_row()`, que no
aparece en ningún punto del texto, y **no dibuja la bifurcación C** (valor fuera del
dominio cerrado), que el texto marca como la familia de fallo de las tres horas sin
buscador del 2026-09-05. La bifurcación que más cuesta es la que no está en el dibujo.

**5.12 · `caso-texto-del-panel.mmd` trae un dato que el texto no tiene.** La nota «entrar y
guardar llegó a dejar la portada en 2,55 s» frente al «llegó a tirar el borde entero» de
§3. El dato existe (`AdminPanel.php:284`: «la portada pasa de 0,21-0,36 s a 2,55 s»), pero
el capítulo y el diagrama deben contar lo mismo, y con la cita.

---

### Resumen de puntos

- **No entendí:** 14 · **Falta para operar:** 18 · **Contradice al código:** 10
- **Contradice `verificacion.json`:** 5 · **Diagramas:** 12 (los siete validan con `mmdc`)
- Los tres más graves, por si hay que arreglar sólo tres: 3.1 (el vigilante sí actúa con el
  estado bloqueado, y el capítulo enseña la conducta anterior al arreglo), 3.4 (los correos
  de puntos no tienen segundo interruptor) y 5.2 (el diagrama de estados usa un nombre que
  no se puede encontrar en la option).

---

## Respuesta del redactor

Crítica aplicada el 2026-09-12. **De los 49 puntos, 48 se aplicaron** al capítulo, a sus
siete diagramas y al `resumen.json`. Uno se comprobó contra el código y **no** se aplicó,
porque el código dice lo contrario. Va abajo, con la evidencia, para que nadie lo reabra.

### El único punto que el código desmiente

**2.17 — «guardar en Sinónimos o en Palabras ignoradas dispara una reconstrucción».** No la
dispara. Las dos sub-pestañas se han añadido al capítulo con sus options
(`akb_search_synonyms`, `Synonyms.php:26`; `akb_search_stopwords`, `Stopwords.php:26`)
—eso era una omisión real y se corrigió—, pero **con el hecho al revés del que afirma la
crítica**, y dicho explícitamente para que nadie lo suponga:

- La propia pantalla lo promete al pintar cada formulario: «Al buscar cualquiera del grupo,
  el buscador matchea todos — **sin reindexar**» (`SearchAdminSection.php:1815`) y «Aplica
  de inmediato, **sin reconstruir el índice**» (`:1877`).
- Y el guardado lo cumple: `handle_save_synonyms()` (`:1839-1867`) y
  `handle_save_stopwords()` (`:1895-1919`) hacen exactamente dos cosas — `persist_options_exact()`
  sobre la option y `Synonyms::flush()` / `Stopwords::flush()`, que sólo ponen a `null` tres
  propiedades estáticas del proceso (`Synonyms.php:527-531`, `Stopwords.php:78-80`). No hay
  encolado, no hay `pending_rebuild`, no se toca la cola `wp_akb_search_work`.

La confusión viene de la línea del `CLAUDE.md`: «guardar cualquier **término** dispara un
rebuild». Ese «término» es un término de **taxonomía** del catálogo —una editorial o una
serie, cuyo termmeta `_akb_taxonomy_discount_pct` está bajo el cerrojo de §5—, no un término
de la lista de sinónimos. Son dos cosas distintas con la misma palabra. El capítulo ahora lo
separa en la sección §4 y en la tabla de Reglas de negocio.

### Lo que sí cambió, agrupado

**Las diez contradicciones con el código (§3) están todas corregidas**, incluidas las tres
que la propia crítica marcaba como las más graves:

- **3.1** era, en efecto, el error más caro: el capítulo enseñaba la conducta **anterior al
  arreglo** del 2026-09-05. Ahora el capítulo, la tabla de «Lo programado», el CU-4 y el
  diagrama de estados dicen lo que dice `watchdog_recovers_serving()`
  (`RebuildJob.php:692-700`): con la fase en `BLOCKED` o `ABORTED` el vigilante **sí** entra,
  intenta el arreglo barato del *serving* y manda la alarma a los 15 minutos. La palabra
  «permanente» quedó reservada para lo único que lo es: el rebuild.
- **3.4** obligó a reescribir el título y la tesis de §9: ahora distingue **los cuatro
  correos que son `WC_Email`** (dos interruptores) de **los seis de puntos** (uno solo, en
  Puntos y Reseñas), con la cita del propio docblock que lo dice.
- **5.2** cambió el diagrama de estados entero: cada estado lleva ahora sus **dos**
  coordenadas, fase y topología, y el capítulo enumera las diez fases reales
  (`RebuildJob.php:87-96`) y las cuatro topologías (`Install.php:103-106`), advirtiendo que
  `ready_swap` nunca aparecerá dentro de la option.

Las cifras se corrigieron contra el *snapshot*: **61** módulos, **40** campos de rewards en
seis secciones, **ocho** hooks de termmeta, **48** claves `akb_copy_*` distintas, **tres**
options de `business` que lee el plugin de bloques. Las dos citas torcidas ahora apuntan a
`uninstall.php:16-18` y `ReadyToShipEmail.php:82`.

**Los dieciocho huecos de operación (§2)** se cerraron con lo que faltaba para poder actuar:
el patrón de URL `admin.php?page=akibara-panel&tab=<id>` con **los 19 `id`**, la aclaración
de que Buscador y Claves y accesos son **submenús, no secciones**, dónde se recoloca la
pantalla Buscador si `business` cae, los hooks reales de los dos crons de rewards **y el
dato de que van sin grupo**, `akb_rewards_expiry_warn_async` con su bifurcación
`use_batches()`, la alarma completa (`akb_search_serving_alarm`, 900 s, el asunto y los dos
comandos de verificación), `akb_search_rebuild_history` y el transient
`akb_search_watchdog_guard_v2` con sus 12 horas, el `ops/rescate-buscador.sh` explicado paso
a paso, el `&section=<EMAIL_ID>` de cada correo, el registro de `akibara-rewards`, la poda
de los tokens de reseña, la vida y el barrido de `akb_pudo_state_*`, el fichero que escribe
el transient del tema, la arista de privacidad de la cola de series, y una sección nueva
—**§16**— con `wp option list --search='akb_*'` y el aviso de que ese prefijo es de los
cuatro plugins.

**Las catorce dudas de lectura (§1)** se resolvieron sobre todo en el glosario, que pasó de
una lista plana a cinco bloques: se añadieron *deuda*, *recibo*, *materializar*, *fuente*,
*foto de la fuente*, *autoritativa*, *dominio cerrado*, *hook*, *filtro*, *acción*,
*prioridad*, *saneador*, *fase*, *topología* y *grupo* de Action Scheduler. La regla de oro
abre §15 en vez de cerrarla; la media línea de qué hace cada pestaña está en la tabla de
§2; y los 30 segundos contra los 6 minutos tienen por fin su explicación en el CU-2 (el
tiempo no es de construir, es de esperar la cola y el ciclo de 5 minutos del cron de
Hostinger).

**Las cinco divergencias con `verificacion.json` (§4)** se absorbieron: el `meta_key`
`_wc_other/akibara/rut` vuelve al capítulo, la cola de series entra en §14, la constante
`AKB_GOOGLE_MAPS_API_KEY` recupera su matiz de seguridad, la fila de options de terceros
lleva ahora fichero y línea una por una, y la cabecera dice de dónde sale cada cifra medida
y que la verificación fue **sólo lectura del snapshot**, con cobertura estimada 72.

Sobre **4.1**: confirmado, el capítulo acierta y la verificación se equivoca. La columna
`event_key VARCHAR(191) DEFAULT NULL` está en `Schema.php:177` y el `UNIQUE KEY event_key`
en `:181`; `:187` y `:191` caen dentro del mismo `CREATE TABLE` pero en otras líneas. Queda
anotado aquí para que no se reabra.

**Los doce puntos de los diagramas (§5)** están aplicados, incluido el primero, que era el
más barato y el de mayor efecto: **5.1**. Los siete diagramas ya no se enlazan como imagen
—un `.mmd` dentro de `![…]()` no lo renderiza nada— sino que van **incrustados en bloques
` ```mermaid ` dentro del capítulo**, con el enlace al `.mmd` debajo por si alguien quiere
el fuente. Los siete ficheros siguen siendo la fuente de verdad y **los siete vuelven a
compilar sin error con `mmdc`**, uno a uno, después de reescribirlos.
