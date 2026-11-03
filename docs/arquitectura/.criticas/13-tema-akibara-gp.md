# Crítica de lector novato — `13-tema-akibara-gp.md`

Quién escribe: alguien que llega hoy al proyecto, no sabe WordPress ni WooCommerce, y tiene
que poder **operar y depurar el tema** con este capítulo en la mano. Leí el capítulo entero
(1.619 líneas) y sus seis diagramas, y después abrí el código del *snapshot* de producción
(`scratchpad/snapshot-prod/themes/akibara-gp/`) para contrastar todo lo que me dio dudas.
Cada línea que cito abajo está comprobada contra ese *snapshot*.

Nota de contexto: el capítulo es de los buenos. Es honesto (§5 lista diecinueve defectos
propios), corrige al mapa de lectura en siete puntos y casi todas sus citas `fichero:línea`
son exactas — comprobé más de cuarenta y fallaron tres. Lo que sigue son, en su mayoría,
huecos de **operación** (rutas de ficheros, nombres de contratos JS, dónde se edita qué) y
media docena de afirmaciones que el código desmiente.

---

## 1 · Lo que NO entendí

**1.1 · No sé qué fichero pinta la ficha de producto, la tienda ni `/faq/`.**
El capítulo dice «el **tema** es la capa que decide cómo se ve todo: qué HTML se imprime» y
después, en «Piezas», enumera «cuatro plantillas propias» (`404.php`, `home.php`,
`archive.php`, `single.php`). Hice `ls` del snapshot: **no existen `page.php`,
`index.php`, `single-product.php` ni `archive-product.php`**. O sea que la ficha de
producto, el catálogo y las once páginas de contenido las pinta GeneratePress (o
WooCommerce), y el tema sólo se cuelga de ganchos. Eso es exactamente lo que un novato
necesita saber antes de buscar dónde editar un `<div>`, y el capítulo no lo dice en ninguna
parte. **Debería decir**, en «Piezas»: «El tema **no** tiene plantilla propia de página, de
catálogo ni de ficha: esas las sirve GeneratePress y WooCommerce, y el tema sólo las
modifica por ganchos (§4.7). Las cuatro plantillas propias son las cuatro superficies
editoriales y el 404».

**1.2 · «Ruta Amarilla» aparece una vez, sin definición y sin glosario.**
Frase (§4.26): «la preventa (`onbackorder`) **sí** pasa y recibe la clase de "Ruta
Amarilla"». No sé si es una clase CSS, un estado de negocio o un nombre interno de un
proyecto anterior. El glosario define `onbackorder` pero no esto. **Debería decir** la clase
literal que se imprime y qué la pinta.

**1.3 · «el constructor de packs ("Arma tu pack")» no fue presentado.**
Frase (§4.26): «Cede ante el constructor de packs ("Arma tu pack") mientras éste está en
pantalla». Es la primera y única mención en todo el capítulo. ¿Quién lo publica, con qué
selector lo detecta `sticky-atc.js`? (En el snapshot el candidato es
`plugins/akibara-business/assets/js/series-bundles.js`.) **Debería decir**: «un bloque que
publica `akibara-business` (`assets/js/series-bundles.js`); `sticky-atc.js` lo detecta por
`<selector>` y se esconde mientras está visible».

**1.4 · El guion que abre el cajón de facetas nunca se nombra.**
§4.8 dice que el botón «despliega el cajón de facetas del buscador propio». En el código el
botón sólo emite `data-akb-drawer-open` y `aria-controls="akb-facets-drawer"`
(`inc/flyout-button.php:70-72`); **quien escucha es `akb-search.js`, del plugin**, y el
capítulo no lo nombra jamás. Si mañana el botón no abre nada, con este capítulo no sé dónde
mirar. **Debería decir**: «el tema sólo pinta el botón; el cajón lo abre `akb-search.js` de
`akibara-search` escuchando `data-akb-drawer-open`; el panel es `#akb-facets-drawer`».

**1.5 · «el aviso amarillo por defecto» no tiene nombre grepeable.**
Frase (§4.30): «Desengancha momentáneamente el aviso amarillo por defecto para no
duplicarlo». Un novato no puede buscar «aviso amarillo». **Debería decir** el callback que
se desengancha y de qué gancho (`wc_empty_cart_message` sobre `woocommerce_cart_is_empty`),
que es lo que hay que volver a poner si algo se rompe.

**1.6 · El `?ver` nunca se muestra entero, y no sé dónde se sube la versión.**
§4.5: «`akibara_gp_asset_ver()` construye el sufijo `?ver=` juntando la versión del tema y
la fecha de modificación del fichero. En producción esa fecha es siempre la misma: el
empaquetador la congela en 1980». Nunca veo el valor resultante ni dónde vive el número que
sí importa. **Debería decir**: «el sufijo queda `?ver=2.1.118.315532800`; el número de
versión se edita a mano en la cabecera de `style.css:8` (`Version: 2.1.118`) y **ése** es el
único que invalida caché».

**1.7 · «26 peticiones pequeñas» sale de la nada.**
§4.5: «`core.css`: un solo fichero que concatena tokens + fonts + base + header + footer,
porque medir en producción mostró que 26 peticiones pequeñas competían entre sí». Si son
cinco ficheros, ¿de dónde salen 26? Faltan las otras 21 (¿hojas condicionales?, ¿fuentes?,
¿plugins?). **Debería decir** qué se contó y en qué página.

**1.8 · Dos pares de números del propio capítulo no coinciden entre sí.**
(a) §4.10 dice «enlazado al directorio con las **104 series**» y ocho líneas más abajo «un
falso negativo cacheado medio día dejaba **106 páginas** sin enlace». (b) §4.17 dice «el
motor propio indexa **1.568 productos**» y §4.21 «**~1.600 fichas**» (y el `CLAUDE.md` del
repo habla de 1.631 documentos). Ninguno de los cuatro números trae fecha ni comando de
medición. **Debería decir** un solo número por cosa, con la fecha y el comando (`wp term list
pa_serie --format=count`, por ejemplo), o decir «≈100» y dejar de fingir precisión.

**1.9 · «94 % del catálogo» se queda en el código, y es lo que explica la pantalla.**
§4.7 explica los botones −/+ diciendo «sólo si la cantidad es ajustable», y el docblock que
cita (`inc/woocommerce.php:500-501`) dice algo mucho más importante que el capítulo se
guarda: «el 94 % del catálogo tiene una sola unidad, de modo que era el estado NORMAL de la
ficha». Leyendo el capítulo uno cree que los botones se ven casi siempre; en la realidad
casi nunca se ven. **Debería decir**: «en la práctica, en ~94 % de las fichas no se pinta
ningún botón: el caso normal es el campo solo».

**1.10 · «la banda del pie de todas las páginas» contra el guard estático.**
§4.13 dice «Un guard estático evita el duplicado en el producto disponible» y, tres líneas
después, «los sellos en cada ficha, en el carrito vacío y en la banda del pie de **todas**
las páginas». No entendí cómo conviven: el guard es `static $impresos`
(`inc/trust-badges.php:68-72`) y el pie **no pasa por él** — llama directo a
`akibara_get_trust_badges_html()` (`template-parts/footer/main.php:157`). Es correcto, pero
hay que decirlo: en una ficha los sellos se imprimen **dos veces en la misma página** (bajo
el botón y en el pie), a propósito.

---

## 2 · Lo que FALTA para operar o depurar esto sin ayuda

**2.1 · Los logos de editorial: ni carpeta, ni convención, ni el aviso de que exigen
release.** §4.7 dice «`akibara_gp_brand_logo()` es la fuente única del logo de una
editorial: SVG del tema por slug, o la imagen del término». En el código la ruta es
`assets/img/editoriales/<slug>.svg` (`inc/woocommerce.php:171`) y hoy hay **ocho** ficheros
(`arechi-manga`, `ivrea-argentina`, `ivrea-espana`, `milky-way`, `ovni-press`,
`panini-argentina`, `panini-espana`, `planeta-espana`). Consecuencia operativa que el
capítulo no enuncia: **añadir la novena editorial con logo propio obliga a publicar una
versión del tema**, y eso choca con la regla de la casa «todo operable sin código». La
alternativa (subir la imagen al término) existe y hay que decirla. **Debería decir**: la
carpeta, la extensión obligatoria, la lista de ocho y «si no quieres desplegar, sube la
imagen en Productos → Editoriales → \<editorial\> → Imagen».

**2.2 · Los tres PNG de los correos no se nombran.** §4.32 dice «las bandas de color son
imágenes» y lista tres filtros de previsualización, pero nunca dice **qué ficheros son ni
dónde están**: `assets/img/akb-email-logo-banner.png`, `assets/img/akb-btn-red.png` y
`assets/img/akb-hdr-white.png` (`woocommerce/emails/email-header.php:29-40`). Son URLs
absolutas a akibara.cl dentro de correos ya enviados: si el tema se renombra, si Cloudflare
las bloquea o si una da 404, **todos** los correos de la tienda quedan sin cabecera y no hay
forma de arreglar los ya enviados. **Debería decir** los tres nombres, que deben ser
públicamente accesibles sin cookie, y cómo comprobarlo (`curl -I`).

**2.3 · La columna «Novedades» del pie se edita en una ubicación llamada «Explorar».**
§4.24 dice «Tres columnas (Catálogo / Novedades / Ayuda) que se pintan sólo si su ubicación
tiene menú asignado». Pero el título visible «Novedades» corresponde a la ubicación
`akibara-footer-explore`, que en Apariencia → Menús aparece como **«Akibara Footer —
Explorar»** (`template-parts/footer/main.php:149` e `inc/setup.php:63`). Un dueño que quiera
cambiar esa columna no la va a encontrar. **Debería decir**: la tabla de las cinco
ubicaciones con **su nombre en wp-admin y el título que se ve en el sitio**, señalando que
no coinciden.

**2.4 · Falta el contrato que abre el cajón del carrito: `window.akbCart.open()`.**
Caso 4 paso 6 y §4.38 dicen «abre el cajón del carrito nativo» sin nombrarlo nunca. En el
código es `window.akbCart.open()` (`assets/js/animations.js:236-240`), y lo publica
**`akibara-business`** (`plugins/akibara-business/assets/js/native-cart.js:48`). El
diagrama `piezas.mmd` sí lo dibuja (`Anim -->|"window.akbCart"| Business`), así que el texto
se quedó atrás. Además, la tabla «Con quién habla» **no incluye esa dependencia**: si
`native-cart.js` no carga, el vuelo de la portada ocurre y el cajón no se abre, y la tabla
dice que lo único que se pierde del lado de `akibara-business` son las URLs, el contador de
favoritos, el cross-sell, las FAQ y las editoriales.

**2.5 · Una superficie entera del tema no aparece: `/suscripcion-confirmada/`.**
§4.4 presenta `inc/page-targets.php` como «la única lista de "en qué página estamos"» y
enumera `AKIBARA_GP_CONTENT_PAGES`, `akibara_gp_is_home_surface()` y
`akibara_gp_surface_has_product_loop()`. Falta la cuarta:
`AKIBARA_GP_SUBSCRIBED_PAGE = 'suscripcion-confirmada'` con
`akibara_gp_is_subscribed_page()` (`inc/page-targets.php:57` y `:82-84`), que es quien
decide que esa página reciba `empty-states.css` (`inc/enqueue.php:259-268`). La página
`/suscripcion-confirmada/` no se menciona en todo el capítulo.

**2.6 · No hay ni un solo comando para probar el tema.** El capítulo trae comandos de
medición sólo en §5.4 y §5.19. No dice: cómo se levanta el espejo para ver el tema, qué
gate corre el tema (¿`ops/qa-theme.sh`, que según la memoria del proyecto está congelado?),
ni cómo regenerar `core.css` en local antes de mirar nada
(`tools/akibara-releases/build-theme-core-css.sh`, que sí existe y sólo se nombra de
pasada en el Caso 11). **Debería tener** un bloque «Cómo lo pruebo» con tres comandos:
levantar, regenerar `core.css`, y `curl -A AkibaraQA` de una ficha.

**2.7 · El Caso 11 («El dueño despliega una versión nueva») no despliega nada.** Describe
qué pasa, no qué se hace. Faltan: qué guion empaqueta (`tools/akibara-releases/…`), dónde
queda el ZIP (`dist/`), cuál de los 49 `ops/*-promotion.sh` lo sube, y sobre todo **la
trampa conocida de este repo**: copiar el ZIP sin `touch` deja mtimes de 1980 que, con
OPcache, sirven dos versiones mezcladas y tumban el front. El capítulo menciona el 1980
como curiosidad de caché del navegador y no como el riesgo que ya costó una caída.

**2.8 · GSAP viaja dentro del tema y no se dice la versión.** §4.5 y §4.38 hablan de «GSAP
+ ScrollTrigger» y de «46 KB», pero no dicen que los ficheros son
`assets/js/vendor/gsap.min.js` y `assets/js/vendor/ScrollTrigger.min.js` **dentro del
tema**, ni qué versión. Consecuencia: actualizar GSAP (o parchear un CVE) es una versión del
tema, y hoy nadie sabe desde qué versión parte.

**2.9 · Los correos no traen ni un asunto real ni el remitente.** §4.33 describe tres
correos y el Caso 8 pone una tabla cuya columna «Asunto» dice «(el que esté configurado)».
Para verificar en la bandeja hace falta el **texto por defecto** de WooCommerce y el
remitente (`woocommerce_email_from_address`). Con lo que hay, no puedo buscar el correo en
Gmail.

**2.10 · Las redes sociales son literales y no se dice.** `https://www.instagram.com/akibara.cl`
y `https://www.facebook.com/akibara.cl` están escritas a mano en
`template-parts/footer/main.php:115` y `:118`. §4.24 sólo dice «Instagram y Facebook fijos»
—lo que puede leerse como «siempre se pintan»—, sin advertir que cambiar la cuenta exige
desplegar el tema.

**2.11 · El panel «Akibara → Textos» no trae ruta.** Aparece nueve veces como destino de
edición y nunca con su URL. Según la memoria del proyecto el slug corto da 403 y el bueno es
`akibara-panel`. **Debería decir** la URL exacta (`/wp-admin/admin.php?page=akibara-panel&tab=…`).

**2.12 · Falta el inventario contra el que un novato compara su `ls`.** El capítulo abre
«Piezas» con un conteo («Veinte módulos en `inc/`, diez partes de plantilla, nueve
sobrescrituras de WooCommerce, cuatro plantillas propias, once guiones y dieciocho hojas de
estilo»). Es casi la única tabla de inventario, y dos de sus cifras no cuadran (ver 3.1 y
3.2). Una tabla «fichero → sección de este capítulo» de una pantalla resolvería el problema
y cerraría el hueco de golpe.

**2.13 · La rama de error de la ficha también recarga, y eso no está.** Ver 3.4: para
depurar «¿por qué se recargó la página al fallar el carrito?» hace falta saber que hay dos
`form.submit()` de último recurso.

---

## 3 · Lo que CONTRADICE al código

**3.1 · «doce entradas en la raíz del tema» → son catorce.**
Frase (preámbulo): «**El ZIP publicado no lleva carpeta `tests/`**: el snapshot de
producción contiene doce entradas en la raíz del tema y ninguna es `tests/`». `ls` del
snapshot da **14**: `404.php`, `archive.php`, `assets/`, `customer-reviews-woocommerce/`,
`footer.php`, `functions.php`, `header.php`, `home.php`, `inc/`, `single.php`, `style.css`,
`template-parts/`, `theme.json`, `woocommerce/`. La conclusión (no hay `tests/`) es correcta;
el número que la sostiene, no. **Debería decir** «catorce» y, ya que es el argumento de
autoridad del preámbulo, enumerarlas.

**3.2 · «once guiones» → son doce (más dos de terceros en `vendor/`).**
`assets/js/` contiene `akibara-brands-filter.js`, `akibara-forms.js`, `animations.js`,
`archive-intro.js`, `atc-guard.js`, `franja-inferior.js`, `main.js`, `page-toc.js`,
`pdp-ajax-atc.js`, `rank-math-content-analysis.js`, `sticky-atc.js` y `wc-event-bridge.js` =
**12**, más `vendor/gsap.min.js` y `vendor/ScrollTrigger.min.js`. El que sobra respecto al
conteo del capítulo es justamente el de administración (§4.18). **Debería decir** «doce
guiones propios (uno de ellos sólo de administración) más GSAP y ScrollTrigger empaquetados
en `assets/js/vendor/`».

**3.3 · «hay que quitarlo dos veces» → se quita una sola.**
Frases: §4.2 «activa lightbox y carrusel de la galería y **desactiva el zoom**» y, en Reglas
de negocio, «El zoom de la galería de producto está desactivado … `inc/setup.php:57-58` y
`:82-88` … **Fijo (hay que quitarlo dos veces porque GeneratePress lo repone)**». En el
código `akibara_gp_setup()` **nunca quita el zoom**: las líneas 57-58 son
`add_theme_support('wc-product-gallery-lightbox')` y `…-slider`, y el zoom simplemente no se
activa; el único `remove_theme_support('wc-product-gallery-zoom')` del tema está en
`inc/setup.php:85`, dentro del cierre de prioridad 99. **Debería decir**: «el tema no activa
el zoom, pero GeneratePress sí lo añade en `after_setup_theme:10`, así que hay que quitarlo
**una vez**, después de él, en prioridad 99 (`inc/setup.php:82-88`)».

**3.4 · «Si el servidor rechaza … no se recarga» → se recarga en dos ramas.**
Frases: Caso 5, Bifurcaciones: «Si el servidor rechaza (carrera de stock), **no se
recarga**»; y Reglas de negocio: «Si el servidor rechaza un añadir al carrito desde la
ficha, **no** se recarga». En `assets/js/pdp-ajax-atc.js` hay dos `form.submit()` de último
recurso: **línea 140** («Sin motivo que mostrar, la recarga sigue siendo la única forma de
que el cliente vea algo») y **línea 147** (`.catch(function () { form.submit(); })`, cuando
falla la segunda petición). **Debería decir**: «no se recarga **si la segunda petición
devuelve un motivo**; si no hay motivo o esa petición falla, sí se recarga la página como
último recurso (`pdp-ajax-atc.js:136-147`)». Lo mismo hay que corregir en el diagrama
(ver 5.5).

**3.5 · «6 hooks de acción replicados» de GeneratePress → son 5.**
Frase (tabla «Con quién habla»): «Tema padre | GeneratePress → 5 filtros y **6 hooks de
acción** replicados». `grep "do_action( 'generate_"` sobre el tema devuelve exactamente
cinco: `header.php:48`, `:53`, `:61` y `footer.php:21`, `:26`. El propio capítulo los
enumera bien más abajo (tres en §4.22 y dos en §4.24): son 3+2=5. El sexto probablemente sea
`generate_before_main_content`, pero ése el tema lo **escucha** (`inc/blog.php`), no lo
replica. **Debería decir** «5 filtros y 5 acciones replicadas, más un sexto gancho del padre
del que el tema cuelga código».

**3.6 · «3.646 de sus 5.322 bytes» de `critical.css` → el fichero tiene 5.885 bytes y 4.209
en comentarios.** Frase (§4.5): «`critical.css` viaja dentro del propio HTML, compactado (se
le quitan los comentarios: 3.646 de sus 5.322 bytes lo eran, y viajaban en **cada**
respuesta)». Medido sobre el snapshot: 142 líneas, **5.885 bytes**, siete comentarios que
suman **4.209 bytes**. El argumento se refuerza, pero las cifras no son las del artefacto
publicado. **Debería decir** las medidas actuales, con el comando que las produce.

**3.7 · `functions.php:139-180` → el fichero termina en la línea 173.**
El rango se cita dos veces (§4.1 y §5.1). El cierre anónimo va de `139` a `173`. La
`verificacion.json` del área ya decía `139-173`.

**3.8 · §5.3 deja fuera una tercera llamada sin guarda, en el mismo fichero.**
Frase: «`functions.php:90` y `:95` usan `is_checkout()`, `is_cart()`, … **sin
`function_exists`**, mientras el callback de al lado (`functions.php:77`) … sí las
protegen». Falta `functions.php:140`: el cierre de `wp_head:2` empieza con
`if ( ! is_product() )` **también sin guarda**, y también corre en toda página del front. El
arreglo que proponga §5.3 tiene que cubrir las tres, no dos.

**3.9 · §5.9 cita mal la ubicación de la regla que denuncia.** Frase: «la regla escrita tres
líneas más arriba». La regla «Un valor vacío es una decisión, no un error: apaga la línea»
está en `inc/site-copy.php:18-19`, es decir **quince** líneas más arriba de
`akibara_gp_copy()` (`:34-39`), en el docblock del fichero, no pegada a la función.

---

## 4 · Lo que contradice a `verificacion.json` del área (`mapas/theme/verificacion.json`)

**4.1 · El conteo de ganchos.** El capítulo abre con «son **69 registros** de
`add_action`/`add_filter` en total, contados sobre el snapshot», y `verificacion.json`
cierra diciendo «El inventario de hooks del mapa es, en cambio, EXACTO: los **63**
`add_action`/`add_filter` del tema están todos representados». Los conté: **69** es el
número correcto (62 con el nombre del gancho en comillas simples en la misma línea, más 7
con el nombre en la línea siguiente: `inc/trust-badges.php:82`, `inc/woocommerce.php:200`,
`:598`, `:613`, `:629`, `inc/rank-math.php:36`, `inc/setup.php:82`) — ése es justo el motivo
por el que el verificador contó de menos. El capítulo acierta, **pero no dice que está
corrigiendo al verificador**, cuando corrige explícitamente al mapa en otros siete puntos
(§4.6, §4.15, §4.16, §4.34, §5.13, §5.18). Quien lea los dos documentos no sabrá a cuál
creerle. **Debería decir**: «69, no 63: el conteo anterior se hizo con una expresión que
pierde los siete registros con el nombre del gancho en la línea siguiente».

**4.2 · El reparto WooCommerce / WordPress es correcto, pero sólo si se cuentan esos siete.**
La tabla dice «**32 de los 69** registros cuelgan de ganchos `woocommerce_*`; los otros 37
son de WordPress (28), de GeneratePress (6) y de LiteSpeed (3)». Confirmado: 28 con nombre
literal en línea + 4 de los diferidos = 32 de WooCommerce; 25 + 3 = 28 de WordPress. Como es
el número que sostiene la frase «el sitio pasa de tienda rota a error fatal global», vale la
pena dejar el comando en el capítulo, porque un `grep` ingenuo devuelve otra cosa.

**4.3 · Cuántas veces aparece `#reviews .commentlist`.** El capítulo (§4.21) dice «doce
apariciones de ese selector»; `verificacion.json` dice «sus **15** selectores». Medido:
`assets/css/reviews-p5-v4.css` tiene 135 líneas, **12** líneas contienen `commentlist` y
**11** contienen literalmente `#reviews .commentlist`. Ninguno de los dos números publicados
es el del fichero.

**4.4 · El rango del preload de la ficha.** `verificacion.json` dice
`functions.php:139-173`; el capítulo escribe `139-180` dos veces. El fichero acaba en 173
(ver 3.7).

**4.5 · La cobertura no se traslada al capítulo.** `verificacion.json` declara
`cobertura_estimada: 88` y avisa: «NO leídos línea a línea: `animations.js`,
`archive-intro.js`, `page-toc.js`, `akibara-brands-filter.js` y el grueso de las 18 hojas de
CSS (30.503 líneas en total)». El capítulo describe §4.28, §4.38 y §4.40 con el mismo tono
asertivo que el resto, y su §4.35 («Sistema de diseño») habla del CSS como si se hubiera
revisado entero. El único aviso del preámbulo es sobre `tests/`. **Debería decir**, en el
preámbulo: «las secciones §4.28, §4.38, §4.40 y la mayor parte de §4.35 se escribieron sin
leer esos ficheros línea a línea; el CSS del tema son 30.503 líneas y no se auditó».

**4.6 · El aviso «WooCommerce no está en el snapshot» sólo vive en §5.13.**
`verificacion.json` lo pone como aviso general para **dos** afirmaciones: los filtros
`..._partial_refunded_order` y el `-1` de `get_max_purchase_quantity()`. En el capítulo el
aviso está enterrado al final de §5.13, mientras la tabla de Reglas de negocio afirma el
`-1` como hecho medido («**ni cuando `get_max_purchase_quantity()` devuelve 1 o -1**. El -1
es el caso "A pedido"») sin ninguna marca. La regla ALTA de todo el capítulo depende de
código que nadie leyó.

---

## 5 · Diagramas

**Validación:** los seis renderizan sin error. Ejecuté
`mmdc -i <fichero>.mmd -o <fichero>.svg -b white` sobre los seis y los seis devolvieron
`rc=0` y SVG de 43-134 KB. La instrucción del preámbulo funciona tal cual está escrita.

**5.1 · `piezas.mmd` promete veinte módulos y dibuja catorce.** Su cabecera dice «Qué
muestra: **los veinte módulos de `inc/`**, las plantillas, los guiones y las hojas de
estilo». Contando nodos hay catorce, y **no aparecen** `inc/blog.php`, `inc/icons.php`,
`inc/site-copy.php`, `inc/page-header.php`, `inc/rank-math.php` ni los tres walkers (van
absorbidos dentro del nodo `Nav`). Justamente `site-copy.php` es el dueño de las tres
options que el propio diagrama dibuja como dato (`OptCopy`), y llega ahí desde `Trust` y
`Footer`, que no son quienes las leen. **Arreglo**: o se corrige la frase de cabecera a «los
módulos principales», o se añaden los cinco nodos que faltan.

**5.2 · `piezas.mmd` usa un contrato que el texto nunca nombra.** La arista
`Anim -->|"window.akbCart"| Business` es correcta (`animations.js:237`), pero `window.akbCart`
no aparece ni una vez en las 1.619 líneas del capítulo. El diagrama tiene que poder leerse
con el texto al lado.

**5.3 · El criterio del color rojo («hoy no corre») es inconsistente.** Marca tres nodos
inertes (`Preload`, `TrustCheck`, `SinPlano`), pero deja en azul —código vivo— dos cosas que
el propio §5 declara muertas o inútiles: la rama `'checkout'` de `inc/page-header.php`
(§5.10, «no puede casar nunca») y `reviews-p5-v4.css` (§4.21, «puede no estar pintando
nada», nodo `RevP5` en azul normal). O el rojo significa «no corre» y esos dos lo llevan, o
hace falta un cuarto color «sospechoso».

**5.4 · `caso-compra-en-la-ficha.mmd` repite el error de 3.4.** Su rama de rechazo termina en
«Motivo junto al botón y enlace "Ver carrito", 6 s, **sin recargar**», y omite los dos
`form.submit()` (`pdp-ajax-atc.js:140` y `:147`). Falta un `else` («sin motivo o segunda
petición caída → recarga completa»).

**5.5 · `caso-compra-en-la-ficha.mmd` atribuye mal quién abre el cajón.** Dibuja
`Woo-->>Bus: El cajón del carrito nativo lo escucha`, como si WooCommerce avisara al plugin.
En el código quien lo abre es **el tema**: `animations.js:236-240` llama a
`window.akbCart.open()` al aterrizar el vuelo de la portada (§4.38 y Caso 4 paso 6). Y
`animations.js` **ni siquiera es participante** del diagrama, aunque la participación `JS`
se declara como «`pdp-ajax-atc.js` + `atc-guard.js`». **Arreglo**: añadir `animations.js` al
participante JS y cambiar la flecha a `JS->>Bus: window.akbCart.open()`.

**5.6 · `caso-correo-de-reembolso.mmd` afirma como hecho lo que §5.7 declara sin medir.** Su
último `alt` dice «El cliente lee la parte de texto plano → **No hay carpeta `plain/` en el
tema: lee el texto de WooCommerce**». §5.7 se titula «[ALTA, **pendiente de medir en base de
datos**]» y admite que depende de `woocommerce_email_settings['email_type']`. El diagrama
publica la conclusión sin el matiz. **Arreglo**: «si el tipo de correo es multiparte o texto
plano (por confirmar: `wp option get woocommerce_email_settings`)…».

**5.7 · `estados-selector-de-cantidad.mmd` es el mejor del capítulo, y le falta una rama.**
El árbol es exacto contra `inc/woocommerce.php:511-528`, incluida la rama `SinGlobal`. No
está el `try/catch` de las líneas `525-527`, que devuelve `true` (ajustable) si
`get_max_purchase_quantity()` lanza — es la tercera forma de llegar a «Pinta» y la única que
puede esconder un error de WooCommerce. Tampoco aparece el «94 % del catálogo» que hace de
`NoPinta` el estado normal (ver 1.9).

**5.8 · `estados-cajon-movil.mmd`: el título dice tres estados y el dibujo tiene cuatro
cajas.** «Los **tres** estados del cajón lateral en móvil», y luego `Cerrado`, `Menu`,
`Busqueda` y `Enviado`. `Enviado` no es un estado del cajón sino la salida de la página.
**Arreglo**: o se llama «Los tres estados del cajón (y por dónde se sale)», o `Enviado` pasa
a ser sólo una transición hacia `[*]`.

**5.9 · `caso-serie-desde-google.mmd` omite la fragilidad que el texto sí explica.** El
recuadro «Orden por tomo» muestra `woocommerce_default_catalog_orderby` y la reposición de
`orderby.php`, pero no la prioridad 20 de `woocommerce_catalog_orderby` ni el motivo que
§4.9 subraya en negrita: «**Prioridad 20 porque `akibara-business` reconstruye el array en
la 10**». Es lo único de ese flujo que puede romperse en silencio con un despliegue del
plugin, y el diagrama —que sí tiene a `Bus` como participante— no lo muestra.

**5.10 · Los seis se embeben con `![…](diagramas/….mmd)`, que ningún visor pinta.** El
preámbulo lo admite y da el comando de `mmdc`, lo cual es honesto pero deja el capítulo con
seis iconos rotos para el público objetivo («alguien que NO conoce nada»). **Arreglo de una
línea**: publicar el `.svg` junto al `.mmd` y enlazar el SVG, o pegar el código dentro de un
bloque ```mermaid, que GitHub y varios visores sí renderizan.

**5.11 · Faltan diagramas donde más falta hacen.** Trece casos de uso y sólo tres tienen
diagrama. Los dos que más me habrían servido y no existen: (a) el orden de `wp_head`
(viewport en 0 → fuentes en 1 → preload de portada en 2 → `wp_preload_resources` del núcleo,
que es el corazón de §5.1 y del Caso 1) y (b) el orden de prioridades de
`woocommerce_no_products_found` (10 de WooCommerce contra 11/13/14 del tema), que es §5.2
entero y se explica hoy con dos párrafos y una tabla.

---

## Cierre

Lo que arreglaría primero, por orden de daño para quien llega nuevo: **2.1** (logos de
editorial: es una tarea de negocio que hoy no se puede hacer sin desplegar), **3.4/5.4** (el
capítulo promete que nunca se recarga y el código recarga), **2.4** (`window.akbCart`: sin
ese nombre no se depura el cajón), **4.5** (decir qué no se leyó) y **1.1** (decir que el
tema no tiene plantilla de ficha ni de página).

---

## Respuesta del redactor

**Aplicada entera.** Comprobé contra el snapshot (`scratchpad/snapshot-prod/themes/akibara-gp/`) los
treinta y tantos puntos que afirmaban un número, una ruta o una contradicción, y **ninguno resultó
equivocado**. No hay nada en esta crítica que haya dejado sin cambiar por estar mal. Lo que sigue son
**matices** que la medición añadió y que conviene dejar por escrito, no desacuerdos.

**1.2 — «Ruta Amarilla»: la clase existe, pero no es amarilla.** Pedías la clase literal y qué la pinta.
La clase es `akb-sticky-atc__btn--preorder` (`template-parts/woocommerce/sticky-atc.php:35-39`). Al ir a
buscar quién la pinta apareció algo mejor que un hueco de documentación: la regla está en
`assets/css/woocommerce.css:4177-4189` y es **roja** (`--aki-red`), con su propio comentario diciéndolo —
«Sticky preventa = rojo de acción (no amarillo). Diferencia por copy + badge». Así que «Ruta Amarilla» es
sólo un apodo interno de la preventa que sobrevive en dos comentarios (`sticky-atc.php:35` y
`woocommerce.css:4174`) y **describe mal lo que se sirve**. El capítulo ahora dice las dos cosas: la clase
y que lo que distingue la preventa en esa barra es el texto del botón, no el color.

**1.3 — el constructor de packs: el selector no es el que suponías, el dueño sí.** Acertaste con
`akibara-business`, pero el guion no es quien lo publica: el marcado sale de
`plugins/akibara-business/src/Modules/SeriesBundles/BuilderWidget.php:796`, y `sticky-atc.js:16` lo
detecta por **`[data-akb-sb-builder]`** (no por una clase). `series-bundles.js` es el guion que lo
maneja, no el que lo imprime. Todo eso está ahora en §4.26.

**2.8 — los «46 KB» de GSAP sí eran un número correcto, mal explicado.** Los ficheros son
`assets/js/vendor/gsap.min.js` (72.927 B) y `ScrollTrigger.min.js` (44.575 B), **GSAP 3.15.0** — la
versión estaba a la vista, en la cabecera de licencia de cada fichero. Los 46 KB del comentario de
`inc/enqueue.php:432` son el **peso comprimido** (28,4 + 18,0 KB medidos con `gzip -c … | wc -c`), no el
de disco, que son ~115 KB. El capítulo dice ahora las dos cifras y la versión, y enuncia la consecuencia
que pedías: parchear GSAP es publicar una versión del tema.

**2.9 — los asuntos por defecto no se pueden transcribir con honestidad.** Tienes razón en que sin el
asunto y el remitente no se puede buscar el correo en Gmail, y el capítulo lo cierra. Pero **no transcribo
ningún literal**: el asunto por defecto de WooCommerce viene de su fichero de traducción y **cambia con la
versión del plugin**, así que un literal escrito aquí caduca sin avisar — y WooCommerce no está en el
snapshot. Lo que hay ahora son los cuatro comandos que leen los valores vivos
(`woocommerce_customer_new_account_settings`, `woocommerce_customer_reset_password_settings`,
`woocommerce_email_from_address`, `…_from_name`), que es lo que de verdad sirve para ir a la bandeja.

**4.3 — confirmado, con el desglose.** `assets/css/reviews-p5-v4.css` son 135 líneas y 3.911 bytes;
**once** reglas empiezan por `#reviews .commentlist` (líneas 7, 13, 22, 38, 43, 48, 65, 80, 87, 98 y 110)
y la duodécima aparición de la palabra está en el comentario de la línea 6. Ni 12 ni 15. El capítulo
publica el desglose y el comando, y dice explícitamente que corrige a los dos documentos.

**4.1 — el capítulo ahora declara que corrige al verificador.** Tenías razón en que callarlo dejaba al
lector sin saber a quién creer. Está escrito en «Piezas», con los siete registros que se perdían y el
comando que da 69.

**5.10 — resuelto por el camino que proponías primero.** Los ocho diagramas se publican ahora como
`.svg` renderizado **junto a** su `.mmd`, y el capítulo embebe el SVG. Los `.mmd` siguen siendo la fuente
editable y el preámbulo dice cómo regenerarlos. Los ocho pasan `mmdc … -b white` con `rc=0`.

**5.11 — los dos diagramas que pedías existen.** `13-tema-akibara-gp-orden-de-wp-head.mmd` (prioridad 0
del viewport → 1 fuentes → 1 `wp_preload_resources` → 2 el preload inerte, con las dos notas que explican
los 108 KB tirados y por qué §5.1 nunca imprime) y
`13-tema-akibara-gp-prioridades-sin-resultados.mmd` (la rama vacía 10/11/13/14 contra la rama con
resultados 4/7/35, con el arreglo propuesto).

**Lo único que no hice fue añadir nodos al diagrama `piezas`… y sí lo hice.** Elegí la segunda opción de
tu 5.1: entraron `inc/blog.php`, `inc/icons.php`, `inc/site-copy.php`, `inc/page-header.php` e
`inc/rank-math.php`, y la arista de `OptCopy` ahora nace de `SiteCopy`, que es quien de verdad las lee;
`Trust` y `Footer` cuelgan de él. Los tres walkers siguen dentro del nodo `Nav`, y la cabecera del
diagrama lo dice en vez de prometer veinte cajas. Sobre tu 5.3 añadí un **cuarto color**, gris punteado =
«sospechoso», para `reviews-p5-v4.css` y para la rama `'checkout'` de `page-header.php`, que era
exactamente la distinción que faltaba.
