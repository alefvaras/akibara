# Crítica de lector novato — `11-rewards-resenas.md`

> Leído entero el capítulo y sus 6 diagramas. Contrastado contra el snapshot de producción
> (`plugins/akibara-rewards` 0.3.13, `plugins/akibara-business`, `themes/akibara-gp`, los 18
> mu-plugins) y contra `mapas/rewards-resenas/verificacion.json`. Los 6 `.mmd` se renderizaron con
> `mmdc -i … -o … -b white`: **los seis validan**, sin un solo error de sintaxis.
>
> Aviso de contexto: el capítulo es, en citas, de los más honestos que he leído — comprobé una a una
> más de sesenta referencias `fichero:línea` y casi todas caen exactas. Lo que sigue son los sitios
> donde aun así me quedé sin poder trabajar solo.

---

## 1 · Lo que NO entendí

**1.1 · «el verificador» y «el mapa» aparecen 24 veces y nunca se presentan.**
El capítulo dice cosas como «**Ojo, corrección del verificador:** no es cierto que…» (§1.2),
«**Omisión que el verificador añadió**» (§4), «**Dato nuevo del verificador, y es grande**» (§12).
Conté 18 «verificador» y 6 «el mapa». Yo no sé qué son. ¿Una persona? ¿Un programa? ¿Un documento que
puedo abrir? El preámbulo los nombra de pasada («Donde el mapa de lectura y el verificador
discreparon, manda el verificador») y ahí se acaba la explicación.
**Debería decir:** una línea en el preámbulo del tipo «*el mapa* y *el verificador* son dos artefactos
internos de la fase de lectura: el primero es el inventario que hizo un agente leyendo el código, el
segundo es la segunda pasada que lo auditó. No se publican con este capítulo; se citan aquí sólo para
dejar dicho qué afirmación cambió y por qué». O, mejor para este público: **quitar las 24 menciones**
y dejar el hecho corregido, que es lo único que el lector necesita.

**1.2 · «es la "decisión A" que `src/Reviews/Moderation.php:3-5` deja escrita» (§Para qué existe).**
¿Qué es una «decisión A»? ¿Hay una B y una C? ¿Dónde está esa lista? Es una referencia a algo que el
capítulo no presenta nunca más.
**Debería decir:** «nada se publica ni se paga sin que el dueño lo apruebe; el propio código lo deja
escrito como la primera de sus decisiones de diseño (`src/Reviews/Moderation.php:3-5`)» — sin la
etiqueta interna, o con una nota de qué numeración es.

**1.3 · «mu-plugin» se usa 6 veces y no está en el glosario.**
«el mu-plugin `akibara-home-reviews.php` que lo servía **ya no existe** en `mu-plugins/`» (§12).
Para alguien que no conoce WordPress, «plugin», «mu-plugin» y «tema» son tres palabras nuevas y el
glosario sólo cubre la tercera de rebote.
**Debería añadir al glosario:** «**mu-plugin** (*must-use plugin*): un trozo de código propio que
WordPress carga siempre y que no se puede desactivar desde el panel. Viven en
`wp-content/mu-plugins/`. Akibara tiene 18.»

**1.4 · «PDP» aparece una sola vez, sin traducir, en D9.**
«*«La divulgación del incentivo va en el listado del PDP»* y esa superficie **no existe**». En todo el
resto del capítulo esa misma pantalla se llama «la ficha del producto».
**Debería decir:** «…va en el listado de la ficha del producto (lo que en el código se abrevia PDP)…».

**1.5 · «carril» tiene dos significados y el glosario sólo da uno.**
El glosario: «**Carril**: en este plugin, cada una de las formas de ganar puntos». Pero §4 dice «si la
tabla no se crea, **todo el carril muere en silencio**» y §5 «Carril apagado o sin Action Scheduler →
nada», donde «carril» ya no es una forma de ganar puntos sino toda la cadena
invitación→formulario→premio. Leyendo §4 entendí «se dejan de ganar puntos», que no es lo que pasa: lo
que muere es la emisión de enlaces.
**Debería decir:** o bien usar «el circuito de reseñas» para la cadena y reservar «carril» para el
premio, o añadir el segundo sentido al glosario.

**1.6 · «huella» y «sello» parecen dos cosas y son la misma.**
Aparecen «Sale **sin sello y sin huella**» (compuerta 8), «Sella con 0» (compuerta 7), «*descartada
con huella* (0)» (§Estados). El glosario define «Sello» y no dice nada de «huella». Tardé en entender
que «dejar huella» = «escribir el sello con 0» y «sin huella» = «no escribir la meta».
**Debería decir:** una frase al presentar el sello: «"dejar huella" en este capítulo significa
exactamente escribir esa meta, aunque sea con un 0: es lo único que distingue una reseña evaluada y
descartada de una que nadie ha mirado».

**1.7 · «first-party» (2 usos) no se explica.**
«el shortcode **no tiene un solo llamador en todo el árbol first-party**» (§12). El glosario no lo
tiene.
**Debería decir:** «en el código propio de Akibara (los cuatro plugins, el tema y los mu-plugins) —
lo que en el proyecto se llama *first-party*».

**1.8 · «slug» (§3, dos veces) y «noindex» (§6) no se explican.**
«un **slug** que no exista cae al valor de fábrica»; «en modo oscuro de marca y con `noindex`».
**Debería añadir al glosario:** «**Slug**: el nombre corto e interno de algo (un estado de pedido, una
página), sin espacios ni acentos: `shipping-progress`, `completed`.» · «**`noindex`**: una marca en la
página que le pide a Google que no la incluya en sus resultados.»

**1.9 · «el token se quema antes de crear la reseña» (§6) — «quemar» no se define.**
El glosario tiene «Token» («la cadena larga y aleatoria del enlace personal… un solo uso») pero no
dice que «quemar» es marcarlo como usado.
**Debería decir:** «el token se marca como usado ("se quema") **antes** de crear la reseña».

**1.10 · Caso 3 empieza en «Productos → Valoraciones» sin haber dicho nunca por qué está ahí.**
Toda la §8 explica que una reseña es un comentario retenido por `pre_comment_approved`. Un novato
buscará las reseñas en *Comentarios*. La razón está escrita en el propio código —«en Productos →
Valoraciones (WooCommerce no las lista en Comentarios)», `src/Support/SettingsPage.php:217`— y el
capítulo no la cita. Encima §14 manda al dueño a **Comentarios** → Crear reseña, con lo que el mismo
carril usa dos menús distintos sin una palabra de por qué.
**Debería decir:** «Una reseña es técnicamente un comentario de WordPress, pero WooCommerce las saca
de la pantalla *Comentarios* y las lista en **Productos → Valoraciones**; la pantalla para transcribir
una a mano, en cambio, cuelga de *Comentarios* porque es una pantalla propia».

**1.11 · D3 depende por completo de `shipping-progress` y el capítulo nunca dice qué es.**
D3 dice «Si ese estado no está dentro, todo pedido que muera ahí produce `verified = 0`». Pero no se
dice que «Listo para enviar» / `shipping-progress` **no es un estado de WooCommerce**: lo aporta la
integración de Blue Express (el transportista), y por eso precisamente puede no estar en la lista de
«estados pagados» del núcleo. Sin ese dato, D3 parece un capricho.
**Debería decir:** «`shipping-progress` («Listo para enviar») es un estado de pedido **inventado por
la integración de Blue Express**, no uno de los que trae WooCommerce; la lista de "estados pagados"
del núcleo trae de fábrica sólo `processing` y `completed`, y nadie en el código propio la amplía.»

**1.12 · «los siete módulos de reseñas no existen» (§Qué pasa si se apaga, punto 2) — ¿cuáles siete?**
Nunca se listan. Están en `src/Plugin.php:98-104` y son `Moderation`, `ReviewPoints`, `Carousel`,
`Schema`, `ReviewNudge`, `Invitations` y `ReviewForm`.
**Debería decir:** esa lista, ahí mismo, entre paréntesis.

**1.13 · «Hay cuatro interruptores de distinto calibre» y hay cinco.**
La lista numerada da: SPAR activo, CusRev instalado, «Dar puntos por reseña con foto», «Invitar a
reseñar por correo». Falta `akb_rewards_carousel_enabled` («Carrusel del home»), que apaga la sección
de la portada entera en `src/Reviews/Carousel.php:187-189` y que el propio capítulo lista después en
sus tablas.
**Debería decir:** cinco, con el del carrusel como quinto: «apaga la sección de la portada sin tocar
nada más».

---

## 2 · Lo que FALTA para poder operar o depurar esto sin ayuda

**2.1 · Las dos pantallas no traen su dirección.**
«**WooCommerce → Puntos y Reseñas** (`:357-364`)» y «**Comentarios → Crear reseña** (`:61-69`)». Los
identificadores están en el código: `akb-rewards-settings` (`src/Support/SettingsPage.php:27`) y
`akb-rewards-new-review` (`src/Reviews/AdminReviews.php:65`).
**Debería decir:** «WooCommerce → Puntos y Reseñas (`/wp-admin/admin.php?page=akb-rewards-settings`)»
y «Comentarios → Crear reseña (`/wp-admin/edit-comments.php?page=akb-rewards-new-review`)». En este
proyecto ya costó tiempo un identificador de panel equivocado que devolvía 403 y parecía un problema
de permisos.

**2.2 · No se dice dónde se mira la cola de trabajos.**
El capítulo nombra el trabajo `akb_rewards_send_review_invite` una docena de veces y nunca dice dónde
se ve si está agendado.
**Debería decir:** «**WooCommerce → Estado → Acciones programadas**, filtrando por el gancho
`akb_rewards_send_review_invite`; por línea de comandos,
`wp action-scheduler list --hook=akb_rewards_send_review_invite`».

**2.3 · Qué hacer cuando `/resena/{token}/` da 404.**
El capítulo explica con detalle por qué la ruta existe (`Points\AccountRewards` refresca los enlaces
permanentes una vez por firma de reglas) pero no dice qué hacer si aun así falla. Faltan dos cosas
concretas: (a) forzar el refresco entrando a **Ajustes → Enlaces permanentes → Guardar cambios**, o
borrando la option `akb_rewards_rewrite_version` (`src/Points/AccountRewards.php:38`); (b) que el
formulario se puede abrir **sin reglas de reescritura** en
`https://akibara.cl/?akb_review_token=<token>`, porque la variable está registrada en
`ReviewForm::register_query_vars()` (`src/Reviews/ReviewForm.php:37`, `:73-80`). Esa segunda es la
prueba de un minuto que separa «la página está rota» de «las reglas no se refrescaron».

**2.4 · No hay forma documentada de re-invitar a un cliente.**
§4 dice «Un pedido puede tener varias filas en su historia — la re-invitación está contemplada
(`:188-191`)». Pero `_akb_rewards_invite_sent` corta para siempre en
`src/Reviews/Invitations.php:124-126` y **no hay ningún botón en ninguna pantalla** que lo borre.
**Debería decir:** «La tabla admite varias invitaciones por pedido, pero hoy **nada las dispara**: en
cuanto el pedido lleva `_akb_rewards_invite_sent` no vuelve a agendarse jamás. La única manera de
re-invitar es borrar esa meta del pedido a mano (`wp post meta delete <id> _akb_rewards_invite_sent`,
o el equivalente en HPOS) y volver a llevar el pedido al estado disparador. Eso es un pendiente, no
una función.»

**2.5 · La option que gobierna la limpieza de enlaces no se nombra.**
D10 dice «Apagar «Hacer vencer los puntos» — un clic que la pantalla presenta como inofensivo». La
option es `akb_rewards_expiry_enabled` (`src/Points/Expiry.php:46`). Sin el nombre no se puede
comprobar por línea de comandos si la limpieza está viva.
**Debería decir:** «…«Hacer vencer los puntos» (`akb_rewards_expiry_enabled`); se comprueba con
`wp option get akb_rewards_expiry_enabled`».

**2.6 · El cron de limpieza puede tardar hasta 12 horas en re-armarse y el capítulo no lo dice.**
`Expiry::ensure_scheduled()` **sólo corre en el panel, en el cron o por línea de comandos**
(`src/Points/Expiry.php:83-85`) y memoriza el resultado en un transient de 12 horas (`:97`). Es decir:
si el dueño vuelve a encender el vencimiento, la limpieza de tokens no queda agendada hasta la
siguiente carga de una página de administración, y si el transient está puesto, ni eso.
**Debería añadirse** a la tabla «Lo programado», columna «Quién lo programa».

**2.7 · La tabla del libro contable no se nombra en todo el capítulo.**
Se habla del «ledger» 13 veces («el premio de 500 puntos es una fila más del libro contable»,
«el índice único del ledger lo bloquea») y nunca se dice cómo se llama:
`{prefijo}akb_rewards_ledger` (`src/Support/Schema.php:49-53`, creada en `:166-183`). La sección
«Datos que deja → Tabla propia» lista **sólo** `akb_rewards_review_forms`.
**Debería decir:** una segunda fila en esa tabla, aunque su dueño sea el capítulo de puntos, con la
consulta que responde la pregunta que uno se hace: `SELECT * FROM wp_akb_rewards_ledger WHERE
ref_type='review'`.

**2.8 · D3 no dice que arreglar el problema no arregla las reseñas ya marcadas.**
`verified_purchase()` calcula **una sola vez** y persiste (`src/Reviews/ReviewPoints.php:315-322`): si
la meta `verified` ya existe, no se recalcula nunca. Así que el día que `shipping-progress` entre en
la lista de estados pagados, las reseñas que ya quedaron con `verified = 0` **siguen sin cobrar**.
**Debería decir:** «El arreglo de D3 son dos pasos, no uno: añadir el estado a la lista de pagados
**y** barrer las comment-meta `verified = 0` ya escritas para que se recalculen. Sin el segundo paso
el arreglo no repara nada de lo pasado.»

**2.9 · De las cinco mediciones pendientes, sólo una trae su comando.**
La lista final («Lo que este capítulo no puede cerrar») nombra cinco cosas y sólo D3 lleva su
`wp eval`. Las otras cuatro tienen comando conocido y cabe en una línea cada una:
- portada: `wp option get page_on_front` y después `wp post get <id> --field=content | grep -c akibara_home_reviews`
- estados terminales: `wp db query "SELECT status, COUNT(*) FROM wp_wc_orders GROUP BY status"`
- avisos del núcleo: `wp option get moderation_notify` y `wp option get comments_notify`
- CusRev: `wp plugin list | grep customer-reviews-woocommerce`

**2.10 · No se dice cómo tirar el caché del carrusel sin guardar la pantalla.**
«**Qué escribe:** el transient `akb_rewards_carousel_v1`, una hora».
**Debería decir:** «se borra con `wp transient delete akb_rewards_carousel_v1`, o guardando la
pantalla de ajustes, que hace lo mismo».

**2.11 · Quien tenga que retocar el formulario no va a encontrar su CSS.**
§6 dice «una página propia, autocontenida, sin JavaScript, en modo oscuro de marca». El estilo está
**escrito dentro del PHP**, en la cadena de `ReviewForm::render_shell()`
(`src/Reviews/ReviewForm.php:585-611`), y en `assets/` del plugin **no hay ningún fichero de reseñas**
(sólo `carousel.js` y `checkout-blocks.js`).
**Debería decir:** «todo el estilo va incrustado en `render_shell()` (`:585-611`): no hay hoja de
estilos que buscar, y tocar los colores de marca es editar ese PHP».

**2.12 · «Si el tema la cargó, y sólo entonces» — y no hay forma de saber si la cargó.**
Sobre GSAP. La respuesta está a dos ficheros: el tema registra `gsap` y `gsap-scrolltrigger` en
`themes/akibara-gp/inc/enqueue.php:433-437`, sólo en superficies con rejilla de productos, y esa lista
(`akibara_gp_surface_has_product_loop()`, `themes/akibara-gp/inc/page-targets.php:101-118`) **incluye
la portada**. O sea: en la portada la animación sí corre.
**Debería decir:** eso, con las dos citas. Hoy el lector se queda con un condicional que no puede
resolver.

**2.13 · No hay tabla de los ganchos propios que este carril emite.**
El capítulo nombra `akb_rewards_review_points_paid`, `akb_rewards_review_pay_failed`,
`akb_rewards_reviews_engine_on` y `akb_order_preventa_can_request_review`, repartidos por seis
secciones distintas. Falta una tabla de cuatro filas: gancho · tipo (acción/filtro) · dónde se emite ·
quién lo escucha hoy. Es exactamente la tabla que hace obvio D2 (un evento emitido sin oyente) y el
punto 3.2 de más abajo (una alarma sin oyente).

**2.14 · La sección «Carrusel del home» se lista por sufijos, no por etiquetas.**
«`akb_rewards_carousel_enabled` (sí), `_min` (8), `_min_chars` (40), `_cards` (8), `_title` («Voces
del Distrito») y `_exclude_rewarded` (no)». La sección «Reseñas», en cambio, sí trae las doce
etiquetas de pantalla. Así el lector no puede encontrar dos de las seis: en pantalla se llaman
«Carrusel del home» (`src/Support/SettingsPage.php:279`) y «Título de la sección» (`:311`).
**Debería decir:** la misma tabla de tres columnas que la sección de arriba.

**2.15 · El capítulo se apoya en otro capítulo y no lo nombra.**
`Points\Ledger`, `Points\Expiry`, `Points\Emails`, `Points\AccountRewards` y `Points\ProgramPage` son
del capítulo de Puntos, y `CompletionPolicy` / `ReadyToShipCron` del de preventa. El capítulo dice
«Este capítulo cuenta sólo la segunda mitad» y nunca dice cuál es la primera ni cómo se llama el
fichero.
**Debería decir:** «la primera mitad está en `10-rewards-akibara-puntos.md`; la compuerta de preventa,
en `04-business-disponibilidad-preventa-y-avisos.md`».

**2.16 · D11 cita dos normas legales sin una palabra de qué son.**
«la procedencia que el propio código justifica citando CONAR art. 20 y la Ley 21.719».
**Debería decir:** qué obliga cada una en una línea, o quitarlas: tal como están, el lector no puede
juzgar si el dato es necesario o no.

**2.17 · «cero reseñas en producción» y «~1.600 fichas» se afirman cuatro veces sin recibo.**
Líneas 51, 494, 564, 754 y 1094. El preámbulo promete «donde ninguno de los dos podía cerrar el asunto
con el código a la vista queda dicho que **hay que medirlo**», y estos dos números no salen del
código: son estado de la base de datos. No están citados y tampoco están en la lista final de lo que
hay que medir, aunque **la mitad de las conclusiones del capítulo cuelgan de ellos**.
**Debería decir:** o la fecha y el comando con que se midieron
(`wp db query "SELECT COUNT(*) FROM wp_comments WHERE comment_type='review'"` y
`wp post list --post_type=product --format=count`), o entrar como puntos 6 y 7 de la lista de
mediciones.

**2.18 · Dónde acaba físicamente la foto, y cómo encontrar las huérfanas.**
«cada foto aprobada queda en `uploads/` como dirección pública y colgada del producto». Falta lo
operable: que se ve en **Medios**, que el adjunto queda con el tomo como padre
(`src/Reviews/Media.php:78-85`), y que no hay ninguna pantalla que liste las fotos de reseñas
rechazadas — la pregunta 5 al dueño se formula sin dar al operador la consulta para siquiera contarlas.

---

## 3 · Lo que CONTRADICE al código

**3.1 · `has_real_photo()` tiene dos ramas y el capítulo describe una.**
Dice §7: «**La vara oficial del premio:** `has_real_photo()` (`:45-59`) exige que el adjunto exista
**y siga siendo una imagen**». Eso es cierto sólo de la primera rama. La segunda,
`src/Reviews/Media.php:52-56`, acepta cualquier fila de la meta heredada `ivole_review_image` con un
`url` no vacío — **sin comprobar que exista, ni que sea una imagen, ni que esté en el servidor**:

```php
foreach ( (array) get_comment_meta( $comment_id, self::META_REMOTE_IMAGE ) as $remote ) {
    if ( is_array( $remote ) && ! empty( $remote['url'] ) ) { return true; }
}
```

Consecuencia real: una reseña heredada de CusRev con sólo foto remota **pasa la compuerta 8 y cobra
los 500 puntos** sin que haya ningún adjunto. Además, la tabla «Metas de reseña» del capítulo omite
tres claves que el código declara y escribe: `ivole_review_image` (`Media.php:30`),
`ivole_review_video2` (`:31`) y `ivole_review_video` (`:32`) — las cuatro entran en el recuento de
`ivole_media_count` (`:101`).
**Debería decir:** «La vara tiene dos varas: un adjunto local que siga siendo imagen, **o** una fila
de la meta remota heredada `ivole_review_image` con URL no vacía, que no se valida de ninguna forma.»

**3.2 · «dispara la alarma `akb_rewards_review_pay_failed`» — no hay ninguna alarma.**
Caso 3: «Si el candado por usuario está ocupado: sale **sin sellar**, dispara la alarma
`akb_rewards_review_pay_failed`». Ese gancho se emite en dos sitios
(`src/Reviews/ReviewPoints.php:240` y `:274`) y un barrido por `plugins/`, `mu-plugins/` y `themes/`
devuelve **cero oyentes**. Tampoco escribe en `Support\Log` — y el propio capítulo dice en §16 que en
esta área el registro lo usan exactamente dos sitios, ninguno de ellos éste. No suena en ninguna
parte.
**Debería decir:** «dispara el gancho `akb_rewards_review_pay_failed`, que **hoy no escucha nadie**:
no hay correo, ni línea en el registro, ni aviso en pantalla. Es un punto de enganche preparado y sin
usar — pertenece a la misma familia de D11.»

**3.3 · Los «siete cortes en orden exacto» de la agenda son ocho.**
§5 numera: carril, pedido caído, estado, ya invitado, preventa, trabajo en cola, agendar. Entre el
tercero y el cuarto el código tiene un corte más, `src/Reviews/Invitations.php:119-122`:

```php
$order = wc_get_order( $order_id );
if ( ! $order instanceof \WC_Order ) { return; }
```

Y lo mismo en el envío (`:161-164`), que el capítulo tampoco lista. Es una salida silenciosa real
(pedido en papelera, id que ya no resuelve) y para quien depure «se agendó y no salió» es justo la que
no queda anotada en ningún sitio.

**3.4 · El índice único no está donde se cita.**
§Para qué existe: «garantizado por un índice único en la tabla de contabilidad
(`src/Reviews/ReviewPoints.php:268`)». Esa línea es el `sprintf( 'review_u%d_p%d', … )` que **arma la
clave**. El índice se declara en `src/Support/Schema.php:181` — `UNIQUE KEY event_key (event_key)` —
sobre la tabla `{prefijo}akb_rewards_ledger`, creada en `:166-183`. Quien vaya a `ReviewPoints.php:268`
buscando un índice no lo encuentra.

**3.5 · El estado «SIN SELLO» tiene una causa más de las cuatro que se listan.**
§Estados: «*sin sello* (cuando la corta una compuerta que no deja huella)» y el diagrama enumera «sin
foto real, sin compra verificada, producto no publicado o candado ocupado». Falta la quinta y es la
peor: si `Ledger::credit()` no devuelve un entero —fallo de infraestructura de base de datos— el
código sale en `src/Reviews/ReviewPoints.php:272-275` **también sin sellar**, después de haber pasado
las once compuertas. Una reseña perfecta y una caída de la base quedan indistinguibles.

**3.6 · La rama «candado ocupado» no puede ocurrir con los valores de fábrica.**
Caso 3 la presenta como una bifurcación viva. Pero todo el bloque del candado está dentro de
`if ( $every > 1 )` (`src/Reviews/ReviewPoints.php:216`), y `akb_rewards_review_every_n` vale **1** de
fábrica. Con la tienda como está, `Ledger::with_user_lock` no se llama nunca y esa rama es inalcanzable.
**Debería decir:** «…y, **sólo si el dueño puso «premiar cada N» mayor que 1**, si el candado por
usuario está ocupado…».

**3.7 · «crea la reseña ya aprobada (`:151`)» apunta a la línea equivocada.**
`src/Reviews/AdminReviews.php:151` es `wp_insert_comment( wp_slash( $commentdata ) )`. La aprobación
se decide en `:141`: `'comment_approved' => ! empty( $args['approved'] ?? true ) ? 1 : 0`, es decir un
parámetro con valor por defecto, no un 1 fijo. Importa porque el motor (`create_review()`) admite
crear reseñas **no** aprobadas, aunque la pantalla de hoy no ofrezca esa opción.

**3.8 · La compuerta de preventa no exige lo que el capítulo dice que exige.**
§16 y Caso 11: «`can_request_review()` exige, para un pedido con preventa, que **todos** sus ítems
estén listos **y** que el pedido esté en `completed`». En
`plugins/akibara-business/src/Modules/OrderPreventa/CompletionPolicy.php:170-172` lo que se exige es
`can_complete( $order ) && $order->has_status( 'completed' )`, y `can_complete()` (`:157-159`) se
cumple con que **no queden ítems pendientes _o_ que `_akb_eta_ready_to_ship` valga `yes`**. Después de
que el cron voltea esa marca, la primera mitad ya está satisfecha: lo único que falta es el estado.
Esa precisión es la que explica por qué el arreglo de D2 es de verdad una línea.

**3.9 · El registro que avisa de la tabla que no se creó nombra la tabla equivocada.**
§4: «si la tabla no se crea, **todo el carril muere en silencio** salvo una línea en el registro, que
la escribe `maybe_install()` (`:100-112`)». Esa línea
(`src/Support/Schema.php:102-112`) imprime **siempre** `self::ledger_table()`, aunque la que falte sea
`akb_rewards_review_forms` — `schema_ready()` (`:214-219`) comprueba las dos, pero el mensaje sólo sabe
nombrar una. El operador lee «`akb_rewards_ledger` no existe» y busca donde no es.
**Debería decir:** eso, como aviso al lector; y de paso es un defecto de una línea que merece entrar en
la lista.

**3.10 · «Si una reseña no tiene foto propia, la tarjeta usa la carátula» usa una vara distinta de la
del premio.**
`src/Reviews/Carousel.php:312-320` recorre **sólo** `Media::META_LOCAL_IMAGE`. Así que una reseña que
cobró los 500 puntos por la rama remota (punto 3.1) sale en el carrusel con la portada del tomo, como
si no tuviera foto. Son dos preguntas distintas —«¿tiene foto real?» y «¿tiene foto que pintar?»— con
dos respuestas posibles para la misma reseña, y el capítulo las presenta como una.

**3.11 · «la línea "El enlace es personal y vence en 30 días"» no es texto fijo.**
§5 la entrecomilla como copia literal. En `src/Reviews/Invitations.php:266` es
`'…vence en %d días.'` alimentado por `self::token_days()`, o sea por la option «Vida del enlace de
reseña (días)». Si el dueño la cambia a 15, el correo dice 15. Lo mismo con «500 Puntos Akibara» en el
asunto del correo del premio (`src/Points/Emails.php:389`), que el capítulo cita como asunto fijo.

**3.12 · Una fila de la tabla de datos se sale del patrón y despista.**
«`akb_rewards_carousel_min_chars` … **Quién la lee:** la consulta de `Carousel::build_data()`
(`:284`)». Todas las demás filas nombran el accesor de `Options`, y esta option también tiene el suyo:
`Options::carousel_min_chars()` (`src/Support/Options.php:312`), que es a quien llama `Carousel.php:284`.
Leído en serie, parece que esta option se salta la autoridad única que §2 acaba de presentar.

---

## 4 · Lo que contradice a `verificacion.json` del área

**4.1 · «once compuertas» esconde una compuerta, y el verificador las enumeró doce.**
El verificador (`errores[2]`) escribe «En el código son 11 cortes secuenciales» y acto seguido lista
**doce**: «programa encendido, tipo review, no-respuesta, producto publicado, sello, admin-created,
cupón viejo, foto real, cuenta, compra verificada, **tope por cliente**, **cadencia**». El capítulo se
quedó con el número y resolvió el desajuste **fusionando las dos últimas en una sola fila** (fila 11:
«Tope histórico por cliente y cadencia «cada N reseñas» | `:210-214` y `:216-252`»). En el código son
dos salidas distintas, en dos bloques distintos, con consecuencias distintas: el tope sella con 0 y
vuelve; la cadencia puede sellar con 0, o salir **sin** sellar si el candado está ocupado.
**Debería decir:** doce filas, o once más una nota explícita de que la fila 11 son dos compuertas.

**4.2 · Una sospecha que el verificador dejó viva se presenta como refutada.**
El recuadro final del capítulo dice: «Dos sospechas del mapa que el verificador refutó, **y que por
tanto no son defectos**… (c) El interruptor «Moderación obligatoria» no gobierna el formulario propio
—es cierto— pero **ninguna pantalla promete lo contrario**… Es un defecto de simetría, no una palanca
que miente.» El verificador (`sospechas_refutadas[4]`) dice literalmente: «**Matizada, no refutada del
todo**… **Baja la severidad de MEDIA a BAJA** salvo que el dueño pida explícitamente la
auto-publicación». O sea: sigue siendo un defecto, de severidad baja. El capítulo lo sacó de la lista.
**Debería decir:** un D22 de severidad baja: «el interruptor "Moderación obligatoria" no gobierna la
reseña del formulario propio (`ReviewForm.php:226` fija el 0 a mano y no pasa por
`pre_comment_approved`): apagarlo no publica automáticamente lo que llega por `/resena/`».

**4.3 · El recuadro dice «Dos», lista tres y el fichero tiene cuatro.**
Encabeza «**Dos** sospechas del mapa que el verificador refutó» y a continuación numera (a), (b) y
(c). `verificacion.json` tiene **cuatro** entradas en `sospechas_refutadas`; la cuarta (los dos relojes
de `_akb_rewards_invite_scheduled`) el capítulo la resuelve bien, pero en otro sitio —la sección
«Datos que deja»—, con lo que ni el número ni la lista cuadran con nada.

**4.4 · D9 baja de «alta» a «media» sin decirlo, contra la regla del propio preámbulo.**
El verificador clasifica esa sospecha (`sospechas_nuevas[2]`, «El JSON-LD publica reseñas incentivadas
con 500 puntos sin divulgación…») como **severidad alta**. El capítulo la publica como «**D9 · media**».
Las otras tres «altas» del verificador (compra verificada, sin reversa, preventa) sí llegan como D3,
D4 y D2 en alta. El preámbulo promete «Donde el mapa de lectura y el verificador discreparon, **manda
el verificador**»: aquí mandó el capítulo, en silencio.
**Debería decir:** o D9 en alta, o una línea explicando por qué se rebaja (por ejemplo: «con cero
reseñas en producción el riesgo es futuro, no vigente»), que es un argumento razonable pero hay que
escribirlo.

**4.5 · La lista final de mediciones deja fuera dos de las que el verificador declaró incerrables.**
Las `notas` del verificador dicen que la cobertura no llega a 100 porque tres sospechas dependen de
terceros o de configuración: «S5 (cuándo dispara WooCommerce el evento de email verificado), S10
(soporte HEIC del servidor) y la nº1 nueva». La lista «Lo que este capítulo no puede cerrar» recoge
cinco puntos y **no incluye ni S5 ni HEIC** — aparecen sueltos, como «Hay que medirlo» dentro del
Caso 4 y de D13, fuera de la lista que alguien va a usar como guion de la ronda de medición.
**Debería decir:** siete puntos en esa lista, con los dos que faltan.

---

## 5 · Diagramas

**Validación:** los seis `.mmd` del capítulo renderizan sin error con
`mmdc -i diagramas/11-rewards-resenas-<x>.mmd -o <x>.svg -b white`. Nada que arreglar ahí.
(La instrucción del preámbulo funciona tal cual está escrita; lo único que no dice es cómo conseguir
`mmdc`, que no viene con el sistema.)

**5.1 · Ninguno se entiende solo: los códigos «D1…D17» no tienen leyenda.**
Los seis diagramas están sembrados de «· D1», «· D15», «· D3», «· D11». El de piezas incluso tiene una
leyenda de colores en su comentario de cabecera —«Naranjo = código propio; gris = tercero…»— y aun así
no explica los D. Renderizado aparte, que es como el propio capítulo dice que hay que verlos, el
lector no tiene forma de saber qué es D1.
**Debería decir:** una línea al pie de cada diagrama del tipo «Dn = defecto nº n de la lista del
capítulo 11», o sustituir «· D2» por «· defecto 2 del capítulo».

**5.2 · `piezas`: akibara-business está pintado como «tercero» y es código propio.**
`CPOL["Business · CompletionPolicy…"]:::tercero` y `READY["Business · ReadyToShipCron…"]:::tercero`,
con la leyenda «gris = tercero». Pero el primer párrafo del capítulo dice «código propio repartido en
**cuatro plugins**», y akibara-business es el principal de los cuatro. El diagrama le dice al novato
justo lo contrario de lo que el texto le acaba de enseñar — y de paso hace que D2 parezca «tocar
código ajeno», que es exactamente el malentendido que el capítulo quiere evitar.
**Debería decir:** `:::propio` para ambos, y si hace falta distinguir «otro plugin nuestro», un cuarto
color o un borde punteado con su entrada en la leyenda.

**5.3 · `piezas`: la compuerta no gatea nada.**
El subgrafo `GATE` se titula «Quién decide que el motor exista» y `PLUGIN` lleva escrito «los siete
módulos de reseñas viven dentro de un gate». Pero `PLUGIN` **no tiene ni una flecha de salida**: la
única arista que lo toca es `OPTIONS --> PLUGIN`. Visualmente, la decisión más importante del capítulo
no decide nada. Y `ADMIN` (`AdminReviews`), que es el único módulo que **sobrevive** al gate, está
dentro del subgrafo «3 · Moderar» sin ninguna marca que lo diga.
**Debería decir:** una flecha de `PLUGIN` a cada uno de los cinco subgrafos de etapa (o al menos a los
que mueren), y un borde distinto para `ADMIN` con la nota «vive fuera del gate».

**5.4 · `piezas`: `LEDGER --> MAILS` es falso.**
El libro contable no manda correos. Quien dispara `akb_rewards_review_points_paid` es `ReviewPoints`,
en `src/Reviews/ReviewPoints.php:296`, y sólo después de comprobar que el saldo se movió de verdad
(`:294`). El propio §9 del capítulo lo explica así. La flecha debería salir de `PTS`, con la etiqueta
`akb_rewards_review_points_paid`.

**5.5 · `piezas`: tres nodos no tienen quién los active.**
`NUDGE` (el párrafo del correo de entrega) sólo tiene salida hacia `CLIENTE`: nada muestra que lo
dispara `woocommerce_email_order_details` desde `WCORD`. `SCH` (el bloque para Google) sale hacia `RM`
pero nadie le entra: no se ve que lee las reseñas aprobadas de `WPCOM`. `TEMA` sale hacia `CLIENTE` sin
entrada, cuando lo que hace es dar estilo a la lista nativa que pinta `WPCOM`. Los tres quedan como
cajas que aparecen de la nada.

**5.6 · `piezas`: faltan cuatro piezas que el texto sí nombra.**
No están: *Simple Points and Rewards* (el interruptor nº 1 del capítulo, el único que impide que el
plugin **entero** arranque), `Support\Schema` (que crea y mantiene la tabla de enlaces, y cuyo fallo
mata todo el carril), `Support\Log` y `Points\ProgramPage` (una de las seis superficies que prometen
el premio, y la única pública). La tabla `akb_rewards_review_forms` sí aparece, pero huérfana de su
dueño.

**5.7 · `caso-invitacion`: la nota dice «siete cortes» y el diagrama dibuja seis.**
`Note over INV: siete cortes en orden — Invitations.php:100-147`, y las ramas `alt/else` son: carril
apagado · pedido caído · estado distinto · ya invitado · preventa · todo en orden. El sexto corte
—«ya hay un trabajo en cola», `Invitations.php:136-138`— sólo sobrevive como el texto «uno por pedido»
en la etiqueta de la flecha de agenda. Y ninguno de los dos, texto ni diagrama, incluye el corte real
de `:119-122` (ver 3.3).

**5.8 · `cron`: la primera sección contradice al propio capítulo y al código.**
El diagrama abre con `section Cuando se activa el plugin / Minuto 0 : Expiry ensure_scheduled arma el
cron diario`. La tabla «Lo programado» del mismo capítulo dice, correctamente,
«`Expiry::ensure_scheduled()` en `init:20`», y el código lo confirma
(`src/Points/Expiry.php:74`): no cuelga de la activación, y además **sólo corre en panel, cron o CLI**
(`:83-85`). En la activación no se arma nada.
**Debería decir:** `section En el primer arranque con panel, cron o WP-CLI`.

**5.9 · `cron` vs la tabla: dos frecuencias distintas para lo mismo.**
El diagrama: «el cron de Hostinger llama a `wp-cron.php` **cada 5 minutos**». La tabla del capítulo,
fila `action_scheduler_run_queue`: «**Cada minuto**, empujado por la tarea de Hostinger cada 5
minutos». Un novato no puede reconciliarlas: si el único empujón llega cada cinco minutos, la cola no
puede correr cada minuto.
**Debería decir:** «Action Scheduler se declara cada minuto, pero en esta tienda `DISABLE_WP_CRON`
está encendido, así que **en la práctica corre cuando Hostinger llama, cada 5 minutos**: "a los 3
días" significa "en la primera corrida después de cumplirse el plazo"».

**5.10 · `estados`: el nodo `SinSello` enumera cuatro causas y son cinco.**
«sin foto real, sin compra verificada, producto no publicado o candado ocupado». Falta el fallo de
`Ledger::credit()` (`src/Reviews/ReviewPoints.php:272-275`), que es el único de los cinco que puede
pasarle a una reseña impecable. Y «candado ocupado» necesita la coletilla «sólo con «premiar cada N»
mayor que 1» (ver 3.6).

**5.11 · `estados`: al ciclo de la reseña le falta la vuelta atrás.**
Están `EnEspera --> Aprobada`, `EnEspera --> Rechazada` y `Aprobada --> Rechazada`. No está
`Rechazada --> Aprobada`, que es lo que pasa cuando el dueño se arrepiente — y es interesante
precisamente porque el sello ya escrito hace que la segunda aprobación **no** re-evalúe el premio
(compuerta 5, `ReviewPoints.php:175-178`). El diagrama que se llama «máquina de estados» se salta la
transición que tiene la consecuencia más contraintuitiva.

**5.12 · Dos diagramas se embeben dos veces y ocho casos se quedan sin ninguno.**
`11-rewards-resenas-piezas.mmd` aparece en las líneas 77 y 128 del capítulo, con el mismo texto
alternativo («Piezas del capítulo 11») y separado por medio kilómetro de tabla;
`caso-resena-y-premio.mmd` aparece en 633 y 668. Mientras tanto, el Caso 6 (el carrusel, cuyo
mecanismo —umbral, caché de una hora, seis invalidaciones y un shortcode sin llamador— es el más
enredado del capítulo) y el Caso 7 (lo que ve Google) no tienen diagrama ninguno.
**Debería hacer:** dejar `piezas` una sola vez (en «Cómo encaja»), y valorar un diagrama de flujo para
el carrusel, que es donde el texto pide tres condiciones encadenadas que nadie retiene leyendo.

---

## Recuento

- **No entendí:** 13 puntos · **Falta para operar/depurar:** 18 puntos · **Contradice al código:** 12 puntos.
- **Contradice a `verificacion.json`:** 5 puntos (compuertas fusionadas, sospecha viva presentada como refutada, recuento «dos/tres/cuatro», severidad de D9 rebajada en silencio, lista de mediciones incompleta).
- **Diagramas:** 12 puntos; los 6 `.mmd` validan con `mmdc`, pero ninguno se entiende solo (códigos «Dn» sin leyenda) y hay 4 contradicciones con el texto o el código (business como «tercero», `LEDGER --> MAILS`, «siete cortes» con seis ramas, el cron «en la activación»).
