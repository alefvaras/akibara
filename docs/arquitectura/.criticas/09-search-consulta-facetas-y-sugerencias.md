# Crítica de lector novato — 09 · search: consulta, facetas y sugerencias

> Quién escribe: alguien que llegó hoy al proyecto y no sabe qué es WordPress, WooCommerce ni el negocio.
> Qué hice: leí el capítulo entero y los seis diagramas, y después abrí el código del snapshot congelado
> (`…/scratchpad/snapshot-prod/plugins/akibara-search/`) para verificar todo lo que me sonó dudoso.
> Todas las citas `fichero:línea` de abajo son del snapshot, verificadas una por una.

---

## 1 · Lo que NO entendí

**1.1 — «Comprobante de publicación» y «comprobante de servido» parecen dos cosas y nunca se dice si lo son.**
El capítulo dice (línea 38): *«hay una segunda llave que el dueño no ve: el **comprobante de publicación**, un
recibo interno que afirma que los precios y las facetas publicados corresponden al catálogo de ahora»*. Doce
páginas después, en las bifurcaciones del Caso 1, aparece: *«Si lo caído es sólo el **comprobante de servido**,
Camila no ve el 503»*. Son dos nombres, uno solo está en el glosario, y nunca se dice si son sinónimos o dos
recibos distintos. **Debería decir** cuál es cuál con una frase: son el mismo recibo (`serving_ready()` /
`capture_serving_token()`), y el nombre del capítulo debe elegirse uno y usarse siempre.

**1.2 — El comprobante nunca se ata a un fichero, una clase ni una option, así que no puedo comprobarlo ni arreglarlo.**
Se menciona más de veinte veces («falta el comprobante», «se revalida el comprobante», «se republica y vuelve el
comprobante exacto»), siempre sin nombre propio. En el código es
`EditorialDiscountPublication::serving_ready()` (`src/Search.php:327` y `:352`) y
`EditorialDiscountPublication::capture_serving_token()` (`src/FacetQueryIntegration.php:174`), un fichero de
162 KB que el capítulo no nombra ni una vez. **Debería decir**: «el comprobante lo emite
`src/EditorialDiscountPublication.php`; `Search::suggest_enabled()` lo consulta con `serving_ready()`».

**1.3 — «El materializado» aparece sin haber sido presentado.**
Línea 61: *«Las rutas REST se registran con el configurado, para que con el **materializado** cerrado respondan
503 y no un 404 ambiguo»*. Es la única vez en todo el capítulo que aparece esa palabra, no está en el glosario y
no hay forma de saber si es el índice, el espejo de facetas o el comprobante. **Debería** usar el término ya
introducido (comprobante) o definirlo.

**1.4 — «Frontera viva» e «identificador rancio» se usan para explicar la decisión más medida del desplegable.**
Línea 203: *«el motor sólo conoce `visible` del índice y la **frontera viva** vive después, en el presentador;
pedir exactamente 7 hacía que cada **identificador rancio** dejara un hueco»*. Tres conceptos nuevos en una
oración, ninguno definido. **Debería decir**: «el índice guarda una foto del catálogo; entre foto y foto un
producto puede haberse despublicado. El presentador lo descubre al ir a buscarlo a WooCommerce y lo descarta,
dejando un hueco: por eso se piden 14 para mostrar 7».

**1.5 — «Canal estático» se usa tres veces y no está en el glosario.**
Línea 92 (`QueryEngine` → «Qué escribe»), línea 180 (`BrandRelax`) y el Caso 2. Para alguien que no programa en
PHP, «un canal estático por petición» no significa nada. **Debería decir**: «una variable que vive sólo durante
esa visita y que cualquier parte del código puede leer; es como el aviso viaja desde el motor hasta la plantilla
que lo imprime, porque en el catálogo no hay un valor de retorno que los una».

**1.6 — «Gate editorial» aparece en el panel y en el diagrama de estados, y no se explica.**
Línea 412: *«ahí **sí** nombra la causa, sacada de `editorial_gate_reason()`»*. Nunca se dice qué es un «gate»
ni por qué es «editorial» (¿tiene que ver con las editoriales de manga, Ivrea/Panini?). En el código no: es el
gate de la publicación de descuentos. **Debería** aclararlo, porque «editorial» en este capítulo ya significa
otra cosa (la marca del tomo) y el choque de nombres confunde de verdad.

**1.7 — «Faceta dueña del archivo» se usa como si ya estuviera definida.**
El glosario define «Archivo (de taxonomía)» pero no «faceta dueña». La primera aparición es en `FacetsView`
(*«no ofrece la faceta dueña del archivo dentro de su propio archivo»*) y se vuelve central en el agujero de
`product_tag`. **Debería** definirse: «la faceta que corresponde a la taxonomía de esa página: en
`/serie/one-piece/` la faceta dueña es *Serie*».

**1.8 — «Autoridad de fuentes» aparece una sola vez, sin antecedente.**
Línea 214 (`ProductPresenter` → Rendimiento): *«el calentamiento de la **autoridad de fuentes** no las
alcanza»*. Es `ProductSourceAuthority` (`src/ProductPresenter.php:47`, `:188`, `:251`, `:490`), una clase de
55 KB que el capítulo no presenta en ninguna ficha. Leyendo el capítulo solo no hay manera de saber de qué se
habla.

**1.9 — El salto lógico del Caso 10: por qué el panel valida distinto que el lector.**
El capítulo lo describe perfectamente («el validador es más estricto que el saneador») pero nunca dice **por
qué existen dos validadores**. Para un novato lo natural es preguntarse por qué el panel no llama al mismo
`configuration_is_canonical()` antes de guardar. El propio código lo explica en su docblock
(`src/Synonyms.php:151-154`: *«Valida que los bytes persistidos sean ya la forma exacta que consumirá el
motor»*). **Debería** incluir esa razón y decir cuál de los dos debe ceder.

**1.10 — «Poda» no se define y es lo único que el cron hace en esta área.**
Aparece en la tabla de interruptores, en el cron y en el Caso 10. Un lector sin contexto no sabe si «podar el
registro» borra búsquedas del cliente o estadísticas del dueño. **Debería** decir en la primera aparición:
«borrar del registro de búsquedas las filas viejas, para que la tabla no crezca sin fin».

**1.11 — «Sello de generación dentro del valor» se repite cinco veces y nunca se explica el truco.**
Aparece en `QueryEngine`, `BrandTokens`, `FacetService` y la tabla de transients. La explicación de por qué
«dentro del valor y no en la clave» sí está (las 23 copias vivas), pero recién en `FacetService`, muy después de
las tres primeras apariciones. **Debería** explicarse la primera vez, o remitir.

**1.12 — «Reconciliador» y «reconstrucción» se usan sin diferenciarse.**
El cron `akb_search_reconcile` «verifica que el índice y el catálogo digan lo mismo»; el panel tiene un botón
«reconstruir». No se dice si son lo mismo, si uno dispara al otro, ni cuál usar cuando el buscador está raro.
Para alguien que tiene que depurar esto de madrugada, es la pregunta número uno.

---

## 2 · Lo que FALTA para poder operar o depurar esto sin ayuda

**2.1 — Ninguna pantalla tiene ruta.** El capítulo dice «Buscador → Ajustes», «Buscador → Índice → Servicios»,
«Buscador → Facetas»… más de treinta veces, y nunca da la dirección. En el código la página es
`admin.php?page=akibara-search&sub=<slug>` con los slugs `indice`, `busquedas`, `facetas`, `precios`,
`sinonimos`, `stopwords`, `ajustes` (`src/SearchAdminSection.php:512-520`), y el menú cuelga de **Akibara →
Buscador** (padre `akibara-panel`), con un *fallback* a menú de primer nivel si `akibara-business` no registró
su menú (`:113-135`). **Debería** haber una tabla con las siete rutas exactas en la ficha de
`SearchAdminSection`.

**2.2 — No se menciona una sola herramienta de línea de comandos, y existen cuatro.**
`src/Cli.php:52-55` registra `wp akb-search rebuild`, `wp akb-search status`, `wp akb-search reindex <id>` y
`wp akb-search reconcile`. El propio panel las publica: el bloque se llama literalmente **«Diagnóstico técnico y
comandos CLI»** (`src/SearchAdminSection.php:1008`), y el capítulo describe esa pestaña sin decirlo. Es la única
forma de diagnosticar con el panel gris.

**2.3 — El hallazgo principal del capítulo no trae arreglo.** El Caso 10 termina con: *«El diagnóstico natural
es "alguien apagó el interruptor" y el arreglo natural —volver a guardar Ajustes— reescribe los interruptores
pero no toca el sinónimo»*. Ahí se corta. **Debería** cerrar con los tres pasos: (1) `wp option get
akb_search_synonyms` para ver los grupos; (2) volver a Buscador → Sinónimos y acortar la línea a 4 fichas
contando **todas** las palabras (no sólo las significativas); (3) decir explícitamente que
`ops/rescate-buscador.sh` **no** sirve aquí — ese guion rescata un rebuild atascado, no una foto semántica
cerrada.

**2.4 — El diagnóstico que nombra la causa existe en el código y no llega a ninguna pantalla; el capítulo no lo dice.**
`SemanticPublication::authoritative_error()` (`:128`) guarda el motivo. Lo consumen `QueryEngine`,
`FacetService`, `FacetRegistry` y `PopularQueries`, pero **`SearchAdminSection` no lo lee nunca** (verificado con
`grep authoritative_error` sobre todo `src/`). Y peor: en el caso del sinónimo el mensaje que se guarda es el
genérico de `:106` (*«Semantic option snapshot contains invalid bytes or values.»*), que **no nombra la
option**, porque `valid_raw_snapshot()` corta antes que la rama de `:116` que sí la nombraría. El capítulo dice
«ninguna de las dos nombra la opción rota»; el dato accionable es que hay un mensaje, que es mudo, y que basta
imprimirlo en Diagnóstico técnico para cerrar el incidente.

**2.5 — Las trece options vigiladas nunca se listan.** Se las nombra cinco veces («las trece options
vigiladas», «un solo byte inválido en una de las trece»). Están en `src/SemanticPublication.php:66-80`.
Sin la lista, el lector no puede saber qué escritura puede apagar la tienda. **Debería** haber una lista
literal, o marcar bien la columna «¿Vigilada?» de la tabla de options (hoy marca sólo doce; ver §3.4).

**2.6 — Los tres hooks de override no se nombran.** Línea 57: *«siempre exigiendo un booleano exacto del hook
de override»*. Los hooks son `akb_search_indexing_enabled`, `akb_search_suggest_enabled` y
`akb_search_facets_enabled` (`src/Search.php:376-380`). Son la palanca de QA para encender un servicio sin tocar
options, y sin el nombre no se pueden usar.

**2.7 — La gramática de la URL de facetas está incompleta donde más se necesita.** Se explica
`?_categoria=shonen,seinen`, `?_editorial=ivrea,panini` y `?_disponibilidad=a_pedido`. Pero **`_precio` y
`_novedad` no llevan valores, llevan un índice**: `novedad_join_condition()` exige `/^(?:0|[1-9][0-9]*)$/` y
resuelve `$buckets[(int) $idx]` (`src/FacetService.php:1032-1036`), con los tramos 7 / 30 / 90 días en ese orden
(`src/Novedad.php:43-56`). O sea `?_novedad=0` es «últimos 7 días». El capítulo menciona `_precio=1` de pasada
como «la cicatriz» y nunca dice qué significa ese 1. Quien quiera reproducir un bug no puede armar la URL.

**2.8 — El cron no tiene hora y el capítulo lo dice, pero no dice cómo verlo.** La fila dice *«Diaria, sin hora
fija»*. Falta la pregunta operativa: ¿cómo compruebo que está agendado? (`wp action-scheduler ...` o el grupo
`akibara-search`, `src/Indexer.php:50`), y que el memo `akb_search_reconciler_scheduled` dura 12 h
(`src/Reconciler.php:120`), así que borrar la acción no se nota hasta 12 horas después.

**2.9 — Los nombres de tabla están escritos como si el prefijo fuera fijo.** El capítulo usa
`wp_akb_search_docs`, `wp_akb_search_qlog`, etc. En el código son `Install::docs_table()` /
`Install::qlog_table()`, o sea `$wpdb->prefix` + literal. Si la instalación no usa el prefijo `wp_`, cada
consulta SQL que el lector copie del capítulo falla. **Debería** decirlo una vez.

**2.10 — Cinco componentes del `src/` no aparecen en ninguna ficha.** Comparando el `ls` del snapshot con las
fichas del capítulo: `EditorialDiscountPublication.php` (162 KB, es el comprobante; §1.2),
`ProductSourceAuthority.php` (55 KB; §1.8), `HealthReport.php` (27 KB — es lo que pinta «Índice → Servicios», y
se lo cita dos veces por línea sin presentarlo), `Cli.php` (§2.2) y `Arrival.php`, que `Search::register()`
engancha **incondicionalmente** (`src/Search.php:143`, «independiente de los flags»). Si el capítulo decidió que
esos cinco son de otra mitad, debería decirlo en una línea («viven en el capítulo 08»), porque hoy parecen
simplemente olvidados.

**2.11 — Falta el `return` temprano por esquema.** La ficha de `Search` enumera seis momentos en que actúa, pero
no menciona que `Search::register()` **sale antes** de cablear la purga y la indexación si
`Install::runtime_schema_ready()` es falso (`src/Search.php:172-174`). Es una tercera causa de «todo apagado»
que el capítulo no contempla y que no se parece a ninguna de las dos que sí describe.

**2.12 — El qlog descarta más de lo que el capítulo dice, y eso arruina las pruebas.** Se dice que se descartan
«robots y las sondas propias `akibara-qa`». El patrón real (`src/QueryLogger.php:141-144`) también descarta
`curl`, `wget`, `headless` y `phantom`. Consecuencia operativa directa: **una sonda con Playwright o un `curl`
de prueba jamás aparecen en Buscador → Búsquedas**, aunque uno crea que sí. Quien pruebe el radar del dueño con
esas herramientas va a concluir que está roto.

**2.13 — El pie de «Populares» arranca vacío y el capítulo lo avisa; falta el resto del mismo aviso para
el radar.** `DemandGaps` necesita filas con `hits = 0` acumuladas en 90 días. En un espejo recién sembrado la
pestaña Búsquedas sale vacía y no es un fallo. El capítulo sólo da ese aviso para Populares.

**2.14 — «Recortar a ocho» es cierto sólo en una de las dos superficies.** El capítulo dice, en `DemandGaps` y
en el Caso 7, *«recién ahí se recorta a ocho»*. El panel pide 8 (`src/SearchAdminSection.php:1176`), pero la
función puente que alimenta el Centro de Mando de business pide **10** por defecto
(`akibara-search.php:196-200`, `DemandGaps::rows( $wpdb, $days, $limit )` con `$limit = 10`). Como el capítulo
describe las dos superficies como la misma lista, conviene decir que los cortes difieren.

---

## 3 · Lo que CONTRADICE al código

**3.1 — «once handlers `admin_post_akb_search_*`» → son NUEVE.**
Línea 397: *«y **once** handlers `admin_post_akb_search_*` prioridad 10: `rebuild`, `reconcile_run`,
`save_facets`, `save_synonyms`, `save_stopwords`, `save_settings`, `save_precios`, `gap_dismiss`,
`gap_restore`»*. El propio capítulo enumera nueve, y el código registra exactamente nueve:
`src/SearchAdminSection.php:84-92`. **Debería decir** «nueve handlers».

**3.2 — «Treinta y cuatro componentes» → las fichas son TREINTA Y CINCO.**
Línea 48. Contadas por sección: llaves 3 + motor 7 + rescates 2 + desplegable 4 + facetas 10 + registro 5 +
panel 4 = 35. El diagrama `-piezas.mmd` dibuja también 35 nodos `:::propio` y su comentario de cabecera repite
«las 34 piezas». **Debería decir** treinta y cinco, en los dos sitios.

**3.3 — «Las catorce options de configuración tienen pantalla» → la propia tabla del capítulo lista quince.**
Línea 410. En la tabla «Datos que deja → Options» hay quince filas de configuración con pantalla
(`indexing`, `suggest`, `facets`, `fuzzy`, `sku_fastpath`, `novedad`, `popular_enabled`, `synonyms`,
`stopwords`, `facets_config`, `precio_buckets`, `precio_mode`, `relaxed_notice`, `empty_hint_catalog`,
`empty_hint_suggest`), más `gap_descartadas` y `audit_log`, que también tienen pantalla. El número no cuadra con
ninguna lectura razonable.

**3.4 — `default_product_cat` está marcada «—» en la columna «¿Vigilada?» y SÍ es vigilada.**
`src/SemanticPublication.php:76` la incluye en las trece. La tabla la describe como *«Option de WooCommerce; se
**lee** para resolver la categoría por defecto»* con «—». Esto no es cosmético: significa que **cambiar la
categoría de producto por defecto desde WooCommerce → Ajustes → Productos rota la generación del índice y puede
cerrar la foto autorizada**, o sea apagar los tres servicios desde una pantalla que ni siquiera es del buscador.
Es exactamente el tipo de trampa que el capítulo existe para contar, y la tabla la esconde. Además, con
`default_product_cat` marcada «—», la columna suma sólo doce «sí», contra las trece que el texto afirma cinco
veces.

**3.5 — «`akibara_search_suggest_active()` es la única que usa el predicado efectivo» → `akibara_search_facets_active()` también lo exige.**
Línea 22. `akibara_search_facets_active()` devuelve `FacetQueryIntegration::current() !== null`
(`akibara-search.php:94-96`), y `current()` sólo es no-nulo si además del flag configurado hay **token de
servido vivo**: `self::$current = null !== $this->request_publication_token && Search::facets_configured() ? $this : null`
(`src/FacetQueryIntegration.php:175`), con el token capturado por
`EditorialDiscountPublication::capture_serving_token()` (`:174`). Es cierto que es la única que llama a
`Search::suggest_enabled()`; no es cierto que sea la única efectiva. **Debería decir**: «suggest y facets son las
dos efectivas, por caminos distintos: suggest por `Search::suggest_enabled()`, facets por el token de servido
capturado en `init:24`».

**3.6 — `src/QueryEngine.php:139-157` apunta al docblock, no al código.**
Se cita así dos veces (ficha de `BrandTokens` y tabla de reglas, «Se suelta primero UNA palabra»). `:139-140`
son las líneas `@param`/`@return`; `relax_attempts()` empieza en **`:142`**. La cita correcta es `:142-157` —
y es justo el desvío que `verificacion.json` ya había señalado en el mapa (ver §4.4).

**3.7 — «Bajo contexto de archivo pide el ranking completo, sin techo (`:63`)».**
`src/SuggestController.php:63` es la apertura de un bloque `/**`. El código está en `:245`
(`$result = $this->engine->suggest( $query, $scoped ? QueryEngine::MAX_RESULTS : self::SUGGEST_FETCH );`).
Mismo caso, menor, en la tabla del Scorer: los niveles 1000/800/600/400/250/100 se citan todos como `:42`
cuando las constantes son `:42-47`.

**3.8 — La tabla del Scorer no dice que 1200 es un `max()`, no una suma, y que el bonus SÍ se suma encima.**
La tabla presenta 1200 como una fila más. En el código: `$score = max( $score, self::TIER_TOMO_NOMBRADO );`
(`src/Scorer.php:97`) y **después** `$score += self::tomo_bonus(...)` (`:99`), así que el documento nombrado
puntúa 1500, no 1200. Como la tabla lista el bonus como fila aparte, un lector cuidadoso puede deducirlo, pero
el texto de al lado («Le pone nota… y después desempata») no lo deja claro y el número 1500 no aparece nunca.

---

## 4 · Lo que contradice a `verificacion.json` del área

(`…/scratchpad/mapas/search-consulta/verificacion.json`, `cobertura_estimada: 78`)

**4.1 — El capítulo presenta el defecto N1 como medido; `verificacion.json` dice que nadie lo midió.**
Línea 205: *«**Efecto medible**: en "jujutsu kaisen ivrea" —el caso que justifica el módulo entero— no sale
ningún grupo Serie»*, y el Caso 1 lo narra como hecho consumado. Las `notas` del verificador dicen lo contrario:
*«nada está MEDIDO en el espejo — todo lo de aquí es lectura de código. La sospecha nueva N1 es la que conviene
medir primero»*. **Debería decir** «efecto esperado por lectura del código, pendiente de medir», o el capítulo
estará afirmando en producción algo que el proceso de verificación marcó como no probado.

**4.2 — Falta la confirmación S14 (docblock de `QueryEngine::suggest()`), que el verificador dio por buena.**
`sospechas_confirmadas_por_lectura`: *«`QueryEngine::suggest()` documenta dos claves y devuelve tres»*.
Verificado: el `@return` dice `array{ids, corrected}` (`src/QueryEngine.php:115`) y el `return` entrega
`ids`, `corrected` **y `relaxed`** (`:121-125`). Justo `relaxed` es la clave por la que el aviso de editorial
viaja «dentro de su propio retorno», que el capítulo destaca en el Caso 1. El capítulo no lo menciona.

**4.3 — Falta la parte BrandRelax de S14, y con ella la razón por la que hoy no rompe nada.**
`verificacion.json` lo confirma como deuda de contrato y explica por qué es inofensivo: todo impresor del
catálogo pasa por `FacetQueryIntegration::current()`, y `deactivate()` pone `self::$current = null`
(`src/FacetQueryIntegration.php:917-925`) antes de cualquier render, así que un `BrandRelax::last()` huérfano no
llega a ninguna superficie. El capítulo no lo cuenta, y sin eso un lector que lea la ficha de `BrandRelax`
(«canal estático por petición») no puede saber si es un bug vivo.

**4.4 — El capítulo hereda uno de los dos desvíos de cita que el verificador ya había marcado.**
`notas`: *«sólo hallé desvíos de docblock (QueryEngine:139 vs :142, FacetService:813 vs :821)»*. El capítulo
corrigió el segundo (cita `FacetService.php:821`, correcto) y **repitió el primero** (`QueryEngine.php:139-157`;
ver §3.6).

**4.5 — El capítulo repite «las cinco rutas REST» del verificador pero sólo documenta tres en la tabla.**
`notas`: *«las cinco rutas REST son suggest/popular/track/count/admin-status»*. La tabla de `SuggestController`
lista tres; `/count` aparece suelta en `FacetQueryIntegration`; y `/search/admin-status` se nombra una sola vez,
de pasada, en «Cuándo actúa» de `Search` — sin método, sin permiso (exige `manage_woocommerce` y nonce,
`src/Search.php:152` y `:195-200`) y sin decir que es lo que hace el panel para refrescarse. Falta una tabla
única con las cinco.

---

## 5 · Diagramas

**5.1 — Los seis validan con `mmdc`.** Verificado con `@mermaid-js/mermaid-cli@11.17.0`: `piezas`,
`estados-autoridad`, `cron`, `caso-editorial-equivocada`, `caso-filtrar-catalogo` y `caso-panel-apaga-la-tienda`
compilan a SVG sin error ni advertencia. Nada que arreglar ahí.

**5.2 — Pero ninguno se ve al leer el capítulo.** Los seis se insertan como imagen apuntando a un `.mmd`:
`![Piezas del capítulo](diagramas/09-search-consulta-facetas-y-sugerencias-piezas.mmd)` (líneas 18, 40, 448,
464, 515, 602). Ningún visor de Markdown —GitHub, VS Code, el navegador— renderiza un `.mmd` como imagen: el
lector ve un ícono roto. El capítulo entero se apoya en ellos («![Los tres estados de cada servicio]» sustituye
a la explicación en prosa de la máquina de estados). **Debería** ser un bloque ```` ```mermaid ```` en línea, o
un SVG generado y versionado, o al menos un enlace en vez de una imagen. (Es convención de todo `docs/arquitectura`,
así que el arreglo es transversal, pero este capítulo es de los que más lo sufren.)

**5.3 — `piezas.mmd` dibuja 35 nodos y su comentario dice 34.** Misma cuenta que §3.2. Además `Fuzzy` está en el
subgrafo `RESCATES` del diagrama y en la sección «El motor» del texto: un lector que busque su ficha por el
diagrama no la encuentra donde el diagrama la puso.

**5.4 — `piezas.mmd` no tiene el nodo del comprobante, que es la pieza que gobierna todo.** El texto dice
(línea 38) que por encima de los tres interruptores está el comprobante y por encima de todo la foto autorizada.
En el diagrama está `SEMPUB` (la foto) pero no hay ningún nodo para `EditorialDiscountPublication`, y las
aristas `SEARCH --> SUGC` / `SEARCH --> FQI` se dibujan como si el flag bastara. Falta también
`ProductSourceAuthority` (que el texto nombra en `ProductPresenter`) y `HealthReport` (que es lo que pinta el
estado que el diagrama de estados describe).

**5.5 — `estados-autoridad.mmd`: la transición de salida no tiene cómo ejecutarse.**
`Cerrado --> Activo: se corrige o se restaura la option rota desde el panel o por línea de comandos`. Es la
única salida del estado que el propio diagrama llama «la transición cara», y no dice qué comando. Con la
información del diagrama, alguien con la tienda caída no puede levantarla. Lo mismo en
`NoDisponible --> Activo: se republica y vuelve el comprobante exacto`: no se dice **quién** republica ni desde
dónde (¿un botón? ¿un rebuild? ¿solo?).

**5.6 — `estados-autoridad.mmd` afirma que el estado `Cerrado` apaga todo, pero el texto dice que el panel sigue vivo.**
El nodo dice *«Los TRES servicios caen juntos y el panel culpa al flag»*, y `Apagado` dice *«Cliente sin
desplegable ni filtros propios»*. El capítulo insiste (línea 36) en que *«el panel sigue estando disponible con
los tres apagados, a propósito»* (`src/Search.php:145-153`, verificado: `admin_section->register()` y la ruta
`admin-status` son incondicionales). El diagrama no lo dice en ninguno de sus cuatro nodos, y es justo el dato
que salva a quien entra a arreglarlo.

**5.7 — `caso-editorial-equivocada.mmd` se entiende solo y tiene todos los nodos que el texto nombra**, con una
excepción: el texto del Caso 1 describe la respuesta con `no-store` «y las dos cabeceras de CDN», y el diagrama
las resume en un paso sin nombrarlas (`CDN-Cache-Control` y `Cloudflare-CDN-Cache-Control`), que es lo único que
alguien necesitaría copiar para verificarlo con `curl -I`.

**5.8 — `cron.mmd` es el más autosuficiente de los seis** —dice el hook, la frecuencia, el disparador real de
Hostinger, lo que caduca solo y la trampa— pero le falta el mismo dato que al capítulo: **cómo se comprueba que
la recurrente existe**. Un `timeline` que explica que «diario» significa «en la primera corrida tras vencer el
plazo» debería terminar diciendo con qué se mira.

**5.9 — `caso-filtrar-catalogo.mmd`: la rama móvil dibuja el `/count` como si fuera el único camino.**
En el diagrama, «Con JavaScript en el teléfono» va a `/count` y de ahí directo al botón; el caso del texto
aclara que tildar la casilla **no** recarga y que los cambios se acumulan en el cajón. El diagrama no muestra la
acumulación, así que se lee como si cada toque navegara. Falta también la bifurcación de `product_tag`, que el
texto sí incluye en las bifurcaciones y es el defecto N5.

---

3 líneas de cierre:

Sin entender: 12 puntos (§1.1–1.12) — casi todos por términos que se usan antes de definirse: comprobante de publicación vs de servido, materializado, canal estático, gate editorial, autoridad de fuentes, faceta dueña.
Falta para operar: 14 puntos (§2.1–2.14) — los tres más caros: ninguna pantalla tiene URL, no se nombra ni una de las cuatro órdenes de `wp akb-search`, y el hallazgo principal del capítulo (Caso 10) no trae arreglo.
Contradicciones: 8 contra el código (§3.1–3.8; las duras son «once handlers» por nueve, «treinta y cuatro» por treinta y cinco, y `default_product_cat` marcada como no vigilada cuando sí lo es) + 5 contra `verificacion.json` (§4.1–4.5; la principal, presentar N1 como «efecto medible» cuando el verificador dice que nada se midió) + 9 de diagramas (§5.1–5.9; los seis validan con `mmdc`, pero ninguno se ve porque se insertan como `![imagen](*.mmd)`).

---

## Respuesta del redactor

**Los 44 puntos son correctos y los 44 están aplicados.** Verifiqué contra el snapshot congelado uno por uno los de las secciones 3 y 4 —las que afirman contradicciones— y no encontré ninguno en que el crítico se equivocara. En detalle:

- **§3.1** confirmado: `src/SearchAdminSection.php:84-92` registra exactamente nueve `add_action( 'admin_post_…' )`. Corregido a «nueve».
- **§3.2** confirmado: conté 35 fichas `####` y 35 nodos `:::propio`. Al aplicar §1.2, §1.8, §2.2 y §2.10 el capítulo pasa a **39** fichas y el diagrama a **39** nodos, y el número está actualizado en el texto, en el comentario de cabecera de `piezas.mmd` y en `resumen.json`.
- **§3.3** confirmado: la tabla lista quince options de configuración con pantalla, más dos operables que no son configuración. Reescrito: «quince», con las otras dos nombradas aparte.
- **§3.4** confirmado y es el hallazgo más valioso de la crítica: `SemanticPublication::options()` (`:65-79`) incluye `default_product_cat`, y sin ella la columna sumaba doce contra las trece que el texto afirmaba. La fila de la tabla ahora dice «sí» y explica que se opera desde WooCommerce → Ajustes → Productos. Además la lista literal de las trece está ahora en el cuerpo del capítulo (§2.5), en la tabla de reglas y en `resumen.json`, y el diagrama de estados lo dice en el nodo `Cerrado`.
- **§3.5** confirmado: `akibara_search_facets_active()` → `FacetQueryIntegration::current()` → `self::$current = null !== $this->request_publication_token && Search::facets_configured() ? $this : null` (`:175`). Reescrito como dos vías al mismo sitio, como pedía la crítica.
- **§3.6 / §4.4** confirmado: `:139-140` son `@param`/`@return`; `relax_attempts()` abre en `:142`. Corregido en los dos lugares.
- **§3.7** confirmado: `:63` abre el bloque de documentación y el código está en `:245`; las constantes del Scorer van de `:42` a `:47`. Corregido citando el código y dejando el comentario como referencia secundaria.
- **§3.8** confirmado leyendo `score()` (`:90-103`): `$score = max( $score, self::TIER_TOMO_NOMBRADO )` y **después** `$score += self::tomo_bonus(...)`. La tabla se partió en tres pasos —nivel, piso, ajustes— y el 1500 aparece explícito, en el cuerpo y en la tabla de reglas.
- **§4.1** confirmado en `verificacion.json` (`notas`: *«nada está MEDIDO en el espejo»*). Todo lo que era «efecto medible» sin fecha pasó a «esperado por lectura del código, todavía sin medir», en el cuerpo, en el Caso 1 y en el diagrama. Se agregó una sección final, **«Lo que falta medir»**, con los comandos concretos de las tres verificaciones pendientes; las mediciones que **sí** existen conservan su fecha y se distinguen explícitamente.
- **§4.2, §4.3, §4.5** confirmados y aplicados: el `@return` de `QueryEngine::suggest()` promete dos claves y entrega tres (`:115` contra `:120-125`), con `relaxed` como la clave omitida; `deactivate()` pone `self::$current = null` (`:917-925`) y por eso el `BrandRelax::last()` huérfano no llega a ninguna superficie; y las cinco rutas REST están ahora en una sola tabla con método, quién las registra, autenticación y techo por IP, incluida `/search/admin-status` con su `manage_woocommerce` y su nonce.
- **§5** aplicado entero. Los seis diagramas se insertan ahora como `![…](….svg)` con una línea *Fuente:* que enlaza el `.mmd`, que es la convención que ya usan los capítulos 02 y 13; los seis SVG están generados y versionados. Los seis vuelven a validar con `mmdc` 11.17.0 tras los cambios. `piezas.mmd` suma los nodos del comprobante, la autoridad de fuentes, el informe de salud y la línea de comandos, con aristas que dicen por qué el interruptor solo no basta; `estados-autoridad.mmd` dice los comandos exactos de las dos salidas y que el panel sobrevive en los cuatro estados; `cron.mmd` gana una sección de cómo comprobar que la recurrente existe; `caso-editorial-equivocada.mmd` nombra las dos cabeceras de CDN; y `caso-filtrar-catalogo.mmd` muestra la acumulación en el cajón y la bifurcación de `product_tag`. También se movió la ficha de `Fuzzy` del «El motor» a «Los dos rescates», que es donde el diagrama la ponía: ahora texto y diagrama coinciden en las siete agrupaciones.

**Tres desvíos menores de cita en la propia crítica**, que no cambian nada de lo que afirma y que corregí al escribir: la lista de options vigiladas es `SemanticPublication.php:65-79` (no `:66-80`); `akibara_search_facets_active()` está en `akibara-search.php:91-97` (no `:94-96`); y el `$limit = 10` de la función puente, en `akibara-search.php:189-196` (no `:196-200`). El registro del menú del panel abarca `SearchAdminSection.php:110-140`.

**Lo único que no se resolvió dentro del capítulo, porque no le toca:** §2.4 y §1.9 describen dos arreglos de **código** —imprimir `authoritative_error()` en Diagnóstico técnico y hacerlo específico, y que el panel valide con `configuration_is_canonical()` antes de guardar—. El capítulo ahora los documenta, dice cuál de los dos validadores debe ceder y por qué, y deja escritos los tres pasos manuales con los que hoy se sale del incidente, incluida la advertencia de que `ops/rescate-buscador.sh` no sirve para esto. Desplegar los arreglos es trabajo de otra sesión.
