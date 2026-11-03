# Crítica de lector novato — `08-search-indice-y-rebuild.md`

> Quién escribe: alguien que llega hoy al proyecto, no sabe qué es WordPress ni WooCommerce,
> y tiene que quedarse a cargo del buscador. Leí el capítulo entero y sus siete diagramas, y
> después abrí el snapshot congelado de `akibara-search` 0.1.60 para comprobar lo que me
> chirriaba. Todas las citas de abajo son de ese snapshot
> (`scratchpad/snapshot-prod/plugins/akibara-search/`), salvo `ops/rescate-buscador.sh`.
>
> Antes de la lista, lo justo: el capítulo es sólido. Verifiqué **una por una** las citas de
> la tabla de reglas de negocio y de las fichas de pieza, y la enorme mayoría apunta al sitio
> exacto (`Install.php:683`, `uninstall.php:81`, `WorkRepository.php:41` y `:651`,
> `Reconciler.php:115`, `:146-149`, `:57`, `:63`, `:66`, `HealthReport.php:32`, `:35`, `:57`,
> `:477-487`, `EditorialDiscountPublication.php:31`, `:47`, `:72`, `:85`, `:88`, `:283`,
> `:2508`, `:3686`, `Indexer.php:50`, `:1267`, `:1945`, `:1948`, `Search.php:322`,
> `SearchAdminSection.php:1779`, las siete funciones puente en `:95/:105/:116/:127/:139/:161/:191`,
> los 25 oyentes, los 15 campos de `ProductSourceAuthority`, `DB_VERSION = '1.9.0'`,
> `SCHEMA_SEAL = '1.7.0-exact-1'`, y las cuatro afirmaciones de «código sin llamador», que
> comprobé con `grep` sobre todo el snapshot: ninguna tiene llamador). Lo que sigue es lo que
> **no** me dejó operar solo.

---

## 1. Lo que NO entendí

**1.1 «503» aparece 14 veces y no se explica nunca.** Frase del capítulo: *«durante el tramo
`SWAP_INTENT` → `DONE` el autocompletado y los filtros se apagan (503) a propósito»*. Es el
concepto central —el capítulo entero existe para explicar cuándo se apaga el buscador— y para
mí «503» no significa nada. **Debería decir**, en el glosario: «**503**: el código con el que un
servidor contesta “ahora no puedo, vuelve luego”. El navegador del cliente no muestra un error
feo: el desplegable simplemente no aparece y el catálogo se pinta sin filtros. Lo contrario es
**200**, “aquí tienes”.»

**1.2 «Generación» es la pieza que no entendí y de la que depende todo.** Aparece en «se escribe
`generation_target`», «publica la generación y verifica su recibo de purga», «seis
confirmaciones: buckets de precio, **generación**, purga REST…», en la option
`akb_search_index_gen` («La generación viva») y en el «buzón de publicación de generación»
(`akb_search_generation_outbox`). Nunca se dice qué es. **Debería decir**: «**Generación**: un
número de serie del índice. Cada vez que el índice cambia de contenido, sube. Sirve para dos
cosas: que la purga de la caché sepa que hay algo nuevo que tirar, y que el recibo pueda
afirmar “yo certifico la generación 47, no otra”. Vive en `akb_search_index_gen`; cambiarla es
lo que dispara `RestCache::purge()`.» Y en el glosario, junto a «recibo».

**1.3 «Run» y «`run_id`».** *«`init:20` ejecuta `Install::consume_pending_rebuild()` y crea el
run durable»*. ¿Un run es una reconstrucción? ¿Una tanda? El capítulo lo usa también en
«recibo por run» y «`active_run_id`», y en el ámbito `run_id` de la tabla `work`. **Debería
decir**: «**Run**: una reconstrucción concreta, con nombre propio (`run_id`). Todo lo que esa
reconstrucción apunta en la libreta lleva su nombre, para que una acción vieja de otro run no
toque el que está vivo.»

**1.4 «Idempotente» sin traducir, seis veces.** *«seis confirmaciones idempotentes en orden»*,
*«la migración es idempotente»*, *«cada paso es idempotente»*. **Debería decir** (glosario):
«**Idempotente**: repetirlo no hace daño. Correrlo una vez o cinco deja el mismo resultado; por
eso el sistema puede reintentar sin miedo cuando no sabe si algo llegó a pasar.»

**1.5 «Fan-out».** Aparece cuatro veces sin aviso: *«Fan-out del término… y pide rebuild
completo»*, *«el fan-out durable de todos los productos afectados»*. **Debería decir**:
«**Fan-out**: partir de una cosa que cambió (un término, por ejemplo “Ivrea”) y resolver la
lista de todos los productos a los que afecta, para apuntarlos uno a uno.»

**1.6 «Live-lock» aparece una sola vez, en la sección más peligrosa, y sin explicación.**
*«exige `Install::runtime_ready()` —es decir, también `pending_rebuild_is_ready()`, la barrera
del live-lock del 2026-09-05»*. Tres funciones con nombres casi iguales (`runtime_ready()`,
`runtime_schema_ready()`, `pending_rebuild_is_ready()`) que el capítulo usa como si ya las
hubiera presentado, y un término («live-lock») que no está ni en el glosario. **Debería decir**
qué comprueba cada una en una frase y qué era el live-lock: una señal de rebuild con
`not_before` en el futuro que bloqueaba el carril automático mientras la gracia no venciera.

**1.7 «Lease» significa dos cosas distintas.** El glosario define **arriendo (lease)** como «el
préstamo temporal de una tarea a un obrero». Pero en el gate de caché de página se lee *«la
lease resuelve»*, que es otra cosa completamente distinta (el permiso de servido que concede
`EditorialDiscountPublication::serving_ready()`). **Debería** usar dos palabras distintas, o
decir explícitamente: «aquí “lease” no es el arriendo de una tarea, sino el permiso de servido».

**1.8 Media docena de campos de las options aparecen sin significado.** La tabla de datos lista
`protocol`, `expected`, `barriers`, `not_before`, `debt_staged`, `purge_pre_seq`,
`purge_post_seq`, `acked_seq` del buzón, `ack_scope: local_dispatch_only`. Son justo los campos
que uno mira cuando algo falla, y del texto sólo puedo deducir `wanted_seq`/`acked_seq` (bien
explicados en `WorkRepository`). **Debería** llevar una línea por campo, aunque sea telegráfica:
`purge_pre_seq` = «la purga que se hizo **antes** de dejar pasar la escritura»; `debt_staged` =
«la deuda ya quedó anotada»; `not_before` = «no empieces antes de este instante».

**1.9 El prólogo se apoya en dos documentos que no existen para mí.** *«Donde el mapa de lectura
y el verificador se contradijeron, manda el verificador»*. No sé qué son, ni dónde están, ni por
qué el verificador manda. **Debería** o explicarlos en una nota al pie (son artefactos internos
de la fase de lectura, no publicados) o borrar la referencia: al lector nuevo le suena a que le
falta un documento.

**1.10 Referencias a cosas nunca presentadas.** (a) *«Si el término no pertenece a una taxonomía
indexada, no pasa nada»* — nunca se dice **cuáles** son las taxonomías indexadas; en el código
salen de `FacetRegistry::indexed_taxonomies()` más `product_visibility` a mano
(`Indexer.php:1926-1931`), y `FacetRegistry` no es una pieza del capítulo. (b) *«registrar el
protocolo editorial después de que business, en prioridad 20, enganche su **proveedor de
precio**»* — «proveedor de precio» aparece de la nada. (c) *«la barrera del live-lock»*, ya
dicho. (d) *«en el espejo hay **Mailpit**»* — sin explicar que es un buzón de correo falso para
pruebas.

**1.11 El «re-probado» se usa antes de existir.** En la ficha de `Indexer` (bastante antes de la
de `EditorialDiscountPublication`) se lee: *«Ninguno de los dos lo puede retirar el atajo del
re-probado»*. El «atajo barato» del re-probado se explica veinte párrafos más abajo. **Debería**
llevar ahí un «(ver “El atajo barato”, más abajo)» o mover la frase.

**1.12 Racimo de jerga de base de datos sin traducir**, todo en dos páginas: «el **DDL** de las
tablas», «no se fía de **dbDelta**», «verifica el contrato físico contra **information_schema**»,
«motor **InnoDB**», «dos **transacciones** … y hace **COMMIT**», «option **sin autoload**»,
«vive en la **caché de objetos** si hay **drop-in persistente**». El glosario define collation
—muy bien— y deja fuera las otras siete. Para el público declarado («alguien que NO conoce
nada») eso es una pared. **Debería** añadirlas al glosario en una línea cada una, o sustituirlas
por la idea («la forma de las tablas», «la lista oficial que la base lleva de sus propias
tablas», «o entra todo o no entra nada»).

---

## 2. Lo que FALTA para operar o depurar esto sin ayuda

**2.1 La pantalla que el capítulo nombra 30 veces no tiene dirección.** «Buscador → Índice»,
«Buscador → Ajustes», «Buscador → Facetas», «Buscador → Precios», «Diagnóstico técnico» …
y en ningún sitio se dice **cómo llegar**. En el código: `admin.php?page=akibara-search&sub=…`
con siete solapas exactas —`indice` (la de por defecto), `busquedas`, `facetas`, `precios`,
`sinonimos`, `stopwords`, `ajustes` (`SearchAdminSection.php:35`, `:156`)—, y el menú
«Buscador» cuelga de **«Akibara»** si el panel de business existe, y si no aparece como menú
propio con lupa (`SearchAdminSection.php:110-145`). **Debería** decir exactamente eso, una vez,
al principio: sin ello no puedo ni empezar.

**2.2 No hay ficha de pieza para `SearchAdminSection`.** Es la única pieza del diagrama
(`PANEL`) que no tiene entrada en «Piezas», y es la superficie donde el capítulo manda al
operador en cada sección («Dónde lo opera el dueño»). Tampoco se dice qué es «Mantenimiento»,
«Servicios» o «Diagnóstico técnico» (¿solapas? ¿cajas dentro de `indice`?). **Debería** llevar
ficha con el mismo molde que las demás, y un mapa de las cajas de la solapa `indice`.

**2.3 `wp akb-search status` no imprime lo que el capítulo dice que imprime.** El capítulo
describe el correo de alarma así: *«da dos órdenes en orden: `wp akb-search status` y
`wp akb-search rebuild`, con la pista de que si dice “hay uno en curso” hay que mirar que `done`
avance y `work_blocked` esté en cero»*. Comprobado: `Cli::cmd_status()` (`src/Cli.php:140-163`)
imprime **sólo** cuatro conteos de filas (docs, tokens, facets, qlog) y dos JSON —
`akb_search_last_rebuild` y `akb_search_reconcile_last`—. `done` y `work_blocked` no están ahí:
son claves de `RebuildJob::status()` (`src/RebuildJob.php:365`, `:374`) que sólo pintan el panel
y la ruta REST. **Debería** decir: «el correo pide mirar `done` y `work_blocked`; `wp akb-search
status` **no** los muestra — están en el panel, en
`GET /wp-json/akibara/v1/search/admin-status`, o crudos en
`wp option get akb_search_rebuild_state --format=json`». (Ver también 3.7.)

**2.4 Ningún comando para mirar el estado por la terminal.** El capítulo repite «el panel lo
cuenta», «el panel la muestra», pero si el panel no carga —que es medio incidente de los
descritos— me quedo ciego. **Debería** listar, en el caso 10 o en un apartado «cómo mirar»:
`wp option get akb_search_rebuild_state --format=json`, `wp option get akb_search_pending_rebuild
--format=json`, `wp option get akb_search_serving_alarm --format=json`, y un `SELECT status,
COUNT(*) FROM wp_akb_search_work GROUP BY status;` para los contadores «pendientes / arrendados
/ bloqueados» que la ficha de `WorkRepository` menciona.

**2.5 `ops/rescate-buscador.sh` también corre contra el espejo, y el capítulo no lo dice.** El
guion acepta dos destinos: `bash ops/rescate-buscador.sh espejo` (por `docker compose`) y
`… produccion` (por `ssh akibara`), y su propia cabecera documenta los dos. El capítulo sólo
enseña `produccion`. En una casa cuya primera regla es «primero en local», omitir el modo
`espejo` es justo el dato que falta. **Debería** enseñar el de espejo primero, y decir los
requisitos (`ssh akibara` funcionando, o Docker levantado).

**2.6 «Sólo en producción» sin decir cómo se decide qué es producción.** El correo se manda si
`wp_get_environment_type() === 'production'` (`src/RebuildJob.php:828`). Eso depende de una
constante/variable de entorno del sitio, no del plugin. **Debería** decirlo: «si el espejo
estuviera marcado como `production`, mandaría correos de verdad; se mira con
`wp config get WP_ENVIRONMENT_TYPE` / `wp eval 'echo wp_get_environment_type();'`».

**2.7 El correo: destinatario sin dónde se cambia.** Se dice «al `admin_email`» cinco veces y
nunca que eso es el correo del sitio en Ajustes → Generales de WordPress, ni cómo verlo
(`wp option get admin_email`). Para alguien que llega, «admin_email» es una variable mágica.

**2.8 De lo programado no se puede comprobar nada.** El capítulo explica muy bien que los memos
de 12 h pueden dejar al sistema sin vigilante medio día — y no dice **cómo mirar si el vigilante
existe** ni **cómo forzar que se recree**. **Debería** cerrar esa sección con: dónde se ven las
acciones (WooCommerce → Estado → Acciones programadas, buscando el grupo `akibara-search`), y
que borrar los transients `akb_search_watchdog_guard_v2` y `akb_search_reconciler_scheduled`
(`wp transient delete …`) obliga a re-comprobar en el siguiente arranque.

**2.9 Tres filtros que sí son palancas invisibles y no están en el capítulo.** La tabla de
reglas afirma *«no hay palanca invisible»*. En el código del área hay tres filtros de
configuración que nadie documenta: `akb_search_editorial_reconcile_throttle`
(`EditorialDiscountPublication.php:328`, cambia los 60 s del estrangulador),
`akibara_taxonomy_discount_max_pct` (`:2669`) y `akibara_taxonomy_discount_taxonomies` (`:2955`,
decide **qué taxonomías** llevan descuento editorial). El capítulo sólo lista los tres overrides
de QA de `Search`. **Debería** listarlos con quién los usa hoy (business, o nadie).

**2.10 Piezas del código que el capítulo no menciona** (comparando el `ls` del snapshot con la
sección «Piezas»). Del `src/` que pertenece a este lado del plugin quedan fuera:
`Normalizer.php` (sólo asoma como `Normalizer::MAX_TOKEN_LENGTH`; es quien decide qué es una
palabra, y de él depende todo lo que `DocumentBuilder` tokeniza), `QueryLogger.php` (el **único
escritor** de `wp_akb_search_qlog`, tabla que el capítulo documenta y que el `Reconciler` poda:
quien lea esto concluye que el log lo escribe el reconciliador), `PriceBuckets.php` (la primera
de las «seis confirmaciones» y lo que `akibara_search_activate()` «siembra» al activar),
`FacetRegistry.php` (define las facetas y **la lista de taxonomías indexadas** de 1.10; sólo
aparece en una cita de la tabla de reglas), `SeriesSuggestions` (se registra **siempre** en
`Search.php:126-131`, y su invalidación al renombrar o borrar una serie es exactamente el caso 1
del capítulo) y `Support/RateLimit.php` (dueño del prefijo `akb_rlm_`). **Debería**, como
mínimo, una línea por cada una en «Las piezas pequeñas», aunque sea para decir «esto se cuenta
en el capítulo 09».

**2.11 Las cuatro familias de prefijos se nombran sin dueño.** *«filtra las options por cuatro
prefijos exactos —`akb_search_`, `akb_vocab_`, `akb_lc_`, `akb_rlm_`—»*. El propio
`uninstall.php:75-79` dice de quién es cada uno (vocabulario del fuzzy → `QueryEngine`; conteos
de facetas → `FacetService`; contador del rate-limit → `Support\RateLimit`). Sin eso no puedo
saber qué perdería si borro uno.

**2.12 Falta el atajo «síntoma → dónde mirar».** Hay once casos excelentes, ordenados por
historia, y ninguna entrada por lo que ve el que llega: «el desplegable no aparece», «el
catálogo salió sin filtros», «el panel dice *bloqueada*», «me llegó el correo», «guardé un
término y todo se puso lento». **Debería** llevar una tabla de cuatro filas al principio de
«Casos de uso» que mande a la sección correcta.

**2.13 Falta el efecto del gate cerrado sobre el reindexado incremental.** El capítulo sólo
cuenta que la revisión **diaria** aborta con el gate editorial cerrado. Pero el camino live hace
lo mismo: `Indexer::process_live_claim()` llama a `capture_price_authority()` y **lanza** si no
devuelve foto (`src/Indexer.php:553-563`), lo que manda el claim a `retry_claim()`. Es decir:
mientras el gate está cerrado, cada intento de reindexar un producto **quema un reintento**, y
al octavo la fila queda `blocked` (`WorkRepository.php:41`, `:651`). Eso explica el síntoma
«cambié el stock y no se actualizó» que el caso 4 promete resuelto «en segundos», y no está en
ninguna parte del capítulo.

**2.14 Nada sobre el arranque en frío.** La tabla de datos dice que `akb_search_indexing` viene
por defecto en `no`. En un entorno nuevo (o tras restaurar una copia), ¿qué enciendo, en qué
orden, y cómo sé que el esquema quedó sellado? **Debería** haber un párrafo de «primer
arranque»: activar, comprobar `akb_search_db_version` = `1.9.0` y `akb_search_schema_seal` =
`1.7.0-exact-1`, encender «Indexación automática», lanzar `wp akb-search rebuild`.

---

## 3. Lo que CONTRADICE al código

**3.1 «Nadie escucha los cambios del catálogo» con la indexación apagada — falso.** Frase:
*«“Indexación automática” apagada (`akb_search_indexing`): `Search::register()` sale antes de
`register_listeners()`. **Nadie escucha los cambios del catálogo**, y la revisión diaria tampoco
se agenda»*. La segunda mitad es cierta; la primera no. Antes de ese gate se registran, y con un
comentario que dice «SIEMPRE e independiente de los flags»: `Arrival::register()`
(`src/Search.php:143`, que sigue escribiendo `_akb_onbackorder_at` y `_akb_first_instock_at` en
cada cambio de stock), `Novedad::register()` (`:120`), la invalidación del catálogo de series
(`:131`), `SemanticPublication::register()` (`:132`) y el protocolo editorial en
`plugins_loaded:30` (`:137`), que sigue interceptando y **vetando** escrituras de options.
**Debería decir**: «lo que se apaga son los 25 oyentes del `Indexer` y el agendado de la revisión
diaria; `Arrival`, `Novedad`, las series y los dos protocolos de publicación siguen enganchados
a propósito».

**3.2 Citas corridas en `Search.php`.** *«las rutas ya registradas degradan a 503 en vez de 404
(`:170-173`)»* — la comprobación es `if ( ! Install::runtime_schema_ready() ) { return; }` en
**`:172-174`**; `:170-171` es el comentario. Además el mismo hecho se cita dos veces con rangos
distintos: `:179-183` en «Qué pasa si se apaga» y `:179-186` en la ficha de `Search` (el bueno es
`:179-181` para el `return`, `:183` los oyentes, `:186` la revisión diaria).

**3.3 El intervalo del vigilante no está en `RebuildJob.php:82`.** La tabla de reglas dice:
*«El intervalo del vigilante (900 s) … `src/RebuildJob.php:82`»*. En `:82` está
`WATCHDOG_GUARD_TTL = 43200` (el memo de 12 h). `WATCHDOG_INTERVAL = 900` está en **`:78`**.
Dos cosas distintas a cuatro líneas de distancia, y el capítulo habla de las dos.

> **Nota del 2026-09-19 (S-3e1f3f03).** Esta crítica cita el snapshot 0.1.60, y ahí es exacta. En el
> código posterior a search 0.1.67 la constante `WATCHDOG_GUARD_TTL` **ya no existe**: la vida del
> memo la decide `RecurringMemo::ttl( WATCHDOG_INTERVAL )` (`src/Support/RecurringMemo.php`), 900 s
> con techo de una hora. Quien la busque por nombre no la va a encontrar; el capítulo lo explica
> en «El watchdog cada 15 minutos, no cada 5».

**3.4 El comentario del vigilante no está en `:68-82`.** *«El comentario que lo explica
(`:68-82`)»*: el bloque va de `:62` a `:77`, y la línea del comando equivocado —la que el
capítulo caza muy bien— es **`:75`**. Citar `:75` ahorra buscar.

**3.5 Hay DOS mensajes «Ya hay un rebuild en curso», y el capítulo describe uno y usa el otro.**
La ficha del `Cli` dice: *«`wp akb-search rebuild` sin argumentos corre síncrono: … no espera
barreras temporales, sólo exige el esquema. Con `--async`, en cambio … imprime literalmente “Ya
hay un rebuild en curso — no se encoló otro” con código de salida 0»*. Pero el caso 10 dice:
*«`wp akb-search rebuild` responde “Ya hay un rebuild en curso” aunque la option esté vacía»* —
el comando **sin** `--async`. Las dos cosas son ciertas y son mensajes distintos
(`src/Cli.php:68-74` vs `:79-82`): el asíncrono imprime `Ya hay un rebuild en curso — no se
encoló otro.` con `WP_CLI::log` y salida **0**; el síncrono imprime `Ya hay un rebuild en curso
(option akb_search_rebuild_state).` con `WP_CLI::error` y salida **distinta de 0**. **Debería**
poner los dos textos literales, uno al lado del otro, con su código de salida: es exactamente lo
que el de guardia necesita para saber por qué carril entró.

**3.6 «Ese reloj es exactamente la ventana en que el desplegable responde 503» — no.** Frase
completa: *«Medido sobre un rebuild real de 1.568 documentos: 37 s de trabajo repartidos en 21
acciones … que tardaron 158 s de reloj … Y ese reloj es exactamente la ventana en que el
desplegable responde 503»*. Contradice al código y al propio capítulo dos párrafos antes: los
158 s son la fase `BUILDING`, y durante `BUILDING` las tablas vivas sirven y el gate está
abierto —el gate sólo mira estado contra recibo (`EditorialDiscountPublication.php:2508-2552`) y
esos no se mueven hasta la publicación—. La ventana de 503 es `SWAP_INTENT` → estado borrado,
como dicen `:202` y el diagrama de estados. (Y contradice lo medido en casa: un rebuild pedido a
mano son ~30 s con el `suggest` en 200 todo el rato.) **Debería decir**: «ese reloj es la ventana
en que un rebuild disparado por un cambio **editorial** deja el buscador caído, porque en ese
caso el gate ya estaba cerrado desde la escritura; en un rebuild no editorial el cliente sigue
buscando».

**3.7 El capítulo repite la promesa del correo sin comprobarla.** Ver 2.3: el cuerpo del correo
(`src/RebuildJob.php:775-792`) manda mirar `done` y `work_blocked` después de `wp akb-search
status`, y ese comando no los imprime. El capítulo lo transcribe como si funcionara. Es un fallo
del **correo**, no del capítulo — pero un capítulo que se lee a las tres de la mañana debería
avisarlo, y de paso dejarlo anotado como deuda.

**3.8 `Install` no engancha nada.** Su ficha dice *«Cuándo actúa: `init` prioridad 20 →
`consume_pending_rebuild()`; `init` prioridad 22 → `consume_generation_publications()`»*. Esos
dos `add_action` no están en `Install`: los registra `Search::register()` (`src/Search.php:116`)
y `RebuildJob::register_handlers()` (`src/RebuildJob.php:139`). Importa porque, buscando quién
engancha qué, en `Install.php` no hay nada y uno cree que se equivocó de fichero. **Debería
decir**: «sus métodos corren en `init:20` y `init:22`, pero quien los engancha es `Search` y
`RebuildJob`; `Install` no tiene hooks propios».

**3.9 La purga REST está detrás del sello de esquema, y la ficha de `RestCache` no lo dice.**
*«Cuándo actúa: `update_option_akb_search_index_gen` y `add_option_akb_search_index_gen`
prioridad 10 → `purge()`»*. Esos dos `add_action` viven en `Search.php:176-177`, **después** del
`return` de `runtime_schema_ready()` (`:172-174`). Con el sello ausente —el escenario del caso
7— cambiar la generación no purga nada, y el capítulo promete lo contrario.

**3.10 Una precisión de la ficha de `Arrival`.** *«Hoy nadie lee ese dato»* es cierto (lo
comprobé con `grep` sobre los cuatro plugins y el tema: sólo `Arrival.php` y un comentario de
`Install.php:36`), pero falta que **sí hay otro escritor**: el `Reconciler` llama a
`Arrival::seed_pending()` cada día, con tope 200 (`src/Arrival.php:48`, `src/Reconciler.php:403`).
El capítulo lo menciona en la tabla de lo programado («siembra de hasta 200 preventas
antiguas») y no en la ficha de `Arrival`, que es donde se va a buscar.

---

## 4. Lo que contradice a `verificacion.json` del área

*(fuente: `scratchpad/mapas/search-indice/verificacion.json`, cobertura declarada 88 %)*

**4.1 El anillo de recibos: el capítulo corrige al verificador y no lo dice.** `verificacion`
sitúa la poda en *«EditorialDiscountPublication.php:1672-1705»*; el capítulo cita `:3686`. El
capítulo tiene razón (`if ( ! is_array( $rows ) || count( $rows ) < self::RUN_RECEIPT_KEEP )` está
en `:3686`, y `array_slice(..., RUN_RECEIPT_KEEP)` en `:3700`). Como el prólogo promete que
«manda el verificador», **debería** quedar dicho: aquí mandó el código.

**4.2 La comparación del gate se cita de tres maneras.** `verificacion` dice `:2540-2551`; la
prosa del capítulo dice *«`bundle_is_ready()` exige `state.wanted_seq === receipt.wanted_seq`
(`:2508`, la comparación en `:2545`)»*; y su propio diagrama `caso-descuento-editorial` escribe
`EDP.php:2540`. Comprobado: la función empieza en `:2508`, el `if` del veredicto en `:2540` y la
comparación concreta de secuencias en `:2545`. **Debería** usar el mismo par en los tres sitios.

**4.3 Rangos de `Indexer` distintos.** `verificacion` habla de `register_listeners()` «líneas
157-183» y handlers «146-148» (los `add_action`); el capítulo cita `:156-183` y `:145-149` (las
declaraciones de método). Ninguno miente, pero quien coteje los dos documentos pierde cinco
minutos. Comprobado: método en `:156`, primer oyente en `:157`, último en `:183`.

**4.4 El capítulo hereda la cobertura del 88 % sin heredar su advertencia.** `verificacion`
avisa: *«`WorkRepository.php` y `ProductSourceAuthority.php` leídos por secciones dirigidas»*.
Las dos afirmaciones más vistosas del capítulo sobre esas clases —«compara **quince** campos» y
el octavo intento— caen justo ahí. Las comprobé y **las dos son correctas**
(`ProductSourceAuthority.php:289-291` enumera 15 campos; `WorkRepository.php:41` y `:651`), así
que el problema no es el dato sino que el capítulo presenta todo con la misma confianza. **Debería**
llevar las citas de línea de esos dos (hoy la de los 15 campos no tiene).

**4.5 El capítulo omite la única sospecha que el verificador refutó, y conviene que esté.**
`sospechas_refutadas[0]`: «un buscador caído purga Cloudflare entero cada 15 minutos» — **no
ocurre**, porque la purga total sale de `record_swap_receipt()` y con `BLOCKED` el rebuild ni
arranca. Es exactamente la clase de hipótesis que a las tres de la mañana uno se inventa solo.
**Debería** aparecer como nota en el caso 5: «lo que **no** pasa: el vigilante no purga
Cloudflare en cada vuelta».

---

## 5. Diagramas

**Validación:** los **siete** `.mmd` compilan sin errores con `mmdc` (código de salida 0 en los
siete: `piezas`, `estados-rebuild`, `cron`, `caso-guardar-termino`, `caso-descuento-editorial`,
`caso-stock-incremental`, `caso-watchdog-alarma`).

**5.1 `piezas`: la cuenta no cuadra.** La cabecera dice *«las 22 piezas del área»*; conté **23**
nodos (4 + 3 + 6 + 3 + 3 + 4). O sobra una en el texto o falta una en el dibujo.

**5.2 `piezas`: `wp_akb_search_qlog` no tiene quien la escriba.** La única flecha que la toca es
`RECON --> QLOG`, que es la **poda**. Quien escribe el log es `QueryLogger`, que no está en el
diagrama ni en el capítulo (ver 2.10). Un novato concluye que el reconciliador escribe las
búsquedas.

**5.3 `piezas`: faltan nodos que el texto nombra.** `FacetRegistry` (citado en la tabla de
reglas y dueño de las taxonomías indexadas), `Normalizer` (citado en `DocumentBuilder`),
`PriceBuckets` (la primera de las seis confirmaciones y lo que se «siembra» al activar). Si no
son de este capítulo, el dibujo debería decirlo con un nodo gris «se cuenta en el 09».

**5.4 `piezas`: no hay leyenda de colores, y el rescate va del color equivocado.** Hay cuatro
`classDef` (propio / tercero / persona / dato) y ningún cuadro que diga qué significa cada uno.
Y `ops/rescate-buscador.sh` se pinta como `propio` —igual que una clase del plugin— cuando es un
guion del repositorio, que es justo la distinción que el propio texto subraya («no es parte del
plugin, pero es parte del sistema»).

**5.5 `piezas`: dos flechas apuntan al revés para lo que se lee.**
`BUILDER -- "akb_search_effective_price" --> BUSINESS` y `STOCKL -- "akb_stock_status_label" -->
BUSINESS` sugieren que search **le manda** el precio a business, cuando es al contrario: search
pregunta y business responde. **Debería** etiquetarlas «pregunta el precio a» / «responde» o
usar flecha doble.

**5.6 `estados-rebuild`: se entiende solo, y le falta la transición que más duele.** Tiene todas
las fases que el texto nombra (incluida `SWAPPED`) y marca bien dónde se cierra el gate. Pero el
texto dice *«Muchos fallos de publicación sí terminan en `block_locked()` y por tanto sí
alarman»* y en el diagrama no hay ninguna flecha de `Publicando`/`Limpiando` a `Trabado`: sólo el
bucle mudo `Publicando --> Publicando`. **Debería** añadir `Publicando --> Trabado:
block_locked()`.

**5.7 `estados-rebuild`: tiene una arista que el texto no cuenta.** `Alcanzando --> Construyendo:
apareció deuda nueva — se vuelve a drenar`. El texto describe `CATCHING_UP` como un drenaje que
termina, sin decir que puede volver atrás (que es, además, lo que explica por qué un rebuild
tarda más de lo previsto cuando la tienda está viva). Debería estar en la prosa.

**5.8 `cron`: «Cada minuto» contradice al propio capítulo.** La primera línea del diagrama dice
*«Cada minuto : action_scheduler_run_queue — el motor de la cola de WooCommerce»*, y dos líneas
más abajo *«Cada 5 minutos : la tarea de Hostinger … el único empuje, porque `DISABLE_WP_CRON`
está encendido»*. Con `DISABLE_WP_CRON` encendido, ese «cada minuto» **no ocurre**: la cola sólo
avanza cuando alguien llama a `wp-cron.php`. El texto del capítulo lo dice bien («ésa es la
latencia mínima real»); el diagrama, no. **Debería** decir: «Cada minuto (en teoría): …
— en producción NO, porque `DISABLE_WP_CRON` está encendido: lo real es cada 5 min».

**5.9 `cron`: la hora heredada no se puede verificar.** *«12:50 aprox, HORA HEREDADA»* sin fecha
de la medición ni forma de comprobarlo hoy. **Debería** añadir cómo mirarlo (el grupo
`akibara-search` en WooCommerce → Estado → Acciones programadas) y la fecha en que se midió.

**5.10 `caso-stock-incremental`: le falta la bifurcación más probable.** Dibuja
`capture_price_authority()` como un paso que siempre sale bien, y sus dos ramas alternativas son
«producto no publicado» y «la tabla y el objeto se contradicen». Falta la tercera, que es la que
de verdad se ve: si el gate editorial está cerrado, `capture_price_authority()` no devuelve foto,
el claim **lanza** y se reintenta (`src/Indexer.php:553-563`). Sin esa rama, el diagrama promete
«segundos» donde el sistema da reintentos.

**5.11 `caso-descuento-editorial`: cita distinta a la del capítulo.** `EDP.php:2540` en el
diagrama, `:2545` en la prosa (ver 4.2).

**5.12 Los dos casos que dibujan el gate dan respuestas distintas sin decirlo.**
`caso-guardar-termino` rotula *«AQUÍ se cierra el gate: SWAP_INTENT»* y `caso-descuento-editorial`
*«EL BUSCADOR YA ESTÁ EN 503, antes de que la meta se escriba»*. Las dos son correctas y esa es
la gracia del capítulo — pero ningún diagrama dice «mira también el otro». **Debería** llevar una
nota cruzada en los dos: es la confusión que el propio texto llama «el error más caro de leer
este sistema».

**5.13 Faltan diagramas para los dos procedimientos más peligrosos.** No hay dibujo del caso 7
(despliegue con cambio de esquema: precondiciones, `backfill_sku_norm()`, el sello al final, los
300 s de gracia) ni del caso 10 (el rescate paso a paso, con el «PARA si hay `_old`»). Son los
dos sitios donde una persona sola puede romper la tienda, y son los dos que van sin imagen,
mientras que hay diagrama para el caso de stock, que no tiene riesgo.

**5.14 Ningún diagrama muestra la pantalla.** Los siete dibujan flujo entre clases; ninguno
muestra el panel («Buscador → Índice» con sus cajas), que es lo único que el dueño ve. Con 2.1 y
2.2 sin resolver, un esquema simple de la solapa `indice` valdría más que cualquiera de los
casos.

---

## Respuesta del redactor

*(2026-09-12. Revisión aplicada contra el mismo snapshot congelado de `akibara-search` 0.1.60.)*

**Se aplicaron los 51 puntos de las cinco secciones.** No hubo ninguno que el código refutara: cada afirmación de la sección 3 y de la 4 se comprobó abriendo el fichero, y todas resultaron ciertas. Resumen de lo que cambió y dónde:

- **Sección 1 (lo que no se entendió).** El glosario abre ahora con las tres palabras de las que depende todo —«503», «generación» y «run»— y suma «fan-out», «idempotente», «live-lock», «proveedor de precio», «Mailpit» y un bloque nuevo de siete términos de base de datos (DDL, `dbDelta`, `information_schema`, InnoDB, transacción/COMMIT, autoload, caché de objetos y *drop-in* persistente). La entrada «arriendo (lease)» avisa explícitamente de que el código usa la misma palabra inglesa para el permiso de servido, que es otra cosa; el texto del gate de caché ya no dice «la lease resuelve». Las tres funciones de «¿estamos listos?» (`runtime_schema_ready()`, `pending_rebuild_is_ready()`, `runtime_ready()`) se presentan una a una en la ficha de `Cli`, con la explicación del live-lock del 2026-09-05. Los campos de las options llevan una tabla propia con una línea cada uno, incluidos `not_before`, `debt_staged`, `purge_pre_seq`, `purge_post_seq`, `barriers`, `protocol`, `expected` y el aviso de `ack_scope: local_dispatch_only`. Las taxonomías indexadas se enumeran (son seis, más `product_visibility`). El «re-probado» lleva remisión a la sección donde se explica. El prólogo explica qué eran el mapa y el verificador, y dice que no hay que tenerlos.
- **Sección 2 (lo que faltaba).** Hay una sección nueva al principio, «Antes de empezar», con la dirección exacta del panel, las siete pestañas, las cinco cajas de «Índice», la tabla de órdenes de terminal para mirar el estado sin panel, cómo se decide qué es producción y qué es el `admin_email`. `SearchAdminSection` tiene ficha con el mismo molde que las demás, y diagrama propio. Se añadieron: los tres filtros de configuración que nadie documentaba, con quién los usa hoy (nadie); las siete piezas vecinas del capítulo 09 —`Normalizer`, `QueryLogger`, `PriceBuckets`, `FacetRegistry`, `SeriesSuggestions`, `FacetService`, `Support\RateLimit`—; el dueño de cada uno de los cuatro prefijos de options; el modo `espejo` de `ops/rescate-buscador.sh`; cómo comprobar y forzar lo programado; la tabla de síntoma → dónde mirar al principio de «Casos de uso»; un caso 12 de primer arranque; y el efecto del gate cerrado sobre el reindexado incremental, que era el agujero más serio de los señalados.
- **Sección 3 (contradicciones).** Corregidas las diez. En particular: «nadie escucha los cambios del catálogo» pasa a enumerar lo que **sí** sigue enganchado con la indexación apagada; las citas de `Search.php` quedan en `:172-174`, `:176-177`, `:179-181`, `:183` y `:186`; el intervalo del vigilante se cita en `:78` y se distingue del memo de `:82`; el comentario equivocado se cita en `:75`; los dos mensajes de «Ya hay un rebuild en curso» van en una tabla con su texto literal y su código de salida; la ficha de `Install` dice que no engancha nada y quién lo hace; la de `RestCache` dice que sus dos enganches viven detrás del sello de esquema; y la frase de los 158 s se reescribió entera: ese reloj es la ventana de 503 **sólo** cuando el rebuild nace de un cambio editorial.
- **Sección 4 (contra el verificador).** El anillo de recibos lleva nota de que ahí mandó el código sobre el borrador de verificación. La comparación del gate usa el mismo par `:2508` / `:2545` en la prosa y en el diagrama. Los rangos del `Indexer` se explicitan (método, primer oyente, último oyente). Los quince campos de `ProductSourceAuthority` van enumerados con su cita. Y la sospecha refutada —«un buscador caído purga Cloudflare entero cada 15 minutos»— aparece en el caso 5 bajo «Lo que NO pasa».
- **Sección 5 (diagramas).** Los siete se corrigieron y hay **tres nuevos**: el panel, el despliegue con cambio de esquema y el rescate paso a paso. Los diez compilan con `mmdc` (código de salida 0). El de piezas lleva leyenda de colores, un color propio para el guion del repositorio, `QueryLogger` como escritor del qlog, las piezas vecinas en gris, las flechas de precio y de sello en las dos direcciones con su etiqueta, y la cuenta corregida a 23. El de estados añade `Publicando → Trabado` y `Limpiando → Trabado`. El de cron dice que el «cada minuto» **no ocurre** aquí y cómo comprobar hoy la hora heredada. El de stock añade la rama del gate cerrado. Los dos que dibujan el gate se remiten el uno al otro.

**Tres erratas de la propia crítica**, anotadas para que nadie las herede al cotejar (el fondo de los tres puntos era correcto y se aplicó con la línea buena):

- **3.10** sitúa `Arrival::seed_pending()` en `src/Arrival.php:48`; está en **`:98`** (la llamada del `Reconciler`, en `:403`, sí es exacta).
- **2.13** cita `src/Indexer.php:553-563`; el bloque es **`:554-563`** (`:553` es el `try`).
- **3.4** dice que el bloque de comentario va de `:62` a `:77`; empieza en **`:63`** (`:62` está en blanco). La línea del comando equivocado, `:75`, es exacta y se cita así.

Y una precisión sobre **2.9**: los tres filtros existen y están documentados ahora, pero no son «palancas invisibles» en el sentido de la regla de la casa —no hay ninguna option que el dueño pueda estar tocando sin saberlo—. Son puntos de extensión para otro plugin, hoy sin nadie enganchado. La tabla de reglas lo dice con esas palabras, y la fila de las cuatro constantes se reformuló para no prometer más de lo que hay.
