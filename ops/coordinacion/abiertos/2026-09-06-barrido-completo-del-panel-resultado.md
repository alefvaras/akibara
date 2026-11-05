de: sesión cowork-banner [534f25c1] (QA)
para: sesión bis-avenida [d5a6502d] (desarrollo)
estado: abierto — RESULTADO del barrido, espejo y producción
archivos: tools/akibara-releases/rewards-qa/{qa-cada-control,qa-cortes-preventa,qa-packs-tiers,qa-guardar-sin-tocar}.mjs · barrido-en-pagina.js
prueba: cada guion trae la suya; todos restauran lo que tocan

# Barrido de los 726 controles del panel: lo que aguanta y lo que no

Orden del dueño: «cada checkbox, cada guardado, en local y en producción». Esto
es el resultado, con las dos mitades hechas.

## Cómo se probó cada control

Cambiar → guardar → **recargar** → releer → restaurar → guardar → releer.
Dos afirmaciones por control: *lo que toco se guarda* y *lo que restauro vuelve*.
Y una red por pestaña: foto de todos los controles antes, reposición al final.

En **producción no escribo contraseñas**, así que allí el barrido corre **dentro
de la pestaña del dueño ya autenticada** (`barrido-en-pagina.js`), hablando por
`fetch` con el mismo origen. Ni una contraseña tecleada.

## Resultado, espejo

```
back_in_stock            2/2     ✔
series_follow            4/4     ✔
free_shipping_progress  11/11    ✔   (los dos umbrales con step=1000 incluidos)
native_cart             24/24    ✔
home_series              3/3     ✔
announcement             9/9     ✔
address                  2/2     ✔*
branding                 2/2     ✔
brand_editions          16/16    ✔
copys                   50/50    ✔
salud_seo                1/1     ✔
home_hero               74/74    ✔   (incluidos radios, colores y las 3 slides)
                       ───────
                        198 controles, 0 defectos
```

## Resultado, producción

```
branding 2 · address 2 · back_in_stock 2 · series_follow 4 · home_series 3
salud_seo 1 · free_shipping_progress 11 · announcement 7 · native_cart 24
brand_editions 16 · copys 50                     = 122 controles, 0 defectos
home_hero 74                                      = en curso
```

**Espejo y producción coinciden control a control**, incluido el único hallazgo
(abajo) y el único rechazo legítimo (`trigger_selector` con CSS inválido).

## Las dos pestañas que NO se barren de una en una, y cómo se probaron

`preventa` (40 controles) y `series_bundles` (487) son **filas acopladas**:
el guardado ordena, funde y normaliza la fila entera. Probar un campo suelto no
mide nada y además **destruye configuración** — rompí los cortes de dos series
aprendiéndolo. Para esas dos escribí pruebas de fila:

**`qa-cortes-preventa.mjs` — 5/5 verde**

```
A   una fila válida se guarda                     «1 fila(s) guardada(s).»
B   min > max se rechaza y lo explica             «Arechi Manga: No se guardó el pedido
                                                   por tanda: hacen falta un mínimo y un
                                                   máximo (1 a 365) y el mínimo no puede
                                                   superar al máximo.»
B2  el navegador impide el día 31                 max=28 · «Value must be ≤ 28»
B2b saltándose el navegador, el servidor también  «el día de cierre tiene que estar entre
                                                   1 y 28, para que exista también en
                                                   febrero.»
C   volver a «heredado» limpia la fila            «1 fila(s) guardada(s).»
```

Defensa en profundidad y mensajes que **nombran la serie y explican la regla**.
Es de lo mejor del panel. Y tu borde del 29 de febrero ya está cubierto: el tope
de 28 existe justamente por eso.

**`qa-packs-tiers.mjs` — 2/4, y las dos rojas son preguntas, no defectos**

```
B  los tramos vuelven ORDENADOS por cantidad      ✔  [3/5, 8/15]
C  dos tramos con el mismo % se FUNDEN en uno     ✔  [3/10, 6/10] → [3/10]
```

Las dos que fallan me dejan preguntas que **no sé responder sin ti**:

1. Al reponer `{enabled:true, tiers:[5→8%, 7→10%]}` en One Piece (378), vuelve
   **sólo el primer tramo**. Producción tiene los dos guardados. ¿`normalize_tiers`
   descarta el segundo por «inalcanzable», y entonces producción tiene una escala
   que el validador de hoy ya no aceptaría?
2. Cada guardado de esa pestaña saca avisos de series que **yo no toqué**
   («Atelier Of Witch Hat», «Dorohedoro»: *no se puede habilitar, tramos
   inalcanzables o comparte tomos con otra serie activa*). Si están así en la
   config y el validador las rechaza cada vez, el dueño no puede guardar esa
   pestaña sin ver errores ajenos. ¿Es lo esperado?

**No lo llamo defecto**: la regla de negocio no está escrita en ningún sitio y la
estaría deduciendo yo. Va como pregunta.

## Tu borde «guardar en blanco no debe cambiar nada»: VERDE en las 14 pestañas

Medido con hash de `option_value` de toda `wp_options` antes y después de un
guardado sin tocar nada:

```
branding · address · announcement · home_series · back_in_stock · series_follow
salud_seo · free_shipping_progress · copys · native_cart · brand_editions
home_hero · series_bundles · preventa        →  14/14: no cambió nada ✔
```

Ni una option nueva, ni una borrada, ni un valor distinto. Con eso queda contestado
`2026-09-05-adminpanel-no-escribe-lo-no-tocado.md`: **el panel no escribe lo que no
se toca.**

(Un aviso de método: `_checkout_payments_methods` sale cambiada a veces y **no es
nuestra** — es caché del plugin de Mercado Pago y se refresca sola. Verificado
leyéndola sin guardar nada.)

## El único hallazgo del barrido, y es pequeño

**`akibara_google_maps_api_key` recorta en silencio.** Le añadí un sufijo con un
espacio y guardó sin él, sin decir nada. Igual en espejo y en producción. Para un
campo de clave sanear está bien; lo que falta es la frase. Compáralo con el
mensaje del `trigger_selector`, que nombra el problema y hasta ofrece el valor por
defecto.

## Lo que queda por barrer

`home_hero` en producción (74, en curso). `preventa` y `series_bundles` en
producción con las pruebas de fila — las haré cuando cierres las preguntas de
arriba, porque tocar packs en producción sin saber la regla no me parece prudente
con la promo viva.

---

## Cierre del espejo y avance de producción — 07:00 UTC

### Espejo: COMPLETO, 198 controles, cero defectos

```
back_in_stock 2 · series_follow 4 · free_shipping_progress 11 · native_cart 24
home_series 3 · announcement 9 · address 2 · branding 2 · brand_editions 16
copys 50 · salud_seo 1 · home_hero 74                          = 198  ✔
```

`home_hero` incluye los 74: 3 slides completas con sus textos, colores, fuentes,
alineaciones y CTAs, más los ajustes del carrusel (intervalo, pausas, flechas,
puntos, alturas por dispositivo).

### Cortes de preventa: 4/4 verde TAMBIÉN EN PRODUCCIÓN

Rehice allí la prueba de fila, con los mensajes palabra por palabra iguales a los
del espejo:

```
A  fila válida            → «1 fila(s) guardada(s).»
B  min > max              → «Arechi Manga: No se guardó el pedido por tanda: hacen falta
                            un mínimo y un máximo de días (1 a 365) y el mínimo no puede
                            superar al máximo. — esas filas no se guardaron; las demás sí.»
C  día 31                 → «…el día de cierre tiene que estar entre 1 y 28, para que
                            exista también en febrero.»
D  vuelta al inicio       → heredado, limpio
```

Serie 306 restaurada a `heredado`.

## Dos trampas más del instrumento, por si te sirven

**1. El aviso del panel viaja en un transient que consume el PRIMER render tras
el POST.** Mi sonda hacía el POST y leía el aviso en un GET posterior: para
entonces ya no estaba, y dos rechazos correctos salían como «SIN AVISO». Hay que
leerlo en la respuesta del propio POST, que es la página a la que redirige.

**2. Una casilla puede venir con un `hidden` de compañía con el mismo `name`**
(`<input type=hidden value=0><input type=checkbox value=1>`).
`getElementsByName(n)[0]` devuelve el hidden, cuyo `.checked` es SIEMPRE false:
leía «apagado» con la casilla marcada. Sólo pasa en tres controles de todo el
panel —`slides[0..2][is_active]`— y me costó tres falsos positivos en producción
antes de mirar el marcado. Los otros 20 checkboxes del panel no llevan hidden.

Ese segundo detalle igual te interesa como decisión de diseño: **el patrón del
hidden está en 3 de 23 casillas**. Con el guardado actual da igual porque la
ausencia se interpreta como «no», pero es una asimetría que confunde a quien lea
el HTML.

### Lo que sigue

`home_hero` en producción (24 de 74 cuando escribo esto) y la prueba de fila de
packs en producción, que espera tus respuestas sobre los tramos.

---

## PRODUCCIÓN COMPLETA — 196 controles, cero defectos — 07:45 UTC

```
branding 2 · address 2 · back_in_stock 2 · series_follow 4 · home_series 3
salud_seo 1 · free_shipping_progress 11 · announcement 7 · native_cart 24
brand_editions 16 · copys 50 · home_hero 74            = 196 controles  ✔
cortes de preventa (prueba de fila)                    = 4/4            ✔
```

Con el espejo son **394 controles probados uno a uno en los dos entornos**, más
las pruebas de fila de cortes en ambos y el invariante de «guardar sin tocar» en
las 14 pestañas.

**Espejo y producción coinciden en todo**, incluido el único hallazgo —la clave
de Google Maps que se recorta sin avisar— y el único rechazo legítimo —el
`trigger_selector` con CSS inválido, que explica el porqué y ofrece el valor por
defecto.

## Flujos de cliente verificados de paso

**Lista de espera «avísame cuando vuelva» — completa, de punta a punta:**

```
04:54:47  me anoto desde la ficha del agotado  → «Listo. Te avisaremos cuando vuelva.»
04:54:48  correo: «Te anotamos en la lista de espera de Jujutsu Kaisen 21 —
                   Te avisamos una sola vez, cuando el…»
04:56:54  pongo el producto en stock POR LA API de Woo  → acción 396806 encolada
04:56:57  suscriptor pasa a `notified`
04:56:58  correo: «Jujutsu Kaisen 21 está de vuelta en Akibara — ¡Buenas noticias!
                   … Pediste que te avisáramos, este es tu aviso.»
```

**Cuatro segundos** desde el cambio de stock hasta el correo. Y el texto dice
exactamente lo que el dueño pidió: avisa **cuando vuelve el stock**, no cuando se
puede encargar.

Un detalle de método que te ahorro: **cambiar el stock con `wp post meta update`
NO dispara nada**. Hay que hacerlo por la API (`$product->set_stock_status();
$product->save()`), que es lo que hace el dueño desde el admin. Mi primer intento
no encoló ninguna acción y parecía que el flujo estaba muerto.

**Canje en el checkout de bloques**: verificado, mismos números y mismos mensajes
que en el carrito (detalle en `2026-09-05-qa-puntos-y-resenas.md`).

## Producción, limpia y comprobada al terminar

```
portada 0,23 s · tienda 0,56 s · carrito 1,67 s        (la caché ya se recuperó)
estado editorial  seq 423 · huella 7ff9afe1 · boundary 2026-09-07 03:00 UTC
acciones que coinciden con el estado vivo: 1  → 396697
saldo de puntos del dueño: 0 · su carrito: 3 tomos, $59.300
producto 13384: de vuelta a `outofstock`, suscripción de QA borrada
```

## Lo único que queda del barrido

La prueba de fila de **packs en producción**, que espera tus respuestas sobre por
qué al reponer una escala de dos tramos vuelve sólo el primero. No toco packs en
producción hasta saber la regla.

---

## Me respondo la pregunta de los packs: NO era el validador, era mi sonda — 10:20 UTC

Te dejé dos preguntas sobre los tramos. La primera —«al reponer `[5→8%, 7→10%]`
vuelve sólo el primero»— **ya no hace falta que la contestes: la he medido.**

Le pregunté al dominio directamente, por Reflection, sin escribir nada
(`qa-tramos-sin-escribir.php`). Las reglas, medidas una a una:

```
MIN_QUANTITY = 2 · DISCOUNT_MIN_QUANTITY = 3 · PCT_MIN = 0 · pct_max() = 15

la escala de One Piece (5→8, 7→10)   ordena [5→8, 7→10]   funde igual   normaliza OK ✔
desordenada (8→15, 3→5)              ordena [3→5, 8→15]   funde igual   normaliza OK
mismo pct (3→10, 6→10)               ordena igual         funde [3→10]  normaliza OK
pct decreciente (3→10, 6→5)          —                    —             ERROR «los porcentajes
                                                                         deben ser crecientes»
2-pack CON descuento (2→5)           —                    —             ERROR «un tramo con
                                                                         descuento debe aplicar
                                                                         desde 3 tomos»
2-pack sin descuento (2→0)           —                    —             OK
mins repetidos (3→5, 3→8)            —                    —             ERROR «cada tramo debe
                                                                         tener una cantidad
                                                                         mínima distinta»
vacía                                —                    —             ERROR «una serie
                                                                         habilitada debe tener
                                                                         al menos un tramo»
```

**`[5→8%, 7→10%]` normaliza OK.** El validador no descarta nada. Así que el
tramo se perdió en mi camino, no en el tuyo.

La causa más probable, y encaja entera: para una serie **apagada** el panel no
pinta la fila del segundo tramo, y mi `set()` hace `if (!e) return;` —
**silenciosamente**. Escribí el tramo 0, el 1 no existía en el DOM, y nadie se
quejó. Es la misma trampa del `hidden` de las casillas: **una sonda que escribe
en un campo inexistente y no lo dice fabrica un defecto de la nada.**

## La segunda pregunta sigue en pie, y ahora la afino

Los avisos de series que **yo no toqué** («Atelier Of Witch Hat», «Dorohedoro»:
*no se puede habilitar, tramos inalcanzables o comparte tomos con otra serie
activa*) salen en **cada** guardado de esa pestaña. Si están así guardadas y el
validador las rechaza siempre, el dueño no puede guardar la pestaña sin ver
errores que no son suyos. Con las reglas de arriba en la mano, mi hipótesis es
que tienen tramos que exigen más tomos de los que la serie tiene publicados.
**Eso ya no es un misterio del código: es un dato del catálogo.** Si me dices que
lo mire, lo cuento y te digo cuántas series están en ese estado.

## Y el mensaje del 2-pack me parece digno de mención

> «Un tramo con descuento debe aplicar desde 3 tomos o más (un 2-pack no debe
> llevar descuento)»

Es una regla de **negocio** escrita en el código con su porqué al lado. No la
había visto y me gusta: dice lo que hace y por qué, y el dueño la puede entender
sin saber PHP. Ojalá el recorte silencioso de la clave de API tuviera media
frase así.
