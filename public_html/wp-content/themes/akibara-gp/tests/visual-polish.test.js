const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const themeRoot = path.resolve(__dirname, '..');
const read = (relative) => fs.readFileSync(path.join(themeRoot, relative), 'utf8');

test('empty cart reserves recommendation space before asynchronous render', () => {
	const css = read('assets/css/empty-states.css');
	const critical = read('assets/css/critical.css');
	assert.match(
		css,
		/\.wp-block-woocommerce-empty-cart-block\s*\{[^}]*min-height:\s*86rem;/s
	);
	assert.match(
		critical,
		/\.woocommerce-cart\s+\.wp-block-woocommerce-cart\.is-loading,[\s\S]*min-height:\s*86rem;/
	);
	// La reserva debe estar anclada a `.is-loading` en AMBAS hojas: sin ese
	// ancla el min-height sobrevive a la hidratación e infla el carrito lleno.
	for (const sheet of [css, critical]) {
		assert.doesNotMatch(
			sheet,
			/\.woocommerce-cart\s+\.wp-block-woocommerce-cart\s*[,{]/,
			'la reserva anti-CLS no debe aplicarse al carrito ya hidratado'
		);
	}
});

test('el carrito reserva alto tambien en el ESQUELETO de React, en regla aparte', () => {
	// PC-3 / UM-11 / PM-8. Tras quitar `.is-loading`, React pinta el esqueleto
	// del carrito LLENO hasta que llega el Store API: el pie entraba y salía de
	// la pantalla (CLS 0,28-0,48 en el carrito vacío y en el lleno, medido en el
	// espejo el 2026-09-22). La reserva se ata a las filas de carga, que ninguna
	// fila real lleva, así que el carrito hidratado no hereda el alto.
	const css = read('assets/css/empty-states.css');
	const critical = read('assets/css/critical.css');
	const esqueleto = String.raw`\.woocommerce-cart\s+\.wp-block-woocommerce-cart:has\(\.wc-block-cart-items\s+\.wc-block-components-skeleton__element\)`;
	for (const sheet of [css, critical]) {
		assert.match(sheet, new RegExp(esqueleto + String.raw`\s*\{\s*min-height:\s*86rem;\s*\}`));
		// Y el resumen no se pinta mientras dure: se rellenaba a trozos (+670 px).
		assert.match(sheet, new RegExp(esqueleto + String.raw`\s+\.wc-block-cart__sidebar\s*\{\s*visibility:\s*hidden;\s*\}`));
		// Nunca en la misma lista que `.is-loading`: un navegador sin `:has()`
		// descartaría la regla entera y con ella la reserva de siempre.
		// (Sin comentarios: los que explican esta misma regla no son selectores.)
		const reglas = sheet.replace(/\/\*[\s\S]*?\*\//g, '');
		assert.doesNotMatch(reglas, /is-loading,[^{]*:has\(/);
		assert.doesNotMatch(reglas, /:has\([^{]*,[^{]*is-loading/);
	}
});

test('critical css hides the REAL cart drawer selectors, not stale ones', (t) => {
	// Este test valida el ACOPLE tema↔plugin, que solo existe en el árbol
	// fuente. Los gates de artefacto (qa-theme.sh contra el ZIP congelado
	// 2.1.48, test de release contra el candidato extraído) copian los tests a
	// un scratch sin `plugins/`: ahí el contrato no es evaluable y se omite en
	// vez de reventar por una ruta ausente o por un artefacto histórico que es
	// inmutable por diseño.
	const pluginFile = path.resolve(
		themeRoot,
		'../../plugins/akibara-business/src/Modules/NativeCart/NativeCart.php'
	);
	if (!fs.existsSync(pluginFile)) {
		t.skip('árbol sin plugins/ (gate de artefacto): el acople no es evaluable aquí');
		return;
	}

	const critical = read('assets/css/critical.css');
	const drawerMarkup = fs.readFileSync(pluginFile, 'utf8');

	// El drawer real que imprime el plugin. Si el plugin renombra la clase, este
	// test truena ANTES de que el anti-FOUC vuelva a quedar apuntando a un
	// selector muerto (el bug de 2026-07-27: critical.css protegía
	// `.cart-drawer`, del drawer de terceros ya retirado, y el Native Cart
	// parpadeaba sin estilo en cada ventana de CSS lento).
	assert.match(drawerMarkup, /class="akb-cart-drawer\b/);
	assert.match(drawerMarkup, /class="akb-cart-overlay\b/);

	// El crítico debe ocultar exactamente esos selectores…
	assert.match(
		critical,
		/\.akb-cart-drawer\s*\{[^}]*visibility:\s*hidden;[^}]*\}/s
	);
	assert.match(
		critical,
		/\.akb-cart-drawer\s*\{[^}]*transform:\s*translate3d\(100%,\s*0,\s*0\);[^}]*\}/s
	);
	assert.match(critical, /\.akb-cart-overlay\s*\{[^}]*visibility:\s*hidden;/s);
	assert.match(critical, /\.akb-cart-drawer\[data-side="left"\]/);

	// …y no cargar con los huérfanos del drawer viejo.
	assert.doesNotMatch(critical, /\.cart-drawer\s*\{/);
	assert.doesNotMatch(critical, /\.cart-drawer__overlay\s*\{/);
});

test('desktop header compacts and all primary controls meet 44px target', () => {
	const css = read('assets/css/header.css');
	const js = read('assets/js/main.js');
	assert.match(css, /\.akibara-search__lens\s*\{[^}]*width:\s*44px;/s);
	assert.match(css, /\.header-icon\s*\{[^}]*width:\s*44px;[^}]*height:\s*44px;/s);
	assert.match(css, /\.site-header\.is-compact/);
	assert.match(js, /classList\.toggle\(\s*'is-compact'/);
});

test('article labels and product descriptions retain readable geometry', () => {
	// Las etiquetas del formulario de comentarios deben verse. Antes se lograba
	// pisando `.screen-reader-text`, pero esa pelea se perdía contra
	// `wc-blocks.css` y las etiquetas terminaban ENCIMA de los campos: ahora
	// llevan clase propia (ver tests/comment-form.test.js).
	assert.match(read('assets/css/blog.css'), /\.comment-form\s+label\.akb-comment-label/);
	assert.match(
		read('assets/css/woocommerce.css'),
		/\.single-product\s+\.woocommerce-Tabs-panel--description\s*>\s*\*/
	);
});

test('weight-specific fonts are preloaded to avoid swap-driven layout shifts', () => {
	// R11-precarga-peso-equivocado (2026-09-20): se precarga lo que el primer
	// viewport usa (Inter 400/500/700 y Bebas), no el 600, que sólo aparece
	// al tabular o a más de 1.300 px. Y todo lo precargado tiene que existir
	// como fontFace en theme.json, o el navegador baja un fichero que el CSS
	// no declara.
	const enqueue = read('inc/enqueue.php');
	const preloaded = [...enqueue.matchAll(/'((?:bebas-neue|inter)-v\d+-latin-[a-z0-9]+\.woff2)'/g)].map((m) => m[1]);
	assert.deepEqual(preloaded, [
		'bebas-neue-v16-latin-regular.woff2',
		'inter-v18-latin-regular.woff2',
		'inter-v18-latin-500.woff2',
		'inter-v18-latin-700.woff2',
	]);
	const themeJson = read('theme.json');
	for (const file of preloaded) {
		assert.match(themeJson, new RegExp(`file:\\./assets/fonts/${file.replace('.', '\\.')}`), `${file} precargada pero sin fontFace en theme.json`);
		assert.ok(fs.existsSync(path.join(themeRoot, 'assets/fonts', file)), `${file} no existe en assets/fonts`);
	}
});

test('each font face is declared once: theme.json or fonts.css, never both (PC-4)', () => {
	// Auditoría 2026-09-22, PC-4: Bebas Neue estaba en theme.json (WordPress la
	// imprime con wp_print_font_faces en el front, el admin y la vista previa del
	// hero) Y en assets/css/fonts.css, con el mismo fichero. Dos @font-face de la
	// misma familia/peso/estilo son dos caras que el navegador resuelve por orden:
	// si un día divergen (otro fichero, otro unicode-range) gana la que se imprime
	// después, en silencio.
	const theme = JSON.parse(read('theme.json'));
	const cara = (familia, peso, estilo) => `${familia.replace(/["']/g, '').trim()}|${String(peso)}|${estilo || 'normal'}`;
	const deTheme = theme.settings.typography.fontFamilies
		.flatMap((f) => f.fontFace || [])
		.map((f) => cara(f.fontFamily, f.fontWeight, f.fontStyle));
	const fonts = read('assets/css/fonts.css').replace(/\/\*[\s\S]*?\*\//g, '');
	const deCss = [...fonts.matchAll(/@font-face\s*\{([^}]*)\}/g)].map((m) => {
		const d = (p) => ((m[1].match(new RegExp(`${p}\\s*:\\s*([^;]+);`)) || [])[1] || '').trim();
		return cara(d('font-family'), d('font-weight'), d('font-style'));
	});
	assert.ok(deTheme.includes('Bebas Neue|400|normal'), 'Bebas Neue 400 tiene que seguir en theme.json');
	assert.ok(deCss.length > 0, 'fonts.css sigue declarando Saira (fuente elegible del hero)');
	for (const c of deCss) {
		assert.ok(!deTheme.includes(c), `${c} está en theme.json y en fonts.css: sobra una`);
	}
	assert.equal(new Set(deCss).size, deCss.length, 'fonts.css repite una cara');
	// core.css es la concatenación servida: tampoco la trae.
	assert.doesNotMatch(read('assets/css/core.min.css'), /font-family:\s*['"]?Bebas Neue['"]?;\s*font-style/, 'core.min.css sigue con la @font-face de Bebas: regenera');
});

test('the button reset inherits the brand typography (form controls do not inherit by default)', () => {
	// R11-botones-en-arial (2026-09-20): 53 de 83 botones del catálogo se
	// pintaban en Arial porque el reset de `button` no declaraba familia.
	const base = read('assets/css/base.css');
	const bloque = base.match(/\.button,\s*\nbutton,[\s\S]*?\{([\s\S]*?)\}/);
	assert.ok(bloque, 'el bloque del reset de botones existe');
	assert.match(bloque[1], /font-family:\s*inherit;/);
});
