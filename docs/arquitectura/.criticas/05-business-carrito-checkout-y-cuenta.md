# Crítica de lector novato — `05-business-carrito-checkout-y-cuenta.md`

> Quién escribe: alguien que llega hoy al proyecto, no sabe qué es WordPress ni WooCommerce,
> y tiene que poder **operar y depurar** esta área sin preguntarle a nadie.
> Todo lo que aquí se afirma del código está contrastado contra el snapshot de producción
> (`snapshot-prod/plugins/akibara-business`, `…/akibara-rewards`, `…/akibara-cart-checkout-blocks`,
> `…/themes/akibara-gp`) y contra `mapas/biz-compra-cuenta/verificacion.json`.
>
> **Lo primero, para que no se pierda entre las quejas:** el capítulo es sólido. Comprobé una
> a una ~60 citas `fichero:línea` y casi todas dan exactamente en el blanco (`NativeCart.php:458-468`,
> `FreeShippingThreshold.php:497/509/517/523`, `PickupPointProvider.php:931-944`,
> `OrderTimeline.php:109/183/202/221/244`, `Security.php:448`, `MarketingConsent.php:153/201-204`,
> `RutValidation.php:49-52/70-82/93-104`, los ocho `do_action` comentados de `form-login.php`
> en 77/95/108/119/120/153/160/167, `PudoMutationGuard` con sus cinco fases reales). Las 20 marcas
> «⚠ Hoy no cuadra» son reales y reproducibles. Las críticas de abajo son de **bordes**: cuentas que
> no cuadran, nombres de pantalla que no existen, piezas del área que no se nombran y una promesa
> del prólogo que el capítulo no cumple.

---

## 1. Lo que NO entendí

**1.1 · «Store API» aparece como un instante mágico y nunca se dice por qué es el único.**
El capítulo dice (ficha 8): *«normaliza los dos teléfonos del pedido … **después** de crearse —hook
`woocommerce_store_api_checkout_order_processed`, prioridad 10—»*, y el glosario define
*«Store API `…_order_processed` — El instante exacto en que un pedido del checkout de bloques ya
existe»*. Lo que no se dice, y es justo lo que un novato necesita: **el checkout de bloques no
dispara los hooks clásicos del checkout** (`woocommerce_checkout_order_processed`,
`woocommerce_checkout_update_order_meta`), así que quien quiera colgar una regla nueva al crearse un
pedido y use el hook clásico verá que nunca corre y no tendrá ningún error que mirar.
**Debería decir:** «En esta tienda el checkout es de bloques; los hooks clásicos del checkout no se
emiten. El único punto fiable para "el pedido ya existe" es
`woocommerce_store_api_checkout_order_processed` (lo usan `PhoneValidation.php:29-34` y
`MarketingConsent.php:110`). Si tu código nuevo no corre, empieza por ahí.»

**1.2 · Conviven dos convenciones de AJAX sin explicar la diferencia, y son incompatibles.**
La ficha 1 registra `wc_ajax_akb_get_cart` / `…_update_cart_item` / `…_remove_cart_items`
(`NativeCart.php:61-65`) y afirma que el de lectura no lleva credencial *«porque sólo toca la sesión
de quien llama»*. Cuatro páginas más abajo, la ficha 5 usa `wp_ajax_akb_wishlist_toggle` **y**
`wp_ajax_nopriv_akb_wishlist_toggle` (`Wishlist/Ajax.php:28-29`). Un lector nuevo deduce —mal— que
`wc_ajax_` sin `nopriv` sólo atiende a quien inició sesión, y entonces no entiende cómo funciona el
cajón para un visitante anónimo. **Debería decir:** «`wc_ajax_<acción>` de WooCommerce atiende a
todos, con sesión y sin ella, y por eso no necesita el par `nopriv`; `wp_ajax_` de WordPress sí lo
necesita y por eso los favoritos registran las dos, una para el cliente y otra para el 401 de "se me
cayó la sesión".»

**1.3 · `$business` aparece de la nada y es el punto de fallo más probable de todo el checkout.**
La tabla «Cómo encaja» dice: *«akibara-cart-checkout-blocks (propio) | `$business->checkout_data()`
y `$business->pudo_checkout()`»*. No se dice **qué es `$business`**, quién lo pide, ni qué pasa si
falla. En el snapshot: `akibara-cart-checkout-blocks/src/Plugin.php:34-42` hace
`$business = BusinessPlugin::instance();` y si `checkout_data()` no devuelve un
`CheckoutDataProvider`, hace `return;` — **el plugin de bloques no arranca, en silencio**. Ese
`return` silencioso es exactamente el síntoma «el checkout perdió el RUT, el teléfono y los sellos»
que la propia tabla anuncia. **Debería decir:** el nombre de la clase, el fichero y que el fallo es
mudo, con qué mirar primero.

**1.4 · El orden causal del envío gratis está invertido y no se entiende hasta la tercera lectura.**
La ficha 7 introduce `akibara_fsp_carrier_threshold` así: *«`akibara_fsp_carrier_threshold`
(**el que manda en producción**)»* —un paréntesis, sin explicación— y recién dos párrafos después
aparece el porqué: *«Las instancias «Envío gratis» de las zonas están deshabilitadas a propósito»*.
**Debería decir primero** que en Akibara WooCommerce no concede envío gratis (lo concede la tarifa
de Blue Express, y por eso las instancias están apagadas), y **de ahí** derivar que el único número
vivo es el del transportista. Tal como está, el lector memoriza una excepción antes de conocer la
regla.

**1.5 · «Arriendo» se usa nueve veces y no está en el glosario.**
Ficha 11: *«Antes de llamar al transportista se abre un «arriendo» que invalida cualquier operación
anterior del mismo cliente»*. El glosario define «Comparar-y-cambiar», «Fingerprint», «HMAC»… pero
no «arriendo». Es la traducción de *lease* y sin ella la frase no significa nada.
**Debería decir:** entrada de glosario, por ejemplo «**Arriendo (lease)** — un turno con dueño y con
hora de caducidad: mientras dure, sólo quien lo tomó puede confirmar; cualquier intento anterior
queda invalidado (`PudoMutationGuard::begin_selection`)».

**1.6 · La única forma de escribir de «Mi colección» está documentada fuera del capítulo.**
Ficha 13: *«Escribe sólo a través del manejador de `SeriesFollow\GlobalToggle` (el formulario POST
del interruptor) y de la ruta REST de `SeriesFollow\CollectionToggle`»*, y antes se avisa que
`SeriesFollow` queda *«fuera de este capítulo»*. Resultado: de la pantalla más privada del área no
se da ni la ruta REST, ni el nombre de la acción del POST, ni la capacidad exigida. Con eso no se
puede depurar «el interruptor no guarda».
**Debería decir:** la ruta REST literal, el `action` del formulario y a qué capítulo ir por el
resto, con su número.

**1.7 · «El JavaScript anota la intención» — ¿dónde?**
Ficha 5: *«Y el JavaScript **anota la intención**: al volver de entrar, el tomo se guarda solo, con
caducidad de media hora»*, y el Caso 3 añade *«si el navegador bloquea el almacenamiento (modo
privado), degrada al flujo de siempre»*. Nunca se dice **en qué** se anota (`sessionStorage`,
`localStorage`, una cookie) ni con qué clave. Sin eso no hay forma de comprobar en vivo si el
guardado pendiente existe, que es el primer paso para depurar «volví de entrar y no se guardó».

**1.8 · El arreglo de los ocho hooks comentados se describe pero no se acota.**
El ⚠ más grave cierra con: *«**Son dos arreglos que van juntos, no uno.**»*. Falta el tercero, que
es el que hace peligroso el parche: `templates/myaccount/form-login.php` es una **copia** de una
plantilla de WooCommerce, así que descomentar los ocho `do_action` sin comparar la copia contra la
plantilla original de la versión de WooCommerce instalada puede reintroducir campos que se quitaron
a propósito. **Debería decir** contra qué fichero de WooCommerce se compara y en qué orden se
descomenta (empezando por `woocommerce_register_form`, que es el que devuelve el aviso legal).

**1.9 · «Casi nada de este código se ejecuta por su cuenta» choca con «Lo programado».**
El prólogo afirma eso y la sección «Lo programado» describe un cron cuya primera pasada *«corre
**dentro de la petición de un cliente**»*. Para un novato son dos afirmaciones contradictorias
porque nunca se le enseña a **ver** la cola: el glosario explica qué es wp-cron pero el capítulo no
da ni un comando para listarla (ver 2.3).

---

## 2. Lo que FALTA para operar o depurar sin ayuda

**2.1 · La promesa del prólogo no se cumple ni una vez: no hay ninguna receta de medición.**
Línea 19: *«No son opiniones: son contradicciones entre lo que el código promete y lo que el código
hace, cada una con su `fichero:línea` y **con la receta para medirla en el espejo Docker**»*.
Medido sobre el propio capítulo: **20 bloques ⚠**, **0 comandos**. La palabra «docker» aparece
**una** vez en todo el fichero —esa misma línea 19—; «curl», **cero**; «wp cron», una vez y dentro
de una frase narrativa. Y `verificacion.json` **sí trae** un `como_verificar` concreto por cada
hallazgo (`docker compose exec -T cron wp wc product update <id> --stock_quantity=0 …`,
`curl -s -A AkibaraQA 'http://localhost:8080/?wc-ajax=akb_get_cart'`,
`curl -s http://localhost:8080/mi-cuenta/ | grep -c 'woocommerce-privacy-policy-text'` → hoy 0…).
**Debería hacer:** pegar el `como_verificar` bajo cada ⚠, o borrar la promesa de la línea 19. Tal
como está, el capítulo promete lo único que convierte un hallazgo en algo accionable y no lo
entrega.

**2.2 · Ninguna pantalla del dueño tiene URL, y el propio capítulo avisa de que el slug engaña.**
El prólogo dice *«su identificador interno es `akibara-panel`, no `akibara`»*, y luego las once
fichas rematan con *«wp-admin → Akibara → pestaña **Carrito**»* / *«pestaña **Envío gratis**»* /
*«pestaña **Textos**»*… sin una sola URL. Las pestañas se identifican por un `tab=` que está en el
código (`src/Plugin.php:1296-1394`).
**Debería decir**, en la primera ficha y en una tabla al final:
`https://akibara.cl/wp-admin/admin.php?page=akibara-panel&tab=<id>`, con los ids reales
`native_cart` (Carrito), `free_shipping_progress` (Envío gratis), `address` (Autocompletar
dirección), `copys` (Textos), `upsells_autofill` (Recomendaciones — ver 3.2).

**2.3 · El único cron del área no trae la forma de saber a qué hora corre.**
La tabla de «Lo programado» dice: *«Cada 24 h, **a la hora en que se agendó por primera vez** — no
hay hora fija»*. Correcto, y por eso mismo hace falta el comando.
**Debería decir:** `wp cron event list --fields=hook,next_run_gmt,recurrence | grep akb_upsells_autofill_cron`
(en producción, tras `ssh akibara`), y que el horario se lee en UTC. Falta además el **único
apagado real que existe**: `UpsellsAutofill::clear_schedule()` está cableado a la desactivación del
plugin (`akibara-business.php:251`, `wp_clear_scheduled_hook( 'akb_upsells_autofill_cron' )`). El ⚠
de la ficha 6 dice *«no hay forma de apagarlo sin tocar código»* y omite ese camino, que no arregla
el problema pero sí explica por qué el evento no es inmortal.

**2.4 · Ningún correo tiene asunto, ni id, ni lista de cuáles son.**
Los nueve casos rematan con *«**Correos.** *Correo de pedido de WooCommerce* —el asunto se configura
en WooCommerce → Ajustes → Correos— **inmediatamente** al crearse el pedido»*. Y el ⚠ de la ficha 10
afirma que la caja de retiro sale *«también en un pedido cancelado o reembolsado»*, apoyado en que
`woocommerce_email_after_order_table` *«lo emiten **todos** los correos al cliente»*. Para poder
reproducir eso hace falta saber **cuáles son**.
**Debería decir:** la lista con sus ids de WooCommerce —`customer_processing_order`,
`customer_completed_order`, `customer_on_hold_order`, `customer_invoice`,
`customer_refunded_order`, `cancelled_order`— cuáles van al cliente y cuáles al administrador (el
único filtro del módulo es `$sent_to_admin`, `PickupCoordination.php:72`), y en qué pantalla exacta
se edita el asunto de cada uno.

**2.5 · El capítulo del checkout no dice con qué se paga.**
El prólogo promete *«**todo el camino del cliente**, desde que pulsa «Añadir al carrito» hasta que
… entra a su cuenta»*, y el Caso 4 salta de *«Al confirmar, WooCommerce crea el pedido»* al hook.
No hay una sola mención de la pasarela de pago. En el snapshot la pasarela es **MercadoPago**, y
este mismo plugin tiene dos módulos que existen sólo para sostenerla:
`src/Modules/FrontendCompatibility/FrontendCompatibility.php:29-35` excluye
`woocommerce-mercadopago/assets/js/checkouts/`, `…/health/`, `…/melidata/`, `…/scripts/` y
`sdk.mercadopago.com/js/v2` del combine/defer de LiteSpeed (`:77-79`), y
`src/Modules/ScriptDiagnostics/CrossOriginScripts.php:1-20` existe porque *«Clarity registró siete
sesiones móviles con un error de JavaScript en `/checkout/` cuyo mensaje completo es «Script
error.»… 127 scripts, 12 de dominios ajenos, y 11 de esos 12 sin `crossorigin`»*.
**Debería decir:** quién cobra, y que el checkout de bloques puede romperse por optimización de
LiteSpeed sobre los scripts de la pasarela, con los dos ficheros que lo evitan.

**2.6 · Piezas del snapshot que el capítulo no nombra (comparando `ls src/Modules` con las fichas).**
Además de los dos de 2.5:
- **`src/Modules/ShippingCopy/ShippingCopy.php`** — publica el shortcode `[akb_envio_gratis]`, que
  se resuelve con **la misma** option `akibara_fsp_carrier_threshold` que la ficha 7 llama «el que
  manda en producción», y su docblock cuenta que el número vivía escrito a mano en tres sitios y que
  subirlo de $55.000 a $60.000 el 2026-09-02 obligó a perseguirlo. El capítulo documenta la option
  como si sólo alimentara la barra: cambiarla mueve también la barra de anuncios y la FAQ.
- **`src/Services/MailerBoot.php`, `AkibaraEmail.php`, `EmailButton.php`** — el transporte por el que
  salen los correos que los nueve casos prometen. No aparecen ni como frontera, y la memoria del
  proyecto registra tres días de producción **sin ningún correo** por un problema de transporte.
**Debería añadir** una tabla corta «toca este camino pero se cuenta en otro capítulo», con el número
de capítulo al lado.

**2.7 · `wp_akb_wishlist` sobrevive a la desinstalación y el capítulo no lo dice.**
La sección «Datos que deja» describe la tabla y la ficha 5 dedica una clase entera
(`PrivacyHandler`) a exportar y borrar datos personales. Pero `uninstall.php` (118 líneas) borra
options, `termmeta` y `usermeta` (`:95-117`) y **no contiene ningún `DROP TABLE`**: al desinstalar el
plugin, la tabla con `user_id` de cada cliente y qué guardó **queda en la base de datos**.
**Debería decir**: qué se borra y qué no al desinstalar, y si eso es una decisión o un olvido.

**2.8 · PUDO no tiene ningún procedimiento de rescate ni canal de registro.**
Ficha 11: *«Dónde lo opera el dueño. Ninguna pantalla propia»*. Y los seis mensajes de error
(`pudo_multiple_packages`, `pudo_quote_invalid`, `pudo_stale_attempt`,
`pudo_checkout_in_progress`, …) se listan como bifurcaciones del Caso 5 sin decir **dónde se ven**.
No hay una llamada a `Log::warn` citada en toda la ficha.
**Debería decir:** qué hacer con un cliente atascado en `pudo_checkout_in_progress` (esperar: el
`CHECKOUT_TTL` son 30 s y la lápida 900 s, `PudoMutationGuard.php:22-25`), cómo listar las filas
(`wp option list --search='akb_pudo_state_%'` por `ssh akibara`), y si estos rechazos dejan o no
rastro en WooCommerce → Estado → Registros. Hoy, si un cliente dice «no me deja elegir el punto»,
el capítulo no ofrece ningún primer paso.

**2.9 · Las mediciones vienen sin fecha ni fuente, y el código sí las trae.**
*«Un dato de uso real: `/tienda/` concentraba 88 «entro a la ficha y vuelvo» semanales»* y
*«en `/mi-cuenta/favoritos/` la permanencia media era de 9,5 segundos»*. El docblock del propio
módulo fecha y atribuye la primera: `QuickView.php:5` — *«Origen del feature (2026-07-27, datos
Clarity de usuarios reales)»*. Una medición sin fecha ni herramienta no se puede volver a correr,
que es justo lo que pide la regla de la casa.
**Debería decir:** «(Clarity, 2026-07-27)» junto a cada número.

**2.10 · El docblock obsoleto de `OrderTimeline` sigue publicado y el capítulo lo trata como
anécdota.** La «Corrección respecto del mapa de lectura» dice: *«Ese «4 + Entregado» sale del
docblock obsoleto de la cabecera del fichero (`:13-17`), que el mapa copió sin contrastarlo»*.
Comprobado: `OrderTimeline.php:13-17` sigue diciendo *«"Despachado" → "Entregado"»* y *«inyectamos
un 5to nodo»*, contra lo que hace `build_steps()`. Es documentación viva que miente dentro del
plugin desplegado y ya hizo caer un mapa; por las reglas del dueño («cero código muerto», «la deuda
previa también se arregla») es trabajo, no curiosidad. **Debería** llevar su propia ⚠ o una línea en
un apartado de deuda.

**2.11 · No hay ninguna vista de conjunto de «qué toco si cambio X».**
Hay 45 options, 9 metas, 1 tabla, 4 transients y 5 claves de sesión, cada una con su fila. Falta la
lectura inversa, que es la que se usa al depurar: «el cliente ve dos números de envío gratis
distintos» → mirar `akibara_fsp_threshold` vs `akibara_fsp_carrier_threshold` (⚠ ficha 7) **y**
`[akb_envio_gratis]` (2.6). Tres líneas de índice inverso al final ahorrarían media hora.

---

## 3. Lo que CONTRADICE al código

**3.1 · «Simple Points and Rewards (tercero, en desuso)» — hoy esa función la publica un plugin
propio.** Línea 71: *«| **Simple Points and Rewards** (tercero, en desuso) |
`function_exists( 'spar_get_user_points' )` | La tarjeta de puntos del panel de Mi cuenta no se
pinta. |»*, y la ficha 14 lo repite (*«la de Recompensas sí se protege con
`function_exists( 'spar_get_user_points' )`»*). En el snapshot, esa función la define
**`akibara-rewards` (first-party)** como shim de compatibilidad:
`akibara-rewards/akibara-rewards.php:200-221` —
`if ( function_exists( 'spar_get_user_points' ) || ! class_exists( '\Akibara\Rewards\Points\Ledger' ) ) { return; }`
… `function spar_get_user_points( $user_id ): int { return \Akibara\Rewards\Points\Ledger::balance( (int) $user_id ); }` —
y el endpoint `rewards` lo registra `akibara-rewards/src/Points/AccountRewards.php:73`
(`add_rewrite_endpoint`), no SPAR. El comentario de `dashboard.php:66-72` que el capítulo parafrasea
(*«el endpoint `rewards` lo registra Simple Points and Rewards»*) también está caduco.
**Debería decir:** «La tarjeta se apaga sola con `function_exists('spar_get_user_points')`. Esa
función hoy la publica el plugin propio `akibara-rewards` (shim, `akibara-rewards.php:200-221`);
SPAR ya no está. Si se desactiva `akibara-rewards`, desaparecen la tarjeta **y** el endpoint
`/mi-cuenta/rewards/`.» Tal como está, quien depure «la tarjeta de puntos no sale» irá a buscar un
plugin de terceros que no está instalado.

**3.2 · «pestaña Upsells» — esa pestaña no existe; se llama «Recomendaciones».**
Tres veces: línea 346 (*«El CSS, sólo en la pestaña Upsells»*), línea 365 (*«wp-admin → Akibara →
pestaña **Upsells**»*) y línea 1353 (*«se muestran en Akibara → Upsells»*). El registro real es
`src/Plugin.php:1362-1370`: `$panel->add_section( 'upsells_autofill', 'Recomendaciones', … )`. En el
panel el dueño lee **Recomendaciones**, y la URL es `…&page=akibara-panel&tab=upsells_autofill`.
La palabra «Upsells» no aparece como etiqueta en ninguna parte de `src/`.

**3.3 · Los canales de registro tienen otro nombre.**
Línea 721: *«Los fallos salen en WooCommerce → Estado → Registros, canal «coleccion»»*, y línea 882:
*«canal «consentimiento»»*. `src/Utilities/Log.php:43` escribe
`array_merge( array( 'source' => 'akibara-' . $module ), $context )`, y las llamadas pasan
`'coleccion'` (`AccountCollection/Renderer.php:42-43`) y `'consentimiento'`
(`MarketingConsent.php:206-207`). O sea, en el desplegable de Registros los ficheros se llaman
**`akibara-coleccion-…`** y **`akibara-consentimiento-…`**. Buscando «coleccion» a secas no aparecen.

**3.4 · «`src/Integrations/Bluex/Pudo/` — seis ficheros» — son siete.**
Línea 553, y el título de línea 547 (*«cinco clases y un candado»*). El directorio del snapshot
contiene: `PickupPointProvider.php`, `PudoCheckoutService.php`, `PudoMutationGuard.php`,
`PudoMutationRepository.php`, `PudoSelectionStore.php`, `PudoValidationException.php`,
`WpOptionsPudoMutationRepository.php`. La tabla de la ficha sí nombra las siete clases (tres
apiladas en la última fila), así que el error está sólo en la cuenta — pero es la cuenta que el
lector usa para comprobar que no le falta nada.

**3.5 · «`AccountCollection` — Mi colección (cuatro clases y una plantilla)» — son cinco clases.**
Línea 670. Su propia línea «Ficheros» lo desmiente dos líneas más abajo, listando
`AccountCollection.php`, `Endpoint.php`, `CollectionResolver.php`, `ContinuityResolver.php` y
`Renderer.php`. El directorio tiene esos cinco.

**3.6 · «`Wishlist` — los favoritos (siete clases)» — son ocho, y la que falta es la importante.**
Línea 260 y su tabla de siete filas (`Install`, `Storage`, `Ajax`, `Endpoint`, `Button`, `Render`,
`PrivacyHandler`) dejan fuera `src/Modules/Wishlist/Wishlist.php`, que es precisamente la clase que
decide dónde bajan CSS y JS (`surface_renders_hearts()`, `:124-148`), la que engancha
`before_delete_post` (`:58`) y la de la lista blanca de LiteSpeed (`:42`) — los tres puntos que el
capítulo desarrolla después citando ese fichero. Añadirla como octava fila.

**3.7 · «Cuarenta clases agrupadas en catorce bloques» — ninguna de las dos cifras cuadra.**
Línea 102. Las fichas numeradas son **diecinueve** (`### 1.` … `### 19.`), y el diagrama de piezas
agrupa en **seis** subgrafos. Sumando las clases que las fichas nombran salen ~43, no 40.
**Debería decir:** «Diecinueve fichas, seis familias» (o el número que se decida), y que la cuenta
coincida con el diagrama.

**3.8 · «Ocho historias contadas paso a paso» — son nueve.**
Línea 1018; hay `### Caso 1` … `### Caso 9`.

**3.9 · «instancia 70 de la zona «Región Metropolitana - Blue Express»» sin fuente.**
Línea 1176. Es el único dato duro del capítulo sin `fichero:línea`, y no puede tenerlo porque es
configuración de producción, no código. En un capítulo cuyo prólogo promete que *«Cada afirmación
técnica lleva su `fichero:línea`»*, una instancia numérica a pelo caduca sin aviso el día que
alguien toque las zonas —y la memoria del proyecto advierte de no tocar Blue → Zonas—.
**Debería decir:** de dónde se lee y cómo comprobarlo (WooCommerce → Ajustes → Envío → zona, o
`wp wc shipping_zone_method list <id de zona> --user=1`), o quitar el número.

**3.10 · Cuatro citas desplazadas (menores, pero el contrato del capítulo es `fichero:línea`).**
- *«`register()` engancha el cron sin ningún gate de option (`:76-77`)»* → en el snapshot es
  `UpsellsAutofill.php:75-76` (`add_action( self::CRON_HOOK, … )` en 75, `add_action( 'init', … )`
  en 76); la 77 ya es la línea en blanco.
- *«`maybe_schedule()` … con `wp_schedule_event( time(), 'daily', … )` (`UpsellsAutofill.php:97-99`)»*
  → el método es `:96-100` y el `wp_schedule_event` está en `:98`.
- *«`Wishlist::ensure_assets()` (`:111-119`)»* → el método llega hasta `:120`; y
  *«`Wishlist::surface_renders_hearts()` (`Wishlist.php:121-148`)»* → `:121-123` es docblock, el
  método empieza en `:124`.
- *«Los tiempos, escritos en la clase (`PudoMutationGuard.php:21-26`)»* → las cuatro constantes
  citadas están en `:22-25`; `:21` es `SCHEMA_VERSION` y `:26` es `CAS_ATTEMPTS` (que el capítulo
  cita bien, por separado, unas líneas más abajo).

---

## 4. Lo que contradice a `verificacion.json` del área

El capítulo trasladó **las seis omisiones, los seis errores y las ocho sospechas nuevas** de
`mapas/biz-compra-cuenta/verificacion.json`, y además respetó las seis refutaciones. Las
divergencias que quedan son éstas:

**4.1 · La única `es_decision_de_negocio: true` del fichero llega al capítulo con etiqueta de
defecto.** `verificacion.json` marca la caja de retiro en pedidos cancelados/reembolsados como
`"es_decision_de_negocio": true`. El capítulo la titula *«⚠ Hoy no cuadra — la caja sale también en
un pedido cancelado o reembolsado»* —la misma etiqueta que usa para los defectos puros— y sólo
aclara en la última línea, entre paréntesis y en cursiva: *«(Qué debe decir ese correo es decisión
del dueño, no del código.)»*. Con la regla de la casa de no decidir negocio solo, **debería** llevar
un rótulo propio desde el título (p. ej. «⚠ Decisión del dueño») para que nadie lo «arregle» en la
misma sesión.

**4.2 · El rango de la política de encolado de favoritos se recortó y dejó fuera donde se aplica.**
`verificacion.json` lo sitúa en `src/Modules/Wishlist/Wishlist.php:96-148` —un rango elegido para
incluir `register_assets()` / `enqueue_assets()`, que es **donde la lista decide encolar**—. El
capítulo lo parte en `:111-119` y `:121-148` y deja el tramo `:96-110` fuera. Quien abra `:121`
aterriza en un docblock y quien quiera ver el efecto no encuentra el encolado.

**4.3 · El docblock obsoleto queda como anécdota del mapa, no como deuda.** `verificacion.json`,
error 2, subraya que el «4 + Entregado» *«sale del docblock obsoleto de la cabecera del fichero, que
el mapa copió sin contrastarlo contra el método»*. El capítulo repite la frase pero no registra en
ningún sitio que ese docblock **sigue desplegado** contradiciendo a `build_steps()` (ver 2.10).

**4.4 · La regla «nunca formato de plantilla» se enuncia como universal del área, y
`verificacion.json` ya refutó esa lectura.** El capítulo la repite dos veces sin matiz: *«El
marcador `{producto}` se sustituye con `str_replace`, nunca con formato de plantilla»* (tabla de
reglas) y *«el texto de la nota se compone con marcadores y `str_replace`, **nunca** con formato de
plantilla, porque … un símbolo de porcentaje sería un error fatal»* (Caso 9).
`verificacion.json`, en `sospechas_refutadas`, dedica una entrada entera a explicar que en
`CheckoutDataService.php:263-264` **sí** se usa `sprintf` y es seguro, *«porque el texto de
`Availability::label_for_state()` es el ARGUMENTO de `sprintf`, no la cadena de formato»*.
Comprobado en el snapshot: esas dos líneas existen y usan `sprintf`. El capítulo omite el matiz, así
que un lector que aplique la regla al pie de la letra reabrirá exactamente el falso hallazgo que
`verificacion.json` ya tumbó. **Debería decir:** la regla es «un copy editable por el dueño nunca
puede ser la **cadena de formato**; como argumento es inofensivo», con el ejemplo de
`CheckoutDataService.php:263-264` al lado.

**4.5 · Los `como_verificar` no viajaron.** Cada hallazgo de `verificacion.json` trae su receta de
medición en el espejo; el capítulo prometió llevarlas (línea 19) y no llevó ninguna (ver 2.1). Es la
diferencia entre un capítulo que se puede auditar y uno que hay que creer.

---

## 5. Diagramas

**Validación:** compilé los siete con `mmdc -i <fichero>.mmd -o <fichero>.svg` y **los siete pasan
sin error** (`caso-cajon-carrito`, `caso-muro-favoritos`, `caso-pudo`, `cron`, `estados-pedido`,
`estados-pudo`, `piezas`). Los siete están enlazados desde el texto, ninguno queda huérfano y
ninguno se enlaza sin existir. Todos llevan cabecera `%%` con «qué muestra», que es lo que los hace
legibles solos. Las observaciones son de contenido:

**5.1 · `-piezas.mmd` repite las tres cuentas equivocadas del texto.**
Nodos `PUDO["Bluex\\Pudo (6 clases)"]` (son siete ficheros, ver 3.4),
`COL["AccountCollection (4 clases)"]` (son cinco, ver 3.5) y `WL["Wishlist (7 clases)"]` (son ocho,
ver 3.6). Al ser el diagrama la superficie que más se cita fuera del documento, arrastra el error
más lejos que el texto.

**5.2 · `-piezas.mmd`: la última arista apunta a un contenedor y nombra dos piezas que no existen en
el diagrama.** `OCP -.->|"la usan BackInStock y SeriesFollow"| CUENTA`. Ni `BackInStock` ni
`SeriesFollow` son nodos del diagrama, y `CUENTA` es el subgrafo «Después de comprar: /mi-cuenta/»,
que no contiene a ninguno de los dos. El lector ve una flecha hacia una caja que no tiene relación
con su etiqueta. **Debería:** dibujarlos como dos nodos atenuados «fuera de este capítulo» y apuntar
a ellos, o quitar la arista y dejar la explicación sólo en el texto.

**5.3 · `-piezas.mmd`: `TL --> META` dice lo contrario de lo que dice el texto.**
La ficha 12 afirma de `OrderTimeline`: *«**Qué lee.** Las metas `_akb_eta_has_preventa` y
`_akb_eta_ready_to_ship` (las escribe `OrderPreventa`, fuera de este capítulo) … **No escribe
nada.**»*. En el diagrama la arista es sólida y sin etiqueta, indistinguible de las de escritura
(`PHONE --> META`, `MKT --> META`, `UPS --> META`). Lo mismo con `COL`, cuyo único enlace es a
LiteSpeed: no se ve de dónde salen sus datos ni por dónde escribe (`SeriesFollow`).
**Debería:** `TL -.->|lee| META` y un nodo atenuado `OrderPreventa` / `SeriesFollow` como origen.

**5.4 · `-piezas.mmd` no tiene a quien cobra.** No hay nodo MercadoPago pese a haber nodos para
Blue Express, Google, Brevo, LiteSpeed y el plugin de WhatsApp, y pese a que dos módulos del propio
plugin existen para sostener sus scripts en `/checkout/` (ver 2.5). Un mapa de «carrito y checkout»
sin la pasarela es el hueco más visible del dibujo.

**5.5 · `-caso-cajon-carrito.mmd` no dibuja la bifurcación que el capítulo marca como ⚠.**
Tiene `alt el carrito lleva algo reservable` y `alt el añadir al carrito FALLÓ`, pero no la del
producto agotado (`max === 0`), que es el hallazgo del Caso 2 y de la ficha 1. Añadir un `alt` con
las dos ramas reales: «−» con cantidad ≥ 2 → aviso «se agotó y lo quitamos»; «−» con 1 unidad o «+»
deshabilitado → la línea sigue sumando en silencio.

**5.6 · `-estados-pedido.mmd`: el lector no puede cerrar la cuenta de «tres pasos + uno».**
El texto dice que el paso 1 es «Pago confirmado» **o** «Esperando pago» —un nodo con dos rótulos— y
el diagrama los pinta como dos estados separados, más `Terminal`, `Espera`, `Preparando`,
`Despachado` y `ListoParaEnviar`: siete cajas para una línea de tiempo que el texto insiste en que
tiene cuatro nodos como máximo. No es falso —son estados del pedido, no nodos pintados— pero el
diagrama no lo aclara y la corrección de los cuatro nodos es justo uno de los puntos que el capítulo
quiere dejar clavados. **Debería:** una `note` que diga «estos son los estados del pedido; la línea
de tiempo pinta tres nodos, más un cuarto si hay espera vigente».

**5.7 · `-estados-pudo.mmd`: `Fallida` es el único estado sin salida.**
Tiene `Fallida --> Pendiente : el cliente vuelve a elegir`, pero no llega nunca a `[*]`, mientras
`Liberada --> [*] : cleanup_expired()` sí. En el código, `fail_selection()` escribe
`$failed['expires_at'] = $now + self::TOMBSTONE_TTL` (`PudoMutationGuard.php:144-147`): una fila
fallida caduca a los 900 s igual que una liberada y la barre la misma limpieza. Tal como está, el
lector concluye que un fallo deja una fila para siempre. Añadir `Fallida --> [*] : caduca a los 900 s`.

**5.8 · `-cron.mmd` cuenta media verdad sobre el apagado.**
La línea *«No hay interruptor en ninguna pantalla; borrar el evento por CLI lo reagenda la siguiente
visita (maybe_schedule en el hook init)»* es correcta, pero omite que desactivar el plugin sí lo
limpia (`akibara-business.php:251`). Es el mismo hueco de 2.3 y conviene que texto y diagrama digan
lo mismo.

**5.9 · Lo que sí funciona, para no tocarlo.** `-estados-pudo.mmd` y `-caso-pudo.mmd` son los dos
mejores del capítulo: se entienden sin el texto, los tiempos de vida están dentro de los nodos, las
cinco salidas de error llevan su código (`pudo_stale_attempt`, `pudo_checkout_in_progress`,
`pudo_multiple_packages`, `pudo_quote_invalid`) y las dos `note` explican el porqué, no el qué.
`-caso-muro-favoritos.mmd` también cierra el círculo completo, incluida la rama del 401 por sesión
caída. Ese nivel es el que deberían alcanzar los otros cuatro.

---

## Respuesta del redactor

Aplicados **todos** los puntos, salvo los matices que se anotan abajo. Comprobé contra el snapshot
de producción cada afirmación que decía «el código dice otra cosa», y las cuentas —siete ficheros en
`Bluex/Pudo/`, cinco clases en `AccountCollection/`, ocho en `Wishlist/`, diecinueve fichas, nueve
casos— salieron todas como las contaste. Igual los nombres de pestaña (`upsells_autofill` →
«Recomendaciones»), los canales de registro (`akibara-coleccion`, `akibara-consentimiento`), el
puente `spar_get_user_points()` de `akibara-rewards`, el `return` mudo de
`akibara-cart-checkout-blocks/src/Plugin.php:29-42`, la ausencia de `DROP TABLE` en `uninstall.php`,
la ausencia de `Log::` en toda la integración de PUDO, el `sessionStorage`/`akbWishlistPending` y el
`wp_clear_scheduled_hook` de `akibara-business.php:251`.

La crítica **2.1 era la correcta y la más cara**: el capítulo prometía recetas y no traía ninguna.
Ahora los veintiún bloques ⚠ llevan su «Cómo medirlo» (o «Cómo comprobarlo», en el que no es un defecto) con comandos literales, y el prólogo explica
el entorno (Docker, `localhost:8080`, Mailpit en `8025`, `-A AkibaraQA`). No pegué los
`como_verificar` tal cual: varios había que reescribirlos para que corrieran, y eso lleva a los tres
puntos siguientes.

### Donde el crítico se equivocó (texto no cambiado, o cambiado de otra forma)

**3.10, tercer guion — «`surface_renders_hearts()` … el método empieza en `:124`».** No. En el
snapshot ese método empieza en **`:131`** y termina en `:148`; `:122-130` es su docblock. Tenías
razón en que `:121-148` arrancaba dentro de un comentario, y en que `ensure_assets()` llega hasta
`:120` y no `:119`. El capítulo ahora cita `:96-102` (el encolado), `:111-120` (la red de seguridad)
y `:131-148` (la lista), y usa `:96-148` como rango de la política entera, que es lo que pedía 4.2.

**2.4 — la lista de correos.** Faltaba una distinción que importa justo para el ⚠ de la ficha 10:
en WooCommerce 11.1.0 (el instalado) **`cancelled_order` es el correo al ADMINISTRADOR**
(`class-wc-email-cancelled-order.php:62`, destinatario `admin_email`), así que `$sent_to_admin` lo
filtra y **no** lleva la caja. El que lee el cliente es **`customer_cancelled_order`**
(`class-wc-email-customer-cancelled-order.php:32-33`, `customer_email = true`). La tabla publicada
lleva los once correos con su identificador y una columna de «¿lleva la caja?», y avisa del par de
nombres parecidos. También añadí `customer_note`, `customer_failed_order` y
`customer_partially_refunded_order`, que faltaban en tu lista y sí la llevan.

**2.3 — «`UpsellsAutofill::clear_schedule()` está cableado a la desactivación».** El efecto es el
que dices, pero el camino no: el `register_deactivation_hook` **no llama a ese método**. Usa el
literal `wp_clear_scheduled_hook( 'akb_upsells_autofill_cron' )` en `akibara-business.php:251`, y el
comentario de `:245-249` explica por qué a propósito —si WooCommerce está inactivo, el bootstrap
retorna antes de registrar el autoloader y referenciar `UpsellsAutofill::CRON_HOOK` fatalearía—. El
capítulo cita el literal y la razón.

**2.8 — `wp option list --search='akb_pudo_state_%'`.** Correcto, y lo publiqué, pero con dos
añadidos: `--fields=option_name,autoload` (lo que se verifica es que **ninguna** viaje con
`autoload` en `yes`) y la advertencia de que **esas filas no se borran a mano**: caducan solas y las
barre `cleanup_expired()`. Un rescate manual ahí es la forma de acuñar el problema, no de
resolverlo.

**Sobre los `como_verificar` de `verificacion.json`.** Tres no se podían pegar literalmente:
- el de la sospecha 5 dice «comprobar el cuerpo en **MailHog**»; el buzón del espejo es
  **Mailpit**, `http://localhost:8025/` (`compose.yaml`, servicio `mailpit`);
- el de la sospecha 3 propone leer `structured_state()` con `wp eval`, que no sirve: WP-CLI no tiene
  la sesión ni el carrito del cliente. La receta publicada lee la barra desde la respuesta real del
  cajón (`NativeCart.php:340`, clave `fsp_html`), y deja la comprobación de `PICKUP_METHOD_IDS` en
  el fuente porque la constante es `private`;
- varios contaban con `grep -c`, que cuenta **líneas**, no ocurrencias: con el HTML minificado da
  1 donde hay 72. Todas las recetas publicadas cuentan con `grep -o … | wc -l`, y el capítulo lo
  advierte dos veces.

**3.9 — la instancia 70.** Quitado el número, como pedías, y sustituido por cómo leerlo
(`wp wc shipping_zone list` → `wp wc shipping_zone_method list <id>`), más el aviso de no pulsar
«Aplicar» en la pantalla de zonas del plugin de Blue Express.

**4.1 — el rótulo.** Hecho, y con algo más que el título: el recuadro de la caja de retiro en
pedidos cancelados empieza diciendo, en negrita, que **no se arregla en la misma sesión, se
pregunta**. El hecho medido queda documentado entero para que la pregunta se pueda hacer con datos.

**5.2 y 5.3 — el diagrama de piezas.** Resueltos dibujando cuatro nodos atenuados «fuera de este
capítulo» (`OrderPreventa`/`EstimatedDelivery`, `SeriesFollow`, `BackInStock`,
`FrontendCompatibility`+`CrossOriginScripts`), con su número de capítulo dentro de la caja. La
arista de `OCP` ya no apunta a un subgrafo; `TL -.->|"sólo LEE"| META` va punteada y `OPREV` escribe
esas metas con flecha sólida. La cabecera del `.mmd` declara ahora la convención: sólida = escribe o
llama, punteada = sólo lee. Y 5.4: hay nodo **Mercado Pago**, con el cliente pagándole y los dos
módulos del capítulo 06 protegiendo sus scripts.

**Validación.** Los siete `.mmd` recompilados con `mmdc -i <fichero>.mmd -o <fichero>.svg`: los
siete pasan. Dos fallaron en el primer intento y se corrigieron —un `end` que faltaba en el `alt`
nuevo de `-caso-cajon-carrito`, y un `;` dentro de una `Note` de `-caso-muro-favoritos`, que el
analizador de `sequenceDiagram` no acepta—.
