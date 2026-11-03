const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const script = fs.readFileSync(
	path.join(__dirname, '../assets/js/shipping-promise.js'),
	'utf8'
);

// DOM mínimo: sólo lo que `apply()` usa. Los selectores que entiende son los
// cuatro que el bundle escribe; cualquier otro lanza, para que un cambio de
// selector en el bundle rompa esta prueba en vez de pasar en falso.
class FakeNode {
	constructor(tag, classes = [], attrs = {}) {
		this.tagName = tag.toUpperCase();
		this.className = classes.join(' ');
		this.attrs = attrs;
		this.children = [];
		this.parentNode = null;
		this.style = {};
		this.textContent = '';
		this.value = attrs.value;
		this.type = attrs.type;
	}
	get classList() {
		const list = this.className.split(/\s+/).filter(Boolean);
		return { contains: (c) => list.includes(c) };
	}
	appendChild(child) {
		child.parentNode = this;
		this.children.push(child);
		return child;
	}
	removeChild(child) {
		this.children = this.children.filter((c) => c !== child);
		child.parentNode = null;
		return child;
	}
	descendants() {
		return this.children.flatMap((c) => [c, ...c.descendants()]);
	}
	matches(selector) {
		if (selector === 'input[type="radio"]') {
			return this.tagName === 'INPUT' && this.type === 'radio';
		}
		const not = selector.match(/^\.([\w-]+):not\(\.([\w-]+)\)$/);
		if (not) {
			return this.classList.contains(not[1]) && !this.classList.contains(not[2]);
		}
		const cls = selector.match(/^\.([\w-]+)$/);
		if (cls) {
			return this.classList.contains(cls[1]);
		}
		throw new Error('selector no soportado por la prueba: ' + selector);
	}
	querySelector(selector) {
		return this.descendants().find((n) => n.matches(selector)) || null;
	}
	querySelectorAll(selector) {
		return this.descendants().filter((n) => n.matches(selector));
	}
	closest(selector) {
		let node = this;
		while (node) {
			if (node.matches(selector)) {
				return node;
			}
			node = node.parentNode;
		}
		return null;
	}
}

function option(rateId) {
	const label = new FakeNode('label', ['wc-block-components-radio-control__option']);
	label.appendChild(new FakeNode('input', [], { type: 'radio', value: rateId }));
	const layout = new FakeNode('div', ['wc-block-components-radio-control__option-layout']);
	label.appendChild(layout);
	return { label, layout };
}

function boot() {
	const document = {
		readyState: 'complete',
		body: null,
		addEventListener() {},
		querySelectorAll() {
			return [];
		},
		createElement(tag) {
			return new FakeNode(tag);
		}
	};
	const window = {
		wp: { data: { subscribe() {}, select() { return null; } } },
		requestAnimationFrame(cb) {
			cb();
		},
		setTimeout(cb) {
			cb();
		}
	};
	vm.runInNewContext(script, { window, document, Object, Number, String });
	return window.akbShippingPromise;
}

function ext(rates, pending = false, version = 1, typical = undefined) {
	const payload = { version, rates, pending_stock: pending };
	if (typical !== undefined) {
		payload.typical = typical;
	}
	return { extensions: { akibara_shipping_promise: payload } };
}

test('reads only its own namespace and version', () => {
	const api = boot();
	assert.equal(api.readExtension(null), null);
	assert.equal(api.readExtension({ extensions: {} }), null);
	assert.equal(api.readExtension(ext({ a: 'x' }, false, 2)), null, 'otra versión se descarta');
	const state = api.readExtension(ext({ 'bluex-ex21': 'Hasta 2 días hábiles' }, true));
	assert.equal(state.rates['bluex-ex21'], 'Hasta 2 días hábiles');
	assert.equal(state.pending, true);
});

test('formats Blue text without inventing dates', () => {
	const api = boot();
	assert.equal(api.format('Hasta 2 días hábiles', false), 'Entrega: hasta 2 días hábiles');
	assert.equal(
		api.format('Hasta 15 días hábiles', true),
		'Entrega: hasta 15 días hábiles, desde que tu pedido esté completo'
	);
	assert.equal(api.format('   ', false), '');
});

test('paints the Blue rates, skips the others, and updates in place', () => {
	const api = boot();
	const root = new FakeNode('div');
	const ex = option('bluex-ex21');
	const pudo = option('bluex-pudo112');
	const pickup = option('local_pickup:70');
	const other = option('flat_rate:99');
	[ex, pudo, pickup, other].forEach((o) => root.appendChild(o.label));

	const state = api.readExtension(
		ext({ 'bluex-ex21': 'Hasta 2 días hábiles', 'bluex-pudo112': 'Hasta 2 días hábiles' })
	);
	assert.equal(api.apply(root, state), 3);
	assert.equal(ex.layout.querySelector('.akb-shipping-promise').textContent, 'Entrega: hasta 2 días hábiles');
	assert.equal(pickup.layout.querySelector('.akb-shipping-promise').textContent, 'Coordinación: te contactaremos en menos de 24 horas.');
	assert.equal(other.label.querySelector('.akb-shipping-promise'), null, 'otros métodos no llevan aviso');

	// Idempotente: una segunda pasada no duplica.
	api.apply(root, state);
	assert.equal(ex.label.querySelectorAll('.akb-shipping-promise').length, 1);

	// Cambia la comuna: el texto se actualiza en el mismo nodo.
	api.apply(root, api.readExtension(ext({ 'bluex-ex21': 'Hasta 3 días hábiles' })));
	assert.equal(ex.layout.querySelector('.akb-shipping-promise').textContent, 'Entrega: hasta 3 días hábiles');
	assert.equal(pudo.label.querySelector('.akb-shipping-promise'), null, 'tarifa que sale del mapa pierde su línea');
});

test('steps aside when Blue already painted its own forecast', () => {
	const api = boot();
	const root = new FakeNode('div');
	const ex = option('bluex-ex21');
	ex.layout.appendChild(new FakeNode('p', ['bluex-delivery-forecast']));
	root.appendChild(ex.label);
	assert.equal(api.apply(root, api.readExtension(ext({ 'bluex-ex21': 'Hasta 2 días hábiles' }))), 0);
	assert.equal(ex.label.querySelector('.akb-shipping-promise'), null);
});

test('clears everything when the extension disappears', () => {
	const api = boot();
	const root = new FakeNode('div');
	const ex = option('bluex-ex21');
	root.appendChild(ex.label);
	api.apply(root, api.readExtension(ext({ 'bluex-ex21': 'Hasta 2 días hábiles' })));
	api.apply(root, null);
	assert.equal(ex.label.querySelector('.akb-shipping-promise'), null);
});

test('adds the usual days only when they are fewer than Blue maximum', () => {
	const api = boot();
	assert.equal(
		api.format('Hasta 2 días hábiles', false, 1),
		'Entrega: normalmente en 1 día · hasta 2 días hábiles'
	);
	assert.equal(
		api.format('Hasta 5 días hábiles', false, 3),
		'Entrega: normalmente en 3 días · hasta 5 días hábiles'
	);
	assert.equal(
		api.format('Hasta 2 días hábiles', true, 1),
		'Entrega: normalmente en 1 día · hasta 2 días hábiles, desde que tu pedido esté completo'
	);
	// Igual o mayor que el máximo de Blue: no se contradice a Blue.
	assert.equal(api.format('Hasta 2 días hábiles', false, 2), 'Entrega: hasta 2 días hábiles');
	assert.equal(api.format('Hasta 1 día hábil', false, 3), 'Entrega: hasta 1 día hábil');
	// Basura: se ignora.
	[0, -1, 1.5, 'x', null, undefined, NaN].forEach((bad) => {
		assert.equal(api.format('Hasta 2 días hábiles', false, bad), 'Entrega: hasta 2 días hábiles', String(bad));
	});
	// Sin número en el texto de Blue no hay máximo con qué comparar.
	assert.equal(api.format('Pronto', false, 1), 'Entrega: pronto');
});

test('paints the usual days per rate and falls back when the field is absent', () => {
	const api = boot();
	const root = new FakeNode('div');
	const ex = option('bluex-ex21');
	const pudo = option('bluex-pudo:112');
	[ex, pudo].forEach((o) => root.appendChild(o.label));

	const withTypical = api.readExtension(
		ext(
			{ 'bluex-ex21': 'Hasta 2 días hábiles', 'bluex-pudo:112': 'Hasta 2 días hábiles' },
			false,
			1,
			{ 'bluex-ex21': 1 }
		)
	);
	assert.deepEqual(withTypical.typical, { 'bluex-ex21': 1 });
	assert.equal(api.apply(root, withTypical), 2);
	assert.equal(
		ex.layout.querySelector('.akb-shipping-promise').textContent,
		'Entrega: normalmente en 1 día · hasta 2 días hábiles'
	);
	assert.equal(pudo.layout.querySelector('.akb-shipping-promise').textContent, 'Entrega: hasta 2 días hábiles');

	// Servidor 2.2.27 (sin `typical`): mismo texto de antes, sin romper.
	const legacy = api.readExtension(ext({ 'bluex-ex21': 'Hasta 2 días hábiles' }));
	assert.equal(Object.keys(legacy.typical).length, 0, 'sin typical el mapa va vacío');
	api.apply(root, legacy);
	assert.equal(ex.layout.querySelector('.akb-shipping-promise').textContent, 'Entrega: hasta 2 días hábiles');
});

test('handles Blue ranges (Desde X Hasta Y) without shouting or misreading the maximum', () => {
	const api = boot();
	assert.equal(api.format('Desde 5 Hasta 7 días hábiles', false), 'Entrega: desde 5 hasta 7 días hábiles');
	assert.equal(
		api.format('Desde 9 Hasta 10 días hábiles', true),
		'Entrega: desde 9 hasta 10 días hábiles, desde que tu pedido esté completo'
	);
	// Con rango no se añade lo habitual, aunque llegue un typical.
	assert.equal(api.format('Desde 1 Hasta 2 días hábiles', false, 1), 'Entrega: desde 1 hasta 2 días hábiles');
});
