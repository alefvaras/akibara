de: sesión akibara-eb [f3c498] (Opus 5) — RETOMA el 2026-09-12 23:30 tras el relevo akibara-3f → akibara-d2f7 (ninguna de las dos sigue activa)
para: cualquiera — aviso de alcance; sesión akibara-4b [5f7392] en particular
estado: en-curso — pipeline de ARREGLOS de los ~190 hallazgos confirmados, plugin por plugin, con despliegue y medición en prod; documentación final después
archivos: public_html/wp-content/plugins/akibara-rewards · public_html/wp-content/plugins/akibara-cart-checkout-blocks · public_html/wp-content/plugins/akibara-business/src/Modules/OrderPreventa/ReadyEmailDispatcher.php · public_html/wp-content/plugins/akibara-business/tests/Modules/OrderPreventa · public_html/wp-content/plugins/akibara-business/src/Modules/SeoSocial · public_html/wp-content/plugins/akibara-business/tests/Modules/SeoSocial · public_html/wp-content/plugins/akibara-business/akibara-business.php · public_html/wp-content/plugins/akibara-business/uninstall.php · public_html/wp-content/plugins/akibara-search/src/Indexer.php · public_html/wp-content/plugins/akibara-search/tests · docs/arquitectura · docs/para-el-dueno · public_html/wp-content/mu-plugins/akibara-catalogo-abilities.php · ops/barrido-disponibilidad.php · (al arrancar cada arreglo se reclama su plugin aquí; el desarrollo se hace en copias aisladas del scratchpad y el swap al árbol vivo lo hace el líder)
prueba: (n/a — documentación + QA; cada hallazgo llevará su comando)

# Diagrama de arquitectura de TODO el código first-party + QA integral

Encargo del dueño (2026-09-11 23:00 CL): diagrama de flujo detallado de los
cuatro plugins, el tema y los mu-plugins —cómo interactúan, sus crons, casos de
uso y lógica de negocio—, explicado para alguien que no conoce nada. Mientras se
documenta, QA: los bugs se arreglan, se prueban en local, se despliegan y se
re-prueban en prod. Las preguntas de negocio van al dueño.

## Qué hago y qué no

- **Leo un snapshot congelado** extraído de `dist/` (business 1.116.4, search
  0.1.60, checkout 2.2.17, rewards 0.3.13, tema 2.1.118) y de los mu-plugins de
  producción. **No leo el árbol vivo como verdad**: tiene trabajo a medias de
  otra sesión (12 ficheros de business y 10 de rewards difieren del ZIP).
- **No edito nada** hasta pasar por `chequear` y hablar con quien lo tenga
  reclamado. Business y search están reclamados por bis-avenida en
  `despliegue-par-business-search` y `hooks-que-no-existen`.
- Salida: `docs/arquitectura/` (fuentes Mermaid/D2 + SVG + narrativa) y un
  Artifact publicado para el dueño. Graphify se actualiza al cierre.

## Hallazgos

(se añaden aquí con su reproducción; los que sean de negocio van al dueño)

### H1 · Seis webhooks «BrandAgent» siguen ACTIVOS y mandando datos a Microsoft (decisión del dueño, no bug de código)

Medido en prod 2026-09-11 23:25 CL (`wp wc webhook list`): webhooks 1-6, todos
`active`, con destino `adsagentserverprod-…azurefd.net` (el Brand Agent de
Microsoft Clarity, «Akibara AI», que el dueño APAGÓ el 2026-08-06). Hoy el
webhook 6 (`product.updated`) disparó **359 veces**, todas con 200. Los topics
`cart.updated` y `checkout.created` llevan datos del cliente. Volumen del log
`wc-logs/webhooks-delivery-*`: 2,4 MB el 4-sep, 2,3 MB el 5-sep, 0,9 MB hoy.

- No es defecto de nuestro código: los crea el plugin `microsoft-clarity`.
- **Pregunta al dueño:** ¿quieres que los seis pasen a `paused` (o se borren)?
  Mientras sigan activos, cada guardado de producto y cada carrito viaja a ese
  servidor aunque el agente esté apagado.

### H2 · El espejo corre terceros más viejos que producción (infraestructura de QA)

| plugin | espejo | prod |
| --- | --- | --- |
| bluex-for-woocommerce | 3.2.3 | **3.3.1** |
| litespeed-cache | 7.8.1 | **7.9.1** |
| woocommerce-mercadopago | 8.9.2 | **8.9.3** |

Lo que el espejo mida de cotización/PUDO de Blue Express no es lo que ve el
cliente. Lo alineo yo en el Docker local (no toca prod) antes de la QA de
comportamiento.

### Estado 2026-09-12 00:45 CL

- Fase 1 (lectura): 18 áreas mapeadas por lectores Opus 5 + verificadores. wf2
  (mu-plugins y transversales) cerrada: 4,2 M tokens, 58 min.
- Fase 2a (verificación adversarial de 149 sospechas de mu-plugins/transversales)
  lanzada: 32 lotes de refutación por código y política + 14 lotes de
  reproducción en el ESPEJO en dos cadenas secuenciales (para no pisarse).
  Los reproductores restauran todo lo que tocan y no ejecutan rebuilds.
- El espejo tiene ahora los terceros alineados con prod (Blue 3.3.1, LiteSpeed
  7.9.1, MP 8.9.3, Clarity 0.10.30, login-with-google 1.4.4, JoinChat 6.3.2) y
  las 33 acciones `akb_search_editorial_discount_boundary` residuales del 5-6
  sep (anteriores al fix de 0.1.54) quedaron canceladas.

### Estado 2026-09-12 04:50 CL (tras el corte por límite de cuenta a la 01:00 y el reinicio de las 04:00)

- Fase 1 cerrada: 18 áreas con mapa y verificador (tema: verificador en curso).
  477 sospechas brutas → 471 vivas (52 altas, 211 medias, 208 bajas; 88 son
  decisiones del dueño, no defectos).
- Fase 2a (mu-plugins + transversales): 32/46 lotes con veredicto. Confirmados
  hasta ahora: S-53e45ef0 (business: `sale_policy` sin saneador), S-abb1b5fe
  (search: rechazo silencioso de zona horaria/decimales), S-4a9d7e0e (mu-plugin
  Brevo portada: nunca engancha, prioridad 20 empata con business),
  S-2beec1c9 (catalogo-abilities: `feed_fecha` opcional = guarda fail-open),
  S-aa3dc0be (correo-no-sale: el cepo aplasta cabeceras multilínea de Woo),
  S-0ac7abaa (search: docblock del runbook con grupo equivocado), S-b6e87720
  (rewards: cumpleaños sin reintento), S-963bf905 (rewards: caducidad apagada
  deja acción viva sin handler). Los cuatro de business/rewards/brevo van a
  bis-avenida por mensaje (04:20). Los de search y mu-plugins sin reclamar los
  arreglo yo en un solo corte al cerrar la reproducción en el espejo.
- Fase 2b (299 sospechas de las áreas de plugins): lanzada, 67 lotes + 29 en
  tres cadenas de reproducción (business / search+checkout / rewards).
- Fase 3: seis capítulos transversales redactados en `docs/arquitectura/`
  (14-19, 527-1699 líneas cada uno, 26 diagramas Mermaid validados); crítico
  novato y corrección en curso.

### 2026-09-12 05:05 CL · Cuatro hallazgos CERRADOS por QA tras re-medir en producción

bis-avenida desplegó business 1.116.5, rewards 0.3.14, tema 2.1.119 y el
mu-plugin de Brevo 1.1.1 con los cuatro. Medido por esta sesión en prod:

| id | qué | medición |
| --- | --- | --- |
| S-4a9d7e0e | mu-plugin Brevo enganchado a prio 25 | `has_filter` abrir=9 / cerrar=11 en los tres ganchos del carrito |
| S-53e45ef0 | saneador de `sale_policy` | `has_filter(...sanitize_option_akibara_taxonomy_discount_sale_policy)=true`; valor vivo `greatest_wins` |
| S-963bf905 | caducidad de puntos con handler siempre + desagendado al apagar | `akb_rewards_expiry_daily` pending=1, memo=`yes`, has_action=true |
| S-b6e87720 | rearme del correo de cumpleaños | `META_BIRTHDAY_GREETED_YEAR` en Birthday.php:244 y Emails.php:242/323/347 |

Home y suggest en 200; `error_log` sin líneas nuevas. Snapshot v2 congelado
con los ZIP nuevos (`scratchpad/snapshot-prod-v2`): 1.116.5 publica además
`VolumeVariant`, `Services/VolumeVariant.php` y
`TaxonomyDiscount/CampaignExpiryCron.php` (cron `akb_taxonomy_discount_expiry_sweep`),
que entran en la documentación.

### Estado 2026-09-12 09:10 CL (segundo corte por límite de cuenta a las ~06:00; reinicio 09:00)

- Verificación: 70 lotes con veredicto de 155. Balance: 147 refutadas, 56
  confirmadas por lectura, 17 reproducidas en el espejo, 4 arregladas y
  medidas en prod, 64 decisiones del dueño, 189 pendientes de veredicto.
- Redacción: 18 capítulos en `docs/arquitectura/` (14-19 corregidos tras la
  crítica; 02-13 en redacción/crítica).
- Corte de mu-plugins (provider-access 1.1.0, catalogo-abilities 1.1.0,
  correo-no-sale 1.1.0): desarrollado con pruebas nuevas
  (`tools/test-dispo-feed-fecha-obligatoria.php`,
  `tools/test-correo-no-sale-cabeceras.php`, casos string_true/TRUE/yes en
  `tools/test-provider-access-mail-gate.php`), suite business 3.103 OK,
  PHPCS 0/0. Revisión adversarial en curso; despliegue después, avisando a
  bis-avenida. OJO: `feed_fecha` pasa a obligatoria en la ability
  `akibara/disponibilidad-set`: el prompt de la tarea programada en la nube
  debe mandarla en la misma ventana del despliegue.
- Confirmados en el buscador (mi alcance, para el release 0.1.61 tras cerrar
  la reproducción): S-79a46751 (sinónimo de 5 palabras apaga el buscador),
  S-fcb9254f (stopword > 64 chars), S-5ef0fea5 (etiqueta de faceta > 100
  chars), S-09b30833 (panel se contradice al cerrar la foto), S-a9b5a722
  (rescate de editorial no llega a las superficies de serie), S-95894772
  (guardar un término sigue reconstruyendo el índice entero), S-abb1b5fe
  (rechazo silencioso de zona horaria/decimales), S-0ac7abaa (docblock).

### 2026-09-12 09:50 CL · Corte de mu-plugins LISTO, despliegue en manos del dueño

Dev + 3 revisores adversariales + corrección: LISTO PARA DESPLEGAR. El
clasificador denegó `ops/akibara-prod-muplugins-deploy.sh` a esta sesión; no se
rodea. Runbook con los comandos exactos: `ops/despliegue-mu-plugins-2026-09-12.md`.
Copia de las versiones 1.0.0 de prod en `.local-checkpoints/mu-plugins-2026-09-12/`.
Contexto de bis-avenida (09:45): el dueño eliminó hoy la tarea de la nube que
llamaba a `akibara/disponibilidad-set` y revocó las dos contraseñas de
aplicación; la ability queda sin llamador. Los reproductores del espejo
mutaron `akibara-correo-smtp.php` (TransportX) a las 09:25; el dev lo restauró
byte a byte desde el ZIP de prod y retiró `mu-plugins/zzz-qa-esp39-sonda.php`.

### 2026-09-12 10:20 CL · Tres mu-plugins DESPLEGADOS y medidos en producción

El dueño pidió que la sesión ejecute todo y autorizó la regla de permisos para
los dos carriles (`.claude/settings.local.json`). Desplegado con
`ops/akibara-prod-muplugins-deploy.sh` (paridad md5 ✓, mail-health sano).
Medido en prod: cabeceras 1.1.0 en los tres, `php -l` limpio, `wp akibara
access-check` = Success, entorno `production`, `wp_get_ability` →
`required: editorial,feed_fecha,items,expected_count`, correo real
`wp_mail=true` a la bandeja del dueño. Cerrados: S-e1def836, S-2beec1c9,
S-aa3dc0be, S-9f7e689a.

Regla del 5 % medida en producción (410 fichas «a pedido» con oferta):
`sale = ROUND(regular × 0,95 / 100) × 100` cuadra en 391; `CEIL` en 359;
`FLOOR` en 64; «−$500» en 261. Es decir: **a la centena más cercana, mitad
hacia arriba**. Con eso se alinea `akibara-catalogo-abilities.php` 1.2.0.

### 2026-09-13 01:55 CL · Relevo de sesión (akibara-3f → akibara-d2f7)

La sesión que abrió este asunto se quedó sin cupo semanal el 12-sep 20:43 en su
cuenta (`resets Sep 18`), con un «continua» del dueño sin ejecutar. El dueño
pidió explícitamente retomar donde quedó, no empezar de cero, y autorizó el
relevo. Esta sesión toma `docs/arquitectura` y libera lo que ya está cerrado:
los tres mu-plugins y su test están desplegados y medidos en producción desde
las 10:20 del 12-sep, así que no tiene sentido seguir reteniéndolos.

Nada del trabajo se perdió: los 18 capítulos, los diagramas Mermaid/SVG y los
veredictos viven en el árbol, no en la conversación.

**Lo que queda, según el propio estado de las 09:10 del 12-sep:**

- 189 sospechas pendientes de veredicto (de 471 vivas).
- Capítulos 02-13 en redacción/crítica; 14-19 ya corregidos tras la crítica.
- Release de buscador 0.1.61 con los ocho confirmados de search, sin empaquetar.
- Graphify sin actualizar al cierre.


### 2026-09-12 23:30 CL · Retoma la sesión akibara-eb (Opus 5), por orden del dueño

El dueño cambió de modelo y pidió «intentar nuevamente» y «no dejes nada a
medias». La sesión relevo (akibara-d2f7) dejó el README de `docs/arquitectura`
y 131 SVG renderizados; no tocó código. Ni ella ni bis-avenida están activas
(`ListAgents` vacío), así que esta sesión toma también business, rewards, tema
y checkout: el dueño ordenó que la sesión ejecute todo. Si bis-avenida vuelve,
que lea esta fila ANTES de tocar esos plugins.

Estado medido ahora: prod business 1.116.5, search 0.1.60, checkout 2.2.17,
rewards 0.3.14, tema 2.1.119; mu-plugins provider-access/catalogo/correo-no-sale
1.1.0 y brevo-portada 1.1.1; home y suggest en 200. Árbol vivo = prod salvo
`akibara-catalogo-abilities.php` 1.2.0 (sin desplegar, en revisión).

Balance de la verificación (hallazgos.json): 245 refutadas · 93 confirmadas
por lectura · 57 reproducidas en el espejo · 39 probables (sólo lente de
política) · 34 decisiones del dueño · 8 arregladas en prod. Quedan 31 lotes de
reproducción sin correr: se sustituyen por la prueba roja obligatoria de cada
arreglo (no se arregla nada que no se ponga rojo primero).

Arreglos pendientes por plugin: business 74 · search 34 · mu-plugins 29 ·
rewards 28 · tema 16 · checkout 8. La herramienta Workflow está desactivada en
esta sesión: se orquesta con agentes sueltos en copias aisladas.

### 2026-09-13 01:15 CL · Cuatro decisiones del dueño, y la primera ya aplicada en producción

Preguntadas y respondidas por el dueño esta madrugada:

| decisión | qué dijo | estado |
| --- | --- | --- |
| Webhooks «BrandAgent» de Clarity (6, activos, 359 envíos el 12-sep) | **Borrarlos** | **HECHO en prod 01:12 CL**: `wp wc webhook delete 1..6 --force`; borradas también `brandagent_secret_key_akibara-cl` y `brandagent_hmac_platform`. Verificado: 0 filas en `wp_wc_webhooks`, home 200, ficha `haikyuu-45-ivrea-argentina` 200 con botón de compra, suggest 200, cero fatales nuevos. Punto de restauración en `.local-checkpoints/webhooks-brandagent-2026-09-13/`. |
| Abilities de WooCommerce por MCP (S-d32fa602) | **Vetar sólo borrar** | pendiente: añadir `woocommerce/product-delete` a `akibara-abilities-denylist.php`; crear/editar producto y cambiar estado de pedido siguen disponibles |
| Interruptores de Puntos (S-2a996d4e) | **Independientes** | pendiente en akibara-rewards: apagar «Dar puntos por compra» no debe apagar bono, referidos ni su correo |
| Foto de la reseña (S-255aeca1) | **Ficha y moderación** | pendiente en akibara-rewards: pintar la foto junto a la reseña en la ficha y en la pantalla de aprobación |

Cupo: el límite de sesión corta los subagentes hasta las 04:30 CL. Mientras,
el líder cierra en directo la documentación (01 visión general, glosario,
20-hallazgos, 21-decisiones) y deja los arreglos listos para la siguiente
ventana.

### 2026-09-13 02:20 CL · Dos arreglos más, desplegados y medidos en producción

| id | qué era | release | medido en prod |
| --- | --- | --- | --- |
| S-d32fa602 | La denylist de abilities sólo miraba a Rank Math; WooCommerce 11.1 publicaba por MCP borrar/crear/editar productos y cambiar estados de pedido | mu-plugin `akibara-abilities-denylist` **1.1.0** | `woocommerce/product-delete → mcp.public=false`; `product-update` sigue `true` y `akibara/disponibilidad-set` intacta (decisión del dueño: vetar sólo borrar). Prueba nueva `AbilitiesDenylistTest` (13 casos) verde contra el árbol y demostrada ROJA contra lo publicado |
| S-24a0b02a | Los botones − / + de cantidad desaparecían en los ~494 tomos «a pedido»: `-1` («sin tope» de WooCommerce) se trataba como tope 1 | tema **2.1.120** | tres mediciones: `haikyuu-45` y `saint-seiya-22` (a pedido) → botones presentes; `demon-slayer-23` (stock 1) → sin botones, correcto; `dan-da-dan-20` (stock > 1) → botones presentes. Home y suggest 200 |

Gates: suite business+checkout 3.130 OK · tema 99 PHP + 91 JS OK · PHPCS sin errores ·
`php -l` limpio · paridad byte a byte en los dos carriles · salud del correo verde.

**Trampa encontrada en el carril del tema:** `tools/akibara-releases/package-theme-production.sh`
sigue clavado en el superset de la **2.1.49** y empaqueta esa versión, no la del
árbol. El empaquetador bueno para el tema es `package-theme.sh`. Anotado como
deuda del riel.

**Aviso:** a las 01:5x un `wp eval` mío de medición reventó en la consola de
producción (no en el sitio; home y buscador siguieron en 200). Si al dueño le
llega un correo de «error crítico» con esa hora, es de esa medición.

## 2026-09-13 01:45 · La foto de la reseña, lista para publicar (rewards 0.3.15)

La decisión del dueño de hoy —«la foto tiene que verse en la ficha y en
moderación»— ya está implementada y en verde local:

- `src/Reviews/PhotoSurfaces.php` (nuevo) cuelga de `comment_text` a prioridad
  25 —después de la insignia de reseña transcrita (20)—, que es el único punto
  por el que pasan LAS DOS superficies de la decisión: la lista de reseñas de la
  ficha y la tabla de moderación del escritorio. En la ficha pinta `medium`
  (240px, perezosa, sin enlace); en el escritorio, `thumbnail` (96px) enlazada a
  la `large`, porque el dueño aprueba mirando el detalle, no un sello.
- Misma vara que el carrusel para decidir si hay foto: el adjunto tiene que
  EXISTIR y ser imagen; una meta huérfana no cuela un hueco vacío.
- `tests/Reviews/PhotoSurfacesTest.php`: 7 pruebas, 14 aserciones, verdes.
  Contra el ZIP publicado 0.3.14 el rojo es documental y absoluto: ahí `grep
  comment_text` sólo encuentra la insignia de AdminReviews, y la foto la pinta
  ÚNICAMENTE `Carousel.php` (home, y sólo a partir de siete reseñas).

Dos trampas que costaron la tarde y valen para cualquier prueba de esta suite:

1. **Una función flecha copia lo que usa al crearse.** Los dobles de
   `get_post_type`/`is_admin` capturaban `$tipos` y `$en_admin` con `fn() =>`,
   así que miraban un catálogo vacío por mucho que el caso lo llenara después.
   Se arregló capturando el OBJETO de la prueba (handle vivo), no sus arrays.
2. **Un doble que se come un atributo esconde el defecto.** El doble de
   `wp_get_attachment_image` sólo pintaba src/alt/class/style, así que una
   imagen sin `loading="lazy"` habría pasado. Ahora pinta todos los atributos,
   como el core.

Estado del paquete: `dist/akibara-rewards-0.3.15.zip`
(sha256 c861256271634694b8bda0642bf4840ab21fe90a15857f68dacaa3b14567fa93),
38 ficheros, sin pruebas dentro. Suite completa **373 OK / 4.718 aserciones**,
PHPCS limpio. Lleva además las dos piezas de la decisión de interruptores
independientes (`Accrual.php` dispara `akb_rewards_after_order_award` también
con 0 puntos; `Emails.php` no manda el correo de 0 puntos).

**No se despliega hasta que vuelva la revisión adversarial** (regla propia: mis
releases ya llevaron una regresión que cazó otro modelo).

### Medido en producción antes de tocar nada

`engine_on=true` — Customer Reviews ya NO está activo, así que las superficies
de reseñas de akibara-rewards están despiertas y PhotoSurfaces sí colgará. Hay
**cero reseñas** en el sitio, de modo que la medición en prod exige sembrar una
reseña de prueba con foto y retirarla después (cliente de pruebas, regla del
dueño: permitido si se restaura contando).

## 2026-09-13 · Barrido de disponibilidad: 1.2.0 lista, prod sirve 1.1.0

`public_html/wp-content/mu-plugins/akibara-catalogo-abilities.php` está en 1.2.0
en el árbol y en **1.1.0 en producción** (hash comparado, no mtime). La 1.2.0 es
exactamente lo que pidió el dueño: modo `solo_disponibilidad` POR DEFECTO (no
escribe ningún precio, ni siquiera para retirar una rebaja) y, cuando se pide
`disponibilidad_y_precio`, la reserva deja de ser la rebaja vieja de $500 fijos
y pasa a ser la regla del 5 % medida en prod: lista × 0,95 a la centena más
cercana, mitad arriba.

Candados, medidos sin tuberías que se traguen el código de salida:

| candado | árbol | producción 1.1.0 |
| --- | --- | --- |
| `tools/test-dispo-modo-y-precio.php` | rc=0 (37 comprobaciones) | **rc=255** (muerde: la función de la reserva ni existe) |
| `tools/test-dispo-feed-fecha-obligatoria.php` | rc=0 | rc=0 (eso ya lo cumplía) |

El rojo se midió contra una copia de producción en el scratchpad, **sin tocar el
árbol vivo**.

Pendiente de la revisión adversarial antes de subirlo. El prompt de la tarea de
la nube (`ops/tarea-nube-barrido-disponibilidad-prompt.md`) está escrito y sólo
espera un paso que es del dueño: crear la contraseña de aplicación.

## 2026-09-13 03:30–05:30 CL · Publicado y medido: foto de reseña (rewards 0.3.15 → 0.3.16) y barrido 1.3.0

### akibara-rewards 0.3.16 — la foto de la reseña (decisión del dueño)

- **0.3.15** salió sólo con `PhotoSurfaces`. Las dos piezas de «interruptores
  independientes» (`Accrual.php`, `Emails.php`) se sacaron del release: la
  revisión adversarial probó —y lo verifiqué contra el código— que rompían tres
  cosas: correo «sumaste **0** Puntos» (el bono se paga a prioridad 10 y el
  corte miraba a 100), pedido de cero sellado para siempre por el bono dentro de
  `META_EARNED`, y premio al padrino sin reversa. La prueba quedó como borrador
  en `ops/borradores/InterruptoresIndependientesTest.php.borrador`. **Sigue
  pendiente**, con una pregunta de negocio al dueño (¿el bono de bienvenida se
  paga en una compra que no genera puntos, por ejemplo pagada entera con puntos?).
- **Playwright** (`tools/akibara-releases/rewards-qa/qa-foto-resena-superficies.mjs`
  + siembra `sembrar-fotos-resena.php`, que se retira por ID, nunca por texto)
  encontró en el espejo tres defectos que PHPUnit no veía; arreglados en 0.3.16:
  1. En móvil (375px) la foto se salía **30px** de la tarjeta: `max-width:240px`
     no respeta el contenedor → `max-width:min(240px,100%)`.
  2. La remota se forzaba a https aunque la página fuera por http → no cargaba.
     Ahora sólo se sube a https si `is_ssl()`.
  3. El aviso «Reseña creada» enlazaba a Comentarios, que WooCommerce 11 deja
     **sin** reseñas (`ReviewsCommentsOverrides`): el dueño aterrizaba en una lista
     vacía. Ahora apunta a Productos › Reseñas.
  Además: prioridad 26 (la 25 empataba con `force_balance_tags`), remota con
  esquema raro descartada.
- Suite rewards **373 OK / 4.717**, PHPCS limpio, rojo del aviso probado contra 0.3.15.
- **Medido en producción** (ventana 03-06 CL): Playwright **PASS 39/39** en ficha
  escritorio y móvil (local, remota por https, huérfana, sin foto, pendiente
  invisible, sin desborde, sin JSON-LD contaminado, sin 4xx, sin excepciones JS)
  + blog; moderación medida con `WP_ADMIN`: miniatura 150×150 enlazada a la
  completa. Siembra retirada: 0 restos, `review_count` 0, ficha y blog limpios en
  dos pasadas cada uno tras `litespeed-purge all` (la purga por post_id/URL NO
  alcanza la copia de invitado: medido, guardado en memoria).

### akibara-catalogo-abilities 1.3.0 — barrido de disponibilidad

Dos revisiones adversariales (10 + 6 hallazgos) y todos resueltos:

| Hallazgo | Arreglo |
| --- | --- |
| precios leídos en `view` (la campaña editorial virtual calzaba con la fórmula en media tienda) | plan y relectura en `edit` |
| la reserva expulsaba la ficha de la campaña y subía el precio | campaña encendida → ninguna reserva nueva (cierre ante la duda; la campaña elige por estado y no se ve antes de reabrir) |
| la 1.2.0 perdió el candado `_akb_sweep_incentivo` | retirar exige cifra Y marca |
| cambiar el porcentaje congelaba todas las reservas | la marca guarda el porcentaje («5%»); la «1» heredada vale lista−500 o 5 % |
| ~336 reservas sin marca tapaban los precios manuales | lista aparte `reservas_sin_marca`; una ficha «a pedido» con la regla vigente no se reporta |
| preventa detectada por `_akb_eta_release_date` (una tba se apagaba, una vencida se seguía vendiendo) | `Availability::is_preventa()`; sin business, cierre ante la duda |
| `max_cambios` sin techo por dentro · banda baja · pct 0 ruidoso · `fallos` ausente en seco · duplicados silenciosos · descripción MCP incompleta | resueltos |
| purga dentro de la petición (Cloudflare síncrono, el cliente cortaba antes del informe) | `litespeed_purge_all` en `shutdown`, sólo si algo quedó escrito |
| `ops/barrido-disponibilidad.php` contradecía a la ability | retirado (copia en `.local-checkpoints/retirados-2026-09-13/`) |

- Candado `tools/test-dispo-modo-y-precio.php`: casos a–o, incluida la ruta de
  ESCRITURA y dos procesos hijos (pct 0, sin business). **16/16 mutantes muertos.**
  `test-dispo-feed-fecha-obligatoria.php` verde. PHPCS limpio.
- Desplegado con `ops/akibara-prod-muplugins-deploy.sh` (md5 verificado). Punto de
  restauración de la 1.1.0: `.local-checkpoints/catalogo-abilities-2026-09-13/`.
- **Medido en producción**: portada, a pedido, ficha y carrito en 200; abilities
  REST 401 sin credencial; sin fatales nuevos en `error_log`. Ensayo en seco de la
  ability real con 10 ISBN reales de Ivrea Argentina: `solo_disponibilidad` → 4
  cambios (2 reabrir, 2 apagar), 0 precios, 0 fallos; `disponibilidad_y_precio` →
  +2 reservas, −2 retiradas, 0 manuales, 0 fallos.
- Catálogo real: 421 fichas con oferta → «a pedido»: 336 con la regla SIN marca,
  73 marcadas («1»), 1 manual; agotadas: 2 con lista−500 sin marca, 8 manuales;
  en bodega: 1 manual. Preventas reales: **0**; ninguna ficha con fecha de
  lanzamiento (`_akb_eta_type`: auto 563, disabled 175).
- Prompt de la nube (`ops/tarea-nube-barrido-disponibilidad-prompt.md`) al día
  con `preventas_respetadas`, `reservas_sin_marca` e `isbn_duplicados`. Espera
  un paso del dueño: crear la contraseña de aplicación.

**Para QA (cierra QA, no yo):** re-medir la ficha con una reseña con foto en
móvil, y un ensayo en seco de la ability con un listado real.

## 2026-09-13 ~06:00 CL · RETIRADO por el dueño: barrido de disponibilidad

Decisión textual del dueño: «elimina el barrido; fue por una única vez; no
debería haber código ni nada sobre él en local ni prod».

- **Producción:** borrado `wp-content/mu-plugins/akibara-catalogo-abilities.php`
  (1.3.0, desplegada una hora antes) y borradas con `delete_post_meta_by_key` las
  metas `_akb_sweep_incentivo` (74) y `_akb_sweep_prev_stock_status` (66); quedan
  0. `function_exists('akibara_dispo_precio_reserva')` → false. Portada, a pedido,
  ficha y carrito en 200. El buscador no escucha cambios de postmeta: sin rebuild.
- **Local:** borrados el mu-plugin, `tools/test-dispo-modo-y-precio.php`,
  `tools/test-dispo-feed-fecha-obligatoria.php`,
  `ops/tarea-nube-barrido-disponibilidad-prompt.md` y `ops/barrido-disponibilidad.php`.
  `AbilitiesDenylistTest` ya no lista `akibara/disponibilidad-set` (12 OK).
- **Respaldo** (fuera del docroot): `.local-checkpoints/barrido-retirado-2026-09-13/`
  — código servido, ficheros locales y las 140 filas de metas en JSON.
- Se quedan a propósito: `ops/dump-disponibilidad.php` (lo usa
  `ops/precio-competitivo.py`) y `ops/reserva-5pct.php` (es la regla de precio del
  5 %, no el barrido). Los precios del 5 % de las 409 fichas «a pedido» no se tocan.
- Documentación de arquitectura: en limpieza (agente, sólo `docs/arquitectura/`).

## 2026-09-13 ~06:30 CL · Entregable final comprometido con el dueño

Pedido textual: «al finalizar todo se le deberá crear un documento al dueño
especificando absolutamente qué es lo que hacen los plugins y cómo interactúan, y
casos de borde con cron, y si tienen panel UI para modificar».

Se entrega al CERRAR este asunto (no antes), sobre el estado final desplegado:
por cada pieza first-party (business y sus módulos, search, checkout blocks,
rewards, tema, mu-plugins vigentes) → qué hace, con quién habla, casos de borde,
cron/Action Scheduler y qué se cambia desde un panel (pantalla exacta) vs. qué
exige código. Basado en `docs/arquitectura/`, verificado contra código y prod.

En curso antes de ese cierre: asesoría y rediseño de Akibara Puntos (bono de
bienvenida, padrino, caducidad de regalos, futuro cupón de primera compra) — el
dueño decide tras el análisis; cualquier cambio arrastra la señalización.

## 2026-09-13 ~06:45 CL · Akibara Puntos: decisiones del dueño (implementación en curso, rewards)

Tras la asesoría (datos de prod, 6 plugins de WordPress.org, Dame Mi Manga):
1. Bono de bienvenida y premio al padrino en la 1ª compra con ≥ $10.000 pagados
   con dinero (sin puntos), independiente de «puntos por compra»; padrino al pasar
   a Completado, con reversa por cancelación/reembolso.
2. Uno por RUT, teléfono y email contra compras anteriores.
3. Canje con carrito mínimo de $40.000.
4. Caducidad 12 meses para todo (sin cambio).
Incluye rehacer «interruptores independientes» sin las tres roturas halladas, y
actualizar toda la señalización (página del programa, ficha, carrito, checkout,
Mi cuenta, correos). Reclamo: `public_html/wp-content/plugins/akibara-rewards/`.

**Corrección del dueño (misma mañana):** la primera compra va SIN monto mínimo
(«no es muy honesto con el programa de puntos»; el campo queda en el panel en 0)
y el canje se activa sólo en carritos desde **$50.000** (no 40.000), como Dame Mi
Manga. Uno por persona (RUT, teléfono, correo) se mantiene. Todo configurable
desde el panel.

## 2026-09-13 · Akibara Puntos 0.4.0 listo (pendiente revisión + despliegue)

Implementadas las decisiones del dueño, con las tres roturas de la 0.3.15
cerradas y probadas:

- **`src/Points/FirstPurchase.php` (nuevo)**: «uno por persona». Compara cuenta,
  RUT, teléfono y correo contra compras PAGADAS anteriores (desde el inicio del
  programa; un pedido anulado no cuenta y uno anterior al programa tampoco, que
  es lo que la página promete). Cada señal con su interruptor en el panel.
  Formatos medidos en prod: teléfono `+56XXXXXXXXX` (101 de 117) y RUT siempre
  `dd.ddd.ddd-d`, por eso se consulta por variantes.
- **Bono**: se paga aunque «Dar puntos por compra» esté apagado (el aviso de
  acreditación ahora también se dispara con 0 puntos) y exige dinero real. Si no
  se paga, el pedido lleva una nota que dice POR QUÉ (una sola vez).
- **Sellado (rotura 2 cerrada)**: la compuerta de «ya acreditado» mira el earned
  MENOS el bono, y al acreditar se SUMA a lo que hubiera. Un pedido de cero que
  cobró el bono vuelve a acreditar si se encienden los puntos. La tasa se sella
  también en la rama de cero.
- **Correo (rotura 1 cerrada)**: sin compra ni bono no sale; con bono y compra en
  cero, el asunto y el cuerpo hablan del bono y nunca dicen «sumaste 0».
- **Padrino (rotura 3 cerrada)**: cuelga de `woocommerce_order_status_changed`
  (paga en el estado configurado, «Completado») y de `woocommerce_order_refunded`;
  la reversa corre aunque el pedido no diera puntos. No paga si el invitado es la
  misma persona (RUT, teléfono o correo del padrino).
- **Canje**: `Redemption::missing_for_minimum()` y carrito mínimo de $50.000.
- **Panel**: 6 campos nuevos (compra mínima, tres señales de identidad, carrito
  mínimo de canje, estado que paga al padrino) y ayudas reescritas.
- **Señalización**: página del programa (incluida la prosa publicada, con piezas
  dinámicas nuevas `minimo_canje` y `minimo_primera_compra`), ficha, registro,
  Mi cuenta, bloque de invitación y caja del checkout.
- **Código muerto**: retirado el borrador `InterruptoresIndependientesTest`, y el
  gancho viejo `akb_rewards_after_order_deduct` del padrino ya no se registra.

Pruebas: suite **397 OK / 5.431 aserciones** (21 nuevas en `PrimeraCompraTest`),
PHPCS limpio, y **Playwright verde en el espejo** (`qa-puntos-primera-compra.mjs`
+ `sembrar-cliente-puntos.php`): página del programa, ficha en escritorio y
móvil, Mi cuenta y el checkout por debajo y por encima del mínimo.
Paquete: `dist/akibara-rewards-0.4.0.zip` (39 ficheros,
sha256 3ed197c457e28e1a3c7a3b0ba9e5525e25f801d68b0278af214bc0fd48856bf7).

## 2026-09-13 10:05 UTC · akibara-rewards 0.4.0 EN PRODUCCIÓN

La revisión adversarial confirmó cerradas las tres roturas de la 0.3.15 y
encontró siete cosas más; todas arregladas antes de desplegar:

1. **(ALTA, dinero)** La reversa del premio al padrino borraba la elegibilidad
   aunque el débito saliera CLAMPADO (el padrino ya se había gastado los
   puntos): la siguiente compra del invitado pagaba otro premio. Ahora, si no se
   recupera el premio completo, la marca se conserva, el pedido lleva nota y se
   dispara `akb_rewards_reversal_failed`. Además, un premio VIVO bloquea el pago
   de otro sobre la misma relación.
2. Los cuatro correos que empujan a canjear callaban el carrito mínimo — el peor,
   el de «tus puntos vencen»: mandaba a una pantalla donde no se podía canjear.
3. El aviso de «canje listo» de Mi cuenta nombraba el techo y no el piso.
4. La ficha no decía «uno por persona».
5. Un RUT tecleado con cero a la izquierda («012.345.678-5») se guarda con el
   cero y no coincidía: segunda bienvenida para la misma persona.
6. Un pedido sin fecha de creación hacía barrer toda la historia (fallaba
   cerrado, negando el bono a quien compró antes del programa).
7. El filtro `akb_rewards_award_allowed` gobierna ahora DOS pagos; documentado.
   Y el tope de premios por padrino se contaba fuera de candado (en prod vale 10):
   se cuenta junto al crédito, bajo el candado del padrino.

Verificación: suite **401 OK / 5.453 aserciones**, PHPCS limpio, **Playwright
verde en el espejo** (página del programa, ficha en escritorio y móvil, Mi cuenta,
aviso del canje y checkout por debajo y por encima del mínimo) y **Playwright
verde en producción** (superficies públicas). Desplegado con el riel
(sha256 32b99aaa…), correo de producción sano, portada/programa/a pedido/mi
cuenta/Store API en 200, sin fatales nuevos. La API del carrito ya sirve
`min_cart=50000`. El texto publicado de `/akibara-puntos/` quedó al día en prod y
en el espejo (respaldo del anterior en `.local-checkpoints/pagina-puntos-2026-09-13/`).
QA nuevo: `tools/akibara-releases/rewards-qa/qa-puntos-primera-compra.mjs` y
`sembrar-cliente-puntos.php` (el cliente de QA del espejo ya se retiró).

**Para QA (cierra QA, no yo):** re-medir con una compra real de punta a punta —
primera compra de una persona nueva (bono), segunda con el mismo RUT (sin bono),
y un canje en un carrito bajo y sobre $50.000.

## 2026-09-13 11:35 UTC · akibara-search 0.1.61 EN PRODUCCIÓN — tres entradas del panel apagaban el buscador

Hallado al escribir el documento para el dueño (sección buscador) y ampliado por
la revisión independiente. El patrón era el mismo en las tres: el GUARDADO del
panel era más permisivo que el LECTOR de la configuración autoritativa, y un solo
valor rechazado invalida la foto semántica → `authoritative_available()` falso →
se apagan a la vez indexación, sugerencias y filtros.

| Entrada del panel | Qué se guardaba y el lector rechazaba | Arreglo |
| --- | --- | --- |
| Sinónimos | términos de 5+ palabras contando las de relleno («el ataque de los titanes»), y `<`/`>` sueltos | `Synonyms::term_is_canonical()` única, usada al guardar y al leer; se cuentan TODAS las palabras para no depender de la lista de palabras vacías |
| Palabras ignoradas | palabras de más de 64 caracteres | `Stopwords::word_is_valid()` + `sanitize_words()`, compartidas |
| Etiquetas de filtro | etiquetas de más de 100 caracteres | `FacetRegistry::sanitize_label()` recorta al máximo; el lector usa la misma constante |

Producción no estaba afectada (sin sinónimos guardados, lista de palabras vacías
por defecto). Pruebas: `SinonimoGuardadoEsLeidoTest` (rojo con 0.1.60: 6 fallos),
`PanelNoApagaElBuscadorTest`; suite **578 OK**, PHPCS limpio. Además: aviso
específico en el panel al descartar, parámetro `$limit` muerto retirado de
`significant_tokens`, comentarios caducados corregidos.

Medido en producción tras desplegar: sugerencias en 200 en tres pasadas (7 tomos
y 2 series para «naruto»), portada y tienda en 200, las tres reglas nuevas
responden como en local, configuración autoritativa disponible, sin fatales.

**Para QA:** en el panel del buscador, guardar un sinónimo de 5 palabras y
comprobar el aviso y que la búsqueda siga respondiendo.

## 2026-09-13 · Entregado el manual del dueño: «Akibara por dentro»

https://claude.ai/code/artifact/ff437f1f-fa23-4fed-89b1-9a8c53f781d6 · fuentes en
`docs/para-el-dueno/` (cinco capítulos + constructor). Escrito por cuatro agentes
Opus 5 y el líder, cada afirmación contra el código y, lo vivo, contra producción
(versiones, interruptores, 23 tareas programadas medidas en la cola, las 19
pestañas de Akibara → Panel leídas del panel real del espejo). Al escribirlo
aparecieron los tres defectos del panel del buscador (arreglados en 0.1.61) y
ocho defectos nuevos de Puntos y reseñas, que pasan al backlog para verificar.

## 2026-09-13 · Reclamo akibara-rewards → 0.4.1 (defectos hallados al documentar)

Verificados contra el código y producción: (1) Cloudflare quita el `Set-Cookie`
de `?ref=` aun en MISS → ningún referido se liga en prod (ALTA, medido con curl
origen vs. borde); (2) invitación a reseñar con disparador ≠ Completado nunca
sale (recomprobación del estado en el job) y la preventa nunca se invita; (3) el
tope del padrino cuenta premios revertidos; (4) padrino sin correo ni nota;
(5) siete alertas `do_action` sin oyente; (6) canje simultáneo con saldo corto
regala el descuento; (7) desaprobar una reseña premiada no retira los puntos;
(8) el correo de reseña premiada no se reintenta; (9) el aviso de vencimiento
puede duplicarse; (10) el campo «Correos que llevan la invitación» pide IDs.

## 2026-09-13 · rewards 0.4.1 en espejo: suites y Playwright en verde, revisión independiente en curso

- PHPUnit rewards 446/5.569 · buscador 578 · PHPCS limpio en `src/`.
- `tools/akibara-releases/rewards-qa/qa-referidos-canje-alertas.mjs` (+ `sembrar-referidos-canje.php`)
  PASS en el espejo: cookie `akb_ref` escrita por el navegador (390 px), registro liga al padrino,
  compra completada paga 500 + correo único al padrino sin datos del invitado, «ya llevas 1»;
  carrera real de dos equipos → `200 · 409 akb_rewards_balance_changed` y descontado = debitado;
  alerta al dueño una sola vez; invitación con disparador «Procesando» sale ya completado; panel con
  casillas y destinatarios validados. Limpieza contada (cuentas, pedidos, filas, options, stock).
- NO se tocó: «desaprobar una reseña premiada no retira puntos» es regla escrita a propósito
  (ReviewPointsTest) → pregunta al dueño.
- Hallazgo aparte (tercero): el aviso de WooCommerce «The order total changed…» sale en inglés en
  el espejo con es_ES; falta medirlo en producción.

## 2026-09-13 · Revisiones independientes y cierre

- **rewards 0.4.1, 1ª revisión (Opus 5, sólo lectura):** 1 ALTA (el pedido detenido en la caja
  quedaba «pendiente» con el descuento entero y pagable desde Mi cuenta) + 9 BAJA. Cerrados: vuelta a
  `checkout-draft` del mismo objeto que guarda WooCommerce; guarda en `woocommerce_before_pay_action`;
  una corrida de reintento gasta un cupo y lo devuelve al salir bien; nota de premio no pagado por
  invitado y motivo + nota de pago; correo al padrino exige el premio entero en el libro; alertas al
  registro de WC siempre; casillas del panel con vacío oculto y sin borrar IDs guardados; la captura
  sólo se salta en `checkout-draft → pending`. Pendientes documentados: `maybe_resend` reinicia su
  cadena al volver a marcar el pedido; aviso de vencimiento con lotes concurrentes de AS.
- **Playwright cazó un defecto de la primera corrección:** anular en `before_pay_action` hacía que
  `pay_action` lo tratara como «sin pago necesario» → `payment_complete()` → Procesando sin cobrar.
  Corregido: no se toca el estado, sólo se detiene el pago (queda pendiente). Re-medido: PASS.
- **checkout 2.2.18 (S-7fd65b78, S-cd119862):** `PudoIntegration::best_effort` para las 8 limpiezas
  previas a un aviso; revisión independiente sin ALTA; BAJA aplicadas (contextos de registro propios,
  comentario exacto, contador de lápidas propio). business+checkout 3.136/9.102 · JS 114/114.
- 2ª revisión de rewards en curso antes de empaquetar.

## 2026-09-13 · checkout 2.2.18 EN PRODUCCIÓN + hallazgo de Blue Express (tercero)

- Desplegada con el riel (sha256 `30ec781c…`, 28 ficheros, mtime real). Medido: portada, ficha,
  carrito y Store API 200; carrito de sonda con tarifas reales `bluex-ex` + `local_pickup`, sin
  errores; registro `akibara-pudo` 0 entradas; ningún fatal posterior al despliegue.
- **Hallazgo aparte (NO del despliegue):** en prod falta la option
  `woocommerce_correios-integration_settings` de Blue 3.3.1 → `pudoEnable` por defecto `no` (el
  retiro en Punto Blue Express no se ofrece: último pedido PUDO #27759 del 28-ago), `districtCode`
  `SCL` en vez de `SMG`, sin `tracking_bxkey`, registros apagados. El espejo la conserva. Causa de la
  desaparición no identificada (el desinstalador de Blue no la borra; ningún guion propio la nombra).
  Requiere al dueño: WooCommerce → Ajustes → Integración → Blue Express (lleva una credencial).

## 2026-09-13 · Blue Express: restauración de ajustes en PRODUCCIÓN (autorizada por el dueño)

Dueño: «me importa que funcione… puedes probar en prod igual». Se escribe la option ausente
`woocommerce_correios-integration_settings` sólo con valores NO secretos tomados del espejo:
`noBlueStatus=wc-shipping-progress`, `devOptions=no`, `districtCode=SMG`, `districtsEnable=yes`,
`pudoEnable=yes`, `active_logs=yes`. No se escribe `tracking_bxkey` (en modo producción Blue usa su
clave integrada; la guardada sólo rige con BLUEX_DEBUG) ni `alternativeBasePath`/`googleKey`.
**Rollback:** `wp option delete woocommerce_correios-integration_settings` (vuelve al estado previo:
ausente). Medición a continuación.
- **Medido tras restaurar (17:06 UTC):** Store API con carrito de sonda → `bluex-pudo` $3.150 +
  `bluex-ex` $3.700 + `local_pickup` en San Miguel, Santiago y Providencia; `wp_bluex_logs` con 200
  en bxgeo/v2, new-nearby-pudos y pricing/v1. Sonda de navegador: `rewards-qa/qa-pudo-produccion.mjs`
  (sin pedidos, vacía el carrito). Pendiente propuesto: que el vigía de Blue avise si la option
  desaparece o `pudoEnable` deja de ser `yes`.

## 2026-09-13 · business 1.116.6 EN PRODUCCIÓN (S-686f69f2)

- Arreglo: el reintento del aviso «tu pedido ya llegó» por fallo del WORKER lleva tope propio
  (`MetaKeys::ORDER_READY_EMAIL_WORKER_FAILURES`, 3 fallos → una nota + `Log::warn`); no cuenta ni anota
  si el email ya agotó su presupuesto; try/catch en el manejador (no corta el lote de la cola);
  escucha también `action_scheduler_unexpected_shutdown`; el reenvío por cambio de estado respeta el
  tope; el reenvío manual y `OrderTagging` limpian la meta.
- Revisión independiente (Opus 5): sin ALTA; MEDIA (doble nota) y BAJA aplicadas.
- Suite business+checkout 3.142/9.117 · PHPCS limpio · paquete 314 ficheros, sha256 `6cb0d88c…`,
  sólo 5 ficheros distintos de 1.116.5.
- Medido en prod antes/después: portada, tienda, ficha, suggest y Store API en 200; sin fatales.

## 2026-09-13 · rewards 0.4.1 — revisión final: pago rechazado y deuda de revival (local, sin desplegar)

- Revisión final (Opus 5) halló: ALTA — tras un pago rechazado, el hash del carrito (incluye el total)
  hacía crear otro pedido y los puntos quedaban en el rechazado; MEDIA — la deuda de un pedido fallido
  sólo se COMPROBABA en la caja (doble gasto en otra pestaña); BAJAS — la cola re-cobraba recortado un
  pedido pendiente, la intención sobrevivía a la compra pagada, texto de alerta con estado fijo.
- Arreglo en `Redemption.php`: `available_points()` (saldo + canje del pedido pendiente de la sesión),
  `remember_superseded`/`release_superseded`, `settle_debt_at_checkout()` (re-cobro estricto en caja y
  «pagar pedido», con reintento si el fallido vuelve a rechazar), `forget_spent_intent()`, cola sin
  cobros sobre `pending`. Caja, Mi cuenta y JS: «Quitar canje» también bajo el mínimo y sin saldo.
- Suite rewards 476/5.643 · JS OK · PHPCS limpio.
- QA espejo: `rewards-qa/qa-canje-pago-rechazado.mjs` PASS 41 (pasarela de rechazo temporal en
  mu-plugins, retirada al terminar) y regresión `qa-referidos-canje-alertas.mjs` PASS 52.
- Pendiente: segunda revisión independiente (en curso) → empaquetar → desplegar → medir en prod.

## 2026-09-13 · business 1.116.7 EN PRODUCCIÓN (S-761e5164)

- Arreglo: la continuación del barrido de portadas JPG usa hook propio
  `akibara_business_social_image_derivatives_continue` (con el hook del diario nunca encadenaba).
- De la revisión independiente (sin ALTA): el tope cuenta intentos, no éxitos; `sort(SORT_STRING)` para
  que el orden cuadre con el cursor; JPG escrita a temporal + `rename`; desactivación y desinstalación
  retiran diario, continuación y, con `wp_unschedule_hook()`, los eventos con argumento (por portada y
  valla de colecciones); comentarios al día; prueba de «una sola vez» ejercitada de verdad.
- Suite business+checkout 3.146/9.128 (memory_limit 512M en `tools/akibara-business/phpunit.xml.dist`:
  la suite ya picaba 128,5 MB) · PHPCS limpio · paquete 314 ficheros, sha256 `9b362580…`, 3 ficheros
  distintos de 1.116.6.
- Espejo: 150 JPG apartadas → diario hace 100 y agenda la continuación a 58 s → continuación termina,
  borra el cursor, 0 temporales. Prod después: 200 en portada, tienda, suggest y Store API; diario
  intacto; sin cursor; 1.699 JPG / 1.698 originales; sin fatales.

## 2026-09-13 · search 0.1.62 (S-95894772) — local, en revisión

- `enqueue_term()` ya no pide reconstrucción completa en cada guardado de término: sólo si el esquema no
  está listo o el reparto de deuda por producto quedó incompleto. Espejo: guardar la editorial 306 sin
  cambios no movió `akb_search_pending_rebuild` y dejó 23 filas de deuda. Suite search 580/2.629.
  Prod en 0.1.61 sin reconstrucción pendiente (medido, sólo lectura).

## 2026-09-13 · business 1.116.8 y search 0.1.62 (local, esperando revisión)

- **business 1.116.8** (paquete 314 ficheros, sha `4910b40b…`, 7 ficheros distintos de 1.116.7):
  portada de serie sin tope de 40 (S-3832cae1); los correos «ya llegó» y «sigue esta serie» mandan la
  portada en JPG con `SocialImageDerivatives::email_url()` (S-f0782d7e); la ficha no promete el aviso
  previo con el interruptor apagado (S-7022f6e1); el panel cuenta los cambios de term-meta y el aviso
  dice lo que pasó de verdad (S-33ca8e15, S-45c17f64). Suite 3.153/9.146 · PHPCS limpio.
  QA espejo: correo real con `-og.jpg` generado en el acto (Mailpit) y
  `business-qa/qa-panel-guardado-y-purga.mjs` PASS (purga, aviso y la promesa de la ficha, todo restaurado).
- **search 0.1.62**: el troceo de la deuda por término ya no pide reconstrucción completa (S-95894772) y
  una option de precios fuera de dominio se puede reparar desde el panel o WP-CLI (S-4d0e7c11, hallado
  hoy en el espejo: tenía `apply_to = stock` y eso lo dejó 29 h en 503 sin salida). Suite 582/2.638.
- **Espejo reparado**: `rescate-buscador.sh espejo`, valor válido por SQL, reconstrucción manual;
  `suggest` volvió a 200. Producción estaba y sigue sana (`apply_to = instock_only`, sin pendiente).
- Pendiente: revisión independiente de rewards 0.4.1 (3ª), business 1.116.8 y search 0.1.62 — los tres
  agentes se cortaron por límite de sesión (se restablece 19:40 CL). Sin revisión no se despliega.

## 2026-09-13 (tarde) · business 1.116.8: once altos cerrados (local, esperando revisión)

Paquete 315 ficheros, sha256 `66a11ada…`, 16 ficheros distintos de 1.116.7. Suite 3.170/9.221, PHPCS limpio.

- S-3832cae1 portada de serie sin tope de 40 · S-f0782d7e correos con portada en JPG ·
  S-7022f6e1 la ficha no promete el aviso previo apagado · S-33ca8e15 y S-45c17f64 purga y aviso del
  panel honestos · S-9d03b9e1 el aviso «ya llegó» no sale tras entregar al transportista ·
  S-d35d8a8b la línea de preventa sobrevive a los correos enviados desde wp-admin ·
  S-10351b0b el Centro de Mando usa el defecto real del módulo · S-0ad29321 el rechazo de tanda se ve
  también en la pantalla de países · S-5a871596 la activación no sella una tabla que no se creó (y
  avisa) · S-ccf4dde8 «Tipo de obra» (cómic / no cómic) por fin tiene pantalla, con columna y purga.
- QA de navegador en el espejo: `business-qa/qa-panel-guardado-y-purga.mjs` PASS (purga, aviso y la
  promesa de la ficha) y `business-qa/qa-serie-tipo-obra.mjs` PASS (campo, guardado, columna).
  Correo real del aviso «ya llegó» con `-og.jpg` verificado en Mailpit.

### Hallazgo nuevo de terceros: S-6b21f0ac

Con Rank Math activo, el botón «Actualizar» de CUALQUIER pantalla de término no envía el formulario
(medido en `pa_serie`, `product_cat`, `pa_pais` y `product_brand`; también con nuestros cuatro plugins
DESACTIVADOS, así que no es código nuestro). Enviando el formulario por código guarda bien. Espejo
1.0.277.2, producción 1.0.278: **falta comprobarlo en producción** —necesita sesión de administrador
del dueño— y si se confirma, es bloqueante para editar editoriales, países y series.

### Hallazgo nuevo del buscador: S-4d0e7c11 (corregido en search 0.1.62)

El espejo llevaba 29 h en 503 porque `akibara_taxonomy_discount_apply_to` tenía un valor fuera de
dominio (`stock`). Eso apaga la autoridad de precios, y el propio veto impedía repararlo desde el panel
y desde WP-CLI. Se reparó el espejo (SQL + rescate + rebuild) y se añadió la salida de reparación.

## 2026-09-13 (tarde) · search 0.1.62, tema 2.1.121 y mu-plugins (local, esperando revisión)

- **search 0.1.62** (58 ficheros, sha256 `2742e712…`): S-95894772 (el troceo por término ya no pide
  reconstrucción entera), S-4d0e7c11 (una option de precios fuera de dominio se puede reparar desde el
  panel o WP-CLI), S-abb1b5fe (el veto deja aviso en pantalla: Ajustes → Generales y WooCommerce →
  Productos ya no dicen «Ajustes guardados» a secas) y S-09b30833 (el panel dice «Encendido, pero no
  disponible ahora» en vez de culpar al interruptor; y el veredicto de indexación vuelve a mirar lo
  efectivo). Suite 584/2.646 · PHPCS limpio · QA espejo
  `business-qa/qa-buscador-foto-cerrada.mjs` PASS (los tres, con el espejo devuelto a su estado).
- **tema 2.1.121** (110 ficheros, sha256 `e6e19e14…`, 4 distintos): S-e9309aea, los tres correos de
  marca con su versión en texto plano y la misma voz; verificado en Mailpit con los tres enviados.
- **mu-plugins**: transporte 1.2.1 (S-26a2b389: cualquier entorno que no sea producción vale, el
  guardarraíl de captura vive en la misma carpeta) y accesos 1.2.0 (S-c15599d0: patrones del perfil real
  y `Transport::profile_is_valid()` en `wp akibara access-check`). Verificado en el espejo.
- **business 1.116.8** suma S-42e147f3: ocho `do_action` del muro de acceso estaban DENTRO de un
  comentario de una línea y no se ejecutaban.

## 2026-09-14 (madrugada) · DESPLEGADO: rewards 0.4.1, business 1.116.9, tema 2.1.122 y mu-plugins

Cada componente pasó revisión independiente (rewards dos veces, business tres), sus hallazgos se
corrigieron con pruebas y se validaron en el espejo antes de subir. Medido en producción después.

- **rewards 0.4.1** (41 ficheros, sha256 `f6a44205…`). Canje: los puntos de un pedido que redirigió a la
  pasarela ya no se prestan a otra compra (alta: dos descuentos con un solo saldo); tri-estado del cobro
  (candado ocupado detiene la caja), liberación entera del rechazado, ventana de pago con revisión
  `impago`, conciliación también en «pagar pedido» y en la caja clásica. Reseñas: aviso de incentivo
  tras interruptor (APAGADO por decisión del dueño), mismo criterio para ficha, carrusel y JSON-LD;
  autorreparación de las reglas de Mi cuenta. Suite 517 · QA espejo canje (8 escenarios, 70 checks)
  y divulgación PASS.
- **business 1.116.9** (315 ficheros, sha256 `8f91f725…`). Purga de «Tipo de obra» sólo si cambia lo
  publicado; nota cuando el aviso «ya llegó» se suprime por salida; tope de conversiones renovado por
  acción; aviso de campo no guardado por el canal parcial (filtro nuevo
  `akibara_panel_options_not_saved`); apóstrofes en correos de texto plano; cola de Action Scheduler
  retirada al desactivar. Suite 3.230 · QA espejo `qa-business-1-116-9.mjs` PASS y registro de Mi
  cuenta (formulario, cumpleaños, cuenta creada, acceso) verificado.
- **tema 2.1.122** (110 ficheros, sha256 `afd4b7ae…`): apóstrofes en los correos de texto plano.
- **mu-plugins**: transporte 1.2.2, accesos 1.2.2 y guardarraíl de captura 1.2.0. ALTA corregida: un
  clon con el `wp-config.php` de producción mandaba correo real a clientes; ahora exige entorno
  production **y** dominio akibara.cl. Verificado en prod sin enviar correo: tienda de verdad = sí,
  destinatario intacto, perfil válido.
- **Medición en producción tras desplegar**: home, Mi cuenta, carrito, programa, series, editoriales,
  suggest y Store API en 200; ficha con `Product,Book`; JS de la caja 0.4.1 servido con el código
  nuevo; formulario de registro con cumpleaños; sin fatales nuevos ni acciones fallidas en 30 min;
  correo sano (auth-ok).
- **Pendiente**: search 0.1.63 (revisión: 1 alta + 3 medias corregidas en copia aislada; falta
  traspaso, QA espejo y despliegue).

## 2026-09-14 (tarde) — search 0.1.63 en producción

- **search 0.1.63** (58 ficheros, sha256 `0d213d4d…`): una sola noción de validez para las
  escrituras vigiladas (`write_verdict()` con el mismo lector que sirve), veto de altas y bajas que
  dejarían la foto ilegible, cierre de marcos a medias (`marco_editorial_sin_cerrar`,
  `deuda_editorial_a_medias`), recibo de swap superado y `broken_options` en el estado. Suite 624,
  PHPCS limpio, QA espejo `qa-buscador-validez-unica.mjs` V1–V6 en verde.
- **Medido en prod tras desplegar**: suggest 20/20 en 200, gate sirviendo, indexing/suggest/facets
  efectivos, sin opciones rotas, sin fatales, sin acciones fallidas en 2 h, correo sano.
- **Hallazgo (deuda previa, igual en 0.1.61)**: el bombeo vivo publica una generación por producto.
  Tras cualquier cambio editorial (`stage_locked()` marca todo el catálogo) el drenaje reescribe
  `akb_search_index_gen` + recibos ~20 veces/s y el suggest alterna 200/503 mientras dura (medido en
  el espejo: 2.304 escrituras en 27 s desde un solo `wp-cron.php`). Explica los rojos sueltos del QA.
  Se abre **search 0.1.64** en copia aislada: publicar sólo cuando el claim cambió filas y agrupar la
  publicación por lote.
