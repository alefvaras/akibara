# Crítica de lector novato — capítulo 14 (mu-plugins A: abilities/MCP, Blue Express, Brevo, Clarity)

> Quién escribe: alguien que llega hoy al proyecto y no sabe qué es WordPress, qué es WooCommerce ni
> cómo se vende manga en Chile. Leí el capítulo entero y después abrí el código del snapshot
> (`scratchpad/snapshot-prod/`) y los terceros del árbol (`public_html/wp-content/plugins/`) para
> comprobar lo que me sonó raro. Todas las citas de abajo las verifiqué una por una.
>
> Antes que nada, lo bueno, porque es mucho: la estructura «qué hace en una frase / cuándo actúa / qué
> lee y escribe / dónde lo opera el dueño / qué ve el cliente / lo que conviene saber» es exactamente
> lo que un novato necesita, y las secciones «Lo que conviene saber» son honestas hasta el punto de
> delatar defectos del propio código. Casi todas las líneas citadas calzan. Lo que sigue es lo que me
> dejó colgado o lo que no aguantó la comprobación.

---

## 1 · Lo que NO entendí (término sin explicar, salto lógico, referencia a algo no presentado)

**1.1 — No sé cómo se llega a ninguna de las tres puertas.**
El capítulo dice (§ intro): «Un adaptador llamado **MCP** publica esas operaciones por internet para que
un agente de inteligencia artificial las use», y la tabla del §10 lista
`POST /wp-json/mcp/mcp-adapter-default-server`. Pero nunca dice **con qué credencial** se entra ni desde
dónde. Un novato no puede ni comprobar el veto ni correr el barrido.
*Debería decir*: que se entra con una **contraseña de aplicación** de un usuario administrador
(`wp user application-password create <usuario> "<etiqueta>" --porcelain`, se revoca en Perfil →
Contraseñas de aplicación), y que el servidor por defecto expone sólo **tres herramientas**
(`mcp-adapter/discover-abilities`, `get-ability-info`, `execute-ability`), siendo la tercera el
ejecutor genérico de cualquier ability con `mcp.public = true`
(`seo-by-rank-math/vendor/wordpress/mcp-adapter/includes/Servers/DefaultServerFactory.php:43-57`).

**1.2 — Las rutas de las citas no se pueden abrir.**
El aviso de cabecera dice «Los plugins de terceros se citan desde el árbol del repositorio», y luego
todo se cita como `plugins/woocommerce/...` o `mu-plugins/...`. En el disco eso vive en
`public_html/wp-content/plugins/...` y `public_html/wp-content/mu-plugins/...`.
*Debería decir*, una sola vez arriba: «todas las rutas son relativas a `public_html/wp-content/`».

**1.3 — El capítulo se contradice a sí mismo sobre si el barrido semanal del vigía existe.**
§2 dice: «La reprogramación exige `is_admin()` o `wp_doing_cron()` (`:135`). En producción el cron lo
empuja el hosting por WP-CLI, donde **ambas son falsas**». Yo entendí «entonces nunca se programa». Pero
la tabla de «Lo programado» lo lista como trabajo vivo («Semanal; primera a la hora de armado + 1 h») y
hasta se preocupa de qué pasa «si corre dos veces». ¿Cuál de las dos es?
*Debería decir*: que `is_admin()` **sí** es verdadera en el escritorio, así que la acción **se arma la
primera vez que el dueño entra a `/wp-admin/`**, y desde ahí Action Scheduler la reprograma sola; lo que
falta es la **red de reparación** (si alguien la borra, el cron de producción por WP-CLI no la vuelve a
crear, sólo una visita al escritorio).

**1.4 — «sin grupo y sin `unique`» no está explicado.**
Aparece en §2, en la tabla de «Lo programado» y en las reglas, y es uno de los defectos centrales del
capítulo. El glosario define «Action Scheduler» pero no **grupo** ni **`unique`**.
*Debería decir*: grupo = etiqueta con la que se agrupan y se buscan las acciones en WooCommerce →
Estado → Acciones programadas; `unique = true` = «si ya hay una igual programada, no crees otra».

**1.5 — «rearme exprés» es una instrucción que no puedo ejecutar.**
§2: «bajar las clases ausentes del repositorio público de Blue a `mu-plugins/bluex-fallback/` y recrear
el guard de carga condicional en `plugins_loaded:99`». No sé qué es un «guard de carga condicional», no
hay fichero de ejemplo, y la notación `plugins_loaded:99` («gancho:prioridad») no se explica en ningún
lado —aunque se usa en todo el capítulo (`init:30`, `init:20`, `rest_api_init:101`)—.
*Debería decir*: explicar la notación `gancho:prioridad` en el glosario, y remitir a la sección concreta
de `COORDINACION.md` (el correo dice «sección del ciclo Blue Express»; el capítulo no la nombra).

**1.6 — «invariantes byte-idénticos del carril de publicación» (§9) no se explica ni se remite.**
Es la única justificación que se da para vetar `rank-math/set-plugin-preferences`. Un novato no sabe qué
es un «carril», ni por qué una auto-actualización lo rompe.
*Debería decir*: una frase («los despliegues comparan el ZIP publicado byte a byte contra producción; si
el plugin se actualiza solo, la comparación falla y el despliegue se detiene») y el capítulo donde vive.

**1.7 — «el espejo» aparece nueve veces y nunca se define.**
«el espejo de pruebas» (§6), «en el espejo local esta ventana no se abre nunca» (Caso 1), «un refresco de
base de datos desde producción trae esas credenciales armadas» (§6).
*Debería estar en el glosario*: espejo = la copia local del sitio en Docker (`compose.yaml`), con datos
traídos de producción.

**1.8 — Números que aparecen sin fuente.**
§5: «decodifica el AVIF y codifica un JPEG de calidad 82, **unos 38 ms**» y Caso 1 paso 5 lo repite. Los
38 ms no están en el fichero ni en su cabecera (que sí trae otras mediciones fechadas). §6: «los tres
servicios de `compose.yaml`» — ¿cuáles tres? §1: «`Erro -888`» se menciona una vez sin decir qué es.
*Debería decir*: de dónde sale cada número (fecha y medición), o marcarlo como estimación.

**1.9 — «Cuál gana depende de quién cargue primero» y ahí se acaba.**
§10 punto 2, sobre las dos copias del adaptador MCP: «las líneas citadas pueden no ser las que corren».
Perfecto, ¿y cómo averiguo cuál corre?
*Debería decir*: el comando (p. ej. `wp eval '$r = new ReflectionClass("WP\\MCP\\Core\\McpAdapter");
echo $r->getFileName();'`), que es lo que convierte el aviso en algo accionable.

---

## 2 · Lo que FALTA para poder operar o depurar esto sin ayuda

**2.1 — No hay ninguna receta para correr el barrido.** §8 «Dónde lo opera el dueño: por conversación con
un agente de inteligencia artificial, que llama a la ability». Eso no es operable. Falta: el endpoint, la
credencial (§1.1), un cuerpo de ejemplo con los campos obligatorios, y qué error devuelve cada guarda
(los códigos ya están en el capítulo: `akibara_dispo_feed_caducado`, `_sospechoso`, `_tope`,
`_expected_count`, `_marca_excluida`).

**2.2 — La receta del Caso 5 no funciona tal como está escrita.** Paso 1: «El agente llama a
`akibara/disponibilidad-set` con `dry_run = true`, la editorial, la fecha del listado y los pares
ISBN–estado». Falta `expected_count`, que el esquema declara **obligatorio para toda llamada**
(`akibara-catalogo-abilities.php:302`: `required => array( 'editorial', 'items', 'expected_count' )`).
Sin él la Abilities API rechaza también el ensayo en seco.
*Debería decir*: «en el ensayo va `expected_count: 0`; en la corrida real va el número que devolvió el
ensayo». La regla de negocio «Escribir exige `expected_count` exacto» está mal encuadrada por lo mismo:
`:302` obliga siempre, `:391-397` es lo que exige que **calce**.

**2.3 — El barrido sí está programado, fuera de WordPress.** «Lo programado» cierra con: «El barrido de
disponibilidad **no está programado**: sólo corre cuando alguien llama a la ability». Es cierto dentro de
WordPress y falso como hecho operativo: existe una tarea programada en la nube, «Akibara — barrido
semanal de disponibilidad», **lunes 08:00 de Chile** (`0 11 * * 1` UTC), que entra por `wc/v3` con una
contraseña de aplicación de administrador y llama a esta ability en dos pasos. Quien lea sólo este
capítulo creerá que nadie toca el catálogo solo, y es el único trabajo del área que **cambia lo que ve
el cliente**.
*Debería decir*: fila propia en la tabla de «Lo programado», con la nota de que su lógica vive en el
**prompt de la tarea**, no en el repositorio.

**2.4 — No hay forma de saber qué hizo la última corrida.** §8 lo admite («no existe ningún sitio donde
ver qué hizo la última corrida») pero no da el sucedáneo, que sí existe: la meta
`_akb_sweep_prev_stock_status` queda por producto (`:196`, `:201`).
*Debería decir*: la consulta (`wp post meta list <id>` o un `SELECT` sobre `postmeta` por esa clave) que
permite reconstruir y revertir a mano lo que hizo el último barrido.

**2.5 — Las dos options del vigía no se pueden ni mirar ni resetear.** §2: «las dos options no aparecen
en ninguna pantalla y sólo se escriben desde código; el propio gate de calidad tiene que manipularlas y
restaurarlas a mano». ¿Cómo?
*Debería decir*: `wp option get akb_bluex_watch_baseline_seen` / `wp option delete ...`, que es lo que
hay que hacer para volver a probar el ciclo de alarma.

**2.6 — Falta el segundo interruptor de Clarity.** §6 presenta `akibara-clarity-production-only.php`
como el único sitio que apaga Clarity. No lo es:
`akibara-business/src/Modules/FrontendCompatibility/FrontendCompatibility.php:44-46` hace el **mismo**
`remove_action( 'wp_head', 'clarity_add_script_to_header' )`, con una condición **más estricta** (exige
además el host canónico) y en otro momento (dentro de `register()`, en `plugins_loaded` 20, no en
`init:1`). Para depurar «por qué el espejo sigue mandando sesiones» hay que conocer los dos, y saber cuál
sobrevive si `akibara-business` se desactiva: el flojo, el del mu-plugin.

**2.7 — Falta quién borra las dos options del vigía.** La tabla «Datos que deja» dice de
`akb_bluex_watch_baseline_seen` y `akb_bluex_watch_resolved_notified`: «¿Se ve en alguna pantalla? No».
Falta la otra mitad: **desinstalar `akibara-business` las borra**, porque su `uninstall.php` barre
`option_name LIKE 'akb\_%'` y su lista de exclusiones sólo nombra plugins hermanos. Tras una
desinstalación, el vigía olvida que hubo rotura.

**2.8 — El vigía no vigila el único fichero del tercero que nosotros cargamos por ruta.** §2 explica que
el vigía recorre los `include`/`require` **que Blue Express se hace a sí mismo**
(`akibara-bluex-integrity-watch.php:28-69`). Pero el acoplamiento que nos puede tumbar el checkout es
otro: `akibara-cart-checkout-blocks/src/ComunaDirectory.php:26` y `:377-393` hacen `require_once` por
ruta fija de `bluex-for-woocommerce/includes/data/chile-communes.php` y dependen de
`bluex_get_communes_by_region()`. Si Blue deja de embarcar **ese** fichero, el vigía no dice nada.
*Debería decir*: qué cubre el vigía y qué no, con esa ruta nombrada.

**2.9 — Falta decir que nuestras propias abilities también están publicadas.** §9 concluye: «El veto
cubre 2 de 31 operaciones públicas por MCP», contando 24 de Rank Math + 7 de WooCommerce. Faltan **las
seis de la casa**, todas con `mcp.public => true`: `akibara/disponibilidad-set`
(`akibara-catalogo-abilities.php:306` y `:312`), `akibara/set-focus-keyword` y
`akibara/redirections-status|snapshot|trash|restore`
(`akibara-rankmath-redirections-abilities.php:204-210`, `:328-334`, `:365-371`, `:410-416`, `:449-455`).
Es justo lo que hace verdadero el punto 1 del §10 («un cliente con cuenta puede leer el incentivo de
$500»): lo puede leer **porque nosotros declaramos `show_in_rest => true`**.

**2.10 — El bucle de borrado de registros no tiene techo.** §3 lo describe como «los borra de mil en mil»
y la tabla de cron lo llama «Inofensivo». Falta la otra cara: el `do { ... } while` de `:41-51` no tiene
presupuesto de tiempo ni de iteraciones —para sólo cuando un lote devuelve menos de 1.000— y el mismo
callback está enganchado a **dos** hooks (`:122-123`) sin ningún candado, así que puede solaparse consigo
mismo. Con la tabla muy crecida eso es una petición larga dentro del evento del tercero.

**2.11 — La buena noticia puede llegar sin que la mala haya llegado nunca.** El capítulo cubre la
bifurcación D (carpeta borrada) pero no ésta: en `akibara-bluex-integrity-watch.php`,
`update_option( 'akb_bluex_watch_baseline_seen', 'yes' )` se escribe en `:89`, **antes** del `wp_mail` de
`:93` y **sin mirar su resultado**. Si el correo falla (que es exactamente lo que pasó cuando el
transporte de correo desapareció tres días), el estado dice «hubo rotura» y el siguiente chequeo sano
manda el «✅ Blue Express arregló su paquete» sin que nadie haya visto jamás la alarma.

**2.12 — La guarda de caducidad no mira hacia el futuro.** §8 y Caso 5 paso 4 explican bien que
`feed_fecha` es opcional. Falta que, aun mandándola, la comprobación es `$dias > 10` (`:338`) sobre un
`$dias` que puede ser **negativo**: un listado fechado el año que viene pasa la guarda.

**2.13 — Rutas de pantalla a medias.** «Ajuste `active_logs` en los ajustes de integración de Blue» y
«Sí, ajustes del plugin de Blue» no son una ruta. La option es
`woocommerce_correios-integration_settings`
(`bluex-for-woocommerce/includes/class-wc-correios-settings.php:26`); falta el menú exacto, del mismo
modo que sí se da para el correo de despacho (WooCommerce → Ajustes → Correos) y para `mcp_integration`
(WooCommerce → Ajustes → Avanzado → Funciones).

**2.14 — Los recuentos de líneas están todos desfasados en uno.** 83/**82**, 151/**150**, 85/**84**,
132/**131**, 29/**28**, 19/**18**, 414/**413**, 45/**44** (124 sí calza). Es menor, pero el lector los usa
para confirmar que tiene el fichero correcto delante, y ocho de nueve fallan.

---

## 3 · Lo que CONTRADICE al código

**3.1 — [GRAVE] §5 dice que la ventana JPG de Brevo se engancha, y en producción no se engancha nunca.**
El capítulo afirma (`akibara-brevo-portada-jpg-en-el-carrito.php` §5, «En qué momento actúa»): «Se
registra en `plugins_loaded` prioridad 20 (`:131`) y, si están los dos terceros, engancha en los tres
ganchos de carrito de Brevo», y el propio comentario que el capítulo parafrasea dice «a esa altura los
dos `class_exists` responden la verdad».
No es así. El segundo `class_exists` es
`akibara-brevo-portada-jpg-en-el-carrito.php:121`:
`class_exists( '\Akibara\Business\Modules\SeoSocial\SocialImageDerivatives' )`. El autocargador PSR-4 que
resuelve `Akibara\Business\*` se registra **dentro** de `akibara_business_bootstrap()`
(`akibara-business/akibara-business.php:138`), y esa función cuelga de `plugins_loaded` a prioridad
**20** (`:159`). Los mu-plugins se cargan **antes** que los plugins, así que el `add_action` del
mu-plugin (`:131`, también prioridad 20) entra primero en la cola y **corre primero**. En ese instante no
existe autocargador para ese espacio de nombres, y nada más lo carga antes (`grep` de
`SocialImageDerivatives` en `akibara-business.php` y en `mu-plugins/`: cero coincidencias fuera del
propio mu-plugin). Resultado: `class_exists` devuelve falso, `akibara_brevo_registrar_ventana_jpg()`
retorna en `:122` y los seis filtros no se registran.
Todo el §5, el Caso 1 completo, la fila «Brevo» de la tabla de dominios («Qué pasa si el mu-plugin se
apaga: el correo de carrito abandonado vuelve a llegar con un hueco») y el nodo `Brevo` del diagrama de
piezas describen un comportamiento que hoy no ocurre. El capítulo además invoca como respaldo la prueba
`BrevoPortadaJpgEnElCarritoTest.php`: una prueba no puede desmentir esto, porque en la suite las clases
ya están cargadas.
*Debería decir*: que la pieza está **inerte por orden de carga**, y cuál es el arreglo mínimo (prioridad
21 en `:131`, o comprobar la clase dentro de la ventana en vez de en el registro).

> **RESUELTO.** El 2026-09-12 el registro pasó a `plugins_loaded:25` (mu-plugin 1.1.1) y el par de
> prioridades quedó fijado por `BrevoPortadaJpgEnElCarritoTest::test_el_registro_espera_a_plugins_loaded_tarde()`.
> El 2026-09-15 se reescribieron §5, el Caso 1 y la fila «Brevo» del capítulo, y el diagrama de la
> secuencia, para describir la pieza viva; medido en producción ese mismo día volcando `$wp_filter`.

**3.2 — «El barrido de disponibilidad no está programado».** Contradicho por la operación real: corre
todos los lunes a las 08:00 de Chile desde una tarea programada en la nube que entra por `wc/v3`. Ver
2.3.

**3.3 — Caso 5 paso 1 y la regla «Escribir exige `expected_count` exacto».** `:302` lo exige en **toda**
llamada, ensayo incluido. Ver 2.2.

**3.4 — Ruta inexistente en §4.** «el plugin carga su dominio de traducción desde un directorio
`languages/` que no existe en el paquete instalado
(`plugins/bluex-for-woocommerce/class-wc-correios.php` carga el dominio…)». Ese fichero **no existe** en
la raíz del plugin (`ls` de la raíz: `LICENSE.txt`, `assets`, `debug-config.php`, `includes`,
`readme.txt`, `templates`, `uninstall.php`, `woocommerce-bluex.php`). La carga del dominio está en
`includes/class-wc-correios.php:131` (`load_plugin_textdomain( 'woocommerce-correios', false,
dirname( plugin_basename( WC_CORREIOS_PLUGIN_FILE ) ) . '/languages/' )`), enganchada en `init` a
prioridad `-1` (`:24`). Lo demás de la frase —que `languages/` no existe— sí es correcto.

**3.5 — Cita equivocada en §6.** «los endpoints REST de Brand Agent (`clarity.php:666`), la ruta
`/.well-known/ucp` (`:657`)». `:666` es correcto (`add_action( 'rest_api_init',
'brandagent_register_rest_api' )`). `:657` **no** es la ruta `/.well-known/ucp`: es
`add_action( 'template_redirect', 'brandagent_handle_custom_endpoint' )`. La ruta `ucp` se registra en
`:634` (`brandagent_ucp_register_routes()`), dentro de la función que el propio capítulo cita bien como
`:636`.

**3.6 — «2 de 31 operaciones públicas por MCP».** Son 24 + 7 + **6 nuestras** = 37 por lectura del
código, y 47 herramientas listadas en la medición viva del servidor por defecto. El número publicado
subestima justo la parte de la que somos responsables. Ver 2.9.

**3.7 — §5: «El coste no se multiplica por los ítems del carrito».** La **conversión** no se multiplica,
pero `ensure_now()` sí corre entera por cada imagen de cada ítem: `portadas_dir()`, un `file_exists`, dos
`filemtime` y un `get_transient` antes de decidir salir
(`SocialImageDerivatives.php:338-360`). La frase «los ~38 ms se pagan **una vez en la vida de cada
portada**» es correcta para el códec y engañosa para el resto.
*Debería decir*: «la conversión se paga una vez por portada; el chequeo (cuatro accesos a disco y un
transient) se paga en cada evento de carrito, por ítem».

**3.8 — §3: «su comentario (`:64-105`)».** El comentario va de `:64` a `:84`; `:85-87` es la guarda que
el propio capítulo cita aparte como código, y `:97-99` es un segundo comentario. El rango publicado
mezcla comentario y código.

---

## 4 · Lo que contradice a `verificacion.json` del área

La verificación del área (`scratchpad/mapas-b/mu-plugins-a/verificacion.json`: 8 omisiones, 3 errores, 9
sospechas nuevas, 17 confirmadas) está **muy bien absorbida** en general: las siete abilities de
WooCommerce, la segunda puerta `/wp-json/woocommerce/mcp`, las dos copias del adaptador, el listado REST
que sigue mostrando las vetadas, la acción semanal sin grupo ni `unique`, el gatillo que salta con
cualquier plugin, `active_logs` y los productos variables están todos en el capítulo, y el error de
`cross-cron` sobre el nombre del hook (`akibara_bluex_log_retention_cleanup`, no
`akibara_bluex_schedule_log_retention`) está corregido. Lo que queda fuera:

**4.1 —** `mapas-b/cross-integraciones/verificacion.json`, sospecha **confirmada** «El mu-plugin de la
portada JPG para Brevo nunca engancha», con el razonamiento de orden de carga completo. El capítulo
afirma lo contrario. Es la contradicción más cara del capítulo (ver 3.1).

**4.2 —** Sospecha confirmada 5.17: «El aviso de "Blue Express arregló su paquete" puede llegar sin el
aviso de rotura» (`akibara-bluex-integrity-watch.php:86-101` frente a `:106-115`). El capítulo tiene la
bifurcación D (carpeta borrada) pero no ésta (ver 2.11).

**4.3 —** Sospecha confirmada 5.20: «Dos sitios apagan Clarity con condiciones distintas y la del
mu-plugin es la más laxa» (`FrontendCompatibility.php:44-46`). Ausente del §6 (ver 2.6).

**4.4 —** Confirmación §6.8: «Eliminar Business borra dos options de los mu-plugins de Blue Express»
(`uninstall.php:84` con `LIKE 'akb\_%'`). Ausente de «Datos que deja» (ver 2.7).

**4.5 —** Sospecha «El vigía de integridad no vigila el único fichero del tercero que Akibara carga por
ruta» (`ComunaDirectory.php:26`, `:377-393`). Ausente del §2 (ver 2.8).

**4.6 —** Sospecha «La purga de los registros de Blue Express borra en bucle sin techo y comparte
callback con el cron del tercero, sin candado» (`akibara-bluex-log-retention.php:40-53`, `:122-123`). El
capítulo la califica de «Inofensivo» sin el matiz (ver 2.10).

*(De paso, `mapas/cross-datos` anota que `akibara-rankmath-redirections-abilities.php:242` y `:257`
escriben postmeta de un tercero, `rank_math_focus_keyword`. Está fuera del alcance del capítulo 14, pero
el capítulo se apoya en ese fichero dos veces —la categoría `akibara-operations` y el contexto del
veto—, así que merece media línea de remisión.)*

---

## 5 · Diagramas

**Validan todos.** `mmdc` 11.17.0 renderiza los seis `.mmd` sin error. Los seis traen cabecera
`%% Título` + `%% Qué muestra`, que es lo que hace que se entiendan solos; buen patrón.

**5.1 — Se ven rotos al abrir el `.md`.** Los siete embebidos son `![…](diagramas/….mmd)`: una imagen de
Markdown apuntando a un fichero de texto. En cualquier visor eso es un icono roto. Es la convención de
los capítulos 14–19, así que basta una nota arriba («los diagramas se generan con
`mmdc -i <fichero>.mmd -o <fichero>.svg`»), pero hoy el lector no tiene cómo verlos.

**5.2 — `piezas.mmd`: falta el buscador, que el texto declara arista clave.** El texto avisa: «Cada
`save()` del barrido dispara los ganchos normales de WooCommerce, así que el buscador (`akibara-search`)
reindexa ese producto». `akibara-search` no es un nodo del diagrama de piezas —sí aparece, bien, en el
diagrama del barrido—. Falta la arista `Barrido → Woo → akibara-search`.

**5.3 — `piezas.mmd`: repite el recuento incompleto de abilities.** Hay nodos `RM["Rank Math · 24
abilities públicas"]` y `WooAb["WooCommerce · 7 abilities públicas"]`, pero ninguno para las **6
nuestras**, todas públicas por MCP. El dibujo enseña la misma laguna que el §9 (ver 2.9/3.6).

**5.4 — `piezas.mmd`: la arista de Brevo dibuja algo que no pasa.** `Woo -->|"eventos de carrito"| Brevo`
y `Brevo --> Deriv` se pintan como camino vivo. Si 3.1 es correcto, esa rama debería ir punteada y
etiquetada («hoy no engancha: orden de carga»).

**5.5 — `piezas.mmd`: sin leyenda de colores.** Hay cuatro `classDef` (propio / tercero / persona /
dato) y nada en la página dice que azul = nuestro, naranja = tercero, verde = persona, morado = dato. Un
novato no lo adivina. Faltan también, como nodo de dato, las dos únicas options del capítulo
(`akb_bluex_watch_*`), mientras que las metas del barrido sí lo tienen.

**5.6 — `caso-portada-carrito.mmd`: el camino de fallo devuelve dos respuestas.** Dentro del `alt` hay
tres ramas; la tercera («AVIF ilegible o ya falló hace menos de 6 horas») ya responde
`Mu-->>Brevo: devuelve la URL AVIF sin tocar`, pero justo después del `end` interno viene, **sin
condición**, `Mu-->>Brevo: URL terminada en -og.jpg`. Leído de arriba abajo, el caso de fallo termina
entregando la JPG. La respuesta `-og.jpg` debe ir **dentro** de las dos ramas exitosas.

**5.7 — `cron.mmd`: el título promete lo contrario de lo que el capítulo demuestra.** `title Trabajos
automáticos del área, hora de Chile`, cuando el propio párrafo que lo introduce dice «**ninguno** de
estos trabajos tiene una hora fija de Chile». Debería titularse por cadencia («cada cuánto corre cada
trabajo — ninguno tiene hora fija»). Y falta el único trabajo del área con hora real y con efecto sobre
el cliente: el barrido semanal de la nube, lunes 08:00 CL (ver 2.3).

**5.8 — `caso-barrido-disponibilidad.mmd` y `estados-vigia.mmd`, dos detalles.**
(a) El diagrama del barrido repite el error de la receta: `run akibara/disponibilidad-set con
dry_run=true`, sin `expected_count` (ver 2.2); y responde `403` donde el código devuelve un `WP_Error`
que la capa REST/MCP traduce (vale la pena decirlo, porque por MCP el mensaje que ve el agente es otro).
Acierta, en cambio, en poner la validación del esquema **antes** del permiso, que es el orden real
(`abilities-api/includes/abilities-api/class-wp-ability.php:565` y `:570`).
(b) En `estados-vigia.mmd`, la única entrada al estado `Ausente` es `Roto --> Ausente`. En el código se
llega a la buena noticia falsa desde **cualquier** estado en que `akb_bluex_watch_baseline_seen` valga
`yes` y la carpeta desaparezca —incluido `Resuelto` tras una recaída—, y falta la transición que describe
2.11 (alarma que no sale pero marca `baseline_seen`). Además, los estados no dicen qué valor de qué
option los define, que es lo que el lector necesita para diagnosticar con `wp option get`.

---

## Resumen

- **No entendí:** 9 puntos (1.1–1.9) · **Falta para operar o depurar:** 14 puntos (2.1–2.14).
- **Contradice al código:** 8 puntos (3.1–3.8), uno de ellos grave: la ventana JPG de Brevo no engancha
  nunca en producción por orden de carga del autocargador, y el capítulo, el Caso 1 y el diagrama de
  piezas la describen funcionando.
- **Contradice `verificacion.json`:** 6 puntos (4.1–4.6) · **Diagramas:** 8 puntos (5.1–5.8); los seis
  `.mmd` validan con `mmdc` 11.17.0, pero se embeben como imágenes `.mmd` (icono roto), al de piezas le
  faltan el buscador y nuestras propias abilities, y el de la portada entrega dos respuestas en el camino
  de fallo.

---

## Respuesta del redactor

Revisé los **45 puntos** uno por uno contra el snapshot de producción y contra el árbol de terceros.
**No encontré ninguno equivocado**, así que están todos aplicados. Anoto abajo sólo las tres cosas donde
lo que escribí en el capítulo no es literalmente lo que pedía la crítica, y por qué.

**Lo que se comprobó y se dio por bueno, con lo que devolvió la comprobación:**

- **3.1 (grave), confirmado y aplicado.** Los dos `add_action` cuelgan de `plugins_loaded` con la misma
  prioridad 20 (`mu-plugins/akibara-brevo-portada-jpg-en-el-carrito.php:131` y
  `plugins/akibara-business/akibara-business.php:159`), el autocargador PSR-4 se registra dentro de
  `akibara_business_bootstrap()` (`:138`), y `SocialImageDerivatives` no aparece en ningún otro sitio de
  `mu-plugins/` ni de `akibara-business.php`. El §5 ahora abre con un aviso, explica el empate de
  prioridades, nombra el arreglo mínimo y dice por qué la prueba no podía cazarlo. El Caso 1 lleva su
  aviso, la fila «Brevo» de la tabla de dominios está reescrita y el diagrama de piezas pinta esa rama
  punteada y en rojo.
- **2.14, confirmado.** Los nueve ficheros miden 82 / 150 / 124 / 84 / 131 / 28 / 18 / 413 / 44. Los
  ocho desfasados están corregidos.
- **3.4, confirmado.** La carga del dominio está en `includes/class-wc-correios.php:131`, enganchada en
  `init` a prioridad `-1` (`:24`), y en la raíz del plugin no hay ningún `class-wc-correios.php`.
- **3.5, confirmado.** `:634` registra `ucp`, `:636` engancha `init`, `:657` es el `template_redirect`
  del manejador y `:666` el `rest_api_init`.
- **3.6 / 2.9, confirmado.** Seis abilities de la casa con `mcp.public => true` y `show_in_rest => true`:
  una en `akibara-catalogo-abilities.php` (`:306`, `:312`) y cinco en
  `akibara-rankmath-redirections-abilities.php` (`:204-210`, `:328-334`, `:365-371`, `:410-416`,
  `:449-455`). El capítulo dice ahora «2 de al menos 37», con las 47 herramientas de la medición viva
  como techo conocido.
- **2.3 / 3.2, confirmado.** La tarea de la nube existe, corre los lunes a las 08:00 de Chile
  (`0 11 * * 1` UTC), entra por `wc/v3` con contraseña de aplicación y su lógica vive en el texto de la
  tarea. Tiene fila propia en «Lo programado», con las tres advertencias que la hacen distinta de todo
  lo demás del capítulo, y sección propia en el diagrama de cron.
- **1.3, 1.4, 1.5, 1.6, 1.7, 1.9, 2.1, 2.4, 2.5, 2.6, 2.7, 2.8, 2.10, 2.11, 2.12, 2.13, 3.3, 3.7, 3.8**
  — todos comprobados en el código y aplicados donde tocaba: cabecera, glosario, §2, §3, §4, §6, §8,
  §10, tablas de reglas y de datos.
- **5.1 a 5.8** aplicados. Los seis `.mmd` vuelven a validar con `mmdc`.

**Las tres diferencias con lo que pedía la crítica:**

1. **1.8, los «tres servicios de `compose.yaml`».** Aquí el capítulo no estaba equivocado, estaba
   incompleto: `compose.yaml` declara **cinco** servicios (`db`, `wordpress`, `cron`, `mailpit`, `cli`)
   y `WP_ENVIRONMENT_TYPE: local` está en **tres** de ellos, que son exactamente los tres que ejecutan
   PHP (`wordpress`, `cron`, `cli`). En vez de corregir el número, lo nombré.
2. **1.8, los «38 ms».** Confirmado que no salen de ningún sitio: no están en el mu-plugin, ni en
   `SocialImageDerivatives.php`, ni en las notas de trabajo. No los marqué como estimación: los quité.
   Lo que sí está en el código es la calidad del JPEG (`SocialImageDerivatives.php:56`), y eso es lo que
   dice ahora el Caso 1. Un número sin medición detrás es peor que ningún número.
3. **Dos citas de línea afinadas al aplicar.** La guarda que exige que `expected_count` **calce** es
   `:389-397`; escribí `:385-397` para que el rango incluya también el `if ( $dry )` de `:385-387`, que
   es lo que hace que esa guarda no corra en el ensayo. Y en `ensure_now()` las tres salidas tempranas
   están en `:352-360`, no en `:349-358` (lo publicado) ni en `:338-360` (lo propuesto): el coste que se
   paga por ítem, con el `portadas_dir()` incluido, es `:343-360`, y así está escrito.

**Lo que añadí por mi cuenta, a raíz de la comprobación:** el barrido tiene una divergencia viva entre
código y regla de negocio. El 2026-09-12 el dueño fijó «a pedido y preventa = 5 % bajo el precio de
lista», y `AKIBARA_DISPO_INCENTIVO` sigue valiendo 500. Queda anotado con su fecha en el §8, junto al
recordatorio de que esa regla vive en dos sitios: la constante y el texto de la tarea de la nube.
