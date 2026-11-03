# Glosario

Todas las palabras técnicas y de negocio que aparecen en esta documentación, explicadas para alguien que llega hoy y no conoce ni WordPress, ni WooCommerce, ni la tienda.
Entre paréntesis, al final de cada línea, los capítulos donde el término se usa de verdad.

**332 términos**, en orden alfabético; al final, los que empiezan por símbolo o número.


## A

- **A pedido.** Un tomo que la editorial sí puede mandar pero que no está en bodega: se compra ahora, se encarga y llega en semanas. Lleva precio de reserva (lista menos 5 %).
- **Abilities API.** La pieza de WordPress que registra las abilities y las publica por su propia API web. En este sitio viene dentro de WooCommerce.  ·  _(cap. 14)_
- **ability.** Una operación que WordPress publica con nombre, descripción, esquema de entrada, una función que comprueba permisos y otra que hace el trabajo, para que un asistente automático la pueda ejecutar. entrada, sus permisos y sus anotaciones.  ·  _(cap. 14, 15)_
- **Ability / Abilities API.** Mecanismo de WordPress para publicar una operación con nombre, esquema de entrada y permiso, de modo que un programa externo pueda invocarla.  ·  _(cap. 18)_
- **acción.** (hook) — Punto del programa que avisa de que algo pasó. Quien se engancha no devuelve nada; sólo hace su trabajo. hora. cuenta después del hecho.  ·  _(cap. 04, 12, 14, 16, 17)_
- **ACK.** La confirmación de que una tarea se completó. Sólo cierra la fila si nadie pidió una revisión nueva mientras tanto.  ·  _(cap. 08)_
- **Acreditar.** Sumar puntos a la cuenta de un cliente por una compra.  ·  _(cap. 10)_
- **Action (acción).** Un aviso que WordPress lanza en un momento concreto («se guardó un pedido») para que quien quiera haga algo. Quien escucha no devuelve nada: actúa. Ver Hook.
- **Action Scheduler.** Una cola de trabajos que trae WooCommerce, con su propia tabla (`wp_actionscheduler_actions`). Es más robusta que WP-Cron, pero sus tareas **no** se ven con las herramientas de cron de WordPress. la ejecuta cuando el cron la empuja. Una acción puede estar *pendiente*, *en curso*, *completada* o *fallida*, y esa diferencia importa: preguntar «¿hay alguna?» puede contar la que se está ejecutando ahora mismo. en curso, completado, fallido), argumentos y grupos. Lo que no puede perderse va aquí. WordPress para tareas encoladas. Guarda una cola de acciones en la base de datos, con reintento y reanudación. Aquí corre las dos tareas diarias. en la base de datos y lo ejecuta con reintentos. de datos y la ejecuta cuando le toca. con estado, argumentos y grupo. Es donde va todo lo que no puede perderse. asíncronas y recurrentes, y **no reintenta un fatal**. Cada acción puede llevar un **grupo**, que es la etiqueta por la que se cancelan en bloque; una acción agendada sin grupo no se encuentra cancelando por grupo. copias instaladas: la de WooCommerce y la de Rank Math.  ·  _(cap. 02, 03, 04, 07, 08, 10, 11, 14, 15, 16, 17, 18, 19)_
- **Adjunto (*attachment*).** La forma que tiene WordPress de guardar un fichero subido. Tiene su propia ficha en la Biblioteca de Medios y puede colgar de otra ficha (aquí, del producto).  ·  _(cap. 11)_
- **`admin-ajax.php`.** La puerta antigua de WordPress para peticiones de fondo desde el navegador. Importa porque `is_admin()` devuelve verdadero ahí, aunque el visitante no sea administrador.  ·  _(cap. 14)_
- **AJAX.** Petición que el navegador hace en segundo plano, sin recargar la página. El cajón del carrito se llena así.  ·  _(cap. 05)_
- **`alloptions`.** El paquete de options marcadas como autoload que WordPress carga de un golpe **en todas las peticiones**, no sólo en las del escritorio. Por eso engordarlo cuesta caro en la web pública. una sola consulta por petición.  ·  _(cap. 02, 17)_
- **Ámbito (scope).** A qué corresponde una tarea de la deuda: `live` (un cambio normal), `baseline` (el manifiesto de un rebuild) o `repair` (lo que cambió mientras el rebuild construía).  ·  _(cap. 08)_
- **`apply_filters` / `add_filter`.** El par de «tengo este valor, corríjanlo si saben más» / «yo lo corrijo». Sí devuelve valor.  ·  _(cap. 19)_
- **Archive.** La página de listado que WordPress genera sola para una etiqueta organizada. En Akibara, `/serie/<slug>/` y `/marca/<slug>/` son archives.  ·  _(cap. 03)_
- **Archivo (de taxonomía).** Una página que lista todos los productos de un término: `/serie/one-piece/`, `/editorial/ivrea-argentina/`, `/product-tag/oferta/`.  ·  _(cap. 09)_
- **Argumentos.** Los datos que viajan con un trabajo (por ejemplo `{order_id: 4821}`). Son lo que distingue dos trabajos del mismo tipo.  ·  _(cap. 16)_
- **Arriendo.** (*lease*): un permiso con fecha de caducidad. Aquí, el derecho exclusivo a ejecutar las tareas del upgrade durante 600 segundos; si quien lo tiene se muere, al vencer otro puede robarlo. tomó puede confirmar la operación; cualquier intento anterior queda invalidado. Es lo que abre `PudoMutationGuard::begin_selection()` antes de preguntarle un precio al transportista, y lo que impide que una respuesta vieja que llega tarde pise a una nueva.  ·  _(cap. 02, 05, 08)_
- **Asíncrono.** Un trabajo que se pide «para lo antes posible», sin hora fija. En Action Scheduler se encola con `as_enqueue_async_action`.  ·  _(cap. 16)_
- **Autoload.** La marca de una option que indica si WordPress la carga en **todas** las peticiones junto con las demás. Encendida ahorra una consulta pero engorda cada petición; apagada hace falta una consulta aparte para leerla. en memoria al principio de cada petición, en un solo viaje a la base, en vez de pedirla suelta cuando se necesite. capítulo se guardan **sin** autoload: sólo se leen cuando hacen falta. (encendida) o sólo cuando alguien la pide (apagada).  ·  _(cap. 02, 08, 10, 15, 17)_
- **Autoloader.** El mecanismo que carga el fichero de una clase la primera vez que alguien la nombra. Si no está registrado todavía, `class_exists()` responde `false` aunque el fichero exista.  ·  _(cap. 02, 19)_
- **Autoridad / foto autorizada.** La lectura única y validada, por petición, de las trece options que cambian lo que el buscador sirve. Si se cierra, los tres servicios se apagan.  ·  _(cap. 09)_
- **Autoritativa.** Se dice de la foto cuando el buscador se fía de ella para publicar precios. Si un valor cae fuera de su dominio cerrado, deja de serlo y el buscador deja de publicar.  ·  _(cap. 17)_
- **AVIF.** Un formato de imagen moderno y liviano, que WhatsApp, X y Gmail **no** saben pintar. De ahí la hermana JPG. clientes de correo como Gmail todavía no lo pintan. hermana JPG.  ·  _(cap. 07, 14, 18)_

## B

- **Backorder (`onbackorder`).** El estado de stock de WooCommerce que Akibara usa para «a pedido» y para «preventa». Por eso el estado comercial se guarda aparte: `onbackorder` solo no distingue uno de otro.
- **Banda de series.** La tira de series relacionadas que el buscador pinta encima de los resultados.  ·  _(cap. 19)_
- **Barra lateral.** La columna de facetas a un lado de la rejilla. El tema abre el hueco; el buscador la imprime dentro.  ·  _(cap. 19)_
- **Barrera pre-escritura.** Apuntar la deuda con unos segundos de retraso, porque el hook corre antes de que WooCommerce haya guardado de verdad.  ·  _(cap. 08)_
- **Barrido.** Una pasada que recorre un conjunto (pedidos, productos, filas) aplicando una regla.  ·  _(cap. 16)_
- **Base puntuable.** La parte del pedido sobre la que se calculan puntos — líneas de producto con IVA, tras descuentos, menos reembolsos de mercadería y menos el canje todavía aplicado. El despacho queda fuera.  ·  _(cap. 10)_
- **bloque.** Una pieza de interfaz que se inserta en el contenido de una página desde el editor de WordPress. En el carrito y el pago nuevos de WooCommerce, cada trozo (los campos, el resumen, los métodos de envío) es un bloque.  ·  _(cap. 12)_
- **bloque interno.** Un bloque que sólo puede vivir **dentro** de otro. Los dos de este plugin sólo pueden ir dentro de `woocommerce/checkout-fields-block`.  ·  _(cap. 12)_
- **Bloques.** El formato nuevo de WooCommerce para el carrito y el checkout: se pinta en el navegador con JavaScript. En Akibara lo gobierna el plugin `akibara-cart-checkout-blocks`.  ·  _(cap. 05, 19)_
- **Bomba (*pump*).** El trabajo que **vacía** la deuda del buscador. Es bomba de achique, la de un barco: saca agua. El aviso se llama `akb_search_pump_live`. No hay nada que explote.  ·  _(cap. 16)_
- **Brevo.** El servicio externo de correo de marketing. Su plugin es de terceros; lo único propio es un mu-plugin de 100 líneas.  ·  _(cap. 07)_
- **bundle.** Un fichero JavaScript que se descarga entero en el navegador. Aquí hay nueve.  ·  _(cap. 12)_

## C

- **cabecera.** Un dato invisible que el navegador o el robot manda junto con la petición, o que el servidor manda junto con la respuesta.  ·  _(cap. 07)_
- **Caché de borde.** La copia que guarda Cloudflare, fuera del servidor de la tienda. No obedece el `Cache-Control` común: hay que hablarle con `CDN-Cache-Control`.  ·  _(cap. 09)_
- **Caché de objetos.** Memoria donde WordPress guarda resultados ya calculados durante (y en producción también entre) peticiones. Es la razón de que `get_option()` pueda devolver un valor viejo y de que los candados se hagan por base de datos.
- **Caché de objetos persistente.** Un almacén en memoria fuera de la base de datos. Con ella encendida, los transients **no pasan por `wp_options`**, y por eso borrarlos con un `DELETE` por SQL no funciona. guarda datos calculados entre peticiones.  ·  _(cap. 03, 17)_
- **Caché de objetos y *drop-in* persistente.** WordPress guarda en memoria lo que ya leyó, pero por defecto sólo durante esa petición. Un *drop-in* persistente —Redis o Memcached— hace que esa memoria sobreviva entre peticiones. Que un dato «viva en la caché de objetos si hay drop-in persistente, y si no en una option» significa exactamente eso: si hay memoria compartida se usa, y si no, se baja a la base.  ·  _(cap. 08)_
- **Cadena.** Una secuencia de trabajos de una sola vez en la que cada corrida agenda la siguiente mientras quede trabajo. Si una corrida no agenda la siguiente, la cadena se **congela**.  ·  _(cap. 16)_
- **Cadena de precedencia.** El orden en que se busca el plazo: ficha, editorial, país, días de tránsito. Gana el primero que exista.  ·  _(cap. 04)_
- **Cajón (drawer).** El panel que se desliza por el costado de la pantalla. Aquí, el carrito.  ·  _(cap. 05)_
- **Cambiazo (swap).** El `RENAME TABLE` único que pone las `_new` en producción y aparta las vivas como `_old`. cambio de nombre y de golpe. Al conjunto de tablas vivas y sombra se le llama **topología**.  ·  _(cap. 08, 16)_
- **Canal estático.** Una variable que vive sólo durante una visita y que cualquier parte del código puede leer, sin pasársela de mano en mano. Es como un aviso viaja desde el motor —que lo descubre— hasta la plantilla que lo imprime. Termina con la petición.  ·  _(cap. 09)_
- **Candado (`GET_LOCK`).** Un cerrojo que ofrece MySQL. Mientras un proceso lo tiene, otro que pida el mismo nombre espera (aquí, hasta 3 segundos) o se rinde. Impide que dos pestañas del mismo cliente muevan el saldo a la vez. la vez. Caduca sola si el trabajo muere a medias. mismo trabajo corra a la vez.  ·  _(cap. 10, 16, 17)_
- **Canje.** Convertir puntos en descuento sobre un pedido.  ·  _(cap. 10)_
- **canonical.** La etiqueta que le dice a Google cuál es la dirección «oficial» de una página, cuando la misma página se puede alcanzar por varias.  ·  _(cap. 07)_
- **canonizar.** Reescribir un valor a su única forma correcta: `nunoa` → `Ñuñoa`.  ·  _(cap. 12)_
- **Capability.** Un permiso con nombre. `manage_product_terms` es el que WooCommerce usa para los atributos de producto; `manage_categories` es el genérico de WordPress. `manage_product_terms` (atributos de producto) y `manage_options`.  ·  _(cap. 03, 07)_
- **Capacidad (`manage_woocommerce`, `list_users`).** Permiso con nombre que WordPress asigna a un rol. Preguntar por la capacidad, y no por el rol, es la forma correcta de decidir quién puede qué. tiene cualquier cliente registrado; las otras dos, sólo quien administra la tienda o el sitio. Akibara.  ·  _(cap. 05, 14, 17)_
- **Cargo (*fee*).** Una línea del carrito que suma o resta dinero sin ser un producto. El canje de puntos es un cargo negativo.  ·  _(cap. 18)_
- **Carril.** En este plugin, cada una de las formas de ganar puntos (compra, bienvenida, reseña, referido, cumpleaños). Cada carril tiene su interruptor.  ·  _(cap. 11)_
- **carril de publicación.** El procedimiento con el que la casa despliega código. Compara el paquete publicado contra lo que hay en producción **byte a byte**; si algo no coincide —por ejemplo porque un plugin se actualizó solo—, el despliegue se detiene.  ·  _(cap. 14)_
- **carrito de bloques.** La versión moderna del carrito y el checkout de WooCommerce, hecha con bloques y que habla con el servidor por la Store API. Es lo que usa Akibara.  ·  _(cap. 14)_
- **CAS.** (*compare-and-swap*, «compara y escribe»): escribir un valor **sólo si** el valor guardado sigue siendo el que leí, resuelto en una única sentencia SQL para que sea indivisible. Es lo que impide que dos visitantes simultáneos hagan el mismo trabajo.  ·  _(cap. 02)_
- **Cebar.** (una option): pedirle a WordPress que traiga de la base varios ajustes **en una sola consulta**, antes de que alguien los pida uno a uno. No cambia ningún valor: sólo evita pagar una consulta por cada lectura. Es el único verbo que este capítulo usa para eso.  ·  _(cap. 02)_
- **centinela.** En este capítulo, la pieza que vigila desde fuera que el transporte siga instalado.  ·  _(cap. 15)_
- **cepo.** En este capítulo, el filtro que desvía todo el correo del espejo a una bandeja inventada.  ·  _(cap. 15)_
- **Cerrojo / gate.** Mecanismo que impide escribir o servir mientras no se pueda garantizar coherencia.  ·  _(cap. 17)_
- **Chip.** La etiqueta con una ✕ que muestra un filtro ya aplicado y que sirve para quitarlo.  ·  _(cap. 19)_
- **`class_exists()` / `function_exists()`.** Preguntar «¿existe esto?» antes de usarlo. Es la diferencia entre degradar y caerse.  ·  _(cap. 19)_
- **Clave de evento (`event_key`).** Texto único que identifica un movimiento irrepetible (`award_1234_1`, `birthday_88_2027`). Tiene índice único en la tabla: si la fila ya existe, el movimiento se declara ya aplicado y el saldo no se toca.  ·  _(cap. 10)_
- **Cloudflare.** La red que está delante del sitio y que también guarda copias de las páginas.  ·  _(cap. 07, 19)_
- **Coalescer.** Juntar varias peticiones idénticas en una sola corrida, para no repetir trabajo.  ·  _(cap. 16)_
- **Collation.** La regla con la que la base decide si dos textos son «iguales». La normal considera «ñ» y «n» la misma letra; en `tokens` se fija una **binaria** a propósito, porque si no dos palabras distintas chocan en la clave primaria y el INSERT muere mudo.  ·  _(cap. 08)_
- **combinar.** (CSS o JS) — fundir varios ficheros en uno. Es lo que rompe la portada de Akibara; **minificar** —comprimir cada fichero por separado— es seguro y está activo.  ·  _(cap. 15)_
- **combobox.** Un campo de texto que además despliega una lista de opciones y se maneja con el teclado. Es lo que este plugin hace con «Comuna».  ·  _(cap. 12)_
- **Comisión / fee.** Una línea extra en el carrito de WooCommerce. La del canje es **negativa**, así que resta.  ·  _(cap. 10)_
- **Comment meta.** Un dato pegado a un comentario. Una reseña es un comentario, así que sus estrellas, su foto y su sello de premio son comment metas.  ·  _(cap. 11)_
- **Comparar-y-cambiar.** Escribir un valor sólo si el valor actual es exactamente el que se leyó antes. Es lo que impide que dos peticiones simultáneas se pisen.  ·  _(cap. 05)_
- **compare-and-swap (CAS).** Escribir un valor **sólo si** el valor anterior era el que esperabas. Es lo que evita que dos procesos se pisen; la API de options de WordPress no lo ofrece, de ahí el SQL a mano. Así dos procesos no pueden ganar los dos.  ·  _(cap. 07, 17)_
- **Comprobante de publicación.** Recibo interno que afirma que los precios y las facetas publicados corresponden al catálogo de ahora. Es lo que separa «configurado» de «efectivo». Lo emite `EditorialDiscountPublication` y se consulta con `serving_ready()` o `capture_serving_token()`. Este capítulo usa siempre este nombre; «comprobante de servido» es lo mismo.  ·  _(cap. 09)_
- **Compuerta (*gate*).** Una comprobación que corta el paso. `maybe_pay()` es una escalera de once.  ·  _(cap. 11)_
- **Comuna.** La división administrativa chilena que el despacho necesita. Blue Express sólo cotiza si la comuna está escrita exactamente como en su propio catálogo: de ahí `ComunaDirectory`.  ·  _(cap. 19)_
- **Configurado vs efectivo.** Configurado: la option dice `yes`. Efectivo: además existe el comprobante vigente.  ·  _(cap. 09)_
- **constante.** Valor fijo escrito con `define()` en `wp-config.php`. No se puede editar desde el navegador, y por eso es donde viven las contraseñas. es donde viven los secretos.  ·  _(cap. 15, 18)_
- **contraseña de aplicación.** Clave larga que WordPress genera aparte de la contraseña normal de una persona, sirve sólo para entrar por la API y se puede revocar sin cambiar la contraseña de esa persona. Es la credencial con la que se entra a las abilities y a MCP.  ·  _(cap. 14)_
- **Contrato de alimentación.** El acuerdo entre Business y el buscador sobre **qué significa** el dato que el primero le entrega al segundo para que lo guarde en su índice — en concreto, qué es el precio efectivo y cuándo no hay precio que publicar. Tiene su propio número de versión, separado del número de versión del plugin, y sólo sube cuando cambia ese significado; cuando sube, hay que reconstruir el índice entero porque lo ya guardado se interpretaba con el significado anterior. Ver pieza 9.  ·  _(cap. 02)_
- **Corte / tanda.** El día del mes en que se cierra el pedido a una editorial. Los pedidos se juntan hasta ese día y el plazo corre **desde** ahí.  ·  _(cap. 04)_
- **cortocircuitar.** (un `||` en PHP) — una expresión `A || B` se evalúa de izquierda a derecha y **se detiene en cuanto algo es verdadero**. Si `A` es verdadera, `B` no se ejecuta. Es lo que hace que LiteSpeed ni pregunte por una opción cuando la constante `LITESPEED_GUEST_OPTM` está definida, y por tanto lo que deja mudo a un filtro que estaba puesto en su sitio.  ·  _(cap. 15)_
- **Cron.** Una tarea programada. En este sitio el cron de WordPress no lo empuja el tráfico: lo dispara el servidor cada 5 minutos. panel de Hostinger cada 5 minutos, no el tráfico de clientes. ejecutan cuando alguien empuja la cola. En producción la empuja el cron de Hostinger cada 5 minutos, porque `DISABLE_WP_CRON` está activo. hospedaje dispara `wp-cron.php` cada 5 minutos. llama el servidor de Hostinger cada 5 minutos. hosting cada 5 minutos, porque el disparo por tráfico de WordPress está desactivado. dispara Hostinger cada cinco minutos desde fuera.  ·  _(cap. 02, 04, 05, 07, 10, 11, 14, 16, 18)_
- **Cupón virtual.** Un cupón que el código fabrica al vuelo para que WooCommerce lo aplique, y que no existe como registro en la base. Es la forma de descontar sin tocar el precio del producto.  ·  _(cap. 03)_
- **CusRev.** Apodo del plugin comprado *Customer Reviews for WooCommerce*, al que este código reemplaza y cuyas claves de datos hereda.  ·  _(cap. 11)_

## D

- **datos estructurados / JSON-LD / `@graph`.** Un bloque invisible de datos que la página lleva para que Google entienda qué es (producto, libro, precio, autor) sin adivinarlo del texto.  ·  _(cap. 07)_
- **`dbDelta`.** La función de WordPress que crea y actualiza tablas. Hace lo que puede y no siempre avisa de lo que no pudo; por eso este plugin no le cree y comprueba después.  ·  _(cap. 02, 08)_
- **DDL.** Las órdenes que definen **la forma** de las tablas —crear una tabla, añadir una columna, crear un índice—, por oposición a las que mueven datos.  ·  _(cap. 08)_
- **Debounce (espera de agrupación).** Retrasar un trabajo unos segundos para que una ráfaga de cambios produzca una sola corrida.  ·  _(cap. 16)_
- **Deduplicar.** No encolar un trabajo si ya hay otro igual esperando.  ·  _(cap. 16)_
- **Deduplicar por argumentos.** Preguntarle a la cola si ya hay un trabajo pendiente con **exactamente** estos datos, en vez de usar su marca global, que ignora los datos y colapsaría un restock masivo a un solo producto.  ·  _(cap. 04)_
- **Degradar.** Seguir funcionando con menos, en vez de romperse. Lo contrario de un fatal.  ·  _(cap. 19)_
- **denylist.** Lista de cosas prohibidas. Aquí, tres abilities que se vetan por nombre: dos de Rank Math y `woocommerce/product-delete`.  ·  _(cap. 14)_
- **Desinstalador (`uninstall.php`).** Fichero que WordPress ejecuta una vez al Eliminar un plugin. Desactivar **no** lo ejecuta.  ·  _(cap. 17)_
- **Deuda (del buscador).** Una anotación durable de «este producto hay que reindexarlo», guardada en tabla para que sobreviva a un reinicio. `akb_search_editorial_discount_state`.  ·  _(cap. 16, 17)_
- **Deuda durable.** La lista de lo que falta por reindexar, guardada en una tabla propia y no en la cola de trabajos, para que sobreviva a una caída del servidor.  ·  _(cap. 08)_
- **`DISABLE_WP_CRON`.** Ajuste de WordPress que apaga el empuje por tráfico. En akibara.cl está encendido, y quien empuja es la tarea de Hostinger cada 5 minutos.  ·  _(cap. 16)_
- **`do_action` / `add_action`.** El par de «pasó esto» / «avísame cuando pase». No devuelve valor.  ·  _(cap. 19)_
- **docroot.** La carpeta que el servidor web publica. En producción es `~/domains/akibara.cl/public_html`. Nada que sea un volcado o una copia de rescate debe vivir dentro: la copia de rescate del transporte está justo un nivel más arriba, en `~/domains/akibara.cl/akb-runtime/`.  ·  _(cap. 15)_
- **Documento.** La fila de `docs` que representa a un producto dentro del índice.  ·  _(cap. 08)_
- **Dominio cerrado.** La lista finita de valores legales de una option. Está en §5.  ·  _(cap. 17)_
- **downgrade silencioso.** Bajar una versión de un fichero sin que nadie se entere. Pasó de verdad: el cron viejo de hPanel reponía a ciegas una copia congelada en la 1.0.0 encima de la 1.1.0 desplegada, y como el fichero estaba, la clase cargaba y el SMTP autenticaba, todas las comprobaciones daban verde. Por eso hoy se compara el md5 y no la presencia.  ·  _(cap. 15)_
- **Drop-in.** Un fichero especial en `wp-content/` que reemplaza una pieza interna de WordPress. Aquí importa `object-cache.php`, que LiteSpeed deja puesto y que convierte la caché de objetos en persistente.  ·  _(cap. 02)_
- **Dropin.** Un fichero especial que WordPress carga antes que casi todo, por su nombre exacto (`object-cache.php`, `advanced-cache.php`). No se activa ni se desactiva: está o no está.
- **`dry_run`.** («ensayo en seco») — Correr una operación sin escribir nada, sólo para ver qué haría.  ·  _(cap. 14, 15)_

## E

- **el espejo.** La copia local de la tienda, en Docker, donde se prueba antes de tocar producción.  ·  _(cap. 07)_
- **Emogrifier.** La librería que WooCommerce usa para meter el CSS **dentro** de cada etiqueta del correo, porque los clientes de correo ignoran las hojas de estilo externas.  ·  _(cap. 07)_
- **Endpoint.** Una dirección con nombre dentro del sitio: `/mi-cuenta/favoritos/` es un endpoint de Mi cuenta; `/?wc-ajax=akb_get_cart` es un endpoint AJAX.  ·  _(cap. 05)_
- **Endpoint REST.** Una dirección `https://akibara.cl/wp-json/…` que el navegador consulta para pedir o enviar datos sin recargar la página.  ·  _(cap. 19)_
- **entorno.** (`wp_get_environment_type()`) — etiqueta que dice si esta instalación es `production`, `local`, `staging` o `development`. En Akibara la fija `wp-config.php:30`.  ·  _(cap. 15)_
- **espejo.** La copia local del sitio, levantada con Docker desde el `compose.yaml` del repositorio y con datos traídos de producción. Sirve para probar antes de tocar la tienda de verdad. Que traiga datos reales es lo que hace peligroso que se le olvide declarar el entorno: llegan también las credenciales de los servicios externos, armadas.  ·  _(cap. 08, 14, 15)_
- **Estrangulador (throttle).** Límite de cuántas veces por minuto puede correr una comprobación cara.  ·  _(cap. 08)_
- **`event_key`.** Cadena que identifica un hecho irrepetible (este pedido, este aviso). Con un índice único, impide aplicarlo dos veces… **si no es nula**.  ·  _(cap. 17)_
- **Evento recurrente.** Un trabajo que se repite con un intervalo fijo (diario, semanal, cada 900 s).  ·  _(cap. 16)_
- **Exportador / borrador de privacidad.** Los ganchos con los que un plugin declara que sabe entregar o borrar los datos personales de un cliente.  ·  _(cap. 17)_

## F

- **faceta.** Cada filtro del catálogo (precio, editorial, género…). Una combinación de facetas produce una URL con parámetros.  ·  _(cap. 07, 08, 09, 19)_
- **Faceta dueña (de un archivo).** La faceta que corresponde a la taxonomía de esa página: en `/serie/one-piece/` la faceta dueña es *Serie*. No se ofrece dentro de su propio archivo. Hay archivos sin dueña —`/product-tag/…/`—, y eso tiene consecuencias.  ·  _(cap. 09)_
- **Facetas.** Los filtros que el cliente ve junto a los resultados de búsqueda —editorial, serie, precio, disponibilidad—, calculados del mismo índice.  ·  _(cap. 16)_
- **fail open.** «Fallar abierto»: cuando falta un dato, seguir vendiendo con menos ayudas en vez de romperse. Su reverso es que el fallo no se nota.  ·  _(cap. 12)_
- **Fail-closed.** Ante una duda, apagar el servicio en vez de devolver algo posiblemente falso. Lo contrario, *fail-open*, es lo que hace el front del catálogo: si el índice está vacío, sirve el catálogo sin filtrar. espera que salga bien.  ·  _(cap. 04, 08, 09, 15)_
- **fail-closed / fail-open.** Dos políticas ante una lectura dudosa. *Fail-closed* aborta y conserva lo anterior (lo que hace `LlmsManifest`); *fail-open* sigue adelante conservando la entrada (lo que hace `SeriesSitemapLastmod` con el sitemap). con menos garantías). El checkout de comunas falla **abierto** a propósito; las abilities de MCP fallan **cerradas**.  ·  _(cap. 07, 18)_
- **Fail-open (fallar abierto).** La excepción deliberada: con el índice vacío, el catálogo se degrada a WooCommerce sin filtrar, para que un índice muerto no convierta la tienda en cero productos. este capítulo aparece una sola vez, y es un defecto: la escritura de redirecciones devuelve éxito aunque haya actualizado cero filas.  ·  _(cap. 09, 15)_
- **Fallar cerrado.** Ante un dato ilegible o desconocido, elegir la opción conservadora en vez de seguir adelante. Aquí: no emitir el nodo de schema, no entrar en el directorio, no avisar.  ·  _(cap. 03)_
- **Fan-out.** Partir de una cosa que cambió —un término, por ejemplo «Ivrea»— y resolver la lista de **todos** los productos a los que afecta, para apuntarlos uno a uno como deuda.  ·  _(cap. 08)_
- **Fase.** En qué punto del viaje está un rebuild, guardado en `akb_search_rebuild_state`. option `akb_search_rebuild_state`, y sólo puede valer uno de diez nombres (§4).  ·  _(cap. 08, 17)_
- **Fatal.** Un error de PHP que corta la página. El visitante ve una pantalla en blanco o un error del servidor.  ·  _(cap. 19)_
- **Fence (cerrojo).** Candado de MariaDB atado a una conexión concreta, que impide que dos procesos escriban el índice a la vez.  ·  _(cap. 08)_
- **Ficha (token).** Cada trozo en que se parte el texto buscado. `jujutsu kaisen 26` son tres fichas.  ·  _(cap. 09)_
- **FIFO.** (*first in, first out*): «lo primero que entra es lo primero que sale». Aplicado al vencimiento, significa que los gastos del cliente consumen siempre sus puntos más viejos.  ·  _(cap. 10)_
- **Filter (filtro).** Un aviso de WordPress que pasa un valor y espera que se lo devuelvan, cambiado o igual («este es el precio, ¿lo modificas?»). Es la forma limpia de alterar lo que hace un plugin ajeno.
- **Filtro (hook).** Punto del programa donde otro trozo de código dice «cuando vayas a usar el valor X, déjame cambiarlo antes». (`add_action`), que hace algo pero no devuelve nada. deja pasar el veredicto «aprobado/en espera» y aquí se devuelve siempre «en espera». devuelvo distinto». del buscador es un filtro: por eso puede impedir una escritura.  ·  _(cap. 04, 05, 11, 12, 14, 17, 18)_
- **Fingerprint (huella).** Un resumen corto y único de algo grande. Aquí, un `sha256` del carrito: si el carrito cambia, la huella cambia.  ·  _(cap. 05)_
- **Flecha con una equis.** (`--x` en los diagramas): significa que ese mensaje **no** ocurre. No es un fallo ni una cancelación: es la ausencia, dibujada a propósito para poder afirmar «por aquí no sale ningún correo».  ·  _(cap. 02)_
- **formato Jed.** La forma en que WordPress entrega las traducciones al JavaScript: un JSON con la estructura `{"locale_data":{"messages":{"texto original":["traducción"]}}}`. Se llama así por la biblioteca de JavaScript que la popularizó. Es lo que reescribe `akibara-woo-blocks-i18n.php:46-72`.  ·  _(cap. 15)_
- **Foto (instantánea).** Una copia congelada y sellada de una configuración —sobre todo la de descuentos— que el buscador publica y el plugin principal adopta. Si la foto no cuadra con lo que hay, se rechaza entera: mejor sin descuento que con un precio inventado.
- **Foto de la fuente.** Leer esos once sitios de golpe y congelar el resultado, para poder comparar sin que cambie a mitad.  ·  _(cap. 17)_
- **Foto de precios (el «recibo»).** La copia sellada de qué campaña de descuento por editorial estaba vigente cuando se construyó el índice, con su **secuencia** (número de orden, para saber cuál es más nueva) y su **huella** (resumen del contenido, para saber si cambió). Si no calza con las tablas vivas, el buscador se apaga antes que servir precios falsos.  ·  _(cap. 16)_
- **Foto sellada (snapshot).** La copia congelada de la configuración de descuento que el buscador le entrega a business para que los dos confirmen que miran lo mismo.  ·  _(cap. 19)_
- **Fuente.** Los once sitios de los que depende un precio — las diez options vigiladas más el termmeta `_akb_taxonomy_discount_pct` de cada editorial o serie.  ·  _(cap. 17)_

## G

- **Gancho (*hook*).** Punto del programa donde WordPress avisa de que algo pasó y permite intervenir.  ·  _(cap. 11)_
- **Gancho / hook.** Un aviso que WordPress o WooCommerce grita mientras trabajan («cambié el estado de un pedido»). Un plugin se cuelga de ese aviso para actuar en el momento exacto.  ·  _(cap. 10)_
- **`gancho:prioridad`.** Forma abreviada de escribir dónde y con qué orden se engancha una función: `init:30` es «en el gancho `init`, con prioridad 30». El número menor corre primero y, a igualdad de número, corre primero quien se enganchó antes.  ·  _(cap. 14)_
- **Gate / serving.** La comprobación que decide si el buscador puede responder. Si la huella viva, la del estado y la del recibo no coinciden, se apaga a propósito.  ·  _(cap. 08)_
- **Gate editorial.** La puerta que vigila si la publicación de los **descuentos por editorial** (un porcentaje colgado de un término entero, ya calculado dentro del índice) está vigente. Es lo que el panel nombra cuando pinta un servicio en naranja. No tiene relación con equivocarse de editorial al buscar.  ·  _(cap. 09)_
- **Generación.** Un **número de serie del índice**. Cada vez que el índice cambia de contenido, sube. Sirve para dos cosas: que la purga de la caché sepa que hay algo nuevo que tirar, y que un recibo pueda afirmar «yo certifico la generación 47, no otra». Vive en la option `akb_search_index_gen`, y cambiarla es exactamente lo que dispara `RestCache::purge()`. cambiazo escriba sobre el índice nuevo; «publicación de generación» es el aviso de que ese número subió.  ·  _(cap. 03, 08, 16)_
- **Generación del índice.** Un número que avanza en cada reconstrucción (la option `akb_search_index_gen`). Sirve para saber si un dato cacheado pertenece al catálogo de ahora.  ·  _(cap. 09)_
- **`GET_LOCK`.** Un cerrojo con nombre que ofrece MySQL, la base de datos del sitio. El primero que lo pide lo tiene; los demás **esperan** su turno, y se suelta solo si el proceso que lo tenía se muere. Es distinto del **reclamo atómico**, que no hace esperar a nadie: ahí el que pierde simplemente no hace el trabajo y sigue.  ·  _(cap. 04)_
- **grupo.** (de Action Scheduler) — Etiqueta que se le pone a una tarea al programarla y por la que se la busca en WooCommerce → Estado → Acciones programadas. Una tarea sin grupo corre igual, pero el dueño no la encuentra en esa pantalla. vez. Los trabajos de puntos **no tienen grupo**.  ·  _(cap. 14, 16)_
- **guard.** En este capítulo, el filtro que decide si un correo sale o se corta.  ·  _(cap. 15)_
- **Guest Mode / `guest_optm`.** Modo de LiteSpeed para el visitante de primera vez. Si está activo, define la constante `LITESPEED_GUEST_OPTM`, que fuerza el paquete de optimizaciones saltándose la configuración.  ·  _(cap. 15)_

## H

- **hidratar.** Cuando el JavaScript toma el HTML ya servido y lo convierte en una interfaz viva. El atributo `data-block-name` desaparece al hidratar: por eso no sirve para verificar.  ·  _(cap. 12)_
- **HMAC.** Una firma criptográfica corta que se adjunta a un enlace para probar que lo emitió el sitio y no un tercero. Se compara con `hash_equals()`, que tarda lo mismo acierte o falle. escribir el identificador de sesión en la base de datos.  ·  _(cap. 03, 05)_
- **Honeypot.** Un campo invisible que sólo rellenan los robots. Si viene con texto, el envío se descarta.  ·  _(cap. 19)_
- **Hook.** (gancho): WordPress va avisando en voz alta de lo que hace («ya cargué los plugins», «voy a pintar la cabecera»). Un hook es uno de esos avisos, y engancharse a él significa pedir que te llamen cuando ocurra. Los hay de dos tipos: **acciones** (te avisan para que hagas algo) y **filtros** (te pasan un valor para que lo devuelvas cambiado). *Engancharse a un hook* es pedir turno para ejecutar algo en un momento concreto. La **prioridad** es el número que decide quién va antes: menor corre primero. despertar. Todo este capítulo funciona a base de hooks. sitios concretos y pregunta «¿alguien quiere añadir o cambiar algo aquí?». Hay dos clases: *acciones* (haz algo ahora) y *filtros* (te paso un valor, devuélveme el que quieras). plugin puede engancharse. La **prioridad** decide el orden: más bajo, antes. aquí?». Se usa con `add_action` (ejecutar algo) o `add_filter` (modificar un valor que va de paso). llamen. La pieza básica de WordPress.  ·  _(cap. 02, 03, 05, 07, 08, 09, 12, 15, 16, 17, 19)_
- **Hook / acción.** Punto de enganche que avisa de que algo pasó, sin cambiar ningún valor.  ·  _(cap. 18)_
- **Hora anclada / hora heredada.** Una hora anclada la recalcula el código y se corrige sola; una heredada es la que quedó del primer agendado y se mueve sola con cada corrida y con el cambio de horario.  ·  _(cap. 19)_
- **hPanel.** El panel de control de Hostinger, la empresa que aloja la tienda. Es donde vive la tarea programada que llama a `wp-cron.php` cada 5 minutos, y donde está el administrador de archivos con el que se puede borrar un mu-plugin sin pasar por SSH. No forma parte de WordPress y WordPress no lo ve.  ·  _(cap. 15)_
- **HPOS.** (*High-Performance Order Storage*): el almacenamiento moderno de pedidos de WooCommerce, en tablas propias en vez de dentro de `wp_posts`. pedidos: en tablas propias (`wp_wc_orders`) en vez de como entradas de WordPress en `wp_posts`. Sólo uno de los dos manda a la vez, y al que manda se le llama **el almacén canónico de pedidos**. El código de esta área no lo da por supuesto: pregunta con `OrderStorage::uses_hpos()` y arma la consulta que corresponda. este capítulo usan las tablas de **líneas** de pedido, que son las mismas con y sin HPOS. vez de en las tablas de entradas del blog. Cambia dónde viven las order meta. plugin declara que es compatible con ellas. las entradas del blog.  ·  _(cap. 02, 03, 05, 10, 12, 18, 19)_
- **Huella (fingerprint).** Resumen criptográfico de una configuración. Sirve para probar que lo publicado corresponde exactamente a lo configurado.  ·  _(cap. 08)_

## I

- **`@id`.** El identificador de un nodo dentro de esos datos. Dos nodos son el **mismo** cuando comparten `@id`, no cuando comparten la URL.  ·  _(cap. 07)_
- **Idempotencia.** La propiedad de que repetir una operación no cambie el resultado. Es lo que impide que un pedido acredite dos veces. cause daño. Es la pregunta que hay que hacerle a cada fila de la tabla «Lo programado». una sola vez.  ·  _(cap. 10, 16, 17)_
- **Idempotente.** Que se puede ejecutar dos veces con el mismo resultado que una. Es el contrato que se le exige a todo oyente de `akibara_business_upgraded`. portadas lo es; el cursor que la acompaña, no.  ·  _(cap. 02, 04, 07, 08, 14)_
- **Incremental.** Reescribir sólo las filas de un producto que cambió. Segundos, sin apagar nada.  ·  _(cap. 08)_
- **IndexNow.** Servicio al que se avisa de que una página cambió para que los buscadores la revisiten.  ·  _(cap. 15)_
- **Índice.** Las tablas propias donde el plugin guarda una copia resumida del catálogo, para no preguntarle a WooCommerce en cada tecla. rápido. El autocompletado y las facetas leen de ahí, nunca de los productos.  ·  _(cap. 08, 09, 16)_
- **Índice del buscador.** Las tablas propias donde el buscador guarda una copia lista para consultar de cada producto, con su precio ya calculado.  ·  _(cap. 19)_
- **`information_schema`.** El catálogo que la propia base de datos lleva de sus tablas. La lista oficial de qué columnas y qué índices existen de verdad, que es contra lo que se verifica.  ·  _(cap. 08)_
- **`init`.** El aviso que WordPress dispara en toda petición una vez que ya está cargado todo. El núcleo se engancha en su turno 999, el último.  ·  _(cap. 02)_
- **inlinar.** Imprimir un trozo de JavaScript dentro del propio HTML de la página, en vez de en un fichero aparte. Es como el servidor le pasa datos al navegador aquí.  ·  _(cap. 12)_
- **Inmutable hacia atrás.** Que un valor, una vez puesto, ya no retrocede: cuando `_akb_eta_ready_to_ship` vale `yes`, ningún proceso lo devuelve a `no`, ni aunque el tomo se vuelva a agotar.  ·  _(cap. 16)_
- **InnoDB.** El motor de almacenamiento de MariaDB que sabe hacer transacciones. Se exige a propósito: sin él, «o entra todo o no entra nada» no existiría.  ·  _(cap. 08)_
- **`INSERT IGNORE`.** Una inserción que, si choca con una restricción única de la base, no falla: simplemente no inserta. Es lo que hace que republicar un tomo no vuelva a avisar.  ·  _(cap. 03)_
- **Intención de canje.** Cuántos puntos dijo el cliente que quiere usar. Vive en la sesión de WooCommerce hasta que compre, la retire o la sesión caduque. No es un cobro.  ·  _(cap. 10)_
- **Interfaz (contrato).** Una lista de preguntas que una clase promete saber responder. `CheckoutDataProvider` es una: business la cumple, y el plugin de bloques comprueba que la cumpla antes de registrar nada.  ·  _(cap. 19)_
- **ISBN.** El código de 13 dígitos que identifica un libro. En la tienda se guarda como SKU.  ·  _(cap. 14)_

## J

- **JSON-LD.** Un bloque de datos estructurados que va en el HTML y que leen Google y los asistentes de IA. `ComicSeries` es el tipo de schema.org para una serie de cómic. producto, vale tanto, tiene estas estrellas»). Es lo que permite las estrellitas en los resultados.  ·  _(cap. 03, 11)_
- **JSON-LD / schema / `@graph`.** El bloque de datos estructurados que se incrusta en la página para que Google entienda qué es cada cosa.  ·  _(cap. 18)_

## L

- **LCP.** El elemento más grande que se pinta al cargar; la métrica de velocidad que Google mira.  ·  _(cap. 18)_
- **lease.** Un candado con caducidad: si quien lo tomó muere, el candado se suelta solo.  ·  _(cap. 07)_
- **Ledger.** El libro de movimientos. Tabla propia donde queda escrito cada suma y cada resta de puntos, con su signo, su saldo resultante, su motivo y su fecha.  ·  _(cap. 10, 11)_
- **`LEFT JOIN` de una tabla consigo misma.** Una forma de mirar **dos filas distintas de la misma tabla a la vez** poniéndolas una al lado de la otra, para poder exigir condiciones sobre las dos en una sola sentencia. Aquí junta la fila del marcador con la fila de la versión instalada.  ·  _(cap. 02)_
- **`Link: rel="alternate"`.** Cabecera de respuesta (RFC 8288) con la que una página anuncia que existe otra versión de sí misma. Aquí es como un agente descubre la variante Markdown sin tener que negociarla.  ·  _(cap. 07)_
- **`List-Unsubscribe` / RFC 8058.** Las cabeceras que hacen aparecer el botón «Cancelar suscripción» arriba del correo en Gmail. Son obligatorias para un remitente masivo.  ·  _(cap. 07)_
- **LiteSpeed.** El plugin de caché del servidor. Guarda el HTML ya armado para no rehacerlo en cada visita; por eso, tras cambiar algo, hay que purgarlo o el cliente sigue viendo lo viejo.
- **LiteSpeed Cache.** El plugin de terceros que guarda páginas ya armadas para servirlas más rápido. Si no se le avisa, sirve la versión vieja. 7.9.1.  ·  _(cap. 07, 15, 19)_
- **Live-lock.** Un sistema que no está parado ni trabado, sino girando sin avanzar. Aquí tiene nombre y fecha: el 2026-09-05, una señal de rebuild con `not_before` en el futuro bloqueaba el carril automático mientras no venciera la gracia, y el comando lo anunciaba como si todo hubiera ido bien.  ·  _(cap. 08)_
- **`llms.txt`.** Un fichero de texto en la raíz del sitio, según la convención de llmstxt.org, que le cuenta a un modelo de lenguaje qué es el sitio y qué hay en él.  ·  _(cap. 07)_
- **`localStorage`.** La memoria que un sitio puede usar dentro del navegador del visitante. No sale de ahí y no llega al servidor.  ·  _(cap. 09)_

## M

- **Mailpit.** El buzón de correo de mentira del espejo. Recoge todo lo que el sitio local intenta enviar, para que ninguna prueba llegue a una persona.  ·  _(cap. 07, 08, 15)_
- **Manejador (*handler*).** El trozo de código que se ejecuta cuando suena un aviso.  ·  _(cap. 16)_
- **Materializar.** El momento en que el recibo alcanza a la fuente. Hasta entonces hay deuda y `suggest` responde 503.  ·  _(cap. 17)_
- **MCP.** El protocolo por el que un agente de inteligencia artificial descubre y ejecuta operaciones publicadas por un servidor. Aquí lo sirve un adaptador que viene dentro de Rank Math y de WooCommerce. akibara.cl la puerta es `POST /wp-json/mcp/mcp-adapter-default-server`, que sirve el adaptador que viene dentro de Rank Math, y el cliente se autentica con una contraseña de aplicación de WordPress. un servidor. Aquí lo alimenta la Abilities API.  ·  _(cap. 14, 15, 18)_
- **`menu_order`.** Un número entero que WordPress guarda por cada ficha y que WooCommerce usa para el orden «Destacados». Aquí lo gestiona una máquina: lo que se arrastre a mano se pisa.  ·  _(cap. 03)_
- **Merchant / Google Listings & Ads.** El catálogo de productos que Google usa para Shopping, y el plugin de terceros que se lo envía.  ·  _(cap. 07)_
- **Meta.** Un dato pegado a otra cosa. `termmeta` es un dato pegado a un término de una taxonomía (una editorial, un país); `usermeta`, a una persona. (`termmeta`), a un pedido (`ordermeta`), a una **línea** de pedido (`orderitemmeta`) o a una persona (`usermeta`). pedido. un pedido o a un comentario. (`usermeta`). Tiene nombre y valor, como una etiqueta pegada. y valor. (*usermeta*). *termmeta* (serie, editorial, país), *usermeta* (cliente), *ordermeta* (pedido), *orderitemmeta* (una línea del pedido), *commentmeta* (reseña).  ·  _(cap. 02, 04, 05, 10, 12, 14, 16, 17)_
- **`meta_key` / `option`.** Dos formas de guardar un dato en la base de datos de WordPress. La option es global de la tienda; la meta cuelga de algo concreto (un pedido, un comentario, un usuario).  ·  _(cap. 11)_
- **Mini-carrito.** El carrito pequeño que trae WooCommerce de serie, distinto del cajón propio.  ·  _(cap. 05)_
- **Moderación.** Retener un comentario hasta que una persona lo apruebe.  ·  _(cap. 11)_
- **mu-plugin.** Fichero que WordPress carga **siempre**, antes que cualquier plugin, y que no se puede apagar desde el panel. «mu» es *must-use*. se puede desactivar desde el panel. antes que cualquier otro plugin, y que no se puede desactivar desde el panel. desactivar desde el panel. no se puede desactivar desde el panel.  ·  _(cap. 07, 14, 15, 17, 18, 19)_
- **`multipart`.** Un correo que lleva las **dos** versiones a la vez, HTML y texto plano, para que cada cliente de correo use la que sepa pintar.  ·  _(cap. 07)_

## N

- **Namespace REST.** El prefijo común de un grupo de esas direcciones. Aquí, `akibara/v1`, compartido por dos plugins.  ·  _(cap. 19)_
- **negociación de contenido.** El mecanismo por el que la **misma** dirección devuelve formatos distintos según lo que pida el cliente en la cabecera `Accept`.  ·  _(cap. 07)_
- **NFD / marcas combinantes.** Dos maneras de escribir la misma letra acentuada: `ñ` como un solo carácter, o como `n` más una tilde suelta encima. Los teclados de iPhone producen la segunda, y por eso hay que borrar las tildes sueltas antes de comparar.  ·  _(cap. 12)_
- **`no-store`.** Instrucción que pide a navegadores y CDN que no guarden copia de una respuesta.  ·  _(cap. 18)_
- **noindex.** Instrucción para que un buscador no meta esa URL en su índice. `follow` significa que sí siga los enlaces que hay dentro.  ·  _(cap. 07)_
- **`noindex`.** Una instrucción para que los buscadores no incluyan una página en sus resultados. En este sitio la guarda Rank Math en el term meta `rank_math_robots`.  ·  _(cap. 03)_
- **Nonce.** Una ficha de un solo uso que WordPress mete en los formularios para comprobar que el envío viene de donde dice. No sirve en páginas cacheadas para invitados: nacería caducada. página legítima y no de un sitio ajeno. Caduca, y por eso el cajón sabe pedir uno fresco. página que la tienda sirvió, y no de otro sitio. envío viene de esa pantalla y no de otro sitio.  ·  _(cap. 04, 05, 08, 09, 11, 17, 19)_
- **`nonce`.** Un código de un solo uso que acompaña a una acción del admin para probar que la pidió de verdad quien dice pedirla.  ·  _(cap. 07)_
- **Normalizar.** Bajar a minúsculas, quitar tildes y signos, para que el catálogo y lo que teclea el cliente se encuentren aunque se escriban distinto.  ·  _(cap. 09)_

## O

- **`onbackorder`.** El estado de stock crudo de WooCommerce que significa «no hay, pero se puede comprar igual». En Akibara agrupa «a pedido» y «preventa». en bodega, porque se encarga al proveedor.  ·  _(cap. 04, 14)_
- **opcache.** Caché de código compilado de PHP. Puede seguir sirviendo un fichero que ya se borró del disco, y por eso el centinela comprueba la clase y no sólo el fichero.  ·  _(cap. 15)_
- **Open Graph / `og:image`.** Las etiquetas que WhatsApp, Facebook y X leen para armar la tarjeta de vista previa de un enlace. `og:image` es la imagen de esa tarjeta.  ·  _(cap. 07)_
- **Option.** Una fila de la tabla `wp_options`, donde WordPress guarda un ajuste como un par nombre → valor. WordPress la carga en **todas** las peticiones, aunque nadie la use. el dueño la edita sin tocar código. dueño configura. por `akb_rewards_`. **Autocargada** significa que WordPress la lee en cada petición del sitio, la toque o no. significa que no se lee en todas las peticiones. lo mismo que una fila de una tabla propia, y el capítulo las distingue siempre. guarda la configuración. invisible», que la regla de oro de la casa prohíbe.  ·  _(cap. 02, 03, 04, 05, 07, 08, 09, 10, 12, 14, 15, 16, 17, 18, 19)_
- **`outofstock`.** («agotado») — Estado de stock de WooCommerce para lo que no se puede comprar.  ·  _(cap. 14)_
- **Over-fetch.** Pedir de más a propósito. Aquí: pedir 14 tarjetas al motor para poder mostrar 7 sin huecos.  ·  _(cap. 09)_

## P

- **`pa_pais`.** El atributo de producto «País». WooCommerce antepone `pa_` a todos los atributos de producto, así que el prefijo no significa nada más. Se edita en *Productos → Atributos → País → Configurar términos*.  ·  _(cap. 04)_
- **Palabra ignorada (stopword).** Palabra tan común («de», «el», «manga») que el buscador la descarta al indexar y al buscar, porque no distingue nada.
- **Permiso (*lease*).** Una reserva con caducidad sobre un trozo de trabajo: «este lote es mío durante 120 segundos» (`RebuildJob.php:61`). Si el trabajador muere a medias, el permiso vence solo y otro puede tomar el lote. Es distinto de un **candado** (que impide que dos copias corran a la vez, como los 15 minutos de `_akb_preventa_datechg_lock`) y distinto de un **reclamo atómico** (que marca una fila como propia en la misma operación en que la lee, como `mark_notified()`). Los tres aparecen en este capítulo y no son intercambiables.  ·  _(cap. 16)_
- **PHPMailer.** La biblioteca que WordPress usa por dentro para armar y enviar el correo.  ·  _(cap. 07, 15)_
- **`phpmailer_init`.** Acción que entrega el objeto PHPMailer ya construido, justo antes de enviar, para configurarlo.  ·  _(cap. 15)_
- **PJAX.** Cambiar un trozo de la página sin recargarla entera. Cuando el buscador lo hace, avisa con el evento `akb:pjax`.  ·  _(cap. 19)_
- **Plantilla (template).** El fichero que decide cómo se pinta algo: un correo, la ficha de un producto, el carrito vacío. WooCommerce trae las suyas y un tema puede reemplazarlas copiándolas con el mismo nombre.
- **`plugins_loaded`.** El aviso de «ya cargué todos los plugins». El núcleo se engancha en el turno 20; el plugin de carrito y pago, en el 30.  ·  _(cap. 02, 19)_
- **Poda (del registro de búsquedas).** Borrar del `qlog` las filas viejas —más de 180 días, y por encima de 50.000— para que la tabla no crezca sin fin. No borra productos ni pedidos, y no le quita nada al cliente.  ·  _(cap. 09)_
- **`post__in`.** La lista de identificadores que se le da a WordPress para que muestre exactamente esos productos y en ese orden.  ·  _(cap. 09)_
- **postmeta.** Lo mismo, pegado a un producto o a una entrada del blog.  ·  _(cap. 07)_
- **Postmeta / termmeta.** Datos sueltos colgados de un producto o de un término.  ·  _(cap. 08)_
- **Postmeta / usermeta / termmeta.** Datos sueltos colgados de un producto, de un usuario o de una categoría.  ·  _(cap. 19)_
- **`pre_wp_mail`.** Filtro que permite cortar un envío antes de que ocurra. Devolver `false` desde ahí termina `wp_mail()` en el acto.  ·  _(cap. 15)_
- **Prefijo de tabla.** El `wp_` con que empiezan las tablas de WordPress. Es configurable por instalación: el código nunca lo escribe a mano, lo pide con `$wpdb->prefix`.  ·  _(cap. 09)_
- **`PreOrder` / `BackOrder`.** Dos valores de disponibilidad de schema.org. El primero es «aún no ha salido»; el segundo, «existe pero hay que traerlo».  ·  _(cap. 07)_
- **Preventa.** Un tomo que todavía no salió: se reserva con fecha anunciada. Hoy el catálogo no tiene ninguna configurada así; los 422 marcados «Preventa» son en realidad importación a pedido.
- **Prioridad.** (o turno): el número que decide en qué orden se llama a quienes se engancharon al mismo hook. Menor número, más temprano. decide el orden: 5 corre antes que 10, y 10 antes que 31. Por eso «prio 25» pone el cartel de fecha encima del botón de comprar y «prio 31» pone las promesas debajo, y por eso el atajo de pedidos (prio 10) corre siempre antes que el vigía de la lista de espera (prio 11). el corazón de favoritos (6) sale antes que la vista rápida (7) y que el botón de compra (10). número, más temprano. Es la fuente de la mitad de las sorpresas de este capítulo. aviso. Menor corre antes. Aquí importa mucho: el canje va en la 10 y la acreditación en la 20. primero. mismo punto: el menor corre primero. `PHP_INT_MAX` es el número más grande posible: «el último de todos». bajo, antes. Por eso la lista de espera va en 11 y el listener de preventa en 10. antes. **`PHP_INT_MAX`** es el número más grande que PHP admite: engancharse ahí es ser el último de todos, que es como se consigue tener la última palabra. hook. Menor corre antes; `PHP_INT_MAX` corre el último.  ·  _(cap. 02, 04, 05, 07, 08, 09, 10, 11, 14, 15, 16, 17, 18, 19)_
- **`product_brand`.** La taxonomía «Marcas» de WooCommerce, en *Productos → Marcas*. En Akibara una «marca» es siempre una **editorial**.  ·  _(cap. 04)_
- **Proveedor de precio.** Quien contesta cuánto vale de verdad un producto con el descuento editorial ya aplicado. No es el buscador: es akibara-business, enganchado al filtro `akb_search_effective_price`.  ·  _(cap. 08)_
- **PUDO.** *Pick Up / Drop Off*: retiro en un local del transportista. En Akibara, un Punto Blue Express. en casa. Aquí son los Puntos Blue Express.  ·  _(cap. 05, 12, 17, 18, 19)_
- **Puntuador.** (*scorer*): la parte del buscador que decide qué resultado va primero, dando a cada producto una nota según lo bien que encaja con lo que se escribió. Su orden manda sólo mientras el visitante no elija un criterio propio.  ·  _(cap. 02)_
- **Purga.** Borrar copias guardadas para que la siguiente visita vea lo nuevo. `litespeed_purge` se queda en el origen; `litespeed_purge_all` es la única que llega a Cloudflare.  ·  _(cap. 08, 18)_
- **Purga por etiqueta.** Pedirle a LiteSpeed que invalide todo lo que lleve una marca, por ejemplo `T.<term_id>`, que cubre un archive **y todas sus páginas 2 y siguientes**. Distinta de la purga por URL, que invalida una dirección concreta.  ·  _(cap. 03)_
- **Purgar.** Tirar a la basura esas copias guardadas para que la siguiente visita reciba el HTML nuevo.  ·  _(cap. 19)_

## Q

- **`q-value`.** El peso (`;q=0.8`) con que un cliente dice cuánto prefiere cada formato en la cabecera `Accept`.  ·  _(cap. 07)_
- **qlog.** El registro agregado de búsquedas (`wp_akb_search_qlog`).  ·  _(cap. 09)_
- **`qlog`.** La tabla donde el buscador anota, **agregadas por día**, las búsquedas que la gente hizo.  ·  _(cap. 17)_

## R

- **Rank Math.** El plugin de terceros que es el dueño oficial del SEO del sitio.  ·  _(cap. 07)_
- **React.** La tecnología con que está escrito ese carrito: el navegador arma la pantalla con JavaScript, no el servidor.  ·  _(cap. 19)_
- **Rebuild (reconstrucción).** Rehacer el índice del buscador entero, en tablas sombra, sin apagar la tienda.  ·  _(cap. 08, 17)_
- **Recibo.** Lo que se guarda **después** de publicar, y que dice «con esta huella, el índice tiene exactamente estos documentos». `akb_search_editorial_discount_receipt`.  ·  _(cap. 08, 17)_
- **Reclamo atómico.** Una escritura condicional en la base que sólo una ejecución puede ganar. Es lo que garantiza que nadie reciba dos veces el mismo aviso, aunque dos obreros corran a la vez. operación, para que dos trabajadores no la procesen a la vez. Es la garantía real de «un correo por cliente».  ·  _(cap. 04, 16)_
- **Reconciliar vs reconstruir.** *Reconstruir* rehace el índice entero desde cero. *Reconciliar* no rehace nada: compara índice y catálogo, repara hasta 100 diferencias y poda el registro. Reconciliar no dispara una reconstrucción.  ·  _(cap. 09)_
- **Reconstrucción (rebuild).** Rehacer ese índice entero. Se construye en tablas sombra, así que un reconstruido pedido a mano no deja el buscador caído.  ·  _(cap. 19)_
- **Rejilla.** La cuadrícula de tarjetas de producto de `/tienda/` y de cualquier listado.  ·  _(cap. 19)_
- **Reparentar.** Colgar un adjunto de otra ficha. Aquí, la foto de una reseña se cuelga del producto.  ·  _(cap. 11)_
- **Rescate.** Lo que hace el motor cuando una búsqueda da cero: soltar el nombre de la editorial, o corregir el tipeo. En ese orden.  ·  _(cap. 09)_
- **Reservable.** Todo lo que está en `onbackorder`: se puede comprar aunque no haya existencias.  ·  _(cap. 04)_
- **REST.** La puerta por la que un programa externo habla con la tienda usando direcciones web y texto JSON. En WordPress cuelga de `/wp-json/`.
- **REST / endpoint.** Una puerta por la que el navegador del cliente habla con el servidor sin recargar la página. (JSON) en vez de una página. Un *endpoint* es una de esas direcciones.  ·  _(cap. 04, 12)_
- **REST / Store API.** Las direcciones por las que el navegador —o un tercero— habla con el servidor en formato de datos. La Store API es la de WooCommerce para carrito y checkout.  ·  _(cap. 05)_
- **Reversa.** Retirar puntos ya acreditados porque la venta se cayó.  ·  _(cap. 10)_
- **RFC 8058.** El estándar de la baja en un clic desde el buzón. Exige un par de cabeceras y que el botón use POST; por eso el GET del enlace sólo confirma.  ·  _(cap. 04)_
- **Riel (de despliegue).** El guion de despliegue y el contrato sellado con que se publica cada plugin. Dos piezas de rieles distintos no se pueden cambiar en el mismo despliegue: por eso un cambio que toca dos plugins cuesta el doble.  ·  _(cap. 19)_
- **Rollback.** Volver a desplegar una versión anterior del código. Es el remedio estándar ante un despliegue malo, y aquí tiene un riesgo documentado con el contrato del buscador.  ·  _(cap. 02)_
- **Run.** Una reconstrucción concreta, con nombre propio (`run_id`). Todo lo que esa reconstrucción apunta en la libreta lleva su nombre, para que una acción vieja de otro run no toque el que está vivo.  ·  _(cap. 08)_
- **RUT.** El número de identidad chileno. El checkout lo pide, lo valida con su dígito verificador y lo guarda en el pedido.
- **Ruta REST.** Una dirección que el navegador consulta y que responde datos, no una página. Aquí: `/wp-json/akibara/v1/search/…`.  ·  _(cap. 09)_

## S

- **Saldo cacheado.** La copia rápida del saldo guardada en el usuario. La **verdad contable** es la suma de las filas del ledger; `wp akb-rewards audit --fix` las vuelve a igualar.  ·  _(cap. 10)_
- **Saneador.** La función que limpia lo que el dueño escribió antes de guardarlo (quita etiquetas, recorta, aplana a una línea).  ·  _(cap. 17)_
- **schema.org.** El vocabulario común de esos datos: `Product`, `Book`, `Offer`, `ComicSeries`….  ·  _(cap. 07)_
- **Sello.** En este capítulo, la meta `_akb_rewards_review_points`, que registra el veredicto del premio para que no se vuelva a evaluar.  ·  _(cap. 11)_
- **Sello de generación dentro del valor.** Guardar la generación del índice **junto al dato** cacheado y no en la clave del caché, para que cada reconstrucción reescriba la misma fila en vez de estrenar una nueva y dejar la vieja viva para siempre.  ·  _(cap. 09)_
- **Señuelo (honeypot).** Un campo invisible del formulario que una persona nunca rellena. Si viene relleno, es un robot — y se le responde exactamente lo mismo que a un alta real.  ·  _(cap. 04)_
- **Serving.** La parte del buscador que responde a las peticiones del cliente. Cuando el gate cierra, devuelve 503.  ·  _(cap. 17)_
- **`serving_ready()`.** La pregunta «¿puedo servir resultados sin mentir?» (`EditorialDiscountPublication.php:421-428`). Si responde `false`, el buscador se apaga solo y en silencio: ni autocompletar, ni filtros, ni aviso.  ·  _(cap. 19)_
- **Sesión de WooCommerce.** El espacio donde se guarda lo que el cliente lleva a medias: el carrito, el método de envío elegido, los avisos pendientes.  ·  _(cap. 05)_
- **`sessionStorage`.** Un cajón de datos del navegador que vive **sólo mientras esté abierta esa pestaña** y no viaja al servidor. Aquí guarda qué tomo quería guardar el cliente antes de que lo mandaran a iniciar sesión, bajo la clave `akbWishlistPending`. En modo privado puede estar bloqueado, y entonces el guardado pendiente simplemente no existe.  ·  _(cap. 05)_
- **`sha256` / huella / fingerprint.** Un resumen corto y único de un texto. Si el texto cambia una coma, el resumen cambia entero. Así se detecta si alguien editó a mano.  ·  _(cap. 07)_
- **Shim / puente de compatibilidad.** Una función con el nombre viejo que responde con datos nuevos, para que el código que todavía llama al nombre viejo no se rompa. Aquí es `spar_get_user_points()`.  ·  _(cap. 10)_
- **`shop_manager`.** Rol de WordPress con permisos de tienda (`manage_woocommerce`) pero sin permisos de administración general (`manage_options`).  ·  _(cap. 15)_
- **Shortcode.** Un marcador entre corchetes que se pega en el editor de una página y que el código sustituye por contenido. `[akibara_series_directory]` y `[akibara_brand_family]` son dos de los de este capítulo. WordPress reemplaza por lo que devuelve una función. `[akibara_home_reviews]` es el del carrusel. (`[akibara_cart_blocks_title]`) y que WordPress sustituye por HTML al servirla.  ·  _(cap. 03, 11, 12, 19)_
- **Singleton.** Un objeto del que sólo puede existir una instancia en toda la petición. `Plugin` lo es.  ·  _(cap. 02)_
- **Sinónimo.** Una equivalencia que el dueño escribe para que dos formas de decir lo mismo encuentren lo mismo («shonen» = «shōnen»).
- **sitemap.** El índice en XML que se le entrega a Google con las URLs del sitio y su fecha de última modificación. descubran. Los genera Rank Math.  ·  _(cap. 07, 15)_
- **SKU.** El código único de un producto. En Akibara es el ISBN del tomo, y es la llave con la que se cruza el listado de la editorial.
- **SlotFill.** El mecanismo con el que WooCommerce deja que un plugin meta su propia interfaz en un hueco concreto del checkout. Así aparece la tarjeta del punto de retiro.  ·  _(cap. 12)_
- **Slug.** La parte legible de una URL (`gannibal-1`). El relleno de ceros toca el título y **no** el slug, a propósito.  ·  _(cap. 02)_
- **SMTP.** El protocolo con el que se envía correo. `smtp.hostinger.com` es el servidor que realmente lo manda.  ·  _(cap. 07, 15)_
- **Snapshot (de descuento).** La foto del aporte por línea que se sella dentro del pedido, para que el descuento se pueda reconstruir meses después aunque cambien los precios. la propia línea del pedido. **En este capítulo la palabra significa siempre esto y nada más.** Al código congelado de producción sobre el que está escrito el capítulo lo llamamos «el corte», nunca «el snapshot». este capítulo.  ·  _(cap. 03, 04, 07)_
- **SPAR.** *Simple Points and Rewards*, el plugin de terceros que hacía este trabajo antes del 30 de agosto de 2026.  ·  _(cap. 10)_
- **`srcset`.** Lista de versiones de una imagen en distintos tamaños que el navegador elige según la pantalla. Se construye aparte del `src`.  ·  _(cap. 14)_
- **Store API.** La interfaz que alimenta el carrito y el checkout de bloques de WooCommerce. El plugin cuelga sus datos ahí bajo el espacio `akibara-rewards`. plugin meta datos propios en una caja llamada `extensions`, y que registre un canal de escritura con nombre. (`/wp-json/wc/store/`). Distinta de la API REST de administración (`/wp-json/wc/v3/`). servidor.  ·  _(cap. 10, 12, 14, 15, 18, 19)_
- **Store API `…_order_processed`.** El instante exacto en que un pedido del checkout de bloques ya existe. `PhoneValidation` y `MarketingConsent` se cuelgan justo ahí. Importa que sea **el único** fiable: como el checkout de esta tienda es de bloques, los hooks clásicos del final del checkout (`woocommerce_checkout_order_processed`, `woocommerce_checkout_update_order_meta`) **no se emiten**, y quien se enganche ahí no se entera de ninguna compra ni recibe ningún error.  ·  _(cap. 05)_

## T

- **Tabla espejo.** `wp_akb_search_facets`: la copia de qué producto tiene qué valor en cada faceta, para poder contar y filtrar con un solo SELECT.  ·  _(cap. 09)_
- **Tabla sombra (`_new`).** Gemela que el rebuild llena a su ritmo mientras las vivas siguen sirviendo. y `_old` cuando queda como marcha atrás tras el intercambio.  ·  _(cap. 08, 17)_
- **Tablas sombra (`_new`).** Las tablas donde se construye el índice nuevo mientras el viejo sigue sirviendo, hasta el cambiazo.  ·  _(cap. 16)_
- **Taxonomía.** Una forma de clasificar contenidos. `pa_pais` es la taxonomía de atributo «país» que crea WooCommerce y donde viven los días de llegada. `pa_serie`, `product_brand` y `pa_autor` son las tres de este capítulo. Cada etiqueta concreta es un **término**, y cada término puede llevar datos propios: los **term meta**. De las tres, `pa_autor` es la única que **ninguna pieza de este capítulo construye**: su página `/autor/<slug>/` es el archive que WooCommerce genera solo para un atributo de producto, y aquí sólo aparece de dos formas — `SeriesFacts` **lee** sus términos para poner la fila «Autor …» del bloque de datos (`:127`, `:156-158`), y `edited_pa_autor` es uno de los eventos que invalidan ese bloque. Quién le pone título, descripción y schema a esa página es asunto del **capítulo 07 — SEO y schema** (`SeoEnhancements.php:320`, `:477-486`).  ·  _(cap. 02, 03)_
- **`term_id` y `term_taxonomy_id`: un término tiene DOS números.** Y confundirlos es el error más fácil de cometer en esta área. El `term_id` identifica **la etiqueta** («One Piece») en la tabla `wp_terms`. El `term_taxonomy_id` —abreviado **ttid**— identifica **esa etiqueta dentro de una taxonomía concreta** («One Piece, como serie») en `wp_term_taxonomy`. Casi siempre valen distinto, y no son intercambiables. En este capítulo: - Usan **ttid**: la clave del transient `akb_series_facts_v3_<generacion>_<ttid>` y la option `akb_series_facts_generation_<ttid>` (`SeriesFacts.php:140`), y el `serie_tt` que calcula el orden del catálogo. - Usan **`term_id`**: las relaciones que el dueño escribe entre términos (`_akb_serie_ediciones_relacionadas`, `akibara_edition_series`) y **la etiqueta de purga de LiteSpeed, `T.<term_id>`**. Por eso `SeriesFacts` resuelve los pares con una consulta antes de purgar (`:612-638`): el dato de invalidación le llega como ttid y la purga lo necesita como `term_id`. Para pasar de uno a otro: ```bash wp term get pa_serie <slug> --by=slug --field=term_id wp term get pa_serie <slug> --by=slug --field=term_taxonomy_id ```.  ·  _(cap. 03)_
- **Término.** Cada valor concreto de una taxonomía: «One Piece» es un término de la taxonomía «serie»; «Ivrea Argentina», uno de «marca».
- **Termmeta.** Un dato pegado a un término de taxonomía: aquí, a una editorial (`product_brand`) o a un país (`pa_pais`).  ·  _(cap. 04, 07, 09)_
- **Termmeta / postmeta / usermeta / ordermeta.** Datos sueltos colgados de un término (una serie), una entrada, un usuario o un pedido.  ·  _(cap. 18)_
- **TLD reservado.** Dominio de primer nivel que la RFC 2606 aparta para pruebas (`.test`, `.example`, `.invalid`, `.localhost`): por definición no existe nadie detrás.  ·  _(cap. 15)_
- **Token.** Una palabra del producto guardada aparte —del título, la serie, el autor, el SKU, una categoría o la descripción— para poder encontrarlo escribiendo un trozo. uso.  ·  _(cap. 08, 11, 19)_
- **Topología.** La foto de qué tablas existen ahora mismo. Cuatro valores: `clean_pre`, `ready_swap`, `swapped`, `ambiguous`. las `_new`, o además las `_old`. El plugin la clasifica con cuatro nombres: `clean_pre`, `ready_swap`, `swapped` y `ambiguous` (`Install.php:103-106`). **No es una fase**: no aparece dentro de la option.  ·  _(cap. 08, 17)_
- **Trabajador (*worker*).** El proceso que ejecuta una acción de la cola.  ·  _(cap. 16)_
- **Transacción / COMMIT.** Un grupo de escrituras que entra entero o no entra nada. `COMMIT` es la orden que lo confirma; hasta entonces, nadie más las ve.  ·  _(cap. 08)_
- **Transient.** Un dato de usar y tirar con fecha de caducidad; si se borra, se vuelve a calcular. redirect del guardado. calcular; si no está, se recalcula. un rato. uso**: se puede perder sin consecuencias. Físicamente vive dentro de `wp_options`, salvo que haya caché de objetos persistente, en cuyo caso no toca la base de datos.  ·  _(cap. 02, 03, 04, 05, 07, 08, 09, 11, 12, 14, 15, 17, 18, 19)_
- **Transitorio (transient).** Un dato guardado con fecha de caducidad. Se usa como memoria corta. sigue funcionando; solo pierde una optimización. **Salvo cuando se usa como memoria de «esto ya está agendado»**: entonces, si la acción desaparece y el transitorio no, el carril queda muerto hasta que el transitorio caduque. Ver **ventana ciega**.  ·  _(cap. 10, 16)_
- **TTL.** «time to live», cuánto tiempo una caché puede servir una copia guardada antes de tener que pedirla de nuevo.  ·  _(cap. 15, 17)_

## U

- **UCSS.** (*Unique CSS*) — la poda de hojas de estilo de LiteSpeed: genera un CSS recortado a lo que la página usa de verdad. Una clase que sólo aparece cuando el JavaScript la añade no está en el HTML que LiteSpeed mira, así que la poda se la lleva y el elemento sale sin estilos. El filtro `litespeed_ucss_whitelist` es donde cada módulo declara a mano las suyas para que eso no pase. **Hoy UCSS está apagado en producción**, y esas declaraciones son inertes. meter ciertos selectores en una lista blanca.  ·  _(cap. 03, 05)_
- **`unique`.** (de Action Scheduler) — Indicador que se pone al programar una tarea recurrente y significa «si ya hay una igual, no crees otra». Sin él, dos peticiones simultáneas pueden dejar dos tareas idénticas corriendo para siempre.  ·  _(cap. 14)_
- **Upsell.** El bloque «Productos recomendados» al pie de una ficha. No confundir con los *cross-sells*, que son los del carrito.  ·  _(cap. 05)_
- **Usermeta.** Un dato suelto pegado a una persona registrada (su cumpleaños, su saldo de puntos). Igual que el postmeta, pero de clientes.
- **UTC.** La hora de referencia mundial. Chile va tres o cuatro horas por detrás según la época del año.  ·  _(cap. 11)_

## V

- **Valla (fence).** Un cerrojo que impide escribir mientras el buscador se está poniendo al día. Si no consigue la valla, la escritura se rechaza en vez de mezclarse a medias.
- **Valla de propietario (*owner fence*).** La nota temporal que se deja cuando un pedido cambia de dueño, para seguir tirando durante unos días la caché de «qué tomos tiene comprados» del dueño viejo y del nuevo (§16).  ·  _(cap. 16)_
- **Válvula.** El aplazamiento con tope de la comprobación de precios mientras hay escrituras en vuelo.  ·  _(cap. 08)_
- **`Vary: Accept`.** Cabecera de respuesta que avisa a las cachés de que el contenido cambia según lo que el cliente pidió. Cloudflare no la respeta: sólo varía por `Accept-Encoding`.  ·  _(cap. 07)_
- **Ventana ciega.** El rato durante el cual un carril no se repara solo porque su memoria de «ya está agendado» sigue viva aunque el trabajo ya no exista. En los dos carriles de puntos dura **12 horas** y no hay nada que la corrija (§21, §22).  ·  _(cap. 16)_
- **viewport visual.** Lo que de verdad se ve en la pantalla del móvil cuando el teclado está abierto, que no es lo mismo que el tamaño de la ventana. Medir el correcto es lo que evita que una lista se abra por debajo del borde.  ·  _(cap. 12)_
- **Vigilante (*watchdog*).** Un trabajo recurrente cuyo único propósito es comprobar que otro no se haya quedado colgado, y repararlo.  ·  _(cap. 16)_

## W

- **wc/store/validation.** El almacén del navegador donde WooCommerce guarda los errores de validación del checkout. Mientras tenga **cualquier** clave con error, el botón de pagar no responde.  ·  _(cap. 12)_
- **`wc/v3`.** La versión actual de la API autenticada de WooCommerce, por donde Blue Express escribe los códigos de seguimiento.  ·  _(cap. 14)_
- **`wc_ajax_` frente a `wp_ajax_`.** Las dos formas de registrar una petición de este tipo. `wc_ajax_<acción>` es de WooCommerce, atiende a todo el mundo —con sesión y sin ella— y por eso **no** tiene variante `nopriv`; su URL es `/?wc-ajax=<acción>`. `wp_ajax_<acción>` es de WordPress y atiende **sólo** a quien tiene sesión: para atender también a un anónimo hay que registrar además `wp_ajax_nopriv_<acción>`; su URL es `/wp-admin/admin-ajax.php?action=<acción>`.  ·  _(cap. 05)_
- **WP-CLI.** La consola de WordPress. `wp akibara access-check` es uno de sus comandos.  ·  _(cap. 15)_
- **WP-Cron.** El sistema de tareas programadas del propio WordPress, cuyas entradas viven en `wp_options` y se consultan con `wp_next_scheduled()`. tarea de hPanel cada 5 minutos. petición que la ejecuta muere, el trabajo se pierde. 5 minutos, porque `DISABLE_WP_CRON` está puesto.  ·  _(cap. 02, 15, 16, 17, 19)_
- **`$wp_filter`.** La tabla global donde WordPress guarda quién escucha cada hook. El buscador la inspecciona directamente para verificar que business está enganchado exactamente como debe.  ·  _(cap. 19)_
- **`wp_kses_post()`.** El filtro de WordPress que limpia HTML dejando sólo lo que considera seguro. Entre otras cosas **borra** de los atributos `style` cualquier propiedad que no esté en su lista blanca (`safecss_filter_attr()`), y `-webkit-text-fill-color` no lo está.  ·  _(cap. 07)_
- **`wp_mail()`.** La función de WordPress que todo el mundo llama para mandar un correo. Es *pluggable*: cualquiera puede interceptarla.  ·  _(cap. 15)_
- **`wp_mail_succeeded` / `wp_mail_failed`.** Las dos acciones que WordPress dispara después de intentar el envío, según cómo haya ido.  ·  _(cap. 15)_
- **`wp_mail`.** La función por la que pasa **todo** el correo de WordPress y de WooCommerce.  ·  _(cap. 18)_
- **`WP_Term::count`.** El contador nativo de productos de un término. **Incluye** los productos ocultos del catálogo, y por eso casi toda el área usa `SeriesCatalogEligibility` en su lugar.  ·  _(cap. 03)_

## Símbolos y números

- **`-webkit-text-fill-color`.** Propiedad de Safari y Chrome que pinta el **texto** (no el fondo) y que **le gana a `color`**. Si sólo se cambia `color`, WebKit sigue pintando el valor viejo. Es la causa del «texto rojo sobre fondo rojo» de los botones de correo.  ·  _(cap. 07)_
- **503.** El código con el que un servidor contesta «ahora no puedo, vuelve luego». El cliente no ve un error feo: el desplegable simplemente no aparece y el catálogo se pinta sin filtros. Lo contrario es **200**, «aquí tienes».  ·  _(cap. 08)_
