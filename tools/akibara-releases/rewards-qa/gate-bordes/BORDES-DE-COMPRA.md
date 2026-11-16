# Sondas de BORDES DE COMPRA

Seis sondas re-ejecutables sobre el espejo Docker (`http://localhost:8080`).
Cubren los casos que el camino feliz nunca toca: comuna hostil, RUT inválido,
doble click en pagar, carrito mixto, canje de puntos al límite y el aviso
«¿No estás en Santiago?».

```bash
# Todas, EN SERIE (nunca en paralelo: el espejo es uno solo)
bash tools/akibara-releases/rewards-qa/gate-bordes/correr-bordes.sh

# Una sola (cwd = tools/akibara-releases/rewards-qa/, donde vive node_modules)
cd tools/akibara-releases/rewards-qa && node gate-bordes/sonda-bordes-rut.mjs
```

Cada sonda sale con código `!= 0` si algún check falla y termina comprobando
**antes = después** de todo lo que sembró.

| Sonda | Caso | Checks |
| --- | --- | --- |
| `sonda-bordes-comuna.mjs` | Comuna hostil (NBSP, minúsculas, «Renaca», inexistente) | 24 |
| `sonda-bordes-rut.mjs` | RUT con/sin puntos y con DV malo | 21 |
| `sonda-bordes-doble-click.mjs` | Doble click en «Realizar el pedido» | 15 |
| `sonda-bordes-carrito-mixto.mjs` | Disponible + A pedido en el mismo carrito | 21 |
| `sonda-bordes-canje-limite.mjs` | Canje bajo mínimo / exacto / idempotencia / reversa | 25 |
| `sonda-bordes-aviso-santiago.mjs` | Aviso de despacho en /carrito/ | 20 |

## ✅ CERRADO (2026-09-01): el canje bajo el mínimo ya avisa

Las seis sondas salen **en verde**. `sonda-bordes-canje-limite.mjs` pasa sus
**25/25** checks, incluido `bajo-minimo-avisa-al-cliente-en-el-momento`.

Qué fallaba: con saldo 500 y mínimo 100, escribir **50** y pulsar «Canjear»
hacía lo correcto en el servidor —`handle_update()` veía `BELOW_MIN`, no tocaba
la intención (`intent` en 0, total en `$15.500`)— pero el cliente no se enteraba
de nada. Medido entonces en el checkout servido: borde `rgb(88,88,88)` →
`rgb(88,88,88)`, fondo `rgb(17,17,17)` → `rgb(17,17,17)`, outline sin cambios y
la caja sin ganar un solo texto (su HTML pasaba de 607 a 597 bytes, que era el
botón volviendo de «Aplicando…» a «Canjear»). El
`input.validationMessage` sí decía «Value must be greater than or equal to
100.», pero nunca se dibujaba: el botón es `type="button"`, no un submit, así
que el navegador no muestra su globo.

El arreglo fue el natural: la caja publica su propio mensaje —«No se aplicó:
el mínimo…»— cuando `clamp_requested()` devuelve `BELOW_MIN`. Verificado por la
sonda contra el checkout servido, no contra el código.

> La copia estática de encima («El canje parte desde 100.») sigue ahí; ya no es
> lo único que avisa.

## Datos que usan

- **17083** Parasyte 04 — instock, stock gestionado (4), $15.500.
- **13629** Dan Da Dan 11 — `onbackorder` («A pedido»), sin gestión de stock.
- **229** cliente real (`miaucraaft@gmail.com`) para el canje, con swap de
  contraseña reversible.

Ningún término de taxonomía se crea ni se guarda (dispara el rebuild del
buscador). Sólo se usan productos y términos existentes.

## Restauración

`foto-229.mjs` guarda y repone el estado del cliente 229. Se usa desde las
sondas, no a mano:

```js
import { snapshot, residuosEnDocroot } from './foto-229.mjs';

snapshot( 'verificar' );  // «no existe la foto…» mientras no se haya guardado
snapshot( 'guardar' );    // GUARDADO — la foto queda EN MEMORIA del proceso
snapshot( 'restaurar' );  // RESTAURADO + IDENTICO/DIFERENTE
```

Guarda el hash de la contraseña, **todas** las metas del usuario (clave y
valor) y los asientos del ledger; `restaurar` repone el hash por `UPDATE`
directo (nunca `wp_set_password()`, que re-hashea y jamás daría el mismo
valor), borra los asientos sembrados y devuelve cada meta a su valor exacto.

> **Por qué ya no hay ficheros en el docroot.** La versión anterior daba por
> hecho un `public_html/qa-bordes-snapshot.php` escrito a mano, con su estado en
> `public_html/.qa-bordes-229.json`. Los dos quedaron **servidos por HTTP** con
> el hash de contraseña y todas las usermeta de una persona real (lo mismo con
> `qa-bordes-reparar-229.php`). Ahora el helper PHP nace con un candado
> (`if ( ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); die(); }`), vive
> lo que dura una llamada a `wp eval-file`, se borra en el `finally` y también
> en `process.on('exit')` — y las sondas cierran con el check
> `docroot-sin-helpers-del-gate`, que exige la ausencia de los tres nombres.

Cada sonda restaura además, por su cuenta:

- **Pedidos y borradores** creados por encima del id de referencia
  (papelera + borrado forzado + barrido de las tablas HPOS).
- **Stock**: un pedido que prospera lo descuenta y borrar el pedido *no* lo
  devuelve.
- **Filas `akb_pudo_state_*`**: cada sesión de checkout deja una.

## Cinco trampas que costaron una corrida cada una

1. **El borrador de checkout falsea el conteo en las dos direcciones.** Woo
   crea un `wc-checkout-draft` en cuanto entra el POST y lo conserva aunque la
   validación lo rechace; cuando el pago prospera, ASCIENDE esa misma fila.
   Contar `wp_wc_orders` entero daba «+1» en un rechazo correcto y «+0» en un
   pedido real. Se cuentan sólo los NO borrador, y HPOS guarda el estado con
   prefijo (`wc-checkout-draft`, no `checkout-draft`).

2. **Sin elegir tarifa de envío, el Store API rechaza por envío, no por lo que
   se está probando.** `prepararEnvio()` fija la dirección y selecciona una
   tarifa antes del POST; sin eso, «Renaca» se rechazaba con
   `woocommerce_rest_invalid_shipping_option` y el check pasaba por el motivo
   equivocado.

3. **`aria-describedby` no es el ancla del error de campo.** En el checkout
   servido apunta al aviso «estás comprando como invitado», el mismo para el
   email y para el RUT. El error de campo cuelga de `aria-errormessage` y de
   `.wc-block-components-validation-error`.

4. **El mismo aviso de plazos tiene dos clases.** Carrito:
   `.akb-order-preventa-blocks-notice`. Checkout: `.akb-checkout-preventa`.

5. **Con la dirección ya completa, Blocks pliega el formulario.**
   `#billing-city` sigue en el DOM pero oculto: como ancla de espera hay que
   usar el botón de pago.

## Qué NO es medible en el espejo

- **Tarifas de despacho reales.** Ningún método Blue Express cotiza sin su API
  y el `free_shipping` de la zona RM está deshabilitado: la única tarifa viva
  es `local_pickup:70` (Región Metropolitana). Valparaíso devuelve **0**
  tarifas, así que un checkout con dirección de Valparaíso muere en «necesita
  una opción de envío» antes de llegar a la validación de comuna. Por eso el
  caso «Renaca» se cierra en la UI (mensaje servido) y contra
  `ComunaDirectory::suggest()`, no contra el Store API.
- **MercadoPago y Flow.** Sin credenciales; todo el gate paga con `bacs`.
