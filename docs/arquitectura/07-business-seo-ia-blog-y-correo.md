# akibara-business · SEO, descubribilidad por IA, blog y todo el correo

> **Fuente de verdad de este capítulo:** el snapshot congelado de lo publicado en producción —
> `akibara-business` 1.116.4, `akibara-search` 0.1.60, `akibara-cart-checkout-blocks` 2.2.17,
> `akibara-rewards` 0.3.13, el tema `akibara-gp` 2.1.118 y los mu-plugins de producción—. Nada
> de lo que sigue describe el árbol de trabajo local, que mezcla cosas a medio terminar. Cada
> afirmación técnica lleva su `fichero:línea` **de ese snapshot**.
>
> **Cómo leerlo si es tu primer día.** No hace falta saber nada de WordPress ni de tiendas. Las
> palabras en `código` (`hook`, `option`, `termmeta`, `og:image`, `multipart`…) están todas
> explicadas en el **Glosario del capítulo**, al final. Cuando una palabra estorbe, está ahí.
>
> **Alcance.** Este capítulo tiene **30 secciones numeradas**: 23 módulos y servicios del plugin
> `akibara-business` (de la 1 `SeoEnhancements` a la 23 `EmailButton`), **una** sección para las 8
> plantillas de correo —4 correos × 2 versiones cada uno, la 24— y 6 mu-plugins (25 a 30). Deja fuera los
> plugins hermanos (`akibara-search`, `akibara-rewards`, `akibara-cart-checkout-blocks`), que
> tienen capítulo propio, y todo lo que hacen los terceros por dentro.
>
> **Los diagramas.** Cada uno está en `diagramas/` dos veces, con el mismo nombre: el `.svg` es la
> imagen ya dibujada —doble clic y se abre en cualquier navegador— y el `.mmd` es su fuente en texto
> (formato *Mermaid*), que es lo que se edita si el diagrama cambia. Cada uno se enlaza en el punto
> del texto donde ayuda.

---

## Para qué existe (en dos párrafos que entienda un novato)

Akibara es una tienda de manga en internet. Para que exista de verdad tiene que pasar dos cosas
que no ocurren solas: que **la encuentren** y que **hable con el cliente después de la compra**.
Este carril se ocupa de las dos. La primera mitad decide qué ve Google cuando alguien busca
«cuántos tomos tiene the climber», qué imagen sale cuando alguien pega un enlace de la tienda en
WhatsApp, y qué le cuenta la tienda a un asistente de inteligencia artificial —ChatGPT, Claude,
Perplexity— que quiere recomendarla. La segunda mitad es **todo el correo**: el remitente, el
transporte que de verdad lo envía, el estilo con marca, y los cuatro correos propios de la casa
(«volvió a stock», «tu preventa se acerca», «cambió la fecha», «salió un tomo nuevo de tu serie»).

Parecen dos temas distintos y comparten dueño por una razón práctica: los dos son **superficies
de salida**. Son lo que la tienda publica hacia afuera cuando nadie está mirando. Y los dos tienen
el mismo modo de fallar: no dan error, simplemente dicen algo que no es. Un título que promete una
colección completa que en realidad está agotada; una tarjeta de WhatsApp sin portada; un correo
que anuncia un retraso y se lee como un adelanto; un fichero del servidor que desaparece y deja la
tienda tres días muda sin que nadie se entere. Casi todo lo que hay escrito en este carril es la
cicatriz de una de esas veces.

---

## Cómo encaja con el resto

[**Diagrama · Piezas y dominios**](diagramas/07-business-seo-ia-blog-y-correo-piezas.mmd) — el mapa
entero en una figura: los cuatro dominios (SEO, agentes de IA, imagen social, correo), quién habla
con quién y dónde están los terceros.

**Con quién habla.**

| Con quién | Qué le pide o le da |
| --- | --- |
| **Rank Math** (tercero, plugin de SEO) | Es el **dueño oficial** del `<title>`, la descripción, el `robots`, el `canonical`, el sitemap y los datos estructurados. Este carril **no lo reemplaza**: se engancha a sus filtros para mejorar lo que emite. Si Rank Math se desactiva, dos módulos cambian de comportamiento entero (ver `SchemaProductRankMath` y `SeoEnhancements::archive_canonical_fallback()`). |
| **WooCommerce** (tercero, la tienda) | Le suprime el breadcrumb y el `Product` estructurados nativos para que sólo haya un emisor; le añade un botón a sus ~9 correos de pedido; usa su clase `WC_Email` como base de los cuatro correos propios. |
| **LiteSpeed Cache** (tercero, caché de página) | Le pide que purgue la caché de una landing cuando cambia su SEO, y que **no** cachee las respuestas cuyo veredicto se produjo con una lectura fallida. |
| **Cloudflare** (tercero, red de entrega) | Dos contratos: los sitemaps se declaran cacheables 4 h en el borde (`mu-plugins/akibara-discovery-warming.php:35-39`), y la variante Markdown depende de una **regla de borde** concreta que Cloudflare tiene que tener puesta — la **Cache Rule nº 10 de la zona, «BYPASS Accept text/markdown (agentes IA)»**, con la expresión `any(http.request.headers["accept"][*] contains "text/markdown")` → *cache: false*, más su equivalente en el `.htaccess` **después** del bloque `# END LSCACHE`. Sin ella el borde ya sirvió Markdown a un navegador normal (ver pieza 15 y el Caso 5). Esa regla **no vive en este código**: es configuración de la zona. |
| **Google Listings & Ads** (tercero) | Este carril entra al feed de Google Merchant por el filtro `woocommerce_gla_product_attribute_values`, en prioridad 10 (`AdultContentFeed.php:82`). **No está solo en ese filtro:** `EstimatedDelivery\GlaBridge` escribe `availabilityDate` en el **mismo hook y la misma prioridad** (`GlaBridge.php:54-60`), así que los dos mutan el mismo array y el orden lo decide en qué orden `Plugin.php` instancia los módulos — ver capítulo 04. Hay un tercer contacto con el mismo tercero fuera de este carril: `FrontendCompatibility.php:62` (`woocommerce_gla_disable_gtag_tracking`). |
| **Brevo** (tercero, correo de marketing) | **Nadie de la casa le manda datos**. Lo único propio **de este carril** es un mu-plugin que, durante el instante en que Brevo lee las imágenes del carrito, le entrega la portada en JPG en vez de en AVIF. Fuera de este carril existe además `src/Modules/MarketingConsent/MarketingConsent.php` (capítulo 05), que normaliza la casilla de consentimiento que pinta el plugin de Brevo. |
| **Hostinger** (el hospedaje) | `smtp.hostinger.com` es el servidor que envía el correo de verdad; su cron dispara WordPress cada 5 minutos. |
| **El tema `akibara-gp`** | Pinta el envoltorio de marca de los correos. Su hoja de estilos pinta de rojo **todos** los enlaces del cuerpo, incluidos los botones: por eso existe `EmailBrand::add_button_exception()`. |
| **`akibara-rewards`** (plugin hermano) | Comparten el hook `woocommerce_email_order_details`: el botón «Ver mi pedido» va en prioridad 15 y el empujón de reseña de rewards en la 25. |
| **`akibara-search`** (plugin hermano) | Sin arista directa en este carril, pero comparte con él la autoridad `SeriesCatalogEligibility` (`src/Modules/SeriesCatalog/SeriesCatalogEligibility.php`, capítulo 03): qué serie tiene catálogo público de verdad. |

**Piezas de otros carriles que este capítulo nombra.** Media docena de clases aparecen en el texto
y en los diagramas como si fueran conocidas. No lo son: viven en otros capítulos. Ésta es la tabla
para no tener que buscarlas.

| Pieza | Dónde vive | Qué decide | Capítulo |
| --- | --- | --- | --- |
| `SeriesCatalogEligibility` | `src/Modules/SeriesCatalog/SeriesCatalogEligibility.php` | Si una serie tiene catálogo público **de verdad** (productos publicados y visibles), que es distinto del conteo bruto de WordPress | 03 |
| `SeriesIndex` | `src/Modules/SeriesDirectory/SeriesIndex.php` | El conjunto de series que existe para el público: es lo que pinta el directorio `/series/` y lo que el blog puede enlazar | 03 |
| `SeriesFacts` | `src/Modules/SeriesFacts/SeriesFacts.php` | Tomos, autoría, editorial y precio mínimo de una serie, tal como están hoy en el catálogo | 03 |
| `PublicationStatus` | `src/Modules/SeriesFacts/PublicationStatus.php` | Los **dos ejes**: si la obra original está finalizada / en curso / en pausa / cancelada, y lo mismo para la edición que vendemos | 03 |
| `SeriesSchema` | `src/Modules/SeriesSchema/SeriesSchema.php` | Publica el nodo `ComicSeries` de la landing, con el `@id` `<url>#serie` (`:438`) al que apunta `BookSchemaEnricher`. Ocupa la **etapa 91** del `@graph` | 03 |
| `Stock\Availability` y `Stock\StockStatus` | `src/Modules/Stock/Availability.php`, `.../StockStatus.php` | Si un producto es preventa real, «a pedido» o disponible. De ahí sale `PreOrder` vs `BackOrder` | 09 |
| `PreventaItems` | `src/Modules/OrderPreventa/PreventaItems.php` | Qué espera todavía **la línea comprada** de un pedido, con la cantidad pendiente real descontando reembolsos | 09 |
| `BackInStock\NotificationDispatcher` | `src/Modules/BackInStock/NotificationDispatcher.php` | Consume el evento `akb_bis_notify_product` y decide a quién se le manda el aviso de vuelta a stock, una sola vez por suscripción | 10 |
| `SeriesFollow\DigestRunner` | `src/Modules/SeriesFollow/DigestRunner.php` | Arma y dispara el resumen «salió un tomo nuevo de tu serie» | 10 |

**Y tres utilidades pequeñas que este carril usa todo el rato**, y que explican reglas que el texto
atribuye a los módulos:

| Utilidad | Dónde vive | Qué hace, y por qué importa aquí |
| --- | --- | --- |
| `RobotsMeta` | `src/Utilities/RobotsMeta.php` | Es **la** lectura del `rank_math_robots` de un término o entrada (`is_indexable()`, `:27-42`). Un valor ausente es indexable; una forma corrupta **falla cerrada** (devuelve `false`). Eso es exactamente lo que produce la bifurcación B del Caso 1. La usan `SeriesPaginationPolicy`, `SeriesSitemapLastmod`, `LlmsManifest`, `SeriesSchema`, `SeriesIndex` y `SeriesEditions` |
| `Text` | `src/Utilities/Text.php` | `Text::length()` (`:25-29`) mide caracteres, no bytes. La regla estrella del capítulo —«62 caracteres»— se mide con esto, no con `strlen()`, que contaría dos o tres por cada tilde |
| `VolumeTitle` | `src/Services/VolumeTitle.php` | `extract_number()` (`:34`) saca el número de tomo del **título** del producto, quitando primero los sufijos finales entre paréntesis o corchetes (`Maximum Berserk 21 (Portada alternativa)` → 21) y devolviendo `null` cuando no hay un número inequívoco. Es de donde sale el `position` del `Book` y el orden de los tomos |

**Qué pasa si se apaga.** Desactivar `akibara-business` no es neutro en este carril, y conviene
saberlo antes de un despliegue del par Business/Search:

1. **Se borran dos ficheros físicos del servidor:** el hook de desactivación llama a
   `LlmsManifest::remove_manifests()`, que retira `/llms.txt` y `/llms-full.txt`
   (`akibara-business.php:255-265`). El sitio vuelve al `llms.txt` que genera Rank Math, que no
   cumple la especificación y **omite las 106 landings de serie**.
2. **Google vuelve a ver los títulos genéricos de Rank Math** y las fechas volátiles del sitemap
   (cada venta de un tomo re-fechaba todas sus series).
3. **Los correos siguen saliendo** —el transporte SMTP es un mu-plugin, no este plugin— pero
   pierden el remitente forzado (`MailFrom`), el botón legible y el «Ver mi pedido». Y los cuatro
   correos propios dejan de existir.
4. **Crons de imágenes sociales:** desde 1.116.7 la desactivación retira el diario, su
   continuación y los eventos por portada (estos con `wp_unschedule_hook()`, porque llevan el
   slug como argumento). Antes quedaban huérfanos (sospecha S7 del mapa).

---

## Piezas

### 1. `SeoEnhancements` — el que escribe lo que Google enseña

`src/Modules/SeoEnhancements/SeoEnhancements.php` (2.717 líneas; el módulo más grande del carril).

**Qué hace en una frase:** compone el título y la descripción de cada ficha y de cada landing de
serie, **y además es el dueño del texto editorial que el cliente lee en `/manga/` y en cada archivo
de género y de autor**.

**En qué momento actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `rank_math/frontend/title` | 20 | `prefer_series_term_title()` / `prefer_product_title()`: título rico si no hay uno escrito a mano. |
| `rank_math/frontend/title` | 99 | `add_pagination_to_title()`: sufija «Página N de M» **sobre el copy ya resuelto**. |
| `rank_math/frontend/description` | 20 | `prefer_series_term_description()` / `prefer_product_description_template()`. |
| `rank_math/frontend/description` | 99 | `add_pagination_to_description()`: marcador de paginación compactando frases enteras. |
| `rank_math/json_ld` | 92 (`SchemaGraph::PAGE_ATTACHMENTS`) | Miga de pan alineada, `/series/` como CollectionPage, `/envios/` como WebPage factual. |
| `rank_math/json_ld` | 999 (`SchemaGraph::GLOBAL_CLEANUP`) | Quita el nodo `Place` vacío y unifica el `Person` del responsable editorial. |
| `rank_math/sitemap/posts_to_exclude` | 10 | `exclude_shop_page_from_rank_math_sitemap()` (`:591-597`): saca la página contenedora `/tienda/` del sitemap de páginas. |
| `wp_head` | 1 | `archive_canonical_fallback()` (`:564-580`): canonical autorreferente en archivos de producto **sólo si Rank Math NO está activo**. Es la única emisión de canonical del sitio en ese escenario. |
| `woocommerce_archive_description` | 10 | `handle_native_taxonomy_description()`. |
| `woocommerce_after_main_content` | 5 | Resto del texto de `/manga/`, al pie. |
| `woocommerce_before_shop_loop` | 3 | `handle_render_taxonomy_intro()`: el intro plegable de género/autor con su botón «Leer más». |
| `set_object_terms` | 10 | `forget_series_medium()`: olvida el medio cacheado si un producto cambia de categoría. |
| `pa_serie_edit_form_fields` | 20 | El renglón «Procedencia del SEO». |
| `admin_post_akibara_serie_seo_auto` | 10 | El botón «Volver a automático». |

**La reorganización del texto de archivo — la parte que el mapa no contaba.** En `register()`
(`:220-226`) el módulo **desengancha dos funciones de WooCommerce de su sitio**: quita
`woocommerce_taxonomy_archive_description` y `woocommerce_product_archive_description` del hook
`woocommerce_archive_description`, manda la del contenedor `/tienda/` al pie, y pone las suyas.
El efecto práctico: en `/manga/` el texto editorial se **parte en dos** con
`split_manga_archive_description()` (`:408-474`) —un trozo arriba, el resto al pie— y en los
archivos de `pa_genero` y `pa_autor` se pinta un intro plegable a tres líneas en móvil
(`handle_render_taxonomy_intro()`, `:543-596`). **El tema ya no pinta esa copy: este plugin es su
dueño.** Desde 1.116.30 (`R6-autores-sin-texto`) un autor **sin** descripción no queda mudo:
`AuthorIntro` (`src/Modules/SeoEnhancements/AuthorIntro.php`) compone el párrafo con hechos del
catálogo —cuántos tomos, de qué series (enlazadas a su guía) y de qué editoriales— y nada más;
con cero tomos publicados no escribe nada. Se cachea 12 h en un transient cuya clave lleva el
número de productos del término (sin termmeta: escribirla dispararía el rebuild del buscador). Su
primera frase —compuesta de los hechos en texto plano, no recortada del HTML: un «Dr. STONE» o un
apóstrofo no la parten— es la meta description cuando el término no tiene descripción propia ni meta
escrita a mano en Rank Math, y nunca en páginas 2+ (`prefer_author_term_description()`, `:521-563`,
misma prioridad 20 y guard excluyente que la de serie). La plantilla GLOBAL de Rank Math para los
archivos de autor («Todos los títulos de %term% disponibles…») NO manda sobre el párrafo: es un valor
por defecto para toda la taxonomía y el párrafo es específico del autor. Hasta 1.116.32 el código
prometía ceder ante esa plantilla, pero leía `rank_math_titles`, una option que no existe (la real es
`rank-math-options-titles`), así que la guarda nunca corrió y producción siempre sirvió el párrafo; en
1.116.33 se retiró la promesa falsa, no el comportamiento (R9-plantilla-autor-inalcanzable, 2026-09-20;
candado `tests/RankMathOptionsSeLlamanConGuionesTest.php`). Series y editoriales se nombran de a tres («y N más»). El dueño ve el texto y cómo
reemplazarlo en WooCommerce → Atributos → Autor → editar término: la descripción del término manda
siempre, y esa regla vive dentro de `AuthorIntro` (no en cada llamador). Motivo: 92 landings /autor/ sin una palabra propia, 69 con
cero impresiones en 28 días y las de autores insignia fuera del índice. Las tres guardas que deciden si se pinta ya no miran `! empty( $_GET )` sino
`CatalogQueryGrammar::has_filter_parameter( $_GET )` (`:383`, `:511` y `:653`), que sólo reconoce
parámetros de FILTRO —claves `_*` salvo `_paged`, `filter_*`, `query_type_*` y la lista de
WooCommerce (`Utilities/CatalogQueryGrammar.php`)—: un `?utm_source=` de campaña o un `?fbclid=` de
Instagram **ya no la apagan** (era la sospecha S8, cerrada; este párrafo siguió describiendo el
código viejo hasta el 2026-09-20). Con un filtro puesto sí se apaga, y como la descripción nativa
está desenganchada, en ese caso no se pinta ninguna: ni la propia ni la de WooCommerce.

**La regla de verdad del título de serie.** `compose_series_meta()` distingue tres ejes y jamás
corta una frase: prueba variantes **enteras** de más a menos específica y se queda con la primera
que quepa en 62 caracteres (`:2233-2239`).

| Situación | Qué se publica |
| --- | --- |
| Obra finalizada + el catálogo la cubre + **todos** los tomos comprables hoy | «*X* manga completo: N tomos» |
| Obra finalizada pero vendemos una parte (Naruto: 5 de 72) | los **dos** ejes rotulados, nunca una sola cifra |
| La edición española cerró en menos tomos que la obra (Slam Dunk Deluxe: 31 vs 20) | «edición completa en 20 tomos» |
| Nada de lo anterior | «*X* manga: N tomos en español» |

La palabra «completo» exige stock porque el 2026-09-02 se midió lo contrario: «My Hero Academia
manga completo: 42 tomos» con 7 tomos comprables. El cliente llegaba desde Google buscando una
colección completa y encontraba el 80 % agotado.

> **Corrección sobre el mapa de lectura.** El mapa decía que si ningún candidato cabe «cae al
> genérico». No es así: el bucle termina con `$title = ''` y `prefer_series_term_title()`
> (`:1101-1103`) devuelve **el título que iba a emitir Rank Math**. El resultado sigue siendo
> correcto —no se corta nada— pero el destino es la plantilla del tercero, no una variante de la
> casa.

**La procedencia del SEO.** La interfaz de Rank Math pinta igual un texto generado y uno escrito a
mano. Si el dueño retoca una coma, el `sha256` guardado deja de coincidir y ese campo queda
**congelado para siempre** sin que nada se lo diga. El módulo añade un renglón en el formulario del
término que dice el estado de cada campo, enseña **lo que publicaría hoy el modo automático**, y
ofrece un botón «Volver a automático» (por `admin-post.php`, con `nonce` y capability
`manage_product_terms`, porque un `<form>` anidado dentro del formulario del término es HTML
inválido). 72 de las 106 descripciones fueron escritas en 2026-08-15 por un generador que ya no
existe, sin marca de propiedad: se reconocen por su **cierre exacto** («Revisa precio y
disponibilidad en Akibara.», `SERIES_META_RETIRED_CLOSINGS`, `:105-107`) y así vuelven al generador
vigente sin tocar una fila de la base de datos.

**Qué lee y qué escribe.** Lee los `termmeta` `rank_math_title` y `rank_math_description`; escribe
sus gemelos `_akibara_rank_math_*_ownership` (vale `generated_snapshot_v1` cuando lo escribió el
generador) y `_akibara_rank_math_*_snapshot_sha256`. Cachea el «medio» de cada serie en el
transient `akb_serie_medio_<term_id>` durante 36 h, y **nunca cachea el valor vacío**: cachearlo
dejaría una serie recién creada con la plantilla genérica hasta día y medio después de publicar su
primer tomo.

> **Comentario caducado (el cuarto del capítulo).** La constante dice 36 h
> (`SERIES_MEDIUM_TTL = 36 * HOUR_IN_SECONDS`, `:159`) y el docblock de `forget_series_medium()`
> (`:1986`) dice que «el TTL de **12 h** cierra cualquier caso residual». El que manda es el de la
> constante: son 36 h, día y medio. Quien lea sólo el docblock calculará mal cuánto tarda en
> caducar un medio mal resuelto. Es la sospecha S12, confirmada por lectura.

**Dónde lo opera el dueño.** Productos → Atributos → Serie → editar término (renglón «Procedencia
del SEO» y botón «Volver a automático») y la columna «SEO» del listado de series. La URL del
listado es `/wp-admin/edit-tags.php?taxonomy=pa_serie&post_type=product`; la de un término,
la misma con `&tag_ID=<id>` (o el enlace «Editar» de la fila).

**Qué ve el cliente.** El título azul y las dos líneas grises de Google; el texto editorial de
`/manga/` y de los archivos de género y autor; la miga de pan.

**Código muerto detectado.** `is_sitemap_path()` (`:552-555`) existe y **no la llama nadie** en el
plugin publicado (`grep -rn 'is_sitemap_path'` sobre el snapshot devuelve sólo la declaración).
Infringe la regla de oro «cero código muerto»: un lector supone que hay una política de rutas de
sitemap que en realidad no se aplica en ninguna parte.

> **Pero antes de borrarla, mirar el árbol de trabajo.** Su propio docblock (`:550`) dice
> `@internal Función pura expuesta para pruebas.` El snapshot publicado **no lleva `tests/`** —los
> ZIP de distribución no los incluyen—, así que el diagnóstico honesto tiene dos ramas: o la prueba
> que la usa vive en el árbol local y la función está viva, o la prueba se borró y quedó el
> huérfano. Sólo lo segundo es código muerto.

---

### 2. `SeriesSitemapLastmod` — la fecha que el sitemap no debe mentir

`src/Modules/SeoIndexing/SeriesSitemapLastmod.php` (1.957 líneas).

**Qué hace en una frase:** decide la fecha de «última modificación» que el sitemap declara para
cada landing de serie, para que no cambie cada vez que se vende un tomo.

**Por qué existe.** Rank Math deriva la fecha de una taxonomía del producto más reciente, así que
**guardar el stock de un tomo reescribía la fecha de todas sus series** y Google re-rastreaba 106
landings por nada.

**Cómo lo sustituye.** Por una huella `sha256` de lo que de verdad cambia la página pública:
nombre, slug, descripción, conteo visible, autores y editoriales del catálogo, la guía relacionada,
las ediciones relacionadas (máximo 8, `:59` y `:1789`) y once metas vigiladas
(`WATCHED_META_KEYS`, `:64-82`). **El precio y el stock están fuera a propósito**: son señales
útiles pero demasiado volátiles para afirmar que la landing se reescribió. La segunda fuente es la
fecha de activación de la versión del renderizador (`SERIES_RENDERER_VERSION`).

**Cuándo actúa.** Una docena de hooks de catálogo y de taxonomía, todos en prioridad 100
(`created_pa_serie`, `edited_pa_serie`, `added/updated/deleted_term_meta`, `set_object_terms`,
`transition_post_status`, `post_updated`, `before_delete_post`, `added/updated/deleted_post_meta`
sólo para `_thumbnail_id`, `clean_term_cache`…). Todos ellos **sólo encolan en memoria**; la
confirmación entera ocurre en `shutdown` (prioridad `PHP_INT_MAX`). Después, en
`rank_math/sitemap/entry` (prioridad 20) pone la fecha estable, o retira la entrada si la serie se
quedó sin catálogo visible.

**Qué escribe.** Los `termmeta` `_akb_serie_sitemap_lastmod_gmt` y `_akb_serie_sitemap_fingerprint`,
y dos options de estado interno: `akb_pa_serie_renderer_state` y
`akb_pa_serie_sitemap_pending_changes`. Esta última es una cola de reintento escrita con
*compare-and-swap* hecho a mano por SQL, porque la API de options no ofrece esa primitiva; con más
de 2.048 ids colapsa a un rescan completo (`:49` y `:1196-1203`).

**Dónde lo opera el dueño.** En ninguna parte: no tiene pantalla. **Qué ve el cliente:** nada
directo; lo que cambia es qué rastrea Google y con qué frecuencia.

> **Aviso de confianza.** La maquinaria de huella, del *compare-and-swap* por SQL y de la
> confirmación en `shutdown` es, junto con `write_manifests()` de `LlmsManifest`, **la parte de este
> carril con menos verificación por lectura** (lo dice el propio `verificacion.json` del área: se
> leyeron las constantes, el registro y el CAS, pero no el cuerpo entero). La descripción de arriba
> sirve para entender el diseño; **antes de cambiar nada aquí, léelo en el fuente**.

---

### 3. `FilteredUrlsPolicy` — qué NO debe indexarse

`src/Modules/SeoIndexing/FilteredUrlsPolicy.php`

**Qué hace en una frase:** marca `noindex,follow` toda URL de catálogo con un parámetro de filtro,
y devuelve un **404 real** cuando una combinación de facetas no tiene ningún producto.

**Cuándo actúa:** `wp_robots` **sin prioridad (o sea, 10)** (`:45`), `rank_math/frontend/robots` en
99 (`:46`), y `template_redirect` en 20 para el 404.

El 404 conserva la plantilla del catálogo, para que el cliente vea el mensaje de «no hay productos»
y pueda quitar filtros. La búsqueda por texto (`_buscar`) participa del `noindex` pero **nunca**
produce un 404: una búsqueda vacía es una respuesta útil. También son `noindex` `add-to-cart`,
`add-to-wishlist` y `mailpoet_page`.

**Qué lee:** la gramática única de parámetros, `src/Utilities/CatalogQueryGrammar.php`. Ese mismo
fichero documenta (`:13-17`) que `! empty( $_GET )` es la aproximación equivocada — la que sigue
viva en tres sitios de `SeoEnhancements` y en `SeriesPaginationPolicy`.

---

### 4. `SeriesPaginationPolicy` — las páginas 2, 3… de una serie sí valen

`src/Modules/SeoIndexing/SeriesPaginationPolicy.php`

**Qué hace en una frase:** afirma `index,follow` y canonical propio para `/serie/<slug>/page/N/`
limpia y con catálogo, y `noindex` para una serie sin productos públicos.

**Cuándo actúa:** `rank_math/frontend/robots` en 98 (`:58`), `wp_robots` en **98** (`:59`),
`rank_math/frontend/canonical` en 98.

**Las tres políticas de indexación, en una tabla.** Son tres módulos que escriben en los mismos dos
sitios, y el orden no es el mismo en los dos. Ésta es la foto completa, para no tener que
reconstruirla de tres secciones distintas:

| Módulo | `rank_math/frontend/robots` (el filtro del tercero) | `wp_robots` (la representación nativa de WordPress) |
| --- | --- | --- |
| `FilteredUrlsPolicy` | 99 (`:46`) | **10** — se engancha sin prioridad, y el default de WordPress es 10 (`:45`) |
| `SeriesPaginationPolicy` | 98 (`:58`) | **98** (`:59`) |
| `SeriesDirectorySearchPolicy` | 99 (`:33-34`) | 99 (`:33-34`) |

En Rank Math la política de series corre **antes** (98 < 99) y la de filtrados tiene la última
palabra. En el nativo corre **después** (98 > 10) y podría devolver `index` a una URL que la otra
acaba de marcar. Lo único que lo salva es el guard `! empty( $_GET )`.

> **Corrección sobre el mapa de lectura.** El mapa decía que el orden entre esta política y
> `FilteredUrlsPolicy` es «98 antes que 99» y que eso es un contrato. Eso **sólo vale para el filtro
> de Rank Math**. En la representación nativa de WordPress el orden está **invertido**:
> `FilteredUrlsPolicy` va en `wp_robots` sin prioridad (10) y esta política en 98, así que **corre
> después** y podría devolver `index` a una URL que la otra acaba de marcar. Lo único que lo impide
> es el guard `! empty( $_GET )` de `applies_to_current_request()` (`:161`), como reconoce el propio
> docblock del módulo (`:54-55`). Consecuencia práctica: ese guard —el que la sospecha S8 propone
> cambiar por la gramática— **también es corrección de indexación**, no sólo de copy editorial.
> Quien lo toque tiene que resolver las dos cosas a la vez.

Si la lectura de elegibilidad falla, no decide nada y marca la respuesta no cacheable
(`litespeed_control_set_nocache`), para que el siguiente request reintente en vez de congelar un
veredicto equivocado.

---

### 5. `SeriesDirectorySearchPolicy` — `/series/` es un solo documento

`src/Modules/SeoIndexing/SeriesDirectorySearchPolicy.php`

**Qué hace en una frase:** `/series/?buscar=` es compartible pero no indexable, y
`/series/page/2/` —que no existe— devuelve 404 de verdad.

**Cuándo actúa:** `wp_robots` y `rank_math/frontend/robots` en 99 (`:33-34`),
`rank_math/frontend/canonical` en 99, `redirect_canonical` en 99 (devuelve `false` para conservar
el 404 e impedir el 301 de recuperación de WordPress), `rank_math/head` en 20 (reemite el canonical
que Rank Math ya no imprime por ser `noindex`) y `template_redirect` en 0.

Cierra esas respuestas a toda caché: `DONOTCACHEPAGE`, LiteSpeed y cabeceras de borde `no-store`.

---

### 6. `ProductSitemapStableOrder` — que el sitemap no repita ni se salte productos

`src/Modules/SeoIndexing/ProductSitemapStableOrder.php`

**Qué hace en una frase:** añade un desempate por ID al orden del sitemap de productos.

Rank Math ordena sólo por `post_modified`, y varios productos comparten el mismo segundo: una
página del sitemap podía repetir u omitir productos. La técnica importa: marca el `WHERE` por el
filtro **oficial** (`rank_math/sitemap/get_posts/where`), engancha el filtro global `query` **sólo
en esa petición**, y sustituye **un único texto exacto**. Si Rank Math cambia su SQL, no se toca
nada. Además invalida el sitemap de productos tras cada actualización del plugin
(`akibara_business_upgraded`).

---

### 7. `RankMathAdminUi` — desambiguar un permiso del tercero

`src/Modules/SeoIndexing/RankMathAdminUi.php`

**Qué hace en una frase:** permite editar el `robots.txt` virtual (que es sólo un ajuste guardado)
y retira del panel el editor del fichero `.htaccess`.

El sitio tiene bloqueada la edición de ficheros por seguridad, y Rank Math usa **la misma bandera**
(`rank_math/can_edit_file`) para las dos cosas. Este módulo devuelve `true` sólo en la pantalla
`rank-math-options-general`, sólo en admin, sólo con `manage_options`, y **deniega siempre** si la
petición es REST o si el POST trae campos de `.htaccess`. Ambos hooks van en `PHP_INT_MAX`.

**Dónde lo opera el dueño:** Rank Math → Ajustes generales (con la pestaña `.htaccess` ausente).

---

### 8. `SeoSocial` — la tarjeta de WhatsApp

`src/Modules/SeoSocial/SeoSocial.php`

**Qué hace en una frase:** arregla la imagen que se ve al compartir un enlace.

**El problema medido:** **el 2026-08-15** se contaron **12.644 portadas** en `/uploads/portadas/` y
todas existían **sólo en AVIF**, un formato que WhatsApp, X y Gmail no saben pintar. Las fichas se
compartían sin portada. (Ese conteo creció: **el 2026-09-11** el mismo código habla de «unas
14.900» — ver la pieza 9. Son dos mediciones de fechas distintas, no una contradicción.)

El otro número de esta pieza, «el 78 % del tráfico es móvil chileno con WhatsApp como canal
dominante de compartición», está escrito en el código (`SocialImageDerivatives.php:7`) **sin decir
de dónde sale**: no cita ni Clarity ni Search Console. Se recoge aquí como está —es el argumento
que justificó el trabajo— y se marca como el único dato del capítulo sin fuente.

**Cuándo actúa:** sobre `rank_math/opengraph/facebook/image_array` y
`rank_math/opengraph/twitter/image_array`, en **dos** prioridades —10 (`prefer_series_cover_image`)
y 20 (`prefer_jpeg_social_image`)—; más `rank_math/opengraph/type` (10) para declarar `website` en
la tienda y las taxonomías, y `rank_math/opengraph/facebook/og_locale` (10) para poner `es_LA` en
vez de `es_ES` (Facebook no soporta `es_CL`).

Usa el filtro `image_array` y no el de la URL a secas porque permite corregir URL y `type` juntos:
cambiar sólo la URL dejaría un `og:image:type image/avif` mintiendo sobre un JPG.

**Dos cosas hace:** en una landing de serie sustituye el banner genérico por la portada del primer
producto por título ascendente —que por la convención del pipeline es el tomo 01—; y cambia el AVIF
por su hermana `-og.jpg` si existe.

**Dos huecos conocidos.** (a) `prefer_series_cover_image()` devuelve `array( 'url' => $url )`
(`:91`) descartando `type`, `width` y `height` que traía Rank Math, y el segundo filtro sólo repone
`type` cuando la URL casa el patrón de `/uploads/portadas/*.avif` **y** la hermana JPG ya existe
(sospecha S13). (b) la consulta que elige esa portada (`:63-85`) **no filtra
`product_visibility`/`exclude-from-catalog`**, siendo la única lectura de catálogo del carril que
no pasa por `SeriesCatalogEligibility`: un pack retirado que ordene primero por título se convierte
en la imagen con la que se comparte la landing.

---

### 9. `SocialImageDerivatives` — la fábrica de portadas compartibles

`src/Modules/SeoSocial/SocialImageDerivatives.php`

**Qué hace en una frase:** fabrica y mantiene una copia JPG (calidad 82, sufijo `-og.jpg`) de cada
portada AVIF.

**Cuatro caminos, en orden de preferencia:**

1. **La subida** (`wp_generate_attachment_metadata`, prioridad 10): el momento natural, WordPress ya
   tiene la imagen decodificada.
2. **El cron diario troceado** (`akibara_business_social_image_derivatives`): hasta
   `MAX_PER_RUN = 100` por corrida (`:64`), con candado (`akb_social_derivatives_lock`, TTL 900 s)
   y cursor de reanudación (`akb_social_derivatives_cursor`).
3. **`ensure_for_slug()`**: en el front **no convierte**; sólo mira si existe y encola un evento
   `akibara_business_social_image_for_slug` por portada.
4. **`ensure_now()`**: la única excepción que convierte en el acto (~38 ms), reservada al evento de
   carrito de Brevo, porque Brevo guarda la URL de ese instante y el correo sale horas después.

Una conversión fallida no se reintenta durante 6 h (transient `akb_og_fail_<slug>`).

> **El defecto más caro del carril.** El re-agendado de la corrida siguiente está guardado tras
> `! wp_next_scheduled( self::HOOK )` (`:239-241`), y ese **mismo hook** tiene un evento diario
> permanente (`:89-91`). WordPress reagenda el recurrente **antes** de ejecutar el callback, así que
> `wp_next_scheduled()` siempre devuelve un timestamp y la cadena **nunca arranca**. El tope real es
> 100 portadas al día, no «se reanuda cada minuto mientras queden pendientes» como promete el
> docblock. El botón del panel encola el mismo hook, así que también rinde 100. Es la sospecha S1,
> confirmada por lectura; el propio código de la casa mide el resultado: 1.699 hermanas escritas de
> unas 14.900 portadas.

> **Segundo aviso.** El comentario de `SeoSocial` (`:123-130`) promete que la variante «se genera
> aquí mismo» en la primera petición del `og:image`. `ensure_for_slug()` (`:275-318`) dice lo
> contrario y hace lo contrario: `enqueue_for_slug( $slug ); return $exists;`. Encadenado con lo
> anterior, una portada nueva puede tardar días en tener su JPG.

> **Lo que esos números significan en el calendario.** 100 portadas al día, y **el 2026-09-11**
> faltaban unas 13.200 hermanas (1.699 escritas de unas 14.900). A ese ritmo el catálogo tarda
> **unos 132 días —más de cuatro meses— en terminar de convertirse**, y cada portada nueva que entre
> se pone al final de esa fila. No es un defecto técnico pequeño: es el motivo de que la mayoría de
> las fichas siga compartiéndose sin imagen.

---

### 10. `SchemaProductRankMath` — un solo dueño de la ficha estructurada

`src/Modules/SchemaProductRankMath/SchemaProductRankMath.php`

**Qué hace en una frase:** añade a la ficha estructurada del producto lo que sólo sabe Akibara: si
es preventa o «a pedido», y que además de producto es un libro.

**Tiene dos modos, y eso el mapa no lo decía.** En `register()` (`:40-65`):

- **Con Rank Math activo** (el caso de producción): enriquece la entidad de Rank Math en
  `rank_math/snippet/rich_snippet_product_entity` (prioridad 20), repone la relación de serie en
  `rank_math/json_ld` (prioridad 100 = `SchemaGraph::PRODUCT_RELATIONSHIPS`), y **suprime el nodo
  `Product` nativo de WooCommerce** con `__return_empty_array` en prioridad 999.
- **Sin Rank Math** (`:45-49`): cambia de dueño de nodo por completo. WooCommerce conserva su
  `Product` y este módulo le agrega los mismos datos por `woocommerce_structured_data_product` en
  prioridad 20, sin suprimir nada.

**Tercera superficie:** `rank_math/opengraph/facebook/product_availability` en prioridad 99
(`:55-64`, `:78-89`): devuelve cadena vacía para lo reservable, porque WooCommerce considera
`onbackorder` como «en stock» y Rank Math emitía `product:availability=instock` contradiciendo al
JSON-LD.

**Las reglas de contenido.** `availability = PreOrder` **sólo** para preventa real; todo lo demás
reservable es `BackOrder` (`:443-449`). `availabilityStarts` sólo con preventa **y** fecha válida.
La `description` se reemplaza por hechos —nombre + «Tomo N de la serie X» + autoría— sin precio,
stock, envío, llamada a la acción, nombre de la tienda ni editorial, con tope de 500 caracteres y
cláusulas enteras o se omiten.

> **Comentario caducado.** El docblock de `apply_preventa()` (`:421`) afirma
> «`availability=PreOrder` para todo `onbackorder`», que es exactamente el bug histórico que el
> cuerpo del método corrigió en 1.109.0. Quien audite el schema leyendo ese docblock puede
> «arreglar» hacia atrás.

---

### 11. `BookSchemaEnricher` — el vocabulario de libro

`src/Modules/SchemaProductRankMath/BookSchemaEnricher.php`

**Qué hace en una frase:** traduce el catálogo a vocabulario de libro: autor, editorial, ISBN
validado, idioma, géneros, serie y número de tomo.

No engancha hooks propios: todo entra por `SchemaProductRankMath`. Marca `Book` sólo si el producto
está en una de ocho categorías (`:31-40`); construye un `Person` por cada término `pa_autor`, en
orden estable y sin duplicados; `publisher` desde `product_brand`; el `isbn` desde el SKU
**validando el dígito de control** de ISBN-10 e ISBN-13 (`:210-235`); `inLanguage: es` siempre
(`:87`); `isPartOf` un `ComicSeries` con el **mismo `@id`** que declara la landing (`<url>#serie`:
en JSON-LD dos nodos son el mismo cuando comparten `@id`, no cuando comparten `url`); y
`position` = número de tomo extraído del título.

---

### 12. `ProductSchemaShopping` — devoluciones y precio tachado

`src/Modules/ProductSchemaShopping/ProductSchemaShopping.php`

**Qué hace en una frase:** publica la política de devoluciones dentro de los datos estructurados y
convierte un nodo de precio redundante en el precio de lista tachado.

**Cuándo actúa:** `rank_math/json_ld` en prioridad 90 (`SchemaGraph::PRODUCT_OFFERS`).

Cuelga la política de devoluciones (10 días, país CL, devolución por correo, reembolso total; el
arrepentimiento lo paga el cliente y el defecto lo paga Akibara) del `Organization` en la portada
**y** dentro de cada `Offer` — porque la global sólo vive en la portada y quien abre una ficha no la
ve. Convierte el `priceSpecification` redundante en el precio de lista tachado cuando el producto
está rebajado (440 de 1.568 productos en oferta). Y traduce `pa_pais` → «País» en los atributos.

> **Contradicción entre dos módulos de la casa — y su condición exacta.**
> `SchemaProductRankMath::sanitize_simple_product_offer()` (`:521-524`) hace
> `unset( $offer['priceValidUntil'] )` «para no inventar fecha de precio», desde un filtro de
> prioridad 20 que Rank Math aplica **mientras arma la entidad**. Este módulo la repone con una
> ventana sintética de 30 días (`:223-225`) desde `rank_math/json_ld` en prioridad 90, que corre
> **sobre el grafo ya montado**.
>
> Las dos mitades llevan condicional, y conviene no borrarlas:
>
> - El borrado ocurre **sólo si no hay fin de oferta futuro**: `if ( ! $has_future_sale_end )`.
> - La fecha sintética se escribe **sólo si el campo quedó ausente**: `if ( ! isset( $offer['priceValidUntil'] ) )`.
>
> O sea: para un producto **sin** fin de oferta futuro —que son la mayoría— el hermano borra la
> fecha, éste escribe una sintética a 30 días y **gana la sintética**. Para un producto rebajado
> **con** fecha de fin de oferta futura, el hermano no borra nada y **sobrevive la fecha real de
> WooCommerce**. La contradicción existe, y cubre el primer caso. Es la sospecha S5, y es
> **decisión del dueño** cuál de las dos reglas se queda.

---

### 13. `AdultContentFeed` — que Google Merchant no rechace productos

`src/Modules/ProductSchemaShopping/AdultContentFeed.php`

**Qué hace en una frase:** declara como «adulto» en el feed de Google Merchant los productos de las
series que el dueño marca +18.

51 productos «requerían atención» en Merchant y 23 eran por contenido adulto **no declarado**.
Declararlo los mueve del limbo al flujo adulto legítimo; **no** declararlo es lo que produce el
rechazo. Los falsos positivos del clasificador de Google (One Punch-Man, Zom 100…) **no** se marcan:
se apelan en Merchant.

**Cuándo actúa:** `woocommerce_gla_product_attribute_values` (10) para añadir `adult=true`;
`pa_serie_add_form_fields` / `pa_serie_edit_form_fields` (10) para pintar la casilla;
`created_pa_serie` / `edited_pa_serie` (10) para guardarla —con `nonce`, capability
`manage_product_terms` y comprobación de que el término existe, porque `edited_pa_serie` lo dispara
cualquier importación y un guardado ciego **desmarcaría** las series—; y
`akibara_business_upgraded` / `admin_init` para la siembra idempotente.

**Datos:** el `termmeta` `_akb_adult_content` (`'1'` exacto) y la option marca `akb_adult_series_seeded`.
La lista de cinco series decidida el 2026-08-15 vive como **semilla** en una constante y se copia
una sola vez; después manda el `termmeta`.

**Dónde lo opera el dueño:** Productos → Atributos → Serie, casilla «Contenido +18 (Merchant)» y
columna «+18» del listado.

> Si una serie sembrada cambia de slug, la siembra **se da por hecha igual** y el único aviso es una
> línea de log (`:179-186`): `Log::warn()` y acto seguido `update_option( SEED_OPTION, '1' )`. El
> propio comentario dice que la consecuencia es que Merchant rechace productos. Sospecha S15.

---

### 14. `LlmsManifest` — lo que la tienda le cuenta a una IA

`src/Modules/LlmsManifest/LlmsManifest.php` (1.787 líneas).

**Qué hace en una frase:** escribe en el servidor dos ficheros de texto, `/llms.txt` y
`/llms-full.txt`, que le cuentan a un asistente de IA qué es Akibara y qué series tiene. Y es la
pestaña «Salud SEO» del panel.

**Por qué es un fichero físico y no una ruta.** El `llms.txt` de Rank Math no cumple la
especificación de llmstxt.org y —lo importante— **omitía las 106 landings `/serie/*`**, que son la
palanca SEO medida de la tienda. El `.htaccess` de WordPress sirve los ficheros existentes antes de
tocar el rewrite, así que un fichero físico gana al handler del tercero **sin desactivar nada**.

**El contenido:** H1, blockquote con la identidad **editable por el dueño**, «cuándo usar Akibara»
(y «para qué no sirve»: leer manga en línea, descargas, catálogos de otros países), las series con
su nota, las editoriales por tamaño de catálogo, las guías del blog, los productos sueltos y las
páginas de ayuda. **Ni precio ni stock**: el fichero puede vivir 24 horas y mentiría.

**Disciplina.** Todo el módulo es *fail-closed*: cualquier lectura degradada aborta y conserva el
manifiesto de ayer, con un código de error que la pestaña muestra. La escritura es atómica
(temporal + `rename`), con lease de 30 minutos, rollback del extendido si falla el compacto, y un
registro por hash de los ficheros que el módulo tiene **derecho** a retirar.

> **Aviso de confianza.** El cuerpo de `write_manifests()` con su rollback, y el armado del texto
> del `llms.txt`, son —junto con la maquinaria de huella de `SeriesSitemapLastmod`— **lo menos
> verificado por lectura de todo el carril**: el `verificacion.json` del área lo dice con esas
> palabras. El párrafo de arriba describe el diseño declarado; si vas a depurar un `llms.txt` que
> no se regenera, **lee el fuente antes de fiarte de esta descripción**.

**Options que deja:** `akibara_llms_identity` (editable en el panel, una línea de máximo 600
caracteres; vacía vuelve al literal de fábrica `DEFAULT_IDENTITY`, `:66`),
`akibara_business_llms_manifest_last_success`, `..._last_error`, `..._lock` y `..._managed_files`.

**Dónde lo opera el dueño:** Panel Akibara → Salud SEO, en
`/wp-admin/admin.php?page=akibara-panel&tab=salud_seo` (`LlmsManifest::PANEL_TAB_ID = 'salud_seo'`,
`:72`). **Ojo con el slug:** el menú se llama `akibara-panel`; escribir `page=akibara` da un 403 que
parece un problema de permisos y no lo es. Dice cuándo fue la última generación
correcta, cuál fue el último fallo, cuándo corre la próxima, cuántas portadas sociales faltan, y
tiene dos botones que **encolan** trabajo, nunca lo ejecutan en el request del admin (`:511-534`).

> **El contador de portadas del panel no mide lo que mide el cron.** `social_cover_status()`
> (`:307-345`) sólo pregunta si el fichero `-og.jpg` **existe**; `generate_missing()` (`:221-224`)
> además regenera cuando el AVIF es **más nuevo** que la JPG. El docblock del panel afirma usar «el
> MISMO criterio» y no es cierto: tras reemplazar portadas (el caso normal cuando llega una
> reimpresión) el panel puede decir «0 sin variante» mientras el cron tiene cientos por rehacer.
> Además hace un `scandir` de ~15.000 ficheros en cada render de la pestaña (sospecha S16).

---

### 15. `MarkdownNegotiation` — la misma URL en dos idiomas de máquina

> **Novedades 1.116.27 (2026-09-19).** (1) **Privacidad, R3-d89de454 (ALTA):** un producto, entrada o página
> protegido con contraseña ya NO se negocia — `es_negociable()` corta con `is_singular() && post_password_required()`
> y la petición cae al HTML, que pinta el formulario de la clave. Antes `markdown_producto()` y `markdown_entrada()`
> leían precio y contenido sin pasar por los dos puntos donde WordPress comprueba la contraseña. `LlmsManifest`
> tampoco lista contenido protegido. Medido en el espejo con una página con clave: `Accept: text/markdown` → HTML,
> cero apariciones del contenido. (2) **URLs resueltas:** el 404 y el pie ya no llevan `https://akibara.cl/…`
> escrito a mano: usan `Services\SeriesHubUrl::url()` y `home_url()`. El manifiesto recibe las URLs de `/series/` y
> `/editoriales/` por el parámetro `$hubs` de `render()` (que sigue siendo función pura) desde `hub_urls()`, de
> mejor esfuerzo: si la búsqueda falla, sale el slug de fábrica y el manifiesto se publica igual.

`src/Modules/AgentReadiness/MarkdownNegotiation.php`

**Qué hace en una frase:** la **misma** dirección devuelve la página normal a una persona y texto
plano (Markdown) a un agente que lo pida por la cabecera `Accept`. Y además redirige ocho alias en
inglés a las páginas en español.

> **Antes que nada: en producción esto está ENCENDIDO.** El default **del código** es apagado
> (`get_option( 'akb_markdown_negotiation_enabled', 'no' )`, `:203`), y eso es lo correcto para un
> entorno nuevo que todavía no tiene la regla de borde. Pero **producción lo tiene en `yes`** desde
> el 2026-08-28, cuando se puso esa regla; el propio código lo dice con esas palabras (`:176-177`).
> Compruébalo antes de razonar sobre este módulo:
>
> ```bash
> ssh akibara
> wp option get akb_markdown_negotiation_enabled     # producción: yes
> ```

**Cuándo actúa:** dos enganches a `template_redirect`, en prioridad **0** (`redirigir_alias`) y en
prioridad **1** (`negociar`).

**Las 8 redirecciones 301 — la superficie que el mapa no contaba.** La constante `ALIAS` (`:76-85`)
mapea `/about/` y `/about-us/` → `/nosotros/`, `/privacy/` y `/privacy-policy/` →
`/politica-de-privacidad/`, `/contact/` → `/contacto/`, `/shipping/` → `/envios/`, `/returns/` →
`/devoluciones/` y `/terms/` → `/terminos-y-condiciones/`. Existen porque el 2026-08-28 un auditor
concluyó que a Akibara le faltaban las páginas «About» y «Privacy» — existiendo ambas en español
desde siempre.

> **Y corren aunque la negociación esté apagada.** `register()` (`:87-89`) engancha
> `redirigir_alias` **sin condición**, y el método sólo comprueba `is_404()` antes de hacer
> `wp_safe_redirect( ..., 301 ); exit;` (`:94-109`). El interruptor `habilitado()` (`:202-204`) lo
> consulta **únicamente** `es_negociable()`, que sólo gobierna `negociar()`. Consecuencia: con el
> plugin activo y el interruptor en `no`, el sitio publica ocho **301 permanentes** que el navegador
> y Cloudflare cachean por mucho tiempo, y apagar el interruptor no las deshace.

**El contrato de negociación** (acceptmarkdown.com) son **cinco** cosas y no una:

1. servir `text/markdown` cuando el cliente lo prefiere;
2. `Vary: Accept` en **todas** las respuestas negociadas, no sólo en las de Markdown;
3. `406` cuando el cliente rechaza los dos tipos;
4. honrar los *q-values*;
5. **anunciar la alternativa en la respuesta HTML** con la cabecera
   `Link: <url>; rel="alternate"; type="text/markdown"` (`anunciar_alternativa()`, `:278-287`,
   llamada desde la rama HTML en `:130`), que es RFC 8288 y es lo único que un agente ve **sin
   negociar nada**: descubre que existe la variante en vez de tener que pedirla a ciegas.

Y hereda el estado HTTP: un 404 se sirve como 404 también en Markdown.

**Dónde se genera el texto.** Desde los **datos** para producto y serie (el precio y el stock no
viven en `post_content`), y convirtiendo HTML sólo para páginas y entradas, que sí son prosa.

**No se cachea en ninguna capa** (`no-store` + `CDN-Cache-Control` + LiteSpeed, `:331-339`). No es
optimización: el 2026-08-28, diez minutos después de desplegar, un agente cacheó la variante
Markdown de una ficha y **Chrome empezó a recibir texto plano con `cf-cache-status: HIT`**. La
causa real no era que Cloudflare ignorase el `Vary`, sino que la Cache Rule nº 1 de la zona usa
`override_origin` y **pisa las cabeceras de caché del origen**: ni `Vary: Accept` ni `no-store`
valían nada.

**El arreglo fue de infraestructura, y tiene nombre:** la **Cache Rule nº 10 de Cloudflare, «BYPASS
Accept text/markdown (agentes IA)»** —la última de la lista y por tanto la que manda—, con la
expresión `any(http.request.headers["accept"][*] contains "text/markdown")` → *cache: false*; más
un bypass equivalente en el `.htaccess` colocado **después** del bloque `# END LSCACHE` (si va
antes, el propio bloque de LiteSpeed lo sobrescribe, porque escribe en la misma variable de
entorno). Por eso el **default del código** queda en apagado: es lo correcto para cualquier entorno
que todavía no tenga esa regla. Encender otro entorno es ponerle su regla primero y **después**
`wp option update akb_markdown_negotiation_enabled yes`.

**Cómo se comprueba que la regla sigue puesta** (dos peticiones, nunca una sola: con una sola
siempre se ve bien):

```bash
curl -sI -A AkibaraQA -H 'Accept: text/markdown' https://akibara.cl/berserk-1/ | grep -i 'content-type\|cf-cache-status\|vary'
curl -sI -A AkibaraQA https://akibara.cl/berserk-1/                            | grep -i 'content-type\|cf-cache-status\|link'
```

La primera tiene que decir `text/markdown` y la segunda `text/html`. Si la segunda dice
`text/markdown` con `cf-cache-status: HIT`, la regla se cayó y el incidente del 2026-08-28 está
ocurriendo otra vez.

**Dónde lo opera el dueño:** en **ninguna parte**. `akb_markdown_negotiation_enabled` sólo se toca
por SSH (`wp option update akb_markdown_negotiation_enabled yes` / `... no`), y sólo se puede saber
en qué estado está con `wp option get akb_markdown_negotiation_enabled`. Es la única palanca del
carril sin pantalla y **infringe la regla de oro «cero options invisibles»** (sospecha S4).

**Dos huecos conocidos.** La variante Markdown publica `(int) $termino->count` (`:449`), el conteo
**bruto** de WordPress, que no coincide con el que ve el cliente ni con el que publica `llms.txt`
(S9). Y `markdown()` (`:359-374`) decide sólo por tipo de consulta, sin mirar `rank_math_robots`:
sirve contenido de URLs marcadas `noindex`, justo lo contrario de lo que hace `LlmsManifest`
(`:1079`). Además `es_negociable()` (`:206-228`) no excluye carrito, finalizar compra ni mi cuenta:
para un visitante sin sesión esas páginas son `is_singular()` y un agente recibe el render de los
bloques convertido a texto.

---

### 16. `AcceptParser` — leer la cabecera `Accept` como manda la norma

`src/Modules/AgentReadiness/AcceptParser.php`

**Qué hace en una frase:** ordena los tipos que pide el cliente por calidad (`q`) y, a igualdad,
gana el más específico.

Es la única pieza del carril sin aristas de verdad, y por eso vive aparte. **La trampa que evita:**
Chrome manda `text/html,...,*/*;q=0.8`, y ese comodín **acepta Markdown** con q=0,8. Lo que decide
no es si el tipo aparece, sino quién gana al ordenar: primero `q`, luego especificidad, luego la
preferencia del servidor.

**Y esa preferencia del servidor no la decide `AcceptParser`: la decide el orden del array que
recibe.** Ese array es la constante `OFRECIDOS`, que vive en **`MarkdownNegotiation.php:63`**
(`private const OFRECIDOS = array( self::TIPO_HTML, self::TIPO_MARKDOWN );`) y se le pasa en
`:157`. Como **HTML va primero**, HTML gana los empates. Invertir ese orden —en el llamador, no
aquí— le serviría Markdown a cualquier cliente con `*/*`, o sea a todos los navegadores.

`q=0` es rechazo explícito; una cabecera ausente o ilegible degrada a HTML.

---

### 17. `Blog` — que las guías enlacen a las landings

`src/Modules/Blog/Blog.php`

**Qué hace en una frase:** convierte la primera mención de cada serie, en una entrada del blog, en
un enlace a su landing.

**Cuándo actúa:** `the_content` en prioridad 20, sólo en entradas singulares de tipo `post`.

**Quién decide el conjunto:** `SeriesIndex`, la misma autoridad que pinta el directorio —no un
`get_terms` a secas—, así que no se enlaza a las series marcadas `noindex`. El mapa nombre → URL
vive 24 h en el transient `akb_blog_series_map`, y **un mapa vacío producido por una lectura
degradada no se cachea**.

**Cómo se invalida — la parte que el mapa no contaba.** Además de `created/edited/delete_pa_serie`,
el módulo engancha tres hooks de catálogo (`:66-68`): `set_object_terms` —filtrado para disparar
sólo cuando de verdad cambió el conjunto de series del objeto, `:86-102`, porque ese hook salta para
toda taxonomía y todo tipo de post—, `woocommerce_update_product` y `woocommerce_new_product`. Es
una invalidación **muy amplia**: guardar cualquier producto en el admin tira el transient. Existe
porque el mapa se arma con el **catálogo** de cada serie, no sólo con el término: publicar el primer
tomo de una serie, o sacarle el último, cambia el conjunto sin tocar la taxonomía.

**Reglas de coincidencia.** Insensible a mayúsculas desde el 2026-09-01 —12 de 31 posts no recibían
un solo enlace porque quien redacta escribe «one piece»— **salvo** para nombres que también son
palabra española corriente: `Saga` sólo enlaza con la caja exacta, o «la saga de Naruto» enlazaría
la palabra *saga* al cómic **Saga**. Nombres de menos de 4 caracteres quedan fuera. Nunca se enlaza
dentro de un `<a>` ni de un título.

> **Hueco detectado por lectura.** El patrón se compone como `/\b(nombre)\b/iu` (`:166-178`). El
> modificador `u` activa UTF-8 pero **no** `PCRE_UCP`: `\b` se sigue definiendo sobre `[A-Za-z0-9_]`.
> Para «Haikyu!!» el `\b` final exige un carácter de palabra después del segundo `!`, y en «los
> tomos de Haikyu!! llegan» viene un espacio: **no casa nunca**. Cualquier serie cuyo nombre empiece
> o termine en signo (`!`, `?`, `&`, acento final) queda fuera en silencio.

---

### 18. `SchemaGraph` — el orden por escrito

`src/Utilities/SchemaGraph.php`

**Qué hace en una frase:** pone por escrito el orden en que los callbacks de la casa pueden tocar
los datos estructurados.

Constantes de etapa, con quién ocupa cada una:

| Etapa | Constante | Quién la ocupa |
| --- | --- | --- |
| **90** | `PRODUCT_OFFERS` | `ProductSchemaShopping` — devoluciones y precio de lista tachado (pieza 12) |
| **91** | `SERIES_COLLECTION` | **`SeriesSchema`** (`src/Modules/SeriesSchema/SeriesSchema.php:187`, capítulo 03): publica el nodo `ComicSeries` de la landing, con el `@id` `<url>#serie` (`:430-438`). **Es el nodo al que apunta el `isPartOf` de `BookSchemaEnricher`** (pieza 11): sin él, esa relación sería un puntero al vacío |
| **92** | `PAGE_ATTACHMENTS` | `SeoEnhancements` — miga de pan alineada, `/series/` como `CollectionPage` (pieza 1) |
| **99** | `ROUTE_COLLECTIONS` | Las rutas propias del catálogo (`/disponibles/`, `/preventas/`…), capítulo 03 |
| **100** | `PRODUCT_RELATIONSHIPS` | `SchemaProductRankMath` — repone la relación de serie (pieza 10) |
| **999** | `GLOBAL_CLEANUP` | `SeoEnhancements` — quita el `Place` vacío y unifica el `Person` (pieza 1) |

Y `node_has_type()`, porque un `@type` puede ser texto o lista y antes había tres implementaciones
con tres semánticas distintas. No tiene hooks ni datos: es un contrato compartido.

---

### 19. `MailFrom` — un solo remitente

`src/Modules/MailFrom/MailFrom.php`

**Qué hace en una frase:** obliga a que **todo** correo que salga del sitio lleve como remitente
`Akibara <contacto@akibara.cl>`.

**Cuándo actúa:** `wp_mail_from` y `wp_mail_from_name` en prioridad 15 (`:47-52`), **ignorando
incondicionalmente** el valor entrante —también un `From:` puesto por quien envía—. No es rigidez:
el SMTP saliente rechaza cualquier remitente no autenticado, así que un `From:` divergente rompería
el envío entero. Queda una perilla por código (`akibara_mail_from_email` /
`akibara_mail_from_name`) para el día en que se verifique otro buzón.

> **El 15 no es la última palabra, y es a propósito.** El propio comentario del código (`:48-49`)
> lo explica: «Prioridad alta (15) para correr DESPUÉS del default de WP core (10), pero **ANTES de
> plugins que quieran override puntual (20+)**». O sea: cualquier plugin de terceros enganchado a
> `wp_mail_from` en 20 o más **le gana**. Hoy no hay ninguno haciéndolo —ni Brevo ni el plugin de
> formularios—, pero si algún día el remitente cambia sin explicación, ése es el primer sitio donde
> mirar: `wp eval "global \$wp_filter; print_r( array_keys( \$wp_filter['wp_mail_from']->callbacks ) );"`.

**Qué ve el cliente:** el «De:» de su bandeja. **Quién depende de esto:** todo `wp_mail()` del
sitio — WooCommerce, WordPress core, `akibara-rewards`, `akibara-search` y los plugins de terceros.

---

### 20. `MailerBoot` — arrancar el motor antes de buscarlo

`src/Services/MailerBoot.php`

**Qué hace en una frase:** arranca el motor de correo de WooCommerce antes de pedirle uno de los
correos de la casa.

**Por qué existe, con nombre y apellido.** El guardia que comprobaba si `WC_Email` se podía cargar
vivía dentro de una clase que empieza por `extends WC_Email`. Para llamar a su método estático, PHP
tiene que autocargar el fichero, y esa carga ejecuta el `extends`: **el fatal saltaba antes de
ejecutar una línea del guardia**. Dos corridas del resumen de series murieron en producción con
`Class "WC_Email" not found`, las dos «vía WP Cron»; la misma acción por Async Request completaba,
porque ese arranque ya trae el mailer cargado. `MailerBoot` no extiende nada y llama a
`WC()->mailer()`, que es lo único que hace el `include` que el autoloader de WooCommerce no sabe
hacer. `from_mailer()` resuelve la instancia por `instanceof`, no por clave.

---

### 21. `AkibaraEmail` — la base de los cuatro correos propios

`src/Services/AkibaraEmail.php`

**Qué hace en una frase:** da a los cuatro correos propios la misma pantalla de ajustes, el mismo
esqueleto de cuerpo y la baja en un clic.

**Los cuatro correos, con todo lo que hace falta para depurar «no llegó el correo X»:**

| Correo | Clase | `EMAIL_ID` | Option donde vive lo editado | Asunto por defecto (tal cual, con sus marcadores) | Plantillas |
| --- | --- | --- | --- | --- | --- |
| Volvió a stock | `BackInStock\InstockEmail` | `akb_bis_instock` (`:36`) | `woocommerce_akb_bis_instock_settings` | `{product_name} está de vuelta en Akibara` (`:59`) | `customer-bis-instock.php` + su gemela en `plain/` |
| Ya llegó | `OrderPreventa\ReadyToShipEmail` | `akb_preventa_ready` (`:57`) | `woocommerce_akb_preventa_ready_settings` | `Tu pedido {order_number} ya llegó a Akibara` (`:82`) | `customer-preventa-ready.php` + `plain/` |
| Se acerca / cambió la fecha / la editorial anunció fecha | `OrderPreventa\ReminderEmail` | `akb_preventa_reminder` (`:70`) | `woocommerce_akb_preventa_reminder_settings` | Depende del **modo** (`match` en `:163-175`): `Tu pedido {order_number} se acerca` para el T-7, y cuatro constantes para los otros dos modos (`:193-196`) | `customer-preventa-reminder.php` + `plain/` |
| Tomo nuevo de tu serie | `SeriesFollow\SeriesFollowEmail` | `akb_sf_series` (`:42`) | `woocommerce_akb_sf_series_settings` | `Salió el tomo {tomo} de {serie}{y_mas}` (`:77`) **y además** `Salieron {n_tomos} tomos de {serie}{y_mas}` (`:103`), que es un segundo asunto con su propio campo | `customer-series-follow.php` + `plain/` |

**Los marcadores son lo que hay que escribir literalmente en el campo del admin.** Si el dueño
quiere cambiar el asunto de «volvió a stock», lo que teclea es `{product_name} ya llegó`, no el
nombre de un producto concreto: WooCommerce sustituye el marcador al enviar. Los marcadores
disponibles no son los mismos en los cuatro (ver la tabla de la costura `akb_after`, más abajo).

**Las clases** son `BackInStock\InstockEmail`, `OrderPreventa\ReadyToShipEmail`,
`OrderPreventa\ReminderEmail` y `SeriesFollow\SeriesFollowEmail`.

> **Ojo al docblock.** El encabezado del propio fichero (`:3-8`) sigue diciendo «base común de los
> **tres** `WC_Email` propios» y nombra sólo tres clases. Es un comentario caducado en el fichero
> que el mapa de lectura pone como fuente de verdad de la capa de correo.

**Dos decisiones con cicatriz.** (a) El campo `email_type` es **obligatorio**: sin él
`get_content_type()` cae a `text/plain` y el HTML viajaba dentro de un `Content-Type: text/plain`
—cazado en la prueba de extremo a extremo con Mailpit—. (b) Su default es `multipart` desde el
2026-09-01, porque con `html` las cuatro plantillas de texto plano de la casa **nunca se
ejecutaban**: código muerto andando, y un cuerpo sin parte plana lo penalizan los filtros de spam.

**El mecanismo `akb_after` — la costura que el mapa no contaba.** `init_form_fields()` (`:124-206`)
arma la pantalla iterando un esqueleto **fijo** de cuatro claves, y son exactamente éstas:

| Clave | Qué es en la pantalla |
| --- | --- |
| `enabled` | La casilla «Activar este email» (default `yes`) |
| `subject` | El asunto |
| `heading` | El encabezado que se pinta arriba del cuerpo |
| `additional_content` | El texto opcional del dueño al final del correo |

Un quinto campo, `email_type`, se añade **después** del bucle y por eso siempre queda el último.
Como el bucle recorre sólo esas cuatro claves, una clave extra devuelta por `form_field_copy()`
—que es el método con el que cada correo cambia los **textos** del esqueleto— se **descartaría en
silencio**: se vería en el código y no en la pantalla, que es exactamente la «option invisible» que
las reglas de la casa prohíben. Por eso existe `extra_form_fields()` (`:207-228`) con la clave
privada `akb_after` (`:160-181`), que dice tras qué campo del esqueleto va el campo nuevo y se quita
antes de que el array llegue a WooCommerce. Los que no anclan a ninguna clave van al final.
**Es la única vía por la que un correo de la casa puede añadir un ajuste visible**: quien escriba el
quinto correo y no la use reintroducirá la option invisible que esta costura existe para evitar.

**Hoy esa costura la usa exactamente un correo: el de novedades de serie.**
`SeriesFollowEmail::extra_form_fields()` (`src/Modules/SeriesFollow/SeriesFollowEmail.php:155-168`)
añade un **quinto campo visible**, `subject_tanda` («Asunto cuando salieron varios tomos»), anclado
con `akb_after => 'subject'` para que aparezca justo debajo del asunto normal. Existe porque el
correo tiene dos asuntos: uno cuando la serie que abre el correo trae **un** tomo y otro cuando trae
**dos o más**; el código elige entre ellos en `:134`.

Ese campo documenta seis marcadores editables (`:163`), que son todos los que este carril ofrece al
dueño:

| Marcador | Qué pone | Correo |
| --- | --- | --- |
| `{product_name}` | Nombre del producto | Volvió a stock |
| `{order_number}` | Número del pedido | Preventa: ya llegó, se acerca, cambio de fecha |
| `{serie}` | Nombre de la serie | Novedades de serie |
| `{tomo}` | El tomo más nuevo | Novedades de serie |
| `{tomos}` | El rango («71 al 74», o «71, 73 y 74» si falta alguno del medio) | Novedades de serie, sólo en `subject_tanda` |
| `{n_tomos}` | Cuántos tomos van. **Cuidado:** son los de esa serie **que van en ESTE correo para ESE cliente**; si ya compró alguno, a él le saldrá un número menor | Novedades de serie, sólo en `subject_tanda` |
| `{y_mas}` | «y 2 series más», cuando el correo trae varias | Novedades de serie |
| `{site_title}` | El nombre del sitio | Todos |

Y una limitación operativa que no dice ninguna otra parte: **`subject_tanda` no se puede
previsualizar**, porque la vista previa de WooCommerce no trae varios tomos. Se comprueba mandándose
el correo de verdad en el espejo.

> **Un aviso que la pantalla da y el capítulo tiene que repetir.** El recordatorio de preventa
> (`ReminderEmail`) tiene **tres modos** —`t7`, `date_changed`, `date_announced`— y **una sola fila**
> en la pantalla de ajustes. El asunto y el encabezado que el dueño escriba valen **sólo para el
> aviso T-7**: `get_subject()` (`:273-285`) y `get_heading()` (`:292-304`) devuelven
> `parent::get_subject()` —lo guardado— **únicamente cuando el modo es `t7`**; en los otros dos
> devuelven el literal de la constante, pasado por el filtro de código
> `woocommerce_email_subject_akb_preventa_reminder`. Los textos de «cambió la fecha» y «la editorial
> anunció fecha» viven en cuatro constantes (`:193-196`) y **sólo se cambian por código**.
>
> El motivo está escrito y es bueno: un asunto personalizado para el T-7 salía también en los
> correos de cambio de fecha, que dicen otra cosa. La pantalla lo advierte («Los modos "cambio de
> fecha" y "fecha anunciada" usan su texto propio», `:704` y `:709`), así que no es una option
> invisible. Pero sí es una palanca que promete más de lo que da, y hay que saberlo antes de
> prometerle al dueño que puede cambiar los cuatro textos.

**Baja en un clic.** `get_headers()` (`:92-101`) añade `List-Unsubscribe` y `List-Unsubscribe-Post`
(RFC 8058) cuando el correo declara una URL de baja: es lo que Gmail y Yahoo exigen a un remitente
masivo.

**Dónde lo opera el dueño:** WooCommerce → Ajustes → Correos, en
`/wp-admin/admin.php?page=wc-settings&tab=email`: cuatro filas, cada una con activar, asunto,
encabezado, contenido adicional y tipo de email —y, sólo en la de novedades de serie, el quinto
campo `subject_tanda`—.

---

### 22. `EmailBrand` — que el botón se lea, y que lleve a alguna parte

> **`EmailBrand::saludo()` (R4-da94be99, 1.116.28).** Único saludo de los cuatro correos de preventa (html y plain):
> «Hola Nombre,» o, sin nombre de facturación, «Hola,». Antes salía «Hola ,».

`src/Modules/EmailBrand/EmailBrand.php`

**Qué hace en una frase:** dos cosas sobre **todos** los correos de WooCommerce: que el texto del
botón rojo se lea, y añadir un botón «Ver mi pedido».

**Cuándo actúa:** `woocommerce_email_styles` (20) y `woocommerce_email_order_details` (15).

**El contraste.** El envoltorio de marca del tema declara
`#body_content_inner a { color:#CC0010; -webkit-text-fill-color:#CC0010 }` y Emogrifier lo inlina
sobre **todos** los `<a>` del cuerpo, botones incluidos.

`-webkit-text-fill-color` es una propiedad de Safari y Chrome que **pinta el texto** —no el fondo—
y que **le gana a `color`**: si sólo se cambia `color`, WebKit sigue pintando el valor viejo. Por
eso un botón que sólo trae `color:#ffffff` gana la propiedad `color` pero **no** la que WebKit usa
de verdad: en Apple Mail, en la app de Gmail y en Outlook móvil, el texto del botón se pintaba
**rojo sobre el fondo rojo del propio botón**, contraste **1,23:1, ilegible** (medido en el gate de
correos, 2026-09-01).

El arreglo va en dos capas a propósito:

1. **cada botón declara `-webkit-text-fill-color:#ffffff` en su atributo `style`** (`EmailButton`,
   `:33-35`), que gana por cascada; y
2. **esta hoja repite la misma regla con `!important`** para `.akb-email-btn`.

La segunda capa existe porque la primera se puede perder: `WC_Emails::wrap_message()` pasa el
cuerpo por `wp_kses_post()` en los correos de **`akibara-rewards`**, y ese filtro **desnuda
cualquier `-webkit-text-fill-color` inline**, porque esa propiedad no está en la lista blanca de
`safecss_filter_attr()` de WordPress. La hoja de estilos es la única capa que sobrevive a esa poda
(`EmailBrand.php:10-22`).

**El destino.** El botón se pinta **sólo en la copia del cliente**, nunca en la del admin, y
`order_url()` (`:112-125`) **elige el destino según haya cuenta detrás**: `view-order` para clientes
registrados, la página de «pedido recibido» para invitados —que WooCommerce autoriza con la `key`
del propio pedido—. Un invitado que pulse `view-order` aterriza en un muro de acceso.

> Las cuatro superficies de preventa hacen justo lo contrario: usan `$order->get_view_order_url()`
> sin mirar la cuenta (`templates/emails/customer-preventa-ready.php:92`, su gemelo plano `:55`,
> `customer-preventa-reminder.php:232` y su plano `:149`), mientras el destinatario es
> `$order->get_billing_email()` (`ReadyToShipEmail.php:132`), que existe igual sin cuenta. Es la
> sospecha S3: dos superficies de la casa contradiciéndose, con el arreglo ya escrito en una de
> ellas.

---

### 23. `EmailButton` — el único botón de correo de la casa

`src/Services/EmailButton.php`

**Qué hace en una frase:** produce el botón en HTML y en texto plano, con el estilo en una
constante para que las pruebas puedan mirarlo.

Relleno blanco inline **siempre**; 45 px de alto real (15 px de padding + 15 px de línea), por
encima del mínimo táctil de 44 px (`:12-13`, `:33-35`); en texto plano, la etiqueta y **la misma
URL**, para que las dos mitades del multipart no diverjan; cadena vacía si no hay destino.

---

### 24. Las plantillas de correo

`templates/emails/*.php` y `templates/emails/plain/*.php` — ocho ficheros, cuatro correos en dos
versiones cada uno.

**Qué hacen en una frase:** son literalmente lo que el cliente lee.

Lo que más pesa en ellas no es el HTML sino **las reglas de verdad**:

- **Las dos fechas de la preventa.** La de la editorial (cuándo publica el tomo) y la de arribo
  (cuándo llega aquí y se puede despachar) se dicen **por separado**. Antes se imprimía sólo la de
  la editorial bajo una etiqueta de «estimada», y como el envío tarda semanas, una postergación de
  21 días salía como una fecha **anterior** a la ya prometida: un aviso de retraso que se leía como
  un adelanto.
- **La entradilla del correo de novedades tiene tres ramas**, no dos, porque `count($groups)`
  cuenta **series**: con una serie y cuatro tomos, «Salió un tomo nuevo» se contradice con el bloque
  de abajo.
- **El copy del dueño nunca pasa como formato de `sprintf`**: el marcador se sustituye con
  `str_replace` (`{n_tomos}`), porque un `%` en un texto editable es un fatal.
- Emiten `woocommerce_email_header` y `woocommerce_email_footer` con su nombre original para que el
  envoltorio del tema pinte la marca.

> **Dos plantillas tomaron decisiones opuestas sobre el mismo dato, y una explica por escrito por
> qué la otra está mal.** El dato es **el precio en la mitad de texto plano**:
>
> - `plain/customer-series-follow.php:53-54` **no lo imprime**, y lo dice: «La parte plana no
>   imprime precio, hoy tampoco». Su mitad HTML sí lo enseña
>   (`customer-series-follow.php:95-103`).
> - `plain/customer-bis-instock.php:30-42` **sí lo imprime**, con el motivo escrito: «la mitad HTML
>   lo muestra y ésta se lo dejaba, así que quien lee el correo en texto plano —clientes antiguos,
>   modo sin imágenes, algunos filtros corporativos— tenía que abrir la ficha para saber cuánto
>   vale».
>
> O sea: el argumento que arregló una de las dos está escrito dentro del fichero y no se aplicó a la
> otra. Es la sospecha S18, confirmada por lectura. La decisión —poner el precio en las cuatro, o
> quitarlo de las cuatro— es del dueño; lo que no puede quedarse es que dos correos de la misma casa
> se contradigan sobre qué ve un cliente en modo texto.

> **Corrección importante sobre la parte de texto plano.** El mapa de lectura decía que el texto
> adicional del dueño «sale con entidades HTML» y ponía como ejemplo «Envío gratis &amp; retiro».
> **Ese ejemplo no puede ocurrir y el síntoma real es peor.** Con `email_type = multipart` (el
> default de la casa), WooCommerce pasa la parte plana por
> `preg_replace( $plain_search, $plain_replace, wp_strip_all_tags( get_content_plain() ) )` antes de
> ponerla en el cuerpo alternativo (`woocommerce/includes/emails/class-wc-email.php:356-372`). Esa
> tabla convierte `&amp;` → `&` y `&quot;` → `"`, así que el ampersand sale bien. Pero **la última
> regla de la tabla es `/&[^&\s;]+;/i` → `''`**: toda entidad no reconocida se **borra**. Y
> `esc_html()` produce `&#039;` para el apóstrofo, mientras `wptexturize()` deja `&#038;` y
> `&#8217;`. Resultado: el copy del dueño no sale con entidades, sale **mutilado** —desaparecen
> apóstrofos, comillas tipográficas y el `&`—.
>
> **Y no es sólo el copy del dueño.** Las cuatro plantillas planas escapan **todo** dato dinámico:
> el nombre del producto (`plain/customer-bis-instock.php:27`), el de la serie
> (`plain/customer-series-follow.php:48`), el nombre de facturación del cliente
> (`plain/customer-preventa-ready.php:16`) y el nombre de cada línea del pedido. Un cliente que lea
> en modo texto recibe «JoJos Bizarre Adventure» en vez de «JoJo's Bizarre Adventure», y «OHiggins»
> si su apellido lleva apóstrofo. `plain/customer-preventa-reminder.php:157-158` ya tiene escrito el
> tratamiento correcto para su bloque final, con el comentario que lo explica.

---

### 25. mu-plugin `akibara-correo-smtp.php` — el transporte

**Qué hace en una frase:** es lo que de verdad hace que un correo salga.

Configura PHPMailer contra `smtp.hostinger.com` con credenciales que viven en `wp-config.php`,
**nunca en la base de datos**. En producción sólo acepta ese host con 465/ssl o 587/tls y usuario
`contacto@akibara.cl` con contraseña no vacía (`:344-354`); cualquier otro entorno falla la
validación.

**Las seis constantes, con su nombre exacto.** Esto no admite abreviaturas: cinco de las seis
empiezan por `AKIBARA_HOSTINGER_SMTP_` y **sólo una** por `AKIBARA_HOSTINGER_MAIL_`. Quien
reconstruya un `wp-config.php` con el prefijo equivocado deja la tienda muda, que es exactamente el
incidente de tres días que esta pieza cuenta más abajo.

| Constante | Para qué sirve | Valor por defecto | Línea |
| --- | --- | --- | --- |
| `AKIBARA_HOSTINGER_MAIL_ENABLED` | **Interruptor maestro.** Si no está definida **o no vale exactamente `true`**, el transporte no se activa en absoluto | — (sin ella, apagado) | `:406-407` |
| `AKIBARA_HOSTINGER_SMTP_HOST` | El servidor de correo | `smtp.hostinger.com` | `:417` |
| `AKIBARA_HOSTINGER_SMTP_PORT` | El puerto | `465` | `:418` |
| `AKIBARA_HOSTINGER_SMTP_ENCRYPTION` | El cifrado | `ssl` | `:419` |
| `AKIBARA_HOSTINGER_SMTP_USERNAME` | El buzón autenticado (`contacto@akibara.cl`) | sin default | `:420` |
| `AKIBARA_HOSTINGER_SMTP_PASSWORD` | Su contraseña | sin default | `:421` |

**Si el correo no sale, lo primero que hay que mirar es `AKIBARA_HOSTINGER_MAIL_ENABLED`**: sin ese
`true`, el módulo no engancha nada y `wp_mail()` cae al `mail()` de PHP, que en Hostinger devuelve
`false` sin decir por qué.

**Cuándo actúa:** `pre_wp_mail` (`PHP_INT_MAX`), `phpmailer_init` (`PHP_INT_MAX`),
`wp_mail_succeeded`, `wp_mail_failed` y `admin_notices`.

**Si el perfil no valida, corta `wp_mail()`** antes de caer al `mail()` de PHP — pero hasta la 1.1.0
lo hacía **en silencio**, y en agosto de 2026 el sitio pasó **tres días sin mandar un solo correo**
(pedido 27720 incluido); se descubrió porque un cliente reclamó. Hoy deja rastro con causa, hora y
contador en la option `akibara_mail_transport_outage`, y lo enseña como aviso rojo **no
descartable**. Un corte del guard se grita desde el primero; los rechazos del propio servidor SMTP,
a partir de **tres seguidos**, porque uno suelto suele ser una dirección mal escrita.

**El rastro se borra sólo cuando un correo SALE de verdad** (`wp_mail_succeeded`), no cuando
PHPMailer se construye: la sonda de salud de cada despliegue borraba la evidencia sin haber enviado
nada.

---

### 26. mu-plugin `akibara-mail-transport-watch.php` — el centinela

**Qué hace en una frase:** vigila **desde fuera** que el fichero del transporte siga existiendo.

Del 18 al 21 de agosto de 2026 ese fichero se esfumó del servidor —hipótesis viva: el escáner del
hospedaje lo «limpió» por parecer un mailer— y con él se fueron el guard, la option y el aviso. **Un
observador dentro del observado no observa nada.**

**Cuándo actúa:** `admin_init` (1) y, sólo si hay cron en curso, `init` (1) — o sea cada 5 minutos,
que es cada cuánto dispara el cron del hospedaje.

**Dónde está de verdad la copia de rescate.** El código sólo declara el nombre del directorio
(`BACKUP_DIR = 'akb-runtime'`, `:51`) y lo resuelve como hermano del docroot (`:204`). En producción
eso es, en concreto:

```
~/domains/akibara.cl/public_html/                 ← el docroot
~/domains/akibara.cl/akb-runtime/akibara-correo-smtp.php   ← la copia de rescate
```

La deposita el **despliegue de mu-plugins**, `ops/akibara-prod-muplugins-deploy.sh` (`:26`, `:95`),
que además comprueba que la copia remota tenga el **mismo hash** que la del repo. Y se verifica sin
tocar nada con `bash ops/akibara-prod-mail-health.sh`, el chequeo que corre al final de cada
despliegue y también a mano: lista los mu-plugins de producción, confirma que el crítico está, y
mira que la copia de rescate siga en su sitio. **Es la red de seguridad de un incidente de tres días
sin correo: comprobarla es un comando, y hay que correrlo.**

Comprueba que la clase esté cargada **y** enganchada a `phpmailer_init` (`has_action()` devuelve la
**prioridad**, y 0 es *falsy*: por eso se compara con `false !==`). Si falta el fichero, lo repone
desde esa copia, **nunca servible por web**. Si el fichero
**está pero no cargó**, no lo pisa: grita para que lo mire una persona — así nació un downgrade
silencioso con el cron viejo. Guarda las 10 últimas reposiciones en
`akibara_mail_transport_watch`, y el historial sobrevive a que el problema se cure.

---

### 27. mu-plugin `akibara-correo-no-sale-a-clientes.php` — el guardarraíl del espejo

**Qué hace en una frase:** fuera de producción, todo el correo se **redirige** a una bandeja de
captura.

El espejo (la copia local de la tienda) tiene 84 direcciones reales de clientes. Si
`wp_get_environment_type()` no es `production`, el filtro `wp_mail` en `PHP_INT_MAX` reescribe el
destinatario a `capturado@akibara.test`, antepone al asunto `[para <dirección original>]` y añade
dos cabeceras (`X-Akibara-Destinatario-Original`, `X-Akibara-Entorno`). Deja pasar tal cual las
direcciones de TLD reservados (`.test`, `.example`, `.invalid`, `.localhost`) y
`example.com`/`example.org`.

**Redirige, no bloquea**, y eso es deliberado: un guardarraíl que estorba se acaba apagando. En
producción el filtro devuelve los argumentos sin mirar nada más.

---

### 28. mu-plugin `akibara-brevo-portada-jpg-en-el-carrito.php` — la ventana de 3 milisegundos

**Qué hace en una frase:** es **todo** lo propio que existe alrededor de Brevo.

Abre una ventana en prioridad **9** y la cierra en la **11** sobre los tres ganchos donde los
oyentes de Brevo (prioridad 10) leen el carrito: `woocommerce_add_to_cart`,
`woocommerce_cart_item_removed` y `woocommerce_update_cart_action_cart_updated`. **Dentro de esa
ventana, y sólo dentro**, un filtro en `wp_get_attachment_image_src` (prioridad 20) cambia la
portada AVIF por su hermana `-og.jpg`, **escribiéndola en el acto** con `ensure_now()` si falta —
porque Brevo guarda la URL de ese instante y el correo de carrito abandonado sale horas después.
Fuera de la ventana el sitio sigue sirviendo AVIF a todo el mundo.

Si el plugin de Brevo o el módulo de derivados no están cargados, **no engancha nada**: muere con el
tercero, como manda la regla de oro de «cero código muerto».

---

### 29. mu-plugin `akibara-discovery-warming.php` — la caché de los sitemaps

**Qué hace en una frase:** declara los sitemaps `no-store` para el navegador y cacheables 4 horas
para el CDN.

Un solo enganche a `rank_math/sitemap/http_headers` (10) que fija tres cabeceras: `Cache-Control:
no-store`, `Cloudflare-CDN-Cache-Control` y `CDN-Cache-Control: public, max-age=14400` (`:35-39`).
La Cache Rule de Cloudflare usa el mismo TTL. El «calentamiento» periódico que daba nombre al
fichero se retiró porque no servía.

---

### 30. mu-plugin `akibara-https-urls-fuera-de-web.php` — https también desde el cron

**Qué hace en una frase:** fuerza `https` cuando la URL se compone fuera de una visita web.

Los correos generados por cron o por línea de comandos salían con las imágenes en `http://` y los
enlaces en `https://`, y el navegador bloqueaba el contenido mixto. El filtro `set_url_scheme` (10)
actúa **sólo** cuando el esquema pedido es `null`, el aplicado es `http` y no hay `is_ssl()`.

---

## Lo programado

Tres trabajos del carril están agendados. Todos corren por **WP-Cron**, que en producción no lo
empuja el tráfico: `DISABLE_WP_CRON` está activo y el cron del hospedaje dispara `wp-cron.php`
**cada 5 minutos**. Eso significa que «diario» quiere decir «en la primera corrida de los 5 minutos
después de que venza el plazo», y que la hora exacta **depende de cuándo se activó el plugin**, no
de un reloj fijo.

**Por eso la columna «Frecuencia y hora Chile» de la tabla no se puede convertir en una hora de
reloj leyendo el capítulo: hay que preguntárselo a producción.** El comando es éste, y devuelve la
hora real de la próxima corrida de cada uno:

```bash
ssh akibara
wp cron event list --fields=hook,next_run_gmt,next_run_relative,recurrence | grep akibara_business_
```

[**Diagrama · Lo programado en un día chileno**](diagramas/07-business-seo-ia-blog-y-correo-cron.mmd)

| Trabajo | Mecanismo | Frecuencia y hora Chile | Quién lo programa | Qué hace | Qué pasa si corre dos veces |
| --- | --- | --- | --- | --- | --- |
| `akibara_business_llms_manifest_refresh` | WP-Cron recurrente | `daily`, primera corrida a **+1 h** de la activación (`LlmsManifest.php:203`) | `LlmsManifest::register()` | Regenera `/llms.txt` y `/llms-full.txt` | **Seguro.** Un *lease* de 30 min con compare-and-swap impide la segunda corrida; la escritura es atómica (temporal + `rename`) y hay rollback del extendido si falla el compacto. |
| `akibara_business_llms_manifest_refresh` | WP-Cron **puntual** | Bajo demanda: al guardar la identidad en el panel, o al pulsar el botón (`:496`, `:521`) | `queue_refresh_after_identity_change()` / `handle_regenerate_request()` | Lo mismo, cuanto antes | **Seguro** por el mismo lease. El aviso del panel distingue «regeneración encolada» de «ya había una». |
| `akibara_business_social_image_derivatives` | WP-Cron recurrente | `daily`, primera corrida a **+2 h** de la activación (`SocialImageDerivatives.php:90`) | `SocialImageDerivatives::register()` | Genera hasta **100** hermanas `-og.jpg` que falten | **Riesgo conocido.** La conversión es idempotente, pero el **cursor** no: `claim_lock()` (`:259-273`) ante un candado huérfano hace `update_option(); return true;` sin comparar, y el `finally` (`:247-249`) hace `delete_option()` sea de quien sea. Dos corridas solapadas pueden pisarse el cursor y saltarse un tramo del directorio (sospecha S14). |
| `akibara_business_social_image_for_slug` | WP-Cron **puntual**, con el slug como argumento | Bajo demanda, desde `ensure_for_slug()` (`:383`) | El propio `SeoSocial` al servir un `og:image` cuya hermana falta | Convierte **una** portada | **Seguro.** La conversión es idempotente y `wp_schedule_single_event` deduplica por hook + argumentos. |

**Dos cosas que hay que saber antes de fiarse de esta tabla.**

1. **La cadena del cron de portadas nunca arranca.** El re-agendado está tras
   `! wp_next_scheduled( self::HOOK )` sobre un hook que **ya** tiene un evento diario permanente:
   la condición nunca se cumple (`:239-241`). El tope real son 100 portadas al día. El botón del
   panel encola el mismo hook, así que también rinde 100.
2. **Al desactivar el plugin, estos dos hooks quedan huérfanos.** `akibara-business.php:248-253`
   limpia seis eventos recurrentes y no limpia ninguno de los dos de portadas;
   `uninstall.php:46-47` sí lo hace, y su propio comentario admite el hueco.

El resto del carril **no está programado**: reacciona a peticiones web (el SEO, el `og:image`, la
negociación Markdown, el blog) o a eventos de la tienda (los correos). Los correos propios sí
dependen de crons, pero de crons que viven en **otros** carriles:
`akb_order_preventa_t7_reminder_scan` (el barrido T-7 de preventa), `akb_sf_digest_run` (el resumen
de series) y la cola de `BackInStock`.

---

## Casos de uso

### Caso 1 · Alguien busca «cuántos tomos tiene the climber» y cae en la landing de serie

[**Diagrama · Caso, la landing de serie en Google**](diagramas/07-business-seo-ia-blog-y-correo-caso-landing-serie.mmd)

Google sirve `/serie/the-climber/`. Lo primero que ocurre **no** es escribir el título, sino
**decidir si esa página merece existir en el índice**.

1. `SeriesPaginationPolicy` (`src/Modules/SeoIndexing/SeriesPaginationPolicy.php`) le pregunta a
   `SeriesCatalogEligibility` si la serie tiene productos publicados y visibles, y lee el
   `rank_math_robots` del término.
   - **Bifurcación A — no hay catálogo:** la landing sale `noindex,follow` aunque el conteo bruto de
     WordPress diga otra cosa, y aquí acaba la historia.
   - **Bifurcación B — la lectura falla:** no se decide nada y la respuesta se marca no cacheable
     (`litespeed_control_set_nocache`), para que el siguiente request reintente en vez de congelar un
     veredicto equivocado.
   - **Bifurcación C — la URL trae cualquier parámetro:** esta política se inhibe (`:161`) y manda
     `FilteredUrlsPolicy`. Ojo: en la representación nativa `wp_robots` el orden está invertido, así
     que ese guard es lo único que evita que esta política devuelva `index` a una URL que la otra
     acaba de marcar.
2. Con catálogo, `SeoEnhancements::prefer_series_term_title()`
   (`src/Modules/SeoEnhancements/SeoEnhancements.php:1095-1103`) mira el `termmeta`: si hay texto y
   su hash no coincide con el guardado, alguien lo escribió a mano y **no se toca**. Si está vacío
   —o es una foto generada reconocible— se compone.
3. Para componerlo hace falta el **medio** (manga / cómic / manhwa). Resolverlo cuesta seis consultas
   y ~70 ms, así que se cachea 36 h en `akb_serie_medio_<term_id>` — pero **sólo el valor resuelto**.
4. `SeriesFacts` da tomos, autor único, editorial única, precio mínimo y cuántos tomos están en
   stock hoy. `compose_series_meta()` (`:2190-2239`) prueba variantes **enteras** de más a menos
   específica y se queda con la primera que quepa en 62 caracteres.
   - **Bifurcación D — ninguna cabe:** el bucle acaba con `$title = ''` y se publica **la plantilla
     de Rank Math**, no una variante de la casa.
   - **Bifurcación E — hay campaña de descuento vigente:** el precio no entra en la meta.
5. Si la URL era la página 2, la prioridad 99 sufija «Página 2 de 4»; si la descripción se pasa de
   160, se conservan la primera frase y la última y se descarta la intermedia
   (`compact_paginated_series_description()`, `:2337-2352`).
   - **Aviso: esa función tiene dos caminos y hoy manda el de respaldo.** El camino principal está
     anclado a dos frases literales: busca `' en Akibara Chile.'` **y** la palabra `'Revisa '`
     (`:2338-2340`). El generador rico que compone casi todas las landings
     (`compose_series_meta()`) **nunca escribe «Revisa»**: sus cierres son «Cómpralo en Akibara con
     despacho a todo Chile.», «Desde $X en Akibara…», «Despacho a todo Chile.» y «en Akibara
     Chile.». La única variante viva que sí lleva «Revisa» es la plantilla genérica de respaldo
     (`series_meta_description()`, `:1529-1531`), la que se usa cuando el catálogo no puede sostener
     una afirmación rica. O sea: **para casi todo lo que se compone hoy, el recorte lo hace el
     `preg_split` por frases** (`:2346`), no el anclaje. La regla se cumple igual; lo que es residuo
     es el anclaje, que sólo acierta sobre filas viejas guardadas en `termmeta` — el mismo tipo de
     residuo que `SERIES_META_RETIRED_CLOSINGS` documenta que hay que marcar, y que aquí está sin
     marcar.
6. En paralelo, `SeriesSitemapLastmod` ya había decidido **qué fecha** declara el sitemap para esa
   landing, y `align_series_breadcrumb_schema()` inserta el nodo «Series» en la miga estructurada
   para que coincida con la visible.

**Correos que salen:** ninguno.

---

### Caso 2 · El dueño retoca el SEO de una serie y lo congela sin querer

1. Abre Productos → Atributos → Serie → *Berserk*. Antes de tocar nada, el renglón «Procedencia del
   SEO» (`SeoEnhancements::render_series_meta_provenance()`) le dice, para el título y para la
   descripción, si es «automático (se compone al servir la página)», «automático (foto guardada del
   catálogo)» o «manual: congelado, ya no sigue al catálogo». Y le enseña, literalmente, **lo que
   publicaría hoy el modo automático**.
2. Cambia una coma en la descripción y guarda. El `sha256` guardado deja de coincidir con el texto,
   así que `is_generated_series_meta_field()` (`:1150-1180`) pasa a considerarlo editorial: esa
   landing deja de seguir al catálogo y ya no cambiará cuando entre un tomo nuevo. La columna «SEO»
   del listado lo muestra de un vistazo.
   - **Bifurcación A — el campo estaba vacío:** un campo vacío **no** está congelado; es automático
     en vivo.
   - **Bifurcación B — la fila era de una plantilla retirada:** si cierra exactamente con «Revisa
     precio y disponibilidad en Akibara.» se reconoce como foto generada aunque no lleve marca. Son
     72 landings que si no se congelarían para siempre.
3. Se arrepiente y pulsa «Volver a automático». La URL va firmada con `nonce`; se comprueba
   `manage_product_terms` (`:1350-1352`); se reescriben valor, marca de propiedad y hash; y se purga
   la caché de página de esa landing (`litespeed_purge` con `T.<term_id>`, el archivo completo con
   sus páginas 2+) — porque escribir `termmeta` no dispara ningún evento de post y LiteSpeed seguiría
   sirviendo la versión anterior.
   - **Bifurcación C — el catálogo no sostiene una foto publicable** (no hay medio reconocible): el
     aviso lo dice con esas palabras. El generador **nunca** escribe una cadena vacía.
4. En paralelo, la edición cambió la huella de la serie, así que `SeriesSitemapLastmod` escribirá en
   `shutdown` una fecha nueva en el sitemap.

**Correos que salen:** ninguno.

---

### Caso 3 · Un cliente pide aviso de stock y el tomo vuelve a bodega

[**Diagrama · Caso, el aviso de vuelta a stock**](diagramas/07-business-seo-ia-blog-y-correo-caso-aviso-stock.mmd)

0. Al guardar el stock, `BackInStock\StockWatcher` (`src/Modules/BackInStock/StockWatcher.php:119`)
   dispara la acción **`akb_bis_notify_product`** con el id del producto. **Ése es el gatillo**, y es
   el nombre que hay que usar para forzarlo a mano en el espejo:
   `wp eval "do_action( 'akb_bis_notify_product', 12345 );"`.
1. `BackInStock\NotificationDispatcher` (`src/Modules/BackInStock/NotificationDispatcher.php`) hace
   el **claim atómico** de la suscripción (*at-most-once*:
   el correo **no** se registra en `woocommerce_email_actions` precisamente para que el re-dispatch
   de WooCommerce no lo dispare dos veces) y resuelve la instancia con `MailerBoot::from_mailer()`
   (`src/Services/MailerBoot.php`), que arranca `WC()->mailer()` si hace falta.
2. `InstockEmail::trigger()` pone el destinatario, el placeholder `{product_name}` y la URL de baja
   de un clic. `AkibaraEmail::get_headers()` (`:92-101`) añade `List-Unsubscribe` y
   `List-Unsubscribe-Post`.
3. El cuerpo se arma **dos veces**, en HTML y en texto plano, porque el tipo por defecto es
   `multipart` (`src/Services/AkibaraEmail.php:194-202`).
4. `MailFrom` (prioridad 15) fuerza `Akibara <contacto@akibara.cl>` ignorando lo que traiga.
5. El mu-plugin del transporte valida el perfil SMTP y configura PHPMailer contra
   `smtp.hostinger.com`.

**Bifurcaciones.**
- El correo del suscriptor está vacío → no se envía nada.
- El perfil SMTP no valida → `pre_wp_mail` **corta** antes de caer al `mail()` de PHP y escribe
  `akibara_mail_transport_outage` con causa y contador; en el admin aparece un aviso rojo no
  descartable.
- El entorno no es producción → el mu-plugin de captura reescribe el destinatario a
  `capturado@akibara.test` y antepone `[para <dirección original>]` al asunto.

**Correo que sale:** el asunto por defecto es, **literalmente**,
**`{product_name} está de vuelta en Akibara`** (`InstockEmail.php:59`). El `{product_name}` es un
marcador que WooCommerce sustituye al enviar, y es **lo que hay que escribir en el campo** si el
dueño lo quiere cambiar (WooCommerce → Ajustes → Correos →
`/wp-admin/admin.php?page=wc-settings&tab=email`). Lo que le llega al cliente es «*Berserk 43* está
de vuelta en Akibara». **Momento:** con la cola, **2-3 minutos después de reponer**, no en el
instante de reponer.

> Dos cosas que ese correo hace mal hoy y están medidas: la portada que incrusta es el **AVIF**
> (`InstockEmail.php:112` resuelve `woocommerce_thumbnail` sobre una portada que sólo existe en
> AVIF), que Gmail no pinta — la hermana `-og.jpg` existe y no se usa (sospecha S2); y en la parte de
> texto plano el nombre del producto pierde apóstrofos y comillas tipográficas por el escapado doble
> (`plain/customer-bis-instock.php:27`).

---

### Caso 4 · Un cliente compra una preventa y recibe hasta cuatro correos

1. **Siete días antes del arribo estimado**, el barrido T-7 (`akb_order_preventa_t7_reminder_scan`,
   cron de otro carril) dispara `ReminderEmail::trigger_t7()`. El correo dice la fecha estimada de
   arribo **a bodega** y promete avisar cuando llegue.
   **Correo:** «Tu pedido *N* se acerca».
2. **Si la editorial mueve la fecha**, el admin la edita y se dispara `trigger_date_changed()`. Aquí
   está la lección más cara del carril: el correo dice «salía el X; ahora sale el Y» **y, aparte**,
   «te llegaría alrededor del Z (contando el envío desde la editorial)». Antes se imprimía sólo la
   fecha de la editorial bajo una etiqueta de «estimada», y como el envío tarda semanas, una
   postergación de 21 días salía como una fecha **anterior** a la ya prometida.
   **Correo:** «Actualización de tu preventa *N*: nueva fecha» o «Actualización de tu pedido *N*:
   cambio de fecha». **Momento:** inmediato al guardar en el admin.
   - **Bifurcación:** si el pedido espera **otro** tomo que llega más tarde, eso se dice en una línea
     propia, para que el cliente no lea la fecha del otro como si fuera la de éste.
3. **Si el tomo se compró sin fecha** y la editorial la anuncia, `trigger_date_announced()` manda una
   buena noticia; si el tomo **ya** tenía un rango prometido, el mismo correo se redacta como un
   cambio y ofrece cancelar con reembolso.
   **Correo:** «¡Ya tenemos fecha para tu preventa *N*!» o «Tu pedido *N*: la editorial anunció fecha
   de salida». **Momento:** inmediato.
4. **Cuando todo llega**, `ReadyToShipEmail::trigger()` toma un lock, marca el pedido **antes** de
   enviar, y comprueba **releyendo sin caché** que el marcador quedó guardado de verdad — porque
   `WC_Order::save()` devuelve el ID incluso si su almacén se tragó una excepción, y sin marcador
   durable el doble despacho (Action Scheduler ‖ WP-Cron) vuelve a estar abierto. El correo dice o
   bien «Tu pedido es para retiro» o bien «Despachamos en *ventana*», y lista lo que se esperaba con
   la **cantidad pendiente real**, descontando reembolsos (`PreventaItems::outstanding_qty()`).
   **Correo:** «Tu pedido *N* ya llegó a Akibara». **Momento:** Action Scheduler o WP-Cron.

**La palabra sigue a cómo se compró.** Los correos dicen «tu preventa» **sólo** si alguna línea en
espera lo es de verdad; si no, dicen «tu pedido». Las 20 líneas en espera de la tienda el 2026-09-02
eran *a pedido*, no preventas.

> **De los cuatro textos que este caso enumera, el dueño sólo puede cambiar uno por pantalla.** Los
> pasos 1, 2 y 3 salen todos de la **misma clase** (`ReminderEmail`) y por tanto de **la misma fila**
> de WooCommerce → Ajustes → Correos. El asunto y el encabezado que el dueño escriba ahí valen
> **sólo para el aviso T-7** del paso 1 («Tu pedido *N* se acerca»). Los textos de los pasos 2 y 3
> son constantes (`ReminderEmail.php:193-196`) y sólo se cambian por código, con el filtro
> `woocommerce_email_subject_akb_preventa_reminder`. El del paso 4 (`ReadyToShipEmail`) sí tiene su
> propia fila y sí es editable. La pantalla lo advierte en la descripción de los dos campos
> (`:704` y `:709`); el motivo está en la pieza 21.

**Bifurcación transversal:** el destinatario es `$order->get_billing_email()`, que existe tenga
cuenta o no — pero el botón «Ver mi pedido» de estas cuatro superficies apunta a `view-order`, que
para un invitado es un muro de acceso (sospecha S3).

---

### Caso 5 · Un agente de IA responde «dónde compro Jujutsu Kaisen en Chile»

[**Diagrama · Caso, el agente de IA**](diagramas/07-business-seo-ia-blog-y-correo-caso-agente-ia.mmd)

1. El agente pide `https://akibara.cl/llms.txt`. El fichero es **físico** en el docroot, así que el
   `.htaccess` lo sirve antes de llegar al handler de Rank Math.
2. Lee el `# Akibara`, el blockquote con la identidad que el dueño escribió desde el panel, la
   sección «cuándo usar Akibara» y la de «no sirve para» (leer manga en línea, descargas, catálogos
   de otros países), y la lista de series con su landing.
3. Si quiere el detalle, pide `/llms-full.txt`, que añade los tomos por serie.
4. Si en cambio pide una URL normal con `Accept: text/markdown`, `MarkdownNegotiation` responde **la
   misma URL** en texto plano con precio, disponibilidad y SKU. **Ésta es la rama viva:** en
   producción el interruptor `akb_markdown_negotiation_enabled` está en `yes` desde el 2026-08-28
   (el default *del código* es `no`; compruébalo con
   `wp option get akb_markdown_negotiation_enabled`).
   - **Nota — cómo se entera un agente de que la variante existe:** no hace falta que la adivine. La
     respuesta **HTML** de esa misma URL lleva la cabecera
     `Link: <url>; rel="alternate"; type="text/markdown"` (`MarkdownNegotiation.php:278-287`). Es la
     única pieza del contrato que se ve sin negociar.
   - **Bifurcación A — el interruptor está apagado** (cualquier entorno nuevo, y producción si
     alguien lo apaga): no se negocia nada y recibe HTML. Pero las **ocho redirecciones 301 de alias
     siguen activas**, porque no consultan el interruptor.
   - **Bifurcación B — el cliente es Chrome:** su `*/*;q=0.8` también acepta Markdown, pero
     `AcceptParser` ordena por `q`, luego por especificidad, luego por la preferencia del servidor, y
     **HTML gana los empates**. Chrome recibe HTML.
   - **Bifurcación C — el cliente rechaza los dos tipos:** `406`.
   - **Bifurcación D — hay sesión iniciada:** no se negocia nunca (la barra de admin y los precios
     por rol hacen que la salida no sea la pública).
   - **Bifurcación E — la URL era un 404:** se sirve como 404 también en Markdown, con los puntos de
     entrada del sitio.
5. Esa respuesta viaja con `Vary: Accept` y con `no-store` en todas las capas. No basta: la Cache
   Rule nº 1 de la zona usa `override_origin` y pisa las cabeceras del origen, y por eso el
   2026-08-28 el borde sirvió Markdown a un navegador con `cf-cache-status: HIT`. Lo que lo impide
   hoy es la **Cache Rule nº 10, «BYPASS Accept text/markdown (agentes IA)»**, más su equivalente en
   el `.htaccess` después del bloque `# END LSCACHE`. Esa regla vive **fuera de este código** y hay
   que comprobarla con dos peticiones (ver pieza 15), nunca con una.

**Correos que salen:** ninguno.

---

### Caso 6 · Un cliente comparte un tomo por WhatsApp

[**Diagrama · Estados de la portada social**](diagramas/07-business-seo-ia-blog-y-correo-estados-portada.mmd)

1. El rastreador de WhatsApp pide la página y lee las etiquetas `og:` que emite Rank Math.
2. En una landing de serie, `SeoSocial::prefer_series_cover_image()` (prioridad 10) sustituye el
   banner genérico por la portada del primer producto por título ascendente — que por la convención
   del pipeline es el tomo 01.
   - **Bifurcación:** esa consulta no filtra `exclude-from-catalog`, así que un pack retirado que
     ordene primero por título se convierte en la imagen de la tarjeta.
3. `SeoSocial::prefer_jpeg_social_image()` (prioridad 20) reconoce la URL AVIF de `/uploads/portadas/`
   y llama a `SocialImageDerivatives::ensure_for_slug()`.
   - **Si la hermana `-og.jpg` existe y es más nueva que el AVIF:** se publica esa URL y
     `og:image:type` pasa a `image/jpeg`. **Tarjeta con portada.**
   - **Si no existe:** se **encola** un evento de cron para esa portada y se conserva el AVIF en esta
     respuesta — mejor una vista previa posiblemente rota que un 404 seguro. Y el `og:image:type` se
     pierde. **Tarjeta sin portada.**
   - **Si la conversión falló antes** (AVIF corrupto, permisos): el transient `akb_og_fail_<slug>`
     evita reintentar durante 6 h.
4. La conversión real ocurre en el cron diario (hasta 100 por corrida), en la subida de la portada, o
   en el acto **sólo** para el evento de carrito de Brevo.

**Correos que salen:** ninguno directo. Indirectamente, el correo de carrito abandonado que Brevo
manda **desde su propio servicio** lleva la portada en JPG gracias al mu-plugin de la ventana 9-11 —
y ni su asunto ni su plantilla viven en este código.

---

### Caso 7 · El correo de la tienda deja de salir y el dueño se entera

[**Diagrama · Caso, el correo mudo**](diagramas/07-business-seo-ia-blog-y-correo-caso-correo-mudo.mmd)

**Caso A — el fichero del transporte desaparece.**
1. En el siguiente `admin_init`, o en la siguiente corrida del cron del hospedaje (cada 5 minutos),
   `Watch::inspect()` comprueba que la clase del transporte esté cargada **y** enganchada a
   `phpmailer_init`.
2. Si **falta el fichero**, lo repone desde la copia hermana del docroot y anota la reposición en el
   historial (máximo 10).
3. Si el fichero **está pero no cargó**, **no lo pisa**: reponer una copia más vieja encima de una
   más nueva está prohibido — así nació un downgrade silencioso.
4. El aviso rojo «Falta el transporte de correo de Akibara» **no se puede descartar**; el ámbar
   «desapareció y se repuso solo» sobrevive a que el problema se cure.

**Caso B — el perfil SMTP no valida.** `Transport::guard_configuration()` corta `wp_mail()` en
`pre_wp_mail` antes de caer al `mail()` de PHP, y escribe `akibara_mail_transport_outage` con causa,
hora y contador. Se grita **desde el primero**: un corte del guard es estructural.

**Caso C — el servidor SMTP rechaza.** `wp_mail_failed` anota lo que dijo el servidor, recortado a
140 caracteres. El aviso sólo grita a partir de **tres rechazos seguidos**, porque uno suelto suele
ser una dirección mal escrita.

**En los tres casos:** el rastro se borra únicamente cuando un correo **sale de verdad**
(`wp_mail_succeeded`). Y `record_outage()` tiene candado de reentrada, porque avisar del corte por
correo volvería a entrar en `wp_mail` y recursaría sin fondo.

**Resultado histórico:** en agosto de 2026 la tienda pasó tres días sin mandar un correo —pedido
27720 incluido— y se supo por un reclamo de un cliente. Hoy el dueño lo ve en el admin.

**Correos que salen:** ninguno, deliberadamente.

---

### Caso 8 · Alguien escribe una guía en el blog y se enlaza sola

1. `Blog::auto_product_links()` se engancha a `the_content` en prioridad 20, sólo en entradas
   singulares de tipo `post`.
2. Pide el mapa nombre de serie → URL, que sale de `SeriesIndex` (catálogo público real y sin
   `noindex`) y vive 24 h en el transient `akb_blog_series_map`.
3. El contenido se parte en trozos de texto y etiquetas; se lleva la cuenta de si estamos dentro de
   un `<a>` o de un título, para no enlazar ahí.
4. La **primera** mención de cada serie se convierte en enlace, respetando el texto que el autor
   escribió — se reemplaza la coincidencia, no el nombre del término, con
   `preg_replace_callback()` para que un `$` o una barra invertida en el nombre no se interprete como
   referencia.
   - **Bifurcación A — el nombre tiene menos de 4 caracteres:** queda fuera.
   - **Bifurcación B — el nombre es palabra española corriente (`Saga`):** sólo enlaza con la caja
     exacta.
   - **Bifurcación C — el nombre empieza o acaba en signo (`Haikyu!!`):** **nunca** enlaza, por el
     `\b` del patrón. Nadie lo dice: ni el código ni el panel.
5. Publicar el primer tomo de una serie —o sacarle el último, o guardar cualquier producto— tira el
   mapa, igual que crear o editar el término.

**Correos que salen:** ninguno.

---

## Cómo se comprueba que esto está vivo

Este carril falla callado: no da error, simplemente dice algo que no es. Por eso no basta con
entenderlo, hay que poder **medirlo**. Todo lo que sigue es de **sólo lectura**: no envía correos,
no regenera nada, no cambia ninguna option.

**Lo que hay en producción ahora mismo** (`ssh akibara`, desde el docroot):

```bash
# ¿Qué está agendado y a qué hora real de hoy?
wp cron event list --fields=hook,next_run_gmt,next_run_relative,recurrence | grep akibara_business_

# El manifiesto para agentes: última generación buena y último fallo
wp option get akibara_business_llms_manifest_last_success --format=json
wp option get akibara_business_llms_manifest_last_error   --format=json

# El correo: ¿hay un corte anotado? ¿desapareció el transporte alguna vez?
wp option get akibara_mail_transport_outage --format=json
wp option get akibara_mail_transport_watch  --format=json

# El interruptor sin pantalla (producción: yes)
wp option get akb_markdown_negotiation_enabled

# Cuántas hermanas -og.jpg hay escritas frente a cuántas portadas existen
ls -1 wp-content/uploads/portadas/*-og.jpg | wc -l
ls -1 wp-content/uploads/portadas/*.avif   | wc -l
```

**Lo que se ve desde fuera** (desde cualquier máquina; el `-A AkibaraQA` marca la petición como
sonda para que no ensucie la analítica de clientes):

```bash
# El fichero para agentes existe y lo sirve el docroot, no Rank Math
curl -sI -A AkibaraQA https://akibara.cl/llms.txt | head -3

# La negociación: SIEMPRE dos peticiones, nunca una
curl -sI -A AkibaraQA -H 'Accept: text/markdown' https://akibara.cl/berserk-1/ | grep -i 'content-type\|cf-cache-status\|vary'
curl -sI -A AkibaraQA                            https://akibara.cl/berserk-1/ | grep -i 'content-type\|cf-cache-status\|link'
```

La primera tiene que devolver `text/markdown`; la segunda, `text/html` y la cabecera `Link:` con la
alternativa. Si la segunda devuelve Markdown, la regla de borde de Cloudflare se cayó.

**El correo, de una vez:** `bash ops/akibara-prod-mail-health.sh`. Comprueba que el mu-plugin del
transporte esté en producción, que la copia de rescate de `akb-runtime/` siga en su sitio y con el
hash correcto, y autentica contra SMTP sin enviar nada. Sale con código 1 si algo crítico falla.

**Lo que NO se comprueba desde aquí, porque no vive en este código:** la Cache Rule nº 10 de
Cloudflare (se mira en el panel de la zona, o indirectamente con las dos peticiones de arriba) y las
seis constantes de `wp-config.php` del transporte (ver pieza 25).

---

## Reglas de negocio

Sólo lo que está **escrito en el código**, con su `fichero:línea` del snapshot.

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
| --- | --- | --- | --- |
| El título SEO generado nunca pasa de 62 caracteres: se prueban variantes enteras y jamás se corta una frase. Si ninguna cabe, se publica la plantilla de Rank Math. | `SeoEnhancements.php:2233-2239` y `:1101-1103` | No | Sólo por código |
| La descripción SEO generada nunca pasa de 155 caracteres (160 con el marcador de paginación). | `SeoEnhancements.php:1004, :1535, :2362` | No | Sólo por código |
| Se puede decir «completo: N tomos» sólo si la obra está finalizada, el catálogo cubre esos N **y** todos se pueden comprar hoy. | `SeoEnhancements.php:2190-2192` | No | Sólo por código |
| Si la obra está cerrada pero vendemos una parte (Naruto: 5 de 72), se publican los **dos** ejes rotulados; nunca una cifra ambigua. | `SeoEnhancements.php:2191` | No | Sólo por código |
| Si la **edición** que vendemos cerró en menos tomos que la obra original, se puede afirmar «edición completa en N tomos». | `SeoEnhancements.php:2205-2222` | No | Sólo por código |
| El vocabulario de medio es cerrado: manga, cómic, manhwa. Sin medio reconocible manda la plantilla genérica. | `SeoEnhancements.php:133` | No | Sólo por código (raíces de `product_cat`) |
| El medio resuelto de una serie se cachea 36 h; el valor vacío **nunca** se cachea. (El docblock de `forget_series_medium()`, `:1986`, dice «12 h»: está caducado, manda la constante.) | `SeoEnhancements.php:159` y `:1955` | No | Sólo por código |
| Un título o descripción de serie guardado cuenta como editorial (y se congela) salvo que su marca sea `generated_snapshot_v1` **y** el `sha256` coincida. | `SeoEnhancements.php:1150-1180` | **Sí** | Productos → Atributos → Serie → botón «Volver a automático» |
| Una fila que cierra exactamente con «Revisa precio y disponibilidad en Akibara.» se reconoce como foto de una plantilla retirada, aunque no lleve marca. | `SeoEnhancements.php:105-107` | No | Sólo por código; al añadir una plantilla nueva la anterior se **agrega**, nunca se sustituye |
| Volver una serie a SEO automático exige `nonce` y la capability `manage_product_terms`. | `SeoEnhancements.php:1350-1352` | No | Sólo por código |
| El texto editorial de `/manga/` se parte en dos (arriba y al pie) y el de género/autor se pinta plegado con «Leer más». La descripción nativa de WooCommerce se desengancha. | `SeoEnhancements.php:220-226`, `:408-474`, `:497-551` | No | Sólo por código (el texto en sí, en la descripción del término) |
| Ese texto editorial **no se pinta** si la URL trae cualquier parámetro o es página 2+. | `SeoEnhancements.php:351`, `:478`, `:572` | No | Sólo por código |
| La página contenedora `/tienda/` queda fuera del sitemap de páginas. | `SeoEnhancements.php:591-597` | No | Sólo por código |
| Sin Rank Math activo, los archivos de producto emiten canonical autorreferente propio, y sólo con la URL limpia. | `SeoEnhancements.php:564-580` | No | Sólo por código |
| Una landing de serie sin ningún producto publicado y visible es `noindex,follow`, aunque el conteo bruto de WordPress sea positivo. | `SeriesPaginationPolicy.php:76-83` y `:104-112` | No | Sólo por código |
| Las páginas 2+ limpias de una serie son `index,follow` con canonical a sí mismas; cualquier parámetro las devuelve a la política de filtrados. | `SeriesPaginationPolicy.php:158-169` | No | Sólo por código |
| Toda URL de catálogo con un parámetro de la gramática de filtros es `noindex,follow`; `add-to-cart`, `add-to-wishlist` y `mailpoet_page` también. | `FilteredUrlsPolicy.php:80-83` y `:212-214` | No | Sólo por código (gramática en `src/Utilities/CatalogQueryGrammar.php`) |
| Una colección facetada sin resultados responde 404 real conservando la plantilla; la búsqueda por texto queda excluida. | `FilteredUrlsPolicy.php:131-151` y `:162-178` | No | Sólo por código |
| `/series/page/N/` no existe: 404 y se impide el 301 de recuperación de WordPress. | `SeriesDirectorySearchPolicy.php:174-191` y `:119-125` | No | Sólo por código |
| El stock y el precio **no** forman parte de la huella que fecha una landing en el sitemap. | `SeriesSitemapLastmod.php:64-82` | No | Sólo por código |
| Como máximo 8 ediciones relacionadas por serie entran en la huella. | `SeriesSitemapLastmod.php:59` y `:1789` | No | Sólo por código |
| La cola durable del sitemap admite 2.048 ids; al superarlos colapsa a un rescan completo. | `SeriesSitemapLastmod.php:49` y `:1196-1203` | No | Sólo por código |
| El `og:locale` de Facebook es `es_LA`, no `es_ES` (Facebook no soporta `es_CL`). | `SeoSocial.php:165-167` | No | Sólo por código |
| La imagen social de una landing de serie es la portada del primer producto por título ascendente. | `SeoSocial.php:63-80` | No | Sólo por código |
| La variante social es JPEG calidad 82 y se generan como máximo 100 por corrida del cron. | `SocialImageDerivatives.php:56` y `:64` | No | Sólo por código |
| Una conversión fallida no se reintenta durante 6 horas. | `SocialImageDerivatives.php:79` y `:156` | No | Sólo por código |
| El candado de la corrida de portadas caduca a los 15 minutos. | `SocialImageDerivatives.php:70` y `:265` | No | Sólo por código |
| Con Rank Math activo hay **un solo** emisor del nodo `Product`: el de WooCommerce se suprime. Sin Rank Math, WooCommerce conserva el suyo y se le añaden los datos. | `SchemaProductRankMath.php:45-49` y `:64` | No | Sólo por código; además el filtro `akibara_schema_rankmath_product_enabled` apaga el módulo entero |
| `availability = PreOrder` sólo para preventa real; todo lo demás reservable es `BackOrder`. `availabilityStarts` sólo con preventa **y** fecha válida. | `SchemaProductRankMath.php:443-449` | No | Sólo por código (el estado lo decide `Stock\Availability`) |
| Open Graph omite `product:availability=instock` para cualquier producto reservable. | `SchemaProductRankMath.php:78-89` | No | Sólo por código |
| La `description` del `Product` no lleva precio, stock, envío, llamada a la acción, nombre de la tienda ni editorial: sólo nombre, tomo/serie y autoría, tope 500 caracteres y cláusulas enteras. | `SchemaProductRankMath.php:27` y `:215-263` | No | Sólo por código |
| Un producto sólo se declara `Book` si está en una de estas categorías: manga, shonen, seinen, shojo, manhwa, comics, vertigo, independiente. | `BookSchemaEnricher.php:31-40` | No | Sólo por código |
| El ISBN se publica sólo si el SKU pasa la validación del dígito de control (ISBN-10 o ISBN-13). | `BookSchemaEnricher.php:210-235` | No | Sólo por código |
| `inLanguage` del libro es siempre `es`. | `BookSchemaEnricher.php:87` | No | Sólo por código |
| La política de devoluciones publicada son 10 días, país CL, devolución por correo, reembolso total; el arrepentimiento lo paga el cliente y el defecto lo paga Akibara. | `ProductSchemaShopping.php:37` y `:167-187` | No | Sólo por el filtro de código `akibara/schema_shopping/return_days` — **no hay pantalla** |
| `priceValidUntil`: para un producto **sin** fin de oferta futuro, el hermano lo borra y éste escribe una ventana rotante de 30 días, recalculada en cada render — gana la sintética. **Con** fin de oferta futuro, el hermano no borra y sobrevive la fecha real de WooCommerce. | `ProductSchemaShopping.php:223-225` frente a `SchemaProductRankMath.php:521-524` | No | Sólo por código; **decisión de negocio pendiente** sobre el primer caso |
| El precio de lista (tachado) sólo se declara si el producto está rebajado y el precio regular real es mayor que el activo. | `ProductSchemaShopping.php:238-246` | No | Sólo por código |
| Una serie se declara adulta en el feed de Merchant si su `termmeta` `_akb_adult_content` vale exactamente `'1'`. | `AdultContentFeed.php:104-106` | **Sí** | Productos → Atributos → Serie: casilla «Contenido +18 (Merchant)» |
| La semilla +18 son cinco series; se copia una sola vez y después manda el `termmeta`. | `AdultContentFeed.php:70-76` y `:152-188` | **Sí** | La casilla del término; la constante sólo siembra |
| Guardar la casilla +18 exige `nonce`, capability `manage_product_terms` y que el término exista. | `AdultContentFeed.php:292-300` | No | Sólo por código |
| El `llms.txt` **no** lleva precios ni disponibilidad: el fichero puede vivir 24 horas. | `LlmsManifest.php:20-22` y `:1312` | No | Sólo por código |
| La identidad de la tienda para agentes se guarda en una sola línea de máximo 600 caracteres; vacía vuelve al literal de fábrica. | `LlmsManifest.php:69` y `:436-460` | **Sí** | Panel Akibara → Salud SEO → «Descripción de la tienda» |
| Sólo entran en el manifiesto las series con conteo publicable **y** `rank_math_robots` indexable; lo mismo para guías y productos. | `LlmsManifest.php:756-762` y `:1079` | **Sí** | El campo robots de Rank Math en cada término o entrada |
| Un producto ya listado bajo una serie no se repite en «Otros productos». | `LlmsManifest.php:969` y `:1038` | No | Sólo por código |
| Cualquier lectura degradada aborta la generación del manifiesto y conserva el fichero anterior, con código de error visible. | `LlmsManifest.php:560-586` | No | Sólo por código |
| El lease de generación del manifiesto dura 30 minutos. | `LlmsManifest.php:78` | No | Sólo por código |
| Los botones del panel Salud SEO **encolan** trabajo, nunca lo ejecutan en el request del admin. | `LlmsManifest.php:511-534` | No | Sólo por código |
| Desactivar el plugin **borra** `/llms.txt` y `/llms-full.txt` del servidor. | `akibara-business.php:255-265` | No | Sólo por código |
| La negociación de Markdown está **apagada** en el default **del código** y sólo se enciende con `akb_markdown_negotiation_enabled = 'yes'`. **Producción la tiene ENCENDIDA** desde el 2026-08-28. | `MarkdownNegotiation.php:176-177` y `:202-204` | No | **Sin pantalla**: `wp option get` / `update akb_markdown_negotiation_enabled` |
| Las 8 redirecciones 301 de alias en inglés corren **aunque** el interruptor esté apagado. | `MarkdownNegotiation.php:76-109` | No | Sólo por código |
| Nunca se negocia Markdown en admin, AJAX, cron, REST, feeds, robots, trackbacks, con sesión iniciada, ni con un método distinto de GET/HEAD. | `MarkdownNegotiation.php:206-228` | No | Sólo por código |
| La variante Markdown hereda el estado de la consulta: un 404 se sirve como 404. | `MarkdownNegotiation.php:171-173` | No | Sólo por código |
| La variante Markdown no se cachea en ninguna capa. | `MarkdownNegotiation.php:331-339` | No | Sólo por código; además exige una regla de borde en Cloudflare |
| HTML gana los empates de la negociación, porque va primero en el array `OFRECIDOS` que el llamador le pasa a `AcceptParser`: invertir ese orden le serviría Markdown a cualquier cliente con `*/*`. | `MarkdownNegotiation.php:63` y `:157` | No | Sólo por código |
| La respuesta **HTML** de una URL negociable anuncia la alternativa con `Link: <url>; rel="alternate"; type="text/markdown"` (RFC 8288). | `MarkdownNegotiation.php:130` y `:278-287` | No | Sólo por código |
| En el blog sólo se enlaza la **primera** mención de cada serie, nunca dentro de un `<a>` ni de un título, y nunca nombres de menos de 4 caracteres. | `Blog.php:28`, `:145-147`, `:161-184` | No | Sólo por código |
| «Saga» —y cualquier nombre de serie que sea palabra corriente en español— sólo enlaza con la caja exacta. | `Blog.php:47` y `:166` | No | Sólo por código (`CASE_SENSITIVE_NAMES` se amplía a mano) |
| El mapa de series del blog vive 24 horas y no se cachea si la lectura fue degradada. | `Blog.php:230-235` | No | Sólo por código |
| Guardar **cualquier** producto tira el mapa de series del blog. | `Blog.php:66-68` y `:86-102` | No | Sólo por código |
| Todo correo del sitio sale como `Akibara <contacto@akibara.cl>`, ignorando incondicionalmente el `From:` que ponga quien envía. Corre en prioridad **15**, elegida a propósito para dejar sitio a un override de terceros en 20+; hoy no hay ninguno. | `MailFrom.php:47-52` y `:61-73` | No | Sólo por los filtros `akibara_mail_from_email` / `akibara_mail_from_name` |
| Los cuatro correos propios salen como `multipart` (HTML + texto plano) por defecto. | `AkibaraEmail.php:194-202` | **Sí** | WooCommerce → Ajustes → Correos → (cada correo) → Tipo de email |
| El esqueleto de la pantalla de cada correo son cuatro claves fijas (`enabled`, `subject`, `heading`, `additional_content`) más `email_type` al final. | `AkibaraEmail.php:128-206` | **Sí** | WooCommerce → Ajustes → Correos |
| Un campo extra de un correo hijo sólo aparece en la pantalla si se declara en `extra_form_fields()` con la clave `akb_after`; cualquier otra clave se descarta en silencio. Hoy lo usa **un** correo: el de novedades de serie, para su segundo asunto `subject_tanda`. | `AkibaraEmail.php:160-181` y `:207-228`; `SeriesFollowEmail.php:155-168` | **Sí** | WooCommerce → Ajustes → Correos → «Tomo nuevo de tu serie» |
| El recordatorio de preventa tiene tres modos y **una** fila de ajustes: el asunto y el encabezado editables valen **sólo para el aviso T-7**; los de «cambió la fecha» y «la editorial anunció fecha» son constantes. | `ReminderEmail.php:273-285`, `:292-304` y `:193-196` | Parcialmente | La fila del admin cambia sólo el T-7; los otros dos, por el filtro `woocommerce_email_subject_akb_preventa_reminder` |
| Un correo de la casa que declare URL de baja añade `List-Unsubscribe` y `List-Unsubscribe-Post` (RFC 8058). | `AkibaraEmail.php:92-101` | No | Sólo por código |
| El botón «Ver mi pedido» sólo se pinta en la copia del **cliente**, y para un pedido de invitado apunta a la página de «pedido recibido», no a `view-order`. | `EmailBrand.php:91-125` | No | Sólo por código |
| El botón de correo mide 45 px de alto real, por encima del mínimo táctil de 44 px. | `EmailButton.php:12-13` y `:33-35` | No | Sólo por código |
| El transporte SMTP **no se activa** si `AKIBARA_HOSTINGER_MAIL_ENABLED` no está definida y no vale exactamente `true`. | `mu-plugins/akibara-correo-smtp.php:406-407` | **Sí** | Constante de `wp-config.php` |
| En producción el transporte SMTP sólo acepta `smtp.hostinger.com` con 465/ssl o 587/tls y usuario `contacto@akibara.cl` con contraseña no vacía. Las cinco constantes del perfil son `AKIBARA_HOSTINGER_SMTP_HOST`, `..._PORT`, `..._ENCRYPTION`, `..._USERNAME` y `..._PASSWORD`. | `mu-plugins/akibara-correo-smtp.php:344-354` y `:417-421` | No | Constantes de `wp-config.php`, nunca en la base de datos |
| Si el perfil SMTP no valida, `wp_mail()` se corta antes de caer al `mail()` de PHP, y el corte deja rastro con causa y contador. | `mu-plugins/akibara-correo-smtp.php:102-114` | No | Sólo por código |
| El aviso de rechazos del servidor SMTP sólo se enseña a partir de **tres** seguidos; un corte del guard se grita desde el primero. | `mu-plugins/akibara-correo-smtp.php:45` y `:245-247` | No | Sólo por código |
| El rastro del corte se borra únicamente cuando un correo **sale** de verdad. | `mu-plugins/akibara-correo-smtp.php:57-70` y `:210-218` | No | Sólo por código |
| Si el fichero del transporte está presente pero no cargó, el centinela **no** lo pisa: avisa para que lo mire una persona. | `mu-plugins/akibara-mail-transport-watch.php:138-152` | No | Sólo por código |
| El historial de reposiciones del transporte guarda las 10 últimas y sobrevive a que el problema se cure. | `mu-plugins/akibara-mail-transport-watch.php:54` y `:232-248` | No | Sólo por código |
| Fuera de producción, todo correo a una dirección que no sea de un TLD reservado se redirige a la bandeja de captura, con el destinatario original en el asunto y en dos cabeceras. | `mu-plugins/akibara-correo-no-sale-a-clientes.php:104-140` | **Sí** | Constante o variable de entorno `AKIBARA_CORREO_CAPTURA` |
| La portada que recibe Brevo se resuelve a JPG **sólo** dentro de la ventana 9-11 de sus tres ganchos de carrito. | `mu-plugins/akibara-brevo-portada-jpg-en-el-carrito.php:92-128` | No | Sólo por código |
| Los sitemaps se declaran `no-store` para el navegador y cacheables 4 horas para el CDN. | `mu-plugins/akibara-discovery-warming.php:35-39` | No | Sólo por código; la Cache Rule de Cloudflare usa el mismo TTL |

---

## Datos que deja

Todo lo que este carril escribe en la base de datos o en el disco. Sin esta lista no se puede
diagnosticar el área por línea de comandos ni saber qué se lleva la desinstalación —que borra por
patrón `akb_` / `akibara_` con exclusiones para los plugins hermanos, `uninstall.php:84-104`.

### Options

| Clave | Quién la escribe | Qué guarda | ¿Tiene pantalla? |
| --- | --- | --- | --- |
| `akibara_llms_identity` | `LlmsManifest` | La frase con la que la tienda se presenta a los agentes de IA; máximo 600 caracteres, una línea | **Sí:** Panel Akibara → Salud SEO |
| `akibara_business_llms_manifest_last_success` | `LlmsManifest` | `{timestamp, series, guides, products, full_sha256, compact_sha256}` | Se **muestra** en Salud SEO |
| `akibara_business_llms_manifest_last_error` | `LlmsManifest` | `{timestamp, code, message}` | Se **muestra** en Salud SEO |
| `akibara_business_llms_manifest_lock` | `LlmsManifest` | Lease de 30 min: `{token, expires_at}` | No |
| `akibara_business_llms_manifest_managed_files` | `LlmsManifest` | Hashes de los ficheros que el módulo tiene derecho a retirar | No |
| `akb_social_derivatives_lock` | `SocialImageDerivatives:67` | Candado de corrida, TTL 900 s | No |
| `akb_social_derivatives_cursor` | `SocialImageDerivatives:73` | Nombre del último fichero procesado, para reanudar | No |
| `akb_adult_series_seeded` | `AdultContentFeed:62` | `'1'` cuando la semilla +18 ya se copió | No |
| `akb_pa_serie_renderer_state` | `SeriesSitemapLastmod:99` | `{version, modified_gmt}` de la versión del renderizador | No |
| `akb_pa_serie_sitemap_pending_changes` | `SeriesSitemapLastmod:100` | Cola durable de reintento; tope 2.048 ids | No |
| `akb_markdown_negotiation_enabled` | `MarkdownNegotiation:203` | `'yes'` / `'no'`. El default **del código** es `'no'`; **producción lo tiene en `'yes'`** desde el 2026-08-28 (`:176-177`) | **No — infringe la regla de oro**. Se lee con `wp option get akb_markdown_negotiation_enabled` |
| `akibara_mail_transport_outage` | mu `akibara-correo-smtp` | `{kind, reason, last_gmt, first_gmt, count}` | Se **muestra** como aviso rojo en el admin |
| `akibara_mail_transport_watch` | mu `akibara-mail-transport-watch` | `{missing_since, last_seen_gmt, outcome, restores[10]}` | Se **muestra** como aviso en el admin |
| `woocommerce_akb_bis_instock_settings` · `woocommerce_akb_preventa_ready_settings` · `woocommerce_akb_preventa_reminder_settings` · `woocommerce_akb_sf_series_settings` | `AkibaraEmail` (vía `WC_Settings_API`) | `enabled`, `subject`, `heading`, `additional_content`, `email_type` — y en la de `akb_sf_series`, además `subject_tanda` | **Sí:** WooCommerce → Ajustes → Correos (`/wp-admin/admin.php?page=wc-settings&tab=email`). En la de `akb_preventa_reminder`, lo guardado **sólo afecta al modo T-7** |
| `robots_txt_content` (de Rank Math) | El dueño, con permiso de `RankMathAdminUi` | El `robots.txt` virtual del sitio | **Sí:** Rank Math → Ajustes generales |

### Transients

| Clave | Quién lo escribe | Qué guarda | Vida |
| --- | --- | --- | --- |
| `akb_serie_medio_<term_id>` | `SeoEnhancements:149` | El medio resuelto de la serie (manga/cómic/manhwa). **El vacío nunca se cachea** | 36 h |
| `akb_blog_series_map` | `Blog:25` | Mapa nombre de serie → URL, ordenado por largo descendente. **Un vacío degradado no se cachea** | 24 h |
| `akb_og_fail_<slug>` | `SocialImageDerivatives:79` | Marca de conversión fallida, para no insistir sobre un AVIF corrupto | 6 h |

### Termmeta (datos pegados a una serie u otro atributo)

| Clave | Qué guarda |
| --- | --- |
| `rank_math_title` / `rank_math_description` | El título y la descripción SEO de la serie. Vacíos → se componen en cada petición |
| `_akibara_rank_math_title_ownership` / `_akibara_rank_math_description_ownership` | `generated_snapshot_v1` cuando la fila la escribió el generador |
| `_akibara_rank_math_title_snapshot_sha256` / `..._description_snapshot_sha256` | `sha256` del texto guardado; si no coincide, alguien lo editó y el campo queda congelado |
| `rank_math_robots` | Si el término pide `noindex`, ninguna política lo convierte en indexable |
| `_akb_serie_sitemap_lastmod_gmt` | Fecha UTC estable que publica el sitemap para esa serie |
| `_akb_serie_sitemap_fingerprint` | `sha256` de todo lo público y duradero de la serie |
| `_akb_adult_content` | `'1'` = la serie se declara adulta en el feed de Merchant |
| (`SeriesSchemaType::META_KEY`) | Marca explícita de si la serie admite semántica `ComicSeries` |

### Postmeta (datos pegados a un producto o entrada)

| Clave | Qué guarda |
| --- | --- |
| `_akb_eta_release_date` | Fecha de salida; se publica como `availabilityStarts` **sólo** si es preventa y la fecha es válida |
| `rank_math_robots` / `rank_math_description` | Indexabilidad y nota de productos y guías (las lee `LlmsManifest`) |
| `_thumbnail_id` | No lo escribe este carril, pero `SeriesSitemapLastmod` vigila sus cambios |

### Ficheros en disco

| Ruta | Quién lo escribe | Nota |
| --- | --- | --- |
| `<docroot>/llms.txt` y `<docroot>/llms-full.txt` | `LlmsManifest` | Físicos: el `.htaccess` los sirve antes del rewrite, así que ganan al handler de Rank Math. **Se borran al desactivar el plugin** |
| `wp-content/uploads/portadas/<slug>-og.jpg` | `SocialImageDerivatives` | Variante JPEG calidad 82, mismo tamaño que el original |
| `~/domains/akibara.cl/akb-runtime/akibara-correo-smtp.php` (hermano del docroot) | `ops/akibara-prod-muplugins-deploy.sh` (`:26`, `:95`), que además compara el hash con el del repo | Copia de rescate del transporte, **nunca servible por web**. Se verifica con `bash ops/akibara-prod-mail-health.sh` |
| `wp-config.php` (constantes) | El dueño / el despliegue | Seis del transporte: `AKIBARA_HOSTINGER_MAIL_ENABLED` (el interruptor maestro) y `AKIBARA_HOSTINGER_SMTP_HOST` / `_PORT` / `_ENCRYPTION` / `_USERNAME` / `_PASSWORD` (`akibara-correo-smtp.php:406-407` y `:417-421`); más `AKIBARA_CORREO_CAPTURA` para la bandeja del espejo. Las credenciales **no** están en la base de datos, a propósito |

### Tablas

Ninguna tabla propia. `SeriesSitemapLastmod` sí escribe `wp_options` con `INSERT`/`UPDATE`/`DELETE`
directos por SQL, porque la API de options no ofrece *compare-and-swap*.

---

## Glosario del capítulo

- **hook** — punto de enganche. WordPress, mientras arma una página o manda un correo, se detiene en
  sitios concretos y pregunta «¿alguien quiere añadir o cambiar algo aquí?». Hay dos clases:
  *acciones* (haz algo ahora) y *filtros* (te paso un valor, devuélveme el que quieras).
- **prioridad** — el número que decide el orden en que corren varios enganchados al mismo hook. Menor
  número, más temprano. Es la fuente de la mitad de las sorpresas de este capítulo.
- **option** — un ajuste guardado en la tabla `wp_options` de la base de datos.
- **transient** — una option con fecha de caducidad; se usa para cachear.
- **termmeta** — un dato pegado a una categoría o atributo. Aquí, casi siempre a una serie de manga.
- **postmeta** — lo mismo, pegado a un producto o a una entrada del blog.
- **mu-plugin** — fichero que WordPress carga **siempre**, antes que cualquier plugin, y que no se
  puede apagar desde el panel. «mu» es *must-use*.
- **cron** — el sistema de trabajos programados. En esta tienda no lo empuja el tráfico: el
  hospedaje dispara `wp-cron.php` cada 5 minutos.
- **Action Scheduler** — la cola de trabajos que trae WooCommerce; más robusta que el cron de
  WordPress para tareas encoladas.
- **sitemap** — el índice en XML que se le entrega a Google con las URLs del sitio y su fecha de
  última modificación.
- **canonical** — la etiqueta que le dice a Google cuál es la dirección «oficial» de una página,
  cuando la misma página se puede alcanzar por varias.
- **noindex** — instrucción para que un buscador no meta esa URL en su índice. `follow` significa
  que sí siga los enlaces que hay dentro.
- **faceta** — cada filtro del catálogo (precio, editorial, género…). Una combinación de facetas
  produce una URL con parámetros.
- **datos estructurados / JSON-LD / `@graph`** — un bloque invisible de datos que la página lleva
  para que Google entienda qué es (producto, libro, precio, autor) sin adivinarlo del texto.
- **`@id`** — el identificador de un nodo dentro de esos datos. Dos nodos son el **mismo** cuando
  comparten `@id`, no cuando comparten la URL.
- **schema.org** — el vocabulario común de esos datos: `Product`, `Book`, `Offer`, `ComicSeries`…
- **`PreOrder` / `BackOrder`** — dos valores de disponibilidad de schema.org. El primero es «aún no
  ha salido»; el segundo, «existe pero hay que traerlo».
- **Open Graph / `og:image`** — las etiquetas que WhatsApp, Facebook y X leen para armar la tarjeta
  de vista previa de un enlace. `og:image` es la imagen de esa tarjeta.
- **AVIF** — un formato de imagen moderno y liviano, que WhatsApp, X y Gmail **no** saben pintar.
  De ahí la hermana JPG.
- **negociación de contenido** — el mecanismo por el que la **misma** dirección devuelve formatos
  distintos según lo que pida el cliente en la cabecera `Accept`.
- **cabecera** — un dato invisible que el navegador o el robot manda junto con la petición, o que el
  servidor manda junto con la respuesta.
- **`q-value`** — el peso (`;q=0.8`) con que un cliente dice cuánto prefiere cada formato en la
  cabecera `Accept`.
- **`Vary: Accept`** — cabecera de respuesta que avisa a las cachés de que el contenido cambia según
  lo que el cliente pidió. Cloudflare no la respeta: sólo varía por `Accept-Encoding`.
- **`llms.txt`** — un fichero de texto en la raíz del sitio, según la convención de llmstxt.org, que
  le cuenta a un modelo de lenguaje qué es el sitio y qué hay en él.
- **`multipart`** — un correo que lleva las **dos** versiones a la vez, HTML y texto plano, para que
  cada cliente de correo use la que sepa pintar.
- **`List-Unsubscribe` / RFC 8058** — las cabeceras que hacen aparecer el botón «Cancelar
  suscripción» arriba del correo en Gmail. Son obligatorias para un remitente masivo.
- **SMTP** — el protocolo con el que se envía correo. `smtp.hostinger.com` es el servidor que
  realmente lo manda.
- **PHPMailer** — la librería que WordPress usa por dentro para hablar SMTP.
- **Emogrifier** — la librería que WooCommerce usa para meter el CSS **dentro** de cada etiqueta del
  correo, porque los clientes de correo ignoran las hojas de estilo externas.
- **`-webkit-text-fill-color`** — propiedad de Safari y Chrome que pinta el **texto** (no el fondo) y
  que **le gana a `color`**. Si sólo se cambia `color`, WebKit sigue pintando el valor viejo. Es la
  causa del «texto rojo sobre fondo rojo» de los botones de correo.
- **`wp_kses_post()`** — el filtro de WordPress que limpia HTML dejando sólo lo que considera seguro.
  Entre otras cosas **borra** de los atributos `style` cualquier propiedad que no esté en su lista
  blanca (`safecss_filter_attr()`), y `-webkit-text-fill-color` no lo está.
- **`Link: rel="alternate"`** — cabecera de respuesta (RFC 8288) con la que una página anuncia que
  existe otra versión de sí misma. Aquí es como un agente descubre la variante Markdown sin tener
  que negociarla.
- **`nonce`** — un código de un solo uso que acompaña a una acción del admin para probar que la pidió
  de verdad quien dice pedirla.
- **capability** — el permiso concreto que WordPress exige para una acción. Aquí aparecen
  `manage_product_terms` (atributos de producto) y `manage_options`.
- **compare-and-swap (CAS)** — escribir un valor **sólo si** el valor anterior era el que esperabas.
  Es lo que evita que dos procesos se pisen; la API de options de WordPress no lo ofrece, de ahí el
  SQL a mano.
- **lease** — un candado con caducidad: si quien lo tomó muere, el candado se suelta solo.
- **idempotente** — que hacerlo dos veces da el mismo resultado que hacerlo una. La conversión de
  portadas lo es; el cursor que la acompaña, no.
- **fail-closed / fail-open** — dos políticas ante una lectura dudosa. *Fail-closed* aborta y conserva
  lo anterior (lo que hace `LlmsManifest`); *fail-open* sigue adelante conservando la entrada (lo que
  hace `SeriesSitemapLastmod` con el sitemap).
- **`sha256` / huella / fingerprint** — un resumen corto y único de un texto. Si el texto cambia una
  coma, el resumen cambia entero. Así se detecta si alguien editó a mano.
- **Rank Math** — el plugin de terceros que es el dueño oficial del SEO del sitio.
- **LiteSpeed Cache** — el plugin de terceros que guarda páginas ya armadas para servirlas más
  rápido. Si no se le avisa, sirve la versión vieja.
- **Cloudflare** — la red que está delante del sitio y guarda copias de lo que sirve.
- **Brevo** — el servicio externo de correo de marketing. Su plugin es de terceros; lo único propio
  es un mu-plugin de 100 líneas.
- **Merchant / Google Listings & Ads** — el catálogo de productos que Google usa para Shopping, y el
  plugin de terceros que se lo envía.
- **Mailpit** — el buzón falso del entorno local, donde QA lee los correos sin que salgan a nadie.
- **el espejo** — la copia local de la tienda, en Docker, donde se prueba antes de tocar producción.
- **snapshot** — la copia congelada de lo que está publicado en producción. Es la fuente de verdad de
  este capítulo.
