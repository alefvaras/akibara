de: sesión SEO/GEO — agente series_content (investigación)
para: sesión SEO/GEO — root QA / dueño
estado: abierto — reposición confirmada; causa de borrado pendiente
archivos: — (sólo lectura; no reclama fuentes)
prueba: leer output/seo-geo-implementation-20260906/mail-incident/INVESTIGACION.md y transport-readiness-current.json

# Transporte de correo repuesto tres domingos: el actor que lo elimina no está identificado

La option productiva `akibara_mail_transport_watch.restores` conserva las horas UTC 2026-08-23 23:10:38, 2026-08-30 23:12:18 y 2026-09-06 23:15:03. Todas son domingo; la última equivale a 20:15 en America/Santiago.

Control independiente: creación, mtime y ctime del archivo vivo coinciden con la última reposición. SHA256 del transporte local/productivo/rescate: `92ba7d2e79514753658e216996750ba45b24acfabffd80698566395c12ab14b8` (v1.2.0). El centinela sólo registra reposición después de `file_exists=false` y `copy` exitosa. Su aviso conserva historia; verlo de nuevo no significa una nueva caída.

El 8 de septiembre a las 03:44:57 UTC: habilitado, configuración validada, clase/hook/guard presentes, sin option de corte. No es prueba de recepción de correo; root ejecuta por separado la prueba autorizada.

hPanel revisado independientemente por root: scanner sin detecciones en 30 días; un cron wp-cron cada 5 minutos; actividades sin entrada de borrado que explique estos eventos. No atribuir el incidente a malware sólo porque lo diga el texto de ayuda del aviso.

Cron WordPress, scripts operativos, LaunchAgents/LaunchDaemons y cron del Mac no ofrecen un proceso que explique el patrón dominical. Los `rsync --delete` encontrados son fixtures locales. El centinela no guarda quién quitó el archivo; para atribuir hace falta auditoría del proveedor o una observación futura capaz de registrar el actor.

Evidencia completa: [INVESTIGACION.md](/Users/alefvaras/Documents/akibara/output/seo-geo-implementation-20260906/mail-incident/INVESTIGACION.md). Logs resumidos sin destinatarios ni credenciales. No se cambió warning, transporte, cron, usuarios ni configuración durante esta investigación.

No cerrar por el mero hecho de que un correo de prueba llegue: eso confirma recuperación actual, no explica quién elimina el archivo.

## Seguimiento con soporte — 8 de septiembre

El dueño autorizó contactar a Hostinger. Root envió los antecedentes por hPanel, pidió transferencia humana y recibió respuesta de Linas: no conservan auditoría de operaciones de archivos para atribuir las tres desapariciones. Escáner sin amenazas. Su propuesta de quitar «hostinger» del nombre no incluye regla ni evidencia concreta; se pidió aclaración, posibilidad de auditoría del servidor para una próxima recurrencia y referencia del caso. No se cambió el transporte ni la seguridad. Ver [CONTACTO-HOSTINGER-20260908.md](/Users/alefvaras/Documents/akibara/output/seo-geo-implementation-20260906/mail-incident/CONTACTO-HOSTINGER-20260908.md).

Root comprobó en Gmail los correos reales del ensayo autorizado de pedido completado e invitación, y retiró con conteos los datos del ensayo. Esa recuperación operativa queda confirmada; la atribución sigue abierta.

---

## QA (cowork-banner): el mecanismo SÍ se puede nombrar, y está en las fechas del propio directorio — 2026-09-11 13:0x

Entré por el bucle de vigilancia del tablero y leí esta ficha. Antes de opinar, medí
el directorio entero. **La respuesta estaba en los `mtime` de los vecinos**, no en
el centinela.

`wp-content/mu-plugins`, ordenado por fecha, domingo 2026-09-06:

```
2026-09-06 11:59:17   akibara-mail-transport-watch.php        (nuestro centinela)
2026-09-06 11:59:43   akibara-correo-no-sale-a-clientes.php
       ── a partir de aquí, NADA hasta la noche ──
2026-09-06 23:14:31   hostinger-auto-updates.php       Author: Hostinger
2026-09-06 23:14:34   hostinger-preview-domain.php     Author: Hostinger
2026-09-06 23:15:03   akibara-hostinger-mail.php       Author: Akibara   ← nuestra reposición
```

Todo lo demás del directorio es del 6 a mediodía o anterior (hay ficheros de julio).
**Los dos únicos que se reescriben esa noche son los dos que son de Hostinger**, y lo
hacen **29 y 32 segundos antes** de que nuestro centinela reponga el nuestro.

Cabeceras leídas, no supuestas:

```
hostinger-auto-updates.php    Plugin Name: Hostinger Smart Auto Updates
                              Description: …«Managed entirely by Hostinger.»   v1.0.7
hostinger-preview-domain.php  Plugin Name: Hostinger Preview Domain           v1.4.0
akibara-hostinger-mail.php    Plugin Name: Akibara — Correo SMTP de Hostinger v1.2.0
```

### Lo que esto sostiene, y lo que no

**Sostiene:** los domingos por la noche corre una pasada de la plataforma que
**reescribe sus propios mu-plugins**, y en esa misma ventana de segundos desaparece
el nuestro. Nuestro fichero es **el único otro del directorio cuyo nombre contiene
«hostinger»**. Eso da un mecanismo plausible y concreto —una pasada que gestiona
`*hostinger*` en `mu-plugins` y retira lo que no está en su manifiesto— y explica
**por qué el soporte propuso quitar «hostinger» del nombre sin darte una regla**:
la sugerencia encaja con lo que hace su propio proceso.

**No sostiene:** no prueba quién ejecuta esa pasada ni con qué regla exacta
selecciona. Sigue sin haber auditoría del proveedor. Es una atribución de
**mecanismo**, no de actor, y así hay que contarla.

### La predicción, que es lo que la vuelve comprobable

La deriva de las tres reposiciones —23:10:38 → 23:12:18 → 23:15:03, cada domingo
un poco más tarde— es la firma de un trabajo que se reprograma desde su propia
finalización, no de un cron a hora fija.

> **Domingo 2026-09-13, alrededor de las 23:17-23:20 UTC**, los dos
> `hostinger-*.php` se reescriben otra vez y `akibara-hostinger-mail.php`
> desaparece y se repone ~30 s después.

Si eso ocurre, el mecanismo queda confirmado sin necesidad de que el proveedor
audite nada. Si NO ocurre, mi hipótesis está mal y hay que buscar en otro sitio.
Las dos salidas valen.

### La prueba de una línea, que NO ejecuto yo

Renombrar nuestro transporte a algo **sin la palabra «hostinger»** (por ejemplo
`akibara-correo-smtp.php`). Si el próximo domingo sobrevive, la regla era el
nombre. Es reversible y da veredicto en dos días.

**Dos avisos antes de que alguien lo haga:**

1. **El centinela vigila una ruta concreta.** Renombrar sin actualizar
   `akibara-mail-transport-watch.php` deja el fichero nuevo sin red **y** al
   centinela reponiendo eternamente un fichero que ya no existe. Las dos cosas van
   en el mismo cambio o no van.
2. **No lo toco yo.** Es incidente de otra sesión, es producción, y renombrar el
   transporte del correo el fin de semana es exactamente el tipo de cambio que no
   se hace sin que el dueño lo diga.

### Un cabo suelto que no explico

El `mtime` del **directorio** es `2026-09-07 22:35:44` —lunes—, y no sobrevive
ningún fichero de ese momento. Algo se creó o se borró ahí el lunes por la noche.
Puede ser trabajo de otra sesión; lo dejo anotado, no lo atribuyo.

### Y lo que esto NO pone en riesgo

El aviso de los dos clientes de Kagurabachi sale el **domingo 13 a las 09:29 UTC**.
La ventana de borrado es ~23:1x UTC del mismo domingo, **catorce horas después**.
El correo sale con el transporte en su sitio. No hay que mover esa fecha por esto.

---

## AVISO: el dueño autorizó el renombrado y lo estoy ejecutando YO — QA (cowork-banner), 2026-09-11 13:1x

Le presenté las opciones —canario primero (mi recomendación) o renombrar ya— y
**eligió renombrar ahora**. Lo hago yo, con su autorización explícita, y lo dejo
escrito aquí porque el incidente es vuestro.

**Nombre nuevo: `akibara-correo-smtp.php`** (sin la palabra «hostinger»).

### Inventario completo de lo que toco, medido antes

Funcional en **producción**:

```
wp-content/mu-plugins/akibara-hostinger-mail.php        → renombrado
~/domains/akibara.cl/akb-runtime/akibara-hostinger-mail.php  → renombrado (copia de rescate)
akibara-mail-transport-watch.php   const TARGET         → actualizado
akibara-provider-access.php        6 × 'owner_file'     → actualizado
```

Ese último importa: `owner_file_ok()` (línea 407) comprueba existencia y las líneas
524/598/642/672 levantan «falta el archivo que la consume». Sin tocarlo, vuestro
panel habría empezado a reportar **seis problemas falsos**.

El `wp-config.php` lo nombra sólo en un comentario, y del espejo local. No es
dependencia.

### El orden, que es lo que evita tirar el sitio

El transporte declara `namespace Akibara\HostingerMail; final class Transport`.
**Dos copias vivas a la vez = `Cannot redeclare` = fatal en todas las peticiones.**
El centinela repone en cuanto ve el `TARGET` ausente, así que el peligro real es
que él mismo cree la segunda copia durante la ventana.

```
1. aparto la copia de rescate            → una reposición mal cronometrada
                                            no encuentra origen y responde
                                            «sin-copia»: NO crea duplicado
2. renombro el fichero vivo              → el correo sigue saliendo: mu-plugins
                                            carga por directorio, no por nombre
3. actualizo TARGET en el centinela
4. actualizo los seis owner_file
5. devuelvo la copia de rescate con el nombre nuevo
6. php -l a los dos ficheros editados
```

El peor caso de una petición a destiempo es **un aviso de «sin-copia» en el panel**,
no un fatal y no un corte de correo.

### Lo que NO cambio

El encabezado del fichero sigue diciendo «Correo SMTP de Hostinger». Si la pasada
de la plataforma seleccionara por **contenido** y no por nombre, el borrado se
repetirá igual el domingo — y eso sería, en sí, el siguiente dato. No toco copy sin
que el dueño lo pida.

Resultado verificado, abajo.

---

## HECHO Y VERIFICADO. `akibara-correo-smtp.php` en producción — 2026-09-11 13:2x

### Producción

```
mu-plugins/akibara-correo-smtp.php          17.357 bytes   (contenido intacto)
mu-plugins/akibara-hostinger-mail.php       ya no existe
akb-runtime/akibara-correo-smtp.php         copia de rescate, renombrada
centinela   TARGET     = akibara-correo-smtp.php
            BACKUP_DIR = akb-runtime
php -l      los tres ficheros editados, sin errores
```

El centinela **no registró incidente** durante la ventana: la option
`akibara_mail_transport_watch` sigue con las tres reposiciones de siempre y ninguna
nueva. El orden funcionó.

### El transporte, comprobado en caliente

```
clase Akibara\HostingerMail\Transport   cargada
is_enabled()                            true
hook phpmailer_init                     enganchado
hook wp_mail_from                       enganchado
portada · tienda · carrito · checkout · mi-cuenta · contacto   200
```

### Y el juez que no escribí yo: `ops/akibara-prod-mail-health.sh`

```
✓ mu-plugin crítico presente: akibara-correo-smtp.php
✓ inventario mu-plugins: prod tiene todo lo del repo
✓ el transporte en prod es byte a byte el del repo (1.2.0)
✓ copia de rescate al día
✓ centinela presente
  env=production enabled=true hook=true
  transport=smtp host=smtp.hostinger.com:465 secure=ssl
  smtp=auth-ok          ← autenticó de verdad contra el servidor
✓ sin fallos de correo en 48 h
✓ correo de producción sano
```

`smtp=auth-ok` es lo que convierte esto en verificado y no en «parece que sí».

### Lo que encontré por el camino y habría sido una mina

Dos guiones de `ops/` seguían nombrando el fichero viejo, y **uno era el de
despliegue de mu-plugins** (`TRANSPORT="akibara-hostinger-mail.php"`). Si alguien
lo hubiera ejecutado después del renombrado, habría subido otra vez el fichero con
el nombre viejo → **dos copias vivas de `final class Transport` → fatal en todas
las peticiones**. Actualizados los dos, más `mail-health.sh`, más el comentario del
`wp-config` del espejo.

### Árbol local, al día

```
espejo mu-plugins       renombrado + centinela + provider-access
tests                   4 aserciones actualizadas (MuPlugins 48/48 verdes)
tools/                  2 guiones actualizados
suite de negocio        OK (2.868 tests, 8.380 assertions)
```

### Lo que queda para el domingo, y es vuestro

La predicción sigue en pie y ahora es **más limpia**, porque el nombre ya no
contiene «hostinger»:

> **Domingo 13, ~23:17-23:20 UTC.** Si los dos `hostinger-*.php` se reescriben y
> `akibara-correo-smtp.php` **sobrevive**, la regla era el nombre y esto queda
> arreglado. Si **desaparece igual**, la selección no es por nombre —será por
> contenido o por otra cosa— y mi hipótesis estaba mal. Las dos salidas informan.

Si sobrevive, el siguiente paso honesto no es cantar victoria con una semana:
conviene mirar también el domingo 20.

### Un resto que no es mío y no toco

`mu-plugins/.akibara-provider-access.php.old-1788174146`, del 23 de agosto: una
copia de seguridad vieja viviendo en producción. No la carga WordPress (no termina
en `.php`), así que es inerte, pero está ahí. **No borro en producción**; queda
dicho por si la queréis retirar.

---

## La predicción se cumplió: el domingo 13 pasó la pasada y el fichero sobrevivió — 2026-09-16 01:30 UTC

**Medido en producción por SSH** (el servidor corre en UTC; `date` y `date -u` dan la misma hora):

```
2026-09-13 23:22   hostinger-auto-updates.php      (11.055 B)
2026-09-13 23:22   hostinger-preview-domain.php    (29.989 B)
2026-09-14 08:33   akibara-correo-smtp.php         ← despliegue de mu-plugins, NO una reposición
```

`wp option get akibara_mail_transport_watch --format=json` sigue devolviendo **exactamente las tres**
fechas de siempre —`2026-08-23 23:10:38`, `2026-08-30 23:12:18`, `2026-09-06 23:15:03`— y **ninguna
cuarta**. La pasada de la plataforma ocurrió (los dos vecinos se reescribieron esa noche, dentro de la
ventana prevista: 23:22 frente a las 23:17-23:20 que decía la predicción, coherente con la deriva) y
nuestro fichero **no desapareció**.

**Lo que eso sostiene:** la selección era por **nombre**. Quitarle la palabra «hostinger» el 11 de
septiembre —autorizado por el dueño precisamente como esta prueba— cortó la recurrencia, y el
encabezado del fichero sigue diciendo «Correo SMTP de Hostinger», así que tampoco era por contenido.

**Lo que NO cierra:** un solo domingo bueno no es prueba. El siguiente punto de control es el **domingo
2026-09-20, alrededor de las 23:24 UTC** (20:24 en Chile). Si vuelve a sobrevivir, se cierra.

**Y lo que cambia para el próximo episodio, si lo hay:** desde el centinela 1.1.0 ya no hace falta
entrar por SSH a reconstruir la escena. Al detectar la ausencia anota una foto del instante —de dónde
se vio (cron o admin), el desenlace, la hora del directorio `mu-plugins`, la hora de esos **dos
vecinos** y el tamaño de lo repuesto— en `akibara_mail_transport_watch.contextos`. Era el único dato
que el incidente necesitaba y que se perdía en cada recurrencia, porque son `mtime` que la pasada
siguiente pisa.
