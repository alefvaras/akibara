---
para: EL DUEÑO (él preguntó) · dev para el contexto
de: QA (cowork-banner)
fecha: 2026-09-06
estado: ABIERTO · decisión de precios. NO es un defecto del código
módulo: precio editorial + preventa
---

# «Creo que esos precios los puse a mano, revisa» — tenías razón

Lo confirmé en la base de datos de producción. Y de paso **corrige lo que yo
mismo escribí antes**, así que lo dejo bien explicado.

## Lo que hay en la base

```
Black Clover 02 (a pedido)   _regular_price 10100   _sale_price 9600   ← puesto a mano
Black Clover 03 (disponible) _regular_price 10100   _sale_price NULL   ← sin oferta guardada
```

El `9.090` que se ve en el 03 **no está guardado en ninguna parte**: lo calcula la
campaña editorial al vuelo (Ivrea Argentina, `-10%`, término 230). El `9.600` del
02 **sí está escrito a mano** en el producto.

O sea: no eran «dos reglas automáticas cruzándose», como yo había dicho. Era
**un precio escrito a mano contra una campaña automática**. Tu corazonada era la
correcta y mi explicación estaba a medias.

## Y ahora la parte importante: por qué el disponible no tiene ese problema

Para lo que está **en stock**, la campaña y el precio a mano compiten y **gana el
más barato** (`greatest_wins`). Lo comprobé en el carrito real:

```
Black Clover 11 · en stock · a mano 9.600 · campaña 9.090
API del carrito → el cliente paga 9.090   ✔ gana la campaña
```

Para lo que está **a pedido**, la campaña **no se aplica** (hay una compuerta de
elegibilidad por stock), así que el cliente paga el precio a mano tal cual.

**Ese es el mecanismo completo.** Encargar sale más caro no porque nada falle,
sino porque la campaña no alcanza a lo que está a pedido y los precios a mano son
menos generosos que ella.

## El tamaño real del asunto

De los productos Ivrea Argentina con precio de oferta escrito a mano:

```
con precio a mano                                    327
  el precio a mano es MEJOR que la campaña             3
  el precio a mano es PEOR (menos descuento)         324
      de esos:  a pedido 321  ·  en stock 3
  sobreprecio medio frente a la campaña            719 por tomo
```

Los tres «en stock» **no son un problema real**: ahí gana la campaña al vuelo, ya
lo verifiqué. Los **321 a pedido** sí pagan el precio a mano.

Los peores casos:

```
Dorohedoro 01-12   lista 25.990   a mano 25.500   campaña 23.391   (+2.109)
Rurouni Kenshin Kanzenban 04  lista 24.000  a mano 23.500  campaña 21.600  (+1.900)
```

## Nota sobre mi propia retractación anterior

En su día levanté «321 productos con sobreprecio», dev me corrigió porque yo
había filtrado con `is_in_stock()` —que devuelve verdadero para `onbackorder`— y
lo retiré. **Dev tenía razón en lo que dijo**: no eran productos en stock
sobrevalorados, y como defecto no existía.

Lo que no se dijo entonces, y ahora sí está medido, es que **esos mismos 321 son
tomos a pedido cuyo precio a mano es menos generoso que la campaña vigente**. No
es un fallo del código; es una decisión de precios que hoy nadie ha tomado a
propósito.

## Lo que puedes hacer, con números

1. **Que la campaña alcance también a lo que está a pedido.** Un cliente que
   reserva Dorohedoro pagaría 23.391 en vez de 25.500.
2. **Subir el descuento de los precios a mano** hasta al menos igualar la campaña.
   Es tocar 321 productos, pero se puede hacer de una vez.
3. **Dejarlo.** Defendible: reservar y comprar disponible no son lo mismo, y el
   margen de la preventa es más ajustado.

**No lo toco yo.** Son tus precios. Dime cuál y lo verifico después.

---

## EL CASO CONCRETO, con nombre y cifras: Rurouni Kenshin Kanzenban — 12:1x UTC

El dueño escribió «creo que esos precios los puse a mano, revisa». Aquí está el
ejemplo exacto, en una sola serie, medido en producción.

```
tomo   estado      precio normal   _sale_price   lo que ve el cliente
 13    con stock      24.000        (ninguno)        21.600     ← campaña, 10%
 12    con stock      24.000        (ninguno)        21.600
 11    con stock      24.000        (ninguno)        21.600
 10    con stock      24.000        (ninguno)        21.600
 09    a pedido       24.000         23.500          23.500     ← precio a mano
 08    a pedido       24.000         23.500          23.500
 07    a pedido       24.000         23.500          23.500
 06    a pedido       24.000         23.500          23.500
```

**Todo funciona como está configurado.** La campaña entra sólo en los que tienen
stock —que es lo que promete el cartel— y los de «a pedido» conservan el precio
que el dueño puso a mano. Ningún defecto.

**Lo que sí conviene que vea el dueño**, porque es decisión suya y no del código:

**1. Hoy, en la misma serie, esperar sale $1.900 más caro.** Quien complete la
colección paga 21.600 por los tomos que salen hoy y 23.500 por los que tardan
semanas.

**2. Y el fin de semana lo invierte.** Los 23.500 son un `_sale_price` **sin
fecha de fin**:

```
_sale_price_dates_from  —      _sale_price_dates_to  —
```

Cuando acabe la campaña, los de stock vuelven a **24.000** y los de a pedido se
quedan en **23.500**. Es decir: **el lunes los «a pedido» pasan a ser los
baratos**, sin que nadie toque nada.

No digo que esté mal —puede ser justo lo que quiere—, digo que la relación entre
los dos precios **cambia sola al terminar la campaña**, y eso conviene decidirlo
a propósito y no descubrirlo el lunes.

---

## 2026-09-12 · dev (bis-avenida) · Reglas nuevas del dueño aplicadas en producción; queda una pregunta sobre el tramo de Ivrea Argentina

**Reglas del dueño (12-sep):** a pedido y preventa = 5 % bajo la lista; y en
todos los productos, 3 % por debajo del competidor más barato del mismo ISBN,
sin promociones («no podemos estar bajo 17 %»).

**Aplicado (todo reversible por metas):**
- Reserva al 5 %: 148 a pedido (`_akb_reserva5_prev_sale`), más NieR 04 a mano y
  10 disponibles que arrastraban la rebaja, devueltos a su lista.
- Competencia por ISBN: catálogo entero de kodoku.cl (2.812 ISBN, su web lo
  permite; precio normal, no el de oferta) + 13 ISBN de damemimanga por
  boletín. Cruce: 218 de nuestros 1.643 ISBN tienen dato. **187 fichas
  cambiadas** (`_akb_comp3_prev_regular/_prev_sale/_fuente`): 155 suben (media
  +$1.591, máx +$4.900: Arechi 23, Milky Way 54, Panini España 23, Planeta 45,
  Ivrea España 7, Ivrea Argentina 3) y 32 bajan poco (One Punch-Man 12.990 →
  12.600, Atelier Grimorio 39.000 → 38.900…). A pedido: reserva recalculada al
  5 % sobre la lista nueva. Índice del buscador reconstruido (40 s, 200 todo el
  tiempo), caché purgada, sitemap invalidado, fichas verificadas.
- **Saltado a propósito:** Hajime no Ippo 01-13 (Planeta España), $23.000 →
  $20.200 según kodoku ($20.900): una baja del 12 % sin dato de costo para Planeta
  España; el piso de margen de la herramienta no se puede calcular. Lo decide el
  dueño.

**Pendiente del dueño:** el tramo de Ivrea Argentina está en **$10.100 puesto a
mano por él el 4-sep** (después del $10.600 del barrido); damemimanga sigue en
$10.990, así que la regla del 3 % lo pondría en $10.600 (627 fichas). No se toca
sin su palabra. Tampoco Maximum Berserk (lista mezclada 24.700/24.000) ni
Vinland Saga 27-28 ($17.490 contra $16.500), preguntados.

— dev

**12-sep, más tarde:** el dueño decidió Hajime no Ippo 01-13 a **$22.000** de lista
(aplicado; 09, 10 y 11 a pedido con reserva $20.900; precio anterior guardado en
`_akb_comp3_prev_*`). Índice reconstruido, buscador en 200.

## 2026-09-12 07:40 UTC · dev (bis-avenida) · la campaña se apaga sola desde business 1.116.5

Orden del dueño (12-sep): «se debería haber apagado cuando terminó la fecha».
Cron diario `akb_taxonomy_discount_expiry_sweep`
(`TaxonomyDiscount\CampaignExpiryCron`): al día siguiente del «hasta», con el
interruptor en «sí», escribe `enabled = no`, lo registra en WooCommerce →
Registros (`akibara-descuentos`) y la tarjeta de estado dice «Se apagó sola el
X: su vigencia terminó el Y» mientras siga apagada. Si la publicación del
buscador rechaza la escritura, queda registrado y reintenta mañana. Agendado en
producción (próxima corrida en ~24 h). Hoy no tiene nada que apagar: el
interruptor ya está en «no» (apagado a mano el 12-sep) con «hasta» 2026-09-06.
