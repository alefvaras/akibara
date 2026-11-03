# Transversal · fronteras con WooCommerce, Blue Express, Brevo, Mercado Pago, Flow, Rank Math, LiteSpeed, Cloudflare, Clarity, Google

> Capítulo del área `cross-integraciones`, escrito sobre el snapshot congelado de lo que
> está publicado en producción: `akibara-business` 1.116.4, `akibara-search` 0.1.60,
> `akibara-cart-checkout-blocks` 2.2.17, `akibara-rewards` 0.3.13, el tema `akibara-gp`
> 2.1.118 y **dieciocho** mu-plugins. Cada afirmación técnica va con su fichero y su línea.
> Donde el mapa de lectura y el código no coincidían, manda el código: las correcciones
> están aplicadas y señaladas en su sitio.
>
> **Sobre el dieciocho:** el snapshot tenía **18**: los diecisiete que cuenta `CLAUDE.md` más
> `akibara-catalogo-abilities.php`, el barrido de disponibilidad. El dueño lo retiró el 2026-09-13
> (fue de una sola vez) y se borró de local y de producción junto con sus metas, así que este
> capítulo ya no lo cuenta y hoy vuelven a ser diecisiete.

---

## Para qué existe (en dos párrafos que entienda un novato)

Akibara.cl no es un programa; es una docena de programas de distintas empresas conviviendo
en el mismo servidor. **WordPress** sirve las páginas, **WooCommerce** convierte WordPress
en tienda (productos, carrito, pedidos, correos), **Blue Express** cotiza y despacha los
paquetes, **Mercado Pago** y **Flow** cobran con tarjeta, **Brevo** manda las campañas,
**Rank Math** decide lo que Google lee de cada página, **LiteSpeed** y **Cloudflare**
guardan copias de las páginas para que carguen rápido, **Microsoft Clarity** graba las
sesiones, **Google** pone el autocompletado de direcciones y el escaparate de Shopping, y
**Hostinger** es el servidor con su buzón de correo y su reloj. De todo eso, Akibara
escribe código propio en seis sitios y nada más: cuatro plugins, el tema hijo y los
mu-plugins.

Este capítulo describe **la costura**: el sitio exacto donde el código de la casa toca el
código ajeno. La regla, sin excepciones declaradas en `CLAUDE.md`, es que el código de un
tercero no se edita: se le habla por los puntos de enganche que él mismo publica (*hooks*).
Cuando eso no alcanza —porque el tercero no publicó el enganche que hace falta— el código
de la casa llama a sus clases directamente, y ese es precisamente el punto frágil que este
capítulo señala uno por uno. La frontera importa porque casi todos los incidentes caros de
esta tienda han pasado aquí y no dentro del código propio: una actualización de Blue
Express que llegó con ficheros ausentes y tumbó el checkout casi un día, tres días sin
ningún correo porque el antivirus del hosting borró un fichero, un carrito servido desde la
caché con cinco minutos de antigüedad, un precio que cambió en la base de datos y siguió
publicándose viejo en las fichas.

---

## Cómo leer este capítulo (cuatro avisos que ahorran media hora)

**1. Si una palabra técnica no se entiende, está definida al final.** *Hook*, *filtro*,
*prioridad*, *option*, *termmeta*, *fail-open*, *Store API*, *purga*: todas están en el
[glosario del capítulo](#glosario-del-capítulo), con una frase cada una. Este capítulo las
usa sin volver a explicarlas.

**2. Todas las pantallas «Akibara → X» son la misma página.** Viven en
`/wp-admin/admin.php?page=akibara-panel`, y cada «X» es una pestaña de esa página
(`&tab=<id>`): «Textos» es `tab=copys`, «Carrito» es `tab=native_cart`, «Descuentos» es
`tab=taxonomy_discount`, «Envío gratis» es `tab=free_shipping_progress`, «Autocompletar
dirección» es `tab=address`, y el llamado «Centro de Mando» es la pestaña «Dashboard»
(`tab=dashboard`). Dos excepciones, que son **páginas propias** colgadas del mismo menú y no
pestañas: «Claves y accesos» (`page=akibara-provider-access`) y «Puntos y Reseñas», que es un
enlace saliente a `page=akb-rewards-settings`, la pantalla de `akibara-rewards`. Ojo con el
identificador: el slug corto `page=akibara` **no existe** y devuelve 403, que parece un
problema de permisos y no lo es.

**3. Todo lo que diga `correios` en este capítulo es Blue Express.** El plugin de Blue
Express (`bluex-for-woocommerce`) se construyó sobre uno brasileño de Correios y conserva sus
nombres internos: la clase `WC_Correios`, la función `wc_correios_updateTrackingCode()`, la
option `woocommerce_correios_settings`, la ordermeta `_correios_tracking_code`. No es el
correo de Brasil ni un error de tipeo: es el nombre que el proveedor no cambió.

**4. Qué dibuja cada diagrama.** Hay tres formas y se leen distinto:

- *Mapa de piezas* (el de más abajo): cada caja es un programa y cada flecha es un punto de
  contacto. No hay tiempo: se lee de un vistazo, como un plano.
- *Línea de tiempo* (el de «Lo programado»): cada bloque es un momento del día y lo que
  ocurre en él.
- *Diagrama de secuencia* (los seis de los casos de uso): cada columna es un programa y el
  tiempo **baja**. Una flecha de izquierda a derecha es «este programa le pide algo a ese»;
  las cajas etiquetadas `alt` son caminos que se excluyen entre sí, las `opt` son pasos que
  pueden no ocurrir, y las `loop` son pasos que se repiten.

---

## Cómo encaja con el resto (con quién habla y qué pasa si se apaga)

![Piezas de la frontera con terceros](diagramas/18-fronteras-con-terceros-piezas.mmd)

*Cómo se lee:* cada caja es un programa —amarillas las de la casa, azules las ajenas, verdes
las personas, moradas los datos que quedan escritos— y cada flecha es un punto de contacto,
con el nombre del enganche encima. No hay tiempo en este dibujo: es un plano, no una
secuencia.

**Con quién habla.** Los cuatro plugins propios cuelgan de WooCommerce y se apagan enteros
si no está: `akibara-business/akibara-business.php:87`, `akibara-search/akibara-search.php:69`,
`akibara-rewards/akibara-rewards.php:97` y —esto el mapa lo contaba mal, decía «los tres
plugins propios»— también `akibara-cart-checkout-blocks/src/Plugin.php:30`, que arranca sólo
si pasa **tres** comprobaciones seguidas (`Plugin.php:30-42`): que exista la clase
`WooCommerce`, que exista `BusinessPlugin` y tenga el método `checkout_data()`, y que lo que
ese método devuelva sea un objeto de tipo `CheckoutDataProvider`. Ese «contrato» es
simplemente una lista de seis servicios que `akibara-business` promete prestarle al checkout
—datos del carrito, disponibilidad de cada producto, validar un RUT, formatearlo, validar un
celular y la configuración del autocompletado de dirección
(`akibara-business/src/Contracts/CheckoutDataProvider.php:21-129`)—. Si alguno de los tres
controles falla, el plugin del carrito **no registra absolutamente nada** y el cliente ve el
checkout de fábrica de WooCommerce: sin RUT, sin comuna canonicalizada y sin punto de retiro.
El
tema `akibara-gp` cuelga de GeneratePress. Los mu-plugins no cuelgan de nadie: WordPress los
carga siempre, antes que cualquier plugin, y no se pueden desactivar desde el panel; por eso
viven ahí las piezas que tienen que funcionar aunque todo lo demás esté caído (el transporte
de correo, su centinela, las barandas de LiteSpeed).

**Qué pasa si se apaga cada tercero:**

| Tercero | Si se apaga o desaparece |
| --- | --- |
| WooCommerce | Los cuatro plugins propios no arrancan nada. No hay tienda. |
| Blue Express (`bluex-for-woocommerce`) | Desaparecen las tarifas y el retiro en punto. `ComunaDirectory` devuelve catálogo vacío y el checkout cae a un campo de comuna de texto libre —falla **abierto**, a propósito (`akibara-cart-checkout-blocks/src/ComunaDirectory.php:377-393`). El seguimiento no se guarda y nada revienta (`mu-plugins/akibara-bluex-rest-tracking.php`). |
| Mercado Pago / Flow | No hay forma de cobrar con tarjeta; queda la transferencia bancaria del núcleo. Ningún código propio falla: no hay una sola línea de Akibara dentro de una pasarela. |
| Brevo | No hay campañas ni carrito abandonado. `MarketingConsent` deja de anotar la prueba (su hook no dispara) y el mu-plugin de portadas JPG no engancha nada (`mu-plugins/akibara-brevo-portada-jpg-en-el-carrito.php`, guarda en `plugins_loaded:25`). |
| Rank Math | Desaparecen título, descripción, canonical, robots, sitemaps y el bloque de datos estructurados. Akibara **no** emite un segundo bloque, así que la página se queda sin ninguno. Las dos abilities de MCP sobre redirecciones no se registran (`mu-plugins/akibara-rankmath-redirections-abilities.php:311-315`). |
| LiteSpeed | Las páginas se sirven sin caché de servidor: más lento y correcto. Las barandas no hacen daño: son filtros que nadie llama. |
| Cloudflare | Se pierde la caché de borde y la cabecera `CF-Connecting-IP`; el limitador por IP cae a `REMOTE_ADDR`, que sin Cloudflare es la IP real. |
| Clarity | Se deja de grabar. El puente de eventos comprueba `clarity_project_id` y no emite nada sin él (`akibara-business/src/Modules/FrontendCompatibility/FrontendCompatibility.php`). |
| Google (Maps, GA4, Ads, Merchant) | Se pierde el autocompletado de dirección y el feed de Shopping. «Continuar con Google» desaparece del muro de acceso. |
| Hostinger SMTP | **No sale ni un correo** y el guard lo grita en el escritorio (`mu-plugins/akibara-correo-smtp.php`). |
| GeneratePress | El tema hijo se queda sin padre: el sitio no se pinta. |
| Joinchat | Desaparece el botón de WhatsApp; `PickupCoordination` cae al teléfono de respaldo `+56944242844` (`akibara-business/src/Modules/PickupCoordination/PickupCoordination.php:162-168`). |

**Hacia dónde salen los cables de este capítulo.** El carrito y el checkout (capítulo de
`akibara-cart-checkout-blocks`), la preventa y los pedidos (`OrderPreventa`), los puntos y
reseñas (`akibara-rewards`), el buscador (`akibara-search`) y el panel del dueño
(`AdminPanel`). Todos ellos atraviesan alguna de estas costuras.

---

## Piezas

Veinticinco piezas. Cada una es una frontera: qué hace, en qué ficheros vive, en qué momento
actúa, qué lee y qué escribe, dónde la opera el dueño y qué ve el cliente.

---

### 1. Frontera WooCommerce (núcleo): impuestos, remitente y arranque

**Qué hace en una frase.** Declara que los plugins propios son compatibles con las tablas
nuevas de pedidos y con el carrito de bloques, renombra a «IVA» toda etiqueta de impuesto y
firma todo el correo del sitio con un único remitente autenticado.

**Ficheros.** `akibara-business/src/Plugin.php`,
`akibara-business/src/Modules/CheckoutTaxLabels/CheckoutTaxLabels.php`,
`akibara-business/src/Modules/MailFrom/MailFrom.php`,
`akibara-business/src/Modules/EmailBrand/EmailBrand.php`,
`akibara-business/src/Services/MailerBoot.php`, `themes/akibara-gp/inc/woocommerce.php`.

**En qué momento actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `before_woocommerce_init` | 10 | Declara compatibilidad con `custom_order_tables` (HPOS) y `cart_checkout_blocks` en los cuatro plugins. |
| `woocommerce_countries_tax_or_vat` | 10 | Devuelve siempre «IVA». |
| `woocommerce_cart_tax_totals` / `woocommerce_order_get_tax_totals` | 10 | Reescribe **sólo** la etiqueta de cada total de impuesto; los montos no se tocan. |
| `wp_mail_from` / `wp_mail_from_name` | 15 | Impone `contacto@akibara.cl` y «Akibara», descartando el remitente que traiga quien llame. |
| `woocommerce_email_classes` | 10 | Registra los correos propios (avisos de stock, seguimiento de serie, preventa) como correos de WooCommerce, para que hereden su plantilla y sus ajustes. |
| `woocommerce_bacs_account_fields` | 10 | Renombra los huecos europeos de la transferencia: `sort_code`→«Tipo de cuenta», `iban`→«RUT», `bic`→«Email» (`themes/akibara-gp/inc/woocommerce.php:470-482`). |

**Qué lee y escribe.** Lee la option `woocommerce_bacs_accounts` (pantalla del núcleo).
Escribe metas en el pedido, siempre por la API `WC_Order`, nunca tocando tablas.

**Dónde lo opera el dueño.** WooCommerce → Ajustes → Correos y WooCommerce → Ajustes → Pagos.

**Qué ve el cliente.** La palabra «IVA» en carrito, pedido y correo; los datos de
transferencia en español chileno; el remitente de todos los correos.

---

### 2. El correo de WooCommerce: sus hooks, catorce plantillas sustituidas y nueve propias

**Qué hace en una frase.** Cambia lo que dice y lo que se ve en los correos del núcleo, y
en un caso decide si un correo del núcleo **sale o no sale**.

> Corrección aplicada: el mapa nombraba tres hooks de correo y se perdía justamente el que
> suprime un correo. Y no mencionaba ni una vez que hay plantillas del núcleo sustituidas,
> que es la frontera más frágil que existe con WooCommerce: una plantilla copiada se queda
> vieja en silencio el día que Woo cambia la suya.
>
> Corrección de una versión anterior de este capítulo: decía «veintitrés plantillas
> sustituidas». Son veintitrés ficheros, pero nueve de ellos no sustituyen nada —son correos
> propios de Akibara y una pantalla propia de Mi cuenta—. Sustituciones reales: catorce. El
> riesgo del que habla la pieza corre sólo para esas catorce.

**Ficheros.**
`akibara-business/src/Modules/OrderPreventa/ReadyEmailDispatcher.php`,
`akibara-business/src/Modules/OrderPreventa/CustomerVisibility.php`,
`akibara-business/src/Modules/RutValidation/RutValidation.php`,
`akibara-business/src/Modules/PickupCoordination/PickupCoordination.php`,
`akibara-business/src/Modules/EmailBrand/EmailBrand.php`,
`themes/akibara-gp/inc/woocommerce.php`, más las plantillas:
`themes/akibara-gp/woocommerce/**` (nueve ficheros) y
`akibara-business/templates/**` (catorce).

**Plantillas: hay que distinguir dos cosas que no corren el mismo riesgo.** Son veintitrés
ficheros, pero sólo catorce **sustituyen** una plantilla del núcleo:

| Qué son | Cuántas | Cuáles | El riesgo |
| --- | --- | --- | --- |
| Sustituciones de plantillas de WooCommerce servidas por el tema | 9 | `themes/akibara-gp/woocommerce/`: `cart/cart-empty.php`, `loop/no-products-found.php`, `loop/orderby.php`, `loop/pagination.php`, `emails/email-header.php`, `email-footer.php`, `customer-new-account.php`, `customer-refunded-order.php`, `customer-reset-password.php` | **Alto.** El día que WooCommerce cambie la suya, la copia se queda vieja en silencio: nada avisa |
| Sustituciones servidas por el plugin, vía el filtro `woocommerce_locate_template` | 5 | `templates/myaccount/`: `dashboard.php`, `orders.php`, `my-address.php`, `form-edit-account.php` (`MyAccountUx.php:32-37`) y `form-login.php` (`GoogleLoginButton.php:29`) | **Alto**, el mismo. Con un matiz propio: sólo sustituyen si WooCommerce iba a servir su plantilla por defecto; si el tema ya la sustituyó, gana el tema (`MyAccountUx.php:208-212`) |
| Plantillas **propias**, que no sustituyen nada | 9 | Los cuatro correos de la casa —aviso de stock, seguimiento de serie, preventa lista, recordatorio de preventa— con sus cuatro versiones en texto plano, más `templates/myaccount/collection.php`, que pinta la pantalla «Colección» que WooCommerce no tiene | **Ninguno de ese tipo.** No hay original ajeno del que quedarse atrás |

**En qué momento actúa.**

| Hook | Prioridad | Fichero:línea | Qué hace |
| --- | --- | --- | --- |
| `woocommerce_email_enabled_customer_completed_order` | 10 | `ReadyEmailDispatcher.php:82` | **Suprime** el correo nativo «tu pedido está completo» sólo en pedidos de preventa: el aviso propio «ya llegó» ya lo cubre con el copy correcto. |
| `woocommerce_email_after_order_table` | 10 | `CustomerVisibility.php:61` | Pinta el cartel de preventa bajo la tabla del pedido. |
| `woocommerce_email_after_order_table` | 20 | `PickupCoordination.php:57` | Pinta el bloque de cómo se coordina el retiro. |
| `woocommerce_email_order_meta_fields` | 10 | `RutValidation.php:38` | Añade el RUT a los datos del pedido en el correo. |
| `woocommerce_email_heading_customer_refunded_order` y `..._subject_...` | 10 | `themes/akibara-gp/inc/woocommerce.php:658-659` | Cambia la voz pasiva del núcleo («Pedido reembolsado») por la activa de la casa: «Te reembolsamos tu pedido #X». |
| `woocommerce_email_footer_text` | 5 | `themes/akibara-gp/inc/woocommerce.php:685` | Convierte cada `<br>` del pie en un salto de línea real, porque WooCommerce 11 quita todas las etiquetas del correo en texto plano y dejaba «AkibaraBartolo Soto 3700» pegado. |
| `woocommerce_email_styles` | 20 | `EmailBrand.php` | Inyecta el CSS del botón «Ver mi pedido» antes de que el motor de correo lo incruste línea a línea. |
| `woocommerce_email_order_details` | 15 | `EmailBrand.php` | Pinta el botón «Ver mi pedido» tras la tabla, sólo en correos al cliente. |
| `woocommerce_locate_template` | 10 | `MyAccountUx.php:55` y `GoogleLoginButton.php:29` | Sirve las plantillas del plugin en lugar de las del núcleo para Mi cuenta y el formulario de acceso. |

**Qué lee y escribe.** Lee metas de pedido (RUT, marcas de preventa) y textos editables del
panel. No escribe nada.

**Dónde lo opera el dueño.** Los textos editables viven en Akibara → Textos; el resto es
código.

**Qué ve el cliente.** Todos los correos de pedido: el botón, el RUT, el cartel de preventa,
las instrucciones de retiro, el asunto del reembolso y el pie.

---

### 3. Frontera WooCommerce Blocks / Store API (el plugin de carrito y checkout)

**Qué hace en una frase.** Añade al carrito y checkout modernos de WooCommerce los campos
chilenos (RUT, comuna, celular) y la información propia de cada línea.

**Ficheros.** `akibara-cart-checkout-blocks/src/Plugin.php`, `StoreApiIntegration.php`,
`CheckoutFields.php`, `CheckoutBlockIntegration.php`, `Assets.php`, `BlocksPage.php`,
`mu-plugins/akibara-woo-blocks-i18n.php`.

**En qué momento actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `plugins_loaded` | 30 | `Plugin::boot`: si falta WooCommerce o Business, no registra nada (`Plugin.php:30`). |
| `woocommerce_blocks_loaded` | 10 | Registra la extensión de datos del carrito con `woocommerce_store_api_register_endpoint_data`, namespace `akibara_cart_checkout_blocks` (`StoreApiIntegration.php:111`). |
| `woocommerce_get_item_data` | 20 | Añade a cada línea «Estado» y «Llegada estimada». |
| `woocommerce_get_country_locale` | 10 | `phone`→«Celular», `city`→«Comuna», y adelanta la Región para que preceda a la Comuna. |
| `woocommerce_store_api_cart_update_customer_from_request` | 10 | Canonicaliza la comuna («nunoa» → «Ñuñoa») **antes** de recalcular el envío. |
| `woocommerce_store_api_checkout_update_order_from_request` | 10 | La misma canonicalización, ya sobre el pedido. |
| `woocommerce_checkout_validate_order_before_payment` | 10 | Rechaza el pago si el celular o la comuna no validan. |
| `woocommerce_set_additional_field_value` | 10 | Copia el RUT del campo nuevo a la meta antigua. |
| `load_script_translations` | 10 | Reescribe **una sola** entrada del catálogo de traducción de WooCommerce: `'Please enter a valid %s'`, que el catálogo `es_CL` traduce «Por favor, introduce un %s válido», pasa a «`El campo %s no es válido.`» (`mu-plugins/akibara-woo-blocks-i18n.php:26-28`). El `%s` es la etiqueta del campo, así que cualquier artículo flexionado falla para la mitad de ellos: sin este parche el checkout decía «un dirección de correo electrónico válido», «un apellidos válido», «un comuna válido». |

**Qué lee y escribe.** Escribe el campo adicional `akibara/rut`; lee la usermeta
`_akb_sf_optout_all` para no pintar el aviso de colección a quien se dio de baja.

**Dónde lo opera el dueño.** Sus textos se editan en el panel de `akibara-business`
(Akibara → Carrito).

**Qué ve el cliente.** Todo el carrito y el checkout.

---

### 4. Store API (2): `akibara-rewards` también se cuelga de Blocks

**Qué hace en una frase.** Publica en el carrito del Store API el saldo de puntos y las
reglas de canje, y recibe por el mismo canal la intención de canjear.

> Corrección aplicada: el mapa afirmaba que el plugin de carrito y checkout «es el único
> sitio donde Akibara se cuelga de Blocks». Hay un segundo, y además es el que mueve dinero.

**Ficheros.** `akibara-rewards/src/Blocks/CheckoutIntegration.php`.

**En qué momento actúa.** `CheckoutIntegration::register()` (`:48-58`) engancha
`woocommerce_blocks_loaded` —o registra directo si ese hook ya pasó, porque dispara en
`plugins_loaded` y puede ganarle al arranque—, más
`woocommerce_blocks_enqueue_checkout_block_scripts_after` y
`..._cart_block_scripts_after`. En `register_store_api()` (`:64-86`) llama a
`woocommerce_store_api_register_endpoint_data` con namespace `akibara-rewards` sobre el
esquema del carrito, y a `woocommerce_store_api_register_update_callback` con el mismo
namespace.

**Qué lee y escribe.** Lee **nueve** options de configuración de puntos, precargadas en una
sola consulta con `wp_prime_option_caches` porque este método corre en **cada** respuesta del
carrito (`CheckoutIntegration.php:96-112`): `akb_rewards_redeem_min_points`,
`_redeem_max_pct`, `_redeem_value`, `_earn_enabled`, `_earn_points`, `_earn_amount`,
`_welcome_enabled`, `_welcome_bonus` y `_welcome_teaser_enabled`. Escribe la intención de
canje en la sesión.

> Corrección de una versión anterior: decía «ocho options», que es lo que dice el comentario
> del propio código («precargar las 8 options»). El array tiene nueve; la novena,
> `akb_rewards_welcome_teaser_enabled`, se añadió sin actualizar el comentario. Contar el
> array y no el comentario es la diferencia.

**Dónde lo opera el dueño.** Akibara → Puntos.

**Qué ve el cliente.** El bloque de canje de puntos dentro del carrito y del checkout.

---

### 5. Quién cambia el IMPORTE antes de que cobre la pasarela

**Qué hace en una frase.** Tres motores propios modifican lo que el cliente va a pagar
antes de que la pasarela vea un solo peso.

> Corrección aplicada: el mapa contaba «Mercado Pago procesa el cobro, Akibara NO toca ese
> paso», lo cual es cierto de la **pasarela** y falso del **importe**. Para el flujo del
> dinero de punta a punta, este es el trozo que faltaba.

**Ficheros.** `akibara-rewards/src/Points/Redemption.php`,
`akibara-business/src/Modules/SeriesBundles/BundleCouponEngine.php`,
`akibara-business/src/Modules/TaxonomyDiscount/CouponInteraction.php`.

**En qué momento actúa.**

| Hook | Prioridad | Fichero:línea | Qué hace |
| --- | --- | --- | --- |
| `woocommerce_cart_calculate_fees` | 20 | `Redemption.php:76` | Añade un cargo **negativo** con `$cart->add_fee()` por el canje de puntos (`:251`). Prioridad 20: después de cualquier cargo de terceros y antes de que WooCommerce lea la lista. |
| `woocommerce_coupon_discount_types` / `woocommerce_product_coupon_types` | 10 | `BundleCouponEngine.php:91-92` | Registra en WooCommerce un **tipo de cupón propio**, que el núcleo no trae. |
| `woocommerce_get_shop_coupon_data` | 10 | `BundleCouponEngine.php:95` | Fabrica al vuelo el cupón virtual del pack cuando WooCommerce lo pide. |
| `woocommerce_coupon_get_items_to_apply` / `..._get_discount_amount` | 10 | `BundleCouponEngine.php:98-99` | Aísla las líneas y sustituye el monto por la contribución exacta de cada ISBN. |
| `woocommerce_checkout_create_order_coupon_item` | 10 | `BundleCouponEngine.php:101` | Persiste la asignación exacta en el pedido para que el recálculo administrativo no la invente. |
| `woocommerce_before_calculate_totals` | 1000 | `BundleCouponEngine.php:109` | Aplica o retira los cupones virtuales según lo que haya hoy en el carrito. |
| `woocommerce_coupon_is_valid` | 10 | `CouponInteraction.php:31` | Invalida un cupón cuando ya hay descuento por taxonomía y la campaña no permite acumular; los cupones virtuales internos quedan exentos por prefijo. |

**Qué lee y escribe.** Lee el libro de puntos y la configuración de la campaña de descuento;
escribe líneas de cargo y de cupón en el pedido.

**Dónde lo opera el dueño.** Akibara → Puntos (valor del punto, mínimo, tope) y
Akibara → Descuentos (acumulación con cupones).

**Qué ve el cliente.** La línea **«Puntos Akibara»** con importe negativo —ese es el texto
literal, `Redemption.php:50` y `:251`; la cadena «Canje de Puntos» no existe en el árbol— y
el descuento del pack en el resumen del carrito.

---

### 6. Pasarelas de pago: Mercado Pago, Flow y transferencia

**Qué hace en una frase.** No escribe ninguna pasarela: sólo evita que el acelerador les
rompa los scripts, retira los que sobran y hace legibles sus errores.

**Ficheros.** `akibara-cart-checkout-blocks/src/RuntimeCompatibility.php`,
`akibara-business/src/Modules/FrontendCompatibility/FrontendCompatibility.php`,
`akibara-business/src/Modules/ScriptDiagnostics/CrossOriginScripts.php`,
`themes/akibara-gp/inc/woocommerce.php`.

**En qué momento actúa.**

| Hook | Prioridad | Dónde corre | Qué hace |
| --- | --- | --- | --- |
| `wp_enqueue_scripts` | 999 | **Sólo en `/carrito/`** | `dequeue_classic_mercado_pago_assets` (`:119`, `:146-181`): recorre una lista de **quince nombres de script** (lo que WordPress llama *handle*: el identificador con el que un fichero JavaScript entra en la cola de la página) y retira los que de verdad estén encolados. La lista es literal y no por prefijo `wc_mercadopago_`, para no llevarse los dos que Blocks **sí** necesita —`wc_mercadopago_woocommerce_scripts` y `wc_mercadopago_health_monitor`— (`:40-71`). |
| `wp_enqueue_scripts` | 999 | `/carrito/` **y** `/checkout/` | `dequeue_classic_city_select` (`:120`, `:227-249`): retira `wc-city-select` y `bluex-blocks-city-select`, que en `/checkout/` traían el catálogo de comunas **duplicado**. El endpoint clásico de Mi cuenta queda intacto. |
| `wp_enqueue_scripts` (1000) y `wp_print_footer_scripts` (0) | — | `/carrito/` y `/checkout/` | `repair_flow_blocks_dependencies`: repone `wc-settings`, `wp-element` y tres más, que el build de Flow no declara porque no empaqueta su `blocks.asset.php`; sin eso su método de pago no aparece. |
| `litespeed_optm_uri_exc` | 10 | todo el sitio | Excluye la URI `checkout` de la reordenación de JavaScript. |
| `litespeed_optimize_js_excludes` y dos hermanas | 10 | todo el sitio | Saca de la combinación y del retardo nueve fragmentos de Mercado Pago y su SDK. |
| `script_loader_tag` | 20 | todo el sitio | Marca `crossorigin="anonymous"` los scripts de seis hosts verificados uno a uno; `googleads.g.doubleclick.net` queda fuera a propósito porque no pasa CORS y marcarlo lo bloquearía. |
| `site_status_tests` | 10 | wp-admin | Publica la prueba `akibara_blocks_third_party_patches`. |

> **Baja de un hook (corregido 2026-09-20, ronda 10).** Esta tabla listaba un cuarto desencolado,
> `dequeue_classic_cart_empty_reload` (`akibara-cart-empty-reload`). Se retiraron el método y su hook
> en checkout 2.2.21 (2026-09-18, hallazgo S-d334fd55) porque era pegamento sin tercero al que
> pegarse: el tema ya había dado de baja ese script. Los parches de `RuntimeCompatibility` son
> **tres**. La historia completa está en el
> [capítulo 12](12-checkout-blocks-campos-chilenos-y-pudo.md).

> **Corrección aplicada, y es la que más cambia la película.** Una versión anterior de este
> capítulo contaba el desencolado de Mercado Pago dentro del checkout. No es ahí: el método
> empieza con `if ( ! $this->blocks_page->is_cart() ) { return; }`
> (`RuntimeCompatibility.php:158-160`), y ese `is_cart()` exige el `is_cart()` de WooCommerce
> **más** que la página del carrito contenga de verdad el bloque `woocommerce/cart`
> (`BlocksPage.php:44-52`). En `/checkout/` no retira nada; el propio docblock lo dice:
> «Removes Mercado Pago's classic observers from the Blocks **cart**» (`:154`). El hermano
> `dequeue_classic_city_select` sí cubre los dos (`:240`), y por eso el contraste pasa
> desapercibido leyendo por encima.
>
> **Y el quince no es un número vigente, es el tamaño de la lista.** El bucle cuenta y retira
> sólo los que estén realmente en la cola, y el comentario del código se niega expresamente a
> congelar la cifra: «Cuántos se retiran HOY no se congela aquí: una medición con fecha
> caduca y termina contradiciendo al contador» (`:172-174`). El número vigente lo publica la
> prueba `akibara_blocks_third_party_patches` en Herramientas → Salud del sitio.

> Corrección aplicada: el docblock de esa prueba declara **tres** parches —«Mercado Pago,
> Flow y Blue Express» (`RuntimeCompatibility.php:279-286`)— pero su cuerpo
> (`:311-390`) sólo evalúa los dos primeros. El desencolado de `wc-city-select` no tiene
> señal ninguna, que es exactamente lo que la prueba venía a evitar.

**Qué lee y escribe.** Escribe la option `akb_blocks_mp_dequeue_observed` sólo cuando cambia
el número de scripts retirados. Lee `akb_crossorigin_scripts_enabled`, que **no tiene
pantalla** en ningún sitio (comprobado: la cadena aparece dos veces en todo el árbol, en la
constante del módulo y en la lista de options precargadas de `Plugin.php:797`).

**Dónde lo opera el dueño.** Herramientas → Salud del sitio; Centro de Mando de Akibara.

**Qué ve el cliente.** Los métodos de pago del checkout.

---

### 7. Blue Express — selector de Punto de retiro (PUDO)

**Qué hace en una frase.** Del navegador sólo acepta el identificador de la agencia; todo lo
demás se lo vuelve a preguntar a la API de Blue Express y compara.

**Ficheros.** `akibara-business/src/Integrations/Bluex/Pudo/` (siete clases),
`akibara-cart-checkout-blocks/src/PudoIntegration.php`, `PudoServiceAdapter.php`,
`PudoLog.php`.

**En qué momento actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `woocommerce_store_api_checkout_update_order_from_request` | 1 | **Antes** que el plugin de Blue: borra su rama de extensiones y la reescribe con lo que el servidor validó (`PudoIntegration.php:285`). |
| `woocommerce_store_api_cart_select_shipping_rate` | 100 | Invalida la selección si el cliente cambia de método. |
| `woocommerce_cart_shipping_packages` | `PHP_INT_MAX` | Mete `_akb_pudo_generation` en el paquete para que WooCommerce no reutilice tarifas viejas. |
| `woocommerce_package_rates` | 999 | Retira la tarifa `bluex-pudo` si no hay selección válida o no coincide con la cotización guardada. |
| `wp_enqueue_scripts` | 999 | Retira el selector visual del proveedor. |
| `woocommerce_blocks_loaded` | 1000 | Registra el callback de actualización del Store API bajo el **mismo** namespace `bluex_pudo`, sustituyendo el permisivo del proveedor. |

**Qué lee y escribe.** Lee de `BlueX_API_Client::get_geolocation()` y `::get_pricing()`.
Escribe en el pedido cuatro metas con los nombres del proveedor, en
`persist_canonical_order_selection()` (`PudoIntegration.php:741-748`): `agencyId` e
`isPudoSelected` siempre —y el valor de esta última es la cadena `'pudoShipping'`, no un
sí/no—, y `agencyName` y `agencyAddress` sólo si no vienen vacías. Guarda la selección con una
huella `sha256` del carrito, y deja
rastro en `wc-logs`, fuente `akibara-pudo`, con cuota de una línea por minuto y contexto
(transient `akb_pudo_log_<md5>`).

**Dónde lo opera el dueño.** WooCommerce → Estado → Registros, fuente `akibara-pudo`.

**Qué ve el cliente.** El selector de agencias y sus mensajes de error, escritos para una
persona.

---

### 8. Blue Express — cotización a domicilio, envío gratis y seguimiento

**Qué hace en una frase.** Calla la cotización imposible, guarda el número de seguimiento
que Blue escribe por la API moderna y pinta la barra de envío gratis con el umbral real.

**Ficheros.** `mu-plugins/akibara-bluex-cotiza-solo-con-comuna.php`,
`mu-plugins/akibara-bluex-rest-tracking.php`, `mu-plugins/akibara-bluex-log-retention.php`,
`akibara-business/src/Services/FreeShippingThreshold.php`,
`akibara-business/src/Modules/FreeShippingProgress/FreeShippingProgress.php`,
`akibara-business/src/Integrations/Bluex/OrderPreventaAdapter.php`.

**En qué momento actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `woocommerce_shipping_bluex-ex_is_available` (y `-py`, `-md`) | 10 | Devuelve `false` mientras el destino no traiga región **y** comuna. La decisión está en `akibara-bluex-cotiza-solo-con-comuna.php:52-63`; el enganche, en `:78`. `bluex-pudo` queda fuera de la regla porque no cotiza contra la API: la lista de métodos afectados son sólo tres (`:42`). |
| `rest_api_init` | 101 | Sustituye el `update_callback` del campo REST `correios_tracking_code`, escrito para la API legada. |
| `woocommerce_rest_insert_shop_order_object` | 20 | Vuelve a leer el parámetro y lo guarda con `wc_correios_updateTrackingCode()`. |
| `bluex_clean_logs` | 10 | Se cuelga del propio hook de limpieza del proveedor. |
| `action_scheduler_init` | 20 | Programa la limpieza diaria de `wp_bluex_logs`, en el grupo **`akibara-bluex`** (`akibara-bluex-log-retention.php:14`). Ese nombre es el que hay que escribir en el filtro «Grupo» de WooCommerce → Estado → Acciones programadas para encontrarla. |
| `pre_http_request` | 10 | En un sitio local devuelve `WP_Error` para los dos POST de Blue que crean despachos reales. |

> Corrección aplicada: el mapa describía el callback de
> `woocommerce_rest_insert_shop_order_object` como «una red de seguridad: si el controlador
> REST no procesó campos adicionales…». No hay tal condición: corre **siempre** que Blue
> escriba un pedido por `wc/v3` (`akibara-bluex-rest-tracking.php:75-84`). Que el código no
> se duplique depende enteramente de que la función del tercero deduplique, y ese contrato
> no está escrito en ninguna parte. El docblock de la función, además, declara tres
> parámetros y la función tiene dos (`:18-40`).

**Qué lee y escribe.** Borra filas de la tabla `wp_bluex_logs` con más de 2 días, por lotes
de 1.000. Lee la option del proveedor `woocommerce_correios_settings`, clave `noBlueStatus`
(`OrderPreventaAdapter.php:172`). Lee y escribe la ordermeta `_correios_tracking_code`.

**Dónde lo opera el dueño.** Akibara → Envío gratis (`page=akibara-panel&tab=free_shipping_progress`).
Las once options que pinta esa pestaña, por si mañana la barra se comporta raro y hay que
leerlas a mano (`FreeShippingProgress.php:39-49`):

| Option | Qué gobierna |
| --- | --- |
| `akibara_fsp_enabled` | Interruptor general de la barra |
| `akibara_fsp_threshold` | Piso visual del operador. Nunca rebaja el mínimo real: se aplica `max()` |
| `akibara_fsp_carrier_threshold` | Umbral real que concede Blue Express dentro de su tarifa. `0` = apagado |
| `akibara_fsp_show_cart` | Pintarla en el carrito |
| `akibara_fsp_show_mini_cart` | Pintarla en el mini-carrito |
| `akibara_fsp_show_checkout` | Pintarla en el checkout |
| `akibara_fsp_show_qualified` | Seguir pintándola cuando el cliente ya alcanzó el envío gratis |
| `akibara_fsp_disable_local_pickup` | No pintarla cuando el envío elegido es retiro en tienda |
| `akibara_fsp_title` | Título de la barra |
| `akibara_fsp_message` | Texto de «te faltan $X» |
| `akibara_fsp_qualified` | Texto de «ya tienes envío gratis» |

**Qué ve el cliente.** Las tarifas del checkout, la barra «Agrega $X más para envío gratis a
domicilio» y el número de seguimiento en Mi cuenta.

---

### 9. Blue Express — el catálogo de comunas es del tercero

**Qué hace en una frase.** La forma canónica de cada comuna («Ñuñoa», no «nunoa») no la
inventa Akibara: la **lee del plugin de Blue Express**, y si su función no está cargada
carga un fichero interno suyo por ruta fija.

> Omisión reparada: el mapa presentaba la canonicalización como lógica propia. La verdad es
> del tercero, y se carga por ruta de fichero, que es exactamente el acoplamiento que tumbó
> el checkout el 2026-08-30.

**Ficheros.** `akibara-cart-checkout-blocks/src/ComunaDirectory.php`.

**En qué momento actúa.** En la primera consulta del directorio, dentro de
`load_from_vendor()` (`:377-393`): si no existe `bluex_get_communes_by_region()`, hace
`require_once` de `WP_PLUGIN_DIR . '/bluex-for-woocommerce/includes/data/chile-communes.php'`
(constante `VENDOR_DATA_RELATIVE`, `:26`). Si sigue sin existir la función, devuelve array
vacío: **falla abierto** y el checkout conserva el campo de texto libre.

**Qué lee y escribe.** Sólo lee. No guarda nada durable.

**Dónde lo opera el dueño.** En ningún sitio: es código.

**Qué ve el cliente.** El campo comuna del checkout, con o sin catálogo.

**Riesgo declarado.** El vigía de integridad de Blue Express (pieza 10) recorre los
`include`/`require` que el tercero se hace **a sí mismo**; este —el que le hacemos
nosotros— queda fuera de su vista. Si Blue mueve ese fichero, el catálogo se vacía sin un
solo error y la cotización empieza a fallar en silencio.

---

### 10. Blue Express — vigía de integridad y cierre de sus agujeros

**Qué hace en una frase.** Recorre el árbol del plugin de Blue buscando cargas de ficheros
que no existen, y cierra por hook las rutas de administración que el proveedor dejaba
abiertas.

**Ficheros.** `mu-plugins/akibara-bluex-integrity-watch.php`,
`akibara-business/src/Modules/Security/Security.php`.

**En qué momento actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `upgrader_process_complete` | 10 | Escanea tras cada actualización de plugin. |
| `init` | 30 | Programa la acción semanal `akibara_bluex_integrity_weekly` si no existe (`:132-144`). |
| `rest_endpoints` | 1000 | Sustituye el `permission_callback` de **cada** handler cuya ruta empiece por `/wc-bluex/v1` por uno que exige `manage_woocommerce`; el proveedor devolvía `true` (`Security.php:196`). |
| `rest_post_dispatch` | 1000 | Añade `Cache-Control: no-store` privado más `Cloudflare-CDN-Cache-Control` y `CDN-Cache-Control`: Cloudflare llegó a cachear públicamente `get-settings`. |
| `wp_ajax_<acción>` y `wp_ajax_nopriv_<acción>` | -1000 | Corta con 403 seis acciones administrativas del proveedor (`Security.php:49-56`). `clear_shipping_cache` queda fuera a propósito: es un flujo público del checkout (`:44-46`). |
| `admin_print_footer_scripts` | 1 | Repone el nonce REST al cliente React del proveedor, que lo generaba y no lo adjuntaba. |

**Qué lee y escribe.** Escribe una línea en el `error_log` del hosting con la lista de
cargas rotas, y dos options sin autocarga: `akb_bluex_watch_baseline_seen` y
`akb_bluex_watch_resolved_notified`. Manda dos correos a `admin_email`
(`akibara-bluex-integrity-watch.php:84`).

**Dónde se configura ese buzón, porque es el único destinatario de la alarma.** `admin_email`
es la option de WordPress que se edita en **Ajustes → Generales → «Dirección de correo
electrónico de administración»** (`/wp-admin/options-general.php`). Si está vacía, el vigía
**no manda nada** —y las marcas se escriben igual, con la consecuencia que cuenta el punto 1
de aquí abajo—. Si apunta a un buzón que nadie mira, el checkout puede estar caído y la
alarma no llega a nadie. Es la primera casilla que hay que revisar antes de confiar en esta
pieza.

**Dos cosas comprobadas en el código que conviene saber.**

1. La marca `akb_bluex_watch_baseline_seen` se escribe **antes** del `wp_mail` y sin mirar
   su resultado (`akibara-bluex-integrity-watch.php:86-101`); la rama de la buena noticia
   sólo exige esa marca (`:106-115`). O sea: si el correo de alarma se pierde, el de «ya
   está arreglado» puede llegar solo, sin haber avisado antes de la rotura.
2. El programador semanal exige `is_admin() || wp_doing_cron()` (`:132-144`). El mu-plugin
   hermano midió que en producción el disparo del cron entra por WP-CLI y ahí
   `wp_doing_cron()` devuelve **false** (`akibara-bluex-log-retention.php:79-82`). Con eso,
   el barrido semanal sólo se programa cuando alguien abre `/wp-admin/`.

**Dónde lo opera el dueño.** Dos correos: «[Akibara] ⚠ Blue Express embarcó includes rotos —
checkout en riesgo» y «[Akibara] ✅ Blue Express arregló su paquete».

**Qué ve el cliente.** Nada directamente; indirectamente, que el checkout no se caiga.

---

### 11. Seguridad del núcleo de WordPress y Mi cuenta

**Qué hace en una frase.** El mismo fichero que cierra Blue Express cierra también la
enumeración de usuarios de WordPress y reescribe el flujo de «olvidé mi contraseña».

> Omisión reparada: el mapa clasificaba `Security.php` entero como pieza de Blue Express.
> La mitad de su superficie es frontera con el núcleo de WordPress y con WooCommerce, y ahí
> hay una **llamada directa a una clase de WooCommerce**, no un hook.

**Ficheros.** `akibara-business/src/Modules/Security/Security.php`.

**En qué momento actúa (`register()`, `:58-70`).**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `wp_loaded` | 15 | `intercept_lostpassword_post`: verifica el nonce y llama **directamente** a `\WC_Shortcode_My_Account::retrieve_password()` (`:442`), después limpia los avisos de WooCommerce para que no se filtre «Invalid username or email» entre redirecciones. Así el cliente nunca distingue un correo registrado de uno que no lo está. |
| `authenticate` | 99 | Endurece los errores de acceso. |
| `woocommerce_process_registration_errors` | 99 | Lo mismo en el registro. |
| `rest_endpoints` | 10 | Retira `/wp/v2/users` para quien no puede listar usuarios. |
| `parse_request` | 1 | Bloquea la enumeración por `?author=N`. |

**Qué lee y escribe.** Nada durable.

**Dónde lo opera el dueño.** En ningún sitio: es código.

**Qué ve el cliente.** Un mensaje genérico en «olvidé mi contraseña», pase lo que pase.

---

### 12. Brevo — consentimiento y portadas de correo

**Qué hace en una frase.** No le habla por API: guarda la prueba de qué aceptó el cliente y
arregla que las portadas de sus correos se vean en Gmail.

**Ficheros.** `akibara-business/src/Modules/MarketingConsent/MarketingConsent.php`,
`mu-plugins/akibara-brevo-portada-jpg-en-el-carrito.php`.

**En qué momento actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `woocommerce_set_additional_field_value` | 10 | Actúa sólo sobre la clave `SendinblueWoocommerce/newsletter_opt_in` y guarda la prueba (`MarketingConsent.php:165`). |
| `woocommerce_store_api_checkout_update_order_from_request` | 10 | La casilla que el bloque no envió (sin tocar) queda como «NO» si el campo está registrado; y escribe la nota que quedó pendiente cuando la prueba se grabó sin ID (`:207-224`). |
| `woocommerce_store_api_checkout_order_processed` | 10 | Si la prueba no quedó escrita, un aviso por pedido en los registros, con la causa. |
| `woocommerce_add_to_cart` (y dos hermanas) | 9 y 11 | Abre y cierra una ventana alrededor de los oyentes de Brevo, que corren a 10. |
| `wp_get_attachment_image_src` | 20, sólo dentro de la ventana | Cambia la portada `.avif` por su hermana `-og.jpg`, escribiéndola en el acto si falta. |
| `plugins_loaded` | 20 | No engancha nada si falta la clase de Brevo o `SocialImageDerivatives`. |

**Nota de coste, medida.** La conversión síncrona ocurre **como mucho una vez por tomo** en
toda la vida del sitio: `SocialImageDerivatives::ensure_now()` sale antes con dos
`filemtime()` si la hermana ya existe y está al día, y guarda un transient de fallo por slug
que corta los reintentos (`SeoSocial/SocialImageDerivatives.php:350-360`). El mapa lo
describía como si ocurriera en el 88% de los «añadir al carrito»; no es así.

**Qué lee y escribe.** Escribe la ordermeta `_akb_marketing_consent` (JSON con valor, texto
exacto de la casilla, fecha, origen y versión de la política). Lee
`wp_page_for_privacy_policy`. Nunca escribe la meta de Brevo.

**Un borde medido, y es el de la prueba legal.** La «versión de la política» que se guarda es
la fecha de última modificación de la página de privacidad, y sale de `post_modified_gmt` tal
cual, sin convertir (`MarketingConsent.php:348-356`). Al pintarla en la nota del pedido,
`note()` sólo le da vuelta a `AAAA-MM-DD` con una expresión regular para dejarlo
`DD/MM/AAAA` (`:366-369`): nunca pasa por `get_date_from_gmt()`. Chile va tres o cuatro horas
detrás de GMT, así que una política editada entre las 20:00 y la medianoche hora chilena
queda anotada en la prueba de consentimiento con la **fecha del día siguiente**. No invalida
nada por sí solo, pero es el dato con el que se contesta «qué texto aceptó este cliente y
cuándo», y ahí un día de diferencia se nota.

**Dónde lo opera el dueño.** Notas privadas del pedido; WooCommerce → Estado → Registros,
fuente `akibara-consentimiento`.

**Qué ve el cliente.** La casilla de ofertas (la pinta Brevo) y la portada del correo de
carrito abandonado.

---

### 13. Rank Math — SEO, schema, robots, canonical y sitemaps

**Qué hace en una frase.** Rank Math es el único que emite el bloque de datos estructurados;
Akibara no emite un segundo, se cuelga del suyo y lo corrige.

**Ficheros.** `akibara-business/src/Utilities/SchemaGraph.php`,
`Modules/SchemaProductRankMath/`, `Modules/SeoIndexing/` (cinco clases),
`Modules/SeoSocial/SeoSocial.php`, `akibara-rewards/src/Reviews/Schema.php`,
`mu-plugins/akibara-discovery-warming.php`.

**En qué momento actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `rank_math/json_ld` | 90 / 91 / 92 / 99 / 100 / 999 | **Seis etapas** fijadas como constantes en `SchemaGraph.php:48-63` (oferta, colección de serie, adjuntos de página, rutas propias, relaciones Book↔Series, higiene final), que reparten entre sí **diez** callbacks de siete clases. Detalle abajo. |
| `rank_math/snippet/rich_snippet_product_entity` | 20 y 50 | Business añade Book/preventa/GTIN; Rewards sustituye `aggregateRating` y `review[]` por la forma exacta del plugin retirado. |
| `rank_math/frontend/robots` y `/canonical` | 98 / 99 | Deciden indexación por gramática de la URL. |
| `rank_math/frontend/title` y `/description` | 20 / 99 | Escriben las rutas propias que Rank Math no conoce. |
| `rank_math/opengraph/type` y compañía | 10 y 20 | Corrigen tipo, idioma e imagen social (JPG en vez de AVIF). |
| `rank_math/sitemap/http_headers` | 10 | `no-store` para el navegador, `public, max-age=14400` para el CDN. |
| `rank_math/can_edit_file` | `PHP_INT_MAX` | Habilita el editor de `robots.txt` **sólo** en la pantalla `rank-math-options-general`. |
| `option_rank_math_google_analytic_options` | 10 | Fuera de producción pone `install_code=false`. |

**Seis etapas, diez callbacks y un intruso: el desglose completo.** Seis y diez no se
contradicen: las seis son los *turnos* (las prioridades con nombre que declara `SchemaGraph`),
y varios módulos pueden compartir turno cuando adjuntan nodos independientes entre sí.

| Etapa (prioridad) | Callbacks que corren ahí | Qué hacen |
| --- | --- | --- |
| `PRODUCT_OFFERS` (90) | `ProductSchemaShopping::handle_rank_math_json_ld` (`:43`) | Completa la oferta, el envío y las devoluciones sobre el nodo Product que Rank Math ya emitió |
| `SERIES_COLLECTION` (91) | `SeriesSchema::handle_rank_math_json_ld` (`:187`) | Adjunta la colección de la landing de serie. Va después de producto porque puede referenciar sus nodos |
| `PAGE_ATTACHMENTS` (92) | `BrandLandingSchema::handle_json_ld` (`:42`) y tres de `SeoEnhancements`: `align_series_breadcrumb_schema`, `add_series_hub_schema`, `add_shipping_page_schema` (`:260-262`) | Cuelgan del WebPage o CollectionPage que ya existe: migas alineadas, hub de series, página de envíos, marca |
| `ROUTE_COLLECTIONS` (99) | `PreventasRoute::rm_jsonld` (`:175`) y `DisponiblesRoute::rm_jsonld` (`:209`) | Las rutas propias `/a-pedido/` y `/disponibles/`, que sustituyen la colección entera de su archivo y por eso corren después de quien la construyó |
| `PRODUCT_RELATIONSHIPS` (100) | `SchemaProductRankMath::restore_book_series_relationship` (`:53`) | Restaura la relación Book ↔ Series entre nodos ya definitivos |
| `GLOBAL_CLEANUP` (999) | `SeoEnhancements::cleanup_global_schema` (`:266`) | Higiene final del grafo completo: el `Place` hueco que la interfaz de Rank Math no deja quitar, y la autoría que apuntaba a un archivo 404 |

> Corrección medida: el propio docblock de `SchemaGraph.php:10` dice «nueve callbacks
> repartidos en siete clases». Las siete clases están bien; los callbacks son **diez**
> —contados uno a uno en la tabla— porque `BrandLandingSchema` se sumó a la etapa 92 sin
> actualizar la cuenta. Una versión anterior de este capítulo decía «seis» en la pieza y
> «nueve» en las reglas de negocio: ninguno de los dos números era el del array.

**Y un undécimo callback que NO respeta el contrato.** `akibara-search` engancha
`rank_math/json_ld` a prioridad **20** —o sea, antes que las seis etapas— desde
`frontend/facets-render.php:833`, para reescribir el `potentialAction` de búsqueda del sitio
y apuntarlo al buscador propio. No usa el vocabulario de `SchemaGraph` porque vive en otro
riel de despliegue y no puede depender de que `akibara-business` esté activo. Toca un nodo
(`WebSite`) que ninguna de las seis etapas toca, así que hoy no choca; pero si alguien mueve
una etapa por debajo de 20, el contrato deja de describir lo que ocurre.

**Cuatro llamadas directas a sus clases** (no a sus filtros), que conviene tener localizadas
porque `CLAUDE.md` prohíbe expresamente tocar terceros fuera de sus hooks:

- `\RankMath\Helper::is_breadcrumbs_enabled()`, `::get_settings()` y `::replace_vars()` en
  `SeoEnhancements.php:2675-2714` (con adaptador inyectable para pruebas).
- `\RankMath\Sitemap\Cache::invalidate_storage('product')` en
  `ProductSitemapStableOrder.php:142-143`.
- `use RankMath\Sitemap\Cache_Watcher` en `SeriesSitemapLastmod.php:21`.
- `\RankMath\Redirections\DB` y `\RankMath\Redirections\Cache` en el mu-plugin de abilities
  (pieza 14).

**Qué lee y escribe.** Postmeta y termmeta `rank_math_title`, `_description`, `_robots`,
`_canonical_url`, `_focus_keyword`.

**Dónde lo opera el dueño.** Las pantallas de Rank Math, con la pestaña `.htaccess`
retirada, y los campos SEO de cada producto y término.

**Qué ve el cliente.** El `title`, la descripción, el canonical, el Open Graph y el JSON-LD
de cada página.

---

### 14. Abilities API / MCP: redirecciones, palabra clave y la denylist

**Qué hace en una frase.** Publica cinco abilities propias sobre Rank Math y veta dos suyas. (El veto lo aplica **otro** mu-plugin, `akibara-abilities-denylist.php`, cuya lista tiene **tres** nombres: esas dos de Rank Math más `woocommerce/product-delete`.)

> Omisión reparada: el mapa describía la denylist que **veta** dos abilities de Rank Math,
> pero no las cinco que la casa **publica** por el mismo canal, todas con
> `mcp.public = true`. La superficie real de MCP sobre Rank Math es al revés de lo que el
> mapa sugería.

**Ficheros.** `mu-plugins/akibara-rankmath-redirections-abilities.php`,
`mu-plugins/akibara-abilities-denylist.php`.

**En qué momento actúa.**

| Hook | Qué hace |
| --- | --- |
| `wp_abilities_api_categories_init` | Registra la categoría `akibara-operations` (`:275-289`). |
| `wp_abilities_api_init` | Registra `akibara/set-focus-keyword` siempre, y las cuatro de redirecciones sólo si existe `\RankMath\Redirections\DB` (`:291-315`). |
| `wp_register_ability_args` (denylist) | Sustituye el `permission_callback` por una **clausura que anota el intento** en el registro de WooCommerce —«VETADA `<ability>` · por `<usuario>` …»— y devuelve `false` (`akibara-abilities-denylist.php:51-63`); y, **si y sólo si** el tercero ya declaró `meta.mcp` como array, baja también `public` a `false` (`:65-66`). No es un `__return_false` pelado: con él, un intento bloqueado no dejaba ni una línea (corregido 2026-09-20, ronda 10). |

**Las cinco abilities.**

| Nombre | Escribe | Permiso |
| --- | --- | --- |
| `akibara/set-focus-keyword` | `rank_math_focus_keyword` de hasta 100 entradas, páginas o productos | `edit_others_posts \|\| manage_options` (`:210-216`) |
| `akibara/redirections-status` | nada (lectura) | `rank_math_redirections \|\| manage_options` (`:25-27`) |
| `akibara/redirections-snapshot` | nada (lectura) | ídem |
| `akibara/redirections-trash` | `DB::change_status(..., 'trashed')` + `Cache::purge()` | ídem |
| `akibara/redirections-restore` | `DB::change_status(..., 'active')` | ídem |

Las tres de escritura son *fail-closed*: IDs explícitos, máximo 500, `expected_count`
obligatorio y `dry_run` verdadero salvo que se diga lo contrario
(`akibara_rm_redirections_change_status()`, `:88-157`).

**Dos detalles del código que conviene registrar.**

1. `akibara/set-focus-keyword` se conforma con `edit_others_posts` —la capacidad del rol
   Editor— mientras el resto de abilities de la casa exigen `rank_math_redirections` o
   `manage_options`. Escribe la señal SEO de las landings de serie.
2. Para hablar con el módulo de redirecciones, el mu-plugin **carga a mano el autoloader
   Composer de Rank Math** (`:298-304`) si su clase no está. Ese directorio,
   `seo-by-rank-math/vendor/`, es donde vive la **segunda copia de Action Scheduler** del
   árbol (pieza 21): registrar ese autoloader en peticiones REST y en WP-CLI puede hacer que
   clases compartidas se resuelvan contra la copia de Rank Math.

**Qué lee y escribe.** Postmeta `rank_math_focus_keyword`; las tablas de redirecciones de
Rank Math, siempre por su API oficial, nunca por SQL.

**Dónde lo opera el dueño.** No hay pantalla propia: MCP o WP-CLI. La pantalla oficial de
Rank Math sigue siendo la de siempre. El patrón de llamada es
`wp_get_ability( '<nombre>' )->execute( $entrada )` dentro de `wp eval`, con `--user=` (sin usuario,
WP-CLI no tiene capacidades y el permiso de la ability lo rechaza); las tres de escritura llevan siempre
`dry_run` y `expected_count`:

```bash
# Leer, que no exige nada: cuántas redirecciones hay y en qué estado.
wp eval 'echo wp_json_encode( wp_get_ability( "akibara/redirections-status" )->execute( array() ) );' --user=1

# Escribir: primero en seco, con los IDs explícitos (nunca un filtro «todas las que…»).
wp eval '
  echo wp_json_encode( wp_get_ability( "akibara/redirections-trash" )->execute( array(
    "ids"            => array( 412, 413 ),
    "expected_count" => 2,
    "dry_run"        => true,
  ) ) );
' --user=1
```

Cambiar `dry_run` a `false` con el mismo `expected_count` es lo que ejecuta de verdad.

**Qué ve el cliente.** Las redirecciones que funcionan o dejan de funcionar.

---

### 15. LiteSpeed Cache — barandas, exclusiones y purga

**Qué hace en una frase.** Fija cinco ajustes **al leerlos**, para que la casilla pueda estar
marcada en el panel sin que el efecto llegue nunca al HTML, y añade dos rutas a la exclusión.

**Ficheros.** `mu-plugins/akibara-litespeed-guardrails.php`,
`akibara-business/src/Modules/TaxonomyDiscount/LscPurger.php`, `FrontendCompatibility.php`,
`akibara-search/src/Support/RestCache.php`,
`akibara-cart-checkout-blocks/src/RuntimeCompatibility.php`,
`themes/akibara-gp/inc/enqueue.php`.

**En qué momento actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `litespeed_conf_load_option_optm-css_comb` | 99 | `false` aunque la casilla esté marcada: combinar CSS rompe el patrón `media=print`+`onload` del tema. |
| `litespeed_conf_load_option_optm-js_comb` | 99 | Igual para JavaScript. |
| `litespeed_conf_load_option_guest_optm` | 99 | Igual para *Guest Optimization*. El identificador lleva **guion bajo**, a diferencia de los dos de arriba; con un guion el filtro sería mudo. |
| `litespeed_conf_load_option_media-lazy` | 99 | `false`: LiteSpeed no puede apagar la carga diferida nativa de WordPress (`media.cls.php:125-128`). Desde la 1.2.0 (2026-09-14): con la opción encendida en producción, `/tienda/` bajaba de entrada 22 portadas fuera de pantalla en móvil (`:135-141`). |
| `litespeed_conf_load_option_cache-exc` | 99 | Añade `/wp-json/wc/store/` y `/wp-json/wc/v3/` (`:168-185`). |
| `litespeed_conf_load_option_cache-mobile` | 99 | `false`: sin lista de agentes móviles, la opción encendida hacía `set_nocache( 'is mobile' )` a toda visita de celular (`control.cls.php:788-790`); desde la 1.3.0 (2026-09-15) celular y escritorio comparten una copia (`:233-239`). |
| `update_option_<opción>` / `add_option_` / `delete_option_` | 10 | `LscPurger::schedule_now` sobre el conjunto cerrado `Settings::OPTIONS` más `DiscountCeiling::OPTION` (`LscPurger.php:49-66`). |
| `akibara_taxonomy_discount_pct_saved` | 10 | Lo mismo cuando el porcentaje se guarda como termmeta. |
| `shutdown` | 99 | `do_purge()` dispara `litespeed_purge_all`, que con `cdn-cloudflare_clear` también vacía Cloudflare (`:100-110`). |

> **Esta pieza no cubre toda la conversación con LiteSpeed, y conviene decirlo.** Aquí están
> las barandas (fijar cinco ajustes peligrosos y añadir dos rutas a la exclusión) y la purga. El resto —qué páginas se declaran
> no cacheables y qué clases CSS se protegen de que el «CSS crítico» las borre— vive
> **repartido por módulo**, en unos treinta ficheros de los cuatro plugins y el tema. Quien
> tenga que depurar «por qué esta página se cacheó» o «por qué este bloque perdió los
> estilos» empieza por esta lista, no por este capítulo:
>
> | Hook de LiteSpeed | Para qué se usa | Dónde vive (ficheros que lo usan) |
> | --- | --- | --- |
> | `litespeed_control_set_nocache` | Declarar una respuesta no cacheable | 11 ficheros: `AccountCollection/Endpoint`, `AgentReadiness/MarkdownNegotiation`, `SeoEnhancements`, `SeoIndexing/SeriesDirectorySearchPolicy` y `…/SeriesPaginationPolicy`, `SeriesBundles/BuilderWidget`, `SeriesDirectory/Shortcode`, `SeriesEditions`, `SeriesFacts`, `akibara-search/EditorialDiscountPublication`, `akibara-search/Support/RestCache` |
> | `litespeed_ucss_whitelist` | Proteger clases CSS de que el CSS crítico las descarte | 9 ficheros: `BackInStock`, `BrandsDirectory/BrandFamilyLanding` y `…/BrandLandingCallout`, `LoopEnhancer`, `OrderPreventa/PreventaUX`, `SeriesBundles/LandingOffer`, `SeriesEditions`, `SeriesFacts`, `Wishlist` |
> | `litespeed_purge_url` | Purgar una URL concreta | 6 ficheros: `BrandsDirectory/EditionFacts`, `…/EditionsPanel`, `…/Shortcode`, `SeriesDirectory/Shortcode`, `akibara-rewards/Reviews/Carousel`, `akibara-search/EditorialDiscountPublication` |
> | `litespeed_purge_post` | Purgar una ficha concreta | `OrderPreventa/PreventasScreen`, `UpsellsAutofill` |
> | `litespeed_purge_all` | Purga total (la cara: propaga a Cloudflare) | 11 ficheros, entre ellos `AdminPanel`, `AnnouncementBar`, `CatalogOrder`, `HomeHero/AdminUI`, `SeriesFacts`, `TaxonomyDiscount/LscPurger`, `akibara-search/RestCache` |
> | `litespeed_control_set_ttl` y `litespeed_control_finalize` | Fijar la caducidad de una respuesta y cerrarla | `akibara-search/EditorialDiscountPublication` |
> | `litespeed_media_lazy_img_excludes` | Sacar una imagen de la carga diferida de LiteSpeed (el LCP de la portada). **Inerte desde 2026-09-14:** LiteSpeed sólo aplica ese filtro dentro de `_parse_img()` (`media.cls.php:999`), que sólo corre con su lazy activo (`:843-847`), y las barandas `media-lazy` y `guest_optm` lo dejan apagado. Listado para retirarlo desde akibara-business | `HomeHero` |
> | `litespeed_optm_gm_js_exc` | Sacar un script del modo «Guest» | `FrontendCompatibility`, `themes/akibara-gp/inc/enqueue.php` |

**Tres defectos comprobables leyendo el código.**

1. `akibara_litespeed_rutas_sin_cache()` promete por escrito «se AÑADE a lo que hubiera, sin
   pisarlo: la lista es del sitio, no nuestra» (`:119-121`) y hace lo contrario en el caso
   no-array: `$excluidas = is_array($valor_guardado) ? array_values($valor_guardado) :
   array()` (`:128`). Si `cache-exc` llega como cadena con saltos de línea —que es como
   LiteSpeed guarda y muestra sus listas— la lista del sitio **desaparece**. Los tres
   filtros hermanos de este mismo repositorio hacen
   `is_array($x) ? $x : preg_split('/[\r\n]+/', (string) $x)` precisamente por esto.
2. **El cuarto hermano es peor, y es el que protege el checkout.**
   `RuntimeCompatibility::exclude_checkout_from_litespeed( array $uris )` (`:136`) declara el
   parámetro **tipado como array** en un fichero con `declare( strict_types = 1 )` (`:8`). El
   defecto nº1 al menos degrada: se queda sin lista y sigue. Este no degrada: si LiteSpeed
   alguna vez pasa una cadena por `litespeed_optm_uri_exc`, PHP lanza un `TypeError` fatal en
   plena carga del checkout. Los otros tres filtros del repositorio aceptan `mixed` y
   normalizan; este no, y es el único de los cuatro cuyo fallo se ve desde la tienda.
3. `LscPurger::el_panel_ya_purga()` decide si purgar mirando `$_POST['akibara_panel_save']`
   (`:95-98`), un campo de formulario. Y `AdminPanel::should_purge_after_save()` devuelve
   `false` cuando `$cambios < 1` (`AdminPanel.php:295-301`), donde `$cambios` sólo cuenta
   options. El porcentaje por serie es **termmeta**. Consecuencia, contada entera en el caso
   de uso 9.

**Qué lee y escribe.** Escribe la option `akb_search_rest_purge_receipt`, recibo idempotente
de la purga por tag del buscador.

**Dónde lo opera el dueño.** El panel de LiteSpeed sigue mostrando sus casillas; tres de
ellas ya no tienen efecto.

**Qué ve el cliente.** Que el HTML servido sea el actual y que el CSS y el JS lleguen enteros.

---

### 16. Cloudflare — cabeceras de borde e IP real

**Qué hace en una frase.** Le dice explícitamente qué no puede guardar, y decide cuándo
creerse la IP que declara.

**Ficheros.** `akibara-business/src/Utilities/RateLimit.php`,
`akibara-search/src/Support/RestCache.php`, `Modules/Security/Security.php`,
`mu-plugins/akibara-discovery-warming.php`.

**En qué momento actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `akibara_trusted_proxy_ranges` | 5 | `RateLimit::seed_trusted_proxy_ranges` descarta lo que trajera el filtro y publica la lista canónica: **15 rangos IPv4** (`RateLimit.php:328-343`) y **7 IPv6** (`:344-351`), 22 en total. Prioridad 5 para que el sitio pueda sumar o quitar encima. |
| `rest_post_dispatch` | 1000 | `no-store` de borde en `/wc-bluex/v1`. |
| `rank_math/sitemap/http_headers` | 10 | `public, max-age=14400` para el CDN. |

**Regla central.** `CF-Connecting-IP` se acepta **sólo** si el par TCP (`REMOTE_ADDR`) está
en uno de esos rangos; si no, se usa `REMOTE_ADDR`. `X-Real-IP` y `X-Forwarded-For` no se
confían nunca porque Cloudflare no los sanea (`RateLimit.php:290-310`).

**Un matiz entre las dos copias del limitador.** `client_ip()` es idéntico byte a byte entre
`akibara-business/src/Utilities/RateLimit.php:301-315` y
`akibara-search/src/Support/RateLimit.php`, e `ip_in_cidr()` sólo difiere en comentarios. Lo
que **sí** diverge es la validación del resultado del filtro: la copia del buscador
(`:303-320`) devuelve `false` para todo el mundo ante una sola entrada no-string, vacía o de
más de 80 caracteres, o si la lista pasa de 100 rangos; la de Business (`:394-403`)
simplemente salta la entrada mala. Cuando el buscador cae a `REMOTE_ADDR` detrás de
Cloudflare, esa IP es la del **borde**: todos los visitantes que entren por el mismo nodo
comparten un solo cubo de envíos.

**Qué lee y escribe.** Transients `akb_rl_<hash>` y `akb_rlm_<hash>`, contadores por IP
resuelta.

**Dónde lo opera el dueño.** En ningún sitio: los rangos se actualizan en código.

**Qué ve el cliente.** Que el precio y el stock sean los de ahora, y que un abuso ajeno no
le cierre el formulario.

**Cloudflare no se purga directamente desde aquí.** No hay ni una llamada a su API en el
código propio: se purga LiteSpeed y él propaga, porque corre con `cdn-cloudflare_clear`
encendido. Eso está contado en la pieza 15, y el coste medido de esa purga total, en el caso
de uso 9.

---

### 17. Microsoft Clarity — analítica de sesión y atribución de IA

**Qué hace en una frase.** Apaga Clarity fuera de producción y le añade el vocabulario de
comercio y la atribución de tráfico de IA que el rastreador no puede adivinar.

> Corrección aplicada: el mapa decía que a Clarity van «sólo tres nombres de ciclo de vida».
> Esos tres son únicamente los valores posibles del **evento inicial** que calcula el PHP:
> `purchase` en la página de gracias, `begin_checkout` en el checkout y `view_product` en una
> ficha de producto (`FrontendCompatibility.php:135-141`). Además de ese, el JavaScript emite
> siete eventos propios y escribe dos etiquetas de sesión: **diez nombres distintos** en
> total.

**Ficheros.** `mu-plugins/akibara-clarity-production-only.php`,
`akibara-business/src/Modules/FrontendCompatibility/FrontendCompatibility.php`,
`akibara-business/assets/js/clarity-commerce-events.js`,
`akibara-business/src/Modules/ScriptDiagnostics/CrossOriginScripts.php`.

**En qué momento actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `init` | 1 | El mu-plugin hace `remove_action` de `clarity_add_script_to_header` y de los dos del Brand agent cuando el entorno no es producción (`:18-25`). |
| `plugins_loaded` | 20 | `FrontendCompatibility` repite el `remove_action`, con una condición **más estricta**: exige además el host canónico (`:44-46`), porque algunos clones de hosting quedan marcados como producción. |
| `wp_enqueue_scripts` | 30 | Carga `clarity-commerce-events.js` con `window.akibaraClarityCommerce = {initialEvent}`, sólo en producción y sólo si `clarity_project_id` no está vacío. El **evento inicial** lo decide el PHP mirando qué página es (`:135-141`): `purchase` en la página de gracias, `begin_checkout` en el checkout, `view_product` en una ficha, y cadena vacía en cualquier otra página, donde entonces no se emite ninguno. |

**Lo que el JavaScript emite de verdad: diez nombres, y así se llega a diez.** Siete son
propios del comportamiento del cliente —`add_to_cart`, `remove_from_cart`, `coupon_applied`,
`checkout_error`, `wishlist_add`, `payment_method_selected` y `ai_referral_landing`
(`:455-510`)— y los otros tres son los valores posibles del evento inicial que le pasa el
PHP: `view_product`, `begin_checkout` y `purchase`. En una misma carga de página sólo puede
salir **uno** de esos tres, pero los tres nombres llegan a Clarity a lo largo del día y hay
que saber leerlos en su panel. Además escribe dos etiquetas de sesión,
`clarity('set','akb_ai_source')` y `akb_ai_evidence` (`:370-375`), y fuerza
`clarity('upgrade','purchase')` cuando el evento es una compra (`:436-438`).

**La atribución de IA.** Lee `document.referrer` y el parámetro `utm_source`, los compara
contra una lista cerrada de cinco fuentes —`chatgpt`, `copilot`, `perplexity`, `claude`,
`gemini` (`:23-42`)— y persiste el resultado en `sessionStorage` bajo la clave
`akb_ai_attribution` (`:15`, `:160`). Es el único instrumento que mide el carril de
descubribilidad por IA, y también el que marca la frontera real de privacidad con Clarity:
nunca viajan URL, consulta, identificador de pedido ni de cliente.

**Un borde medible.** El deduplicador vive en variables de módulo (`lastEvent`,
`lastEventAt`, ventana de 750 ms, `:441-450`), así que sólo colapsa el par jQuery/Blocks
dentro de la **misma** carga de página. Cada recarga de la página de gracias vuelve a emitir
`purchase` y a forzar `upgrade`.

**Qué lee y escribe.** `sessionStorage` en el navegador. Lee la option `clarity_project_id`.

**Dónde lo opera el dueño.** El panel de Clarity, fuera de WordPress.

**Qué ve el cliente.** Un script de terceros en el HTML, sólo en producción.

---

### 18. Google — Maps/Places, Analytics, Ads, Merchant y el acceso con Google

**Qué hace en una frase.** Aporta la clave de Maps, los datos del feed de Shopping, el
apagado fuera de producción, y hace que «Continuar con Google» devuelva al cliente al tomo
que estaba mirando.

> Omisión reparada: el componente «Google» del mapa enumeraba Maps, GA4, Ads y Merchant y se
> dejaba fuera el acceso con Google, que es el botón **primario** del muro de acceso.

**Ficheros.** `akibara-business/src/Modules/AddressAutocomplete/AddressAutocomplete.php`,
`akibara-cart-checkout-blocks/src/AddressIntegration.php`, `GooglePlacesAddressProvider.php`,
`akibara-business/src/Modules/EstimatedDelivery/GlaBridge.php`,
`Modules/ProductSchemaShopping/AdultContentFeed.php`,
`Modules/GoogleLoginButton/GoogleLoginButton.php`,
`akibara-cart-checkout-blocks/assets/js/checkout-inner-blocks-editor.js`.

**En qué momento actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `woocommerce_address_providers` | 10 | Registra `GooglePlacesAddressProvider` en la API oficial de WooCommerce. |
| `pre_option_woocommerce_address_autocomplete_enabled` | 10 | Devuelve `yes` **sólo** en el checkout de bloques. |
| `pre_update_option_akibara_google_maps_api_key` | 10 | Rechaza al guardar cualquier valor que no case con `/^AIza[0-9A-Za-z_-]{35}$/` y conserva el anterior. |
| `option_woocommerce_google_analytics_settings` | 10 | Vacía `ga_id` fuera de producción. |
| `woocommerce_gla_disable_gtag_tracking` | 10 | Devuelve `true` fuera de producción. |
| `woocommerce_gla_product_attribute_values` | 10 | `GlaBridge` inyecta la fecha de disponibilidad (borde alto del rango) y `AdultContentFeed` declara `adult:true` por serie. |
| `rtcamp.google_default_redirect` | 10 | `GoogleLoginButton.php:31` y `:59-63`: devuelve el destino saneado del muro, porque el tercero `login-with-google` sólo mira `redirect_to` en `wp-login.php` y en `/mi-cuenta/` caía a la URL actual —o sea, al muro. |
| `woocommerce_login_form_end` | 1 | `drop_unvalidated_redirect_field()` (`:84-103`): desengancha el callback de WooCommerce Blocks que imprime un `<input name="redirect">` **sin validar**, buscándolo por el nombre de método `redirect_to_field` y por el namespace `Automattic\WooCommerce\Blocks\`. |
| `woocommerce_locate_template` | 10 | Sirve la plantilla propia de `form-login.php`. |

**Un borde que apaga el seguimiento sin que nadie se entere.** Los tres apagados de Google
fuera de producción —vaciar `ga_id`, poner `install_code=false` en Rank Math y devolver
`true` en `woocommerce_gla_disable_gtag_tracking`— cuelgan todos del mismo método,
`FrontendCompatibility::is_production_frontend()` (`:202-210`, consumido en `:219`, `:234` y
`:246`). Ese método pregunta por `$_SERVER['HTTP_HOST']` y exige que sea `akibara.cl` o
`www.akibara.cl`. **En el cron y en WP-CLI no hay `HTTP_HOST`**: la variable no existe, el
host resuelto es cadena vacía y el método devuelve `false` aunque el sitio sea el real. O
sea: cualquier cosa que corra fuera de una petición del navegador —una tarea programada, un
`wp` por línea de comandos— ve un sitio que, para estos tres filtros, «no es producción», y
el seguimiento de Google Ads se declara desactivado. Hoy no se ha medido daño, porque los
tres valores se consultan al pintar HTML y ahí sí hay navegador; pero cualquier código futuro
que lea esos filtros desde el cron recibirá la respuesta contraria a la verdad. El host
explícito está ahí a propósito —hay clones de Hostinger marcados como `production`— y la
corrección no es quitarlo, es no fiarse de él cuando no hay petición web.

**Una palanca que existe y no se usa.** El mismo tercero del botón, `login-with-google`,
publica `rtcamp.google_user_created`, que avisa cuando alguien se da de alta por Google. Con
él se podría vincular automáticamente los pedidos que ese cliente hizo antes como invitado.
**No se usa**, y la decisión está escrita en el código:
`GuestOrderLinking/GuestOrderLinking.php:54` es una clase vacía a propósito, cuyo único
contenido es la explicación de por qué. Resumen: WooCommerce 11 ya trae el camino correcto
—el cliente confirma su correo desde un enlace que llega a **su** bandeja y ahí se vinculan
los pedidos—, y colgarse del alta con Google exigiría antes comprobar que el token de Google
trae `email_verified` y que el plugin lo respeta, cosa que hoy no hace. El coste de no usarla
es un clic para quien entra con Google. Si algún día se usa, el módulo a tocar es ese.

**Un acoplamiento declarado.** El desenganche del campo `redirect` depende de que WooCommerce
siga llamando `redirect_to_field` a ese método y siga colgándolo desde una clase del
namespace `Blocks\`. WooCommerce ha ido moviendo clases de `Blocks\` a `Internal\`; el día
que lo haga, el campo sin validar vuelve a imprimirse, gana sobre el de la casa —lo dice el
propio comentario del fichero— y todo sigue devolviendo 200. No hay prueba de Site Health
que lo cubra, a diferencia de los parches de Mercado Pago y Flow.

**Qué lee y escribe.** Termmeta `_akb_adult_content` en `pa_serie`. Lee postmeta `_wc_gla_*`
sin escribirlas nunca. Lee la option `akibara_google_maps_api_key` o la constante
`AKB_GOOGLE_MAPS_API_KEY`, que tiene prioridad.

**Dónde lo opera el dueño.** Akibara → Dirección (clave e interruptor); Productos →
Atributos → Serie (marca +18).

**Qué ve el cliente.** El autocompletado del campo dirección, el botón «Continuar con
Google» y las fichas en Google Shopping.

---

### 19. Hostinger — transporte de correo, su guard y el centinela

**Qué hace en una frase.** Todo el correo sale por un buzón autenticado con las credenciales
en `wp-config.php`, y hay un centinela que vigila **desde fuera** que el fichero siga ahí.

**Ficheros.** `mu-plugins/akibara-correo-smtp.php`,
`mu-plugins/akibara-mail-transport-watch.php`,
`mu-plugins/akibara-correo-no-sale-a-clientes.php`,
`mu-plugins/akibara-https-urls-fuera-de-web.php`,
`akibara-business/src/Services/MailerBoot.php`.

**En qué momento actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `pre_wp_mail` | `PHP_INT_MAX` | `Transport::guard_configuration`: corta con `false` si el transporte no está habilitado o el perfil no valida. |
| `phpmailer_init` | `PHP_INT_MAX` | Aplica el perfil: `isSMTP`, host, puerto, cifrado, credenciales, timeout 20 s, `setFrom` y `Sender`. |
| `wp_mail_succeeded` | `PHP_INT_MAX` | Borra el rastro del corte — y sólo cuando un correo **sale de verdad**, no cuando PHPMailer se construye. |
| `wp_mail_failed` | `PHP_INT_MAX` | Anota el mensaje del servidor, recortado a 140 caracteres. |
| `admin_init` | 1 | `Watch::inspect` comprueba una vez por petición que la clase esté cargada **y** enganchada. |
| `init` | 1 (sólo con `DOING_CRON`) | La misma comprobación en el cron. |
| `wp_mail` | `PHP_INT_MAX` | Fuera de producción redirige a `capturado@akibara.test`, conservando el destinatario original en el asunto y en la cabecera `X-Akibara-Destinatario-Original`. |
| `set_url_scheme` | 10 | Fuerza `https` en las URL generadas fuera de una petición web. |

**Dos bordes comprobados.**

1. `validate_configuration()` sólo tiene ramas para los entornos `local` y `production`, y
   un `else { return null; }` para todo lo demás (`:339-354`). En un *staging* de Hostinger
   o un clon marcado `development`, el guard corta con `pre_wp_mail` y el filtro `wp_mail`
   —donde vive la redirección a la bandeja de captura— no llega a correr nunca. Hoy no
   muerde porque el espejo Docker es `local`.
2. El troceo de destinatarios usa `preg_split('/,\s*/', ...)` (`:110`), así que
   `"Apellido, Nombre <a@b.cl>"` se parte en dos. El trozo `"Apellido` **no se descarta**:
   `akibara_correo_es_de_pruebas()` devuelve `false` cuando no hay dominio (`:68-72`), así
   que entra en la lista de «reales» y viaja al asunto y a la cabecera. La dirección real no
   se pierde —el segundo trozo sí casa `<...>`—; lo que se ensucia es la evidencia, y además
   mete texto controlado por el pedido dentro del asunto.

**Qué lee y escribe.** Options `akibara_mail_transport_outage` (con `kind`, `reason`,
`last_gmt`, `first_gmt` y `count`) y `akibara_mail_transport_watch` (con `missing_since`,
`last_seen_gmt`, `outcome` y hasta 10 reposiciones). Repone el fichero desde una copia en un
directorio hermano del docroot, nunca servible por web.

**Dónde lo opera el dueño.** No hay pantalla que abrir: son avisos que salen solos en
**cualquier página de `/wp-admin/`**, para quien tenga permiso `manage_woocommerce`. Son
tres, y cada uno dice una cosa distinta:

| Aviso | Color y si se puede cerrar | Título exacto | Qué significa y qué hacer |
| --- | --- | --- | --- |
| El correo no sale | Rojo, **no** descartable | «El correo de Akibara NO está saliendo» | El guard cortó o el buzón rechazó. El cuerpo distingue las dos causas: si el transporte está configurado, el problema es del buzón (contraseña rotada, cuota llena, `smtp.hostinger.com` caído); si no, hay que revisar las constantes SMTP de `wp-config.php`. Desaparece solo cuando un correo vuelva a salir de verdad (`akibara-correo-smtp.php:285-293`) |
| Falta el fichero del transporte | Rojo, **no** descartable | «Falta el transporte de correo de Akibara» | El mu-plugin no está cargado desde tal fecha. El cuerpo dice qué hizo el centinela: `repuesto` (lo copió de la copia de rescate, pero algo lo borró → revisar hPanel → Seguridad → Malware scanner), `sin-copia` (subirlo a mano por SSH), `fallo-al-reponer` (no pudo escribir en `mu-plugins/`), `presente-pero-inerte` (el fichero está pero no cargó; no lo pisa, para no tapar la causa) (`akibara-mail-transport-watch.php:332-354`) |
| Ya desapareció antes | Ámbar, **sí** se descarta con la ✕ | «El transporte de correo desapareció y se repuso solo» | Hoy el correo sale, pero el fichero ya se perdió N veces, la última tal día. Se descarta con la equis del propio aviso, y vuelve a salir la próxima vez que ocurra (`akibara-mail-transport-watch.php:361-383`) |

> Corrección de una versión anterior: decía «dos avisos». Son tres, y los dos rojos vienen de
> mu-plugins distintos y significan cosas distintas: uno es «el correo no sale», el otro es
> «el programa que hace salir el correo ya no está en el servidor».

**Qué ve el cliente.** Que los correos lleguen, y con el remitente correcto.

---

### 20. Catálogo de claves de proveedores

**Qué hace en una frase.** Un inventario declarativo de qué claves espera Akibara, de qué
tipo son y quién las usa; no contiene ningún valor.

**Ficheros.** `mu-plugins/akibara-provider-access.php`.

**En qué momento actúa.** `admin_menu:99` añade el submenú «Claves y accesos» bajo
`akibara-panel` con capacidad `manage_options`; `admin_notices:10` lista los problemas.

**La regla.** Si la clave viaja al navegador es pública y puede vivir en una option; si no
viaja, vive **únicamente** como `define()` en `wp-config.php`, y la rama que llega a
`get_option()` está dentro de un `if` sobre el ámbito (`:265-270`). El catálogo se **niega**
a devolver el valor de una clave cuyo consumidor es otro fichero (`:295-304`): para saber si
está puesta existe `is_configured()`, que no la devuelve. El formato se revalida en la
**lectura**, no sólo al guardar, porque un `wp option update` esquiva el validador del panel.

**Tres comandos.** `akibara access-list` (inventario), `access-check` (gate del despliegue,
sale con 1) y `access-scan` (constantes `AKB_`/`AKIBARA_` leídas fuera del catálogo, quitando
comentarios con `token_get_all`).

**Dónde lo opera el dueño.** Akibara → Claves y accesos: sólo lectura, nunca muestra el
valor, sólo si está, de dónde sale y cuántos caracteres tiene.

**Qué ve el cliente.** Nada.

---

### 21. Action Scheduler (dos copias en el árbol)

**Qué hace en una frase.** Acota a 14 días la retención del historial de la cola de tareas,
por hook y sin editar a nadie, porque hay **dos** copias del mismo programa instaladas.

**Ficheros.** `mu-plugins/akibara-as-retention.php`,
`mu-plugins/akibara-bluex-log-retention.php`,
`akibara-business/src/Modules/OrderPreventa/StockChangeListener.php`.

**En qué momento actúa.** `action_scheduler_retention_period:10` devuelve
`14 * DAY_IN_SECONDS` (el default del tercero es 31); no toca las pendientes ni las
fallidas. `action_scheduler_init:20` programa la limpieza de registros de Blue Express con
`as_has_scheduled_action` (1 consulta) en vez de `as_next_scheduled_action` (3).

**El patrón de la casa, y su excepción.** «Programar tareas sólo desde donde alguien puede
repararlas» lo cumple `akibara-bluex-log-retention.php:85-110`:
`wp_doing_cron() || (WP_CLI) || (is_admin() && ! wp_doing_ajax())`. El vigía de integridad
usa otro más pobre, sin rama de WP-CLI y sin excluir `admin-ajax`
(`akibara-bluex-integrity-watch.php:132-144`).

**Una instancia directa.** `StockChangeListener::claim_pending_product_actions()`
(`:184-189`) hace `new \ActionScheduler_DBStore()` y `stake_claim()` para reclamar acciones
por hook y grupo. Es una clase concreta de una de esas dos copias, justo lo que el resto del
área evita por principio.

**Qué lee y escribe.** Las tablas `wp_actionscheduler_actions` y `_logs` las purga el propio
Action Scheduler por lotes; no hay ningún `DELETE` manual.

**Dónde lo opera el dueño.** WooCommerce → Estado → Acciones programadas.

**Qué ve el cliente.** Nada.

---

### 22. El buscador y la caché de producto de WooCommerce

**Qué hace en una frase.** La autoridad de producto del índice del buscador depende de poder
invalidar la caché interna de WooCommerce, y si no puede, se declara no disponible.

> Omisión reparada: del plugin del buscador el mapa sólo había leído `Support/RestCache.php`.
> Este acoplamiento no estaba declarado en ninguna parte.

**Ficheros.** `akibara-search/src/ProductSourceAuthority.php`.

**En qué momento actúa.** En `evict_woocommerce_product_caches()` (`:783-809`), antes de
hidratar productos. Exige que existan `\WC_Cache_Helper::get_cache_prefix()` e
`::invalidate_cache_group()`; si no, `mark_unavailable()`. Después **verifica** que el
prefijo cambió de verdad: lee antes, invalida, lee después y compara. Si no cambió, se
declara no disponible en lugar de seguir con datos posiblemente viejos.

**Qué lee y escribe.** La caché de objetos de WooCommerce, grupo `product_<id>`.

**Dónde lo opera el dueño.** En ningún sitio: es código.

**Qué ve el cliente.** Que los resultados del buscador traigan el precio y el stock de ahora.

---

### 23. GeneratePress (tema padre) y Joinchat (WhatsApp)

**Qué hace en una frase.** Corrige por hook dos cosas del tema padre y una del botón de
WhatsApp.

**Ficheros.** `akibara-business/src/Modules/FrontendCompatibility/FrontendCompatibility.php`,
`Modules/PickupCoordination/PickupCoordination.php`,
`akibara-cart-checkout-blocks/src/RuntimeCompatibility.php`.

**En qué momento actúa.** `template_redirect:20` hace
`remove_action('generate_before_content','generate_featured_page_header_inside_single')` en
fichas de producto —el tema la ocultaba con `display:none`, que no evita descargar 130 KB
con `fetchpriority=high` compitiendo con el LCP real—. `generate_show_title:100` devuelve
`false` en el checkout. `wp_enqueue_scripts:30` carga el despeje que impide que el botón
flotante de Joinchat tape la cuarta tarjeta de confianza de la portada, midiendo la
intersección real en vez de fijar un desplazamiento estático.

**Qué lee y escribe.** Lee la option `joinchat`, clave `telephone`, con `+56944242844` como
respaldo (`PickupCoordination.php:162-168`).

**Dónde lo opera el dueño.** La pantalla del plugin Joinchat.

**Qué ve el cliente.** El botón de WhatsApp, la portada y la velocidad de carga de la ficha.

---

### 24. Terceros retirados que dejaron huella: CusRev y Simple Points and Rewards

**Qué hace en una frase.** El motor propio de puntos y reseñas duerme mientras el tercero
siga vivo, y conserva sus nombres de datos —y también sus **nombres de función**— para no
romper lo ya publicado.

**Ficheros.** `akibara-rewards/akibara-rewards.php`, `src/Support/Options.php`,
`src/Reviews/Schema.php`, `src/Reviews/Media.php`, `src/Points/Migration.php`,
`akibara-business/templates/myaccount/dashboard.php`.

**En qué momento actúa.** El motor de reseñas se enciende cuando **no** existe la clase
`CR_Reviews` del tercero (`Options.php:167`), consultado en `plugins_loaded`.
`rank_math/snippet/rich_snippet_product_entity:50` sustituye `aggregateRating` y `review[]`
por la forma exacta de CusRev; si el producto no tiene valoraciones, **borra** las dos claves
en vez de dejar la forma propia de Rank Math.

**Las funciones globales heredadas.** En `plugins_loaded:25`, y sólo si no existe ya la del
tercero, `akibara-rewards.php:205-218` declara en el espacio de nombres **global**
`spar_get_user_points()`. La plantilla del escritorio de Mi cuenta la consume con
`function_exists()` (`dashboard.php:73-74`) para decidir si pinta la tarjeta «Recompensas».
Es decir: quién pinta esa tarjeta depende de qué plugin ganó la carrera de declarar esa
función.

**Qué lee y escribe.** Migra desde `wp_spar_points_logs`; conserva **dos** metas del tercero,
`ivole_review_image2` (foto local) e `ivole_review_image` (foto remota) — el contador
`ivole_media_count` y las dos metas de vídeo se retiraron en rewards 0.4.17 por no tener lector
(corregido 2026-09-20, ronda 10); mantiene viva la ruta
antigua `/cusrev/{formId}` porque los enlaces del tercero ya salieron por correo a clientes
reales.

**Dónde lo opera el dueño.** Akibara → Puntos y Reseñas.

**Qué ve el cliente.** Las estrellas y reseñas de la ficha, y lo que Google muestra de ellas.

---

### 25. Jetpack y Cloudinary: dónde NO hay frontera

**Qué hace en una frase.** Cerrar el mapa diciendo también dónde no hay nada.

**Jetpack:** cero enganches en los cuatro plugins propios, los diecisiete mu-plugins y el
tema. Si alguien lo activa, nada de Akibara reacciona.

**Cloudinary:** queda un filtro de una línea,
`cloudinary_lazy_load_bypass_classes` en `FrontendCompatibility.php`, que añade `skip-lazy`
a la lista de clases que no se difieren, igual que hace LiteSpeed. Cloudinary **no está en
`plugins/`** (comprobado el 2026-09-14): el filtro no tiene quién lo dispare y está listado
para retirarlo desde akibara-business.

---

## Lo programado

En producción `DISABLE_WP_CRON` está activo: el reloj de WordPress **no** lo empuja el
tráfico, lo dispara Hostinger desde fuera cada cinco minutos. Las horas de abajo son la hora
de Chile, y son aproximadas por diseño: las dos acciones se programan a «ahora + 1 hora» la
primera vez y después se reprograman solas.

![Lo que corre solo en esta frontera](diagramas/18-fronteras-con-terceros-cron.mmd)

*Cómo se lee:* es una línea de tiempo. Cada sección es una frecuencia —el reloj, lo diario, lo
semanal, lo que no es periódico— y debajo, lo que ocurre en ella.

| Trabajo | Mecanismo | Frecuencia y hora Chile | Quién lo programa | Qué hace | Si corre dos veces |
| --- | --- | --- | --- | --- | --- |
| `akibara_bluex_log_retention_cleanup` | Action Scheduler, acción recurrente, grupo **`akibara-bluex`** (`akibara-bluex-log-retention.php:14`) | diaria; primera corrida a la hora siguiente de programarse, después a esa misma hora cada día | `akibara_bluex_schedule_log_retention` en `action_scheduler_init:20` (`akibara-bluex-log-retention.php:85-115`) | Borra filas de `wp_bluex_logs` con más de 2 días, en lotes de 1.000 | Inocuo: la segunda pasada no encuentra filas que borrar. El guard `as_has_scheduled_action` impide la doble programación |
| `akibara_bluex_integrity_weekly` | Action Scheduler, acción recurrente **sin grupo ninguno** — la llamada a `as_schedule_recurring_action()` no le pasa uno (`akibara-bluex-integrity-watch.php:140`), y por eso en WooCommerce → Estado → Acciones programadas hay que buscarla por su nombre y no por grupo, que es justo lo que la hace difícil de encontrar | semanal, **si llega a programarse** | cierre anónimo en `init:30` (`akibara-bluex-integrity-watch.php:132-144`) | Recorre el árbol de `bluex-for-woocommerce` buscando `include`/`require` de ficheros ausentes y avisa por correo | Inocuo para los datos, pero **manda el correo otra vez**: la alarma se repite a propósito mientras dure la rotura. Ojo: se programa sólo si `is_admin() \|\| wp_doing_cron()`, y en producción el disparo puede entrar por WP-CLI, donde `wp_doing_cron()` es false |
| Purga de LiteSpeed tras guardar descuentos | `add_action('shutdown', …, 99)` dentro de la misma petición | no es periódico: ocurre al guardar | `LscPurger::schedule_now` o `AdminPanel::handle_save` | Dispara `litespeed_purge_all`, que con `cdn-cloudflare_clear` propaga a Cloudflare | La propiedad `$scheduled` de `LscPurger` (`:66-79`) impide programarla dos veces en la misma petición |
| Retención del historial de Action Scheduler | filtro `action_scheduler_retention_period` | lo ejecuta el propio Action Scheduler en su barrido | `mu-plugins/akibara-as-retention.php:13-18` | Acota a 14 días las acciones completadas y canceladas | No aplica: es un filtro, no una tarea |

---

## Casos de uso

![Caso: el cliente paga con tarjeta](diagramas/18-fronteras-con-terceros-caso-pago-tarjeta.mmd)

*Cómo se lee:* cada columna es un programa y el tiempo baja. El recuadro amarillo marca dónde
cambia el importe; el azul, el único tramo donde no hay una línea de código propio.

### Caso 1 · Un cliente paga con tarjeta por Mercado Pago

**Actor:** cliente. **Empieza cuando** pulsa «Finalizar compra» en `/checkout/`.

1. El cliente abre `/checkout/`. `BlocksPage::is_checkout()` comprueba que la página contiene
   de verdad el bloque `woocommerce/checkout` (`BlocksPage.php:28`), no que la URL se llame
   así.
2. `RuntimeCompatibility::exclude_checkout_from_litespeed` añade `checkout` a las URI que
   LiteSpeed no reordena, y `FrontendCompatibility::add_litespeed_checkout_script_exclusions`
   saca de la combinación y del retardo los nueve fragmentos de Mercado Pago y su SDK
   (`RuntimeCompatibility.php`, `FrontendCompatibility.php`).
3. `dequeue_classic_city_select` retira los dos selectores de comuna heredados,
   `wc-city-select` y `bluex-blocks-city-select`, que aquí venían **duplicados**
   (`RuntimeCompatibility.php:239-249`). El desencolado de los scripts de Mercado Pago **no
   ocurre aquí**: ese corre sólo en `/carrito/` (`:158-160`), o sea que, para un cliente que
   pasó por el carrito antes de llegar al checkout, ya ocurrió; y para uno que entró directo
   al checkout, no ocurre nunca. Ver la corrección de la pieza 6.
4. Si Flow está instalado, `repair_flow_blocks_dependencies` le repone las dependencias que
   su build no declara; sin eso, su método de pago no aparece siquiera.
5. `CrossOriginScripts::mark_third_party` marca `crossorigin="anonymous"` los scripts de
   Mercado Pago, para que un error de JavaScript no llegue a Clarity como «Script error.»
   sin fichero ni línea.
6. El cliente escribe RUT, comuna y celular. `CheckoutFields::canonicalize_customer_comuna`
   reescribe la comuna a la forma exacta que acepta la API de Blue —una forma que sale del
   catálogo **del propio Blue**, cargado por `ComunaDirectory` (`:377-393`)— antes de
   recalcular el envío.
7. **Aquí cambia el importe, y no lo cambia ninguna pasarela.** Si el cliente canjea puntos,
   `Redemption::apply_fee` añade un cargo negativo en
   `woocommerce_cart_calculate_fees:20` (`Redemption.php:76` y `:251`). Si hay un pack,
   `BundleCouponEngine` fabrica al vuelo su cupón virtual y recalcula la contribución exacta
   de cada ISBN (`:95-99`). Si hay descuento por serie y la campaña no permite acumular,
   `CouponInteraction::maybe_invalidate` rechaza el cupón que el cliente hubiera escrito
   (`:31`).
8. `MarketingConsent::on_field_value` guarda en el pedido la prueba del consentimiento con el
   **texto exacto** que el cliente vio, leído del registro de campos de WooCommerce y no de
   las opciones de Brevo (`MarketingConsent.php:138`, `:312-340`).
9. `CheckoutTaxLabels` reescribe la etiqueta del impuesto a «IVA» en los totales; los montos
   no se tocan.
10. Mercado Pago procesa el cobro. **Ahí sí** no hay una sola línea de código propio.
11. Con el pedido creado, `woocommerce_store_api_checkout_order_processed` dispara **seis**
    callbacks propios, en este orden de prioridad:

    | Prioridad | Quién | Qué hace |
    | --- | --- | --- |
    | 10 | `PhoneValidation::normalize_on_store_api_order` (`:28-34`) | Deja el celular guardado en una sola forma |
    | 10 | `MarketingConsent::warn_if_proof_missing` (`:137`) | Si la prueba del consentimiento no quedó escrita, un aviso por pedido en los registros, con la causa |
    | 15 | `SeriesBundles\BundleCouponEngine::reconcile_store_api_order_snapshot` (`:106`) | Cuadra en el pedido la foto exacta del pack, para que un recálculo administrativo no la invente |
    | 20 | `OrderPreventa\OrderTagging::on_store_api_order_processed` (`:61-66`) | El «etiquetado»: marca el pedido como preventa, a pedido o disponible, que es de lo que dependen después el correo «ya llegó» y la pantalla «Por llegar» |
    | 20 | `SeriesBundles\OrderInstrumentation::stamp_order_store_api` (`:37`) | La «instrumentación»: sella en el pedido qué pack se aplicó y cómo, para poder auditarlo meses después |
    | 20 | `akibara-rewards\Points\Redemption::capture_from_order` (`:78`) | Descuenta del libro de puntos lo que el cliente acaba de canjear |
12. WooCommerce manda el correo. `MailFrom` lo firma como `contacto@akibara.cl`, el
    mu-plugin del transporte lo entrega por SMTP de Hostinger y `EmailBrand` añade el botón
    «Ver mi pedido».
13. En el navegador, `clarity-commerce-events.js` emite `purchase` y fuerza
    `clarity('upgrade','purchase')` (`:436-438`).

**Bifurcaciones.**

- Si LiteSpeed tuviera el combinado encendido, la baranda lo devuelve a `false` en la lectura
  y los scripts de pago llegan enteros igual.
- Si el host de un script no está en la lista verificada, **no** se marca con `crossorigin`:
  marcarlo lo bloquearía y la tienda se quedaría sin cobrar.
- Si falta la prueba de consentimiento (el campo no está registrado en WooCommerce), se escribe
  un aviso por pedido en los registros y el pedido sigue adelante.
- Si el cliente recarga la página de gracias, `purchase` se emite otra vez: el deduplicador
  vive en memoria y una recarga lo reinicia.

**Correos que salen.**

- «Pedido recibido» / «Tu pedido está en proceso» (correos de WooCommerce), inmediatos, con
  el botón «Ver mi pedido» al pie del detalle y el RUT entre los datos del pedido.

---

### Caso 2 · El cliente elige transferencia bancaria

**Actor:** cliente. **Empieza cuando** selecciona «Transferencia bancaria directa».

1. WooCommerce crea el pedido en estado «en espera» y pinta los datos de la cuenta desde la
   option `woocommerce_bacs_accounts`.
2. El núcleo llama a `woocommerce_bacs_account_fields`;
   `akibara_gp_bacs_account_field_labels` (`themes/akibara-gp/inc/woocommerce.php:470-482`)
   renombra `sort_code`→«Tipo de cuenta», `iban`→«RUT» y `bic`→«Email», porque «Sort code»,
   «IBAN» y «BIC» no significan nada en Chile.
3. Los mismos rótulos salen en la pantalla de gracias y en el correo, porque los dos pasan
   por el mismo filtro.
4. Si el envío es un retiro propio, `PickupCoordination::pintar_en_correo` añade en
   `woocommerce_email_after_order_table:20` el bloque que explica cómo se coordina el retiro
   y con qué número de pedido escribir, con el teléfono leído de la option `joinchat`.
5. Cuando el dueño confirma la transferencia y pasa el pedido a «procesando», se disparan el
   abono de puntos, la invitación a reseña y, si la línea de envío es de un transportista, el
   monitor de entrega.

**Bifurcaciones.**

- El bloque de coordinación **no** se pinta en el correo al administrador, ni en despacho a
  domicilio, ni en retiro en Punto Blue Express, que es otro flujo
  (`PickupCoordination.php:46` y `:72`).
- La detección mira el método **real** de la línea de envío (`local_pickup` /
  `pickup_location`), nunca el título, porque el título lo edita el dueño.

**Correos que salen.**

- «Pedido en espera» (WooCommerce), inmediato, con el bloque «Cómo es el retiro» cuando
  corresponde.

---

![Caso: retiro en Punto Blue Express](diagramas/18-fronteras-con-terceros-caso-pudo.mmd)

*Cómo se lee:* cada columna es un programa y el tiempo baja. Del navegador sólo entra el
identificador de la agencia: todo lo demás lo vuelve a preguntar el servidor.

### Caso 3 · Retiro en Punto Blue Express, de punta a punta

**Actor:** cliente. **Empieza cuando** selecciona «Retiro en Punto Blue Express».

1. Akibara ya retiró el selector del proveedor y cargó el suyo
   (`PudoIntegration::dequeue_vendor_pudo_assets`).
2. El cliente elige una agencia. El navegador manda al Store API, bajo el namespace
   `bluex_pudo`, **sólo cuatro claves**: `agency_id`, `address`, `region_code` y
   `attempt_id`. Cualquier otra se rechaza con `akibara_pudo_untrusted_payload`
   (`PudoIntegration.php:443-451`); el `attempt_id` debe ser 32 caracteres hexadecimales en
   minúscula (`:463`).
3. `handle_store_api_update` exige que PUDO sea el método elegido y que haya **un solo**
   paquete.
4. Saca del paquete la comuna y la región calculadas **por el servidor**, y sólo si el país
   es CL y la región casa con `/^CL-[A-Z]{2}$/`.
5. `PudoCheckoutService::select_agency` toma un **candado con caducidad** —60 segundos— para
   que dos pestañas del mismo cliente no se pisen eligiendo agencias distintas a la vez, y
   llama a `PickupPointProvider::resolve()`. El candado lo lleva una sola clase,
   `PudoMutationGuard`, y se suelta de tres maneras: al confirmar la selección
   (`commit_selection`), al fallar (`fail_selection`) o solo, cuando caduca. **Nadie tiene
   que acordarse de soltarlo**: si el servidor se muere en medio, el candado expira por su
   cuenta (`PudoMutationGuard.php:22-23`).
6. `resolve()` pregunta a `BlueX_API_Client::get_geolocation()`. Exige confianza ≥ 80%, país
   CL, que la región del proveedor coincida con la del servidor **por dos caminos** (código
   numérico y nombre) y que el id de agencia devuelto sea el pedido
   (`PickupPointProvider.php:358` y `:381`).
7. `quote()` cotiza con `get_pricing()` reproduciendo exactamente cómo el plugin de Blue arma
   el bulto: dimensiones truncadas a entero con mínimo 10, peso mínimo 0,01, valor declarado
   igual a la suma de precios por cantidad menos el descuento del carrito. Acepta los códigos
   `'00'` y `'01'` (`:288`).
8. Exige coherencia: si el total es 0, el envío tiene que venir marcado gratis, y al revés
   (`:305-310`).
9. La selección se guarda con una huella `sha256` del carrito. Si el carrito cambia, la
   huella cambia y la selección se descarta sola.
10. `woocommerce_package_rates:999` retira la tarifa `bluex-pudo` si no coincide con la
    cotización guardada: exige el mismo id de agencia y que el coste no difiera en más de
    0,01 (`PudoCheckoutService.php:515-527`).
11. El cliente pulsa pagar. `rewrite_checkout_request` corre a **prioridad 1**, antes que el
    plugin de Blue: revalida todo, toma el **mismo tipo de candado pero para el pago** —30
    segundos esta vez, `PudoMutationGuard.php:23`—, escribe la dirección del punto y las
    metas del pedido, y reescribe las extensiones de la petición con los valores canónicos
    (`PudoIntegration.php:285`). Las metas se escriben en
    `persist_canonical_order_selection()` (`:741-748`): `agencyId` siempre, `isPudoSelected`
    siempre —y su valor **no** es un sí/no: es la cadena literal `'pudoShipping'` (`:742`),
    dato que importa si alguien consulta esa meta creyendo que guarda un booleano—, y
    `agencyName` y `agencyAddress` **sólo si no vienen vacías** (`:744`, `:747`).
12. Pase lo que pase —se cierre el pedido o falle el pago—, el bloque `finally` suelta ese
    candado de 30 segundos. Y si algo impidiera ejecutar ese bloque, el candado caduca solo.

**Bifurcaciones.**

- Si otra petición reemplazó la selección mientras esta estaba en vuelo, se responde 409 con
  «Otra selección de punto de retiro reemplazó esta solicitud».
- Si el punto o su tarifa ya no están, se limpia la selección y se pide elegirlo de nuevo:
  **nunca** se cierra el pedido con un dato del navegador.
- Si `agency_address` viene vacío, la selección se invalida entera: con un valor vacío el
  escritor del proveedor caía a su copia en sesión, que el comprador podía sembrar por el
  AJAX clásico.
- Si el catálogo de comunas de Blue no se puede cargar, la canonicalización desaparece y el
  campo vuelve a ser texto libre; la cotización empieza a fallar sin un solo error, y el
  vigía de integridad no lo ve porque ese `require` es nuestro, no del tercero.

**Correos que salen:** ninguno.

---

### Caso 4 · Blue Express devuelve el número de seguimiento

**Actor:** sistema. **Empieza cuando** Blue Express actualiza el pedido llamando a la API
autenticada `wc/v3`.

1. El plugin de Blue registró en su día un campo REST `correios_tracking_code` con un
   callback escrito para la API **legada**, que espera un `WP_Post`.
2. La API moderna entrega un `WC_Order`, así que ese callback no guardaba nada.
3. `akibara_bluex_register_rest_tracking_compatibility` (`rest_api_init:101`) sustituye ese
   `update_callback` por uno propio (`akibara-bluex-rest-tracking.php:58`).
4. `akibara_bluex_store_tracking_codes` parte el valor por comas, sanea cada código y lo
   guarda con la propia función del proveedor, `wc_correios_updateTrackingCode()`.
5. Además, `woocommerce_rest_insert_shop_order_object:20` vuelve a leer el parámetro de la
   petición y hace lo mismo. **Corrección al mapa:** esto no es condicional —no comprueba si
   el paso 4 ya ocurrió—; la única guarda es que `$request` sea un `WP_REST_Request`
   (`:75-84`). Que el código no quede duplicado depende de que la función del tercero
   deduplique, y eso no está escrito ni probado en ninguna parte.
6. Si el pedido es una preventa y el monitor de entrega había agendado una comprobación, al
   correr a las 2 horas encuentra ya el seguimiento y no molesta a nadie.

**Bifurcaciones.**

- Si no existe `wc_correios_updateTrackingCode` (plugin desactivado), no se guarda nada y no
  se rompe nada.
- Un valor vacío o que no sea cadena se ignora.

**Correos que salen:** ninguno.

---

![Caso: Blue Express embarca una actualización rota](diagramas/18-fronteras-con-terceros-caso-integridad-bluex.mmd)

*Cómo se lee:* cada columna es un programa y el tiempo baja. El recuadro rojo marca el hueco
del que habla el paso 4: las marcas se escriben antes de mandar el correo y sin mirar si
salió.

### Caso 5 · Blue Express embarca una actualización rota

**Actor:** sistema. **Empieza cuando** WordPress termina de actualizar cualquier plugin.

1. El mu-plugin comprueba que el tipo de actualización es `plugin` y llama a
   `akibara_bluex_integrity_check('post-actualización')`.
2. `akibara_bluex_broken_includes()` recorre todo el árbol de `bluex-for-woocommerce` y busca
   cada `include`/`require` resoluble estáticamente; las rutas con variables se descartan
   porque no se pueden resolver sin ejecutar.
3. Para cada uno prueba dos rutas candidatas y anota los que no existen, con fichero y línea.
4. Si hay alguno: escribe una línea en el `error_log`, marca
   `akb_bluex_watch_baseline_seen`, borra `akb_bluex_watch_resolved_notified` y manda un
   correo (`:86-101`). **Las dos options se escriben antes del `wp_mail` y sin mirar su
   resultado.**
5. La alarma se **repite** mientras dure la rotura, deliberadamente: desde el 2026-09-01 no
   hay guard amortiguando, así que cualquier carga rota es riesgo inmediato del checkout.
6. Cuando Blue arregla su paquete, el siguiente barrido manda una sola vez la buena noticia
   (`:106-115`).

**Bifurcaciones.**

- Si el directorio del plugin no existe, no hay nada que vigilar y no se avisa.
- El respaldo semanal existe porque el aviso `upgrader_process_complete` sólo llega cuando es
  **el propio WordPress** quien instala o actualiza el plugin, desde su pantalla de plugins o
  desde las actualizaciones automáticas. Hostinger a veces reemplaza los ficheros por fuera
  —desde su panel o por despliegue—, y entonces ese aviso **no se dispara**: el vigía no se
  entera de nada. Para ese caso está el barrido semanal. Con un pero: sólo se programa cuando
  alguien abre `/wp-admin/` o cuando corre un cron que se identifique como tal, y en
  producción el disparo entra por WP-CLI, donde `wp_doing_cron()` es `false`.
- Si el `admin_email` está vacío, no se manda nada y la marca queda escrita igual: el correo
  de «ya está arreglado» podría llegar sin que nadie recibiera el de la rotura.

**Correos que salen.**

- «[Akibara] ⚠ Blue Express embarcó includes rotos — checkout en riesgo», inmediato tras la
  actualización, y de nuevo en cada barrido mientras siga roto.
- «[Akibara] ✅ Blue Express arregló su paquete», una sola vez, en el primer barrido limpio.

---

![Estados del transporte de correo](diagramas/18-fronteras-con-terceros-estados-correo.mmd)

*Cómo se lee:* esto no es una secuencia, es un mapa de **estados**: cada caja es una situación
en la que puede estar el correo de la tienda, y cada flecha, el suceso que lo mueve de una a
otra.

### Caso 6 · El correo de la tienda deja de salir

**Actor:** sistema. **Empieza cuando** el fichero del transporte desaparece del servidor, o
la contraseña del buzón se rota.

1. **Caso A — el fichero desaparece.** En la siguiente entrada al escritorio (`admin_init:1`)
   o en el siguiente disparo del cron (`init:1` con `DOING_CRON`), `Watch::inspect`
   comprueba dos cosas: que la clase del transporte esté cargada y que su método siga
   enganchado a `phpmailer_init`.
2. `Watch::restore_plan` decide sin tocar el disco: si el fichero **no** está y hay copia
   legible → copiar; si el fichero está pero la clase no cargó → «presente pero inerte» y
   **no se pisa**, porque podría ser un fatal dentro del fichero o una versión más nueva que
   la copia.
3. Si copia, anota la reposición en el historial (hasta 10) y pinta un aviso rojo no
   descartable; cuando el transporte vuelve, el aviso cambia a ámbar y recuerda mirar
   hPanel → Seguridad → Malware scanner.
4. **Caso B — la contraseña está rotada.** El perfil sigue validando (sólo se exige que no
   esté vacía), así que el guard deja pasar y el fallo ocurre dentro del envío.
5. Ese fallo dispara `wp_mail_failed` y `Transport::record_send_failure` anota la causa
   recortada a 140 caracteres con `kind='send_failed'`.
6. El aviso rojo **no** aparece al primer rechazo: hay que llegar a **tres rechazos seguidos
   sin un solo envío correcto de por medio**. «Seguidos» es literal: cualquier correo que
   salga bien dispara `wp_mail_succeeded` y borra el rastro entero, contador incluido
   (`akibara-correo-smtp.php:36-44` y `:70`). O sea que llegar a tres ya significa que no
   está saliendo nada, no que hubo tres fallos sueltos alguna vez. La razón de no gritar al
   primero: un rechazo suelto casi siempre es una dirección de cliente mal escrita, y gritar
   por eso enseña a ignorar la alarma.
7. El rastro se borra sólo cuando un correo **sale de verdad** (`wp_mail_succeeded`), no
   cuando PHPMailer se construye: hasta la versión 1.1.0 la sonda de salud del despliegue
   borraba la evidencia sin haber enviado nada.

**Bifurcaciones.**

- Un corte por configuración (`kind='blocked'`) es estructural y se grita desde el primero.
- El inspector corre una sola vez por petición: una segunda pasada vería el fichero recién
  repuesto, la clase seguiría sin cargar y pisaría el desenlace real.
- En un entorno que no sea `local` ni `production`, `validate_configuration()` devuelve
  `null` (`:339-354`), el guard corta y el filtro donde vive la redirección de QA no llega a
  correr.

**Correos que salen:** ninguno. El correo no puede avisar de que el correo está roto; la
señal son los dos avisos del escritorio.

---

### Caso 7 · Brevo manda el carrito abandonado con la portada visible

**Actor:** cliente. **Empieza cuando** añade un tomo al carrito y se va sin comprar.

1. `woocommerce_add_to_cart` se dispara. A prioridad 9,
   `akibara_brevo_abrir_ventana_jpg` engancha el filtro `wp_get_attachment_image_src`.
2. A prioridad 10 corre el oyente de Brevo, que arma el evento de carrito pidiendo la URL de
   la imagen de cada ítem.
3. Dentro de esa ventana, `akibara_brevo_portada_en_jpg` reconoce las portadas
   `/uploads/portadas/*.avif` y las cambia por su hermana `-og.jpg`, escribiéndola en el acto
   con `SocialImageDerivatives::ensure_now()` si todavía no existe.
4. Tiene que ser en el acto porque Brevo guarda la URL de **este** instante y el correo sale
   horas después.
5. A prioridad 11, `akibara_brevo_cerrar_ventana_jpg` quita el filtro y el resto del sitio
   vuelve a servir AVIF.
6. Horas después, Brevo manda su correo con una imagen que Gmail sí pinta.

**Bifurcaciones.**

- Si no se puede escribir la JPG (AVIF ilegible, directorio sin permiso), la URL no se toca:
  un AVIF que quizá no se vea es mejor que un enlace roto seguro.
- Si el plugin de Brevo o el módulo de derivados no están cargados, no se engancha nada.
- La conversión ocurre **como mucho una vez por tomo**: `ensure_now()` sale antes con dos
  `filemtime()` si la hermana está al día, y un transient de fallo por slug corta los
  reintentos (`SocialImageDerivatives.php:350-360`).

**Correos que salen.**

- «Carrito abandonado», lo manda Brevo desde su plataforma, horas después, según la
  automatización que el dueño tenga configurada allí.

---

### Caso 8 · Google rastrea una ficha y Akibara le corrige a Rank Math lo que publica

**Actor:** sistema. **Empieza cuando** un rastreador pide `/nombre-del-tomo/`.

1. Rank Math construye el `@graph`: un nodo Product con su oferta, un WebPage y un
   BreadcrumbList.
2. A prioridad 20, `SchemaProductRankMath::augment_entity` enriquece el producto con la
   semántica editorial (Book, serie, GTIN) y la preventa; a 50, `Reviews\Schema::inject`
   sustituye `aggregateRating` y `review[]` por la forma exacta del plugin retirado.
3. Sobre `rank_math/json_ld` corren luego las **seis etapas** que fija `SchemaGraph`, con
   **diez** callbacks repartidos entre ellas (el desglose completo, en la pieza 13): 90
   oferta y políticas de envío y devolución, 91 colección de serie, 92 adjuntos de página
   —cuatro callbacks comparten este turno—, 99 rutas propias —dos—, 100 relaciones
   Book↔Series, 999 higiene final. Antes de todas ellas, a prioridad 20, `akibara-search`
   reescribe el `potentialAction` de búsqueda del sitio (`facets-render.php:833`): es el
   único que toca el grafo fuera del contrato.
4. En paralelo, `rank_math/frontend/robots` y `/canonical` deciden si esta URL se indexa.
5. Para las redes, `SeoSocial` cambia el `og:type` y sustituye la imagen AVIF por su hermana
   JPG, corrigiendo a la vez la URL y el `og:image:type`.

**Bifurcaciones.**

- Si el producto no tiene valoraciones, se **borran** `aggregateRating` y `review[]` en vez
  de dejar la forma propia de Rank Math: publicar una forma ajena a la que se replica sería
  inventar.
- Si no existe la hermana JPG, se conserva el AVIF.
- Akibara nunca emite un segundo bloque JSON-LD. Pero sí llama directamente a cuatro puntos
  de la API interna de Rank Math (pieza 13), que es la excepción a «sólo por hooks».

**Correos que salen:** ninguno.

---

### Caso 9 · El dueño cambia un porcentaje de descuento por serie

**Actor:** dueño. **Empieza cuando** guarda un porcentaje en Akibara → Descuentos, o alguien
lo cambia por línea de comandos.

1. **Si el cambio es una option** (el interruptor de la campaña, el tope de descuento):
   WordPress dispara `update_option_<nombre>`, `add_option_` o `delete_option_`. `LscPurger`
   escucha el conjunto **cerrado** de `Settings::OPTIONS` más `DiscountCeiling::OPTION`
   (`LscPurger.php:49-66`). La rama de `delete_option_` existe porque la madrugada del
   2026-09-06 alguien creó y borró el tope en producción.
2. `LscPurger::schedule_now` mira si el panel ya va a purgar: lo decide con
   `isset($_POST['akibara_panel_save'])` (`:95-98`). Si el guardado viene del formulario,
   se abstiene.
3. El panel, por su parte, programa su purga sólo si `should_purge_after_save($tab,
   $cambios)` devuelve verdadero, y ese método devuelve `false` cuando `$cambios < 1`
   (`AdminPanel.php:295-301`, `:603-612`).
4. La purga, cuando ocurre, va en `shutdown:99` y dispara `litespeed_purge_all`. Como
   LiteSpeed corre con `cdn-cloudflare_clear`, esa purga total propaga a Cloudflare y devuelve
   al origen las ~1.568 fichas, las 106 landings de serie, la portada y el blog. Medido el
   2026-08-22: una landing pasa de 48 a 1.057 ms hasta recalentarse. Por eso no es barata y
   por eso no se hace dos veces.

**La bifurcación que importa, y que el mapa contaba al revés.** El porcentaje por serie no es
una option: es **termmeta**, escrita con `update_term_meta`/`delete_term_meta`
(`TaxonomyDiscount/AdminFieldType.php:140-148`, `Settings.php:652-664`). `$cambios` sólo
cuenta `updated_option` y `added_option`. Entonces, en el caso más común —el dueño cambia
sólo un porcentaje y guarda—:

- `$cambios` queda en 0, así que **el panel no purga**;
- `LscPurger` sí escucha `akibara_taxonomy_discount_pct_saved`, pero ve
  `$_POST['akibara_panel_save']` y **se abstiene** creyendo que el panel se encarga.

Resultado: el dueño baja un descuento del 20% al 10%, ve el aviso verde de guardado, y las
fichas siguen sirviendo el precio de antes hasta que algo más purgue.

**El otro camino.** Un `wp option update` desde la línea de comandos no lleva
`akibara_panel_save`, así que ahí `LscPurger` sí purga. El buscador, por su parte, **no**
escala a purga total: cambia la generación de su índice y se apoya en el `no-store` de borde,
porque su purga cuelga de cada escritura viva —o sea, de cada venta o cambio de stock—.

**Correos que salen:** ninguno.

---

### Caso 10 · QA prueba en el espejo y ningún cliente real recibe nada

**Actor:** sistema. **Empieza cuando** cualquier `wp_mail()` en un entorno que no es
producción.

1. `wp_mail` arranca y el primer filtro que corre es `pre_wp_mail`, donde está el guard del
   transporte. En un entorno `local` con el transporte deshabilitado, el guard deja pasar sin
   tocar nada (`:97-113`).
2. Después corre el filtro `wp_mail`, donde `akibara_correo_no_sale_a_clientes` (prioridad
   `PHP_INT_MAX`) mira `wp_get_environment_type()`.
3. Si no es producción, separa los destinatarios por `preg_split('/,\s*/', ...)` (`:110`),
   descarta los que ya son de prueba —`.test`, `.example`, `.invalid`, `.localhost`,
   `example.com`, `example.org` (`:68-81`)— y, si queda alguno real, cambia el destinatario
   por `capturado@akibara.test`.
4. Conserva el original en **dos** sitios: al principio del asunto y en la cabecera
   `X-Akibara-Destinatario-Original` (`:126-133`). En el asunto porque es lo único que se ve
   en la lista de la bandeja sin abrir el correo.
5. El espejo tiene 84 direcciones reales de clientes y 69 cuentas con correo real: basta
   conectar un SMTP de verdad para que 84 personas reciban correo de una prueba.

**Bifurcaciones.**

- No **bloquea** el correo, lo **redirige**: un guardarraíl que impide comprobar lo único que
  importa de un correo —que llega y qué dice— se acaba apagando.
- El destino por defecto está en un TLD reservado, así que aunque alguien conecte un SMTP
  real no hay ningún buzón al que molestar.
- En producción no hace absolutamente nada.
- Un destinatario escrito como `"Apellido, Nombre <a@b.cl>"` se parte en dos por la coma. El
  trozo `"Apellido` no tiene dominio, `akibara_correo_es_de_pruebas()` devuelve `false`
  (`:68-72`) y ese trozo entra en la lista de «reales»: la evidencia sale ensuciada y el
  asunto acaba llevando texto controlado por el pedido.

**Correos que salen.** Todos los del sitio, redirigidos, con el asunto prefijado.

---

## Reglas de negocio

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
| --- | --- | --- | --- |
| Los métodos de Blue que cotizan contra su API no están disponibles sin región **y** comuna | `mu-plugins/akibara-bluex-cotiza-solo-con-comuna.php:52-63` | no | En código |
| `bluex-pudo` queda fuera de esa regla: ofrece tarifa fija sin dirección | `mu-plugins/akibara-bluex-cotiza-solo-con-comuna.php:41-43` | no | En código |
| El retiro en punto no está disponible si el carrito se parte en más de un envío | `Pudo/PudoCheckoutService.php:421`, `PickupPointProvider.php:214` | no | En código |
| Una agencia sólo se acepta con confianza ≥ 80%, país CL, región coincidente por código y por nombre, e id devuelto igual al pedido | `Pudo/PickupPointProvider.php:358-386` | no | En código |
| Una cotización se rechaza si el total y el indicador de gratis se contradicen | `Pudo/PickupPointProvider.php:305-310` | no | En código |
| Sólo se aceptan los códigos `'00'` y `'01'` de la API de precios de Blue | `Pudo/PickupPointProvider.php:288` | no | En código |
| La tarifa PUDO se retira si difiere en más de 0,01 de la cotizada | `Pudo/PudoCheckoutService.php:515-527` | no | En código |
| Del navegador sólo se aceptan cuatro claves para elegir un punto | `PudoIntegration.php:443-451` | no | En código |
| El `attempt_id` debe ser 32 caracteres hexadecimales en minúscula | `PudoIntegration.php:463` | no | En código |
| La forma canónica de las comunas es la del catálogo de Blue Express, cargado por ruta fija; sin él el checkout **falla abierto** | `ComunaDirectory.php:26`, `:377-393` | no | En código |
| El envío gratis a domicilio lo concede Blue dentro de su tarifa, no una instancia core | `Services/FreeShippingThreshold.php:54-62` | sí | Akibara → panel, `akibara_fsp_carrier_threshold` (0 = apagado) |
| El piso visual del operador nunca rebaja el mínimo real: se aplica `max()` | `Services/FreeShippingThreshold.php:497` | sí | Akibara → panel, `akibara_fsp_threshold` |
| Con varios paquetes, la barra muestra el cuello de botella | `Services/FreeShippingThreshold.php:411-416` | no | En código |
| Retención de `wp_bluex_logs`: 2 días, lotes de 1.000 | `mu-plugins/akibara-bluex-log-retention.php:15-16` | no | En código |
| Retención de Action Scheduler: 14 días para completadas y canceladas | `mu-plugins/akibara-as-retention.php:13-18` | no | En código |
| Las rutas `/wc-bluex/v1` exigen `manage_woocommerce` y no se almacenan en navegador ni CDN | `Modules/Security/Security.php:196-252` | no | En código |
| Seis acciones AJAX de Blue exigen `manage_woocommerce`; `clear_shipping_cache` queda fuera a propósito | `Modules/Security/Security.php:44-56`, `:260` | no | En código |
| `/wp/v2/users` y `?author=N` se cierran para quien no puede listar usuarios | `Modules/Security/Security.php:64-70` | no | En código |
| «Olvidé mi contraseña» siempre responde lo mismo, exista o no el correo | `Modules/Security/Security.php:405-450` | no | En código |
| El transporte SMTP sólo valida en entorno `local` o `production`; cualquier otro corta el correo | `mu-plugins/akibara-correo-smtp.php:339-354` | no | Constantes de `wp-config.php` |
| El aviso de rechazo SMTP se muestra desde el tercer rechazo **seguido** —cualquier envío correcto pone el contador a cero—; un corte por configuración, desde el primero | `mu-plugins/akibara-correo-smtp.php:44`, `:70`, `:245` | no | En código |
| Todo `wp_mail` sale como «Akibara \<contacto@akibara.cl\>» | `Modules/MailFrom/MailFrom.php:44-51` | sí | Filtros `akibara_mail_from_email` / `_name` |
| Fuera de producción el correo se redirige a `capturado@akibara.test` | `mu-plugins/akibara-correo-no-sale-a-clientes.php:104-139` | sí | Constante `AKIBARA_CORREO_CAPTURA` |
| El correo nativo «tu pedido está completo» se suprime en pedidos de preventa | `OrderPreventa/ReadyEmailDispatcher.php:82` | no | En código |
| El pie del correo convierte `<br>` en salto de línea antes de que WooCommerce quite las etiquetas | `themes/akibara-gp/inc/woocommerce.php:685` | no | En código |
| El correo de reembolso va en voz activa: «Te reembolsamos tu pedido #X» | `themes/akibara-gp/inc/woocommerce.php:648-659` | no | En código |
| Un secreto de servidor no tiene camino desde la base de datos | `mu-plugins/akibara-provider-access.php:265-270` | no | En código |
| El catálogo se niega a devolver el valor de una clave cuyo consumidor es otro fichero | `mu-plugins/akibara-provider-access.php:295-304` | no | En código |
| La clave de Google Maps se rechaza si no casa con `/^AIza[0-9A-Za-z_-]{35}$/`, al guardar **y** al leer | `AddressAutocomplete.php:71-80`, `akibara-provider-access.php:277-283` | no | En código |
| El autocompletado del núcleo se enciende sólo en el checkout de bloques | `AddressIntegration.php:87-92` | sí | Akibara → Dirección |
| «Continuar con Google» vuelve al destino saneado del muro, no a la URL actual | `GoogleLoginButton.php:31`, `:59-63` | no | En código |
| El campo `redirect` sin validar de WooCommerce Blocks se desengancha del formulario de acceso | `GoogleLoginButton.php:84-103` | no | En código |
| Sólo se marcan con `crossorigin` seis hosts verificados; `googleads.g.doubleclick.net` queda fuera | `ScriptDiagnostics/CrossOriginScripts.php:36-37`, `:60-67` | sí | Filtro `akb_crossorigin_verified_hosts`; option `akb_crossorigin_scripts_enabled` **sin pantalla** |
| Clarity se apaga fuera de producción; los eventos exigen además el host canónico | `mu-plugins/akibara-clarity-production-only.php:18-25`, `FrontendCompatibility.php:44-46`, `:202-210` | no | En código |
| Ese mismo gate del host se apoya en `HTTP_HOST`, que no existe en cron ni en WP-CLI: allí responde «no es producción» aunque el sitio sea el real, y con él se apaga también el seguimiento de Google Ads | `FrontendCompatibility.php:202-210`, `:246` | no | En código |
| A Clarity nunca viajan URL, consulta, pedido ni cliente: sólo nombres de evento y dos etiquetas de fuente de IA | `assets/js/clarity-commerce-events.js:1-8`, `:294-319`, `:370-380` | no | En código |
| Las fuentes de IA reconocidas son cinco, en lista cerrada | `assets/js/clarity-commerce-events.js:23-42` | no | En código |
| Fuera de producción no se instala GA4, se vacía el `ga_id` y se desactiva Ads | `FrontendCompatibility.php:218-247` | no | En código |
| La declaración `adult:true` del feed es por serie y la decide el dueño | `ProductSchemaShopping/AdultContentFeed.php:20-28`, `:57` | sí | Termmeta `_akb_adult_content` de `pa_serie` |
| Cinco ajustes de LiteSpeed quedan fijados a `false` en la **lectura** (`optm-css_comb`, `optm-js_comb`, `guest_optm`, `media-lazy`, `cache-mobile`) | `mu-plugins/akibara-litespeed-guardrails.php:37-141`, `:233-239` | no | En código |
| Las dos rutas de API se añaden siempre a la exclusión de caché… si el valor guardado llega como array | `mu-plugins/akibara-litespeed-guardrails.php:168-185` | no | En código |
| Ninguna respuesta REST del buscador es públicamente almacenable | `akibara-search/src/Support/RestCache.php:105-115` | no | En código |
| `CF-Connecting-IP` sólo se acepta si el par TCP está en un rango publicado de Cloudflare | `Utilities/RateLimit.php:290-310` | sí | Filtro `akibara_trusted_proxy_ranges` |
| Los sitemaps: `no-store` para el navegador, 4 horas para el CDN | `mu-plugins/akibara-discovery-warming.php:35-39` | no | En código |
| **Tres** abilities quedan vetadas en su registro —dos de Rank Math (`:27-28`) y `woocommerce/product-delete` (`:37`, decisión del dueño del 2026-09-13)—; la primera capa anota el intento y devuelve `false`, y la segunda es **condicional** a que el tercero declare `meta.mcp` como array | `mu-plugins/akibara-abilities-denylist.php:26-38`, `:51-63`, `:65-66` | no | En código (corregido 2026-09-20, ronda 10: decía «dos … de Rank Math») |
| Las escrituras de redirecciones por MCP exigen IDs explícitos, máximo 500, `expected_count` y `dry_run` | `mu-plugins/akibara-rankmath-redirections-abilities.php:17`, `:88-157` | no | En código |
| El orden del `@graph` es un contrato: seis etapas con nombre, diez callbacks de siete clases repartidos entre ellas (el docblock del propio fichero dice «nueve» y se quedó corto) | `Utilities/SchemaGraph.php:48-63` | no | En código |
| `akibara-search` toca el `@graph` a prioridad 20, fuera de ese contrato, porque viaja en otro riel y no puede depender de `akibara-business` | `akibara-search/frontend/facets-render.php:833` | no | En código |
| Sin valoraciones se **borran** `aggregateRating` y `review[]` | `akibara-rewards/src/Reviews/Schema.php:73-77` | no | En código |
| El motor propio de reseñas duerme mientras exista `CR_Reviews` | `akibara-rewards/src/Support/Options.php:167` | sí | Filtro `akb_rewards_reviews_engine_on` |
| La etiqueta de impuesto es siempre «IVA»; los montos no se tocan | `Modules/CheckoutTaxLabels/CheckoutTaxLabels.php:40-83` | no | En código |
| Los campos europeos de la transferencia se rotulan Tipo de cuenta / RUT / Email | `themes/akibara-gp/inc/woocommerce.php:470-482` | sí | Los **valores** en WooCommerce → Ajustes → Pagos |
| El bloque de coordinación de retiro se pinta sólo para `local_pickup` y `pickup_location` | `Modules/PickupCoordination/PickupCoordination.php:46`, `:72` | no | En código |
| El teléfono de WhatsApp sale de la option `joinchat`, clave `telephone`, con `+56944242844` de respaldo escrito en el código | `Modules/PickupCoordination/PickupCoordination.php:162-168` | sí | Pantalla de Joinchat |
| La vinculación automática de pedidos de invitado al darse de alta con Google **no se usa**: la palanca del tercero existe y está deliberadamente sin enganchar | `Modules/GuestOrderLinking/GuestOrderLinking.php:54` (clase vacía a propósito, con la explicación) | no | En código |
| La prueba de consentimiento guarda el texto **opcional** de la casilla, que es el que se pinta | `Modules/MarketingConsent/MarketingConsent.php:312-340` | sí | Filtro `akibara_marketing_consent_field_key` |
| El canje de puntos se aplica como cargo negativo a prioridad 20 del cálculo de cargos | `akibara-rewards/src/Points/Redemption.php:76`, `:251` | sí | Akibara → Puntos |
| Un cupón se invalida si ya hay descuento por taxonomía y la campaña no acumula; los virtuales internos quedan exentos por prefijo | `TaxonomyDiscount/CouponInteraction.php:31-58` | sí | Akibara → Descuentos |
| El alta de preventa y el despacho real de Blue se bloquean en un sitio local | `Integrations/Bluex/OrderPreventaAdapter.php:34-37`, `:136-153` | no | En código |

---

## Datos que deja

| Tipo | Nombre | Quién lo escribe | Qué guarda |
| --- | --- | --- | --- |
| option | `akb_bluex_watch_baseline_seen` | `akibara-bluex-integrity-watch.php:90` | `'yes'` una vez que se vio al menos una rotura. Sin autocarga |
| option | `akb_bluex_watch_resolved_notified` | `akibara-bluex-integrity-watch.php:108` | `'yes'` cuando ya se avisó que Blue lo arregló; se borra al detectar rotura nueva |
| option | `akibara_mail_transport_outage` | `akibara-correo-smtp.php` | `kind` (`blocked`\|`send_failed`), `reason`, `first_gmt`, `last_gmt`, `count` |
| option | `akibara_mail_transport_watch` | `akibara-mail-transport-watch.php` | `missing_since`, `last_seen_gmt`, `outcome` y hasta 10 reposiciones |
| option | `akb_blocks_mp_dequeue_observed` | `RuntimeCompatibility.php:205` | `retirados` (int) y `at`; se reescribe **sólo** cuando el número cambia. El Centro de Mando lo pinta como «(2 datos)» para siempre, porque cuenta las claves del array (`AdminDashboard.php:792-794`) |
| option | `akb_crossorigin_scripts_enabled` | nadie: **no hay pantalla** | `'yes'` (default) / `'no'` como interruptor de emergencia. Sólo aparece en `CrossOriginScripts.php:70` y en la precarga de `Plugin.php:797` |
| option | `akb_search_rest_purge_receipt` | `akibara-search/src/Support/RestCache.php` | `protocol`, `generation_target`, `tag`, `dispatched_at`, `ack_scope` |
| option | `akibara_fsp_*` (**11 claves**: `_enabled`, `_threshold`, `_carrier_threshold`, `_show_cart`, `_show_mini_cart`, `_show_checkout`, `_show_qualified`, `_disable_local_pickup`, `_title`, `_message`, `_qualified`) | Akibara → Envío gratis | Umbrales, interruptores y textos de la barra de envío gratis. Qué gobierna cada una, en la pieza 8 |
| option | `akibara_google_maps_api_key` | Akibara → Dirección | Clave pública de Maps; la constante `AKB_GOOGLE_MAPS_API_KEY` tiene prioridad |
| option | `akibara_blocks_shipping_hint_text`, `akibara_blocks_collection_notice_text` | Akibara → Carrito | Textos del carrito |
| option (de terceros, sólo lectura) | `woocommerce_bacs_accounts`, `woocommerce_correios_settings`, `joinchat`, `clarity_project_id`, `rank_math_google_analytic_options`, `woocommerce_google_analytics_settings`, `wp_page_for_privacy_policy` | los terceros | Akibara las lee y en algunos casos las filtra en lectura; nunca las escribe |
| tabla | `wp_bluex_logs` | Blue Express | Bitácora del proveedor; Akibara sólo borra filas de más de 2 días |
| tabla | `wp_actionscheduler_actions`, `_logs` | Action Scheduler | Las purga el propio Action Scheduler con la retención de 14 días |
| tabla | `wp_spar_points_logs`, `wp_cr_local_forms`, `wp_cr_reminders_log` | terceros retirados | Leídas para migrar y después retiradas |
| ordermeta | `agencyId`, `isPudoSelected` | `PudoIntegration.php:741-742` | Nombres del proveedor, escritos por Akibara con valores ya validados. `isPudoSelected` **no guarda un sí/no**: guarda la cadena literal `'pudoShipping'` |
| ordermeta | `agencyName`, `agencyAddress` | `PudoIntegration.php:744-748` | Igual, pero **sólo se escriben si no vienen vacías**: un pedido de retiro en punto puede no tenerlas |
| ordermeta | `_correios_tracking_code` | `wc_correios_updateTrackingCode()` | Códigos de seguimiento separados por coma |
| ordermeta | `_akb_marketing_consent` | `MarketingConsent.php` | JSON con `value`, `label`, `at`, `source`, `policy_modified_gmt` |
| ordermeta | `SendinblueWoocommerce/newsletter_opt_in` | WooCommerce | `'1'`/`'0'` que Brevo lee. Akibara no lo escribe |
| ordermeta | `akibara/rut` (campo adicional) y `_wc_other/akibara/rut` | `CheckoutFields`, `RutValidation` | RUT del comprador |
| ordermeta | `_akb_eta_bluex_handoff_alerted` | `CarrierHandoffMonitor` | Marca antigua de alerta ya enviada |
| ordermeta | `_spar_points_redeemed` (+`_value`, +`_refunded`) | tercero retirado | Canjes antiguos, leídos para reconstruir el libro |
| postmeta | `rank_math_focus_keyword` | `akibara-rankmath-redirections-abilities.php` y Rank Math | Palabra clave de enfoque |
| postmeta | `rank_math_title`, `_description`, `_robots` | Rank Math y Akibara | Campos SEO, con marcas de propiedad en las landings de serie |
| postmeta | `ivole_review_image2`, `ivole_review_image` | `akibara-rewards` | Fotos de reseña, con el formato del tercero retirado. Desde rewards **0.4.17** son sólo estas dos: `ivole_media_count` y las dos metas de vídeo se retiraron por no tener lector, aunque puedan quedar filas viejas en la base de datos |
| postmeta | `_wc_gla_*` | Google Listings & Ads | Akibara nunca las escribe; sólo respeta un valor manual si existe |
| termmeta | `rank_math_title`, `_description`, `_robots`, `_canonical_url` | Akibara y Rank Math | Lo mismo a nivel de serie o editorial |
| termmeta | `_akb_adult_content` | Productos → Atributos → Serie | `'1'` = esta serie se declara adulta en el feed de Merchant |
| termmeta | porcentaje de descuento por serie | `TaxonomyDiscount/Settings.php:652-664` | El dato que **no** dispara la purga de caché (caso 9) |
| usermeta | `_akb_sf_optout_all` | avisos de serie | Baja global de los avisos de tomo nuevo |
| transient | `akb_pudo_log_<md5>` | `PudoLog` | Cuota de una línea de registro por minuto y contexto |
| transient | `akb_rl_<hash>`, `akb_rlm_<hash>` | `RateLimit` | Contadores del limitador por IP resuelta |
| fichero | `wc-logs`, fuentes `akibara-pudo` y `akibara-consentimiento` | Akibara | Bitácoras visibles en WooCommerce → Estado → Registros |
| fichero | `error_log` del hosting | `akibara-bluex-integrity-watch.php:88` | Una línea `[akibara-bluex-watch]` con la lista de cargas rotas |
| fichero | `<padre del docroot>/akb-runtime/akibara-correo-smtp.php` | el despliegue | Copia de rescate del transporte, nunca servible por web |
| navegador | `sessionStorage` clave `akb_ai_attribution` | `clarity-commerce-events.js:15`, `:160` | Fuente de IA, evidencia y marcas de tiempo de la sesión |

---

## Glosario del capítulo

- **Ability / Abilities API**: mecanismo de WordPress para publicar una operación con nombre,
  esquema de entrada y permiso, de modo que un programa externo pueda invocarla.
- **Action Scheduler**: la cola de tareas que WooCommerce trae dentro. Aquí hay **dos**
  copias instaladas: la de WooCommerce y la de Rank Math.
- **AVIF**: formato de imagen moderno y muy comprimido que Gmail no sabe pintar; de ahí la
  hermana JPG.
- **Cargo (*fee*)**: una línea del carrito que suma o resta dinero sin ser un producto. El
  canje de puntos es un cargo negativo.
- **Constante**: valor escrito a mano en `wp-config.php`. No se puede cambiar desde el panel;
  es donde viven los secretos.
- **Cron**: el reloj que dispara tareas solo. En esta tienda no lo empuja el tráfico: lo
  dispara Hostinger cada cinco minutos desde fuera.
- **Filtro**: punto de enganche que permite cambiar un valor antes de que el programa lo use.
- **Fail-closed / fail-open**: ante la duda, cerrar (rechazar la operación) o abrir (seguir
  con menos garantías). El checkout de comunas falla **abierto** a propósito; las abilities
  de MCP fallan **cerradas**.
- **Hook / acción**: punto de enganche que avisa de que algo pasó, sin cambiar ningún valor.
- **HPOS**: las tablas nuevas donde WooCommerce guarda los pedidos, en vez de mezclarlos con
  las entradas del blog.
- **JSON-LD / schema / `@graph`**: el bloque de datos estructurados que se incrusta en la
  página para que Google entienda qué es cada cosa.
- **LCP**: el elemento más grande que se pinta al cargar; la métrica de velocidad que Google
  mira.
- **MCP**: protocolo por el que un agente externo descubre y llama operaciones publicadas por
  un servidor. Aquí lo alimenta la Abilities API.
- **mu-plugin**: fichero que WordPress carga **siempre**, antes que cualquier plugin, y que
  no se puede desactivar desde el panel.
- **`no-store`**: instrucción que pide a navegadores y CDN que no guarden copia de una
  respuesta.
- **Option**: ajuste guardado en la base de datos. Si nadie le puso pantalla, es una «option
  invisible», que la regla de oro de la casa prohíbe.
- **Prioridad**: número que decide el orden en que corren varios callbacks sobre el mismo
  hook. Menor corre antes; `PHP_INT_MAX` corre el último.
- **PUDO**: *pick-up / drop-off*, el punto de retiro de una agencia de Blue Express.
- **Purga**: vaciar la caché para que la próxima visita reciba la versión nueva.
- **Store API**: la interfaz por la que el carrito y el checkout de bloques hablan con el
  servidor.
- **Termmeta / postmeta / usermeta / ordermeta**: datos sueltos colgados de un término (una
  serie), una entrada, un usuario o un pedido.
- **Transient**: dato guardado con fecha de caducidad; se usa para cuotas y contadores.
- **`wp_mail`**: la función por la que pasa **todo** el correo de WordPress y de WooCommerce.
