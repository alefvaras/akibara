# mu-plugins (B) · correo SMTP y su centinela, LiteSpeed, claves de proveedores, redirecciones, logs, solo-local

> **Alcance.** Trece ficheros: **once** que viven en el servidor de producción y **dos** que sólo existen en el
> ordenador de desarrollo (`akibara-debug-log-rotation.php` y `akibara-local-cron-spawn.php`). La verdad de cada
> afirmación es el código del snapshot congelado de producción (`snapshot-prod/mu-plugins/`) y, para los dos de
> sólo-local, el árbol `public_html/wp-content/mu-plugins/`. Cada dato técnico lleva su fichero y su línea.
>
> **Corregido 2026-09-20 (ronda 10):** este capítulo decía «nueve en producción y **tres** de sólo-local». Los de
> sólo-local son **dos**. `akibara-local-external-indexing-guard.php` **vive en producción** —a propósito, desde
> la ronda 3 (2026-09-19, hallazgo R3-b9fecf46)—: **el que engaña es su nombre**, que arrastra el prefijo
> `akibara-local-` de cuando sólo corría en el espejo. Y se sumó la pieza 10, `akibara-woocommerce-rest-solo-en-la-raiz.php`,
> que estaba en producción sin capítulo. Las dos verificadas en sólo lectura el 2026-09-20, con la copia local y
> la de producción byte a byte idénticas.
>
> **Lo que este capítulo NO cubre.** En esa misma carpeta de producción hay hoy **veintiún** ficheros nuestros
> (medido el 2026-09-20). Los **otros diez** —las **cinco** piezas de Blue Express, contando la que traduce su
> correo al español; la de Brevo; la de Clarity; la retención de Action Scheduler; la lista de veto y el rastro de
> abilities— están en el [capítulo 14](14-mu-plugins-abilities-bluex-brevo-clarity.md). Con las once de aquí,
> **los veintiuno quedan documentados**: once + diez (2026-09-20, ronda 10). Y conviven además con **dos ficheros que no son
> nuestros**, puestos ahí por la empresa que aloja la tienda: `hostinger-auto-updates.php` («Hostinger Smart Auto
> Updates», v1.0.7) y `hostinger-preview-domain.php` (v1.4.0), más una carpeta `assets/`. No se tocan, no se
> documentan aquí y no están bajo nuestro control — pero **importan**, y mucho, para el incidente que cuenta la
> pieza 3.
>
> **Si un término no te suena, está definido en el [Glosario del capítulo](#glosario-del-capítulo), al final.**
> «Cepo», «cartero», «centinela», «guard», «ability», «fail-closed» y «TTL» aparecen desde la primera página y
> todos están ahí.
>
> **Cómo leer la certeza de cada afirmación.** No todo en este capítulo está comprobado del mismo modo. Hay tres
> niveles y van marcados:
>
> | Marca | Qué significa |
> |---|---|
> | **[medido]** | Alguien lo ejecutó y anotó el resultado, con fecha. Es un hecho. |
> | **[leído]** | Está en el código o en una ficha de incidente y se cita con fichero y línea. Es lo que dice la fuente. |
> | **[deducido]** | Se sigue de leer dos códigos y cruzarlos, pero **nadie lo ha ejecutado todavía**. Puede estar mal. |
>
> Lo que no lleva marca es descripción directa del código (hooks, prioridades, qué escribe cada función) y se
> comprueba abriendo el fichero en la línea citada. El resumen completo de qué está medido y qué sólo deducido
> está en la sección [«Qué está medido y qué está deducido»](#qué-está-medido-y-qué-está-deducido), antes del
> glosario. La regla de la casa es literal: *primero local, después producción, y hasta que no está medido en
> producción no está aprobado*.

---

## Para qué existe (en dos párrafos que entienda un novato)

Akibara es una tienda hecha con **WordPress**, un programa que corre en un servidor y sirve las páginas que ve el
cliente. Encima de WordPress se instalan **plugins**: paquetes de código que añaden funciones. WooCommerce, por
ejemplo, es el plugin que convierte WordPress en una tienda. Hay una carpeta especial dentro de WordPress que se
llama `wp-content/mu-plugins`; las siglas **mu** significan *must-use*, «de uso obligatorio». Todo fichero PHP que
se deje ahí dentro se carga **siempre**, en cada visita, **antes** que cualquier otro plugin, y **no se puede
desactivar** desde el panel de administración: ni siquiera aparece en la lista de plugins con su interruptor. Es
la carpeta de las piezas que no deben poder apagarse por accidente ni por un clic distraído.

Este capítulo cubre doce de esas piezas, y se leen en cuatro bloques. **El primero es el correo**, y es el que
importa: un fichero entrega físicamente los correos de la tienda, otro vigila desde fuera que ese fichero siga en
su sitio, un tercero impide que las pruebas del ordenador de desarrollo lleguen a clientes reales, y un cuarto
lleva el inventario de qué claves de proveedores espera la tienda —sin guardar nunca su valor—. **El segundo son
las barandas**: código que fija cinco comportamientos de la caché LiteSpeed para que un clic en el panel de
Hostinger no vuelva a tirar la portada. **El tercero son arreglos puntuales**, cada uno nacido de una avería
concreta y medida: el esquema `https` de las imágenes en los correos automáticos, las cabeceras de caché de los
mapas del sitio, una traducción del checkout que decía «un dirección de correo electrónico válido», y una puerta
controlada para que un asistente pueda operar las redirecciones de URLs. **El cuarto son tres ficheros que nunca
viajan al servidor**: existen para que el espejo de desarrollo se parezca a producción sin salpicar hacia fuera.

![Piezas del capítulo y sus relaciones](diagramas/15-mu-plugins-correo-litespeed-claves-y-guardas-piezas.mmd)

---

## Cómo encaja con el resto (con quién habla: plugins, tema, terceros; qué pasa si se apaga)

**El transporte de correo es la dependencia común de todo lo que le habla al cliente por escrito.** WooCommerce
(todos los `WC_Email`: confirmación de pedido, pedido completado, recuperación de contraseña, alta de cuenta),
`akibara-rewards` (avisos del programa de puntos) y `akibara-business` (avisos de «ya llegó» y solicitudes de
reseña) llaman todos a la misma función de WordPress, `wp_mail()`. Si el transporte cae, caen los tres a la vez y
sin ruido: en Hostinger la función `mail()` de PHP devuelve `false` y nadie se entera, porque el correo no puede
avisar de que el correo está roto. Eso pasó de verdad del 18 al 21 de agosto de 2026 —tres días, el pedido 27720
entre ellos— y es el motivo por el que existen la mitad de las piezas de este capítulo
(`akibara-mail-transport-watch.php:20-37`).

**Con el panel de administración.** La pantalla «Claves y accesos» se engancha como submenú de `akibara-panel`
(`akibara-provider-access.php:488,496-503`), que es el menú que registra Akibara Business en
`AdminPanel.php:172,179-187`. El padre se registra en la prioridad por defecto de `admin_menu` (10) y el hijo en
la 99, así que el padre ya existe cuando el hijo se cuelga. Ojo al detalle de capacidades, que no es simétrico:
el menú padre exige `manage_woocommerce` (`AdminPanel.php:182`), la pantalla de claves exige `manage_options`
(`akibara-provider-access.php:482`), y los dos avisos del correo —el del transporte y el del centinela— exigen
`manage_woocommerce` (`akibara-correo-smtp.php:229` y `akibara-mail-transport-watch.php:309`). **Consecuencia
práctica:** un usuario con rol de gestor de tienda (`shop_manager`, que tiene `manage_woocommerce` pero no
`manage_options`) ve los dos avisos rojos del correo, pero **no** ve la fila roja «falta el archivo que la usa»
del inventario, que es justamente la que convierte «el mu-plugin del correo desapareció» en una señal y no en
tres días de silencio.

**Con terceros.** Las barandas de LiteSpeed se acoplan a la API de configuración de LiteSpeed Cache 7.9.1
(`optimize.cls.php`, `root.cls.php`, `vary.cls.php`, `media.cls.php`) y protegen tres cosas concretas: las hojas
de estilo que el tema `akibara-gp` difiere (`inc/performance.php`), la carga diferida nativa de WordPress de la que
dependen las portadas del catálogo, y la Store API que usan el carrito y el checkout de bloques de
`akibara-cart-checkout-blocks`. La política de caché de los mapas del sitio y las cinco *abilities* de
redirecciones se acoplan a Rank Math. La corrección de concordancia se acopla al catálogo de traducciones de
WooCommerce. **Ninguna edita código de terceros: todas actúan por hooks**, que es la regla de la casa.

**Con el despliegue.** `ops/akibara-prod-muplugins-deploy.sh` sube estos ficheros, mantiene al día la copia de
rescate del centinela en `akb-runtime/` (`:26,95`), tiene en cuarentena explícita todo lo que empieza por
`akibara-local-` más `akibara-debug-log-rotation.php` (`:32`,
`QUARANTINE_RE='^(akibara-local-|akibara-debug-log-rotation\.php$)'`, aplicado en `:43`) y termina
llamando a `ops/akibara-prod-mail-health.sh` (`:105`), que compara el md5 del transporte y de la copia de rescate
y ejecuta `ops/lib/akibara-mail-probe.php` para abrir una conexión SMTP real **sin enviar nada**.

> **El prefijo `akibara-local-` ya no significa «sólo local», y conviene no fiarse de él.** La expresión de
> cuarentena de arriba cubre **tres** nombres del árbol, pero uno de ellos —`akibara-local-external-indexing-guard.php`,
> la pieza 13— **está en producción a propósito** desde la ronda 3 (2026-09-19, hallazgo R3-b9fecf46: se desplegó
> como `indexing-guard 1.1.0`). El nombre se le quedó de cuando sólo corría en el espejo, y es lo único que
> engaña: allí no bloquea nada, porque la tienda real es justo el caso que deja pasar. Los que de verdad no viajan
> son **dos**: `akibara-debug-log-rotation.php` y `akibara-local-cron-spawn.php`. Para saber qué hay en producción
> se mira la carpeta de producción, no el prefijo del nombre (2026-09-20, ronda 10).

**Qué pasa si se apaga cada pieza.** Estos ficheros no tienen interruptor: se apagan borrándolos, y borrarlos
**no se puede hacer desde el panel de WordPress**: la carpeta `mu-plugins` no aparece en la lista de plugins, no
tiene casilla de «desactivar», y en el espejo además `wp-config.php:34` define `DISALLOW_FILE_MODS` en `true`, que
apaga el editor de ficheros del admin. Las dos únicas vías reales son **por SSH** (`ssh akibara`, y de ahí a
`~/domains/akibara.cl/public_html/wp-content/mu-plugins/`) y **desde el administrador de archivos de hPanel**. Y
eso importa:

| Si desaparece… | Qué pasa | Se nota |
|---|---|---|
| `akibara-correo-smtp.php` | La tienda deja de mandar **todo** correo. `wp_mail()` cae al `mail()` de PHP, que en Hostinger falla. | Sólo gracias al centinela y a `mail-health.sh`. Solo, no se notaría. |
| `akibara-mail-transport-watch.php` | El fichero anterior puede desaparecer sin que quede constancia. | No se nota hasta el siguiente incidente. |
| `akibara-correo-no-sale-a-clientes.php` | Una prueba en el espejo con un SMTP real puede escribir a **84 direcciones reales de clientes** copiadas de producción. | No se nota: se nota cuando ya pasó. |
| `akibara-provider-access.php` | El despliegue pierde su barrera de claves y el dueño pierde la pantalla de inventario. | `wp akibara access-check` deja de existir; el despliegue falla al invocarlo. |
| `akibara-litespeed-guardrails.php` | Un preset de «optimizar todo» de Hostinger puede volver a fundir las hojas de estilo y tirar la portada; el carrito vuelve a cachearse para clientes con sesión; y LiteSpeed vuelve a apagar la carga diferida de WordPress (medido el 2026-09-14: `/tienda/` bajaba 3.472 KB de imagen en móvil). | Caída visible de la portada; carrito que «no se vacía»; catálogo lento en el móvil sin ningún error. |
| `akibara-woo-blocks-i18n.php` | El checkout vuelve a decir «un comuna válido». | Sólo mirándolo. |
| `akibara-woocommerce-rest-solo-en-la-raiz.php` | Vuelve el fatal del 3–12 de septiembre: cualquier URL que **contenga** `wp-json/` sin ser API —las fabrican los rastreadores— responde **500** en vez de la 404 del tema, porque WooCommerce la toma por API, no carga la sesión y Google for WooCommerce imprime su etiqueta leyendo una sesión que no existe. | En el registro de errores y en las métricas de 500; el cliente normal no lo pisa. |
| Los **dos** de sólo-local (`akibara-debug-log-rotation.php`, `akibara-local-cron-spawn.php`) | El espejo pierde la rotación del log y el cron se congela. | Sólo afecta al ordenador de desarrollo. |
| `akibara-local-external-indexing-guard.php` | **Ojo: este sí está en producción, y a propósito** (ronda 3, 2026-09-19); el prefijo `akibara-local-` del nombre es lo único que sugiere otra cosa. En el espejo, las pruebas pueden avisar a IndexNow con URLs de `localhost` o de un clon. En producción **quitarlo no rompe nada** —la tienda real es justo el caso en que deja pasar—, pero mientras esté ahí ata IndexNow al mu-plugin del correo: ver la fila siguiente. | En el espejo, no se nota hasta que Bing ya indexó una URL de staging, que no se desindexa pidiéndolo de vuelta. |
| `akibara-correo-smtp.php`, **para IndexNow** | El guard de arriba **falla CERRADO**: sin la clase `\Akibara\HostingerMail\Transport` no puede comprobar el dominio, así que en la **tienda real** cancela `publish_url` y `submit_url`, congela la option `rank_math_indexnow_log` y devuelve `WP_Error` a `api.indexnow.org`. | **No se nota**: no hay error visible, sólo deja de avisarse a los buscadores. Si el correo cae, revisar también esto (corregido 2026-09-20, ronda 10). |

---

## Piezas

### 1. `akibara-correo-smtp.php` — el cartero

**En una frase:** es el único responsable de que un correo de la tienda salga de verdad, entregándolo por SMTP
autenticado contra el buzón `contacto@akibara.cl` de Hostinger.

**Ficheros:** `mu-plugins/akibara-correo-smtp.php` (versión 1.3.0, clase `Akibara\HostingerMail\Transport`).
Copia de rescate: en producción, **`~/domains/akibara.cl/akb-runtime/akibara-correo-smtp.php`**
(`ops/akibara-prod-muplugins-deploy.sh:26`). Es un hermano de la carpeta publicada por web, no un hijo: por ahí
no se puede descargar. El centinela **no** lee esa ruta de ninguna variable de los guiones: la deriva de
`ABSPATH` —sube un nivel y baja a `akb-runtime/`— precisamente para no depender de que alguien se acuerde de
definir una constante (`akibara-mail-transport-watch.php:188-204`). Sonda de salud:
`ops/lib/akibara-mail-probe.php`.

**En qué momento actúa (hook y prioridad):**

| Hook | Tipo | Prioridad | Qué hace | Línea |
|---|---|---|---|---|
| `pre_wp_mail` | filtro | `PHP_INT_MAX` | El **guard**: decide si el envío sigue o se corta ahí mismo. | `:54` |
| `phpmailer_init` | acción | `PHP_INT_MAX` | Aplica el perfil SMTP validado sobre el objeto que enviará el correo. | `:55` |
| `wp_mail_succeeded` | acción | `PHP_INT_MAX` | Borra el rastro del corte: un correo volvió a salir. | `:70` |
| `wp_mail_failed` | acción | `PHP_INT_MAX` | Anota un rechazo del servidor SMTP. | `:74` |
| `admin_notices` | acción | 10 | Pinta el aviso rojo «El correo de Akibara NO está saliendo». | `:83` |

**El orden real dentro de `wp_mail()`, que es contraintuitivo.** WordPress aplica **primero** el filtro `wp_mail`
—donde vive el cepo de la pieza 2— y **sólo después** el filtro `pre_wp_mail` —donde vive este guard—. Las dos
líneas exactas, en el WordPress de este árbol: `apply_filters( 'wp_mail', … )` en
`wp-includes/pluggable.php:209`, `apply_filters( 'pre_wp_mail', … )` en `:233`. Es decir: cuando el guard decide
si corta, el cepo **ya** reescribió el destinatario, el asunto y las cabeceras.

**Qué datos lee. Seis constantes** de `wp-config.php`, nunca de la base de datos, y se leen en **dos sitios
distintos**, que es un detalle que conviene no confundir:

| Constante | Quién la lee | Línea | Para qué |
|---|---|---|---|
| `AKIBARA_HOSTINGER_MAIL_ENABLED` | `is_enabled()` | `:405-408` | El interruptor. Exige el booleano `true`, con identidad estricta. |
| `AKIBARA_HOSTINGER_SMTP_HOST` | `validated_configuration()` | `:416-422` | Servidor. Opcional; por defecto `smtp.hostinger.com` (`:23`). |
| `AKIBARA_HOSTINGER_SMTP_PORT` | `validated_configuration()` | `:416-422` | Puerto. Opcional; por defecto `465` (`:24`). |
| `AKIBARA_HOSTINGER_SMTP_ENCRYPTION` | `validated_configuration()` | `:416-422` | Cifrado. Opcional; por defecto `ssl` (`:25`). |
| `AKIBARA_HOSTINGER_SMTP_USERNAME` | `validated_configuration()` | `:416-422` | Usuario del buzón. |
| `AKIBARA_HOSTINGER_SMTP_PASSWORD` | `validated_configuration()` | `:416-422` | Contraseña del buzón. |

O sea: **cinco** las lee `validated_configuration()` (`:416-422`) para armar el perfil, y la sexta —el
interruptor— la lee `is_enabled()` en otro sitio (`:405-408`). También lee el entorno con
`wp_get_environment_type()` (`:431-433`).

**Qué datos escribe.** Una sola option, **sin autoload**: `akibara_mail_transport_outage`, con cinco claves
—`kind`, `reason`, `last_gmt`, `first_gmt`, `count`— y, desde la **1.3.0**, una sexta: `blocked`, la lista de los
envíos que se quedaron sin salir, con **destinatario y asunto** de cada uno. Hasta la 1.2.2 no se guardaba nada de
eso —`guard_configuration()` hacía `unset( $attributes )` en su primera línea— y el coste era el que se pagó en
agosto: el dueño sabía «se bloquearon 14 envíos, el último el 6 de septiembre» y no **qué 14** ni **a quién**, así
que no podía reenviarlos ni disculparse con nadie concreto; el incidente se descubrió porque reclamó un cliente
(S-04d13bef).

Eso sigue siendo una decisión de privacidad, ahora con dos límites escritos en el código en vez de con una
ausencia: se guardan **como máximo 20** y **caducan a los 30 días**, porque el dato existe para reescribirle a
quien se quedó sin su correo y ese uso caduca. El aviso del escritorio nombra los **tres últimos**, con su asunto
y su destinatario. Y el fichero **no emite ninguna acción propia** al anotar el corte: la que había no tenía un
solo oyente, así que era código muerto y, peor, una promesa escrita de que alguien reaccionaba (S-8302c3f2).

**El perfil que acepta, y la trampa de esa validación.** En `production` sólo pasa `smtp.hostinger.com` en el
puerto 465 con `ssl` **o** en el 587 con `tls`, y sólo con el usuario exactamente igual a `contacto@akibara.cl` y
contraseña no vacía (`:344-351`). En `local` la validación es mucho más laxa: sólo exige que usuario y contraseña
estén **ambos** puestos o **ambos** vacíos (`:340-343`), lo que permite apuntar a Mailpit sin autenticación —que
es exactamente lo que hace `wp-config.php:42-46`: `mailpit`, puerto `1025`, cifrado cadena vacía. **Sólo hay esas dos ramas**, y en la 1.3.0 se retiró lo que sugería una tercera: detrás venía un
`else { return null; }` «para cualquier entorno desconocido», con el docblock de `environment()` prometiéndolo por
escrito. Ninguna cadena lo alcanzaba —o el entorno es `production` o no lo es—, así que la promesa hacía contar
con una defensa que no existía. Qué entorno hay puesto, de todos modos, no se deja al azar: `wp-config.php:30` define `WP_ENVIRONMENT_TYPE` incondicionalmente
(`getenv('WP_ENVIRONMENT_TYPE') ?: 'local'`) y `compose.yaml` la fija a `local` en los tres servicios. Llegar a
`staging` o `development` exige editar `wp-config.php` a mano.

**La puerta de escape de `local`, que conviene conocer.** Antes de cualquier otra comprobación, el guard hace
esto (`:97-100`):

```php
$environment = self::environment();
if ( 'local' === $environment && ! self::is_enabled() ) {
    return $short_circuit;
}
```

Es decir: en el espejo, si alguien quita la constante `AKIBARA_HOSTINGER_MAIL_ENABLED`, el guard **no corta, no
deja option de corte y no enciende ningún aviso**: `wp_mail()` cae al `mail()` de PHP en silencio. Es el mismo
modo de fallo silencioso que este fichero existe para matar, conservado a propósito sólo en el espejo. Quien
pruebe correo en local sin esa constante verá «se envió» y no habrá ni rastro ni rojo.

**Y borra la configuración SMTP de cualquier otro plugin.** Esto es la respuesta a «instalé un plugin de SMTP y
no hace nada». `apply_configuration()` no se limita a poner host, puerto, cifrado, usuario, contraseña y
`Timeout = 20`: también deja en cero todo lo que otro pudiera haber configurado antes —`SMTPDebug = 0`,
`SMTPOptions = array()`, `AuthType = ''`, `SMTPKeepAlive = false` (`:380-384`)—, y lo hace en `phpmailer_init`
con prioridad `PHP_INT_MAX`, o sea **el último de todos**. Cualquier plugin de SMTP que se instale encima queda
sin efecto sin dar un solo error.

**El remitente está fijado por código, y pisa lo que diga WooCommerce.** En `apply_configuration()` (`:386-392`):

```php
if ( method_exists( $phpmailer, 'setFrom' ) ) {
    $phpmailer->setFrom( self::REQUIRED_SENDER, 'Akibara', false );
} else { … }
$phpmailer->Sender = self::REQUIRED_SENDER;
```

`setFrom()` con el tercer argumento en `false` no fuerza el `From` si ya había uno, pero la línea siguiente fija
el `Sender` —el remitente del sobre, el que ven los servidores— **incondicionalmente**, y todo esto corre en
`phpmailer_init` con prioridad `PHP_INT_MAX`, o sea el último, después de que WooCommerce y los filtros
`wp_mail_from` / `wp_mail_from_name` hayan decidido el suyo. **Esto explica por qué todo sale de
`contacto@akibara.cl`, y significa que el día que el dueño cambie el remitente en WooCommerce → Correos
electrónicos no pasará absolutamente nada y no habrá ninguna pista de por qué.**

**Dónde lo opera el dueño.** En ningún sitio: no tiene pantalla. Sus palancas son constantes de `wp-config.php`,
y lo que ve del fichero es el aviso rojo en el admin y, en la pantalla «Claves y accesos», **seis de las siete
filas del catálogo** —las seis del correo; la séptima es la de Google Maps y no tiene nada que ver con esto—.

**Cómo se mira el estado por consola, que es la única forma que hay.** La option no tiene pantalla ni comando, y
al no llevar autoload tampoco sale en un volcado de `alloptions`. Desde `ssh akibara`, dentro del docroot:

```bash
wp option get akibara_mail_transport_outage --format=json
```

Devuelve `kind`, `reason`, `first_gmt`, `last_gmt` y `count`, o un error si la option no existe — que es
exactamente lo que se quiere ver: **que no exista significa que el correo está saliendo**.

**Y cómo se apaga el rojo a mano, con su advertencia.** El aviso rojo no es descartable por diseño: sólo se apaga
cuando un correo vuelve a salir de verdad. Si el buzón está caído durante días y el rojo estorba, la única salida
es `wp option delete akibara_mail_transport_outage`. **Hacerlo apaga la alarma sin arreglar nada**, que es
literalmente el fallo que tenía la versión anterior de esta pieza y que se cuenta dos párrafos más abajo. Si se
hace, hay que dejarlo escrito en la ficha del incidente.

**Qué ve el cliente.** El correo, o nada. Si el guard corta, el cliente no recibe su confirmación y su dirección
no queda registrada en ningún sitio.

**Dos decisiones de diseño que vale la pena entender:**

- `clear_outage` cuelga de `wp_mail_succeeded` y **no** de `phpmailer_init` (`:57-70`). El comentario documenta
  los dos caminos medidos por los que la versión anterior estaba rota: (a) la sonda de salud del despliegue
  (`ops/lib/akibara-mail-probe.php:27`) dispara `phpmailer_init` para comprobar la autenticación **sin enviar
  nada**, así que borraba la evidencia del corte en cada despliegue; (b) con la contraseña rotada el perfil sigue
  validando —sólo se exige que no esté vacía—, se borraba el aviso y *después* fallaba el envío: el dueño veía
  apagarse la alarma roja justo cuando el correo seguía sin salir.
- Hay **dos naturalezas de fallo con umbrales distintos** (`:31-45,245-247`). Un corte del guard (`blocked`) es
  estructural y grita desde el primero. Un rechazo del SMTP (`send_failed`) no grita hasta el **tercero seguido**,
  porque un rechazo suelto suele ser una dirección mal escrita y gritar por eso enseña a ignorar la alarma.

**Trampas conocidas y vivas en esta pieza:**

- `wp_mail_failed` lo dispara el núcleo de WordPress también cuando PHPMailer no tiene **ningún destinatario
  válido** («You must provide at least one recipient email address»), sin haber abierto conexión con el SMTP. El
  texto del aviso que sale entonces (`:285`) afirma: «El transporte está configurado, así que el problema está
  del lado del buzón: contraseña rotada, cuota llena o smtp.hostinger.com caído.» Tres altas o tres pedidos con
  el correo mal escrito, sin un envío bueno de por medio, encienden un rojo que manda al dueño a rotar una
  contraseña que está bien.
- La acción `akibara_mail_transport_blocked` (`:194`) **no la escucha nadie**. Una búsqueda por todo el árbol
  first-party da tres aciertos: el comentario del propio fichero (`:162`), el `do_action` (`:194`) y un
  comentario en `HostingerMailTransportTest.php:346`. Cero `add_action`. El candado de reentrada
  `self::$recording` (`:166-169`) y su prueba dedicada protegen un mecanismo que hoy no existe.

---

### 2. `akibara-correo-no-sale-a-clientes.php` — el cepo del espejo

**En una frase:** fuera de producción, desvía todo el correo a una bandeja inventada y deja el destinatario real a
la vista, para que ninguna prueba escriba a un cliente de verdad.

**Ficheros:** `mu-plugins/akibara-correo-no-sale-a-clientes.php` (versión 1.1.0, funciones globales, sin clase).

**En qué momento actúa.** Filtro `wp_mail`, prioridad `PHP_INT_MAX` (`:142`). Como se explicó arriba, este filtro
corre **antes** que el `pre_wp_mail` del cartero.

**Por qué existe, con el número delante.** El espejo tiene **84 direcciones reales de clientes** **[medido]** el
2026-09-06 sobre `wc_orders`, y 69 cuentas con correo real (`:8-10`).

**Cuidado con el comentario del propio fichero, que está caducado.** Dice (`:10-11`) que hoy no sale nada a
internet «por **accidente** de configuración: el transporte del contenedor es `mail` contra `localhost:25`, que
no entrega». **Eso era verdad y ya no lo es.** Desde que `wp-config.php:42-46` lleva el correo del espejo a
Mailpit (`mailpit`, puerto `1025`, sin cifrado), el espejo **sí** entrega correo: lo entrega a un buzón de
pruebas que corre en el propio Docker y del que no sale nada hacia internet. La diferencia importa: hoy la
protección no es un accidente, es una elección declarada en dos sitios (el `wp-config.php` y este cepo).

**Lo que sigue justificando el guardarraíl son las otras dos razones del comentario**, que siguen intactas:
refrescar la base desde producción y heredar credenciales armadas —ya pasó con Cloudflare y los webhooks—, o
conectar un proveedor de campañas como Brevo para probar un envío. El tercero está sobre la mesa (`:14-20`). Y se
suma una tercera, la que hace el cepo imprescindible aunque Mailpit esté puesto: **basta que alguien apunte el
SMTP local a un servidor de verdad** durante media hora de pruebas para que 84 personas reciban correo de una
prueba.

**Qué hace, paso a paso** (`:104-140`):

1. Si `wp_get_environment_type()` es `production`, devuelve los argumentos sin tocar nada y se acabó (`:105-107`).
2. Separa los destinatarios y descarta los que ya son de pruebas: los TLD reservados por la RFC 2606 (`.test`,
   `.example`, `.invalid`, `.localhost`) más `example.com` y `example.org` (`:68-81`). No es una lista blanca de
   personas: es la lista de sitios donde por definición no hay nadie.
3. Si queda algún destinatario real, reescribe `to` a la bandeja de captura y antepone `[para destinatario@real.cl]`
   al asunto (`:130-131`). En el asunto porque es lo único que se ve en la lista de la bandeja sin abrir el correo.
4. Añade las cabeceras `X-Akibara-Destinatario-Original` y `X-Akibara-Entorno` (`:135-137`).

**Por qué redirige en vez de bloquear.** Su propio comentario (`:22-25`): «si bloqueara, QA dejaría de poder
comprobar lo único que de verdad importa de un correo —que llega y qué dice—, y un guardarraíl que estorba se
acaba apagando».

**Dónde lo opera el dueño.** La bandeja por defecto es `capturado@akibara.test`. Se puede cambiar por dos vías,
y el código mira la constante primero (`:48-56`):

1. **Como constante**, añadiendo a `public_html/wp-config.php` una línea
   `define('AKIBARA_CORREO_CAPTURA', 'otra@akibara.test');` dentro del bloque `if ('local' === WP_ENVIRONMENT_TYPE)`
   que ya existe en `:41-46`. Tiene efecto en la siguiente petición.
2. **Como variable de entorno**, declarándola en `compose.yaml` bajo `environment:` del servicio `wordpress` —
   junto a `WP_ENVIRONMENT_TYPE: local` (`compose.yaml:31`)—. Esta vía **exige reiniciar el contenedor**
   (`docker compose up -d wordpress`): las variables de entorno se leen al arrancar el proceso, no al recargar la
   página.

**No tiene pantalla ni aparece en el inventario de claves**, lo que contraviene la regla de la casa de que todo lo
operable tenga pantalla con valores visibles; y de paso es una de las dos constantes que hacen ladrar a
`wp akibara access-scan`.

**Qué ve el cliente.** Nada, que es el objetivo. Lo ve QA, en la bandeja de captura.

**Dos defectos que estuvieron vivos hasta 1.1.0 (2026-09-12), medidos y cerrados:**

- **El cepo destrozaba las cabeceras de los correos de WooCommerce** — ya **no es deducción: está medido**.
  Normalizaba así: `$cabeceras = is_array( $cabeceras ) ? $cabeceras : array( (string) $cabeceras );`, y
  `WC_Email::get_headers()` devuelve una cadena multilínea en **19 de los 22** correos registrados
  (`Content-Type` + `Reply-to`; los 3 restantes —«pedido nuevo», «cancelado» y «fallido»— traen sólo
  `Content-Type`). `wp_mail()` sólo parte esa cadena por saltos de línea cuando `$headers` **no** es un array
  (`pluggable.php:297-303`); si ya lo es, lo toma tal cual (`:304-305`) y recorre los elementos (`:312`)
  haciendo `explode( ':', trim( $header ), 2 )` (`:321`). Resultado medido en Mailpit: el `Content-Type` valía
  «text/html\r\nReply-to: …», se perdía el `Reply-to` y el mensaje ni aparecía en la bandeja —`wp_mail()`
  devolvía `true` y `wp_mail_failed` no saltaba—, justo en los 19 correos que van al cliente.
  **Arreglo:** `akibara_correo_cabeceras_en_lineas()` entrega **una cabecera por elemento**, con el mismo
  criterio del núcleo. Candado: `tools/test-correo-no-sale-cabeceras.php`.
- **No filtraba `Cc:` ni `Bcc:`.** Al partir la cadena, `wp_mail()` empieza a honrar de verdad el `Bcc:` que
  arma WooCommerce, así que cerrar lo anterior sin cerrar esto abría una puerta de atrás. Desde 1.1.0 las
  direcciones de `Cc:`/`Bcc:` se **retiran** y quedan anotadas en `X-Akibara-Copias-Originales`. Y se retiran
  **antes** de decidir si hay que intervenir: con el `to` ya en un dominio reservado, el cepo devolvía los
  argumentos intactos y la copia oculta a una persona real salía entera (entregada en Mailpit durante la
  revisión del 2026-09-12). Hoy basta **una** dirección real en `to`, `Cc` o `Bcc` para que el cepo actúe.

---

### 3. `akibara-mail-transport-watch.php` — el centinela

**En una frase:** vigila desde **fuera** que el fichero del cartero siga cargado, lo repone desde una copia de
rescate si desaparece, y —lo que más importa— deja constancia escrita de cada reposición aunque el problema se
cure solo.

**Ficheros:** `mu-plugins/akibara-mail-transport-watch.php` (versión 1.1.0, clase
`Akibara\MailTransportWatch\Watch`).

**La frase que lo justifica**, en su propio comentario (`:27`): *«Un observador dentro del observado no observa
nada.»* El guard de la versión anterior vivía dentro del mismo fichero que desaparecía.

**En qué momento actúa.** **No crea ningún trabajo programado.** Se cuelga de dos latidos que ya existen:

| Hook | Prioridad | Condición | Línea |
|---|---|---|---|
| `admin_init` | 1 | ninguna | `:65` |
| `init` | 1 | sólo si la constante `DOING_CRON` está definida y es verdadera | `:78-80` |
| `admin_notices` | 10 | ninguna | `:82` |

Mira la **constante** `DOING_CRON` y no la función `wp_doing_cron()` a propósito: esto corre al cargar un
mu-plugin, el momento más frágil del arranque (`:71-77`).

**Un matiz importante sobre `admin_init`:** no se dispara «cuando el dueño entra al escritorio». Se dispara para
**cualquier usuario autenticado** que toque `/wp-admin/` —incluido un cliente que va a su cuenta y acaba en
`profile.php`— y también en `admin-post.php`. Y `Watch::inspect()` **no tiene ninguna comprobación de capacidad**
(`:94-107`, `:160-175`): la copia de fichero dentro de `WPMU_PLUGIN_DIR` se ejecuta en la petición de cualquiera
de ellos. Lo que sí está acotado por capacidad es el **aviso** (`render_notice`, `:309`), no la acción.

**Cómo decide si el transporte está vivo.** Dos comprobaciones, no una (`:120-129`):

```php
if ( ! class_exists( self::TRANSPORT_CLASS, false ) ) {
    return false;
}
return false !== has_action( 'phpmailer_init', array( self::TRANSPORT_CLASS, 'configure_phpmailer' ) );
```

El `false !==` no es cosmética: `has_action()` devuelve la **prioridad** del callback, y una prioridad 0 es
*falsy* — con una comprobación por verdad simple el centinela daría por muerto un transporte sano. Hay una prueba
dedicada a eso en `MailTransportWatchTest.php`.

**Qué hace cuando falta.** La decisión va separada de la copia (`restore_plan`, función pura y probada,
`:142-153`) y da tres desenlaces:

| Situación | Plan | Por qué |
|---|---|---|
| El fichero **existe** pero la clase no cargó | `presente-pero-inerte` | Copiar encima no arregla un fatal ni unos permisos, y podría pisar una versión **más nueva**. Así nació el *downgrade silencioso* del cron viejo de hPanel (ver recuadro debajo de la tabla). |
| El fichero **falta** y hay copia legible | `copiar` | Se copia desde `~/domains/akibara.cl/akb-runtime/akibara-correo-smtp.php`, ruta que el centinela deriva de `ABSPATH` (`:188-205`). |
| El fichero **falta** y no hay copia | `sin-copia` | Hay que subirlo a mano por SSH. |

Si la copia falla al escribirse, el desenlace es `fallo-al-reponer` (`:174`).

> **Qué es un «downgrade silencioso».** Es bajar de versión sin que nadie se entere. El cron viejo de hPanel
> —retirado el 2026-08-23, uid `5WxqXVQ5zM`— reponía a ciegas una copia congelada en la versión **1.0.0** encima
> de la **1.1.0** que estaba desplegada y funcionando. El fichero estaba, la clase cargaba, el SMTP autenticaba:
> todas las comprobaciones de entonces daban verde, porque las cumple **cualquier** versión. Lo único que dejaba
> ese suceso era una línea en un log que sólo se ve por SSH
> (`ops/akibara-prod-mail-health.sh:49-53`, `akibara-mail-transport-watch.php:29-33`). Por eso hoy la sonda de
> salud compara **contenido** (el md5) y no presencia, y por eso el centinela no pisa un fichero que está.

![Estados del centinela del correo](diagramas/15-mu-plugins-correo-litespeed-claves-y-guardas-estados-centinela.mmd)

**Qué datos escribe.** Una option **sin autoload**: `akibara_mail_transport_watch` (`:42,286-298`), con
`missing_since`, `last_seen_gmt`, `outcome` y un array `restores` acotado a las **10 últimas** fechas
(`:54,239-249`). **El historial es el punto entero de la pieza**: sobrevive a que el problema se cure, que es
justo lo que el cron viejo no hacía. `clear_outage()` borra las tres primeras claves y **conserva `restores` a
perpetuidad** (`:254-265`).

**Dónde lo opera el dueño.** En ningún sitio: no tiene pantalla ni comando. Lo que ve son dos avisos distintos
(`:308-325`): uno rojo mientras el transporte no está, y otro amarillo que recuerda que el fichero ya desapareció
alguna vez aunque hoy esté todo bien.

**Lo que hay ahí hoy en producción** — **[leído]** de la ficha del incidente, **no releído contra la option** al
escribir este capítulo. Tres reposiciones: `2026-08-23 23:10:38`, `2026-08-30 23:12:18` y `2026-09-06 23:15:03`
UTC. **Las tres en domingo**, cada una un poco más tarde que la anterior. La última equivale a las **20:15 en hora
de Chile**. Quien quiera comprobarlo hoy, desde `ssh akibara` y dentro del docroot:

```bash
wp option get akibara_mail_transport_watch --format=json
```

(La option no lleva autoload: no sale en un volcado de `alloptions`, hay que pedirla por su nombre.)

La fuente de esas tres fechas es la ficha
`ops/coordinacion/abiertos/2026-09-08-correo-repuesto-tres-domingos-causa-pendiente.md`, que **sí** las midió y
además las cruzó con el `ctime` y el `mtime` del fichero vivo y con el sha256 del transporte
(`92ba7d2e79514753…`, v1.2.0). El incidente sigue **abierto**: el escáner de malware de Hostinger no detectó nada
en 30 días, el soporte confirmó que no conservan auditoría de operaciones de fichero para atribuir las
desapariciones, y ni el cron de WordPress, ni los guiones de operaciones, ni las tareas programadas del Mac
explican el patrón dominical.

**El mecanismo plausible, que sí se puede nombrar.** La ficha lo cerró como «actor no identificado», pero
**mecanismo** y actor no son lo mismo. Ordenando por fecha los ficheros del directorio `mu-plugins` de producción
esa noche del domingo 6 de septiembre (**[medido]** el 2026-09-11 sobre el propio directorio):

```
2026-09-06 11:59:17   akibara-mail-transport-watch.php   (nuestro centinela)
2026-09-06 11:59:43   akibara-correo-no-sale-a-clientes.php
       ── a partir de aquí, nada hasta la noche ──
2026-09-06 23:14:31   hostinger-auto-updates.php       Author: Hostinger
2026-09-06 23:14:34   hostinger-preview-domain.php     Author: Hostinger
2026-09-06 23:15:03   akibara-hostinger-mail.php       Author: Akibara   ← nuestra reposición
```

Los **dos únicos ficheros del directorio que se reescriben esa noche son los dos que son de Hostinger**, y lo
hacen **32 y 29 segundos antes** de que nuestro centinela reponga el nuestro. Y nuestro fichero se llamaba
entonces `akibara-hostinger-mail.php`: era **el único otro del directorio con la palabra «hostinger» en el
nombre**. Eso sostiene una hipótesis concreta —una pasada de la plataforma que gestiona `*hostinger*` dentro de
`mu-plugins` y retira lo que no está en su manifiesto— y explica por qué el soporte propuso quitar esa palabra
del nombre sin dar una regla. **Lo que no sostiene:** quién ejecuta esa pasada ni con qué criterio exacto
selecciona. Es atribución de mecanismo, no de actor.

**La predicción falsable, y hay que leerla con el calendario delante.** La deriva de las tres reposiciones
—23:10:38 → 23:12:18 → 23:15:03, cada domingo un poco más tarde— es la firma de un trabajo que se reprograma
desde su propia finalización, no de un cron a hora fija. La ficha deja escrita esta predicción:

> **Domingo 2026-09-13, alrededor de las 23:17-23:20 UTC** (20:17-20:20 hora de Chile), los dos
> `hostinger-*.php` se reescriben otra vez. Si el nuestro —ya renombrado a `akibara-correo-smtp.php`—
> **sobrevive**, la regla era el nombre y esto queda cerrado. Si **desaparece igual**, la selección no era por
> nombre y hay que buscar en otro sitio. Las dos salidas informan.

El 11 de septiembre el dueño autorizó el renombrado de `akibara-hostinger-mail.php` a `akibara-correo-smtp.php`
—quitando la palabra «hostinger»— precisamente **como esa prueba**; el snapshot ya refleja el cambio, con el
`TARGET` del centinela, la copia de rescate y los seis `owner_file` del inventario actualizados en el mismo
movimiento. Lo que **no** se cambió es el encabezado del fichero, que sigue diciendo «Correo SMTP de Hostinger»:
si la pasada seleccionara por contenido y no por nombre, el borrado se repetiría igual — y eso sería, en sí, el
siguiente dato. Un solo domingo bueno no cierra nada: conviene mirar también el domingo 20.

**Trampas que tenía esta pieza, y cómo quedaron (1.1.0, en producción el 2026-09-16):**

- ~~**Repone sin comprobar qué repone.**~~ **Cerrado.** Antes de copiar, `backup_is_valid()` exige tres marcas
  del transporte en la copia (`namespace Akibara\HostingerMail;`, `class Transport`, `phpmailer_init`) y después
  compara byte a byte lo escrito con lo leído, porque con el disco lleno `copy()` devuelve verdadero dejando el
  fichero a medias. Si la copia no vale, el desenlace es `copia-invalida` y el aviso lo dice **sin prometer** que
  el correo vuelva: «Hay copia de rescate, pero NO es el transporte… no la copié, porque dejaría el correo igual
  de caído con la apariencia de arreglado».
- ~~**Dos inspecciones simultáneas se pisan.**~~ **Cerrado.** `save()` relee la option y **funde** antes de
  escribir (`merge_restores`, `merge_contexts`). No hace falta candado: el historial es un conjunto de fechas y la
  unión de dos lecturas de un conjunto es el conjunto.
- ~~**El aviso amarillo no se puede cerrar desde la interfaz, nunca.**~~ **Cerrado.** El aviso lleva un enlace con
  nonce que guarda `history_ack` —cuántas reposiciones se dieron por vistas— y vuelve solo si el fichero
  desaparece otra vez. **Ya no hace falta borrar la option para dejar de verlo**, que era la salida de antes y se
  llevaba por delante la evidencia del incidente.
- ~~**El centinela depende del mismo cron de hPanel cuya ausencia nadie vigila.**~~ **Acotado.** Cada paso por el
  cron anota `last_cron_gmt`, y si en producción lleva más de **30 minutos** sin latir, el escritorio avisa: «Las
  tareas de fondo de Akibara no están corriendo». Fuera de producción calla, porque allí el cron no corre a
  propósito. No sustituye a vigilar la tarea en hPanel: dice que **no** está corriendo, no por qué.
- ~~**No registra ningún contexto de quién borró el fichero.**~~ **Cerrado.** Al detectar la ausencia guarda una
  foto del instante en `contextos`: de dónde se vio (`cron` o `admin`), el desenlace, el `mtime` del directorio
  `mu-plugins`, el de los **dos** `hostinger-*.php` —los que la hipótesis señala— y los bytes de lo repuesto. Una
  por episodio, no una por comprobación. Era el único dato que el incidente necesitaba y que se perdía en cada
  recurrencia, porque son `mtime` que la pasada siguiente pisa.
- ~~**`inspect()` copia ficheros en la petición de cualquier usuario autenticado.**~~ **Cerrado.** `admin_init` no
  significa «el dueño entró al escritorio»: corre para cualquiera en `/wp-admin/`, incluida una clienta en
  `profile.php`. Ahora sólo actúa en el cron —que no tiene usuario y corre cada 5 minutos— o con
  `manage_woocommerce`. Como mucho, la reparación espera un ciclo de cron.

### 4. `akibara-provider-access.php` — el inventario de claves de proveedores

**En una frase:** un catálogo declarativo de qué claves de API espera la tienda, de qué tipo son, quién las usa y
si están puestas — pero **nunca su valor**.

**Ficheros:** `mu-plugins/akibara-provider-access.php` (versión 1.3.0). Cuatro clases en el espacio de nombres
`Akibara\ProviderAccess`: `Registry`, `Access`, `Screen` y `Cli`, más la excepción `MissingProviderAccess`
(`:20`).

**Las tres excepciones que puede lanzar, y quién lanza cada una** — porque la cabecera nombra una sola y el
código tira tres:

| Excepción | Quién la lanza | Línea | Cuándo |
|---|---|---|---|
| `\InvalidArgumentException` | `Registry::entry()` | `:205` | Le pides una clave que no está declarada en el catálogo. Es un error de programación. |
| `\DomainException` | `Access::get()` | `:302` | Le pides el **valor** de una clave que tiene dueño declarado (`owner_file`). Es la frontera del correo. |
| `MissingProviderAccess` | `Access::must()` | `:326-330` | Le exiges una clave que no está configurada o no valida. |

**La salida oficial de la segunda es `Access::is_configured()`** (`:350`), y no es un detalle de estilo: el propio
mensaje de la excepción termina literalmente con «Usa `is_configured()`». Ese método responde **sí o no** sin
devolver nunca el valor, que es todo lo que cualquier pantalla necesita saber de una contraseña.

**En qué momento actúa:**

| Hook | Prioridad | Qué hace | Línea |
|---|---|---|---|
| `admin_menu` | 99 | Registra el submenú «Claves y accesos» bajo `akibara-panel`. | `:488` |
| `admin_notices` | 10 | Pinta el aviso rojo cuando falta una clave obligatoria o el fichero que la consume. | `:489` |
| (al cargar, si `WP_CLI`) | — | Registra los tres comandos de consola. | `:770-772` |

**La regla estructural**, en una línea de su propio docblock (`:28-32`):

> Si la clave viaja al navegador del cliente aunque sea una sola vez, es **PÚBLICA**: puede vivir en una option y
> editarse desde el admin. Si no viaja, es **SECRETO DE SERVIDOR**: vive únicamente como `define()` en el
> `wp-config.php` de producción, jamás en la base de datos ni en el repositorio.

Y no es una convención de buenas intenciones: en `Access::resolve()` la rama que llega a `get_option()` está
literalmente dentro de un `if` sobre el ámbito (`:268`), así que a un secreto de servidor **no hay camino desde la
base de datos**.

**Las siete entradas del catálogo** (`:88-185`):

| id | Constante | Ámbito | Obligatoria | Patrón | Quién la usa |
|---|---|---|---|---|---|
| `google_maps` | `AKB_GOOGLE_MAPS_API_KEY` | pública | no | `/^AIza[0-9A-Za-z_-]{35}$/` | Business · Autocompletar dirección |
| `hostinger_mail_enabled` | `AKIBARA_HOSTINGER_MAIL_ENABLED` | servidor | **sí** | `/^true$/` **+ `expect_bool`** (1.1.0: exige el TIPO booleano, antes de aplanar) | el transporte |
| `hostinger_smtp_username` | `AKIBARA_HOSTINGER_SMTP_USERNAME` | servidor | **sí** | `/^contacto@akibara\.cl$/i` | el transporte |
| `hostinger_smtp_password` | `AKIBARA_HOSTINGER_SMTP_PASSWORD` | servidor | **sí** | `/^\S{8,}$/` | el transporte |
| `hostinger_smtp_host` | `AKIBARA_HOSTINGER_SMTP_HOST` | servidor | no | `/^smtp\.hostinger\.com$/i` | el transporte |
| `hostinger_smtp_port` | `AKIBARA_HOSTINGER_SMTP_PORT` | servidor | no | `/^(?:465\|587)$/` | el transporte |
| `hostinger_smtp_encryption` | `AKIBARA_HOSTINGER_SMTP_ENCRYPTION` | servidor | no | `/^(ssl\|tls)$/i` | el transporte |

**`Access` — el lector.** `resolve()` es una función **pura** (`:260-285`): recibe los dos candidatos y decide,
para poder probar la matriz completa sin definir una sola constante (en PHP las constantes son globales e
inmutables, así que un test que las define contamina a los siguientes). Revalida el formato **en la lectura**
(`:280`), no sólo al guardar.

> **Corrección a un comentario del propio código, que este capítulo repetía.** El comentario de `:277-279` dice
> que la validación en lectura hace falta «porque el `pre_update_option` de Google Maps lo esquiva entero un
> `wp option update`». **Eso es falso.** `update_option()` dispara `pre_update_option_{$option}` siempre
> (`wp-includes/option.php:901`) y `wp option update` llama a `update_option()`, así que el filtro del módulo
> —registrado en `plugins/akibara-business/src/Modules/AddressAutocomplete/AddressAutocomplete.php:32`— **sí**
> corre por esa vía. La revalidación en lectura sigue estando bien justificada, pero por **otras** rutas: un
> `wp option add` (que dispara `add_option_*`, no `pre_update_option_*`), un `wp db query` escribiendo la tabla
> a pelo, o un valor guardado antes de que el módulo existiera. Queda anotado como deuda del comentario, que es
> lo que la regla de la casa pide: la deuda previa también se arregla.

`get()` **lanza una excepción** para toda clave con `owner_file` declarado (`:301-309`): es la
frontera del correo, y el comentario avisa de que no es un obstáculo que haya que rodear —«Si alguien la
"arregla" para poder mostrar el estado del SMTP, el catálogo se convierte en una puerta a las credenciales».
`owner_file_ok()` comprueba con `is_readable()` que el fichero consumidor siga en disco (`:407-425`), con memo
por petición para no hacer seis `stat()` por pantalla.

**`Screen` — la pantalla «Claves y accesos».** Se llega por **`/wp-admin/admin.php?page=akibara-provider-access`**
(el slug está en `:501`). Es submenú de `akibara-panel` (`:497`) — ojo: el menú padre se llama `akibara-panel`,
**no** `akibara`; el slug corto devuelve 403 y parece un problema de permisos cuando no lo es. Capacidad
`manage_options` (`:482,496-503`), **sólo lectura**: no hay ningún formulario que acepte credenciales. La tabla muestra estado,
ámbito, origen (`wp-config.php` o `base de datos`), quién la usa — y como mucho **cuántos caracteres** tiene el
valor (`:576-612`). Tiene una puerta de entorno deliberada (`:514-520`, `:571-574`): fuera de producción, una
clave de servidor ausente **no** es un rojo, porque el espejo apunta a Mailpit sin usuario ni contraseña a
propósito y *«un rojo permanente y FALSO en local entrena a ignorar los rojos de verdad»*. La rama de «falta el
archivo que la usa» sí sigue viva en todo entorno.

**`Cli` — los tres comandos:**

- `wp akibara access-list` (`:638-656`) — la tabla, sin valores.
- `wp akibara access-check` (`:661-694`) — **el gate del despliegue**: sale con error si falta algo obligatorio en
  producción o si desapareció un fichero consumidor.
- `wp akibara access-scan` (`:727-765`) — caza constantes `AKB_`/`AKIBARA_` que el código lea sin declararlas. Usa
  `token_get_all()` para quitar los comentarios **antes** de mirar (`:706-720`), porque la primera pasada en
  producción se quejó de una constante de ejemplo que sólo existía en un PHPDoc del propio fichero.

**Qué ve el cliente.** Nada. Esta pieza es íntegramente para el dueño y para el despliegue.

**Trampas de esta pieza, y cómo quedaron (1.3.0, en producción el 2026-09-16):**

- ~~**El gate valida la FORMA de las credenciales, no el perfil que el transporte exige.**~~ **Cerrado** — ver el
  recuadro de arriba: los patrones son hoy el perfil de producción y `access-check` le pregunta la combinación a
  `Transport::profile_is_valid()`.
- ~~**El patrón `/^true$/` deja pasar la cadena `'true'`.**~~ **Cerrado en 1.1.0 (2026-09-12)** con `expect_bool`:
  el tipo se juzga antes de aplanar, y `tools/test-provider-access-mail-gate.php` fija que catálogo y transporte
  respondan lo mismo a los 8 valores.
- ~~**El inventario y el centinela miden el mismo fichero de dos formas y se contradicen.**~~ **Cerrado.** Hay un
  único predicado, `Transport::is_live()` —clase cargada **y** enganche puesto—, y el inventario lo consulta en la
  columna `owner_live`. Con un fichero presente pero inerte, la pantalla dice «el archivo está, pero no se cargó»,
  `access-list` marca `NO CARGADO` y **`access-check` falla**. Esto último lo cazó la revisión independiente antes
  de desplegar: las pantallas ya distinguían «está» de «cargó» y el gate del despliegue, que es quien decide si un
  despliegue sigue, todavía no. Por eso el cálculo vive ahora en `Cli::problemas()`, que recibe las filas y se
  prueba **ejecutándola**: al gate sólo se le hacía `grep` al texto fuente, y ahí cabía el hueco.
- ~~**`wp akibara access-scan` no puede dar verde.**~~ **Cerrado.** Las constantes propias que no son claves de
  nadie —rutas, versiones, ficheros y dos palancas de prueba, más el buzón de captura del espejo— se declaran en
  `Registry::NOT_A_PROVIDER_KEY`, una lista explícita con su razón; se saltan las carpetas `/tests/`, que escriben
  constantes de mentira a propósito; y el barrido pasó de mirar **tres** carpetas a las **seis** del código
  propio, así que una clave leída a mano en el buscador, en los puntos o en la caja de pago ya no es invisible.
  Sigue fuera de su alcance una constante usada **desnuda**, sin `defined()` ni `constant()`: cazarla pide mirar
  tokens en vez de texto, y está dicho en el propio código.
- ~~**`wp akibara access-list` grita FALTA donde las otras dos superficies dicen «sólo en producción».**~~
  **Cerrado.** El estado se calcula en `Cli::estado_de()`, con la misma puerta de entorno que la pantalla y el
  gate; en el espejo muestra `SÓLO EN PROD`.
- **`Access::get()` y `Access::must()` no los llama nadie.** **SIGUE ABIERTA.** Los únicos aciertos en todo el
  árbol propio están en las pruebas (medido el 2026-09-16 sobre los cinco plugins y el tema). Y el módulo que
  debería usarlos lee la clave a mano: `AddressAutocomplete.php:44` hace
  `defined( 'AKB_GOOGLE_MAPS_API_KEY' ) ? … : get_option( … )`, **sin revalidar el patrón en la lectura**. Con una
  clave de Maps pegada a medias, la pantalla dice «formato inválido» mientras el módulo la sigue imprimiendo en el
  HTML que recibe el cliente. El arreglo es pequeño, pero vive en `akibara-business`: pide una versión del plugin
  principal con su riel, no un despliegue de mu-plugins.

### 5. `akibara-litespeed-guardrails.php` — las barandas de la caché

**En una frase:** fija por código seis comportamientos de LiteSpeed Cache que rompen el tema, cachean el
carrito, apagan la carga diferida de WordPress o dejan al celular sin caché de página, aunque alguien los active
desde la interfaz.

**Ficheros:** `mu-plugins/akibara-litespeed-guardrails.php` (versión 1.5.0).

**La idea que lo sostiene**, escrita en su propio comentario (`:32`): *«la casilla es un estado, no una
garantía»*. Que una opción esté en 0 hoy no impide que vuelva a 1 con un clic o con un preset de «optimizar todo»
de Hostinger. Fijarla desde un filtro sí lo impide.

**De dónde sale el nombre de estos filtros, y por qué un filtro le gana a una casilla marcada.** LiteSpeed
guarda cada una de sus opciones con un **identificador** de texto: `optm-css_comb` para «combinar CSS»,
`optm-js_comb` para «combinar JavaScript», `guest_optm` para el modo de visitante nuevo, `media-lazy` para
«cargar imágenes en diferido», `cache-exc` para la lista de rutas excluidas y `cache-mobile` para «cachear aparte
el celular» (los seis están declarados en `litespeed-cache/src/base.cls.php:136`, `:145`, `:37`, `:203`, `:62` y
`:51`). Cada vez que LiteSpeed **lee** una opción,
antes de devolver el valor guardado dispara un filtro cuyo nombre es literalmente
`litespeed_conf_load_option_` + ese identificador. El mecanismo es `filter_overwritten()`
(`root.cls.php:322-338`), llamado desde `conf()` en `root.cls.php:444`.

Consecuencia: **si devolvemos un valor desde ese filtro, lo guardado en la base de datos deja de importar**. La
casilla del panel puede estar encendida, el panel seguirá diciendo que está encendida, y el efecto no llegará
nunca al HTML. Eso es lo que significa que un filtro «gane» a una casilla marcada: no discute con ella, se pone
después de ella.

Y una trampa de escritura: **el identificador se concatena tal cual**. Escribir `guest-optm` con guion donde el
identificador lleva guion bajo (`guest_optm`) produce un nombre de filtro que no existe, que nadie dispara nunca
y que **no da ningún error** — el fichero parece correcto y la baranda está muerta.

**En qué momento actúa.** Seis filtros, todos en prioridad **99**, todos enganchados a
`litespeed_conf_load_option_{id}`.

| Filtro | Valor que fija | Línea |
|---|---|---|
| `litespeed_conf_load_option_optm-css_comb` | `false` | `:37-43` |
| `litespeed_conf_load_option_optm-js_comb` | `false` | `:56-62` |
| `litespeed_conf_load_option_guest_optm` | `false` | `:93-99` |
| `litespeed_conf_load_option_media-lazy` | `false` | `:135-141` |
| `litespeed_conf_load_option_cache-exc` | añade dos rutas | `:181-185` |
| `litespeed_conf_load_option_cache-mobile` | `false` | `:233-239` |
| `litespeed_conf_load_option_optm-js_defer` | techo en `1` («Deferred»); nunca `2` («Delayed») | `akibara_litespeed_js_sin_retraso()` |

**1. `optm-css_comb` → false.** Combinar CSS rompe la portada entera. El tema difiere las hojas no críticas con el
patrón `media='print'` + `onload="this.media='all'"`, y las marca con el atributo `data-akb-deferred-style`
(`akibara-gp/inc/performance.php:283`). Al combinar, LiteSpeed funde esas hojas en un archivo único y se queda con
el `media` del primer `<link>` — pero **no conserva el `onload`** que las devuelve a `all`. Resultado
**[medido]**: `brands-home.css` y `search-suggest.css` nunca se aplican, el carrusel de editoriales se convierte
en una lista cruda de 1.805 px y el cajón lateral pierde el estilo. Fue una caída real de la portada el
**2026-08-15** (`:16-24`).

**2. `optm-js_comb` → false.** Por el mismo motivo estructural, aunque aquí es prevención y no una caída vivida.
Se documenta *«para que quien lo quite sepa que es una decisión, no un olvido»* (`:45-52`).

**3. `guest_optm` → false. La puerta trasera.** Los dos filtros de arriba actúan dentro de `Root::conf()`. El
problema es que LiteSpeed, en los sitios que de verdad deciden si combinar o no, **ni siquiera llega a llamar a
`conf()`**. Lo que escribe es de esta forma:

```php
$this->cfg_css_comb = defined('LITESPEED_GUEST_OPTM') || $this->conf(self::O_OPTM_CSS_COMB);
```

En PHP, un `||` evalúa de izquierda a derecha y **se detiene en cuanto encuentra algo verdadero**: si
`defined('LITESPEED_GUEST_OPTM')` ya devuelve verdadero, la parte derecha **no se ejecuta nunca**. LiteSpeed no
llega a preguntar la opción, nuestro filtro no corre, y combinar CSS queda encendido pese a las dos barandas
anteriores. Eso es lo que significa «cortocircuitar»: no es que el filtro pierda, es que nadie lo llama. Por eso
hace falta una **tercera** baranda, que apaga la constante desde su raíz desactivando `guest_optm`.

**Cuántos sitios cortocircuita, medido sobre el LiteSpeed instalado hoy (7.9.1).** El comentario del fichero cita
las líneas 288 y 290 de `optimize.cls.php` v7.8.1, y dos sitios; los dos datos están caducados. En 7.9.1,
`css_comb` está en `optimize.cls.php:279` y `js_comb` en `:281`, y `defined('LITESPEED_GUEST_OPTM')` aparece en
**dieciséis** lugares sólo dentro de `optimize.cls.php` (`:73`, `:79`, `:85`, `:114`, `:245`, `:278`-`:284`,
`:317`, `:524`, `:678`, `:1220`) y en **treinta y uno** en todo el plugin, contando `media.cls.php`
(`:823`, `:832`-`:836`, `:1030`, `:1293`, `:1298`), `optimizer.cls.php` (`:85`, `:261`), `placeholder.cls.php`
(`:118`, `:120`, `:263`) y `core.cls.php` (`:264`). **La cita del comentario está caducada; el mecanismo y la
baranda son correctos** — y treinta y un sitios refuerzan el argumento mucho más que dos.

Esa constante la define `Vary::maybe_guest()` para toda visita en Guest Mode cuando `guest_optm` está activo
(`vary.cls.php:222`) — o sea, para el visitante de primera vez, que es justo quien ve la portada por primera
vez.

**4. `media-lazy` → false. La carga diferida es de WordPress, no de LiteSpeed** (`:101-141`). Con esta
opción encendida, `Media::init()` registra `add_filter('wp_lazy_loading_enabled', '__return_false')`
(`media.cls.php:125-128`): es el **único** sitio de todo el plugin que apaga la carga diferida nativa, y
`tools/test-litespeed-guardrails.php` lo cuenta recorriendo el plugin entero. **[medido 2026-09-14]** En
producción la opción estaba encendida y el lazy de LiteSpeed no llegaba al HTML (ni `data-lazyloaded` ni
`data-src`): nadie difería. `/tienda/` en móvil difería 1 imagen de 26 y bajaba de entrada 22 portadas fuera de
pantalla, 3.472 KB de imagen; el espejo, con el mismo tema y sin esa opción, difería 21 de 26 y bajaba 1.275 KB
(`:109-115`). Se fija en `false` y no al revés porque el lazy de LiteSpeed reescribe `src` a `data-src`
y difiere toda imagen que no esté excluida por `data-no-lazy`, clase o URL (`media.cls.php:1052-1076`), la
primera portada con `fetchpriority="high"` incluida. El otro lector de la opción (`media.cls.php:832`) lleva la
misma puerta trasera `defined('LITESPEED_GUEST_OPTM') || …` que el combinado, y la cierra la baranda 3. Esta
baranda no es la única defensa de las portadas: el tema les pone los atributos a mano
(`akibara-gp/inc/performance.php`, capítulo 13, §4.6), así que el catálogo sale bien aunque alguien la quite; lo
que se perdería es el lazy nativo en el resto del sitio.

**5. `cache-exc` → se añaden dos rutas** (`:168-179`). Son exactamente `/wp-json/wc/store/` y `/wp-json/wc/v3/`.
Conviene nombrarlas, porque no son «el carrito y los pedidos»: la primera es la **Store API**, que usan el carrito
y el checkout de bloques; la segunda es la **API REST de administración de WooCommerce**, la que usa entre otras
cosas Blue Express para escribir el número de seguimiento de los pedidos. La única exclusión configurada en el panel
(`cache-excludes_qs`) cubre el carrito **viejo** (`wc-ajax`, `add-to-cart`) y no la Store API: la tienda de hoy
quedaba fuera de la única protección que había. Medido en producción el **2026-09-06** con sesión iniciada: se
quitan tres artículos del carrito, se relee el endpoint y siguen los cuatro, cuatro veces seguidas y durante más
de cinco minutos; la respuesta llegaba con `x-litespeed-cache: hit,private` mientras su propio `Cache-Control`
decía `no-store, private`. Al visitante anónimo no le pasaba: el disparador era **tener sesión**, que es justo la
población del programa de puntos (`:153-159`). La función añade sin pisar, no duplica, y si el valor guardado
tiene una forma inesperada parte de una lista vacía y añade igual las dos rutas obligatorias — hay una prueba
llamada exactamente así en `LitespeedCartExclusionTest.php`.

**7. `optm-js_defer` → como mucho «Deferred»** (1.5.0, 2026-09-19, en producción). La opción vale 0, 1
(«Deferred»: añade `defer`, los scripts corren en orden al terminar de leer la página) o 2 («Delayed»: los
convierte en `type="litespeed/javascript"` y no corren hasta la primera interacción). Producción está en 1. Con 2,
lo que el tema y WooCommerce hacen AL CARGAR deja de ocurrir: el contador del carrito del header —impreso en
HTML cacheado, lo corrige `wc-cart-fragments`— se queda con el número de la copia pública hasta que el cliente
toque algo; el primer «Agregar» de una ficha hace el envío nativo. El tema excluye SUS scripts del retraso, pero
los de WooCommerce dependen de jQuery y no se pueden excluir sin sacar a jQuery del `defer`. La baranda va a la
causa: `akibara_litespeed_js_sin_retraso()` devuelve `min(1, valor)`. **[medido 2026-09-19 en el espejo]** con la
opción guardada en 2, `Conf::conf()` lee 1 y la portada sale sin ningún `litespeed/javascript`. Prueba:
`tests/MuPlugins/LitespeedNoRetrasaElJsTest.php`.

**6. `cache-mobile` → false. La visita desde el celular también guarda la página** (`:187-239`, 1.3.0,
2026-09-15). Con esta opción encendida LiteSpeed espera que el `.htaccess` marque las visitas móviles con
`ismobile` en el vary. Ese bloque (`### marker MOBILE`) sólo se escribe si la lista de agentes `cache-mobile_rules`
trae algo (`htaccess.cls.php:605-610`), y en producción está vacía. Sin marca, `Control::finalize()` hace
`set_nocache( 'is mobile' )` a toda visita de celular (`control.cls.php:788-790`). **[medido 2026-09-15, en el
origen]** Con agente de celular, la portada, una ficha y la política de privacidad responden `no-cache` una y otra
vez; con agente de escritorio, miss en 0,65 s y después hit en 10 ms, y **el celular sirve esa misma copia**. O sea,
que una página estuviera en caché dependía de que antes pasara alguien desde un computador, con unas tres de cada
cuatro visitas desde el celular. Con la opción en `false`, `finalize()` sale sin marcar nada y los dos comparten una
copia, que es lo que ya pasaba con la del escritorio y lo que hace Cloudflare (una copia por URL). El HTML no cambia
por dispositivo: en el código activo sólo Joinchat (su QR, apagado) y Mercado Pago (en el checkout, que no se
cachea) preguntan `wp_is_mobile()`, y nadie filtra `litespeed_is_mobile`. Los otros lectores de la opción no tocan
el HTML servido: el `.htaccess` al guardar, el crawler (apagado), el CSS crítico por dispositivo
(`Root::_separate_mobile()`, sin uso con `optm-css_async` en 0), la sincronización con QUIC.cloud y la pantalla de
ajustes (el interruptor sigue encendido, con el aviso «This value is overwritten by the filter»). El `.htaccess` y
QUIC.cloud leen con `get_options()` y **no** pasan por el filtro: «Restablecer ajustes» carga la lista de agentes de
fábrica y escribiría `### marker MOBILE`, con dos copias por URL; tras tocar LiteSpeed hay que mirar que el marcador no
esté. Guardar la pestaña Caché cuenta la opción como cambiada y vacía la lista de exclusiones del crawler (apagado):
ruido, sin purga. `tools/test-litespeed-guardrails.php` contrasta contra `control.cls.php` que la opción apagada siga
saliendo limpia. Si algún día el marcado depende del dispositivo, lo que corresponde es la lista de agentes con el
vary móvil **y además quitar la baranda**, las dos cosas: con la lista llena y la baranda puesta el servidor separaría
la copia móvil pero PHP saldría antes de comprobar el agente (`control.cls.php:772-791`) y podría guardar HTML de
escritorio en la copia del celular.

**Lo que NO se toca:** el minificado (`optm-css_min`), que sí es seguro y está activo. *«Lo que rompe es fundir
archivos, no comprimirlos»* (`:32-33`).

**Dónde lo opera el dueño.** En ningún sitio, y ese es el punto: el panel de LiteSpeed puede decir lo que quiera,
estas cinco opciones están fijadas desde código.

**Cómo se comprueba hoy, contra la superficie servida.** Una baranda que nadie vuelve a medir es una baranda que
nadie sabe si sigue puesta. Dos comprobaciones, las dos de sólo lectura, las dos marcando la sonda con el
user-agent de QA para que el log de producción sepa que no es un cliente:

```bash
# 1 · ¿sigue la portada sirviendo sus hojas diferidas, o alguien las fundió?
curl -sS -A AkibaraQA https://akibara.cl/ | grep -o 'data-akb-deferred-style' | wc -l
```

Ese atributo lo pone el tema en cada hoja que difiere (`akibara-gp/inc/performance.php:283`). Si el número baja a
**0**, las hojas se combinaron y la portada está en el camino de la caída del 2026-08-15. Va con `grep -o … | wc -l`
y no con `grep -c` a propósito: `grep -c` cuenta **líneas**, y si LiteSpeed minifica el HTML el documento entero
viene en una sola línea — el mismo sitio sano daría «1» o «416» según el día.

```bash
# 2 · ¿el carrito se está cacheando para un cliente con sesión?
curl -sS -o /dev/null -D - -A AkibaraQA -b "$COOKIES_DE_SESION" \
     https://akibara.cl/wp-json/wc/store/v1/cart | grep -i '^x-litespeed-cache'
```

Con una cookie de sesión real, la respuesta correcta es **ninguna línea** o un `miss`. Un `hit,private` es
exactamente el fallo medido el 2026-09-06. Sin sesión no sirve: el disparador era **tener sesión**.

**Nota de mantenimiento, y es una fecha de caducidad.** Los cinco identificadores —`optm-css_comb`,
`optm-js_comb`, `guest_optm`, `media-lazy`, `cache-exc`— están escritos **a mano** en nuestro fichero. Si una actualización de
LiteSpeed renombra cualquiera de ellos, el filtro correspondiente queda enganchado a un nombre que ya no se
dispara: **sin error, sin aviso y sin protección**. Este mismo capítulo demuestra que sus citas a LiteSpeed
caducan (las líneas del comentario cambiaron entre la 7.8.1 y la 7.9.1). La comprobación, después de cada
actualización del plugin:

```bash
grep -n "'optm-css_comb'\|'optm-js_comb'\|'guest_optm'\|'media-lazy'\|'cache-exc'" \
     public_html/wp-content/plugins/litespeed-cache/src/base.cls.php
```

Hoy los cinco salen (`:136`, `:145`, `:37`, `:203`, `:62`). El día que falte uno, hay una baranda muda. `php tools/test-litespeed-guardrails.php` hace este contraste solo, contra el `base.cls.php` del árbol, y además comprueba que el apagado del lazy nativo sigue colgando de `O_MEDIA_LAZY`.

**Qué ve el cliente.** Una portada que no se rompe y un carrito que refleja lo que él acaba de hacer.

---

### 6. `akibara-discovery-warming.php` — política de caché de los mapas del sitio

**En una frase:** declara qué puede cachear el navegador y qué puede cachear Cloudflare de los ficheros XML que
los buscadores leen para descubrir las páginas de la tienda.

**Ficheros:** `mu-plugins/akibara-discovery-warming.php` (versión 1.6.0).

**En qué momento actúa.** Un solo filtro sobre `rank_math/sitemap/http_headers`, prioridad por defecto 10
(`:27-44`).

**Qué escribe** (`:35-39`): `Cache-Control: no-store, must-revalidate, max-age=0` —el navegador no conserva XML—
y `Cloudflare-CDN-Cache-Control` + `CDN-Cache-Control` con `public, max-age=14400` —la CDN puede servir una copia
fresca durante 4 horas—.

**Lo interesante es lo que ya no tiene.** La versión 1.6.0 eliminó el calentamiento periódico por dos razones
medidas (`:7-12`): un acierto de caché **no renueva el TTL del objeto**, así que un trabajo cada 3 h no podía
mantener caliente de forma continua un objeto con TTL de 4 h; y una respuesta 429 del origen llegó a reemplazar
un objeto expirado. La disponibilidad para bots se resuelve ahora en Cloudflare, antes del origen. La auditoría
del 2026-08-23 retiró además la limpieza del evento legado, que colgaba de `init` prioridad 1 —o sea, corría en
**todas** las peticiones— para un trabajo que su propio comentario declaraba de una sola vez; se comprobó que el
trabajo había terminado antes de retirarlo (`:14-20`).

**Nota de estado:** este es el único mu-plugin de producción del área sin `declare(strict_types=1)`. **Ya está
arreglado en el árbol local** —el diff completo contra el snapshot es exactamente esa línea (`:25`)—, así que no
es un arreglo pendiente sino un **despliegue** pendiente. Se hace con el guion de siempre, **nombrando el fichero**:

```bash
bash ops/akibara-prod-muplugins-deploy.sh --dry-run akibara-discovery-warming.php   # primero mirar
bash ops/akibara-prod-muplugins-deploy.sh akibara-discovery-warming.php
```

El nombre del fichero no es opcional. Sin argumentos, el guion sube **sólo** el par del correo —el transporte y
el centinela— (`ops/akibara-prod-muplugins-deploy.sh:47`); con `--all` sube **toda** la carpeta menos la
cuarentena (`:43`), y eso arrastraría a producción cualquier otro cambio a medio hacer que haya en el árbol
local, que es exactamente lo que el CLAUDE.md del repo advierte («el árbol mezcla trabajo sin terminar»). Un
fichero suelto, nombrado, es la forma segura.

---

### 7. `akibara-https-urls-fuera-de-web.php` — https también desde el cron y la consola

**En una frase:** fuerza el esquema `https` en las direcciones de recursos que WordPress genera cuando no hay una
visita web de por medio.

**Ficheros:** `mu-plugins/akibara-https-urls-fuera-de-web.php` (versión 1.0.0).

**En qué momento actúa.** Filtro `set_url_scheme`, prioridad 10, tres argumentos (`:15-38`).

**El problema que resuelve.** WordPress decide el esquema de `content_url()`, `plugins_url()` y
`get_stylesheet_directory_uri()` con `is_ssl()`, que es **falso** en el cron y en WP-CLI. Resultado medido el
**2026-08-22**: los correos generados fuera de una petición web (Action Scheduler, recordatorios, reenvíos) salían
con las imágenes en `http://akibara.cl/…` mientras los enlaces (`home_url`, que respeta el esquema de la opción
`home`) iban en `https`.

**Cuándo actúa exactamente** (`:23-32`): sólo cuando se cumplen tres condiciones a la vez —el llamador no pidió
esquema explícito (`$orig_scheme === null`), el esquema resuelto fue `http`, y la petición actual no es SSL— y
además el sitio vive en `https` según la opción `home`, comprobado una sola vez por petición con una variable
estática. **En una visita web normal no hace absolutamente nada.**

**Nota honesta:** no hay ninguna comprobación de que el host de la URL pertenezca al sitio. En teoría, un tercero
que llamara `set_url_scheme()` sobre la dirección de un proveedor que sólo existe en `http`, sin pasar
`$orig_scheme`, dentro de una tarea programada, la vería reescrita. En la práctica el escenario no es alcanzable
por accidente: cualquier tercero que arme una URL de proveedor la escribe entera o usa `wp_remote_*`, que no pasa
por este filtro. Es una baranda que falta, no un fallo vivo.

---

### 8. `akibara-woo-blocks-i18n.php` — concordancia de los mensajes del checkout

**En una frase:** reescribe una única entrada del catálogo de traducciones de WooCommerce para que el checkout
deje de decir «un dirección de correo electrónico válido».

**Ficheros:** `mu-plugins/akibara-woo-blocks-i18n.php` (versión 1.0.0).

**En qué momento actúa.** Filtro `load_script_translations`, prioridad 10, cuatro argumentos (`:73`).

**El problema.** El catálogo `es_CL` de WooCommerce traduce la plantilla `Please enter a valid %s` como «Por
favor, introduce un %s válido», que asume que toda etiqueta es masculina singular. En el checkout de Akibara eso
producía «un dirección de correo electrónico válido», «un apellidos válido», «un comuna válido», «un celular
válido», «un rut válido» — justo en el punto de mayor fricción comercial.

**La solución** (`:25-29`): una sola sustitución, `'Please enter a valid %s' => 'El campo %s no es válido.'`, que
ancla la concordancia en «el campo» —invariable— y deja la etiqueta como nombre propio del campo.

**Cómo lo hace sin tocar a terceros** (`:46-72`): WordPress no le entrega las traducciones al JavaScript como un
fichero `.po` ni nada parecido, sino como un texto JSON con una forma concreta —se la llama **formato Jed**, por
la biblioteca de JavaScript que la popularizó— que se resume así:

```json
{ "locale_data": { "messages": { "Please enter a valid %s": ["El campo %s no es válido."] } } }
```

Una caja `locale_data`, dentro otra `messages`, y dentro un par por cada texto: la clave es el texto **original en
inglés** y el valor es un **array** cuyo primer elemento es la traducción. Este mu-plugin recibe ese texto,
lo decodifica (`:51`), comprueba que traiga esa estructura (`:52-54`), pisa **sólo** las claves que conoce
(`:57-63`) y lo vuelve a serializar (`:69`). Si el dominio no es `woocommerce` o el JSON no trae esa clave, se
devuelve intacto: el filtro es un **no-op** para los demás ficheros del dominio. No se toca el plugin de terceros
ni su catálogo.

**Por qué sólo hay enganche de JavaScript y no de PHP.** Porque no hay superficie PHP que corregir: la plantilla
`Please enter a valid %s` **no existe** en ningún fichero PHP de WooCommerce —sólo en los bundles de bloques
(`assets/client/blocks/checkout.js`, `checkout-frontend.js`,
`order-confirmation-create-account-frontend.js`)—. El checkout de bloques al caer a PHP y la Store API devuelven
sus propios mensajes, con otros identificadores. Un `add_filter('gettext', …)` sobre esa cadena sería **código
muerto**, que es lo que el dueño prohíbe.

---

### 9. `akibara-rankmath-redirections-abilities.php` — las redirecciones por MCP

**En una frase:** expone las redirecciones de URLs de Rank Math como operaciones que un asistente puede ejecutar
de forma controlada, envolviendo exclusivamente la API oficial del plugin.

**Antes de nada: quién es «el asistente», por dónde entra y si esto está encendido hoy.** «Ability» es el nombre
que WordPress le da a una operación que publica para que un programa la ejecute; **MCP** es el protocolo por el
que un asistente de inteligencia artificial descubre y ejecuta esas operaciones. En akibara.cl la puerta es
`POST /wp-json/mcp/mcp-adapter-default-server`, que sirve el adaptador MCP que **viene dentro de Rank Math**
(`plugins/seo-by-rank-math/vendor/wordpress/mcp-adapter/`); el cliente que se conecta a ella es el mismo que en
las herramientas de trabajo aparece como `rank-math-production`, y se autentica con una **contraseña de
aplicación** de WordPress asociada a un usuario administrador — no hay ninguna credencial propia de este
mu-plugin. Por esa puerta sólo se ven las abilities marcadas con `mcp.public = true`, y **las cinco de este
fichero lo están** (`:210`, `:334`, `:371`, `:416`, `:455`). Están vivas en producción hoy, con una salvedad que
se explica en el párrafo siguiente: la de palabra clave siempre, y las **cuatro de redirecciones sólo si la clase
`\RankMath\Redirections\DB` llegó a cargarse**. El detalle de esa superficie —las otras puertas, quién puede
leer qué y los dos ganchos de auditoría, que **sí escucha** `akibara-abilities-audit.php` y dejan rastro en
WooCommerce → Estado → Registros— está en el
[capítulo 14](14-mu-plugins-abilities-bluex-brevo-clarity.md) (corregido 2026-09-20, ronda 10).

**Ficheros:** `mu-plugins/akibara-rankmath-redirections-abilities.php` (versión 1.0.0).

**En qué momento actúa.** Dos acciones, prioridad por defecto 10:

| Hook | Qué hace | Línea |
|---|---|---|
| `wp_abilities_api_categories_init` | Registra la categoría `akibara-operations` con `wp_register_ability_category()`. | `:274-288` |
| `wp_abilities_api_init` | Registra la ability de palabra clave; carga el autoloader de Rank Math si hace falta; registra las cuatro de redirecciones si el módulo existe. | `:290-464` |

**Una línea que merece mención aparte** (`:299-307`): en contextos donde Rank Math no arranca completo —por
ejemplo WP-CLI— su autoloader de Composer no está registrado, y este mu-plugin hace `require_once` de
`WP_PLUGIN_DIR . '/seo-by-rank-math/vendor/autoload.php'`. **Es la única línea del área que carga código de
terceros a mano**, y es la que decide si las cuatro abilities de redirecciones existen o no. Si tras ese
`require_once` la clase sigue sin existir, el fichero simplemente no registra nada (`:309-311`).

**Permisos** (`:25-27`): `current_user_can( 'rank_math_redirections' ) || current_user_can( 'manage_options' )`,
o sea el mismo que exige la pantalla oficial de Redirections. La quinta ability usa `edit_others_posts` o
`manage_options` (`:212-214`).

**Las cinco abilities:**

| Nombre | Qué hace | Escribe |
|---|---|---|
| `akibara/redirections-status` | Conteos por estado y código HTTP. | no |
| `akibara/redirections-snapshot` | Las filas completas: id, orígenes, destino, código, estado. | no |
| `akibara/redirections-trash` | Mueve IDs explícitos a la papelera. | sí |
| `akibara/redirections-restore` | Restaura IDs explícitos desde la papelera. | sí |
| `akibara/set-focus-keyword` | Escribe la meta oficial `rank_math_focus_keyword` en lote. | sí |

**Las dos de escritura son *fail-closed*.** Antes de tocar nada exigen todo esto (`:94-143`): que el módulo
Redirections exista; **IDs explícitos** (nada de «todas las que cumplan X»); como mucho **500** IDs
(`AKIBARA_RM_REDIRECTIONS_MAX_IDS`, `:18`); un `expected_count` que coincida **exactamente** con el número de IDs;
que **todos** los IDs pedidos estén realmente en el estado de origen, con error nombrando hasta 20 si alguno no lo
está; y `dry_run = true` **por defecto** (`:134`), hay que pedir explícitamente `false` para que escriba.

**Y después de escribir purgan la caché** de aciertos de Rank Math (`:146-150`), porque —esto es un hallazgo del
propio código— «la UI oficial no purga la caché de aciertos al cambiar estado; `DB::delete` sí». Sin esa purga,
una fila retirada seguiría redirigiendo.

**Qué escribe en la base de datos.** Nada propio: cambia la columna `status` de la tabla de redirecciones de Rank
Math vía `DB::change_status()` y escribe la meta `rank_math_focus_keyword` en los posts indicados (`:257`).

**Trampas conocidas y vivas en esta pieza:**

- **El tope de 1000 filas por estado no se declara en ninguna parte.** `akibara_rm_redirections_rows()` pide
  `'limit' => 1000` sin paginar (`:56-61`). Y hay un detalle que lo hace fácil de arreglar y por tanto más
  llamativo: `DB::get_redirections()` devuelve `compact( 'redirections', 'count' )`, donde `count` es el **total
  real** de filas del estado, vía `get_found_rows()` (`class-db.php:101-103`) — y la línea `:62` de este fichero
  lee sólo `$result['redirections']` y **tira el total a la basura**. Detectar el truncamiento es comparar dos
  números que ya están en la mano. Sin eso, un ID real más allá del corte se rechaza con «no están en estado
  active/inactive» (`:122-132`), que es un mensaje **falso**.
- **Una escritura que no cambia nada devuelve éxito.** `$updated = DB::change_status( $ids, $to_state );` y a
  continuación se devuelve `'updated_rows' => (int) $updated` sin compararlo con `count( $ids )` (`:145-158`).
  Toda la pieza está construida fail-closed y el último paso, el único que escribe, es fail-open: si Rank Math
  actualiza 0 filas, la respuesta es `{"dry_run": false, "requested": 5, "updated_rows": 0}`, que un asistente
  leerá como «aplicado».
- **Coste.** Cada `counts()` recorre los tres estados completos, y `change_status` la llama hasta dos veces por
  operación: leer miles de filas para validar cinco IDs.

---

### 10. `akibara-woocommerce-rest-solo-en-la-raiz.php` — que una URL con «wp-json/» en medio no tumbe la página

**En una frase:** hace que WooCommerce trate como «petición de API» **sólo** las rutas que de verdad van a la API,
y no cualquier dirección que lleve `wp-json/` en algún punto del camino.

**Ficheros:** `mu-plugins/akibara-woocommerce-rest-solo-en-la-raiz.php` (82 líneas, **versión 1.0.0**, en
producción — copia local y copia de producción byte a byte idénticas, verificado en sólo lectura el 2026-09-20).

**En qué momento actúa.** Un solo enganche: filtro `woocommerce_is_rest_api_request`, prioridad 10 (`:82`).

**El problema que resuelve, medido.** `WooCommerce::is_rest_api_request()` decide con un
`strpos( REQUEST_URI, 'wp-json/' )`, así que una URL como `/lo-que-sea/wp-json/x` le parece API. WordPress **no**
lo es: sólo enruta a la API lo que está anclado a la raíz (`^wp-json/?$` y `^wp-json/(.*)?`), de modo que esa
petición termina renderizando la **404 del tema**, con `wp_head` y todo. Y ahí se junta el problema: como
WooCommerce creyó que era API, **no corrió `wc_load_cart()`** —sin sesión, sin cliente, sin carrito—, mientras
Google for WooCommerce (`google-listings-and-ads`, 3.9.3) imprime en `wp_head` su etiqueta de conversiones
mejoradas leyendo `WC()->session->get( 'customer' )` **sin comprobar que la sesión exista**. Resultado: fatal. La
404 devolvía un **500**. Ocurrió **cuatro veces entre el 3 y el 12 de septiembre de 2026**, siempre desde URLs de
rastreadores, y se reprodujo a mano el 12-sep con una URL propia.

**Cómo lo hace, sin tocar a nadie** (`:64-80`). El filtro sólo puede **desmentir**, nunca inventar: si WooCommerce
ya dijo que no, devuelve `false` y se acabó (`:65-67`). Si dijo que sí, lo confirma en dos casos:

- la petición trae `?rest_route=` (`:69-71`), que WordPress **sí** atiende en cualquier ruta;
- o la ruta empieza por el prefijo REST del sitio, comprobado contra `rest_get_url_prefix()` y la ruta del
  `home_url()` —con o sin `index.php/` delante, por si los enlaces permanentes lo llevan— (`:44-56`, `:75-79`).

Todo lo demás vuelve a ser una petición normal del sitio, que es exactamente lo que WordPress ya hacía con ella.
Nótese que **no se usa el literal `wp-json`**: se pregunta por el prefijo real, así que si alguna vez se cambia,
esto sigue valiendo.

**Dónde lo opera el dueño.** En ningún sitio: no tiene pantalla, ni interruptor, ni option. Es una corrección de
comportamiento que o está o no está.

**Qué ve el cliente.** Nada, y ése es el objetivo. Antes, un rastreador que pidiera una URL rara podía llevarse un
500; hoy se lleva la 404 del tema, que es lo correcto.

**Qué pasa si se cae.** Vuelve el fatal: cualquier URL que contenga `wp-json/` sin ser API —las fabrican los
rastreadores solas— devuelve **500** en vez de 404, mientras Google for WooCommerce siga imprimiendo esa etiqueta.
No se pierde ningún dato; lo que se pierde es la página de error correcta, y se ensucian las métricas de error del
sitio.

**Muere con el tercero, como manda la regla de la casa.** Si WooCommerce no está, el filtro no lo llama nadie. Y
si WooCommerce corrige su propia comprobación, este fichero sobra: la prueba
`akibara-business/tests/MuPlugins/WooRestSoloEnLaRaizTest.php` mide contra el **core instalado** y avisa el día
que el parche deje de hacer falta.

---

### 11. `akibara-debug-log-rotation.php` — rotación del log y canal de fatales (sólo local)

**En una frase:** evita que el registro de depuración del espejo crezca sin fin, y separa los errores fatales en
un fichero corto propio.

**Ficheros:** `public_html/wp-content/mu-plugins/akibara-debug-log-rotation.php` (versión 1.1.0). **No existe en
el snapshot de producción**: el guion de despliegue lo tiene en cuarentena explícita
(`ops/akibara-prod-muplugins-deploy.sh:32,43`).

**En qué momento actúa.** Dos enganches, los dos inertes si el entorno no es `local` (`:47-49`, `:93-95`):

| Mecanismo | Prioridad | Qué hace | Línea |
|---|---|---|---|
| Acción `shutdown` | 9999 | Rota `debug.log` si pasa de 2 MB, conservando 3 generaciones. | `:79` |
| `register_shutdown_function()` | — | Escribe la última línea de error en `fatal_crash.log` si fue fatal. | `:119` |

**Los dos números de esa tabla son constantes con nombre, y ahí es donde se cambian** (no hay pantalla):
`AKIBARA_DEBUG_LOG_MAX_BYTES` vale `2097152`, o sea 2 MB (`:16`), y `AKIBARA_DEBUG_LOG_KEEP` vale `3`, las
generaciones que se conservan (`:19`). Y hay una tercera función global que decide **qué** fichero se rota:
`akibara_debug_log_path()` (`:26`), que devuelve la ruta del log de depuración de WordPress. El fichero declara
en total tres funciones globales —esa, `akibara_debug_log_maybe_rotate()` (`:46`) y
`akibara_capture_fatal_to_own_channel()` (`:92`)— más las dos constantes.

**El problema que resuelve** (`:4`): el `debug.log` crecía sin rotación hasta mezclar meses de fatales ya
resueltos, notices de terceros y sondas de QA en un solo fichero de varios MB; un fatal **nuevo** quedaba
enterrado, y una auditoría podía atribuir a la versión actual un fallo histórico. Además le da dueño real al
huérfano `fatal_crash.log`, que existía vacío y sin nadie que lo escribiera — *«sugería una observabilidad que
nadie implementaba»* (`:83-85`).

**No usa WP-Cron a propósito** (`:38-42`): *«el propio evento programado depende de que el cron funcione —
exactamente lo que falló y produjo el archivo gigante»*. En su lugar usa un **transient** de 60 segundos,
`akibara_debug_log_rotation_checked` (`:51-54`), para no medir el tamaño del fichero en cada petición. Sin caché
de objetos eso se traduce en una lectura y, una vez por minuto, una escritura en `wp_options` en cada `shutdown`
del espejo.

**Trampas conocidas y vivas en esta pieza:**

- ~~**El docblock dice cuatro niveles y el código captura cinco.**~~ **Cerrado en 1.1.0.** El comentario
  enumera ahora los cinco e incluye `E_USER_ERROR`, diciendo además por qué está: lo lanza código de aplicación
  con `trigger_error()`, que es la vía por la que los terceros meten más ruido.
- ~~**El fichero que existe para acabar con un log sin rotación crea otro log sin rotación.**~~ **Cerrado en
  1.1.0** (S-c63a5754). Los dos logs rotan por la misma función, `akibara_log_maybe_rotate()`: `debug.log` a los
  2 MB conservando tres generaciones y `fatal_crash.log` a los 512 KB conservando dos —más bajo a propósito,
  porque este canal existe para leerse entero de una pasada—. Y la rotación del canal de fatales corre **justo
  antes de escribir**, no en el `shutdown`: un fatal corta la petición y ese hook no llega a ejecutarse.
- **Escribe dentro del docroot**, lo que infringe la regla de la casa de no dejar volcados en la carpeta servida
  por web. El riesgo concreto, sin embargo, **no se materializa**: la función retorna antes si el entorno no es
  `local` (`:93-95`) y el fichero está en cuarentena de despliegue, así que nunca viaja a producción. Es higiene
  pendiente, no exposición.

---

### 12. `akibara-local-cron-spawn.php` — cron resoluble en Docker (sólo local)

**En una frase:** reescribe la dirección del auto-disparo de WP-Cron hacia el nombre interno del contenedor, para
que las colas del espejo no se congelen.

**Ficheros:** `public_html/wp-content/mu-plugins/akibara-local-cron-spawn.php` (versión 1.0.0). En cuarentena de
despliegue por el prefijo `akibara-local-`.

**En qué momento actúa.** Filtro `cron_request`, prioridad por defecto 10 (`:72`). Inerte si el entorno no es
`local` (`:32-34`).

**El problema.** En Docker, `WP_SITEURL` es `http://localhost:8080`, pero ese host y puerto sólo existen en el
Mac: dentro de los contenedores no resuelve y `spawn_cron()` moría con error 7 de cURL. Sin ese auto-disparo,
`action_scheduler_run_queue` nunca corría y las colas de Akibara Search y de preventa se congelaban.

**Qué hace exactamente** (`:31-71`): reescribe **sólo** la URL de la petición hacia
`http://wordpress<ruta>` —el nombre del servicio de compose, constante `AKIBARA_LOCAL_CRON_INTERNAL_HOST`
(`:19`)—, conservando la cadena de consulta, y fija la cabecera `Host` al host original para que WordPress siga
viendo la petición como dirigida al sitio público y no altere ninguna otra decisión (`:63-64`). **Sólo reescribe
`localhost` y `127.0.0.1`** (`:46-48`); cualquier otro host se respeta.

---

### 13. `akibara-local-external-indexing-guard.php` — IndexNow bloqueado fuera de producción (**vive en producción**, pese al nombre)

> **1.1.0 (R3-b9fecf46, 2026-09-19): entorno Y dominio.** Las cuatro guardas preguntaban sólo por
> `wp_get_environment_type()`. Un clon de Hostinger hereda `production` —ya pasó con el correo y con Clarity— y
> habría avisado a Bing de URLs de staging con la clave real. Ahora exigen además el dominio de producción
> (`Transport::is_production_host( home_url() )`, el mismo criterio que sus dos hermanos) y fallan CERRADO si esa
> clase no existe.

**En una frase:** evita que el espejo envíe direcciones de `localhost` al servicio de indexación instantánea de
los buscadores.

**Ficheros:** `public_html/wp-content/mu-plugins/akibara-local-external-indexing-guard.php` (152 líneas, **versión
1.1.0**).

> **Dónde vive de verdad, que no es lo que dice su nombre (corregido 2026-09-20, ronda 10).** Este capítulo lo
> daba por «en cuarentena de despliegue» y lo contaba entre los de sólo-local. **Está en producción, y a
> propósito**: se desplegó en la **ronda 3** como `indexing-guard 1.1.0` (2026-09-19 16:52, hallazgo
> R3-b9fecf46), con la copia local y la de producción byte a byte idénticas (verificado en sólo lectura el
> 2026-09-20). **Lo que engaña es el nombre**, que conserva el prefijo `akibara-local-` de cuando la pieza sólo
> corría en el espejo: por ese prefijo la expresión de cuarentena de los guiones
> —`^(akibara-local-|akibara-debug-log-rotation\.php$)`, `ops/akibara-prod-muplugins-deploy.sh:32` y
> `ops/akibara-prod-mail-health.sh:43`— lo excluye del despliegue automático, de modo que **el fichero viaja por
> fuera de ese riel**. En producción no bloquea nada: la tienda real es justo el caso que las cuatro guardas dejan
> pasar. Pero mientras esté, **IndexNow depende del mu-plugin del correo** — lo explica la condición de abajo.

**En qué momento actúa. Cinco enganches**, todos en prioridad `PHP_INT_MAX`, en tres capas. Comparten una
condición de entrada que **exige dos cosas, no una** (`:38-45`): que `wp_get_environment_type()` sea
`production` **y** que exista `\Akibara\HostingerMail\Transport` y su
`Transport::is_production_host( home_url() )` diga que sí. Es **fallo-cerrado a propósito**: un clon de Hostinger
hereda `production` —ya pasó con el correo y con Clarity—, así que si no se puede comprobar el dominio se trata
como «no es la tienda de verdad» y se bloquea. Lo irreversible aquí es dejar pasar: una URL de staging que
IndexNow ya aceptó no se desindexa pidiéndolo de vuelta.

> **La consecuencia que hay que tener presente.** Esa clase la define `akibara-correo-smtp.php` (v1.3.0 en
> producción, `is_production_host()` en `:659`). El 8 de agosto de 2026 ese fichero **desapareció tres días**. Si
> vuelve a pasar, el daño ya no es sólo «ningún correo»: sin esa clase este guard pasa a fallar cerrado **en la
> tienda real** y cancela los avisos a IndexNow en silencio. Las tablas de «si desaparece…» de este capítulo y
> del 16 prometían que perder el correo no tocaba nada más; por eso esa fila está corregida arriba.

Ninguno de los cinco se limita a esa condición ni todos devuelven el valor original:

| Hook | Capa | Qué comprueba además del entorno | Qué devuelve si corta | Línea |
|---|---|---|---|---|
| `rank_math/instant_indexing/publish_url` | Rank Math | nada más | el valor original | `:62-66` |
| `rank_math/instant_indexing/submit_url` | Rank Math | nada más | el valor original | `:67-71` |
| `pre_update_option_rank_math_indexnow_log` | la option del registro | nada más | el valor **anterior** | `:90-94` |
| `pre_update_option` | la option, por la vía global | que la option sea exactamente `rank_math_indexnow_log` (`:107`) | el valor **anterior** | `:114-118` |
| `pre_http_request` | HTTP, defensa en profundidad | que el host sea exactamente `api.indexnow.org` (`:137`) | un **`WP_Error`**, no el valor original (`:141-145`) | `:147-151` |

Leídas así se ven las tres capas: **dos enganches en la puerta de Rank Math**, **dos sobre la option del
registro** —el específico y el global, porque WordPress ejecuta el global después y hay rutas que se saltan el
otro— y **uno en la salida HTTP**, que es el último recurso y el único que corta con un error en vez de fingir
que no pasó nada.

**El detalle que hace que funcione la capa del registro** (`:42-48`): conservar exactamente el valor anterior hace
que `update_option()` sea un **no-op**, incluso cuando la opción todavía no existe y WordPress pretendía crearla.
Y hace falta la capa global además de la específica porque la ruta REST/manual de Rank Math llama a `Api::submit()`
directamente y puede saltarse el filtro de `submit_url`.

---

### Nota transversal sobre las catorce piezas: nombres en el espacio global

**Nueve de los catorce ficheros declaran funciones y constantes en el espacio de nombres global sin guarda
`function_exists()` / `defined()`.** (Catorce desde el 2026-09-20: se sumó `akibara-mercadopago-nota-sin-correo.php`,
el pegamento a Mercado Pago que calla el correo «Nota para el cliente» de sus notas automáticas —`R7-nota-mercado-pago`,
decisión del dueño delegada—; muere si `MP_PLUGIN_FILE` no está definido y se prueba sin WordPress con
`tools/test-mercadopago-nota-sin-correo.php` y dentro de la suite con `tests/MuPlugins/MercadoPagoNotaSinCorreoTest.php`.)
La lista completa, porque una lista incompleta no sirve para el argumento que viene después:

| Fichero | Qué declara en el espacio global |
|---|---|
| `akibara-correo-no-sale-a-clientes.php` | 4 funciones: `akibara_correo_captura` (`:48`), `akibara_correo_es_de_pruebas` (`:68`), `akibara_correo_direccion` (`:90`), `akibara_correo_no_sale_a_clientes` (`:104`) |
| `akibara-litespeed-guardrails.php` | 1 función: `akibara_litespeed_rutas_sin_cache` (`:168`) |
| `akibara-woo-blocks-i18n.php` | 2 funciones: `akibara_woo_blocks_message_overrides` (`:25`), `akibara_woo_blocks_script_translations` (`:46`) |
| `akibara-rankmath-redirections-abilities.php` | 1 constante `AKIBARA_RM_REDIRECTIONS_MAX_IDS` (`:18`) y **6** funciones: las cinco `akibara_rm_redirections_*` (`:25`, `:35`, `:53`, `:75`, `:94`) **y `akibara_register_focus_keyword_ability`** (`:166`), que es igual de global aunque no lleve el mismo prefijo |
| `akibara-debug-log-rotation.php` | 2 constantes (`:16`, `:19`) y **3** funciones: `akibara_debug_log_path` (`:26`), `akibara_debug_log_maybe_rotate` (`:46`) y **`akibara_capture_fatal_to_own_channel`** (`:92`), que tampoco lleva el prefijo |
| `akibara-local-cron-spawn.php` | 1 constante `AKIBARA_LOCAL_CRON_INTERNAL_HOST` (`:19`) y 1 función `akibara_local_cron_spawn_request` (`:31`) |
| `akibara-woocommerce-rest-solo-en-la-raiz.php` | 2 funciones: `akibara_woo_rest_ruta_es_de_la_api` (`:44`), `akibara_woo_rest_solo_en_la_raiz` (`:64`) |
| `akibara-mercadopago-nota-sin-correo.php` | 3 funciones: `akibara_mp_nota_sin_correo_enganchar`, `akibara_mp_nota_es_de_mercado_pago`, `akibara_mp_nota_sin_correo_filtrar` |
| `akibara-local-external-indexing-guard.php` | **5** funciones `akibara_external_indexing_*` (`:38`, `:58`, `:86`, `:106`, `:129`) — eran cuatro en la 1.0.0 (corregido 2026-09-20, ronda 10) |

**Los otros cinco no declaran nada global, por dos motivos distintos.** Dos lo evitan **por construcción**, usando
una función anónima dentro del propio `add_filter`, que no tiene nombre y por tanto no puede chocar con nada:
`akibara-discovery-warming.php:29-46` y `akibara-https-urls-fuera-de-web.php:15-38`. Los otros tres —el
transporte, el centinela y el inventario— son **clases dentro de un espacio de nombres**.

**Por qué importa: dos copias del mismo fichero dentro de `mu-plugins` producen un error fatal en TODAS las
peticiones.** Y aquí hay que corregir una idea cómoda y falsa: **las tres piezas basadas en clase no están a
salvo**. En PHP, redeclarar una clase es un error **fatal** (`Cannot redeclare class …`), exactamente igual que
redeclarar una función; el espacio de nombres cambia el nombre completo de la clase, no el hecho de que sólo
pueda declararse una vez. **Ninguna de las trece piezas está protegida**: dos copias de cualquiera de ellas dentro
de `mu-plugins` tumban el sitio entero.

Y el riesgo es **mayor** precisamente en la pieza basada en clase más crítica: el transporte
(`akibara-correo-smtp.php:21`, `final class Transport`) es el único fichero del área que **otro proceso copia
solo** dentro de `mu-plugins` (`akibara-mail-transport-watch.php:174`). El día del renombrado —2026-09-11— la
ventana existió de verdad: si el centinela hubiera repuesto el fichero viejo mientras el nuevo ya estaba en
disco, habrían convivido dos `final class Transport` y el sitio entero se habría caído. La forma de evitarlo fue
el orden de los pasos: **apartar primero la copia de rescate** —de modo que una reposición a destiempo no
encuentre origen y responda «sin-copia»— y sólo después renombrar. El peor caso posible pasó de «fatal en todas
las peticiones» a «un aviso amarillo de más en el panel».

---

## Lo programado

**El área no crea ni un solo trabajo programado propio.** No hay `wp_schedule_event()`, ni acciones de Action
Scheduler, ni cron de WordPress registrado por ninguna de las trece piezas. Todo lo que «corre solo» se cuelga de
latidos que ya existen. Esto es deliberado y está documentado en el código
(`akibara-mail-transport-watch.php:62-69`, `akibara-debug-log-rotation.php:38-42`).

| Trabajo | Mecanismo | Frecuencia y hora Chile | Quién lo programa | Qué hace | Qué pasa si corre dos veces |
|---|---|---|---|---|---|
| Inspección del transporte desde el cron | Acción `init` prioridad 1, condicionada a la constante `DOING_CRON` (`watch:78-80`) | Cada **5 minutos**, las 24 horas | **Nadie de Akibara**: es una tarea de hPanel, externa a WordPress, que llama a `wp-cron.php`. `DISABLE_WP_CRON` está en `true` en producción | Comprueba clase y enganche del transporte; si falta, lo repone desde `akb-runtime/` y anota | Dentro de la misma petición, imposible: `self::$inspected` lo impide (`:57,95-98`). **Entre peticiones sí se pisan**: dos inspecciones simultáneas pueden perder una fila del historial `restores` |
| Inspección del transporte desde el admin | Acción `admin_init` prioridad 1 (`watch:65`) | Cada visita de **cualquier usuario autenticado** a `/wp-admin/` | WordPress | Lo mismo que la anterior | Igual que la anterior |
| Avisos del correo en el admin | Acción `admin_notices` (`smtp:83`, `watch:82`, `provider-access:489`) | Cada pantalla del admin | WordPress | Pinta rojo o amarillo según el estado guardado | Idempotente: sólo lee y pinta |
| Rotación de `debug.log` | Acción `shutdown` prioridad 9999 + transient de 60 s (`rotation:51-54,79`) | Como mucho **una vez por minuto**, **sólo en el espejo** | El propio mu-plugin | Si `debug.log` pasa de 2 MB, lo rota conservando tres generaciones | El transient es el candado; una segunda pasada dentro del minuto retorna sin hacer nada |
| Captura de fatales | `register_shutdown_function()` (`rotation:119`) | Al final de **cada** petición, **sólo en el espejo** | El propio mu-plugin | Escribe una línea en `fatal_crash.log` si el último error fue fatal | Añade una línea más: el fichero **nunca se rota** |
| Sonda de salud del correo | `ops/akibara-prod-mail-health.sh` | En cada despliegue y a mano | El despliegue (`muplugins-deploy.sh:105`) | Compara md5 del transporte y de la copia de rescate; abre conexión SMTP real sin enviar nada | Idempotente: sólo lee y autentica |

![Los seis ritmos del área, con las horas en UTC y en hora de Chile](diagramas/15-mu-plugins-correo-litespeed-claves-y-guardas-cron.mmd)

**El dato con más consecuencia del capítulo: dónde vive ese cron y cómo se comprueba que sigue vivo.** El único
latido que funciona cuando nadie abre el admin es la tarea de hPanel, y es **externa a WordPress**: no es un
`wp_schedule_event`, no aparece en `wp cron event list` y **nada dentro de WordPress comprueba que exista**. Su
ficha, hasta donde llega la evidencia del repositorio:

| Qué | Dato |
|---|---|
| Dónde se ve y se edita | Panel de Hostinger (hPanel) → el sitio `akibara.cl` → **Avanzado → Trabajos cron**. No hay equivalente dentro de WordPress. |
| Qué ejecuta | Una llamada a `wp-cron.php` del sitio. En los trabajos cron de hPanel el comando `wp` **no** está en el `PATH` (`ops/carril-ssh-operacion.md:14`). |
| Cada cuánto | **Cada 5 minutos**, declarado así en el panel y confirmado por el dueño al revisar hPanel el 2026-09-08 (ficha del incidente, sección «hPanel revisado independientemente por root»). |
| Qué depende de él | Todo `Watch::inspect()` fuera del admin, **y** el drenaje de Action Scheduler: `DISABLE_WP_CRON` está en `true` en producción. |
| Identificador de la tarea viva | **No consta en el repositorio.** El único uid escrito en ninguna parte es el de la tarea **retirada** el 2026-08-23, `5WxqXVQ5zM` (`ops/akibara-prod-mail-health.sh:49`). |

Esa última fila es en sí misma un hueco: la pieza de la que depende todo el correo automático de la tienda no
tiene su identificador anotado en ningún sitio del repositorio, así que nadie puede reconocerla en el panel ni
notar que la cambiaron. Mientras no se anote, **la comprobación posible es indirecta y se hace desde SSH**:

```bash
ssh akibara
cd ~/domains/akibara.cl/public_html
wp cron event list --fields=hook,next_run_relative,next_run_gmt --format=table | head -20
```

Si el latido está llegando, la lista **no** acumula eventos con el «próximo disparo» en negativo: se drenan solos
en cinco minutos o menos. Un `action_scheduler_run_queue` con horas de retraso significa que **nadie está
llamando a `wp-cron.php`** — y ése es el aviso que hoy no existe dentro de la tienda. Si esa tarea desaparece y el
dueño pasa tres días sin abrir el escritorio, el escenario de agosto se repite entero con el centinela puesto y
callado.

---

## Casos de uso

### Caso 1 — Un cliente paga un pedido y recibe su confirmación

![Un pedido manda su confirmación](diagramas/15-mu-plugins-correo-litespeed-claves-y-guardas-caso-correo-de-pedido.mmd)

1. El cliente termina de pagar. WooCommerce arma el correo de **«Tu pedido en Akibara ha sido recibido»** y llama
   a `wp_mail()`.
2. WordPress aplica **primero** el filtro `wp_mail`, donde está el cepo
   (`akibara-correo-no-sale-a-clientes.php:142`). El entorno es `production`, así que devuelve los argumentos sin
   tocar nada y se acabó (`:105-107`).
3. WordPress aplica **después** el filtro `pre_wp_mail`, donde está el guard del cartero
   (`akibara-correo-smtp.php:54,94`). Aquí hay dos bifurcaciones grandes:
   - **Bifurcación A — todo bien.** La constante `AKIBARA_HOSTINGER_MAIL_ENABLED` es el booleano `true` y el
     perfil valida contra `smtp.hostinger.com:465/ssl` con usuario `contacto@akibara.cl`. El guard deja pasar.
   - **Bifurcación B — falta configuración o el perfil no valida.** El guard anota en la option
     `akibara_mail_transport_outage` el tipo `blocked` con su causa (`:111`), devuelve `false` y `wp_mail()`
     **termina ahí mismo** (`:113`). El cliente **no recibe nada**, y su dirección **no queda registrada en
     ningún sitio**. La próxima vez que alguien con `manage_woocommerce` abra el admin verá el aviso rojo «El
     correo de Akibara NO está saliendo», con la causa y el conteo. Ese aviso **no se puede descartar**:
     desaparece solo cuando un correo vuelva a salir de verdad.
4. Con el camino A, WordPress construye el objeto PHPMailer y dispara `phpmailer_init`. El cartero fija host,
   puerto, cifrado, usuario, contraseña, `Timeout = 20`, y —aquí está la línea consecuente— fija el remitente a
   `contacto@akibara.cl` con nombre `Akibara`, **pisando el `Sender` decidido por WooCommerce** (`:386-392`).
5. PHPMailer conecta con `smtp.hostinger.com`, autentica y entrega. Dos desenlaces:
   - **Salió.** WordPress dispara `wp_mail_succeeded` y el cartero **borra** la option del corte (`:210-218`). El
     cliente recibe el correo con asunto **«Tu pedido en Akibara ha sido recibido»**, en cuestión de segundos.
   - **El servidor lo rechazó.** WordPress dispara `wp_mail_failed` con un `WP_Error`. El cartero recorta el
     mensaje a 140 caracteres y lo anota con tipo `send_failed`, sumando uno al contador (`:129-145`). **El aviso
     rojo no aparece hasta el tercer rechazo seguido** (`:245-247`), porque un rechazo suelto suele ser una
     dirección de cliente mal escrita.
6. **Bifurcación C, poco intuitiva y viva hoy:** `wp_mail_failed` también se dispara cuando PHPMailer no tiene
   **ningún destinatario válido**, sin haber abierto conexión con el SMTP. Tres altas o tres pedidos con el correo
   mal escrito, sin un envío bueno de por medio, encienden el rojo con un texto que afirma «el problema está del
   lado del buzón: contraseña rotada, cuota llena o smtp.hostinger.com caído» (`:285`) — y manda al dueño a rotar
   una contraseña que está perfectamente bien. El mensaje real del servidor, que sí delataría la causa, aparece
   entre comillas al lado.

**Correos que salen en este caso, de dónde sale cada asunto y qué se puede tocar.** Los dos asuntos los pone
WooCommerce, no ninguna pieza de este capítulo:

| Correo | A quién | Plantilla del asunto | Dónde está |
|---|---|---|---|
| Confirmación de pedido | Al cliente | `Your {site_title} order has been received!` → en español de Chile, «Tu pedido en Akibara ha sido recibido» | `plugins/woocommerce/includes/emails/class-wc-email-customer-processing-order.php:68-70` |
| Aviso de venta | Al buzón de la tienda | `[{site_title}]: New order #{order_number}` → «[Akibara]: Nuevo pedido #27720» | `plugins/woocommerce/includes/emails/class-wc-email-new-order.php:76-80` |

Los dos se editan desde el panel, en **WooCommerce → Ajustes → Correos electrónicos**, entrando en cada plantilla.
Y aquí hay que separar dos cosas que este capítulo mezcla si no se dice:

- **Sí tiene efecto** lo que se cambie ahí del **asunto**, del **encabezado**, del **texto adicional** y del
  **destinatario** del aviso de venta (por defecto el correo del administrador,
  `class-wc-email-new-order.php:63`).
- **No tiene ningún efecto** lo que se cambie en la sección «Opciones del remitente» de esa misma pantalla —el
  «Dirección "De"» y el «Nombre "De"»—: el transporte los pisa en `phpmailer_init` con prioridad `PHP_INT_MAX`
  (`akibara-correo-smtp.php:386-392`) y todo sale igualmente de `contacto@akibara.cl`. No hay ninguna pista en la
  interfaz de que eso vaya a pasar.

Cuando el transporte está caído, **no sale ninguno de los dos** y no queda rastro de a quién iban.

---

### Caso 2 — Un domingo por la noche el fichero del cartero desaparece del servidor

![El transporte desaparece y el centinela lo repone](diagramas/15-mu-plugins-correo-litespeed-claves-y-guardas-caso-transporte-desaparecido.mmd)

Esto no es hipotético: ha pasado tres veces, los domingos 23 y 30 de agosto y 6 de septiembre de 2026, alrededor
de las 23:10-23:15 UTC.

1. Algo del servidor borra `wp-content/mu-plugins/akibara-correo-smtp.php`. **Quién** no está identificado —el
   escáner de malware de Hostinger no detectó nada en 30 días y el soporte confirmó que no conservan auditoría de
   operaciones de fichero—, pero el **mecanismo** sí se puede nombrar: en esa misma ventana de segundos se
   reescriben los dos únicos mu-plugins de la plataforma (`hostinger-auto-updates.php` y
   `hostinger-preview-domain.php`), 32 y 29 segundos antes, y nuestro fichero se llamaba entonces
   `akibara-hostinger-mail.php` — el único otro del directorio con «hostinger» en el nombre. La hipótesis, y la
   predicción falsable con fecha que la pone a prueba el **domingo 13 de septiembre a las 23:17-23:20 UTC**, están
   en la pieza 3.
2. A partir de ese instante, `wp_mail()` ya no tiene guard ni transporte: cae al `mail()` de PHP, que en Hostinger
   devuelve `false`. **Todo correo de la tienda deja de salir.** Sin el centinela, esto es invisible: fue
   exactamente lo que pasó del 18 al 21 de agosto, tres días y el pedido 27720 incluido, descubierto porque un
   cliente reclamó.
3. En los siguientes **5 minutos**, el cron de hPanel llama a `wp-cron.php`, que define `DOING_CRON` antes de
   cargar WordPress. El centinela se engancha a `init` prioridad 1 y ejecuta `inspect()`
   (`akibara-mail-transport-watch.php:78-80,94`).
4. `transport_is_live()` comprueba **dos** cosas: que la clase `Akibara\HostingerMail\Transport` esté declarada y
   que su método siga enganchado a `phpmailer_init` (`:120-129`). Falla la primera. Se llama a `restore()`, que a
   su vez consulta la función pura `restore_plan()` (`:142-153`). Tres bifurcaciones:
   - **El fichero falta y hay copia legible en `akb-runtime/`** → plan `copiar`. Se hace `@copy()`. Si devuelve
     verdadero, el desenlace es `'repuesto'` (`:174`), se escribe `missing_since`, `last_seen_gmt` y `outcome`, y
     se **añade la fecha al historial `restores`** (`:220-227`). El aviso rojo dice: «Lo repuse desde la copia de
     rescate; el correo debería volver a salir en la próxima petición. Aun así, algo lo borró: revisa hPanel →
     Seguridad → Malware scanner» (`:336`). **Ojo:** `copy()` devuelve verdadero también para un fichero de 0
     bytes o truncado, así que ese «repuesto» no está verificado.
   - **El fichero existe pero la clase no cargó** (un error de PHP dentro, permisos, opcache sirviendo un fichero
     ya borrado) → plan `presente-pero-inerte`. **No se pisa**, para no tapar la causa ni bajar una versión más
     nueva: así nació el *downgrade silencioso* del cron viejo de hPanel, que reponía la 1.0.0 sobre la 1.1.0.
   - **El fichero falta y no hay copia** → plan `sin-copia`. Hay que subirlo a mano por SSH.
5. Entre el momento del borrado y el instante en que la copia vuelve a cargarse hay **al menos una petición** en
   la que el fichero ya está en disco pero la clase todavía no está en memoria: los mu-plugins ya pasaron. Un
   pedido hecho justo ahí se queda **sin confirmación y sin rastro**.
6. Al día siguiente el dueño abre el escritorio. `admin_init` dispara `inspect()` otra vez; ahora el transporte
   está vivo, así que `clear_outage()` borra `missing_since`, `last_seen_gmt` y `outcome` — y **conserva
   `restores`** (`:254-265`). El aviso pasa de rojo a amarillo: «El transporte de correo desapareció y se repuso
   solo — Ocurrió 3 veces, la última el 6 de septiembre, 20:15.»
7. **Ese aviso amarillo ya no se apaga desde ninguna pantalla.** `is-dismissible` sólo lo oculta hasta la
   siguiente carga de página, y no hay ninguna option, comando ni pantalla que vacíe `restores`. La única salida
   es por consola —`wp option delete akibara_mail_transport_watch`, o vaciar sólo `restores` con
   `wp option patch delete akibara_mail_transport_watch restores`— y **eso borra la evidencia del incidente que
   sigue abierto**: esas tres fechas son el dato que sostiene toda la investigación.

**Correos que salen en este caso:** ninguno, y ése es el punto. La pieza existe porque el correo no puede avisar
de que el correo está roto: la única señal es visual, dentro del admin.

---

### Caso 3 — QA prueba una plantilla de correo en el espejo Docker

![El cepo captura el correo en el espejo](diagramas/15-mu-plugins-correo-litespeed-claves-y-guardas-caso-espejo-captura.mmd)

1. QA monta un escenario con `tools/qa-seed-preventa.php`, que crea pedidos **con los hooks reales del checkout**
   (un pedido hecho con `add_product()` a secas no lleva las metas del snapshot y da falsos «está roto»).
2. WooCommerce cambia el estado del pedido y llama a `wp_mail()` con la dirección real del cliente de prueba.
3. El cepo actúa en el filtro `wp_mail`. El entorno es `local`, no `production`. Bifurcación:
   - **El destinatario es de un dominio reservado** (`.test`, `.example`, `.invalid`, `.localhost`,
     `example.com`, `example.org`) → pasa tal cual, no hay nada que interceptar (`:116,121-123`).
   - **El destinatario es una dirección real** → se reescribe `to` a `capturado@akibara.test`, se antepone
     `[para cliente@real.cl]` al asunto y se añaden las dos cabeceras de rastro (`:130-137`).
4. El cartero aplica el perfil de `local`: `mailpit`, puerto `1025`, cifrado vacío, sin autenticación
   (`wp-config.php:42-46`). El correo aterriza en Mailpit, visible en `http://localhost:8025`.
5. QA abre la bandeja y ve, sin abrir el mensaje, a quién habría ido — que es como se revisa una tanda de doce.

**Bifurcación que muy probablemente arruina esta prueba, y conviene saberla antes de dar un veredicto** —
**[deducido]** de leer el cepo, `pluggable.php` y `WC_Email::get_headers()`, **sin ejecutar en el espejo**: para
todo correo de WooCommerce, el cuerpo llegaría como **HTML crudo sin renderizar y sin `Reply-to`**. La causa no
estaría en la plantilla ni en WooCommerce: estaría en el cepo, que envuelve la cadena multilínea de cabeceras de
`WC_Email::get_headers()` en un único elemento de array (`:133-134`), de modo que `wp_mail()` no la divide
(`pluggable.php:297-305`) y el `Content-Type` acaba valiendo «text/html» más el resto de las cabeceras pegadas
detrás. **Sería un falso negativo y un falso positivo a la vez**: QA puede reportar como roto un correo sano, o
dar por bueno un arreglo de plantilla que nunca pudo ver. Y en producción el cepo no actúa, así que el defecto
**sólo existiría donde se prueba**.

**La comprobación que lo cierra, y que todavía nadie ha corrido:** en el espejo, cambiar de estado un pedido de
prueba, abrir el mensaje en Mailpit (`http://localhost:8025`) y mirar su cabecera `Content-Type`. Si trae el
`Reply-to` pegado detrás, esto deja de ser deducción y pasa a ser un defecto medido, con arreglo en la misma
sesión. Si no lo trae, hay que retirar este párrafo del capítulo. La regla del dueño es literal: primero local,
después producción, y hasta que no está medido no está aprobado.

**Bifurcación silenciosa adicional:** si alguien quita `AKIBARA_HOSTINGER_MAIL_ENABLED` del `wp-config.php` local,
el guard devuelve el valor previo sin cortar y sin dejar rastro (`akibara-correo-smtp.php:97-100`). El correo cae
al `mail()` de PHP, no llega a Mailpit, y **no hay ni option de corte ni aviso rojo** que lo explique.

---

### Caso 4 — El dueño despliega los mu-plugins a producción

![El gate del despliegue](diagramas/15-mu-plugins-correo-litespeed-claves-y-guardas-caso-gate-del-despliegue.mmd)

1. Se ejecuta `ops/akibara-prod-muplugins-deploy.sh`. Lo primero que hace es apartar los ficheros en cuarentena:
   todo lo que empiece por `akibara-local-` y `akibara-debug-log-rotation.php` (`:32,43`). Esos tres nunca viajan.
2. Comprueba que cada fichero a subir **compile** con `php -l` (`:53`), imprime el plan con el md5 y la versión de
   cada uno, sube los ficheros y actualiza la copia de rescate de `akb-runtime/`, verificando el hash remoto
   (`:95`).
3. Llama al gate de claves, `wp akibara access-check`. Recorre las siete entradas del catálogo
   (`akibara-provider-access.php:661-694`). Bifurcación:
   - **Falta una clave obligatoria en producción, o desapareció un fichero consumidor** → imprime las
     advertencias y sale con error. El despliegue se detiene.
   - **Todo verde** → «Todos los accesos obligatorios están configurados».
4. **Aquí está el hueco que conviene tener presente:** el gate valida la **forma** de cada constante, no el perfil
   exacto que el transporte exige. Con `AKIBARA_HOSTINGER_SMTP_USERNAME = 'ventas@akibara.cl'` el gate da verde,
   la pantalla dice «✓ puesta», el despliegue sigue — y el transporte rechaza el perfil y el guard corta todos los
   envíos. (La **cadena** `'true'` en vez del booleano ya no: desde 1.1.0 el catálogo exige el tipo y el gate se
   pone rojo diciendo «definida con formato inválido».)
5. El despliegue termina llamando a `ops/akibara-prod-mail-health.sh` (`:105`), que es quien sí mide de verdad.
   **También se puede correr solo, y conviene**, porque es la única forma de saber si el correo sale sin mandar
   un correo:

   ```bash
   cd /Users/alefvaras/Documents/akibara
   bash ops/akibara-prod-mail-health.sh
   ```

   Se ejecuta desde el Mac, **no** dentro del servidor: el guion abre la sesión él mismo, así que lo único que
   necesita es que `ssh akibara` funcione (conviene probarlo antes, que es la regla de la casa). **No envía ni un
   correo** —la sonda autentica y cierra—, así que es seguro correrlo a cualquier hora, también fuera de la
   ventana de QA en producción. Sale con `0` si todo está sano y con `1` si algo crítico falla. Lo que comprueba:
   compara el md5 del transporte de producción contra el del repositorio (para cazar un downgrade silencioso),
   comprueba que la copia de rescate esté al día, confirma que el centinela está presente, y ejecuta
   `ops/lib/akibara-mail-probe.php` con `wp eval-file`. **La sonda dispara `phpmailer_init` y abre una conexión
   SMTP real, autentica y cierra — sin enviar nada** (`probe:27,30-33`). Si cualquiera de las cinco
   comprobaciones falla (`env=production`, `enabled=true`, `hook=true`, `transport=smtp`, `smtp=auth-ok`), el
   guion sale con 1 y el despliegue se marca como no sano.
6. Por último revisa el log transaccional de WooCommerce de las últimas 48 h y bloquea sólo si la última caída a
   `mail()` es **más reciente** que el último envío correcto: un incidente ya resuelto se informa, no frena el
   despliegue dos días.

**Correos que salen en este caso:** ninguno. Toda la verificación es de sólo lectura, y eso es deliberado: la
sonda se escribió en un fichero aparte precisamente para no enviar nada y para que el escapado de barras de un
`wp eval` inline por SSH no rompiera el espacio de nombres (`probe:6-9`).

---

### Caso 5 — Un asistente retira cinco redirecciones antiguas por MCP

0. **Quién es «el asistente» aquí.** Un cliente MCP —en la práctica, la herramienta que en las sesiones de
   trabajo aparece como `rank-math-production`— conectado a `POST /wp-json/mcp/mcp-adapter-default-server` con
   una contraseña de aplicación de un usuario administrador de la tienda. Todo lo que sigue ocurre por esa
   puerta, sobre producción, y cada paso vuelve a comprobar el permiso por su cuenta.
1. El asistente pide `akibara/redirections-snapshot` para ver qué hay. La ability lee con el lector oficial de
   Rank Math, 1000 filas por estado (`:56-61`), y devuelve `total` y `rows`.
2. El asistente decide retirar cinco IDs y llama a `akibara/redirections-trash` con `ids`, `expected_count: 5` y
   **sin** `dry_run`. Como `dry_run` es `true` por defecto (`:134`), la primera llamada **no escribe nada**:
   devuelve el plan, los conteos antes y una muestra de hasta cinco filas (`:135-143`).
3. Antes de eso, la ability ha comprobado en orden: que el módulo exista (`:95-97`); que haya IDs explícitos
   (`:101-103`); que no pasen de 500 (`:104-106`); que `expected_count` coincida **exactamente** (`:108-114`); y
   que **todos** los IDs estén en el estado de origen, nombrando hasta 20 si alguno no lo está (`:116-132`).
   Cualquier fallo devuelve un `WP_Error` y no se toca nada.
4. El asistente repite la llamada con `dry_run: false`. Ahora sí: `DB::change_status()` mueve las filas y, acto
   seguido, `Cache::purge()` limpia la caché de aciertos de Rank Math (`:145-150`) — **porque la interfaz oficial
   del plugin no la purga al cambiar estado, y sin eso una fila retirada seguiría redirigiendo**.
5. **Bifurcación que hoy no se detecta:** si Rank Math actualiza menos filas de las pedidas —o ninguna—, la
   respuesta es `{"dry_run": false, "requested": 5, "updated_rows": 0}` y **no hay error** (`:152-158`). El mismo
   `expected_count` que se exige a la entrada no se exige a la salida.
6. **Segunda bifurcación que hoy no se detecta:** si hubiera más de 1000 redirecciones en un estado, un ID real
   más allá del corte se rechazaría con «no están en estado active/inactive», un mensaje **falso**. El total real
   viene servido por el propio lector oficial —`DB::get_redirections()` devuelve `count` vía `get_found_rows()`—
   y esta pieza lo descarta en la línea `:62`.

**Correos que salen en este caso:** ninguno.

---

## Reglas de negocio

| Regla | Dónde está escrita | ¿Configurable? | Dónde se configura |
|---|---|---|---|
| Todo el correo de la tienda sale del buzón `contacto@akibara.cl` y de ningún otro | `akibara-correo-smtp.php:26,349,387,392` | **No** | Sólo tocando el mu-plugin y desplegando. Cambiarlo en WooCommerce → Correos electrónicos **no tiene efecto** |
| En producción sólo se acepta `smtp.hostinger.com` en 465/ssl o 587/tls | `akibara-correo-smtp.php:344-351` | **No** | Está en código |
| Si el transporte no está habilitado o el perfil no valida, **no se envía nada** (mejor no enviar que filtrar por el `mail()` de PHP) | `akibara-correo-smtp.php:102-114` | **No** | Está en código |
| Un corte del guard grita desde el primero; un rechazo del SMTP no grita hasta el tercero seguido | `akibara-correo-smtp.php:37-45,245-247` | **No** | Constante `SEND_FAILURE_THRESHOLD` en código |
| El aviso rojo del correo **no se puede descartar**: sólo desaparece cuando un correo vuelve a salir | `akibara-correo-smtp.php:220-226,288-294` | **No** | Está en código |
| Fuera de producción, ningún correo llega a un cliente real: se redirige, no se bloquea | `akibara-correo-no-sale-a-clientes.php:22-25,105,130` | **Sí, el destino** | Constante o variable de entorno `AKIBARA_CORREO_CAPTURA`. **Sin pantalla** |
| Los dominios reservados (`.test`, `.example`, `.invalid`, `.localhost`, `example.com`, `example.org`) pasan sin interceptar | `akibara-correo-no-sale-a-clientes.php:68-81` | **No** | Lista en código |
| El historial de reposiciones del centinela **sobrevive a que el problema se cure** | `akibara-mail-transport-watch.php:220-227,254-265` | **No** | No hay forma de vaciarlo |
| Se conservan las **10 últimas** reposiciones, no las primeras | `akibara-mail-transport-watch.php:54,242-246` | **No** | Constante `HISTORY_LIMIT` en código |
| Un fichero de transporte presente pero inerte **no se pisa** | `akibara-mail-transport-watch.php:142-150` | **No** | Está en código |
| Una clave que viaja al navegador es pública y puede vivir en la base de datos; una que no viaja es secreto de servidor y vive sólo en `wp-config.php` | `akibara-provider-access.php:28-41,268` | **No** | Es la regla estructural del catálogo |
| El inventario **nunca** devuelve el valor de una clave con `owner_file` declarado | `akibara-provider-access.php:296-309` | **No** | Lanza excepción a propósito |
| Fuera de producción, una clave de servidor ausente **no** es un error | `akibara-provider-access.php:514-520,571-574,662-668` | **No** | Está en código. `wp akibara access-list` **no** aplica esta regla (`:641`) |
| El despliegue se detiene si falta una clave obligatoria en producción o el fichero que la consume | `akibara-provider-access.php:661-694` | **No** | Es el gate `wp akibara access-check` |
| Combinar CSS y JS está prohibido, pase lo que pase en el panel de LiteSpeed | `akibara-litespeed-guardrails.php:37-62,93-99` | **No** | Fijado por filtro. El minificado sí está permitido |
| LiteSpeed no puede apagar la carga diferida nativa de WordPress | `akibara-litespeed-guardrails.php:135-141` | **No** | Fijado por filtro. Las portadas del tema llevan además sus atributos a mano |
| `/wp-json/wc/store/` y `/wp-json/wc/v3/` nunca se sirven desde caché | `akibara-litespeed-guardrails.php:168-185` | **Sólo para añadir más** | La función añade sin pisar lo que haya en `cache-exc` |
| Los mapas del sitio no los guarda el navegador, y la CDN los guarda 4 horas | `akibara-discovery-warming.php:35-39` | **No** | Está en código; la Cache Rule de Cloudflare usa el mismo TTL |
| Toda escritura de redirecciones exige IDs explícitos, `expected_count` exacto, tope de 500 y `dry_run` por defecto | `akibara-rankmath-redirections-abilities.php:18,99-134` | **No** | Está en código |
| Después de cambiar el estado de una redirección se purga la caché de aciertos de Rank Math | `akibara-rankmath-redirections-abilities.php:146-150` | **No** | Está en código |
| `set-focus-keyword` sólo actualiza posts **existentes y publicados**, y nunca acepta palabra clave vacía | `akibara-rankmath-redirections-abilities.php:229-238` | **No** | Está en código |
| Los **dos** ficheros de sólo-local no los despliegan los guiones. **Ojo: no es lo mismo que «no están en producción».** La expresión de cuarentena cubre también `akibara-local-external-indexing-guard.php`, y ese fichero **sí está en producción**, desplegado a propósito en la ronda 3 (R3-b9fecf46, 2026-09-19) por fuera de ese riel; el prefijo del nombre es lo único que sugiere «sólo local» (medido el 2026-09-20) | `ops/akibara-prod-muplugins-deploy.sh:32` y `ops/akibara-prod-mail-health.sh:43` | **No** | Expresión de cuarentena en los dos guiones (corregido 2026-09-20, ronda 10: decía «los tres ficheros de sólo-local **nunca** viajan a producción») |
| El espejo nunca avisa a IndexNow | `akibara-local-external-indexing-guard.php:62,67,90,114,147` | **No** | Está en código, con **cinco** enganches en tres capas |
| WooCommerce sólo trata como API las rutas **ancladas a la raíz** (`/wp-json/…` o `?rest_route=`), no cualquiera que contenga `wp-json/`: el filtro sólo puede desmentir la marca, nunca inventarla, y pregunta por `rest_get_url_prefix()`, no por el literal | `akibara-woocommerce-rest-solo-en-la-raiz.php:64-82` | **No** | Está en código; la prueba `WooRestSoloEnLaRaizTest.php` avisa si WooCommerce corrige lo suyo y el parche sobra |
| La guarda de IndexNow exige entorno `production` **Y** dominio de producción, y falla **cerrada** si falta `\Akibara\HostingerMail\Transport`: sin el mu-plugin del correo, la tienda real deja de avisar a IndexNow | `akibara-local-external-indexing-guard.php:38-45` y `akibara-correo-smtp.php:659` | **No** | Está en código (corregido 2026-09-20, ronda 10) |

---

## Datos que deja

| Nombre | Tipo | Dónde se escribe | Autoload | Qué guarda | Quién lo borra |
|---|---|---|---|---|---|
| `akibara_mail_transport_outage` | option | `akibara-correo-smtp.php:181-191` | **no** | `kind` (`blocked` o `send_failed`), `reason` (máx. 140 caracteres), `first_gmt`, `last_gmt`, `count` | `clear_outage()` en `wp_mail_succeeded` (`:210-218`), o sea un envío correcto |
| `akibara_mail_transport_watch` | option | `akibara-mail-transport-watch.php:286-298` | **no** | `missing_since`, `last_seen_gmt`, `outcome` (`repuesto` \| `sin-copia` \| `fallo-al-reponer` \| `presente-pero-inerte`) y `restores`, array de hasta 10 fechas GMT | `clear_outage()` borra las tres primeras claves; **ningún código borra `restores`** (`:254-265`). Sólo a mano: `wp option delete akibara_mail_transport_watch` — y eso borra la evidencia del incidente abierto |
| `akibara_google_maps_api_key` | option | La escribe el módulo de Business, no este área | — | La clave pública de Google Maps | El módulo de Business |
| `akibara_debug_log_rotation_checked` | transient (60 s) | `akibara-debug-log-rotation.php:51-54` | — | Marca de «ya miré el tamaño del log este minuto» | Caduca solo. **Sólo en el espejo** |
| `rank_math_focus_keyword` | meta de post | `akibara-rankmath-redirections-abilities.php:257` | — | La palabra clave de enfoque de Rank Math | Se sobrescribe; nadie la borra desde aquí |
| Tabla de redirecciones de Rank Math | tabla de terceros | `DB::change_status()`, llamado desde `:145` | — | Sólo cambia la columna `status` y `updated` | La papelera de Rank Math, o su limpieza periódica a los 30 días |
| `cache-exc` de LiteSpeed | option de terceros | **No se escribe** | — | El filtro sólo modifica el valor **en la lectura** (`:181-185`); lo guardado no cambia | — |
| `~/domains/akibara.cl/akb-runtime/akibara-correo-smtp.php` | fichero | `ops/akibara-prod-muplugins-deploy.sh` (lo actualiza) y el centinela (lo lee) | — | Copia de rescate del transporte, fuera de la carpeta servida por web | El despliegue la reemplaza |
| `wp-content/debug.log` y `.1` … `.3` | ficheros | Los rota `akibara-debug-log-rotation.php:66-77` | — | Registro de depuración, máx. 2 MB por generación | El propio mu-plugin, **sólo en el espejo** |
| `wp-content/fatal_crash.log` | fichero | `akibara-debug-log-rotation.php:117` | — | Una línea por error fatal: fecha UTC, mensaje, fichero y línea | **Nadie. No se rota nunca** |

**Lo que deliberadamente NO se guarda:** el destinatario, el asunto ni el contenido de los correos que el guard
corta (`akibara-correo-smtp.php:95`), y ningún valor de ninguna clave de proveedor en ninguna superficie
(`akibara-provider-access.php:296-309,389`).

---

## Qué está medido y qué está deducido

El mapa de lectura del que sale este capítulo se cerró con una **cobertura estimada del 92 %**, y dijo por escrito
cuáles eran los dos ojos que le faltaban: **el espejo y producción**. Los trece ficheros se leyeron enteros, y
también el código de terceros con el que se acoplan; lo que **no** se hizo fue ejecutar nada. Esta sección separa
las dos cosas, porque el resto del capítulo las cuenta con el mismo tono y no deberían leerse igual.

**Medido, con fecha y con alguien que anotó el resultado:**

| Qué | Cuándo | Dónde está la evidencia |
|---|---|---|
| La portada se rompe al combinar CSS: `brands-home.css` y `search-suggest.css` no se aplican, el carrusel de editoriales cae a una lista cruda de 1.805 px | 2026-08-15 | `akibara-litespeed-guardrails.php:16-24` |
| Los correos generados fuera de una petición web salían con las imágenes en `http://` | 2026-08-22 | `akibara-https-urls-fuera-de-web.php` |
| El espejo tiene 84 direcciones reales de clientes y 69 cuentas con correo real | 2026-09-06 | `akibara-correo-no-sale-a-clientes.php:8-10` |
| El carrito de un cliente **con sesión** se servía desde caché: cuatro intentos seguidos, más de cinco minutos, con `x-litespeed-cache: hit,private` | 2026-09-06 | `akibara-litespeed-guardrails.php:153-159` |
| `/tienda/` en móvil bajaba de entrada 22 portadas fuera de pantalla (3.472 KB de imagen): LiteSpeed apagaba el lazy nativo y no ponía el suyo | 2026-09-14 | `akibara-litespeed-guardrails.php:109-115` |
| Tres desapariciones del transporte en domingo, cruzadas con `ctime`, `mtime` y el sha256 del fichero | 23 y 30 ago, 6 sep 2026 | ficha `ops/coordinacion/abiertos/2026-09-08-…md` |
| Los dos `hostinger-*.php` se reescriben 32 y 29 segundos antes de cada reposición | 2026-09-11 | misma ficha, sección de QA |
| El correo de producción sale de verdad: `smtp=auth-ok` contra el servidor, tras el renombrado | 2026-09-11 | `ops/akibara-prod-mail-health.sh`, salida en la ficha |
| El correo estuvo tres días caído sin que nadie lo notara, pedido 27720 incluido | 18-21 ago 2026 | `akibara-mail-transport-watch.php:20-37` |

**Leído del código o de una ficha, pero no re-comprobado al escribir:** el contenido actual de
`akibara_mail_transport_watch` (las tres fechas se copiaron de la ficha, no se releyeron contra la option); el
`wp-config.php` **de producción**, que es el que decidiría del todo la severidad del desajuste entre el gate de
claves y el perfil que el transporte exige.

**Deducido de cruzar dos códigos, y todavía sin ejecutar — puede estar mal:**

| Afirmación | De dónde sale | Cómo se cerraría |
|---|---|---|
| ~~El cepo rompe las cabeceras de todo correo de WooCommerce en el espejo~~ **CONFIRMADO y cerrado en 1.1.0** (2026-09-12): eran 19 de los 22 correos, los del cliente | medido en Mailpit | — |
| ~~El cepo no filtra un `Bcc:` que llegue ya como array~~ **CONFIRMADO y cerrado en 1.1.0** (2026-09-12): se retiran las copias a direcciones reales, vengan en cadena o en array, y con el `to` real o de pruebas | medido en Mailpit | — |
| Tres correos mal escritos seguidos encienden el rojo con un texto falso | `wp_mail_failed` del núcleo + el texto de `:285` | tres altas con correo inválido en el espejo, y mirar el aviso |
| Una reposición truncada deja el fichero presente e inerte con una fila ya sumada a `restores` | `@copy()` en `:174` devuelve verdadero también para 0 bytes | reponer desde una copia vacía en el espejo |
| Dos inspecciones simultáneas pierden una fila del historial | leer-modificar-escribir sin candado en `:212-230` | dos peticiones concurrentes con el transporte ausente |

Nada de lo de esa última tabla debería citarse fuera de este documento como un hecho. Y ninguna de esas
comprobaciones necesita producción: **todas se hacen en el espejo**, que es exactamente donde la regla de la casa
dice que empiezan.

---

## Glosario del capítulo

- **mu-plugin** — «must-use plugin». Fichero PHP dentro de `wp-content/mu-plugins` que WordPress carga siempre,
  antes que cualquier otro plugin, y que no se puede desactivar desde el panel.
- **hook** («gancho») — punto del programa donde WordPress se detiene y pregunta «¿alguien quiere hacer algo
  aquí?». Se usa con `add_action` (ejecutar algo) o `add_filter` (modificar un valor que va de paso).
- **prioridad** — número que decide el orden en que corren los enganchados a un mismo hook. Menor corre antes.
  `PHP_INT_MAX` es el número más grande posible: «el último de todos».
- **option** — dato guardado en la tabla `wp_options` con nombre y valor, que sobrevive entre visitas.
- **autoload** — marca de una option que hace que WordPress la cargue en **cada** petición. Las options de este
  capítulo se guardan **sin** autoload: sólo se leen cuando hacen falta.
- **transient** — option con fecha de caducidad. Se usa como candado barato o como caché corta.
- **constante** — valor fijo escrito con `define()` en `wp-config.php`. No se puede editar desde el navegador, y
  por eso es donde viven las contraseñas.
- **SMTP** — el protocolo estándar con el que un programa entrega correo a un servidor de correo.
- **PHPMailer** — la biblioteca que WordPress usa por dentro para armar y enviar el correo.
- **`wp_mail()`** — la función de WordPress que todo el mundo llama para mandar un correo. Es *pluggable*:
  cualquiera puede interceptarla.
- **`pre_wp_mail`** — filtro que permite cortar un envío antes de que ocurra. Devolver `false` desde ahí termina
  `wp_mail()` en el acto.
- **`phpmailer_init`** — acción que entrega el objeto PHPMailer ya construido, justo antes de enviar, para
  configurarlo.
- **`wp_mail_succeeded` / `wp_mail_failed`** — las dos acciones que WordPress dispara después de intentar el
  envío, según cómo haya ido.
- **guard** — en este capítulo, el filtro que decide si un correo sale o se corta.
- **cepo** — en este capítulo, el filtro que desvía todo el correo del espejo a una bandeja inventada.
- **centinela** — en este capítulo, la pieza que vigila desde fuera que el transporte siga instalado.
- **entorno** (`wp_get_environment_type()`) — etiqueta que dice si esta instalación es `production`, `local`,
  `staging` o `development`. En Akibara la fija `wp-config.php:30`.
- **espejo** — la copia de la tienda que corre en el Mac con Docker, para probar sin tocar producción.
- **Mailpit** — buzón de pruebas que corre en el espejo y captura todo el correo en `http://localhost:8025`.
- **TLD reservado** — dominio de primer nivel que la RFC 2606 aparta para pruebas (`.test`, `.example`,
  `.invalid`, `.localhost`): por definición no existe nadie detrás.
- **fail-closed** — diseño que, ante cualquier duda, se niega a actuar. Lo contrario de *fail-open*, que actúa y
  espera que salga bien.
- **`dry_run`** — modo de ensayo: la operación calcula y muestra lo que haría, sin escribir nada.
- **ability** — operación que WordPress publica para que un asistente la pueda ejecutar por MCP, con su esquema de
  entrada, sus permisos y sus anotaciones.
- **MCP** — protocolo por el que un asistente habla con herramientas externas, en este caso con WordPress. En
  akibara.cl la puerta es `POST /wp-json/mcp/mcp-adapter-default-server`, que sirve el adaptador que viene dentro
  de Rank Math, y el cliente se autentica con una contraseña de aplicación de WordPress.
- **formato Jed** — la forma en que WordPress entrega las traducciones al JavaScript: un JSON con la estructura
  `{"locale_data":{"messages":{"texto original":["traducción"]}}}`. Se llama así por la biblioteca de JavaScript
  que la popularizó. Es lo que reescribe `akibara-woo-blocks-i18n.php:46-72`.
- **downgrade silencioso** — bajar una versión de un fichero sin que nadie se entere. Pasó de verdad: el cron
  viejo de hPanel reponía a ciegas una copia congelada en la 1.0.0 encima de la 1.1.0 desplegada, y como el
  fichero estaba, la clase cargaba y el SMTP autenticaba, todas las comprobaciones daban verde. Por eso hoy se
  compara el md5 y no la presencia.
- **cortocircuitar** (un `||` en PHP) — una expresión `A || B` se evalúa de izquierda a derecha y **se detiene en
  cuanto algo es verdadero**. Si `A` es verdadera, `B` no se ejecuta. Es lo que hace que LiteSpeed ni pregunte por
  una opción cuando la constante `LITESPEED_GUEST_OPTM` está definida, y por tanto lo que deja mudo a un filtro
  que estaba puesto en su sitio.
- **fail-open** — lo contrario de *fail-closed*: ante la duda, seguir adelante y dar por bueno el resultado. En
  este capítulo aparece una sola vez, y es un defecto: la escritura de redirecciones devuelve éxito aunque haya
  actualizado cero filas.
- **LiteSpeed Cache** — plugin de terceros que cachea páginas y optimiza CSS y JavaScript. Aquí está en la versión
  7.9.1.
- **Guest Mode / `guest_optm`** — modo de LiteSpeed para el visitante de primera vez. Si está activo, define la
  constante `LITESPEED_GUEST_OPTM`, que fuerza el paquete de optimizaciones saltándose la configuración.
- **combinar** (CSS o JS) — fundir varios ficheros en uno. Es lo que rompe la portada de Akibara; **minificar**
  —comprimir cada fichero por separado— es seguro y está activo.
- **Store API** — la API REST de WooCommerce que usan el carrito y el checkout de bloques
  (`/wp-json/wc/store/`). Distinta de la API REST de administración (`/wp-json/wc/v3/`).
- **sitemap** («mapa del sitio») — fichero XML que lista las páginas de la tienda para que los buscadores las
  descubran. Los genera Rank Math.
- **TTL** — «time to live», cuánto tiempo una caché puede servir una copia guardada antes de tener que pedirla de
  nuevo.
- **IndexNow** — servicio al que se avisa de que una página cambió para que los buscadores la revisiten.
- **Action Scheduler** — la cola de trabajos en segundo plano que usan WooCommerce y los plugins de Akibara.
- **WP-Cron** — el planificador de WordPress. En producción está desactivado (`DISABLE_WP_CRON`) y lo dispara una
  tarea de hPanel cada 5 minutos.
- **WP-CLI** — la consola de WordPress. `wp akibara access-check` es uno de sus comandos.
- **docroot** — la carpeta que el servidor web publica. En producción es
  `~/domains/akibara.cl/public_html`. Nada que sea un volcado o una copia de rescate debe vivir dentro: la copia
  de rescate del transporte está justo un nivel más arriba, en `~/domains/akibara.cl/akb-runtime/`.
- **hPanel** — el panel de control de Hostinger, la empresa que aloja la tienda. Es donde vive la tarea
  programada que llama a `wp-cron.php` cada 5 minutos, y donde está el administrador de archivos con el que se
  puede borrar un mu-plugin sin pasar por SSH. No forma parte de WordPress y WordPress no lo ve.
- **opcache** — caché de código compilado de PHP. Puede seguir sirviendo un fichero que ya se borró del disco, y
  por eso el centinela comprueba la clase y no sólo el fichero.
- **`shop_manager`** — rol de WordPress con permisos de tienda (`manage_woocommerce`) pero sin permisos de
  administración general (`manage_options`).
