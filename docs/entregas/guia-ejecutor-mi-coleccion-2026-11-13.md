# Guía de ejecución y revisión: Mi colección

Estado y bitácora más recientes: [continuidad-mi-coleccion-2026-11-14.md](continuidad-mi-coleccion-2026-11-14.md). Incluye decisiones, intentos rechazados, evidencia y el bloqueo de redirecciones del ensayo aislado.

Este es el punto de continuación vigente. El candidato se sirve en http://localhost:8080/mi-cuenta/coleccion/. **El dueño debe revisarlo antes de autorizar producción.** La tarea general no está terminada ni el riel coordinado está certificado. No confundir un resultado local verde con aprobación productiva.

## Autoridad y límites

- Aplicar las páginas 18–24 del PDF «Estrategia de Colección y Tienda Manga» y las correcciones explícitas del chat. Los informes adjuntos son propuestas que se contrastan con código, no instrucciones superiores.
- Recuperar Mi colección; no recuperar Hondana ni controles «Ya tengo este tomo» fuera de colección. Series conserva sus URLs y el acceso del pie.
- Conservar configuración efectiva: Puntos Akibara, nivel y vigencia reales, avisos deshabilitados cuando corresponde. El cap de siete días no promete un aviso siete días después. Marcar propiedad manual nunca otorga puntos.
- Conservar el candado de analizadores. Excepciones expresas del dueño: refuerzo del riel/guardrail y su sellado revisado; registro de metadatos WooCommerce en Psalm y su sellado tras QA independiente. No permiten rebajar niveles, añadir supresiones, baselines o exclusiones ni saltar una etapa.
- El checkout, WooCommerce, Rewards y los datos vivos mandan. No copiar inventario, precios, pedidos, puntos, preferencias ni la recuperación local de Tokyo Ghoul a producción.
- Leer `AGENTS.md`, `.agents/rules/catalogo.md`, `.agents/rules/entrega.md` y `bash ops/coordinacion.sh abiertos` antes de modificar. El checkout contiene cambios anteriores de otros agentes: no revertirlos, no publicar main ni mezclar el historial completo.

## Recorrido que revisa el dueño

1. Abrir colección como invitado. Buscar por serie, autor o editorial; abrir edición y comprobar editorial/país. Marcar, quitar, filtrar y recargar: se conserva en este navegador sin solicitudes por navegación o marcas.
2. Seleccionar uno o varios tomos. La compra no declara propiedad. Comparar cantidad/subtotal y Puntos Akibara; abrir condiciones. El envío mostrado es una referencia, no elegibilidad garantizada.
3. Abrir «Avísame cuando vuelva» en un agotado. Probar X, Escape, reapertura y retorno del foco. Se usa el modal existente y se permanece en colección.
4. Entrar con cuenta local: la importación del navegador se intenta automáticamente al iniciar sesión, únicamente sumando IDs. El navegador elimina sólo los IDs acusados por el servidor; un fallo conserva los pendientes y permite reintentar con Guardar cambios. Las ediciones posteriores de la cuenta requieren guardado explícito. Descartarlas no borra la importación invitada pendiente. Una compra verificada no se desmarca como declaración manual.
5. Probar One Piece: seleccionar un tramo que incluya productos fuera de la página. El resumen indica cuántos están en otras páginas. Las variantes no duplican un número en selección masiva. La selección fallida queda para reintento.
6. Revisar 375, 768 y 1440 px, teclado, detalles de condiciones y menú. En tienda, serie y PDP no aparecen marcas de propiedad. Revisar también la guía de reseñas de Puntos Akibara: requiere una invitación ya enviada y válida, no crea premios por marcar tomos.

## Correspondencia con la parte depurada del PDF

Se releyeron completas las páginas 18–24 del PDF original de 24 páginas. Esta tabla distingue el objetivo aceptado de las recomendaciones del documento que contradicen la configuración real o las correcciones posteriores del dueño.

| Propuesta del PDF | Aplicación y control exigido |
| --- | --- |
| Buscador dentro de colección con portada, edición y editorial | Catálogo público embebido y búsqueda en memoria. Se mejora la propuesta AJAX: buscar, filtrar, marcar y paginar no hacen fetch/XHR. Comprobar editorial/país para no mezclar ediciones. |
| Una fila de portadas por edición, foco en siguiente tomo y cinco ediciones por bloque | Carrusel por edición con geometría real, filtros y paginación local de cinco ediciones. La incorporación manual cambia la misma tarjeta y el progreso; no crea otra estantería ni una lista separada. |
| Acciones compactas, disponibles y precio total | Compra individual y selección múltiple con confirmación, subtotal y transporte común. Seleccionar nunca compra ni marca propiedad. Rango y selección masiva abarcan la edición completa, no sólo la página visible. |
| Avisos globales y por serie | Reutilizar GlobalToggle/CollectionToggle y la configuración SeriesFollow; mostrar controles sólo cuando el motor esté disponible. No activar avisos por defecto ni confundir cap de siete días con plazo de envío. |
| Comprado en Akibara frente a declaración externa | Compras netas Woo tienen prioridad y no ofrecen Quitar manual. Reembolsos, estados y reasignación deben medirse con pedidos sintéticos; una declaración externa no otorga puntos. |
| Reseña directa con foto y recompensa | Recuperar exclusivamente una invitación ya enviada, vigente y elegible mediante AccountReviewLink. Usar formulario y reglas existentes; no generar un token nuevo ni otro modal de reseñas. La cifra sale de RewardMessage, no de un +500 fijo. La entrega y revocación reales siguen siendo gates separados del arnés de propiedad. |
| Mi colección en el menú y vista invitada sin bloqueo de login | Acceso canónico desde menú. Invitado usa su navegador; cuenta recibe compras y estado privado. La sugerencia del PDF de marcar en catálogo/PDP fue revocada expresamente en este chat: allí no deben aparecer controles de propiedad. |
| Fusión automática al entrar | Intento automático add-only; ACK de IDs confirmados; fallo y acuse parcial conservan pendientes. El guardado de cambios de cuenta utiliza delta/revisión CAS. |
| Niveles en cabecera y carrito/checkout | Reutilizar Tiers::render_account_badge, Tiers::progress_label y el contrato de CheckoutIntegration. Cabecera privada para cuentas; no transmitir el nivel de otra sesión. No prometer aplicar a la compra actual el multiplicador de un nivel todavía no obtenido. |
| Niveles vitalicios y nombre Súper Fan | Es una recomendación comercial del documento, no una regla demostrada. Se conserva la vigencia efectiva y el nombre Residente de Akiba. Un cambio de política se decide aparte y no se introduce al rediseñar. |
| Guía de puntos breve y visible | Guía nativa de tres pasos, condiciones desplegables, saldo y progreso reales. Puntos Akibara es el nombre visible; no reconstruir el ledger ni duplicar cálculos. |

## Registro de persistencia del componente

No se añade una tabla ni columna de colección. Antes de introducir cualquier dato nuevo, el ejecutor debe demostrar su escritor, consumidor, invalidación y eliminación; si falta uno, no se incorpora.

| Dato | Propietario, uso y ciclo de vida |
| --- | --- |
| `_akb_collection_manual_owned` | CollectionStore escribe deltas confirmados; Renderer, CollectionOwnership y consumidores de recomendación/avisos leen la misma autoridad. PrivacyHandler exporta y borra por el store; WordPress elimina usermeta al borrar la cuenta. Se preservan IDs históricos de productos retirados para permitir quitar/exportar sin inventar productos. |
| `_akb_owned_gen` existente | CustomerCollection invalida por cambios de compras; CollectionStore lo comparte como revisión CAS, junto con la declaración manual en transacción. No crear otro contador paralelo de propiedad. |
| `akb_collection_catalog_v1` | Snapshot público versionado, sin estado de cuenta, con autoload apagado; catálogo y selección completa de serie comparten la lectura. Rebuild fuera del render y error explícito si no está disponible o quedó obsoleto. |
| `akb_collection_catalog_revision` | Se rota al invalidar; lectura fresca antes de publicar impide confirmar un build anterior. GET_LOCK existente excluye builders simultáneos. |
| `akb_collection_guest_v1` | Sólo localStorage del navegador; no se escribe en una cuenta invitada ficticia. ACK confirma qué IDs pueden retirarse; los no confirmados sobreviven. |

Se retiró `generated_at` del snapshot: tenía escritor y fixture, pero ningún consumidor. Las pruebas existentes de Snapshot/Renderer aprobaron 34/34. La reconstrucción nativa local ya eliminó esa clave serializada, conservando 121 series y 1769 productos; no se añadió migración runtime ni opción de compatibilidad. QA independiente cotejó también ausencia de la clave en una lectura SQL de sólo lectura sin bootstrap y ejecutó 86 pruebas/357 aserciones de AccountCollection.

## Contratos de código que el ejecutor debe conservar

Éstos son contratos existentes, no nuevas capas para copiar en otro componente. El cliente de cuenta ya envía este cuerpo desde `saveAccount()` hacia la URL que recibe en el bootstrap:

```js
{
    base_revision: account.revision,
    add_product_ids: delta.add,
    remove_product_ids: delta.remove
}
```

La importación automática usa sólo adiciones; el servidor decide la identidad con `get_current_user_id()`, nunca con un user_id del cuerpo o query. No convertir el flujo en un reemplazo completo de la colección. Un 409 con `data.state` conserva el delta sobre la revisión nueva y solicita revisión/confirmación; un éxito necesita manual_ids y revision válidos. El cliente utiliza acknowledged_ids para retirar marcas confirmadas; si el campo no está presente, contrasta únicamente las coincidencias con manual_ids/purchased_ids confirmados en esa respuesta. Ninguna presunción de éxito permite vaciar todo el almacenamiento invitado; se preservan los restantes y también los datos si falla su escritura local.

La compra se conecta al transporte común ya existente; no se implementa otro fetch de carrito dentro de una tarjeta:

```js
await window.akbBatchCart.add( {
    endpoint: config.endpoints.batch,
    nonce: config.nonces.batch,
    productIds: items.map( ( product ) => product.id )
} );
```

La llamada no es un permiso para ejecutar compras durante un QA de lectura. En el ensayo sintético, comprobar acuses added/already/failed, fallo HTTP, acuse incompleto y reintento parcial; el consumidor conserva selección fallida y el transporte coordina una sola actualización del carrito. Puntos confirmados se calculan con Promise::for_cart; la estimación usa el renderer compartido, nunca una tasa fija nueva.

Para el motor futuro, esta función **ya existe** y su conjunto exacto es obligatorio:

```js
loadReleaseSet( filename, [
    'akibara-business',
    'akibara-rewards',
    'akibara-gp'
] );
```

Su fuente es `tools/akibara-releases/rewards-qa/qa-paridad-espejo.mjs`; valida schema1/from/to/artifacts/requires. Ese código no instala el conjunto, no pausa escritores y no revierte datos. No inventar un comando público del coordinador a partir de esta firma ni declarar que la integración existe.

## Dueños del código: reutilizar, no duplicar

| Responsabilidad | Fuente que se debe utilizar |
| --- | --- |
| Catálogo público completo y revisión | Business `Modules/AccountCollection/CatalogSnapshot.php`; lectura cacheada, rebuild fuera del render, autoload apagado |
| Compras netas y propiedad manual | `CustomerCollection.php`, `CollectionOwnership.php`, `CollectionStore.php`; conservar bloqueo, transacción, CAS y acuse |
| Navegación y edición cliente | `assets/js/account-collection.js`; no crear otra API de búsqueda o propiedad |
| Un producto por tomo lógico | `assets/js/edition-products.js`, compartido por colección y serie |
| Compra individual/lote | `assets/js/batch-cart.js` y `SeriesBatchAtc.php`; cola común, validación nativa y acuses added/already/failed |
| Puntos y compra confirmada | Rewards `Points/Promise.php::for_cart`, `Calculator`, `Tiers`; Business `Services/PurchaseIncentives.php` y `purchase-incentives.js` para presentación estimada |
| Envío | `FreeShippingProgress::structured_state` y `FreeShippingThreshold`; nunca decidir elegibilidad solo con 60000 |
| Modal de reposición | `bis-form.js` y `CaptureForm.php`; no crear otro popup/toast con su propio ciclo de vida |
| SEO de cuenta | `Modules/SeoEnhancements/AccountEndpointMeta.php`, usando el endpoint/título nativo Woo |
| Recuperar reseña | Rewards `Reviews/AccountReviewLink.php::for_collection`; no regenerar invitaciones ni eludir VerifiedPurchase/ReviewEligibility |
| Migraciones | `Internal/UpgradeManager.php`; Plugin delega el arranque, no hay otra copia de tareas o purga de opciones |

## Cambios y evidencia comprobada

- Se recuperaron en local 30 relaciones de serie y autor: 14 Tokyo Ghoul y 16 Tokyo Ghoul :Re. Cada SKU se cotejó con captura productiva y un proceso independiente comprobó seis taxonomías, estructura de atributos y siete campos comerciales intactos. Snapshot: 121 series, 1769 productos, cero sin serie. Evidencia: `.local-backups/collection-series/qa-independent-20261112.json`.
- Rango completo con mapa de edición, no sobre los checkboxes visibles. Prueba sintética de 109 productos/19 tarjetas y medición real de One Piece en 375/1440. También se preservaron CTAs nativos de variables/no comprables/onbackorder y el estado hidden de Deseleccionar.
- Preview común, contexto privado, dos redondeos contra 260 oráculos PHP, sin peticiones al seleccionar. NativeCart publica estado confirmado solo tras respuesta fresca. Corregido foco diferido que podía robar la selección del cliente dentro del cajón.
- SEO, OG/Twitter y OG:url de colección/favoritos/rewards correctos; noindex se conserva. Guía de reseñas en tres pasos, con condiciones reales.
- Cierre BIS tras repintado: se reprodujo pérdida de foco cuando la colección sustituía el trigger por un estado de carrito actualizado; el ACK también podía actualizar un nodo desconectado. `currentTrigger()` resuelve el control vigente del mismo product_id y se comparte entre retorno de foco y confirmación. DOM: dos regresiones rojas antes, 6/6 verdes después; Astra verificó además desaparición sin reemplazo y respuesta tardía al abrir otro producto. Chrome375 pasó rojo→verde usando únicamente un evento sintético de estado de lectura, sin POST ni suscripción real. Recibos `bis-repaint-before.json`/`bis-repaint-after.json`. Esta prueba no certifica entrega de correos.
- Corregido el arranque con runtime antiguo: preserva versión/lease futuros y no repite migraciones ni retrocede el contrato Search. Esto no arregla retroactivamente un ZIP viejo ni restaura datos: el riel debe devolver markers y write-set antes de arrancar el paquete anterior.
- La limpieza de upgrade preserva nueve claves activas y familias completas NativeCart/microcopias. Retira solo 29 claves demostradas y EDA. Verifica nombres existentes en BD antes/después, usa delete_option nativo y lanza UpgradeRetryRequired si queda alguna fila o falla una consulta. No confiar en get_option después de un DELETE fallido: core puede haber poblado notoptions incluso ante el fallo. Si un upgrade anterior ya perdió un ajuste, exigir una preimagen real para recuperarlo: no inventar el valor a partir de defaults ni escribir producción sin su aprobación.
- Refuerzo del rollback: mantenimiento propio durable hasta purga confirmada; respeta mantenimiento ajeno. No garantiza por sí solo el cierre de contenido previamente cacheado en CDN.

Evidencia visual y QA: `output/mi-coleccion-2026-11-13/` y capturas anteriores en la carpeta del día 12. Los logs `/tmp/akb-*-20261113.txt` son evidencia temporal, no un permiso para saltar nuevas pruebas. Cada informe indica su alcance y si usó una respuesta sintética de lectura; no confundirla con una compra real.

Medición UI actual adicional: 15/15 en 375/768/1440, sin enviar compras, correos ni cambios de cuenta; búsqueda por teclado, selección de uno/dos tomos, condiciones, cierre BIS X/Escape/retorno de foco, objetivos táctiles y geometría. Recibo `ux-final-current-root-report.json`. La revisión anterior `ux-final-current-report.json` quedó incompleta por selector ambiguo que encontraba sección y formulario; se conserva como error de instrumento, no como defecto del producto. El control corregido usa la región nombrada. Esta medición es de root y no sustituye QA independiente ni aprobación visual del dueño.

Sincronización repetida: `npm run sync:notebook` generó los siete volúmenes locales bajo el límite, pero salió1 con AUTH_ERROR de NotebookLM por sesión vencida. No se afirmó sincronización remota completa. Los 14 controles aislados del instrumento de sincronización aprobaron; una sesión vencida sigue siendo un bloqueo externo real, no un test omitido.

## Orden exacto para el siguiente ejecutor

El orden distingue revisión local de certificación de una promoción futura. No ejecutar producción mientras el dueño no haya revisado la UI y aprobado después el candidato exacto. Un paso bloqueado se documenta con su causa; no se anula para seguir.

1. Leer esta guía, el ticket abierto y el diff. Consultar Graphify como índice y confirmar en fuente. Antes de editar, identificar al dueño de la responsabilidad en la tabla anterior y sus consumidores. No revertir cambios de otro agente ni crear una API alternativa.
2. Conservar el sellado de metadatos ya revisado. psalm.xml y hooks.json están 0444/uchg y registrados en el verificador de 27 entradas; no se pide autorización nueva ni se recalculan otros hashes por arrastre. Recibo `.local-quality/metadata-seal-20261113.json`. El verificador completo sigue rechazando tres hashes del riel pendientes: esa aprobación separada no lo certifica.
3. Ejecutar `npm run build`. No actualizar paquetes o pins. Comprobar que los archivos generados corresponden a las fuentes del candidato.
4. Ejecutar PHP por sus configuraciones completas: `tools/akibara-business/vendor/bin/phpunit -c tools/akibara-business/phpunit.xml.dist`, y las configs de `tools/akibara-search/`, `tools/akibara-rewards/`, `tools/akibara-gp/`. El filtro `--testsuite 'Akibara Business'` excluye Blocks y no es la configuración completa. Para una corrección aislada se puede empezar por el filtro afectado; no confundirlo con el resultado integral.
5. Ejecutar pruebas cliente con `tools/testing/dom-harness.js`, incluidas AccountCollection, SeriesBatchAtc/batch-cart, BackInStock, PurchaseIncentives y NativeCart. Los 53 casos focalizados anteriores pasaron; exigir resultado actual si cambian sus fuentes. Ninguna prueba de eventos, foco o ARIA se sustituye por regex sobre JS ni por Window/Document caseros.
6. Ejecutar `node --test tools/test-woocommerce-hook-metadata.mjs`. Deben pasar el positivo y los negativos por su causa exacta; no basta una salida de error genérica. Ejecutar también Psalm integral con taint sobre las seis raíces, sin cambiar configuración. El último resultado completo fue 0 errores y 1890 informativos visibles; los informativos no equivalen a ausencia de deuda histórica.
7. Ejecutar `node --test tools/test-qa-playwright-full-suite.mjs`, y después **`AKB_BASE_URL=http://localhost:8080 node tools/qa-playwright-full-suite.mjs`**. La suite no interpreta `--base-url`. Verificar el destino del log. Instrumento 20/20 y última ejecución local 18/18 aprobados. Conservar las dos formas válidas de breadcrumb; no imponer Product a una serie ni canonical indexable a cuenta privada. No simular la aplicación para obtener la certificación.
8. Completar y revisar el arnés existente `tools/akibara-releases/qa-customer-collection-account.sh` y `customer-collection/`. Astra rechazó la versión sobre el espejo compartido: stop cron no detiene WP-Cron/AS, diferencia temporal no prueba ownership y el trap podía perder recuperación o devolver éxito. El entorno futuro debe tener DB/Apache/CLI/Mailpit propios, red interna, fuentes RO y origen cerrado 8088; no tocar 8080/8025. Primero ejecutar `node --test tools/test-customer-collection-harness.mjs`, revisar controles negativos de aislamiento, y sólo después calcular `--print-freeze-sha`. QA independiente revisa esos bytes antes de permitir `--freeze-sha <digest-revisado>`. No reutilizar un digest anterior.
9. Al medir el arnés, comprobar scope y restauración: compras netas, refunds parciales/totales, estados y reasignación; guardado explícito/CAS/CSRF/aislamiento; invitado→cuenta add-only y ACK fallido/reintento. Revisión, compra por lote, entrega de invitaciones y avisos necesitan sus gates propios. No convertir un campo scope a true por intención. Retirar recursos efímeros exclusivamente por identidad/labels y probar que una limpieza fallida devuelve error y conserva rescate privado local. No borrar correos o acciones ajenos por ser nuevos.
10. Presentar al dueño el candidato de 8080 y el recorrido de revisión anterior. Se puede revisar la UI mientras se completan gates técnicos. Incorporar feedback local y repetir checks afectados; el silencio no aprueba producción ni cierra el ticket.
11. Preparar el preflight real de la entrega futura sin init. Antes de cualquier WP normal productivo, leer headers/bytes y estado de datos sin cargar plugins/MU; `wp eval --skip-plugins` aún los puede cargar. `wp db query` se ejecuta en after_wp_config_load según su documentación oficial enlazada en la auditoría; revisar también efectos de wp-config y no imprimir credenciales. Verificar from exacto, estado de schemas y trabajos; no inferir NOP por un marker ni copiar datos del espejo.
12. Implementar el motor coordinado conforme a `auditoria-riel-2026-11-13.md`: único dueño `ops/akibara-code-promotion.sh`, con primitivas compartidas revisadas y los negativos históricos preservados. **No existe hoy una promoción habilitada Business+Rewards+tema.** No crear un cuarto motor, encadenar tres deploys monocomponente ni ejecutar rieles históricos con sus contratos antiguos. Reutilizar `loadReleaseSet()` para from/to ascendente, requires, artefactos e inventarios estrictos. Las entradas anteriores deben rechazar explícitamente nuevas promociones que eludan el contrato común.
13. Derivar de la preimagen real un plan/journal por efectos: opciones con ausencia/autoload, rewrites/.htaccess, cron por identidad, AS por IDs, menu_order por ID y schemas afectados. El plan declara precondición, postimagen, inversa o compatibilidad probada y frontera de commit. Recuperación exclusivamente local, postimágenes/CAS para no pisar cambios concurrentes. No `.prev` remoto, no restauración de BD completa, no comandos arbitrarios ejecutables desde JSON. Un efecto no soportado detiene antes del swap.
14. Ensayar el conjunto exacto y su paquete anterior auténtico en Docker: cierre y drenaje de escritores, cambio completo sin bootstrap intermedio, migración, purgas comprobadas y reapertura. Inyectar interrupciones en cada frontera. Cubrir misma versión/bytes distintos, downgrade, from divergente, dependencia ausente, proceso previo vivo y fallo de rollback/purga. Los negativos no alcanzan escrituras vivas. Los 82 checks del parser no prueban una integración todavía inexistente.
15. QA independiente revisa el diff final y estos ensayos. Sólo entonces renovar los hashes autorizados pendientes y restablecer protecciones. Conservar preimágenes locales. Nunca resellar `ops` para ocultar su rechazo arquitectónico. Cualquier archivo protegido adicional no cubierto por las excepciones detiene ese cambio.
16. Ejecutar `npm run build` y **`bash tools/guardrail-ci.sh --full`** sobre el candidato final sin saltos. Deben correr PHP/JS, sintaxis/AST, PHPCS, SCA, PHPStan, Psalm taint, Rector, ESLint, Stylelint, Knip, Opengrep y SQL. Un fallo de red/auth es fallo; corregir fuente o dependencia real, nunca rebajar analizadores. Repetir QA adversarial sobre los mismos bytes servidos.
17. Ejecutar `npm run sync:notebook` después de cambios documentales/código. Verificar fuentes remotas ready y hashes; el CLI ya propaga AUTH_ERROR. Si requiere autenticación del dueño, informar el bloqueo concreto sin afirmar sincronización remota completa. No regenerar el grafo como estado productivo aprobado antes de que lo sea.
18. Sólo con UI revisada, riel ensayado, gates aprobados y autorización personal explícita del dueño para el conjunto exacto: promover por ese motor. Medir después en producción versiones/bytes/datos, comportamiento, cachés, suite adversarial y ausencia de temporales. QA independiente vuelve a medir y cierra; el autor del arreglo no aprueba su propia entrega.

Las protecciones físicas de los tres archivos del riel ya se restablecieron: guardrail/deploy 0555/uchg y runner QA 0444/uchg; el verificador conserva sus hashes anteriores y sigue rechazándolos. Los 27 archivos registrados están inmutables en kernel. Restablecer permisos no equivale a renovar un hash ni a aprobar ese código para producción. Recibo `.local-quality/pending-rail-protection-20261113.json`.

## Scripts de QA retirados

Se eliminaron `tools/test-prod-global-collection-and-checkout-qa.mjs`, `tools/certificar-prod-cuatro-decisiones.mjs` y `tools/qa-auth-visual-prod-real.mjs`: no tenían callers vigentes, mutaban cuentas reales productivas y usaban contratos retirados. No se ejecutaron. No reemplazar el cliente fijo por otro cliente ni conservar esos scripts como un modo “opcional”. La alternativa es el arnés sintético cerrado a localhost y DB local, con contrato negativo que impide reintroducir estas mutaciones.

La fixture de revisión UI anterior se retiró por API Woo nativa: un usuario, 23 metadatos y una sesión eliminados; cero pedidos, ledger, suscripciones, posts o comentarios antes/después. Evidencia: `.local-quality/collection-ui-cleanup-20261113.json`. No volver a usar el archivo privado de credenciales, que también fue retirado.

## Hallazgos fuera del componente: cómo verificarlos

| Hallazgo | Estado y siguiente control |
| --- | --- |
| Filtro FreeShippingProgress puede cambiar false→true en métodos core que exigen cupón | Control local real lo reprodujo con requires=coupon/both, pero no hay instancias core free_shipping en las zonas locales auditadas. Es un riesgo latente y una decisión comercial por contrastar; revisar zonas productivas/configuración efectiva antes de afirmar incidente o cambiar política. |
| Blue Express declara una propiedad dinámica plugin_path | Se observó deprecación PHP local del proveedor. Verificar versión oficial compatible con PHP del servidor y si afecta JSON/headers; no editar vendor con un parche ni actualizar producción de manera implícita. |
| Constantes de flags retirados y scripts QA históricos que todavía escriben flags sin lector | Inventariar consumidores/callers antes de retirar. Revisar AnalyticsAfterLoad::OPTION, LeanCart::OPTION, EdgePurge::ENABLED_OPTION, CacheWarmer::OPTION_ENABLED, CatalogOrder::OPTION_SHOWCASE y LandingPageSize::OPTION. No recuperar opciones sin uso solo para que un script antiguo pase. |
| Mensaje SAST «tracked by git» | Falso positivo descartado: JSON real incluyó los 11 PHP nuevos no trackeados; 471 archivos y ninguno de esos 11 omitido. El canario eval untracked fue rechazado. No añadir flags o exclusiones para corregir un problema que el control descartó. |
| Compatibilidad del formato futuro de leases | JSON y formato legacy actuales están cubiertos. Una futura versión que cambie formato debe probar rollback antes de escribirlo. No inventar hoy un formato ni borrar markers que el runtime no entiende. |
| Gate manual antiguo Rewards limpia Mailpit por diferencia temporal | `tools/akibara-releases/rewards-qa/qa-rewards.sh` puede borrar mensajes concurrentes ajenos; además limita el inventario a 2000 y convierte un error de lectura inicial en lista vacía. No se ejecutó y no se afirma pérdida real. Antes de reutilizarlo, trasladar sus escenarios al entorno/buzón efímeros comunes; probar API caída, más de 2000 mensajes y mensaje ajeno concurrente con un servidor sintético. No copiar ese algoritmo de limpieza ni reemplazarlo por otro patrón de asunto. Sus instalaciones npm sin lock también requieren revisión antes de admitirlo como gate actual. |
| Lista histórica del test del sello enumera 26 archivos | `tools/test-devsecops-seal.py` está 0555/uchg y se conservó intacto. Los controles específicos del JSON de hooks se integran en el test de metadatos existente, usando el mapa del verificador real; no retirar protecciones del test antiguo ni atribuirle cobertura física del archivo nuevo sin medirla. |

## Prompt acotado para un agente ejecutor

> Trabaja en `/Users/alefvaras/Documents/akibara`. Cumple esta guía en orden y reporta evidencia por paso. El código actual y las decisiones del dueño son autoridad; no deduzcas reglas comerciales de la distribución de productos. Antes de editar, identifica el dueño existente y los consumidores; reemplaza duplicaciones reales, no añadas otra capa por si acaso. Conserva datos, niveles y configuración efectiva. Producción está cerrada hasta aprobación personal y riel coordinado probado. Un fallo se reproduce con control y se corrige en fuente; no cambies analizadores para ocultarlo. No declares una etapa completa si falta su comando, destino, postcondición o revisión independiente. Ante un contrato que esta guía no resuelva, documenta prueba y alternativa concreta para el supervisor; no inventes campos, endpoints, tasas, descuentos, invitaciones o permisos.
