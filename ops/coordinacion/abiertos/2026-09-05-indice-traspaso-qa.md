de: sesión cowork-banner [534f25c1] (QA)
para: cualquiera — ÍNDICE, léeme primero
estado: abierto
archivos: (ninguno — índice; no reclama nada)
prueba: bash ops/coordinacion.sh abiertos

# ÍNDICE 2026-09-05 23:00 UTC — todo lo abierto en una pantalla

Existe porque hay **17 asuntos** y nadie debería tener que leerlos todos para
saber qué le toca. Aquí está el mapa; el detalle sigue en cada fichero.
**Si un dato de aquí contradice una medición tuya de ahora, gana la tuya**: hoy
hubo nueve despliegues.

## Producción, medida al escribir esto

```
business 1.115.9 · search 0.1.53 · blocks 2.2.16 · tema 2.1.116
suggest HTTP 200 · state/receipt seq 407 · fingerprints iguales · window_active
akb_search_serving_alarm  ausente
akb_discount_max_pct      NO existe   (el guardado ya no lo recrea)
promo: apply_to=instock_only · 2026-09-05 → 2026-09-06 · 10% Ivrea Argentina
seq 411 (state = receipt) · el vencimiento cae 2026-09-07 04:00 UTC = 01:00 Chile
alcance real de la promo: 586 tomos en bodega
catálogo: 1.131 disponibles · 398 a pedido · 102 agotados · 0 preventas (1.631)
```

## 1. Qué le toca al DESARROLLO (bis-avenida)

| Asunto | Qué falta |
| --- | --- |
| `candado-cruzado-no-cierra` | **PRIORITARIO.** El candado de dominios Search↔Business no ve una divergencia. Medido con control. Va ANTES que cualquier cambio de dominio. |
| `panel-pinta-vigencia-vieja` | Al guardar, el bloque ESTADO pinta la vigencia anterior (2 de 2 en producción). Empuja a guardar otra vez. |
| `debate-preventa-vs-pedido-descuento` | **Respondido por QA.** Propongo desglosar la preventa en el número del panel en vez de una cuarta opción, y documentar el contrato en `is_stock_eligible()`. Te toca. |
| `qa-preventa-con-datos` | El defecto de fondo: el descuento no distingue preventa de a-pedido. Espera a lo de arriba. |
| `qa-puntos-y-resenas` | 20 de 22 campos numéricos tienen `min=0` y **ningún `max`**: un cero de más en un campo de dinero entra sin avisar. |
| `qa-panel-todos-los-modulos` | Contador de caracteres de `AnnouncementBar` (hallazgo 3, no lo tomaste). |
| `panel-recrea-max-pct` | Síntoma resuelto y verificado; falta la causa raíz escrita y un test que la fije. |
| `boundary-duplicado-medido` | `as_unschedule_all_actions()` antes de programar. **NO el domingo**: es el planificador del vencimiento. |
| `run-mudo-y-boundary-duplicado` | No reproducible en 0.1.53. Liberado, es tuyo. |
| Empaquetado de `akibara-search` | Debe llevar tu `wp_unslash` de `FacetQueryIntegration.php` — único fichero que difiere entre árbol y producción. |
| `deuda-analisis-estatico` | 322 errores PHPCS en 79 ficheros, por orden del dueño se cierran, no se heredan. |

## 2. Qué espera DECISIÓN DEL DUEÑO

1. **El vencimiento del domingo.** Medido en vivo el sábado 22:10:32 UTC: el
   precio vuelve a normal en **12 s**, pero el buscador se cae **~36 min** y
   dispara la alarma de 15 min. Se puede: (a) dejarlo y asumir 36 min sin
   buscador de madrugada, (b) retirar la promo a mano el domingo por la tarde con
   el sitio vigilado, o (c) que el desarrollo arregle el rebuild antes. **Mi
   consejo: (b)**, y (c) sin prisa después.
2. **Descontar preventas o no.** Hoy **no urge**: cero preventas, y la promo está
   en `instock_only`. Es un asunto de octubre. Detalle en el debate.
3. **El módulo de Preventa está construido entero y no lo usa nadie.** Pantalla
   «Por llegar», ruta `/preventas/`, recordatorios T-7, avisos de cambio de
   fecha… y cero productos con fecha. O es deliberado o hay una función parada.

## 3. Herramientas de QA que dejo funcionando

Todas en `tools/akibara-releases/rewards-qa/`, contra el **espejo** salvo aviso.

```
node qa-panel-invariantes.mjs       barre las 20 pestañas; falla si un guardado
                                    en blanco escribe en la base
node qa-bordes-modulos.mjs          casos de borde por módulo
node qa-bordes-numericos.mjs        campos numéricos (con guarda checkValidity)
node qa-puntos-propagacion.mjs      panel → ficha de producto
node poner-promo-editorial.mjs      pone/quita la promo desde la interfaz
                                    (producción exige AKB_PERMITIR_PRODUCCION=si)
mirar-preventa.mjs · mirar-porllegar.mjs · mirar-cortes.mjs   inspección
```

**Datos sembrados en el ESPEJO** (no en producción, y hay una razón):
12945 `release_date` +30 d · 12959 `tba` · 13079 `release_date` −10 d.
No se siembran fechas de lanzamiento en producción: una fecha inventada sobre un
tomo real le miente al cliente sobre cuándo le llega. El permiso del dueño para
tumbar el sitio no cubre eso.

## 4. Las seis reglas que costaron OCHO falsos positivos hoy

Están aquí porque cada una nació de que yo di por bueno un defecto que no
existía, y le hice perder el tiempo a alguien.

1. **No midas la foto editorial en la misma petición que la escribe.** Los
   pestillos contaminan.
2. **No cuentes «pendientes» en total**: por *scope* y a lo largo del tiempo. El
   total sube solo porque `repair` se siembra desde los acks de `live`.
3. **Antes de culpar al servidor, comprueba que el formulario se envió.**
   `input[type=number]` con `max` lo bloquea el navegador, sin llegar a PHP.
4. **Los tiempos del espejo no valen para producción.** Producción tiene su
   propio registro; el espejo va a ritmo de cron cada 60 s.
5. **Una observación no es una regla.** Repítela con el sistema sano.
6. **Cuando una medición dice que algo está roto, sospecha primero del
   instrumento.** Seis de los ocho falsos positivos eran el instrumento.

## 5. Protocolo de cierre (acordado hoy, se respeta)

Un asunto arreglado pasa a `esperando-respuesta`, **nunca a resuelto**, y **lo
cierra QA después de medirlo**. Quien arregla no cierra lo suyo. Ya se aplicó:
`etiqueta-disponibles-preventas` está en `resueltos/` porque lo verifiqué yo con
mi propia consulta, no con la del informe.


## 6. Correcciones a lo que YO mismo daba por cierto (medido el 2026-09-05 23:15)

- **«Tocar el panel cuesta ~36 min de buscador» → FALSO** para los guardados que
  no mueven precios. Dos guardados de fechas en producción: `suggest` 200 todo el
  rato, cero reconstrucción, `receipt_resellado_sin_cambio_de_precio`. Mover la
  ventana de la promo es **gratis**.
- **«El candado cruzado nos protege al tocar el dominio» → FALSO.** No cierra.
- **«El cambio de hora de Chile puede romper la firma de zona» → FALSO.** Con
  `timezone_string` no vacío la firma es el literal. Comprobado y descartado.

---

# Estado al cierre de la jornada del 2026-09-06, 10:10 UTC

## Cerrado y verificado EN PRODUCCIÓN (no sólo en la suite)

```
2026-09-06-canje-de-puntos-recorta-en-silencio        los tres recortes avisan y persisten
2026-09-06-el-correo-del-ajuste-manual-miente         el correo dice lo aplicado, no lo pedido
2026-09-06-litespeed-cachea-el-carrito                la cabecera desapareció; sin lecturas viejas
2026-09-06-arma-tu-pack-no-se-apaga                   apagado no pinta NI encola (122/1/4/2 → 0/0/0/0)
2026-09-06-deshacer-un-movimiento-de-puntos           el ajuste manual ya no cuenta como «ganado»
2026-09-06-boundary-una-hora-tarde-por-el-cambio-...  medianoche correcta, 1 acción que coincide
2026-09-06-hooks-que-no-existen                       el precio cambia solo en 5 s, sin purgar
2026-09-06-la-rebaja-manual-...-mas-cara              RETIRADO: falso positivo mío
```

## Medido y verde, sin defecto que arreglar

```
barrido del panel        394 controles uno a uno (198 espejo + 196 producción), 0 defectos
guardar sin tocar        14/14 pestañas: ni una option cambia
cortes de preventa       5/5 espejo · 4/4 producción, con mensajes que nombran la serie
lista de espera          de punta a punta con sus dos correos, 4 s del stock al aviso
canje en checkout        mismos números y mensajes que en el carrito
bordes: dinero · buscador · carrito · panel · fechas · stock · preventa
ensayo del vencimiento   5 s desde que vence hasta que la ficha muestra el precio de lista
```

## Esperando a desarrollo

```
la-foto-de-la-resena-debe-ser-obligatoria   el servidor la exige; el formulario aún dice «(opcional)»
espejo-atascado-bloquea-la-politica         detector de latido muerto y freno a la cola
cada-guardado-purga-la-tienda-entera        ¿la purga puede depender de qué option cambió?
series-follow-nunca-se-ha-probado           ¿qué condición gobierna el encolado?
barrido-completo-del-panel-resultado        packs: por qué al reponer 2 tramos vuelve 1
campana-de-bordes                           las tres preguntas de fechas (29-feb, fecha basura, invertida)
carriles-de-puntos-sin-datos                ¿siembro cumpleaños/referidos/caducidad en el espejo?
```

## Esperando al dueño

```
a-pedido vs campaña      encargar un tomo sale más caro que comprarlo disponible mientras
                         dure una campaña porcentual. Subir el descuento de a-pedido, hacerlo
                         porcentual, o extender la campaña: es decisión suya.
retiro en San Miguel     la barra lo promete y el checkout no lo ofrece con su dirección.
                         ¿En qué comunas debería salir?
```

## Reglas de método que salieron de esta jornada

1. Un control que no puede fallar no es un control.
2. Sospecha del instrumento antes que del sistema.
3. Relee después de escribir.
4. Cuenta ocurrencias, no líneas.
5. Si el borde no se puede dar hoy, se siembra.
6. Antes de creer que no hay dato, comprueba que lees bien la llave.
7. Un filtro no es una definición (`is_in_stock()` incluye `onbackorder`).
8. Cuando midas un efecto, mide también su causa en la misma línea.
9. Antes de escribir estado para medir algo, comprueba si puedes preguntárselo
   al código directamente.
10. Un punto de restauración caduca: comprueba que lo que hay sigue siendo lo que
    dejaste antes de reponer nada.
11. El espejo es compartido. Avisa antes de escribir en él.

---

## Cierre de la tanda de series-follow y correos — 06:3x UTC

### Ítems nuevos

| ítem | quién | qué es |
|---|---|---|
| `2026-09-06-el-guardia-del-mailer-es-el-que-mata-la-corrida.md` | **dev** | **Defecto reproducido en producción.** `AkibaraEmail::mailer_ready()` es estático en una clase que `extends WC_Email`: llamarlo autocarga la clase y el fatal salta al declararla. El guardia contra «WC_Email not found» *provoca* «WC_Email not found». Acción 385728 murió así el 2026-09-02. Afecta también a la lista de espera. |
| `2026-09-06-avisos-de-serie-que-se-marcan-enviados-sin-enviarse.md` | **dev** + copy del dueño | Dos tomos de la misma serie pendientes → se anuncia el más nuevo y el otro queda `sent` sin nombrarse. El colapso está bien (evita spam del import masivo); lo que miente es la contabilidad y el docblock. |

### Ítems cerrados por mí en esta tanda

- **`series-follow-nunca-se-ha-probado`** → no era defecto: mi semilla fallaba
  por dos motivos a la vez (título sin número final, `catalog_visibility=hidden`).
  Repetida con semilla válida, el módulo va: encola 11 de 13, correo honesto,
  RFC 8058, baja de un clic real, tope semanal que **retiene** en vez de tirar.
- **`el-retiro-si-se-ofrece`** → dev tenía razón, yo estaba equivocado.
  Verificado como invitado: retiro gratis en RM y sólo en RM.

### Regla 12, aprendida hoy dos veces en el mismo asunto

> **Si una respuesta vuelve vacía, comprueba que le diste todo lo que pide antes
> de creer que el sistema no lo hace.**

Me devolvió «sin métodos de envío» porque no puse comuna ni código postal, y
«cola = 0 filas» porque sembré un producto que el módulo descarta con razón. Las
dos veces el sistema tenía razón y el instrumento estaba corto.

### Estado del espejo

Limpio y verificado: cola 0, 0 productos de QA, metas `_akb_sf_*` de los 11
clientes tocados borradas, acciones canceladas, `akb_sf_enabled=no`,
`akb_sf_digest_delay_hours=6` (igual que producción), sin ficheros temporales.
Queda una meta ajena que **no era mía** y no toco: `u287 _akb_sf_optout = a:0:{}`
(array vacío, inocua).

## Segunda tanda: reseñas de punta a punta — 07:3x UTC

| ítem | quién | qué es |
|---|---|---|
| `2026-09-06-la-resena-funciona-entera-y-el-texto-quedo-a-medias.md` | dev (un texto) | Flujo completo VERIFICADO en el espejo: foto obligatoria rechaza/acepta, token se quema, reseña con `verified=1`, foto reparentada, **500 puntos al aprobar y NO paga dos veces**. Defecto único: línea 542 dice «se acreditan tus puntos *si subiste foto*» y la foto ya es obligatoria. |
| `2026-09-06-por-que-la-tienda-tiene-cero-resenas.md` | **DUEÑO** | Diagnosticado: CusRev programaba los recordatorios, se desinstaló, y sus avisos del 1-sep se fueron con él. ~11 pedidos completados (4 con cliente registrado, 27-ago…1-sep) quedaron sin invitación de nadie. El motor nuevo sí funciona en producción (rastro de envío en 27955 y 27958). Propuesto un rescate de una vez; **no se ejecuta sin el sí del dueño**. |

### Estado del tablero

Moví a `resueltos/` siete ítems que ya estaban cerrados o retirados y seguían en
`abiertos/`. Quedan **41**, y de esos la mayoría son informes o notas de método,
no defectos vivos. Defectos abiertos de verdad: **3** (guardia del mailer, avisos
de serie que se marcan enviados, texto de la reseña) más **3 decisiones del
dueño** (copy del retiro, rescate de invitaciones, a-pedido vs campaña).

### Reglas nuevas de esta tanda

- **12.** Si una respuesta vuelve vacía, comprueba que le diste todo lo que pide
  antes de creer que el sistema no lo hace. *(«sin métodos de envío» sin comuna;
  «cola = 0» con semilla inválida.)*
- **13.** Un `grep` de nombres también acierta dentro de otras palabras.
  Buscando `review` me salió `hostinger-p-review-domain`.
- **14.** La tabla de pedidos también guarda reembolsos. `wc_get_order()` puede
  devolverte un `OrderRefund` y reventar un `TypeError` que no es del sistema,
  es tuyo. Filtra `type="shop_order"`.

## Tercera tanda: la cadena del pedido y el arreglo del mailer probado A/B — 08:2x UTC

| ítem | quién | qué |
|---|---|---|
| `2026-09-06-la-cadena-completa-del-pedido-probada.md` | informe | Pedido real por sus estados: **1000 puntos** (500 compra + 500 bienvenida), tasa `2/100` sellada, reembolso parcial revierte **−250** sin tocar el bono, `total_earned` no baja, e invitación de reseña agendada a +3 días. **Cero defectos.** |
| `el-guardia-del-mailer…` (apéndice) | dev | Arreglo aplicado y quitado en el espejo: **A/B con la corrida real por petición web**. Sin arreglo `DIGEST_FATAL` y la fila se queda atascada; con arreglo `DIGEST_OK` y se procesa. Parche listo en `tools/…/parche-mailerboot/`. |

### Reglas 15 y 16

- **15.** Antes de decir que un disparador no dispara, comprueba que pasaste por
  el estado que lo dispara. Los puntos cuelgan de «Listo para enviar», no de
  «Completado».
- **16.** Un verde con el sujeto apagado no es un verde. Mi primera corrida del
  resumen dio `DIGEST_OK` con `akb_sf_enabled=no`: `run()` sale antes de llegar a
  lo que yo creía estar probando.

### Por qué los tres defectos siguen sin aplicar en el código

No es protocolo, son dos hechos:

1. **Este repo NO tiene control de versiones.** `git rev-parse` falla: no hay
   `.git`. Sin `checkout` que deshaga, editar fuente con dev fuera de línea y el
   dueño durmiendo es cómo se pierde trabajo. Cuando toqué fuente para el A/B,
   restauré **verificando md5** y quité el fichero nuevo del árbol.
2. **Producción es un despliegue, no una edición.** El defecto del mailer está
   vivo allí (mismo md5 que el espejo), pero desplegar no es una acción de QA.

Los tres están **a un `cp` y dos líneas** (el del mailer), **a una cadena de
texto** (el de la reseña) y **a una decisión de diseño** (el del colapso de
avisos: si las filas comidas deben quedar `dropped` con motivo o si el correo
debe nombrarlas). El tercero **no lo decido yo**, y por eso no lo escribo.

## Cuarta tanda: el buscador en producción — 08:5x UTC

| ítem | quién | qué |
|---|---|---|
| `2026-09-06-el-tomo-1-no-sale-en-el-desplegable-del-buscador.md` | **dev** · impacto comercial | «one piece 1» en el desplegable devuelve 100-107 y **nunca el tomo 1**; «01» devuelve 010-016; sólo «001» acierta. Pasa en Kingdom, Blue Lock, Dan Da Dan. **La página de resultados completa SÍ pone One Piece 001 primero**, así que las dos superficies se contradicen y falla la que el cliente ve primero. El resto del buscador (tildes, mayúsculas, erratas, inyección, 300-800 ms) está bien. |

### El reparto lo mandó el dueño, no lo acordamos nosotros

Lo dejo aquí porque me lo han cuestionado hoy. Textual, en
`2026-09-05-reparto-nuevo-qa-y-desarrollo.md`:

> «Tú debes ser principalmente el QA, ese rol tomarás de ahora en adelante, y le
> levantarás todos los errores al otro agente y **él promoverá los cambios**;
> luego tú los revisarás.»

Y en `2026-09-06-regla-del-dueno-como-se-prueba.md`:

> «Cada checkbox, cada guardado, cada pequeño detalle lo debes probar y validar
> que funciona. **Si no, lo pasas al agente dev, él lo resuelve, lo vuelves a
> revisar tú y das el visto bueno para producción.**»

No es una convención entre sesiones: es orden del dueño, dos veces. Por eso mido,
reproduzco y dejo el arreglo listo, y **no lo aplico ni lo despliego** salvo que
él lo pida.

### Regla 17

> Una comilla tipográfica pegada a una variable pasa a formar parte de su nombre.
> Me pasó hoy **dos veces**: `«$titulo»` en PHP y `"«$q»"` en bash. Usa `${q}`.

## Quinta tanda: preventa vista como cliente, en producción — 09:1x UTC

| ítem | quién | qué |
|---|---|---|
| `2026-09-06-el-tomo-que-esperas-cinco-semanas-cuesta-mas.md` | **DUEÑO** | Con cifras en vivo: Black Clover 02 (a pedido, llega 8-14 oct) **$9.600** vs Black Clover 03 (disponible, 24-48 h) **$9.090**. +$510 por esperar cinco semanas, en la misma pantalla. No es defecto: es la campaña editorial y la rebaja de reserva cruzándose. Recomiendo la opción 2 (la reserva nunca peor que la campaña vigente). |

### Comprobado y LIMPIO en esta tanda (sin defecto)

- **Stock en producción**: 1.131 en stock, 102 agotados, 398 a pedido,
  **0 con cantidad negativa**, **0 «en stock» sin unidades**. El −1 que vi antes
  era de mi pedido de prueba en el espejo, no de producción.
- **Ficha de «a pedido»**: dice a pedido, da precio de reserva, **avisa de que el
  precio sube al llegar**, fecha estimada, fecha de corte y tiempo de viaje. Y
  **no lleva la promesa de «24 a 48 h»** — la busqué expresamente en dos fichas:
  cero veces. No se le miente al comprador.
- **Las fechas se calculan**: `corte día 20 + mín 18 = 8-oct`, `+ máx 24 = 14-oct`
  → la ficha pinta «8 – 14 oct». Cuadra exacto con la configuración de cortes.
- **Buscador**: tildes, mayúsculas, erratas, espacios, inyección y bordes, todo
  bien, 300-800 ms. El único agujero es el del tomo 1 en el desplegable.

## Sexta tanda: envío, stock y carrito en producción — 09:4x UTC

**Todo lo de esta tanda salió LIMPIO salvo un detalle señalado a medias.**

- **Envío gratis sobre $60.000: se cumple.** $39.253 → Blue Express $4.800;
  $69.809 y $92.726 → **GRATIS**. La promesa del banner es real. Esto **rebaja la
  prioridad** de `umbral-envio-gratis-no-hace-nada`: el campo del panel confunde
  a quien administra, pero a ningún cliente se le cobra de más.
- **Candado de stock**: rechaza 6 unidades de un tomo con 1 y dice «queda 1».
  Producción sin sobreventa: 0 negativos, 0 «en stock» sin unidades.
- **Retiro en San Miguel**: gratis, y sólo en RM.
- **Señalado sin confirmar**: el nombre de la tarifa llega de la API con
  `&#8211;` sin decodificar. No monté un carrito en el navegador del dueño para
  verlo —sesión de administrador con datos de un tercero— así que **no afirmo que
  el cliente lo vea**. Falta una mirada de un segundo.

### Recuento honesto del estado

**Defectos abiertos: 5** — guardia del mailer (producción, con parche probado
A/B), texto «si subiste foto», colapso de avisos de serie, tomo 1 ausente del
desplegable, y el `&#8211;` por confirmar.
**Decisiones del dueño: 3** — precio a-pedido vs campaña (con cifras), copy del
retiro «(Santiago)», y el rescate de las ~11 invitaciones del hueco de CusRev.
**Cero de ellos los aplico yo**, por orden suya de 2026-09-05.

## Séptima tanda: impuestos, pagos y la causa raíz del buscador — 10:1x UTC

| ítem | quién | qué |
|---|---|---|
| `2026-09-06-el-iva-del-despacho-va-en-cero.md` | **DUEÑO** (su contador) | Productos con IVA 19% impecable (9.090 = 7.639 + 1.451). El **despacho va con IVA 0** por configuración (`tax_rate_shipping = 0`). Al cliente no se le cobra de más; si corresponde IVA, lo estarías poniendo tú (~591 por despacho de 3.700). **No doy opinión tributaria.** |
| `el-tomo-1-no-sale…` (apéndice) | **dev** | **Causa raíz medida.** No es el matcher: el tomo 1 **sí** es candidato, en posición 24 de 40. Es el `Scorer`: «one piece 100» empieza con la cadena «one piece 1» → `TIER_TITLE_PREFIX = 1000`; el tomo 1 sólo saca `BONUS_TOMO_EXACT` → **400**. Gana el prefijo accidental por 600 puntos. Dos arreglos propuestos; **la elección es de dev** porque toca el orden de todo el buscador y hay un caso declarado (RED-TEAM M5) que no se puede romper. |

### Y una teoría mía que resultó falsa, dicha en voz alta

Primero supuse que el tomo 1 se indexaba como «001» y que el prefijo no lo
alcanzaba. **Mentira**: los tokens reales son `001`, `01` **y** `1`. Si mando esa
teoría sin comprobarla, dev arregla el sitio equivocado. Queda como recordatorio
de la regla 8: **cuando midas un efecto, mide su causa en la misma línea.**

### Pagos

Pasarelas activas: `bacs`, `flowpayment`, `woo-mercado-pago-basic`,
`woo-mercado-pago-custom`, `pickup_location`. Habilitadas en configuración.
**El pago en sí no lo pruebo**: no introduzco datos de tarjeta ni credenciales
en ningún entorno. Llego a la elección del medio y me detengo.
