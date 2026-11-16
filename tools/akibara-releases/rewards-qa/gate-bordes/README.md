# Gate de BORDES — resiliencia (qué se rompe cuando un tercero se rompe)

Sondas **re-ejecutables** y **auto-restauradoras**. Cada una sale con código
`!= 0` ante cualquier falla.

## Cómo correrlas

```sh
cd tools/akibara-releases/rewards-qa
node gate-bordes/run-all.mjs          # todas, EN SERIE
node gate-bordes/01-vigia-bluex.mjs   # una sola
```

**EN SERIE SIEMPRE.** Hay un solo espejo (`http://localhost:8080`); dos sondas a
la vez se pisan.

## Qué mide cada una

| Sonda | Borde | Restaura |
| --- | --- | --- |
| `01-vigia-bluex.mjs` | Actualización de tercero con include roto: alarma + rearme exprés en el cuerpo, buena noticia UNA vez, tercera corrida muda. | options `akb_bluex_watch_*`, fichero sonda del tercero, correos en Mailpit |
| `02-token-resena-reusado.mjs` | `/resena/{token}` reusado: cero segunda reseña, `used_at` intacto. | reseña creada, fila de `wp_akb_rewards_review_forms`, aviso de moderación |
| `03-baja-serie-reusada.mjs` | Baja de serie: GET no muta, POST sí, 2.º POST no duplica, token corrupto = página neutra idéntica y cero mutación. | usermeta `_akb_sf_*` del usuario 229 |
| `04-action-scheduler-caido.mjs` | Con AS agenda de verdad; sin AS el scan cae al fallback de `shutdown` y el digest degrada sin fatal. | acción sembrada en `wp_actionscheduler_actions` |
| `05-aislamiento-credenciales.mjs` | Solo LECTURA: Cloudflare apagado, webhooks sin activos, pasarelas sin credenciales de producción. | nada que restaurar |

## Reglas que respetan

- **Nunca** guardan ni editan términos de taxonomía (eso dispara el rebuild del
  buscador y deja `suggest` en 503 unos seis minutos). Solo leen términos que ya
  existen.
- **Nunca** editan ficheros del tercero: la sonda de Blue **añade** un fichero
  propio y lo borra (también en el `finally`).
- Miden la **superficie servida** (HTML real por `curl`), no el código.
- Los helpers PHP se escriben en `public_html/qa-gate-bordes/` porque es el
  único monte visible dentro del contenedor, **nacen con un candado**
  (`if ( ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); die(); }`) y se
  borran al terminar — en el `finally` y en `process.on('exit')`. Ninguna sonda
  deja un fichero servido en el docroot: las que tocan al cliente 229 lo
  comprueban con el check final `docroot-sin-helpers-del-gate`.
- El estado del cliente 229 **no se guarda en ningún fichero**: viaja en memoria
  del proceso Node (`foto-229.mjs`).

## Censo de pasarelas (05)

`05` enumera TODAS las pasarelas registradas
(`WC()->payment_gateways()->payment_gateways()`), no una lista escrita a mano, y
para cada una habilitada exige que no resuelva a credenciales de producción.
Flow (`woocommerce_flowpayment_settings`, con su `mode` PROD/SANDBOX) y
MercadoPago se comprueban además **por su nombre**. Barre también las options
`woocommerce_*_settings` armadas que no correspondan a ninguna pasarela ni
método de envío registrado: la pasarela dormida con credenciales vivas.
