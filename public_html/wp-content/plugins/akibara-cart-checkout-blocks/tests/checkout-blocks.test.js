const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const script = fs.readFileSync(
	path.join(__dirname, '../assets/js/checkout-blocks.js'),
	'utf8'
);

function createStorage(initial, throws) {
	const values = new Map(initial || []);
	const calls = [];

	return {
		values,
		calls,
		api: {
			getItem(key) {
				calls.push(['get', key]);
				if (throws) {
					throw new Error('Storage unavailable');
				}
				return values.get(key) || null;
			},
			removeItem(key) {
				calls.push(['remove', key]);
				if (throws) {
					throw new Error('Storage unavailable');
				}
				values.delete(key);
			},
			setItem(key, value) {
				calls.push(['set', key, value]);
				if (throws) {
					throw new Error('Storage unavailable');
				}
				values.set(key, value);
			}
		}
	};
}

function bootCheckoutScript({
	initialSessionStorage,
	initialLocalStorage,
	paymentImages = [],
	storageThrows = false
} = {}) {
	const session = createStorage(initialSessionStorage, storageThrows);
	const local = createStorage(initialLocalStorage, storageThrows);
	let subscriber = null;
	let frameRequests = 0;
	const dispatchCalls = [];

	const document = {
		readyState: 'complete',
		addEventListener() {},
		getElementById() {
			return null;
		},
		querySelector() {
			return null;
		},
		querySelectorAll(selector) {
			if (selector === '.row-image-select img:not([alt])') {
				return paymentImages;
			}
			return [];
		}
	};
	const wp = {
		data: {
			dispatch() {
				dispatchCalls.push('dispatch');
				return {};
			},
			subscribe(callback) {
				subscriber = callback;
				return function () {};
			}
		}
	};
	const window = {
		localStorage: local.api,
		requestAnimationFrame(callback) {
			frameRequests += 1;
			callback();
		},
		sessionStorage: session.api,
		wp
	};

	vm.runInNewContext(script, { document, Object, window });

	return {
		dispatchCalls,
		frameRequests() {
			return frameRequests;
		},
		local,
		session,
		triggerStoreUpdate() {
			subscriber();
		}
	};
}

test('purges every retired address cache without reading or rewriting it', () => {
	const key = 'akb_checkout_blocks_address';
	const runtime = bootCheckoutScript({
		initialLocalStorage: [[key, '{"address":{"city":"Santiago"}}']],
		initialSessionStorage: [[key, '{"address":{"city":"Providencia"}}']]
	});

	assert.equal(runtime.local.values.has(key), false);
	assert.equal(runtime.session.values.has(key), false);
	assert.deepEqual(runtime.local.calls, [['remove', key]]);
	assert.deepEqual(runtime.session.calls, [['remove', key]]);
	assert.equal(runtime.dispatchCalls.length, 0);
});

test('storage failures never block checkout initialization', () => {
	assert.doesNotThrow(() => bootCheckoutScript({ storageThrows: true }));
});

test('marks duplicated Mercado Pago payment icons as decorative', () => {
	const attributes = new Map();
	const image = {
		setAttribute(name, value) {
			attributes.set(name, value);
		}
	};

	bootCheckoutScript({ paymentImages: [image] });
	assert.equal(attributes.get('alt'), '');
});

test('rechecks payment accessibility after native store updates without mutating cart data', () => {
	const runtime = bootCheckoutScript();

	runtime.triggerStoreUpdate();

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
	assert.match(script, /localStorage/);
	assert.match(script, /sessionStorage/);
	assert.match(script, /removeItem/);
});
