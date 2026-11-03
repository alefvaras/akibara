# Crítica de lector novato · `07-business-seo-ia-blog-y-correo.md`

**Quién escribe.** Primer día en el proyecto. No sé qué es WordPress, ni WooCommerce, ni el
negocio. Leí el capítulo entero (1.448 líneas), abrí los siete `.mmd`, los compilé con `mmdc`, y
cada vez que algo me sonó raro fui al snapshot congelado
(`scratchpad/snapshot-prod/`) a mirar el fichero. Todas las citas `fichero:línea` de abajo son de
ese snapshot, no del árbol de trabajo.

**Resumen.** 10 puntos de «no entendí», 12 de «falta para operar», 7 contradicciones con el código,
5 desvíos respecto de `verificacion.json` y 5 observaciones sobre los diagramas (los 7 compilan).

**Veredicto corto.** Es un capítulo bueno y honesto: dice los defectos en vez de taparlos, y las
correcciones que le hace al mapa de lectura son correctas —las verifiqué una por una—. Lo que le
falta es casi todo de un mismo tipo: **el capítulo explica muy bien por qué las cosas son así y
muy mal cómo se tocan**. Hay diez piezas que nombra sin decir dónde viven, seis constantes de
producción con el nombre equivocado o abreviado, cero comandos de diagnóstico, y una pieza del
código —la que emite la entidad de serie— que el propio capítulo necesita y no presenta.

---

## 1 · Lo que NO entendí

### 1.1 Tres autoridades que aparecen cinco veces y nunca se presentan

El capítulo usa `SeriesCatalogEligibility` (líneas 64, 339, 959), `SeriesIndex` (608, 1194) y
`SeriesFacts` (976) como si yo supiera qué son. No traen ruta, ni capítulo, ni una frase de qué
deciden.

> «`SeriesPaginationPolicy` … le pregunta a `SeriesCatalogEligibility` si la serie tiene productos
> publicados y visibles» (línea 959)

**Qué debería decir.** La primera vez que aparece cada una, una línea con ruta y remisión:

- `SeriesCatalogEligibility` → `src/Modules/SeriesCatalog/SeriesCatalogEligibility.php`. Decide si
  una serie tiene catálogo público de verdad (capítulo 03).
- `SeriesIndex` → `src/Modules/SeriesDirectory/SeriesIndex.php`. La lista de series que pinta el
  directorio (capítulo 03).
- `SeriesFacts` → `src/Modules/SeriesFacts/SeriesFacts.php`. Tomos, autoría, editorial y precio
  mínimo de una serie (capítulo 03).

Lo mismo con `Availability`, `StockStatus`, `PreventaItems`, `PublicationStatus`,
`BackInStock\NotificationDispatcher` y `DigestRunner`, que aparecen en el texto y en los diagramas
sin ruta.

### 1.2 La etapa 91 de `SchemaGraph` no tiene dueño en el capítulo

> «Constantes de etapa: **90** ofertas de producto, **91** colección de serie, **92** nodos de
> página…» (pieza 18)

Las etapas 90, 92, 99, 100 y 999 tienen dueño nombrado en el capítulo. La **91 no**. Y sí existe:
es `src/Modules/SeriesSchema/SeriesSchema.php`, que se engancha a `rank_math/json_ld` en 91 y es
**quien publica el nodo `ComicSeries` de la landing**. Esto no es un detalle: la pieza 11 dice

> «`isPartOf` un `ComicSeries` con el **mismo `@id`** que declara la landing (`<url>#serie`…)»

y el capítulo nunca dice quién declara ese `@id`. Para mí es un puntero al vacío. (Está en el
capítulo 03, sección «`SeriesSchema` + `SeriesSchemaType`», pero el 07 no lo remite.)

**Qué debería decir.** En la pieza 18, junto a la etapa 91: «la ocupa `SeriesSchema`
(`src/Modules/SeriesSchema/SeriesSchema.php`), que publica el `ComicSeries` de la landing al que
apunta `BookSchemaEnricher` — ver capítulo 03».

### 1.3 «Relleno blanco» se lee como fondo, y es lo contrario

> «Un botón que sólo trae `color:#ffffff` gana el color pero no el relleno: WebKit … pintaba texto
> rojo sobre fondo rojo» (pieza 22)

En castellano «relleno» es el fondo. Aquí «relleno» traduce `-webkit-text-fill-color`, que es el
color **del texto**, no del fondo. La frase, tal cual, dice que el botón gana el color pero no el
color. Tardé tres lecturas y una mirada a
`src/Services/EmailButton.php:33-35` para entenderlo.

**Qué debería decir.** «…gana la propiedad `color` pero no `-webkit-text-fill-color`, que es la
que WebKit usa de verdad para pintar el texto» + una línea en el glosario:
«**`-webkit-text-fill-color`** — propiedad de Safari/Chrome que pinta el texto y le gana a `color`;
si sólo se cambia `color`, WebKit sigue pintando el valor viejo».

### 1.4 `wp_kses_post()` sin dueño ni motivo

> «y la misma regla con `!important` desde la hoja, que es la única capa que sobrevive a
> `wp_kses_post()`» (pieza 22)

¿Quién llama a `wp_kses_post()`? ¿Por qué mata lo inline y no la hoja? El código sí lo explica
(`src/Modules/EmailBrand/EmailBrand.php:18-22`): lo aplica `WC_Emails::wrap_message()` **a los
correos de `akibara-rewards`**, y desnuda cualquier `-webkit-text-fill-color` inline porque no está
en la lista blanca de `safecss_filter_attr()`.

**Qué debería decir.** Exactamente eso, con el fichero: sin el «quién» y el «a cuáles», la frase es
un conjuro.

### 1.5 Dos totales de portadas que no cuadran y nadie fecha

- Pieza 8: «las 12.644 portadas existen **sólo** en AVIF».
- Pieza 9 y el diagrama de estados: «1.699 hermanas escritas de unas 14.900 portadas».

¿Son 12.644 o 14.900? El código lo aclara y el capítulo se lo comió: son **dos mediciones de fechas
distintas** —`SocialImageDerivatives.php:5` dice «Medido 2026-08-15: las 12.644 portadas» y
`:327-328` dice «Medido el 2026-09-11: 1.699 hermanas … de unas 14.900»—.

**Qué debería decir.** Llevar la fecha pegada al número las dos veces, como hace el resto del
capítulo con «el 2026-09-02 se midió…».

### 1.6 La aritmética del Alcance no cierra

> «Este capítulo cubre 30 piezas: 18 módulos y servicios del plugin `akibara-business`, las 8
> plantillas de correo, y 6 mu-plugins» (cabecera)

18 + 8 + 6 = 32, no 30. Y las piezas numeradas de módulo/servicio son **23** (de la 1
`SeoEnhancements` a la 23 `EmailButton`), no 18; las 8 plantillas ocupan **una** pieza numerada (la
24) y los mu-plugins son las 25-30.

**Qué debería decir.** «30 secciones numeradas: 23 módulos y servicios, una sección para las 8
plantillas de correo (4 correos × 2 versiones) y 6 mu-plugins».

### 1.7 El orden invertido de `wp_robots` exige memoria de tres módulos

La corrección de la pieza 4 es correcta (la verifiqué: `FilteredUrlsPolicy.php:45` engancha
`wp_robots` sin prioridad, `SeriesPaginationPolicy.php:59` en 98 y
`SeriesDirectorySearchPolicy.php:33` en 99). Pero para seguirla hay que tener las seis prioridades
en la cabeza a la vez, repartidas entre tres secciones distintas del capítulo.

**Qué debería decir.** Una tabla de tres filas × dos columnas en la pieza 3 o 4:

| Módulo | `rank_math/frontend/robots` | `wp_robots` (nativo) |
| --- | --- | --- |
| `FilteredUrlsPolicy` | 99 | **10** (sin prioridad) |
| `SeriesPaginationPolicy` | 98 | **98** |
| `SeriesDirectorySearchPolicy` | 99 | 99 |

…y debajo la frase que ya está: «en Rank Math la de series corre antes; en el nativo corre
después, y lo único que lo salva es el guard `! empty( $_GET )`».

### 1.8 «100 por corrida» y «100 al día» sin la consecuencia en días

El capítulo dice tres veces que el tope real son 100 portadas al día, y dos veces que faltan ~13.200
hermanas. No hace la resta: **son unos 132 días**. Es el dato que convierte un defecto técnico en un
hecho de negocio, y el capítulo lo deja al lector.

### 1.9 «El 78 % del tráfico es móvil chileno» (pieza 8) no dice de dónde sale

El resto del capítulo fecha y atribuye cada número. Éste no. Si viene de Clarity o de GSC, decirlo.

### 1.10 La pantalla de ajustes de correo: nunca se dicen las cuatro claves

> «`init_form_fields()` arma la pantalla iterando un esqueleto **fijo** de cuatro claves» (pieza 21)

¿Cuáles cuatro? Hay que llegar a la tabla de options, 400 líneas más abajo, para deducir que son
`enabled`, `subject`, `heading`, `additional_content`.

---

## 2 · Lo que FALTA para operar o depurar esto sin ayuda

### 2.1 Las constantes SMTP están mal nombradas — el punto más grave de esta sección

> «`wp-config.php` (constantes) … `AKIBARA_HOSTINGER_MAIL_*` y `AKIBARA_CORREO_CAPTURA`»
> (tabla «Ficheros en disco»)

Ese comodín cubre **una** de las seis constantes reales. En
`mu-plugins/akibara-correo-smtp.php:406-421` las constantes son:

| Constante | Qué es |
| --- | --- |
| `AKIBARA_HOSTINGER_MAIL_ENABLED` | **Interruptor maestro.** Si no es `true`, el transporte no se activa (`:406-407`) |
| `AKIBARA_HOSTINGER_SMTP_HOST` | default `smtp.hostinger.com` (`:417`) |
| `AKIBARA_HOSTINGER_SMTP_PORT` | default `465` (`:418`) |
| `AKIBARA_HOSTINGER_SMTP_ENCRYPTION` | default `ssl` (`:419`) |
| `AKIBARA_HOSTINGER_SMTP_USERNAME` | sin default (`:420`) |
| `AKIBARA_HOSTINGER_SMTP_PASSWORD` | sin default (`:421`) |

Cinco de las seis empiezan por `AKIBARA_HOSTINGER_SMTP_`, no por `AKIBARA_HOSTINGER_MAIL_`. Quien
siga el capítulo para reconstruir un `wp-config.php` deja la tienda muda —que es exactamente el
incidente de tres días que el capítulo cuenta en la pieza 25—. Y el **interruptor maestro**
`AKIBARA_HOSTINGER_MAIL_ENABLED` no se menciona en ninguna parte del capítulo, aunque es lo
primero que hay que mirar si el correo no sale.

### 2.2 Ninguna pantalla trae su URL

El capítulo dice «Panel Akibara → Salud SEO», «Productos → Atributos → Serie», «WooCommerce →
Ajustes → Correos», «Rank Math → Ajustes generales». Ninguna trae la URL. Para la primera hay
además una trampa conocida del proyecto: el slug es `akibara-panel` y el slug corto da 403.

**Qué debería decir.** Junto a cada «Dónde lo opera el dueño»:

- Salud SEO → `/wp-admin/admin.php?page=akibara-panel&tab=salud_seo`
  (`LlmsManifest::PANEL_TAB_ID = 'salud_seo'`, `LlmsManifest.php:72`)
- Serie → `/wp-admin/edit-tags.php?taxonomy=pa_serie&post_type=product`
- Correos → `/wp-admin/admin.php?page=wc-settings&tab=email`

### 2.3 No hay tabla que una los cuatro correos con sus identificadores

La pieza 21 nombra las cuatro clases; la tabla de options nombra las cuatro claves; los casos
nombran los asuntos en prosa. Nada las cruza. Para depurar «no llegó el correo X» hace falta esto
en una sola tabla:

| Correo | Clase | `EMAIL_ID` | Option | Asunto por defecto | Plantillas |
| --- | --- | --- | --- | --- | --- |
| Volvió a stock | `BackInStock\InstockEmail` | `akb_bis_instock` (`:36`) | `woocommerce_akb_bis_instock_settings` | `{product_name} está de vuelta en Akibara` (`:59`) | `customer-bis-instock.php` + `plain/` |
| Ya llegó | `OrderPreventa\ReadyToShipEmail` | `akb_preventa_ready` (`:57`) | `..._akb_preventa_ready_settings` | `Tu pedido {order_number} ya llegó a Akibara` (`:82`) | `customer-preventa-ready.php` + `plain/` |
| Se acerca / cambió la fecha | `OrderPreventa\ReminderEmail` | `akb_preventa_reminder` (`:70`) | `..._akb_preventa_reminder_settings` | depende del modo, `match` en `:163-168` (`SUBJECT_DATE_CHANGED_PREVENTA` / `..._PEDIDO` / …) | `customer-preventa-reminder.php` + `plain/` |
| Tomo nuevo de tu serie | `SeriesFollow\SeriesFollowEmail` | `akb_sf_series` (`:42`) | `..._akb_sf_series_settings` | `Salió el tomo {tomo} de {serie}{y_mas}` (`:77`), **más** `subject_tanda` (ver 2.4) | `customer-series-follow.php` + `plain/` |

La tercera fila esconde el hallazgo del punto siguiente.

### 2.3 bis · Tres de los cuatro asuntos de preventa **no** se pueden editar por pantalla

> «**Dónde lo opera el dueño:** WooCommerce → Ajustes → Correos, cuatro filas con activar, asunto,
> encabezado, contenido adicional y tipo de email.» (pieza 21)
>
> «**Correo:** «Actualización de tu preventa *N*: nueva fecha» o «Actualización de tu pedido *N*:
> cambio de fecha».» (Caso 4, paso 2)

`ReminderEmail` tiene **tres modos** (`t7`, `date_changed`, `date_announced`) y una sola fila en la
pantalla. Y `get_subject()` / `get_heading()` (`ReminderEmail.php:272-284` y `:291-303`) devuelven
`parent::get_subject()` —o sea, lo que el dueño escribió— **sólo cuando el modo es `t7`**. En los
otros dos modos devuelven el literal de la constante pasado por el filtro de código
`woocommerce_email_subject_akb_preventa_reminder`, ignorando lo guardado.

O sea: de los cuatro textos que el Caso 4 enumera, **el dueño sólo puede cambiar uno por pantalla**
(«Tu pedido {order_number} se acerca»). Los otros tres viven en constantes
(`ReminderEmail.php:193-196`) y sólo se tocan por código. El motivo está escrito y es bueno —un
asunto personalizado para el T-7 salía también en los correos de cambio de fecha—, pero el capítulo
no lo cuenta.

La pantalla **sí** lo advierte («Los modos "cambio de fecha" y "fecha anunciada" usan su texto
propio», `:704` y `:709`), así que no es una option invisible; es el capítulo el que no lo dice, y es
justo el capítulo el que promete decir qué se puede tocar y dónde.

**Qué debería decir.** En la pieza 21 y en el Caso 4: «El recordatorio de preventa tiene tres modos
y una sola fila de ajustes: el asunto y el encabezado editables valen **sólo para el aviso T-7**.
Los textos de «cambió la fecha» y «la editorial anunció fecha» son constantes
(`ReminderEmail.php:193-196`) y sólo se cambian por código, con el filtro
`woocommerce_email_subject_akb_preventa_reminder`.»

### 2.4 El quinto campo existe hoy y el capítulo no lo dice

> «cuatro filas con activar, asunto, encabezado, contenido adicional y tipo de email» (pieza 21)

Falso para uno de los cuatro. `SeriesFollowEmail::extra_form_fields()`
(`src/Modules/SeriesFollow/SeriesFollowEmail.php:155-166`) añade un **quinto** campo visible,
`subject_tanda` («Asunto cuando salieron varios tomos»), con `akb_after => 'subject'`. Es el único
usuario real del mecanismo que la pieza 21 describe en abstracto, y el capítulo no lo conecta.

Peor: el capítulo sólo nombra **un** marcador editable en todo el carril (`{n_tomos}`) y otro de
paso (`{product_name}`). El campo `subject_tanda` documenta **seis**: `{serie}`, `{y_mas}`,
`{n_tomos}`, `{tomos}`, `{tomo}`, `{site_title}` (`:163`), con reglas de negocio dentro
(«`{n_tomos}` son los tomos de esa serie que van en ESTE correo para ESE cliente: si ya compró
alguno, a él le saldrá un número menor») y una limitación operativa que nadie más dice: **ese asunto
no se puede previsualizar**.

**Qué debería decir.** Una tabla de marcadores por correo, y en la pieza 21: «hoy el mecanismo
`akb_after` lo usa exactamente un correo: el de novedades de serie, para su segundo asunto».

### 2.5 Cero comandos de diagnóstico

El capítulo tiene **un** comando en 1.448 líneas: el `wp option update
akb_markdown_negotiation_enabled yes`. Para un carril cuyo modo de fallo característico es
«no da error, simplemente dice algo que no es», falta el bloque de comprobación:

```bash
ssh akibara
wp cron event list --fields=hook,next_run_relative | grep akibara_business_
wp option get akibara_business_llms_manifest_last_success --format=json
wp option get akibara_business_llms_manifest_last_error   --format=json
wp option get akibara_mail_transport_outage --format=json
wp option get akibara_mail_transport_watch  --format=json
wp option get akb_markdown_negotiation_enabled
ls -1 wp-content/uploads/portadas/*-og.jpg | wc -l   # hermanas escritas hoy
curl -sI -A AkibaraQA https://akibara.cl/llms.txt | head -3
```

### 2.6 «+1 h / +2 h de la activación» no se puede convertir en una hora

La tabla de «Lo programado» es honesta al decir que la hora depende de cuándo se activó el plugin.
Pero entonces **hay que dar la forma de averiguar la hora de hoy**, que es `wp cron event list`.
Sin eso, la columna «Frecuencia y hora Chile» no sirve para nada operativo.

### 2.7 La ruta de rescate del transporte es un acertijo

> «`<hermano del docroot>/akb-runtime/akibara-correo-smtp.php` … Copia de rescate del transporte»

El código sólo declara el nombre del directorio
(`mu-plugins/akibara-mail-transport-watch.php:51`, `BACKUP_DIR = 'akb-runtime'`). El capítulo no da
la ruta absoluta de producción, no dice **quién** la deposita ahí («El despliegue», dice la tabla —
¿qué script de `ops/`?), ni cómo comprobar que sigue estando. Es la red de seguridad de un incidente
de tres días sin correo: tiene que ser verificable en un comando.

### 2.8 No hay forma de saber si la negociación Markdown está encendida hoy

El capítulo dice dos cosas distintas en dos sitios:

- Pieza 15: «por eso el interruptor viene apagado por defecto», y el Caso 5 desarrolla la
  «Bifurcación A — el interruptor está apagado» como si fuera el estado vivo.
- Tabla de options: «`'yes'` / `'no'` (default `'no'`; **producción lo tiene en `'yes'`**)».

El código zanja: `MarkdownNegotiation.php:176-177` dice «El default del código es «apagado»;
producción lo tiene **ENCENDIDO** desde que se arregló el borde». O sea que el Caso 5 desarrolla
como caso principal el escenario que **no** es el de producción.

**Qué debería decir.** En la pieza 15, en negrita y con fecha: «En producción está ENCENDIDO
(`akb_markdown_negotiation_enabled = yes`); el default del código es apagado. Compruébalo con
`wp option get akb_markdown_negotiation_enabled`». Y el Caso 5 debería empezar por la rama viva.

### 2.9 «La regla de borde de Cloudflare» se cita cuatro veces y nunca se identifica

Aparece en la tabla «Con quién habla», en la pieza 15, en el Caso 5 y en la tabla de reglas. Nunca
se dice cuál es, dónde se ve, cómo se llama, ni cómo se comprueba que sigue puesta. Es la única cosa
que impide repetir el incidente del 2026-08-28 (Chrome recibiendo Markdown con
`cf-cache-status: HIT`) y está fuera de este código.

**Qué debería decir.** Nombre y número de la Cache Rule, y la comprobación:
`curl -sI -H 'Accept: text/markdown' https://akibara.cl/<ficha>/ | grep -i 'cf-cache-status\|content-type'`.

### 2.10 Piezas del snapshot que el capítulo no menciona (comparando con el `ls`)

Comparé `ls -R` del snapshot con las 30 piezas. Además de `SeriesSchema` (punto 1.2), faltan tres
utilidades que este carril usa de verdad:

| Fichero del snapshot | Quién de este capítulo lo usa | Por qué importa |
| --- | --- | --- |
| `src/Utilities/RobotsMeta.php` | `SeriesPaginationPolicy`, `SeriesSitemapLastmod`, `LlmsManifest` | Es **la** lectura de `rank_math_robots` que el capítulo atribuye tres veces a los módulos («lee el `rank_math_robots` del término»). Su semántica de fallo decide la bifurcación B del Caso 1 |
| `src/Utilities/Text.php` | `SeoEnhancements` (`Text::length` en el corte de 62), `SchemaProductRankMath` | La regla estrella del capítulo —«62 caracteres»— se mide con esta clase, no con `strlen` |
| `src/Services/VolumeTitle.php` | `SeoEnhancements`, `BookSchemaEnricher` | Es quien extrae el nº de tomo del título; el capítulo dice «`position` = número de tomo extraído del título» sin decir quién lo extrae ni con qué regla |

### 2.11 Falta decir con qué cron sale el correo de vuelta a stock

El Caso 3 dice «con la cola, 2-3 minutos después de reponer». El nombre del gatillo
(`akb_bis_notify_product`) aparece **sólo dentro del diagrama**, nunca en el texto. Quien quiera
forzarlo en el espejo no lo encuentra leyendo.

---

## 3 · Lo que CONTRADICE al código

### 3.1 «Único punto de contacto con el feed de Google Merchant» — falso, y la colisión importa

> «**Google Listings & Ads** (tercero) | Único punto de contacto con el feed de Google Merchant: el
> filtro `woocommerce_gla_product_attribute_values`.» (tabla «Con quién habla»)

Hay **dos** módulos de la casa enganchados a ese mismo filtro, los dos en prioridad 10:

- `src/Modules/ProductSchemaShopping/AdultContentFeed.php:82` → `declare_adult_attribute`, 10, 2 args
- `src/Modules/EstimatedDelivery/GlaBridge.php:55-60` → `inject_availability_date`, 10, 3 args

Y hay un tercer punto de contacto con el mismo tercero en otro filtro:
`src/Modules/FrontendCompatibility/FrontendCompatibility.php:62` →
`woocommerce_gla_disable_gtag_tracking`.

**Por qué importa y no es un detalle.** Dos callbacks en el mismo filtro y la misma prioridad
corren en orden de registro —o sea, en el orden en que `Plugin.php` instancia los módulos—, los dos
mutando el mismo array. Es exactamente el defecto de «orden decidido por instanciación» que el
propio código documenta como cicatriz en `SeriesSchema.php:41-45`. El capítulo, al declarar
exclusividad, **cierra la puerta a que alguien lo mire**.

**Qué debería decir.** «Este carril entra al feed por `woocommerce_gla_product_attribute_values`
(prioridad 10). **No está solo en ese filtro**: `EstimatedDelivery\GlaBridge` escribe
`availabilityDate` en el mismo hook y la misma prioridad, así que el orden lo decide la
instanciación en `Plugin.php` — ver capítulo 04.»

### 3.2 «Gana siempre la fecha sintética» — sólo cuando no hay oferta con fin futuro

> «Este módulo la repone con una ventana sintética de 30 días (`:223-225`) … **Gana siempre la fecha
> sintética.**» (pieza 12, sospecha S5)

Las dos mitades llevan condicional y el capítulo borra las dos:

- `SchemaProductRankMath.php:521-524`: `if ( ! $has_future_sale_end ) { unset( $offer['priceValidUntil'] ); }`
  — el borrado **no** ocurre si WooCommerce tiene un fin de oferta futuro.
- `ProductSchemaShopping.php:223-225`: `if ( ! isset( $offer['priceValidUntil'] ) ) { … }`
  — la fecha sintética **sólo** se escribe si el campo quedó ausente.

O sea: para un producto rebajado con fecha de fin de oferta futura, **sobrevive la fecha real de
WooCommerce**, no la sintética. La contradicción entre módulos existe, pero cubre el caso «sin fin
de oferta futuro», no todos.

**Qué debería decir.** «Para un producto sin fin de oferta futuro, el hermano borra la fecha y éste
escribe una sintética a 30 días: gana la sintética. Si la oferta sí tiene fin futuro, el hermano
no borra nada y la fecha real sobrevive. La decisión pendiente del dueño es sobre el primer caso,
que son la mayoría.»

### 3.3 «Un solo remitente, **sin excepciones**» — la prioridad 15 no es la última palabra

> «`MailFrom` — un solo remitente, sin excepciones … `wp_mail_from` y `wp_mail_from_name` en
> prioridad 15, **ignorando incondicionalmente** el valor entrante» (pieza 19)
>
> «**Todo** correo del sitio sale como `Akibara <contacto@akibara.cl>`» (tabla de reglas)

«Ignora incondicionalmente lo que le llega» es cierto. «Sin excepciones» no: el propio código
declara que 15 se eligió para dejar sitio a los overrides posteriores —
`src/Modules/MailFrom/MailFrom.php:48-49`: «Prioridad alta (15) para correr DESPUÉS del default de
WP core (10), pero **ANTES de plugins que quieran override puntual (20+)**». Cualquier plugin de
terceros enganchado en 20 o más gana. Es justo el escenario que importa (Brevo, un plugin de
formularios) y el capítulo afirma lo contrario.

**Qué debería decir.** «`MailFrom` ignora el valor entrante, pero corre en 15 a propósito: un
plugin enganchado en 20 o más todavía puede pisarlo. Hoy ninguno lo hace; si el remitente cambia
sin explicación, eso es lo primero que hay que buscar.»

### 3.4 La cita de `OFRECIDOS` apunta al fichero equivocado

> «**### 16. `AcceptParser`** … `src/Modules/AgentReadiness/AcceptParser.php` … y **HTML va
> primero** en `OFRECIDOS` (`:63`)» (pieza 16)

Dentro de la pieza de `AcceptParser`, `:63` se lee como `AcceptParser.php:63`. Pero `OFRECIDOS`
vive en **`MarkdownNegotiation.php:63`** (`private const OFRECIDOS = array( self::TIPO_HTML,
self::TIPO_MARKDOWN );`). En `AcceptParser.php` no existe esa constante. La tabla de reglas de
negocio, 700 líneas más abajo, sí cita bien `MarkdownNegotiation.php:63`: el capítulo se contradice
consigo mismo.

**Qué debería decir.** «…y **HTML va primero** en el array que le pasa quien lo llama
(`MarkdownNegotiation.php:63`, `OFRECIDOS`)». Además vale la pena explicitar que `AcceptParser` no
decide el desempate por sí solo: lo decide el **orden del array que recibe**.

### 3.5 «Ni su asunto ni su plantilla viven en este código» — pero sí vive código propio de Brevo

> «es **todo** lo propio que existe alrededor de Brevo» (pieza 28)

Hay al menos un segundo fichero first-party dedicado a Brevo:
`src/Modules/MarketingConsent/MarketingConsent.php`, cuyo propio docblock (`:9`, `:50`, `:126`)
habla de Brevo y de «la casilla del plugin de Brevo». El capítulo 07 no lo menciona, ni siquiera
para remitirlo a su capítulo.

**Qué debería decir.** «Es todo lo propio **de este carril** alrededor de Brevo. Fuera de él existe
`MarketingConsent` (capítulo 05), que normaliza la casilla de consentimiento del plugin de Brevo.»

### 3.6 `is_sitemap_path()` se declara código muerto sin decir lo que el código dice de sí mismo

> «`is_sitemap_path()` (`:552-555`) existe y **no la llama nadie** … Infringe la regla de oro «cero
> código muerto»» (pieza 1)

Verifiqué que efectivamente nadie la llama en el snapshot. Pero su docblock
(`SeoEnhancements.php:550`) dice `@internal Función pura expuesta para pruebas.` Eso cambia el
diagnóstico: o hay una prueba que la usa (y no está en el snapshot publicado, que no lleva `tests/`),
o la prueba se borró y quedó el huérfano.

**Qué debería decir.** Añadir la mitad que falta: «…y su docblock la declara «expuesta para
pruebas», pero el snapshot publicado no lleva `tests/`: o la prueba vive sólo en el árbol de
trabajo, o se borró y quedó el huérfano. Hay que mirar el árbol antes de borrarla.»

### 3.7 Precisión menor: `MailFrom.php:44-51` no es `register()`

La tabla de reglas cita `MailFrom.php:44-51` para el remitente forzado. `register()` está en
`:47-52`. El rango cubre las constantes y casi todo `register()`; sin ser falso, es impreciso en un
capítulo que presume de `fichero:línea`. Lo mismo con `akibara-business.php:249-254`: los seis
`wp_clear_scheduled_hook` están en `:248-253`.

---

## 4 · Lo que contradice o se aparta de `verificacion.json` del área

Fuente: `scratchpad/mapas/biz-seo-correo/verificacion.json` (9 omisiones, 5 errores, 9 sospechas
nuevas, 2 refutadas, 15 confirmadas, cobertura 82 %).

**Lo bueno primero:** el capítulo incorpora bien los 5 errores y 8 de las 9 sospechas nuevas, y las
tres correcciones explícitas al mapa (compose_series_meta, orden de `wp_robots`, texto plano
mutilado) coinciden con lo que dice `verificacion.json` **y con el código**. Eso lo comprobé línea
a línea. Lo que sigue son los huecos.

### 4.1 La sospecha nueva nº 7 se perdió, y el capítulo publica como regla lo que ella refuta

`verificacion.json` → `sospechas_nuevas[7]`: «El anclaje de la descripción paginada de serie busca
una frase que el generador vigente ya no escribe» (`SeoEnhancements.php:2337-2352`).

El capítulo publica justamente la regla afectada, sin la salvedad:

> «si la descripción se pasa de 160, se conservan la primera frase y la última y se descarta la
> intermedia (`compact_paginated_series_description()`, `:2337-2352`)» (Caso 1, paso 5)

Lo verifiqué en el fuente: la rama principal busca `strrpos( $base, 'Revisa ' )` (`:2340`) y
**ninguna variante que genera el código de hoy contiene «Revisa»** — los cierres vigentes son
«Cómprala en Akibara con despacho a todo Chile.», «Desde %s en Akibara…», «Despacho a todo Chile.»
y «en Akibara Chile.». La regla se cumple, pero la cumple el `preg_split` de respaldo (`:2346`),
no el anclaje. La rama anclada sólo corre sobre las filas viejas guardadas en `termmeta` — el mismo
residuo que `SERIES_META_RETIRED_CLOSINGS` documenta que hay que marcar, sin marcar.

**Qué debería decir.** Un aviso como los otros del capítulo: «La rama anclada busca la palabra
«Revisa», que el generador vigente ya no escribe: para todo lo que se compone hoy manda el respaldo
por frases. Es residuo de una plantilla retirada, y está sin marcar.»

### 4.2 S12 (confirmada por lectura) no llegó al capítulo

`verificacion.json` → `sospechas_confirmadas_por_lectura[9]`: «S12 · El comentario dice 12 h y la
constante dice 36 h».

El capítulo publica el 36 h dos veces («Cachea el «medio» … durante 36 h», y la regla «El medio
resuelto de una serie se cachea 36 h») y **nunca dice que el fichero se contradice a sí mismo**.
Verificado: `SeoEnhancements.php:159` declara `SERIES_MEDIUM_TTL = 36 * HOUR_IN_SECONDS` y el
docblock de `forget_series_medium()` (`:1986`) dice «el TTL de **12 h** cierra cualquier caso
residual». Es el mismo tipo de defecto —comentario caducado— que el capítulo sí denuncia en
`AkibaraEmail` («los **tres** `WC_Email`»), en `apply_preventa()` y en `SeoSocial`. Falta el cuarto.

### 4.3 S18 (confirmada por lectura) no llegó al capítulo

`verificacion.json` → `sospechas_confirmadas_por_lectura[14]`: «S18 · La parte HTML del correo de
novedades enseña precio y la plana no» (`customer-series-follow.php:95-103` frente a
`plain/customer-series-follow.php:53-54`, y `plain/customer-bis-instock.php:30-42` que hace lo
contrario **y lo justifica por escrito**).

La pieza 24 dedica media página a las reglas de verdad de las plantillas y no menciona que **dos de
las cuatro tomaron decisiones opuestas sobre el mismo dato** —el precio en la mitad plana—, con el
comentario de una de ellas explicando por qué la otra está mal. Es exactamente el género de hallazgo
que el capítulo colecciona.

### 4.4 La refutación S17 se tragó una superficie pública real

`verificacion.json` → `sospechas_refutadas[0]` refuta S17 (el `Link: rel=alternate` no es un bug) y
tiene razón. Pero el capítulo, al descartar la sospecha, **descartó también el hecho**: la cabecera
`Link: <url>; rel="alternate"; type="text/markdown"` se emite en la rama HTML
(`MarkdownNegotiation.php:286`) y no aparece ni una vez en el capítulo. El capítulo enumera «el
contrato de negociación son **cuatro** cosas y no una» (servir `text/markdown`, `Vary: Accept`,
`406`, honrar q-values) y deja fuera la quinta, que es la única que un agente ve sin negociar nada.

**Qué debería decir.** Añadirla a la lista del contrato: «y anunciar la alternativa con
`Link: …; rel="alternate"; type="text/markdown"` en la respuesta HTML, para los clientes que
descubren en vez de negociar (`:278-287`)».

### 4.5 `verificacion.json` marca su propia zona ciega y el capítulo no la hereda

> notas: «Sin verificar por lectura: el cuerpo de `write_manifests()` y su rollback, el armado del
> texto de `llms.txt`, y la maquinaria de huella/CAS/shutdown de `SeriesSitemapLastmod` — ahí la
> cobertura es la más baja del área».

El capítulo describe esas tres cosas con la misma seguridad que el resto («escritura atómica
(temporal + `rename`), con lease de 30 minutos, rollback del extendido si falla el compacto»;
«compare-and-swap hecho a mano por SQL»), sin decir que son la parte menos verificada. Para un
novato que va a depurar un `llms.txt` que no se regenera, esa advertencia vale oro.

**Qué debería decir.** Una nota al pie de las piezas 2 y 14: «Esta maquinaria es la parte del
carril con menos verificación por lectura; antes de fiarte de esta descripción para un cambio,
léela en el fuente.»

### 4.6 Coincidencias que sí verifiqué (para que no se toquen)

Estas tres correcciones del capítulo son correctas contra el código y no hay que reabrirlas:
`compose_series_meta` deja `$title = ''` y cae a la plantilla de Rank Math
(`SeoEnhancements.php:2233-2239` + `:1101-1103`); el orden de `wp_robots` está invertido respecto a
Rank Math (`FilteredUrlsPolicy.php:45` sin prioridad vs `SeriesPaginationPolicy.php:59` en 98); y la
parte plana no sale con entidades sino **mutilada** por la última regla de `$plain_search`.

---

## 5 · Los diagramas

**Validación con `mmdc` (v11.17.0): los 7 compilan sin error.** También comprobé que los `<br/>`
del `stateDiagram-v2` se renderizan de verdad (0 apariciones literales de `&lt;br/&gt;` en el SVG,
54 `foreignObject`), que era lo que más riesgo tenía.

| Diagrama | Compila | ¿Se entiende solo? | Nodos que el texto nombra y faltan |
| --- | --- | --- | --- |
| `-piezas.mmd` | OK | Casi | **Sí, varios (ver 5.1)** |
| `-cron.mmd` | OK | Sí | — |
| `-estados-portada.mmd` | OK | **Sí, el mejor** | — |
| `-caso-landing-serie.mmd` | OK | Sí | — |
| `-caso-agente-ia.mmd` | OK | Sí | falta el `Link: rel=alternate` (ver 4.4) |
| `-caso-aviso-stock.mmd` | OK | Sí | — |
| `-caso-correo-mudo.mmd` | OK | Sí | — |

### 5.1 `-piezas.mmd` — faltan los nodos que el propio capítulo declara imprescindibles

La cabecera del fichero promete «el mapa entero en una figura … las 30 piezas … y quién habla con
quién». Le faltan cuatro cosas que el texto usa como si estuvieran:

1. **`SeriesCatalogEligibility`** — el capítulo dice que es la autoridad que decide si una landing
   se indexa (Caso 1, paso 1) y que es lo que comparte con `akibara-search` (tabla «Con quién
   habla»). En el diagrama no existe, así que la arista `PAGINA → ?` no se ve.
2. **`SeriesIndex`** — el nodo `BL` («Blog») sale del diagrama sólo hacia `TERMMETA`, cuando el
   texto dice que quien decide el conjunto es `SeriesIndex`.
3. **`SeriesFacts`** — está en el diagrama del Caso 1 pero no en el de piezas, aunque es lo que
   alimenta `ENH`.
4. **`SeriesSchema` (etapa 91)** — el subgrafo `SCHEMA` tiene `SPRM`, `BOOK`, `SHOPPING`, `ADULTO`
   y `GRAFO`; falta quien ocupa la 91, y sin él la arista `BOOK --isPartOf--> ?` no se puede dibujar
   (ver 1.2).

**Sugerencia concreta.** Añadir un subgrafo `COMPARTIDO["Autoridades de otros carriles"]` con
`ELEGIB`, `INDEX`, `FACTS` y `SSCHEMA`, en el estilo `tercero` o en uno nuevo, con las aristas
`PAGINA --> ELEGIB`, `ENH --> FACTS`, `BL --> INDEX`, `BOOK -. "isPartOf #serie" .-> SSCHEMA`. Son
cuatro nodos y cuatro aristas, y convierten cuatro referencias ciegas en el mapa en algo que se ve.

### 5.2 `-piezas.mmd` — dos etiquetas prometen más de lo que el código hace

- `SOC["SeoSocial<br/>la portada del tomo 01 y **el cambio AVIF a JPG**"]` — el cambio a JPG sólo
  ocurre si la hermana ya existe; con 1.699 de 14.900, la etiqueta describe el caso minoritario. El
  diagrama de estados sí lo dice bien. **Sugerencia:** «la portada del tomo 01 y el cambio a JPG
  cuando la hermana ya existe».
- `MD["MarkdownNegotiation<br/>la misma URL en Markdown + 8 alias en inglés"]` — correcto, pero lo
  que el capítulo quiere que el lector recuerde es que **los alias corren con el interruptor
  apagado**. En el diagrama del agente sí está como `Note`; en el de piezas no.

### 5.3 `-cron.mmd` — le falta la única columna operativa

El `timeline` está bien construido y dice lo importante («la cadena de reanudación NUNCA arranca»,
«tope real 100 portadas por DÍA»). Lo que no dice, y es lo que uno va a buscar ahí, es **cómo
averiguar la hora real de hoy**. Una entrada más en la sección «Cómo se disparan de verdad»:
`wp cron event list --fields=hook,next_run_relative | grep akibara_business_`.

### 5.4 `-caso-aviso-stock.mmd` — el asunto dibujado no es el asunto que se edita

El diagrama cierra con `SMTP-->>Cliente: «Producto está de vuelta en Akibara»`, y el texto del
capítulo lo repite igual en el Caso 3: «**«*Producto* está de vuelta en Akibara»** (asunto por
defecto, editable en WooCommerce → Ajustes → Correos)». El asunto real es
`'{product_name} está de vuelta en Akibara'` (`InstockEmail.php:59`).

La diferencia no es cosmética: **`{product_name}` es literalmente lo que hay que escribir en el
campo del admin**. Un capítulo que promete decir dónde se opera cada cosa tiene que enseñar el
marcador, no su resultado. Pasa lo mismo con los otros tres (`{order_number}` en
`ReadyToShipEmail.php:82`, `'Salió el tomo {tomo} de {serie}{y_mas}'` en `SeriesFollowEmail.php:77`)
y en todos los casos de uso del capítulo.

**Sugerencia.** En el diagrama, `«{product_name} está de vuelta en Akibara»`; y en el capítulo, una
columna «asunto por defecto» en la tabla del punto 2.3, con los marcadores tal cual.

*(Comprobé además el orden de los dos mu-plugins de correo que el diagrama dibuja —captura antes
que transporte— y **es correcto**: WordPress aplica el filtro `wp_mail` antes que `pre_wp_mail`,
así que la reescritura del destinatario ocurre antes de que el guard del transporte decida. No hay
nada que cambiar ahí.)*

### 5.5 `-estados-portada.mmd` — el mejor del lote, con un hueco

Tiene los cinco estados, las notas de las dos trampas medidas y el bucle roto. Lo único que le falta
es el estado **«Encolada pero el evento no corre»**: el diagrama va `Encolada --> Convertida: corre
el evento puntual` como si siempre corriera, y el propio capítulo dice que encadenado con S1 «una
portada nueva puede tardar días». Una transición `Encolada --> Encolada: el cron del hospedaje aún
no pasó por este evento` cerraría la figura.

---

## Lo que haría yo primero si me dieran este capítulo para arreglar

1. **Las constantes SMTP (2.1).** Es lo único de esta lista que, si alguien sigue el capítulo al pie
   de la letra, **deja la tienda muda**.
2. **El estado vivo del interruptor Markdown (2.8).** El capítulo desarrolla como caso principal el
   escenario que no es el de producción.
3. **«Único punto de contacto» con el feed (3.1).** Una exclusividad falsa impide mirar una colisión
   real de prioridades.
4. **Los asuntos de preventa que no se editan por pantalla (2.3 bis).** El capítulo promete una
   palanca que no existe para tres de cuatro correos.
5. **Las remisiones de piezas no presentadas (1.1, 1.2, 2.10).** Es lo que separa un capítulo que se
   lee de uno que se puede usar.

---

## Respuesta del redactor

**Fecha: 2026-09-12.** Se aplicaron **todos** los puntos de la crítica al capítulo, a la tabla de
reglas, a la de datos, al glosario, al `resumen.json` y a cinco de los siete `.mmd` (los siete
vuelven a compilar con `mmdc`, y `-piezas` y `-estados-portada` siguen renderizando sus `<br/>` como
`foreignObject`, cero apariciones literales). Sólo tres matices, y ninguno cambia la conclusión de
quien criticó.

**1 · Punto 4.1 — el hallazgo es correcto, la frase que lo enuncia va un paso más lejos que el
código.** La crítica dice que «**ninguna** variante que genera el código de hoy contiene "Revisa"».
No es exacto: la plantilla genérica de respaldo `series_meta_description()`
(`SeoEnhancements.php:1529-1531`) sigue viva y sus tres candidatas **sí** llevan «Revisa el catálogo,
preventas y envío según destino.». Lo que no lleva «Revisa» es el **generador rico**
`compose_series_meta()` (`:2260-2315`), que es el que compone casi todas las landings: sus cierres
son «Cómpralo en Akibara con despacho a todo Chile.», «Desde $X en Akibara…», «Despacho a todo
Chile.» y «en Akibara Chile.». Como el anclaje exige **las dos** frases a la vez
(`strpos( $base, ' en Akibara Chile.' )` **y** `strrpos( $base, 'Revisa ' )`, `:2338-2340`), el
resultado práctico es el que denuncia la crítica —para lo que se compone hoy manda el `preg_split`
de respaldo— pero la rama anclada no está muerta del todo: acierta sobre la plantilla genérica y
sobre las filas viejas de `termmeta`. El aviso se escribió con esa precisión (Caso 1, paso 5).

**2 · Punto 3.1 — se aplicó entero, y se añadió el tercer contacto que la propia crítica menciona.**
Verificado: `AdultContentFeed.php:82` y `GlaBridge.php:54-60` son los dos en
`woocommerce_gla_product_attribute_values` prioridad 10, y `FrontendCompatibility.php:62` es el
tercer punto de contacto con el mismo tercero por otro filtro. La palabra «único» desapareció de la
tabla «Con quién habla» **y** del `resumen.json`, que la repetía.

**3 · Punto 2.9 — la regla de borde ahora tiene nombre, número y expresión, pero no sale del
snapshot.** Se identificó como la **Cache Rule nº 10 de la zona, «BYPASS Accept text/markdown
(agentes IA)»**, con la expresión `any(http.request.headers["accept"][*] contains "text/markdown")`
→ *cache: false*, más el bypass equivalente en el `.htaccess` **después** de `# END LSCACHE`. Eso no
está en el código congelado: viene del registro de operaciones del 2026-08-28. El capítulo lo dice
con esas palabras —«esa regla no vive en este código»— para no romper su propia promesa de que cada
afirmación técnica sale del snapshot. La comprobación que pedía la crítica (dos peticiones, no una)
está en la pieza 15 y en la nueva sección «Cómo se comprueba que esto está vivo».

**Lo demás se aplicó sin reservas**, incluidos los cinco de la lista final: las seis constantes SMTP
con su nombre exacto y el interruptor maestro `AKIBARA_HOSTINGER_MAIL_ENABLED` (2.1), el estado vivo
del interruptor Markdown en producción con el Caso 5 reescrito para empezar por la rama viva (2.8),
la exclusividad falsa del feed (3.1), los tres asuntos de preventa que no se editan por pantalla
(2.3 bis, en la pieza 21 y en el Caso 4) y las remisiones de las piezas no presentadas (1.1, 1.2 y
2.10, en una tabla nueva antes de «Piezas», con `RobotsMeta`, `Text` y `VolumeTitle` incluidas).
También se añadieron la sección de comandos de diagnóstico que faltaba (2.5), las URL de las cuatro
pantallas (2.2), la tabla que cruza los cuatro correos con sus identificadores (2.3), los seis
marcadores de `subject_tanda` (2.4), la ruta absoluta de `akb-runtime/` con quién la deposita y cómo
se verifica (2.7), la aritmética de los 132 días (1.8), las fechas pegadas a los dos conteos de
portadas (1.5), la tabla de prioridades de `wp_robots` (1.7), las cuatro claves del esqueleto de la
pantalla de correo (1.10), la mitad que faltaba de `is_sitemap_path()` (3.6), la condicional del
`priceValidUntil` (3.2), el matiz de la prioridad 15 de `MailFrom` (3.3), la remisión a
`MarketingConsent` (3.5), el `OFRECIDOS` que vive en `MarkdownNegotiation.php:63` (3.4), los rangos
de línea corregidos (3.7), el TTL contradictorio de 36 h / 12 h (4.2), el precio divergente en las
mitades planas (4.3), el `Link: rel="alternate"` como quinta pieza del contrato (4.4) y los avisos
de confianza sobre las zonas menos verificadas (4.5). En el glosario entraron
`-webkit-text-fill-color`, `wp_kses_post()` y `Link: rel="alternate"` (1.3 y 1.4).
