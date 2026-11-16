'use strict';

/**
 * Comportamiento del recordatorio de intención del invitado en wishlist.js
 * ejecutado sobre el árbol DOM real mediante `tools/testing/dom-harness.js`.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { createDOM } = require('../../../../../../../tools/testing/dom-harness.js');

const SCRIPT_REL = 'public_html/wp-content/plugins/akibara-business/assets/js/wishlist.js';

function baseConfig(overrides = {}) {
	return Object.assign(
		{
			ajaxUrl: 'https://akibara.cl/wp-admin/admin-ajax.php',
			nonce: 'nonce-123',
			isLogged: false,
			isLoggedIn: false,
			savedIds: [],
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
				guestTitle: 'Guarda tus favoritos',
				guestBody: 'Inicia sesión para guardar {producto}.',
				guestCta: 'Iniciar sesión',
				guestDismiss: 'Seguir mirando',
				guestClose: 'Cerrar',
				guestMarker: '{producto}',
			},
		},
		overrides
	);
}

test('Un invitado que abre el diálogo y lo descarta no deja intención pendiente en sessionStorage', () => {
	const dom = createDOM({
		html: '<button type="button" class="akb-wl-toggle" data-product-id="4210" data-name="Chainsaw Man 01" aria-pressed="false" aria-haspopup="dialog"></button>',
		globals: {
			akbWishlist: baseConfig({ guestPrompt: true }),
		},
	});

	try {
		dom.loadScript(SCRIPT_REL);
		dom.click('.akb-wl-toggle');

		const dialog = dom.document.querySelector('dialog.akb-wl-login');
		assert.ok(dialog, 'El diálogo de invitado debe crearse al pulsar el corazón.');
		assert.equal(
			dom.window.sessionStorage.getItem('akbWishlistPending'),
			null,
			'Abrir el aviso todavía no guarda la intención de login.'
		);

		dom.click('.akb-wl-login__dismiss');
		assert.equal(
			dom.window.sessionStorage.getItem('akbWishlistPending'),
			null,
			'Descartar el aviso con «Seguir mirando» deja sessionStorage limpio.'
		);
	} finally {
		dom.teardown();
	}
});

test('Pulsar el CTA «Iniciar sesión» del diálogo persiste productId y timestamp en sessionStorage antes de navegar', () => {
	const dom = createDOM({
		html: '<button type="button" class="akb-wl-toggle" data-product-id="4210" data-name="Chainsaw Man 01" aria-pressed="false"></button>',
		globals: {
			akbWishlist: baseConfig({ guestPrompt: true }),
		},
	});

	try {
		dom.loadScript(SCRIPT_REL);
		dom.click('.akb-wl-toggle');
		dom.click('.akb-wl-login__cta');

		const raw = dom.window.sessionStorage.getItem('akbWishlistPending');
		assert.ok(raw, 'El CTA de acceso debe registrar akbWishlistPending.');
		const parsed = JSON.parse(raw);
		assert.equal(parsed.productId, 4210);
		assert.equal(typeof parsed.at, 'number');
	} finally {
		dom.teardown();
	}
});

test('Al volver autenticado con una intención vigente (< 30 min), se ejecuta intent=add y se consume la clave', async () => {
	const requests = [];
	const dom = createDOM({
		html: '<button type="button" class="akb-wl-toggle" data-product-id="4210" data-name="Chainsaw Man 01" aria-pressed="false"></button>',
		globals: {
			akbWishlist: baseConfig({ isLogged: true, isLoggedIn: true, savedIds: [] }),
			fetch: async (url, opts) => {
				requests.push({ url, body: opts.body });
				return {
					ok: true,
					status: 200,
					json: async () => ({
						success: true,
						data: { state: 'added', product_id: 4210, count: 1, saved_ids: [4210] },
					}),
				};
			},
		},
	});

	try {
		dom.window.sessionStorage.setItem(
			'akbWishlistPending',
			JSON.stringify({ productId: 4210, at: Date.now() - 5000 })
		);
		dom.loadScript(SCRIPT_REL);
		await new Promise((r) => setTimeout(r, 20));

		assert.equal(requests.length, 1, 'Debe disparar un único POST para completar la intención.');
		const params = new URLSearchParams(requests[0].body);
		assert.equal(params.get('product_id'), '4210');
		assert.equal(params.get('intent'), 'add');
		assert.equal(dom.window.sessionStorage.getItem('akbWishlistPending'), null);

		const btn = dom.document.querySelector('.akb-wl-toggle');
		assert.equal(btn.classList.contains('is-saved'), true);
		assert.equal(btn.getAttribute('aria-pressed'), 'true');
	} finally {
		dom.teardown();
	}
});

test('Una intención vencida (> 30 min) se descarta sin disparar peticiones AJAX', async () => {
	let fetchCalled = false;
	const dom = createDOM({
		html: '<button type="button" class="akb-wl-toggle" data-product-id="4210" data-name="Chainsaw Man 01" aria-pressed="false"></button>',
		globals: {
			akbWishlist: baseConfig({ isLogged: true, isLoggedIn: true, savedIds: [] }),
			fetch: async () => {
				fetchCalled = true;
				throw new Error('No debe llamarse');
			},
		},
	});

	try {
		dom.window.sessionStorage.setItem(
			'akbWishlistPending',
			JSON.stringify({ productId: 4210, at: Date.now() - 1800001 })
		);
		dom.loadScript(SCRIPT_REL);
		await new Promise((r) => setTimeout(r, 10));

		assert.equal(fetchCalled, false, 'Intención mayor a 30 minutos no debe ejecutarse.');
		assert.equal(dom.window.sessionStorage.getItem('akbWishlistPending'), null);
	} finally {
		dom.teardown();
	}
});
