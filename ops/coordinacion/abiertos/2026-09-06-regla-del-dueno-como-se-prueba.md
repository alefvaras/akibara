de: sesión cowork-banner [534f25c1] (QA)
para: cualquiera — REGLA VINCULANTE, guardada en AGENTS.md
estado: abierto
archivos: AGENTS.md CLAUDE.md
prueba: (no aplica — es protocolo)

# Orden del dueño guardada donde no se pierde: AGENTS.md § «Cómo se prueba y quién da el visto bueno»

El dueño pidió expresamente que esto **no se pierda**, así que no vive en un
asunto del tablero —que se cierra— sino en **`AGENTS.md`**, que es su fichero de
reglas vinculantes y lo lee toda sesión nueva. `CLAUDE.md` lo referencia en su
punto 1.

Sus palabras, que son las que mandan:

> «Quiero que pienses que eres el cliente y a la vez el dueño. Cada checkbox,
> cada guardado, cada pequeño detalle lo debes probar y validar que funciona. Si
> no, lo pasas al agente dev, él lo resuelve, lo vuelves a revisar tú y das el
> visto bueno para producción. Por eso debes revisar el archivo donde se
> coordinan.»

## Lo que cambia para ti, en concreto

1. **El visto bueno para producción lo da QA**, no quien escribió el arreglo. Ya
   lo veníamos haciendo por acuerdo entre nosotros; ahora es orden del dueño y
   está escrita.
2. `esperando-respuesta` **nunca** pasa a resuelto por su autor. QA lo re-mide y
   lo cierra.
3. La cobertura que se espera de QA sube: **cada checkbox y cada guardado**, con
   las dos miradas —configurar como dueño, comprobar como cliente— y el
   interruptor **encendido y apagado**.

## Y arreglé una contradicción que había quedado viva

`AGENTS.md` decía «probarlos y validarlos primero en el entorno Docker local».
Eso ya no rige: el dueño lo sustituyó el 2026-09-06 por **probar en producción**,
aceptando por escrito más de 3 h de caída. Dejé la línea vieja tachada con la
fecha y el porqué, en vez de borrarla, para que quien la recuerde vea que
cambió y no crea que se perdió.

Las obligaciones que esa autorización **no** exime están en el mismo sitio: punto
de restauración, `ops/rescate-buscador.sh`, devolver todo a su estado original
**contando** contra el punto de restauración, y no mentirle al cliente.

---

## VERSIÓN FINAL de la regla — 01:45 UTC. Son LAS DOS, en orden

> *«Probar no solo en producción. Mira: primero probar en local, y después,
> cuando esté el cambio en producción, también probar allá.»*

No es local **o** producción: **es local Y DESPUÉS producción**. Sustituye a las
dos versiones anteriores de esta misma madrugada y está en `AGENTS.md` y en el
punto 0 de `CLAUDE.md`.

1. **Espejo primero.** Ahí se descubre y se deja en verde, donde un error no
   cuesta ventas y los casos de borde se repiten barato.
2. **Producción después de desplegar.** Verde en local **no basta**: el espejo
   miente en cosas que ya medimos —cron cada 5 min contra WP-Cron, Cloudflare,
   LiteSpeed, datos reales, cola de Action Scheduler—.
   **Hasta que no está medido en producción, no está aprobado.**

La caída que el dueño autoriza es el **techo del riesgo**, no el método: primero
se agota el espejo.

**Y antes de fiarte del espejo, comprueba que puede medir:**
`node tools/akibara-releases/rewards-qa/qa-paridad-espejo.mjs`. Con
`AKB_ALINEAR=si` lo deja con la configuración de producción. El 2026-09-06 el
espejo tenía la cola en 1.470 pendientes y el umbral de envío gratis en 55.000
cuando producción tiene 60.000: cualquier prueba de esas dos cosas allí era
falsa.

## Y de paso: la cadena de Puntos y Reseñas queda VERIFICADA ENTERA en producción

El dueño escribió la reseña con foto y yo la aprobé:

```
reseña 1876 · producto 27914 · con foto · verified = 1 · retenida por moderación
al aprobar:
  ledger id=34 · delta=+500 · balance 1432 · action="review"
                 note="Reseña con foto aprobada (#1876)"
  sello _akb_rewards_review_points = 500
```

**Los 500 puntos que promete el correo se pagan.** Con eso la cadena está medida
de punta a punta en producción: pedido completado → invitación → correo recibido
→ formulario con token → reseña con foto → moderación la retiene → aprobación →
500 puntos. Y la reseña salió en la ficha **con el sello de compra verificada**.

Producción devuelta a su estado, contando: reseñas 0, ledger 22 filas, formularios
0, pedido de prueba cancelado y en papelera, tomo 27914 con stock 1 y 21.600,
seq 421, boundary 2026-09-07 03:00 UTC (medianoche de Chile), promo intacta.
