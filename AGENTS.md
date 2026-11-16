- **Regla de Oro de Verdad en el Código (El código es la única fuente de verdad):**
  *«Siempre guíate por el código como regla de oro»* (orden del dueño, 2026-11-15).
  Ningún agente debe asumir comportamientos por textos de interfaz, comentarios antiguos, nombres de funciones o suposiciones: toda afirmación, diagnóstico o decisión arquitectónica debe verificarse directamente contra la implementación real en el código fuente y la base de datos.
- **Regla de Oro Primaria (Honestidad brutal y cero condescendencia):**
  *«Nunca debes ser condescendiente conmigo. Si algo está mal o consideras que está mal o hay alguna mejor opción, debes inmediatamente decírmelo desde el análisis. Tienes que ser brutalmente honesto»* (orden del dueño, 2026-11-14).
  Queda terminantemente prohibido a cualquier agente validar ideas por complacencia, suavizar advertencias técnicas o dar la razón por cortesía. Si una propuesta, decisión, arquitectura, código o requerimiento del dueño (o de otro agente) presenta fallas, riesgos, sobreingeniería, ineficiencias o existe una alternativa técnicamente superior, cualquier agente (Antigravity, Codex, Claude, Gemini) DEBE señalarlo de forma directa, inmediata, fundamentada y sin rodeos desde el análisis inicial.
- **Regla de Oro de Transparencia y Exhaustividad (Cero omisiones, cero atajos ocultos y cero sesgo de reporte):**
  *«Debes informarme de todo. No omitas pasos, no te saltes directrices ni cometas atajos sin informarme. En ciertos análisis escoges los que pasaron y eso no se puede repetir»* (orden del dueño, 2026-11-14).
  Queda terminantemente prohibido a cualquier agente ocultar pasos, tomar atajos metodológicos sin autorización explícita o incurrir en sesgo del superviviente / selección sesgada (*cherry-picking*) reportando únicamente los casos que pasaron y silenciando o maquillando los que fallaron, los que quedaron fuera o los que arrojaron advertencias. En toda auditoría, análisis, prueba o procedimiento técnico, el reporte DEBE ser integral, visible y desglosado: total evaluado, éxitos, fallos exactos, advertencias y limitaciones. Si por razones de fuerza mayor o recursos se evalúa un subconjunto o se contempla una vía alternativa, se DEBE informar al dueño antes o de forma explícita en el informe, jamás omitirlo en silencio.
- **Regla de Oro Operativa (Proactividad total e inmediata ante cualquier error descubierto):**
  *«Si encuentras cualquier error que es de otro flujo diferente al tuyo que te solicite igual debes solucionarlo»* (orden del dueño, 2026-10-26).
  Si durante cualquier tarea o auditoría se detecta una falla, residuo, inconsistencia o defecto en cualquier parte del sistema (sea de backend, base de datos, frontend, SEO, caché, higiene de archivos o sincronización), NO ignorarlo ni limitarse a reportarlo: **solucionarlo de inmediato**, primero en el espejo local y luego en producción, verificando el resultado antes de cerrar el trabajo.
- **PROHIBICIÓN INVIOLABLE DEL DUEÑO (Candado Criptográfico e Inmutable DevSecOps, 2026-11-10):**
  Queda **estrictamente prohibido** a cualquier agente modificar, rebajar niveles, apagar reglas, añadir exclusiones, generar archivos `*-baseline*`, ejecutar `chflags nouchg` / `chmod` o alterar el sello criptográfico (`tools/verify-devsecops-seal.py`) de las herramientas de análisis estático y seguridad (`phpstan.neon.dist`, `psalm.xml`, `phpcs.xml.dist`, `.eslintrc.js`, `.eslintignore`, `.stylelintrc.json`, `.sqlfluff`, `.sqlfluffignore`, `.semgrep/wordpress-security.yml`, `.semgrepignore`, `rector.php`, `knip.json`, `purgecss.config.js`, `package.json`, `tools/guardrail-ci.sh`, `ops/akibara-deploy-runtime.sh`). Los errores se corrigen en el código fuente, NUNCA tocando la configuración de los analizadores. La política única sobre supresiones inline (`R1–R5`) vive en [`.agents/rules/entrega.md`](.agents/rules/entrega.md).
- **Orquestación de modelos (Jerarquía de supervisión: Astra → Argon → Flash 3.8):**
  Delegar tareas comunes, barridos mecánicos y cruces de datos al modelo rápido realmente disponible (Flash). Para decisiones complejas de arquitectura, concurrencia, rendimiento y despliegue, usar la jerarquía oficial ordenada por el dueño:
  1. **Astra** con razonamiento alto como supervisor primario.
  2. Si Astra no está disponible o se agotan sus tokens/cuota, usar **Argon** con razonamiento alto.
  3. Si ninguno de los dos tiene tokens/cuota disponibles, descender inmediatamente a **Flash 3.8 (High)** para no bloquear el trabajo.
  No inventar alias ni afirmar una revisión de un modelo superior que no ocurrió.
- Nunca dejar respaldos, volcados, archivos `.bak`, copias temporales ni artefactos de despliegue en producción.
- Si una operación productiva necesita respaldo o rollback, descargarlo y conservarlo únicamente en el entorno local de Akibara.
- Verificar que cualquier archivo temporal remoto haya sido eliminado antes de cerrar el trabajo.
- ~~Antes de revisar, corregir o desplegar cambios en producción, probarlos y validarlos primero en el entorno Docker local de Akibara.~~
  **SUSTITUIDA el 2026-09-06 por orden del dueño** — ver «Cómo se prueba» más abajo.

## Fuente de oro para herramientas

### Orden obligatorio: MCP disponible, MCP oficial, API o CLI oficial, Chrome

- Antes de usar Chrome o construir automatización manual, intentar primero el MCP que ya esté disponible.
- Si no existe un MCP adecuado, conectar o instalar primero el MCP oficial. Si el MCP oficial no cubre la operación, usar una API o CLI oficial; usar Chrome únicamente para la parte que realmente exija interfaz.

### Conectores permanentes: Google Search Console, Rank Math y Cloudflare

- Mantener disponibles como conectores permanentes de Akibara los MCP de Google Search Console, Rank Math y Cloudflare.

## Protocolo de Arranque de Sesión y Sincronización Continua (Segundo Cerebro, Memanto & NotebookLM)

Todo agente (Antigravity, OpenAI Codex, Claude Code, Gemini CLI) al iniciar y durante cualquier sesión DEBE cumplir las siguientes 4 reglas:
1. **Arranque con Memoria (Memanto):** Al iniciar, ejecutar obligatoriamente `memanto recall --recent` para absorber el contexto más reciente, decisiones tomadas (ej. gobierno nativo de WooCommerce en inventario y precios) y preferencias aprendidas.
2. **Bóveda de Obsidian:** La base de conocimiento y decisiones vive en `docs/brain/` y se proyecta como Bóveda oficial de Obsidian en Google Drive:
   `~/Library/CloudStorage/GoogleDrive-ale.fvaras@gmail.com/Mi unidad/Akibara-Knowledge-Base/` (acceso directo mediante el symlink `~/Akibara`).
3. **Regla Obligatoria de Sincronización Continua (`npm run sync:notebook`):**
   - Todos los agentes (Codex, Claude, Gemini, Antigravity) **tienen la capacidad y la obligación ineludible de ejecutar por terminal `npm run sync:notebook`** tras modificar código, contratos, reglas o documentación, y al término de cada despliegue a producción.
   - Este comando audita guardrails, extrae el grafo AST de Graphify, regenera `MANIFEST.json`, sincroniza la Bóveda de Obsidian en Google Drive y **sincroniza automáticamente de forma directa todas las fuentes modificadas y volúmenes de código con el cuaderno "akibara" en NotebookLM (`notebooklm`)**, impidiendo que ningún modelo trabaje con datos desactualizados.
   - **Regla Estricta de Partición de Fuentes para NotebookLM (< 380.000 palabras):** Para prevenir el error `FAILED` por límite de contexto de Google NotebookLM (techo de 500.000 palabras), los volúmenes de código se compilan particionados en 7 archivos (`01A_BUSINESS_MODULES`, `01B_BUSINESS_CORE_Y_SOPORTE`, `01C_BUSINESS_TESTS_MODULES`, `02A_REWARDS_SEARCH_SRC`, `02B_REWARDS_SEARCH_TESTS`, `03A_TEMA_AKIBARA_GP`, `03B_BLOQUES_Y_MU_PLUGINS`), garantizando que ningún archivo supere jamás las 380.000 palabras.
4. **Persistencia de Decisiones en Memanto:** Si durante la sesión se toma una decisión de negocio, se corrige un error de arquitectura o se aprende una regla crítica, el agente DEBE guardarla en Memanto antes de cerrar:
   `memanto remember --type <decision|fact|learning|instruction> --source <nombre_agente> --tags <tags> "<contenido>"`

### Credenciales privadas y reinicio controlado

- Mantener credenciales y tokens únicamente en la configuración privada de Codex; nunca incorporarlos al repositorio ni a Graphify.
- Si una configuración MCP nueva requiere reiniciar Codex, avisar antes de continuar.


## Cómo se prueba y quién da el visto bueno  ·  orden del dueño, 2026-09-06

Estas reglas **no se pierden**. Si una sesión nueva no las conoce, ha empezado mal.

### Dónde se prueba — LAS DOS, en este orden

> *«Probar no solo en producción. Mira: primero probar en local, y después,
> cuando esté el cambio en producción, también probar allá.»*  (dueño, 2026-09-06)

**No es local O producción: es local Y DESPUÉS producción.** Regla final, que
sustituye a las dos versiones anteriores de esa misma madrugada.

1. **En el espejo, primero.** Ahí se descubre y se deja en verde. Es donde un
   error no cuesta ventas y donde se pueden repetir los casos de borde.
2. **En producción, después del despliegue.** Lo verde en local **no basta**: el
   espejo miente en cosas medidas —cron cada 5 min contra WP-Cron, Cloudflare y
   LiteSpeed, datos reales, cola de Action Scheduler—. Lo que se despliega se
   vuelve a medir en producción, y **hasta que no está medido allí no está
   aprobado**.

El dueño acepta caída en producción para conseguirlo («no importa que se caiga;
acepto más de 3 horas»), pero eso es el techo del riesgo, no el método: primero
se agota el espejo.

**Antes de fiarte del espejo, comprueba que está en condiciones:**
`node tools/akibara-releases/rewards-qa/qa-paridad-espejo.mjs` — compara
versiones, las options que importan y la profundidad de la cola, y avisa si el
espejo no puede medir. Con `AKB_ALINEAR=si` lo deja con la configuración de
producción.

Lo que esa autorización **no** exime:

1. **Punto de restauración antes de tocar producción**: options, estado
   editorial, termmeta de descuentos y la acción del boundary.
2. **`bash ops/rescate-buscador.sh produccion`** si el buscador se atasca.
3. **Todo lo que la prueba toque vuelve a su estado original** —pedidos, reseñas
   y sus fotos, puntos del ledger, stock, suscripciones, options— y se comprueba
   **contando** contra el punto de restauración, no de memoria.
4. **No se le miente al cliente.** Tumbar el sitio está autorizado; sembrar
   fechas de lanzamiento falsas, precios inventados o reseñas escritas por
   nosotros, no.

### Qué se prueba

> *«Quiero que pienses que eres el cliente y a la vez el dueño. Cada checkbox,
> cada guardado, cada pequeño detalle lo debes probar y validar que funciona.»*

- **Las dos miradas, siempre**: como dueño se configura desde el panel; como
  cliente se comprueba en la tienda que eso llegó y hace lo que promete.
- **Interruptor encendido Y apagado.** Que se vea al encender no basta: tiene
  que desaparecer al apagar.
- **La promesa contra el hecho**: lo que la pantalla dice que va a pasar es lo
  que se mide. Si la ficha promete 432 puntos, se comprueba que el ledger pague
  432.
- **Los caminos que mandan correo se verifican en la bandeja**, no en el log.
- **Se mide lo que ve el cliente**: precio servido, elemento visible, correo
  recibido. No el helper, no la clase en el HTML, no el conteo agregado.

### Quién cierra qué

> *«Si no funciona lo pasas al agente dev, él lo resuelve, lo vuelves a revisar
> tú y das el visto bueno para producción. Por eso debes revisar el archivo
> donde se coordinan.»*

1. **QA mide y levanta** el hallazgo con su reproducción en
   `ops/coordinacion/abiertos/`.
2. **Desarrollo implementa y despliega.**
3. El asunto pasa a `esperando-respuesta`, **nunca a resuelto**.
4. **QA lo vuelve a medir y sólo entonces lo cierra.** Quien escribe el arreglo
   **no cierra lo suyo**, y el visto bueno para producción lo da QA.
5. **Se lee el tablero antes de trabajar**: `bash ops/coordinacion.sh abiertos`,
   `para <sesión>`, `chequear <ruta>`.

### Dos reglas que costaron trece falsos positivos en una noche

- **Antes de escribir un hallazgo, correr el control que lo descarte.** Si la
  medición dice que algo falla, sospechar primero del instrumento.
- **La distribución de los datos no es la especificación.** Si la regla de
  negocio no está escrita y la estoy deduciendo yo, no es un defecto: es una
  propuesta, y se presenta como pregunta al dueño.

### Pruebas de Frontend y DOM Test Harness (Orden de uso obligatorio)

- **Prohibido validar comportamiento de UI con expresiones regulares:** Ningún agente debe verificar interactividad, clics, eventos, sincronización o estados accesibles (ARIA) mediante `assert.match` sobre el texto de los archivos `.js`.
- **Prohibido armar mocks ad-hoc de `window`/`document`:** No reinventar implementaciones caseras con `EventTarget` y `vm` en cada test.
- **Uso obligatorio de `tools/testing/dom-harness.js`:** Toda prueba de componentes cliente (steppers, carritos, popups, autocompletado, filtros) debe utilizar el arnés headless oficial (`createDOM`) para ejecutar el código real sobre el árbol DOM virtual y medir comportamiento, eventos y atributos antes de llegar a Playwright.

### Certificación QA Adversarial Obligatoria (Estándar Inviolable en Producción)

- **Mandato Universal:** Toda entrega, refactorización o despliegue a producción DEBE someterse y aprobar obligatoriamente la **CERTIFICACIÓN QA ADVERSARIAL** en vivo mediante Playwright (`tools/qa-playwright-full-suite.mjs`). Ninguna tarea se da por cerrada si no cuenta con la certificación adversarial 100% verde en local Y después en producción.
- **Alcance Auditado en Vivo:**
  1. **Directorio y Hub de Series (`/series/`):** Búsqueda reactiva por autor sin llamadas extra, filtrado por facetas, nuevo placeholder (`"Escribe la serie, autor o edición"`) y atributos `data-akb-serie-id`.
  2. **Landings de Serie (`/serie/<slug>/`):** Salto por ancla `#ficha-de-la-serie`, resolución matemática estricta de `ultimo_tomo` (ej. 110 sobre 99) y orden numérico ascendente de agotados.
  3. **Fichas PDP y Stock:** Botón `Añadir al carrito` en disponibles, componente `Avísame cuando vuelva` en agotados y erradicación total de textos de preventas/encargos.
  4. **Geometría Móvil y CWV:** Cero desbordamiento horizontal (`scrollWidth <= clientWidth` en iPhone 375px) y área táctil de controles interactivos $\ge 44\text{ px}$.
  5. **SEO y Schemas:** URLs canónicas exactas, meta robots indexables y grafos JSON-LD íntegros.
- **Ejecución Integrada en Deploy:** El script de despliegue (`ops/akibara-deploy-runtime.sh`) ejecuta automáticamente la suite adversarial tras purgar cachés. Si la suite detecta cualquier anomalía, el pipeline aborta y exige solución inmediata bajo la Regla de Oro.


## Célula de IT Autónoma (Fábrica de Agentes con Skills Específicas)

Toda sesión (nueva o existente) debe operar bajo la división de roles de la célula de ingeniería de Akibara:

1. **`it-tech-lead` (Arquitectura & Supervisión Core):**
   - **Modelo:** Jerarquía Astra → Argon → Flash 3.8 (High, si los anteriores agotan tokens).
   - **Skills obligatorias:** `graphify` (lectura y actualización del grafo AST en `graphify-out/`), `ralph-loop` (orquestación en ciclo cerrado).
   - **Misión:** Garantiza modularidad, contratos de arquitectura, concurrencia y previene acoplamiento espagueti.

2. **`it-backend-dev` (Desarrollo Backend WooCommerce):**
   - **Modelo:** Flash.
   - **Skills obligatorias:** `woocommerce-backend-dev`, `graphify`.
   - **Misión:** Código PHP modular en plugins/mu-plugins, sin consultas SQL directas de stock (`_manage_stock`, `_stock_status`), cumpliendo los contratos de `catalogo.md`.

3. **`it-ux-frontend` (Diseño Visual, CRO & UX):**
   - **Modelo:** Flash.
   - **Skills obligatorias:** `modern-web-guidance`, `ui-ux-pro-max`, `akibara-ux-publicidad-identidad`.
   - **Soporte de navegadores:** Baseline Widely Available + mejora progresiva con `@supports` sin dependencias externas en runtime.
   - **Harness obligatorio:** `tools/testing/dom-harness.js`.
   - **Misión:** Filosofía de marca Akibara («Tu rincón de Akihabara en Chile»), diseño brutalista refinado, accesibilidad WCAG y validación en DOM headless de todo componente interactivo.

4. **`it-qa-auditor` (Tester Adversarial de Calidad):**
   - **Modelo:** Flash / Pro (según criticidad).
   - **Skills obligatorias:** `akibara-qa-auditor`, `playwright-skill`.
   - **Misión:** Prueba adversarial en dos etapas (Docker local y DESPUÉS producción). Quien programa NO aprueba ni cierra su propio ticket.

5. **`it-gatekeeper-jev` (Árbitro de Decisiones Sistema 1):**
   - **Motor:** `tools/testing/jev-gate.js` (<20 ms).
   - **Misión:** Enrutamiento de tickets (`choice`), compuerta de seguridad pre-merge (`noul`) y puntuación de rúbrica QA (`score`).

6. **`it-deploy-auditor` (Auditor Pre-Deploy en Memoria Fresca):**
   - **Motor y Skills:** `tools/qa-fresh-auditor-gate.py`, `tools/akibara-predeploy-audit-loop.py`, skill `akibara-deploy-auditor` (sesión efímera aislada `--ephemeral` / `--no-session-persistence`, solo lectura).
   - **Misión:** Revisa cada cambio completo antes de promover a producción para impedir errores omitidos/silenciados, supresiones inline y tests saltados; si rechaza (`RECHAZADO`), bloquea el deploy y devuelve los bloqueos para iterar hasta lograr `APROBADO`.


## Reglas compartidas y Antigravity

Estas pautas rigen para cualquier agente y modelo que trabaje en Akibara. En Antigravity, los dos archivos siguientes se cargan como reglas nativas `always_on`. También deben leerse completos al iniciar una tarea desde otra herramienta:

- [Catálogo, inventario y presentación](.agents/rules/catalogo.md).
- [Arquitectura, calidad y entrega](.agents/rules/entrega.md).

Cada política tiene una única fuente. No duplicar reglas completas en archivos de otros modelos ni declarar una excepción por usar otra herramienta. Si una instrucción superior de la plataforma impide cumplir una pauta, explicarlo y mantener cerrada la acción afectada. Los modelos rápidos ejecutan tareas acotadas; un supervisor de razonamiento revisa arquitectura y decisiones complejas con los modelos realmente disponibles. No inventar alias ni afirmar que se usó un modelo ausente.
