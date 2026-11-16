'use strict';

/**
 * Carga diferida de `wishlist-guest-prompt.css` desde `wishlist.js`
 * verificada con el DOM Test Harness oficial (`createDOM`).
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { createDOM } = require('../../../../../../../tools/testing/dom-harness.js');

const SCRIPT_REL = 'public_html/wp-content/plugins/akibara-business/assets/js/wishlist.js';
const HOJA = 'https://akibara.test/wp-content/plugins/akibara-business/assets/css/wishlist-guest-prompt.css?ver=abc';

function runWithConfig(overrides = {}) {
	const dom = createDOM({
		html: '<button type="button" class="akb-wl-toggle" data-product-id="1"></button>',
		globals: {
			akbWishlist: Object.assign(
				{
					ajaxUrl: '/wp-admin/admin-ajax.php',
					nonce: 'n',
					isLogged: false,
					isLoggedIn: false,
					savedIds: [],
					guestPrompt: true,
					guestStyle: HOJA,
					loginUrl: '/mi-cuenta/',
					labels: {},
				},
				overrides
			),
		},
	});

	dom.loadScript(SCRIPT_REL);
	return dom;
}

test('Invitado con guestPrompt activo y soporte de <dialog> inyecta el <link id="akb-wishlist-guest-css"> una sola vez', () => {
	const dom = runWithConfig();
	try {
		const links = dom.document.head.querySelectorAll('link#akb-wishlist-guest-css');
		assert.equal(links.length, 1);
		assert.equal(links[0].getAttribute('rel'), 'stylesheet');
		assert.equal(links[0].getAttribute('href'), HOJA);

		// Re-ejecutar el script es idempotente: no duplica el <link>.
		dom.loadScript(SCRIPT_REL);
		assert.equal(dom.document.head.querySelectorAll('link#akb-wishlist-guest-css').length, 1);
	} finally {
		dom.teardown();
	}
});

test('Cliente autenticado no descarga la hoja CSS del aviso de invitado', () => {
	const dom = runWithConfig({ isLogged: true, isLoggedIn: true });
	try {
		assert.equal(dom.document.head.querySelectorAll('link#akb-wishlist-guest-css').length, 0);
	} finally {
		dom.teardown();
	}
});

test('Con guestPrompt desactivado no se descarga la hoja CSS del aviso', () => {
	const dom = runWithConfig({ guestPrompt: false });
	try {
		assert.equal(dom.document.head.querySelectorAll('link#akb-wishlist-guest-css').length, 0);
	} finally {
		dom.teardown();
	}
});

test('Sin soporte de HTMLDialogElement no se descarga la hoja CSS del aviso', () => {
	const dom = createDOM({
		html: '<button type="button" class="akb-wl-toggle" data-product-id="1"></button>',
		globals: {
			HTMLDialogElement: undefined,
			akbWishlist: {
				ajaxUrl: '/wp-admin/admin-ajax.php',
				nonce: 'n',
				isLogged: false,
				isLoggedIn: false,
				savedIds: [],
				guestPrompt: true,
				guestStyle: HOJA,
				loginUrl: '/mi-cuenta/',
				labels: {},
			},
		},
	});

	try {
		dom.loadScript(SCRIPT_REL);
		assert.equal(dom.document.head.querySelectorAll('link#akb-wishlist-guest-css').length, 0);
	} finally {
		dom.teardown();
	}
});
