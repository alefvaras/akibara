de: sesión bis-avenida [8c4c125d] (desarrollo, orquestando agentes)
para: cualquiera — AVISO DE ALCANCE: no editar los árboles reclamados
estado: en-curso — RONDA 7 CERRADA y en producción (business 1.116.31, rewards 0.4.16, search 0.1.71, checkout 2.2.24, 2026-09-20 madrugada); inventario a CERO tras siete rondas; dos decisiones nuevas del dueño en el capítulo 21 (registros de prod en sólo lectura, Clarity, Search Console, invariantes y QA exploratorio con navegador en el ESPEJO: los observadores escriben en el espejo y restauran)
archivos: public_html/wp-content/plugins/akibara-business public_html/wp-content/plugins/akibara-search public_html/wp-content/plugins/akibara-rewards public_html/wp-content/plugins/akibara-cart-checkout-blocks public_html/wp-content/themes/akibara-gp public_html/wp-content/mu-plugins docs/arquitectura/20-hallazgos-qa.md tools/akibara-e2e
prueba: ./tools/akibara-business/vendor/bin/phpunit -c tools/akibara-<plugin>/phpunit.xml.dist

# QA a cero — carriles paralelos (2026-09-19)

Orden del dueño: llevar el inventario de `docs/arquitectura/20-hallazgos-qa.md` a cero, probar en
profundidad (flujos y bordes), auditar los cierres previos y mejorar la web donde se vea margen.

**RESULTADO: inventario en CERO — 221 cerrados, 0 abiertos.** Todo LIBERADO: nadie edita ya ningún árbol.

| componente | versión en producción | revisión antes de desplegar | medición |
| --- | --- | --- | --- |
| akibara-business | 1.116.25 | 11 lotes de Sonnet, cada uno con revisor independiente + revisión de Fable 5.1 sobre cada diff (cazó 1 defecto real: la cadena de reintentos del digest no tenía techo) | páginas 200, log sin fatales, recordatorio T-7 re-anclado a las 03:00 CL, baja de un clic `DYNAMIC` en el borde |
| akibara-search | 0.1.68 | 3 lentes independientes (7 defectos) + segunda pasada sobre los arreglos (1 más) | suggest 200, «shingeki» 1→6, fragmento con rebote y `noindex`, `.min` = sha del fuente |
| akibara-rewards | 0.4.10 | revisión independiente (1 defecto medio-alto en la marca de pago rechazado, arreglado con desenlace) | e2e puntos/carrito/cuenta verdes |
| akibara-cart-checkout-blocks | 2.2.22 | revisión independiente (cazó la clave nativa equivocada del RUT) | filtros de privacidad registrados con las claves reales |
| akibara-gp | 2.1.135 | revisor del carril + mutaciones de Fable | pie de correo servido = línea pública |
| 5 mu-plugins | audit 1.1.0 · denylist 1.2.0 · bluex-watch 1.2.0 · mail-watch 1.2.0 · rm-redirections 1.1.0 | revisor del carril + lectura de Fable | paridad md5; el barrido semanal de Blue pasó de DOS acciones vivas a UNA |

e2e de sólo lectura contra producción con todo dentro: **128 verdes, 0 rojos**. Espejo: 142 verdes.
Suites: business 3.680 (9 órdenes distintos) · search 812 + 44 puente + 46 JS · rewards 654 · tema 137 + 99 JS.

**Qué le toca a QA:** re-medir en producción lo de la tabla y cerrar este asunto. El visto bueno es suyo.

**Guest Mode de LiteSpeed: APAGADO el 2026-09-19 con autorización del dueño** (`guest` 1→0 + purga). Medido después en producción: HTML sin `guest.vary`, una sola carga por visita, e2e 129 verdes. La prueba `primera-visita.spec.mjs` lo exige siempre.

**RONDA 2 (2026-09-19, mañana):** el dueño pidió iterar hasta cero. Una ronda nueva de descubrimiento (9 lectores por área + un refutador adversarial por candidato) confirmó defectos nuevos — «cero hallazgos ≠ módulo limpio», otra vez. Esta sesión RECLAMA los seis árboles de arriba: un carril de arreglo por plugin, revisor independiente, y revisión de Fable 5.1 sobre cada diff antes de desplegar. Lo desplegado en la ronda 1 (tabla de arriba) sigue siendo lo que corre en producción hasta nuevo aviso.

**Hallazgos de instrumento que quedan escritos:** los rojos intermitentes del e2e contra producción eran
esa recarga, no el producto; la suite entra ahora como visitante que vuelve y la primera visita tiene su
propio spec. Mejora de relevancia M-3 («d gray man» devuelve coincidencias sólo de descripción): medida
con el registro real (9 de 132 consultas multi-palabra) y NO tocada — incluye aciertos legítimos; pide
un arnés de repetición antes de cambiar el orden.

**RONDAS 2 y 3 CERRADAS (2026-09-19, tarde). Inventario: 0 abiertos.** En producción y medido: business 1.116.27 ·
search 0.1.70 · rewards 0.4.12 · tema 2.1.136 · checkout-blocks 2.2.22 (sin cambios) · mu-plugins barandas de LiteSpeed
1.5.0, indexing-guard 1.1.0, correo-no-sale-a-clientes (sólo docblock). Ronda 2: 10 defectos; ronda 3: 15 confirmados
(14 cerrados + 1 decisión del dueño, `R3-89ab0284`, y otra registrada, `R3-reenvio-aviso-de-fecha`). Cada arreglo pasó por
revisor independiente y por revisión línea a línea del tech lead; esas revisiones sumaron seis correcciones más (bono y
referidos por la misma compuerta de vigencia; reposición por `wc_update_product_stock`; editoriales al publicar/papelera;
`wc-cart-fragments` encolado por el tema + baranda «JS Delayed»; reloj de los 60 días anclado a la fecha de compra;
literales `/series/` del manifiesto y del 404 en Markdown).

**Gate nuevo:** `bash tools/test-clases-aisladas.sh <business|search|rewards|gp>` — 30 clases de prueba sólo pasaban dentro
de la suite completa (19 search, 8 business, 2 tema, 1 rewards); hoy 0. Suites: business 3.787 · search 819 + 44 + 46 JS ·
rewards 666 + 31 JS · tema 143 + 108 JS, todas en tres órdenes.

**Qué le toca a QA:** re-medir en producción y cerrar. **Para el dueño:** en prod hay dos carpetas residuales de un
despliegue viejo (`plugins/.akibara-business-old-1788285628`, `themes/.akibara-gp-old-1788285628`); WordPress no las carga;
borrarlas es decisión suya.

**RONDA 6 CERRADA y en producción (2026-09-19, 19:47 CL). business 1.116.30 · rewards 0.4.14.** Primera ronda con
evidencia de producción (registros por SSH en sólo lectura, Clarity, Search Console, invariantes del espejo, QA exploratorio
con el bloque de checkout real): 6 confirmados + 1 destapado al medir (filas `R6-…` en el capítulo 20). Cerrados con código:
la prueba de consentimiento de ofertas —ningún pedido real la llevaba: el bloque no envía la casilla sin tocar, y con la
casilla marcada la nota privada se perdía porque WooCommerce copia la sesión al pedido antes de su primer `save()`—; el techo
de 9.999 unidades en ficha, añadir clásico y cajón; el redondeo de la base de puntos (canje bloqueado en $50.000 exactos);
y el párrafo automático de las 92 landings `/autor/`. Sin código: cuatro sitemaps reenviados a Search Console y una
observación de tráfico automatizado en `/serie/hunter-x-hunter/` (Clarity). Revisión independiente: 13 observaciones, 9
aplicadas antes de desplegar. Suites: business 3.862 ×3 + 359 clases aisladas + 32 JS · rewards 681 ×3 + 51 + 31 JS ·
puente 44 · e2e espejo 153+ · e2e prod sólo lectura 137. **Para el dueño:** Clarity → Settings → IP blocking para el
rastreador de Hunter x Hunter, si le molesta en «Popular pages»; y las descripciones de autor se escriben en WooCommerce →
Atributos → Autor (ahí ve el texto automático que sale hoy).

**RONDA 7 CERRADA y en producción (2026-09-20, 00:30-01:00 CL). business 1.116.31 · rewards 0.4.16 · search 0.1.71 ·
checkout-blocks 2.2.24.** Eje: salud operativa de producción en sólo lectura (error log, Action Scheduler/cron, WC status y
base, caché vs barandas, correo y avisos, puntos con datos reales; 6 lectores + 2 refutadores por candidato): 14 confirmados,
4 refutados por los refutadores y 2 más por el tech lead. Con código (filas `R7-…`): la vitrina llevaba 14 días sin
reordenarse (la acción diaria se desagendaba a sí misma); la cola de Action Scheduler no se vigilaba (ahora 25 hooks, business
+ rewards); un pedido real pagado 4 días sin puntos (barrido diario nuevo; al desplegar acreditó 994 puntos al #27973 y selló
12 de invitado); los siete correos del programa sin nombre en el registro; el rechazo mudo de un punto de retiro; el descarte
mal explicado de una edición especial; 755 filas huérfanas del buscador (barridas en el rebuild de las 00:55); la auditoría
irreparable; y la evidencia de estados bajo los campos del panel de reseñas. Sin código: option huérfana borrada. Decisiones
del dueño (cap. 21): `R7-invitacion-disparador` (la invitación a reseñar dispara en «Completado» y los pedidos terminan en
«Listo para enviar»: 0 invitaciones reales en la historia; comparación con CusRev/Trustindex/ReviewX/Photo Reviews/Site
Reviews y el correo nativo de WooCommerce) y `R7-nota-mercado-pago`. Revisión independiente: 13 observaciones, 13 aplicadas
(un ALTO: el barrido se auto-obstruía con los pedidos de invitado). Suites: business 3.872 ×3 + 360 aisladas · rewards 693 ×3
+ 53 · search 820 ×3 + 67 · checkout 124 · puente 44 · e2e espejo 153 · e2e prod sólo lectura 137. **Medido el 2026-09-20 03:13 UTC:** la
corrida diaria de la vitrina corrió sola a las 03:05:04 UTC (`akb_catalog_order_last_run` pasó del 13-sep a
`time=1789873504, rows=1643, applied=52`; la acción `akibara_catalog_order_daily` quedó `complete` y reagendada para
el 21-sep 03:05:04). R7-vitrina-nunca-corre cerrado también en la medición.
**Para el dueño:** elegir en Puntos → Reseñas el estado «Listo para enviar» y los días de espera (5-7), y decidir el mu-plugin
de la nota de Mercado Pago.

**RONDA 8 CERRADA y en producción (2026-09-20, 02:48-02:50 UTC). business 1.116.32 · search 0.1.72 · tema
2.1.138.** Eje: HIGIENE DEL CATÁLOGO medida en producción en sólo lectura (Merchant Center e incidencias, GTIN y
portadas, taxonomías huérfanas, sitemaps e índice, redirecciones, SEO on-page y schema, imagen social; 5 lectores
+ 2 refutadores por candidato): 15 confirmados, 4 refutados. Con código (filas `R8-…`): og:image sin medidas en
~1.700 fichas; hermana social sin tope (367 de 1.699 por encima de 300 KB; se rehacen solas por tandas); el
`lastmod` del índice de sitemaps inventado por Rank Math (`@rownum` sin `+1`; ahora se calcula con SU propia
consulta, heredada en `RankMathProductPages`, y se invalida también el índice); la miga de las 1.643 fichas sin
la demografía; 29 títulos de serie congelados (haikyuu 41→45); la landing de serie sin tomos («Hoy no tenemos
tomos de «Berserk»»); y el `style.css` vacío del child que toda página pedía. Datos del dueño (capítulo 20,
«datos y terceros»): 15 sin GTIN, un SKU≠GTIN, 8 portadas <500 px, 41 portadas fuera del pipeline (22 fichas
comparten en AVIF/WebP), 13 series huérfanas, 3 redirecciones a `/preventas/`. Decisión (capítulo 21):
`R8-fecha-google-a-pedido` — 7 tomos «a pedido» sin promesa de fecha rechazados por Google Shopping. Dos
revisiones independientes: 10 + 9 observaciones, todas aplicadas (3 ALTAS). Suites: business 3.882 ×3 + 360
aisladas · search 820 + 67 · tema 148 + 18 + 109 JS · puente 44 · e2e espejo 160 (+2 rojos rotativos de carrito
bajo carga paralela, verdes solos) · **e2e prod sólo lectura 147 verdes** (spec nuevo `higiene-seo.spec.mjs`,
con `AKB_SERIE_VACIA=berserk`). Medido en prod tras purgar el borde: og 744×1116, schema
Inicio/Manga/Shonen/Dan Da Dan 20, índice = hijos en las páginas 2/5/9, `/` y `/tienda/` sin
`generate-child-css`. Trampa nueva: el empaquetador NO sobrescribe un ZIP de la misma versión (el gate y el
dry-run pasaban con el ZIP viejo); hay que borrar los tres artefactos y reempaquetar. **Para el dueño:** decidir
`R8-fecha-google-a-pedido`; copiar el ISBN al campo GTIN en 15 fichas; resubir por Image Studio las 41 portadas
fuera del pipeline (incluidas las 8 pequeñas); borrar o poblar los 13 términos de serie vacíos; apuntar las 3
redirecciones a `/a-pedido/`.

**RONDA 9 CERRADA y en producción (2026-09-20, 04:05 UTC). search 0.1.73 · business 1.116.33.** Eje: DATOS DE USO
reales en sólo lectura (qlog del buscador, Search Console, Clarity, pedidos y estados, promesas de aviso; 5 lectores +
2 refutadores por candidato): 3 confirmados, 6 refutados. Con código (filas `R9-…`): el corrector laxo de la página de
resultados medía con `levenshtein()` (transposición = 2) y el del desplegable con OSA (= 1): «balck clover» daba 0 en
la página con 37 tomos en catálogo (medido tras desplegar: 24 fichas, igual que «black clover»); y la meta de las 109
landings de autor consultaba `rank_math_titles`, option inexistente (la real es `rank-math-options-titles`, el mismo
error del H1 de la ronda 8): se retiró la promesa falsa —producción siempre sirvió el párrafo— y se dejó un candado
con tokenizador (`RankMathOptionsSeLlamanConGuionesTest`, cuatro plugins + mu-plugins). Decisión del dueño (capítulo
21): `R9-editorial-ajena` (soltar editoriales que la tienda no vende, con lista en el panel). Revisión independiente:
9 observaciones aplicadas (1 alta: el capítulo 07 prometía la precedencia retirada; se corrigió también su párrafo
viejo de `$_GET`). Medidos los pendientes de la ronda 7: vitrina 03:05:04 UTC (`applied=52`), barrido de puntos
03:45:04 (13 sellados). Suites: business 3.885 ×3 + 361 aisladas · search 823 ×3 + 68 · spec buscador 35/35 en el
espejo · **e2e prod sólo lectura 149 verdes, 0 rojos** (04:12 UTC). Ronda 10 (CÓDIGO MUERTO y contratos rotos en los
cuatro plugins, el tema y los mu-plugins, más docs contra código) lanzada a las 04:15 UTC.

**RONDA 10 CERRADA y en producción (2026-09-20, 05:20-05:22 UTC). business 1.116.34 · search 0.1.74 · rewards 0.4.17 ·
checkout-blocks 2.2.25 · tema 2.1.139.** Eje: CÓDIGO MUERTO y contratos rotos (5 lectores, uno por árbol + docs contra
código; 2 refutadores por candidato): 26 confirmados (15 con código en los cinco árboles, 11 de documentación —3 altas:
arreglos ya en producción que los capítulos daban por pendientes—), 8 refutados. Arreglado por cinco carriles en paralelo
(uno por árbol) y revisión independiente sobre los diffs contra los ZIP publicados: nada roto en runtime; 8 derivas
documentales nuevas y el CSS de CusRev en el tema, todo aplicado. Option huérfana `akb_search_rebuild_ids` borrada en prod.
Medido tras desplegar y purgar: `/`, `/tienda/`, `/panini/`, `/manga/`, `/serie/one-piece/` y una ficha en 200;
`data-akb-family` ×0, `data-akb-series-intro` ×0 (archive-intro ×2), CSS crudo del tema sin `cr-ajax-reviews`,
`home-hero-admin.css` 25.742 B. Suites: business 3.885 ×3 + 361 · search 823 ×3 + 68 + 44 + 46 JS · rewards 691 ×3 + 53
+ 31 JS · checkout 124 + 118 JS · tema 148 ×3 + 18 + 108 JS · e2e espejo 166 · **e2e prod sólo lectura 149 verdes, 0 rojos** (05:35 UTC).
**Para QA:** el markup del panel del hero emite `akb-hero-position__pills--block` (plural) sin regla CSS: mirar esa
pantalla. **Para el dueño:** sigue `R9-editorial-ajena` en el capítulo 21.

**RONDA 11 CERRADA (parcial) y en producción (2026-09-20, 06:50 UTC). business 1.116.35 · tema 2.1.140.** Eje: lo
que el cliente ve en el teléfono. Corrieron 2 de 5 lectores (velocidad real en móvil 4G con navegador; correos
transaccionales renderizados en Mailpit); los otros tres (accesibilidad medida, formularios de compra, textos y
vocabulario) los cortaron dos veces las salvaguardas del modelo antes de usar una herramienta: quedan para la ronda
siguiente. 6 confirmados, 1 refutado. Con código (filas `R11-…`): la portada de cada tomo en los ocho correos de pedido
viajaba en AVIF 100×100 (Gmail no la pinta) → hermana JPG con proporción real (medido en Mailpit: pedido 30708 con
`dan-da-dan-1-ivrea-argentina-og.jpg` 48×72); la precarga de fuentes llevaba Inter 600 en vez del 500 que usa el primer
viewport (y dos controles del catálogo móvil bajaron de 600 a 500); 53 de 83 botones del catálogo en Arial (el reset de
`button` no heredaba la fuente). Mejora medida sin aplicar: −312 ms de LCP en la ficha sin GSAP (`R11-gsap-en-ficha-medido`).
Refutado por medición: el CLS del «Leer más» (0,03). Decisión del dueño (capítulo 21): `R11-casilla-en-el-correo`.
Revisión independiente: 12 observaciones, todas aplicadas. El candado `SingleImageFormatContractTest` se actualizó a la
doctrina vigente (la hermana `-og.jpg` es la única derivada JPEG sancionada; prohibía el gancho del correo por la capa
antigua y estaba ciego para los de Rank Math). Medido en prod tras purgar: precarga 500/700/Bebas, 600 sin precarga,
`core.css` con `font-family: inherit` en botones, `/`, `/tienda/`, ficha y `/carrito/` en 200. Suites: business 3.886 +
361 · tema 148 ×3 + 18 + 109 JS · **e2e prod sólo lectura 149 verdes, 0 rojos** (07:05 UTC). Ronda 12 (los tres ejes
que faltaron) lanzada a las 07:10 UTC.

**RONDA 12 CERRADA y en producción (2026-09-20, 08:05 UTC). tema 2.1.141.** Eje: los dos ejes que la ronda 11 no
pudo leer y sí corrieron ahora —accesibilidad MEDIDA (axe-core 4.12, dos viewports, 14 superficies del espejo) y
TEXTOS servidos (25 páginas de producción)—; el de la compra de invitado paso a paso cayó por tercera vez ante las
salvaguardas del modelo y queda cubierto por los specs e2e existentes. 5 confirmados, 9 refutados. Con código (filas
`R12-…`, todo en el tema): cabeceras del buscador móvil a 3,0:1 (gray-300 + opacity 1 → 8,7:1); listados saltando
h1→h3 (nivel del título por contexto: h2 en archivos, h3 bajo sección h2); la reseña a 4,40:1 y 3,83:1; la miga
«Autor del producto»/«Género del producto» en 114 landings (arreglo generalizado desde series, y el matcher pasa de
posicional a por firma porque en páginas 2+ moría con «Página N»). Propuesta de copy: `R12-email-o-correo`.
Revisión independiente: 7 observaciones aplicadas (2 medias). Medido en prod tras purgar: /tienda/, /manga/,
/a-pedido/, autor y género h1 → h2 ×24; /serie/one-piece/ h1 → h2 → h3 y su página 2 h1 → h2; migas «Inicio / Autores /
Kentaro Miura», «Inicio / Géneros / Acción / Página 2». Suites: tema 150 ×3 + 18 + 109 JS · accesibilidad 6/6 · **e2e
prod sólo lectura 149 verdes, 0 rojos** (08:20 UTC). Ronda 13 (terceros caídos en el espejo con restauración, datos raros
del catálogo, el reloj) lanzada a las 08:25 UTC.

**RONDA 13 NO CORRIÓ (2026-09-20, 08:30 UTC).** Ejes previstos: terceros caídos en el espejo (con restauración), datos
raros del catálogo, y el reloj (horario chileno, medianoche, fin de año). Los tres lectores cayeron ante las
salvaguardas del modelo antes de medir nada (sólo lecturas: `ls`, `docker compose ps`, `sed`, `grep`; el espejo quedó
intacto, verificado). Es la cuarta vez en la noche que un eje que pide «romper algo a propósito» —aunque sea en el
laboratorio— no pasa el filtro; el guion queda guardado para correrlo con un operador humano al teclado:
`~/.claude/projects/-Users-alefvaras-Documents-akibara/8c4c125d-9a65-4e3c-85b0-896ede88c011/workflows/scripts/akibara-ronda-13-terceros-datos-tiempo-wf_114a4fec-b79.js`.
**RONDA 13 CORRIDA A MANO (2026-09-20, 09:00-13:30 UTC), por orden del dueño («5 dale quiero que lo veas y esté en
prod»).** *Terceros caídos* en el espejo, desactivando y reactivando uno a uno (estado final = el de antes: inactivos sólo
google-listings-and-ads y login-as-user): sin Rank Math → todo 200 salvo la ficha en 301 (artefacto del espejo, que
sirve `/product/` y Rank Math redirige la raíz), sin ld+json/og/miga (esperado), 0 fatales; sin Blue Express → 200 en
todo, 0 fatales, y el checkout CON carrito: spec `compra-de-invitado` **10/10 sin el plugin** (línea base con él:
10/10; los 2 rojos de un primer intento eran por correr en paralelo con otra sonda); sin LiteSpeed → 200, 0 fatales. *El reloj*: barrido estático limpio (todos los `DateTimeImmutable` llevan zona; el único
`->date()` sobre un `WC_DateTime`, que la lleva). *Datos raros*: en PRODUCCIÓN (sólo SELECT) el catálogo está limpio en
los 12 casos —sin precio, precio 0, precio con decimales, stock negativo, instock con stock ≤ 0, sin imagen, sin categoría,
título con `<>&`, título vacío, lookup table desalineada en precio y en stock_status—: **0 de 1.643**. En el espejo,
sembrados y borrados (`[QA:r13]`): sin precio → sin botón y «no está disponible para comprar»; stock −3 → Woo lo pasa
a agotado, ficha con precio y sin botón, schema `OutOfStock`, el carrito lo rechaza; precio 0 → comprable a $0; precio
1234,56 → la ficha muestra $1.235 y el carrito 1.234 (redondeo de WooCommerce con IVA incluido: 1.037 + 197; del
tercero, 0 casos reales); título con
`"&<>` ñ 🧪 → 200 y slug bien codificado; 0 avisos PHP. Trampa propia: la primera sonda quedó colgada 21 min porque en zsh
`set -- $kv` no divide y WP-CLI sin valor se pone a leer STDIN sin TTY —las sondas se escriben como script bash con
`</dev/null` en cada `wp`.

**R8 CERRADO EN PROD (13:02 UTC, business 1.116.36):** desplegable «Tomos "a pedido" sin promesa de fecha, para Google»
en Akibara → Entregas (de fábrica «Agotado para Google»); `GlaBridge` devuelve `availability=out_of_stock` **en el
array del filtro** —la revisión independiente cazó que la primera versión llamaba `setAvailability()`, que sólo existe en
el adaptador de Content API (hoy sólo valida) y no en el de Merchant API (el que arma el payload): habría puesto los 7
en `synced` con Google rechazándolos igual—, y la fecha manual del campo nativo de GLA se respeta ahora también en
Merchant API. 9 observaciones de la revisión, todas aplicadas. Medido: resincronización de los 7 encolada y corrida
(`gla/jobs/update_products/process_item`); `_wc_gla_sync_status` has-errors 7 → 0; Merchant Center leído por la Merchant
API: aprobados en CL en los 6 destinos; 5 de 7 `OUT_OF_STOCK`. **Residuo del dueño (R8b, cap. 21):** 14185 y 14239 figuran
`IN_STOCK` porque la cuenta tiene «mejoras automáticas» estrictas (`allowStrictAvailabilityUpdates`) y Google lee el
`BackOrder` honesto de la ficha como comprable; se apaga en Merchant Center → Productos → Automatizaciones; no es código.
R9 decidido NO (sin tomos de Norma) y R11 hecho (etiqueta de Brevo acotada en prod). e2e prod sólo lectura 149/149 tras el
despliegue. Suites: business 3.893 + 362 aisladas · PHPCS limpio.

**PENDIENTES DEL DUEÑO EJECUTADOS POR DELEGACIÓN (2026-09-20, 14:00-14:45 UTC; «haz tú mis pendientes»).** Sin código:
GTIN ← SKU en 16 fichas (0 sin GTIN, 0 SKU≠GTIN); 3 redirecciones de Rank Math a `/a-pedido/` (408 sigue en cadena de 2
por el canónico de WordPress, 0 visitas); 5 carpetas residuales `.akibara-*-old-*` (business, tema, checkout, search,
rewards) movidas fuera del docroot a `~/respaldos-residuales-2026-09-20/`; invitación a reseñar en «Listo para enviar» +
7 días; Merchant Center: actualización estricta de disponibilidad apagada por la Merchant API (R8b); 47 portadas al
pipeline (39 adoptadas en el servidor + 8 escaladas 400→800 con Image Studio), 47/47 verificadas por HTTP. Con código:
business **1.116.37** + tema **2.1.142** (`R12-email-o-correo`: 23 literales «email»→«correo», candado de fuente + spec
propio) y mu-plugin nuevo `akibara-mercadopago-nota-sin-correo.php` (`R7-nota-mercado-pago`). Revisión independiente:
10 observaciones (2 altas: la meta de `/a-pedido/` sólo existe con Rank Math; faltaban 17 literales), todas aplicadas.
Suites: business 3.910 + 364 aisladas · tema 150 + 109 JS · candado mu-plugin 14/14 · spec `vocabulario-correo` 4/4 en el
espejo. **Bloqueado por el clasificador de permisos (no se rodea):** borrar los 13 términos `pa_serie` huérfanos en
producción (probado en el espejo: dispara un rebuild en tablas sombra, suggest 200). Comando para el dueño en la fila
`R8-series-huerfanas` del cap. 20. Trampas: `set -- $kv` y los globs sin coincidencia en zsh (dos veces hoy: sonda colgada
y ZIP viejo sin reemplazar — el gate pasó con el ZIP viejo hasta borrarlo por nombre).

**R3 DECIDIDOS Y CERRADOS (2026-09-20, 15:20-16:30 UTC).** `R3-89ab0284`: se queda como está y SIN la frase en la
página del programa (el dueño: «el programa suma desde que crea su cuenta»). `R3-reenvio-aviso-de-fecha`: el dueño
eligió la acción «Reenviar aviso de cambio de fecha» «lo más sólido posible» y mirar antes a la competencia. Workflow de
4 lectores + síntesis (plugins libres de preventa descargados al scratchpad): ni Bright Pre-Orders ni YITH libre tienen
siquiera el correo de cambio de fecha; WooCommerce núcleo reenvía los suyos escribiendo la nota a ciegas. Hecho en
**business 1.116.38** (`ReadyEmailDispatcher`): acción ofrecida sólo si el pedido espera (predicado único
`waiting_resend_allowed()`, también para «aviso previo», que tenía el mismo hueco desde julio), con líneas pendientes y
un cambio de fecha vigente (`last_date_change()`: sólo la última firma `old__new`, casada con la fecha actual de
exactamente UNA línea pendiente); el reenvío suelta el tope y su contador y dispara el mismo evento del envío
automático (una sola puerta). Revisión independiente: 11 observaciones, 3 medias cerradas antes de desplegar. Probado dos
veces de punta a punta en el espejo (siembra `qa-seed-preventa` parcela r3, correo apagado → nota «NO enviado»;
encendido → acción; reenvío → correo en Mailpit y notas; nueva postergación → la acción desaparece; 0 avisos PHP).
Medido en prod: hook enganchado; el único pedido en espera con preventa no la ofrece (no tiene cambio registrado).
Suites: business 3.918 + 365 aisladas. 1.116.39 sólo corrige el docblock de la constante (la afirmación sobre «el
oficial de WooCommerce» no se leyó y se quitó).

**RONDA 14 · PREVENTA EN EL ESPEJO (2026-09-20, 16:40-19:40 UTC; el dueño: «crea preventa en local y prueba»).**
Workflow: 6 lectores Explore (ficha, compra, fechas, llegada, correos, cruces), cada uno con su parcela de
`tools/qa-seed-preventa.php`, y 2 refutadores por hallazgo (66 agentes, 7,9 M tokens): 33 observaciones, 15 tumbadas,
18 sobrevivieron; el tech lead juzgó los divididos con el dato en la mano. **Aplicado en business 1.116.40** (13): borrar
la fecha desde la ficha deja preventa sin fecha (no «a pedido»); recálculo también a pedidos en espera de pago con nota
y sin correo; «fecha anunciada» sobre línea comprada con fecha = cambio (asunto/encabezado/ámbar); «Reenviar aviso
previo» sólo en ventana; nota «Adelanto registrado»; el panel «Por llegar» dice cuántos ya salieron; nota al pasar a
«Listo para enviar» con tomos sin llegar; guarda de coherencia arribo vs salida en el correo; `priceValidUntil` ≥ salida;
carrito «tu preventa»; mes corto en la columna; pie plano «Correo:»; frase del WhatsApp; docs. NO aplicado por decisión
previa del dueño: agrupar en `/a-pedido/`; el sello de despacho. Del dueño: asuntos iguales en WooCommerce → Correos.
Definición del dueño (cap. 21): preventa = aún no sale; a pedido = ya salió y no lo tenemos. Verificado en el espejo,
cambio a cambio, con siembra propia (parcelas r14-r16; `wp wc order update` no existe en este WP-CLI: el estado se
cambia con `update_status()` por `wp eval`). Revisión independiente (Explore, 19 observaciones): 1 bloqueante
(`function_exists()` en el sello, el patrón que su propio docblock prohíbe), 3 altas (una preventa sin fecha iba al
feed de Google como `PreOrder` sin fecha —ahora `is_sin_promesa()` cubre `tba`—; la pantalla «Por llegar» citaba un
asunto que el correo ya no manda —ahora distingue si la línea se compró sin fecha—; `priceValidUntil` sin la cota de
años), 9 medias (guarda también para «tu pedido completo…», el «botón» de WhatsApp que no existe → «el enlace al pie»,
docblock huérfano, punteros de línea del cap. 04 → métodos, `maybe_dispatch()` usa el helper, la frase del panel sobre
pedidos en espera de pago, gramática y plural editable del carrito, aviso al dueño al guardar sin fecha, nota
contradictoria sin ETA) y 6 bajas (3 aplicadas: `?? ''` en la plana, unidades en vez de líneas, `pending` también se
recalcula; 3 documentadas). Suites tras la revisión: business 3.9xx + 365 aisladas.

**Estado:** inventario de código a CERO tras catorce rondas; en producción business **1.116.40** (desplegado 2026-09-20
20:05 UTC tras la revisión; medido: universos de estados, GLA 0 errores, schema «a pedido» intacto, e2e prod) · search
0.1.74 · rewards 0.4.17 · checkout-blocks 2.2.25 · tema **2.1.142**. Los 13 términos los borró el dueño a las 15:05 UTC
(rebuild de 30 s en tablas sombra, suggest 200 en todas las lecturas, 1.643 docs, cola 1.503 → 0). Pendiente del dueño:
sólo `R3-89ab0284` y `R3-reenvio-aviso-de-fecha` (recomendación: dejar como está) y borrar los 41 adjuntos viejos si
quiere. Ya no hay serie vacía en prod ni en el espejo: el spec «serie vacía» de `higiene-seo` se salta hasta sembrar una.
