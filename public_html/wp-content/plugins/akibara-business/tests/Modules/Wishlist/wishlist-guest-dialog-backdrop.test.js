'use strict';

/**
 * Cierre por toque en el fondo del diálogo de invitado (`wishlist.js`)
 * verificado en el DOM Test Harness oficial (`createDOM`).
 *
 * B5-R3: el `<dialog>` es el `event.target` tanto en el `::backdrop` como en su
 * propio padding. El listener debe cerrar únicamente cuando las coordenadas caen
 * fuera del `getBoundingClientRect()` del diálogo.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { createDOM } = require('../../../../../../../tools/testing/dom-harness.js');

const SCRIPT_REL = 'public_html/wp-content/plugins/akibara-business/assets/js/wishlist.js';

function createGuestDOM() {
	const dom = createDOM({
		html: '<button id="heart" type="button" class="akb-wl-toggle" data-product-id="99" data-name="Berserk 1" aria-pressed="false"></button>',
		globals: {
			akbWishlist: {
				ajaxUrl: '/wp-admin/admin-ajax.php',
				nonce: 'n',
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
					guestTitle: 'Guarda tus favoritos',
					guestBody: 'Entra para guardar {producto}.',
					guestCta: 'Iniciar sesión',
					guestDismiss: 'Seguir mirando',
					guestClose: 'Cerrar',
					guestMarker: '{producto}',
				},
			},
		},
	});

	dom.loadScript(SCRIPT_REL);
	dom.click('#heart');

	const dialog = dom.document.querySelector('dialog.akb-wl-login');
	assert.ok(dialog, 'El diálogo de invitado debe existir.');
	dialog.getBoundingClientRect = () => ({
		left: 100,
		right: 400,
		top: 200,
		bottom: 380,
		width: 300,
		height: 180,
	});

	return { dom, dialog };
}

test('Tocar dentro del relleno del diálogo (target === dialog pero dentro de la caja) NO lo cierra', () => {
	const { dom, dialog } = createGuestDOM();
	try {
		assert.equal(dialog.hasAttribute('open'), true);
		dialog.dispatchEvent(
			new dom.window.MouseEvent('mousedown', {
				bubbles: true,
				clientX: 115,
				clientY: 250,
			})
		);
		assert.equal(dialog.hasAttribute('open'), true, 'Un clic en el padding interno no debe cerrar el modal.');
	} finally {
		dom.teardown();
	}
});

test('Tocar sobre un hijo interno del diálogo nunca lo cierra', () => {
	const { dom, dialog } = createGuestDOM();
	try {
		const body = dialog.querySelector('.akb-wl-login__body');
		body.dispatchEvent(
			new dom.window.MouseEvent('mousedown', {
				bubbles: true,
				clientX: 10,
				clientY: 10,
			})
		);
		assert.equal(dialog.hasAttribute('open'), true);
	} finally {
		dom.teardown();
	}
});

test('Tocar fuera de la caja visible (en cualquiera de los cuatro lados del backdrop) cierra el diálogo y restaura foco', () => {
	const outsidePoints = [
		{ x: 50, y: 260 },
		{ x: 450, y: 260 },
		{ x: 250, y: 120 },
		{ x: 250, y: 420 },
	];

	for (const pt of outsidePoints) {
		const { dom, dialog } = createGuestDOM();
		try {
			dialog.dispatchEvent(
				new dom.window.MouseEvent('mousedown', {
					bubbles: true,
					clientX: pt.x,
					clientY: pt.y,
				})
			);
			assert.equal(
				dialog.hasAttribute('open'),
				false,
				`Toque en backdrop (${pt.x}, ${pt.y}) debe cerrar el diálogo.`
			);
			assert.equal(
				dom.document.documentElement.classList.contains('akb-wl-login-open'),
				false
			);
		} finally {
			dom.teardown();
		}
	}
});
