de: sesión bis-avenida [d5a6502d] (desarrollo)
para: sesión cowork-banner [534f25c1] (QA)
estado: abierto
archivos: (ninguno reclamado todavía — se reclama al tomar cada borde)
prueba: se declara por borde; cada uno lleva el suyo

# Campaña de BORDES: el dueño quiere que iteremos tú y yo buscando errores en los límites

Encargo del dueño, textual: **«son errores iterar con qa pruebas de borde y de
todo tipo»**. Así que esto no es una ronda más de QA funcional: es ir a por los
casos límite, los que nadie prueba porque «no van a pasar», hasta que dejen de
salir cosas.

El reparto sigue siendo el de siempre: **tú mides y vuelves a medir, yo arreglo**.
Lo que cambia es que aquí **también propongo bordes yo**, porque conozco por dónde
el código se estrecha, y tú tienes que romperlos.

## Cómo trabajamos esto, para no repetir los errores de hoy

Hoy nos costaron cuatro falsos positivos entre los dos. Las reglas que salen de
ahí, y que valen para toda la campaña:

1. **Un control que no puede fallar no es un control.** Antes de dar por bueno un
   resultado, pregúntate si habría salido distinto con la hipótesis contraria. Mi
   prueba de la vigencia no discriminaba y concluí igual.
2. **Sospecha del instrumento antes que del sistema.** Seis de tus ocho falsos
   positivos eran el instrumento; los míos de hoy, los dos.
3. **Relee después de escribir.** En esta tienda una escritura de option de
   precio se puede rechazar en silencio. Si mides encima de una config que crees
   haber cambiado, mides otra cosa.
4. **Cuenta ocurrencias, no líneas.** `grep -c` cuenta líneas y el HTML va
   minificado o no según la caché: el mismo sitio sano da 416 o 3.
5. **Si el borde no se puede dar hoy, se siembra.** Los productos de preventa no
   existen en el catálogo real; por eso están en el espejo.

## Los bordes que propongo, por dominio

No están por orden de probabilidad, sino de **daño si fallan**.

### Dinero — lo primero, porque se cobra

- Descuento del **0%** en un término, y del **90%** (el tope duro). Y un término
  con el porcentaje puesto y la campaña apagada.
- Un producto con **rebaja manual** más descuento por editorial, con las cuatro
  políticas de `sale_policy`. La combinación que más me preocupa es
  `stack_on_sale` con el tope al 90.
- Precio **0** y precio **vacío**. Un tomo sin precio no debería entrar en ningún
  cálculo de descuento ni pintar un tachado absurdo.
- **Redondeo**: un precio que al aplicar el % dé decimales con
  `woocommerce_price_num_decimals` en 0. ¿Se redondea hacia arriba o hacia abajo,
  y coincide lo que pinta la ficha con lo que cobra el carrito? **Ese desajuste es
  el peor error posible de esta tienda**: enseñar un precio y cobrar otro.
- Puntos: canjear **exactamente** el mínimo, uno menos, y más puntos de los que
  se tienen. Y un canje que supere el total del pedido.

### Fechas — porque acabamos de quemarnos con una

- Ventana **desde > hasta**. El JS lo impide en el navegador; prueba por CLI.
- Ventana de **un solo día** (desde = hasta), y que la promo esté viva ese día
  completo, incluido el último segundo.
- `desde` y `hasta` **vacíos** (vigencia indefinida) con la campaña encendida.
- **29 de febrero** de un año no bisiesto: `2027-02-29` no existe. ¿Lo rechaza el
  validador o construye una fecha inventada?
- El día del cambio de horario, que ya sabemos que muerde: ventana que empieza el
  **2026-09-06**, cuya medianoche no existe en Chile.
- Y el simétrico de abril: ventana que termina el **2027-04-03**, cuando la
  medianoche siguiente ocurre **dos veces**.

### Stock y preventa — con tu semilla

- Preventa con fecha que **pasa mientras el pedido está en curso**.
- Preventa **sin fecha** (`tba`) y preventa con fecha **hoy**.
- Un pedido **mixto**: un tomo disponible y uno en preventa. ¿Qué le decimos al
  cliente sobre cuándo le llega?
- Producto que pasa de agotado a disponible **mientras está en el carrito**.

### Carrito y checkout

- Carrito con **un solo tomo** justo en el umbral del envío gratis, y un peso por
  debajo.
- Comuna sin despacho (las islas, que están fuera a propósito).
- Cupón + descuento por editorial con `stacks_with_coupons` en los dos estados.
- Cantidad **0** y cantidad negativa por POST.

### Buscador

- Consulta **vacía**, de un solo carácter, de 500 caracteres.
- Acentos y ñ: «pokemon» vs «pokémon», «nino» vs «niño».
- Una editorial que no existe. Y una serie que existe en **dos** editoriales
  (ojo: hoy ninguna lo está, así que este borde hay que sembrarlo).
- `orderby` con basura, que acabo de endurecer y quiero ver roto si se puede.

### Panel

- Guardar **en blanco** cada una de las 20 pestañas, y contar filas nuevas en
  `wp_options` antes y después. Debe ser cero.
- Un texto con **`%`** dentro (nos costó un fatal en /mi-cuenta) y con emoji, y
  con comillas tipográficas.
- Un texto de 10.000 caracteres en la barra de anuncios.
- Guardar dos pestañas **a la vez** desde dos sesiones.

## Lo que hago yo mientras

Monto la misma batería por el lado del código: casos límite en la suite, con
control de que cada uno cae en rojo sin el arreglo. Los bordes que encuentres tú
en vivo y los que encuentre yo en la suite se juntan aquí.

**Empieza por Dinero.** Si sólo da tiempo a un dominio, que sea ése: el redondeo
entre ficha y carrito es el único de la lista que le cobra de más a un cliente.

---

## PRIMERA TANDA MÍA — 40 bordes en la suite. Dos dominios cerrados, cero defectos, tres falsos positivos míos

Empecé por donde dije: **Dinero** y **Fechas**. Resultado honesto: **el código
aguanta los dos**. Lo que falló tres veces fue mi instrumento.

### Dinero — `BordesDeDineroTest`, 10 casos, todos verdes

| Borde | Qué fija |
| --- | --- |
| Término al **0%** | una decisión explícita de no descontar no acaba aplicando otro |
| Término al **90%** | el tope de la tienda recorta al término, no al revés |
| Precio **0** y precio **vacío** | ni negativo ni precio inventado |
| Descuento que cae en **medio peso** | redondeo estable y con el criterio de la tienda, no propio |
| Cualquier precio | el resultado **nunca** es más caro que el normal, ni negativo |
| Rebaja manual + `skip_if_on_sale` | no se pisa el trabajo del dueño |
| Rebaja manual + `greatest_wins` | gana el más barato **en los dos sentidos** |
| Encadenado sobre rebaja + término 90% | el tope duro sigue mandando sobre el resultado final |
| Agotado con «sólo disponibles» | no recibe descuento |

**El borde del redondeo, que era mi mayor sospecha, no se puede dar hoy**: medí
producción y hay **0 de 1.631 productos** con precio que no sea múltiplo de 10, y
`woocommerce_price_num_decimals` está en 0. Con el 10% actual todos los
resultados son exactos. La prueba queda para el día que se cambie el porcentaje.

Lo que sí encontré mirando el código, y **no es defecto pero conviene saberlo**:
`DiscountResolver::round()` toma los decimales de tres sitios según el camino —la
foto adoptada, el lector crudo, o `wc_get_price_decimals()`—. Los dos primeros
son sellados; el tercero lee por caché. Si algún día se cambian los decimales,
hay una ventana en la que el buscador y la ficha podrían redondear distinto. Está
comentado en el propio código («un default nacido de un cache contaminado cambia
el precio publicado»), así que ya lo sabían. Lo dejo apuntado, no lo toco.

### Fechas — `DateDomainParityTest`, 26 casos, todos coinciden

Este iba a por sangre: **los dos plugins validan las fechas con código
distinto**. Search construye con la zona del sitio y mira los avisos de PHP;
Business exige antes un patrón y construye sin zona. Una fecha que uno acepte y
el otro rechace descarta la foto entera y apaga el buscador — la misma familia
del fallo del boundary de esta noche.

Universo probado: 29 de febrero bisiesto **y no bisiesto**, 31 de un mes de 30,
mes 13, mes 00, día 00, el día del salto de primavera, el del salto de otoño,
año de cinco cifras, sin ceros a la izquierda, con hora pegada, con espacios,
barras, texto, año cero y 9999.

**Coinciden en los 26.** Y el fichero lleva su propio control: si el universo
dejara de tener casos de los dos signos, la paridad se cumpliría por vacío y la
prueba lo dice.

### Copys — `CopyNeverUsedAsFormatTest`, candado nuevo

Barre **todo `src/`** buscando `sprintf`/`printf` cuyo formato no sea un literal
nuestro. Es el defecto que ya costó un fatal en /mi-cuenta cuando un texto
editable traía un «%».

**Cero casos vivos.** Las cinco llamadas con formato no-literal que hay son
ternarios entre literales (singular/plural), que es legítimo.

**Control hecho de verdad**: inyecté `sprintf( get_option( 'akb_copy_x' ), 1 )`
en AnnouncementBar y el barrido lo cazó por fichero y línea. Fichero restaurado y
verificado.

### Los tres falsos positivos míos, porque valen más que los verdes

1. **El doble de producto no llevaba la bandera de oferta.** El precio rebajado y
   `is_on_sale()` son campos SEPARADOS en el doble, igual que en WooCommerce.
   Marqué sólo uno y me salieron dos «defectos» de la política de rebajas que no
   existían.
2. **El detector de formatos miraba sólo su propia línea.** El estilo del
   proyecto parte el argumento a la siguiente: 130 llamadas correctas marcadas
   como infractoras.
3. **Y luego no saltaba comentarios.** Una llamada más, en
   `OrderPreventa/ReadyToShipCron.php:329`, marcada porque el literal venía
   después de dos líneas de comentario dentro del `sprintf`.

Van tres veces esta noche que **el instrumento me da el defecto que no existe**.
Es exactamente lo que tú llevas todo el día diciendo. Lo asumo y lo escribo aquí
para que la campaña empiece con esa vara para los dos.

### Suite

2.796 tests en verde. 44 en la cruzada. PHPCS limpio.

### Lo siguiente que voy a atacar

Stock y preventa con tu semilla, y los bordes del panel con textos raros (emoji,
comillas tipográficas, 10.000 caracteres). **Los de carrito, checkout y buscador
en vivo son tuyos**: yo no puedo simular un cliente real mejor que tú.

---

## QA acepta, empieza por Dinero como pediste, y trae el primero — 02:50 UTC

Acepto el reparto y acepto el orden. Empecé por Dinero y **el primer borde ya dio
sangre**: `2026-09-06-la-rebaja-manual-sale-mas-cara-que-la-campana.md`.

Resumen en tres líneas para que decidas si sueltas lo que estés haciendo:

- **321 productos** de los 924 del término de campaña se sirven **por encima del
  precio de campaña**, porque tienen rebaja manual y `greatest_wins` no está
  ganando. Sobreprecio sumado **$229.904**; el peor, Dorohedoro, **+$2.109 por
  tomo**.
- El código de `greatest_wins` es `min( regular × factor, sale )`, o sea correcto.
  El sitio sirve el `sale`. Algo entre el resolver y lo servido no aplica la
  política.
- **Pista**: en akibara-search `sale_policy` sólo aparece como nombre de option
  vigilada para la huella, nunca como lógica.

## Tu borde estrella sale VERDE, y me alegro de poder decírtelo

«Enseñar un precio y cobrar otro» **no pasa**. Ficha contra carrito de invitado
por HTTP, tres productos, dos con descuento editorial y uno con rebaja manual:
los tres coinciden al peso. El precio es coherente de punta a punta; lo que falla
es cuál se elige.

Y el redondeo que te preocupaba **no tiene dónde morder hoy**: barrí los 870
productos comprables buscando alguno donde el 90 % del regular diera decimales
con `price_num_decimals = 0`. **Cero.** Todos los precios de lista son múltiplos
de 10 —10.100, 13.000, 25.990—, así que el 10 % siempre cae entero. El borde
existe en el código pero no en los datos: si quieres cazarlo hay que **sembrarlo**
(un producto a 10.105, por ejemplo), y eso pide espejo. Que hoy está muerto.

## Sobre tus cinco reglas: firmo las cinco, y añado una sexta

Las cinco son las nuestras y me las llevo escritas. La sexta la aprendí esta
noche, dos veces en dos horas:

> **6. Antes de creer que no hay dato, comprueba que estás leyendo bien la
> llave.** Leí el estado editorial con `get_option()` y me dio «NO HAY ESTADO»:
> se guarda como JSON y hay que decodificarlo. Y busqué el porcentaje de los
> términos con `akibara_discount_pct` cuando la llave es
> `_akb_taxonomy_discount_pct`. Las dos veces el sistema estaba sano y el
> instrumento ciego. Un «no hay nada» es una afirmación tan fuerte como
> cualquier otra y necesita su propio control.

Si hubiera reportado la primera, habría dicho que el vencimiento del domingo no
tiene quién lo dispare. Habrías salido corriendo a arreglar algo que funciona.

## Lo que hago a continuación, por si quieres redirigirme

1. Seguir en **Dinero** con lo que se puede medir sin escribir en producción:
   precio 0 y precio vacío dentro de la campaña, y el canje exactamente en el
   mínimo y uno por debajo.
2. **Fechas** y **Panel** de tu lista piden escribir configuración. En producción
   un sábado con la promo viva, no. En el espejo, tampoco: lleva quince minutos
   con el latido congelado. **Ese es el cuello de botella de toda la campaña de
   bordes**, y por eso te insisto con él.
3. Los tres correos y la caché del carrito que te dejé esta noche, cuando los
   arregles, los vuelvo a medir yo y cierro.

## Y una petición

Tengo seis ítems esperándote y ninguno los he priorizado por ti. El orden que yo
seguiría: **(1) la rebaja manual —es dinero en vivo—, (2) el espejo —bloquea todo
lo demás—, (3) el correo del ajuste manual —le miente a un cliente—, (4) la caché
del carrito, (5) el recorte silencioso del canje, (6) la pregunta del histórico
de puntos.** Si no estás de acuerdo, dilo y lo reordeno.

---

## Bordes medidos esta madrugada: Buscador y el resto de Dinero — 03:05 UTC

Herramienta: `tools/akibara-releases/rewards-qa/bordes-buscador.sh` (18 consultas,
sólo lee). La primera pasada me salió inútil —todo `302`, porque `?s=` redirige a
`/tienda/?_buscar=` y yo no seguía redirecciones—; con `-L` ya mide.

### Tu endurecimiento del `orderby` AGUANTA

Lo pediste explícitamente y no pude romperlo. Cuatro intentos, y los cuatro
devuelven una página **byte a byte idéntica al control** (186.561 B):

```
control        ?s=naruto                                    186561 B
orderby=;DROP                                               186561 B
orderby=price) UNION SELECT                                 186561 B
order=BASURA                                                186561 B
paged=-1                                                    186561 B
```

Los parámetros basura se ignoran enteros, no se cuelan ni cambian el orden.

### Acentos y ñ: bien

```
nino    211.656 B  con resultados
niño    211.675 B  con resultados      ← plegado correcto, encuentra lo mismo
pokemon 105.888 B  sin resultados
pokémon 105.904 B  sin resultados      ← coherentes entre sí
```

### Inyección y comodines: bien

- `"<script>alert(1)` llega al servidor como `_buscar=scriptalert(1)/script`:
  las etiquetas se caen, nada se refleja.
- `%` y `%_%` no se tratan como comodines SQL: cero resultados, cero error.
- 500 caracteres: 200 y sin resultados, en 0,69 s.
- Un solo carácter (`a`, `ñ`): 200 y sin resultados. Si querías que un carácter
  sí buscara, ese es un cambio de producto, no un fallo.

### Lo único que encontré, y es pequeño

`?s=` **vacío** no redirige a `/tienda/` como todo lo demás: se queda en la
búsqueda nativa de WordPress y pinta

```
<title>- Akibara</title>
schema: "SearchResultsPage" … name: "Resultados para"
```

Un título que empieza por un guion suelto y un dato estructurado con el nombre a
medias. **La vía del cliente está bien**: el formulario del sitio manda a
`/tienda/?_buscar=`, y vacío da «Catálogo de manga y cómics originales en Chile»
con el catálogo entero, que es lo correcto. O sea que esto sólo lo ve quien
llegue por `?s=` a pelo — o un buscador que lo indexe. Prioridad baja, pero es
metadato indexable.

### Falso positivo que cacé antes de contártelo

Mi sonda marcaba `warn=1` en las 18 respuestas, control incluido. No son avisos
de PHP: es el CSS `--wp--preset--color--warning`. **Tu regla 4 en acción** —
contar líneas con `grep -c` sobre HTML minificado no vale. Si te lo hubiera
mandado, te habría hecho buscar 18 warnings que no existen.

## El resto de Dinero, cerrado

**Redondeo: no hay dónde morder.** Barrí los 870 productos comprables buscando
uno donde el 90 % del regular diera decimales con `price_num_decimals = 0`.
**Cero.** Todos los precios de lista son múltiplos de 10. El borde vive en el
código, no en los datos: para cazarlo hay que sembrar un producto a 10.105, y eso
necesita espejo.

**Precio 0 y precio vacío: no existen.** Ni en los 924 del término de campaña ni
en el catálogo publicado entero. Cero productos sin precio de lista. Otro borde
sin datos que lo sostengan.

**Mínimo de canje: verde, y con el límite inclusivo bien puesto.** Otorgué
exactamente 100 puntos y probé el borde por los dos lados:

```
pido 99   →  no se aplica, total intacto, y lo dice:
             «No se aplicó: el mínimo para canjear es 100 puntos y pediste 99.»
pido 100  →  se aplica, total 59.300 → 59.200, exactamente $100
```

`>= mínimo`, no `> mínimo`. Sin off-by-one.

Y fíjate en el contraste, que refuerza lo del recorte silencioso: **cuando el
sistema RECHAZA, explica con número y todo; cuando RECORTA, calla.** Es la misma
pantalla.

## De paso, una confirmación en vivo de la pregunta del histórico

Al otorgar y quitar esos 100 puntos, `_akb_rewards_total_earned` pasó de 3.296 a
**3.396 y ahí se quedó**. El saldo volvió a 0, el histórico no. Es exactamente lo
que describí en `2026-09-06-deshacer-un-movimiento-de-puntos.md`, ahora medido dos
veces. Lo restauré a mano otra vez.

---

## Bordes de CARRITO, con sesión de invitado propia — 09:20 UTC

Los hice contra producción con un carrito de invitado mío, sin tocar el del dueño
ni el espejo (que estabas usando tú). Herramienta:
`qa-bordes-carrito-invitado.sh`.

```
añadir 1 (control)          201  items=1 total=9.090
añadir cantidad 0           400  «No puedes añadir al carrito «Death Note 12» con una
                                  cantidad menor o igual a 0.»
añadir cantidad NEGATIVA    400  el mismo mensaje
añadir 999 (stock 2)        400  «…porque no hay suficiente…»
añadir un AGOTADO           400  «No puedes añadir «Jujutsu Kaisen 21» al carrito porque
                                  el producto está agotado.»
id inexistente              400  «El producto con el ID «999999999» no se ha encontrado…»
cupón inexistente           400  «El cupón «noexiste-qa» no se puede aplicar porque no existe.»
estado final                200  items=1 total=9.090   ← ninguna basura entró
```

**Los seis rechazos son correctos, con su HTTP 400 y su frase en castellano**, y
—lo que más me importaba— **el carrito queda exactamente como estaba**: ni una
línea fantasma, ni una cantidad rara, ni un total movido. Tu borde «cantidad 0 y
negativa por POST» sale verde.

### Un detalle cosmético que sí me chirría

Cinco de los seis mensajes usan comillas angulares « ». El de stock insuficiente
llega con **entidades HTML sin decodificar**:

```
No puedes añadir esa cantidad de &quot;Death Note 12&quot; al carrito porque…
```

Si el carrito de bloques lo pinta como texto plano, el cliente ve
`&quot;Death Note 12&quot;` con las entidades a la vista. Es de WooCommerce core,
no nuestro, pero se ve en nuestra tienda. **No lo he comprobado en pantalla**
—habría que provocarlo con el carrito abierto— así que lo dejo como aviso, no
como hallazgo.

### Lo que queda de tu campaña, y de quién es

```
Dinero    ✔ rebaja manual (retirado: era mío), redondeo (no hay datos),
            precio 0/vacío (no existen), mínimo de canje, los dos topes
Buscador  ✔ inyección en orderby, acentos, ñ, comodines, 500 caracteres, vacía
Carrito   ✔ cantidad 0/negativa, sobre-stock, agotado, id falso, cupón falso
Fechas    ⏸ empezadas y PARADAS: te estaba pisando el espejo (ver
            2026-09-06-choque-en-el-espejo-aviso-urgente.md). Las repito cuando
            me digas que está libre.
Panel     ✔ «guardar en blanco no cambia nada» en las 14 pestañas;
            faltan los textos con %, emoji y 10.000 caracteres
Preventa  ✔ prueba de fila en espejo y producción, 4/4 y 5/5
```

Me quedan los textos raros del panel (`%`, emoji, comillas tipográficas, 10.000
caracteres). Esos sí puedo hacerlos sin tocar el descuento: van a `copys` y
`announcement`. Los hago ahora salvo que me digas otra cosa.

---

## Bordes de TEXTO del panel: los seis, verdes — 09:35 UTC

Sobre `copys` (no rocé ninguna option del descuento, que estás usando tú).
Herramienta: `qa-textos-raros.mjs`. Cada caso: guardar → recargar → releer →
**y pedir la página pública que usa ese texto**, para ver si revienta.

```
                        guardado           página pública
porcentaje suelto       idéntico           200 · sin fatal
printf mal formado      idéntico           200 · sin fatal     ← «%s y %d y %%»
emoji                   idéntico           200 · sin fatal     ← 🕐 🎌
comillas tipográficas   idéntico           200 · sin fatal     ← « » “ ” —
diez mil caracteres     recortado 10000→300   200 · sin fatal
html y script           recortado 46→14       200 · sin fatal · el <script> NO sale
restaurado              sí                 200 · sin fatal
```

**El del `%` era el que más me interesaba** —es el que nos costó un fatal en
/mi-cuenta— y ahora aguanta hasta un `printf` mal formado con `%s`, `%d` y `%%`
juntos. Ni fatal ni texto roto.

El de HTML es el más bonito: de `Horario <b>9 a 18</b><script>alert(1)</script>`
sobrevive `Horario 9 a 18`. Las etiquetas se caen enteras y **el `<script>` no
aparece en el HTML público**, ni escapado ni crudo.

### La única pega, y es la de siempre

Los dos recortes —el de 10.000 caracteres a 300 y el del HTML— **son silenciosos**.
El dueño pega un texto largo, guarda, y se encuentra con 300 caracteres sin que
nadie le haya dicho que sobraban 9.700. Es el mismo patrón que la clave de Google
Maps y que el canje antes de tu arreglo: **el sistema hace lo correcto y no lo
cuenta**.

Con el canje ya demostraste que decirlo cuesta poco y se agradece. Yo pondría la
misma frase aquí: «Se guardaron los primeros 300 caracteres; el resto se
descartó». Tú decides si vale el esfuerzo — no es un defecto, es una cortesía.

### Estado de tu campaña tras esto

```
Dinero    ✔     Buscador  ✔     Carrito  ✔     Preventa  ✔
Panel     ✔     (guardar en blanco · textos con %, emoji, comillas, 10k, HTML)
Fechas    ⏸     paradas por el choque en el espejo; las repito cuando esté libre
Stock     ✔     lista de espera de punta a punta, con sus dos correos
```

---

## Bordes de FECHAS — hechos, y SIN escribir ni una option — 09:55 UTC

Después de pisarte el espejo se me ocurrió lo que debí haber hecho desde el
principio: **la misma pregunta se responde sin tocar estado compartido**. El
cálculo real (`AdoptedSnapshot::compute_window()`) se puede invocar por
Reflection con opciones sintéticas y un reloj a elegir. Cero escrituras, y de
regalo puedo mover el reloj, que con options no podía.

Herramienta: `qa-bordes-fechas-sin-escribir.php` (`AKB_AHORA` fija el reloj).

### Con el reloj de hoy (2026-09-06)

```
caso                                activa   boundary
ventana normal (04→06)              sí       2026-09-07 03:00 UTC   ← medianoche Chile ✔
un solo día (06→06)                 sí       2026-09-07 03:00 UTC   ← vive el día entero ✔
ambas vacías (indefinida)           sí       null                   ✔
sólo desde (sin fin)                sí       null                   ✔
sólo hasta (sin inicio)             sí       2026-10-01 03:00 UTC   ✔
empieza el día del salto (06→20)    sí       2026-09-21 03:00 UTC   ← sin resaca del cambio ✔
APAGADO con ventana válida          no       null                   ✔
invertida (desde 10 → hasta 02)     no       2026-09-10 03:00 UTC   ← ver abajo
mes 13                              null     la foto se RECHAZA     ← ver abajo
texto que no es fecha               null     la foto se RECHAZA     ← ver abajo
```

### Con el reloj dentro de cada ventana (el control que faltaba)

```
reloj 2027-04-02      to = 2027-04-03  →  2027-04-04 04:00 UTC   ✔ medianoche con −04
reloj 2027-04-02      to = 2027-04-04  →  2027-04-05 04:00 UTC   ✔
reloj 2027-02-15      to = 2027-02-29  →  2027-03-02 03:00 UTC   ← ver abajo
```

**La transición de otoño sale limpia**, como dijiste: en abril los dos métodos
coinciden y el boundary cae en la medianoche real con el offset correcto. Tu
arreglo del salto de primavera no rompió el otro lado.

## Tres cosas que sí merecen tu opinión

**1. El 29 de febrero de un año no bisiesto NO se rechaza: se desborda.**
Con `to = 2027-02-29`, PHP lo resuelve como **2027-03-01** y la ventana termina a
la medianoche del **2 de marzo**. O sea: el dueño escribe «29 de febrero» y la
promo **dura un día más** de lo que él cree. No revienta nada, pero tampoco avisa.
Alcance: el panel usa `type=date`, así que por la interfaz no se puede escribir;
se llega por CLI o por una importación.

**2. Una fecha basura tumba la foto entera.** `2026-13-01` o `«ayer»` hacen que
`compute_window()` devuelva `null`, y eso —según tu propio comentario— rechaza la
foto y **apaga buscador y facetas**. Es un modo de fallo duro para un dato que
sólo se valida en el navegador. No digo que haya que arreglarlo; digo que
conviene saber que existe y decidir si la validación vive también en el servidor.

**3. Una ventana invertida programa un boundary que no sirve para nada.**
Con `desde 10 → hasta 02` la ventana nunca se activa, pero se programa un
boundary en la fecha de inicio. Cuando dispare, republicará y encontrará que
sigue inactiva. Inofensivo, pero es una acción de más en la cola cada vez.

## Y me apunto la lección de método

**Cuando lo que quiero medir es un cálculo, no hace falta mover el estado del
mundo.** Escribí options compartidas para preguntarle a una función qué devuelve,
y por el camino te pisé el experimento. La Reflection responde lo mismo, no
molesta a nadie, y encima permite mover el reloj — que era justo lo que me
faltaba para discriminar los casos de abril. **Novena regla: antes de escribir
estado para medir algo, comprueba si puedes preguntárselo al código directamente.**

Con esto **Fechas queda cerrado** y tu campaña completa por mi lado, salvo lo que
me devuelvas.

---

## La «mirada de un segundo» que faltaba, hecha. NO es defecto — 10:2x UTC

Dejé dos avisos marcados como **«señalado sin confirmar»** porque no quise montar
un carrito en el navegador del dueño (sesión de administrador con datos de un
tercero). Los dos están resueltos, y ninguno es un defecto.

### 1. Las entidades `&quot;` del aviso de stock: el cliente NO las ve

Monté el escenario en el espejo y lo **rendericé de verdad** con Playwright sobre
un Chrome con perfil nuevo —sin tocar ninguna sesión del dueño—: carrito de
invitado con el tomo dentro, luego lo dejé sin stock, luego recargué.

Lo que la API devuelve en el JSON:

```
No puedes añadir esa cantidad de &quot;…&quot; al carrito…
```

Lo que el cliente **lee en pantalla**:

```
«Los diarios de la boticaria 04» no tiene existencias y no se puede comprar.
Por favor, elimínalo de tu carrito.
```

**Comillas angulares, ninguna entidad a la vista.** El carrito de bloques
decodifica antes de pintar (`decodeEntities`, 20 usos en el bundle). Mi aviso era
prudente pero infundado: la entidad vive en el transporte, no en la pantalla.

### 2. El `&#8211;` en el nombre de la tarifa: tampoco está

Medido en producción, carrito de invitado con dirección de Santiago:

```
'Blue Express Despacho a domicilio'   limpio
'Retiro en Metro San Miguel'          limpio
paquete: 'Envío'                      limpio
```

Y barrí siete páginas de producción buscando entidades **dobles** escapadas
(las únicas que el cliente leería literales) en texto visible y en `alt`/`title`:

```
portada · catálogo · a pedido · carrito · ficha · mi cuenta · puntos
→ las siete limpias
```

**Retiro los dos avisos.** No hay entidades visibles en ninguna superficie.

### De paso, la captura enseñó cosas que sí funcionan

Ya que tenía el carrito pintado delante:

- **`Total estimado $12.500 · Incluyendo $1.996 IVA`** — el IVA desglosado y correcto.
- **La línea del tomo dice «Estado: Agotado»**, así que el cliente entiende por qué
  el aviso rojo está ahí. No es un error suelto sin contexto.
- **La barra de envío gratis calcula bien**: «Agrega $47.500 más para envío gratis»
  sobre un carrito de $12.500 con umbral en $60.000. La aritmética es exacta.
- El bloque de «Crear una cuenta» promete «500 Puntos Akibara **se activan con
  esta compra**» y «2 puntos por cada $100» — las dos cifras coinciden con lo que
  el sistema paga de verdad, ya lo verifiqué midiendo.

Eso rebaja aún más la prioridad de `umbral-envio-gratis-no-hace-nada`: **al
cliente se le dice el número correcto y se le calcula bien lo que le falta.** Lo
que confunde es el campo del panel, no la tienda.
