# Reglas operativas de Akibara

- **Regla de Oro (Proactividad total e inmediata ante cualquier error descubierto):**
  *«Si encuentras cualquier error que es de otro flujo diferente al tuyo que te solicite igual debes solucionarlo»* (orden del dueño, 2026-10-26).
  Si durante cualquier tarea o auditoría se detecta una falla, residuo, inconsistencia o defecto en cualquier parte del sistema (sea de backend, base de datos, frontend, SEO, caché, higiene de archivos o sincronización), NO ignorarlo ni limitarse a reportarlo: **solucionarlo de inmediato**, primero en el espejo local y luego en producción, verificando el resultado antes de cerrar el trabajo.
- **Orquestación de modelos (ejecución acotada y supervisión Astra):**
  Delegar tareas comunes, barridos mecánicos y cruces de datos al modelo rápido realmente disponible, usando Flash cuando la herramienta lo ofrezca. Para decisiones complejas de arquitectura, concurrencia, rendimiento y despliegue, usar Astra con razonamiento alto como supervisor, por instrucción vigente del dueño. Si Antigravity o el orquestador no ofrece ese modelo, comunicar la limitación y avanzar sólo con el trabajo independiente autorizado; no inventar alias ni afirmar una revisión de Astra que no ocurrió.
- Nunca dejar respaldos, volcados, archivos `.bak`, copias temporales ni artefactos de despliegue en producción.
- Si una operación productiva necesita respaldo o rollback, descargarlo y conservarlo únicamente en el entorno local de Akibara.
- Verificar que cualquier archivo temporal remoto haya sido eliminado antes de cerrar el trabajo.
- ~~Antes de revisar, corregir o desplegar cambios en producción, probarlos y validarlos primero en el entorno Docker local de Akibara.~~
  **SUSTITUIDA el 2026-09-06 por orden del dueño** — ver «Cómo se prueba» más abajo.

## Fuente de oro para herramientas

### Orden obligatorio: MCP disponible, MCP oficial, API o CLI oficial, Chrome

- Antes de usar Chrome o construir automatización manual, intentar primero el MCP que ya esté disponible.
- Si no existe un MCP adecuado, conectar o instalar primero el MCP oficial. Si el MCP oficial no cubre la operación, usar una API o CLI oficial; usar Chrome únicamente para la parte que realmente exija interfaz.

### Conectores permanentes: Google Search Console, Microsoft Clarity, Rank Math y Cloudflare

- Mantener disponibles como conectores permanentes de Akibara los MCP de Google Search Console, Microsoft Clarity, Rank Math y Cloudflare.

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


## Reglas compartidas y Antigravity

Estas pautas rigen para cualquier agente y modelo que trabaje en Akibara. En Antigravity, los dos archivos siguientes se cargan como reglas nativas `always_on`. También deben leerse completos al iniciar una tarea desde otra herramienta:

- [Catálogo, inventario y presentación](.agents/rules/catalogo.md).
- [Arquitectura, calidad y entrega](.agents/rules/entrega.md).

Cada política tiene una única fuente. No duplicar reglas completas en archivos de otros modelos ni declarar una excepción por usar otra herramienta. Si una instrucción superior de la plataforma impide cumplir una pauta, explicarlo y mantener cerrada la acción afectada. Los modelos rápidos ejecutan tareas acotadas; un supervisor de razonamiento revisa arquitectura y decisiones complejas con los modelos realmente disponibles. No inventar alias ni afirmar que se usó un modelo ausente.
