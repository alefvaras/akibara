de: sesión bis-avenida [d5a6502d]
para: cualquiera
estado: abierto
archivos: (ninguno — protocolo)
prueba: (no aplica)

# Reparto fijado por el dueño: developer senior de WooCommerce + QA

**2026-09-05, orden del dueño:** «Tú tomarás el rol de developer senior
especialista en WooCommerce desde ahora en adelante. Cuando el QA detecte
hallazgos, iteras con él, lo resuelves, y él lo vuelve a probar.»

| | Sesión | Qué hace |
| --- | --- | --- |
| **Desarrollo** | bis-avenida [d5a6502d] | diseña, implementa, prueba lo suyo, despliega |
| **QA** | cowork-banner [534f25c1] | encuentra hallazgos y **RE-PRUEBA lo arreglado** |

## Cómo se cierra un hallazgo, a partir de ahora

1. QA lo abre aquí con su medición y su comando de reproducción.
2. **Iteramos antes de codificar** si el diagnóstico, el alcance o el reparto no
   están claros. Preguntar cuesta un mensaje; deshacer un arreglo cuesta un
   despliegue.
3. Desarrollo implementa, con suite y prueba en el espejo, y despliega.
4. **El asunto pasa a `esperando-respuesta`, NO a `resuelto`**, con: qué se
   cambió, qué prueba lo verifica y qué hay que ver para darlo por bueno.
5. **Lo cierra QA con su verificación.** Quien escribe el arreglo no lo cierra.

## Por qué, con lo de hoy como evidencia

Un arreglo verificado por quien lo escribió hereda sus puntos ciegos. Hoy, en un
día:

- di por bueno un «candado circular» que era un rebuild normal, y estuve a punto
  de revertir search en producción;
- medí si la promo seguía viva contando etiquetas `<ins>` y me salió lo
  contrario de la verdad;
- rompí una plantilla con una sustitución automática.

Las tres las cacé por contraste —una medición nueva, un control, `php -l`—, no
por estar seguro. Y las dos mejores correcciones del día vinieron de la otra
sesión: sus tres retractaciones y su ensayo del vencimiento, que refutó sus
propias predicciones con datos.

## Lo que aporta cada lado, en concreto

**Desarrollo debe avisar de lo que QA no puede ver**: interacciones entre
arreglos en vuelo. Hoy el «releer tras guardar» de QA habría dado 47 avisos
falsos por una guarda desplegada una hora antes. Eso se dice ANTES.

**QA debe medir la superficie servida**, no el árbol ni la teoría. El ensayo del
vencimiento en producción valió más que las dos estimaciones que lo precedieron.
