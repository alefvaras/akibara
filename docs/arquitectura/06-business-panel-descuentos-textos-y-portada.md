# akibara-business · el panel del dueño: campañas de descuento, textos, portada, ficha y formularios

> Fuente de verdad de este capítulo: el *snapshot* congelado de lo publicado en producción
> — `akibara-business` 1.116.4, `akibara-search` 0.1.60,
> `akibara-cart-checkout-blocks` 2.2.17, `akibara-rewards` 0.3.13 y el tema `akibara-gp`
> 2.1.118. Salvo que se diga otra cosa, **todas las rutas son relativas a**
> `public_html/wp-content/plugins/akibara-business/`. Cada afirmación técnica lleva su
> fichero y su línea.

---

## Para qué existe (en dos párrafos que entienda un novato)

Akibara vende manga por internet. El sitio está hecho con **WordPress** (el programa que
arma y sirve las páginas) más **WooCommerce** (el añadido que le da carrito, precios y
pedidos). Ninguno de los dos sabe nada del negocio chileno de vender tomos: no sabe que
una editorial puede estar en campaña, que un tomo puede estar «a pedido», ni que el cartel
de la portada cambia cada dos semanas. Todo eso lo pone `akibara-business`, un *plugin*
propio. Y este capítulo describe la parte de ese plugin que existe **para que el dueño no
tenga que llamar a nadie**: el tablero desde el que cambia precios, frases, colores,
portada y avisos, y la maquinaria que convierte esos cambios en lo que ve el cliente.

La pieza central es el **panel Akibara**: un menú propio dentro de la administración de
WordPress, con una pestaña por tema. Desde ahí el dueño monta una **campaña de descuento**
(«todo lo de Ivrea Argentina, un 10 % menos, hasta el 30»), edita las **50 frases** de la
tienda, arma el **cartel de la portada**, escribe la **cinta de anuncios** de arriba y
elige los dos **colores de marca**. Nada de eso reescribe los 1.568 productos: el
porcentaje se guarda una sola vez, pegado al grupo de productos, y el precio con descuento
se calcula **al vuelo** cada vez que alguien pide un precio. Por eso apagar una campaña
devuelve la tienda a su precio normal en la siguiente visita, sin tocar una sola ficha — y
por eso el precio que ve el cliente puede no coincidir con el número que hay escrito en la
base de datos. La contracara es la **caché**: el sitio guarda las páginas ya pintadas para
servirlas rápido, así que cambiar un precio exige además **avisar a la caché**, y buena
parte de los problemas de este capítulo viven justo ahí.

### Vocabulario mínimo

| Palabra | Qué es, en media frase |
| --- | --- |
| *option* | Un ajuste guardado en la tabla `wp_options` de WordPress: un nombre y un valor. |
| *hook* | Un aviso con nombre que WordPress emite en un momento concreto y al que un plugin se apunta. |
| *action* | Un hook que sólo avisa («ya guardé esto»). |
| *filter* | Un hook que además deja **cambiar** el valor que pasa por él (por ejemplo, el precio). |
| *prioridad* | Un número que ordena a los apuntados a un mismo hook: el más bajo corre primero. |
| *shortcode* | Una etiqueta entre corchetes que se escribe dentro de un texto y el sitio sustituye al pintar. |
| *taxonomía* / *término* | La forma de agrupar productos (editorial, categoría, serie, formato) y cada grupo concreto. |
| *term-meta* / *post-meta* | Un dato extra pegado a un término o a un producto. |
| *transient* | Un dato guardado con fecha de caducidad, para no recalcularlo en cada visita. |
| *nonce* | Un pase de un solo uso que demuestra que un formulario salió de la pantalla real. |
| *capability* | El permiso que WordPress exige para una acción; aquí casi siempre `manage_woocommerce`. |
| *purgar* | Tirar a la basura las páginas guardadas para que se vuelvan a armar con los datos nuevos. |
| *variación* | La versión concreta de un producto con opciones (talla, color). En Akibara **no hay ninguna**: el catálogo es 100 % de productos simples. |
| *drop-in* | Un fichero suelto en `wp-content/` que WordPress usa **en lugar** de una parte suya; aquí, el `object-cache.php` que dejó LiteSpeed. |
| *sección de acción* | Una pestaña del panel que **no** guarda ajustes por el formulario común: trae el suyo y, con él, sus propias reglas. |
| *huella (fingerprint)* | Un resumen corto de la configuración de descuentos; el buscador lo guarda para saber si su índice sigue vigente. |

### Las nueve pantallas de este capítulo, y cómo llegar a cada una

Todas cuelgan del menú **Akibara** (slug `akibara-panel`, no `akibara`: el slug corto da 403
y parece un problema de permisos). La URL de una pestaña es siempre
`/wp-admin/admin.php?page=akibara-panel&tab=<slug>`. El nav pinta cuatro grupos en este
orden: primero las que no tienen grupo —ahí va el Dashboard—, después **Venta**, **Sitio** y
**Sistema** (`AdminPanel.php:73-78`). Una sección registrada con un grupo que no existe cae
en el primer bucket en vez de desaparecer del nav.

| Pestaña | Slug (`&tab=`) | Grupo | ¿Sección de acción? | Dónde se registra |
| --- | --- | --- | --- | --- |
| Dashboard | `dashboard` | — (primera) | **Sí** | `src/Plugin.php:1280-1286` |
| Slider de Inicio | `home_hero` | Sitio | **Sí** | `src/Plugin.php:1287-1295` |
| Series en la portada | `home_series` | Sitio | No | `src/Plugin.php:1296` |
| Barra de anuncios | `announcement` | Sitio | No | `src/Plugin.php:1297` |
| Textos | `copys` | Sitio | No | `src/Modules/CopyManager/CopyManager.php:146-156` |
| Branding y UX | `branding` | Sitio | No | `src/Modules/ThemeCustomizer/ThemeCustomizer.php:56-100` |
| Imágenes *(desde 1.116.10)* | `imagenes` | Sitio | No (campo + botón propio) | `src/Modules/ImageQuality/ImageQuality.php:154` |
| Velocidad *(desde 1.116.13)* | `velocidad` | Sitio | No (la option de la etiqueta de Ads purga sola, y sólo si cambia) | `src/Modules/SiteSpeed/SiteSpeed.php` |
| Descuentos | `taxonomy_discount` | Venta | No | `src/Plugin.php:1384-1392` |
| Arma tu Pack *(capítulo 03)* | `series_bundles` | Venta | No | `src/Plugin.php:1393-1401` |

Una **sección de acción** es una pestaña que no guarda ajustes por el formulario común del
panel, sino que trae el suyo. Eso no es un detalle de estilo: **se salta todas las redes del
panel** —el filtro `akibara_panel_save_errors` que puede bloquear el guardado, el contador
de cambios reales que decide si purgar, y `skip_untouched_default()`—. De ahí salen dos de
los defectos de este capítulo (el hero que se trunca sin avisar y el hero que purga siempre).
El panel marca una sección así al registrarla, y un módulo puede cambiarlo por el filtro
`akibara_panel_section_is_action` (`AdminPanel.php:652-664`).

---

## Cómo encaja con el resto (con quién habla: plugins, tema, terceros; qué pasa si se apaga)

![Piezas del panel, el precio, los textos y la portada](diagramas/06-business-panel-descuentos-textos-y-portada-piezas.mmd)

Este capítulo no es un módulo suelto: es **una pantalla, un cálculo y muchas superficies**.
Conviene entender cuatro fronteras antes de entrar en las piezas.

**Con WooCommerce se habla sólo por hooks, nunca editando su código.** El descuento no
escribe precios: se apunta a ocho filtros de precio de WooCommerce y devuelve otro número
(`src/Modules/TaxonomyDiscount/PriceFilters.php`). Los sellos de la tarjeta se pintan en
los huecos que WooCommerce ofrece (`woocommerce_before_shop_loop_item_title`, prioridad 9,
`src/Modules/LoopEnhancer/LoopEnhancer.php`). La rejilla de la ficha se cuela en la
pestaña «Descripción» (`woocommerce_product_tabs`, prioridad 98,
`src/Modules/PdpEnhancer/PdpEnhancer.php:62`). Si mañana se desactivara `akibara-business`,
WooCommerce seguiría vendiendo: con los precios normales, sin sellos, sin rejilla, sin
barra de anuncios y sin cartel de portada, pero vendiendo.

**Con el buscador propio (`akibara-search`) se habla por un contrato estricto y en las dos
direcciones.** El buscador **materializa** el precio con descuento dentro de su índice, y
un precio equivocado ahí queda sellado sin que nada lo delate; por eso Business le entrega
el precio por el filtro `akb_search_effective_price` y, si tiene la menor duda, **lanza una
excepción en vez de devolver el precio base**
(`src/Modules/TaxonomyDiscount/SearchPriceProvider.php`). En sentido contrario, el buscador
vigila el porcentaje de cada término: se engancha a `added/updated/deleted_term_meta` con
prioridad `PHP_INT_MAX` sobre `_akb_taxonomy_discount_pct`
(`akibara-search/src/EditorialDiscountPublication.php:244-246`) y, cuando su **publicación
editorial** termina, dispara `litespeed_purge` y `litespeed_purge_all` (`:1275-1279` y
`:2235-2239`). Ese detalle importa mucho y se explica en el caso 1: **quien purga la caché
tras cambiar un porcentaje no es el panel, es el buscador.**

**Qué es una «publicación editorial», y cuánto dura.** Es el proceso con el que
`akibara-search` vuelve a escribir dentro de su índice el precio con descuento de todos los
productos de las editoriales afectadas y después intercambia sus tablas, de modo que el
buscador nunca sirve una mezcla de precios viejos y nuevos. No es lo mismo que una
**reconstrucción** del índice entero, aunque una publicación puede pedir una: la
reconstrucción rehace los 1.631 documentos. Y los dos caminos no cuestan lo mismo:

| Camino | Qué lo dispara | Cuánto tarda | Qué ve el cliente |
| --- | --- | --- | --- |
| Reconstrucción **pedida a mano** | El botón del panel del buscador o su orden por línea de comandos | ~30 segundos para 1.631 documentos | Nada: se construye en tablas sombra y las sugerencias siguen respondiendo |
| Reconstrucción **disparada por guardar** | Escribir `_akb_taxonomy_discount_pct` u otro dato vigilado desde la administración | ~6 minutos | Las sugerencias del buscador responden **503** durante ese rato |

Cuando este capítulo dice «~6 minutos», habla siempre del segundo camino: es el que dispara
guardar la pestaña Descuentos.

**Con el tema `akibara-gp` se habla por options y por un hook de datos.** El pie del sitio
lee `akb_copy_footer_support_hours` y `akb_copy_footer_phone`; el acordeón de preguntas
frecuentes de la ficha lo **pinta el tema** pero los datos salen de `ProductFaq` por el
hook `akibara/product_faq_data`; y los dos colores de marca se inyectan como variables CSS
`--aki-red` y `--aki-surface-1` en `wp_head` con prioridad 100, **sólo si difieren del
color que trae el tema** (`src/Modules/ThemeCustomizer/ThemeCustomizer.php:159-177`).

**Con los terceros de rendimiento y analítica se habla para que no rompan lo propio.**
LiteSpeed Cache y Cloudflare guardan el HTML; `FrontendCompatibility` apaga Microsoft
Clarity, el Google Analytics de Rank Math, el de WooCommerce y el de Google Ads salvo que se
cumplan **las dos** condiciones de `is_production_frontend()`: que
`wp_get_environment_type()` devuelva `production` **y** que el host de la petición sea
`akibara.cl` o `www.akibara.cl`
(`src/Modules/FrontendCompatibility/FrontendCompatibility.php:202-210`, la condición en
`:208-209`). Son dos y no una, y eso cambia la prueba: si en el servidor real
`WP_ENVIRONMENT_TYPE` no está puesto en `production`, la analítica se apaga **en producción**
y no falla nada visible que lo delate; y un clon que copie el `wp-config.php` con
`production` dentro queda protegido sólo por el host. Además protege los scripts de
MercadoPago del **combinado** y del **diferido** de LiteSpeed —dos optimizaciones que juntan
varios ficheros JavaScript en uno y retrasan su ejecución— y hace que Cloudinary respete la
clase `skip-lazy`, la marca con la que una imagen pide cargarse de inmediato en vez de
esperar a que el visitante llegue hasta ella.

**Qué pasa si se apaga cada cosa.** La campaña de descuento tiene interruptor propio
(`akibara_taxonomy_discount_enabled`), y su defecto de lectura es **`no`**
(`src/Modules/TaxonomyDiscount/Settings.php:217`): sin fila escrita, no hay descuento. La
barra de anuncios viene **encendida** de fábrica (`AnnouncementBar.php:124`) y apagarla
conserva los mensajes. El hero desaparece solo si no hay diapositivas. El módulo de
`crossorigin` tiene un interruptor de emergencia (`akb_crossorigin_scripts_enabled`) que
**no tiene ninguna pantalla**: hoy sólo se mueve por línea de comandos
(`src/Modules/ScriptDiagnostics/CrossOriginScripts.php:70` y `:91`). Apagarlo son dos
órdenes, y surte efecto en la petición siguiente sin desplegar nada, porque la option se
consulta dentro del filtro y no al arrancar el plugin (`:89-93`):

```bash
ssh akibara
wp option update akb_crossorigin_scripts_enabled no
```

Sólo el valor exacto `no` lo apaga: cualquier otra cosa —`0`, `off`, vacío— lo deja
encendido, porque la comparación es `'no' === get_option( …, 'yes' )` (`:91`). Para volver a
encenderlo, `wp option update akb_crossorigin_scripts_enabled yes`.

---

## Piezas

### AdminPanel — la pantalla única

**Qué hace en una frase.** Pinta el menú «Akibara», dibuja los campos que cada módulo
declara, verifica permiso y nonce, guarda, cuenta qué cambió de verdad y sólo entonces
decide si purgar la caché.

**Ficheros.** `src/Modules/AdminPanel/AdminPanel.php`, `assets/js/admin-panel.js`,
`assets/css/admin-panel.css`.

**En qué momento actúa.**

| Hook | Prioridad | Callback | Qué hace |
| --- | --- | --- | --- |
| `admin_menu` | 10 | `register_menu` | Crea el menú (slug `akibara-panel`, capacidad `manage_woocommerce`) y el submenú «Panel». |
| `admin_init` | 10 | `handle_save` | Procesa el POST: nonce, capacidad, validación, guardado, purga y redirección. |
| `admin_enqueue_scripts` | 10 | `enqueue_admin_assets` | Carga CSS y JS sólo en la pantalla del panel. |
| `admin_print_styles-edit.php` | 10 | `print_product_list_styles` | Impide que el SKU (el ISBN-13) parta en dos líneas en la lista de productos. |
| `pre_update_option` | 10 | `skip_untouched_default` | Sólo durante el guardado: no escribe la fila si no existe y lo enviado es exactamente el defecto pintado (`:382-420`). Crear filas de más **mueve la huella editorial** y puede atascar el buscador. |
| `updated_option` / `added_option` | 10 | contador | Cuenta cambios **reales** para decidir la purga. |

**Qué datos lee y escribe.** No tiene options propias: guarda las que declara cada
pestaña. Deja dos transients de 60 segundos para sobrevivir a la redirección:
`akibara_eda_save_errors_{user_id}` (errores de validación) y
`akibara_eda_save_partial_{user_id}` (campos que se intentaron escribir y no quedaron).

**Hooks que ofrece.** `akibara_panel_save_errors` (un módulo puede bloquear el guardado
entero), `akibara_panel_form_hidden_inputs` (inyectar campos ocultos),
`akibara_panel_section_is_action` (declarar que una pestaña trae su propio formulario y por
tanto se salta las redes del panel — ver la tabla de pestañas de arriba) y
`litespeed_purge_all`.

**Cómo se purga a mano, cuando hace falta.** El panel purga solo, pero el dueño —o QA—
puede forzarlo desde **LiteSpeed Cache → Toolbox → Purge All** en la administración, o desde
la línea de comandos con `wp eval "do_action( 'litespeed_purge_all' );"`, que es exactamente
lo que disparan estos módulos. Conviene no hacerlo por costumbre: LiteSpeed corre con
`cdn-cloudflare_clear`, así que una purga total devuelve al origen las ~1.568 fichas, las
106 landings de serie, la portada y el blog. Para **mirar** una página sin purgar nada basta
con añadirle un parámetro que nadie tenga guardado, por ejemplo `/tienda/?_v=1699999999`:
ni Cloudflare ni LiteSpeed tienen esa URL cacheada, así que la página se arma de nuevo sólo
para quien la abre. Es el mismo truco que usa el enlace del aviso post-guardado.

**Dónde lo opera el dueño.** WP Admin → Akibara. El menú se llama `akibara-panel`, no
`akibara`: el slug corto da 403 y parece un problema de permisos.

**Qué ve el cliente.** Nada, directamente.

**Los dos errores que puede mostrar, y qué hacer con cada uno.** El panel guarda dos
transients de 60 segundos y los pinta después de la redirección:

| Qué ve el dueño | Transient | Qué significa | Qué hacer |
| --- | --- | --- | --- |
| «No se guardó ningún descuento porque el servidor truncó el formulario. Reduce la cantidad visible o aumenta `max_input_vars` antes de intentarlo nuevamente.» (`TaxonomyDiscount.php:369`) | `akibara_eda_save_errors_{user_id}` | PHP se comió parte del formulario y **no se escribió nada**. | Cerrar los acordeones de taxonomías que no se van a tocar (así se envían menos campos) y volver a guardar. Si aun así se repite, subir `max_input_vars` en PHP. |
| Un aviso que nombra los campos que se intentaron escribir y no quedaron | `akibara_eda_save_partial_{user_id}` | El candado del buscador rechazó esas escrituras concretas; el resto del guardado sí entró. | Reintentar ese campo pasados unos minutos; si insiste, mirar el estado del buscador antes de volver a guardar. |

**Detalle que el mapa omitía** (corrección del verificador): `admin-panel.js` hace dos
cosas más que el contador de líneas y el selector móvil. Mide el **alto real** de las
barras pegajosas del panel y lo publica en `--akb-panel-barra-alto` con un
`ResizeObserver`, más una red de seguridad en `focusin` que desplaza el scroll cuando un
control queda tapado (`assets/js/admin-panel.js:29-177`); el literal que antes vivía en el
CSS caducó en diez días —la barra del Slider de Inicio pasó a medir 121 px en móvil contra
los 84 px fijados—. Y pone `aria-label` a los `input.select2-search__field` que selectWoo
crea sin nombre accesible, vigilando el árbol con un `MutationObserver` porque selectWoo
los vuelve a crear al abrir el desplegable (`:235-278`). Un hallazgo de QA sobre solapes o
sobre accesibilidad en el panel toca justo lo que gobiernan estas dos piezas.

---

### AdminDashboard — el Centro de Mando

> **Alerta de purga del borde (R4-fb4b69e8, 1.116.28).** `EdgePurge` reintenta una vez a los 75 s (tope 3, por
> Action Scheduler con args posicionales) y `EdgePurge::health_alert()` enciende «Alertas del Sistema» sólo cuando
> los reintentos se agotan: con 401/403 dice «revisa las credenciales de Cloudflare en LiteSpeed Cache → CDN». El
> borde guarda 10 minutos (el docblock decía 8 horas): un fallo suelto se cura solo y no alerta.

**Qué hace en una frase.** Es la primera pantalla del panel: dice cómo está el ecosistema
de los cuatro plugins propios, levanta alertas y publica el ranking «Lo más solicitado»,
que es con lo que el dueño decide qué reponer y qué ficha crear.

**Ficheros.** `src/Modules/AdminDashboard/AdminDashboard.php`.

**En qué momento actúa.** No se engancha a nada del frontend. Su único hook es
`admin_post_akb_demand_csv` (prioridad 10), detrás del botón **«Descargar CSV»** que hay al
pie del pedido sugerido (`:953`), con capacidad y nonce, y blindando contra inyección de
fórmulas de Excel (prefija con apóstrofo cualquier celda que empiece por `=`, `+`, `-` o
`@`).

**Qué datos lee y escribe.** Lee de forma agregada `wp_akb_bis_subscribers`,
`wp_akb_wishlist`, `wp_wc_order_product_lookup`, `wp_wc_orders` y
`wp_wc_product_meta_lookup`. Escribe dos transients: `akb_demand_ranking_v1` (1 hora) y
`akb_demand_continuity_v1` (6 horas).

**Qué tareas programadas vigila — y cuáles NO.** Ésta es la confusión más cara de la
pantalla. El Centro de Mando **no** vigila «las tareas programadas» del sitio: recorre una
lista escrita a mano de **dos** hooks, más un tercero sólo si su interruptor está encendido
(`:88-98`):

| Hook vigilado | Qué es | Condición |
| --- | --- | --- |
| `akb_order_preventa_recompute_ready` | Preventa: recálculo diario de pedidos listos | siempre |
| `akb_bis_cleanup_terminal` | Lista de espera: limpieza de suscripciones terminales | siempre |
| `akb_order_preventa_t7_reminder_scan` | Preventa: recordatorio previo a la llegada | sólo con `akb_preventa_t7_enabled` en `yes` |

De cada uno mira dos cosas: si está **desagendado** (alerta inmediata) y si su próxima
ejecución lleva más de **6 horas** de retraso (`CRON_GRACE_SECONDS`, `:31`, comprobado en
`:124-136`). Fuera de esa lista **no vigila nada**: ni los crons del buscador, ni los de
fidelidad, ni los del SEO del capítulo 16, ni ninguna tarea de Action Scheduler. Un
Dashboard en verde significa «estos dos o tres hooks están agendados y al día», nunca «las
tareas programadas del sitio corren».

**Reglas propias del ranking.** Los pesos son: esperando aviso ×3, coleccionista en racha
×2, búsqueda sin resultado ×2, favorito y venta ×1, con tope de 10 filas
(`:235, 247, 257, 263-267`). Una «racha» de colección exige tener el último y el penúltimo
tomo contando **volúmenes distintos**, para que una recompra o una reimpresión no avance la
colección (`:305-313`).

**El ranking mezcla tres clases de fila, y sólo una es un tomo.** Quien lea la tabla sin
saberlo no entiende por qué hay filas que no son un producto:

| `type` | Qué es la fila | Cómo puntúa | Línea |
| --- | --- | --- | --- |
| `product` | Un tomo concreto del catálogo | `3 × esperando + 2 × racha + favoritos + ventas` | `:228-236` |
| `serie_gap` | Un **hueco de serie**: un tomo que falta en una colección que varios clientes siguen | `2 × clientes` | `:239-248` |
| `gap` | Una **búsqueda sin resultado** en el buscador | `2 × búsquedas de los últimos 90 días` | `:251-258` |

Las tres se ordenan juntas por puntaje, se descartan las de puntaje 0 y se cortan a diez
(`:261-267`). Por eso el mismo ranking dice a la vez «repón este tomo» y «crea la ficha de
este título que te buscan y no tienes».

**Dónde lo opera el dueño.** Panel Akibara → Dashboard.

**Defecto confirmado.** `module_toggles()` (`:719-727`) declara **cinco** interruptores, y
uno de ellos miente: `'taxonomy_discount' => … 'default' => 'yes'` (`:724`) contra el
`get_option( …, 'no' )` real de `Settings.php:217`. Como la fila no existe mientras el dueño
no encienda la campaña —`skip_untouched_default()` impide crearla con su valor por
defecto—, en el estado normal de la tienda **el Centro de Mando dice «módulos: todos
encendidos» con los descuentos apagados**.

Los otros cuatro sí coinciden con su módulo: `akibara_announcement_enabled` (defecto `yes`,
contra `AnnouncementBar.php:124`), `akibara_fsp_enabled`, `akibara_native_cart_enabled` y
`akibara_address_autocomplete_enabled`. El defecto es de **una fila**, no del mapa entero.

> Una corrección al verificador: su nota nombraba `akb_preventa_t7_enabled` entre «los otros
> cuatro interruptores del mapa». No pertenece a `module_toggles()`; se lee en otro sitio, en
> la vigilancia de crons (`:94`). El interruptor que faltaba nombrar es
> `akibara_announcement_enabled`.

---

### CopyManager — la pestaña «Textos»

**Qué hace en una frase.** Reúne en una sola pantalla **50** frases que antes vivían
escritas dentro del código: los carteles de fecha de llegada, las promesas de despacho, los
textos de `/a-pedido/` y `/disponibles/`, las etiquetas del selector de orden, el pie del
sitio, la página de acceso y el correo al que llegan los mensajes de contacto.

**Ficheros.** `src/Modules/CopyManager/CopyManager.php`, `src/Utilities/Text.php`.

**En qué momento actúa.** `init` prioridad 10 registra la pestaña «Textos» (slug `copys`,
grupo Sitio) con **ocho** secciones: Textos editables (`:167`), Promesas de la tienda
(`:305`), Contenido de preventas (`:358`), Contenido de disponibles (`:417`), Ordenamiento
del catálogo (`:451`), Pie del sitio (`:512`), Página de acceso (`:538`) y Formulario de
contacto (`:623`). Dentro de ellas hay **50** campos editables: 46 de texto, 2 de área de
texto, 1 de correo y 1 desplegable. Al guardar, cada texto pasa por su propio
`woocommerce_admin_settings_sanitize_option_{option}`: `sanitize_required_copy`,
`sanitize_optional_copy`, `sanitize_preventas_intro`, `sanitize_disponibles_intro`,
`sanitize_contact_email` o `sanitize_preventas_guide`.

**Qué datos lee y escribe.** Las 50 options de texto. Las principales:
`akb_copy_eta_*` (**diez** carteles de fecha), `akb_copy_promise_dispatch`,
`akb_copy_promise_preventa_transit`, `akb_copy_promise_pedido_transit`,
`akb_copy_editions_*`, `akb_copy_order_*` (siete etiquetas del selector de orden),
`akb_copy_footer_support_hours`, `akb_copy_footer_phone`, `akibara_forms_notify_email`,
los nueve textos del muro de acceso y los de `/a-pedido/` y `/disponibles/`.

**Cómo se sanean.** Un texto de una línea se limpia **al guardar y al leer**: sin
etiquetas HTML, sin saltos de línea y cortado a 300 caracteres, midiendo en **caracteres y
no en bytes** (con tildes y ñ los bytes engañan) — `src/Utilities/Text.php:37` y `:65-80`.
Los dos textos del pie aceptan el vacío a propósito: vaciarlos es la forma de apagar esa
línea. El correo de contacto inválido **se guarda como el de siempre**.

**Dónde lo opera el dueño.** Panel Akibara → Textos.

**Qué ve el cliente.** Casi todo: carrito, ficha, pedido, correos, pie del sitio, rutas de
catálogo y muro de acceso.

**Los diez carteles de fecha, uno por uno.** Hacen falta por nombre para revisar el
defecto de más abajo o para arreglarlo, así que van completos, con la línea donde se
declara cada uno en `CopyManager.php`:

| Option | Línea | ¿Protegida contra el vacío? |
| --- | --- | --- |
| `akb_copy_eta_cart_ready` | `:174` | **no** |
| `akb_copy_eta_cart_preventa` | `:182` | **no** |
| `akb_copy_eta_tba` | `:190` | **no** |
| `akb_copy_eta_single_preventa` | `:198` | **no** |
| `akb_copy_eta_single_pedido` | `:206` | **no** |
| `akb_copy_eta_single_ready` | `:214` | **no** |
| `akb_copy_eta_noun_preventa` | `:222` | **no** |
| `akb_copy_eta_noun_pedido` | `:230` | **no** |
| `akb_copy_eta_overdue` | `:238` | sí, al guardar |
| `akb_copy_eta_item_arrived` | `:246` | sí, al leer |

**Defecto confirmado, con el recuento corregido dos veces**: **once** textos se pueden dejar
en blanco y publican una etiqueta vacía — **ocho** `akb_copy_eta_*` desprotegidos más los
**tres** `akb_copy_promise_*`. El mapa decía «nueve más tres»; el verificador corrigió el
resultado a once pero conservó la premisa equivocada («nueve» carteles de fecha), y con
nueve la resta no cierra. Los carteles son **diez** (la tabla de arriba), y con diez la
cuenta sale sola: 10 − `_overdue` − `_item_arrived` = 8, más los tres de promesa = 11.
`akb_copy_eta_overdue` sí está en
`required_one_line_copies()` vía `CustomerVisibility::OPTION_OVERDUE`
(`src/Modules/OrderPreventa/CustomerVisibility.php:52`), y `akb_copy_eta_item_arrived` está
protegido **al leer**, porque se lee con `Text::one_line_option()`, que cae al literal
cuando el valor queda vacío (`src/Utilities/Text.php:55-57` y `:73-75`). Los siete
`akb_copy_order_*` tampoco entran en la lista: están protegidos al leer por
`akibara_business_catalog_order_label()` (`akibara-business.php:315-318`).

**Cerrado por las dos puertas (1.116.26).** *Al leer* (R2-2f374da1): `BannerRenderer`,
`PreventaItems::order_noun()` y `PdpEnhancer` ya no leen `akb_copy_*` con `get_option()` a secas
—que sólo aplica el literal cuando la fila NO existe— sino con `Text::one_line_option()`: una fila
vaciada por importación o `wp option update` vuelve a mostrar el texto de fábrica. No queda ningún
`get_option( 'akb_copy_…' )` crudo en `src/`. *Al guardar* (R2-0a2ff112): el aviso «se guardó
recortado» recorre TODA `required_one_line_copies()` (antes una lista a mano de cuatro campos) y
mide con `Text::would_clip()`, sobre el texto SIN truncar: antes medía el resultado de
`Text::one_line()`, que ya viene cortado a 300, así que ni esos cuatro campos podían disparar
el aviso nunca.

---

### ThemeCustomizer — la pestaña «Branding y UX»

**Qué hace en una frase.** Dos selectores de color con los que el dueño cambia el rojo de
marca y el fondo oscuro de los componentes sin tocar CSS.

**Ficheros.** `src/Modules/ThemeCustomizer/ThemeCustomizer.php`.

**En qué momento actúa.** `init` prioridad 10 registra la pestaña; `wp_head` prioridad
100 emite `:root{--aki-red:…;--aki-surface-1:…}` **sólo si hay diferencia real** con el
color de la paleta de `theme.json` (`:159-177`).

**Qué datos lee y escribe.** `akb_brand_color_primary` y `akb_brand_color_surface`. Los
valida con `sanitize_hex_color()` (`:165`).

**Dónde lo opera el dueño.** Panel Akibara → Branding y UX. **Qué ve el cliente:** los
colores de todo el sitio.

---

### ImageQuality y Recompressor — la pestaña «Imágenes» (1.116.10)

**Qué hace en una frase.** Decide con qué calidad guarda WordPress los tamaños de toda imagen
AVIF que se sube (60 por decisión del dueño del 2026-09-14; core trae 82) y recomprime las
portadas ya subidas cuando eso las deja más livianas.

**Ficheros.** `src/Modules/ImageQuality/ImageQuality.php` (ajuste, filtro, pestaña, botón) y
`src/Modules/ImageQuality/Recompressor.php` (la pasada en la cola).

**Por qué existe (medido en producción el 2026-09-14).** Con 82, las 67 portadas subidas el 4 y
5 de septiembre tenían su tamaño de ~700 px en 138 KB de mediana, más que el original de 800 px
(~105 KB). Con 60, sobre 9 portadas: 560 px 105 → 66 KB (SSIM 0,985 → 0,976), ~700 px 146 → 89 KB;
sin diferencia a tamaño real y casi igual con lupa ×3. Las 1.518 portadas antiguas, en cambio,
salieron con la compresión por defecto de ImageMagick (≈ calidad 50 en bytes): recomprimirlas
las haría más pesadas.

**La trampa.** La calidad sólo llega al codificador por `wp_editor_set_quality`: un
`set_quality()` a mano antes de guardar lo pisa el editor, y en AVIF
`setImageCompressionQuality()` no cambia nada (manda `setCompressionQuality()`, que core llama
con lo que devuelve el filtro). Medido: bytes idénticos con 82 y con 40 por el camino equivocado.

**En qué momento actúa.**
- `wp_editor_set_quality` prioridad 20: sólo `image/avif`; JPEG y WebP siguen con core. Registrado
  fuera de cualquier rama de admin, para que alcance a las subidas por CLI/REST y a la cola.
- Guardado de la pestaña: `akibara_panel_save_errors` rechaza una calidad fuera de 40-90 y
  `woocommerce_admin_settings_sanitize_option_*` normaliza; las dos usan `parse_quality()`, la misma
  que usa el lector. Guardar no purga (la pestaña está en `SECTIONS_WITHOUT_CACHED_SURFACE`).
- Botón «Recomprimir las portadas ya subidas con calidad N» → `admin-post` → `Recompressor::start()`
  (`Recompressor.php:181`): encola en Action Scheduler (grupo `akb-image-recompress`, prioridad 20,
  detrás del buscador y la preventa), dice «ya hay una en curso», o reinicia una pasada detenida.
- `Recompressor::run()`: **agenda la corrida siguiente antes de trabajar** (si PHP muere a mitad, la
  pasada sigue) y vuelve a comprobarlo al salir, **también cuando no consigue el candado** — eso fue un
  fallo real: el 2026-09-14 una corrida de la cola y otra por CLI dejaron la pasada detenida en 424 de
  1.589, porque la que no entró se llevó consigo la única acción pendiente. Anota la portada en curso
  (`en_proceso`) y le da **un reintento**: una corrida se corta también por cosas ajenas a la portada
  (tope de tiempo, reciclado del proceso, memoria de otra cuenta del hosting compartido), y marcarla a la
  primera la dejaba fuera para siempre sin forma de recuperarla. Si se corta **dos veces seguidas en la
  misma portada**, ésa sí se marca, se cuenta en «omitidas» y el aviso del panel dice cómo reintentarla
  (recomprimir con otra calidad). 20 s por corrida revisados **entre portadas, nunca a mitad de una** (cortar a
  mitad hacía que la siguiente la empezara de cero: con una portada más lenta que el presupuesto la
  pasada no terminaba nunca). Imagick a un hilo con 256 MiB de memoria y 512 MiB de mapa, restaurados en
  el `finally` **y** en un `register_shutdown_function` (lsphp reutiliza el proceso tras un fatal). Al
  acabar desagenda la continuación.
- **Un solo escritor del estado, con candado propio.** El candado `akb_image_recompress_lock` guarda
  `marca|token|renovación` y desde 1.116.11 se escribe con `Utilities\OptionRow` (compartida con el
  generador de JPG sociales, el manifiesto `llms.txt` y la bitácora de contacto), SQL sobre `wp_options`: `INSERT IGNORE` para tomarlo y un
  `UPDATE … WHERE option_value = <lo leído>` para robarlo vencido (15 min), renovarlo y soltarlo. **No se
  usa `add_option()`**: hace `INSERT … ON DUPLICATE KEY UPDATE` y, con la caché de objetos desfasada,
  pisa el candado de otra corrida en vez de fallar (mismo patrón que `WP_Upgrader::create_lock()`). Cada
  archivo codificado renueva el candado antes de reemplazar nada; la corrida que lo perdió no reemplaza,
  no escribe el estado y no borra el candado ajeno. `start()` y `purge_edge()` también lo toman: si una
  corrida trabaja, el botón dice «ya en curso» y la purga se reintenta a los diez minutos.
- **Retomar una pasada detenida.** Con la **misma** calidad, `start()` sigue donde quedó (mismo cursor y
  mismas cuentas); con otra, empieza de cero pero **arrastra la deuda de purga** (`purga_pendiente`), para
  que lo ya reemplazado no se quede servido pesado desde Cloudflare.
- **Sin purga en hora punta.** Las URLs no cambian, así que Cloudflare seguiría sirviendo las copias
  pesadas; pero `litespeed_purge_all` también tira CSS/JS optimizado, la caché de objetos y todo el
  borde (revisión independiente, 2026-09-14). Si la pasada reemplazó algo, agenda UNA acción
  `akibara_business_image_recompress_purge` a las 04:30 de la tienda. Dos reglas que salieron de la
  segunda revisión: el reintento por candado ocupado (+10 min) **no se sale de la madrugada** —si esos
  diez minutos ya caen de día, se va a la siguiente 04:30—, y agendar **respeta la purga que ya exista**
  en vez de cancelarla: cancelar un reintento de las 04:40 para poner una a las 04:30 del día siguiente
  dejaba el borde sirviendo copias pesadas 24 h más. Si la cola no acepta la acción, el panel no promete
  ninguna fecha.

**Reglas de `process_attachment()` (`:293`).** Sólo adjuntos AVIF bajo `portadas/` (`SELECT DISTINCT`;
el logo, el favicon y los banners también son AVIF y no se midieron). Fuente: la de core
(`source_for()`, `wp_get_original_image_path()` o la adjunta); una fuente de más de 16 MP se deja como
está, se cuenta en «omitidas» y **se marca** (es permanente); una fuente ausente se cuenta igual pero
**no se marca**, porque un disco o un permiso pasajero no puede dejar una portada fuera para siempre. Cada archivo (una vez aunque figure
con dos nombres de tamaño) se recodifica a un temporal único `.akb-recomprimir-{id}-{pid}-…` con la
caja y el recorte del tamaño registrado; **reemplaza sólo si el resultado es ese AVIF, mide lo mismo y
pesa ≤ 90 %**. `error_getting_dimensions` (la caja no cabe) cuenta como conservado. El peso nuevo se
anota releyendo `_wp_attachment_metadata` en crudo y tocando sólo `filesize`, sin
`wp_update_attachment_metadata` (LiteSpeed lo engancha; en prod `img_optm-auto` está encendido). La
marca `_akb_avif_quality` se pone sólo si ningún tamaño falló.

**Qué datos lee y escribe.** Option `akb_image_avif_quality` (sin fila = 60); option
`akb_image_recompress_state` (sin autoload: calidad, inicio, fin, cursor, en_proceso, total, revisadas,
reemplazados, conservados, omitidas, errores, bytes antes/después, purga pendiente/agendada/purgada);
candado `akb_image_recompress_lock`; postmeta `_akb_avif_quality`; los
archivos de tamaño de `uploads/portadas/` y su `filesize` en la metadata.

**Dónde lo opera el dueño.** Panel Akibara → Sitio → Imágenes. **Qué ve el cliente:** las mismas
portadas, más livianas. **Límite honesto:** subir la calidad después no mejora lo ya recomprimido
(el reemplazo nunca hace un archivo más pesado).

**No medido.** WordPress 7.1 puede procesar en el NAVEGADOR las imágenes que se suben desde la
biblioteca por HTTPS (`wp_is_client_side_media_processing_enabled()`), con otro codificador. Las
portadas se cargan por CLI en el servidor (`wp media import` / `eval-file`), que es lo medido.

**QA.** `tools/akibara-releases/business-qa/qa-imagenes-calidad.mjs` (espejo): pestaña, validación,
subida nueva byte a byte con la calidad guardada, botón que no duplica, decisiones sobre portadas
reales, AVIF ajeno intacto y fichas que siguen pintando su portada.

---

### SiteSpeed — la pestaña «Velocidad» (1.116.14)

Decisión del dueño del 2026-09-15, con las mediciones de `ops/informe-rendimiento-2026-09-15.md`.
Tres piezas en `src/Modules/SiteSpeed/`:

- **`CacheWarmer` — recalentado nocturno de las fichas.** Una pasada por noche desde las 00:20 hasta
  las 06:00 de la tienda (`in_window()`, `next_window_start()` con calendario de zona, no con desfases):
  después del reordenado de la vitrina (00:05, `CatalogOrder`), cuya purga total borraba lo calentado
  cuando corría a las 05:00. Trozos de 20 s por corrida de Action Scheduler
  (`akibara_business_cache_warm`, grupo `akb-cache-warm`) reagendados a +60 s; en producción la cola
  la empuja el hosting cada 5 min, que es el ritmo real. **Una petición por ficha, dos fichas a la vez**
  (celular y computador comparten copia desde las barandas de LiteSpeed 1.3.0, `cache-mobile` en false),
  con `Accept` de navegador real (`image/avif,image/webp`), DIRECTO al origen con `CURLOPT_CONNECT_TO`
  (`akibara.cl:443:127.0.0.1:443`): conserva nombre y certificado (Let's Encrypt, valida con el
  `ca-bundle.crt` de WordPress, medido en prod), no pasa por Cloudflare y el bloque «ORIGIN LOCKDOWN»
  del `.htaccess` de producción deja pasar `127.0.0.1`. Si cURL no acepta `CONNECT_TO`, la petición
  falla en vez de salir por Cloudflare. Orden: disponible y a pedido por ventas, agotado al final. La
  corrida frena ante una tanda de más de 8 s, un 5xx o una petición sin respuesta. A las 06:00 una
  pasada nocturna se corta (`cortada`); una manual que cierra ya de noche abre la nocturna en un minuto
  (`next_run_after_close()`). Candado con `OptionRow` (`akb_cache_warm_lock`), estado en `akb_cache_warm_state`
  (sin autoload), cola en `akb_cache_warm_queue`. Botón «Recalentar las fichas ahora» (a cualquier
  hora). Interruptor `akb_cache_warm_enabled` (fila ausente = encendido; apagarlo desagenda también en
  el primer guardado, `add_option_*`). Clasifica cada respuesta
  por `x-litespeed-cache`: `hit` (ya estaba), `miss` (guardada ahora), `sin_cache`, `error`.
  **Defecto cazado en el espejo antes de publicar:** un solo objeto `Hooks` compartido entre las dos
  peticiones hacía que Requests parseara dos veces la respuesta y todas daban error; ahora uno por
  petición, con candado de fuente en la prueba. El espejo cambia la ruta con el filtro
  `akibara_cache_warm_connect_to` (dentro del contenedor WordPress escucha en `wordpress:80`).
- **`SeriesCacheTags` — purga por lo que muestra la ficha.** Cada ficha lleva `akb_serie.<term_taxonomy_id>` **La cantidad (R2-6984f3e5, 1.116.26):** purga al CRUZAR el umbral de escasez (1) en los dos sentidos, porque «Arma tu pack» cuenta los tomos de copia única. Hay dos caminos y los dos están cubiertos: el formulario/REST (`stock_quantity` en `get_changes()`, contra el `_stock` aún persistido) y `wc_update_product_stock()` —ventas, anulaciones que devuelven stock, sincronizaciones—, que escribe directo en el objeto y NO aparece en `get_changes()`: ahí `remember_stock()` guarda la cantidad anterior en `…_before_set_stock` y `purge_on_stock()` compara. 20 → 19 sigue sin purgar.
  por cada serie (hook `wp`) y `akb_muestra.<id>` por cada producto que pinta en una tarjeta
  (`woocommerce_before_shop_loop_item`: relacionados, ventas cruzadas). Se purgan las series del
  producto, su etiqueta de muestra y su propia ficha (`litespeed_purge_post`) cuando:
  cambia algo visible antes de guardar (`woocommerce_before_product(_variation)_object_save` con
  `get_changes()`: nombre, estado, visibilidad, precio y rebaja, stock status, imagen…; cubre
  formulario, REST, WP-CLI, importaciones y rebajas programadas); cambia el estado de stock; la
  cantidad queda en 1 o menos (una venta que sólo baja la cantidad no purga: `wc_update_product_stock()`
  guarda el producto en cada venta y por eso NO se usa `woocommerce_update_product`); cambia la serie
  (`set_object_terms` de `pa_serie`, nueva y vieja); o se publica/retira (`transition_post_status`).
  Una vez por petición. Existe porque la ficha vive ahora hasta medianoche (search 0.1.65).
  Límites aceptados: Cloudflare guarda la ficha hasta 10 min y la purga por etiqueta no llega al
  borde; desde WP-CLI o la cola LiteSpeed encola la purga hasta la próxima petición web.
- **`GoogleAdsTag` — etiqueta de Google Ads.** Filtro `woocommerce_gla_disable_gtag_tracking`,
  enganchado en `plugins_loaded:0` desde `akibara-business.php` (Google for WooCommerce registra sus
  servicios en `plugins_loaded:20`, la misma prioridad que el arranque de este plugin: engancharse ahí
  dependería del orden de carga de los plugins). Option
  `akb_google_ads_tag`: sólo `yes` la carga; la fila ausente la apaga. Purga toda la caché al apagar
  del panel sólo si el valor cambia. El campo sólo aparece con Google for WooCommerce activo
  (`WC_GLA_VERSION`). Medido: 146 KB y ~150 ms de CPU por página en un celular de gama media. El feed
  a Merchant Center no depende de ella.

### AnnouncementBar — la barra de anuncios

> **El estado del mensaje promocional mira el interruptor maestro (R3-eafda1cc, 1.116.27).** Con «Activar»
> apagado la barra no se pinta, pero el panel seguía diciendo «se está viendo en la tienda» del mensaje
> promocional vigente. `promo_status_text()` ahora lo dice primero: «oculto — la barra completa está desactivada».

**Qué hace en una frase.** La cinta que aparece arriba del todo en **todas** las páginas,
con mensajes fijos y un mensaje promocional con fecha de caducidad que puede atarse a la
campaña de descuentos para aparecer y desaparecer solo.

**Ficheros.** `src/Modules/AnnouncementBar/AnnouncementBar.php`,
`assets/js/announcement-bar.js`, `assets/css/announcement-bar.css`.

**En qué momento actúa.**

| Hook | Prioridad | Callback | Qué hace |
| --- | --- | --- | --- |
| `wp` | 10 | `maybe_init_bar` | Si está encendida y hay mensajes, engancha el render y los assets (`:123-125`). |
| `wp_body_open` | 10 | `render_bar` | Imprime la barra, con CSS crítico en línea marcado `data-no-optimize` para que no parpadee (`:78-99` y `:169`). |
| `wp_enqueue_scripts` | 10 | `enqueue_assets` | CSS siempre; el JS sólo con dos mensajes o más. |
| `update_option_{5 base}` | 10 | `purge_cache_on_save` | Dispara `litespeed_purge_all` **en línea** (`:109-113` y `:206-208`). |
| `update_option_` y `add_option_{4 promo}` | 10 | `purge_cache_on_save` | Igual, para el carril promocional (`:117-120`). |

**Corrección del verificador:** el mapa decía «purga al guardar cualquiera de sus options».
No es así. Las **cinco options base** (`enabled`, `messages`, `bg`, `color`, `border`) sólo
escuchan `update_option_`; el `add_option_` está **únicamente** en el bucle de las
promocionales, y `delete_option_` no está en ninguna de las nueve. En una instalación
limpia, o tras un `wp option delete`, escribir el primer mensaje por línea de comandos no
purga nada.

**Qué datos lee y escribe.** `akibara_announcement_enabled` (defecto `yes`),
`akibara_announcement_messages` (un mensaje por línea), `akibara_announcement_promo_text`,
`_promo_from`, `_promo_to`, `_promo_follow_discount` y los tres colores `_bg`, `_color`,
`_border`.

**Reglas propias.** El promocional se pinta **antes** que los fijos (`:215-221`). Sólo se
resuelven los **tres shortcodes propios** dentro del texto; el resto no se ejecuta
(`:239-248`). El HTML admitido es `<a href target>`, `<strong>` y `<em>` (`:174-181`). La
ventana propia incluye ambos extremos (`:297-315`), pero si «Seguir la vigencia del
descuento» está marcado, las fechas se ignoran y manda el mismo booleano que gobierna el
precio (`:278-280`).

**Dónde lo opera el dueño.** Panel Akibara → Barra de anuncios (slug `announcement`, grupo
Sitio), con una línea de estado bajo el campo que dice si el mensaje se está viendo ahora
mismo y, si no, por qué.

**Qué ve el cliente.** La cinta superior, con rotación automática cada 6 segundos, flecha,
teclado y swipe.

**Defecto confirmado: los tres colores no se validan como colores.** Los tres campos se
declaran `'type' => 'color'` y **sin saneador propio** (`:416-433`), así que lo único que los
limpia es el `wc_clean()` genérico de WooCommerce, que quita etiquetas pero no comprueba que
el valor sea un color. Al pintar, el valor se mete tal cual dentro de un atributo `style` con
sólo `esc_attr()` (`:172`), y `esc_attr()` **no neutraliza el punto y coma**: un valor como
`red;--otra-cosa:…` cierra la declaración CSS y abre otra. No es un agujero de guion —el HTML
no se escapa de ahí—, pero sí es una vía para dejar la cinta de **todas** las páginas pintada
de cualquier manera desde un campo que no avisa de nada. La comparación está dentro de la
propia casa: `ThemeCustomizer` valida sus dos colores con `sanitize_hex_color()` (`:165`) y
`HomeHero\Settings` tiene su `sanitize_color()`. La barra es la única que no.

**Una asimetría que decide el dueño, no el código.** La cinta **rota sola cada 6 segundos**
en cuanto hay dos mensajes, mientras que el carrusel de la portada viene con el autoplay
**apagado de fábrica citando la pauta de accesibilidad WCAG 2.2.2** (`HomeHero/Settings.php:136-140`),
que es precisamente la que habla del contenido que se mueve solo. Son dos criterios opuestos
sobre lo mismo, en el mismo plugin. La barra sí trae dos mitigaciones que el capítulo no
debería callar —no arranca si el navegador pide `prefers-reduced-motion: reduce`, y se rinde
para siempre en cuanto el cliente toca la flecha, el teclado o hace swipe
(`assets/js/announcement-bar.js:97-117`)—, pero el contraste sigue en pie y **quién lo
resuelve es el dueño**: o la barra deja de rotar sola, o el hero puede rotar como ella.

---

### ShippingCopy y DiscountCopy — los tres shortcodes que evitan mentir

**Qué hacen en una frase.** Publican `[akb_envio_gratis]`, `[akb_descuento_pct]` y
`[akb_descuento_editorial]`, para que el monto de envío gratis y el porcentaje en campaña
se escriban **una sola vez** y ninguna página pueda prometer un 10 % cuando la ficha cobra
un 8 %.

**Ficheros.** `src/Modules/ShippingCopy/ShippingCopy.php`,
`src/Modules/DiscountCopy/DiscountCopy.php`.

**En qué momento actúan.** Los tres son `add_shortcode`, más `resolve_in()` para los
textos que no pasan por `do_shortcode` (la barra de anuncios). `DiscountCopy` además tira
su foto cacheada cuando cambia una option del módulo de descuentos
(`update_option_`/`add_option_`/`delete_option_` sobre `Settings::OPTIONS`, `:82-86`) o el
porcentaje de un término (`added/updated/deleted_term_meta`, `:88-90`).

**Qué datos lee y escribe.** `ShippingCopy` lee `akibara_fsp_carrier_threshold`, que es
propiedad del módulo de Envío gratis, y devuelve **cadena vacía** si no hay umbral: mejor
una frase incompleta que una promesa falsa (`:68-75`). `DiscountCopy` guarda el transient
`akb_discount_top_rule` (12 horas) con la regla de **mayor** porcentaje, incluido el caso
«no hay ninguna», para no barrer ~277 términos en cada página del sitio.

**Dónde lo opera el dueño.** En ninguna pantalla propia: escribiendo el shortcode dentro
de cualquier texto.

**Dos defectos vivos.** (1) `DiscountCopy` **no escucha** `akb_discount_max_pct`, el tope
de la tienda, mientras que `LscPurger` sí lo cubre (`LscPurger.php:59-61`). Como el
porcentaje que publica sale de `Settings::get_term_pct()`, que acota contra ese tope, bajar
el tope de 15 a 8 hace que la ficha cobre 8 % y la barra siga anunciando 15 % **hasta 12
horas**. (2) El nombre de la editorial se inserta con `preg_replace` **sin escapar el
reemplazo** (`:195-208`): en PCRE, `$` y `\` son sintaxis, así que un término llamado
«Ivrea $1 Argentina» saldría mutilado en la cinta de todas las páginas. La rama del
porcentaje, dos líneas más arriba, usa `preg_replace_callback` justamente para no tener
ese problema.

---

### TaxonomyDiscount — la pestaña «Descuentos»

**Qué hace en una frase.** Es la pantalla desde la que se monta una campaña: una tarjeta
de estado, la lista de reglas activas y un acordeón por taxonomía con una casilla de
porcentaje por cada término.

**Ficheros.** `src/Modules/TaxonomyDiscount/TaxonomyDiscount.php`,
`assets/js/taxonomy-discount-admin.js`, `assets/css/taxonomy-discount-admin.css`.

**En qué momento actúa.**

| Hook | Prioridad | Callback | Qué hace |
| --- | --- | --- | --- |
| `admin_enqueue_scripts` | 10 | `enqueue_admin_assets` | CSS y JS sólo en esta pestaña. |
| `akibara_panel_save_errors` | 10 | `validate_payload_guard` / `validate_window_dates` | **Antes de escribir nada**: bloquea el guardado entero si el formulario llegó truncado o las fechas no son reales (`:259-314` y `:368-370`). |
| `akibara_panel_form_hidden_inputs` | 10 | `render_payload_guard` | Inyecta el centinela de final de formulario. |
| `woocommerce_admin_settings_sanitize_option_*` | 10 | `sanitize_apply_to` / `sanitize_max_pct` / `sanitize_sale_badge_min_pct` | Acota cada valor a su dominio. |
| `added/updated/deleted_term_meta` | 10 | `note_term_pct_change` | Cuenta cambios **reales** de porcentaje y olvida los memos. |
| `updated_option` | 10 | `note_config_change` | Marca que cambió la configuración general (`:156`). |
| `akibara_taxonomy_discount_pct_saved` | 10 | `note_tab_saved` | Marca que esta petición guardó la pestaña. |
| `admin_notices` | 10 | `render_post_save_notice` | Pinta el aviso «Precios. Cambiaste el porcentaje de N términos…». |
| `shutdown` | 90 | `persist_save_trace` | Deja el rastro del guardado en un transient, una sola vez. |

**Qué datos lee y escribe.** Las seis options del módulo (`akibara_taxonomy_discount_*`),
el tope compartido `akb_discount_max_pct`, el umbral del sello rojo
`akb_sale_badge_min_pct`, el term-meta `_akb_taxonomy_discount_pct` y el transient
`akibara_taxonomy_discount_save_trace_{user_id}` (60 s).

**Dónde lo opera el dueño.** Panel Akibara → Descuentos (slug `taxonomy_discount`, grupo «Venta»).

**Qué ve el cliente.** Indirectamente, **todos los precios del sitio**.

**Tres problemas conocidos de esta pantalla.**
1. `note_config_change` escucha sólo `updated_option`, que WordPress **no** dispara cuando
   la fila se **crea**. Como `akibara_taxonomy_discount_enabled` no existe mientras no se
   encienda la campaña, el guardado que **estrena** la campaña —el más importante de esa
   pantalla— no explica nada y no entrega el enlace de verificación. El docblock afirma
   que el conjunto escuchado «es el MISMO» que el de `LscPurger`, y no lo es: aquél cubre
   `update_option_`, `add_option_` y `delete_option_` (`LscPurger.php:44-61`). Tampoco
   cubre `akb_sale_badge_min_pct`, que se edita en esa misma pestaña.
2. La etiqueta «Disponibles + a pedido (N)» suma `instock + onbackorder` de un
   `GROUP BY meta_value` sobre `_stock_status` (`:705-723` y `:765-777`), donde
   `onbackorder` agrupa «a pedido» **y** «preventa»; la elegibilidad real pasa por
   `Availability::of()` y **excluye las preventas** (`Settings.php:692-711`). Con cero
   preventas hoy el número coincide por casualidad.
3. El docblock de `catalog_stock_counts()` se contradice consigo mismo: la línea 731 dice
   «cacheada cinco minutos» y el comentario de `:744-753` dice «Memo por PETICIÓN, no
   transient».

---

### TaxonomyDiscount\AdminFieldType — la fila de porcentaje, y la guarda que evita 7.793 rebuilds

**Qué hace en una frase.** Pinta y guarda **cada una de las ~277 filas** de porcentaje de la
pestaña Descuentos —una por término—, monta los acordeones que las agrupan por taxonomía, y
contiene la guarda que impide que un guardado inocente reescriba las 277.

**Ficheros.** `src/Modules/TaxonomyDiscount/AdminFieldType.php`.

**En qué momento actúa.** No se engancha a WordPress en general, sino a los tipos de campo
que WooCommerce le pide pintar y guardar (`:31-42`):

| Hook | Qué hace |
| --- | --- |
| `woocommerce_admin_field_akb_taxonomy_pct_row` | Pinta una fila: etiqueta del término, casilla numérica de 0 a `max_pct()` y el recuento de productos (`:49-97`). |
| `woocommerce_update_option_akb_taxonomy_pct_row` | Guarda esa fila (`:105-148`). |
| `woocommerce_admin_field_akb_accordion_start` / `_akb_accordion_end` | Abren y cierran el `<details>` colapsable de cada taxonomía. |
| `woocommerce_admin_field_akb_filter_bar` | Pinta el buscador de términos dentro de un acordeón. |

**La guarda anti-reescritura, que es la razón de ser de esta clase.** `save_row()` compara lo
enviado con `current_pct`, **el valor con el que se pintó esa misma fila**; si coinciden, el
dueño no tocó esa casilla y **no se escribe nada** (`:140-144`). No es una optimización
cosmética. Cada escritura de `_akb_taxonomy_discount_pct` —incluso el borrado de una fila que
no existe, porque el filtro `delete_term_metadata` corre **antes** de que WordPress compruebe
si la fila estaba— dispara una publicación editorial completa en `akibara-search`. Medido en
el espejo el 2026-09-05, **un** guardado sin tocar nada subía el contador del buscador en
~277, pedía ~277 reconstrucciones y dejaba **7.793 filas pendientes y 401 bloqueadas** en la
cola de trabajo (el comentario que lo cuenta está en `:124-139`).

**Lo que sí ocurre aunque no se toque nada.** El action `akibara_taxonomy_discount_pct_saved`
se emite **para las ~277 filas en todos los casos**, se haya escrito o no (`:142` y `:147`),
porque su único consumidor —`note_tab_saved`— sólo significa «se guardó esta pestaña», no
«cambió un precio». Es lo que hace que `LscPurger` se entere de un guardado de porcentajes
aunque el panel no cuente ninguna option cambiada.

**Dónde lo opera el dueño.** Panel Akibara → Descuentos, en los acordeones por taxonomía.

**Trampa asociada.** La fila se pinta **ya acotada** contra el tope de la tienda, y esta misma
guarda decide entonces que no hay nada que guardar: es el mecanismo exacto de la trampa
descrita en `Utilities\DiscountCeiling`, más abajo.

---

### TaxonomyDiscount\Settings — qué está vivo y qué entra

> **La tarjeta «Estado» lee todo de la base (R4-83d4b7c3 y R4-198b3376, 1.116.28).** `Utilities\OptionsFromDatabase`
> lee un lote de options con UNA consulta directa esquivando la caché de objetos; `window_state_from_database()`
> trae ahora también política, alcance y cupones, y `SeriesBundles\Settings::status_snapshot_from_database()`
> hace lo mismo para la cabecera de «Arma tu pack». Antes una tarjeta podía mezclar una lectura fresca con tres
> cacheadas en el mismo render.

**Qué hace en una frase.** Guarda y lee la configuración de la campaña y el porcentaje de
cada grupo, con dos modos: el normal (rápido, usa las cachés de WordPress) y uno
**estricto** que lee en crudo de la base para el buscador.

**Ficheros.** `src/Modules/TaxonomyDiscount/Settings.php`.

**Qué decide.** La campaña está viva si el interruptor está en `yes` **y** el día de hoy
cae dentro de la ventana (`:207-223`), comparando contra el día del **sitio** —zona horaria
de Chile— y no contra UTC (`:245`). `window_covers()` es la **única** implementación de
«esta ventana cubre hoy», para que el precio y la tarjeta de estado no puedan divergir
(`:263-280`). Un producto entra por su **estado comercial**: `instock_only` = sólo
Disponible; `instock_and_onbackorder` = Disponible + A pedido; las **preventas quedan fuera
de las dos** por decisión escrita del dueño del 2026-09-05 (`:692-711`).

**Qué datos lee y escribe.** Las seis options de `Settings::OPTIONS` (`:51-58`) y el
term-meta `_akb_taxonomy_discount_pct` (`:60`), con clamp 0..tope al leer (`:616-619`) y al
guardar (`:652-664`).

**Detalle corregido por el verificador.** El mapa afirmaba que `stacks_with_coupons()` era
la **única** lectura de configuración sin rama estricta ni de foto adoptada. No lo es:
`get_from_date()` y `get_to_date()` también leen `get_option()` a secas (`:282-288`). Hoy
sólo las consume `window_state_from_database()` como respaldo, pero son públicas, así que
cualquiera puede llamarlas desde el camino estricto creyendo el inventario del mapa. El
verificador además **refutó** que esto sea un defecto vivo: para que muerda hace falta
escribir código nuevo que lo llame en modo estricto. Queda como **deuda de simetría**.

**Un riesgo de la tarjeta de Estado.** `window_state_from_database()` (`:313-362`) trae
sólo `from`, `to` y `enabled`; la política, el alcance y el acumulable de las líneas
`TaxonomyDiscount.php:453-455` caen a `get_option()`, es decir al blob compartido que el
comentario de encima describe como el problema. Y cuando la consulta falla,
`wpdb::get_results()` devuelve un array vacío, así que la guarda `! is_array()` no se
cumple y la tarjeta pinta **«Apagado / vigencia indefinida» sobre una campaña viva**. El
resto del módulo sí distingue «no hay dato» de «falló la lectura» mirando
`$wpdb->last_error` (`:575-593`).

---

### TaxonomyDiscount\DiscountResolver — la calculadora

**Qué hace en una frase.** Dado un producto, devuelve el precio final con descuento o «no
aplica», ganando el porcentaje **más alto** entre todos los grupos a los que pertenece.

**Ficheros.** `src/Modules/TaxonomyDiscount/DiscountResolver.php`.

**Cómo calcula.** `factor = (100 − pct) / 100`, y luego depende de si hay una rebaja manual
**vigente** — una oferta programada a futuro o ya vencida **no** cuenta, porque se mira
`is_on_sale('edit')` (`:307-309`):

| Política | Qué devuelve | Línea |
| --- | --- | --- |
| Sin rebaja manual vigente | `regular × factor` | `:312` |
| `skip_if_on_sale` (defecto) | `null` — se respeta la rebaja del dueño | `:316-317` |
| `from_regular` | `regular × factor` | `:319-320` |
| `stack_on_sale` («Encadenar») | **`sale × factor`** — sobre el precio **ya rebajado** | `:322-323` |
| `greatest_wins` | `min( regular × factor, sale )` | `:325-326` |

**Corrección del verificador.** El mapa afirmaba como regla de negocio nº 11 que «el
descuento se calcula SIEMPRE sobre el precio NORMAL». **Sólo tres de las cuatro políticas
lo hacen.** `stack_on_sale` calcula sobre el precio ya rebajado, y el propio texto de ayuda
del panel lo dice: «Encadenar: queda $6.400 (20 % sobre $8.000)»
(`TaxonomyDiscount.php:859` y `:865`).

**Cómo se protege.** Memoiza por producto dentro de la petición, **pero sólo si el
resultado es autoritativo**: cualquier lectura fallida envenena la instancia para toda la
petición y distingue «este producto no lleva descuento» de «no pude averiguarlo».

---

### TaxonomyDiscount\PriceFilters — el enchufe con WooCommerce

**Qué hace en una frase.** Cada vez que la tienda pide el precio de un producto, devuelve
el precio con descuento. **No escribe nada en la base.**

**Ficheros.** `src/Modules/TaxonomyDiscount/PriceFilters.php`.

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `woocommerce_product_get_price` | 10 | Devuelve el precio con descuento. |
| `woocommerce_product_get_sale_price` | 10 | Ídem para el precio de oferta. |
| `woocommerce_product_is_on_sale` | 10 | Marca el producto como «en oferta» cuando hay descuento. |
| `woocommerce_product_variation_get_price` / `_get_sale_price` | 10 | Ídem para variaciones. |
| `woocommerce_variation_prices_price` / `_sale_price` | 10 | Ídem en la tabla de precios de variación. |
| `woocommerce_get_variation_prices_hash` | 10 | Mete la firma de la configuración en el hash, para que WooCommerce no sirva un precio de variación viejo. |
| `woocommerce_get_price_html` | 20 | Reescribe el HTML como «antes tachado / ahora». |
| `woocommerce_before_calculate_totals` | 20 | Cinturón de seguridad: re-sella el precio en cada línea del carrito antes de calcular totales. |

**Sobre las tres filas de «variación».** Una **variación** es la versión concreta de un
producto que se vende con opciones —una camiseta en talla M y color negro—: el producto
«padre» agrupa y cada combinación tiene su propio precio. En Akibara **no existe ninguna**:
el catálogo es 100 % de productos simples, y el propio buscador lo deja anotado —«el catálogo
es 100% simple hoy (1503/1503)»— en `akibara-search/src/DocumentBuilder.php:295-296`. Esas
tres filas son **preventivas**: están para que el día que se venda un producto con opciones
el descuento no se quede fuera. Hoy no se ejecutan nunca, así que una prueba de QA sobre
variaciones no va a dar ningún resultado, y eso no significa que estén rotas.

**Qué ve el cliente.** El precio en listados, ficha, carrito, checkout y correos — el mismo
número en los cinco sitios.

---

### TaxonomyDiscount\CouponInteraction — la convivencia con los cupones

**Qué hace en una frase.** Decide si un cupón se puede usar cuando el carrito ya lleva
productos con descuento por editorial; por defecto **no** se acumula.

**Ficheros.** `src/Modules/TaxonomyDiscount/CouponInteraction.php`.

**En qué momento actúa.** `woocommerce_coupon_is_valid`, prioridad 10 (`:44`).

**Cómo decide.** No hace nada si la campaña está apagada o si «Acumulable con cupones»
está en `yes`. Deja pasar los cupones virtuales internos cuyo prefijo declare el filtro
`akibara_taxonomy_discount_ignored_coupon_prefixes`. Hoy el único que declara un prefijo por
ese filtro es **«Arma tu Pack»** (`SeriesBundles\BundleCouponEngine.php:112`): el módulo que
vende varios tomos de una misma serie como un lote con descuento por volumen, y que aplica su
rebaja creando un cupón interno al vuelo, no uno que el cliente teclee. Tiene su propia
pestaña en este panel —«Arma tu Pack», slug `series_bundles`, `src/Plugin.php:1393-1401`— y
se describe entero en el capítulo 03; aquí sólo aparece por dos cosas: este filtro y el tope
de descuento que comparte con la campaña por editorial. Consulta al
**mismo resolver que decide el precio**, no al porcentaje del término: un producto con
rebaja manual bajo la política «no aplicar» conserva su precio y no debe bloquear nada. Si
queda alguna línea descontada, lanza una excepción nombrando **hasta tres** productos y
ofreciendo dos salidas (`:41-77`).

**Qué ve el cliente.** El mensaje de error al aplicar un cupón en carrito o checkout.

---

### TaxonomyDiscount\LscPurger — la purga de lo que no pasa por el formulario

**Qué hace en una frase.** Vacía la caché de página cuando alguien cambia la campaña
**fuera** del panel (por línea de comandos, por un cron, por una migración), y se aparta
cuando el cambio viene del panel para no purgar el borde dos veces.

**Ficheros.** `src/Modules/TaxonomyDiscount/LscPurger.php`.

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `update_option_` / `add_option_` / `delete_option_` de las **seis** options (`:44-52`) | 10 | Programa la purga. La rama de `delete_` faltaba y costó una noche: el 2026-09-06 alguien creó y borró el tope en producción. |
| Las mismas tres sobre `akb_discount_max_pct` (`:59-61`) | 10 | Ídem: bajar el tope cambia precios servidos igual que apagar la campaña. |
| `akibara_taxonomy_discount_pct_saved` (`:62`) | 10 | Programa la purga tras guardar porcentajes. |
| `shutdown` | 99 (`:73`) | `do_purge()` dispara `litespeed_purge_all`. |

**Por qué se aparta.** `el_panel_ya_purga()` mira el centinela `$_POST['akibara_panel_save']`
(`:95-98`). Purgar el borde **no es barato** en esta tienda: LiteSpeed corre con
`cdn-cloudflare_clear`, así que cada purga total devuelve al origen las ~1.568 fichas, las
106 landings de serie, la portada y el blog; medido el 2026-08-22, una landing de serie
pasa de 48 a 1.057 ms hasta recalentarse.

**Hueco conocido.** `akb_sale_badge_min_pct` —el umbral del sello rojo, que decide si cada
tarjeta del catálogo lleva o no el «−NN %»— **no está en la lista**. Por el panel se salva
de casualidad (el contador de `AdminPanel` cuenta esa escritura), pero un
`wp option update akb_sale_badge_min_pct 1` por SSH —justo el camino que este purgador dice
cubrir— no purga nada.

---

### La autoridad de precio para el buscador

**Qué hace en una frase.** Existe para que, si hay la menor duda sobre el precio,
`akibara-business` **se niegue a dar un número** en vez de inventarlo, porque en el índice
del buscador un precio equivocado queda sellado sin que nada lo delate.

**Ficheros.** `src/Modules/TaxonomyDiscount/SearchPriceProvider.php`,
`AdoptedSnapshot.php`, `AuthoritativeReader.php`, `PriceAuthority.php`,
`PriceAuthorityUnavailable.php`.

**En qué momento actúa.** El filtro `akb_search_effective_price`, prioridad 10, registrado
desde `src/Plugin.php`.

> **Confianza de esta sección.** Es la única del capítulo que **no está verificada línea a
> línea**. El verificador del área dejó escrito que quedaron sin lectura completa
> `AuthoritativeReader.php`, `AdoptedSnapshot.php` (leyó la validación de ventana y los
> getters citados) y `SearchPriceProvider.php`, y que ahí «la cobertura es del mapa», con una
> cobertura estimada del área del 82 %. Tratándose justo del camino donde «un precio
> equivocado queda sellado sin que nada lo delate», conviene leerla como la mejor descripción
> disponible y no como hecho comprobado: **es la siguiente pasada pendiente**.

![Máquina de estados de la autoridad de precio](diagramas/06-business-panel-descuentos-textos-y-portada-estados-autoridad-precio.mmd)

**De quién son los cuatro estados.** La máquina —VACÍO, PENDIENTE, ACTIVA, FALLIDA— vive en
`SearchPriceProvider`, que las declara como constantes `STATE_EMPTY`, `STATE_PENDING`,
`STATE_ACTIVE` y `STATE_FAILED` (`:52-55`) y guarda la actual en una propiedad suya (`:59`).
`PriceAuthority` es **otra cosa y más pequeña**: un único sí/no de «sigo teniendo certeza»,
compartido por `Settings`, `DiscountResolver` y el proveedor, y **monótono** —si uno de los
tres pierde la certeza, la pierden todos, y no se recupera dentro de la misma petición
(`PriceAuthority.php:1-12` y `:26`)—. **VACÍO es el estado normal de toda la tienda**: la
máquina sólo sale de ahí cuando el buscador entrega su foto, es decir durante una
reconstrucción o una publicación editorial. Una visita de cliente nunca la mueve.
**Cómo funciona.** El buscador entrega una «foto» de la configuración (options en crudo,
zona horaria, si la ventana está activa y cuándo caduca). Business la valida **entera** y
recalcula por su cuenta `window_active` y `boundary_at`; si no coinciden con lo declarado,
rechaza. El fin de la ventana se calcula como la **medianoche del día siguiente derivada
por fecha**, no sumando 24 horas, porque el cambio de horario de Chile borra una medianoche
(`AdoptedSnapshot.php:525-548`). Después, cada cálculo lee en crudo —sin cachés de
WordPress— y comprueba al entrar y al salir que la foto sigue siendo la misma.

**Dónde vive el «token» que aparece en el caso 5.** En ninguna parte que se pueda mirar
después: es una propiedad privada del objeto `SearchPriceProvider` de **esa** petición
(`:63`), un resumen firmado de la foto adoptada. No se guarda en `wp_options`, ni en un
transient, ni en la base: buscarlo ahí para depurar no devuelve nada. Al terminar la
petición desaparece con el objeto, y la petición siguiente arranca otra vez en VACÍO. Lo
mismo vale para el `boundary_at` **que el proveedor comprueba**: el instante de caducidad sí
queda escrito, pero del lado del buscador, en su propio estado
(`akibara-search/src/EditorialDiscountPublication.php`, campo `next_boundary_at`), no del
lado de Business.

**Qué ve el cliente.** El precio que muestra el buscador y sus facetas.

---

### Utilities\DiscountCeiling — el techo de la tienda

**Qué hace en una frase.** Guarda en un solo sitio el máximo descuento que la tienda
acepta, porque el descuento por editorial y **«Arma tu Pack»** —el módulo `SeriesBundles`
presentado más arriba, en `CouponInteraction`— son el mismo número de negocio: hasta dónde se
puede bajar sin vender bajo costo. Los dos leen esta misma clase: el descuento por editorial
desde `TaxonomyDiscount\Settings`, y «Arma tu Pack» desde
`SeriesBundles\VolumeTierResolver`. Por eso bajar el tope frena las dos promociones a la vez,
y por eso esa casilla vive en la pestaña Descuentos aunque gobierne también la otra.

**Ficheros.** `src/Utilities/DiscountCeiling.php`. Lee `akb_discount_max_pct` con defecto
15 y acota a 1..90 **exigiendo forma entera canónica**: un `15%` o un `nada` caen al
defecto, no a 15 ni a 0 (`:36-42`).

**Trampa leída en el código, todavía sin medir** (la receta para reproducirla está al final
del capítulo, en «Cómo se reproduce cada defecto»). El porcentaje se acota **al leer** y la fila del panel se pinta ya
acotada. Si el dueño baja el tope de 15 a 8 para frenar una promoción, la pantalla muestra
8 en cada término, lo enviado coincide con lo pintado y la guarda anti-reescritura de
`AdminFieldType::save_row()` decide que no hay nada que guardar: **el term-meta sigue
valiendo 15 en la base**. El día que suba el tope a 20 por otra campaña, todos esos
términos vuelven solos al 15 sin que nadie haya tocado una casilla. Es un cambio de precio
sin autor (`Settings.php:616-619`, `AdminFieldType.php:52-61` y `:122-147`).

---

### LoopEnhancer y SaleDiscount — las tarjetas del listado

**Qué hacen en una frase.** Ponen en cada tarjeta los sellos que cambian la decisión de
compra (Agotado, A pedido, Preventa, el rojo con el porcentaje), arreglan el botón de
comprar, ordenan los relacionados y publican el microtexto «Disponible» bajo el precio.

**Ficheros.** `src/Modules/LoopEnhancer/LoopEnhancer.php`,
`src/Modules/LoopEnhancer/SaleDiscount.php`.

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `woocommerce_before_shop_loop_item_title` | 9 | Pinta los sellos de la tarjeta. |
| `woocommerce_single_product_summary` | 4 | Pinta los sellos en la ficha. |
| `woocommerce_loop_add_to_cart_link` | 15 y 20 | Convierte el enlace en `<button>` (no publica una URL rastreable) y pone «Avísame cuando vuelva» en agotados. |
| `woocommerce_product_related_posts_shuffle` | 10 | `__return_false`: apaga el barajado de WooCommerce. |
| `woocommerce_related_products` | 20 | Pone los comprables delante (**reordena**, no filtra: filtrar dejaría rejillas de dos tarjetas). |
| `woocommerce_get_stock_html` | 10 | Sólo avisa cuando quedan entre 2 y el umbral (3) unidades. |
| `woocommerce_get_price_html` | 30 | En agotados, un solo importe sin tachado. |
| `woocommerce_after_shop_loop_item_title` | 5 y 11 | Rating con umbral de 5 reseñas y microtexto «Disponible». (El «hueco de alineación» de prioridad 6 se retiró en 1.116.34: nunca emitía nada — R10, 2026-09-20.) |
| *(sin hook)* `remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 )` | — | Retira el rating nativo de WooCommerce del listado, para sustituirlo por el propio (`:155-156`). Es la misma trampa que la regla 44 rescata para la ficha: sin este `remove_action` la tarjeta mostraría dos filas de estrellas, y con cero reseñas en producción el duplicado es invisible. |
| `litespeed_ucss_whitelist` | 20 | Protege `.akb-notify-link` y compañeras del podado de CSS. |

**Reglas propias.** En el listado **nunca** se pinta el sello «Disponible»: el hueco se
reserva para OFERTA / A pedido / Preventa / AGOTADO, y «Agotado» suprime a los demás
(`:722-736` y `:834-860`).

El sello rojo tiene **dos umbrales según con quién comparta la tarjeta**, y conviene decirlo
sin la palabra «apilar»: cuando la tarjeta ya lleva el sello «Preventa» y el rojo se pone
**encima**, el rojo sólo aparece si el descuento supera el umbral configurado (10 % de
fábrica) — un «−1 %» junto a «Preventa» se lee como contradicción y abarata la marca. Cuando
el rojo va **solo**, el piso baja a 1 %: cualquier liquidación real muestra su sello. En el
código la diferencia es el argumento `$suppress_trivial` de `SaleDiscount::label()`, y quien
lo pone en `true` es la tarjeta que ya pintó «Preventa» (`SaleDiscount.php:90` y `:102-104`).
El aviso de escasez exige entre 2 y 3 unidades porque con 965 de 1.024 productos a una
unidad, «última unidad» no informaría de nada (`:387-406`).

**Qué datos lee y escribe.** `akb_sale_badge_min_pct` (0..90, defecto 10).

---

### PdpEnhancer y PdpSeriesEnhancer — la ficha de producto

**Qué hacen en una frase.** Ponen las estrellas bajo el título, montan la rejilla de
especificaciones con su panel de disponibilidad, evitan que «Información adicional» repita
lo mismo, y añaden la barra de navegación entre tomos de la serie.

**Ficheros.** `src/Modules/PdpEnhancer/PdpEnhancer.php`,
`src/Modules/PdpEnhancer/PdpSeriesEnhancer.php`, `assets/css/pdp-enhancer.css`,
`assets/css/pdp-series-nav.css`.

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `woocommerce_product_tabs` | 98 | Inyecta la rejilla y renombra «Información adicional» a «Especificaciones». |
| `woocommerce_single_product_summary` | 7 | Estrellas propias, que no pintan nada si no hay reseñas. |
| `woocommerce_single_product_summary` | 6 | `PdpSeriesEnhancer` pinta la barra «tomo anterior / Ver tomos / tomo siguiente». |
| `woocommerce_display_product_attributes` | 10 | Quita los atributos ya presentes en la rejilla. |
| `woocommerce_product_description_heading` | 10 | `__return_false`: sin título duplicado. |
| `woocommerce_update_product` / `_product_set_stock_status` / `set_object_terms` | 10 | Caducan la rejilla cacheada del producto (`:87-89`). |
| `woocommerce_single_product_image_thumbnail_html` | 10 | Da nombre accesible al enlace que abre la imagen principal de la galería (`:59`). |
| `wp_footer` | 10 | `render_stacked_tabs_js`: el JavaScript que apila las pestañas de la ficha en móvil, en vez de dejarlas en fila (`:79`). |
| `wp_enqueue_scripts` | 20 | Encola el CSS de la ficha sólo donde se usa; antes eran ~230 líneas de `<style>` repetidas en cada ficha (`:84`). |

**Omisión que el verificador rescató:** el módulo ejecuta
`remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10 )`
en `register()` (`:70`). Es el hook que evita **dos filas de estrellas** en toda ficha con
reseñas; con cero reseñas en producción el duplicado es invisible, así que es exactamente
el tipo de línea que alguien retira «porque no hace nada».

**Qué datos lee y escribe.** Lee `akb_copy_promise_dispatch`,
`akb_copy_promise_preventa_transit` y `akb_copy_promise_pedido_transit`. Escribe el
post-meta `_akb_pdp_specs_generation` (el testigo que caduca la rejilla) y guarda el HTML
de la rejilla en el object cache, grupo `akb_pdp`, 12 horas.

**Defecto con el copy que más se usa** (confirmado por el verificador): el hash de la clave
de caché cubre **dos** promesas de las tres — `akb_copy_promise_dispatch` y
`akb_copy_promise_preventa_transit` (`:313-319`) —. La tercera,
`akb_copy_promise_pedido_transit`, **se pinta** (`:415-416`) y **no entra en la clave**. El
docblock de esas mismas líneas explica que el hash existe porque «sin él, cambiar el copy
en el tab Textos no se reflejaría hasta expirar el TTL de 12 h». Y esa tercera promesa es
justo la que aplica a los ~398 tomos `onbackorder` del catálogo real, frente a **cero**
preventas. El dueño edita la frase, el panel purga LiteSpeed y le dice «el cambio ya está
visible», pero la purga de página **no toca** el grupo `akb_pdp` del object cache, que en
producción es persistente: la ficha sigue pintando la frase vieja hasta medio día. Conviene
no confundir las dos cachés: `litespeed_purge_all` tira el **HTML guardado de las páginas**;
la rejilla vive en la **caché de datos** (el object cache), y ésa sólo se vacía con
`wp cache flush` o cuando caduca su testigo.

**Qué hacer mientras no se arregle.** Después de editar «Disponibilidad en producto (a
pedido)» hay dos salidas, las dos inmediatas:

- **Vaciar la caché de datos entera:** `ssh akibara` y `wp cache flush`. Sirve para todas las
  fichas de golpe; a cambio, el sitio recalcula todo lo demás que tenía memorizado.
- **Tocar el producto:** abrir su ficha en la administración y pulsar Actualizar. Eso dispara
  `woocommerce_update_product`, que sube el testigo `_akb_pdp_specs_generation` y caduca la
  rejilla **de ese producto** (`PdpEnhancer.php:87`). Es lo que conviene si sólo hay que
  comprobar un tomo.

---

### ProductFaq — los datos del acordeón

**Qué hace en una frase.** Resuelve serie, editorial, autor y géneros desde las taxonomías
y compone las preguntas frecuentes de cada ficha; el tema las pinta.

**Ficheros.** `src/Modules/ProductFaq/ProductFaq.php`. Su único hook es el filtro de datos
`akibara/product_faq_data`, prioridad 10.

**Reglas propias.** Dice «manga» o «cómic» **sólo** cuando la categoría es inequívoca; si
no, usa una frase neutral. Cachea por petición y por producto.

**Corrección del verificador.** `Services\VolumeTitle` **no es una dependencia**: el `use`
está importado y no se llama en ninguna línea del fichero (`:24` es su única aparición). Es
un **import muerto**, no una arista del grafo. En el mismo fichero, `$serie_norm` y
`$serie_key` se calculan y no se leen nunca (`:98-101`), contra un post-meta que el propio
comentario documenta como inexistente en producción («cero filas, medido 2026-08-22»).

---

### HomeHero — el cartel de la portada

**Qué hace en una frase.** El cartel grande de la portada: imagen de escritorio y de móvil,
título, bajada, hasta tres botones, punto focal y hasta ocho diapositivas encadenadas.

**Ficheros y qué hace cada uno.**

| Fichero | De qué se encarga |
| --- | --- |
| `src/Modules/HomeHero/HomeHero.php` | El módulo: registra el shortcode `[akb_hero]`, el `preload` de la primera imagen y los avisos de caché. |
| `Settings.php` | El contrato de datos: forma de la option, valores por defecto, topes y saneado de todo el árbol al guardar. |
| `Renderer.php` | **Todo el HTML que ve el cliente.** Decide los tres modos (0 diapositivas = cadena vacía; 1 = cartel estático sin JavaScript; 2+ = carrusel), arma el `<picture>` con su fuente de móvil, prefiere un `.webp` adyacente si existe, pone `loading="eager"` y `fetchpriority="high"` **sólo** en la primera imagen y difiere las demás dentro de un `<template>` con `<noscript>` de respaldo, y calcula el nombre accesible de cada diapositiva (`:64`, `:163`, `:362`, `:600`, `:663`, `:687`). |
| `AdminUI.php` | **Toda la pantalla de administración.** Encola el selector de medios sólo en esta pestaña, pinta el formulario propio —un bloque por diapositiva, otro por botón y el del carrusel—, atiende la vista previa por AJAX reutilizando el mismo `Renderer` del front, y procesa el POST en `handle_save()` (`:41`, `:127`, `:176`, `:277`, `:399`, `:841`, `:875`). |
| `Migration.php` | Siembra la diapositiva inicial una sola vez, y se abstiene si la base respondió con error. |
| `assets/js/home-hero-admin.js`, `home-hero.js` | El editor (punto focal, avisos de dimensiones) y el carrusel del front. |
| `assets/css/home-hero.css`, `home-hero-admin.css` | Estilos del cartel y del editor. |

> El verificador del área declara **no haber leído** `Renderer.php` ni el resto de las
> plantillas de `AdminUI.php`. Lo de esta tabla es lectura directa del código publicado, pero
> conviene saber que esas dos piezas no pasaron por la revisión independiente.

**Por qué esta pestaña se comporta distinta a todas las demás.** «Slider de Inicio» está
registrada como **sección de acción** (`src/Plugin.php:1287-1295`): no guarda por el
formulario común del panel, sino por el suyo, que va a
`admin-post.php?action=akibara_hero_save` (`AdminUI.php:127-163`). Eso explica de golpe sus
dos riesgos: **no pasa por `akibara_panel_save_errors`**, así que no puede tener el centinela
de fin de formulario que salva a Descuentos del truncado; y **no pasa por el contador de
cambios reales del panel**, así que purga siempre en vez de purgar sólo si algo cambió. No
son dos descuidos sueltos: son la misma causa.

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `add_shortcode akb_hero` | — | 0 diapositivas = nada; 1 = cartel estático sin JS; 2+ = carrusel con scroll-snap, flechas, puntos y arrastre. |
| `wp_preload_resources` | 10 | `<link rel=preload>` de la primera imagen (es el elemento más grande de la página, el que Google mide). |
| `admin_post_akibara_hero_save` | 10 | Guarda el formulario propio: capacidad + nonce (`AdminUI.php:127-133`). |
| `wp_ajax_akb_hero_preview` | 10 | Vista previa sin guardar, con el mismo HTML del front. |
| `admin_enqueue_scripts` | 10 | Carga el selector de medios **sólo** en la pestaña `home_hero`. |
| `akibara_business_upgraded` / `admin_init` | 10 / 5 | `Migration::maybe_seed` siembra la diapositiva inicial una sola vez. |
| `attachment_updated` / `delete_attachment` / `update_option_akibara_hero_settings` | 10 | Tira el transient del WebP adyacente. |
| `litespeed_media_lazy_img_excludes` | 10 | Excluye la imagen del hero del lazy-load. |

**Qué datos lee y escribe.** La option `akibara_hero_settings`, un array versionado con
`slides[]` y `carousel{}`, y el transient `akb_hero_webp_{md5(url)}` (12 h).

**Reglas propias.** Máximo 8 diapositivas y 3 botones; intervalo 2..30 s; alto de
escritorio 200..2000 px y de móvil 30..50 vh (`Settings.php:25-26, 237, 303, 342, 347,
351`). El autoplay viene **apagado** de fábrica citando WCAG 2.2.2 (`:136-140`). El
encuadre de tablet se deriva del de móvil capando la Y al 50 %. Una diapositiva **sin
ninguna imagen se descarta al guardar**, con todo su texto y sin decirlo (`:245-251`). La
siembra inicial corre sólo si la option nunca existió y se abstiene si la base respondió
con error (`Migration.php:38-80`).

**Dos riesgos.** (1) El guardado **reemplaza la option entera**: con ocho diapositivas de
tres botones el POST supera el `max_input_vars` por defecto de PHP, y si el servidor lo
trunca las diapositivas que no llegaron desaparecen sin un solo aviso. La pestaña
Descuentos ya resolvió esto con un centinela de final de formulario; el hero no lo tiene.
(2) **Corrección del verificador:** `AdminUI::handle_save()` dispara `litespeed_purge_all`
**en línea y siempre**, haya cambiado algo o no (`:146`). Abrir el editor y pulsar
Guardar sin tocar nada devuelve al origen las ~1.568 fichas y las 106 landings — el coste
exacto que `should_purge_after_save()` y `LscPurger` existen para evitar.

**Un detalle de rendimiento.** `render_shortcode()` llama a `enqueue_assets()` **después**
de renderizar (`HomeHero.php:182-188`), y un shortcode corre durante `the_content`, es decir
después de `wp_head`: WordPress imprime esa hoja con `print_late_styles()` **en el pie**.
Mismo patrón en `HomeSeriesDestacadas.php:262` (el `wp_enqueue_style` corre dentro del render del shortcode). La barra de anuncios, en cambio,
resuelve el problema con CSS crítico en línea.

---

### HomeDestacados y HomeSeriesDestacadas — las vitrinas de la portada

**Qué hacen en una frase.** Llenan las filas de productos de la portada eligiendo con
variedad (nunca ocho tomos de la misma serie), y publican la lista de «Series más
buscadas» que enlaza a las landings que Google posiciona.

**Ficheros.** `src/Modules/HomeDestacados/HomeDestacados.php`,
`src/Modules/HomeSeriesDestacadas/HomeSeriesDestacadas.php`,
`assets/css/home-series-destacadas.css`.

**En qué momento actúan.** Son shortcodes: `[akb_destacados]`,
`[akb_preventas_destacadas]` y `[akb_series_destacadas]`. `HomeDestacados` caduca sus
vitrinas con seis hooks de producto (`woocommerce_new_product`, `_update_product`,
`_delete_product`, `_trash_product`, `_product_set_stock_status`,
`_variation_set_stock_status`), prioridad 10. `HomeSeriesDestacadas` tira su foto con
`update_option_` de sus tres options y con `woocommerce_update_product` /
`woocommerce_product_set_stock_status`.

**Qué datos lee y escribe.** `akb_home_destacados_generation` (un testigo interno sin
pantalla), los transients `akb_destacados_v1_*` y `akb_preventas_destacadas_v2_*` (1 hora),
las options `akibara_home_series_enabled`, `_title` y `_slugs`, y el transient
`akb_home_series_v1_{md5(lista)}` (6 horas).

**Reglas propias.** `HomeDestacados` selecciona **sólo los IDs** y delega el pintado al
shortcode nativo `[products ids=… orderby=post__in]`, para reutilizar la tarjeta estándar;
una segunda pasada rellena los huecos relajando el tope por serie para que no quede una
celda vacía (`:297-345`). `HomeSeriesDestacadas` admite máximo 24 series, tolera que el
dueño pegue la URL entera (`/serie/blue-lock/` → `blue-lock`) y se salta sola las series
sin tomos publicados (`:37` y `:254-257`).

**Defecto, con el síntoma corregido** (corrección del verificador): la clave del transient
se deriva **sólo de la lista completa** de slugs, no del recorte (`:295-297`). El transient
sí se comparte entre dos shortcodes con `count` distinto, pero el efecto no es el que
describía el mapa: `items()` recibe ya el `array_slice($slugs, 0, $count)` y devuelve lo
cacheado **sin volver a recortar**, así que un bloque de `count=4` pintado después de uno
de `count=12` muestra **doce** tarjetas, y al revés muestra cuatro. QA tiene que contar
tarjetas **en los dos órdenes**.

---

### Forms — el formulario de contacto

> **Respaldo sin JavaScript (R3-dc2c02a9, business 1.116.27 + tema 2.1.136).** El `<form>` del tema es ahora
> `method="post"` hacia `admin-post.php` (`action=akb_contacto`); con JS nada cambia (fetch al REST). Sin JS, antes
> el navegador hacía GET a la misma página con nombre, correo y mensaje EN LA URL y el mensaje se perdía. El
> servidor (`ContactoSubmit::handle_admin_post()`) reutiliza la MISMA lógica que el REST —`process()`: límite por
> minuto, honeypot, origen, validación, bitácora, correo— y vuelve con `?akb_contacto=ok|error`, sin datos del
> cliente en la URL. No hay nonce a propósito: el HTML se cachea 10 minutos para todos. Medido en el espejo con
> navegador sin JS: vuelve a `/contacto/?akb_contacto=ok` con «Recibido», y el correo llega a Mailpit.

**Qué hace en una frase.** Recibe nombre, correo y mensaje desde `/contacto/`, los guarda
en una bitácora y manda un correo al buzón del dueño; si el correo falla, el mensaje **no
se pierde**: aparece como alerta en el Centro de Mando.

**Ficheros.** `src/Modules/Forms/Forms.php`, `ContactoForm.php`, `ContactoSubmit.php`,
`ContactoLog.php`, `Honeypot.php`, `Settings.php`, `src/Utilities/RateLimit.php`.

| Hook / endpoint | Autorización | Qué hace |
| --- | --- | --- |
| `rest_api_init` → `POST /wp-json/akibara/v1/contacto` | Público + honeypot + origen + dos cuotas por IP | Recibe el mensaje. **Sin nonce a propósito**: la página se sirve cacheada y un nonce caducado rompía el formulario a las 12-24 h. |
| `admin_post_akb_contacto_dismiss` | `manage_woocommerce` + nonce | **Borra** las entradas que no salieron por correo. Ver el aviso de abajo. |
| `add_shortcode akb_form_contacto` | — | Incluye `template-parts/forms/contacto.php` del tema. |
| `wp_enqueue_scripts` (prioridad 10) | — | Carga JS y CSS sólo en páginas con el shortcode. |

**Qué datos lee y escribe.** Lee `akibara_forms_notify_email` (inválido cae a
`contacto@akibara.cl`, `Settings.php:29-32`). Escribe una fila `akb_contacto_log_{uuid}`
por mensaje, sin autoload, máximo 100 entradas y 4.000 caracteres por mensaje
(`ContactoLog.php:66-69`), y los contadores del limitador, que usa **dos** prefijos de clave:
`akb_rl_` para la cuota por hora (`RateLimit.php:82`) y `akb_rlm_` para la de intentos por
minuto (`:111`). Quien busque uno solo va a depurar a medias.

**El tope de 100 no se come lo pendiente (R2-8c11861a, 1.116.26).** Al pasar de 100 mensajes la
poda borra primero los **ya entregados** más antiguos; uno sin entregar sólo cae si no queda
ningún entregado que borrar. Antes se podaba por fecha a secas y el mensaje que este módulo
existe para no perder podía desaparecer por viejo.

**Cuidado con «descartar»: borra el mensaje, no la alerta.** El botón de descartar del Centro
de Mando llama a `ContactoLog::forget_undelivered()`, que recorre la bitácora y **elimina la
fila** de cada entrada que no llegó a salir por correo (`ContactoLog.php:136-143`). No marca
la alerta como leída ni archiva nada: el texto que escribió el cliente, su nombre y su correo
**desaparecen**, y no hay deshacer. Existe porque una alerta que no se puede cerrar enseña a
ignorar la tarjeta entera (`Forms.php:70-75`), pero la acción correcta antes de pulsarlo es
**leer y responder los mensajes**, no vaciarlos.

**Quién figura como remitente, y qué pasó en agosto.** El `From` de este correo —y de todos
los del sitio— no lo pone este módulo: lo fija `MailFrom`, que lo clava en
`contacto@akibara.cl` con el nombre «Akibara» por los filtros `wp_mail_from` y
`wp_mail_from_name` en prioridad 15 (`src/Modules/MailFrom/MailFrom.php:44-51`); se cuenta en
el capítulo 07. Lo que el cliente ve en «responder» es el `Reply-To`, que sí lo pone
`ContactoSubmit` con la dirección del propio cliente. Y el motivo de que la bitácora se
escriba **antes** que el correo está escrito en el código: *«en agosto de 2026 el sitio estuvo
tres días sin poder enviar ninguno»* (`ContactoSubmit.php:163-167`). Para un operador nuevo,
la regla práctica es: si dejan de llegar mensajes de contacto, el sitio no está mudo —los
mensajes están guardados—; hay que mirar **Centro de Mando → alerta roja** y responder desde
ahí.

**Omisión que el verificador rescató:** existe una option **legacy**, `akb_contacto_log`
(un array con todas las entradas dentro), y su migración a una fila por mensaje corre en la
**primera lectura** de la bitácora (`ContactoLog.php:57` y `:222-240`). Sigue siendo un
contrato vivo: un refresco de base desde un dump anterior la reintroduce, y la migración se
dispara desde el **endpoint público** de contacto, es decir por el camino anónimo, no desde
el panel.

**Coste conocido.** `record()` llama a `prune()`, que llama a `all()`, que hace
`SELECT option_value FROM wp_options WHERE option_name LIKE %s` (`:94`, `:206`, `:159-164`).
Se paga en **cada envío válido** del endpoint público.

**Dónde lo opera el dueño.** El destinatario en Textos → Formulario de contacto; la alerta
en el Centro de Mando.

---

### FrontendCompatibility y CrossOriginScripts — los parches con terceros

**Qué hacen en una frase.** Evitan que un plugin ajeno rompa o ensucie lo propio: mantienen
la analítica de producción fuera del espejo, protegen los scripts de pago del optimizador,
y hacen diagnosticables los errores de JavaScript de terceros.

**Ficheros.** `src/Modules/FrontendCompatibility/FrontendCompatibility.php`,
`assets/js/clarity-commerce-events.js`, `assets/js/joinchat-trust-clearance.js`,
`src/Modules/ScriptDiagnostics/CrossOriginScripts.php`.

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| *(sin hook)* dentro de `register()` — arranque en `plugins_loaded` 20 | — | `remove_action( 'wp_head', 'clarity_add_script_to_header' )` fuera de producción (`:44-46`). |
| `template_redirect` | 20 | Quita la imagen destacada duplicada que GeneratePress imprime sobre la ficha. |
| `option_rank_math_google_analytic_options`, `option_woocommerce_google_analytics_settings`, `woocommerce_gla_disable_gtag_tracking` | 10 | Desactivan la analítica fuera de `akibara.cl` (`:202-210`). |
| `wp_enqueue_scripts` | 30 | Encola los eventos de comercio para Clarity y el parche de Joinchat. |
| `litespeed_optimize_js_excludes` / `litespeed_optm_js_defer_exc` / `litespeed_optm_gm_js_exc` | 10 | Protegen los scripts de MercadoPago. |
| `cloudinary_lazy_load_bypass_classes` | 10 | Añade `skip-lazy`. |
| `wp_head` | **0** | **Sólo con `WP_DEBUG` activo**: sube `Error.stackTraceLimit` a 50 para que el detector de dependencias de WooCommerce 10.5+ vea al llamador real (`:73-75` y `:109-114`). |
| `script_loader_tag` | 20 | `CrossOriginScripts` marca con `crossorigin="anonymous"` seis hosts verificados uno por uno contra producción. |

**Dos omisiones que el verificador rescató.** (1) El `wp_head` prioridad 0 es el **único
hook del módulo que inyecta HTML propio en la cabecera**, y sólo existe con `WP_DEBUG`: en
el espejo Docker sale y en producción no, así que una sonda que compare el `<head>` de
local contra prod encuentra una diferencia que el mapa no explicaba. (2) El
`remove_action` de Clarity **no corre por un hook**: corre dentro de `register()`, en el
arranque del plugin. Si un día el plugin de Clarity registrase su callback **después** de
`plugins_loaded` 20, esa retirada no quitaría nada y el espejo volvería a contaminar las
grabaciones de producción sin que nada fallara visiblemente.

**Y una decisión del dueño, no un defecto técnico.** `clarity-commerce-events.js:437`
ejecuta `window.clarity('upgrade','purchase')`: **toda sesión que llega a la página de
«pedido recibido» se marca para grabación prioritaria en Clarity**. El payload sigue sin
llevar URLs, pedidos ni identificadores, pero el módulo cambia la **política de grabación**
para las sesiones de compra — grabar con prioridad las sesiones donde el cliente acaba de
teclear sus datos es una decisión que corresponde al dueño.

---

### WelcomeCoupon y Pop-ups & Promociones — captura de suscriptores y campañas estáticas

**Qué hace en una frase.** Centraliza la captación y conversión de visitantes nuevos mediante un beneficio permanente de primera compra (-10% sobre $25.000 CLP) conectado de forma agnóstica a Brevo, y permite alternar a voluntad con campañas estáticas de temporada (Cyber, Halloween, Aniversario) sin pedir correo ni romper la configuración de bienvenida.

**Ficheros.** `src/Modules/WelcomeCoupon/WelcomeCoupon.php`,
`src/Modules/WelcomeCoupon/Settings.php`,
`src/Modules/WelcomeCoupon/CouponService.php`,
`src/Modules/WelcomeCoupon/PopupRenderer.php`,
`src/Modules/WelcomeCoupon/RestController.php`,
`assets/css/welcome-popup.css`, `assets/js/welcome-popup.js`,
`mu-plugins/akibara-brevo-welcome-coupon.php` (puente agnóstico).

**Dónde lo opera el dueño.** En el menú centralizado: *Akibara → Marketing & Promociones → «Pop-ups & Promociones»*.

#### 1. Las dos modalidades del módulo

1. **Popup de Bienvenida (Primera Compra Dinámico):**
   - Ofrece un descuento configurable (por defecto 10%) con compra mínima ($25.000 CLP) y vigencia estricta de 3 días (`Settings::get_days_valid()`).
   - Genera un código de cupón único al vuelo con prefijo `HOLA-` y sufijo criptográfico aleatorio en WooCommerce (`CouponService::issue_for_email`), restringido al correo del usuario y con `usage_limit = 1`.
   - **Arquitectura agnóstica de eventos:** El plugin principal no conoce a Brevo ni a ningún proveedor externo. Emite los eventos canónicos `akb_welcome_coupon_issued`, `akb_welcome_coupon_used` y `akb_welcome_existing_buyer_subscribed`. El puente `AkibaraMarketingBridge` captura estos eventos y sincroniza las listas y automatizaciones de Brevo con tolerancia a fallos.
   - **Welcome Pivot:** Si un comprador habitual introduce su correo en el popup, el sistema no le emite un nuevo 10%: lo suscribe a novedades/preventas y le recuerda que acumula Puntos Akibara en cada compra.

2. **Popup Promocional de Temporada (Estático):**
   - Diseñado para eventos de alto impacto (Halloween, Cyber, Aniversario).
   - No solicita correo: muestra directamente el código de cupón ingresado por el dueño, con un botón interactivo de copiado y redirección en 1 clic.
   - Mientras está encendido y tiene código configurado, toma prioridad visual sobre el popup de primera compra. Al apagarlo, la bienvenida se restaura automáticamente sin perder ningún ajuste.

#### 2. Ergonomía de Conversión y Nota de Confianza (Sin Confirmshaming)

- **Eliminación del Confirmshaming:** El botón secundario de cierre («No, gracias · prefiero pagar precio normal») fue suprimido por generar fricción y ser redundante.
- **Microcopy de Confianza:** Se sustituye por una nota informativa no interactiva y transparente (`akb-welcome__trust-note`):
  *«Cero spam. Solo novedades, preventas y avisos de llegada del siguiente tomo de tu serie favorita. Puedes cancelar tu suscripción con 1 clic cuando quieras.»*
- **Cierre Unificado:** El modal se cierra limpiamente con la cruz (`[X]`), clic en el telón o tecla Escape. En caso de cierre, respeta los días de enfriamiento configurados (30 días por defecto) mediante cookie segura, y mantiene un *launcher* flotante discreto si la opción está encendida.
- **Diseño Adaptativo Móvil:** En pantallas pequeñas opera como un *Bottom Sheet* accesible en la zona del pulgar (Thumb Zone), evitando bloqueos visuales y cumpliendo con las directrices de Google contra intersticiales intrusivos.

#### 3. Política Best-Wins y Preservación de Cupones

- **Cálculo sobre Precio Normal Base:** El descuento del 10% se calcula exclusivamente contra el `regular_price` del producto (`CouponService::unit_welcome_discount_on_base_price`).
- **Respeto a Descuentos Superiores:** Si un tomo ya posee una oferta editorial/promocional igual o superior al 10% (ej. 20% OFF), el cupón aporta $0 de descuento en esa línea. Si la cesta aprovecha descuentos por volumen («Arma tu Pack», hasta 20%), prevalece el beneficio más conveniente para el cliente.
- **Bloqueo Amigable sin Quema:** Si todo el carrito ya cuenta con descuentos mayores, `assert_welcome_coupon_benefits_cart()` detiene la aplicación del cupón con un aviso claro. **El cupón no se consume (`usage_count = 0`)**; queda intacto para una compra posterior y respaldado por el correo emitido por Brevo.

#### 4. Convivencia con Akibara Rewards (Anti Double-Dipping)

- De acuerdo con el estándar global de eCommerce (Smile.io, Yotpo, Sephora), la acumulación de puntos (2 por cada $100 CLP) se liquida sobre el **desembolso neto en dinero pagado** (`subtotal - cupón - puntos redimidos`).
- Si una compra de $33.980 CLP aplica el cupón de 10% (-$3.398 CLP), el cliente paga $30.582 CLP y recibe exactamente 612 puntos. Se elimina cualquier subvención artificial de margen.

#### 5. Blindaje Anti-Fraude y Protección contra Bots

- **Cloudflare Turnstile:** Verificación invisible en frontend y validación servidor a servidor con la API de Cloudflare (`siteverify`).
- **Honeypot y Tiempo Mínimo:** Campo trampa invisible (`akb_hp_url`) y rechazo de peticiones automáticas con tiempo de llenado inferior a 800 ms.
- **Rate Limit por IP:** Límite atómico en base de datos de 3 peticiones por hora por dirección IP.
- **Normalización de Identidad:** Descarte de puntos y sufijos `+` en direcciones de Gmail.
- **Candado en Checkout:** Validación cruzada en caja que verifica que el RUT chileno (`_billing_rut`), celular o correo no hayan realizado compras previas procesadas o completadas en la tienda.

#### 6. Ciclo de Vida del Cupón ante Pasarelas y Transferencias (Des-quema ante Fallo)

- **El cupón se quema ÚNICAMENTE cuando la venta está verdaderamente pagada:** Solo transiciona a consumido (`usage_count = 1`) y notifica a Brevo (`akb_welcome_coupon_used`) en estados pagados (`processing`, `shipping-progress`, `completed`).
- **Nunca en creación ni en espera:** No se quema en `woocommerce_checkout_order_processed`, `pending` (Webpay en curso) ni `on-hold` (transferencia emitida sin verificar).
- **Des-quema y restauración automática:** Si la pasarela (Transbank Webpay, MercadoPago, Flow) rechaza la tarjeta o el cliente cancela una orden (`failed`, `cancelled`, `refunded`), `restore_order_coupon()` repone el cupón a `usage_count = 0`, limpia las marcas de orden, borra la cookie de cierre y emite `akb_welcome_coupon_restored`, permitiendo que el cliente reintente el pago inmediatamente con su descuento activo.

---

## Lo programado

**Este capítulo no programa nada.** Un `grep` de `wp_schedule_event`,
`wp_schedule_single_event` y `as_schedule_*` sobre los diecisiete módulos de esta área
—`TaxonomyDiscount`, `AdminPanel`, `AdminDashboard`, `CopyManager`, `AnnouncementBar`,
`HomeHero`, `HomeDestacados`, `HomeSeriesDestacadas`, `Forms`, `LoopEnhancer`,
`PdpEnhancer`, `ProductFaq`, `DiscountCopy`, `ShippingCopy`, `ThemeCustomizer`,
`FrontendCompatibility` y `ScriptDiagnostics`— devuelve **cero resultados**. Todo lo que
aquí ocurre pasa dentro de una petición: la de un cliente que abre una página o la del
dueño que pulsa Guardar.

Hay, eso sí, **tres cosas que se comportan como si fueran programadas** y conviene no
confundir con crons:

| Trabajo | Mecanismo | Frecuencia y hora Chile | Quién lo programa | Qué hace | Qué pasa si corre dos veces |
| --- | --- | --- | --- | --- | --- |
| Caducidad de la campaña de descuento | Comparación de fechas en cada lectura de precio (`Settings::is_within_window`, `Settings.php:263-280`) | En la primera petición del día siguiente al último de la ventana, hora del sitio (Chile) | Nadie **dentro de este capítulo**: se evalúa al vuelo. Pero sí hay una tarea agendada para ese instante, y la agenda el buscador — ver el recuadro de abajo | El resolver devuelve `null` para todos los productos y el precio vuelve al normal | Es idempotente: comparar dos veces la misma fecha da lo mismo |
| Purga de LiteSpeed tras guardar | `add_action('shutdown', …, 99)` dentro de la misma petición (`LscPurger.php:73` y `AdminPanel.php:603`) | Al final de cada guardado que cambió algo | `AdminPanel` o `LscPurger`, nunca los dos (centinela `akibara_panel_save`) | `do_action('litespeed_purge_all')` | Purgar dos veces no corrompe nada, pero devuelve al origen ~1.568 fichas y 106 landings dos veces. Por eso existe la deduplicación |
| Vigilancia de **dos o tres crons ajenos** | Lectura en `AdminDashboard::health_alerts()` al pintar el Dashboard | Cuando el dueño abre la pantalla | Nadie: es una lectura | Sobre una lista escrita a mano de dos hooks (tres con el interruptor de preventa encendido), marca desagendado o atrasado a partir de **6 horas** (`AdminDashboard.php:31` y `:88-136`) | Sin efecto: no escribe nada |

> **Corrección importante: al cruzar la medianoche SÍ hay quien mira la caché.** Versiones
> anteriores de este capítulo afirmaban que «nada purga la caché al cruzar la medianoche».
> Es falso, y en la cola de Action Scheduler se ve. `akibara-search` se agenda **una acción
> única para el instante exacto de `boundary_at`**, con el gancho
> `akb_search_editorial_discount_boundary`
> (`akibara-search/src/EditorialDiscountPublication.php:2255-2279`, la constante en `:32`, el
> enganche en `:251`). Su manejador comprueba que la campaña siga siendo la misma y llama a
> `reconcile_fingerprint()` (`:1112-1122`); como `window_active` entra en la huella de la
> configuración (`:607-608`), cruzar el borde la cambia, se materializa la deuda y eso
> dispara `litespeed_purge` + `litespeed_purge_all` en dos fases (`:1074` y `:1078` →
> `:2235-2239`). Dos matices que conviene tener a mano: **(1)** en producción el cron no lo
> empuja el tráfico —lo llama Hostinger cada 5 minutos—, así que la purga llega con hasta
> ~5 minutos de retraso; **(2)** sólo ocurre si el estado del buscador sigue siendo válido y
> coincide con lo agendado, porque si no el manejador se retira en su primera línea. Hay
> además una segunda red, anterior a todo esto: mientras la campaña está viva, el buscador
> **prohíbe guardar la página** (`litespeed_control_set_ttl` no se autoriza y la respuesta se
> marca no-store), y también la prohíbe durante las **12 horas** anteriores al borde
> (`cap_page_cache_ttl`, `:567-597`, y `authorized_page_cache_ttl`, `:636-664`). Es decir: en
> el camino normal no hay HTML guardado que pueda quedarse con el precio de ayer.

Los crons reales de la tienda —el recordatorio de preventa, la poda de la lista de espera,
la reconciliación del buscador, los de fidelidad— pertenecen a otras áreas y están en el
capítulo *Todo lo programado* (16). Recuerda que en producción el cron **no lo empuja el
tráfico**: `DISABLE_WP_CRON` está puesto y Hostinger llama a `wp-cron.php` cada 5 minutos.

---

## Casos de uso

### Caso 1 — El dueño monta una campaña de descuento por editorial

![El dueño monta una campaña de descuento](diagramas/06-business-panel-descuentos-textos-y-portada-caso-campana-descuento.mmd)

**Actor:** el dueño. **Empieza cuando** entra a WP Admin → Akibara → Descuentos para poner
un 10 % a «Ivrea Argentina» con una fecha de término.

1. Se abre la pestaña. El panel comprueba `manage_woocommerce`
   (`src/Modules/AdminPanel/AdminPanel.php:22`, `:113` y `:481-485`) y pinta la **tarjeta de
   Estado** leyendo interruptor y fechas **directamente de la base**, esquivando la caché,
   porque ésta es la pantalla que confirma que el cambio entró
   (`src/Modules/TaxonomyDiscount/Settings.php:313-362`).
   *Bifurcación silenciosa:* la política de rebaja, el alcance y el acumulable de esa misma
   tarjeta **sí** salen de la caché (`TaxonomyDiscount.php:453-455`), así que justo después
   de cambiar el alcance la tarjeta puede seguir mostrando el valor anterior.
2. Debajo aparecen «Reglas activas» y un acordeón por taxonomía con una fila por término
   (`src/Modules/TaxonomyDiscount/AdminFieldType.php` pinta cada fila).
3. Escribe `10` en la fila de la editorial, marca «Activar descuentos por taxonomía» y pone
   la fecha de término. El JS muestra la barra pegajosa «Tienes cambios sin guardar»
   (`assets/js/taxonomy-discount-admin.js`).
4. Pulsa **Guardar**. El panel verifica nonce y capacidad (`AdminPanel::handle_save`).
5. **Antes de escribir nada** corren dos validaciones por el filtro
   `akibara_panel_save_errors`: que el formulario no llegó truncado por `max_input_vars`
   (`TaxonomyDiscount.php:368-370`) y que las fechas son reales y no están invertidas
   (`:293-311`).
   - *Bifurcación:* si el formulario llegó truncado, **no se guarda nada** y se explica.
   - *Bifurcación:* si una fecha no es `AAAA-MM-DD` real, **no se guarda nada**.
6. `woocommerce_update_options()` recorre los campos. Los que no existen todavía y valen
   exactamente su defecto **no se escriben** (`AdminPanel.php:382-420`).
   *Por qué esa cautela:* el buscador guarda una **huella** —un resumen corto de la
   configuración de descuentos— para saber si su índice sigue vigente; si la huella cambia, se
   obliga a republicar. Las options de la campaña entran en esa huella, así que **crear una
   fila que antes no existía la cambia igual que cambiarle el valor**, y un guardado que
   estrena filas «vacías» encola una publicación editorial que nadie pidió.
7. Cada fila de término la guarda `AdminFieldType::save_row()`, que **no reescribe** la
   fila si lo enviado coincide con lo pintado (`:140-144`) — cada escritura de este
   term-meta dispara una publicación editorial completa en el buscador —; el resto va a
   `Settings::set_term_pct()` con clamp 0..tope. Un 0 borra la fila.
   *Lo que ocurre igual:* el action `akibara_taxonomy_discount_pct_saved` se emite **para las
   ~277 filas**, se haya escrito alguna o no (`AdminFieldType.php:142` y `:147`). Ésa es la
   razón de ser de la guarda anterior: sin ella, un guardado en el que el dueño no tocó una
   sola casilla escribía las 277 filas y dejaba 7.793 filas pendientes y 401 bloqueadas en la
   cola del buscador (medido en el espejo el 2026-09-05, `AdminFieldType.php:124-139`).
8. El panel cuenta cuántas **options** cambiaron y, si hubo al menos una, programa
   `litespeed_purge_all` en `shutdown` (`AdminPanel.php:295-301` y `:603`).
   - **Bifurcación importante:** si el dueño sólo cambió **porcentajes** y ninguna option,
     `$cambios` vale 0 y el panel **no purga**; `LscPurger` tampoco, porque se aparta al ver
     el centinela `akibara_panel_save` (`LscPurger.php:95-98`). El porcentaje es term-meta,
     y escribirlo dispara `updated_term_meta`, **nunca** `updated_option`.
9. Redirige a `?page=akibara-panel&tab=taxonomy_discount&saved=1`. El panel pinta el aviso
   verde y `TaxonomyDiscount` añade «Precios. Cambiaste el porcentaje de N términos…» con
   un enlace a `/tienda/?_v=…` para verificar.
   - **Lo que ese aviso no dice bien:** el argumento `$purged` del aviso verde se deriva
     **sólo** de la lista de secciones exentas, no de si la purga ocurrió
     (`AdminPanel.php:691-696` contra `:295-301` y `:603`). Guardar sin tocar nada en
     Textos, Descuentos o Barra de anuncios pinta «Se limpió la caché del sitio — el cambio
     ya está visible» sin que `litespeed_purge_all` se haya disparado. Y el aviso de
     Descuentos afirma con texto fijo «La purga de caché ya se lanzó»
     (`TaxonomyDiscount.php:1186-1193`) precisamente en el caso donde no se lanzó.
10. **Quién purga de verdad, y cuándo** (aquí el verificador corrigió al mapa): no es que
    nadie purgue. `akibara-search` escucha `added/updated/deleted_term_meta` en
    `PHP_INT_MAX` sobre el mismo term-meta
    (`akibara-search/src/EditorialDiscountPublication.php:244-246` y `:985-1000`), y cuando
    su publicación editorial termina el intercambio dispara `litespeed_purge` y
    `litespeed_purge_all` (`:1275-1279` y `:2235-2239`). El defecto vivo, entonces, **no es
    una caché eterna: es una ventana de minutos** —la reconstrucción disparada por guardar,
    la de ~6 minutos— durante la cual el panel ya felicitó al dueño y el cliente sigue viendo
    el precio viejo. Para QA eso cambia la prueba: hay que **medir el retardo** con dos `curl`
    separados en el tiempo, no la ausencia de purga.

    **Y mientras tanto, ¿qué hace el dueño?** Tres cosas, en este orden:
    1. **No volver a guardar.** Cada guardado vuelve a mover el term-meta y encola otra
       publicación editorial; es exactamente lo que convierte una espera de minutos en una
       cola de miles de filas.
    2. **Para verificar el precio nuevo ya**, abrir el enlace que ofrece el propio aviso:
       `/tienda/?_v=<marca de tiempo>` (`TaxonomyDiscount.php:1190`). Ese parámetro no lo tiene
       cacheado nadie, así que la página se arma de nuevo sólo para quien la abre, **sin
       purgar nada** ni devolver al origen las 1.568 fichas.
    3. **Esperar.** Cuando la publicación termina, el buscador purga solo y la URL normal
       —sin `?_v=`— pasa a servir el precio nuevo. Si a los diez minutos sigue igual, el sitio
       para mirar es el estado del buscador, no esta pantalla.
11. En paralelo, `DiscountCopy` tira su foto `akb_discount_top_rule` porque cambiaron una
    option y un term-meta vigilados.

**Bifurcación adicional.** Si el candado del buscador rechaza una escritura, el panel lo
dice por su canal aparte, nombrando el campo (transient
`akibara_eda_save_partial_{user_id}`).

**Correos:** ninguno.

**Piezas:** `AdminPanel`, `TaxonomyDiscount`, `Settings`, `AdminFieldType`, `LscPurger`,
`DiscountCopy`, `akibara-search`.

---

### Caso 2 — El cliente ve el precio con descuento en listado, ficha y carrito

![El cliente ve el precio con descuento](diagramas/06-business-panel-descuentos-textos-y-portada-caso-precio-cliente.mmd)

**Actor:** el cliente. **Empieza cuando** abre `/tienda/` o una ficha mientras la campaña
está vigente.

1. Si LiteSpeed o Cloudflare tienen la página guardada, se la sirven tal cual — con el
   precio que tuviera cuando se guardó. Sólo si hay que armarla de nuevo entra en juego lo
   que sigue.
2. WooCommerce pide el precio y dispara `woocommerce_product_get_price`; `PriceFilters` lo
   intercepta.
3. El resolver comprueba tres cosas: campaña encendida y dentro de ventana
   (`Settings::is_enabled`), el producto entra por su estado comercial
   (`Settings::is_stock_eligible`) y alguno de sus grupos tiene porcentaje. Si pertenece a
   varios grupos con descuento, **gana el más alto** (`DiscountResolver.php:419-426`).
4. Calcula `regular × (100 − pct) / 100` y redondea con los decimales de la tienda.
   - *Bifurcación:* si el producto tiene una rebaja manual **vigente**, entra la política
     elegida. Recuerda la corrección: **«Encadenar» calcula sobre el precio ya rebajado**,
     no sobre el normal (`:322-323`).
   - *Bifurcación:* una oferta programada a futuro o ya vencida **no** cuenta como oferta
     (`:307-309`).
   - *Bifurcación:* si la lectura de términos o del term-meta falla, el resolver **pierde
     la autoridad** y devuelve `null`: el cliente ve el precio normal, y se corrige solo en
     la siguiente petición.
5. El HTML del precio se reescribe como «antes tachado / ahora»
   (`woocommerce_get_price_html`, prioridad 20).
6. La tarjeta añade el sello rojo con el porcentaje calculado **sobre el precio ya
   filtrado** (`SaleDiscount`).
7. *Bifurcación:* si el producto está **agotado**, `LoopEnhancer` reescribe el precio a un
   solo importe sin tachado, en prioridad 30 — después del 20 de la campaña.
8. Al añadir al carrito, `woocommerce_before_calculate_totals` (prioridad 20) vuelve a
   sellar el precio en la línea antes de calcular totales.

**Resultado.** El precio mostrado, el del carrito y el del checkout son el mismo número,
sin haber tocado la base de datos.

**Correos:** ninguno.

**Piezas:** `PriceFilters`, `DiscountResolver`, `Settings`, `LoopEnhancer`, `SaleDiscount`.

---

### Caso 3 — El cliente intenta usar un cupón con un tomo en campaña

**Actor:** el cliente. **Empieza cuando** escribe un cupón en el carrito o en el checkout
teniendo un producto con descuento editorial.

1. WooCommerce valida el cupón y dispara `woocommerce_coupon_is_valid`
   (`CouponInteraction::maybe_invalidate`, `:44`).
2. *Bifurcación:* si la campaña está apagada o «Acumulable con cupones» está en `yes`, el
   cupón pasa.
3. *Bifurcación:* si el código empieza por uno de los prefijos internos declarados en el
   filtro `akibara_taxonomy_discount_ignored_coupon_prefixes` —hoy, sólo los cupones internos
   que crea «Arma tu Pack»—, pasa.
4. Recorre el carrito y pregunta **al mismo resolver que decide el precio** si cada línea
   lleva descuento — no al porcentaje del término.
5. *Bifurcación:* con cero líneas descontadas el cupón pasa, aunque la campaña esté viva.
6. Si hay al menos una línea descontada, lanza una excepción con hasta tres nombres de
   producto y dos salidas: quitar esos tomos, o quedarse con el descuento editorial sin
   cupón (`:41-77`).

**Correos:** ninguno. **Piezas:** `CouponInteraction`, `DiscountResolver`.

---

### Caso 4 — La campaña caduca sola al pasar la última fecha

**Actor:** el sistema (en realidad, el reloj). **Empieza cuando** el día del sitio pasa a
uno posterior a «Vigencia hasta».

1. En la siguiente petición, `Settings::is_enabled()` llama a `is_within_window()`, que
   compara el día de **hoy** del sitio contra la ventana, **ambos extremos incluidos**
   (`:263-280`).
2. El resolver devuelve `null` para todos los productos y el precio vuelve al normal sin
   que nadie toque nada.
3. *Bifurcación:* si el mensaje promocional de la barra está marcado «Seguir la vigencia
   del descuento», deja de pintarse el mismo día (`AnnouncementBar.php:278-280`).
4. Del lado del buscador, la foto de configuración declara un `boundary_at` —la medianoche
   del día siguiente al último, derivada **por fecha** y no sumando 24 horas— para que el
   cambio de horario de Chile no adelante ni atrase la caducidad
   (`AdoptedSnapshot.php:525-548`).
5. *Bifurcación:* con las dos fechas vacías la vigencia es indefinida y sólo manda el
   interruptor.

6. **Y del lado de la caché sí hay quien actúa.** El buscador se había agendado una acción
   única de Action Scheduler para ese instante exacto
   (`akb_search_editorial_discount_boundary`,
   `akibara-search/src/EditorialDiscountPublication.php:2255-2279`). Al dispararse comprueba
   que la campaña sigue siendo la que agendó y reconcilia la huella; como `window_active`
   forma parte de esa huella, el cambio se materializa y con él salen `litespeed_purge` y
   `litespeed_purge_all` (`:1112-1122` → `:2235-2239`).
   - *Bifurcación:* si el estado del buscador ya no coincide con lo agendado —otra campaña,
     otra huella, otro instante—, el manejador se retira sin hacer nada.
   - *El retraso real:* en producción las tareas las empuja Hostinger cada 5 minutos, así que
     la purga puede llegar hasta ~5 minutos después de la medianoche.

**Lo que casi nunca hace falta.** Antes de todo eso hay una red más temprana: mientras la
campaña está viva —y también durante las **12 horas** previas al borde— el buscador **no
autoriza guardar la página** y la respuesta sale marcada para no almacenarse
(`cap_page_cache_ttl`, `:567-597`, y `authorized_page_cache_ttl`, `:636-664`). Por eso, en el
camino normal, al cruzar la medianoche no hay HTML guardado que pueda quedarse con el precio
de ayer. La purga agendada es el cinturón, no el único tirante. Quien quiera comprobarlo
tiene dónde mirar: **la cola de Action Scheduler**, buscando el gancho
`akb_search_editorial_discount_boundary`.

**Correos:** ninguno. **Piezas:** `Settings`, `DiscountCopy`, `AnnouncementBar`,
`AdoptedSnapshot`, `akibara-search`.

---

### Caso 5 — El buscador publica el precio efectivo de cada producto

**Actor:** el sistema. **Empieza cuando** `akibara-search` reconstruye su índice o hace una
publicación editorial.

1. Search captura su propia foto de la configuración (options en crudo, zona horaria,
   ventana activa, instante de caducidad) y se la entrega a Business
   (`SearchPriceProvider::adopt_search_snapshot`).
2. Business la valida **entera**: forma, dominio de cada option, zona horaria canónica, y
   recalcula por su cuenta `window_active` y `boundary_at`.
   - *Bifurcación:* si no coinciden con lo declarado, **rechaza** la foto.
3. Devuelve un token y el estado pasa a **PENDIENTE**.
4. Para cada producto, Search aplica `akb_search_effective_price`. En la primera invocación
   el resolver queda ligado a la foto y entra en **modo estricto** (estado **ACTIVA**).
5. El cálculo lee relaciones producto-término por JOIN y el term-meta en crudo, sin tocar
   las cachés de WordPress (`AuthoritativeReader`).
6. Antes y después de cada cálculo se comprueba que la foto sigue siendo la misma, que
   nadie invalidó la autoridad y que no se cruzó `boundary_at`.
   - *Bifurcación:* si algo no cuadra, pasa a **FALLIDA** —de donde no se vuelve en esa
     petición— y lanza `PriceAuthorityUnavailable` en vez de devolver el precio base.
   - *Bifurcación:* adoptar una foto **distinta** de la vigente en la misma petición falla
     de forma pegajosa.

**Por qué el contrato es distinto del de la tienda.** En el storefront un fallo muestra el
precio base y se corrige solo en la siguiente visita; aquí un fallo quedaría **sellado en
el índice** sin que nada lo delate.

**Correos:** ninguno. **Piezas:** `SearchPriceProvider`, `AdoptedSnapshot`,
`AuthoritativeReader`, `PriceAuthority`, `DiscountResolver`, `Plugin`.

---

### Caso 6 — El dueño anuncia la promoción en la barra de anuncios

![El dueño anuncia la promoción en la barra de anuncios](diagramas/06-business-panel-descuentos-textos-y-portada-caso-barra-anuncios.mmd)

**Actor:** el dueño. **Empieza cuando** entra a Akibara → Barra de anuncios.

1. Escribe `[akb_descuento_pct] en [akb_descuento_editorial]` en «Mensaje promocional (con
   vigencia)» y marca «Seguir la vigencia del descuento».
2. Debajo del campo lee la línea de estado, calculada **al pintar la pantalla**: «Ahora
   mismo: se está viendo en la tienda» / «oculto — no hay ningún descuento por editorial
   vigente» (`AnnouncementBar::promo_status_text`).
3. El contador del panel le dice cuántas líneas hay y cuál es la más larga, avisando en
   rojo las que superan ~40 caracteres (`assets/js/admin-panel.js`).
4. Guarda. Al escribirse la option se dispara `update_option_akibara_announcement_promo_text`
   y la barra purga LiteSpeed **en línea** (`:206-208`); el panel programa **otra** purga en
   `shutdown` porque la sección no está exenta.
   - *Bifurcación deducida del código, todavía sin medir:* un guardado que toque el mensaje,
     el promocional y los tres colores debería disparar **cinco** purgas sincrónicas dentro de
     la petición, más la del panel en `shutdown`. El verificador del área dejó esto como
     «esperado hoy: 4-5 en línea + 1 en `shutdown`», no como cifra medida, y su receta para
     comprobarlo está al final del capítulo, en «Cómo se reproduce cada defecto». `LscPurger` deduplica, difiere a `shutdown` y se aparta cuando el POST viene
     del panel; la barra de anuncios no hace ninguna de las tres — y es la pestaña que más
     se guarda.
5. En el front, la barra compone la lista: primero el promocional vigente, después los
   fijos.
6. Los tres shortcodes propios se resuelven con el valor **vivo**; el resto del texto no
   ejecuta shortcodes.
7. El HTML se filtra con lista blanca y se pinta con CSS crítico en línea para que no
   parpadee.
8. Con dos mensajes o más se carga el JS: rota sola cada 6 s, se detiene con el puntero
   encima o con el foco dentro, y se rinde para siempre en cuanto el cliente toca la flecha,
   el teclado o hace swipe (`assets/js/announcement-bar.js:98-170`).

**Bifurcaciones.** Si «Seguir la vigencia» está marcado, las fechas de abajo se ignoran; si
no, manda la ventana propia. Con el texto promocional vacío la barra vuelve a mostrar sólo
los fijos.

**Riesgo conocido.** Las fechas del promocional **no se validan**: los campos son
`'type' => 'date'` sin `default` y sin ningún saneador propio (`:398-415`), WooCommerce los
guarda con `wc_clean()` y `within_window()` compara cadenas (`:310`). Con `to = 'ayer'` la
comparación `'2026-09-11' > 'ayer'` es falsa y **el banner no caduca nunca**. El módulo de
Descuentos sí blinda esto con `validate_window_dates()`.

**Correos:** ninguno. **Piezas:** `AnnouncementBar`, `DiscountCopy`, `ShippingCopy`,
`AdminPanel`.

---

### Caso 7 — Un cliente escribe por el formulario de contacto

![Un cliente escribe por el formulario de contacto](diagramas/06-business-panel-descuentos-textos-y-portada-caso-contacto.mmd)

**Actor:** el cliente. **Empieza cuando** rellena nombre, correo y mensaje en `/contacto/`.

1. El JS del tema hace `POST /wp-json/akibara/v1/contacto` **sin nonce**.
2. WordPress valida el esquema y corta con **400** si un campo excede su largo (nombre 120,
   correo 120, mensaje 4000).
3. El handler comprueba el tope de **20 intentos por minuto y por IP**; si se pasa, **429**
   (`ContactoSubmit.php:91`, `RateLimit.php:30-31`). Los administradores están exentos
   (`RateLimit.php:76`).
4. *Bifurcación:* si el campo trampa viene relleno, responde **200 «todo bien»** y descarta
   en silencio — el bot no aprende (`:100-102`).
5. Comprueba el origen: con cabecera `Origin`/`Referer` de otro dominio, **403**; sin
   cabecera, se permite (`:104-110`).
6. *Bifurcación:* si falta **alguno de los tres campos** —nombre, correo o mensaje—, responde
   **400 «Nombre, email y mensaje son requeridos.»** (`:127-133`). Es el corte que más se ve
   al depurar el formulario, y corre **antes** que el del correo inválido. Ojo con el orden
   interno: el correo se mide **en crudo**, tal como lo tecleó el cliente, y no después de
   sanearlo, porque `sanitize_email()` **vacía** lo que está mal formado; medirlo saneado
   hacía que «juan@correo» se contase como campo vacío y que el 400 de correo inválido de más
   abajo fuera código inalcanzable (el comentario lo explica en `:113-121`).
7. *Bifurcación:* si sanear el correo lo **vacía o lo cambia** (un espacio en medio, una
   tilde), responde **400 «Email inválido»** en vez de mandar la respuesta al buzón de otra
   persona (`:144-150`).
8. Recién ahí cobra la cuota de **3 envíos válidos por hora y por IP**; si se pasa, **429**
   (`:155-162`). Se cobra tan tarde a propósito: sólo los envíos que se van a notificar gastan
   cuota.
9. Guarda el mensaje en su propia fila de options **antes** de intentar el correo
   (`ContactoLog::record`, `:167`).
10. Manda el correo a la dirección configurada en Textos. Sale **inmediatamente, en la misma
    petición**, por `wp_mail()`, con `Reply-To` saneado de comas y punto y coma. El `From` lo
    fija `MailFrom`: siempre `Akibara <contacto@akibara.cl>`.
11. *Bifurcación:* si el correo salió, marca la entrada como entregada. Si no salió pero la
    bitácora sí guardó, el cliente igual recibe «Mensaje recibido» y el mensaje aparece como
    **alerta roja** en el Centro de Mando. Sólo si fallan los dos responde **503** con la
    dirección de contacto escrita (`:174-181`).

**Correos que salen:**

| Asunto | Cuándo | De quién | A quién |
| --- | --- | --- | --- |
| `Contacto: <las 8 primeras palabras del mensaje>` | Inmediato, dentro de la misma petición del cliente | `Akibara <contacto@akibara.cl>`, fijado por `MailFrom` (capítulo 07); el `Reply-To` es el correo del cliente | `akibara_forms_notify_email` — por defecto `contacto@akibara.cl` |

**Si el correo no sale.** El mensaje no se pierde: ya está guardado. Aparece como alerta roja
en **Centro de Mando**, con el nombre, el correo y el texto del cliente. Es el escenario para
el que existe la bitácora, y no es hipotético: en agosto de 2026 el sitio pasó **tres días
sin poder enviar ni un correo** (`ContactoSubmit.php:163-167`).

**Piezas:** `Forms`, `ContactoSubmit`, `ContactoLog`, `RateLimit`, `AdminDashboard`,
`CopyManager`.

---

### Caso 8 — El dueño cambia un texto de la tienda

**Actor:** el dueño. **Empieza cuando** entra a Akibara → Textos y edita una frase, por
ejemplo «Despacha en 24-48 h».

1. El panel pinta las **ocho** secciones con sus **50** campos.
2. Al guardar, cada texto pasa por su saneador: sin etiquetas HTML, saltos de línea
   aplanados y corte a 300 caracteres (`Utilities/Text.php:37` y `:65-80`).
3. *Bifurcación:* los textos declarados obligatorios que queden vacíos vuelven al literal de
   fábrica (`CopyManager.php:94-117`); los dos del pie **sí** aceptan el vacío, que es como
   se apaga esa línea; y **once** no están protegidos ni al guardar ni al leer, y publican
   una etiqueta en blanco.
4. *Bifurcación:* un texto con un `%` **nunca** se pasa por `sprintf` —un `%` suelto sería un
   error fatal—: el marcador se sustituye con `str_replace` y, si el dueño lo borró, el dato
   se añade al final (`CustomerVisibility.php:339-345`, `EditionCopy.php:76-86`).
5. El panel cuenta los cambios reales y purga la caché del sitio: la pestaña `copys` **no**
   está exenta (`AdminPanel.php:57-61`).
6. En el front, cada superficie lee su option. La ficha la relee al recomponer su rejilla,
   cuya clave de caché incluye un hash de las promesas — **pero sólo de dos de las tres**,
   así que cambiar «Disponibilidad en producto (a pedido)» puede tardar **hasta 12 horas**
   en verse, aunque el panel felicite al instante. El carrito y los correos la leen en el
   momento.

**Correos:** ninguno. **Piezas:** `CopyManager`, `Utilities\Text`, `AdminPanel`,
`PdpEnhancer`, `EstimatedDelivery\BannerRenderer`, `OrderPreventa\PreventaItems`.

---

### Caso 9 — El dueño arma el cartel de la portada

![El dueño arma el cartel de la portada](diagramas/06-business-panel-descuentos-textos-y-portada-caso-hero.mmd)

**Actor:** el dueño. **Empieza cuando** entra a Akibara → Slider de Inicio.

1. Al abrir esa pestaña —y **sólo** esa— se cargan el selector de medios de WordPress, el
   selector de color y el editor propio.
2. Elige imagen de escritorio y de móvil; el JS compara las dimensiones con las recomendadas
   (1920×811 y 768×1377, tolerancia 8 %) y **avisa sin bloquear**.
3. Hace clic sobre la miniatura para fijar el punto focal; el encuadre de tablet se deriva
   del de móvil capando la Y al 50 %.
4. Escribe título, bajada y hasta tres botones, y elige la posición del texto en una rejilla
   de 3×3.
5. Puede pedir una **vista previa por AJAX** que renderiza exactamente el mismo HTML del
   front con los tokens reales del tema (`AdminUI::ajax_preview` → `Renderer`).
6. Guarda: el formulario va a `admin-post.php?action=akibara_hero_save`, que comprueba
   capacidad y nonce, sanea todo el árbol y escribe la option **completa**
   (`AdminUI.php:127-141`, `Settings::save`).
   - *Bifurcación:* una diapositiva **sin ninguna imagen se descarta**, con título, bajada y
     botones incluidos, sin aviso ni bloqueo.
   - *Bifurcación no protegida:* si PHP truncó el POST por `max_input_vars`, las
     diapositivas que no llegaron **desaparecen para siempre** sin un solo aviso.
7. Purga LiteSpeed **siempre**, haya cambiado algo o no (`:141-146`), y redirige. Si pulsó
   «Guardar y abrir en el sitio», el JS abre la portada en otra pestaña.
8. En el front, `[akb_hero]` pinta el cartel y añade el `preload` de la primera imagen; con
   dos o más diapositivas carga el JS del carrusel. Las diapositivas 2+ van diferidas dentro
   de un `<template>`, con `<noscript>` de respaldo.

**Correos:** ninguno. **Piezas:** `HomeHero`, `AdminUI`, `Settings`, `Renderer`,
`Migration`, `AdminPanel`.

---

### Caso 10 — El cliente llega a una tarjeta agotada

**Actor:** el cliente. **Empieza cuando** ve en el listado un tomo sin stock.

1. La tarjeta resuelve sus sellos: **«Agotado» manda y suprime a los demás**.
2. El precio se reduce a un solo importe, sin tachado.
3. *Bifurcación:* el botón de comprar se reemplaza por «Avísame cuando vuelva», que lleva al
   formulario de lista de espera de la ficha, **sólo si la captura está operativa** (filtro
   `akibara_loop_waitlist_active`). Nunca se promete un aviso que no se va a mandar.
4. *Bifurcación:* si la lista de espera está apagada, queda el botón muerto «Agotado» con su
   descripción accesible corregida.
5. En la ficha, la rejilla de especificaciones no pinta el panel de disponibilidad y el
   texto de stock nativo queda vacío.

**Correos:** ninguno (el de «ya llegó» pertenece a otra área). **Piezas:** `LoopEnhancer`,
`PdpEnhancer`, `BackInStock`.

---

## Cómo se reproduce cada defecto en el espejo

Todos los defectos de este capítulo salieron de **leer el código**, no de medirlos en vivo:
el verificador del área lo dejó escrito («ninguna de mis sospechas nuevas se midió en vivo:
son lectura de código con su receta de medición en el espejo»). Eso significa que ninguno
está **aprobado** todavía en el sentido que exige la casa: primero se reproduce en el espejo
Docker y después, cuando el arreglo esté en producción, se vuelve a medir allá.

Estas son las recetas, una por defecto vivo. El espejo responde en `http://localhost:8080` y
las órdenes de WordPress se dan con `docker compose exec -T cron wp …`. Las sondas van
marcadas con `-A AkibaraQA`, que es como se distingue el tráfico de pruebas del de clientes.

| Defecto | Cómo reproducirlo |
| --- | --- |
| **La promesa «a pedido» tarda 12 h en verse** (ficha de `PdpEnhancer`) | Abrir una ficha `onbackorder` que **no** sea preventa y anotar la frase del panel «A pedido». `wp option update akb_copy_promise_pedido_transit 'PRUEBA A PEDIDO'`. Recargar con cache-buster: `curl -s -A AkibaraQA 'http://localhost:8080/<ficha>/?_v=1'` — sigue la frase vieja. Contrastar con `wp option update akb_copy_promise_dispatch 'PRUEBA STOCK'` en una ficha **en stock**, que sí cambia a la primera. Cerrar con `wp cache flush` para ver que entonces sí aparece. |
| **La barra purga el borde varias veces por guardado** (caso 6) | Poner en el espejo un mu-plugin de pruebas con `add_action('litespeed_purge_all', function(){ error_log('PURGA ' . microtime(true)); });`. Guardar la pestaña Barra de anuncios cambiando el texto **y** los tres colores, y contar líneas en el log (esperado: 4-5 en línea + 1 en `shutdown`). Repetir guardando **sin tocar nada** (esperado: 0). Repetir en Slider de Inicio sin tocar nada (esperado: 1, la de `AdminUI`). |
| **Estrenar la campaña no pinta el aviso de precios** (`TaxonomyDiscount`, problema 1) | `wp option delete akibara_taxonomy_discount_enabled`. Abrir Akibara → Descuentos, marcar «Activar descuentos por taxonomía» y Guardar: **no** aparece la caja azul «Precios. …». Repetir con la fila ya creada (desmarcar y volver a marcar): ahí sí aparece. |
| **Bajar el tope no borra los porcentajes** (`DiscountCeiling`) | Poner un término al 15 % y comprobarlo: `wp term meta get <term_id> _akb_taxonomy_discount_pct` → 15. `wp option update akb_discount_max_pct 8`; abrir la pestaña (la fila dice 8) y pulsar Guardar. Volver a `wp term meta get`: **sigue 15**. `wp option update akb_discount_max_pct 20` y recargar: la fila vuelve a decir 15 y el precio servido baja otra vez. |
| **El nombre de la editorial se mutila en la cinta** (`DiscountCopy`) | `wp term update product_brand <id> --name='Ivrea $1 Argentina'`, ponerle porcentaje, encender la campaña, escribir `[akb_descuento_editorial]` en el mensaje promocional y cargar la portada: el nombre sale truncado. **Restaurar el nombre original contándolo**, que es un dato real del catálogo. |
| **La tarjeta de Estado dice «Apagado» sobre una campaña viva** (`Settings`) | Forzar el fallo de la consulta con un mu-plugin de pruebas que enganche `query` y devuelva error para el `SELECT option_name, option_value FROM wp_options WHERE option_name IN (…)`. Abrir Akibara → Descuentos con la campaña encendida y leer la tarjeta; contrastar con `wp option get akibara_taxonomy_discount_enabled`. |
| **El umbral del sello rojo no purga nada por SSH** (`LscPurger`) | Poner un 5 % a una editorial y el umbral en 10 (sin sello). Calentar `/tienda/`. `wp option update akb_sale_badge_min_pct 1`. Recargar **sin** cache-buster y contar sellos: `curl -s -A AkibaraQA http://localhost:8080/tienda/ \| grep -c 'akb-badge--sale'`; comparar con la misma URL con `?_v=1`. |
| **Guardar sólo porcentajes no purga en esa petición** (caso 1, paso 8) | Con el mismo mu-plugin del log de purgas: guardar la pestaña Descuentos cambiando **sólo** una casilla de porcentaje. Esperado: 0 purgas en la petición, y la purga real llegando minutos después desde el buscador. Medir el retardo con dos `curl` separados en el tiempo sobre la misma ficha. |
| **El hero se trunca en silencio** (caso 9) | Bajar `max_input_vars` de PHP en el espejo (p. ej. a 200), cargar el editor con ocho diapositivas de tres botones y pulsar Guardar. Contar las diapositivas que quedan en `wp option get akibara_hero_settings --format=json`. Restaurar el valor después. |
| **La analítica puede apagarse en producción** (regla 58) | En el espejo, poner el host a `akibara.cl` en `/etc/hosts` y dejar `WP_ENVIRONMENT_TYPE` sin `production`: comprobar que el `<head>` **no** trae el script de Clarity. Repetir con `production`: aparece. La prueba tiene que mirar **las dos** condiciones, nunca una sola. |

Lo que **sí** hay que medir en producción, después de desplegar: el retardo real entre
guardar un porcentaje y ver el precio nuevo en `/tienda/` sin cache-buster, y la presencia de
la acción `akb_search_editorial_discount_boundary` en la cola de Action Scheduler cuando hay
una campaña con fecha de término. Ninguna de las dos cosas se puede dar por buena desde el
espejo: el cron de producción lo empuja Hostinger cada 5 minutos y el borde es Cloudflare.

---

## Reglas de negocio

Sólo entran las que están **escritas en el código**, con fichero y línea. Las rutas son
relativas a `public_html/wp-content/plugins/akibara-business/`.

| # | Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
| --- | --- | --- | --- | --- |
| 1 | La campaña está viva sólo si el interruptor está en `yes` **y** hoy cae dentro de la ventana. | `src/Modules/TaxonomyDiscount/Settings.php:207-223` | sí | Panel → Descuentos → Configuración general |
| 2 | La ventana incluye **ambos extremos**: el último día todavía descuenta. Sin fechas, vigencia indefinida. | `src/Modules/TaxonomyDiscount/Settings.php:263-280` | sí | Panel → Descuentos (Vigencia desde / hasta) |
| 3 | El defecto de **lectura** del interruptor es `no`: sin fila escrita, no hay campaña. | `src/Modules/TaxonomyDiscount/Settings.php:217` | sí | Panel → Descuentos |
| 4 | La vigencia se evalúa con el día del **sitio** (Chile), no con UTC. | `src/Modules/TaxonomyDiscount/Settings.php:245` | no | Ajustes de WordPress → Zona horaria |
| 5 | El fin de ventana que declara la foto del buscador es la medianoche del día **siguiente**, derivada por fecha y no sumando 24 h. | `src/Modules/TaxonomyDiscount/AdoptedSnapshot.php:525-548` | no | — |
| 6 | Las **preventas** quedan fuera del descuento tanto en «Sólo disponibles» como en «Disponibles + a pedido». Decisión del dueño, 2026-09-05. | `src/Modules/TaxonomyDiscount/Settings.php:692-711` | sí | Panel → Descuentos → Aplicar a productos según stock |
| 7 | El alcance por defecto es «Sólo productos disponibles (en stock)». | `src/Modules/TaxonomyDiscount/Settings.php:403` | sí | Panel → Descuentos |
| 8 | Ante una rebaja manual vigente, la política por defecto es **no** aplicar la regla de taxonomía. | `src/Modules/TaxonomyDiscount/Settings.php:384` y `DiscountResolver.php:315-328` | sí | Panel → Descuentos → Política para productos con precio rebajado manual |
| 9 | Una oferta programada a futuro o ya vencida **no** cuenta como oferta. | `src/Modules/TaxonomyDiscount/DiscountResolver.php:307-309` | no | — |
| 10 | Con varios grupos en campaña, gana el porcentaje **más alto**. | `src/Modules/TaxonomyDiscount/DiscountResolver.php:419-426` | no | — |
| 11 | **Tres** de las cuatro políticas calculan sobre el precio normal (`regular × (100−pct)/100`); **«Encadenar» calcula sobre el precio ya rebajado** (`sale × factor`). | `src/Modules/TaxonomyDiscount/DiscountResolver.php:312` y `:322-323` | sí (eligiendo política) | Panel → Descuentos |
| 12 | Ningún porcentaje supera el tope de la tienda; el tope se acota a 1..90 exigiendo forma entera canónica, defecto 15. | `src/Utilities/DiscountCeiling.php:36-42` y `Settings.php:530-543` | sí | Panel → Descuentos → Descuento máximo de la tienda (%) |
| 13 | El porcentaje de un término se acota a 0..tope al guardar; un **0 borra la fila**. | `src/Modules/TaxonomyDiscount/Settings.php:652-664`, `AdminFieldType.php:122` | no | — |
| 14 | Por defecto los cupones **no** se acumulan: con una línea descontada el cupón se rechaza nombrando hasta tres productos. | `src/Modules/TaxonomyDiscount/CouponInteraction.php:41-77` | sí | Panel → Descuentos → Acumulable con cupones |
| 15 | Guardar Descuentos con una fecha que no sea `AAAA-MM-DD` real, o con la ventana invertida, **no guarda nada**. | `src/Modules/TaxonomyDiscount/TaxonomyDiscount.php:293-311` | no | — |
| 16 | Si PHP trunca el formulario de Descuentos, **no se guarda ningún descuento**. | `src/Modules/TaxonomyDiscount/TaxonomyDiscount.php:368-370` | no | — |
| 17 | Una fila de término cuyo valor enviado coincide con el pintado **no se reescribe**. | `src/Modules/TaxonomyDiscount/AdminFieldType.php:140-144` | no | — |
| 18 | Guardar una pestaña no puede crear filas de options que nadie tocó. | `src/Modules/AdminPanel/AdminPanel.php:382-420` | no | — |
| 19 | Sólo se purga la caché si el guardado cambió algo **y** la pestaña alimenta alguna superficie cacheada; exentas: `address`, `native_cart`, `free_shipping_progress`. | `src/Modules/AdminPanel/AdminPanel.php:57-61` y `:295-301` | no | — |
| 20 | Todo el panel exige `manage_woocommerce`; una sección puede exigir más y entonces desaparece del nav y su POST se rechaza. | `src/Modules/AdminPanel/AdminPanel.php:22`, `:113`, `:481-485` | no | — |
| 21 | La barra de anuncios viene **encendida** de fábrica; apagarla conserva los mensajes. | `src/Modules/AnnouncementBar/AnnouncementBar.php:124` | sí | Panel → Barra de anuncios |
| 22 | El mensaje promocional se pinta **antes** que los fijos. | `src/Modules/AnnouncementBar/AnnouncementBar.php:215-221` | no | — |
| 23 | Con «Seguir la vigencia del descuento» marcado, el promocional ignora sus fechas y usa el mismo booleano que el precio. | `src/Modules/AnnouncementBar/AnnouncementBar.php:278-280` | sí | Panel → Barra de anuncios |
| 24 | La ventana del promocional también incluye ambos extremos. | `src/Modules/AnnouncementBar/AnnouncementBar.php:297-315` | sí | Panel → Barra de anuncios |
| 25 | El texto de la barra resuelve **sólo** los tres shortcodes propios. | `src/Modules/AnnouncementBar/AnnouncementBar.php:239-248` | no | — |
| 26 | El HTML admitido en un mensaje de la barra es `<a href target>`, `<strong>` y `<em>`. | `src/Modules/AnnouncementBar/AnnouncementBar.php:174-181` | no | — |
| 26b | Los tres colores de la barra **no se validan como colores**: se declaran `'type' => 'color'` sin saneador y viajan a un atributo `style` con sólo `esc_attr()`, que no neutraliza el punto y coma. `ThemeCustomizer` y `HomeHero` sí los validan. | `src/Modules/AnnouncementBar/AnnouncementBar.php:416-433` y `:172` | no | — |
| 27 | La barra purga la caché al guardar **sólo** con `update_option_` de las nueve options y `add_option_` de las cuatro promocionales; las cinco base no escuchan ni el alta ni el borrado. | `src/Modules/AnnouncementBar/AnnouncementBar.php:109-120` | no | — |
| 27b | La cinta **rota sola cada 6 segundos** con dos mensajes o más, salvo que el navegador pida `prefers-reduced-motion`; se detiene para siempre en cuanto el cliente interactúa. Es el criterio **opuesto** al del hero (regla 49), sobre lo mismo. Decisión pendiente del dueño. | `assets/js/announcement-bar.js:97-117` | sí (apagando la barra o dejando un solo mensaje) | Panel → Barra de anuncios |
| 28 | El banner nombra la regla de **mayor** porcentaje cuando hay varias. | `src/Modules/DiscountCopy/DiscountCopy.php:311-331` | no | — |
| 29 | Sin umbral de envío gratis configurado, `[akb_envio_gratis]` devuelve cadena vacía, no «$0». | `src/Modules/ShippingCopy/ShippingCopy.php:68-75` | sí | Panel → Envío gratis |
| 30 | El formulario de contacto admite 20 intentos/minuto y 3 envíos válidos/hora por IP; los administradores están exentos. | `src/Modules/Forms/ContactoSubmit.php:31`, `src/Utilities/RateLimit.php:30-31` y `:76` | no | — |
| 31 | Faltar cualquiera de los tres campos se rechaza con **400 «Nombre, email y mensaje son requeridos»** (`:127-133`), midiendo el correo **en crudo**; y si sanear el correo lo cambia o lo vacía, se rechaza con **400 «Email inválido»** (`:144-150`). Esa es la primera guarda, y corre antes que la segunda. | `src/Modules/Forms/ContactoSubmit.php:127-133` y `:144-150` | no | — |
| 32 | El mensaje se guarda en la bitácora **antes** de intentar el correo; sólo si fallan los dos se responde 503. | `src/Modules/Forms/ContactoSubmit.php:167-181` | no | — |
| 33 | Si el correo de notificación configurado no es válido, se usa `contacto@akibara.cl`. | `src/Modules/Forms/Settings.php:29-32` | sí | Panel → Textos → Formulario de contacto |
| 34 | La bitácora conserva máximo 100 mensajes y 4.000 caracteres por mensaje. | `src/Modules/Forms/ContactoLog.php:66-69` | no | — |
| 35 | Un texto de una línea se sanea al leer y al guardar: sin etiquetas, sin saltos, máximo 300 caracteres. | `src/Utilities/Text.php:37` y `:65-80` | no | — |
| 36 | Un texto del dueño **nunca** se pasa por `sprintf`: el marcador se sustituye con `str_replace`. | `src/Modules/OrderPreventa/CustomerVisibility.php:339-345`, `src/Modules/BrandsDirectory/EditionCopy.php:76-86` | no | — |
| 37 | Los textos obligatorios vuelven a su literal si quedan vacíos; los dos del pie aceptan el vacío; **once** de los cincuenta quedan desprotegidos: 8 de los **diez** `akb_copy_eta_*` (todos menos `_overdue` y `_item_arrived`) más los 3 `akb_copy_promise_*`. | `src/Modules/CopyManager/CopyManager.php:94-117`, campos en `:174-246` | sí (por campo) | Panel → Textos |
| 38 | En el listado nunca se pinta el sello «Disponible»; «Agotado» suprime a los demás. | `src/Modules/LoopEnhancer/LoopEnhancer.php:722-736` y `:834-860` | no | — |
| 39 | El sello rojo tiene dos pisos: si la tarjeta ya lleva «Preventa» y el rojo se pone encima, sólo aparece por encima del umbral configurado (10 % de fábrica); si va solo, aparece desde el 1 %. | `src/Modules/LoopEnhancer/SaleDiscount.php:90` y `:102-104` | sí | Panel → Descuentos → El sello rojo −NN % aparece desde (%) |
| 40 | La ficha sólo avisa de escasez entre 2 y el umbral (3) unidades. | `src/Modules/LoopEnhancer/LoopEnhancer.php:387-406` | no | filtro `akibara_business_low_stock_threshold` |
| 41 | Las estrellas del listado no se pintan por debajo de 5 reseñas. | `src/Modules/LoopEnhancer/LoopEnhancer.php:190-193` | no | filtro `akibara_loop_rating_min_reviews` |
| 42 | Un producto agotado no se muestra «en oferta»: un solo importe sin tachado. | `src/Modules/LoopEnhancer/LoopEnhancer.php:478-513` | no | — |
| 43 | Los relacionados se **reordenan** (comprables delante), no se filtran. | `src/Modules/LoopEnhancer/LoopEnhancer.php:299-342` | no | — |
| 44 | El rating nativo de WooCommerce se **retira** de la ficha: sin ese `remove_action`, toda ficha con reseñas mostraría dos filas de estrellas. | `src/Modules/PdpEnhancer/PdpEnhancer.php:70` | no | — |
| 45 | La rejilla de especificaciones no se pinta si el producto no tiene ninguna de las cinco taxonomías. | `src/Modules/PdpEnhancer/PdpEnhancer.php:375-382` | no | — |
| 46 | La clave de caché de la rejilla incluye versión, estado comercial, testigo por producto y un hash de **dos** de las tres promesas: `akb_copy_promise_pedido_transit` queda fuera. | `src/Modules/PdpEnhancer/PdpEnhancer.php:313-334` contra `:415-416` | no | — |
| 47 | El hero admite máximo 8 diapositivas y 3 botones; intervalo 2..30 s; alto de escritorio 200..2000 px y de móvil 30..50 vh. | `src/Modules/HomeHero/Settings.php:25-26, 237, 303, 342, 347, 351` | sí | Panel → Slider de Inicio |
| 48 | Una diapositiva sin ninguna imagen se descarta al guardar, sin avisar. | `src/Modules/HomeHero/Settings.php:245-251` | no | — |
| 49 | El autoplay del hero viene **apagado** de fábrica (WCAG 2.2.2). | `src/Modules/HomeHero/Settings.php:136-140` | sí | Panel → Slider de Inicio |
| 50 | La siembra inicial del hero corre sólo si la option nunca existió, y se abstiene si la base respondió con error. | `src/Modules/HomeHero/Migration.php:38-80` | no | — |
| 51 | Guardar el hero purga LiteSpeed **siempre**, haya cambiado algo o no. | `src/Modules/HomeHero/AdminUI.php:141-146` | no | — |
| 52 | «Series en la portada» admite máximo 24 series y se salta las que no tienen tomos publicados. | `src/Modules/HomeSeriesDestacadas/HomeSeriesDestacadas.php:37` y `:254-257` | sí | Panel → Series en la portada |
| 53 | Las vitrinas de producto limitan cuántos tomos de la misma serie salen (1 en destacados, 2 en a-pedido) y rellenan huecos relajando el tope. | `src/Modules/HomeDestacados/HomeDestacados.php:297-345` | sí | atributo `max_per_serie` del shortcode |
| 54 | «Recién llegados» sólo muestra productos en stock. | `akibara-business.php:277-303` | no | — |
| 55 | En **cualquier** inserción o actualización de un producto —el editor, la edición rápida, una importación o la API REST—, un número de tomo de un dígito se rellena a dos en el título (Gannibal 1 → Gannibal 01); el slug no se toca. El gancho es `wp_insert_post_data`, no un hook de «guardar en el editor». | `akibara-business.php:424-448` | no | — |
| 56 | El criterio «valoración» se retira del selector de orden mientras el sitio no tenga reseñas reales. | `akibara-business.php:326` | no | — |
| 57 | Los colores de marca sólo se inyectan si difieren del color del tema. | `src/Modules/ThemeCustomizer/ThemeCustomizer.php:159-177` | sí | Panel → Branding y UX |
| 58 | La analítica de producción sólo se enciende si se cumplen **las dos** condiciones: `wp_get_environment_type()` es `production` **y** el host es `akibara.cl` o `www.akibara.cl`. Si falta cualquiera de las dos, se apaga — también en producción, y sin que nada falle a la vista. | `src/Modules/FrontendCompatibility/FrontendCompatibility.php:202-210`, la condición en `:208-209` | no | constante `WP_ENVIRONMENT_TYPE` del `wp-config.php` |
| 59 | Toda sesión que llega a «pedido recibido» se marca para grabación prioritaria en Clarity. | `assets/js/clarity-commerce-events.js:437` | no | — |
| 60 | El script que sube `Error.stackTraceLimit` sólo se registra con `WP_DEBUG` activo: nunca sale en producción. | `src/Modules/FrontendCompatibility/FrontendCompatibility.php:73-75` | no | constante `WP_DEBUG` |
| 61 | Sólo seis hosts verificados reciben `crossorigin`; `googleads.g.doubleclick.net` queda fuera a propósito porque marcarlo lo rompería. | `src/Modules/ScriptDiagnostics/CrossOriginScripts.php:60-67` | no | filtro `akb_crossorigin_verified_hosts` |
| 62 | El Centro de Mando vigila una lista literal de **dos** tareas programadas (`akb_order_preventa_recompute_ready`, `akb_bis_cleanup_terminal`), más `akb_order_preventa_t7_reminder_scan` sólo si `akb_preventa_t7_enabled` está en `yes`. De ésas, marca desagendada la que no esté en la cola y atrasada la que lleve más de **6 horas** de retraso. No vigila ninguna otra tarea del sitio. | `src/Modules/AdminDashboard/AdminDashboard.php:88-98`, umbral en `:31`, comprobación en `:124-136` | sí (el tercer hook) | Panel → Pedidos y preventa · Ajustes |
| 63 | El ranking de demanda mezcla tres clases de fila con puntajes distintos: **producto** (`3 × esperando + 2 × racha + favoritos + ventas`), **hueco de serie** (`2 × clientes`) y **búsqueda sin resultado** (`2 × búsquedas de 90 días`). Se ordenan juntas, se descartan las de puntaje 0 y se cortan a 10. | `src/Modules/AdminDashboard/AdminDashboard.php:228-236, 239-248, 251-258, 261-267` | no | — |
| 64 | Una «racha» de colección exige el último y el penúltimo tomo contando volúmenes **distintos**. | `src/Modules/AdminDashboard/AdminDashboard.php:305-313` | no | — |

---

## Datos que deja

| Dato | Tipo | Quién lo escribe | Dónde lo opera el dueño | Para qué |
| --- | --- | --- | --- | --- |
| `akibara_taxonomy_discount_enabled` | option | Panel | Descuentos | Interruptor de la campaña. Defecto de lectura `no`. |
| `akibara_taxonomy_discount_from` / `_to` | option | Panel | Descuentos | Ventana `AAAA-MM-DD`, ambos extremos incluidos. |
| `akibara_taxonomy_discount_apply_to` | option | Panel | Descuentos | `instock_only` / `instock_and_onbackorder` / `all`. Defecto `instock_only`. |
| `akibara_taxonomy_discount_sale_policy` | option | Panel | Descuentos | `skip_if_on_sale` (defecto), `greatest_wins`, `from_regular`, `stack_on_sale`. |
| `akibara_taxonomy_discount_stacks_coupons` | option | Panel | Descuentos | `yes`/`no`, defecto `no`. |
| `akb_discount_max_pct` | option | Panel | Descuentos | Tope de la tienda 1..90, defecto 15. **Compartida con «Arma tu Pack»**. |
| `akb_sale_badge_min_pct` | option | Panel | Descuentos | Umbral del sello rojo, 0..90, defecto 10. Propiedad de `LoopEnhancer\SaleDiscount`. |
| `_akb_taxonomy_discount_pct` | **term-meta** | `Settings::set_term_pct` | Filas de la pestaña Descuentos | El porcentaje de cada grupo. Un 0 borra la fila. Vigilada por `akibara-search`. |
| `akibara_taxonomy_discount_save_trace_{user_id}` | transient (60 s) | `TaxonomyDiscount` en `shutdown` 90 | — | Cuántos términos cambiaron, para el aviso post-guardado. |
| `akb_discount_top_rule` | transient (12 h) | `DiscountCopy` | — | Foto de la regla dominante (porcentaje + nombre del término). |
| `akb_copy_eta_*` (**diez**) | option | Panel | Textos | Carteles de fecha estimada. **Ocho** sin protección contra el vacío; `_overdue` protegida al guardar y `_item_arrived` al leer. Los diez, con su línea, en la ficha de `CopyManager`. |
| `akb_copy_promise_dispatch` / `_preventa_transit` / `_pedido_transit` | option | Panel | Textos → Promesas | Frase del panel de disponibilidad de la ficha. Las tres **sin protección** contra el vacío. |
| `akb_copy_order_*` (siete) | option | Panel | Textos → Ordenamiento | Etiquetas del selector de orden; protegidas al leer. |
| `akb_copy_footer_support_hours` / `akb_copy_footer_phone` | option | Panel | Textos → Pie | Horario y teléfono. El **vacío se guarda**: así se apaga la línea. Las lee el tema. |
| `akb_copy_editions_*` (seis) | option | Panel | Textos → Ediciones | Conmutador de ediciones y tarjetas de familia. |
| `akibara_forms_notify_email` | option | Panel | Textos → Formulario de contacto | Destinatario del formulario; inválido cae a `contacto@akibara.cl`. |
| `akb_brand_color_primary` / `akb_brand_color_surface` | option | Panel | Branding y UX | Colores de marca, validados con `sanitize_hex_color()`. |
| `akibara_announcement_enabled` / `_messages` / `_bg` / `_color` / `_border` | option | Panel | Barra de anuncios | Las cinco base. Los tres colores **no se validan como colores** (`:416-433`) y salen a un atributo `style` con sólo `esc_attr()` (`:172`) — ver el defecto en la ficha de `AnnouncementBar`. |
| `akibara_announcement_promo_text` / `_promo_from` / `_promo_to` / `_promo_follow_discount` | option | Panel | Barra de anuncios | Carril promocional. Las fechas **no se validan**. |
| `akibara_hero_settings` | option | `AdminUI::handle_save` | Slider de Inicio | Array versionado con `slides[]` y `carousel{}`. Se reemplaza **entera** al guardar. |
| `akb_hero_webp_{md5(url)}` | transient (12 h) | `HomeHero` | — | Si existe un `.webp` adyacente a la imagen. |
| `akibara_home_series_enabled` / `_title` / `_slugs` | option | Panel | Series en la portada | Máximo 24 slugs, uno por línea. |
| `akb_home_series_v1_{md5(lista)}` | transient (6 h) | `HomeSeriesDestacadas` | — | Series resueltas: nombre, URL, portada, tomos. |
| `akb_home_destacados_generation` | option (autoload) | `HomeDestacados` | **SIN pantalla** (testigo interno) | Marca de tiempo que caduca las vitrinas. |
| `akb_destacados_v1_*` / `akb_preventas_destacadas_v2_*` | transient (1 h) | `HomeDestacados` | — | Lista de IDs elegidos por vitrina. |
| `_akb_pdp_specs_generation` | **post-meta** | `PdpEnhancer` | — | Testigo por producto que caduca la rejilla cacheada. |
| `akb_pdp_specs_{id}_…` (grupo `akb_pdp`) | object cache (12 h) | `PdpEnhancer` | — | HTML de la rejilla de especificaciones. En producción el object cache es **persistente**: ese TTL es real. |
| Mapa de tomos por serie (grupo `akb_pdp`) | object cache (12 h) | `PdpSeriesEnhancer` | — | `product_id => título` para la barra de navegación. |
| `akb_contacto_log_{uuid}` | option (sin autoload) | `ContactoLog::record` | Descarte desde el Centro de Mando | Una fila por mensaje de contacto; máximo 100. |
| `akb_contacto_log` | option **legacy** | migración en la primera lectura | — | Bitácora antigua (array con todo dentro). Se reparte en filas y se borra. Un refresco de base la reintroduce. |
| `akb_rl_{hash}` y `akb_rlm_{hash}` | **object cache, grupo `akb_rl`** en producción; filas con forma de transient sólo si no hay drop-in | `Utilities\RateLimit` | — | Contadores por IP del limitador: `akb_rl_` para la cuota por hora (`:82`), `akb_rlm_` para la de intentos por minuto (`:111`). **En producción no están en `wp_options`**: el object cache es persistente, así que `bump()` toma el camino de `wp_cache_add()` + `wp_cache_incr()` (`:147-160`) y buscarlos en la base para depurar un 429 no devuelve nada. |
| `akibara_eda_save_errors_{user_id}` / `akibara_eda_save_partial_{user_id}` | transient (60 s) | `AdminPanel` | — | Errores y escrituras rechazadas, para sobrevivir al redirect. |
| `akb_demand_ranking_v1` / `akb_demand_continuity_v1` | transient (1 h / 6 h) | `AdminDashboard` | — | Ranking «Lo más solicitado» y señal de continuidad. |
| `akb_crossorigin_scripts_enabled` | option | — | **SIN pantalla**; sólo `wp option update` por SSH | Interruptor de emergencia del módulo `crossorigin`. Defecto `yes`. |
| `akibara_business_version_installed` / `_search_feed_contract_version` / `_upgrade_tasks_pending` | option (sin autoload) | `Plugin::maybe_upgrade` | **SIN pantalla** (internas) | Gobiernan el upgrade con SQL condicional para que sólo un request gane. |
| `sessionStorage akb_ai_attribution` | dato del navegador | `clarity-commerce-events.js` | — | Fuente de IA (chatgpt/copilot/perplexity/claude/gemini) y evidencia de la visita. |

---

## Glosario del capítulo

| Término | Qué significa aquí, en una línea |
| --- | --- |
| **Action Scheduler** | La cola de tareas que usan WooCommerce y el buscador; ahí se ve, por ejemplo, la acción agendada para la medianoche en que caduca una campaña. |
| **AdminPanel** | La clase que pinta el menú «Akibara», guarda las pestañas y decide si purgar la caché. |
| **«Arma tu Pack»** | El módulo `SeriesBundles`: vende varios tomos de una misma serie como un lote con descuento por volumen. Tiene pestaña propia (`series_bundles`) y se cuenta en el capítulo 03; aquí aparece porque comparte el tope de descuento y declara un prefijo de cupón exento. |
| **combinado / diferido** | Dos optimizaciones de LiteSpeed: juntar varios ficheros JavaScript en uno, y retrasar su ejecución. Los scripts de pago se excluyen de ambas. |
| **drop-in** | Un fichero suelto en `wp-content/` que WordPress usa en lugar de una parte suya; aquí, el `object-cache.php` de LiteSpeed, que hace persistente la caché de datos. |
| **huella (*fingerprint*)** | El resumen corto de la configuración de descuentos que el buscador guarda para saber si su índice sigue vigente; si cambia, se obliga a republicar. Crear una option que antes no existía la cambia igual que cambiarle el valor. |
| **publicación editorial** | El proceso con el que `akibara-search` reescribe en su índice el precio con descuento de todos los productos de las editoriales afectadas y después intercambia las tablas. Pedida a mano cuesta ~30 s sin caída; disparada por un guardado, ~6 min con las sugerencias en 503. |
| **Availability** | El servicio que dice el estado **comercial** de un producto: Disponible, A pedido, Preventa o Agotado. |
| **`akb_search_effective_price`** | El filtro por el que `akibara-business` entrega al buscador el precio con descuento, o lanza una excepción. |
| **`akibara_panel_save`** | El campo oculto que el panel envía en su POST; es el centinela que hace que `LscPurger` se aparte. |
| **`akibara_panel_save_errors`** | El filtro con el que un módulo puede bloquear el guardado entero de una pestaña. |
| **`alloptions`** | El bloque donde WordPress guarda juntas todas las options «de arranque»; el lector estricto lo esquiva leyendo la tabla directamente. |
| **boundary_at** | El instante exacto en que caduca la ventana de la campaña, según la foto que el buscador adopta. |
| **capability** | El permiso que WordPress exige; aquí casi siempre `manage_woocommerce`. |
| **CAS (escritura condicional)** | Un `UPDATE` que sólo gana si el valor anterior es el esperado; así sólo un request corre las tareas del upgrade. |
| **Cloudflare** | El servicio que sirve el sitio desde su propio borde; una purga de LiteSpeed se le propaga por `cdn-cloudflare_clear`. |
| **cron** | Una tarea programada. En producción la empuja Hostinger cada 5 minutos, no el tráfico. |
| **foto adoptada (*snapshot*)** | La copia validada de la configuración que el buscador entrega, para que el precio del índice no dependa de cachés. |
| **honeypot** | Un campo trampa invisible: si viene relleno, lo llenó un bot y el mensaje se descarta en silencio con un 200. |
| **HPOS** | El almacenamiento de pedidos de WooCommerce en tablas propias (`wp_wc_orders`) en vez de posts. |
| **LCP** | *Largest Contentful Paint*: el elemento más grande de la página, que Google mide como señal de velocidad. La imagen del hero suele serlo. |
| **LiteSpeed Cache** | El plugin de terceros que guarda las páginas ya pintadas; `litespeed_purge_all` las tira todas. |
| **`max_input_vars`** | El tope de PHP al número de campos de un formulario; si se supera, PHP **trunca en silencio**. |
| **memoizar** | Guardar el resultado de un cálculo para no repetirlo dentro de la misma petición. |
| **object cache** | Una caché de datos en memoria; en producción es **persistente** (drop-in de LiteSpeed), así que sus TTL son reales. |
| **PriceAuthorityUnavailable** | La excepción que se lanza cuando no se puede determinar un precio con certeza, en vez de devolver el precio base. |
| **sección de acción** | Una pestaña del panel que trae su propio formulario en vez de usar el común; por eso se salta las validaciones, el contador de cambios y la guarda de defectos del panel. |
| **shortcode** | Una etiqueta entre corchetes dentro de un texto que el sitio sustituye al pintar (`[akb_envio_gratis]`). |
| **`skip-lazy`** | La clase con la que una imagen pide cargarse de inmediato, en vez de esperar a que el visitante llegue hasta ella. |
| **`shutdown`** | El último hook de una petición de WordPress; ahí se difieren las purgas caras. |
| **term-meta** | Un dato extra pegado a un **término** (una editorial, una serie); aquí, el porcentaje de descuento. |
| **transient** | Un dato con fecha de caducidad que, si se borra, se vuelve a calcular. |
| **token (de la autoridad de precio)** | El resumen firmado de la foto que el buscador adoptó. Vive **sólo en memoria**, dentro del objeto de esa petición (`SearchPriceProvider.php:63`): no está en la base ni en ningún transient. |
| **UCSS** | El CSS «usado» que LiteSpeed recorta; por eso hay una lista blanca de selectores propios que no se deben podar. |
| **variación** | La versión concreta de un producto que se vende con opciones (talla, color). El catálogo de Akibara es 100 % de productos simples: hoy **no hay ninguna**. |
| **`wc_clean()`** | El saneador genérico de WooCommerce: limpia texto, pero **no valida** que una fecha sea fecha ni que un color sea color. |
