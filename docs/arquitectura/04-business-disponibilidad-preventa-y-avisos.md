# akibara-business · disponible, a pedido y preventa; entrega estimada; «avísame cuando llegue»

> **Qué es este capítulo.** El trozo del plugin propio `akibara-business` que responde tres preguntas
> del negocio: *¿qué se puede comprar hoy y en qué condiciones?*, *¿cuándo le llega al cliente?* y
> *¿qué le contamos mientras espera?*. Está escrito para alguien que no conoce WordPress, ni
> WooCommerce, ni el negocio del manga en Chile.
>
> **Sobre qué código habla.** El **corte congelado del código de producción** —una copia exacta de
> los ficheros que hoy corren en el servidor, guardada aparte para poder leerla sin que cambie bajo
> los pies—: `akibara-business` 1.116.4, `akibara-search` 0.1.60, `akibara-cart-checkout-blocks`
> 2.2.17, `akibara-rewards` 0.3.13 y el tema hijo `akibara-gp` 2.1.118. Todas las rutas de fichero y
> todos los números de línea que aparecen aquí corresponden a ese corte, no al árbol de trabajo
> local. Las horas de los trabajos programados vienen de la lista real de tareas capturada en
> producción el 2026-09-11.
>
> **Aviso de vocabulario.** A ese código congelado lo llamamos «el corte», y nunca «snapshot». En el
> resto del capítulo la palabra **snapshot** significa siempre otra cosa: la *foto de la compra* que
> se guarda dentro de cada línea del pedido. Son dos congelados distintos y no tienen nada que ver.
>
> **Cómo leer las notaciones.** Cuatro convenciones, fijas en todo el capítulo:
>
> - **Un número entre paréntesis después de un nombre de hook es la prioridad**: `save_post_product`
>   (20). Cuando varios trozos de código escuchan el mismo aviso, ese número decide el orden —más
>   bajo, más temprano—; por eso hay «prio 25» y «prio 31» más abajo.
> - **Un número entre paréntesis y en código, después de un fichero o de un método, es la línea de
>   ese fichero**: `Availability.php:123-125`, o `(`171-194`)` cuando el fichero ya se nombró.
> - **Los valores por defecto llevan siempre la palabra «por defecto»** escrita al lado. Si no está
>   esa palabra, el número no es un valor: es una prioridad o una línea.
> - **Un nombre con contrabarra es «módulo\clase»**: en `OrderPreventa\ReminderEmail`, `OrderPreventa`
>   es la carpeta y `ReminderEmail` el fichero `ReminderEmail.php` dentro de ella. No existe ningún
>   fichero que se llame `OrderPreventa\ReminderEmail`.
>
> **Hasta dónde llega «completo».** Se leyeron enteros los 4 ficheros del módulo `Stock`, los 12 de
> `EstimatedDelivery`, los 33 de `OrderPreventa`, los 13 de `BackInStock`, las dos rutas públicas, el
> adaptador de Blue Express, los dos contratos de `src/Contracts/` (`EtaProvider` y
> `CheckoutDataProvider`), las seis plantillas de correo del área, los tres servicios compartidos de
> los que dependen (`AkibaraEmail`, `MailerBoot`, `OneClickPage`), las cuatro utilidades compartidas
> que el área usa sin ser suyas (`Utilities\RateLimit`, `Utilities\Text`, `Utilities\StoreTime` y
> `Modules\Forms\Honeypot`), el cableado del área en `Plugin.php` y `StockLabels` de
> `akibara-search`. Lo que **no** se pudo comprobar por lectura —el comportamiento medido en vivo:
> cuántos correos salen de verdad, cuánto tarda la pantalla «Por llegar» con el volumen real, y qué
> valor tienen hoy en producción los interruptores de la lista de espera— queda dicho donde toca.

---

## Para qué existe (en dos párrafos que entienda un novato)

Akibara vende manga en Chile, y casi nada de lo que vende está físicamente en una bodega cuando
alguien lo compra. La mayor parte del catálogo se **encarga** a una editorial de Argentina o de
España y viaja semanas hasta Santiago. Eso convierte una pregunta que en una tienda normal es
trivial —«¿está disponible?»— en una pregunta con tres respuestas distintas. La primera es
*Disponible*: hay ejemplares, se compra y se despacha. La segunda es *A pedido*: el tomo **ya
existe**, la editorial lo tiene impreso, lo pedimos en el próximo pedido y viaja; lo único que se
espera es el transporte. La tercera es *Preventa*: el tomo **todavía no sale**, la editorial lo
publica tal día; se espera el lanzamiento **y después** el transporte. Hay una cuarta,
*Agotado*, que es la única en la que no se puede comprar nada. WooCommerce —el añadido que le da
tienda a WordPress— sólo conoce tres estados de stock crudos (`instock`, `outofstock` y
`onbackorder`, que significa «no hay pero se puede comprar igual»), así que durante mucho tiempo
«a pedido» y «preventa» convivieron bajo la misma etiqueta y la tienda le decía lo mismo a dos
clientes que estaban esperando cosas muy distintas. La versión 1.109.0 partió ese vocabulario en dos,
y este capítulo describe el resultado.

**Contrato de cantidades:** A pedido y Preventa sin unidades físicas se guardan con
`manage_stock=false`, cantidad vacía y `stock_status=onbackorder`. WooCommerce guarda
`backorders=no` en ese caso; la compra continúa habilitada por el estado. Los avisos,
ETA y snapshots de Akibara no dependen de forzar `notify`. Una normalización de
catálogo no modifica productos ligados a pedidos pendientes ni reemplaza la
recepción oficial FIFO. Producción es la autoridad del inventario; los datos del
espejo no se exportan para corregir existencias.

Lo que el área hace, en orden. **Uno:** deduce el estado comercial de cada tomo a partir del stock
crudo más dos datos que el dueño escribe en la ficha del producto. **Dos:** calcula cuándo llegaría
ese tomo, aplicando una cadena de reglas que va de lo más específico a lo más general —el plazo
escrito a mano en esa ficha, la política de la editorial, la política del país, los días de tránsito
del país— y lo pinta en la ficha, en las tarjetas del catálogo, en el carrito y en el checkout.
**Tres:** en el momento de la compra congela en cada línea del pedido una *foto* de lo que la ficha
prometía, para que meses después la página del pedido y los correos digan lo que se prometió y no lo
que el catálogo diga hoy. **Cuatro:** mientras el cliente espera, le manda cuatro correos automáticos
y ninguno más —un aviso unos días antes de la fecha, un aviso si la editorial mueve la fecha, un
aviso si un tomo que no tenía fecha por fin la recibe, y el aviso de «tu pedido ya llegó a
Akibara»— y le impide al dueño dar por completado un pedido cuya mercadería no ha llegado. **Y
cinco:** para lo que está *agotado de verdad* —lo que ni siquiera se puede encargar— mantiene una
lista de espera propia, «Avísame cuando llegue», que manda un quinto correo cuando el tomo vuelve.

![Piezas del área y cómo se relacionan](diagramas/04-business-disponibilidad-preventa-y-avisos-piezas.mmd)

Dos ideas conviene fijarlas antes de seguir, porque explican la mitad del diseño:

1. **El estado comercial no se guarda nunca.** Se deduce en cada lectura. Por eso una preventa
   «caduca sola»: el día después de la fecha de lanzamiento, la misma ficha empieza a decir «A
   pedido» sin que nadie la toque. Y por eso existe un barrido diario: para avisar a las dos
   superficies que sí guardan una copia horneada del estado —el índice del buscador y el HTML
   cacheado de la página—.
2. **Lo que el cliente compró no cambia porque cambie el catálogo.** Cada línea del pedido lleva su
   foto congelada, y de ahí salen la palabra, el país y la fecha que se le enseñan meses después.

![Los cuatro estados comerciales y por qué una preventa caduca sola](diagramas/04-business-disponibilidad-preventa-y-avisos-estados-disponibilidad.mmd)

---

## Cómo encaja con el resto (con quién habla; qué pasa si se apaga)

**Hacia dentro del mismo plugin.** El área es el proveedor de disponibilidad y de fechas para medio
`akibara-business`. En el corte hay **24 ficheros** que llaman a `Stock\Availability`. Once están
dentro de este capítulo; los trece de fuera son el resto del plugin, y conviene conocerlos porque
dos de ellos llegan a Google:

| Quién consulta `Stock\Availability` | Qué es | Dónde se explica |
| --- | --- | --- |
| `LoopEnhancer\LoopEnhancer` | Quien pinta las tarjetas del catálogo (el badge de estado) | capítulo 06 |
| `PdpEnhancer\PdpEnhancer` | La ficha del producto (la ficha técnica) | capítulo 06 |
| `ProductFaq\ProductFaq` | El acordeón de preguntas de la ficha — **texto indexable** (`108`) | capítulo 06 |
| `TaxonomyDiscount\Settings` | El descuento por editorial o categoría | capítulo 06 |
| `SchemaProductRankMath` | Los **datos estructurados que ve Google**: `is_preventa()` decide entre `schema.org/PreOrder` y `schema.org/BackOrder`, y `is_valid_release_date()` decide si sale `availabilityStarts` (`443, 449`) | capítulo 07 |
| `SeoEnhancements\SeoEnhancements` | Otros retoques de SEO de la ficha | capítulo 07 |
| `AgentReadiness\MarkdownNegotiation` | La versión en texto plano de la ficha para agentes de IA | capítulo 07 |
| `SeriesFollow\DigestRunner` | El correo de seguimiento de serie, que imprime `Availability::label_of()` (`596`) | capítulo 03 |
| `SeriesBundles\{BuilderWidget, LandingOffer, BypCartNoticeProvider, CartSeriesCollector}` | El constructor de packs por serie y sus avisos en el carrito | capítulo 03 |
| `AccountCollection\CollectionResolver` | «Mi colección», en la cuenta del cliente | capítulo 05 |
| `NativeCart\NativeCart` | El carrito nativo de WooCommerce | capítulo 05 |

`Services\CheckoutDataService` es el contrato estable por el que el carrito y el checkout piden la
palabra del estado y la fecha agregada.

**Con los otros plugins propios.** Con `akibara-search`, por dos filtros:
`akb_search_availability_value` —el buscador por sí solo no sabe distinguir preventa de a pedido, y
`Stock\SearchAvailabilityBridge` se lo presta— y `akb_stock_status_label`, la palabra del estado
crudo; y, sin filtro de por medio, porque el indexador del buscador escucha `save_post_product`
(capítulo 08). Con `akibara-cart-checkout-blocks`, por `CheckoutDataService` (capítulo 12).

**Con `akibara-rewards`, y esto conviene leerlo despacio.** `CompletionPolicy` declara dos filtros
cooperativos, `akb_order_preventa_can_complete_order` y `akb_order_preventa_can_request_review`,
pensados como punto de extensión para cualquier integración de reseñas. **Hoy no los engancha
nadie:** el grep sobre todo el corte devuelve exactamente dos ocurrencias de cada uno, y son las dos
líneas que los declaran (`CompletionPolicy.php:161` y `:174`). El acoplamiento real con
`akibara-rewards` (capítulo 11) es una **llamada estática directa** desde el otro plugin:
`akibara-rewards/src/Reviews/Invitations.php:131-132` y `:175-176` llaman a
`\Akibara\Business\Modules\OrderPreventa\CompletionPolicy::can_request_review( $order )` tras un
`class_exists()`, para no invitar a reseñar algo que el cliente todavía no ha recibido. La
consecuencia práctica es una dependencia dura: renombrar o mover ese método rompe `akibara-rewards`
en silencio, sin que ningún filtro avise.

**Con el tema.** El tema `akibara-gp` **no** sobreescribe ninguna de las plantillas de correo de este
área: su carpeta `woocommerce/emails/` sólo tiene `header`, `footer`, `new-account`,
`refunded-order` y `reset-password`. Todo lo que el cliente lee sobre su espera sale del plugin.

**Con terceros.** WooCommerce aporta los pedidos, las clases de correo y la cola de trabajos (Action
Scheduler). Blue Express aporta —a través de un adaptador propio— qué estado significa «entregado al
transportista» y de dónde salen los números de seguimiento; también aporta, sin saberlo, la option
`woocommerce_correios_settings['noBlueStatus']`, que dos clases de este capítulo leen directamente.
Joinchat aporta el número de WhatsApp del pie de los correos de preventa, con un número de respaldo
escrito en el código. LiteSpeed Cache recibe purgas de página. Rank Math le da a `/a-pedido/` y a
`/disponibles/` su propio título, su canónica y sus datos estructurados. Google Listings & Ads recibe
la fecha de disponibilidad para el feed de Google Shopping.

**Qué pasa si se apaga.** Si se desactiva `akibara-business` entero: desaparecen los cuatro estados
comerciales (todo vuelve a ser «hay / no hay / se puede pedir»), desaparecen todas las fechas
estimadas de todas las superficies, los pedidos con espera dejan de recalcularse y de avisar,
`/a-pedido/` y `/disponibles/` devuelven 404, la lista de espera deja de capturar y de avisar —pero
su tabla y sus tokens siguen en la base—, y el buscador vuelve a facetar con su propio valor por
defecto (`a_pedido` para todo lo reservable). Dentro del área hay tres interruptores más finos:
apagar el aviso previo desprograma su cron semanal; apagar la captura de la lista de espera deja de
pintar el formulario sin borrar ninguna suscripción; y apagar el envío de la lista de espera pausa
los correos sin perder la cola, que se retoma al volver a **guardar** el ajuste.

---

## Dónde se opera, dónde se mira y cómo se prueba

Antes de las piezas, las cinco cosas que un novato necesita para no leer a ciegas: dónde están las
pantallas, cómo se llaman los trozos de la tienda que el capítulo nombra, dónde viven los datos de
países y editoriales, dónde quedan las trazas cuando algo falla, y cómo montar en local los casos
que producción no tiene.

### Las pantallas del área, con su URL exacta

Todas cuelgan del panel propio, cuyo identificador en la URL es **`akibara-panel`** —no `akibara`, que
da 403 y parece un problema de permisos—. El menú se llama «Akibara» en la barra lateral de
*wp-admin*, y todo el panel exige la capacidad `manage_woocommerce` (`AdminPanel.php:179-198`).

| Lo que se ve en el menú | URL | Qué se hace ahí |
| --- | --- | --- |
| *Akibara → Pedidos y preventa · Ajustes* | `/wp-admin/admin.php?page=akibara-panel&tab=preventa` | Los once umbrales del módulo, en cinco sub-pestañas |
| ↳ sub-pestaña «Cortes por país y editorial» | `…&tab=preventa&subtab=cortes` (**la que abre por defecto**) | La tabla única de cortes; exige además `manage_categories` para guardar |
| ↳ sub-pestaña «Fechas estimadas» | `…&subtab=fechas` | Días de tránsito de cada país |
| ↳ sub-pestaña «Aviso previo a la llegada» | `…&subtab=recordatorio` | Interruptor y ventana del aviso previo |
| ↳ sub-pestaña «Cambio de fecha» | `…&subtab=cambio-fecha` | Umbral de retraso, ventana entre avisos, adelantos |
| ↳ sub-pestaña «Pedidos atrasados» | `…&subtab=stale` | Las gracias, el tope y la antigüedad del aviso «ya llegó» |
| *Akibara → Por llegar* | `/wp-admin/admin.php?page=akibara-panel&tab=preventas` — **ojo: `preventas`, en plural** | La operativa del día a día: qué tomos se esperan, quién los espera, cambiar una fecha |
| *Akibara → Lista de espera* | `…&tab=back_in_stock` | Los dos interruptores de «Avísame cuando llegue» y la tabla de demanda |
| *Productos → editar producto → pestaña «Disponibilidad»* | la ficha de cada producto | El tipo y la fecha de salida de ese tomo |
| *Productos → Marcas* · *Productos → Atributos → País → Configurar términos* | taxonomías de WooCommerce | Política de pedido de una editorial o de un país |
| *WooCommerce → Ajustes → Correos* | `/wp-admin/admin.php?page=wc-settings&tab=email` | Activar, desactivar y editar el asunto de los tres correos del área |

**Ojo con una trampa de nombres:** `tab=preventa` (singular) son los **ajustes** y `tab=preventas`
(plural) es la pantalla **«Por llegar»**. Son dos pantallas distintas y la única diferencia en la URL
es esa `s` (`Plugin.php:1318-1349`, `OrderPreventa/Settings.php:41`).

### Seis palabras para seis cosas de la pantalla

El capítulo nombra seis piezas visuales distintas. Ésta es la tabla para saber cuál se está mirando,
con la clase CSS con la que se encuentran en el HTML servido:

| Cómo lo llama el capítulo | Dónde sale | Quién lo pinta | Clase CSS |
| --- | --- | --- | --- |
| El **cartel** de fecha | En la ficha del producto, encima del botón de comprar | `EstimatedDelivery\BannerRenderer` | `.akb-eta` |
| La **píldora** «Llegada: 1 – 4 sep» | En cada tarjeta del catálogo, bajo el título | `BannerRenderer` | `.akb-eta-loop` |
| El **badge** de la tarjeta | En cada tarjeta del catálogo: la palabra «A pedido», «Preventa», «Agotado» | `LoopEnhancer` (capítulo 06) | `.akb-badge` |
| El **chip** de faceta | En el buscador: el filtro «A pedido / Preventa» que se pulsa | `akibara-search` (capítulo 09) | — |
| La **línea** por tomo | Bajo **cada producto** de un pedido: «📦 A pedido · Llega entre el 8 y el 14 de octubre · Argentina» | `OrderPreventa\CustomerVisibility` | `.akb-eta-badge` |
| El **cartel global** del pedido | **Una sola vez** por pedido, bajo la tabla de productos, en los correos y en «ver pedido» | `CustomerVisibility` | `.akb-email-callout` |

La distinción entre las dos últimas es la que decide si la trampa más silenciosa del capítulo —el
guard `is_admin()`, más abajo— se nota o no: ahí desaparece la **línea** y sobrevive el **cartel**.

### Dónde viven los países y las editoriales

Dos nombres técnicos aparecen decenas de veces y conviene fijarlos de una vez:

- **`pa_pais`** es el atributo de producto «País». WooCommerce antepone `pa_` a **todos** los
  atributos de producto (*product attribute*), así que el prefijo no significa nada más que eso. Sus
  valores —«Argentina», «España»— se editan en *Productos → Atributos → País → Configurar términos*,
  y cada uno lleva pegados sus días de tránsito.
- **`product_brand`** es la taxonomía «Marcas» de WooCommerce, en *Productos → Marcas*. En Akibara
  una «marca» es **siempre una editorial**: Ivrea, Panini, OVNI. Cada una puede llevar pegada su
  propia política de pedido, que gana sobre la del país.

Los datos que se les pegan son **termmeta** (ver el glosario) y están inventariados en «Datos que
deja».

### Dónde mirar cuando un correo no sale

Las trazas del área se escriben con `Utilities\Log` (`Log.php:36-46`), que es un envoltorio delgado
sobre el registrador de WooCommerce (`wc_get_logger()`). Se leen en ***WooCommerce → Estado →
Registros***, y hay que elegir la fuente correcta: son **tres**.

| Fuente | Qué escribe ahí | Ejemplos |
| --- | --- | --- |
| `akibara-preventa` | Todo lo de pedidos y preventa: el despachador del correo «ya llegó», el propio correo, el atajo de reposición y el paginador cuando un pedido revienta | `ReadyEmailDispatcher.php:388`, `ReadyToShipEmail.php:250, 374`, `StockChangeListener.php:202, 223`, `OrderQueryPaginator.php:83` |
| `akibara-bis` | La lista de espera: el despachador de avisos, la instalación de la tabla y el correo de alta | `NotificationDispatcher.php:392`, `Install.php:82`, `SubscriptionNotice.php:96` |
| `akibara-eda` | El cálculo de fechas | `DateCalculator.php:460` |

**La cola de trabajos** se mira en ***WooCommerce → Estado → Acciones programadas***, filtrando por
grupo. Los seis trabajos de este capítulo que corren por Action Scheduler usan **dos** grupos:
`akibara-order-preventa` (`CarrierHandoffMonitor.php:29`, `StockChangeListener.php:60`,
`ReadyEmailDispatcher.php:51`) y `akibara-bis` (`StockWatcher.php:44`). El grupo va también en la
tabla de «Lo programado».

**Las horas de los cuatro trabajos con reloj** no están escritas en el código —salvo la del domingo—:
salieron del instante en que el evento se agendó por primera vez. Para ver la hora real de hoy:

```bash
wp cron event list --fields=hook,next_run_gmt,recurrence | grep akb_
```

Si un evento **no aparece**, no hay que reinstalar nada: basta con cargar cualquier página del sitio.
Las cuatro clases se auto-reparan en el hook `init`, que corre en toda petición de WordPress
(`PreventaVencidaCron.php:65, 87-89`, `ReadyToShipCron.php:70, 93-95`, y los equivalentes de
`RemindersCron` y `Retention`).

### Qué está encendido hoy, y cómo comprobarlo

Los dos interruptores de la lista de espera —aceptar altas y enviar avisos— nacen **apagados** por
decisión de diseño (`BackInStock.php:10-14`: *«default OFF»*), y eso es todo lo que la lectura del
código puede afirmar. **Qué valor tienen hoy en producción no se midió** y no se puede deducir
leyendo. El comando que lo responde:

```bash
wp option get akb_bis_capture_enabled ; wp option get akb_bis_dispatch_enabled
```

Mientras eso no se mida, todo el módulo `BackInStock` de este capítulo hay que leerlo como «esto es
lo que pasa **si** están encendidos».

### Cómo reproducir en local lo que producción no tiene

Hoy el catálogo real no tiene ningún pre-lanzamiento configurado: los ~422 tomos marcados «Preventa»
son en realidad importación *a pedido*. Eso significa que la mitad de este capítulo —todo lo de
«preventa con fecha»— **no se puede probar contra datos reales**. Para eso existe
`tools/qa-seed-preventa.php`, que monta en el espejo local productos disponibles, a pedido y preventa
con fecha, más pedidos que los usan, incluido uno mixto. Lo importante: crea los pedidos con los
**hooks reales del checkout**, no con `add_product()` a secas; un pedido hecho a mano no lleva las
metas del snapshot y produce falsos «está roto». Acepta una parcela para que dos sesiones de pruebas
simultáneas no se pisen.

---

## Piezas

Setenta y tres piezas, agrupadas por módulo. De cada una: qué hace en una frase, de qué ficheros sale,
en qué momento actúa, qué lee y qué escribe, dónde la opera el dueño y qué ve el cliente.

**Una aclaración que ahorra desconcierto:** en los tres módulos grandes hay una clase que se llama
**igual que su módulo** —`EstimatedDelivery\EstimatedDelivery`, `OrderPreventa\OrderPreventa`,
`BackInStock\BackInStock`—. Es la «raíz»: la que construye a las demás y las conecta, y que por sí
sola casi no hace nada. Cuando el capítulo dice `EstimatedDelivery` a secas puede referirse a la
carpeta, al módulo o a esa clase; el contexto lo aclara, y la clase se nombra siempre con la
contrabarra delante.

### Stock — quién decide el estado comercial

#### `Stock\Availability`

**Qué hace.** Es la única autoridad que decide cuál de los cuatro estados comerciales tiene un
producto, y la dueña de la palabra que lo nombra.

- **Ficheros:** `src/Modules/Stock/Availability.php`.
- **Cuándo actúa:** no se engancha a ningún hook; se la llama cada vez que alguien pregunta. El
  estado **nunca se guarda** (`Availability.php:149-162`).
- **Qué lee:** el stock crudo de WooCommerce y dos metas del producto, `_akb_eta_type` (auto ·
  release_date · tba · disabled) y `_akb_eta_release_date` (fecha `Y-m-d` en que la editorial
  publica). Un tipo ausente o desconocido cae a `auto`, que es «a pedido»
  (`Availability.php:123-125`).
- **Qué escribe:** nada.
- **Qué emite:** el filtro `akb_availability_label`, pensado como punto de override del vocabulario.
  **Hoy no lo engancha nadie**: el grep sobre todo el corte sólo devuelve las tres líneas de la
  propia clase que lo declaran (`Availability.php:167, 189, 207`).
- **Tres vocabularios, no uno.** La misma idea —«a pedido» / «preventa»— está escrita en tres sitios
  distintos que no se hablan entre sí:
  1. **La option de *Akibara → Textos*** (`akb_copy_eta_*`) —esa pantalla es `Modules\CopyManager`,
     capítulo 06—, que gobierna el cartel de la ficha, el carrito, la línea del pedido y los correos.
  2. **La constante `Availability::LABELS`** de esta clase, que gobierna el badge de la tarjeta y la
     ficha técnica. No pasa por la option ni por el filtro.
  3. **Los literales de `ProductFaq`** (capítulo 06): `'disponible en preventa'` y
     `'disponible a pedido'`, escritos a mano en `ProductFaq.php:108`, que terminan dentro del texto
     de las preguntas frecuentes de la ficha — **contenido indexable por Google**.

  Consecuencia práctica: cambiar «A pedido» en *Akibara → Textos* cambia el primer grupo y no toca
  los otros dos. Quien renombre el vocabulario tiene que barrer los tres.
- **Regla fina:** el día del lanzamiento todavía cuenta como preventa; degrada al día siguiente, en
  hora de Chile (`Availability.php:276-282`).
- **Trampa conocida:** `release_timestamp()` acepta cualquier cadena que PHP sepa leer como fecha
  (`Availability.php:368-381`), mientras que `is_valid_release_date()` sí exige `^\d{4}-\d{2}-\d{2}$`
  (`340-350`). Las dos puertas de admin validan, así que hoy no muerde; muerde el día que una
  importación o un `wp post meta update` escriban la meta directamente.
- **Ve el cliente:** indirectamente, la palabra del badge, del botón y de la ficha.
  **Opera el dueño:** indirectamente, la columna «Stock» de la pantalla «Por llegar».

#### `Stock\StockStatus`

**Qué hace.** Centraliza los tres estados de stock crudos de WooCommerce y responde «¿se puede
reservar?», «¿está agotado?», «¿hay en bodega?» sin que nadie escriba el literal a mano.

- **Ficheros:** `src/Modules/Stock/StockStatus.php`.
- **Cuándo actúa:** por llamada directa.
- **Qué emite:** el filtro `akb_stock_status_label`, único punto de override del vocabulario crudo;
  lo consume `akibara-search` con su propio valor por defecto.
- **Detalle de diseño:** `is_reservable()` es el predicado que antes se llamaba `is_preventa()` y
  mentía, porque `onbackorder` agrupa dos negocios. Ante un estado desconocido, la palabra de grupo
  cae a «Agotado» (*fail-closed*).
- **Ve el cliente / opera el dueño:** ninguna superficie propia.

#### `Stock\SearchAvailabilityBridge`

**Qué hace.** Le presta al buscador propio la capacidad de distinguir «preventa» de «a pedido», que
por sí solo no tiene.

- **Ficheros:** `src/Modules/Stock/SearchAvailabilityBridge.php`.
- **Cuándo actúa:** filtro `akb_search_availability_value` (prio 10) y filtro
  `akb_stock_status_label` (prio 10).
- **Ve el cliente:** el chip de faceta «A pedido / Preventa» del buscador y el badge del
  autocompletado. **Trampa conocida:** ese badge, en la rama «preventa», está a fuego en el otro
  plugin (`akibara-search/src/StockLabels.php:175-177`, capítulo 09) y no pasa por el filtro compartido.

#### `Stock\PreventaVencidaCron`

**Qué hace.** Barrido diario que «toca» las fichas cuya fecha de lanzamiento ya pasó, para que el
buscador y las cachés de página se enteren de que dejaron de ser preventa.

- **Ficheros:** `src/Modules/Stock/PreventaVencidaCron.php`.
- **Cuándo actúa:** acción `akb_preventa_vencida_sweep` (prio 10) para el barrido; `init` (prio 10)
  para auto-repararse si alguien borró el evento; `akb_eta_release_date_changed` (prio 10) para
  olvidar su testigo.
- **Qué lee:** hasta 50 productos al día que sean `onbackorder` + tipo `release_date` + fecha
  anterior a hoy + sin el testigo (`PreventaVencidaCron.php:45, 143-152`).
- **Qué escribe:** un `wp_update_post` **vacío** —que dispara `save_post_product` sin mutar ninguna
  meta— y el testigo `_akb_preventa_vencida_swept` para que el cursor avance.
- **Quién escucha esa escritura vacía, que es el punto del ejercicio.** Dos oyentes, ninguno de este
  capítulo:
  - El indexador de `akibara-search` (capítulo 08), que se engancha a `save_post_product` en prio 20
    (`akibara-search/src/Indexer.php:157`) y encola el reindexado **de ese solo producto**. Es el
    camino **barato**: reindexado incremental por producto, no la reconstrucción completa del índice
    que sí disparan los cambios de término y de opciones y que deja el autocompletado en 503 durante
    minutos. Un barrido de 50 fichas encola 50 reindexados de un documento cada uno.
  - LiteSpeed Cache, que purga por su cuenta el HTML cacheado de la ficha cuando el producto se
    guarda.

  Es decir: el barrido no arregla nada del estado —el estado ya estaba bien, porque se deduce—, sólo
  golpea la puerta de las dos superficies que guardaban una copia horneada.
- **Pieza fácil de perder:** alrededor de cada `wp_update_post()` engancha y desengancha un filtro
  temporal `wp_insert_post_data` que **preserva `post_modified`** (`PreventaVencidaCron.php:171-194`).
  Sin él, el barrido le juraría a Google que hasta 50 fichas se editan cada día, y eso alimenta el
  `lastmod` del sitemap y el `dateModified` de las landings de serie. Es exactamente el tipo de
  filtro que alguien «limpia» al refactorizar.
- **Trampa conocida:** el testigo sólo se olvida por el hook `akb_eta_release_date_changed`
  (`63-80`). Una importación o un `wp post meta update` que cambien la fecha sin dispararlo dejan esa
  ficha marcada para siempre: cuando su nueva fecha venza, el barrido no la tocará.

### EstimatedDelivery — quién calcula la fecha

#### `EstimatedDelivery\EstimatedDelivery`

**Qué hace.** La raíz del módulo: construye los sub-componentes y aporta la pantalla «Fechas
estimadas» del panel, donde se editan los días de tránsito de cada país.

- **Ficheros:** `src/Modules/EstimatedDelivery/EstimatedDelivery.php`.
- **Cuándo actúa:** `woocommerce_admin_field_akb_notice` (10), `woocommerce_admin_field_akb_pais_range_pair`
  (10) y `woocommerce_update_option_akb_pais_range_pair` (10).
- **Qué escribe:** termmeta `_akb_min_days` / `_akb_max_days` del término de país; transient
  `akibara_eda_save_errors_{user_id}` (60 s) para que los errores de validación sobrevivan al
  redirect del guardado.
- **Opera el dueño:** *Akibara → Pedidos y preventa · Ajustes → Fechas estimadas*.

#### `EstimatedDelivery\DateCalculator`

**Qué hace.** El cerebro del cálculo: recibe un producto y devuelve el rango de fechas en que
llegaría, o «todavía sin fecha».

- **Ficheros:** `src/Modules/EstimatedDelivery/DateCalculator.php`.
- **Cuándo actúa:** por llamada directa; implementa el contrato `Contracts\EtaProvider`.
- **Cómo decide, en orden:** sin stock `onbackorder` no hay fecha (`149-151`); con el tipo
  «sin promesa de fecha» tampoco (`156-158`); con la editorial en modo «pausada» devuelve «sin
  fecha» a propósito (`165-167`); luego elige la base —hoy, o la fecha de lanzamiento si es
  futura— y aplica la cadena **plazo manual de la ficha → tanda de la editorial o del país → días de
  tránsito del país** (`176-230`).
- **Qué lee y escribe:** postmeta `_akb_eta_manual_min_dias` / `_akb_eta_manual_max_dias` (plazo
  personalizado, 1–365, sólo para preventas); termmeta de días de tránsito, vía `Settings`.
- **Detalle que importa:** suma días de **calendario** con aritmética de fechas, nunca de segundos,
  para no perder un día en el cambio de horario de Chile.
- **Ve el cliente:** todo lo que diga una fecha de llegada —ficha, tarjeta, carrito, checkout, pedido
  y correos—.

#### `EstimatedDelivery\EtaResult`

**Qué hace.** El paquetito inmutable que devuelve el cálculo: o un rango de dos fechas, o «sin fecha
pero con plazo conocido», o «sin fecha a secas». Sin hooks, sin datos propios.
**Ficheros:** `src/Modules/EstimatedDelivery/EtaResult.php`.

#### `EstimatedDelivery\Settings`

**Qué hace.** Lee los días de tránsito de un país y su nombre visible, con dos niveles de respaldo
para no quedarse mudo.

- **Ficheros:** `src/Modules/EstimatedDelivery/Settings.php`.
- **Qué lee:** termmeta del término de `pa_pais` y, si falta, las constantes de respaldo —Argentina
  12–15 días, España 17–20 (`49-52`)—. Valida siempre `1 ≤ mín ≤ máx ≤ 365` (`183-185`). Lee también
  el interruptor global `akibara_eda_business_days_only` («saltar fines de semana», por defecto sí).
- **Opera el dueño:** indirectamente, la pantalla «Fechas estimadas».

#### `EstimatedDelivery\PedidoPolicy`

**Qué hace.** Decide si los pedidos a una editorial —o a un país— se juntan hasta un día de corte del
mes o corren desde la compra, y redacta las frases que se lo explican al cliente.

- **Ficheros:** `src/Modules/EstimatedDelivery/PedidoPolicy.php`.
- **Cómo decide:** primero por la **editorial** (`product_brand`); si no tiene política propia, por
  el **país** (`pa_pais`); si ninguno, «heredado» = plazo continuo (`169-218`).
- **Qué escribe:** termmeta `_akb_pedido_modo` (heredado · continuo · tanda · pausada),
  `_akb_tanda_dia` (1–28, para que el corte exista también en febrero), `_akb_tanda_min_dias` y
  `_akb_tanda_max_dias`.
- **Reglas propias:** quien compra **el propio día** del cierre entra en esa tanda (`245-257`); la
  cuenta atrás sólo aparece en los 7 días previos (`109, 325-327`); una tanda a medias se rechaza
  entera (`500-517`); poner el modo «heredado» borra las cuatro metas, mientras «continuo» y
  «pausada» sólo escriben el modo.
- **Ve el cliente:** la nota bajo el cartel de fecha («Sale en el pedido que cierra el 5 de
  septiembre; desde ahí viaja 2–3 semanas») y la cuenta atrás.
  **Opera el dueño:** *Productos → Marcas*, *Productos → Atributos → País*, y la tabla de cortes.

#### `EstimatedDelivery\BannerRenderer`

**Qué hace.** Pinta la fecha estimada donde el cliente la mira, y escribe las cinco promesas de la
ficha.

- **Ficheros:** `src/Modules/EstimatedDelivery/BannerRenderer.php`.
- **Cuándo actúa:** `wp_enqueue_scripts` (10) para los estilos; `woocommerce_single_product_summary`
  **prio 25** para el cartel de fecha —antes del botón, porque la fecha informa la decisión—;
  `woocommerce_single_product_summary` **prio 31** para el bloque de promesas —después del botón—;
  `woocommerce_after_shop_loop_item_title` **prio 12** para la píldora «Llegada: 1 – 4 sep» de las
  tarjetas.
- **Qué lee:** las seis options de texto `akb_copy_eta_*` de *Akibara → Textos*.
- **Regla del carrito:** manda el tomo que **más tarda**, los disponibles no cuentan, y cualquier
  línea sin fecha contagia a todo el carrito (`367-428`).
- **Trampa conocida:** las cinco promesas (`205-222`) son literales —«Si llega antes de esa fecha, lo
  despachamos de inmediato», «te avisamos una semana antes y cuando llegue a bodega», «el precio que
  pagas hoy no cambia», «si se atrasa más de 60 días desde esa fecha, cancelas y te devolvemos el
  100 %»— sin `get_option()` ni filtro, a diferencia de todo el resto del copy del área. Y no son
  sólo de preventa: `render_promesas()` se pinta para **cualquier** reservable con fecha calculable,
  es decir para los ~422 tomos «a pedido» que son la realidad del catálogo. Además, la promesa de
  «una semana antes» sigue impresa aunque el dueño apague el interruptor del aviso previo, que es lo
  único que la cumple.

#### `EstimatedDelivery\ProductDataPanel`

**Qué hace.** La pestaña «Disponibilidad» dentro de la ficha del producto: ahí el dueño elige, entre
**cuatro** opciones, qué clase de espera tiene ese tomo, y escribe la fecha.

- **Ficheros:** `src/Modules/EstimatedDelivery/ProductDataPanel.php`.
- **Desde 1.116.40 (QA de preventa, 2026-09-20):** «Preventa — con fecha» con el campo de fecha en
  blanco se guarda como **preventa sin fecha confirmada** (`tba`), igual que hace la pantalla «Por
  llegar». Antes quedaba `release_date` sin fecha y `Availability` caía a «a pedido» con la
  urgencia de la tanda de hoy: un tomo que no ha salido de la editorial se vendía como si ya hubiera
  salido (definición del dueño: preventa = aún no sale; a pedido = ya salió y no lo tenemos).
  Una fecha inválida sigue conservando la anterior. Candado: `ProductDataPanelSaveTest`.
- **Los cuatro botones, con su rótulo literal en pantalla** y el valor que cada uno escribe en la
  meta `_akb_eta_type` (los rótulos están en `ProductDataPanel.php:53-56`):

  | Lo que el dueño lee en la pantalla | Valor de `_akb_eta_type` | Qué estado comercial produce |
  | --- | --- | --- |
  | «A pedido (automático) — se pide a la editorial; usa la regla de su editorial o país» | `auto` | A pedido |
  | «Preventa — todavía no sale (con fecha de lanzamiento)» | `release_date` | Preventa mientras la fecha sea hoy o futura; A pedido en cuanto pasa |
  | «Preventa — todavía no sale (aún sin fecha confirmada)» | `tba` | Preventa **siempre**, sin mirar ninguna fecha |
  | «Sin promesa de fecha (no mostrar plazos en la ficha)» | `disabled` | A pedido, pero sin enseñar ninguna fecha |
- **Cuándo actúa:** `woocommerce_product_data_tabs` (10), `woocommerce_product_data_panels` (10),
  `woocommerce_process_product_meta` (10) y `admin_enqueue_scripts` (10).
- **Qué escribe:** `_akb_eta_type`, `_akb_eta_release_date` (o la borra) y el plazo manual.
- **Qué emite:** la acción `akb_eta_release_date_changed` (id, fecha vieja, fecha nueva) — el único
  puente entre la ficha de un producto y los pedidos que lo esperan.
- **Detalle de diseño:** guarda **sólo** si el panel viajó en el envío (campo testigo
  `_akb_eta_panel`); sin eso, cualquier guardado ajeno borraba la configuración. Y conserva la fecha
  anterior avisando, en vez de borrarla en silencio, cuando la escrita es inválida.
- **Opera el dueño:** *Productos → editar producto → pestaña «Disponibilidad»*.

#### `EstimatedDelivery\PaisTermFields`

**Qué hace.** Los campos que aparecen al editar un país: días de tránsito y, desde 2026-08-30,
también el corte de pedidos de ese país.

- **Ficheros:** `src/Modules/EstimatedDelivery/PaisTermFields.php`.
- **Cuándo actúa:** `pa_pais_add_form_fields` (10), `pa_pais_edit_form_fields` (10), `create_pa_pais`
  (10), `edited_pa_pais` (10) y `admin_notices` (10).
- **Qué escribe:** termmeta de días y de política; transient `akibara_eda_pais_term_errors_{user_id}`.
- **Trampa conocida:** el bloque de tanda se delega en `MarcaTermFields` (`174`), y el aviso de
  rechazo que esa clase deja en un transient **sólo se pinta en la pantalla de Marcas**
  (`MarcaTermFields.php:111-114`). Editando un país, una tanda inválida se rechaza entera, WordPress
  imprime «Elemento actualizado» y el motivo caduca a los 60 segundos sin que nadie lo vea.

#### `EstimatedDelivery\MarcaTermFields`

**Qué hace.** Los campos de política de pedido al editar una **editorial**, más una columna
«Pedidos» en el listado para ver de un vistazo cuál quedó en tanda.

- **Ficheros:** `src/Modules/EstimatedDelivery/MarcaTermFields.php`.
- **Cuándo actúa:** `product_brand_edit_form_fields` (10), `edited_product_brand` (10),
  `create_product_brand` (10), `admin_notices` (10), `manage_edit-product_brand_columns` (10) y
  `manage_product_brand_custom_column` (10).
- **Qué escribe:** las cuatro termmeta de política; transient `akb_pedido_aviso_{user_id}` (1 minuto).
- **Detalle:** muestra «Lo que ve el cliente» con la configuración **guardada** y la fecha de hoy, y
  valida delegando en `PedidoPolicy::sanitize_row()` — un único validador para las dos pantallas.

#### `EstimatedDelivery\CortesPanel`

**Qué hace.** Una tabla con **todos** los cortes —países y editoriales— en una sola pantalla, con
vista previa de lo que ve el cliente y botón de deshacer.

- **Ficheros:** `src/Modules/EstimatedDelivery/CortesPanel.php`.
- **Cuándo actúa:** `admin_post_akb_cortes_save` (10) y `admin_post_akb_cortes_restore` (10), ambos
  exigiendo la capacidad `manage_categories` y un nonce.
- **Qué escribe:** termmeta directo —nunca `wp_update_term`, para no disparar el reindexado del
  buscador—; la option `akb_cortes_backup` (hasta 5 respaldos); transient
  `akb_cortes_notice_{user_id}`.
- **Detalle de diseño:** guarda sólo los identificadores que la propia pantalla pintó, hace *diff*
  antes de escribir y una fila inválida no aborta a las demás: el aviso la **nombra**.
- **Opera el dueño:** *Akibara → Pedidos y preventa · Ajustes → «Cortes por país y editorial»*.

#### `EstimatedDelivery\Migration`

**Qué hace.** Migración de una sola vez que rellenó los días de tránsito de cada país cuando el
cálculo dejó de depender de opciones globales (versión 1.14.0). Sella la marca
`akibara_eda_pa_pais_migrated_at` y nunca sobrescribe una termmeta ya válida. Las options antiguas
`akibara_eda_lt_ar_min` / `_max` / `akibara_eda_lt_es_min` / `_max` **sólo** se leen aquí.
**Ficheros:** `src/Modules/EstimatedDelivery/Migration.php`.

#### `EstimatedDelivery\GlaBridge`

**Qué hace.** Le pasa a Google Shopping la fecha en que estará disponible un producto reservable.

- **Ficheros:** `src/Modules/EstimatedDelivery/GlaBridge.php`.
- **Cuándo actúa:** filtro `woocommerce_gla_product_attribute_values` (10) — el plural, porque el
  singular no corre para los productos sin valor manual.
- **Qué escribe:** el campo `availabilityDate` del feed, con el borde **alto** del rango (la fecha
  conservadora). Respeta cualquier fecha manual ya puesta; si el resultado es «sin fecha», no toca
  nada (`98-117`).
- **Ve el cliente:** la ficha del producto en Google Shopping.

### OrderPreventa — el pedido y su espera

#### `OrderPreventa\OrderPreventa`

Raíz del módulo: su constructor arma **veintiún** objetos (`OrderPreventa.php:75-96`) y su
`register()` conecta **veinte** de ellos (`124-145`). El que no se conecta es `SummaryRecalculator`:
no escucha ningún hook, y se lo pasan por el constructor a los cuatro que lo necesitan —`OrderTagging`
(`78`), `ReadyToShipCron` (`79`), `ReleaseDateChangeListener` (`82`) y `WpCli` (`93`)—. Se engancha a
`woocommerce_email_classes` (10) para inyectar los dos correos propios y a `woocommerce_email_actions`
(10) para declarar `akb_order_preventa_t7_reminder`, `_date_changed` y `_date_announced` como
acciones transaccionales —así WooCommerce arranca el mailer antes de re-emitir el hook—.
**Ficheros:** `src/Modules/OrderPreventa/OrderPreventa.php`.

**Qué significa «t7», porque el nombre miente.** `t7` es el nombre histórico del **aviso previo a la
llegada**: viene de «T menos 7 días», cuando el aviso salía exactamente una semana antes. Ese nombre
quedó fosilizado en la option `akb_preventa_t7_enabled`, en el hook
`akb_order_preventa_t7_reminder`, en la meta `_akb_eta_t7_reminder_sent` y en el nombre del modo del
correo. **Pero el 7 ya no es el umbral de nada.** Hoy la ventana la fijan dos options editables
—`akb_preventa_t7_window_min_days` (1 por defecto) y `akb_preventa_t7_window_max_days` (10 por
defecto)— más una duración mínima de la espera, `akb_preventa_t7_lead_days` (14 por defecto). Quien
abra la base de datos y vea `t7` no va a encontrar ningún 7 en ninguna parte: es un nombre propio, no
un número.

#### `OrderPreventa\MetaKeys`

El diccionario: declara en un solo sitio las 7 metas de línea y las 15 de pedido, cada una con el
porqué de su existencia. Sin comportamiento. **Ficheros:**
`src/Modules/OrderPreventa/MetaKeys.php`. El inventario completo está en «Datos que deja».

#### `OrderPreventa\LineItemSnapshot`

**Qué hace.** En el momento exacto de la compra, congela en cada línea del pedido lo que la ficha
prometía.

- **Ficheros:** `src/Modules/OrderPreventa/LineItemSnapshot.php`.
- **Cuándo actúa:** `woocommerce_checkout_create_order_line_item` (10), antes de que WooCommerce
  persista la línea.
- **Qué escribe:** `_akb_eta_was_preventa`, `_akb_eta_type_at_purchase`, `_akb_eta_min_at_purchase` /
  `_akb_eta_max_at_purchase`, `_akb_eta_country_at_purchase`, `_akb_eta_country_name_at_purchase` y
  `_akb_eta_is_tba_at_purchase`. Sólo si el producto era `onbackorder` **en ese instante** (`76-84`).
- **Detalle:** es la regla única de qué metas lleva una línea en espera; el back-fill de consola llama
  al mismo método, para que un pedido histórico quede exactamente igual que uno nuevo.

#### `OrderPreventa\OrderTagging`

> **Pedidos creados fuera de la caja (R4-9d738de7, ALTA, 1.116.28).** Un pedido armado a mano en wp-admin
> (venta por WhatsApp), por REST o por importación no pasaba por los hooks del checkout: ni snapshot por línea
> ni marcas del pedido, y quedaba invisible para todo el módulo (sin aviso «ya llegó», sin columna «Reserva»).
> Ahora `maybe_tag_order_created_outside_checkout()` cuelga de `woocommerce_before_order_object_save` (el
> guardado genérico de toda orden, HPOS y CPT) con una guarda barata —un `get_meta` de `ORDER_HAS_PREVENTA`—
> y sólo trabaja la primera vez: líneas reservables sin snapshot reciben el del estado ACTUAL del producto
> (nota de pedido: «la fecha estimada se tomó hoy, no al comprar»), estados sin promesa se saltan, snapshots
> existentes no se reescriben, y nunca sale correo por este etiquetado tardío. Medido en producción: 0 pedidos
> así en 180 días; medido en el espejo con `wc_create_order()+add_product()`.
> **Corrección de la ronda 5 (1.116.29):** la guarda excluye además los estados «ya salió» (`FulfillmentCapabilities::departed_order_statuses()`: completado y entregas al transportista). Sin ella, un pedido histórico nacido fuera de la caja y cerrado hace meses se reetiquetaba con la fecha de HOY al guardarlo por cualquier motivo si su tomo volvía a estar a pedido, y reaparecía como «Pendiente» en «Reserva» con una nota falsa. Lo que queda fuera a propósito: un pedido que nazca de una sola vez ya completado (integración o importación que cree líneas y estado final en el mismo guardado) no se etiqueta; hoy no existe esa vía, y para lo histórico está `wp akibara order-preventa-backfill`.

**Qué hace.** Resume en el pedido lo que dicen sus líneas.

- **Ficheros:** `src/Modules/OrderPreventa/OrderTagging.php`.
- **Cuándo actúa:** `woocommerce_checkout_create_order` (20) para el checkout clásico;
  `woocommerce_store_api_checkout_update_order_meta` (20) para reconciliar el borrador si el carrito
  cambia antes de pagar; `woocommerce_store_api_checkout_order_processed` (20) como último guard
  idempotente del checkout de bloques. Las **tres** puertas de compra están cubiertas.
- **Qué escribe:** `_akb_eta_has_preventa`, `_akb_eta_pending_count` y `_akb_eta_ready_to_ship = no`
  (sólo si no existía ya); delega el estimado en `SummaryRecalculator`.

#### `OrderPreventa\SummaryRecalculator`

**Qué hace.** El único que escribe y lee «para cuándo llega el último tomo que falta de este pedido».

- **Ficheros:** `src/Modules/OrderPreventa/SummaryRecalculator.php`.
- **Cómo decide:** suma sólo las líneas **pendientes** —ni reembolsadas ni ya en bodega— y se queda
  con la más tardía. La contribución de cada línea la decide el **tipo actual** del producto: sin
  fecha aporta 0; con fecha futura se calcula en vivo y se recuerda en la línea; con fecha vencida
  vale la última promesa de esa línea; y para `auto` o producto borrado vale el snapshot de compra
  —recalcular ahí inflaría la fecha «como si comprara hoy»— (`186-234`).
- **Qué escribe:** `_akb_eta_summary_max` en el pedido, `_akb_eta_min_current` /
  `_akb_eta_max_current` en la línea. **Nunca guarda un 0:** si no hay fecha, la meta se **borra**
  (`111-136`). Deja nota «Estimado de arribo recalculado: X → Y» sólo si había un valor previo
  distinto.

#### `OrderPreventa\PreventaItems`

**Qué hace.** La dueña de «qué está esperando este cliente»: qué líneas siguen vigentes, cuáles ya
llegaron, cuántas unidades quedan tras un reembolso y qué palabra las nombra.

- **Ficheros:** `src/Modules/OrderPreventa/PreventaItems.php`.
- **Reglas propias:** `has_arrived()` mira **primero** la bandera inmutable del pedido y sólo después
  el stock del producto; un producto en la papelera **no** ha llegado (`169-186`).
  `outstanding_qty()` es la única resta de reembolso de la casa —antes los correos imprimían la
  cantidad cruda— (`102-107`). Y `era_preventa()` saca la palabra del **snapshot de compra**, nunca
  del estado de hoy (`262-269`).
- **Qué lee:** las options `akb_copy_eta_single_preventa`, `akb_copy_eta_single_pedido`,
  `akb_copy_eta_noun_preventa` y `akb_copy_eta_noun_pedido` de *Akibara → Textos* (`344-348`).

#### `OrderPreventa\PromiseWindow`

**Qué hace.** Qué ventana de fechas se le prometió a un pedido al comprar, y cuál rige hoy. Toma el
borde más tardío de todas las líneas en espera —un pedido viaja entero— y lo etiqueta con el país y
la editorial del **mismo tomo** que fija esa ventana. `auditable()` excluye cancelados, reembolsados,
fallidos y los pedidos cuyas esperas se reembolsaron todas. No descuenta reembolsos al medir la
promesa: audita lo prometido, no lo vigente.
**Ficheros:** `src/Modules/OrderPreventa/PromiseWindow.php`.

#### `OrderPreventa\PromiseAudit`

**Qué hace.** Compara la fecha prometida con la que ocurrió y emite un veredicto: dentro, tarde,
antes, abierto, vencido o sin promesa. Compara por **día** en la zona de Chile —un pedido listo a las
18:47 del 31 cumple una promesa «hasta el 31»— y mide el desvío contra el borde cruzado, no contra el
centro de la ventana. Al resumir una lista **no promedia** desvíos (un −3 y un +3 darían cero y
dirían que todo va bien): cuenta los incumplimientos y reporta el peor (`93-152`).
**Ficheros:** `src/Modules/OrderPreventa/PromiseAudit.php`. **Opera el dueño:** el bloque «¿Estás
cumpliendo los plazos?» de «Por llegar» y el comando de consola.

#### `OrderPreventa\ShipPromise`

**Qué hace.** La ventana de despacho que la tienda promete —«1-3 días hábiles»— en un solo lugar;
antes estaba escrita a mano en seis superficies. Lee la option `akb_copy_promise_ready_window` y cae
al literal de siempre si está vacía (`34-45`).
**Ficheros:** `src/Modules/OrderPreventa/ShipPromise.php`. **Opera el dueño:** *Akibara → Textos →
Promesas de la tienda*.

#### `OrderPreventa\ShippableStamp`

**Qué hace.** Anota cuándo un pedido con espera se volvió despachable de verdad, para poder medir
después si se cumplió el plazo.

- **Ficheros:** `src/Modules/OrderPreventa/ShippableStamp.php`.
- **Desde 1.116.40:** si el pedido cruza al estado despachable con tomos de preventa **sin llegar**
  (`PreventaItems::pending_items()`), el sello se escribe igual (es una decisión del dueño, medida en
  producción) pero queda una nota en el pedido diciendo cuántos tomos faltan y que desde ahí el
  cliente deja de ver la espera y de recibir avisos de fecha, y que el informe de cumplimiento
  contará ese momento como despacho. Candado: `ShippableStampTest`.
- **Cuándo actúa:** `woocommerce_order_status_changed` (10).
- **Qué escribe:** `_akb_eta_shippable_at`, **una sola vez**: un pedido que va y vuelve no puede
  borrar su propio atraso (`86-99`). Guarda sólo la meta, no el pedido entero, porque corre dentro de
  la propia transición.
- **Dependencia de tercero:** lee `woocommerce_correios_settings['noBlueStatus']` —el ajuste de Blue
  Express— para saber qué estado significa «entregado al transportista» (`113-135`). Si el dueño lo
  cambia en la pantalla de Blue, este sello lo sigue; si esa option desaparece, cae al estado por
  defecto. No hay ninguna pantalla de Akibara que muestre este dato.

#### `OrderPreventa\ReadyToShipCron`

> **La señal de los 60 días (R3-cbc69f7a, 1.116.27).** La ficha promete «si se atrasa más de 60 días desde esa
> fecha, cancelas y te devolvemos el 100 %», y nada en el código medía ese plazo (los avisos internos usan 30 y
> 120, editables). El reembolso sigue siendo MANUAL por decisión del dueño; lo que faltaba era la señal. Ahora
> `Settings::REFUND_AFTER_DAYS = 60` es la ÚNICA fuente del número —la usa la promesa de `BannerRenderer` y el
> chequeo— y NO es una option: el plazo también está escrito en Términos, FAQ y Devoluciones, y una option los
> desincronizaría. En el repaso diario, `maybe_mark_refund_eligible()` deja UNA nota interna (meta
> `ORDER_REFUND_ELIGIBLE_NOTED`) cuando el pedido pasa de 60 días sobre la fecha prometida —día 61: «más de»—, y
> la columna «Reserva» del listado de pedidos añade «· reembolso 100%» junto a «atrasado». No se le escribe al cliente.

**Qué hace.** El repaso diario: marca «listo para despachar» los pedidos cuyos tomos ya están todos
en bodega, y deja notas internas en los que llevan demasiado esperando.

- **Ficheros:** `src/Modules/OrderPreventa/ReadyToShipCron.php`.
- **Cuándo actúa:** acción `akb_order_preventa_recompute_ready` (10) e `init` (10) para
  auto-repararse.
- **Qué hace la primera pasada:** recorre por páginas, de la última a la primera, los pedidos en
  estado operativo con `_akb_eta_ready_to_ship = no`. Si no queda nada vigente, marca listo con nota
  y **no** dispara correo. Si quedan pendientes, actualiza el contador y evalúa la nota de atraso. Si
  no queda nada pendiente, escribe `ready_to_ship = yes` + `ready_date` y dispara
  `akb_order_preventa_marked_ready`.
- **Qué hace la segunda pasada:** avisa de los pedidos ya listos que llevan **4 días hábiles o más**
  sin despachar. El corte es `if ( $business_days < $stale_threshold ) { return; }` con
  `OPT_STALE_POST_READY_DAYS` = 4 (`322-327`): con exactamente 4 días hábiles la nota ya se escribe.
- **Qué emite:** `akb_order_preventa_marked_ready` (acción) y `akb_order_preventa_holidays_mmdd`
  (filtro, para inyectar feriados chilenos al conteo de días hábiles).
- **Regla dura:** la marca `yes` es **inmutable** — aunque el producto se agote de nuevo, el pedido no
  vuelve atrás (`6-7, 184-187`).
- **Trampa conocida:** la nota que se escribe cuando ya no queda nada vigente dice literalmente «Ya no
  queda nada pendiente tras el reembolso — no se envía aviso de llegada» (`150-162`), pero la
  condición que la dispara es falsa por **cualquier** vía: también si esas líneas perdieron
  `_akb_eta_was_preventa` por una edición a mano o una importación, sin que hubiera reembolso alguno.
  El pedido queda marcado listo de forma irreversible, el cliente se queda sin el correo, y el
  historial culpa a un reembolso que no existió.
- **Trampa menor:** el conteo de días hábiles avanza sumando `DAY_IN_SECONDS` (`355-364`), así que en
  la semana del cambio de horario chileno puede saltarse o repetir un día. Sólo afecta a una nota
  interna.

#### `OrderPreventa\RemindersCron`

**Qué hace.** El repaso semanal que dispara el aviso «tu pedido se acerca», una sola vez por pedido.

- **Ficheros:** `src/Modules/OrderPreventa/RemindersCron.php`.
- **Desde 1.116.40:** la ventana del aviso vive en dos helpers públicos, `days_until_arrival()` y
  `within_t7_window()`, que también usa el reenvío manual «Reenviar aviso previo a la llegada»
  (`ReadyEmailDispatcher`): la acción sólo se ofrece dentro de la ventana y, si se fuerza fuera,
  deja nota («al pedido le quedan N días, fuera de la ventana de 1 a 10 días») en vez de mandar
  «tu pedido llega pronto» con la llegada a cinco meses vista.
- **Cuándo actúa:** acción `akb_order_preventa_t7_reminder_scan` (10) e `init` (10), que programa o
  **desprograma** según el interruptor (`78-93`).
- **A quién elige:** pedidos con espera, no listos, con líneas vigentes, en estado de espera, sin
  marca de enviado y con estimado vigente; y que queden entre 1 y 10 días para la fecha, con una
  espera original de al menos 14 días (`153-205`).
- **Qué emite:** `akb_order_preventa_t7_reminder`; la marca de enviado la pone el propio correo.
- **Hora:** domingos a las 03:00 de Chile, escrito en el código (`114-120`), para que el correo llegue
  el lunes por la mañana. Es la única hora fija de todo el capítulo.

#### `OrderPreventa\ReleaseDateChangeListener`

**Qué hace.** Cuando la editorial mueve, anuncia o retira la fecha de salida de un tomo, decide qué
significa el cambio, recalcula los pedidos afectados y decide a quién hay que escribirle.

- **Ficheros:** `src/Modules/OrderPreventa/ReleaseDateChangeListener.php`.
- **Desde 1.116.40 (QA de preventa, 2026-09-20):** (1) el lote y el guard usan
  `FulfillmentCapabilities::recompute_order_statuses()` = estados de espera **más `on-hold`**: a un
  pedido en espera de pago (transferencia sin confirmar, el estado normal de un comprador recién
  llegado) se le **recalcula** la fecha y se le registra la firma, pero **no se le escribe** —queda la
  nota «Cambio de fecha NO notificado al cliente: el pedido está en espera de pago…»— y verá la fecha
  nueva en su pedido y en el correo de confirmación cuando pague. Antes quedaba con la fecha vieja para
  siempre. (2) La nota de traza distingue «Adelanto registrado» de «Postergación registrada». (3) En
  el correo, «fecha anunciada» sobre una línea comprada CON fecha (retirada y vuelta a anunciar) sale
  con asunto y encabezado de cambio de fecha y recuadro ámbar, no de celebración; y la línea «Te
  llegaría alrededor del…» se calla si el arribo estimado quedó anterior a la nueva salida (antes que
  contradecirse). (4) La pantalla «Por llegar» dice cuántos de los pedidos que «esperan» el tomo ya
  salieron hacia el transportista y por tanto no recibirán correo. Candados:
  `ReleaseDateChangeWiringTest`, `ReleaseDateChangeClassifierTest`, `ReminderEmailWordTest`,
  `PreventaReminderTemplateDatesTest`.
- **Cuándo actúa:** `akb_eta_release_date_changed` (10) para **agendar** —el recorrido de todos los
  pedidos no cabe en el guardado del producto y se perdía entero al expirar— y
  `akb_order_preventa_release_date_changed_batch` (10) para hacer la pasada real.
- **Cómo clasifica** (`387-427`): sin fecha → fecha = «anunciada»; fecha → sin fecha = «retirada»;
  hacia una fecha **ya pasada** = nada; retraso de 7 días o más = «postergación»; adelanto de 7 días o
  más **sólo** si el interruptor está encendido; el resto, nada.
- **Candado:** toma `_akb_preventa_datechg_lock` en `wp_options` con `INSERT IGNORE`, sin pasar por
  la caché de options, para que dos pasadas solapadas no manden dos correos; el perdedor reintenta en
  un minuto en vez de perder el aviso. Caduca solo a los 15 minutos.
- **Qué escribe:** `_akb_eta_date_change_sigs` —firmas `vieja__nueva` ya registradas, para que el
  mismo cambio no se repita— y notas internas. El recálculo del estimado corre **siempre**, aunque la
  firma ya estuviera registrada (`452-481`).
- **Reglas propias:** «fecha anunciada» se suprime con nota operativa si tras el recálculo el pedido
  no tiene una fecha que el cliente pueda ver (`474-487`); «fecha retirada» **no manda correo**
  —decisión sellada del dueño el 2026-07-17— y sólo escribe la nota «Fecha retirada · producto #N ya
  no tiene fecha de salida vigente», que se compone en `event_note()` (`557-571`).
- **Trampa conocida:** al pasar un tomo de «preventa con fecha» a «a pedido», esa nota interna queda
  en el pedido mientras el cliente sigue viendo la fecha del snapshot de compra —que puede ser
  **anterior** a la última que se le notificó por correo—.

#### `OrderPreventa\StockChangeListener`

**Qué hace.** Cuando un tomo vuelve a estar en stock, no espera al repaso diario: agenda en segundos
una revisión de los pedidos que lo esperaban.

- **Ficheros:** `src/Modules/OrderPreventa/StockChangeListener.php`.
- **Qué pedidos mira (R2-dc4972fb, 1.116.26).** Preventas (`has_preventa = yes`) que **no** estén
  marcadas listas: `ready_to_ship != yes` **o sin la meta**. Hasta la 1.116.25 exigía `= 'no'`, y un
  pedido nacido sin pasar por `OrderTagging` (importación, API, alta a mano) quedaba fuera y esperaba
  al repaso diario. Era el cuarto consumidor de esa regla; los otros tres ya estaban corregidos.
  Medido en el espejo con HPOS: consulta válida.
- **Cuándo actúa:** `woocommerce_product_set_stock_status` **prio 10**
  (`StockChangeListener.php:72`) y `akb_order_preventa_recompute_for_product` (10) para el obrero.
- **No es gemelo del vigía de la lista de espera, aunque lo parezca.** `BackInStock\StockWatcher`
  escucha el mismo hook en prio 11 —orden determinístico acordado a propósito—, pero escucha
  **dos**: `woocommerce_product_set_stock_status` y también
  `woocommerce_variation_set_stock_status` (`StockWatcher.php:62-63`). Éste sólo escucha el primero.
  La consecuencia operativa: reponer una **variación** de un producto variable manda los avisos de la
  lista de espera pero **no** dispara el atajo de +5 s; esos pedidos esperan al repaso diario de las
  ~19:30. Cuánto muerde ese hueco depende de si el catálogo tiene productos variables, dato que no se
  midió en producción: se comprueba contando las variaciones existentes con
  `wp post list --post_type=product_variation --format=count`. Si ese número es 0, el hueco es
  inofensivo hoy — pero se vuelve real el día que se cree el primer producto variable.
- **Detalle de diseño:** deduplica **por argumentos**, nunca con el flag global de Action Scheduler,
  que ignora los argumentos y colapsaría un restock masivo a un solo producto. Al ejecutarse reclama
  de forma atómica las acciones hermanas del lote y procesa todos esos productos en un **único**
  recorrido paginado. Nunca modifica el stock: por eso no puede entrar en bucle.

#### `OrderPreventa\RefundListener`

Tras un reembolso, recalcula el pedido en el acto en vez de esperar al repaso diario. Se engancha a
`woocommerce_order_refunded` (20) y llama a `ReadyToShipCron::process_order()` si el pedido sigue
activo y no está ya listo. **Ficheros:** `src/Modules/OrderPreventa/RefundListener.php`.

#### `OrderPreventa\ReadyEmailDispatcher`

**Qué hace.** La máquina que hace salir el correo «tu pedido ya llegó»: lo agenda, lo reintenta, lo
vuelve a intentar en el cambio de estado y ofrece el reenvío manual.

- **Ficheros:** `src/Modules/OrderPreventa/ReadyEmailDispatcher.php`.
- **Cuándo actúa:** `akb_order_preventa_marked_ready` (10) para agendar;
  `akb_order_preventa_dispatch_ready_email` (10) para el obrero; `action_scheduler_failed_execution`
  (10) y `action_scheduler_failed_action` (10) para reagendar a +305 s;
  `woocommerce_email_enabled_customer_completed_order` (10) para **suprimir el correo nativo
  «pedido completado»** en pedidos con espera pendiente; `woocommerce_order_status_changed` (20) para
  reintentar al entrar en un estado apto; `woocommerce_order_actions` (10) y los tres
  `woocommerce_order_action_akb_resend_*` (10) para el reenvío manual desde «Acciones del pedido»:
  «tu pedido ya llegó» (si está listo), «aviso previo a la llegada» (si espera) y, desde 1.116.38,
  **«Reenviar aviso de cambio de fecha»** (`R3-reenvio-aviso-de-fecha`, decisión del dueño
  2026-09-20), que se ofrece sólo cuando el pedido espera y tiene un cambio de fecha registrado que
  sigue vigente: `last_date_change()` toma la firma `old__new` más reciente del CSV
  `_akb_eta_date_change_sigs` (postergación o adelanto; «anunciada»/«retirada» no cuentan) y la
  casa con la fecha de salida ACTUAL de una línea pendiente —las firmas no llevan producto—; si la
  fecha volvió a moverse o se retiró no hay nada honesto que reenviar y la acción no aparece (y si
  se fuerza, deja nota «NO posible»). El reenvío suelta el tope de frecuencia y su contador y
  dispara el mismo evento del envío automático (`akb_order_preventa_date_changed`), así que pasa
  por la única puerta: correo apagado → nota «NO enviado… deshabilitado»; transporte caído →
  `SendFailureRearm`; salida → «Email de cambio de fecha enviado al cliente». Candado:
  `ResendDateChangeActionTest`.
- **Detalle de diseño:** instancia el mailer **dentro** del obrero, cuando WooCommerce ya cargó sus
  clases de correo.
- **Precisión sobre el tope de antigüedad:** la option `akb_preventa_ready_email_max_age_days` (7 por
  defecto; 0 lo desactiva) se consulta en **un solo sitio**, `maybe_resend_ready_email()`, que es
  el re-enganche por cambio de estado. Ni el camino principal (`akb_order_preventa_marked_ready` →
  `schedule_ready_email_dispatch()`), ni el reenvío manual (`handle_resend_ready_email_action()`),
  ni el reintento de Action Scheduler (`maybe_retry_failed_ready_email_dispatch()`) lo consultan
  (referencias por método: los números de línea de este fichero cambian con cada versión). En la práctica el
  camino principal siempre tiene 0 días de antigüedad, pero **no** existe el candado global que la
  regla enunciada de forma general sugiere.

#### `OrderPreventa\SendFailureRearm`

Cuando el proveedor de correo **rechaza de verdad** un envío, quita la marca de «ya enviado» para que
el pedido vuelva al conjunto elegible, con un tope de tres intentos para no ciclar contra un relay
caído; al agotarlos deja una nota con la instrucción de reenviar desde «Acciones del pedido»
(`52-97`). **Ficheros:** `src/Modules/OrderPreventa/SendFailureRearm.php`.

#### `OrderPreventa\ReadyToShipEmail`

**Qué hace.** El correo «Tu pedido {order_number} ya llegó a Akibara», con la lista de lo que se
esperaba y la promesa de despacho.

- **Ficheros:** `src/Modules/OrderPreventa/ReadyToShipEmail.php`,
  `templates/emails/customer-preventa-ready.php` y `templates/emails/plain/customer-preventa-ready.php`.
- **Guards antes de enviar:** estado apto, pedido con espera, líneas vigentes, sin marca de enviado,
  presupuesto de intentos, correo de facturación presente y email habilitado en WooCommerce.
- **Cómo se protege:** toma un bloqueo de base de datos (`GET_LOCK`) por pedido; arma **todo** el
  mensaje antes de poner la marca —si la plantilla revienta, la marca no llega a escribirse y el
  reintento es seguro—; comprueba con una lectura directa sin caché que la marca quedó de verdad en
  la base; y cierra en el `finally` todo lo que WooCommerce deja abierto (idioma, bandera de envío y
  los tres filtros de transporte).
- **Qué escribe:** `_akb_eta_ready_email_sent` y `_akb_eta_ready_email_attempts`.
- **Opera el dueño:** *WooCommerce → Ajustes → Correos → «Akibara · Preventa lista»* (asunto,
  encabezado y texto adicional).

#### `OrderPreventa\ReminderEmail`

> **Un aviso que no sale deja rastro (R3-2f7636fd, ALTA, 1.116.27).** Si el correo «Akibara · Preventa
> recordatorio» está apagado en WooCommerce → Emails cuando la editorial mueve o anuncia una fecha, el aviso al
> cliente no sale — y antes no quedaba nada que lo dijera: el listener ya había consumido la firma del cambio y
> dejado su nota de traza. Ahora el pedido recibe la nota «Email de cambio de fecha NO enviado al cliente: el
> correo … está deshabilitado». La huella que impide reintentar NO se marca, así que el SIGUIENTE cambio se avisa
> con normalidad al reencender. No hay reenvío automático: a quién se le escribe lo decide el dueño (cap. 21). El
> modo T-7 no necesita nota: sin marca de enviado, el repaso semanal lo reintenta solo mientras dure su ventana.

**Qué hace.** Un solo correo con tres modos: «tu pedido se acerca», «la editorial movió la fecha» y
«la editorial anunció fecha».

- **Ficheros:** `src/Modules/OrderPreventa/ReminderEmail.php`,
  `templates/emails/customer-preventa-reminder.php` y su gemela en `plain/`.
- **Cuándo actúa:** `akb_order_preventa_t7_reminder_notification` (10),
  `akb_order_preventa_date_changed_notification` (10) y
  `akb_order_preventa_date_announced_notification` (10) — los tres con el sufijo que añade
  WooCommerce al re-emitirlos con el mailer ya arrancado.
- **Reglas propias:** en el modo aviso previo, la marca se escribe **antes** del envío (corta el
  doble disparo) y la nota afirmativa **después**, sólo si el transporte no dijo que no. En los modos
  de fecha se aplica el tope de frecuencia —no re-notificar más de una vez cada 14 días por
  defecto—, y ese tope es **asimétrico**: la postergación lee y escribe sólo su marca; el anuncio lee
  las dos pero escribe sólo la suya, para que una buena noticia nunca suprima un retraso posterior
  (`431-494`).
- **Dónde se cambia el texto — y dónde no.** El campo del panel de WooCommerce pertenece **sólo** al
  modo aviso previo: `get_subject()` y `get_heading()` cortocircuitan a `parent::` únicamente cuando
  el modo es `t7`, y en los otros dos devuelven el texto propio pasado por los filtros
  `woocommerce_email_subject_akb_preventa_reminder` y `woocommerce_email_heading_akb_preventa_reminder`
  (`273-303`). El propio rótulo del campo lo dice: «Asunto (aviso previo a la llegada)», y su
  descripción avisa de que «los modos "cambio de fecha" y "fecha anunciada" usan su texto propio»
  (`700-716`). Consecuencia práctica: cuatro asuntos que el cliente lee —
  `SUBJECT_DATE_CHANGED_PREVENTA`, `SUBJECT_DATE_CHANGED_PEDIDO`, `SUBJECT_DATE_ANNOUNCED_PREVENTA` y
  `SUBJECT_DATE_ANNOUNCED_PEDIDO` (`192-208`)— y dos encabezados **no tienen pantalla**: se cambian
  desplegando código o escribiendo un filtro PHP. Uno de ellos comunica una postergación.
- **Qué escribe:** `_akb_eta_t7_reminder_sent` / `_attempts`,
  `_akb_eta_date_change_last_notified_at` / `_send_attempts` y
  `_akb_eta_date_announced_last_notified_at` / `_send_attempts`.

#### `OrderPreventa\CustomerVisibility`

**Qué hace.** Todo lo que el cliente ve sobre su espera fuera de los correos de preventa: la línea
bajo cada producto, el cartel del pedido y la columna «Llegada» en su lista de pedidos.

- **Ficheros:** `src/Modules/OrderPreventa/CustomerVisibility.php`.
- **Cuándo actúa:** `woocommerce_order_item_meta_end` (10) para la línea por producto;
  `woocommerce_email_after_order_table` (10) para el cartel en los correos;
  `woocommerce_order_details_after_order_table` (10) para el cartel en «ver pedido»;
  `woocommerce_account_orders_columns` (10) y
  `woocommerce_my_account_my_orders_column_akb_preventa_llegada` (10) para la columna «Llegada».
- **Qué pinta:** «📦 A pedido · Llega entre el 8 y el 14 de octubre · Argentina», o «Ya llegó a
  nuestra bodega · Argentina»; el verbo cambia a «Llegaba» cuando la ventana ya pasó, para no hablar
  en presente de una fecha vencida. El cartel tiene tres estados —listo para despachar, con fecha, o
  vencida con el texto de disculpa editable—.
- **Qué suprime:** el **cartel** en pedidos cancelados, reembolsados, fallidos y en los que ya
  salieron (completado o entregado al transportista), porque ahí «salía en el correo del código de
  seguimiento» (`384-386, 399-403`); la **línea** se conserva como traza del historial.
- **Detalle que costó una caída:** nunca pasa el texto editable del dueño como formato de `sprintf`
  —un «%» suelto tumbaba la página del pedido—: usa `str_replace`.
- **Trampa conocida, y es la más silenciosa del capítulo.** El callback de la línea por producto
  abre con `if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) { return; }`
  (`85-87`), justificado como «evita doble render con `OrderItemDisplay`». Ese doble render no puede
  ocurrir: `OrderItemDisplay` cuelga de **otro** hook (`woocommerce_after_order_itemmeta`), que
  WooCommerce sólo dispara en el metabox de artículos del pedido, nunca dentro de una plantilla de
  correo. Lo que sí ocurre es que `is_admin()` vale verdadero en **cualquier** petición de wp-admin:
  cuando el dueño cambia el estado de un pedido desde la lista, o pulsa «Reenviar correos del
  pedido», el correo se compone dentro de esa petición y la línea «📦 A pedido · Llega entre el 2 y
  el 5 de junio · Argentina» **desaparece**. El cliente recibe el cartel global, que no lleva ese
  guard, pero sin el detalle por tomo — y ese mismo pedido sí la muestra en «ver pedido». Es
  invisible en una prueba que dispare los correos por consola o por la cola, porque ahí `is_admin()`
  es falso o `DOING_AJAX` es verdadero.

#### `OrderPreventa\OrderItemDisplay`

La línea técnica bajo cada producto en la pantalla del pedido del **admin**: qué era al comprarse
—leído del snapshot, nunca del estado de hoy—, qué fecha se prometió, el país, y «✓ Disponible hoy»
si el producto ya está en stock. Se engancha a `woocommerce_after_order_itemmeta` (10).
**Ficheros:** `src/Modules/OrderPreventa/OrderItemDisplay.php`.

#### `OrderPreventa\AdminColumn`

La columna «Reserva» en la lista de pedidos: verde si está lista, amarillo con la fecha si está
pendiente, rojo si la fecha ya pasó. Decide **sólo** por el estimado vigente —sin fecha dice «por
confirmar», nunca una fecha de 1970— y usa el mismo corte de «atrasado» que ve el cliente. Hooks:
`manage_woocommerce_page_wc-orders_columns` (10), `manage_woocommerce_page_wc-orders_custom_column`
(10) y `admin_print_styles` (10). **Ficheros:** `src/Modules/OrderPreventa/AdminColumn.php`.

#### `OrderPreventa\AdminFilter`

El desplegable sobre la lista de pedidos para ver sólo los que esperan, sólo los listos, o sólo los
que no tienen espera. Hooks: `woocommerce_order_list_table_restrict_manage_orders` (10) y
`woocommerce_order_list_table_prepare_items_query_args` (10).
**Trampa conocida:** compara por el literal exacto `_akb_eta_ready_to_ship = 'no'` (`82-85`),
mientras los dos crons usan `!= 'yes'` **o** `NOT EXISTS`. Un pedido importado o creado a mano que
tenga líneas en espera pero nunca haya recibido esa meta no aparece al filtrar «Solo preventa
pendiente», sin ningún aviso. **Ficheros:** `src/Modules/OrderPreventa/AdminFilter.php`.

#### `OrderPreventa\CompletionPolicy`

**Qué hace.** Impide que un pedido se dé por completado mientras siga esperando mercadería, venga el
intento de donde venga.

- **Ficheros:** `src/Modules/OrderPreventa/CompletionPolicy.php`.
- **Cuándo actúa:** `woocommerce_payment_complete_order_status` **prio 20** (degrada a «procesando»
  el completado automático de la pasarela de pago); `woocommerce_before_order_object_save` **prio 0**
  (restaura el estado previo **antes** de guardar, así que WooCommerce ni siquiera publica el
  «completado» a transportistas ni a plugins de reseñas); `woocommerce_order_status_completed`
  **prio 0** como red de seguridad para rutas que eludan el guardado normal, con guardia contra la
  recursión (`80-107, 156-162`).
- **Qué emite:** `akb_order_preventa_completion_blocked` (acción),
  `akb_order_preventa_can_complete_order` (`161`) y `akb_order_preventa_can_request_review` (`174`),
  los dos filtros pensados como contrato cooperativo para integraciones. **Hoy no los engancha
  nadie**: el grep sobre todo el corte devuelve sólo esas dos líneas que los declaran.
- **Cómo se conecta de verdad con `akibara-rewards`.** Por una llamada estática directa desde el otro
  plugin, no por los filtros: `akibara-rewards/src/Reviews/Invitations.php:131-132` y `:175-176`
  invocan `CompletionPolicy::can_request_review( $order )` tras comprobar con `class_exists()` que
  `akibara-business` esté presente (si no está, se falla hacia invitar: una tienda sin preventas no
  debe bloquear reseñas). Es una **dependencia dura** de `akibara-rewards` sobre esta clase y este
  método: renombrarlo o moverlo lo rompe sin que ningún filtro lo advierta.

#### `OrderPreventa\CompletionBlockedNotice`

Explica **en pantalla** por qué el pedido que el dueño acaba de intentar completar sigue igual,
nombrando los tomos que faltan. Apunta el bloqueo en la **usermeta** de quien lo provocó
(`_akb_preventa_completion_blocked`), no en un transient global, para que el mensaje le llegue a esa
persona tras el redirect; lista hasta cinco tomos por pedido y hasta veinte pedidos por acción en
lote. Hooks: `akb_order_preventa_completion_blocked` (10) y `admin_notices` (10).
**Ficheros:** `src/Modules/OrderPreventa/CompletionBlockedNotice.php`.

#### `OrderPreventa\CarrierHandoffMonitor`

**Qué hace.** Vigila que el transportista añada el número de seguimiento después de que se le entregue
un pedido; si a las dos horas no está, deja una alerta.

- **Ficheros:** `src/Modules/OrderPreventa/CarrierHandoffMonitor.php`.
- **Cuándo actúa:** `woocommerce_order_status_changed` (20) para agendar y
  `akb_order_preventa_verify_carrier_handoff` (10) para comprobar, a +2 h.
- **Qué escribe:** `_akb_eta_carrier_handoff_alerted`.
- **Qué emite:** `akb_order_preventa_carrier_handoff_alerted` y
  `akb_order_preventa_carrier_handoff_alert_message` (filtros), para que un adaptador pueda decir que
  ya avisó por su cuenta o poner el nombre real del transportista.
- **Deuda menor:** el texto de la alerta repite «2 horas» escrito a mano (`114`) en vez de derivarlo
  de la constante que fija la espera, `DELAY_SECONDS` (`30`), que hoy vale exactamente eso
  (`2 * HOUR_IN_SECONDS`). Los dos números coinciden; la deuda es que nada garantiza que sigan
  coincidiendo si alguien cambia la constante. Y, para rematar, el mensaje que de verdad le llega al
  dueño es el del adaptador de Blue Express, que vive en otro fichero.

#### `OrderPreventa\FulfillmentCapabilities`

**Qué hace.** El contrato neutral entre el módulo y cualquier plugin de transporte: qué estados
cuentan como «activo», «en espera», «apto para el correo de llegada» o «entregado al transportista»,
y de dónde salen los números de seguimiento. Por defecto declara sólo `processing` y deja que los
adaptadores añadan los suyos por filtro; detecta el retiro en tienda mirando los métodos que
WooCommerce registra como retiro local; normaliza estados quitando el prefijo `wc-`.

- **Ficheros:** `src/Modules/OrderPreventa/FulfillmentCapabilities.php`.
- **Qué emite (nueve filtros):** `akb_order_preventa_active_order_statuses`,
  `_waiting_order_statuses`, `_ready_email_order_statuses`, `_can_send_ready_email`,
  `_carrier_handoff_statuses`, `_tracking_references`, `_is_local_pickup_order`,
  `_requires_carrier_fulfillment` y la re-aplicación de `woocommerce_local_pickup_methods` del propio
  WooCommerce, para que los terceros lo sigan viendo.
- **Contradicción documentada:** `ready_email_order_statuses()` deriva de `active_order_statuses()`
  (`65-73`), donde el adaptador de Blue Express mete el estado de entrega al transportista. Así que el
  correo «tu pedido ya llegó a Akibara» **puede** salir en un estado en el que `CustomerVisibility`
  calla el cartel a propósito. Dos superficies, dos criterios sobre el mismo hecho.

#### `OrderPreventa\OrderQueryPaginator`

Recorre pedidos por páginas sin saltarse ninguno y sin que un pedido envenenado tumbe la pasada
entera: va de la **última** página a la primera —si un pedido deja de cumplir el filtro al
procesarlo, las páginas de abajo no se desplazan—, aísla cada pedido en su propio `try/catch` y deja
traza del que falla. `limit` es tamaño de **página**, no tope de trabajo.
**Ficheros:** `src/Modules/OrderPreventa/OrderQueryPaginator.php`.

#### `OrderPreventa\Settings`

**Qué hace.** La pantalla «Pedidos y preventa · Ajustes» con sus cinco sub-pestañas, y el lector con
valores por defecto de todos los umbrales del módulo.

- **Ficheros:** `src/Modules/OrderPreventa/Settings.php`.
- **Cuándo actúa:** `update_option_akb_preventa_t7_enabled` (10) para programar o desprogramar el
  cron semanal al guardar el interruptor; `akibara_panel_form_hidden_inputs` (10) para conservar la
  sub-pestaña activa; `akibara_panel_save_errors` (10) para rechazar ventanas incoherentes (una
  mínima mayor que la máxima apagaría el aviso en silencio); `akibara_panel_section_is_action` (10)
  para suprimir el formulario en la sub-pestaña «cortes», que trae el suyo.
- **Qué lee:** las once options del módulo (ver «Datos que deja»), siempre con su valor por defecto
  canónico, para que los crons funcionen igual aunque el dueño no toque nada.

#### `OrderPreventa\PreventasScreen`

**Qué hace.** La pantalla «Por llegar»: qué tomos espera la gente —agrupados **por tomo**, que es como
llega la mercadería—, quién los espera, y dos botones: cambiar la fecha de un tomo y forzar una
revisión.

- **Ficheros:** `src/Modules/OrderPreventa/PreventasScreen.php`.
- **Cuándo actúa:** `admin_post_akb_preventa_revisar` (10) y `admin_post_akb_preventa_fecha` (10),
  ambos exigiendo `manage_woocommerce` y nonce; `admin_notices` (10).
- **Qué muestra:** hasta 60 tomos pendientes, los urgentes primero (fecha vencida y, a igualdad, más
  pedidos); el estado real de cada tomo, incluidos «En la papelera» y «Eliminado» —los borrados se
  rescatan leyendo el id desde la meta de la línea—; y el bloque «¿Estás cumpliendo los plazos?» con
  la **misma** regla que el comando de consola.
- **La sub-vista «Cambiar fecha»** dice de antemano exactamente qué correo va a salir, con qué
  asunto, a cuántas personas y a cuántas **no** les llegará por el tope de frecuencia. Al guardar
  escribe por el CRUD de WooCommerce —para que el buscador reindexe y las cachés del producto se
  invaliden—, purga la página en LiteSpeed (`litespeed_purge_post`) y dispara el **mismo** hook
  `akb_eta_release_date_changed` que la ficha del producto.
- **Qué rechaza:** fechas inválidas, fechas ya pasadas, fechas a más de cinco años y tomos en la
  papelera.
- **Coste conocido:** abrir la pantalla paga **dos** recorridos completos de la tabla de pedidos
  —`esperas_por_tomo()`, llamada en `108`, y `render_cumplimiento()`, que va de `253` a `345` y
  consulta con `'status' => 'any'` (`267`), es decir **todos** los pedidos con espera de la historia;
  el bloque «¿Estás cumpliendo los plazos?» que pinta con ese dato empieza en `311`— y la sub-vista
  de fecha
  paga un **tercero**, porque `pedidos_de()` (`776-786`) vuelve a ejecutar `esperas_por_tomo()`
  entera para sacar los identificadores de un solo tomo. Con decenas de pedidos no se nota; con
  cientos, lo primero que deja de cargar es justamente el bloque que mide si se cumplen los plazos.

#### `OrderPreventa\PreventaUX`

**Qué hace.** Ajusta cómo se presenta un producto reservable: el texto del botón y el precio, que
WooCommerce pinta como si fuera una rebaja cuando en realidad es el precio de reserva.

- **Ficheros:** `src/Modules/OrderPreventa/PreventaUX.php`.
- **Cuándo actúa:** `woocommerce_product_single_add_to_cart_text` (10) y
  `woocommerce_product_add_to_cart_text` (10) para el botón;
  `woocommerce_product_add_to_cart_description` (10) para alinear el nombre accesible con el texto
  visible (WCAG 2.5.3); `woocommerce_get_price_html` **prio 25** para reescribir el precio; y
  `litespeed_ucss_whitelist` (20) para proteger `.akb-price--preventa` y `.akb-preventa-alza` del
  recorte de CSS de LiteSpeed.
- **Dónde está el umbral.** La reescritura del precio —quitar los textos de lector de pantalla que
  llamaban «precio original» al precio **futuro** y poner la nota «Al llegar el stock sube a $X»—
  sólo ocurre si el descuento **no** supera el umbral de oferta real:
  `apply_filters( 'akibara_loop_sale_badge_min_pct', SaleDiscount::configured_min_pct(), $product )`
  (`192-197`), donde `SaleDiscount` es la pieza de campañas de descuento del capítulo 06. Ese mismo
  filtro y ese mismo valor por defecto los usa el badge «−NN %» del listado, a
  propósito: si la ficha y el listado no comparten la fuente, el sello rojo aparece en uno y no en el
  otro. Es la palanca que separa «oferta de verdad» de «precio de reserva» en las fichas reservables
  con segundo precio, y cambiarla altera una afirmación de precio ante el consumidor en todo el
  catálogo reservable.
- **Qué emite:** `akibara_preventa_price_note` (filtro) — único punto de override o de apagado de la
  nota; devolver cadena vacía la suprime.

#### `OrderPreventa\ContactFooter`

**Qué hace.** El bloque «¿Necesitas ayuda con tu pedido?» con WhatsApp y correo, al pie de los dos
correos de preventa (HTML y texto plano).

- **Ficheros:** `src/Modules/OrderPreventa/ContactFooter.php`.
- **De dónde sale el número.** De la option del plugin de terceros Joinchat:
  `get_option( 'joinchat' )['telephone']` y, si no está disponible —plugin desactivado, o sin número
  configurado—, de la constante `FALLBACK_PHONE = '+56944242844'`, documentada como «coincide con el
  número configurado en prod 2026-05-17» (`34-41, 122-134`). Es un dato de contacto publicado al
  cliente que depende de un tercero, sin pantalla propia ni verificación: si el dueño desactiva
  Joinchat, los correos vuelven en silencio a ese número.
- **Deuda menor:** la versión HTML imprime el número formateado («+56 9 4424 2844») y la de texto
  plano imprime la URL larga completa de `wa.me` (`69` frente a `93`).

#### `OrderPreventa\WpCli`

Tres comandos de consola. `wp akibara order-preventa-backfill [--dry-run] [--limit=N]` marca los
pedidos antiguos sin snapshot usando la **misma** regla de estampado que el checkout;
`wp akibara order-preventa-recompute-ready [--order-id=N]` fuerza una pasada del repaso, entera o de
un solo pedido; `wp akibara order-preventa-cumplimiento [--format=…]` imprime la tabla de prometido
contra real, agrupada por editorial cuando tiene política propia y por país cuando hereda, y con el
tamaño de la muestra dicho en voz alta. **Ficheros:** `src/Modules/OrderPreventa/WpCli.php`.

### BackInStock — «Avísame cuando llegue»

#### `BackInStock\BackInStock`

Raíz del módulo. La carpeta tiene 13 ficheros: éste más doce. De esos doce, `register()` conecta
**ocho** llamando a su propio `register()` —`CaptureRestController`, `CaptureForm`,
`SubscriptionNotice`, `StockWatcher`, `NotificationDispatcher`, `UnsubscribeHandler`,
`PrivacyHandler` y `Retention` (`BackInStock.php:70-80`)—; además llama a `Install::maybe_install()`
y registra `InstockEmail` por el filtro `woocommerce_email_classes`. Los dos que no se conectan a
nada son `Subscriber` y `SubscriberRepository`: no escuchan hooks, son el objeto-fila y el acceso a
la tabla. Aporta también la pantalla del panel.
Dos interruptores **independientes** —aceptar altas nuevas y enviar los avisos—, **los dos apagados
por defecto** (`BackInStock.php:10-14`; qué valor tienen hoy en producción no se midió: ver «Qué está
encendido hoy»), y ninguno de los dos borra suscripciones. Hooks: `woocommerce_email_classes` (10), `akibara_loop_waitlist_active` (10) y
`akb_sb_waitlist_active` (10) para encender los botones de las tarjetas y del constructor de packs,
`admin_init` (5) para normalizar el formato antiguo de los interruptores (1/0 → yes/no),
`woocommerce_admin_field_akb_bis_demand` (10) para la tabla de demanda y `litespeed_ucss_whitelist`
(20). Filtros que emite: `akb_bis_capture_enabled` y `akb_bis_dispatch_enabled`.
**Opera el dueño:** *Akibara → Lista de espera*. **Ficheros:**
`src/Modules/BackInStock/BackInStock.php`.
**Deuda de documentación:** su propio docblock (`20-21`) sigue diciendo «3 altas/hora/IP» cuando el
tope real es 12 — y `SubscriptionNotice.php:19-21` justifica su envío síncrono con ese mismo número
caducado.

#### `BackInStock\Install`

Crea y migra la tabla `{prefijo}akb_bis_subscribers`, con clave única `(producto, email)` y única por
token. Auto-migra en cada arranque si la versión guardada difiere, sin depender de reactivar el
plugin, y **sondea la existencia real** de la tabla antes de sellar la versión: sellar a ciegas dejaba
el módulo envenenado para siempre. Option interna: `akb_bis_db_version`.
**Ficheros:** `src/Modules/BackInStock/Install.php`.

#### `BackInStock\Subscriber`

Una fila de la tabla, como objeto, sin dependencias de WordPress. Declara los cuatro estados del ciclo
de vida —suscrito, avisado, dado de baja, fallido— y el predicado «está esperando».
**Ficheros:** `src/Modules/BackInStock/Subscriber.php`.

#### `BackInStock\SubscriberRepository`

**Qué hace.** Todo el acceso a la tabla. Su pieza clave es el **reclamo atómico**: `mark_notified()`
es un `UPDATE` condicional que devuelve verdadero sólo si **esta** llamada afectó la fila (`166-178`).
Ésa, y no la deduplicación de la cola, es la garantía de «como mucho un aviso por suscripción».
`record_failed_attempt()` es un solo `UPDATE` con `CASE` que incrementa los intentos y pasa a estado
terminal al llegar al tope, con guardia para no resucitar a quien se dio de baja en medio
(`212-245`). `reactivate()` devuelve la fila a «suscrito» **con el presupuesto de reintentos a cero**
(`188-201`) — es decir, quien se vuelve a anotar se recupera solo.
**Ficheros:** `src/Modules/BackInStock/SubscriberRepository.php`.
**Deuda menor de documentación.** El comentario que explica por qué `products_with_waiting()` es la
única consulta del fichero **sin tope** —y por qué eso es correcto: el `LIMIT` de `demand_summary()`
sirve para pintar una tabla, y usarlo para decidir a quién se notifica dejaba fuera en silencio al
producto 51 en adelante— está colocado **sobre el método equivocado**. Termina en `364` y lo sigue de
inmediato otro comentario (`365-377`) que describe `count_other_waiting()`; la función que de verdad
explica, `products_with_waiting()`, aparece desnuda en `418`. El mismo patrón se repite en
`InstockEmail.php:38-39`, donde un comentario sobre la URL de baja quedó pegado encima del
constructor. Quien lea el fichero de arriba abajo entiende justo al revés lo que cada método hace.

#### `BackInStock\CaptureForm`

El formulario «Avísame cuando vuelva» en la ficha de un producto agotado. Se pinta **sólo** si la
captura está encendida y el producto está estrictamente agotado —lo reservable se compra, no se
espera—. No mete ningún dato por visitante en el marcado (ni nonce ni marcas de tiempo): la ficha se
sirve cacheada a invitados y un nonce nacería muerto. Es el dueño del ancla `#akb-bis-form` que usan
los tres botones que llevan hasta aquí (filtro `akibara_bis_form_anchor`). Hooks:
`woocommerce_single_product_summary` **prio 35** y `wp_enqueue_scripts` (10).
**Ficheros:** `src/Modules/BackInStock/CaptureForm.php`.

**El campo señuelo no es suyo: es prestado.** Lo pinta `Modules\Forms\Honeypot::field_html()`
(`CaptureForm.php:93`), una utilidad compartida con el formulario de contacto (capítulo 06). El campo
se llama **`akb_hp_url`** (`Honeypot.php:19`) y va dentro de un `<div aria-hidden="true" inert>` con
la etiqueta «Si eres humano, deja este campo vacío». Quien decide que viene relleno es
`Honeypot::tripped( $params )`, y en la puerta pública se comprueba **antes** que cualquier otra
cosa: `CaptureRestController.php:90`, por delante de los guards de email, producto y tope por IP. Ese
es el nombre que hay que buscar cuando un alta se rechaza sin explicación.

#### `BackInStock\CaptureRestController`

**Qué hace.** La puerta por la que el navegador manda el email a la lista de espera. Sin contraseña,
con antispam en capas.

- **Ficheros:** `src/Modules/BackInStock/CaptureRestController.php`.
- **Puerta HTTP:** `POST /wp-json/akibara/v1/back-in-stock`, pública a propósito.
- **Guards, en orden:** campo señuelo (`90`) → interruptor → email no vacío, de **100 bytes** como
  mucho y válido → el producto existe → el producto está estrictamente agotado → tope de altas por
  hora y por IP → alta (`125-160`).
- **Precisión sobre el tope, porque tiene tres letras pequeñas.** Lo aplica
  `Utilities\RateLimit::exceeded( 'bis' )`, con 12 altas por hora (`RateLimit.php:48-49`). Las tres
  cosas que un novato descubre a golpes:
  1. **El dueño está exento.** El método abre con
     `if ( current_user_can( 'manage_woocommerce' ) ) { return false; }` (`76-78`). Quien esté
     logueado como administrador **nunca** toca el tope, así que probarlo exige una ventana de
     incógnito sin sesión iniciada. (La exención se estrechó el 2026-07-28: antes eximía a *cualquier*
     usuario logueado, y con el registro abierto por Google Login eso lo evadía cualquiera.)
  2. **La ventana es de balde fijo, no deslizante.** La hora va dentro de la clave del contador
     (`81-84`), así que se pueden colar hasta **24 altas** repartidas entre dos horas consecutivas
     —el propio fichero lo llama «burst de frontera» y lo acepta a cambio de que contar sea una sola
     escritura atómica—.
  3. **El contador no se puede borrar por nombre para desbloquear a alguien.** La clave es
     `akb_rl_<sha256 de tipo|IP|hora>`, un resumen irreversible: no hay forma de reconstruirla sin
     conocer la IP exacta y la hora exacta. Y ni siquiera vive siempre en la base: con un almacén de
     caché persistente instalado —que es el caso real del sitio, porque LiteSpeed deja su
     `object-cache.php`— el contador vive ahí y no deja fila ninguna (`116-140`).
- **Precisión sobre el largo:** el guard es `strlen( $email ) > 100`, que cuenta **bytes**, mientras
  la columna es `VARCHAR(100)`, que son **caracteres** (`Install.php:144`). Un correo con tildes o
  «ñ» en la parte local puede tener 96 caracteres, 101 bytes, y ser rechazado con «Ingresa un email
  válido» pese a caber de sobra en la columna. El módulo ya tiene la herramienta correcta al lado:
  `Utilities\Text::length()`, que es `mb_strlen` (`src/Utilities/Text.php:25-27`).
- **Detalles:** responde al señuelo con exactamente la misma forma que un alta real; devuelve códigos
  distintos para «está en preventa», «está a pedido» y «está disponible», con el mensaje que
  corresponde; re-activa la fila si existía en estado no-activo; y dispara `akb_bis_subscribed` en
  toda alta y re-alta.

#### `BackInStock\SubscriptionNotice`

El correo inmediato «Te anotamos en la lista de espera de X», con su enlace de baja. Existe porque el
endpoint es público: cualquiera podía anotar el email de otro. Se envía **siempre**, sin depender de
ningún interruptor ni del panel de correos de WooCommerce —es consentimiento, no una etapa operable—
y de forma síncrona dentro de la propia petición del alta. Incluye las cabeceras de baja en un clic
(RFC 8058) que exigen Gmail y Yahoo. Hook: `akb_bis_subscribed` (10). Filtro que emite:
`akb_bis_subscribe_notice_enabled`, pensado para migraciones masivas.
**Ficheros:** `src/Modules/BackInStock/SubscriptionNotice.php`.

#### `BackInStock\StockWatcher`

Detecta que un producto agotado volvió a estar disponible y encola el trabajo de avisar. Escucha
`woocommerce_product_set_stock_status` y `woocommerce_variation_set_stock_status` en **prio 11**
—después del listener de pedidos, que va en 10— y sale barato si nadie espera ese producto. Encola
deduplicando **por argumentos**, no con el flag global de la cola, que habría descartado en silencio
todos los productos menos el primero en un restock masivo. La detección está **siempre** viva: el
interruptor apaga el envío, no la detección. Emite `akb_bis_notify_product`.
**Ficheros:** `src/Modules/BackInStock/StockWatcher.php`.

#### `BackInStock\NotificationDispatcher`

**Qué hace.** El obrero que manda los avisos, en lotes de 20 y con un minuto entre lotes para no
reventar el límite del proveedor de correo.

- **Ficheros:** `src/Modules/BackInStock/NotificationDispatcher.php`.
- **Cuándo actúa:** `akb_bis_notify_product` (10) y `update_option_akb_bis_dispatch_enabled` (10) —al
  **guardar** el interruptor de envío, reencola **todos** los productos con gente esperando, no sólo
  los 50 que pinta la tabla del panel—.
- **Qué hace por lote:** revalida que el producto sigue en stock; si algo impide enviar, reagenda un
  número **acotado** de veces y deja de rebotar. Por suscriptor: reclamo atómico → envío → si falla,
  cuenta el intento y lo devuelve al grupo hasta el tope.
- **Dos relojes distintos para reagendar, y sólo uno está en la tabla de crons.** El presupuesto de
  reintentos es el mismo —cuatro— pero el plazo depende del motivo:
  - Si el **envío está pausado** por el interruptor, o el correo está deshabilitado en WooCommerce,
    se reagenda a `DEFAULT_PAUSE_RETRY_SECONDS`, **900 segundos por defecto** (`54`, `345`).
  - Si lo que pasa es que **las clases de correo de WooCommerce todavía no son cargables**, se
    reagenda a `MAILER_RETRY_SECONDS`, **60 segundos** fijos (`57`, `318-323`), consumiendo el mismo
    presupuesto de cuatro.

  Quien vea en la cola una acción `akb_bis_notify_product` reagendada a +60 s no está viendo un lote
  normal —esos van a +60 s también, pero con suscriptores pendientes—: está viendo un reintento por
  mailer no cargado.
- **Filtros que emite:** `akb_bis_batch_size` (20 por defecto), `akb_bis_throttle_gap_seconds` (60
  por defecto), `akb_bis_pause_retry_seconds` (900 por defecto), y la acción de observabilidad
  `akb_bis_subscribers_ready` —que lleva el conteo, nunca emails ni tokens—.
- **Dependencia de servicio:** la sonda `Services\MailerBoot::ready()` es de la que cuelga el
  reintento cuando las clases de correo de WooCommerce todavía no están cargadas (`104-110`).
- **Trampa conocida:** el freno de 20 por minuto se puede duplicar. `StockWatcher` deduplica con
  `as_has_scheduled_action( ..., array( 'product_id' => $id ), ... )` (`101-112`), pero la cadena que
  el propio despachador agenda lleva un argumento extra, `pause_try` (`356-374`). Como
  `as_has_scheduled_action()` compara los argumentos **exactos**, el vigía no reconoce esa cadena: una
  segunda transición a `instock` del mismo producto mientras el lote está en vuelo encola una
  **segunda** cadena. La garantía de «como mucho un aviso por persona» se conserva —la da el reclamo
  atómico—, pero el freno se pierde: dos cadenas mandan 40 correos por minuto.

#### `BackInStock\InstockEmail`

El correo de aviso de disponibilidad, con portada, precio, botón de compra y enlace de
baja discreto. Diferencia con claridad absoluta los tres estados comerciales (2026-10-17):
- **Disponible:** listo para compra y despacho inmediato.
- **A pedido:** habilitado para reserva con la fecha de corte del ciclo internacional y los días restantes.
- **Preventa:** preventa abierta con fecha estimada de llegada y recordatorio de alza de precio al completarse el stock.

Extiende la clase de correo de la casa para heredar el panel de ajustes y la plantilla
de marca, pero **no** se registra como acción de correo de WooCommerce: lo dispara el obrero
directamente, porque el reclamo atómico ya da la garantía. Asunto sin emoji, por entregabilidad.
**Ficheros:** `src/Modules/BackInStock/InstockEmail.php`,
`templates/emails/customer-bis-instock.php` y su gemela en `plain/`.
**Opera el dueño:** *WooCommerce → Ajustes → Correos → «Akibara · Volvió a stock»*.
Opciones de panel adicionales: `akb_bis_notify_on_backorder` (permite gatillar avisos cuando el producto pasa a reserva 'A pedido').

#### `BackInStock\UnsubscribeHandler`

La baja en un clic desde el correo, por token, siempre viva —un enlace de baja muerto en un correo ya
enviado es incumplimiento—. Con **GET** sólo confirma, porque los escáneres de enlaces corporativos y
el prefetch del buzón hacen GET a todo: si el GET mutara, escanear el correo daría de baja al
suscriptor. Con **POST** da de baja, que es lo que envían los botones nativos de Gmail y Yahoo. La
página nombra el tomo y dice cuántos otros avisos siguen activos para ese correo; un token inválido
recibe exactamente la misma página, sin tocar la base. Hook: `template_redirect` **prio 1**, atendiendo
`?akb_bis_unsub=<token de 64 hex>`. **Ficheros:** `src/Modules/BackInStock/UnsubscribeHandler.php`.

#### `BackInStock\Retention`

Minimización de datos: las suscripciones ya cerradas se borran solas a los 180 días; las que siguen
esperando se conservan, porque esa espera es la señal de qué conviene reponer. Hooks: `init` (10) para
programarse, `akb_bis_cleanup_terminal` (10) para purgar y `before_delete_post` (10) para borrar de
inmediato las filas de un producto eliminado. Filtro: `akb_bis_retention_days` (180 por defecto, mínimo 30).
**Ficheros:** `src/Modules/BackInStock/Retention.php`.

#### `BackInStock\PrivacyHandler`

Conecta la lista de espera con las herramientas nativas de WordPress: exporta cada suscripción de un
email (producto, estado, fechas) y borra **físicamente** todas las de un email. Hooks:
`wp_privacy_personal_data_exporters` (10) y `wp_privacy_personal_data_erasers` (10).
**Opera el dueño:** *Herramientas → Exportar / Borrar datos personales*.
**Ficheros:** `src/Modules/BackInStock/PrivacyHandler.php`.

### Las rutas públicas

#### `PreventasRoute` — la ruta `/a-pedido/`

**Qué hace.** La URL pública que muestra el catálogo filtrado a lo reservable, con su propio título,
texto introductorio, SEO y fecha de actualización.

- **Ficheros:** `src/Modules/PreventasRoute/PreventasRoute.php`.
- **Cuándo actúa:** `init` (10) para las cuatro reglas de URL; `query_vars` (10);
  `template_redirect` **prio 0** (301 de la variable suelta y del path antiguo `/preventas/*`) y
  **prio 1** (302 al catálogo general si la faceta elegida contradice la ruta, en vez de dejar al
  usuario en un 404); `posts_clauses` (20) para el filtro por stock;
  `akb_search_query_vars` (10) para preseleccionar el chip del buscador;
  `woocommerce_before_shop_loop` **prio 3** para el bloque introductorio; y siete filtros de Rank Math
  para título, descripción, canónica, `rel` prev/next, sitemap y datos estructurados propios.
- **Cómo filtra:** `AND akb_sl.stock_status = 'onbackorder'` contra la tabla indexada de WooCommerce
  —no una consulta de metas, que no está indexada— (`297-298`).
- **Qué lee:** las options de texto `akb_copy_preventas_*` y `akb_preventas_guide_post_id`;
  el transient `akb_preventas_updated` (6 h) con la fecha real de última actualización del catálogo.
- **Incoherencia documentada:** el estado `onbackorder` agrupa los **dos** negocios, pero el H1
  («A pedido», `325`), el `<title>` (`335`), la descripción (`551`), el chip preseleccionado
  (`FACET_VALUE = 'a_pedido'`, `59`) y el párrafo introductorio por defecto («ya está publicado»,
  `72`) hablan sólo de «a pedido». Hoy no muerde porque el catálogo no tiene pre-lanzamientos
  configurados; el día que los haya, la página afirmará de ellos algo falso en una superficie
  indexada.

#### `DisponiblesRoute` — la ruta `/disponibles/`

La ruta gemela, con `instock` como filtro (`296-297`) y el mismo esquema de hooks. Su texto está
calibrado contra lo que el dato garantiza: dice «no esperan importación» y **no** «están en nuestra
bodega», porque el estado de stock es un permiso de venta, no una afirmación de posesión física.
Enlaza a `/a-pedido/` como recuperación de quien no encontró su tomo, igual que `/a-pedido/` enlaza
aquí —es la **única** vía interna de descubrimiento de esa ruta—. Options:
`akb_copy_disponibles_intro`, `akb_copy_disponibles_sibling_prefix`, `_sibling_link`; transient
`akb_disponibles_updated`. **Ficheros:** `src/Modules/DisponiblesRoute/DisponiblesRoute.php`.

### Contratos, integraciones, servicios y plantillas

#### `Contracts\EtaProvider`

El contrato mínimo que `OrderPreventa` le pide a quien calcula fechas —`calculate()`,
`resolve_country()` y `get_country_name()`—. Existe para romper la dependencia circular entre los dos
módulos: la implementación es `EstimatedDelivery\DateCalculator`.
**Ficheros:** `src/Contracts/EtaProvider.php`.

#### `Integrations\Bluex\OrderPreventaAdapter`

**Qué hace.** Traduce Blue Express al contrato neutral: le dice al módulo qué estado significa
«entregado al transportista» y de dónde salen los números de seguimiento.

- **Ficheros:** `src/Integrations/Bluex/OrderPreventaAdapter.php`.
- **Cuándo actúa:** `akb_order_preventa_active_order_statuses` (10),
  `akb_order_preventa_carrier_handoff_statuses` (10), `akb_order_preventa_tracking_references` (10),
  `akb_order_preventa_carrier_handoff_alert_message` (10),
  `akb_order_preventa_carrier_handoff_alerted` (10) y `pre_http_request` (10) —que en un sitio local
  **bloquea** las llamadas a la API de Blue que crearían un despacho real—.
- **Hook heredado, fácil de perder:** además escucha la acción
  `akb_order_preventa_verify_bluex_handoff` (constante `LEGACY_HANDOFF_CHECK_HOOK`, `29` y `52`) para
  atender las acciones de Action Scheduler encoladas **antes** de la migración al contrato neutral, y
  respeta la meta heredada `_akb_eta_bluex_handoff_alerted` (`39`, `120-126`) para no duplicar
  alertas en los pedidos anteriores a 1.87.0. Quien retire el adaptador dejaría esas acciones
  huérfanas.
- **De dónde saca el estado:** `woocommerce_correios_settings['noBlueStatus']`, con
  `wc-shipping-progress` por defecto (`171-178`). Y de dónde saca los seguimientos: la función
  `wc_correios_getTrackingCodes()` o, en su defecto, la meta antigua `_correios_tracking_code`
  (`95-100`).

#### `Contracts\CheckoutDataProvider`

El segundo de los dos contratos de `src/Contracts/`, y el que el carrito de bloques consume de
verdad. Declara qué se le puede pedir al área sobre un producto y sobre el carrito entero, sin
obligar al otro plugin a conocer ninguna clase concreta de aquí.
**Ficheros:** `src/Contracts/CheckoutDataProvider.php`.

#### `Services\CheckoutDataService` (la parte de disponibilidad)

La única implementación de ese contrato —literalmente
`final class CheckoutDataService implements CheckoutDataProvider` (`CheckoutDataService.php:31`)—, y
lo que `akibara-cart-checkout-blocks` (capítulo 12) consume: por producto, el estado de stock crudo,
la palabra del estado comercial, si es reservable, la fecha formateada y su marca de tiempo; para el
carrito entero, el resumen agregado más la nota de la tanda del corte **más tardío**. Redacta la
explicación del pedido mixto usando la **misma** palabra que la etiqueta por línea. Lee del catálogo
de hoy: **no** toca ni construye `LineItemSnapshot` —el congelado de la compra ocurre después, en
otro hook y por otra pieza—.
**Ficheros:** `src/Services/CheckoutDataService.php`.

#### `Services\AkibaraEmail`

El esqueleto del que heredan los tres correos del área. Aporta `init_form_fields()` —la pantalla de
*WooCommerce → Ajustes → Correos* con activar/desactivar, asunto, encabezado y texto adicional— y el
par de cabeceras `List-Unsubscribe` / `List-Unsubscribe-Post` de la RFC 8058 cuando el correo declara
una URL de baja (`88-97`). Aquí vive un hecho de negocio que no está en ninguna clase del módulo:
**los tres correos nacen activados**, porque el campo `enabled` tiene `'default' => 'yes'` (`129-134`).
**Ficheros:** `src/Services/AkibaraEmail.php`.

#### `Services\MailerBoot`

La sonda `ready()`, que devuelve verdadero si `WC_Email` existe y, si no, fuerza el arranque del
mailer de WooCommerce para provocar el `include` que el autoloader no sabe hacer (`57-71`). De ella
cuelga el reintento del despachador de la lista de espera. Vive fuera de `AkibaraEmail` por una razón
medida: un guardia que comprueba si `WC_Email` se puede cargar no puede vivir en una clase que empieza
por `extends WC_Email` —llamarlo obligaba a autocargar ese fichero, y el `extends` reventaba antes de
ejecutar una línea—. **Ficheros:** `src/Services/MailerBoot.php`.

#### `Services\OneClickPage`

La cáscara de marca de las páginas de baja por enlace de correo —fondo oscuro, botón rojo, cero
assets externos— que envuelve los flujos «GET confirma → POST muta» de la RFC 8058. Nació el
2026-09-01 al deduplicar la página de `UnsubscribeHandler` y su copia textual en `SeriesFollow`.
**Ficheros:** `src/Services/OneClickPage.php`.

#### `Utilities\StoreTime`

**Qué hace.** Responde a una sola pregunta —«¿en qué zona horaria vive esta tienda?»— y es la fuente
única de las siete veces que este capítulo dice «en hora de Chile». Trece llamadas del área pasan por
aquí.

- **Ficheros:** `src/Utilities/StoreTime.php`.
- **Qué devuelve:** `wp_timezone()`, es decir la zona configurada en WordPress. **La zona real no se
  cambia en ninguna pantalla de Akibara**: se cambia en *Ajustes → Generales → Zona horaria* de
  WordPress, y cambiarla mueve todas las fechas de este capítulo a la vez.
- **Por qué existe.** El idioma `function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone(
  'UTC' )` estaba copiado diez veces por el plugin y las copias ya no decían lo mismo: unas caían a
  UTC, otras a `null`, y `ReminderEmail` llamaba `wp_timezone()` sin guarda —fatal en cualquier
  arranque sin WordPress cargado, que es exactamente el de la suite de pruebas—.
- **Por qué el respaldo a UTC no es cosmético**, en palabras del propio fichero (`StoreTime.php:12-15`):
  el servidor corre en UTC y la tienda en `America/Santiago`, así que después de las 20:00 de Chile
  la fecha comparada sería la del día siguiente y **una preventa caducaría cuatro horas antes de
  tiempo**. Cuando WordPress no está cargado, UTC es la única respuesta honesta.

#### Las seis plantillas de correo

`templates/emails/customer-preventa-ready.php`, `customer-preventa-reminder.php`,
`customer-bis-instock.php` y sus tres gemelas en `templates/emails/plain/`. La de «preventa lista»
lleva saludo, tabla de lo que se esperaba **con la cantidad neta de reembolsos**, bloque de retiro o
de despacho con la ventana editable, botón «Ver mi pedido» y pie de contacto. La de «recordatorio»
tiene tres cuerpos distintos que nombran **dos** fechas separadas —la de la editorial y la de llegada
al cliente— y, si otro tomo llega más tarde, también la del pedido completo. Todas re-emiten los
hooks `woocommerce_email_header` y `woocommerce_email_footer` para no romper a los plugins que los
escuchan.

**Trampa conocida en las versiones de texto plano.** Tres defectos de la misma familia: (1) el nombre
del producto y el del cliente se imprimen con `esc_html()` dentro de un cuerpo `text/plain`
(`plain/customer-preventa-reminder.php:141`, `plain/customer-preventa-ready.php:36`,
`plain/customer-bis-instock.php:27`), así que un tomo llamado «Jojo's Bizarre Adventure» se lee como
«Jojo&#039;s»; (2) el «texto adicional» que el dueño escribe en el panel se imprime con
`esc_html( wp_strip_all_tags( wptexturize( … ) ) )` en dos de las tres plantillas
(`plain/customer-preventa-ready.php:61-65` y `plain/customer-bis-instock.php:49-51`), lo que llena de
entidades lo único de esos correos que él escribe a mano; y (3) la tercera plantilla ya tiene el
arreglo correcto escrito al lado, con dos líneas de comentario que explican exactamente por qué
`esc_html()` está mal ahí (`plain/customer-preventa-reminder.php:155-160`).

---

## Lo programado

Cuatro trabajos con reloj y seis que dispara un hecho. Las horas marcadas «heredada» no están
escritas en el código: salieron del instante en que el evento se agendó por primera vez, y cambian
tras una reinstalación (cómo leer la de hoy: ver «Qué está encendido hoy»). Los seis trabajos de
Action Scheduler se ven en *WooCommerce → Estado → Acciones programadas* filtrando por su grupo, que
va en la tabla.

**Cada cuánto avanza esto de verdad, que es la pregunta que importa.** El único empuje del sitio es
una tarea del panel de Hostinger que llama a `wp-cron.php` **cada 5 minutos**: `DISABLE_WP_CRON` está
encendido, así que el tráfico de clientes no empuja nada. Action Scheduler tiene su propio trabajo de
drenaje, `action_scheduler_run_queue`, **agendado cada minuto** — pero agendado no es ejecutado: sin
nadie que empuje, ese minuto no llega. En la práctica, **la cola avanza cada 5 minutos**. Por eso
ningún trabajo «inmediato» de esta tabla es instantáneo: se encola al instante y se ejecuta en el
siguiente empuje.

| Trabajo | Mecanismo | Frecuencia y hora (Chile) | Quién lo programa | Qué hace | Si corre dos veces |
| --- | --- | --- | --- | --- | --- |
| `akb_preventa_vencida_sweep` | WP-Cron | Diaria, ~02:37 (heredada) | `PreventaVencidaCron::maybe_schedule` en `init` | Toca hasta 50 fichas de preventa vencida para refrescar índice y caché | Inocuo: el testigo `_akb_preventa_vencida_swept` hace que la segunda pasada encuentre otro lote o ninguno |
| `akb_order_preventa_recompute_ready` | WP-Cron | Diaria, ~19:30 (heredada) | `ReadyToShipCron::maybe_schedule` en `init` | Marca listos los pedidos completos; deja notas internas de atraso | Inocuo: la marca `ready_to_ship = yes` es inmutable y `_akb_eta_stale_noted` evita la nota repetida |
| `akb_order_preventa_t7_reminder_scan` | WP-Cron | Semanal, **domingo 03:00** (fija en el código) | `RemindersCron::maybe_schedule` en `init`; `Settings::on_t7_enabled_change` al guardar el interruptor | Dispara el aviso «tu pedido se acerca» | Inocuo: el correo escribe `_akb_eta_t7_reminder_sent` **antes** de enviar |
| `akb_bis_cleanup_terminal` | WP-Cron | Diaria, ~20:30 (heredada) | `Retention::maybe_schedule` en `init` | Borra suscripciones cerradas de más de 180 días | Inocuo: la segunda pasada no encuentra nada que borrar |
| `akb_order_preventa_recompute_for_product` | Action Scheduler · grupo `akibara-order-preventa` | A +5 s de que un tomo vuelva a `instock` | `StockChangeListener::on_stock_status_change` | Revisa los pedidos que esperaban ese tomo | Inocuo: deduplica por argumentos y reclama las acciones hermanas del lote |
| `akb_order_preventa_dispatch_ready_email` | Action Scheduler · grupo `akibara-order-preventa` | Inmediata al marcar listo; reintento a +305 s | `ReadyEmailDispatcher::schedule_ready_email_dispatch` | Envía «tu pedido ya llegó» | Seguro: `GET_LOCK` por pedido + marca releída sin caché antes de entregar |
| `akb_order_preventa_release_date_changed_batch` | Action Scheduler · grupo `akibara-order-preventa` | Inmediata al cambiar la fecha; reintento a +1 min si pierde el candado | `ReleaseDateChangeListener::on_release_date_changed` | Recalcula y notifica los pedidos afectados | Seguro: candado global en `wp_options` + firma `vieja__nueva` por pedido |
| `akb_order_preventa_verify_carrier_handoff` | Action Scheduler · grupo `akibara-order-preventa` | A +2 h de la entrega al transportista | `CarrierHandoffMonitor::on_status_changed` | Comprueba que haya número de seguimiento y, si no, alerta | Inocuo: `_akb_eta_carrier_handoff_alerted` deja una sola alerta |
| `akb_order_preventa_verify_bluex_handoff` | Action Scheduler · grupo `akibara-order-preventa` | Sólo acciones heredadas ya encoladas | Nadie lo agenda hoy; el adaptador de Blue Express lo atiende | Lo mismo que el anterior, para pedidos anteriores a 1.87.0 | Inocuo: comparte la deduplicación, además de la meta heredada |
| `akb_bis_notify_product` | Action Scheduler · grupo `akibara-bis` | Inmediata al reponer; lotes de 20 cada 60 s; con el envío pausado, 4 reintentos cada 900 s; si lo que falla es que el mailer todavía no carga, esos mismos 4 reintentos van a **+60 s** | `StockWatcher::enqueue` y `NotificationDispatcher::schedule_next` | Avisa a la lista de espera de ese producto | **Parcialmente seguro:** ningún suscriptor recibe dos correos (reclamo atómico), pero dos cadenas en paralelo duplican el ritmo de envío |

![Cuándo corre cada cosa, en hora de Chile](diagramas/04-business-disponibilidad-preventa-y-avisos-cron.mmd)

---

## Casos de uso

### 1. Un cliente compra un tomo «a pedido» (el caso normal de la tienda)

**Empieza cuando** alguien finaliza la compra con un producto `onbackorder` de tipo `auto`.

1. En la ficha ya leyó «Fecha estimada de llegada: 27 may – 1 jun» y un botón que decía «A pedido»
   (`BannerRenderer::render_single`, `PreventaUX::change_add_to_cart_text`).
2. Bajo el botón vio las cinco promesas de «Cómo funciona este pedido» (`BannerRenderer::render_promesas`).
3. Al crear cada línea del pedido se congela el snapshot: era reservable, tipo `auto`, país Argentina,
   rango 27 may – 1 jun (`LineItemSnapshot::on_create_line_item`).
4. Al crear el pedido se escribe el resumen: tiene espera, una línea pendiente, «listo» = no
   (`OrderTagging::apply_tags`).
5. El estimado de arribo lo calcula y persiste `SummaryRecalculator::recompute_for_order`.
6. En el correo de confirmación de WooCommerce, bajo la tabla, aparece el cartel «Tu pedido incluye
   productos a pedido. Estimamos que el último producto llega el 1 de junio», y bajo la línea del
   producto «📦 A pedido · Llega entre el 27 de mayo y el 1 de junio · Argentina»
   (`CustomerVisibility`).

**Bifurcaciones.** Si el producto no era reservable no se escribe ningún snapshot y el pedido no entra
en este circuito. Si la editorial trabaja por tanda, la fecha se cuenta desde el próximo cierre y el
cartel añade «Sale en el pedido que cierra el 5 de septiembre; desde ahí viaja 2–3 semanas». Si está
pausada, no hay fecha: «Te avisamos en cuanto tengamos fecha». Y si ese correo de confirmación se
dispara desde una pantalla de wp-admin, la línea por producto no aparece —sí el cartel—.

**Correos.** El de confirmación nativo de WooCommerce, enriquecido, inmediato.

![Del cartel de la ficha a las metas del pedido](diagramas/04-business-disponibilidad-preventa-y-avisos-caso-compra-a-pedido.mmd)

### 2. Un pedido mixto: dos tomos disponibles, uno a pedido y una preventa con fecha

**Empieza cuando** el carrito tiene productos de los tres estados.

1. En el carrito, el resumen agrega las fechas: manda el tomo que **más tarda**, y los disponibles no
   cuentan (`BannerRenderer::aggregate` vía `CheckoutDataService::summarize_cart`).
2. Si alguno de los reservables no tiene fecha, **todo** el carrito pasa a «Te avisamos en cuanto
   tengamos fechas»: es la regla conservadora de `aggregate()`.
3. La nota de la tanda que se muestra es la del corte **más tardío** del carrito, no la de la primera
   línea.
4. Al comprar, sólo las dos líneas reservables llevan snapshot; las disponibles no llevan ninguna meta
   nueva.
5. `_akb_eta_pending_count` queda en 2 y el estimado del pedido es el máximo de las dos.
6. En la página del pedido, cada línea reservable lleva su **propia** palabra —«A pedido» una,
   «Preventa» la otra— y su propio rango.
7. El pedido **no** se puede completar mientras falte cualquiera de los dos
   (`CompletionPolicy::can_complete`).

**Bifurcaciones.** Si llega uno de los dos, el estimado baja al que queda y el contador pasa a 1. Si
se reembolsa por completo una de las líneas reservables, esa deja de contar.

**Correos.** Confirmación nativa con el cartel «Tu pedido incluye preventa» — la palabra sube a
«preventa» porque hay al menos una preventa de verdad.

### 3. El tomo llega a bodega y el cliente recibe «tu pedido ya llegó»

**Empieza cuando** el dueño pone el producto en stock: ficha, edición en lote, importación o WP-CLI.

1. WooCommerce dispara el cambio de stock; en prioridad 10 lo recoge el listener de pedidos y agenda
   una revisión a +5 s para **ese** producto (`StockChangeListener::on_stock_status_change`).
2. En prioridad 11 lo recoge la lista de espera, que sale barato si nadie esperaba ese producto
   (`BackInStock\StockWatcher`).
3. El obrero reclama las acciones hermanas del mismo lote y recorre en **una** pasada los pedidos
   activos con «listo = no» (`StockChangeListener::process_product_change`).
4. Por cada pedido que contiene el producto se recalcula: si todavía falta algo, se actualizan el
   contador y el estimado; si no falta nada, se marca `ready_to_ship = yes`, se sella la fecha y se
   deja nota (`ReadyToShipCron::process_order`).
5. Ese cambio dispara `akb_order_preventa_marked_ready` y el despachador agenda una acción por pedido.
6. El obrero toma un bloqueo de base de datos por pedido, arma el mensaje entero, comprueba que la
   marca quedó **de verdad** en la base y sólo entonces entrega el correo (`ReadyToShipEmail::trigger`).
7. En la página del pedido y en «mis pedidos», el cartel pasa a verde: «Tu pedido está listo para
   despachar. Sale en los próximos 1-3 días hábiles».

**Bifurcaciones.** Si el stock vuelve a caer entre el encolado y el obrero, no pasa nada: la marca es
inmutable. Si el proveedor de correo rechaza el envío, se quita la marca y se reintenta hasta tres
veces; al agotarlas, la nota pide reenviarlo a mano. Si el pedido ya no tiene nada vigente porque se
reembolsó todo, se marca listo pero **no** se manda el correo. Y si alguien mueve el estado de un
pedido que quedó listo hace más días que el tope configurado, ese re-enganche concreto no reenvía y
deja una nota —el tope vive sólo ahí—.

**Correos.** «Tu pedido {n} ya llegó a Akibara», por la cola. **Cuánto tarda:** entre uno y unos diez
minutos desde el cambio de stock, no menos. La cadena tiene tres eslabones y el reloj de los tres es
el mismo: el recálculo se encola a +5 s, el correo se encola en cuanto el pedido queda marcado listo,
y **cada uno de esos pasos espera al siguiente empuje de `wp-cron.php`, que llega cada 5 minutos**.
Un correo que salga «en menos de un minuto» sería casualidad de haber caído justo antes de un empuje.

![De la reposición al correo](diagramas/04-business-disponibilidad-preventa-y-avisos-caso-ya-llego.mmd)

### 4. El aviso «tu pedido se acerca», una vez por pedido

**Empieza cuando** corre el cron semanal del domingo a las 03:00 de Chile.

1. Si el interruptor está apagado, no se hace nada —y el evento ni siquiera está programado—.
2. Se recorren por páginas los pedidos en estado de espera cuyo «listo» no es `yes`, **incluidos los
   que no tienen la meta**.
3. Por pedido: debe tener espera, líneas vigentes, no estar listo, no tener ya la marca de enviado y
   tener un estimado vigente.
4. Deben quedar entre 1 y 10 días para la fecha, y la espera original debe haber sido de al menos 14
   días.
5. Se dispara la acción; WooCommerce arranca el mailer y la re-emite con sufijo; el correo pone la
   marca **antes** de enviar y la nota **después**.

**Bifurcaciones.** Si el transporte rechaza el envío, la marca se revierte y el pedido vuelve al
conjunto elegible, hasta tres veces. Un pedido sin fecha vigente no recibe este aviso: no hay una
semana que contar. Y si el dueño estrecha las ventanas o sube la duración mínima, el aviso deja de
salir sin que nada lo indique — mientras la ficha lo sigue prometiendo.

**Correos.** «Tu pedido {n} se acerca», la madrugada del domingo; llega el lunes por la mañana.

### 5. La editorial posterga la fecha de salida de un tomo

**Empieza cuando** el dueño cambia la fecha en la pestaña «Disponibilidad» de la ficha o en «Por
llegar».

1. Se guarda la nueva fecha y se dispara `akb_eta_release_date_changed` con la vieja y la nueva.
2. El listener **no** hace el trabajo ahí: agenda una acción en la cola.
3. El obrero clasifica el cambio: retraso de 7 días o más → «postergación».
4. Toma el candado global en `wp_options`; si lo pierde, reintenta en un minuto.
5. Recorre los pedidos en espera que contienen ese producto y que **todavía** lo están esperando: a
   quien ya lo tiene en bodega no se le escribe.
6. Por pedido: recalcula el estimado **siempre**, registra la firma del cambio una sola vez, deja nota
   interna y dispara la acción del correo.
7. El correo comprueba el tope de frecuencia —una vez cada 14 días por defecto—: si está dentro de la
   ventana, deja nota y **no** envía.
8. El cuerpo nombra el tomo que cambió, sus dos fechas —la de la editorial y la de llegada al
   cliente— y, si otro tomo llega más tarde, también la del pedido completo.

**Bifurcaciones.** Retraso de menos de 7 días: no se avisa ni se recalcula. Adelanto: sólo si el dueño
encendió «Notificar también los adelantos». Fecha nueva que ya pasó: no es un cambio accionable.

**Correos.** «Actualización de tu preventa {n}: nueva fecha» o «Actualización de tu pedido {n}: cambio
de fecha». Ese asunto **no** se puede cambiar desde ninguna pantalla: sólo por el filtro
`woocommerce_email_subject_akb_preventa_reminder`.

![Las cuatro ramas del cambio de fecha](diagramas/04-business-disponibilidad-preventa-y-avisos-caso-cambio-de-fecha.mmd)

### 6. La editorial anuncia la primera fecha de un tomo que no la tenía

1. Igual que el caso anterior hasta la clasificación, que aquí da «anunciada» y **no** pasa por el
   umbral de días.
2. Antes de mandar nada se comprueba que, tras el recálculo, el pedido tiene una fecha que el cliente
   pueda ver; si no —falta el país o sus días de tránsito—, se suprime el correo con nota operativa.
3. La **palabra** del asunto sigue a cómo se compró la línea: si el cliente compró una preventa sin
   fecha, «¡Ya tenemos fecha para tu preventa!»; si compró «a pedido» y ya tenía un rango prometido,
   «Tu pedido: la editorial anunció fecha de salida», porque para ese cliente es un **cambio**, no una
   buena noticia.
4. La pantalla «Por llegar» dice de antemano cuántos correos van a salir, con qué asunto cada uno, y a
   cuántos no les llegará por el tope de frecuencia.

**Bifurcación.** El tope de este modo lee las **dos** marcas —la de postergación y la suya— pero
escribe sólo la suya: una buena noticia nunca puede suprimir un retraso posterior.

### 7. El dueño retira la fecha de un tomo

1. Desde «Por llegar», vaciar la fecha deja el tomo como preventa **sin** fecha confirmada (tipo
   `tba`) y lo dice en pantalla.
2. El listener clasifica «retirada»: recalcula los pedidos afectados y deja nota interna, pero **no**
   manda ningún correo (decisión sellada del dueño, 2026-07-17).
3. En el pedido, la línea de ese tomo pasa a «Sin fecha confirmada» y el estimado baja al de otra línea
   o desaparece.
4. La pantalla avisa: «Si borras la fecha no sale ningún correo… si quieres avisarle, escríbele tú».

**Bifurcación que conviene conocer.** Si en vez de vaciar la fecha el dueño cambia el **tipo** del
producto a «A pedido (automático)», el efecto no es el mismo: el listener escribe igualmente la nota
«Fecha retirada», pero `SummaryRecalculator` ve el tipo actual `auto`, borra la promesa vigente de la
línea y vuelve al snapshot de compra. La línea y el cartel quedan coherentes entre sí, pero el cliente
puede volver a ver una ventana **anterior** a la última que se le prometió por correo, mientras el
historial interno dice que la fecha se retiró.

**Correos.** Ninguno.

### 8. Una preventa caduca sola y pasa a ser «a pedido»

**Empieza cuando** llega el día siguiente a la fecha de lanzamiento.

1. Nadie escribe nada: la ficha, el badge, el botón, el esquema de Google y el panel ya dicen «A
   pedido» en cuanto alguien los lee, porque el estado se **deduce**.
2. Pero dos superficies quedaron horneadas con el estado viejo: la fila de faceta del buscador y el
   HTML cacheado de la ficha.
3. El barrido diario encuentra hasta 50 fichas vencidas sin barrer y les hace una escritura **vacía**,
   que dispara el reindexado y la purga sin tocar ninguna meta ni la fecha de modificación.
4. Cada ficha barrida se marca con un testigo para que el cursor avance; el testigo se olvida si la
   fecha vuelve a cambiar **por el panel**.

**Bifurcaciones.** El día del lanzamiento todavía cuenta como preventa. En los pedidos ya hechos no
cambia nada: la palabra sale del snapshot de compra.

**Correos.** Ninguno.

### 9. El dueño reembolsa una línea en espera

1. Al terminar el reembolso se recalcula el pedido en el acto (`RefundListener` →
   `ReadyToShipCron::process_order`).
2. La línea reembolsada del todo deja de contar como vigente y el estimado del pedido baja.
3. Si ya no queda **nada** vigente, el pedido se marca listo con la nota «Ya no queda nada pendiente
   tras el reembolso — no se envía aviso de llegada».
4. Los correos que salgan después imprimen la cantidad **neta**: un cliente al que se le devolvió 1 de
   3 ejemplares ya no lee «× 3».

**Bifurcaciones.** Un pedido cuyas esperas se reembolsaron todas **no** entra en el informe de
cumplimiento: una devolución no es una promesa cumplida. Y ojo: esa nota del paso 3 se escribe
también cuando no hubo ningún reembolso, si las líneas perdieron su meta de snapshot por otra vía.

**Correos.** Ninguno.

### 10. El dueño intenta completar un pedido que todavía espera mercadería

1. Antes de guardar se detecta que el pedido tiene espera pendiente y se sustituye el estado por el
   anterior, así que WooCommerce ni siquiera publica el «completado» a transportistas ni a plugins de
   reseñas.
2. Se deja nota en el pedido y se dispara el aviso de bloqueo.
3. El aviso se apunta en la meta del **usuario** que lo provocó y se pinta tras el redirect, nombrando
   los tomos que faltan.
4. Si un tercero lo completó por una vía que elude el guardado normal, la red de seguridad lo devuelve
   a su estado anterior.

**Bifurcación.** Un pedido con espera pero con todo ya llegado —o ya marcado listo— sí se puede
completar.

**Correos.** Ninguno. (De hecho, en estos pedidos también se suprime el correo nativo «pedido
completado» de WooCommerce.)

### 11. El pedido se entrega a Blue Express y el transportista no pone el seguimiento

1. Se agenda una comprobación a las dos horas (`CarrierHandoffMonitor::on_status_changed`).
2. Al ejecutarse se mira si hay números de seguimiento: el adaptador los aporta desde la función de
   Blue Express o desde su meta antigua `_correios_tracking_code`.
3. Si no hay ninguno, se deja **una** alerta en el pedido, con la advertencia de revisar el portal del
   transportista antes de reintentar, para no duplicar el despacho.
4. En paralelo, `ShippableStamp` ya selló la fecha real de despacho, que es la que usa el informe de
   cumplimiento.

**Bifurcación.** Sólo se vigilan pedidos con espera vigente que necesitan transportista: el retiro en
tienda queda fuera.

**Correos.** Ninguno al cliente; la alerta es una nota interna.

### 12. Un cliente pide que le avisen cuando un tomo agotado vuelva

1. El formulario sólo existe si la captura está encendida y el producto está estrictamente agotado: lo
   reservable se compra, no se espera.
2. El navegador manda el email a la puerta pública.
3. Guards en orden: interruptor, email válido de 100 bytes como mucho, producto existente, producto
   agotado, tope de 12 altas por hora y por IP.
4. Si ya existía una fila para ese email y producto en estado no activo, se re-activa con el
   presupuesto de reintentos a cero; si no, se inserta con un token nuevo.
5. Sale **en el acto** el correo «Te anotamos en la lista de espera de X», con el enlace de baja: el
   endpoint es público y cualquiera podría anotar el email de otro.

**Bifurcaciones.** Si el producto es reservable, se responde con su propio código y mensaje. Si el
campo señuelo viene relleno, la respuesta es idéntica a la de un alta real. Si el email ya estaba
esperando, «Este email ya está en la lista de espera».

**Correos.** «Te anotamos en la lista de espera de {producto}», inmediato, dentro de la propia
petición.

![Alta en la lista de espera y aviso de reposición](diagramas/04-business-disponibilidad-preventa-y-avisos-caso-lista-de-espera.mmd)

### 13. El tomo agotado vuelve a stock y salen los avisos de la lista de espera

1. El vigía comprueba barato si alguien espera ese producto y encola el trabajo.
2. El obrero revalida que sigue en stock y dispara la acción de observabilidad con el conteo.
3. Si el envío está pausado, o el correo está deshabilitado en WooCommerce, o el mailer todavía no
   carga: se reagenda un número acotado de veces y se deja de rebotar; la cola se retoma al **guardar**
   el interruptor.
4. Por cada suscriptor del lote —20 como máximo—: reclamo atómico «suscrito → avisado»; sólo quien gana
   ese reclamo recibe el correo.
5. Si quedan suscriptores, se agenda el siguiente lote a +60 s.

**Bifurcaciones.** Si el envío falla, se cuenta el intento y la fila vuelve al grupo hasta tres veces;
a la tercera queda en «fallido» y se ve en la columna «Fallidos» del panel. Si el stock volvió a caer
entre el encolado y el obrero, no se manda nada. Y si el obrero muere **entre** el reclamo y el envío
—un timeout, un fatal fuera del `try`—, la fila queda en «avisado» sin que se haya mandado nada: no
hay reenvío manual como sí lo hay en los correos de preventa, aunque el cliente que se vuelva a
anotar se recupera solo.

**Correos.** «{producto} está de vuelta en Akibara», en lotes de 20 cada minuto.

### 14. El cliente se da de baja de la lista de espera

1. Con **GET** sólo se muestra una página de confirmación con un botón, porque los escáneres de
   enlaces corporativos y el prefetch del buzón hacen GET a todo.
2. La página nombra el tomo del que se va a dar de baja.
3. Con **POST** se da de baja de verdad: es lo que envía el botón nativo de Gmail o Yahoo.
4. La página de «Listo» dice, si aplica, «Tus avisos de otros 2 productos siguen activos».
5. Un token inválido recibe exactamente la misma página, sin tocar la base.

**Bifurcación.** La fila no se borra: pasa a «dado de baja» y el barrido de retención la borra a los
180 días.

**Correos.** Ninguno.

### 15. El dueño abre «Por llegar» y cambia la fecha de un tomo

1. La pantalla recorre todos los pedidos activos con espera y agrupa las líneas pendientes **por
   tomo**, que es como llega la mercadería.
2. Ordena los urgentes primero: fecha vencida y, a igualdad, más pedidos.
3. Debajo, el bloque «¿Estás cumpliendo los plazos?» con las cifras del informe de cumplimiento,
   calculadas con la misma regla que el comando de consola.
4. Al pulsar «Cambiar fecha» se abre una sub-vista que dice exactamente qué correo va a salir, a
   cuántas personas, con qué asunto, y a cuántas no les va a llegar por el tope de frecuencia.
5. Al guardar, se escribe por el CRUD de WooCommerce, se purga la página en LiteSpeed y se dispara el
   hook real de cambio de fecha.

**Bifurcaciones.** Una fecha que ya pasó se rechaza. Una fecha con un año de más (2206) se rechaza con
la misma regla que la ficha. Un tomo en la papelera se rechaza: el listener mandaría «fecha anunciada»
por algo que no se vende.

**Correos.** Los de «cambio de fecha» o «fecha anunciada», según el caso, con la cola, en minutos.

### 16. El dueño configura un corte mensual para una editorial

1. Elige el modo —hereda · continuo · por tanda · pausada— y, si es tanda, el día de cierre (1-28) y
   los días corridos mínimo y máximo desde el cierre.
2. Al guardar, cada fila pasa por el **mismo** validador que la pantalla del término; una fila inválida
   no aborta a las demás y el aviso la nombra.
3. Antes de escribir se hace *diff*: una fila sin cambios no toca la base. Lo anterior se guarda como
   respaldo y aparece «Deshacer el último guardado».
4. La columna «Lo que ve el cliente» traduce la configuración **guardada** a la frase y al rango
   exactos que saldrán en la ficha.
5. A partir de ahí, la ficha dice «Sale en el pedido que cierra el 5 de septiembre; desde ahí viaja 3–4
   semanas» y, en los siete días previos, «Quedan 3 días para entrar».

**Bifurcaciones.** El día máximo es 28 a propósito: un corte «el 30» no existe en febrero. Una tanda a
medias se rechaza entera. La editorial con política propia gana sobre la del país.
**Y una trampa:** si el dueño hace esto desde la pantalla del **país** en vez de la tabla de cortes o
la pantalla de editoriales, el motivo del rechazo no se pinta en ninguna parte.

**Correos.** Ninguno.

### 17. Un pedido lleva demasiado esperando y el dueño recibe un aviso interno

1. Se mide contra **dos** anclas: los días de gracia después de la fecha prometida vigente (30 por
   defecto) y el tope desde la **primera** fecha prometida al comprar (120 por defecto), por si la
   editorial pospone en bucle y la promesa nunca vence.
2. Si el tomo no tenía fecha al comprar, la gracia se cuenta desde la compra.
3. Se deja **una** nota interna con el motivo exacto; una nota por «tope» no tapa después la del
   incumplimiento real de la promesa.
4. Segunda pasada: los pedidos ya marcados listos que llevan **4 días hábiles o más** sin despachar
   reciben otra nota, citando la misma promesa de despacho que leyó el cliente.

**Bifurcación.** La nota no cambia el estado del pedido ni manda nada al cliente: el dueño lo trata
caso por caso.

**Correos.** Ninguno.

### 18. Un visitante entra por Google a `/a-pedido/` o a `/disponibles/`

1. La regla de URL sirve el catálogo de WooCommerce conservando la URL bonita.
2. La consulta se filtra por el estado de stock usando la tabla indexada de WooCommerce.
3. El chip de disponibilidad del buscador aparece marcado como pista visual.
4. Se pinta el bloque introductorio editable, el enlace a la guía, el enlace a la ruta gemela y la
   fecha real de última actualización del catálogo.
5. Si Rank Math está activo, el título, la descripción, la canónica, los `rel` prev/next y los datos
   estructurados son propios de la ruta y no del catálogo general.

**Bifurcaciones.** En las páginas 2 en adelante, o con cualquier filtro puesto, el bloque introductorio
no se pinta. Si el visitante marca la disponibilidad contraria a la de la ruta, se le manda al catálogo
general con su selección intacta en vez de dejarlo en un 404. El path antiguo `/preventas/` redirige
con 301 conservando la página.

**Correos.** Ninguno.

![La vida de un pedido que espera](diagramas/04-business-disponibilidad-preventa-y-avisos-estados-pedido.mmd)

---

## Reglas de negocio

Cada fila apunta al fichero y a la línea del corte de código donde la regla vive. Lo que no aparece aquí y
parezca una regla, no lo es.

| # | Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
| --- | --- | --- | --- | --- |
| 1 | El estado comercial se **deriva** del stock crudo más dos metas, nunca se guarda: `instock` → Disponible; `onbackorder` con lanzamiento pendiente → Preventa; `onbackorder` sin él → A pedido; cualquier otra cosa → Agotado | `Stock/Availability.php:149-162` | no | — |
| 2 | «Lanzamiento pendiente» son **dos** casos, no uno: el tipo `tba` lo es **siempre**, sin mirar ninguna fecha (es la primera condición del método), y el tipo `release_date` lo es mientras su fecha sea hoy o futura | `Stock/Availability.php:255-263` | no | — |
| 3 | El día del lanzamiento todavía cuenta como preventa; degrada al día siguiente, en hora de Chile | `Stock/Availability.php:276-282` | no | — |
| 4 | Un tipo desconocido o ausente cae a `auto`, que es «a pedido»: es el default del negocio | `Stock/Availability.php:123-125` | no | — |
| 5 | Una fecha de lanzamiento no puede estar a más de 5 años vista y tiene que ser un día real del calendario; no hay cota inferior, a propósito | `Stock/Availability.php:301, 340-350` | no | — |
| 6 | El barrido de preventas vencidas procesa como mucho 50 fichas al día y **no** muta ninguna meta ni la fecha de modificación | `Stock/PreventaVencidaCron.php:45, 143-152, 171-194` | no | — |
| 7 | Sin stock `onbackorder` no hay fecha estimada que dar | `EstimatedDelivery/DateCalculator.php:149-151` | no | — |
| 8 | Con el tipo «sin promesa de fecha» no se muestra ninguna fecha | `EstimatedDelivery/DateCalculator.php:156-158` | sí | Ficha → pestaña «Disponibilidad» → cuarto botón, «Sin promesa de fecha (no mostrar plazos en la ficha)» |
| 9 | Una editorial «pausada» no promete fecha aunque su país tenga plazos | `EstimatedDelivery/DateCalculator.php:165-167` | sí | *Productos → Marcas*, o la tabla de cortes |
| 10 | Precedencia del plazo: ficha > editorial > país > días de tránsito del país | `EstimatedDelivery/DateCalculator.php:176-230`, `PedidoPolicy.php:169-218` | sí | Ficha, término de editorial, término de país, tabla de cortes |
| 11 | El plazo manual de la ficha sólo aplica a los tipos de preventa; el «a pedido» se rige por su editorial o su país | `EstimatedDelivery/DateCalculator.php:176-178` | no | — |
| 12 | Un plazo manual a medias, fuera de 1–365, o con máximo menor que el mínimo, se descarta **completo** | `EstimatedDelivery/DateCalculator.php:392-405` | no | — |
| 13 | En modo tanda el plazo corre desde el **cierre**, no desde la compra, y en días corridos | `EstimatedDelivery/DateCalculator.php:242-257` | sí | Días mín/máx desde el corte |
| 14 | Una fecha de lanzamiento ya pasada ancla el cálculo a **hoy**, no a la fecha vencida | `EstimatedDelivery/DateCalculator.php:467-470` | no | — |
| 15 | Días de tránsito por defecto: Argentina 12–15, España 17–20; un país nuevo sin configurar no muestra fecha | `EstimatedDelivery/Settings.php:49-52` | sí | *Ajustes → Fechas estimadas*, o el término de `pa_pais` |
| 16 | Días de tránsito válidos: `1 ≤ mín ≤ máx ≤ 365`; cualquier otra cosa se rechaza conservando lo anterior | `EstimatedDelivery/Settings.php:183-185` | no | — |
| 17 | Por defecto las fechas saltan sábados y domingos | `EstimatedDelivery/Settings.php:174-176` | sí | *Ajustes → Fechas estimadas → «Saltar fines de semana»* |
| 18 | El día de cierre de una tanda va del 1 al 28, para que exista también en febrero | `EstimatedDelivery/PedidoPolicy.php:99, 500-517` | no | — |
| 19 | Quien compra **el propio día** del cierre entra en esa tanda | `EstimatedDelivery/PedidoPolicy.php:245-257` | no | — |
| 20 | La cuenta atrás del cierre sólo aparece en los 7 días previos | `EstimatedDelivery/PedidoPolicy.php:109, 325-327` | no | — |
| 21 | Una tanda a medias se rechaza **entera** y no se escribe nada | `EstimatedDelivery/PedidoPolicy.php:500-517` | no | — |
| 22 | El modo «heredado» borra las cuatro metas de política; «continuo» y «pausada» sólo escriben el modo | `EstimatedDelivery/PedidoPolicy.php:490-537` | no | — |
| 23 | Las cinco promesas de la ficha con fecha, incluida «si se atrasa más de 60 días, cancelas y te devolvemos el 100 %» | `EstimatedDelivery/BannerRenderer.php:205-222` | **no** | Sin pantalla: exige desplegar código |
| 24 | La rama sin fecha no promete el aviso de una semana antes ni los 60 días; promete la cláusula de agotado | `EstimatedDelivery/BannerRenderer.php:206-213` | no | — |
| 25 | La fecha del carrito es la del tomo que más tarda; los disponibles no cuentan y una línea sin fecha contagia a todo el carrito | `EstimatedDelivery/BannerRenderer.php:367-428` | no | — |
| 26 | A Google Shopping se le manda el borde **alto** del rango; «sin fecha» no toca nada | `EstimatedDelivery/GlaBridge.php:98-117` | no | — |
| 27 | Una línea de pedido sólo lleva snapshot si el producto era `onbackorder` **en el momento** de la compra | `OrderPreventa/LineItemSnapshot.php:76-84` | no | — |
| 28 | La palabra de una línea sale del snapshot de compra, nunca del estado de hoy | `OrderPreventa/PreventaItems.php:262-269` | sí (el texto) | *Akibara → Textos* |
| 29 | El vocabulario «a pedido / preventa» está escrito en **tres** sitios que no se hablan: la option de *Textos*, la constante `Availability::LABELS` y los literales de `ProductFaq` | `Stock/Availability.php:167, 189, 207`, `ProductFaq/ProductFaq.php:108` | sólo el primero | *Akibara → Textos* |
| 30 | «Ya llegó» se decide primero por la bandera inmutable del pedido y sólo después por el stock; un producto en la papelera **no** ha llegado | `OrderPreventa/PreventaItems.php:169-186` | no | — |
| 31 | Los correos y la página del pedido muestran la cantidad **neta** de reembolsos | `OrderPreventa/PreventaItems.php:102-107` | no | — |
| 32 | El estimado se calcula sólo con las líneas pendientes, y la contribución de cada una la decide el **tipo actual**; para `auto` y `disabled` nunca se recalcula en vivo | `OrderPreventa/SummaryRecalculator.php:186-234` | no | — |
| 33 | «Sin fecha» se representa **borrando** la meta, nunca guardando 0 | `OrderPreventa/SummaryRecalculator.php:111-136` | no | — |
| 34 | Si todo lo vigente ya llegó pero el cron aún no marcó el pedido, la última fecha se **conserva** | `OrderPreventa/SummaryRecalculator.php:126-130` | no | — |
| 35 | Una vez marcado «listo para despachar», el pedido **no** vuelve atrás | `OrderPreventa/ReadyToShipCron.php:6-7, 184-187` | no | — |
| 36 | Un pedido al que se le reembolsó todo lo que esperaba se marca listo en el acto y **no** recibe aviso de llegada | `OrderPreventa/ReadyToShipCron.php:150-162` | no | — |
| 37 | La nota «revisar manualmente» se mide contra la fecha prometida vigente (30 días de gracia) y contra la primera prometida al comprar (tope de 120); sin fecha al comprar, desde la compra | `OrderPreventa/ReadyToShipCron.php:226-287` | sí | *Ajustes → Pedidos atrasados* |
| 38 | Un pedido ya listo que lleva **4 días hábiles o más** sin despachar recibe una nota de prioridad que cita la misma ventana de despacho que leyó el cliente | `OrderPreventa/ReadyToShipCron.php:315-339` | sí | *Ajustes → Pedidos atrasados* |
| 39 | El aviso previo se manda **una** sola vez por pedido, sólo si quedan entre 1 y 10 días y la espera original fue de al menos 14 | `OrderPreventa/RemindersCron.php` (`maybe_dispatch()`, `within_t7_window()`) | sí | *Ajustes → Aviso previo a la llegada* |
| 40 | El repaso del aviso previo corre los domingos a las 03:00 de Chile | `OrderPreventa/RemindersCron.php` (`maybe_schedule()`, `next_sunday_03_ts()`) | no | — |
| 41 | Sólo se avisa de un cambio de fecha con 7 días o más de retraso; los adelantos sólo con el interruptor; una fecha ya pasada no es accionable | `OrderPreventa/ReleaseDateChangeListener.php` (`classify()`) | sí | *Ajustes → Cambio de fecha* |
| 42 | Retirar la fecha **no** manda correo: sólo recalcula y deja nota | `OrderPreventa/ReleaseDateChangeListener.php` (`event_note()`, rama `retracted`) | no | — |
| 43 | «Ya tenemos fecha» no sale si tras el recálculo el cliente no puede ver ninguna fecha | `OrderPreventa/ReleaseDateChangeListener.php` (`process_order()`, guarda `$announce_ok`) | no | — |
| 44 | El mismo cambio no se registra ni se notifica dos veces (firma `vieja__nueva`), pero el recálculo corre **siempre** | `OrderPreventa/ReleaseDateChangeListener.php` (`process_order()`, CSV de firmas) | no | — |
| 45 | Un pedido no recibe dos avisos de fecha en menos de 14 días; el segundo queda anotado pero no se envía | `OrderPreventa/ReminderEmail.php` (`dispatch_date_email()` decide y anota; `within_notify_window()` es la cuenta) | sí | *Ajustes → Cambio de fecha → «Ventana entre avisos»* |
| 46 | El tope de frecuencia es **asimétrico**: la postergación lee y escribe sólo su marca; el anuncio lee las dos y escribe sólo la suya | `OrderPreventa/ReminderEmail.php` (`trigger_date_changed()`, `trigger_date_announced()`) | no | — |
| 47 | El asunto y el encabezado editables en el panel pertenecen **sólo** al modo «aviso previo»; los modos de fecha usan sus cuatro asuntos propios | `OrderPreventa/ReminderEmail.php` (constantes `SUBJECT_*`, `get_default_subject()`, `get_default_heading()`, `form_field_copy()`) | sólo por filtro | `woocommerce_email_subject_akb_preventa_reminder` / `…_heading_…` (sin pantalla) |
| 48 | Todos los correos escriben la marca de «enviado» **antes** del envío y la nota afirmativa después, sólo si el transporte no lo rechazó | `OrderPreventa/ReminderEmail.php` (`trigger_t7()`, `dispatch_date_email()`), `ReadyToShipEmail.php` (`trigger()`) | no | — |
| 49 | Ante un rechazo real del proveedor, la marca se revierte y el pedido vuelve al conjunto elegible, hasta tres intentos | `OrderPreventa/SendFailureRearm.php:52-97` | no | — |
| 50 | El tope de antigüedad para el aviso «ya llegó» (7 días) se aplica **sólo** en el re-enganche por cambio de estado; ni el camino principal, ni el reenvío manual, ni el reintento lo consultan | `OrderPreventa/ReadyEmailDispatcher.php:207-231` frente a `71-72, 137-148, 345-363` | sí | *Ajustes → Pedidos atrasados*; con 0 se desactiva |
| 51 | En pedidos con espera pendiente se suprime el correo nativo «pedido completado» de WooCommerce | `OrderPreventa/ReadyEmailDispatcher.php` (`maybe_suppress_native_completed_email()`) | no | — |
| 52 | Un pedido con espera pendiente no se puede completar: el estado se restaura antes de guardar | `OrderPreventa/CompletionPolicy.php:80-107, 156-162` | sólo por filtro | `akb_order_preventa_can_complete_order` (sin pantalla) |
| 53 | La fecha real de despacho se sella **una** sola vez, al primer cruce a «completado» o al estado de entrega al transportista | `OrderPreventa/ShippableStamp.php` (`stamp()`) | no | — |
| 54 | Qué estado cuenta como «entregado al transportista» lo decide la configuración de Blue Express, no una constante | `OrderPreventa/ShippableStamp.php` (`estados_despachables()`), `Integrations/Bluex/OrderPreventaAdapter.php:171-178` | sí, fuera de Akibara | Pantalla de integración de Blue Express |
| 55 | El cumplimiento se mide por **día** en Chile y contra el borde cruzado, no contra el centro; cancelados, reembolsados y fallidos no cuentan | `OrderPreventa/PromiseAudit.php:93-152`, `PromiseWindow.php:46-63` | no | — |
| 56 | La ventana de despacho prometida sale de un solo sitio para las seis superficies que la citan | `OrderPreventa/ShipPromise.php:34-45` | sí | *Akibara → Textos → Promesas de la tienda* |
| 57 | La nota de precio «al llegar el stock sube a $X» sólo se emite si el descuento **no** supera el umbral de oferta real, el mismo que usa el badge del listado | `OrderPreventa/PreventaUX.php:192-197` | sólo por filtro | `akibara_loop_sale_badge_min_pct` (sin pantalla) |
| 58 | El número de WhatsApp de los correos de preventa sale de la option de Joinchat, con un número de respaldo escrito en el código | `OrderPreventa/ContactFooter.php:34-41, 122-134` | sí, fuera de Akibara | Ajustes del plugin Joinchat |
| 59 | La lista de espera sólo acepta productos **estrictamente agotados** | `BackInStock/CaptureRestController.php:143-155`, `CaptureForm.php:75-76` | no | — |
| 60 | El email de la lista de espera debe caber en 100 **bytes** (la columna es de 100 caracteres) | `BackInStock/CaptureRestController.php:131-136`, `Install.php:144` | no | — |
| 61 | Las altas se limitan a 12 por hora y por IP, **salvo** para quien tenga la capacidad `manage_woocommerce`, que está exento; y la ventana es de balde fijo, no deslizante, así que se pueden colar hasta 24 entre dos horas consecutivas | `Utilities/RateLimit.php:48-49, 66-72, 76-78, 83-85` | no | — |
| 62 | Toda alta y toda re-alta dispara un correo inmediato al titular con su enlace de baja, y ese correo **no** se apaga desde el panel: es consentimiento | `BackInStock/SubscriptionNotice.php:1-24, 42-44` | sólo por filtro | `akb_bis_subscribe_notice_enabled` (sin pantalla) |
| 63 | El aviso de reposición se envía como mucho **una** vez por suscripción, garantizado por un reclamo atómico en la base | `BackInStock/SubscriberRepository.php:166-178` | no | — |
| 64 | Tres fallos de envío dejan la suscripción en «fallido»; volver a anotarse la reactiva con el presupuesto completo | `BackInStock/SubscriberRepository.php:188-201, 212-245` | no | — |
| 65 | La baja en un clic muta **sólo** con POST; el GET únicamente confirma | `BackInStock/UnsubscribeHandler.php:84-94` | no | — |
| 66 | Las suscripciones cerradas se borran a los 180 días; las que siguen esperando se conservan | `BackInStock/Retention.php:26, 57-69` | sólo por filtro | `akb_bis_retention_days` (sin pantalla; mínimo 30) |
| 67 | Los avisos salen en lotes de 20 con 60 s entre lotes; con el envío pausado se reintenta 4 veces cada 900 s —o cada 60 s si lo que falla es que el mailer no carga— y luego se espera al guardado del ajuste | `BackInStock/NotificationDispatcher.php:48-68, 54, 57, 291-348` | sólo por filtro | `akb_bis_batch_size`, `akb_bis_throttle_gap_seconds`, `akb_bis_pause_retry_seconds` |
| 68 | Los tres correos del área nacen **activados** | `Services/AkibaraEmail.php:129-134` | sí | *WooCommerce → Ajustes → Correos* |
| 69 | `/a-pedido/` filtra por `onbackorder` y `/disponibles/` por `instock`, siempre contra la tabla indexada de WooCommerce | `PreventasRoute/PreventasRoute.php:297-298`, `DisponiblesRoute/DisponiblesRoute.php:296-297` | no | — |
| 70 | El bloque introductorio de las rutas sólo se pinta en la página 1 y sin ningún filtro en la URL | `PreventasRoute/PreventasRoute.php:483-487` | sí (el texto) | *Akibara → Textos* |

---

## Datos que deja

### Options (configuración en `wp_options`)

| Clave | Qué es | Valores y default | Dónde se edita |
| --- | --- | --- | --- |
| `akibara_eda_business_days_only` | Saltar sábados y domingos al contar | `yes` \| `no` (yes) | *Ajustes → Fechas estimadas* |
| `akibara_eda_google_sin_promesa` | Qué le dice el feed a Google de un «a pedido» con «Sin promesa de fecha» (1.116.36, R8) | `out_of_stock` \| `next_cycle` \| `leave` (out_of_stock); `autoload=no`, sólo la lee el job de GLA | ídem |
| `akb_preventa_t7_enabled` | Interruptor del aviso previo | `yes` \| `no` (yes) | *Ajustes → Aviso previo* |
| `akb_preventa_t7_window_max_days` | Cuántos días antes empieza la ventana | 1–30 (10) | ídem |
| `akb_preventa_t7_window_min_days` | Cuántos días antes se cierra | 0–14 (1) | ídem |
| `akb_preventa_t7_lead_days` | Duración mínima de la espera para merecer aviso | 0–90 (14) | ídem |
| `akb_preventa_date_change_threshold_days` | Retraso a partir del cual se avisa | 1–90 (7) | *Ajustes → Cambio de fecha* |
| `akb_preventa_date_change_notify_window_days` | Ventana entre avisos de fecha | 1–90 (14) | ídem |
| `akb_preventa_date_change_notify_advance` | Avisar también los adelantos | `yes` \| `no` (no) | ídem |
| `akb_preventa_ready_email_max_age_days` | No reenviar avisos de pedidos viejos | 0–365 (7; 0 desactiva) | *Ajustes → Pedidos atrasados* |
| `akb_preventa_stale_pending_days` | Gracia tras la fecha prometida | 7–180 (30) | ídem |
| `akb_preventa_stale_max_age_days` | Tope desde la primera fecha prometida | 30–365 (120) | ídem |
| `akb_preventa_stale_post_ready_days` | Días hábiles listo y sin despachar | 1–30 (4) | ídem |
| `akb_bis_capture_enabled` | Aceptar altas en la lista de espera | `yes` \| `no` (no) | *Akibara → Lista de espera* |
| `akb_bis_dispatch_enabled` | Enviar los avisos de reposición | `yes` \| `no` (no) | ídem |
| `akb_copy_eta_tba`, `_cart_ready`, `_single_ready`, `_cart_preventa`, `_single_preventa`, `_single_pedido`, `_noun_preventa`, `_noun_pedido`, `_overdue`, `_item_arrived` | Todo el vocabulario visible del área | textos | *Akibara → Textos* |
| `akb_copy_promise_ready_window` | «1-3 días hábiles» | texto | *Akibara → Textos → Promesas* |
| `akb_copy_preventas_intro`, `akb_preventas_guide_post_id`, `akb_copy_preventas_guide_prefix`, `_guide_link`, `_sibling_prefix`, `_sibling_link` | Textos de `/a-pedido/` | textos e id | *Akibara → Textos* |
| `akb_copy_disponibles_intro`, `akb_copy_disponibles_sibling_prefix`, `_sibling_link` | Textos de `/disponibles/` | textos | *Akibara → Textos* |
| `akb_cortes_backup` | Respaldo de las 5 últimas tandas de cambios de cortes | array | Sin pantalla; lo alimenta «Deshacer» |
| `_akb_preventa_datechg_lock` | Candado global de la pasada de cambio de fecha | marca de tiempo, caduca a los 15 min | Sin pantalla |
| `akb_bis_db_version` | Versión del esquema de la tabla de la lista de espera | `'2.0.0'` | Sin pantalla |
| `akibara_eda_pa_pais_migrated_at` | Marca de la migración 1.14.0 | ISO + versión | Sin pantalla |
| `akibara_eda_lt_ar_min` / `_max` / `akibara_eda_lt_es_min` / `_max` | Options anteriores a 1.14.0 | enteros | Sólo se leen en la migración |
| `woocommerce_correios_settings['noBlueStatus']` | **De un tercero.** Qué estado significa «entregado al transportista» | slug de estado | Pantalla de Blue Express |
| `joinchat['telephone']` | **De un tercero.** WhatsApp del pie de los correos de preventa | número E.164 | Ajustes de Joinchat |

### Metas de producto (`postmeta`)

| Clave | Qué es | Quién la escribe |
| --- | --- | --- |
| `_akb_eta_type` | `auto` \| `release_date` \| `tba` \| `disabled` | `ProductDataPanel` |
| `_akb_eta_release_date` | Fecha `Y-m-d` en que publica la editorial | `ProductDataPanel`, `PreventasScreen` |
| `_akb_eta_manual_min_dias` / `_akb_eta_manual_max_dias` | Plazo personalizado de esa ficha, 1–365 | `ProductDataPanel` |
| `_akb_preventa_vencida_swept` | Testigo de «ya se le refrescaron las superficies horneadas» | `PreventaVencidaCron` |

### Metas de término (`termmeta`)

| Clave | Qué es | En qué taxonomía |
| --- | --- | --- |
| `_akb_min_days` / `_akb_max_days` | Días de tránsito del país | `pa_pais` |
| `_akb_pedido_modo` | `heredado` \| `continuo` \| `tanda` \| `pausada` | `product_brand` y `pa_pais` |
| `_akb_tanda_dia` | Día del mes en que cierra la tanda, 1–28 | ídem |
| `_akb_tanda_min_dias` / `_akb_tanda_max_dias` | Días corridos entre el cierre y la llegada | ídem |

### Metas de línea de pedido (`orderitemmeta`)

| Clave | Qué es |
| --- | --- |
| `_akb_eta_was_preventa` | `yes` si esta línea se compró estando el producto reservable |
| `_akb_eta_type_at_purchase` | El tipo al comprar; decide la **palabra** |
| `_akb_eta_min_at_purchase` / `_akb_eta_max_at_purchase` | Rango prometido al comprar |
| `_akb_eta_country_at_purchase` / `_akb_eta_country_name_at_purchase` | País de origen, slug y nombre visible |
| `_akb_eta_is_tba_at_purchase` | `yes`/`no`: si al comprar no había fecha |
| `_akb_eta_min_current` / `_akb_eta_max_current` | Promesa **vigente** tras un recálculo; un 0 explícito significa «se retiró la fecha», ausente significa «sigue valiendo la de compra» |

### Metas de pedido (`ordermeta`)

| Clave | Qué es |
| --- | --- |
| `_akb_eta_has_preventa` | `yes`/`no`: el pedido tiene al menos una línea en espera |
| `_akb_eta_ready_to_ship` | `yes`/`no`: todo lo que faltaba está en bodega. En `yes` **no vuelve atrás** |
| `_akb_eta_ready_date` | Cuándo el cron vio todo de vuelta en stock |
| `_akb_eta_shippable_at` | Primera vez que el pedido entró en un estado despachable de verdad |
| `_akb_eta_summary_max` | Estimado vigente del último arribo. Si no hay fecha, **se borra** |
| `_akb_eta_pending_count` | Cuántas líneas siguen sin llegar |
| `_akb_eta_stale_noted` | `promesa` \| `tope` \| `yes` (heredado): por qué se dejó la nota «revisar» |
| `_akb_eta_shipped_stale_noted` | «Ya avisé de que está listo y sin despachar» |
| `_akb_eta_t7_reminder_sent` / `_attempts` | Marca e intentos del aviso previo |
| `_akb_eta_ready_email_sent` / `_attempts` | Marca e intentos del aviso «ya llegó» |
| `_akb_eta_ready_email_too_old_noted` | Ya se dejó la nota de aviso no enviado por antigüedad |
| `_akb_eta_date_change_sigs` | Firmas `vieja__nueva` ya registradas |
| `_akb_eta_date_change_last_notified_at` / `_send_attempts` | Tope de frecuencia del aviso de postergación |
| `_akb_eta_date_announced_last_notified_at` / `_send_attempts` | Tope de frecuencia del aviso de fecha anunciada |
| `_akb_eta_carrier_handoff_alerted` | Ya se avisó de que falta el número de seguimiento |
| `_akb_eta_bluex_handoff_alerted` | **Heredada.** Lo mismo, escrito antes del contrato neutral |
| `_correios_tracking_code` | **De un tercero.** Código de seguimiento antiguo de Blue Express |

### Metas de usuario y tablas

| Tipo | Clave | Qué es |
| --- | --- | --- |
| `usermeta` | `_akb_preventa_completion_blocked` | Pedidos bloqueados pendientes de avisar a esa persona, con los tomos que faltan |
| tabla | `{prefijo}akb_bis_subscribers` | `id`, `product_id`, `email`, `user_id`, `token`, `status`, `notify_attempts`, `created_at`, `notified_at`. Clave única `(product_id, email)` y única por `token`; `email` es `VARCHAR(100)` |

### Transients (datos temporales)

| Clave | Qué guarda | Vida |
| --- | --- | --- |
| `akibara_eda_save_errors_{user_id}` | Errores de validación del guardado de días de tránsito | 60 s |
| `akibara_eda_pais_term_errors_{user_id}` | Días inválidos al editar un país | 60 s |
| `akb_pedido_aviso_{user_id}` | Motivo del rechazo de una tanda inválida | 60 s |
| `akb_cortes_notice_{user_id}` | Resultado del guardado o del deshacer de la tabla de cortes | 60 s |
| `akb_preventas_updated` | Fecha real de última actualización del catálogo reservable | 6 h |
| `akb_disponibles_updated` | Lo mismo para `/disponibles/` | 6 h |
| `akb_rl_<sha256>` | El contador antispam de la lista de espera: uno por IP y por hora. **Es el único dato de esta tabla que genera el cliente**, no el dueño; nace en cada alta. El nombre es un resumen irreversible de tipo + IP + hora, así que no se puede borrar por nombre para desbloquear a nadie. Y con un almacén de caché persistente instalado —el caso real del sitio— el contador vive en la caché y no deja fila ninguna | 1 h |

---

## Glosario del capítulo

- **Action Scheduler.** La cola de trabajos que trae WooCommerce: tablas propias, estados (pendiente,
  en curso, completado, fallido), argumentos y grupos. Lo que no puede perderse va aquí.
- **Acción (hook).** Punto del programa donde otro trozo de código dice «cuando pase X, avísame».
- **`onbackorder`.** El estado de stock crudo de WooCommerce que significa «no hay, pero se puede
  comprar igual». En Akibara agrupa «a pedido» y «preventa».
- **Cadena de precedencia.** El orden en que se busca el plazo: ficha, editorial, país, días de
  tránsito. Gana el primero que exista.
- **Corte / tanda.** El día del mes en que se cierra el pedido a una editorial. Los pedidos se juntan
  hasta ese día y el plazo corre **desde** ahí.
- **Cron.** Una tarea que se ejecuta sola cada cierto tiempo. En este sitio la empuja una tarea del
  panel de Hostinger cada 5 minutos, no el tráfico de clientes.
- **Deduplicar por argumentos.** Preguntarle a la cola si ya hay un trabajo pendiente con
  **exactamente** estos datos, en vez de usar su marca global, que ignora los datos y colapsaría un
  restock masivo a un solo producto.
- **Fail-closed.** Ante un valor que no se entiende, elegir la opción más conservadora. Aquí: un
  estado de stock desconocido se trata como «Agotado».
- **Filtro (hook).** Punto del programa donde otro trozo de código dice «cuando vayas a usar el valor
  X, déjame cambiarlo antes».
- **`GET_LOCK`.** Un cerrojo con nombre que ofrece MySQL, la base de datos del sitio. El primero que
  lo pide lo tiene; los demás **esperan** su turno, y se suelta solo si el proceso que lo tenía se
  muere. Es distinto del **reclamo atómico**, que no hace esperar a nadie: ahí el que pierde
  simplemente no hace el trabajo y sigue.
- **`pa_pais`.** El atributo de producto «País». WooCommerce antepone `pa_` a todos los atributos de
  producto, así que el prefijo no significa nada más. Se edita en *Productos → Atributos → País →
  Configurar términos*.
- **`product_brand`.** La taxonomía «Marcas» de WooCommerce, en *Productos → Marcas*. En Akibara una
  «marca» es siempre una **editorial**.
- **Prioridad.** Cuando varios trozos de código escuchan el mismo aviso, el número de prioridad
  decide el orden: 5 corre antes que 10, y 10 antes que 31. Por eso «prio 25» pone el cartel de fecha
  encima del botón de comprar y «prio 31» pone las promesas debajo, y por eso el atajo de pedidos
  (prio 10) corre siempre antes que el vigía de la lista de espera (prio 11).
- **Idempotente.** Que hacerlo dos veces produce el mismo resultado que hacerlo una.
- **Meta.** Un dato pegado a algo concreto: a un producto (`postmeta`), a una etiqueta o categoría
  (`termmeta`), a un pedido (`ordermeta`), a una **línea** de pedido (`orderitemmeta`) o a una persona
  (`usermeta`).
- **Nonce.** Una ficha de un solo uso que WordPress mete en los formularios para comprobar que el
  envío viene de donde dice. No sirve en páginas cacheadas para invitados: nacería caducada.
- **Option.** Una fila de configuración guardada en la base de datos con un nombre. Si tiene pantalla,
  el dueño la edita sin tocar código.
- **Reclamo atómico.** Una escritura condicional en la base que sólo una ejecución puede ganar. Es lo
  que garantiza que nadie reciba dos veces el mismo aviso, aunque dos obreros corran a la vez.
- **REST / endpoint.** Una puerta por la que el navegador del cliente habla con el servidor sin
  recargar la página.
- **Reservable.** Todo lo que está en `onbackorder`: se puede comprar aunque no haya existencias.
- **RFC 8058.** El estándar de la baja en un clic desde el buzón. Exige un par de cabeceras y que el
  botón use POST; por eso el GET del enlace sólo confirma.
- **Señuelo (honeypot).** Un campo invisible del formulario que una persona nunca rellena. Si viene
  relleno, es un robot — y se le responde exactamente lo mismo que a un alta real.
- **Snapshot.** La foto congelada de lo que la ficha prometía en el instante de la compra, guardada en
  la propia línea del pedido. **En este capítulo la palabra significa siempre esto y nada más.** Al
  código congelado de producción sobre el que está escrito el capítulo lo llamamos «el corte», nunca
  «el snapshot».
- **Termmeta.** Un dato pegado a un término de taxonomía: aquí, a una editorial (`product_brand`) o a
  un país (`pa_pais`).
- **Transient.** Un dato temporal con fecha de caducidad. Se usa para que un aviso sobreviva al
  redirect del guardado.
