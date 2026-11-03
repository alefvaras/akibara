# akibara-cart-checkout-blocks · RUT, celular, comuna y puntos Blue Express en el checkout por bloques

> **Fuente de verdad de este capítulo:** el snapshot de lo publicado en producción —
> `akibara-cart-checkout-blocks` **2.2.17**, con `akibara-business` 1.116.4, el tema
> `akibara-gp` 2.1.118 y WooCommerce al lado—, nunca el árbol de trabajo local, que mezcla
> trabajo a medias. Cada afirmación técnica lleva su `fichero:línea`. **Las rutas que no
> dicen de quién son, son de este plugin**: `src/…` y `assets/…` cuelgan de
> `public_html/wp-content/plugins/akibara-cart-checkout-blocks/`. Cuando la cita es de otro
> sitio se nombra entero (`akibara-business/src/…`, `themes/akibara-gp/inc/…`).
>
> **Cómo leerlo si es tu primer día.** Las palabras en `código` —`hook`, `option`, `meta`,
> `transient`, `bloque`, `Store API`— están todas explicadas en el **Glosario del capítulo**,
> al final. No hace falta leerlo antes: cuando una palabra estorbe, está ahí abajo.
>
> **Los diagramas.** Cada uno está en `diagramas/` dos veces, con el mismo nombre: el `.svg` es la
> imagen ya dibujada —doble clic y se abre en cualquier navegador— y el `.mmd` es su fuente en texto
> (formato *Mermaid*), que es lo que se edita si el diagrama cambia. Cada uno se enlaza en el punto
> del texto donde ayuda.
>
> **Marcas «⚠ Hoy no cuadra».** No son opiniones: son contradicciones entre lo que el código
> promete y lo que el código hace, cada una con su `fichero:línea`. Están aquí porque un mapa
> que sólo cuenta la versión bonita es un mapa que miente.
>
> **Cuándo dice «Medido».** Toda medición lleva fecha y procedencia, porque hay dos clases y
> no valen lo mismo: **(a) medido en esta ronda**, el 2026-09-12, contra producción por
> `ssh akibara` o contra el fichero del proveedor en el árbol de trabajo; y **(b) copiado de
> un comentario del propio código**, con la fecha que ese comentario trae —casi todas del
> 2026-08-23, hechas en Docker o con Playwright sobre el espejo—. Un dato de (b) es de segunda
> mano: sirve para entender, pero si vas a decidir algo con él, repite la medición.
>
> **Qué se puede comprobar y qué no.** Los ficheros de este plugin y de los otros tres propios
> están en el snapshot de producción. El código de los terceros —WooCommerce, Blue Express,
> Mercado Pago, Flow— **no**: cuando el capítulo cita un fichero suyo, hay que abrirlo en el
> árbol de trabajo (`public_html/wp-content/plugins/<tercero>/`) o en el servidor.

---

## Para qué existe (en dos párrafos que entienda un novato)

Akibara.cl es una tienda hecha con **WordPress** —el programa que arma y sirve las páginas— más
**WooCommerce**, un añadido de terceros que le pone catálogo, carrito y pago. WooCommerce ofrece
hoy dos maneras de dibujar el carrito y el formulario de pago: la vieja («clásica», plantillas
PHP que se rellenan enteras en el servidor) y la nueva («bloques»: piezas de interfaz escritas
en JavaScript que se insertan en el contenido de la página desde el editor de WordPress y se
pintan en el navegador). **Akibara usa la nueva**, en dos páginas dedicadas: `/carrito/` y
`/checkout/`. Este plugin es *todo* el pegamento propio entre la tienda y esa versión nueva. No
reimplementa el carrito: se cuelga de los puntos de extensión que WooCommerce publica, y por eso
casi ninguna línea de aquí se ejecuta por su cuenta —espera a que WordPress la llame.

Lo que rellena es el hueco entre un carrito pensado para vender camisetas en Estados Unidos y una
tienda chilena de manga. Cuatro familias de cosas: **(1) los campos chilenos** —el RUT como campo
extra, el celular con su validación, y sobre todo la **comuna**, que WooCommerce trata como
«ciudad» de texto libre y que aquí se convierte en una lista buscable de las 346 comunas del
país, con las tildes corregidas *antes* de cotizar el envío; **(2) la presentación** —avisos de
pack, barra de envío gratis, resumen de preventas, señales de confianza, todo dibujado en el
navegador a partir de un paquete de datos que el servidor publica; **(3) el retiro en Punto Blue
Express** —un mapa incrustado donde el cliente elige el local donde pasará a buscar el pedido; y
**(4) los parches contra terceros** (Mercado Pago, Flow, Blue Express, LiteSpeed) que ensucian o
rompen el pago de bloques, más una pantalla que avisa cuando un parche ya sobra.

![Piezas del capítulo](diagramas/12-checkout-blocks-campos-chilenos-y-pudo-piezas.mmd)

---

## Cómo encaja con el resto (con quién habla; qué pasa si se apaga)

**El reparto de aguas es una frase: `akibara-business` decide, `akibara-cart-checkout-blocks`
pinta.** Este plugin no tiene reglas de precio, ni de stock, ni de fechas: se las pide a Business
por un contrato estable y las convierte en HTML, JSON y mensajes de error. Tampoco tiene pantalla
propia en el panel: sus dos textos configurables se editan en *Akibara → Carrito*, que es del
**otro** plugin.

**Con akibara-business.** Es una dependencia dura declarada en la cabecera
(`Requires Plugins: woocommerce, akibara-business`, `akibara-cart-checkout-blocks.php:8`). El
arranque comprueba **cuatro** cosas, en este orden, antes de instanciar nada
(`src/Plugin.php:30-42`):

| # | Qué comprueba | Línea | Cuándo falla en la vida real |
| --- | --- | --- | --- |
| 1 | Que exista la clase `WooCommerce` | `:30` | WooCommerce desactivado |
| 2 | Que exista la clase `Akibara\Business\Plugin` | `:30` | akibara-business desactivado o no desplegado |
| 3 | Que ese objeto tenga el **método** `checkout_data()` | `:35-37` | **Una versión vieja de Business**: el escenario más probable tras un despliegue a medias |
| 4 | Que lo que devuelva implemente el contrato `CheckoutDataProvider` | `:40` | Business cambió el tipo de retorno |

Si alguna falla, **no arranca nada** y la tienda se queda con el carrito pelado de WooCommerce:
sin RUT, sin comuna, sin avisos, sin retiro en punto. El servicio de PUDO se pide aparte y es
opcional: sólo si `$business->pudo_checkout()` devuelve un objeto se construye `PudoIntegration`
(`src/Plugin.php:47-49`, `62-64`).

> **Dónde está «el contrato», que este capítulo nombra ocho veces.** La interfaz es
> `akibara-business/src/Contracts/CheckoutDataProvider.php`, y el objeto que la cumple lo
> entrega `akibara-business/src/Plugin.php:1482-1484` (`checkout_data()`). De ahí salen
> `is_valid_rut()`, `format_rut()`, `phone_validation_error()`, `structured_cart_data()` y
> `address_autocomplete_config()`. El servicio de PUDO es **otro** objeto y viene por otro
> método: `akibara-business/src/Plugin.php:1493-1495` (`pudo_checkout()`). Si algún día «falla
> el contrato», ésos son los dos ficheros que hay que abrir.

> **Las options se leen por NOMBRE, nunca importando clases del otro plugin.** Lo explica el
> propio código (`src/Assets.php:74-86`) y lo exige el gate de release
> `tools/akibara-releases/docker-checkout.sh`: `src/` de este plugin no puede depender de los
> módulos internos de Business. El nombre de la option *es* el contrato.

**Con WooCommerce.** Por todas partes, y siempre por hooks publicados: el formulario chileno
(`woocommerce_get_country_locale`), el campo adicional RUT
(`woocommerce_register_additional_checkout_field`), la **Store API** (dos extensiones de datos y
un canal de escritura), el cálculo de envíos (`woocommerce_cart_shipping_packages`,
`woocommerce_package_rates`), la validación final antes de cobrar
(`woocommerce_checkout_validate_order_before_payment`) y el registro de bloques internos del
formulario de pago. Además declara compatibilidad con las tablas de pedidos nuevas (HPOS) y con
los bloques de carrito/pago (`akibara-cart-checkout-blocks.php:42-52`).

**Con el plugin de terceros de Blue Express** (`bluex-for-woocommerce`, que **no se toca nunca**),
de tres formas distintas:

1. **Le lee el catálogo de comunas.** `ComunaDirectory` incluye
   `bluex-for-woocommerce/includes/data/chile-communes.php` y llama a
   `bluex_get_communes_by_region()` (`src/ComunaDirectory.php:26`, `377-393`). Es el único listado
   que su API de cotización acepta, así que es el que manda.
2. **Le sustituye la integración de retiro en punto.** La Store API tiene **dos registros
   separados que llevan el mismo nombre**, `bluex_pudo`, y Akibara sólo se queda con uno:
   - el de **lectura** —qué datos del punto elegido se publican dentro de la caja `extensions`
     del carrito— **sigue siendo de Blue Express**; el bundle de Akibara se limita a leerlo
     (`assets/js/pudo-blocks.js:683-685`);
   - el de **escritura** —el canal `POST /wp-json/wc/store/v1/cart/extensions` por el que el
     navegador manda la elección— es el que Akibara **sustituye**, registrando su propio
     manejador bajo ese mismo nombre (`src/PudoIntegration.php:21`, `82-95`).

   Además retira los ficheros de PUDO de Blue en la página de pago (`:346-353`). Leer «Akibara
   pisa `bluex_pudo`» y «la tarjeta lee de `bluex_pudo`, que es de Blue» no es una contradicción:
   son los dos registros distintos.
3. **Le desencola dos selectores de comuna** que en bloques no funcionan
   (`src/RuntimeCompatibility.php:239-249`).

**Con el tema `akibara-gp`.** Dos shortcodes que el tema y las páginas usan
(`src/Shortcodes.php:27-28`), y un filtro `generate_show_title` a prioridad 100 para que el tema
no pinte su propio título encima del del shortcode (`src/RuntimeCompatibility.php:124`,
`447-449`).

**Con Google.** El buscador de direcciones del campo Dirección. El plugin registra el proveedor y
monta la URL del cargador con la clave, la biblioteca y el idioma
(`src/AddressIntegration.php:119-143`); la llamada real la hace el navegador del cliente.

**Con Mercado Pago, Flow y LiteSpeed.** Sólo por hooks, sólo para quitar peso muerto o reponer
dependencias que el tercero olvidó (`src/RuntimeCompatibility.php:117-126`).

**Con un cuarto tercero que el mapa no nombraba: `google-login`.** El bundle del editor mete el
bloque `google-login/login-button` en la lista blanca de hijos permitidos del formulario de pago,
como literal cableado (`assets/js/checkout-inner-blocks-editor.js:22`, `102`). Es una dependencia
viva que nadie está vigilando: si ese plugin se desinstala o renombra su bloque, queda un nombre
muerto en la lista y no lo dice nadie.

### «Fail open» y su reverso

Casi todo aquí está escrito para que, si falta el dato de un tercero, **la tienda siga vendiendo
con menos ayudas en vez de romperse**.

**Sin catálogo de comunas no es una puerta, son tres**, y el orden en que hay que mirarlas
cuando mañana «la comuna no se valida» es exactamente éste:

| # | Puerta | Dónde | Qué apaga |
| --- | --- | --- | --- |
| 1 | ¿Se inyectó un `ComunaDirectory`? | `src/CheckoutFields.php:70-74` | Si no, los **tres hooks de comuna ni se registran**. Hoy siempre se inyecta: `src/Plugin.php:46` lo construye sin condición, así que esta puerta está abierta salvo que alguien cambie el arranque |
| 2 | ¿El directorio tiene datos usables? | `src/CheckoutFields.php:276-278` (`is_available()`) | El validador **sale antes de mirar nada**. Ésta es la puerta que de verdad se cierra en producción |
| 3 | ¿Hay datos al encolar? | `src/Assets.php:338-341` | El combobox **no se encola** y `window.akbComunaData` no se inlina |

Si el servicio de PUDO de Business no expone los siete métodos que este transporte llama, las
tarifas de retiro desaparecen del listado en vez de dar error (`src/PudoServiceAdapter.php:50-68`,
`src/PudoIntegration.php:259-262`). Si la versión del contrato de presentación no cuadra, el
`payload` se devuelve vacío (`src/StoreApiIntegration.php:244-246`).

Ese es también el reverso: **son cosas que fallan en silencio**. Cero errores de PHP, cero errores
de consola, 200 en todas las páginas. La única de las cuatro que deja rastro legible es la de
PUDO, porque para eso se escribió `PudoLog` (`src/PudoLog.php:1-16`).

### Qué pasa si se apaga cada cosa

| Se cae / se apaga | Qué deja de funcionar | Qué sigue funcionando |
| --- | --- | --- |
| `akibara-business` | **Todo el plugin.** No arranca (`src/Plugin.php:30-42`) | El carrito y el pago de WooCommerce, sin nada chileno |
| `$business->pudo_checkout()` devuelve `null` | El retiro en punto entero (`src/Plugin.php:62-64`) | Campos chilenos, avisos, despacho a domicilio |
| El catálogo de Blue (`bluex_get_communes_by_region`) | Combobox de comunas, canonización y validación de comuna; y el buscador de Google deja la comuna siempre vacía (`assets/js/google-places-address-provider.js:630-634`) | El campo Comuna como texto libre; el pedido pasa |
| La interfaz `IntegrationInterface` de Woo Blocks | Los dos bloques internos del pago, sin un solo error (`src/Plugin.php:58-60`) | El carrito completo y el resto del formulario |
| `structured_cart_data()` devuelve otra versión | Avisos de pack, barra de envío gratis, resumen de preventa, señales de confianza y el rótulo del descuento de pack (`src/StoreApiIntegration.php:244-246`) | **La clase de estado de cada línea y las filas «Estado» / «Llegada estimada»**: no pasan por esa guarda |
| El filtro `akibara_comuna_directory` devuelve `array()` | Combobox y validación de comuna (interruptor de emergencia por código) | Todo lo demás |

> **⚠ Hoy no cuadra — la fila de `pudo_checkout()` es una compuerta TEÓRICA.** La tabla la
> ofrece como si fuera una palanca, y hoy no hay ninguna: Business construye el servicio
> **siempre y sin condición** (`akibara-business/src/Plugin.php:884`:
> `$this->pudo_checkout = new PudoCheckoutService();`) y el accesor se limita a devolverlo
> (`:1493-1495`). No existe option, ni filtro, ni casilla que apague sólo el retiro en punto:
> esa fila sólo se cumple si Business entero no arranca. Queda anotado como pregunta para el
> dueño al final del capítulo.

> **⚠ Hoy no cuadra — el radio de la avería del contrato es PARCIAL, no total.** La extensión
> **por línea** (`stock_status`, `stock_label`, `is_preventa`, `eta`) no lleva `schema_version`
> ninguno, y el bundle la consume sin comprobar nada: usa `rawExtensionFromMap`, no
> `structuredExtensionFromMap` (`assets/js/cart-checkout-blocks.js:40-49`, `454-462`). Y las filas
> «Estado» / «Llegada estimada» las escribe el servidor por `woocommerce_get_item_data`, que
> tampoco pasa por esa guarda (`src/StoreApiIntegration.php:317-357`). Al medirlo en el espejo hay
> que **esperar un fallo parcial**; quien espere mudez completa concluirá que el experimento salió
> mal.

---

## Piezas

![Piezas del capítulo y sus relaciones](diagramas/12-checkout-blocks-campos-chilenos-y-pudo-piezas.mmd)

**Dieciocho piezas, repartidas en 28 ficheros**: 15 PHP (14 en `src/` más el fichero principal
del plugin), 9 JavaScript en `assets/js/`, 3 CSS en `assets/css/` y una imagen en
`assets/images/`. Son 18 y no 28 porque varias piezas juntan su PHP con su JavaScript y su CSS:
el combobox de comunas, por ejemplo, es una sola pieza con tres ficheros. Van agrupadas por
dominio, en el orden en que se encuentran trabajando.

### 1. Arranque — `akibara-cart-checkout-blocks.php` + `src/Plugin.php`

**Qué hace, en una frase.** Enciende el plugin sólo cuando WooCommerce y akibara-business ya están
cargados, y va creando una a una las piezas que hacen el trabajo.

**Ficheros.** `akibara-cart-checkout-blocks.php` (54 líneas), `src/Plugin.php` (66 líneas).

**Cuándo actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `before_woocommerce_init` | 10 | Declara compatibilidad con HPOS (`custom_order_tables`) y con `cart_checkout_blocks` (`akibara-cart-checkout-blocks.php:42-52`) |
| `plugins_loaded` | **30** | `Plugin::boot()`: compone todo (`akibara-cart-checkout-blocks.php:54`). La 30 es posterior al arranque de Business |

**Qué lee y qué escribe.** Nada de la base de datos. Declara cuatro constantes —versión, fichero,
directorio y URL— y registra un autocargador propio que mapea el espacio de nombres
`Akibara\CartCheckoutBlocks` a `src/` (`akibara-cart-checkout-blocks.php:22-40`).

**El orden de construcción no es el orden de registro**, y conviene saberlo: primero se
instancian `BlocksPage`, `AddressIntegration` y **`ComunaDirectory`** (`src/Plugin.php:44-46`),
porque los tres se le inyectan a los demás; después se encadenan los `register()` de
`CheckoutFields`, `StoreApiIntegration`, `RuntimeCompatibility`, `Assets`, `Shortcodes` y
`AddressIntegration` (`src/Plugin.php:51-56`); y al final, bajo dos compuertas distintas,
`CheckoutBlockIntegration` y `PudoIntegration`.

> **⚠ Hoy no cuadra — una compuerta silenciosa que nadie nombra.** Los dos bloques internos del
> pago sólo se registran si `interface_exists('\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface')`
> (`src/Plugin.php:58-60`). Si esa interfaz no resuelve —renombrada, movida, un WooCommerce sin
> bloques— el resumen de preventas y las señales de confianza **desaparecen del checkout sin un
> solo error**. Es el mismo modo de fallo invisible que este capítulo denuncia en otros sitios, y
> aquí ni siquiera está señalizado.

**Dónde lo opera el dueño.** En ningún sitio: se opera activando o desactivando el plugin.

**Qué ve el cliente.** Nada directamente; sin este arranque no existe ninguna de las demás piezas.

---

### 2. `BlocksPage` — la única pregunta

**Qué hace.** Responde una sola cosa: *¿la página que se sirve ahora es el carrito de bloques, o
el pago de bloques?*

**Ficheros.** `src/BlocksPage.php` (54 líneas).

**Cuándo actúa.** No se engancha a nada. La llaman `Assets`, `RuntimeCompatibility`,
`PudoIntegration`, `AddressIntegration` y `StoreApiIntegration` cuando necesitan decidir.

**Qué lee.** `is_checkout()` / `is_cart()` de WooCommerce, más `wc_get_page_id()` y `has_block()`.
No basta con `is_checkout()`: además exige que **no** sea la pantalla de «pedido recibido» ni la
de «pagar pedido», y que la página contenga de verdad el bloque `woocommerce/checkout`
(`src/BlocksPage.php:28-36`). Igual para el carrito (`45-53`).

**Lo que cuesta, medido con precisión y no de oído.** Las dos funciones **salen por la primera
línea** cuando la página no es la suya: `is_cart()` empieza con
`if ( ! function_exists('is_cart') || ! is_cart() ) { return false; }` (`src/BlocksPage.php:46`)
y `is_checkout()` igual (`:29`). O sea que en una ficha de producto, en la portada o en una
entrada del blog, llamar a esto cuesta **una llamada a función y una comparación**: ni
`wc_get_page_id()`, ni `has_block()`, ni tocar la base de datos. El coste sólo aparece **dentro
de** `/carrito/` y `/checkout/`, donde sí se llega a `has_block()` — que resuelve el post
(WordPress lo cachea en memoria tras la primera vez de esa petición) y busca el nombre del
bloque dentro de su contenido. Es barato, pero **no es gratis**, y hay sitios donde se llama
una vez por producto del carrito (ver la pieza 6).

**Dónde lo opera el dueño.** En ningún sitio. Lo único que cambia su respuesta es qué páginas
están marcadas como carrito y pago en *WooCommerce → Ajustes → Avanzado*, y que esas páginas
sigan conteniendo los bloques `woocommerce/cart` y `woocommerce/checkout`.

**Qué ve el cliente.** Nada: es una pregunta interna. Pero si respondiera mal, el cliente vería
un pago sin campos chilenos y sin retiro en punto.

---

### 3. `CheckoutFields` — todo lo chileno del formulario

**Qué hace.** El campo RUT, las etiquetas y el orden de Región y Comuna, la corrección automática
de la comuna, y las dos validaciones finales antes de cobrar.

**Ficheros.** `src/CheckoutFields.php` (539 líneas).

**Cuándo actúa.**

| Hook | Prioridad | Qué hace | Línea |
| --- | --- | --- | --- |
| `woocommerce_get_country_locale` | 10 | Etiquetas, ejemplo y orden del formulario CL | `:53` |
| `woocommerce_get_default_value_for_akibara/rut` | 10 | Rellena el RUT desde la meta antigua | `:54-59` |
| `woocommerce_checkout_validate_order_before_payment` | 10 | Valida el celular de facturación | `:60` |
| `woocommerce_set_additional_field_value` | 10 | Copia el RUT a la meta heredada | `:61` |
| `woocommerce_store_api_cart_update_customer_from_request` | 10 | Canoniza la comuna del **cliente** | `:64` |
| `woocommerce_store_api_checkout_update_order_from_request` | 10 | Canoniza la comuna del **pedido** | `:65` |
| `woocommerce_checkout_validate_order_before_payment` | 10 | Valida la comuna | `:66` |
| `woocommerce_init` | 10 | Registra el campo adicional RUT, una sola vez | `:69-74` |

Los tres hooks de comuna **sólo se registran si se inyectó un directorio de comunas**
(`src/CheckoutFields.php:70-74`) — que hoy siempre ocurre—; el que de verdad se apaga cuando
falta el catálogo es el validador, que sale por `is_available()` (`:180-182`). Es la puerta 2
de las tres del *fail open*.

**Qué lee y escribe.** Lee del contrato de Business `is_valid_rut()`, `format_rut()` y
`phone_validation_error()`. Escribe el RUT formateado en `_billing_rut` (pedido) y `billing_rut`
(cliente) (`src/CheckoutFields.php:498-514`), y reescribe la comuna con `set_shipping_city` /
`set_billing_city` cuando la coincidencia normalizada es exacta (`:223-240`).

**Cuatro detalles que importan.**

- **Región antes que Comuna.** WooCommerce pone ciudad en prioridad 70 y región en 80; el plugin
  sube región a **69** (`src/CheckoutFields.php:216-221`), de modo que el combobox de comunas ya
  tiene una región que filtrar cuando el cliente llega a él.
- **El ejemplo del celular es `8765 4321` a propósito.** El anterior, `1234 5678`, lo **rechaza el
  propio validador** por empezar en 1: ningún celular chileno lo hace. El campo sugería de ejemplo
  un número que no acepta (`src/CheckoutFields.php:191-196`).
- **El RUT se canoniza en la entrada.** Sin eso, el campo nativo guardaba `123456785` y la meta
  heredada `12.345.678-5`: dos escrituras del mismo RUT en el mismo pedido
  (`src/CheckoutFields.php:388-414`). Un RUT **inválido** se devuelve tal cual, sin maquillar: el
  cliente tiene que ver lo que él escribió (`:300-303`, `310-318`).
- **La canonización corre ANTES del guardado y del recálculo de envíos**, tanto en el carrito como
  en el pago. Sólo se reescriben coincidencias **exactas normalizadas**; una entrada parcial no se
  toca jamás (`src/CheckoutFields.php:327-330`).

**Dónde lo opera el dueño.** En ningún panel. El único interruptor es el filtro por código
`akibara_comuna_directory`.

**Qué ve el cliente.** El campo RUT en el paso de contacto; las etiquetas «Celular» y «Comuna»; el
orden Región → Comuna; y los mensajes de error al pulsar «Realizar el pedido».

> **La validación de comuna recorre DOS direcciones, no una.** `validate_chilean_comuna()` itera
> `array( 'shipping', 'billing' )` y se detiene en el primer grupo con problema
> (`src/CheckoutFields.php:280`, `204`, `213`). O sea que **una comuna mal escrita en la
> dirección de facturación bloquea el pedido igual**, aunque la de envío esté perfecta. Y el
> bloque de facturación suele estar plegado en el formulario («Usar la misma dirección para
> facturar»), así que el cliente lee un error sobre una comuna que no ve por ninguna parte. Si
> alguien reporta «no me deja pagar y la comuna está bien», lo primero es desplegar
> facturación.

> **⚠ Hoy no cuadra — el servidor valida sólo el celular de FACTURACIÓN.** `validate_billing_phone()`
> lee únicamente `get_billing_phone()` (`src/CheckoutFields.php:446-456`) y no existe ningún
> equivalente para envío en todo el plugin. Un cliente sin JavaScript puede dejar un teléfono de
> envío que el transportista no puede usar, y nadie se entera hasta que el paquete vuelve. Es una
> decisión de negocio: arreglarlo cambia qué pedidos se aceptan.

---

### 3 bis. `RutPrivacy` — el RUT también es un dato personal (desde 2.2.22)

**Qué hace.** Mete el RUT en las dos herramientas de privacidad de WordPress (Herramientas →
Exportar datos personales / Borrar datos personales). Era el único dato de cliente de la caja que
no salía ni se borraba (hallazgo S-23bc8b3c): WooCommerce exporta y anonimiza los campos de
facturación que conoce, pero **no** los campos adicionales del checkout de bloques.

**Ficheros.** `src/RutPrivacy.php`, registrado en `src/Plugin.php` junto a `CheckoutFields`.

**Dónde vive el RUT** (dos copias por dueño, y por eso el borrado nombra las dos):

| Dueño | Meta histórica | Campo nativo de bloques |
| --- | --- | --- |
| Pedido | `_billing_rut` | `_wc_other/akibara/rut` |
| Cliente | `billing_rut` | `_wc_other/akibara/rut` |

**Cuándo actúa.** Sólo cuando corre una de las dos herramientas; en una visita normal no hace nada.

| Filtro de WooCommerce | Qué hace |
| --- | --- |
| `woocommerce_privacy_export_order_personal_data_meta` | Añade UNA fila «RUT» por pedido (las dos metas guardan lo mismo) |
| `woocommerce_privacy_remove_order_personal_data_meta` | Nombra las dos metas para el borrado |
| `woocommerce_privacy_remove_order_personal_data_meta_value` | Devuelve vacío para que WooCommerce **borre** la meta en vez de dejarla en «[deleted]» (que saldría en el panel y en los correos del pedido) |
| `woocommerce_privacy_export_customer_personal_data` | Añade la fila «RUT» del cliente |
| `woocommerce_privacy_erase_personal_data_customer` | Borra las dos metas del cliente, guarda y lo dice en la respuesta |

**Dónde lo opera el dueño.** No tiene pantalla propia: usa las de WordPress. El borrado de PEDIDOS
respeta el ajuste de WooCommerce → Ajustes → Cuentas y privacidad («Eliminar datos personales de
los pedidos a petición»): con ese interruptor apagado WooCommerce no toca ningún pedido, y el RUT
del pedido se conserva igual que el resto de sus datos de facturación.

**Medido** en el espejo el 2026-09-19 con el exportador y el borrador reales de WooCommerce: el RUT
sale en las dos exportaciones, y tras el borrado las cuatro metas quedan vacías. El registro de
búsquedas (`wp_akb_search_qlog`) que el mismo hallazgo señalaba **no** lleva datos por cliente: es
un agregado por día, consulta y origen, con contadores.

### 3 ter. Envío = facturación — la dirección guardada llega a la caja (2.2.30 y 2.2.31)

**El problema (reporte de un cliente, 2026-09-28).** La tienda envía siempre a la facturación
(`woocommerce_ship_to_destination = billing_only`): la caja de bloques muestra sólo la tarjeta de
facturación, «Mi cuenta» sólo edita la facturación y el POST del pago manda `shipping_address` = esa
tarjeta. Pero WooCommerce **cotiza con los campos de ENVÍO** del cliente, y el frontend no los copia al
cargar. Con el envío guardado vacío, el paso «Opciones de envío» decía «Introduce una dirección de
envío…» y Blue no aparecía hasta reescribir la dirección; con otro envío (el punto Blue de un pedido
anterior, otra comuna) se cotizaba otra zona y un punto elegido contra esa dirección se rechazaba al pagar.

**La regla: con `billing_only`, el envío es copia exacta de la facturación** (`mirror_billing_into_shipping()`),
aplicada en dos sitios y sólo si la facturación está completa (país, región, comuna, calle):

| Dónde | Hook | Qué escribe |
| --- | --- | --- |
| Al cargar el carrito, antes de cualquier ruta del Store API | `woocommerce_cart_loaded_from_session` → `sync_forced_shipping_address()` | Sólo el cliente de **sesión** (WooCommerce lo guarda en `shutdown`); nunca la cuenta |
| Antes de guardar **cualquier** cliente | `woocommerce_before_customer_object_save` → `mirror_before_customer_save()` | La cuenta: «Mi cuenta» y el volcado pedido→cliente tras un pedido con punto (`OrderController::sync_customer_data_with_order`) |

**Por qué no rompe el punto Blue:** elegir un punto nunca escribe el envío del cliente de sesión (vive en
`akb_pudo_selection`/`bluex_agency_*` y en el **pedido**, que conserva la dirección del punto); la copia
corre al inicio de cada petición, antes del callback que elige el punto, así que la huella del punto ya se
calcula con la dirección de la tarjeta. Retiro en tienda: sólo existe en la zona 7 (RM) y sigue la región
de la tarjeta, igual que el POST.

**Si se apaga:** la regla depende del ajuste de WooCommerce, no de una option propia: si la tienda deja de
forzar el envío a la facturación, las dos puertas se cierran solas.

**Cómo se vigila:** `tools/akibara-e2e/specs/checkout-direccion-guardada.spec.mjs` (espejo: control de
invitado, envío vacío, envío de otra región, guardado en Mi cuenta; comprobado que falla sin el arreglo) y
`bash ops/centinela-envio-checkout.sh produccion <desde_unix>` (sólo lectura: cuentas guardadas tras el
despliegue desalineadas, cajas activas desalineadas, pedidos a domicilio con envío ≠ facturación, cajas
completas sin tarifa Blue).

### 4. `ComunaDirectory` — el listín

**Qué hace.** Carga el catálogo de comunas de Chile del plugin de Blue Express, lo valida, y sabe
decir si un texto escrito a mano es una comuna, cuál es su escritura correcta y de qué región es.

**Ficheros.** `src/ComunaDirectory.php` (395 líneas).

**Cuándo actúa.** No se engancha a nada; carga a demanda, **una sola vez por petición**
(`src/ComunaDirectory.php:282-285`).

**Qué lee.** `bluex_get_communes_by_region()`, o el fichero
`bluex-for-woocommerce/includes/data/chile-communes.php` si la función no está cargada
(`:377-393`). Si el dato viene malformado, se descarta entero y el directorio reporta «no
disponible» (`:296-324`).

**Medido en esta ronda (2026-09-12, contra el fichero de Blue Express 3.3.1 del árbol de
trabajo): 16 regiones, 346 comunas y los 346 nombres únicos a nivel país** una vez bajados a
minúsculas. Esa unicidad no es un adorno: es lo que permite que el combobox **cambie la Región
por el cliente** cuando elige una comuna de otra región, sin preguntarle cuál de las dos quería.

**Cómo volver a medirlo tú mismo**, que es lo que hay que hacer cada vez que Blue Express
publica una versión nueva. Dos caminos:

- **El gate, que es el bueno**: `tests/php/VendorCommunesContractTest.php` ya lo comprueba en
  cada release —16 regiones exactas, al menos 340 comunas, códigos de región con forma `CL-XX`,
  y unicidad global de los nombres normalizados—, y además exige que `match('nunoa')` siga
  devolviendo `Ñuñoa`. Se corre con el gate entero (ver **Cómo se prueba**, más abajo).
- **A mano, sin WordPress**, para una comprobación rápida desde la raíz del repositorio:

  ```bash
  php -r 'define("ABSPATH","/");
    require "public_html/wp-content/plugins/bluex-for-woocommerce/includes/data/chile-communes.php";
    $c = bluex_get_communes_by_region(); $total = 0; $nombres = array();
    foreach ($c as $comunas) { $total += count($comunas);
      foreach ($comunas as $e) { $nombres[] = mb_strtolower($e["name"]); } }
    echo "regiones=" . count($c) . " comunas=$total unicos=" . count(array_unique($nombres)) . "\n";'
  # 2026-09-12 → regiones=16 comunas=346 unicos=346
  ```

  Si «unicos» baja de «comunas», hay nombres repetidos y **el cambio automático de Región del
  combobox deja de ser inequívoco**: hay que rediseñarlo antes de publicar.

**`normalize()` es el criterio autoritativo** (`src/ComunaDirectory.php:237-258`), y su gemelo
JavaScript tiene que producir exactamente lo mismo: si se separan, el combobox ofrece una comuna
que el servidor rechaza y **el cliente no puede pagar**. Hace tres cosas en este orden concreto:

1. **Borra las marcas combinantes primero** (`:242`): en forma descompuesta (NFD) —la que producen
   los teclados de iOS/macOS y buena parte del texto pegado— `Ñuñoa` es `N` + U+0303, que el mapa
   de precompuestos no ve.
2. **Colapsa espacios, incluido el espacio duro U+00A0** (`:248`), que inyectan los autocompletados
   de dirección.
3. **Baja a minúsculas con `mb_strtolower`** cuando está disponible (`:253-255`); `strtolower()`
   sólo baja ASCII.

> **⚠ Hoy no cuadra — la paridad de espacios sigue incompleta.** El patrón PHP es
> `/[\s\x{00A0}]+/u` (`src/ComunaDirectory.php:248`): con `/u` pero **sin** `PCRE_UCP`, `\s` sigue
> siendo ASCII, así que cubre exactamente **un** espacio no-ASCII, U+00A0. El gemelo JavaScript usa
> `.replace(/\s+/g, ' ')` (`assets/js/comuna-combobox.js:41-44`), y el `\s` de JavaScript incluye
> además U+2009, U+202F, U+2000–U+200A, U+205F, U+3000 y U+FEFF. Una comuna pegada con un
> U+202F o un BOM —texto copiado de otra web, autocompletado de iOS— normaliza distinto en los dos
> lados: la lista la acepta y `validate_chilean_comuna()` la rechaza. Es el mismo fallo que el
> comentario del fichero dice haber arreglado, con otro carácter.

**`suggest()` tiene dos decisiones aprendidas a golpes**, documentadas en el propio fichero:

- **Umbral proporcional al largo**: 1 hasta 4 letras, 2 de 5 a 7, 3 desde 8
  (`src/ComunaDirectory.php:184-190`). Con el umbral fijo de 2, «Vina» sugería «Pica» —comuna de
  Tarapacá, a 1.800 km de Viña del Mar— con toda seguridad.
- **La región es un FILTRO, no un desempate** (`:192-204`). Antes puntuaba
  `distancia*2 + (misma_región ? 0 : 1)`, así que «Renaca» proponía «Renca» (Santiago) estando el
  cliente en Valparaíso. Aceptar esa sugerencia dejaba la dirección en otra región: cotización
  equivocada y paquete a un destino que el cliente nunca pidió.

**Interruptor de emergencia.** El filtro `akibara_comuna_directory` (`:326-339`). Devolver un array
vacío apaga el combobox **y** la validación de comuna.

**Dónde lo opera el dueño.** En ningún panel: sólo por código, con ese filtro.

**Qué ve el cliente.** Nada directamente. Con el directorio disponible ve la lista de comunas y
los mensajes de error; sin él, un campo «Comuna» de texto libre.

---

### 5. `comuna-combobox.js` + `comuna-combobox.css`

**Qué hace.** Convierte los campos de texto `#shipping-city` y `#billing-city` en una lista
buscable accesible: filtra mientras se escribe, se maneja con teclado, elige sola la región, y
avisa en el momento si lo escrito no es una comuna.

**Ficheros.** `assets/js/comuna-combobox.js` (766 líneas), `assets/css/comuna-combobox.css`.

**Cuándo actúa.** En el navegador, sólo en el pago. Se encola desde `Assets` y sólo si hay catálogo
(`src/Assets.php:338-341`). Se engancha en `DOMContentLoaded` (`assets/js/comuna-combobox.js:761-765`).

**Qué lee.** El global `window.akbComunaData` que PHP inlina antes del script: catálogo completo,
etiquetas de región de WooCommerce y tres literales de interfaz (`src/Assets.php:365-379`).

**Lo que hace de verdad, por partes.**

- **Los campos del pago los dibuja React**, la biblioteca con la que está escrito el checkout de
  bloques. React guarda el valor del campo en su propia memoria, no en el `<input>`: si un guion
  ajeno escribe directamente en el `<input>`, React no se entera y en el siguiente repintado lo
  revierte. Por eso el combobox usa `setNativeValue()`
  (`assets/js/comuna-combobox.js:255-277`), que escribe el valor por la puerta de atrás **y
  además finge los eventos `input` y `change`** que React está escuchando. Es el mismo truco en
  todos los ayudantes de este plugin.
- React los vuelve a montar; un `MutationObserver` sobre `document.body` y una suscripción a
  `wp.data` reenganchan el combobox (`:725-726`, `750-752`).
- Al **elegir** una comuna de otra región, **cambia la Región por el cliente** (`:499-513`): los
  346 nombres son únicos —medido el 2026-09-12, ver la pieza 4—, así que no hay ambigüedad.
- Al **cambiar la región**, vacía una comuna que ya no le pertenece (`shouldClearCity`, `:112-128`,
  `638-648`): paridad con el selector clásico.
- `comunaIssue()` (`:150-202`) es el gemelo en cliente de la validación del servidor. Publica el
  mensaje en el almacén `wc/store/validation` bajo la clave `billing_city` / `shipping_city`, que
  es **la misma que usa WooCommerce** (`:396-398`), de modo que sale anclado al campo con su
  `aria-describedby` en vez de como nota suelta del formulario.
- `placeListbox()` (`:219-240`) decide si la lista abre arriba o abajo midiendo el **viewport
  visual** (`window.visualViewport`), no el de maquetación: el teclado del móvil aparece *después*
  del foco y no dispara `resize` de `window` en iOS. Medido con Playwright el 2026-08-23 en un
  iPhone 390×664 —dato copiado del comentario del propio fichero,
  `assets/js/comuna-combobox.js:208`—: la lista iba de 596 a 860 px, 196 px por debajo del
  viewport, con 542 px libres por encima.

**Dónde lo opera el dueño.** En ningún panel. Se apaga con el mismo filtro
`akibara_comuna_directory` de la pieza 4.

**Qué ve el cliente.** La lista desplegable de comunas y el mensaje de error bajo el campo.

> **⚠ Hoy no cuadra — el combobox exige que la Región sea un `<select>` nativo, sin plan B.**
> `stateSelectFor()` devuelve `null` si `#shipping-state` no es un `SELECT`
> (`assets/js/comuna-combobox.js:403-411`), y `currentRegion()` entonces devuelve cadena vacía
> (`:408-411`). No hay vía alternativa —por ejemplo leer la región del propio almacén del carrito,
> que es de donde la lee el checkout. Woo Blocks alterna entre `<select>` y un combobox con
> `<input role="combobox">` según versión y número de opciones. Si eso cambia, pasan **tres cosas
> a la vez y sin un solo error de consola**: (1) `rank()` deja de filtrar y ofrece las 346 comunas
> del país; (2) `comunaIssue()` nunca devuelve `kind:'region'`, porque con región vacía sale por la
> rama «no hay nada que avisar» (`:171`); y (3) `select()` no corrige la región al elegir una comuna
> de otra. El servidor sigue bloqueando al pagar, así que el cliente lo descubre al final.

> **⚠ Hoy no cuadra — cliente y servidor sugieren con algoritmos distintos.** El servidor usa
> **distancia de Levenshtein** —cuántas letras hay que cambiar, añadir o borrar para convertir un
> texto en el otro: de `renaca` a `reñaca`, una— con umbral por largo
> (`src/ComunaDirectory.php:184-215`); el cliente usa contención por prefijo en los dos sentidos,
> quedándose con la candidata más larga (`assets/js/comuna-combobox.js:182-195`).
>
> **El ejemplo donde se separan es «Santiago Centro»**, y lo dice el comentario del propio JS
> (`assets/js/comuna-combobox.js:178-181`): el cliente sugiere «Santiago», porque la comuna es
> prefijo de lo escrito; el servidor **no sugiere nada**, porque
> `levenshtein("santiago centro", "santiago")` = 7, muy por encima del umbral 3. Los dos
> bloquean, pero **la tienda se contradice**: el navegador propone una salida y el servidor deja
> al cliente sin ninguna.
>
> (Ojo con el ejemplo contrario, que es fácil equivocarse: con «Providen» **los dos coinciden**.
> `levenshtein("providen", "providencia")` = 3 y el umbral para 8 letras es 3; la comparación es
> `if ($distance > $threshold) continue`, y 3 no es mayor que 3, así que el servidor **también**
> sugiere «Providencia».)
>
> La paridad **de comunas** entre los dos lados sólo está probada para `normalize()`
> (`tests/comuna-normalize-parity.test.js`); `suggest()` no tiene gemela. El otro gemelo del
> capítulo, el del celular, sí la tiene: `tests/checkout-phone-parity.test.js`.

---

### 6. `StoreApiIntegration` — el cable servidor → navegador

**Qué hace.** Es el cable por el que el servidor le manda al navegador todo lo que sólo él sabe del
carrito.

**Ficheros.** `src/StoreApiIntegration.php` (418 líneas).

**Cuándo actúa.**

| Hook | Prioridad | Qué hace | Línea |
| --- | --- | --- | --- |
| `woocommerce_get_item_data` | **20** | Añade las filas «Estado» y «Llegada estimada» bajo cada producto | `:86` |
| `woocommerce_blocks_loaded` | 10 | Registra las dos extensiones de la Store API, una sola vez | `:93` |

Y una sutileza que hay que leer con cuidado: **si `woocommerce_blocks_loaded` ya se disparó, el
registro es inmediato** y el `add_action` no llega a existir (`:88-92`).

**Qué publica.** Dos extensiones bajo el nombre `akibara_cart_checkout_blocks`
(`src/StoreApiIntegration.php:26`, `111-129`):

- **Del carrito entero**: avisos de pack con su tono y su insignia, estado de la barra de envío
  gratis (estado, motivo, umbral, total, restante, porcentaje), resumen de preventas y señales de
  confianza (`:141-229`).
- **De cada línea**: estado de stock canónico, su etiqueta visible, si es preventa, la fecha
  estimada y su marca de tiempo (`:262-283`).

**`SCHEMA_VERSION = 2` es el dueño único del número** (`:37`). Los dos bundles del navegador lo
comparan contra su propio literal y descartan el payload entero si no coincide
(`assets/js/cart-checkout-blocks.js:46-49`, `assets/js/checkout-inner-blocks-frontend.js:37-39`).
La prueba `PluginContractTest` es la que exige que los tres números sigan siendo el mismo. **No
viaja en el ZIP publicado**, así que en el snapshot de producción no hay forma de comprobarla:
vive en el árbol de trabajo, en
`public_html/wp-content/plugins/akibara-cart-checkout-blocks/tests/php/PluginContractTest.php`
(ver **Cómo se prueba**). En producción, el único guardián de esos tres literales es que alguien
se acuerde.

> **⚠ Hoy no cuadra — no es UN número, son DOS cosas, y el segundo literal está desprotegido en
> UN solo bundle.** El **namespace** `akibara_cart_checkout_blocks` vive en la constante
> `src/StoreApiIntegration.php:26`, y de los dos bundles del navegador cada uno lo trata distinto:
>
> - `assets/js/cart-checkout-blocks.js:5` hace
>   `var namespace = config.namespace || 'akibara_cart_checkout_blocks';`, o sea que **lee el
>   valor que el servidor le inlina** desde la constante (`src/Assets.php:163`:
>   `'namespace' => StoreApiIntegration::NAMESPACE`). El literal es sólo un respaldo para cuando
>   el inline falte. Si mañana se cambia la constante, este bundle la sigue solo.
> - `assets/js/checkout-inner-blocks-frontend.js:15` lo tiene **cableado a mano** y no recibe
>   nada del servidor: su `get_script_data()` devuelve array vacío a propósito (pieza 8).
>
> O sea que el bundle desprotegido es justo el de **los dos bloques internos del pago** —el
> resumen de preventas y las señales de confianza—: cambiar la constante los deja mudos sin un
> solo error. (El `schema_version` sí está cableado en los dos: `cart-checkout-blocks.js:46-49`
> y `checkout-inner-blocks-frontend.js:37-39`.)

**La memoización, y la llamada de al lado que nadie miró.** La disponibilidad de cada producto se
memoiza por petición con clave `id|estado_de_stock` (`:372-384`): medido en Docker el 2026-08-23
—dato copiado del comentario del fichero, `src/StoreApiIntegration.php:52`— sobre una respuesta
`GET /wc/store/v1/cart` con 2 ítems, 4 llamadas antes y 2 después, con la carga útil byte a byte
idéntica. El ahorro es marginal (~0,1 ms por llamada); se hizo porque el trabajo repetido no
tenía razón de existir.

**Dónde lo opera el dueño.** En ningún sitio: no hay interruptor. Lo que sí decide qué se publica
aquí es el contrato de presentación de Business (pieza 1 y caso 5).

**Qué ve el cliente.** Las filas «Estado» y «Llegada estimada» bajo cada producto del carrito y
del pago. Todo lo demás que viaja por este cable lo pintan los bundles de las piezas 8 y 15.

> **⚠ Hoy no cuadra — y a dos líneas hay una llamada más cara que nadie memoizó.**
> `append_stock_item_data()` calcula `$is_blocks_page = is_cart() || is_checkout()`
> **antes** de mirar si la petición es de la Store API, o sea **una vez por ítem del carrito**
> (`src/StoreApiIntegration.php:317-333`). En `/carrito/` eso llega hasta `has_block()` en cada
> ítem. No es la catástrofe que parece —WordPress cachea el post en memoria tras la primera
> vez—, pero es trabajo repetido con la respuesta ya conocida. Si se va a tocar rendimiento
> aquí, es ese el sitio, no el memo de al lado.

---

### 7. `Assets` — quién carga qué

**Qué hace.** Decide qué JavaScript y qué CSS se descargan en el carrito y en el pago, y les pasa
desde el servidor los dos textos configurables y el catálogo de comunas.

**Ficheros.** `src/Assets.php` (389 líneas).

**Cuándo actúa.**

| Hook | Prioridad | Qué hace | Línea |
| --- | --- | --- | --- |
| `woocommerce_blocks_enqueue_cart_block_scripts_after` | 10 | Bundle de presentación y su CSS | `:136` |
| `woocommerce_blocks_enqueue_checkout_block_scripts_after` | 10 | Lo mismo **más** todos los ayudantes del pago | `:137` |

Un interruptor interno `$enqueued` impide que corra dos veces (`:146-148`, `:199`).

**Qué encola.** En carrito **y** pago: `cart-checkout-blocks.js` + su CSS (`:150-192`). Sólo en el
pago: `checkout-blocks.js`, `checkout-rut.js`, `checkout-phone.js`, el combobox de comunas y el
puente de Google Places (`:194-197`, `280-293`). Cada fichero se versiona con **versión del plugin
+ `filemtime`**, para que un cambio llegue al navegador aunque la caché esté caliente.

> **Un detalle que confunde al leer el HTML del pago:** el aviso «¿No estás en Santiago?» se
> inlina en `window.akbCartCheckoutBlocks` **también en `/checkout/`** (`src/Assets.php:159-184`
> corre en las dos páginas), pero allí no lo pinta nadie: `renderShippingHint()` sólo se monta
> en el carrito (`assets/js/cart-checkout-blocks.js:263`). O sea que el texto **viaja y no se
> ve**. No es un fallo, es equipaje de más; pero quien mire el HTML servido del pago y lo
> encuentre ahí va a pensar que algo está roto.

**Qué options lee** (siempre **por nombre**, nunca importando clases):

| Option / meta | Para qué | Línea |
| --- | --- | --- |
| `akibara_blocks_shipping_hint_text` | Aviso «¿No estás en Santiago?» del carrito | `:33`, `211-215` |
| `akibara_blocks_collection_notice_text` | Aviso de colección del pago | `:60`, `228-232` |
| `akb_sf_enabled` | Interruptor maestro de los avisos de tomo nuevo | `:86`, `259` |
| `_akb_sf_optout_all` (usermeta) | Baja global del cliente; **sólo se lee** | `:101`, `272` |

**La regla del texto vacío, que conviene entender:**

> **Un texto guardado VACÍO apaga el aviso.** El texto por defecto sólo entra cuando la fila
> **nunca** se guardó (`src/Assets.php:202-215`, `217-232`). Reponerlo sobre un vacío guardado le
> quitaría al dueño el único interruptor que tiene, y haría mentir a la descripción del panel
> («Déjalo vacío para apagarlo»).

**`collection_notice_applies()`** decide **en el servidor** si el aviso de colección es verdad para
quien está pagando (`:258-275`): hace falta que el maestro `akb_sf_enabled` esté encendido —su
estado de fábrica es **apagado**— y que el cliente no tenga la baja global. Un invitado (sin
cuenta) sólo depende del maestro (`:264-266`). Ninguno de los dos datos viaja al navegador, así que
la decisión no se puede tomar en el bundle.

Una sutileza que conviene tener escrita: la última línea es
`return ! ( is_scalar( $stored ) && '1' === (string) $stored );` (`src/Assets.php:274`). La
guarda `is_scalar()` está para no reventar con un warning si la usermeta llegara deserializada
como array, y la consecuencia es que **una usermeta corrupta se lee como «no se dio de baja»**:
el aviso se pinta. Es la decisión correcta —el checkout no puede callarse por un dato
corrupto— pero es también el único camino por el que un cliente dado de baja vería el aviso.

**Dónde lo opera el dueño.** *Akibara → Carrito*, en el panel de **akibara-business**: el campo
«Aviso ¿No estás en Santiago? (carrito de bloques)» y el campo «Aviso de colección (checkout de
bloques)» (`akibara-business/src/Modules/NativeCart/Settings.php:356-369`).

**Qué ve el cliente.** Nada de esta pieza directamente: `Assets` es quien decide qué ficheros se
descargan. Lo que el cliente ve son los dos avisos cuyo texto sale de aquí, y la lista de comunas
que existe porque aquí se encoló.

**Los tres puntos de extensión por código de este plugin.** No hay sólo uno. Son tres filtros, y
los tres son de primera parte —los pusimos nosotros, para nosotros—:

| Filtro | Dónde | Qué cambia |
| --- | --- | --- |
| `akibara_comuna_directory` | `src/ComunaDirectory.php:326-339` | El catálogo de comunas. Devolver `array()` apaga combobox y validación: es el interruptor de emergencia |
| `akibara_blocks_shipping_hint_text` | `src/Assets.php:177-180` | El texto del aviso «¿No estás en Santiago?» |
| `akibara_blocks_collection_notice_text` | `src/CheckoutBlockIntegration.php:171-174` | El texto del aviso de colección |

Los dos últimos **corren después** de resolver el texto guardado, así que un filtro puede volver
a encender un aviso que el dueño dejó vacío en el panel. No son teóricos: el propio tema los
nombra en su código al explicar qué se llevó de ahí
(`themes/akibara-gp/inc/woocommerce.php:555-556`). La diferencia entre los dos: el del aviso de
colección sólo llega a ejecutarse **si el aviso ya aplicaba** —el `apply_filters` está dentro de
la rama `$applies` (`src/CheckoutBlockIntegration.php:170-176`)—, así que **cambia el texto pero
no puede reencender un aviso que el maestro o el cliente apagaron**. El del envío no tiene ese
resguardo.

> **⚠ Hoy no cuadra — el panel promete y el código apaga.** La descripción del campo dice
> literalmente «Se ve en el checkout, encima del botón de pagar, **a todos los clientes**»
> (`akibara-business/src/Modules/NativeCart/Settings.php:368`), y el código exige `akb_sf_enabled`,
> cuyo estado de fábrica es apagado (`src/Assets.php:259-262`; y en Business,
> `SeriesFollow::option_is_enabled()` también parte de `false`). El dueño edita el texto, recarga,
> no ve nada y concluye que está roto.

> **⚠ Hoy no cuadra — la mitad copiada del criterio.** `SeriesFollow::enabled()` de Business es
> `apply_filters('akb_sf_enabled', option_is_enabled(...))`; `collection_notice_applies()` sólo hace
> `get_option()` (`src/Assets.php:259`). En una sesión de QA que encienda los avisos por el filtro,
> el módulo manda correos mientras el checkout se calla — y al revés.

> **⚠ Hoy no cuadra — los dos textos por defecto están duplicados byte a byte en dos plugins.**
> `src/Assets.php:42` y `:69` frente a
> `akibara-business/src/Modules/NativeCart/Settings.php:103` y `:115`. Hoy coinciden (comprobado).
> El día que alguien edite uno, el panel mostrará un texto y el checkout servirá otro mientras la
> fila de la option no exista, y nadie verá la diferencia sin leer el HTML servido.

---

### 8. `CheckoutBlockIntegration` + los dos bloques internos

**Qué hace.** Crea dos piezas propias que se insertan **dentro** del bloque de campos del pago:
`akibara/checkout-preventa-summary` y `akibara/checkout-trust-signals`.

**Ficheros.** `src/CheckoutBlockIntegration.php` (226 líneas),
`assets/js/checkout-inner-blocks-editor.js` (107), `assets/js/checkout-inner-blocks-frontend.js` (176).

**Cuándo actúa.**

| Hook | Prioridad | Qué hace | Línea |
| --- | --- | --- | --- |
| `woocommerce_blocks_checkout_block_registration` | 10 | Se da de alta en el registro de integraciones del bloque de pago | `:40` |
| `init` | 10 | `register_block_type()` de los dos bloques, en PHP | `:41` |

**La lección grabada en este fichero: `registerCheckoutBlock()` en JavaScript no basta**
(`src/CheckoutBlockIntegration.php:44-66`). WordPress recorre el contenido de la página con
`render_block()` y **descarta todo bloque que no esté registrado en PHP**, antes de que el HTML
salga hacia el navegador: no emite el envoltorio y el componente nunca tiene dónde montarse. Del
2.1.0 al 2.2.x los dos bloques existieron con componente, estilos y registro JS, y **nunca se
vieron en producción**; el script se descargaba en cada pago —clientes pagando el peso— para
registrar dos componentes que jamás montaban. El arreglo es `register_block_type()` con un
`render_callback` que emite el mismo envoltorio que usa WooCommerce
(`<div data-block-name="akibara/…" class="wp-block-akibara-…"></div>`, `:86-92`).

**Y hay una TERCERA pieza sin la cual siguen sin poder usarse.** El bundle del editor registra un
filtro `additionalCartCheckoutInnerBlockTypes` que añade los dos bloques propios —y
`google-login/login-button`— a la lista de hijos permitidos de
`woocommerce/checkout-fields-block` (`assets/js/checkout-inner-blocks-editor.js:94-105`). Sin él
los bloques **ni siquiera aparecen como insertables** en el editor. Quien reproduzca «el arreglo»
con dos de los tres pasos se queda exactamente donde estaba.

> **Trampa al verificar:** el atributo `data-block-name` **desaparece del DOM después de hidratar**.
> Buscarlo con `querySelector` devuelve `null` aunque todo funcione. Hay que buscar la clase que
> pinta el componente: `.akb-checkout-preventa`, `.akb-blocks-trust`.

**El aviso de colección viaja inlinado, no por el payload** (`src/CheckoutBlockIntegration.php:161-179`):
es una option del panel, no depende del carrito, y el bloque tiene que poder pintarlo aunque la
extensión falte o cambie de esquema. Cuando no aplica se inlina la cadena vacía, que el bundle ya
trata como «no pintar» (`assets/js/checkout-inner-blocks-frontend.js:135-147`).

**`get_script_data()` devuelve array vacío a propósito**, y hay una prueba que lo exige
(`:209-225`): WooCommerce imprime lo que devuelva ahí como ajustes del bundle en **cada** pago, y
emitía `namespace` + `schemaVersion` que ningún JS leía.

**Dónde lo opera el dueño.** Los dos bloques aparecen en el editor de la página de pago, dentro del
bloque «Campos de pago», con un marcador de posición (`assets/js/checkout-inner-blocks-editor.js:60-92`).

**Qué ve el cliente.** El resumen «Tu pedido incluye preventas» y la grilla de tres beneficios con
el aviso de colección debajo, dentro de la columna de campos del pago.

> **Medido en producción el 2026-09-12** (`ssh akibara`, `wp post get 9083 --field=content`): los
> dos bloques **están puestos** en el contenido de la página de pago, como
> `<!-- wp:akibara/checkout-preventa-summary /-->` justo después del bloque de pago exprés y
> `<!-- wp:akibara/checkout-trust-signals /-->` justo antes del bloque de acciones. O sea que los
> tres pasos del arreglo están completos en el sitio real, no sólo en el código.

> **⚠ Hoy no cuadra — el editor declara un candado que el servidor no conoce.** El bundle del
> editor registra `attributes: { lock: { type:'object', default:{ remove:true, move:true } } }`
> (`assets/js/checkout-inner-blocks-editor.js:37-45`); el `register_block_type()` del servidor no
> declara `attributes` ninguno (`src/CheckoutBlockIntegration.php:81-99`). Hoy no se nota porque el
> `render_callback` ignora los atributos, pero es la otra mitad del mismo problema que este fichero
> cuenta: lo que el editor declara y el servidor no.

> **⚠ Hoy no cuadra — el aviso de colección se calcula POR CLIENTE y viaja en el HTML del pago.**
> El valor se decide con `get_current_user_id()` (`src/CheckoutBlockIntegration.php:159`) y se
> inlina en `window.akbCheckoutInnerBlocks`. La única garantía escrita es la frase del docblock «El
> checkout nunca se sirve desde caché de página» (`:152`), que es una propiedad del entorno
> —Cloudflare, LiteSpeed— y no algo que este código imponga. Si una regla de caché cambiara, un
> cliente que se dio de baja vería el aviso, o al revés.

---

### 9. `AddressIntegration` + `GooglePlacesAddressProvider`

**Qué hace.** Da de alta a Google Places como el buscador de direcciones que WooCommerce usará en
el pago, y enciende esa función nativa **sólo ahí**.

**Ficheros.** `src/AddressIntegration.php` (145 líneas), `src/GooglePlacesAddressProvider.php` (33).

**Cuándo actúa.**

| Hook | Prioridad | Qué hace | Línea |
| --- | --- | --- | --- |
| `woocommerce_address_providers` | 10 | Registra el proveedor `akibara-google-places`, sin duplicarlo | `:46`, `58-77` |
| `pre_option_woocommerce_address_autocomplete_enabled` | 10 | Devuelve `'yes'` **sólo** en el pago de bloques y sólo si Akibara lo tiene encendido | `:47`, `87-93` |

`enqueue()` no es un hook: lo llama `Assets` cuando ya sabe que estamos en el pago
(`src/Assets.php:196`).

**Qué lee y escribe.** Lee `address_autocomplete_config()` del contrato de Business (encendido,
clave y mapa de regiones). No escribe nada. El interruptor real es
`akibara_address_autocomplete_enabled`, del módulo `AddressAutocomplete` de akibara-business; la
option nativa de WooCommerce se **cortocircuita**, no se guarda.

**El identificador del proveedor se publica desde una sola constante**
(`GooglePlacesAddressProvider::ID`, `src/AddressIntegration.php:138`). El literal estaba copiado en
el JavaScript, y una divergencia dejaba el autocompletado muerto sin un error de PHP: sólo dejaba
de funcionar.

**`GooglePlacesAddressProvider` hace algo más que llevar ese identificador**, y es lo único del
capítulo que el cliente ve como una imagen. Además de `ID` publica el nombre visible del
proveedor —«Google Places»— y el `branding_html`: un `<img>` de 98×18 px al logo de Google Maps
(`src/GooglePlacesAddressProvider.php:24`, `27-31`). Ese logo es el fichero
`assets/images/google-maps-logo-dark-gray.svg`, la única imagen que viaja en el ZIP de este
plugin, y **WooCommerce lo pinta debajo del campo Dirección porque el acuerdo de uso de la API de
Google obliga a atribuir**. Si el fichero desaparece de un despliegue, el cliente ve una imagen
rota justo bajo el campo donde está escribiendo su dirección.

**Dónde lo opera el dueño.** *Akibara → Autocompletar dirección*
(`wp-admin/admin.php?page=akibara-panel&tab=address`), en el panel de **akibara-business**: la
casilla «Activar» (option `akibara_address_autocomplete_enabled`, de fábrica `no`) y el campo
«Google Maps API key».

**Qué ve el cliente.** El desplegable de sugerencias de dirección mientras escribe, con el logo
de Google Maps debajo del campo. Con el módulo apagado, el campo Dirección es texto normal.

> **Cómo se rota la clave de Google**, que es la operación que el capítulo tiene que permitir
> hacer sin ayuda:
>
> 1. **Dónde se escribe.** Option `akibara_google_maps_api_key`, editable en *Akibara →
>    Autocompletar dirección* (`akibara-business/src/Modules/AddressAutocomplete/AddressAutocomplete.php:28`,
>    `215-220`). Empieza por `AIza…` y tiene 39 caracteres; el panel valida el formato al guardar
>    (`:32`, filtro `pre_update_option_…`).
> 2. **La constante gana siempre.** Si `wp-config.php` define `AKB_GOOGLE_MAPS_API_KEY` con un
>    valor no vacío, ésa es la que se usa y el campo del panel **queda de sólo lectura**, con el
>    aviso «Definida en wp-config.php … Lo que escribas acá se ignora»
>    (`:43-47`, `199`, `219`, `223-226`). Si la clave no cambia al guardar en el panel, mira ahí
>    antes que en ningún otro sitio.
> 3. **La restricción, que es la única protección real.** El propio panel la dice: restringir la
>    clave a los referentes `https://akibara.cl/*` en la consola de Google Cloud (`:220`). Eso se
>    hace **fuera de este repositorio**, en
>    `https://console.cloud.google.com/google/maps-apis/credentials`.
> 4. Tras cambiarla, recargar `/checkout/` y comprobar que la clave nueva aparece dentro de
>    `window.akbCartCheckoutBlocksGoogle.loaderUrl` en el HTML servido.

> **⚠ Hoy no cuadra — la clave de Google viaja en el HTML de cada pago.** Va dentro de `loaderUrl`
> (`src/AddressIntegration.php:119-141`). Es inevitable con una API de navegador, y la única
> protección posible es la restricción por referente y por API en la consola de Google, que hay que
> verificar fuera de este repositorio. Dato útil: el script de Google **no se carga hasta que
> alguien escribe** (`loadPlacesLibrary()` sólo se invoca desde `search()`,
> `assets/js/google-places-address-provider.js:815`), así que el coste de red es cero hasta el
> primer tecleo; lo que sí viaja siempre es la clave.

---

### 10. `google-places-address-provider.js` — el puente en el navegador

**Qué hace.** Busca sugerencias en Google, las muestra, y al elegir una rellena calle, número,
comuna y región.

**Ficheros.** `assets/js/google-places-address-provider.js` (926 líneas).

**Cuándo actúa.** Se registra en `window.wc.addressAutocomplete` cuando WooCommerce ya publica el
proveedor del servidor, con hasta 200 reintentos de 25 ms (`:905-925`).

**Hace bastante más que llamar a Google:**

- Carga el script de Google **bajo demanda** y reintenta mientras la librería se inicializa
  (80 × 25 ms, `:18-19`).
- Mantiene el **token de sesión** de Google —obligatorio para su modelo de precio por sesión— y lo
  recicla tras 3 minutos de inactividad (`:17`, `736-`).
- Pinta estados que WooCommerce **no trae**: *buscando direcciones…*, *sin resultados*, *error de
  red*, *completando dirección…*, con `role`/`aria-live` y `aria-describedby` en el campo
  (`:262-300`, `809`, `880-886`).
- Coloca el panel de sugerencias arriba o abajo midiendo el viewport visual, igual que el combobox
  de comunas (`:131-260`).
- Limita la búsqueda a Chile y al español, desde **3 caracteres** (`:799-828`).
- Traduce la región de Google a `CL-XX` con el mapa del servidor más una tabla de alias
  (`:20-44`, `568-594`), normalizando el nombre (sin tildes, sin comillas, sin el prefijo «Región
  de/del», `:557-566`).
- **Resuelve la comuna cruzando lo que devuelve Google contra el catálogo real de esa región**
  (`administrative_area_level_3`, `locality`, `sublocality`), descartando el nombre de la provincia
  y exigiendo **una sola** coincidencia (`:630-669`).

> **⚠ Hoy no cuadra — elegir una dirección de Google BORRA lo que el cliente ya había escrito, y
> no sólo la comuna.** `parseAddressComponents()` inicializa seis claves y devuelve las seis
> siempre (`assets/js/google-places-address-provider.js:671-679`, `723-725`). `address_2` queda en
> cadena vacía en cuanto la dirección elegida no trae `subpremise` —o sea, en la mayoría de las
> direcciones chilenas (`:697-699`)—; `postcode` queda vacío si Google no devuelve código postal
> (`:715-717`); `city` queda vacío si el cruce no es inequívoco (`:668`). WooCommerce aplica el
> objeto devuelto sobre la dirección actual, así que esas cadenas vacías **pisan lo ya escrito**.
> El que más duele es el número de departamento: un paquete sin «Depto 42» vuelve.

> **⚠ Hoy no cuadra — sin catálogo de comunas, el buscador de Google deja la comuna SIEMPRE vacía
> y no lo dice.** `communeName()` devuelve `''` en cuanto `window.akbComunaData.regions[region]` no
> es un array con contenido (`:630-634`), y ese global sólo se inlina si el directorio está
> disponible (`src/Assets.php:338-341`). Las dos degradaciones se multiplican: sin catálogo no hay
> combobox, no hay validación, y además el buscador vacía la comuna en cada selección.

---

### 11. `PudoIntegration` — quién manda en el retiro en punto

> **Errores en castellano (R4-42974e1f, 2.2.23).** `pudo-blocks.js` sólo muestra el mensaje del servidor si el
> `code` es uno de los 12 que emite `throw_route_error()` (lista comparada con el PHP por la prueba); un
> `TypeError` de red o el JSON inválido de apiFetch caen al mensaje en castellano.

**Qué hace.** Sustituye la integración de retiro del plugin de Blue Express, valida en el servidor
el punto que elige el cliente, y escribe en el pedido los datos definitivos.

**Ficheros.** `src/PudoIntegration.php` (829 líneas).

**De dónde sale la tarifa «Retiro en Punto Blue Express», que este plugin NO crea.** Es un
**método de envío** del plugin de Blue Express, con identificador `bluex-pudo`, que el dueño da
de alta en *WooCommerce → Ajustes → Envío*, dentro de una zona. Medido en producción el
2026-09-12 (`wp wc shipping_zone_method list`): está activo en dos zonas —«Todo Chile - Blue
Express» (zona 5, instancia 128) y «Región Metropolitana - Blue Express» (zona 7, instancia
112)—, junto al despacho a domicilio `bluex-ex` y, sólo en la Metropolitana, el
`local_pickup` «Retiro en Metro San Miguel». Akibara **nunca añade** esa tarifa: lo único que
hace es **quitarla** cuando el servicio no la avala (`filter_package_rates`, `:256-275`). Si
mañana «no aparece el retiro en punto», antes de mirar este plugin hay que mirar si la tarifa
sigue activa en su zona.

> **Baranda del dueño, y va en serio:** en la pantalla de Blue Express → Zonas **NUNCA se pulsa
> «Aplicar»**. Ese botón reescribe las zonas de envío desde su propia configuración y se lleva
> por delante lo consolidado a mano, incluida la zona sin despacho de Isla de Pascua y Juan
> Fernández. Para tocar una tarifa se entra por *WooCommerce → Ajustes → Envío*, no por ahí.

**Cuándo actúa — la secuencia completa, en el servidor:**

| # | Hook | Prioridad | Qué hace | Línea |
| --- | --- | --- | --- | --- |
| 1 | `woocommerce_blocks_loaded` | **1000** | Registra el canal de escritura `bluex_pudo`, pisando el de Blue (@10) | `:74`, `82-95` |
| 2 | `woocommerce_cart_shipping_packages` | `PHP_INT_MAX` | Reconcilia el estado PUDO y mete la huella `_akb_pudo_generation` en cada paquete, **antes** de que WooCommerce calcule la clave de caché de tarifas | `:64`, `220-241` |
| 3 | `woocommerce_package_rates` | 999 | Deja pasar la tarifa PUDO sólo mientras el servicio la avala | `:65`, `256-275` |
| 4 | `POST /cart/extensions` con namespace `bluex_pudo` | — | `handle_store_api_update()` | `:104-174` |
| 5 | `woocommerce_store_api_cart_select_shipping_rate` | 100 | Si el cliente elige otro método, invalida la selección | `:63`, `185-210` |
| 6 | `woocommerce_store_api_checkout_update_order_from_request` | **1** | Antes que Blue (@10): valida otra vez, reescribe `extensions`, escribe la dirección y las metas | `:62`, `285-339` |
| 7 | `wp_enqueue_scripts` | 999 | Desencola los ficheros de PUDO de Blue en el pago | `:66`, `346-353` |
| 8 | `woocommerce_blocks_enqueue_checkout_block_scripts_after` | 10 | Encola el SlotFill propio | `:67`, `360-385` |

> **⚠ Hoy no cuadra — la prioridad 1000 probablemente no interviene en nada.** `register()`
> comprueba `did_action('woocommerce_blocks_loaded')` y, si ya corrió, **registra inmediatamente**
> (`src/PudoIntegration.php:69-75`; mismo patrón en `src/StoreApiIntegration.php:88-93`). Como este
> plugin arranca en `plugins_loaded` @30 y WooCommerce en @10, ése es el camino probable en
> producción: lo único que hace ganar a Akibara es **llegar después**.
>
> **Aquí «ganar» no quiere decir lo mismo que en el resto del capítulo.** La prioridad de un
> `hook` decide *cuándo corre cada manejador* y todos corren. Esto es otra cosa: WooCommerce
> guarda los canales de escritura en un array **indexado por nombre**, así que el segundo
> registro **sobrescribe** al primero y el de Blue deja de existir. Lo primero ordena *cuándo*;
> lo segundo decide *cuál*.
>
> Lo que sí hay es una prueba que vigila los **literales**:
> `tests/php/VendorPudoContractTest.php` fija los dos handles que se desencolan, el namespace
> `bluex_pudo`, el origen `https://widget-pudo.blue.cl` con el tipo `pudo:select`, y la versión
> de Blue contra la que se revisó (3.3.1, el 2026-09-12). Lo que **nadie comprueba es quién gana
> el registro en tiempo de ejecución**: no hay comprobación en vivo ni una línea de registro si
> el manejador permisivo de Blue acabara mandando. La prueba mira los nombres, no el orden.

**Lo que el navegador puede mandar** son exactamente cuatro claves: `agency_id`, `address`,
`region_code` y `attempt_id`. Cualquier clave extra invalida la petición entera
(`akibara_pudo_untrusted_payload`, `:444-447`). Los formatos están fijados: agencia ≤ 64 caracteres
con una gramática cerrada, dirección ≤ 200, región exactamente `CL-XX`, intento exactamente 32
hexadecimales en minúscula (`:459-467`).

**La dirección de destino que se le manda a Blue sale siempre del paquete de envío del servidor**
(`:483-500`), nunca de lo que mandó el navegador.

**Guardas previas a preguntarle nada a Blue:** un solo paquete de envío (`:611-618`), PUDO como
único método elegido (`:625-633`), y destino chileno con región válida (`:483-494`).

**Al confirmar el pedido** se escriben `agencyId`, `isPudoSelected = 'pudoShipping'`, `agencyName`
y `agencyAddress`, y la dirección de envío se sustituye por la del punto (`:719-750`). Una
selección con nombre **o dirección vacíos** se considera inválida (`:535-551`): con el valor vacío
el escritor del proveedor caía a su copia en sesión —sembrable por el comprador vía el AJAX clásico
`update_order_review`— y la escribía en el meta del pedido (auditoría de seguridad 2026-07-28).

**Blue puede renombrar la línea de envío** de `bluex-pudo` a `bluex-ex` antes de que corra su
propio hook de persistencia, dejando el original en la meta `_bluex_original_method`. Por eso
`order_uses_pudo()` reconoce las dos formas (`:664-694`).

**Dónde vive el estado autoritativo de PUDO, que es lo primero que hay que mirar cuando «se
pierde el punto de retiro».** No está en la sesión ni en el pedido: está en **options de
`akibara-business` con el prefijo `akb_pudo_state_`**
(`akibara-business/src/Integrations/Bluex/Pudo/PudoMutationRepository.php:31`). La clave completa
es ese prefijo más 64 caracteres hexadecimales —un HMAC-SHA256 del identificador de sesión del
comprador, `PudoMutationGuard::option_key()`, `:447`—, de modo que **hay una fila por sesión con
una selección en vuelo**, y no se puede adivinar desde fuera a qué cliente pertenece. Sobre esas
filas trabaja el `compare_and_swap` que produce los 409 de «otra pestaña está confirmando».

Medido en producción el 2026-09-12: **3 filas** vivas.

```bash
ssh akibara 'cd ~/domains/akibara.cl/public_html && \
  wp db query "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE \"akb_pudo_state_%\"" \
  --skip-column-names'
```

**Dónde lo opera el dueño.** En ninguna pantalla propia. Las tres superficies que sí existen son
de otros: la tarifa, en *WooCommerce → Ajustes → Envío*; el registro de fallos, en *WooCommerce →
Estado → Registros* (pieza 13); y el pedido ya creado, en la pantalla de pedido, donde se ven las
cuatro metas `agencyId` / `isPudoSelected` / `agencyName` / `agencyAddress`. **No hay forma de
crear a mano un pedido de retiro** con esas metas validadas: el selector de agencia sólo se
encola en el pago de bloques (`:360-365`). Queda anotado en las preguntas al dueño.

**Qué ve el cliente.** Nada de esta pieza directamente —es todo servidor—, pero es la que decide
si la tarifa de retiro aparece en el listado de envíos, si su elección se acepta, y qué dirección
sale impresa en el correo del pedido.

> **⚠ Hoy no cuadra — ALTA: el `catch` de `filter_package_rates` vuelve a llamar al servicio que
> acaba de fallar, fuera de todo `try`** (`src/PudoIntegration.php:270-274`). `clear_selection()`
> delega en seco en el servicio de Business (`src/PudoServiceAdapter.php:108-110`), y allí hay
> puntos de lanzamiento reales. Si esa segunda llamada lanza, la excepción escapa desde un filtro
> `woocommerce_package_rates`, que corre durante el cálculo de envíos de **cualquier** página con
> carrito: el cliente ve un error 500 en vez de un carrito sin la opción de retiro. **La intención
> del bloque es degradar; escrito así puede hacer lo contrario justo cuando el proveedor ya está
> fallando.**

> **⚠ Hoy no cuadra — ALTA: `release_checkout_claim()` corre en un `finally` SIN protección**
> (`src/PudoIntegration.php:334-338`). Una excepción lanzada dentro de un `finally` **reemplaza a
> la que venía en vuelo**. Si el bloque `try` ya estaba lanzando el `RouteException` de
> `akibara_pudo_checkout_invalid` («El punto de retiro o su tarifa ya no están disponibles.
> Selecciona el punto nuevamente.»), ese mensaje se pierde y lo que sale es
> `woocommerce_rest_unknown_server_error` con estado 500 — con el cliente pulsando «Realizar el
> pedido» y la tarjeta ya escrita. Es el mismo patrón del punto anterior, en el peor sitio posible.

> **⚠ Hoy no cuadra — cuatro rutas llaman a `invalidate_selection()` sin protección**
> (`src/PudoIntegration.php:140`, `169`, `616`, `631`; la quinta, `:194`, sí está dentro de un
> `try`). Las excepciones que puede lanzar el guard de Business no son `RouteException`, así que
> WooCommerce las convierte en un 500 genérico en vez del mensaje explicativo que el propio código
> escribió a continuación. Peor: `serverMessage()` del bundle pinta el texto crudo de la excepción
> interna si cabe en 300 caracteres y no trae marcado (`assets/js/pudo-blocks.js:734-759`), así que
> el cliente puede leer literalmente *«PUDO state remained contended during mutation.»*

> **Sospecha REFUTADA:** «en el panel de administración las tarifas PUDO desaparecen». La cadena
> causal no llega hasta ahí: el único consumidor que retira tarifas es `filter_package_rates()`,
> enganchado a `woocommerce_package_rates` (`src/PudoIntegration.php:65`), y ese filtro sólo corre
> durante el **cálculo de envíos de un carrito**. La pantalla de pedido del panel no calcula
> paquetes. Lo que sí falta en el panel es el **selector de agencia** —el SlotFill sólo se encola
> en el checkout de bloques (`:360-365`)— y la escritura canónica de las metas. La pregunta real
> para el dueño no es «¿por qué desaparecen?» sino **«¿hace falta poder crear a mano un pedido de
> retiro?»**, y está anotada como tal en **Preguntas abiertas para el dueño**, al final.

---

### 12. `PudoServiceAdapter` — el envoltorio finito

**Qué hace.** Un envoltorio alrededor del servicio de PUDO de akibara-business, para que este
plugin no dependa de la clase concreta del otro.

**Ficheros.** `src/PudoServiceAdapter.php` (207 líneas).

**Cuándo actúa.** No se engancha a nada; lo llama `PudoIntegration`.

**La lista de siete métodos es EXACTAMENTE la que este transporte invoca** (`:50-68`): antes exigía
tres más que ningún llamador usaba (`select_agency`, `get_selection`, `cart_fingerprint`); que
Business renombrara cualquiera de ellos devolvía `false`, `runtime()` daba `null` y
`filter_package_rates` retiraba **todas** las tarifas PUDO. El retiro en punto desaparecía del pago
por un método que nadie llamaba. Añadir aquí un nombre que el transporte no invoque vuelve a
comprar ese riesgo.

Normaliza además los valores de vuelta (array o `null`), y `validate_checkout()` acepta la
respuesta sólo si trae `is_pudo === true` con `selection` y `checkout_claim` como arrays
(`:182-197`).

**Los siete métodos vivos, para que se puedan buscar**: `canonicalize_request`,
`clear_selection`, `invalidate_selection`, `prepare_shipping_packages`, `filter_package_rates`,
`validate_checkout` y `release_checkout_claim` (`:51-60`). Los tres retirados eran
`select_agency`, `get_selection` y `cart_fingerprint`; **`select_agency` sobrevive sólo como
etiqueta de texto** en una llamada al registro (`src/PudoIntegration.php:163`), que es por lo que
un `grep` todavía lo encuentra. Si lo ves en un diagrama o en un registro, no es una llamada.

**Dónde lo opera el dueño.** En ningún sitio.

**Qué ve el cliente.** Nada; pero si esta comprobación falla, lo que ve es un pago **sin la
opción de retiro en punto**, sin ningún mensaje que lo explique.

---

### 13. `PudoLog` — la bitácora que faltaba

**Qué hace.** Escribe en el registro de WooCommerce los fallos que las rutas de PUDO capturan.

**Ficheros.** `src/PudoLog.php` (96 líneas).

**Por qué existe.** Las seis rutas de PUDO capturaban `\Throwable` y lo tiraban. Degradar sin
romper el pago es lo correcto —el cliente cae a envío a domicilio en vez de ver una pantalla
rota— pero el dueño se quedaba sin saber que Blue Express estaba fallando: **no había ni una línea
de registro en todo el plugin**, y una caída del proveedor se veía sólo como «bajaron los retiros
en punto», semanas después (`src/PudoLog.php:1-16`).

**Dónde escribe.** *WooCommerce → Estado → Registros*, fuente `akibara-pudo` (`:29`, `61-67`). Sin
WooCommerce cargado cae al registro de PHP, y **sólo si `WP_DEBUG` está encendido** (`:69-73`).

**Cómo se busca por SSH, que es lo que hace falta para un incidente de hace semanas:**

- **Nivel:** todas las líneas salen como `error` (`src/PudoLog.php:64`: `$logger->error(...)`).
  No hay `warning` ni `info`: si el filtro de nivel de la pantalla está en «Warning», no se ve
  nada.
- **Fichero:** WooCommerce guarda cada fuente y cada día en su propio fichero dentro de
  `wp-content/uploads/wc-logs/`, con el patrón `akibara-pudo-<AAAA-MM-DD>-<32 hexadecimales>.log`.
- **Cuánto se conserva:** 30 días, que es el valor de fábrica de WooCommerce; en producción no
  hay ninguna option que lo cambie (comprobado el 2026-09-12). Un incidente de hace tres semanas
  todavía está; uno de hace cinco, no.
- **El comando:**

  ```bash
  ssh akibara 'cd ~/domains/akibara.cl/public_html && \
    ls wp-content/uploads/wc-logs/ | grep "^akibara-pudo-"'
  ssh akibara 'cd ~/domains/akibara.cl/public_html && \
    grep -h "" wp-content/uploads/wc-logs/akibara-pudo-*.log | tail -50'
  ```

**Medido el 2026-09-12: cero ficheros `akibara-pudo-*`** entre los 126 registros de producción.
Eso quiere decir que **desde que existe esta bitácora no se ha registrado ni un fallo de PUDO**;
no que no puedan ocurrir.

**Dónde lo opera el dueño.** *WooCommerce → Estado → Registros*, eligiendo la fuente
`akibara-pudo`. No hay interruptor: o hay incidentes o no los hay.

**Qué ve el cliente.** Nada: esta pieza escribe sólo para el dueño.

**El techo.** Como mucho **una línea por minuto y contexto** (`:38`, `82-95`): una caída del
proveedor afecta a todas las peticiones a la vez, y sin techo un incidente de una hora deja el
registro inservible justo cuando hace falta leerlo. Si el almacén de transients no está disponible,
registra igual: perder la señal de un incidente es peor que repetirla (`:82-85`).

---

### 14. `pudo-blocks.js` + `pudo-blocks.css` — el diálogo del mapa

**Qué hace.** La tarjeta «Punto de retiro Blue Express» y el diálogo con el mapa donde el cliente
elige su local, más el código que le manda esa elección al servidor.

![Estados del diálogo del punto de retiro](diagramas/12-checkout-blocks-campos-chilenos-y-pudo-estados-pudo-dialogo.mmd)

**Ficheros.** `assets/js/pudo-blocks.js` (1.165 líneas), `assets/css/pudo-blocks.css`.

**Cuándo actúa.** Se registra como plugin del checkout dentro de
`ExperimentalOrderShippingPackages` (`:1155-1163`), y sólo se encola en el pago
(`src/PudoIntegration.php:360-365`).

> **Sí, «Experimental» significa lo que parece, y conviene saber qué pasa el día que cambie.**
> `ExperimentalOrderShippingPackages` es el hueco que WooCommerce Blocks publica para meter
> interfaz propia junto a los métodos de envío, y lleva ese prefijo porque **WooCommerce no
> promete mantenerlo**: puede renombrarlo o retirarlo en cualquier versión menor. El bundle está
> escrito para no explotar si eso ocurre: lo lee de `wc.blocksCheckout` y, si no es una función,
> **se sale entero antes de registrar nada** (`assets/js/pudo-blocks.js:470`, `473-481`).
>
> El resultado no es un error: es que **la tarjeta del punto de retiro desaparece del pago** y el
> cliente elige la tarifa de retiro sin poder elegir local. Cómo se comprueba en treinta
> segundos, y hay que hacerlo después de cada subida de WooCommerce: abrir `/checkout/`, abrir la
> consola del navegador y escribir
> `typeof wc.blocksCheckout.ExperimentalOrderShippingPackages`. Si no responde `"function"`, la
> tarjeta ya no existe.

**El diálogo es accesible de verdad.** Foco atrapado, resto del documento inerte (`inert` +
`aria-hidden` puestos y restaurados **uno a uno** al cerrar, `:338-399`), Escape, devolución del
foco al botón que lo abrió.

**El mensaje entrante pasa cinco comprobaciones** (`:109-139`): origen exactamente
`https://widget-pudo.blue.cl`, iframe conectado, `event.source === iframe.contentWindow`, ≤ 8 KB de
UTF-8, y sólo las claves `type` y `payload` con `type === 'pudo:select'`.

**Las peticiones al servidor van por una cola serializada** (`:320-336`) para que dos cambios no se
pisen, con tope de 15 segundos (`:8`, `995-1002`) y un `attempt_id` generado con
`crypto.getRandomValues` (`:195-205`). El mapa tiene además su propio tope de carga de 12 segundos
(`:961-964`).

**El mensaje de error que se pinta es el del servidor**, no uno genérico (`:1005-1012`): el
servidor manda un texto distinto por cada causa —punto en otra región, selección reemplazada por
otra pestaña, Blue sin confirmar, conflicto de escritura— y antes se descartaban todos para mostrar
siempre «vuelve a intentarlo», que no era la solución de ninguno.

**Mientras no hay punto elegido**, el bundle publica un error en `wc/store/validation` bajo la clave
`akibara-pudo-selection` y el pedido no se completa (`:6`, `809-819`). Ese texto se anuncia como
región viva **`polite`, sin `role="alert"`**: aparece en cuanto se elige el método, o sea antes de
que el cliente haya podido hacer nada mal.

**El foco sólo se mueve en UNO de los dos estados de error, y está razonado en el código**
(`assets/js/pudo-blocks.js:820-836`). Con el pedido **dividido en más de un envío** —que el
cliente no puede arreglar desde esa tarjeta y tiene que leer sí o sí— el bundle mueve el foco al
mensaje, que se pinta con `role="alert"` y `tabIndex: -1` para poder recibirlo (`:1032-1041`).
Con «todavía no eligió punto» **no**: eso es el estado normal del primer segundo, y antes el foco
saltaba de donde estuviera la persona —a media dirección— hasta un botón que aún no había
pulsado.

**Nota de historia, ya resuelta:** durante meses la selección exigió que la región de la agencia
igualara la región de despacho. Esa regla **era invención de Akibara**, no de Blue Express, y se
eliminó. Hoy el destino sólo sirve para decirle al cliente a dónde se despacha: *«Tu pedido se
despacha a Providencia, Región Metropolitana. Busca tu punto de retiro en el mapa.»* (`:278-285`).

> **⚠ Hoy no cuadra — sin `region_code` y con un nombre de región fuera de la tabla fija, el clic
> del cliente NO HACE NADA.** `chileRegionCode()` es un diccionario de literales exactos
> (`assets/js/pudo-blocks.js:43-71`): escribe `Ibañez` —sin tilde en la *a*, donde lo correcto es
> `Ibáñez` (`:46`)—, tiene la comilla grave y la recta de O'Higgins pero no la tipográfica
> (`:55-56`), y la única forma con el prefijo «Región» que reconoce es
> `Región Metropolitana de Santiago` (`:64`, con tilde en el código: buscarla sin ella no la
> encuentra). Ninguna otra región admite ese prefijo. Cuando no encaja, `parseWidgetMessage()`
> devuelve `null` (`:162`) y el manejador sale con un `return` seco (`:979`): el cliente pincha su
> punto y el diálogo se queda exactamente igual, sin error, sin spinner, sin nada que reintentar.
> **El arreglo estructural ya está escrito en este mismo plugin**, a cuatro ficheros de distancia:
> `normalizeRegionName()` (`assets/js/google-places-address-provider.js:557-566`) absorbe esas tres
> variantes y muchas más. Añadir formas a la tabla vuelve a caducar en la siguiente variante del
> proveedor; compartir la función, no.

> **⚠ Hoy no cuadra — tras un error al guardar, volver a elegir EL MISMO punto no hace nada.**
> `markMessageProcessed()` compara el JSON de `{agency_id, address, region_code}`
> (`assets/js/pudo-blocks.js:185-193`) y el `attempt_id` se añade **después** (`:986`), así que no
> distingue reintentos. `replayRef` sólo se vacía al abrir el diálogo (`:790`) y al pulsar
> «Reintentar» (`:799`) — y «Reintentar» **recarga el iframe**, no reenvía el guardado. El cliente
> concluye que ese punto «no se puede elegir».

> **⚠ Hoy no cuadra — el iframe del proveedor corre SIN `sandbox` en la página donde el cliente
> escribe la tarjeta.** Hay `referrerPolicy` y `allow`, pero no `sandbox`
> (`assets/js/pudo-blocks.js:618-630`). Las cinco comprobaciones validan lo que **entra** por
> `postMessage`; nada limita lo que el frame de `widget-pudo.blue.cl` puede hacer hacia afuera
> (navegación del documento superior, ventanas emergentes, formularios).

**Dónde lo opera el dueño.** En ningún panel. Lo único que enciende o apaga esta tarjeta es que
la tarifa `bluex-pudo` esté activa en la zona de envío (*WooCommerce → Ajustes → Envío*, ver la
pieza 11).

**Qué ve el cliente.** La tarjeta «Punto de retiro Blue Express» bajo los métodos de envío; el
botón que abre el diálogo con el mapa de Blue; y, una vez elegido, el nombre y la dirección de su
punto. Es la superficie más visible de todo el capítulo.

---

### 15. `cart-checkout-blocks.js` — el bundle de presentación

**Qué hace.** Pinta en el carrito y en el pago todo lo que el servidor manda por la Store API, y
esconde los cupones automáticos de pack para que el cliente no vea códigos internos.

**Ficheros.** `assets/js/cart-checkout-blocks.js` (511 líneas), `assets/css/cart-checkout-blocks.css`.

**Qué pinta.** Avisos de pack (`:129-174`), barra de envío gratis con su `progressbar` accesible
(`:176-232`), aviso «¿No estás en Santiago?» (`:261-284`), resumen de preventas y señales de
confianza **sólo en el carrito** (`:371-388`: en el pago los pintan los dos bloques internos), y el
rótulo del descuento de pack (`:406-450`).

**Cuatro filtros de checkout** (`:452-482`): la clase de estado de stock de cada línea
(`cartItemClass`), el filtrado de los cupones de pack de la lista visible, y el silenciado de sus
dos avisos al aplicar y al quitar.

**Dónde lo opera el dueño.** El texto del aviso «¿No estás en Santiago?», en *Akibara → Carrito*.
Todo lo demás lo decide el contrato de presentación de Business, no este bundle.

**Qué ve el cliente.** Casi todo lo que hay de propio en el carrito: los avisos de pack, la barra
de envío gratis, el aviso «¿No estás en Santiago?», el resumen de preventas, las señales de
confianza y el rótulo del descuento de pack. Y lo que **no** ve gracias a él: los códigos internos
de los cupones automáticos de pack.

**Una asimetría de WooCommerce que costó un bug visible** (`:59-74`): los filtros `coupons`,
`showApplyCouponNotice` y `showRemoveCouponNotice` se invocan **sin** `extensions` —el argumento les
llega siempre como `{}`—, así que leerlo de ahí los hacía rendirse en silencio y el cliente veía el
código MD5 del cupón de pack como chip con su X en el carrito y el pago. La solución es leer el
snapshot del almacén de datos (`filterExtension()`, `:75-77`).

> **⚠ Hoy no cuadra — el aviso «¿No estás en Santiago?» no reconoce el retiro en Punto Blue
> Express como retiro.** `pickupOnly` sólo acepta `local_pickup` y `pickup_location`
> (`assets/js/cart-checkout-blocks.js:272-275`); `bluex-pudo` no está en la lista. Si en algún
> estado del carrito la única tarifa visible fuera la de Punto Blue Express, el aviso que existe
> justamente para que un cliente de regiones no crea que «no hay despacho» **no se pintaría**, que
> es exactamente lo que venía a evitar.

---

### 16. Los tres ayudantes pequeños del pago

**`checkout-rut.js`** (97 líneas). Gemelo en cliente de `CheckoutFields::validate_rut`. Mismo
algoritmo de dígito verificador, mismo mensaje. La clave del almacén es `contact_akibara/rut` —el
**nombre** del campo (`:14`)—, no `contact-akibara-rut` (el id del input): con el id, el almacén
guarda el error pero el componente no lo muestra.

**`checkout-phone.js`** (205 líneas). Gemelo de `CheckoutFields::validate_billing_phone`, con la
gramática de paréntesis y guiones replicada carácter a carácter (`:32-71`). El control de paréntesis
faltaba, y por eso `9(87654321` pasaba en el cliente y el servidor lo rechazaba al pagar: exactamente
el viaje de vuelta que el gemelo vino a evitar. El motivo, dicho en el propio código: el pago es una
página que se lleva **107 segundos de media** (`:8-9`).

**`checkout-blocks.js`** (82 líneas). Accesibilidad de los formularios de pago de terceros
—`aria-label` del botón de Mercado Pago (`:34-37`), `alt=""` en las imágenes sin alt del selector de
medios de pago (`:39-41`) y `title` en los tres iframes de tarjeta (`:43-53`)— y borrado de una clave
de `localStorage`/`sessionStorage` de una implementación anterior (`:4`, `19-31`). Tiene un
**invariante de prueba** que prohíbe en ese fichero `MutationObserver`, `billing_phone`,
`shipping_phone`, `contact_akibara`, `scrollIntoView` y `scrollBy`: cualquier lógica de campos va en
su propio fichero.

**Dónde lo opera el dueño.** En ningún sitio: las tres son reglas fijas, sin interruptor.

**Qué ve el cliente.** Los mensajes de error bajo el campo RUT y bajo el campo Celular, en el
momento en que sale del campo y no al final. De `checkout-blocks.js` no ve nada: su trabajo
—etiquetas para lector de pantalla en los formularios de Mercado Pago— sólo se nota con un lector
de pantalla encendido.

**Los dos gemelos usan el mismo mecanismo, y por la misma razón**: `ValidatedTextInput` de
WooCommerce ejecuta `setCustomValidity("")` y luego `checkValidity()`, así que una validez
personalizada se borra. La única vía fiable es el almacén `wc/store/validation`, escrito desde un
`focusout` a nivel de `document` — que corre **después** del manejador de React.

> **⚠ Hoy no cuadra — un campo `-phone` de cualquier otro plugin puede dejar al cliente sin poder
> pagar.** El comentario del fichero explica que `type === 'tel'` a secas no basta y que por eso se
> exige además pertenecer a uno de los dos bloques de dirección (`assets/js/checkout-phone.js:163-171`);
> pero **esa restricción vive sólo en la SEGUNDA rama**: la primera, `id.slice(-6) === '-phone'`,
> no tiene ninguna (`:176-181`). Y `storeKeyFor()` publica el error bajo el id con guiones bajos
> (`:131-134`), una clave que ningún campo de Blocks pinta. Woo Blocks bloquea el envío mientras
> `wc/store/validation` tenga **cualquier** clave en error: un fijo chileno de 9 dígitos
> («22 345 6789») escrito en un campo ajeno acabado en `-phone` deja al cliente con el botón de
> pagar bloqueado y sin ver dónde está el error.

---

### 17. `RuntimeCompatibility` — los parches y su fecha de caducidad

**Qué hace.** Parchea, siempre desde fuera y sin tocar código ajeno, defectos de terceros que
ensucian o rompen el pago de bloques; y publica una comprobación en *Salud del sitio* que avisa
cuando un parche ya sobra.

**Ficheros.** `src/RuntimeCompatibility.php` (445 líneas).

**Cuándo actúa** (`src/RuntimeCompatibility.php:117-125`):

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `litespeed_optm_uri_exc` | 10 | Añade el fragmento `'checkout'` a las URIs excluidas |
| `wp_enqueue_scripts` | 999 | `dequeue_classic_mercado_pago_assets()` |
| `wp_enqueue_scripts` | 999 | `dequeue_classic_city_select()` |
| `wp_enqueue_scripts` | **1000** | `repair_flow_blocks_dependencies()` |
| `wp_print_footer_scripts` | **0** | `repair_flow_blocks_dependencies()` **otra vez** |
| `generate_show_title` | 100 | `hide_checkout_entry_title()` |
| `site_status_tests` | 10 | Registra la prueba de Salud del sitio |

**Son TRES los parches, y ninguno es un desencolado de más.** Hubo un cuarto —
`dequeue_classic_cart_empty_reload()`— y se retiró junto con su hook en checkout 2.2.21 (2026-09-18,
hallazgo S-d334fd55); la nota histórica está al final de esta lista. Uno por uno:

- **Mercado Pago**: 15 observadores del checkout clásico, retirados del carrito de bloques
  (`:55-71`, `:146-171`). La lista es **literal a propósito**: barrer por prefijo `wc_mercadopago_`
  se llevaría también lo que Mercado Pago sí necesita en bloques (medido: en `/carrito/` la cola
  trae `wc_mercadopago_woocommerce_scripts` y `wc_mercadopago_health_monitor`, que deben
  sobrevivir; dato copiado del comentario del fichero, `src/RuntimeCompatibility.php:44`).
- **Blue Express**: `wc-city-select` (el selector clásico, que engancha selectores que en bloques no
  existen) y `bluex-blocks-city-select` (su versión de bloques **sin terminar**: su
  `filterAddressFields()` devuelve los campos intactos y la única mutación está comentada)
  (`:227-237`). Medido en Docker el 2026-08-23 sobre el HTML servido a un móvil —dato copiado del
  comentario del fichero, `src/RuntimeCompatibility.php:217-221`—: `/checkout/` traía los dos, o sea
  **el catálogo de comunas duplicado en la misma página**, casi 11 KB de más. El endpoint clásico
  de direcciones de *Mi cuenta* queda intacto.
- **Flow**: su build no empaqueta `blocks.asset.php`, así que registra `wc-flowpayment-blocks`
  **sin dependencias** y el método de pago no aparece en el pago de bloques. El parche repone las
  cinco que faltan (`:86-97`, `:244-263`). Está **registrado dos veces** —`wp_enqueue_scripts` @1000
  y `wp_print_footer_scripts` @0 (`:121-122`)— porque el registro de Flow puede llegar tarde, en el
  pie. Quien vaya a medirlo mirando sólo `wp_enqueue_scripts` concluirá que no hace nada.

> **Nota histórica — el cuarto desencolado, y por qué ya no está.** Hasta checkout 2.2.21
> (2026-09-18) había un `dequeue_classic_cart_empty_reload()` que retiraba el handle
> `akibara-cart-empty-reload` en el carrito de bloques. Era pegamento sin tercero al que pegarse: el
> tema ya había dado de baja ese script y **lo dejó escrito en su propio código** («Aquí se encolaba
> `cart-empty-reload.js` … Ese guion era del carrito CLÁSICO: la página es de bloques y no hay
> `tr.cart_item` que contar», `themes/akibara-gp/inc/enqueue.php:368-376`), así que ya nadie
> registraba el handle. El coste medido nunca fue el argumento —`BlocksPage::is_cart()` sale por su
> primera línea cuando la página no es el carrito (`src/BlocksPage.php:45`), o sea una llamada a
> función, no una resolución de post—: el motivo fue la REGLA DE ORO del dueño, cero código muerto,
> el pegamento muere con su motivo. **Se retiraron el método y su hook** (hallazgo S-d334fd55), y hoy
> `grep -rn "cart_empty_reload"` sobre `wp-content` no devuelve nada fuera de esta nota y de la
> prueba del tema que fija su ausencia (corregido 2026-09-20, ronda 10: este capítulo aún lo
> contaba como vivo y pedía retirarlo).

**La idea que merece la pena copiar en otros módulos.** *Un parche que sobrevive a su motivo es
deuda invisible*, así que hay una prueba de *Salud del sitio* (`akibara_blocks_third_party_patches`,
`:290-390`) que mira el **código instalado** del tercero —no la cola de scripts, que en el panel no
existe— y dice si cada parche sigue haciendo falta. Para Mercado Pago cruza dos cosas: cuántos
handles **declara** el tercero (`:413-437`) y cuántos **había realmente encolados** la última vez que
ese número cambió. Esto último lo anota el propio carrito en la option
`akb_blocks_mp_dequeue_observed`, que **sólo se reescribe cuando el número se mueve** (`:199-212`):
por eso el sello es el del último **cambio**, no el de la última carga, y la pantalla tiene que
decirlo así o a los seis meses de operación normal informará «hace 6 meses» de un carrito que se
carga a diario.

> **⚠ Hoy no cuadra — el listado de Salud del sitio escapa su propio marcado.** La línea es
> `'… <code>' . esc_html( implode( '</code>, <code>', $missing ) ) . '</code>.'`
> (`src/RuntimeCompatibility.php:365`): `esc_html()` se aplica a la cadena **ya unida**, así que
> escapa también los separadores. Con dos o más handles ausentes, el dueño lee literalmente
> `wc_mercadopago_supertoken&lt;/code&gt;, &lt;code&gt;wc_mercadopago_custom_page`. Es justo la
> pantalla que existe para que un parche no sobreviva a su motivo: **se vuelve ilegible el día que
> tiene algo que decir**. El orden correcto es escapar cada elemento y luego unir.

> **⚠ Hoy no cuadra — una option sin ninguna pantalla de edición.**
> `akb_blocks_mp_dequeue_observed` (`:37`) no tiene campo en ningún panel. Atenuante: es
> diagnóstico y sí tiene superficie de **lectura** en Salud del sitio. Pero si queda con un valor
> viejo, la pantalla miente y no hay dónde borrarla. Es tensión directa con la REGLA DE ORO «todo
> operable sin código»; un botón «volver a medir» lo cerraría.

> **⚠ Hoy no cuadra — se escribe una option desde tráfico anónimo, sin candado.**
> `record_mercado_pago_dequeue()` hace `get_option()` no autocargada en cada carga de `/carrito/` y
> un `update_option()` cuando el número cambia (`:199-212`), sin lectura-comparación-escritura
> atómica. Dos cargas simultáneas del carrito pueden pisarse y el sello `at` queda en cualquiera de
> las dos.

> **⚠ Hoy no cuadra — la exclusión de LiteSpeed usa el fragmento suelto `'checkout'`**
> (`src/RuntimeCompatibility.php:136-140`). `litespeed_optm_uri_exc` compara por fragmento, no por
> ruta: cualquier URI que contenga esa palabra —un post con `checkout` en el slug, una landing—
> pierde la optimización sin que nadie lo note.

**Dónde lo opera el dueño.** *Herramientas → Salud del sitio*, donde lee si cada parche sigue
haciendo falta (caso 7). No hay ningún interruptor para apagar un parche: se apagan quitando el
código cuando la prueba dice que sobran.

**Qué ve el cliente.** Nada, y ése es el punto: lo que ve es un carrito **sin** 15 guiones de
Mercado Pago que no le sirven, un pago **sin** el catálogo de comunas de Blue duplicado, y el
método de pago de Flow **presente** en vez de ausente.

> **⚠ Hoy no cuadra — dos fuentes de verdad para la misma decisión, en el mismo filtro.**
> `hide_checkout_entry_title()` (`src/RuntimeCompatibility.php:447-449`, enganchado en `:124`)
> devuelve `false` en el pago de bloques; y el tema ya devuelve `false` para los slugs `carrito` y
> `checkout` sobre el **mismo** filtro `generate_show_title`
> (`themes/akibara-gp/inc/page-header.php:42-61`). El matiz que decide cuál sobra: el tema empareja
> por **slug** (`is_page('checkout')`) y el plugin por `BlocksPage::is_checkout()` —página de pago
> de WooCommerce **más** presencia del bloque—. La del plugin es la robusta, sobrevive a un cambio
> de slug; si se elimina una, debería ser la del tema.

---

### 18. `Shortcodes`

**Qué hace.** Dos atajos que se escriben en el contenido de la página del carrito.

**Ficheros.** `src/Shortcodes.php` (58 líneas).

- `[akibara_cart_blocks_title]` devuelve `<header class="akb-page-title"><h1>Tu carrito</h1></header>`,
  escapado (`:38-43`).
- `[akibara_cart_blocks_empty]` renderiza la plantilla `cart/cart-empty.php`, que el tema
  sobrescribe (`:52-57`).

**Dónde lo opera el dueño.** Editando el contenido de la página `/carrito/` en el editor de
WordPress: el shortcode se escribe ahí, como un bloque de atajo más.

**Qué ve el cliente.** El título «Tu carrito» encima del carrito, y el mensaje de carrito vacío
con el diseño del tema.

> **Duda del mapa de lectura, CERRADA con una medición.** Se sospechaba que el H1 de la pantalla
> de pago pudiera estar diciendo «Tu carrito», porque el shortcode devuelve un literal fijo y no
> acepta atributos (`src/Shortcodes.php:38-43`), y porque el tema afirma **dos veces** en su
> propio código que ese mismo shortcode pone el título en `/carrito/` **y** en `/checkout/`
> (`themes/akibara-gp/inc/page-header.php:12-15` y
> `themes/akibara-gp/inc/woocommerce.php:558-559`).
>
> **No es así.** Leído el contenido de las dos páginas en producción el 2026-09-12
> (`ssh akibara`, `wp post get <id> --field=content`; carrito = 9082, pago = 9083):
>
> - `/carrito/` (9082) empieza con `<!-- wp:shortcode -->[akibara_cart_blocks_title]`, o sea que
>   su H1 **sí** sale de aquí y dice «Tu carrito».
> - `/checkout/` (9083) **no lleva el shortcode**: lleva un bloque `wp:html` con el encabezado
>   escrito a mano, `<header class="akb-page-title"><h1>Finalizar compra</h1></header>`.
>
> O sea que el cliente ve «Finalizar compra» en la pantalla donde paga, y eso es lo que indexa el
> buscador. Lo que sí queda pendiente es menor y de higiene: **los dos comentarios del tema están
> obsoletos** y mandan a quien los lea a una conclusión falsa.
>
> Cómo repetirlo:
>
> ```bash
> ssh akibara 'cd ~/domains/akibara.cl/public_html && \
>   wp post get $(wp option get woocommerce_checkout_page_id) --field=content' | head -5
> ```

---

## Lo programado

**No hay diagrama de cron en este capítulo, y es a propósito: no hay nada que dibujar.**

**Este plugin no programa nada.** Ni un `wp_schedule_event`, ni una acción de Action Scheduler, ni
un cron propio. Todo lo que hace ocurre **dentro de la petición del cliente**: una carga de
`/carrito/` o `/checkout/`, o una llamada de la Store API que el propio navegador dispara.

Hay tres cosas que *parecen* programadas y no lo son:

| Trabajo | Mecanismo | Frecuencia y hora Chile | Quién lo programa | Qué hace | Si corre dos veces |
| --- | --- | --- | --- | --- | --- |
| Techo del registro de PUDO | `transient` `akb_pudo_log_<md5(contexto)>`, 60 s (`src/PudoLog.php:38`, `82-95`) | No tiene hora: se crea al primer fallo de cada contexto y caduca solo | Nadie: lo crea la propia petición que falla | Impide más de una línea por minuto y contexto en *WooCommerce → Estado → Registros* | Inofensivo: la segunda lectura ve el transient y no registra |
| Sello del desencolado de Mercado Pago | `update_option( 'akb_blocks_mp_dequeue_observed' )` (`src/RuntimeCompatibility.php:199-212`) | Cada carga de `/carrito/` **sólo si el número cambió** | Nadie: la dispara el tráfico del carrito | Deja constancia de cuántos handles había realmente encolados | Dos cargas simultáneas pueden pisarse: el sello queda en cualquiera de las dos (ver el aviso de la pieza 17) |
| Invalidación de tarifas cacheadas | `$session->__unset('shipping_for_package_N')` (`src/PudoIntegration.php:791-799`) | Al guardar o borrar un punto de retiro | Nadie: la dispara el clic del cliente | Fuerza a WooCommerce a recalcular el precio del envío | Idempotente: borrar una clave que ya no existe no hace nada |

**El cron de producción, para quien venga de otro capítulo:** el sitio tiene `DISABLE_WP_CRON` y
Hostinger empuja `wp-cron` cada 5 minutos. Nada de este plugin depende de eso.

---

## Cómo se prueba

**Las pruebas de este plugin viven dentro del propio plugin, no en `tools/`.** Están en
`public_html/wp-content/plugins/akibara-cart-checkout-blocks/tests/`, y **no viajan en el ZIP
publicado**: por eso en el snapshot de producción no aparecen.

| Dónde | Cuántas | Qué cubren |
| --- | --- | --- |
| `tests/php/` | **12 clases** de PHPUnit (más `tests/php/fixtures/`, 4 dobles de WooCommerce y del entorno) | `PluginContractTest` (los tres literales del `SCHEMA_VERSION`), `ComunaDirectoryTest`, `CheckoutFieldsTest`, `CheckoutFieldsComunaTest`, `StoreApiIntegrationTest`, `PudoIntegrationTest`, `RuntimeCompatibilityTest`, `AddressIntegrationTest`, `ShippingHintCopyTest`, `CollectionNoticeCopyTest`, y los dos contratos contra Blue Express: `VendorCommunesContractTest` y `VendorPudoContractTest` |
| `tests/*.test.js` | **10 ficheros** de `node --test` | Un fichero por bundle, más las dos paridades cliente/servidor: `comuna-normalize-parity.test.js` y `checkout-phone-parity.test.js` |

**Cómo se corren las dos tandas, con un solo comando**, que es el gate local del release:

```bash
bash tools/akibara-releases/docker-checkout.sh
```

Ese guion hace, en este orden: comprobar que el árbol del plugin no tiene respaldos ni basura,
correr `node --test` sobre los diez ficheros `tests/*.test.js` (línea 121), correr PHPUnit dentro
de Docker con `tools/akibara-business/phpunit.xml.dist` (líneas 122-131) y verificar el contrato
del paquete. Necesita Docker levantado.

**Dos avisos para no sacar conclusiones falsas:**

- `VendorCommunesContractTest` y `VendorPudoContractTest` leen el **árbol local** del plugin de
  Blue Express, no producción. Verde aquí no prueba nada sobre la versión que corre en el
  servidor; lo dice el propio docblock de la segunda
  (`tests/php/VendorPudoContractTest.php:24-42`). Cuando las dos versiones divergen, hay que
  repetir la comprobación contra el servidor.
- Lo que estas pruebas **no** cubren: quién gana el registro del canal `bluex_pudo` en tiempo de
  ejecución, y la paridad de `suggest()` entre el servidor y el combobox. Las dos están
  señalizadas con su ⚠ en las piezas 11 y 5.

---

## Casos de uso

### Caso 1 · «Escribí mal la comuna»

![Caso: la comuna mal escrita](diagramas/12-checkout-blocks-campos-chilenos-y-pudo-caso-comuna.mmd)

1. El cliente llega a `/checkout/`. `Assets::enqueue()` ya encoló el combobox, porque el catálogo
   de Blue estaba disponible (`src/Assets.php:338-341`), y le inlinó las 346 comunas
   (`:365-379`).
2. El combobox se engancha a `#shipping-city` (`assets/js/comuna-combobox.js:650-657`). Como React
   vuelve a montar el campo, un `MutationObserver` y una suscripción a `wp.data` reintentan el
   enganche cuantas veces haga falta (`:725-726`, `750-752`).
3. El cliente teclea `nunoa`. La lista se filtra por la región ya elegida (`rank()`, `:81-105`).
   - **Bifurcación A — elige de la lista**: se escribe el nombre canónico con el *native value
     setter*, y si esa comuna es de otra región, **el combobox le cambia la Región**
     (`:499-513`). Fin: no hay error.
   - **Bifurcación B — escribe a mano y sale del campo**: el `focusout` a nivel de documento
     (`:695-706`) llama a `validateComunaField()`, que decide si hay algo que avisar y publica el
     mensaje anclado al campo (`:359-401`).
   - **Bifurcación C — no hay catálogo**: no hay combobox, no hay canonización, no hay validación.
     El campo es texto libre y el pedido pasa. (En el diagrama esta bifurcación aparece **antes**,
     en el bloque de encolado, porque se decide antes de que el cliente escriba nada: es la rama
     «No hay catálogo».)
4. Cada vez que el navegador manda la dirección al servidor, la comuna se reescribe a su forma
   canónica **antes** de guardar el cliente y de recalcular el envío
   (`src/CheckoutFields.php:237-245`, `223-240`): `nunoa` → `Ñuñoa`, y la cotización de Blue Express
   recibe el nombre que su API acepta.
5. Al pulsar «Realizar el pedido» se canoniza otra vez la comuna del **pedido**
   (`src/CheckoutFields.php:254-262`) y después se valida (`:179-215`). Cuatro desenlaces:

| Situación | Qué ve el cliente | Línea |
| --- | --- | --- |
| Comuna válida (región vacía o coincidente) | pasa | `:195-197` |
| Comuna que existe pero es de otra región | *«"Ñuñoa" no pertenece a la región seleccionada. Revisa la región o corrige la comuna.»* | `:199-205` |
| Comuna inexistente **con** candidata en su región | *«No encontramos la comuna "Renaca". ¿Quisiste decir "Reñaca"?»* | `:207-212` |
| Comuna inexistente **sin** candidata en su región | *«No encontramos la comuna "Stgo". Selecciónala de la lista de comunas.»* | `:207-212` |

**Y una quinta situación que no está en la tabla porque no es un mensaje distinto, sino un sitio
distinto:** ese mismo recorrido se hace **dos veces**, primero sobre la dirección de **envío** y
después sobre la de **facturación** (`src/CheckoutFields.php:280`). Cualquiera de las dos con la
comuna mala produce exactamente los mismos mensajes, y el mensaje **no dice de cuál de las dos
habla**. Como el bloque de facturación suele estar plegado, el cliente puede leer «No encontramos
la comuna "Stgo"» mientras mira un campo Comuna que dice «Providencia».

**Correos que salen:** ninguno.

---

### Caso 2 · «Quiero retirar en un Punto Blue Express»

![Caso: elegir un Punto Blue Express](diagramas/12-checkout-blocks-campos-chilenos-y-pudo-caso-pudo.mmd)

1. El cliente selecciona la tarifa de retiro en punto. **Esa tarifa la ofrece el plugin de Blue
   Express** con el identificador `bluex-pudo`, porque el dueño la tiene activa en las dos zonas
   de envío del sitio (medido en producción el 2026-09-12: zonas «Todo Chile - Blue Express» y
   «Región Metropolitana - Blue Express»; ver la pieza 11). Al elegirla aparece la tarjeta «Punto
   de retiro Blue Express» (`assets/js/pudo-blocks.js:1044-1060`).
2. Mientras no haya punto, el bundle publica un error en `wc/store/validation` bajo la clave
   `akibara-pudo-selection` y el pedido no se completa (`:809-819`): *«Selecciona un Punto Blue
   Express antes de realizar el pedido.»* Se anuncia como región viva `polite`, no `assertive`:
   aparece antes de que el cliente haya podido hacer nada mal, y anunciarlo como alerta
   interrumpía al lector de pantalla para reprochar algo que nadie había hecho.
3. Pulsa el botón → diálogo modal con el mapa de Blue en un iframe hacia
   `https://widget-pudo.blue.cl` (`:618-630`). El resto del documento queda inerte (`:338-399`).
4. Elige un punto. El widget manda un `postMessage` que pasa las cinco comprobaciones
   (`:109-139`), se convierte en una petición mínima de cuatro claves y viaja al servidor por la
   cola serializada (`:761-768`, `:986-1002`).
5. El servidor rechaza cualquier clave extra (`src/PudoIntegration.php:444-447`), exige un solo
   paquete (`:611-618`) y que PUDO sea el único método elegido (`:625-633`), saca el destino del
   **paquete del servidor** (`:483-500`) y se lo pasa a akibara-business, que consulta a Blue
   (`:144-165`).
   - **Bifurcación A — lo que vuelve no es exactamente la agencia pedida**: *«Blue Express no
     confirmó el punto seleccionado. Selecciónalo nuevamente.»* (`:167-171`).
   - **Bifurcación B — carrito dividido en más de un envío**: rechazo explícito (`:611-618`).
   - **Bifurcación C — dirección sin región chilena**: *«Completa una dirección chilena válida
     antes de seleccionar el punto de retiro.»* (`:139-142`).
   - **Bifurcación D — otra pestaña confirmando**: 409 con explicación (`:152-159`).
   - **Bifurcación E — el servicio de Business no expone los siete métodos**: las tarifas PUDO
     desaparecen del listado y el cliente sólo ve despacho a domicilio
     (`src/PudoServiceAdapter.php:50-68`, `src/PudoIntegration.php:259-262`).
   - **Bifurcación F — la región del mapa no se traduce**: el diálogo **no reacciona en absoluto**
     (ver el aviso de la pieza 14).
6. Si cuadra: se borran las tarifas cacheadas de la sesión para que el precio se recalcule
   (`:791-799`) y el diálogo se cierra solo (`assets/js/pudo-blocks.js:1003-1004`).
7. La tarjeta pasa a mostrar el nombre y la dirección del punto — leídos de la extensión de
   **lectura** `bluex_pudo`, que sigue siendo la del propio plugin de Blue
   (`assets/js/pudo-blocks.js:287-297`, `683-685`). No es contradicción con el paso 4: Akibara
   sustituyó el registro de **escritura** de ese nombre y dejó intacto el de **lectura** (ver la
   pieza 11).
8. Al pagar, antes que nadie (@1), se valida todo otra vez, se reescribe `extensions`, y se
   escriben la dirección de envío y las cuatro metas del pedido
   (`src/PudoIntegration.php:285-339`, `719-750`).

**Correos que salen:** ninguno de este plugin. El correo de confirmación lo manda **WooCommerce**,
con su plantilla «Pedido procesándose», en el momento en que el pedido pasa a *procesando* — y ya
con la dirección de envío **reescrita al punto de retiro**, porque el paso 8 corrió antes.

Dónde se comprueba y dónde se edita ese correo, que no está en este plugin ni en este repositorio:

- El **asunto** y el encabezado se editan en *WooCommerce → Ajustes → Correos → Pedido
  procesándose*. Comprobado en producción el 2026-09-12: la option
  `woocommerce_customer_processing_order_settings` sólo guarda `{"email_type":"multipart"}`, o sea
  que **el asunto es el de fábrica de WooCommerce**, sin texto propio de Akibara. Si hay que
  citarlo textualmente, se lee de esa pantalla o de la bandeja, no de aquí.
- El **remitente y la marca** (nombre «Akibara», colores, cabecera) los pone el módulo
  `EmailBrand` de akibara-business
  (`akibara-business/src/Modules/EmailBrand/EmailBrand.php`), más la option
  `woocommerce_email_from_name`, que en producción vale `Akibara`.
- Para verificarlo como manda el estándar de QA: hacer el pedido de prueba y **abrir el correo en
  la bandeja**, no dar por bueno lo que dice el panel.

---

### Caso 3 · «Elegí mi dirección del buscador de Google»

![Caso: autocompletar la dirección con Google](diagramas/12-checkout-blocks-campos-chilenos-y-pudo-caso-direccion-google.mmd)

1. WooCommerce tiene el autocompletado encendido porque Akibara cortocircuita la option nativa
   mientras se sirve el pago (`src/AddressIntegration.php:87-93`).
2. El cliente escribe. A partir de **tres caracteres**, el puente pinta *«Buscando direcciones…»*
   (`assets/js/google-places-address-provider.js:809`), carga la librería de Google **por primera
   vez** (`:815`) y pide sugerencias limitadas a Chile y en español (`:824-834`).
   - **Bifurcación A — menos de tres caracteres**: no se llama a Google y se recicla el token de
     sesión (`:802-808`).
   - **Bifurcación B — error de red o API**: *«No pudimos buscar direcciones en este momento.
     Puedes escribirla manualmente y continuar.»* (`:775-785`, `860-870`).
3. Al elegir una, se piden los componentes del lugar (`:888-892`) y se traducen: calle y número a
   Dirección, `subpremise` a Dirección 2, la región de Google a `CL-XX`, y la comuna **sólo si el
   cruce con el catálogo real de esa región es inequívoco** (`:630-669`, `671-726`).
   - **Bifurcación C — el cruce no es inequívoco, o falta el catálogo**: la comuna vuelve vacía…
     y, con ella, **también el departamento y el código postal** (ver el aviso de la pieza 10).
   - **Bifurcación D — fallo al completar**: *«No pudimos completar esa dirección. Puedes
     intentarlo nuevamente o escribirla manualmente.»* (`:787-792`, `893-898`).

**Correos que salen:** ninguno.

---

### Caso 4 · «Me equivoqué en el RUT o en el celular»

1. El cliente escribe un RUT con el dígito verificador mal y sale del campo. El `focusout` a nivel
   de documento (`assets/js/checkout-rut.js:77-82`, `89-91`) calcula el dígito y publica el error
   bajo `contact_akibara/rut` (`:70-75`): *«El RUT ingresado no es válido. Verifica el formato y el
   dígito verificador.»*
2. Lo mismo con el celular (`assets/js/checkout-phone.js:184-189`), con tres mensajes distintos
   según la causa: largo, dígitos repetidos, o número que empieza en 0 o 1 (`:18-22`, `98-110`).
3. **Bifurcación A — el cliente lo ignora, o tiene el JavaScript desactivado**: el servidor rechaza
   igual al pulsar «Realizar el pedido» (`src/CheckoutFields.php:425-436`, `350-360`).
4. **Bifurcación B — el RUT es válido**: se canoniza en la entrada
   (`src/CheckoutFields.php:406-414`) y se copia formateado a `_billing_rut` (pedido) y
   `billing_rut` (cliente) (`:402-418`), de modo que la próxima compra llega con el campo relleno
   (`:372-387`).

**Correos que salen:** ninguno.

---

### Caso 5 · «Llevo preventas en el carrito»

1. El navegador pide el carrito (`GET /wp-json/wc/store/v1/cart`).
2. El servidor le pide a Business el contrato de presentación
   (`src/StoreApiIntegration.php:238-253`).
   - **Bifurcación A — la versión no es 2**: devuelve `schema_version 0` y **las superficies de
     carrito se quedan mudas a propósito** (`:244-246`): avisos de pack, barra de envío gratis,
     resumen de preventa, señales de confianza y el rótulo del descuento de pack. Sin ningún
     síntoma: 200 en todas las páginas, cero errores de PHP, cero de consola, y **ninguna línea de
     registro**. Lo que **sí** sobrevive: la clase de estado de cada línea y las filas «Estado» /
     «Llegada estimada», que no pasan por esa guarda.
   - **Bifurcación B — la versión es 2**: viajan avisos de pack, barra, resumen de preventa y
     señales, más el estado y la fecha de cada línea (`:141-229`, `262-283`).
3. En el **carrito** lo pinta el bundle (`assets/js/cart-checkout-blocks.js:371-388`); en el
   **pago** lo pintan los dos bloques internos
   (`assets/js/checkout-inner-blocks-frontend.js:42-66`, `153-166`), y bajo las señales sale el
   aviso de colección si el servidor decidió que aplica.

**Correos que salen:** ninguno aquí. Pero el aviso de colección **promete** un correo de «salió el
siguiente tomo», que manda el módulo `SeriesFollow` de akibara-business con su propia cola. Por eso
el aviso se calla cuando ese módulo está apagado o el cliente se dio de baja
(`src/Assets.php:258-275`): una superficie de consentimiento no puede afirmar lo contrario de lo
que el sitio tiene registrado sobre ese cliente.

---

### Caso 6 · «Quiero cambiar un texto» (dueño)

1. *wp-admin → Akibara → Carrito* (panel de **akibara-business**; el identificador del panel es
   `akibara-panel`, no `akibara`).
2. Editar «Aviso ¿No estás en Santiago? (carrito de bloques)» o «Aviso de colección (checkout de
   bloques)» (`akibara-business/src/Modules/NativeCart/Settings.php:356-369`) y guardar. Business lo
   sanea y lo guarda.
3. En la siguiente carga, `Assets` lo lee **por nombre de option** y lo inlina
   (`src/Assets.php:211-232`).
   - **Bifurcación A — lo deja vacío**: el aviso se apaga. Es el único interruptor **del panel**;
     por código quedan los filtros `akibara_blocks_shipping_hint_text` y
     `akibara_blocks_collection_notice_text`, que corren **después** de leer la option y pueden
     volver a poner texto donde el dueño dejó vacío (ver la pieza 7).
   - **Bifurcación B — el aviso de colección con `akb_sf_enabled` apagado**: no se ve nunca,
     aunque el campo muestre su texto por defecto y la descripción diga que lo ven todos (ver el
     aviso de la pieza 7).

**Correos que salen:** ninguno.

---

### Caso 7 · «¿Siguen haciendo falta los parches?» (dueño)

1. *wp-admin → Herramientas → Salud del sitio*.
2. La prueba con distintivo azul «Akibara» explica en párrafos si Flow ya empaqueta su fichero, si
   Mercado Pago sigue declarando los 15 handles, y si el carrito los encontró realmente encolados
   (`src/RuntimeCompatibility.php:311-390`).
   - **Bifurcación A — el tercero no está instalado**: «ya no aplica y puede quitarse»
     (`:328-329`, `336-337`).
   - **Bifurcación B — el tercero lo arregló**: «sobra. Revisá quitarlo» (`:330-331`).
   - **Bifurcación C — declarados pero nunca encolados**: «el desencolado no está retirando nada»
     (`:340-348`).
   - **Bifurcación D — faltan algunos handles**: el párrafo sale **ilegible**, con
     `&lt;/code&gt;, &lt;code&gt;` entre los nombres (`:365`, y el aviso de la pieza 17).

**Correos que salen:** ninguno.

---

### Caso 8 · «Blue Express se cayó» (sistema)

1. Una ruta de PUDO captura la excepción.
2. **Si es un conflicto de mutación conocido** (`pudo_stale_attempt`, `pudo_checkout_in_progress`,
   `src/PudoServiceAdapter.php:129-139`): se le explica al cliente con un 409 y **no se registra**,
   porque es esperable (`src/PudoIntegration.php:118-124`, `153-159`, `196-202`).
3. **Si es otra cosa**: se registra una línea en *WooCommerce → Estado → Registros*, fuente
   `akibara-pudo`, como mucho una por minuto y contexto (`src/PudoLog.php:47-74`), y el cliente lee
   un mensaje de recuperación concreto.
4. El cliente puede elegir otro método de envío. Si el servicio sigue caído, las tarifas de retiro
   simplemente no aparecen (`src/PudoIntegration.php:259-262`, `768-783`).

**Correos que salen:** ninguno. El dueño se entera por el registro, no por correo.

![Estados de la selección de punto en el navegador](diagramas/12-checkout-blocks-campos-chilenos-y-pudo-estados-pudo-dialogo.mmd)

---

## Reglas de negocio

> Sólo van aquí las que se pueden citar con fichero y línea.

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
| --- | --- | --- | --- |
| En Chile el teléfono se llama «Celular», es obligatorio, ejemplo `8765 4321` | `src/CheckoutFields.php:187-202` | no | — |
| En Chile la ciudad se llama «Comuna», ejemplo «Providencia» | `src/CheckoutFields.php:203-209` | no | — |
| La Región se pide **antes** que la Comuna (prioridad 69 vs. 70) | `src/CheckoutFields.php:216-221` | no | — |
| El RUT es obligatorio, vive en contacto, máx. 13 caracteres, patrón `[0-9.\-Kk]+` | `src/CheckoutFields.php:364-380` | no | — |
| Un RUT inválido se rechaza y **no** se maquilla al guardarlo | `src/CheckoutFields.php:406-436` | no | — |
| El RUT válido se guarda formateado en `_billing_rut` y `billing_rut` | `src/CheckoutFields.php:498-514` | no | — |
| Una comuna cuyo texto normalizado coincide **exactamente** se reescribe a su forma canónica antes de cotizar el envío | `src/CheckoutFields.php:237-262`, `223-240` | sí, por código | filtro `akibara_comuna_directory` |
| Comuna de otra región ⇒ bloquea el pago con mensaje propio | `src/CheckoutFields.php:295-301` | no | — |
| Comuna inexistente ⇒ bloquea; se sugiere sólo **dentro** de la región elegida | `src/CheckoutFields.php:303-309`, `src/ComunaDirectory.php:192-215` | no | — |
| Umbral de parecido por largo: 1 (≤4), 2 (5-7), 3 (≥8) | `src/ComunaDirectory.php:184-190` | no | — |
| Sin catálogo de comunas: ni combobox ni validación; texto libre | `src/Assets.php:338-341`, `src/CheckoutFields.php:276-278` | sí, por código | mismo filtro |
| El contrato de presentación es la versión **2**; si no coincide, el navegador descarta el payload del carrito | `src/StoreApiIntegration.php:37`, `244-246` | no | lo fija `PluginContractTest`, que no viaja en el ZIP publicado |
| Cada línea muestra «Estado» y, si es preventa con fecha, «Llegada estimada» | `src/StoreApiIntegration.php:341-354` | no | — |
| Texto guardado **vacío** ⇒ aviso apagado; el default sólo entra si la fila nunca existió | `src/Assets.php:202-232` | sí | *Akibara → Carrito* |
| El aviso de colección exige `akb_sf_enabled` encendido (de fábrica: **apagado**) | `src/Assets.php:258-262` | sí | *Akibara → Venta* |
| Un cliente con `_akb_sf_optout_all = '1'` no ve el aviso de colección | `src/Assets.php:272-274` | sí | *Mi cuenta → Colección* |
| Un invitado **sí** ve el aviso de colección cuando el maestro está encendido | `src/Assets.php:264-266` | no | — |
| El aviso «¿No estás en Santiago?» sólo en el carrito y sólo si **todas** las tarifas visibles son de retiro | `assets/js/cart-checkout-blocks.js:261-284` | el texto, sí | *Akibara → Carrito* |
| Los cupones con el prefijo de pack se ocultan y sus dos avisos se silencian | `assets/js/cart-checkout-blocks.js:390-400`, `452-482` | el prefijo lo publica Business | — |
| PUDO no admite pedidos divididos en más de un envío | `src/PudoIntegration.php:611-618` | no | — |
| Para elegir agencia, PUDO debe ser el **único** método seleccionado | `src/PudoIntegration.php:625-633` | no | — |
| El navegador sólo puede mandar 4 campos al canal `bluex_pudo` | `src/PudoIntegration.php:444-451` | no | — |
| Formatos: agencia ≤64 con gramática cerrada, dirección ≤200, región `CL-XX`, intento 32 hex | `src/PudoIntegration.php:459-467` | no | — |
| El destino que se le manda a Blue sale del paquete del **servidor** | `src/PudoIntegration.php:138-142`, `483-500` | no | — |
| Nombre **o** dirección de agencia vacíos ⇒ selección inválida | `src/PudoIntegration.php:535-551` | no | — |
| Al confirmar: `agencyId`, `isPudoSelected='pudoShipping'`, `agencyName`, `agencyAddress` + dirección de envío sustituida | `src/PudoIntegration.php:719-750` | no | contrato de Blue |
| El mapa sólo acepta mensajes de `https://widget-pudo.blue.cl`, tipo `pudo:select`, ≤ 8 KB | `assets/js/pudo-blocks.js:4`, `109-139` | no | — |
| El mapa abre siempre en Santiago y lista todo Chile; el destino es **informativo** | `assets/js/pudo-blocks.js:234-240`, `278-285` | no | — |
| Los fallos de PUDO se registran ≤1 vez por minuto y contexto | `src/PudoLog.php:38`, `82-95` | no | — |
| Se retiran 15 observadores clásicos de Mercado Pago, nombrados uno a uno | `src/RuntimeCompatibility.php:55-71` | no | — |
| Se retiran los dos selectores de comuna de Blue en carrito y pago; el de *Mi cuenta* queda intacto | `src/RuntimeCompatibility.php:239-249` | no | — |
| Se repone al script de Flow las 5 dependencias que su build no declara | `src/RuntimeCompatibility.php:256-275` | no | — |
| El pago queda excluido de la optimización JS de LiteSpeed | `src/RuntimeCompatibility.php:136-140` | no | — |
| El título de la página de carrito es «Tu carrito», literal fijo | `src/Shortcodes.php:38-43` | no | — |
| El autocompletado sólo busca en Chile, en español, desde 3 caracteres | `assets/js/google-places-address-provider.js:796-808`, `824-828` | el interruptor, sí | *Akibara → Dirección* |
| Al elegir dirección de Google, la comuna sólo se rellena si el cruce es **inequívoco** | `assets/js/google-places-address-provider.js:630-669` | no | — |
| Dos bloques propios y `google-login/login-button` pueden vivir dentro del formulario de pago | `assets/js/checkout-inner-blocks-editor.js:94-105` | no | — |

---

## Datos que deja

| Tipo | Clave | Quién la escribe | Qué guarda | Dónde se edita |
| --- | --- | --- | --- | --- |
| `option` | `akb_blocks_mp_dequeue_observed` | `src/RuntimeCompatibility.php:199-212` | `{retirados, at}`: cuántos handles clásicos de Mercado Pago había realmente encolados y el sello Unix del último **cambio**. No autocargada | **Ninguna pantalla.** Sólo se lee en Salud del sitio |
| `option` (sólo lectura) | `akibara_blocks_shipping_hint_text` | akibara-business | Texto del aviso «¿No estás en Santiago?» | *Akibara → Carrito* |
| `option` (sólo lectura) | `akibara_blocks_collection_notice_text` | akibara-business | Texto del aviso de colección | *Akibara → Carrito* |
| `option` (sólo lectura) | `akb_sf_enabled` | akibara-business (`SeriesFollow`) | Interruptor maestro de los avisos de tomo nuevo | *Akibara → Venta* |
| `option` (cortocircuitada) | `woocommerce_address_autocomplete_enabled` | WooCommerce | En el pago de bloques su valor guardado **no se usa** (`src/AddressIntegration.php:87-93`) | — |
| `option` (sólo lectura) | `woocommerce_cart_page_id`, `woocommerce_checkout_page_id` | WooCommerce | Qué página mirar para `has_block()` (`src/BlocksPage.php:33`, `50`) | Ajustes de WooCommerce |
| `ordermeta` | `_billing_rut` | `src/CheckoutFields.php:511-513` | RUT formateado `12.345.678-5` del pedido (meta heredada) | — |
| `usermeta` | `billing_rut` | `src/CheckoutFields.php:511-513` | Mismo RUT en la ficha del cliente, para rellenar la próxima compra | — |
| `ordermeta` | `_wc_other/akibara/rut` | WooCommerce (persistencia nativa de campos adicionales) | Copia nativa del campo RUT | — |
| `ordermeta` | `agencyId` | `src/PudoIntegration.php:741` | Identificador de la agencia Blue Express. Nombre impuesto por el contrato público de Blue | — |
| `ordermeta` | `isPudoSelected` | `src/PudoIntegration.php:742` | Literal `'pudoShipping'`: le dice a Blue que el pedido es de retiro en punto | — |
| `ordermeta` | `agencyName` | `src/PudoIntegration.php:744-746` | Nombre del punto, validado por el servidor (nunca el que mandó el navegador) | — |
| `ordermeta` | `agencyAddress` | `src/PudoIntegration.php:747-749` | Dirección del punto, ídem | — |
| Campos del pedido | `shipping_address_1/2`, `shipping_city`, `shipping_state`, `shipping_country`, `shipping_postcode` | `src/PudoIntegration.php:737-739` | La dirección de envío **sustituida** por la del punto de retiro | — |
| `transient` | `akb_pudo_log_<md5(contexto)>` | `src/PudoLog.php:87-92` | Marca de «ya registré este contexto», 60 s | — |
| Sesión de WooCommerce | `shipping_for_package_<n>` | `src/PudoIntegration.php:796-798` | Se **borra** para forzar el recálculo de tarifas | — |
| Sesión de WooCommerce (lectura) | `chosen_shipping_methods` | `src/PudoIntegration.php:646` | Qué método eligió el cliente | — |
| Paquete de envío (en memoria) | `_akb_pudo_generation` | `src/PudoIntegration.php:33`, `236` | Huella que entra en la clave de caché de tarifas de WooCommerce | — |
| `localStorage` / `sessionStorage` | `akb_checkout_blocks_address` | **Sólo se borra** (`assets/js/checkout-blocks.js:4`, `19-31`) | Basura de una versión anterior | — |
| Global inlinado | `window.akbCartCheckoutBlocks` | `src/Assets.php:159-184` | `namespace` + `shippingHint` ya resuelto | — |
| Global inlinado | `window.akbComunaData` | `src/Assets.php:365-379` | Las 346 comunas por región, las etiquetas de región y tres literales | — |
| Global inlinado | `window.akbCartCheckoutBlocksGoogle` | `src/AddressIntegration.php:119-143` | `loaderUrl` (con la clave de Google dentro), `providerId`, `regionMap` | — |
| Global inlinado | `window.akbCheckoutInnerBlocks` | `src/CheckoutBlockIntegration.php:161-179` | `collectionNotice` ya decidido por cliente; cadena vacía = no pintar | — |
| Almacén del navegador | `wc/store/validation`, claves `contact_akibara/rut`, `billing_city`/`shipping_city`, `<id-del-campo-con-guiones-bajos>` y `akibara-pudo-selection` | los cuatro bundles del pago | Los mensajes de error anclados a cada campo | — |

**Lo que este plugin NO crea:** ninguna tabla propia, ningún tipo de contenido, ninguna taxonomía,
ningún usuario, ningún endpoint REST propio (usa los de la Store API de WooCommerce).

---

## Glosario del capítulo

- **bloque** — Una pieza de interfaz que se inserta en el contenido de una página desde el editor de
  WordPress. En el carrito y el pago nuevos de WooCommerce, cada trozo (los campos, el resumen, los
  métodos de envío) es un bloque.
- **bloque interno** — Un bloque que sólo puede vivir **dentro** de otro. Los dos de este plugin sólo
  pueden ir dentro de `woocommerce/checkout-fields-block`.
- **bundle** — Un fichero JavaScript que se descarga entero en el navegador. Aquí hay nueve.
- **combobox** — Un campo de texto que además despliega una lista de opciones y se maneja con el
  teclado. Es lo que este plugin hace con «Comuna».
- **canonizar** — Reescribir un valor a su única forma correcta: `nunoa` → `Ñuñoa`.
- **fail open** — «Fallar abierto»: cuando falta un dato, seguir vendiendo con menos ayudas en vez de
  romperse. Su reverso es que el fallo no se nota.
- **filtro (filter)** — Un hook que recibe un valor y devuelve el valor cambiado: «dame esto, te lo
  devuelvo distinto».
- **acción (action)** — Un hook que sólo avisa: «esto acaba de pasar», sin devolver nada.
- **hook** — Un gancho con nombre que WordPress dispara en un momento concreto y al que cualquier
  plugin puede engancharse. La **prioridad** decide el orden: más bajo, antes.
- **HPOS** — Las tablas de pedidos nuevas de WooCommerce (`High-Performance Order Storage`). Este
  plugin declara que es compatible con ellas.
- **hidratar** — Cuando el JavaScript toma el HTML ya servido y lo convierte en una interfaz viva. El
  atributo `data-block-name` desaparece al hidratar: por eso no sirve para verificar.
- **inlinar** — Imprimir un trozo de JavaScript dentro del propio HTML de la página, en vez de en un
  fichero aparte. Es como el servidor le pasa datos al navegador aquí.
- **meta** — Un dato extra colgado de un pedido (`ordermeta`), de un producto o de una cuenta
  (`usermeta`). Tiene nombre y valor, como una etiqueta pegada.
- **option** — Una fila de configuración en la base de datos de WordPress, con nombre y valor.
  **Autocargada** significa que WordPress la lee en cada petición del sitio, la toque o no.
- **PUDO** — *Pick-Up Drop-Off*: retirar el pedido en un local del transportista en vez de recibirlo
  en casa. Aquí son los Puntos Blue Express.
- **NFD / marcas combinantes** — Dos maneras de escribir la misma letra acentuada: `ñ` como un solo
  carácter, o como `n` más una tilde suelta encima. Los teclados de iPhone producen la segunda, y por
  eso hay que borrar las tildes sueltas antes de comparar.
- **REST / endpoint** — Una dirección web que el navegador llama por detrás y que responde datos
  (JSON) en vez de una página. Un *endpoint* es una de esas direcciones.
- **shortcode** — Un atajo que se escribe entre corchetes en el contenido de una página
  (`[akibara_cart_blocks_title]`) y que WordPress sustituye por HTML al servirla.
- **SlotFill** — El mecanismo con el que WooCommerce deja que un plugin meta su propia interfaz en un
  hueco concreto del checkout. Así aparece la tarjeta del punto de retiro.
- **Store API** — La API REST del carrito de WooCommerce (`/wp-json/wc/store/v1/…`). Deja que un
  plugin meta datos propios en una caja llamada `extensions`, y que registre un canal de escritura
  con nombre.
- **transient** — Una option con fecha de caducidad. Se usa para cosas que valen un rato.
- **viewport visual** — Lo que de verdad se ve en la pantalla del móvil cuando el teclado está
  abierto, que no es lo mismo que el tamaño de la ventana. Medir el correcto es lo que evita que una
  lista se abra por debajo del borde.
- **wc/store/validation** — El almacén del navegador donde WooCommerce guarda los errores de
  validación del checkout. Mientras tenga **cualquier** clave con error, el botón de pagar no
  responde.
