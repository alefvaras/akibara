# Crítica de lector novato · `19-como-se-hablan-los-plugins.md`

**Quién escribe:** alguien que llega hoy al proyecto, no sabe qué es WordPress ni WooCommerce, y
tiene que poder operar y depurar con este capítulo en la mano.
**Qué hice:** leí el capítulo entero y los seis `.mmd`; después abrí el snapshot
(`scratchpad/snapshot-prod`) y contrasté con `grep`/lectura todo lo que me pareció dudoso; validé
los seis diagramas con `mmdc` 11.17.0; y comparé el capítulo con
`mapas-b/cross-interacciones/verificacion.json`.
**Veredicto corto:** el capítulo es el mejor mapa del proyecto para entender las costuras, pero
tiene **una afirmación central invertida** (la del apagón del buscador sin business), **dos
componentes de primer orden que no aparecen** (el contrato `CheckoutDataProvider` y el directorio
de comunas que lee ficheros de Blue Express) y **un bloque de citas `fichero:línea` desplazadas**
que hacen perder tiempo a quien las siga.

---

## 1 · Lo que NO entendí

**1.1 · «el silencio apaga el buscador a propósito».**
Frase del capítulo (tabla «Las cinco formas de hablarse», fila *Hook*): *«Normalmente nada: si
nadie escucha, el valor por defecto sigue. La excepción de esta tienda es el precio, donde el
silencio apaga el buscador a propósito.»* No entendí qué es «el silencio»: ¿que nadie conteste el
hook, o que conteste alguien equivocado? Es la diferencia entre dos averías opuestas y el código
hace justo lo contrario de lo que dice la frase (ver **3.1**). **Debería decir:** «la excepción es
el precio, donde el problema no es que nadie conteste —eso el buscador lo tolera— sino que conteste
alguien **de más**: dos oyentes apagan el buscador entero».

**1.2 · «gana el que se apuntó primero, que siempre es un mu-plugin».**
En el bloque del orden de arranque. Cinco secciones más abajo, §8 explica un empate en prioridad 3
entre **dos plugins** (`facets-render.php:1051` y `DisponiblesRoute.php:192`) donde no hay ningún
mu-plugin. Un novato no puede sostener las dos frases a la vez. **Debería decir:** «en un empate
gana quien se apuntó antes; si uno de los dos es un mu-plugin, gana siempre el mu-plugin; si son
dos plugins, decide el orden en que WordPress los cargó, que nadie fijó».

**1.3 · «hace que la cuenta dé 2».**
§3, *Consecuencia que hay que memorizar*. ¿Qué cuenta? No hay ninguna cuenta presentada antes.
**Debería decir:** «el buscador cuenta cuántos oyentes tiene el hook y exige exactamente uno
(`count($flat) !== 1` en `EditorialDiscountPublication.php:3020`); con dos, la comprobación falla».

**1.4 · El renglón `20 ── [primero los mu-plugins apuntados en 20]` del esquema de arranque.**
No sé cuáles son esos mu-plugins ni cómo averiguarlo. En todo el snapshot hay **uno solo**
(`akibara-brevo-portada-jpg-en-el-carrito.php:131`). **Debería decir** cuál es, porque ese renglón
existe sólo para preparar el Caso 5.

**1.5 · `serving_ready()` se usa 14 veces antes de definirse.**
Primera aparición: bloque «Qué se rompe si se apaga cada pieza», página 2. Definición: en el
glosario, al final del capítulo. **Debería** definirse en una línea la primera vez, o el glosario
debería anunciarse arriba («los términos en `código` están al final»).

**1.6 · La máquina de estados del precio tiene cuatro estados y el texto nombra tres.**
§3 habla de `PENDING`, `ACTIVE` y `FAILED`; el diagrama añade `EMPTY`, que es donde empieza toda
petición (`SearchPriceProvider.php:52-59`). Leí el diagrama y no supe de dónde salía ese estado.

**1.7 · ¿Veinte avisos o veinticinco?**
§9 abre con *«escucha veinte avisos»* y la tabla de *Lo programado* dice *«los 25 oyentes del
`Indexer`»*. Son 25 (`Indexer.php:157-183`). Un novato no sabe cuál creer.

**1.8 · «rieles de despliegue distintos».**
Aparece en §11, §14 y en Caso 4 («dos plugins de rieles distintos»), y es el argumento de por qué
algo es caro de arreglar. «Riel» no está en el glosario ni se explica. **Debería** haber una línea:
«un *riel* es el guion de despliegue y el contrato sellado con que se publica cada plugin; dos
piezas de rieles distintos no se pueden cambiar en el mismo despliegue».

**1.9 · Tres nombres para lo que puede ser una sola pantalla.**
El capítulo usa «el panel **Akibara**», «el **Centro de Mando**» y «`AdminPanel`/`AdminDashboard`»
sin decir si son lo mismo. §12 se titula con las clases y el cuerpo habla del Centro de Mando.
**Debería** decir en una línea: «el panel Akibara (`admin.php?page=akibara-panel`) tiene una
portada, y a esa portada el proyecto le dice Centro de Mando; la pinta `AdminDashboard`».

**1.10 · «el mapa» aparece 15 veces y nunca se presenta.**
*«(omisión del mapa)»*, *«(corregido — el mapa las daba todas como duras)»*, *«el mapa nunca la
mencionó»*. No sé qué es el mapa, no lo tengo, y no puedo consultarlo. Para el lector al que está
dirigido el capítulo, eso es ruido: lo que importa es qué hace el código. **Debería**
sustituirse por la afirmación en positivo, y dejar la contabilidad de correcciones en un apéndice o
en el `verificacion.json`.

**1.11 · «el propio business ya diagnosticó ese defecto y por eso bajó `SeriesFacts` a prioridad 2».**
`SeriesFacts` no se presentó nunca. No sé qué pinta ni dónde. **Debería** decir «`SeriesFacts`, el
bloque de datos de serie que business pinta arriba de la rejilla».

**1.12 · Vocabulario de la rejilla sin glosario.**
«faceta», «chips», «banda de series», «barra lateral», «rejilla», «PJAX en vuelo» se usan como si
fueran conocidos; el glosario define PJAX pero no faceta ni chip. Para alguien que no conoce la
tienda, §8 es ilegible. **Debería** añadir al glosario: *faceta* (cada filtro: editorial, precio,
disponibilidad), *chip* (la etiqueta con la ✕ que muestra un filtro puesto), *banda de series*.

---

## 2 · Lo que FALTA para poder operar o depurar esto sin ayuda

**2.1 · No hay un solo comando para comprobar la regla más importante del capítulo.**
La regla es *«el proveedor de precio debe ser el único oyente de `akb_search_effective_price`»*, y
el capítulo dice que engancharse ahí apaga la tienda sin mensaje. Falta la línea que responde
«¿quién está enganchado hoy?». **Debería incluir:**
`wp eval 'print_r(array_map("array_keys", array_map(fn($r)=>$r, $GLOBALS["wp_filter"]["akb_search_effective_price"]->callbacks)));'`
(lectura pura, sin disparar hooks de admin) y, al lado, la ruta que ya existe y da el veredicto
entero: `GET /wp-json/akibara/v1/search/admin-status` (`Search.php:196-205`, exige
`manage_woocommerce`).

**2.2 · Pantallas sin ruta.**
El capítulo da `admin.php?page=akibara-panel` y para todo lo demás dice «el panel de descuentos»,
«el panel de rewards», «la pestaña Akibara → Venta → Carrito», «el panel Disponibilidad de la ficha
del producto». Las rutas están en el código y faltan aquí: buscador
`admin.php?page=akibara-search` (`SearchAdminSection.php:130-133` y `AdminDashboard.php:766`),
rewards `admin.php?page=akb-rewards-settings` (`AdminDashboard.php:781`).

**2.3 · Falta el contrato, que es la pieza central del capítulo.**
§10 dice: *«`Plugin.php:1483-1496` expone `checkout_data()` y `pudo_checkout()`, que es todo lo que
el plugin de bloques necesita»*. El contrato en sí —`akibara-business/src/Contracts/CheckoutDataProvider.php`—
no se cita ni una vez, y tiene **seis** métodos (`:74`, `:90`, `:100`, `:110`, `:120`, `:129`):
`structured_cart_data()`, `product_availability()`, `is_valid_rut()`, `format_rut()`,
`phone_validation_error()` y `address_autocomplete_config()`. De esos seis el capítulo sólo nombra
los dos del RUT. **Debería** haber una tabla con los seis y qué se cae si falta cada uno.

**2.4 · Faltan la mitad de los ficheros del plugin de bloques, y el más peligroso es uno de ellos.**
§10 lista `Plugin.php`, `CheckoutFields.php`, `StoreApiIntegration.php`, `Assets.php`,
`Shortcodes.php`. El `ls` del snapshot da **14** ficheros. Los que faltan y son costura:
`ComunaDirectory.php`, `AddressIntegration.php`, `GooglePlacesAddressProvider.php`,
`PudoServiceAdapter.php`, `PudoIntegration.php`, `PudoLog.php`, `BlocksPage.php`,
`RuntimeCompatibility.php`, `CheckoutBlockIntegration.php`. El grave es **`ComunaDirectory.php`**:
carga un fichero **de dentro del plugin ajeno de Blue Express** por ruta
(`'bluex-for-woocommerce/includes/data/chile-communes.php'`, `:26`) y llama a su función global
`bluex_get_communes_by_region()` (`:378-393`). Es la única costura del proyecto que entra al código
de un tercero sin pasar por un hook —justo lo que las reglas del repo prohíben— y el capítulo de
las costuras no la menciona.

**2.5 · Ocho de los 18 mu-plugins no existen para este capítulo, y el «centinela» no tiene nombre.**
La tabla §17 se titula *«Los mu-plugins que son costura, no contexto»* y lista seis; el texto añade
correo, no-sale y bluex-log-retention: diez. El `ls` da 18. No aparecen:
`akibara-abilities-denylist.php`, `akibara-bluex-cotiza-solo-con-comuna.php` (citado en Caso 2 como
«un mu-plugin lo fuerza desde fuera», sin nombre), `akibara-bluex-integrity-watch.php`,
`akibara-bluex-rest-tracking.php`, `akibara-clarity-production-only.php`,
`akibara-discovery-warming.php`, `akibara-provider-access.php` (sólo en la tabla de datos),
`akibara-rankmath-redirections-abilities.php`. Y el «centinela» del correo, que aparece dos veces
en el texto y una en el diagrama del correo, se llama **`akibara-mail-transport-watch.php`**: sin
ese nombre no se puede ir a mirarlo.

**2.6 · El hallazgo de los tres oyentes muertos de Clarity no se puede reproducir con lo que hay aquí.**
§18 concluye *«hoy nadie mide ni los cupones aplicados ni los errores del checkout»*. Falta el dato
que hace fracasar la comprobación obvia: `akibara-clarity-production-only.php:14-25` **quita el tag
de Clarity fuera de producción**, así que en el espejo no se mide nada de nada y el novato concluye
lo contrario de lo que pasa. **Debería** decirlo, y dar la forma de medir (stub de `window.clarity`
en consola).

**2.7 · La cadena del correo no tiene ni una constante ni un asunto.**
§16 y Caso 6 dicen «si las constantes SMTP no están completas» y «mete el original entre corchetes
en el asunto y en dos cabeceras `X-Akibara-*`». Faltan los nombres, que son lo único con lo que se
depura: `AKIBARA_HOSTINGER_MAIL_ENABLED`, `AKIBARA_HOSTINGER_SMTP_HOST` / `_PORT` / `_ENCRYPTION` /
`_USERNAME` / `_PASSWORD` (`akibara-correo-smtp.php:406-421`), definidas en `wp-config.php`; el
prefijo literal del asunto y los nombres exactos de las dos cabeceras
(`akibara-correo-no-sale-a-clientes.php:130-138`); y dónde se define `AKIBARA_CORREO_CAPTURA`.

**2.8 · Las horas de los crons no dicen cuáles están ancladas y cuáles derivan.**
La tabla de *Lo programado* da nueve horas como hechos y sólo explica la herencia en una fila
(*«hora heredada del primer agendado»*). En el código son dos familias distintas:
`Reconciler::ensure_scheduled()` agenda `time() + HOUR_IN_SECONDS` cada 24 h (`Reconciler.php:115`)
—las 12:50 son pura deriva, se mueven solas—, mientras `CatalogOrder` **re-ancla** a una hora
calculada y explica por qué (`CatalogOrder.php:142-160`: la deriva medida fue de ~131 s/día, más
una hora de golpe con el cambio de horario). **Debería** llevar una columna «hora anclada / hora
heredada» y el comando para ver la verdad: `wp action-scheduler list --hooks=<hook> --status=pending`
y `wp cron event list`. Falta también el nombre del capítulo al que remite: *«la lista completa […]
vive en el capítulo de tareas programadas»* → es `16-todo-lo-programado.md`.

**2.9 · La «palanca que nadie usa» no se puede usar con lo que dice el capítulo.**
§5: *«cada URL pasa por un filtro `akibara_navigation_url_<clave>` […] Es el único punto donde se
podría mover `/carrito/` sin tocar código»*. Faltan las claves, que son seis y están en el código:
`home`, `shop`, `cart`, `account`, `contact`, `wishlist` (`NavigationLinks.php:230`, `:242`,
`:257`, `:273`, `:289`, `:297`). Falta además dónde se escribiría ese `add_filter` (un mu-plugin) y
que el HTML viejo sigue cacheado hasta purgar. Y hay un choque de palabras con la tabla de reglas,
que para la misma fila dice «Sí, **sólo por código**»: o es «sin tocar código» o es «sólo por
código», no las dos.

**2.10 · Interruptores del buscador incompletos.**
La tabla de datos lista `akb_search_suggest` y `akb_search_facets`, pero la tabla de crons depende
de un tercero que no aparece en ningún lado: `akb_search_indexing` (`Search.php:34`), el que hace
que `akb_search_reconcile` esté o no agendado. Y faltan los dos filtros de override que son
exactamente la clase de palanca invisible de la que trata el capítulo:
`akb_search_suggest_enabled` y `akb_search_facets_enabled` (`Search.php:335` y `:356`).

**2.11 · La historia del `<h1>` del carrito no tiene dónde mirarse.**
§10 y la tabla de reglas explican que el tema suprime su `<h1>` contando con el shortcode del
plugin de bloques. Falta lo que hace falta para arreglarlo: **el shortcode se escribe en el
contenido de la página** (editor de páginas de WordPress), cosa que no está en ningún fichero del
snapshot; y **no existe ningún shortcode de título para el checkout**: el único que hay devuelve
literalmente «Tu carrito» (`Shortcodes.php:38-42`). O sea que la página de pago o no tiene `<h1>`
o dice «Tu carrito».

**2.12 · La invitación a reseñar no trae ni opción ni valor por defecto.**
Caso 2: *«agenda una acción única con el retraso configurado»*, y el diagrama dice «+3 días» sin
fuente. Faltan `akb_rewards_invite_delay_days` (por defecto **3**) y `akb_rewards_invite_token_days`
(por defecto **30**), `Invitations.php:69-79`.

**2.13 · Cuando el buscador se apaga, el capítulo no dice cómo levantarlo.**
Describe tres veces el apagón silencioso y termina sin salida. Falta la línea de rescate que el
propio proyecto tiene (`ops/rescate-buscador.sh`) y el orden que exige, y falta decir que un
reconstruido pedido a mano no tumba nada (eso sí está en el glosario, pero no en el punto donde
duele).

---

## 3 · Lo que CONTRADICE al código

**3.1 · (grave) «sin proveedor de precio, `serving_ready()` pasa a `false`». Es al revés.**
Frase: tabla «Qué se rompe si se apaga cada pieza», fila `akibara-business`: *«y el buscador se
apaga: sin proveedor de precio, `serving_ready()` pasa a `false`»*. El código trata la **ausencia**
como caso válido y documentado: `resolve_price_provider()` devuelve `null` cuando el hook no tiene
oyentes —su propio docblock dice *«Objeto exacto, **ausencia válida** o forma inválida»*
(`akibara-search/src/EditorialDiscountPublication.php:2992`, `:2995-2997`)— y `adopt_price_snapshot()`
toma esa rama, marca `$adopted_provider_absent = true` y **devuelve una foto válida con token
vacío** (`:3077-3082`), rama que `snapshot_is_fresh()` acepta (`:3115`). Lo que apaga el buscador es
un oyente **de más, en otra prioridad, con otros argumentos o de otra clase** (`:3020-3026`).
**Debería decir:** «sin business el buscador **sigue sirviendo**, con precio base; lo que lo apaga
es un segundo oyente». Importa porque invierte la búsqueda del culpable en la avería más cara del
proyecto.

**3.2 · Caso 5: «las portadas de Akibara están en formato WebP». Son AVIF.**
El mu-plugin se llama literalmente *«Brevo recibe la portada en JPG, no en AVIF»* y explica que
*«las 12.644 portadas de la tienda existen sólo en AVIF»*; su expresión regular sólo casa `\.avif$`
(`mu-plugins/akibara-brevo-portada-jpg-en-el-carrito.php:3-4`, `:11`, `:67`, `:75`). Con «WebP» en
la cabeza, quien vaya a arreglarlo va a probar con el fichero equivocado.

**3.3 · `akibara_gp_asset_ver()` no está en `functions.php`.**
La tabla de §6 dice «Dónde está definida: `themes/akibara-gp/functions.php`» y la tabla de datos
repite «el tema (`functions.php`, `inc/woocommerce.php:169`)». Está en
**`themes/akibara-gp/inc/enqueue.php:37`**. (La segunda, `akibara_gp_brand_logo()`, sí está en
`inc/woocommerce.php:169`.)

**3.4 · Caso 2, paso 4: la comuna no se canonicaliza «delegando en business».**
Frase: *«La comuna se canonicaliza al vuelo en los hooks del Store API, también delegando en
business»*. Quien canonicaliza es el **plugin de bloques con su propia clase**: `CheckoutFields`
recibe un `ComunaDirectory` (`CheckoutFields.php:30`, `:42`), engancha
`woocommerce_store_api_cart_update_customer_from_request` y `…checkout_update_order_from_request`
(`:64-65`) y reescribe la comuna en `canonicalize_address_comuna()` (`:141-164`, `:223`). El
catálogo de comunas sale de **Blue Express** (`ComunaDirectory.php:26` y `:378-393`), no de
business. Business aquí no participa.

**3.5 · §10: «La comprobación en cuatro pasos […] Si algo falla, no registra nada y se calla».**
El cuarto paso no es parte de la puerta. En `akibara-cart-checkout-blocks/src/Plugin.php`, los
`return` que abortan todo son tres (`:30-32`, `:35-37`, `:40-42`); `pudo_checkout()` se consulta
después (`:48-50`) y su ausencia sólo deja fuera `PudoIntegration` (`:61-63`) — el RUT, la comuna,
los avisos y los shortcodes se registran igual. Falta además una quinta puerta que el capítulo no
menciona: `interface_exists('\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface')`
para `CheckoutBlockIntegration` (`:57-59`).

**3.6 · §9: «escucha veinte avisos». Son 25.**
`akibara-search/src/Indexer.php:157-183` registra 25 oyentes; la propia tabla de crons del capítulo
dice 25.

**3.7 · Las siete funciones puente están citadas en líneas que no son.**
§7 da `(:91)`, `(:100)`, `(:112)`, `(:123)`, `(:132)`, `(:152)` y `(:175)`. En
`akibara-search/akibara-search.php` están en **95, 105, 116, 127, 139, 161 y 191**. Quien abra la
152 encuentra un comentario; el desfase llega a 16 líneas en la última. (El rango global del
bloque, `:91-233`, sí es correcto.)

**3.8 · §8 dice «Dónde se engancha exactamente» y falta el enganche que pinta la barra lateral.**
La lista nombra `is_active_sidebar` (`:1077`), que sólo **abre el hueco**, pero no
`dynamic_sidebar_before` prioridad 4 (`facets-render.php:1150`), que es donde el buscador imprime
la barra. Con una lista anunciada como exacta, la omisión engaña.

**3.9 · Caso 5 cita como «la ventana» dos líneas que no son la ventana.**
*«un mu-plugin que abre una “ventana” […] (`:70` y `:76`)»*. Las líneas 70 y 76 son los dos usos de
`SocialImageDerivatives` dentro del filtro; la ventana son
`add_filter($gancho, 'akibara_brevo_abrir_ventana_jpg', 9)` y `…'cerrar…', 11` (**`:128-129`**).
Además el mu-plugin tiene **dos** `class_exists` y el primero es el del plugin de Brevo
(`:118`); el capítulo sólo nombra el de business, aunque luego cite el comentario que habla de «los
dos».

**3.10 · §10 y la tabla de reglas se contradicen con el comentario del propio tema sobre el `<h1>`.**
El capítulo dice que el tema suprime el `<h1>` *«dando por hecho que el shortcode del plugin de
bloques pone uno»*. El comentario del tema dice otra cosa: *«Pages con template override que
renderea H1 propio (cart-empty.php)»* (`themes/akibara-gp/inc/page-header.php:49-50`) — y
`woocommerce/cart/cart-empty.php` **no tiene ningún `<h1>`** (verificado con `grep`). O sea que la
razón escrita en el tema es falsa y la única fuente real del `<h1>` es el shortcode. Merece decirse
así, porque es una pista más de que ese `<h1>` está colgando de un hilo.

**3.11 · Desfases menores de línea, agrupados** (cada uno manda al lector a la línea equivocada):
`Support/RateLimit.php:306` → **305**; `StockLabels.php:175-177` → **176-178**;
`Assets.php:258-262` → **261-265**; `SearchAdminSection.php:105-107` → **104-107**;
`DisponiblesRoute.php:192` → **191**.

**3.12 · «`akibara-rewards.php:182`» en la ficha de §1 no es el arranque.**
La línea 182 es el `add_action` del *shim* de `spar_get_user_points`; el arranque de rewards es
`:191`. El capítulo lo cita bien en el esquema y mal en la ficha.

---

## 4 · Lo que contradice a `verificacion.json` (área `cross-interacciones`)

**4.1 · Líneas de las funciones puente: los dos se equivocan, y distinto.**
`verificacion.json` → `errores[3]`: «95, 105, 117, 127, 140, 162, 191». Capítulo: «91, 100, 112,
123, 132, 152, 175». Código: **95, 105, 116, 127, 139, 161, 191**. Manda el código: el capítulo está
mal en siete de siete; la verificación, en tres de siete.

**4.2 · Enganches de `facets-render.php`: aquí el capítulo tiene razón y la verificación no.**
`verificacion.json` → `errores[4]` dice «`template_redirect` prioridad 0 en 1149» y
«`woocommerce_no_products_found` en 1160-1163». El código dice **1148** y **1161-1164**, que es
justo lo que escribió el capítulo. Lo anoto para que nadie «corrija» el capítulo hacia atrás.

**4.3 · El endpoint que no es REST: ninguna de las dos citas es la buena.**
`verificacion.json` → `omisiones[2]`: «facets-render.php:1098-1149». Capítulo: «`:1136-1148`».
El código: el detector de la cabecera vive en `:1091-1110`, la función que responde y hace `exit`
es `akibara_gp_akb_render_facet_options_fragment()` en **`:1137-1147`** y el enganche en **`:1148`**.

**4.4 · El capítulo hereda una cobertura del 82 % y la presenta como inventario cerrado.**
`verificacion.json` cierra con `cobertura_estimada: 82` y una lista explícita de lo no cubierto:
«los 17 mu-plugins en detalle línea a línea (sólo los que tocan a los plugins propios), la
superficie de admin de rewards, los bundles `.min`». El capítulo titula §17 *«Los mu-plugins que son
costura, no contexto»* sin decir que la revisión fue parcial — y el hueco se nota: la costura
bloques→Blue Express por `ComunaDirectory` (punto **2.4**) no está ni en la verificación ni en el
capítulo. **Debería** llevar una línea de alcance: «lo que sigue cubre los mu-plugins con arista
conocida; los ocho restantes no se revisaron para este capítulo».

**4.5 · La forma de medir se perdió por el camino.**
Las seis `sospechas_nuevas` del verificador traen cada una su `como_verificar` (curl con
`-A AkibaraQA`, conteo de `<h1>`, mu-plugin de prueba con prioridad 10 y luego 11, stub de
`window.clarity`). El capítulo conserva **una sola** (el conteo de `<h1>`) y publica las otras cinco
como afirmaciones sin forma de comprobarlas. Para un lector que tiene que depurar, esa es la parte
útil.

**4.6 · Una afirmación del capítulo no viene del verificador y es la que está mal.**
`sospechas_refutadas[2]` documenta que el fatal sin business es política escrita del tema
(`functions.php:16-22`) y no dice nada del buscador. El *«y el buscador se apaga»* que el capítulo
añade a esa misma fila no está respaldado por la verificación y lo desmiente el código (**3.1**).

---

## 5 · Diagramas

**Validación:** los seis compilan con `mmdc` 11.17.0 sin advertencias
(`caso-busqueda`, `caso-compra`, `caso-descuento`, `cron`, `estados-precio`, `piezas`).

**5.1 · No se ven al leer el capítulo.** Están insertados como
`![Piezas y costuras](diagramas/19-…-piezas.mmd)`: una imagen Markdown apuntando a un `.mmd`, que
ningún visor pinta — el lector ve un icono roto seis veces. (Es la convención de los capítulos 17 y
18 también, así que es decisión del conjunto, pero para el público de este documento hay que
resolverlo: bloque ```mermaid embebido, o un `.svg` exportado junto al `.mmd`.)

**5.2 · `cron.mmd` tiene un trabajo que el capítulo no nombra en ninguna parte.**
`03h00 domingo akb_order_preventa_t7_reminder_scan (business)`. No está en la tabla de *Lo
programado* ni en el texto. Existe (`OrderPreventa/RemindersCron.php:48`), pero el lector no puede
cruzarlo con nada. O se añade la fila, o se saca el nodo.

**5.3 · `estados-precio.mmd` introduce `EMPTY`, que el texto nunca menciona** (ver 1.6).

**5.4 · `piezas.mmd` no dibuja la arista que §11 presenta como hallazgo.**
El texto dice que rewards y el plugin de bloques **extienden el mismo endpoint del Store API** y que
el carrito es la superficie donde el cliente ve a los dos a la vez. En el diagrama no hay ninguna
línea entre `REWARDS` y `BLOQUES`. Falta `REWARDS -.->|"los dos extienden CartSchema del Store API"| BLOQUES`.

**5.5 · `piezas.mmd` contradice al texto en el mu-plugin de retención.**
El texto (§17) dice que `akibara-as-retention.php` afecta a *«los tres plugins que encolan
trabajo»*; el diagrama sólo traza `AS14 --> SEARCH` y `AS14 --> REWARDS`. Falta `AS14 --> BUSINESS`.

**5.6 · Las aristas de `piezas.mmd` no usan la clave que el propio capítulo enseña.**
El capítulo abre con «las cinco formas de hablarse» (clase, hook, option, tabla, REST/JS) y es su
mejor idea. En el diagrama, unas aristas llevan etiqueta («llamada dura», «hook
`akb_search_effective_price`») y ocho van **mudas** (`SMTP --> BUSINESS`, `NOSALE --> REWARDS`,
`HTTPS --> BUSINESS`, `CATALOGO --> SEARCH`, `BUSINESS --> TABLAS`, `LS --> LSC`…). Mirando la
figura no se puede decir si una línea es una llamada de clase o un hook, que es justo lo que hay que
saber para estimar el daño. **Debería** etiquetar las cinco formas (o darles estilo: línea sólida =
llamada dura, punteada = hook, gruesa = dato compartido) **y añadir una leyenda**: hay cuatro
colores (propio / tercero / persona / dato) y ningún nodo que los explique.

**5.7 · `piezas.mmd` deja fuera dos terceros y dos contratos que el texto sí nombra.**
No hay nodo de **Rank Math**, aunque el texto lo liste entre los terceros de las costuras y §8 cite
dos filtros suyos (`facets-render.php:782` y `:833`). Tampoco aparecen el **bus de eventos de
JavaScript** (`akb:pjax`) ni la **clase CSS `.akb-cart-trigger`**, que son dos de las filas de
«Datos que deja» y una de las cinco formas de hablarse.

**5.8 · `caso-compra.mmd` atribuye a los bloques una llamada que no hacen.**
`Bloques->>Bluex: cotiza con región y comuna canónicas` da a entender que el plugin propio cotiza
contra Blue Express. Quien cotiza es el método de envío de Blue dentro de WooCommerce; lo que hace
el plugin de bloques es **canonicalizar la comuna** para que esa cotización no falle
(`CheckoutFields.php:141-164`) leyendo el catálogo del propio Blue (`ComunaDirectory.php:26`).
**Debería** decir `Bloques->>Woo: comuna canónica en la sesión` y `Woo->>Bluex: cotiza`.

**5.9 · `caso-compra.mmd` inventa un número que el texto no da.**
`agenda akb_rewards_send_review_invite a +3 días`. El texto dice «el retraso en días configurado».
Alinear los dos y nombrar la opción (`akb_rewards_invite_delay_days`, por defecto 3).

**5.10 · `caso-descuento.mmd` pierde un oyente de los tres.**
El texto (Caso 3, paso 2) dice que `akibara_taxonomy_discount_pct_saved` lo escuchan **tres**
sitios: `LscPurger.php:62`, `TaxonomyDiscount.php:152` y `Indexer.php:182`. El diagrama sólo dibuja
dos destinos (Search y LiteSpeed) y el tercero, el propio módulo de descuentos, no está.

**5.11 · `caso-busqueda.mmd` omite la ruta que el texto destaca.**
El paso 3 del texto dice que con el campo vacío responde **`/search/popular`**; en el diagrama sólo
existe `/search/suggest`. Y la bifurcación del 429 («pasada la cuota, el desplegable se queda
vacío») no es una rama del diagrama, aunque el paso de la cuota sí está dibujado.

**5.12 · `cron.mmd` repite el problema de las horas.**
Muestra nueve horas sin distinguir ancladas de derivantes (ver 2.8). Basta un sufijo por entrada
(`↩ hora heredada`) para que el diagrama deje de prometer precisión que no tiene.

**5.13 · ¿Se entienden solos?** Los tres de secuencia y el de estados, sí: tienen actores nombrados,
`autonumber` y notas que dicen qué se rompe. **`piezas.mmd`, no**: 25 nodos, cuatro colores sin
leyenda, ocho aristas mudas y tres subgrafos cuyo criterio de agrupación («El centro de gravedad»,
«Lo que se ve») no está explicado en el texto. Es el primer diagrama del capítulo y el que más
lector novato va a mirar.

**5.14 · Falta el diagrama que el capítulo pide a gritos: el orden de arranque.**
El bloque de `plugins_loaded` está en ASCII dentro del texto y es la causa raíz del Caso 5 y del
empate de la prioridad 3. Un `sequenceDiagram` o un `timeline` de una columna (mu-plugins → 15 → 20
→ 25 → 30 → `init:999`) haría más por el lector nuevo que cualquiera de los seis actuales.

---

## Respuesta del redactor

*(2026-09-12 · todo lo de abajo verificado contra `scratchpad/snapshot-prod`, con los seis `.mmd` —ahora siete—
revalidados con `mmdc` 11.17.0, cero advertencias.)*

**Aplicado sin reservas:** 1.1, 1.2, 1.3, 1.5, 1.6, 1.7, 1.8, 1.9, 1.10, 1.11, 1.12 · 2.1, 2.2, 2.3, 2.5, 2.6, 2.7,
2.8, 2.9, 2.10, 2.11, 2.12, 2.13 · 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9, 3.10, 3.12 · 4.1, 4.3, 4.4, 4.5, 4.6 ·
5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 5.9, 5.10, 5.11, 5.12, 5.13, 5.14. **3.1 era el punto grave y tenías razón**:
`resolve_price_provider()` devuelve `null` en dos ramas distintas (`:2996-2998` cuando el hook ni existe, `:3017-3019`
cuando existe pero está vacío) y `adopt_price_snapshot()` acepta esa rama devolviendo token vacío (`:3074-3082`), que
`verify_adopted_price_snapshot()` da por buena (`:3114-3117`). El capítulo decía justo lo contrario; ya no.

**4.2 confirmado a tu favor** y anotado en el texto: el capítulo tenía razón (`facets-render.php:1148` y `:1161-1164`)
y la verificación no. No se «corrigió hacia atrás».

Y ahora lo que **no** se cambió, porque la comprobación contra el código te da a ti la razón parcial o ninguna:

**1.4 · Los mu-plugins apuntados en `plugins_loaded:20` son DOS, no uno.** Además de
`akibara-brevo-portada-jpg-en-el-carrito.php:131` está `akibara-bluex-cotiza-solo-con-comuna.php:82`
(`grep -rn "plugins_loaded" mu-plugins/` devuelve los dos). Importa porque son el contraste perfecto: el de Blue sólo
pregunta por clases del plugin ajeno, que a esa altura ya existen, así que **sí** engancha; el de Brevo pregunta por una
clase de business y se queda sin nada. El capítulo nombra los dos y explica la diferencia.

**3.11 · Tres de tus cinco desfases de línea no lo son.**
- `StockLabels.php:175-177` está **bien**: la línea 173 es el `apply_filters`, y el `return` ternario que escribe
  «Preventa» a mano ocupa 175-177. Tu 176-178 mete el `}` de cierre del método y deja fuera la primera línea del
  `return`.
- `Assets.php:258-262` está **bien**: 258 es la firma de `collection_notice_applies()` y 259-262 es la lectura cruda de
  `akb_sf_enabled` con su `in_array`. Tu 261-265 empieza dentro del `return false;` y termina en la guarda de invitado.
  (Lo que sí corregí de esa frase es otra cosa: el gate real no es «texto no vacío», son **interruptor maestro + el
  cliente no dado de baja**; el texto vacío es un tercer camino que se resuelve en `CheckoutBlockIntegration.php:159`.)
- `DisponiblesRoute.php:192` está **bien**: `grep -n woocommerce_before_shop_loop` devuelve 192 en
  `Modules/DisponiblesRoute/DisponiblesRoute.php` y 160 en `PreventasRoute.php`, que es exactamente lo que decía el
  capítulo.
- `Support/RateLimit.php:306 → 305` **sí** era tuyo, corregido.
- `SearchAdminSection.php:105-107 → 104-107` es indiferente (104 abre el docblock, 105-106 es la frase); adopté el tuyo
  por ser el bloque completo.

**3.5 · Dos de tus líneas van una corrida.** Los tres `return` que abortan (`:30-32`, `:35-37`, `:40-42`) y la quinta
puerta `interface_exists` son tuyos y están aplicados, pero en el snapshot `pudo_checkout()` se consulta en **`:47-49`**
(no `:48-50`) y `PudoIntegration` se registra en **`:62-64`** (no `:61-63`). El capítulo usa los números verificados.

**2.4 · La costura de `ComunaDirectory` es real y está ahora en el capítulo, pero no como «lo que las reglas del repo
prohíben».** La regla de la casa es *no editar código de terceros*, y esto no lo edita: hace `require_once` de un
fichero de **datos** del plugin de Blue y llama a su función global. Es una dependencia de la **ruta interna** de un
tercero, que es frágil de otra manera (una actualización de Blue puede moverla) y por eso merece estar documentada.
Además falla hacia abierto, cosa que tu punto no dice: si el fichero no está o viene roto, `is_available()` devuelve
`false` (`:120-122`), el desplegable no se pinta (`Assets.php:339`) y el checkout se queda con el campo de texto libre.
El capítulo lo cuenta así, con esos matices.
