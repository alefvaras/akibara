# Crítica de lector novato — capítulo 06 (`06-business-panel-descuentos-textos-y-portada.md`)

> Quién escribe: alguien que llega hoy al proyecto. No sé qué es WordPress, no sé qué es
> WooCommerce y no conozco el negocio de vender manga en Chile. Leí el capítulo entero
> (1.381 líneas) y sus cinco diagramas, y después abrí el código del *snapshot* de producción
> (`scratchpad/snapshot-prod/plugins/akibara-business/` y `.../akibara-search/`) y el
> `verificacion.json` del área `biz-precios-panel-textos-home` para comprobar lo que me sonó
> raro. Los cinco `.mmd` los pasé por `mmdc`.
>
> Lo bueno primero, porque es mucho. La plantilla de cada pieza —«qué hace en una frase /
> en qué momento actúa / qué lee y escribe / dónde lo opera el dueño / qué ve el cliente»—
> es exactamente lo que necesita alguien que no sabe nada, y los dos párrafos de apertura me
> hicieron entender el truco central (el precio se calcula al vuelo, no se reescribe) sin
> saber PHP. Los casos de uso con «bifurcaciones» son el mejor material del documento, y el
> capítulo es honesto hasta el punto de delatar defectos de su propio código. Casi todas las
> citas `fichero:línea` caen donde dicen. Lo que sigue es lo que me dejó colgado, lo que no
> me deja operar solo, y lo que no aguantó la comprobación.

---

## 1 · Lo que NO entendí (término sin explicar, salto lógico, referencia a algo no presentado)

**1.1 — «Arma tu Pack» se nombra tres veces y nunca se presenta.**
El capítulo dice: *«Deja pasar los cupones virtuales internos cuyo prefijo declare el filtro
`akibara_taxonomy_discount_ignored_coupon_prefixes` (los de «Arma tu Pack»)»*, y más abajo
*«el descuento por editorial y «Arma tu Pack» son el mismo número de negocio»* y
*«**Compartida con «Arma tu Pack»**»*. No sé qué es: ¿una promoción? ¿una pantalla? ¿otro
plugin? No está en el glosario ni en la tabla de piezas.
*Debería decir*, en su primera aparición: «**Arma tu Pack**: el módulo `SeriesBundles`, que
vende varios tomos de una serie como un lote con descuento; tiene su propia pestaña
«Arma tu Pack» en este mismo panel (`src/Plugin.php:1389`) y se describe en el capítulo 05».
Sin eso, la regla 12 («el tope se comparte») es una afirmación sin sujeto.

**1.2 — «publicación editorial» aparece seis veces y no se define.**
Frases: *«cuando su publicación editorial termina, dispara `litespeed_purge`»*, *«cada
escritura de este term-meta dispara una publicación editorial completa en el buscador»*,
*«Search reconstruye su índice o hace una publicación editorial»*. Para mí son tres cosas
distintas. Tampoco sé cuánto dura: en un punto se dice «~6 minutos» y en otro «el camino de
reconstrucción de ~6 minutos», pero el propio `CLAUDE.md` distingue entre un rebuild
disparado por guardar (6 min con `suggest` en 503) y uno pedido a mano (30 s sin caída).
*Debería decir*: una entrada de glosario («**publicación editorial**: el proceso con el que
`akibara-search` vuelve a escribir en su índice el precio de todos los productos de las
editoriales afectadas y luego intercambia las tablas») y, en el caso 1, cuál de los dos
caminos es el que se dispara al guardar la pestaña Descuentos.

**1.3 — «mueve la huella editorial» no significa nada para mí.**
Aparece dos veces, en la pieza `AdminPanel` (*«crear filas de más mueve la huella
editorial y puede atascar el buscador»*) y en el caso 1 paso 6. «Huella» no está en el
glosario y es justo la razón por la que existe media pantalla de defensas.
*Debería decir*: «**huella (fingerprint)**: el resumen de la configuración de descuentos que
el buscador guarda para saber si su índice sigue vigente; si cambia, el buscador se obliga a
republicar». Y en el paso 6, por qué una fila nueva la cambia.

**1.4 — «sección de acción» (`is_action`) se menciona como hook y nunca se explica, y es la
causa del defecto del hero.**
El capítulo lista entre los hooks que ofrece el panel: *«`akibara_panel_section_is_action`»*,
y aparte dice que el hero *«va a `admin-post.php?action=akibara_hero_save`»* y que *«La
pestaña Descuentos ya resolvió esto con un centinela de final de formulario; el hero no lo
tiene»*. Falta el eslabón: el hero no puede tener esa red **porque no se guarda por el
formulario del panel**, es una sección «de acción» con su propio `<form>`.
*Debería decir*: «Una sección **de acción** es una pestaña que no guarda ajustes por el
formulario común del panel, sino que trae el suyo (Dashboard, Slider de Inicio, Por llegar,
Vitrina, Editoriales · Ediciones). Por eso no pasa por `akibara_panel_save_errors`, ni por
el contador de cambios, ni por `skip_untouched_default()`: todas esas redes se saltan».

**1.5 — «el sello rojo respeta el umbral sólo cuando se apila sobre «Preventa»».**
No sé qué es «apilar» un sello sobre otro. En el código la condición se llama
`$suppress_trivial` (`SaleDiscount.php:102-104`) y depende de quién llame.
*Debería decir*: «cuando la tarjeta ya lleva el sello "Preventa", el rojo sólo se añade si el
descuento supera el umbral —para no ensuciar la tarjeta con un "−1 %"—; cuando el rojo va
solo, se pinta desde el 1 %».

**1.6 — Cinco de los ocho filtros de precio hablan de «variaciones» y el catálogo no tiene
ninguna.**
La tabla de `PriceFilters` dedica tres filas a `woocommerce_product_variation_get_price`,
`woocommerce_variation_prices_price` y `woocommerce_get_variation_prices_hash`. No sé qué es
una variación, el capítulo no lo dice, y el propio código del buscador anota que el catálogo
es *«100% simple hoy (1503/1503)»* (`akibara-search/src/DocumentBuilder.php:295-297`).
*Debería decir*: qué es una variación (un producto con tallas/colores), y que hoy **no hay
ninguna** en Akibara, así que esas tres filas son preventivas. Si no, un novato se pasa la
tarde buscando por qué su prueba de variaciones no da nada.

**1.7 — Salto lógico en el caso 1: sé que el precio viejo se sigue sirviendo, pero no qué
hacer mientras tanto.**
El paso 10 dice: *«el panel ya felicitó al dueño y el cliente sigue viendo el precio viejo»*.
Y ahí termina. ¿El dueño espera? ¿Purga a mano? ¿Cuánto? Es el momento exacto en que alguien
nuevo va a hacer algo peligroso por su cuenta.
*Debería decir*: «mientras tanto no hay que volver a guardar (cada guardado vuelve a mover el
term-meta y encola otra publicación); si hace falta ver el precio nuevo ya, se abre la URL
con `?_v=<timestamp>`, que esquiva la caché sin purgarla».

**1.8 — «combinado y diferido de LiteSpeed», `skip-lazy` y «drop-in» se usan sin entrada de
glosario.**
*«protege los scripts de MercadoPago del combinado y del diferido de LiteSpeed»*, *«hace que
Cloudinary respete la clase `skip-lazy`»*, *«en producción es persistente (drop-in de
LiteSpeed)»*. El glosario recoge UCSS y LiteSpeed pero no estos tres.

**1.9 — El «token» y el `boundary_at` del caso 5 no tienen dueño visible.**
*«Devuelve un token y el estado pasa a PENDIENTE»*. ¿Dónde vive ese token? ¿Se puede mirar
para depurar? (Es memoria de la petición, no se guarda en ningún sitio — pero eso hay que
decirlo, porque si no uno lo busca en la base de datos.)

---

## 2 · Lo que FALTA para poder operar o depurar esto sin ayuda

**2.1 — No tengo los slugs de pestaña: no puedo llegar a seis de las ocho pantallas por URL.**
El capítulo da `?page=akibara-panel&tab=taxonomy_discount` (caso 1) y `home_hero` (caso 9), y
menciona de pasada `copys`. Faltan los de Barra de anuncios, Branding y UX, Series en la
portada y Dashboard.
*Debería traer* una tabla al principio: Dashboard `dashboard` · Slider de Inicio `home_hero`
· Series en la portada `home_series` · Barra de anuncios `announcement` · Textos `copys` ·
Branding y UX `branding` · Descuentos `taxonomy_discount` (`src/Plugin.php:1279-1393`,
`ThemeCustomizer.php:57-59`, `CopyManager.php:147-155`). Con el grupo de cada una («Sitio» o
«Venta»), que es como están ordenadas en el menú.

**2.2 — Los diez carteles `akb_copy_eta_*` no están nombrados, y son exactamente los que
pueden quedar en blanco.**
El capítulo dice *«`akb_copy_eta_*` (nueve carteles de fecha)»* y luego *«**ocho**
`akb_copy_eta_*` desprotegidos»*, salvando por nombre a `akb_copy_eta_overdue` y
`akb_copy_eta_item_arrived`. Con «nueve» menos dos protegidos, la cuenta da siete, no ocho —
y son diez (§3.1). Para arreglar el defecto o para revisarlo hay que poder listarlos.
*Debería traer* la lista literal: `akb_copy_eta_cart_ready`, `_cart_preventa`, `_tba`,
`_single_preventa`, `_single_pedido`, `_single_ready`, `_noun_preventa`, `_noun_pedido`
(`CopyManager.php:174-230`), más los dos protegidos `_overdue` (`:238`) y `_item_arrived`
(`:246`).

**2.3 — No hay ningún remedio operativo para la caché de 12 h de la ficha.**
El capítulo describe el defecto (*«la ficha sigue pintando la frase vieja hasta medio día»*)
y ahí lo deja. Quien edite «Disponibilidad en producto (a pedido)» hoy necesita saber cómo
salir del paso.
*Debería decir*: «hasta que se arregle, después de editar esa frase hay que vaciar el object
cache (`wp cache flush`) o tocar el producto (guardar la ficha dispara
`woocommerce_update_product`, que sube `_akb_pdp_specs_generation` y caduca la rejilla —
`PdpEnhancer.php:87`)».

**2.4 — Nunca se dice cómo se purga la caché a mano.**
Todo el capítulo gira alrededor de purgar, y no aparece ni un comando ni un botón. Tampoco
se dice qué significa `?_v=` en el enlace del aviso post-guardado (es un parámetro cualquiera
que Cloudflare y LiteSpeed no tienen cacheado, y por eso obliga a rearmar la página).
*Debería traer* una línea: «a mano, desde LiteSpeed Cache → Toolbox → Purge All; o el
`do_action('litespeed_purge_all')` que usan estos módulos. Y recordar que purgar devuelve al
origen ~1.568 fichas y 106 landings».

**2.5 — El interruptor sin pantalla no trae su comando.**
*«`akb_crossorigin_scripts_enabled` … hoy sólo se mueve por línea de comandos»* y en la tabla
de datos *«sólo `wp option update` por SSH»*. No está el comando entero ni el valor que lo
apaga.
*Debería decir*: `ssh akibara` y `wp option update akb_crossorigin_scripts_enabled no`
(cualquier valor distinto de `no` lo deja encendido, `CrossOriginScripts.php:91`), y que surte
efecto en la petición siguiente sin desplegar nada.

**2.6 — «Una tarea programada se considera atrasada a partir de 6 horas» no dice *cuáles*
tareas, y son tres.**
El Centro de Mando no vigila «las tareas programadas»: vigila una lista escrita a mano de dos
hooks —`akb_order_preventa_recompute_ready` y `akb_bis_cleanup_terminal`— más un tercero
(`akb_order_preventa_t7_reminder_scan`) sólo si su interruptor está encendido
(`AdminDashboard.php:90-98`). Ni el buscador, ni la fidelidad, ni el SEO del capítulo 16
están ahí. El comentario del propio código dice «los seis eventos» y ya no son seis.
*Debería decir* qué tres hooks vigila y, sobre todo, **qué no vigila**, para que nadie
concluya «el Dashboard está verde, entonces los crons corren».

**2.7 — Del Centro de Mando faltan la ruta del CSV y qué hace exactamente «descartar».**
Se dice *«`admin_post_akb_demand_csv` (prioridad 10) para descargar el pedido sugerido en
CSV»* y *«`admin_post_akb_contacto_dismiss` … Descarta las entradas no entregadas»*. Ni el
botón («Descargar CSV», `AdminDashboard.php:953`) ni qué significa descartar (¿borra el
mensaje del cliente? ¿sólo la alerta?). Para alguien que atiende la tienda, eso es una
decisión irreversible sin explicar.

**2.8 — El ranking «Lo más solicitado» mezcla tres tipos de fila y el capítulo describe uno.**
Los pesos que da (*«esperando aviso ×3, coleccionista en racha ×2, búsqueda sin resultado ×2,
favorito y venta ×1»*) corresponden a filas de **producto** (`AdminDashboard.php:235`), pero
el ranking también emite filas de **hueco de serie** (`2 × clientes`, `:247`) y de
**búsqueda sin resultado** (`2 × s90`, `:257`). Sin saberlo, uno lee la tabla y no entiende
por qué hay filas que no son un tomo.

**2.9 — Falta el «qué hago si» de los dos errores que el panel puede mostrar.**
El capítulo explica bien los dos transients (`akibara_eda_save_errors_{user_id}` y
`..._partial_{user_id}`) pero no qué ve el dueño ni qué debe hacer. El mensaje real del
formulario truncado dice *«Reduce la cantidad visible o aumenta `max_input_vars`»*
(`TaxonomyDiscount.php:369`): eso —cerrar acordeones de taxonomías— es la acción que falta.

**2.10 — De los correos: falta quién es el remitente y qué pasa si `wp_mail` está caído.**
La tabla de correos trae asunto, momento y destinatario, muy bien. Falta el `From` (lo fija
otro módulo, `MailFrom`, capítulo 07) y que en agosto de 2026 el sitio estuvo **tres días sin
enviar ninguno** (`ContactoSubmit.php:164-167`), que es el escenario para el que existe la
bitácora. Un operador nuevo debería saber dónde mirar: Centro de Mando → alerta roja.

**2.11 — Ninguna pieza dice cómo se prueba en el espejo.**
El `verificacion.json` trae recetas concretas (`docker compose exec -T cron wp option
update …`, `curl -s -A AkibaraQA …`) para cada defecto. El capítulo hereda los defectos y
deja fuera las recetas. Con un «cómo se reproduce» de dos líneas por defecto vivo, esto se
vuelve operable sin ayuda.

**2.12 — Componentes del código que el capítulo no menciona** (comparando el `ls` del
snapshot con las piezas):
- `src/Modules/TaxonomyDiscount/AdminFieldType.php` aparece en los casos pero **no tiene
  ficha de pieza propia**, siendo quien pinta y guarda las ~277 filas de porcentaje y quien
  implementa la guarda anti-reescritura de la regla 17.
- `src/Modules/HomeHero/Renderer.php` y `AdminUI.php` se nombran en la lista de ficheros y
  nunca se explica qué hace cada uno (es justo lo que el verificador declara **no haber
  leído**: `verificacion.json` → `notas`).
- Tres hooks de `PdpEnhancer` quedan fuera de su tabla:
  `woocommerce_single_product_image_thumbnail_html` (`:59`, nombre accesible de la galería),
  `wp_footer` → `render_stacked_tabs_js` (`:79`, el JS que apila las pestañas en móvil) y
  `wp_enqueue_scripts` prioridad 20 (`:84`).
- `LoopEnhancer` hace un `remove_action( 'woocommerce_after_shop_loop_item_title',
  'woocommerce_template_loop_rating', 5 )` (`:155`) que no está en la tabla — es exactamente
  la misma trampa que el capítulo sí rescata para la ficha (regla 44).
- `RateLimit::per_minute()` usa un segundo prefijo de clave, `akb_rlm_`
  (`src/Utilities/RateLimit.php:111`), que no está en «Datos que deja».

---

## 3 · Lo que CONTRADICE al código (con fichero:línea)

**3.1 — «nueve carteles de fecha» son diez.**
El capítulo: *«`akb_copy_eta_*` (nueve carteles de fecha)»* y en la tabla de datos
*«`akb_copy_eta_*` (nueve) … Ocho **sin protección** contra el vacío»*.
El código declara **diez** campos `akb_copy_eta_*` en la pestaña Textos:
`CopyManager.php:174, 182, 190, 198, 206, 214, 222, 230, 238, 246`. Con diez, la cuenta del
propio capítulo cierra (10 − `_overdue` − `_item_arrived` = 8); con nueve, no.
*Debería decir*: «diez carteles de fecha, ocho de ellos sin protección».

**3.2 — «siete secciones» de la pestaña Textos son ocho.**
El capítulo: *«registra la pestaña «Textos» (grupo Sitio) con siete secciones»* y el caso 8:
*«El panel pinta las siete secciones con sus ~45 campos»*.
`CopyManager::get_settings()` devuelve ocho bloques `'type' => 'title'`: Textos editables
(`:166`), Promesas de la tienda (`:304`), Contenido de preventas (`:357`), Contenido de
disponibles (`:416`), Ordenamiento del catálogo (`:450`), Pie del sitio (`:511`), Página de
acceso (`:537`) y Formulario de contacto (`:622`). Y los campos editables son **50**, no ~45
(46 `text` + 2 `textarea` + 1 `email` + 1 `select`).

**3.3 — «la analítica se apaga en cualquier host que no sea akibara.cl» es media condición.**
El capítulo y la regla 58 dicen eso citando `FrontendCompatibility.php:202-210`. Esas mismas
líneas exigen **dos** cosas: `'production' === wp_get_environment_type()` **y** que el host
sea `akibara.cl`/`www.akibara.cl` (`:208-209`).
Por qué importa: si en el servidor real `WP_ENVIRONMENT_TYPE` no está en `production`, la
analítica se apaga **en producción** y nadie lo nota (no falla nada visible); y un clon que
copie el `wp-config.php` con `production` sigue protegido sólo por el host. La prueba de QA
tiene que mirar las dos.

**3.4 — «Nada purga la caché al cruzar la medianoche» es falso: hay una tarea agendada para
ese instante exacto.**
El capítulo lo afirma dos veces: en «Lo programado» (*«**Ojo:** nada purga la caché al cruzar
la medianoche»*) y en el caso 4 (*«**Lo que no ocurre.** Nada purga la caché de página al
cruzar la medianoche»*). Y la primera fila de la tabla de «cosas que parecen programadas»
dice *«Quién lo programa: **Nadie: no hay tarea**»*.
El código: `akibara-search` agenda una acción única de Action Scheduler en `boundary_at`
—`akb_search_editorial_discount_boundary`— (`EditorialDiscountPublication.php:2255-2279`,
constante en `:32`), su manejador llama a `reconcile_fingerprint()` (`:1112-1122`) y ése
dispara `litespeed_purge` + `litespeed_purge_all` en las fases *pre* y *post*
(`:1074`, `:1078` → `:2226-2244`).
*Debería decir*: «al cruzar la medianoche sí hay quien purga: el buscador se agendó una tarea
para ese instante. Como el cron de producción lo empuja Hostinger cada 5 minutos, la purga
llega con hasta ~5 minutos de retraso, y sólo si el estado del buscador sigue siendo válido».
Es el mismo `boundary_at` que el capítulo ya explica en la regla 5 sin decir que alguien se
agenda con él. Tal y como está, la sección «Lo programado» de este capítulo es la única
fuente de un novato y le dice que no hay nada que mirar en la cola de Action Scheduler.

**3.5 — El Centro de Mando vigila cinco interruptores, y el capítulo nombra uno que no está
en esa lista.**
El capítulo: *«El verificador comprobó los otros cuatro interruptores del mapa y **todos
coinciden** con su módulo (`akibara_fsp_enabled`, `akibara_native_cart_enabled`,
`akibara_address_autocomplete_enabled`, `akb_preventa_t7_enabled`)»*.
`AdminDashboard::module_toggles()` (`:720-725`) tiene cinco filas: `announcement`, `fsp`,
`native_cart`, `taxonomy_discount`, `address`. `akb_preventa_t7_enabled` **no está** ahí: se
lee en otro sitio, la vigilancia de crons (`:94`). El interruptor que el capítulo omite es
`akibara_announcement_enabled`, que sí está en el mapa (y sí coincide, defecto `yes` contra
`AnnouncementBar.php:124`).

**3.6 — Los contadores del limitador no son transients en producción.**
Tabla de datos: *«`akb_rl_{hash}` | transient | `Utilities\RateLimit`»*.
`RateLimit::bump()` documenta y aplica dos caminos: con object cache persistente —*«el caso
REAL del sitio: LiteSpeed dejó su `object-cache.php` en `wp-content/`»*— usa
`wp_cache_add()` + `wp_cache_incr()` en el grupo `akb_rl`; sólo **sin** drop-in escribe filas
con forma de transient (`src/Utilities/RateLimit.php:116-135`). El propio capítulo afirma en
otra fila que en producción el object cache es persistente, así que se contradice consigo
mismo: en prod esos contadores **no** están en `wp_options` y buscarlos ahí para depurar un
429 no devuelve nada.

**3.7 — Falta un 400 en la lista de «las siete guardas» del formulario de contacto.**
El caso 7 enumera los cortes en orden y se salta el de campos vacíos: `if ( '' === $nombre ||
'' === $email_crudo || '' === $mensaje )` → **400 «Nombre, email y mensaje son requeridos»**
(`ContactoSubmit.php:127-133`), que corre **antes** del de correo inválido. El diagrama
tampoco lo tiene. Es el 400 más frecuente que va a ver quien depure el formulario.

**3.8 — «una tarea programada se considera atrasada» describe una vigilancia universal que no
existe.** Ver §2.6: `AdminDashboard.php:90-98` vigila una lista literal de dos hooks (tres
con el interruptor de preventa). La frase del capítulo —*«Marca como atrasada cualquier tarea
con más de 6 horas de retraso»*— no es lo que hace el código.

**3.9 — Citas que no caen en la línea que dicen** (no cambian el sentido, pero quien vaya a
leer el código se pierde):
- *«protegidos al leer por `akibara_business_catalog_order_label()`
  (`akibara-business.php:311-314`)»* → la función está en `:315-318`.
- *«`add_action('shutdown', …, 99)` … (`LscPurger.php:74`)»* → está en `:73`.
- *«si sanear el correo lo **vacía o lo cambia** … (`:144-150`)»* → el `if` está en
  `:145-151`.
- *«un número de tomo de un dígito se rellena a dos … `akibara-business.php:424-447`»* → el
  `add_filter( 'wp_insert_post_data', … )` va de `:426` a `:450`.

**3.10 — «Al guardar un producto» no es lo que dispara el relleno del número de tomo.**
Regla 55. El gancho es `wp_insert_post_data` (`akibara-business.php:426`), que corre en
**cualquier** inserción o actualización de un producto: el editor, la edición rápida, una
importación o la API REST. Para quien esté depurando por qué un título cambió solo, «al
guardar un producto» apunta al sitio equivocado.

**3.11 — «en producción el object cache es persistente: ese TTL es real» está bien, pero la
pieza `PdpEnhancer` dice a la vez que la caché se guarda «en el object cache, grupo
`akb_pdp`, 12 horas» y que basta con purgar LiteSpeed.** No es contradicción del código sino
del propio capítulo con su tabla de datos; el texto de la pieza debería cerrar con la frase
que sí está en la tabla: la purga de página **no toca** ese grupo.

---

## 4 · Lo que contradice a `verificacion.json` del área

**4.1 — El capítulo presenta como «medido» lo que el verificador declara no haber medido.**
Caso 6: *«**Bifurcación medida:** un guardado que toque el mensaje, el promocional y los tres
colores dispara **cinco** purgas sincrónicas»*. Y en `DiscountCeiling`: *«**Trampa
medida.**»*. El `verificacion.json` cierra con: *«Ninguna de mis sospechas nuevas se midió en
vivo: son lectura de código con su receta de medición en el espejo»* (`notas`), y en la
propia sospecha escribe *«esperado hoy: 4-5 inline + 1 en shutdown»* — un valor **esperado**,
no medido. Con la regla del dueño («hasta que no está medido en producción, no está
aprobado»), llamar «medido» a una lectura de código es exactamente lo que no se puede hacer.
*Debería decir*: «**Bifurcación deducida del código, sin medir todavía**», con la receta.

**4.2 — La sección de la autoridad de precio y el caso 5 se escriben con una seguridad que la
cobertura no respalda.** El verificador anota: *«Quedan sin lectura completa:
`HomeHero/Renderer.php`, el resto de `AdminUI` (plantillas), `AuthoritativeReader.php` y
`AdoptedSnapshot.php` … y `SearchPriceProvider.php` — ahí la cobertura es del mapa, no mía, y
es donde recomendaría la siguiente pasada»* (`cobertura_estimada: 82`). El capítulo describe
esas cinco clases sin ninguna marca de confianza. Tratándose del camino donde *«un precio
equivocado queda sellado sin que nada lo delate»*, merece una nota: «esta sección no está
verificada línea a línea; es la siguiente pasada pendiente».

**4.3 — Dos hallazgos confirmados del verificador se cayeron del capítulo.**
(a) *«Los tres colores de la barra entran en un `style` en línea sin validar que sean
colores»*: los campos son `'type' => 'color'` sin saneador (`AnnouncementBar.php:416-433`) y
el valor viaja a `style="--akb-ann-bg:…"` con sólo `esc_attr()` (`:172`), que **no** neutraliza
el punto y coma — mientras `ThemeCustomizer` (`:165`) y `HomeHero\Settings::sanitize_color()`
sí validan. El capítulo sólo deja una nota de pie en la tabla de datos (*«no se validan como
colores»*) y no lo lista entre los defectos de la pieza ni en las reglas.
(b) *«La barra rota sola cada 6 s … mientras el hero trae autoplay apagado citando WCAG»*: el
capítulo cuenta las dos cosas por separado (regla 49 y «rotación automática cada 6 segundos»)
y nunca señala que son criterios opuestos sobre lo mismo, que es lo que el verificador
marcaba como decisión del dueño.

**4.4 — El recuento corregido se aplicó, pero arrastrando el error de base.**
El verificador corrige *«son OCHO `akb_copy_eta_*` desprotegidos + tres `akb_copy_promise_*`
= 11»* partiendo de que hay nueve. El capítulo copia «nueve» y «ocho» juntos y la resta ya no
cierra. La corrección era correcta en el resultado (once) y equivocada en la premisa: son
diez (§3.1).

**4.5 — El verificador nombra `akb_preventa_t7_enabled` como uno de «los otros cuatro
interruptores del mapa» y el capítulo lo repite sin comprobarlo.** Ver §3.5: ese interruptor
no pertenece a `module_toggles()`. Es el único punto donde el capítulo confía en el
`verificacion.json` por encima del código, y justo ahí el `verificacion.json` se equivocó.

---

## 5 · Diagramas

Los cinco renderizan sin error con `mmdc` (`06-…-piezas`, `-caso-campana-descuento`,
`-caso-precio-cliente`, `-caso-contacto`, `-estados-autoridad-precio` → SVG correctos). El
comentario de cabecera de cada uno («qué muestra») es una idea excelente y ayuda mucho.

**5.1 — El diagrama del formulario de contacto dice que es «Caso 3» y en el capítulo es el
caso 7.** `06-…-caso-contacto.mmd`, línea 1: *«Caso 3 del capítulo 06 — un cliente escribe por
el formulario de contacto»*. En el capítulo, el caso 3 es el del cupón y el del contacto es el
7. Si alguien busca «caso 3» acaba en la pieza equivocada.

**5.2 — La máquina de estados dice que el índice «guarda SIN precio», y el código dice que no
guarda nada.** `06-…-estados-autoridad-precio.mmd`: *«FALLIDA --> [*]: el índice guarda SIN
precio antes que guardar uno falso»*. El contrato real:
*«DocumentBuilder deja propagar esa excepción para que Indexer conserve la deuda y **no
publique un documento parcial/base-price**»* (`akibara-search/src/DocumentBuilder.php:248-252`).
No es lo mismo: un documento sin precio rompería las facetas de precio; lo que ocurre es que
el documento **no se publica** y el índice viejo sigue sirviendo hasta que se pueda publicar
con certeza. *Debería decir*: «el documento no se publica y la deuda queda pendiente: sigue
sirviendo el índice anterior».

**5.3 — La misma máquina atribuye los estados a la clase equivocada.** La cabecera dice
*«(PriceAuthority + AdoptedSnapshot)»*. Los cuatro estados son constantes de
`SearchPriceProvider` (`STATE_EMPTY/PENDING/ACTIVE/FAILED`, `:52-55`); `PriceAuthority` es
otra cosa: un único booleano monótono de «sigo teniendo certeza» compartido por `Settings`,
`DiscountResolver` y el proveedor (`PriceAuthority.php:1-12`). El capítulo tampoco dice qué
clase tiene la máquina, así que el diagrama es la única pista y apunta mal.

**5.4 — El estado `VACIO` del diagrama no existe en el texto.** El caso 5 arranca en «Search
captura su foto» y el primer estado que nombra es PENDIENTE. Quien lea el diagrama antes que
el texto no sabe que VACIO es «el estado normal de toda la tienda» (lo dice el propio
diagrama, y es la observación más útil de los cinco: conviene subirla al capítulo).

**5.5 — En el diagrama de piezas, dos componentes *first-party* están pintados como
terceros.** `classDef tercero` se aplica a `SEARCH["akibara-search …"]` y a
`TEMA["tema akibara-gp …"]`, con el mismo estilo gris que WooCommerce, LiteSpeed, Cloudflare y
Clarity. Pero la cabecera del capítulo dice que `akibara-search` y `akibara-gp` son código
propio (los cuatro plugins y el tema hijo), y el `CLAUDE.md` los lista como «lo único que se
puede modificar». Un novato deduciría del color que no se pueden tocar — o al revés, que
LiteSpeed sí. *Debería* haber una tercera clase («otro plugin propio») o pintarlos como
`propio`.

**5.6 — El diagrama de piezas no tiene leyenda.** Sabe uno que las líneas punteadas son
purgas porque lo dice el comentario del fichero `.mmd`, que **no se ve en el SVG**. Y los
cuatro colores (naranja `propio`, gris `tercero`, verde `persona`, violeta `dato`) no se
explican en ninguna parte. *Debería* llevar un subgrafo «Leyenda» con un nodo de cada clase,
o el capítulo debería traducir los colores en la línea que precede a la imagen.

**5.7 — La barra de anuncios no llega al cliente en el diagrama.** `TABBAR` sólo tiene flechas
hacia `LITE` (purga) y desde `DCOPY`/`SHIP`. No hay ningún nodo que represente la cinta
pintada en el front, así que del diagrama no se deduce lo que el texto repite: que la barra
sale *«arriba del todo en **todas** las páginas»*. Y las flechas `DCOPY --> TABBAR` y
`SHIP --> TABBAR` se leen al revés de lo que ocurre: los shortcodes no alimentan la pantalla
de administración, se resuelven al pintar la cinta en el sitio. *Debería* haber un nodo
`BARRA["Cinta superior — todas las páginas"]` con `TABBAR --> BARRA`, `DCOPY --> BARRA`,
`SHIP --> BARRA` y `BARRA --> CLIENTE`.

**5.8 — Falta en el diagrama de piezas quien hace la mitad del caso 1.** `AdminFieldType` —la
clase que pinta y guarda cada fila de porcentaje, y donde vive la trampa del tope (regla 17 y
`DiscountCeiling`)— aparece en el diagrama de secuencia pero no en el de piezas, donde
`TABDESC --> TMETA` sugiere que la pestaña escribe el term-meta directamente.

**5.9 — El diagrama del caso 1 termina mejor que el capítulo, y conviene alinearlos.** El
`.mmd` cierra con *«Search → LiteSpeed: `litespeed_purge` + `litespeed_purge_all` al
terminar»* y *«recién ahí el HTML público trae el precio nuevo»*, que es justo la corrección
del verificador. Bien. Lo que falta en los dos: que el guardado dispara
`akibara_taxonomy_discount_pct_saved` para las ~277 filas aunque no se toque ninguna
(`AdminFieldType.php:124-145`), que es la razón por la que la guarda anti-reescritura existe
y por la que un guardado inocente llegó a dejar 7.793 filas pendientes en la cola.

**5.10 — Sólo tres de los diez casos tienen diagrama** (1, 2 y 7). No es un defecto, pero los
dos que más lo pedirían son el caso 6 (la barra, con sus cinco purgas y la ventana que no
caduca) y el caso 9 (el hero, con el truncado silencioso): son los dos con pérdida de datos o
coste real, y ambos se explican hoy sólo en prosa.

---

## Respuesta del redactor

Crítica aplicada el 2026-09-12 contra el mismo *snapshot* de producción
(`akibara-business` 1.116.4, `akibara-search` 0.1.60). **Casi todo era correcto y está
aplicado**: los cuarenta y tres puntos de las secciones 1, 2, 4 y 5, y ocho de los once de
la sección 3. El detalle de cada uno está en
`06-business-panel-descuentos-textos-y-portada.resumen.json`, clave
`correcciones_de_la_critica_del_lector_aplicadas`. Gracias en particular por §3.4: era el
error más caro del capítulo y lo negaba dos veces.

Quedan **tres puntos donde la crítica se equivoca**, comprobados línea a línea contra el
código publicado. En los tres el texto del capítulo **no se cambió** en la dirección pedida.

**§3.9, tercer punto — la cita del correo inválido estaba bien.** La crítica pide mover
`ContactoSubmit.php:144-150` a `:145-151`. El `if` está en la línea **144**
(`if ( '' === $email || ! is_email( $email ) || $email !== $email_crudo ) {`), el
`return new WP_Error(` en la 145 y el bloque cierra con `}` en la **150**. La cita original
era exacta y se conserva. Las otras tres citas de ese mismo punto sí estaban mal y sí se
corrigieron: `akibara_business_catalog_order_label()` es `:315-318` y el
`add_action( 'shutdown', …, 99 )` de `LscPurger` es `:73`.

**§3.9, cuarto punto — el rango del relleno de tomo tampoco es el que propone.** La crítica
dice que «el `add_filter( 'wp_insert_post_data', … )` va de `:426` a `:450`». La línea 426 es
la **función anónima** (`static function ( array $data, array $postarr ): array {`); el
`add_filter(` empieza en la **424** y la llamada cierra con `);` en la **448**. El capítulo
decía `:424-447` —corto por una línea— y ahora dice `:424-448`, que no es lo pedido. La
observación de fondo de §3.10, en cambio, era acertada y está aplicada: el gancho corre en
cualquier inserción o actualización, no «al guardar un producto».

**§1.1 — «Arma tu Pack» no está en el capítulo 05.** La crítica pide remitir al capítulo 05;
el módulo `SeriesBundles` se describe en el **03** (`03-business-catalogo-y-series.md`,
dominio 5, y su pestaña en `:206`). En el 05 sólo aparece de pasada, en el aviso del
carrito. La presentación se añadió en `CouponInteraction` —su primera aparición real en el
capítulo, antes que `DiscountCeiling`— remitiendo al 03. El slug de su pestaña se registra en
`src/Plugin.php:1393-1401`, no en `:1389`.

Dos apuntes más, por si sirven para la próxima ronda:

- **§3.4 daba todavía menos crédito del que merecía la defensa del sitio.** Además de la
  acción agendada en `boundary_at` que la crítica encontró, el buscador **prohíbe guardar la
  página** mientras la campaña está viva y durante las 12 horas previas al borde
  (`cap_page_cache_ttl`, `EditorialDiscountPublication.php:567-597`, y
  `authorized_page_cache_ttl`, `:636-664`). Es decir: en el camino normal no hay HTML
  guardado que pueda quedarse con el precio de ayer. Las dos cosas están ahora en «Lo
  programado» y en el caso 4.
- **§4.3(b) merecía un matiz que la crítica no pedía y el capítulo ahora incluye.** La barra
  sí rota sola cada 6 s frente al hero con autoplay apagado, y el contraste es real; pero la
  barra no arranca con `prefers-reduced-motion: reduce` y se rinde para siempre en cuanto el
  cliente interactúa (`assets/js/announcement-bar.js:97-117`). Queda escrito como
  **decisión pendiente del dueño** (regla 27b), no como defecto técnico.
