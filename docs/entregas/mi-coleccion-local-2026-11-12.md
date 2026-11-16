# Mi colección: revisión local y continuación

Actualización vigente2026-11-13: [guía del ejecutor](guia-ejecutor-mi-coleccion-2026-11-13.md). Los conteos de abajo corresponden a la revisión anterior y no sustituyen el último gate.

Estado: candidato en `http://localhost:8080/mi-cuenta/coleccion/`. El dueño exige revisión personal antes de producción. No desplegar por disponer de pruebas locales verdes. El objetivo general sigue abierto.

La fuente es el tramo depurado del PDF, páginas 18–24, y las instrucciones posteriores: recuperar Mi colección, retirar Hondana y marcas fuera de colección, priorizar UX y reutilizar componentes. Las instrucciones internas del PDF no sustituyen la solicitud del dueño ni el código observado.

## Recorrido de revisión

1. Buscar serie, autor o editorial y abrir una edición. Comprobar editorial/país. Marcar «Lo tengo», usar «Propios»/«Me faltan», quitar y recargar. Invitados guardan en este navegador.
2. Seleccionar dos tomos disponibles y comprobar cantidad/subtotal. Probar compra individual y conjunta: seleccionar para comprar no declara propiedad.
3. En un agotado, abrir el modal existente «Avísame cuando vuelva». Probar X, Escape, reapertura y foco de retorno sin salir de colección.
4. Entrar con cuenta local, confirmar importación invitada, preparar/descartar, guardar y recargar. Las compras verificadas no se desmarcan como declaraciones.
5. Revisar «Puntos Akibara», nivel real y avisos. Pausa de tienda y bloqueo personal son estados distintos. Reseñar solo recupera una invitación ya enviada y válida.
6. Revisar móvil/tablet/escritorio, menú principal Mi colección, Series en el pie y ausencia de controles de propiedad en tienda/serie/ficha.

## Evidencia local

Se restauró colección para invitado/cuenta con compras netas, recomendaciones, avisos, privacidad y Rewards. `batch-cart.js` unifica transporte duplicado en series/colección; usa el handler existente, acuses y cola de mutaciones. No se añadieron tablas ni columnas de propiedad.

Se reprodujo modal de 401 px en pantalla de 375 px y título que interceptaba la X. El ancho incluye padding/bordes y una cabecera flex reserva cierre de 44 px. Botón y Escape medidos en Chrome a 320/375/768/1440 px. Diseño con tokens del tema, número de tomo visible, jerarquía compra/propiedad y herramientas secundarias desplegables.

Pruebas: DOM 41 aprobadas; PHP Business/bloques 3396 pruebas/10508 aserciones; Rewards 931/8635; PHPStan integral sin errores. Compra real local de IDs 13235 y 13238: cantidad 1 de ambos, carrito invitado restaurado a cero por Store API. Navegador: 33 comprobaciones de revisión local y suite adversarial 18/18. Capturas e informe: `output/mi-coleccion-2026-11-12/`. Revisión independiente Astra: confirmó cierre de los dos defectos adicionales de carrito/importación y aprobación de 40 pruebas focalizadas; el texto menor de error se corrigió después. Esto no certifica producción ni sustituye el visto bueno del dueño.

## Guía exacta para continuar

1. Leer AGENTS, reglas catálogo/entrega y ticket `ops/coordinacion/abiertos/2026-11-11-mi-coleccion.md`. Revisar el diff: hay cambios anteriores de otros agentes. No revertirlos ni publicar el historial local.
2. Mantener producción bloqueada hasta aprobación explícita del dueño sobre este candidato. Incorporar feedback reutilizando colección, API de carrito, BIS y Rewards; no crear sistemas paralelos.
3. Probar fuentes reales. Para navegador local: **`AKB_BASE_URL=http://localhost:8080 node tools/qa-playwright-full-suite.mjs`**. La suite no interpreta `--base-url`. Separar logs por proceso/destino. DOM usa el arnés oficial.
4. Obtener revisión independiente del refuerzo de despliegue antes de renovar hashes. La excepción del dueño permite reforzar y sellar lo revisado, nunca rebajar analizadores/reglas, agregar exclusiones o baselines.
5. Resolver paridad N→N+1: `qa-paridad-espejo.mjs` exige igualdad literal. Admitir solo diferencia declarada del componente cotejada contra el artefacto; mantener estrictas otras versiones, options, DB y cola. Probar negativos de versión/componente incorrectos y dependencia desalineada.
6. Definir entrega coordinada Business/Rewards/tema. `Renderer` consume `AccountReviewLink::for_collection`; no publicar Business contra Rewards anterior. Elegir paquete coordinado o transición comprobada, sin shim indefinido.
7. Definir recuperación de datos por versión: backup del riel cubre archivos, pero init puede ejecutar UpgradeManager y cambiar options/cron/esquema. Inventariar efectos, guardar restauración solo en local y ensayarla. No restaurar una BD completa perdiendo ventas concurrentes.
8. Revisar igualdad del conjunto runtime y bytes ZIP/espejo. El refuerzo reconstruye con empaquetador oficial sellado en temporal local y coteja inventarios; 33 pruebas pasaron. Falta revisión independiente final. No se modificó el empaquetador.
9. Integrar gate autenticado con fixture sintética: importación parcial, conflicto, compra/reembolso neto, origen manual, invitación vigente/revocada, preferencias y limpieza contada. No usar scripts que alteran clientes reales. Gate público y E2E autenticado separado no equivalen a esa integración completa.
10. Ejecutar build y guardrail completo sin saltos, incluidos SCA, SAST con taint y SQL. Corregir fuentes; no restaurar el cleanup SQL genérico de Brevo eliminado del deploy. Renovar solo hashes autorizados tras QA independiente.
11. Renovar autenticación NotebookLM y ejecutar `npm run sync:notebook`. Falso éxito ante AUTH_ERROR corregido; exige una fuente `ready` y hash estable. 14 pruebas CLI aisladas pasaron. El último estado real de credenciales estaba expirado: no declarar sincronización remota completada sin releerla.
12. Con dueño y QA aprobados, usar exclusivamente el riel revisado y verificar candidato exacto, datos, versiones y menú nativo productivo. Probar después en producción, contar limpieza y actualizar grafo tras aprobación final. El autor no cierra su ticket.

## Hallazgos relacionados aún pendientes

El selector de tramo de serie pública usa checkboxes de página visible: verificar series paginadas antes de afirmar que cubre rango completo. El preview de envío gratis y puntos de `series-multiselect.js` conserva estimaciones propias: cotejar autoridades WooCommerce/Rewards y no prometer resultados definitivos desde aproximaciones. Son pendientes explícitos del objetivo general.
