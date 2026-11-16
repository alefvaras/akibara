'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { createDOM } = require('../../../../../../../tools/testing/dom-harness.js');

const SCRIPT_REL = 'public_html/wp-content/plugins/akibara-business/assets/js/native-cart.js';

test('native-cart.js: aplica inert al fondo al abrir el cajón y enlaza títulos de líneas y recomendaciones con permalink', async () => {
	const dom = createDOM({
		html: `
			<main id="primary"><a href="/tienda/">Tienda</a></main>
			<a class="header-icon akb-cart-trigger" aria-label="Carrito"></a>
			<div id="akb-native-cart-overlay"></div>
			<div id="akb-native-cart-drawer">
				<button type="button" class="akb-cart-close">×</button>
				<div class="akb-cart-progress-slot"></div>
				<div class="akb-cart-body">
					<div class="akb-cart-items-container"></div>
				</div>
				<div class="akb-cart-live" aria-live="polite"></div>
				<div data-akb-cart-status></div>
				<div class="akb-cart-footer">
					<span class="akb-cart-subtotal-val"></span>
					<div class="akb-cart-discount"><span class="akb-cart-discount-val"></span></div>
					<div class="akb-cart-total"><span class="akb-cart-total-val"></span></div>
					<div class="akb-cart-shipping-notice"></div>
					<a class="akb-cart-checkout-btn" href="https://akibara.cl/finalizar-compra/">Pagar pedido</a>
				</div>
			</div>
		`,
		globals: {
			akbNativeCart: {
				enabled: true,
				ajaxUrl: 'https://akibara.cl/?wc-ajax=%%endpoint%%',
				copy: {},
			},
			fetch: async () => ({
				ok: true,
				json: async () => ({
					success: true,
					data: {
						items: [
							{
								title: 'Frieren 06',
								permalink: 'https://akibara.cl/producto/frieren-06/',
								quantity: 1,
								keys_to_remove: ['k1'],
								max: 5,
								price_html: '$12.990',
							},
						],
						settings: { showRecommendations: true },
						cross_sells: [
							{
								id: 88,
								title: 'Frieren 07',
								permalink: 'https://akibara.cl/producto/frieren-07/',
								price_html: '$12.990',
							},
						],
						cart_count: 1,
						subtotal_html: '$12.990',
						has_discount: false,
						discount_html: '',
						total_html: '$12.990',
						shipping_notice: '',
						nonce: 'n1',
					},
				}),
			}),
		},
	});

	try {
		dom.loadScript(SCRIPT_REL);
		dom.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));

		const main = dom.document.getElementById('primary');
		const trigger = dom.document.querySelector('.akb-cart-trigger');

		assert.equal(main.hasAttribute('inert'), false);

		dom.window.akbCart.open(trigger);
		await new Promise((r) => setTimeout(r, 40));

		assert.equal(main.hasAttribute('inert'), true, 'El contenedor de fondo debe quedar con inert mientras el cajón está abierto.');

		const itemLink = dom.document.querySelector('a.akb-cart-item-title');
		assert.ok(itemLink, 'El título del producto en el carrito debe ser un enlace <a> a la ficha.');
		assert.equal(itemLink.getAttribute('href'), 'https://akibara.cl/producto/frieren-06/');
		assert.equal(itemLink.textContent, 'Frieren 06');

		const csLink = dom.document.querySelector('a.akb-cs-title');
		assert.ok(csLink, 'El título de la recomendación en el carrito debe ser un enlace <a> a la ficha.');
		assert.equal(csLink.getAttribute('href'), 'https://akibara.cl/producto/frieren-07/');
		assert.equal(csLink.textContent, 'Frieren 07');

		dom.window.akbCart.close();
		assert.equal(main.hasAttribute('inert'), false, 'Al cerrar el cajón debe retirarse inert del contenedor de fondo.');
	} finally {
		dom.teardown();
	}
});
