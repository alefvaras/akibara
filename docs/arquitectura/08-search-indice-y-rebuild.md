# akibara-search · el índice: cómo se construye, se reconstruye y se cuida

> Levantado contra el **snapshot congelado de producción** de `akibara-search` 0.1.60. Cada afirmación técnica apunta a un fichero y una línea de ese snapshot.
>
> Ruta del snapshot: `scratchpad/snapshot-prod/plugins/akibara-search/`. Las rutas que aparecen en el texto son relativas a esa carpeta, salvo `ops/rescate-buscador.sh`, que vive en el repositorio.
>
> **Cómo se resolvieron las dudas.** Este capítulo se escribió a partir de dos borradores internos que no se publican —un «mapa de lectura», que es el inventario que un primer pase hace del código, y un «verificador», que es un segundo pase que comprueba el primero contra los ficheros—. No hace falta tenerlos para leer esto: son andamios, no documentación. Cuando los dos discreparon, ganó el que había vuelto al código; y donde **ninguno** acertaba, mandó el código, que es la única fuente. Los dos sitios donde eso pasó quedan dichos en su lugar (el anillo de recibos y la comparación del gate).

---

## Para qué existe (en dos párrafos que entienda un novato)

Akibara vende manga en akibara.cl. La tienda está hecha con **WordPress** (el programa que arma las páginas) y **WooCommerce** (el añadido que le da carrito, productos y pedidos). Un **plugin** es un paquete de código que se enchufa a WordPress para añadirle cosas. `akibara-search` es un plugin escrito en casa que reemplazó a dos programas de terceros: el buscador (Relevanssi) y los filtros del catálogo (FacetWP). Este capítulo cubre **la mitad de abajo** del plugin: la que construye y mantiene el índice. La mitad de arriba —el desplegable de sugerencias, la caja de búsqueda, los filtros que se pintan en pantalla— consume lo que aquí se construye.

Un **índice** es una copia adelantada del catálogo, ya masticada, para que buscar sea instantáneo. Sin él, escribir «naruto 12» obligaría a WordPress a recorrer producto por producto en cada tecleo; con él, la respuesta sale de tres tablas propias del plugin en unos diez milisegundos en vez de unos trescientos. El problema del índice es que es una **copia**: en cuanto el catálogo cambia y la copia no, la tienda empieza a mentir —muestra un precio en el listado y cobra otro en el carrito, ofrece un filtro de una categoría que ya no existe, promete un tomo que se agotó—. Todo lo que este capítulo describe existe para una sola cosa: que la copia no mienta, y que cuando no se pueda **probar** que no miente, el buscador se apague en vez de inventar.

---

## Antes de empezar: la pantalla, la terminal y cómo se lee este capítulo

Nada de lo que sigue se puede comprobar sin saber dónde mirar. Van primero las tres direcciones.

### La pantalla

El buscador tiene su propio panel dentro del escritorio de WordPress (la zona de administración, la que el cliente nunca ve). La dirección exacta es:

```
https://akibara.cl/wp-admin/admin.php?page=akibara-search&sub=indice
```

En el menú de la izquierda se llama **«Buscador»**, y cuelga de **«Akibara»** —el panel de akibara-business— si ese panel existe. Si no existe, el buscador aparece como menú propio de primer nivel con un icono de lupa, para que nunca quede inalcanzable (`src/SearchAdminSection.php:110-144`). El slug de la página es siempre `akibara-search`, cuelgue de donde cuelgue.

El parámetro `sub` elige la pestaña, y sólo acepta **siete** valores; cualquier otra cosa cae en `indice` (`src/SearchAdminSection.php:156`):

| `sub=` | Cómo se llama en pantalla | Para qué |
|---|---|---|
| `indice` (por defecto) | **Índice** | salud, servicios, estado, mantenimiento y diagnóstico. Es la pantalla de este capítulo |
| `busquedas` | Búsquedas | qué buscó la gente y qué no encontró |
| `facetas` | Facetas | qué filtros existen y cómo se llaman |
| `precios` | Precios | los tramos del filtro de precio |
| `sinonimos` | Sinónimos | palabras que deben encontrar lo mismo |
| `stopwords` | Palabras ignoradas | palabras que no aportan al buscar |
| `ajustes` | Ajustes | los tres interruptores del buscador |

Todas exigen el permiso `manage_woocommerce`: o administrador, o gestor de la tienda.

**La pestaña «Índice» son cinco cajas, siempre en este orden** (`SearchAdminSection::render_index_status()`, `src/SearchAdminSection.php:551-562`). El capítulo manda al operador a estas cajas por su nombre, así que conviene tenerlas ubicadas:

![La pestaña Índice del panel del buscador](diagramas/08-search-indice-y-rebuild-panel-indice.mmd)

1. **Banda de salud** — el veredicto en una frase: *sano*, *atención* o *reconstruyendo*, con un enlace a Mantenimiento cuando hay algo que hacer.
2. **Servicios** — los tres interruptores (indexación, autocompletado, filtros) y el estado efectivo del gate editorial **con su causa**.
3. **Estado** — la tabla de números: documentos, tokens, filas de filtros, deuda pendiente, versión de esquema, última reconstrucción, última verificación y la solicitud de rebuild pendiente si la hay.
4. **Mantenimiento** — los botones «Reconstruir índice» / «Reintentar reconstrucción», o el cartel rojo de bloqueo con el puntero a `ops/rescate-buscador.sh`.
5. **Diagnóstico técnico** — un desplegable con el volcado crudo, los comandos de terminal y el «Registro de cambios» (las últimas 15 entradas del cuaderno).

### La terminal

El panel es lo cómodo, pero en la mitad de los incidentes de este capítulo el panel es justo lo que no carga. Todo el estado se puede leer por terminal, sin panel y sin navegador. En producción por `ssh akibara`; en el espejo local, anteponiendo `docker compose run --rm --no-deps -T cli` a cada `wp`.

| Qué quiero saber | Orden |
|---|---|
| ¿Cuántas filas tiene cada tabla, y cómo fue la última reconstrucción? | `wp akb-search status` |
| ¿En qué fase va un rebuild? ¿Avanza `done`? ¿Hay tareas trabadas? | `wp option get akb_search_rebuild_state --format=json` |
| ¿Hay una reconstrucción pedida y sin consumir? | `wp option get akb_search_pending_rebuild --format=json` |
| ¿Está sonando la alarma de «buscador caído»? | `wp option get akb_search_serving_alarm --format=json` |
| ¿El esquema está sellado? | `wp option get akb_search_db_version` y `wp option get akb_search_schema_seal` |
| ¿Cuánta deuda hay y de qué tipo? | `wp db query "SELECT status, COUNT(*) FROM wp_akb_search_work GROUP BY status;"` |
| ¿Este sitio se considera producción (o sea, manda correos de verdad)? | `wp eval 'echo wp_get_environment_type();'` |
| ¿A qué dirección van los correos de alarma? | `wp option get admin_email` |

Dos avisos sobre esa tabla:

- `wp akb-search status` imprime **cuatro conteos de filas y dos resúmenes en JSON**, y nada más (`src/Cli.php:140-162`). En particular **no** imprime `done` ni `work_blocked`, que es lo que el correo de alarma pide mirar: esos dos viven en `akb_search_rebuild_state` y los pinta el panel (véase «El correo promete algo que su propio comando no da», más abajo).
- El prefijo `wp_` de la consulta SQL es el de este sitio. En un sitio con otro prefijo hay que cambiarlo, y nada avisa.

### El correo, y qué cuenta como producción

Cuando el buscador lleva 900 segundos caído sale un correo. Va al **`admin_email`**, que es la dirección del sitio: la que WordPress guarda en Ajustes → Generales, la misma que usa para «has olvidado tu contraseña». Se ve con `wp option get admin_email`.

Y sólo sale **en producción**. «Producción» aquí no es una idea: es lo que devuelve la función `wp_get_environment_type()` de WordPress (`src/RebuildJob.php:828`), que sale de una constante o variable de entorno del sitio, no del plugin. El espejo local está marcado como otra cosa a propósito, y por eso ahí no llegan correos de verdad: llegan a **Mailpit**, un buzón de mentira que el entorno de Docker levanta para que las pruebas no molesten a nadie. Si alguien marcara el espejo como `production`, mandaría correos reales.

### Cómo se lee este capítulo

Hay **un glosario al final** con todas las palabras que este texto usa como si ya se supieran: «503», «generación», «run», «idempotente», «fan-out», «fase», «recibo», «gate». Si una palabra en negrita no significa nada, está ahí abajo. Y en «Casos de uso» hay, antes de los casos, una tabla de **síntoma → dónde mirar**: es la entrada correcta cuando algo ya se rompió.

---

## Cómo encaja con el resto

![Piezas del área del índice](diagramas/08-search-indice-y-rebuild-piezas.mmd)

**Con quién habla, hacia dentro de la casa:**

| Con quién | Por dónde | Para qué |
|---|---|---|
| **akibara-business** | filtro `akb_search_effective_price` | El índice guarda el precio **ya calculado**. Quien sabe cuánto descuento editorial le toca a un producto es business, no search. `DocumentBuilder` pregunta por este filtro y guarda la respuesta. |
| **akibara-business** | filtros `akb_stock_status_label` y `akb_search_availability_value` | La palabra que ve el cliente —Disponible, A pedido, Preventa, Agotado— sale de `StockLabels`, y business puede afinarla. Sin business, quedan los valores locales. |
| **akibara-business** | acción `akibara_taxonomy_discount_pct_saved` | Cuando business guarda un porcentaje de descuento de un término, avisa a `Indexer::on_discount_pct_saved()` para que reindexe los productos afectados. |
| **akibara-business** | función puente `akibara_search_demand_gaps()` | El ranking «Lo más solicitado» de business se alimenta de las búsquedas que no dieron ningún resultado, que las guarda el buscador. |
| **tema akibara-gp** | **dos** de las **ocho** funciones puente, con `function_exists` | Las ocho son `akibara_search_facets_active` (`akibara-search.php:118`), `_facets_fail_open` (`:131`), `_facets_view` (`:141`), `_facets_context` (`:152`), `_suggest_active` (`:163`), `_correction` (`:175`), `_relaxed_notice` (`:197`) y `_demand_gaps` (`:227`). **El tema sólo llama a dos**: `_facets_active` y `_suggest_active`. Las cinco de facetas y avisos las consume `frontend/facets-render.php`, del propio plugin, y `_demand_gaps` la consume business (corregido 2026-09-20, ronda 10: decía «siete» y omitía `_facets_fail_open`). |

**Con quién habla, hacia fuera:**

- **WooCommerce**: es la fuente de los productos, del stock y de las ofertas programadas, y además es quien trae **Action Scheduler**, la cola de trabajos en segundo plano que el índice usa como despertador.
- **LiteSpeed y Cloudflare**: el plugin dispara `litespeed_purge` (purga por etiqueta, se queda en el origen) y `litespeed_purge_all` (**la única que llega a Cloudflare**), y además impone techo de caché a las páginas.
- **El correo del dueño**: `wp_mail()` al `admin_email`, sólo en producción, con dos asuntos posibles que se detallan más abajo.

**Qué pasa si se apaga.** Hay tres interruptores distintos, y apagan cosas distintas:

- **«Indexación automática» apagada** (`akb_search_indexing`): `Search::register()` sale antes de llegar a `register_listeners()` (el `return` está en `src/Search.php:179-181`; los oyentes, en `:183`). Lo que se apaga es exactamente eso: **los 25 oyentes del `Indexer`** y el agendado de la revisión diaria (`:186`). Lo demás sigue enganchado **a propósito**, y el código lo dice con esas palabras —«SIEMPRE e independiente de los flags»—, porque se registra *antes* de esa compuerta:
  - los tres manejadores de la cola (`register_handlers()`, `src/Indexer.php:145-149`): un trabajo ya encolado debe poder terminar aunque el interruptor se apague a mitad de camino;
  - `Arrival::register()` (`src/Search.php:143`), que sigue anotando `_akb_onbackorder_at` y `_akb_first_instock_at` en cada cambio de stock, porque esa historia no se puede reconstruir hacia atrás;
  - `Novedad::register()` (`:120`) y la invalidación del catálogo de series (`:126`);
  - los **dos protocolos de publicación**: `SemanticPublication::register()` (`:132`) y el editorial en `plugins_loaded:30` (`:137`), que siguen interceptando —y **vetando**— escrituras de options;
  - el panel y su ruta de estado (`:147`, `:153`), que existen justamente para operar con los interruptores apagados.

  O sea: con la indexación apagada **el catálogo sí tiene oyentes**; lo que no tiene es quien convierta un cambio en deuda de reindexado.
- **«Autocompletado» o «Filtros» apagados**: el cliente deja de ver el desplegable o los filtros, pero el índice se sigue manteniendo al día.
- **El plugin entero desactivado**: el tema degrada a mudo gracias a los `function_exists`; nunca fatalea. Las tablas y los datos **se conservan**: desactivar no borra nada. Sólo «Eliminar» ejecuta `uninstall.php`.

---

## Piezas

### `akibara-search.php` — el arranque

**Qué hace.** Es el fichero que WordPress lee para saber que el plugin existe: carga las clases sin Composer y enchufa el buscador cuando WooCommerce ya está listo.

- **Ficheros:** `akibara-search.php`.
- **Cuándo actúa:** `plugins_loaded` prioridad **15** → `akibara_search_bootstrap()` (`:80`), que no hace nada si la clase `WooCommerce` no existe. Y `register_activation_hook` → `akibara_search_activate()` (`:222`), que crea o migra las tablas y siembra los buckets de precio la primera vez que se activa.
- **Qué lee y escribe:** nada directamente.
- **Dónde lo opera el dueño:** en ningún sitio.
- **Qué ve el cliente:** nada directo; de aquí depende que el tema tenga con qué pintar filtros y desplegable.

### `Install` — el dueño del esquema

**Qué hace.** Crea y migra las cinco tablas propias, decide si el sistema está en condiciones de escribir, y guarda las señales de «hay que reconstruir el índice».

- **Ficheros:** `src/Install.php`, `uninstall.php`.
- **Cuándo actúa:** sus métodos corren en `init` prioridad **20** → `consume_pending_rebuild()` (toma la primera solicitud pendiente y crea el **run** durable) y en `init` prioridad **22** → `consume_generation_publications()` (publica la **generación** pendiente del buzón y verifica su recibo de purga). **`Install` no engancha ninguna de las dos**: quien las cuelga del hook es `Search::register()` (`src/Search.php:116`) y `RebuildJob::register_handlers()` (`src/RebuildJob.php:139`). En `Install.php` no hay un solo `add_action`, y quien vaya a buscarlo ahí creerá que se equivocó de fichero. Además, `maybe_install()` se llama en **cada arranque** desde `Search::register()` (`src/Search.php:103`).
- **Qué lee y escribe:** las options `akb_search_db_version`, `akb_search_schema_seal`, `akb_search_pending_rebuild`, `akb_search_generation_outbox` y `akb_search_index_gen`. Y el DDL de las tablas.
- **Dónde lo opera el dueño:** Buscador → Índice muestra la topología, la versión de esquema y la solicitud pendiente; no las edita.
- **Qué ve el cliente:** nada.

No se fía de `dbDelta` —la función de WordPress que crea y modifica tablas, que hace lo que puede y devuelve éxito aunque haya quedado a medias—. En vez de creerle, vuelve a preguntarle a la base de datos **cómo quedaron las tablas de verdad**: se lo pregunta a `information_schema`, que es el catálogo que MariaDB lleva de sus propias tablas, la lista oficial de qué columnas y qué índices existen. Y exige que coincida hasta el detalle: motor **InnoDB** (el motor de almacenamiento que sabe hacer transacciones, o sea «o entra todo o no entra nada»), columnas exactas en orden exacto, índices exactos y collation `utf8mb4_bin` en `tokens`. Sólo entonces sella la versión.

El sello son dos options escritas **al final**, bajo un candado con nombre propio y releyéndolas para comprobar que quedaron; si el proceso muere antes, la versión conserva el valor anterior y la petición siguiente reintenta, porque la migración es **idempotente** (repetirla no hace daño: correrla una vez o cinco deja el mismo resultado).

Dos pasos de la migración que conviene conocer porque tocan datos y no sólo estructura:

- **`backfill_sku_norm()`** (`src/Install.php:1633-1642`) ejecuta un `UPDATE` idempotente sobre las filas vivas de `docs` para rellenar `sku_norm`. Es el **único punto donde una migración escribe datos** en las tablas vivas, y si falla **aborta la instalación**: `install()` devuelve `false` en `:1583-1585` y la versión no se sella.
- **`purge_legacy_rotating_transients()`** (`src/Install.php:1612-1625`) borra los transients rotatorios `akb_vocab_`, `akb_lc_` y `akb_rlm_` que versiones viejas dejaban acumulados. Es best-effort: su fallo no invalida la migración.

Y un detalle que sorprende a quien lea sólo el titular: **`maybe_install()` pide un rebuild completo también cuando la versión NO cambió**. La condición literal es `should_flag_rebuild($previous) || self::DB_VERSION === $previous` (`src/Install.php:683`). O sea que una deriva del sello —`akb_search_schema_seal` perdido con `akb_search_db_version` al día— reconstruye el índice entero con 300 segundos de gracia. Es un rebuild que nadie pidió, y la razón está en el comentario de arriba: la señal se persiste **antes** del sello para que un crash posterior no deje la versión final sin el rebuild que un bump o una deriva exigen.

**El desinstalador** merece nota aparte. Borra 13 tablas (las tres vivas, sus `_new`, sus `_old`, `qlog` con dos residuos históricos de swap que nunca debió tener, y `work`), cancela **todo el grupo** `akibara-search` de Action Scheduler pasando hook vacío, y filtra las options por **cuatro prefijos exactos** y jamás por `akb_%` a secas (`uninstall.php:81`). Cada prefijo tiene dueño, y el propio fichero lo dice (`uninstall.php:75-79`):

| Prefijo | De quién es | Qué se pierde si se borra |
|---|---|---|
| `akb_search_` | el índice y su panel | todo lo que este capítulo describe: estado, recibos, configuración, cuaderno |
| `akb_vocab_` | `QueryEngine` | el vocabulario del corrector de tipeos; se vuelve a construir solo, más lento |
| `akb_lc_` | `FacetService` | los conteos guardados de los filtros; se recalculan |
| `akb_rlm_` | `Support\RateLimit` | los contadores del tope de peticiones por IP; empieza de cero |

Sin esa lista, desinstalar dejaba atrás las tres últimas familias, y con caché de objetos persistente encendida el recolector de basura de WordPress no las barre nunca: quedaban para siempre. Ese prefijo corto está compartido con akibara-business (lista de espera, favoritos, Arma tu Pack): un borrado por ahí se llevaría la configuración del plugin vecino en silencio, y el síntoma aparecería mucho después como «la tienda se comporta raro».

### `WriteFence` — el cerrojo

**Qué hace.** Impide que dos procesos escriban el índice a la vez.

- **Ficheros:** `src/WriteFence.php`.
- **Cuándo actúa:** no cuelga de ningún hook; lo toman `Indexer`, `RebuildJob`, `Install` y `EditorialDiscountPublication` antes de cada operación peligrosa.
- **Qué lee y escribe:** un candado nombrado de MariaDB, `GET_LOCK('akb-search:<sha256 de la base y el prefijo>')`, acotado a 64 bytes.
- **Dónde lo opera el dueño / qué ve el cliente:** nada.

Tres cosas lo separan de un candado ingenuo: verifica que el candado lo tiene **esta conexión física** (`CONNECTION_ID()` + `IS_USED_LOCK()`), no sólo que `GET_LOCK` devolvió 1; `assert_owned()` **lanza excepción** si la conexión se reconectó, y los llamadores lo pegan a cada `RENAME` y a cada `DROP`; y `release()` nunca suelta el candado de otro, conservando la propiedad local ante cualquier duda para poder reintentar. La razón es concreta: una reconexión silenciosa de la base es lo que convierte un `DROP` en desastre.

### `WorkRepository` — la libreta de tareas

**Qué hace.** Anota qué producto hay que reescribir, se lo presta a un obrero por un rato, y no da la tarea por hecha hasta que el obrero confirma.

- **Ficheros:** `src/WorkRepository.php`.
- **Cuándo actúa:** sin hooks propios; lo usan `Indexer`, `RebuildJob` y `Reconciler`.
- **Qué lee y escribe:** la tabla `wp_akb_search_work`, con la clave funcional `(scope, run_id, product_id)`. Tres ámbitos: `live` (cambios normales), `baseline` (el manifiesto de un rebuild) y `repair` (lo que cambió mientras el rebuild corría). Las filas `baseline`/`repair` de una corrida las poda `purge_run()` al llegar a DONE y, desde 0.1.71, `purge_other_runs()` al ARRANCAR la siguiente corrida barre todo lo que no sea suyo, en cualquier estado: una corrida que no llegó a DONE dejaba sus filas para siempre (755 filas de dos corridas del 2026-09-05 medidas en producción, `R7-trabajo-huerfano`). `live` no se toca: es por producto, sin corrida.
- **Dónde lo opera el dueño:** los contadores «pendientes / arrendados / bloqueados» de Buscador → Índice.
- **Qué ve el cliente:** nada.

Lo importante es el par `wanted_seq` / `acked_seq`. Cuando llega un cambio se sube `wanted_seq` en uno; cuando un obrero confirma, el ACK sólo cierra la fila **si `wanted_seq` no avanzó mientras trabajaba**, y si avanzó la fila vuelve a `pending`. Así una revisión nueva no queda enterrada bajo el ACK de la anterior. El **arriendo** (`lease_token`, `lease_seq`, `lease_until`) evita que un obrero muerto bloquee la fila para siempre: `recover_expired()` la devuelve a la cola. Y `retry_with_backoff()` reintenta con espera creciente —5 s, 10 s, 20 s… tope una hora— hasta el **octavo intento**, donde la marca `blocked` y observable (`src/WorkRepository.php:41`, `:651`). Sin ese techo, un producto irreparable giraba para siempre con el backoff clampado a una hora: el run nunca cerraba, nadie lo veía, y el estado `blocked` —con su contador en el panel y su veredicto en `HealthReport`— no tenía escritor. La observabilidad existía y era mentira.

Ningún conteo convierte un error SQL en cero: devuelven `false`, y el llamador lo trata como «no sé», nunca como «no hay nada».

**Deuda con espera.** Todos los caminos usan `mark_live_dirty_many( $ids, $delay )`. El envoltorio `mark_live_dirty_deferred()`, que no llamaba nadie en producción, se retiró en 0.1.64.

### `Indexer` — el oído y la mano

**Qué hace.** Escucha todo lo que cambia en la tienda y lo convierte en deuda durable; y es también quien escribe las filas del índice.

- **Ficheros:** `src/Indexer.php`.
- **Cuándo actúa:** dos registros distintos.
  - `register_handlers()` (el método, con sus llaves, ocupa `:145-149`; los tres `add_action` están en `:146-148`) registra **siempre**, pase lo que pase con el interruptor, los tres manejadores de cola: `akb_search_reindex_product`, `akb_search_pump_live` y `akb_search_reindex_term`. La razón está escrita: los trabajos ya encolados deben poder correr aunque el interruptor se apague a mitad de camino.
  - `register_listeners()` (el método se declara en `:156`; el primer oyente está en `:157` y el último en `:183`) registra **25 oyentes**, y sólo si «Indexación automática» está encendida.
- **Qué lee y escribe:** escribe deuda live y repair en `wp_akb_search_work`; escribe `docs`, `tokens` y `facet_index` (y sus `_new`) con DELETE + INSERT dentro de una transacción.
- **Dónde lo opera el dueño:** Buscador → Ajustes → «Indexación automática» enciende y apaga **todos** estos oyentes de golpe.
- **Qué ve el cliente:** indirectamente, que el precio y el sello de stock del desplegable estén al día.

Los 25 oyentes, con su prioridad, tal como aparecen en `register_listeners()`:

| Hook | Prioridad | Qué hace |
|---|---|---|
| `save_post_product` | 20 | Barrera de 10 s + deuda live. Ignora autoguardados y revisiones. |
| `woocommerce_new_product`, `woocommerce_update_product` | 10 | Deuda inmediata cuando WooCommerce ya guardó todo. |
| `woocommerce_new_product_variation`, `woocommerce_update_product_variation` | 10 | Reindexa **al padre**: las variaciones no tienen documento propio. |
| `woocommerce_before_product_object_save` | 10 | Barrera pre-escritura; para variaciones apunta padre viejo y nuevo. |
| `woocommerce_product_set_stock_status` | 12 | Reindexa el producto cuyo stock cambió. |
| `woocommerce_variation_set_stock_status` | 12 | Reindexa al padre de la variación. |
| `add_term_relationship`, `delete_term_relationships` | 10 | Barrera de 10 s antes de que cambie la relación producto↔término. |
| `set_object_terms` | 10 | Deuda inmediata del producto cuyos términos cambiaron. |
| `edit_terms` | 10 | Pide rebuild completo, **pero sólo si** el guardado toca nombre, slug o padre. |
| `edited_term` | 10 | Fan-out del término… y pide rebuild completo **sin condición**. |
| `pre_delete_term` | 10 | Pide rebuild y captura los hijos directos **antes** de que WordPress los recoloque. |
| `delete_term` | 10 | Apunta los productos del término borrado y de todo su subárbol. |
| `before_delete_post`, `wp_trash_post`, `untrash_post` | 10 | Barrera pre-escritura del cambio de estado del producto. |
| `deleted_post`, `trashed_post` | 10 | Deuda del producto que desapareció. |
| `untrashed_post` | 10 | Deuda del producto que volvió. |
| `wc_after_products_starting_sales`, `wc_after_products_ending_sales` | 10 | Las ofertas **programadas** cambian el precio sin pasar por `save_post`. |
| `akibara_taxonomy_discount_pct_saved` | 10 | Fan-out del término cuyo porcentaje cambió; lo dispara akibara-business. |
| `update_option_woocommerce_hide_out_of_stock_items` | 10 | Ese ajuste cambia el campo `visible` de **todo** el catálogo → rebuild completo. |

**Qué taxonomías cuentan.** Varios de esos oyentes sólo reaccionan si el término tocado pertenece a una **taxonomía indexada**, y la lista no está escrita a mano en el `Indexer`: sale de `FacetRegistry::indexed_taxonomies()` (`src/FacetRegistry.php:660`), que la deriva de las facetas definidas, más `product_visibility` añadida aparte (`src/Indexer.php:1926-1931`). Hoy son seis: `product_cat` (las categorías, de donde salen las facetas Tipo, Demografía y Sello), `product_brand` (la editorial), `pa_pais`, `pa_genero`, `pa_autor` y `pa_serie`. Un término de cualquier otra taxonomía no mueve nada. `FacetRegistry` en sí es pieza del capítulo 09, donde se cuenta cómo se pintan y se cuentan las facetas.

**La barrera pre-escritura.** Varios de esos hooks ocurren *antes* de que WooCommerce persista. Para ésos, el `Indexer` apunta la deuda con 10 segundos de espera (`available_at` en el futuro) y programa un despertador para después. Si el proceso muere entre el write y el hook posterior, sobrevive la primera anotación.

**La mano.** Cada tarea viva se escribe en **dos transacciones**, y desde 0.1.64 la publicación se hace **por lote**, no por producto:

- `stage_live_claim()` es la primera transacción: toma la foto de precio, construye el documento, valida el arriendo, escribe las filas del índice y la deuda de reparación, hace COMMIT y suelta el cerrojo. La tarea **sigue arrendada**: *«ésa es la deuda durable que permite reintentar si el proceso muere entre filas y publicación.»*
- `handle_live_pump()` junta hasta 25 tareas así y llama a `close_live_batch()`: **una** publicación de generación (con su recibo de purga, huella semántica y recibo editorial), después un ACK por tarea y **un** `reconcile_now()`. El lote se corta antes si se acaba su presupuesto (`LIVE_BATCH_SECONDS` = 10 s, medido con un solo reloj desde antes del primer arriendo y con margen para que ningún arriendo de 60 s venza antes de su ACK).
- **Atajo sin cambios.** Si lo que se escribiría es idéntico a lo que ya está en las tres tablas vivas (`live_rows_match()`, leído con `FOR UPDATE` dentro de la misma transacción), no se escribe ni se publica: sólo se refresca `indexed_at`, que el `Reconciler` necesita. El candado de durabilidad es `attempts`: el atajo sólo vale en el **primer** arriendo desde el último ACK, porque un reintento puede venir de un proceso que murió con filas escritas y sin publicar, y ése publica siempre. Un candado de pruebas ata las columnas que escribe `persist_rows()` a las que compara el atajo, para que una columna nueva no quede fuera en silencio.
- `wp akb-search reindex <id>` **fuerza** la publicación aunque las filas coincidan: es la palanca del operador cuando el índice está raro.

Por qué importa, medido en el espejo el 2026-09-14: hasta 0.1.63 cada producto publicaba su generación, y como un cambio editorial ensucia el catálogo entero (`stage_locked()` → `mark_live_dirty_many()`), el drenaje reescribía esas options unas 20 veces por segundo. El gate público lee su foto dos veces por petición y exige que cuadren: entre medias, 503. Con dos clientes bajo el límite, el 12 % de las búsquedas recibía 503 al primer intento durante 297 s de drenaje; con 0.1.64, **cero generaciones y cero búsquedas caídas** durante el drenaje. Riesgo que queda escrito en el código: si el proceso muere a mitad de lote, hasta 25 productos quedan con filas nuevas sin publicar (antes, uno); se recuperan solos al vencer el arriendo, porque el reintento llega con `attempts >= 2` y publica.

**Un detalle que vale una caída:** el INSERT masivo usa `INSERT IGNORE` y **compara filas insertadas contra enviadas**. Con `IGNORE`, MySQL degrada un choque de clave a aviso; antes de esa comparación, el producto quedaba indexado a medias y en silencio, invisible hasta la revisión diaria.

**Guardar un término.** Desde 0.1.63, `on_pre_edit_term()` decide **antes** del UPDATE si el guardado toca algo que el índice use —nombre, slug o padre (`term_edit_touches_index()`)— y, si no, marca la edición como inerte: `enqueue_term()` ya no reparte deuda ni pide reconstrucción para ese término. La reconstrucción entera (`request_term_rebuild()`, motivo `term_fanout`) queda sólo como red cuando el reparto no se pudo guardar completo (esquema no listo, término o productos ilegibles, escritura fallida). Medido en el espejo: guardar «Manga» (1.596 productos) sin tocar esos tres campos no deja pedido ni deuda viva y el suggest sigue en 200. Guardar un porcentaje editorial sigue entrando por `enqueue_term()` desde `on_discount_pct_saved()`, y `withdraw_option_rebuild_request()` sólo retira motivos `option_update_*`, `option_add_*` u `option_delete_*`, así que un `term_fanout` pedido como red no lo retira el atajo del re-probado.

**Lo que pasa con el gate editorial cerrado, y no está en ninguna otra parte.** El camino incremental —el barato, el de «llegó stock de un tomo»— arranca pidiendo la foto de precio: `stage_live_claim()` llama a `EditorialDiscountPublication::capture_price_authority()` y, si no devuelve una foto, **lanza excepción** y el trabajo se va a `retry_claim()`. O sea: mientras el gate editorial esté cerrado —que es justo cuando el buscador está en 503 por deuda de precio—, **cada intento de reindexar un producto quema un reintento**, con su espera creciente de 5 s, 10 s, 20 s… y al **octavo** la fila queda `blocked` (`src/WorkRepository.php:41`, `:651`).

Eso explica un síntoma que el caso 4 promete resuelto «en segundos»: si el gate está cerrado, cambiar el stock de un tomo **no** se refleja en segundos, y si el episodio dura lo suficiente el producto termina trabado y hay que desbloquearlo. Es la misma regla que en la revisión diaria —no se reindexa con un precio que no es autoritativo—, pero aquí el coste no es abortar una corrida: es gastar los ocho intentos de cada producto que cambie durante el episodio.

**Un solo camino síncrono.** `index_product_outcome( $product_id, $forzar_publicacion )` es el único; lo usa la CLI, que fuerza la publicación. `Indexer::index_product()`, que no llamaba nadie en producción, se retiró en 0.1.64.

### `DocumentBuilder` — de producto a filas

**Qué hace.** Convierte un producto de WooCommerce en las filas que van al índice: el documento, sus palabras y sus filtros. Todo lo caro se calcula aquí, una vez, para que buscar sea barato.

- **Ficheros:** `src/DocumentBuilder.php`.
- **Cuándo actúa:** sin hooks propios; lo llaman `Indexer`, `RebuildJob` y `Reconciler`. **Dispara** tres filtros: `akb_search_effective_price`, `akb_search_availability_value` y `akb_stock_status_label`.
- **Qué lee y escribe:** lee el producto a través de `ProductSourceAuthority`; devuelve las filas de `docs`, `tokens` y `facet_index`.
- **Dónde lo opera el dueño:** en ningún sitio directamente.
- **Qué ve el cliente:** **todo** lo del desplegable sale de aquí: título, precio pintado, miniatura y sello de stock.

Cada dato se lee por una frontera que hace visible el fallo (`read_source`): limpia `last_error`, ejecuta, y si la base dejó un error o la función devolvió un `WP_Error`, **lanza**. Un producto que no se puede leer entero conserva su deuda y se reintenta; nunca se indexa a medias.

Materializa el título normalizado, la serie, los autores, el SKU y su variante sin guiones, el tomo, el rango de stock, el precio efectivo (vía `akb_search_effective_price`), la URL, la miniatura de 150×150 y el **precio ya pintado en HTML**, saneado con `wp_kses_post` **antes** de recortar, porque termina en el `innerHTML` del desplegable y `get_price_html()` es filtrable por terceros.

Tokeniza título —más un token pegado sin espacios, para que «Dan Da Dan 20» se encuentre escribiendo `dandadan`—, serie, autores, SKU (exacto y sólo dígitos, para que un ISBN con guiones se encuentre sin ellos), taxonomías y hasta 80 palabras de la descripción. Y **materializa los ancestros**: un producto en «Shonen» genera también fila en «Manga», porque sin eso los conteos de `/manga/` y `/comics/` mienten.

El recorte usa `mb_strcut`, no `substr`: un byte UTF-8 suelto al final reventaba el INSERT con «Incorrect string value» y el producto quedaba fuera del índice. El token pegado del título, en cambio, se mide con `strlen()` contra `Normalizer::MAX_TOKEN_LENGTH` (`:362-367`), que es un largo de columna en **caracteres** (`VARCHAR(40)`): son dos unidades distintas, y el síntoma sería que un título con un carácter multibyte que la normalización no quite deja de encontrarse sin explicación.

### `ProductSourceAuthority` — el notario

**Qué hace.** WooCommerce y WordPress esconden fallos de lectura detrás de cachés vacías. Esta clase tira la caché, la repuebla desde la base y verifica que lo que devuelven las funciones públicas coincide, campo a campo, con la fila real.

- **Ficheros:** `src/ProductSourceAuthority.php`.
- **Cuándo actúa:** sin hooks; la usan `DocumentBuilder` y `Reconciler`.
- **Qué lee y escribe:** lee `wp_posts` directamente y compara **quince** campos, enumerados en `src/ProductSourceAuthority.php:289-291`: `ID`, `post_type`, `post_status`, `post_title`, `post_excerpt`, `post_content`, `menu_order`, `post_name`, `post_date`, `post_date_gmt`, `post_modified`, `post_modified_gmt`, `post_parent`, `post_password` y `comment_status`. Para productos variables borra **y comprueba que se borraron** los transients `wc_var_prices_<id>` y `wc_product_children_<id>` antes del primer getter de precio; sin eso, el precio saldría de una foto agregada vieja.
- **Dónde lo opera el dueño / qué ve el cliente:** nada.

Usa el trait `FailsClosed`: el primer fallo cierra **todas** las lecturas del resto de la petición y conserva el diagnóstico original. La regla, escrita en el propio fichero: *«Un cero inventado es peor que un servicio apagado — se cachea, se publica y se convierte en “el catálogo no tiene nada”.»*

### `RebuildJob` — el director y el vigilante

**Qué hace.** Dirige la reconstrucción completa —crea las tablas sombra, las llena, hace el cambiazo atómico, publica y limpia— y además es el vigilante que cada 15 minutos rescata lo colgado y avisa por correo si el buscador lleva rato caído.

- **Ficheros:** `src/RebuildJob.php`.
- **Cuándo actúa:**
  - `akb_search_rebuild_batch` prioridad 10 → `handle_batch()`: despertador de un lote; una acción vieja que ya no corresponde al run vigente no hace nada.
  - `akb_search_rebuild_watchdog` prioridad 10 → `handle_watchdog()`: el vigilante.
  - `init` prioridad **20** → `handle_recovery()`: reconcilia fase y topología bajo el cerrojo; con la option ausente sale tras una sola lectura.
  - `init` prioridad **21** → `register_recurring_guard()`: asegura que el watchdog recurrente existe, memoizado con un transient que vive lo que el intervalo del propio vigilante: 900 s (12 h hasta search 0.1.67; véase S-3e1f3f03 en «Lo programado»).
  - `action_scheduler_ensure_recurring_actions` prioridad 10 → `repair_watchdog()`: recrear el watchdog si Action Scheduler detecta que faltan recurrentes. Sólo se engancha si la versión de Action Scheduler lo soporta (`:148-150`).
- **Qué lee y escribe:** las options `akb_search_rebuild_state`, `akb_search_last_rebuild`, `akb_search_rebuild_history` y `akb_search_serving_alarm`; el transient `akb_search_watchdog_guard_v2`; y todas las tablas del índice, incluidas las sombra.
- **Dónde lo opera el dueño:** Buscador → Índice → Mantenimiento: los botones «Reconstruir índice» / «Reintentar reconstrucción», y el cartel de bloqueo con el puntero a `ops/rescate-buscador.sh`.
- **Qué ve el cliente:** durante el tramo `SWAP_INTENT` → `DONE` el autocompletado y los filtros se apagan **a propósito**, respondiendo **503**. Un 503 es la forma que tiene un servidor de decir «ahora no puedo, vuelve luego»; el cliente no ve un error feo, simplemente el desplegable no aparece y el catálogo se pinta sin filtros.

![Fases de un rebuild y topología de tablas](diagramas/08-search-indice-y-rebuild-estados-rebuild.mmd)

**El viaje completo**, fase por fase, es el del diagrama de estados: `PREPARING` crea las tres tablas `_new` y siembra el manifiesto; `BUILDING` bombea lotes de 200 tareas; `CATCHING_UP` drena la deuda «repair» que se acumuló mientras construía y exige cobertura exacta —cero faltantes, cero sobrantes, cero huérfanos— y que la foto editorial no se haya movido; `SWAP_INTENT` escribe `generation_target` y ejecuta un único `RENAME TABLE` que en un golpe pone las nuevas en producción y aparta las viejas con sufijo `_old`; `SWAPPED`, `PUBLISHING` (seis confirmaciones **idempotentes** —o sea, que se pueden repetir sin hacer daño— en orden: buckets de precio, generación, purga REST, huella semántica, recibo editorial e historial), `CLEANUP` (borra las `_old` tras comprobar **dos** veces que no queda deuda) y `DONE` (borra el estado).

**El viaje no siempre es hacia adelante.** `CATCHING_UP` puede **volver a `BUILDING`**: si mientras drenaba la deuda de reparación apareció deuda nueva —alguien vendió un tomo, alguien guardó un producto—, el run retrocede y vuelve a drenar. No es un fallo; es lo que hace que un rebuild sobre una tienda viva tarde más de lo previsto, y sobre una tienda quieta, exactamente lo previsto. La única salida hacia adelante es que en una vuelta entera no aparezca nada nuevo.

**La «generación»** que aparece en `SWAP_INTENT` y en las confirmaciones es un **número de serie del índice**: cada vez que el índice cambia de contenido, sube. Sirve para dos cosas: que la purga de la caché sepa que hay algo nuevo que tirar, y que el recibo pueda afirmar «yo certifico la generación 47, no otra». Vive en la option `akb_search_index_gen`, y cambiarla es exactamente lo que dispara `RestCache::purge()`.

**La topología** es la foto de qué tablas existen, y sólo tiene cuatro valores: `clean_pre` (sólo las vivas, reposo sano), `ready_swap` (vivas + `_new`, hay un rebuild construyendo), `swapped` (vivas + `_old`, el RENAME ya ocurrió) y `ambiguous` (cualquier otra cosa, o una lectura de metadata fallida). Ante `ambiguous` nadie adivina: se persiste `BLOCKED` y se pide diagnóstico humano.

**Cuándo se apaga el buscador, exactamente.** Hay dos respuestas, y confundirlas es el error más caro de leer este sistema:

- Si el rebuild **no nace de un cambio editorial** (una migración de esquema, un `term_fanout`, un rebuild manual), el gate público sólo se cierra en el tramo final: desde `SWAP_INTENT` hasta que el estado desaparece. Mientras construye la sombra, la tienda sigue buscando con el índice viejo.
- Si el disparador **es** una option editorial o la termmeta `_akb_taxonomy_discount_pct`, el gate se cierra **en el instante de la escritura**, mucho antes del cambiazo: `stage_locked()` sube `wanted_seq` a N *antes* de dejar pasar el write (`src/EditorialDiscountPublication.php:2037-2087`) y el recibo se queda en N−1, y `bundle_is_ready()` exige `state.wanted_seq === receipt.wanted_seq` (`:2508`, la comparación en `:2545`). No se reabre hasta que se publica el recibo nuevo, o hasta que el re-probado lo re-sella.

**El encadenado de lotes.** Medido sobre un rebuild real de 1.568 documentos: 37 s de trabajo repartidos en 21 acciones de Action Scheduler que tardaron **158 s de reloj** — el 66 % era espera de cola, no CPU. Por eso una invocación ahora encadena lotes hasta agotar 20 segundos de presupuesto (`PUMP_BUDGET_SECONDS`, `:58`).

**Ese reloj no es, por sí solo, la ventana de 503**, y confundirlo es fácil. Esos 158 s son sobre todo la fase `BUILDING`, y durante `BUILDING` las tablas vivas siguen sirviendo: el gate sólo compara estado contra recibo (`src/EditorialDiscountPublication.php:2508`, la comparación de secuencias en `:2545`), y ni el estado ni el recibo se mueven hasta la publicación. Lo que el reloj cuenta es otra cosa según quién disparó el rebuild:

- Si el rebuild **nace de un cambio editorial**, el gate ya estaba cerrado desde la escritura, así que sí: los 158 s enteros son tiempo de buscador caído, y alargar el rebuild alarga la caída.
- Si el rebuild **no** es editorial —una migración de esquema, un `term_fanout`, un rebuild pedido a mano—, el cliente sigue buscando durante esos 158 s y sólo pierde el buscador en el tramo final. Medido en casa: un rebuild pedido a mano son ~30 s con el `suggest` respondiendo 200 todo el rato.

Que el watchdog corra cada 15 minutos importa por el primer caso, no por el segundo.

**El watchdog cada 15 minutos, no cada 5.** El intervalo es `WATCHDOG_INTERVAL = 900` (`src/RebuildJob.php`, constante pública). No confundir con el memo `akb_search_watchdog_guard_v2`, que es otra cosa: el transient que evita preguntarle a Action Scheduler en cada petición si el vigilante sigue agendado. **Cuánto vive ese memo depende de la versión.** Hasta search 0.1.67 lo fijaba una constante propia, `WATCHDOG_GUARD_TTL = 43200` (12 horas), cuatro líneas más abajo del intervalo. Desde el arreglo S-3e1f3f03 (2026-09-19; sale con la versión siguiente a 0.1.67) **esa constante ya no existe** —buscarla en el fichero no encuentra nada—: la vida del memo la decide `RecurringMemo::ttl( WATCHDOG_INTERVAL )` (`src/Support/RecurringMemo.php`), con una sola regla para el vigilante y para la revisión diaria: *un memo nunca dura más que el intervalo de la acción que protege, con techo de una hora*. Para el vigilante son 900 segundos; para la revisión diaria, 3.600.

Medido contra producción: en 31 días el watchdog completó 5.668 acciones frente a 756 de `rebuild_batch` —corría casi ocho veces por cada cosa que había que vigilar, y era el 36 % de toda la cola—. No se sube más porque es lo único que rescata un rebuild colgado, y esa ventana es la del 503. El comentario que lo explica (`:63-77`) añade una instrucción de despliegue que **está equivocada**: en **`:75`** dice que para reprogramar el intervalo hay que ejecutar `as_unschedule_all_actions('akb_search_rebuild_watchdog', array(), 'akb-search')`, y el grupo real es `akibara-search` (`src/Indexer.php:50`, el mismo que usan `ensure_watchdog()` y `uninstall.php:68`). Ese comando no cancela nada y no devuelve error.

**La alarma por correo.** Existe por un incidente concreto: el 2026-09-05 la tienda estuvo tres horas sin buscador ni filtros, y otra media hora más por la tarde, y las dos veces el único que se enteró fue quien estaba mirando la pantalla. Un solo correo por episodio, en texto plano, cuando el serving lleva **900 segundos** caído; y un correo de vuelta cuando se recupera. Va al **`admin_email`**, la dirección de correo del sitio, la que WordPress guarda en Ajustes → Generales y se consulta con `wp option get admin_email`.

Y sólo en producción, entendiendo por producción lo que devuelve `wp_get_environment_type()` (`:828`), que es un ajuste del sitio y no del plugin. En el espejo los correos van a **Mailpit** —un buzón de mentira que el entorno de Docker levanta para que las pruebas no manden nada a nadie—, y ahí un aviso de alarma sólo enseñaría a ignorar los avisos de verdad.

**El correo promete algo que su propio comando no da.** El cuerpo (`:775-792`) manda ejecutar, por orden, `wp akb-search status` y `wp akb-search rebuild`, y añade: «si el rebuild dice que hay uno en curso, comprueba en el estado que *done* avanza y que *work_blocked* está en cero». El problema es que `wp akb-search status` **no imprime ninguno de los dos**: sólo cuatro conteos de filas y dos resúmenes en JSON (`src/Cli.php:140-162`). `done` y `work_blocked` son claves de `RebuildJob::status()` (`:365`, `:374`), que alimenta el panel y la ruta REST. A las tres de la mañana hay que mirarlos en el panel, en `GET /wp-json/akibara/v1/search/admin-status`, o crudos con `wp option get akb_search_rebuild_state --format=json`. **Es un fallo del correo, no del capítulo, y queda anotado como deuda.**

**El pestillo, y hasta dónde llega cada arreglo.** `BLOCKED` y `ABORTED` no los reanuda nadie: `recovery_decision()` devuelve `idle`, `resume_run()` no tiene rama y `schedule($force)` sale ante fase terminal. Eso está bien: un estado trabado necesita una persona. Lo que no estaba bien es lo que el pestillo se llevaba por delante, y se arregló en tres pasos:

- **2026-09-06.** La recuperación automática del serving colgaba de que el estado fuese nulo, porque `maybe_recover_serving()` sólo se llamaba entonces; `watchdog_recovers_serving()` se extendió a BLOCKED y ABORTED. Con el pestillo puesto volvieron la reparación barata (`reconcile_now()`) y el correo. **Es lo que hay en producción hasta search 0.1.67**, junto con los dos defectos que cierran los puntos siguientes.
- **S-8ab53cfb (2026-09-19; sale con la versión siguiente a 0.1.67). Con fila de estado presente el vigilante ya no pide rebuild.** Antes pedía la señal `serving_recovery` y llamaba a `consume_pending_rebuild()`, que termina en `schedule_request()`, y éste devuelve cadena vacía en cuanto existe fila de estado: el rebuild **nunca podía arrancar**, la señal se acumulaba, y cada 15 minutos se escribía «serving caído sin run activo; rebuild solicitado» —falso dos veces: hay estado, y el rebuild no arranca— en un cuaderno de 50 entradas. Ahora el rebuild de recuperación sólo se pide **sin** fila de estado (`request_recovery_rebuild()`), que es el único caso en que alguien puede consumirlo. Consecuencia para quien está de guardia: **bajo un BLOCKED el cuaderno conserva lo que pasó ANTES del bloqueo; una entrada `serving_recovery` que veas ahí no es ruido del pestillo, es de antes** (o el sitio corre una versión ≤ 0.1.67). Con el pestillo puesto siguen funcionando la reconciliación barata y el correo; el rebuild lo arranca el rescate (`ops/rescate-buscador.sh`) al terminar.
- **S-190efeef (2026-09-19; misma versión). La alarma ya no depende de la fase.** `handle_watchdog()` llama a `maybe_recover_serving()` en **todas** las vueltas, también con un run vivo: un run colgado en `SWAP_INTENT`, `SWAPPED`, `PUBLISHING` o `CLEANUP` tiene el gate cerrado —cliente sin buscador ni filtros— y antes no alarmaba nunca, porque el vigilante reanudaba y se iba. `watchdog_recovers_serving()` ya sólo decide si la vuelta *reanuda* un run o *repara por su cuenta* (sin estado, BLOCKED, ABORTED). Con un run vivo sólo se mide: ni se reconcilia ni se pide otro rebuild por encima. Y se mide **con bytes frescos y después de actuar** (`EditorialDiscountPublication::serving_ready_fresh()`): `serving_ready()` es una foto por petición, y en la petición de cron esa foto ya la había tomado `reconcile_on_init()` (`init:23`) con el buscador caído, así que sin releer el «volvió» salía una vuelta tarde y el «lleva rato caído» podía salir en el instante exacto en que el run publicaba.

**Código sin llamador:** `has_live_actions()` (`:2786`) no lo invoca nadie en producción.

### `Reconciler` — la revisión diaria

**Qué hace.** Compara el índice contra el catálogo real, repara lo que encuentra y poda el log de búsquedas viejo.

- **Ficheros:** `src/Reconciler.php`.
- **Cuándo actúa:** la acción `akb_search_reconcile`, recurrente cada 24 horas; la agenda `ensure_scheduled()` desde `init` prioridad 20, y **sólo si la indexación automática está encendida**.
- **Qué lee y escribe:** lee `docs`, `facet_index` y `wp_posts`; poda `qlog`; escribe la deuda de los divergentes; y sella el resumen en `akb_search_reconcile_last` o el fallo en `akb_search_reconcile_last_error`, más el transient de alerta `akb_search_reconcile_alert` (2 días).
- **Dónde lo opera el dueño:** Buscador → Índice → botón «Verificar consistencia» y la fila «última verificación».
- **Qué ve el cliente:** nada directo.

Cuatro detectores, más un muestreo:

| Detector | Qué caza | Por qué nada más lo vería |
|---|---|---|
| Faltantes | productos publicados sin documento | — |
| Sobrantes | documentos de productos despublicados | — |
| Obsoletos | `post_modified_gmt > indexed_at` | una edición por WP-CLI con `--skip-plugins` se salta todos los oyentes |
| Filtros huérfanos | filas de `facet_index` cuyo término ya no existe | **borrar un término no mueve `post_modified_gmt`**, así que el detector de obsoletos es ciego, y el muestreo sólo mira `docs` |

El **muestreo** reconstruye 10 documentos al azar y los compara campo a campo, incluidos los tres materializados (`url`, `thumb_url`, `price_html`). Esos tres son los únicos que pueden quedar obsoletos sin que ningún oyente se entere, porque las mutaciones que los cambian son globales: cambiar la estructura de enlaces permanentes, regenerar los tamaños de imagen, ajustar la moneda o el sufijo de precio de WooCommerce.

Cualquier lectura fallida corta la corrida y escribe en `akb_search_reconcile_last_error`, que es una option **distinta** de la del éxito: un resultado parcial nunca se viste de corrida completa.

**Lo que hoy no hace, y conviene saber:** la corrida **entera** aborta si el gate editorial está cerrado. La primera comprobación de `run()` es el sello del esquema (`src/Reconciler.php:143-144`); la segunda es `capture_price_authority()`, y si devuelve algo que no es un array se sella `price_authority_capture_failed` y se sale (`:146-149`) — antes de los cuatro detectores, del muestreo y de la poda del qlog. Fallar cerrado es correcto para **reparar**: no se reindexa con un precio que no es autoritativo. El efecto secundario es que justo cuando el índice es más sospechoso —gate cerrado, recibo atrasado— nadie está contando faltantes, sobrantes ni facetas huérfanas, y el qlog deja de podarse. Y `HealthReport` lo pinta como `reconciler_fallido` (`src/HealthReport.php:477-487`), así que el operador ve **dos avisos rojos por una sola causa**.

### `EditorialDiscountPublication` — el guardián del precio

**Qué hace.** Se asegura de que el precio que muestra el buscador y el que calcula el carrito sean el mismo; y si no puede probarlo, apaga el buscador en vez de mentir. Es el componente más grande del área (3.861 líneas) y el más delicado.

- **Ficheros:** `src/EditorialDiscountPublication.php`.
- **Cuándo actúa:**
  - `pre_update_option` prioridad `PHP_INT_MAX` → `filter_pre_update_option()`: ante una de las diez options editoriales monta la deuda antes del write, y si no puede **veta** el cambio devolviendo el valor viejo.
  - `add_option` / `delete_option` prioridad **1** → `before_add_option()` / `before_delete_option()`: lo mismo para altas y bajas. Son **acciones** y no devuelven valor, así que desde 0.1.63 el veto de un alta o una baja que dejaría la foto ilegible se hace anulando **esa** consulta en el filtro `query` de `$wpdb` (formas exactas de Core, retirado en cuanto no queda veto pendiente). Si sólo falta el cerrojo, se deja rastro en el `AuditLog` y se sigue.
  - `updated_option` / `added_option` / `deleted_option` prioridad `PHP_INT_MAX`: cierran el marco.
  - `update_term_metadata` / `add_term_metadata` / `delete_term_metadata` (y sus variantes `_by_mid`) prioridad `PHP_INT_MAX`: igual, para el porcentaje de descuento de un término; devolver `false` cancela la escritura.
  - `updated_term_meta` / `added_term_meta` / `deleted_term_meta` prioridad `PHP_INT_MAX`: cierran el marco del término.
  - `init` prioridad **23** → `reconcile_on_init()`: reconciliación periódica, **estrangulada a una por minuto** (`RECONCILE_THROTTLE_SECONDS = 60`, `:283`), y a una cada 5 s si el buscador está caído.
  - `akb_search_editorial_discount_boundary` prioridad 10 → `handle_boundary()`: apaga o enciende sola la campaña al llegar la medianoche de su borde.
  - `template_redirect` prioridad **1** → `cap_page_cache_ttl()`: decide si la página se puede guardar en caché y con qué TTL.
  - `litespeed_control_finalize` prioridad 10 → `finalize_page_cache()`: revalida justo antes de que LiteSpeed guarde.
- **Qué lee y escribe:** las options `akb_search_editorial_discount_state`, `_receipt`, los recibos por run `akb_search_editorial_run_receipt_<sha256>`, `akb_search_editorial_reconcile_at` y `akb_search_reproof_deferred_since`. **Lee** (nunca escribe) las options de negocio de akibara-business: `akibara_taxonomy_discount_enabled/_from/_to/_apply_to/_sale_policy`, `akb_discount_max_pct` y `akibara_business_search_feed_contract_version`. Y la termmeta `_akb_taxonomy_discount_pct`.
- **Dónde lo opera el dueño:** el porcentaje y las fechas se guardan en **Akibara → Descuentos** (que es de business); Buscador → Índice → «Servicios» muestra el estado efectivo y su causa.
- **Qué ve el cliente:** decide si hay autocompletado y filtros, y si su página se puede guardar en caché.

**La foto.** Diez options, los porcentajes por término, las taxonomías con atributos de WooCommerce, la zona horaria y el **proveedor de precio**. Se toma **dos veces seguidas** y se exige que coincidan byte a byte; si no, no hay foto. De ahí sale una huella SHA-256.

(El «proveedor de precio» es quien contesta cuánto vale de verdad un producto con el descuento editorial ya aplicado. No es el buscador: es akibara-business, que se engancha al filtro `akb_search_effective_price` en `plugins_loaded` prioridad 20. El buscador pregunta y guarda la respuesta; por eso el protocolo editorial se registra en prioridad 30, **después**, para no adelantarse a quien tiene que responder.)

**La ventana.** Si el descuento está activo hoy, y cuándo es su próximo borde. El borde es la medianoche de Santiago del día siguiente al último día, y se calcula **por fecha**, no sumando un día a un instante (`:3169`): sumar días a un instante falla el día que el reloj se adelanta, porque en Chile la medianoche de ese día **no existe** —el reloj salta de las 23:59:59 a la 01:00— y PHP la resuelve como la 01:00; con `to = 2026-09-06` el borde quedaba a las 04:00 UTC en vez de a las 03:00, y la promoción vivía una hora de más. Y una advertencia cruzada: esa forma tiene que ser **idéntica** a la de `AdoptedSnapshot::compute_window()` en akibara-business. Si los dos lados difieren en un segundo, la foto se rechaza entera y se apagan buscador y facetas.

**La intercepción.** Toda escritura de esas options y de la termmeta pasa por aquí: primero el cerrojo, luego la intención con secuencia +1, luego el fan-out durable de todos los productos afectados, luego la solicitud de rebuild, luego la purga PRE, **y sólo entonces** se deja pasar el write. Para las altas y bajas de option —que no se pueden vetar— hasta el 2026-09-02 se lanzaba excepción, y como salía de `do_action()` **tumbaba la petición entera**: un `wp option delete` durante el rebuild terminaba en fatal. Hoy se deja rastro en el `AuditLog` y se sigue, con una pieza que el mapa no contaba: `record_unfenced_option_write()` también **borra la marca del estrangulador** (`clear_reconcile_mark()`, `:871-881` y `:2152-2160`). Esa es la mitad operativa del arreglo: sin ella el reconcile que reabre el serving quedaría esperando la ventana de 60 s, y medido en el espejo hacían falta **tres** llamadas a `reconcile_now()` para volver a 200. Con la marca borrada, la petición siguiente reconcilia.

**Una sola noción de validez (0.1.63).** Toda escritura vigilada pasa por `write_verdict()`, que contesta con `source_snapshot_from_options()` —la MISMA función que arma la foto que sirve la tienda— sobre las filas **crudas** de MySQL (sin caché de objetos) y con la option sustituida por los bytes que Core va a escribir (`stored_option_bytes()`). Cinco veredictos: `WRITE_UNCHANGED` (los bytes ya están en la fila: no se cerca nada; es lo que evita que guardar Ajustes → Generales, que no envía `gmt_offset`, suba la revisión y purgue el sitio), `WRITE_FENCED` (legible antes y después: manda el cerco), `WRITE_REPAIRS` (lo guardado es ilegible y esto lo arregla: pasa sin cerco y pide la reconstrucción), `WRITE_BREAKS` (con esto el lector no podría leer una configuración sana: se veta) y `WRITE_STILL_BROKEN` (ya estaba rota y esto no la arregla: se veta y se nombra el dato). Más `REJECTED_BUSY` cuando el cerco lo tiene otro proceso. `snapshot_readable()` restaura el pestillo `read_authority_available`, porque la pregunta es hipotética; y `repairs_own_group()` acepta arreglar de a un dato cuando hay dos rotos (zona horaria y `gmt_offset` se juzgan juntas). Hasta 0.1.62 la escritura normal no miraba el valor y la salida de reparación usaba otra regla (`valid_source_option_value()`, retirada): fallaban en los dos sentidos. `status()['broken_options']` dice qué dato está roto y en qué pantalla se corrige, y el aviso por usuario (`record_rejected_write()`) nombra la causa; sólo la causa «ocupado» se delega en el aviso del panel de business (filtro `akibara_panel_options_not_saved`).

**Nada queda a medias.** Si Core no llega a confirmar una escritura cercada (un `UPDATE` que no toca filas, un `add_option()` que se niega porque `get_option()` devuelve un valor calculado), `release_on_shutdown()` deja `marco_editorial_sin_cerrar` y suelta el estrangulador; si `stage_locked()` falla después de subir la revisión, `abandon_staged_revision()` deja `deuda_editorial_a_medias` con el paso que falló y hace lo mismo. En los dos casos la petición siguiente retoma la deriva y el servicio vuelve solo, sin reconstrucción (medido en el espejo). Y `record_superseded_swap_receipt()` cierra una publicación cuya revisión avanzó entre el swap y el recibo: antes quedaba atascada con las tablas `_old` puestas.

**El atajo barato.** Cambiar un ajuste editorial no siempre mueve un precio. El caso medido en producción el 2026-09-02: **ningún término tenía porcentaje**, así que encender o apagar el descuento no movía un solo peso — y aun así el buscador se caía en 503 hasta que alguien corría un rebuild completo. Ahora `reprove_physical_receipt()` + `editorial_materialization_is_unchanged()` (`:1792-1870`) recorren los 1.568 productos comparando precio y precio pintado **uno a uno** —nunca por muestreo: sellar un recibo falso es el fallo que este protocolo existe para impedir—. Si no cambia nada, re-sellan el recibo y **retiran la señal de rebuild**. El docblock mide el coste: 1,39 s en frío y unos 36 MB de pico, dentro de una petición del front, a lo más una vez por minuto mientras el gate esté cerrado por revisión editorial.

**La válvula.** Una divergencia de precio no siempre es un índice que miente: también puede ser un índice a medio escribir. Se aplaza mientras haya trabajo live en vuelo… pero con tope de **600 segundos**, porque sin tope un `null` se repite para siempre y eso es exactamente lo que el 2026-09-05 dejó el suggest en 503 permanente.

**El gate de caché de página.** Una página sólo se autoriza a guardarse si es pública, GET/HEAD, **sin parámetros de consulta**, sin usuario conectado, sin cookies privadas, no es carrito/checkout/cuenta, **el permiso de servido resuelve** —ojo: aquí la palabra inglesa *lease* del código no significa el arriendo de una tarea del que habla `WorkRepository`, sino el permiso que concede `serving_ready()`; son dos cosas distintas con el mismo nombre—, la ventana está **cerrada ahora** y su próximo borde está a más de 12 horas. La invariante es `horizonte (43200 s) > TTL (3600 s)`: así ninguna copia guardada sobrevive al borde. **Desde 0.1.65 la ficha de producto no usa ese TTL:** vive hasta un minuto antes de la medianoche de la tienda (`PRODUCT_PAGE_MIDNIGHT_MARGIN`, `seconds_before_store_midnight()`), acotada además por la distancia al borde. Es seguro porque lo que la ficha calcula con la fecha cuenta días de calendario, LiteSpeed purga la ficha al cambiar su stock o precio, y los datos de otros tomos que muestra los purga akibara-business por etiqueta de serie (`SeriesCacheTags`). Los listados siguen con 3600 s: LiteSpeed no los purga al cambiar un producto. En el último minuto del día la ficha no se guarda. La zona sale de la MISMA foto que autorizó el token (`timezone` en `serving_capture_window`). Medido en producción el 2026-09-15 antes del cambio: 38 de 39 fichas al azar llegaban con la caché vencida (TTFB mediana 0,95 s contra 10 ms guardada). Con una advertencia honesta en el propio código: eso vale para el origen, **no para Cloudflare**, que almacena igual pese al `no-store` (medido: `/manga/` devolvió `cf-cache-status: HIT` con `age: 246`).

**El anillo de recibos.** `RUN_RECEIPT_KEEP = 20` (`:85`), pero el número operativo es **21**: la poda sólo actúa cuando ya hay 20 o más filas supersedidas (`if ( ! is_array($rows) || count($rows) < RUN_RECEIPT_KEEP ) return;`, `:3686`) y el recibo recién escrito queda siempre fuera. Además escanea como mucho 500 filas (`RUN_RECEIPT_SCAN_LIMIT`, `:88`) y no comprueba `last_error`: hace lo que puede y no se queja si falla, porque la publicación ya quedó sellada cuando esto corre.

*(Aquí mandó el código. El borrador de verificación situaba esta poda en `:1672-1705`; abrir el fichero dice que la comprobación está en `:3686` y el recorte del sobrante en `:3700`. Lo que se cita arriba es lo que hay en el snapshot.)*

**Tres filtros que sí son palancas, y no tienen pantalla.** El resto del capítulo insiste en que aquí no hay ajustes invisibles, y es cierto en el sentido de que ninguna option cambia el comportamiento a espaldas del dueño. Pero **sí hay tres puntos de extensión** que otro plugin —o un fragmento de código en el tema— podría usar para cambiar reglas de este componente. Hoy **no los usa nadie**, comprobado sobre los cuatro plugins y el tema; conviene saber que existen, porque si mañana alguien los engancha, nada en pantalla lo dirá:

| Filtro | Qué cambia | Valor sin nadie enganchado |
|---|---|---|
| `akb_search_editorial_reconcile_throttle` (`:328`) | los segundos del estrangulador de la reconciliación periódica | 60 s |
| `akibara_taxonomy_discount_max_pct` (`:2669`) | el techo del porcentaje de descuento editorial | el de `akb_discount_max_pct` |
| `akibara_taxonomy_discount_taxonomies` (`:2955`) | **qué taxonomías** llevan descuento editorial | el mapa que el propio código deriva |

**Código sin llamador:** `is_ready()` (`:510`) y la constante `OPTION_HISTORY = 'akb_search_editorial_discount_history'` (`:31`), declarada y jamás leída ni escrita. Esa constante promete un historial que no existe, y es lo primero que alguien va a buscar al depurar el gate.

### `SemanticPublication` — la misma prueba, para la configuración del buscador

**Qué hace.** Lo que `EditorialDiscountPublication` hace con los precios, esto lo hace con las trece options «semánticas» del buscador: anota antes de escribir, confirma después, y deja una huella que prueba qué configuración está publicada.

- **Ficheros:** `src/SemanticPublication.php`.
- **Cuándo actúa:** `pre_update_option` (`PHP_INT_MAX`), `add_option` y `delete_option` (prioridad 1) para la barrera de 10 s; `updated_option` / `added_option` / `deleted_option` (`PHP_INT_MAX`) para confirmar, purgar y sellar la huella; e `init` prioridad **23** → `reconcile_fingerprint()` (`:246`), que repara escrituras hechas fuera de los hooks.
- **Qué lee y escribe:** la option `akb_search_semantic_fingerprint`, y las trece que vigila: sinónimos, stopwords, corrector de tipeos, búsqueda por SKU, novedad, populares, los tres interruptores (`akb_search_indexing`, `_suggest`, `_facets`), la definición de facetas, los buckets de precio, su modo, y la categoría por defecto de WooCommerce (`default_product_cat`).
- **Dónde lo opera el dueño:** las pestañas Sinónimos, Stopwords, Facetas, Precios y Ajustes del panel del buscador.
- **Qué ve el cliente:** nada directo; de aquí depende que los tres interruptores se lean con autoridad.

Sirve esos valores desde **una sola foto cruda de `wp_options` por petición**, sin pasar por la caché de objetos, y si esa lectura falla cierra la autoridad de toda la petición. Si cambia la definición de facetas o la categoría por defecto, además pide rebuild: esas dos alimentan el espejo materializado.

**Dos diferencias con el gemelo editorial, y en qué quedaron:**

1. **`before_add()` y `before_delete()` ya no lanzan (search 0.1.68, S-caa820de).** Hasta 0.1.67 lanzaban `RuntimeException` cuando no se podía persistir la intención previa; cuelgan de `add_option` y `delete_option`, que son acciones, así que la excepción salía de `do_action()` y **tumbaba la petición entera** del dueño. Ahora se anota `option_semantica_sin_cercar` en el cuaderno y la escritura pasa. Lo que la escritura sin cercar se saltaba se repone en `complete()`, con la option ya escrita: la publicación posterior de siempre y —si la option define el espejo de filtros (`FacetRegistry::OPTION_KEY`, `default_product_cat`)— **el rebuild que `stage()` no llegó a pedir**. Sin eso la publicación y `reconcile_fingerprint()` sólo invalidaban cachés y el espejo seguía armado con la definición vieja. Si tampoco entonces se puede pedir, queda `rebuild_de_filtros_no_pedido` en el cuaderno.
2. **`reconcile_fingerprint()` no tiene estrangulador, y medido no lo necesita.** Cuelga de `init` prioridad 23 sin marca de throttle. Se temía que costara unas cuatro consultas sin caché por petición, como su gemelo editorial antes de acotarse (21 consultas; `EditorialDiscountPublication.php:283`). **Medido el 2026-09-19 en el espejo, en régimen: 1 consulta y 0,16 ms por petición** (la foto cruda de las trece options es una sola lectura y el recibo ya está en memoria). Un estrangulador retrasaría la detección de escrituras hechas fuera de los hooks a cambio de nada medible: se deja como está, con este número al lado para quien vuelva a dudarlo.

### `Support\RestCache` — ninguna respuesta se guarda

**Qué hace.** Fija la política de caché de las respuestas del buscador: ninguna se puede guardar, ni en el origen ni en Cloudflare. Y guarda el recibo de cada purga, que es la prueba física que el rebuild exige antes de publicar.

- **Ficheros:** `src/Support/RestCache.php`.
- **Cuándo actúa:** `update_option_akb_search_index_gen` y `add_option_akb_search_index_gen` prioridad 10 → `purge()`. Dispara `litespeed_purge` y `litespeed_control_set_nocache`. **Con una condición que importa:** esos dos enganches viven en `src/Search.php:176-177`, o sea **después** del `return` de `runtime_schema_ready()` (`:172-174`). Si el esquema no está sellado —el escenario del caso 7—, cambiar la generación **no purga nada**, porque no hay nadie escuchando. Es coherente (sin esquema sellado no se escribe el índice), pero no es lo que uno supone leyendo sólo la línea de arriba.
- **Qué lee y escribe:** la option `akb_search_rest_purge_receipt`.
- **Dónde lo opera el dueño:** en ningún sitio.
- **Qué ve el cliente:** las cabeceras de `/suggest`, `/popular` y `/count`.

`no-store` permanente, y además las dos cabeceras de CDN, porque **Cloudflare ignora `Cache-Control`**: medido el 2026-08-23, `/suggest?q=naruto` viajaba con `no-store` y volvía con `cf-cache-status: HIT` y `age: 3`, con el `price_html` y el sello de stock dentro, y sin nadie que lo invalidara al cambiar precio o stock. El TTL del borde era de unos 600 s: hasta diez minutos sirviendo un precio equivocado.

Y una decisión explícita de no escalar: `litespeed_purge_all` sí llega a Cloudflare, pero **no se usa aquí**, porque `purge()` cuelga de cada escritura live —o sea de cada venta— y un flush total del borde se midió en 48 → 1.057 ms la landing de serie.

### `HealthReport` — el termómetro

**Qué hace.** No repara nada: junta los números y traduce el estado a tres colores y a frases que el dueño entiende.

- **Ficheros:** `src/HealthReport.php`.
- **Cuándo actúa:** sin hooks propios; lo llama el panel y la ruta REST.
- **Qué lee y escribe:** lee de `RebuildJob`, `Reconciler`, `WorkRepository`, `EditorialDiscountPublication` e `Install`; no escribe nada.
- **Dónde lo opera el dueño:** la banda de salud de Buscador → Índice, que se repolla cada 4 segundos mientras hay una reconstrucción, vía `GET /wp-json/akibara/v1/search/admin-status` (requiere `manage_woocommerce` y nonce de cookie; responde `Cache-Control: no-store`).
- **Qué ve el cliente:** nada.

Dos mitades: `collect()` toca la base, y `verdict()` es **pura** —sin WordPress, sin reloj propio: todo entra por parámetro— y por eso se puede fijar con pruebas. Tres veredictos: `sano`, `atencion`, `reconstruyendo`.

Avisos que sólo existen aquí: índice vacío (la tienda sirve el catálogo sin filtrar, que es el *fail-open* del front); documentos de más («1.474 de 1.466» con banda verde mentiría); productos variables publicados (el índice sólo representa al padre, así que no busca por SKU de variación, ordena por el precio más bajo del rango y no lo marca en Oferta — hoy el catálogo es 100 % simple y el aviso existe para que el primero no entre en silencio); collation de tokens degradada; y catálogo servible por encima de 5.000 documentos visibles, que es aviso de capacidad y no fallo.

### `AuditLog` — el cuaderno

**Qué hace.** Guarda «qué se tocó y cuándo»: un anillo de 50 entradas con fecha, usuario, acción y detalle, para que «qué toqué la semana pasada» no dependa de la memoria del operador.

- **Ficheros:** `src/AuditLog.php`.
- **Cuándo actúa:** sin hooks; lo escriben el panel, `RebuildJob` y `EditorialDiscountPublication`.
- **Qué lee y escribe:** la option `akb_search_audit_log`, con tope 50 (`:27`).
- **Dónde lo opera el dueño:** Buscador → Índice → Diagnóstico técnico → «Registro de cambios», 15 entradas visibles, escapadas con `esc_html`.
- **Qué ve el cliente:** nada.

Conviene saber que el anillo compite consigo mismo. El camino de aplazamiento del re-probado escribe una entrada por ciclo (`receipt_reproof_diferido`, `_rechazado`, `_valvula`, `_carrera`), así que en un episodio malo y largo el anillo puede llenarse de ruido del sistema y perder los guardados reales del dueño — justo después de un incidente, que es cuando hace falta saber qué se tocó. **El otro competidor se retiró:** hasta search 0.1.67 el vigilante escribía además una `serving_recovery` cada 15 minutos mientras hubiera un pestillo puesto, y en unas doce horas y media el anillo entero era eso. Desde S-8ab53cfb (2026-09-19; versión siguiente a 0.1.67) con fila de estado presente no se pide rebuild ni se escribe la entrada: en doce horas de bloqueo el vigilante deja, como mucho, la línea del aviso enviado.

**Desde search 0.1.68 el cuaderno ya no se pisa a sí mismo.** Dos cosas cambiaron. Lo que se repite se **pliega** en una fila con contador (`n`, `t0`; ventana de 8 filas), así que el ruido de máquina no expulsa los guardados del dueño. Y cada anotación **relee la option de la base** (`wp_cache_delete` antes de leer) dentro de un candado corto propio (`GET_LOCK` de 2 s, de mejor esfuerzo): un runner de Action Scheduler vive medio minuto y anota varias veces, y con la copia en memoria de su primera lectura pisaba —sin error ni rastro— lo que el dueño había guardado en el panel entre medio. Sin candado se anota igual: una fila del cuaderno nunca frena la operación que la escribe.

### `Cli` — la consola

**Qué hace.** Los cuatro comandos de terminal para cuando algo va mal: `rebuild`, `status`, `reindex <id>` y `reconcile`.

- **Ficheros:** `src/Cli.php`.
- **Cuándo actúa:** a mano, por `ssh akibara` o `docker compose exec`.
- **Qué lee y escribe:** lo que lea o escriba el componente que invoque; deja rastro en el `AuditLog`.
- **Dónde lo opera el dueño:** la terminal.
- **Qué ve el cliente:** nada.

**Hay DOS mensajes que dicen «Ya hay un rebuild en curso», y son carriles distintos.** Distinguirlos es lo primero que necesita el de guardia, porque dicen cosas contrarias sobre qué hacer a continuación:

| Orden | Texto literal | Cómo sale | Código de salida | Qué significa |
|---|---|---|---|---|
| `wp akb-search rebuild --async` | `Ya hay un rebuild en curso — no se encoló otro.` | `WP_CLI::log` | **0** (éxito) | No se encoló nada. Puede ser que haya un run vivo… o que la barrera temporal aún no venza |
| `wp akb-search rebuild` (sin `--async`) | `Ya hay un rebuild en curso (option akb_search_rebuild_state).` | `WP_CLI::error` | **distinto de 0** | Existe la fila `akb_search_rebuild_state`. Si está vacía o trabada, esto es el pestillo del caso 10 |

El primero está en `src/Cli.php:73` (dentro del bloque `:68-75`); el segundo, en `:80` (bloque `:79-82`).

Y la diferencia de fondo: **sin argumentos corre síncrono**, que es la vía manual, y por eso `run_sync()` no espera barreras temporales, sólo exige el esquema. Con `--async` llama a `RebuildJob::schedule()`, que exige `Install::runtime_ready()`, y ahí está el detalle que costó el incidente del 2026-09-05.

**Las tres funciones de «¿estamos listos?»**, que el resto del capítulo usa como si ya se hubieran presentado, son éstas y comprueban cosas distintas (`src/Install.php`):

- `runtime_schema_ready()` (`:1007`) — «¿el esquema está sellado?». Es la compuerta que decide si se cablean escritores de tablas.
- `pending_rebuild_is_ready()` (`:982`) — «¿la solicitud de rebuild pendiente ya puede empezar?». Mira el campo `not_before` de la cola de solicitudes: si una solicitud dice «no empieces antes de tal instante», esto devuelve `false` hasta que ese instante llegue.
- `runtime_ready()` (`:1043`) — las dos anteriores juntas: `runtime_schema_ready() && pending_rebuild_is_ready()`.

**El live-lock del 2026-09-05** es lo que pasa cuando la segunda dice que no y nadie lo explica. Una migración deja una señal de rebuild con 300 segundos de gracia (`not_before` en el futuro). Durante esos 300 segundos `runtime_ready()` es `false`, así que el carril asíncrono se niega a encolar —y lo anuncia con el mensaje engañoso de arriba, **con código de salida 0**, como si todo hubiera ido bien—. El sistema no está parado ni trabado: está esperando, girando sin avanzar, que es lo que la palabra *live-lock* nombra. El carril síncrono no tiene esa barrera; el asíncrono la hereda.

Y `rebuild_failure_message()` (`:104-131`) declara fallido un rebuild sano si alguien publica un producto durante la ventana: `COVERAGE_TOLERANCE = 1.0` (`src/RebuildJob.php:43`) no deja margen ninguno pese a que el comentario promete uno, y `$eligible` se recuenta **después** del rebuild, así que `docs = 1568` con `eligible = 1569` sale por `WP_CLI::error` con código distinto de cero. Los runbooks de despliegue confían en ese código.

### Las piezas pequeñas

- **`Arrival`** (`src/Arrival.php`): escucha `woocommerce_product_set_stock_status` en prioridad **10** —antes que el `Indexer`, que va en 12— y anota en postmeta `_akb_onbackorder_at` (primer momento observado en preventa) y `_akb_first_instock_at` (primera llegada real). Hoy **nadie lee** ese dato: se acumula para una versión futura de «Novedades», porque esa historia no se puede reconstruir hacia atrás. Ninguna migración ni rebuild las toca (`src/Arrival.php:20`). **Tiene un segundo escritor que no es el oyente:** la revisión diaria llama a `Arrival::seed_pending()` (`src/Arrival.php:98`, invocado desde `src/Reconciler.php:403`), que siembra hasta **200** preventas antiguas por corrida — las que ya estaban en «a pedido» antes de que esta captura existiera y por tanto nunca dispararon el oyente. Se busca aquí, en la ficha, y estaba sólo en la tabla de lo programado.
- **`Novedad`** (`src/Novedad.php`): el filtro de recientes. El índice materializa **siempre** la fecha; los tramos —7 días, 1 mes, 3 meses— se calculan al vuelo, así que encender o apagar el filtro nunca obliga a reindexar. Escucha los cambios de la option `akb_search_novedad` sólo para invalidar el memo de la petición.
- **`StockLabels`** (`src/StockLabels.php`): la única fuente de la palabra que ve el cliente sobre disponibilidad. Dispara `akb_stock_status_label` y `akb_search_availability_value`. Fail-closed a «Agotado» (`:105`): nunca se promete disponibilidad no verificada.
- **`Support\FailsClosed`** (`src/Support/FailsClosed.php`): la regla de la casa escrita una sola vez — si una lectura no se puede garantizar, no se devuelve un resultado a medias, se apaga el servicio para el resto de la petición.
- **`Support\OptionCodec`** (`src/Support/OptionCodec.php`): traduce entre lo que PHP tiene en memoria y los bytes exactos que WordPress guarda en la columna. Existe porque el plugin lee y escribe options sin pasar por la caché y necesita reproducir el formato de WordPress al byte.

**Siete piezas que este capítulo nombra pero no explica, porque se cuentan en el 09.** Van aquí para que nadie las busque y concluya que no existen:

- **`Normalizer`** (`src/Normalizer.php`): quien decide **qué es una palabra**. Pasa todo a minúsculas, quita tildes, convierte «ñ» en «n» y da a los números forma canónica doble («1» indexa también «01»), para que «haikyu 1» encuentre «Haikyu!! 01». De él depende todo lo que `DocumentBuilder` tokeniza, y de él sale `MAX_TOKEN_LENGTH`. Es una clase pura, sin WordPress.
- **`QueryLogger`** (`src/QueryLogger.php`): el **único escritor** de la tabla `wp_akb_search_qlog`. Guarda cada búsqueda **agregada** por día, consulta y origen, nunca una fila por evento, y limpia de la consulta los correos y los números largos antes de guardarla. El `Reconciler` que aparece en este capítulo sólo **poda** esa tabla; no escribe en ella.
- **`PriceBuckets`** (`src/PriceBuckets.php`): deriva los tramos del filtro de precio de los precios reales del catálogo, sin nada fijado a mano. Es la primera de las «seis confirmaciones» de la publicación, y lo que `akibara_search_activate()` siembra la primera vez que se activa el plugin.
- **`FacetRegistry`** (`src/FacetRegistry.php`): la definición canónica de las facetas, y por tanto la dueña de la **lista de taxonomías indexadas** que decide si guardar un término mueve algo o no.
- **`SeriesSuggestions`** (`src/SeriesSuggestions.php`): las sugerencias de **serie** del autocompletado, que no son documentos del índice sino términos leídos aparte y cacheados. Se registra **siempre** (`src/Search.php:126`), con la indexación encendida o apagada, porque su invalidación al renombrar o borrar una serie es exactamente el caso 1 de este capítulo: sin ella el panel seguiría ofreciendo landings que ya no existen.
- **`FacetService`** (`src/FacetService.php`): los conteos de los filtros. Dueña del prefijo `akb_lc_`.
- **`Support\RateLimit`** (`src/Support/RateLimit.php`): el tope de peticiones por IP y por minuto de los endpoints públicos del buscador. Dueña del prefijo `akb_rlm_`. Es una copia autocontenida —a propósito— del limitador de akibara-business, para no depender de él.

### `SearchAdminSection` — la pantalla

**Qué hace.** Es el panel entero: las siete pestañas, sus formularios, sus botones y el «Registro de cambios». Todo lo que en este capítulo se llama «Buscador → algo» es esta clase.

- **Ficheros:** `src/SearchAdminSection.php`.
- **Cuándo actúa:** `admin_menu` prioridad **20** → `register_menu()` (`:110`), que cuelga el menú de «Akibara» si el panel de business existe y si no lo crea como menú propio; y `admin_enqueue_scripts` → `enqueue_admin()`, sólo en su propia pantalla. **Nunca toca el front:** cero superficie para el cliente.
- **Qué lee y escribe:** lee todo lo que `HealthReport::collect()` junte; escribe las options de configuración de cada pestaña (sinónimos, palabras ignoradas, facetas, tramos de precio, los tres interruptores) y deja rastro en el `AuditLog`.
- **Dónde lo opera el dueño:** es la propia pantalla. La dirección y las siete pestañas están al principio del capítulo.
- **Qué ve el cliente:** nada. Aunque los tres interruptores del front estén apagados, el panel sigue registrado a propósito: existe precisamente para operar cuando el front está caído.

**Cada guardado y cada botón pasan por el mismo guardia:** `guard()` (`:1778`) exige el permiso `manage_woocommerce` **y** comprueba el nonce de la acción. Sin las dos cosas, `wp_die()`.

**La pestaña `indice` es una sola llamada a `HealthReport::collect()` repartida en cinco cajas** (`render_index_status()`, `:551-562`), en este orden fijo: banda de salud → Servicios → Estado → Mantenimiento → Diagnóstico técnico. El orden es la respuesta a cuatro preguntas, por diseño: ¿está sano? → ¿qué servicios corren? → ¿está actualizado? → ¿tengo que hacer algo? → ¿cómo diagnostico?

Dos detalles que ahorran una llamada de madrugada:

- **El botón de Mantenimiento cambia con el estado.** Si hay un run vivo dice «Reintentar reconstrucción»; si el estado quedó en fase terminal **no hay botón**, y en su lugar sale un cartel rojo que dice que la tienda sigue sirviendo el índice anterior completo y que hay que ir a `ops/rescate-buscador.sh`. Antes había un botón ahí y no hacía nada.
- **La banda de salud se repolla cada 4 segundos** mientras hay una reconstrucción, contra `GET /wp-json/akibara/v1/search/admin-status`. Esa ruta va por fuera de los tres interruptores, también a propósito.

### `Search` — el interruptor general

**Qué hace.** Decide qué partes del buscador se enchufan en cada petición, según tres ajustes del dueño y según si el índice está en condiciones de servir.

- **Ficheros:** `src/Search.php`.
- **Cuándo actúa:** se construye en `plugins_loaded:15`. Engancha `init:20` (`consume_pending_rebuild`), `rest_api_init:10` (la ruta del panel) y `plugins_loaded:30` para registrar el protocolo editorial **después** de que business, en prioridad 20, enganche su proveedor de precio.
- **Qué lee y escribe:** las options `akb_search_indexing`, `_suggest` y `_facets`, siempre a través de `SemanticPublication::authoritative_value()`, nunca por `get_option()`. Deja tres filtros de override para QA (`akb_search_indexing_enabled`, `_suggest_enabled`, `_facets_enabled`), y un valor no booleano falla apagado.
- **Dónde lo opera el dueño:** Buscador → Ajustes.
- **Qué ve el cliente:** si hay autocompletado y si hay filtros.

El orden de las compuertas en `register()` importa, y las líneas exactas son éstas:

- Si el esquema no está sellado, `runtime_schema_ready()` hace salir el método en **`:172-174`** (la comprobación en `:172`, el `return` en `:173`). No se cablea ningún escritor de tablas, y las rutas ya registradas más arriba degradan a **503** en vez de a 404: el cliente recibe «ahora no puedo» y no «esto no existe».
- Justo después de esa compuerta, y sólo si se pasó, se enganchan las dos purgas de la caché REST (**`:176-177`**).
- Si además la indexación está apagada, sale en **`:179-181`**, antes de los 25 oyentes (**`:183`**) y antes de agendar la revisión diaria (**`:186`**).

Un efecto secundario que conviene entender: `indexing_enabled()` cae a `false` ante **cualquier** fallo de la foto semántica, porque `authoritative_value()` falla cerrado. Fallar cerrado está bien para *servir*; para *escuchar*, el efecto es el contrario — si la foto no se puede leer en esa petición, los oyentes no se registran y ese guardado de producto no deja deuda durable. El consuelo es que en esa misma petición el gate de servido también está cerrado, así que el cliente ve un 503 y no un precio viejo; lo que se pierde en silencio es la deuda de ese evento.

### `ops/rescate-buscador.sh` — la manguera

**Qué hace.** El procedimiento de emergencia cuando el rebuild se queda atascado y ninguna orden del plugin lo desatasca. No es parte del plugin, pero es parte del sistema, y **el orden de sus pasos es el arreglo**.

- **Ficheros:** `ops/rescate-buscador.sh` (en el repositorio, no en el plugin).
- **Cuándo actúa:** a mano, cuando el panel dice «La reconstrucción quedó bloqueada (`orphan_topology_without_state`)» y no hay botón de reintento.
- **Dónde lo opera el dueño:** la terminal. **Acepta dos destinos**, y la casa manda probar primero en el espejo:

  ```bash
  bash ops/rescate-buscador.sh espejo        # el Docker local; exige el entorno levantado
  bash ops/rescate-buscador.sh produccion    # akibara.cl; exige `ssh akibara` y pide escribir SI
  ```

  La única diferencia entre los dos es por dónde hablan con WordPress: `espejo` usa `docker compose run --rm --no-deps -T cli wp …`; `produccion` usa `ssh akibara`. Los pasos, el orden y la parada de seguridad son idénticos, así que el ensayo en el espejo es fiel. Antes de tocar producción conviene probar que `ssh akibara` responde.

Los cuatro pasos: leer la topología **por SQL puro** (`wp db query` no arranca WordPress, así que no puede acuñar un bloqueo por el camino); **PARAR** si hay tablas `_old`, porque el RENAME ya ocurrió y `_old` es la única vuelta atrás —no hay rename inverso—; soltar las `_new` **primero**; y borrar `akb_search_rebuild_state` **después**. Al revés —borrar la option dejando la sombra— el primer arranque de WordPress acuña `BLOCKED / orphan_topology_without_state`, que es un pestillo que ni el panel ni la consola deshacen. Medido en las dos direcciones; sólo esa combinación sale.

**Dos advertencias sobre el propio guion**, medidas contra el snapshot:

- Su cabecera **se corrigió el 2026-09-19** (S-8ab53cfb, S-190efeef). Hasta entonces argumentaba desde un comportamiento que el código ya no tenía —afirmaba que `handle_watchdog()` sólo mira el serving con el estado nulo— y quien la leyera a las tres de la mañana decidía creyendo que bajo un BLOCKED no hay ni alarma ni reparación barata, y las hay. Ahora dice lo que el código hace: con el pestillo puesto siguen vivas la reparación barata y la alarma, el vigilante **no** pide un rebuild que nadie podría consumir, y el rebuild lo arranca el propio guion en su paso 4. También dejó de citar líneas de `RebuildJob.php` —estaban todas corridas— y cita las funciones por nombre, que no caducan con cada arreglo.
- Fija a mano el prefijo `wp_` en las consultas de topología y en el DELETE de `wp_actionscheduler_actions`. En un sitio con otro prefijo no haría nada, y no lo diría.

---

## Lo programado

![Reloj de Chile del índice](diagramas/08-search-indice-y-rebuild-cron.mmd)

Todo lo de este capítulo corre sobre **Action Scheduler**, la cola durable que trae WooCommerce, en el grupo `akibara-search` (`src/Indexer.php:50`). Y Action Scheduler sólo avanza si alguien la empuja: en producción `DISABLE_WP_CRON` está encendido y quien llama a `wp-cron.php` es una tarea del panel de Hostinger **cada 5 minutos**. Esa es la latencia mínima real de cualquier cosa «asíncrona» de aquí.

| Trabajo | Mecanismo | Frecuencia y hora Chile | Quién lo programa | Qué hace | Qué pasa si corre dos veces |
|---|---|---|---|---|---|
| `akb_search_rebuild_watchdog` | Action Scheduler, recurrente | **cada 15 minutos**, sin hora fija | `RebuildJob::ensure_watchdog()` desde `init:21`, memoizado con el transient `akb_search_watchdog_guard_v2` (900 s; 12 h hasta search 0.1.67) | Consume publicaciones pendientes, recupera arriendos vencidos, despierta deuda live, reconcilia fase y topología, intenta levantar el serving y manda la alarma a los 900 s caído | Inofensivo: cada paso es idempotente y todo lo peligroso va bajo el `WriteFence`. El correo no se duplica porque `notified_at` marca el episodio |
| `akb_search_reconcile` | Action Scheduler, recurrente | **cada 24 horas**; la hora es **heredada** del primer agendado (`time() + HOUR_IN_SECONDS`, `src/Reconciler.php:115`) — medida en producción alrededor de las **12:50** de Chile | `Reconciler::ensure_scheduled()` desde `init:20`, sólo con la indexación encendida; memoizado con `akb_search_reconciler_scheduled` (3.600 s; 12 h hasta search 0.1.67) | Cuatro detectores, muestreo de 10 documentos, poda del qlog, siembra de hasta 200 preventas antiguas y sellado del resumen | Inofensivo: los detectores son lecturas, las reparaciones se encolan como deuda deduplicada por `(scope, run_id, product_id)`, y la poda es un DELETE por rango |
| `akb_search_rebuild_batch` | Action Scheduler, asíncrona | mientras el run tenga deuda; lotes de 200 tareas con 20 s de presupuesto de reloj por invocación | `RebuildJob::enqueue_wakeup()` | Bombea un lote del ámbito `baseline` o `repair` | Inofensivo: una acción que no corresponde al run vigente no hace nada, y los claims son arriendos exclusivos |
| `akb_search_pump_live` | Action Scheduler, asíncrona | en cuanto hay deuda; deduplicada contra acciones **pendientes**, no contra las que ya están corriendo | `Indexer::wake_live_work()` | Drena hasta 25 tareas live | Inofensivo, y la deduplicación es deliberadamente laxa: una acción en curso ya tomó su foto y no puede tapar un cambio nuevo |
| `akb_search_pump_live` (con barrera) | Action Scheduler, asíncrona | a **ahora + 10 + 2 segundos**, una acción pendiente como máximo por tipo de barrera | `Indexer::wake_live_work_after()` | Despierta después de la barrera pre-escritura | Igual que la anterior |
| `akb_search_editorial_discount_boundary` | Action Scheduler, **acción única** | en el segundo exacto del próximo borde: la medianoche de Santiago del día siguiente al último día de la campaña | `EditorialDiscountPublication::schedule_boundary()`, al cerrar cualquier marco y al final de cada reconciliación | Reconcilia para que la campaña se apague o se encienda sola | Inofensivo: `handle_boundary()` comprueba que la secuencia, la huella y el instante siguen coincidiendo con el estado, y si algo no cuadra no hace nada |
| `akb_search_reindex_product` / `akb_search_reindex_term` | Action Scheduler, asíncrona | a demanda | `Indexer` | Reindexado puntual de un producto o de un término por lotes | Inofensivo: misma deuda durable |

Dos memos, y el hueco que dejaban: `akb_search_watchdog_guard_v2` y `akb_search_reconciler_scheduled` se sellan **en cuanto se comprueba que la acción existe**. Un memo positivo no es una prueba de vida: si la acción recurrente desaparece justo después —una limpieza de la tabla de Action Scheduler, un `wp db` de mantenimiento, una restauración parcial—, nadie vuelve a mirar hasta que el memo caduque. **Hasta search 0.1.67 los dos duraban 12 horas**, o sea medio día sin vigilante —sin rescate de un rebuild colgado y sin alarma, que vive dentro del vigilante— y sin revisión diaria. **Desde S-3e1f3f03 (2026-09-19; versión siguiente a 0.1.67)** la vida la decide `RecurringMemo::ttl()` (`src/Support/RecurringMemo.php`): nunca más que el intervalo de la acción que protege, con techo de una hora. El vigilante se re-comprueba cada 900 s y la revisión diaria cada 3.600 s, así que una ausencia se descubre, como mucho, una vuelta tarde. Cuesta 96 comprobaciones al día en vez de 2 para el vigilante y 24 en vez de 2 para la revisión, de dos consultas indexadas cada una. El gancho `action_scheduler_ensure_recurring_actions` cubre parte del caso por su lado, pero sólo si la versión de Action Scheduler lo soporta y sólo cuando ese gancho corre.

**Cómo se comprueba que el vigilante existe, y cómo se le obliga a volver.** Nada de esto se ve en el panel del buscador:

1. **Mirar la cola.** En el escritorio de WordPress: **WooCommerce → Estado → Acciones programadas**, y filtrar por el grupo `akibara-search`. Ahí están, con su próxima fecha, `akb_search_rebuild_watchdog` (debe aparecer como pendiente, a menos de 15 minutos vista) y `akb_search_reconcile` (pendiente, a menos de 24 horas). Por terminal, lo mismo:

   ```bash
   wp action-scheduler list --group=akibara-search --status=pending
   ```

2. **Si falta alguna, romper el memo.** Los dos memos son transients, y borrarlos obliga a re-comprobar en el siguiente arranque de WordPress:

   ```bash
   wp transient delete akb_search_watchdog_guard_v2
   wp transient delete akb_search_reconciler_scheduled
   ```

   Después basta con cargar cualquier página del sitio (o esperar al empujón de Hostinger, que llega en 5 minutos como mucho): `register_recurring_guard()` en `init:21` y `Reconciler::ensure_scheduled()` en `init:20` vuelven a agendar lo que falte. Borrarlos sin necesidad no rompe nada: el peor caso es una consulta de más a Action Scheduler.

---

## Casos de uso

Los once casos de abajo están ordenados por historia: qué pasa, en qué orden, desde que alguien toca algo. Pero cuando algo **ya se rompió** la pregunta es otra, así que primero la tabla de entrada rápida:

| Lo que se ve | Qué suele ser | Dónde mirar primero | A qué caso ir |
|---|---|---|---|
| El desplegable de búsqueda no aparece, o devuelve 503 | el gate está cerrado: el recibo no cuadra con el estado | Buscador → Índice → **Servicios**, que dice el estado efectivo **y su causa**; o `wp option get akb_search_editorial_discount_state --format=json` | casos **2**, **5** y **10** |
| El catálogo salió sin ningún filtro | lo mismo: filtros y buscador se apagan juntos | igual que arriba | casos **2** y **5** |
| El panel dice «La reconstrucción quedó bloqueada» y no hay botón | el pestillo `BLOCKED`; nada del plugin lo deshace | `wp option get akb_search_rebuild_state --format=json` | caso **10** |
| Llegó el correo «el buscador lleva rato caído» | el vigilante lleva 900 s viendo el serving abajo | el correo pide `done` y `work_blocked`, que **no** están en `wp akb-search status`: mirar el panel o la option del estado | casos **5** y **10** |
| Guardé un término y toda la tienda se puso lenta / el buscador cayó | guardar cualquier término dispara un rebuild completo, sin condición | Buscador → Índice → **Estado**, fila de solicitud pendiente | casos **1** y **9** |
| Cambié el stock de un tomo y el buscador no se enteró | o hay deuda trabada, o el gate editorial está cerrado y cada intento quema un reintento | `wp db query "SELECT status, COUNT(*) FROM wp_akb_search_work GROUP BY status;"` | caso **4** |
| Acabo de restaurar una copia / es un entorno nuevo | falta encender y sellar | `wp option get akb_search_db_version` | caso **12** |

### 1. El dueño guarda una editorial y el buscador se reconstruye entero

![Guardar un término y reconstruir el índice](diagramas/08-search-indice-y-rebuild-caso-guardar-termino.mmd)

El dueño abre «Ivrea Argentina» en el panel de WordPress para cambiar una term-meta —la descripción de la landing, digamos— y pulsa «Actualizar». El formulario reenvía siempre nombre y slug, aunque no los haya tocado.

1. WordPress dispara `edit_terms` **antes** del UPDATE. `Indexer::on_pre_edit_term` pregunta si el cambio toca nombre, slug o padre (`term_edit_touches_index`, `src/Indexer.php:1298`). No los toca → **no pide rebuild**. Hasta aquí, todo como se prometió.
2. WordPress escribe y dispara `edited_term`. `Indexer::on_edited_term` llama a `enqueue_term()`.
3. `enqueue_term()` llama a `Install::request_rebuild('term_fanout')` **sin ninguna condición** (`src/Indexer.php:1945`). Se persiste una solicitud de rebuild completo con `not_before` = ahora.
4. `enqueue_term()` resuelve además todos los productos del término y de sus subcategorías y los apunta como deuda live, en lotes de 100.
5. En el siguiente arranque de WordPress —la propia redirección del panel sirve—, `init:20` ejecuta `Install::consume_pending_rebuild()` y crea el run durable.
6. `PREPARING` → `BUILDING` → `CATCHING_UP`: el cliente **sigue buscando**, el índice vivo no se ha tocado.
7. `SWAP_INTENT`: se escribe `generation_target` y **el gate se cierra**. `RENAME TABLE`.
8. `PUBLISHING`: seis confirmaciones. `CLEANUP`: se borran las `_old`. `DONE`: se borra el estado y **el gate se reabre**.

**Bifurcaciones.** Si el término no pertenece a una taxonomía indexada, no pasa nada. Si ya hay un run vivo, la solicitud se junta en la cola —hasta cuatro— en vez de arrancar otro. Si Action Scheduler no está disponible, la señal queda persistida y la recoge el siguiente `init` o el vigilante.

**Correos:** ninguno.
**Lo que ve el cliente:** si buscaba justo en el tramo de publicación, el desplegable no responde y el catálogo pierde los filtros.

### 2. El dueño pone −10 % a Ivrea

![Descuento editorial y cierre del gate](diagramas/08-search-indice-y-rebuild-caso-descuento-editorial.mmd)

1. `update_term_metadata` → `filter_update_term_metadata` (prioridad `PHP_INT_MAX`).
2. `stage_term()` toma el `WriteFence`. **Si no lo consigue, devuelve `false` y WordPress no escribe la meta**: la operación se cancela limpiamente en vez de dejar el índice mintiendo.
3. Foto doble de la fuente → huella SHA-256.
4. Se resuelven todos los productos del término, con `include_children`.
5. `stage_locked()` (`src/EditorialDiscountPublication.php:2037`): estado con `wanted_seq` +1 → deuda live **y** repair con 10 s de barrera → solicitud de rebuild → `debt_staged = true` → purga PRE (`litespeed_purge` + `litespeed_purge_all`).
6. **Desde este punto, y no desde el cambiazo, el buscador está apagado.** El recibo sigue en la secuencia anterior y `bundle_is_ready()` exige que estado y recibo coincidan (`:2508`, `:2545`).
7. WordPress escribe la termmeta.
8. `updated_term_meta` cierra el marco: re-toma la foto, sella `purge_post_seq`, suelta el cerrojo, despierta el pump y programa la acción de borde.
9. El rebuild publica, deja el recibo con la secuencia nueva, dispara `litespeed_purge_all` y el gate se reabre.

**Bifurcación importante:** si el cambio **no mueve ningún precio** —por ejemplo, ningún término tenía porcentaje—, el atajo del re-probado lo detecta comparando los 1.568 productos uno a uno, re-sella el recibo y **retira la señal de rebuild**. Cero reconstrucción. Si hay trabajo live en vuelo para un producto divergente, se aplaza hasta 600 s antes de caer al rebuild. Y si la lectura de la fuente deja de ser autoritativa en cualquier punto, todo falla cerrado.

**Bifurcación que el mapa no contaba:** si la escritura entra por `add_option` o `delete_option` y no hubo cerrojo, no se puede vetar; se deja la entrada `option_editorial_sin_cercar` en el `AuditLog` **y se borra la marca del estrangulador**, para que la petición siguiente reconcilie sin esperar los 60 segundos.

**Correos:** ninguno.

### 3. Una campaña se apaga sola a medianoche

Programada como acción única con `(seq, huella, instante)` como argumentos. `handle_boundary()` comprueba que los tres siguen coincidiendo con el estado y que la hora ya pasó; si algo no cuadra, no hace nada. Luego reconcilia: `window_active` cambió → la huella ya no coincide → deuda (`editorial_source_drift`) + purga + rebuild, y el índice se reescribe sin descuento.

Desde **12 horas antes** del borde, `cap_page_cache_ttl()` deja de autorizar guardar páginas, para que ninguna copia lo sobreviva. El propio guion de rescate avisa de que un rescate puede dejar la acción de borde sin coincidir, y entonces **nadie apaga la promo**: hay que comprobarlo a mano.

**Correos:** ninguno.

### 4. Llega stock de un tomo

![Reindexado incremental por stock](diagramas/08-search-indice-y-rebuild-caso-stock-incremental.mmd)

`woocommerce_product_set_stock_status` tiene dos oyentes: `Arrival` (prioridad 10, anota la llegada real si venía de preventa) e `Indexer` (prioridad 12). Deuda en `work`, despertador encolado, pump que reclama hasta 25 tareas, dos transacciones con la publicación de generación en medio, y al final `reconcile_now()` para que el recibo editorial vuelva a cuadrar con el nuevo conteo de documentos — sin eso, **cada alta o baja de producto dejaba el buscador cerrado** hasta la reconciliación siguiente.

**Bifurcaciones:** si el producto ya no está publicado, se borran sus filas. Si la tabla dice «publicado» y el objeto de WooCommerce dice otra cosa, se conserva la deuda y se reintenta: dos fotos incompatibles no son prueba de ausencia. Ocho intentos fallidos → `blocked`, y el panel lo dice.

**La tercera bifurcación, que es la más probable de las tres: el gate editorial cerrado.** Antes de construir nada, `stage_live_claim()` pide la foto de precio, y si no la consigue **lanza** y el trabajo se reintenta. Mientras el gate esté cerrado —o sea, mientras el buscador esté en 503 por deuda editorial— este camino no repara nada: **cada vuelta gasta un reintento**, con espera creciente, y al octavo el producto queda `blocked`. La promesa de «segundos» de abajo vale con el gate abierto, que es lo normal; con el gate cerrado, lo que hay son reintentos, y cuando el episodio termine habrá que mirar el contador de trabadas.

**Correos:** ninguno.
**Lo que ve el cliente:** con el gate abierto, el sello de stock y el precio correctos en segundos, sin que nada se apague. Con el gate cerrado, el cliente no ve nada de nada —ni buscador ni filtros—, y el cambio de stock espera su turno.

### 5. El vigilante avisa al dueño

![El vigilante y los dos correos](diagramas/08-search-indice-y-rebuild-caso-watchdog-alarma.mmd)

Cada 15 minutos: consume publicaciones pendientes → recupera arriendos vencidos → despierta deuda live → reconcilia fase y topología → **reanuda el run si hay uno vivo** → y, en **todas** las vueltas, mira el serving.

Qué hace al mirarlo depende del estado del rebuild, y son tres casos (S-190efeef y S-8ab53cfb, 2026-09-19; versión siguiente a search 0.1.67, y el diagrama de arriba ya los dibuja — hasta 0.1.67 eran dos ramas, la del run vivo no medía nada y la del pestillo pedía un rebuild imposible):

- **Sin estado:** primero la reparación barata (`reconcile_now()`); y si al medir sigue caído, pide rebuild con motivo `serving_recovery`, lo consume en el acto y deja rastro en el `AuditLog`.
- **`BLOCKED` / `ABORTED`:** la reparación barata y nada más. No se pide un rebuild que nadie podría consumir.
- **Run vivo:** sólo se mide. Lo que levanta el serving es que el run termine; pedir otro por encima sería pisarlo.

La medición es la misma en los tres, con bytes frescos y **después** de reanudar y de reconciliar (`serving_ready_fresh()`): si sirve, `note_serving_up()`; si no, `note_serving_down()`, que arranca un reloj.

**Correo 1 — «Akibara: el buscador lleva rato caído».** Al `admin_email`, texto plano, cuando lleva **900 s** caído y todavía no se avisó. Sale desde la acción del vigilante, o sea con la cola de Action Scheduler; en producción el cron externo de Hostinger la empuja cada 5 minutos, así que en el peor caso el correo llega unos minutos después de cumplirse el umbral. El cuerpo dice qué ve el cliente, que el sistema ya intentó recuperarse solo, y da dos órdenes en orden: `wp akb-search status` y `wp akb-search rebuild`, con la pista de que si dice «hay uno en curso» hay que mirar que `done` avance y `work_blocked` esté en cero.

**Correo 2 — «Akibara: el buscador volvió».** Al `admin_email`, sólo si antes se mandó el de caída, en el ciclo en que se detecta la recuperación.

**Bifurcaciones honestas, medidas contra el código:**

- Si el autocompletado está apagado, no es una caída: es una decisión del dueño, y no se mide nada. Hasta search 0.1.67 eso dejaba un agujero: apagar el interruptor **a mitad de un episodio ya avisado** dejaba `notified_at` puesto para siempre, y al volver a encender la siguiente caída real no mandaba correo. Desde S-e52a233e (2026-09-19; versión siguiente a 0.1.67) el apagado **cierra el episodio** (`note_lane_off()`), sin correo de «volvió» —el buscador no volvió, lo apagaron— y con una línea en el cuaderno que explica por qué ese correo de caída no tiene pareja. Con el **esquema sin sellar** no se mide ni se cierra nada: para el cliente el buscador sigue caído, y el «volvió» tiene que poder salir cuando vuelva.
- Si el run se cuelga en una fase **no terminal** —`PUBLISHING`, `CLEANUP`, `SWAP_INTENT`, `SWAPPED`—, el gate está cerrado y el cliente no tiene buscador ni filtros. **Hasta search 0.1.67 eso no alarmaba nunca:** `maybe_recover_serving()` no se llamaba con un run vivo y el vigilante se limitaba a `resume_run()` en bucle (alarmaban sólo los fallos que terminan en `block_locked()`; no los que dejan un estado recuperable o salen sin escribir estado: `publish_schema_recheck`, `cleanup_work_debt_unresolved`, una confirmación que nunca se cumple). Desde S-190efeef el reloj del episodio corre igual con un run vivo y el correo sale a los 900 s. Un rebuild **sano** no lo dispara: construye en tablas sombra con el serving arriba, y su corte de publicación dura segundos.
- Con el pestillo `BLOCKED` puesto, hasta search 0.1.67 el vigilante pedía cada 15 minutos un rebuild que nadie podía consumir y escribía cada vez la entrada `serving_recovery`. Desde S-8ab53cfb ya no: ni señal ni entrada.

**Lo que NO pasa, aunque a las tres de la mañana uno se lo invente:** un buscador caído **no** purga Cloudflare entero cada 15 minutos. La purga total sale de `record_swap_receipt()`, que sólo corre si un rebuild **llega a publicar**; con `BLOCKED` puesto el rebuild ni siquiera arranca, porque `schedule_request()` devuelve cadena vacía mientras exista la fila de estado (`src/RebuildJob.php:271-274`). Y la otra purga posible, `ensure_purge_for_state()`, es idempotente por `purge_pre_seq` / `purge_post_seq`, así que tampoco se repite por ciclo. Hasta search 0.1.67 lo único que sí se repetía cada 15 minutos era una entrada en el cuaderno y una señal de rebuild que nadie consumía; desde S-8ab53cfb, ni eso: bajo un pestillo el vigilante sólo reintenta la reparación barata y lleva la cuenta del aviso.

### 6. La verificación diaria

Esquema sellado + foto de precio adoptada → cuatro detectores (100 identificadores cada uno) → muestreo de 10 → poda del qlog (180 días, tope 50.000 filas) → encolar los divergentes como deuda live → sembrar hasta 200 preventas antiguas → resumen en `akb_search_reconcile_last` y alerta de 2 días para el panel.

**Bifurcación de entrada:** si el esquema no está sellado, o si `capture_price_authority()` no devuelve una foto, la corrida **no empieza**: se sella `runtime_schema_not_ready` o `price_authority_capture_failed` en `akb_search_reconcile_last_error` y se sale (`src/Reconciler.php:142-149`). Es decir: mientras el buscador está caído por deuda editorial, la revisión diaria no corre, y el panel muestra además un `reconciler_fallido` que apunta a otra parte.

**Correos:** ninguno.

### 7. Despliegue con cambio de esquema

![Despliegue con cambio de esquema, paso a paso](diagramas/08-search-indice-y-rebuild-caso-migracion-esquema.mmd)

`maybe_install()` en cada arranque. Si la versión guardada difiere de `DB_VERSION`: `WriteFence` + lock propio de la migración (una sola petición migra) → **precondiciones**: topología limpia, porque un rebuild vivo con sombras impide migrar, para que una sombra de esquema viejo no se swapee encima de tablas ya migradas → `dbDelta` + InnoDB + collation binaria + sustitución de `idx_post` por `idx_post_token` en una sola sentencia + `backfill_sku_norm()` → **señal de rebuild con 300 s de gracia** → y sólo entonces se sellan las dos options, con relectura de comprobación.

Los 300 segundos son para que terminen las peticiones que cargaron el runtime anterior durante un despliegue rolling.

**Bifurcaciones:** si cualquier verificación falla, la versión **no** se sella y la petición siguiente reintenta, porque la migración es idempotente. Si `backfill_sku_norm()` falla, `install()` devuelve `false` y no se sella nada. Y hay un camino que sorprende: **si la versión guardada es exactamente `DB_VERSION`, también se pide rebuild completo** (`src/Install.php:683`), de modo que una deriva del sello reconstruye el índice entero sin que nadie lo haya pedido.

**Correos:** ninguno.
**Lo que ve el cliente:** mientras el esquema no esté sellado, las rutas del buscador devuelven 503 en vez de 404.

### 8. El dueño pulsa «Reconstruir índice» en el panel

Nonce + capacidad `manage_woocommerce` → `RebuildJob::schedule($force)`. Si devuelve `true`, se deja rastro en el `AuditLog` y se redirige con el aviso «encolado». Si devuelve `false`, el panel **distingue los dos motivos**: hay un run vivo (basta esperar) o el estado quedó en fase terminal (bloqueado). En el segundo caso ni siquiera pinta el botón, y muestra el puntero a `ops/rescate-buscador.sh`, porque el botón que había antes no hacía nada y el operador quedaba dando vueltas.

**Correos:** ninguno.

### 9. Se borra una categoría padre

`pre_delete_term` pide rebuild y captura los hijos **directos** antes de que WordPress los recoloque bajo el abuelo; en `delete_term` esa jerarquía ya no existe. `delete_term` entrega los productos con relación directa, se juntan con el subárbol capturado y se apuntan como deuda live. Si algo no se pudo leer, se pide rebuild igual y se deja rastro (`index_term_debt_failed`).

Como red de seguridad, la revisión diaria busca filas de `facet_index` con `term_id` inexistente: borrar un término no mueve `post_modified_gmt`, así que el detector de obsoletos es ciego a esa corrupción y el muestreo aleatorio sólo mira `docs`.

**Correos:** ninguno.
**Lo que ve el cliente:** los filtros dejan de ofrecer categorías que ya no existen.

### 10. El buscador se atasca y hay que rescatarlo a mano

![El rescate manual, paso a paso](diagramas/08-search-indice-y-rebuild-caso-rescate.mmd)

El panel dice «La reconstrucción quedó bloqueada (`orphan_topology_without_state`)» y no hay botón de reintento. `wp akb-search rebuild` —el **síncrono**, sin `--async`— responde `Ya hay un rebuild en curso (option akb_search_rebuild_state).` con código de salida distinto de cero, aunque la option parezca vacía; y `reconcile` dice «ok» sin mirar que el índice va por detrás.

**Antes de tocar nada, mirar.** Tres órdenes, en este orden, que no cambian nada:

```bash
wp option get akb_search_rebuild_state --format=json     # ¿qué fase? ¿avanza «done»? ¿«work_blocked» en cero?
wp option get akb_search_pending_rebuild --format=json   # ¿hay solicitudes encoladas sin consumir?
wp db query "SHOW TABLES LIKE 'wp_akb_search_%';"        # ¿existen tablas _new? ¿y _old?
```

Si aparecen tablas **`_old`**, el rescate automático **no se usa**: ahí el cambiazo ya ocurrió y `_old` es la única marcha atrás que existe. El guion para solo en ese caso, y hace bien.

**El rescate, primero en el espejo.** El guion acepta los dos destinos y hace exactamente lo mismo en los dos:

```bash
bash ops/rescate-buscador.sh espejo        # ensayo en el Docker local
bash ops/rescate-buscador.sh produccion    # pide escribir SI
```

Lee la topología por SQL puro, **para** si hay tablas `_old`, suelta las `_new` primero, borra `akb_search_rebuild_state` sólo si las `_new` desaparecieron, limpia los pumps encolados y lanza un rebuild limpio. Y recuerda comprobar que sigue habiendo una acción de borde que apague la promoción.

El estado de reposo sano es **option ausente + topología `clean_pre`**. Escribir `PHASE_ABORTED` deja el mismo pestillo con otro nombre. Si `drop_shadow_tables()` falla, probablemente alguien tiene el `WriteFence` tomado: reintentar.

**Correos:** el de «lleva rato caído» ya habrá salido, si el episodio pasó de 900 s; el de «volvió» sale cuando el rescate funciona.
**Tiempo real medido del incidente sin este guion:** unas 3 horas de tienda sin buscador ni filtros.

### 11. Se elimina el plugin

Desactivar no borra nada. «Eliminar» ejecuta `uninstall.php`: 13 tablas, todas las acciones del grupo `akibara-search` (pasando hook vacío, para que también caigan las de versiones futuras), y las options y transients de **cuatro** familias de prefijos exactos, nunca `akb_%`. Si WooCommerce ya no está activo, `as_unschedule_all_actions()` no existe y las acciones quedan huérfanas pero inertes: su hook ya no tiene callback.

**Correos:** ninguno.

### 12. Primer arranque: entorno nuevo, o copia recién restaurada

Es el caso que más se necesita y el que no tiene botón. La option `akb_search_indexing` viene por defecto en **`no`**, así que un sitio recién levantado tiene el plugin instalado y **no escuchando nada**. Los pasos, en orden, y qué se comprueba después de cada uno:

1. **Activar el plugin.** La activación llama a `akibara_search_activate()` (`akibara-search.php:222`): crea o migra las cinco tablas y siembra los tramos de precio. Comprobar que el esquema quedó sellado —las dos options tienen que existir **y** tener estos valores exactos:

   ```bash
   wp option get akb_search_db_version      # → 1.9.0
   wp option get akb_search_schema_seal     # → 1.7.0-exact-1
   ```

   Si alguna falta o no coincide, la migración no llegó al final. Cargar cualquier página del sitio y volver a mirar: la migración es idempotente y la petición siguiente reintenta sola. Si tras varios intentos sigue sin sellarse, el error está en el log de PHP y **no hay que seguir**: sin sello, el buscador responde 503 y ningún escritor de tablas se cablea.

2. **Encender «Indexación automática»** en Buscador → Ajustes → «Servicios de la tienda». Eso registra los 25 oyentes y agenda la revisión diaria. Los otros dos interruptores —«Autocompletado» y «Filtros»— se pueden dejar apagados hasta que el índice esté lleno; de hecho conviene, para que nadie vea un buscador vacío.

3. **Llenar el índice a mano**, sin esperar a que nadie toque un producto:

   ```bash
   wp akb-search rebuild
   ```

   Va en síncrono: no espera barreras temporales y termina con un `Índice reconstruido: N docs en X s.`. Si termina con error, el mensaje dice cuál (el más común es que alguien publicara un producto durante la ventana; véase la ficha de `Cli`).

4. **Comprobar que hay algo dentro** y que los números cuadran:

   ```bash
   wp akb-search status
   ```

   `docs` debe acercarse al número de productos publicados. Cero documentos con el esquema sellado es el aviso de «índice vacío» que `HealthReport` pinta en rojo, y significa que el rebuild no llegó a publicar.

5. **Encender «Autocompletado» y «Filtros»**, y mirar la banda de salud: tiene que decir **sano**.

6. **Comprobar que lo programado existe**: WooCommerce → Estado → Acciones programadas, grupo `akibara-search`, con `akb_search_rebuild_watchdog` y `akb_search_reconcile` pendientes. Si falta alguna, borrar los dos memos (véase «Lo programado»): desde S-3e1f3f03 caducan solos en 15 minutos y una hora, pero borrarlos ahorra la espera; hasta search 0.1.67 duraban 12 horas y borrarlos era la única forma.

**Un aviso para la copia restaurada:** si el volcado viene de producción, trae también las options de estado. Antes de encender nada conviene mirar `akb_search_rebuild_state`: si llegó una fila con una fase a medias, el sitio nuevo arranca con un pestillo heredado que no corresponde a ninguna reconstrucción real. Ahí el camino es el caso 10, en el espejo.

**Correos:** ninguno, mientras el entorno no esté marcado como `production`.

---

## Reglas de negocio

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
|---|---|---|---|
| Entran al índice sólo `post_type='product'` y `post_status='publish'`; las variaciones no tienen documento propio, enriquecen el del padre | `src/RebuildJob.php:406` | no | — |
| Orden de disponibilidad: 0 disponible, 1 a pedido/preventa, 2 agotado; ascendente, o sea lo disponible primero | `src/DocumentBuilder.php:80` | no | — |
| Una preventa **nunca** cuenta como «novedad»: novedad = llegó a tienda, no = se abrió un encargo | `src/DocumentBuilder.php:546` | no | revocable borrando el guard y subiendo `DB_VERSION`, lo que fuerza rebuild |
| Un producto con precio efectivo 0 no genera fila en el filtro de precio | `src/DocumentBuilder.php:533` | no | — |
| Escalones de oferta 10/20/30/50 %, sobre el precio **efectivo** contra el regular, acumulativos | `src/FacetRegistry.php:413`, `src/DocumentBuilder.php:664` | no | — |
| El porcentaje de oferta se redondea (`round()`), así que un 9,5 % aparece bajo «10 % o más» | `src/DocumentBuilder.php:669` | no | — |
| `onbackorder` se llama «A pedido»; sólo es «Preventa» si business responde exactamente `preventa` | `src/StockLabels.php:173` | **sí** | akibara-business (`SearchAvailabilityBridge`) y el filtro `akb_stock_status_label` |
| Estado de stock desconocido → «Agotado». Nunca se promete disponibilidad no verificada | `src/StockLabels.php:105` | no | — |
| El qlog es agregado por día **UTC** (Chile va 3-4 h por detrás); retención 180 días, tope 50.000 filas | `src/QueryLogger.php:91`, `src/Reconciler.php:63`, `:66` | no | — |
| El cambiazo exige los cuatro conteos en cero **y** cobertura exacta: 0 faltantes, 0 sobrantes, 0 huérfanos | `src/RebuildJob.php:1541`, `:2507` | no | — |
| Buscador y filtros se apagan si la huella viva, la del estado y la del recibo no coinciden, o si el conteo físico de documentos no casa con el recibo | `src/EditorialDiscountPublication.php:2508-2552`, `src/Search.php:322` | no | — |
| Ninguna respuesta REST del buscador es almacenable: `no-store` + las dos cabeceras de CDN | `src/Support/RestCache.php:105` | no | — |
| Una página se cachea sólo si es pública, GET/HEAD, sin query string, sin sesión, sin cookies privadas, no carrito/checkout/cuenta, con lease resuelta, ventana cerrada y borde a más de 12 h | `src/EditorialDiscountPublication.php:567`, `:636` | no | — |
| TTL máximo 3600 s en listados y portada; la ficha de producto, hasta la medianoche de la tienda menos 60 s (0.1.65); horizonte ciego 43200 s. Invariante: horizonte > TTL de listados, y ficha ≤ borde | `src/EditorialDiscountPublication.php` (`PAGE_CACHE_TTL`, `PRODUCT_PAGE_MIDNIGHT_MARGIN`, `PAGE_CACHE_BOUNDARY_HORIZON`) | no | — |
| «Ocultar agotados» de WooCommerce → rebuild completo | `src/Indexer.php:1721` | no | — |
| Guardar un término dispara rebuild completo. El guard de «no toca nombre, slug ni padre» sólo actúa en `edit_terms`; `edited_term` pide el rebuild sin condición | `src/Indexer.php:1267`, `:1298`, `:1945` | no | — |
| Reintentos 5/10/20 s… tope 1 h; al **octavo** intento la tarea queda `blocked` y observable | `src/WorkRepository.php:41`, `:651` | no | — |
| Rebuild «detenido» a los 300 s sin latido **y sin ningún lease vigente** (desde 0.1.68: el latido se toca una vez por pasada de 200, y un run lento no es un run muerto — `RebuildJob::stalled_verdict()`); cola muerta a los 1800 s; revisión diaria muda a las 48 h | `src/RebuildJob.php:85`, `src/HealthReport.php:35`, `:32` | no | — |
| Aviso por correo a los 900 s caído, **uno solo** por episodio, sólo en producción | `src/RebuildJob.php:738`, `:768`, `:828` | no | — |
| El borde de una ventana es la medianoche de Santiago del día siguiente, calculada **por fecha** | `src/EditorialDiscountPublication.php:3169` | no | — |
| Porcentaje de descuento editorial: entero canónico 1..90, sin signo ni ceros a la izquierda | `src/EditorialDiscountPublication.php:3330` | **sí** | akibara-business (`Utilities\DiscountCeiling`), option `akb_discount_max_pct` |
| Se conservan los **20** recibos de publicación más recientes (operativamente hasta 21) y la poda escanea como mucho 500 filas; el vigente nunca se poda | `src/EditorialDiscountPublication.php:85`, `:88`, `:3686` | no | — |
| El desinstalador filtra por cuatro prefijos exactos, jamás por `akb_%` | `uninstall.php:81` | no | — |
| Intocables por cualquier migración o rebuild: la tabla `qlog`, las options de curación del operador y las postmeta de llegadas | `src/Install.php:30`, `src/Arrival.php:20` | no | — |
| Aviso de capacidad a los 5.000 documentos visibles; es vigilancia, no límite | `src/HealthReport.php:57` | no | — |
| El panel exige `manage_woocommerce` + nonce en cada guardado y cada botón | `src/SearchAdminSection.php:1779` | no | — |
| Una migración de esquema pide rebuild también cuando la versión **no** cambió, con 300 s de gracia | `src/Install.php:683`, `:82` | no | — |
| El intervalo del vigilante (900 s), el tope de aplazamiento (600 s), el umbral de la alarma (900 s) y el tamaño del muestreo (10) son **constantes de clase**: ninguna option invisible las mueve, pero tampoco hay pantalla | `src/RebuildJob.php:78` (intervalo), `:738` (alarma); `src/Reconciler.php:57` (muestreo) | no | sólo con un despliegue |
| Tres **filtros** permiten a otro plugin cambiar el estrangulador editorial, el techo del porcentaje y qué taxonomías llevan descuento. Hoy no los usa nadie | `src/EditorialDiscountPublication.php:328`, `:2669`, `:2955` | **sí, por código** | no hay pantalla: sólo enganchándose desde otro plugin |
| El correo de alarma manda mirar `done` y `work_blocked` después de `wp akb-search status`, y ese comando **no los imprime** | `src/RebuildJob.php:775-792` contra `src/Cli.php:140-162` | — | deuda conocida: están en el panel y en `akb_search_rebuild_state` |

---

## Datos que deja

**Tablas** (todas con el prefijo real del sitio; aquí se escriben con `wp_`):

| Tabla | Qué guarda | Participa del cambiazo |
|---|---|---|
| `wp_akb_search_docs` | Una fila por producto: `post_id`, `title`, `title_norm`, `serie_norm`, `autores_norm`, `sku`, `sku_norm`, `tomo`, `stock_rank`, `menu_order`, `price_effective`, `visible`, `url`, `thumb_url`, `price_html`, `indexed_at`. Índices `idx_visible_stock`, `idx_sku`, `idx_sku_norm` | sí |
| `wp_akb_search_tokens` | Una fila por (palabra, campo, producto). `token VARCHAR(40)` con collation **binaria** a propósito; `field` 1=título 2=serie 3=autor 4=SKU 5=taxonomía 6=descripción. Sin FULLTEXT: el hosting no deja bajar `min_token_size` de 3 y se perderían los números de tomo | sí |
| `wp_akb_search_facet_index` | Espejo de filtros: `(facet, post_id, value)` con `label`, `value_num`, `term_id` y `parent_id`; los ancestros están materializados | sí |
| `wp_akb_search_qlog` | Búsquedas **agregadas** por (día UTC, consulta normalizada, origen) con `hits` y `searches`. Nunca una fila por evento | **no**: es fuente, no derivado |
| `wp_akb_search_work` | La deuda durable. `(scope, run_id, product_id)` único; `scope` = live / baseline / repair; `wanted_seq`, `acked_seq`, `status`, `available_at`, `lease_token`, `lease_seq`, `lease_until`, `attempts`, `last_error` | **no**: siempre viva |
| `wp_akb_search_{docs,tokens,facet_index}_new` | Las gemelas sombra. Existen sólo entre `PREPARING` y el RENAME | — |
| `wp_akb_search_{docs,tokens,facet_index}_old` | Las vivas anteriores, apartadas por el RENAME. Son la **única** vuelta atrás: no hay rename inverso | — |

**Options:**

| Option | Qué guarda | Dónde se edita |
|---|---|---|
| `akb_search_db_version` | `1.9.0` | sin pantalla; sólo la escribe la migración. Borrarla a mano es la reparación documentada del panel |
| `akb_search_schema_seal` | `1.7.0-exact-1` | sin pantalla |
| `akb_search_pending_rebuild` | Cola de hasta **4** solicitudes de reconstrucción | sin pantalla; el panel la muestra en «Índice» |
| `akb_search_generation_outbox` | Buzón de publicación de **generación** (el número de serie del índice) | sin pantalla |
| `akb_search_index_gen` | La generación viva; cambiarla dispara la purga de la caché REST | sin pantalla |
| `akb_search_rebuild_state` | La verdad del cambiazo: fase, run, huellas y seis banderas de confirmación | sin pantalla; el panel la muestra, y `ops/rescate-buscador.sh` la borra a mano como último recurso |

**Los campos que uno mira cuando algo falla.** Estas tres options son las que hay que abrir de madrugada, y sus nombres no se explican solos. Una línea por campo:

| Campo | Dónde aparece | Qué quiere decir |
|---|---|---|
| `token` | solicitud y buzón | el nombre propio de esa anotación, para no confundirla con otra |
| `seq` / `wanted_seq` | solicitud, buzón, estado editorial | «lo que se quiere que esté publicado», un contador que sube en uno con cada cambio |
| `acked_seq` | buzón, tabla `work` | «lo que efectivamente se confirmó». Si va por detrás de `wanted_seq`, queda trabajo |
| `version` | solicitud | la versión de esquema con la que se pidió. Si ya no es la vigente, la solicitud caducó |
| `reason` | solicitud | por qué se pidió: `term_fanout`, `schema_migration`, `serving_recovery`, `option_update_…` |
| `requested_at` | solicitud | cuándo se pidió |
| `not_before` | solicitud y buzón | **«no empieces antes de este instante»**. Es la gracia de 300 s de un despliegue, y es lo que hace que el carril asíncrono diga «ya hay uno en curso» cuando no lo hay |
| `barriers` | buzón | las esperas concretas que todavía no vencen; cuando se vacía, `not_before` vuelve a 0 |
| `protocol` | buzón y estado | el número de formato del propio dato. Si no coincide con el que el código espera, el dato es de una versión vieja y se descarta en vez de interpretarse mal |
| `phase` | estado del rebuild | en qué punto del viaje va: `PREPARING`, `BUILDING`, … `DONE`, `BLOCKED` |
| `expected` | estado del rebuild | cuántos documentos tiene que haber al final. Es contra lo que se mide la cobertura exacta |
| `generation_target` | estado y recibos | qué generación va a certificar esta publicación |
| `debt_staged` | estado editorial | «la deuda ya quedó anotada»: los productos afectados están apuntados y se puede dejar pasar la escritura |
| `purge_pre_seq` | estado editorial | la purga que se hizo **antes** de dejar pasar la escritura, con el número de la secuencia que la motivó |
| `purge_post_seq` | estado editorial | la purga que se hizo **después** de cerrar el marco. El gate exige que alcance a `wanted_seq` |
| `active_run_id` | estado editorial | qué reconstrucción está atendiendo esta deuda ahora mismo |
| `ack_scope: local_dispatch_only` | recibo de purga | **una advertencia, no un dato**: el recibo confirma sólo que la orden de purga se despachó aquí y quedó anotada. **No** prueba que ningún nodo de Cloudflare la haya obedecido |
| `akb_search_last_rebuild` | Recibo de la última ejecución: `run_id`, `docs`, `result`, `error` | visible en Diagnóstico |
| `akb_search_rebuild_history` | Anillo de 10 ejecuciones | visible en Diagnóstico |
| `akb_search_serving_alarm` | `{down_since, notified_at}` — el estado de la alarma por correo | sin pantalla; el umbral de 900 s sólo se cambia con un despliegue |
| `akb_search_reconcile_last` | Resumen de la última corrida **completa** de la revisión diaria | visible en Buscador → Índice |
| `akb_search_reconcile_last_error` | Resumen de la última corrida **fallida**, separado para no vestir un parcial como éxito | visible en Buscador → Índice |
| `akb_search_editorial_discount_state` | Intención: `wanted_seq`, `wanted_fingerprint`, `window_active`, `next_boundary_at`, `source_term_ids`, `debt_staged`, `active_run_id`, `purge_pre_seq`, `purge_post_seq` | sin pantalla; se muestra en «Servicios» |
| `akb_search_editorial_discount_receipt` | Prueba: `run_id`, `wanted_seq`, `fingerprint`, `coverage{eligible,docs,missing,extra,orphans}`, `generation_target`, `semantic_fingerprint` | sin pantalla |
| `akb_search_editorial_run_receipt_<sha256>` | Recibo inmutable por (run, revisión); se conservan los 20 más recientes | sin pantalla |
| `akb_search_editorial_reconcile_at` | Marca del estrangulador de 60 s; vive en la caché de objetos si hay drop-in persistente, si no en option sin autoload | sin pantalla |
| `akb_search_reproof_deferred_since` | Epoch de cuándo empezó el aplazamiento en curso; 0 = ninguno | sin pantalla; el tope de 600 s sólo se cambia con un despliegue |
| `akb_search_editorial_discount_history` | **Nada.** La constante existe (`src/EditorialDiscountPublication.php:31`) y ni se lee ni se escribe | — |
| `akb_search_semantic_fingerprint` | `{protocol, fingerprint, generation, published_at}` | sin pantalla |
| `akb_search_rest_purge_receipt` | `{generation_target, tag: akb_search_rest, dispatched_at, ack_scope: local_dispatch_only}` | sin pantalla |
| `akb_search_audit_log` | Hasta 50 entradas `{t, user, action, detail}` | se lee en Diagnóstico técnico → «Registro de cambios» |
| `akb_search_indexing` / `_suggest` / `_facets` | `yes`/`no`; `akb_search_indexing` por defecto `no` | Buscador → Ajustes → «Servicios de la tienda» |
| `akb_search_synonyms` / `_stopwords` / `_fuzzy` / `_sku_fastpath` / `_novedad` / `_popular_enabled` | Listas curadas y `yes`/`no` | Buscador → Sinónimos, Stopwords y Ajustes |
| `akb_search_facets_config` / `akb_search_precio_buckets` / `akb_search_precio_mode` | Overrides de facetas, buckets y modo auto/manual | Buscador → Facetas y Buscador → Precios |
| `akibara_taxonomy_discount_enabled` / `_from` / `_to` / `_apply_to` / `_sale_policy`, `akb_discount_max_pct`, `akibara_business_search_feed_contract_version` | Las escribe **akibara-business**; el buscador sólo las **lee** y las vigila | Akibara → Descuentos |

**Transients:**

| Transient | Qué guarda | Vida |
|---|---|---|
| `akb_search_watchdog_guard_v2` | «El vigilante ya está agendado» | 900 s — `RecurringMemo::ttl()` (12 h hasta search 0.1.67) |
| `akb_search_reconciler_scheduled` | «La revisión diaria ya está agendada» | 3.600 s — `RecurringMemo::ttl()` (12 h hasta search 0.1.67) |
| `akb_search_reconcile_alert` | Alerta para la tarjeta del panel cuando hubo divergencias | 2 días |
| `wc_var_prices_<id>`, `wc_product_children_<id>` | De WooCommerce; `ProductSourceAuthority` los borra **y comprueba que se borraron** antes de leer el precio de un producto variable | — |

**Metas de término y de producto:**

| Meta | Dónde | Qué guarda |
|---|---|---|
| `_akb_taxonomy_discount_pct` | termmeta | El porcentaje de descuento del término. Lo escribe akibara-business; el buscador **intercepta** su escritura |
| `_akb_onbackorder_at` | postmeta | Epoch del primer momento observado en preventa. Se escribe **una vez**: un ciclo posterior de preventa del mismo producto no la pisa (`src/Arrival.php:13-17`). Es **fuente**, no derivado: ninguna migración ni rebuild la toca |
| `_akb_first_instock_at` | postmeta | Epoch de la primera llegada real: el primer paso observado de «a pedido» a «disponible». Se escribe **una vez** y jamás se pisa (`src/Arrival.php:14-19`) |

---

## Glosario del capítulo

**Lo primero, las tres palabras que este capítulo usa en cada página:**

- **503**: el código con el que un servidor contesta «ahora no puedo, vuelve luego». El cliente no ve un error feo: el desplegable simplemente no aparece y el catálogo se pinta sin filtros. Lo contrario es **200**, «aquí tienes».
- **Generación**: un **número de serie del índice**. Cada vez que el índice cambia de contenido, sube. Sirve para dos cosas: que la purga de la caché sepa que hay algo nuevo que tirar, y que un recibo pueda afirmar «yo certifico la generación 47, no otra». Vive en la option `akb_search_index_gen`, y cambiarla es exactamente lo que dispara `RestCache::purge()`.
- **Run**: una reconstrucción concreta, con nombre propio (`run_id`). Todo lo que esa reconstrucción apunta en la libreta lleva su nombre, para que una acción vieja de otro run no toque el que está vivo.

**El resto, por orden de aparición:**

- **Índice**: copia adelantada del catálogo, guardada en tablas propias y ya masticada, para que buscar y filtrar sea instantáneo.
- **Documento**: la fila de `docs` que representa a un producto dentro del índice.
- **Token**: una palabra del producto guardada aparte —del título, la serie, el autor, el SKU, una categoría o la descripción— para poder encontrarlo escribiendo un trozo.
- **Faceta**: un filtro del catálogo (editorial, categoría, autor, serie, precio, disponibilidad, novedad, oferta).
- **Collation**: la regla con la que la base decide si dos textos son «iguales». La normal considera «ñ» y «n» la misma letra; en `tokens` se fija una **binaria** a propósito, porque si no dos palabras distintas chocan en la clave primaria y el INSERT muere mudo.
- **Rebuild (reconstrucción completa)**: reescribir el índice entero, hoy unos 1.568 productos.
- **Incremental**: reescribir sólo las filas de un producto que cambió. Segundos, sin apagar nada.
- **Tabla sombra (`_new`)**: gemela que el rebuild llena a su ritmo mientras las vivas siguen sirviendo.
- **Cambiazo (swap)**: el `RENAME TABLE` único que pone las `_new` en producción y aparta las vivas como `_old`.
- **Topología**: la foto de qué tablas existen ahora mismo. Cuatro valores: `clean_pre`, `ready_swap`, `swapped`, `ambiguous`.
- **Fase**: en qué punto del viaje está un rebuild, guardado en `akb_search_rebuild_state`.
- **Deuda durable**: la lista de lo que falta por reindexar, guardada en una tabla propia y no en la cola de trabajos, para que sobreviva a una caída del servidor.
- **Ámbito (scope)**: a qué corresponde una tarea de la deuda: `live` (un cambio normal), `baseline` (el manifiesto de un rebuild) o `repair` (lo que cambió mientras el rebuild construía).
- **Arriendo (lease)**: el préstamo temporal de una tarea a un obrero. Si el obrero muere, el arriendo vence y la tarea vuelve a la cola. **Cuidado con la palabra:** el código en inglés usa *lease* también para otra cosa completamente distinta —el **permiso de servido** que concede `EditorialDiscountPublication::serving_ready()`, que decide si el buscador puede contestar—. Este capítulo dice «arriendo» sólo para las tareas y «permiso de servido» para lo otro.
- **Fan-out**: partir de una cosa que cambió —un término, por ejemplo «Ivrea»— y resolver la lista de **todos** los productos a los que afecta, para apuntarlos uno a uno como deuda.
- **Idempotente**: repetirlo no hace daño. Correrlo una vez o cinco deja el mismo resultado; por eso el sistema puede reintentar sin miedo cuando no sabe si algo llegó a pasar.
- **Live-lock**: un sistema que no está parado ni trabado, sino girando sin avanzar. Aquí tiene nombre y fecha: el 2026-09-05, una señal de rebuild con `not_before` en el futuro bloqueaba el carril automático mientras no venciera la gracia, y el comando lo anunciaba como si todo hubiera ido bien.
- **Proveedor de precio**: quien contesta cuánto vale de verdad un producto con el descuento editorial ya aplicado. No es el buscador: es akibara-business, enganchado al filtro `akb_search_effective_price`.
- **ACK**: la confirmación de que una tarea se completó. Sólo cierra la fila si nadie pidió una revisión nueva mientras tanto.
- **Fence (cerrojo)**: candado de MariaDB atado a una conexión concreta, que impide que dos procesos escriban el índice a la vez.
- **Huella (fingerprint)**: resumen criptográfico de una configuración. Sirve para probar que lo publicado corresponde exactamente a lo configurado.
- **Recibo**: lo que se guarda **después** de publicar, y que dice «con esta huella, el índice tiene exactamente estos documentos».
- **Gate / serving**: la comprobación que decide si el buscador puede responder. Si la huella viva, la del estado y la del recibo no coinciden, se apaga a propósito.
- **Fail-closed**: ante una duda, apagar el servicio en vez de devolver algo posiblemente falso. Lo contrario, *fail-open*, es lo que hace el front del catálogo: si el índice está vacío, sirve el catálogo sin filtrar.
- **Estrangulador (throttle)**: límite de cuántas veces por minuto puede correr una comprobación cara.
- **Válvula**: el aplazamiento con tope de la comprobación de precios mientras hay escrituras en vuelo.
- **Barrera pre-escritura**: apuntar la deuda con unos segundos de retraso, porque el hook corre antes de que WooCommerce haya guardado de verdad.
- **Action Scheduler**: la cola de trabajos en segundo plano que trae WooCommerce. Aquí es sólo un despertador: la verdad de lo pendiente vive en la tabla `work`.
- **Hook**: punto del programa donde WordPress deja enganchar código propio. Una **acción** ejecuta algo y no se puede vetar; un **filtro** transforma un valor y sí puede devolver otro.
- **Prioridad**: el número que decide en qué orden corren varios enganches al mismo hook. `PHP_INT_MAX` significa «el último».
- **Option**: un ajuste guardado en la tabla `wp_options` de WordPress.
- **Transient**: una option con fecha de caducidad.
- **Postmeta / termmeta**: datos sueltos colgados de un producto o de un término.
- **Nonce**: código de un solo uso que prueba que el clic salió de la pantalla correcta y no de otro sitio.
- **Purga**: borrar copias guardadas para que la siguiente visita vea lo nuevo. `litespeed_purge` se queda en el origen; `litespeed_purge_all` es la única que llega a Cloudflare.
- **Espejo**: el entorno local con Docker donde se prueba antes de producción.
- **Mailpit**: el buzón de correo de mentira del espejo. Recoge todo lo que el sitio local intenta enviar, para que ninguna prueba llegue a una persona.

**Palabras de base de datos**, que aquí salen media docena de veces:

- **DDL**: las órdenes que definen **la forma** de las tablas —crear una tabla, añadir una columna, crear un índice—, por oposición a las que mueven datos.
- **`dbDelta`**: la función de WordPress que crea y actualiza tablas. Hace lo que puede y no siempre avisa de lo que no pudo; por eso este plugin no le cree y comprueba después.
- **`information_schema`**: el catálogo que la propia base de datos lleva de sus tablas. La lista oficial de qué columnas y qué índices existen de verdad, que es contra lo que se verifica.
- **InnoDB**: el motor de almacenamiento de MariaDB que sabe hacer transacciones. Se exige a propósito: sin él, «o entra todo o no entra nada» no existiría.
- **Transacción / COMMIT**: un grupo de escrituras que entra entero o no entra nada. `COMMIT` es la orden que lo confirma; hasta entonces, nadie más las ve.
- **Autoload**: la marca que decide si WordPress carga una option en **todas** las peticiones o sólo cuando alguien la pide. «Sin autoload» quiere decir lo segundo: no pesa en cada visita.
- **Caché de objetos y *drop-in* persistente**: WordPress guarda en memoria lo que ya leyó, pero por defecto sólo durante esa petición. Un *drop-in* persistente —Redis o Memcached— hace que esa memoria sobreviva entre peticiones. Que un dato «viva en la caché de objetos si hay drop-in persistente, y si no en una option» significa exactamente eso: si hay memoria compartida se usa, y si no, se baja a la base.
