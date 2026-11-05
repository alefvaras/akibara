
## Cierre de la jornada — 10:1x UTC · producción sana, y dos reglas nuevas

| ítem | quién | qué |
|---|---|---|
| Comprobación de salud tras el 4.º despliegue | — | 10 páginas en 200, buscador con el tomo 1 primero, facetas 1.131+102+398 = 1.631 exacto, 404/sitemap/llms/robots correctos. **0 anomalías.** Detalle en `lo-desplegado-vs-lo-probado.md`. |
| `tools/…/rewards-qa/qa-salud-produccion.sh` | quien despliegue | **Instrumento nuevo y reutilizable.** Solo lectura, sin sesión. Sale 0 si todo va bien, 1 si algo chirría. Córrelo después de cada despliegue. |
| La cadena del resumen | **dev** | Es de **Action Scheduler**, no de WP-Cron. El fatal **no era determinista** (hoy: 09:12 bien, 09:20 muerta, 09:29 bien). Tu `MailerBoot` entró a las 09:55, **después** de la última corrida: está verificado en banco pero **ninguna corrida real lo ha ejercido**. La primera, mañana 09:29 UTC. |
| Fecha del aviso a los dos clientes | **DUEÑO** | **Corregida: 13 de septiembre, 06:29 de Chile** — no el 12 que le dije. El tope semanal cuenta desde `_akb_sf_last_sent` (5-sep 18:41 UTC), no desde la fila. |
| `akb_search_editorial_discount_boundary` | **dev** | 30 filas `pending`, catorce del mismo instante. Munición para tu ficha del boundary duplicado. |

### Regla 22: una sola medición de un tiempo no es una medición

Dos veces hoy estuve a punto de reportar «regresión en `/carrito`» (1,75 s y
2,27 s) y las dos veces era caché fría tras el despliegue: al muestrear cinco
veces daba 0,35-0,63 s, y en una ronda el pico **se mudó a `/checkout`**. El pico
es del primer golpe, no de la página. `qa-salud-produccion.sh` ya nace con esto
dentro: mide tres veces e informa la mediana.

### Regla 23: si mides con caché delante, mide contra el origen a propósito

La primera versión de ese guion daba 0,08 s en la portada y yo casi lo celebro.
No era velocidad: los golpes 2.º y 3.º iban a la **misma** URL y los servía
Cloudflare. La mediana medía la CDN, no el sitio. Con un anticaché **distinto por
golpe** salen 0,49-0,97 s, que es lo real. Un número bueno que no puede empeorar
nunca no es una medida, es un adorno.

### Lo que NO hice, y por qué

Forzar una corrida del resumen para ver el arreglo del mailer en vivo. Con la
cola como está (dos filas `pending` de clientes reales), forzarla dispara correo
de verdad seis días antes de tiempo. **Prefiero esperar a mañana a las 09:29 que
mandar un correo que nadie autorizó.**
