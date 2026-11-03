# Crítica de lector novato — `12-checkout-blocks-campos-chilenos-y-pudo.md`

Quién escribe: alguien que llega hoy al proyecto, no sabe WordPress, ni WooCommerce, ni el
negocio, y tiene que poder **operar y depurar el carrito y el pago de bloques** con este
capítulo en la mano. Leí el capítulo entero (1.396 líneas) y sus cinco diagramas, después
abrí el código del snapshot de producción
(`scratchpad/snapshot-prod/plugins/akibara-cart-checkout-blocks/`, más `akibara-business` y
el tema donde hacía falta cruzar) y comprobé una a una las citas que me dieron duda. Los
cinco `.mmd` los compilé con `mmdc`.

Nota de contexto, para que se lea en su sitio: **el capítulo es bueno**. Absorbió las diez
omisiones y los seis errores de `verificacion.json`, mide casi siempre bien el radio de las
averías y sus citas `fichero:línea` son de las más exactas que he visto en este corpus —
comprobé más de sesenta y fallaron dos. Casi todo lo que sigue son huecos de **operación**
(dónde se edita una clave, cómo se corren las pruebas, dónde vive el estado de PUDO) y media
docena de afirmaciones puntuales que el código desmiente.

---

## 1 · Lo que NO entendí

**1.1 · «Veinte piezas» no cuadra con ninguna de las dos cuentas que da la misma frase.**
Línea 153: «**Veinte piezas**: catorce ficheros PHP en `src/` y nueve ficheros JavaScript en
`assets/js/` (más tres CSS)». 14 + 9 = 23, no 20. Y la sección numera **18**. Comprobé el
`ls` del snapshot: 14 PHP, 9 JS, 3 CSS, 1 SVG = 27 ficheros. **Debería decir**: «Dieciocho
piezas, que reparten 14 ficheros PHP, 9 JavaScript, 3 CSS y una imagen» — o quitar el número
y dejar sólo el desglose. Un novato usa ese número como lista de control.

**1.2 · «encolar», «desencolar» y «handle» no están en el glosario, y la pieza 17 entera
depende de ellos.** Frases: «Son CUATRO desencolados», «cuántos handles **declara** el
tercero», «retira el handle `akibara-cart-empty-reload`». El glosario define `bloque`,
`bundle`, `inlinar` y `transient`, pero no esto. **Debería añadir**: «**encolar (enqueue)** —
pedirle a WordPress que incluya un fichero JS o CSS en la página. **handle** — el nombre corto
con el que se pide (`akibara-comuna-combobox`); es la única forma de referirse a él para
quitarlo. **desencolar (dequeue)** — pedir que uno ya encolado no se sirva».

**1.3 · «los inputs son controlados por React» y «el *native value setter*» aparecen sin
presentación.** Pieza 5: «Los inputs son **controlados por React**: escribirles exige el
*native value setter* y disparar `input` y `change` a mano». No sé qué es React ni por qué
escribir en una caja de texto necesita un truco. **Debería decir**, en una frase: «Los campos
del pago los dibuja una biblioteca (React) que guarda el valor en su propia memoria; si un
guion ajeno escribe en el `<input>`, React no se entera y lo revierte. Por eso se usa el
`setNativeValue()` (`assets/js/comuna-combobox.js:255-277`), que escribe y además finge los
eventos que React escucha» — y meter «React» en el glosario.

**1.4 · «distancia de Levenshtein» se usa como si todo el mundo la conociera.** Aparece dos
veces (pieza 5, ⚠ de sugerencias) y es la base de la regla de negocio «umbral de parecido por
largo». **Debería decir**: «cuántas letras hay que cambiar, añadir o borrar para convertir un
texto en el otro: de `renaca` a `reñaca`, una».

**1.5 · «RUT» y «dígito verificador» no se explican nunca.** El capítulo dice que el público
no conoce el negocio, y el RUT es la primera de las cuatro familias. **Debería decir**: «el
RUT es el número de identidad chileno; termina en un dígito (o una K) que se calcula desde
los anteriores, así que un RUT mal tecleado se detecta sin consultar a nadie».

**1.6 · «payload» se usa once veces y no está en el glosario.** «descartan el payload entero»,
«el payload se devuelve vacío». **Debería decir**: «**payload** — el paquete de datos que
viaja del servidor al navegador dentro de la caja `extensions` de la Store API».

**1.7 · El «contrato» de Business se nombra ocho veces y nunca se dice dónde está.**
`CheckoutDataProvider`, `checkout_data()`, `structured_cart_data()`, `is_valid_rut()`,
`phone_validation_error()`, `address_autocomplete_config()`, `pudo_checkout()`. Si mañana
falla «el contrato», con este capítulo no sé qué fichero abrir. **Debería decir**: la interfaz
es `akibara-business/src/Contracts/CheckoutDataProvider.php` y el objeto lo entrega
`akibara-business/src/Plugin.php:1482-1495`.

**1.8 · La palabra «prioridad» significa dos cosas opuestas en el mismo capítulo, y el
glosario sólo explica una.** El glosario dice «más bajo, antes». Con eso entiendo la fila
`woocommerce_store_api_checkout_update_order_from_request @1` (antes que Blue @10). Pero dos
párrafos después el ⚠ dice que en `woocommerce_blocks_loaded` **gana el que llega después**,
porque WooCommerce guarda los manejadores en un array por nombre. Son dos mecanismos
distintos con la misma palabra. **Debería decir**, en el ⚠: «Aquí "ganar" no es la prioridad
del hook: es sobrescribir una entrada de un array. Lo primero ordena *cuándo corre cada uno*;
lo segundo decide *cuál existe*».

**1.9 · El «fail open» de la comuna se cuenta como una cosa y son dos mecanismos distintos.**
«Sin catálogo de comunas no hay combobox y la validación de comuna se apaga entera
(`src/Assets.php:338-341`, `src/CheckoutFields.php:180-182`)». Leyendo el código son tres
puertas: los tres hooks **ni se registran** (`src/CheckoutFields.php:63-67`), el validador
además sale temprano (`:180-182`), y el combobox no se encola (`Assets.php:339-341`).
**Debería decir** las tres, porque si mañana la validación de comuna no bloquea, el orden de
lo que hay que mirar es exactamente ése.

**1.10 · Salto lógico: `bluex_pudo` es a la vez «el canal que Akibara pisa» y «la extensión
de lectura que sigue siendo de Blue».** Pieza 11: «Akibara registra su propio manejador de
escritura con el **mismo nombre de canal** (`bluex_pudo`)». Caso 2, paso 7: la tarjeta muestra
el punto «leídos de la extensión de **lectura** `bluex_pudo`, que **sigue siendo la del propio
plugin de Blue**». Leí las dos veces pensando que una contradecía a la otra. Es cierto —son
dos registros distintos de la Store API con el mismo nombre—, pero el capítulo nunca lo dice.
**Debería decir**, en la pieza 11: «La Store API tiene dos registros separados bajo el mismo
nombre: el de **lectura** (qué datos se publican en el carrito) sigue siendo de Blue; el de
**escritura** (`POST /cart/extensions`) es el que Akibara sustituye».

**1.11 · «SlotFill … dentro de `ExperimentalOrderShippingPackages`» — no sé si eso es un
riesgo.** La palabra «Experimental» está en el nombre de una API de WooCommerce y el capítulo
no la comenta. **Debería decir** si una subida de WooCommerce puede llevarse por delante la
tarjeta del punto de retiro, y qué se mira para saberlo.

---

## 2 · Lo que FALTA para poder operar o depurar esto sin ayuda

**2.1 · No hay ninguna sección de verificación, y el capítulo se equivoca al decir dónde
viven las pruebas.** Dice: «La prueba `PluginContractTest` … **no viaja en el ZIP publicado**
… vive en el árbol de trabajo, junto al resto de `tools/test-*.php`». Comprobado: en
`tools/` no hay ningún `test-*.php` de este plugin. Las pruebas están en
`public_html/wp-content/plugins/akibara-cart-checkout-blocks/tests/`: **12 ficheros PHP** en
`tests/php/` (incluido `PluginContractTest.php`) y **11 ficheros `*.test.js`**. Las corre el
gate `tools/akibara-releases/docker-checkout.sh` (`node --test` en su línea 121; `phpunit`
con `tools/akibara-business/phpunit.xml.dist` en las 130-131). **Debería añadirse** una
sección «Cómo se prueba» con esas dos rutas y ese comando; hoy el capítulo nombra dos clases
de prueba y ninguna ruta.

**2.2 · No se puede rotar la clave de Google con este capítulo.** El ⚠ de la pieza 9 dice
que la clave «viaja en el HTML de cada pago» y que la protección «hay que verificar fuera de
este repositorio», pero nunca dice **dónde se escribe la clave**. En el código: option
`akibara_google_maps_api_key`, con la constante `AKB_GOOGLE_MAPS_API_KEY` de `wp-config.php`
teniendo prioridad, y campo propio en el panel con validación de formato
(`akibara-business/src/Modules/AddressAutocomplete/AddressAutocomplete.php:28`, `43-49`,
`214-236`). El propio panel dice ahí la restricción que hay que poner:
`https://akibara.cl/*` en Google Cloud Console. **Debería decir** exactamente eso, más que
con la constante puesta el campo queda de sólo lectura.

**2.3 · La pieza más grande del capítulo (`PudoIntegration`, 829 líneas) no tiene «Dónde lo
opera el dueño» ni «Qué ve el cliente», y no dice dónde vive el estado.** Tres huecos
concretos: (a) el capítulo nunca dice **cómo aparece la tarifa «Punto Blue Express»** en el
listado —quién la registra, en qué zona de envío, dónde se enciende—, así que no puedo
reproducir el paso 1 del caso 2; (b) el estado autoritativo de PUDO vive en **options con
prefijo `akb_pudo_state_`**
(`akibara-business/src/Integrations/Bluex/Pudo/PudoMutationRepository.php:31`) y el capítulo
no lo nombra ni en «Datos que deja» ni en el caso 8, que es justo donde hay que ir a mirar
cuando «se pierde el punto de retiro»; (c) la memoria del proyecto avisa «NUNCA pulsar
"Aplicar" en Blue → Zonas» y el capítulo no repite esa baranda en el único capítulo donde
alguien va a entrar a esa pantalla.

**2.4 · El capítulo depende del código de `bluex-for-woocommerce` y ese código no está en el
snapshot ni lleva versión.** Se citan `chile-communes.php`, `bluex_get_communes_by_region()`,
`bluex-blocks-city-select` («su `filterAddressFields()` devuelve los campos intactos y la
única mutación está comentada»), el canal `bluex_pudo` y el renombrado a `bluex-ex`. Nada de
eso se puede comprobar: el snapshot sólo trae los cuatro plugins propios y el tema. En el
árbol de trabajo, `tests/php/VendorPudoContractTest.php` sí anota el dato que falta: Blue
Express **3.3.1**, en producción desde el 2026-08-31, revisado el 2026-09-12. **Debería
decir**, en la cabecera de «Con el plugin de terceros de Blue Express»: la versión contra la
que está escrito el capítulo, la ruta (`public_html/wp-content/plugins/bluex-for-woocommerce/`)
y que el snapshot no la incluye.

**2.5 · El registro de PUDO se puede leer, pero no buscar.** Se dice la pantalla
(*WooCommerce → Estado → Registros*) y la fuente (`akibara-pudo`), y eso está bien. Falta el
**nivel** —es `error` (`src/PudoLog.php:64`)—, el nombre de fichero con el que WooCommerce lo
guarda (`akibara-pudo-<fecha>-<hash>.log`) y cuánto se conserva. Sin eso no puedo hacer
`grep` por SSH ni saber si el incidente de hace tres semanas todavía existe.

**2.6 · Un fichero publicado que el capítulo no nombra, y lo que el cliente ve por él.**
`assets/images/google-maps-logo-dark-gray.svg` está en el ZIP y no aparece en ninguna parte
del capítulo. Lo usa `GooglePlacesAddressProvider`, que además de la constante `ID` publica
el nombre visible «Google Places» y el `<img>` de marca que WooCommerce exige debajo del
buscador (`src/GooglePlacesAddressProvider.php:24`, `27-31`). La pieza 9 lo despacha como
«identificador único del proveedor». **Debería decir**: qué es ese logo, que es obligación
del acuerdo con Google mostrarlo, y que si desaparece el fichero el cliente ve una imagen
rota bajo el campo Dirección.

**2.7 · Hay TRES filtros de código en este plugin y el capítulo documenta uno.** Dice, en la
pieza 3: «El único interruptor es el filtro por código `akibara_comuna_directory`». Los otros
dos son `akibara_blocks_shipping_hint_text` (`src/Assets.php:177-180`) y
`akibara_blocks_collection_notice_text` (`src/CheckoutBlockIntegration.php:171-174`). No son
teóricos: el propio tema los nombra en su código
(`themes/akibara-gp/inc/woocommerce.php:555-556`). Y tienen una consecuencia que el capítulo
afirma al revés: caso 6, bifurcación A dice «lo deja vacío: el aviso se apaga. **Es el único
interruptor que tiene**» — un filtro puede volver a encender un texto vaciado, porque el
`apply_filters` corre **después** de `shipping_hint_copy()`. **Debería listar los tres** en
«Reglas de negocio» y en «Datos que deja», como puntos de extensión de primera parte.

**2.8 · Las cifras «16 regiones, 346 comunas» sostienen una garantía de diseño y no hay forma
de re-medirlas.** Sobre esa unicidad descansa «los 346 nombres son únicos, así que no hay
ambigüedad» (pieza 5) y el cruce del buscador de Google. **Debería decir** el comando:
`docker compose exec -T cron wp eval 'var_dump(count(Akibara\CartCheckoutBlocks\ComunaDirectory::…))'`
o, más simple, el `wp eval` sobre `bluex_get_communes_by_region()` contando regiones, comunas
y nombres repetidos.

**2.9 · El ⚠ de los shortcodes queda abierto y no dice cómo cerrarlo.** Termina: «Falta sólo
leer el contenido de la página para cerrarlo». Es la única duda del capítulo que puede estar
poniendo «Tu carrito» como `<h1>` de la pantalla de pago —y eso lo indexa Google. **Debería
decir** el comando:
`ssh akibara "wp post get \$(wp option get woocommerce_checkout_page_id) --field=content" | grep akibara_cart_blocks_title`.

**2.10 · «Qué ve el cliente» falta en 10 de las 18 piezas.** Lo tienen las piezas 1, 3, 5 y 8;
no lo tienen 2, 4, 6, 7, 9, 10, 11, 12, 13, 14, 15, 16, 17 y 18 — incluidas la tarjeta del
punto de retiro y el buscador de direcciones, que son las dos superficies más visibles del
capítulo. Igual con «Dónde lo opera el dueño». **Debería** completarse o decirse explícitamente
«esta pieza no tiene superficie visible», que también es información.

**2.11 · El único correo del capítulo se cita sin fuente.** Caso 2: «El correo de pedido lo
manda WooCommerce con su asunto habitual («Tu pedido en Akibara ha sido recibido»)». Es el
único asunto de correo de todo el capítulo y no lleva `fichero:línea` ni dice dónde se edita
(es plantilla de WooCommerce, y el remite y la marca los toca `EmailBrand` de Business).
**Debería decir** de qué pantalla sale ese asunto (*WooCommerce → Ajustes → Correos →
Pedido procesándose*) para poder comprobarlo en la bandeja, como exige el estándar de QA.

**2.12 · Faltan en «Datos que deja» dos options de las que el capítulo depende.**
`akibara_address_autocomplete_enabled` (se nombra en el texto pero no en la tabla) y
`akibara_google_maps_api_key` (no se nombra en ninguna parte). La tabla sí lista otras cuatro
de sólo lectura, así que la ausencia se lee como «no existen».

**2.13 · La validación de comuna del servidor recorre envío **y** facturación, y el capítulo
no lo dice.** `validate_chilean_comuna()` itera `array('shipping','billing')` y devuelve al
primer grupo con error (`src/CheckoutFields.php:184`, `204`, `213`). O sea que una comuna mala
en la dirección de **facturación** bloquea el pedido igual, aunque el envío esté perfecto — y
ese campo suele estar plegado en el formulario. Es exactamente el tipo de «no puedo pagar y no
veo dónde» que el capítulo persigue en otros sitios. **Debería decir**lo en la pieza 3 y en la
tabla del caso 1.

---

## 3 · Lo que CONTRADICE al código

**3.1 · El ejemplo «Providen» está mal calculado, y es el único que sostiene ese ⚠.**
Frase: «Con «Providen» el cliente sugiere «Providencia» y el servidor no sugiere nada
(**distancia 4 > umbral 3**)». Calculado: `levenshtein("providen","providencia")` = **3**, y
`strlen("providen")` = 8, que cae en el umbral **3** (`src/ComunaDirectory.php:188-190`). Como
la comparación es `if ($distance > $threshold) continue`, 3 no es > 3: **el servidor SÍ sugiere
«Providencia»**, igual que el cliente. El ejemplo demuestra lo contrario de lo que afirma.
El caso bueno lo da el propio comentario del JS (`assets/js/comuna-combobox.js:178-181`):
«Santiago Centro» → el cliente sugiere «Santiago» por contención, el servidor no sugiere nada
porque `levenshtein("santiago centro","santiago")` = **7**. **Debería decir** «Santiago
Centro», no «Providen».

**3.2 · El coste que se le atribuye al código muerto no es el que tiene.** El ⚠ de la pieza 17
dice: «el `add_action` corre en `wp_enqueue_scripts` de **toda** petición del front, y
`BlocksPage::is_cart()` llama a `has_block()`, que **resuelve el post** de la página de
carrito». Leyendo `src/BlocksPage.php:45-52`: la primera línea es
`if (! function_exists('is_cart') || ! is_cart()) { return false; }`, así que en cualquier
página que no sea el carrito la función sale antes de tocar `wc_get_page_id()` y `has_block()`.
El coste real en «toda petición del front» es **una llamada a función**, no una resolución de
post. El hallazgo sigue en pie (es código muerto y la REGLA DE ORO lo condena), pero el
argumento de rendimiento no. **Debería decir**: «el coste por petición es despreciable; el
motivo para quitarlo es que es pegamento sin tercero al que pegarse».

**3.3 · El namespace no está «copiado a mano en los dos bundles»: sólo en uno.**
⚠ de la pieza 6: «El **namespace** `akibara_cart_checkout_blocks` también está copiado a mano
en los dos bundles del navegador (`assets/js/cart-checkout-blocks.js:5` y
`assets/js/checkout-inner-blocks-frontend.js:15`)». En el primero la línea es
`var namespace = config.namespace || 'akibara_cart_checkout_blocks';` — o sea que **lee el
valor que el servidor le inlina** desde la constante (`src/Assets.php:163`:
`'namespace' => StoreApiIntegration::NAMESPACE`), y el literal es sólo un respaldo para
cuando el inline falta. Si mañana se cambia la constante, ese bundle la sigue. El único copiado
duro de verdad es `checkout-inner-blocks-frontend.js:15`, que no recibe nada del servidor.
**Debería decir** eso, que además es más interesante: el bundle desprotegido es justo el de
los dos bloques internos.

**3.4 · «No hay prueba» sobre el override del canal de PUDO — sí la hay.**
⚠ de la pieza 11: «No hay prueba, ni comprobación en tiempo de ejecución, ni una línea de
registro si el manejador permisivo de Blue acabara mandando».
`public_html/wp-content/plugins/akibara-cart-checkout-blocks/tests/php/VendorPudoContractTest.php`
existe exactamente para eso: fija los dos handles que se desencolan, el namespace `bluex_pudo`
y el origen `https://widget-pudo.blue.cl` con el tipo `pudo:select`, y su docblock deja escrita
la revisión contra Blue 3.3.1 del 2026-09-12. Lo que de verdad falta —y es lo que hay que
decir— es que **ninguna prueba comprueba quién gana el registro en tiempo de ejecución**: la
prueba vigila los literales, no el orden.

**3.5 · `PluginContractTest` no vive donde el capítulo dice.** «vive en el árbol de trabajo,
junto al resto de `tools/test-*.php`». Está en
`public_html/wp-content/plugins/akibara-cart-checkout-blocks/tests/php/PluginContractTest.php`.
En `tools/` no hay ningún `test-*.php` de este plugin.

**3.6 · «El arranque comprueba tres cosas» — son cuatro.** `src/Plugin.php`: clase
`WooCommerce` y clase `Akibara\Business\Plugin` (`:30`), **`method_exists($business,
'checkout_data')` (`:35-37`)**, e `instanceof CheckoutDataProvider` (`:40`). La que falta es
justo la que salta con una versión vieja de Business, que es el escenario más probable de
«el plugin no arranca» tras un despliegue parcial.

**3.7 · Un literal citado sin la tilde que sí tiene el código.** El ⚠ de la pieza 14 dice que
la tabla «incluye `Region Metropolitana de Santiago`». En el código es
`'Región Metropolitana de Santiago'`, con tilde (`assets/js/pudo-blocks.js:64`). Va dentro de
un span de código, o sea que se lee como literal exacto, y quien lo busque con `grep` no lo
encuentra. (Lo mismo, al revés, con `Ibañez`: ahí el capítulo acierta — el código escribe
`Ibañez` donde lo correcto sería `Ibáñez`, `:46`.)

**3.8 · «La prueba de paridad cubre sólo `normalize()`» es cierto para la comuna y falso como
afirmación general.** También existe `tests/checkout-phone-parity.test.js`, que es la paridad
del otro gemelo. Basta acotar la frase: «la paridad **de comunas** sólo cubre `normalize()`;
`suggest()` no tiene gemela».

**3.9 · La fila «`$business->pudo_checkout()` se cae» describe un estado que hoy no existe.**
La tabla «Qué pasa si se apaga» la ofrece como palanca. En el código, Business construye el
servicio **siempre** y sin condición (`akibara-business/src/Plugin.php:884`:
`$this->pudo_checkout = new PudoCheckoutService();`), así que no hay option, ni filtro, ni
casilla que lo apague: la fila sólo se cumple si Business no arranca. **Debería decir**: «no
hay forma de apagar sólo PUDO; hoy esta compuerta es teórica», que además es una pregunta
legítima para el dueño.

---

## 4 · Lo que contradice a `verificacion.json` del área

El capítulo absorbió las 10 omisiones y los 6 errores del fichero, y eso se nota. Quedan
estos desajustes:

**4.1 · Hereda sin comprobar el error de coste de V-02.** `verificacion.json` dice que el
desencolado muerto obliga a un `has_block()` en cada petición del front; el capítulo lo repite
palabra por palabra. Es falso por el `return` temprano de `src/BlocksPage.php:46` (ver 3.2).
El capítulo corrigió a `verificacion.json` en otros sitios; aquí no lo hizo.

**4.2 · Se queda con la mitad refutada de S-21 y tira la mitad accionable.**
`verificacion.json` refuta «en el panel desaparecen las tarifas PUDO» **y recomienda
reescribir el hallazgo** como «no existe ruta para crear a mano un pedido de retiro con las
metas validadas». El capítulo publica la refutación y cierra con «La pregunta real para el
dueño no es "¿por qué desaparecen?" sino "¿hace falta poder crear a mano un pedido de
retiro?"» — y ahí muere: el capítulo **no tiene sección de preguntas al dueño**, así que esa
pregunta no está en ningún sitio donde alguien la vaya a responder. **Debería** existir un
apartado «Preguntas abiertas para el dueño» (esta, más la del `<h1>` del punto 2.9).

**4.3 · Deja caer el hecho detrás de la sospecha S-16, no sólo la sospecha.**
`verificacion.json` degrada S-16 a nota, con razón. Pero el hecho sigue siendo cierto y
verificable: `Assets::enqueue()` inlina `shippingHint` **también en el pago**
(`src/Assets.php:159-184`), donde `renderShippingHint()` nunca lo usa
(`assets/js/cart-checkout-blocks.js:263`). La tabla «Datos que deja» describe
`window.akbCartCheckoutBlocks` como «`namespace` + `shippingHint` ya resuelto» sin decir que
en `/checkout/` ese texto viaja y no se pinta. Quien lea el HTML del pago y encuentre ahí el
aviso de despacho va a pensar que está roto.

**4.4 · Tampoco menciona el matiz de S-11, que es de operación.** `verificacion.json` lo
degrada a «redacción ambigua del comentario», pero el hecho —`collection_notice_applies()`
devuelve `true` si la usermeta está corrupta (`src/Assets.php:274`)— es el único camino por el
que un cliente dado de baja vería el aviso. No hace falta un ⚠; basta una línea en la pieza 7.

**4.5 · «Medido» no distingue lo medido en esta ronda de lo copiado de un comentario del
código.** `verificacion.json` cierra diciendo «**Nada de esto está medido en el espejo**». El
capítulo, en cambio, usa «Medido» siete veces sin fecha ni fuente: «Medido: 16 regiones, 346
comunas», «medido en Docker sobre una respuesta `GET /wc/store/v1/cart` con 2 ítems»,
«Medido en iPhone 390×664», «Medido sobre el HTML servido a un móvil», «(medido: en
`/carrito/` la cola trae…)». Comprobé al menos uno: la medición del ejemplo del celular está
literalmente en un comentario del código («comprobado en el checkout local el 2026-08-23»,
`src/CheckoutFields.php:99`). **Debería** marcarse cuál es cuál, porque un novato que lea
«medido» asume que puede fiarse del número sin repetirlo.

**4.6 · El resto cuadra.** Las 12 sospechas nuevas (V-01 … V-12) están todas en el capítulo,
con el radio corregido en S-05 y S-07 tal como pedía el fichero, y las derivas de cita que
`verificacion.json` señala (`:447-449`, `:136-140`, `PudoLog :82-95`) están corregidas en el
texto. Lo comprobé contra el snapshot: las tres son ahora exactas.

---

## 5 · Diagramas

**5.1 · Los cinco compilan.** `mmdc -i <fichero>.mmd -o <fichero>.svg` termina en 0 para los
cinco (46 KB, 43 KB, 53 KB, 51 KB y 214 KB de SVG). Ninguno tiene error de sintaxis.

**5.2 · Los cinco están sin tildes ni eñes, contra la convención del propio repositorio — y
en este capítulo las tildes SON el tema.** De los 119 `.mmd` de `docs/arquitectura/diagramas/`,
**107 usan acentos**; los 5 de este capítulo tienen **cero**. En un capítulo cuyo asunto
central es `nunoa` → `Ñuñoa` y `Renaca` → `Reñaca`, quitar las tildes no es una cuestión de
estilo: borra el ejemplo.

**5.3 · Consecuencia directa en `caso-comuna.mmd`: la sugerencia es idéntica a lo que el
cliente escribió.** El diagrama pinta `No encontramos la comuna "Renaca". Quisiste decir
"Renaca"?`. Un lector nuevo concluye que el validador está roto. Lo mismo dos pasos antes:
el cliente teclea `nunoa` y el plugin «escribe `Nunoa`», o sea la canonización que el diagrama
existe para mostrar no se ve. **Debería** o recuperar las tildes (`"Renaca"` → `"Reñaca"`,
`"Nunoa"` → `"Ñuñoa"`), o cambiar el par por uno que sobreviva sin ellas
(`"Recolet"` → `"Recoleta"`).

**5.4 · `caso-comuna.mmd` atribuye al tema algo que hace WooCommerce.** La línea
`Tema->>Plugin: wp_enqueue_scripts del pago de bloques` tiene mal el actor **y** el hook: quien
dispara es WooCommerce Blocks, con
`woocommerce_blocks_enqueue_checkout_block_scripts_after` (`src/Assets.php:136-137`), y el
tema no interviene. El participante `Tema as Tema akibara-gp` no hace nada más en todo el
diagrama. **Debería** eliminarse ese participante y mandar el mensaje desde `Woo`.

**5.5 · `caso-pudo.mmd` nombra un método que el capítulo dice que se eliminó.** La línea
`Plugin->>Business: select_agency validado`: `select_agency` es uno de los **tres métodos que
la pieza 12 cuenta que se quitaron del contrato** por no ser invocados nunca
(`src/PudoServiceAdapter.php:38-44`; la lista viva son siete y no lo incluye, `:52-60`). En el
código sólo sobrevive como cadena de contexto del registro
(`src/PudoIntegration.php:163`). El diagrama y el texto se contradicen. **Debería decir**
`canonicalize_request` / `validate_checkout`, que son los que se llaman de verdad.

**5.6 · `caso-direccion-google.mmd` mete el borrado de `address_2` y `postcode` en la rama
equivocada.** La nota «Y con ella address_2 y postcode vacias: pisan el "Depto 42" ya escrito»
cuelga del `else El cruce es ambiguo o falta el catalogo`. En el código las seis claves se
inicializan vacías y se devuelven **siempre**
(`assets/js/google-places-address-provider.js:671-679`, `723-725`): `address_2` se queda vacía
en cuanto el lugar no trae `subpremise` (`:697-699`) y `postcode` en cuanto no trae
`postal_code` (`:715-717`), pase lo que pase con la comuna. El texto del capítulo lo tiene
bien; el diagrama lo estrecha y hace pensar que es un caso raro, cuando es el caso normal.
**Debería** ir como `Note over Puente,Woo` fuera del `alt`.

**5.7 · `estados-pudo-dialogo.mmd` afirma algo que no está en el capítulo ni encontré en el
código.** `MultiPaquete: Rechazo explicito, el foco se mueve al mensaje`. El «el foco se mueve
al mensaje» no aparece en el capítulo y no lo hallé en `assets/js/pudo-blocks.js`. O se cita,
o se quita: en un diagrama de accesibilidad esa frase es una promesa.

**5.8 · El mismo diagrama se enlaza dos veces y la segunda no encaja.** Aparece en la pieza 14
(donde sí corresponde) y otra vez al final del **caso 8 «Blue Express se cayó»**. La máquina de
estados no tiene ningún estado para «el proveedor está caído» ni para «las tarifas PUDO
desaparecen del listado», que es de lo que trata ese caso. **Debería** o añadirse un estado
`SinTarifas` alimentado por `filter_package_rates`, o quitarse el segundo enlace.

**5.9 · Las bifurcaciones del texto y las del diagrama no llevan las mismas etiquetas.** El
caso 1 numera «Bifurcación A / B / **C** (no hay catálogo)»; `caso-comuna.mmd` sólo rotula
`Bifurcacion A` y `Bifurcacion B`, y el caso sin catálogo aparece antes, en el bloque de
encolado, sin etiqueta. Como el texto dice «ver el diagrama», la C se busca y no está.

**5.10 · `piezas.mmd` se entiende solo y tiene casi todos los nodos que el texto nombra — con
tres ausencias.** Faltan: la pantalla de *Salud del sitio* (sólo existe como etiqueta de una
flecha del Dueño, no como nodo, pese a ser una superficie operable), el logo
`assets/images/google-maps-logo-dark-gray.svg` (punto 2.6) y el estado `akb_pudo_state_*`
(punto 2.3). Además el nodo `OPT` dice «akb_blocks_mp_dequeue_observed y **tres** de sólo
lectura» y la tabla «Datos que deja» del capítulo enumera **cinco** de sólo lectura
(`akibara_blocks_shipping_hint_text`, `akibara_blocks_collection_notice_text`, `akb_sf_enabled`,
`woocommerce_cart_page_id`, `woocommerce_checkout_page_id`). A favor, y conviene decirlo: el
diagrama **sí** dibuja las dos compuertas condicionales (`si interface_exists`, `si Business
expone pudo_checkout`) y **sí** incluye `google-login`, que es justo lo que el mapa de lectura
no veía.

---

## Cierre

Lo que arreglaría primero, por daño para quien llega nuevo: **3.1** (el ejemplo «Providen»
demuestra lo contrario de lo que dice y es el único que sostiene ese aviso), **5.3/5.2** (los
diagramas sin tildes vuelven ilegible el ejemplo central del capítulo), **2.1** (no hay forma
de saber cómo se prueba esto, y la ruta que da es falsa), **2.3** (la pieza más grande no dice
dónde se opera ni dónde vive su estado) y **2.2** (no se puede rotar la clave de Google).
