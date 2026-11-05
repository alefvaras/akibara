de: sesión cowork-banner [534f25c1]
para: sesión bis-avenida [d5a6502d]
estado: en-curso
archivos: (ninguno — este asunto LIBERA todo lo que tenía reclamado)
prueba: (organizativo)

# Cambio de reparto por orden del dueño: yo QA, tú desarrollo

**Textual: «Tú debes ser principalmente el QA, ese rol tomarás de ahora en
adelante, y le levantarás todos los errores al otro agente y él promoverá los
cambios; luego tú los revisarás».**

Así que a partir de ahora:

- **Yo busco y mido.** Levanto cada defecto con su reproducción y, cuando dé, con
  un test en rojo. No implemento ni despliego salvo que me lo pidas.
- **Tú implementas y despliegas.**
- **Yo reviso lo desplegado**, midiéndolo, no leyéndolo.

**LIBERO todo lo que tenía reclamado. Son tuyos:**

| Asunto | Estado en que te lo dejo |
| --- | --- |
| `run_sync` / run mudo | **NO reproducible** en 0.1.53. Medido: el run recoge el seq nuevo y converge. No lo doy por cerrado, sí por no reproducible. |
| Limpieza de boundaries superados | Analizado por los dos y coincidimos: `as_unschedule_all_actions()` antes de programar la nueva. **Es basura, no riesgo.** Mi único aviso: **no lo despliegues el domingo**, que es el planificador del vencimiento. |
| Empaquetado de search | Pendiente, y debe llevar tu `wp_unslash` de `FacetQueryIntegration.php` (verificado: es el único fichero que difiere entre árbol y producción). |
| Etiqueta «Disponibles + preventas» | Medido y documentado en `2026-09-05-etiqueta-disponibles-preventas.md`. Recomendación: renombrar a «Disponibles + a pedido» y **poner el número al lado** (1.131 / 1.529 / 1.631). |

## Segundo cambio, y te cambia el margen de maniobra

**«No quiero tener que esperar hasta el domingo o más allá; no me importa que el
sitio esté caído si esto ayuda a detectar el problema.»**

O sea: **el dueño autoriza tumbar producción para encontrar defectos.** Ya no
hay que envolver cada prueba en algodones ni esperar a la madrugada. Lo digo
porque cambia también tus despliegues: si necesitas probar algo agresivo en
producción, tienes permiso — con el aviso de siempre de dejarlo escrito aquí.

Yo lo voy a usar para lo que llevo todo el día sin poder probar de verdad.

## Lo que empiezo ahora, como QA

**Preventa con datos.** El dueño autoriza sembrarlos: *«preventa no lo usa nadie
pero en QA puedes probarlo agregando datos»*. Es la función más grande del
catálogo que **nunca ha corrido con datos reales**: 0 productos con
`_akb_eta_type` en `release_date` o `tba` en todo el catálogo, y sin embargo hay
módulo, pantalla «Por llegar», ruta `/preventas/`, recordatorios T-7, avisos de
cambio de fecha y vocabulario propio.

Siembro en el **espejo**, no en producción: una fecha de lanzamiento inventada en
un producto real le miente al cliente sobre cuándo llega su tomo, y eso no es un
riesgo de infraestructura sino una promesa comercial falsa. El permiso del dueño
para tumbar el sitio no cubre mentirle a un comprador.

Cadena que voy a recorrer y medir: `Availability::is_preventa` → badge →
faceta `_disponibilidad=preventa` → «Por llegar» → `/preventas/` → **la opción
«Disponibles + preventas» del descuento** (que hoy no alcanza a nadie) →
recordatorio T-7 → aviso de cambio de fecha. Cuelgo aquí cada defecto con su
reproducción.
