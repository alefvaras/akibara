de: sesión cowork-banner [534f25c1] (QA)
para: cualquiera — POLÍTICA DEL DUEÑO, léela antes de tocar producción
estado: abierto
archivos: (ninguno — política)
prueba: node tools/akibara-releases/rewards-qa/qa-paridad-espejo.mjs

# Orden del dueño: se prueba TODO en local y se despliega. Lo que no puede pasar es un error en producción

**Textual, 2026-09-06: «Lo que me interesa es que no ocurran errores en prod, por
eso deben probar todo en local y desplegar en prod; no me importa que la página
se encuentre caída momentáneamente».**

Esto **cambia lo que veníamos haciendo**, sobre todo yo. Esta noche he provocado
seis guardados y un ensayo de vencimiento **directamente en producción**. Estaba
autorizado, pero la orden de ahora es más precisa: **la caída aceptable es la de
un despliegue, no la de una prueba.** A partir de aquí:

1. **Todo hallazgo mío llega con reproducción EN EL ESPEJO.** Si sólo lo tengo en
   producción, lo digo explícitamente y lo marco como no reproducido en local.
2. **Toda verificación mía posterior a un despliegue es de SÓLO LECTURA en
   producción**: medir precios, cabeceras, opciones, `seq`, HTTP. Nada de
   escribir en producción para «ver qué pasa».
3. **Escribir en producción sólo cuando el arreglo YA está verde en el espejo**,
   y con la medición preparada de antemano.

## Y aquí viene la parte incómoda: el espejo MIENTE, y hay que saber en qué

«Probar en local» sólo sirve si el local se parece. Medido hoy, no de memoria:

| | Espejo | Producción | Consecuencia |
| --- | --- | --- | --- |
| WP-Cron | activo, dispara solo | `DISABLE_WP_CRON=true`; empuja el cron del panel cada 5 min | **Los tiempos NO se transfieren.** Me equivoqué dos veces con la duración del rebuild por esto. |
| Cola de Action Scheduler | llegó a **1.470 pendientes** (1.405 `akb_search_pump_live` de mis pruebas) | **54** | Con la cola atascada, en el espejo «no ocurre» ≠ «está roto». Me pasó con Vitrina: 160 s sin correr y la acción llevaba 20 min esperando. |
| Borde | sin Cloudflare ni LiteSpeed real | Cloudflare + LiteSpeed | Cachés y purgas no se prueban en local. |
| Datos | 3 preventas SEMBRADAS por mí (12945, 12959, 13079) | **cero** preventas | El espejo prueba ramas que producción no recorre — y al revés. |
| Precios | materializados; cambiar la option por CLI NO los recalcula | igual, pero con el cron real detrás | Verificar por PRECIO en el espejo exige esperar la re-materialización. |

**Regla que sale de ahí:** antes de creerse un «no ocurre» del espejo, mirar la
cola. `wp db query "SELECT status, COUNT(*) FROM wp_actionscheduler_actions GROUP BY status;"`
Si hay cientos de pendientes, el espejo no está midiendo nada.

**Ya lo dejé calibrado:** borré los 1.421 pumps pendientes que había generado yo.
El espejo pasó de 1.470 a 53 pendientes — el mismo orden que producción (54).
Quien encuentre el espejo atascado, que lo limpie antes de medir.

## Lo que esto NO cambia

El permiso para tumbar producción sigue existiendo, pero para **desplegar**, no
para explorar. Y sigue en pie lo acordado: quien arregla no cierra; cierra QA
después de medirlo.

---

## LA REGLA CAMBIÓ — 01:00 UTC. Manda producción, y el dueño aceptó el número

**Textual: «Producción, quiero que pruebes igual, no importa que se caiga;
acepto que pueda estar más de 3 horas caída.»**

Le puse delante el costo real —que el atasco del buscador **no tiene rescate
soportado** y que esta mañana costó 3 h— y decidió con ese dato encima. Es su
tienda; se ejecuta. **Lo de arriba queda como histórico**: ya no se prueba en
local antes, se prueba en producción.

Lo que sigue siendo obligatorio, porque es oficio y no permiso:

1. **Punto de restauración antes de tocar.** El de esta noche:
   `.local-backups/prod-restore-20260906-0056/restore.txt` — options, estado
   editorial (seq 419, huella, `next_boundary_at`, `source_term_ids [230]`),
   el 10 % del término 230 y la acción 396388 con sus args exactos.
2. **`bash ops/rescate-buscador.sh produccion`**, que escribí con lo aprendido
   esta noche: soltar el estado, **quitar las tablas sombra** (si no, el run
   siguiente sale `orphan_topology_without_state`), limpiar los pumps y arrancar
   limpio. Es la manguera mientras llega tu extintor.
3. **Antes de cada prueba, mirar si toca la foto editorial.** Las options
   vigiladas mueven `seq` y huella, y con eso **la única acción que apaga la
   promo el lunes deja de coincidir**. Las que NO están vigiladas —envío gratis,
   carrito, anuncios, Arma tu Pack, textos— se pueden probar en producción sin
   tocar el buscador. Empiezo por esas.
4. **No se le miente al cliente.** Tumbar el sitio está autorizado; sembrar una
   fecha de lanzamiento falsa o un precio inventado en un producto real, no.
