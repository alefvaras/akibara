de: sesión bis-avenida [8c4c125d] (desarrollo)
para: QA · y el dueño para lo que queda
estado: esperando-respuesta — DESPLEGADO y verificado en producción el 2026-09-16 ~14:21 UTC; revisión independiente cerrada (un bloqueante, arreglado). Lo cierra QA tras re-medirlo
archivos: public_html/wp-content/mu-plugins/akibara-litespeed-guardrails.php · public_html/wp-content/themes/akibara-gp/{functions.php,style.css} · public_html/wp-content/plugins/akibara-business/src/Modules/{OrderPreventa/ReadyToShipCron.php,AnnouncementBar/AnnouncementBar.php,UpsellsAutofill/UpsellsAutofill.php,AddressAutocomplete/AddressAutocomplete.php} · akibara-business.php · sus pruebas
prueba: suite de business 3.410 en verde · `tools/akibara-business/qa.sh` rc=0 · `tools/akibara-releases/qa-theme.sh` rc=0 (96 pruebas) · `tools/test-litespeed-guardrails.php` PASS · phpcs y `php -l` limpios

# Los defectos que el manual del dueño declaraba, arreglados

Encargo del dueño: *«del documento todos los defectos detectados en él los soluciones»*, sobre
«Akibara por dentro». El manual declara ~20 defectos repartidos en sus cinco capítulos. Esta ficha
cubre el primer lote; el resto queda listado abajo.

## Arreglado en este lote

| Defecto (con las palabras del manual) | Pieza | Qué cambia |
| --- | --- | --- |
| «la misma nota sale si las líneas perdieron su marca por una edición manual o importación, sin reembolso, y el cliente se queda sin aviso» | business · `ReadyToShipCron` | `nota_de_cierre()`, pura y probada, decide por si queda alguna línea con marca de preventa. Sin marcas la nota nombra la causa, dice que el aviso ya **no puede** salir y que hay que restaurar la marca o avisar a mano |
| «Colores de la barra sin comprobar que sean colores» | business · `AnnouncementBar` | `color_o_por_defecto()` valida `#rgb`/`#rrggbb` en el punto de uso; lo demás cae al de fábrica |
| «No tiene interruptor: sólo se apaga desactivando el plugin» | business · `UpsellsAutofill` | Interruptor en Panel → Recomendaciones, con nonce y capacidad. Apagado **retira** el evento del cron; `cron_fill()` además se corta solo |
| «si LiteSpeed guarda su lista de rutas excluidas en un formato distinto al esperado, la lista propia del sitio se pierde en lugar de sumarse» | mu-plugin LiteSpeed 1.4.0 | `akibara_litespeed_lista_de_rutas()` normaliza array o texto con saltos de línea, recorta y no repite |
| «el aviso al navegador para bajar primero la portada del libro no se emite nunca» | tema 2.1.130 | `global $product` está vacío en `wp_head:2`; ahora se pide a la consulta con `wc_get_product( get_queried_object_id() )` |
| «`Access::get()` y `Access::must()` no los llama nadie» | business · `AddressAutocomplete` | Lo cerró la sesión `akibara-provider-access`; aquí se integra y verifica |

## Declarados en el manual y que YA no eran ciertos

Comprobados contra el código antes de tocar nada, y corregidos en la documentación en vez de
«arreglados» dos veces:

- «la pieza que protege el checkout se rompería del todo —error fatal en plena página de pago— si
  LiteSpeed cambiara el formato de ese dato»: el parámetro es `mixed` con comprobación desde 1.1.0.
- «basta con guardar un término, aunque sólo se haya tocado la descripción»: cerrado en search 0.1.65.
- «El gate valida la FORMA de las credenciales, no el perfil que el transporte exige»: cerrado; los
  patrones del catálogo **son** el perfil y `access-check` pregunta a `Transport::profile_is_valid()`.
- «El docblock dice cuatro niveles y el código captura cinco» (rotador de logs): cerrado en 1.1.0.

## Empaquetado

```
akibara-business-1.116.15.zip   322 ficheros   5946752dc95ba1b8b595316f54752dbbd93bf8fdf939279bae8bcd1bd75ed972
akibara-gp-2.1.130.zip          110 ficheros   c324c34184592a34c0331f28dd3c7ee69eee067e4c45c279c8e5a7fd4c070016
```

(Los dos primeros paquetes, anteriores a la revisión, se retiraron sin desplegar: el empaquetador se
niega —con razón— a reescribir un artefacto distinto con el mismo número.)

El QA del tema confirma los acoples declarados: checkout 2.2.18, buscador 0.1.65, business 1.116.15.

## Lo que queda del manual, por pieza

- **business:** jerga «stock status onbackorder» en la pestaña Disponibilidad · once textos que
  aceptan quedar en blanco mientras los demás vuelven a fábrica · «Series en la portada» pide
  escribir la dirección de cada serie en vez de elegirla · el correo y el texto del retiro en Metro
  San Miguel sólo en código · el plazo de 180 días de la lista de espera · las portadas JPG para
  compartir, sin forma de regenerarlas ni ver su avance · la fecha de la política de consentimiento
  sin conversión de huso · el umbral de producción de Google que depende de `HTTP_HOST` (falso
  negativo en cron y WP-CLI) · el patrón de la clave de Maps duplicado en el saneador del panel.
- **buscador:** un producto bloqueado tras 8 intentos sólo se desbloquea por terminal · el correo de
  alarma manda a un comando que no muestra los dos datos que pide mirar · «Los más buscados que no
  tienes» puede listar como faltante algo que sí se encuentra, si el cálculo falla a medias · sin
  pantalla: qué dispara la reconstrucción, el umbral y destinatario del correo, los topes de 7 tomos
  y 2 series.
- **puntos y reseñas:** el formulario de reseña de la ficha no acepta foto, nunca paga y no se lo
  explica al cliente · los textos de los correos del programa sólo se cambian en código y no salen
  en la pantalla de correos de WooCommerce.
- **tema:** enlaces a Instagram y Facebook, los otros tres sellos, el orden de las series, colores y
  tipografías: sólo código.
- **pegamento:** no hay pantalla que liste las acciones vetadas a los asistentes.

## Decisión pendiente del dueño

`must()` y `MissingProviderAccess` siguen sin llamador. Recomendación de esta sesión: **conservarlas**
y que el runbook de claves (`ops/akibara-claves-de-proveedores-runbook.md:72`) quede como su llamador
declarado —retirarlas obliga a otro despliegue de mu-plugins y son el paso documentado para la
próxima clave—. Si el dueño prefiere la regla estricta de cero código muerto, se retiran en la
siguiente tanda de mu-plugins.


## La revisión independiente encontró un bloqueante, y era serio

> **La nota nueva mandaba al dueño a un botón que no existe.** Decía «reenvíalo desde Acciones del
> pedido», y esa acción NO se ofrece en este pedido: `ReadyEmailDispatcher` la añade sólo si quedan
> líneas en espera, que es exactamente lo que no hay en esa rama. Y forzándola tampoco serviría: el
> correo se arma con esas líneas y saldría **vacío**. El cliente seguía sin aviso y ahora además el
> dueño perdía el tiempo buscando el botón.

Arreglado con la fórmula que ya usa la casa: la nota dice que el aviso **no puede salir** y que hay que
restaurar la marca o avisar a mano. Y la prueba que fijaba el texto falso ahora fija lo contrario:
`test_la_nota_no_manda_a_un_boton_que_no_existe`.

Lo demás que levantó, todo aplicado:

- **El discriminador no era el hecho que separa las dos causas.** `get_total_refunded()` suma TODOS los
  reembolsos: uno parcial del despacho con las marcas borradas devolvía la nota del reembolso y el caso
  volvía a ser invisible. Ahora se decide por si queda alguna línea con marca de preventa
  (`PreventaItems::era_preventa()`), sin consultas nuevas.
- **El panel de Recomendaciones mentía al apagarlo**: decía «se agenda solo en la próxima visita».
  Tercera rama en el bloque de estado.
- **La option del interruptor sin autoload** y se lee en cada `init`: ahora con autoload.
- **`clear_schedule()` en vez de `wp_unschedule_event()`**: retira todas las ocurrencias —con
  duplicados en el cron, la otra tardaba varias visitas en converger— y de paso ese método deja de ser
  uno sin llamador.
- **Preload**: `imagesrcset` sólo junto a `imagesizes` (sin él, el scanner asume `100vw` y puede bajar
  un candidato más ancho: la doble descarga que el bloque existe para evitar), y no se emite en fichas
  protegidas con contraseña.
- **El color de fábrica del fondo** pasó a `#0a0a0a`, el mismo de los dos CSS y el que hay guardado en
  producción: con la variable siempre escrita, el de antes habría cambiado la barra a rojo si la option
  estuviera vacía.
- **Maps**: `try/catch` en los dos lectores, para que un id renombrado en el catálogo no salga por el
  checkout de un cliente.
- **El docblock de `cache-exc`** ya no afirma que por la interfaz se guarde texto: en LiteSpeed 7.9.1
  no pasa, y la rama es defensa contra importaciones y `wp option update`.

## Desplegado y medido en producción — 2026-09-16 ~14:21 UTC

```
akibara-business-1.116.15.zip   322 ficheros   5946752dc95ba1b8b595316f54752dbbd93bf8fdf939279bae8bcd1bd75ed972
akibara-gp-2.1.130.zip          110 ficheros   c324c34184592a34c0331f28dd3c7ee69eee067e4c45c279c8e5a7fd4c070016
akibara-litespeed-guardrails.php 1.4.0         (paridad byte a byte por el guion de mu-plugins)
```

- WordPress reporta 1.116.15 y 2.1.130, con mtimes reales (no 1980).
- `wp akibara access-check` verde; salud del correo sana (4 envíos en 48 h, sin fallos).
- **La precarga de la portada ya se emite** en la ficha, con `imagesrcset` + `imagesizes`
  (`(max-width: 782px) 100vw, 782px`) y `fetchpriority="high"`.
- La barra sigue igual: `--akb-ann-bg:#0A0A0A;--akb-ann-color:#FFFFFF;--akb-ann-border:#D90010`.
- Recomendaciones: interruptor encendido y tarea agendada.
- `akibara_litespeed_lista_de_rutas( "texto\ncon líneas" )` → `/una-ruta/ · /otra/`.

**Borde sin purgar, a propósito.** El único cambio de marcado añade la precarga; las fichas cacheadas
ya se sirven en 10 ms, expiran a medianoche y el recalentado de las 00:20 las repone con el marcado
nuevo. Purgar a mediodía costaría una primera visita lenta por ficha para ganar poco.

**Nota de QA:** en una corrida del `qa.sh` apareció un fallo «selector tiene target 44x44» que **no se
reprodujo** en la siguiente (misma versión, mismo paquete). Prueba de navegador inestable, no defecto;
si reaparece, vale mirarla.
