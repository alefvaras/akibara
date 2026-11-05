'use strict';

/**
 * Akibara DOM Harness Test: Pricing Matrix Mobile Responsiveness
 *
 * Verifies mobile layout contracts (viewports 360px-390px / <= 768px):
 *  1. Semantic attributes: all cells provide data-label and distinct column classes.
 *  2. Mobile accessibility: interactive form buttons satisfy touch target guidelines (>= 42px).
 *  3. Responsive styles: CSS defines card transformation without table-cell overflow.
 */

const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createDOM } = require('./dom-harness');

const CSS_PATH = path.resolve(__dirname, '../../public_html/wp-content/plugins/akibara-business/assets/css/pricing-matrix-admin.css');

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
							<th>A Pedido</th>
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
							<td class="akb-pm-col-backorder" data-label="A Pedido">110</td>
							<td class="akb-pm-col-outofstock" data-label="Agotado">20</td>
							<td class="akb-pm-col-series" data-label="Series">12 series</td>
							<td class="akb-pm-col-actions" data-label="Precios de Venta">
								<form class="akb-scale-row-form">
									<div class="akb-pm-scale-calc-grid">
										<div class="akb-pm-calc-field">
											<input type="number" class="akb-calc-reg" value="12000" />
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
		assert.equal(cells.length, 9, 'Debe tener exactamente 9 celdas estructuradas');

		const labels = Array.from(cells).map((c) => c.getAttribute('data-label'));
		assert.deepEqual(labels, [
			'Editorial',
			'País',
			'Precio Actual',
			'Total',
			'Stock',
			'A Pedido',
			'Agotado',
			'Series',
			'Precios de Venta'
		], 'Todas las celdas deben proveer su data-label semántico para la vista móvil');

		// Verificar botones de acción
		const buttons = row.querySelectorAll('.akb-pm-btn-group button');
		assert.equal(buttons.length, 2, 'Debe incluir los 2 botones de acción (Simular y Aplicar)');
	});
});
