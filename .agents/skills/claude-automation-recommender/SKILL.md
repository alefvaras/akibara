---
name: claude-automation-recommender
description: Analyze the Akibara codebase (or any project) and recommend tailored Claude Code & Antigravity automations (hooks, subagents, skills, plugins, MCP servers). Use when the user asks for automation recommendations, wants to optimize their Claude Code / multi-agent setup, or wants to audit and evolve Akibara's agentic workflows.
tools: Read, Glob, Grep, Bash
---

# Claude & Multi-Agent Automation Recommender (Adaptado para Akibara)

Analiza los patrones reales del repositorio para recomendar y auditar automatizaciones de **Claude Code** y **Antigravity** en las 5 categorías de extensibilidad: **MCP Servers**, **Skills**, **Hooks**, **Subagents** y **Plugins**.

**Modo de operación:** Por defecto es **de diagnóstico y recomendación (read-only)** durante el análisis, e identifica tanto lo que ya está activo en Akibara como las brechas o mejoras de automatización listas para aplicar en `.claude/` y `.agents/`.

## Pautas de Salida

- **Recomendar 1-2 de cada categoría principal**: Priorizar las automatizaciones de mayor impacto real para la arquitectura de Akibara (WordPress/WooCommerce HPOS, buscador propio, bloques de carrito/checkout, rieles de despliegue sellados y Segundo Cerebro).
- **Si el usuario pide una categoría específica**: Profundizar en esa categoría con 3-5 opciones concretas.
- **Respetar las Invariantes Operativas de Akibara (`AGENTS.md` / `CLAUDE.md`)**:
  - **No es un repositorio Git con flujo de PR en GitHub**: Nunca recomendar hooks de `gh pr` o `git push` para despliegue; todo despliegue va por `ops/*-promotion.sh`, contratos en `ops/contracts/` y verificación por hash contra `ssh akibara`.
  - **Doble prueba obligatoria (Local Docker -> Producción)**: Primero en el espejo Docker (`compose.yaml`) y después medido en producción (`akibara.cl`).
  - **Modelo de Inventario Binario Estricto**: `instock` y `outofstock` únicamente (`0 onbackorder`), auditado con `python3 ops/guardrail-inventario-akibara.py`.
  - **Frontend DOM Test Harness**: Uso obligatorio de `tools/testing/dom-harness.js` (`happy-dom` + `node:test`); prohibido validar UI con regex (`assert.match`).
  - **Jerarquía de Modelos de la Célula de IT**: **1. Astra** $\rightarrow$ **2. Argon** $\rightarrow$ **3. Flash 3.8 (High)**.

## Resumen de Tipos de Automatización

| Tipo | Propósito en Akibara | Ubicación en el Proyecto |
|------|----------------------|--------------------------|
| **Hooks** | Acciones automáticas en eventos de herramientas (`PreToolUse`, `PostToolUse`, `SessionStart`, `PreCompact`) | `.claude/settings.json` y `~/.claude/settings.json` |
| **Subagents** | Agentes especializados de la Célula de IT (`it-tech-lead`, `it-backend-dev`, `it-ux-frontend`, `it-qa-auditor`, `it-gatekeeper-jev`) | `.claude/agents/<name>.md` y `.agents/skills/akibara-it-squad/` |
| **Skills** | Conocimiento empaquetado y flujos repetibles invocables por el agente o con `/skill-name` | `.agents/skills/<name>/SKILL.md` y `.claude/skills/<name>/SKILL.md` |
| **Plugins** | Paquetes instalables desde marketplaces oficiales (`claude plugin install`) | `~/.claude/plugins/` |
| **MCP Servers** | Conectores permanentes (Google Search Console, Rank Math, Cloudflare, Hostinger, Chrome DevTools, Memory) | `.agents/mcp.json`, `.mcp.json` y `~/.claude/settings.json` |

## Flujo de Ejecución (Workflow)

### Fase 1: Análisis del Codebase de Akibara

Ejecutar el barrido de contexto adaptado a la topología real de Akibara:

```bash
# 1. Detectar herramientas de build, calidad y locks del monorepo
ls -la package.json compose.yaml AGENTS.md CLAUDE.md tools/akibara-business/composer.json 2>/dev/null
cat package.json 2>/dev/null

# 2. Auditar componentes first-party de WordPress / WooCommerce
ls -la public_html/wp-content/plugins/akibara-* public_html/wp-content/themes/akibara-gp public_html/wp-content/mu-plugins/akibara-*.php 2>/dev/null

# 3. Verificar configuración existente de Claude Code y Antigravity
ls -la .claude/ .claude/agents/ .claude/skills/ .agents/ .agents/skills/ .agents/rules/ 2>/dev/null
claude plugin list 2>/dev/null || true

# 4. Verificar instrumentos de QA, Graphify, Memanto y Coordinación
ls -la tools/guardrail-ci.sh tools/testing/dom-harness.js tools/testing/jev-gate.js ops/coordinacion.sh ops/guardrail-inventario-akibara.py graphify-out/graph.json notbooklm/MANIFEST.json 2>/dev/null
```

**Indicadores Clave a Capturar en Akibara:**

| Categoría | Qué Inspeccionar | Informa Recomendaciones Para |
|-----------|------------------|------------------------------|
| Runtime Backend | `public_html/wp-content/plugins/akibara-{business,search,rewards,cart-checkout-blocks}` y 17 `mu-plugins` | Hooks `php -l` / AST hygiene, subagente `it-backend-dev`, skill `woocommerce-backend-dev` |
| Frontend & Tema | `public_html/wp-content/themes/akibara-gp/`, `esbuild`, `happy-dom` | Hook de `dom-harness.js`, subagente `it-ux-frontend`, skills `ui-ux-pro-max` y `akibara-ux-publicidad-identidad` |
| Base de Datos & Stock | Tablas `wp_akb_search_*`, `wp_akb_rewards_*`, `ops/guardrail-inventario-akibara.py` | Hooks de bloqueo SQL directo a `_stock` y protección de `database/production.sql` |
| QA & E2E | `tools/akibara-e2e` (Playwright), `tools/test-clases-aisladas.sh`, `qa-paridad-espejo.mjs` | Subagente `it-qa-auditor`, skill `akibara-qa-auditor`, Playwright MCP / Chrome DevTools MCP |
| SEO & Infraestructura | Rank Math, Google Search Console, Cloudflare, LiteSpeed, Hostinger | Conectores MCP permanentes (`google-search-console`, `cloudflare`, `rank-math`) |
| Segundo Cerebro | `graphify-out/graph.json`, `memanto`, `tools/sync-notebooklm.mjs`, `docs/brain/` | Hooks `SessionStart` (`memanto recall`), `PostToolUse` / cierre (`npm run sync:notebook`) |
| Coordinación & Despliegue | `ops/coordinacion.sh`, `ops/contracts/`, `ops/*-promotion.sh` | Hook `PreToolUse` con `ops/coordinacion.sh chequear` y bloqueo de código third-party |

### Fase 2: Generar Recomendaciones por Categoría

Consultar las referencias detalladas en [references/](references/):
- [references/mcp-servers.md](references/mcp-servers.md)
- [references/skills-reference.md](references/skills-reference.md)
- [references/hooks-patterns.md](references/hooks-patterns.md)
- [references/subagent-templates.md](references/subagent-templates.md)
- [references/plugins-reference.md](references/plugins-reference.md)

#### A. Recomendaciones de MCP Servers para Akibara
| Señal en el Proyecto | Servidor MCP Recomendado |
|----------------------|--------------------------|
| SEO orgánico y auditoría de indexación de fichas/series | **google-search-console** + **rank-math** (conectores permanentes en `AGENTS.md`) |
| Caché edge, WAF y analítica de tráfico en `akibara.cl` | **cloudflare-observability** y **cloudflare-api** |
| Auditoría visual, LCP, cookies y DOM en espejo/producción | **chrome-devtools** / **Playwright MCP** |
| Memoria persistente inter-sesión y grafo de entidades | **memanto** CLI + **memory** MCP (`@modelcontextprotocol/server-memory`) |
| Infraestructura DNS/Hosting del servidor productivo | **hostinger-hosting / dns / domains** |

#### B. Recomendaciones de Skills para Akibara
| Señal en el Proyecto | Skill Recomendada | Invocación |
|----------------------|-------------------|------------|
| Orquestación multi-agente y compuerta Jev System 1 | **akibara-it-squad** + **ralph-loop** | Ambos |
| Desarrollo de módulos PHP y tests Brain Monkey | **woocommerce-backend-dev** | Ambos |
| Auditoría adversarial local Docker + producción | **akibara-qa-auditor** + **playwright-skill** | Ambos |
| Diseño brutalista refinado, CRO y fichas/editoriales | **akibara-ux-publicidad-identidad** + **ui-ux-pro-max** | Ambos |
| Consultas de impacto arquitectónico AST (25.683 nodos) | **graphify** | Ambos |
| Promoción segura por rieles y contratos sellados | **akibara-release-promotion** (custom skill sugerida con `disable-model-invocation: true`) | Sólo usuario |

#### C. Recomendaciones de Hooks (`.claude/settings.json`)
| Señal en el Proyecto | Hook Recomendado |
|----------------------|------------------|
| Edición de archivos PHP first-party (`akibara-*`) | **PostToolUse (`Edit\|Write`)**: Ejecutar `php -l` automático sobre el archivo PHP modificado para atrapar errores de sintaxis al instante |
| Archivos sensibles, volcados y código third-party | **PreToolUse (`Edit\|Write`)**: Bloquear edición directa de `database/production.sql`, `.local-backups/`, `public_html/wp-config.php`, locks y plugins de terceros (`woocommerce`, `seo-by-rank-math`, `litespeed-cache`) |
| Tablero de coordinación multi-sesión (`ops/coordinacion.sh`) | **PreToolUse (`Edit\|Write`)**: Verificar reclamo de ruta con `bash ops/coordinacion.sh chequear <ruta>` antes de modificar código compartido |
| Arranque y compactación de sesión | **SessionStart / PreCompact**: Ejecutar `memanto recall --recent` y sincronizar memoria con `memanto memory sync` |

#### D. Recomendaciones de Subagents (`.claude/agents/`)
| Señal en el Proyecto | Subagente Recomendado |
|----------------------|-----------------------|
| Supervisión de arquitectura, concurrencia y contratos | **it-tech-lead** (Jerarquía Astra $\rightarrow$ Argon $\rightarrow$ Flash 3.8 High) |
| Desarrollo modular WooCommerce y pruebas aisladas | **it-backend-dev** (Flash + `woocommerce-backend-dev`) |
| Componentes UI, CSS/JS y arnés `dom-harness.js` | **it-ux-frontend** (Flash + `dom-harness.js`) |
| Certificación independiente en espejo Docker y `akibara.cl` | **it-qa-auditor** (Read-only / Explore + Playwright) |
| Enrutamiento rápido (<20 ms) y compuerta `noul` | **it-gatekeeper-jev** (`tools/testing/jev-gate.js`) |

#### E. Recomendaciones de Plugins Oficiales de Claude Code
| Señal en el Proyecto | Plugin Oficial Recomendado |
|----------------------|----------------------------|
| Desarrollo sobre WordPress / WooCommerce | **build-with-wordpress@claude-plugins-official** (Instalado y activo) |
| Infraestructura y Workers/WAF en Cloudflare | **cloudflare@cloudflare** (Instalado y activo) |
| Auditoría continua de automatizaciones Claude Code | **claude-code-setup@claude-plugins-official** (Instalado y activo) |
| Análisis estático PHP y advertencias de seguridad OWASP | **php-lsp@claude-plugins-official** y **security-guidance@claude-plugins-official** |

### Fase 3: Formato del Informe de Recomendaciones

Entregar un informe estructurado con:
1. **Perfil Detectado del Proyecto Akibara** (stack, plugins propios, herramientas de verificación, estado actual de `.claude/` y `.agents/`).
2. **Top 1-2 Recomendaciones de Alto Impacto por Categoría** (MCP Servers, Skills, Hooks, Subagents, Plugins) con rutas exactas y fragmentos listos para usar.
3. **Estado de Instalación y Sincronización** entre `.claude/` y `.agents/`.
