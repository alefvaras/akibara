---
name: akibara-it-squad
description: >-
  Célula de Ingeniería y Fábrica de Agentes Autónomos de Akibara.
  Orquesta la colaboración multi-agente entre Tech Lead (Astra/Argon/Flash 3.8),
  Backend Dev, Frontend UX y QA Auditor, coordinados por Graphify y Jev (System 1).
  Debe activarse para cualquier ticket de desarrollo, refactor, auditoría o despliegue.
---

# Célula de IT de Akibara (Fábrica de Agentes)

Este procedimiento estándar rige de forma obligatoria para **toda sesión nueva o antigua** en el repositorio de Akibara.

## 1. Organigrama de la Célula y Asignación de Skills

Cada agente cuenta con un rol específico y herramientas delimitadas para evitar cruces o desalineaciones:

| Rol de la Célula | Identificador Subagente | Jerarquía de Modelos | Skills Obligatorias | Responsabilidad Primaria |
| :--- | :--- | :--- | :--- | :--- |
| **Tech Lead / Arquitecto** | `it-tech-lead` | **1. Astra** → **2. Argon** → **3. Flash 3.8 (High)** *(si los anteriores agotan tokens)* | `graphify`, `ralph-loop` | Contratos de arquitectura, prevención de espagueti, concurrencia de BD, aprobación técnica de tickets. |
| **Backend Developer** | `it-backend-dev` | **Flash** | `woocommerce-backend-dev`, `graphify` | Módulos PHP en `akibara-business`, `akibara-search`, `akibara-rewards`, Brain Monkey tests. Cero escrituras directas de stock por SQL. |
| **Frontend & UX Specialist** | `it-ux-frontend` | **Flash** | `ui-ux-pro-max`, `akibara-ux-publicidad-identidad`, `dom-harness` | Identidad de marca, usabilidad CRO, accesibilidad WCAG y pruebas de comportamiento en `tools/testing/dom-harness.js`. |
| **QA Auditor Adversarial** | `it-qa-auditor` | **Flash** o **Pro** | `akibara-qa-auditor`, `playwright-skill` | Doble chequeo independiente (Local Docker Y DESPUÉS producción). El programador nunca aprueba su propio trabajo. |
| **Árbitro de Decisiones (System 1)** | `it-gatekeeper-jev` | Ejecutable local / API TypeSafe | `tools/testing/jev-gate.js` | Enrutamiento instantáneo (<20ms), compuerta de seguridad `noul` y puntuación `score`. |

---

## 2. Flujo de Trabajo Operativo (Pipeline de 4 Fases)

### Fase 1: Enrutamiento Rápido con Jev (`choice`)
Ante cualquier ticket o requerimiento, ejecutar la clasificación con Jev:
```bash
node -e "
const { evaluateTaskRouting } = require('./tools/testing/jev-gate.js');
console.log(evaluateTaskRouting(process.argv[1]));
" "<descripción del ticket>"
```
* Si el resultado es `astra_architecture` $\rightarrow$ Delegar la especificación a `it-tech-lead`.
* Si el resultado es `frontend_dom` $\rightarrow$ Delegar a `it-ux-frontend`.
* Si el resultado es `flash_mechanic` $\rightarrow$ Delegar a `it-backend-dev`.

### Fase 2: Implementación con Consulta al Grafo (Graphify)
1. Antes de modificar código, el agente asignado DEBE consultar las relaciones e impactos en el grafo:
   ```bash
   python3 -c "import json; g=json.load(open('graphify-out/graph.json')); print('Nodos:', len(g.get('nodes', [])))"
   ```
2. Si el cambio toca componentes frontend, es **obligatorio** escribir o actualizar la prueba en `tools/testing/dom-harness.js`. Queda terminantemente prohibido usar `assert.match` sobre el texto de los archivos `.js`.

### Fase 3: Compuerta de Seguridad Jev (`noul`)
Antes de promover o ensayar cualquier cambio, evaluar el diff con la compuerta de seguridad:
```bash
node -e "
const { execSync } = require('child_process');
const { evaluateNoulSafety } = require('./tools/testing/jev-gate.js');
const diff = execSync('git diff HEAD').toString();
const gate = evaluateNoulSafety(diff);
if (!gate.result) {
  console.error('BLOQUEO JEV:', gate.reason);
  process.exit(1);
}
console.log('Jev Safety Gate: APROBADO');
"
```

### Fase 4: QA Adversarial y Cierre de Ticket
1. Ejecutar la suite completa de calidad:
   ```bash
   bash tools/guardrail-ci.sh
   ```
2. Seguir la **orden del dueño del 2026-09-06**:
   * Ensayar en el contenedor Docker local primero.
   * Tras desplegar a producción, auditar con Playwright y herramientas de QA en vivo.
   * Actualizar el grafo de conocimiento al finalizar:
     ```bash
     bash ops/update-graphify.sh
     ```
