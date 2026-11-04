---
name: ralph-loop
description: Metodología autónoma de ciclo cerrado (Ralph Loop) para ejecución de tareas continuas por tickets en Akibara. Ejecuta 3 iteraciones obligatorias por ticket (Implementación, Verificación Adversarial, Sellado & Grafo), manteniendo progreso en disco y avanzando de forma autónoma sin pedir confirmación al usuario salvo ante decisiones de negocio.
---

# Ralph Loop — Célula de Ejecución Autónoma de Akibara

La metodología **Ralph Loop** garantiza persistencia, calidad inquebrantable e higiene técnica ejecutando tareas de forma atómica y continua sobre un backlog estructurado.

## Principios Inviolables

1. **Autonomía Operativa:** La célula NO consulta al dueño para tareas técnicas, refactorizaciones, corrección de bugs ni despliegues ya autorizados. Solo se detiene ante **decisiones de negocio** (precios comerciales, promociones o cambios de políticas).
2. **Una tarea a la vez:** Se toma un único ticket del backlog, se ejecuta con sus 3 iteraciones completas y solo cuando está certificado y versionado se avanza al siguiente.
3. **Memoria en Disco:** El progreso nunca se confía al contexto volátil de la sesión. Se registra permanentemente en `ops/coordinacion/BACKLOG-RALPH.md` y `ops/coordinacion/progress-ralph.txt`.
4. **Regla de Oro de Akibara:** Todo error colateral detectado durante el camino se soluciona en el acto, primero en local y luego en producción.

## El Ciclo de 3 Iteraciones por Ticket

Para cada ticket del backlog, la célula ejecuta estrictamente:

### 🔄 Iteración 1: Implementación Local
- Diagnóstico fino de causa raíz y contratos de WordPress/WooCommerce.
- Modificación limpia de código en el entorno Docker local.
- Respeto de convenciones de arquitectura y cero código muerto o temporal.

### 🧪 Iteración 2: Verificación Rigurosa y Adversarial
- Ejecución de suites de prueba (`wp akb-pricing self-test`, guardrails, curl o Playwright).
- Medición de paridad y control negativo.
- Despliegue a Producción si corresponde (`rsync`/`ssh`), purga de cachés (Cloudflare + LiteSpeed) y re-medición en caliente en producción hasta obtener verde.

### 📦 Iteración 3: Sellado, Commit y Grafo
- Git commit atómico con mensaje semántico y referencia al ticket.
- Actualización del Second Brain / grafo de conocimiento (`graphify update .`).
- Registro del avance en `ops/coordinacion/progress-ralph.txt` y actualización del estado del ticket en `BACKLOG-RALPH.md`.
- Paso inmediato al siguiente ticket pendiente.
