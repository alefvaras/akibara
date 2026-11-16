'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { createDOM } = require('../../../../../tools/testing/dom-harness.js');

const SCRIPT_PATH = 'public_html/wp-content/themes/akibara-gp/assets/js/akibara-brands-filter.js';

function createSampleDirectoryWithRegionsHTML() {
	return `
		<div class="akb-brands-directory" role="region" aria-label="Directorio de editoriales" data-akb-total="4">
			<div class="akb-brands-directory__summary" aria-label="Resumen del directorio">
				<span class="akb-brands-directory__summary-label">Editoriales oficiales</span>
				<span class="akb-brands-directory__summary-value">4 editoriales · 2 regiones</span>
			</div>
			<div class="akb-brands-filterbar" role="search" aria-label="Filtrar editoriales">
				<label class="akb-brands-filterbar__search" for="akb-search">
					<span class="akb-brands-filterbar__label">Buscar por nombre</span>
					<span class="akb-brands-filterbar__search-field">
						<input id="akb-search" type="search" class="akb-brands-filterbar__input" placeholder="Ej. Ivrea, Panini…" autocomplete="off" aria-controls="akb-results" aria-describedby="akb-status">
					</span>
				</label>
				<fieldset class="akb-brands-filterbar__filters">
					<legend class="akb-brands-filterbar__label">Filtrar por origen</legend>
					<div class="akb-brands-filterbar__chips">
						<button type="button" class="akb-brands-chip akb-brands-chip--active" aria-pressed="true" data-akb-region="all">Todas</button>
						<button type="button" class="akb-brands-chip" aria-pressed="false" data-akb-region="ar">Argentina</button>
						<button type="button" class="akb-brands-chip" aria-pressed="false" data-akb-region="es">España</button>
					</div>
				</fieldset>
				<div class="akb-brands-filterbar__status" id="akb-status" aria-live="polite" role="status">
					<span class="akb-brands-filterbar__status-text" data-akb-status-text>Busca por editorial o filtra por región.</span>
					<button type="button" class="akb-brands-filterbar__clear" data-akb-clear-filters hidden>Limpiar filtros</button>
				</div>
			</div>
			<div class="akb-brands-directory__sections" id="akb-results">
				<section class="akb-brands-section" data-akb-region="ar">
					<h2 class="akb-brands-section__title">Editoriales Argentinas</h2>
					<ul class="akb-brands-grid">
						<li class="akb-brand-card" data-akb-brand-slug="ivrea-argentina" data-akb-brand-region="ar" data-akb-brand-name="ivrea argentina">
							<a class="akb-brand-card__link" href="/marca/ivrea-argentina/">Ivrea Argentina</a>
						</li>
						<li class="akb-brand-card" data-akb-brand-slug="ovni-press" data-akb-brand-region="ar" data-akb-brand-name="ovni press">
							<a class="akb-brand-card__link" href="/marca/ovni-press/">Ovni Press</a>
						</li>
					</ul>
				</section>
				<section class="akb-brands-section" data-akb-region="es">
					<h2 class="akb-brands-section__title">Editoriales Españolas</h2>
					<ul class="akb-brands-grid">
						<li class="akb-brand-card" data-akb-brand-slug="ivrea-espana" data-akb-brand-region="es" data-akb-brand-name="ivrea españa">
							<a class="akb-brand-card__link" href="/marca/ivrea-espana/">Ivrea España</a>
						</li>
						<li class="akb-brand-card" data-akb-brand-slug="panini-comics" data-akb-brand-region="es" data-akb-brand-name="panini cómics">
							<a class="akb-brand-card__link" href="/marca/panini-comics/">Panini Cómics</a>
						</li>
					</ul>
				</section>
			</div>
			<p class="akb-brands-empty" hidden>
				No encontramos editoriales que coincidan.
				<a href="/contacto/">¿Tienes dudas sobre nuestro catálogo? Escríbenos</a>
			</p>
		</div>
	`;
}

test('DOM Harness: Brands Filter with BrandRegion chips and text search in real DOM', async () => {
	const dom = createDOM({ html: createSampleDirectoryWithRegionsHTML() });

	try {
		dom.loadScript(SCRIPT_PATH);

		const input = dom.document.querySelector('.akb-brands-filterbar__input');
		const chips = Array.from(dom.document.querySelectorAll('.akb-brands-chip'));
		const cards = Array.from(dom.document.querySelectorAll('.akb-brand-card'));
		const sections = Array.from(dom.document.querySelectorAll('.akb-brands-section'));
		const statusText = dom.document.querySelector('[data-akb-status-text]');
		const clearButton = dom.document.querySelector('[data-akb-clear-filters]');
		const emptyMessage = dom.document.querySelector('.akb-brands-empty');

		assert.ok(input, 'El input de búsqueda debe existir');
		assert.equal(chips.length, 3, 'Deben existir 3 chips de región');
		assert.equal(cards.length, 4, 'Deben existir 4 tarjetas iniciales');
		assert.equal(sections.length, 2, 'Deben existir 2 secciones regionales');

		// 1. Estado inicial por defecto
		assert.equal(cards.every((c) => !c.hidden), true, 'Todas las tarjetas son visibles');
		assert.equal(sections.every((s) => !s.hidden), true, 'Todas las secciones son visibles');
		assert.equal(chips[0].getAttribute('aria-pressed'), 'true', 'Chip "Todas" activo');
		assert.equal(chips[0].classList.contains('akb-brands-chip--active'), true);
		assert.equal(clearButton.hidden, true, 'Botón limpiar nace oculto');
		assert.equal(emptyMessage.hidden, true, 'Mensaje vacío nace oculto');
		assert.equal(statusText.textContent, 'Busca por editorial o filtra por región.');

		// 2. Filtrado por chip regional: clic en Argentina ('ar')
		const chipAr = chips.find((c) => c.getAttribute('data-akb-region') === 'ar');
		assert.ok(chipAr, 'Chip "ar" debe existir');
		dom.click(chipAr);

		assert.equal(chipAr.getAttribute('aria-pressed'), 'true', 'Chip "ar" pasa a activo');
		assert.equal(chipAr.classList.contains('akb-brands-chip--active'), true);
		assert.equal(chips[0].getAttribute('aria-pressed'), 'false', 'Chip "Todas" pasa a inactivo');

		// Tarjetas argentinas visibles, españolas ocultas
		assert.equal(cards[0].hidden, false, 'Ivrea Argentina visible');
		assert.equal(cards[1].hidden, false, 'Ovni Press visible');
		assert.equal(cards[2].hidden, true, 'Ivrea España oculta');
		assert.equal(cards[3].hidden, true, 'Panini Cómics oculta');

		// Sección Argentina visible, sección España oculta por completo
		assert.equal(sections[0].hidden, false, 'Sección Argentina visible');
		assert.equal(sections[1].hidden, true, 'Sección España oculta porque ningún hijo matchea');

		assert.equal(statusText.textContent, '2 editoriales');
		assert.equal(clearButton.hidden, false, 'Botón limpiar visible con filtro activo');

		// 3. Búsqueda combinada: dentro de región 'ar', busca 'ivrea'
		dom.fill(input, 'ivrea');
		await new Promise((r) => setTimeout(r, 120));

		assert.equal(cards[0].hidden, false, 'Ivrea Argentina visible');
		assert.equal(cards[1].hidden, true, 'Ovni Press oculta por query');
		assert.equal(cards[2].hidden, true, 'Ivrea España oculta por región');
		assert.equal(cards[3].hidden, true, 'Panini oculta por ambos');
		assert.equal(sections[0].hidden, false);
		assert.equal(sections[1].hidden, true);
		assert.equal(statusText.textContent, '1 editorial');

		// 4. Búsqueda sin coincidencias en la región activa
		dom.fill(input, 'panini');
		await new Promise((r) => setTimeout(r, 120));

		// Panini está en ES, pero la región activa es AR -> 0 coincidencias
		assert.equal(cards.every((c) => c.hidden), true, 'Todas las tarjetas ocultas');
		assert.equal(sections.every((s) => s.hidden), true, 'Ambas secciones ocultas');
		assert.equal(statusText.textContent, '0 editoriales');
		assert.equal(emptyMessage.hidden, false, 'Mensaje vacío visible');

		// 5. Clic en "Limpiar filtros" restablece todo a 'all' y query vacía
		dom.click(clearButton);

		assert.equal(input.value, '', 'Input limpio');
		assert.equal(chips[0].getAttribute('aria-pressed'), 'true', 'Chip "Todas" reactivado');
		assert.equal(cards.every((c) => !c.hidden), true, 'Todas las tarjetas restauradas');
		assert.equal(sections.every((s) => !s.hidden), true, 'Todas las secciones restauradas');
		assert.equal(emptyMessage.hidden, true, 'Mensaje vacío oculto');
		assert.equal(clearButton.hidden, true, 'Botón limpiar oculto');
		assert.equal(statusText.textContent, 'Busca por editorial o filtra por región.');

		// 6. Filtrado por chip España ('es')
		const chipEs = chips.find((c) => c.getAttribute('data-akb-region') === 'es');
		dom.click(chipEs);

		assert.equal(cards[0].hidden, true, 'Ivrea AR oculta');
		assert.equal(cards[1].hidden, true, 'Ovni Press oculta');
		assert.equal(cards[2].hidden, false, 'Ivrea ES visible');
		assert.equal(cards[3].hidden, false, 'Panini ES visible');
		assert.equal(sections[0].hidden, true, 'Sección AR oculta');
		assert.equal(sections[1].hidden, false, 'Sección ES visible');

		// 7. Escape limpia la búsqueda por texto
		dom.fill(input, 'panini');
		await new Promise((r) => setTimeout(r, 120));
		assert.equal(cards[3].hidden, false);
		assert.equal(cards[2].hidden, true);

		input.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

		assert.equal(input.value, '', 'Escape limpia el input');
		assert.equal(cards[2].hidden, false, 'Ivrea ES vuelve a estar visible en sección ES');
		assert.equal(cards[3].hidden, false, 'Panini ES sigue visible en sección ES');
	} finally {
		dom.teardown();
	}
});
