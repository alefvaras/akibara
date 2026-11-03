# Crítica de lector novato — capítulo 04 · disponibilidad, preventa y avisos

> Quién escribe. Alguien que llegó hoy al proyecto: no sabe WordPress, no sabe WooCommerce, no
> conoce el negocio del manga en Chile. Leyó el capítulo entero y sus ocho diagramas, y después
> abrió el código del snapshot (`scratchpad/snapshot-prod/plugins/…`) cada vez que algo no cerraba.
>
> Lo bueno, dicho una vez para no repetirlo: el capítulo es muy preciso. De los ~40 números de línea
> que verifiqué contra el snapshot, **todos** apuntan al sitio correcto; los valores por defecto de
> las once options coinciden con `OrderPreventa/Settings.php:68-80`; los ocho diagramas **validan con
> `mmdc` sin un solo error**; y 17 de las 18 sospechas confirmadas de `verificacion.json` están
> incorporadas. Lo que sigue es lo que igual me dejó fuera.

---

## 1 · Lo que NO entendí

**1.1 — «snapshot» significa dos cosas distintas y el glosario sólo define una.**
Línea 8: «El **snapshot** congelado de producción: `akibara-business` 1.116.4…». Línea 45: «Cada línea
del pedido lleva su foto congelada». El glosario (final) define: «**Snapshot.** La foto congelada de
lo que la ficha prometía en el instante de la compra». Leí las primeras 40 líneas creyendo que la
«foto congelada» de la línea 45 era el mismo congelado de código de la línea 8.
*Debería decir:* en la línea 8, «el **corte congelado del código** de producción», y reservar la
palabra «snapshot» para la foto de la línea de pedido; o poner un aviso explícito en el glosario:
«en este capítulo “snapshot” es siempre la foto de la compra; el corte de código se llama corte».

**1.2 — `t7` nunca se expande, y el número 7 no es el número que manda.**
La option se llama `akb_preventa_t7_enabled`, el hook `akb_order_preventa_t7_reminder`, el modo del
correo es «`t7`», y el capítulo habla siempre de «aviso previo». Nunca dice que «t7» es «T menos 7
días». Peor: la ventana real por defecto es **1 a 10 días** (`akb_preventa_t7_window_min_days` = 1,
`_max_days` = 10), y el mínimo de espera es 14. Un novato que abra la base y vea `t7` va a buscar un
7 que no existe en ninguna parte.
*Debería decir:* «`t7` es el nombre histórico del aviso previo —“T menos 7 días”— pero **el 7 ya no
es el umbral**: hoy la ventana la fijan `…window_min_days` y `…window_max_days` (1 y 10 por defecto)».

**1.3 — Los paréntesis significan tres cosas distintas y nadie lo dice.**
- «`woocommerce_email_classes` (10)» → prioridad de hook.
- «`akb_bis_batch_size` (20)» → valor por defecto del filtro.
- «la espera real es la constante `DELAY_SECONDS` (`30`)» (línea 745) → **número de línea**.
Esa última me hizo leer «la espera real son 30 segundos» y creer que el mensaje de «2 horas» mentía
por un factor de 240. Es al revés: `CarrierHandoffMonitor.php:30` dice
`private const DELAY_SECONDS = 2 * HOUR_IN_SECONDS;` — el valor coincide, la deuda es que el texto no
se deriva de la constante.
*Debería decir:* fijar una convención al principio («entre paréntesis y en negrita, la prioridad;
entre paréntesis y en código, la línea del fichero; los valores por defecto van con la palabra “por
defecto”») y, en 745, «el mensaje repite “2 horas” en texto (`114`) en vez de derivarlo de
`DELAY_SECONDS`, que hoy vale exactamente eso (`30`)».

**1.4 — «prio 25», «prio 31», «prio 0», «prio 20»: nunca se explica qué es una prioridad.**
El glosario define «Acción (hook)» y «Filtro (hook)» pero no la prioridad. El capítulo la usa como
argumento de diseño («prio 25 para el cartel —antes del botón—; prio 31 —después del botón—»), que es
justo donde un novato necesita saber que el número **ordena** a los que escuchan el mismo aviso y que
más bajo es más temprano.
*Debería decir:* una entrada de glosario: «**Prioridad.** Cuando varios trozos de código escuchan el
mismo aviso, el número decide el orden: 5 corre antes que 10, y 10 antes que 31. Por eso “prio 25” y
“prio 31” colocan el cartel arriba del botón y las promesas abajo».

**1.5 — `pa_pais` y `product_brand` aparecen 30 veces sin decir qué son ni dónde viven.**
«termmeta `_akb_min_days` / `_akb_max_days` del término de país», «primero por la **editorial**
(`product_brand`); si no tiene política propia, por el **país** (`pa_pais`)». El glosario define
«Termmeta» pero no explica el prefijo `pa_` (atributo de producto de WooCommerce) ni conecta
`product_brand` con la pantalla *Productos → Marcas* que el capítulo sí nombra por separado.
*Debería decir:* «`pa_pais` es el atributo de producto “País” (WooCommerce antepone `pa_` a todos los
atributos) y se edita en *Productos → Atributos → País → Configurar términos*. `product_brand` es la
taxonomía “Marcas” de WooCommerce, en *Productos → Marcas*: aquí una “marca” es siempre una
editorial».

**1.6 — Cinco palabras para cinco cosas de la pantalla, ninguna definida.**
«el **cartel** de fecha», «la **píldora** “Llegada: 1 – 4 sep”», «el **badge** de la tarjeta», «el
**chip** de faceta», «el **cartel global**» frente a «la **línea** por tomo». Un novato no sabe cuál
de esas cinco cosas está mirando cuando abre la tienda, y la trampa más grave del capítulo (1.6 del
guard `is_admin()`) depende de distinguir «cartel» de «línea».
*Debería decir:* una mini-tabla de cuatro filas al principio de «Piezas» —nombre, dónde sale, qué
pieza lo pinta— o, como mínimo, el nombre de la clase CSS (`.akb-eta-badge` para la línea) para
poder buscarla en el HTML.

**1.7 — `GET_LOCK` se usa como si fuera conocido.**
«toma un bloqueo de base de datos (`GET_LOCK`) por pedido». El glosario define «Reclamo atómico»,
que es el concepto hermano, pero no esto.
*Debería decir:* «`GET_LOCK` es un cerrojo con nombre que ofrece MySQL: el primero que lo pide lo
tiene y el resto espera; se suelta solo si el proceso muere. Es distinto del reclamo atómico, que no
espera a nadie: el que pierde simplemente no hace el trabajo».

**1.8 — Diez nombres de clase de otros capítulos, sin una sola referencia cruzada.**
Línea 74 en adelante: `LoopEnhancer`, `PdpEnhancer`, `SeriesBundles`, `AccountCollection`,
`TaxonomyDiscount`, `AgentReadiness`; más adelante `SaleDiscount`, `SeriesFollow`, «*Akibara →
Textos*» (que es `Modules\CopyManager`), `Modules\Forms\Honeypot`. El capítulo **no tiene ni una
referencia a otro capítulo** (lo verifiqué: 0 ocurrencias de «ver el capítulo»). Para un novato son
diez cajas negras.
*Debería decir:* al menos «(capítulo 03)», «(capítulo 06)» junto a cada nombre la primera vez, y una
frase de qué hace: «`LoopEnhancer` es quien pinta las tarjetas del catálogo (capítulo 03)».

**1.9 — `EstimatedDelivery\EstimatedDelivery`: una clase que se llama igual que su módulo.**
«**Qué hace.** La raíz del módulo». Tardé en entender que `EstimatedDelivery` a secas unas veces es
la carpeta, otras el módulo y otras la clase. Lo mismo pasa con `OrderPreventa\OrderPreventa` y
`BackInStock\BackInStock`.
*Debería decir:* una frase al abrir «Piezas»: «En los tres módulos grandes hay una clase que se llama
igual que el módulo: es la “raíz”, la que arma las demás y no hace nada por sí sola».

**1.10 — «el barrido… para avisar a las dos superficies que sí guardan una copia horneada»: falta el
eslabón.**
Se dice que una escritura vacía «dispara `save_post_product`, y con él el reindexado del buscador y
la purga de LiteSpeed». Nunca se dice **quién** escucha `save_post_product` para reindexar. Para un
novato el reindexado ocurre por magia, y es exactamente el punto donde el capítulo 08 dice que
guardar cosas dispara rebuilds caros.
*Debería decir:* nombrar al oyente («lo escucha `akibara-search`, capítulo 08») y aclarar si esta
escritura vacía cae en el camino barato (rebuild incremental) o en el caro.

**1.11 — La pestaña «Disponibilidad» tiene cuatro opciones, no tres, y el capítulo se contradice
consigo mismo.**
Línea 291: «ahí el dueño elige si un tomo reservable es «a pedido», «preventa con fecha» o «preventa
sin fecha»» (tres). Regla 7 de la tabla: «Ficha → pestaña Disponibilidad → **cuarto radio**». Los
cuatro existen y sus rótulos reales están en `ProductDataPanel.php:53-56`:
`A pedido (automático)…` · `Preventa — todavía no sale (con fecha de lanzamiento)` ·
`Preventa — todavía no sale (aún sin fecha confirmada)` · `Sin promesa de fecha (no mostrar plazos en
la ficha)`.
*Debería decir:* los cuatro rótulos literales, que es lo que el dueño ve en pantalla, y con qué valor
de `_akb_eta_type` se corresponde cada uno.

---

## 2 · Lo que FALTA para operar o depurar esto sin ayuda

**2.1 — Ninguna pantalla tiene URL, y el menú se llama distinto de como creo.**
El capítulo dice «*Akibara → Pedidos y preventa · Ajustes → Fechas estimadas*», «*Akibara → Lista de
espera*», «la pantalla «Por llegar»». Nunca dice que la página se llama `akibara-panel` ni que las
sub-pestañas viajan en la URL. Los datos reales están en `Plugin.php:1318-1349` y
`OrderPreventa/Settings.php:41, 87-93`:
- Ajustes: `/wp-admin/admin.php?page=akibara-panel&tab=preventa` (sub-pestañas `cortes` —la que abre
  por defecto—, `fechas`, `recordatorio`, `cambio-fecha`, `stale`).
- Por llegar: `…&tab=preventas` (ojo: `preventa` y `preventas` son **dos pantallas distintas**).
- Lista de espera: `…&tab=back_in_stock`.
*Debería decir:* una tabla «Pantallas del área» con etiqueta visible, URL exacta y capacidad
requerida (`manage_woocommerce` para «Por llegar», `manage_categories` para los cortes).

**2.2 — No dice dónde están los registros. Es lo primero que se mira cuando un correo no sale.**
El área escribe trazas con `Utilities\Log` (`Log.php:36-46`), que es un envoltorio de
`wc_get_logger()`. Se ven en ***WooCommerce → Estado → Registros***, y usa **tres** fuentes:
`akibara-preventa` (`ReadyEmailDispatcher.php:388`, `ReadyToShipEmail.php:250, 374`,
`StockChangeListener.php:202, 223`, `OrderQueryPaginator.php:83`), `akibara-bis`
(`NotificationDispatcher.php:392`, `Install.php:82`, `SubscriptionNotice.php:96`) y `akibara-eda`
(`DateCalculator.php:460`). La palabra «registro», «log» o «`wc_get_logger`» no aparece ni una vez en
el capítulo.
*Debería decir:* una sección corta «Dónde mirar cuando algo falla» con esas tres fuentes y qué
escribe cada una.

**2.3 — No dice dónde se ve la cola ni cómo se llaman sus grupos.**
Seis de los diez trabajos corren en Action Scheduler. El capítulo explica qué es en el glosario pero
no dónde se mira (*WooCommerce → Estado → Acciones programadas*) ni con qué grupo filtrar:
`akibara-order-preventa` y `akibara-bis` (`CarrierHandoffMonitor.php:29`,
`ReadyEmailDispatcher.php:51`, `NotificationDispatcher.php:44`).
*Debería decir:* grupo por trabajo en la tabla de «Lo programado», y la ruta de la pantalla.

**2.4 — Dice que tres de las cuatro horas son «heredadas» y «cambian tras una reinstalación», pero
no cómo verlas.**
Si son heredadas y pueden haber cambiado, el lector necesita el comando que las lee
(`wp cron event list`) y qué hacer si el evento desapareció —el capítulo dice que las clases se
«auto-reparan» en `init`, pero no cómo confirmarlo.
*Debería decir:* «para ver la hora real hoy: `wp cron event list --fields=hook,next_run_gmt` filtrando
por `akb_`; si un evento no aparece, basta cargar cualquier página del sitio: `maybe_schedule()` corre
en `init`».

**2.5 — «en hora de Chile» aparece siete veces y nunca dice de dónde sale esa hora.**
La fuente única es `Utilities\StoreTime` (13 usos en el área), que devuelve `wp_timezone()` y **cae a
UTC** si WordPress no está cargado. Su propio docblock explica por qué eso importa
(`StoreTime.php:12-15`): «el servidor corre en UTC y la tienda en America/Santiago, así que después
de las 20:00 CL la fecha comparada sería la del día siguiente y una preventa caducaría cuatro horas
antes de tiempo». `StoreTime` no aparece en el capítulo ni en la lista de ficheros leídos.
*Debería decir:* una pieza corta para `Utilities\StoreTime`, y que la zona real se cambia en
*Ajustes → Generales → Zona horaria* de WordPress, no en ninguna pantalla de Akibara.

**2.6 — El tope de «12 altas por hora y por IP» tiene una exención que lo vuelve invisible en QA.**
`RateLimit::exceeded()` (`Utilities/RateLimit.php:76-78`) empieza con
`if ( current_user_can( 'manage_woocommerce' ) ) { return false; }`: el dueño logueado **nunca** lo
toca. Además la ventana es de bucket fijo (la hora va dentro de la clave, `83-85`), lo que permite
hasta 24 altas repartidas en dos horas consecutivas —el propio fichero lo llama «burst de frontera»—.
Y el contador vive en un transient con nombre hasheado (`akb_rl_<sha256>`), así que **no se puede
borrar por nombre** para desbloquear a alguien.
*Debería decir:* los tres hechos, y que probar el tope exige una ventana de incógnito sin sesión.

**2.7 — Los transients `akb_rl_*` faltan en «Datos que deja».**
La tabla de transients lista seis y ninguno es el del antispam, que es el único que genera el
**cliente** (uno por IP y hora, en cada alta de la lista de espera).

**2.8 — Falta el contrato que el carrito consume de verdad.**
El capítulo documenta `Contracts\EtaProvider` y llama a `CheckoutDataService` «el contrato estable
que `akibara-cart-checkout-blocks` consume». El contrato tiene nombre y fichero:
`CheckoutDataService implements CheckoutDataProvider` (`Services/CheckoutDataService.php:31`,
`src/Contracts/CheckoutDataProvider.php`). El snapshot tiene **dos** ficheros en `src/Contracts/` y el
capítulo sólo nombra uno.

**2.9 — El «campo señuelo» no tiene dueño.**
«Incluye el campo señuelo antispam» (línea 910). Sale de `Modules\Forms\Honeypot`, compartido con el
formulario de contacto. Para depurar un alta rechazada hace falta saber **cómo se llama el campo** y
quién decide que viene relleno.

**2.10 — No dice si la lista de espera está encendida en producción.**
Trece piezas, una tabla nueva en la base, un endpoint público y dos correos… y los dos interruptores
que los gobiernan vienen **apagados por defecto** (`BackInStock.php:10-12`, y la tabla del capítulo lo
confirma: `no`). El capítulo declara que las horas de los crons se midieron en producción el
2026-09-11, pero no dice el estado de estos dos interruptores. Sin ese dato no sé si estoy leyendo un
módulo vivo o uno dormido.

**2.11 — Nada sobre cómo reproducir esto en local.**
El capítulo dice que hoy el catálogo no tiene pre-lanzamientos configurados. Entonces la mitad del
capítulo —todo lo de «preventa con fecha»— no se puede probar contra datos reales. El repositorio
tiene exactamente la herramienta para eso (`tools/qa-seed-preventa.php`, que monta disponible/a
pedido/preventa con fecha y pedidos con los **hooks reales** del checkout) y el capítulo no la
menciona.

**2.12 — Falta el reintento de +60 s del despachador de la lista de espera.**
El capítulo y la tabla de crons dicen «con el envío pausado, 4 reintentos cada 900 s». Hay un segundo
camino con otro reloj: cuando `WC_Email` todavía no es cargable se reagenda a
`MAILER_RETRY_SECONDS = 60` (`NotificationDispatcher.php:57, 318-323`), gastando el **mismo**
presupuesto de 4. Quien vea una acción reagendada a +60 s no la va a encontrar en el capítulo.

**2.13 — El atajo de reposición no cubre variaciones; la lista de espera sí.**
El capítulo presenta los dos oyentes como gemelos: «la lista de espera escucha el **mismo** hook en
prio 11, por contrato explícito». No lo son. `BackInStock\StockWatcher` escucha **dos** hooks
—`woocommerce_product_set_stock_status` y `woocommerce_variation_set_stock_status`
(`StockWatcher.php:62-63`)—; `OrderPreventa\StockChangeListener` sólo el primero
(`StockChangeListener.php:72`). Consecuencia operativa: reponer una **variación** manda los avisos de
lista de espera pero **no** dispara el recálculo de +5 s; esos pedidos esperan al cron diario de las
~19:30.
*Debería decir:* eso mismo, más si hoy existen productos variables en el catálogo (si no existen, hay
que decirlo: es lo que convierte el hueco en inofensivo).

---

## 3 · Lo que CONTRADICE al código

**3.1 — La conexión con `akibara-rewards` no es por filtros: es una llamada estática dura.**
El capítulo (línea 84): «Con `akibara-rewards` y cualquier integración de reseñas, por los dos
filtros cooperativos de `CompletionPolicy` (`akb_order_preventa_can_complete_order` y
`akb_order_preventa_can_request_review`)». Un grep sobre **todo** el snapshot devuelve exactamente
dos ocurrencias de cada filtro: las dos líneas que los declaran
(`CompletionPolicy.php:161` y `:174`). Nadie los engancha. El acoplamiento real es una llamada
estática desde el otro plugin:
`akibara-rewards/src/Reviews/Invitations.php:131-132` y `:175-176` →
`! \Akibara\Business\Modules\OrderPreventa\CompletionPolicy::can_request_review( $order )`,
tras un `class_exists()`.
Esto importa: el capítulo aplica el criterio correcto al filtro hermano (`akb_availability_label`:
«**Hoy no lo engancha nadie**»), y aquí hace lo contrario. Además, renombrar o mover ese método rompe
`akibara-rewards` sin que ningún filtro avise.
*Debería decir:* «los dos filtros existen como punto de extensión y **hoy no los engancha nadie**. El
enganche real de `akibara-rewards` es una llamada directa a `CompletionPolicy::can_request_review()`
(`Invitations.php:132, 176`), protegida por `class_exists()`: es una dependencia dura de
akibara-rewards sobre esta clase».

**3.2 — «típicamente en menos de un minuto» contradice la propia tabla de crons del capítulo.**
Línea 1244: «**Correos.** «Tu pedido {n} ya llegó a Akibara», con la cola, típicamente en menos de un
minuto tras el cambio de stock». Dos páginas antes el mismo capítulo dice: «El empuje lo da una tarea
del panel de Hostinger que llama a `wp-cron.php` **cada 5 minutos**: `DISABLE_WP_CRON` está encendido,
así que el tráfico de clientes no empuja nada». Y la cadena es: +5 s para el recálculo → acción del
correo → siguiente drenaje de la cola. Con un empuje cada 5 minutos, «menos de un minuto» sólo puede
pasar por casualidad.
*Debería decir:* «entre 1 y ~10 minutos: el trabajo se encola de inmediato, pero la cola sólo avanza
cuando la tarea de Hostinger empuja `wp-cron.php`, cada 5 minutos».

**3.3 — «los diecinueve colaboradores» son veinte.**
Línea 371. `OrderPreventa::register()` hace exactamente **20** llamadas `->register()`
(`OrderPreventa.php:124-142` y `145`), y el constructor arma 21 objetos contando `SummaryRecalculator`. Un
lector que use el número para comprobar que no se olvidó ninguna pieza se queda con una de menos.

**3.4 — La lista de quién consulta `Stock\Availability` está incompleta, y faltan superficies que
llegan a Google.**
Línea 73-76 nombra seis consumidores (`LoopEnhancer`, `PdpEnhancer`, `SeriesBundles`,
`AccountCollection`, `TaxonomyDiscount`, `AgentReadiness`). En el snapshot hay **22 ficheros** que
usan `Stock\Availability`. Los que faltan y pesan:
- `Modules/SchemaProductRankMath/SchemaProductRankMath.php:443, 449` — los **datos estructurados** que
  ve Google salen de `Availability::is_preventa()` + `is_valid_release_date()`.
- `Modules/ProductFaq/ProductFaq.php:108` — el bloque de preguntas de la ficha.
- `Modules/SeriesFollow/DigestRunner.php:596` — el correo de seguimiento de serie lleva
  `Availability::label_of()`.
- `Modules/SeriesBundles/{LandingOffer,BypCartNoticeProvider,CartSeriesCollector}.php` y
  `BackInStock/CaptureRestController.php`.

**3.5 — Hay un tercer vocabulario a fuego, y el capítulo afirma que sólo hay dos.**
El capítulo: «cambiar «A pedido» en *Akibara → Textos* cambia el cartel, el carrito, la línea del
pedido y los correos, pero **no** el badge de la tarjeta ni la ficha técnica, que leen esta
constante». Falta el tercero: `ProductFaq.php:108` escribe los literales
`'disponible en preventa'` / `'disponible a pedido'`, que no siguen ni a la option ni a la constante,
y terminan dentro del texto de la FAQ de la ficha (contenido indexable).
*Debería decir:* «tres vocabularios: la option de *Textos*, la constante de `Availability` y los
literales de `ProductFaq`».

**3.6 — La regla 58 enuncia el tope antispam como si fuera universal.**
«las altas se limitan a 12 por hora y por IP». Le falta «salvo para usuarios con `manage_woocommerce`,
que están exentos» (`RateLimit.php:76-78`) y «la ventana es de bucket fijo, no deslizante: se pueden
colar hasta 24 entre dos horas consecutivas» (`RateLimit.php:66-72, 83-85`).

**3.7 — «registra los diez sub-componentes» (BackInStock) no coincide con ninguna cuenta.**
`BackInStock::register()` hace **8** llamadas `->register()` (`BackInStock.php:70-80`), más
`Install::maybe_install()` y `InstockEmail` por filtro; y la carpeta tiene 12 ficheros además de la
raíz (`Subscriber` y `SubscriberRepository` no se «registran»). Sea cual sea la cuenta que se elija,
hay que decir cuál es.

---

## 4 · Lo que contradice a `verificacion.json` del área

Referencia: `scratchpad/mapas/biz-disponibilidad-preventa/verificacion.json` (cobertura estimada 88).

**4.1 — Se cayó una de las 18 sospechas confirmadas.**
`verificacion.json` → `sospechas_confirmadas_por_lectura` #21: «El docblock de
`products_with_waiting()` está colocado sobre otro método». Lo comprobé y es cierto: el bloque que
explica por qué esa consulta no lleva tope termina en `SubscriberRepository.php:364` y lo sigue de
inmediato **otro** docblock (365-377) que describe `count_other_waiting()`; `products_with_waiting()`
queda desnuda en la línea 418. El mismo patrón se repite en `InstockEmail.php:38-39`. Las otras 17
sospechas confirmadas sí están en el capítulo; ésta no aparece por ningún lado.
*Debería decir:* una «deuda menor» en la pieza `BackInStock\SubscriberRepository`, junto a la de los
«3 altas/hora» caducados, que sí se recogió.

**4.2 — El capítulo declara haber leído menos ficheros de los que `verificacion.json` dice que se
leyeron, y son justo los que faltan.**
Línea 14, «Hasta dónde llega «completo»», enumera: los 4 de `Stock`, 12 de `EstimatedDelivery`, 33 de
`OrderPreventa`, 13 de `BackInStock`, las dos rutas, el adaptador de Blue, `EtaProvider`, las seis
plantillas y tres servicios (`AkibaraEmail`, `MailerBoot`, `OneClickPage`). Las `notas` de
`verificacion.json` añaden: «…más `RateLimit`, `Text`, `StoreTime`, `StockLabels` (akibara-search) y
el cableado de `Plugin`». Esos cuatro ficheros son exactamente el origen de los huecos 2.5, 2.6 y 2.9
de esta crítica. Declarar una cobertura menor a la real hace que el hueco parezca deliberado.

**4.3 — Discrepancia de líneas en `render_cumplimiento()` entre capítulo, verificación y código.**
El capítulo cita «`render_cumplimiento()` en `263-302`»; `verificacion.json` → `errores` #4 lo sitúa
en «313-345»; el código dice que la función empieza en `PreventasScreen.php:253` y termina en 345,
con el `'status' => 'any'` en 267 y el bloque «¿Estás cumpliendo los plazos?» en 311-344. Ninguna de
las dos citas cubre la función entera.
*Debería decir:* `PreventasScreen.php:253-345`, con `'status' => 'any'` en `267`.

---

## 5 · Los diagramas

**Validación:** los ocho `.mmd` del capítulo compilan con `mmdc` sin advertencias (cuatro `sequenceDiagram`,
un `flowchart`, dos `stateDiagram-v2` y un `timeline`). Los ocho están
referenciados desde el texto, en el orden correcto. Eso está bien y no hay nada que arreglar ahí.

**5.1 — `…-estados-disponibilidad.mmd` dice lo contrario que el código en el caso más importante.**
El diagrama tiene esta arista: `reservable --> APedido: tipo = auto, **tba**, disabled, ausente o
desconocido`. Es falso: `Availability::pending_launch()` devuelve `true` para `tba` antes de mirar
cualquier fecha —`if ( self::TYPE_TBA === $type ) { return true; }`
(`Stock/Availability.php:258-260`)—, o sea **`tba` es Preventa**. El propio capítulo lo dice en el
caso 7 («vaciar la fecha deja el tomo como preventa **sin** fecha confirmada (tipo `tba`)»), así que
el diagrama contradice a la vez al código y al texto. Y es el diagrama que un novato usa para
entender el área entera.
*Debería decir:* dos aristas: `reservable --> Preventa: tipo = release_date con fecha hoy o futura, **o
tipo = tba**` y `reservable --> APedido: tipo = auto, disabled, ausente o desconocido, o release_date
con fecha ya pasada`.

**5.2 — `…-cron.mmd` cuenta cinco trabajos por hecho y dibuja seis.**
Cabecera del diagrama: «los cuatro trabajos con reloj y los **cinco** que dispara un hecho». El
capítulo dice «Cuatro trabajos con reloj y **seis** que dispara un hecho», y el propio diagrama lista
seis en la sección «Sin reloj» (incluido `akb_order_preventa_verify_bluex_handoff`).

**5.3 — `…-cron.mmd` se contradice a sí mismo sobre cada cuánto avanza la cola.**
Sección «El empuje»: «Cada 5 minutos : una tarea del panel de Hostinger llama a wp-cron.php;
DISABLE_WP_CRON está encendido, así que el tráfico de clientes NO empuja nada» y, justo debajo, «Cada
minuto : action_scheduler_run_queue drena la cola de WooCommerce». Si `DISABLE_WP_CRON` está encendido
y el único empuje llega cada 5 minutos, `action_scheduler_run_queue` **no puede** correr cada minuto:
está agendado cada minuto, que no es lo mismo que ejecutarse cada minuto.
*Debería decir:* «Agendado cada minuto, pero sólo avanza cuando llega el empuje de Hostinger: en la
práctica, cada 5 minutos». Es la misma corrección que 3.2, y las dos juntas cambian la promesa de
latencia del correo «ya llegó».

**5.4 — `…-piezas.mmd` mete un plugin propio dentro del subgrafo «Terceros que participan».**
El nodo `SEARCH["akibara-search…"]` está clasificado `:::propio` (naranjo) y colgado del subgrafo
`TERCEROS["Terceros que participan"]`. La leyenda del propio diagrama dice «Naranjo = código propio;
gris = tercero». Un lector que mire sólo el diagrama concluye que el buscador es de terceros, cuando
el capítulo entero se apoya en que es uno de los cuatro plugins propios.
*Debería decir:* sacar `SEARCH` a un subgrafo aparte («Los otros plugins propios») o renombrar el
subgrafo a «Con quién habla fuera de este módulo».

**5.5 — `…-piezas.mmd` dibuja una dependencia que no existe: `CHKSVC --> SNAP`.**
`Services\CheckoutDataService` no construye ni llama a `LineItemSnapshot`; éste recibe un
`EtaProvider` en su constructor (`LineItemSnapshot.php:37`) y lo instancia
`OrderPreventa::__construct()` (`OrderPreventa.php:77`). En sentido inverso tampoco:
`CheckoutDataService` no nombra a `LineItemSnapshot` en ninguna línea. La arista hermana
`BANNER --> CHKSVC` sí es real (`CheckoutDataService.php:35, 56`).

**5.6 — `…-piezas.mmd` deja fuera la pieza de la que cuelga el informe de cumplimiento.**
El texto dice que `ShippableStamp` «sella la fecha real de despacho, **que es la que usa el informe de
cumplimiento**», y que `PromiseAudit` compara prometido contra real. En el diagrama, el nodo `AUDIT`
(«PromiseWindow + PromiseAudit») no recibe ninguna arista: el dato real llega de la nada. Falta el
nodo `ShippableStamp` (y su meta `_akb_eta_shippable_at`) con la arista hacia `AUDIT`. En la misma
línea faltan `ShipPromise` (la ventana «1-3 días hábiles» que citan seis superficies, y que sí tiene
pantalla) y el adaptador de Blue Express, que en el diagrama aparece como `BLUEX --> CAPS` sin nombrar
al adaptador que hace la traducción.

**5.7 — `…-estados-pedido.mmd`: se llega a «Despachable» por dos caminos con significados opuestos.**
`Listo --> Despachable: sale el correo «tu pedido ya llegó»` y `ListoSinAviso --> Despachable` (sin
etiqueta). El nodo se describe como «el cliente ve el cartel verde», pero por el segundo camino el
cliente no recibió ningún correo. Leído solo, el diagrama sugiere que el cartel verde y el correo van
siempre juntos.
*Debería decir:* etiquetar la segunda arista «sin correo: al cliente le reembolsaron todo lo que
esperaba».

**5.8 — Los tres diagramas de caso nombran clases con `\` y el de piezas también; ninguno explica la
notación.**
`OrderPreventa\ReleaseDateChangeListener`, `EstimatedDelivery\DateCalculator`… El capítulo usa la
misma notación en el texto sin decir nunca que la parte antes de la barra es el módulo (la carpeta) y
la de después la clase (el fichero `.php`). Es una línea de leyenda en el diagrama de piezas y ahorra
el desconcierto de buscar un fichero llamado `OrderPreventa\ReminderEmail`.

---

## Respuesta del redactor

Comprobé los 38 puntos contra el corte de código. **Los 38 son correctos y los 38 están aplicados**
en la reescritura del capítulo, en sus diagramas y en el `.resumen.json`. No hay ninguno que
devuelva. Dos llevan matiz, y un tercero no se puede cerrar leyendo:

**Matiz al 3.4 — son 24 ficheros, no 22.** El grep de `Modules\Availability` sobre `src/` del corte
devuelve **24** ficheros. Once están dentro de este capítulo (`DateCalculator`, `BannerRenderer`,
`ProductDataPanel`, `LineItemSnapshot`, `PreventaItems`, `PreventaUX`, `PreventasScreen`,
`SummaryRecalculator`, `CaptureRestController`, `CheckoutDataService` y la propia `Availability` vía
sus consumidores internos); los trece de fuera son los que ahora van en tabla, con su capítulo al
lado. A la lista de la crítica le faltaban `SeoEnhancements\SeoEnhancements`,
`NativeCart\NativeCart` y `SeriesBundles\BuilderWidget`. El fondo del punto —la lista del capítulo
estaba incompleta y se dejaba fuera justo la superficie que llega a Google— es exacto, y así quedó
escrito.

**Matiz al 1.3 — la convención se fijó, pero en la dirección contraria a la propuesta.** En vez de
«entre paréntesis y en negrita, la prioridad», lo que ahora manda es que **todo valor por defecto
lleve escrita la palabra “por defecto”**. Es la regla que menos casos deja sueltos: los paréntesis
del capítulo son o prioridad de hook (van siempre pegados a un nombre de hook) o número de línea (van
siempre pegados a un fichero o a un método), y el único caso ambiguo era el tercero. De paso se
barrieron los cuatro sitios donde un valor por defecto aparecía desnudo entre paréntesis:
`akb_bis_batch_size`, `akb_bis_throttle_gap_seconds`, `akb_bis_pause_retry_seconds` y
`akb_bis_retention_days`.

**El 2.10 no se puede cerrar por lectura, y eso ahora se dice.** El corte prueba que los dos
interruptores **nacen apagados** (`BackInStock.php:10-14`), y nada más: qué valor tienen hoy en
producción no se midió en la sesión que produjo este capítulo, y deducirlo del código sería
inventarlo. El capítulo ahora lo declara como hueco explícito, con el comando que lo responde
(`wp option get akb_bis_capture_enabled`), y avisa de que todo el módulo `BackInStock` hay que leerlo
como «esto es lo que pasa **si** están encendidos». Lo mismo con el 2.13: si el hueco de las
variaciones muerde hoy depende de si existen productos variables, que tampoco se midió; queda dicho,
con el comando al lado.

Lo demás entró tal cual se pidió: el corte de código deja de llamarse «snapshot», `t7` se expande y
se desmiente el 7, hay tabla de pantallas con URL exacta y la trampa `preventa`/`preventas`, tabla de
las seis piezas visuales con su clase CSS, sección «dónde mirar cuando un correo no sale» con las
tres fuentes de registro y los dos grupos de la cola, entradas de glosario para prioridad, `GET_LOCK`,
`pa_pais` y `product_brand`, referencias cruzadas a los capítulos 03, 05, 06, 07, 08, 09, 11 y 12, y
`Utilities\StoreTime` como pieza propia. La conexión con `akibara-rewards` está reescrita como lo que
es —una llamada estática dura, con los filtros declarados y sin enganchar—, la latencia del correo
«ya llegó» pasa de «menos de un minuto» a «1 a ~10 minutos» en las tres superficies donde se afirmaba
(texto, caso de uso y diagrama), los conteos van a 20 y a 8, `render_cumplimiento()` a `253-345`, y
en el diagrama de estados `tba` ya es Preventa. Los ocho `.mmd` vuelven a validar con `mmdc` sin
advertencias tras los cambios.
