'use strict';

const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');

const css = readFileSync(
	join(__dirname, '..', 'assets', 'css', 'woocommerce.css'),
	'utf8'
);
const toolbarPhp = readFileSync(
	join(__dirname, '..', 'inc', 'flyout-button.php'),
	'utf8'
);

test('desktop toolbar leads with the total and keeps ordering next to it', () => {
	assert.match(
		css,
		/\.akb-shop-toolbar \.akb-search-correction \{\s*order: -2;\s*flex: 1 1 100%;\s*\}/
	);
	// El conteo encabeza la fila (order 0) y el control de orden la cierra
	// (order 1) anclado al borde derecho por el margen auto del conteo. Antes
	// era al revés: el select abría por la izquierda y el total quedaba
	// empujado al extremo opuesto.
	assert.match(
		css,
		/\.akb-shop-toolbar \.akb-result-count \{\s*order: 0;[\s\S]*margin: 0 auto 0 0;[\s\S]*white-space: nowrap;/
	);
	assert.match(
		css,
		/body\.archive\.woocommerce-page \.akb-shop-toolbar \.woocommerce-ordering \{\s*order: 1;/
	);
	// Etiqueta visible del control de orden (solo escritorio).
	assert.match(
		css,
		/\.akb-shop-toolbar \.woocommerce-ordering::before \{\s*content: "Ordenar por:";/
	);
});

test('empty result pages do not reserve the height of the missing sort control', () => {
	const desktop = css.slice(css.indexOf('@media (min-width: 769px)', css.indexOf('Shop toolbar wrapper')));
	assert.match(desktop, /\.akb-shop-toolbar \{[\s\S]*min-height: 44px;/);
	assert.match(desktop, /\.akb-shop-toolbar\.akb-shop-toolbar--empty \{\s*min-height: 0;\s*\}/);
});

test('mobile toolbar orders correction, total and 44px controls', () => {
	const mobile = css.slice(css.indexOf('@media (max-width: 768px)', css.indexOf('Shop toolbar wrapper')));
	assert.match(mobile, /\.akb-shop-toolbar \.akb-search-correction \{\s*order: -3;/);
	assert.match(mobile, /\.akb-shop-toolbar \.akb-result-count \{\s*order: -2;/);
	assert.match(mobile, /min-height: 44px;/);
	assert.match(mobile, /\.akb-shop-toolbar--empty \.akb-filter-toggle \{\s*flex-basis: 100%;/);
});

test('zero-result pages keep a complete toolbar and accessible filter trigger', () => {
	assert.match(toolbarPhp, /woocommerce_no_products_found', 'akibara_gp_open_shop_toolbar', 11/);
	assert.match(toolbarPhp, /woocommerce_no_products_found', 'akibara_gp_render_flyout_trigger', 13/);
	assert.match(toolbarPhp, /woocommerce_no_products_found', 'akibara_gp_close_shop_toolbar', 14/);
});

test('la barra de resultados comparte el canalón de la grilla en pantallas anchas', () => {
	const loop = readFileSync(
		join(__dirname, '..', 'assets', 'css', 'loop.css'),
		'utf8'
	);

	// La grilla se centra con un tope propio. Si el toolbar se estira al 100 %
	// del contenedor, en cuanto la ventana supera ese tope el conteo se descuelga
	// a la izquierda de la primera portada y «Ordenar por» a la derecha de la
	// última — 13 px por lado en 1440, y creciendo con el ancho [QA 2026-08-16].
	const tope = loop.match(
		/\.woocommerce ul\.products \{[\s\S]*?max-width:\s*(\d+)px;[\s\S]*?\}/
	);
	assert.ok(tope, 'la grilla debe declarar su max-width en loop.css');

	// La regla de escritorio se identifica por su contenido (`min-height: 44px`,
	// la reserva de alto del control de orden) y no por el @media que la
	// envuelve: acotar el media query por llaves es frágil, porque el primer
	// cierre que aparece es el de la regla interna, no el del bloque.
	// Los comentarios se despojan ANTES de afirmar: este mismo cuerpo explica el
	// arreglo citando `max-width: 1400px`, y sin quitarlos el candado pasaría por
	// el texto de la explicación aunque la declaración no existiera.
	const reglas = [...css.matchAll(/\.akb-shop-toolbar \{([\s\S]*?)\}/g)]
		.map((m) => m[1].replace(/\/\*[\s\S]*?\*\//g, ''));
	const regla = reglas.find((cuerpo) => /min-height:\s*44px;/.test(cuerpo));
	assert.ok(regla, 'debe existir la regla de .akb-shop-toolbar en escritorio');
	assert.match(
		regla,
		new RegExp('max-width:\\s*' + tope[1] + 'px;'),
		'el toolbar debe usar el MISMO tope que la grilla para no descolgarse'
	);
	assert.match(
		regla,
		/margin-inline:\s*auto;/,
		'y centrarse igual que ella'
	);

	// `margin-inline` tiene que ir DESPUÉS del shorthand `margin`: al revés, el
	// shorthand lo borra y el defecto vuelve sin que el CSS lo aparente.
	assert.ok(
		regla.indexOf('margin-inline') > regla.indexOf('margin:'),
		'margin-inline debe declararse después del shorthand margin'
	);
});
