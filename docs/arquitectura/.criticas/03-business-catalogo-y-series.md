# Crítica de lector novato — `03-business-catalogo-y-series.md`

> Quién escribe: alguien que llega hoy al proyecto, sin WordPress, sin WooCommerce y sin
> conocer el negocio. Leí el capítulo entero y sus 7 diagramas, y después abrí el código del
> paquete publicado (`snapshot-prod/plugins/akibara-business`, 1.116.4) y el tema
> (`snapshot-prod/themes/akibara-gp`) para contrastar lo que me sonó dudoso.
>
> Lo digo de entrada porque se nota: el capítulo es bueno. Las citas de línea que revisé
> (unas 35) aciertan en la gran mayoría, la sección «Reglas de negocio» es utilizable, y el
> aviso sobre `VolumeVariant` es exactamente la clase de honestidad que necesito. Lo que
> sigue es lo que me dejó parado, no un veredicto sobre el conjunto.

---

## 1 · Lo que NO entendí

### 1.1 `litespeed_ucss_whitelist` aparece cinco veces y nunca se explica

**Dice:** «`litespeed_ucss_whitelist` @20» en `SeriesFacts`, `SeriesEditions`,
`BrandLandings`, `LandingOffer` y `SeriesRelatedGuide`.

No sé qué es. No está en el glosario, no está en el texto, y la palabra «UCSS» no aparece
en ninguna otra parte del capítulo.

**Debería decir:** una frase la primera vez —«LiteSpeed puede generar un CSS reducido a lo
que la página usa de verdad (UCSS); una clase que sólo aparece cuando el JavaScript la
añade se pierde en esa poda, así que estos módulos la declaran a mano»— y una entrada de
glosario. Sin eso, esas cinco líneas son ruido para mí y no puedo diagnosticar «el riel se
ve sin estilos».

### 1.2 `term_id` y `term_taxonomy_id` se usan como si fueran lo mismo

**Dice:** «el transient `akb_series_facts_v3_<generacion>_<ttid>`» y, tres líneas más
abajo, «`akb_series_facts_generation_<term_taxonomy_id>`»; pero `SeriesEditions` guarda
«array de hasta 8 `term_id`» y `EditionFacts` «CSV de hasta 6 `term_id`».

El glosario define «término» y «term meta», pero nunca dice que un término tiene **dos**
números distintos ni cuál se usa dónde. Si mañana quiero mirar el transient de una serie,
no sé qué número poner.

**Debería decir:** en el glosario, «cada término tiene un `term_id` (la etiqueta) y un
`term_taxonomy_id` (esa etiqueta dentro de una taxonomía concreta); las claves de caché de
`SeriesFacts` usan el segundo, las relaciones entre términos el primero», y cómo obtener
uno desde el otro.

### 1.3 «Dos datos que no se derivan» contra «los 4 campos que el dueño escribe a mano»

**Dice** la introducción: «existen exactamente **dos datos que no se derivan** y que el
dueño escribe a mano —el estado de la obra original y el de la edición que se vende—».
**Dice** el diagrama `piezas.mmd`: «PublicationStatus + PublicationStatusFields<br/>los 4
campos que el dueño escribe a mano en el término». **Dice** la sección de term meta: cuatro
claves (`_akb_serie_estado`, `_akb_serie_tomos_obra`, `_akb_serie_estado_edicion`,
`_akb_serie_tomos_edicion`).

Son dos **pares** (estado + total, por cada eje), pero el capítulo lo cuenta como dos, como
cuatro y como cuatro claves sin reconciliarlo nunca.

**Debería decir:** «dos ejes —la obra original y la edición que se vende— y dos campos por
eje: en qué estado está y cuántos tomos son. Cuatro term meta en total».

### 1.4 «El almacén canónico de pedidos» no se explica

**Dice:** «Lee: `wc_order_product_lookup`, `wc_order_stats`, `wc_customer_lookup` y el
almacén canónico de pedidos».

No sé qué es «el almacén canónico». En la tabla de datos aparece `wp_wc_orders` sin
comentario. En ninguna parte se dice que WooCommerce puede guardar los pedidos en dos
sitios distintos (HPOS o posts) y que este sitio usa el primero.

**Debería decir:** nombrarlo —«HPOS, las tablas propias de pedidos de WooCommerce:
`wp_wc_orders`; en este sitio están encendidas»— y ponerlo en el glosario.

### 1.5 «Reservable», «a pedido», «onbackorder» y «preventa» son cuatro palabras para (¿?) lo mismo

**Dice:** «incluye lo **reservable** (`instock` y `onbackorder`…) porque el filtro estricto
a "en bodega" dejaba fuera los 422 productos "A pedido"», y en las reglas: «Vocabulario
agregado: Disponible / A pedido / Agotado; por producto se distingue además "Preventa"».

Después de leer el capítulo entero sigo sin poder contestar: ¿«reservable» es exactamente
«a pedido»? ¿«preventa» es un estado de stock distinto o una etiqueta comercial encima del
mismo `onbackorder`? Ninguna de las cuatro palabras está en el glosario, y el negocio
completo (packs, correos, portadas, más vendidos) se decide con ellas.

**Debería decir:** un recuadro de cuatro líneas: el estado técnico de WooCommerce es uno de
tres (`instock`, `onbackorder`, `outofstock`); «reservable» = `onbackorder`; la tienda lo
muestra como «A pedido»; «Preventa» es una distinción adicional que vive en
`Stock\Availability` y **no** es un estado de stock. Y remitir al capítulo 04.

### 1.6 «Se lee fresco en cada render» en una página que está cacheada

**Dice** de `PublicationStatus`: «Y se lee **fresco en cada render**, fuera del cache de 6
horas, a propósito».

Dos párrafos antes se me explicó que la landing la sirve LiteSpeed desde una copia
guardada, y dos secciones después que hay que purgar por etiqueta `T.<term_id>` porque
guardar un término no invalida nada. Si la página está cacheada, «fresco en cada render» no
me llega a mí, el cliente. Las dos afirmaciones no se contradicen —son cachés distintas—
pero el capítulo no lo dice y yo no puedo deducirlo.

**Debería decir:** «fresco respecto del cache de 6 h de `SeriesFacts`; el cache de página de
LiteSpeed sigue por encima, y por eso al guardar el término se purga la etiqueta
`T.<term_id>`».

### 1.7 Los números del catálogo no cuadran entre sí

El capítulo usa, sin reconciliarlos: **1.466** («1.466 guardados dispararían el
reindexado»), **~1.566** («las ~1.566 fichas de producto»), **1.568** («recalcula el
`menu_order` de los 1.568 productos»); y para las series: **130** términos, **~104**
productivas, **119** series en la pantalla de packs, **104** landings en la introducción.

No sé cuántos productos tiene esta tienda ni cuántas series. Cada número está bien en su
origen (el de 1.466 es un comentario viejo del código, `CatalogOrder.php:378`), pero
puestos juntos parecen errores.

**Debería decir:** fijar una cifra de referencia con su fecha («~1.568 productos y 130
términos de serie, de los cuales ~104 tienen al menos un tomo en catálogo, medido el
2026-08-23») y, donde se cite otra, decir de dónde sale y por qué difiere.

### 1.8 «66 componentes» que no se pueden contar

**Dice:** «66 componentes en cinco dominios. Para cada uno: qué hace en una frase…».

En la sección hay 31 fichas `####`, muchas agrupando varias clases. No puedo verificar el
66 ni saber si falta alguna. Como lector nuevo, «66» me hace pensar que hay 35 piezas que
no me contaron.

**Debería decir:** o bien «66 clases agrupadas en 31 fichas», o bien listar las 66 en una
tabla de una línea cada una antes de las fichas.

### 1.9 `template_redirect` @1 de `LandingOffer` aparece y nunca se explica

**Dice:** «`LandingOffer` en `template_redirect` @1, `woocommerce_before_shop_loop` @**40**,
…».

Es el único hook de la lista que puede **cambiar la URL del cliente** y el capítulo no dice
qué hace. El código (`LandingOffer.php:823-888`) reescribe
`?_disponibilidad=onbackorder` a `?_disponibilidad=a_pedido` porque los chips emitían el
valor interno de WooCommerce y la faceta del buscador esperaba el comercial.

**Debería decir:** eso mismo, en una línea. Un redirect no documentado es media hora de
depuración cuando alguien se pregunta por qué su URL cambió sola.

### 1.10 Tres cosas parecidas con nombres parecidos

«Ediciones relacionadas» (`SeriesEditions`, term meta de `pa_serie`), «el conmutador de
ediciones» (`BrandLandingCallout`, sobre `product_brand`) y «familia» (`BrandLandings`)
aparecen mezcladas. En un punto leo «Otras ediciones en Akibara» y en otro «un enlace a la
edición hermana»; son dos mecanismos distintos, en dos taxonomías distintas, y el capítulo
nunca lo dice de frente.

**Debería decir:** una frase de deslinde: «hay dos parentescos y no se tocan: entre
**series** (lo escribe el dueño, `_akb_serie_ediciones_relacionadas`) y entre **editoriales
de la misma familia** (lo deriva una tabla del código)».

### 1.11 `Plugin.php` y `akibara_business_upgraded` se citan sin presentarlos

**Dice:** «son 66 piezas repartidas en módulos que el plugin cablea en `Plugin.php`» y
«`SeriesBundles` … **Cuándo actúa:** `akibara_business_upgraded` @10».

Ninguno de los dos se explica ni se remite a otro capítulo. El capítulo no enlaza nunca a
otro capítulo, y sin embargo depende de ellos.

**Debería decir:** «(ver capítulo 02 — núcleo)» junto a cada uno.

### 1.12 El asunto del correo se presenta como un literal y es una plantilla

**Dice:** «**Asunto:** «Salió el tomo 74 de Kingdom» — o «Salieron 4 tomos de Kingdom»».

El valor real es `'Salió el tomo {tomo} de {serie}{y_mas}'`
(`SeriesFollowEmail.php:77`). Si el dueño entra a cambiar el asunto y escribe texto plano,
pierde los datos. Ver también 2.6.

---

## 2 · Lo que FALTA para operar o depurar esto sin ayuda

### 2.1 No se nombra ni un solo registro (log) — y los hay

Cinco puntos de esta área escriben trazas con `Utilities\Log::warn()`, que va a
**WooCommerce → Estado → Registros** con fuente `akibara-<módulo>`:

| Dónde | Fuente | Qué dice |
|---|---|---|
| `CatalogOrder.php:458` | `akibara-catalog-order` | «Chunk de N menu_order falló: …» |
| `DigestRunner.php:190`, `:346`, `:367` | `akibara-series-follow` | fallos del digest |
| `DigestRunner.php:240` | `akibara-series-follow` | «Digest diferido: WC_Email todavía no cargable.» |
| `Install.php:80` | `akibara-series-follow` | la tabla de la cola no se creó |

El capítulo describe con detalle *que* un chunk puede fallar («Sin traza el fallo es
invisible», dice el propio código) y nunca dice **dónde se lee esa traza**. Es el único
canal de diagnóstico del área.

**Debería decir:** una sección «Dónde mirar cuando algo falla» con esa tabla.

### 2.2 Falta la mitad del comando de consola

**Dice:** «También hay un comando `wp akibara-catalog-order status`».

Hay dos (`CatalogOrder.php:73-79` y `:81-96`): `status` **y**
`wp akibara-catalog-order recompute`, que es el que arregla el orden sin depender del botón
del panel ni de Action Scheduler. Justamente el que necesito si el panel miente (ver 4.5).

### 2.3 No sé si esto está encendido hoy

Tres interruptores centrales vienen «por defecto **no**» (`akb_series_bundles_enabled`,
`akb_sf_enabled`) o «por defecto **yes**» (`akibara_home_series_enabled`), y el capítulo
dedica 30 páginas a «Arma tu Pack» y «Sigue esta serie» sin decir nunca si están vivos en
producción. El dato del 2026-09-06 («tres corridas del digest en toda la vida del módulo»)
sugiere que casi no, pero es una inferencia mía.

**Debería decir:** o el estado con fecha, o —mejor, porque no caduca— cómo comprobarlo:
`wp option get akb_series_bundles_enabled`, `wp option get akb_sf_enabled`, y para el
segundo interruptor del correo,
`wp option get woocommerce_akb_sf_series_settings --format=json`.

### 2.4 El grupo de Action Scheduler de «Sigue esta serie» no aparece

Se da `akb-catalog-order` pero para el otro sólo «(grupo de `SeriesFollow`)». El valor es
`akibara-series-follow` (`SeriesFollow.php:56`). Sin él no puedo filtrar
**Herramientas → Acciones programadas**, que es la pantalla donde se ve si la cadena del
digest sigue viva.

### 2.5 Ni el nombre real de la tabla ni los valores de `status`

La tabla se escribe `{prefijo}akb_series_follow_queue` mientras el resto del capítulo usa
`wp_posts` y `wp_options`. El prefijo real no se dice en ninguna parte. Y la columna
`status` se lista sin enumerar sus valores legales, que son seis (ver 3.3).

**Debería decir:** «`wp_akb_series_follow_queue` (con el prefijo de este sitio)» y la lista
de estados con una línea por estado.

### 2.6 El correo: faltan los marcadores, el encabezado y el identificador

Para operar el correo desde **WooCommerce → Ajustes → Correos** necesito tres cosas que no
están:

- Los marcadores admitidos: `{serie}`, `{tomo}`, `{n_tomos}`, `{tomos}`, `{y_mas}`,
  `{site_title}` (`SeriesFollowEmail.php:61-68`).
- El encabezado por defecto: «Novedades de tu colección» (`:87`).
- El identificador del correo: `akb_sf_series` (`:42`), que es lo que explica el nombre de
  la option `woocommerce_akb_sf_series_settings` y lo que se busca en la URL del ajuste.

### 2.7 Nadie me dice en qué página vive el directorio de series

**Dice:** «**Dónde lo opera el dueño:** crea una página y pega el shortcode. **El código no
crea páginas.**» Y más abajo: «el "Ver todas →" lleva `home_url( '/series/' )` escrito a
mano (`:169`), y ese slug lo ocupa hoy una redirección 301 hacia `/tienda/`».

Entonces: ¿existe hoy la página del directorio? ¿En qué URL? Porque si `/series/` es un 301
a `/tienda/`, la purga de `SeriesDirectory/Shortcode.php:155` tampoco está purgando el
directorio: está purgando una redirección.

**Debería decir:** la URL real del directorio hoy, y si la purga y el enlace apuntan a ella
o no. Tal como está, el capítulo describe un defecto y deja sin contestar si la superficie
siquiera existe.

### 2.8 Se me dice que un `DELETE` por SQL no sirve y no se me da la alternativa

**Dice:** «Las generaciones son un token opaco … la razón de que un `DELETE` de transients
por SQL no sirva».

Bien. ¿Y entonces cómo fuerzo yo que la landing de una serie se recalcule ahora? No hay
botón, no hay comando, y el capítulo no dice que rotar la option
`akb_series_facts_generation_<ttid>` sea la vía. Lo mismo para `akb_brands_grid_gen`,
`akb_home_destacados_generation` y `akb_sb_vols_cache_ver`: se listan como «sin pantalla
(interno)» y no se dice cómo tocarlos sin romper nada.

### 2.9 «Lo oculto del catálogo» nunca se traduce a una casilla

`exclude-from-catalog` aparece unas ocho veces y es la diferencia entre dos contadores que
el capítulo compara todo el rato. Nunca se dice que es la casilla **«Visibilidad del
catálogo»** de la ficha de producto, ni dónde está.

### 2.10 El escaneo de avisos se re-agenda a sí mismo y no se advierte

`DigestRunner::maybe_paginate()` (`:711-730`) vuelve a encolar `akb_sf_scan_product` con un
cursor cuando la página de compradores viene llena. Ver cinco acciones del mismo producto
en la cola es **normal**, y el capítulo —que sí dice «páginas de 200»— no lo menciona.
Quien mire la pantalla de acciones programadas va a creer que hay duplicados.

### 2.11 No hay ninguna ruta de rescate

El capítulo enumera con honestidad los modos de fallo (la cola quieta porque el correo de
WooCommerce está apagado; el `menu_order` en 0 de un producto publicado durante una
corrida; la tarjeta de edición 12 h obsoleta). No hay una sola instrucción de qué hacer
cuando ocurren.

**Debería decir:** una sección final «Si esto pasa, haz esto», con tres o cuatro entradas y
comandos concretos.

### 2.12 `maybe_schedule_daily()` sólo corre en admin, cron o consola

`CatalogOrder.php:126-131` sale temprano si la petición no es de backend. El capítulo dice
«`init` @**20** (para agendar la diaria)» sin la condición. En un entorno local donde nadie
entra al admin y el cron no corre, la diaria **nunca** se agenda, y el lector concluirá que
el código está roto.

### 2.13 El panel se nombra por menús, nunca por URL

«Akibara → Venta → "Vitrina"», «Akibara → Sitio», «Akibara → Textos y Copys». Para alguien
que no conoce WordPress, una URL (`/wp-admin/admin.php?page=akibara-panel&tab=…`) vale más
que un camino de menú, y además el slug del panel tiene trampa conocida (`akibara-panel`,
no `akibara`).

---

## 3 · Lo que CONTRADICE al código

### 3.1 «Ninguna purga de LiteSpeed propaga al borde» — el propio `CatalogOrder` dice lo contrario

**Dice el capítulo** (tabla «Con quién habla», fila Cloudflare): «**Ninguna llamada
directa.** Se le nombra en los avisos del panel, pero ninguna purga de LiteSpeed propaga al
borde (`CatalogOrder.php:478-481`)».

**Dice el código**, 370 líneas más arriba, `CatalogOrder.php:110-112`:

> «el `litespeed_purge_all` de abajo, que con `cdn-cloudflare_clear` propaga al BORDE y
> devuelve la landing de serie de 48 a 1.057 ms»

Lo que dice `:478-481` es que las purgas **por etiqueta** no propagan. La generalización del
capítulo borra la distinción y, con ella, el único mecanismo del área que sí llega a
Cloudflare: `litespeed_purge_all`, que disparan la corrida diaria (`:486-487`) y el panel de
ediciones cuando cambia el texto del menú (`EditionsPanel.php:280-290`).

**Debería decir:** «purga **por etiqueta** y **por URL**: se quedan en el servidor. Purga
**entera** (`litespeed_purge_all`): con el complemento `cdn-cloudflare_clear` sí llega al
borde, y por eso cuesta cara —medido, la landing de serie pasa de 48 a 1.057 ms— y por eso
la corrida diaria es a las 05:00 y no a las 06:00».

Consecuencia práctica de la confusión: el aviso del panel de ediciones que el capítulo
critica en el Caso 3 («el borde las renueva en unos minutos») **sólo sale en la rama de
purga por URL** (`EditionsPanel.php:246`); la rama que sí llega al borde imprime otro texto
y no promete nada. Es decir: el texto que promete el borde es el del camino que no lo toca,
y viceversa. Eso es lo que habría que contar.

### 3.2 «Los más vendidos caen al fallback propio del tema» — el tema no tiene fallback

**Dice el capítulo:** «Con otro tema … los más vendidos caen al fallback propio del tema».

**Dice el código**, `themes/akibara-gp/template-parts/empty/cross-sell.php:45-47`:

```php
if ( empty( $akb_popular_ids ) ) {
	$akb_popular_ids = array( 0 ); // fallback to prevent showing all
}
```

`post__in => array( 0 )` no devuelve ningún producto: la sección entera desaparece. El
«fallback» existe para **no** mostrar el catálogo completo, no para mostrar otra cosa.

**Debería decir:** «sin el plugin, la fila de más vendidos no se pinta: el tema fuerza un
resultado vacío a propósito para no acabar mostrando el catálogo entero».

### 3.3 La cola de avisos tiene SEIS estados, no cuatro

**Dice el capítulo** (y el diagrama de estados): cuatro estados —`pending`, `sent`,
`coalesced`, `failed`— más `expired`.

**Dice el código**, `DigestQueue.php:56-62`: `coalesced`, `pending`, `sent`, **`dropped`**,
`expired`, `failed`. Y `dropped` no es teórico: lo escribe `DigestRunner` en tres sitios
(`:410` opt-out, `:447` ya lo tiene, `:471` despublicado) con tres motivos nombrados
(`DROP_OPTOUT`, `DROP_OWNED`, `DROP_UNPUBLISHED`).

Son exactamente las tres revalidaciones que el capítulo describe en el Caso 1 paso 9 —«se
revalida **ahora** la baja, la propiedad y la publicación»— sin decir qué queda escrito en
la tabla. Quien abra `wp_akb_series_follow_queue` va a ver un estado que la documentación
niega que exista.

### 3.4 `expired` no borra la fila

**Dice el diagrama** `estados-cola-avisos.mmd`: `state "expired · borrada" as expired`.

**Dice el código**, `DigestQueue.php:306-313`: es un `UPDATE … SET status = 'expired'`. La
fila se queda. Que se quede importa: con el `UNIQUE` intacto, ese tomo tampoco se volverá a
avisar nunca.

### 3.5 «Un tomo no se avisa dos veces» tiene una excepción deliberada

**Dice el capítulo** (regla): «Un tomo no se avisa dos veces: lo garantiza una restricción
única de la base».

**Dice el código**, `DigestQueue::drop()` `:276-283`: con el motivo `DROP_UNPUBLISHED` la
fila se **borra** (`DELETE`), no se marca. Es a propósito —un tomo que se despublicó y se
vuelve a publicar sí debe poder avisarse— pero es precisamente el agujero de la garantía
absoluta que el capítulo enuncia.

Y el otro lado de la moneda, que tampoco está: si un cliente se da de baja y luego vuelve
a activarse desde Mi cuenta → Colección, las filas que se descartaron durante la baja
quedan en `dropped`, que es terminal. **Los tomos de ese periodo no se recuperan.** El Caso
2 termina con «La página de "Listo" dice cómo volver» y el lector se queda creyendo que
volver es reversible del todo.

### 3.6 Cita equivocada: «máximo 5 series por correo»

**Dice:** «Acumulación de N horas (6) y máximo 5 series por correo | `SeriesFollow.php:64-66`
· `DigestRunner.php:509`».

`DigestRunner.php:509` es `'ids' => $by_term[ $term_id ]['ids'] ?? array(),`, dentro del
agrupado. El corte está en **`:528`**: `array_slice( $by_term, 0, SeriesFollow::max_series_per_email(), true )`.

### 3.7 Cita equivocada: el contador de «reordenados»

**Dice:** «el contador de "reordenados" suma `count( $ids )` … (`:440-447`), contra lo que
promete su propio docblock (`:373-378`)».

En el paquete publicado: el `UPDATE` está en **`:451-453`**, el `$applied += count( $ids );`
en **`:464`**, y la frase del docblock que lo contradice («El retorno cuenta lo APLICADO, no
lo propuesto») en **`:381`** (el docblock va de `:376` a `:389`). Ninguno de los dos rangos
citados contiene lo que se afirma. Como el capítulo promete «cada afirmación técnica lleva
su fichero y su línea», una cita que no aguanta el `sed -n` cuesta confianza en las otras
200.

### 3.8 Cita equivocada, y el capítulo se contradice consigo mismo

**Dice** la tabla de reglas: «El armador de la ficha **no pinta** los reservables … (contra
`LandingOffer.php:308` y `:682-684`)» — pero dos filas más abajo: «Los reservables sólo
cuentan si el dueño lo enciende … `Settings.php:141-152` · `LandingOffer.php:688-690`».

El copy «Cuentan los tomos disponibles ahora; los a pedido no. Puedes agregarlos juntos
igual» está en **`LandingOffer.php:683`**. `:688-690` es el `<button>` del CTA. La primera
cita del capítulo es correcta y la segunda no.

### 3.9 La guarda del interruptor no está donde se cita

**Dice:** «Con el interruptor apagado `PublishListener` ni siquiera encola
(`SeriesFollow.php:335-344`)».

`SeriesFollow.php:335-344` es la definición de `enabled()`. La comprobación que hace que
`PublishListener` no encole está en **`PublishListener.php:77`** y **`:104`**. Citar la
definición del método en vez del sitio donde se llama deja al lector buscando en el fichero
equivocado.

### 3.10 Otras tres citas que no aguantan la lectura

| Afirmación | Cita del capítulo | Dónde está de verdad |
|---|---|---|
| `CatalogOrder::maybe_schedule_daily()` en `init` @20 | `CatalogOrder.php:160-188` | el método es `:122-164`; `:166-188` es `next_daily_timestamp()` |
| «se publicó hace menos de 60 días» | `DigestRunner.php:65` · `:677-684` | `:65` sí (`NOVELTY_DAYS`); la comprobación es `:695-700`, no el docblock `:675-684` |
| «Un cambio de stock **no** dispara el recálculo del orden» | `CatalogOrder.php:112-134` · `:221-231` | la guarda real es `on_status_change()`, `:218-227` (con su docblock en `:209-212`); `:112-134` es el docblock de la diaria |

### 3.11 «Los más vendidos aparecen en el 404, en el carrito vacío y en "sin resultados"»

En el tema publicado el filtro `akibara/popular_product_ids` se invoca **una sola vez**, con
tamaño **4**, desde el helper `akibara_get_popular_cross_sell_ids()`
(`themes/akibara-gp/inc/performance.php:300-301`), y ese helper lo usa **una sola** plantilla
(`template-parts/empty/cross-sell.php:44`). El capítulo repite la lista de tres superficies
que aparece en un comentario del plugin (`PopularProducts.php:140-141`) sin contrastarla con
el tema.

Consecuencia sobre la propia regla del capítulo: «un tamaño nuevo pedido por el tema nunca
se invalidaría» es cierto **en potencia**, pero hoy el único tamaño pedido es 4, que **sí**
está en la lista fija. El capítulo presenta como trampa viva algo que hoy no muerde, y eso
también desinforma.

### 3.12 «`LandingOffer` **escribe** la faceta real `_disponibilidad`»

`_disponibilidad` es `LandingOffer::STOCK_FILTER_PARAM` (`:64`): un **parámetro de la URL**
que los chips emiten y que el buscador lee, más el redirect de `:823-888` que traduce el
valor antiguo. No se escribe nada en ninguna parte. Para un lector nuevo, «escribe» sugiere
base de datos.

---

## 4 · Lo que contradice o ignora `verificacion.json` del área

*(Leí `mapas/biz-catalogo-series/verificacion.json`: 6 omisiones, 5 errores, 7 sospechas
nuevas, 4 refutadas, 14 confirmadas, cobertura estimada 88 %.)*

Primero lo justo: el capítulo **sí** incorporó la mayoría. Corrigió la cita del selector de
orden (error 1 → ahora `akibara-business.php:321`, `:326` y `:344`, verificado y correcto);
corrigió «siete piezas» por 14 clases (error 5); corrigió el rango de
`BrandsDirectory.php:16-17` y añadió la aparición de `pwb-brand` en el tema (error 4);
recogió el matiz de `hub_url()` (refutada 1) con la frase exacta «resuelve el permalink, no
la independencia de slug»; recogió la mitad que faltaba de `SeriesCover` (sospecha 4), el
`add_option_` (sospecha 6) y la nota sobre `List-Unsubscribe-Post` (refutada 3). Eso está
bien hecho.

Lo que queda fuera:

### 4.1 La línea que puede tumbar `/editoriales/` no aparece

`verificacion.json` → confirmada 10: «`BrandsDirectory/Shortcode.php:280` es el ÚNICO
`esc_url( get_term_link( … ) )` sin guarda de todo el área … `get_term_link()` puede
devolver `WP_Error`, y `esc_url()` acaba llamando `ltrim()` sobre él, que en PHP 8 es un
TypeError».

Lo verifiqué: la línea es

```php
esc_url( get_term_link( $brand ) ),
```

y no hay `is_wp_error()` en el camino. El capítulo describe `BrandsDirectory\Shortcode` con
detalle (el contador de generación, la resolución del permalink) y no menciona esto, que es
el único riesgo de página caída del dominio de editoriales.

### 4.2 La columna del admin que afirma un número que la landing calla

`verificacion.json` → confirmada 12: `PublicationStatusFields.php:171-173` concatena siempre
« · N tomos», mientras `PublicationStatus::edition_phrase()` (`:403-432`) sólo publica el
total con «Completa» y «En publicación».

El capítulo recoge la regla del lado público («El total de la edición se publica con
"Completa" y "En publicación"; con "Al día", "En pausa" o "Cancelada", no») y **no** el
desfase con la columna que el dueño mira. Es exactamente el patrón «la promesa contra el
hecho»: el dueño ve «Al día · 23 tomos» en el listado y no lo ve en la landing, y va a
creer que la landing está rota.

### 4.3 El coste del SQL del orden del catálogo

`verificacion.json` → confirmada 13: en `CatalogOrder::recompute()` (`:389-400`) el
predicado `tt.taxonomy = 'pa_serie'` está en el `LEFT JOIN`, no en el `WHERE`, así que cada
producto se multiplica por todas sus relaciones de término antes del `GROUP BY`. El
resultado es correcto; el coste no. El capítulo, que dedica párrafos a por qué esta consulta
no puede correr en cada venta, no lo menciona.

### 4.4 El renombrado de un término es un riesgo de área, no de una pieza

`verificacion.json` → error 2 y refutada 2: cinco lugares filtran la visibilidad por
`'field' => 'name'` — `SeriesCover.php:87`, `HomeDestacados.php:236`,
`PopularProducts.php:118`, `EditionFacts.php:292` y `:331` — mientras las dos consultas SQL
propias van por slug (`SeriesCatalogEligibility.php:211`, `SeriesFacts.php:1479`).

El capítulo sólo lo dice de `PopularProducts` («excluye … filtrando `'field' => 'name'`
(`:116-119`)»), como si fuera una curiosidad de esa pieza. No lo es: si alguien renombra el
término de visibilidad, se caen la portada de serie, la vitrina del inicio, los más
vendidos y las tarjetas de edición **a la vez**, y las dos consultas por slug siguen
funcionando, que es lo que hace el diagnóstico endiablado.

### 4.5 La sospecha nueva 1 se cuenta a medias

El capítulo recoge la guarda (`as_next_scheduled_action()` cuenta las acciones en curso) y
la contradicción del docblock. No recoge la mitad operativa: cuando la guarda se dispara, el
botón devuelve `ya-encolada` y el panel imprime **«Ya había un recálculo en cola; no se
duplicó»** (`CatalogOrder.php:321`) aunque no haya nada pendiente y el cambio del dueño no
vaya a entrar hasta las 05:00. Es la única pantalla que el dueño tiene para esto, y le está
mintiendo.

### 4.6 La sospecha nueva 2 se describe sin enunciar la consecuencia

El capítulo dice: «la clave del transient es `md5(option_slugs)` a secas (`:295-297`),
**sin el atributo `count` del shortcode**, aunque lo que se cachea es el array ya recortado
a `$count` (`:152`)». Todo cierto y verificado. Pero nunca dice lo que el dueño va a vivir:
**cambiar `[akb_series_destacadas count=12]` no cambia nada durante 6 horas**, y va a
concluir que el bloque está roto. La gemela `HomeDestacados` sí versiona la clave con
`count`, `columns` y `max_per_serie` (`:230-236`) — ese contraste tampoco está.

### 4.7 La cobertura declarada no se refleja en el tono

`verificacion.json` → notas: «No leí entero `SeriesBundles` (8.390 líneas: `BundleCouponEngine`,
`DiscountEngine`, `CartSeriesCollector`, `OrderInstrumentation`, `PromotionPolicy`,
`BypConfigController`), así que las cuatro sospechas del mapa que viven ahí … quedan SIN
verificar» y «Nada de esto se midió en el espejo ni en producción: es lectura de código
sobre el snapshot congelado».

El capítulo afirma lo de «Arma tu Pack» —el 30 % del texto— con la misma seguridad que lo
verificado línea a línea, y presenta como hechos medidos cifras que en realidad son
comentarios del código (los 1.122 de 1.568, los 48→1.057 ms, «6 de 7 peticiones al origen
devolvían `x-litespeed-cache: hit`», «347 KB con el interruptor maestro en el byte
344.191»).

**Debería decir:** de dónde sale cada medición y cuándo se tomó —«medido en producción el
2026-08-23 y anotado en `CatalogOrder.php:107-114`»— y marcar lo que no se ha vuelto a
comprobar. Un número sin procedencia envejece sin avisar.

---

## 5 · Diagramas

### 5.1 Validación

Los **7** validan con `mmdc` sin errores (`rc=0`, SVG generado):
`piezas`, `cron`, `estados-cola-avisos`, `caso-aviso-de-serie`, `caso-landing-editorial`,
`caso-arma-tu-pack`, `caso-tomo-nuevo`.

### 5.2 La numeración de los casos no coincide con el capítulo

- `caso-arma-tu-pack.mmd` línea 1: «**Caso 2** del capítulo 03» → en el capítulo es el
  **Caso 4**.
- `caso-tomo-nuevo.mmd` línea 1: «**Caso 4** del capítulo 03» → en el capítulo es el
  **Caso 6**.

(`caso-aviso-de-serie` = Caso 1 y `caso-landing-editorial` = Caso 3 sí coinciden.) El
comentario no se renderiza, pero el `.mmd` es un fichero que se abre.

### 5.3 Tres de los siete casos no tienen diagrama

Sin diagrama: Caso 2 (la baja), Caso 5 (los cuatro órdenes) y Caso 7 (el estado de
publicación). El **Caso 5** es el que más lo pide: es puro «cuatro reglas distintas para lo
mismo» y en texto plano hay que releerlo dos veces.

### 5.4 `caso-tomo-nuevo.mmd` promete nueve superficies y dibuja ocho

El `par` dice «Las nueve superficies se enteran a la vez» y tiene 8 ramas (`Facts`, `Dir`,
`Cord`, `Home`, `EF`, `Pop`, `BW`, `PL`). La novena de la tabla del capítulo —**el buscador
`akibara-search`, que reindexa por `set_object_terms`**— no está ni como participante.
Justo la que dispara el efecto más caro de la casa.

### 5.5 `piezas.mmd`: piezas que el texto nombra y el diagrama no tiene

- **`Interleaver`**: el texto le dedica una ficha («agrupa por serie, ordena, reparte por
  rondas»). En el diagrama existe un nodo con id `Interl` pero **etiquetado como dato**:
  `Interl["menu_order de los 1.568 productos"]`. El id promete una clase y la etiqueta
  entrega una tabla. Debería haber un nodo `Interleaver` propio.
- **`BypCartNoticeProvider`** — no aparece.
- **`Admin\AdminSection`, `Admin\BypConfigController`, `Admin\AdminAssets`** — no aparecen:
  la pantalla de «Arma tu Pack», que es donde el dueño configura todo el dominio y la que el
  texto describe como la que pesa 347 KB, no está en el mapa de piezas.
- **`SeriesFollow` (raíz) e `Install`** — no aparecen: el panel del módulo y quien crea la
  tabla quedan fuera.
- **`BrandsDirectory` (bootstrap)** — no aparece, pese a ser «el fichero que un lector nuevo
  abre primero» según el propio capítulo.

### 5.6 `piezas.mmd`: tres flechas que un novato lee mal

- `FF --> OI`: se lee «FollowerFinder alimenta OrderInstrumentation», que es falso. Lo real
  es que `FollowerFinder` reusa `CustomerCollection`, que está **fundida** en el nodo `OI`
  junto a `OrderInstrumentation`. Dos responsabilidades muy distintas en un nodo.
  **Arreglo:** separar `CustomerCollection` en su propio nodo y apuntar `FF --> CC`.
- `CLIENTE --> MAIL`: la flecha va del cliente al correo. El correo va **al** cliente.
- `LO --> FACTS`: el texto no dice en ninguna parte que `LandingOffer` use `SeriesFacts`, y
  `verificacion.json` deja «la segunda instancia de `SeriesFacts` en `LandingOffer`» como
  sospecha **sin verificar**. El diagrama afirma como hecho una arista que el texto no
  explica y la verificación no confirmó.

### 5.7 `piezas.mmd`: el nodo `pa_autor` no tiene dueño

El diagrama declara `AUTOR["Taxonomía pa_autor … su página es /autor/&lt;slug&gt;/"]`. En
todo el capítulo `pa_autor` sólo aparece como un hook de invalidación
(`edited_pa_autor`) y como la palabra «Autor …» dentro del bloque de datos. Ninguna pieza
construye esa página. O el nodo sobra, o falta la frase que explique quién sirve
`/autor/<slug>/`.

### 5.8 `piezas.mmd`: promete «dónde entra el dueño» y sólo dibuja seis entradas

Hay `DUENO -->` hacia `PUB`, `EFACTS`, `EDIC`, `HSD`, `SET` y `CORD`. Faltan, según el
propio texto: Productos → Marcas (`REGION`, `BrandFeatured`), Apariencia → Menús (`MENU`),
la columna «Guía» (`GUIA`), Akibara → Textos y Copys, y **WooCommerce → Ajustes → Correos**
(`MAIL`), que es el segundo interruptor del que el panel no informa — el que el capítulo
considera importante y el diagrama deja invisible.

### 5.9 `piezas.mmd`: los colores no se explican

Hay cinco `classDef` (`propio`, `tercero`, `persona`, `dato`, `alerta`) y ninguna leyenda.
Un lector nuevo no sabe que naranja = código propio, gris = tercero, rojo = el que nos
muerde. `alerta` se usa una sola vez (Cloudflare) sin decir por qué.

### 5.10 `estados-cola-avisos.mmd`: le falta un estado y miente en otro

Ver 3.3 y 3.4: falta `dropped` (con sus tres motivos) y `expired` está etiquetado «borrada»
cuando es un `UPDATE`. El comentario de cabecera —«los cuatro estados de la tabla
`akb_series_follow_queue`»— es incorrecto: son seis.

### 5.11 `cron.mmd`: «Cada minuto» se contradice con su propia cabecera

Dice «Cada minuto : `action_scheduler_run_queue` — el motor de la cola de WooCommerce» y,
dos líneas arriba, «`DISABLE_WP_CRON` está encendido, el tráfico NO empuja nada» y «Cada 5
minutos: la tarea de Hostinger llama a `wp-cron.php`».

Si nada empuja el cron salvo Hostinger cada 5 minutos, la cola **no** corre cada minuto:
está *agendada* cada minuto y se *ejecuta* cada 5. Para alguien que intenta explicar por qué
un aviso tardó 7 minutos, la diferencia es todo.

**Debería decir:** «agendada cada minuto; sólo corre cuando el cron de Hostinger empuja, o
sea cada 5 minutos».

### 5.12 Se entienden solos, con una salvedad

Los cuatro diagramas de caso son los mejores del conjunto: las `Note over` llevan el porqué,
no sólo el qué, y se pueden leer sin el capítulo. `piezas.mmd` **no** se entiende solo: 40
nodos, sin leyenda de colores, y con los tres problemas de flechas de 5.6. Un novato que
empiece por ahí sale con un modelo mental equivocado de quién llama a quién.

---

## Lo que me llevo como lector nuevo

Puedo explicar el área. **No** puedo operarla: no sé si los módulos están encendidos, no sé
dónde se leen los errores, no sé el nombre real de la tabla ni los estados de sus filas, y
no tengo una sola instrucción de rescate. Las tres cosas que arreglaría primero son 2.1
(los registros), 2.3 (el estado de los interruptores) y 3.1 (Cloudflare), porque las tres
cambian lo que haría yo delante de una incidencia.

---

## Respuesta del redactor

Aplicados **todos** los puntos salvo los tres matices que van al final. Gracias en
particular por 3.1, 3.3 y 2.1: los tres cambian lo que haría alguien delante de una
incidencia, y los tres estaban mal o ausentes.

### Lo que se comprobó contra el código y se corrigió tal cual

- **3.1** — Confirmado. `CatalogOrder.php:110-112` dice que `litespeed_purge_all`, con
  `cdn-cloudflare_clear`, **sí** propaga al borde. El capítulo generalizaba `:478-481`, que
  sólo habla de las purgas por etiqueta. Corregido en la tabla «Con quién habla», en una
  sección nueva («La caché tiene dos pisos»), en el Caso 3, en `piezas.mmd`, en
  `caso-tomo-nuevo.mmd`, en `caso-landing-editorial.mmd` y en `cron.mmd`. Y confirmada
  también la consecuencia que señalas: los dos avisos de `EditionsPanel.php:243-248` están
  cruzados — el que promete el borde es el de la rama por URL (`:288`), y el de la rama que
  sí llega (`:284`) no promete nada. Ahora está contado así, y dibujado en el `alt` del
  diagrama del Caso 3.
- **3.2** — Confirmado. `cross-sell.php:45-47` fuerza `post__in => array( 0 )`; el propio
  comentario del tema dice *fallback to prevent showing all*. «Caen al fallback del tema» era
  falso: sin el plugin la sección desaparece.
- **3.3, 3.4, 3.5** — Confirmados los tres. `DigestQueue.php:57-62` declara seis estados;
  `expire_older_than()` (`:302-317`) es un `UPDATE`; y `drop()` con `DROP_UNPUBLISHED`
  (`:276-283`) hace `DELETE`. Añadido un recuadro con los seis estados y sus motivos,
  reescrito `estados-cola-avisos.mmd` (seis estados + un nodo «la fila deja de existir»), y
  añadido el punto que faltaba: **`dropped` es terminal, así que reactivarse no recupera los
  tomos del periodo de baja.** Eso también está ahora en el Caso 2 y en
  `caso-aviso-de-serie.mmd`.
- **3.6, 3.7, 3.8, 3.9, 3.10** — Las cinco citas estaban mal; corregidas todas contra el
  paquete. En detalle, y con el número que quedó: el corte de series, `DigestRunner.php:528`.
  El contador de reordenados, `CatalogOrder.php:464` contra su docblock de `:381`, con el
  `UPDATE` en `:451-453`. El copy de los reservables, `LandingOffer.php:683` (y
  `Settings::allows_backorder()` en `:146-151`). La guarda del interruptor,
  `PublishListener.php:77` y `:104`. `maybe_schedule_daily()`, `:122-164`, con
  `next_daily_timestamp()` en `:174-188`. Los 60 días, `NOVELTY_DAYS` en `:65` y la
  comprobación en `product_is_announceable()`, `:685-702`. La guarda del stock,
  `on_status_change()`, `:218-227` con su docblock en `:209-217`.
- **3.12** — Confirmado: `_disponibilidad` es `STOCK_FILTER_PARAM` (`:64`), un parámetro de
  la **URL**. «Escribe» cambiado por «emite».
- **4.1** a **4.6** — Confirmados los seis y todos incorporados: el `get_term_link()` sin
  guarda de `Shortcode.php:280`; el desfase entre la columna del admin
  (`handle_admin_column_content()`, `:174-176`) y `edition_phrase()` (`:404-432`); el
  predicado `tt.taxonomy` en el `LEFT JOIN` de `:397-408`; el renombrado del término de
  visibilidad como riesgo **de área** (con las cinco identificaciones por nombre y las dos
  por slug, en un recuadro propio); el «Ya había un recálculo en cola» de `:321`; y la
  consecuencia de la clave sin `count`, con el contraste explícito contra `HomeDestacados`.
- **4.7** — Aceptado. El capítulo abre ahora con una tabla de cifras de referencia con su
  fecha y su fichero, y el dominio «Arma tu Pack» lleva un aviso de procedencia que dice
  exactamente qué se leyó entero, qué no, y que **nada de este capítulo se midió en vivo**.
- **1.1 a 1.12, 2.1 a 2.13** — Aplicados todos. Lo nuevo: la explicación de UCSS y su entrada
  de glosario (con el dato de que hoy es inerte, `SeriesFacts.php:392-393`); `term_id` vs
  `term_taxonomy_id` en el glosario con los comandos para pasar de uno a otro; «dos ejes, dos
  campos, cuatro term meta»; HPOS explicado y en el glosario; el recuadro de las cuatro
  palabras de la disponibilidad; el matiz de «fresco en cada render»; «31 fichas que cubren
  67 clases» (contadas: 14 en «Sigue esta serie», 21 en «Arma tu Pack», el resto en los tres
  primeros dominios); el `template_redirect` @1 explicado; el deslinde de los dos
  parentescos; las remisiones al capítulo 02; el asunto del correo como plantilla; una
  sección nueva **«Dónde mirar cuando algo falla»** con la tabla de registros; los dos
  comandos del CLI; los comandos para saber si los interruptores están encendidos hoy; el
  grupo `akibara-series-follow`; `wp_akb_series_follow_queue` con su prefijo y sus seis
  estados; los marcadores, el encabezado y el identificador del correo; cómo forzar un
  recálculo rotando la generación; «Visibilidad del catálogo»; el auto-reencolado de
  `maybe_paginate()`; una sección nueva **«Si esto pasa, haz esto»** con cuatro rutas de
  rescate y una lista de lo que no hay que hacer; la condición de backend de
  `maybe_schedule_daily()`; y una tabla que traduce cada camino de menú a su
  `?page=akibara-panel&tab=…`.
- **5.1 a 5.11** — Aplicados. Numeración de los casos corregida (`arma-tu-pack` → Caso 4,
  `tomo-nuevo` → Caso 6); `akibara-search` añadido como novena rama de `caso-tomo-nuevo`;
  `piezas.mmd` con leyenda de colores, nodo `Interleaver` separado del dato `menu_order`,
  `BypCartNoticeProvider`, el trío de `Admin`, `SeriesFollow` + `Install`, el bootstrap
  `BrandsDirectory`, `CustomerCollection` en nodo propio (con `FF --> CC`), la flecha del
  correo invertida (`MAIL --> CLIENTE`), el nodo `pa_autor` con dueño explícito, y las seis
  entradas del dueño que faltaban; `cron.mmd` con «agendada cada minuto, se ejecuta cada 5».
  Y **hay un diagrama nuevo para el Caso 5**, los cuatro órdenes
  (`03-business-catalogo-y-series-caso-cuatro-ordenes.mmd`), que era el que más lo pedía.
  Los ocho `.mmd` validan con `mmdc`, `rc=0`.

### Dos cosas donde te equivocaste, y una donde el capítulo estaba peor de lo que creías

**3.11 — «una sola plantilla»: medio acierto.** Tienes razón en lo que importa: el filtro
`akibara/popular_product_ids` se invoca una sola vez, con tamaño **4**
(`inc/performance.php:300-301`), así que la trampa de la lista fija de tamaños hoy **no
muerde** — y el capítulo la presentaba como viva. Eso está corregido, y además queda anotado
que el comentario del propio plugin (`PopularProducts.php:139-141`) es el que está
desactualizado: dice que el tema llama «con 4 y 6», y hoy sólo llama con 4.

Pero la lista de tres superficies del capítulo **sí es correcta**, y no salía sólo del
comentario del plugin. El ayudante lo usa una sola *plantilla*,
`template-parts/empty/cross-sell.php`, pero esa plantilla es un `template-part` que se
incluye desde **tres** sitios del tema publicado: `404.php:97`,
`woocommerce/cart/cart-empty.php:76` y `woocommerce/loop/no-products-found.php:100`. Tres
superficies, un solo tamaño. El capítulo ahora lo dice con esas tres citas, para que nadie
tenga que volver a comprobarlo.

**5.6, tercer punto — `LO --> FACTS` sí es un hecho.** `LandingOffer.php:777-778` hace
`( new SeriesFacts() )->catalog_facts( $term_taxonomy_id )`, y su cabecera lo dice en
`:20-21`. La arista del diagrama era correcta; lo que faltaba era la frase en el texto que la
explicara, y ahora está (con el detalle de que es una **segunda instancia**, distinta de la
que comparten el bloque de datos y el JSON-LD). Los otros dos puntos de 5.6 eran correctos y
están arreglados.

**2.7 — el defecto era el contrario del que creías, y es el hallazgo más útil de tu
crítica.** Preguntas si la página del directorio existe, dado que `/series/` sería un 301 a
`/tienda/`. Fui a mirar: **`/series/` es el directorio, y el 301 es lo que el módulo vino a
reemplazar.** Los comentarios que dicen «`/series/` es hoy una redirección 301 hacia
`/tienda/` con 69 impactos» (`Shortcode.php:10-13`, `Plugin.php:1147-1153`) son el
*argumento de por qué se construyó el módulo* —«la demanda de esta página está medida, y lo
que había en su lugar era un desvío»—, no una descripción del presente. Lo confirma el resto
del código: `BrandLandingSchema.php:12-25` anota, medido el 2026-08-18, que el hub `/series/`
está «Submitted and indexed» con 104 elementos; `SeoEnhancements.php:745-760` le adjunta un
`CollectionPage`; y `SeriesDirectorySearchPolicy` existe precisamente para gobernar la
política de URL de esa página, incluido convertir `/series/page/N/` en un 404 real. Así que
la purga de `Shortcode.php:155` y el «Ver todas →» apuntan a la página buena.

**El capítulo repetía ese comentario como si fuera el estado actual, en dos sitios.** Era un
error mío, no una omisión: leí una justificación histórica como un hecho vigente. Corregido,
con el aviso explícito de que esos dos comentarios del código confunden, y con el `curl` para
comprobarlo en cualquier momento sin volver a discutirlo.

### Lo que sigue sin poder contestarse desde el código

Nada de este capítulo se ha medido en vivo para esta revisión: sigue siendo lectura del
paquete congelado. En particular, el estado real de los tres interruptores en producción
**hoy** no se afirma en ninguna parte, a propósito; lo que el capítulo da ahora son los
comandos para averiguarlo, que es lo que pedías en 2.3 y no caduca.
