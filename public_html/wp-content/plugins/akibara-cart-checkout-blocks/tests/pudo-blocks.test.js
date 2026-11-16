const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const { createDOM } = require('../../../../../tools/testing/dom-harness.js');

const SCRIPT_REL_PATH = 'public_html/wp-content/plugins/akibara-cart-checkout-blocks/assets/js/pudo-blocks.js';
const STYLE_PATH = path.join(__dirname, '../assets/css/pudo-blocks.css');

function parseCssRules(cssText) {
	const clean = cssText.replace(/\/\*[\s\S]*?\*\//g, '');
	const rules = [];
	let index = 0;

	function parseBlock(mediaContext) {
		while (index < clean.length) {
			while (index < clean.length && /\s/.test(clean[index])) {
				index += 1;
			}
			if (index >= clean.length) {
				break;
			}
			if (clean[index] === '}') {
				index += 1;
				return;
			}

			const openBrace = clean.indexOf('{', index);
			if (openBrace === -1) {
				break;
			}
			const prelude = clean.slice(index, openBrace).trim();
			index = openBrace + 1;

			if (prelude.startsWith('@media') || prelude.startsWith('@keyframes')) {
				const nestedContext = prelude.startsWith('@media') ? prelude : mediaContext;
				parseBlock(nestedContext);
				continue;
			}

			let depth = 1;
			const bodyStart = index;
			while (index < clean.length && depth > 0) {
				if (clean[index] === '{') {
					depth += 1;
				} else if (clean[index] === '}') {
					depth -= 1;
				}
				index += 1;
			}
			const body = clean.slice(bodyStart, index - 1);
			const declarations = {};
			for (const chunk of body.split(';')) {
				const colon = chunk.indexOf(':');
				if (colon === -1) {
					continue;
				}
				const prop = chunk.slice(0, colon).trim().toLowerCase();
				const value = chunk.slice(colon + 1).trim();
				if (prop) {
					declarations[prop] = value;
				}
			}
			rules.push({
				selectors: prelude.split(',').map((s) => s.trim().replace(/\s+/g, ' ')),
				media: mediaContext || '',
				declarations
			});
		}
	}

	parseBlock('');
	return rules;
}

function createHooksDomRuntime(options = {}) {
	const timers = [];
	let nextTimerId = 1;
	let now = 0;

	const validation = {
		cleared: [],
		errors: [],
		clearValidationError(key) {
			this.cleared.push(key);
		},
		setValidationErrors(errors) {
			this.errors.push(errors);
		}
	};

	const cartUpdates = [];
	const warnings = [];
	const extensionHandler = options.extensionCartUpdate || (() => Promise.resolve());
	let registeredPlugin = null;

	function scheduleTimer(fn, delay) {
		const id = nextTimerId;
		nextTimerId += 1;
		timers.push({ id, at: now + Math.max(0, Number(delay) || 0), fn, cancelled: false });
		return id;
	}

	function cancelTimer(id) {
		for (const timer of timers) {
			if (timer.id === id) {
				timer.cancelled = true;
			}
		}
	}

	const Fragment = Symbol('Fragment');
	const states = [];
	const refs = [];
	const effects = [];
	let stateCursor = 0;
	let refCursor = 0;
	let effectCursor = 0;
	let pendingEffects = [];
	let renderQueued = false;
	let mountNode = null;
	let currentPropsFactory = null;
	let currentVNode = null;

	function normalizeChildren(raw) {
		const list = [];
		function visit(item) {
			if (item === null || item === undefined || item === false || item === true) {
				return;
			}
			if (Array.isArray(item)) {
				item.forEach(visit);
				return;
			}
			list.push(typeof item === 'object' ? item : { type: '#text', text: String(item) });
		}
		visit(raw);
		return list;
	}

	function applyProps(el, prevProps, nextProps, listenersMap) {
		const SVG_NS = 'http://www.w3.org/2000/svg';
		const isSvg = el.namespaceURI === SVG_NS;
		const oldKeys = Object.keys(prevProps || {});
		const newKeys = Object.keys(nextProps || {});

		for (const key of oldKeys) {
			if (key === 'children' || key === 'key' || key === 'ref') {
				continue;
			}
			if (!Object.prototype.hasOwnProperty.call(nextProps, key) || nextProps[key] === undefined) {
				if (key.startsWith('on') && typeof prevProps[key] === 'function') {
					const evtName = key.slice(2).toLowerCase();
					const oldFn = listenersMap.get(el)?.get(evtName);
					if (oldFn) {
						el.removeEventListener(evtName, oldFn);
						listenersMap.get(el).delete(evtName);
					}
				} else if (key === 'disabled') {
					el.disabled = false;
					el.removeAttribute('disabled');
				} else if (key === 'className') {
					el.removeAttribute('class');
				} else {
					el.removeAttribute(key.toLowerCase());
				}
			}
		}

		for (const key of newKeys) {
			if (key === 'children' || key === 'key' || key === 'ref') {
				continue;
			}
			const value = nextProps[key];
			if (value === undefined || value === null) {
				continue;
			}
			if (key.startsWith('on') && typeof value === 'function') {
				const evtName = key.slice(2).toLowerCase();
				if (!listenersMap.has(el)) {
					listenersMap.set(el, new Map());
				}
				const elListeners = listenersMap.get(el);
				const prevFn = elListeners.get(evtName);
				if (prevFn !== value) {
					if (prevFn) {
						el.removeEventListener(evtName, prevFn);
					}
					el.addEventListener(evtName, value);
					elListeners.set(evtName, value);
				}
			} else if (key === 'className') {
				if (isSvg) {
					el.setAttribute('class', String(value));
				} else {
					el.className = String(value);
				}
			} else if (key === 'tabIndex') {
				el.tabIndex = Number(value);
				el.setAttribute('tabindex', String(value));
			} else if (key === 'disabled') {
				el.disabled = Boolean(value);
				if (value) {
					el.setAttribute('disabled', '');
				} else {
					el.removeAttribute('disabled');
				}
			} else {
				el.setAttribute(key.toLowerCase(), String(value));
			}
		}
	}

	const listenersMap = new WeakMap();

	function setRef(ref, value) {
		if (!ref) {
			return;
		}
		if (typeof ref === 'function') {
			ref(value);
		} else if (typeof ref === 'object') {
			ref.current = value;
		}
	}

	function clearRefIfOwned(ref, domEl) {
		if (ref && typeof ref === 'object' && ref.current === domEl) {
			ref.current = null;
		}
	}

	function expandVNode(vnode) {
		if (!vnode || vnode.type === '#text') {
			return vnode ? [vnode] : [];
		}
		if (vnode.type === Fragment) {
			const out = [];
			for (const child of vnode.children || []) {
				out.push(...expandVNode(child));
			}
			return out;
		}
		if (typeof vnode.type === 'function') {
			const propsWithChildren = {
				...(vnode.props || {}),
				children: (vnode.children || []).length === 1 ? vnode.children[0] : (vnode.children || [])
			};
			return expandVNode(vnode.type(propsWithChildren));
		}
		return [vnode];
	}

	function createDomNode(doc, vnode) {
		if (vnode.type === '#text') {
			const tn = doc.createTextNode(vnode.text);
			vnode._dom = tn;
			return tn;
		}
		const SVG_TAGS = new Set(['svg', 'path', 'g', 'circle', 'rect']);
		const el = SVG_TAGS.has(vnode.type)
			? doc.createElementNS('http://www.w3.org/2000/svg', vnode.type)
			: doc.createElement(vnode.type);
		applyProps(el, {}, vnode.props || {}, listenersMap);
		setRef(vnode.props && vnode.props.ref, el);
		vnode._dom = el;
		const children = [];
		for (const rawChild of vnode.children || []) {
			children.push(...expandVNode(rawChild));
		}
		vnode._expandedChildren = children;
		for (const child of children) {
			el.appendChild(createDomNode(doc, child));
		}
		return el;
	}

	function reconcileChildren(doc, parentEl, oldList, newList) {
		const maxLen = Math.max(oldList.length, newList.length);
		for (let i = 0; i < maxLen; i += 1) {
			const oldChild = oldList[i];
			const newChild = newList[i];
			if (!newChild) {
				if (oldChild && oldChild._dom && oldChild._dom.parentNode === parentEl) {
					if (oldChild.props && oldChild.props.ref) {
						clearRefIfOwned(oldChild.props.ref, oldChild._dom);
					}
					parentEl.removeChild(oldChild._dom);
				}
				continue;
			}
			if (!oldChild || oldChild.type !== newChild.type || (oldChild.props?.key ?? null) !== (newChild.props?.key ?? null)) {
				if (oldChild && oldChild.props && oldChild.props.ref) {
					clearRefIfOwned(oldChild.props.ref, oldChild._dom);
				}
				const created = createDomNode(doc, newChild);
				if (oldChild && oldChild._dom && oldChild._dom.parentNode === parentEl) {
					parentEl.replaceChild(created, oldChild._dom);
				} else {
					parentEl.appendChild(created);
				}
				continue;
			}
			if (newChild.type === '#text') {
				oldChild._dom.textContent = newChild.text;
				newChild._dom = oldChild._dom;
				continue;
			}
			const domEl = oldChild._dom;
			newChild._dom = domEl;
			applyProps(domEl, oldChild.props || {}, newChild.props || {}, listenersMap);
			setRef(newChild.props && newChild.props.ref, domEl);
			const nextChildren = [];
			for (const rawChild of newChild.children || []) {
				nextChildren.push(...expandVNode(rawChild));
			}
			newChild._expandedChildren = nextChildren;
			reconcileChildren(doc, domEl, oldChild._expandedChildren || [], nextChildren);
		}
	}

	function runEffects() {
		const queue = pendingEffects.slice();
		pendingEffects = [];
		for (const idx of queue) {
			const slot = effects[idx];
			if (!slot) {
				continue;
			}
			if (typeof slot.cleanup === 'function') {
				slot.cleanup();
				slot.cleanup = undefined;
			}
			slot.cleanup = slot.cb() || undefined;
		}
	}

	function renderNow() {
		if (!mountNode || !registeredPlugin) {
			return;
		}
		renderQueued = false;
		stateCursor = 0;
		refCursor = 0;
		effectCursor = 0;

		const doc = dom.window.document;
		function ExperimentalOrderShippingPackagesComponent(props) {
			const children = normalizeChildren(props.children);
			return {
				type: 'div',
				props: { 'data-akb-slot': 'shipping-packages' },
				children
			};
		}

		const renderedRoot = registeredPlugin.settings.render();
		// Expand outer plugin wrapper to find PudoShippingFill element and inject live props
		let rootVNode = renderedRoot;
		if (rootVNode && typeof rootVNode.type === 'function' && currentPropsFactory) {
			const children = normalizeChildren(rootVNode.children || rootVNode.props.children).map((child) => {
				if (child && typeof child.type === 'function') {
					return {
						...child,
						props: { ...(child.props || {}), ...currentPropsFactory() }
					};
				}
				return child;
			});
			rootVNode = {
				type: ExperimentalOrderShippingPackagesComponent,
				props: rootVNode.props,
				children
			};
		}

		const nextExpanded = expandVNode(rootVNode);
		reconcileChildren(doc, mountNode, currentVNode || [], nextExpanded);
		currentVNode = nextExpanded;
		runEffects();
	}

	function scheduleRender() {
		if (renderQueued) {
			return;
		}
		renderQueued = true;
		queueMicrotask(() => {
			if (renderQueued) {
				renderNow();
			}
		});
	}

	function useState(initial) {
		const idx = stateCursor;
		stateCursor += 1;
		if (idx >= states.length) {
			states.push(typeof initial === 'function' ? initial() : initial);
		}
		const setState = (updater) => {
			const next = typeof updater === 'function' ? updater(states[idx]) : updater;
			if (!Object.is(next, states[idx])) {
				states[idx] = next;
				scheduleRender();
			}
		};
		return [states[idx], setState];
	}

	function useRef(initial) {
		const idx = refCursor;
		refCursor += 1;
		if (idx >= refs.length) {
			refs.push({ current: initial });
		}
		return refs[idx];
	}

	function useEffect(cb, deps) {
		const idx = effectCursor;
		effectCursor += 1;
		const prev = effects[idx];
		const changed = !prev || deps === undefined || !prev.deps || deps.length !== prev.deps.length
			|| deps.some((dep, i) => !Object.is(dep, prev.deps[i]));
		if (!prev) {
			effects[idx] = { deps, cb, cleanup: undefined };
			pendingEffects.push(idx);
		} else {
			prev.deps = deps;
			prev.cb = cb;
			if (changed) {
				pendingEffects.push(idx);
			}
		}
	}

	function ExperimentalOrderShippingPackages(props) {
		return {
			type: 'div',
			props: { 'data-akb-slot': 'shipping-packages' },
			children: normalizeChildren(props && props.children)
		};
	}

	const wp = {
		data: {
			dispatch(storeName) {
				assert.equal(storeName, 'wc/store/validation');
				return validation;
			}
		},
		element: {
			Fragment,
			createElement(type, props, ...children) {
				return {
					type,
					props: props || {},
					children: normalizeChildren(children)
				};
			},
			useEffect,
			useRef,
			useState
		},
		plugins: {
			registerPlugin(name, settings) {
				registeredPlugin = { name, settings };
			}
		}
	};

	const dom = createDOM({
		html: options.html || '<header id="site-header"></header><main id="checkout-main"><div id="pudo-mount"></div></main><footer id="site-footer"></footer>',
		url: 'https://akibara.cl/checkout/',
		globals: {
			akbPudoBlocksTestMode: options.testMode !== false,
			wp,
			wc: {
				blocksCheckout: {
					ExperimentalOrderShippingPackages,
					extensionCartUpdate(payload) {
						cartUpdates.push(payload);
						return extensionHandler(payload);
					}
				}
			}
		}
	});

	dom.window.setTimeout = scheduleTimer;
	dom.window.clearTimeout = cancelTimer;
	dom.window.console.warn = (...args) => {
		warnings.push(args);
	};
	if (!dom.window.crypto || typeof dom.window.crypto.getRandomValues !== 'function') {
		dom.window.crypto = {};
	}
	dom.window.crypto.getRandomValues = function (bytes) {
		for (let i = 0; i < bytes.length; i += 1) {
			bytes[i] = i;
		}
		return bytes;
	};

	dom.loadScript(SCRIPT_REL_PATH);

	async function flushMicrotasks() {
		await Promise.resolve();
		await new Promise((resolve) => setTimeout(resolve, 0));
		if (renderQueued) {
			renderNow();
		}
	}

	async function advanceTimersBy(ms) {
		const target = now + ms;
		while (true) {
			let earliest = null;
			for (const timer of timers) {
				if (!timer.cancelled && timer.at <= target && (!earliest || timer.at < earliest.at)) {
					earliest = timer;
				}
			}
			if (!earliest) {
				now = target;
				break;
			}
			earliest.cancelled = true;
			now = earliest.at;
			earliest.fn();
			await flushMicrotasks();
		}
	}

	return {
		dom,
		api: dom.window.akbPudoBlocksTestApi,
		validation,
		cartUpdates,
		warnings,
		getPlugin() {
			return registeredPlugin;
		},
		async mount(propsFactory) {
			mountNode = dom.window.document.getElementById('pudo-mount');
			currentPropsFactory = typeof propsFactory === 'function' ? propsFactory : () => propsFactory;
			renderNow();
			await advanceTimersBy(0);
		},
		async update(propsFactory) {
			currentPropsFactory = typeof propsFactory === 'function' ? propsFactory : () => propsFactory;
			renderNow();
			await advanceTimersBy(0);
		},
		async unmount() {
			for (const slot of effects) {
				if (slot && typeof slot.cleanup === 'function') {
					slot.cleanup();
				}
			}
			await flushMicrotasks();
		},
		advanceTimersBy,
		flushMicrotasks
	};
}

function bootUtilities(options = {}) {
	return createHooksDomRuntime(options);
}

function validPayload() {
	return {
		type: 'pudo:select',
		payload: {
			agency_id: 'AG-27136',
			agency_name: 'Nombre visible no confiable',
			location: {
				street_name: 'Av. Providencia',
				street_number: '123',
				city_name: 'Providencia',
				state_name: 'Metropolitana de Santiago',
				region_code: 'CL-RM',
				country_name: 'Chile'
			}
		}
	};
}

function validEvent(api) {
	const source = {};
	const iframe = {
		contentWindow: source,
		isConnected: true
	};

	return {
		event: {
			data: validPayload(),
			origin: api.WIDGET_ORIGIN,
			source
		},
		iframe
	};
}

test('accepts only an exact-origin connected iframe message and returns minimal data', () => {
	const { api } = bootUtilities();
	const { event, iframe } = validEvent(api);

	const result = api.parseWidgetMessage(event, iframe);

	assert.deepEqual(
		JSON.parse(JSON.stringify(result)),
		{
			agency_id: 'AG-27136',
			address: 'Av. Providencia 123 Providencia',
			region_code: 'CL-RM'
		}
	);
	assert.equal(Object.prototype.hasOwnProperty.call(result, 'agency_name'), false);
	assert.equal(Object.prototype.hasOwnProperty.call(result, 'shipping_address'), false);
});

test('rejects forged origin, forged source and detached iframe messages', () => {
	const { api } = bootUtilities();
	const { event, iframe } = validEvent(api);

	assert.equal(
		api.parseWidgetMessage({ ...event, origin: 'https://widget-pudo.qa.blue.cl' }, iframe),
		null
	);
	assert.equal(
		api.parseWidgetMessage({ ...event, source: {} }, iframe),
		null
	);
	assert.equal(
		api.parseWidgetMessage(event, { ...iframe, isConnected: false }),
		null
	);
});

test('rejects oversized or expanded envelopes but accepts and projects the official widget payload', () => {
	const { api } = bootUtilities();
	const { event, iframe } = validEvent(api);
	const oversized = validPayload();
	oversized.payload.agency_name = 'x'.repeat(api.MAX_MESSAGE_BYTES);
	const official = validPayload();
	official.payload = {
		...official.payload,
		status: 'active',
		package_reception: true,
		pickup_availability: true,
		maximum_package_dimensions: {
			height: 100,
			length: 100,
			weight: 16,
			width: 100
		},
		point_type: { description: 'Punto Blue Express' },
		is_24_hours: false,
		client_services: [],
		additional_services: [],
		location: {
			...official.payload.location,
			geolocation: { latitude: -33.4372, longitude: -70.6506 },
			state_id: 13,
			city_id: 13101,
			other_info: 'Referencia no confiable'
		},
		shipping_address: {
			address_1: 'Dirección que nunca debe llegar al servidor'
		}
	};
	const expandedEnvelope = {
		...validPayload(),
		shipping_address: { address_1: 'No permitido en el sobre' }
	};

	assert.equal(api.parseWidgetMessage({ ...event, data: oversized }, iframe), null);
	assert.deepEqual(
		JSON.parse(JSON.stringify(api.parseWidgetMessage({ ...event, data: official }, iframe))),
		{
			agency_id: 'AG-27136',
			address: 'Av. Providencia 123 Providencia',
			region_code: 'CL-RM'
		},
		'Only the minimal allowlisted projection should leave the browser.'
	);
	assert.equal(api.parseWidgetMessage({ ...event, data: expandedEnvelope }, iframe), null);
});

test('rejects invalid agency IDs, foreign countries and unknown regions', () => {
	const { api } = bootUtilities();
	const { event, iframe } = validEvent(api);
	const invalidId = validPayload();
	invalidId.payload.agency_id = '<script>';
	const foreign = validPayload();
	foreign.payload.location.country_name = 'Argentina';
	const unknownRegion = validPayload();
	unknownRegion.payload.location.region_code = '';
	unknownRegion.payload.location.state_name = 'Región inventada';

	assert.equal(api.parseWidgetMessage({ ...event, data: invalidId }, iframe), null);
	assert.equal(api.parseWidgetMessage({ ...event, data: foreign }, iframe), null);
	assert.equal(api.parseWidgetMessage({ ...event, data: unknownRegion }, iframe), null);
});

test('logs diagnostic warning and returns null when widget message payload is non-serializable', () => {
	const { api, warnings } = bootUtilities();
	const { event, iframe } = validEvent(api);
	const circular = { payload: {} };
	circular.payload.self = circular;

	assert.equal(api.parseWidgetMessage({ ...event, data: circular }, iframe), null);
	assert.equal(warnings.length, 1);
	assert.match(String(warnings[0][0]), /no serializable/);
});

test('allows one widget selection per modal replay key', () => {
	const { api } = bootUtilities();
	const replayRef = { current: '' };
	const request = {
		agency_id: 'AG-27136',
		address: 'Av. Providencia 123 Providencia',
		region_code: 'CL-RM'
	};

	assert.equal(api.markMessageProcessed(replayRef, request), true);
	assert.equal(api.markMessageProcessed(replayRef, request), false);

	replayRef.current = '';
	assert.equal(api.markMessageProcessed(replayRef, request), true);
});

test('creates a fresh exact-shape attempt correlation ID from browser crypto', () => {
	const { api } = bootUtilities();

	assert.equal(api.createAttemptId(), '000102030405060708090a0b0c0d0e0f');
	assert.match(api.createAttemptId(), /^[a-f0-9]{32}$/);
});

test('builds only the production widget URL with an encoded agency ID', () => {
	const { api } = bootUtilities();

	assert.equal(api.buildWidgetUrl(''), 'https://widget-pudo.blue.cl');
	assert.equal(api.buildWidgetUrl('AG:27.136'), 'https://widget-pudo.blue.cl?id=AG%3A27.136');
	assert.equal(api.buildWidgetUrl('AG:27/136'), 'https://widget-pudo.blue.cl');
	assert.doesNotMatch(api.buildWidgetUrl('AG-27136'), /qa|dev/);
});

test('iterates every Store API package and detects selected native PUDO rates', () => {
	const { api } = bootUtilities();
	const packages = [
		{
			package_id: 0,
			shipping_rates: [
				{ method_id: 'bluex-pudo', rate_id: 'bluex-pudo:3', selected: true }
			]
		},
		{
			package_id: 1,
			shipping_rates: [
				{ method_id: 'bluex-ex', rate_id: 'bluex-ex:4', selected: true }
			]
		}
	];

	assert.equal(api.shippingPackages({ cart: { shippingRates: packages } }).length, 2);
	assert.equal(api.selectedPudoPackages(packages).length, 1);
});

test('writes and clears a visible Validation Store error', () => {
	const { api, validation } = bootUtilities();

	api.setValidationError('Selecciona un punto.');
	api.clearValidationError();

	assert.deepEqual(
		JSON.parse(JSON.stringify(validation.errors)),
		[
			{
				'akibara-pudo-selection': {
					message: 'Selecciona un punto.',
					hidden: false
				}
			}
		]
	);
	assert.deepEqual(validation.cleared, ['akibara-pudo-selection']);
});

test('keeps timed out update A authoritative while allowing a safe visual exit', async () => {
	const runtime = bootUtilities();
	const doc = runtime.dom.window.document;
	const checkout = doc.getElementById('checkout-main');
	const modal = doc.createElement('div');
	modal.className = 'akb-pudo-modal';
	doc.body.appendChild(modal);

	const { api } = runtime;
	const queue = api.createSerializedQueue();
	const order = [];
	let resolveA;
	let timeoutAnnounced = false;
	const restoreCheckout = api.isolateDocumentBehindModal(modal);

	const updateA = queue(() => {
		order.push('A:start');
		return new Promise((resolve) => {
			resolveA = resolve;
		});
	});
	const monitoredA = api.monitorTimeout(updateA, 5, () => {
		timeoutAnnounced = true;
	});
	const updateB = queue(() => {
		order.push('B:start');
		return Promise.resolve('B:done');
	});

	await runtime.advanceTimersBy(10);
	assert.equal(timeoutAnnounced, true);
	assert.deepEqual(order, ['A:start'], 'B must remain queued after the UX timeout.');
	assert.equal(
		api.canDismissOperation(true, timeoutAnnounced),
		true,
		'The shopper must be able to close the timed-out modal without settling A.'
	);
	restoreCheckout();
	assert.equal(checkout.hasAttribute('inert'), false, 'Checkout must become interactive after safe dismissal.');
	assert.equal(
		api.canDismissOperation(true, false),
		false,
		'An in-flight request must remain non-dismissible before its UX timeout.'
	);

	resolveA('A:done');
	assert.equal(await monitoredA, 'A:done');
	assert.equal(await updateB, 'B:done');
	assert.deepEqual(order, ['A:start', 'B:start']);
});

test('serializes clear then select and recovers the queue after a failed operation', async () => {
	const { api } = bootUtilities();
	const queue = api.createSerializedQueue();
	const order = [];
	let releaseClear;

	const clear = queue(() => {
		order.push('clear:start');
		return new Promise((resolve) => {
			releaseClear = resolve;
		});
	});
	const select = queue(() => {
		order.push('select:start');
		return Promise.resolve('select:done');
	});

	await new Promise((resolve) => setImmediate(resolve));
	assert.deepEqual(order, ['clear:start']);
	releaseClear('clear:done');
	assert.equal(await clear, 'clear:done');
	assert.equal(await select, 'select:done');
	assert.deepEqual(order, ['clear:start', 'select:start']);

	await assert.rejects(
		queue(() => Promise.reject(new Error('provider failure'))),
		/provider failure/
	);
	assert.equal(await queue(() => Promise.resolve('recovered')), 'recovered');
});

test('makes every background branch inert and restores its exact prior state', () => {
	const runtime = bootUtilities({
		html: '<header id="h" aria-hidden="false"></header><div id="checkout-root"><div id="package-card"></div><div id="modal"></div></div><footer id="f"></footer>'
	});
	const doc = runtime.dom.window.document;
	const header = doc.getElementById('h');
	const checkoutRoot = doc.getElementById('checkout-root');
	const footer = doc.getElementById('f');
	const packageCard = doc.getElementById('package-card');
	const modal = doc.getElementById('modal');

	const restore = runtime.api.isolateDocumentBehindModal(modal);

	for (const background of [header, footer, packageCard]) {
		assert.equal(background.hasAttribute('inert'), true);
		assert.equal(background.inert, true);
		assert.equal(background.getAttribute('aria-hidden'), 'true');
	}
	assert.equal(modal.hasAttribute('inert'), false);
	assert.equal(checkoutRoot.hasAttribute('inert'), false);

	restore();

	assert.equal(header.getAttribute('aria-hidden'), 'false');
	assert.equal(header.hasAttribute('inert'), false);
	assert.equal(footer.hasAttribute('aria-hidden'), false);
	assert.equal(packageCard.hasAttribute('aria-hidden'), false);
	assert.equal(header.inert, false);
	assert.equal(footer.inert, false);
	assert.equal(packageCard.inert, false);
});

test('registers one native ExperimentalOrderShippingPackages fill', () => {
	const runtime = bootUtilities();
	const plugin = runtime.getPlugin();

	assert.equal(plugin.name, 'akibara-blocks-pudo');
	assert.equal(plugin.settings.scope, 'woocommerce-checkout');
	assert.equal(
		plugin.settings.render().type,
		runtime.dom.window.wc.blocksCheckout.ExperimentalOrderShippingPackages
	);
});

test('mounts real DOM controls, enforces accessible modal focus/timeout/retry and guards saving', async () => {
	let resolveUpdate;
	const runtime = createHooksDomRuntime({
		extensionCartUpdate() {
			return new Promise((resolve) => {
				resolveUpdate = resolve;
			});
		}
	});
	const doc = runtime.dom.window.document;
	const selectedPackages = [
		{
			package_id: 0,
			destination: { address_1: 'Av. Providencia 123', city: 'Curicó', state: 'CL-ML', country: 'CL' },
			shipping_rates: [{ method_id: 'bluex-pudo', rate_id: 'bluex-pudo:3', selected: true }]
		}
	];

	await runtime.mount(() => ({
		cart: { shippingRates: selectedPackages },
		extensions: {}
	}));

	const reminder = doc.querySelector('.akb-pudo-package__validation');
	assert.ok(reminder, 'Unselected reminder renders in DOM');
	assert.equal(reminder.getAttribute('aria-live'), 'polite');
	assert.equal(reminder.getAttribute('role'), null, 'Initial reminder is polite status, never assertive alert');
	assert.equal(doc.querySelector('.akb-pudo-dialog__notice'), null, 'Dead notice channel must not exist in DOM');

	const selectBtn = doc.querySelector('.akb-pudo-button');
	assert.ok(selectBtn);
	assert.equal(selectBtn.getAttribute('type'), 'button');
	assert.equal(selectBtn.getAttribute('aria-invalid'), 'true');
	assert.equal(selectBtn.getAttribute('aria-errormessage'), reminder.id);
	assert.notEqual(doc.activeElement, selectBtn, 'Initial unselected state must not steal focus to selection button');

	// Open modal by real click on the button
	selectBtn.click();
	await runtime.flushMicrotasks();
	await runtime.advanceTimersBy(0);

	const modal = doc.querySelector('.akb-pudo-modal');
	const dialog = doc.querySelector('[role="dialog"]');
	const iframe = doc.querySelector('.akb-pudo-dialog__iframe');
	const loading = doc.querySelector('.akb-pudo-dialog__loading');
	const scopeLine = doc.querySelector('.akb-pudo-dialog__scope');
	assert.ok(modal && dialog && iframe && loading && scopeLine);
	assert.equal(dialog.getAttribute('aria-modal'), 'true');
	assert.equal(dialog.getAttribute('aria-describedby'), 'akb-pudo-dialog-description');
	assert.equal(dialog.getAttribute('tabindex'), '-1');
	assert.equal(doc.body.classList.contains('akb-pudo-modal-open'), true);
	assert.equal(doc.activeElement, dialog, 'Opening modal focuses dialog container, not close button');
	assert.equal(iframe.getAttribute('title'), 'Mapa para seleccionar un Punto Blue Express');
	assert.equal(iframe.getAttribute('tabindex'), '-1');
	assert.equal(iframe.getAttribute('aria-hidden'), 'true');
	assert.equal(loading.getAttribute('role'), 'status');
	assert.equal(loading.querySelector('[role="progressbar"]')?.getAttribute('aria-valuetext'), 'Cargando mapa de puntos de retiro');
	assert.equal(doc.getElementById('site-header').getAttribute('inert'), '');

	// Focus trap pulls focus back into dialog if outside element receives focusin
	doc.getElementById('site-footer').dispatchEvent(new runtime.dom.window.Event('focusin', { bubbles: true }));
	assert.equal(doc.activeElement, dialog);

	// Frame timeout reveals error alert + Reintentar CTA, and clicking Reintentar resets frame loading
	await runtime.advanceTimersBy(12000);
	const errorBox = doc.querySelector('.akb-pudo-dialog__error');
	assert.ok(errorBox);
	assert.equal(errorBox.getAttribute('role'), 'alert');
	const retryBtn = errorBox.querySelector('button');
	assert.equal(retryBtn?.textContent, 'Reintentar');
	assert.equal(doc.activeElement, retryBtn);

	retryBtn.click();
	await runtime.flushMicrotasks();
	await runtime.advanceTimersBy(0);
	assert.equal(doc.querySelector('.akb-pudo-dialog__error'), null);
	assert.ok(doc.querySelector('.akb-pudo-dialog__loading'));

	// Mark iframe loaded -> interactive iframe
	doc.querySelector('.akb-pudo-dialog__iframe').dispatchEvent(new runtime.dom.window.Event('load'));
	await runtime.flushMicrotasks();
	await runtime.advanceTimersBy(0);
	const liveIframe = doc.querySelector('.akb-pudo-dialog__iframe');
	assert.equal(doc.querySelector('.akb-pudo-dialog__loading'), null);
	assert.equal(liveIframe.getAttribute('tabindex'), '0');
	assert.equal(liveIframe.hasAttribute('aria-hidden'), false);

	// Simulate postMessage selection -> saving state locks close/Escape/backdrop until UPDATE_TIMEOUT_MS
	const sourceWin = {};
	Object.defineProperty(liveIframe, 'contentWindow', { value: sourceWin, configurable: true });
	runtime.dom.window.dispatchEvent(new runtime.dom.window.MessageEvent('message', {
		origin: runtime.api.WIDGET_ORIGIN,
		source: sourceWin,
		data: validPayload()
	}));
	await runtime.flushMicrotasks();
	await runtime.advanceTimersBy(0);

	assert.equal(runtime.cartUpdates.length, 1);
	assert.equal(Object.prototype.hasOwnProperty.call(runtime.cartUpdates[0].data, 'shipping_address'), false);
	const closeBtn = doc.querySelector('.akb-pudo-dialog__close');
	const savingBox = doc.querySelector('.akb-pudo-dialog__saving');
	assert.ok(closeBtn && savingBox);
	assert.equal(closeBtn.disabled, true);
	assert.equal(selectBtn.disabled, true);
	assert.equal(savingBox.getAttribute('role'), 'status');
	assert.equal(doc.querySelector('.akb-pudo-dialog__iframe').getAttribute('tabindex'), '-1');

	// Escape & backdrop mousedown cannot dismiss while saving before timeout
	dialog.dispatchEvent(new runtime.dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
	modal.dispatchEvent(new runtime.dom.window.MouseEvent('mousedown', { bubbles: true, cancelable: true }));
	await runtime.flushMicrotasks();
	assert.ok(doc.querySelector('.akb-pudo-modal'), 'Modal remains open while saving before UX timeout');

	// Advance past UPDATE_TIMEOUT_MS -> dismissible with informative copy
	await runtime.advanceTimersBy(15000);
	assert.equal(doc.querySelector('.akb-pudo-dialog__close').disabled, false);
	assert.ok(doc.querySelector('.akb-pudo-dialog__saving').textContent.includes('elegir otro método de envío o recargar la página'));

	// Dismissing via Escape restores background & focus while in-flight promise settles later
	doc.querySelector('[role="dialog"]').dispatchEvent(new runtime.dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
	await runtime.flushMicrotasks();
	await runtime.advanceTimersBy(0);
	assert.equal(doc.querySelector('.akb-pudo-modal'), null);
	assert.equal(doc.body.classList.contains('akb-pudo-modal-open'), false);
	assert.equal(doc.getElementById('site-header').hasAttribute('inert'), false);

	resolveUpdate();
	await runtime.flushMicrotasks();
	await runtime.unmount();
});

test('surfaces Spanish server error alert and unlocks dialog when Store API rejects PUDO update', async () => {
	const rejected = {
		code: 'akibara_pudo_stale_attempt',
		message: 'Otra selección de punto de retiro reemplazó esta solicitud. Revisa el punto vigente.'
	};
	const runtime = createHooksDomRuntime({
		extensionCartUpdate() {
			return Promise.reject(rejected);
		}
	});
	const doc = runtime.dom.window.document;
	await runtime.mount(() => ({
		cart: {
			shippingRates: [
				{
					package_id: 0,
					destination: { city: 'Curicó', state: 'CL-ML', country: 'CL' },
					shipping_rates: [{ method_id: 'bluex-pudo', rate_id: 'bluex-pudo:3', selected: true }]
				}
			]
		},
		extensions: {}
	}));

	doc.querySelector('.akb-pudo-button').click();
	await runtime.flushMicrotasks();
	await runtime.advanceTimersBy(0);

	const iframe = doc.querySelector('.akb-pudo-dialog__iframe');
	iframe.dispatchEvent(new runtime.dom.window.Event('load'));
	await runtime.flushMicrotasks();
	await runtime.advanceTimersBy(0);

	const liveIframe = doc.querySelector('.akb-pudo-dialog__iframe');
	const sourceWin = {};
	Object.defineProperty(liveIframe, 'contentWindow', { value: sourceWin, configurable: true });
	runtime.dom.window.dispatchEvent(new runtime.dom.window.MessageEvent('message', {
		origin: runtime.api.WIDGET_ORIGIN,
		source: sourceWin,
		data: validPayload()
	}));
	await runtime.flushMicrotasks();
	await runtime.advanceTimersBy(0);

	const errAlert = doc.querySelector('.akb-pudo-dialog__error[role="alert"]');
	assert.ok(errAlert);
	assert.ok(errAlert.textContent.includes(rejected.message));
	assert.equal(doc.querySelector('.akb-pudo-dialog__saving'), null);
	assert.equal(doc.querySelector('.akb-pudo-dialog__close').disabled, false);
	await runtime.unmount();
});

test('logs warning, blocks checkout validation and renders retry CTA when clearing PUDO fails on method switch', async () => {
	let attemptCount = 0;
	let propsState = {
		hasPudo: false,
		agencyId: 'AG-27136'
	};
	const runtime = createHooksDomRuntime({
		extensionCartUpdate() {
			attemptCount += 1;
			if (attemptCount === 1) {
				return Promise.reject({
					code: 'akibara_pudo_unavailable',
					message: 'Blue Express no respondió al limpiar el punto anterior.'
				});
			}
			propsState.agencyId = '';
			return Promise.resolve({});
		}
	});
	const doc = runtime.dom.window.document;
	const makeProps = () => ({
		cart: {
			shippingRates: [
				{
					package_id: 0,
					shipping_rates: [
						propsState.hasPudo
							? { method_id: 'bluex-pudo', rate_id: 'bluex-pudo:3', selected: true }
							: { method_id: 'flat_rate', rate_id: 'flat_rate:1', selected: true }
					]
				}
			]
		},
		extensions: {
			bluex_pudo: {
				agency_id: propsState.agencyId
			}
		}
	});

	await runtime.mount(makeProps);
	await runtime.flushMicrotasks();
	await runtime.advanceTimersBy(0);

	assert.equal(attemptCount, 1);
	assert.equal(runtime.warnings.length, 1);
	const alertBox = doc.querySelector('.akb-pudo-package-error[role="alert"]');
	assert.ok(alertBox);
	assert.ok(alertBox.textContent.includes('Blue Express no respondió al limpiar el punto anterior.'));
	const lastError = runtime.validation.errors[runtime.validation.errors.length - 1];
	assert.equal(
		lastError['akibara-pudo-selection']?.message,
		'Blue Express no respondió al limpiar el punto anterior.'
	);

	const retryBtn = alertBox.querySelector('button');
	assert.equal(retryBtn?.textContent, 'Reintentar');
	retryBtn.click();
	await runtime.flushMicrotasks();
	await runtime.advanceTimersBy(0);
	await runtime.update(makeProps);
	await runtime.flushMicrotasks();
	await runtime.advanceTimersBy(0);

	assert.equal(attemptCount, 2);
	assert.equal(doc.querySelector('.akb-pudo-package-error'), null);
	assert.equal(runtime.validation.cleared[runtime.validation.cleared.length - 1], 'akibara-pudo-selection');
	await runtime.unmount();
});

test('focuses multi-package alert in DOM and cleans dead/literal z-index styles from CSS', async () => {
	const runtime = bootUtilities();
	const doc = runtime.dom.window.document;
	await runtime.mount(() => ({
		cart: {
			shippingRates: [
				{ package_id: 0, shipping_rates: [{ method_id: 'bluex-pudo', rate_id: 'bluex-pudo:3', selected: true }] },
				{ package_id: 1, shipping_rates: [{ method_id: 'bluex-ex', rate_id: 'bluex-ex:4', selected: true }] }
			]
		},
		extensions: {}
	}));

	const multiErr = doc.querySelector('.akb-pudo-package-error');
	assert.ok(multiErr);
	assert.equal(multiErr.getAttribute('role'), 'alert');
	assert.equal(multiErr.getAttribute('tabindex'), '-1');
	assert.equal(doc.activeElement, multiErr, 'Multi-package alert takes focus');
	await runtime.unmount();

	const cssRules = parseCssRules(fs.readFileSync(STYLE_PATH, 'utf8'));
	const findRule = (selector, media = '') => cssRules.find(
		(rule) => rule.media === media && rule.selectors.includes(selector)
	);

	const modalRule = findRule('.akb-pudo-modal');
	assert.ok(modalRule);
	assert.equal(modalRule.declarations.height, '100dvh');
	assert.equal(modalRule.declarations['z-index'], 'var(--wp--custom--z--cart, 10400)');
	assert.ok(modalRule.declarations.padding.includes('safe-area-inset-bottom'));

	const buttonRule = findRule('.akb-pudo-button');
	assert.equal(buttonRule.declarations['min-height'], '44px');
	assert.ok(findRule('.akb-pudo-package__validation'));
	assert.equal(findRule('.akb-pudo-dialog__iframe[aria-hidden="true"]')?.declarations['pointer-events'], 'none');

	const reducedSpinner = findRule('.akb-pudo-spinner', '@media (prefers-reduced-motion: reduce)');
	assert.equal(reducedSpinner?.declarations.animation, 'none');

	const backdropLayersRule = findRule('body.akb-pudo-modal-open .wc-block-components-notice-snackbar-list');
	assert.ok(backdropLayersRule && backdropLayersRule.selectors.includes('body.akb-pudo-modal-open #wpadminbar'));
	assert.equal(backdropLayersRule.declarations['z-index'], 'var(--wp--custom--z--toast, 500)');

	for (const rule of cssRules) {
		const z = rule.declarations['z-index'];
		if (z) {
			assert.equal(/^\d{3,}$/.test(z), false, `Sin z-index numérico literal >=100 (${z})`);
		}
		assert.equal(
			(rule.declarations.outline || '').includes('var(--aki-yellow'),
			false,
			'Sin outline amarillo duplicado'
		);
	}
	assert.ok(findRule('.akb-pudo-dialog:focus')?.declarations['box-shadow']?.includes('0 24px 80px'));
	assert.ok(findRule('.akb-pudo-dialog iframe:focus-visible')?.declarations['box-shadow']?.includes('--aki-focus-ring'));
	assert.ok(findRule('.akb-pudo-button[aria-invalid="true"]:focus-visible'));
});

test('reads the customer region only from a valid Chilean Store API destination', () => {
	const { api } = bootUtilities();
	const withDestination = (destination) => [{ destination, shipping_rates: [] }];

	// The destination object crosses a vm realm, so compare fields, not prototypes.
	const curico = api.pudoDestination(withDestination({
		address_1: 'Volcán Llaima 715',
		city: 'Curicó',
		state: 'CL-ML',
		postcode: '',
		country: 'CL'
	}));
	assert.equal(curico.city, 'Curicó');
	assert.equal(curico.region, 'CL-ML');

	// A region alone is enough; the city only enriches the copy.
	const regionOnly = api.pudoDestination(withDestination({ city: '', state: 'CL-RM', country: 'CL' }));
	assert.equal(regionOnly.city, '');
	assert.equal(regionOnly.region, 'CL-RM');

	// Anything unusable must degrade to null so the server stays the only gate.
	assert.equal(api.pudoDestination([]), null);
	assert.equal(api.pudoDestination(withDestination(null)), null);
	assert.equal(api.pudoDestination(withDestination({ city: 'Lima', state: 'CL-ML', country: 'PE' })), null);
	assert.equal(api.pudoDestination(withDestination({ city: 'Curicó', state: 'ML', country: 'CL' })), null);
	assert.equal(api.pudoDestination(withDestination({ city: 'Curicó', state: '', country: 'CL' })), null);
});

test('never pre-judges a pickup point by its region', () => {
	const { api } = bootUtilities();

	// BlueExpress no restringe el retiro a la región de despacho —su módulo cotiza
	// con la comuna del cliente y jamás lee `state_name`—, así que el navegador no
	// debe exponer una comparación que bloquee puntos de otra región.
	assert.equal(typeof api.regionMismatchMessage, 'undefined');
});

test('states the shipping destination before the map opens', () => {
	const { api } = bootUtilities();

	assert.equal(api.destinationPlaceLabel({ city: 'Curicó', region: 'CL-ML' }), 'Curicó, Maule');
	assert.equal(api.destinationPlaceLabel({ city: '', region: 'CL-ML' }), 'Maule');
	assert.equal(api.destinationPlaceLabel(null), '');

	assert.match(api.regionScopeHint({ city: 'Curicó', region: 'CL-ML' }), /Curicó, Maule/);
	assert.match(api.regionScopeHint({ city: 'Curicó', region: 'CL-ML' }), /Busca tu punto de retiro en el mapa/);
	assert.equal(api.regionScopeHint(null), '');

	// Every region the widget can return must have a name for the copy above.
	['CL-AI', 'CL-AN', 'CL-AP', 'CL-AR', 'CL-AT', 'CL-BI', 'CL-CO', 'CL-LI', 'CL-LL',
		'CL-LR', 'CL-MA', 'CL-ML', 'CL-NB', 'CL-RM', 'CL-TA', 'CL-VS'
	].forEach((code) => {
		assert.notEqual(api.chileRegionName(code), '', `missing name for ${code}`);
	});
});

test('paints focus rules without duplicate outlines and hides inert/notice dead markup', async () => {
	const runtime = bootUtilities();
	await runtime.mount(() => ({
		cart: {
			shippingRates: [
				{
					package_id: 0,
					destination: { city: 'Curicó', state: 'CL-ML', country: 'CL' },
					shipping_rates: [{ method_id: 'bluex-pudo', rate_id: 'bluex-pudo:3', selected: true }]
				}
			]
		},
		extensions: {}
	}));

	const doc = runtime.dom.window.document;
	doc.querySelector('.akb-pudo-button').click();
	await runtime.flushMicrotasks();
	await runtime.advanceTimersBy(0);

	assert.equal(doc.querySelector('.akb-pudo-dialog__notice'), null);
	assert.ok(doc.querySelector('.akb-pudo-dialog__scope')?.textContent.includes('Curicó, Maule'));
	assert.equal(doc.activeElement, doc.querySelector('[role="dialog"]'));
	assert.notEqual(doc.activeElement, doc.querySelector('.akb-pudo-dialog__close'));
	await runtime.unmount();
});

test('serverMessage() sólo deja pasar los códigos reales de PudoIntegration; todo lo demás cae al castellano', () => {
	// R4-42974e1f (2026-09-19): el cliente podía ver un error crudo del
	// navegador o de apiFetch, en inglés, en el modal de retiro Blue Express.
	const { api } = bootUtilities();

	// Caso 1 — error nuestro: RouteException que PudoIntegration::throw_route_error()
	// lanza para el endpoint `bluex_pudo` (ver PudoIntegration.php); apiFetch lo
	// entrega ya parseado como `{code, message, data}`. Debe mostrarse tal cual.
	const backendError = {
		code: 'akibara_pudo_stale_attempt',
		message: 'Otra selección de punto de retiro reemplazó esta solicitud. Revisa el punto vigente.',
		data: { status: 409 }
	};
	assert.equal(api.serverMessage(backendError), backendError.message);

	// Todos los demás códigos reales del plugin también deben pasar: si alguno
	// se cae de la lista, este caso lo detecta sin tener que enumerarlos aparte.
	[
		'akibara_pudo_checkout_invalid',
		'akibara_pudo_invalid_destination',
		'akibara_pudo_invalid_payload',
		'akibara_pudo_method_not_selected',
		'akibara_pudo_mismatched_selection',
		'akibara_pudo_multiple_packages',
		'akibara_pudo_mutation_conflict',
		'akibara_pudo_order_unavailable',
		'akibara_pudo_stale_attempt',
		'akibara_pudo_unavailable',
		'akibara_pudo_untrusted_payload',
		'akibara_pudo_validation_failed'
	].forEach((code) => {
		assert.equal(api.serverMessage({ code: code, message: 'texto propio' }), 'texto propio', `código propio rechazado: ${code}`);
	});

	// Caso 2 — error de red del propio navegador: un TypeError nativo de
	// fetch() cuando la petición nunca llega a tener respuesta HTTP (corte de
	// conexión, DNS, CORS). No trae `code`, así que debe caer al mensaje en
	// castellano que ya pone handleMessage(), nunca mostrar este texto fijo
	// del navegador.
	assert.equal(api.serverMessage(new TypeError('Failed to fetch')), '');
	assert.equal(api.serverMessage(new TypeError('NetworkError when attempting to fetch resource.')), '');

	// Caso 3 — apiFetch no pudo parsear la respuesta como JSON (p. ej. un
	// cuerpo de error de LiteSpeed/Cloudflare sin llegar a ser HTML con '<').
	// Ver wp-includes/js/dist/api-fetch.js: el código por defecto es
	// 'invalid_json', no uno de los nuestros.
	assert.equal(
		api.serverMessage({ code: 'invalid_json', message: 'The response is not a valid JSON response.' }),
		''
	);

	// Un código ajeno —incluso el genérico de WooCommerce Blocks para
	// extensiones del carrito— tampoco cuela: sólo la lista real de
	// PudoIntegration::throw_route_error() pasa.
	assert.equal(
		api.serverMessage({ code: 'woocommerce_rest_cart_extensions_error', message: 'algo' }),
		''
	);

	// Guardas de forma degenerada: nunca debe reventar ni colar un `code`
	// falsificado que no sea texto.
	assert.equal(api.serverMessage(null), '');
	assert.equal(api.serverMessage({ message: 'sin code' }), '');
	assert.equal(api.serverMessage({ code: 42, message: 'code no es string' }), '');
	assert.equal(api.serverMessage({ code: 'akibara_pudo_unavailable', message: 123 }), '');
});
