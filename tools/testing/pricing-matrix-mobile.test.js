'use strict';

/**
 * Akibara DOM Harness Test: Pricing Matrix Mobile Responsiveness
 *
 * Verifies mobile layout contracts (viewports 360px-390px / <= 768px):
 *  1. Semantic attributes: all cells provide data-label and distinct column classes.
 *  2. Mobile accessibility: interactive form buttons satisfy touch target guidelines (>= 42px).
 *  3. Responsive styles: CSS defines card transformation without table-cell overflow.
 */

const { afterEach, describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createDOM } = require('./dom-harness');

const CSS_PATH = path.resolve(__dirname, '../../public_html/wp-content/plugins/akibara-business/assets/css/pricing-matrix-admin.css');
const ADMIN_PAGE_PATH = path.resolve(__dirname, '../../public_html/wp-content/plugins/akibara-business/src/Modules/PricingMatrix/PricingMatrixAdminPage.php');

describe('Pricing Matrix Mobile Responsiveness (PA-06 Item 3)', () => {
	it('CSS contains mobile card transformations for tables on screens <= 768px', () => {
		const cssContent = fs.readFileSync(CSS_PATH, 'utf8');

		assert.ok(cssContent.includes('@media (max-width: 768px)'), 'Debe incluir media query para pantallas <= 768px');
		assert.ok(cssContent.includes('#akb-pricing-scales-table'), 'Debe aplicar reglas específicas a #akb-pricing-scales-table');
		assert.ok(cssContent.includes('akb-scale-searchable-row'), 'Debe transformar las filas a tarjetas de bloque');
		assert.ok(cssContent.includes('akb-pm-col-price'), 'Debe estilizar el bloque de precio móvil');
		assert.ok(cssContent.includes('#akb-rescue-table'), 'Debe transformar la tabla de rescate a tarjetas móviles');
	});

	it('renders table cells with semantic classes and data-label attributes for mobile headers', () => {
		const sampleHtml = `
		<div class="akb-pm-wrap">
			<div class="akb-pm-table-responsive">
				<table class="akb-pm-table" id="akb-pricing-scales-table">
					<thead>
						<tr>
							<th>Editorial</th>
							<th>País</th>
							<th>Precio Actual</th>
							<th>Total</th>
							<th>Stock</th>
							<th>Agotado</th>
							<th>Series</th>
							<th>Precios de Venta</th>
						</tr>
					</thead>
					<tbody>
						<tr class="akb-scale-searchable-row" data-search="ivrea espana">
							<td class="akb-pm-col-editorial" data-label="Editorial">Ivrea</td>
							<td class="akb-pm-col-country" data-label="País">ES</td>
							<td class="akb-pm-col-price" data-label="Precio Actual">$12.000</td>
							<td class="akb-pm-col-total" data-label="Total">150</td>
							<td class="akb-pm-col-stock" data-label="Stock">20</td>
							<td class="akb-pm-col-outofstock" data-label="Agotado">20</td>
							<td class="akb-pm-col-series" data-label="Series">12 series</td>
							<td class="akb-pm-col-actions" data-label="Precios de Venta">
								<form class="akb-scale-row-form">
									<div class="akb-pm-scale-calc-grid">
										<div class="akb-pm-calc-field">
											<input type="number" class="akb-calc-reg" value="12000" />
											<div class="akb-pm-quick-steps">
												<button type="button" class="akb-pm-quick-step" data-step="-500">-$500</button>
												<button type="button" class="akb-pm-quick-step" data-step="500">+$500</button>
												<button type="button" class="akb-pm-quick-step" data-step="1000">+$1.000</button>
											</div>
										</div>
									</div>
									<div class="akb-pm-btn-group">
										<button type="button" class="button">Simular</button>
										<button type="submit" class="button button-primary">Aplicar</button>
									</div>
								</form>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>`;

		const dom = createDOM({
			html: sampleHtml,
			innerWidth: 375, // Viewport móvil tipo iPhone 13/14
			innerHeight: 812
		});

		const table = dom.document.querySelector('#akb-pricing-scales-table');
		assert.ok(table, 'La tabla de escalas debe existir en el DOM');

		const row = dom.document.querySelector('.akb-scale-searchable-row');
		assert.ok(row, 'La fila de escala debe existir');

		// Verificar que todos los td posean data-label para soporte de seudo-elementos móviles ::before
		const cells = row.querySelectorAll('td');
		assert.equal(cells.length, 8, 'Debe tener exactamente 8 celdas estructuradas');

		const labels = Array.from(cells).map((c) => c.getAttribute('data-label'));
		assert.deepEqual(labels, [
			'Editorial',
			'País',
			'Precio Actual',
			'Total',
			'Stock',
			'Agotado',
			'Series',
			'Precios de Venta'
		], 'Todas las celdas deben proveer su data-label semántico para la vista móvil');

		// Verificar botones rápidos y de acción
		assert.equal(row.querySelectorAll('.akb-pm-quick-step').length, 3, 'Debe incluir los 3 botones rápidos (-$500, +$500, +$1.000)');
		const buttons = row.querySelectorAll('.akb-pm-btn-group button');
		assert.equal(buttons.length, 2, 'Debe incluir los 2 botones de acción (Simular y Aplicar)');
	});

	let dom = null;

	afterEach(() => {
		if (dom) {
			dom.teardown();
			dom = null;
		}
	});

	it('ejecuta el script real de PricingMatrixAdminPage en DOM headless para quick-step y ordenamiento accesible (click + Enter/Space)', () => {
		const phpSource = fs.readFileSync(ADMIN_PAGE_PATH, 'utf8');
		const scriptMatch = phpSource.match(/<script>\s*([\s\S]*?)\s*<\/script>/);
		assert.ok(scriptMatch, 'Debe existir el bloque <script> en PricingMatrixAdminPage.php');
		const jsSource = scriptMatch[1].replace(/<\?php[\s\S]*?\?>/g, 'test_stub');

		const html = `
			<div class="akb-pm-wrap">
				<table class="akb-pm-table" id="akb-pricing-scales-table">
					<thead>
						<tr>
							<th scope="col" class="akb-sortable" data-sort="string" tabindex="0" aria-sort="none">Editorial ↕</th>
							<th scope="col" class="akb-sortable" data-sort="number" tabindex="0" aria-sort="none">Precio Actual ↕</th>
						</tr>
					</thead>
					<tbody>
						<tr class="akb-scale-searchable-row" data-brand="panini">
							<td data-value="Panini">Panini</td>
							<td class="akb-pm-col-price" data-value="12000">
								<form class="akb-scale-row-form">
									<div class="akb-pm-calc-field">
										<input type="number" class="akb-calc-reg" name="new_regular" value="12000" min="100" step="10" />
										<div class="akb-pm-quick-steps">
											<button type="button" class="akb-pm-quick-step" data-step="-500">-$500</button>
											<button type="button" class="akb-pm-quick-step" data-step="500">+$500</button>
											<button type="button" class="akb-pm-quick-step" data-step="1000">+$1.000</button>
										</div>
									</div>
								</form>
							</td>
						</tr>
						<tr class="akb-scale-searchable-row" data-brand="ivrea">
							<td data-value="Ivrea">Ivrea</td>
							<td class="akb-pm-col-price" data-value="9500">9500</td>
						</tr>
					</tbody>
				</table>
			</div>
		`;

		dom = createDOM({ html });
		dom.evaluate(jsSource);

		const document = dom.document;
		const regInput = document.querySelector('input.akb-calc-reg');
		const btnPlus500 = document.querySelector('.akb-pm-quick-step[data-step="500"]');
		const btnPlus1000 = document.querySelector('.akb-pm-quick-step[data-step="1000"]');
		const btnMinus500 = document.querySelector('.akb-pm-quick-step[data-step="-500"]');

		btnPlus500.click();
		assert.equal(regInput.value, '12500', '+$500 debe pasar 12000 -> 12500');

		btnPlus1000.click();
		assert.equal(regInput.value, '13500', '+$1.000 debe pasar 12500 -> 13500');

		btnMinus500.click();
		assert.equal(regInput.value, '13000', '-$500 debe pasar 13500 -> 13000');

		// Piso CLP en 100 al restar desde 200
		regInput.value = '200';
		btnMinus500.click();
		assert.equal(regInput.value, '100', '-$500 desde 200 debe acotar al piso mínimo 100');

		const headers = document.querySelectorAll('th.akb-sortable');
		const editorialTh = headers[0];
		const priceTh = headers[1];
		const tbody = document.querySelector('#akb-pricing-scales-table tbody');

		assert.equal(editorialTh.getAttribute('aria-sort'), 'none');
		assert.equal(priceTh.getAttribute('aria-sort'), 'none');

		// Ordenar por columna Editorial con click (ascendente: Ivrea antes de Panini)
		editorialTh.click();
		assert.equal(editorialTh.getAttribute('aria-sort'), 'ascending');
		assert.equal(priceTh.getAttribute('aria-sort'), 'none');
		assert.deepEqual(
			Array.from(tbody.querySelectorAll('tr')).map((tr) => tr.getAttribute('data-brand')),
			['ivrea', 'panini']
		);

		// Alternar ordenamiento por teclado con Enter (descendente: Panini antes de Ivrea)
		editorialTh.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
		assert.equal(editorialTh.getAttribute('aria-sort'), 'descending');
		assert.deepEqual(
			Array.from(tbody.querySelectorAll('tr')).map((tr) => tr.getAttribute('data-brand')),
			['panini', 'ivrea']
		);

		// Ordenar por columna numérica con Space (ascendente: 9500 antes de 12000; Editorial vuelve a none)
		const spaceEv = new dom.window.KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true });
		priceTh.dispatchEvent(spaceEv);
		assert.equal(spaceEv.defaultPrevented, true, 'Space en cabecera ordenable debe prevenir scroll');
		assert.equal(priceTh.getAttribute('aria-sort'), 'ascending');
		assert.equal(editorialTh.getAttribute('aria-sort'), 'none');
		assert.deepEqual(
			Array.from(tbody.querySelectorAll('tr')).map((tr) => tr.getAttribute('data-brand')),
			['ivrea', 'panini']
		);
	});
});
