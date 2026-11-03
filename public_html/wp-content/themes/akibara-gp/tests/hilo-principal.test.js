const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

// PC-11 (auditoría del tema 2026-09-22): trabajo de estilo/layout forzado dentro
// de las interacciones y del arranque. En campo, `#menu-toggle` daba INP 440 ms.
// Medido en el espejo, móvil, CPU x4, 7 repeticiones (mediana):
//   abrir ☰ 72 → 40 ms · cerrar 72 → 40 · abrir «Buscar» 80 → 48 · Cancelar 72 → 40.
// Lo caro era `inert` + `aria-hidden` sobre el resto del documento (15-37 ms
// poner, 14-24 quitar) dentro del mismo cuadro del toque, más un
// `void drawer.offsetWidth` y un focus() síncrono que lo forzaban ahí mismo.

const themeRoot = path.resolve(__dirname, '..');
const read = (rel) => fs.readFileSync(path.join(themeRoot, rel), 'utf8');

function sinComentarios(fuente) {
	return String(fuente)
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

function cuerpo(js, firma) {
	const inicio = js.indexOf(firma);
	assert.ok(inicio >= 0, `falta ${firma}`);
	let nivel = 0;
	for (let i = js.indexOf('{', inicio); i < js.length; i++) {
		if ('{' === js[i]) { nivel++; }
		if ('}' === js[i] && 0 === --nivel) { return js.slice(inicio, i + 1); }
	}
	throw new Error(`sin cierre: ${firma}`);
}

const main = sinComentarios(read('assets/js/main.js'));

test('abrir el cajón aplica el inert DESPUÉS del pintado, y sólo si sigue abierto', () => {
	const abrir = cuerpo(main, 'function openDrawer(');
	assert.doesNotMatch(
		abrir.replace(/afterNextPaint\(function \(\) \{[\s\S]*?\n\t\t\}\);/, ''),
		/setOutsideInert\(/,
		'setOutsideInert síncrono mete el recálculo de todo el documento en el cuadro del toque'
	);
	assert.match(abrir, /afterNextPaint\(function \(\) \{\s*if \(drawer\.classList\.contains\('open'\)\) \{\s*setOutsideInert\(true\);/);
	assert.match(main, /function afterNextPaint\(fn\) \{\s*window\.requestAnimationFrame\(function \(\) \{\s*window\.setTimeout\(fn, 0\);/);
});

test('cerrar suelta el inert y devuelve el foco después del pintado, salvo que se reabra', () => {
	const cerrar = cuerpo(main, 'function closeDrawer(');
	const diferido = cerrar.match(/afterNextPaint\(function \(\) \{([\s\S]*?)\n\t\t\}\);/);
	assert.ok(diferido, 'el cierre debe diferir el trabajo caro');
	assert.match(diferido[1], /if \(drawer\.classList\.contains\('open'\)\) \{\s*return;/, 'reabierto dentro del mismo cuadro: manda ese estado');
	// Primero soltar el inert y DESPUÉS enfocar: un elemento inerte no acepta foco.
	// Y sin desplazar (QAM-1): el disparador vive en la cabecera fija, dentro de
	// la franja de `scroll-padding-top`, y un focus() a secas subía la página
	// 422 px al cerrar el menú.
	assert.match(diferido[1], /setOutsideInert\(false\);\s*if \(target\) target\.focus\(\{ preventScroll: true \}\);/);
	const sincrono = cerrar.replace(diferido[0], '');
	assert.doesNotMatch(sincrono, /setOutsideInert\(/);
	assert.doesNotMatch(sincrono, /\.focus\(\)/, 'el focus() síncrono forzaba el recálculo dentro del toque');
});

test('el tab «Buscar» enfoca el campo sin forzar un layout antes', () => {
	const buscar = main.slice(main.indexOf("bottomSearch.addEventListener('click'"), main.indexOf('if (searchCancel)'));
	assert.doesNotMatch(buscar, /offsetWidth|offsetHeight|getBoundingClientRect/, 'el reflow forzado costaba 42 ms con CPU x4 en la portada');
	assert.match(buscar, /openDrawer\(input\);[\s\S]*?input\.focus\(\);/, 'el focus() sigue SÍNCRONO: iOS sólo abre el teclado dentro del gesto');
	assert.match(buscar, /input\.dispatchEvent\(new Event\('focus'\)\)/, 'la re-emisión diferida sigue pintando el zero-state');
	// «Cancelar» ya no enfoca a mano: la barra inferior sigue inerte en ese
	// instante y el foco lo devuelve closeDrawer() al tab «Buscar».
	const cancelar = main.slice(main.indexOf("searchCancel.addEventListener('click'"), main.indexOf("bottomSearch.setAttribute('aria-controls'"));
	assert.doesNotMatch(cancelar, /bottomSearch\.focus\(\)/);
});

test('la barra de progreso avanza con transform y su primera lectura va al cuadro siguiente', () => {
	// Hasta la primera declaración del bloque del menú (FLYOUT_ITEMS, T5-R1).
	const progreso = main.slice(main.indexOf("getElementById('aki-scroll-progress')"), main.indexOf('var FLYOUT_ITEMS'));
	assert.match(progreso, /progress\.style\.transform = 'scaleX\(' \+ ratio \+ '\)'/);
	assert.doesNotMatch(progreso, /progress\.style\.width/, 'animar el ancho obliga a un layout por cuadro de scroll');
	assert.doesNotMatch(progreso, /\n\t\tupdateProgress\(\);/, 'síncrono al arrancar forzaba el primer layout completo (34-47 ms con CPU x4)');
	assert.match(progreso, /requestProgress\(\);\s*\}\s*$/);

	const css = sinComentarios(read('assets/css/header.css'));
	const barra = css.match(/#aki-scroll-progress\s*\{([^}]*)\}/);
	assert.ok(barra);
	assert.match(barra[1], /width:\s*100%/);
	assert.match(barra[1], /transform:\s*scaleX\(0\)/);
	assert.match(barra[1], /transform-origin:\s*left center/);
	assert.match(barra[1], /transition:\s*transform 80ms linear/);
	assert.doesNotMatch(barra[1], /transition:\s*width/);
	// core.css es lo que se sirve: tiene que estar regenerado.
	assert.match(read('assets/css/core.css'), /transform:\s*scaleX\(0\);\s*transform-origin:\s*left center;/);
});

test('el reveal de tarjetas lee todas las cajas antes de escribir clases', () => {
	const anim = sinComentarios(read('assets/js/animations.js'));
	const reveal = cuerpo(anim, 'function revealNewCards(');
	const lectura = reveal.indexOf('getBoundingClientRect()');
	const escritura = reveal.indexOf("classList.add('akb-revealed')");
	assert.ok(lectura > 0 && escritura > lectura, 'primero las lecturas, después las escrituras');
	assert.doesNotMatch(
		reveal,
		/forEach\(function \(card\) \{[^}]*classList\.add\('akb-revealed'\);[^}]*getBoundingClientRect/,
		'intercalar escritura y lectura en el mismo bucle abre la puerta a un layout por tarjeta'
	);
});
