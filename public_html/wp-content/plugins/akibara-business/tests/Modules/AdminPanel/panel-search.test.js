'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { createDOM } = require(path.resolve(__dirname, '../../../../../../../tools/testing/dom-harness.js'));

const SCRIPT_PATH = 'public_html/wp-content/plugins/akibara-business/assets/js/admin-panel.js';

const PANEL_HTML = `
<div class="akb-panel">
	<div class="akb-panel-search">
		<input type="search" id="akb-panel-search-input" class="akb-panel-search__input" placeholder="🔍 Buscar ajuste...">
	</div>
	<nav class="akb-panel-nav">
		<span class="akb-panel-nav__group" data-nav-group="venta" role="presentation">Operaciones &amp; Pedidos</span>
		<a href="?page=akibara-panel&tab=pedidos" class="akb-panel-nav__link" data-tab="pedidos" data-nav-group="venta">Pedidos</a>
		<a href="?page=akibara-panel&tab=envios" class="akb-panel-nav__link" data-tab="envios" data-nav-group="venta">Envíos</a>

		<span class="akb-panel-nav__group" data-nav-group="marketing" role="presentation">Marketing</span>
		<a href="?page=akibara-panel&tab=descuentos" class="akb-panel-nav__link" data-tab="descuentos" data-nav-group="marketing">Descuentos</a>

		<span class="akb-panel-nav__group" data-nav-group="satelites" role="presentation">Pantallas Satélite</span>
		<a href="?page=akibara-pricing-matrix" id="link-pricing" class="akb-panel-nav__link" data-tab="satelite_pricing_matrix" data-nav-group="satelites" data-keywords="matriz precios tarifas editoriales">Matriz de Precios</a>
		<a href="?page=akibara-search" id="link-search" class="akb-panel-nav__link" data-tab="satelite_search" data-nav-group="satelites" data-keywords="buscador indice facetas sinonimos">Buscador</a>
	</nav>
</div>
`;

test('Panel Search: filtra por texto o keywords ("precios" / "tarifas"), muestra Matriz de Precios y oculta grupos sin coincidencia', () => {
	const dom = createDOM({ html: PANEL_HTML });

	try {
		dom.loadScript(SCRIPT_PATH);
		dom.dispatch(dom.document, 'DOMContentLoaded');

		const linkPricing = dom.document.getElementById('link-pricing');
		const linkSearch = dom.document.getElementById('link-search');
		const groupVenta = dom.document.querySelector('.akb-panel-nav__group[data-nav-group="venta"]');
		const groupMarketing = dom.document.querySelector('.akb-panel-nav__group[data-nav-group="marketing"]');
		const groupSatelites = dom.document.querySelector('.akb-panel-nav__group[data-nav-group="satelites"]');

		// 1. Búsqueda por "precios"
		dom.fill('#akb-panel-search-input', 'precios');

		assert.equal(linkPricing.classList.contains('is-hidden'), false, 'Matriz de Precios debe ser visible al buscar "precios"');
		assert.equal(linkSearch.classList.contains('is-hidden'), true, 'Buscador debe estar oculto al buscar "precios"');
		assert.equal(groupSatelites.classList.contains('is-hidden'), false, 'Grupo Pantallas Satélite debe ser visible');
		assert.equal(groupVenta.classList.contains('is-hidden'), true, 'Grupo Venta debe estar oculto');
		assert.equal(groupMarketing.classList.contains('is-hidden'), true, 'Grupo Marketing debe estar oculto');

		// 2. Búsqueda por keyword sinónimo "tarifas"
		dom.fill('#akb-panel-search-input', 'tarifas');

		assert.equal(linkPricing.classList.contains('is-hidden'), false, 'Matriz de Precios debe ser visible al buscar por keyword "tarifas"');
		assert.equal(linkSearch.classList.contains('is-hidden'), true, 'Buscador debe estar oculto al buscar "tarifas"');
		assert.equal(groupSatelites.classList.contains('is-hidden'), false, 'Grupo Pantallas Satélite debe ser visible');
		assert.equal(groupVenta.classList.contains('is-hidden'), true, 'Grupo Venta debe permanecer oculto');
		assert.equal(groupMarketing.classList.contains('is-hidden'), true, 'Grupo Marketing debe permanecer oculto');

		// 3. Limpiar búsqueda muestra todo de nuevo
		dom.fill('#akb-panel-search-input', '');

		assert.equal(linkPricing.classList.contains('is-hidden'), false);
		assert.equal(linkSearch.classList.contains('is-hidden'), false);
		assert.equal(groupVenta.classList.contains('is-hidden'), false);
		assert.equal(groupMarketing.classList.contains('is-hidden'), false);
		assert.equal(groupSatelites.classList.contains('is-hidden'), false);
	} finally {
		dom.teardown();
	}
});

test('Panel Search: un enlace .akb-panel-nav__link sin data-tab no lanza TypeError', () => {
	const htmlWithBrokenLink = `
	<div class="akb-panel">
		<div class="akb-panel-search">
			<input type="search" id="akb-panel-search-input" class="akb-panel-search__input" value="">
		</div>
		<nav class="akb-panel-nav">
			<span class="akb-panel-nav__group" data-nav-group="custom">Personalizado</span>
			<a href="?page=test" class="akb-panel-nav__link">Enlace sin data-tab</a>
		</nav>
	</div>
	`;

	const dom = createDOM({ html: htmlWithBrokenLink });

	try {
		dom.loadScript(SCRIPT_PATH);
		dom.dispatch(dom.document, 'DOMContentLoaded');

		assert.doesNotThrow(() => {
			dom.fill('#akb-panel-search-input', 'prueba');
		}, 'No debe arrojar TypeError si data-tab está ausente');

		const link = dom.document.querySelector('.akb-panel-nav__link');
		assert.equal(link.classList.contains('is-hidden'), true);
	} finally {
		dom.teardown();
	}
});
