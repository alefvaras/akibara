# akibara-business · carrito, checkout, retiro, cuenta del cliente y seguridad

> **Fuente de verdad de este capítulo:** el snapshot de lo publicado en producción —
> `akibara-business` 1.116.4, `akibara-cart-checkout-blocks` 2.2.17, `akibara-search` 0.1.60,
> `akibara-rewards` 0.3.13 y el tema `akibara-gp` 2.1.118—, no el árbol de trabajo local, que
> mezcla trabajo a medias. Cada afirmación técnica lleva su `fichero:línea` dentro del plugin
> `akibara-business`; cuando la ruta empieza por otra cosa, se dice de quién es.
>
> **Cómo leerlo si es tu primer día.** Las palabras en `código` —`hook`, `nonce`, `transient`,
> `endpoint`, `meta`— están todas explicadas en el **Glosario del capítulo**, al final. No hace
> falta leerlo antes: cuando una palabra estorbe, está ahí abajo.
>
> **Los diagramas.** Cada uno está en `diagramas/` dos veces, con el mismo nombre: el `.svg` es la
> imagen ya dibujada —doble clic y se abre en cualquier navegador— y el `.mmd` es su fuente en texto
> (formato *Mermaid*), que es lo que se edita si el diagrama cambia. Cada uno se enlaza en el punto
> del texto donde ayuda.
>
> **Marcas «⚠ Hoy no cuadra».** A lo largo del capítulo hay recuadros así. No son opiniones: son
> contradicciones entre lo que el código promete y lo que el código hace, cada una con su
> `fichero:línea` y **con un bloque «Cómo medirlo» al final del recuadro**, con los comandos
> literales. Están aquí porque un mapa que sólo cuenta la versión bonita es un mapa que miente.
> Hay además una marca distinta, **«⚠ Decisión del dueño»**: eso no se arregla, se pregunta.
>
> **El espejo donde se mide.** Todos los comandos de este capítulo asumen el entorno local de
> Docker del repositorio (`compose.yaml` en la raíz): se levanta con `docker compose up -d`, la
> tienda queda en `http://localhost:8080/`, los correos **no salen a nadie** y se leen en
> **Mailpit**, `http://localhost:8025/`. Los comandos de WordPress se lanzan con
> `docker compose exec -T cron wp …` (el servicio `cron` ya monta el docroot). Las peticiones de
> prueba llevan siempre `-A AkibaraQA` para poder distinguirlas del tráfico real cuando la misma
> receta se repita en producción. **Producción no se toca para medir un hallazgo**: si el dato se
> obtiene en el espejo, pedirlo en producción es exposición, no verificación.

---

## Para qué existe (en dos párrafos que entienda un novato)

akibara.cl vende manga y cómic en Chile. Está hecha con **WordPress** —el programa que arma y
sirve las páginas— y **WooCommerce**, un añadido de terceros que le pone carrito, pedidos y pagos.
Ese par, tal como viene de fábrica, sirve para vender camisetas en Estados Unidos: no sabe qué es
un RUT, no sabe que un teléfono chileno se escribe `+569` y ocho cifras, no sabe que un cliente
puede pasar a buscar su pedido a la salida del Metro San Miguel, y no sabe que quien compra manga
compra *el tomo siguiente* de algo que ya empezó. Este capítulo cubre la parte del plugin
`akibara-business` que rellena exactamente ese hueco: **todo el camino del cliente**, desde que
pulsa «Añadir al carrito» hasta que, semanas después, entra a su cuenta a ver en qué va su pedido.

Casi nada de este código se ejecuta por su cuenta —hay **una sola** excepción, un trabajo diario
que se cuenta entero en «Lo programado», con el comando para verlo en la cola. Todo lo demás
espera a que pase algo. WordPress funciona a base de **hooks**: avisos
que el sistema lanza en momentos concretos («ya calculé los totales del carrito», «voy a pintar el
pie de página», «se acaba de crear un pedido»). Un módulo se apunta a un aviso con `add_action`
—para hacer algo— o con `add_filter` —para modificar un dato antes de que siga su camino—, y
cuando llega ese momento WordPress le cede el turno. Por eso cada ficha de la sección «Piezas»
lleva una lista de «cuándo actúa»: son los instantes en que ese módulo despierta. Lo que el dueño
puede tocar vive en **options** (filas con nombre y valor en la base de datos, por ejemplo
`akibara_native_cart_enabled = yes`) que se editan en el panel propio, en **wp-admin → Akibara**
—su identificador interno es `akibara-panel`, no `akibara`—. Lo del cliente vive en las tablas de
pedidos de WooCommerce, en una tabla propia `wp_akb_wishlist` para los favoritos, y en **metas**:
datos extra colgados de un pedido, de un producto o de una cuenta.

---

## Cómo encaja con el resto

**El reparto de aguas es una sola frase: `akibara-business` decide, `akibara-cart-checkout-blocks`
pinta.** Las dos páginas dedicadas —`/carrito/` y `/checkout/`— están hechas con el formato nuevo
de WooCommerce («bloques»), que se pinta en el navegador con JavaScript, y de esas dos páginas se
encarga el otro plugin propio. Business le pasa los datos ya masticados por un contrato estable,
`Services\CheckoutDataService` (`src/Services/CheckoutDataService.php`), y **nunca le pasa HTML**.
Si mañana el carrito de bloques cambia de aspecto entero, las reglas de negocio de este capítulo no
se enteran.

### Qué es `$business`, y por qué es el punto de fallo más probable del checkout

En todo el capítulo aparecen llamadas del estilo `$business->checkout_data()`. Conviene saber qué
es esa variable antes de depurar nada, porque su fallo es **mudo**.

`$business` es la instancia única del plugin de este capítulo: la clase
`Akibara\Business\Plugin`, que el plugin de bloques importa con el alias `BusinessPlugin` y pide
con `BusinessPlugin::instance()`. El arranque del plugin de bloques
(`akibara-cart-checkout-blocks/src/Plugin.php:29-42`) hace tres comprobaciones seguidas y, si
cualquiera falla, **hace `return;` sin escribir nada en ningún registro**:

1. ¿Existen las clases `WooCommerce` y `BusinessPlugin`? Si no, `return`.
2. ¿Ese objeto tiene el método `checkout_data()`? Si no, `return`.
3. ¿Lo que devuelve `checkout_data()` es un `Contracts\CheckoutDataProvider`? Si no, `return`.

Superadas las tres, se piden además los servicios de PUDO con
`method_exists( $business, 'pudo_checkout' )` —si no está, el retiro en Punto Blue Express
simplemente no se registra, y el resto del checkout sigue vivo (`:46-49`).

**Qué se ve cuando esto falla:** `/carrito/` y `/checkout/` cargan, se ven casi normales y han
perdido el RUT, el teléfono chileno, los sellos, el aviso de preventa, la barra de envío gratis y
el selector de Punto Blue Express. No hay error en pantalla ni en los registros. **Qué mirar
primero:** que `akibara-business` esté activo y en una versión que exponga `checkout_data()`
(`docker compose exec -T cron wp plugin list --status=active --fields=name,version`), y que el
orden de carga no haya cambiado —bloques arranca *después* de business a propósito.

### El único instante fiable de «el pedido ya existe»

Esto vale para cualquiera que quiera colgar una regla nueva al crearse un pedido, y es la causa
número uno de «mi código no corre y no hay ningún error».

En Akibara el checkout es de bloques, así que el pedido lo crea la **Store API** de WooCommerce, no
el checkout clásico. La Store API **no emite** los hooks clásicos del final del checkout
—`woocommerce_checkout_order_processed`, `woocommerce_checkout_update_order_meta`—. Quien se
enganche ahí no se entera de ninguna compra y no recibe ningún aviso de que no se enteró: en todo
`src/` de este plugin esos dos hooks **no aparecen ni una vez**.

El punto fiable es `woocommerce_store_api_checkout_order_processed`, y es el que usan
`PhoneValidation.php:30` y `MarketingConsent.php:137`. Módulos de otros capítulos que necesitan
también el camino clásico —por si algún día se pagara por él— registran **los dos**:
`OrderPreventa\OrderTagging.php:50` y `:62` engancha `woocommerce_checkout_create_order` *y*
`woocommerce_store_api_checkout_order_processed`, y lo explica en su propio docblock (`:25-29`).
Ese es el patrón a copiar: cinturón y tirante, nunca sólo el clásico.

Con quién más habla:

| Con quién | Por dónde | Qué pasa si se apaga |
| --- | --- | --- |
| **WooCommerce** (tercero) | Casi todo: hooks del carrito, del checkout, de los correos, de Mi cuenta y de la Store API | Nada de este capítulo existe. Business no arranca sin WooCommerce |
| **akibara-cart-checkout-blocks** (propio) | `$business->checkout_data()` y `$business->pudo_checkout()` (ver el apartado de arriba) | `/carrito/` y `/checkout/` pierden el RUT, el teléfono chileno, los sellos, el aviso de preventa, la barra de envío gratis y el selector de Punto Blue Express. El cajón lateral y Mi cuenta siguen enteros |
| **Mercado Pago** (tercero, `woocommerce-mercadopago`) | **Es quien cobra.** Este capítulo no le habla: dos módulos existen sólo para que sus scripts lleguen enteros al navegador — `FrontendCompatibility` los saca del combinado y del diferido de LiteSpeed (`src/Modules/FrontendCompatibility/FrontendCompatibility.php:28-37` y `:77-79`) y `ScriptDiagnostics\CrossOriginScripts` les añade `crossorigin` para que sus errores se puedan leer | Nadie puede pagar. Y si LiteSpeed vuelve a combinar sus scripts, el checkout se rompe en el navegador con un «Script error.» sin fichero ni línea |
| **tema `akibara-gp`** (propio) | `Services\NavigationLinks` para las URLs; `pdp-ajax-atc.js` llama al endpoint del carrito con `drain_notices=1`; el estado vacío de favoritos pinta `template-parts/empty/cross-sell.php` del tema | Los enlaces de cabecera y pie caen a rutas por defecto; el estado vacío de favoritos se queda sin su bloque de «Más vendidos» (hay guarda: no revienta) |
| **Blue Express** (tercero, plugin `bluex-for-woocommerce`) | Sólo por hooks y por sus clases `BlueX_API_Client` / `WC_Correios_Settings`. **Nunca se edita su código** | Desaparece el retiro en Punto Blue Express; la barra de envío gratis pierde el sentido, porque en esta tienda el envío gratis lo concede la tarifa de Blue Express, no WooCommerce |
| **`login-with-google`** (tercero) | Filtro `rtcamp.google_default_redirect` | El muro de acceso pierde el botón «Continuar con Google»; los otros dos caminos (correo/contraseña y alta) siguen |
| **Brevo** (tercero, proveedor de campañas) | Sólo el **nombre** de su campo de checkout, `SendinblueWoocommerce/newsletter_opt_in`. **Cero llamadas a su API** | Deja de existir la casilla de ofertas; `MarketingConsent` registra un aviso por pedido de «pedido sin prueba» (no inventa un «NO» de una casilla que nadie vio) |
| **Google Maps Places** (tercero) | `AddressAutocomplete`, apagado por defecto | Nada: viene apagado (`src/Modules/AddressAutocomplete/AddressAutocomplete.php:54`) |
| **LiteSpeed Cache** (tercero) | `litespeed_ucss_whitelist`, `litespeed_purge_post`, `litespeed_control_set_nocache` | Los corazones de favoritos pueden quedarse sin CSS; las fichas con recomendados nuevos siguen sirviendo la copia vieja; la página de colección podría acabar en una caché compartida |
| **`akibara-rewards`** (propio) | `function_exists( 'spar_get_user_points' )` — ver la aclaración de abajo | La tarjeta de puntos del panel de Mi cuenta no se pinta, y `/mi-cuenta/rewards/` deja de existir |
| **Otros módulos del mismo plugin** | `SeriesBundles`, `EstimatedDelivery`, `OrderPreventa`, `SeriesFollow`, `Stock`, `SeriesFacts`, `CopyManager`, `AdminPanel` | Cada uno se dice en su ficha. Son del mismo plugin: no se apagan por separado |

> **Aclaración importante: `spar_get_user_points()` ya no es de un tercero.**
> «Simple Points and Rewards» (SPAR) fue un plugin de terceros, y **ya no está instalado**. Su
> función `spar_get_user_points()` la publica hoy el plugin propio `akibara-rewards`, como puente
> de compatibilidad: `akibara-rewards/akibara-rewards.php:204-221` la declara en `plugins_loaded`
> prioridad 25, y sólo si nadie la definió antes y si existe su propio libro de puntos
> (`\Akibara\Rewards\Points\Ledger`). El saldo sale de `Ledger::balance()`. El endpoint
> `/mi-cuenta/rewards/` también lo registra el plugin propio
> (`akibara-rewards/src/Points/AccountRewards.php:73`, `add_rewrite_endpoint`).
>
> Esto importa al depurar: si alguien reporta «la tarjeta de puntos no sale», **no hay ningún
> plugin de terceros que buscar**. Se mira si `akibara-rewards` está activo. El comentario de
> `templates/myaccount/dashboard.php:68-71` que todavía dice «el endpoint `rewards` lo registra
> Simple Points and Rewards» es documentación caduca dentro del plugin desplegado (ver el apartado
> «Deuda documentada» al final del capítulo).

![Piezas del capítulo y sus relaciones](diagramas/05-business-carrito-checkout-y-cuenta-piezas.mmd)

### Toca este camino, pero se cuenta en otro capítulo

No todo lo que atraviesa el carrito, el checkout o la cuenta vive en este capítulo. Estas piezas
aparecen aquí nombradas y su ficha completa está en otro sitio:

| Pieza | Qué hace en este camino | Dónde se cuenta entera |
| --- | --- | --- |
| `Modules/ShippingCopy/ShippingCopy.php` | Publica el shortcode `[akb_envio_gratis]`, que resuelve **la misma** option `akibara_fsp_carrier_threshold` de la barra de envío gratis. Cambiar ese número mueve también la barra de anuncios y la FAQ | Capítulo **06 · panel, descuentos, textos y portada** |
| `Modules/FrontendCompatibility/FrontendCompatibility.php` | Saca los scripts de Mercado Pago del combinado y el diferido de LiteSpeed, para que se pueda pagar | Capítulo **06** |
| `Modules/ScriptDiagnostics/CrossOriginScripts.php` | Añade `crossorigin` a los scripts de dominios ajenos del checkout para que sus errores se puedan leer | Capítulo **06** |
| `Services/MailerBoot.php`, `Services/AkibaraEmail.php`, `Services/EmailButton.php` | El transporte y la plantilla por los que salen **todos** los correos que prometen los nueve casos de uso. Si el transporte se cae, no sale ninguno y nadie avisa | Capítulo **07 · SEO, IA, blog y correo** |
| `Modules/SeriesFollow/` (`GlobalToggle`, `CollectionToggle`, `FollowRegistry`) | El interruptor de avisos de «Mi colección»: la **única** vía de escritura de esa pantalla | Capítulo **04 · disponibilidad, preventa y avisos** |
| `Modules/OrderPreventa/`, `Modules/EstimatedDelivery/` | Escriben las metas `_akb_eta_has_preventa` y `_akb_eta_ready_to_ship` y la fecha estimada que `OrderTimeline` **sólo lee** | Capítulo **04** |
| `Modules/SeriesBundles/` | El motor de «Arma tu Pack», que decide si el aviso del cajón sale | Capítulo **03 · catálogo y series** |

### Las URLs reales, y quién las resuelve

| Qué | URL | Quién da esa URL |
| --- | --- | --- |
| Carrito (página de bloques) | `https://akibara.cl/carrito/` | `NavigationLinks::cart_url()` (`src/Services/NavigationLinks.php:268`) |
| Checkout (página de bloques) | `https://akibara.cl/checkout/` | `wc_get_checkout_url()` de WooCommerce (`src/Modules/NativeCart/NativeCart.php:120`) |
| Mi cuenta / muro de acceso | `https://akibara.cl/mi-cuenta/` | `NavigationLinks::account_url()` (`:219`) |
| Pedidos | `https://akibara.cl/mi-cuenta/orders/` | `wc_get_endpoint_url()` dentro de `templates/myaccount/dashboard.php:57-105` |
| Detalle de un pedido | `https://akibara.cl/mi-cuenta/view-order/{id}/` | WooCommerce |
| Favoritos | `https://akibara.cl/mi-cuenta/favoritos/` | `Wishlist\Endpoint::url()`, y `NavigationLinks::wishlist_url()` (`:252`) delega en él |
| Mi colección | `https://akibara.cl/mi-cuenta/coleccion/` | `AccountCollection\Endpoint::url()` (`src/Modules/AccountCollection/Endpoint.php:216-226`) |
| Direcciones · Detalles · Recuperar contraseña | `…/edit-address/`, `…/edit-account/`, `…/lost-password/` | `wc_get_endpoint_url()` en las plantillas |
| Tienda | `https://akibara.cl/tienda/` | `NavigationLinks::shop_url()` (`:284`) |

> **Corrección respecto del mapa de lectura.** `NavigationLinks` resuelve **seis** URLs, no once:
> cuenta, contacto, favoritos, carrito, tienda y portada (`src/Services/NavigationLinks.php:219-298`).
> No existe ningún `checkout_url()`, ni `orders`, ni `view-order`, ni `edit-address`, ni
> `lost-password`, ni colección. Importa porque quien busque «dónde cambio la URL del checkout» no
> la va a encontrar ahí.

El cajón del carrito no tiene URL propia: vive en el pie de todas las demás páginas.

### Las pantallas del dueño, con su URL

Todas las pestañas del panel propio cuelgan de la misma página de wp-admin y se distinguen por el
parámetro `tab`. **El identificador de la página es `akibara-panel`, no `akibara`**: escribir
`page=akibara` devuelve un 403 que parece un problema de permisos y no lo es. La plantilla es:

```
https://akibara.cl/wp-admin/admin.php?page=akibara-panel&tab=<id>
```

| Pestaña, tal como se lee en el panel | `tab=` | Quién la registra | Qué se toca ahí |
| --- | --- | --- | --- |
| **Carrito** | `native_cart` | `Plugin.php:1361` | Los 25 ajustes y textos del cajón lateral |
| **Envío gratis** | `free_shipping_progress` | `Plugin.php:1360` | Las once options de la barra, incluido el «Umbral del transportista» |
| **Recomendaciones** | `upsells_autofill` | `Plugin.php:1362-1370` | Cobertura, salud de enlaces y los dos botones del relleno de recomendados |
| **Autocompletar dirección** | `address` | `Plugin.php:1298` | Encendido y clave de Google Maps |
| **Textos** | `copys` | `CopyManager.php:147-155` | Los diez copys del muro de acceso y la promesa de cuotas |

Fuera del panel propio, dos pantallas de WooCommerce salen repetidamente en este capítulo:

| Pantalla | URL |
| --- | --- |
| Asuntos y encendido de cada correo | `…/wp-admin/admin.php?page=wc-settings&tab=email` (y `&section=<id del correo>` para uno concreto) |
| Registros (los canales `akibara-*`) | `…/wp-admin/admin.php?page=wc-status&tab=logs` |

En el espejo Docker las mismas URLs viven bajo `http://localhost:8080/`.

---

## Piezas

**Diecinueve fichas, cuarenta y cuatro clases, seis familias.** Las familias son las mismas seis
cajas del diagrama de arriba: el carrito, lo que se hace desde el listado, los retoques chilenos
del checkout, las dos formas de retirar, lo que pasa después de comprar en `/mi-cuenta/`, y los
transversales (seguridad, consentimiento y servicios compartidos). Una de las cuarenta y cuatro,
`Contracts\CheckoutDataProvider`, no es una clase sino un **contrato**: la lista de datos que
business promete entregarle al plugin de bloques.

Cada ficha dice, en este orden: qué hace en una frase, en qué ficheros vive, cuándo actúa (con el
hook y su prioridad), qué lee y qué escribe, dónde lo opera el dueño y qué ve el cliente.

### 1. `NativeCart` — el cajón lateral del carrito

> **El aviso de envío y la barra dicen lo mismo (R3-2cf1a611, 1.116.27).** El cajón decía «El envío se calcula
> al finalizar la compra» incluso cuando la barra de envío gratis, en el mismo cajón, ya decía «¡Listo!». Ahora
> pregunta a la MISMA regla (`FreeShippingProgress::is_qualified()`, por el filtro `akb_native_cart_fsp_qualified`).
> Medido en el espejo: bajo el umbral, «se calcula…»; sobre él, «Envío incluido en el total» + «¡Listo!».
> **Ronda 5 (1.116.29):** el filtro `akb_native_cart_fsp_qualified` se registra siempre que el módulo está activo, fuera del bloque «Mostrar en mini carrito»: la regla de si el carrito califica no depende de que la barra se pinte (con esa casilla apagada, el aviso volvía a mentir). Medido en el espejo con la casilla apagada: «Envío incluido en el total» y sin barra.

**Qué hace.** Es el carrito que se abre por el costado cuando el cliente añade un tomo o pulsa el
icono del carrito; sustituye a un plugin de terceros.

**Ficheros.** `src/Modules/NativeCart/NativeCart.php` · `assets/js/native-cart.js` ·
`assets/css/native-cart-v2.css`.

**Cuándo actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `wp_enqueue_scripts` | 10 | Carga CSS y JS y les pasa URLs, textos y `nonce` (`NativeCart.php:92-126`) |
| `wp_footer` | 10 | Imprime el HTML **vacío** del cajón (`NativeCart.php:128`) |
| `wc_ajax_akb_get_cart` | 10 | Devuelve el estado completo del carrito en JSON |
| `wc_ajax_akb_update_cart_item` | 10 | Cambia la cantidad de una línea |
| `wc_ajax_akb_remove_cart_items` | 10 | Elimina una o varias líneas |
| `woocommerce_after_calculate_totals` | 10 | Anota que WooCommerce ya recalculó, para no pagar un segundo cálculo |

El HTML que se imprime en el pie está vacío a propósito: todo lo que se ve lo trae la petición
**AJAX** `?wc-ajax=akb_get_cart`. Por eso el cajón funciona igual en una página servida desde
caché, que es casi toda la tienda.

**Qué lee y escribe.** Sólo el carrito vivo de la sesión (`WC()->cart`) y la cola de avisos de
WooCommerce. No guarda nada propio en base de datos.

**Los tres endpoints.**

| Ruta | Método | Credencial | Para qué |
| --- | --- | --- | --- |
| `/?wc-ajax=akb_get_cart` | GET | ninguna | Leer el carrito. Con `&drain_notices=1` además vacía y devuelve los avisos de error pendientes |
| `/?wc-ajax=akb_update_cart_item` | POST | `nonce` `woocommerce_akibara_native_cart` | Cambiar la cantidad |
| `/?wc-ajax=akb_remove_cart_items` | POST | el mismo `nonce` | Eliminar líneas |

Los dos que mutan exigen `nonce` —una credencial de un solo uso contra peticiones falsificadas—;
el de lectura no, porque sólo toca la sesión de quien llama, y a cambio **devuelve un `nonce`
fresco en cada respuesta**. Eso es lo que hace que el carrito se auto-sane: el `nonce` que viaja
horneado en el HTML puede llegar caducado desde una caché de siete días, así que ante un 403 el
JavaScript refresca el carrito, adopta el `nonce` nuevo y reintenta **una** vez.

> **Las dos convenciones de AJAX que conviven en este capítulo, y por qué no se mezclan.**
> En el código verás dos familias de hooks que parecen lo mismo y no lo son:
>
> - **`wc_ajax_<acción>`**, de WooCommerce. Atiende **a todo el mundo**, con sesión y sin ella, en
>   una sola entrada, y por eso **no existe** ninguna variante `nopriv`. Es lo que usa el cajón:
>   `wc_ajax_akb_get_cart`, `…_remove_cart_items`, `…_update_cart_item` (`NativeCart.php:61`,
>   `:63`, `:65`). Su URL es `/?wc-ajax=<acción>`, mucho más barata que `admin-ajax.php` porque no
>   carga el panel.
> - **`wp_ajax_<acción>`**, de WordPress. Atiende **sólo a quien tiene sesión**. Para atender
>   también a un visitante anónimo hay que registrar **además** `wp_ajax_nopriv_<acción>`. Es lo
>   que hacen los favoritos, con las dos: `wp_ajax_akb_wishlist_toggle` para el cliente y
>   `wp_ajax_nopriv_akb_wishlist_toggle` para responder «se te cayó la sesión»
>   (`Wishlist/Ajax.php:28-29`). Su URL es `/wp-admin/admin-ajax.php?action=<acción>`.
>
> Es decir: que el carrito no registre `nopriv` **no** significa que exija sesión. Significa que
> está en la otra familia, donde esa distinción no existe.

**Dos decisiones que se ven.**

- **Producto agotado.** `get_max_purchase_quantity()` devuelve tres cosas distintas: `-1` sin
  límite, `N` tope real, y `0` *no queda nada comprable*. El guard anterior metía el `0` en el
  mismo saco que el `-1`, así que un producto agotado se comportaba como stock infinito. Hoy, con
  máximo 0, la línea **se retira** y el cliente lee «X se agotó y lo quitamos de tu carrito»
  (`NativeCart.php:458-468`).
- **Techo de cantidad (1.116.30, `R6-cantidad-sin-techo`).** El `-1` «sin límite» se traduce al
  techo de WooCommerce —9.999 por línea, el que el Store API ya aplicaba en /carrito/— antes del
  guard, en el payload del cajón y en el clamp de `akb_update_cart_item`, con
  `CartQuantityCeiling::maximo_para()` (`src/Modules/CartQuantity/CartQuantityCeiling.php`). El
  mismo módulo pone `max="9999"` en el campo cantidad de la ficha (`woocommerce_quantity_input_max`)
  y rechaza con aviso el «Añadir al carrito» clásico por encima del techo cuando el tomo no
  gestiona stock («Puedes llevar hasta 9.999 unidades de «X» por pedido»); el stock gestionado y
  el «de uno en uno» los sigue validando WooCommerce con su mensaje. Antes, 999999 unidades
  entraban por la ficha y el cajón, el ícono y la barra de envío gratis las mostraban en todo el
  sitio. Sin options: es el techo de WooCommerce, no una regla de la tienda.
- **Avisos gateados por el contenido real.** El aviso de preventa y el de «Arma tu Pack» no salen
  sólo porque el interruptor esté encendido: se miran los ítems. Y el de preventa **elige la
  palabra** —«pedidos», «preventas» o «preventas y pedidos»— según lo que el cliente lleve
  (`NativeCart::detect_cart_flags`, `:591-599`).

**Dónde lo opera el dueño.** wp-admin → Akibara → pestaña **Carrito**
(`…/wp-admin/admin.php?page=akibara-panel&tab=native_cart`).

**Qué ve el cliente.** El cajón en toda la tienda salvo `/carrito/` y `/checkout/`: lista de tomos
con miniatura, `+`/`−`, eliminar, subtotal, descuentos, total, avisos, barra de envío gratis,
recomendados y los botones «Pagar pedido» y «Seguir comprando».

> **⚠ Hoy no cuadra — una línea agotada se queda muda dentro del cajón.**
> El servidor sabe retirar la línea y explicarla, pero el único gesto que el cajón ofrece para
> llegar ahí —subir la cantidad— está **deshabilitado** justo en ese caso:
> `var atMax = (max === 0) || (max > 0 && current >= max); … if (atMax) { plus.disabled = true; }`
> (`assets/js/native-cart.js:610-617`). Con una sola unidad, «−» manda cantidad 0 y cae en la rama
> de borrado silencioso (`NativeCart.php:477-479`), que no pasa por el aviso. Resultado: el tomo
> agotado sigue en el cajón sumando al subtotal y al progreso de envío gratis hasta que el cliente
> llega a `/carrito/` y la Store API le dice «no tiene existencias». La rama del aviso sólo se
> alcanza pulsando «−» sobre una línea con cantidad ≥ 2.
>
> **Cómo medirlo (espejo Docker).**
> ```bash
> # 1. Añadir el tomo al carrito PRIMERO, con el navegador, y dejar el cajón abierto.
> # 2. Agotarlo por detrás:
> docker compose exec -T cron wp wc product update <id> \
>   --stock_quantity=0 --stock_status=outofstock --manage_stock=true --user=1
> # 3. Comprobar que la línea sigue ahí y que el servidor ya la marca sin tope comprable:
> curl -s -A AkibaraQA -b cookies.txt 'http://localhost:8080/?wc-ajax=akb_get_cart' | grep -o '"max":[0-9-]*'
> ```
> Se espera `"max":0`. En el navegador: el botón «+» de esa línea está deshabilitado y la región
> `[data-akb-cart-status]` no dice nada. Con **una** unidad, pulsar «−» borra la línea sin aviso;
> con **dos**, pulsar «−» sí devuelve «se agotó y lo quitamos de tu carrito». Ese contraste entre
> las dos cantidades es la prueba.

### 2. `NativeCart\Settings` — los ajustes y los textos del carrito

**Qué hace.** Guarda, valida y sirve todo lo que el dueño puede tocar del cajón.

**Ficheros.** `src/Modules/NativeCart/Settings.php`.

**Cuándo actúa.** `woocommerce_admin_settings_sanitize_option` (prioridad 10) limpia cada valor
antes de escribirlo; `akibara_panel_save_errors` (prioridad 10) **bloquea el guardado** del tab si
el selector de activadores no es válido.

**Cuántos campos son.** **25** campos declarados (`Settings.php:212-420`), más un `title` y un
`sectionend`, que no son campos. Son: activar · abrir al agregar · selector de activadores ·
pantalla completa en móvil · lado · ancho · miniaturas · subtotal · avance de envío gratis ·
mostrar recomendados · límite de recomendados · fuente de recomendados · aviso pack · copy pack ·
aviso preventa · copy preventa · aviso «¿No estás en Santiago?» · aviso de colección · movimiento
reducido · texto de carrito vacío · texto del botón de checkout · texto de seguir comprando · URL
de seguir comprando · título de recomendados · botón de recomendados.

Dos de esos campos —`akibara_blocks_shipping_hint_text` y `akibara_blocks_collection_notice_text`—
los pinta en realidad el plugin de bloques: viven aquí porque son copys del carrito, y **vaciarlos
los apaga** en vez de reponer el literal por defecto (`Settings.php:452-458` y `:657-660`).

**El validador que merece mención.** Un «Selector de activadores» escrito como texto libre —«boton
rojo del header»— se guardaba tal cual, y en el navegador `element.closest()` lanzaba un error que
dejaba el icono del carrito muerto **sin ningún aviso**. Hoy el guardado se rechaza con una
explicación (`Settings::validate_trigger_selector`, `:141-206`).

**Qué ve el cliente.** Todos los textos y comportamientos visibles del cajón, más dos avisos que
salen en el carrito y el checkout de bloques.

### 3. `NativeCart\SmartCartRecommender` — qué se sugiere dentro del carrito

**Qué hace.** Decide los tres «También te puede gustar» del cajón.

**Ficheros.** `src/Modules/NativeCart/SmartCartRecommender.php`.

**Cuándo actúa.** `woocommerce_cart_crosssell_ids` (10) antepone sus candidatos a los cross-sells
configurados a mano; `woocommerce_order_status_processing` y `…_completed` (10) borran el
historial cacheado del cliente.

**El orden de los candidatos.** Primero **el siguiente tomo** de las series que el cliente lleva en
el carrito o ya compró; si sobra hueco, un **tomo 1** de sus dos géneros más frecuentes. Excluye
lo que ya tiene mirando hasta 50 pedidos atrás con **una sola consulta** a las tablas de líneas de
pedido, que son las mismas con y sin HPOS y no dependen de que los informes estén al día.

**Qué lee y escribe.**

| Tipo | Clave | Vida |
| --- | --- | --- |
| `transient` | `akb_smartcart_<md5 de carrito+usuario>` | 5 minutos |
| `transient` | `akb_user_history_data_<user_id>` | 6 horas, o hasta que el cliente compre |
| tabla (sólo lectura) | `wp_woocommerce_order_items`, `wp_woocommerce_order_itemmeta`, `wp_posts`, `wp_term_relationships` | — |

El patrón es «cachea candidatos, stock en vivo»: los candidatos viven 5 minutos con clave de
carrito **y** de usuario —si sólo fuera del carrito, dos clientes con el mismo carrito
compartirían recomendaciones personales—, y el filtro de stock corre **siempre** sobre datos
frescos (`:42`, `:57`, `:150-158`). El historial cacheado se borra en cuanto el cliente compra,
porque antes el motor le recomendaba el tomo que acababa de pagar (`:95-96`).

**Dónde lo opera el dueño.** Ninguna pantalla propia; sólo el número de recomendados y los textos,
en la pestaña Carrito.

### 4. `QuickView` — la vista rápida desde el listado

**Qué hace.** Enseña la sinopsis de un tomo sin salir de la cuadrícula.

**Ficheros.** `src/Modules/QuickView/QuickView.php` · `assets/js/quick-view.js` ·
`assets/css/quick-view.css`.

**Cuándo actúa.** `wp_enqueue_scripts` (10) sólo en contextos de catálogo; `woocommerce_after_shop_loop_item`
(**prioridad 7**), que lo coloca justo entre el corazón de favoritos (prioridad 6) y el botón de
compra (prioridad 10).

**Por qué existe.** Un dato de uso real: `/tienda/` concentraba 88 «entro a la ficha y vuelvo»
semanales **(Clarity, medido el 2026-07-27 sobre usuarios reales; la medición está fechada y
atribuida en el propio docblock, `QuickView.php:5-10`)**. La tarjeta ya muestra precio, editorial,
disponibilidad y fecha; lo único que falta para decidir es la sinopsis, y no cabe en una grilla
móvil de dos columnas (unos 165 px por tarjeta).

**Cómo está hecho.** No hay endpoint: la sinopsis, ya recortada a 280 caracteres por palabra
completa y limpia de frases comerciales (`:53`, `:174-209`), viaja **dentro del propio botón**. El
resto —portada, precio, sello, botón de compra— se **clona** del DOM de la tarjeta dentro de una
ventana modal nativa. El clon del botón de compra funciona porque WooCommerce escucha por
delegación: no hay una segunda lógica de carrito. Sin soporte de `<dialog>` el botón queda oculto y
la tarjeta navega a la ficha; sin sinopsis el botón **no se pinta** (`:113-118`).

**Dónde lo opera el dueño.** En ningún sitio: no tiene ajustes.

### 5. `Wishlist` — los favoritos (ocho clases)

**Qué hace.** El corazón de cada tarjeta y la página `/mi-cuenta/favoritos/`. Sustituyó al plugin
externo WPC Smart Wishlist. **Exige sesión iniciada**: no hay lista de invitado ni cookie
(`Storage.php:46-52`).

| Clase | Fichero | Qué hace |
| --- | --- | --- |
| `Wishlist` | `src/Modules/Wishlist/Wishlist.php` | La que manda: decide en qué superficies bajan CSS y JS (`:96-148`), protege los selectores de la purga de LiteSpeed (`:42`) y limpia las filas de un producto borrado (`:58`) |
| `Install` | `…/Install.php` | Crea y migra `wp_akb_wishlist`, con clave primaria compuesta `(user_id, product_id)` (`:82-89`) |
| `Storage` | `…/Storage.php` | Las cuatro operaciones, con caché por petición para no hacer 24 consultas por página |
| `Ajax` | `…/Ajax.php` | El clic del corazón, con auto-sanado de `nonce` |
| `Endpoint` | `…/Endpoint.php` | La ruta `/mi-cuenta/favoritos/` y su pestaña |
| `Button` | `…/Button.php` | El corazón en tarjetas y fichas |
| `Render` | `…/Render.php` | La cuadrícula y el estado vacío |
| `PrivacyHandler` | `…/PrivacyHandler.php` | Exportar y borrar los favoritos de una persona |

**Cuándo actúa.** `wp_enqueue_scripts` (10) registra y encola; `litespeed_ucss_whitelist` (20)
protege `.akb-product-cover-wrap` y `.akb-wl-anchor` de la purga de CSS; `before_delete_post` (10)
borra las filas de un producto eliminado; `deleted_user` (10) borra los favoritos de una cuenta
eliminada, porque WordPress no toca tablas propias. El corazón lo pintan cuatro hooks del loop:
`woocommerce_before_shop_loop_item_title` en prioridades 8 y 12 (abre y cierra la caja de la
portada), `woocommerce_after_shop_loop_item` en 6 (la tarjeta) y `woocommerce_single_product_summary`
en 35 (la ficha).

**Dónde se cargan los estilos, y por qué importa.** La política entera vive en tres métodos
seguidos de `Wishlist.php:96-148`, y conviene leerlos en ese orden:

- `enqueue_assets()` (`:96-102`) es el que corre en `wp_enqueue_scripts`: registra las hojas y
  **encola sólo si la superficie pinta corazones**.
- `surface_renders_hearts()` (`:131-148`) es la lista: portada, `/tienda/`, ficha, taxonomías de
  producto, búsqueda, `/carrito/` y el endpoint de favoritos; **nunca el checkout**, que sale por
  la primera rama. El docblock de arriba (`:122-130`) trae las cuentas con que se decidió —portada
  96 corazones, `/tienda/` 72, búsqueda 48, `/carrito/` 12, ficha 1—.
- `ensure_assets()` (`:111-120`) es la red de seguridad: lo encola tarde desde `Button::html()`
  para cualquier superficie no prevista. Falla abierta —la hoja llega en el pie— nunca sin hoja.

Quien añada corazones a una superficie nueva tiene que conocer esta lista, porque sin CSS
`.akb-wl-anchor` pierde su caja absoluta y —como advierte el propio comentario del
`allowlist_ucss_selectors`— puede tragarse el clic de toda la portada.

**Tres detalles con historia.**

- El endpoint se registra **también** en el registro de WooCommerce (`woocommerce_get_query_vars`),
  no sólo en WordPress. No hacerlo fue lo que rompió `/tienda/` durante 30 minutos con el plugin
  anterior.
- El corazón vive **fuera** del enlace de la tarjeta: un `<button>` dentro de un `<a>` es HTML
  inválido y, antes de que cargue el script, el toque **navegaba** en vez de guardar.
- Para quien no ha entrado, el botón **ya no se anuncia como conmutador**. Decía «Guardar en
  favoritos» con `aria-pressed`, y lo que hacía era cambiar de página. Ahora dice la verdad:
  «Inicia sesión para guardar «X» en favoritos» (`Button.php:105-108`). Y el JavaScript **anota la
  intención**: al volver de entrar, el tomo se guarda solo.

**Dónde se anota esa intención, exactamente.** En el **`sessionStorage`** del navegador —el
almacén que vive sólo mientras dure esa pestaña—, bajo la clave **`akbWishlistPending`**
(`assets/js/wishlist.js:92`). El valor es un JSON con el identificador del producto y el momento
en que se anotó; al volver, `resumePendingIntent()` (`:310`) lo lee **y lo borra en el acto**
(`:109-110`), y lo descarta si han pasado más de **1.800.000 ms —media hora—** (`:121`). Para
comprobar en vivo si hay un guardado pendiente, en la consola del navegador:
`sessionStorage.getItem('akbWishlistPending')`. Si devuelve `null` justo después de pulsar el
corazón, o el navegador bloquea el almacenamiento (modo privado) o algo falló antes.

**Qué lee y escribe.** La tabla `wp_akb_wishlist` (`user_id`, `product_id`, `added_at`) y la option
`akb_wishlist_db_version`, que no tiene pantalla.

**Dónde lo opera el dueño.** Ninguna pantalla propia. Aparece en wp-admin → Herramientas →
Exportar/Borrar datos personales.

> **⚠ Hoy no cuadra — el encabezado cuenta una cosa y la cuadrícula pinta otra.**
> `items_grid_html()` monta la consulta con `post_status => 'publish'` y un `tax_query` que excluye
> `product_visibility = exclude-from-catalog` (`Render.php:91-108`), mientras el encabezado usa
> `Storage::count()`, que cuenta **todo lo guardado** (`:115`). Basta que el dueño despublique un
> tomo, lo mande a la papelera o lo marque «oculto del catálogo» para que los dos números dejen de
> coincidir, con tres favoritos o con ciento treinta y siete. Y como el `if ( $query->have_posts() )`
> **no tiene rama `else`** (`:130-150`), si todos los guardados están ocultos la página imprime «N
> productos guardados» y debajo no pinta nada: ni tarjetas ni el estado vacío honesto que el módulo
> ya tiene escrito.
>
> Se suma una segunda causa: la limpieza de huérfanos se engancha a `before_delete_post`, que es el
> **borrado definitivo** (`Wishlist.php:58` → `Storage.php:220-226`). Un tomo en la papelera
> conserva su fila.
>
> Y una tercera, independiente: `MAX_ITEMS_RENDERED = 100` (`Render.php:30`, aplicado en `:37` y
> `:97`) sin paginación ni aviso. Con 137 favoritos, el encabezado dice la verdad y debajo hay 100
> tarjetas.
>
> **Cómo medirlo (espejo Docker).** Con una cuenta de prueba, guardar tres tomos y después
> despublicar uno:
> ```bash
> docker compose exec -T cron wp post update <id> --post_status=draft
> # Con la cookie de sesión de esa cuenta en cookies.txt. Cada tarjeta lleva un corazón:
> curl -s -A AkibaraQA -b cookies.txt http://localhost:8080/mi-cuenta/favoritos/ \
>   | grep -o 'akb-wl-toggle' | wc -l
> ```
> **Ojo:** `grep -c` cuenta **líneas**, no ocurrencias, y el HTML servido puede venir minificado en
> una sola línea. Hay que contar con `grep -o … | wc -l`, siempre.
>
> Se espera **2** corazones contra un encabezado que sigue diciendo «3 productos guardados».
> Repitiendo el `post_status=draft` sobre los tres se reproduce la página en blanco: encabezado
> con número y, debajo, nada. Para la papelera, `wp post delete <id>` (sin `--force`) y comprobar
> que la fila sigue: `docker compose exec -T cron wp db query "SELECT * FROM wp_akb_wishlist WHERE
> product_id = <id>"`.

### 6. `UpsellsAutofill` — los recomendados de la ficha

**Qué hace.** Rellena el bloque «Productos recomendados» de las fichas que no tienen ninguno.

**Ficheros.** `src/Modules/UpsellsAutofill/UpsellsAutofill.php` · `assets/css/upsells-admin.css`.

**Por qué existe.** El catálogo histórico traía recomendados en el CSV de importación; las cargas
manuales posteriores los olvidaron en varias series.

**Cuándo actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `init` | 10 | `maybe_schedule()`: si el evento diario no está agendado, lo agenda — con inicio «ahora» (`:96-100`, el `wp_schedule_event` en `:98`) |
| `akb_upsells_autofill_cron` | 10 | La pasada diaria: hasta 100 productos (`:116-131`) |
| `admin_post_akibara_upsells_run` | 10 | Botón «Procesar siguiente lote» (capacidad `manage_woocommerce` + `nonce` + casilla de confirmación) |
| `admin_post_akibara_upsells_repair` | 10 | Botón «Limpiar enlaces rotos», mismas tres guardas |
| `admin_enqueue_scripts` | 10 | El CSS, sólo en la pestaña **Recomendaciones** |

**La regla.** Rellena **sólo** los productos sin ningún recomendado, con los **tres** tomos
siguientes visibles, comprables y con stock de la misma serie (`:39`, `:1205-1211`,
`:1076-1101`). Nunca pisa una lista escrita a mano, y **firma** lo que escribe
(`_akb_upsells_autofill_sig`) para poder distinguir después «lo escribió el robot y nadie lo tocó»
de curaduría humana.

**La calibración que evitó un desastre.** De 211 enlaces que un criterio anterior llamaba
«inválidos», 210 eran **retrospectivos deliberados** —el patrón «completa tu colección» del último
tomo— y 1 era una referencia cruzada intencional (`:1063-1065`). Por eso el botón de limpieza sólo
retira lo objetivamente roto: destinos borrados o despublicados (`:1051-1061`, `:797-848`).

**Qué lee y escribe.** `_upsell_ids` y `_akb_upsells_autofill_sig` (metas de producto); las options
`akb_upsells_autofill_last_run` y `akb_upsells_autofill_last_repair`, que son **recibos**, no
interruptores; y dos `transient` de 5 minutos con las estadísticas del panel
(`akb_upsells_coverage_v2`, `akb_upsells_health_v2`). Emite `litespeed_purge_post` por cada ficha
modificada, porque LiteSpeed no escucha guardados que sólo tocan metas.

**Dónde lo opera el dueño.** wp-admin → Akibara → pestaña **Recomendaciones**
(`…/wp-admin/admin.php?page=akibara-panel&tab=upsells_autofill`): porcentaje de cobertura, lista de
los que no aplican, salud de los enlaces, vista previa y los dos botones. Y por línea de
comandos: `wp akibara:upsells-fill [--dry-run] [--limit=N] [--force]`.

> **Nota de nombres.** La pestaña **se lee «Recomendaciones»** en el panel; su identificador
> interno es `upsells_autofill` (`Plugin.php:1362-1370`). La palabra «Upsells» no aparece como
> etiqueta en ninguna pantalla: sólo en nombres de clase, de option y de fichero. Quien busque
> «Upsells» en el panel no lo va a encontrar.

> **⚠ Hoy no cuadra — no hay forma de apagarlo sin tocar código.**
> `register()` engancha el cron sin ningún gate de option (`:75-76`: la 75 es
> `add_action( self::CRON_HOOK, … )` y la 76 el `add_action( 'init', … )`); `maybe_schedule()`
> corre en `init` en **cada petición** y reprograma si el evento no existe (`:96-100`); y las dos
> únicas options del módulo son recibos. Borrar el evento con `wp cron event delete` lo devuelve la
> siguiente visita al sitio, y como el primer disparo se ancla en `time()`, esa pasada corre dentro
> de la petición de un cliente. Cada pasada escribe hasta 100 productos y purga su caché de página.
> La pestaña **informa** («Relleno automático diario activo — próxima pasada: …») pero no ofrece
> interruptor. Choca de frente con la regla del dueño de que todo sea operable sin código.
>
> **El único apagado que sí existe hoy** —y que no arregla el problema, pero explica que el evento
> no sea inmortal— es **desactivar el plugin entero**: el `register_deactivation_hook` de
> `akibara-business.php:239-253` retira los seis crons propios, y el de este módulo está en la
> línea **251** (`wp_clear_scheduled_hook( 'akb_upsells_autofill_cron' )`). Al reactivar, el
> `maybe_schedule()` del `init` lo vuelve a agendar. Es decir: no hay interruptor *de este
> trabajo*, hay un interruptor *de todo*.
>
> **Cómo medirlo (espejo Docker).**
> ```bash
> # ¿Cuándo corre? Las horas van en UTC, no en hora de Chile.
> docker compose exec -T cron wp cron event list --fields=hook,next_run_gmt,recurrence \
>   | grep akb_upsells_autofill_cron
> # Borrarlo y comprobar que vuelve con la primera visita:
> docker compose exec -T cron wp cron event delete akb_upsells_autofill_cron
> curl -s -o /dev/null -A AkibaraQA http://localhost:8080/
> docker compose exec -T cron wp cron event list --fields=hook,next_run_gmt | grep akb_upsells
> ```
> Se espera que la última línea vuelva a listarlo. Y que no exista ninguna option de encendido:
> `docker compose exec -T cron wp option list --search='akb_upsells*' --field=option_name` sólo
> devuelve los dos recibos y los dos `transient`. En producción, las mismas órdenes tras
> `ssh akibara`.

### 7. `FreeShippingProgress` y `Services\FreeShippingThreshold` — la barra de envío gratis

**Antes que nada, la regla del negocio, porque sin ella lo demás no se entiende.** En Akibara
**WooCommerce no concede envío gratis**. Quien lo concede es **Blue Express**, dentro de su propia
tarifa: si el pedido supera cierto monto, el transportista deja de cobrar el envío. Por eso las
instancias «Envío gratis» de las zonas de WooCommerce están **deshabilitadas a propósito**:
habilitarlas publicaría una tarifa paralela, gratis y **sin servicio Blue detrás**, que la tienda
tendría que pagar de su bolsillo.

De ahí se deriva todo lo demás. El número que gobierna la promesa real es el del transportista, y
por eso la option que manda en producción es `akibara_fsp_carrier_threshold` («Umbral del
transportista»). El otro campo, `akibara_fsp_threshold` («Umbral envío gratis»), sólo puede
**exigir más** que el real; nunca menos.

**Qué hacen.** Dos piezas con roles distintos: el **servicio** responde «¿hay un camino real al
envío gratis para este carrito y esta zona, y cuánto falta?», y el **módulo** lo presenta.

**Ficheros.** `src/Modules/FreeShippingProgress/FreeShippingProgress.php` ·
`assets/css/free-shipping-progress.css` · `src/Services/FreeShippingThreshold.php`.

**Cuándo actúa el módulo.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `wp_enqueue_scripts` | 20 | Carga el CSS sólo si el mini-carrito está encendido y no estamos en `/carrito/` ni `/checkout/` |
| `akb_native_cart_fsp_html` | 10 | Entrega el HTML de la barra al cajón propio |
| `woocommerce_widget_shopping_cart_before_buttons` | 10 | Pinta la barra en el mini-carrito clásico de WooCommerce |
| `woocommerce_add_to_cart_fragments` | 10 | Refresca la barra del widget tras añadir al carrito |
| `woocommerce_after_calculate_totals` | 10 | Olvida el HTML memoizado cuando el carrito cambió |

**Lo importante del servicio.** No deriva la promesa del menor umbral global: resuelve la zona de
cada paquete, mira sólo los métodos «Envío gratis» habilitados en esa zona y respeta la semántica
de WooCommerce —requisito por cupón, por monto, por ambos o por cualquiera, y si el mínimo ignora
descuentos—. Y **falla cerrado**: sin país en la dirección, sin métodos, o si un tercero vetó el
método, devuelve «no disponible» en vez de inventarse una promesa (`:619-628`).

**Cómo se implementa la regla de arriba.** Como las instancias de zona están apagadas, el servicio
casi nunca encuentra un método «Envío gratis» al que preguntarle. Entonces **sintetiza** uno con el
número del «Umbral del transportista», y sólo si la zona entrega a domicilio (`:256-261`,
`:589-616`). Ese método sintético es la promesa que ve el cliente.

**Qué lee y escribe.** Lee `chosen_shipping_methods` de la sesión. No escribe nada. Once options,
todas en Akibara → Envío gratis
(`…/wp-admin/admin.php?page=akibara-panel&tab=free_shipping_progress`): `akibara_fsp_enabled`,
`akibara_fsp_threshold` (sólo puede exigir más), `akibara_fsp_carrier_threshold` (**el que manda en
producción**, porque es el del transportista), `akibara_fsp_show_cart`,
`akibara_fsp_show_mini_cart`, `akibara_fsp_show_checkout`, `akibara_fsp_show_qualified`,
`akibara_fsp_disable_local_pickup`, `akibara_fsp_title`, `akibara_fsp_message` y
`akibara_fsp_qualified`.

> **Cuidado al cambiar `akibara_fsp_carrier_threshold`.** Ese mismo número alimenta el shortcode
> `[akb_envio_gratis]` de `Modules/ShippingCopy/ShippingCopy.php:40`, que es lo que pinta el monto
> en la barra de anuncios y en la página de preguntas frecuentes. Tocar el campo mueve **tres**
> superficies, no una. Está contado entero en el capítulo 06.

**Qué ve el cliente.** La barra dentro del cajón y, vía el plugin de bloques y el **estado
estructurado** `structured_state()` (`:205-215`) —datos, no HTML—, en `/carrito/` y `/checkout/`.

> **⚠ Hoy no cuadra (1) — el «Umbral envío gratis» cambia el número anunciado pero no el momento de
> calificar.**
> `$threshold = max( $real_threshold, max( 0.0, $override ) )` es lo que se **anuncia**
> (`FreeShippingThreshold.php:497`) y `$amount_met = $total >= $real_threshold` lo que **califica**
> (`:509`). Con transportista en 60.000 y campo del panel en 80.000, un carrito de 65.000 entra por
> `$core_qualified` (`:510-515`) y, como el método sintético trae `current_available => null`
> (`:614`), la condición `false !== $actual_available` (`:517`) se cumple y devuelve
> «calificado». Peor: ese retorno lleva `'threshold' => $real_threshold` (`:523`), así que el
> mensaje de calificado pinta 60.000 mientras el de progreso venía pintando 80.000 — **dos números
> distintos en la misma sesión**. Y la ayuda del propio panel dice, con el número vivo, «este campo
> sólo sirve para exigir más» (`FreeShippingProgress.php:474-476`).
>
> **Cómo medirlo (espejo Docker).**
> ```bash
> docker compose exec -T cron wp option update akibara_fsp_carrier_threshold 60000
> docker compose exec -T cron wp option update akibara_fsp_threshold 80000
> ```
> Con el navegador, armar un carrito de unos **$50.000** con despacho a domicilio: la barra del
> cajón anuncia que faltan $30.000, o sea el umbral de **80.000**. Subir el carrito a unos
> **$65.000** y volver a leer la barra, esta vez desde la respuesta del cajón, que es donde viaja
> su HTML (`NativeCart.php:340`, clave `fsp_html`):
> ```bash
> curl -s -A AkibaraQA -b cookies.txt 'http://localhost:8080/?wc-ajax=akb_get_cart' \
>   | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["fsp_html"])'
> ```
> Se espera el mensaje de **calificado** —con $65.000, que no llega a 80.000— y, si el mensaje
> lleva el monto, el número que pinta es **60.000**: el contrario al que el cliente acaba de leer.
> La misma incoherencia se ve en `/carrito/`, que consume `structured_state()`.
> **Restaurar los dos valores al terminar**, anotando antes los originales con
> `docker compose exec -T cron wp option get akibara_fsp_threshold`.

> **⚠ Hoy no cuadra (2) — `pickup_location` no está donde el mapa decía, y el título manda más de lo
> que debería.**
> En `FreeShippingProgress::is_pickup_rate()` (`:512-518`) el reconocimiento por identificador cubre
> sólo `local_pickup` y `bluex-pudo`; **`pickup_location` no aparece**. Sólo se atrapa por la
> heurística del **título** de la tarifa elegida («retiro» / «pickup»), que es justo el criterio que
> el resto del área declara descartado. La lista con los tres identificadores vive en otro sitio,
> `FreeShippingThreshold::PICKUP_METHOD_IDS` (`src/Services/FreeShippingThreshold.php:38`), y sólo
> gobierna el fallback del transportista.
> Además, `is_pickup_rate()` **ignora el `$rate_id` que recibe** para la comprobación por título:
> `get_chosen_shipping_title()` resuelve siempre `chosen_shipping_methods[0]` y el primer paquete
> (`:520-534`), así que en un pedido con dos envíos juzga el segundo paquete con el título del
> primero. Y cualquier tarifa a domicilio cuya etiqueta contenga «retiro» apaga la barra.
>
> **Cómo medirlo (espejo Docker).** La prueba barata es la del título, y basta un minuto:
> 1. En wp-admin → WooCommerce → Ajustes → Envío, renombrar **temporalmente** una tarifa a
>    domicilio de la zona a «Envío express — retiro rápido».
> 2. Armar un carrito que no llegue al umbral, elegir esa tarifa y abrir el cajón.
> 3. La barra **desaparece**, aunque el pedido vaya a domicilio.
> 4. Devolver el título original. *(La memoria del proyecto avisa: en la zona de Blue Express
>    **nunca** se pulsa «Aplicar» en la pantalla de zonas del plugin del transportista.)*
>
> Y para dejar constancia de que las dos listas no son la misma —`PICKUP_METHOD_IDS` es `private`,
> así que se lee en el fuente, no por `wp eval`—:
> ```bash
> cd public_html/wp-content/plugins/akibara-business
> sed -n '38p'      src/Services/FreeShippingThreshold.php              # los TRES identificadores
> sed -n '512,518p' src/Modules/FreeShippingProgress/FreeShippingProgress.php   # sólo DOS
> ```
> La primera línea trae `local_pickup`, `pickup_location` y `bluex-pudo`; el segundo tramo sólo
> comprueba `local_pickup` y `bluex-pudo`, y para todo lo demás cae en la heurística del título.

### 8. `CheckoutTaxLabels`, `PhoneValidation` y `RutValidation` — los tres retoques chilenos

Tres módulos pequeños con el mismo criterio: el **campo vivo** del checkout pertenece al plugin de
bloques, y business se queda con la regla que no depende de quién pinte.

**`CheckoutTaxLabels`** (`src/Modules/CheckoutTaxLabels/CheckoutTaxLabels.php`) fuerza la palabra
«IVA» en el carrito, el checkout, el pedido y el correo, por tres hooks en prioridad 10:
`woocommerce_countries_tax_or_vat`, `woocommerce_cart_tax_totals` y `woocommerce_order_get_tax_totals`
(`:40-43`, `:76-83`). No toca ni un peso: sólo la etiqueta.

**`PhoneValidation`** (`src/Modules/PhoneValidation/PhoneValidation.php`) normaliza los dos
teléfonos del pedido a `+569XXXXXXXX` **después** de crearse —hook
`woocommerce_store_api_checkout_order_processed`, prioridad 10— y sólo si el número pasa la
validación (`:97-108`). La guarda importa: el formateador antepone `+569` a cualquier cadena de
ocho dígitos, incluidas las que el propio validador rechaza (`00000000`, `11111111`). Guardar eso
sería peor que dejarlo como vino: nadie vuelve a mirar un `+56911111111`.

**`RutValidation`** (`src/Modules/RutValidation/RutValidation.php` · `assets/css/rut-account.css`)
es compatibilidad pura: lee la clave retirada `_billing_rut` para que los pedidos anteriores al
campo nativo sigan mostrando su RUT.

> **⚠ Hoy no cuadra — el RUT antiguo gana en el correo y en Mi cuenta.**
> `display_in_admin_order()` comprueba si existe el RUT del campo nuevo (`_wc_other/akibara/rut`) y
> se calla (`RutValidation.php:49-52`). `add_to_email()` (`:70-82`) y `display_in_my_account()`
> (`:93-104`) **no** hacen esa comprobación: leen `_billing_rut` sin más. Un pedido con las dos
> metas enseña el RUT nuevo en el panel y el viejo en el correo y en el detalle del cliente. Si
> difieren, el cliente y el dueño leen documentos distintos del mismo pedido, y el que llega a la
> bandeja es el viejo.
>
> **Cómo medirlo (espejo Docker).** Hace falta un pedido con **las dos** metas y valores distintos:
> ```bash
> # <id> = un pedido real del espejo, hecho por el checkout de bloques (ya trae el RUT nuevo).
> docker compose exec -T cron wp wc order update <id> --user=1 \
>   --meta_data='[{"key":"_billing_rut","value":"11.111.111-1"}]'
> docker compose exec -T cron wp eval 'foreach ( array( "_billing_rut", "_wc_other/akibara/rut" ) as $k ) { echo $k, " = ", (string) wc_get_order( <id> )->get_meta( $k ), PHP_EOL; }'
> ```
> Después, comparar tres superficies del **mismo** pedido: (a) wp-admin → el pedido, que enseña el
> **nuevo**; (b) `http://localhost:8080/mi-cuenta/view-order/<id>/`, que enseña el **viejo**;
> (c) reenviar el correo desde el panel del pedido («Acciones → Reenviar correo…») y leerlo en
> Mailpit, `http://localhost:8025/`, donde también sale el **viejo**. Borrar la meta de prueba al
> terminar con `wp wc order update <id> --meta_data='[{"key":"_billing_rut","value":""}]'`.

### 9. `AddressAutocomplete` — sugerencias de dirección

> **Mi cuenta → Editar dirección (R3-787c75fa, 1.116.27).** Al montarse sobre un campo que ya traía la calle
> guardada, el componente la vaciaba a la vista. Ahora arranca con el valor existente
> (prueba `attach-carries-existing-value.test.js`).

**Qué hace.** Sugiere direcciones mientras el cliente escribe, con Google Places.

**Ficheros.** `src/Modules/AddressAutocomplete/AddressAutocomplete.php` ·
`assets/js/address-autocomplete.js` · `assets/css/address-autocomplete.css`.

**Cómo está hecho.** Carga perezosa: el script de Google sólo baja cuando el cliente enfoca un
campo de dirección. La clave se resuelve con prioridad —la constante `AKB_GOOGLE_MAPS_API_KEY` de
`wp-config.php` por encima de la option del panel— y el campo se muestra en solo lectura si la
constante existe (`:43-48`, `:223-226`). **Viene apagado por defecto** (`:54`, `:242`). En el
checkout lo usa el proveedor nativo de WooCommerce Blocks; aquí queda sólo el puente clásico para
`/mi-cuenta/edit-address/`, y el comentario del código lo dice explícito: *Checkout … must never
receive this classic script*.

**Dónde lo opera el dueño.** wp-admin → Akibara → pestaña **Autocompletar dirección**
(`…/wp-admin/admin.php?page=akibara-panel&tab=address`), con un semáforo de estado.

> **⚠ Hoy no cuadra (1) — una clave rechazada se descarta en silencio.**
> El rechazo se comunica con `add_settings_error()` (`:72`), que sólo se pinta si alguien llama a
> `settings_errors()`. En todo `src/` esa llamada **no existe ni una vez**: el canal real del panel
> es el filtro `akibara_panel_save_errors`, que `AdminPanel.php:500` recoge y que sí usan
> `NativeCart\Settings`, `OrderPreventa` y `TaxonomyDiscount`. Además `sanitize_api_key()` devuelve
> el valor anterior (`:79`), así que `update_option()` compara igual contra igual, no escribe y
> devuelve `false` sin que nadie lo mire. El dueño pega una clave con un carácter de más, pulsa
> Guardar, lee el mensaje de éxito de siempre y se va convencido de que quedó configurado.
>
> **Cómo medirlo (espejo Docker).**
> ```bash
> docker compose exec -T cron wp option update akibara_google_maps_api_key 'AIzaMARCADOR-DE-PRUEBA-0000000000000'
> ```
> Después, en `…/admin.php?page=akibara-panel&tab=address`, pegar una clave **inválida** (por
> ejemplo `no-es-una-clave`) y pulsar Guardar. El panel responde con su mensaje de éxito de
> siempre. Comprobar que no cambió nada:
> ```bash
> docker compose exec -T cron wp option get akibara_google_maps_api_key
> ```
> Sigue devolviendo el marcador anterior, y **no hubo ningún aviso de error en pantalla**. Que el
> canal usado no se pinta nunca se confirma en el fuente:
> `grep -rn "settings_errors()" public_html/wp-content/plugins/akibara-business/src/` → cero
> resultados. Restaurar la option al valor original al terminar.

> **⚠ Nota operativa — la clave sale al navegador.**
> `CheckoutDataService::address_autocomplete_config()` (`src/Services/CheckoutDataService.php:153-162`)
> entrega la clave **en claro** al plugin de bloques, que la lleva al navegador. No es un defecto —
> una clave de Maps JS es pública por diseño— pero su única protección es la restricción por
> *referrer* configurada fuera de este repositorio, en Google Cloud Console. Si esa restricción se
> cae, cualquiera la usa y la factura es del dueño.
>
> **Cómo comprobarlo.** Con el autocompletar encendido y una clave puesta, cargar `/checkout/` en
> el navegador y buscar la clave en el HTML servido:
> `curl -s -A AkibaraQA http://localhost:8080/checkout/ | grep -o 'AIza[A-Za-z0-9_-]\{35\}'`.
> Que aparezca es lo **esperado**, no el defecto. Lo que hay que verificar está fuera del código:
> en Google Cloud Console, que la clave tenga restricción por *referrer* limitada a `akibara.cl`.

### 10. `PickupCoordination` — el retiro en mano en el Metro San Miguel

**Qué hace.** Explica al cliente que eligió «retiro con nosotros» cómo, dónde y cuándo va a recibir
su pedido.

**Ficheros.** `src/Modules/PickupCoordination/PickupCoordination.php`.

**Por qué existe.** El hueco está medido: un pedido real de retiro cuyo correo decía «Envío: Retiro
en Metro San Miguel ¡GRATIS!» y nada más. Ni cuándo, ni dónde exactamente, ni quién escribe a
quién. La portada sí lo explica, pero **el cliente que ya compró no vuelve a la portada: mira el
correo**.

**Cuándo actúa.** `woocommerce_email_after_order_table` (prioridad 20, 4 argumentos) para el correo
al cliente, y `woocommerce_order_details_after_order_table` (prioridad 20) para la página de gracias
y `/mi-cuenta/view-order/{id}/` (`:57-60`).

**En qué correos, exactamente.** `woocommerce_email_after_order_table` lo emite la plantilla
`templates/emails/email-order-details.php:213` de WooCommerce, o sea **todos** los correos que
llevan la tabla del pedido. Estos son, con el identificador que usa WooCommerce para cada uno
(medido contra WooCommerce 11.1.0, el instalado):

| Identificador | A quién va | ¿Lleva la caja de retiro? |
| --- | --- | --- |
| `customer_processing_order` | Cliente | Sí |
| `customer_completed_order` | Cliente | Sí |
| `customer_on_hold_order` | Cliente | Sí |
| `customer_invoice` | Cliente | Sí |
| `customer_note` | Cliente | Sí |
| `customer_failed_order` | Cliente | Sí |
| `customer_cancelled_order` | Cliente | **Sí — ver el ⚠ de abajo** |
| `customer_refunded_order` / `customer_partially_refunded_order` | Cliente | **Sí — ver el ⚠ de abajo** |
| `new_order` | Administrador | No |
| `cancelled_order` | Administrador | No |
| `failed_order` | Administrador | No |

El **único** filtro del módulo es el argumento `$sent_to_admin` (`PickupCoordination.php:72`), y por
eso los tres de abajo quedan fuera y los ocho de arriba entran. Ojo con el par de nombres parecidos:
`cancelled_order` es el aviso **al administrador**; el que lee el cliente es
`customer_cancelled_order`.

El asunto y el encendido de cada uno se editan en wp-admin → WooCommerce → Ajustes → Correos
(`…/wp-admin/admin.php?page=wc-settings&tab=email`), y cada correo tiene su propia pantalla en
`&section=<identificador en minúsculas>`.

**Cómo reconoce el retiro.** Por el **método real** de la línea de envío, no por su título, que el
dueño puede cambiar cualquier día: `METODOS_DE_RETIRO = array( 'local_pickup', 'pickup_location' )`
(`:46`). `bluex-pudo` queda fuera a propósito: ese retiro es en un local del transportista y lo
coordina él.

**Qué lee y escribe.** Lee la option `joinchat` del plugin de WhatsApp para tomar el número
(`:162-169`); si falta, usa el de respaldo. No escribe nada.

**Dónde lo opera el dueño.** **En ningún sitio.** Es la única superficie de toda el área sin
pantalla: los métodos de retiro (`:46`), el correo `contacto@akibara.cl` (`:49`), el teléfono de
respaldo `+56944242844` (`:52`) y las tres frases del cuerpo (`:116-131` en HTML y `:135-144` en
texto plano) son literales de clase. Lo único editable fuera del código es el teléfono, y sólo si
el plugin de WhatsApp trae uno configurado. Cambiar el punto de entrega exige un despliegue.

> **⚠ Decisión del dueño — la caja sale también en un pedido cancelado o reembolsado.**
> **Este recuadro NO es como los demás: no se arregla en la misma sesión, se pregunta.** Qué debe
> decirle un correo de cancelación a alguien que iba a retirar en mano es una decisión comercial,
> no un defecto de programación, y la regla de la casa es no decidir negocio solo. Lo que aquí se
> documenta es **el hecho medido**, para que quien lo lea sepa exactamente qué está pasando antes
> de preguntar.
>
> El único filtro es «no es para el administrador» y «la línea de envío es retiro» (`:69-93`).
> `woocommerce_email_after_order_table` lo emiten **todos** los correos al cliente —la tabla de
> arriba los enumera—, `customer_cancelled_order` y `customer_refunded_order` incluidos; y
> `woocommerce_order_details_after_order_table` pinta en `/view-order/` sin mirar el estado. Así,
> el correo que anuncia la cancelación lleva debajo «📍 Cómo es el retiro en Metro San Miguel — te
> escribimos en cuanto tu pedido esté listo».
>
> Que el criterio existe en el área lo demuestra el módulo hermano de la misma pantalla:
> `OrderTimeline` sí trata los estados terminales aparte (`OrderTimeline.php:58-62`). Aquí no se
> aplicó. Pero aplicarlo es decidir qué ve el cliente: **eso lo decide el dueño.**
>
> **Cómo medirlo (espejo Docker).** Con un pedido de retiro montado con
> `tools/qa-seed-preventa.php`:
> ```bash
> docker compose exec -T cron wp wc order update <id> --status=cancelled --user=1
> curl -s -A AkibaraQA -b cookies.txt http://localhost:8080/mi-cuenta/view-order/<id>/ \
>   | grep -o 'akb-pickup-coord' | wc -l
> ```
> Se espera **≥ 1**: la caja sigue ahí en un pedido cancelado. Para el correo, reenviarlo desde
> wp-admin → el pedido → «Acciones → Reenviar correo…» y leer el cuerpo en Mailpit,
> `http://localhost:8025/`. Devolver el pedido a su estado original al terminar.

### 11. El retiro en Punto Blue Express (PUDO) — siete ficheros y un candado

**Qué hace.** Permite elegir un local del transportista como destino, y **decide cuánto se le cobra
al cliente por ese envío** con un dato que viene de un tercero. Es la pieza más defensiva de toda
el área, y con motivo.

**Ficheros.** `src/Integrations/Bluex/Pudo/` — **siete ficheros**, uno por clase o interfaz:

| Clase o interfaz | Papel |
| --- | --- |
| `PudoCheckoutService` | El director: canoniza la petición, cotiza, valida el checkout |
| `PickupPointProvider` | Habla con la API de Blue Express y desconfía de lo que responde |
| `PudoSelectionStore` | Proyecta la selección en la sesión, con la forma que espera el plugin del transportista |
| `PudoMutationGuard` | El candado de concurrencia |
| `PudoMutationRepository` | El **contrato** del almacén de estado: qué operaciones hacen falta, sin decir dónde se guardan |
| `WpOptionsPudoMutationRepository` | La implementación viva de ese contrato: guarda con SQL directo, saltándose la caché de opciones |
| `PudoValidationException` | El error con código que viaja hasta el cliente (`pudo_quote_invalid`, `pudo_stale_attempt`…); desde 1.116.31 lleva además `details()` —qué condición concreta falló— que sólo va al registro `akibara-pudo`, nunca al cliente (`R7-pudo-registro-mudo`) |

**El principio.** «Del navegador sólo me fío del identificador del punto.» Nombre, dirección,
comuna, región y **precio** se vuelven a pedir a Blue Express y se guardan en el servidor.

**La huella del carrito.** Cada selección se ata a un `sha256` del contenido del carrito, sus
cantidades, sus totales de línea y el destino (`PudoCheckoutService::cart_fingerprint`). Si el
carrito cambia, la huella cambia y la selección deja de valer: es imposible cobrar la tarifa de un
carrito sobre otro.

**El candado.** Antes de llamar al transportista se abre un **«arriendo»** (*lease*: un turno con
dueño y con hora de caducidad — está en el glosario del capítulo) que invalida cualquier operación
anterior del mismo cliente. Al volver, sólo se confirma si ese arriendo exacto sigue siendo el
vigente (comparar-y-cambiar, hasta 8 intentos: `CAS_ATTEMPTS`, `PudoMutationGuard.php:26`). Así, si
el cliente cambia de punto dos veces y la primera respuesta llega la última, **la vieja no gana**.
Al pulsar «Realizar pedido», la selección se congela 30 segundos mientras se copian los datos: en
esa ventana nadie puede cambiarla.

**Los tiempos, escritos en la clase** (`PudoMutationGuard.php:22-25`): `PENDING_TTL = 60` segundos,
`CHECKOUT_TTL = 30`, `TOMBSTONE_TTL = 900`, `CLEANUP_BATCH = 25`. (La línea `:21` de arriba es
`SCHEMA_VERSION` y la `:26` de abajo es `CAS_ATTEMPTS`, los ocho intentos del párrafo anterior.) La
selección confirmada vive hasta una hora (`PudoSelectionStore.php:53-59`).

**Dónde vive el estado.** En filas `akb_pudo_state_<hmac>` de `wp_options`, siempre sin *autoload*.
La clave de la fila es un HMAC del identificador de sesión, así que ese identificador nunca se
escribe en la base de datos. El valor lleva delante la fecha de caducidad con **ancho fijo**, lo
que permite borrar las filas caducadas con una sola sentencia SQL, sin funciones JSON
(`WpOptionsPudoMutationRepository.php:130-150`). **No hay cron**: la limpieza es oportunista, hasta
25 filas por cada selección (`PudoMutationGuard.php:96` y `:178`).

**La desconfianza del proveedor.** La geolocalización se rechaza por debajo del 80 % de confianza,
si la región no es coherente consigo misma, si el país no es Chile o si el punto devuelto no es el
que el cliente eligió (`PickupPointProvider.php:358-386`). La cotización se rechaza si el total y
la marca de «es gratis» se contradicen (`:305-310`). Y todo texto del proveedor se limpia de
etiquetas antes de mostrarse o guardarse: el origen es la API del transportista, pero esos valores
acaban en el checkout y en la meta del pedido.

**El detalle que da la medida del trabajo.** Las medidas que se envían a cotizar **reproducen las
truncaciones del plugin de Blue Express** (dimensiones vacías o cero → 10; dimensiones
fraccionarias → entero; peso mínimo 0,01). No es capricho: el checkout compara después la
cotización propia con la tarifa del plugin, y si no coincidieran al céntimo el retiro no se podría
ofrecer nunca.

**Dónde lo opera el dueño.** Ninguna pantalla propia; el origen y las credenciales viven en los
ajustes del plugin de Blue Express.

**Si un cliente dice «no me deja elegir el punto»: el primer paso.**

Esta pieza **no escribe en ningún registro**: en todo `src/Integrations/Bluex/` no hay ni una
llamada a `Log::warn` (`grep -rn "Log::" src/Integrations/Bluex/` → cero resultados). Los seis
códigos de rechazo (`pudo_multiple_packages`, `pudo_quote_invalid`, `pudo_stale_attempt`,
`pudo_checkout_in_progress`, `pudo_invalid_agency`, `pudo_invalid_region`) **viajan al navegador
del cliente y no quedan en WooCommerce → Estado → Registros**. Así que la depuración es por estado,
no por registro:

| Lo que reporta el cliente | Qué es | Qué hacer |
| --- | --- | --- |
| «Se queda cargando y no me deja cambiar» (`pudo_checkout_in_progress`) | Hay una confirmación de pedido en curso sobre su sesión | **Esperar 30 segundos** (`CHECKOUT_TTL`, `PudoMutationGuard.php:23`) y reintentar. No hay nada que borrar |
| «Me dice que vuelva a elegir» (`pudo_stale_attempt`) | Llegó una respuesta vieja después de una nueva; la vieja se descarta a propósito | Reintentar. Si se repite siempre, hay dos pestañas del checkout abiertas |
| «Dice que no está disponible» (`pudo_multiple_packages`) | El carrito se partió en varios envíos | No es un fallo: el retiro **no** se ofrece con envíos partidos. Se le explica |
| «El precio no cuadra» (`pudo_quote_invalid`) | La cotización propia y la tarifa del plugin no coinciden al céntimo | Mirar si Blue Express cambió tarifas; la tarifa se retira a propósito antes que cobrar de más |
| Nada de lo anterior encaja | — | Listar el estado guardado (abajo) |

Para ver las filas de estado, por `ssh akibara` en producción o
`docker compose exec -T cron` en el espejo:

```bash
wp option list --search='akb_pudo_state_%' --fields=option_name,autoload
```

Deben salir **pocas** (una por sesión con selección viva) y **todas con `autoload` en `no`**. Si
hay cientos, la limpieza oportunista no está corriendo. **Ninguna de esas filas se borra a mano:**
caducan solas —60 s en pendiente, 1 h confirmadas, 900 s la lápida— y las barre
`cleanup_expired()`, hasta 25 por vez, en la siguiente selección de cualquier cliente.

> **⚠ Hoy no cuadra — el tope de 365 días sólo protege el camino que no hace falta proteger.**
> `strict_promise_day()` hace un retorno temprano: `$number = self::strict_non_negative_integer( $value );
> if ( null !== $number ) { return $number; }` (`PickupPointProvider.php:931-935`). La comparación
> `return 365 >= $parsed ? $parsed : null;` está **después** (`:944`), en la rama de rescate de
> cadenas sucias. O sea: un `promiseDay` entero —que es exactamente lo que manda el JSON de Blue
> Express— o una cadena que casa `\A\d+\z` sale sin pasar por el tope. Un `promiseDay: 99999`
> limpio entra intacto en la cotización guardada y de ahí a lo que se le promete al cliente,
> mientras que `"99999 días"` sí se rechaza. La asimetría es la prueba.
>
> **Cómo medirlo.** El método es `private static`, así que no se llama directamente: se mide por
> `quote()` con un doble del cliente de la API. La prueba va en `tools/test-*.php` y se ejecuta
> desde el docroot —Docker sólo monta `public_html`, así que el script hay que copiarlo allí—:
> ```bash
> cp tools/test-pudo-promise-day.php public_html/
> docker compose exec -T cron php test-pudo-promise-day.php
> rm public_html/test-pudo-promise-day.php
> ```
> Lo que la prueba tiene que enfrentar son **dos** respuestas del transportista que sólo se
> diferencian en el tipo del campo:
> - `{"total":3990,"isShipmentFree":false,"promiseDay":99999}` → **hoy se acepta**, con
>   `promise_day = 99999`.
> - `{"total":3990,"isShipmentFree":false,"promiseDay":"99999 días"}` → hoy **se rechaza** con
>   `bluex_pricing_schema`.
>
> Esa diferencia entre el entero y la cadena sucia es exactamente el defecto. Con el arreglo
> —mover la comparación de 365 **antes** del retorno temprano— las dos deben rechazarse igual.
> **Nunca escribir el script de prueba fuera de `public_html` y dejarlo ahí**: cualquier fichero en
> el docroot se sirve por HTTP.

![Estados de la selección de Punto Blue Express](diagramas/05-business-carrito-checkout-y-cuenta-estados-pudo.mmd)

### 12. `OrderTimeline` — la línea de tiempo del pedido

**Qué hace.** Pinta, encima de la tabla del pedido, en qué paso va.

**Ficheros.** `src/Modules/OrderTimeline/OrderTimeline.php`.

**Cuándo actúa.** `woocommerce_order_details_before_order_table`, prioridad 5 — antes de la tabla.

**Cuántos nodos son.** `build_steps()` construye **tres pasos base** y, si hay espera vigente, un
**cuarto**:

1. «Pago confirmado» o «Esperando pago» (`:183`).
2. *(condicional)* La espera, con la palabra del propio pedido —«Preventa» o «A pedido»— que decide
   `PreventaItems::order_label()` (`:202`).
3. «Preparando despacho» (`:221`).
4. «Despachado», o «Listo para enviar» si el estado es `shipping-progress` (`:244`).

> **Corrección respecto del mapa de lectura.** Nunca hay cinco nodos, y **no existe ningún nodo
> «Entregado»**. Ese «4 + Entregado» sale del docblock obsoleto de la cabecera del fichero
> (`:11-17`), que el mapa copió sin contrastarlo contra el método. La verdad son los cuatro
> `$steps[] =`, uno de ellos condicional.

> **⚠ Hoy no cuadra — el docblock que engañó al mapa sigue publicado en producción.**
> La corrección de arriba no cierra el asunto: **el texto falso sigue dentro del plugin
> desplegado**. `OrderTimeline.php:11-17` afirma hoy, palabra por palabra, que los estados se
> mapean «a 4 nodos visuales: "Pago confirmado" → "Preparando" → "Despachado" → "Entregado"» y que
> con preventa «inyectamos un 5to nodo». Ni el nodo «Entregado» ni el quinto existen en
> `build_steps()`.
>
> Eso no es una anécdota del mapa: es **documentación viva que miente**, dentro del código, sobre
> la pantalla que el cliente mira para saber en qué va su pedido. Ya hizo caer un mapa de lectura;
> el siguiente que la lea se equivocará igual. Por las reglas de la casa —cero código muerto, y la
> deuda previa también se arregla aunque no sea de quien la encuentra— **es trabajo pendiente, no
> curiosidad**. El arreglo es de una línea y no toca comportamiento: reescribir el docblock para
> que diga tres pasos más uno condicional, sin nodo «Entregado».
>
> **Cómo medirlo.**
> ```bash
> cd public_html/wp-content/plugins/akibara-business
> sed -n '11,17p' src/Modules/OrderTimeline/OrderTimeline.php   # lo que el docblock promete
> grep -n '\$steps\[\] =' src/Modules/OrderTimeline/OrderTimeline.php   # lo que el método hace
> ```
> El primero habla de cuatro nodos más un quinto; el segundo devuelve **cuatro** líneas —`:183`,
> `:202`, `:221`, `:244`— y ninguna dice «Entregado».

**No crea estados nuevos:** mapea los siete nativos de WooCommerce más el `shipping-progress` de
Blue Express.

**Tres correcciones que vienen de contradicciones medidas en producción.**

1. La bandera de preventa del pedido **nunca se apaga**, así que un pedido cuya única reserva se
   reembolsó pintaba «En preventa» para siempre. Hoy hace falta además que queden líneas vigentes
   (`:152-156`).
2. El cartel del pedido decía «listo para despachar» mientras el timeline seguía en «En preventa»
   con la pista «Llega ~octubre». La misma página se contradecía. Hoy la espera se cierra cuando la
   mercadería llegó, aunque el pedido siga en «procesando» (`:173-176`, `:193-207`).
3. Con `shipping-progress`, WooCommerce le decía al cliente «Listo para enviar» en la cabecera y el
   timeline decía «Despachado»: dos nombres para un estado, y el más avanzado era el falso — el
   paquete todavía no salía (medido sobre el HTML servido del pedido 31494 el 2026-08-23). Hoy los
   dos dicen lo mismo (`:237-248`).

Y una promesa vencida no se disfraza: si la fecha estimada ya pasó, la pista dice «Llegaba ~octubre
2026 · **atrasado**»; sin estimado vigente, «Fecha por confirmar» (`:272-290`).

**Qué lee.** Las metas `_akb_eta_has_preventa` y `_akb_eta_ready_to_ship` (las escribe
`OrderPreventa`, fuera de este capítulo) y el estimado vigente por
`SummaryRecalculator::current_max_ts()`, único lector canónico. No escribe nada.

**Dónde lo opera el dueño.** En ningún sitio.

> **⚠ Hoy no cuadra — voseo argentino en una tienda chilena.**
> «El pago no llegó a procesarse. Si ya transferiste, **escribinos** por WhatsApp con el
> comprobante» (`:109`). Es la única forma voseante del área; el resto del módulo tutea. Y no es
> editable: está en el código.
>
> **Cómo medirlo.**
> ```bash
> cd public_html/wp-content/plugins/akibara-business
> grep -rn "escribinos\|mandanos\|contanos\|tenés\|querés" src/ templates/
> ```
> Hoy devuelve exactamente **una** línea, `src/Modules/OrderTimeline/OrderTimeline.php:109`. Para
> verlo servido: poner un pedido de prueba en estado `failed`
> (`docker compose exec -T cron wp wc order update <id> --status=failed --user=1`) y buscar la
> palabra en `http://localhost:8080/mi-cuenta/view-order/<id>/`. Devolver el pedido a su estado.

![Estados del pedido tal como los pinta OrderTimeline](diagramas/05-business-carrito-checkout-y-cuenta-estados-pedido.mmd)

### 13. `AccountCollection` — Mi colección (cinco clases y una plantilla)

**Qué hace.** La pantalla privada donde el cliente ve sus tomos agrupados por edición, cuánto lleva
de cada una y cuál le falta. Se pinta entera en el servidor, **sin JavaScript** salvo el interruptor
de avisos.

**Ficheros.** `src/Modules/AccountCollection/AccountCollection.php`, `Endpoint.php`,
`CollectionResolver.php`, `ContinuityResolver.php`, `Renderer.php` ·
`templates/myaccount/collection.php`.

**Cuándo actúa `Endpoint`.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `init` | 10 | Registra la máscara de URL |
| `woocommerce_get_query_vars` | 10 | Da de alta la ruta en el registro de WooCommerce |
| `woocommerce_account_menu_items` | **30** | Inserta «Colección» tras «Pedidos» — después de que `MyAccountUx` ordene, en la 20 |
| `woocommerce_endpoint_coleccion_title` | 10 | Título «Mi colección» |
| `document_title_parts` / `rank_math/frontend/title` | 99 | Alinean el título del navegador con el H1 |
| `woocommerce_account_coleccion_endpoint` | 10 | Pinta la colección |
| `wp_enqueue_scripts` | 10 | CSS y el JS del interruptor por serie, sólo dentro del endpoint |
| `wp_headers` | 10 | `Cache-Control: no-store, private` |
| `template_redirect` | **0** | Define `DONOTCACHEPAGE` y dispara `litespeed_control_set_nocache` |

Se marca como privada por **tres vías a la vez** (`:168-197`). No es paranoia: una colección
cacheada y servida a otro cliente sería una fuga de datos personales.

**Cómo se calcula.**

- `CollectionResolver` hace todo el trabajo de datos con **dos consultas**, no una por serie, y
  **hidrata stock y fecha sólo del tomo candidato**: una edición larga puede tener cientos de
  productos, y construir objetos de WooCommerce para todos en cada visita sería trabajo inútil. Un
  tomo que pertenece a cero series o a más de una queda «sin agrupar»: no se adivina (`:97-102`).
  Cada tomo cuenta una sola vez aunque se haya comprado varias, y una reedición del mismo número no
  suma posición (`:693-704`).
- `ContinuityResolver` es cálculo puro, sin base de datos. Su regla más interesante: **el hueco se
  busca dentro del rango que el cliente tiene** (`:284-308`). Antes barría desde el tomo 1, así que
  a quien empezó la serie más arriba le decía «No vemos el tomo 1 entre tus compras» y le ofrecía el
  tomo 1. Sobre las 31 colecciones reales de producción, **26 recibían la recomendación equivocada**.
- `Renderer` y la plantilla presentan. Y algo que no es cosmético: **el interruptor de avisos se
  pinta en todas las ramas con sesión, incluida la de error**. El consentimiento no depende de que
  la vista de compras funcione, y quien se dio de baja desde un correo aterriza justo aquí. Si el
  interruptor global está apagado, las tarjetas **no** pintan su botón por serie, porque encender
  una serie levantaría la baja global (`Renderer.php:241-283` y `templates/myaccount/collection.php:210-226`).

**Qué lee y escribe.** Lee las tablas de términos y de productos por consulta directa preparada, y
las bajas de `SeriesFollow` desde `usermeta`. **Esta pantalla, por sí misma, no escribe nada.** Las
dos únicas vías de escritura son de `SeriesFollow` —cuya ficha completa está en el capítulo 04— y
conviene tenerlas a mano, porque son lo que hay que mirar cuando alguien dice «el interruptor no
guarda»:

| Vía | Detalle exacto | Quién puede |
| --- | --- | --- |
| **El interruptor global** («Avisos de mi colección») | Un **POST a la propia página** `/mi-cuenta/coleccion/`, que atiende `SeriesFollow\GlobalToggle::handle()` enganchado a `template_redirect` **prioridad 1** (`GlobalToggle.php:91`). El campo se llama `akb_sf_global` y vale `on` u `off` (`:53`); el `nonce` viaja en el campo `_akb_sf_nonce` (`:56`) con la acción `akb_sf_global_<id del usuario>` (`:59`). No hay `admin_post`: es un POST normal, con patrón POST-redirección-GET también cuando falla | Cualquier cliente **sobre sus propios datos**. No se exige ninguna capacidad de administrador: la guarda es sesión iniciada + `nonce` (`:110-129`) |
| **El botón por serie** | `POST /wp-json/akibara/v1/series-follow` — ruta REST registrada en `SeriesFollow\CollectionToggle.php:47-55` (`REST_NAMESPACE = 'akibara/v1'`, `REST_ROUTE = '/series-follow'`) | Sesión iniciada **y** `nonce` de REST válido en la cabecera `X-WP-Nonce` con la acción `wp_rest` (`::can_toggle`). Sin el `nonce`, un sitio ajeno podría dar de baja las series del cliente con su propia cookie |

Para depurar «el interruptor global no guarda», el orden es: ¿llega el POST con el campo
`akb_sf_global`? ¿lleva `_akb_sf_nonce`? ¿hay sesión? Los tres fallos dejan un aviso de error en
pantalla y redirigen igual, así que un redirect sin cambio **es** el síntoma.

**Dónde lo opera el dueño.** Ninguna pantalla. Los fallos salen en WooCommerce → Estado → Registros
(`…/wp-admin/admin.php?page=wc-status&tab=logs`). **Ojo con el nombre:** `Utilities\Log::warn()`
compone el canal como `'akibara-' . $modulo` (`src/Utilities/Log.php:43`), y este módulo pasa
`'coleccion'` (`AccountCollection/Renderer.php:42-43`). En el desplegable de Registros los ficheros
se llaman por tanto **`akibara-coleccion-<fecha>`**: buscando «coleccion» a secas no aparecen.

**Qué ve el cliente.** `https://akibara.cl/mi-cuenta/coleccion/`.

### 14. `MyAccountUx` — Mi cuenta usable con el pulgar

**Qué hace.** Renombra y reordena las pestañas de Mi cuenta y sustituye cuatro plantillas de
WooCommerce.

**Ficheros.** `src/Modules/MyAccountUx/MyAccountUx.php` · `templates/myaccount/dashboard.php`,
`orders.php`, `my-address.php`, `form-edit-account.php`.

**Cuándo actúa.**

| Hook | Prioridad | Qué hace |
| --- | --- | --- |
| `woocommerce_account_menu_items` | 20 | Renombra («Resumen», «Detalles», «Direcciones») y ordena: Pedidos, Recompensas, Favoritos, Direcciones, Detalles, extras, Resumen y Salir al final (`:166-188`) |
| `woocommerce_valid_order_statuses_for_order_again` | 10 | Devuelve lista vacía: «Volver a comprar» deja de existir (`:54`) |
| `woocommerce_locate_template` | 10 | Sustituye las cuatro plantillas, **sólo si el tema no las ha sustituido antes** |
| `woocommerce_my_account_my_address_description` | 10 | Reescribe el texto de la cabecera de Direcciones |
| `woocommerce_save_account_details_required_fields` | 10 | Quita el nombre visible de los obligatorios |
| `woocommerce_save_account_details_errors` | **5** | Rellena el nombre visible antes de que WooCommerce guarde |
| `woocommerce_save_account_details` | 10 | Retira el aviso de contraseña temporal |
| `woocommerce_my_account_my_orders_actions` | 20 | Quita «Volver a comprar» de cada fila (cinturón además del tirante) |
| `wp_enqueue_scripts` | 10 | El medidor de contraseña, sólo en la pantalla de detalles |

**Dos decisiones del dueño escritas en el código.**

- **«Volver a comprar» no existe.** En una tienda de tomos seriados nadie recompra el mismo tomo.
  No basta con quitarlo de las filas: la plantilla del detalle lo emitía por su cuenta. Se vacía la
  **lista de estados que lo permiten**, así el núcleo no lo pinta en ninguna superficie ni acepta su
  URL (`:54`, `:321-325`).
- El campo «nombre visible» se retiró del formulario. Eso tenía una consecuencia invisible y cara:
  llegaba vacío al guardado, `wp_update_user` fallaba **en silencio** y **no se guardaba nada** —ni
  nombre, ni apellido, ni correo—. Hoy el nombre visible se compone solo a partir del nombre y el
  apellido.

**Qué lee y escribe.** La meta de usuario `default_password_nag`, que se pone a falso tras un cambio
de contraseña desde Mi cuenta. Las plantillas propias **re-emiten** los hooks de extensión de
WooCommerce (`woocommerce_account_dashboard`, `woocommerce_my_account_my_orders_column_*`,
`woocommerce_my_account_after_my_address`) para que lo que se cuelga ahí —por ejemplo la columna
«Llegada» de `OrderPreventa`— siga funcionando.

**Dónde lo opera el dueño.** Ninguna pantalla propia.

> **⚠ Hoy no cuadra (1) — el mismo texto escrito dos veces, y sólo una manda.**
> `MyAccountUx::address_description()` devuelve su propio literal e **ignora el argumento** que
> recibe (`:224-226`), mientras `templates/myaccount/my-address.php:52` pasa un literal idéntico a
> `apply_filters( 'woocommerce_my_account_my_address_description', … )`. El filtro siempre gana, así
> que el de la plantilla es **texto muerto**: quien lo edite verá que no cambia nada en pantalla.
>
> **Cómo medirlo (espejo Docker).** Cambiar **sólo** el literal de la plantilla y recargar:
> ```bash
> cd public_html/wp-content/plugins/akibara-business
> sed -i.bak '52s/Estas son tus direcciones guardadas/PRUEBA PLANTILLA/' templates/myaccount/my-address.php
> curl -s -A AkibaraQA -b cookies.txt http://localhost:8080/mi-cuenta/edit-address/ | grep -c 'PRUEBA PLANTILLA'
> ```
> Se espera **0**: el texto servido no cambia. Repitiendo el cambio sobre
> `MyAccountUx::address_description()` (`:224-226`) sí cambia. Restaurar con el `.bak` al terminar.

> **⚠ Hoy no cuadra (2) — el medidor de contraseña no puede decir «No coincide».**
> `wp.passwordStrength.meter( pwd, …, pwd )` (`templates/myaccount/form-edit-account.php:134`) pasa
> la misma contraseña como tercer argumento, que es el de **confirmación**. Comparar `pwd` consigo
> misma hace imposible el score 5, que es justo el que el código tiene mapeado a «No coincide»
> (`:111-126`). El campo `#password_2` no se compara nunca en el cliente: el cliente descubre que se
> equivocó al enviar, y WooCommerce le devuelve el formulario con los tres campos vacíos.
>
> **Cómo medirlo.** En `http://localhost:8080/mi-cuenta/edit-account/`, con sesión: escribir una
> contraseña en «Nueva contraseña» y **otra distinta** en «Confirmar». El texto de
> `#akb-pwd-strength` sigue mostrando la fortaleza («Media», «Fuerte»…) y **nunca** dice «No
> coincide». Con el arreglo —pasar `document.getElementById('password_2').value` como tercer
> argumento y escuchar también los cambios de ese campo— sí debe aparecer.

> **⚠ Hoy no cuadra (3) — dos tarjetas del panel pueden llevar a un 404.**
> `templates/myaccount/dashboard.php:62` y `:94` emiten `wc_get_endpoint_url( 'coleccion' )` y
> `wc_get_endpoint_url( 'favoritos' )` **sin comprobar que el endpoint exista**, mientras la de
> Recompensas sí se protege con `function_exists( 'spar_get_user_points' )` (`:73`) — función que
> hoy publica el plugin propio `akibara-rewards`, no ningún tercero (ver la aclaración de «Cómo
> encaja»). Con el módulo propio desactivado, esas dos tarjetas llevan a una página que no existe.
>
> **Cómo medirlo (espejo Docker).** El endpoint `favoritos` lo registra `Wishlist\Endpoint` dentro
> del mismo plugin, así que la forma limpia de reproducirlo es pedir la URL de un endpoint no
> registrado:
> ```bash
> docker compose exec -T cron wp eval 'echo wc_get_endpoint_url( "no-existe-este-endpoint" ), PHP_EOL;'
> curl -s -o /dev/null -w '%{http_code}\n' -A AkibaraQA http://localhost:8080/mi-cuenta/no-existe-este-endpoint/
> ```
> La primera orden **devuelve una URL igualmente**, sin quejarse; la segunda devuelve **404**. Eso
> es exactamente lo que pasaría con las dos tarjetas si sus endpoints dejaran de registrarse:
> enlace pintado, destino inexistente, y ningún aviso por el camino.

### 15. `GoogleLoginButton` y `AccessWall` — el muro de acceso

**Qué hace.** La pantalla que ve quien pulsa un enlace de la cuenta sin haber entrado: «Continuar
con Google» arriba y dos desplegables debajo para correo/contraseña y para crear cuenta.

**Ficheros.** `src/Modules/GoogleLoginButton/GoogleLoginButton.php`, `AccessWall.php` ·
`templates/myaccount/form-login.php`.

**Cuándo actúa.** `woocommerce_locate_template` (10) sirve la plantilla propia;
`rtcamp.google_default_redirect` (10) le da al plugin de Google el mismo destino saneado que usan
los otros dos caminos; `woocommerce_login_form_end` (1) desengancharía el campo `redirect` sin
validar de WooCommerce Blocks (ver el ⚠ de abajo).

**`AccessWall` responde dos preguntas.**

- **Qué dice el cartel.** Hay textos propios por endpoint —favoritos, colección, pedidos— porque el
  cartel genérico no nombraba ni una vez lo que el cliente había ido a buscar: en
  `/mi-cuenta/favoritos/` la permanencia media era de 9,5 segundos **(auditoría de UX del
  2026-09-05; la medición está fechada en el docblock del propio módulo, `AccessWall.php:10-13`)**.
  Y sólo nombra un producto
  **publicado**: un borrador o un privado no filtran su título (`AccessWall.php:255-277`), porque el
  `redirect_to` lo escribe cualquiera.
- **A dónde vuelve.** El mismo destino saneado se hornea como campo oculto en los dos formularios y
  se le pasa al botón de Google por el filtro que su propio plugin expone. Hasta la versión 1.115.2
  ese botón volvía al muro, no al tomo — y los comentarios del código afirmaban lo contrario.

**La validación del destino vive en un solo sitio**, `NavigationLinks::safe_internal_destination()`
(`src/Services/NavigationLinks.php:95-156`), y se usa en los dos extremos del viaje. Rechaza
destinos externos, caracteres de control —porque `esc_url()` reescribe la cadena al imprimirla y un
salto de línea colaba un destino distinto del comprobado— y `/wp-admin/` o `wp-login.php` **aunque
vengan codificados varias veces**: una guarda que se salta codificando un guion no es una guarda.

**Qué lee.** Diez copys en Akibara → Textos (`akb_copy_wall_title`, `akb_copy_wall_sub`,
`akb_copy_wall_sub_login`, y los pares `…_favoritos_…`, `…_coleccion_…`, `…_orders_…`, más
`akb_copy_wall_return`) y tres ajustes de WooCommerce → Cuentas
(`woocommerce_enable_myaccount_registration` en `yes`,
`woocommerce_registration_generate_password` en `yes`, `woocommerce_registration_generate_username`).

**Dónde lo opera el dueño.** wp-admin → Akibara → pestaña **Textos**
(`…/wp-admin/admin.php?page=akibara-panel&tab=copys`), grupo del muro de acceso.

> **⚠ Hoy no cuadra — el hallazgo más grave del capítulo: ocho hooks de WooCommerce están
> comentados.**
> En `templates/myaccount/form-login.php`, ocho `do_action` van escritos así, en una sola línea:
> `<?php // phpcs:ignore … do_action( 'woocommerce_register_form' ); ?>`. El `//` comenta **hasta el
> final de la línea**, así que el `do_action` que va detrás **nunca se ejecuta**. Son las líneas 77
> (`woocommerce_login_form_start`), 95 (`woocommerce_login_form`), 108 (`woocommerce_login_form_end`),
> 119 (`woocommerce_register_form_tag`), 120 (`woocommerce_register_form_start`), 153
> (`woocommerce_register_form`), 160 (`woocommerce_register_form_end`) y 167
> (`woocommerce_after_customer_login_form`).
>
> **Qué rompe:** cualquier campo que un tercero quiera pintar en el alta —casilla de privacidad o de
> términos, un captcha, la casilla de novedades del proveedor de campañas— **no aparece nunca**, y
> nadie recibe un error. Con `woocommerce_register_form` muerto desaparece además el **aviso de
> política de privacidad del registro** que emite el propio WooCommerce
> (`wc_registration_privacy_policy_text()`): un texto ya escrito y aprobado en WooCommerce → Ajustes
> → Cuentas y privacidad que hoy **no se muestra a nadie**. Y el propio
> `GoogleLoginButton::drop_unvalidated_redirect_field`, enganchado a `woocommerce_login_form_end`, es
> código muerto.
>
> Que es un descuido y no una decisión lo demuestra la plantilla hermana: `form-edit-account.php`
> usa `/* … */` y sus siete hooks **sí** se disparan; y en el mismo fichero, el único `do_action`
> que está en su propia línea sin `//` —`woocommerce_before_customer_login_form`, línea 28— **sí**
> corre. Es además exactamente la trampa que el dueño ya tiene documentada: un candado de fuente que
> quita comentarios antes de mirar no lo ve.
>
> **Cómo medirlo (espejo Docker).** La consecuencia legal es la más fácil de medir, porque deja un
> marcador en el HTML:
> ```bash
> # 1. Escribir un texto en wp-admin → WooCommerce → Ajustes → Cuentas y privacidad
> #    → «Política de privacidad del registro».
> # 2. Contar el marcador que el núcleo imprimiría:
> curl -s -A AkibaraQA http://localhost:8080/mi-cuenta/ \
>   | grep -o 'woocommerce-privacy-policy-text' | wc -l
> ```
> Hoy devuelve **0**. Con el `do_action( 'woocommerce_register_form' )` descomentado debe devolver
> **1**. (De nuevo: `grep -c` cuenta líneas y el HTML puede venir minificado en una sola; hay que
> contar con `grep -o … | wc -l`.) Y para ver el descuido en crudo:
> ```bash
> grep -n 'do_action' public_html/wp-content/plugins/akibara-business/templates/myaccount/form-login.php
> ```
> Nueve resultados: ocho llevan `//` delante en la misma línea; el de la línea 28
> (`woocommerce_before_customer_login_form`) está solo y **sí** corre.
>
> **Tres arreglos que van juntos, no uno.** Quien lo tome tiene que hacer los tres, y en este
> orden:
>
> 1. **Comparar la copia contra el original antes de tocar nada.** `templates/myaccount/form-login.php`
>    es una **copia** de la plantilla homónima de WooCommerce, y la copia se hizo hace versiones.
>    Descomentar los ocho hooks sin comparar puede reintroducir campos que se quitaron a propósito.
>    El original es
>    `public_html/wp-content/plugins/woocommerce/templates/myaccount/form-login.php` — el de la
>    versión **instalada**, no el de la documentación:
>    ```bash
>    diff -u public_html/wp-content/plugins/woocommerce/templates/myaccount/form-login.php \
>            public_html/wp-content/plugins/akibara-business/templates/myaccount/form-login.php
>    ```
> 2. **Descomentar, empezando por `woocommerce_register_form` (línea 153)**, que es el que devuelve
>    el aviso legal de privacidad: es el de mayor valor y el más fácil de verificar con el `grep`
>    de arriba. Después los otros siete, uno a uno, verificando el HTML servido entre medio.
> 3. **Reponer el desenganche del campo `redirect` sin validar.** Hoy **no** hay dos campos
>    `redirect` compitiendo, precisamente porque ese `do_action` comentado
>    (`woocommerce_login_form_end`, línea 108) es el único punto por el que el campo de Blocks se
>    imprimiría. Al descomentarlo, `GoogleLoginButton::drop_unvalidated_redirect_field` —que hoy es
>    código muerto— vuelve a ser necesario, y hay que comprobar que corre.

### 16. `MarketingConsent` — la prueba del consentimiento

**Qué hace.** Guarda dentro del propio pedido qué aceptó el cliente exactamente, con el texto que
vio.

**Ficheros.** `src/Modules/MarketingConsent/MarketingConsent.php`.

**Por qué existe.** La casilla de «ofertas y novedades» del checkout la registra el plugin del
proveedor de campañas (hoy Brevo), y WooCommerce ya guarda su valor. Pero eso **no prueba qué
aceptó el cliente**: la etiqueta la edita el dueño en la interfaz del proveedor y puede cambiar
mañana.

**Cuándo actúa.** `woocommerce_set_additional_field_value` (10) guarda la prueba y añade la nota
privada cuando la casilla **viajó** en el POST; `woocommerce_store_api_checkout_update_order_from_request`
(10, `:207-224`) cierra el caso que el bloque no envía —la casilla **sin tocar** no va en
`additional_fields`, medido el 2026-09-19— guardando la prueba «NO» con el texto que el cliente
vio, y escribe la nota que quedó pendiente si la prueba se grabó cuando el pedido aún no tenía ID
(WooCommerce copia la sesión al pedido antes de su primer `save()`, `:315-341`; y si la decisión
cambia con el pedido ya guardado, la nota pendiente se descarta para que la última nota nunca
contradiga la prueba); `woocommerce_store_api_checkout_order_processed` (10) comprueba que el
pedido no quedara sin prueba.

**Qué guarda.** La meta `_akb_marketing_consent`, un JSON con `value`, `label`, `at`, `source` y
`policy_modified_gmt`. La etiqueta la lee del registro de campos de WooCommerce —que es lo que el
checkout realmente pintó— y elige la variante «(opcional)», porque es la que el cliente leyó
(`:435-459`). No habla con el proveedor: **cero llamadas a su API**. El nombre del campo,
`SendinblueWoocommerce/newsletter_opt_in`, se puede cambiar por el filtro
`akibara_marketing_consent_field_key` (`:101`, `:145-156`) — sin pantalla.

**Dos cuidados.** Una edición desde el panel **no es consentimiento**: la prueba no se toca y el
pedido recibe una nota que lo dice (`:180-183`, `:349-371`). Y si el valor y la etiqueta no
cambiaron, no se reescribe nada: un pedido lleva una nota por decisión, no una por tecla
(`:187-190`). Y la primera vez que la prueba se escribe puede ser sobre un pedido que aún no tiene ID
(WooCommerce copia la sesión al pedido antes de su primer `save()`): ahí la nota queda pendiente y
sale al cerrar `update_order_from_request` (`:315-341`).

**Dónde lo opera el dueño.** La nota privada en el pedido, y los avisos en WooCommerce → Estado →
Registros (`…/wp-admin/admin.php?page=wc-status&tab=logs`). **El canal se llama
`akibara-consentimiento`**, no «consentimiento» a secas: el módulo pasa `'consentimiento'`
(`MarketingConsent.php:250-251`) y `Utilities\Log::warn()` le antepone `akibara-`
(`src/Utilities/Log.php:43`). En el desplegable hay que buscar **`akibara-consentimiento-<fecha>`**.
El aviso es **por pedido afectado** (hasta 1.116.29 era uno al día con un transient que el object
cache de producción perdía en cada purga de LiteSpeed) y dice si el campo está registrado en
WooCommerce (`:235-257`): por la ruta real sólo falta la prueba cuando no lo está, porque el cierre
de `update_order_from_request` la escribe siempre que lo esté.

> **⚠ Hoy no cuadra (1) — `is_admin()` distingue mal al cliente del dueño.**
> `if ( is_admin() )` (`:180`) es lo único que separa «lo marcó el cliente» de «lo cambió el panel».
> Un pedido creado o actualizado por WP-CLI, por cron o por Action Scheduler corre con `is_admin()`
> en `false` y **escribiría una prueba** con `source => 'checkout-blocks'` y `at => ahora`,
> afirmando un consentimiento que nadie dio en ningún checkout.
>
> **Cómo medirlo (espejo Docker).** Escribir el campo desde la línea de comandos, que es un
> contexto sin panel, y mirar la prueba que queda:
> ```bash
> docker compose exec -T cron wp eval '
>   $o = wc_get_order( <id> );
>   do_action( "woocommerce_set_additional_field_value", "SendinblueWoocommerce/newsletter_opt_in", true, "other", $o );
>   $o = wc_get_order( <id> );
>   echo (string) $o->get_meta( "_akb_marketing_consent" ), PHP_EOL;'
> ```
> Se espera un JSON con `"source":"checkout-blocks"` y un `at` de ahora mismo, sobre un pedido en el
> que **nadie** marcó nada en ningún checkout. Usar un pedido de prueba creado con
> `tools/qa-seed-preventa.php`, **nunca un pedido real**, y devolverlo a su estado al terminar.

> **Resuelto (2), 2026-09-19 — el aviso ya no se silencia por día.** Hasta 1.116.29 un transient
> global de 24 h dejaba **un** pedido en el registro y callaba el resto; en producción, además, el
> transient vivía en el object cache externo y cada purga de LiteSpeed lo borraba (dos avisos con
> 16 h de diferencia el 2026-09-16), así que ni cuadraba la cuota ni se sabía a cuántos pedidos
> alcanzaba. Hoy es un aviso por pedido afectado y sin estado (`R6-consentimiento-sin-tocar`).
>
> **Lo que la ronda 6 encontró con evidencia de producción (2026-09-19).** Ningún pedido real llevó
> jamás `_akb_marketing_consent`. Dos causas, las dos en el checkout de bloques: **(a)** la casilla
> sin tocar **no viaja** en `additional_fields`, WooCommerce no dispara el hook del campo y el
> pedido cerraba sin prueba —el aviso diario culpaba al proveedor sin que hubiera cambiado nada—;
> **(b)** con la casilla marcada, el bloque la guarda en la sesión con cada cambio y, al pulsar
> «Realizar el pedido», WooCommerce la copia al pedido recién construido **antes de su primer
> `save()`**: la prueba quedaba, la nota privada no (`add_order_note()` sin ID devuelve 0). Las dos
> se cierran en `on_order_from_request()` (`:207-224`): prueba «NO» cuando el campo está
> registrado y no viajó, y nota pendiente escrita cuando el pedido ya tiene ID (`:315-341`).
> Medido por la ruta real en `tools/akibara-e2e/specs/consentimiento-de-ofertas.spec.mjs`.

### 17. `GuestOrderLinking` — reclamar compras hechas sin cuenta

**Qué hace.** Sustituye la caja de WooCommerce «Confirma tu dirección de correo electrónico para
comprobar si hay pedidos anteriores» por una línea corta y constante.

**Ficheros.** `src/Modules/GuestOrderLinking/GuestOrderLinking.php`.

**Cuándo actúa.** `woocommerce_customer_email_verification_should_show_prompt` (10) devuelve
**siempre** `false` y anota que WooCommerce iba a pintar el aviso (`:162-170`);
`woocommerce_before_account_orders` (20) pinta la línea propia (`:176`).

**Por qué el rodeo.** La vinculación automática está **retirada desde el 2026-08-23** (`:3-61`),
porque abría una fuga: el alta de WooCommerce autentica en el acto y no pide contraseña, así que
cualquiera podía reclamar el historial de otro registrándose con su correo. Y por eso la línea se
pinta **igual para todos** los no verificados: medido el 2026-09-11, 78 cuentas sin verificar la
veían y sólo 4 tenían algo que reclamar, pero condicionar el aviso a *si ese correo tiene pedidos*
convertiría la pantalla en un lector —bastaría registrarse con el correo de un desconocido para
saber si compró aquí—. **La constancia es la protección.**

**Qué lee y escribe.** Nada propio: copia el nombre de parámetro y la acción de `nonce` del
`VerificationController` de WooCommerce para armar el enlace.

**Dónde lo opera el dueño.** En ningún sitio.

> **⚠ Hoy no cuadra — la línea depende de que WooCommerce siga preguntando por su filtro.**
> `pintar_invitacion()` sale de inmediato si `$this->aviso_apagado` es falso (`:176`), y esa bandera
> sólo se pone a `true` dentro de `silenciar_aviso()` cuando WooCommerce consulta su filtro con una
> decisión afirmativa (`:162-170`). Si WooCommerce renombra o retira ese filtro, la línea desaparece
> **sin error y sin que nadie se entere**.
>
> **Cómo medirlo (espejo Docker).** Simular la desaparición del filtro y contar la línea propia en
> el HTML servido, con una cuenta **sin verificar** que tenga sesión:
> ```bash
> # 1. Estado de hoy: la línea propia SÍ se pinta.
> curl -s -A AkibaraQA -b cookies.txt http://localhost:8080/mi-cuenta/orders/ \
>   | grep -o 'akb-orders-claim' | wc -l
> # 2. Quitarle a WooCommerce la consulta del filtro, y volver a contar:
> docker compose exec -T cron wp eval '
>   add_filter( "woocommerce_customer_email_verification_should_show_prompt", "__return_false", 1 );
>   echo "filtro cortocircuitado antes de que llegue a GuestOrderLinking", PHP_EOL;'
> ```
> La forma honesta de reproducirlo en vivo es añadir ese `__return_false` en prioridad **1** desde un
> mu-plugin temporal del espejo —nunca de producción— y recargar `/mi-cuenta/orders/`: el contador
> del paso 1 pasa a **0**, y no hay ningún error, ni en pantalla ni en Registros. Retirar el
> mu-plugin al terminar.

### 18. `Security` — lo que impide que te enumeren

**Qué hace.** No es una pantalla, es un conjunto de parches. Cierra los caminos por los que un
desconocido podía averiguar qué correos tienen cuenta, y tapa dos agujeros del plugin de Blue
Express sin editar su código.

**Ficheros.** `src/Modules/Security/Security.php`.

**Los tres caminos de enumeración, cerrados con el mismo criterio: responder siempre lo mismo.**

| Camino | Qué filtraba | Cómo se cerró | Dónde |
| --- | --- | --- | --- |
| «Olvidé mi contraseña» | Redirigía distinto según existiera la cuenta | Se ejecuta el proceso real y se redirige **siempre** a `?reset-link-sent=true` | `wp_loaded` prio 15, `:410-462` |
| Login | «El nombre de usuario X no está registrado» vs «la contraseña es incorrecta» | Cuatro códigos colapsados en un mensaje genérico con enlace a restablecer | `authenticate` prio 99, `:103-120` |
| Alta | «Ya existe una cuenta registrada con …» | Se adelanta al punto filtrable y responde algo que no confirma nada | `woocommerce_process_registration_errors` prio 99, `:151-182` |

**Además cierra la lista pública de usuarios**: `/wp-json/wp/v2/users` (`rest_endpoints` prio 10,
`:344-366`) y el archivo de autor —`?author=N`, `/author/slug/`— con un 404 real (`parse_request`
prio 1, `:376-399`). Una cuenta de cliente **no** elude el control por haber iniciado sesión.

**Y tapa dos agujeros de Blue Express sin editarlo:** seis acciones AJAX
(`wp_ajax_*` y `wp_ajax_nopriv_*`, prioridad **−1000**) y todas sus rutas REST administrativas
`/wc-bluex/v1/*` (`rest_endpoints` prio 1000) pasan a exigir `manage_woocommerce`, y sus respuestas
se marcan como no almacenables (`rest_post_dispatch` prio 1000) — Cloudflare llegó a cachear
públicamente su pantalla de configuración. Como su propia interfaz no adjuntaba el `nonce` que ella
misma genera, se le añade un puente (`admin_print_footer_scripts` prio 1) para que siga funcionando
después del cierre.

**Dónde lo opera el dueño.** En ningún sitio: no hay ajustes.

> **⚠ Hoy no cuadra (1) — queda una tercera superficie de enumeración abierta.**
> El módulo cierra REST y archivo de autor, pero **no toca el sitemap de usuarios de WordPress**
> (`/wp-sitemap-users-1.xml`): en todo el fichero no hay ningún filtro `wp_sitemaps_add_provider` ni
> equivalente. El impacto en Akibara es bajo —sólo salen autores con entradas publicadas, y Rank
> Math suele servir su propio sitemap— pero es un hueco que el mapa daba por cerrado, y conviene
> medirlo antes de darlo por cerrado.
>
> **Cómo medirlo (espejo Docker).** Las dos superficies cerradas, contra la tercera:
> ```bash
> curl -s -o /dev/null -w 'REST   %{http_code}\n' -A AkibaraQA http://localhost:8080/wp-json/wp/v2/users
> curl -s -o /dev/null -w 'AUTOR  %{http_code}\n' -A AkibaraQA 'http://localhost:8080/?author=1'
> curl -s -A AkibaraQA http://localhost:8080/wp-sitemap-users-1.xml | head -20
> ```
> Se esperan **401/403** en la primera, **404** en la segunda y, en la tercera, o bien un 404 —si
> Rank Math sirve su propio sitemap y el nativo está apagado— o bien una lista de autores. Ese
> tercer resultado es el que hay que medir **antes** de declarar el hueco cerrado o abierto. Y en
> el fuente: `grep -n "wp_sitemaps" src/Modules/Security/Security.php` no devuelve nada.

> **⚠ Hoy no cuadra (2) — se borra más cola de avisos de la necesaria.**
> `wc_clear_notices()` se llama **sin el argumento del grupo** (`:448`), dentro de
> `intercept_lostpassword_post()` y justo antes del redirect. Se lleva por delante los avisos de
> `success` y `notice`, no sólo el `error` de «Invalid username or email» que se quiere ocultar.
>
> **Cómo medirlo.** En el fuente, el contraste con el módulo que sí lo hace bien:
> ```bash
> cd public_html/wp-content/plugins/akibara-business
> grep -rn "wc_clear_notices" src/
> ```
> `Security.php:448` llama `wc_clear_notices()` sin argumento; `NativeCart.php` lo llama con
> `'error'`. En vivo: encolar un aviso de éxito con
> `docker compose exec -T cron wp eval 'wc_add_notice( "MARCADOR", "success" );'` no sirve —cada
> petición de WP-CLI tiene su propia sesión—, así que la medición honesta es enviar el formulario
> de «Olvidé mi contraseña» desde el navegador **en la misma carga** en que haya otro aviso de
> éxito en cola, y comprobar que desaparece junto con el de error.

### 19. Los servicios compartidos

- **`Services\CheckoutDataService` + `Contracts\CheckoutDataProvider`**
  (`src/Services/CheckoutDataService.php`, `src/Contracts/CheckoutDataProvider.php`) — el único
  puente con el plugin de bloques. Entrega datos con forma declarada: avisos de pack, estado del
  envío gratis, resumen de preventa, sellos de confianza y, por producto, stock, palabra comercial y
  fecha. **Nunca HTML.** Se apoya en `EstimatedDelivery`, `SeriesBundles`, `FreeShippingProgress`,
  `AddressAutocomplete`, `Stock`, `Utilities\ChileanPhone`, `Utilities\RutValidator` y
  `CheckoutTrustSignals`.
- **`Services\CheckoutTrustSignals`** (`src/Services/CheckoutTrustSignals.php`) — los tres sellos del
  checkout. El de las cuotas es editable porque es una promesa **financiera** que cambia con el
  medio de pago, y sale de **una sola** option (`akb_copy_promise_installments`, `:22-25`, `:70-75`)
  para que la ficha y el checkout no se contradigan.
- **`Services\NavigationLinks`** (`src/Services/NavigationLinks.php`) — seis URLs canónicas y la
  regla de destinos seguros. Cada URL pasa por su filtro (`akibara_navigation_url_account`,
  `…_cart`, `…_shop`, `…_contact`, `…_wishlist`, `…_home`) para poder sustituirla desde código sin
  tocar a quien la consume.
- **`Services\OneClickPage`** (`src/Services/OneClickPage.php`) — la cáscara negra con el logotipo
  que envuelve las páginas de baja por correo, compartida por `BackInStock` y `SeriesFollow` en vez
  de copiarla. Wordmark tipográfico, no imagen: una petición extra a un asset podría llegar tarde y
  dejar al cliente ante una pantalla anónima justo cuando tiene que reconocer de quién se da de
  baja.

---

## Lo programado

**En toda esta área hay exactamente un trabajo programado.** El resto de lo que parece automático
—la limpieza de las filas de PUDO, la caducidad de los `transient`— no es programado: ocurre de
paso, dentro de la petición que lo necesita.

| Trabajo | Mecanismo | Frecuencia y hora Chile | Quién lo programa | Qué hace | Qué pasa si corre dos veces |
| --- | --- | --- | --- | --- | --- |
| `akb_upsells_autofill_cron` | **wp-cron**, recurrencia `daily` | Cada 24 h, **a la hora en que se agendó por primera vez** — no hay hora fija. En producción `DISABLE_WP_CRON` está activo y quien empuja la cola es el cron de Hostinger cada 5 minutos, así que la pasada corre en el primer disparo posterior al vencimiento | `UpsellsAutofill::maybe_schedule()`, en el hook `init` de **cualquier** petición, con `wp_schedule_event( time(), 'daily', … )` (`UpsellsAutofill.php:97-99`). Si el evento no existe, se reagenda solo | Rellena hasta 100 productos sin recomendados con sus tres tomos siguientes, firma lo escrito y purga la caché de página de cada ficha tocada (`:116-131`) | **Inocuo.** Sólo toca productos *sin* recomendados, así que la segunda pasada no encuentra candidatos y sale barata. La única huella es que la option-recibo `akb_upsells_autofill_last_run` no se reescribe cuando no hubo trabajo, a propósito, para conservar la última corrida útil |

**Cómo ver a qué hora corre.** Como no hay hora fija, el dato hay que preguntárselo a la cola:

```bash
# En producción, después de ssh akibara:
wp cron event list --fields=hook,next_run_gmt,recurrence | grep akb_upsells_autofill_cron
# En el espejo Docker:
docker compose exec -T cron wp cron event list --fields=hook,next_run_gmt,recurrence | grep akb_upsells
```

**La hora que devuelve `next_run_gmt` está en UTC**, no en hora de Chile: hay que restarle el
desfase vigente ese día (3 o 4 horas según el cambio de horario). Y para ver la cola entera:
`wp cron event list` sin filtro.

**Dos avisos sobre este cron.** El primero: como el arranque se ancla en `time()`, la primera pasada
tras una activación corre **dentro de la petición de un cliente**, no en segundo plano. El segundo:
no hay forma de apagarlo desde ninguna pantalla (ver el ⚠ de la ficha 6). Lo único que lo retira es
**desactivar el plugin entero**, en `akibara-business.php:251`
(`wp_clear_scheduled_hook( 'akb_upsells_autofill_cron' )`, dentro del `register_deactivation_hook`
de `:239-253`). Al reactivar, vuelve con el primer `init`.

**Lo que se limpia sin cron.** Las filas `akb_pudo_state_*` caducadas se borran de forma oportunista,
hasta 25 por vez, en cada apertura y cada confirmación de selección
(`PudoMutationGuard.php:96` y `:178` → `WpOptionsPudoMutationRepository::cleanup_expired`). Los
`transient` (`akb_smartcart_*`, `akb_user_history_data_*`, `akb_upsells_coverage_v2`,
`akb_upsells_health_v2`) los caduca WordPress por su cuenta.

![Lo único programado en este capítulo](diagramas/05-business-carrito-checkout-y-cuenta-cron.mmd)

---

## Casos de uso

**Nueve** historias contadas paso a paso, con sus bifurcaciones, el fichero de cada paso entre
paréntesis y los correos que salen.

### Caso 1 · Compro un tomo y se me abre el cajón

![Del clic en «Añadir al carrito» al cajón pintado](diagramas/05-business-carrito-checkout-y-cuenta-caso-cajon-carrito.mmd)

1. Pulso «Añadir al carrito» en una tarjeta de `/tienda/`. WooCommerce añade la línea por su propio
   AJAX y emite el evento `added_to_cart` (`assets/js/native-cart.js:113`).
2. El JavaScript del cajón **olvida su copia en memoria** y pide el estado real
   (`native-cart.js::fetchCart`). Sin ese olvido, al abrir se pintaba primero el carrito anterior,
   sin el tomo recién añadido, y se sustituía al llegar la respuesta: el «parpadeo».
3. El servidor recalcula totales **sólo si nadie lo hizo ya en esta petición**
   (`NativeCart::ajax_get_cart` + `mark_totals_calculated`).
4. Recorre las líneas: nombre, cantidad, subtotal por línea, miniatura resuelta **una sola vez** y
   tope de compra (`NativeCart::thumbnail_url`).
5. Calcula qué lleva de verdad el carrito: si hay algo reservable y si además arma un pack de serie
   (`NativeCart::detect_cart_flags`, que usa **el mismo colector** que el motor de packs, para que el
   aviso y el descuento coincidan por construcción).
6. Pide la barra de envío gratis por el filtro `akb_native_cart_fsp_html`
   (`FreeShippingProgress::native_cart_html`).
7. Pide los recomendados: WooCommerce llama al filtro de cross-sells y el recomendador antepone los
   siguientes tomos de las series del cliente (`SmartCartRecommender::inject_smart_recommendations`).
8. La respuesta viaja **con un `nonce` nuevo**, que el JavaScript adopta (`native-cart.js::adoptNonce`).
9. Se pinta todo y, si «Abrir al agregar» está encendido, el cajón se abre solo.

**Bifurcaciones.** Si `akibara_native_cart_enabled` está en «no», el cajón ni se imprime y el
cliente se queda con el carrito de WooCommerce · si la página es `/carrito/` o `/checkout/`, no se
carga a propósito · si el carrito lleva algo reservable, el aviso elige la palabra («pedidos»,
«preventas» o «preventas y pedidos») y debajo aparece la nota de en qué tanda entra la compra · si
el motor de packs global está apagado, el aviso «Arma tu Pack» no sale aunque queden configuraciones
por serie vivas · si el añadir **falló**, la ficha pide la cola de errores con
`?wc-ajax=akb_get_cart&drain_notices=1` y el cliente lee el motivo sin recargar.

**Correos.** Ninguno.

### Caso 2 · Cambio la cantidad de una línea

1. El JavaScript **no destruye la lista**: la atenúa y la marca ocupada (`native-cart.js::setBusy`),
   y guarda dónde estaba el foco y el scroll (`::captureRestorePoint`). Antes, cada clic la
   sustituía por «Cargando…», el pie desaparecía y volvía —un salto de 248 px—, el scroll se
   reseteaba y el botón pulsado dejaba de existir, así que el foco caía al cuerpo del documento y se
   rompía el atrapado del diálogo.
2. Envía la mutación con el `nonce` vigente (`::mutateCart`).
3. El servidor lo verifica (`NativeCart::ajax_update_cart_item`).
4. Lee el máximo comprable del producto.
5. Aplica la cantidad y delega en la lectura del carrito, que devuelve el estado nuevo y un `nonce`
   nuevo.
6. El JavaScript repinta y devuelve el foco **al mismo control de la misma fila**; si esa fila
   desapareció, al control más cercano que exista, nunca al cuerpo del documento (`::restorePoint`).

**Bifurcaciones.** Máximo `−1`: no hay tope · máximo `N`: la cantidad se recorta a `N` aunque el
cliente pidiera más (`NativeCart.php:470-474`) · cantidad 0 o menos: la línea se elimina, **en
silencio** (`:477-479`) · **máximo 0**: la línea se retira y la respuesta trae «X se agotó y lo
quitamos de tu carrito», anunciado también a lectores de pantalla (`:458-468`) · 403 por `nonce`
caducado: se refresca el carrito, se adopta el `nonce` nuevo y se reintenta **una** vez
(`::mutateWithRetry`) · si ya hay una mutación en vuelo, la segunda se descarta.

> **Ojo con la bifurcación del agotado.** Tal como está el JavaScript, **subir la cantidad no es el
> camino que la dispara**: con máximo 0 el botón «+» se pinta deshabilitado
> (`native-cart.js:610-617`). La rama del aviso sólo se alcanza pulsando «−» sobre una línea con
> cantidad ≥ 2 (o desde otro flujo que mande una cantidad mayor que 0). Con una sola unidad, «−»
> manda 0 y cae en el borrado silencioso.

### Caso 3 · Toco el corazón sin haber entrado

![Del corazón al muro y de vuelta con el tomo guardado](diagramas/05-business-carrito-checkout-y-cuenta-caso-muro-favoritos.mmd)

1. El botón ya me lo dijo: «Inicia sesión para guardar «Naruto 3» en favoritos»
   (`Wishlist\Button::html:105-108`).
2. El navegador anota qué quería guardar en el **`sessionStorage`** de esa pestaña, bajo la clave
   **`akbWishlistPending`** (`wishlist.js::rememberIntent`, `:92-96`), y me lleva al muro con
   `redirect_to` apuntando a la página actual.
3. **El muro habla de lo mío**: título y subtítulo propios del endpoint, y una línea que nombra el
   tomo con su enlace de vuelta — sólo si ese producto existe y está publicado, porque el
   `redirect_to` lo escribe cualquiera y nombrar un borrador filtraría su título
   (`AccessWall::copy`, `::return_notice`).
4. Entro por cualquiera de los tres caminos: los tres llevan **el mismo destino saneado**
   (`AccessWall::redirect_field_value` y `GoogleLoginButton::google_returns_where_the_customer_came_from`).
5. De vuelta en la ficha, el JavaScript recoge la intención pendiente y completa el guardado
   (`wishlist.js::resumePendingIntent` → `Wishlist\Ajax::toggle` → `Storage::add`).

**Bifurcaciones.** Si pasó más de media hora —1.800.000 ms, `wishlist.js:121`—, la intención se
descarta: completar un guardado que el cliente ya no recuerda es una sorpresa, no una ayuda · si
entre medio ya lo guardó por otro camino, no se repite el conmutador, porque repetirlo lo quitaría ·
si el destino no es una página pública de este sitio, se descarta entero y el muro no nombra nada ·
si el navegador **bloquea el `sessionStorage`** (modo privado, o ajustes que impiden guardar datos
del sitio), `rememberIntent()` falla en silencio y degrada al flujo de siempre: el cliente entra y
el corazón sigue vacío · si la
sesión **caducó** después de pintarse la página, la petición llega a `wp_ajax_nopriv_akb_wishlist_toggle`
y la rama de invitado responde 401 con la URL del muro, que el JavaScript usa para redirigir
(`Wishlist/Ajax.php:29`; `wishlist.js:168-171`). Esa rama no es código muerto: es el camino de «se
me cayó la sesión».

**Correos.** Ninguno.

### Caso 4 · Pago con despacho a domicilio

1. El checkout es de bloques: lo pinta `akibara-cart-checkout-blocks`, que pide a business **una
   sola** carga de datos (`CheckoutDataService::structured_cart_data`).
2. Esa carga trae los avisos de pack, el estado del envío gratis, el resumen de preventa y los tres
   sellos de confianza (`CheckoutTrustSignals::all`).
3. Escribo mi RUT: el campo y su validación viven en el plugin de bloques, que usa el validador de
   business (`CheckoutDataService::is_valid_rut`).
4. Escribo mi teléfono: se valida en vivo con el mismo servicio
   (`CheckoutDataService::phone_validation_error`).
5. Escribo mi dirección: si el autocompletar está encendido y con clave, el proveedor nativo de
   WooCommerce ofrece sugerencias (`CheckoutDataService::address_autocomplete_config`).
6. En el resumen, cada línea de impuesto se llama «IVA» (`CheckoutTaxLabels::handle_cart_tax_totals`).
7. Encima del botón de pagar puede salir el aviso de colección, cuyo texto se edita en la pestaña
   Carrito (`akibara_blocks_collection_notice_text`).
8. **Elijo el medio de pago y pago.** Quien cobra es **Mercado Pago**
   (plugin `woocommerce-mercadopago`). Este capítulo no le habla ni una vez: el dinero es cosa
   suya. Lo que sí hace este plugin es **defender sus scripts**, porque el checkout de bloques se
   rompe entero si LiteSpeed los junta o los retrasa —
   `Modules/FrontendCompatibility/FrontendCompatibility.php:28-37` los excluye del combinado y del
   diferido (`:77-79`) y `Modules/ScriptDiagnostics/CrossOriginScripts.php` les añade `crossorigin`
   para que sus errores lleguen con fichero y línea en vez de un «Script error.» mudo. Los dos se
   cuentan enteros en el capítulo 06.
9. Al confirmar, WooCommerce crea el pedido por la **Store API** y dispara
   `woocommerce_store_api_checkout_order_processed`. **Ése, y no el hook clásico del checkout, es
   el único instante fiable de «el pedido ya existe»** (ver «Cómo encaja»).
10. `PhoneValidation` normaliza los dos teléfonos a `+569XXXXXXXX`, **sólo si el número es válido**.
11. `MarketingConsent` ya guardó la prueba —al persistirse el campo si la casilla viajó, o como
    «NO» al cerrar `update_order_from_request` si el bloque no la envió— y comprueba que exista.

**Bifurcaciones.** Teléfono rechazado por el validador (empieza por 0 o 1, u ocho cifras iguales):
**no** se normaliza · el campo de consentimiento no está registrado en WooCommerce: queda un aviso
por pedido en el registro, que es la señal de que el proveedor renombró su casilla · autocompletar encendido pero sin
clave: el cliente escribe a mano y el dueño ve un aviso en el panel · el pago falla o se abandona:
el pedido queda en «pago fallido» o «pendiente» y `OrderTimeline` le pinta su cartel propio, con el
enlace de WhatsApp para mandar el comprobante.

**Correos.** *Correo de pedido de WooCommerce* —`customer_processing_order` si el pago se confirmó,
`customer_on_hold_order` si quedó en espera; el asunto de cada uno se configura en WooCommerce →
Ajustes → Correos, `…&tab=email&section=<identificador>`— **inmediatamente** al crearse el pedido.

### Caso 5 · Pago eligiendo un Punto Blue Express

![El retiro en Punto Blue Express, de punta a punta](diagramas/05-business-carrito-checkout-y-cuenta-caso-pudo.mmd)

1. Elijo un local en el mapa. Al servidor viaja **sólo el identificador de la agencia** y un
   identificador de intento (`PudoCheckoutService::canonicalize_request`).
2. Se calcula la huella del carrito y del destino (`::cart_fingerprint`).
3. Se abre un arriendo que invalida cualquier operación anterior del mismo cliente
   (`PudoMutationGuard::begin_selection`).
4. Se le pregunta a Blue Express por ese punto usando **mi** comuna y **mi** región, y se comprueba
   que el punto devuelto sea el que elegí, con al menos 80 % de confianza y país Chile
   (`PickupPointProvider::resolve` → `::canonicalize_geolocation`).
5. Se cotiza reproduciendo exactamente los números que enviaría el propio plugin del transportista
   (`PickupPointProvider::quote`).
6. Se sella la selección con la huella y una hora de vida (`PudoSelectionStore::prepare`).
7. Se confirma **sólo** si ese arriendo sigue siendo el vigente (`PudoMutationGuard::commit_selection`).
8. Se proyecta a la sesión para que el plugin de Blue Express vea lo que espera
   (`PudoSelectionStore::hydrate`).
9. Al recalcular tarifas, **se retira cualquier tarifa de retiro que no coincida con la cotización
   guardada** (`PudoCheckoutService::filter_package_rates`).
10. Al pulsar «Realizar pedido» se vuelve a validar todo y se congela la selección 30 segundos
    mientras se copian los datos al pedido (`::validate_checkout` → `PudoMutationGuard::claim_for_checkout`),
    y después se libera (`::release_checkout_claim`).

**Bifurcaciones.** Pedido partido en varios envíos → el retiro no está disponible
(`pudo_multiple_packages`) · carrito cambiado → la huella no coincide y hay que elegir otra vez ·
cotización y tarifa que no cuadran al céntimo → la tarifa se retira (`pudo_quote_invalid`) · dos
selecciones solapadas → la vieja recibe `pudo_stale_attempt` · alguien intenta cambiar el punto
durante la confirmación → `pudo_checkout_in_progress` · Blue Express responde total 0 pero dice que
no es gratis (o al revés) → se rechaza la cotización entera · más de una hora sin tocar el carrito →
caduca.

**Correos.** *Correo de pedido de WooCommerce*, inmediato. **No** lleva la caja de coordinación de
Metro San Miguel: este retiro lo coordina el transportista.

### Caso 6 · Pago eligiendo retiro en mano en el Metro San Miguel

1. El pedido se crea con esa línea de envío —internamente un método `local_pickup` de la zona de la
   Región Metropolitana. El identificador completo de la tarifa es `local_pickup:<nº de
   instancia>`, y ese número **no está en el código**: es configuración de producción, así que aquí
   no se escribe —caducaría sin aviso el día que alguien toque las zonas—. Se lee en wp-admin →
   WooCommerce → Ajustes → **Envío** → la zona, o por línea de comandos:
   `wp wc shipping_zone list --user=1` para dar con el identificador de la zona y
   `wp wc shipping_zone_method list <id de zona> --user=1` para ver sus métodos con la instancia de
   cada uno. *(En la pantalla de zonas del plugin de Blue Express **no** se pulsa «Aplicar»: eso
   reescribe las zonas.)*
2. WooCommerce envía el correo del pedido y, tras la tabla de productos, dispara
   `woocommerce_email_after_order_table`.
3. `PickupCoordination` mira el **método real** de cada línea de envío, no su título (`::es_retiro`).
4. Pinta la caja «Cómo es el retiro en Metro San Miguel» con el enlace de WhatsApp ya armado con el
   número de pedido y el correo de contacto (`::pintar_html` / `::texto_plano`).
5. La misma caja aparece en la página de gracias y en `/mi-cuenta/view-order/{id}/`
   (`::pintar_en_pantalla`).
6. La barra de envío gratis **no** se pinta para este pedido: el retiro no queda gratis por monto.

**Bifurcaciones.** Correo al administrador → la caja no se pinta: él ya sabe cómo entrega · correo
en texto plano → se emite una versión sin etiquetas con el teléfono legible · método `bluex-pudo` →
la caja no sale · el número de WhatsApp sale del plugin `joinchat`; si falta, el de respaldo del
código · **y hoy, también un pedido cancelado o reembolsado recibe la caja** (ver el ⚠ de la ficha
10).

**Correos.** *Correo de pedido de WooCommerce* **con la caja añadida**, inmediato al crearse el
pedido y en cada cambio de estado que dispare un correo al cliente.

### Caso 7 · Abro mi pedido de preventa

1. Antes de la tabla, `OrderTimeline::render` pinta los pasos
   (`woocommerce_order_details_before_order_table`, prioridad 5).
2. Se comprueba si quedan líneas en espera **todavía vigentes**: no basta la bandera del pedido, que
   nunca se apaga (`build_steps` + `PreventaItems::has_outstanding_items`).
3. El nodo de la espera se llama por la palabra del pedido: «Preventa» o «A pedido»
   (`PreventaItems::order_label`).
4. La pista de fecha sale de **una sola** fuente y se escribe como «Llega ~octubre 2026»
   (`::preventa_eta_hint`).
5. Tras la tabla, si el pedido es retiro con nosotros, aparece la caja de coordinación.

**Bifurcaciones.** Cancelado, reembolsado o pago fallido → no hay pasos: se pinta un cartel propio
(`::render_terminal_state`, `:97-120`) · pedido ya marcado listo para despachar, o todo lo esperado
llegó → la espera se cierra y el paso actual pasa a «Preparando despacho» aunque el pedido siga en
«procesando» · estado `shipping-progress` → el último paso se llama «Listo para enviar» · fecha
vencida → «Llegaba ~octubre 2026 · atrasado» · sin estimado vigente → «Fecha por confirmar».

**Correos.** Ninguno propio de esta pantalla.

### Caso 8 · Compré sin cuenta, me registro y no veo mis pedidos

1. Entro a `/mi-cuenta/orders/`. WooCommerce iba a pintar su caja de «Confirma tu dirección de
   correo electrónico…» y pregunta por su filtro.
2. `GuestOrderLinking::silenciar_aviso` responde **siempre** que no, sin mirar ni un pedido ni una
   meta.
3. En su lugar se pinta una línea corta e **idéntica para todos** los no verificados: «¿Compraste
   antes sin tener cuenta? Confirma tu correo y esos pedidos aparecen aquí.»
   (`::pintar_invitacion`).
4. Al pulsarla, WooCommerce envía **a su bandeja** el correo de verificación.
5. Al confirmar desde ese enlace, WooCommerce vincula los pedidos de invitado que coincidan.
6. A partir de ahí, la colección también los incluye (`AccountCollection\CollectionResolver`).

**Bifurcaciones.** Si WooCommerce acaba de responder a un envío —hay un aviso suyo en pantalla—, la
línea no se pinta: dentro del minuto de espera sólo produciría un error.

**Correos.** *Correo de verificación de dirección de WooCommerce*, al pulsar el enlace, inmediato.

### Caso 9 · El dueño cambia la casilla de ofertas desde el panel del pedido

1. Abre un pedido en wp-admin y marca o desmarca la casilla. WooCommerce guarda el campo adicional y
   dispara `woocommerce_set_additional_field_value`.
2. `MarketingConsent` detecta que la petición viene del panel (`:180`).
3. Compara con el valor realmente guardado y **sólo actúa si cambió** (`::note_admin_change`).
4. Añade una nota privada: «Casilla de ofertas y novedades cambiada desde el panel: ahora SÍ/NO. No
   es consentimiento del cliente: la prueba del checkout queda como estaba.»
5. La prueba original (`_akb_marketing_consent`) **no se toca**.

**Bifurcaciones.** Un guardado del pedido sin tocar la casilla no genera nota, porque el panel
reenvía todos los campos en cada «Actualizar» · el texto de la nota se compone con marcadores y
`str_replace`, **nunca** metiendo la etiqueta del dueño como **cadena de formato**, porque un
símbolo de porcentaje en esa cadena sería un error fatal (ver el matiz de la sección «Reglas de
negocio»: como **argumento** de `sprintf` el mismo texto es inofensivo).

**Correos.** Ninguno.

---

## Reglas de negocio

Sólo las que están **escritas en el código**, con fichero y línea. Las tres filas marcadas con ⚠
son correcciones respecto del mapa de lectura: la fuente no cubría lo que la regla afirmaba.

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
| --- | --- | --- | --- |
| El cajón no se carga ni se imprime en `/carrito/` ni en `/checkout/` | `NativeCart.php:93` y `:129` | Sólo el encendido general | Akibara → Carrito → «Activar carrito nativo» |
| Si el máximo comprable es 0, la línea se **retira** y se le explica al cliente | `NativeCart.php:458-468` | No | — |
| La cantidad pedida se recorta siempre al máximo comprable | `NativeCart.php:470-474` | No | — |
| Una cantidad de 0 o menos elimina la línea **sin aviso** | `NativeCart.php:477-479` | No | — |
| El aviso de preventa elige su palabra: «preventas», «pedidos» o «preventas y pedidos» | `NativeCart.php:591-594` y `Settings.php:597-599` | Sí | Akibara → Carrito → «Aviso de pedidos/preventa», con el marcador `{tipo}` |
| El aviso «Arma tu Pack» sólo sale si el motor arma de verdad un pack; con el motor global apagado nunca sale | `NativeCart.php:596-599` y `:622-628` | Sí | Akibara → Carrito + interruptor global de `SeriesBundles` |
| El ancho del cajón se recorta entre 320 y 640 px y los recomendados entre 0 y 6 | `Settings.php:464-470` | Sí | Akibara → Carrito |
| Un «Selector de activadores» que no sea clase, id o atributo se rechaza al guardar | `Settings.php:141-206` | Sí | Akibara → Carrito; vaciarlo repone `.akb-cart-trigger` |
| Vaciar el aviso «¿No estás en Santiago?» o el de colección los **apaga**; vaciar cualquier otro texto repone el literal | `Settings.php:452-458` y `:657-660` | Sí | Akibara → Carrito |
| El recomendador cachea candidatos 5 min y el historial 6 h, pero el filtro de stock corre **siempre** en vivo | `SmartCartRecommender.php:42`, `:57`, `:150-158` | No | — |
| El historial cacheado se borra en cuanto un pedido del cliente pasa a «procesando» o «completado» | `SmartCartRecommender.php:95-96` | No | — |
| Series y géneros se analizan sobre los 5 pedidos más recientes; «ya comprado» sobre los 50 | `SmartCartRecommender.php:65` y `:74` | No | — |
| La vista rápida sólo existe en `/tienda/`, taxonomías de producto y búsqueda; la portada queda fuera a propósito | `QuickView.php:71-79` | No | — |
| La sinopsis se corta en 280 caracteres, por palabra completa, sin frases de marca, envío ni cuotas | `QuickView.php:53` y `:174-209` | No | — |
| Sin sinopsis no hay botón de vista rápida | `QuickView.php:113-118` | No | — |
| Los favoritos exigen sesión iniciada: no hay lista de invitado ni cookie | `Wishlist/Storage.php:46-52` | No | — |
| Un cliente no puede guardar dos veces el mismo tomo: lo garantiza la clave primaria compuesta | `Wishlist/Install.php:82-89` | No | — |
| Los favoritos no caducan: sólo se borran con el borrado de cuenta, el **borrado definitivo** del producto o la herramienta de privacidad | `Wishlist/Storage.php:11-16`, `:220-237`; hook `before_delete_post` en `Wishlist.php:58` | No | — |
| La página de favoritos pinta como mucho 100 tarjetas por carga, sin paginación ni aviso | `Wishlist/Render.php:30` y `:37` | No | — |
| La cuadrícula de favoritos sólo pinta productos **publicados y no excluidos del catálogo**, mientras el encabezado cuenta todo lo guardado | `Wishlist/Render.php:91-108` frente a `:115` | No | — |
| Los estilos de favoritos bajan en portada, tienda, ficha, taxonomías, búsqueda, carrito y el endpoint de favoritos; **nunca en el checkout** | `Wishlist.php:96-148` (el encolado en `:96-102`, la lista en `:131-148`, la red de seguridad en `:111-120`) | No | — |
| Un `nonce` caducado en el corazón no es un error final: el servidor devuelve uno fresco y el navegador reintenta una vez | `Wishlist/Ajax.php:41-50` y `assets/js/wishlist.js:172-177` | No | — |
| El relleno de recomendados sólo toca productos **sin** recomendados; nunca pisa una lista escrita a mano | `UpsellsAutofill.php:1076-1101` | No | — |
| Se rellenan exactamente 3 tomos siguientes, sólo entre los visibles, comprables y con stock de la misma serie | `UpsellsAutofill.php:39` y `:1205-1211` | No | — |
| Cada pasada procesa como mucho 100 productos, la del cron y la del botón | `UpsellsAutofill.php:40`, `:119`, `:256` | No | — |
| Un recomendado a un tomo **anterior** de la misma serie es válido («completa tu colección») y no se toca | `UpsellsAutofill.php:1063-1065` | No | — |
| La limpieza sólo retira enlaces a productos borrados o no publicados; los que apuntan a otra serie se listan para decidir a mano | `UpsellsAutofill.php:1051-1061` y `:797-848` | No | — |
| El «Umbral envío gratis (CLP)» nunca rebaja el mínimo real de la zona: se toma el mayor de los dos | `FreeShippingThreshold.php:496-497` | Sí | Akibara → Envío gratis; 0 = usar el real |
| Si la zona no tiene ninguna instancia «Envío gratis» habilitada, se usa el «Umbral del transportista», y sólo si la zona entrega a domicilio | `FreeShippingThreshold.php:256-261` y `:589-616` | Sí | Akibara → Envío gratis; 0 lo apaga |
| Con varios paquetes manda el que **más** falta: nunca se promete envío gratis sobre medio pedido | `FreeShippingThreshold.php:409-416` | No | — |
| Sin país en la dirección del paquete, la barra no aparece | `FreeShippingThreshold.php:619-628` | No | — |
| ⚠ La barra se oculta si el cliente eligió retiro, pero el reconocimiento **por identificador** cubre sólo `local_pickup` y `bluex-pudo`; `pickup_location` se atrapa únicamente por el **título** de la tarifa | `FreeShippingProgress.php:512-518`; la lista de tres identificadores vive en `FreeShippingThreshold.php:38` y sólo gobierna el fallback | Sí | Akibara → Envío gratis → «Ocultar si retiro en tienda» |
| Si el paquete sólo pudo calcular **una** tarifa y esa es retiro, no se considera elegida: fue el valor por defecto de WooCommerce | `FreeShippingProgress.php:548-573` | No | — |
| A partir del 80 % de avance la barra dice «¡CASI LO LOGRAS!», y ese mismo 80 % marca `almost_there` en el estado que consumen los bloques | `FreeShippingProgress.php:307`, `:584-590`, `:603` | No | — |
| Las etiquetas de impuesto del carrito, checkout, pedido y correo dicen siempre «IVA» | `CheckoutTaxLabels.php:40-43` y `:76-83` | No | — |
| Un teléfono sólo se normaliza a `+569XXXXXXXX` si **antes** pasa la validación; los rechazados se dejan tal cual | `PhoneValidation.php:97-108` | No | — |
| En el **panel** del pedido, si existe el RUT del campo nuevo no se pinta el antiguo (en el correo y en Mi cuenta sí se pinta) | `RutValidation.php:49-52` frente a `:70-82` y `:93-104` | No | — |
| La clave de Google Maps de `wp-config.php` gana siempre sobre la del panel, y el campo se muestra en solo lectura | `AddressAutocomplete.php:43-48` y `:223-226` | Sí | Constante `AKB_GOOGLE_MAPS_API_KEY`, o Akibara → Autocompletar dirección |
| Una clave de Google sin la forma `AIza` + 35 caracteres se rechaza y se conserva la anterior | `AddressAutocomplete.php:66-82` | No | — |
| El autocompletar viene **apagado** por defecto | `AddressAutocomplete.php:54` y `:242` | Sí | Akibara → Autocompletar dirección → «Activar» |
| Sólo `local_pickup` y `pickup_location` cuentan como «retiro con nosotros»; `bluex-pudo` queda fuera porque lo coordina el transportista | `PickupCoordination.php:46` y `:101-109` | No | — |
| La caja de coordinación **no** se pinta en el correo al administrador | `PickupCoordination.php:72` | No | — |
| El correo de contacto del retiro es `contacto@akibara.cl` y el teléfono de respaldo `+56944242844` | `PickupCoordination.php:49`, `:52`, `:162-169` | Sólo el teléfono | Ajustes del plugin de WhatsApp (option `joinchat`). El correo y el texto están en el código |
| El aviso de verificación de correo se apaga para **todos** los no verificados, sin mirar si ese correo tiene pedidos | `GuestOrderLinking.php:162-170` | No | — |
| La vinculación automática de pedidos de invitado está **retirada** desde el 2026-08-23 | `GuestOrderLinking.php:3-61` | No | — |
| Un pedido cancelado, reembolsado o con pago fallido no tiene línea de tiempo: se pinta un cartel | `OrderTimeline.php:58-62` y `:97-120` | No | — |
| Los pasos del pedido son **tres**, más un cuarto sólo si hay espera vigente. No existe nodo «Entregado» | `OrderTimeline.php:183`, `:202`, `:221`, `:244` | No | — |
| La etapa de espera sólo se pinta si quedan líneas reservadas **vigentes**; no basta la bandera del pedido, que nunca se apaga | `OrderTimeline.php:152-156` | No | — |
| La espera se cierra cuando el pedido está marcado listo para despachar o todo lo esperado llegó, aunque siga en «procesando» | `OrderTimeline.php:173-176` y `:193-207` | No | — |
| Con el estado `shipping-progress` el último paso se llama «Listo para enviar», no «Despachado» | `OrderTimeline.php:237-248` | No | — |
| Una fecha vencida se muestra como «Llegaba ~mes año · atrasado»; sin estimado vigente, «Fecha por confirmar» | `OrderTimeline.php:272-290` | No | — |
| La prueba del consentimiento guarda la etiqueta que el cliente **vio** (la variante con «(opcional)») | `MarketingConsent.php:440-464` | No | — |
| Una edición desde el panel **no** es consentimiento: la prueba no se toca y el pedido recibe una nota | `MarketingConsent.php:180-183` y `:354-376` | No | — |
| Si el valor y la etiqueta no cambiaron, no se reescribe la prueba ni se anota | `MarketingConsent.php:187-190` | No | — |
| La casilla que el bloque no envía (sin tocar) queda como «NO» con el texto visto; sin el campo registrado no se inventa nada | `MarketingConsent.php:207-224` | No | — |
| Un pedido sin prueba deja un aviso **por pedido**, diciendo si el campo está registrado, sin cuota diaria | `MarketingConsent.php:235-257` | No | — |
| El campo del consentimiento es `SendinblueWoocommerce/newsletter_opt_in` | `MarketingConsent.php:101` y `:145-156` | Sí, **sin pantalla** | Filtro `akibara_marketing_consent_field_key` |
| El login, el alta y el «olvidé mi contraseña» no revelan nunca si un correo tiene cuenta | `Security.php:103-120`, `:151-182`, `:410-462` | No | — |
| La lista de usuarios por REST y el archivo de autor están cerrados para quien no puede listar usuarios, **aunque tenga sesión de cliente** | `Security.php:344-366` y `:376-399` | No | — |
| Seis acciones AJAX y todas las rutas REST administrativas de Blue Express exigen `manage_woocommerce` | `Security.php:49-56`, `:196-216`, `:260-279` | No | — |
| El retiro en Punto Blue Express no está disponible si el pedido se divide en varios envíos | `PudoCheckoutService.php:421-426` y `PickupPointProvider.php:214-219` | No | — |
| La tarifa de retiro cobrada tiene que coincidir con la cotización guardada dentro de un céntimo, y el «es gratis» tiene que cuadrar con el coste | `PudoCheckoutService.php:511-528` | No | — |
| Una selección de punto vive como mucho una hora, y muere antes si el carrito o el destino cambian | `PudoSelectionStore.php:53-59` y `:110-122` | No | — |
| Mientras un pedido confirma su punto de retiro (30 s), nadie puede cambiarlo | `PudoMutationGuard.php:23` y `:509-536` | No | — |
| La geolocalización se rechaza bajo el 80 % de confianza, con región incoherente, país distinto de Chile o punto distinto del elegido | `PickupPointProvider.php:358-386` | No | — |
| Una cotización cuyo total y cuya marca de gratis se contradigan se rechaza entera | `PickupPointProvider.php:305-310` | No | — |
| ⚠ El tope de 365 días para el plazo de entrega **sólo** se aplica a la rama de rescate de cadenas sucias; un `promiseDay` entero pasa intacto | `PickupPointProvider.php:931-935` (retorno temprano) frente a `:944` (el tope) | No | — |
| «Volver a comprar» no existe: la lista de estados que lo permiten se deja vacía | `MyAccountUx.php:54` y `:321-325` | No | — |
| El orden de las pestañas es Pedidos, Recompensas, Favoritos, Direcciones, Detalles, extras, Resumen y Salir | `MyAccountUx.php:166-188` | No | — |
| «Colección» se inserta detrás de «Pedidos», en prioridad 30, después de que `MyAccountUx` ordene en la 20 | `AccountCollection/Endpoint.php:35` y `:69-87` | No | — |
| La página de la colección nunca entra en una caché compartida: cabeceras, `DONOTCACHEPAGE` y aviso a LiteSpeed | `AccountCollection/Endpoint.php:168-197` | No | — |
| Un tomo de cero series o de más de una queda «sin agrupar»: no se adivina | `CollectionResolver.php:97-102` | No | — |
| Cada tomo cuenta una sola vez aunque se comprara varias, y una reedición del mismo número no suma posición | `CollectionResolver.php:693-704` | No | — |
| El hueco de la colección se busca **dentro** del rango que el cliente tiene | `ContinuityResolver.php:284-308` | No | — |
| Con el interruptor global de avisos apagado, las tarjetas no pintan su botón por serie | `Renderer.php:241-283` y `templates/myaccount/collection.php:210-226` | Sí, **por el cliente** | Mi cuenta → Colección → «Avisos de mi colección» |
| Un destino de vuelta sólo vale si empieza por la URL del sitio, no lleva caracteres de control y no apunta a `/wp-admin/` ni `wp-login.php`, **ni siquiera codificados varias veces** | `NavigationLinks.php:95-156` | No | — |
| El muro de acceso sólo nombra un producto **publicado** | `AccessWall.php:255-277` | No | — |
| El marcador `{producto}` se sustituye con `str_replace`: un copy del dueño nunca es la **cadena de formato** de `sprintf` | `AccessWall.php:65-71` y `:220-228` | Sí | Akibara → Textos → `akb_copy_wall_return` |
| La promesa de cuotas del checkout y la del FAQ de la ficha salen de **una única** option | `CheckoutTrustSignals.php:22-25` y `:70-75` | Sí | Akibara → Textos → «Promesas de la tienda» |

### El matiz de `sprintf` que hay que leer entero antes de «arreglar» nada

La regla de la casa dice que un copy editable por el dueño **nunca** puede ir a `sprintf`. Dicha así
es media regla, y quien la aplique al pie de la letra abrirá un falso hallazgo que ya se tumbó una
vez. La regla completa es:

> Un texto que el dueño puede editar nunca puede ser la **cadena de formato** —el primer argumento
> de `sprintf`—, porque un `%` suelto ahí es un error fatal que tumba la página. Como **argumento**
> —del segundo en adelante— el mismo texto es completamente inofensivo: `sprintf` sólo interpreta
> los `%` del primer parámetro, y un `%` dentro de un argumento se imprime tal cual.

El ejemplo vivo de la segunda mitad está en `Services/CheckoutDataService.php:263-264`:

```php
$explanation = $mixed
    ? sprintf( 'Tu pedido combina productos disponibles y %s. Te lo despachamos completo cuando llegue todo.', $a_pedido )
    : sprintf( 'Te lo despachamos cuando lleguen todos los productos %s.', $a_pedido );
```

`$a_pedido` sale de `Stock\Availability::label_for_state()` —la misma palabra comercial que el
cliente ve en la etiqueta de cada línea del carrito—. Está **como argumento**, no como formato. Ese
`sprintf` es correcto, y **hacer editable esa palabra no obliga a cambiarlo**.

Lo que sí está prohibido es lo contrario: `sprintf( $copy_del_dueño, $algo )`. Ahí es donde se usa
`str_replace` con un marcador, como en `AccessWall.php:65-71` con `{producto}`.

---

## Datos que deja

### Options

| Clave | Quién la escribe | Dónde se edita | Valores |
| --- | --- | --- | --- |
| `akibara_native_cart_enabled` · `…_auto_open` · `…_trigger_selector` · `…_mobile_fullscreen` · `…_drawer_side` · `…_drawer_width` · `…_show_thumbnails` · `…_show_subtotal` · `…_show_free_shipping` · `…_show_recommendations` · `…_recommendation_limit` · `…_recommendation_source` · `…_show_byp_policy` · `…_show_preventa_policy` · `…_byp_policy_copy` · `…_preventa_policy_copy` · `…_empty_copy` · `…_checkout_copy` · `…_continue_copy` · `…_continue_url` · `…_recommendations_copy` · `…_recommendation_add_copy` · `…_reduced_motion` | `NativeCart\Settings` | Akibara → Carrito | 23 ajustes del cajón |
| `akibara_blocks_shipping_hint_text` · `akibara_blocks_collection_notice_text` | `NativeCart\Settings` | Akibara → Carrito | Texto libre; **vacío los apaga**. Los pinta `akibara-cart-checkout-blocks` |
| `akibara_fsp_enabled` · `akibara_fsp_threshold` · `akibara_fsp_carrier_threshold` · `akibara_fsp_show_cart` · `…_show_mini_cart` · `…_show_checkout` · `…_show_qualified` · `akibara_fsp_disable_local_pickup` · `akibara_fsp_title` · `akibara_fsp_message` · `akibara_fsp_qualified` | `FreeShippingProgress` | Akibara → Envío gratis | `akibara_fsp_carrier_threshold` es **el que manda en producción** |
| `akibara_address_autocomplete_enabled` · `akibara_google_maps_api_key` | `AddressAutocomplete` | Akibara → Autocompletar dirección | Apagado por defecto; la constante de `wp-config.php` gana sobre la clave |
| `akb_copy_wall_title` · `…_sub` · `…_sub_login` · `…_favoritos_title` · `…_favoritos_sub` · `…_coleccion_title` · `…_coleccion_sub` · `…_orders_title` · `…_orders_sub` · `akb_copy_wall_return` | `CopyManager` / `AccessWall` | Akibara → Textos | Los diez textos del muro |
| `akb_copy_promise_installments` | `CheckoutTrustSignals` | Akibara → Textos | Por defecto «3 cuotas sin interés» |
| `akb_wishlist_db_version` | `Wishlist\Install` | **Sin pantalla** | `1.0.0` |
| `akb_upsells_autofill_last_run` · `akb_upsells_autofill_last_repair` | `UpsellsAutofill` | **Sin pantalla editable** (se muestran en Akibara → **Recomendaciones**, `tab=upsells_autofill`) | Recibos: candidatos, rellenados, saltados, ids, fecha, usuario y origen |
| `akb_pudo_state_<hmac de la sesión>` | `WpOptionsPudoMutationRepository` | **Sin pantalla**, se autolimpia | Siempre **sin autoload**. Caducidad con ancho fijo + `|` + JSON con fase, testigo de versión, huella del carrito y selección |
| `joinchat` (de terceros) · `wp_page_for_privacy_policy` · `woocommerce_myaccount_page_id` · `woocommerce_cart_page_id` · `woocommerce_shop_page_id` · `woocommerce_enable_myaccount_registration` · `woocommerce_registration_generate_password` · `woocommerce_registration_generate_username` | **No las escribe este capítulo** | Sus propios plugins / WooCommerce | Sólo lectura |

### Tablas

| Tabla | Quién la escribe | Qué guarda |
| --- | --- | --- |
| `wp_akb_wishlist` | `Wishlist\Install` (esquema) y `Wishlist\Storage` (filas) | `user_id`, `product_id`, `added_at`. Clave primaria compuesta `(user_id, product_id)`, más índices por `(user_id, added_at)` y por `product_id` |
| `wp_options` | `WpOptionsPudoMutationRepository` por **SQL directo**, saltándose la caché de opciones | Las filas `akb_pudo_state_*`, con borrado acotado a 25 filas por limpieza |
| `wp_woocommerce_order_items` · `wp_woocommerce_order_itemmeta` · `wp_posts` · `wp_terms` · `wp_term_taxonomy` · `wp_term_relationships` | **Sólo lectura** | `SmartCartRecommender` y `CollectionResolver` las consultan directamente, con consultas preparadas |

### Metas

| Clave | Tipo | Quién la escribe | Qué guarda |
| --- | --- | --- | --- |
| `_upsell_ids` | producto | `UpsellsAutofill` | La lista de recomendados de WooCommerce |
| `_akb_upsells_autofill_sig` | producto | `UpsellsAutofill` | Huella `sha1` de lo que escribió el robot, para saber si alguien la editó después |
| `_akb_marketing_consent` | pedido | `MarketingConsent` | JSON con `value`, `label`, `at`, `source`, `policy_modified_gmt` |
| `_billing_phone` · `_shipping_phone` | pedido | `PhoneValidation` (los reescribe) | Los teléfonos en forma canónica `+569XXXXXXXX` |
| `_billing_rut` | pedido | **Sólo lectura** (clave retirada) | El RUT de los pedidos anteriores al campo nativo |
| `_wc_other/akibara/rut` | pedido | `akibara-cart-checkout-blocks` | El RUT vivo |
| `_akb_eta_has_preventa` · `_akb_eta_ready_to_ship` | pedido | `OrderPreventa` (fuera de este capítulo) | **Sólo lectura** aquí: `OrderTimeline` los consulta |
| `default_password_nag` | usuario | `MyAccountUx` | Se pone a falso tras cambiar la contraseña desde Mi cuenta |
| bajas de `SeriesFollow` | usuario | `SeriesFollow` (fuera de este capítulo) | **Sólo lectura** aquí: `Renderer` las consulta para pintar los interruptores |

### Transients

| Clave | Quién lo escribe | Vida |
| --- | --- | --- |
| `akb_smartcart_<md5 de carrito+usuario>` | `SmartCartRecommender` | 5 minutos |
| `akb_user_history_data_<user_id>` | `SmartCartRecommender` | 6 horas, o hasta que el cliente compre |
| `akb_upsells_coverage_v2` · `akb_upsells_health_v2` | `UpsellsAutofill` | 5 minutos |

### Sesión de WooCommerce

`akb_pudo_selection` y las cuatro claves `bluex_agency_id`, `bluex_agency_name`,
`bluex_agency_address`, `bluex_pudo_selected` son una **proyección no autoritativa**, escrita por
`PudoSelectionStore` para que el plugin de Blue Express siga viendo lo que espera. La verdad vive en
la fila `akb_pudo_state_*`.

### Qué se lleva y qué se queda al desinstalar el plugin

Desinstalar es distinto de desactivar. **Desactivar** no borra nada: sólo retira los crons
(`akibara-business.php:239-253`). **Desinstalar** ejecuta `uninstall.php`, 118 líneas que borran:

| Qué | Cómo |
| --- | --- |
| Las options y los `transient` propios | `DELETE` sobre `wp_options` por los prefijos `akb_` y `akibara_`, excluyendo los de los plugins hermanos (`:95-105`) |
| El term-meta propio | `DELETE FROM wp_termmeta WHERE meta_key LIKE '_akb_%'` (`:109-111`) |
| El user-meta de «Sigue esta serie» | `DELETE FROM wp_usermeta WHERE meta_key LIKE '_akb_sf_%'` (`:115-117`) |

**Lo que NO borra, y hay que saberlo:**

- **La tabla `wp_akb_wishlist` se queda.** En todo `uninstall.php` no hay **ningún** `DROP TABLE`.
  Al desinstalar el plugin, la tabla con el `user_id` de cada cliente y qué tomos guardó **sigue en
  la base de datos**, sin nadie que la lea. Compruébalo con
  `grep -c 'DROP' public_html/wp-content/plugins/akibara-business/uninstall.php` → **0**.
- **Las metas de pedido tampoco.** `_akb_marketing_consent`, `_billing_rut`,
  `_wc_other/akibara/rut`, `_akb_eta_*` viven en las tablas de pedidos de WooCommerce y no se
  tocan. Eso sí es deliberado y correcto: son parte del historial del pedido, y borrarlas sería
  destruir la prueba de qué aceptó el cliente.

**¿Descuido u olvido lo de la tabla?** No hay ningún comentario que lo justifique, mientras que
todos los demás borrados sí llevan el suyo explicando por qué. Contrasta además con el trabajo que
el módulo dedica a la privacidad: tiene un `PrivacyHandler` entero para exportar y borrar los
favoritos de **una** persona, y engancha `deleted_user` para limpiar los de una cuenta borrada.
Barrer la tabla al desinstalar es la pieza que falta de ese mismo cuidado. **Queda anotado como
deuda, no arreglado aquí: tocar `uninstall.php` es irreversible por definición y el borrado de
datos de clientes lo autoriza el dueño.**

---

## Si cambio esto, ¿qué más se mueve? (índice inverso)

Las tablas de arriba se leen «de la clave hacia afuera». Al depurar hace falta lo contrario:
se parte del síntoma. Estas son las cadenas que más veces han costado tiempo.

| Síntoma o cambio | Qué mirar, en este orden |
| --- | --- |
| **«El cliente ve dos montos distintos de envío gratis»** | 1) `akibara_fsp_carrier_threshold` frente a `akibara_fsp_threshold` — el ⚠(1) de la ficha 7 explica cómo uno anuncia y el otro califica. 2) El shortcode `[akb_envio_gratis]` (`ShippingCopy.php:40`), que pinta el mismo número en la barra de anuncios y en la FAQ, y que nadie recuerda al cambiarlo |
| **«Cambié el umbral del transportista»** | Se mueven **tres** superficies: la barra del cajón y de `/carrito/`+`/checkout/`, la barra de anuncios y la FAQ. Todas leen la misma option |
| **«La barra de envío gratis desapareció»** | 1) ¿La tarifa elegida se llama «retiro» o «pickup»? La heurística del título la apaga (⚠(2) de la ficha 7). 2) ¿El paquete tiene país en la dirección? Sin país, falla cerrado. 3) ¿`akibara_fsp_enabled` en `yes`? |
| **«El checkout perdió el RUT, el teléfono y los sellos»** | El arranque mudo de `akibara-cart-checkout-blocks` (apartado «Qué es `$business`»). Ningún error en ningún registro |
| **«Mi código nuevo no corre al crearse un pedido»** | Se enganchó a `woocommerce_checkout_order_processed`, que el checkout de bloques no emite. El bueno es `woocommerce_store_api_checkout_order_processed` |
| **«El corazón no guarda»** | 1) ¿Hay sesión? Los favoritos la exigen (`Storage.php:46-52`). 2) ¿El `nonce` caducó? Se auto-sana y reintenta una vez. 3) ¿Volviendo de entrar? Mirar `sessionStorage.getItem('akbWishlistPending')` y la caducidad de media hora |
| **«Favoritos dice N y pinta menos»** | Encabezado (`Storage::count()`) frente a cuadrícula (`post_status` + visibilidad), más el tope de 100 sin paginación. ⚠ de la ficha 5 |
| **«El interruptor de Mi colección no guarda»** | Las dos vías de escritura son de `SeriesFollow`: el POST con `akb_sf_global` + `_akb_sf_nonce`, y `POST /wp-json/akibara/v1/series-follow` con `X-WP-Nonce`. Ficha 13 |
| **«No encuentro los errores en Registros»** | Los canales llevan prefijo: `akibara-coleccion`, `akibara-consentimiento`. Buscar la palabra sola no devuelve nada |
| **«No encuentro la pestaña Upsells»** | Se lee **Recomendaciones**; el identificador interno es `upsells_autofill` |
| **«No me deja elegir el punto de retiro»** | La tabla de códigos de la ficha 11. PUDO **no escribe en Registros**: se depura por estado, no por registro |
| **«Un correo dice algo que no corresponde al estado del pedido»** | `woocommerce_email_after_order_table` lo emiten **todos** los correos al cliente. La tabla de la ficha 10 dice cuáles son |
| **«El checkout se rompe en móvil sin mensaje»** | Los scripts de Mercado Pago y LiteSpeed: `FrontendCompatibility` y `CrossOriginScripts`, capítulo 06 |
| **«Nadie recibe correos»** | No es de este capítulo: el transporte es `Services/MailerBoot.php`, capítulo 07. Ya hubo tres días de producción sin ningún correo por esa vía |

---

## Deuda documentada (lo que este capítulo deja anotado, no arreglado)

Tres cosas que **no** son defectos de comportamiento y aun así son trabajo pendiente, para que no se
pierdan entre los ⚠:

1. **El docblock de `OrderTimeline.php:11-17` sigue mintiendo** en producción: promete un nodo
   «Entregado» y un quinto nodo que `build_steps()` no construye. Ya hizo caer un mapa de lectura.
   Arreglo de una línea, sin cambio de comportamiento. Tiene su propio ⚠ en la ficha 12.
2. **El comentario de `templates/myaccount/dashboard.php:68-71`** afirma que «el endpoint `rewards`
   lo registra Simple Points and Rewards». SPAR ya no está instalado: lo registra el plugin propio
   `akibara-rewards` (`src/Points/AccountRewards.php:73`), y la función `spar_get_user_points()` la
   publica su puente de compatibilidad (`akibara-rewards.php:204-221`).
3. **`uninstall.php` no borra `wp_akb_wishlist`** (ver arriba). Decisión del dueño, porque implica
   borrar datos de clientes.

---

## Glosario del capítulo

- **AJAX** — Petición que el navegador hace en segundo plano, sin recargar la página. El cajón del
  carrito se llena así.
- **`wc_ajax_` frente a `wp_ajax_`** — Las dos formas de registrar una petición de este tipo.
  `wc_ajax_<acción>` es de WooCommerce, atiende a todo el mundo —con sesión y sin ella— y por eso
  **no** tiene variante `nopriv`; su URL es `/?wc-ajax=<acción>`. `wp_ajax_<acción>` es de
  WordPress y atiende **sólo** a quien tiene sesión: para atender también a un anónimo hay que
  registrar además `wp_ajax_nopriv_<acción>`; su URL es `/wp-admin/admin-ajax.php?action=<acción>`.
- **Arriendo (*lease*)** — Un turno con dueño y con hora de caducidad. Mientras dure, sólo quien lo
  tomó puede confirmar la operación; cualquier intento anterior queda invalidado. Es lo que abre
  `PudoMutationGuard::begin_selection()` antes de preguntarle un precio al transportista, y lo que
  impide que una respuesta vieja que llega tarde pise a una nueva.
- **Bloques** — El formato nuevo de WooCommerce para el carrito y el checkout: se pinta en el
  navegador con JavaScript. En Akibara lo gobierna el plugin `akibara-cart-checkout-blocks`.
- **Cajón (drawer)** — El panel que se desliza por el costado de la pantalla. Aquí, el carrito.
- **Capacidad (`manage_woocommerce`, `list_users`)** — Permiso con nombre que WordPress asigna a un
  rol. Preguntar por la capacidad, y no por el rol, es la forma correcta de decidir quién puede qué.
- **Comparar-y-cambiar** — Escribir un valor sólo si el valor actual es exactamente el que se leyó
  antes. Es lo que impide que dos peticiones simultáneas se pisen.
- **Cron (`wp-cron`)** — El planificador de WordPress. No es un reloj real: son tareas que se
  ejecutan cuando alguien empuja la cola. En producción la empuja el cron de Hostinger cada 5
  minutos, porque `DISABLE_WP_CRON` está activo.
- **Endpoint** — Una dirección con nombre dentro del sitio: `/mi-cuenta/favoritos/` es un endpoint de
  Mi cuenta; `/?wc-ajax=akb_get_cart` es un endpoint AJAX.
- **Filtro (`add_filter`)** — Un hook que **devuelve** un dato modificado. Frente a la acción
  (`add_action`), que hace algo pero no devuelve nada.
- **Fingerprint (huella)** — Un resumen corto y único de algo grande. Aquí, un `sha256` del carrito:
  si el carrito cambia, la huella cambia.
- **HMAC** — Un resumen firmado con una clave secreta. Se usa para nombrar las filas de PUDO sin
  escribir el identificador de sesión en la base de datos.
- **Hook** — El aviso que WordPress lanza en un momento concreto y al que un módulo se apunta para
  despertar. Todo este capítulo funciona a base de hooks.
- **HPOS** — El almacenamiento nuevo de pedidos de WooCommerce, en tablas propias. Las consultas de
  este capítulo usan las tablas de **líneas** de pedido, que son las mismas con y sin HPOS.
- **Meta** — Un dato extra colgado de un pedido, un producto o una cuenta. Por ejemplo el RUT de un
  pedido.
- **Mini-carrito** — El carrito pequeño que trae WooCommerce de serie, distinto del cajón propio.
- **Nonce** — Credencial de un solo uso que acompaña a una petición para demostrar que salió de una
  página legítima y no de un sitio ajeno. Caduca, y por eso el cajón sabe pedir uno fresco.
- **Option** — Una fila con nombre y valor en la base de datos. Es donde vive casi todo lo que el
  dueño configura.
- **PUDO** — *Pick Up / Drop Off*: retiro en un local del transportista. En Akibara, un Punto Blue
  Express.
- **Prioridad** — El número con que un módulo se apunta a un hook. Menor número, antes corre. Por eso
  el corazón de favoritos (6) sale antes que la vista rápida (7) y que el botón de compra (10).
- **REST / Store API** — Las direcciones por las que el navegador —o un tercero— habla con el
  servidor en formato de datos. La Store API es la de WooCommerce para carrito y checkout.
- **`sessionStorage`** — Un cajón de datos del navegador que vive **sólo mientras esté abierta esa
  pestaña** y no viaja al servidor. Aquí guarda qué tomo quería guardar el cliente antes de que lo
  mandaran a iniciar sesión, bajo la clave `akbWishlistPending`. En modo privado puede estar
  bloqueado, y entonces el guardado pendiente simplemente no existe.
- **Sesión de WooCommerce** — El espacio donde se guarda lo que el cliente lleva a medias: el
  carrito, el método de envío elegido, los avisos pendientes.
- **Store API `…_order_processed`** — El instante exacto en que un pedido del checkout de bloques ya
  existe. `PhoneValidation` y `MarketingConsent` se cuelgan justo ahí. Importa que sea **el único**
  fiable: como el checkout de esta tienda es de bloques, los hooks clásicos del final del checkout
  (`woocommerce_checkout_order_processed`, `woocommerce_checkout_update_order_meta`) **no se
  emiten**, y quien se enganche ahí no se entera de ninguna compra ni recibe ningún error.
- **Transient** — Un dato guardado con fecha de caducidad. Se usa para cachear cosas caras de
  calcular; si no está, se recalcula.
- **UCSS (LiteSpeed)** — La optimización que borra el CSS que cree que no se usa. Por eso hay que
  meter ciertos selectores en una lista blanca.
- **Upsell** — El bloque «Productos recomendados» al pie de una ficha. No confundir con los
  *cross-sells*, que son los del carrito.
