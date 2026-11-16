# Pruebas de extremo a extremo (navegador real)

Lo que las pruebas unitarias no pueden ver: la página RENDERIZADA, su código de
estado y lo que el cliente toca. Nacieron el 2026-09-18, el día en que todas las
fichas de producto respondieron 500 durante 4 minutos con 3.472 pruebas
unitarias en verde — ninguna renderizaba esa plantilla.

## Correr

```bash
cd tools/akibara-e2e && npm install        # la primera vez
npx playwright install chromium            # la primera vez

npm test                                   # contra el espejo (localhost:8080)
npm test -- --project=movil                # sólo móvil
npm run report                             # informe HTML de la última corrida (output/playwright/e2e-report)
```

Contra **producción**, sólo lectura y con tope de cargas:

```bash
npm run test:prod -- --list                # en seco: qué correría (no toca el sitio ni el informe HTML)
AKB_SOLO_LECTURA=1 npm test -- --grep @prod  # la misma selección contra el espejo: mide sus cargas
npm run test:prod                          # la prueba de humo: lo etiquetado @prod
npm run test:prod:completa                 # la suite entera: SÓLO con permiso del dueño

# con --reporter propio, el presupuesto va en la lista (si no, no arranca):
npm run test:prod -- --reporter=list,json,./specs/soporte/presupuesto-de-cargas.mjs
```

`AKB_SOLO_LECTURA=1` apaga todo lo que escribe —carrito, cupones, formularios,
sembrado de textos—. En la tienda de verdad sólo se mira. Contra un sitio que no
es el espejo local, sin esa variable la suite ni arranca (`soporte/destino.mjs`);
en seco (`--list`) lista igual, porque no le pide nada al sitio.

**El tope de cargas.** Cada navegación contra producción es una carga real de la
tienda. `npm run test:prod` corre sólo las pruebas etiquetadas `@prod` (54, en
escritorio y móvil: portada, listado, fichas disponible, a pedido y con armador,
buscador, cabeceras y caché, puntos, vitrina, enlaces de correo, primera visita,
y la marquesina de la portada, que es la comprobación de después del despliegue:
si falla, purgar LiteSpeed y repetir; en el espejo pasan 51 y se saltan 3 —la
página de baja sólo se mide con Cloudflare delante, y la primera visita sólo en
escritorio—), y en sólo lectura un reporter
(`soporte/presupuesto-de-cargas.mjs`) cuenta lo que la suite pide —`goto`,
`reload`, atrás/adelante y cada petición de `request`— y pone la corrida en rojo
si pasa de `AKB_TOPE_CARGAS`: 120 sin la variable; `0` lo quita, sólo con
permiso del dueño; cualquier otra cosa que no sea un entero ≥ 0 (`abc`, una
variable vacía) detiene la corrida, en vez de apagar el tope en silencio. Medido
en el espejo el 2026-09-26: 92 cargas pedidas (92 GET de documentos y API en el
log del servidor) más 40 POST de `get_refreshed_fragments`, que manda el propio
WooCommerce del navegador al cargar cualquier página, como a cualquier
visitante. La suite entera pasa de 500. Una etiqueta `@prod` nueva se mide antes
en el espejo (el reporter imprime las pruebas más caras) y, si el total pasa de
~100, sale otra. Un spec que diga correr en `npm run test:prod` tiene que llevar
pruebas `@prod` (lo vigila `instrumento.spec.mjs`); si sólo vale contra
producción en la suite entera, dice `npm run test:prod:completa`.

El mismo reporter pone en rojo, aunque la prueba pase, cualquier petición de
`request` que no sea GET/HEAD en una corrida de sólo lectura. Playwright titula
el paso con el método tal como lo escribe la prueba (`post "…"`, `Delete "…"`) o
con el molde `{method} "…"` si no lo pone (sale un GET): el reporter lo pasa a
mayúsculas como hace Playwright al enviarlo, y descarta los pasos de la misma
forma que no piden nada (`Fill "…"`, `Press "Enter"`…), sacados del mismo
catálogo con que Playwright arma los títulos. Si una versión de Playwright
cambia los títulos con que cuenta (`fetch`, `goto`, `reload`, atrás/adelante),
el reporter no carga y la corrida no arranca, en vez de quedarse sin contar.

Por el título no se distingue todo, y el reporter no recibe más. Una palabra
que es un método de HTTP cuenta siempre como petición: `tracing.group()` se
titula `Trace "…"`, como un `fetch` con `method: 'Trace'`, así que cuenta como
TRACE (la suite no lo usa; es una falsa alarma que se ve). Al revés, un `fetch`
con `method: 'Fill'` —o `Press`, `Type`, `Insert`, `Dispatch`, `Expect`, con esa
grafía exacta— se titula igual que el paso y no se ve: no son métodos de HTTP,
y si un spec o el soporte los escribe tal cual, `instrumento.spec.mjs` sale en
rojo.

**`--reporter` reemplaza la lista de la config**, entera. Pasar
`--reporter=list,json` dejaba la corrida sin tope y sin detector de escrituras,
sin aviso (la verificación de producción del 2026-09-26 corrió así). En sólo
lectura, `soporte/destino.mjs` no deja arrancar si la lista que va a correr no
trae `./specs/soporte/presupuesto-de-cargas.mjs` (la ruta, relativa a
`tools/akibara-e2e`). El tope lo lee el propio reporter de `AKB_TOPE_CARGAS`,
así que vale igual venga de la config o de la línea de comandos. En seco
(`--list`) no corre ninguna prueba, así que ahí no hace falta.

**La línea de comandos la lee el commander de Playwright**, con su tabla de
opciones, no un `includes( '--list' )`. Tras `--`, `--list` es un filtro de
ficheros, y tras `--grep` es su valor: la corrida es de verdad, lleva el informe
HTML y, en sólo lectura, la guardia le exige el presupuesto. Con `--ui` (o
`PWTEST_WATCH`) tampoco es en seco: esos modos no miran `--list` y corren
pruebas. Un `--reporter=` vacío Playwright lo ignora y corre con la lista de la
config, que en sólo lectura ya trae el presupuesto.

**Una corrida rechazada no borra nada.** La guardia corre al cargar
`playwright.config.mjs`, que es lo primero que hace Playwright: antes vivía sólo
en el globalSetup, y Playwright vacía `test-results` (su tarea «clear output») y
construye los reporters ANTES del globalSetup. Una corrida rechazada se llevaba
las capturas y el `error-context.md` de la última corrida de verdad, y el
reporter html dejaba un informe vacío en su lugar. El globalSetup repite la
comprobación sobre la lista ya resuelta, como segunda red.

**La carpeta del informe está escrita dos veces**: en `playwright.config.mjs`
(`outputFolder`) y en `npm run report` (`package.json`), porque `show-report` no
lee la config y sin carpeta busca `./playwright-report` («No report found»).
`instrumento.spec.mjs` vigila que las dos digan la misma.

`AKB_AVISO_PUNTOS=apagado` declara que el aviso «Gana N Puntos Akibara» de la
ficha está apagado a propósito (panel de Puntos). Sin esa declaración, una
ficha sin el aviso es un rojo: `puntos` y `rewards-aviso-de-puntos` no deducen
«apagado» de que falte justo lo que miden. Con la variable puesta, esas pruebas
abren igual la ficha y exigen que NO pinte el aviso; sólo entonces se saltan.
Una declaración olvidada después de volver a encender el aviso es un rojo, no
seis saltos.

## Qué cubre

| Carril | Qué fija |
| --- | --- |
| `catalogo` | Portada, listado con tarjetas de verdad, precios con símbolo, ficha disponible y ficha agotada, y el buscador del 404 apuntando al motor propio. |
| `buscador` | El formulario del header y del cajón móvil, resultados, cero-resultados con salida, el endpoint de sugerencias y que no se filtre el vocabulario crudo de WooCommerce. |
| `carrito` | Carrito vacío, alta por AJAX sin recargar, el botón listo para el siguiente clic, y el campo de cupón entero al enfocarlo. |
| `puntos` | La promesa de puntos con su equivalencia en pesos, en la ficha y en la portada. |
| `accesibilidad` | Un H1 por página, chips anunciados como enlaces, portadas con `alt`, anillo de foco visible. |
| `cabeceras` | 200/404 donde corresponde, ninguna ficha con 5xx escondido, carrito y pago fuera de la caché compartida, canónica en la ficha. |
| `vocabulario` | Renombrar «A pedido» en Textos cambia la ficha y el badge a la vez. **Siembra y restaura**: sólo espejo. |

## Trampas medidas al montarlo (2026-09-18)

- **`innerText()` devuelve el texto TRANSFORMADO por CSS.** Un badge escrito
  «A pedido» llega como «A PEDIDO»: comparar tal cual hacía que las pruebas se
  saltaran solas, que es la peor forma de pasar.
- **El preset `devices['iPhone 13']` cuelga el arranque en esta máquina.** Pide
  `deviceScaleFactor: 3` y el binario responde `CVDisplayLinkCreateWithCGDisplay
  failed`; el `launch` agota sus 180 s y seis pruebas «fallan» sin un solo
  mensaje. El proyecto móvil define su propio viewport, táctil y user-agent: lo
  que hace falta para probar la tienda en móvil es el ANCHO, no la densidad.
- **El Chromium completo tampoco sirve:** se queda esperando a los servicios de
  Google (GCM) al arrancar. Se usa el «headless shell».
- **`npx playwright install` se cuelga aquí** (2 h 30 y 624 KB de 150 MB): la
  configuración apunta con `executablePath` al binario ya cacheado.
- **Lo que escribe corre aparte.** El spec que siembra una option global tiene
  su propio proyecto (`siembra`), con `dependencies` y sin paralelismo; el del
  carrito va en serie. Sin eso, tres pruebas ajenas se ponían rojas sin que nada
  estuviera roto.
- **`.first()` agarra elementos ocultos.** El primer enlace a `/tienda/` vive en
  el cajón cerrado y el primero a `/checkout/` en el carrito lateral: o se pide
  `:visible`, o se afirma sobre la existencia y el destino.

## Trampas medidas después

- **Sin trace (2026-09-25).** Con Node 26 y Playwright 1.56.1, juntar las trazas
  de una prueba que falla se cuelga en cuanto una entrada del zip pasa de ~64 KB:
  cada fallo real sumaba un «Test timeout of 60000ms exceeded.» ajeno, 60 s de
  espera y un trace.zip roto. La config lleva `trace: 'off'`; la captura y el
  `error-context.md` dicen dónde falló. `--trace on` también se cuelga; se puede
  volver a encender con Playwright ≥ 1.60 o con Node 22.
- **`--list` reescribe el informe HTML (Playwright 1.56, 2026-09-26).** En seco
  Playwright construye igual todos los reporters de la config y llama a su
  `onEnd`, y el del html empieza por borrar su carpeta: un `--list` lanzado
  después de una corrida de verdad dejaba `npm run report` con las pruebas
  listadas y ningún resultado. La config quita el html cuando Playwright lee
  `--list` en la línea de comandos (no uno tras `--` ni el valor de `--grep`).
- **`devices[...]` pisa el user-agent del `use` general (2026-09-26).** El
  escritorio llegaba al espejo y a producción como un Chrome de Windows sin la
  marca `AkibaraQA/e2e`. Cada proyecto la lleva en su propio user-agent.
- **Los errores de gtag y Clarity no son de la tienda.** Sólo corren en
  producción y a veces tiran uno propio. `erroresDePagina()` (soporte/tienda.mjs)
  ignora los que nacen en googletagmanager.com o clarity.ms y anota la pila de
  todos en la prueba; úsalo en vez de un `page.on( 'pageerror' )` suelto.
- **Una intercepción no es una guarda.** Una prueba que toca el «Añadir» real o
  envía un formulario confiando en `page.route` escribe de verdad el día que la
  intercepción no casa: se salta con `AKB_SOLO_LECTURA`, o corta TODAS las
  salidas (`cortarEnviosDeContacto()` para el contacto: REST y admin-post).

## Reglas de la casa que respetan estos tests

- **Nada de escrituras en producción.** Lo que escribe se salta con `AKB_SOLO_LECTURA`,
  y el reporter de sólo lectura lo vigila.
- **Lo sembrado se retira**, contando, en el `afterAll` del propio spec.
- **User-agent `AkibaraQA/e2e`** en cada proyecto: el qlog de producción es la
  voz del cliente y estas visitas tienen que poder separarse.
- **Sin reintentos.** Un fallo intermitente en una tienda con caché es una
  señal, no ruido.
- Los specs **no clavan slugs**: buscan un producto real del catálogo y se
  saltan solos si el entorno no tiene el escenario (`test.skip` con motivo).
