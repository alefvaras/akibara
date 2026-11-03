# Transversal · cómo se hablan entre sí los plugins, el tema y los mu-plugins; qué se rompe si falta uno

> **Fuente de verdad de este capítulo:** el snapshot de lo publicado en producción — `akibara-business` 1.116.4,
> `akibara-search` 0.1.60, `akibara-cart-checkout-blocks` 2.2.17, `akibara-rewards` 0.3.13, el tema `akibara-gp` 2.1.118
> y los 18 mu-plugins. Cada afirmación técnica lleva su `fichero:línea`, y todas están leídas contra ese snapshot.
>
> **Cómo leerlo si es tu primer día.** Las palabras en `código` (`serving_ready()`, `plugins_loaded`, `faceta`…) están
> todas explicadas en el **Glosario del capítulo**, al final. No hace falta leerlo antes: cuando una palabra estorbe,
> está ahí.
>
> **Alcance, dicho antes de empezar.** Los cuatro plugins propios y el tema se revisaron enteros. De los mu-plugins
> se revisaron en detalle los **nueve que tienen arista** con una pieza propia; los otros ocho se nombran, se dice qué
> hacen en una línea y se dice explícitamente que **no** se auditaron para este capítulo (están en la tabla de §17).
> También quedan fuera los ficheros `.min` compilados que se sirven al navegador: aquí se habla del código fuente.
>
> **Actualización 2026-09-26.** El lote de UX y rendimiento del tema (en producción el 2026-09-26 06:08-06:11
> UTC: `akibara-search` 0.1.75, `akibara-business` 1.117.0, `akibara-cart-checkout-blocks` 2.2.26,
> `akibara-rewards` 0.4.18 y tema 2.1.143) abrió costuras nuevas y cambió dos de las de arriba. Están
> juntas en **§19 «Las costuras del lote del 2026-09-26»**, con el **orden de despliegue y sus acoples**, y
> marcadas «(2026-09-26)» donde tocan una sección vieja. Leídas contra los ZIP publicados de `dist/`.

---

## Para qué existe (en dos párrafos que entienda un novato)

akibara.cl es una tienda hecha con **WordPress** —el programa que arma y sirve las páginas— y **WooCommerce**, un
añadido de terceros que le pone carrito, pedidos y pagos. Encima de esos dos, Akibara tiene seis piezas propias:
cuatro *plugins* (`akibara-business`, `akibara-search`, `akibara-cart-checkout-blocks`, `akibara-rewards`), un *tema*
(`akibara-gp`, que decide cómo se ve todo) y 18 *mu-plugins*, que son ficheros sueltos que WordPress carga **siempre**,
antes que cualquier plugin, y que no se pueden apagar desde el panel. Ninguna de esas seis piezas hace la tienda sola.
La portada que ve un cliente es el tema pintando, el buscador metiendo su barra lateral dentro de los huecos que deja
el tema, business decidiendo el precio y el orden, y WooCommerce poniendo el botón de comprar. Todo eso ocurre en la
misma petición y en un orden que nadie escribió en un solo sitio.

Este capítulo no cuenta qué hace cada pieza por dentro —para eso están los capítulos de cada plugin—. Cuenta **las
costuras**: por dónde se hablan. Importa porque **todas las averías caras del proyecto han ocurrido en una costura, no
dentro de una pieza**: tres horas sin buscador por un valor que otro componente dejó mal guardado, un correo que dejó
de salir durante tres días porque el fichero que lo enviaba desapareció, un arreglo de portadas en los correos de
carrito abandonado que estuvo un mes escrito sin ejecutarse **ni una sola vez** porque se registraba un instante antes de
que exista lo que necesita. Si alguien tiene que entender una sola cosa de esta tienda antes de tocarla, es este mapa.

> **Los diagramas de este capítulo.** Cada uno está en `diagramas/` dos veces, con el mismo nombre: el `.svg` es la
> imagen ya dibujada —doble clic y se abre en cualquier navegador— y el `.mmd` es su fuente en texto (formato
> *Mermaid*), que es lo que se edita si el diagrama cambia. Cada uno se enlaza en el punto del texto que le
> corresponde.

[**Diagrama · Piezas y costuras**](diagramas/19-como-se-hablan-los-plugins-piezas.mmd) — el mapa entero en una figura:
las seis piezas propias, los terceros, los datos compartidos y de qué clase es cada línea que los une.

---

## Cómo encaja con el resto (con quién habla: plugins, tema, terceros; qué pasa si se apaga)

### Las cinco formas de hablarse, y cuánto duele cada una

| Forma | Qué es | Cuánto duele si el otro no está |
| --- | --- | --- |
| **Llamada directa de clase** | Una pieza nombra una clase de otra: `\Akibara\Business\Services\NavigationLinks::home_url()`. | **Fatal.** PHP corta la página con *Class not found* y el visitante ve una pantalla en blanco — salvo que quien llama use `class_exists()` primero. |
| **Hook** | Un aviso con nombre. Una pieza grita «pasó esto» (`do_action`) o «tengo este valor, corríjanlo si saben más» (`apply_filters`) y otra puede tener apuntado que quiere escuchar. | Normalmente **nada**: si nadie escucha, el valor por defecto sigue. La excepción de esta tienda es el precio, y va justo al revés de lo que se espera: que **nadie** conteste es un caso previsto y el buscador lo tolera (sirve con el precio base); lo que apaga el buscador entero es que conteste **alguien de más**. Dos oyentes en `akb_search_effective_price` y se acabó. |
| **Option compartida** | Una fila de configuración en la base de datos, con nombre. Dos piezas que no se conocen leen y escriben la misma fila: **el nombre es todo el contrato**. | Silencioso y traicionero: el dueño edita un texto en un panel y quien lo pinta es otro plugin. Si ese otro se va, el campo sigue guardando algo que ya nadie lee. |
| **Tabla de base de datos** | Una pieza lee con SQL crudo una tabla que es de otra. | Degradación: el panel dice «índice no disponible» en vez de romperse. |
| **Endpoint REST y eventos de JavaScript** | Una dirección `https://akibara.cl/wp-json/…` que el navegador consulta sin recargar, y avisos entre trozos de JavaScript. | Depende: normalmente el navegador se queda mudo, sin error visible. |

### El orden de arranque, que no es un detalle

WordPress dispara un aviso llamado `plugins_loaded` cuando terminó de cargar los plugins. Cada pieza se apunta con un
**número de prioridad**: número más bajo, corre antes. Y cuando dos comparten número, **gana quien se apuntó antes**.
Esa regla se resuelve de dos maneras distintas, y conviene tener las dos claras:

- **Si uno de los dos es un mu-plugin, gana siempre el mu-plugin**, porque WordPress carga los mu-plugins antes que
  cualquier plugin y por tanto sus `add_action` quedan primeros en la fila.
- **Si son dos plugins**, decide el orden en que WordPress los cargó (alfabético por carpeta, en la práctica), que
  **nadie fijó a propósito**. Ese es el caso del empate de prioridad 3 de §8, donde no hay ningún mu-plugin de por medio.

```
mu-plugins ─── se cargan ANTES que cualquier plugin (sólo dejan apuntados sus avisos)
   │
plugins_loaded ─┬─ 15 ── akibara-search arranca                    (akibara-search.php:80)
                ├─ 20 ── PRIMERO los dos mu-plugins apuntados en 20:
                │          akibara-brevo-portada-jpg-en-el-carrito.php:131
                │          akibara-bluex-cotiza-solo-con-comuna.php:82
                ├─ 20 ── akibara-business arranca                  (akibara-business.php:159)
                │          ← y SÓLO aquí registra su autoloader    (akibara-business.php:138)
                ├─ 20 ── akibara-rewards arranca                   (akibara-rewards.php:191)
                ├─ 25 ── rewards declara spar_get_user_points()    (akibara-rewards.php:202-223)
                └─ 30 ── cart-checkout-blocks arranca              (akibara-cart-checkout-blocks.php:54)
   │
init:999 ── business detecta cambio de versión y emite akibara_business_upgraded
```

Ese detalle de relojero —«el autoloader de business vive **dentro** de su arranque»— es la causa exacta de que el
mu-plugin de Brevo no se enganche nunca (ver *Casos de uso · Caso 5*). El de Blue Express, apuntado en la misma
prioridad 20, **no** tiene el problema: sólo pregunta por clases del plugin ajeno de Blue, que a esa altura ya existen.

[**Diagrama · El orden de arranque**](diagramas/19-como-se-hablan-los-plugins-arranque.mmd) — el mismo esquema como
línea de tiempo, con el punto exacto donde el mu-plugin de Brevo pregunta por una clase que todavía no existe.

### Qué se rompe si se apaga cada pieza

| Se apaga | Qué pasa |
| --- | --- |
| **`akibara-business`** | **Caída total del front**, pero no por el buscador. El pie de página (`themes/akibara-gp/template-parts/footer/main.php:27`) y la barra inferior móvil (`footer/bottom-nav.php:22-24`) llaman a `NavigationLinks` **sin red**, y se pintan en todas las páginas: eso solo es la pantalla en blanco. Además el plugin de bloques no registra nada (`cart-checkout-blocks/src/Plugin.php:30-42`). **El buscador, en cambio, sigue sirviendo**: la ausencia de proveedor de precio es un caso previsto y aceptado (ver §3), y el índice se llena con el precio base. |
| **`akibara-search`** | Sin fatal. El tema degrada mudo (todas sus llamadas van con `function_exists`), el Centro de Mando dice «índice no disponible» (`AdminDashboard.php:765-769`) y `/tienda/?_buscar=` deja de filtrar — **pero business sigue rotulando el selector de orden como «Relevancia»** (`akibara-business.php:332-345`). El cliente lee una promesa que nadie cumple. |
| **`akibara-rewards`** | Sin fatal. Desaparecen el submenú del panel, la tarjeta de puntos del Centro de Mando y el saldo en Mi cuenta (la plantilla `templates/myaccount/dashboard.php:73` llama a `spar_get_user_points()`, que deja de existir). Se dejan de acreditar puntos y de invitar a reseñar. |
| **`akibara-cart-checkout-blocks`** | Sin fatal, pero el checkout pierde RUT, comuna canónica, retiro PUDO y los dos avisos editables. Además **la página `/carrito/` se queda sin ningún `<h1>`**: el tema suprime el suyo de forma incondicional (`themes/akibara-gp/inc/page-header.php:47-58`) y el único `<h1>` de esa página lo pone un shortcode de este plugin (`cart-checkout-blocks/src/Shortcodes.php:38-43`). |
| **el tema** | business sigue encolando ficheros del tema, pero con `file_exists` delante (`Forms.php:115`), y `ThemeAssetVersion` cae a su propio cálculo (`ThemeAssetVersion.php:50-56`). El buscador pierde los hooks donde pinta. **(2026-09-26)** Además: `ThemeAssetVersion::served_path()` resuelve por su cuenta qué copia servir (la `.min` si existe, la fuente con `SCRIPT_DEBUG`); nadie pinta la venta cruzada en `akibara_search_empty_after` (el aviso del buscador sale igual, sin ella); el carrusel de reseñas de rewards no encuentra `gsap` registrado y no anima; y los filtros `akb_eta_linea_corta` y `akb_guide_series_card` de business quedan sin quien los pregunte. |
| **mu-plugin del correo** (`akibara-correo-smtp.php`) | No sale ningún correo de la tienda. Un segundo mu-plugin, el centinela `akibara-mail-transport-watch.php`, comprueba que siga cargado y **repone el fichero** desde una copia. |
| **mu-plugin de barandas de LiteSpeed** | Vuelve el riesgo de combinar CSS (que ya rompió la portada) y de cachear el carrito para clientes con sesión. |

### Terceros que están en medio de casi todas las costuras

- **WooCommerce** — emite los hooks de la rejilla (`woocommerce_before_shop_loop`, `woocommerce_no_products_found`) donde entran a la vez el buscador y business, y el **Store API** que extienden **dos** plugins propios a la vez.
- **LiteSpeed Cache** y **Cloudflare** — deciden si el HTML que se sirve es el recién generado o uno de antes. Cuatro piezas propias les hablan.
- **Blue Express** — cotiza el despacho y presta los puntos PUDO; cuatro mu-plugins lo corrigen desde fuera, **y el plugin de bloques le lee por ruta un fichero de datos** (ver §10).
- **Brevo** — manda el correo de carrito abandonado; un mu-plugin le presta la portada en JPG.
- **Rank Math** — escribe el título y los datos estructurados; el buscador le corrige lo que escribiría en una página de búsqueda (`facets-render.php:782` y `:833`).

---

## Piezas

### 1. El orden de arranque (no es una clase, es el reparto de turnos)

**Qué hace en una frase:** decide quién habla primero, y por lo tanto quién ve a quién.

- **Ficheros:** `akibara-search/akibara-search.php:80` · `akibara-business/akibara-business.php:138` y `:159` · `akibara-rewards/akibara-rewards.php:191` y `:202-223` · `akibara-cart-checkout-blocks/akibara-cart-checkout-blocks.php:54` · `akibara-brevo-portada-jpg-en-el-carrito.php:141` (prioridad **25**: espera al autocargador de business) y `akibara-bluex-cotiza-solo-con-comuna.php:82` (prioridad 20).
- **Cuándo actúa:** `plugins_loaded`, prioridades 15 / 20 / 20 / 25 / 30.
- **Qué lee y escribe:** nada propio. Sólo define el orden.
- **Dónde lo opera el dueño:** en ningún sitio. Es código.
- **Qué ve el cliente:** nada… salvo cuando el orden falla, y entonces ve un hueco donde debía ir una portada, o una página en blanco.

### 2. `Akibara\Business\Plugin` — la raíz que tiende todos los cables

**Qué hace en una frase:** crea los ~70 módulos de business y, sobre todo, **le tiende los cables a las otras tres piezas propias**.

- **Ficheros:** `akibara-business/src/Plugin.php`.
- **Cuándo actúa:** dentro del arranque de `plugins_loaded:20`.
- **Qué lee y escribe:**
  - `Plugin.php:1223` cuelga el proveedor de precio del buscador en `akb_search_effective_price`. **Se registra siempre**, esté o no el buscador instalado.
  - `Plugin.php:811` siembra la lista de rangos de Cloudflare que comparten los **dos** limitadores de peticiones.
  - `Plugin.php:210` y `:707-763` guardan la option `akibara_business_search_feed_contract_version` y, cuando cambia, piden al buscador una reconstrucción completa.
  - `Plugin.php:1483-1496` expone `checkout_data()` y `pudo_checkout()`, que es todo lo que el plugin de bloques le pide (el contrato completo, en §10).
  - `Plugin.php:272` y `:369` emiten `akibara_business_booted` y `akibara_business_upgraded`.
- **Dónde lo opera el dueño:** indirectamente, por el panel **Akibara** (`admin.php?page=akibara-panel`).
- **Qué ve el cliente:** todo lo chileno de la tienda.

### 3. El puente de PRECIO — la costura más delicada del sitio

**Qué hace en una frase:** business es el único que sabe calcular el precio con descuento de editorial, y el buscador lo guarda en su índice sólo si puede demostrar que los dos están mirando exactamente la misma configuración.

- **Ficheros:** `akibara-search/src/EditorialDiscountPublication.php` ⟷ `akibara-business/src/Modules/TaxonomyDiscount/SearchPriceProvider.php`.
- **Cuándo actúa:** cada vez que el buscador escribe un documento en su índice.
- **Cómo funciona, paso a paso:**
  1. El buscador saca una **foto** de toda la configuración de descuento —options, zona horaria, ventana de vigencia, tope de porcentaje, decimales, taxonomías y termmeta— y se la entrega a business: `adopt_search_snapshot($snapshot)` (`EditorialDiscountPublication.php:3089`).
  2. business la valida, la adopta como `PENDING` y devuelve un token `sha256`; sólo al primer cálculo pasa a `ACTIVE` (`SearchPriceProvider.php:110-159` y `:229-297`).
  3. El buscador vuelve a comparar: la foto tiene que ser idéntica a `authoritative_snapshot()` y `snapshot_is_fresh($token)` tiene que ser exactamente `true` (`EditorialDiscountPublication.php:3090-3097`).
  4. Si algo no cuadra, business **lanza** `PriceAuthorityUnavailable` en vez de devolver el precio base (`SearchPriceProvider.php:121-123` y `:337`), y queda en `FAILED`, del que no se vuelve en esa petición.
- **Cómo el buscador encuentra al proveedor:** mirando la tabla global de hooks `$wp_filter` y **contando** cuántos oyentes tiene `akb_search_effective_price`. Exige **exactamente uno**, en prioridad 10, con dos argumentos y de la clase exacta `Akibara\Business\Modules\TaxonomyDiscount\SearchPriceProvider` (`EditorialDiscountPublication.php:3020-3031`).
- **La ausencia SÍ está prevista; la compañía, no.** Si el hook no tiene **ningún** oyente —porque business está desactivado—, `resolve_price_provider()` devuelve `null`, y su propio docblock llama a eso *«ausencia válida»* (`EditorialDiscountPublication.php:2992`; las dos ramas que devuelven `null`, en `:2996-2998` y `:3017-3019`). `adopt_price_snapshot()` toma esa rama, marca la petición como «sin proveedor» y **devuelve una foto válida con token vacío** (`:3074-3082`), que la verificación posterior acepta (`:3114-3117`). Traducido: **sin business el buscador sigue sirviendo, con el precio base**.
- **Consecuencia que hay que memorizar:** lo que apaga el buscador es un oyente **de más**. La comprobación es literalmente `1 !== count( $flat )` (`:3020`): con dos oyentes la cuenta da 2, la comprobación falla, el buscador se declara no autoritativo y **se apaga entero, sin mensaje**. Cualquiera los añade sin querer: un mu-plugin de pruebas, una sonda, un módulo nuevo. Lo mismo vale para un oyente único pero mal formado: otra prioridad, otro número de argumentos o de otra clase. Es deliberado, y es la forma exacta de las tres horas sin buscador que ya se pagaron una vez.
- **Cómo se comprueba hoy, en un comando:** ver *«Cómo comprobar y cómo levantar»*, al final de esta sección.
- **Dónde lo opera el dueño:** el porcentaje, en la ficha de cada editorial; la ventana y el tope, en el panel de descuentos (`admin.php?page=akibara-panel`, pestaña de descuentos).
- **Qué ve el cliente:** el precio tachado. Y, cuando esto falla, la desaparición silenciosa del autocompletar.

[**Diagrama · Estados de la autoridad de precio**](diagramas/19-como-se-hablan-los-plugins-estados-precio.mmd) — los
**cuatro** estados por los que pasa el proveedor dentro de una sola petición. Toda petición empieza en `EMPTY` (aún sin
foto adoptada: `SearchPriceProvider.php:52-59`); de ahí pasa a `PENDING` al adoptar, a `ACTIVE` al primer cálculo, y a
`FAILED` en cuanto algo no cuadra. De `FAILED` no se vuelve hasta la petición siguiente.

### 4. El puente de VOCABULARIO de stock

**Qué hace en una frase:** WooCommerce sólo conoce tres estados y Akibara necesita cuatro, así que el buscador le pregunta a business cuál de los dos negocios es un producto reservable.

- **Ficheros:** `akibara-search/src/StockLabels.php` ⟷ `akibara-business/src/Modules/Stock/SearchAvailabilityBridge.php`.
- **Cuándo actúa:** `akb_search_availability_value` y `akb_stock_status_label`, los dos en prioridad 10 con 2 argumentos (`SearchAvailabilityBridge.php:43-44`).
- **Qué lee y escribe:** el buscador trae su propio mapa por defecto y **nunca hace `use` de business** (`StockLabels.php:65-75`). Para separar preventa de «a pedido», business responde `'preventa'` sólo si `Availability::is_preventa()` ve un lanzamiento pendiente (`SearchAvailabilityBridge.php:78-83`).
- **Sin business:** todo lo reservable se etiqueta «A pedido». Degradación, no error.
- **Trampa medida:** `provide_label()` devuelve `StockStatus::LABELS[$status] ?? (string) $default_label` (`SearchAvailabilityBridge.php:58-61`) — o sea, **descarta** el valor que traiga el filtro siempre que el estado sea conocido. Como los mu-plugins se apuntan antes que los plugins, un `add_filter('akb_stock_status_label', …, 10, 2)` puesto en un mu-plugin queda primero en la fila de la prioridad 10 y business lo pisa después. El único modo de que el override documentado funcione es **prioridad 11 o mayor**, y eso no está escrito en ninguno de los dos docblocks que llaman a ese filtro «el ÚNICO punto de override».
- **Qué ve el cliente:** la palabra de la insignia: Disponible · A pedido · Preventa · Agotado.

### 5. `NavigationLinks` — el contrato business → tema

**Qué hace en una frase:** es la única fuente de las URLs canónicas de la tienda (inicio, catálogo, carrito, cuenta, contacto, favoritos).

- **Ficheros:** `akibara-business/src/Services/NavigationLinks.php`.
- **Cuándo actúa:** cuando cualquier plantilla del tema la llama.
- **Superficies del tema que la llaman:**
  - **Sin red, fatal si business no está:** `template-parts/footer/main.php:27`, `template-parts/footer/bottom-nav.php:22-24`, `template-parts/header/shell.php:113-114`, `template-parts/header/mobile-drawer.php:101-102`, `template-parts/brands/grid.php:211`, `inc/woocommerce.php:336`.
  - **Con red (`class_exists` + URL de respaldo):** `404.php:32`, `woocommerce/cart/cart-empty.php:31`, `woocommerce/loop/no-products-found.php:29`.
- **La palanca que nadie usa:** cada URL sale por un filtro `akibara_navigation_url_<clave>` (`NavigationLinks.php:314-321`). **Las claves son seis, y son estas:**

  | Clave | Qué URL cambia | Dónde se aplica |
  | --- | --- | --- |
  | `home` | la portada | `NavigationLinks.php:297` |
  | `shop` | el catálogo (`/tienda/`) | `:289` |
  | `cart` | el carrito (`/carrito/`) | `:273` |
  | `account` | Mi cuenta | `:230` |
  | `contact` | `/contacto/` | `:242` |
  | `wishlist` | favoritos | `:257` |

  **Nadie escucha ninguna de las seis en todo el proyecto.** Usarlas significa escribir un `add_filter` en un
  **mu-plugin** nuevo (no hay pantalla para esto), por ejemplo
  `add_filter('akibara_navigation_url_cart', fn() => 'https://akibara.cl/mi-carrito/');`. Dos advertencias: el HTML
  viejo sigue **cacheado** en LiteSpeed y Cloudflare hasta que se purgue, así que el enlace no cambia solo; y mover
  la URL de verdad exige además que exista esa página en WordPress. Es decir: se puede hacer **sin tocar ninguno de
  los cuatro plugins ni el tema**, pero sí escribiendo código en un mu-plugin.
- **Dónde lo opera el dueño:** en ningún sitio, por diseño. El tema declara la dependencia dura como intencional (`themes/akibara-gp/functions.php:16-22`).
- **Qué ve el cliente:** los enlaces del pie y de la barra inferior… o una pantalla en blanco.

### 6. El tema como consumidor: tres criterios distintos

**Qué hace en una frase:** el tema decide cuánta red pone según cuánto se pueda permitir que el plugin falte.

- URLs → **llamada dura** a `NavigationLinks`.
- Buscador → **`function_exists`** sobre las funciones puente. Si el buscador no está, degrada mudo.
- Textos del dueño → **`get_option()` por nombre**, nunca por clase: `akb_copy_promise_installments` (`inc/site-copy.php:47`), `akb_copy_footer_support_hours` (`:55`), `akb_copy_footer_phone` (`:69`), con el razonamiento escrito: «un tema no puede quedarse sin pie porque un plugin esté desactivado» (`inc/site-copy.php:13-18`).

**Y al revés: business llama a DOS funciones globales del tema.** Las dos van protegidas con `function_exists`, así que
el tema puede faltar sin romper nada:

| Función del tema | Dónde está definida | Quién la llama desde business | Qué decide |
| --- | --- | --- | --- |
| `akibara_gp_asset_ver()` | `themes/akibara-gp/inc/enqueue.php:37` (`:39` en 2.1.143) | `Utilities/ThemeAssetVersion.php::for_path()` (la ruta `Services/` de versiones anteriores de este capítulo era un error), y de ahí el encolado del JS y el CSS de formularios y editoriales | La versión (`?ver=`) con que se sirve un fichero del tema. Si el tema no está, cae a su propio cálculo |
| `akibara_gp_asset_servido()` **(2026-09-26)** | `themes/akibara-gp/inc/enqueue.php:84` (2.1.143) | `Utilities/ThemeAssetVersion.php::served_path()` → `Forms` y `BrandsDirectory` (`for_path( served_path( … ) )`) | **Qué fichero** del tema se sirve: la copia `.min` salvo con `SCRIPT_DEBUG`, y la fuente si la copia falta. Sin el tema (o con un tema anterior a las copias), business aplica la misma regla por su cuenta |
| `akibara_gp_brand_logo()` | `themes/akibara-gp/inc/woocommerce.php:169` | `Modules/BrandsDirectory/BrandFamilyLanding.php:244-245` | **La imagen que se publica en la landing de cada editorial.** El tema busca primero un SVG en `assets/img/editoriales/<slug>.svg` (`inc/woocommerce.php:170-175`); si no responde, business cae al `thumbnail_id` del término |

Que la segunda exista importa más de lo que parece: el logo de una editorial —la cara de `/ivrea/`, `/panini/`— lo
decide un fichero del **tema**, no un campo del panel. Cambiar de tema cambia los logos de las landings sin que nadie
haya tocado business.

### 7. Las funciones puente del buscador

**Qué hace en una frase:** son funciones globales que el tema y business pueden llamar sin conocer ni una clase del buscador.

- **Ficheros:** `akibara-search/akibara-search.php:113-249` (el bloque entero, comentario incluido).
- **Son OCHO, no cuatro ni siete.** Cada una vive dentro de un `if ( ! function_exists(…) )` que la protege del redeclare; la línea que se cita aquí es la de la **definición**, que es la que hay que abrir:

  | Función | Definida en | Quién la consume |
  | --- | --- | --- |
  | `akibara_search_facets_active()` | `:118` | el tema (`woocommerce/loop/pagination.php:25`, `inc/flyout-button.php:52`) |
  | `akibara_search_facets_fail_open()` | `:131` | sólo el propio buscador (`frontend/facets-render.php:1250`, marca el `<meta>` del fail-open) |
  | `akibara_search_facets_view()` | `:141` | sólo el propio buscador (`frontend/facets-render.php`) |
  | `akibara_search_facets_context()` | `:152` | sólo el propio buscador |
  | `akibara_search_suggest_active()` | `:163` | el tema (`template-parts/header/shell.php:103`, `mobile-drawer.php:63`) |
  | `akibara_search_correction()` | `:175` | sólo el propio buscador |
  | `akibara_search_relaxed_notice()` | `:197` | sólo el propio buscador |
  | `akibara_search_demand_gaps()` | `:227` | el panel de business (`AdminDashboard.php:475-476`) |
  | `akibara_search_empty_suggestions()` **(0.1.75)** | bloque `if ( ! function_exists( 'akibara_search_empty_suggestions' ) )` de `akibara-search.php` | sólo el propio buscador (`frontend/facets-render.php`, `akibara_gp_akb_empty_suggestions_html()`) |

- **(2026-09-26) Son NUEVE desde search 0.1.75.** La novena es `akibara_search_empty_suggestions()` (la serie parecida y lo más buscado para el aviso de «sin resultados»), y su único consumidor es `frontend/facets-render.php`, dentro del propio plugin (capítulo 09). El tema sigue consumiendo dos.
- **La cuenta, corregida (2026-09-20, ronda 10).** Esta tabla decía «son SIETE» y se dejaba fuera justo `akibara_search_facets_fail_open()`, que es la que marca el fail-open por gate. Son **ocho**, el **tema consume dos** de ellas en cuatro sitios, business consume una, y las **cinco restantes** las consume `frontend/facets-render.php`, que vive dentro del propio plugin: son globales porque el render server-first se enganchó como si fuera del tema. El docblock del plugin decía «estas 4 funciones puente» y «los 6 consumidores del theme» —los dos números mal— y **ya está corregido en el código** desde search 0.1.73 (`akibara-search.php:91-112`).
- **Doble candado:** los dos interruptores de cara al cliente exigen **además** que `serving_ready()` —la pregunta «¿puedo servir resultados sin mentir?», ver el glosario— sea `true` (`Search.php:322-331` y `:347-356`). El interruptor del panel no basta.

### 8. `frontend/facets-render.php` — el buscador pintando dentro del tema

**Qué hace en una frase:** un fichero del **plugin** que define funciones con el prefijo del **tema** y se cuelga de los puntos de render de WooCommerce para meter la barra lateral, los *chips* y la banda de series.

> *Faceta*, *chip*, *banda de series* y *rejilla* están en el glosario del final. En corto: la **faceta** es cada filtro
> (editorial, precio, disponibilidad); el **chip** es la etiqueta con la ✕ que muestra un filtro ya puesto; la **banda
> de series** es la tira de series que sale sobre los resultados; la **rejilla** es la cuadrícula de productos.

- **Ficheros:** `akibara-search/frontend/facets-render.php`.
- **Cuántas funciones:** **24** funciones `akibara_gp_akb_*` más 4 funciones `akibara_search_*` de la banda de series (:366, :412, :439, :514). Ninguna lleva `function_exists`: si el tema definiera alguna con el mismo nombre, el sitio cae entero con *Cannot redeclare*.
- **Dónde se engancha exactamente (las 18 líneas de `add_action`/`add_filter` del fichero, sin excepción):** `woocommerce_page_title` (:747), `document_title_parts` (:766), `rank_math/frontend/title` prio 20 (:782), `rank_math/json_ld` prio 20 (:833), `template_redirect` prio 0 para canonicalizar `/?s=` (:913), `wp_enqueue_scripts` prio 20 (:1028), `woocommerce_before_shop_loop` prios 1 · 3 · 5 · 36 · 37 (:1050, :1051, :1151, :1152, :1153), `is_active_sidebar` (:1077) para **forzar el hueco** de la barra lateral, `template_redirect` prio 0 otra vez (:1148), **`dynamic_sidebar_before` prio 4 (:1150), que es donde el buscador imprime de verdad la barra lateral dentro de ese hueco**, y `woocommerce_no_products_found` prios 5 · 12 · 16 · 17 (:1161-1164).
- **El endpoint que no es REST:** `akibara_gp_akb_render_facet_options_fragment()` (`:1137-1147`) responde un fragmento de HTML y hace `exit` **antes de que el tema pinte nada**; el que decide si la petición le corresponde es `akibara_gp_akb_facet_options_fragment()` (`:1098-1111`), activado por la cabecera propia `X-Requested-With: akb-facet-options` más `?_akb_expand=`, y el enganche está en `:1148`. Es el único endpoint público del proyecto fuera de `/wp-json/`, corre sobre cualquier superficie de producto —incluidas las rutas `/disponibles/` y `/preventas/` que son de business— y la única protección de caché que lleva es `nocache_headers()` (`:1143`).
- **Empate de prioridad con business:** la banda de series del buscador se pinta en `woocommerce_before_shop_loop` **prioridad 3** (`facets-render.php:1051`) y las intros de `/disponibles/` y `/preventas/` de business también en **prioridad 3** (`DisponiblesRoute.php:192`, `PreventasRoute.php:160`). Aquí no hay ningún mu-plugin: son dos plugins empatados, así que el orden del DOM lo decide el orden en que WordPress cargó las carpetas. Hoy sale estable porque el buscador arranca en `plugins_loaded:15` y business en la 20, pero eso es una coincidencia de arranque, no un contrato. El propio business ya diagnosticó ese defecto en otro bloque suyo y por eso bajó **`SeriesFacts`** —el bloque de datos de la serie que business pinta **encima de la rejilla**, bajo el hero— a **prioridad 2**, explicando por escrito que empatar en la 3 «dejaría el orden del DOM decidido por el orden de instanciación en `Plugin.php` en vez de por un contrato» (`SeriesFacts.php:35-44`).

- **(2026-09-26) El aviso de «sin resultados» abre un sitio para el tema.** `akibara_gp_akb_print_empty_state()` dispara `do_action( 'akibara_search_empty_after', $q, $motivo )` al final del nodo `[data-akb-empty]`, y el tema cuelga ahí «Lo más vendido» (§19). Y la línea del total admite HTML de otro plugin por el filtro `akb_search_result_count_extra` (hoy, el atajo «Último tomo» de business en las series).

### 9. `Akibara\Search\Indexer` — quien reacciona a lo que escriben los demás

**Qué hace en una frase:** escucha **25** avisos y, cuando alguien guarda algo que cambia el catálogo, marca deuda y encola el reindexado.

Los 25 `add_action` están en `Indexer.php:157` y `:160-183`. Cuatro de ellos comparten hook con business, y ahí es
donde el orden importa:

| Hook que escucha | Prio | Quién más responde ahí |
| --- | --- | --- |
| `woocommerce_product_set_stock_status` | 12 | business en 10 y 11; el propio buscador en 10 (`Arrival`) |
| `save_post_product` | 20 | business en 10 (`PdpSeriesEnhancer`, `SeriesFacts`, `BuilderWidget`) |
| `woocommerce_update_product` | 10 | business en 10 (varios invalidadores de caché) |
| `akibara_taxonomy_discount_pct_saved` | 10 | **lo emite business** (`AdminFieldType.php:142` y `:147`); también lo escuchan `LscPurger.php:62` y `TaxonomyDiscount.php:152` |

### 10. El plugin de bloques y el contrato `CheckoutDataProvider`

**Qué hace en una frase:** el carrito y el checkout son React, y este plugin es el traductor que le mete dentro los datos de negocio que le presta business.

- **Ficheros:** el plugin tiene **14** ficheros en `src/`. Los que son costura con otra pieza:

  | Fichero | Con quién es costura |
  | --- | --- |
  | `Plugin.php` | business: es donde se comprueba el contrato |
  | `CheckoutFields.php` | business (RUT y celular) y `ComunaDirectory` (comuna) |
  | `StoreApiIntegration.php` | WooCommerce (Store API) y business |
  | `Assets.php` | business, por tres options leídas por nombre |
  | `Shortcodes.php` | **el tema**: el `<h1>` del carrito |
  | `ComunaDirectory.php` | **Blue Express**, por ruta de fichero (ver abajo) |
  | `AddressIntegration.php` + `GooglePlacesAddressProvider.php` | business (`address_autocomplete_config()`) y Google Places |
  | `PudoServiceAdapter.php` · `PudoIntegration.php` · `PudoLog.php` | business (`pudo_checkout()`) y Blue Express |
  | `CheckoutBlockIntegration.php` | WooCommerce Blocks (`IntegrationInterface`) |
  | `RuntimeCompatibility.php` | business: escribe la option que lee el Centro de Mando |
  | `BlocksPage.php` | uso interno, compartido por los demás |

- **Cuándo actúa:** `plugins_loaded:30`.
- **El contrato, que es la pieza central de esta sección.** `Akibara\Business\Contracts\CheckoutDataProvider` es una *interfaz*: una lista de preguntas que business promete saber responder. Tiene **seis** métodos, y el plugin de bloques usa los seis:

  | Método del contrato | Definido en | Quién lo usa | Qué se cae si business lo deja de cumplir |
  | --- | --- | --- | --- |
  | `structured_cart_data()` | `CheckoutDataProvider.php:74` | `StoreApiIntegration.php:239-243` | Los avisos de colección y las etiquetas de los cupones dentro del carrito de bloques. El código pregunta antes con `method_exists` y se calla |
  | `product_availability()` | `:90` | `StoreApiIntegration.php:375` y `:380` | La disponibilidad (Disponible / A pedido / Preventa) de cada línea del carrito |
  | `is_valid_rut()` | `:100` | `CheckoutFields.php:315`, `:332`, `:382`, `:410` | La validación del RUT: se aceptaría cualquier cosa |
  | `format_rut()` | `:110` | `CheckoutFields.php:316`, `:386`, `:414` | El formato canónico con que el RUT se guarda en el pedido |
  | `phone_validation_error()` | `:120` | `CheckoutFields.php:356` | El mensaje de error del celular chileno |
  | `address_autocomplete_config()` | `:129` | `AddressIntegration.php:59`, `:88`, `:101` | El autocompletar de direcciones (Google Places): sin él, dirección a mano |

  **Aviso importante:** si business no cumple la interfaz, no se cae *uno* de los seis — el arranque entero se detiene
  antes (siguiente punto) y **no se registra nada**. La columna de arriba describe qué depende de cada método, no un
  modo de fallo parcial.

- **La puerta de entrada son TRES `return`, no cuatro.** En `Plugin.php` los tres que abortan todo el arranque son:
  1. `class_exists('WooCommerce')` **y** `class_exists(BusinessPlugin::class)` (`:30-32`);
  2. `method_exists($business, 'checkout_data')` (`:35-37`);
  3. `$data_provider instanceof CheckoutDataProvider` (`:40-42`).

  Si alguna falla, **no registra nada y se calla**. Después ya no se aborta nada más: `pudo_checkout()` se consulta
  (`:47-49`) y su ausencia sólo deja fuera `PudoIntegration` (`:62-64`) — el RUT, la comuna, los avisos, el
  autocompletar de direcciones y los shortcodes se registran igual. Y hay una **cuarta puerta parcial** que conviene
  conocer: `interface_exists('\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface')` (`:58-60`), que si
  falla deja fuera sólo `CheckoutBlockIntegration`.

- **La costura con Blue Express que no pasa por ningún hook:** `ComunaDirectory` es el catálogo de comunas chilenas del
  checkout, y **no lo tiene propio**: lo carga desde dentro del plugin ajeno de Blue Express, **por ruta de fichero**
  (`'bluex-for-woocommerce/includes/data/chile-communes.php'`, `ComunaDirectory.php:26`), con un `require_once` y
  llamando después a su función global `bluex_get_communes_by_region()` (`:377-394`). Su docblock explica por qué:
  es exactamente la lista que la API de cotización de Blue acepta, y usar otra haría fallar la cotización (`:17-20`).
  **No modifica nada del tercero** —eso sí lo prohíben las reglas del repo—, pero sí depende de dónde guarda sus
  ficheros, que es algo que el tercero puede cambiar en cualquier actualización. Falla hacia abierto: si el fichero no
  está o viene roto, `is_available()` devuelve `false` (`:120-122`), no se pinta el desplegable de comunas
  (`Assets.php:339`) y el checkout se queda con el campo de texto libre.

- **Options que lee por nombre, no por clase:** `akibara_blocks_shipping_hint_text` (`Assets.php:33`), `akibara_blocks_collection_notice_text` (`:60`) —los dos campos viven en `business/…/NativeCart/Settings.php`— y `akb_sf_enabled` (`:86`, leída en `:259`). El propio código explica por qué: montar una pantalla propia para un solo texto le habría dado al dueño dos lugares donde buscar el mismo carrito.
- **Divergencia real y acotada:** el checkout lee `akb_sf_enabled` **en crudo** (`Assets.php:259`), mientras business envuelve la misma lectura en `apply_filters('akb_sf_enabled', …)` (`SeriesFollow.php:343`). Los conjuntos de valores verdaderos son **idénticos** en los dos (`array(true, 1, '1', 'yes', 'on')`), así que **no hay divergencia por el valor guardado**: la única es que apagar los avisos *por filtro* deja al cliente leyendo la promesa encima del botón de pagar.
- **El triángulo con el tema:** este plugin publica dos shortcodes (`Shortcodes.php:27-28`): `[akibara_cart_blocks_title]` y `[akibara_cart_blocks_empty]`. El tema **cuenta con el primero** para suprimir el `<h1>` de GeneratePress en `carrito` y `checkout` (`inc/page-header.php:47-58`), y el segundo llama a `wc_get_template('cart/cart-empty.php')`, que resuelve a la plantilla **del tema**, que a su vez consulta a business. Es la única arista bloques → tema del proyecto, y cierra un triángulo completo.
- **Dónde vive realmente ese `<h1>`, y por qué cuelga de un hilo.** Tres hechos incómodos, los tres verificados:
  1. **El shortcode no está en ningún fichero.** Se escribe en el **contenido de la página** desde el editor de páginas de WordPress (`admin.php` → Páginas → Carrito). No aparece en el snapshot ni en ningún despliegue: si alguien lo borra editando la página, no hay revisión de código que lo cace.
  2. **El único título que existe dice «Tu carrito», literal.** `render_cart_title()` devuelve `<header class="akb-page-title"><h1>Tu carrito</h1></header>` (`Shortcodes.php:38-43`). **No hay ningún shortcode de título para el checkout.** O sea: la página de pago o no tiene `<h1>`, o —si alguien puso ahí el mismo shortcode— dice «Tu carrito» en la página de pagar.
  3. **La razón escrita en el tema es falsa.** El comentario dice *«Pages con template override que renderea H1 propio (cart-empty.php)»* (`inc/page-header.php:50`), pero `woocommerce/cart/cart-empty.php` **no contiene ningún `<h1>`** (comprobado con `grep`). La única fuente real de ese `<h1>` es el shortcode del punto 2.
- **Y un tercer riesgo, por el slug:** `akibara_gp_hide_entry_title()` compara con `is_page(array(…, 'carrito', 'checkout'))` (`inc/page-header.php:47-58`), y `is_page()` acepta ID, slug o título. Pero el único rótulo de URL escrito en el tema para esa página dice otra cosa: *«Links en `/carrito/` y `/finalizar-compra/`»* (`assets/css/woocommerce.css:3023`), y la cadena `finalizar-compra` **no aparece en ningún `.php` del proyecto**. Si la página de pago vive realmente en `/finalizar-compra/`, el `is_page('checkout')` no acierta, GeneratePress pinta su `entry-title` **además** del `<h1>` del shortcode y quedan **dos `<h1>` en la página de mayor valor comercial** — justo lo que el docblock de ese fichero dice estar evitando. Se mide contando: `curl -s -A AkibaraQA http://localhost:8080/finalizar-compra/ | grep -o '<h1[^>]*>'`. Uno es sano; dos es el defecto; cero es el defecto contrario.

### 11. `akibara-rewards` y sus tres costuras

**Qué hace en una frase:** puntos y reseñas, apoyándose en business para dos preguntas y prestándole una función para una tercera.

- **Con business — ¿se puede pedir reseña?** `Invitations.php:131-134` y `:174-178` preguntan `CompletionPolicy::can_request_review($order)` **antes de agendar y otra vez antes de enviar**. Sin business, **invita igual**: falla hacia invitar.
- **Hacia business — el saldo de Mi cuenta.** Declara la función global `spar_get_user_points()` en `plugins_loaded:25` (`akibara-rewards.php:202-223`) para que la plantilla `akibara-business/templates/myaccount/dashboard.php:73` pinte el saldo. El comentario del plugin afirma que también lo consume «el menú móvil» (`akibara-rewards.php:195`), pero un barrido sobre todo el snapshot devuelve **un único consumidor**, el de Mi cuenta.
- **Con el plugin de bloques:** los **dos** extienden el **mismo** endpoint del Store API de WooCommerce (`CartSchema`), con namespaces distintos — `akibara-rewards` (`Blocks/CheckoutIntegration.php:67-88`) y `akibara_cart_checkout_blocks` (`StoreApiIntegration.php:104-130`). Los dos resuelven el mismo riesgo de orden con el mismo truco (`did_action('woocommerce_blocks_loaded')`), sin contrato escrito entre ellos. El carrito de bloques es la superficie donde el cliente ve a los dos a la vez: el aviso de colección y el canje de puntos.
- **La puerta de convivencia:** rewards **no arranca** mientras el plugin ajeno *Simple Points and Rewards* siga activo (`akibara-rewards.php:127-140`), y lo dice con un aviso en el escritorio.
- **Dónde lo opera el dueño:** `admin.php?page=akb-rewards-settings` (`Support/SettingsPage.php:27`), al que también se llega desde el Centro de Mando (`AdminDashboard.php:780`).

### 12. `AdminDashboard` + `AdminPanel` — el hub que mira a los otros tres

**Qué hace en una frase:** la única pantalla que junta el estado de las cuatro piezas propias, y para lograrlo lee cosas que no son suyas.

> **Tres nombres, una sola pantalla.** El **panel Akibara** es el menú de administración que registra business, y vive
> en `admin.php?page=akibara-panel` (ojo: el slug corto `akibara` da 403 y parece un problema de permisos). Ese panel
> tiene una portada, y a esa portada el proyecto le dice **Centro de Mando**. Quien registra el menú es la clase
> `AdminPanel`; quien pinta la portada es `AdminDashboard`. Son lo mismo visto desde tres sitios.

- **Ficheros:** `akibara-business/src/Modules/AdminDashboard/AdminDashboard.php`, `…/AdminPanel/AdminPanel.php`.
- **Qué lee:** `{prefijo}akb_search_docs` con SQL directo (`:760-764`), `{prefijo}akb_rewards_ledger` con SQL directo y `class_exists` delante (`:772-781`), la option `akb_blocks_mp_dequeue_observed` del plugin de bloques (`:790`), la función puente `akibara_search_demand_gaps()` (`:434-436`) y `SearchAdminSection::gap_action_url()` (`:454-458`).
- **Las tres pantallas y sus direcciones exactas:**

  | Pantalla | Dirección | Quién la registra |
  | --- | --- | --- |
  | Panel Akibara (portada: Centro de Mando) | `admin.php?page=akibara-panel` | business, `AdminPanel.php:172-186` |
  | Buscador | `admin.php?page=akibara-search` | el buscador, `SearchAdminSection.php:126-133` (enlace desde el hub en `AdminDashboard.php:768`) |
  | Puntos y Reseñas | `admin.php?page=akb-rewards-settings` | rewards, `Support/SettingsPage.php:27` (enlace desde el hub en `AdminDashboard.php:780`) |

- **El menú compartido:** business registra el menú `akibara-panel` en `admin_menu` con prioridad por defecto 10 (`AdminPanel.php:172-186`) y **el buscador se cuelga como submenú suyo** en `admin_menu` prioridad 20 (`SearchAdminSection.php:100`), decidiendo según vea `$admin_page_hooks['akibara-panel']` (`:118-135`). Si el padre no existe, se muda solo a un menú de primer nivel (`:137-145`). Es la única dependencia que el buscador tiene de business en el admin — y el docblock del propio método dice justo lo contrario de lo que hace el cuerpo: *«Menú top-level propio… Independiente del AdminPanel de akibara-business»* (`SearchAdminSection.php:104-107`).

### 13. El formulario de contacto, repartido en cuatro sitios

**Qué hace en una frase:** el ejemplo perfecto de costura cruzada — cuatro dueños para un solo formulario.

- El **shortcode** es de business (`Forms/ContactoForm.php:25`, con `locate_template()`).
- El **HTML** lo pone el tema (`template-parts/forms/contacto.php`), que además imprime el campo trampa antibots que genera business (`:13`).
- El **JavaScript** vive en el tema (`assets/js/akibara-forms.js`) pero **lo encola business** (`Forms.php:112-127`), calculando su versión con la función global del tema `akibara_gp_asset_ver()` a través de `ThemeAssetVersion::for_path()` (`ThemeAssetVersion.php:50-56`).
- El **endpoint** que recibe el envío es de business otra vez (`Forms/ContactoSubmit.php:38`), **a propósito sin nonce**, porque `/contacto/` se sirve desde caché y un nonce horneado llega caducado — medido en producción el 2026-08-23 (`Forms.php:126-141`).
- **Acoplamiento más ancho de lo necesario:** business le entrega al JS del tema la **base** del namespace, no una ruta: `'restUrl' => rest_url('akibara/v1/')` (`Forms.php:145`), y el JS compone `/wp-json/akibara/v1/{kind}` (`akibara-forms.js:5`). El contrato publicado es «cualquier cosa bajo `akibara/v1`».

### 14. El namespace REST `akibara/v1`, compartido por dos plugins

**Qué hace en una frase:** dos plugins que se publican por **rieles de despliegue distintos** —el *riel* es el guion de despliegue y el contrato sellado con que se publica cada plugin; dos piezas de rieles distintos no se pueden cambiar en el mismo despliegue— ponen rutas bajo el mismo espacio de nombres, y nada comprueba que no choquen.

| Ruta | Método | Quién la publica | ¿Pública? |
| --- | --- | --- | --- |
| `/search/suggest` | GET | buscador (`SuggestController.php:152`) | sí (`__return_true`) |
| `/search/popular` | GET | buscador (`SuggestController.php:171`) | sí — es la que sirve el desplegable cuando el campo está **vacío** |
| `/search/track` | POST | buscador (`SuggestController.php:181`) | sí |
| `/search/count` | GET | buscador (`FacetQueryIntegration.php:190`) | sí |
| `/search/admin-status` | GET | buscador (`Search.php:196-205`) | no — exige `manage_woocommerce` |
| `/back-in-stock` | POST | business (`BackInStock/CaptureRestController.php:68`) | sí |
| `/contacto` | POST | business (`Forms/ContactoSubmit.php:38`) | sí |
| `/series-follow` | POST | business (`SeriesFollow/CollectionToggle.php:47`) | sí |

`register_rest_route` no avisa de las colisiones: la segunda pisa a la primera, en silencio.

**Lo que sí está bien resuelto, y conviene no volver a sospecharlo.** Da miedo que el mu-plugin de barandas de
LiteSpeed no nombre estas rutas en su lista de exclusiones de caché: serían los endpoints públicos de más tráfico del
proyecto, y cachear la respuesta de un cliente para servírsela a otro sería grave. **No ocurre, y no puede ocurrir por
esta vía.** Toda respuesta del buscador pasa por `Support/RestCache::no_store()` (`RestCache.php:105-123`), que hace
tres cosas a la vez:

1. pone `Cache-Control: no-store, max-age=0` — que frena al navegador y a LiteSpeed;
2. emite `Cloudflare-CDN-Cache-Control: no-store` **y** `CDN-Cache-Control: no-store` — el par que el borde sí obedece, medido el 2026-08-23 después de comprobar que sólo con el primero Cloudflare seguía almacenando (`cf-cache-status: HIT`, `age: 3`);
3. dispara `do_action('litespeed_control_set_nocache', …)`, que es el control **por petición** del propio LiteSpeed, más fuerte que cualquier lista de direcciones.

`apply()` es literalmente un alias de `no_store()` (`RestCache.php:95-96`), así que no queda ningún camino de éxito que
se salte el control: lo aplican `/suggest` y `/popular` (`SuggestController.php:206`, `:218`, `:410`, `:433`, `:459`,
`:472`), `/track` (`:591`) y `/count` (`FacetQueryIntegration.php:224`, `:252`, `:960`). La única ruta que usa una
cabecera pelada es `/search/admin-status`, y está detrás de `manage_woocommerce` (`Search.php:229`). Segunda sospecha
descartada del mismo modo: el Centro de Mando comprueba con `SHOW TABLES LIKE` si existen las tablas ajenas sin escapar
el guion bajo, pero compara el resultado con el nombre exacto en la misma expresión (`AdminDashboard.php:762-764` y
`:774-776`), así que un acierto espurio se descarta solo. Es una inconsistencia de estilo, no un defecto.

### 15. Los dos limitadores de peticiones

**Qué hace en una frase:** dos copias deliberadas del mismo código, una en cada plugin, «para que el buscador no dependa de que akibara-business esté activo».

- **Ficheros:** `akibara-search/src/Support/RateLimit.php` · `akibara-business/src/Utilities/RateLimit.php`.
- **Lo que comparten sin querer:** el **mismo grupo de memoria** `akb_rl` (`Support/RateLimit.php:30` y `Utilities/RateLimit.php:57`) y el **mismo prefijo de clave** `akb_rlm_` (`:62` y `:111`). Los cubos son disjuntos hoy; nada lo garantiza mañana.
- **Lo que comparten a propósito:** el filtro `akibara_trusted_proxy_ranges`, que business siembra en prioridad 5 (`Plugin.php:811`) y el buscador aplica sobre su copia local (`Support/RateLimit.php:305`). `CF-Connecting-IP` sólo se cree si el vecino TCP es Cloudflare.

### 16. La cadena del correo

**Qué hace en una frase:** cuatro piezas vigilan la misma tubería en puntos distintos, y la última gana.

```
wp_mail()
  ├─ wp_mail_from / wp_mail_from_name (prio 15) ...... business MailFrom impone el remitente
  ├─ wp_mail (PHP_INT_MAX) ........... akibara-correo-no-sale-a-clientes.php: FUERA DE PRODUCCIÓN desvía al buzón de captura
  ├─ pre_wp_mail (PHP_INT_MAX) ....... akibara-correo-smtp.php: corta si las constantes SMTP no están completas
  ├─ phpmailer_init (PHP_INT_MAX) .... akibara-correo-smtp.php: aplica Hostinger y RE-FIJA el remitente
  └─ wp_mail_succeeded / wp_mail_failed  akibara-correo-smtp.php: enciende o apaga el aviso rojo del escritorio
        ▲
        └─ y por fuera: akibara-mail-transport-watch.php comprueba que ese mu-plugin siga cargado y lo repone
```

- **Las constantes, que es lo único con lo que se depura.** Todas se declaran en `wp-config.php`, ninguna vive en el código:

  | Constante | Para qué | Dónde se lee |
  | --- | --- | --- |
  | `AKIBARA_HOSTINGER_MAIL_ENABLED` | enciende el transporte propio; tiene que valer `true` exacto | `akibara-correo-smtp.php:406-407` |
  | `AKIBARA_HOSTINGER_SMTP_HOST` | servidor de salida | `:417` |
  | `AKIBARA_HOSTINGER_SMTP_PORT` | puerto | `:418` |
  | `AKIBARA_HOSTINGER_SMTP_ENCRYPTION` | cifrado | `:419` |
  | `AKIBARA_HOSTINGER_SMTP_USERNAME` | usuario; **sin valor por defecto** | `:420` |
  | `AKIBARA_HOSTINGER_SMTP_PASSWORD` | clave; **sin valor por defecto** | `:421` |
  | `AKIBARA_CORREO_CAPTURA` | buzón al que se desvía todo fuera de producción | `akibara-correo-no-sale-a-clientes.php:49-53` (también admite variable de entorno del mismo nombre) |

- **Lo que el guardarraíl de fuera de producción escribe, literal:** el asunto queda `[para <destinatario original>] <asunto original>` (`akibara-correo-no-sale-a-clientes.php:131`) y se añaden dos cabeceras: `X-Akibara-Destinatario-Original` y `X-Akibara-Entorno` (`:135-136`).
- **La perilla que no mueve nada:** `MailFrom` se engancha a `wp_mail_from` en prioridad 15 (`MailFrom.php:48-51`) y documenta dos filtros de override. El mu-plugin hace `setFrom('contacto@akibara.cl', 'Akibara', false)` y `$phpmailer->Sender = …` en `phpmailer_init` con `PHP_INT_MAX` (`akibara-correo-smtp.php:386-392`), sin consultar ninguno de los dos filtros. **Matiz honesto:** esto sólo muerde cuando el transporte SMTP propio está configurado y activo.
- **El `Reply-To` que sobrevive al guardarraíl:** fuera de producción el mu-plugin reescribe `to` y `subject` y añade las dos cabeceras `X-Akibara-*` (`:130-138`), pero **no toca `Reply-To`**, y el formulario de contacto sí inyecta ahí el correo real del cliente (`ContactoSubmit.php:235-243`). Quien haga pruebas en el espejo y pulse «Responder» le escribe a un cliente de verdad.
- **El aviso que nadie escucha:** `akibara_mail_transport_blocked` se emite (`akibara-correo-smtp.php:194`) y está documentado como «para que algo REACCIONE al corte». **No hay ni un `add_action` en todo el proyecto.**

### 17. Los 17 mu-plugins, y cuáles son costura

Los mu-plugins se cargan siempre y antes que todo, así que cualquiera de ellos puede alterar a los cuatro plugins sin
que nada lo declare. **Nueve tienen arista conocida con una pieza propia y se revisaron para este capítulo**; los otros
ocho se nombran aquí para que existan en el mapa, pero **no se auditaron**: lo que dice su columna es su propia
descripción, no una revisión.

> El *snapshot* tenía 18: falta `akibara-catalogo-abilities.php`, el barrido de disponibilidad, que el dueño
> retiró de local y de producción el 2026-09-13.

| mu-plugin | ¿Revisado aquí? | Con quién habla | Qué le hace |
| --- | --- | --- | --- |
| `akibara-as-retention.php:13-18` | sí | los **tres** plugins que encolan trabajo (business, buscador, rewards) | Fija la retención de Action Scheduler en 14 días con un filtro global: acorta la ventana de auditoría de cualquier incidente de los tres. |
| `akibara-https-urls-fuera-de-web.php:15-30` | sí | los correos de business y de rewards | Fuerza `https` en `set_url_scheme()` cuando no hay petición SSL — que es el caso de **todo** correo enviado desde cron, la cola o la consola. Sin él, las portadas salen en `http` y el cliente ve el aviso de contenido mixto. |
| `akibara-woo-blocks-i18n.php:27` y `:73` | sí | el plugin de bloques | Reescribe la traducción de `Please enter a valid %s`. Su razón de existir son **exactamente** los campos propios del checkout: rut, comuna, celular. Si alguien retira uno de esos campos, este mu-plugin queda huérfano. |
| `akibara-litespeed-guardrails.php:37-239` | sí | el tema y el plugin de bloques | Fija **en la lectura** `optm-css_comb`, `optm-js_comb`, `guest_optm`, `media-lazy` y `cache-mobile`, y nunca cachea `/wp-json/wc/store/` ni `/wp-json/wc/v3/`. |
| `akibara-brevo-portada-jpg-en-el-carrito.php:128`, `:131` y `:141` | sí | business y Brevo | Quiere usar `SocialImageDerivatives`. Ver el Caso 5: por eso se registra en `plugins_loaded:25`, después del autocargador de business. |
| `akibara-correo-smtp.php` | sí | todos: es el único transporte real del correo | Ver §16. |
| `akibara-correo-no-sale-a-clientes.php` | sí | todos los correos, fuera de producción | Ver §16. |
| `akibara-mail-transport-watch.php` | sí | el mu-plugin del correo | **El «centinela»**: vigila que el transporte SMTP siga instalado, lo repone si desaparece y deja constancia. No contiene credenciales. |
| `akibara-bluex-cotiza-solo-con-comuna.php:82` | sí | Blue Express y, de rebote, el checkout de bloques | Apaga los métodos de Blue que cotizan contra su API (`bluex-ex`, `bluex-py`, `bluex-md`) mientras el paquete no traiga región y comuna. Es «el mu-plugin que lo fuerza desde fuera» del Caso 2. |
| `akibara-abilities-denylist.php` | **no** | Rank Math **y WooCommerce** | Bloquea el registro de **tres** *abilities* peligrosas: dos de Rank Math (`set-plugin-preferences`, `set-module-status`) y `woocommerce/product-delete`, vetada por decisión del dueño del 2026-09-13 (corregido 2026-09-20, ronda 10). |
| `akibara-bluex-integrity-watch.php` | **no** | Blue Express | Tras cada actualización verifica que los `include` del árbol de Blue apunten a ficheros que existen, y avisa por correo. |
| `akibara-bluex-rest-tracking.php` | **no** | Blue Express y la API REST de WooCommerce | Compatibilidad del número de seguimiento con la API moderna. |
| `akibara-bluex-log-retention.php` | **no** (su cron sí está en *Lo programado*) | Blue Express | Poda los registros del plugin ajeno enganchándose encima de su propio cron. |
| `akibara-clarity-production-only.php:15-28` | sí (ver §18) | Microsoft Clarity | Fuera de producción quita los hooks del tag de Clarity. |
| `akibara-discovery-warming.php` | **no** | Cloudflare y los sitemaps | Declara una política de caché acotada para los sitemaps. |
| `akibara-provider-access.php:93` | **no** | business | Catálogo declarativo de claves de API; declara la option `akibara_google_maps_api_key`. |
| `akibara-rankmath-redirections-abilities.php` | **no** | Rank Math | Expone las redirecciones de Rank Math como *abilities* para operarlas por MCP. |

### 18. El bus de eventos de JavaScript

**Qué hace en una frase:** trozos de JavaScript de piezas distintas se avisan entre sí con eventos de nombre propio.

- `akb:pjax` — lo emite el buscador (`akb-search.js:200`) y lo escuchan el tema (`main.js:534`, `animations.js:887`) y business (`quick-view.js:301`, `series-bundles.js:1149`) para volver a engancharse al HTML nuevo tras un cambio de filtro.
- `akb:added_to_cart` / `akb:removed_from_cart` — el tema re-emite en vanilla los eventos jQuery de WooCommerce (`wc-event-bridge.js:49-62`) y los escucha la analítica de business (`clarity-commerce-events.js:491-499`). **(2026-09-26)** Desde el tema 2.1.143 quien abre el cajón del carrito al oír `akb:added_to_cart` es `animations-loader.js:189` (antes `animations.js`), sin depender de GSAP: llama a `window.akbCart.open()` de business.
- **(2026-09-26)** `akb:animaciones-listas` — lo emite `animations.js` al cargar (`:961`) y lo escucha `animations-loader.js:80` para pedir el vuelo al carrito; `akb:tarjeta-motivo` — lo emite `atc-guard.js` (`:387`) al pintar el motivo de un rechazo en una tarjeta, y `main.js` vuelve a mirar si el botón de WhatsApp tapa un botón de compra. Los dos son internos del tema.
- `.akb-cart-trigger` — contrato por **clase CSS**: el tema la pinta (`inc/woocommerce.php:341`, `footer/bottom-nav.php:90`) y la buscan el carrito nativo de business y el de bloques.
- `_buscar` — contrato de **cinco** superficies del tema: el campo está en `header/shell.php:88`, el pre-fill en `:50`, el cajón móvil lo lee y lo imprime (`mobile-drawer.php:28` y `:51`), el puente del blog construye URLs con él (`inc/blog.php:260`) y el JS lo trata como parámetro reservado (`main.js:508`). business lo lee para rotular «Relevancia» (`akibara-business.php:332-345`) y el buscador lo declara como `FilterSet::PARAM_SEARCH`.
- **Tres oyentes muertos:** business escucha `akb:applied_coupon`, `akb:applied_coupon_in_checkout` y `akb:checkout_error` (`clarity-commerce-events.js:500-508`). Un barrido sobre todo el proyecto devuelve **sólo esas tres líneas**: no hay ningún emisor. El puente del tema dejó de re-emitirlos a propósito y lo explica en su cabecera (`wc-event-bridge.js:22-32`). Consecuencia: **hoy nadie mide ni los cupones aplicados ni los errores del checkout**, que son los dos escalones que explican por qué un carrito no se convierte.
  - **Cuidado al intentar comprobarlo en el espejo.** `akibara-clarity-production-only.php:15-28` **quita el tag de Clarity fuera de producción** (`remove_action('wp_head', 'clarity_add_script_to_header')` y dos más, en `init` prioridad 1), así que en local no se mide **nada** de nada y es facilísimo concluir lo contrario de lo que pasa. Para ver si el evento existe sin depender de Clarity, se pone un espía en la consola del navegador antes de tocar el checkout: `window.clarity = (...a) => console.log('clarity', ...a);` y luego `['akb:applied_coupon','akb:applied_coupon_in_checkout','akb:checkout_error'].forEach(e => document.addEventListener(e, ev => console.log('EVENTO', e, ev)));`. Si al aplicar un cupón no aparece nada, el emisor no existe: es el hallazgo.

### 19. Las costuras del lote del 2026-09-26 (search 0.1.75 · business 1.117.0 · checkout 2.2.26 · rewards 0.4.18 · tema 2.1.143)

**Qué hace en una frase:** el lote de UX del tema repartió varias mejoras entre piezas —el dato en un plugin,
el pintado en el tema— y cada reparto es una costura nueva con su forma de fallar.

| Costura | Quién emite → quién recibe | Forma | Si falta el emisor | Si falta el receptor |
| --- | --- | --- | --- | --- |
| **Búsqueda sin resultados** | search (`frontend/facets-render.php`, dentro de `[data-akb-empty]`) → tema (`akibara_gp_search_empty_cross_sell()`, `inc/woocommerce.php:377-392`) | Acción `akibara_search_empty_after( $q, $motivo )`, `$motivo` ∈ `busqueda` / `filtros` / `serie` | No hay aviso ni gancho: nada que pintar | El aviso sale con título, pista y salidas del motor, sin «Lo más vendido» |
| **¿Se pinta «Lo más vendido»?** | tema pregunta → business responde (`Recommendations\Settings::mas_vendidos_en_busqueda()`) | Filtro `akibara_show_popular_in_empty_search`, de fábrica `true` | El tema usa `true`; sin más vendidos que pintar, la fila tampoco sale | — |
| **La rejilla de verdad** | search (`akb-search.js`, `mainGrid()`) ↔ tema (la `ul.products` de la venta cruzada dentro del aviso) | Contrato de DOM: la rejilla del catálogo es la primera `ul.products` **fuera** de `[data-akb-empty]` | — | Sin `mainGrid()`, el PJAX tomaba la venta cruzada del aviso por la rejilla y al pasar de 0 a N dejaba la tienda sin productos (medido en el espejo durante el lote: 0 tarjetas con el bundle viejo, 24 con el nuevo). El gancho y el arreglo llegaron juntos en 0.1.75 |
| **Atajo «Último tomo»** | business (`SeriesFacts::result_count_extra()`) → search (`akibara_search_result_count_html()`) | Filtro `akb_search_result_count_extra` (HTML, pasado por `wp_kses_post`) | La línea del total sale sola | El atajo no sale |
| **Qué fichero del tema se sirve** | tema (`akibara_gp_asset_servido()`) → business (`ThemeAssetVersion::served_path()`) | Función global con `function_exists` | business aplica la misma regla (`.min` salvo `SCRIPT_DEBUG`, fuente si falta) | — |
| **Llegada en la barra de compra** | tema (`sticky-atc.php`) pregunta → business responde (`EstimatedDelivery\BannerRenderer::linea_corta()`) | Filtro `akb_eta_linea_corta( '', $product )`; texto `akb_copy_eta_bar` con `{fecha}` (str_replace, nunca `sprintf`) | La línea no sale | — |
| **Tarjeta de la serie en una guía** | tema (`inc/guide-series.php`) pregunta → business (`RelatedGuides\GuideSeriesCard`) | Filtro `akb_guide_series_card( null, $post_id )` | Sin tarjeta y cierre a la tienda, como antes | — |
| **Motivo de un rechazo al agregar** | business (`NativeCart::ajax_get_cart()` + `RejectionReason`) → tema (`pdp-ajax-atc.js`, `atc-guard.js`) | Endpoint `?wc-ajax=akb_get_cart&drain_notices=1`, campo `motivo` = `ya_en_carrito` / `sin_stock` | La ficha recarga como último recurso; la tarjeta vuelve a WooCommerce | — |
| **Rótulo de la vista rápida** | business (`QuickView`, `data-akb-qv-label`) → tema (`loop.css`, `content: attr(data-akb-qv-label)`) | Atributo HTML | — | El icono sale sin rótulo visible; el nombre accesible sigue en el `aria-label` |
| **Avisos del formulario de contacto** | business (`Forms\Settings::MESSAGES`) → tema (`akibara-forms.js`) | `AkibaraFormsConfig.messages` (`wp_localize_script`) | El JS usa el `validationMessage` del navegador | — |
| **Textos del tema en el panel** | business (`CopyManager`, sección «Blog / Buscador», y `FRONT_READ_OPTIONS`) → tema (`akibara_gp_copy()`) | Options compartidas por nombre: `akb_copy_search_placeholder`, `akb_copy_search_empty_popular`, `akb_copy_guide_*` | El tema cae a sus literales de fábrica (los mismos que los de business: una prueba los compara) | La pantalla guarda textos que nadie lee |
| **Título de las reseñas** | rewards (`akb_rewards_carousel_title`, Puntos y Reseñas → «Título de las reseñas (portada y fichas)») → tema (`akibara_gp_reviews_title()`) | Option compartida por nombre; rewards 0.4.18 purga **el origen** (`litespeed_purge` `*`) al cambiarla | El tema cae a «Voces del Distrito» | — |
| **GSAP para el carrusel de reseñas** | tema (registra `gsap` y `gsap-scrolltrigger` en toda superficie con animaciones) → rewards (`Reviews\Carousel.php:237-246`, los pide como dependencia si están registrados) | Handles de `wp_register_script` | El carrusel no anima | — |
| **Aviso de puntos bajo el botón** | rewards (`PdpNotice`) ↔ business (`BannerRenderer::render_despacho()`) ↔ tema (sellos) | Prioridades de `woocommerce_after_add_to_cart_form`: despacho **5**, puntos **10**, sellos del tema **15**; en un agotado, respaldo de rewards en `woocommerce_single_product_summary:32` | — | — |
| **Capas del selector PUDO** | tema (`theme.json` → `custom.z`) → checkout (`pudo-blocks.css`) | Variables `--wp--custom--z--cart` / `--toast` con respaldo literal (10400 / 500) | — | El respaldo literal manda |

#### Orden de despliegue y acoples

El 2026-09-26 se desplegó **search → business → checkout → rewards → tema**, con
`ops/akibara-deploy-runtime.sh`, entre las 06:08 y las 06:11 UTC; paridad por pieza 61/61, 351/351, 31/31,
47/47 y 143/143 ficheros, claves y correo sanos en cada paso (asunto
`ops/coordinacion/abiertos/2026-09-22-tema-ux-rendimiento-mejoras.md`). Por qué ese orden:

1. **search antes que el tema** (lo pidió el lote B4). El gancho `akibara_search_empty_after` y `mainGrid()`
   viven en search 0.1.75, y el tema 2.1.143 da por hecho que la venta cruzada de «sin resultados» entra por
   ese gancho. Con el tema nuevo y el buscador viejo nada se rompe, pero la búsqueda sin resultados se queda
   sin salida comercial (el buscador retira la plantilla de WooCommerce que la traía). Con search nuevo y
   tema viejo tampoco pasa nada: el gancho no tiene oyente.
2. **business antes que el tema o en el mismo paso, nunca el tema primero** (QA-DIFF-TEMA-1). Con el tema
   nuevo y business 1.116.40, business seguía pintando la ficha de la serie **encima** de la rejilla mientras
   el tema ya había retirado la regla que lo compensaba (las dos primeras portadas de cada serie en móvil
   saldrían de entrada y con prioridad, bajo el pliegue); la vista previa del hero perdía Bebas (el tema
   la quitó de `fonts.css` y el panel viejo no imprime las caras de `theme.json`); y el rótulo de la vista
   rápida (`data-akb-qv-label`) saldría vacío. Al revés, business nuevo con el tema viejo degrada limpio —la
   fecha de la barra y el código de motivo no tienen quien los pida, y `quick-view.css` volvió a traer el
   aspecto de base del botón (QA-BIZ-DIFF-1)—, pero **los textos nuevos de Akibara → Textos** («Blog /
   Buscador», «Barra de compra: llegada», «Atajo al último tomo») se guardan con el aviso verde y no cambian
   nada hasta que llegan el tema y el buscador nuevos (QA-BIZ-DIFF-2, sin arreglo en código): por eso los
   cinco pasos van en la misma ventana. La vitrina «disponibles primero» (option
   `akb_catalog_order_showcase`, sin fila = de fábrica) se aplica sola: `akibara_business_upgraded` encola un
   recálculo inmediato, que en producción terminó a las **06:09:26 UTC con 1.643 filas aplicadas**
   (`akb_catalog_order_last_run`, leído en sólo lectura).
3. **El gate del tema declara los acoples.** `bash tools/akibara-releases/qa-theme.sh 2.1.143` prueba el ZIP
   publicado con las pruebas del árbol y extrae además los ZIP de checkout, search y business **en las
   versiones que declaran sus cabeceras**, porque las pruebas de acople (`cart-semantics`, `search-context`,
   `visual-polish`) los leen por ruta relativa. Su línea final nombra las versiones con que pasó.
4. **Después: purgar LiteSpeed y Cloudflare.** El HTML de invitados vive hasta 10 minutos en el borde. Un
   HTML de 2.1.142 servido con los assets nuevos pierde la apertura del cajón y la envoltura de la
   marquesina (TEMA-VENTANA-1), y una landing de serie de 24 tomos cacheada junto a otra de 48 salta o
   repite tomos. En producción LiteSpeed tiene `cdn-cloudflare=1` y `cdn-cloudflare_clear=1` (releído en
   sólo lectura el 2026-09-26), y con eso su purga total —`wp litespeed-purge all` del riel, o la acción
   `litespeed_purge_all` que lanza el recálculo de la vitrina al terminar (`CatalogOrder.php:846`)— llama a
   `Purge::_purge_all()`, que vacía también Cloudflare (`purge_everything`). Así lo leyeron las dos
   refutaciones de BIZ-DW-1 / PD-BIZ-CACHE-1 en el código de LiteSpeed 7.9.1 y lo documenta rewards 0.4.18
   (`SettingsPage::PROMESA_EN_CADA_FICHA`); **no se midió en el borde**. Lo que queda (BIZ-DW-2, baja) es la
   ventana entre el cambio de versión y el final del recálculo: el 2026-09-26, de 06:08 a 06:09:26 UTC.
5. **Verificar la superficie servida, no el árbol:** el HTML tiene que pedir los guiones del tema como
   `*.min.js?ver=2.1.143.*` (van siempre fuera del optimizador de LiteSpeed) y traer el guion en línea
   `akb-toque-temprano`; las hojas del tema se ven como `*.min.css?ver=2.1.143.*` en las páginas que no se
   cachean (carrito, checkout, Mi cuenta), porque en las cacheables las reescribe LiteSpeed a
   `/wp-content/litespeed/css/…`. El 2026-09-26 se comprobó en 11 páginas clave, con el suggest en 200, la
   cola del índice (`wp_akb_search_work`) en 1.648 filas con 0 en deuda y 1.643 documentos indexados en
   `wp_akb_search_docs`, 0 huérfanos (dos tablas distintas: no confundir las cifras).

**Qué se cruza con Cloudflare y no es código** (capítulo 21): la regla 9 de caché no cubre
`/wp-content/litespeed/` (los bundles de LiteSpeed salen con `no-store` y se bajan en cada página). Además, la
regla `qs-normalize` vacía la query antes de llegar al origen cuando la query empieza por uno de sus
parámetros de seguimiento y no lleva ninguno funcional; la condición exacta está en el capítulo 21. Un enlace
de campaña típico llega así. Por eso ninguna 301 de una dirección que llega sólo con esos parámetros puede
conservarlos en producción: ni la de `LandingPageSize`, que los conserva en el origen, ni la canónica de
WordPress cuando el enlace llega sin la barra final (`/tienda?utm_source=…` → `/tienda/`, medido el
2026-09-26). Si la query lleva un parámetro funcional, o empieza por otro parámetro, la regla no actúa y la 301
los conserva (`/tienda?utm_source=prueba&orderby=price` → `/tienda/?utm_source=prueba&orderby=price`).
La zona está en el plan Free: la salida que cabe en ese plan, lo que cubre cada paso (acotar a
`/serie/*/page/*` sólo recupera la primera) y lo que no sirve están en el capítulo 21,
`L26-cloudflare-qs-normalize`.

---

## Cómo comprobar y cómo levantar (la parte que se usa a las tres de la mañana)

**1 · ¿Quién está enganchado hoy al hook del precio?** Es la regla más importante del capítulo y la avería más cara.
Lectura pura, sin disparar ningún hook de admin:

```bash
wp eval '
$h = $GLOBALS["wp_filter"]["akb_search_effective_price"] ?? null;
if ( ! $h ) { echo "SIN OYENTES (ausencia válida: el buscador sirve con precio base)\n"; exit; }
foreach ( $h->callbacks as $prio => $rows ) {
  foreach ( $rows as $row ) {
    $f = $row["function"];
    $n = is_object( $f ) ? get_class( $f )
       : ( is_array( $f ) ? ( is_object( $f[0] ) ? get_class( $f[0] ) : $f[0] ) . "::" . $f[1] : (string) $f );
    printf( "prio=%d args=%d %s\n", $prio, $row["accepted_args"], $n );
  }
}'
```

Sano = **exactamente una línea**, `prio=10`, `args=2`,
`Akibara\Business\Modules\TaxonomyDiscount\SearchPriceProvider`. Dos líneas = buscador apagado.

**2 · El veredicto entero, ya calculado, por HTTP.** `GET /wp-json/akibara/v1/search/admin-status`
(`Search.php:196-205`). Exige la capacidad `manage_woocommerce`, así que hay que llamarla con la sesión de un
administrador.

**3 · Cuando el buscador ya está caído.** Existe un guion de rescate en el repo: `bash ops/rescate-buscador.sh
produccion`. **El orden importa y el guion lo respeta:** suelta la tabla sombra `_new` **primero** y retira la option
`akb_search_rebuild_state` **después**. Al revés, el primer arranque de WordPress acuña un estado bloqueado que ni el
panel ni la consola deshacen. Si el guion encuentra tablas `_old`, **se para**: ahí `_old` es el rollback, no residuo.

**4 · Un reconstruido pedido a mano no tumba nada.** Se construye en tablas sombra: medido en producción, 1.631
documentos en ~30 segundos con el autocompletar respondiendo 200 todo el rato. Lo caro no es el reconstruido, es el
camino que lo dispara (guardar un término desde el panel deja el autocompletar en 503 unos 6 minutos).

**5 · Qué hora tiene de verdad un trabajo programado.**

```bash
wp cron event list                                         # los de WP-Cron
wp action-scheduler list --hook=<gancho> --status=pending   # los de Action Scheduler
```

**6 · (2026-09-26) ¿El borde sirve el tema nuevo con sus copias `.min`?** Sólo GET, con la marca de las sondas:

```bash
curl -s -A AkibaraQA https://akibara.cl/tienda/  | grep -o 'akibara-gp/assets/js/[a-z-]*\.min\.js?ver=[0-9.]*' | sort -u
curl -s -A AkibaraQA https://akibara.cl/carrito/ | grep -o 'akibara-gp/assets/css/[a-z-]*\.min\.css?ver=[0-9.]*' | sort -u
curl -s -A AkibaraQA https://akibara.cl/tienda/  | grep -c 'id="akb-toque-temprano"'   # → 1
```

Medido el 2026-09-26 tras el despliegue: en `/tienda/`, `animations-loader`, `atc-guard`, `main` y
`wc-event-bridge` en `.min.js?ver=2.1.143.1790403032`; en `/carrito/`, `core`, `empty-states`, `loop` y
`woocommerce-carrito` en `.min.css` con la misma versión; el guion en línea, una vez. Las hojas de `/tienda/`
no salen así porque esa página se cachea y LiteSpeed las reescribe a `/wp-content/litespeed/css/…`. Si sale
una `?ver=` de la versión anterior, el borde todavía sirve HTML viejo: purgar Cloudflare (ver §19).

---

## Lo programado

Este capítulo no tiene trabajos programados **propios** — es una capa de costuras, no un módulo. Lo que sigue es la
lista de los trabajos de otros capítulos que **cruzan** de una pieza a otra: al correr, despiertan a un componente que
no es el suyo. La lista completa, con sus horas medidas en producción, vive en
[`16-todo-lo-programado.md`](16-todo-lo-programado.md).

**Sobre las horas: hay dos familias, y sólo una es de fiar.** Una *hora anclada* la recalcula el código cada vez y se
corrige sola. Una *hora heredada* es la que quedó del día en que se agendó por primera vez (`time() + un rato`) y
además **deriva**: Action Scheduler cuenta el intervalo desde que la acción **termina**, no desde su hora prevista, así
que una corrida que tarda dos minutos empuja la siguiente dos minutos. Medido en producción sobre el trabajo del orden
de catálogo antes de anclarlo: 05:00:00 → 05:10:56 en cinco días, unos **131 s/día** (`CatalogOrder.php:148-156`); y el
cambio de horario de Chile mueve la hora guardada **una hora entera de golpe**. Por eso las horas heredadas de esta
tabla son una foto, no un contrato: para saber la de hoy, los comandos del punto 5 de arriba.

| Trabajo | Mecanismo | Hora Chile | ¿Anclada o heredada? | Quién lo programa | Qué hace (y a quién despierta) | Qué pasa si corre dos veces |
| --- | --- | --- | --- | --- | --- | --- |
| `akb_preventa_vencida_sweep` | WP-Cron | diaria · ~02:37 | **heredada** (`wp_schedule_event(time(), 'daily')`, `PreventaVencidaCron.php:89`) | `PreventaVencidaCron::maybe_schedule()` en `init`, en toda petición (`:87-90`) | Guardado vacío de hasta 50 fichas de preventa ya vencida. **Despierta al buscador** (`save_post_product`) y purga LiteSpeed, sin mover `post_modified` | Inofensivo: el testigo `_akb_preventa_vencida_swept` saca de la consulta lo ya procesado, así que la segunda pasada toma las **siguientes** 50 |
| `akb_order_preventa_t7_reminder_scan` | WP-Cron | semanal · domingo 03:00 | **anclada** (`next_sunday_03_ts()`, `OrderPreventa/RemindersCron.php:92`) | `RemindersCron` | Recordatorio T-7 días de los pedidos con preventa; sólo con su interruptor encendido | Inofensivo: relee los mismos pedidos y respeta sus marcas |
| `akb_search_pump_live` | Action Scheduler (asíncrona) | al vuelo, cuando alguien ensucia un producto | — | los 25 oyentes del `Indexer`, el vigilante y la publicación editorial | Indexa lo que business o el mu-plugin del catálogo acaban de guardar | Inofensivo: es idempotente por documento; el coste es trabajo repetido |
| `akb_search_rebuild_watchdog` | Action Scheduler | recurrente · cada 15 minutos | — (intervalo, no hora) | `RebuildJob::ensure_watchdog()` en `init:21` | Vigila que una reconstrucción no quede colgada y auto-recupera el servicio | Inofensivo por diseño: es precisamente el trabajo que arregla estados a medias |
| `akb_search_reconcile` | Action Scheduler | diaria · ~12:50 | **heredada y derivante** (`time() + HOUR_IN_SECONDS`, `Reconciler.php:115`) | `Reconciler::ensure_scheduled()` en `init:20`, sólo con la indexación encendida | Compara el índice con el catálogo real; vuelve a pedirle el precio a business | Inofensivo: compara y corrige, no acumula |
| `akibara_catalog_order_daily` | Action Scheduler | diaria · 05:00 | **anclada, y se re-ancla sola** (`CatalogOrder.php:143-163`) | `CatalogOrder::maybe_schedule_daily()` en `init:20` | Recalcula el orden del catálogo, que es la rejilla que el buscador también pinta | Inofensivo: recalcula desde cero |
| `akb_sf_digest_run` | Action Scheduler | cadena de eventos únicos; se ve como diaria · ~06:30 | **heredada** (cada eslabón agenda el siguiente con `time() + retraso`, `DigestRunner.php:809-814`) | `DigestRunner::schedule_digest()` | Manda los avisos de tomo nuevo que **el checkout de bloques promete** encima del botón de pagar | **Sí importa:** hay topes por cliente y por semana; dos corridas solapadas podrían gastar cuota de más |
| `akibara_business_social_image_derivatives` | WP-Cron | diaria · ~19:05 | **heredada** (`wp_schedule_event(time() + 2h, 'daily')`, `SocialImageDerivatives.php:90`) | `SocialImageDerivatives::register()` | Genera las portadas en JPG que el mu-plugin de Brevo quiere servir en el correo de carrito abandonado | Inofensivo: sólo genera las que faltan |
| `akb_rewards_birthday_daily` | Action Scheduler | diaria · ~16:10 | **heredada** (`time() + HOUR_IN_SECONDS`, `Points/Birthday.php:72`) | `ensure_scheduled()` en `init:20`, memoizado 12 h | Puntos de cumpleaños; manda correo por la cola | **Sí importa:** acredita puntos; lleva candado por usuario y día |
| `akb_rewards_expiry_daily` | Action Scheduler | diaria · ~17:15 | **heredada** (`time() + 2 * HOUR_IN_SECONDS`, `Points/Expiry.php:93`) | `ensure_scheduled()` en `init:20`, memoizado 12 h | Caducidad de puntos; manda correo por la cola | **Sí importa:** retira puntos; lleva candado por usuario y día |
| `akibara_bluex_log_retention_cleanup` | Action Scheduler (+ cron del tercero) | diaria · ~21:55 | **heredada** (`time() + HOUR_IN_SECONDS`, `akibara-bluex-log-retention.php:112-119`) | `action_scheduler_init:20` del mu-plugin | Poda los registros de Blue Express enganchándose **encima** del cron del propio plugin ajeno | Inofensivo: es un `DELETE` por antigüedad |

[**Diagrama · Cron transversal en hora de Chile**](diagramas/19-como-se-hablan-los-plugins-cron.mmd) — las mismas
horas, con la marca `↩` en las heredadas.

---

## Casos de uso

### Caso 1 · Un cliente busca «naruto» desde la lupa de la cabecera

[**Diagrama · Búsqueda cruzando cuatro piezas**](diagramas/19-como-se-hablan-los-plugins-caso-busqueda.mmd)

1. El campo lo pinta el **tema** (`template-parts/header/shell.php:64-101`), que antes pregunta con `function_exists` si el autocompletar está activo.
2. Esa función devuelve `true` sólo si el interruptor `akb_search_suggest` está en `yes` **y** `EditorialDiscountPublication::serving_ready()` es `true` (`Search.php:322-331`).
3. El navegador pide `GET /wp-json/akibara/v1/search/suggest?q=naruto`. **Con el campo vacío la ruta que responde es otra:** `/wp-json/akibara/v1/search/popular` (`SuggestController.php:171`), que devuelve historial y populares.
4. El endpoint limita por IP y minuto y confía en `CF-Connecting-IP` sólo si el vecino TCP es Cloudflare — **con la lista que siembra business** (`Plugin.php:811`).
5. Por cada tarjeta, el buscador resuelve la insignia preguntándole a business por dos hooks, y el precio ya viene sellado en el índice.
6. El cliente pulsa Enter → `/tienda/?_buscar=naruto`. Ahí el buscador toma el control del listado y pinta la barra lateral **dentro de los hooks de las plantillas del tema**.
7. business, sin saber nada del buscador, cambia la etiqueta del selector de orden a «Relevancia» sólo porque ve `_buscar` en la URL (`akibara-business.php:332-345`).
8. Si el cliente mueve un filtro, el JavaScript intercambia los resultados sin recargar y emite `akb:pjax`, que escuchan el tema y business para volver a engancharse al HTML nuevo.

**Bifurcaciones.** Sin `serving_ready()` no hay desplegable ni filtros, y **no sale ningún aviso**. Sin business, todo lo reservable se rotula «A pedido» y el índice queda con el precio base, pero el buscador sigue en pie. Pasada la cuota, el endpoint responde 429 y el desplegable se queda vacío, también sin decir nada. Con `akb_search_facets` en `no`, la rejilla va ordenada por «Destacados» mientras el selector insiste en decir «Relevancia».

**Correos:** ninguno.

### Caso 2 · Un cliente compra: del carrito al correo de puntos y a la invitación a reseñar

[**Diagrama · Del carrito a los correos**](diagramas/19-como-se-hablan-los-plugins-caso-compra.mmd)

1. El cliente pulsa el carrito. El botón lo pinta el tema con la clase `.akb-cart-trigger`, y quien la busca es el JavaScript del plugin de bloques: **la clase CSS es todo el contrato**.
2. El plugin de bloques le mete al checkout los datos de Akibara pidiéndoselos a business por el contrato `CheckoutDataProvider` (`Plugin.php:30-42`).
3. El campo **RUT** lo registra el plugin de bloques; **la validación la hace business** (`CheckoutFields.php:315-316`, `:332`, `:356`, `:382`, `:410-414`).
4. **La comuna la canonicaliza el propio plugin de bloques, no business.** `CheckoutFields` recibe un `ComunaDirectory` (`CheckoutFields.php:42-44`), engancha `woocommerce_store_api_cart_update_customer_from_request` y `…checkout_update_order_from_request` (`:64-65`) y reescribe la comuna a su grafía canónica en `canonicalize_address_comuna()` (`:223-240`). El catálogo de comunas sale de **Blue Express** (`ComunaDirectory.php:26` y `:377-394`): business no participa en este paso. Y como Blue Express no cotiza sin región y comuna, un mu-plugin (`akibara-bluex-cotiza-solo-con-comuna.php:82`) apaga desde fuera sus métodos de cotización mientras falten.
5. Los avisos de envío y de colección salen de dos options que el dueño edita **en el panel de business**; el de colección sólo se pinta si el interruptor maestro `akb_sf_enabled` está encendido **y** el cliente no apagó los avisos de su colección (`Assets.php:258-262` y siguientes) — y, obviamente, si el texto guardado no está vacío (`CheckoutBlockIntegration.php:159-175`).
6. Si elige retiro PUDO, el plugin de bloques usa el servicio que le presta business (`Plugin.php:62-64`).
7. Al pagar, `woocommerce_order_status_changed` despierta, en este orden: **prio 10** `ShippableStamp` (business) y `Redemption` (rewards); **prio 20** `CarrierHandoffMonitor` y `ReadyEmailDispatcher` (business) y `Accrual` e `Invitations` (rewards).
8. rewards acredita los puntos y emite `akb_rewards_after_order_award`, que dispara el bono de bienvenida, los referidos y el correo.
9. `Invitations` **le pregunta a business** si el pedido se puede dar por cumplido. Si tiene preventa sin cumplir, no agenda nada. Si deja, agenda una acción única con el retraso configurado en la option `akb_rewards_invite_delay_days` (**por defecto 3 días**, `Invitations.php:69-71`), y **al dispararse vuelve a comprobar lo mismo**. El enlace de reseña que viaja en ese correo caduca según `akb_rewards_invite_token_days` (**por defecto 30 días**, `:78-80`), y el estado del pedido que dispara todo lo decide `akb_rewards_invite_status` (**por defecto `completed`**, `:60`).

**Correos que salen, con su momento:**

| Asunto | Quién lo manda | Cuándo |
| --- | --- | --- |
| «Pedido recibido» (plantilla de WooCommerce) | WooCommerce | inmediato |
| Correo de puntos acreditados | `Rewards\Points\Emails::send_order_email` | con la cola, 2-3 minutos |
| Invitación a reseñar | `Rewards\Reviews\Invitations::send_invite` | con la cola, tras `akb_rewards_invite_delay_days` (3 por defecto) |

**Bifurcaciones.** Sin business, el checkout pierde todo lo chileno (RUT, celular, avisos, autocompletar de direcciones, PUDO) pero no falla — y rewards **invita a reseñar siempre**. Sin el catálogo de Blue Express, el desplegable de comunas desaparece y queda el campo de texto libre. Sin el plugin de bloques, el checkout vuelve al genérico y `/carrito/` se queda sin `<h1>`. Fuera de producción, los tres correos se desvían al buzón de captura… conservando el `Reply-To` real del cliente.

### Caso 3 · El dueño guarda un porcentaje de descuento de editorial

[**Diagrama · El protocolo de la foto sellada**](diagramas/19-como-se-hablan-los-plugins-caso-descuento.mmd)

1. El panel guarda el termmeta y emite `akibara_taxonomy_discount_pct_saved` (`AdminFieldType.php:142` y `:147`).
2. Lo escuchan **tres sitios a la vez**: el purgador de LiteSpeed de business (`LscPurger.php:62`), el propio módulo de descuentos de business (`TaxonomyDiscount.php:152`) y **el `Indexer` del buscador** (`Indexer.php:182`).
3. El buscador marca deuda para los productos de esa editorial y encola trabajo.
4. En cada documento saca su foto, se la entrega a business, recibe un token y **verifica que business siga ligado a esa misma foto**.
5. Por cada producto pide el precio; business lo devuelve **o lanza**.
6. El precio queda sellado en `akb_search_docs.price_effective` y LiteSpeed purga el HTML afectado.

**Bifurcaciones.** Si el proveedor está mal enganchado —o si hay dos—, el buscador **se apaga**. Si business lanza, esos productos **no entran** al índice con precio base: se quedan fuera, a propósito. Y hay una segunda puerta: **el filtro que business ofrece al dueño como «la palanca sin código para añadir una taxonomía»** (`TaxonomyDiscount.php:19-23`) lo aplica también el buscador **dentro de su lectura sellada** (`EditorialDiscountPublication.php:2955`), y devuelve `null` si el callback lanza, si no devuelve un array o si mete una clave vacía o que no sea texto. Ese `null` apaga el buscador entero. El mismo patrón existe para `akibara_taxonomy_discount_max_pct` (`EditorialDiscountPublication.php:2669`).

**Correos:** ninguno. **Nota operativa:** guardar cualquier término dispara un reconstruido; el propio reconstruido pedido a mano no tumba nada, pero el camino que lo dispara sí puede. Si queda atascado, `bash ops/rescate-buscador.sh produccion`.

### Caso 4 · El dueño marca un tomo como preventa, y la fecha pasa

1. Se guardan `_akb_eta_type` y `_akb_eta_release_date`; el panel emite `akb_eta_release_date_changed`.
2. El guardado dispara el reindexado del buscador y las invalidaciones de caché de business.
3. Al reindexar, el buscador pregunta por `akb_search_availability_value` y business contesta `'preventa'`.
4. Pasa la fecha: en la **ficha** la degradación es automática al leer, desde el día siguiente (`Availability.php:280-282`).
5. En el **índice** y en el **HTML cacheado** sigue diciendo «Preventa» hasta que el trabajo diario `akb_preventa_vencida_sweep` toca hasta 50 fichas vencidas.
6. Ese toque dispara otra vez el reindexado y la purga, y escribe `_akb_preventa_vencida_swept` para que el barrido **avance** y no repita las mismas 50 fichas para siempre (`PreventaVencidaCron.php:46-61`).

**Bifurcación incómoda:** la palabra «Preventa» está escrita a mano en el buscador (`StockLabels.php:175-177`), mientras la rama hermana sí pasa por el filtro de vocabulario. El docblock del mismo fichero sella el contrato contrario. Si el dueño quisiera llamarle «Reserva», tendría que tocar código en **dos plugins de rieles distintos** —dos guiones de despliegue que no se publican juntos—, que es exactamente por qué un cambio de una palabra sale caro aquí.

**Correos:** ninguno directo. Sí los del aviso de tomo nuevo, que viajan por su propio trabajo programado.

### Caso 5 · El arreglo que estuvo un mes escrito sin ejecutarse nunca

Historia de una costura que falló por relojería, no por lógica. Está corregida desde el 2026-09-12 y se queda aquí porque el modo de fallo reaparece cada vez que una pieza depende de otra dentro del mismo arranque.

1. Un cliente deja el carrito abandonado. **Brevo** —un tercero— arma el correo de recuperación con las portadas de los tomos.
2. **Las 12.644 portadas de la tienda existen sólo en formato AVIF**, que Gmail no pinta: el correo llegaba con el hueco. Por eso existe un mu-plugin que abre una «ventana» durante el armado de ese correo para servir la versión **JPG** que ya genera business (`akibara-brevo-portada-jpg-en-el-carrito.php:3-4` y `:8-20`). La sustitución la hace un filtro sobre `wp_get_attachment_image_src` que sólo casa `\.avif$` (`:67` y `:75`). **No es WebP: quien vaya a arreglarlo probando con un `.webp` no va a reproducir nada.**
3. La «ventana» son dos `add_filter` alrededor de los tres hooks del carrito de Brevo: abrir en prioridad 9 y cerrar en la 11 (`:128-129`).
4. El mu-plugin sólo abre esa ventana si existen **dos** clases: primero la del plugin de Brevo, `\SendinblueWoocommerce\Managers\CartEventsManagers` (`:128`), y después la de business, `\Akibara\Business\Modules\SeoSocial\SocialImageDerivatives` (`:131`).
5. business **registra su autocargador dentro** de su propio arranque (`akibara-business.php:138`), colgado de `plugins_loaded` **prioridad 20** (`:159`). El mu-plugin nació apuntado a esa misma prioridad 20.
6. WordPress carga los mu-plugins **antes** que los plugins, así que en el empate de la prioridad 20 ganaba el mu-plugin. Cuando corría, el autocargador de `Akibara\Business` **todavía no existía**, el segundo `class_exists()` devolvía `false` y la función se iba sin enganchar nada.

**Cómo terminó:** un mes de correos con el hueco y nadie sospechando del fichero —existe, está bien escrito, y su propio comentario aseguraba que «a esa altura los dos `class_exists` responden la verdad»—. Lo cazó QA el 2026-09-12 volcando `$wp_filter['plugins_loaded']`. El arreglo fue una línea: el registro pasó a `plugins_loaded` **prioridad 25** (`:141`). Medido en producción el 2026-09-15, los tres ganchos del carrito llevan la ventana en 9 y 11 alrededor del oyente de Brevo en la 10. La prueba `test_el_registro_espera_a_plugins_loaded_tarde()` fija el **par** de prioridades: mover una sin la otra sale en rojo.

**Correos afectados:** el de carrito abandonado de Brevo, que sale por fuera de la tienda.

### Caso 6 · Un correo sale de la tienda

1. Quien sea —WooCommerce, business o rewards— llama a `wp_mail()`.
2. `MailFrom` de business impone el remitente en prioridad 15.
3. **Fuera de producción**, `akibara-correo-no-sale-a-clientes.php` sustituye el destinatario por el buzón de captura (constante `AKIBARA_CORREO_CAPTURA`) y mete el original entre corchetes al principio del asunto —`[para <correo>] …`, `:131`— y en dos cabeceras: `X-Akibara-Destinatario-Original` y `X-Akibara-Entorno` (`:135-136`).
4. Si las constantes SMTP no están completas (`AKIBARA_HOSTINGER_MAIL_ENABLED` y las cinco `AKIBARA_HOSTINGER_SMTP_*`, `akibara-correo-smtp.php:406-421`), `akibara-correo-smtp.php` corta en `pre_wp_mail` y emite `akibara_mail_transport_blocked` — que **nadie escucha**.
5. El transporte propio configura PHPMailer en `phpmailer_init` con `PHP_INT_MAX` y **vuelve a fijar** el remitente, ignorando los dos filtros que business documenta como perillas.
6. Un solo rechazo del SMTP se anota pero no grita; **a partir de tres seguidos** aparece el aviso rojo del escritorio, que no se puede descartar y sólo desaparece cuando un correo vuelve a salir de verdad.
7. Si el correo lo generó un trabajo programado o la consola, las imágenes salen en `https` gracias a `akibara-https-urls-fuera-de-web.php`. Sin él, el cliente ve el aviso de contenido mixto.
8. Y por fuera de toda la cadena, `akibara-mail-transport-watch.php` comprueba que el fichero del transporte siga cargado y lo repone si desaparece — porque ya desapareció una vez, y la tienda estuvo tres días sin mandar ni un correo.

**Correos:** todos los de la tienda pasan por aquí.

---

## Reglas de negocio

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
| --- | --- | --- | --- |
| El buscador sólo sirve autocompletar y filtros si la lectura de la configuración editorial es autoritativa **en esta petición** | `search/src/EditorialDiscountPublication.php:421-428`; `search/src/Search.php:322-331`, `:347-356` | No | — |
| El proveedor de precio debe ser el **único** oyente de `akb_search_effective_price`, en prioridad 10, con 2 argumentos y la clase exacta. **Cero oyentes es válido** (el buscador sirve con precio base); dos o más lo apagan | `search/src/EditorialDiscountPublication.php:3020-3031`, `:3074-3082` | No | — |
| Si el descuento no se puede determinar con autoridad, business **lanza** en vez de devolver el precio base | `business/…/SearchPriceProvider.php:121-123`, `:337` | No | — |
| Un filtro de taxonomías o de tope que lance, o devuelva algo que no sea un array bien formado, **apaga la lectura** | `search/src/EditorialDiscountPublication.php:2955-2971`, `:2669`; `business/…/AuthoritativeReader.php:771-829` | Sí, y ahí está el riesgo | filtros `akibara_taxonomy_discount_taxonomies` y `akibara_taxonomy_discount_max_pct`, escribiendo un `add_filter` en un mu-plugin |
| `onbackorder` es «A pedido» por defecto; sólo business lo convierte en «Preventa» | `search/src/StockLabels.php:65-75`, `:173`; `business/…/SearchAvailabilityBridge.php:78-83` | Sí | panel «Disponibilidad» de la ficha del producto |
| El override del vocabulario de stock sólo funciona en **prioridad 11 o mayor**: en la 10 lo pisa el puente de business | `business/…/SearchAvailabilityBridge.php:44` y `:58-61` | No documentado | — |
| «Preventa» está escrita a mano y **no** pasa por el filtro de vocabulario | `search/src/StockLabels.php:175-177` | No | — |
| El día del lanzamiento todavía es preventa; degrada al día siguiente | `business/…/Availability.php:280-282` | No | — |
| El barrido de preventas vencidas toca **50 fichas por día** y no mueve `post_modified` | `business/…/PreventaVencidaCron.php:45`, `:171-194` | No | — |
| El plugin de bloques no registra **nada** si business no expone un `CheckoutDataProvider` con los seis métodos | `blocks/src/Plugin.php:30-42`; `business/src/Contracts/CheckoutDataProvider.php:74`, `:90`, `:100`, `:110`, `:120`, `:129` | No | — |
| Sin `pudo_checkout()` sólo se pierde el retiro PUDO; el resto del checkout se registra igual | `blocks/src/Plugin.php:47-49`, `:62-64` | No | — |
| El catálogo de comunas del checkout es el de Blue Express, leído por ruta de fichero; si falta, el campo vuelve a texto libre | `blocks/src/ComunaDirectory.php:26`, `:120-122`, `:377-394`; `blocks/src/Assets.php:339` | No | — |
| El aviso de colección del checkout exige interruptor maestro encendido **y** que el cliente no haya apagado sus avisos | `blocks/src/Assets.php:258-262` y siguientes; `blocks/src/CheckoutBlockIntegration.php:159` | Sí | dos pestañas distintas del panel Akibara |
| El tema suprime su `<h1>` en `carrito` y `checkout` de forma **incondicional**, contando con un shortcode que sólo existe en el contenido de la página. La razón escrita en el tema (*«cart-empty.php renderea H1 propio»*) es falsa: esa plantilla no tiene ninguno | `themes/akibara-gp/inc/page-header.php:47-58` (comentario en `:50`); `blocks/src/Shortcodes.php:38-43`; contraste en `assets/css/woocommerce.css:3023` | Por slug, no por interruptor | — |
| No hay ningún shortcode de título para el checkout: el único que existe dice literalmente «Tu carrito» | `blocks/src/Shortcodes.php:27-28`, `:38-43` | No | — |
| El logo de la landing de cada editorial lo resuelve una función del **tema**, no un campo del panel | `themes/akibara-gp/inc/woocommerce.php:169-175`; `business/…/BrandFamilyLanding.php:244-245` | Sí, por fichero | subir `assets/img/editoriales/<slug>.svg` al tema, o fijar el `thumbnail_id` del término |
| rewards no arranca mientras el plugin ajeno SPAR siga activo | `akibara-rewards.php:127-140` | No | — |
| No se invita a reseñar un pedido cuya preventa business no da por cumplida; **sin business, se invita igual** | `rewards/…/Invitations.php:131-134`, `:174-178` | Sí (retraso, caducidad y estado disparador) | `admin.php?page=akb-rewards-settings`; options `akb_rewards_invite_delay_days` (3), `akb_rewards_invite_token_days` (30), `akb_rewards_invite_status` (`completed`) |
| El buscador cuelga su pantalla como submenú del panel de business, con caída a menú propio si el padre no existe | `search/src/SearchAdminSection.php:100`, `:118-145` | No | — |
| Todo correo sale como `Akibara <contacto@akibara.cl>`, ignorando lo que pida quien envía | `business/…/MailFrom.php:44-51`; `mu/akibara-correo-smtp.php:386-392` | En teoría sí, en la práctica no | filtros `akibara_mail_from_email` / `_name`, que el mu-plugin pisa |
| Fuera de producción ningún correo llega a un destinatario real (pero el `Reply-To` sí es real) | `mu/akibara-correo-no-sale-a-clientes.php:104-142` | Sí | constante `AKIBARA_CORREO_CAPTURA` en `wp-config.php` (o variable de entorno del mismo nombre) |
| El transporte propio no envía nada si falta cualquiera de las seis constantes SMTP | `mu/akibara-correo-smtp.php:406-421` | Sí | `wp-config.php` |
| El aviso de correo caído: al **primer** corte de configuración, al **tercer** rechazo del SMTP | `mu/akibara-correo-smtp.php:45`, `:245-247` | No | — |
| Fuera de producción **no se mide nada** con Microsoft Clarity: el tag se quita entero | `mu/akibara-clarity-production-only.php:15-28` | No | — |
| `optm-css_comb`, `optm-js_comb`, `guest_optm`, `media-lazy` y `cache-mobile` están forzados a `false` aunque la casilla del panel diga otra cosa | `mu/akibara-litespeed-guardrails.php:37-99` | No | — |
| `/wp-json/wc/store/` y `/wp-json/wc/v3/` nunca se cachean | `mu/akibara-litespeed-guardrails.php:126-143` | Parcial | — |
| `CF-Connecting-IP` sólo se cree si el vecino TCP es Cloudflare; la lista la siembra business para los dos limitadores | `search/src/Support/RateLimit.php:305`; `business/src/Plugin.php:811` | Sí, por código | filtro `akibara_trusted_proxy_ranges` |
| Action Scheduler retiene 14 días en vez de 31, para los tres plugins que encolan trabajo | `mu/akibara-as-retention.php:13-18` | No | — |
| El contrato de alimentación del buscador vale `'2'`; subirlo agenda **una** reconstrucción completa con candado atómico | `business/src/Plugin.php:210`, `:707-763`; `search/src/EditorialDiscountPublication.php:37` | No | — |
| Las URLs canónicas admiten override por filtro en **seis** claves, y **nadie lo usa** | `business/src/Services/NavigationLinks.php:230`, `:242`, `:257`, `:273`, `:289`, `:297`, `:314-321` | Sí, escribiendo un `add_filter` en un mu-plugin (no hay pantalla) | filtros `akibara_navigation_url_home` · `_shop` · `_cart` · `_account` · `_contact` · `_wishlist` |

---

## Datos que deja

| Dato | Tipo | Quién escribe | Quién lee | Para qué |
| --- | --- | --- | --- | --- |
| `akibara_business_search_feed_contract_version` | option | business (`Plugin.php:210`, `:735`) | el buscador (`EditorialDiscountPublication.php:37`) | Versión del contrato de alimentación; el buscador exige que valga `'2'` |
| `akibara_taxonomy_discount_enabled` · `_from` · `_to` · `_apply_to` · `_sale_policy` | options | business, desde el panel (`TaxonomyDiscount/Settings.php`) | el buscador, en crudo (`EditorialDiscountPublication.php:90-98`) | Entran en la foto que los dos comparan byte a byte |
| `akb_discount_max_pct` | option | business | el buscador | Tope del porcentaje, también dentro de la foto |
| `akb_search_indexing` · `akb_search_suggest` · `akb_search_facets` | options | el panel del buscador (`Search.php:34`, `:37`, `:40`) | el buscador y, de rebote, el tema | Los **tres** interruptores. `akb_search_indexing` es el que decide si `akb_search_reconcile` está agendado y si el `Indexer` escucha; los otros dos son los de cara al cliente, y **no bastan por sí solos** (además exigen `serving_ready()`) |
| filtros `akb_search_indexing_enabled` · `akb_search_suggest_enabled` · `akb_search_facets_enabled` | filtros | nadie hoy | el buscador (`Search.php:313`, `:338`, `:363`) | Override por código de cada uno de los tres interruptores: la palanca invisible exacta de la que trata este capítulo |
| `akibara_blocks_shipping_hint_text` · `akibara_blocks_collection_notice_text` | options | business, pestaña Akibara → Venta → Carrito | el plugin de bloques, por nombre (`Assets.php:33`, `:60`) | Los dos avisos del checkout |
| `akb_sf_enabled` | option | business (`SeriesFollow.php`) | business con filtro (`:343`) y el plugin de bloques en crudo (`Assets.php:259`) | Interruptor maestro de los avisos de colección |
| `akb_rewards_invite_delay_days` · `_token_days` · `_status` | options | el panel de rewards | rewards (`Invitations.php:69-71`, `:78-80`, `:60`) | Retraso (3), caducidad del enlace (30) y estado que dispara la invitación (`completed`) |
| `akb_blocks_mp_dequeue_observed` | option | el plugin de bloques (`RuntimeCompatibility.php:37`) | el Centro de Mando de business (`AdminDashboard.php:790`) | Contrato de observación entre plugins; el único dato que el hub lee del checkout |
| `akb_copy_promise_installments` · `akb_copy_footer_support_hours` · `akb_copy_footer_phone` | options | business (`CopyManager`) | **el tema**, por nombre (`inc/site-copy.php:47`, `:55`, `:69`) | Textos del pie y de la promesa de cuotas |
| `akb_copy_order_relevance` · `akb_copy_order_featured` | options | business | business (`akibara-business.php:343-345`) | Etiquetas del selector de orden |
| `akibara_google_maps_api_key` | option | declarada por `mu/akibara-provider-access.php:93` | business (`AddressAutocomplete.php:43-47`) | Clave del autocompletar de direcciones; declaración duplicada, sin llamada entre las dos piezas |
| `{prefijo}akb_search_docs` | tabla | el buscador | el buscador y, con SQL crudo, el Centro de Mando (`AdminDashboard.php:760-764`) | El índice; su columna `price_effective` guarda el precio sellado |
| `{prefijo}akb_rewards_ledger` | tabla | rewards | rewards y, con SQL crudo, el Centro de Mando (`AdminDashboard.php:772-781`) | Libro mayor de puntos; el hub suma `delta` para el saldo vivo |
| `_akb_eta_type` · `_akb_eta_release_date` | postmeta | business, desde la ficha del producto | business (`Availability`) y, de rebote, el buscador | Tipo de disponibilidad y fecha de salida |
| `_akb_preventa_vencida_swept` | postmeta | `PreventaVencidaCron` (`:60`) | el mismo cron | Testigo que hace **avanzar** el barrido en vez de repetir las mismas 50 fichas |
| usermeta de baja global de la colección | usermeta | business (`FollowRegistry::META_OPTOUT_ALL`) | business y el plugin de bloques, **copiada por nombre** | Si el cliente apagó todos los avisos |
| grupo de memoria `akb_rl`, claves `akb_rlm_…` | caché de objetos | los **dos** limitadores | los dos limitadores | Contadores por IP y minuto; cubos disjuntos hoy, nada lo garantiza |
| Namespace REST `akibara/v1` | espacio de nombres global | business y el buscador | el JavaScript del tema y el de los dos plugins | Ocho rutas de dos plugins de rieles distintos, sin árbitro |
| Fichero `bluex-for-woocommerce/includes/data/chile-communes.php` | fichero de un tercero | Blue Express | el plugin de bloques (`ComunaDirectory.php:26`, `:377-394`) | El catálogo de comunas del checkout: se lee por ruta, no por hook |
| Eventos `akb:pjax`, `akb:added_to_cart`, `akb:removed_from_cart` | eventos de JavaScript | el buscador y el tema | el tema, business | Re-enganchar comportamiento tras un cambio de HTML |
| Clase CSS `.akb-cart-trigger` | contrato de marcado | el tema | el carrito nativo de business y el de bloques | Abrir el carrito desde cualquier botón |
| Funciones globales `akibara_gp_asset_ver()` y `akibara_gp_brand_logo()` | funciones del tema | el tema (`inc/enqueue.php:37` y `inc/woocommerce.php:169`) | business (`ThemeAssetVersion.php:50-56`, `BrandFamilyLanding.php:244-245`) | La versión de los ficheros encolados y el logo de cada landing de editorial |
| Fichero `assets/img/editoriales/<slug>.svg` | fichero del tema | quien suba el logo | el tema, y de rebote business | Es el logo que se publica en `/ivrea/`, `/panini/` y el resto |
| Parámetro de URL `_buscar` | parámetro público | el tema (5 superficies) | business y el buscador | El texto de la búsqueda; renombrarlo exige barrer cinco sitios |
| Shortcode `[akibara_cart_blocks_title]` | contenido de una página | quien edite la página en el editor de WordPress | el plugin de bloques (`Shortcodes.php:27`) | El único `<h1>` de `/carrito/`. No vive en ningún fichero del repositorio |

---

## Glosario del capítulo

- **Hook** — un aviso con nombre que una pieza lanza y otras pueden escuchar. Es la manera educada de extender WordPress sin tocar el código ajeno.
- **`do_action` / `add_action`** — el par de «pasó esto» / «avísame cuando pase». No devuelve valor.
- **`apply_filters` / `add_filter`** — el par de «tengo este valor, corríjanlo si saben más» / «yo lo corrijo». Sí devuelve valor.
- **Prioridad** — el número con que un oyente se apunta a un hook. Más bajo, corre antes. En un empate gana quien se apuntó antes; si uno es un mu-plugin, gana el mu-plugin; si son dos plugins, decide el orden de carga, que nadie fijó.
- **`plugins_loaded`** — el aviso que WordPress lanza cuando terminó de cargar todos los plugins. Es donde arrancan las cuatro piezas propias.
- **mu-plugin** — «must use»: un fichero que WordPress carga siempre, antes que cualquier plugin, y que no se puede desactivar desde el panel.
- **Autoloader** — el mecanismo que carga el fichero de una clase la primera vez que alguien la nombra. Si no está registrado todavía, `class_exists()` responde `false` aunque el fichero exista.
- **`class_exists()` / `function_exists()`** — preguntar «¿existe esto?» antes de usarlo. Es la diferencia entre degradar y caerse.
- **Interfaz (contrato)** — una lista de preguntas que una clase promete saber responder. `CheckoutDataProvider` es una: business la cumple, y el plugin de bloques comprueba que la cumpla antes de registrar nada.
- **Option** — una fila de configuración en la base de datos, identificada por un nombre de texto.
- **Postmeta / usermeta / termmeta** — datos sueltos colgados de un producto, de un usuario o de una categoría.
- **Transient** — una option con fecha de caducidad; se usa para guardar cálculos caros un rato.
- **Endpoint REST** — una dirección `https://akibara.cl/wp-json/…` que el navegador consulta para pedir o enviar datos sin recargar la página.
- **Namespace REST** — el prefijo común de un grupo de esas direcciones. Aquí, `akibara/v1`, compartido por dos plugins.
- **Store API** — el conjunto de endpoints con que WooCommerce sirve el carrito y el checkout de bloques. Dos plugins propios lo extienden a la vez.
- **Bloques** — la forma moderna de armar páginas en WordPress. El carrito y el checkout de Akibara están hechos así, y por dentro son React.
- **React** — la tecnología con que está escrito ese carrito: el navegador arma la pantalla con JavaScript, no el servidor.
- **Shortcode** — una etiqueta entre corchetes que se escribe en el contenido de una página y que un plugin sustituye por HTML. Como vive en el contenido y no en un fichero, no aparece en el código.
- **Rejilla** — la cuadrícula de tarjetas de producto de `/tienda/` y de cualquier listado.
- **Faceta** — cada uno de los filtros del listado: editorial, precio, disponibilidad, etc.
- **Chip** — la etiqueta con una ✕ que muestra un filtro ya aplicado y que sirve para quitarlo.
- **Banda de series** — la tira de series relacionadas que el buscador pinta encima de los resultados.
- **Barra lateral** — la columna de facetas a un lado de la rejilla. El tema abre el hueco; el buscador la imprime dentro.
- **Action Scheduler** — la cola de trabajos de WooCommerce. Guarda tareas en la base de datos y las va ejecutando; es lo que hace que un correo salga «con la cola» y no al instante.
- **WP-Cron** — el temporizador propio de WordPress. En esta tienda no lo empuja el tráfico: lo llama el hosting cada cinco minutos.
- **Hora anclada / hora heredada** — una hora anclada la recalcula el código y se corrige sola; una heredada es la que quedó del primer agendado y se mueve sola con cada corrida y con el cambio de horario.
- **Riel (de despliegue)** — el guion de despliegue y el contrato sellado con que se publica cada plugin. Dos piezas de rieles distintos no se pueden cambiar en el mismo despliegue: por eso un cambio que toca dos plugins cuesta el doble.
- **HPOS** — el modo en que WooCommerce guarda los pedidos en tablas propias en vez de mezclarlos con las entradas del blog.
- **Índice del buscador** — las tablas propias donde el buscador guarda una copia lista para consultar de cada producto, con su precio ya calculado.
- **Reconstrucción (rebuild)** — rehacer ese índice entero. Se construye en tablas sombra, así que un reconstruido pedido a mano no deja el buscador caído.
- **Foto sellada (snapshot)** — la copia congelada de la configuración de descuento que el buscador le entrega a business para que los dos confirmen que miran lo mismo.
- **Token** — la huella `sha256` de esa foto. Sirve para comprobar que nada cambió entre la pregunta y la respuesta.
- **`serving_ready()`** — la pregunta «¿puedo servir resultados sin mentir?» (`EditorialDiscountPublication.php:421-428`). Si responde `false`, el buscador se apaga solo y en silencio: ni autocompletar, ni filtros, ni aviso.
- **Degradar** — seguir funcionando con menos, en vez de romperse. Lo contrario de un fatal.
- **Fatal** — un error de PHP que corta la página. El visitante ve una pantalla en blanco o un error del servidor.
- **LiteSpeed Cache** — el plugin de caché del servidor: guarda el HTML ya generado para no rehacerlo en cada visita.
- **Cloudflare** — la red que está delante del sitio y que también guarda copias de las páginas.
- **Purgar** — tirar a la basura esas copias guardadas para que la siguiente visita reciba el HTML nuevo.
- **PUDO** — «pick up / drop off»: los puntos donde el cliente retira su pedido en vez de recibirlo en casa.
- **Comuna** — la división administrativa chilena que el despacho necesita. Blue Express sólo cotiza si la comuna está escrita exactamente como en su propio catálogo: de ahí `ComunaDirectory`.
- **Nonce** — un código de un solo uso que WordPress pone en los formularios. En una página cacheada llega caducado, por eso el formulario de contacto no lleva.
- **Honeypot** — un campo invisible que sólo rellenan los robots. Si viene con texto, el envío se descarta.
- **PJAX** — cambiar un trozo de la página sin recargarla entera. Cuando el buscador lo hace, avisa con el evento `akb:pjax`.
- **`$wp_filter`** — la tabla global donde WordPress guarda quién escucha cada hook. El buscador la inspecciona directamente para verificar que business está enganchado exactamente como debe.
