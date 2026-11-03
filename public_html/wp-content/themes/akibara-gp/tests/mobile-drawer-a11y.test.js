const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const themeRoot = path.resolve(__dirname, '..');
const read = (relative) => fs.readFileSync(path.join(themeRoot, relative), 'utf8');

// Reglas de un selector, para poder afirmar sobre cada aparición y no solo
// sobre la primera: el defecto que motiva este archivo fue justamente una
// SEGUNDA declaración, más abajo en la hoja, que pisaba a la primera.
const bloques = (css, selector) => {
	const salida = [];
	const patron = new RegExp(
		'(^|[},])\\s*' + selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{([^}]*)\\}',
		'g'
	);
	let m;
	while ((m = patron.exec(css)) !== null) {
		salida.push(m[2]);
	}
	return salida;
};

test('los sublinks de un acordeón cerrado quedan fuera del orden de tabulación', () => {
	const css = read('assets/css/header.css');

	// `max-height:0` / `height:0` con `overflow:hidden` oculta a la vista pero
	// NO al foco: con los acordeones cerrados, Tab recorría los 9 sublinks
	// invisibles y el scroll saltaba a un punto en blanco (QA 2026-08-16).
	const cerrados = bloques(css, '.mobile-drawer__sub');
	assert.ok(cerrados.length > 0, 'debe existir la regla base de .mobile-drawer__sub');
	assert.match(
		cerrados[0],
		/visibility:\s*hidden/,
		'el acordeón cerrado debe salir del orden de tabulación con visibility:hidden'
	);

	const abiertos = bloques(css, '.mobile-drawer__sub.open');
	assert.ok(abiertos.length > 0, 'debe existir la regla de .mobile-drawer__sub.open');
	assert.match(
		abiertos[0],
		/visibility:\s*visible/,
		'el acordeón abierto debe volver a ser navegable'
	);
});

test('ninguna redefinición de transition deja fuera a visibility en el acordeón', () => {
	const css = read('assets/css/header.css');

	// El retardo `visibility 0s linear 280ms` es lo que mantiene los enlaces a
	// la vista mientras la altura se anima al cerrar. Vive dentro del shorthand
	// `transition`, así que CUALQUIER redefinición posterior del shorthand que
	// no lo repita lo borra en silencio: pasó con el bloque
	// `@supports (interpolate-size: allow-keywords)`, que en Chrome 129+,
	// Safari 17.4+ y Firefox 137+ —o sea casi todo el tráfico móvil— dejaba
	// `transitionProperty` en "height, padding" y hacía desaparecer los 9
	// enlaces de golpe, con una franja vacía colapsando sola [QA 2026-08-16].
	const declaraciones = bloques(css, '.mobile-drawer__sub')
		.concat(bloques(css, '.mobile-drawer__sub.open'))
		.map((cuerpo) => (cuerpo.match(/transition:\s*[^;]+;/) || [])[0])
		.filter(Boolean);

	assert.ok(
		declaraciones.length >= 2,
		'se esperan al menos dos declaraciones de transition (regla base y override de @supports)'
	);

	for (const decl of declaraciones) {
		// La del estado abierto solo cancela el retardo; ahí basta con que
		// nombre a visibility o con que sea un transition-delay suelto.
		assert.match(
			decl,
			/visibility/,
			`esta declaración pisa el shorthand sin repetir visibility y anula el retardo del cierre: ${decl}`
		);
	}
});
