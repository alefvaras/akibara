# Crítica del capítulo 02 — `02-business-nucleo.md`

> Quién escribe: lector novato. No sé qué es WordPress, no sé qué es WooCommerce, no conozco
> el negocio del manga en Chile. Leí el capítulo entero (1.129 líneas) y sus siete diagramas,
> y después abrí el código del *snapshot* de producción
> (`scratchpad/snapshot-prod/plugins/akibara-business/`) y el `verificacion.json` del área
> `biz-nucleo` para comprobar lo que me sonó raro. Validé los siete `.mmd` con `mmdc` 11.17.0.
>
> Lo bueno primero, porque es mucho: la plantilla de cada pieza —«qué hace en una frase / qué
> lee / qué escribe / dónde lo opera el dueño / qué ve el cliente»— es exactamente lo que
> necesito, y los casos de uso con bifurcaciones me dejaron entender el motor de upgrade sin
> saber PHP. El capítulo además aplicó casi todas las correcciones del verificador, incluida
> la del módulo `guest_order_linking`. Lo que sigue es lo que me dejó colgado o lo que no
> aguantó la comprobación contra el código. Cada punto lleva **la frase del capítulo** y
> **qué debería decir**.

---

## 1 · Lo que NO entendí (término sin explicar, salto lógico, referencia a algo no presentado)

**1.1 — No sé dónde estoy ni cuáles son los otros capítulos.**
El capítulo dice: *«Este capítulo no describe lo que Akibara Business hace de cara al cliente
—las fechas de llegada, la lista de espera, los descuentos por editorial, el panel del dueño:
cada cosa tiene su propio capítulo»*. Después nombra sin presentar `order_preventa`,
`series_bundles`, `series_follow`, `CollectionResolver`, `SeriesIndex`, `series_facts`,
`series_schema`, `customer_collection`, `BrandLandings`, `AdminDashboard`… y nunca dice en qué
capítulo vive ninguno. Además, en `docs/arquitectura/` **no existe un capítulo 01**: la
numeración empieza en el 02, así que no hay índice al que volver.
*Debería decir*: una línea al principio con el número de capítulo de cada módulo que se nombra
(«las fechas de llegada, capítulo 04; el panel, capítulo 06; el buscador, capítulos 08 y 09»),
o crearse un `01-indice.md` y enlazarlo desde la cabecera.

**1.2 — «contrato de alimentación» se usa antes de existir.**
Aparece en la sección «Cómo encaja»: *«le agenda una reconstrucción completa del índice cuando
cambia el **contrato de alimentación** (`src/Plugin.php:762-771`)»*. En ese punto no sé qué es
un índice, ni qué alimenta Business, ni qué es un contrato aquí. La pieza 9 lo explica 300
líneas más abajo y el glosario no lo recoge.
*Debería decir*: en la primera mención, una aposición de media línea («el acuerdo sobre qué
significa el precio que Business le entrega al buscador para que éste lo guarde») y un
«ver pieza 9». Y entrada de glosario para **contrato de alimentación**.

**1.3 — «Las cinco dependencias de construcción» y la tabla trae seis.**
Pieza 6: *«Las cinco dependencias de construcción que lo fijan son:»* seguido de una tabla con
seis filas (`estimated_delivery`→`calculator()`, `estimated_delivery`→`renderer()`,
`taxonomy_discount`, `series_catalog_eligibility`, `series_facts`, `customer_collection`).
Conté tres veces pensando que me perdía algo.
*Debería decir*: «seis», o agrupar las dos de `estimated_delivery` en una fila y dejar cinco.

**1.4 — El `LEFT JOIN` del CAS se nombra y no se muestra.**
Pieza 8, paso 4: *«la sentencia SQL exige **a la vez** que no haya otra tarea y que la versión
instalada siga siendo la anterior, con un `LEFT JOIN` de la tabla de options consigo misma»*.
El glosario explica qué es un CAS, pero no cómo un `LEFT JOIN` de una tabla consigo misma
consigue comprobar dos filas distintas en una sola escritura, y la sentencia no se transcribe
en ninguna parte.
*Debería decir*: pegar la sentencia (o un esqueleto de tres líneas) y una frase: «se junta la
fila del marcador con la fila de la versión para poder exigir las dos condiciones en el
`WHERE` de un único `UPDATE`, que la base ejecuta de forma indivisible».

**1.5 — El «formato viejo `"nueva|anterior"`» sale de la nada.**
Pieza 8, casos de borde: *«**Marcador antiguo** con el formato viejo `"nueva|anterior"` → se
acepta como `queued`»*. No sé de qué versión viene ese formato, ni si me lo puedo encontrar
hoy en producción, ni qué hago si lo veo.
*Debería decir*: desde qué versión el formato es JSON, y que un valor con una barra vertical
en medio es un marcador anterior a ese cambio, no una corrupción.

**1.6 — «el buscador ya no conserva el orden de su puntuador»: palabra nueva y afirmación de oídas.**
Caso 6, bifurcación B: *«según el comentario de esas mismas líneas el orden real también
cambia, porque el buscador ya no conserva el orden de su puntuador»*. «Puntuador» no está en el
glosario; y la cabecera del capítulo promete *«Cada afirmación técnica lleva su fichero y su
línea»*, pero aquí el capítulo se apoya en un comentario ajeno sin comprobarlo.
*Debería decir*: qué es un puntuador (*scorer*: la parte del buscador que decide qué resultado
va primero), y o bien la comprobación con fichero y línea del lado del buscador, o la etiqueta
explícita «esto es lo que dice el comentario; no está medido».

**1.7 — El glosario y el cuerpo se contradicen sobre `alloptions`.**
Pieza 7: *«ninguna necesita viajar dentro del blob `alloptions` que WordPress carga en todas
las páginas **del escritorio**»*. El glosario dice lo contrario y lo correcto: *«Autoload: …
si WordPress la carga en **todas** las peticiones»*. Entendí que el autoload sólo pesa en el
panel de administración, que es justo al revés de lo que hace cara al cliente.
*Debería decir*: «que WordPress carga en **todas** las peticiones, también las del cliente».

**1.8 — «Cebar» se usa como verbo técnico y no está en el glosario.**
Pieza 6: *«**cebar de golpe** las options que el front lee en cada visita»*; pieza 7: «El
cebado de options del front»; Caso 5: «calienta sus options de un tirón». Tres palabras
distintas para lo mismo y ninguna definida.
*Debería decir*: entrada de glosario para **cebar** («pedirle a WordPress que traiga de la base
varios ajustes en una sola consulta, antes de que alguien los pida uno a uno») y usar un solo
verbo en todo el capítulo.

**1.9 — El bucle de cuatro intentos no tiene final.**
Pieza 8, paso 3: *«**Un bucle de hasta cuatro intentos** (`:316-337`)»*. El capítulo describe
qué pasa en cada vuelta pero no qué pasa si las cuatro se agotan. Leyendo el código, el `for`
simplemente termina y la función retorna sin hacer nada (`Plugin.php:316-337`), así que el bump
queda sin aplicar hasta la petición siguiente — y eso no está escrito en ningún sitio.
*Debería decir*: «si las cuatro vueltas se agotan, la función se rinde en silencio y lo vuelve
a intentar la petición siguiente», y si eso es aceptable o no.

**1.10 — «lógica pura» y «ninguno con estado» chocan con la tabla que va debajo.**
Pieza 15: *«trozos pequeños de lógica pura que existen para que la misma pregunta se conteste
igual en todas partes»* y *«trece ficheros, **ninguno con estado**»*. Pero la primera fila de
la tabla es `RateLimit`, que **escribe** un contador en la caché o en la base, y `DiscountCeiling`
y `Text` **leen** options. Eso no es lógica pura ni ausencia de estado.
*Debería decir*: «ninguno guarda estado **en memoria entre llamadas**; dos de ellos sí leen y
escriben fuera (`RateLimit` cuenta envíos, `DiscountCeiling` y `Text` leen ajustes del dueño)».

**1.11 — Caso 5 me dice que se cumple una guarda que en producción no se alcanza.**
Caso 5, paso 2: *«su tercera guarda es `if ( ! taxonomy_exists( 'pa_pais' ) ) return;`
(`Migration.php:85-89`) … En **cada** petición la guarda se cumple, la función retorna sin
migrar y sin escribir el flag»*. En producción el flag `akibara_eda_pa_pais_migrated_at` **sí**
existe, así que `maybe_run_lazy()` retorna en su **primera** guarda (`Migration.php:63`) y nunca
llega a la tercera. Las dos cosas son ciertas en escenarios distintos y el capítulo las mezcla
en el caso «un cliente abre la portada» de una tienda viva.
*Debería decir*: separar los dos escenarios — «en producción retorna en la primera guarda: una
consulta y nada más; en una instalación sin flag retorna en la tercera y no migra nunca».

**1.12 — No entiendo qué relación tiene la pieza 16 con «el núcleo».**
Pieza 16 vive en `src/Modules/GuestOrderLinking/`, o sea es un módulo como los otros 73. El
capítulo lo justifica así: *«Esta pieza está en el capítulo del núcleo porque **dos comentarios
del código afirman que este módulo no hace nada, y sí lo hace**»*. Eso es un motivo para
denunciarlo, no para que sea parte de la sala de máquinas, y rompe la definición que el propio
capítulo dio de núcleo («no pinta nada en la web del cliente»).
*Debería decir*: sacarlo de la lista de piezas y ponerlo en una sección aparte («Un hallazgo
que no es del núcleo pero se descubrió leyéndolo»), o explicar por qué un módulo cuenta como
pieza del núcleo y los otros 73 no.

---

## 2 · Lo que FALTA para poder operar o depurar esto sin ayuda

**2.1 — Ninguna pantalla tiene dirección.**
El capítulo manda al dueño a *Akibara → Textos y Copys*, *Akibara → Descuentos*, *Akibara →
Textos y Copys → Ordenamiento del catálogo* y *Ajustes → Generales → Zona horaria*, y no da ni
una URL. No puedo llegar a ninguna.
*Debería decir*: que el panel vive en `/wp-admin/admin.php?page=akibara-panel` y que cada
pestaña se abre con `&tab=<id>` (`AdminPanel.php:183`, `:723`); para los textos del orden,
`&tab=copys` (`CopyManager.php:147-148`); para el tope de descuento,
`&tab=taxonomy_discount` (`TaxonomyDiscount.php:54`). El *slug* corto `akibara` da 403 y parece
un problema de permisos.

**2.2 — No hay un solo comando para mirar nada.**
El capítulo describe tres options del upgrade sin pantalla, ocho tareas programadas de las que
seis «se agendan a la hora en que se instalaron», y un contrato que puede quedar en `1`. No da
ni una orden para leerlo.
*Debería decir*, en un recuadro: `ssh akibara` y después
`wp option get akibara_business_version_installed`,
`wp option get akibara_business_upgrade_tasks_pending`,
`wp option get akibara_business_search_feed_contract_version`,
`wp cron event list --fields=hook,next_run_relative` (las de WP-Cron) y dónde se ven las de
Action Scheduler (*WooCommerce → Estado → Acciones programadas*, grupo `akb-catalog-order`).

**2.3 — Los oyentes de `akibara_business_upgraded` no se nombran, y son justo lo que hace falta para depurar la bifurcación F.**
El capítulo dice: *«Uno de los oyentes es `cleanup_orphan_options`»* y, en la bifurcación F,
*«un oyente lanza cualquier otra excepción: sube por `init:999` y la petición muere con un
error 500»*. No puedo depurar ese 500 sin saber quiénes son los oyentes. Son cinco en total:
`Plugin::cleanup_orphan_options` (`src/Plugin.php:261`),
`AdultContentFeed::seed_from_constant` (`src/Modules/ProductSchemaShopping/AdultContentFeed.php:95`),
`ProductSitemapStableOrder::invalidate_product_sitemap_cache` (`src/Modules/SeoIndexing/ProductSitemapStableOrder.php:59`),
`SeriesBundles::standardize_promotion_policy` (`src/Modules/SeriesBundles/SeriesBundles.php:129`) y
`HomeHero\Migration::maybe_seed` (`src/Modules/HomeHero/HomeHero.php:55`).
*Debería decir*: esa tabla de cinco filas, con la advertencia de que cualquiera de ellos puede
tumbar la petición y que el orden entre ellos es el de registro.

**2.4 — El hook `akibara_business_booted` se presenta como «público» y nadie sabe quién lo escucha.**
Pieza 5: *«dispara el hook público `akibara_business_booted` pasándose a sí mismo»*. Tiene
exactamente un oyente: `LlmsManifest::register_admin_section`
(`src/Modules/LlmsManifest/LlmsManifest.php:174`), que es cómo ese módulo añade su sección al
panel sin editar el panel.
*Debería decir*: quién lo escucha hoy y para qué sirve el patrón, que es el ejemplo de cómo un
módulo se cuelga del panel.

**2.5 — El `ls` del snapshot tiene dos carpetas enteras que el capítulo no menciona.**
`src/` contiene `Contracts/`, `Internal/`, `Utilities/`, `Plugin.php`, `Modules/` **y también
`Services/` (10 ficheros) e `Integrations/` (9 ficheros)**. El capítulo sólo nombra dos de esos
diez servicios (`CheckoutDataService`, y `PudoCheckoutService`, que en realidad vive en
`src/Integrations/Bluex/Pudo/PudoCheckoutService.php`, no en `Services/`). Los otros ocho
—`AkibaraEmail`, `MailerBoot`, `EmailButton`, `NavigationLinks`, `SeriesCover`, `VolumeTitle`,
`FreeShippingThreshold`, `CheckoutTrustSignals`, `OneClickPage`— no aparecen en ningún sitio, y
por el nombre parecen justo lo mismo que las «utilidades» de la pieza 15.
*Debería decir*: una tabla de `src/Services/` igual que la de utilidades (aunque sea para
remitir a otro capítulo) y una línea diciendo que `src/Integrations/` es el pegamento con Blue
Express y que vive en el capítulo 18. Hoy, comparando el `ls` con las piezas, faltan 19
ficheros sin explicación.

**2.6 — «74 módulos» sin lista y sin puntero.**
El capítulo repite la cifra cinco veces y nunca dice dónde están. En el disco,
`src/Modules/` tiene **61 carpetas**, así que ni siquiera puedo deducir la lista contando
directorios.
*Debería decir*: o el inventario (aunque sea en un apéndice), o la orden exacta para sacarlo
(`grep -o "modules\['[a-z_0-9]*'\]" src/Plugin.php | sort -u`), y que 74 claves salen de 61
carpetas porque varias carpetas registran más de un módulo.

**2.7 — «el inventario completo vive aquí» y faltan las acciones sueltas de Action Scheduler.**
Sección «Lo programado»: *«es el único sitio del plugin que las **retira** … y por eso el
inventario completo vive aquí»*, y luego lista 8 recurrentes + 5 eventos únicos, todos de
WP-Cron salvo uno. Pero el plugin agenda además acciones sueltas de Action Scheduler que nadie
retira nunca, con el mismo defecto que el capítulo denuncia para `akibara_catalog_order_daily`:
`akb_bis_notify_product` (`src/Modules/BackInStock/StockWatcher.php:41`, :110 — es el hook que
manda el correo de «ya llegó» del Caso 8),
`akibara_catalog_order_recompute` (`src/Modules/CatalogOrder/CatalogOrder.php:38`, :259) y las
de `SeriesFollow` (`PublishListener.php:150`, `DigestRunner.php:722`, `:753`, `:809`).
*Debería decir*: o listarlas, o cambiar «el inventario completo» por «el inventario de lo
recurrente y de los eventos únicos de WP-Cron; las acciones sueltas de Action Scheduler están
en el capítulo 16».

**2.8 — El límite por minuto no tiene número.**
Caso 8, paso 1: *«pide primero un cupo por minuto (`RateLimit::per_minute()`, `:105-116`) y más
tarde el cupo por hora»*. `per_minute()` recibe el máximo como argumento
(`RateLimit.php:105`), así que el número lo pone cada módulo: el formulario de contacto pasa
`self::MAX_ATTEMPTS_PER_MINUTE` con el cubo `'contacto_attempt'`
(`src/Modules/Forms/ContactoSubmit.php:91`). Si a un cliente le rechazan el envío, no puedo
decirle cuál de los dos topes saltó.
*Debería decir*: el valor de `MAX_ATTEMPTS_PER_MINUTE`, el nombre del cubo, y que hay dos
límites encadenados con mensajes distintos.

**2.9 — El único correo del capítulo no tiene asunto, ni destinatario, ni forma de verificarlo.**
Cada caso termina con «Correos que salen: ninguno», que agradezco. Pero en el Caso 8, paso 6:
*«El correo del formulario de contacto lo compone `Forms\ContactoSubmit` y sale **inmediato**
por `wp_mail` a una bandeja humana. El asunto lo define ese módulo, fuera de esta área»*. Es el
único correo del capítulo y es el que menos datos trae.
*Debería decir*: a qué dirección va, con qué asunto, en qué capítulo se documenta, y cómo
comprobar en la bandeja que llegó — que es lo que exige `AGENTS.md` («los correos verificados
en la bandeja»).

**2.10 — El tope de descuento se presenta como un ajuste inocente y es una palanca peligrosa.**
Pieza 15: *«`DiscountCeiling` | ¿Cuál es el tope de descuento? | 15 % por defecto (`:36`),
configurable de 1 a 90 (`:39-42`) en *Akibara → Descuentos*»*. Lo que no dice: `akb_discount_max_pct`
es una de las **diez** options de la foto sellada del descuento
(`src/Modules/TaxonomyDiscount/AdoptedSnapshot.php:72`), la misma foto cuyo rechazo el capítulo
describe como «el buscador desaparece de la superficie servida». Guardar ese campo no es como
guardar un texto.
*Debería decir*: la advertencia, en la misma fila: «tocar este campo reescribe la foto del
descuento y el buscador se queda sin resultados hasta la siguiente reconstrucción».

**2.11 — El hallazgo rojo no trae ni una línea de qué hacer.**
Pieza 9 describe con detalle que un *rollback* devuelve la option a `1` y deja el buscador
fuera. Pero el capítulo no dice **cómo salir de ahí**.
*Debería decir*: el remedio (`wp option update akibara_business_search_feed_contract_version 2`
y, si el índice quedó atascado, `bash ops/rescate-buscador.sh produccion`) y la comprobación
posterior (que `/?_buscar=naruto` devuelve resultados, no un 503).

**2.12 — «apagarlo exige SSH y línea de comandos» sin la línea de comandos.**
Tabla «Datos que deja», fila `akb_crossorigin_scripts_enabled`: *«El módulo queda encendido;
apagarlo exige SSH y línea de comandos»*. No dice con qué valor se apaga.
*Debería decir*: el comando literal y el valor que el lector espera
(`CrossOriginScripts.php:70` para ver qué considera «apagado»).

**2.13 — El 404 de la reactivación no trae el arreglo.**
Pieza 8: *«`/favoritos/`, `/preventas/`, `/disponibles/` y los endpoints propios de *Mi cuenta*
siguen en 404 hasta que alguien guarde permalinks a mano»*. «Guardar permalinks» no es una
acción que yo sepa hacer.
*Debería decir*: *Ajustes → Enlaces permanentes → Guardar cambios* (basta con pulsar Guardar,
sin cambiar nada), o `wp rewrite flush` por SSH; y cómo comprobarlo
(`curl -s -o /dev/null -w '%{http_code}' -A AkibaraQA https://akibara.cl/favoritos/`).

**2.14 — `templates/` no existe para el capítulo.**
El ZIP publicado trae `templates/` con 14 ficheros (`templates/myaccount/`, `templates/emails/`).
El capítulo inventaría los dos ficheros de la raíz y siete «piezas» de `src/`, y nunca dice qué
es esa carpeta ni de quién es.
*Debería decir*: una línea en la pieza 1 o en la 6 («`templates/` guarda las plantillas con las
que el plugin reemplaza pantallas y correos de WooCommerce; se documentan en los capítulos 05
y 07»).

**2.15 — El párrafo del tema es el único sin fichero y sin línea, y esconde una rama.**
*«Con el tema. Una sola dirección: la utilidad `ThemeAssetVersion` delega en la función
`akibara_gp_asset_ver()` del tema»*. Es la única afirmación técnica del capítulo sin cita, y
además es incompleta: `ThemeAssetVersion::for_path()` sólo delega **si la función existe**
(`src/Utilities/ThemeAssetVersion.php:54-56`) y si no, resuelve por su cuenta (`:58` en
adelante). El docblock del fichero nombra los tres módulos que encolan ficheros del tema
(`:8-10`).
*Debería decir*: la cita (`ThemeAssetVersion.php:50-56` y la función del tema en
`themes/akibara-gp/inc/enqueue.php:37`), la rama de repuesto, y qué pasa si alguien cambia de
tema.

**2.16 — No hay manera de saber qué versión está viva.**
El capítulo se declara «fuente de verdad del snapshot congelado de … `akibara-business` 1.116.4»
y todo el motor de upgrade gira sobre comparar una constante contra una option. Pero no dice
cómo comprobar, hoy, si producción sirve esa versión. `CLAUDE.md` es explícito en que eso se
decide **por hash contra producción, no por `mtime`**.
*Debería decir*: el comando (`ssh akibara "wp plugin get akibara-business --field=version"`) y la
regla del hash, o un enlace al capítulo que la explique.

**2.17 — No hay puerta de entrada al plugin.**
El `verificacion.json` lo deja escrito en sus notas: *«`public_html/wp-content/plugins/akibara-business/README.md`
NO EXISTE (el plugin no tiene ningún .md en su raíz)»*. El capítulo no lo menciona, así que quien
abra la carpeta del plugin no encuentra nada que leer y no sabe que este capítulo existe.
*Debería decir*: o que ese README no existe y este capítulo lo sustituye, o crearse el fichero
con un enlace de vuelta.

---

## 3 · Lo que CONTRADICE al código (con fichero:línea del snapshot)

**3.1 — El reparto de las dieciséis piezas está invertido.**
*«El núcleo son **dieciséis** piezas. **Nueve** viven en los dos ficheros de la raíz
(`akibara-business.php` y `uninstall.php`) y **siete** dentro de `src/`.»*
Contando las piezas que el propio capítulo numera: en la raíz están la 1, 2, 3, 4, 11, 12 y 13
= **siete**; en `src/` están la 5, 6, 7, 8, 9, 10, 14, 15 y 16 = **nueve**.
*Debería decir*: «Siete viven en los dos ficheros de la raíz y nueve dentro de `src/`».

**3.2 — «los únicos tres sitios donde el núcleo imprime algo, y los tres pasan por `esc_html()`»: ni son tres ni pasan todos.**
Pieza 2: *«Los dos carteles rojos y los tres `wp_die()` de la activación son los **únicos tres
sitios** donde el núcleo imprime algo, y **los tres** pasan por `esc_html()`»*. Dos más tres son
cinco. Y de esos cinco, **dos no escapan nada**: el primer cartel imprime literales sueltos sin
`esc_html()` (`akibara-business.php:91-94`) y el segundo `wp_die()` es una cadena pelada
(`akibara-business.php:189`). Sólo escapan el segundo cartel (`:109-114`) y los dos `wp_die()`
con `sprintf` (`:177-185` y `:196-204`).
*Debería decir*: «cinco sitios; tres de ellos interpolan variables y esos tres pasan por
`esc_html()`; los otros dos son literales sin variables».

**3.3 — «no pinta nada en la web del cliente» lo desmiente el propio capítulo dos veces.**
Introducción: *«Lo que el núcleo **no** hace: no pinta nada en la web del cliente»*. Pero la
pieza 4 dice de los cuatro filtros de catálogo *«los cuatro son visibles»* (cambian el listado
de la portada, las etiquetas del selector de orden y el título de cada producto), y la pieza 16
imprime un `<p>` en *Mi cuenta → Pedidos* (`GuestOrderLinking.php:196-198`).
*Debería decir*: «no pinta pantallas propias; lo único que toca de la web del cliente son los
cuatro filtros de catálogo de la pieza 4».

**3.4 — «se cuelga de dos avisos de WordPress y de ninguno más».**
*«El núcleo se cuelga de dos avisos de WordPress y de ninguno más»* (`plugins_loaded:20` e
`init:999`). En el mismo fichero se cuelga además de `before_woocommerce_init`
(`akibara-business.php:54`), `admin_notices` (`:89` y `:107`), `wp_insert_post_data` (`:424`),
`woocommerce_shortcode_products_query` (`:277`), `woocommerce_catalog_orderby` (`:320`) y
`woocommerce_get_catalog_ordering_args` (`:369`), más `register_activation_hook` (`:172`) y
`register_deactivation_hook` (`:239`) — todos ellos piezas que el capítulo enumera.
*Debería decir*: «el **arranque** se cuelga de dos avisos; el resto de las piezas de la raíz
tiene cada una el suyo, y están en la tabla de la pieza 4».

**3.5 — `uninstall.php` no tiene 127 líneas.**
Pieza 13: *«**Ficheros:** `uninstall.php` (127 líneas)»*. El fichero del snapshot tiene **118**.
Y las citas de esa pieza están desplazadas: los ocho `wp_clear_scheduled_hook` están en
`:40-47`, no en `:41-48`; `remove_manifests()` está en `:50`, no en `:51`; y el `DELETE` de
`usermeta` está en **`:116-118`**, no en `:118-120` — que además cae fuera del fichero.
*Debería decir*: 118 líneas, y `:40-47`, `:50`, `:116-118`.

**3.6 — El rango de `register_modules()` está mal y por eso la cuenta de líneas también.**
Pieza 6: *«**Ficheros:** `src/Plugin.php:801-1433` (632 líneas…)»*. El método abre en `:801` y
cierra en **`:1417`** — son **617** líneas. `:1418-1433` es ya el docblock de
`cleanup_orphan_options()`, que el capítulo cita aparte en `:1434-1463`.
*Debería decir*: `src/Plugin.php:801-1417` (617 líneas).

**3.7 — «Todo cliente con el correo sin verificar» no ve esa línea.**
Pieza 16, «Qué ve el cliente»: *«**todo cliente** con el correo sin verificar que abra *Mi
cuenta → Pedidos*. En vez de la caja nativa de WooCommerce 11 ve una línea»*. El código lo
condiciona dos veces: `pintar_invitacion()` sale antes si `$this->aviso_apagado` es falso
(`GuestOrderLinking.php:176`), bandera que sólo se enciende cuando WooCommerce **había decidido
mostrar** su caja (`:166`); y sale también si la URL trae `wc_verify_notice` (`:187`), o sea
justo después de pedir el enlace.
*Debería decir*: «lo ve quien, a juicio de WooCommerce, iba a ver la caja nativa — no cualquier
cuenta sin verificar — y no lo ve en la pantalla inmediatamente posterior a pedir el enlace».

**3.8 — La pestaña se llama «Textos», no «Textos y Copys».**
El capítulo manda seis veces a *Akibara → Textos y Copys*. La sección se registra como
`add_section( 'copys', 'Textos', … )` (`src/Modules/CopyManager/CopyManager.php:147-148`), así
que en pantalla dice **Textos**. El bloque interno sí se llama «Ordenamiento del catálogo»
(`CopyManager.php:450`).
*Debería decir*: *Akibara → Textos → Ordenamiento del catálogo*.

**3.9 — Las horas de `CatalogOrder` están dos líneas corridas.**
Tabla de crons: *«**05:00 Chile**, hora fija y re-anclada si deriva más de 15 minutos
(`CatalogOrder.php:43, 55, 163`)»*. `DAILY_HOUR = 5` está en **`:45`** y
`DAILY_DRIFT_TOLERANCE` en **`:54`**; `:43` y `:55` son comentarios. `:163` sí es correcto.
*Debería decir*: `CatalogOrder.php:45, 54, 163`.

**3.10 — La cita del reconciliador no cubre la línea que el capítulo transcribe.**
Pieza 9: *«el código es `if ( $stored === $current ) { return; }` seguido del CAS
(`:738-741`)»*. Ese `if` está en **`:734-736`**; `:738-739` es sólo el CAS.
*Debería decir*: `:734-739`.

**3.11 — La fila de `CatalogQueryGrammar` cita un rango donde no está lo que afirma.**
Pieza 15: *«`utm_source`, `gclid` y `fbclid` **no** lo son; `_paged` tampoco (`:59-69`)»*. En
`:59-69` no aparece ninguno de esos tres nombres: quedan fuera simplemente por no estar en
`WOOCOMMERCE_PARAMETERS` (`:30-56`). Y el *catch-all* que el capítulo transcribe está en
`:60-62`, no en `:59-61`.
*Debería decir*: `:30-56` para la lista blanca y `:60-62` para la regla del guion bajo, y que
los parámetros de campaña se descartan por **ausencia**, no por una regla que los nombre — que
es justamente por qué el agujero de `_gl` existe.

**3.12 — Se repite como vigente un comentario que el propio capítulo contradice dos párrafos después.**
Pieza 9: *«explica por qué **no** sube a 3 … y **producción nunca llegó a adoptar el 2**»*
(copiado del docblock de `src/Plugin.php:186-192`, escrito en 1.99.15). Tres párrafos más abajo
el hallazgo rojo supone lo contrario: *«Desplegar un Business anterior … sobre una tienda cuya
option **ya vale 2**»*, y el diagrama de piezas rotula la option como *«hoy 2»*. Con el plugin
en 1.116.4, o el docblock caducó o el hallazgo rojo no aplica, y el capítulo no dice cuál.
*Debería decir*: el valor que tiene la option en producción hoy (medido, no supuesto), con el
comando para leerlo, y marcar el docblock como texto de 1.99.15 que puede haber caducado.

**3.13 — El comentario de cabecera de `uninstall.php` dice «3 crons» y el fichero limpia 8.**
El capítulo describe la pieza 13 con cuidado pero no señala que su propio fichero se contradice:
`uninstall.php:10-12` promete *«los 3 crons recurrentes»* y el código llama a
`wp_clear_scheduled_hook` ocho veces (`:40-47`). Es el mismo tipo de comentario mentiroso que el
capítulo denuncia en la pieza 16.
*Debería decir*: añadirlo a la lista de comentarios que mienten, con su línea.

---

## 4 · Lo que contradice (o se deja) del `verificacion.json` del área `biz-nucleo`

**4.1 — La omisión 2 está aplicada a medias.**
El verificador señala que `akibara_business_search_feed_contract_version` *«es una de las 10
options de la foto sellada del descuento»* y cita `AdoptedSnapshot.php:71` (dentro de
`OPTIONS`). El capítulo cita `:85` (el literal `FEED_CONTRACT`) y `:250-254` (el rechazo), pero
**no** que la option esté **dentro** de la lista (`AdoptedSnapshot.php:72`). Y su tabla «Datos
que deja» sigue presentándola como escrita y leída sólo por el motor de upgrade.
*Debería decir*: que la option es a la vez el sello del contrato **y** uno de los diez valores
fotografiados, así que moverla cambia la foto entera, no sólo el literal que se compara.

**4.2 — El error 2 del verificador se perdió entero.**
El verificador corrigió la cifra de assets («26 CSS y 17 JS … la cobertura real de las utilidades
de cache-bust es del **100 %** de los assets propios, no 53 de 82», con las tres excepciones en
`Wishlist.php:118-119` y `MyAccountUx.php:115`). El capítulo resolvió el error borrando el
párrafo: la pieza 15 insiste en que el fallo del `filemtime` congelado *«apareció **dos veces en
una jornada***» pero nunca dice cuántos assets hay ni cuántos están cubiertos hoy.
*Debería decir*: publicar el dato corregido — 26 CSS y 17 JS, cobertura 100 %, tres excepciones
nominadas —, que es la única forma de que el lector sepa si el candado sirve.

**4.3 — La sospecha refutada 1 sigue escrita como estaba.**
El verificador refutó expresamente *«sin una sola señal»*: `BackInStock\Install::maybe_install()`
escribe una advertencia con contexto en el registro de WooCommerce (`Install.php:73-91`); lo que
rompe la activación es que ese punto **nunca se alcanza**. El capítulo aplica la corrección en
la pieza 11 («No es cierto que no haya ninguna señal»), pero en el **Caso 2, bifurcación D**
vuelve a escribir *«el módulo queda muerto sin señal»*, que es la frase refutada.
*Debería decir*: en la bifurcación D, «la señal existe y está escrita en el registro de
WooCommerce, pero sellar a ciegas impide que se llegue a ella».

**4.4 — La sospecha refutada 2 no llegó a la tabla de datos.**
El verificador precisó que en producción el camino SQL de `RateLimit::bump()` **no se ejecuta
nunca** (`RateLimit.php:148`, con el `object-cache.php` de LiteSpeed puesto), así que no hay
filas `_transient_akb_rlm_*` en `wp_options`. La tabla «Datos que deja» sigue describiendo los
dos contadores como *«caché de objetos, **o** transient en `wp_options` sin caché»*, sin decir
cuál es el caso real de este sitio — el mismo dato que el Caso 8 sí da.
*Debería decir*: «en producción viven **sólo** en la caché de objetos; la rama de `wp_options`
es la de un entorno sin *drop-in*».

**4.5 — Las notas del verificador sobre el README no se recogen.**
Ya está en 2.17: el `verificacion.json` avisa de que la ruta asignada
`.../akibara-business/README.md` no existe y pide corregirla o crear el fichero. El capítulo no
lo menciona.

**4.6 — El capítulo publica como hechos cosas que la verificación marca como no medidas.**
Las notas son tajantes: *«Nada se midió en vivo: todas las sospechas nuevas traen su
comprobación para el espejo Docker … y ninguna está aprobada hasta medirse allí primero y en
producción después»*, con cobertura estimada del **88 %**. El capítulo presenta los ocho
hallazgos (🔴 y ⚠️) en indicativo, sin distinguir lo leído de lo medido, y no trae ni una de
las órdenes de `como_verificar` que la verificación escribió para cada uno.
*Debería decir*: marcar cada hallazgo como «leído, sin medir» y arrastrar su comprobación
(las de Docker están escritas una por una en `verificacion.json`), tal como exige la regla del
dueño de `CLAUDE.md` («hasta que no está medido en producción, no está aprobado»).

**4.7 — La cobertura del 88 % y lo que quedó fuera no se traslada.**
La verificación deja dicho qué no pudo cerrar por lectura: el comportamiento real de
`wp_cache_incr()` en el *drop-in* de LiteSpeed y si `?_gl=` llega de verdad al sitio («su
frecuencia no se puede saber sin mirar el qlog de producción»). El capítulo afirma la
consecuencia del agujero de `_gl` —*«una landing de serie … se sirve `noindex,follow`»*— sin
decir que nadie ha comprobado que ese parámetro llegue.
*Debería decir*: «el agujero es cierto por lectura; cuántas visitas lo atraviesan no está
medido — se mira en el qlog».

**4.8 — Los siete dobles de prueba están listados, pero no lo que el verificador quería que se dijera.**
El capítulo recoge la omisión 5 (los siete `tests/Support/`) y añade la moraleja correcta. Bien.
Lo que falta es lo que hace accionable el aviso: **cuál doble sostiene cuál candado**. Hoy
enumera cuatro pruebas y siete dobles sin emparejarlos, así que no puedo auditar ninguna.
*Debería decir*: la pareja candado → dobles que usa, aunque sea en una tabla de cuatro filas.

---

## 5 · Los diagramas

**5.1 — Los siete validan.** Corrí `mmdc -i <fichero> -o <svg>` con `mmdc` 11.17.0 sobre los
siete `.mmd` y los siete salieron con código 0. No hay errores de sintaxis.

**5.2 — Pero ninguno se ve al leer el capítulo.** Están insertados como
`![Piezas del núcleo de akibara-business](diagramas/02-business-nucleo-piezas.mmd)`, o sea como
**imagen** apuntando a un `.mmd`. Ni GitHub ni la vista previa de un editor renderizan eso: sale
un icono de imagen rota. Abrí los siete a mano para poder criticarlos.
*Debería decir*: o un bloque ```mermaid con el contenido en línea, o un enlace normal
(`[ver diagrama](diagramas/…​.mmd)`) más un `.svg` generado al lado.

**5.3 — `piezas.mmd` promete dieciséis piezas y dibuja quince.** Su comentario de cabecera dice
*«las dieciséis piezas del núcleo agrupadas por dominio»*, y en el grafo hay 15 nodos de pieza
(`CAB`, `GATE`, `AUTO`, `FILTROS`, `ACT`, `DES`, `UNI`, `BOOT`, `REG`, `CEBA`, `UPG`, `CONTRATO`,
`LIMPIA`, `UTILS`, `CONTRATOS`). **Falta la pieza 16**, `GuestOrderLinking`, a la que el texto
dedica una sección entera y llama «superficie de seguridad de WooCommerce».
*Debería decir*: o añadir el nodo (con su arista a WooCommerce y a *Mi cuenta*), o corregir el
comentario a quince y explicar por qué la 16 no está.

**5.4 — `piezas.mmd` es ilegible a tamaño de página.** Renderizado mide **4.271 × 2.391 px**.
En un documento se ve como una mancha; hay que abrirlo aparte y hacer zoom.
*Debería decir*: partirlo en dos —«arranque y ciclo de vida» (raíz) y «núcleo y fronteras»
(`src/`)—, que además es la división que el propio texto usa.

**5.5 — `cron.mmd` contradice la tabla del texto en el número de eventos únicos.** El texto
lista **cinco** eventos únicos; el diagrama muestra **tres**. Faltan el reintento a los 60
segundos de las imágenes sociales (`SocialImageDerivatives.php:240`) y el segundo evento de
`LlmsManifest` (`:521`).
*Debería decir*: los cinco, o decir en el diagrama que sólo muestra los que sobreviven a la
desactivación.

**5.6 — `cron.mmd` dice que la hora hay que leerla en producción y no dice con qué.** Su
cabecera: *«las demás se agendan relativas al momento de instalarse, así que la hora real hay
que leerla en producción»*. Es la frase más útil del diagrama y no trae el comando.
*Debería decir*: añadir una sección «Cómo mirar la hora real» con
`wp cron event list --fields=hook,next_run` y, para la de Action Scheduler, *WooCommerce →
Estado → Acciones programadas*, grupo `akb-catalog-order`.

**5.7 — `caso-limite-formularios.mmd` nombra mal el cubo y funde los dos límites.** Dibuja
`Form->>RL: per_minute('contacto', máx)`; el cubo real es `'contacto_attempt'`
(`src/Modules/Forms/ContactoSubmit.php:91`) y el máximo es una constante que no se muestra.
Además el texto dice que son **dos llamadas en dos momentos distintos** («pide primero un cupo
por minuto … y más tarde el cupo por hora») y el diagrama tiene una sola rama de rechazo
rotulada *«Supera el tope (3 por hora en contacto)»*, así que no puedo saber cuál de los dos
cortó el envío.
*Debería decir*: dos interacciones separadas, con sus dos cubos, sus dos topes y sus dos
mensajes de rechazo.

**5.8 — `estados-upgrade.mmd` se queda corto en un estado y se inventa otro.** El texto enumera
seis casos de borde e incluye *«**Marcador antiguo** con el formato viejo `"nueva|anterior"` →
se acepta como `queued`»*: en el diagrama no hay ni nodo ni transición para eso. Al revés, el
diagrama trae una transición `Vacio --> Corrupto: escritura manual o valor truncado` que el
texto no menciona en ningún lado.
*Debería decir*: un estado `Legado` con su transición a `Encolado`, y respaldar en el texto la
transición a `Corrupto` (o quitarla).

**5.9 — `caso-activacion.mmd` sella el marcador incondicionalmente.** Dibuja
`Mig->>DB: sella akibara_eda_pa_pais_migrated_at (autoload apagado)` como último paso seguro.
En el código el flag se escribe **sólo al final del recorrido de términos**
(`Migration.php:109-113`) y la función retorna sin escribirlo en cualquiera de sus tres guardas
(`:77`, `:81`, `:85`) — la tercera es la misma que, según el texto, mata el camino perezoso.
*Debería decir*: poner el sello dentro de la rama «todo en orden», y añadir la rama «alguna
guarda se cumple → no migra y **no** sella».

**5.10 — `caso-despliegue.mmd` separa dos pasos que en el código son uno.** Dibuja
`Boot->>DB: ceba de una vez las options del front` y después
`Boot->>Boot: enciende los 74 módulos` como dos acciones de `boot()`. El cebado ocurre
**dentro** de `register_modules()`, en sus dos primeras líneas (`src/Plugin.php:802-803`), que
es justo lo que el texto explica en la pieza 6 («primerísima línea de `register_modules()`»).
*Debería decir*: anidar el cebado dentro del paso de los módulos, o rotularlo «primera línea de
register_modules()».

**5.11 — `caso-desinstalacion.mmd` señala dos fugas y hay más.** Marca con ⚠️ que no se retiran
`akibara_catalog_order_daily` ni `akibara_business_cleanup_collection_owner_fence`. Tampoco se
retiran las demás acciones sueltas de Action Scheduler (2.7): `akb_bis_notify_product`,
`akibara_catalog_order_recompute` y las de `SeriesFollow`.
*Debería decir*: una sola línea «⚠️ ninguna acción de Action Scheduler se retira: viven en
`wp_actionscheduler_actions`», en vez de nombrar sólo una.

**5.12 — El `--x` no está explicado en ningún sitio.** Cuatro diagramas usan `Plugin--xCorreo`
o `Form--xCorreo` para decir «no sale ningún correo». Es un hallazgo de diseño excelente, pero
un novato lee una flecha con una equis y no sabe si significa «falla», «se cancela» o «no
ocurre».
*Debería decir*: una nota de una línea al pie de cada diagrama que lo use, o una entrada en el
glosario («la flecha con una equis significa que ese mensaje **no** ocurre»).

**5.13 — No hay diagrama de lo que el capítulo dice que es lo más frágil.** La pieza 6 —el orden
de registro de los 74 módulos y las dependencias que no se pueden mover— es, según el propio
texto, donde «mover una línea deja al de abajo sin su colaborador». Es la única pieza grande sin
diagrama: la información vive en una tabla de seis filas.
*Debería decir*: un grafo pequeño de quién presta qué a quién (seis nodos y sus flechas),
señalando el caso raro de `marca_term_fields`, que se construye en la posición 43 y se registra
al final del bloque.

---

## Lo que sí quiero que quede escrito

El motor de upgrade, el CAS y el arriendo los entendí **sin saber PHP**, y eso es mérito de la
redacción. Las tres promesas del principio, los «Correos que salen: ninguno» al final de cada
caso, y la honestidad de marcar en el propio capítulo los defectos del código (el sello a
ciegas, el rollback del contrato, el aviso apagado, los cuatro punteros rotos) hacen que
confíe en el texto. Por eso duelen más los desajustes de arriba: son casi todos de bulto —
cuentas invertidas, números de línea corridos, un nombre de pestaña que no existe — y se
arreglan en una pasada.

---

## Respuesta del redactor

Gracias: la crítica era casi toda de bulto y comprobable, y está **aplicada entera**. Lo
que sigue son sólo los puntos donde me desvié de lo que pedías, o donde la comprobación
contra el código dio un número distinto del tuyo. Todo lo demás se corrigió tal cual.

**4.1 — la línea era otra (corregido al aplicarlo).** Pedías citar
`AdoptedSnapshot.php:72` para decir que `akibara_business_search_feed_contract_version`
está *dentro* de la lista `OPTIONS`. En el snapshot, `:72` es `akb_discount_max_pct` y la
option del contrato es `:73`; la lista entera va de `:71` a `:82`. El capítulo cita ahora
`:71-82` con la option en `:73`, y tu 2.10 —que sí apuntaba a `:72` para
`akb_discount_max_pct`— quedó como estaba. Misma corrección, número exacto.

**3.11 — la lista blanca empieza en `:46`, no en `:30`.** Proponías `:30-56` para la lista
blanca de `CatalogQueryGrammar`. `:30-36` es el docblock y la constante
`TEXT_SEARCH_PARAMETER` (`_buscar`); la constante `WOOCOMMERCE_PARAMETERS` es `:46-56`. El
capítulo cita `:46-56` para la lista, `:36` para `_buscar`, `:41-42` y `:61` para `_paged`,
y `:60-62` para el *catch-all*, que es donde tú lo habías puesto y estaba bien.

**2.8 — el número era 20, no uno de los dos que suponías.** `MAX_ATTEMPTS_PER_MINUTE` vale
**20** y el cubo es `contacto_attempt`; el «3 por hora» es el otro límite, cubo `contacto`.
Son dos guardas en dos momentos distintos y por motivos distintos (el de minuto corre
**antes** de validar, para que un envío malformado no salga gratis; el de hora corre
**después**, para que sólo gasten cuota los envíos válidos). Va todo en una tabla, con los
dos mensajes de rechazo literales, que es lo que hacía falta para poder contestarle a un
cliente.

**1.6 — la afirmación no era de oídas; ahora tiene su cita.** Tenías razón en que el
capítulo se apoyaba en un comentario ajeno. Fui a comprobarlo al otro lado y **la
afirmación es cierta**: `FacetQueryIntegration::user_chose_orderby()` devuelve `true` en
cuanto la URL trae cualquier `orderby` no vacío, y entonces el buscador no fija
`orderby = post__in`, así que el orden de su puntuador se pierde y manda el `menu_order` de
WooCommerce (`akibara-search/src/FacetQueryIntegration.php:357`, `:367-369`, `:790-799`).
El texto ya no dice «según el comentario»: dice qué pasa y dónde está escrito. Y
**puntuador** entró al glosario.

**4.8 — la pareja que pedías no existe, y eso es el hallazgo.** Pedías una tabla «candado →
dobles que usa». La respuesta medida es que **ninguno de los once candados de la raíz de
`tests/` usa ningún doble**: ninguno menciona `Support`, y el `bootstrap.php` tampoco los
carga. Los siete dobles sostienen pruebas de *otros* capítulos, y el capítulo los empareja
con ellas (`AuthorityWpdb` → las siete de `TaxonomyDiscount`, `EditionsWpdb` → las tres de
`BrandsDirectory`, `NullEtaProvider` y `PedidoConDatosDeCliente` → las once de
`OrderPreventa`, los tres de `SeriesFollow` → las suyas). La tabla que pedías está, con la
columna «dobles que usa» llena de «ninguno», que es más útil que la pareja: dice que estos
candados no heredan el riesgo del doble.

**1.1 — el índice va dentro de este capítulo, no en un `01-indice.md`.** Dabas dos opciones
y tomé la primera. Crear `01-indice.md` es un fichero de la serie entera, no de este
capítulo, y otras sesiones están escribiendo los demás: abrirlo desde aquí se pisaría con
ellas. Lo que hay ahora es una sección «Dónde estoy» con la tabla de los dieciocho
capítulos y, debajo, el mapa de a qué capítulo va cada módulo que este texto nombra de
paso. Si alguien crea el 01 después, esa tabla se reemplaza por un enlace.

**1.12 + 3.1 + 5.3 — los tres se resolvieron con el mismo movimiento, y la cuenta cambió.**
Saqué `GuestOrderLinking` de la lista de piezas, como pedías en 1.12: está en una sección
aparte, «Un hallazgo que no es del núcleo, pero se descubrió leyéndolo», que explica en su
primera línea por qué se documenta aquí y cuál es su capítulo de verdad (el 05). Eso deja
el núcleo en **quince** piezas, así que el reparto de 3.1 no queda en «siete y nueve» sino
en **siete en la raíz (1, 2, 3, 4, 11, 12, 13) y ocho en `src/` (5, 6, 7, 8, 9, 10, 14,
15)**, que es la suma correcta con la pieza 16 fuera. Y por lo mismo, 5.3 se resuelve sin
añadir el nodo que faltaba: ya no hay pieza 16 que dibujar.

**5.2 — `.svg` generado al lado, no bloque ```mermaid en línea.** Tomé la segunda de tus
dos opciones. Inlinear el diagrama de piezas habría metido cinco mil píxeles de grafo en
medio del texto; ahora cada diagrama entra como imagen `.svg` (que sí renderiza en GitHub y
en cualquier editor) con el enlace al `.mmd` debajo, para quien quiera la fuente. Los nueve
`.svg` están generados con `mmdc` 11.17.0 y regenerarlos es una orden por fichero.

**5.4 — partido en dos, y el de `src/` sigue siendo ancho.** `piezas.mmd` ya no existe: se
reemplazó por `piezas-arranque.mmd` (2011 × 1290) y `piezas-nucleo.mmd` (3168 × 1469),
contra los 4271 × 2391 del original. El segundo bajó de tamaño acortando etiquetas y
agrupando las fronteras, pero sigue siendo ancho porque son seis fronteras que salen del
mismo sitio. Es legible con zoom moderado; partirlo otra vez rompería lo que el diagrama
existe para mostrar, que es que todo eso cuelga de un solo `boot()`.

**5.8 — la transición a `Corrupto` se respaldó en el texto, no se quitó.** Añadí el estado
`Legado` con su transición a `Encolado`, como pedías. Y en vez de borrar
`Vacio --> Corrupto`, la respaldé: el texto explica ahora qué cuenta como corrupto
(cualquier valor que no decodifique ni como JSON de cinco campos ni como el formato legado,
`Plugin.php:519-565`) y de dónde sale en la práctica —una escritura a mano con
`wp option update` o un valor truncado—. La etiqueta del diagrama dice eso mismo.

**3.12 — no puedo darte el valor medido, y eso quedó escrito como tal.** Pedías «el valor
que tiene la option en producción hoy (medido, no supuesto)». Esta sesión no midió nada en
vivo, así que el capítulo no lo inventa: marca el docblock como texto de 1.99.15 que puede
haber caducado, da la orden para leerlo
(`ssh akibara "wp option get akibara_business_search_feed_contract_version"`) y dice qué
significa cada una de las dos respuestas posibles. El diagrama ya no rotula la option como
«hoy 2»: dice «la constante vale 2; lo que vale en producción NO está medido». Es la misma
disciplina que pedías en 4.6, aplicada también a este punto.

**2.17 — el README sigue sin existir, y el capítulo lo dice.** De tus dos opciones tomé la
primera. Crear `plugins/akibara-business/README.md` es escribir dentro del plugin
desplegable, y eso no lo decide un capítulo de documentación: cambia el contenido del
árbol que se empaqueta. Queda anotado en «Dónde estoy» —«el plugin no tiene README; este
capítulo hace de README hasta que exista uno»— y en el `resumen.json`, para que quien
decida crearlo sepa qué tiene que enlazar.
