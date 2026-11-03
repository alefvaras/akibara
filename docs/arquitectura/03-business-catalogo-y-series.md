# akibara-business · catálogo, series, tomos, editoriales y packs

> **Qué cubre:** las series (`pa_serie`), los tomos, las editoriales (`product_brand`),
> las landings `/serie/<slug>/` y `/marca/<slug>/`, las páginas paraguas `/ivrea/` y
> `/panini/`, el directorio `/series/`, los packs «Arma tu Pack», el aviso «Sigue esta
> serie», el orden del catálogo, las vitrinas del inicio y las portadas de serie.
>
> **Verdad del código:** el paquete publicado en producción, **akibara-business 1.116.4**.
> Todo lo que sigue se leyó de ese ZIP congelado, no del árbol local. Cada afirmación
> técnica lleva su fichero y su línea.

---

## Para qué existe (en dos párrafos que entienda un novato)

Akibara vende manga y cómic en Chile. La tienda es **WooCommerce**, que a su vez es una
tienda montada encima de **WordPress**. En WordPress cada producto es una **ficha**: una
fila de la base de datos con título, precio e imagen. Las fichas se agrupan con
**taxonomías**, que son etiquetas organizadas que WordPress guarda aparte y que, además,
**generan una página de listado propia**. Y aquí está el dato que cambia cómo se lee todo
lo demás: **en Akibara un producto es UN TOMO, no una serie**. «One Piece» no tiene ficha
propia; lo único que existe de ella es el listado de sus tomos, en `/serie/one-piece/`.

De ahí sale el trabajo central de este capítulo: **derivar los hechos de una serie a
partir de sus tomos**. Cuántos hay, en qué estado de stock, entre qué precios, de qué
editorial, de qué autor, cuándo se tocó por última vez. Ese agregado lo calcula **una sola
clase con una sola consulta** (`SeriesFacts`), y de ahí beben tanto el bloque visible bajo
el título como el **JSON-LD** (los datos estructurados que leen Google y los asistentes de
inteligencia artificial). La regla escrita en el código es tajante: *un dato que no se
puede derivar NO se muestra*, nunca un valor de relleno. Y lo que no se puede derivar son
**dos ejes** —la obra original y la edición que se vende—, cada uno con **dos campos**: en
qué estado está y cuántos tomos son. **Cuatro term meta en total**, y los cuatro los
escribe el dueño a mano. Hacen falta porque la base de datos no puede distinguir «esta
serie terminó en 17 tomos» de «esta serie va por el 17»: la suma da 17 en los dos casos, y
ésa es justamente la pregunta con la que la gente llega desde Google. Esas landings de
serie son, medido en Search Console, la palanca de posicionamiento del sitio: 4.044
impresiones cada 90 días y 128 clics (`SeriesDirectory/Shortcode.php:6-7`; la cifra es una
anotación del código, no una consulta hecha para este capítulo).

![Piezas del capítulo](diagramas/03-business-catalogo-y-series-piezas.mmd)

### Las cifras de referencia, con su fecha

El capítulo cita números del catálogo. Todos salen de mediciones anotadas en comentarios
del código en fechas distintas, y por eso **no coinciden entre sí**. La cifra de trabajo de
este capítulo es la más reciente y la única que se midió contra producción:

| Cifra | Vale | De dónde sale |
|---|---|---|
| Productos (tomos) publicados | **1.568** | Medido en producción el **2026-08-23**, anotado en `CatalogOrder.php:108` y `:476`. Es la cifra que usa el resto del capítulo |
| Productos, cifra vieja | ~1.566 | Comentarios de `SeriesSchema.php:8` y `LlmsManifest.php:1112`, anteriores al recuento del 2026-08-23 |
| Productos, cifra muy vieja | 1.466 | Un comentario de `CatalogOrder.php:377` que explica por qué no se usa `wp_update_post`. El argumento sigue valiendo; el número, no |
| Términos de `pa_serie` | **130** | `SeoEnhancements.php:44` y `Plugin.php:1090` |
| Series con al menos un tomo en catálogo (las que se indexan) | **~104** | `Shortcode.php:6`, `Plugin.php:1147` y `LlmsManifest.php:651`. En `AdminPanel.php:595` aparece 106, de otra fecha |
| Series que el dueño ve en el panel de packs | **119** | `BypConfigController.php:147` y `SeriesEditions.php:472`: ahí se listan **todas** las que tienen productos, indexables o no |
| Productos «A pedido» | 422, el 27 % | `PopularProducts.php:122-125`, sin fecha en el comentario |

**Regla de lectura:** donde este capítulo dé un número, dice de dónde sale. Un número sin
procedencia envejece sin avisar.

### Las cuatro palabras de la disponibilidad

El negocio entero —packs, correos, portadas, más vendidos— se decide con estas palabras, y
no significan lo mismo:

- **El estado técnico** es uno de **tres** valores de WooCommerce: `instock`,
  `onbackorder`, `outofstock`. No hay más.
- **«Reservable»** es la palabra de este capítulo para `onbackorder`: lo que no está en
  bodega pero se puede comprar igual.
- **«A pedido»** es cómo la tienda lo *muestra*. `StockStatus::LABELS` (`:50-54`) traduce
  `instock` → «Disponible», `onbackorder` → «A pedido», `outofstock` → «Agotado». Es el
  vocabulario **del grupo**: el que usan los conteos de `SeriesFacts` y los chips.
- **«Preventa»** **no es un estado de stock.** Es una distinción comercial adicional que
  vive en `Stock\Availability::LABELS` (`:135-141`) y que sólo existe **por producto**: dos
  productos con el mismo `onbackorder` pueden mostrarse uno «A pedido» y el otro
  «Preventa», según su fecha. El detalle está en el **capítulo 04 — disponibilidad,
  preventa y avisos**.

Dicho corto: hay tres estados técnicos, tres palabras de grupo y cuatro palabras por
producto.

La reserva sin unidades físicas usa el contrato nativo de WooCommerce:
`manage_stock=false`, cantidad vacía, `stock_status=onbackorder` y `backorders=no`.
El estado mantiene la compra; `no` no prohíbe vender esta reserva sin cantidades
gestionadas. No se fuerza `notify` después de guardar. La regla 18 de la
[política de catálogo](../../.agents/rules/catalogo.md) protege los productos
vinculados a pedidos pendientes durante cualquier migración.

---

## Cómo encaja con el resto

### Con quién habla

| Con quién | Por dónde | Qué pasa si falta |
|---|---|---|
| **WooCommerce** (tercero) | Productos, la tabla `wc_product_meta_lookup` (stock y min/max de precio), los cupones, las clases `WC_Email` y el shortcode `[products]` | El área entera deja de funcionar: es el sustrato, no una dependencia opcional |
| **Action Scheduler** (tercero, lo trae WooCommerce) | `as_schedule_recurring_action` y `as_schedule_single_action` en `CatalogOrder`, `PublishListener`, `DigestRunner` y `CustomerCollection` | Sin él, `CatalogOrder::schedule_recompute()` recalcula **en el acto** (`CatalogOrder.php:247-250`) y «Sigue esta serie» no envía nada (`DigestRunner.php:792-793`) |
| **Rank Math** (tercero) | `rank_math/json_ld` prioridad **91** (`SeriesSchema`) y **92** (`BrandLandingSchema`); y el term meta `rank_math_robots`, que decide el `noindex` | Sin Rank Math no hay grafo al que adjuntarse: el nodo `ComicSeries` desaparece y las 1.568 fichas de producto vuelven a apuntar con `isPartOf` a una entidad que no existe |
| **LiteSpeed Cache** (tercero) | `litespeed_purge` con la etiqueta `T.<term_id>`, `litespeed_purge_url`, `litespeed_purge_all` y `litespeed_ucss_whitelist` | Los cambios siguen escribiéndose, pero la página cacheada sigue sirviendo lo viejo hasta que caduque |
| **Cloudflare** (tercero) | **Ninguna llamada directa**, pero sí un camino indirecto: `litespeed_purge_all`, con el complemento `cdn-cloudflare_clear` de LiteSpeed, **sí propaga al borde** (`CatalogOrder.php:110-112`). Las purgas **por etiqueta y por URL no** (`:478-481`) | El borde sigue sirviendo lo viejo hasta que venza su propia caché. Ver *La caché tiene dos pisos* |
| **Tema `akibara-gp`** (propio) | `akibara_gp_series_hero_after_title` (lo usa `SeriesEditions`) y el filtro `akibara/popular_product_ids` (lo alimenta `PopularProducts`) | Con otro tema, el bloque «Otras ediciones en Akibara» **no se pinta**; y la fila de más vendidos **no se pinta tampoco**: el tema fuerza un resultado vacío a propósito (ver `PopularProducts`) |
| **`akibara-search`** (propio) | `PublishListener` viaja encima de `set_object_terms`, el evento que dispara el reindexado; y `LandingOffer` **emite** en la URL la faceta `_disponibilidad` con el vocabulario comercial (`instock` / `a_pedido` / `outofstock`), que es el que el índice del buscador conoce | Los chips de la landing dejan de filtrar; el reindexado no se ve afectado en sentido contrario |
| **`akibara-cart-checkout-blocks`** (propio) | `SeriesBundles::structured_cart_presentation()`: etiquetas de cupón y avisos semánticos para el adaptador de bloques | El carrito de bloques pierde el texto propio del pack y muestra el nombre técnico del cupón |
| **Módulos hermanos de `akibara-business`** | `TaxonomyDiscount\Settings` (el predicado que calla el rango de precios), `Utilities\DiscountCeiling` (el tope de porcentaje compartido), `Stock\StockStatus` (el vocabulario de grupo: Disponible / A pedido / Agotado) y `Stock\Availability` (el vocabulario por producto, que añade «Preventa»), `EstimatedDelivery\PedidoPolicy` y `DateCalculator` (las fechas), `Services\AkibaraEmail` y `Services\OneClickPage`, `BackInStock\CaptureForm`, `Services\FreeShippingThreshold`, `AccountCollection\Endpoint` | Están dentro del mismo plugin: si faltan, falta el plugin |

### La caché tiene dos pisos, y este capítulo vive en el de abajo

Casi todos los «se invalida» del capítulo hablan del **piso de abajo**: los transients y
las generaciones que guardan *datos ya calculados* (el agregado de una serie, la lista de
IDs de una vitrina). Encima de ése hay otro piso, el **cache de página** de LiteSpeed, que
guarda el HTML entero; y encima de ése, **Cloudflare**, que guarda otra copia del HTML en
sus servidores, que es la que de verdad ve el cliente. Tirar el de abajo no tira los de
arriba.

Por eso conviene tener claros los tres gestos:

| Gesto | Qué invalida | ¿Llega a Cloudflare? |
|---|---|---|
| Rotar una generación / borrar un transient | Sólo el dato calculado. El HTML cacheado sigue igual | No |
| `litespeed_purge` con la etiqueta `T.<term_id>` · `litespeed_purge_url` | El HTML de esa página (la etiqueta cubre también sus páginas 2 y siguientes) **en el servidor** | **No** (`CatalogOrder.php:478-481`) |
| `litespeed_purge_all` | Todo el HTML cacheado del sitio | **Sí**, vía el complemento `cdn-cloudflare_clear` de LiteSpeed (`CatalogOrder.php:110-112`) |

Y por eso el tercero cuesta caro: medido y anotado en ese mismo comentario, tras un
`litespeed_purge_all` la landing de serie pasa de **48 a 1.057 ms** mientras el borde se
vuelve a llenar. Es la razón de que la corrida del reorden sea a las 05:00 y de que sólo se
dispare **si escribió algo** (`:482-484`).

En esta área lo disparan exactamente dos cosas: la corrida del reorden del catálogo, y el
panel de ediciones **sólo cuando cambia el texto del menú** (`EditionsPanel.php:282-286`),
porque el menú sale en todas las páginas.

> **Una trampa de redacción que vale una hora de depuración.** El aviso que el panel de
> ediciones le muestra al dueño promete que «el borde (Cloudflare) las renueva al vencer su
> caché, en unos minutos» — y ese texto es el de la rama que **no** toca el borde (la purga
> por URL). La rama que sí lo toca imprime otro texto («se purgó la caché de página
> entera») y no promete nada. Los dos avisos están en `EditionsPanel.php:243-248`.

### `litespeed_ucss_whitelist`: por qué cinco módulos declaran sus clases CSS

LiteSpeed puede generar un CSS reducido a lo que la página usa de verdad — se llama
**UCSS**, *Unique CSS*. Poda la hoja de estilos mirando el HTML: lo que no aparece, se va.
El problema es que una clase que **sólo existe cuando el JavaScript la añade** no aparece
en ese muestreo, así que la poda se la lleva y el elemento sale sin estilos en cuanto el
script lo pinta. Cinco módulos de esta área (`SeriesFacts`, `SeriesEditions`,
`BrandLandings`, `LandingOffer` y `SeriesRelatedGuide`) se enganchan al filtro
`litespeed_ucss_whitelist` en prioridad 20 para declarar a mano las suyas. Cada módulo
protege **sus** clases, en vez de una lista central que hay que acordarse de tocar desde
otro fichero (`SeriesFacts.php:349-353`).

**Hoy no hace nada:** UCSS está apagado en producción (`litespeed.conf.optm-ucss` vacío), y
el propio código lo dice — se declara «para que activar UCSS no reintroduzca el defecto en
silencio» (`SeriesFacts.php:392-393`). Si algún día alguien enciende UCSS y un riel aparece
sin estilos, éste es el sitio donde mirar.

### Qué pasa si se apaga

No hay un interruptor único para «el catálogo y las series»: son las 67 clases que se
describen más abajo, y el plugin las cablea una a una en `Plugin.php` (el fichero que
construye y registra todos los módulos; **ver capítulo 02 — núcleo**). Lo que sí tiene
interruptor propio:

- **«Arma tu Pack»** (`akb_series_bundles_enabled`, por defecto **no**). Apagarlo oculta la
  **superficie entera**, no sólo el descuento (`LandingOffer.php:165-172`, `:262-269`,
  `:285-292`): desaparecen el armador de la ficha y el riel de la landing.
- **«Sigue esta serie»** (`akb_sf_enabled`, por defecto **no**). Con el interruptor apagado
  `PublishListener` ni siquiera encola: la comprobación está en sus dos manejadores,
  `PublishListener.php:77` y `:104`, y llama a `SeriesFollow::enabled()`
  (`SeriesFollow.php:335-344`). Así que la cola se queda vacía. Hay un **segundo**
  interruptor, el «Habilitar este email» de WooCommerce, y el panel no informa de él.
- **La vitrina «Series más buscadas»** (`akibara_home_series_enabled`). Además, sin lista de
  slugs el bloque no pinta nada: nunca un bloque vacío ni una selección inventada.

#### Cómo saber si están encendidos **hoy**

El estado de una option caduca; el comando no. Desde el servidor (`ssh akibara`, en el
docroot):

```bash
wp option get akb_series_bundles_enabled          # «Arma tu Pack»: yes | no
wp option get akb_sf_enabled                      # «Sigue esta serie», 1er interruptor
wp option get akibara_home_series_enabled         # vitrina de series de la portada
# El SEGUNDO interruptor del correo, el de WooCommerce:
wp option get woocommerce_akb_sf_series_settings --format=json   # mirar la clave "enabled"
```

Un `wp option get` de una option que no existe devuelve error y código de salida 1: eso
significa «nunca se guardó», o sea el valor por defecto de la lista de arriba.

Si se desactivara el plugin entero: las landings de serie seguirían existiendo (son
archives de WordPress) pero sin bloque de datos, sin JSON-LD de serie, sin conmutador de
ediciones y sin packs; el `menu_order` quedaría congelado en el último valor calculado; y
`/editoriales/`, `/ivrea/` y `/panini/` servirían el shortcode sin resolver.

---

## Piezas

**67 clases** en cinco dominios, más el fichero de arranque `akibara-business.php`,
agrupadas en **31 fichas** (las clases que sólo se entienden juntas comparten ficha; el
título de cada una las nombra todas, así que se pueden contar). Para cada ficha: qué hace
en una frase, sus ficheros, en qué momento actúa, qué lee y escribe, dónde lo opera el
dueño y qué ve el cliente.

> **Cómo leer «dónde lo opera el dueño».** Los caminos de menú del tipo «Akibara → Venta →
> Vitrina» son pestañas de un panel propio que vive en
> `/wp-admin/admin.php?page=akibara-panel&tab=<pestaña>`. **El slug es `akibara-panel`, no
> `akibara`**: el corto devuelve 403 y parece un problema de permisos. Las pestañas que
> nombra este capítulo, con el identificador que va en `tab=`:
>
> | Camino de menú | `tab=` | Dónde se declara |
> |---|---|---|
> | Akibara → Venta → «Vitrina» | `catalog_order` | `CatalogOrder.php:60` · `Plugin.php:1375-1382` |
> | Akibara → Venta → «Arma tu Pack» | `series_bundles` | `SeriesBundles.php:55` · `Plugin.php:1393` |
> | Akibara → Venta → «Sigue esta serie» | `series_follow` | `Plugin.php:1351-1353` |
> | Akibara → Sitio → «Editoriales · Ediciones» | `brand_editions` | `EditionsPanel.php:35` |
> | Akibara → Sitio → «Series en la portada» | `home_series` | `Plugin.php:1296` |
>
> Lo que dice «Productos → …», «Apariencia → Menús» o «WooCommerce → Ajustes → …» son
> pantallas de WordPress y de WooCommerce, no del panel.

### Dominio 1 — La serie y su ficha de datos

#### `SeriesCatalogEligibility`

Contesta una sola pregunta para todo el sitio: «¿cuántos tomos publicados y visibles tiene
esta serie?».

- **Ficheros:** `src/Modules/SeriesCatalog/SeriesCatalogEligibility.php`
- **Cuándo actúa:** no se engancha a ningún hook; lo llaman otros por método.
- **Lee:** `wp_posts`, `wp_term_relationships`, `wp_term_taxonomy` y `wp_terms` en **una**
  consulta agregada, nunca una por serie. **Escribe:** nada.
- **Dónde lo opera el dueño:** en ninguna parte.
- **Qué ve el cliente:** el número «6 tomos en catálogo» del directorio, y las decisiones de
  indexación que dependen de él.
- **Por qué existe:** el contador nativo `WP_Term::count` incluye los productos marcados
  `exclude-from-catalog`, y además esta clase distingue un cero real de un fallo de base de
  datos: `is_reliable()` dice si la última lectura fue autoritativa, y quien iba a persistir
  estado SEO se abstiene si dice que no (`:190-219`).

> **Qué es «lo oculto del catálogo».** `exclude-from-catalog` no es una invención de
> Akibara: es uno de los valores de la casilla **«Visibilidad del catálogo»** que WooCommerce
> pone en la caja «Publicar» de la ficha de producto (enlace «Editar» junto a *Visibilidad
> del catálogo*, arriba a la derecha). Sus cuatro opciones son «Tienda y resultados de
> búsqueda», «Sólo en la tienda», «Sólo resultados de búsqueda» y «Oculto», y por dentro se
> guardan como términos de una taxonomía llamada `product_visibility`:
> `exclude-from-catalog` y `exclude-from-search`. Un tomo marcado «Oculto» existe, se puede
> comprar por su URL y **cuenta** para `WP_Term::count`, pero no debería aparecer en ningún
> listado. Esa diferencia es la que separa los dos contadores que este capítulo compara todo
> el rato.

#### `SeriesIndex` + `SeriesDirectory\Shortcode` + bootstrap `SeriesDirectory`

El directorio alfabético de series, con shortcode `[akibara_series_directory]`.

- **Ficheros:** `src/Modules/SeriesDirectory/SeriesIndex.php`, `Shortcode.php`,
  `SeriesDirectory.php`
- **Cuándo actúa:** `add_shortcode akibara_series_directory`;
  `created_pa_serie` / `edited_pa_serie` / `delete_pa_serie` @10; `set_object_terms` @10;
  `deleted_term_relationships` @10; `transition_post_status` @10;
  `added_term_meta` / `updated_term_meta` / `deleted_term_meta` @10; `shutdown` @`PHP_INT_MAX`;
  `wp_enqueue_scripts` @10 y `rank_math/frontend/description` @10 en el bootstrap.
- **Lee:** `SeriesCatalogEligibility::counts()` y el term meta `rank_math_robots`.
  **Escribe:** el transient `akb_series_directory_v3` (HTML ya construido, 6 h).
- **Dónde lo opera el dueño:** crea una página y pega el shortcode. **El código no crea
  páginas.**
- **Qué ve el cliente:** el índice alfabético con buscador. El buscador sale **oculto** y lo
  revela el JavaScript: un buscador visible que no funciona porque el script no cargó es
  peor que no tener buscador.
- **Dónde vive hoy: `/series/`.** El módulo no registra rutas, así que la URL la decide el
  dueño al crear la página — pero la que existe es `/series/`, y el resto del código la da
  por buena: la purga (`Shortcode.php:155`), el «Ver todas →» de la vitrina de la portada
  (`HomeSeriesDestacadas.php:169`), el `llms.txt` (`LlmsManifest.php:1366`), la negociación
  en Markdown (`MarkdownNegotiation.php:479`) y el schema del hub
  (`SeoEnhancements.php:621`) apuntan todos ahí. Y está medida: el 2026-08-18 el hub
  `/series/` aparecía en Search Console «Submitted and indexed» con 104 elementos
  (`BrandLandingSchema.php:12-25`).
  **Ojo con dos comentarios del código que confunden:** `Shortcode.php:10-13` y
  `Plugin.php:1147-1153` dicen que `/series/` «es hoy una redirección 301 hacia `/tienda/`
  con 69 impactos». Eso describe **lo que había antes de este módulo** y es el argumento de
  por qué se construyó: «la demanda de esta página está medida, y lo que había en su lugar
  era un desvío». No es el estado actual. Si algún día `/series/` vuelve a devolver un 301,
  entonces sí la purga estaría invalidando una redirección y el «Ver todas →» llevaría a
  `/tienda/`; para comprobarlo basta
  `curl -sI -A AkibaraQA https://akibara.cl/series/ | head -1`.
- **La URL se resuelve, no se da por sentada (R2-8951cc1e, 1.116.26).** `Services\SeriesHubUrl::url()`
  busca la página publicada que CONTIENE `[akibara_series_directory]` (mismo algoritmo que
  `akibara_gp_series_hub_url()` del tema) y cae a `/series/` sólo si no la encuentra. La usan el
  schema de `SeoEnhancements`, la purga de `SeriesDirectory\Shortcode`, el «Ver todas» de
  `HomeSeriesDestacadas` y `AccountCollection\Renderer`: antes cada uno repetía el literal, y
  renombrar la página dejaba schema, purgas y enlaces apuntando a una URL rota. Medido: 0,4 ms.
- **Dos reglas, ninguna lista de slugs** (`SeriesIndex.php:311` y `:349`): al menos un tomo
  en catálogo, y sin `noindex`. De los 130 términos de `pa_serie` quedan las ~104 productivas, y la
  siguiente entra sola. El orden usa el **nombre tal como se ve** (`:293-297`): «El Verano
  que Hikaru Murió» se archiva en la E, no en la V, y con `strcmp` sobre el nombre plegado,
  nunca con `strcoll`, que depende del idioma del proceso.

#### `SeriesFacts`

El bloque de datos duros bajo el título de la landing, y el único agregado del que bebe el
JSON-LD.

- **Ficheros:** `src/Modules/SeriesFacts/SeriesFacts.php`
- **Cuándo actúa:** `woocommerce_before_shop_loop` @2 (pinta);
  `litespeed_ucss_whitelist` @20 (declara sus clases condicionales ante la poda de CSS; ver
  arriba); y para invalidar: `woocommerce_product_set_stock_status` @10,
  `woocommerce_product_set_stock` @10, `save_post_product` / `before_delete_post` @10,
  `added_post_meta` / `updated_post_meta` / `deleted_post_meta` @10, `set_object_terms` @10,
  `deleted_term_relationships` @10, `edited_product_brand` / `edited_pa_autor` @10, y
  `shutdown` @**-100** para vaciar la cola de purgas.
- **Lee:** `wc_product_meta_lookup`, los cuatro term meta de `PublicationStatus`,
  `TaxonomyDiscount\Settings::is_enabled()` y `Stock\StockStatus::label()`.
  **Escribe:** las options de generación `akb_series_facts_generation` y
  `akb_series_facts_generation_<term_taxonomy_id>` (tokens opacos, sin autoload) y el
  transient `akb_series_facts_v3_<generacion>_<ttid>` (6 h, se cachea incluso el resultado
  vacío).
- **Dónde lo opera el dueño:** indirectamente, por el estado de publicación y por el
  interruptor de campañas de descuento.
- **Qué ve el cliente:** «107 tomos · Disponible 84 · A pedido 19 · Agotado 4: 03 y 06 ·
  Edición Ivrea Argentina · Autor …».
- **Sus cinco reglas de honestidad:** sin tomos publicados no imprime bloque (`:1138`);
  «Disponible» y «A pedido» salen **incluso en 0** porque un cero ahí es información, y
  «Agotado» sólo si existe (`:1811-1817`); los números de los agotados se nombran **sólo si
  se pueden afirmar todos** (`:1577-1610`), porque quien lee «Agotado: 4 tomos: 03 y 06»
  concluye que el 08 y el 11 están disponibles; el rango de precios **no se publica mientras
  haya campaña vigente** (`:1285-1291` y `:1865-1870`), porque la tabla de precios de
  WooCommerce no incluye un descuento aplicado por filtro y el bloque diría $9.500 sobre una
  rejilla que dice $8.075; y la fila «Edición X» sólo sale si el catálogo deriva **una** sola
  editorial (`:1731-1740`).
- **Las generaciones** son un token opaco en `wp_options` (un UUID v4, `:833-839`) que, al
  rotar, vuelve inalcanzable el snapshot viejo **incluso con caché de objetos externa** —
  que es lo que hay en producción y la razón de que un `DELETE` de transients por SQL no
  sirva. **Qué número va en la clave:** el sufijo del transient y el de la option de
  generación son el **`term_taxonomy_id`** de la serie, no su `term_id` (`:140` lo dice
  expresamente). La etiqueta de purga de LiteSpeed, en cambio, es `T.<term_id>`; por eso
  `invalidate_on_catalog_term_edited()` resuelve los pares `ttid:term_id` con una consulta
  (`:612-638`) antes de purgar. Ver el glosario: son dos números distintos del mismo
  término.
- **Cómo forzar un recálculo a mano** (no hay botón ni comando; es la vía documentada aquí
  precisamente porque un `DELETE` de transients no funciona): rotar la generación. Desde el
  servidor,

  ```bash
  # el ttid de la serie, desde su slug
  wp term get pa_serie <slug> --by=slug --field=term_taxonomy_id
  # rotar SOLO esa serie (cualquier valor nuevo sirve: es un token opaco)
  wp option update akb_series_facts_generation_<ttid> "manual-$(date +%s)"
  ```

  Y después hay que purgar el HTML de esa landing, que es el piso de arriba: desde el panel
  de LiteSpeed Cache, o con el comando propio de ese plugin si está disponible. Rotar la
  generación sola cambia el dato, no la página cacheada.

  El valor se filtra a letras, números y guiones, y se recorta a 64 caracteres
  (`normalize_cache_generation()`, `:1094-1097`): cualquier cosa que se escriba fuera de ese
  alfabeto se pierde en silencio.

  Las gemelas de las otras piezas se rotan igual: `akb_brands_grid_gen` (contador entero:
  súmale 1), `akb_home_destacados_generation` (marca de tiempo: `$(date +%s)`) y
  `akb_sb_vols_cache_ver`. **Nunca borrar la option con `wp option delete`**: el código la
  vuelve a crear en el siguiente render, pero mientras tanto una clave sin generación es una
  clave que colisiona con las viejas.

#### `PublicationStatus` + `PublicationStatusFields`

El contrato y el formulario de **los cuatro datos** de la serie que no se derivan: dos ejes
—la obra original y la edición que se vende— con dos campos cada uno, el estado y el total
de tomos.

- **Ficheros:** `src/Modules/SeriesFacts/PublicationStatus.php`, `PublicationStatusFields.php`
- **Cuándo actúa:** `pa_serie_add_form_fields` / `pa_serie_edit_form_fields` @10;
  `created_pa_serie` / `edited_pa_serie` @10;
  `added_term_meta` / `updated_term_meta` / `deleted_term_meta` @10;
  `manage_edit-pa_serie_columns` / `manage_pa_serie_custom_column` @10; `shutdown` @`PHP_INT_MAX`.
- **Lee y escribe:** los term meta `_akb_serie_estado`, `_akb_serie_tomos_obra`,
  `_akb_serie_estado_edicion` y `_akb_serie_tomos_edicion`. Vigila además
  `_akb_related_guide_post_id` para purgar la landing.
- **Dónde lo opera el dueño:** Productos → Atributos → Serie → editar término; y la columna
  «Publicación» del listado.
- **Qué ve el cliente:** «Edición Ivrea Argentina: Completa en 23 tomos» y, sólo si aporta
  algo distinto, «Obra original: Finalizada en 23 tomos».
- **Detalles que importan:** el valor es una **clave de un conjunto cerrado** y el español se
  escribe una sola vez —si el campo fuera libre, la voz de marca sería la de 104 momentos
  distintos. El guardado exige nonce, la capability `manage_product_terms` y que el término
  exista en `pa_serie`, porque `edited_pa_serie` lo dispara **cualquier** `wp_update_term`
  (una importación, un cron, otro plugin). Guarda campo a campo: *ausente no se toca, vacío
  borra* (`PublicationStatusFields.php:492-520`). Y se lee **fresco en cada render**, a
  propósito — con una precisión importante: *fresco respecto del cache de 6 h de
  `SeriesFacts`*, que es el piso de abajo. El cache de página de LiteSpeed sigue estando
  por encima, y por eso al guardar el término hay que purgar además la etiqueta
  `T.<term_id>`; si no, el cliente seguiría viendo el HTML viejo aunque el dato ya esté
  corregido.
- **La columna del listado afirma un número que la landing calla.** En el listado de
  términos, `handle_admin_column_content()` concatena **siempre** « · N tomos»
  cuando el total existe (`:174-176`). El lado público es más estricto:
  `PublicationStatus::edition_phrase()` (`:404-432`) sólo publica el total con «Completa» y
  «En publicación»; con «Al día», «En pausa» o «Cancelada», no. Consecuencia para el dueño:
  ve «Al día · 23 tomos» en su listado, entra a la landing y ahí dice sólo «Al día» —
  **y va a creer que la landing está rota**. No lo está: son dos reglas distintas, y la del
  público es la deliberada.

#### `SeriesSchema` + `SeriesSchemaType`

Publica la serie como entidad `ComicSeries` dentro del grafo JSON-LD que Rank Math ya emite.

- **Ficheros:** `src/Modules/SeriesSchema/SeriesSchema.php`, `SeriesSchemaType.php`
- **Cuándo actúa:** `rank_math/json_ld` @**91**.
- **Lee:** la misma instancia de `SeriesFacts` que el bloque visible —el JSON-LD y el HTML no
  pueden contradecirse— más `rank_math_robots` y el term meta `_akb_serie_schema_type`.
  **Escribe:** nada.
- **Dónde lo opera el dueño:** **en ninguna parte** para `_akb_serie_schema_type`. Es una
  omisión conocida, ver *Reglas de negocio*.
- **Qué ve el cliente:** nada directo; lo ven Google y los asistentes de IA.
- **Cuatro guardas:** sólo archive de serie, sólo página 1 (`:251-253`), sólo si no está en
  `noindex` (`:265`) y sólo si el término está autorizado como cómic (`SeriesSchemaType.php:60-68`,
  que **falla cerrado** ante cualquier valor desconocido).
- **Lo que deliberadamente no emite:** `ItemList` (describiría 107 tomos sobre una página que
  muestra 24), `AggregateOffer` (`:359-372`; colgar «lowPrice: 9500» de la serie afirma que
  la serie cuesta $9.500 cuando comprarla entera son ~$1.037.000), `dateModified` (el dato es
  rotación de inventario, no revisión de la obra) y el estado de publicación (se buscó la
  propiedad en schema.org y no existe).

#### `SeriesEditions`

Relaciona las ediciones físicas de una misma obra sin fusionarlas: el One Piece de Ivrea y
el 3-en-1 de Planeta.

> **Deslinde: en este capítulo hay DOS parentescos y no se tocan.**
>
> | | Entre **series** | Entre **editoriales de la misma familia** |
> |---|---|---|
> | Quién lo pinta | `SeriesEditions` | `BrandLandingCallout` |
> | Sobre qué taxonomía | `pa_serie` | `product_brand` |
> | De dónde sale el vínculo | Lo **escribe el dueño**, term meta `_akb_serie_ediciones_relacionadas`, hasta 8 y siempre recíproco | Lo **deriva una tabla fija del código** (`BrandLandings`: qué marcas son «Ivrea», qué marcas son «Panini») |
> | Qué lee el cliente | «Otras ediciones en Akibara», junto al H1 de `/serie/<slug>/` | «un enlace a la edición hermana», en `/marca/<slug>/` |
> | Dónde se cambia | Productos → Atributos → Serie → editar término | En el código; sólo las **etiquetas** son editables (Akibara → Sitio → «Editoriales · Ediciones») |
>
> Se parecen porque los dos dicen «esto mismo, en otra edición», pero uno habla de la obra y
> el otro del sello que la publica. Tocar uno no mueve el otro.

- **Ficheros:** `src/Modules/SeriesEditions/SeriesEditions.php`
- **Cuándo actúa:** `akibara_gp_series_hero_after_title` @10 (hook **del tema**);
  `pa_serie_add_form_fields` / `pa_serie_edit_form_fields` @10 y @20;
  `created_pa_serie` / `edited_pa_serie` @10 y `edited_pa_serie` @100;
  `added_term_meta` / `updated_term_meta` / `deleted_term_meta` @100;
  `edited_term_taxonomy` @100; `set_object_terms` / `deleted_term_relationships` @100;
  `pre_delete_term` @10; `litespeed_ucss_whitelist` @20.
- **Lee y escribe:** el term meta `_akb_serie_ediciones_relacionadas` (array de hasta 8
  `term_id` de `pa_serie`), **en los dos términos o en ninguno**.
- **Dónde lo opera el dueño:** Productos → Atributos → Serie → editar término → «Ediciones
  relacionadas», con **casillas**, no un desplegable múltiple —que borraba relaciones con un
  clic accidental y, como la relación es recíproca, las borraba en los dos sentidos sin aviso.
- **Qué ve el cliente:** «Otras ediciones en Akibara» junto al H1. **Con otro tema, no se
  pinta.**

#### `SeriesRelatedGuide`

Cuelga una entrada del blog de una serie y la muestra como tarjeta antes de la rejilla.

- **Ficheros:** `src/Modules/RelatedGuides/SeriesRelatedGuide.php`
- **Cuándo actúa:** `pa_serie_add_form_fields` / `pa_serie_edit_form_fields` @10;
  `created_pa_serie` / `edited_pa_serie` @10;
  `manage_edit-pa_serie_columns` / `manage_pa_serie_custom_column` @10;
  `wp_enqueue_scripts` @10; `woocommerce_before_shop_loop` @**3**;
  `post_updated` / `transition_post_status` / `before_delete_post` @10; `shutdown` @`PHP_INT_MAX`.
- **Lee y escribe:** el term meta `_akb_related_guide_post_id`.
- **Dónde lo opera el dueño:** el formulario del término y la columna «Guía» del listado.
- **Qué ve el cliente:** la tarjeta de la guía, **sólo en la URL limpia** (sin facetas, sin
  paginar). Los parámetros de campaña **sí** se permiten (`:356-379`), porque esconder el
  contexto justo al tráfico de anuncios era el defecto anterior.

### Dominio 2 — Las editoriales

#### `BrandsDirectory` (bootstrap)

Cablea los once componentes del dominio de editoriales.

- **Ficheros:** `src/Modules/BrandsDirectory/BrandsDirectory.php`
- **Cuándo actúa:** `wp_enqueue_scripts` @10; `akibara_brands_directory_assets` @10.
- **Aviso de lectura:** su cabecera (`:16-17`) afirma «Single source of truth = pwb-brand».
  **Es falso para el código publicado:** todo el módulo usa `product_brand`. La taxonomía
  `pwb-brand` sólo reaparece en el tema (`themes/akibara-gp/inc/enqueue.php:209`), y ahí se
  documenta correctamente como **retirada**, con sus URLs antiguas sostenidas por
  redirecciones de Rank Math. Es el fichero que un lector nuevo abre primero.

#### `BrandRegion` + `RegionMeta` + `BrandFeatured`

De qué país es cada editorial, y si aparece destacada en el inicio.

- **Ficheros:** `BrandRegion.php`, `RegionMeta.php`, `BrandFeatured.php`
- **Cuándo actúa:** `product_brand_add_form_fields` / `product_brand_edit_form_fields` @10;
  `created_product_brand` / `edited_product_brand` @10;
  `manage_edit-product_brand_columns` / `manage_product_brand_custom_column` @10.
- **Lee y escribe:** los term meta `akibara_region` (`argentina` | `espana` | `otros`) y
  `akb_brand_featured` (`'1'`).
- **Dónde lo opera el dueño:** Productos → Marcas → editar marca, con un desplegable y una
  casilla, y una columna que dice si la región es manual o automática.
- **Qué ve el cliente:** la insignia de país y el grupo bajo el que aparece la editorial.
- **La cascada** (`BrandRegion.php:137-158`): el valor que eligió el dueño → el sufijo del
  slug (`-argentina`, `-espana`) → un mapa de slugs antiguos → pistas en el nombre → «Otros».
  Las pistas de nombre (`:105`) incluyen `'panini'` e `'ivrea'` dentro de la región **España**,
  así que un término nuevo llamado «Ivrea» a secas se clasificaría como española.

#### `BrandsDirectory\Shortcode` + `HomeCarousel`

`[akibara_brands_grid]`, la página `/editoriales/`; y `[akibara_brands_home]`, la vitrina
capada del inicio.

- **Ficheros:** `Shortcode.php`, `HomeCarousel.php`
- **La URL de `/editoriales/` se resuelve (R3-editoriales-hub, 1.116.27).** `Services\BrandsHubUrl::url()` busca la
  página publicada que CONTIENE `[akibara_brands_grid]` y cae al slug sólo si no la encuentra — gemela de
  `SeriesHubUrl`. La usan `HomeCarousel`, `MenuInjector` (dos sitios), `Shortcode::hub_url()` y el manifiesto
  `llms.txt`; antes cada uno hacía su propio `get_page_by_path( 'editoriales' )`.
- **Cuándo se vacían las dos cachés (R2-c3e7997b, 1.116.26).** Además de crear/editar/borrar una
  editorial: cuando un producto **entra o sale de una marca** (`set_object_terms` con 6 argumentos,
  comparando lo que queda con lo que había —WordPress dispara ese hook en cada «Actualizar» aunque
  nada cambie, y sin comparar cada guardado purgaba `/editoriales/`—, y `deleted_term_relationships`)
  y cuando un producto **se publica, se despublica o va a la papelera** (`transition_post_status`):
  eso mueve el `count` de la marca sin pasar por `set_object_terms`. La regla «exactamente uno de los
  dos estados es `publish`» vive en `Utilities\ProductVisibility::changed()`, fuente única que también
  usan `SeriesDirectory\Shortcode` y `SiteSpeed\SeriesCacheTags`. Antes una editorial nueva tardaba
  hasta 12 h en aparecer en la vitrina del inicio.
- **Cuándo actúa:** los dos shortcodes; `edited_product_brand` / `created_product_brand` /
  `delete_product_brand` @10; `shutdown` @`PHP_INT_MAX`.
- **Lee:** `BrandRegion::for_term()`, el term meta nativo `thumbnail_id` (el logo) y
  `$brand->count`. **Escribe:** la option `akb_brands_grid_gen` (contador entero, autoload) y
  los transients `akb_brands_grid_<generacion>_<md5>` (1 h) y
  `akibara_home_carousel_brands_v4` (12 h).
- **Dónde lo opera el dueño:** en ninguna parte directamente.
- **Qué ve el cliente:** la rejilla de editoriales agrupada por país.
- **Por qué un contador y no un `DELETE`:** el `DELETE` por SQL de transients que había antes
  **no podía acertar jamás**, porque en producción hay caché de objetos persistente y los
  transients nunca tocan `wp_options` (medido el 2026-08-23). La purga de la página se
  resuelve por la página real con slug «editoriales» y sólo cae al literal `/editoriales/` si
  no existe (`:152-167`) — **resuelve el permalink, no la independencia de slug**.
- **El único riesgo de página caída del dominio de editoriales:** en el marcado de
  emergencia, `fallback_markup()` escribe `esc_url( get_term_link( $brand ) )` (`:280`) **sin
  comprobar el resultado**. `get_term_link()` devuelve un `WP_Error` cuando el término no
  resuelve (una taxonomía sin reglas de reescritura, un término borrado a medias), y
  `esc_url()` acaba llamando `ltrim()` sobre ese objeto, que en PHP 8 es un `TypeError`: la
  página entera muere. Es el **único** `esc_url( get_term_link( … ) )` sin guarda
  `is_wp_error()` de toda el área. No se ha disparado nunca en producción —hace falta que la
  rejilla caiga al marcado de emergencia *y* que un término no resuelva— pero está a una
  línea de distancia.

#### `MenuInjector` + `MenuItemFields`

Convierten un elemento del menú marcado con una clase en un megamenú generado solo.

- **Ficheros:** `MenuInjector.php`, `MenuItemFields.php`
- **Cuándo actúa:** `wp_nav_menu_objects` @10; `wp_nav_menu_item_custom_fields` @10;
  `wp_update_nav_menu_item` @10.
- **Lee y escribe:** el postmeta nativo `_menu_item_classes`. **No inventa una fuente de datos
  nueva:** lee y escribe las mismas clases CSS.
- **Dónde lo opera el dueño:** Apariencia → Menús, bloque «Megamenú Akibara», con casillas de
  nombre humano en vez de teclear clases exactas en un campo escondido tras «Opciones de
  pantalla».
- **Qué ve el cliente:** las landings de familia arriba, las marcas agrupadas por país debajo
  y un «Ver todas →» al final.

#### `BrandLandings` + `BrandFamilyLanding` + `BrandLandingCallout` + `BrandLandingSchema`

Las páginas paraguas `/ivrea/` y `/panini/`, y el conmutador entre ediciones hermanas.

- **Ficheros:** `BrandLandings.php`, `BrandFamilyLanding.php`, `BrandLandingCallout.php`,
  `BrandLandingSchema.php`
- **Cuándo actúa:** `add_shortcode akibara_brand_family`; `wp_enqueue_scripts` @10;
  `woocommerce_before_shop_loop` @**3** (el conmutador); `litespeed_ucss_whitelist` @20;
  `rank_math/json_ld` @**92**.
- **Lee:** la tabla familia → marcas del código y las options `akb_brand_landing_*_menu_label`
  y `akb_brand_landing_*_callout_label`. `Plugin::front_read_options()` las calienta en una
  sola consulta, porque el menú las lee en **todas** las páginas.
- **Dónde lo opera el dueño:** Akibara → Sitio → «Editoriales · Ediciones» para las etiquetas.
- **Qué ve el cliente:** `/panini/` con una tarjeta por edición y la tabla de comparación; y,
  en `/marca/panini-argentina/`, un enlace a la edición hermana con el número de productos de
  cada una para decidir sin navegar.
- **Por qué existen:** una editorial que publica desde dos países vive en dos términos, y para
  Google esos dos archives **compiten por la misma búsqueda**. Medido el 2026-08-18, «ivrea
  chile» devolvía `/marca/ivrea-argentina/` en posición 13,6 y `/marca/ivrea-espana/` en la
  21,1: 170 impresiones y **cero clics** entre las dos.
- **Detalles:** `BrandLandings` resuelve la URL **por slug de página, nunca por ID** (los IDs
  incrustados producen ramas muertas silenciosas cuando la página se recrea); el conmutador
  sólo aparece con dos o más ediciones resolubles (`BrandLandingCallout.php:178`), porque con
  una sola no hay a dónde cambiar.

#### `EditionFacts` + `EditionCopy` + `EditionsPanel`

Lo que la tienda sabe de cada edición: tres hechos que escribe el dueño y dos que se calculan.

- **Ficheros:** `EditionFacts.php`, `EditionCopy.php`, `EditionsPanel.php`
- **Cuándo actúa:** `EditionFacts` en `woocommerce_product_set_stock_status` @10,
  `woocommerce_update_product` @10 y `shutdown` @`PHP_INT_MAX`; `EditionsPanel` en
  `admin_post_akb_editions_save` @10 y `admin_post_akb_editions_restore` @10.
- **Lee y escribe:** los term meta `akibara_edition_idioma`, `akibara_edition_formato`,
  `akibara_edition_numeracion` (≤140 caracteres, sin HTML) y `akibara_edition_series` (CSV de
  hasta 6 `term_id`); el transient `akb_edition_facts_<term_id>` (12 h); el transient de aviso
  `akb_editions_notice_<user_id>` (60 s); y la option `akb_editions_backup` (hasta 3
  snapshots). Los textos del cliente viven en options `akb_copy_editions_*` y `akb_copy_edition_*`.
- **Dónde lo opera el dueño:** Akibara → Sitio → «Editoriales · Ediciones» para los hechos y
  las series; Akibara → Textos y Copys para los textos.
- **Qué ve el cliente:** la tarjeta de edición con idioma, formato, numeración, N productos,
  «Desde $X» y hasta 6 series destacadas con su portada.
- **Dos decisiones de diseño:** los hechos se escriben como **term meta directo, nunca con
  `wp_update_term`** (`:205-229`), para no disparar la reindexación de los 856 productos de
  Ivrea Argentina; y `EditionCopy` sustituye el marcador `%s` con `str_replace` y **jamás con
  `sprintf`** (`:89-99`), porque un «%» suelto escrito por el dueño sería un error fatal.
- **Hueco medido:** `EditionFacts::register()` (`:62-63`) sólo escucha el cambio de stock y el
  guardado. **No** escucha `woocommerce_delete_product`, `woocommerce_trash_product` ni
  `before_delete_post`, que sí escuchan sus hermanas `HomeDestacados` (`:77-78`) y
  `PopularProducts`. Borrar o mandar a la papelera un tomo deja el conteo y el «Desde $X»
  hasta **12 horas** obsoletos, y la portada de la serie destacada puede apuntar a un producto
  que ya no existe.

![Caso: configurar la landing de una editorial](diagramas/03-business-catalogo-y-series-caso-landing-editorial.mmd)

### Dominio 3 — El orden, las vitrinas y las portadas

#### `CatalogOrder` + `Interleaver`

Calculan el orden «Destacados» del catálogo escribiendo el campo `menu_order` de cada
producto.

- **Ficheros:** `src/Modules/CatalogOrder/CatalogOrder.php`, `Interleaver.php`
- **Cuándo actúa:** `transition_post_status` @10; `admin_post_akibara_catalog_order_recompute` @10;
  `akibara_catalog_order_recompute` @10; `akibara_catalog_order_daily` @10; `init` @**20**
  (para agendar la diaria).
- **Lee:** todos los productos publicados con su serie, su stock y su orden actual, en una
  consulta (`:397-408`). **Escribe:** `wp_posts.menu_order` en lotes de 200 y sin pasar por
  `wp_update_post` —el docblock (`:376-378`) habla de 1.466 guardados, la cifra del catálogo
  de entonces; hoy son 1.568 y el argumento es el mismo: cada `wp_update_post` dispararía el
  reindexado de esa fila—; y la option `akb_catalog_order_last_run` (`{time, rows, applied}`).
- **Dónde lo opera el dueño:** Akibara → Venta → «Vitrina»
  (`admin.php?page=akibara-panel&tab=catalog_order`): el estado de la última corrida y un
  botón de recálculo. Y hay **dos** comandos de consola, no uno (`:73-79` y `:81-96`):

  ```bash
  wp akibara-catalog-order status      # cuándo fue la última corrida y qué escribió
  wp akibara-catalog-order recompute   # recalcula AHORA, sin panel y sin Action Scheduler
  ```

  El segundo es el que sirve cuando el panel no ayuda (ver el aviso de abajo): no encola,
  ejecuta, y responde con el número de productos actualizados.
- **Qué ve el cliente:** la primera página del catálogo con ~24 series distintas en vez de 15
  tomos seguidos de la misma.
- **Cómo reparte** (`Interleaver.php:54-80`): agrupa por serie, ordena las series por su tomo
  **vendible** más nuevo, dentro de cada serie pone lo vendible antes que lo agotado, y
  reparte **por rondas** —el tomo más nuevo de cada serie, luego el segundo de cada serie, y
  así.
- **Cuándo corre:** una vez al día a las **05:00 hora de Chile** (`DAILY_HOUR = 5`,
  `CatalogOrder.php:45`), calculada con el calendario de la zona y **no** sumando desfases,
  porque el cambio de horario de septiembre y abril rompe eso (`next_daily_timestamp()`,
  `:174-188`); se re-ancla si la acción se desvía más de 15 minutos. Y 120 segundos después
  de cualquier alta o baja de catálogo. **No** corre en cada venta: medido en producción el
  2026-08-23, un recálculo reescribe 1.122 de 1.568 `menu_order`, así que colgarlo de cada
  venta sería reordenar el 71,6 % del catálogo —y purgar el borde— una vez por compra
  (`:106-114`). Un cambio de stock **tampoco** lo dispara: la guarda está en
  `on_status_change()` (`:218-227`, con su docblock en `:209-217`), que sale si la
  transición no entra ni sale de `publish`.
- **La diaria sólo se agenda desde el backend.** `maybe_schedule_daily()` (`:122-164`) cuelga
  de `init` @20, pero lo primero que hace es salir si la petición no es de admin, de WP-Cron
  o de WP-CLI (`:123-131`). Es deliberado —comprobar el agendado cuesta una consulta a las
  tablas de Action Scheduler y no se quiere pagar en cada visita del front—, pero tiene una
  consecuencia que desconcierta: **en un entorno local donde nadie entra al panel y el cron
  no corre, la diaria no se agenda nunca**, y el lector concluye que el código está roto. No
  lo está: basta abrir `/wp-admin/` una vez.
- **Dos detalles que el propio código contradice:** la guarda contra corridas duplicadas usa
  `as_next_scheduled_action()` (`:252-253`) y su docblock (`:230-232`) afirma seguir «el mismo
  patrón coalescente que usa `SeriesFollow\DigestRunner`», **que ya no es cierto**; y el
  contador de «reordenados» suma `count( $ids )` (`:464`) en vez de las filas que el `UPDATE`
  (`:451-453`) afectó, contra lo que promete su propio docblock: «El retorno cuenta lo
  APLICADO, no lo propuesto» (`:381`). Los dos están en *Reglas de negocio*.
- **Y una consecuencia de la primera que el dueño sí ve.** Cuando esa guarda se dispara,
  `schedule_recompute()` devuelve `ya-encolada` (`:255`) y el panel imprime **«Ya había un
  recálculo en cola; no se duplicó»** (`:321`). Pero `as_next_scheduled_action()` también
  cuenta la acción **en curso**: si el dueño pulsa el botón mientras una corrida se está
  ejecutando, ve ese aviso tranquilizador aunque no quede nada pendiente, y su cambio no va a
  entrar hasta las 05:00 del día siguiente. Es la única pantalla que tiene para esto, y en
  ese caso le está mintiendo. La salida es `wp akibara-catalog-order recompute`.
- **El coste de la consulta, medido por lectura.** En `recompute()` (`:397-408`) el predicado
  `tt.taxonomy = 'pa_serie'` va en el `LEFT JOIN`, no en el `WHERE`. El resultado es
  **correcto** —`MIN( tt.term_taxonomy_id )` ignora los `NULL` y el `GROUP BY p.ID` colapsa
  las filas de más—, pero antes del `GROUP BY` cada producto se multiplica por **todas** sus
  relaciones de término, no sólo por las de serie: categoría, marca, autor, visibilidad,
  etiquetas. Con 1.568 productos y media docena de términos cada uno, la consulta intermedia
  es del orden de diez mil filas en vez de mil quinientas. Es una de las razones por las que
  esto corre una vez al día y no en cada venta.

#### Bootstrap `akibara-business.php` — padding de títulos y etiquetas del selector de orden

- **Ficheros:** `akibara-business.php`
- **Cuándo actúa:** `wp_insert_post_data` @10 (el padding);
  `woocommerce_catalog_orderby` @10 y `woocommerce_get_catalog_ordering_args` @10 (el selector);
  `woocommerce_shortcode_products_query` @10 (la inyección de `_stock_status=instock` en
  «Recién llegados» del inicio, `:278-299`).
- **Lee y escribe:** `wp_posts.post_title`, para dejar el número de tomo con dos dígitos
  («Gannibal 1» → «Gannibal 01», `:425-447`). **El slug no se toca**, para no cambiar URLs.
  Lee las options `akb_copy_order_*`.
- **Dónde lo opera el dueño:** Akibara → Textos y Copys → «Ordenamiento del catálogo».
- **Qué ve el cliente:** las etiquetas del selector de orden, y un orden dentro de la serie que
  no se rompe al llegar al tomo 10.
- **Dos reglas del selector:** el criterio «valoración» se retira con `unset( $options['rating'] )`
  en el filtro `woocommerce_catalog_orderby` (declarado en `:321`, el `unset` en `:326`) porque
  el sitio aún no tiene reseñas; y con búsqueda activa y sin criterio elegido, la opción por
  defecto se rotula «Relevancia» (`:344`), porque `akibara-search` conserva el orden de su
  propio puntuador aunque el valor técnico siga siendo `menu_order`.

#### `HomeSeriesDestacadas`

La vitrina «Series más buscadas» de la portada.

- **Ficheros:** `src/Modules/HomeSeriesDestacadas/HomeSeriesDestacadas.php`
- **Cuándo actúa:** `add_shortcode akb_series_destacadas`;
  `update_option_akibara_home_series_enabled` / `_title` / `_slugs` @10;
  `woocommerce_update_product` / `woocommerce_product_set_stock_status` @10.
- **Lee y escribe:** las options `akibara_home_series_enabled` (yes/no, por defecto **yes**),
  `akibara_home_series_title` y `akibara_home_series_slugs` (un slug por línea, máx. 24); y el
  transient `akb_home_series_v1_<md5 de la lista>` (6 h).
- **Dónde lo opera el dueño:** Akibara → Sitio → «Series en la portada».
- **Qué ve el cliente:** tarjetas con la portada de la serie, su nombre y su número de tomos, y
  un «Ver todas →».
- **Tres cosas medidas que conviene saber:** cuenta los tomos con `(int) $term->count`
  (`:255` y `:266`), el contador nativo que **sí** incluye los ocultos del catálogo; el «Ver
  todas →» lleva `home_url( '/series/' )` escrito a mano (`:169`), o sea que si el dueño
  moviera el directorio de página, este enlace se quedaría atrás en silencio; y la clave del
  transient es `md5(option_slugs)` a secas (`:295-297`), **sin el atributo `count` del
  shortcode**, aunque lo que se cachea es el array ya recortado a `$count` (`:152`).
- **Qué vive el dueño con eso último, dicho en claro:** si edita la portada y cambia
  `[akb_series_destacadas count=8]` por `count=12`, **no pasa nada durante seis horas**. La
  clave de caché no cambió, así que se sirve la lista vieja de 8, y la conclusión natural es
  «el bloque está roto». No lo está; hay que esperar a que caduque el transient o rotar la
  clave cambiando un slug de la lista. Su gemela `HomeDestacados` **sí** versiona la clave
  con `count`, `columns` y `max_per_serie` (`HomeDestacados.php:230-236`), así que ahí el
  mismo gesto funciona al instante. Son dos piezas casi idénticas con comportamientos
  opuestos ante el mismo cambio.

#### `HomeDestacados`

Las dos vitrinas de producto del inicio: `[akb_preventas_destacadas]` y `[akb_destacados]`.

- **Ficheros:** `src/Modules/HomeDestacados/HomeDestacados.php`
- **Cuándo actúa:** los dos shortcodes; `woocommerce_new_product`, `woocommerce_update_product`,
  `woocommerce_delete_product`, `woocommerce_trash_product`,
  `woocommerce_product_set_stock_status` y `woocommerce_variation_set_stock_status` @10.
- **Lee y escribe:** la option `akb_home_destacados_generation` (marca de tiempo, autoload) y
  los transients `akb_preventas_destacadas_v2_*` y `akb_destacados_v1_*` (1 h). Delega el
  pintado en el `[products]` nativo de WooCommerce con `orderby=post__in`.
- **Dónde lo opera el dueño:** pegando los shortcodes en el editor de la portada, con atributos
  `count`, `columns` y `max_per_serie`.
- **Qué ve el cliente:** ocho productos de ocho series distintas en vez de ocho tomos de la
  misma.
- **Por qué existe:** el `[products]` nativo no diversifica por serie. En un caso real del
  2026-02-24, **ocho de ocho** huecos del inicio eran «The Climber». Aquí se impone un tope por
  serie —2 para «a pedido», 1 para el resto (`:106-108`)— y, si la categoría tiene menos series
  que huecos, se relaja **sólo lo justo** para no dejar una celda vacía (`:353-370`).
- **Diferencia entre las dos gemelas:** `render_destacados()` excluye lo oculto del catálogo con
  una `tax_query` (`:234-237`); `render_a_pedido_destacados()` (`:126-145`) **no la trae**, y
  WooCommerce no cubre el hueco porque `WC_Shortcode_Products::set_visibility_query_args()` no
  añade ninguna cláusula para `visibility => 'visible'`.

#### `PopularProducts`

Los más vendidos que aparecen en el 404, en el carrito vacío y en «sin resultados».

- **Ficheros:** `src/Modules/PopularProducts/PopularProducts.php`
- **Cuándo actúa:** filtro `akibara/popular_product_ids` @10;
  `woocommerce_product_set_stock_status` / `woocommerce_variation_set_stock_status` @10;
  `before_delete_post` @10.
- **Lee:** `wp_posts`, `wp_term_relationships` y el postmeta `_stock_status`.
  **Escribe:** los transients `akb_popular_cross_sell_ids_v3_<limite>` (1 h; 2 minutos si la
  lista vino vacía).
- **Dónde lo opera el dueño:** en ninguna parte.
- **Qué ve el cliente:** una fila de best-sellers donde otra cosa habría dejado un hueco.
- **Cómo lo consume el tema, verificado en el paquete publicado:** el filtro se invoca en
  **un solo sitio**, el ayudante `akibara_get_popular_cross_sell_ids()`
  (`themes/akibara-gp/inc/performance.php:300-301`), siempre con tamaño **4**. Ese ayudante
  lo usa **una sola plantilla**, `template-parts/empty/cross-sell.php:44`, que a su vez se
  incluye desde las tres superficies que el capítulo nombra: `404.php:97`,
  `woocommerce/cart/cart-empty.php:76` y `woocommerce/loop/no-products-found.php:100`. O sea:
  tres superficies, un solo tamaño.
- **Sin el plugin, la fila NO se pinta.** El tema no tiene un «fallback propio» que muestre
  otra cosa: si la lista viene vacía fuerza `post__in => array( 0 )`
  (`cross-sell.php:45-47`), que no devuelve ningún producto y hace desaparecer la sección
  entera. El comentario del propio tema lo dice —*fallback to prevent showing all*—: existe
  para **no** acabar mostrando el catálogo completo, no para mostrar un sustituto.
- **Dos detalles:** incluye lo **reservable** (`instock` y `onbackorder`, `:126-132`), porque el
  filtro estricto a «en bodega» dejaba fuera los 422 productos «A pedido», el 27 % del catálogo,
  que se venden igual (`:122-125`); y excluye `exclude-from-catalog` y `exclude-from-search`,
  filtrando `'field' => 'name'` (`:114-120`) — ver el aviso sobre renombrar términos, más
  abajo. Su `flush()` borra los transients de una **lista fija** de tamaños
  —`1, 2, 3, 4, 5, 6, 8, 12` (`:145-149`)— así que un tamaño nuevo pedido por el tema nunca se
  invalidaría al agotarse o borrarse un producto. **Hoy eso no muerde:** el único tamaño que
  el tema pide es 4, y 4 está en la lista. Lo que sí está desactualizado es el comentario que
  justifica la lista (`:139-141`), que afirma que el tema llama «con 4 (404, carrito vacío) y
  6 (sin resultados)»; en el tema publicado las tres superficies piden 4. Es una trampa
  latente, no un defecto vivo.

> **Un riesgo que no es de esta pieza, sino de toda el área: renombrar el término de
> visibilidad.** Cinco lugares identifican «lo oculto del catálogo» por el **nombre** del
> término, no por su slug: `SeriesCover.php:86`, `HomeDestacados.php:235`,
> `PopularProducts.php:117` y, en SQL crudo, `EditionFacts.php:292` y `:331`
> (`tv.name = 'exclude-from-catalog'`). Las dos consultas propias del área lo identifican
> por **slug**: `SeriesCatalogEligibility.php:211` y `SeriesFacts.php:1479`
> (`vt.slug = %s`).
>
> Consecuencia: si alguien renombra ese término desde el panel de WordPress —el nombre es
> editable, el slug no cambia solo— se caen **a la vez** la portada de serie, la vitrina del
> inicio, los más vendidos y las tarjetas de edición, mientras el directorio de series y el
> bloque de datos siguen funcionando **perfectamente**. Ese «funciona la mitad» es lo que
> hace el diagnóstico endiablado, y es la razón de mencionarlo aquí y no dentro de una sola
> ficha.

#### `SeriesCover` + `VolumeTitle`

Qué portada representa a una serie, y qué número de tomo lleva un título.

- **Ficheros:** `src/Services/SeriesCover.php`, `src/Services/VolumeTitle.php`
- **Cuándo actúa:** sin hooks; los llaman `HomeSeriesDestacadas`, `EditionFacts` y el ordenador
  de listas de packs.
- **Lee:** productos publicados y no ocultos, opcionalmente sólo `instock`. **Escribe:** nada.
- **Qué ve el cliente:** la imagen que identifica a la serie en la vitrina del inicio y en las
  tarjetas de edición de `/ivrea/` y `/panini/`.
- **La regla escrita:** la portada del tomo de número más bajo, prefiriendo los que tienen stock
  (`for_tax_query()`, `:67-74`).
- **Dos límites medidos:** la consulta `tomo_ids()` (`:90-99`) se arma **sin ninguna clave
  `orderby`**, así que `WP_Query` cae a `post_date DESC` y `posts_per_page` vale `MAX_TOMOS = 40`
  — en una serie de más de 40 tomos se traen los 40 publicados más recientemente y se elige «el
  más bajo» sólo entre ésos; el comentario de la constante (`:28`) afirma lo contrario. Y el
  fallback `$best_id = $ids[0]` (`:46`) hereda ese mismo orden: cuando **ningún** título de la
  serie termina en número entero —una edición integral, una serie de artbooks—, `VolumeTitle`
  devuelve `null` para todos y la portada acaba siendo la del producto publicado más
  recientemente, que cambia sola cada vez que entra un tomo.
- **`VolumeTitle` es deliberadamente estricto:** devuelve «no sé» para los decimales (`1.5`),
  los omnibus y los especiales, y esos productos quedan al final de todas las listas.

### Dominio 4 — «Sigue esta serie»

14 clases y una idea grande: **la suscripción no se almacena**. Un cliente sigue la serie S si
(a) compró al menos un producto de S en un pedido pagado y (b) no hizo opt-out de S. La ventaja
de que sea derivado es concreta: un pedido que cae de `processing` a `cancelled` deja de
producir seguimiento en la lectura siguiente, sin evento de baja ni fila que limpiar. **Lo
único que se guarda es la negación.**

> **La tabla y sus seis estados.** La cola vive en una tabla propia,
> `{prefijo}akb_series_follow_queue`. El *prefijo* es el que WordPress antepone a todas sus
> tablas en este sitio; el resto del capítulo escribe `wp_posts` y `wp_options`, así que la
> tabla es **`wp_akb_series_follow_queue`** (para confirmarlo sin adivinar: `wp db prefix`).
>
> Su columna `status` tiene **seis** valores legales, todos declarados en
> `DigestQueue.php:57-62`:
>
> | Estado | Qué significa | Quién lo escribe |
> |---|---|---|
> | `pending` | Encolado, todavía no se avisó | `DigestRunner` al escanear un tomo nuevo |
> | `sent` | Se envió el correo que le dio **bloque propio** a este tomo | El `UPDATE` atómico tras enviar |
> | `coalesced` | El correo **nombró** el tomo dentro del bloque de un tomo más nuevo de su serie, pero no le dio ficha, ni precio, ni botón. Ni «enviado» ni «descartado» | `DigestRunner` al agrupar |
> | `dropped` | Se descartó por una decisión sobre **el cliente**, con motivo: `DROP_OPTOUT` (se dio de baja, `DigestRunner.php:410`), `DROP_OWNED` (ya tiene el tomo, `:447`) | `DigestQueue::drop()` |
> | `expired` | Llevaba más de 60 días pendiente. Dos meses tarde no es un aviso | `DigestQueue::expire_older_than()` |
> | `failed` | Tres intentos de envío fallidos. Se drena en vez de reintentar para siempre | `DigestRunner` |
>
> Los cinco últimos son **terminales**: ninguno vuelve a `pending`. Quien abra la tabla va a
> ver `dropped`, que es el estado que falta en casi toda la documentación previa.
>
> **Y el tercer motivo de descarte no deja fila:** con `DROP_UNPUBLISHED` —el tomo se
> despublicó entre el encolado y la corrida— la fila se **borra** con un `DELETE`, no se
> marca (`DigestQueue::drop()`, `:276-283`). Es a propósito: un tomo que se despublica y se
> vuelve a publicar sí debe poder avisarse, y con la fila marcada la restricción única lo
> impediría para siempre.

#### `SeriesFollow` (raíz) + `Install`

- **Ficheros:** `src/Modules/SeriesFollow/SeriesFollow.php`, `Install.php`
- **Cuándo actúa:** `woocommerce_email_classes` @10; `woocommerce_admin_field_akb_sf_pending` @10;
  `Install` en el hook de activación y con guarda de versión en `register()`.
- **Lee y escribe:** las options `akb_sf_enabled` (yes/no, por defecto **no**),
  `akb_sf_weekly_cap_days` (1-30, por defecto 7), `akb_sf_digest_delay_hours` (0-72, por defecto
  6), `akb_sf_max_series_per_email` (1-20, por defecto 5) y `akb_sf_db_version`. `Install` crea
  la tabla `{prefijo}akb_series_follow_queue` y **sólo sella la versión si la sonda confirma que
  la tabla existe** —`dbDelta` falla en silencio ante permisos, motor o largo de clave, y sellar
  a ciegas envenenaría el módulo para siempre. Si no la pudo crear, lo deja escrito en el
  registro (`Install.php:80`).
- **Su grupo de Action Scheduler es `akibara-series-follow`** (`SeriesFollow.php:56`). Es el
  valor que hay que escribir en el filtro de **Herramientas → Acciones programadas** para ver
  si la cadena del digest sigue viva; sin él, esa pantalla no se puede acotar.
- **Dónde lo opera el dueño:** Akibara → Venta → «Sigue esta serie» (ajustes y cola),
  `admin.php?page=akibara-panel&tab=series_follow`.
- **Detalle medido:** el encabezado calcula «próxima corrida» con
  `as_next_scheduled_action( HOOK_DIGEST, null, AS_GROUP )` (`SeriesFollow.php:265`), la misma
  función que `DigestRunner` documenta como no fiable porque cuenta las acciones **en curso**.

#### `PublishListener`

Escucha la publicación y **encola y se va**.

- **Ficheros:** `PublishListener.php`
- **Cuándo actúa:** `transition_post_status` @10 y `set_object_terms` @10.
- **Escribe:** nada; agenda `akb_sf_scan_product` a **+120 s**.
- **Por qué +120 s y cero consultas:** viaja encima de `set_object_terms`, el evento más caro de
  la casa —guardar cualquier término dispara el reindexado del buscador— y el importador escribe
  el post **antes** de asignarle los términos.

#### `SeriesResolver` · `FollowerFinder` · `DigestQueue` · `DigestRunner`

- **Ficheros:** `SeriesResolver.php`, `FollowerFinder.php`, `DigestQueue.php`, `DigestRunner.php`
- **Cuándo actúa:** `DigestRunner` en `akb_sf_scan_product` @10, `akb_sf_digest_run` @10 y
  `update_option_akb_sf_enabled` @10.
- **Lee:** `wc_order_product_lookup`, `wc_order_stats`, `wc_customer_lookup` y **el almacén
  canónico de pedidos** (ver el recuadro de abajo), reusando de `CustomerCollection` la
  definición de «lo tenés» en vez de reescribirla. **Escribe:** la tabla
  `wp_akb_series_follow_queue` y el user meta `_akb_sf_last_sent`.

> **Qué es «el almacén canónico de pedidos».** WooCommerce puede guardar los pedidos en dos
> sitios distintos, y sólo uno manda a la vez. El modo antiguo los guarda como entradas de
> WordPress, en `wp_posts`. El moderno, llamado **HPOS** (*High-Performance Order Storage*),
> los guarda en tablas propias: `wp_wc_orders`. «Canónico» quiere decir «el que manda hoy».
> El código no lo da por supuesto: pregunta en tiempo de ejecución con
> `OrderStorage::uses_hpos()` y arma dos `JOIN` distintos según la respuesta
> (`CustomerCollection.php:307-330`). Las otras tres tablas —`wc_order_product_lookup`,
> `wc_order_stats`, `wc_customer_lookup`— son las de **Analytics** de WooCommerce, un resumen
> aparte que se llena solo; existen porque son rápidas, y el `JOIN` contra el almacén
> canónico está precisamente para exigir que la fila de Analytics corresponda a un pedido
> **todavía vivo** (`:298-306`).
- **Dónde lo opera el dueño:** la tabla «Qué hay en cola» y el número de la próxima corrida, en
  el panel.
- **Las cuatro decisiones que definen el módulo:** `SeriesResolver` exige **una sola** serie y un
  número inequívoco, o descarta (`:49`, `:61-64`); y desde 1.116.31 `discard_reason()` dice en el
  aviso del resumen cuál de las tres puertas cerró —serie única, número de tomo o variante—, porque el
  texto anterior culpaba a la taxonomía cuando el descarte real era una edición especial
  (`R7-descarte-mal-explicado`). `FollowerFinder` ante cualquier duda devuelve
  **null**, nunca «nadie sigue esta serie», porque convertir una caída en un conjunto vacío
  marcaría el tomo como avisado sin haber avisado. `DigestQueue` es la cola **y** la marca de
  avisado en la misma tabla, y que un tomo no se avise dos veces lo garantiza una **restricción
  única de la base** (`Install.php:135-152`), no una lectura previa que tendría carrera. Y
  `DigestRunner` **arma el correo antes de reclamar las filas** (`:10-18`, `:344-360`): al revés,
  una excepción dejaría filas marcadas como enviadas sin correo, y la restricción única prohibiría
  reencolarlas.
- **Ver cinco acciones del mismo producto en la cola es normal.** `maybe_paginate()`
  (`:711-732`) vuelve a encolar `akb_sf_scan_product` con un **cursor** en los argumentos
  cada vez que la página de compradores vino llena: una serie con miles de compradores no
  puede caber en un solo trabajador. Quien mire **Herramientas → Acciones programadas** va a
  ver el mismo `product_id` repetido con cursores distintos y va a pensar que hay
  duplicados. No los hay: es la paginación.
- **Detalle medido:** `schedule_digest()` (docblock y método, `:761-807`) pregunta por lo **pendiente** con
  `as_get_scheduled_actions( status => 'pending' )` precisamente porque `as_next_scheduled_action()`
  devuelve `true` con una acción en curso. En producción, el 2026-09-06, había **tres corridas del
  digest en toda la vida del módulo** y ninguna encadenada. El listener de re-arranque
  (`:138`, `:149-161`) escucha `update_option_akb_sf_enabled` y sólo acepta el valor literal
  `'yes'`.

#### `FollowRegistry` · `UnfollowHandler` · `CollectionToggle` · `GlobalToggle`

La baja, por los tres caminos por los que se puede pedir.

- **Ficheros:** `FollowRegistry.php`, `UnfollowHandler.php`, `CollectionToggle.php`, `GlobalToggle.php`
- **Cuándo actúa:** `template_redirect` @**1** (`UnfollowHandler` y `GlobalToggle`);
  `rest_api_init` @10 (`CollectionToggle`, que registra `POST /wp-json/akibara/v1/series-follow`
  con `permission_callback` propio: sesión iniciada **y** nonce de REST válido).
- **Escribe:** los user meta `_akb_sf_optout` (array de `term_id`) y `_akb_sf_optout_all` (`'1'`).
  La higiene de datos personales sale gratis con el borrado del usuario.
- **Qué ve el cliente:** el enlace de baja de cada correo, y el interruptor de Mi cuenta →
  Colección.
- **Las tres reglas:** **GET confirma, POST muta** (`UnfollowHandler.php:118-133`), porque los
  escáneres de enlaces corporativos y el prefetch del buzón hacen GET a todos los enlaces de un
  correo; un token inválido devuelve la **misma** página de «Listo» sin tocar nada (`:22-27`,
  `:126-136`), porque la respuesta no puede confirmar ni negar que un cliente existe; y encender
  **una** serie levanta también la baja global, mientras que encender el global **no** deshace las
  bajas por serie, y la pantalla lo dice con un aviso aparte (`FollowRegistry.php:151-176`, `:196-206`).
- **Volver NO es del todo reversible, y conviene decirlo.** Mientras el cliente estuvo de
  baja, cada corrida del digest marcó sus filas como `dropped` con motivo `DROP_OPTOUT`
  (`DigestRunner.php:410`). `dropped` es **terminal**: no vuelve a `pending`. Así que si
  vuelve a activarse desde Mi cuenta → Colección, **los tomos que salieron durante su baja
  no se recuperan**: volverá a recibir avisos, pero de los tomos que se publiquen **desde
  ahora**. La pantalla de «Listo» dice cómo volver; no dice esto.
- **Nota verificada:** la cabecera `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
  (`Services/AkibaraEmail.php:96-97`) hace que Gmail y Outlook manden un POST; los tres parámetros
  viajan en el query string, así que el `isset( $_GET[...] )` de `:95` se cumple también en ese
  POST. Es exactamente el comportamiento que exige la RFC 8058, no un defecto.

#### `SeriesFollowEmail` + plantillas · `VolumePhrase` · `CutoffPhrase`

- **Ficheros:** `SeriesFollowEmail.php`, `templates/emails/customer-series-follow.php`,
  `templates/emails/plain/customer-series-follow.php`, `VolumePhrase.php`, `CutoffPhrase.php`
- **Lee y escribe:** la option `woocommerce_akb_sf_series_settings` (`subject`, `subject_tanda`,
  `heading`, `additional_content`, `enabled`).
- **Dónde lo opera el dueño:** WooCommerce → Ajustes → Correos → «Akibara · Novedades de tu serie».
  **Éste es el segundo interruptor**, y el panel de Akibara no informa de él.
- **El identificador del correo es `akb_sf_series`** (`SeriesFollowEmail.php:42`). De ahí sale
  el nombre de la option (`woocommerce_akb_sf_series_settings`) y de ahí sale el fragmento que
  hay que buscar en la URL para llegar al ajuste:
  `admin.php?page=wc-settings&tab=email&section=akb_sf_series`.
- **El asunto no es un texto fijo: es una plantilla con marcadores.** El valor de fábrica es
  literalmente `'Salió el tomo {tomo} de {serie}{y_mas}'` (`:77`), y el encabezado por
  defecto, `'Novedades de tu colección'` (`:87`). Si el dueño entra a cambiar el asunto y
  escribe texto plano, **pierde los datos**: sale el mismo asunto para todos. Los marcadores
  admitidos son seis (`:61-68`):

  | Marcador | Qué pone |
  |---|---|
  | `{serie}` | El nombre de la serie que abre el correo |
  | `{tomo}` | El número del tomo más nuevo de esa serie |
  | `{n_tomos}` | Cuántos tomos de esa serie trae el correo |
  | `{tomos}` | La frase de tomos ya armada («71 al 74») |
  | `{y_mas}` | El « y más» que se añade cuando hay más series en el mismo envío |
  | `{site_title}` | El nombre del sitio |

  El encabezado dice «Novedades de tu colección» y **no** hay un segundo campo de encabezado
  para las tandas, a propósito: el problema que resolvía no era el plural, era la palabra
  «siguiente» (`:84-86`).
- **Qué ve el cliente:** un bloque por serie con portada, «Kingdom — tomos 71 al 74», precio y
  estado del tomo mostrado, la fecha de corte si es reservable, un botón «Ver el tomo 74» y un
  enlace de baja de esa serie; en el pie, la baja de todas.
- **`VolumePhrase`** (`:96-110`): el rango «71 al 74» sólo si el tramo es **contiguo** y tiene 3 o
  más; con más de los que se enumeran, «entre el 71 y el 78»; si no, la lista con «y».
- **`CutoffPhrase`** no formatea ni una fecha a propósito: todo sale de
  `EstimatedDelivery\PedidoPolicy`, porque una segunda cuenta «equivalente» es lo que deja el
  correo nombrando un cierre distinto del que imprime la ficha.

![Caso: el aviso de serie](diagramas/03-business-catalogo-y-series-caso-aviso-de-serie.mmd)

### Dominio 5 — «Arma tu Pack»

El cliente elige varios tomos de una serie y recibe un descuento por cantidad.

> **Aviso de procedencia, para este dominio en particular.** Los cinco dominios de este
> capítulo se leyeron del paquete publicado, pero «Arma tu Pack» son **8.390 líneas** y la
> lectura de `BundleCouponEngine`, `DiscountEngine`, `CartSeriesCollector`,
> `OrderInstrumentation`, `PromotionPolicy` y `BypConfigController` fue **selectiva**: se
> verificaron las reglas que se enuncian aquí, no el fichero entero. Y **nada de este
> dominio se midió en vivo** —ni en el espejo ni en producción—: lo que sigue es lectura de
> código sobre un paquete congelado. Donde se cita una medición (los 347 KB de la pantalla,
> el −$10.493 contra −$7.195), la medición está **anotada en el propio código** por quien la
> hizo, con su fecha cuando la trae; no se volvió a comprobar para este capítulo.

#### `SeriesBundles` (raíz) · `Settings` · `EligibilityGuard` · `SeriesDepth`

- **Ficheros:** `SeriesBundles.php`, `Settings.php`, `EligibilityGuard.php`, `SeriesDepth.php`
- **Cuándo actúa:** `akibara_business_upgraded` @10 (estandariza tramos antiguos, con reintento si
  el bloqueo optimista falla). `akibara_business_upgraded` es un hook **propio**: el plugin lo
  dispara una sola vez, cuando detecta que su número de versión subió respecto del guardado,
  y es el sitio donde cada módulo migra sus datos (**ver capítulo 02 — núcleo**).
- **Lee y escribe:** las options `akb_series_bundles_enabled` (yes/no, por defecto **no**),
  `akb_series_bundles_byp` (JSON `{term_id: {enabled, tiers[{min,pct}]}}`),
  `akb_series_bundles_exclude_cats` y `akb_series_bundles_allow_backorder`.
- **Dónde lo opera el dueño:** Akibara → Venta → «Arma tu Pack».

#### `VolumeTierResolver` + `PromotionPolicy`

- **Ficheros:** `VolumeTierResolver.php`, `PromotionPolicy.php`
- **Reglas:** un tramo con descuento arranca en **3 tomos** (`:47`, `:154-157`) —un 2-pack no se
  regala—; los porcentajes deben ser **estrictamente crecientes** (`:159-173`); el tope de
  porcentaje es el **mismo** que el del descuento editorial, leído en tiempo de ejecución desde
  `Utilities\DiscountCeiling` (`:52-63`); el tramo es **inclusivo**, se gana desde su mínimo y
  sigue valiendo con más (`:74-101`). La política recomendada del alta en lote es 8 % desde 5
  tomos y 10 % desde 7 (`PromotionPolicy.php:49-66`), y esa alta es **aditiva**: nunca pisa lo
  configurado (`:133-170`).

#### `CartSeriesCollector` · `DiscountEngine` · `Support\Money` · `Support\Result`

- **Ficheros:** `CartSeriesCollector.php`, `DiscountEngine.php`, `Support/Money.php`, `Support/Result.php`
- **Qué hacen:** `CartSeriesCollector` es **la única rutina «carrito → serie → configuración»**.
  Existe porque esa rutina vivía copiada en cuatro archivos y las copias divergieron de verdad: el
  aviso del carrito prometía −$10.493 y el motor otorgaba −$7.195. `DiscountEngine` convierte
  porcentajes en pesos. `Money::round()` es **quien decide el redondeo**: usa
  `wc_get_price_decimals()` con fallback a 0 decimales, y `clamp_non_negative()` acota a cero.
  `Result` es un tipo inmutable `ok`/`error` con mensaje, usado por la normalización de tramos; sus
  mensajes se reciben en español literal, sin funciones de internacionalización.
- **Las reglas:** gana el mejor precio —si el precio ya filtrado es ≤ el objetivo del tramo, el
  aporte de ese tomo es **0** (`DiscountEngine.php:212-222`, `:249-258`)—; el descuento cubre **una**
  unidad de cada tomo distinto (`:265-290`); un tomo agotado no cuenta para nada
  (`CartSeriesCollector.php:44-46`, `:188-196`); y un producto que está en dos series con pack **no
  recibe descuento por ninguna** (`:90`).

#### `BundleCouponEngine`

- **Ficheros:** `BundleCouponEngine.php`
- **Cuándo actúa:** `woocommerce_coupon_discount_types` / `woocommerce_product_coupon_types` @10;
  `woocommerce_get_shop_coupon_data` @10; `woocommerce_coupon_get_items_to_apply` @10;
  `woocommerce_coupon_get_discount_amount` @10; `woocommerce_checkout_create_order_coupon_item` @10;
  `woocommerce_order_recalculate_coupons_coupon_object` @10;
  `woocommerce_checkout_order_created` / `woocommerce_store_api_checkout_order_created` @20; y el
  cálculo en `woocommerce_before_calculate_totals` prioridad **1000**, después del descuento
  editorial (prioridad 20), para leer precios ya rebajados.
- **La decisión central:** el descuento se materializa como un **cupón virtual** fabricado al vuelo
  que no existe en la base, **nunca cambiando el precio del producto** (`:4-10`), porque cambiar el
  precio rompe el cálculo del envío gratis. El cupón sólo se aplica con descuento **estrictamente
  positivo** (`:1063-1092`). Con un cupón de campaña externo aplicado, **el pack cede** (`:705-716`)
  para no regalar margen. Aporta su prefijo `akb_sb_` al filtro
  `akibara_taxonomy_discount_ignored_coupon_prefixes` para que sus cupones no se invaliden.

#### `BuilderWidget` (la ficha) y `LandingOffer` (la landing)

Las dos superficies del cliente.

- **Ficheros:** `BuilderWidget.php`, `LandingOffer.php`
- **Cuándo actúa:** `BuilderWidget` en `woocommerce_after_single_product_summary` @**5**,
  `wp_enqueue_scripts` @20, `wp_ajax_akb_sb_add_byp` / `wp_ajax_nopriv_akb_sb_add_byp` @10, y
  `save_post_product` / `deleted_post_product` / `trashed_post` / `untrashed_post` /
  `added_term_relationship` / `deleted_term_relationships` / `set_object_terms` @10 más
  `shutdown` @`PHP_INT_MAX` para la invalidación. `LandingOffer` en `template_redirect` @1,
  `woocommerce_before_shop_loop` @**40**, `woocommerce_no_products_found` @15,
  `woocommerce_after_shop_loop_item` @**8**, `wp_enqueue_scripts` @20,
  `wp_ajax(_nopriv)_akb_sb_collection_state` @10, `wp_ajax(_nopriv)_akb_sb_add_series_selection` @10
  y `litespeed_ucss_whitelist` @20.
- **Qué hace ese `template_redirect` @1, que es el único hook del capítulo capaz de cambiarle
  la URL al cliente.** `redirect_legacy_stock_filter()` (`:854-880`) manda con un **301**
  `?_disponibilidad=onbackorder` a `?_disponibilidad=a_pedido`. Existe porque los chips de la
  landing emitieron durante meses `onbackorder` —el estado **interno** de WooCommerce—
  mientras el índice del buscador sólo publica el vocabulario **comercial**: medido en
  producción el 2026-09-05 sobre `/serie/frieren/`, `a_pedido` servía 15 tomos y
  `onbackorder`, cero. Esos enlaces siguen circulando porque se compartieron. Sin el
  redirect, el cliente ve lo peor de los dos mundos: el chip «A pedido» encendido —porque el
  resaltado sí traduce— y la rejilla vacía. Es 301 a propósito, para consolidar en una sola
  URL lo que eran dos. Un redirect no documentado es media hora de depuración cuando alguien
  se pregunta por qué su URL cambió sola.
- **Escribe:** la option testigo `akb_sb_vols_cache_ver` y el transient `akb_series_vols_<md5>` (1 h).
  `BuilderWidget` lee además `akibara_fsp_carrier_threshold` (la **misma** option que la barra de
  envío gratis del carrito).
- **Qué ve el cliente:** en la ficha, una rejilla con todos los tomos de la serie, cuánto falta para
  el envío gratis y un enlace «Avísame cuando vuelva» en los agotados; en la landing, el riel
  «Completa tu colección de X» con las pastillas de tramo y los chips de disponibilidad.
- **Dos reglas de honestidad:** **sólo se anuncian tramos alcanzables**
  (`BuilderWidget.php:1030-1048`, `LandingOffer.php:612-627`) —el riel recorre el universo real de
  la serie aplicando los cuatro guardas del carrito, porque esto se publica en una página cacheada e
  indexada y prometer un tramo que el carrito nunca dará es mentir en frío—; y **el armador arranca
  vacío** (`BuilderWidget.php:700-705`): nunca casillas premarcadas con cargo.
- **Divergencia medida entre las dos:** con el interruptor «Incluir tomos a pedido» apagado (que es
  el valor **por defecto**, `Settings::allows_backorder()`, `:146-151`), `BuilderWidget`
  (`:1305`, `:1319-1321`) **salta por completo** los tomos reservables, mientras
  `LandingOffer` (`:308-309`) los deja seleccionar y su copy —que está en **`:683`**, dentro
  de la rama `if ( ! $this->settings->allows_backorder() )` de `:682`— promete «Cuentan los
  tomos disponibles ahora; los a pedido no. **Puedes agregarlos juntos igual**».
- **`LandingOffer` es el segundo consumidor de `SeriesFacts`**, aparte del bloque de datos y
  del JSON-LD: los conteos de sus chips salen de `SeriesFacts::catalog_facts()`, y cuando
  nadie le inyecta un lector construye **su propia instancia** (`:777-778`). Es decir, dos
  objetos distintos leyendo el mismo agregado en la misma petición; comparten el transient,
  así que la segunda lectura no cuesta otra consulta, pero conviene saberlo antes de tocar el
  constructor.

#### `PublicBatchRequest` · `BypCartNoticeProvider` · `OrderInstrumentation` · `CustomerCollection`

- **Ficheros:** `PublicBatchRequest.php`, `BypCartNoticeProvider.php`, `OrderInstrumentation.php`,
  `CustomerCollection.php`
- **Cuándo actúa:** `OrderInstrumentation` en `woocommerce_checkout_create_order` @20 y
  `woocommerce_store_api_checkout_order_processed` @20; `CustomerCollection` en
  `woocommerce_order_status_changed` @10 y una docena de eventos de pedido e ítem.
- **Escribe:** el order item meta `_akb_byp_exact_snapshot_v1` (JSON con el aporte por línea) y
  `_akb_byp_line_key_v1` (identidad provisional que enlaza la línea con su ID persistido); las cinco
  metas de pedido `_akb_byp_used`, `_akb_byp_discount_clp`, `_akb_byp_series`, `_akb_byp_distinct` y
  `_akb_byp_tiers`; el user meta `_akb_byp_owned_generation` y el transient
  `akb_byp_owned_all_v3_<user>_g<md5>` (1 día); y los transients de aviso
  `akb_sb_byp_notices_<user_id>` (60 s) y `akb_sb_byp_rejected_<user_id>` (5 min).
- **Las tres guardas de los endpoints públicos** (`PublicBatchRequest.php:66-95`,
  `BuilderWidget.php:94`, `:1075-1096`): mismo origen, tope de **300 IDs** y **10 peticiones por
  minuto** por IP. Existen porque el nonce de invitado viaja en HTML cacheado y es **el mismo para
  todos los invitados del sitio**.
- **La supervivencia del descuento:** si meses después el dueño recalcula el pedido, el cupón se
  reconstruye desde el snapshot; y si el snapshot ya no describe las líneas actuales, se cae a un
  cupón de importe fijo con el **valor histórico**, nunca a cero.

#### `Admin\AdminSection` · `Admin\BypConfigController` · `Admin\AdminAssets`

- **Ficheros:** `Admin/AdminSection.php`, `Admin/BypConfigController.php`, `Admin/AdminAssets.php`
- **Cuándo actúa:** `admin_post_akb_sb_save_byp` @10; `admin_post_akb_sb_bulk_enable` @10;
  `admin_enqueue_scripts` @10.
- **Dónde lo opera el dueño:** Akibara → Venta → «Arma tu Pack», con un bloque por serie.
- **Detalle medido:** una serie que nadie tocó **no se revalida al guardar** (`:246-266`), pero la
  pantalla **sí** recorre casi todo el catálogo al pintarse: `PromotionPolicy::eligible_depth()`
  ejecuta una consulta por término y después hidrata cada producto con `wc_get_product()` para
  aplicarle los guardas del carrito. Con las 119 series que lista esa pantalla y 1.568
  productos, abrir la pestaña recorre prácticamente el catálogo entero, y el propio módulo
  documenta que esta página pesa 347 KB con el interruptor maestro en el byte 344.191
  (`SeriesBundles.php:225-230`) — con un dueño que administra desde el teléfono. **Esa cifra
  es una anotación del código, no una medición hecha para este capítulo**: no se volvió a
  comprobar.

![Caso: armar un pack](diagramas/03-business-catalogo-y-series-caso-arma-tu-pack.mmd)

---

## Lo programado

Cinco trabajos, todos por **Action Scheduler**. El cron de este sitio no lo empuja el tráfico:
`DISABLE_WP_CRON` está encendido y una tarea del panel de Hostinger llama a `wp-cron.php` **cada 5
minutos**.

> **La diferencia entre «agendado» y «corre».** Action Scheduler deja su propio motor
> (`action_scheduler_run_queue`) *agendado cada minuto*, y es fácil leer eso como «la cola se
> vacía cada minuto». No: con `DISABLE_WP_CRON` encendido, nada empuja el cron salvo la tarea
> de Hostinger, **cada 5 minutos**. Así que la cola se ejecuta cada 5 minutos, no cada uno, y
> los «+120 s» de este cuadro son el mínimo, no el plazo. Para alguien que intenta explicar
> por qué un aviso tardó 7 minutos, esa diferencia lo es todo.

| Trabajo | Mecanismo | Frecuencia y hora Chile | Quién lo programa | Qué hace | Si corre dos veces |
|---|---|---|---|---|---|
| `akibara_catalog_order_daily` | Action Scheduler, acción **recurrente** (grupo `akb-catalog-order`) | Cada 24 h, anclada a las **05:00** de Chile. Se re-ancla si la acción se desvía más de 15 minutos (`DAILY_DRIFT_TOLERANCE`) | `CatalogOrder::maybe_schedule_daily()` en `init` @20 (`CatalogOrder.php:122-164`), **sólo en peticiones de admin, cron o consola** (`:123-131`) | Recalcula el `menu_order` de los 1.568 productos y, si escribió algo, dispara `litespeed_purge_all` — la única purga del área que llega a Cloudflare | **Idempotente en el resultado**: el cálculo es determinista y sólo se escriben los que cambian. El coste es el doble de una consulta pesada y una purga de más |
| `akibara_catalog_order_recompute` | Action Scheduler, acción **puntual** | +120 s tras un alta o baja de catálogo; +0 s desde el botón del panel | `CatalogOrder::schedule_recompute()` (`:246-265`) | Lo mismo que la diaria, agrupando la ráfaga de un import | Igual que arriba. La guarda contra duplicados usa `as_next_scheduled_action()`, que cuenta también las acciones **en curso** |
| `akb_sf_scan_product` | Action Scheduler, acción **puntual** (grupo `akibara-series-follow`, `SeriesFollow.php:56`) | +120 s tras publicar un tomo; y **se reencola a sí misma** con un cursor mientras la página de compradores venga llena (`DigestRunner.php:711-732`) | `PublishListener::enqueue_scan()` | Revalida el tomo, resuelve serie y número, busca compradores y encola las filas | **Idempotente por la base**: el `INSERT IGNORE` contra el UNIQUE `u<cliente>:p<producto>` afecta 0 filas la segunda vez. Ver varias acciones del mismo producto en la cola es normal: es la paginación, no un duplicado |
| `akb_sf_digest_run` | Action Scheduler, acción **puntual** que se reencadena (mismo grupo `akibara-series-follow`) | A las N horas de acumulación configuradas (por defecto 6); y +60 s hasta 4 veces si la clase `WC_Email` aún no es cargable | `DigestRunner::schedule_digest()` (`:791-807`), que sólo agenda si **no hay ya una acción pendiente** | Toma 50 clientes, arma un correo por cliente y reclama sus filas | **Seguro**: las filas se reclaman con un `UPDATE` atómico `pending → sent`; si afectó 0 filas, el segundo trabajador se retira sin enviar |
| `akibara_business_cleanup_collection_owner_fence` | Action Scheduler, acción **puntual** | Sin reloj: al cambiar de dueño un pedido | `CustomerCollection::schedule_owner_fence_cleanup()` | Limpia los rastros `akb_byp_owner_fence_*` del dueño anterior | Borrar dos veces lo ya borrado no tiene efecto |

![Reloj del capítulo](diagramas/03-business-catalogo-y-series-cron.mmd)

---

## Casos de uso

### Caso 1 — Sale el tomo 74 de Kingdom y Sofía recibe el aviso

Sofía compró los tomos 68 a 73 hace unos meses. **Nunca pulsó nada parecido a «avísame»**: haber
comprado en un pedido pagado ya la convierte en seguidora.

1. El dueño publica el tomo 74. WordPress dispara `transition_post_status` y, cuando el importador
   le asigna la serie, `set_object_terms` (`PublishListener.php`). El escuchador mira el interruptor
   `akb_sf_enabled`, encola un escaneo para **dentro de 120 segundos** y se va **sin hacer ni una
   consulta**.
2. A los dos minutos (más lo que tarde el cron en empujar: hasta 5 más) corre
   `DigestRunner::scan()`. Revalida tres cosas: el tomo sigue publicado, no está oculto del
   catálogo, y se publicó hace **menos de 60 días** — la constante es `NOVELTY_DAYS`
   (`:65`) y la comprobación está en `product_is_announceable()` (`:685-702`). Republicar un
   tomo de hace dos años no es una novedad.
3. `SeriesResolver` exige una sola `pa_serie` y un número inequívoco. **Bifurcación:** si la ficha
   tiene 0 o 2 series, o el título no da un número claro, se descarta y no se avisa nunca.
4. `FollowerFinder` hace la consulta inversa (serie → compradores) en páginas de 200.
   **Bifurcación:** si esa lectura no fue fiable devuelve `null`, el trabajo sale **sin marcar nada**
   y Action Scheduler reintenta.
5. Por cada comprador se aplican **cuatro descartes** (`DigestRunner::queue_for_followers`,
   `:643-656`): ¿ya tiene el 74? ¿su tomo más alto de Kingdom es 74 o mayor? ¿no tiene **ningún**
   tomo numerado de la serie —sólo un artbook, un especial—? ¿se dio de baja de Kingdom o de todo?
   Sofía pasa los cuatro y su fila entra con `INSERT IGNORE`.
6. Se agenda la corrida del digest a las **6 horas** configuradas; si ya había una **pendiente**, no
   se agenda otra.
7. Llega `DigestRunner::run()`. Comprueba el interruptor y que la clase del correo sea cargable, con
   hasta cuatro reintentos de 60 s — sin eso la corrida moría con un error fatal, y **Action
   Scheduler no reintenta un fatal**: el digest se perdía.
   **Bifurcación importante:** si el «Habilitar este email» de WooCommerce está apagado, la corrida
   **sale sin enviar y sin re-agendarse** (`:248-251`), y la cola se queda quieta hasta que alguien
   publique otro tomo — mientras el panel sigue diciendo «avisos activos» (`SeriesFollow.php:238-243`).
8. Caduca lo pendiente de más de 60 días. Toma 50 clientes y descarta a los que recibieron correo
   hace menos de **7 días**; sus filas quedan `pending`.
9. Para Sofía: se revalida **ahora** la baja, la propiedad y la publicación. **Lo que queda
   escrito en la tabla cuando una de las tres falla:** baja → `dropped` con motivo
   `DROP_OPTOUT` (`:410`); ya lo tiene → `dropped` con `DROP_OWNED` (`:447`); despublicado →
   la fila se **borra**, con `DROP_UNPUBLISHED` (`:471`, y el `DELETE` en
   `DigestQueue.php:276-283`). Después se agrupa por serie; se corta a 5 series
   (`array_slice`, `:528`); **se arma el correo**; y sólo entonces se reclaman las filas con
   un `UPDATE` atómico `pending → sent`. **Bifurcación:** si afectó 0 filas, otro trabajador
   se adelantó y éste se retira.
10. Se envía. **Bifurcación:** si falla, las filas vuelven a la cola y al tercer intento quedan
    `failed`, para drenar en vez de reintentar para siempre. Si va bien, los tomos que el correo
    **nombró pero no mostraron ficha propia** quedan en `coalesced` —que no es «enviado» ni
    «descartado»— y se sella `_akb_sf_last_sent`.

> **El correo que llega**
> **Asunto:** el valor de fábrica es la **plantilla** `'Salió el tomo {tomo} de {serie}{y_mas}'`
> (`SeriesFollowEmail.php:77`), que para Sofía se resuelve como «Salió el tomo 74 de Kingdom»;
> si la serie que abre trae varios tomos se usa el segundo campo, `subject_tanda`, y sale
> «Salieron 4 tomos de Kingdom». Los dos son configurables en la option
> `woocommerce_akb_sf_series_settings` — **y los dos llevan marcadores**: quien los reescriba
> con texto plano le manda el mismo asunto a todo el mundo.
> **Cuándo:** con la cola de Action Scheduler, ~6 h después de publicarse el tomo (2 minutos de
> escaneo más la ventana de acumulación).
> **Qué trae:** un bloque por serie con portada, «Kingdom — tomos 71 al 74», precio y estado del
> tomo mostrado, la fecha de corte si es reservable, un botón «Ver el tomo 74» y un enlace de baja de
> esa serie. En el pie, la baja de todas.
> **Lo que el correo nunca dice:** «el siguiente tomo». Con una tanda se enseña el más nuevo, que no
> es el siguiente que le toca; y una fila rezagada puede anunciar un tomo *anterior* al ya avisado
> — medido en producción el 2026-09-06 con dos clientes reales.

![Estados de la cola de avisos](diagramas/03-business-catalogo-y-series-estados-cola-avisos.mmd)

### Caso 2 — Sofía se da de baja

1. Pulsa «Darme de baja de esta serie». El navegador hace un **GET** y el sitio responde una **página
   de confirmación con un botón**, sin tocar nada (`UnfollowHandler.php:118-133`). No es burocracia:
   los escáneres de enlaces corporativos y el prefetch del buzón hacen GET a todos los enlaces del
   correo.
2. Pulsa «Confirmar baja» → **POST**. Se comprueba la firma HMAC con `hash_equals()` y se escribe el
   user meta `_akb_sf_optout`. **Bifurcación:** con un token manipulado se sirve la **misma** página
   de «Listo» sin mutar nada, porque la respuesta no puede confirmar ni negar que un cliente existe.
3. La página de «Listo» dice cómo volver: el interruptor de **Mi cuenta → Colección**
   (`CollectionToggle`, ruta REST `POST /wp-json/akibara/v1/series-follow`), que escribe exactamente
   el mismo user meta.
4. Encender **una** serie levanta también la baja global —si no, el interruptor diría «sigues esta
   serie» mientras la cabecera global calla todo—; encender el global **no** deshace las bajas por
   serie, y la pantalla lo dice con un aviso aparte.

No sale ningún correo en este caso.

### Caso 3 — El dueño configura la landing de una editorial

1. El dueño **crea la página** con slug `panini` y pega `[akibara_brand_family]`. **El código no
   crea páginas:** una página publicada desde código es un efecto que el dueño no pidió y que después
   hay que explicar.
2. Entra a **Akibara → Sitio → «Editoriales · Ediciones»** (`EditionsPanel.php`). Arriba de cada
   familia ve su landing enlazada, o un aviso si no está publicada.
3. Escribe dos **etiquetas de familia** (el texto del menú y el nombre de la familia); por edición,
   **tres hechos** de una línea —Idioma, Formato, Numeración y precio—; y hasta **6 series
   destacadas**, elegidas de una lista con filtro ya ordenada por número de tomos, con las que
   entrarían solas marcadas en gris. Al lado ve la **vista previa de lo automático**: cuántos
   productos tiene la edición y desde qué precio.
4. Guarda. El panel compara con lo que había y cuenta los cambios.
   **Bifurcación A — no cambió nada:** el aviso dice «Sin cambios que guardar» y **no se purga nada**.
   **Bifurcación B — cambió algo:** guarda un respaldo (hasta 3 en `akb_editions_backup`) y escribe
   sólo lo que cambió. Purga por URL la landing y los archives de sus ediciones; y **sólo si cambió
   el texto del menú** purga toda la caché de página con `litespeed_purge_all`, porque el menú sale
   en todas las páginas (`EditionsPanel.php:274-290`).
5. El aviso que lee el dueño depende de qué rama se ejecutó, y **los dos textos están
   cruzados respecto de lo que hacen** (los dos salen de `EditionsPanel.php:243-248`):
   - **Rama por URL** (el caso normal): el aviso dice «Las páginas afectadas se purgaron en el
     servidor; el borde (Cloudflare) las renueva al vencer su caché, en unos minutos». Lo
     único que se ejecuta es `do_action( 'litespeed_purge_url', $url )` (`:288`), que **se
     queda en el servidor**: el propio `CatalogOrder.php:478-481` deja escrito que esas
     purgas «no propagan al borde de Cloudflare, que es justo donde vive la copia que ve el
     cliente». **Ese plazo de «unos minutos» no lo controla el código**, es el TTL de la
     zona.
   - **Rama entera** (sólo si cambió el texto del menú): el aviso dice «Cambió el texto del
     menú: se purgó la caché de página entera (aparece en todas las páginas)», y **no promete
     nada sobre el borde** — aunque es justo la que sí llega, porque `litespeed_purge_all`
     (`:284`) propaga vía `cdn-cloudflare_clear`.

   Dicho corto: el texto que promete el borde es el del camino que no lo toca, y viceversa.
6. En el front aparecen tres cosas a la vez: `/panini/` con una tarjeta por edición y la tabla de
   comparación; el megamenú con «Panini · todas las ediciones» arriba del listado por país; y el
   conmutador en el archive de cada edición.
7. **Si se equivocó:** «Deshacer el último guardado» repone el estado anterior y purga igual.

No sale ningún correo en este caso.

### Caso 4 — Un cliente arma un pack desde la landing

1. Llega a `/serie/kingdom/` desde Google. El riel «Completa tu colección de Kingdom»
   (`LandingOffer.php`, `woocommerce_before_shop_loop` @40) enuncia el descuento con sus pastillas de
   tramo. **Los tramos que se anuncian no son los configurados a secas:** el riel recorre el universo
   real de la serie aplicando los cuatro guardas del carrito y descarta los que la serie no alcanza.
2. Todo ese HTML **no lee usuario, ni pedidos, ni carrito**: es cacheable. El progreso personal se
   pide **después**, por un endpoint de sólo lectura que jamás acepta un `user_id` (usa el de la
   sesión). **Bifurcación:** si es invitado, responde «no autenticado» y aparece un enlace para
   iniciar sesión que vuelve a la landing.
3. Marca tomos —la selección se guarda en el navegador **por serie**, así que sobrevive al cambio de
   página— y pulsa «Agregar seleccionados».
4. El servidor comprueba mismo origen, límite por minuto, tope de 300 y **revalida cada producto**
   contra la serie exacta (`PublicBatchRequest.php:66-95`). La operación es deliberadamente
   **parcial**: el carrito puede rechazar un tomo entre la validación y la escritura, así que la
   respuesta dice cuántos entraron y cuáles, y el cliente reintenta el resto.
5. Al recalcular el carrito, `BundleCouponEngine` corre en `woocommerce_before_calculate_totals`
   prioridad **1000** —después del descuento editorial, que va en 20— y aplica el cupón virtual.
   **Bifurcación A:** si el cliente tenía un **cupón de campaña externo**, el pack **no** se aplica y
   el carrito lo dice con un aviso propio. **Bifurcación B:** si le falta uno o dos tomos para el
   siguiente tramo, se lo dice también.
6. Al crear el pedido se guarda en el ítem de cupón un **snapshot** con el aporte por línea. Como las
   líneas todavía no tienen ID definitivo, se usan claves provisionales y un hook posterior las
   sustituye por los IDs reales. En el checkout de bloques, donde WooCommerce reutiliza un pedido
   borrador y puede reemplazar las líneas conservando el cupón, un hook con carrito vivo reconstruye
   el mapa y **nunca** reutiliza el importe del intento anterior.

No sale ningún correo propio de este módulo; el de confirmación de pedido es el de WooCommerce.

### Caso 5 — Cómo se decide el orden de los tomos

No hay un orden: hay **cuatro**, con cuatro reglas distintas.

- **El del catálogo** («Destacados», el que sale si nadie elige criterio). Lo calcula la tienda
  escribiendo `menu_order`: una consulta trae todos los productos publicados con su serie, su stock y
  su orden actual; el `Interleaver` agrupa por serie, ordena las series por su tomo **vendible** más
  nuevo, dentro de cada serie pone lo vendible antes que lo agotado, y reparte **por rondas**. Se
  escriben sólo los que cambian, en lotes de 200, sin pasar por `wp_update_post`.
- **El de dentro de una serie.** Lo da el mismo `menu_order`, pero como todos los tomos comparten
  serie quedan consecutivos y el desempate real lo pone el **título** — de ahí el relleno a dos
  dígitos al guardar (`akibara-business.php:425-447`). Es un **proceso**, no un arreglo puntual: sin
  él, el primer tomo nuevo de una serie rompe el orden al llegar al 10.
- **El del armador de packs y las listas de faltantes.** Aquí el alfabético ya no alcanza: con más de
  99 tomos, «100» cae antes que «11» incluso con relleno. El SQL ordena por título y **PHP reordena
  por número de tomo** extraído del título (`VolumeTitle::sort_records`), dejando al final los que no
  tienen número.
- **El del directorio de series**, distinto en naturaleza: no ordena tomos sino nombres, con `strcmp`
  sobre el nombre plegado y nunca con `strcoll`, que depende del idioma del proceso y daría un orden
  distinto según dónde corra el código, congelado seis horas en el cache.

![Caso: los cuatro órdenes](diagramas/03-business-catalogo-y-series-caso-cuatro-ordenes.mmd)

### Caso 6 — Entra un tomo nuevo: quién se entera

Nueve superficies se invalidan por caminos distintos, y ninguna espera a que caduque su caché para
dejar de mentir:

| Superficie | Cómo se entera | Qué hace |
|---|---|---|
| El bloque de datos y su JSON-LD | `save_post_product`, `set_object_terms`, metas @10 | Rota la generación de la serie y purga la etiqueta `T.<term_id>` en `shutdown` @-100 |
| El directorio de series | `transition_post_status`, `set_object_terms` @10 | Borra el transient y purga `/series/` por URL |
| El orden del catálogo | `transition_post_status` @10 | Encola el recálculo a +120 s **sólo si fue alta o baja**, no si fue stock |
| Las dos vitrinas del inicio | `woocommerce_new_product` y cinco hermanos @10 | Suben el testigo de generación |
| Las tarjetas de edición | `woocommerce_update_product` / `set_stock_status` @10 | Borran el transient de 12 h **— pero no escuchan el borrado ni la papelera** |
| Los más vendidos | `before_delete_post` y los cambios de stock @10 | Borran los transients de una lista fija de tamaños |
| La membresía del armador de packs | `save_post_product`, `set_object_terms` y las relaciones @10 | Sube el testigo que versiona la lista de tomos |
| El escaneo de «Sigue esta serie» | `transition_post_status`, `set_object_terms` @10 | Encola el escaneo a +120 s |
| El buscador (`akibara-search`) | `set_object_terms` | Dispara su propio reindexado — **el efecto más caro de la casa** |

**El buscador es la novena y la que más cuesta.** Ninguna de las otras ocho tarda más de
unos milisegundos: rotan un testigo o borran un transient. El reindexado del buscador, en
cambio, reconstruye su índice, y por eso `PublishListener` está deliberadamente escrito para
**encolar y salir sin hacer ni una consulta**: viaja encima del mismo evento.

**Lo que a propósito no se entera:** el precio del bloque de datos sigue viniendo de
`wc_product_meta_lookup`, que no incluye el descuento aplicado por filtro — y por eso el bloque se
**calla el rango** mientras haya campaña vigente.

![Caso: entra un tomo nuevo](diagramas/03-business-catalogo-y-series-caso-tomo-nuevo.mmd)

### Caso 7 — El dueño carga el estado de publicación y sale al instante

La gente busca «the climber cuantos tomos tiene» (146 impresiones en 90 días, posiciones 4,8 y 5,5) y
la landing no lo contestaba arriba: lo contestaba el último párrafo de la descripción, a ~6.400 px de
scroll — es decir, en móvil (72,5 % del tráfico) **no lo contestaba**.

1. El dueño elige «Completa» y escribe 23 en el formulario del término.
2. El guardado compara antes y después. **Bifurcación A:** si no cambió nada —porque sólo editó la
   descripción— **no purga**. **Bifurcación B:** si cambió, purga la landing por etiqueta
   `T.<term_id>`, que en LiteSpeed cubre el archive **y todas sus páginas 2 y siguientes**.
3. Esto hace falta porque LiteSpeed sólo purga por eventos de entrada, y ninguno de ellos dispara al
   guardar un **término** — medido en producción el 2026-08-09: 6 de 7 peticiones al origen devolvían
   `x-litespeed-cache: hit`. (La medición es una anotación del código, en
   `PublicationStatusFields.php:426-434`; no se ha vuelto a comprobar.)
4. En la landing aparece «Edición Ivrea Argentina: Completa en 23 tomos» y, sólo si aporta información
   distinta, «Obra original: Finalizada en 23 tomos». El total de la obra **no** se compara con el
   conteo del catálogo (`PublicationStatus.php:361-366`): son ejes explícitamente distintos, porque
   una obra de 10 tomos puede venderse como 5 integrales.

### Caso 8 — Obras con múltiples ediciones físicas y tomos únicos (2026-10-17)

Cuando una misma obra literaria llega al catálogo en distintas ediciones físicas (por ejemplo, *Fumando atrás del súper con vos* de Ivrea Argentina y *Fumando juntos detrás del súper* de Panini España, o *One Piece* de Ivrea y *One Piece 3 en 1* de Planeta Cómic), no se fusionan los términos ni se crea una taxonomía abstracta adicional:

1. **Identidad de edición:** Cada edición física conserva su propio término en `pa_serie` (`term_id=653` y `term_id=607`), preservando su slug, descripción editorial, conteo de tomos y sitemap independiente.
2. **Relación simétrica:** Las ediciones homólogas se vinculan mediante el metadato de término `_akb_serie_ediciones_relacionadas`, que guarda un array con los `term_id` de las otras ediciones.
3. **Bloque comparativo en PDP (`SeriesEditions::render_pdp_editions` @35):**
   - Al visualizar cualquier tomo de una edición, el bloque busca si en la otra edición existe el tomo homólogo (mismo número).
   - Si existe: muestra su portada, origen (`Editorial · País`), precio, estado comercial (`Disponible`, `A pedido`, etc.) y enlace directo (`Ver tomo NN →`).
   - Si no existe: informa que el tomo aún no ha sido editado en esa versión y ofrece el enlace a la edición completa.
   - En la cabecera del producto (`PdpEnhancer::render_edition_summary` @9) se exponen el país de edición y las dimensiones del tomo.
4. **Directorio `/series/` y puente de búsqueda:**
   - La faceta de País y Editorial filtra dinámicamente en el cliente sin recarga.
   - Cada tarjeta expone su origen (`Editorial · País`) y la pista cruzada *«También como: {Otra edición}»*.
   - El buscador del directorio incluye el nombre alternativo en `data-akb-serie`, por lo que buscar cualquier variante del título despliega todas las ediciones disponibles.
5. **Obras autoconclusivas (Tomo Único):**
   - Si una serie tiene 1 tomo y su estado es `completa` (o metadato `_akb_serie_tipo_obra == 'tomo_unico'`), la navegación de serie en PDP adopta la redacción singular *"Ver la edición de {Serie}"* y *"Tomo único · Obra autoconclusiva"*, suprimiendo flechas vacías y conteos superfluos. En `/series/` dispone de su propio chip de filtro y en Mi Cuenta se muestra al 100% completa.

---

## Dónde mirar cuando algo falla

Esta área **sí** deja trazas, y es su único canal de diagnóstico: casi todo lo que puede
fallar aquí corre de forma asíncrona, por Action Scheduler, donde nadie lee el valor de
retorno. Cinco puntos escriben con `Utilities\Log::warn()`, un envoltorio de
`wc_get_logger()` (`src/Utilities/Log.php:36-45`) que etiqueta cada línea con una **fuente**
del tipo `akibara-<módulo>`.

**Dónde se leen:** en el panel, **WooCommerce → Estado → Registros**, eligiendo la fuente en
el desplegable. Desde consola, `wp log list` no existe; los ficheros viven en
`wp-content/uploads/wc-logs/<fuente>-<fecha>-<hash>.log`.

| Dónde | Fuente | Qué dice, y qué significa |
|---|---|---|
| `CatalogOrder.php:458-461` | `akibara-catalog-order` | «Chunk de N menu_order falló: …» — un lote de 200 `menu_order` no se escribió (deadlock, `max_allowed_packet`, tabla en sólo lectura). El orden del catálogo quedó parcialmente viejo. Se reintenta solo en la siguiente corrida |
| `DigestRunner.php:190` | `akibara-series-follow` | Falló el escaneo de un producto |
| `DigestRunner.php:240` | `akibara-series-follow` | «Digest diferido: `WC_Email` todavía no cargable.» — normal hasta cuatro veces; si se repite más, el correo de WooCommerce no está cargando |
| `DigestRunner.php:346` y `:367` | `akibara-series-follow` | Falló el armado o el envío del digest |
| `Install.php:80` | `akibara-series-follow` | **La tabla de la cola no se pudo crear.** El módulo no va a encolar nada y el panel no lo dice |

Si no hay ninguna línea y algo igual no funciona, lo siguiente que hay que mirar es
**Herramientas → Acciones programadas**, filtrando por grupo: `akb-catalog-order` para el
orden del catálogo, `akibara-series-follow` para los avisos.

---

## Si esto pasa, haz esto

Cuatro incidencias que este capítulo describe y qué hacer con cada una. Todas se ejecutan
por `ssh akibara`, en el docroot.

### «El orden del catálogo está viejo» / el botón del panel dice que ya hay uno en cola

El botón puede estar mintiendo: su guarda cuenta también la corrida **en curso**
(`CatalogOrder.php:252-253`). Salta el panel:

```bash
wp akibara-catalog-order status      # ¿cuándo fue la última corrida y cuántas filas escribió?
wp akibara-catalog-order recompute   # recalcula ahora, sin Action Scheduler
```

Si `status` no registra ninguna corrida y el sitio lleva días así, lo más probable es que la
diaria nunca se haya agendado, porque `maybe_schedule_daily()` sólo corre en admin, cron o
consola (`:123-131`). Con una petición de WP-CLI ya queda agendada.

### «La cola de avisos está quieta»

Casi siempre es el **segundo** interruptor, el de WooCommerce, que el panel de Akibara no
muestra: con «Habilitar este email» apagado, la corrida sale sin enviar **y sin
re-agendarse** (`DigestRunner.php:248-251`), así que la cola se queda parada hasta que
alguien publique otro tomo.

```bash
wp option get akb_sf_enabled                                    # 1er interruptor
wp option get woocommerce_akb_sf_series_settings --format=json  # mirar "enabled"
wp db query "SELECT status, COUNT(*) FROM wp_akb_series_follow_queue GROUP BY status;"
```

Si el segundo estaba apagado, encenderlo no reencadena nada por sí solo: el listener de
re-arranque escucha `update_option_akb_sf_enabled` (el del **primer** interruptor) y sólo
acepta el valor literal `'yes'` (`DigestRunner.php:138`, `:149-161`). La cadena vuelve a
arrancar con la siguiente publicación, o encendiendo y apagando ese primer interruptor.

### «La landing de una serie muestra datos viejos»

Dos pisos, dos gestos, en este orden (el `DELETE` de transients por SQL **no** sirve: hay
caché de objetos persistente):

```bash
wp term get pa_serie <slug> --by=slug --field=term_taxonomy_id
wp option update akb_series_facts_generation_<ttid> "manual-$(date +%s)"
```

y después purgar la etiqueta `T.<term_id>` desde el panel de LiteSpeed Cache. Si lo que está
viejo es `/editoriales/`, la portada o el armador de packs, las options equivalentes son
`akb_brands_grid_gen` (entero, súmale 1), `akb_home_destacados_generation` y
`akb_sb_vols_cache_ver`.

### «Un producto publicado quedó con `menu_order` en 0 / fuera de sitio»

Pasa cuando el alta cae **dentro** de una corrida: la consulta ya había leído el universo.
No hay que arreglar la fila a mano —el campo lo gestiona una máquina y el arrastre manual se
pisa—: basta rehacer el cálculo completo con `wp akibara-catalog-order recompute`.

### Lo que NO hay que hacer en ninguno de los cuatro casos

- **No borrar options de generación** con `wp option delete`: el código las vuelve a crear,
  pero entre medias una clave sin generación colisiona con las viejas.
- **No editar `wp_posts.menu_order` a mano.**
- **No borrar filas de `wp_akb_series_follow_queue`** para «destrabar» la cola: las filas
  `sent` **son** la marca de avisado; borrarlas reabre la ventana y el cliente recibe dos
  veces el mismo tomo.

---

## Reglas de negocio

### La serie y su ficha de datos

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
|---|---|---|---|
| Una serie sólo cuenta tomos publicados y no ocultos; el `count` nativo no sirve | `SeriesCatalogEligibility.php:190-219` | no | — |
| Sin tomos en catálogo, la serie no entra en el directorio | `SeriesIndex.php:311` | no | — |
| Con `noindex` no entra en el directorio ni recibe entidad `ComicSeries` | `SeriesIndex.php:349` · `SeriesSchema.php:265` | sí | Rank Math, en el término |
| El directorio ordena por el nombre tal como se ve, sin mover artículos | `SeriesIndex.php:293-297` | no | — |
| Sin tomos publicados no se imprime el bloque de datos | `SeriesFacts.php:1138` | no | — |
| «Disponible» y «A pedido» salen siempre, incluso en 0; «Agotado» sólo si existe | `SeriesFacts.php:1811-1817` | no | — |
| Los tomos agotados se nombran sólo si se pueden afirmar **todos** | `SeriesFacts.php:1577-1610` | no | — |
| Máximo 6 números de tomo y 3 autores; el resto, «y N más» | `SeriesFacts.php:259` · `:269` | no | — |
| El rango de precios no se publica durante una campaña de descuento, ni en el HTML ni en el JSON-LD | `SeriesFacts.php:1285-1291` · `:1865-1870` | sí | Akibara → Descuentos |
| La fila «Edición X» sólo sale si el catálogo deriva **una** editorial | `SeriesFacts.php:1731-1740` | no | — |
| El total de la obra sólo se publica con «Finalizada» | `PublicationStatus.php:389-401` | no | — |
| El total de la edición se publica con «Completa» y «En publicación»; con «Al día», «En pausa» o «Cancelada», no | `PublicationStatus.php:419-432` | no | — |
| El total va de 1 a 999; un decimal es «sin dato» | `PublicationStatus.php:150` · `:247-260` | no | — |
| El total de la obra **no** se compara con el conteo del catálogo | `PublicationStatus.php:361-366` | no | — |
| Al guardar el término: ausente no se toca, vacío borra | `PublicationStatusFields.php:492-520` | no | — |
| La entidad `ComicSeries` sólo en la página 1 del archive | `SeriesSchema.php:251-253` | no | — |
| Un término no-cómic conserva su archive pero nunca se identifica como `ComicSeries` | `SeriesSchemaType.php:60-68` | **sin pantalla** | sólo por SQL o WP-CLI (ver más abajo) |
| El schema de la serie no emite precio agregado | `SeriesSchema.php:359-372` | no | — |
| Hasta 8 ediciones relacionadas, siempre en los dos sentidos o en ninguno | `SeriesEditions.php:40` · `:176-195` | no | — |
| La guía de lectura sólo en la URL limpia; los parámetros de campaña no cuentan | `SeriesRelatedGuide.php:356-379` | no | — |

### Editoriales

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
|---|---|---|---|
| La región se resuelve en cascada: meta → sufijo de slug → mapa antiguo → nombre → «Otros» | `BrandRegion.php:137-158` | sí | Productos → Marcas, y dos filtros |
| Las pistas de nombre incluyen `panini` e `ivrea` dentro de **España** | `BrandRegion.php:105` | sí | el override manual del término |
| El conmutador sólo aparece con dos o más ediciones resolubles | `BrandLandingCallout.php:178` | no | — |
| Una etiqueta vacía vuelve al literal de fábrica y borra la option | `BrandLandings.php:153-165` | sí | Editoriales · Ediciones |
| Hasta 6 series destacadas por edición; los huecos se rellenan con las de más tomos | `EditionFacts.php:40` · `:369-379` | sí | Editoriales · Ediciones |
| Cada hecho es una línea de ≤140 caracteres sin HTML | `EditionFacts.php:46` · `:164-171` | no | — |
| Los hechos se escriben con term meta directo, nunca `wp_update_term` | `EditionFacts.php:205-229` | no | — |
| La invalidación de las tarjetas de edición **no** cubre el borrado ni la papelera | `EditionFacts.php:62-63` (contra `HomeDestacados.php:77-78`) | no | — |
| Los textos del cliente **nunca** pasan por `sprintf` | `EditionCopy.php:89-99` | sí | Akibara → Textos y Copys |
| El «Desde $X» de la tarjeta de edición **no** omite el rango durante una campaña | `EditionFacts.php:315-337` (contra `SeriesFacts.php:1285-1291`) | no | — |
| El aviso del panel promete que el borde se renueva «en unos minutos», y sólo se ejecuta una purga por URL de LiteSpeed | `EditionsPanel.php:243-248` y `:280-287` | no | — |

### Orden, vitrinas y portadas

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
|---|---|---|---|
| La portada de una serie es la del tomo más bajo, con stock si lo hay | `SeriesCover.php:37-74` | no | — |
| La consulta de portadas trae 40 tomos **sin `orderby`**, y el fallback toma `$ids[0]` | `SeriesCover.php:46-54` y `:90-99` (contra el comentario de `:28`) | no | — |
| El orden «Destacados» lo calcula la tienda: el arrastre manual se pisa | `CatalogOrder.php:1-25` · `:390-489` | no | — |
| Dentro de una serie, lo vendible va antes que lo agotado | `Interleaver.php:54-80` | no | — |
| El recálculo corre a las 05:00 de Chile, re-anclado si se desvía >15 min | `CatalogOrder.php:45` · `:174-188` | no | — |
| Un cambio de stock **no** dispara el recálculo del orden | `CatalogOrder.php:218-227` (`on_status_change()`, con su docblock en `:209-217`) | no | — |
| La guarda contra corridas duplicadas usa `as_next_scheduled_action()`, que cuenta también las acciones **en curso**; el panel imprime entonces «Ya había un recálculo en cola» aunque no quede nada pendiente | `CatalogOrder.php:252-253` (docblock en `:230-232`) · el aviso, `:321` | no | — |
| El contador de «reordenados» suma los IDs enviados (`:464`), no las filas que el `UPDATE` (`:451-453`) afectó | `CatalogOrder.php:464` (contra su docblock, «El retorno cuenta lo APLICADO, no lo propuesto», `:381`) | no | — |
| Al guardar, el número de tomo de un dígito se rellena a dos; el slug no se toca | `akibara-business.php:425-447` | no | — |
| El selector de orden no ofrece «valoración» (no hay reseñas) | `akibara-business.php:321` y `:326` | no | — |
| Con búsqueda y sin criterio elegido, la opción por defecto se rotula «Relevancia» | `akibara-business.php:344` | sí | Akibara → Textos y Copys |
| En la portada, «Recién llegados» inyecta `_stock_status=instock`; las secciones con `category` quedan fuera | `akibara-business.php:278-299` | no | — |
| Los más vendidos incluyen lo reservable, excluyen lo agotado | `PopularProducts.php:126-132` | no | — |
| `PopularProducts::flush()` sólo borra los transients de los tamaños 1,2,3,4,5,6,8,12. Hoy no muerde: el tema pide un único tamaño, 4, que está en la lista | `PopularProducts.php:145-149` (contra su propio comentario de `:139-141`, que afirma dos tamaños) · `themes/akibara-gp/inc/performance.php:301` | no | — |
| La vitrina de series no pinta sin lista: nunca inventa una selección | `HomeSeriesDestacadas.php:226-254` | sí | Akibara → Sitio → Series en la portada |
| La vitrina de series cuenta tomos con `WP_Term::count`, que incluye los ocultos | `HomeSeriesDestacadas.php:255` y `:266` | no | — |
| La clave del transient de la vitrina de series incluye la versión del catálogo de WooCommerce, la lista y el `count` (desde business 1.118.0); la vitrina lleva etiqueta de caché propia `akb_home_series`, exenta del purge global | `HomeSeriesDestacadas.php:48`, `:55`, `:351-393` y `:442-446` | no | — |
| «Ver todas →» lleva `/series/` escrito a mano, igual que la purga del directorio | `HomeSeriesDestacadas.php:169` · `SeriesDirectory/Shortcode.php:155` | no | — |
| Las vitrinas de producto reparten 2 (a pedido) o 1 (resto) por serie, y sólo relajan el tope para no dejar huecos | `HomeDestacados.php:106-108` · `:196-204` · `:353-370` | sí | atributos del shortcode |
| La vitrina «A pedido» **no** excluye lo oculto del catálogo; su gemela sí | `HomeDestacados.php:126-145` (contra `:234-237`) | no | — |

### Sigue esta serie

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
|---|---|---|---|
| Seguir es un hecho derivado de haber comprado en un pedido pagado; no hay alta ni carga histórica | `CustomerCollection.php:204-207` · `SeriesFollow.php:8-27` | sí | un filtro, sin pantalla |
| Sólo se avisa a quien no tiene el tomo y cuyo tomo más alto es **menor** | `DigestRunner.php:643-656` | no | — |
| 0 o 2 series, o sin número inequívoco → no se avisa nunca | `SeriesResolver.php:49` · `:61-64` | no | — |
| Un tomo de más de 60 días no es novedad | `DigestRunner.php:65` (`NOVELTY_DAYS`) · la comprobación, `:685-702` | no | — |
| Un tomo no se avisa dos veces: lo garantiza una restricción única de la base — **con una excepción deliberada**: con el motivo `DROP_UNPUBLISHED` la fila se **borra**, para que un tomo despublicado y vuelto a publicar sí pueda avisarse | `Install.php:135-152` · `DigestQueue.php:96-146` · la excepción, `DigestQueue.php:276-283` | no | — |
| Un cliente recibe como mucho un correo cada N días (7); el tope se sella sólo tras el éxito | `DigestRunner.php:262-275` · `:390` | sí | Akibara → Venta → Sigue esta serie |
| Acumulación de N horas (6) y máximo 5 series por correo | `SeriesFollow.php:64-66` · el corte, `DigestRunner.php:528` | sí | ídem |
| Varios tomos de la misma serie viajan en un bloque; los demás quedan en `coalesced`, que es terminal | `DigestQueue.php:36-58` · `DigestRunner.php:530-560` | no | — |
| El rango «71 al 74» sólo si el tramo es contiguo y tiene 3 o más | `VolumePhrase.php:96-110` | no | — |
| Lo pendiente de más de 60 días caduca | `DigestQueue.php:305-320` | no | — |
| La baja exige POST; el GET sólo confirma | `UnfollowHandler.php:118-133` | no | — |
| Un token inválido devuelve la misma página, sin filtrar si el cliente existe | `UnfollowHandler.php:22-27` · `:126-136` | no | — |
| Encender una serie levanta el global; encender el global no deshace las bajas por serie | `FollowRegistry.php:151-176` · `:196-206` | no | — |
| Armar el correo va antes de reclamar las filas: al revés se perdería el aviso para siempre | `DigestRunner.php:10-18` · `:344-360` | no | — |
| Hay **dos** interruptores, y el panel informa sólo del primero | `SeriesFollow.php:238-243` · `DigestRunner.php:248-251` | sí | Akibara → Venta; y WooCommerce → Correos |
| El re-arranque de la cola sólo reconoce el valor literal `'yes'`, y escucha `update_option_`, no `add_option_` | `DigestRunner.php:138` y `:149-161` (contra `SeriesFollow.php:407-410`) | no | — |
| «Próxima corrida» del panel se calcula con `as_next_scheduled_action()`, que cuenta las acciones en curso | `SeriesFollow.php:260-273` | no | — |

### Arma tu Pack

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
|---|---|---|---|
| Un tramo con descuento arranca en 3 tomos: un 2-pack no se regala | `VolumeTierResolver.php:47` · `:154-157` | no | — |
| Los porcentajes deben ser estrictamente crecientes | `VolumeTierResolver.php:159-173` | no | — |
| El tope de porcentaje es el **mismo** que el del descuento editorial, leído en tiempo de ejecución | `VolumeTierResolver.php:52-63` · `DiscountEngine.php:205-208` | sí | Akibara → Descuentos |
| El tramo es inclusivo: se gana desde su mínimo y sigue valiendo con más | `VolumeTierResolver.php:74-101` | no | — |
| Gana el mejor precio: si el precio filtrado ya es ≤ el objetivo, el aporte es 0 | `DiscountEngine.php:212-222` · `:249-258` | no | — |
| El redondeo del descuento usa `wc_get_price_decimals()`, con fallback a 0 decimales | `Support/Money.php:27-30` | sí | Ajustes de moneda de WooCommerce |
| El descuento cubre **una** unidad de cada tomo distinto | `DiscountEngine.php:265-290` · `BundleCouponEngine.php:300-306` | no | — |
| Un tomo agotado no cuenta para nada | `CartSeriesCollector.php:44-46` · `:188-196` | no | — |
| Los reservables sólo cuentan si el dueño lo enciende; igual se pueden comprar juntos | `Settings.php:146-151` · el copy, `LandingOffer.php:682-683` | sí | Akibara → Venta → Arma tu Pack |
| El armador de la ficha **no pinta** los reservables con el interruptor apagado; la landing sí los ofrece | `BuilderWidget.php:1305` y `:1319-1321` (contra `LandingOffer.php:308-309` y el copy de `:683`) | sí (el interruptor) | ídem |
| Con un cupón externo aplicado, el pack cede | `BundleCouponEngine.php:705-716` | no | — |
| El descuento es cupón virtual, nunca `set_price()` | `BundleCouponEngine.php:4-10` | no | — |
| El cupón sólo se aplica con descuento estrictamente positivo | `BundleCouponEngine.php:1063-1092` | no | — |
| Un producto en dos series con pack no recibe descuento por ninguna | `CartSeriesCollector.php:90` | no | — |
| Sólo se anuncian tramos alcanzables | `BuilderWidget.php:1030-1048` · `LandingOffer.php:612-627` | no | — |
| El armador arranca vacío: nunca casillas premarcadas con cargo | `BuilderWidget.php:700-705` | no | — |
| «Te faltan N» sólo con 6 o menos; el botón de completar sólo si **todos** son comprables | `BuilderWidget.php:103` · `:686-696` | no | — |
| Los POST públicos: 300 IDs, 10 por minuto e IP, mismo origen | `BuilderWidget.php:94` · `:1075-1096` · `PublicBatchRequest.php:66-95` | no | — |
| Política recomendada del alta en lote: 8 % desde 5 tomos, 10 % desde 7 | `PromotionPolicy.php:49-66` · `:447-455` | sí | el botón de alta en lote |
| El alta en lote es aditiva: nunca pisa lo configurado | `PromotionPolicy.php:133-170` | no | — |
| Una serie que nadie tocó no se revalida al guardar | `BypConfigController.php:246-266` | no | — |
| Apagar el módulo oculta la superficie entera, no sólo el descuento | `LandingOffer.php:165-172` · `:262-269` · `:285-292` | sí | Akibara → Venta → Arma tu Pack |
| Vocabulario **de grupo**: Disponible / A pedido / Agotado, sobre los tres estados técnicos de WooCommerce. «Preventa» **no es un estado de stock**: es una distinción por producto que vive aparte | `StockStatus.php:50-54` · `Availability.php:135-141` | sí | el filtro `akb_stock_status_label`, sin pantalla |

### Una regla sin pantalla

`_akb_serie_schema_type` es un dato de negocio —«esta serie es una novela, no un cómic»— que gobierna
el tipo declarado en los datos estructurados de la landing **y de las 1.568 fichas de producto**, el
`ItemList` del hub de series y la fecha del sitemap. Tiene **cinco consumidores y cero pantallas**: no
hay campo en el formulario del término (que ya tiene siete campos de otros módulos), ni columna en el
listado, ni pestaña en el panel. Sólo se puede escribir por SQL o WP-CLI. Y `supports_comic_series()`
falla cerrado ante cualquier valor desconocido (`SeriesSchemaType.php:60-68`): un error de tecleo
escrito por SQL apaga en silencio el `ComicSeries` de esa serie y de todos sus tomos.

### Un aviso sobre `VolumeVariant`

En el paquete publicado **no existe** `src/Modules/VolumeVariant` ni `src/Services/VolumeVariant.php`;
`grep -rl VolumeVariant` sobre el snapshot devuelve cero coincidencias. En el árbol vivo sí hay
pruebas para esas clases. **`VolumeVariant` es trabajo en curso del árbol local y no forma parte de
akibara-business 1.116.4.** Cualquier diagrama o ronda de QA que lo incluya estaría describiendo algo
que el cliente no tiene.

---

## Datos que deja

### Options

| Clave | Qué guarda | Dónde se edita |
|---|---|---|
| `akibara_home_series_enabled` / `_title` / `_slugs` | Interruptor, título y lista de hasta 24 slugs de la vitrina de series | Akibara → Sitio → «Series en la portada» |
| `akb_brand_landing_<familia>_menu_label` / `_callout_label` | Los dos textos de cada familia de editorial | Akibara → Sitio → «Editoriales · Ediciones» |
| `akb_editions_backup` | Hasta 3 snapshots para el botón «Deshacer el último guardado» | se escribe sola; se consume con el botón |
| `akb_copy_editions_label` / `_compare` / `akb_copy_edition_cta` / `_count` | Textos de las tarjetas de edición (marcador `%s` por `str_replace`) | Akibara → Textos y Copys |
| `akb_copy_order_relevance` / `_featured` / `_title` / `_popularity` / `_date` / `_price` / `_price_desc` | Etiquetas del selector de orden del catálogo | Akibara → Textos y Copys → Ordenamiento |
| `akb_catalog_order_last_run` | `{time, rows, applied}` de la última corrida del reorden | se muestra, no se edita, en Akibara → Venta → «Vitrina» |
| `akb_sf_enabled` / `akb_sf_weekly_cap_days` / `akb_sf_digest_delay_hours` / `akb_sf_max_series_per_email` | Los cuatro ajustes de «Sigue esta serie» | Akibara → Venta → «Sigue esta serie» |
| `akb_sf_db_version` | Marca de migración de la tabla de la cola | sin pantalla |
| `woocommerce_akb_sf_series_settings` | `subject`, `subject_tanda`, `heading`, `additional_content`, `enabled` del correo | WooCommerce → Ajustes → Correos |
| `akb_series_bundles_enabled` / `_byp` / `_exclude_cats` / `_allow_backorder` | El interruptor, el JSON de tramos por serie, las categorías excluidas y si entran los reservables | Akibara → Venta → «Arma tu Pack» |
| `akibara_fsp_carrier_threshold` | Umbral del transportista, leído por el armador para decir cuánto falta | Akibara → Venta → «Envío gratis» |
| `akb_series_facts_generation` y `akb_series_facts_generation_<ttid>` | Tokens opacos que versionan la caché del bloque de datos; **sin autoload** | sin pantalla (internos) |
| `akb_brands_grid_gen` | Contador entero que versiona la caché de `/editoriales/`; con autoload | sin pantalla (interno) |
| `akb_home_destacados_generation` | Marca de tiempo que versiona las vitrinas de producto; con autoload | sin pantalla (interno) |
| `akb_sb_vols_cache_ver` | Testigo que versiona la lista de tomos del armador; sin autoload | sin pantalla (interno) |
| `akb_byp_owner_fence_*` | Rastro temporal del dueño anterior de un pedido | sin pantalla (interno) |

### Tablas

| Tabla | Qué guarda |
|---|---|
| `wp_akb_series_follow_queue` (el prefijo es el de este sitio; `wp db prefix` lo confirma) | La cola **y** la marca de avisado: `id`, `user_id`, `product_id`, `term_id`, `volume`, `event_key` (UNIQUE), `status`, `attempts`, `created_at`, `sent_at`. `status` toma seis valores: `pending`, `sent`, `coalesced`, `dropped`, `expired`, `failed` (`DigestQueue.php:57-62`). Las filas `sent` **no se barren nunca** |
| `wp_posts.menu_order` | Campo gestionado por máquina: cualquier orden arrastrado a mano en el listado de productos se pisa en el siguiente recálculo |
| `wp_posts.post_title` | Se reescribe al guardar para dejar el número de tomo con dos dígitos |
| `wp_wc_product_meta_lookup` | Sólo lectura: de ahí salen `stock_status` y `min_price`/`max_price`. **No refleja descuentos aplicados por filtro** |
| `wc_order_product_lookup`, `wc_order_stats`, `wc_customer_lookup` | Sólo lectura: las tablas de **Analytics** de WooCommerce, el resumen rápido de qué se vendió a quién |
| `wp_wc_orders` | Sólo lectura: el **almacén canónico de pedidos** cuando HPOS está encendido (si no lo está, el mismo código lee `wp_posts`; lo decide `OrderStorage::uses_hpos()`). Sirve para exigir que la fila de Analytics corresponda a un pedido todavía vivo |

### Term meta

| Clave | Taxonomía | Qué guarda |
|---|---|---|
| `_akb_serie_estado` · `_akb_serie_tomos_obra` | `pa_serie` | Estado y total de la **obra original** |
| `_akb_serie_estado_edicion` · `_akb_serie_tomos_edicion` | `pa_serie` | Estado y total de la **edición que se vende** |
| `_akb_serie_schema_type` | `pa_serie` | Clasificación de tipo de obra. **Sin ninguna pantalla** |
| `_akb_serie_ediciones_relacionadas` | `pa_serie` | Array de hasta 8 `term_id`, escrito en los dos términos |
| `_akb_related_guide_post_id` | `pa_serie` | ID de la entrada del blog relacionada |
| `rank_math_robots` | `pa_serie` | Lo escribe Rank Math; si contiene `noindex`, la serie sale del directorio y del schema |
| `akibara_region` | `product_brand` | Override manual del país: `argentina`, `espana`, `otros` |
| `akb_brand_featured` | `product_brand` | `'1'` = destacada en la vitrina del inicio |
| `akibara_edition_idioma` · `_formato` · `_numeracion` | `product_brand` | Los tres hechos de una línea (≤140 caracteres, sin HTML) |
| `akibara_edition_series` | `product_brand` | CSV de hasta 6 `term_id` de `pa_serie` elegidos a mano |
| `thumbnail_id` | `product_brand` | Logo de la marca (dato nativo de WooCommerce) |

### User meta, post meta y meta de pedido

| Clave | Dónde | Qué guarda |
|---|---|---|
| `_akb_sf_last_sent` | usuario | Marca de tiempo del último digest enviado con éxito (el tope de 7 días) |
| `_akb_sf_optout` | usuario | Array de `term_id` de los que el cliente no quiere avisos |
| `_akb_sf_optout_all` | usuario | `'1'` = no quiere avisos de ninguna serie |
| `_akb_byp_owned_generation` | usuario | Testigo que versiona la caché de «qué tiene el cliente» |
| `_menu_item_classes` | elemento de menú | Las clases CSS del megamenú (dato nativo de WordPress) |
| `_akb_byp_exact_snapshot_v1` | ítem de cupón | JSON con el aporte por línea del cupón del pack |
| `_akb_byp_line_key_v1` | ítem de línea | Identidad provisional que enlaza la línea con su ID persistido |
| `_akb_byp_used` · `_akb_byp_discount_clp` · `_akb_byp_series` · `_akb_byp_distinct` · `_akb_byp_tiers` | pedido | Si usó pack, el descuento total con impuestos, las series, los tomos distintos y el tramo aplicado por serie |

### Transients

| Clave | TTL | Qué guarda |
|---|---|---|
| `akb_series_directory_v3` | 6 h | HTML ya construido del directorio de series |
| `akb_series_facts_v3_<generacion>_<ttid>` | 6 h | El agregado de la serie; se cachea **incluso el resultado vacío** |
| `akb_brands_grid_<generacion>_<md5>` | 1 h | HTML del directorio de editoriales |
| `akibara_home_carousel_brands_v4` | 12 h | Lista ya resuelta de marcas con logo |
| `akb_edition_facts_<term_id>` | 12 h | Series resueltas con portada y precio «desde» por edición |
| `akb_editions_notice_<user_id>` | 60 s | Aviso del panel que sobrevive al redirect |
| `akb_home_series_v1_<md5 de la lista>` | 6 h | Series de la vitrina ya resueltas con portada |
| `akb_preventas_destacadas_v2_*` · `akb_destacados_v1_*` | 1 h | CSV de IDs elegidos por cada vitrina de producto |
| `akb_popular_cross_sell_ids_v3_<limite>` | 1 h (2 min si vino vacía) | Lista de IDs de más vendidos |
| `akb_series_vols_<md5>` | 1 h | IDs de los tomos de la serie para el armador |
| `akb_byp_owned_all_v3_<user>_g<md5>` | 1 día | IDs comprados por el cliente |
| `akb_sb_byp_notices_<user_id>` · `akb_sb_byp_rejected_<user_id>` | 60 s · 5 min | Avisos y rechazos del armador que sobreviven al redirect |

---

## Glosario del capítulo

- **Action Scheduler** — la cola de trabajos que trae WooCommerce. Guarda cada tarea como una fila y
  la ejecuta cuando el cron la empuja. Una acción puede estar *pendiente*, *en curso*, *completada* o
  *fallida*, y esa diferencia importa: preguntar «¿hay alguna?» puede contar la que se está
  ejecutando ahora mismo.
- **Archive** — la página de listado que WordPress genera sola para una etiqueta organizada. En
  Akibara, `/serie/<slug>/` y `/marca/<slug>/` son archives.
- **Capability** — un permiso con nombre. `manage_product_terms` es el que WooCommerce usa para los
  atributos de producto; `manage_categories` es el genérico de WordPress.
- **Caché de objetos persistente** — un almacén en memoria fuera de la base de datos. Con ella
  encendida, los transients **no pasan por `wp_options`**, y por eso borrarlos con un `DELETE` por SQL
  no funciona.
- **Cupón virtual** — un cupón que el código fabrica al vuelo para que WooCommerce lo aplique, y que
  no existe como registro en la base. Es la forma de descontar sin tocar el precio del producto.
- **Fallar cerrado** — ante un dato ilegible o desconocido, elegir la opción conservadora en vez de
  seguir adelante. Aquí: no emitir el nodo de schema, no entrar en el directorio, no avisar.
- **Generación (o testigo de caché)** — un valor que forma parte de la clave de una caché. Al
  cambiarlo, todas las claves antiguas quedan inalcanzables: es una invalidación que funciona aunque
  no se pueda enumerar lo guardado.
- **HPOS** (*High-Performance Order Storage*) — el modo moderno en que WooCommerce guarda los
  pedidos: en tablas propias (`wp_wc_orders`) en vez de como entradas de WordPress en
  `wp_posts`. Sólo uno de los dos manda a la vez, y al que manda se le llama **el almacén
  canónico de pedidos**. El código de esta área no lo da por supuesto: pregunta con
  `OrderStorage::uses_hpos()` y arma la consulta que corresponda.
- **HMAC** — una firma criptográfica corta que se adjunta a un enlace para probar que lo emitió el
  sitio y no un tercero. Se compara con `hash_equals()`, que tarda lo mismo acierte o falle.
- **Hook** — un punto del código donde WordPress se detiene y deja que otros añadan cosas.
  *Engancharse a un hook* es pedir turno para ejecutar algo en un momento concreto. La **prioridad**
  es el número que decide quién va antes: menor corre primero.
- **`INSERT IGNORE`** — una inserción que, si choca con una restricción única de la base, no falla:
  simplemente no inserta. Es lo que hace que republicar un tomo no vuelva a avisar.
- **JSON-LD** — un bloque de datos estructurados que va en el HTML y que leen Google y los asistentes
  de IA. `ComicSeries` es el tipo de schema.org para una serie de cómic.
- **`menu_order`** — un número entero que WordPress guarda por cada ficha y que WooCommerce usa para
  el orden «Destacados». Aquí lo gestiona una máquina: lo que se arrastre a mano se pisa.
- **Option** — un ajuste guardado en la base de datos con un nombre. *Autoload* significa que
  WordPress la carga en **todas** las peticiones, aunque nadie la use.
- **`noindex`** — una instrucción para que los buscadores no incluyan una página en sus resultados.
  En este sitio la guarda Rank Math en el term meta `rank_math_robots`.
- **Purga por etiqueta** — pedirle a LiteSpeed que invalide todo lo que lleve una marca, por ejemplo
  `T.<term_id>`, que cubre un archive **y todas sus páginas 2 y siguientes**. Distinta de la purga
  por URL, que invalida una dirección concreta.
- **Shortcode** — un marcador entre corchetes que se pega en el editor de una página y que el código
  sustituye por contenido. `[akibara_series_directory]` y `[akibara_brand_family]` son dos de los de
  este capítulo.
- **Snapshot (de descuento)** — la foto del aporte por línea que se sella dentro del pedido, para que
  el descuento se pueda reconstruir meses después aunque cambien los precios.
- **Taxonomía** — una familia de etiquetas organizadas que WordPress guarda aparte de las fichas.
  `pa_serie`, `product_brand` y `pa_autor` son las tres de este capítulo. Cada etiqueta concreta es un
  **término**, y cada término puede llevar datos propios: los **term meta**. De las tres,
  `pa_autor` es la única que **ninguna pieza de este capítulo construye**: su página
  `/autor/<slug>/` es el archive que WooCommerce genera solo para un atributo de producto, y
  aquí sólo aparece de dos formas — `SeriesFacts` **lee** sus términos para poner la fila
  «Autor …» del bloque de datos (`:127`, `:156-158`), y `edited_pa_autor` es uno de los
  eventos que invalidan ese bloque. Quién le pone título, descripción y schema a esa página es
  asunto del **capítulo 07 — SEO y schema** (`SeoEnhancements.php:320`, `:477-486`).
- **`term_id` y `term_taxonomy_id`: un término tiene DOS números** — y confundirlos es el
  error más fácil de cometer en esta área. El `term_id` identifica **la etiqueta** («One
  Piece») en la tabla `wp_terms`. El `term_taxonomy_id` —abreviado **ttid**— identifica **esa
  etiqueta dentro de una taxonomía concreta** («One Piece, como serie») en
  `wp_term_taxonomy`. Casi siempre valen distinto, y no son intercambiables. En este
  capítulo:
  - Usan **ttid**: la clave del transient `akb_series_facts_v3_<generacion>_<ttid>` y la
    option `akb_series_facts_generation_<ttid>` (`SeriesFacts.php:140`), y el `serie_tt` que
    calcula el orden del catálogo.
  - Usan **`term_id`**: las relaciones que el dueño escribe entre términos
    (`_akb_serie_ediciones_relacionadas`, `akibara_edition_series`) y **la etiqueta de purga
    de LiteSpeed, `T.<term_id>`**.

  Por eso `SeriesFacts` resuelve los pares con una consulta antes de purgar (`:612-638`): el
  dato de invalidación le llega como ttid y la purga lo necesita como `term_id`. Para pasar
  de uno a otro:

  ```bash
  wp term get pa_serie <slug> --by=slug --field=term_id
  wp term get pa_serie <slug> --by=slug --field=term_taxonomy_id
  ```
- **UCSS** (*Unique CSS*) — la poda de hojas de estilo de LiteSpeed: genera un CSS recortado a
  lo que la página usa de verdad. Una clase que sólo aparece cuando el JavaScript la añade no
  está en el HTML que LiteSpeed mira, así que la poda se la lleva y el elemento sale sin
  estilos. El filtro `litespeed_ucss_whitelist` es donde cada módulo declara a mano las suyas
  para que eso no pase. **Hoy UCSS está apagado en producción**, y esas declaraciones son
  inertes.
- **Transient** — un valor guardado con fecha de caducidad. Se usa como caché.
- **`WP_Term::count`** — el contador nativo de productos de un término. **Incluye** los productos
  ocultos del catálogo, y por eso casi toda el área usa `SeriesCatalogEligibility` en su lugar.

### business 1.118.0 (lote SEO, 2026-09-27): la serie en la miga de la ficha

La miga visible y el `BreadcrumbList` de cada ficha llevan un peldaño con la serie (Inicio / Manga / Seinen / **Serie** /
Tomo), y el enlace a la serie de la ficha usa la copy editable `akb_copy_pdp_series_link` («Ver los tomos de {serie}»,
Akibara → Textos; registrada en `FRONT_READ_OPTIONS`). Detalle y pruebas: `ops/coordinacion/abiertos/2026-09-26-seo-serie-en-miga-y-vitrina.md`.
