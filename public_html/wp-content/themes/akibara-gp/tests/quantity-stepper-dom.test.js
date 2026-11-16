'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { createDOM } = require('../../../../../tools/testing/dom-harness.js');

const SCRIPT_PATH = 'public_html/wp-content/themes/akibara-gp/assets/js/main.js';

test('DOM Harness PoC: Quantity Stepper boundaries, click delegation, and ARIA announcement', () => {
	const html = `
		<form class="cart">
			<div class="quantity">
				<button type="button" class="akb-qty-btn akb-qty-btn--minus" aria-label="Reducir cantidad">-</button>
				<input type="number" class="input-text qty text" step="1" min="1" max="3" value="1">
				<button type="button" class="akb-qty-btn akb-qty-btn--plus" aria-label="Aumentar cantidad">+</button>
			</div>
			<button type="submit" class="single_add_to_cart_button">Añadir al carrito</button>
		</form>
	`;

	const dom = createDOM({ html });

	try {
		// Cargar el script real del tema en el DOM virtual
		dom.loadScript(SCRIPT_PATH);

		const input = dom.document.querySelector('input.qty');
		const minusBtn = dom.document.querySelector('.akb-qty-btn--minus');
		const plusBtn = dom.document.querySelector('.akb-qty-btn--plus');

		assert.ok(input, 'El input de cantidad debe existir');
		assert.ok(minusBtn, 'El botón minus debe existir');
		assert.ok(plusBtn, 'El botón plus debe existir');

		// 1. Estado inicial en el límite inferior (min=1, val=1)
		assert.equal(input.value, '1');
		assert.equal(minusBtn.disabled, true, 'Minus debe nacer deshabilitado en min');
		assert.equal(minusBtn.getAttribute('aria-disabled'), 'true', 'Minus debe tener aria-disabled="true"');
		assert.equal(plusBtn.disabled, false, 'Plus debe estar habilitado');
		assert.equal(plusBtn.getAttribute('aria-disabled'), 'false');

		// 2. Clic en Plus -> Incrementa a 2
		let inputEventDispatched = false;
		let changeEventDispatched = false;
		input.addEventListener('input', () => { inputEventDispatched = true; });
		input.addEventListener('change', () => { changeEventDispatched = true; });

		dom.click(plusBtn);

		assert.equal(input.value, '2', 'Valor debe subir a 2 tras el primer clic');
		assert.equal(inputEventDispatched, true, 'Debe despachar evento input para WooCommerce');
		assert.equal(changeEventDispatched, true, 'Debe despachar evento change para WooCommerce');
		assert.equal(minusBtn.disabled, false, 'Minus debe habilitarse al salir del mínimo');
		assert.equal(minusBtn.getAttribute('aria-disabled'), 'false');
		assert.equal(plusBtn.disabled, false, 'Plus sigue habilitado');

		// 3. Clic en Plus -> Llega al tope máximo (max=3)
		dom.click(plusBtn);

		assert.equal(input.value, '3', 'Valor debe subir a 3');
		assert.equal(plusBtn.disabled, true, 'Plus debe deshabilitarse al alcanzar max');
		assert.equal(plusBtn.getAttribute('aria-disabled'), 'true');
		assert.equal(minusBtn.disabled, false, 'Minus sigue habilitado');

		// 4. Verificación de Accesibilidad WCAG (región en vivo aria-live)
		const hint = dom.document.querySelector('.akb-qty-hint');
		assert.ok(hint, 'Debe insertarse la región de anuncio accesible al alcanzar el máximo');
		assert.equal(hint.getAttribute('role'), 'status', 'Debe tener role="status"');
		assert.equal(hint.getAttribute('aria-live'), 'polite', 'Debe tener aria-live="polite"');
		assert.equal(hint.textContent, 'Es el máximo disponible: 3 unidades.');

		// 5. Clic adicional en Plus cuando está deshabilitado -> No debe superar max
		dom.click(plusBtn);
		assert.equal(input.value, '3', 'No debe superar el límite max=3');

		// 6. Clic en Minus -> Vuelve a 2 y rehabilita Plus
		dom.click(minusBtn);
		assert.equal(input.value, '2', 'Valor debe reducirse a 2');
		assert.equal(plusBtn.disabled, false, 'Plus debe reactivarse al bajar del tope');
		assert.equal(plusBtn.getAttribute('aria-disabled'), 'false');

		// 7. Modificación manual directa de input con sincronización delegada
		input.value = '1';
		input.dispatchEvent(new dom.window.Event('input', { bubbles: true }));

		assert.equal(minusBtn.disabled, true, 'Minus debe sincronizarse al mínimo vía evento delegado');
		assert.equal(minusBtn.getAttribute('aria-disabled'), 'true');
		assert.equal(plusBtn.disabled, false, 'Plus debe mantenerse activo');
	} finally {
		dom.teardown();
	}
});
