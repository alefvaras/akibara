const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const { createDOM } = require('../../../../../tools/testing/dom-harness');

const scriptPath = path.join(__dirname, '../assets/js/checkout-blocks.js');
const script = fs.readFileSync(scriptPath, 'utf8');

function instrumentStorage(storage, initialEntries, throws) {
	const calls = [];
	if (!throws && Array.isArray(initialEntries)) {
		for (const [k, v] of initialEntries) {
			storage.setItem(k, v);
		}
	}
	return {
		calls,
		api: {
			getItem(key) {
				calls.push(['get', key]);
				if (throws) {
					throw new Error('Storage unavailable');
				}
				return storage.getItem(key);
			},
			removeItem(key) {
				calls.push(['remove', key]);
				if (throws) {
					throw new Error('Storage unavailable');
				}
				storage.removeItem(key);
			},
			setItem(key, value) {
				calls.push(['set', key, value]);
				if (throws) {
					throw new Error('Storage unavailable');
				}
				storage.setItem(key, value);
			}
		}
	};
}

function bootCheckoutScript({
	html = '<main></main>',
	initialSessionStorage,
	initialLocalStorage,
	storageThrows = false
} = {}) {
	const dom = createDOM({
		html,
		url: 'https://akibara.cl/checkout/'
	});

	const localInstrument = instrumentStorage(dom.window.localStorage, initialLocalStorage, storageThrows);
	const sessionInstrument = instrumentStorage(dom.window.sessionStorage, initialSessionStorage, storageThrows);
	Object.defineProperty(dom.window, 'localStorage', {
		configurable: true,
		value: localInstrument.api
	});
	Object.defineProperty(dom.window, 'sessionStorage', {
		configurable: true,
		value: sessionInstrument.api
	});

	let subscriber = null;
	let frameRequests = 0;
	let observerCreations = 0;
	const dispatchCalls = [];
	const warnings = [];

	dom.window.console.warn = (...args) => {
		warnings.push(args);
	};

	dom.window.MutationObserver = class {
		constructor() {
			observerCreations += 1;
		}
		observe() {}
		disconnect() {}
	};
	dom.window.requestAnimationFrame = (callback) => {
		frameRequests += 1;
		callback();
		return frameRequests;
	};
	dom.window.wp = {
		data: {
			dispatch() {
				dispatchCalls.push('dispatch');
				return {};
			},
			subscribe(callback) {
				subscriber = callback;
				return () => {};
			}
		}
	};

	dom.loadScript(scriptPath);

	return {
		dom,
		dispatchCalls,
		warnings,
		frameRequests() {
			return frameRequests;
		},
		observerCreations() {
			return observerCreations;
		},
		local: {
			calls: localInstrument.calls,
			raw: () => dom.window.localStorage
		},
		session: {
			calls: sessionInstrument.calls,
			raw: () => dom.window.sessionStorage
		},
		triggerStoreUpdate() {
			subscriber();
		}
	};
}

test('purges every retired address cache without reading or rewriting it', (t) => {
	const key = 'akb_checkout_blocks_address';
	const runtime = bootCheckoutScript({
		initialLocalStorage: [[key, '{"address":{"city":"Santiago"}}']],
		initialSessionStorage: [[key, '{"address":{"city":"Providencia"}}']]
	});
	t.after(() => runtime.dom.teardown());

	assert.equal(runtime.dom.window.localStorage.getItem(key), null);
	assert.equal(runtime.dom.window.sessionStorage.getItem(key), null);
	// Reset call logs comparison only for calls made before our explicit verify check.
	assert.deepEqual(runtime.local.calls.slice(0, -1), [['remove', key]]);
	assert.deepEqual(runtime.session.calls.slice(0, -1), [['remove', key]]);
	assert.equal(runtime.dispatchCalls.length, 0);
	assert.equal(runtime.observerCreations(), 0);
});

test('storage failures never block checkout initialization and emit diagnostic warnings', (t) => {
	let runtime;
	assert.doesNotThrow(() => {
		runtime = bootCheckoutScript({ storageThrows: true });
	});
	t.after(() => runtime.dom.teardown());
	assert.equal(runtime.warnings.length, 2);
});

test('marks payment controls, duplicated icons and card iframes accessibly', (t) => {
	const runtime = bootCheckoutScript({
		html: `
			<button id="mp-wallet-button"></button>
			<div class="row-image-select">
				<img id="img-missing-alt" src="mp.png">
				<img id="img-kept-alt" src="visa.png" alt="Visa">
			</div>
			<iframe id="card-frame" name="cardNumber"></iframe>
			<iframe id="exp-frame" name="expirationDate"></iframe>
			<iframe id="cvv-frame" name="securityCode" title="CVV personalizado"></iframe>
		`
	});
	t.after(() => runtime.dom.teardown());

	const { document } = runtime.dom;
	assert.equal(document.getElementById('mp-wallet-button').getAttribute('aria-label'), 'Pagar con Mercado Pago');
	assert.equal(document.getElementById('img-missing-alt').getAttribute('alt'), '');
	assert.equal(document.getElementById('img-kept-alt').getAttribute('alt'), 'Visa');
	assert.equal(document.getElementById('card-frame').title, 'Número de tarjeta');
	assert.equal(document.getElementById('exp-frame').title, 'Fecha de vencimiento');
	assert.equal(document.getElementById('cvv-frame').title, 'CVV personalizado');
});

test('rechecks payment accessibility after native store updates without mutating cart data', (t) => {
	const runtime = bootCheckoutScript({
		html: '<div class="row-image-select"></div>'
	});
	t.after(() => runtime.dom.teardown());

	const lateImg = runtime.dom.document.createElement('img');
	runtime.dom.document.querySelector('.row-image-select').appendChild(lateImg);

	runtime.triggerStoreUpdate();

	assert.equal(lateImg.getAttribute('alt'), '');
	assert.equal(runtime.frameRequests(), 1);
	assert.equal(runtime.dispatchCalls.length, 0);
});

test('source contains no address persistence, cart mutation or checkout-wide DOM observer', () => {
	assert.doesNotMatch(
		script,
		/MutationObserver|billing_phone|shipping_phone|contact_akibara|scrollIntoView|scrollBy/
	);
	assert.doesNotMatch(
		script,
		/setItem|getItem|setShippingAddress|setBillingAddress|updateCustomerData|blocksCheckoutEvents/
	);
});
