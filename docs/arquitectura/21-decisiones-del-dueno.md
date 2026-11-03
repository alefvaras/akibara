# Decisiones que le tocan al dueño

Aquí no hay defectos de código. Hay sitios donde el código hace algo a propósito, o donde la regla que decidiría qué es «correcto» **no está escrita en ninguna parte**. Esas no las decide quien programa.

## Ya respondidas (2026-09-13)

| pregunta | respuesta del dueño | estado |
| --- | --- | --- |
| Los seis webhooks «BrandAgent» de Clarity seguían mandando cada cambio de producto, carrito y checkout a Microsoft (359 envíos el 12-sep) con Akibara AI apagado desde el 6-ago | **Borrarlos** | **Hecho y medido en producción** el 13-sep 01:12: 0 webhooks, borradas las dos claves `brandagent_*`, tienda sana |
| WooCommerce publica por MCP borrar/crear/editar productos y cambiar estados de pedido, y nuestra lista de vetos sólo cubría dos acciones de Rank Math | **Vetar sólo borrar** | ✅ **RESUELTA.** Implementado y **desplegado**: `akibara-abilities-denylist.php` **1.2.0** lleva `'woocommerce/product-delete'` en la lista (`:37`), con la doble capa `permission_callback` → `false` (`:51`) y `meta.mcp.public` → `false` (`:65-66`). Verificado en producción el 2026-09-20 (copia local y copia de producción byte a byte idénticas). `product-create`, `product-update` y las tres de pedidos siguen fuera **por la misma decisión**. (corregido 2026-09-20, ronda 10: esta fila decía «pendiente de implementar») |
| Apagar «Dar puntos por compra» apagaba en silencio el bono de bienvenida, los referidos y su correo | **Interruptores independientes** | pendiente en akibara-rewards |
| La foto de la reseña es obligatoria y paga 500 puntos, pero sólo se ve en el carrusel de la portada | **Que se vea en la ficha y en la moderación** | pendiente en akibara-rewards |

## Decididas por delegación (2026-09-26) — «tómalas tú en pro de mejorar»

El 2026-09-26 delegaste las decisiones pendientes del lote: «las decisiones mías tómalas tú en pro de mejorar».
Se aplicó con tres reglas. En cada una se eligió la opción que mejora la experiencia o la venta. Cada una queda
**reversible**: con una casilla u opción del panel, o, si es un despliegue, con los paquetes que siguen en
`dist/`. Y cada una lleva, escrito **antes** de encenderla, qué se mide y con qué resultado se vuelve atrás. La
delegación no se salta las reglas del proyecto: nada llega a producción sin el visto bueno de QA, y lo que se
enciende se prueba encendido **y** apagado (regla 0 de `CLAUDE.md`). Tampoco cubre lo que no es de producto ni
de experiencia: tu marca, tus políticas comerciales y lo que sólo se hace desde tus cuentas (Cloudflare, el
servidor) o con tus imágenes (la del carrusel de la portada) siguen siendo tuyos.

**Estado al escribir esto:** las cinco de la tabla están decididas y **ninguna aplicada en producción**
(valores leídos allí el 2026-09-26). El seguimiento sale en dos tandas (`D26-1`): business 1.117.1 y tema
2.1.144 primero, search después. Las de panel (`D26-2`, `D26-4` y `D26-5`) viven en business y no dependen de
search: se encienden **después** de la primera tanda, cada una con su semana de medida anotada. Las siete que el lote dejó «a un clic» sin ser una casilla también
pasaron por la delegación: cinco quedan decididas y dos siguen siendo tuyas, cada una con su motivo (al final
de esta sección, «Las siete de «a un clic» que no son una casilla»).

| id | qué se decidió | ajuste que la controla (option) | cómo se vuelve atrás | se vuelve atrás si… |
| --- | --- | --- | --- | --- |
| `D26-1` | Desplegar el seguimiento, con `LeanCart` encendido: business 1.117.1 y tema 2.1.144 primero; search después, en una versión posterior a 0.1.75 | Despliegue; `akb_lean_cart` para lo de Mercado Pago | Casilla, o los paquetes que hoy están en producción, en orden inverso | Mercado Pago deja de cobrar, o QA ve una regresión |
| `D26-2` | Encender «Cargar Google Analytics y Clarity después de la carga» y medir 7 días | `akb_analytics_after_load` = `yes` | Desmarcar la casilla (purga sola) | Caen las compras que miden GA4 o Clarity frente a los pedidos pagados |
| `D26-3` | Mantener la 301 de `/serie/…/page/N/` como excepción a «las redirecciones viven en Rank Math» | Sin casilla propia; la gobierna `akb_series_landing_per_page` | «Tomos por página» a 24, o código | Search Console las marca como «Error de redirección», o el destino no da 200 |
| `D26-4` | Encender el aviso en la página del corazón de favoritos para invitados | `akb_wishlist_guest_prompt` = `yes` | Desmarcar la casilla | Bajan las cuentas nuevas o los favoritos guardados |
| `D26-5` | «También te recomendamos…» con todos los siguientes agotados → completar con los comprables | `akb_reco_serie_agotada` = `completar` | Volver a «Mostrarlos» | Bajan los «Avísame» y no suben los agregados al carrito |

### ✅ D26-1 · Desplegar el seguimiento, con `LeanCart` encendido: business 1.117.1 y tema 2.1.144 primero, search después — DECIDIDA POR DELEGACIÓN el 2026-09-26 (orden actualizado el 2026-09-27)

- **Qué se decidió:** desplegar los arreglos preparados el 26-09 sobre lo desplegado. Search:
  `akb-search.js`, `search-suggest.js` y `akb-search.css` con sus `.min`, `facets-render.php`,
  `EmptyStateCopy.php` y `SearchAdminSection.php`. Business: `quick-view.css`, `series-bundles.js`,
  `LlmsManifest`, `SiteSpeed` y el módulo nuevo `LeanCart`, que conecta `src/Plugin.php`: lo importa, se lo
  pasa a `SiteSpeed` al construirlo y añade `akb_lean_cart` a `FRONT_READ_OPTIONS`. Tema: `loop.css`,
  `trust.css` y `toque-temprano.js`, con sus `.min`. `akibara-business.php` y el `style.css` del tema sólo
  cambian el número de versión. Checkout y rewards sólo cambiaron pruebas.
- **En qué orden (cambió el 2026-09-27): ya no sale en una sola ventana.**
  - **Primero business 1.117.1 y después el tema 2.1.144**, en la misma ventana: nunca el tema antes que
    business (capítulo 19, «Orden de despliegue y acoples», regla 2). Los dos ZIP están en `dist/` y
    coinciden fichero a fichero con el árbol (comprobado el 2026-09-27, sin contar `tests/` ni los ficheros
    de trabajo). El gate del tema pasó con el search de producción: `qa-theme.sh 2.1.144` → «acoples:
    checkout 2.2.29, search 0.1.75, business 1.117.1» (`scratchpad/qa-theme-2.1.144.log` de la sesión).
  - **Search sale después, en una versión posterior a 0.1.75**, cuando su carril cierre lo que sigue abierto
    y la verificación lo mida y quede en verde.
  - **Separarlos no rompe nada.** Ni business 1.117.1 ni el tema 2.1.144 leen algo nuevo de search: sus
    diferencias con 1.117.0 y 2.1.143 son las de la lista de arriba, comparados fichero a fichero los ZIP de
    `dist/` (siete ficheros en business y siete en el tema), y lo único que `src/Plugin.php` añade es el
    cableado de `LeanCart`. Y lo que trae search vive entero en search: la plantilla del aviso, su texto y su
    estilo. Del tema sólo usa la línea propia que ya le da, desde 2.1.143, a
    `.akb-shop-toolbar > .woocommerce-notices-wrapper` (`assets/css/woocommerce.css:2715` y `:2903`).
- **Lo que la delegación no quita:** el visto bueno es de QA, en cada tanda. La verificación del 2026-09-26
  frenó el seguimiento por search. Las dos primeras versiones del arreglo de PS-S-1 perdían un Atrás con un
  PJAX en vuelo (capítulo 20, `PS-S-1`):
  - la primera, 26 de 26 veces en un iPhone anterior a iOS 26.2, contra 2 de 26 con lo desplegado;
  - la segunda, 7 de 7 hacia otro sitio en Safari con Navigation API, contra 0 de 7.

  La del árbol ya no navega sola ante un fallo de red, en ningún navegador. Deja la página como estaba y,
  pasados 4 s (1 s sin conexión, 8 s si hubo un clic que se lleva la página), muestra un aviso con
  «Reintentar», que carga lo que el cliente pidió; el siguiente gesto del catálogo navega sin PJAX. El texto
  del aviso es nuevo en el panel: **Buscador → Ajustes → «Cuando un filtro no carga por la conexión»**
  (`akb_search_network_notice`, capítulo 09).
- **Incluye una decisión que estaba abierta, la PR-2 del capítulo 20: `LeanCart` encendido de fábrica.**
  - **Qué quita.** Mercado Pago encola en el carrito cosas que sólo usa la página de pago: su SDK, la huella
    antifraude (`security.js`), los textos de error de la tarjeta y dos hojas de estilo. `LeanCart` las saca
    de la cola **sólo en el carrito de bloques** (`dequeue_checkout_only_assets()` en
    `wp_enqueue_scripts:1000`, `src/Modules/SiteSpeed/LeanCart.php:168` y `:186-203`), sin tocar el código de
    Mercado Pago. La página de pago las sigue cargando igual.
  - **El carrito ya no las ejecuta, pero las sigue descargando**, para que la página de pago las encuentre en
    la caché, como hoy. Anota la etiqueta que imprimiría WordPress (`remember()`, `:289-297`) y las pide
    desde el `<head>` con un `<link>` que no ejecuta nada. En Chromium y Firefox es un `prefetch` por
    `wp_resource_hints` (`add_prefetch_hints()`, `:169` y `:216-229`). En Safari, que no tiene `prefetch`,
    es un `preload` dentro de un marco oculto (`WARMUP_JS`, `:154`, que imprime `print_warmup()` en
    `wp_head:3`, `:170` y `:238-250`).
  - **Lo medido.** En producción, en sólo lectura, el 2026-09-26, con un móvil lento, 3+3 corridas y esos
    cinco bloqueados en el navegador: el TBT pasó de 1.769 a 507 ms (docblock, `:24-28`). Esa medida es de
    la primera versión, la que no los precargaba. Su «1.428 → 1.334 KB» ya no vale, porque la versión final
    vuelve a descargarlos; el TBT no se volvió a medir con la precarga, que no ejecuta nada.
  - Esto reemplaza la nota del 2026-09-15 «Mercado Pago en el carrito: es del plugin de terceros; no se
    toca» (`ops/coordinacion/abiertos/2026-09-15-velocidad-cache-de-fichas-y-etiqueta-ads.md:20`). Su código
    sigue sin tocarse: se usan sus ganchos, y queda a una casilla.
- **Ajuste que la controla:** el despliegue no tiene casilla. La parte de Mercado Pago sí: **Akibara → Panel →
  Velocidad → «Quitar del carrito lo que sólo usa la página de pago»**, option `akb_lean_cart`. Con `yes` o
  sin fila queda encendido; con `no`, apagado (`LeanCart::OPTION`, `LeanCart.php:100`; `::DEFAULT`, `:103`; el
  lector es `enabled()`, `:179-181`). El campo está en `SiteSpeed.php:189-210` de business 1.117.1, no se
  autocarga (`:204`) y sólo aparece si Mercado Pago está activo (`if ( LeanCart::glue_active() )`, `:189`).
  En producción no habrá fila hasta el despliegue: rige el valor de fábrica, encendido.
- **Cómo se vuelve atrás:**
  - Lo de Mercado Pago: desmarcar la casilla. El carrito no se guarda en caché, así que el cambio se ve en la
    visita siguiente.
  - Cada tanda, con los paquetes que hoy están en producción, que siguen en `dist/`, con el mismo guion y en
    **orden inverso** al de subida, para no dejar nunca el tema nuevo con el business viejo. La primera:
    `bash ops/akibara-deploy-runtime.sh theme 2.1.143` y después `… business 1.117.0`. Search, cuando haya
    salido: `… search 0.1.75`. Siempre con la purga del capítulo 19.
- **Qué se mide:**
  1. En producción, tras cada tanda, lo de siempre del capítulo 19: la superficie servida con
     `.min?ver=<versión nueva>`, el suggest en 200 y la e2e de sólo lectura.
  2. Antes de desplegar search, en el espejo y después en producción:
     - El Atrás con un PJAX en vuelo, a una página de la tienda y a otro sitio (una página externa falsa),
       con la página anterior frenada 2 s. En WebKit móvil (sin `beforeunload`, como el Safari del iPhone) y
       de escritorio, con y sin `window.navigation`, A/B contra 0.1.75.
     - El corte de red: con la red del PJAX cortada, la página se queda como estaba y el aviso sale a la
       vista y en la región viva. «Reintentar» y el segundo toque llegan a la página pedida, y un Atrás entre
       páginas del propio PJAX recarga lo que dice la barra.
  3. En `/carrito/`, con la casilla encendida, que Mercado Pago **no se ejecute**: en la consola,
     `typeof MercadoPago` da `"undefined"` (apagada, no). En la pestaña Red, `sdk.mercadopago.com` sale
     igual, a propósito: en Chromium, como `prefetch` del `<head>` con la prioridad más baja; en Safari,
     pedido desde un marco oculto. **Buscar que no aparezca daría un rojo falso.** En la página de pago, lo
     mismo con la casilla encendida y apagada.
  4. Los pedidos pagados con Mercado Pago en los 7 días siguientes, contra los 7 anteriores. Si Mercado Pago
     falla al cobrar, se apaga `akb_lean_cart` primero y después se mira.

### ✅ D26-2 · Encender «Cargar Google Analytics y Clarity después de la carga» y medir 7 días — DECIDIDA POR DELEGACIÓN el 2026-09-26

- **Qué se decidió:** encenderla **7 días** y medir en GA4 las sesiones y los eventos de compra. **Si caen los
  eventos de compra, se apaga.** Es la recomendación de `L26-analitica-diferida`, más abajo, que explica qué
  gana y qué cuesta.
- **Ajuste que la controla:** **Akibara → Panel → Velocidad → «Cargar Google Analytics y Clarity después de la
  carga»**, option `akb_analytics_after_load`. Sólo `yes` la enciende (`AnalyticsAfterLoad::OPTION`,
  `src/Modules/SiteSpeed/AnalyticsAfterLoad.php:53`; el lector es `enabled()`, `:93-95`). El campo está en
  `SiteSpeed.php:163-169` de business 1.117.0, la de producción, y en `:168-174` de 1.117.1. Hoy en producción
  no tiene fila, así que está **apagada**. No depende de search: se enciende después de la primera tanda de
  `D26-1`.
- **Cómo se vuelve atrás:** desmarcar la casilla y guardar. Al cambiar, la option purga la caché sola
  (`update_option_akb_analytics_after_load` → `GoogleAdsTag::purge_after_change`, `AnalyticsAfterLoad.php:79-80`).
  Cloudflare puede tardar hasta 10 min.
- **Antes de encenderla:**
  - Anotar la línea base de los 7 días anteriores.
  - Comprobar que la etiqueta de Google Ads (`akb_google_ads_tag`) sigue apagada. Si está encendida, GA4 se
    baja igual con ella y la semana no mide nada; el panel lo avisa.
- **Qué se mide (7 días encendida contra los 7 anteriores):**
  - **Sesiones** de GA4 y de Clarity. Se espera que bajen algo, porque ese es el precio declarado: quien se va
    antes de que termine la carga deja de contar. Esto es información, no el umbral.
  - **El umbral son las compras**, contadas contra los **pedidos pagados de WooCommerce** del mismo periodo,
    que no dependen de ninguna etiqueta. Se miran dos proporciones: «compras que ve GA4 / pedidos pagados» y
    «eventos `purchase` de Clarity / pedidos pagados». **Si cualquiera de las dos cae respecto de la semana
    anterior, se apaga.** Se compara la proporción y no el número a secas para que una semana de menos ventas
    no pase por pérdida de medición. Tampoco la confunde `LeanCart`, que no toca ninguna de las dos etiquetas.
  - **Qué es «compra» en GA4.** En el código de la tienda, nada envía `purchase` a GA4. El único `purchase` es
    el de Google for WooCommerce, que va a la conversión de Ads (`google-listings-and-ads/src/Google/GlobalSiteTag.php:406-408`)
    y se apaga con la etiqueta de Ads. Si la propiedad de GA4 no tiene un evento propio creado en su panel, la
    compra se cuenta como vistas de la página de pedido recibido (la ruta con `order-received`). En Clarity sí
    hay evento: `purchase`, que emite `assets/js/clarity-commerce-events.js` en esa página. Con la casilla
    encendida, ese evento espera en la cola de Clarity en vez de perderse.
  - **Velocidad, como contraste:** A/B de 3+3 en portada y ficha (LCP y `load`).
- **Comprobado en el espejo el 2026-09-26**, con el filtro real y la option forzada sólo en un proceso aparte:
  con `yes`, la etiqueta de GA4 de Rank Math sale como `type="text/plain"` con su dirección en
  `data-akb-after-load`; con `no`, sale como siempre. En el navegador no se puede ver, por lo que sigue.
- **Dónde no se puede probar:** en el espejo no hace nada. Las etiquetas de GA4 y Clarity sólo se imprimen en
  akibara.cl (`FrontendCompatibility` y el mu-plugin `akibara-clarity-production-only`), así que la medida es
  de producción.

### ✅ D26-3 · La 301 de `/serie/…/page/N/` se queda como excepción documentada a «las redirecciones viven en Rank Math» — DECIDIDA POR DELEGACIÓN el 2026-09-26

- **La regla de la que es excepción:** toda alta, edición o baja de una redirección se hace en **Rank Math →
  Redirecciones** (`ops/rankmath-redirections-ui-inventory.md:3-4`). El conjunto es **cerrado** y se compara
  contra producción con recuentos exactos (`ops/rankmath-redirections-ui-final.csv`).
- **Qué se decidió:** la 301 de `SeriesCatalog\LandingPageSize` se queda en el código y queda **documentada**
  como la excepción a esa regla. Hace esto: si una página de serie `/serie/<serie>/page/N/` ya no existe, la
  manda a la última página que queda.
- **Por qué no puede ser una regla de Rank Math:** su destino no es fijo. Depende de «Tomos por página» y del
  número de tomos visibles de cada serie en ese momento (`out_of_range_target()`, `LandingPageSize.php:390-420`).
  Una regla fija quedaría mal con el primer cambio del ajuste o con la primera serie que crece, y metería
  decenas de filas en un conjunto que se valida por recuentos. Tiene además tres límites:
  - Sólo actúa en una página **limpia**: sin parámetros, salvo los de seguimiento y `paged`. Con filtros u
    orden, la página sigue en 404 `noindex`.
  - Sale como no cacheable (`litespeed_control_set_nocache`).
  - Se identifica con `X-Redirect-By: Akibara` (`handle_template_redirect()`, `:360-372`).
- **Ajuste que la controla:** la redirección no tiene casilla propia. La gobierna **Akibara → Panel → Vitrina →
  «Tomos por página»**, que decide qué páginas existen: option `akb_series_landing_per_page`
  (`LandingPageSize::OPTION`, `:91`; entre 12 y 120 y de fábrica 48, `:94-100`). Hoy en producción vale 48.
- **Cómo se vuelve atrás:**
  - **Sin código:** poner «Tomos por página» en **24**. Vuelven a existir todas las páginas que Google tenía
    indexadas y la 301 deja de actuar sobre ellas. Sólo seguiría actuando con números de página que nunca
    existieron, que antes daban 404.
  - **Quitar la excepción de verdad:** es código en business. Hay que retirar los ganchos `pre_handle_404` y
    `template_redirect` de `LandingPageSize::register()` (`:144-145`) y, si se quieren conservar esos destinos,
    darlos de alta a mano en Rank Math.
- **Qué se mide:**
  1. En producción: `curl -sI -A AkibaraQA 'https://akibara.cl/serie/sanda/page/7/'` tiene que dar `301`, con
     `location: https://akibara.cl/serie/sanda/` y `x-redirect-by: Akibara`.
  2. En Search Console, las `/serie/…/page/N/` que sobran pasan a «Página con redirección» y no aparecen como
     404.
  3. Rank Math → Redirecciones no gana filas por esto.
  4. **Se vuelve atrás** si Search Console marca esas direcciones como «Error de redirección» o si el destino
     de la 301 no responde 200.
- **Comprobado en el espejo el 2026-09-26**, en Chromium y WebKit, con móvil táctil y escritorio:
  `/serie/jujutsu-kaisen/page/2/` responde `301` a `/serie/jujutsu-kaisen/` con `X-Redirect-By: Akibara`. El
  navegador llega a los 30 tomos en una sola página, sin enlace a una página 2.
- **Lo que esta decisión no resuelve:** en el origen, esta 301 conserva `fbclid` y `utm`. Pero en un enlace
  de campaña Cloudflare los borra antes de que lleguen (`L26-landing-301-seguimiento` y
  `L26-cloudflare-qs-normalize`). Eso sigue siendo tuyo: es un cambio en tu cuenta de Cloudflare.

### ✅ D26-4 · Encender el aviso en la página para el corazón de favoritos sin sesión — DECIDIDA POR DELEGACIÓN el 2026-09-26

- **Qué se decidió:** encenderlo. Quien no ha entrado y toca el corazón ya no sale de la ficha. Ve un aviso en
  la misma página («Inicia sesión para guardar tus favoritos»), con el acceso a un toque y «Seguir mirando». Si
  entra, el tomo se guarda solo al volver; si sigue mirando, no se guarda nada.
- **Por qué mejora:** el 100 % del tráfico orgánico llega sin sesión. Las grabaciones de Clarity mostraban el
  rebote de vuelta a la ficha en 2-4 s, sin cuentas creadas (`src/Modules/Wishlist/GuestPrompt.php:5-8`). El
  corazón sigue siendo un punto de captación de cuenta; lo que cambia es que ahora el cliente elige ir.
- **Ajuste que la controla:** **Akibara → Panel → Textos → «Corazón de favoritos sin sesión» → «Mostrar el aviso
  en la página»**, option `akb_wishlist_guest_prompt`. Sólo `yes` lo enciende (`GuestPrompt::OPTION_ENABLED`,
  `GuestPrompt.php:39`; el lector está en `:76-78`). El campo está en `CopyManager.php:1218-1230` de business
  1.117.0, igual en 1.117.1. Los cuatro textos del aviso están en la misma sección: `akb_copy_wl_guest_title`, `_body`, `_cta` y
  `_dismiss` (`:41-44`). Hoy en producción está **apagado**.
- **Cómo se vuelve atrás:** desmarcar la casilla y guardar. La pestaña Textos limpia la caché al guardar.
- **Qué se mide (7 días encendido contra los 7 anteriores):**
  - Cuentas nuevas (`wp_users.user_registered`).
  - Favoritos guardados (`wp_akb_wishlist.added_at`, y el evento `wishlist_add` de Clarity).
  - Una muestra de grabaciones de Clarity con un toque en el corazón sin sesión.
  - **Si bajan las cuentas nuevas o los favoritos guardados, se apaga.** Antes, se prueba encendido y
    apagado en incógnito, como indica el manual (2.5).
- **Comprobado en el espejo el 2026-09-26:**
  - La option decide lo que la ficha le pasa a `wishlist.js`: `guestPrompt` sale vacío con `no` y `"1"` con
    `yes`. Se midió pintando la ficha en un proceso aparte, sin escribir en la base.
  - Chromium y WebKit, móvil táctil y escritorio, en la ficha de Jujutsu Kaisen 07:
    - Apagado, el corazón lleva a `/mi-cuenta/?redirect_to=<la ficha>`.
    - Encendido (forzado sólo en ese navegador, como hace la e2e `negocio-interaccion-y-terceros`), se abre el
      aviso, la dirección no cambia, Escape lo cierra y no se anota nada.

### ✅ D26-5 · «También te recomendamos…» con todos los siguientes tomos agotados: completar con los que se pueden comprar — DECIDIDA POR DELEGACIÓN el 2026-09-26

- **Qué se decidió:** «También te recomendamos…» es el bloque de la ficha que sigue la serie. Si **ninguno**
  de los siguientes tomos guardados se puede comprar, se reemplazan por los siguientes tomos de la misma serie
  que **sí** se pueden comprar, en orden de lectura. Si no hay ninguno comprable, se siguen mostrando los
  agotados con «Avísame cuando vuelva».
- **Por qué mejora:** una fila entera de «Agotado / Avísame» no deja comprar nada en la ficha. Con este ajuste,
  el bloque ofrece el siguiente tomo que sí está.
- **Ajuste que la controla:** **Akibara → Panel → Recomendaciones → «"También te recomendamos…" con todo
  agotado» → «Cambiarlos por los siguientes tomos de la serie que sí se pueden comprar»**, option
  `akb_reco_serie_agotada` = `completar` (`Settings::OPTION_SERIE_AGOTADA` y `::AGOTADA_COMPLETAR`,
  `src/Modules/Recommendations/Settings.php:45` y `:51`). Los valores son `mostrar`, `completar` y `ocultar`, con
  `mostrar` de fábrica (`:86` y `:106`). El campo está en `:254-265` y el lector en `UpsellsDisplay.php:112-115`.
  Hoy en producción vale `mostrar`, el de fábrica.
- **Cómo se vuelve atrás:** elegir «Mostrarlos con "Avísame cuando vuelva"» y guardar. La pestaña limpia la
  caché al guardar.
- **Comprobado en el espejo el 2026-09-26** (la ficha se pintó en un proceso aparte, con el ajuste forzado sólo
  en ese proceso y con cualquier escritura en la base bloqueada; hubo 0 escrituras):
  - Jujutsu Kaisen 07 tiene guardados el 08, el 09 y el 10, los tres agotados. Con `mostrar` salen esos tres
    con «Avísame». Con `completar` salen el 13, el 15 y el 16, que sí se pueden comprar.
  - Jujutsu Kaisen 24 es el caso contrario: del 25 al 30 están todos agotados. Con `completar` siguen saliendo
    el 25, el 26 y el 27, agotados.
- **Qué se mide:**
  1. En las fichas cuyos siguientes tomos están todos agotados, el bloque trae tomos que se pueden comprar,
     siempre que la serie tenga alguno posterior disponible.
  2. En los 7 días siguientes contra los 7 anteriores: las altas en «Avísame cuando vuelva»
     (`wp_akb_bis_subscribers.created_at`) y los agregados al carrito (evento `add_to_cart` de Clarity). El
     precio posible es que, al esconder las tarjetas agotadas, bajen los avisos. **Si bajan los avisos y no
     suben los agregados al carrito, se vuelve a «Mostrarlos».**

### Las siete de «a un clic» que no son una casilla — pasaron por la misma delegación

La tabla «Otras que el lote dejó con el comportamiento de hoy, a un clic» (más abajo) traía siete que no se
cambian con una casilla: piden código o no son de producto. Se decidieron con las mismas reglas; las dos que
tocan tu marca o una política comercial quedan contigo. Otras dos filas de esa tabla tampoco son una casilla y
quedan fuera por la última regla: PM-16 / PM-10 (exportar de nuevo la imagen móvil del carrusel, que es tuya,
y las «JavaScript Detections», que están en tu cuenta de Cloudflare) y SRV-1 / SRV-2 (bloquear y borrar en el
servidor de producción, con tu permiso).

| id | qué se decidió | por qué | qué falta y cómo se vuelve atrás |
| --- | --- | --- | --- |
| QAM-9 | **Se mantiene** la línea «Llega …» en la barra de compra, también con el teléfono en horizontal | El plazo es lo que decide reservar un tomo a pedido (UM-7), y la barra es donde está el botón. Ocultarla con el teléfono en horizontal (`@media (max-height: 480px)`, capítulo 13, §4.26) sólo recuperaría 15-17 px de 390 | Nada: no cambia nada. Si algún día se prefiere pantalla, la regla está escrita |
| REV-T6-2-4 | **Se mantiene un solo título** para las reseñas de la portada y de las fichas | El panel ya dice que titula los dos sitios («Título de las reseñas (portada y fichas)») y el tema lo documenta a propósito (`akibara_gp_reviews_title()`, `inc/site-copy.php`). Separarlo añade un texto que escribir, y el cliente no ve nada distinto mientras no se escriba otro | Nada. Si quieres un título distinto en las fichas, es un cambio de código: un texto nuevo en el panel y su lectura en el tema |
| QA-ESC-TECLADO-7 | **Se arregla** en la tienda de escritorio (`/tienda/` y sus `/tienda/page/N/`, con filtros o sin ellos y con cualquier orden de la barra) con **5 columnas sólo cuando, en esa ventana y con lo que esa página muestra, con 4 el botón de la primera fila queda bajo el borde y con 5 se ve**, con la rejilla en su ancho máximo (1.056 px), en Chromium y en WebKit y con la barra de desplazamiento oculta o clásica, como ajuste nuevo del panel (Akibara → Panel → Vitrina), encendido, y sólo en la plantilla de la tienda. **No se escribe como una franja fija de alto** (la de antes, `@media (min-width: 1420px) and (min-height: 765px) and (max-height: 845px)`, sólo acertaba en la página 1 sin filtros y con el orden por defecto): el tema **mide la primera fila en el navegador**, con 4 columnas y con 5 en la misma tarea (la prueba no llega a pintarse), desde un script en línea justo después de la rejilla, y vuelve a decidir al cambiar el tamaño de la ventana y cada vez que el buscador cambia la rejilla (`akb:pjax`). En las demás ventanas siguen 4, como hoy, también cuando la ventana no deja a la rejilla llegar a su máximo. **No** se aplica a categorías, editoriales, «A pedido» ni a la búsqueda: su rejilla empieza más abajo y ahí, en ventanas de hasta 893 px de alto, ni con 5 columnas se ve el botón. **Siguen 24 tomos por página**: con 5 columnas la última fila de cada página queda con 4 de 5. **No** cambian las landings de serie, y **no** se pliega el texto de `/manga/` ni la introducción de `/a-pedido/` | Medido en el espejo el 2026-09-27 (sondas D-D-c1 y D-D-c2: Chromium 1237 con la barra oculta y con la clásica, y WebKit 2342; escritorio, sólo GET, imágenes bloqueadas; el botón, sin la animación de entrada). **Una franja fija de alto no cumple la regla: el botón de la primera fila se mueve dentro de la misma plantilla.** La de antes salía de la página 1 de `/tienda/`, sin filtros y con el orden por defecto, donde el botón queda en 845,1-845,2 px con 4 columnas y en 764,7-764,8 con 5 desde que la rejilla llega a su máximo (de ahí 765-845: a 764 px de alto ni con 5 se ve, y a 846 ya se ve con 4), y ahí la franja de antes acertaba en las 1.950 ventanas de D-D-c1. Pero a 1.440 de ancho, con 4 y con 5 columnas (Chromium, igual con la barra clásica; WebKit, hasta 0,9 px más abajo), y entre paréntesis los altos de ventana en que 5 sirven: `/tienda/page/6/`, 849,1 y 784,9 (785-849: precio de oferta en la primera tarjeta y un título que sólo con 5 columnas pasa a 3 líneas); `/tienda/page/10/`, 845,1 y 784,9 (785-845); un filtro por «Disponible», por «Agotado» o por una editorial, 885,1 y 804,7 (805-885: la fila de chips, 24 px más 16 de margen, baja la rejilla de 278 a 318 px); «A pedido», 903,8 y 839 (839-903); «Preventa», 920,4 y 855,6 (856-920; sólo en el espejo, que tiene una sembrada); el orden «Más recientes» o «Más vendidos», 863,8 y 799 (799-863); «Menor precio», 880,4 y 815,6 (816-880). Las tarjetas «A pedido» y «Preventa» llevan el precio tachado, «Al llegar el stock sube a…» y «Llegada: …» (`.akb-eta-loop`). Con el orden por defecto y la vitrina «Disponibles primero», la de hoy, llegan a la primera fila en las páginas 44 a 63 del espejo; con «Intercalado», la otra vitrina del panel (`akb_catalog_order_showcase`), también a la página 1 (el 2026-09-22, antes de cambiarla, la tienda abría con 6 de 6 tomos «a pedido»). En las 68 páginas de `/tienda/` (1440×800), 23 no tienen los umbrales de la página 1. En esos estados la franja ponía 5 sin que el botón se viera (de 765 hasta el borde de abajo de cada uno) y dejaba fuera ventanas en que 5 servían (de 846 hasta el de arriba); en producción, hoy, la página 1 da 845,1 y 764,7, `/tienda/page/6/` 845,1 y 784,9, «Menor precio» 863,8 y 799 y «Disponible» 885,1 y 804,7 (REV-D-D-2o-1 y 2f-1). Y un filtro aplicado desde la barra no recarga la página: el buscador cambia la rejilla y pinta los chips por PJAX, y la media query no se entera. Medido tras marcar «Disponible» a 1440×800 (Chromium y WebKit, ratón y táctil, con y sin `window.navigation`): la franja deja 5 columnas con el botón a 805 px, fuera de la vista (8 de 8), y la regla medida vuelve a 4 (8 de 8); a 1440×850, la franja deja 4 con el botón a 885 y la regla pone 5 con el botón a 805, a la vista (8 de 8). **La regla medida sí la cumple, por construcción**: en las 3.663 ventanas medidas de 13 estados (33 altos de 700 a 950 px en cada ancho: 1.440 en los 13; de 1.420 a 1.434 y 1.920 en tres de ellos; de 1.390 a 1.417 en la página 1) pone 5 justo donde con 4 el botón queda bajo el borde y con 5 se ve, y en ninguna otra, en los tres navegadores. **Cuándo decide también está medido.** Desde un script en línea tras la rejilla, la página se pinta ya con sus columnas: ningún desplazamiento (CLS 0 en Chromium, 6 de 6; WebKit no lo mide, y deja las mismas columnas). En el evento `load`, después de pintar, la rejilla salta de 4 a 5 (CLS 0,244-0,245 en Chromium, 3 de 3). En `DOMContentLoaded`, WebKit puede decidir con una hoja de estilos aún cargando (la de Joinchat, al pie): la prueba con 5 columnas no se aplica y la regla decide 4 donde 5 servían (4 de 20, las 4 veces en que esa hoja seguía cargando; Chromium, 20 de 20 bien). Ninguna tarjeta de la primera fila queda escondida por la animación de entrada: ni al cargar, ni al pasar de 900 a 800 px de alto, ni tras el PJAX. **El ancho lo lee de la rejilla, no de `min-width`.** (1) En la página 1, el borde inferior del botón no depende del alto de la ventana (salvo 1-4 px en WebKit a 1.025-1.040 de ancho, donde pasa de 3 a 4 columnas) ni, a igual ancho útil (`clientWidth`, sin la barra), del motor: ±1 px desde unos 1.160 px de ancho útil (más angosto, con 5 columnas, hasta 5 px, por cómo corta cada motor una línea). Crece con ese ancho hasta que la rejilla llega a su máximo, 1.056 px, con 1.400 px de ancho útil, en los tres. (2) Las media queries no miden ese ancho igual en todos los navegadores. Chromium cuenta la barra clásica: con una ventana de 1.440, `min-width` ve 1.440 y el ancho útil es 1.426. WebKit no la cuenta: ve 1.426. Por eso la franja de antes, con `min-width: 1420px`, no actuaba en WebKit con barra clásica de 1.420 a 1.433 px de ventana aunque 5 servían (52 ventanas en D-D-c1; REV-D-D-2o-2); la regla medida sí (184 de 184 en D-D-c2). (3) Por debajo de 1.400 px de ancho útil siguen 4: el botón se mueve con el ancho (a 1.280 de ancho útil queda a 801 px con 4 columnas y a 729 con 5; a 1.366, a 833 y 755) y 5 columnas darían tarjetas más angostas que los 198 px medidos, sin medir cómo quedan. Las cifras de 1.366 que antes se daban por motor (832/827 px con 4 columnas) eran la misma ventana con 14 px menos de ancho útil en WebKit. Los equipos de esos anchos (1366×768, 1280×800) dejan la ventana en 657-700 px, donde ni con 5 se ve. (4) Sólo la tienda, porque es el único listado cuya rejilla empieza a 278 px. En categorías, editoriales, «A pedido» y la búsqueda empieza entre 407 y 612 px (espejo, 11 listados a 1.440 de ancho; en producción, `/tienda/` 278, `/manga/` 510 y `/a-pedido/` 587), y el botón de la primera fila queda entre 894 y 1.106 px aun con 5 columnas. Con esto gana una ventana de 1.440 px de ancho o más cuyo alto quede entre 765 y 845 px en la página 1 sin filtros (con filtros u otro orden, más alta): en una pantalla de 1440×900, si la barra de menú, la del navegador y el Dock o la barra de tareas suman entre 55 y 135 px. **No gana un portátil de 1366×768 real**: con la barra de tareas y las del navegador la ventana queda en unos 657-695 px, y ni con 5 columnas se ve el botón; ahí siguen 4, como en producción. Con una ventana de 900 px de alto, en la página 1 el botón ya se ve con 4. El número por página no se toca: 24 llena las filas de 2, 3 y 4 columnas (móvil; tableta de 769-1024 px, `assets/css/loop.css:1022-1024`; escritorio). 20 o 40 llenarían las de 5, pero dejarían la tableta en 6×3+2 o 13×3+1 y moverían los tomos de todas las `/page/N/` que Google ya conoce; y no puede depender de la ventana, porque la página va en caché igual para todas (por lo mismo, las columnas se deciden en el navegador). El tema ya quitó las 5 columnas forzadas por esos huecos (`assets/css/loop.css:44-47`); la regla los limita a las ventanas en las que dejan ver la primera fila. Las landings de serie quedan fuera: con 48 por página la última fila quedaría en 3 de 5, y su primera fila no se ve ni con 5 en ventanas de hasta 1.258 px de alto (en `/serie/one-piece/` el botón queda a 1.259-1.286 px con 5 columnas y a 1.359-1.392 con 4). El texto de la categoría es para Google y la introducción de «A pedido» explica el plazo; moverlos no está medido | Código del tema y el ajuste en business: **próximo lote**, con la regla medida y sólo en la plantilla de la tienda, **no con la franja fija**, que en esa misma plantilla ya pone 5 sin servir (al lado). Lo que la medida pide al código: el script en línea justo después de la rejilla (no en `load`, que salta, ni en `DOMContentLoaded`, que en WebKit puede decidir con una hoja de estilos aún cargando), comprobando que la prueba con 5 columnas de verdad pinta 5; la caja de la primera fila sin la animación de entrada (GSAP baja 8 px las tarjetas que estaban bajo el pliegue); decidir otra vez al cambiar el tamaño y tras `akb:pjax`; y el ancho, de la rejilla en su máximo (1.056 px), no de `min-width`. Si LiteSpeed difiere o retrasa los scripts en línea, se pierde el «antes de pintar»: se mira en el HTML que sirve producción. Se mide en Chromium (con la barra oculta y con la clásica), en WebKit y en Firefox (que aquí no se midió), leyendo las columnas y el botón, en el espejo y después en producción: la página 1 de `/tienda/`, `/tienda/page/6/` y `/tienda/page/10/`; un filtro por «Disponible», «A pedido» y una editorial, abierto por URL y desde la barra (ratón y táctil, con y sin `window.navigation`); y los seis órdenes de la barra. Con 5 columnas y el botón a la vista: 1440×765, 1440×800 y 1440×845 en la página 1, 1440×790 en `/tienda/page/6/`, 1440×850 con «Disponible» y con «Menor precio», 1425×800 (también en WebKit con barra clásica) y 1920×800. Siguen 4: 1440×764 y 1440×846 en la página 1 (ni con 5 se ve; con 4 ya se ve), 1440×784 en `/tienda/page/6/`, 1440×800 con «Disponible» (ni con 5 se ve), 1100×780 y 1200×780 (con 4 ya se ve), 1399×800 y 1366×800 (la rejilla no llega a su máximo), y 1366×657 y 1366×695 (el portátil de 1366×768 real). En `/manga/` y `/a-pedido/` a 1440×800, 4; en tableta, 3; en `/serie/…`, 4. Y la carga a 1440×800 sin desplazamiento (CLS 0 con el script en línea) y sin tarjetas de la primera fila escondidas. Para el portátil de 1366×768 real, lo que acercaría la primera fila es la altura de lo que va encima de la rejilla (empieza en y=278 a 1366×768): sin medir, se mide en el mismo lote. Se apaga el ajuste si, 7 días contra 7, bajan los agregados al carrito en escritorio desde el catálogo (evento `add_to_cart` de Clarity) |
| venta cruzada en una serie sin tomos | **Se deja como está** («Hoy no tenemos tomos de «X».» y «Ver todas las series») | El 2026-09-20 se borraron las 13 series sin tomos que había en producción y quedaron 0 (capítulo 20, `R8-series-huerfanas`; en el espejo también hay 0, contado el 2026-09-26). Programarla sería código para una página que hoy no existe | Nada. Si vuelve a aparecer una serie vacía, se trata como aquellas 13 |
| PM-18 | **Se prueba** en el próximo lote una Inter variable subconjuntada (pesos 400-700, latin) en lugar de los cuatro `woff2` de Inter, y **se adopta sólo si pesa claramente menos** que los 97.036 B de hoy y las capturas no cambian | Es rendimiento y es código del tema (la precarga de `akibara_gp_preload_fonts()`, `inc/enqueue.php:679-694`; los ficheros de `assets/fonts/` que declara `theme.json`): no toca la marca —la letra es la misma—, ni una política comercial, ni pide tus cuentas. La auditoría la dejó sin medir: el −462 ms de su A/B quitaba las fuentes del todo, las fuentes se guardan un año en el navegador (sólo gana la primera visita) y el tamaño de la variable no se midió (PM-18 = PC-4, `ops/informe-tema-ux-rendimiento-2026-09-22.md`). Medido en el espejo en Chromium y WebKit, móvil táctil y escritorio: la portada baja las cuatro Inter (97.036 B, tres precargadas) y Bebas (8.596 B) | Código del tema: **próximo lote**. Primero se genera el `woff2` variable y se miden sus bytes; si no baja claramente de 97.036 B, se queda como está. Si se adopta: capturas antes y después en Chromium y WebKit, LCP de la portada A/B 3+3, y al día el candado de `tests/visual-polish.test.js` y la decisión R11 de pesos precargados. Se vuelve atrás (código: los cuatro ficheros) si cambia la métrica de la letra (CLS al cargar la fuente) o el LCP no mejora |
| DS-3 | **Sigue siendo tuya** | «Reservar el rojo a comprar» es la regla de color de toda la marca (la auditoría del 2026-09-22 contó 487 usos del rojo fuera de «comprar»), no el ajuste de una pantalla. Es identidad, y no se mide en una semana | Tu palabra. Hasta entonces, la página actual de la paginación sigue en rojo |
| «Encárgalo» en una búsqueda sin resultados | **Sigue siendo tuya** | Ofrecer «lo traemos por ti» es una política comercial: compromete a la tienda con un precio, un plazo y la atención de cada encargo, y esa atención la llevas tú | Tu palabra. Hasta entonces, no se ofrece |

## Lote UX y rendimiento del tema (auditoría 2026-09-22 → producción 2026-09-26)

El lote se desplegó el 2026-09-26 (search 0.1.75, business 1.117.0, checkout-blocks 2.2.26, rewards 0.4.18,
tema 2.1.143). Lo técnico está en los capítulos 09, 13, 19 y 20; aquí sólo lo que es tuyo.

### ✅ L26-vitrina-disponibles-primero · DECIDIDO el 2026-09-22 — La tienda abre con lo que está en bodega

- **Tu decisión (textual):** «mostrar los disponibles primero, pero ojo que también tenemos un ordenamiento de las
  series».
- **Qué pasaba:** la vitrina intercalaba series por lo más nuevo, y la primera página de `/tienda/` abría con 6 de
  6 tomos «a pedido» (llegada en 5-7 semanas; UC-3 de la auditoría).
- **Qué se hizo (business 1.117.0):** el recálculo de la vitrina agrupa **disponible → a pedido (incluye
  preventas) → agotado**, y dentro de cada grupo sigue intercalando series con lo más nuevo arriba
  (`CatalogOrder` + `Interleaver`). Es un ajuste del panel, no código fijo: **Akibara → Panel → Vitrina →
  «Disponibles primero (recomendado)»** (de fábrica) o «Intercalado, como hasta septiembre de 2026», con un
  validador que usan el guardado y el lector (`akb_catalog_order_showcase`). Cambiarlo encola el recálculo, que
  purga la caché al terminar. **Tu matiz de las series se respetó:** las landings `/serie/…` conservan «Tomo 1 →
  N», y su «Novedades primero» ya no depende de la vitrina (business la traduce a «lo más nuevo arriba, lo
  agotado al final», con el stock en vivo).
- **Medido en producción el 2026-09-26:** la primera página de `/tienda/` son 24 de 24 tomos disponibles, y el
  catálogo entero va 1.090 disponibles → 410 a pedido → 143 agotados, sin mezclas. Recálculo terminado a las
  06:09:26 UTC con 1.643 productos.

### ✅ L26-fable-5-1 · REGLA VIGENTE desde el 2026-09-25 — Se puede usar Fable 5.1 cuando convenga

- **Tu regla:** se puede usar un agente Fable 5.1 cuando se estime conveniente, además de las skills; la base del
  trabajo sigue siendo Opus. (Registrada en la memoria de trabajo del proyecto el 2026-09-25.)
- **Cómo se aplicó en este lote:** en la revisión pre-despliegue, con **10 lentes Fable y Opus** sobre cada pieza
  y doble refutación de cada hallazgo: 0 medios o altos confirmados, 5 bajos y 2 refutados (capítulo 20).

### ✅ L26-pruebas-visuales-y-de-comportamiento · REGLA VIGENTE — Cada cambio, probado como lo ve el cliente

- **La regla:** cada cambio lleva **prueba visual** (capturas antes y después) **y de comportamiento** en
  **Chromium y WebKit** (Safari/iPhone), en **móvil táctil y en escritorio**, más **pruebas automáticas que
  fallen sin el cambio**; primero en el espejo y, después de desplegar, también en producción (la regla 0 del
  proyecto: «hasta que no está medido en producción, no está aprobado»).
- **Cómo se aplicó:** una línea base visual y de comportamiento tomada **antes** de tocar el tema (15
  superficies; proyectos móvil 390×844 táctil, escritorio 1440×900 y 1366×768, y WebKit móvil), comparada tras
  cada lote en el espejo y, tras desplegar, contra capturas de producción tomadas a las 06:05 UTC: las 60
  diferencias se explicaron por cambios intencionados. Además, la suite e2e de sólo lectura en producción.
- **Lo que falta para que la regla no dependa de una sesión:** la suite visual vive en un directorio temporal,
  **fuera del repositorio** (capítulo 20, `VIS-REPO`). Recomendación: llevarla a `tools/` y tomar la corrida
  posterior al despliegue como nueva base de producción.

### ✅ L26-analitica-diferida · «Cargar Google Analytics y Clarity después de la carga» — DECIDIDA el 2026-09-26 por delegación (`D26-2`): encender y medir 7 días

- **Dónde:** Akibara → Panel → **Velocidad** → «Cargar Google Analytics y Clarity después de la carga»
  (`akb_analytics_after_load`; sólo `yes` lo enciende; en producción no tiene fila, o sea **apagado** hasta
  que se aplique `D26-2`).
- **Qué hace encendido:** la etiqueta de GA4 (188 KB) y la de Clarity dejan de bajarse al leer la página y
  esperan a que termine de cargar, o al primer toque, tecla o desplazamiento del cliente. Los eventos de
  compra de Clarity no se pierden: esperan en cola. Medido en el espejo con red lenta: en la ficha la portada
  aparece ~0,3 s antes y la página termina de cargar ~1,3 s antes; en el catálogo el LCP no cambia.
- **Por qué era tuya:** el precio es que **quien se va antes de que la página termine de cargar deja de contar**
  en Analytics y en Clarity. Además, si está encendida la etiqueta de Google Ads, GA4 se sigue bajando al abrir
  la página (la trae Ads); el panel lo avisa.
- **Decidido (`D26-2`, arriba):** encenderla 7 días y medir en GA4 las sesiones y las compras; **si caen los
  eventos de compra, se apaga**. La recomendación que había aquí («una semana, A/B 3+3 en portada y ficha; la
  portada sigue en LCP 4,9 s, PR-3») queda como contraste de velocidad dentro de esa medida. Se apaga con la
  misma casilla; el cambio purga la caché solo.

### L26-landing-301-seguimiento · La 301 de las páginas de serie que ya no existen conserva `fbclid`/`utm`… pero Cloudflare los borra antes

- **Qué hace el código (business 1.117.0, `SeriesCatalog\LandingPageSize`):** las series pasaron de 24 a **48
  tomos por página** (Akibara → Panel → Vitrina → «Tomos por página», 12-120). Una `/serie/…/page/N/` que deja
  de existir responde **301 a la última página que queda**, y la regla tiene una **excepción**: los parámetros de
  seguimiento (`fbclid`, `gclid`, `utm_*`, `srsltid`…) **no cuentan como filtros** y la redirección los
  **conserva**, para que la visita que llega desde una campaña o desde Google no pierda su atribución.
- **Qué pasa en producción:** un enlace de campaña llega sólo con datos de seguimiento y su query empieza por
  `fbclid=`, `utm_source=`…: es como llegan los de Meta, Google Ads y los correos. En esos enlaces, la regla
  `qs-normalize` de Cloudflare vacía la query **antes** de que llegue a la tienda (la condición exacta está en
  `L26-cloudflare-qs-normalize`), y la excepción no tiene nada que conservar. Medido en sólo lectura el
  2026-09-26: `/serie/sanda/page/7/?fbclid=…` y `?utm_source=…` redirigen a `/serie/sanda/` **sin** el
  parámetro. Con `srsltid` solo o al principio de la query, la regla no actúa y la 301 lo conserva (capítulo 20,
  E2E-1). Si `srsltid` va detrás de un `utm_*` o de un `fbclid`, se pierde con el resto.
- **La 301 se mantiene:** queda como excepción documentada a «las redirecciones viven en Rank Math»
  (`D26-3`, decidida por delegación el 2026-09-26).
- **Por qué lo de Cloudflare sigue siendo tuyo:** la regla es de tu cuenta y afecta a toda la tienda. Se decide
  junto con la siguiente (`L26-cloudflare-qs-normalize`), que explica que esta 301 **no es la única** que pierde
  la atribución. Hasta entonces, la prueba automática «la atribución viaja» queda marcada como conocida en
  producción.

### L26-cloudflare-qs-normalize · La regla de Cloudflare borra `fbclid`, `gclid` y `utm_*` de los enlaces de campaña

- **Qué pasa:** la regla de transformación `qs-normalize` (aplicada el 2026-07-29, `unit_qs_normalize()` en
  `ops/akibara-cloudflare-hardening.sh:339-368`) **reescribe la URL** para que la caché no guarde una copia por
  cada enlace de campaña.
- **Cuándo actúa, exactamente** (es la única definición; los capítulos 19 y 20 y el manual la citan desde aquí):
  - **Condición 1:** la query **empieza** por uno de sus 14 parámetros de seguimiento: `fbclid=`, `gclid=`,
    `dclid=`, `msclkid=`, `ttclid=`, `igshid=`, `mc_cid=`, `mc_eid=`, `_gl=`, `utm_source=`, `utm_medium=`,
    `utm_campaign=`, `utm_term=` o `utm_content=` (`:341`).
  - **Condición 2:** la query **no contiene** ninguna de sus 16 marcas funcionales: `s=`, `orderby=`, `paged=`,
    `_buscar=`, `add-to-cart`, `wc-ajax`, `p=`, `page_id`, `product_cat`, `filter_`, `min_price`, `max_price`,
    `preview`, `elementor`, `ref=` o `rest_route` (`:344`). Es una comparación de texto: `s=` también salta con
    cualquier parámetro cuyo nombre termine en «s».
  - **Qué hace entonces:** vacía la query **entera** (`query.value: ""`), `srsltid` incluido si viene detrás.
  - **Cuándo no toca nada:** si la query empieza por otro parámetro (`srsltid=`, `gbraid=`…) o lleva uno
    funcional.
  - Un enlace de campaña o de Meta llega así: sólo con datos de seguimiento y empezando por ellos.
- **Por qué importa:** funciona para la caché, pero cuando la tienda redirige (una 301) un enlace así, el
  navegador ya no tiene los parámetros y esa visita pierde la atribución en GA4 y en Meta. El runbook daba por
  hecho que «GA4 y Meta siguen atribuyendo igual» (`:337-338`), y en esas redirecciones no es así.
- **Qué redirecciones pierden la atribución: todas las de un enlace que llega sólo con datos de campaña, no sólo
  una.** En esos enlaces la regla vacía la query **antes** de que la petición llegue a la tienda, y ninguna
  redirección puede conservar lo que no recibe. Están medidas dos, el 2026-09-26: (1) la 301 de las páginas
  de serie que ya no existen (`LandingPageSize`, `L26-landing-301-seguimiento` y capítulo 20, E2E-1); (2) la
  **301 canónica de WordPress** cuando el enlace llega sin la barra final (`/tienda` → `/tienda/`,
  `/serie/sanda` → `/serie/sanda/`). La segunda es la de un enlace de campaña pegado a mano en una historia o en un correo:
  `akibara.cl/serie/frieren`, al que Meta le añade `?fbclid=…`. En el espejo, que no tiene Cloudflare,
  `/tienda?utm_source=…&fbclid=…`, `/serie/sanda?utm_source=…&fbclid=…`, `/serie/frieren?fbclid=…` y
  `/manga?utm_source=…` responden 301 a la dirección con barra y **con** los parámetros. En producción
  (sólo lectura), `/tienda?utm_source=…` y `/serie/sanda?fbclid=…` redirigen a `/tienda/` y a
  `/serie/sanda/` **sin** ellos.
- **Las que sí los conservan hoy** (medido en producción, sólo lectura, el 2026-09-26; revisión REV-S-D-3o-1 y
  3f-1):
  - Si la query empieza por otro parámetro: `/tienda?srsltid=z&utm_source=prueba` →
    `/tienda/?srsltid=z&utm_source=prueba`.
  - Si lleva uno funcional: `/tienda?utm_source=prueba&orderby=price` → `/tienda/?utm_source=prueba&orderby=price`,
    y `/serie/sanda?ref=x&fbclid=…` → `/serie/sanda/?ref=x&fbclid=…`.
  - Al revés, `srsltid` detrás de un parámetro de campaña se pierde con él: `/tienda?utm_source=prueba&srsltid=z`
    → `/tienda/`.
  - En el espejo, el origen conserva los parámetros en esas cuatro direcciones (medido el mismo día), así que lo
    que falta en producción lo quita la regla.
- **Salida en el plan Free, paso 1 (medida en el origen, pero parcial):** no tocar la clave de caché y **acotar
  la regla**, añadiendo a su expresión
  `and not (starts_with(http.request.uri.path, "/serie/") and http.request.uri.path contains "/page/")`.
  Usa las mismas funciones que la regla ya tiene (`starts_with` y `contains`; el guion evita `matches` porque el
  plan lo restringe), así que el plan la acepta. Esto recupera **sólo** la 301 de las páginas 2, 3… de las
  series, la de `LandingPageSize`. En el espejo, `/serie/sanda/page/7/?utm_source=…&fbclid=…` responde 301 a
  `/serie/sanda/?utm_source=…&fbclid=…` (medido el 2026-09-26). **No recupera la canónica**: `/serie/sanda` no
  contiene `/page/`, así que la regla la sigue vaciando y esa visita sigue sin atribución. El precio es
  pequeño: esas páginas, cuando llegan con un parámetro de campaña, se guardan en la caché una vez por clic,
  y una campaña casi nunca enlaza la página 2 de una serie.
- **Paso 2, para cubrir también la canónica (opción a evaluar, no medida):** en vez de la condición del paso 1,
  añadir
  `and not ((starts_with(http.request.uri.path, "/serie/") and http.request.uri.path contains "/page/") or not ends_with(http.request.uri.path, "/"))`.
  Así la regla deja pasar sin tocar toda dirección que no termina en «/», y entre ellas las que WordPress
  redirige a su versión con barra. La página de destino, que sí termina en «/», se sigue normalizando para la
  caché. `ends_with()` pertenece al mismo lenguaje de reglas que `starts_with()`, pero **no está comprobado**
  que el panel la acepte en esta regla ni cómo se porta en producción. Hay que confirmarlo al guardarla y
  medirlo después. El coste: un fichero (imagen, hoja de estilos, guion; no terminan en «/») enlazado con un
  `fbclid` se guardaría una vez por clic. Fuera de las páginas `/serie/*/page/*`, ni el paso 1 ni el 2 cubren
  una redirección que llegue **con** barra final: por ejemplo, una dirección vieja que una de las redirecciones
  activas de Rank Math manda a la nueva (en el espejo hay 216 activas). Esa sigue sin atribución. Si el enlace
  la trae **sin** barra final, el paso 2 sí la cubre: en el origen, `/generos?utm_source=prueba` responde 301 a
  `/tienda/?utm_source=prueba` (`X-Redirect-By: Rank Math`), y la expresión del paso 2 no vacía esa query;
  con `/generos/?utm_source=prueba` sí la vacía (medido en el espejo y evaluado sobre la regla viva el 2026-09-26).
- **Después, comprobar en producción** con `curl -sI -A AkibaraQA` y mirar la línea `location:`.
  (1) `'https://akibara.cl/serie/sanda/page/7/?utm_source=prueba'`: hoy sale `location: https://akibara.cl/serie/sanda/`
  y, con cualquiera de los dos pasos, tiene que terminar en `?utm_source=prueba`. (2) `'https://akibara.cl/tienda?utm_source=prueba'`:
  hoy sale `location: https://akibara.cl/tienda/` (medido el 2026-09-26). Con el paso 1 **seguirá así**, y eso
  muestra qué queda fuera. Sólo con el paso 2 terminaría en `?utm_source=prueba`.
- **Lo que no sirve en este plan.** Quitar esos parámetros **sólo de la clave de caché** (Cache Rules → Cache
  key → Query string → «All query string parameters except…») es **sólo de Enterprise**: la tabla de
  disponibilidad de *Cache Keys* en la documentación de Cloudflare da «Query string» como «No» en Free, Pro y
  Business, y la zona está en **Free** (`.local-backups/cloudflare-hardening/20260729T130435Z/zone.json`: «Free
  Website»; también `ops/akibara-cloudflare-hardening.sh:40` y `:333`). «Ignore query string» en la clave (esa
  tabla lo da en Free; la página de ajustes de Cache Rules lo presenta como opción de Enterprise y de pago por
  uso) **tampoco conviene**: la tienda copia la query en el HTML —el formulario «Ordenar por» la lleva en campos
  ocultos y los enlaces de paginación la conservan; medido en el espejo con `/tienda/?utm_source=…`—, así que
  una página guardada sin mirar la query serviría a todos el `utm` del primero que la pidió.
- **Si no se acota:** hay dos salidas. Una es aceptar la pérdida en **todas** las redirecciones de un enlace que
  sólo trae datos de campaña: la de las páginas de serie que ya no existen, la canónica de cualquier enlace
  pegado sin la barra final y la de una dirección vieja. La otra es cambiar de plan, que no es proporcionado
  para esto. No hay medida de cuántas visitas son.

### L26-cloudflare-regla-9 · Los archivos combinados de LiteSpeed se vuelven a bajar en cada página

- **Qué pasa:** la regla de caché 9 de Cloudflare («CACHE static assets») cubre `uploads`, `themes` y `plugins`
  pero **no `/wp-content/litespeed/`**, así que esos CSS y JS salen con `cache-control: … no-store` y el
  navegador los baja otra vez en cada página: 140-226 KB por navegación (re-medido el 2026-09-26, PR-1).
- **Por qué es tuya:** es un cambio en tu panel de Cloudflare; desde tu Chrome el clasificador bloqueó la
  edición automática (asunto de coordinación del 2026-09-22).
- **Recomendación:** añadir `starts_with(http.request.uri.path, "/wp-content/litespeed/css/") or
  starts_with(http.request.uri.path, "/wp-content/litespeed/js/")` a la regla 9, con browser TTL «respetar
  origen» o un año (los nombres llevan hash y `?ver`), y comprobar con `curl -I` que desaparece `no-store`.

### Otras que el lote dejó con el comportamiento de hoy, a un clic

| id | qué es | dónde se cambia | hoy |
| --- | --- | --- | --- |
| QAM-10 | El corazón de favoritos de alguien sin sesión: ¿aviso en la misma página («Inicia sesión para guardar tus favoritos») o directo a la página de acceso? | Akibara → Textos → «Corazón de favoritos sin sesión» → «Mostrar el aviso en la página» (`akb_wishlist_guest_prompt`) | **Decidido el 2026-09-26 por delegación: encender** (`D26-4`). Hasta aplicarlo, apagado (va al acceso, como siempre) |
| QAM-11 | «También te recomendamos…» cuando todos los siguientes tomos están agotados: mostrarlos con «Avísame», cambiarlos por los siguientes comprables u ocultar el bloque | Akibara → Panel → Recomendaciones (`akb_reco_serie_agotada`) | **Decidido el 2026-09-26 por delegación: «Cambiarlos por los siguientes que sí se pueden comprar»** (`D26-5`). Hasta aplicarlo, «Mostrarlos» |
| QAM-9 | La línea «Llega …» sube la barra de compra de 68 a 83-85 px; con el teléfono en horizontal quedan 165 px de contenido. ¿Se oculta en pantallas bajas? | Código (una regla de CSS) si decides ocultarla | Se muestra. **Decidido el 2026-09-26 por delegación: se mantiene** (ver «Las siete de «a un clic» que no son una casilla») |
| QA-ESC-TECLADO-7 | Con una ventana de 1366×768 la primera fila del catálogo no entra entera (y en `/manga/` y `/a-pedido/`, el texto de introducción empuja la rejilla). Opciones: 5 columnas en pantallas bajas, o plegar el texto SEO tras la rejilla | Pediría un ajuste nuevo del panel | Como está. **Decidido el 2026-09-26 por delegación: 5 columnas en la tienda (`/tienda/` y sus páginas, con filtros o sin ellos y en cualquier orden) sólo cuando así se ve el botón de la primera fila y con 4 no**, en ventanas de escritorio en que la rejilla llega a su ancho máximo (con 1.400 px de ancho o más sin contar la barra de desplazamiento). No es una franja fija de alto: en la página 1 sin filtros son las ventanas de 765 a 845 px de alto, y un filtro, otro orden o lo que lleven las tarjetas de la primera fila la mueven (785-849, 805-885, 839-903 o 856-920, medido); por eso el tema lo mide en el navegador (corregido tres veces el 2026-09-27 con la medida). Medido en Chromium y WebKit; Firefox, en el próximo lote. En el próximo lote y con su ajuste del panel; 24 por página y sin tocar las landings de serie. Categorías, editoriales y «A pedido» siguen en 4: su rejilla empieza más abajo y, en ventanas de hasta 893 px de alto, ni con 5 se vería el botón. No alcanza a un portátil de 1366×768 real (ver «Las siete de «a un clic» que no son una casilla») |
| DS-3 | Reservar el rojo a «comprar»: la página actual de la paginación sigue siendo un bloque rojo (se revirtió en el lote porque es regla de marca) | Código del tema | Rojo. **Sigue siendo tuya:** es la regla de color de la marca |
| REV-T6-2-4 | El título de las reseñas es **un solo texto** para la portada y para cada ficha; ¿separarlos? | Hoy: Akibara → Puntos y Reseñas → «Título de las reseñas (portada y fichas)» | Uno solo, «Voces del Distrito». **Decidido el 2026-09-26 por delegación: se mantiene uno solo** |
| — | «Encárgalo» / «lo traemos por ti» en una búsqueda sin resultados (política de encargo) | Pediría un texto y una regla nuevos | No se ofrece. **Sigue siendo tuya:** es una política comercial |
| — | Venta cruzada en la landing de una serie sin tomos (hoy sólo «Ver todas las series») | Código del tema | No se muestra. **Decidido el 2026-09-26 por delegación: se deja así** (el 2026-09-20 quedaron 0 series sin tomos) |
| QA-ESC-TECLADO-13 | El nombre con que el lector de pantalla anuncia el carrusel de la portada | Akibara → Textos → «Portada» → «Nombre del carrusel» | **Aplicado con la sugerencia «Destacados»** (antes «Hero rotativo de Akibara»); cámbialo si prefieres otro |
| PM-18 | La portada sigue en LCP 4,9 s; una parte son las fuentes: cuatro `woff2` de Inter (97.036 B, tres precargados) más Bebas en la primera visita. ¿Una Inter variable subconjuntada? | Código del tema (`inc/enqueue.php:679-694`, `assets/fonts/`, `theme.json`) | Cuatro ficheros. **Decidido el 2026-09-26 por delegación: se prueba la variable en el próximo lote y se adopta sólo si pesa claramente menos** |
| PM-16 / PM-10 | El resto del LCP de la portada: exportar el hero móvil a su encuadre real (~768×906) y las «JavaScript Detections» de Cloudflare | Imagen del hero en Slider de Inicio; Cloudflare | Sin cambios. **Siguen siendo tuyas:** son tu imagen y tu cuenta de Cloudflare |
| SRV-1 / SRV-2 | `error_log` descargable por web, `php.ini` y `wp-cli.yml` públicos y residuos de despliegues en el docroot (capítulo 20) | Producción: bloquear y retirar; los borrados en producción los tiene que correr el dueño | Pendiente de tu permiso |

## Esperando tu palabra

Cada una dice qué hace hoy el código, por qué es decisión y no defecto, y lo que yo recomendaría.


### ✅ S-d32fa602 · RESUELTA — el veto ya cubre el BORRADO de productos de WooCommerce

> **Cerrada el 2026-09-20 (ronda 10).** El dueño respondió el 2026-09-13 «vetar sólo borrar», y eso
> **ya está implementado y en producción**: `akibara-abilities-denylist.php` **1.2.0** incluye
> `'woocommerce/product-delete'` (`:37`) y lo apaga por las dos capas —`permission_callback` que
> devuelve `false` (`:51`) y `meta.mcp.public` a `false` (`:65-66`)—, así que por MCP no se lista y
> por REST el `POST` a `.../run` devuelve 403. Además, desde la reapertura de S-31b76a50 el intento
> vetado **deja línea** en WooCommerce → Estado → Registros (`:51-63`). Verificado en producción en
> sólo lectura el 2026-09-20: versión 1.2.0, línea 37 presente, copia local y copia de producción
> byte a byte idénticas.
>
> **Alcance real de la decisión, para que no se reabra:** se vetó **sólo borrar**.
> `woocommerce/product-create`, `product-update` y las de pedidos (`orders-query`, `order-add-note`,
> `order-update-status`) siguen publicadas **a propósito**: son el trabajo que un agente hace a
> diario y dejan rastro reversible. Lo nuestro —las cinco abilities de la casa con
> `show_in_rest => true`— sigue fuera de la lista, que es harina de otro costal.
>
> Lo que sigue abajo es el texto original de la pregunta, que se conserva como historia.

- **Dónde:** `mu-plugins/akibara-abilities-denylist.php:25-28; terceros: woocommerce/src/Internal/Abilities/Domain/ProductDelete.php:5…`  ·  **Severidad si fuera defecto:** alta  ·  **Área:** mu-plugins-a
- **Qué pasa hoy:** La cabecera de la denylist explica que existe para que un agente no pueda hacer por MCP lo que la casa tiene prohibido. Pero el catalogo de lo que un agente puede pedir hoy incluye woocommerce/product-delete (borrar o mandar a la papelera un producto), product-create, product-update, orders-create/update y order-update-status, todas con mcp.public=true y ninguna en la lista. El freno que queda es el permission_callback de cada una (capacidades de tienda), exactamente el mismo freno que la casa considero insuficiente para set-module-status. Es una asimetria que no resiste la pregunta del dueno:…
- **Por qué es tuya y no del código:** El hecho técnico se confirma: en WooCommerce 11.1.0, AbilitiesLoader registra ProductCreate/ProductUpdate/ProductDelete/OrdersQuery/OrderAddNote/OrderUpdateStatus y ProductDelete declara meta.mcp.public=true con annotations.destructive=true y permission_callback can_delete_product() → wc_rest_check_post_permissions('product','delete',$id). Ninguna está en la denylist. PERO la premisa de la sospecha («la cabecera explica que la denylist existe para que un agente no pueda hacer por MCP lo que la casa tiene prohibido») no es lo que dice el fichero: la cabecera y la memoria acotan la lista, por escrito y a propósito, a DOS abilities de Rank Math que chocan con dos reglas selladas concretas (auto…

### S-2a996d4e · Apagar «Dar puntos por compra» apaga en silencio el bono, los referidos y el correo, pero las superficies los siguen prometiendo

- **Dónde:** `src/Points/Accrual.php:147-163 y :202; src/Points/WelcomeBonus.php:45; src/Points/Referrals.php:53; src/Points/Emails.ph…`  ·  **Severidad si fuera defecto:** alta  ·  **Área:** rewards-puntos
- **Qué pasa hoy:** El panel presenta «Dar puntos por compra», «Dar bono de bienvenida» y «Programa de referidos» como tres interruptores independientes y el texto de ayuda de cada uno dice que rige al instante. Con el primero apagado (o con «Tamaño del tramo» en 0) el aviso no se dispara nunca, así que no se paga el bono de bienvenida, no se paga ningún referido y no sale el correo de puntos — mientras la página pública (ProgramPage.php:267-289) y la ficha de producto (PdpNotice.php:71-80) siguen prometiendo «+500 puntos de bienvenida» y «500 puntos por invitado», porque esos módulos consultan sus propios interr…
- **Por qué es tuya y no del código:** Lo marque como decision_negocio el lector; no lo es. Verificado en el snapshot: Options::earn_points() devuelve 0 con el interruptor apagado (Options.php:50-55) -> Calculator::points_for devuelve 0 (Calculator.php:37-53) -> Accrual.php:146-163 hace return ANTES del do_action('akb_rewards_after_order_award') de la linea 202; y de ese unico aviso cuelgan WelcomeBonus::maybe_pay (WelcomeBonus.php:45, prio 10), Referrals::maybe_pay_referrer (Referrals.php:53, prio 30) y Emails::send_order_email (Emails.php:243, prio 100). Es decir: un interruptor apaga en silencio tres carriles ajenos, contra la directiva escrita de independencia. Y la pagina publica sigue prometiendo: ProgramPage.php:267-289 pi…

### S-255aeca1 · La foto es obligatoria, paga 500 puntos y no se pinta en ninguna parte salvo el carrusel de la portada

- **Dónde:** `src/Reviews/Media.php:76 (escritura) · src/Reviews/Carousel.php:313 (único lector que la pinta)`  ·  **Severidad si fuera defecto:** alta  ·  **Área:** rewards-resenas
- **Qué pasa hoy:** El dueño aprueba a ciegas: la foto es el requisito de la reseña y la justificación de los 500 puntos, y en Productos → Valoraciones no puede verla; tendría que buscarla a mano en la Biblioteca de Medios. Y al cliente se le exigió una foto «para que ayude a quien duda si comprarlo» y su foto no aparece en la ficha del tomo: sólo podría llegar a la portada, y sólo si la tienda supera las 8 reseñas. Quien pintaba estas fotos era la plantilla del plugin de terceros que se retiró.
- **Por qué es tuya y no del código:** Confirmado por barrido del snapshot entero (plugins first-party + mu-plugins + tema): las unicas apariciones de la meta son Media.php:29/45-46/76/101 (escritura y validacion), ReviewPoints.php:190 (decide el premio) y Carousel.php:313-314 (unico lector que la pinta, y detras de la compuerta de >7 resenas elegibles). Ningun filtro comment_text, ningun hook woocommerce_review_*, ningun meta-box ni columna en Productos -> Valoraciones. Quien pintaba estas fotos era la plantilla del tercero retirado (04-cusrev-ingenieria-inversa.md:408-410: la grid de CusRev las mostraba, el slider no). Resultado: la promesa escrita al cliente («es lo que hace util tu resena para quien duda si comprarlo») no se …

### S-a9b5a722 · N1 · El rescate de editorial no llega a NINGUNA de las dos superficies de serie

- **Dónde:** `src/SuggestController.php:334 · frontend/facets-render.php:413 y :446`  ·  **Severidad si fuera defecto:** alta  ·  **Área:** search-consulta
- **Qué pasa hoy:** En el caso que justifica el módulo entero —«jujutsu kaisen ivrea», medido en prod el 2026-09-05— el cliente recibe las 31 tarjetas y el aviso «esto es lo que tenemos de jujutsu kaisen», pero NI el grupo Serie del desplegable NI la banda «Ver la serie» del catálogo. Es exactamente la landing `/serie/*` que Search Console señala como la palanca SEO del sitio (4.044 impresiones), y el rescate la apaga justo cuando más señal hay de intención de serie. Además es una incoherencia interna demostrable: tres de los cuatro consumidores de la consulta caen a `kept` y éste no.
- **Por qué es tuya y no del código:** Verificado en el snapshot: SuggestController.php:334 hace `$series = $this->series->suggest( $corrected['fix'] ?? $query );` — sin la rama `is_array($relaxed) ? (string) $relaxed['kept']` que SI llevan el highlight (~313) y el total del pie (~368, con su propio comentario «Con la editorial soltada, el total va sobre lo que DE VERDAD se busco»). En el catalogo, akibara_search_series_band_query() (frontend/facets-render.php:412) devuelve `trim($view['search'])`, el _buscar crudo, y akibara_search_series_band_match() se lo pasa a SeriesSuggestions::suggest() exigiendo match fuerte (tier 0/1), que «jujutsu kaisen ivrea» no puede cumplir. Tres de los cuatro consumidores de la consulta caen a `kep…

### S-42e2b311 · El «Umbral envío gratis (CLP)» cambia el número que se promete pero NO el momento en que la barra dice «¡Listo!»

- **Dónde:** `src/Services/FreeShippingThreshold.php:497 y :509`  ·  **Severidad si fuera defecto:** media  ·  **Área:** biz-compra-cuenta
- **Qué pasa hoy:** El umbral que se pinta es el mayor de los dos, pero la comprobación de si ya califica usa SÓLO el real. Con el transportista en 60.000 y el campo del panel en 80.000, un carrito de 50.000 lee «Agrega $30.000 más para envío gratis» y, al llegar a 65.000, la barra salta a «¡Listo! Tu pedido tiene envío gratis» sin haber llegado a los 80.000 que ella misma anunciaba. El cliente ve dos números incompatibles en la misma sesión. Y contradice literalmente la ayuda del panel, que dice «este campo sólo sirve para exigir más» (FreeShippingProgress.php:474-476). Es distinto del hallazgo ya cerrado en ops…

### S-ac8a464f · Una línea agotada se queda muda dentro del cajón: el mismo `max === 0` que dispara el aviso es el que deshabilita el botón que podría dispararlo

- **Dónde:** `assets/js/native-cart.js:610-617 y src/Modules/NativeCart/NativeCart.php:445-468`  ·  **Severidad si fuera defecto:** media  ·  **Área:** biz-compra-cuenta
- **Qué pasa hoy:** El servidor sabe retirar la línea y explicarla («X se agotó y lo quitamos de tu carrito»), pero el único gesto que el cajón ofrece para llegar ahí —subir la cantidad— está deshabilitado justo en el caso en que el aviso haría falta. Con una sola unidad en el carrito, «−» manda qty 0 y cae en la rama de borrado silencioso (NativeCart.php:477-479), que NO pasa por el aviso. Resultado: el tomo agotado sigue en el cajón, sumando al subtotal y al progreso de envío gratis, hasta que el cliente llega a /carrito/ y la Store API le dice «no tiene existencias» — que es exactamente el incidente del 2026-0…

### S-02b2ed3e · La ruta /a-pedido/ lista también las preventas reales, y su título, su descripción y su texto afirman lo contrario

- **Dónde:** `plugins/akibara-business/src/Modules/PreventasRoute/PreventasRoute.php:59, 72, 297-298, 331-337`  ·  **Severidad si fuera defecto:** media  ·  **Área:** biz-disponibilidad-preventa
- **Qué pasa hoy:** En cuanto haya una preventa de verdad en el catálogo, esa ficha aparece en /a-pedido/ con su badge «Preventa» y su CTA «Reservar en Preventa», dentro de una página cuyo título, descripción de Google y párrafo introductorio afirman que el tomo «ya está publicado». Además el chip del buscador dice «A pedido (N)» con un N que no coincide con lo que se ve. Hoy no muerde porque el catálogo no tiene pre-lanzamientos configurados, pero el día que los haya la página miente sobre ellos y lo hace en la superficie indexada.
- **Por qué es tuya y no del código:** El código es exactamente como lo describe el hallazgo (`PreventasRoute.php:298` filtra por `StockStatus::ON_BACKORDER` crudo mientras `FACET_VALUE` precarga el comercial `a_pedido`, y el título y la intro hablan sólo de «a pedido»), pero agrupar los dos estados comerciales bajo `onbackorder` es la decisión vigente, tomada con el dato delante (0 preventas en 1.631 productos, medido en producción) y con su disparador escrito para el día que cambie. Hoy no miente sobre nada: no hay ni un producto con `release_date` o `tba`. Confirmarlo como defecto sería reabrir una decisión medida; lo correcto es anotarlo en la lista del día D junto con la cuarta opción del descuento que el dueño ya pidió.

### S-eee8e31c · Un aviso de reposición perdido por un fatal no tiene NINGUNA vía de recuperación

- **Dónde:** `plugins/akibara-business/src/Modules/BackInStock/NotificationDispatcher.php:256-280`  ·  **Severidad si fuera defecto:** media  ·  **Área:** biz-disponibilidad-preventa
- **Qué pasa hoy:** Si el obrero muere entre el reclamo y el envío (timeout del proceso, fatal fuera del try, el proveedor se cae a media conexión), la fila queda en «avisado» sin que se haya mandado nada. A diferencia de OrderPreventa —que tiene `SendFailureRearm`, contador de intentos y reenvío manual desde «Acciones del pedido»—, la lista de espera NO tiene reenvío manual, ni pantalla para reabrir una fila, ni columna que distinga «avisado de verdad» de «avisado en la base y nunca enviado». El panel la contará entre los «Notificados» y nadie se enterará.

### S-6d8bf806 · Los cuatro asuntos de los correos de cambio y anuncio de fecha no tienen pantalla: sólo se cambian por filtro PHP

- **Dónde:** `plugins/akibara-business/src/Modules/OrderPreventa/ReminderEmail.php:192-208, 273-303 y 700-716`  ·  **Severidad si fuera defecto:** media  ·  **Área:** biz-disponibilidad-preventa
- **Qué pasa hoy:** El cortocircuito es CORRECTO (evita que el asunto del T-7 se cuele en los otros dos modos), pero deja cuatro asuntos y dos encabezados que el cliente lee —uno de ellos el de una postergación, que bajo Ley 21.719 es comunicación material— sólo modificables desplegando código o escribiendo un filtro. Todo el resto del copy de esta área migró a options de «Akibara → Textos» (las promesas de la ficha son la excepción que el mapa ya denuncia en su sospecha 4); esta es la segunda excepción y el mapa no la ve porque el docblock la presenta como una decisión resuelta. Además el patrón de la casa para …

### S-57e08a0f · Dos modulos de la casa se contradicen sobre `priceValidUntil`: uno lo borra por no inventar fechas y el otro lo inventa

- **Dónde:** `src/Modules/SchemaProductRankMath/SchemaProductRankMath.php:521-524 (sanitize_simple_product_offer) vs src/Modules/Produ…`  ·  **Severidad si fuera defecto:** media  ·  **Área:** biz-seo-correo
- **Qué pasa hoy:** El primero corre en `rank_math/snippet/rich_snippet_product_entity` (prioridad 20) y el segundo en `rank_math/json_ld` (prioridad 90), es decir DESPUES: el borrado queda sistematicamente deshecho y lo publicado es siempre la fecha sintetica. Uno de los dos comentarios miente sobre lo que hace el sitio, y quien lea el primero concluira que la ficha no declara fecha de precio cuando si la declara. No es una regresion funcional obvia, es una regla de negocio que existe dos veces con signo opuesto.
- **Por qué es tuya y no del código:** La medición del hallazgo es correcta: `SchemaProductRankMath` corre en `rank_math/snippet/rich_snippet_product_entity` (prio 20) y `ProductSchemaShopping` en `rank_math/json_ld` con `SchemaGraph::PRODUCT_OFFERS` = 90, que es posterior, y en ficha de producto siempre pasa por `fix_price_specification()` — así que el `unset` de la línea 523 queda sistemáticamente deshecho y lo servido es siempre la fecha a 30 días. Pero esa fecha es la política vigente y documentada, no una regresión: el veredicto correcto NO es cambiar el JSON-LD. Lo que queda vivo es una rama inalcanzable y un docblock que miente («Sanea un Offer sin inventar fecha de precio»), que cae bajo la REGLA DE ORO de cero código mue…

### S-f47ab665 · Dos interruptores que sólo existen por consola (regla de oro: cero options invisibles)

- **Dónde:** `plugins/akibara-business/src/Modules/AgentReadiness/MarkdownNegotiation.php:197-203 y src/Modules/ScriptDiagnostics/Cros…`  ·  **Severidad si fuera defecto:** media  ·  **Área:** cross-datos
- **Qué pasa hoy:** El dueño no puede verlos ni moverlos desde ninguna pantalla, ni siquiera en modo lectura. El segundo es justamente un interruptor de emergencia que puede dejar la tienda sin cobrar si un script de pago se marca mal: tenerlo sólo por SSH contradice la regla de oro «todo operable sin código».
- **Por qué es tuya y no del código:** El hecho es cierto: en el snapshot las dos options aparecen SOLO en su propio módulo (MarkdownNegotiation.php:197,203 y CrossOriginScripts.php:70) más la lista FRONT_READ_OPTIONS de Plugin.php:797; ninguna pantalla las pinta. Pero la regla de oro que se invoca trae su propia excepción escrita, y las dos caen en lados distintos de ella. akb_markdown_negotiation_enabled SÍ está documentada: su docblock explica que encenderla sin la regla de borde sirve markdown a los clientes (medido el 2026-08-28) y que por eso el default es 'no' y el encendido va por consola DESPUÉS de poner la regla — un campo en el panel sería justamente la palanca que la memoria cloudflare-ignora-vary describe como peligr…

### S-a301d63f · El texto del retiro nombra «Metro San Miguel» a fuego para CUALQUIER método local_pickup

- **Dónde:** `akibara-business/src/Modules/PickupCoordination/PickupCoordination.php:46, :118, :122, :139, :153`  ·  **Severidad si fuera defecto:** media  ·  **Área:** cross-integraciones
- **Qué pasa hoy:** Basta que el dueño cree una segunda instancia de «Retiro local» (otro punto, otra zona) para que el correo, la página de gracias y Mi cuenta le digan al cliente que vaya al Metro San Miguel. El módulo detecta el método correctamente pero luego asume que sólo hay uno. Además el copy no es editable desde el panel, lo que infringe la REGLA DE ORO de «todo operable sin código» para un texto que va en un correo al cliente.
- **Por qué es tuya y no del código:** Hay UN punto de retiro y es una decisión del dueño, con el copy aprobado por él y desplegado hace un día; la segunda instancia de local_pickup es un escenario hipotético que hoy no existe (la única instancia viva es la 70). Así que el módulo no está mintiendo a nadie. Lo que sí queda en su cancha, y es lo único que yo levantaría: el texto va a fuego en el código y viaja en un correo al cliente, mientras el resto de los copys del dueño se editan en «Textos» — si algún día abre un segundo punto, el correo mentirá. Es una pregunta de una línea para él, no un arreglo que decidamos nosotros.

### S-28b71a5b · Fuera de produccion se silencia la etiqueta de Clarity pero sigue vivo todo el aparato de Brand Agent

- **Dónde:** `mu-plugins/akibara-clarity-production-only.php:22-25`  ·  **Severidad si fuera defecto:** media  ·  **Área:** mu-plugins-a
- **Qué pasa hoy:** Solo se quitan las tres inyecciones del navegador. En el espejo siguen registrados los endpoints REST de Brand Agent, la ruta publica /.well-known/ucp, el borrado de webhooks en init:20 y dos rutinas de admin_init del tercero: clarity_repair_oauth_status, que puede VOLVER A PONER BAOauthSuccess en 1 si encuentra la marca de inyeccion y el secreto HMAC, y clarity_backfill_webhooks_created. Como un refresco de base desde produccion trae esas credenciales armadas (es la trampa que ya costo un incidente con Cloudflare y los webhooks), el espejo puede acabar hablando con la cuenta real de Clarity. …
- **Por qué es tuya y no del código:** Lo que decide si Brand Agent vuelve o no es una decisión de negocio ya tomada y escrita, y la parte ejecutable (webhooks vivos, credenciales que viajan en el dump) está en manos del dueño en un asunto abierto de esta misma jornada. Lo que la lente de política SÍ sostiene como deuda técnica pendiente de su palabra: hoy nada del lado de la casa impide que `clarity_repair_oauth_status` reponga `BAOauthSuccess`, y CLAUDE.md obliga a que cualquier candado sea por hook, nunca editando el tercero. Eso se propone al dueño junto con H1, no se ejecuta solo.

### S-784f564b · El borrador de datos personales promete desvincular la identidad y el código escribe el correo del cliente en cada fila del ledger

- **Dónde:** `src/Support/Privacy.php:58-64 frente a :186-189`  ·  **Severidad si fuera defecto:** media  ·  **Área:** rewards-puntos
- **Qué pasa hoy:** El texto que se le entrega al cliente en su solicitud de borrado dice que su identidad queda DESVINCULADA; el código hace lo contrario, pega su dirección de correo dentro del texto de cada movimiento del ledger, precisamente en el momento de borrar la cuenta. Es una contradicción entre lo prometido y lo ejecutado, en el trámite donde menos se puede fallar. (La intención del código —dejar el historial legible— es defendible; lo que no lo es es el texto que dice lo contrario.)
- **Por qué es tuya y no del código:** La mitad de código está DECIDIDA por escrito, así que el arreglo no puede ser quitar el sello: stamp_identity() (Privacy.php:48-65) congela el correo en la nota a propósito, para que el registro contable siga siendo legible, y su docblock lo dice sin disimulo («Congela la identidad del cliente en sus filas del ledger ANTES de que WordPress lo borre»). Lo que NO cubre ninguna decisión escrita es el texto que se le entrega al cliente en su solicitud de borrado: erase() (líneas 186-189) responde «Al borrar la cuenta, la identidad queda desvinculada», y es falso por partida doble — se le pega el correo en cada nota Y las filas conservan su `user_id` intacto (las consultas siguen siendo `WHERE us…

### S-7c596ce4 · _akb_rewards_total_earned se escribe en cada crédito y no lo lee absolutamente nadie

- **Dónde:** `src/Points/Ledger.php:110-113 y :64`  ·  **Severidad si fuera defecto:** media  ·  **Área:** rewards-puntos
- **Qué pasa hoy:** Ledger::total_earned() no tiene ningún llamador, y un grep de _akb_rewards_total_earned sobre el snapshot entero (plugins, tema, mu-plugins) devuelve sólo la línea del uninstall.php que la borra. Es una escritura extra en cada movimiento de puntos —con su lectura cruda bajo candado, que además invalida la caché de usermeta del cliente— por un dato que ninguna pantalla muestra y ninguna regla consulta. Peor: hay una decisión de diseño entera construida encima (el comentario de 15 líneas de AdminAdjust.php:195-209 sobre por qué los regalos manuales no cuentan como ganados, para no romper la inva…
- **Por qué es tuya y no del código:** El hecho técnico es correcto y está verificado en el snapshot: Ledger::total_earned() no tiene llamadores y _akb_rewards_total_earned sólo reaparece en uninstall.php:26. Pero no es código muerto no decidido: es una cifra que QA levantó, dev diagnosticó y el dueño resolvió hace seis días, dejando por escrito que se conserva para niveles/insignias/aniversario y que «si algún día se estrena el uso de total_earned, ese es el momento de decidir si se recalcula desde el ledger». La propuesta del hallazgo («o se le da superficie, o se barre con su lógica asociada») reabre una decisión ya tomada: barrerlo requiere palabra del dueño, no de desarrollo.

### S-aba640bd · `wp akb-rewards audit --fix` pisa la decisión deliberada de la migración y BAJA saldos que el cliente ya vio

- **Dónde:** `src/Points/Migration.php:309-316 y :369-371 frente a src/Support/Audit.php:37-79 y :102-121`  ·  **Severidad si fuera defecto:** media  ·  **Área:** rewards-puntos
- **Qué pasa hoy:** La migración escribe a propósito el saldo DECLARADO por SPAR aunque no cuadre con la suma de los movimientos importados, y lo deja dicho en el informe: «se conserva el SPAR (es lo que el cliente ve)». La auditoría no sabe nada de esa decisión: para ella cualquier meta que no iguale la suma del ledger es una «desviación», y --fix la re-deriva de la suma. Resultado: en un sitio migrado, la herramienta que el propio mapa recomienda para «reparar» (mapa.md:24) le quita puntos al cliente sin avisar a nadie, y además esos usuarios quedan marcados como desviación para siempre hasta que alguien los «r…
- **Por qué es tuya y no del código:** El choque existe tal como se describe y lo confirmé en el snapshot (Audit::mismatches compara meta contra SUM(delta) y repair() la re-deriva sin excepción para los saldos sellados por la migración). Lo que NO hay es una decisión escrita que diga cuál de las dos manda: cada mitad tiene su propia justificación escrita y nadie las ha confrontado. Es dinero del cliente, así que la resolución es del dueño, no de desarrollo (memoria no-decidir-negocio-solo). Dos atenuantes medidos que conviene decir al escalarlo: el cutover real de producción cerró con «migración REAL 14 movimientos / 7 saldos paridad 0 difs» (COORDINACION.md:108), o sea hoy no hay ningún usuario en ese estado; y --fix es un coman…

### S-b7699a04 · Dos datos que se escriben y nadie lee: el avance «2 de 3» de la cadencia y la procedencia del consentimiento

- **Dónde:** `src/Reviews/ReviewPoints.php:249 · src/Reviews/AdminReviews.php:191`  ·  **Severidad si fuera defecto:** media  ·  **Área:** rewards-resenas
- **Qué pasa hoy:** Con «premiar cada 3 reseñas» encendido, el cliente manda su reseña, se la aprueban, no le llegan puntos y no hay ningún sitio donde pueda ver que va 2 de 3: para él es un premio que no se pagó. Y el dueño guardó la procedencia y el consentimiento de cada reseña transcrita creyendo que queda a mano, cuando la única forma de leerla es por SQL — justo lo contrario de la regla de la casa de que todo sea operable sin código.
- **Por qué es tuya y no del código:** Confirmo el hecho: un grep del snapshot sólo devuelve las escrituras de _akb_rewards_review_progress (ReviewPoints.php:249) y _akb_rewards_source_note (AdminReviews.php:191). Pero la consecuencia que describe el hallazgo está refutada en su parte más grave: la cadencia SÍ se le anuncia al cliente («500 puntos cada 3 reseñas») en Mi cuenta (AccountRewards.php:270-276) y en la página pública (ProgramPage.php:273-279), así que no hay premio prometido y no pagado; y el carril está apagado de fábrica (Options.php:343-344 devuelve 1 por defecto, y la medición de prod del 2026-09-05 —abiertos/2026-09-05-qa-puntos-y-resenas.md— dice que las únicas filas de configuración vivas son referral_max_per_us…

### S-02eca29f · No hay ningún tope de invitaciones por cliente: diez pedidos son diez correos

- **Dónde:** `src/Reviews/Invitations.php:124-126`  ·  **Severidad si fuera defecto:** media  ·  **Área:** rewards-resenas
- **Qué pasa hoy:** Un cliente fiel que compra ocho veces en dos meses recibe ocho correos «[Akibara] ¿Qué te pareció tu pedido?». En una tienda de manga, donde lo normal es comprar un tomo al mes de varias series, eso es la receta para que marquen el remitente como spam — y la memoria del proyecto ya recoge que los límites antispam heredados costaron una venta. La regla no está escrita en el código porque nadie la decidió.
- **Por qué es tuya y no del código:** El código es como se describe: un pedido, una invitación, sin ventana mínima ni tope mensual por cliente. Pero «cuántos correos de reseña tolera un cliente» es política comercial pura, sin nada escrito que la fije, y la memoria del proyecto empuja en las dos direcciones a la vez (limites-antispam-cortan-ventas documenta que un tope heredado de otro formulario costó una venta). Por eso no lo doy por defecto confirmado: escala al dueño junto con la pregunta del rescate que ya le espera, y que él diga el número. Nota de alcance: en producción los pedidos por cliente son pocos, así que hoy no hay daño medido.

### S-46ab8fa3 · La relajacion de editorial puede disparar hasta once consultas extra en la unica superficie publica sin limite por IP

- **Dónde:** `src/QueryEngine.php:269 y src/FacetQueryIntegration.php:262`  ·  **Severidad si fuera defecto:** media  ·  **Área:** search-consulta
- **Qué pasa hoy:** Una URL como /tienda/?_buscar=ivrea+argentina+panini+espana+planeta+ovni+distrito no devuelve nada y provoca, en cada peticion y sin cache de pagina util, una cascada de consultas de uniones. No es un agujero de seguridad, pero es la superficie mas facil de martillar y la que mas caro sale por peticion. El desplegable, que SI tiene limite, esta mejor protegido que la pagina.
- **Por qué es tuya y no del código:** Las dos mitades de la sospecha están decididas por escrito: la relajación por editorial es una mejora comercial aprobada y medida, y la ausencia de límite por IP en el catálogo es un hallazgo de auditoría ya dictaminado con otra mitigación. Poner un tope de intentos o un rate-limit en /tienda/ cambiaría un compromiso ya tomado: es del dueño, no un bug.

### S-194732c2 · Un buscador caído por una causa no reparable purga Cloudflare ENTERO cada 15 minutos, indefinidamente

- **Dónde:** `src/RebuildJob.php:714-735 (maybe_recover_serving) → src/EditorialDiscountPublication.php:1275-1282 (record_swap_receipt…`  ·  **Severidad si fuera defecto:** media  ·  **Área:** search-indice
- **Qué pasa hoy:** El cálculo del comentario sólo mira el coste del rebuild, no el de la purga. `litespeed_purge_all` es la ÚNICA purga integrada con Cloudflare (el propio docblock de RestCache lo dice) y un flush total del borde se midió el 2026-08-22 en 48 → 1.057 ms la landing de serie y 54 → 710 ms el blog. Si la causa del 503 no se arregla sola (por ejemplo, el proveedor de precio de akibara-business ausente tras una cuarentena de Imunify), el sitio entero pierde su caché de borde cada cuarto de hora, de forma indefinida y sin tope. El dueño vería «la web está lentísima» sin relación aparente con el buscado…
- **Por qué es tuya y no del código:** El coste del reintento indefinido está aceptado por escrito frente al riesgo mayor (buscador caído sin nadie que lo levante) y ahora lleva alarma al dueño. Lo único no decidido —que cada recuperación arrastre una purga ENTERA de Cloudflare— pertenece al hilo abierto de los nueve purgadores, que otra sesión ya trabaja.

### S-c1eb6c16 · La comparación uno-a-uno de los 1.568 productos corre dentro de una petición de cliente

- **Dónde:** `src/EditorialDiscountPublication.php:1792-1870 (editorial_materialization_is_unchanged), llamada desde reconcile_on_init…`  ·  **Severidad si fuera defecto:** media  ·  **Área:** search-indice
- **Qué pasa hoy:** `init` corre en TODAS las peticiones del front. Con el gate cerrado, una de cada sesenta peticiones —elegida al azar, la del cliente que pase en ese segundo— paga 1,4 s extra de PHP y 36 MB. En hosting compartido eso se nota: un cliente ve la portada tardar casi un segundo y medio más sin ninguna razón visible. El razonamiento del docblock es correcto comparado con un rebuild de 100-160 s, pero la alternativa que no se consideró es hacerlo en una acción de Action Scheduler en vez de en la petición del visitante. Es una decisión medida, pero es una decisión de negocio (latencia del cliente) tom…
- **Por qué es tuya y no del código:** Correr la reconciliación en `init` con ventana de un minuto es una decisión de ingeniería tomada, medida y afinada dos veces por escrito, con la latencia del front nombrada explícitamente («en hosting compartido eso importa»). Mover el trabajo a Action Scheduler es una propuesta legítima, pero cambia un compromiso ya acordado: va al dueño / al hilo abierto, no se ejecuta.

### S-ea21fa66 · El sello «Atención por WhatsApp» no se apaga cuando el dueño borra el teléfono

- **Dónde:** `public_html/wp-content/themes/akibara-gp/inc/trust-badges.php:44-47`  ·  **Severidad si fuera defecto:** media  ·  **Área:** theme
- **Qué pasa hoy:** El texto es un literal. El pie, en cambio, sí obedece: si `akb_copy_footer_phone` queda vacía, `akibara_gp_footer_phone_digits()` devuelve '' y el enlace de WhatsApp desaparece (template-parts/footer/main.php:121-125). Resultado: la ficha y el carrito vacío siguen prometiendo «Atención por WhatsApp» en cada producto mientras el sitio ya no ofrece ninguna forma de llegar a ese WhatsApp. El dueño no tiene ninguna palanca para retirar la promesa sin tocar código, que es justo lo que la regla de oro prohíbe.
- **Por qué es tuya y no del código:** Que el sello «Atención por WhatsApp» sea literal está censado, con destino de option nombrado (akb_copy_promise_support) y diferido a propósito al LOTE 2. El censo YA registró la contradicción de esa misma familia (la ficha dice «Atención por WhatsApp» y el checkout «Atención personalizada», CheckoutTrustSignals::all()). La derivada que añade el hallazgo —vaciar akb_copy_footer_phone apaga el enlace wa.me del pie (akibara_gp_footer_phone_digits() devuelve '') y deja el sello prometiendo un canal sin vía de acceso— es consecuencia del mismo literal, no un defecto aparte; y el escenario exige que el dueño vacíe el teléfono, cosa que hoy no ha hecho. Acoplar «sin teléfono ⇒ sin sello» es además…

### S-7a8da14e · La copia de cliente de `pintar_invitacion()` está clavada en el código, sin pantalla

- **Dónde:** ``  ·  **Severidad si fuera defecto:** baja  ·  **Área:** biz-nucleo
- **Qué pasa hoy:** 

### S-8e376a21 · La barra de anuncios rota sola cada 6 s sin control de pausa visible, mientras el hero viene con autoplay apagado citando WCAG

- **Dónde:** `public_html/wp-content/plugins/akibara-business/assets/js/announcement-bar.js:98-134 contra public_html/wp-content/plugi…`  ·  **Severidad si fuera defecto:** baja  ·  **Área:** biz-precios-panel-textos-home
- **Qué pasa hoy:** WCAG 2.2.2 pide que todo movimiento automático de más de cinco segundos se pueda pausar, detener u ocultar con un control. La barra si se detiene con el puntero encima, con el foco dentro y para siempre en cuanto el cliente toca la flecha o hace swipe —lo cual es discutiblemente suficiente—, pero no ofrece un control explícito y su único botón dice «Siguiente», no «Pausar». Ademas la rotacion no es configurable desde el panel y el intervalo de 6 s esta escrito en el JS, mientras que el del hero si se edita. Es una inconsistencia de criterio entre dos carruseles del mismo sitio.
- **Por qué es tuya y no del código:** No es un descuido: la pausa por puntero, por foco, por pestaña oculta y la rendición permanente al primer gesto están escritas y razonadas, y con prefers-reduced-motion la barra ni arranca. Queda una pregunta de criterio para el dueño, no para desarrollo: si la barra debe ganar intervalo editable y un control explícito de pausa como el hero, o si se queda así por la otra regla de oro escrita («LÍMITE de consultor: no sobre-configurar», memoria regla-todo-operable-sin-codigo).

### S-3778c7df · La parte HTML del correo de novedades ensena precio y la parte de texto plano no

- **Dónde:** `templates/emails/customer-series-follow.php:95-104 frente a templates/emails/plain/customer-series-follow.php:53-54`  ·  **Severidad si fuera defecto:** baja  ·  **Área:** biz-seo-correo
- **Qué pasa hoy:** Las dos mitades del mismo multipart dicen cosas distintas. En el correo de vuelta a stock la casa decidio lo contrario y lo dejo escrito: «El precio va tambien aqui: la mitad HTML lo muestra y esta se lo dejaba, asi que quien lee el correo en texto plano tenia que abrir la ficha para saber cuanto vale» (plain/customer-bis-instock.php:30-34). Es una decision consciente pero incoherente entre dos correos hermanos: conviene que la confirme el dueno.
- **Por qué es tuya y no del código:** La ausencia es deliberada («La parte plana no imprime precio, hoy tampoco») y QA la tuvo delante al cerrar, pero nadie escribió POR QUÉ aquí sí y en vuelta-a-stock no. Es copy que lee el cliente y toca la regla escrita de la casa sobre barrer todas las superficies de un texto: la decide el dueño, no desarrollo. No es bug de código.

### S-9c027302 · La negociacion Markdown no excluye carrito, checkout ni mi-cuenta: un agente invitado recibe el render de los bloques como texto

- **Dónde:** `src/Modules/AgentReadiness/MarkdownNegotiation.php:206-228 y :359-374, :460-471`  ·  **Severidad si fuera defecto:** baja  ·  **Área:** biz-seo-correo
- **Qué pasa hoy:** Las paginas de carrito, finalizar-compra y mi-cuenta son `is_singular()` y para un visitante no logueado entran en la rama de prosa. Un agente con `Accept: text/markdown` recibe el render server-side de los bloques de carrito/checkout convertido a texto: no filtra datos de nadie (la sesion es de invitado) pero es una superficie publica que nadie diseno, imposible de revisar, y que un modelo puede citar como si fuera contenido editorial de la tienda.

### S-5f2a8745 · La clave de Google Maps viaja en el HTML de cada pago

- **Dónde:** `src/AddressIntegration.php:119-141`  ·  **Severidad si fuera defecto:** baja  ·  **Área:** checkout-blocks
- **Qué pasa hoy:** Es inevitable con la API de navegador de Google —la clave tiene que estar en el cliente— pero significa que la única protección posible es la restricción por referente HTTP en la consola de Google. Si esa restricción no está puesta, cualquiera puede copiar la clave del HTML y gastar la cuota del dueño. El código no puede comprobarlo; es una verificación de configuración que conviene dejar escrita.
- **Por qué es tuya y no del código:** Que la clave viaje en el HTML no es un fallo: es exactamente la clasificación escrita de la casa, con su contrato en código y su gate en el despliegue. Lo único que queda —confirmar en la consola de Google Cloud que esa clave tiene restricción de referente a akibara.cl y está limitada a Places— no se puede comprobar desde el código y es una acción del dueño en una consola de terceros. Lo dejo escrito como tal, no como bug.

### S-f9886c39 · Dos crons diarios pesados quedaron agendados al mismo instante

- **Dónde:** `plugins/akibara-business/src/Modules/OrderPreventa/ReadyToShipCron.php:94-96 y UpsellsAutofill/UpsellsAutofill.php:97-99`  ·  **Severidad si fuera defecto:** baja  ·  **Área:** cross-cron
- **Qué pasa hoy:** Los dos usan `time()` como ancla, así que su hora la decide el instante en que se activo el plugin — y en produccion los dos cayeron en el mismo: la captura del 2026-09-11 muestra `akb_order_preventa_recompute_ready` y `akb_upsells_autofill_cron` con la MISMA próxima corrida (20 h 25 min). WP-Cron ejecuta los eventos vencidos de una tanda en la misma petición, así que un recorrido paginado de todas las ordenes con preventa y un relleno de hasta 100 productos con su purga de páginas comparten request. A esa hora (~19:30 de Chile) todavia hay gente comprando. Ninguno de los dos es configurable n…

### S-42c5fa82 · El correo de invitación a reseñar no es editable por el dueño

- **Dónde:** `plugins/akibara-rewards/src/Reviews/Invitations.php:245-280`  ·  **Severidad si fuera defecto:** baja  ·  **Área:** cross-cron
- **Qué pasa hoy:** El asunto, el encabezado y todo el cuerpo están a fuego en el código. El resto de correos transaccionales de la casa (preventa, lista de espera, tomo nuevo) son `WC_Email` con su formulario de asunto y encabezado en WooCommerce -> Correos electrónicos, y la pestana del panel enlaza a ese formulario. Este no: cambiar una coma exige un despliegue, lo que contradice la regla de que todo debe ser operable sin código. Es una decisión de producto, no un fallo técnico, y por eso va marcada como tal.
- **Por qué es tuya y no del código:** No hay decisión escrita ni a favor ni en contra de exponer el copy, así que es llamada del dueño, no defecto. Dos matices que cambian el encuadre: (1) no es un descuido puntual — los SIETE correos de rewards salen por Emails::send_branded() con asunto y cuerpo a fuego (Points/Emails.php:281, :331, :387, :506, Points/AdminAdjust.php:261, Reviews/Invitations.php:273); es la arquitectura del plugin, no un olvido en la invitación; (2) la invitación NO está sin mando: SettingsPage ya expone «Invitar a reseñar por correo» (interruptor), «Estado del pedido que dispara la invitación» y el plazo en días, más el párrafo de premio que se apaga con el carril de reseñas. Lo que falta es editar el texto. …

### S-0f97e3c2 · El hook akibara_mail_transport_blocked existe, esta documentado como 'para que algo REACCIONE al corte' y no lo escucha nadie

- **Dónde:** `mu-plugins/akibara-correo-smtp.php:162-195`  ·  **Severidad si fuera defecto:** baja  ·  **Área:** cross-interacciones
- **Qué pasa hoy:** El aviso existe, el candado anti-recursion que lo protege existe, y el oyente no. El unico camino por el que el dueno se entera de que el correo no sale sigue siendo entrar al escritorio y ver el aviso rojo. Si pasa tres dias sin entrar -que es exactamente lo que paso en agosto de 2026- no se entera. No es un fallo de correccion, es una pieza a medio construir que el codigo presenta como terminada.
- **Por qué es tuya y no del código:** El hecho está confirmado en el código del snapshot: akibara-correo-smtp.php:194 emite la acción y el único add_action del fichero para avisar es `admin_notices` (:83, render_outage_notice :228); no hay ni un oyente de `akibara_mail_transport_blocked` en todo el árbol. Pero lo que falta NO es una línea de código: es elegir por qué canal quiere enterarse el dueño de que el correo no sale. El correo está descartado por construcción (el propio comentario :162-165 explica la recursión y por eso existe el candado `self::$recording`), WhatsApp está vetado como canal automático, y el dueño ya dejó escrito que rechaza las reparaciones mudas (retiró el cron de 2026-08-23 precisamente porque avisaba a …

### S-718f3fa0 · akb_rewards_migration_epoch decide qué puntos heredados vencen y no tiene pantalla

- **Dónde:** `src/Points/Migration.php:349 leída en src/Points/Expiry.php:134-138`  ·  **Severidad si fuera defecto:** baja  ·  **Área:** rewards-puntos
- **Qué pasa hoy:** Es una option invisible que gobierna dinero del cliente: si se pierde o se escribe mal, o todos los puntos heredados quedan protegidos para siempre (por el valor de reserva 9999-12-31) o vencen en masa en la siguiente corrida nocturna. La regla de oro de la casa es cero options invisibles; como mínimo debería verse en una línea de sólo lectura en la pantalla de ajustes, junto a los meses de vencimiento.
- **Por qué es tuya y no del código:** La mitad alarmante de la sospecha queda refutada por escrito: el valor de reserva `9999-12-31` es un fallo-hacia-no-quitar DELIBERADO y documentado, y el escenario «vencen en masa» es precisamente lo que la época existe para impedir. Lo que queda es una petición legítima de visibilidad, y esa sí choca con la regla de oro del dueño — aunque la época no es una palanca de negocio que él quiera tocar por temporada (la sella la migración una sola vez), gobierna dinero del cliente y hoy no se ve en ninguna pantalla. Mi recomendación al dueño: una línea de SÓLO LECTURA en WooCommerce → Puntos y Reseñas junto a los meses de vencimiento («Los puntos migrados envejecen desde: <fecha>»), nunca un campo…

### S-8fb6422d · Vencer puntos no manda ningún correo; sólo existe el aviso previo, que puede no haber salido nunca

- **Dónde:** `src/Points/Expiry.php:341-343`  ·  **Severidad si fuera defecto:** baja  ·  **Área:** rewards-puntos
- **Qué pasa hoy:** No hay ningún do_action ni envío en el camino del vencimiento efectivo. El aviso previo sale como máximo una vez al mes y sólo si el transporte de correo respondió; si ese mes el SMTP estaba caído —ya pasó tres días seguidos en agosto—, el cliente pierde sus puntos sin haber recibido ninguna advertencia y sin ningún correo que se lo cuente después. Va como sospecha y no como regla porque en el código no hay ninguna decisión escrita que diga «al vencer no se avisa»: simplemente no existe el camino.
- **Por qué es tuya y no del código:** El hecho es cierto —no hay `do_action` ni correo en el camino del vencimiento efectivo— pero el modelo «aviso ANTES, no después» es una decisión de diseño escrita y contrastada contra la competencia, no un olvido. El escenario de daño que se propone, en cambio, está refutado por el propio código: el marcador `_akb_rewards_expiry_warned` lo sella EL CORREO sólo con transporte confirmado, precisamente porque sellarlo antes «perdía el aviso para siempre si el SMTP estaba caído»; con la ventana por defecto de 30 días, tres días de SMTP muerto dejan 27 reintentos. Añade a eso que la caducidad nunca ha vencido un solo punto en producción y que la época de migración es del 2026-08-31 con 12 meses d…

### S-434201fb · Cuatro options de control no tienen ninguna pantalla y sus umbrales sólo se cambian tocando código

- **Dónde:** `src/RebuildJob.php:738 y :741 (ALARM_AFTER_SECONDS, akb_search_serving_alarm), src/EditorialDiscountPublication.php:1892…`  ·  **Severidad si fuera defecto:** baja  ·  **Área:** search-indice
- **Qué pasa hoy:** La REGLA DE ORO del dueño es «todo operable sin código: cero options invisibles». Estas cuatro son estado interno, lo cual es defendible, pero los NÚMEROS que gobiernan cuándo le llega un correo (900 s), cuánto se aguanta antes de reconstruir (600 s), cada cuánto vigila el sistema (900 s) y cuántos productos se revisan al azar por día (10) no están en ninguna pantalla. El dueño no puede decir «avísame antes» ni «revisa 50 en vez de 10» sin pedir un despliegue. Como mínimo, el panel debería MOSTRAR los valores vigentes en «Diagnóstico técnico».
- **Por qué es tuya y no del código:** Verificado: `WATCHDOG_INTERVAL=900` (RebuildJob.php:78), `ALARM_AFTER_SECONDS=900` (:738), `REBUILD_DEFER_SECONDS=600` (EditorialDiscountPublication.php:1892) y `SPOT_CHECK_SIZE=10` (Reconciler.php:56) son CONSTANTES de clase, no options; las dos options citadas (`akb_search_serving_alarm`, `akb_search_reproof_deferred_since`) son estado interno del motor —el propio hallazgo lo concede— y la regla de oro sólo obliga a dar pantalla a options de COMPORTAMIENTO o TEXTO. Son cadencias de vigilancia y muestreo, la familia que la regla nombra como contrato técnico. La parte defendible del hallazgo —«como mínimo el panel debería MOSTRAR los valores vigentes en Diagnóstico técnico»— es exactamente u…

### S-313f6b0f · Tres de los cuatro sellos de confianza no son editables por el dueño

- **Dónde:** `public_html/wp-content/themes/akibara-gp/inc/trust-badges.php:29-48`  ·  **Severidad si fuera defecto:** baja  ·  **Área:** theme
- **Qué pasa hoy:** Sólo la promesa de cuotas pasa por una option. Los otros tres textos —y la URL /embalaje-seguro/— están escritos en código, y aparecen en la ficha de cada producto, en el carrito vacío y en la banda del pie de TODAS las páginas. Son promesas comerciales («envíos a todo Chile», «atención por WhatsApp»), no etiquetas de interfaz: cambian con la operación. La regla de oro del dueño («cero options invisibles, todo operable sin código») apunta en la dirección contraria. El plugin ya tiene el sitio donde vivirían: Akibara → Textos.
- **Por qué es tuya y no del código:** El hallazgo es correcto en el diagnóstico y redundante como hallazgo: la no-editabilidad de los tres sellos está censada, priorizada y con destino de implementación escrito desde el 2026-09-01, incluida la corrección de que la fuente canónica debe ser CheckoutTrustSignals::all() (hoy el checkout dice «Atención personalizada» y la ficha «Atención por WhatsApp» — dos redacciones del mismo sello). También es falso el matiz de que «sólo la promesa de cuotas pasa por una option» como si eso fuera casualidad: ese cableado es el ítem cerrado del LOTE 1. Recomendación: no abrir defecto nuevo; encadenarlo al LOTE 2 (akb_copy_promise_shipping + akb_copy_promise_support, y la URL /embalaje-seguro/ como…

### R4-avisame-por-variacion · «Avísame cuando vuelva» avisa por producto, no por variación (ronda 4, 2026-09-19)

- **Dónde:** `akibara-business/src/Modules/BackInStock/` (formulario, REST y despachador).  ·  **Severidad si fuera defecto:** media  ·  **Área:** avisos de stock
- **Qué pasa hoy:** nada: el catálogo no tiene ningún producto con variaciones (**medido en producción el 2026-09-19: 0 variables, 0 variaciones**; todo son tomos simples). Pero si algún día publicas un producto con variaciones —«tapa dura» y «tapa blanda» del mismo tomo—, el formulario guarda el aviso contra el producto entero, y WooCommerce marca el producto como «disponible» en cuanto CUALQUIER variación tiene stock: el cliente que pidió la tapa dura recibiría «está de vuelta» al reponerse la blanda.
- **Por qué es tuya y no del código:** construir la captura por variación para un tipo de producto que la tienda no vende es código que nadie ejercita. Es barato hacerlo el día que haga falta y caro mantenerlo sin uso.
- **Recomendación:** no hacer nada ahora. Si vas a vender productos con variaciones, avísalo antes de publicarlos: son unas cuarenta líneas (el formulario manda la variación elegida; el resto del flujo ya escucha el stock por variación).

---

### R3-reenvio-aviso-de-fecha · Un aviso de cambio de fecha que no salió no se puede reenviar desde el panel (ronda 3, 2026-09-19)

- **Dónde:** `OrderPreventa\ReminderEmail` y el panel de un pedido (acciones de pedido).  ·  **Severidad si fuera defecto:** baja  ·  **Área:** preventa / correo
- **Qué pasa hoy:** desde la 1.116.27, si el correo «Akibara · Preventa recordatorio» está apagado cuando una editorial mueve una fecha, el pedido deja la nota «Email de cambio de fecha NO enviado al cliente». Reencender el correo no reenvía ese aviso: sólo el SIGUIENTE cambio de fecha se avisa. Hoy existe «Reenviar recordatorio» para el aviso de llegada, pero no para el de cambio de fecha.
- **Por qué es tuya y no del código:** reenviar es escribirle a un cliente, y a quién se le escribe y cuándo lo decides tú. Un reenvío automático al reencender podría mandar de golpe avisos viejos de fechas que ya volvieron a cambiar.
- **Recomendación:** si alguna vez apagas ese correo, revisa después los pedidos con esa nota (se buscan por el texto «NO enviado al cliente») y avísales tú. Si te pasa más de una vez, se añade una acción «Reenviar aviso de cambio de fecha» en el pedido: son unas treinta líneas. De paso: el tablero «Lo que estás esperando» no muestra la señal «reembolso 100%» que sí muestra el listado de pedidos; si operas más desde ese tablero, dilo y se añade.
- **✅ DECIDIDO y HECHO (2026-09-20 16:00 UTC): elegiste la acción «Reenviar aviso de cambio de fecha», «lo más sólido posible», mirando antes a la competencia.** Lo que hace la competencia (leído en su código, plugins descargados al scratchpad sin instalar): *Pre-Orders for WooCommerce* (Bright Plugins, 2.3.1) **no tiene ningún correo de cambio de fecha** —guardar la fecha sólo actualiza un meta, sin comparar, sin correo, sin nota— ni acción de pedido alguna, y marca «enviado» fuera del punto de envío; *YITH Pre-Order* libre (3.19.0) tampoco: dispara `yith_ywpo_pre_order_date_changed` **sin oyentes** (el correo vive en el Premium), sin acciones de pedido ni notas, y con un guard que aborta el resto de líneas; *WooCommerce* núcleo ofrece «Enviar detalles del pedido» en Acciones del pedido, pero **ignora que el correo esté apagado y escribe la nota a ciegas**. Es decir: nadie reenvía un aviso de fecha; lo único reutilizable es el mecanismo (`woocommerce_order_actions` + `woocommerce_order_action_{id}`) y, como anti-patrón, la nota escrita sin mirar el resultado. **Lo nuestro (business 1.116.38):** la acción aparece en el desplegable sólo cuando el pedido espera (estado de espera y correo del cliente), tiene líneas de preventa pendientes (vigentes y sin llegar, la misma lista que pinta el correo) y un cambio de fecha registrado que **sigue vigente** (`last_date_change()`: sólo la firma `old__new` más reciente, y sólo si su `new` coincide con la fecha actual de **exactamente una** línea pendiente; si la fecha volvió a moverse, se retiró, lo último fue una «fecha anunciada», o dos tomos comparten esa fecha y no se sabe cuál cambió, no hay nada honesto que reenviar y la acción no aparece). Al pulsarla suelta el tope de frecuencia y su contador (lo pides tú, la ventana no manda) y dispara el mismo evento del envío automático: pasa por la única puerta —correo apagado → nota «NO enviado… deshabilitado»; transporte caído → reintentos de siempre; salida → «Email de cambio de fecha enviado al cliente»—. Nunca finge: en un pedido cancelado, sin correo o sin cambio vigente deja una nota «NO posible: …» y no dispara nada (la revisión independiente cazó ese hueco; también cerró el mismo hueco en «Reenviar aviso previo a la llegada», que existía desde julio). Límite conocido: reenvía **el último** cambio del pedido, no un historial (las firmas no llevan producto). **Medido en el espejo** con la siembra de preventa: correo apagado + postergación → nota «NO enviado»; correo encendido → acción ofrecida; reenvío → correo en Mailpit («Actualización de tu preventa #… : nueva fecha») y notas; nueva postergación → la acción desaparece. Sin idempotencia entre clics, como los otros dos reenvíos: cada «Actualizar» con la acción elegida manda un correo. Candados: `ResendDateChangeActionTest` (8 casos) + `ReadyEmailDispatcherTest`. Revisión independiente: 11 observaciones (3 medias, cerradas: el handler comprobaba menos que el desplegable; «pendiente» vs «vigente»; dos tomos con la misma fecha), el resto de docs aplicadas. Manual: Acciones del pedido → «Reenviar aviso de cambio de fecha» → Actualizar.

---

### R3-89ab0284 · Un pedido de invitado que después se vincula a una cuenta no suma puntos (ronda 3, 2026-09-19)

- **Dónde:** `akibara-rewards/src/Points/Accrual.php` (`maybe_award()`), frente a `wc_update_new_customer_past_orders()` de WooCommerce.  ·  **Severidad si fuera defecto:** media  ·  **Área:** puntos / reglas del programa
- **Qué pasa hoy:** el programa acredita cuando el pedido llega al estado que fija el panel **y el pedido tiene cuenta**. Una compra hecha como invitado no suma («los puntos requieren cuenta»; la caja se lo dice al invitado y le ofrece crear la cuenta ahí mismo). Si esa persona crea la cuenta después y confirma su correo, WooCommerce le vincula los pedidos anteriores hechos con ese correo — aparecen en «Mis pedidos» — pero el estado del pedido no cambia, así que Rewards no vuelve a mirarlo: esa compra queda sin puntos, sin bono de bienvenida y sin premio para quien lo invitó.
- **Por qué es tuya y no del código:** acreditar hacia atrás es una regla del programa, no un arreglo. A favor: el cliente compró de verdad y es la misma persona (correo verificado). En contra: el bono de bienvenida dice «tu primera compra **con cuenta**», la caja ya le ofreció la cuenta y eligió no crearla, y abrir el abono retroactivo abre también una vía para cobrar bono y referido con pedidos antiguos. La regla «uno por persona» ya cuenta esos pedidos de invitado como compra previa (se compara correo, teléfono y RUT), así que hoy el comportamiento es coherente de punta a punta.
- **Recomendación:** dejarlo como está y decirlo en la página del programa («las compras hechas sin cuenta no suman puntos, aunque después crees una»). Si prefieres abonar hacia atrás, se engancha `woocommerce_update_new_customer_past_order` para re-evaluar SÓLO los puntos por compra (nunca bono ni referido) de pedidos dentro de la vigencia; es un cambio de unas veinte líneas con su prueba.
- **✅ DECIDIDO por el dueño (2026-09-20 15:20 UTC): se deja como está y SIN la frase.** Su razón: el programa suma desde que la persona crea su cuenta, y así es como está contado; no hace falta anunciar en negativo lo que no suma. Cero código, cero copy.

---

### R7-nota-mercado-pago · Cada pedido pagado con Mercado Pago le manda al cliente un correo «Nota para el cliente» de más (ronda 7, 2026-09-19)

- **Dónde:** tercero — `woocommerce-mercadopago/src/Order/OrderStatus.php:150-151` (dos `add_order_note('Mercado Pago: Pago aprobado.')`, la segunda como nota para el cliente)  ·  **Severidad si fuera defecto:** baja  ·  **Área:** correo-cliente
- **Qué pasa hoy:** Medido en producción: en todos los pedidos de Mercado Pago (#27973, #27974, #27978, #27979 y anteriores) hay dos notas idénticas en el mismo segundo y, 2-3 segundos después, «Se ha enviado el correo electrónico «Nota para el cliente»». Los 42 envíos de `customer_note` de la historia de la tienda cuelgan de un pedido de Mercado Pago; ninguno de una nota escrita por ti. El cliente recibe, justo después de «Procesando tu pedido», un segundo correo cuyo cuerpo entero es «Mercado Pago: Pago aprobado.». Con Flow no pasa.
- **Por qué es tuya y no del código:** Qué correos recibe un cliente es política comercial (regla de la casa: no se decide negocio solo). El arreglo es un mu-plugin de una pantalla —pegamento a Mercado Pago que muere con él—: filtrar `woocommerce_email_enabled_customer_note` para no mandar el correo cuando la nota empieza por «Mercado Pago: » (la nota sigue visible en Mi cuenta). Recomendación: hacerlo. Alternativa sin código: apagar «Nota para el cliente» en WooCommerce → Correos, pero eso también apagaría las notas que TÚ escribas al cliente.
- **✅ HECHO en producción (2026-09-20 14:40 UTC, por tu orden «haz tú mis pendientes», con la recomendación):** mu-plugin `akibara-mercadopago-nota-sin-correo.php`. El correo «Nota para el cliente» no sale cuando la nota empieza por «Mercado Pago: » (también la nota HTML de Pix, que lleva el prefijo dentro de la traducción); tus notas siguen saliendo; la nota sigue visible en Mi cuenta; si Mercado Pago no está, no engancha nada. Candado sin WordPress (14 casos) y prueba en la suite. Se ve en el registro de correos: desde hoy no debería aparecer ningún `customer_note` colgado de un pedido de Mercado Pago.

### R7-invitacion-disparador · La invitación a reseñar se dispara en «Completado» y tus pedidos terminan en «Listo para enviar» (ronda 7, 2026-09-19)

- **Dónde:** `akibara-rewards/src/Reviews/Invitations.php:67-69` (valor de fábrica `completed`) y Akibara → Puntos → Reseñas → «Estado del pedido que dispara la invitación»  ·  **Severidad si fuera defecto:** alta  ·  **Área:** rewards-resenas
- **Qué pasa hoy:** Medido en producción: 63 pedidos en «Listo para enviar», 11 completados (el último el 2026-08-25) y cero invitaciones a clientes reales en toda la historia (las dos únicas son tus pedidos de prueba). El disparador de fábrica es «Completado» y tus pedidos nunca llegan ahí: se quedan en «Listo para enviar» (el estado de Blue Express).
- **Por qué es tuya y no del código:** Cuándo pedir la reseña es decisión comercial, y el ajuste YA está en tu pantalla: el desplegable ofrece los estados reales del sitio, incluido «Listo para enviar», y «Días de espera antes de invitar» (0-30, de fábrica 3) cuenta desde que el pedido entra a ese estado. Desde rewards 0.4.15 la pantalla enseña debajo del campo cuántos pedidos hay en cada estado en los últimos 90 días y avisa cuando el elegido no lo alcanza ninguno. Recomendación: elegir «Listo para enviar» y dejar la espera en 5-7 días (el tiempo de entrega de Blue Express), o marcar los pedidos como Completado al entregarse. Hasta que lo cambies, la invitación no sale.
- **Lo que hace la competencia (leído en su código, 2026-09-19; descargados al scratchpad, sin instalar):** *Customer Reviews for WooCommerce* (CusRev, 80.000 instalaciones): un solo estado disparador configurable —pero sólo entre los que WooCommerce considera «pagados», sin los custom del sitio—, espera en días enteros 0-999 con **5 de fábrica**, un recordatorio por pedido, sin tope por cliente, y desprograma si el pedido se cancela o reembolsa; el segundo recordatorio, la hora del día y WhatsApp son Pro. *Customer Reviews Collector* (Trustindex, 5.000): estado configurable **con los custom del sitio**, espera de una lista cerrada (0, 1-7, 14, 21; de fábrica 7) y **tope por cliente** («cada N meses», 0 = cada pedido). *ReviewX* (7.000): sólo «Completed» o «Processing», espera 1-30, decide un servicio externo. *Photo Reviews* (10.000): estado clavado en Completed, espera con unidad libre (de fábrica ¡10 segundos!), sin baja ni tope por pedido. *Site Reviews* (60.000): sin recordatorio propio; usa el de WooCommerce. Y **WooCommerce mismo trae su «Solicitud de reseña»** (`WC_Email_Customer_Review_Request`, espera 1-60 días, 7 de fábrica, dispara en Completado): viene apagado de fábrica y en producción sigue apagado (sin option, cero envíos en el registro); si algún día se enciende en WooCommerce → Correos, los clientes recibirían dos invitaciones. Ninguno de los cinco barre los pedidos que ya estaban en el estado cuando se cambia el ajuste. Frente a eso, lo nuestro está a la par o por encima en disparador (estados custom), espera (0-30 desde el cambio de estado), un correo por pedido y baja; lo único que tiene alguien y nosotros no es el tope por cliente de Trustindex, que ya está registrado como decisión tuya (`S-02eca29f`).
- **✅ APLICADO en producción (2026-09-20 14:12 UTC, por tu orden «haz tú mis pendientes», con la recomendación de arriba):** `akb_rewards_invite_status = shipping-progress` («Listo para enviar») y `akb_rewards_invite_delay_days = 7` (el plazo de entrega de Blue Express; antes 3 desde «Completado»). Sin código: son las dos options de Akibara → Puntos → Reseñas. Desde ahora cada pedido que ENTRE a «Listo para enviar» recibe la invitación siete días después; los 49 que ya estaban en ese estado no se barren (ningún plugin del mercado lo hace, y invitar de golpe a pedidos viejos no lo lee nadie). Medible: `akb_rewards_invite_*` en `wp_options` y la cola de Action Scheduler del grupo de rewards en una semana.

### R8-fecha-google-a-pedido · ✅ RESUELTO el 2026-09-20 — Siete tomos «a pedido» con «Sin promesa de fecha» estaban rechazados por Google Shopping (ronda 8, 2026-09-20)

- **Decisión del dueño (2026-09-20):** «ve cómo lo hace damemimanga.cl y otras tiendas de la competencia y resuelve de la mejor manera». Mirado (sólo GET, unas pocas fichas): Dame Mi Manga, Bazar Historias y Mangas Friki venden «preventa/a pedido» siempre **con fecha por ciclo de cierre** (Bazar Historias: cierres los 5 y 20 de cada mes; Mangas Friki: cierres programados; Dame Mi Manga: fecha de llegada en la ficha) — que es lo que Akibara ya hace con el «a pedido» normal (`availabilityDate` = borde alto del rango). Los 7 rechazados son la excepción que tú marcaste «sin promesa». Google exige fecha para un `backorder` y admite `out_of_stock` sin fecha.
- **Resuelto en business 1.116.36:** un desplegable nuevo en **Akibara → Entregas → «Tomos "a pedido" sin promesa de fecha, para Google»** con tres opciones: **Agotado para Google (de fábrica, recomendado)** — el feed los marca agotados y salen de Shopping sin prometer nada; **Fecha del próximo pedido** — se publican con la fecha que tendría un «a pedido» normal; **Dejar con error** — el estado anterior. Sin option invisible: `Settings::google_sin_promesa()`, `GlaBridge::inject_availability_date()` (devuelve `availability=out_of_stock` en el array del filtro, el único camino que gana en los dos adaptadores de Google for WooCommerce; la revisión independiente cazó que la primera versión mutaba el adaptador de Content API, que ya no es el que arma el payload), prueba `GlaBridgeSinPromesaTest` contra un doble de cada adaptador. **Medido en producción el 2026-09-20 13:02 UTC:** resincronización de los 7 encolada y corrida a mano; `_wc_gla_sync_status` has-errors **7 → 0** (synced 1.571), `_wc_gla_errors` vacío; Merchant Center (consulta directa por la Merchant API a Frieren 05 y Rurouni Kenshin Kanzenban 04): actualizados 13:02:10 UTC, **aprobados en CL en los seis destinos**, sin problemas por ítem. Es decir: ya no estorban en Merchant Center y, como agotados, no salen en Shopping hasta que tengan promesa —exactamente lo que elegiste. Desde business 1.116.40 el mismo desplegable gobierna también las **preventas sin fecha confirmada** (tba): tampoco tienen fecha que darle a Google, y sin esto habrían ido al feed como `PreOrder` sin fecha (lo cazó la revisión de la ronda 14).
- **R8b ✅ CERRADO (2026-09-20 14:10 UTC, por tu orden «haz tú mis pendientes»):** apagada la actualización **estricta** de disponibilidad por la Merchant API (`PATCH accounts/v1/accounts/{id}/automaticImprovements?updateMask=itemUpdates`, con las otras tres mejoras tal como estaban: precio, disponibilidad no estricta y condición en `true`); releído: `accountItemUpdatesSettings.allowStrictAvailabilityUpdates = false` y el `effectiveAllowStrictAvailabilityUpdates` desapareció de la respuesta (= false). Google ya no volverá a subir a «en stock» un «a pedido»; Frieren 05 y Gachiakuta 10 volverán a `OUT_OF_STOCK` en su próximo rastreo. Lo que decía antes: **Residuo que sólo tú puedes cerrar (R8b, medido a las 13:10 UTC leyendo Merchant Center por la Merchant API):** de los 7, cinco están `OUT_OF_STOCK` como pediste, pero **Frieren 05 (14185) y Gachiakuta 10 (14239) figuran `IN_STOCK`**: Google los cambió solo con sus «Mejoras automáticas» (aviso `availability_updated`, NOT_IMPACTED: «se actualizó la disponibilidad para que coincida con la página de destino»; seis veces en cada uno, ya ocurría antes del arreglo). La cuenta tiene `allowAvailabilityUpdates = true` **y `allowStrictAvailabilityUpdates = true`** (la variante «estricta» es la que permite subir a «en stock», no sólo bajar a agotado). La ficha dice lo honesto —`BackOrder` en el schema, botón «A pedido»— y Google lo lee como comprable. No hay arreglo de código honesto (declarar `OutOfStock` en una ficha que vende sería mentirle a Google y a los rich results). Lo que decides tú, en **Merchant Center → Productos → Automatizaciones → Actualizaciones automáticas de artículos**: apagar la actualización **estricta** de disponibilidad (Google seguiría bajando a agotado lo que la ficha diga agotado, pero dejaría de subir a «en stock» un «a pedido») — afecta a todo el catálogo, por eso es tuya; o dejarlo como está y aceptar que los «sin promesa» puedan salir como «en stock» cuando Google los vuelva a rastrear. Es un ajuste de cuenta de Google: no lo toco yo. Mientras tanto la tabla local `wp_gla_merchant_issues` seguirá mostrando los `presync_error_availabilityDate` viejos hasta que corra `gla/jobs/update_merchant_product_statuses` (diario, 00:30 UTC).

- **Dónde:** `akibara-business/src/Modules/EstimatedDelivery/GlaBridge.php` (reglas 5 y 6 del docblock: «si DateCalculator devuelve null → no tocar», «si TBA → dejamos el error visible para intervención manual») y la ficha de producto → «Fecha estimada» → **«Sin promesa de fecha (no mostrar plazos en la ficha)»**  ·  **Severidad si fuera defecto:** alta  ·  **Área:** entregas / Google Merchant
- **Qué pasa hoy:** Medido en producción el 2026-09-20 00:30: Merchant Center tiene 7 productos con `presync_error_availabilityDate` («Availability date is required if you set the product's availability to backorder or pre-order»), y son exactamente los 7 tomos «a pedido» (`onbackorder`) con el interruptor en «Sin promesa de fecha»: 14185 Frieren 05, 14191 Frieren 07, 14236 Gachiakuta 09, 14239 Gachiakuta 10, 14783 One Piece 059, 24551 Frieren 15, 27811 Rurouni Kenshin Ed. Kanzenban 04. `_wc_gla_sync_status`: has-errors 7 · pending 69 · synced 1.564. En la tienda se venden con normalidad (precio de reserva, sin bloque de fecha); en Google Shopping **no salen**. El `availability=backorder` lo deriva Google for WooCommerce del `stock_status`, no nuestro código; lo que falta es la fecha, y nuestro puente la omite a propósito cuando tú dijiste «sin promesa».
- **Por qué es tuya y no del código:** El puente hace lo que su contrato escribió: sin promesa de fecha, no inventar una para Google. Cambiar eso es decidir **qué le decimos a Google de un tomo cuya llegada no queremos prometer**, y esa promesa es comercial, no técnica. Las tres salidas honestas:
  1. **Marcarlos como «agotado» para Google** (`availability=out_of_stock`, sin fecha): la ficha en la tienda no cambia, el feed vuelve a ser válido, y esos 7 dejan de aparecer en Shopping mientras no haya promesa. *Recomendada:* no promete nada que no prometas en la ficha.
  2. **Mandar igual la fecha del próximo pedido** (la misma que verían si el interruptor estuviera en «a pedido» normal): salen en Shopping, pero Google publica un plazo que la ficha calla; si el tomo se retrasa, el reclamo llega por Google.
  3. **Mandar una fecha lejana fija** (Google admite hasta un año): salen en Shopping como «disponible el …»; es una promesa inventada.
- **Qué haría el código si eliges (1) o (2):** un desplegable en Akibara → Entregas, «Cuando un tomo no tiene promesa de fecha, a Google se le dice…» con esas dos opciones (y «Nada: dejarlo con error», el estado de hoy, como tercera). Sin option invisible; medible en `wp_gla_merchant_issues` al día siguiente del cambio.
- **Mientras decides:** los 7 se pueden sacar del error hoy poniéndoles el interruptor en «a pedido» con fecha (si esa promesa es real) o quitándoles la sincronización a Google en la pestaña del producto.

### R9-editorial-ajena · ✅ DECIDIDO el 2026-09-20 (NO) — Quien busca «jujutsu norma» recibe cero aunque haya 31 tomos de Jujutsu Kaisen (ronda 9, 2026-09-20)

- **Decisión del dueño (2026-09-20):** «no tendremos por ahora tomos de Norma». No se amplía lo soltable a editoriales ajenas; el buscador sigue soltando sólo las editoriales del catálogo. Sin código. Si algún día se trabaja con Norma, entra sola como marca y el rescate ya funciona.

- **Dónde:** `akibara-search/src/BrandTokens.php:151-162` (`brand_names()`: sólo son «soltables» las editoriales que la tienda vende, hoy 8 términos `product_brand`) y `QueryEngine.php:269` (con cero resultados se suelta LA EDITORIAL y se reintenta, con el aviso «No tenemos X de Ivrea. Esto es lo que tenemos de X»).  ·  **Severidad si fuera defecto:** media  ·  **Área:** buscador
- **Qué pasa hoy:** Medido en el registro real de consultas: `jujutsu norma` (28-ago), `frieren preludio` (18-ago), `mushoku tensei novela` (1-sep) → 0 resultados con 31, 15 y 20 tomos en catálogo. «jujutsu ivrea» sí se rescata (Ivrea está en catálogo); «jujutsu norma» no, porque Norma Editorial no es marca de la tienda y por eso no es soltable. Las otras dos no son editoriales: son palabras de más, y el AND estricto («cada palabra tiene que estar») es el precio deliberado del capítulo 09 («precio deliberado de la segunda condición»).
- **Por qué es tuya y no del código:** El buscador cumple su contrato escrito (capítulo 09: se suelta la editorial, acotada a las marcas del catálogo) y la cascada de relajación en `/tienda/` ya la fijaste tú en `S-46ab8fa3` (techo de intentos = min(10, tokens soltables) + 1). Ampliar lo soltable a **editoriales que la tienda NO trabaja** (Norma, Kamite, Distrito Manga, ECC, Viz, Kodansha, Yen Press…) es una regla de producto nueva: decide si quieres que «jujutsu norma» responda «No tenemos Jujutsu Kaisen de Norma; esto es lo que tenemos de Jujutsu Kaisen». *Recomendada:* sí, con la misma guarda 2 de hoy (no soltar la palabra si aparece en algún título, serie, autor o SKU) y con la lista **en el panel** (Buscador → Sinónimos → «Editoriales que no trabajamos»), para que la mantengas tú sin código. Las palabras de más que no son editorial («preludio», «novela») seguirían en cero: cambiar eso sería relajar el AND para todo el mundo, y esa decisión ya está tomada.
- **Qué haría el código si dices que sí:** una lista editable en el panel (con validador y vista previa, como los sinónimos), leída por `BrandTokens` como segunda fuente de tokens soltables; medible en Buscador → Búsquedas («¿La resuelve hoy el buscador?»).

### R11-casilla-en-el-correo · ✅ RESUELTO el 2026-09-20 — El correo de confirmación imprimía el párrafo entero de la casilla de ofertas como dato del pedido («…con el enlace que va en cada correo.: Sí») (ronda 11, 2026-09-20)

- **Decisión del dueño (2026-09-20):** «puedes acotarlo tú dentro de la etiqueta de Brevo» (opción 2). Hecho en producción a las 09:10 UTC: `displayOptInLabel` de la option `sendinblue_woocommerce_user_connection_settings` pasó de las 40 palabras a **«Quiero recibir por correo las ofertas y novedades de Akibara»** (sin «preventas», que la casa no usa; el bloque añade «(opcional)» solo). El correo dirá «Quiero recibir por correo las ofertas y novedades de Akibara: Sí». Sin código; el soporte e2e (`specs/soporte/casilla-de-ofertas.php`) lleva la misma etiqueta.

- **Dónde:** WooCommerce vuelca en «Información adicional» de los correos de pedido cada campo adicional del checkout con su etiqueta (`class-wc-emails.php`, `printf( '<li><strong>%s</strong>: %s</li>' …)`), y la etiqueta de la casilla de ofertas es TU copy de Brevo (WooCommerce → Brevo → ajustes de la casilla, option `sendinblue_woocommerce_user_connection_settings`): 40 palabras con interrogación y punto final.  ·  **Severidad si fuera defecto:** baja  ·  **Área:** correos / Brevo
- **Qué pasa hoy:** Medido en el espejo con Mailpit (dos correos, casilla marcada y sin marcar): bajo «Dirección de facturación» sale «**¿Esperando el próximo tomo? Recibe por correo las ofertas, novedades y preventas de Akibara. Es opcional y puedes darte de baja cuando quieras con el enlace que va en cada correo.**: Sí» (o «: No»), en HTML y en texto plano. Aplica igual en producción (mismo campo, misma etiqueta).
- **Por qué es tuya y no del código:** Ninguna línea propia lo emite ni promete lo contrario: la etiqueta es tu texto y quién la imprime es WooCommerce. Hay dos salidas y las dos son decisiones de copy: (1) **ocultar ese campo del correo** (un filtro `woocommerce_filter_fields_for_order_confirmation` en `MarketingConsent`: la prueba del consentimiento ya queda en la meta `_akb_marketing_consent` y en la nota privada del pedido, así que no se pierde nada) —*recomendada*—; o (2) **acortar la etiqueta en Brevo** a algo como «Ofertas y novedades por correo», con lo que el correo diría «Ofertas y novedades por correo: Sí» — pero ese mismo texto es el que el cliente lee junto a la casilla en el checkout, y hoy está escrito para convencer, no para etiquetar.
- **Qué haría el código si eliges (1):** el filtro, con su prueba; medible en el próximo correo de pedido del espejo (Mailpit) y de producción.

### N-guest-mode-recarga · ✅ RESUELTO el 2026-09-19 — A todo cliente nuevo la tienda se le cargaba DOS veces (LiteSpeed Guest Mode)

- **Decisión y cierre:** el dueño autorizó apagarlo el mismo 19 de septiembre. `guest` pasó de 1 a 0 (`wp litespeed-option set guest 0`) y se purgó LiteSpeed. **Medido en producción después:** el HTML ya no trae el guion `guest.vary` ni en el origen ni en el borde, la prueba `primera-visita.spec.mjs` exige UNA sola carga y pasa, y el e2e completo de sólo lectura da 129 verdes, 0 rojos. Para revertir: LiteSpeed Cache → General → Guest Mode → ON. Ojo: los *presets* de LiteSpeed lo vuelven a encender; si alguien aplica uno, esa prueba sale en rojo.

- **Dónde:** ajuste de LiteSpeed Cache → General → **Guest Mode** (hoy encendido; `guest_optm` apagado). No es código de Akibara.  ·  **Severidad si fuera defecto:** media  ·  **Área:** caché / terceros
- **Qué pasa hoy:** Quien llega sin la cookie `_lscache_vary` —o sea, todo cliente nuevo, y cualquiera que no haya entrado en dos días— recibe la página, su navegador pide `/wp-content/plugins/litespeed-cache/guest.vary.php`, LiteSpeed contesta `{"reload":"yes"}` y **la página se recarga entera**. Medido en producción el 2026-09-19 con un navegador limpio: 4 de 4 visitas a `/tienda/` navegan dos veces. Lo que el cliente tecleó en el buscador o desplazó en ese primer segundo se pierde, y la analítica ve dos cargas por visita nueva. Era además la causa de los rojos intermitentes del QA automático contra producción (`net::ERR_ABORTED`, «cero tarjetas»): la suite ahora entra como visitante que vuelve, y la primera visita tiene su propia prueba (`tools/akibara-e2e/specs/primera-visita.spec.mjs`), hoy en `fixme`.
- **Qué se gana con Guest Mode:** que la primera respuesta salga siempre de caché. Aquí eso ya lo hace Cloudflare, que guarda diez minutos todo el HTML de invitado: el beneficio es casi nulo y el costo es la recarga.
- **Por qué es tuya y no del código:** Es la configuración de caché de un plugin de terceros en producción, y el historial de esta tienda con esos interruptores (CSS Combine, Guest Optimization) aconseja que lo cambie quien puede mirar el resultado con calma. **Recomendación: apagarlo.** Un clic en LiteSpeed Cache → General → Guest Mode → OFF, «Guardar», y purgar todo. Se revierte con el mismo clic. Después se mide con `AKB_GUEST_MODE_APAGADO=1 npm run test:prod` (la prueba de primera visita deja de ser `fixme` y exige UNA sola carga).

---

Cuando decidas cualquiera de estas, se convierte en arreglo o en documentación, y se cierra con su medición en producción.


---

### Definición del dueño (2026-09-20) · «Preventa» y «a pedido» no son lo mismo

- **Preventa** es lo clásico: un tomo que **todavía no sale** de la editorial. Se reserva y llega cuando la editorial lo publique.
- **A pedido** es un tomo que la editorial **ya tiene disponible** pero nosotros no tenemos en stock: se pide en la próxima tanda.
- **Consecuencias aplicadas en business 1.116.40:** borrar la fecha de una preventa desde la ficha la deja como «preventa sin fecha confirmada» (antes caía a «a pedido» con la urgencia de la tanda de hoy); el resumen del carrito habla de «tu preventa» cuando lo que se espera son preventas (antes decía «todos los productos a pedido»); el correo de «fecha anunciada» sólo celebra cuando la línea se compró sin fecha. La agrupación de las dos cosas bajo la ruta `/a-pedido/` (S-02b2ed3e) sigue vigente: es una página, no el vocabulario de cada tomo.

---

### Definiciones del dueño (2026-10-21) · Pop-ups & Promociones, Best-Wins, Erradicación de Confirmshaming y Akibara Rewards

1. **Nombre del módulo en el panel: «Pop-ups & Promociones»**
   - El lateral del panel centralizado decía «Primera compra». Al integrar tanto el beneficio permanente de nuevos suscriptores (cupón dinámico por Brevo) como el «Popup Promocional de Temporada» (Cyber, Halloween, Aniversario, con código estático y copiado en 1 clic), se renombra oficialmente la pestaña a **«Pop-ups & Promociones»** dentro de la sección *Marketing & Promociones*.

2. **Erradicación del Confirmshaming por nota de confianza:**
   - La opción de «No, gracias · prefiero pagar precio normal» es redundante, genera fricción y degrada la experiencia de usuario. Se sustituye por una nota de confianza visible y no interactiva (`akb-welcome__trust-note`): *«Cero spam. Solo novedades, preventas y avisos de llegada del siguiente tomo de tu serie favorita. Puedes cancelar tu suscripción con 1 clic cuando quieras.»*.
   - El cierre del modal queda unificado y limpio exclusivamente en la cruz (`[X]`).

3. **Política Best-Wins sobre coste normal y preservación del cupón:**
   - El descuento del 10% sobre $25.000 se calcula siempre sobre el coste o precio base normal (`regular_price`).
   - Si un tomo tiene una oferta promocional igual o superior (ej. 20% OFF) o la cesta cuenta con un descuento mayor por «Arma tu Pack», el cupón de bienvenida no debe sustituir ni acumularse indebidamente con ese descuento.
   - Si el descuento existente es mayor en todo el carrito, el cupón se bloquea amigablemente con aviso explicativo y **no se consume (`usage_count = 0`)**, guardándose intacto para una compra futura y respaldado por el correo electrónico emitido por Brevo.

4. **Convivencia con Akibara Rewards (Puntos de Fidelidad):**
   - Siguiendo el estándar de la industria (Smile.io, Yotpo, LoyaltyLion, Sephora), la acumulación de puntos (2 puntos por cada $100 CLP) se liquida exclusivamente sobre el **dinero neto pagado** (`subtotal_after_discounts - points_redeemed`). Si una compra de $33.980 CLP usa el cupón de 10% (-$3.398 CLP), el cliente paga $30.582 CLP y acumula exactamente 612 puntos. Se elimina el doble subsidio y se respeta el margen comercial.

5. **Marca «Panini Latam», unificación de catálogo y atributo `pa_pais` invisible (2026-10-21):**
   - Para evitar la fragmentación y aislamiento del catálogo de Panini (ya que Panini Argentina distribuye e imprime títulos en México, y la llegada de la línea Omnini):
     - La marca editorial (`product_brand`, ID 263) pasa a llamarse **«Panini Latam»** (`panini-latam`), unificando los 372 productos bajo un mismo paraguas respetado por la comunidad.
     - La landing de familia `/panini/` (`BrandLandings.php`) vincula a `panini-latam` y `panini-espana`.
     - En las series (`pa_serie`), se preserva la editorial pero se elimina el país individual: `Jujutsu Kaisen (Panini Latam)` vs `Jujutsu Kaisen (Panini España)`.
     - El atributo de producto `pa_pais` se conserva exclusivamente como motor logístico interno para el cálculo de plazos de entrega (`EstimatedDelivery`) y preventas (`PedidoPolicy`), creándose el término `México` (`mexico`, ID 791, 12-18 días de tránsito).
     - La visibilidad de `pa_pais` en la ficha pública fue desactivada masivamente (`is_visible = 0`) en 1.621 productos mediante `tools/make-pa-pais-invisible.php`, y `PdpEnhancer.php` respeta `! $attr->get_visible()`.
     - En `BrandRegion.php`, se formalizó `REGION_LATAM = 'latam'` con insignia `Latam` (`badge_text => 'Latam'`, color rojo `--aki-red`). El carrusel de la home (`home-carousel.php`) muestra limpiamente **`Panini • Latam`** (en lugar de `AR`), y el subtítulo de la vitrina se actualizó a «Importamos ediciones oficiales en español desde España y Latinoamérica».
     - En `FacetRegistry.php` (`akibara-search`), se retiró la faceta `pais` del seed de búsqueda, eliminándola del drawer de filtros del catálogo/tienda y manteniendo el 100% de la suite de tests en verde (891 PHPUnit + 28 JS).

