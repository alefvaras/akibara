# Crítica de lector novato — `10-rewards-akibara-puntos.md`

> Quién escribe: alguien que llegó hoy al proyecto. No sé qué es WordPress, no sé qué es
> WooCommerce, no conozco el negocio del manga en Chile. Leí el capítulo entero (1.355
> líneas) y sus seis diagramas, y después abrí el código publicado
> (`scratchpad/snapshot-prod/plugins/akibara-rewards/`, 0.3.13, 37 ficheros / 10.249
> renglones) y el `verificacion.json` del área `rewards-puntos` para comprobar todo lo que
> me sonó raro. Validé los seis `.mmd` con `mmdc` 11.17.0: **los seis compilan sin error**.
>
> Lo bueno primero, porque es mucho y conviene no perderlo. (a) **Las citas son de verdad**:
> comprobé una a una más de cuarenta —`Options.php:54`, `:89`, `:135`, `:190`, `:239`,
> `:304`; `Expiry.php:46/49/58/71-74/82-99/93/98/341-343`; `Emails.php:457/463/465/473`;
> `Redemption.php:76-81/208-225/326-399`; `Accrual.php:103-106`; `Privacy.php:49-64`;
> `Schema.php:84/85/100-112/118`; `AdminAdjust.php:263-266`— y **todas caen donde el
> capítulo dice**, salvo las tres que enumero en §3. (b) Los recuentos de líneas de los 24
> ficheros que el capítulo declara son exactos, uno por uno. (c) Las correcciones del
> verificador están todas incorporadas y bien atribuidas. (d) La honestidad sobre la hora
> del cron («ninguna de las dos corridas tiene una hora fija de Chile escrita en el
> código») es justo lo que necesito para no perder una tarde buscándola.
>
> Lo que sigue es lo que me dejó colgado, lo que no me alcanza para operar, y lo que no
> aguantó el contraste con el código. Cada punto lleva **la frase del capítulo** y **qué
> debería decir**.

---

## 1 · Lo que NO entendí (término sin explicar, salto lógico, referencia a algo no presentado)

**1.1 — «Listo para enviar» no existe en WooCommerce y el capítulo no avisa.**
Dice: *«Acreditar ocurre cuando el pedido entra al estado configurado. De fábrica es
«Listo para enviar» (`shipping-progress`, `Options.php:102`)»*. Yo, que acabo de aprender
que WooCommerce trae los pedidos, busqué `shipping-progress` en la documentación de
WooCommerce y no existe: es un **estado a medida que registra otro plugin de la casa**
(`akibara-business`; el término aparece en `akibara-business/src/Modules/OrderTimeline/OrderTimeline.php`,
`.../OrderPreventa/ShippableStamp.php` y cinco ficheros más). El capítulo lo trata como si
viniera de fábrica y nunca dice de dónde sale.
*Debería decir*: «`shipping-progress` («Listo para enviar») **no es de WooCommerce**: lo
crea `akibara-business` (capítulo 02). Si ese plugin se desactiva, el estado configurado
deja de existir y **ningún pedido acredita**, sin error ni aviso.»

**1.2 — Los seis diagramas están enlazados como imágenes y ninguno se ve.**
El capítulo escribe `![Piezas del capítulo](diagramas/10-rewards-akibara-puntos-piezas.mmd)`.
Un `.mmd` es texto fuente de Mermaid, no una imagen: en GitHub, en la vista previa de
VS Code y en cualquier lector de Markdown eso sale como **imagen rota**. Abrí el capítulo
esperando ver el mapa de piezas y vi un icono partido; tuve que ir a buscar los ficheros a
mano y compilarlos yo.
*Debería decir*: o bien pegar el diagrama en línea en un bloque ` ```mermaid ` (que sí
renderiza), o bien generar el `.svg` junto al `.mmd` y enlazar el `.svg`, dejando el `.mmd`
como «fuente» en un enlace de texto debajo.

**1.3 — «Neto» y «bruto» son la bisagra de todo el canje y no están en el glosario.**
Dice: *«la comisión de canje se escribe en **neto** (`bruto / (1 + tasa)`)»* y, en el caso
C, *«Se añade una comisión negativa llamada «Puntos Akibara» escrita en **neto**:
−$923,53»*. No sé qué es «neto», no sé qué es «bruto», no sé por qué 1.099 se convierte en
923,53 ni de dónde sale el 1,19. El glosario define «comisión / fee» pero no esto.
*Debería decir*: en la primera mención, «los precios de la tienda **incluyen** el IVA de
19 % (eso es el **bruto**, lo que paga el cliente); el **neto** es el precio sin ese
impuesto. $1.099 brutos son $923,53 netos, porque 923,53 × 1,19 = 1.099». Y dos entradas de
glosario: **bruto**, **neto**.

**1.4 — «`dbDelta` falla en silencio» — ¿qué es `dbDelta`?**
Pieza 3: *«La versión sólo se sella si la tabla quedó realmente creada (`:100-112`):
`dbDelta` falla en silencio, y sellar a ciegas envenena el módulo para siempre»*. Es la
única mención en todo el capítulo y no hay entrada de glosario.
*Debería decir*: «`dbDelta` es la función de WordPress que crea o actualiza una tabla
comparando la que hay con la que se le pide. Su defecto conocido: si la creación falla,
devuelve lo mismo que si hubiera funcionado.»

**1.5 — «Deuda de revival», `RESTORED`, `REVIVAL_DEBT`, `UNSETTLED`: cuatro palabras en
mayúsculas que nunca se presentan.**
Pieza 10: *«La reposición lleva contabilidad en dos cuentas: `RESTORED` (lo acumulado
devuelto al cliente) y `REVIVAL_DEBT` (la porción devuelta por *muerte* del pedido…)»*, y
el caso K habla de *«la porción no recuperada se anota como `UNSETTLED` (`:55`)»*. Nunca se
dice que son los nombres internos de tres constantes de PHP que en la base de datos se
llaman `_akb_rewards_redeemed_restored`, `_akb_rewards_redeemed_revival_debt` y
`_akb_rewards_redeemed_unsettled` (la tabla de order meta sí trae los nombres largos, pero
600 líneas más abajo y sin decir que son lo mismo). Además «revival» es un anglicismo que
el propio capítulo usa en prosa castellana («deuda de revival»).
*Debería decir*: al presentarlas, «tres contadores que el código llama `RESTORED`,
`REVIVAL_DEBT` y `UNSETTLED` y que en el pedido se guardan como
`_akb_rewards_redeemed_restored`, `…_revival_debt` y `…_unsettled`», y en prosa «deuda de
resurrección» o «deuda por pedido revivido».

**1.6 — El resumen dice que sólo una cosa quita puntos; el diagrama dice dos.**
Intro: *«Alrededor de ese núcleo hay cinco carriles más que también regalan puntos […] y
uno que los quita: la caducidad»*. Pero el canje también resta —es un débito del ledger
(`Redemption.php:344-354`)— y el propio diagrama de piezas tiene un subgrupo «Lo que resta
puntos» con **dos** cajas: `Points\Expiry` y `Points\Redemption`. Como lector me quedé
media hora creyendo que canjear no descontaba del saldo.
*Debería decir*: «…y **dos** que los quitan: el canje, que los cambia por descuento, y la
caducidad, que se los lleva sin nada a cambio».

**1.7 — «Cada carril tiene un interruptor de un clic» y dos carriles no lo tienen.**
Sección «Qué pasa si se apaga», punto 1. En el catálogo del panel hay once casillas
`*_enabled` (`SettingsPage.php:39-329`): `earn`, `welcome`, `welcome_teaser`, `referral`,
`reviews`, `birthday`, `expiry`, `carousel`, `invite`, `review_nudge`, `pdp_notice`. **No
hay ninguna para el ajuste manual ni para el canje**, y los dos son carriles del programa
según el propio capítulo (el ajuste manual es uno de los seis del diagrama de piezas).
*Debería decir*: «Cinco de los seis carriles tienen interruptor (`Options.php:51`, `:126`,
`:251`, `:285`, `:301`). El **ajuste manual** no lo necesita —lo dispara una persona— y el
**canje no tiene forma de apagarse desde el panel**: lo más cerca es poner el tope en
pesos en… (decir qué hacer).»

**1.8 — El `?ver=` aparece en la tabla de vecinos y no se explica hasta nunca.**
*«`?ver=` compuesto por versión + fecha del fichero (`Support/Assets.php`)»*. En ese punto
del capítulo no sé qué es un asset, ni qué es un `?ver=`, ni por qué una fecha metida en una
dirección evita un problema de caché. La pieza 23 lo repite igual de comprimido.
*Debería decir*: «cada vez que el navegador pide el fichero de JavaScript de la caja del
checkout, la dirección lleva pegado un número al final (`…checkout-blocks.js?ver=0.3.13.1757`).
Si ese número cambia, el navegador y la caché entienden que es un fichero distinto y lo
vuelven a bajar; si no cambia, siguen sirviendo el viejo.»

**1.9 — «Sellar» significa cuatro cosas distintas en el mismo capítulo.**
Se sella la meta del pedido, se sella la tasa, se sella la época de migración, se sella la
versión de esquema, se sella el marcador del aviso, y además hay «contratos sellados» en el
proyecto. Todas son escrituras de una sola vez, pero un novato no tiene cómo saberlo.
*Debería decir*: una entrada de glosario —«**sellar**: escribir un dato una sola vez para
que nadie lo recalcule después; si ya está escrito, el código no lo toca»— y usarlo siempre
con el mismo sentido.

---

## 2 · Lo que FALTA para poder operar o depurar esto sin ayuda

**2.1 — La pantalla del cliente no tiene dirección escrita en ninguna parte.**
El capítulo repite catorce veces *«Mi cuenta → Puntos Akibara»* y nunca dice la URL. El
endpoint es `rewards` (`AccountRewards.php:35`) y la tarjeta de `akibara-business` enlaza
`wc_get_endpoint_url('rewards')` (`akibara-business/templates/myaccount/dashboard.php:76`),
o sea `/mi-cuenta/rewards/`. Para probar algo tengo que adivinarlo.
*Debería decir*: la URL literal, junto a la mención de la pieza 15.

**2.2 — La pantalla del dueño tampoco.**
*«Dónde lo opera el dueño. WooCommerce → Puntos y Reseñas»* (cinco veces). Falta la
dirección directa —`/wp-admin/admin.php?page=akb-rewards-settings` (`SettingsPage.php:27`)—
y falta el permiso que hace falta para verla: `manage_woocommerce` (`:25`), que el capítulo
sí menciona pero sólo para el ajuste manual.

**2.3 — El registro se nombra y no se dice cómo filtrarlo.**
*«los eventos críticos de dinero van a WooCommerce → Estado → Registros, con pantalla,
retención y filtro por origen»*. El valor del desplegable es `akibara-rewards`
(`Support/Log.php:29`). Además `Log` **sólo tiene nivel `error`** (`:38`): una corrida
correcta no deja ninguna línea, así que «no hay nada en el registro» no significa «no
corrió». Eso hay que decirlo.

**2.4 — No existe ninguna pantalla para ver el historial de puntos de un cliente, y el
capítulo no lo advierte.**
El único listado del ledger que existe es el del propio cliente, en su pantalla, y trae
**diez filas** (`AccountRewards.php:331`, `… ORDER BY created_at DESC, id DESC LIMIT 10`).
El dueño, ante «compré y no me llegaron los puntos», no tiene ninguna pantalla donde mirar.
*Debería decir*: decirlo con todas las letras y dar la salida: la consulta
`SELECT * FROM wp_akb_rewards_ledger WHERE user_id = N ORDER BY id DESC` o
`wp akb-rewards audit`, y que hoy **no hay interfaz** para esto.

**2.5 — Falta una receta de depuración, que es lo que de verdad hace falta a las 11 de la
noche.**
El capítulo tiene todos los ingredientes repartidos —la nota del pedido, la meta
`_akb_rewards_points_earned`, el ledger, el registro, la cola de acciones programadas, la
compuerta de vigencia, el caso del invitado— pero nunca los junta.
*Debería decir*: una sección «Si el cliente dice que no le llegaron los puntos, mira esto
en este orden» con seis pasos: (1) ¿el pedido tiene cuenta o es de invitado
(`Accrual.php:103-106`, y **no deja nota**)? (2) ¿está en el estado que acredita? (3) ¿fue
creado antes del inicio de vigencia? (4) ¿qué notas tiene el pedido? (5) ¿qué dice
`wp_akb_rewards_ledger` para ese `ref_id`? (6) ¿hay algo en Registros con origen
`akibara-rewards`?

**2.6 — Cómo disparar a mano las dos corridas diarias.**
El capítulo sólo nombra `wp action-scheduler run` **como causa** del doble aviso de
vencimiento, nunca como herramienta. Falta: dónde se ven y se fuerzan las acciones
(WooCommerce → Estado → Acciones programadas, botón «Ejecutar»), cómo se llaman
(`akb_rewards_expiry_daily`, `akb_rewards_birthday_daily`) y qué esperar de la ejecución.
Y falta el aviso de que forzarla a mano **duplica el correo de vencimiento**, que es
justamente lo que el capítulo documenta como el único riesgo real.

**2.7 — Falta el tercer comando de línea, y ninguno se escribe entero.**
El capítulo dice *«los comandos de línea (`wp akb-rewards migrate`, `audit`)»* y luego
*«Un segundo comando (`cleanup`) retira las tablas y datos residuales de los terceros»*
sin escribir nunca `wp akb-rewards cleanup` (`Migration.php:65-66`). Tampoco escribe una
sola línea de comando completa: `wp akb-rewards migrate --dry-run`,
`wp akb-rewards audit --fix`. Para alguien que no conoce WP-CLI, «hay un `--dry-run`
siempre» no es ejecutable.

**2.8 — La página pública `/akibara-puntos/` hay que crearla a mano y el capítulo no lo
dice.**
Pieza 17: *«Da el código corto `[akb_puntos_resumen]` que incrusta la página
`/akibara-puntos/`»*. El código no crea nada: busca una página de WordPress cuyo *slug* sea
exactamente `akibara-puntos` (`ProgramPage::SLUG`, `:30`; `get_page_by_path()`, `:58`) y si
no existe, `url()` devuelve cadena vacía (`:72-76`) y **el enlace secundario del correo de
puntos desaparece sin decir nada** (`Emails.php:495-500`).
*Debería decir*: «alguien tiene que crear una página de WordPress con el slug
`akibara-puntos` y pegarle el código corto. Si esa página no existe o cambia de slug, el
código corto sigue funcionando donde esté pegado, pero los enlaces a «cómo funciona el
programa» se apagan solos, sin error.»

**2.9 — Faltan dos de los asuntos de correo, y uno es justo el del error que el capítulo
denuncia.**
El capítulo da cuatro asuntos textuales y presume de ello, pero: (a) el ajuste manual
**negativo** manda `[Akibara] Movimiento en tus Puntos Akibara` (`AdminAdjust.php:265-266`)
y el capítulo sólo transcribe el festivo, que es el que sale mal; sin el correcto no puedo
reconocer en la bandeja cuál de los dos llegó. (b) El quinto correo registrado en
`Emails::register()` es el de reseñas, `[Akibara] Tu reseña sumó N Puntos Akibara`
(`:389`), y el capítulo afirma que esta clase «manda los cinco correos del carril de
puntos» sin nombrar ese.

**2.10 — Del correo no se dice ni de dónde sale el sobre ni cómo probarlo.**
El capítulo explica bien la política (uno por pedido, sello sólo con transporte confirmado,
doble parte) pero nunca dice qué es `Emails::send_branded()`, qué plantilla envuelve el
HTML, con qué remitente sale, ni cómo mandarme uno de prueba a mí mismo. Para «probar en el
espejo» según la regla de la casa, eso es imprescindible.

**2.11 — Una superficie entera que el cliente ve y el capítulo no menciona: la segunda
caja del checkout.**
`assets/js/checkout-blocks.js:219-246` pinta, **sólo para invitados**, un bloque con borde
dorado que dice «Marca «Crear una cuenta» y llévate más», con tres viñetas: seguimiento
Blue Express, «🎁 500 Puntos Akibara te esperan: se activan con esta compra» y «⭐ Acumula
2 puntos por cada $100 en cada compra». Se monta en `ExperimentalOrderMeta` (`:254`),
aparte de la caja de canje. La pieza 20 resume *«Qué ve el cliente. Saldo, mínimo, tope del
pedido y un campo para canjear»* — el gancho de invitado, que es la pieza de marketing que
todo comprador sin cuenta ve en cada checkout, no aparece en ninguna parte del capítulo.
Es también la única consumidora real de los campos `teaser`, `welcome_bonus`, `earn_points`
y `earn_amount` que la misma pieza enumera sin decir para qué son.

**2.12 — `Support\Assets` está en la pieza 23 y en ninguna otra parte.**
No se dice qué encola (`checkout-blocks.js` y el carrusel de reseñas), ni aparece como nodo
en el diagrama de piezas, así que no sé a qué ficheros afecta la regla del `?ver=`.

**2.13 — «Qué pasa si se apaga» sólo contempla apagar *este* plugin.**
Faltan los dos apagados que de verdad rompen el programa y que no dependen de él: (a) si
`akibara-business` se desactiva, el estado que acredita deja de existir (§1.1); (b) si la
cola de Action Scheduler deja de drenar, **el vencimiento y el cumpleaños se paran sin
ninguna alarma** —no hay vigía, y el capítulo enumera ocho avisos sin oyente, así que no hay
nadie escuchando tampoco por ahí—. Dónde se comprueba: WooCommerce → Estado → Acciones
programadas, pestaña «Pendiente».

**2.14 — El aviso de vencimiento tiene dos caminos y el capítulo sólo cuenta uno.**
Dice *«encola una acción asíncrona por cliente (`:365-369`)»*, como si fuera siempre así.
El código sólo lo hace si `use_batches()` (`Expiry.php:277-289`) es cierto; si no, dispara
el aviso **en la misma corrida**, de forma síncrona (`:372-379`). En producción
`use_batches()` siempre es cierto porque Action Scheduler viene con WooCommerce, pero el
camino existe y cambia el diagnóstico.

**2.15 — Cómo leer y corregir la época de migración.**
El capítulo la señala bien como la option más peligrosa sin pantalla, pero no da la forma de
mirarla: `wp option get akb_rewards_migration_epoch`, y qué significa vacía
(`9999-12-31 23:59:59` → los puntos heredados no vencen nunca, `Expiry.php:135-138`).

---

## 3 · Lo que CONTRADICE al código

**3.1 — «Los ocho sin oyentes» y la tabla de arriba trae nueve.**
Dice: *«Los **ocho** sin oyentes señalan exactamente los casos que cuestan plata»*. La tabla
inmediatamente anterior tiene **nueve** filas marcadas «**No**»: `akb_rewards_referral_paid`,
`…_redemption_shortfall`, `…_capture_failed`, `…_reversal_failed`, `…_lock_busy`,
`…_manual_review_needed`, `…_points_email_failed`, `…_review_pay_failed` y
`…_points_applied`. Comprobé con `grep -rn "add_action( '<aviso>'"` sobre **todo** el
snapshot (plugins, tema y mu-plugins): los nueve dan cero oyentes. El diagrama de piezas
repite el error («8 alarmas sin oyente»).
*Debería decir*: «nueve», o excluir explícitamente `akb_rewards_points_applied` diciendo que
es un aviso de extensión, no una alarma.

**3.2 — «Lo llaman los seis carriles» — lo llaman catorce ficheros.**
Pieza 6: *«**Momento.** No cuelga de ganchos: lo llaman los seis carriles»*. `Ledger::`
aparece en catorce ficheros del plugin: los seis carriles (`Accrual`, `WelcomeBonus`,
`Referrals`, `Birthday`, `AdminAdjust`, `Reviews/ReviewPoints`) **más** `Expiry`,
`Redemption`, `Audit`, `Migration`, `Emails`, `Privacy`, `AccountRewards` y
`Blocks/CheckoutIntegration`. El propio capítulo dice tres párrafos después que el débito
del canje pasa por aquí, así que la frase se contradice sola.
*Debería decir*: «lo llaman los seis carriles que suman, los dos que restan, y las
superficies que sólo leen el saldo».

**3.3 — Las citas de quién crea las tablas apuntan a los accesores de nombre, y el mismo
capítulo se cita a sí mismo de dos formas distintas.**
Pieza 3: *«Las tablas `wp_akb_rewards_ledger` (`:49-52`) y `wp_akb_rewards_review_forms`
(`:59-62`)»*. `Schema.php:49-52` es la función `ledger_table()`, que sólo **devuelve el
nombre**; `:59-62` es `review_forms_table()`, igual. Los `CREATE TABLE` están en `:169-186`
y `:194-207`. La tabla «Datos que deja», 700 líneas más abajo, cita bien el ledger
(`Support/Schema.php:169-186`) y mal la otra (`Support/Schema.php:59-62`).
*Debería decir*: `:169-186` y `:194-207` en las dos partes.

**3.4 — El aviso de «pediste menos del mínimo» no está donde el capítulo lo cita.**
Dos veces —pieza 15 (*«La caja del checkout **sí** lo explica
(`assets/js/checkout-blocks.js:145`)»*) y pieza 20 (*«cuando se pide menos del mínimo lo
dice en pantalla (`checkout-blocks.js:145`)»*)— apunta a la línea 145. La 145 es el párrafo
estático del saldo (`'Tienes ' + fmt( data.balance ) + …`); el mínimo estático está en la
146; y **el aviso del que habla el capítulo** —`'No se aplicó: el mínimo para canjear es '
+ fmt( min ) + ' puntos y pediste ' + fmt( pedido ) + '.'`— está en la **179**, con su
estado en `:70-72` y su pintado en `:209-213`.
*Debería decir*: `checkout-blocks.js:179` (el aviso) y `:146` (el texto fijo del mínimo).

**3.5 — «Manda los cinco correos del carril de puntos»: son cuatro aquí y uno fuera.**
`Emails::register()` (`:242-248`) cuelga cinco oyentes, pero uno es el de **reseñas**
(`send_review_email`, otro capítulo) y otro es el **reenvío del mismo correo de compra**
(`maybe_resend`). Y hay un correo del programa que **no sale de esta clase**: el del ajuste
manual lo arma y lo manda `AdminAdjust::notify()` (`:245-270`) llamando directamente a
`Emails::send_branded()`. El caso J del propio capítulo lo describe.
*Debería decir*: «cuatro correos propios del carril de puntos (compra —con su reenvío—,
cumpleaños y aviso de vencimiento), más el de reseñas que comparte la clase. El del ajuste
manual sale de `AdminAdjust::notify()` usando el mismo sobre.»

**3.6 — «Cada carril tiene un interruptor de un clic» (ver §1.7).**
`lane_on()` gobierna cinco carriles y el carrusel (`Options.php:51`, `:77`, `:126`, `:251`,
`:285`, `:301`). El ajuste manual y el canje no tienen ninguna casilla.

**3.7 — «Alrededor de ese núcleo […] uno que los quita» (ver §1.6).**
`Redemption` debita del ledger igual que `Expiry`.

**3.8 — El diagrama de piezas dice «3 módulos» y son cuatro ficheros, seis lecturas.**
El nodo `FUGA` dice *«Fuga: 3 módulos leen get_option crudo — Expiry 46/49/58 · PdpNotice
44 · teaser en 3 sitios»*. Los ficheros con lectura cruda son **cuatro**: `Expiry.php:46` y
`:58`, `PdpNotice.php:44`, `WelcomeBonus.php:167` y `:190`, y
`Blocks/CheckoutIntegration.php:133` (más la declaración en `:110`). El texto del capítulo
los enumera bien los cuatro; el diagrama dice tres.

**3.9 — Citas con un renglón de desfase (menores, pero erosionan la confianza que el
capítulo se ha ganado).** `redemption_fee_amount()` se cita como `(:225-234)` y la
declaración está en la **224** (el diagrama del caso C sí pone `:224-234`).
`Points/Ledger.php:44` se presenta como si fuera la escritura de `_akb_rewards_total_earned`
cuando es sólo la **constante**; la escritura está en `:110-113`, como bien dice la tabla de
datos.

---

## 4 · Lo que contradice o deja fuera al `verificacion.json` del área `rewards-puntos`

El capítulo incorporó **todas** las omisiones (7/7), todos los errores (6/6) y todas las
sospechas nuevas (5/5) del verificador, y las atribuye. De las 20 sospechas confirmadas por
lectura, **tres se quedaron en el camino**, y la primera es la más cara de todas.

**4.1 — S1 no está en el capítulo, y es el fallo más grande del programa.**
`verificacion.json` → `sospechas_confirmadas_por_lectura[0]`: *«S1 — Apagar «Dar puntos por
compra» apaga en silencio bono, referidos y correo»*. Lo comprobé en el código y es exacto,
con este camino: apagar `akb_rewards_earn_enabled` hace que `Options::earn_points()`
devuelva 0 (`Options.php:51-52`) → `Calculator::points_for_base()` devuelve 0
(`Calculator.php:38-41`) → `Accrual` entra en la rama `$points <= 0`, sella un 0 y **hace
`return` en `Accrual.php:162`**, *antes* del `do_action( 'akb_rewards_after_order_award' )`
de `:202`. Y de ese aviso cuelgan el bono de bienvenida (`WelcomeBonus.php:45`), los
referidos (`Referrals.php:53`) y el correo (`Emails.php:243`). Es decir: **apagar un
interruptor apaga otros tres carriles que siguen con su propio interruptor encendido**.
Peor todavía: el cartel del formulario de registro sigue prometiendo el bono, porque
`render_register_teaser()` sólo mira `welcome_bonus() > 0` y la casilla del gancho
(`WelcomeBonus.php:166-168`), y lo mismo el banner de Mi cuenta (`is_pending_for()`,
`:189-192`). Eso contradice frontalmente dos promesas explícitas del capítulo: *«ninguna
superficie puede prometer un premio que ningún código puede pagar»* (pieza 2) y *«Ningún
carril apagado se promete en ninguna superficie»* (tabla de reglas).
*Debería decir*: en «Qué pasa si se apaga», punto 1, un párrafo propio: «El interruptor de
«Dar puntos por compra» **no apaga sólo su carril**: como el bono, los referidos y el correo
cuelgan del aviso que sólo se dispara cuando hay una acreditación con puntos > 0, apagarlo
los apaga a los tres en silencio, y el cartel del registro sigue prometiendo el bono.»

**4.2 — S13 no está: hay una rama de código muerta en el corazón del cálculo.**
`verificacion.json`: *«Los dos llamadores (`Redemption.php:188`,
`CheckoutIntegration.php:160`) omiten `$redeemed_gross`, así que la rama de `:171-173` nunca
corre»*. Confirmado: `Calculator::award_base_for_cart( \WC_Cart $cart, float
$redeemed_gross = 0.0 )` (`:161`) tiene dos llamadores en todo el plugin y ninguno pasa el
segundo argumento, así que `if ( $redeemed_gross > 0 ) { $base -= $redeemed_gross; }`
(`:171-173`) es inalcanzable. El capítulo denuncia con mucho detalle el campo `max_pct` como
«carga muerta» y no menciona esta, que está en el fichero que él mismo llama «la
matemática» y «código puro donde importa» (pieza 7).
*Debería decir*: una línea en la pieza 7, junto a `max_pct`: «el segundo parámetro de
`award_base_for_cart()` (`Calculator.php:161`, `:171-173`) no lo usa ningún llamador: o se
usa, o se borra» — regla de la casa «cero código muerto».

**4.3 — S18 no está: la tarjeta «Recompensas» convierte puntos a pesos a 1:1 por su cuenta.**
`verificacion.json`: *«dashboard.php:85-90 llama `wc_price($akb_points)` sin pasar por
`Options::redeem_value()`»*. Confirmado en
`akibara-business/templates/myaccount/dashboard.php:84-89`: la tarjeta pinta
`'%1$s puntos · %2$s'` con `number_format_i18n( $akb_points )` y
`wc_price( $akb_points )` —los puntos metidos tal cual en una función de precios—. El
capítulo dedica una fila entera de «Con quién habla» a esa tarjeta y una frase del caso L a
que *«la ficha de producto, Mi cuenta, la página pública y el checkout dicen […] porque
todos leen el mismo accesor»*. Esta tarjeta, que está dentro de «Mi cuenta», **no** lee el
accesor: si el dueño cambia «Pesos de descuento por punto» a 2, la tarjeta seguirá diciendo
que 1.099 puntos son $1.099.
*Debería decir*: en la fila de `akibara-business`, añadir «…y esa tarjeta calcula el valor
en pesos por su cuenta (`dashboard.php:84-89`), sin pasar por `Options::redeem_value()`:
miente en cuanto el canje deje de valer $1 por punto». Es deuda de otro plugin, pero la
regla de la casa dice que la deuda previa también se arregla.

---

## 5 · Diagramas

**5.1 — Los seis validan.** `mmdc` 11.17.0, código de salida 0 en los seis, sin advertencias:
`-piezas`, `-cron`, `-estados-canje`, `-caso-compra`, `-caso-canje`, `-caso-vencimiento`. Y
los seis que el texto referencia existen, y los seis que existen se referencian: no sobra ni
falta ninguno.

**5.2 — Pero ninguno se ve (ver §1.2).** Están enlazados como `![texto](….mmd)`. Mientras eso
no cambie, el lector no ve diagramas: ve imágenes rotas. Es, de lejos, el problema más grave
de los seis.

**5.3 — ¿Se entienden solos? Cinco sí, uno no.** Los cinco pequeños llevan en la cabecera un
comentario `%%` de dos o tres líneas que dice *qué muestra* el diagrama, y eso los vuelve
autónomos: leí `-caso-vencimiento.mmd` antes que el capítulo y entendí el mecanismo entero
sin el texto. El de piezas, no: compilado mide **7.031 × 1.769 píxeles**. En una pantalla o
en un papel, eso es ilegible; para leer una caja hay que ampliar tanto que se pierde el
resto del dibujo.
*Debería*: partirse en dos —«el motor y los carriles» y «las superficies, la operación y los
datos»—, o pasar el `flowchart TB` a `LR` por subgrupos. Los otros cinco están entre 2.100 y
3.600 px de ancho, que ya es mucho pero se sostiene.

**5.4 — Nodos que el texto nombra y el diagrama de piezas no tiene.** Faltan tres piezas
completas del capítulo: el **arranque** (`akibara-rewards.php` /
`akibara_rewards_check_dependencies` / `Plugin::boot()`, pieza 1), `Support\Assets`
(pieza 23) y `uninstall.php` (pieza 24). Se nota sobre todo en la flecha
`SPAR -.->|"mientras viva, el boot no corre"| MOTOR`: el rótulo habla del **boot**, que no
está dibujado, y la flecha acaba apuntando al subgrupo del motor, que es otra cosa. Debería
existir un nodo `BOOT` y que SPAR apunte ahí.

**5.5 — Números que sólo viven en el diagrama.** El nodo de terceros dice «WordPress 6.8»
—cifra que el texto nunca da (está en la cabecera del plugin: `Requires at least: 6.8`)— y
los nodos de datos dicen «11 usermeta», «10 order meta» y «43 options», que sí cuadran con
las tablas del capítulo (40 con casilla + `db_version` + `rewrite_version` +
`migration_epoch`). Está bien que cuadren; convendría que el texto diera también el 6.8 al
hablar de las tres puertas de arranque, donde sí cita el mínimo de WooCommerce.

**5.6 — El diagrama de piezas repite el error de «3 módulos» y «8 alarmas»** (ver §3.1 y
§3.8). Son cuatro y nueve.

**5.7 — `-estados-canje.mmd` no nombra `UNSETTLED`, que es un estado del dinero.** El nodo
`Recobrado` dice *«si el cliente ya gastó lo repuesto, el débito se recorta, la deuda se
cancela con alarma»*, pero nunca dice que esa porción queda anotada como `UNSETTLED`
(`Redemption.php:55`), que es justo lo que hace que el pedido revivido deje de puntuar sobre
plata que nunca entró —el caso K del texto lo explica y el diagrama, que es el mapa de ese
caso, se lo salta—.

**5.8 — `-cron.mmd` está escrito con entidades HTML (`&#40;`, `&#58;`) para poder meter
paréntesis y dos puntos en un `timeline`.** Es una limitación real de Mermaid y la solución
es correcta, pero deja el fichero fuente ilegible como texto: quien abra el `.mmd` verá
`akb_rewards_expiry_daily &#40;Expiry.php&#58;93&#41;`. Merece un comentario `%%` de una
línea explicándolo, para que nadie «arregle» las entidades y rompa el diagrama.

**5.9 — Los tres diagramas de casos llevan las mismas cifras que el texto y las mismas
advertencias**, incluida la del residuo de ±$1 y la alarma falsa de `shortfall`, y el de
canje incluso cita `:224-234` mejor que el texto (§3.9). Eso está muy bien: la única
diferencia real que encontré entre texto y diagramas son los dos recuentos del punto 5.6.
