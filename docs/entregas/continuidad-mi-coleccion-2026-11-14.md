# Continuidad verificable — Mi colección — 2026-11-14

Este archivo permite retomar el trabajo sin depender del historial de un chat, de un proveedor de modelos ni de la memoria de un agente. Registra acciones, fundamentos técnicos, evidencia y límites; no contiene credenciales ni datos de clientes.

**Estado:** candidato funcional servido en `http://localhost:8080/mi-cuenta/coleccion/`. Producción no fue modificada y sigue sin autorización: el dueño debe revisar primero el diseño y el flujo local. El ensayo autenticado aislado todavía está rechazado por QA hasta corregir y medir el guard de redirecciones. No presentar la entrega como terminada ni resellar el deploy para poder continuar.

## Documentos de referencia y autoridad

1. Leer `AGENTS.md`, `.agents/rules/catalogo.md` y `.agents/rules/entrega.md`; consultar `bash ops/coordinacion.sh abiertos`. Las correcciones explícitas del dueño en este trabajo prevalecen sobre propuestas del PDF y reglas antiguas.
2. La guía operativa y el prompt del ejecutor están en [guia-ejecutor-mi-coleccion-2026-11-13.md](guia-ejecutor-mi-coleccion-2026-11-13.md). Contiene matriz PDF→decisión, responsables del código, persistencia y orden de ejecución. Este archivo es la bitácora de continuidad, no una segunda especificación.
3. El diseño de promoción y sus bloqueos están en [auditoria-riel-2026-11-13.md](auditoria-riel-2026-11-13.md). La revisión de los informes adjuntos está en [revision-informes-2026-11-12.md](revision-informes-2026-11-12.md).
4. El contrato del arnés aislado está en `tools/akibara-releases/customer-collection/README.md`. El ticket continúa en `ops/coordinacion/abiertos/2026-11-11-mi-coleccion.md`; el implementador no lo cierra.
5. El código servido y los controles reproducibles son la evidencia principal. Los resultados siguientes prueban los bytes y el alcance que describen; no autorizan ejecutar un código posterior sin nueva revisión.

## Decisiones del dueño que deben conservarse

- Aplicar la parte depurada del PDF original, páginas 18–24, mejorando diseño y funcionalidad mediante reutilización. Se releyeron esas siete páginas completas.
- Recuperar **Mi colección**, sin volver a introducir Hondana. Los controles de propiedad quedan exclusivamente en colección; no aparecen en catálogo, serie, PDP, buscador ni recomendaciones.
- Invitados usan almacenamiento del navegador. Iniciar sesión intenta una fusión automática add-only; sólo un acuse confirmado permite retirar IDs temporales. Las ediciones posteriores de cuenta tienen guardar/descartar explícitos y revisión CAS.
- Compras netas WooCommerce son autoridad y no se quitan como una marca manual. Marcar propiedad no genera puntos ni una compra.
- Reutilizar BIS y el carrito existentes, permitir compra de uno o varios tomos, mostrar **Puntos Akibara** y configuración efectiva. No fijar una recompensa, descuento, umbral o plazo comercial por copiar un ejemplo del PDF.
- Una reseña recupera únicamente una invitación ya enviada, vigente y elegible. No inventar invitaciones, tokens ni premios para que una prueba pase.
- Revisión personal local antes de producción. Las excepciones autorizadas de DevSecOps sólo cubren el refuerzo acordado y los metadatos WooCommerce/sellado revisado; no permiten rebajar analizadores ni eludir QA.

## Acciones realizadas y fundamentos técnicos

| Acción o decisión | Fundamento y evidencia | Alternativa descartada / límite |
| --- | --- | --- |
| Catálogo público embebido y snapshot con fencing | Búsqueda, marcas, filtros y paginación sin fetch/XHR; revisión invalida un build antiguo. Reutiliza criterios editoriales existentes. | No se añadió otra API de búsqueda por copiar la propuesta AJAX. El payload sigue siendo un coste medido, no se certifica coste cero. |
| Store manual con candado, transacción y CAS | Evita que dos sesiones sobrescriban cambios. Se conservan metadatos existentes y el historial de retirados. | No reemplazar toda la lista ni crear una tabla/contador paralelo. |
| Agrupación de tomos y compra comunes | `edition-products.js` y `batch-cart.js` sirven a colección y serie. Rango opera sobre edición completa, no sobre 19 checkboxes visibles. | No otro handler de compra por tarjeta; no truncar selección silenciosamente. |
| Incentivos y puntos compartidos | `Promise::for_cart`, Calculator y Tiers gobiernan confirmación; presentación común comprobada contra 260 oráculos PHP. | No un cálculo distinto por superficie ni prometer elegibilidad de envío antes de resolver dirección/método. |
| Reconstrucción local de 30 relaciones de serie/autor | Cada SKU cotejado con evidencia productiva; QA independiente de seis taxonomías, atributos y siete campos comerciales. | No se dedujeron títulos/atributos ni se copió este saneamiento local a producción. |
| UpgradeManager y limpieza de opciones corregidos | Protege markers futuros ante runtime antiguo; limpieza nativa preserva claves/familias activas y comprueba BD antes/después. | Restituir archivos viejos no revierte datos; no restaurar valores del dueño desde defaults inventados. |
| `generated_at` retirado del snapshot | Sólo tenía escritor y fixture, sin consumidor. Rebuild nativo local retiró la clave; 34 pruebas/158 aserciones y revisión independiente, luego 86/357 de AccountCollection. | No se añadió una migración runtime ni una opción de compatibilidad sin consumidor. |
| Sello de metadatos WooCommerce completado | JSON contra productores reales, AST y positivos/negativos; sólo entrada Psalm y alta JSON en el verificador. Tres tests actuales pasan. | No suppressions, exclusiones, baselines, cambios de nivel ni retoques de vendor. No equivale a sellado del riel. |
| Corrección adicional del cierre BIS | Un repintado desconectaba el trigger: dos regresiones DOM rojas, luego 6/6 verdes; revisión independiente Astra con dos negativos adicionales. Chrome375 reprodujo antes/después sin POST. | Resolver el mismo product_id vigente en un helper compartido; no enfocar ni marcar otro producto ante respuesta tardía. |
| QA sobre entorno efímero propio | El espejo compartido puede ejecutar cron/AS por visitas y recibir correos ajenos. DB/Mailpit/red propias evitan interferir con 8080/8025. | Rechazado detener sólo el servicio cron y borrar todo lo nuevo por tiempo. No constituye autorización de ejecución todavía. |
| Motor de promoción único propuesto | Reutilizar `ops/akibara-code-promotion.sh` y primitivas comunes revisadas de rieles históricos. Arquitectura Astra aprobó la dirección. | No cuarto ejecutor, tres deploys separados ni ejecución de contratos históricos como si fueran vigentes. Sigue faltando implementación/drenaje/journal CAS. |

## Resultados actuales y cómo reproducirlos

Trabajar desde `/Users/alefvaras/Documents/akibara`. Los logs `/tmp` son temporales; los recibos y capturas durables están en `output/mi-coleccion-2026-11-13/` y las recuperaciones privadas en `.local-*`. Si un log ya no existe, repetir el control seguro correspondiente; no inventar su resultado.

| Control | Resultado y evidencia |
| --- | --- |
| Build | `npm run build`: salida0. `/tmp/akb-build-current-20261113.txt`. |
| DOM cliente focalizado | 54/54, salida0. `/tmp/akb-client-dom-final-20261113.txt`. Incluye colección, selección serie, batch-cart, BIS, incentivos y foco de carrito. |
| Instrumento adversarial | 20/20. `/tmp/akb-qa-instrument-current-20261113.txt`. |
| Adversarial real local tras arreglo BIS | 18/18, salida0 y footer de certificación. `/tmp/akb-qa-adversarial-bis-repaint-20261113.txt`. |
| UI invitada actual | 15/15 en 375/768/1440, sin comprar, suscribir ni cambiar cuentas. `output/mi-coleccion-2026-11-13/ux-final-current-root-report.json`. Medición de root, no aprobación visual del dueño. |
| Repintado BIS | `bis-repaint-before.json` falla foco; `bis-repaint-after.json` lo recupera para el mismo producto. Evento de lectura sintético sobre navegador real, no una suscripción real. |
| Metadatos/sello | 3/3. `/tmp/akb-metadata-seal-final-20261113.txt`; `.local-quality/metadata-seal-20261113.json`. |
| Instrumento de sincronización | 14/14. `/tmp/akb-notebook-instrument-20261113.txt`. |
| Arnés aislado previo | 28/28 offline, también reejecutado por Astra. `/tmp/akb-harness-offline-final-20261113.txt`. **No es un E2E aprobado**; queda el defecto de redirecciones siguiente. |
| Análisis integral previo | Psalm taint0 errores/1890 informativos; PHPStan0 y otros análisis detallados en la guía/auditoría. No convertir esos resultados históricos en un guardrail final verde de bytes nuevos. |

Comandos seguros de comprobación de instrumentos:

```sh
node --test tools/test-woocommerce-hook-metadata.mjs
node --test tools/test-qa-playwright-full-suite.mjs
node --test tools/test-customer-collection-harness.mjs
```

La suite viva se ejecuta con destino explícito:

```sh
AKB_BASE_URL=http://localhost:8080 node tools/qa-playwright-full-suite.mjs
```

No interpreta `--base-url`; omitir la variable puede elegir otro destino. No ejecutar scripts retirados de QA productiva ni sustituir una cuenta fija real por otra.

## Bloqueo actual: redirecciones del arnés aislado

QA independiente **rechazó** `browser-instrument.mjs` SHA-256 `61e5989d92e5949c60e6ffdb74a69aa18495f9a2ff69f5d4962c70256390c516`. La ruta permitía `route.continue()` en8088, pero Playwright no vuelve a invocar el handler para los siguientes saltos de una redirección. Una cadena local podía alcanzar 8080 o un origen externo sin quedar bloqueada. Se confirmó en el código instalado de Playwright 1.62.1 y en su [contrato oficial de routing](https://playwright.dev/docs/api/class-page#page-route).

El positivo de routing con stubs no ejercitaba esa semántica real del navegador; por eso 28/28 no bastó. No se declaró verde el aislamiento, no se creó la base efímera y no se ejecutó el fixture.

Alternativas evaluadas:

- **Descartada:** seguir redirects manualmente mediante route.fetch y entregar sólo la respuesta final. Cambia URL/historial del login y puede ocultar defectos del flujo real. Validar sólo la primera Location tampoco cubre una cadena de varios saltos.
- **No elegida por coste:** otro proxy/servicio o nueva infraestructura de navegador. Antes se debe agotar un mecanismo del servidor efímero existente.
- **Dirección aprobada para implementar y probar:** guard de Location en el Apache efímero, conservando redirecciones locales nativas. Registrar y retirar una Location rechazada antes de enviarla, cubriendo tablas onsuccess y always. La configuración entra en el freeze y sólo existe en el entorno QA. El [contrato de mod_headers](https://httpd.apache.org/docs/2.4/mod/mod_headers.html) distingue ambas tablas; always no engloba todas las cabeceras existentes.

Este guard todavía necesita implementación y medición: no se presenta aquí como una solución ya certificada.

## Próximo paso exacto y condiciones de avance

1. Leer el diff del runner y `customer-collection/*`, especialmente environment, instrument, runtime-check y redact. No ejecutar `--freeze-sha` mientras el bloqueo anterior siga abierto.
2. El implementador termina el guard Apache efímero y sus negativos. No tocar `.htaccess`, PHP/config ni servicios del espejo8080 ni producción. No cambiar analizadores o el sello para admitirlo.
3. QA independiente revisa esos bytes. Antes de cualquier clon de datos se permite preparar una prueba del instrumento **sólo tras aprobación expresa de su alcance por el supervisor**: imagen Apache local exacta y contenido totalmente sintético, sin WordPress/DB/Mailpit. La autorización para esa prueba no se presume ni se extiende al fixture.
4. Medir destino prohibido con contador en cero, incluidos varios saltos locales y redirects de PHP/Apache. Positivos: cookie+login local,302/303 convierten POST→GET,307/308 conservan método/cuerpo. Un bloqueo debe hacer fallar el gate y quedar registrado; no silenciarlo en consola.
5. Después revisar el conjunto completo de instrumentos, ejecutar sus tests, obtener un freeze nuevo con `--print-freeze-sha` y releer recibos de imagen/config/política. Ese modo consulta Docker pero no crea recursos. Un digest de un candidato rechazado no se reutiliza.
6. Sólo con QA independiente aprobado, el supervisor habilita el ensayo local completo. Medir compras/reembolsos/estados/reasignación, guardado/CAS/CSRF/aislamiento, fusión invitada/ACK y privacidad. Retirar recursos propios por run ID, scope y daemon exactos, y comprobar ausencia. Si falla la retirada, preservar manifiesto privado local y salir distinto de cero.
7. No cambiar `batchPurchase`, `reviewSubmission` o `notificationDelivery` a true: ese arnés no los mide. Las compras por lote y la entrega de correos/invitaciones conservan gates propios.
8. Actualizar esta bitácora, la guía y el ticket con comando, destino, hash, salida, postcondición y quién revisó. Sin una de esas pruebas, registrar pendiente; no cerrar lo propio.
9. Ejecutar `npm run sync:notebook` tras el lote final de cambios. El último intento generó siete volúmenes locales bajo 380000 palabras, pero salió 1 con AUTH_ERROR por sesión vencida de NotebookLM. No afirmar sincronización remota completa sin ready/hash confirmados.
10. El trabajo de promoción sigue separado y bloqueado: revisión personal del dueño, motor coordinado implementado/ensayado, journal selectivo y QA independiente antes de renovar hashes pendientes o pedir una aprobación productiva del conjunto concreto.

## Protecciones, recuperación y cosas que no hacer

- Los 27 archivos registrados están uchg. Psalm/JSON/verificador quedaron 0444; guardrail/deploy 0555 y runner QA 0444. No retirar flags como atajo. Tres hashes del riel conservan deliberadamente su sello anterior: el verificador completo sigue fallando por esos candidatos no aprobados, no por metadatos.
- No volver a crear la fixture UI antigua ni leer su fichero de credenciales eliminado. Fue retirada por API Woo con conteos verificados; recibo `.local-quality/collection-ui-cleanup-20261113.json`.
- No restaurar una base completa ni borrar acciones/correos por diferencia temporal. El gate manual antiguo Rewards tiene ese riesgo documentado y no debe confundirse con el nuevo arnés aislado.
- Recuperaciones, manifiestos y estados privados permanecen sólo locales; no se suben a Git/Graphify ni se imprimen. Logs públicos de QA se redactan; no se graban trazas que capturen el segundo login del probe.
- No ejecutar contratos históricos de promoción, no hacer git pull dentro del webroot, no publicar main y no desplegar tres componentes uno por uno.
- El candado no se renueva para ocultar un defecto. Una prueba offline con servicios sustituidos certifica decisiones del instrumento, no comportamiento de WordPress, datos reales, entrega de correo o producción.

## Prompt de reanudación neutral

> Trabaja en /Users/alefvaras/Documents/akibara. Lee primero esta continuidad, la guía operativa, AGENTS/reglas y el ticket; coteja el código actual y sus hashes. La UI se sirve en 8080 y producción no está autorizada. No ejecutes el arnés aislado mientras siga rechazado por redirecciones. Termina únicamente el guard y los controles definidos, reutilizando los componentes existentes. Documenta fundamento, alternativa descartada, archivo dueño, reproducción, comando/destino/salida y revisión independiente de cada cambio; nunca inventes reglas comerciales, datos, interfaces o una certificación de otro modelo. Conserva scope y protecciones. Si un hecho histórico ya no se verifica, señálalo y mide el estado presente. Al terminar, deja este punto de continuación actualizado para cualquier proveedor de agentes, sin depender de tu chat ni de secretos personales.
