# business 1.116.10 — calidad AVIF 60 y recompresión de portadas desde el panel

- **Abierto:** 2026-09-14 · **estado:** segunda vuelta de revisión independiente tras corregir sus hallazgos; sin desplegar
- **Reclama:** `public_html/wp-content/plugins/akibara-business/src/Modules/ImageQuality/` (nuevo),
  `src/Plugin.php` (cableado), `src/Modules/AdminPanel/AdminPanel.php` (`SECTIONS_WITHOUT_CACHED_SURFACE`),
  `uninstall.php`, `akibara-business.php` (1.116.10), `tests/Modules/ImageQuality/`,
  `tests/Modules/AdminPanel/PanelPurgePolicyTest.php`, `tests/CacheBustContractTest.php` (exención con motivo),
  `tests/Modules/TaxonomyDiscount/DiscountCeilingSettingsTest.php` y `tests/Modules/Forms/ContactoSubmitEmailTest.php`
  (deuda previa: dependían del orden), `tools/akibara-releases/business-qa/qa-imagenes-calidad.mjs`.
- **Continúa** la línea de trabajo de `2026-09-11-diagrama-arquitectura-y-qa-integral` (misma sesión) y el traspaso
  `2026-09-14-tema-2-1-126-portadas.md`, donde está la medición.

## Decisión del dueño (2026-09-14)

- Calidad **60** para las imágenes AVIF que se suban («60 (Recomendado)»), vista con capturas a tamaño real y lupa ×3.
- Recomprimir lo ya subido: «lo ideal es que sea lo más completo posible».

## Qué hace

- **Panel → Sitio → Imágenes**: campo «Calidad AVIF (de 40 a 90)», 60 de fábrica, validado con una sola función
  para guardar y leer (inválido = no se guarda y el panel lo dice). Guardarla no purga la caché.
- Filtro `wp_editor_set_quality` sólo para `image/avif` (la única vía que llega al codificador; medido).
- Botón «Recomprimir las portadas ya subidas con calidad N»: pasada en Action Scheduler, de a 20 s por corrida,
  Imagick a un hilo, candado, cursor por ID, continuación a los 60 s, estado y avance en la pestaña.
  **Sólo portadas** (`portadas/`: el logo y los banners también son AVIF y no se midieron). Cada tamaño se recodifica
  desde la misma fuente que core y **sólo reemplaza si pesa ≤ 90 %** con las mismas medidas; nunca toca el original.
  Al terminar, si algo cambió, `litespeed_purge_all` (vacía también el borde de Cloudflare: las URLs no cambian).

## Medido

- Suite business 3.265 en verde con 5 semillas aleatorias y orden natural; mutaciones de las reglas clave muertas.
- Espejo, `qa-imagenes-calidad.mjs` PASS: subida nueva byte a byte con calidad 60; portada reciente con sus 8
  tamaños reemplazados (450 px 114 → 77 KB, 560 px 154 → 102 KB, ~700 px 206 → 135 KB) y antigua con los 7 intactos;
  originales y AVIF ajeno intactos; fichas pintan su portada.

## Revisión independiente (primera vuelta) y correcciones

- **Alta, pasada muerta:** la corrida siguiente se agenda antes de trabajar, la portada en curso queda
  anotada y se salta si PHP muere en ella, presupuesto revisado entre archivos, estado «detenida» en el
  panel y reinicio posible.
- **Alta, purga desproporcionada:** ya no purga al terminar; agenda una sola purga a las 04:30 de Chile.
- **Media:** fuente = la de core (sin atajo `-scaled`); fuentes > 16 MP se omiten; prioridad 20 en la cola;
  Imagick con memoria acotada y restaurada; peso anotado en la metadata en crudo sin pasar por los filtros
  de LiteSpeed (en prod `img_optm-auto` = 1).
- **Bajas:** resultado validado (ruta y AVIF), errores permanentes marcados, archivo compartido una vez,
  temporales únicos, `DISTINCT`, barrido en `uninstall.php`.
- **Deuda previa arreglada al pasar el gate completo:** `SearchSnapshotContractTest` metía todo el JSON en un
  argumento de `-r` (en Linux > 128 KB → «Argument list too long»); ahora va por la entrada estándar.
- **Gate del selector de series:** su rojo eran 3 imágenes que faltaban en el disco del ESPEJO (uploads a
  medias), vistas en `/tienda/` de escritorio; repuestas desde producción.

## Revisión independiente (segunda vuelta) y versión 3 — 2026-09-15

La segunda revisión levantó un bucle infinito y dos formas de corromper el estado. Todo corregido:

- **Alta, bucle infinito:** cortar por presupuesto a mitad de una portada hacía que la corrida siguiente la
  empezara **de cero**; con una portada más lenta que los 20 s, la pasada no terminaba nunca y quemaba CPU
  cada minuto. Ahora el presupuesto se revisa **entre portadas**: la portada empezada se termina entera.
- **Media, corrida vieja que pisa a la nueva:** el candado no identificaba a su dueño, así que una corrida
  a la que le robaron el candado seguía escribiendo el estado y al salir borraba el candado ajeno. Ahora el
  valor es `marca|token|renovación`, se escribe con SQL (`INSERT IGNORE` + `UPDATE … WHERE option_value =
  <lo leído>`) y **toda** escritura del estado renueva el candado primero; quien lo perdió se va sin
  reemplazar, sin escribir y sin borrar nada. `start()` y `purge_edge()` también lo toman.
- **`add_option()` no es atómico** (hace `INSERT … ON DUPLICATE KEY UPDATE`): con la caché de objetos
  desfasada pisaba el candado en vez de fallar. Se cambió por el patrón de `WP_Upgrader::create_lock()`.
- **Media, reanudar perdía cosas:** retomar borraba `en_proceso` (la portada que mató a PHP volvía a
  intentarse y a tumbarlo) y la deuda de purga. Ahora, con la misma calidad se retoma **donde quedó**; la
  portada que cortó la corrida se marca y se cuenta en «omitidas»; con otra calidad se empieza de cero pero
  la deuda de purga viaja.
- **Bajas:** `limit_imagick()` dentro del `try` y además `register_shutdown_function` (tras un fatal lsphp
  conserva los límites); la purga ya no pisa el progreso (va con candado y se reintenta a los 10 min); el
  estado se relee con `wp_cache_delete` antes (caché de objetos de producción); una fuente ausente ya **no**
  se marca (puede ser pasajero) y la enorme sí.

**Verificación de la versión 3:** módulo 49 pruebas / 181 aserciones; suite completa 3.279 en verde; PHPCS
del plugin en **cero** (se saldó la última deuda: un filtro de core en `performance.php` del tema sin
`phpcs:ignore`); `qa-imagenes-calidad.mjs` PASS en el espejo con la tanda encadenando sola (800 de 1.589 al
momento de escribir, candado con 112 renovaciones, 1 pendiente + 1 en curso).

## Deuda detectada y NO corregida aquí (va en 1.116.11)

- `src/Modules/SeoSocial/SocialImageDerivatives.php:369-379` usa el mismo candado con `add_option()` que se
  acaba de demostrar no atómico, y al soltarlo no comprueba que siga siendo suyo. Mismo arreglo, release
  aparte para que se revise por separado.

## Tercera vuelta de revisión, versión 4 y despliegue — 2026-09-15

**Alta.** Marcar la portada en vuelo a la primera la dejaba fuera **para siempre**: una corrida también se
corta por el tope de tiempo, el reciclado del proceso o la memoria de otra cuenta del hosting, y con
~320 corridas repartidas en más de un día eso no es un caso de esquina. Ahora hay **un reintento**: a la
segunda interrupción seguida en la MISMA portada se marca, se cuenta en «omitidas» y el aviso del panel
dice cómo reintentarla (recomprimir con otra calidad). `start()` ya no marca nada a ciegas.

**Medias.** (a) El reintento de la purga se reagendaba +10 min sin mirar la hora: encadenando reintentos
el `litespeed_purge_all` podía caer a media mañana; ahora, si esos diez minutos ya son de día, se va a la
próxima 04:30. (b) Agendar la purga **cancelaba** la que ya hubiera: una purga que terminara a las 04:35
empujaba el vaciado a mañana y el borde servía copias pesadas 24 h más; ahora respeta la pendiente.

**Bajas corregidas.** `quality = 0` daba por revisada toda portada sin marca (`(int) '' === 0`); el barrido
de temporales podía mirar el directorio de trabajo cuando el adjunto no tenía archivo; `purga_agendada`
prometía una fecha aunque la cola rechazara la acción; tras un fatal el candado bloqueaba 15 minutos sin
proteger nada (ahora el `register_shutdown_function` también lo suelta); la omitida ya no se cuenta
además como revisada y el panel no muestra «1.610 de 1.604»; el barrido de `uninstall.php` cubre tres
niveles de `portadas/`; y el panel enseña el **último aviso**, que antes se guardaba y no se mostraba.

**Pruebas nuevas** (56 en el módulo del recompresor, 21 en el de la calidad): dos corridas vivas peleando
el candado por el camino real (INSERT IGNORE → leer → CAS), el robo de un candado vencido por una sola,
el reintento acotado en sus dos fases, la purga que respeta la pendiente, el reintento que no se sale de
la madrugada, `quality = 0`, el botón con y sin permiso, el contrato entre los tres resultados de
`start()` y los avisos del panel, y un candado de fuente: el candado **no** puede volver a la API de
options.

**Verificación final:** suite 3.289 en verde (también en orden aleatorio), PHPCS en cero, `qa.sh` rc=0
con el gate del selector de series en Chromium y WebKit, `qa-imagenes-calidad.mjs` PASS en el espejo.
**Desplegado a producción el 2026-09-15 03:44 UTC**; la pasada se encoló con calidad 60 sobre 1.603
portadas (609,3 MB de partida) y el primer ciclo dejó 11 portadas revisadas, 54 tamaños reemplazados y
~1 MB menos, con la tienda en 200 y sin errores.
