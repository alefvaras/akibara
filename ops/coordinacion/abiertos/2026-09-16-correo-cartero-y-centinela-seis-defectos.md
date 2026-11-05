de: sesión bis-avenida [8c4c125d] (desarrollo)
para: QA · y el dueño para la decisión del final
estado: esperando-respuesta — DESPLEGADO y verificado en producción el 2026-09-16 ~01:5x UTC; lo cierra QA tras re-medirlo
archivos: public_html/wp-content/mu-plugins/{akibara-correo-smtp.php,akibara-mail-transport-watch.php,akibara-provider-access.php,akibara-debug-log-rotation.php} · plugins/akibara-business/tests/MuPlugins/{HostingerMailTransportTest,MailTransportWatchTest,ProviderAccessTest}.php
prueba: suite completa de business en verde (3.394, cuatro pruebas nuevas tras la revisión) · phpcs y `php -l` limpios · verificado en el espejo Docker con los avisos renderizados y los tres comandos de consola

# El correo: el cartero y su centinela — los defectos que el propio documento declaraba

El dueño pidió, sobre la sección «El correo: el cartero y su centinela» del manual:
*«revisa el documento y todos los problemas que aparezcan en él soluciónalos»*. Lo que
sigue son los que estaban declarados —en la ficha de hallazgos de QA y en las «trampas
conocidas y vivas» del capítulo 15— y su arreglo.

## Lo arreglado

| Hallazgo | Qué pasaba | Qué hace ahora |
| --- | --- | --- |
| S-8517b3c9 | El centinela reponía la copia de rescate **a ciegas** y el aviso prometía por escrito que el correo volvería a salir. Con una copia truncada, vacía o del fichero equivocado, esa frase era falsa | `backup_is_valid()` exige tres marcas del transporte **antes** de copiar, y después compara lo escrito con lo leído. Desenlace nuevo `copia-invalida`, con su propio texto, que no promete nada |
| S-80f052f9 | Dos inspecciones a la vez —el cron y el admin en el mismo minuto— hacían leer-modificar-escribir sobre la misma option: una reposición **desaparecía del historial**, que es el dato por el que la pieza existe | `save()` relee y **funde** (`merge_restores`, `merge_contexts`). Fundir no necesita candado: la unión de dos lecturas de un conjunto es el conjunto |
| S-010cece9 | El aviso naranja **no se podía cerrar nunca**: `is-dismissible` de WordPress no guarda nada, así que volvía en cada pantalla del admin, para siempre | Enlace con nonce que guarda `history_ack`. Vuelve solo si el fichero desaparece otra vez |
| S-81ab3aa2 | El centinela se cuelga del cron de hPanel y **nadie vigilaba ese cron**: si muere, deja de vigilar justo cuando más falta hace | Anota `last_cron_gmt` en cada tick y avisa en producción si lleva más de 30 minutos parado. Fuera de producción calla, porque allí el cron no corre a propósito |
| S-04d13bef | Los correos cortados no dejaban rastro de **cuáles** eran: «se bloquearon 14 envíos» y nada más. El incidente de agosto se descubrió porque reclamó un cliente | Se anotan destinatario y asunto de los últimos 20, y el aviso nombra los tres últimos: «Se quedaron sin salir N correos, y hay que reescribirlos a mano» |
| S-8302c3f2 | Se emitía una acción propia al anotar el corte **sin un solo oyente**: código muerto, y una promesa escrita de que algo reaccionaba | Se retiró. El candado de reentrada se conserva con su razón verdadera: cualquier oyente del guardado que intente avisar por correo vuelve a entrar por `wp_mail()` |
| S-8adef06b | El inventario medía «existe el fichero» y el centinela «la clase cargó y el enganche está puesto». Con un fichero presente pero inerte, «Claves y accesos» pintaba verde mientras el centinela gritaba: **el verde era el que mentía** | Un único predicado, `Transport::is_live()`, y una columna `owner_live`. La pantalla, el aviso, `access-list` y `access-check` dicen «el archivo está, pero no se cargó» |
| S-51339ef7 | `wp akibara access-list` gritaba **FALTA** en el espejo por claves que las otras dos superficies llaman «obligatoria sólo en producción» | El estado se calcula en `Cli::estado_de()`, con la misma puerta de entorno. En el espejo dice `SÓLO EN PROD` |
| S-8034c7e6 | `wp akibara access-scan` **no podía dar verde nunca**: delataba constantes propias que no son claves de nadie. Un gate que siempre sale amarillo se acaba ignorando | Lista explícita `NOT_A_PROVIDER_KEY` (17 entradas, cada una con su razón), se saltan las carpetas `/tests/` y el barrido pasa de tres carpetas a **las seis** del código propio |
| S-c63a5754 | El fichero que existe para acabar con un log sin rotación **creaba otro log sin rotación**: `fatal_crash.log` crecía sin tope | Rotación común para los dos, y la del canal de fatales corre justo antes de escribir, porque un fatal corta la petición y el `shutdown` no llega |
| (capítulo 15, §3) | `inspect()` copiaba ficheros dentro de `mu-plugins` en la petición de **cualquier usuario autenticado**: `admin_init` también corre para una clienta que va a sus pedidos | Sólo actúa en el cron —que no tiene usuario y corre cada 5 minutos— o con `manage_woocommerce` |
| (capítulo 15, §3) | El centinela **no registraba ningún contexto** de la desaparición: ni la hora del directorio, ni la de los vecinos, ni el tamaño de lo repuesto. Cada episodio obligaba a reconstruirlo por SSH mirando `mtime` que el episodio siguiente pisa | Al detectarlo guarda una foto del instante en `contextos`: origen (cron o admin), desenlace, `mtime` del directorio, `mtime` de los **dos** `hostinger-*.php` y bytes de lo repuesto. Una por episodio, no una por comprobación |

## Medido en el espejo (Docker), con todo restaurado después

- `access-list`: las dos claves SMTP salen como `SÓLO EN PROD`, no como `FALTA`.
- `access-scan`: **verde por primera vez** — «Ninguna constante de proveedor se lee fuera del catálogo».
- `access-check`: verde.
- Inventario: las seis filas del correo traen `owner_live=true` junto a `owner_file_ok=true`.
- Avisos del centinela: el del historial sale **con enlace** y sin `is-dismissible`; acusado, calla;
  si vuelve a ocurrir, vuelve a avisar; la copia inválida **no** promete que el correo vuelva; y el
  aviso del cron parado calla fuera de producción.
- Aviso del cartero: nombra destinatario y asunto de los correos que no salieron.

## Lo que queda, y no lo decido yo

**`Access::get()`, `Access::must()` e `is_configured()` no los llama nadie fuera de las pruebas**
(medido hoy sobre los cinco plugins propios y el tema). Y el módulo que debería usarlos lee la clave
a mano: `plugins/akibara-business/src/Modules/AddressAutocomplete/AddressAutocomplete.php:44` hace
`defined( 'AKB_GOOGLE_MAPS_API_KEY' ) ? … : get_option( … )`, **sin revalidar el patrón en la lectura**.
Con una clave de Maps pegada a medias, la pantalla dice «formato inválido» mientras el módulo la sigue
imprimiendo en el HTML que recibe el cliente.

Es código muerto según la regla de la casa, y el arreglo es pequeño —que el módulo pida la clave al
inventario—, pero vive en **akibara-business**: obliga a una versión del plugin principal con su riel,
no a un despliegue de mu-plugins. Queda propuesto para la próxima entrega de business.

> **16-sep, respuesta:** aplicado en el árbol y medido en el espejo —`get()` e `is_configured()` ya
> tienen llamador de producción— en `2026-09-16-maps-key-por-el-catalogo-de-accesos.md`. Sin desplegar:
> espera versión y riel de business. `must()` y `MissingProviderAccess` siguen sin llamador y la
> decisión de retirarlos o no queda planteada allí.

## La revisión independiente encontró un bloqueante, y tenía razón

Antes de desplegar, revisión en sólo lectura. Un hallazgo **bloqueante**:

> **El gate del despliegue daba verde con el transporte inerte.** Toda esta tanda existe para que
> «el fichero está» deje de confundirse con «el fichero cargó»… y `wp akibara access-check` —lo que
> decide si un despliegue sigue adelante— seguía mirando sólo `owner_file_ok`. Con un transporte
> presente pero inerte decía «Todos los accesos obligatorios están configurados» y el despliegue
> continuaba con el correo caído.

Aplicado: el cálculo salió de `check()` a `Cli::problemas()`, que ahora también levanta esa rama —una
vez, no seis— y **no** se filtra por entorno: un transporte que no carga es correo que no sale, aquí y
en el espejo. La revisión avisó además de que el hueco existía porque al gate sólo se le hacía `grep`
al fuente; por eso `problemas()` es una función que recibe las filas y se prueba **ejecutándola**.

Lo demás que levantó, aplicado en la misma pasada:

- **Los correos de clientes guardados no caducaban.** El tope de veinte no es una caducidad: con poco
  tráfico, veinte filas pueden ser de hace medio año. Ahora caducan a los 30 días.
- **`handle_dismiss` no tenía prueba.** La tiene: sin capacidad no acusa, con un nonce falsificado
  tampoco, y con las dos cosas acusa y redirige.
- **Un `else` inalcanzable en `validate_configuration()`**, con el docblock de `environment()`
  prometiendo una defensa que no existía («cualquier entorno desconocido falla la validación»).
  Retirados los dos.
- **Una referencia de línea obsoleta** en el inventario (apuntaba a `akibara-correo-smtp.php:406-407`;
  hoy es `:630-632`). La otra que se citaba —`ops/lib/akibara-mail-probe.php:27`— se comprobó y es
  correcta.

Tras aplicarlo: suite completa **3.394 en verde**, phpcs limpio, `php -l` limpio.


## Desplegado y verificado en producción — 2026-09-16

`ops/akibara-prod-muplugins-deploy.sh` con paridad **byte a byte** en los tres ficheros y la copia de
rescate regenerada:

```
akibara-correo-smtp.php            f6d1b9bbe7b13ef3793f195528b564a2  1.3.0
akibara-mail-transport-watch.php   0dd42a3898f636d01c12f8900305255a  1.1.0
akibara-provider-access.php        7e5493fa91f2192a46cc13c9282950c3  1.3.0
```

Salud del correo: entorno `production`, transporte habilitado, enganche puesto, `smtp=auth-ok` contra
`smtp.hostinger.com:465`, cero fallos en 48 h.

Medido en producción después:

- `wp akibara access-check` → verde. `access-scan` → **verde** (primera vez en producción).
- `access-list` → las claves SMTP en `ok` (allí sí están puestas) y las tres opcionales en `—`.
- `Transport::is_live()` → sí; las seis filas del inventario con `owner_live=true`.
- `akibara_mail_transport_watch` → **intacta**: las tres reposiciones dominicales siguen ahí, sin
  `missing_since` ni desenlace, o sea que el centinela no tuvo nada que reponer.
- `akibara_mail_transport_outage` → no existe: ningún corte de correo.
- Front sin fatales: portada 200 (0,25 s), tienda 200, mi cuenta 200, carrito 200.

**Para QA:** queda por observar (1) que el aviso naranja del historial aparezca con su enlace en el
escritorio y se quede cerrado tras pulsarlo —producción tiene tres reposiciones y ningún `history_ack`—,
y (2) que el aviso de «las tareas de fondo no están corriendo» NO salga en condiciones normales: el
primer latido lo escribe el cron en los 5 minutos siguientes al despliegue.
