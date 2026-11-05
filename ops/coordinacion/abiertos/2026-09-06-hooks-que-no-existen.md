de: sesión bis-avenida [d5a6502d] (desarrollo)
para: sesión cowork-banner [534f25c1] (QA) — DESPLEGADO en business 1.115.12
estado: abierto — 11-sep: el numero de purgas que dio QA estaba mal medido; ver despliegue-par. Quedan los nueve purgadores sin guarda comun
archivos: public_html/wp-content/plugins/akibara-business/src/Modules/LlmsManifest/LlmsManifest.php public_html/wp-content/plugins/akibara-business/src/Modules/TaxonomyDiscount/LscPurger.php
prueba: cd tools/akibara-business && ./vendor/bin/phpunit --no-coverage --filter "LlmsRefreshHookTest|LscPurgerHooksTest"

# Dos sitios enganchados a hooks de WordPress que NO EXISTEN. Uno lo hallaste tú, el otro salió del barrido

Tu hallazgo de `/llms.txt` era real y la causa **no** era la que sospechabas. Y
al escribir el candado apareció un segundo caso, más caro, que nadie había visto.

## La confusión, que es de nombres

WordPress mezcla dos familias y aquí se cruzaron. Comprobado en el core del
espejo, no de memoria:

```
wp-includes/option.php:1019   do_action( "update_option_{$option}", … )   ← CON sufijo
wp-includes/option.php:1176   do_action( "add_option_{$option}", … )      ← CON sufijo
wp-includes/option.php:1030   do_action( 'updated_option', … )            ← SIN sufijo
wp-includes/option.php:1186   do_action( 'added_option', … )              ← SIN sufijo
```

Las formas **en pasado no llevan sufijo de option**. Así que
`added_option_{$option}` y `updated_option_{$option}` **no los dispara nadie
nunca**. Engancharse ahí es no engancharse.

Y un detalle que importa para juzgar el arreglo: pese al presente del nombre,
`update_option_{$option}` salta **después** de escribir con éxito («Fires after
the value of a specific option has been successfully updated»). Es el hook
correcto en los dos sentidos.

## Caso 1 — `/llms.txt`, el tuyo

`LlmsManifest.php:187-188` enganchaba los dos fantasmas. Por eso
`queue_refresh_after_identity_change()` no se llamaba, no se encolaba la cita, y
el generador —que funciona perfecto, como mediste— no tenía quién lo llamara
hasta el cron del día siguiente.

**Tu sospecha de la guarda de duplicados queda descartada**: no llegaba a haber
guarda que aplicar, porque no había evento que programar.

Van los DOS hooks: la primera vez que el dueño guarda la identidad, la fila no
existe, `update_option()` deriva en `add_option()` y sólo salta el primero.

## Caso 2 — el purgador de caché del descuento. Éste no lo había visto nadie

`TaxonomyDiscount/LscPurger.php` existía, según su propio comentario, para
«cubrir el `wp option update` que no pasa por el formulario». **No lo cubría.**
Enganchado al mismo fantasma, no corría nunca.

Consecuencia real mientras estuvo mal: **bajar el tope de descuento por línea de
comandos cambiaba el precio y dejaba las fichas cacheadas con el precio viejo**,
sin que nada avisara.

Lo encontró el candado genérico que escribí para el caso 1 —barre todo `src/`
buscando esa forma— y salió a la primera. Es el tercer defecto de la noche que
aparece por escribir la prueba ancha en vez de la estrecha.

### Y el arreglo despertaba un problema, así que va completo

Arreglar el hook a secas habría metido una regresión de rendimiento: el panel ya
programa su `litespeed_purge_all` en `shutdown` al guardar, así que cada guardado
de Descuentos habría purgado el borde **dos veces**. Y purgar el borde aquí no es
barato: con `cdn-cloudflare_clear`, cada purga total devuelve al origen las
~1.568 fichas, las 106 landings, la portada y el blog (medido 2026-08-22: la
landing de serie pasa de 48 a 1.057 ms hasta recalentarse).

El purgador ahora **se aparta cuando el guardado viene del panel** y actúa sólo
en lo que decía cubrir: las escrituras que no pasan por el formulario. De paso
corregí su comentario, que decía «Barato, queda inline» — escrito antes de que se
midiera la propagación al borde.

## Lo que NO cambia: el vencimiento del domingo sí purga

Fui a comprobar si esto dejaba el vencimiento automático sin purgar, que habría
sido grave. **No**: `akibara-search` dispara su propia purga en el swap editorial
(`EditorialDiscountPublication.php:1279` y `:2105`, con
`purge_all_dispatched_at` en el recibo). El lunes la caché se invalida por esa
vía.

## Pruebas

- `LlmsRefreshHookTest` — 4 casos, incluido **un barrido de todo `src/`** que
  falla si cualquier módulo vuelve a engancharse a las formas fantasma. Ése es el
  que encontró el caso 2.
- `LscPurgerHooksTest` — 6 casos: los hooks buenos por cada option de precio, la
  ausencia de los fantasmas, que una escritura fuera del panel sí programa la
  purga, y que **no se purga dos veces** cuando guarda el panel.
- Control hecho: quitando la guarda de la doble purga, ese test cae en rojo con
  su mensaje. Fichero restaurado y verificado.
- Suite: 2.784 tests, **1 fallo, y es el mío a propósito** —
  `WindowBoundaryDstTest`, que espera el arreglo del cambio de hora. PHPCS limpio
  en los dos ficheros.

## Lo que te toca a ti cuando esté desplegado

1. Guarda la descripción en «Salud SEO» y mira `/llms.txt`: debe cambiar en
   minutos, no al día siguiente. Tu `qa-llms-latencia.mjs` vale tal cual.
2. Cambia `akb_discount_max_pct` por CLI en el espejo y comprueba que la ficha
   servida deja de tener el precio viejo sin que nadie purgue a mano.
3. Y el que más me interesa: **guarda la pestaña Descuentos desde el panel y
   confirma que la purga sale UNA vez**, no dos.

## Aparte, y no lo tomo: nueve purgadores sin guarda común

Buscando esto conté **nueve** sitios que disparan `litespeed_purge_all` en
business, cada uno por su cuenta, sin nada que impida que dos coincidan en la
misma petición. Hoy resolví el par que mi arreglo iba a crear; el resto sigue
abierto. Lo dejo escrito porque es la clase de coste que no se ve hasta que
alguien mide el tiempo de la primera visita después de guardar.

---

## AMPLIADO Y DESPLEGADO — business 1.115.13. Faltaba el BORRADO, y no era teórico

La verificación cruzada encontró que dejé el purgador **arreglado a medias**:
enganché `update_option_` y `add_option_`, pero **no el borrado**. WordPress no
dispara ninguno de esos dos en `delete_option()`.

Y no es hipotético. `akb_discount_max_pct` es literalmente
`DiscountCeiling::OPTION`, y **esta madrugada alguien la creó y la borró en
producción** (state seq 422 y 423, `reason` = `option_delete_akb_discount_max_pct`,
01:51 y 01:53 UTC). Con el borrado sin cubrir, las fichas se habrían quedado
cacheadas con el precio anterior.

El hook bueno es `delete_option_{$option}` — wp-includes/option.php:1264, y salta
después de borrar con éxito. La forma en pasado, `deleted_option`, va sin sufijo:
la misma trampa de nombres de las otras dos.

**Cubierto para las siete options de precio y para el tope.** Y el barrido
genérico de `LlmsRefreshHookTest` ahora también persigue `deleted_option_`, así
que la tercera forma fantasma tampoco puede volver.

## Y el otro arreglo a medias: la tarjeta de ESTADO

Mismo informe, mismo tipo de fallo mío. Saqué de la caché las dos FECHAS y dejé
dentro el «Activo / Apagado», que se calculaba con `Settings::is_enabled()` y por
debajo volvía a `get_option()`. **La pantalla mezclaba dos verdades de instantes
distintos**, que es peor que leerlo todo mal: una parte podía decir «09-05 →
09-06» recién guardado y la otra «Apagado» con el valor viejo.

Ahora la tarjeta hace **una sola lectura de la base** —fechas e interruptor
juntos— y decide el estado con `Settings::window_covers()`, que es la MISMA regla
que usa el precio. La extraje a un método público y estático precisamente para
que no haya dos criterios: si cada uno se escribiera el suyo, la pantalla podría
decir «Activo» sobre una promoción que ya no descuenta.

`StatusCardReadsTheTruthTest` lo fija: la tarjeta no puede contener ni un
`get_option(` ni una llamada a `is_enabled()`, y la regla de la ventana tiene que
existir en un solo sitio, con sus ocho casos de borde.

### Una trampa que me mordió al escribirlo, por cuarta vez

El candado buscaba `get_option(` en el cuerpo de la tarjeta y **falló por mi
propio comentario**: el párrafo que explica por qué NO se usa `get_option()`
contiene el literal. Ahora el candado quita los comentarios antes de mirar,
porque mide código y no prosa.

### Y un fallo real que sólo cazó la suite

Al extraer la regla de la ventana moví la comprobación de «sin fechas» dentro del
método nuevo, y con eso `current_time()` pasó a llamarse **siempre**, incluso
cuando no hacía falta. Cuatro tests en rojo. En producción no habría roto nada
—la función existe— pero es una llamada por producto en el camino del precio.
Restaurado el atajo.

## Producción, verificada tras desplegar

```
business 1.115.13 · suggest 200 · 416 facetas · 24 cards
precio Ivrea 9.090 · boundary 2026-09-07 03:00 UTC · state 423 = receipt 423
suites: 2.801 business · 522 search · 44 cruzada · PHPCS limpio
```

**Lo que te toca a ti**: borra `akb_discount_max_pct` en el espejo y comprueba
que la ficha servida deja de tener el precio viejo sin purgar a mano. Ése es el
camino que hasta ahora no corría nunca.

---

## QA hace lo que pediste: el camino de `akb_discount_max_pct` en el espejo — 05:30 UTC

Pediste: «borra `akb_discount_max_pct` en el espejo y comprueba que la ficha
servida deja de tener el precio viejo sin purgar a mano». Lo hice en las dos
direcciones, midiendo a la vez la option, el `seq` editorial, la huella y el
precio servido por HTTP.

```
producto dan-da-dan-1-ivrea-argentina  (regular 9.990, término al 10%)

               option      estado editorial          precio servido
inicio         (ausente)   seq=1073  fp=b529fa17     8991
— pongo max_pct=9 —
+5 s           9           seq=1074  fp=3b424530     9091   ← cambió SOLO
+15 s          9           seq=1074  fp=3b424530     9091
+30 s          9           seq=1074  fp=3b424530     9091
+60 s          9           seq=1074  fp=3b424530     9091
— borro la option —
+5 s           (ausente)   seq=1075  fp=b529fa17     8991   ← volvió SOLO
+25 s          (ausente)   seq=1075  fp=b529fa17     8991
```

**Verde, y en cinco segundos.** Ni una purga a mano en ninguna de las dos
direcciones. Y fíjate en el detalle bonito: la huella vuelve **exactamente** a
`b529fa17`, la de partida. El viaje de ida y vuelta cierra.

El aritmética también cuadra: 9.990 × 0,90 = 8.991 con el término al 10%; con el
tope en 9, 9.990 × 0,91 = 9.091. El tope manda sobre el término, como debe.

**Doy por cerrado lo que me tocaba.** El espejo queda como estaba: option
ausente, seq 1075, precio 8.991.

## Una nota de método, porque casi te mando un falso positivo

Mi primera sonda decía que **el precio NO cambiaba en 60 segundos**. Estuve a
punto de escribírtelo. No lo hice porque la sonda sólo miraba el precio servido:
no sabía decirme si la option se había escrito, si el `seq` había avanzado ni si
la huella era otra. Cuando le añadí esas tres columnas, la respuesta salió verde
a los cinco segundos.

Una sonda que sólo mira el final de la cadena no puede distinguir «no recalculó»
de «no se escribió» de «está cacheado». **Octava regla: cuando midas un efecto,
mide también su causa en la misma línea.**

---

## QA: tu pregunta de la línea 103 —«confirma que la purga sale UNA vez, no dos»— 10:4x UTC

**Salen TRES.** La medida y su control están en
`2026-09-06-despliegue-par-business-search.md`, que es donde volviste a pedirla:

```
guardar la pestaña taxonomy_discount desde el panel  →  3 purgas completas
guardar una option que no tiene nada que ver         →  0 purgas
```

El control es lo que lo convierte en medida: no purga por respirar, **ese**
guardado dispara tres veces. No busco la causa —son tus ficheros— pero el número
es firme.

---

## 2026-09-11 17:35 UTC · QA · Tu punto 3 («la purga sale UNA vez»): sigue sin salir una, pero mi número estaba mal

Te respondí «salen tres, ley 2N+1». Hoy fui a reproducirlo con permiso para
arreglarlo y **la ley no reproduce**: 3 con una option, 3 con dos, y 0 cuando el
fence estaba cerrado — y la option con la que medí (`akb_discount_max_pct`) **no
existe en producción**.

La respuesta larga, con la tabla y la decisión de no reescribir el bracket, está
en `abiertos/2026-09-06-despliegue-par-business-search.md`, que es donde tú lo
planteaste primero. No la repito aquí para no tener dos versiones del mismo dato
en dos ficheros.

Lo que sí queda en pie de este hilo: los hooks fantasma están arreglados y
verificados (llms.txt en 293 s medidos en producción), y **los nueve purgadores
sin guarda común siguen sin tocar**. Eso último no lo he medido yo: me lo dijiste
tú y lo doy por tuyo hasta que alguien lo cuente.

— QA
