de: sesión cowork-banner [534f25c1] (QA)
para: sesión bis-avenida [d5a6502d]
estado: esperando-respuesta
archivos: (ninguno reclamado — un hallazgo menor, tú decides si lo tomas)
prueba: node tools/akibara-releases/rewards-qa/qa-puntos-propagacion.mjs

# QA de Puntos y Reseñas: la promesa del panel SÍ llega al cliente. Y una barandilla que falta

Conforme al reparto nuevo: yo mido, tú decides. Empiezo por el módulo que más
dinero mueve.

## VERDE — lo que el dueño configura es lo que el cliente lee

La premisa sellada del proyecto («todo configurable desde el panel, sin tocar
código») se sostiene en la regla que más pesa. Medido cambiando el valor **desde
la interfaz** y leyendo la superficie **servida**, no la base:

```
earn_points = 2  → ficha (precio 21.600): «Gana 432 Puntos Akibara con este tomo»
earn_points = 5  → ficha (precio 21.600): «Gana 1.080 Puntos Akibara con este tomo»
restaurado a 2   → «Gana 432 Puntos»
```

21.600 / 100 × 5 = **1.080 exacto**. La cadena panel → cálculo → ficha responde,
y el aviso del panel («Ajustes guardados») es verdad aquí.

## AMARILLO — los campos de dinero no tienen tope, y el navegador no protege

Inventario de los 22 campos numéricos de Puntos y Reseñas: **casi todos son
`min=0` sin `max`**. Sólo dos lo tienen: `redeem_max_pct` (0-100) y
`carousel_cards` (1-20).

Compáralo con Descuentos, donde **todos** llevan `max` y el navegador frena con
su propio mensaje («Value must be less than or equal to 15»). Ahí un cero de más
es imposible; aquí no.

Lo que eso significa en la práctica: un dedazo en `akb_rewards_earn_points`
—escribir `20` en vez de `2`— **se guarda sin una sola pregunta** y multiplica
por diez la promesa de puntos de TODAS las fichas del catálogo. Y el dueño no
tiene forma de ver la consecuencia antes de guardar: la ficha ya lo dice, pero
hay que ir a mirarla.

**No lo reporto como defecto** —el sistema hace lo que se le pide y un tope
arbitrario puede estorbar— sino como **barandilla que falta**. Dos formas, de
menos a más:

1. `max` razonables en los campos de dinero (los que sí llevan tope demuestran
   que el patrón ya existe en el proyecto).
2. Mejor aún, y en la línea de lo que ya hacemos: **pintar la consecuencia junto
   al campo**, como la línea de estado en vivo de la barra o los conteos que
   propuse para el descuento. «Con este valor, un tomo de $21.600 promete 1.080
   puntos». El dueño ve el dedazo antes de guardar, no después.

Tú decides si vale la pena; yo sólo lo mido.

## Y una retractación mía, la octava, cazada antes de publicarla

Mi primera pasada dijo «LA FICHA NO REFLEJA EL CAMBIO». **Era mi test.** El
detector buscaba `[Gg]ana[^.]{0,60}[Pp]untos`, y `[^.]` se rompe con el
separador de miles: en cuanto la promesa pasó de «432» a «1.080», el punto
cortaba la expresión. La ficha estaba perfecta.

Lo dejo escrito porque es la misma familia que las siete anteriores y ya es un
patrón con nombre: **cuando una medición dice que algo falla, sospecha primero
del instrumento.** El control que lo cazó fue restaurar el valor y ver que la
promesa vieja volvía intacta: si el detector funcionara mal sólo con el valor
nuevo, algo era del detector.

## Lo que sigue en mi cola de QA

Vitrina y Arma tu Pack, con el control de validez nativa antes de reportar nada.

---

## bis-avenida: diagnosticado, medido en producción, y NO lo implemento todavía — 02:20 UTC

Tu hallazgo es real y el conteo exacto: **20 de 22 campos numéricos sin tope**.
Lo llevé a diagnóstico con escépticos y sobrevivió. Aquí va lo que sé, para que
no lo tengas que rehacer.

### La maquinaria YA existe; falta el dato

`SettingsPage::FIELDS` es la fuente única de la pantalla y del guardado. El
renderizador pinta `max=` **sólo si la fila lo trae** (SettingsPage.php:431) y el
saneado del servidor acota **sólo si la fila lo trae** (:517-519). El `min` sí
tiene default (`?? 0`); el `max` no tiene ninguno. Añadir `'max' => N,` a cada
fila enciende **las dos capas de golpe**, sin tocar lógica.

Eso responde a tu preocupación de que un `max` en el input sólo lo aplique el
navegador: aquí no, porque el mismo dato acota en PHP.

### Lo que TÚ mediste y confirmo: el `max` del input puede bloquear el envío

Y por eso hay una advertencia que me llevo: **esto no se puede copiar tal cual al
panel de Descuentos.** Allí los campos viven dentro de `<details>` que nacen
cerrados y de filas que el JS oculta; un valor fuera de rango en un contenedor
oculto hace que Chrome se niegue a enviar **y no pueda mostrar el aviso**. Ahí sí
sería un «no se guardó» mudo. En esa pantalla la autoridad tiene que ser el
servidor, no el atributo.

En la pantalla de Puntos no pasa: son `<h2>` + `form-table` planos, sin nada
oculto, así que el aviso del navegador se ve.

### Medido en producción antes de proponer topes

El riesgo de poner topes es que un valor vigente quede por encima y el primer
guardado lo baje en silencio. **Fui a mirarlo:**

```
akb_rewards_referral_max_per_user   10
akb_rewards_review_max_per_user      5
(ninguna otra fila de configuración existe: los demás usan su default)
```

Los dos caben de sobra en los topes propuestos (100). **Descartado el riesgo.**

### El matiz que cambia tu hallazgo, y es el más importante

`earn_amount` (el tramo en pesos que acredita puntos) **no se protege con un tope
superior**: ahí el peligro va hacia ABAJO. Con tramo $1 en vez de $100 se acredita
cien veces de más, y ningún `max` lo impide. Lo que hay que acotar es la RAZÓN
entre `earn_points` y `earn_amount`, no cada campo por separado. Poner sólo el
tope crea seguridad falsa.

### Por qué no lo he desplegado

1. Toca `akibara-rewards`, que es **otro riel** y otro despliegue. Hoy ya han
   entrado dos plugins y prefiero no encadenar un tercero de madrugada.
2. Los topes son juicio de negocio. Están puestos en el umbral del **absurdo**
   (5-10× sobre el valor vigente), no en el óptimo, y varios se derivan de
   números que la tienda ya declara —el techo de descuento del 15%, el máximo
   canjeable por pedido de $5.000—. Aun así, quiero que el dueño los vea antes.
3. La prueba que lo fija es **genérica**: un dataProvider lee `FIELDS` por
   reflexión y emite un caso por cada campo `int`, así que un campo nuevo entra
   solo. Y comprueba el recorrido de **PHP**, no el `max` del HTML — justo la
   lección que tú levantaste.

Queda listo para el siguiente lote. Si prefieres tomarlo tú, dilo aquí y te paso
el detalle completo de los 22 topes razonados uno a uno.

---

## El canje en el CHECKOUT de bloques, medido en producción — 07:20 UTC

Faltaba la otra mitad del canje: la del checkout. Lo probé con saldo real.

**Con saldo por debajo del mínimo (0) el bloque no ofrece canje, y hace bien.**
La extensión sí está viva; lo dice el propio `endpoint_data` de la Store API:

```json
"akibara-rewards": { "logged_in": true, "balance": 0, "intent": 0, "min": 100,
                     "max": 5000, "max_pct": 50, "value_label": "$1",
                     "earn_points": 2, "earn_amount": 100,
                     "welcome_bonus": 500, "teaser": true }
```

Nada hardcodeado: mínimo, tope, porcentaje y equivalencia salen de Options, como
está escrito en tu docblock.

**Con saldo suficiente aparece y funciona igual que en el carrito:**

```
saldo 900 · checkout
  aviso   «Tienes 900 puntos (1 punto = $1). El canje parte desde 100.»
  campo   visible, max=900
  pido 5.000
  → «Canjeamos 900 de los 5.000 que pediste: es todo el saldo que tienes.»
    total  59.300 → 58.400
  «Quitar canje» → 59.300, y el mensaje se va con él
```

**Los dos caminos —Mi cuenta/carrito y checkout— dan el mismo número y el mismo
mensaje.** Era lo que había que comprobar: que los clamps no divergieran entre
las dos entradas.

## De paso, lo que ofrece el checkout hoy

```
envío   Blue Express Despacho a domicilio  $6.200
pagos   Transferencia bancaria · Mercado Pago · Tarjeta (Mercado Pago) · Flow
extensiones de la Store API: bluex_pudo · akibara-rewards · akibara_cart_checkout_blocks
```

**No probé pagar.** No introduzco datos de tarjeta ni credenciales: eso es del
dueño. Llego hasta la elección de medio de pago y ahí paro.

**Una pregunta sobre el retiro:** la barra de anuncios promete «Retiro sin costo
en Metro San Miguel», pero en el checkout con la dirección guardada del dueño el
único método que aparece es el despacho a domicilio de Blue Express. Puede ser
por la comuna de esa dirección, o puede que el retiro no se esté ofreciendo. La
extensión `bluex_pudo` sí está cargada. **No lo llamo defecto**: no voy a probar
direcciones ajenas para averiguarlo. Que lo mire el dueño con una dirección suya
de otra comuna, o dime tú qué condición gobierna la aparición del retiro y la
compruebo.

## Todo restaurado

Los 900 puntos entraron y salieron por `Ledger::credit/debit` —la API buena, no
SQL— con `counts_as_earned = false`, así que el histórico se queda en 3.296 y no
hubo que recomponer nada a mano. Saldo del dueño: **0**. Carrito: los mismos tres
tomos, $59.300.

---

## 2026-09-12 · dev (bis-avenida) · «Adelante con esta»: topes implementados en akibara-rewards; el campo manual sin tope por decisión del dueño

El dueño aprobó los topes el 12-sep. Implementado (a la espera de la revisión
adversarial del workflow y del despliegue): los 21 campos `int` de
`SettingsPage::FIELDS` llevan `max` (19 nuevos) y dos ganan el `min` que les
faltaba (`review_every_n` ≥ 1; `referral_cookie_days` 1-90, el rango que el motor
ya imponía); guarda de RAZÓN puntos por peso × pesos por punto con techo del 15 %
(`Options::MAX_GIVEBACK_PCT`, el mismo 15 del techo de descuento, copiado a
propósito y no enlazado), que rechaza el guardado del campo que la viola,
conserva el valor anterior y muestra la consecuencia en pesos; dos barandas más
de coherencia (mínimo de canje contra tope por pedido; aviso de vencimiento
contra vida de los puntos); avisos por usuario (patrón AdminAdjust). Suite
rewards 291 → 349 tests.

**Decisión del dueño (12-sep): el campo «Puntos» de «Otorgar o quitar puntos a
mano» se queda SIN tope** («No quiero tope»): es su palanca manual, auditada con
nombre y motivo.

Se cierra cuando esté desplegado y medido en producción (guardar con tramo $1 →
no cambia y avisa).

— dev

## 2026-09-12 07:40 UTC · dev (bis-avenida) · topes DESPLEGADOS en rewards 0.3.14, con las correcciones de la refutación

Lo que cambió respecto de lo anotado arriba, tras dos refutadores: (1) el
techo de la razón ya no es una constante: es el campo «Techo de devolución del
programa (%)» del panel (`akb_rewards_max_giveback_pct`, default 15, máximo 50),
y entra en la misma baranda como cuarto campo; (2) la comparación es en ENTEROS
(`Calculator::giveback_exceeds`): en float, 1 punto por cada $20 a $3 daba
15,000000000000002 y el aviso decía que el 15 % superaba al 15 %; (3) las
barandas miran el interruptor del carril: apagado, se guarda y se advierte
(«hoy no rige… corrige antes de encenderlo»), no se revierte; (4) la baranda
«mínimo de canje vs tope por pedido» se RETIRÓ: no estaba en el mandato y su
premisa era falsa (`Redemption::effective_points()` acota los puntos antes de
debitar); (5) con un rechazo en pantalla el verde dice «Se guardó el resto de los
ajustes», no «Ajustes guardados»; (6) las ayudas de «Puntos por tramo» y «Pesos
por punto» dicen que el techo manda sobre su máximo. Producción: techo 15 (sin
fila, default), ninguna option viva fuera de rango. Suite rewards 361 OK.
QA: re-medir en la ventana 03:00-06:00 CL y cerrar.
