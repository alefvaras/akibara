'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
	evaluateNoulSafety,
	evaluateTaskRouting,
	evaluateQaScore
} = require('./jev-gate.js');

test('Jev Gatekeeper: noul allows safe clean diffs', () => {
	const safeDiff = `
diff --git a/assets/css/main.css b/assets/css/main.css
+ .akb-card { color: red; }
	`;
	const gate = evaluateNoulSafety(safeDiff);
	assert.equal(gate.result, true);
	assert.ok(gate.probability >= 0.9);
});

test('Jev Gatekeeper: noul blocks forbidden stock SQL modifications', () => {
	const dangerousDiff = `
+ UPDATE wp_postmeta SET meta_value = 'instock' WHERE post_id = 123;
	`;
	const gate = evaluateNoulSafety(dangerousDiff);
	assert.equal(gate.result, false, 'Debe bloquear inyecciones SQL de stock directo');
	assert.match(gate.reason, /Bloqueo de seguridad Jev/);
});

test('Jev Gatekeeper: noul blocks invalid onbackorder with manage_stock=yes', () => {
	const invalidDiff = `
+ _manage_stock = 'yes';
+ _stock_status = 'onbackorder';
	`;
	const gate = evaluateNoulSafety(invalidDiff);
	assert.equal(gate.result, false, 'Debe bloquear contrato ilegal onbackorder con manage_stock');
});

test('Jev Gatekeeper: choice routes high concurrency and architecture to Astra', () => {
	const routing = evaluateTaskRouting('Revisión de concurrencia y deadlocks en PricingMatrix');
	assert.equal(routing.choice, 'astra_architecture');
});

test('Jev Gatekeeper: choice routes UI client interactions to frontend DOM', () => {
	const routing = evaluateTaskRouting('Ajustar accesibilidad ARIA y clic en el modal del carrito');
	assert.equal(routing.choice, 'frontend_dom');
});

test('Jev Gatekeeper: choice routes mechanical queries to Flash', () => {
	const routing = evaluateTaskRouting('Normalizar sinopsis de tomos de Panini en el catálogo');
	assert.equal(routing.choice, 'flash_mechanic');
});

test('Jev Gatekeeper: score requires rigorous adversarial evidence', () => {
	// Plan incompleto (sin DOM harness ni producción)
	const poor = evaluateQaScore({
		testedLocalMirror: true,
		usedDomHarness: false,
		negativeControlsIncluded: false,
		productionVerified: false
	});
	assert.equal(poor.passed, false);
	assert.equal(poor.score, 35);

	// Plan completo siguiendo la regla del dueño
	const solid = evaluateQaScore({
		testedLocalMirror: true,
		usedDomHarness: true,
		negativeControlsIncluded: true,
		productionVerified: true
	});
	assert.equal(solid.passed, true);
	assert.equal(solid.score, 100);
});
