# Tablero de Coordinación entre Sesiones (`ops/coordinacion/`)

Protocolo ligero de coordinación multi-agente y multi-sesión para evitar colisiones de edición sobre el mismo plugin o ruta.

## Comandos Obligatorios Antes de Editar

```bash
bash ops/coordinacion.sh abiertos               # Lista los asuntos activos
bash ops/coordinacion.sh para <tu-sesión>       # Filtra los dirigidos a tu sesión
bash ops/coordinacion.sh chequear <ruta...>     # Sale != 0 si una ruta está reclamada
bash ops/coordinacion.sh ver <id>               # Muestra el detalle de un asunto
bash ops/coordinacion.sh cerrar <id>            # Mueve el asunto a resueltos/ (sólo QA tras re-medir)
```

## Estructura de un Asunto (`ops/coordinacion/abiertos/<id>.md`)

```yaml
de: sesion-origen
para: sesion-destino | cualquiera
estado: abierto | en-curso | esperando-respuesta
archivos: public_html/wp-content/plugins/akibara-business/src/...
prueba: bash tools/test-clases-aisladas.sh business
```

## Regla de Cierre (Orden del Dueño 2026-09-06)

1. **QA mide y levanta** el hallazgo con su reproducción en `ops/coordinacion/abiertos/`.
2. **Desarrollo implementa y despliega**, cambiando `estado: esperando-respuesta` (nunca `resuelto`).
3. **QA vuelve a medir** en local y en producción y sólo entonces ejecuta `bash ops/coordinacion.sh cerrar <id>`.
