# Crítica del capítulo 15 — `15-mu-plugins-correo-litespeed-claves-y-guardas.md`

> Quién escribe: lector novato (no sé WordPress, no sé WooCommerce, no conozco el negocio).
> Leí el capítulo entero y sus siete diagramas, y después abrí el código del snapshot
> (`scratchpad/snapshot-prod/mu-plugins/`), el árbol local, LiteSpeed 7.9.1, el núcleo de
> WordPress, los guiones de `ops/` y la ficha abierta del incidente.
> Cada punto lleva **la frase del capítulo** y **qué debería decir**.

---

## 1. Lo que NO entendí (10 puntos)

**1.1 · El glosario está al final y nadie me avisa.**
El capítulo usa «cepo», «cartero», «centinela», «guard», «ability», «fail-closed» y «TTL»
desde la página 1, y los define en la última sección.
*Debería decir*, justo después del párrafo de alcance: «Si un término no te suena, está
definido en el **Glosario del capítulo**, al final», con enlace. O mover el glosario arriba.

**1.2 · «los consumidores reales cortocircuitan antes de llamarlo».**
Frase exacta: *«Los dos filtros de arriba actúan dentro de `Root::conf()`, pero los consumidores
reales cortocircuitan **antes** de llamarlo.»* No entiendo qué cortocircuita ni por qué eso
derrota a un filtro.
*Debería decir*: «LiteSpeed escribe `defined('LITESPEED_GUEST_OPTM') || $this->conf(...)`. En PHP,
si la parte izquierda de un `||` ya es verdadera, la derecha **no se ejecuta**: LiteSpeed nunca
llega a preguntar la opción, así que nuestro filtro nunca corre. Por eso hace falta una tercera
baranda que apague la propia constante desactivando `guest_optm`.»

**1.3 · Cómo se llama un filtro de LiteSpeed.**
Frase: *«todos enganchados a `litespeed_conf_load_option_{id}`»* y después el aviso de que
`guest_optm` lleva guion bajo. No entiendo de dónde sale ese nombre ni por qué un filtro «gana»
a una casilla marcada en un panel.
*Debería decir*: «LiteSpeed guarda cada opción con un identificador (`optm-css_comb`,
`guest_optm`). Cada vez que **lee** una, dispara un filtro cuyo nombre es literalmente
`litespeed_conf_load_option_` + ese identificador (`root.cls.php:322-338`, llamado desde
`root.cls.php:444`). Como devolvemos un valor desde ahí, lo guardado en la base de datos deja de
importar: la casilla puede estar encendida y el efecto no llega nunca al HTML. El identificador se
concatena tal cual, así que escribirlo con un guion en vez de un guion bajo deja el filtro mudo
sin dar ningún error.»

**1.4 · «ability» y «MCP»: no se dice quién llama a esas operaciones.**
La pieza 9 se titula «las redirecciones por MCP» y el Caso 5 empieza con *«El asistente pide
`akibara/redirections-snapshot`»*. No sé qué asistente, cómo se conecta, ni si esto está encendido
hoy en producción.
*Debería decir*: qué cliente MCP se conecta (el mismo de `mcp__rank-math-production`, según
`ops/`), con qué credenciales, y si las cinco abilities están vivas en producción ahora mismo o
sólo cuando Rank Math carga su autoloader.

**1.5 · «formato Jed».**
Frase: *«el payload de traducciones llega como JSON serializado (formato Jed)»*. No sé qué es Jed
y el glosario no lo trae.
*Debería decir*: «un JSON con la forma `{"locale_data":{"messages":{"texto original":["traducción"]}}}`,
que es como WordPress entrega las traducciones al JavaScript», y citar `akibara-woo-blocks-i18n.php:51-63`,
que es exactamente esa ruta.

**1.6 · «downgrade silencioso» se usa como si ya lo supiera.**
Aparece tres veces («Así nació el *downgrade silencioso* del cron viejo de hPanel») antes de
cualquier definición.
*Debería decir* la primera vez: «bajar una versión sin que nadie se entere: el cron viejo copiaba
encima una copia congelada en la 1.0.0 sobre la 1.1.0 ya instalada, y su única salida era una
línea en un log que sólo se ve por SSH» (está en `akibara-mail-transport-watch.php:29-33`).

**1.7 · «<padre del docroot>» no es una ruta.**
Aparece tres veces («Copia de rescate: `<padre del docroot>/akb-runtime/akibara-correo-smtp.php`»).
No sé cuál es el docroot ni puedo comprobar nada.
*Debería decir* la ruta real: `~/domains/akibara.cl/akb-runtime/akibara-correo-smtp.php`
(`ops/akibara-prod-muplugins-deploy.sh:26`), y explicar que el centinela la deriva de `ABSPATH`
(`akibara-mail-transport-watch.php:194-205`), no de esa variable del guion.

**1.8 · «Hoy no sale nada a internet, pero por accidente de configuración» choca con el Caso 3.**
La pieza 2 dice que en el espejo el correo no sale «por accidente»; tres secciones después el Caso 3
dice que el correo **sí sale**, hacia Mailpit en `localhost:8025`. No pueden ser las dos.
*Debería decir*: que ese «accidente» era el estado antiguo —el contenedor entregaba por `mail()`
contra `localhost:25`, tal como dice el comentario del código (`akibara-correo-no-sale-a-clientes.php:11`)—
y que **hoy ya no es así**, porque `wp-config.php:42-46` apunta el transporte a Mailpit. El
guardarraíl sigue siendo necesario por las otras dos razones (refrescar la base con credenciales
armadas, conectar Brevo), no por ésa.

**1.9 · «Estos ficheros no tienen interruptor: se apagan borrándolos» — ¿quién los borra y cómo?**
No se dice si eso se hace por SSH, por el panel de Hostinger o por FTP, ni que `DISALLOW_FILE_MODS`
está en `true` en el espejo (`wp-config.php:34`).
*Debería decir*: «Se borran por SSH (`ssh akibara`) o desde el administrador de archivos de hPanel.
Desde el panel de WordPress no se puede: la carpeta `mu-plugins` no aparece en la lista de plugins.»

**1.10 · «seis filas de la pantalla Claves y accesos» contra «las siete entradas del catálogo».**
La pieza 1 dice que el dueño ve *«las seis filas de la pantalla “Claves y accesos”»*; la pieza 4
publica una tabla con **siete** entradas y el Caso 4 habla de *«las siete entradas del catálogo»*.
*Debería decir*: «seis de las siete filas son del correo; la séptima es la de Google Maps», una
vez, la primera vez que aparece el número.

---

## 2. Lo que FALTA para operar o depurar esto sin ayuda (16 puntos)

**2.1 · La tarea de cron de hPanel: el capítulo la llama «el único latido» y no dice dónde está.**
Frase: *«el único latido que funciona cuando nadie abre el admin es el cron de hPanel, que es
externo a WordPress y nadie comprueba desde dentro»*. Se da el uid de la tarea **retirada**
(`5WxqXVQ5zM`) y ninguno de la viva.
*Falta*: la ruta exacta en hPanel (menú y submenú), el uid o el comando de la tarea actual, su
frecuencia declarada ahí, y una frase de «cómo compruebo hoy que sigue viva». Es el dato con más
consecuencia del capítulo y es el único que no está.

**2.2 · Los dos mu-plugins de Hostinger que viven en la misma carpeta no aparecen en ningún capítulo.**
`ls public_html/wp-content/mu-plugins` devuelve, además de los 21 `akibara-*`, dos ficheros de
terceros: `hostinger-auto-updates.php` y `hostinger-preview-domain.php` (más una carpeta `assets/`).
No están en el capítulo 15 ni en el 14 (comprobado con `grep` sobre los dos).
*Falta*: nombrarlos. Y no es cosmético: según `ops/coordinacion/abiertos/2026-09-08-correo-repuesto-tres-domingos-causa-pendiente.md`,
esos dos son **los únicos ficheros del directorio que se reescriben la noche del borrado**
(`2026-09-06 23:14:31` y `23:14:34`, o sea 32 y 29 segundos antes de nuestra reposición de las
`23:15:03`). Son la evidencia central del incidente que este mismo capítulo narra.

**2.3 · La hipótesis de mecanismo del incidente, y su predicción con fecha, no están.**
El capítulo cierra el incidente con *«no identificado»* y *«ni el cron de WordPress, ni los guiones
de operaciones, ni las tareas programadas del Mac explican el patrón dominical»*, y luego dice que
el renombrado se hizo «como prueba» — sin decir prueba **de qué**.
*Falta*: (a) el mecanismo plausible —una pasada de la plataforma que gestiona `*hostinger*` dentro
de `mu-plugins` y retira lo que no está en su manifiesto; nuestro fichero era el único otro del
directorio con «hostinger» en el nombre—; (b) la predicción falsable que la ficha deja escrita:
**domingo 2026-09-13, ~23:17-23:20 UTC**, los dos `hostinger-*.php` se reescriben y el nuestro
sobrevive si la regla era el nombre. Hoy es 12 de septiembre: quien lea este capítulo tiene un día
para prepararse y no se entera.

**2.4 · La pantalla «Claves y accesos» no tiene ruta.**
Frase: *«Submenú de `akibara-panel`, capacidad `manage_options`»*. No hay URL.
*Falta*: `/wp-admin/admin.php?page=akibara-provider-access` (slug en `akibara-provider-access.php:501`),
y el aviso de que el menú padre se llama `akibara-panel`, no `akibara`.

**2.5 · No hay ni un comando para leer las dos options que el capítulo declara centrales.**
El capítulo dice del centinela: *«Dónde lo opera el dueño. En ningún sitio: no tiene pantalla ni
comando»*, y deja al lector sin forma de mirar el estado.
*Falta*: `wp option get akibara_mail_transport_watch --format=json` y
`wp option get akibara_mail_transport_outage --format=json`, con la nota de que en producción se
corre tras `ssh akibara` y que son options sin autoload, así que no salen en un volcado de
`alloptions`.

**2.6 · El aviso amarillo «no se apaga nunca» y no se da la salida manual.**
Frase: *«no hay ninguna ruta —ni option, ni comando, ni pantalla— que vacíe `restores`»*.
*Falta*: decir que sí hay una salida por consola (`wp option delete akibara_mail_transport_watch`,
o vaciar sólo `restores` con `wp option patch`), **y el aviso de que hacerlo borra la evidencia del
incidente abierto**. Un lector sin esa frase o vive con el amarillo para siempre o lo borra sin
saber qué se llevó por delante.

**2.7 · Lo mismo con el aviso rojo.**
Frase: *«Ese aviso no se puede descartar: desaparece solo cuando un correo vuelva a salir de verdad.»*
Si el buzón está caído de verdad, el dueño se queda con el rojo y sin salida documentada.
*Falta*: `wp option delete akibara_mail_transport_outage` y la advertencia de que eso apaga la
alarma sin arreglar nada — que es exactamente el fallo que la versión 1.1.0 tenía y que el propio
capítulo cuenta en «Dos decisiones de diseño».

**2.8 · Cómo se comprueba a mano que el correo de producción está sano.**
El capítulo describe las cinco comprobaciones de `ops/akibara-prod-mail-health.sh`
(`env=production`, `enabled=true`, `hook=true`, `transport=smtp`, `smtp=auth-ok`) pero nunca dice
cómo se ejecuta.
*Falta*: la línea (`bash ops/akibara-prod-mail-health.sh`), desde dónde se corre, que necesita
`ssh akibara` funcionando, y que **no envía nada** — lo que la hace segura fuera de la ventana de QA.

**2.9 · `AKIBARA_CORREO_CAPTURA`: se dice que es la palanca y no dónde se pone.**
Frase: *«Se puede cambiar con la constante o la variable de entorno `AKIBARA_CORREO_CAPTURA`»*.
*Falta*: en qué fichero (`public_html/wp-config.php`) y en qué servicio de `compose.yaml` se
declara la variable de entorno, y que hay que reiniciar el contenedor para que tome efecto.

**2.10 · Los asuntos de los correos del Caso 1 no llevan fuente ni pantalla.**
Frase: *«El cliente recibe el correo con asunto “Tu pedido en Akibara ha sido recibido”»* y *«si
WooCommerce lo tiene configurado, “Nuevo pedido” al buzón de la tienda»*. Es el único dato del
capítulo sin `fichero:línea`, y además queda ambiguo: el capítulo acaba de decir que cambiar el
remitente en WooCommerce → Correos electrónicos **no hace nada**.
*Falta*: la ruta (`WooCommerce → Ajustes → Correos electrónicos`), de dónde sale cada asunto, y una
frase que separe lo editable (asunto, encabezado, destinatario del aviso de tienda) de lo que el
transporte pisa (remitente y `Sender`).

**2.11 · `Access::is_configured()` no se menciona, y es la salida oficial de la excepción.**
El capítulo dice: *«`get()` **lanza una excepción** para toda clave con `owner_file` declarado»*.
El mensaje de esa excepción (`akibara-provider-access.php:304`) termina literalmente en
«Usa `is_configured()`».
*Falta*: nombrar `Access::is_configured()` (`:350`) como el método permitido, y decir que
`must()` (`:326-330`) es el que lanza `MissingProviderAccess`, mientras `get()` lanza
`\DomainException` (`:302`) y `Registry::entry()` lanza `\InvalidArgumentException` (`:205`).
Hoy el capítulo lista `MissingProviderAccess` en la cabecera de la pieza y nunca dice quién la tira.

**2.12 · Las dos palancas de la rotación del log están ocultas detrás de dos números.**
Frase: *«Rota `debug.log` si pasa de 2 MB, conservando 3 generaciones»*.
*Falta*: nombrar las constantes que fijan esos números —`AKIBARA_DEBUG_LOG_MAX_BYTES`
(`akibara-debug-log-rotation.php:16`) y `AKIBARA_DEBUG_LOG_KEEP` (`:19`)— y la tercera función
global del fichero, `akibara_debug_log_path()` (`:26`), que decide **qué** log se rota.

**2.13 · No hay forma de verificar las barandas de LiteSpeed contra la superficie servida.**
El capítulo prueba su necesidad con mediciones de agosto y septiembre, pero no deja ninguna
comprobación repetible.
*Falta*: cómo confirmar hoy que `/wp-json/wc/store/` no se cachea (una petición con sesión mirando
`x-litespeed-cache`) y que la portada no trae un CSS combinado. Y una nota de mantenimiento: los
cuatro identificadores (`optm-css_comb`, `optm-js_comb`, `guest_optm`, `cache-exc`) están escritos a
mano; si LiteSpeed los renombra en una actualización, los filtros quedan mudos **sin error**. El
propio capítulo ya demuestra que sus citas a LiteSpeed caducan.

**2.14 · El «despliegue pendiente» de `akibara-discovery-warming.php` no dice cómo se despliega.**
Frase: *«no es un arreglo pendiente sino un **despliegue** pendiente»*.
*Falta*: con qué guion (`ops/akibara-prod-muplugins-deploy.sh`), y —dato que cambia la decisión— que
ese guion sube **toda** la carpeta (`:43`, `ls *.php` menos la cuarentena), no un fichero suelto:
desplegar esa línea arrastra cualquier otro cambio que haya en el árbol local.

**2.15 · El capítulo no dice qué capítulo cubre los otros nueve mu-plugins de producción.**
El `ls` del snapshot devuelve **18** ficheros; el capítulo explica nueve.
*Falta*: una línea en «Alcance»: «los otros nueve están en el capítulo 14».

**2.16 · El transporte borra la configuración SMTP de cualquier otro plugin y no se dice.**
El capítulo resume `apply_configuration()` como «fija host, puerto, cifrado, usuario, contraseña,
`Timeout = 20`». El código también hace `SMTPDebug = 0`, `SMTPOptions = array()`, `AuthType = ''`
y `SMTPKeepAlive = false` (`akibara-correo-smtp.php:380-384`), en `PHP_INT_MAX`, o sea el último.
*Falta*: decirlo. Es la respuesta a «instalé un plugin de SMTP y no hace nada».

---

## 3. Lo que CONTRADICE al código (10 puntos)

**3.1 · «Cinco constantes» y a continuación seis nombres, con la cita equivocada.**
Frase: *«**Qué datos lee.** Cinco constantes de `wp-config.php`, nunca de la base de datos
(`:416-422`): `AKIBARA_HOSTINGER_MAIL_ENABLED`, `AKIBARA_HOSTINGER_SMTP_USERNAME`, … `_ENCRYPTION`.»*
Son **seis** nombres, y `:416-422` (`akibara-correo-smtp.php`) lee sólo cinco: host, puerto,
cifrado, usuario y contraseña. `AKIBARA_HOSTINGER_MAIL_ENABLED` se lee en otro sitio,
`akibara-correo-smtp.php:405-408`.
*Debería decir*: «Seis constantes. Cinco las lee `validated_configuration()` (`:416-422`) y la
sexta, el interruptor, `is_enabled()` (`:405-408`).»

**3.2 · «Cuatro enganches» del guardarraíl de IndexNow, y la tabla trae cinco.**
Frase: *«**En qué momento actúa.** Cuatro enganches, todos en prioridad `PHP_INT_MAX`, todos con la
misma única condición —`wp_get_environment_type()`— y todos devolviendo el valor original en
producción»*, seguida de una tabla de **cinco** filas. El código tiene cinco `add_filter`
(`akibara-local-external-indexing-guard.php:30, 35, 58, 82, 115`). Y las otras dos afirmaciones
también fallan: `akibara_external_indexing_global_option_guard()` exige además que la opción sea
`rank_math_indexnow_log` (`:75`), y `akibara_external_indexing_http_guard()` exige además que el
host sea `api.indexnow.org` (`:105`) y **no devuelve el valor original**: devuelve un `WP_Error`
(`:109-112`). El mismo «cuatro» se repite en «Reglas de negocio» («Está en código, con cuatro capas»).
*Debería decir*: «Cinco enganches», y separar «las dos capas de Rank Math» / «las dos capas de la
option» / «la capa HTTP, que además comprueba el host y corta con un `WP_Error`».

**3.3 · «Nueve de los doce ficheros declaran funciones y constantes en el espacio global»: son siete.**
La propia enumeración del capítulo nombra siete ficheros (`akibara_correo_*`,
`akibara_litespeed_rutas_sin_cache`, `akibara_woo_blocks_*`, `akibara_rm_*`, `akibara_debug_log_*`,
`akibara_local_cron_spawn_request`, `akibara_external_indexing_*`). Los dos que faltan para llegar a
nueve —`akibara-discovery-warming.php` y `akibara-https-urls-fuera-de-web.php`— **no declaran nada
global**: usan funciones anónimas dentro del `add_filter` (`discovery-warming.php:27-44`,
`https-urls-fuera-de-web.php:15-38`).
*Debería decir*: «Siete de los doce… Otros dos evitan el problema por construcción, usando funciones
anónimas; los tres restantes son clases.»

**3.4 · `akibara_debug_log_*` «(2)»: son tres funciones y además dos constantes.**
`akibara-debug-log-rotation.php` declara `akibara_debug_log_path()` (`:26`),
`akibara_debug_log_maybe_rotate()` (`:46`) y `akibara_capture_fatal_to_own_channel()` (`:92`) —esta
última no lleva el prefijo `akibara_debug_log_`, y por eso se le escapó a la cuenta— más
`const AKIBARA_DEBUG_LOG_MAX_BYTES` (`:16`) y `const AKIBARA_DEBUG_LOG_KEEP` (`:19`).

**3.5 · `akibara_rm_*` «(5)»: falta una sexta función global.**
`akibara-rankmath-redirections-abilities.php` declara cinco `akibara_rm_redirections_*`
(`:25, 35, 53, 75, 94`) **y** `akibara_register_focus_keyword_ability()` (`:166`), que es global
igual y no lleva el prefijo. Si el argumento del capítulo es «una copia del fichero fatalea», la
lista tiene que ser exhaustiva o no sirve.

**3.6 · «Las tres piezas basadas en clase … están a salvo porque PHP no permite redeclarar una clase
en un espacio de nombres sin avisar antes».**
Esto no es cierto, y es justo al revés de lo que hace falta saber: redeclarar una clase en PHP es un
error **fatal**, exactamente igual que redeclarar una función. El transporte
(`akibara-correo-smtp.php:21`, `final class Transport`) es además **el fichero que el centinela copia
por su cuenta dentro de `mu-plugins`** (`akibara-mail-transport-watch.php:174`), o sea el que más
expuesto está a acabar duplicado.
*Debería decir*: «Ninguna de las doce piezas está a salvo: dos copias de cualquiera de ellas dentro
de `mu-plugins` tumban el sitio entero. El riesgo es mayor en el transporte, porque es el único que
otro proceso copia solo. El día del renombrado (2026-09-11) la ventana existió de verdad.»

**3.7 · «el `pre_update_option` de Google Maps lo esquiva entero un `wp option update`».**
Falso. `update_option()` dispara `pre_update_option_{$option}` en
`public_html/wp-includes/option.php:901`, y `wp option update` llama a `update_option()`. El filtro
del módulo está en
`plugins/akibara-business/src/Modules/AddressAutocomplete/AddressAutocomplete.php:32` y **sí** corre
por esa vía. El capítulo repite tal cual un comentario equivocado del propio código
(`akibara-provider-access.php:277-279`).
*Debería decir*: la revalidación en lectura sigue estando bien justificada, pero por otras rutas —
`wp option add` (que dispara `add_option_*`, no `pre_update_option_*`), un `wp db query` directo, o
un valor escrito antes de que el módulo existiera— y anotarlo como deuda del comentario, que es lo
que la regla de la casa pide.

**3.8 · «cortocircuita **al menos ocho** lugares» y la lista trae nueve, acotada a un fichero sin decirlo.**
La enumeración del capítulo es `:73`, `:79`, `:85`, `:114`, `:245`, `:278`, `:279`, `:280`, `:281`:
**nueve**. Y sólo cubre `optimize.cls.php`: la constante también cortocircuita en
`media.cls.php:823,832-836,1030,1293,1298`, `optimizer.cls.php:85,261`,
`placeholder.cls.php:118,120,263`, `core.cls.php:264` y `optimize.cls.php:282-284,317,524,678,1220`.
*Debería decir*: «nueve lugares sólo dentro de `optimize.cls.php`, y más de veinte en todo el
plugin», que además refuerza el argumento de la baranda.

**3.9 · Las dos líneas de `pluggable.php` están mal.**
Frase: *«(`wp-includes/pluggable.php:210` y `:222` aproximadamente, el `apply_filters( 'wp_mail', … )`
precede al `apply_filters( 'pre_wp_mail', … )`)»*. Las líneas reales en este árbol son **209** y
**233**. Y el `explode( ':', trim( $header ), 2 )` que el capítulo cita como `pluggable.php:298-306`
no está ahí: `:298-306` es la rama `is_array()`; el bucle empieza más abajo.
*Debería decir* las líneas exactas y sin «aproximadamente»: en un capítulo donde todo lo demás lleva
línea verificable, una cita estimada enseña a no comprobar.

**3.10 · El «accidente» del espejo ya no es el que el capítulo describe.**
Ver 1.8: el capítulo hereda el comentario de `akibara-correo-no-sale-a-clientes.php:10-11` («el
transporte del contenedor es `mail` contra `localhost:25`») y lo presenta en presente, cuando
`wp-config.php:42-46` lleva el correo del espejo a Mailpit. Es una contradicción interna entre la
pieza 2 y el Caso 3.

---

## 4. Lo que contradice a `verificacion.json` del área (3 puntos)

> Fuente: `scratchpad/mapas-b/mu-plugins-b/verificacion.json` (`cobertura_estimada: 92`).
> El capítulo incorporó correctamente las 8 omisiones, los 7 errores, las 12 sospechas confirmadas y
> las 6 refutadas. Lo que no trasladó es **el estado de verificación**.

**4.1 · Las tres reposiciones dominicales se presentan como hecho medido, y la verificación dice que
no se midieron.**
Frase del capítulo: *«**Lo que hay ahí hoy en producción.** Tres reposiciones: `2026-08-23 23:10:38`,
`2026-08-30 23:12:18` y `2026-09-06 23:15:03` UTC.»* — en negrita, sin matiz.
`verificacion.json` → `notas`: *«Sin medir en producción: el contenido real de
`akibara_mail_transport_watch` (las tres reposiciones dominicales) … así que las afirmaciones del
mapa sobre esos dos puntos quedan sin verificar por mí.»*
*Debería decir*: de dónde sale el dato (la ficha
`ops/coordinacion/abiertos/2026-09-08-…md`, que sí lo midió y además lo cruzó con `ctime`/`mtime` y
el sha256 `92ba7d…`), o marcarlo como «leído de la ficha del incidente, no releído contra la option».

**4.2 · Los defectos del espejo se cuentan como observados y sólo están razonados.**
El Caso 3 dice: *«para todo correo de WooCommerce, el cuerpo llega como **HTML crudo sin renderizar
y sin `Reply-to`**»*, y la pieza 2 lo titula *«Dos defectos vivos»*. `verificacion.json` marca V.1
como sospecha **nueva** deducida de leer `pluggable.php` y `WC_Email::get_headers()`, y en `notas`
avisa: *«NO ejecutado en el espejo Docker: todas las verificaciones propuestas están escritas pero
sin correr (tarea de sólo lectura).»*
*Debería decir*: «deducido del código, **sin ejecutar en el espejo**», con la comprobación de una
línea que lo confirmaría (mandar un correo de pedido en Docker y mirar el `Content-Type` en Mailpit).
La regla del dueño es exactamente ésta: primero local, después producción, y hasta que no está
medido no está aprobado.

**4.3 · El capítulo no lleva ninguna marca de cobertura ni de puntos ciegos.**
`verificacion.json` cierra con `cobertura_estimada: 92` y nombra los dos ojos que faltan: «el espejo
y producción». El capítulo no tiene una sola frase que distinga «leído» de «medido», y mezcla en el
mismo tono mediciones fechadas (el carrito cacheado del 2026-09-06, la caída de la portada del
2026-08-15) con deducciones nunca ejecutadas (V.1, V.2, V.4, V.5, V.8).
*Debería decir*: una marca por afirmación, o al menos una sección «Qué está medido y qué está
deducido» con las dos listas.

---

## 5. Diagramas (9 puntos)

**Validación con `mmdc`: los siete `.mmd` compilan sin error.** Ninguno tiene leyenda de colores,
pese a que `piezas.mmd` define cuatro clases (`propio`, `tercero`, `persona`, `dato`) que un novato
no puede interpretar.

**5.1 · `piezas.mmd` — falta el `akibara-panel` que el texto convierte en la bisagra del capítulo.**
El texto dedica un párrafo a que «Claves y accesos» cuelga de `akibara-panel`, registrado por Akibara
Business con `manage_woocommerce` mientras el hijo exige `manage_options`. En el diagrama
`INVENTARIO --> DUENO` va directo: no hay nodo del panel, ni de Business, ni pista de las dos
capacidades.
*Debería*: añadir un nodo `akibara-panel (Akibara Business)` entre `INVENTARIO` y `DUENO`, y
etiquetar las flechas con la capacidad que exige cada aviso.

**5.2 · `piezas.mmd` — la flecha `HTTPS --> CARTERO` inventa una relación que no existe.**
`akibara-https-urls-fuera-de-web.php` filtra `set_url_scheme`; no habla con el transporte por ningún
lado. Lo que arregla son las **imágenes dentro del cuerpo** de los correos generados fuera de una
petición web.
*Debería*: `HTTPS -. "arregla las imágenes del cuerpo" .-> WOO`, o un nodo «Action Scheduler / WP-CLI».

**5.3 · `piezas.mmd` — falta el gate del despliegue, que el texto llama así.**
El texto: *«`wp akibara access-check` … **el gate del despliegue**»*, y le dedica el Caso 4 entero.
En el diagrama no hay ni despliegue ni gate: `INVENTARIO` sólo tiene una flecha punteada
«comprueba que el fichero exista».
*Debería*: un nodo `ops/akibara-prod-muplugins-deploy.sh` con flechas a `INVENTARIO` (gate),
a `RESCATE` (mantiene la copia) y a `ops/akibara-prod-mail-health.sh`.

**5.4 · `cron.mmd` — inventa un horario que no está en el texto.**
La fila «Cualquier visita a wp-admin despierta Watch inspect por admin_init» va de `09:00` durante
`14h`. El capítulo no dice en ninguna parte que el admin se use entre las 9 y las 23; al contrario,
su escenario de riesgo es *«el dueño pasa tres días sin abrir el admin»*.
*Debería*: o marcar esa fila como «cuando alguien entra — no hay horario» (un hito, no una barra), o
quitarla.

**5.5 · `cron.mmd` — mezcla hora de Chile y UTC en la misma línea.**
El título dice «en hora de Chile» y el hito dice literalmente «domingos 23 y 30 ago y 6 sep, **23 UTC**»
mientras se coloca en las **20:15**. Un novato no sabe cuál de las dos horas leer.
*Debería*: «domingos, 20:15 hora de Chile (23:15 UTC)».

**5.6 · `cron.mmd` — el gantt dice «corre todo el día» donde el texto dice «cada 5 minutos».**
Una barra continua de 24 h es exactamente lo contrario de un latido cada 5 minutos, y el formato
gantt no puede representarlo. Además el diagrama muestra cuatro entradas cuando la tabla «Lo
programado» del texto tiene **seis** (faltan los avisos del admin, la captura de fatales y la sonda
de salud del despliegue).
*Debería*: cambiar de forma —una tabla ya está en el texto, o un diagrama de secuencia con el ciclo
de 5 minutos— o, si se conserva el gantt, poner «cada 5 min» dentro de la etiqueta y completar las
seis filas.

**5.7 · `estados-centinela.mmd` — el estado «Ausente» contradice una de sus propias salidas.**
Del estado llamado `Ausente` sale la transición *«el fichero existe pero la clase no cargó»*. Si el
fichero existe, no está ausente. El estado que el código modela (`transport_is_live()`,
`akibara-mail-transport-watch.php:120-129`) es «el transporte no está vivo», que es otra cosa.
*Debería*: renombrar `Ausente` a `Transporte no vivo` y dejar «fichero ausente» / «fichero presente»
como la primera bifurcación dentro.

**5.8 · `estados-centinela.mmd` — falta la transición que el propio texto marca como trampa viva.**
El texto dice: *«Repone sin comprobar qué repone … `copy()` devuelve verdadero también para un
fichero de 0 bytes»*. El diagrama sólo permite `Repuesto --> SanoConHistorial`. Si lo repuesto está
truncado, la siguiente inspección ve el fichero presente y la clase sin cargar: `Repuesto --> Inerte`,
con una fila ya sumada a `restores`.
*Debería*: añadir esa arista, etiquetada «la copia estaba truncada o vacía».

**5.9 · Los tres diagramas de caso omiten justo la bifurcación que su texto destaca.**
- `caso-correo-de-pedido.mmd`: no tiene la «Bifurcación C» (`wp_mail_failed` sin ningún destinatario
  válido, que enciende el rojo con un texto falso), que el texto marca como *«poco intuitiva y viva
  hoy»*. Y dibuja `Cartero->>Dueno: aviso rojo` como si fuera un empujón, cuando el aviso sólo se
  pinta la próxima vez que alguien con `manage_woocommerce` abra el admin.
- `caso-espejo-captura.mmd`: no tiene la «Bifurcación silenciosa adicional» (quitar
  `AKIBARA_HOSTINGER_MAIL_ENABLED` en local → el guard no corta, no deja option y no avisa), que el
  texto sí describe en ese mismo caso.
- `caso-gate-del-despliegue.mmd`: sólo dibuja el camino feliz de `Health`. Faltan la salida con
  código 1 cuando falla cualquiera de las cinco comprobaciones y el paso 6 del texto (el repaso del
  log transaccional de las últimas 48 h, que decide si frena o sólo informa).

---

## Resumen

| Categoría | Puntos |
|---|---|
| 1 · No entendí | 10 |
| 2 · Falta para operar o depurar | 16 |
| 3 · Contradice al código | 10 |
| 4 · Contradice `verificacion.json` | 3 |
| 5 · Diagramas | 9 |
| **Total** | **48** |

Los tres que arreglaría primero: **2.1/2.2/2.3** (la tarea de hPanel, los dos mu-plugins de
Hostinger y la predicción del domingo 13 — el incidente que el capítulo narra se decide mañana),
**3.6** (la afirmación de que las clases «están a salvo» es falsa y desactiva la única defensa que
tendría un lector) y **4.3** (sin separar «medido» de «deducido», el capítulo entero se lee como si
todo estuviera comprobado en producción, y no lo está).

---

## Respuesta del redactor

Aplicados **46 de los 48 puntos**. Dos se aplicaron *corrigiendo la corrección*, porque al comprobarlos contra el
código el crítico también se quedaba corto o se pasaba de largo. Queda constancia aquí, no en el capítulo.

**2.14 — «ese guion sube *toda* la carpeta (`:43`), no un fichero suelto». Parcialmente incorrecto, y el capítulo
dice ahora lo que el guion hace de verdad.** `ops/akibara-prod-muplugins-deploy.sh` tiene **tres** modos, y sólo
uno de los tres es el que describe la crítica:

- **sin argumentos** (`:47`): sube exactamente dos ficheros, `FILES=( "$TRANSPORT" akibara-mail-transport-watch.php )`
  — el transporte y el centinela. No toca nada más.
- **`--all`** (`:43`): ahí sí, `ls *.php` menos la cuarentena. Es el modo que el crítico describe, y su advertencia
  es correcta **para ese modo**: arrastraría cualquier trabajo a medias del árbol local.
- **con nombres de fichero** (`:44-45`): `FILES=( "$@" )`. Se puede desplegar `akibara-discovery-warming.php`
  solo, y es lo que el capítulo recomienda ahora, con `--dry-run` delante.

O sea: el dato que cambia la decisión no es «el guion sube todo», es «el guion sube todo **si le pides `--all`**»,
y la forma segura estaba disponible desde el principio. El capítulo lo explica con los tres modos y el comando
exacto.

**3.8 — «nueve lugares sólo dentro de `optimize.cls.php`, y más de veinte en todo el plugin». Correcto en el
diagnóstico, corto en la cuenta.** El recuento real sobre el LiteSpeed 7.9.1 instalado
(`grep -rn "LITESPEED_GUEST_OPTM" src/`) da **dieciséis** apariciones en `optimize.cls.php`
(`:73`, `:79`, `:85`, `:114`, `:245`, `:278`-`:284`, `:317`, `:524`, `:678`, `:1220`) y **treinta y una** en todo
el plugin, más una trigésimo segunda que es la que **define** la constante (`vary.cls.php:222`) y por tanto no
cuenta como consumidora. El capítulo lleva las cifras corregidas y la lista por fichero. El argumento del crítico
—que el número real refuerza la baranda— es exactamente por lo que valía la pena contarlas bien.

**Un matiz sobre 3.9**, que no cambia nada pero afina la cita: las dos líneas del `wp_mail` / `pre_wp_mail` son en
efecto **209** y **233**. En el segundo dato, la rama `is_array()` empieza en `:298` y el `explode( "\n", … )` de
la cadena está en `:303`; la rama de array es `:304-305`, el bucle arranca en `:312` y el
`explode( ':', trim( $header ), 2 )` está en `:321`. El capítulo cita ahora esos cuatro números en vez de un
rango aproximado.

**Todo lo demás se aplicó tal cual**, incluidos los tres que el crítico pedía primero: la ficha completa del cron
de hPanel con la confesión de que el uid de la tarea viva no consta en ningún sitio del repositorio y la
comprobación indirecta desde SSH (2.1); los dos mu-plugins de Hostinger, ahora nombrados en el alcance y
convertidos en la evidencia central del incidente (2.2); el mecanismo plausible y la predicción con fecha para el
domingo 13 (2.3); la corrección de que **ninguna** de las doce piezas está a salvo de una redeclaración, con el
transporte como la más expuesta por ser la única que otro proceso copia sola (3.6); y la separación entre medido,
leído y deducido, con marcas en el texto y una sección propia antes del glosario (4.3).

Los siete diagramas se rehicieron y se revalidaron con `mmdc` uno a uno. El `gantt` de ritmos desapareció: una
barra continua de 24 horas no puede representar un latido cada 5 minutos, así que ahora es un diagrama de
secuencia con los seis ritmos, sus bucles y las horas siempre en las dos referencias (UTC y hora de Chile).
