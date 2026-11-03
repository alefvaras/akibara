const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const themeRoot = path.resolve(__dirname, '..');
const read = (relative) => fs.readFileSync(path.join(themeRoot, relative), 'utf8');

// Invariantes del carrusel de editoriales arrastrable (2.1.71-2.1.75).
// Son tests ESTÁTICOS sobre el fuente — el theme no tiene harness de DOM —
// y anclan exactamente los contratos que la revisión adversarial 2026-08-08
// confirmó como los frágiles: cada uno de estos, si se pierde en un refactor,
// reintroduce un bug ya diagnosticado.

// Las aserciones de este archivo son regex sobre el FUENTE, así que un comentario
// que mencione la propiedad prohibida las hace pasar (o fallar) por la prosa y no
// por el código. Esta sesión lo pagó: un `doesNotMatch(/aspect-ratio/)` se puso
// rojo por un comentario que EXPLICABA por qué esa propiedad ya no está.
function sinComentarios(fuente) {
	return String(fuente)
		// Bloques /* … */ (CSS y JS).
		.replace(/\/\*[\s\S]*?\*\//g, '')
		// Y los de línea `// …`, que es donde caí la primera vez: el comentario
		// que EXPLICA por qué no se usa `offsetLeft` hacía fallar la aserción
		// que prohíbe `offsetLeft`. El `(^|[^:])` protege el `//` de las URLs.
		.replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

test('los conteos editoriales nombran productos de catálogo, no títulos u obras', () => {
	for (const template of [
		'template-parts/brands/home-carousel.php',
		'template-parts/brands/grid.php',
	]) {
		const php = read(template);
		assert.match(
			php,
			/_n\(\s*'%d producto en catálogo',\s*'%d productos en catálogo',/,
			`${template} debe rotular term->count con singular y plural honestos`
		);
		assert.doesNotMatch(
			php,
			/_n\(\s*'%d título',\s*'%d títulos',|\?> títulos<\/span>/,
			`${template} no debe presentar productos asociados como títulos distintos`
		);
	}
});

test('marquee cede el pan vertical al navegador (touch-action: pan-y)', () => {
	const css = read('assets/css/brands-home.css');
	assert.match(
		css,
		/\.akb-brands-home__track\.is-marquee\s*\{[^}]*touch-action:\s*pan-y/s,
		'sin pan-y el drag horizontal secuestra el scroll de la página'
	);
	// La red de seguridad reduced-motion se mantiene.
	assert.match(
		css,
		/@media\s*\(prefers-reduced-motion:\s*reduce\)[\s\S]*\.akb-brands-home__track\.is-marquee\s*\{[^}]*transform:\s*none\s*!important/
	);
});

test('cada listener del gesto tiene su alta Y su baja (sin fugas en cleanup)', () => {
	const js = read('assets/js/animations.js');
	for (const type of [
		'pointerenter', 'pointerleave', 'pointerdown', 'pointermove',
		'pointerup', 'pointercancel', 'lostpointercapture', 'dragstart',
		'focusin', 'focusout',
	]) {
		assert.match(js, new RegExp(`track\\.addEventListener\\('${type}'`), `falta alta de ${type}`);
		assert.match(js, new RegExp(`track\\.removeEventListener\\('${type}'`), `falta baja de ${type} en el cleanup de matchMedia`);
	}
	// El click de supresión va en fase CAPTURA en alta y baja (mismo flag).
	assert.match(js, /track\.addEventListener\('click', onClickCapture, true\)/);
	assert.match(js, /track\.removeEventListener\('click', onClickCapture, true\)/);
});

test('pan vertical y captura perdida terminan el gesto SIN inercia', () => {
	const js = read('assets/js/animations.js');
	// pointercancel (pan-y cedido al navegador) → finishDrag(true).
	assert.match(
		js,
		/function onPointerCancel\(event\)\s*\{[\s\S]*?finishDrag\(true\);[\s\S]*?\}/,
		'pointercancel debe cancelar sin momentum'
	);
	// lostpointercapture (pointerup perdido por Alt-Tab/diálogo del SO)
	// también libera el gesto — sin esto la marquesina quedaba congelada.
	assert.match(
		js,
		/function onLostCapture\(event\)\s*\{[\s\S]*?finishDrag\(true\);[\s\S]*?\}/
	);
});

test('el hover-pause filtra puntero de mouse real (el tap táctil no congela)', () => {
	const js = read('assets/js/animations.js');
	assert.match(
		js,
		/function onPointerEnter\(event\)\s*\{[\s\S]*?'mouse'\s*===\s*event\.pointerType[\s\S]*?\}/,
		'pointerenter sin filtrar pointerType reintroduce el congelamiento por tap (bug original)'
	);
	// Y la marquesina ya no usa los eventos mouse* emulados.
	assert.doesNotMatch(js, /track\.addEventListener\('mouseenter'/);
	assert.doesNotMatch(js, /track\.addEventListener\('mouseleave'/);
});

test('resumeAuto re-verifica TODOS los estados de pausa antes de reanudar', () => {
	const js = read('assets/js/animations.js');
	const resumeAuto = js.match(/function resumeAuto\(\)\s*\{([\s\S]*?)\}/);
	assert.ok(resumeAuto, 'resumeAuto debe existir');
	for (const guard of ['document.hidden', 'drag.active', 'momentum', 'hoverPaused', 'focusPaused', 'offscreenPaused', 'userPaused']) {
		assert.match(
			resumeAuto[1],
			new RegExp(guard.replace('.', '\\.')),
			`resumeAuto sin chequear ${guard} reanuda la marquesina bajo el usuario (WCAG 2.2.2)`
		);
	}
});

test('build() nace pausado y delega el arranque en resumeAuto', () => {
	const js = read('assets/js/animations.js');
	const build = js.match(/function build\(\)\s*\{([\s\S]*?)\n\t\t\}/);
	assert.ok(build, 'build debe existir');
	assert.match(build[1], /paused:\s*true/, 'un resize con el mouse encima no debe autoplayar bajo el cursor');
	assert.match(build[1], /resumeAuto\(\)/);
	assert.match(build[1], /drag\.active/, 'un rebuild durante un gesto activo debe abortarlo limpio');
});

test('la supresión de click exige arrastre real (el tap navega)', () => {
	const js = read('assets/js/animations.js');
	assert.match(
		js,
		/if\s*\(drag\.moved\)\s*\{[\s\S]*?suppressClick = true;/,
		'suppressClick solo tras drag.moved: suprimir el tap mataría la navegación a la editorial'
	);
});

// ─── 2026-08-09: fluidez del arrastre ────────────────────────────────────────
// Los tres defectos que se midieron en producción 2.1.76 y que este bloque
// impide que vuelvan.

test('el recorrido del bucle se mide del clon, no de scrollWidth/2', () => {
	const js = read('assets/js/animations.js');
	const setWidth = js.match(/function setWidth\(\)\s*\{([\s\S]*?)\n\t\t\}/);
	assert.ok(setWidth, 'setWidth debe existir');
	const cuerpo = sinComentarios(setWidth[1]);
	assert.match(
		cuerpo,
		/clones\[0\]\.getBoundingClientRect\(\)\.left\s*-\s*slides\[0\]\.getBoundingClientRect\(\)\.left/,
		'scrollWidth/2 suma el padding del track y parte el último gap: medido en prod ' +
		'daba 1192,5 px contra 1205 reales = 12,5 px de salto en cada vuelta'
	);
	// `offsetLeft` redondea a entero y el ancho del slide sale de un clamp con
	// vw: a 393, 412 y 428 px (anchos reales de teléfono) dejaba hasta 0,5 px
	// de desajuste por vuelta. El rect no redondea.
	assert.doesNotMatch(cuerpo, /offsetLeft/, 'offsetLeft es entero: usa getBoundingClientRect');
	// `scrollWidth` sigue en el cuerpo A PROPÓSITO y por eso NO se prohíbe:
	// es el fallback para cuando no hay clones o la medida sale <= 0, porque
	// devolver 0 haría que build() lo lea como "no hay nada que animar" y
	// desmonte la marquesina. Lo que fija este test es que el camino real sea
	// la resta de rects, que es lo que faltaba en producción.
});

test('la marquesina se vuelve a medir cuando aterriza la hoja diferida', () => {
	const js = read('assets/js/animations.js');
	// brands-home.css se carga con el truco media="print" (inc/performance.php),
	// así que animations.js puede medir un track todavía sin flex.
	const codigo = sinComentarios(js);
	// La compuerta NO puede ser `display:flex`: ese flex lo produce la propia
	// clase `.is-marquee`, así que usarlo para decidir si añadirla no termina
	// nunca. Se pregunta por un token que la hoja define en la sección.
	assert.match(codigo, /function stylesReadyRaw\(\)\s*\{[\s\S]*?--akb-brand-slide-w/);
	// Y la marquesina se MONTA recién cuando la hoja llegó: montarla antes deja
	// una lista vertical de 1815px trasladándose en X durante toda la ventana
	// de carga diferida (medido con la hoja retrasada 4s).
	assert.match(
		codigo,
		/function start\(\)\s*\{[\s\S]*?stylesReadyRaw\(\)[\s\S]*?classList\.add\('is-marquee'\)[\s\S]*?build\(\);/,
		'la clase y el build tienen que ir juntos y detrás de la compuerta'
	);
	assert.doesNotMatch(
		codigo.split('function start(')[0],
		/classList\.add\('is-marquee'\)/,
		'nadie puede montar la marquesina antes de la compuerta'
	);
	assert.match(codigo, /if\s*\(!start\(\)\)\s*\{[\s\S]*?setTimeout\(waitForStyles/);
	// El reintento NO puede colgar de requestAnimationFrame: en pestaña de fondo
	// el navegador lo congela y la marquesina no monta nunca (medido en local con
	// el panel oculto: token presente, `is-marquee` sin aplicar).
	assert.doesNotMatch(
		codigo,
		/requestAnimationFrame\(waitForStyles\)/,
		'la espera de la hoja no puede depender de rAF'
	);
	assert.match(js, /clearTimeout\(styleWatch\)/, 'el watcher debe morir en el cleanup');
});

test('el arrastre escribe UNA vez por frame (ticker), no una por pointermove', () => {
	const js = read('assets/js/animations.js');
	const onMove = js.match(/function onPointerMove\(event\)\s*\{([\s\S]*?)\n\t\t\}/);
	assert.ok(onMove, 'onPointerMove debe existir');
	assert.match(
		onMove[1],
		/pendingProgress = wrapProgress\(/,
		'el move deja el valor pendiente; lo aplica el ticker'
	);
	assert.doesNotMatch(
		onMove[1],
		/tween\.progress\(/,
		'escribir el tween en cada pointermove hacía 9,88 escrituras por frame en WebKit (medido)'
	);
	assert.match(js, /gsap\.ticker\.add\(flushDrag\)/, 'falta el alta del ticker del gesto');
	assert.match(js, /gsap\.ticker\.remove\(flushDrag\)/, 'falta la baja del ticker del gesto');
	// El vaciado tiene que ocurrir ANTES de calcular la inercia, o el throw
	// arranca desde un progreso viejo.
	assert.match(
		js,
		/function stopDragTicker\(\)\s*\{\s*[\s\S]*?flushDrag\(\);\s*gsap\.ticker\.remove\(flushDrag\);/,
	);
	assert.match(js, /drag\.active = false;\s*stopDragTicker\(\);/);
});

// ─── 2026-08-09: placas uniformes ────────────────────────────────────────────

test('el ancho de la placa lo manda el layout, nunca el nombre', () => {
	const css = read('assets/css/brands-home.css');
	// El slide del carrusel: base fija Y min-width:0 (sin el segundo, el
	// min-content del nombre pasaba por encima de flex-basis: medido 144px
	// donde la base decía 120px).
	assert.match(
		css,
		/\.akb-brands-home__track\.is-marquee \.akb-brands-home__slide\s*\{[^}]*flex:\s*0 0 var\(--akb-brand-slide-w\)[^}]*min-width:\s*0/s,
		'el slide del carrusel necesita ancho fijo + min-width: 0'
	);
	// El <a> no puede desbordar su <li>.
	assert.match(
		css,
		/\.akb-brands-home__item\s*\{[^}]*width:\s*100%[^}]*min-width:\s*0[^}]*box-sizing:\s*border-box/s
	);
	// Y el nombre largo se recorta en vez de deformar la tarjeta.
	assert.match(css, /\.akb-brands-home__name\s*\{[^}]*-webkit-line-clamp:\s*2/s);
});

test('la placa es una caja FIJA con object-fit: contain (patrón /editoriales/)', () => {
	const css = read('assets/css/brands-home.css');
	assert.match(
		css,
		/\.akb-brands-home__logo\s*\{[^}]*height:\s*var\(--akb-brand-plate-h\)[^}]*box-sizing:\s*border-box[^}]*overflow:\s*hidden/s,
		'sin caja fija + border-box, el alto de la placa vuelve a depender del logo'
	);
	const img = sinComentarios(css).match(/\.akb-brands-home__logo img\s*\{([\s\S]*?)\n\}/);
	assert.ok(img, '.akb-brands-home__logo img debe existir');
	assert.match(img[1], /object-fit:\s*contain/);
	// La imagen llena la caja de la placa; el ritmo visual lo da la placa, no
	// un tope de proporción sobre la tinta. Se probó `aspect-ratio` fija y se
	// revirtió: emparejaba el ALTO pero empeoraba el ÁREA —que es lo que el ojo
	// lee como "tamaño"— y dejaba Arechi como una estampilla dentro de su placa,
	// que es la queja original. Es el patrón de /editoriales/, ya aprobado.
	assert.match(img[1], /width:\s*100%/);
	assert.match(img[1], /height:\s*100%/);
	assert.doesNotMatch(
		img[1],
		/aspect-ratio/,
		'un tope de proporción sobre la tinta achica los logos compactos: se midió y se revirtió'
	);
	assert.doesNotMatch(
		img[1],
		/max-width:\s*\d/,
		'max-width/max-height dejaban que la proporción de cada logo decidiera el alto'
	);
	// Especificación del dueño (2026-10-22): fondo blanco + imagen del logo con
	// sus colores auténticos siempre (sin filtro de inversión a blanco).
	assert.match(
		img[1],
		/filter:\s*none/,
		'los logos muestran sus colores auténticos directamente sobre la placa blanca'
	);
});

test('las reglas de grilla móvil no gobiernan el carrusel', () => {
	const css = read('assets/css/brands-home.css');
	const mobile = css.match(/@media \(max-width: 640px\)\s*\{([\s\S]*?)\n\}/);
	assert.ok(mobile, 'el bloque móvil debe existir');
	// `grid-column` es inerte bajo flex, pero `max-width: 60%` NO: con un
	// número impar de editoriales deformaba la última placa del carrusel.
	assert.match(
		mobile[1],
		/:not\(\.is-marquee\)[^{]*:last-child:nth-child\(odd\)[^{]*\.akb-brands-home__item\s*\{[^}]*max-width:\s*60%/s,
		'el centrado del último impar es exclusivo de la grilla (fallback reduced-motion)'
	);
	assert.match(
		mobile[1],
		/\.akb-brands-home__track:not\(\.is-marquee\)\s*\{[^}]*grid-template-columns/s,
		'la grilla de 2 columnas es el fallback, no un segundo diseño conviviendo'
	);
	assert.doesNotMatch(
		mobile[1],
		/\.akb-brands-home__track\s*\{/,
		'una regla al track sin :not(.is-marquee) vuelve a pisarle padding/gap al carrusel'
	);
});

test('la placa no transiciona `all`: arrastraba el padding y lo dejaba en 0', () => {
	// Medido en local 2026-08-09: con `transition: all 200ms` la placa se quedaba
	// con `padding: 0` de forma PERMANENTE — a los 1500 ms seguía en 0 y sólo al
	// forzar `transition: none` saltaba a `4px 12px`. La caja útil valía 153x53 en
	// vez de 128x44, así que el logo iba pegado al borde y la geometría publicada
	// no era la diseñada. Es la causa raíz de los tamaños descuadrados.
	const css = sinComentarios(read('assets/css/brands-home.css'));
	assert.doesNotMatch(
		css,
		/transition:\s*all\b/,
		'`transition: all` incluye padding/width/height — enumerar las propiedades'
	);
	// Y lo que el hover SÍ cambia sigue transicionando.
	assert.match(css, /transition:\s*background-color[^;]*border-color[^;]*box-shadow/);
});

test('equilibrio óptico: la tinta se normaliza por área, no por caja', () => {
	const css = sinComentarios(read('assets/css/brands-home.css'));
	const js = sinComentarios(read('assets/js/animations.js'));

	// `contain` en una caja fija reparte áreas muy distintas: el máximo se lo lleva
	// el logo cuyo aspecto coincide con el de la caja y los extremos pierden
	// siempre (3,29x medido). El factor lo escribe el JS y lo aplica el CSS.
	assert.match(css, /transform:\s*scale\(var\(--akb-brand-fit,\s*1\)\)/);
	// El hover DEBE componerse con el ajuste: `scale(1.05)` a secas lo desharía
	// y el logo pegaría un salto al pasar el mouse.
	assert.match(css, /transform:\s*scale\(calc\(var\(--akb-brand-fit,\s*1\)\s*\*\s*1\.05\)\)/);

	// El cálculo mide la caja con `offsetWidth/Height`, NUNCA con el rect: el rect
	// ya viene multiplicado por el scale de la vuelta anterior y se realimentaría.
	const fit = js.match(/function fitLogos\(\)\s*\{([\s\S]*?)\n\t\t\}/);
	assert.ok(fit, 'fitLogos() debe existir');
	assert.match(fit[1], /img\.offsetWidth/);
	assert.match(fit[1], /img\.offsetHeight/);
	assert.doesNotMatch(fit[1], /getBoundingClientRect/);
	// Sólo se escala hacia abajo: agrandar sacaría de la placa a los que ya la llenan.
	assert.match(fit[1], /k\s*<\s*1\s*\?\s*k\s*:\s*1/);
});

test('el recálculo observa la caja INTERIOR, que es la que cambia', () => {
	// La placa mide 156x56 con y sin la hoja diferida, así que observarla no
	// avisaba nunca; lo que cambia al aterrizar el CSS es el padding, y eso sólo
	// lo ve la imagen. Con el observador puesto en la placa los factores quedaban
	// calculados contra una caja que ya no existía (Ivrea 0,806 donde iba 0,757).
	const js = sinComentarios(read('assets/js/animations.js'));
	assert.match(js, /ResizeObserver/);
	assert.match(
		js,
		/track\.querySelector\('\.akb-brands-home__logo img'\)/,
		'hay que observar la imagen, no la placa'
	);
	assert.match(read('assets/js/animations.js'), /fitObserver\.disconnect\(\)/, 'debe morir en el cleanup');
});

// ─── 2026-09-22: auditoría del tema (AI-1/PC-9 y AI-3) ────────────────────────

test('fuera de pantalla la marquesina no anima (IntersectionObserver con baja)', () => {
	// Medido en producción (portada móvil, CPU x4, 5 s en reposo con la sección
	// 10.969 px bajo el pliegue): el tween repeat:-1 recalculaba estilo a 60 fps
	// sin que nadie lo viera. Con el pulso del carrito apagado, 0,77 s → 0,19 s
	// de hilo principal y 301 → 41 recálculos en el espejo.
	const js = sinComentarios(read('assets/js/animations.js'));
	const marquee = js.slice(js.indexOf('function setupBrandsMarquee('));
	assert.match(marquee, /var offscreenPaused = 'function' === typeof window\.IntersectionObserver;/,
		'nace en pausa: el primer aviso del observador es asíncrono y build() no debe arrancar un tween que quizá no se ve');
	assert.match(
		marquee,
		/new window\.IntersectionObserver\(function \(entries\) \{[\s\S]*?offscreenPaused = !entries\[entries\.length - 1\]\.isIntersecting;[\s\S]*?pause\(\);[\s\S]*?resumeAuto\(\);/,
		'al salir pausa, al entrar reanuda por resumeAuto (que respeta hover, foco, gesto y el botón)'
	);
	assert.match(marquee, /visibilityObserver\.observe\(section \|\| track\)/, 'se observa la sección: el track se traslada');
	assert.match(marquee, /visibilityObserver\.disconnect\(\)/, 'el observador muere en el cleanup de matchMedia');
});

test('la marquesina tiene su botón de pausa (WCAG 2.2.2) y sólo existe si hay marquesina', () => {
	const js = sinComentarios(read('assets/js/animations.js'));
	// Se monta en start(), detrás de la compuerta de la hoja y antes del build:
	// sin JS o con reducir movimiento no hay movimiento que pausar.
	assert.match(js, /function start\(\)\s*\{[\s\S]*?classList\.add\('is-marquee'\);\s*mountPauseBtn\(\);\s*build\(\);/);
	const mount = js.match(/function mountPauseBtn\(\)\s*\{([\s\S]*?)\n\t\t\}/);
	assert.ok(mount, 'mountPauseBtn debe existir');
	assert.match(mount[1], /pauseBtn\.type = 'button'/);
	// Nombre FIJO + aria-pressed: el patrón de botón de alternancia. Un nombre que
	// cambia bajo el foco con aria-pressed encima se anuncia dos veces.
	assert.match(mount[1], /setAttribute\('aria-label', 'Pausar el carrusel de editoriales'\)/);
	assert.match(mount[1], /setAttribute\('aria-pressed', 'false'\)/);
	assert.match(mount[1], /\.akb-brands-home__more/, 'va junto a «Ver editoriales», fuera del track (el foco en el track ya pausa)');
	const click = js.match(/function onPauseClick\(\)\s*\{([\s\S]*?)\n\t\t\}/);
	assert.ok(click, 'onPauseClick debe existir');
	assert.match(click[1], /userPaused = !userPaused;/);
	assert.match(click[1], /aria-pressed', userPaused \? 'true' : 'false'/);
	// Y se desmonta con la marquesina.
	assert.match(js, /pauseBtn\.removeEventListener\('click', onPauseClick\);\s*pauseBtn\.remove\(\);/);
});

test('el botón de pausa: ≥44 px, gemelo de «Ver editoriales», hover sólo con puntero real', () => {
	const css = sinComentarios(read('assets/css/brands-home.css'));
	const btn = css.match(/\.akb-brands-home__pause\s*\{([^}]*)\}/);
	assert.ok(btn, '.akb-brands-home__pause debe existir');
	// Área táctil ≥ 44 px y el mismo alto que «Ver editoriales» (su min-height de
	// 44 px es de contenido: + padding de bloque 0,45rem × 2 + borde 2 × 2).
	assert.match(btn[1], /width:\s*52px/);
	assert.match(btn[1], /height:\s*calc\(44px \+ 0\.9rem \+ 4px\)/);
	assert.match(btn[1], /box-sizing:\s*border-box/);
	const enlace = css.match(/\.akb-brands-home__more\s*\{([^}]*)\}/);
	assert.match(enlace[1], /min-height:\s*44px/, 'si cambia el alto del enlace, el del botón tiene que acompañarlo');
	assert.match(enlace[1], /padding:\s*0\.45rem/);
	assert.match(btn[1], /margin-left:\s*auto/, 'pegado al enlace: el auto margin gana al space-between de la cabecera');
	assert.match(css, /\.akb-brands-home__pause\[aria-pressed="true"\] \.akb-brands-home__pause-icon\s*\{[^}]*clip-path/, 'pausada muestra el triángulo de reanudar');
	assert.match(css, /\.akb-brands-home__pause:focus-visible\s*\{/, 'el foco de teclado se ve');
	// En un teléfono el :hover se queda pegado tras el toque.
	assert.doesNotMatch(css.replace(/@media \(hover: hover\)\s*\{[\s\S]*?\n\}/g, ''), /\.akb-brands-home__pause:hover/);
	// Móvil: la cabecera es fila que se parte (texto arriba, [pausa][enlace] abajo).
	const mobile = css.match(/@media \(max-width: 640px\)\s*\{([\s\S]*?)\n\}/);
	assert.match(mobile[1], /\.akb-brands-home__header\s*\{[^}]*flex-wrap:\s*wrap/);
	assert.match(mobile[1], /\.akb-brands-home__intro\s*\{[^}]*flex:\s*1 1 100%/);
});

// T3-R1 (revisión independiente, 2026-09-22): en colores forzados (alto contraste
// de Windows) el botón quedaba como una caja vacía. El icono está hecho SÓLO de
// fondos —degradado para las barras, fondo + clip-path para el triángulo— y ahí
// el navegador quita los degradados y reemplaza los fondos. La prueba de
// navegador (tema-hilo-principal.spec.mjs) lo mide por píxeles; ésta ancla el CSS.
test('colores forzados: el icono de pausar/reanudar y el foco del botón siguen a la vista', () => {
	const css = sinComentarios(read('assets/css/brands-home.css'));
	const forzados = css.match(/@media \(forced-colors: active\)\s*\{([\s\S]*?)\n\}/);
	assert.ok(forzados, 'falta el bloque @media (forced-colors: active) del botón de pausa');
	const icono = forzados[1].match(/\.akb-brands-home__pause-icon\s*\{([^}]*)\}/);
	assert.ok(icono, 'el icono necesita su regla en colores forzados');
	assert.match(icono[1], /forced-color-adjust:\s*none/, 'sin esto el navegador borra el degradado y el fondo del icono');
	// Un hijo con `forced-color-adjust: none` hereda el color del AUTOR, no el
	// forzado del botón: sin un color de sistema salía gris 300 sobre blanco (2,2:1).
	assert.match(icono[1], /color:\s*ButtonText/, 'el icono pinta en el color de sistema del texto del botón');
	// Y los dos dibujos siguen pintando en currentColor: si no, ButtonText no les llega.
	assert.match(css, /\.akb-brands-home__pause-icon\s*\{[^}]*background:\s*linear-gradient\(to right, currentColor/);
	assert.match(css, /\.akb-brands-home__pause\[aria-pressed="true"\] \.akb-brands-home__pause-icon\s*\{[^}]*background:\s*currentColor/);
	// El foco: el amarillo tampoco llega y la regla general lleva `outline: none`;
	// quedaba sólo un cambio de color del borde. Desde REV-T6-2-1 lo devuelve el
	// contorno de respaldo GLOBAL (tokens.css), para éste y todos los controles:
	// una copia local de la misma regla sería código muerto.
	assert.doesNotMatch(forzados[1], /\.akb-brands-home__pause:focus-visible/, 'el foco en colores forzados es el de tokens.css, no una copia');
	const tokens = sinComentarios(read('assets/css/tokens.css'));
	assert.match(tokens, /@media \(forced-colors: active\)\s*\{\s*:focus-visible(:where\(:not\(#main\)\))?\s*\{[^}]*outline:\s*2px solid Highlight !important/, 'en colores forzados el foco del botón vuelve a tener contorno');
});

// T3-R2 (revisión, 2026-09-23): el recorte y el degradado de la marquesina
// estaban en el contenedor de TODA la sección. El degradado se comía el
// principio del título y el final de «Ver editoriales» en escritorio, y el
// recorte cortaba por arriba el foco del botón de pausa y el de «Ver
// editoriales» (en colores forzados el lado de arriba del contorno desaparecía
// entre 753 y 1.233 px). Ahora viven en una ventana que envuelve SÓLO la pista.
test('la marquesina recorta y difumina sólo la pista, no la cabecera', () => {
	const css = sinComentarios(read('assets/css/brands-home.css'));
	assert.match(css, /\.akb-brands-home__viewport:has\(> \.is-marquee\)\s*\{[^}]*overflow:\s*hidden;[^}]*mask-image:\s*linear-gradient/s);
	assert.doesNotMatch(css, /\.akb-brands-home__container:has\(/, 'el contenedor de la sección no recorta ni difumina');
	const plantilla = read('template-parts/brands/home-carousel.php');
	assert.match(plantilla, /<\/header>\s*(<\?php[^?]*\?>\s*)?<div class="akb-brands-home__viewport">\s*<ul class="akb-brands-home__track"/, 'la ventana envuelve la pista y deja la cabecera fuera');
});
