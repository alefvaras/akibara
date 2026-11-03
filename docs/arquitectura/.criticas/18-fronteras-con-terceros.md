# Crítica de lector novato — `18-fronteras-con-terceros.md`

> Quién escribe: alguien que llega hoy al proyecto y no sabe nada de WordPress, de
> WooCommerce ni del negocio. Leí el capítulo entero (1.702 líneas), los siete diagramas y
> después abrí el código del snapshot para contrastar lo que no me cuadraba.
> Snapshot: `/private/tmp/claude-501/-Users-alefvaras-Documents-akibara/8c4c125d-9a65-4e3c-85b0-896ede88c011/scratchpad/snapshot-prod`.
> Área de contraste: `mapas-b/cross-integraciones/verificacion.json`.
>
> Balance honesto antes de la lista: es un capítulo bueno. Las correcciones al mapa están
> aplicadas y señaladas, las citas `fichero:línea` que comprobé caen casi todas donde dicen,
> y las dos piezas nuevas (MCP de catálogo y de redirecciones) están bien contadas. Lo que
> sigue es lo que igual me dejó fuera.

---

## 1. Lo que NO entendí

**1.1 — El glosario está al final y nadie me avisa.**
Frase: «se le habla por los puntos de enganche que él mismo publica (*hooks*)» (línea 27).
A partir de ahí, y durante 1.600 líneas, el capítulo usa *hook*, *filtro*, *prioridad*,
*option*, *termmeta*, *fail-open* y *Store API* como si ya los supiera. El glosario que los
define existe, pero está en la línea 1.659.
Debería decir: después del primer párrafo, «Si alguna palabra técnica no se entiende, está
definida en el glosario del final», o mejor, mover el glosario a continuación de «Para qué
existe».

**1.2 — «Esos tres» nombres de Clarity nunca se dicen.**
Frase: «el mapa decía que a Clarity van "sólo tres nombres de ciclo de vida". Esos tres son
únicamente el `initialEvent` que calcula el PHP» (pieza 18).
No puedo saber cuáles son los tres: el capítulo nunca los nombra, y «el mapa» es un
documento que yo no he visto. En el código son `purchase`, `begin_checkout` y `view_product`
(`FrontendCompatibility.php:135-141`).
Debería decir: «El PHP calcula un único evento inicial según la página: `purchase` en la
página de gracias, `begin_checkout` en el checkout, `view_product` en una ficha».

**1.3 — «Observadores» de Mercado Pago: no es una palabra de este mundo.**
Frase: «retira **quince** observadores del checkout clásico, por nombre literal y no por
prefijo» (pieza 6).
Un «observador» no es nada en WordPress. Lo que hay en el código es una lista de quince
*handles* de script (`RuntimeCompatibility.php:55-71`), es decir, nombres con los que
WordPress identifica cada fichero JavaScript en la cola.
Debería decir: «retira quince scripts del checkout antiguo, identificados por su nombre
exacto en la cola de WordPress (*handle*) y no por prefijo, para no llevarse los dos que sí
hacen falta».

**1.4 — «Arrendamiento», «reclamar el checkout», «liberar la reclamación».**
Frases: «abre un arrendamiento en el guard de concurrencia» (Caso 3, paso 5); «revalida
todo, reclama el checkout» (paso 11); «el bloque `finally` libera la reclamación» (paso 12).
Tres nombres distintos para lo que parece ser el mismo candado, y ninguno explicado. No sé
si «arrendamiento» y «reclamación» son la misma cosa ni qué pasa si nadie la libera.
Debería decir, una vez y en castellano: «toma un candado con caducidad para que dos pestañas
del mismo cliente no se pisen; el candado se suelta siempre, aunque el pago falle».

**1.5 — El `@graph` de Rank Math: seis etapas o nueve callbacks.**
El capítulo dice en la pieza 13 «`rank_math/json_ld` | 90 / 91 / 92 / 99 / 100 / 999» con
seis descripciones, y el Caso 8 repite «seis etapas». Pero en «Reglas de negocio» dice «El
orden de los **nueve** callbacks sobre el `@graph` es un contrato»
(`Utilities/SchemaGraph.php:48-63`). Seis y nueve no pueden ser los dos.
Debería decir el mismo número en los tres sitios, y aclarar si los otros tres callbacks
corren sobre otro hook (`rich_snippet_product_entity`, por ejemplo).

**1.6 — «Correios» aparece sin explicación y parece un error de tipeo.**
Frases: «Lee la option del proveedor `woocommerce_correios_settings`» (pieza 8), «lo guarda
con `wc_correios_updateTrackingCode()`» (Caso 4), ordermeta `_correios_tracking_code`.
El capítulo habla de Blue Express y de pronto todo se llama «correios», que es el correo de
Brasil. Pasé cinco minutos creyendo que me había equivocado de proveedor.
Debería decir, la primera vez: «El plugin de Blue Express se construyó sobre uno brasileño y
conserva sus nombres internos: todo lo que diga `correios` en este capítulo es Blue Express».

**1.7 — `CheckoutDataProvider` es un «contrato» que nunca se presenta.**
Frase: «exige que exista `BusinessPlugin` y que le entregue el contrato
`CheckoutDataProvider`» (línea 47).
No sé qué es un «contrato» aquí, qué datos entrega, ni qué pasa si el método existe pero
devuelve otra cosa. En el código son dos comprobaciones seguidas
(`akibara-cart-checkout-blocks/src/Plugin.php:30-44`).
Debería decir: «exige además que `akibara-business` esté cargado y exponga un objeto con los
datos del checkout (precios, estados, textos); si no lo expone, el plugin de carrito no
registra nada y el checkout queda con lo que traiga WooCommerce de fábrica».

**1.8 — El *upgrader* aparece como si ya lo conociera.**
Frase: «El respaldo semanal existe por si la actualización ocurre sin pasar por el *upgrader*
—que es como actualiza Hostinger—» (Caso 5).
El paréntesis no aclara: no sé si «como actualiza Hostinger» significa «pasando por el
upgrader» o «sin pasar por él» (es lo segundo).
Debería decir: «WordPress avisa con `upgrader_process_complete` sólo cuando el propio
WordPress instala el plugin. Hostinger a veces reemplaza los ficheros por fuera, y entonces
ese aviso no llega: para eso está el barrido semanal».

**1.9 — La pieza 17 se titula «…y purga» y no habla de ninguna purga.**
Título: «17. Cloudflare — cabeceras de borde, IP real y purga». La pieza cubre rangos, IP
real y dos cabeceras. La purga de Cloudflare se cuenta en la pieza 16 (vía
`cdn-cloudflare_clear`).
Debería decir: o quitar «y purga» del título, o cerrar la pieza 17 con una línea: «Cloudflare
no se purga directamente: se purga LiteSpeed y él propaga — ver pieza 16».

**1.10 — «Etiquetado» e «instrumentación» sin antecedente.**
Frase: «`woocommerce_store_api_checkout_order_processed` dispara el etiquetado, la
instrumentación, `Redemption::capture_from_order`…» (Caso 1, paso 11).
Las dos últimas tienen nombre de clase; las dos primeras son sustantivos sueltos que no
aparecen en ninguna otra parte del capítulo.
Debería decir qué módulo hace cada cosa, o borrarlas.

---

## 2. Lo que FALTA para operar o depurar esto sin ayuda

**2.1 — No hay una sola ruta al panel del dueño.**
El capítulo manda al dueño doce veces a «Akibara → Textos», «Akibara → Carrito», «Akibara →
Puntos», «Akibara → Descuentos», «Akibara → Dirección», «Akibara → Claves y accesos» y
«Centro de Mando de Akibara». Nunca dice que todo eso es la misma página con pestañas, ni cuál
es su dirección. (Es `/wp-admin/admin.php?page=akibara-panel`; con el slug corto `akibara` da
403 y parece un problema de permisos.)
Debería decir, una vez, al empezar las piezas: «Todas las pantallas "Akibara → X" son
pestañas de `/wp-admin/admin.php?page=akibara-panel`».

**2.2 — El grupo de la acción programada no está.**
Frase: «`akibara_bluex_log_retention_cleanup` | Action Scheduler, acción recurrente, **grupo
propio**» («Lo programado»). Para encontrarla en WooCommerce → Estado → Acciones programadas
hay que filtrar por grupo, y el grupo se llama `akibara-bluex`
(`akibara-bluex-log-retention.php:14`).
Debería decir el nombre del grupo. Y decir también que la semanal **no tiene grupo**, que es
justo lo que hace difícil encontrarla en esa pantalla.

**2.3 — `akibara_fsp_*` «(10 claves)» y sólo se nombran dos.**
«Datos que deja» dice «option | `akibara_fsp_*` (10 claves) | Akibara → panel | Umbrales,
interruptores y copys». Si mañana el envío gratis se comporta raro, no sé qué leer.
Debería listar las diez, aunque sea en una línea.

**2.4 — La frontera con LiteSpeed es mucho más grande de lo que dice la pieza 16.**
La pieza 16 nombra seis ficheros y siete hooks. En el snapshot, el código propio usa además
`litespeed_ucss_whitelist` (9 veces), `litespeed_control_set_nocache` (11),
`litespeed_purge_post`, `litespeed_purge_url`, `litespeed_control_set_ttl`,
`litespeed_control_finalize`, `litespeed_media_lazy_img_excludes` y `litespeed_optm_gm_js_exc`,
repartidos por unos treinta ficheros (`SeriesFacts`, `UpsellsAutofill`, `Wishlist`,
`HomeHero`, `BrandsDirectory`, `EditorialDiscountPublication`, `Reviews/Carousel`,
`themes/akibara-gp/inc/enqueue.php:548`…).
Debería decir al menos: «esta pieza cubre las barandas y la purga; el resto de la
conversación con LiteSpeed (qué páginas se declaran no-cacheables y qué clases CSS se
protegen del CSS crítico) vive repartido por módulo — ver capítulo X», con la lista de hooks.
Sin eso, quien depure «por qué esta página se cacheó» no tiene por dónde empezar.

**2.5 — `feed_fecha` es opcional y el capítulo la presenta como guarda siempre activa.**
Frase: «Listado caducado | `:29`, `:330-344` | Más de 10 días desde `feed_fecha` y aborta»
(pieza 14), y la regla «Un listado de la editorial con más de 10 días no manda sobre el
catálogo». En el código, `feed_fecha` **no está en `required`**
(`akibara-catalogo-abilities.php:288`, la lista es `editorial`, `items`, `expected_count`) y
la comprobación está dentro de `if ( '' !== $fecha )` (`:331`). Quien llame sin fecha se salta
la guarda entera.
Debería decir: «La guarda sólo existe si el llamador manda `feed_fecha`; omitirla la desactiva.
Es el agujero más barato de cerrar de toda la ability».

**2.6 — Las abilities no traen ningún ejemplo de llamada.**
Las piezas 14 y 15 dicen «No hay pantalla: se opera por MCP o por WP-CLI». No hay un solo
ejemplo de cómo se llama por WP-CLI, ni cómo se lee el plan en seco, ni cómo se pasa el
`expected_count` que después exige. La pieza más peligrosa del capítulo es la única sin
comando.
Debería traer dos líneas de ejemplo: la del ensayo en seco y la de la escritura.

**2.7 — Los avisos del escritorio no tienen texto ni sitio.**
Frase: «Dos avisos en el escritorio: uno rojo no descartable mientras el correo no sale, y uno
ámbar…» (pieza 20). No sé qué dicen, ni en qué pantalla salen, ni cómo se descarta el ámbar.
Los dos correos del vigía de Blue Express sí traen su asunto exacto: eso mismo hace falta aquí.

**2.8 — No se dice dónde se configura `admin_email`, que es el destinatario de las dos alarmas.**
Pieza 10: «Manda dos correos al `admin_email`». Si ese buzón está mal, la alarma de checkout
caído no llega a nadie y el capítulo mismo advierte que la marca se escribe igual.
Debería decir: «Ajustes → Generales → Dirección de correo electrónico de administración»,
y añadirlo a la lista de cosas que revisar.

**2.9 — El umbral de rechazos SMTP no dice cómo se reinicia.**
Frase: «hay que llegar a tres seguidos» (Caso 6, paso 6). El código aclara que cualquier envío
correcto borra el contador (`akibara-correo-smtp.php:40-45`). Sin eso, «tres seguidos» se puede
leer como «tres en total, alguna vez».
Debería decir: «tres rechazos seguidos sin un solo envío correcto de por medio; un envío bueno
pone el contador a cero».

**2.10 — Falta el componente `dequeue_classic_cart_empty_reload`.**
`RuntimeCompatibility::register()` engancha cinco callbacks a `wp_enqueue_scripts`
(`:119-123`) y el capítulo sólo cuenta cuatro. Falta
`dequeue_classic_cart_empty_reload` (`:147-151`), que retira `akibara-cart-empty-reload` en el
carrito de bloques.
Debería aparecer en la tabla de la pieza 6, aunque sea en una línea.

**2.11 — Falta `rtcamp.google_user_created`, que `verificacion.json` sí registra.**
El área documentó ese hook como palanca disponible y no usada
(`GuestOrderLinking/GuestOrderLinking.php:54`). El capítulo cuenta el botón de Google (pieza
19) pero no la vinculación de pedidos de invitado que cuelga del mismo tercero.
Debería añadirlo, aunque sea para decir «existe y no se usa; si alguien la usa, tocará este
otro módulo».

**2.12 — La pieza 3 nombra `mu-plugins/akibara-woo-blocks-i18n.php` y no lo explica nunca.**
Aparece en la lista de ficheros y luego en la tabla como «`load_script_translations` | 10 |
Arregla el catálogo de traducción para que no diga "un dirección de correo electrónico
válido"». No dice qué frase se sustituye ni por cuál. Es
`'Please enter a valid %s'` → `'El campo %s no es válido.'`
(`akibara-woo-blocks-i18n.php:26-28`), y el porqué está muy bien explicado en el propio
fichero: `%s` es la etiqueta del campo y no hay artículo que concuerde con todas.
Debería traer esas dos cadenas: es lo único que permite buscarlo si mañana vuelve a salir mal.

**2.13 — «Dieciocho mu-plugins» contra los «17 mu-plugins» de `CLAUDE.md`.**
El capítulo abre con «**dieciocho** mu-plugins» (en negrita, como si supiera que discute con
alguien) pero nunca dice con quién. `CLAUDE.md` —el primer documento que lee alguien que llega—
dice 17. Conté el snapshot: son 18.
Debería decir: «`CLAUDE.md` dice 17; el snapshot tiene 18 (`akibara-catalogo-abilities.php` es
el que falta en esa cuenta). Manda el snapshot».

---

## 3. Lo que CONTRADICE al código

**3.1 — El desencolado de Mercado Pago NO corre en el checkout, y el Caso 1 lo pone ahí.**
Frase (Caso 1, paso 3): «El cliente abre `/checkout/`… `dequeue_classic_mercado_pago_assets`
retira quince observadores del checkout clásico».
Código: `dequeue_classic_mercado_pago_assets()` empieza con
`if ( ! $this->blocks_page->is_cart() ) { return; }`
(`akibara-cart-checkout-blocks/src/RuntimeCompatibility.php:158-160`), y `is_cart()` exige
`is_cart()` de WooCommerce más el bloque `woocommerce/cart` en la página del carrito
(`BlocksPage.php:44-52`). En `/checkout/` ese método no retira nada.
El propio docblock del método lo dice: «Removes Mercado Pago's classic observers from the
Blocks **cart**» (`:154`).
Debería decir: en la pieza 6, «se aplica sólo en `/carrito/`»; y el Caso 1 debería sacar ese
paso del checkout o explicar que ocurre antes, cuando el cliente pasa por el carrito.
(El hermano `dequeue_classic_city_select` sí cubre carrito **y** checkout, `:236-241`: por eso
el contraste pasa desapercibido.)

**3.2 — «Retira quince» es un número que el código se niega expresamente a congelar.**
Frase: «retira **quince** observadores… (`RuntimeCompatibility.php:158`)».
Código: la lista tiene quince nombres, pero el bucle sólo cuenta y retira los que estén
realmente encolados, y el comentario avisa: «Cuántos se retiran HOY no se congela aquí: una
medición con fecha caduca y termina contradiciendo al contador» (`:172-174`).
Debería decir: «retira, de una lista de quince nombres, los que de verdad estén en la cola; el
número vigente lo publica la prueba de Salud del sitio».

**3.3 — La línea que ve el cliente no se llama «Canje de Puntos Akibara».**
Frase: «Qué ve el cliente. La línea «Canje de Puntos Akibara»…» (pieza 5).
Código: `public const FEE_NAME = 'Puntos Akibara';`
(`akibara-rewards/src/Points/Redemption.php:50`), usada tal cual en `add_fee()` (`:251`).
La cadena «Canje de Puntos» no existe en todo el snapshot.
Debería decir: «la línea **Puntos Akibara**, con importe negativo».

**3.4 — Los rangos de Cloudflare son quince IPv4, no catorce.**
Frase: «publica la lista canónica (14 rangos IPv4 y 7 IPv6)» (pieza 17).
Código: `CLOUDFLARE_RANGES` tiene **15** IPv4 (`RateLimit.php:329-343`) y 7 IPv6 (`:345-351`).
Debería decir 15 y 7. El total es 22.

**3.5 — Rewards precarga nueve options, no ocho.**
Frase: «Lee ocho options de configuración de puntos, precargadas en una sola consulta con
`wp_prime_option_caches`» (pieza 4).
Código: el array tiene nueve claves —la novena es `akb_rewards_welcome_teaser_enabled`—
(`akibara-rewards/src/Blocks/CheckoutIntegration.php:96-108`). El comentario del código dice
«las 8 options»: el capítulo copió el comentario, no contó el array.
Debería decir nueve.

**3.6 — La cita de las metas de PUDO apunta 27 líneas antes de donde se escriben.**
Frase: «Escribe en el pedido `agencyId`, `agencyName`, `agencyAddress`, `isPudoSelected`
(`PudoIntegration.php:714`)», repetida en el Caso 3 paso 11.
Código: las escrituras están en `:741-748`. En `:714` hay un bloque de comentario de tipos.
Además, `agencyName` y `agencyAddress` sólo se escriben si no vienen vacías (`:744`, `:747`) y
`isPudoSelected` no guarda un sí/no sino la cadena `'pudoShipping'` (`:742`) — dato que importa
si alguien consulta esa meta.
Debería citar `:741-748` y decir el valor real de `isPudoSelected`.

**3.7 — «El JavaScript emite diez eventos» y sólo se pueden contar ocho.**
Frase: «El JavaScript emite diez eventos y escribe dos etiquetas de sesión» (pieza 18),
seguida de «Además del evento inicial:» y siete nombres.
Código: el JS emite siete nombres propios —`add_to_cart`, `remove_from_cart`,
`coupon_applied`, `checkout_error`, `wishlist_add`, `payment_method_selected`,
`ai_referral_landing`— más el `initialEvent`, que puede ser uno de tres
(`clarity-commerce-events.js:456-507` y `FrontendCompatibility.php:135-141`). Siete más tres
posibles dan diez sólo si se cuentan los tres iniciales, cosa que el capítulo no dice porque
nunca los nombra (ver 1.2).
Debería decir: «siete eventos propios más el evento inicial, que puede ser `view_product`,
`begin_checkout` o `purchase`: diez nombres distintos en total».

**3.8 — Dos citas distintas para la misma regla de Blue Express.**
La pieza 8 cita la regla «región **y** comuna» en
`akibara-bluex-cotiza-solo-con-comuna.php:78` (que es el `add_filter`, `:78`) y «Reglas de
negocio» la cita en `:52-63` (que es la función que decide, correcto).
Debería usar `:52-63` en los dos sitios, y dejar `:78` sólo si quiere señalar dónde se
engancha.

**3.9 — «Veintitrés plantillas sustituidas» mezcla sustituciones con plantillas propias.**
Título de la pieza 2 y su lista: «nueve ficheros» del tema y «catorce» del plugin.
Código: los nueve del tema sí sustituyen plantillas de WooCommerce
(`themes/akibara-gp/woocommerce/`). De los catorce del plugin, ocho son los correos **propios**
de Akibara (`templates/emails/customer-bis-instock.php`, `customer-series-follow.php`,
`customer-preventa-ready.php`, `customer-preventa-reminder.php` y sus cuatro versiones en texto
plano), que no sustituyen nada: son plantillas nuevas de correos que WooCommerce no tiene.
Sustituciones reales: quince (nueve del tema + las seis de `templates/myaccount/`).
Debería decir: «quince plantillas del núcleo sustituidas y ocho plantillas propias de los
correos de la casa», porque el riesgo del que habla la pieza —«una plantilla copiada se queda
vieja en silencio»— sólo corre para las quince.

---

## 4. Lo que contradice o deja fuera a `verificacion.json` del área

**4.1 — La fecha de la política de privacidad, confirmada como defecto, desaparece del capítulo.**
`verificacion.json`, `sospechas_confirmadas_por_lectura` nº4: «La fecha de la política de
privacidad se anota en GMT y se pinta como fecha chilena sin convertir»
(`MarketingConsent.php:348-356` y `:366-369`). Lo comprobé: `policy_modified_gmt()` devuelve
`post_modified_gmt` tal cual y `note()` le da vuelta a `AAAA-MM-DD` con una expresión regular,
sin pasar por `get_date_from_gmt()`.
El capítulo cuenta la prueba de consentimiento en la pieza 12 y en dos reglas, y esto no
aparece. Es la prueba legal de qué aceptó el cliente: la fecha importa.
Debería aparecer como borde medido en la pieza 12.

**4.2 — El desfase de `strict_types` en `exclude_checkout_from_litespeed()` se cae del capítulo
justo donde el capítulo cuenta el defecto hermano.**
`verificacion.json`, confirmada nº3. El capítulo denuncia muy bien que
`akibara_litespeed_rutas_sin_cache()` borra la lista del sitio si el valor no llega como array,
y añade: «Los tres filtros hermanos de este mismo repositorio hacen `is_array($x) ? $x :
preg_split(...)` precisamente por esto» (pieza 16). Lo que no dice es que el cuarto hermano,
`RuntimeCompatibility::exclude_checkout_from_litespeed( array $uris )` (`:136`), está en un
fichero con `declare(strict_types=1)` (`:8`): si LiteSpeed le pasa una cadena, no degrada — es
un error fatal de PHP, y es el filtro que protege el checkout.
Debería ser el defecto nº3 de esa pieza, con su cita.

**4.3 — La consecuencia de `is_production_frontend()` fuera de una petición web no se cuenta.**
`verificacion.json`, confirmada nº7: ese método se apoya en `HTTP_HOST`, que no existe en cron
ni en WP-CLI, así que devuelve `false` aunque el sitio sea el real
(`FrontendCompatibility.php:202-210`), y uno de sus tres consumidores es
`disable_google_ads_tracking()`.
El capítulo usa el gate del host dos veces —pieza 18 («exige además el host canónico») y la
regla de Clarity— y nunca dice que el mismo gate apaga el seguimiento de Google Ads en todo lo
que corra fuera del navegador.
Debería aparecer como borde en la pieza 19, que es la de Google.

---

## 5. Diagramas

Los siete validan con `mmdc` sin errores ni avisos (`18-fronteras-con-terceros-piezas`,
`-cron`, `-estados-correo`, `-caso-pago-tarjeta`, `-caso-pudo`,
`-caso-integridad-bluex`, `-caso-mcp-disponibilidad`). Lo que sigue es de contenido.

**5.1 — `piezas.mmd`: las dos flechas de LiteSpeed están cambiadas de dueño.**
Dice `BUSINESS -->|"barandas: fija opciones peligrosas por filtro"| LS` y
`MUS -->|"exclusiones y purga por tag"| LS`.
Es al revés: las barandas viven en `mu-plugins/akibara-litespeed-guardrails.php` (MUS) y la
purga la programan `TaxonomyDiscount/LscPurger.php` (BUSINESS) y
`akibara-search/src/Support/RestCache.php` (SEARCH). Las exclusiones están repartidas entre los
tres.
Debería ser: `MUS -->|barandas + exclusión de /wp-json/| LS`,
`BUSINESS -->|purga total al guardar descuentos| LS`,
`SEARCH -->|purga por tag del índice| LS`.

**5.2 — `piezas.mmd`: falta Joinchat, que el texto sí trata como tercero de pleno derecho.**
La tabla «qué pasa si se apaga» tiene una fila de Joinchat, y la pieza 24 lleva su nombre en el
título. En el diagrama no hay nodo `Joinchat` ni flecha hacia él, y el teléfono de respaldo
`+56944242844` —que es lo que el cliente ve si el plugin desaparece— no aparece por ninguna
parte.
Debería añadirse un nodo `JOINCHAT["Joinchat<br/>botón de WhatsApp"]` en el subgrafo
`MARKETING` o en uno nuevo, con
`BUSINESS -->|"lee option joinchat.telephone, respaldo en código"| JOINCHAT`.

**5.3 — `piezas.mmd`: el nodo `SEARCH` queda casi mudo.**
Sólo tiene dos flechas (`class_exists WooCommerce` y `WC_Cache_Helper`). El texto le atribuye
además el `no-store` de sus respuestas REST (pieza 16 y 17), su propia copia del limitador por
IP con una divergencia medida (pieza 17) y el recibo `akb_search_rest_purge_receipt`.
Debería llevar `SEARCH -->|"no-store en sus respuestas REST + su copia del limitador"| CF`.

**5.4 — `caso-pago-tarjeta.mmd` repite el error 3.1.**
La línea `Blocks->>MP: dequeue_classic_mercado_pago_assets retira 15 observadores del checkout
clasico` está dentro de la secuencia que empieza con `Cliente->>Woo: abre /checkout/`. En el
código ese desencolado sólo corre en `/carrito/`.
Debería o sacarse del diagrama del checkout, o dibujarse antes, con una nota «ocurre en
/carrito/, no aquí».

**5.5 — `caso-mcp-disponibilidad.mmd`: tres `alt` sin `else` se leen como pasos obligatorios.**
Los bloques `alt la editorial es Panini Argentina`, `alt feed_fecha tiene mas de 10 dias` y
`alt cero agotados…` no tienen rama `else`. Mermaid los dibuja como cajas etiquetadas «alt», y
un lector nuevo los lee como «esto pasa siempre». Además el segundo esconde lo que descubrí en
2.5: esa guarda no corre si el llamador no manda `feed_fecha`.
Debería usar `opt` en vez de `alt` para las tres (que es lo que significan: pasos opcionales de
rechazo), y la de la fecha debería decir «si viene `feed_fecha` y tiene más de 10 días».

**5.6 — `caso-integridad-bluex.mmd`: el final se lee como si Blue siempre arreglara.**
Tras el `alt`/`else`, el diagrama sigue con `Note over Arbol: Blue Express publica el paquete
arreglado` y dos mensajes más, fuera de toda condición. Un novato lo lee como «al final del
proceso siempre llega el correo verde».
Debería envolver ese tramo en un `opt cuando Blue arregla su paquete`.

**5.7 — `cron.mmd`: la línea de la semanal dice a la vez «una vez por semana» y «por WP-CLI no
entra».**
El mismo bloque afirma que corre semanalmente y que probablemente no se programa nunca en
producción. Las dos frases están en el mismo nivel visual y se anulan.
Debería decir: «Semanal — **si llega a programarse**: sólo se programa desde `/wp-admin/` o
desde un cron que se identifique como tal; en producción el disparo entra por WP-CLI, donde
`wp_doing_cron()` es false».

**5.8 — Nota común a los seis diagramas de caso: ninguno dice qué es lo que dibuja.**
`sequenceDiagram`, `stateDiagram-v2` y `timeline` se leen distinto. Para el público declarado
—alguien que no sabe nada— una línea bajo cada imagen («cada columna es un programa; el tiempo
baja») costaría poco. El `%%` de cabecera de cada `.mmd` ya tiene una explicación excelente,
pero es un comentario: no se ve al renderizar.
Debería subirse ese texto al capítulo, como pie de cada imagen.

---

## Lo que sí funcionó (para que no se toque)

- El «qué pasa si se apaga cada tercero» es lo mejor del capítulo: es lo único que me dejó
  entender el sistema sin saber WordPress.
- Las «correcciones aplicadas» en recuadro, con el error del mapa citado, son honestas y
  enseñan más que la afirmación sola.
- Los dos defectos de LiteSpeed (pieza 16) y los tres de la ability de catálogo (pieza 14)
  están contados con la frase del código al lado: se pueden verificar sin preguntarle a nadie.
- La pieza 26 («dónde NO hay frontera») debería copiarse en todos los capítulos.

---

## Respuesta del redactor

Aplicados **todos** los puntos de las cinco secciones. Lo que sigue no discute ninguno: son
tres matices donde la medición me dio un número distinto del tuyo, y dos avisos sobre lo que
encontré de camino. En los cinco casos el capítulo quedó con el número medido, no con el
mío ni con el tuyo.

**Sobre 2.10 — «cinco callbacks a `wp_enqueue_scripts`».** Tenías razón en lo que importa:
faltaba `dequeue_classic_cart_empty_reload`, y ya está en la tabla de la pieza 6. El detalle:
`register()` engancha en `:119-123` **cuatro** callbacks a `wp_enqueue_scripts`
—`dequeue_classic_cart_empty_reload`, `dequeue_classic_mercado_pago_assets`,
`dequeue_classic_city_select` y `repair_flow_blocks_dependencies`— y el quinto, `:123`, es el
mismo `repair_flow_blocks_dependencies` colgado de **otro** hook,
`wp_print_footer_scripts`. Contar el rango de líneas y no los nombres de hook da cinco. La
tabla ahora dice el hook exacto de cada uno.

**Sobre 3.9 — «sustituciones reales: quince».** Son **catorce**. Las nueve del tema, sí; pero
de las seis de `templates/myaccount/`, sólo cinco sustituyen una plantilla del núcleo
—`dashboard.php`, `orders.php`, `my-address.php`, `form-edit-account.php` (`MyAccountUx.php:32-37`)
y `form-login.php` (`GoogleLoginButton.php:29`)—. La sexta, `collection.php`, **no sustituye
nada**: WooCommerce no tiene una plantilla de «Colección»; es la pantalla propia del endpoint
de Akibara, y la pinta `AccountCollection/Renderer.php:55` cargando el fichero directo, sin
pasar por `woocommerce_locate_template`. O sea: 14 sustituciones y 9 plantillas propias (los 8
correos de la casa más esa). El riesgo del que hablabas corre para las catorce, que es
exactamente tu argumento con un número corregido.

**Sobre 1.5 — «seis o nueve».** Ni seis ni nueve: **seis etapas** y **diez callbacks**, y no
se contradicen porque una etapa es un turno que varios módulos pueden compartir. Los conté
uno a uno: 90 (`ProductSchemaShopping`), 91 (`SeriesSchema`), 92 (`BrandLandingSchema` más
tres de `SeoEnhancements`), 99 (`PreventasRoute` y `DisponiblesRoute`), 100
(`SchemaProductRankMath`), 999 (`SeoEnhancements::cleanup_global_schema`). El docblock de
`SchemaGraph.php:10` dice «nueve» y también se quedó corto: `BrandLandingSchema` se sumó a la
etapa 92 sin actualizar la cuenta. Tu pregunta sobre `rich_snippet_product_entity` tenía
buena intuición pero no era por ahí: ese hook es otro, y sus dos callbacks ya estaban
contados aparte en la misma tabla. Lo que sí apareció buscándolo es un **undécimo** callback
que ninguno de los dos esperaba: `akibara-search` toca el mismo `@graph` a prioridad 20
—antes que todas las etapas— desde `frontend/facets-render.php:833`, fuera del contrato,
porque viaja en otro riel de despliegue. Está ahora en la pieza 13, en el caso 8 y en las
reglas de negocio.

**Un aviso sobre 2.6 (los ejemplos de llamada).** Los añadí, pero con dos advertencias que no
estaban en tu crítica y que cambian cómo se usan: la llamada por WP-CLI necesita `--user=`
—sin usuario, `current_user_can()` es falso y la ability se niega, cosa que parece un fallo y
no lo es— y el segundo comando **cambia precios publicados en producción**. Quedaron escritos
en ese orden y con esa advertencia encima.

**Un aviso sobre 2.13 (la cuenta de mu-plugins).** Escribí lo que dices —`CLAUDE.md` dice 17,
el snapshot tiene 18, manda el snapshot— y también cuál es el decimoctavo. No verifiqué de
dónde sale el 17 de `CLAUDE.md`, porque ese documento no es el snapshot y arreglarlo no es
trabajo de este capítulo; queda anotado en el recuadro de cabecera para que quien lo lea
primero no se quede con el número viejo.

**Sobre el punto 4.2, que me pareció el mejor de la crítica.** Tenías razón y el defecto es
peor de lo que lo describiste: el filtro hermano no sólo está en un fichero con
`strict_types`, es que **tipa el parámetro** (`exclude_checkout_from_litespeed( array $uris )`,
`:136`). El nº1 de esa pieza degrada —se queda sin lista y sigue—; este lanza un `TypeError`
fatal, y es el único de los cuatro cuyo fallo lo ve el cliente, en el checkout. Es el defecto
nº2 de la pieza 16, con su cita.

Los siete diagramas se volvieron a validar con `mmdc` tras los cambios: siete OK, sin errores
ni avisos.
