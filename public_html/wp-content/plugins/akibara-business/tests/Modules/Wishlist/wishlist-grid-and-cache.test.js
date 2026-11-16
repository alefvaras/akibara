'use strict';

/**
 * Pruebas conductuales de la grilla de Favoritos, pestañas binarias, casos de borde
 * al eliminar tarjetas y autosanación de caché pública (LiteSpeed/Cloudflare)
 * ejecutadas sobre `tools/testing/dom-harness.js` (`createDOM`).
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { createDOM } = require('../../../../../../../tools/testing/dom-harness.js');

const SCRIPT_REL = 'public_html/wp-content/plugins/akibara-business/assets/js/wishlist.js';

function createWishlistGridHTML() {
	return `
		<span data-akb-wishlist-count="2">2</span>
		<div class="woocommerce akb-wl-grid-wrap">
			<p class="akb-wl-grid__lead">
				<span class="akb-wl-grid__lead-count">2 productos guardados</span>
				<span class="akb-wl-grid__nota">1 ya no está en el catálogo.</span>
			</p>
			<nav class="akb-wl-filters" aria-label="Filtrar favoritos">
				<button type="button" class="akb-wl-filter-tab is-active" data-wl-filter="all" aria-pressed="true">Todos (2)</button>
				<button type="button" class="akb-wl-filter-tab" data-wl-filter="instock" aria-pressed="false">🟢 En Bodega · Entrega Inmediata (1)</button>
				<button type="button" class="akb-wl-filter-tab" data-wl-filter="outofstock" aria-pressed="false">🔴 Agotados (1)</button>
			</nav>
			<div class="akb-wl-empty-filter" style="display:none">
				<p class="akb-wl-empty-filter__text">No tienes favoritos en esta categoría.</p>
				<button type="button" class="akb-wl-btn-reset" data-wl-filter="all">Ver todos los favoritos</button>
			</div>
			<ul class="products columns-3 akb-wl-grid">
				<li class="product instock" data-test-id="101">
					<button type="button" class="akb-wl-toggle is-saved" data-product-id="101" data-name="Fire Force 08" aria-pressed="true"></button>
				</li>
				<li class="product outofstock" data-test-id="202">
					<button type="button" class="akb-wl-toggle is-saved" data-product-id="202" data-name="Berserk 01" aria-pressed="true"></button>
				</li>
			</ul>
		</div>
	`;
}

function loggedConfig(overrides = {}) {
	return Object.assign(
		{
			ajaxUrl: 'https://akibara.cl/wp-admin/admin-ajax.php',
			nonce: 'nonce-ok',
			isLogged: true,
			isLoggedIn: true,
			savedIds: [101, 202],
			guestPrompt: true,
			guestStyle: 'https://akibara.cl/guest.css',
			loginUrl: 'https://akibara.cl/mi-cuenta/',
			labels: {
				save: 'Guardar',
				saved: 'Guardado',
				addAria: 'Guardar en favoritos',
				savedAria: 'Quitar de favoritos',
				addAriaNamed: 'Guardar «%s» en favoritos',
				savedAriaNamed: 'Quitar «%s» de favoritos',
				announceAdded: '«%s» guardado en favoritos',
				announceRemoved: '«%s» quitado de favoritos',
			},
		},
		overrides
	);
}

test('Las pestañas binarias filtran En Bodega y Agotados, actualizan aria-pressed y el botón de reset restaura Todos', () => {
	const dom = createDOM({
		html: createWishlistGridHTML(),
		globals: { akbWishlist: loggedConfig() },
	});

	try {
		dom.loadScript(SCRIPT_REL);

		const cardIn = dom.document.querySelector('[data-test-id="101"]');
		const cardOut = dom.document.querySelector('[data-test-id="202"]');
		const tabAll = dom.document.querySelector('.akb-wl-filter-tab[data-wl-filter="all"]');
		const tabIn = dom.document.querySelector('.akb-wl-filter-tab[data-wl-filter="instock"]');
		const tabOut = dom.document.querySelector('.akb-wl-filter-tab[data-wl-filter="outofstock"]');

		dom.click(tabIn);
		assert.equal(tabIn.classList.contains('is-active'), true);
		assert.equal(tabIn.getAttribute('aria-pressed'), 'true');
		assert.equal(tabAll.getAttribute('aria-pressed'), 'false');
		assert.equal(cardIn.style.display, '');
		assert.equal(cardOut.style.display, 'none');

		dom.click(tabOut);
		assert.equal(tabOut.classList.contains('is-active'), true);
		assert.equal(tabOut.getAttribute('aria-pressed'), 'true');
		assert.equal( tabIn.getAttribute('aria-pressed'), 'false');
		assert.equal(cardIn.style.display, 'none');
		assert.equal(cardOut.style.display, '');

		dom.click('.akb-wl-btn-reset');
		assert.equal(tabAll.classList.contains('is-active'), true);
		assert.equal(tabAll.getAttribute('aria-pressed'), 'true');
		assert.equal(cardIn.style.display, '');
		assert.equal(cardOut.style.display, '');
	} finally {
		dom.teardown();
	}
});

test('Quitar el único favorito del filtro activo muestra .akb-wl-empty-filter sin destruir .akb-wl-grid__nota y actualiza contadores de pestañas', async () => {
	const dom = createDOM({
		html: createWishlistGridHTML(),
		globals: {
			akbWishlist: loggedConfig(),
			fetch: async () => ({
				ok: true,
				status: 200,
				json: async () => ({
					success: true,
					data: { state: 'removed', product_id: 101, count: 1, saved_ids: [202] },
				}),
			}),
		},
	});

	try {
		dom.loadScript(SCRIPT_REL);

		// Activar el filtro "En Bodega" (donde sólo vive el producto 101)
		dom.click('.akb-wl-filter-tab[data-wl-filter="instock"]');
		// Quitar el producto 101
		dom.click('.akb-wl-toggle[data-product-id="101"]');

		// Esperar a que resuelva fetch + los 220ms de removeCardAnimated
		await new Promise((r) => setTimeout(r, 260));

		// 1. El lead actualizó a singular y conservó intacta la nota secundaria .akb-wl-grid__nota
		const leadCount = dom.document.querySelector('.akb-wl-grid__lead-count');
		const nota = dom.document.querySelector('.akb-wl-grid__nota');
		assert.equal(leadCount.textContent, '1 producto guardado');
		assert.ok(nota, 'El nodo .akb-wl-grid__nota debe seguir presente en el DOM.');
		assert.equal(nota.textContent, '1 ya no está en el catálogo.');

		// 2. Los contadores de las 3 pestañas se actualizaron en vivo
		assert.equal(
			dom.document.querySelector('.akb-wl-filter-tab[data-wl-filter="all"]').textContent,
			'Todos (1)'
		);
		assert.equal(
			dom.document.querySelector('.akb-wl-filter-tab[data-wl-filter="instock"]').textContent,
			'🟢 En Bodega · Entrega Inmediata (0)'
		);
		assert.equal(
			dom.document.querySelector('.akb-wl-filter-tab[data-wl-filter="outofstock"]').textContent,
			'🔴 Agotados (1)'
		);

		// 3. Como estábamos en la pestaña "En Bodega" y quedó con 0 visibles (pero aún queda 1 agotado),
		// debe mostrarse el aviso .akb-wl-empty-filter en lugar de dejar la pantalla vacía.
		const emptyFilter = dom.document.querySelector('.akb-wl-empty-filter');
		assert.equal(emptyFilter.style.display, 'block');
	} finally {
		dom.teardown();
	}
});

test('Autosanación en página pública cacheada por LiteSpeed: cookie de sesión activa preserva localStorage e hidrata corazones sin abrir modal de invitado', async () => {
	let toggleCalled = false;
	const dom = createDOM({
		html: `
			<span data-akb-wishlist-count="0">0</span>
			<button type="button" class="akb-wl-toggle" data-product-id="101" data-name="Fire Force 08" aria-pressed="false" aria-haspopup="dialog"></button>
		`,
		globals: {
			// HTML cacheado para invitado: isLogged=false y savedIds=[]
			akbWishlist: loggedConfig({ isLogged: false, isLoggedIn: false, savedIds: [] }),
			fetch: async () => {
				toggleCalled = true;
				return {
					ok: true,
					status: 200,
					json: async () => ({
						success: true,
						data: { state: 'removed', product_id: 101, count: 0, saved_ids: [] },
					}),
				};
			},
		},
	});

	try {
		// El cliente sí tiene sesión activa (cookie akb_owned_gen) y localStorage previo
		dom.document.cookie = 'akb_owned_gen=1700000000';
		dom.window.localStorage.setItem('akb_wl_ids', JSON.stringify([101]));

		dom.loadScript(SCRIPT_REL);

		const btn = dom.document.querySelector('.akb-wl-toggle[data-product-id="101"]');
		const badge = dom.document.querySelector('[data-akb-wishlist-count]');

		// No borró localStorage y pintó el corazón + contador inmediatamente
		assert.equal(dom.window.localStorage.getItem('akb_wl_ids'), '[101]');
		assert.equal(btn.classList.contains('is-saved'), true);
		assert.equal(btn.getAttribute('aria-pressed'), 'true');
		assert.equal(btn.hasAttribute('aria-haspopup'), false, 'Debe limpiar aria-haspopup="dialog" heredado del HTML guest cacheado.');
		assert.equal(badge.textContent, '1');

		// Al hacer clic, ejecuta toggle real en vez de abrir el modal de invitado
		dom.click(btn);
		await new Promise((r) => setTimeout(r, 20));
		assert.equal(toggleCalled, true);
		assert.equal(dom.document.querySelector('dialog.akb-wl-login'), null);
	} finally {
		dom.teardown();
	}
});

test('Autosanación en página pública cacheada sin localStorage previo sincroniza vía intent=sync', async () => {
	const requests = [];
	const dom = createDOM({
		html: `
			<span data-akb-wishlist-count="0">0</span>
			<button type="button" class="akb-wl-toggle" data-product-id="555" data-name="Dan Da Dan 01" aria-pressed="false" aria-haspopup="dialog"></button>
		`,
		globals: {
			akbWishlist: loggedConfig({ isLogged: false, isLoggedIn: false, savedIds: [] }),
			fetch: async (url, opts) => {
				requests.push(opts.body);
				return {
					ok: true,
					status: 200,
					json: async () => ({
						success: true,
						data: { state: 'synced', saved_ids: [555], count: 1 },
					}),
				};
			},
		},
	});

	try {
		dom.document.cookie = 'akb_owned_gen=1700000000';
		dom.loadScript(SCRIPT_REL);
		await new Promise((r) => setTimeout(r, 25));

		assert.equal(requests.length, 1);
		const params = new URLSearchParams(requests[0]);
		assert.equal(params.get('intent'), 'sync');

		const btn = dom.document.querySelector('.akb-wl-toggle[data-product-id="555"]');
		assert.equal(btn.classList.contains('is-saved'), true);
		assert.equal(dom.document.querySelector('[data-akb-wishlist-count]').textContent, '1');
	} finally {
		dom.teardown();
	}
});

test('Navegación PJAX (akb:pjax) rehidrata tarjetas recién inyectadas en el DOM', () => {
	const dom = createDOM({
		html: `<div id="catalog-root"></div>`,
		globals: {
			akbWishlist: loggedConfig({ savedIds: [777] }),
		},
	});

	try {
		dom.loadScript(SCRIPT_REL);

		// Simular que PJAX reemplaza la grilla de productos con una tarjeta sin hidratar
		const root = dom.document.getElementById('catalog-root');
		root.innerHTML = `
			<button type="button" class="akb-wl-toggle" data-product-id="777" data-name="Chainsaw Man 01" aria-pressed="false" aria-haspopup="dialog"></button>
		`;

		dom.document.dispatchEvent(new dom.window.CustomEvent('akb:pjax'));

		const btn = dom.document.querySelector('.akb-wl-toggle[data-product-id="777"]');
		assert.equal(btn.classList.contains('is-saved'), true);
		assert.equal(btn.getAttribute('aria-pressed'), 'true');
		assert.equal(btn.hasAttribute('aria-haspopup'), false);
	} finally {
		dom.teardown();
	}
});
