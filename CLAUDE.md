# Akibara — mapa del repo

Tienda WooCommerce (akibara.cl, manga en Chile). **No es un repo git.**
Para decidir qué desplegar, compara **hash contra producción**, no `mtime`.

## Antes de nada

0. **REGLAS DE ORO DEL DUEÑO (2026-11-14 / 2026-11-15):**
   - **El código es la única fuente de verdad:** *«Siempre guíate por el código como regla de oro»* (orden del dueño, 2026-11-15). Toda afirmación, análisis y decisión debe basarse en la lectura directa del código fuente y datos reales, nunca en suposiciones o textos antiguos.
   - **Honestidad brutal y cero condescendencia:** *«Nunca debes ser condescendiente conmigo. Si algo está mal o consideras que está mal o hay alguna mejor opción, debes inmediatamente decírmelo desde el análisis. Tienes que ser brutalmente honesto.»* Cero complacencia, cero validaciones por cortesía. Si una idea o requerimiento tiene fallas, riesgos o existe una mejor opción técnica/UX/negocio, advertirlo directamente desde el análisis.
   - **Transparencia y exhaustividad total (Cero omisiones y cero selección sesgada):** *«Debes informarme de todo. No omitas pasos, no te saltes directrices ni cometas atajos sin informarme. En ciertos análisis escoges los que pasaron y eso no se puede repetir.»* Prohibido el cherry-picking o reportar solo lo que dio verde. Todo análisis, auditoría o prueba debe desglosar el 100% de los casos (aprobados, fallidos, advertencias y limitaciones). Nunca tomar atajos ni omitir pasos sin autorización explícita previa.

1. **REGLA DEL DUEÑO (2026-09-06, versión final):** *«Primero probar en local,
   y después, cuando esté el cambio en producción, también probar allá.»*

   **Las dos, en ese orden.** El espejo primero —ahí se descubre y se deja en
   verde—; producción después del despliegue, porque el espejo miente en cosas
   medidas (cron cada 5 min, Cloudflare, LiteSpeed, datos reales, cola de Action
   Scheduler). **Hasta que no está medido en producción, no está aprobado.**

   El dueño acepta caída en producción («más de 3 horas») como techo de riesgo,
   no como método. Detalle y las cuatro obligaciones que eso NO exime —punto de
   restauración, `ops/rescate-buscador.sh`, devolver todo a su estado original
   contando, y no mentirle al cliente— en `AGENTS.md`.

1. [`AGENTS.md`](AGENTS.md) — reglas operativas del dueño. **Vinculante.**
   Incluye «Cómo se prueba y quién da el visto bueno» (2026-09-06): las dos
   miradas —cliente y dueño—, cada checkbox y cada guardado, el interruptor
   encendido **y** apagado, la promesa contra el hecho, los correos verificados
   en la bandeja, y **quién cierra qué**: QA mide y levanta, desarrollo arregla,
   el asunto pasa a `esperando-respuesta` y **lo cierra QA tras re-medirlo**.
   El visto bueno para producción lo da QA, nunca quien escribió el arreglo.
2. **Coordinación, en este orden y cuesta tres líneas:**

   ```bash
   bash ops/coordinacion.sh abiertos               # qué hay abierto ahora
   bash ops/coordinacion.sh para <tu-sesión>       # qué te preguntaron a ti
   bash ops/coordinacion.sh chequear <ruta...>     # ANTES de editar; sale !=0 si está reclamado
   ```

   Protocolo y por qué existe: [`ops/coordinacion/README.md`](ops/coordinacion/README.md).
   El 2026-09-05 dos sesiones depuraron el mismo incidente durante horas porque
   ninguna leyó la fila de la otra en un tablero de 300 KB. `chequear` convierte
   «por favor lee el tablero» en algo que falla si no lo haces.
3. **Segundo Cerebro, Memanto y Sincronización NotebookLM:**
   - Al arrancar: ejecutar `memanto recall --recent` para recuperar decisiones y contexto activo (ADR-010, modelo binario 0 onbackorder).
   - Bóveda de Obsidian y decisiones: `docs/brain/` y en Google Drive `~/Akibara`.
   - Sincronización continua obligatoria: tras modificar código, contratos o documentación, y al desplegar a producción, ejecutar obligatoriamente `npm run sync:notebook` para sincronizar Google Drive y actualizar directamente todas las fuentes en el cuaderno "akibara" de NotebookLM.
4. El histórico está en [`ops/COORDINACION-historico.md`](ops/COORDINACION-historico.md).
   Es archivo: **no lo leas para saber el estado actual**.

## Código first-party (lo único que se puede modificar)

| Qué | Dónde |
| --- | --- |
| Plugin principal | `public_html/wp-content/plugins/akibara-business/` |
| Carrito y checkout (bloques) | `public_html/wp-content/plugins/akibara-cart-checkout-blocks/` |
| Buscador | `public_html/wp-content/plugins/akibara-search/` |
| Tema | `public_html/wp-content/themes/akibara-gp/` |
| 17 mu-plugins | `public_html/wp-content/mu-plugins/akibara-*.php` |

Todo lo demás (WooCommerce, GeneratePress, Rank Math, LiteSpeed, Blue Express)
es de terceros: **se toca sólo por hooks**, nunca editando su código.

## Despliegue

- Empaquetado y rieles: `tools/akibara-releases/`, `ops/*-promotion.sh` (49 scripts).
- Contratos sellados: `ops/contracts/` (35). Los rieles exigen versiones concretas;
  **desplegar un riel deja al otro pidiendo versiones que ya no están en prod**
  y el preflight muere sin mensaje.
- Los ZIP publicados viven en `dist/`. **La verdad de lo publicado es el ZIP**,
  no el árbol local — el árbol mezcla trabajo sin terminar.
- Acceso a producción: `ssh akibara`. Probarlo **primero** para cualquier cosa de prod.

## Entorno local

- Docker: `compose.yaml`. Validar aquí antes de tocar producción.
- Rank Math está inactivo en local **a propósito**.
- `wp eval` en prod: nunca disparar hooks de admin — WooCommerce fatalea y
  le llega un correo de recuperación al dueño.

## Trampas que cuestan caídas

- **Nunca dos workflows/agentes sobre el mismo plugin a la vez.**
- Los agentes de auditoría deben ser `Explore` (uno editó un mu-plugin de prod).
- Verificar siempre la **superficie servida** tras desplegar: el bundle `.min`,
  el `?ver` y la copia de LiteSpeed pueden dejar al cliente con código viejo.
- Cambiar el **nombre, slug o padre** de un término indexado dispara un rebuild del buscador
  → `suggest` en 503 ~6 min. Desde search 0.1.63 (prod 2026-09-14), pulsar «Actualizar»
  sin tocar esos tres campos ya no reconstruye ni marca productos (medido en el espejo con
  «Manga», 1.596 productos: sin pedido, cero deuda viva, suggest 20/20 en 200; falta medirlo
  en prod, donde Rank Math también engancha el guardado).
  (Un rebuild **pedido a mano** no: 1.631 docs en 30 s con el suggest en 200 todo
  el rato — se construye en tablas sombra. El coste está en el camino que lo dispara.)
- Cambiar un ajuste de **precios/descuentos** marca todo el catálogo como deuda viva, y el
  bombeo publica una generación por producto: mientras drena, el `suggest` alterna 200/503
  (medido 2026-09-14, deuda previa; se corrige en search 0.1.64). Un rojo suelto del QA
  tras tocar precios es esto: mirar `wp_akb_search_work` (scope `live`, pending/leased).
- **Buscador atascado, o «Ya hay un rebuild en curso» con la option vacía:**
  `bash ops/rescate-buscador.sh produccion`. El ORDEN importa y el guion lo respeta:
  suelta la sombra `_new` **primero**, retira `akb_search_rebuild_state` **después**.
  Al revés —lo que hacíamos hasta el 2026-09-06— el primer arranque de WordPress
  acuña `BLOCKED / orphan_topology_without_state`, que es un pestillo que ni el
  panel ni la CLI deshacen. Si aparecen tablas `_old`, el guion **para**: ahí `_old`
  es el rollback, no residuo.

## Verificación

- PHP: `tools/test-*.php` · Node: `tools/*.mjs` · lint: PHPCS/WPCS.
  Docker sólo monta `public_html`: copia el script al docroot para ejecutarlo.
- **Cada clase de prueba, sola: `bash tools/test-clases-aisladas.sh <business|search|rewards|gp>`.**
  PHPUnit carga todos los ficheros antes de correr ninguno, así que `--order-by=random` no
  detecta una clase que sólo pasa porque otra le cargó sus dobles. El 2026-09-19 eran 19 de 67
  en search: una, corrida sola, medía una lista vacía en vez del motor.
- **Navegador de verdad: `tools/akibara-e2e`** (`npm test` contra el espejo,
  `npm run test:prod` en sólo lectura contra akibara.cl). Nació el 2026-09-18,
  el día en que TODAS las fichas respondieron 500 durante 4 minutos con 3.472
  pruebas unitarias en verde: ninguna renderiza una plantilla. Lo que cambia una
  rama gateada por configuración se prueba ahí, no sólo en la suite.
- **Modelo de inventario binario estricto**: Akibara opera exclusivamente con `instock` (stock físico en bodega) y `outofstock` (agotado con captura «Avísame cuando vuelva»). Preventa, a pedido y arma tu pack han sido totalmente erradicados. Se valida siempre con `python3 ops/guardrail-inventario-akibara.py --target local`.
- Graphify es una **pista**, no la verdad.
  La verdad es el código y producción en vivo.
