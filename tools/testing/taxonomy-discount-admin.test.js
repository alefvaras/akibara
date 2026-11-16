'use strict';

/**
 * Akibara DOM Harness Test: Taxonomy Discount Admin Interactivity
 *
 * Verifica sobre el DOM headless el comportamiento real de
 * `assets/js/taxonomy-discount-admin.js`:
 *  1. Filtrado por texto y checkbox "Solo con % > 0".
 *  2. Acción rápida "Poner visibles en 0%" (botón contextual).
 *  3. Detección de cambios y activación de la barra pegajosa `#akb-sticky-save-bar`.
 *  4. Validación cruzada de fechas vigencia desde/hasta.
 */

const { describe, it, afterEach } = require('node:test');
const assert = require('node:assert/strict');
const { createDOM } = require('./dom-harness');

const SCRIPT_PATH = 'public_html/wp-content/plugins/akibara-business/assets/js/taxonomy-discount-admin.js';

function buildFixtureHtml() {
	return `
	<form class="akb-panel-form">
		<input type="date" id="akibara_taxonomy_discount_from" name="akibara_taxonomy_discount_from" value="" />
		<input type="date" id="akibara_taxonomy_discount_to" name="akibara_taxonomy_discount_to" value="" />
		<details class="akb-accordion" id="akb-accordion-product_brand" open>
			<summary class="akb-accordion__summary">Editoriales</summary>
			<table class="form-table">
				<tr>
					<td colspan="2">
						<div class="akb-tax-filter-bar" data-accordion="product_brand">
							<input type="search" class="akb-filter-search" />
							<input type="checkbox" class="akb-filter-toggle" />
							<span class="akb-counter akb-filter-counter"></span>
							<button type="button" class="button-link button-link-delete akb-filter-clear-visible" hidden>
								Poner visibles en 0%
							</button>
						</div>
					</td>
				</tr>
				<tr class="akb-taxonomy-discount-row">
					<th><label for="pct_1">Ivrea Argentina</label></th>
					<td><input type="number" id="pct_1" name="akibara_taxonomy_discount_pct_1" value="15" /></td>
				</tr>
				<tr class="akb-taxonomy-discount-row">
					<th><label for="pct_2">Panini España</label></th>
					<td><input type="number" id="pct_2" name="akibara_taxonomy_discount_pct_2" value="10" /></td>
				</tr>
				<tr class="akb-taxonomy-discount-row">
					<th><label for="pct_3">Distrito Manga</label></th>
					<td><input type="number" id="pct_3" name="akibara_taxonomy_discount_pct_3" value="" /></td>
				</tr>
			</table>
		</details>
	</form>`;
}

describe('Taxonomy Discount Admin UI Behavior', () => {
	let dom = null;

	afterEach(() => {
		if (dom) {
			dom.teardown();
			dom = null;
		}
	});

	it('filtra términos, contabiliza activos y limpia solo visibles con el botón contextual', () => {
		dom = createDOM({ html: buildFixtureHtml() });
		dom.loadScript(SCRIPT_PATH);

		const counter = dom.document.querySelector('.akb-filter-counter');
		const clearBtn = dom.document.querySelector('.akb-filter-clear-visible');
		const rows = dom.document.querySelectorAll('tr.akb-taxonomy-discount-row');
		const saveBar = dom.document.getElementById('akb-sticky-save-bar');

		assert.equal(counter.textContent, '3 visibles de 3 (2 con descuento)');
		assert.equal(clearBtn.hidden, false);
		assert.ok(saveBar, 'Debe crear la barra pegajosa de guardado');
		assert.equal(saveBar.classList.contains('is-active'), false);

		// Activar "Solo con % > 0"
		const toggle = dom.document.querySelector('.akb-filter-toggle');
		toggle.checked = true;
		toggle.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

		assert.equal(rows[0].hidden, false);
		assert.equal(rows[1].hidden, false);
		assert.equal(rows[2].hidden, true);
		assert.equal(counter.textContent, '2 visibles de 3 (2 con descuento)');

		// Filtrar solo "Panini" y usar "Poner visibles en 0%" (debe dejar Ivrea intacto en 15%)
		dom.fill('.akb-filter-search', 'panini');
		assert.equal(rows[0].hidden, true);
		assert.equal(rows[1].hidden, false);
		assert.equal(counter.textContent, '1 visibles de 3 (2 con descuento)');

		dom.click(clearBtn);

		assert.equal(dom.document.getElementById('pct_1').value, '15', 'Ivrea oculta no debe borrarse');
		assert.equal(dom.document.getElementById('pct_2').value, '', 'Panini visible debe quedar en vacío/0%');
		assert.equal(counter.textContent, '0 visibles de 3 (1 con descuento)');
		assert.equal(clearBtn.hidden, true, 'Sin visibles con % > 0 el botón debe ocultarse');
		assert.equal(saveBar.classList.contains('is-active'), true, 'La barra pegajosa debe activarse tras el cambio');
	});

	it('valida que la fecha hasta sea igual o posterior a la fecha desde', () => {
		dom = createDOM({ html: buildFixtureHtml() });
		dom.loadScript(SCRIPT_PATH);

		const fromInput = dom.document.getElementById('akibara_taxonomy_discount_from');
		const toInput = dom.document.getElementById('akibara_taxonomy_discount_to');

		dom.fill(fromInput, '2026-11-20');
		dom.fill(toInput, '2026-11-15');
		assert.notEqual(toInput.validationMessage, '');

		dom.fill(toInput, '2026-11-25');
		assert.equal(toInput.validationMessage, '');
	});
});
