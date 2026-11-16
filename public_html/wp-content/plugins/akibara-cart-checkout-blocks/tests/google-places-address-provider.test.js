const assert = require('node:assert/strict');
const test = require('node:test');

const { createDOM } = require('../../../../../tools/testing/dom-harness');

const PROVIDER_SCRIPT_REL_PATH = 'public_html/wp-content/plugins/akibara-cart-checkout-blocks/assets/js/google-places-address-provider.js';
const activeDoms = [];

test.afterEach(() => {
	while (activeDoms.length > 0) {
		activeDoms.pop().teardown();
	}
});

function deferred() {
	let reject;
	let resolve;
	const promise = new Promise((nextResolve, nextReject) => {
		resolve = nextResolve;
		reject = nextReject;
	});

	return { promise, reject, resolve };
}

// ¿Ya respondió? Un setImmediate corre después de todas las microtareas.
async function settlesNow(promise) {
	return Promise.race([
		promise.then(() => true, () => true),
		new Promise((resolve) => setImmediate(() => resolve(false)))
	]);
}

async function waitForRequest(requests, input) {
	for (let attempt = 0; attempt < 10 && !requests.has(input); attempt += 1) {
		await Promise.resolve();
	}
	assert.ok(requests.has(input), 'expected deferred request for ' + input);
	return requests.get(input);
}

function buildCheckoutHtml(addressTypes) {
	const containersHtml = addressTypes
		.map(
			(addressType) =>
				`<div class="wc-block-components-address-autocomplete-container" data-address-type="${addressType}">` +
				`<div class="wc-block-components-text-input">` +
				`<input role="combobox" aria-autocomplete="list" aria-describedby="${addressType}-validation">` +
				`</div>` +
				`</div>`
		)
		.join('');

	return `<main class="woocommerce-checkout">${containersHtml}</main>`;
}

function bootProvider(addressComponents, options = {}) {
	const autocompleteRequests = [];
	const animationFrames = new Map();
	const documentListeners = [];
	const fieldRequests = [];
	const notices = [];
	const scheduledTimeouts = [];
	const visualViewportListeners = new Map();
	const windowListeners = new Map();
	const elementListeners = new WeakMap();
	const listenerOptions = new WeakMap();
	const activeObservers = new Set();
	const elementRects = new WeakMap();
	const storageAccesses = [];
	const htmlSinkCalls = [];
	let animationFrameId = 0;
	let importLibraryCallCount = 0;
	let loaderAppendCount = 0;
	let provider;
	let timeoutId = 0;
	let tokenCount = 0;
	let now = 1_000_000;

	const dom = createDOM({
		html: options.addressTypes ? buildCheckoutHtml(options.addressTypes) : '<main></main>',
		innerHeight: options.viewportHeight || 900,
		url: 'https://akibara.cl/checkout/'
	});
	activeDoms.push(dom);

	const { document, window } = dom;
	const RealDate = window.Date;
	window.Date = class extends RealDate {
		static now() {
			return now;
		}
	};

	const emptyRect = () => ({ bottom: 0, height: 0, left: 0, right: 0, top: 0, width: 0 });
	window.Element.prototype.setRect = function (rect) {
		const next = {
			...(elementRects.get(this) || emptyRect()),
			...rect
		};
		if (rect.height === undefined && rect.top !== undefined && rect.bottom !== undefined) {
			next.height = rect.bottom - rect.top;
		}
		if (rect.width === undefined && rect.left !== undefined && rect.right !== undefined) {
			next.width = rect.right - rect.left;
		}
		elementRects.set(this, next);
	};
	window.Element.prototype.getBoundingClientRect = function () {
		return { ...(elementRects.get(this) || emptyRect()) };
	};

	if (options.trackSecuritySinks) {
		const innerHtmlDescriptor = Object.getOwnPropertyDescriptor(window.Element.prototype, 'innerHTML');
		Object.defineProperty(window.Element.prototype, 'innerHTML', {
			configurable: true,
			get() {
				return innerHtmlDescriptor.get.call(this);
			},
			set(value) {
				htmlSinkCalls.push(['innerHTML', String(value)]);
				return innerHtmlDescriptor.set.call(this, value);
			}
		});
		const origInsertAdjacentHTML = window.Element.prototype.insertAdjacentHTML;
		window.Element.prototype.insertAdjacentHTML = function (position, text) {
			htmlSinkCalls.push(['insertAdjacentHTML', String(text)]);
			return origInsertAdjacentHTML.call(this, position, text);
		};
		for (const storageName of ['localStorage', 'sessionStorage']) {
			const backing = window[storageName];
			Object.defineProperty(window, storageName, {
				configurable: true,
				value: {
					getItem(key) {
						storageAccesses.push([storageName, 'get', key]);
						return backing.getItem(key);
					},
					setItem(key, value) {
						storageAccesses.push([storageName, 'set', key, value]);
						backing.setItem(key, value);
					},
					removeItem(key) {
						storageAccesses.push([storageName, 'remove', key]);
						backing.removeItem(key);
					}
				}
			});
		}
	}

	const origWinAddEventListener = window.addEventListener.bind(window);
	const origWinRemoveEventListener = window.removeEventListener.bind(window);
	window.addEventListener = function (type, listener, listenerOpts) {
		const listeners = windowListeners.get(type) || new Set();
		listeners.add(listener);
		windowListeners.set(type, listeners);
		return origWinAddEventListener(type, listener, listenerOpts);
	};
	window.removeEventListener = function (type, listener, listenerOpts) {
		windowListeners.get(type)?.delete(listener);
		return origWinRemoveEventListener(type, listener, listenerOpts);
	};

	const nodeEventTargetProto = Object.getPrototypeOf(window.Node.prototype);
	const origNodeAddEventListener = nodeEventTargetProto.addEventListener;
	const origNodeRemoveEventListener = nodeEventTargetProto.removeEventListener;
	nodeEventTargetProto.addEventListener = function (type, listener, listenerOpts) {
		if (this === document) {
			documentListeners.push({ listener, options: listenerOpts, type });
		} else if (this instanceof window.Element) {
			let byType = elementListeners.get(this);
			if (!byType) {
				byType = new Map();
				elementListeners.set(this, byType);
			}
			const listeners = byType.get(type) || new Set();
			listeners.add(listener);
			byType.set(type, listeners);
			listenerOptions.set(listener, listenerOpts);
		}
		return origNodeAddEventListener.call(this, type, listener, listenerOpts);
	};
	nodeEventTargetProto.removeEventListener = function (type, listener, listenerOpts) {
		if (this === document) {
			const index = documentListeners.findIndex((entry) => entry.type === type && entry.listener === listener);
			if (index >= 0) {
				documentListeners.splice(index, 1);
			}
		} else if (this instanceof window.Element) {
			elementListeners.get(this)?.get(type)?.delete(listener);
		}
		return origNodeRemoveEventListener.call(this, type, listener, listenerOpts);
	};

	const NativeMutationObserver = window.MutationObserver;
	window.MutationObserver = class extends NativeMutationObserver {
		constructor(callback) {
			super(callback);
			this.options = {};
			this.target = null;
		}
		observe(target, observerOptions) {
			this.target = target;
			this.options = observerOptions;
			activeObservers.add(this);
			return super.observe(target, observerOptions);
		}
		disconnect() {
			activeObservers.delete(this);
			this.target = null;
			return super.disconnect();
		}
	};

	const autocompleteDom = options.addressTypes
		? {
			containers: new Map(
				options.addressTypes.map((type) => [
					type,
					document.querySelector(`.wc-block-components-address-autocomplete-container[data-address-type="${type}"]`)
				])
			),
			inputs: new Map(
				options.addressTypes.map((type) => [
					type,
					document.querySelector(`.wc-block-components-address-autocomplete-container[data-address-type="${type}"] input[role="combobox"]`)
				])
			)
		}
		: null;

	const communeRegions = options.communeRegions === undefined
		? {
			RM: ['Las Condes', 'Providencia', 'Santiago'],
			'CL-RM': ['Las Condes', 'Providencia', 'Santiago']
		}
		: options.communeRegions;

	class AutocompleteSessionToken {
		constructor() {
			tokenCount += 1;
			this.id = tokenCount;
		}
	}

	const places = {
		AutocompleteSessionToken,
		AutocompleteSuggestion: {
			async fetchAutocompleteSuggestions(request) {
				autocompleteRequests.push(request);
				if (options.suggestionsError) {
					throw new Error('Places suggestions unavailable');
				}
				const input = request.input;
				const prediction = {
					placeId: input + '-id',
					text: options.predictionText || {
						toString() {
							return options.predictionLabel || input;
						}
					},
					toPlace() {
						return {
							addressComponents: [],
							async fetchFields(fields) {
								fieldRequests.push(fields);
								if (options.fetchFieldsError) {
									throw new Error('Places details unavailable');
								}
								if (typeof options.fieldsResponder === 'function') {
									await options.fieldsResponder();
								}
								this.addressComponents = addressComponents || [
									{ types: ['route'], longText: 'Providencia' },
									{ types: ['street_number'], longText: '123' },
									{ types: ['locality'], longText: 'Santiago' },
									{ types: ['administrative_area_level_1'], shortText: 'RM' },
									{ types: ['country'], shortText: 'CL' }
								];
							}
						};
					}
				};

				const response = {
					suggestions: [{ placePrediction: prediction }]
				};
				return typeof options.suggestionsResponder === 'function'
					? options.suggestionsResponder(request, response)
					: response;
			}
		}
	};

	const addressAutocomplete = {
		providers: {},
		serverProviders: [{ id: 'akibara-google-places' }],
		registerAddressAutocompleteProvider(nextProvider) {
			provider = nextProvider;
			this.providers[nextProvider.id] = nextProvider;
		}
	};
	const maps = {
		async importLibrary(name) {
			assert.equal(name, 'places');
			importLibraryCallCount += 1;
			if (importLibraryCallCount <= (options.importLibraryFailures || 0)) {
				throw new Error('Google importLibrary unavailable');
			}
			return places;
		}
	};

	window.akbComunaData = communeRegions ? { regions: communeRegions } : undefined;
	window.akbCartCheckoutBlocksGoogle = {
		loaderUrl: 'https://maps.googleapis.test/loader.js',
		providerId: 'akibara-google-places',
		regionMap: options.regionMap || { RM: 'RM' }
	};
	window.google = options.googleImportAfterLoaderOnload ? undefined : { maps };
	window.requestAnimationFrame = function (callback) {
		animationFrameId += 1;
		animationFrames.set(animationFrameId, callback);
		return animationFrameId;
	};
	window.cancelAnimationFrame = function (frameId) {
		animationFrames.delete(frameId);
	};
	window.setTimeout = function (callback, delay = 0) {
		timeoutId += 1;
		const timeout = { callback, delay: Number(delay) || 0, id: timeoutId };
		scheduledTimeouts.push(timeout);
		return timeout.id;
	};
	window.clearTimeout = function (id) {
		const index = scheduledTimeouts.findIndex((timeout) => timeout.id === id);
		if (index >= 0) {
			scheduledTimeouts.splice(index, 1);
		}
	};
	window.wp = {
		data: {
			dispatch(storeName) {
				assert.equal(storeName, 'core/notices');
				return {
					createErrorNotice(message, settings) {
						notices.push({ message, settings });
					}
				};
			}
		}
	};
	window.wc = { addressAutocomplete };

	if (options.visualViewport) {
		window.visualViewport = {
			height: options.visualViewport.height,
			offsetTop: options.visualViewport.offsetTop || 0,
			addEventListener(type, listener) {
				const listeners = visualViewportListeners.get(type) || new Set();
				listeners.add(listener);
				visualViewportListeners.set(type, listeners);
			},
			removeEventListener(type, listener) {
				visualViewportListeners.get(type)?.delete(listener);
			}
		};
	}

	const origHeadAppendChild = document.head.appendChild.bind(document.head);
	document.head.appendChild = function (node) {
		if (node && node.tagName === 'SCRIPT') {
			if (options.googleImportAfterLoaderOnload) {
				loaderAppendCount += 1;
				if (typeof node.onload === 'function') {
					node.onload();
				}
				window.google = { maps };
				while (scheduledTimeouts.length > 0) {
					scheduledTimeouts.shift().callback();
				}
				return node;
			}
			throw new Error('No script should be appended in this fixture.');
		}
		return origHeadAppendChild(node);
	};

	dom.loadScript(PROVIDER_SCRIPT_REL_PATH);
	if (!options.googleImportAfterLoaderOnload && !autocompleteDom) {
		assert.equal(scheduledTimeouts.length, 0, 'Provider registration should not need retries.');
	}

	function flushingProvider() {
		return {
			get id() {
				return provider.id;
			},
			canSearch: (...args) => provider.canSearch(...args),
			select: (...args) => provider.select(...args),
			search(...args) {
				const before = new Set(scheduledTimeouts.map((timeout) => timeout.id));
				const result = provider.search(...args);
				const created = scheduledTimeouts.filter((timeout) => !before.has(timeout.id));
				created.forEach((timeout) => {
					assert.ok([0, 250].includes(timeout.delay), 'a keystroke only schedules the search timer');
					scheduledTimeouts.splice(scheduledTimeouts.indexOf(timeout), 1);
					timeout.callback();
				});
				return result;
			}
		};
	}

	function dispatchBlur(input) {
		if (document.activeElement === input) {
			input.blur();
		} else {
			input.dispatchEvent(new window.FocusEvent('blur', { bubbles: false }));
		}
	}

	return {
		window,
		document,
		appendSuggestions(addressType, geometry = {}) {
			const suggestions = document.createElement('div');
			suggestions.className = 'wc-block-components-address-autocomplete-suggestions';
			const list = document.createElement('ul');
			list.className = 'suggestions-list';
			const branding = document.createElement('div');
			branding.className = 'woocommerce-address-autocomplete-branding';
			suggestions.appendChild(list);
			suggestions.appendChild(branding);
			autocompleteDom.containers.get(addressType).appendChild(suggestions);
			if (geometry.input) {
				autocompleteDom.inputs.get(addressType).setRect(geometry.input);
			}
			if (geometry.panel) {
				suggestions.setRect(geometry.panel);
			}
			if (geometry.list) {
				list.setRect(geometry.list);
			}
			if (geometry.branding) {
				branding.setRect(geometry.branding);
			}
			return suggestions;
		},
		activate(addressType, value) {
			const input = autocompleteDom.inputs.get(addressType);
			input.value = value;
			input.focus();
			return input;
		},
		advanceTime(milliseconds) {
			now += milliseconds;
		},
		autocompleteRequests,
		fieldRequests,
		htmlSinkCalls,
		storageAccesses,
		getProvider() {
			return provider ? flushingProvider() : provider;
		},
		getRawProvider() {
			return provider;
		},
		getAnimationFrameCount() {
			return animationFrames.size;
		},
		getPendingTimeouts() {
			return scheduledTimeouts.map(({ delay, id }) => ({ delay, id }));
		},
		getMutationObservers() {
			return [...activeObservers];
		},
		getDocumentListeners(type) {
			return documentListeners.filter((entry) => entry.type === type);
		},
		getInputListeners(addressType, type) {
			const input = autocompleteDom.inputs.get(addressType);
			const listeners = [...(elementListeners.get(input)?.get(type) || [])];
			return listeners.map((listener) => ({
				listener,
				options: listenerOptions.get(listener)
			}));
		},
		dispatchFocusIn(target) {
			target.dispatchEvent(new window.FocusEvent('focusin', { bubbles: true }));
		},
		dispatchDocumentEvent(type, target = document.body) {
			target.dispatchEvent(new window.Event(type, { bubbles: true, cancelable: true }));
		},
		getTokenCount() {
			return tokenCount;
		},
		getLoaderAppendCount() {
			return loaderAppendCount;
		},
		getImportLibraryCallCount() {
			return importLibraryCallCount;
		},
		flushTimeouts(maximumDelay = Infinity) {
			let timeoutIndex = scheduledTimeouts.findIndex(
				(timeout) => timeout.delay <= maximumDelay
			);
			while (timeoutIndex >= 0) {
				const [timeout] = scheduledTimeouts.splice(timeoutIndex, 1);
				timeout.callback();
				timeoutIndex = scheduledTimeouts.findIndex(
					(nextTimeout) => nextTimeout.delay <= maximumDelay
				);
			}
		},
		flushAnimationFrames(limit = 50) {
			let framesRun = 0;
			while (animationFrames.size > 0 && framesRun < limit) {
				const frames = [...animationFrames.entries()];
				animationFrames.clear();
				frames.forEach(([, callback]) => callback());
				framesRun += 1;
			}
			assert.ok(framesRun < limit, 'animation frame work must stay bounded');
		},
		getContainer(addressType) {
			return autocompleteDom.containers.get(addressType);
		},
		getFeedback(addressType) {
			return [...autocompleteDom.containers.get(addressType).children].find(
				(child) => child.className.includes('akb-address-autocomplete-feedback')
			);
		},
		getInput(addressType) {
			return autocompleteDom.inputs.get(addressType);
		},
		getWindowListenerCount(type) {
			return windowListeners.get(type)?.size || 0;
		},
		getVisualViewportListenerCount(type) {
			return visualViewportListeners.get(type)?.size || 0;
		},
		input(addressType, value) {
			const input = this.activate(addressType, value);
			input.dispatchEvent(new window.Event('input', { bubbles: true }));
			return input;
		},
		reparentAndRefocus(addressType) {
			const input = autocompleteDom.inputs.get(addressType);
			const field = input.parentNode;
			dispatchBlur(input);
			field.removeChild(input);
			field.appendChild(input);
			input.focus();
			this.flushTimeouts();
		},
		blur(addressType) {
			this.startBlur(addressType);
			this.flushTimeouts();
		},
		startBlur(addressType) {
			const input = autocompleteDom.inputs.get(addressType);
			dispatchBlur(input);
		},
		pressSuggestion(addressType) {
			const panel = autocompleteDom.containers.get(addressType)
				.querySelector('.wc-block-components-address-autocomplete-suggestions');
			const list = panel.querySelector('.suggestions-list');
			const option = document.createElement('li');
			option.className = 'wc-block-components-address-autocomplete-suggestion';
			option.tabIndex = -1;
			list.appendChild(option);
			const input = autocompleteDom.inputs.get(addressType);
			let blurred = false;
			input.addEventListener('blur', () => {
				blurred = true;
			}, { once: true });
			option.focus();
			if (!blurred) {
				input.dispatchEvent(new window.FocusEvent('blur', { bubbles: false }));
			}
			return option;
		},
		touchSuggestion(addressType) {
			const list = autocompleteDom.containers.get(addressType)
				.querySelector('.wc-block-components-address-autocomplete-suggestions')
				.querySelector('.suggestions-list');
			const option = document.createElement('li');
			option.className = 'wc-block-components-address-autocomplete-suggestion';
			list.appendChild(option);
			this.dispatchDocumentEvent('pointerdown', option);
			this.dispatchDocumentEvent('touchstart', option);
			return option;
		},
		dispatchWindowEvent(type) {
			window.dispatchEvent(new window.Event(type));
		},
		setViewportHeight(height) {
			window.innerHeight = height;
			if (document.documentElement) {
				Object.defineProperty(document.documentElement, 'clientHeight', {
					configurable: true,
					value: height
				});
			}
		},
		dispatchVisualViewportEvent(type) {
			for (const listener of visualViewportListeners.get(type) || []) {
				listener({ type });
			}
		},
		setVisualViewport(bounds) {
			Object.assign(window.visualViewport, bounds);
		},
		notices
	};
}

test('shows a scoped live loading state and aria-busy until deferred suggestions settle', async () => {
	const pending = deferred();
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		suggestionsResponder: () => pending.promise
	});
	const provider = runtime.getProvider();
	const input = runtime.activate('shipping', 'Avenida Providencia 1234');

	const search = provider.search('Avenida Providencia 1234', 'CL');
	const feedback = runtime.getFeedback('shipping');

	assert.equal(input.getAttribute('aria-busy'), 'true');
	assert.equal(feedback.hidden, false);
	assert.equal(feedback.getAttribute('role'), 'status');
	assert.equal(feedback.getAttribute('aria-live'), 'polite');
	assert.match(feedback.textContent, /Buscando direcciones/);

	pending.resolve({ suggestions: [] });
	await search;

	assert.equal(input.getAttribute('aria-busy'), null);
	assert.match(feedback.textContent, /No encontramos direcciones/);
});

test('keeps loading feedback when Woo reparents and refocuses the active combobox', async () => {
	const pending = deferred();
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		suggestionsResponder: () => pending.promise
	});
	const provider = runtime.getProvider();
	const input = runtime.activate('shipping', 'Avenida Providencia 1234');
	const search = provider.search('Avenida Providencia 1234', 'CL');
	const feedback = runtime.getFeedback('shipping');

	runtime.reparentAndRefocus('shipping');

	assert.equal(input.getAttribute('aria-busy'), 'true');
	assert.equal(feedback.hidden, false);
	assert.equal(feedback.getAttribute('role'), 'status');
	assert.match(feedback.textContent, /Buscando direcciones/);

	pending.resolve({ suggestions: [] });
	await search;
});

test('keeps the rendered suggestions below the field when the panel fits safely', async () => {
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		viewportHeight: 900
	});
	const provider = runtime.getProvider();
	runtime.activate('shipping', 'Avenida Providencia 1234');

	await provider.search('Avenida Providencia 1234', 'CL');
	runtime.appendSuggestions('shipping', {
		input: { bottom: 250, height: 50, top: 200 },
		list: { bottom: 494, height: 240, top: 254 },
		panel: { bottom: 538, height: 284, top: 254 }
	});
	runtime.flushAnimationFrames();

	const container = runtime.getContainer('shipping');
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), false);
	assert.equal(container.classList.contains('akb-address-autocomplete-constrained'), false);
	assert.equal(
		container.style.getPropertyValue('--akb-address-autocomplete-list-max-height'),
		''
	);
	assert.equal(runtime.getWindowListenerCount('resize'), 1);
	assert.equal(runtime.getWindowListenerCount('scroll'), 1);
});

test('flips the rendered suggestions above when the panel does not fit below', async () => {
	const runtime = bootProvider(undefined, {
		addressTypes: ['billing'],
		viewportHeight: 900
	});
	const provider = runtime.getProvider();
	runtime.activate('billing', 'Avenida Apoquindo 4501');

	await provider.search('Avenida Apoquindo 4501', 'CL');
	runtime.appendSuggestions('billing', {
		input: { bottom: 750, height: 50, top: 700 },
		list: { bottom: 994, height: 240, top: 754 },
		panel: { bottom: 1038, height: 284, top: 754 }
	});
	runtime.flushAnimationFrames();

	const container = runtime.getContainer('billing');
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), true);
	assert.equal(container.classList.contains('akb-address-autocomplete-constrained'), false);
	assert.equal(
		container.style.getPropertyValue('--akb-address-autocomplete-list-max-height'),
		''
	);
});

test('tracks the visual viewport when a mobile keyboard changes the usable area', async () => {
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		viewportHeight: 900,
		visualViewport: { height: 500, offsetTop: 100 }
	});
	const provider = runtime.getProvider();
	const input = runtime.activate('shipping', 'Avenida Irarrázaval 1234');

	await provider.search('Avenida Irarrázaval 1234', 'CL');
	const panel = runtime.appendSuggestions('shipping', {
		input: { bottom: 450, height: 50, top: 400 },
		list: { bottom: 694, height: 240, top: 454 },
		panel: { bottom: 738, height: 284, top: 454 }
	});
	runtime.flushAnimationFrames();

	const container = runtime.getContainer('shipping');
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), true);
	assert.equal(runtime.getVisualViewportListenerCount('resize'), 1);
	assert.equal(runtime.getVisualViewportListenerCount('scroll'), 1);

	runtime.setVisualViewport({ height: 800, offsetTop: 0 });
	input.setRect({ bottom: 150, height: 50, top: 100 });
	panel.setRect({ bottom: 438, height: 284, top: 154 });
	runtime.dispatchVisualViewportEvent('resize');
	runtime.flushAnimationFrames();
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), false);

	runtime.blur('shipping');
	assert.equal(runtime.getVisualViewportListenerCount('resize'), 0);
	assert.equal(runtime.getVisualViewportListenerCount('scroll'), 0);
});

test('constrains only the suggestions list when neither side can fit the full panel', async () => {
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		viewportHeight: 600
	});
	const provider = runtime.getProvider();
	runtime.activate('shipping', 'Avenida Los Leones 1234');

	await provider.search('Avenida Los Leones 1234', 'CL');
	const panel = runtime.appendSuggestions('shipping', {
		branding: { bottom: 774, height: 40, top: 734 },
		input: { bottom: 380, height: 50, top: 330 },
		list: { bottom: 734, height: 360, top: 374 },
		panel: { bottom: 774, height: 400, top: 374 }
	});
	runtime.flushAnimationFrames();

	const container = runtime.getContainer('shipping');
	const branding = panel.querySelector('.woocommerce-address-autocomplete-branding');
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), true);
	assert.equal(container.classList.contains('akb-address-autocomplete-constrained'), true);
	assert.equal(
		container.style.getPropertyValue('--akb-address-autocomplete-list-max-height'),
		'278px'
	);
	assert.equal(
		branding.style.getPropertyValue('--akb-address-autocomplete-list-max-height'),
		'',
		'branding must remain outside the constrained scroll area'
	);
});

test('repositions on resize and releases viewport work on close, reopen and real blur', async () => {
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		viewportHeight: 900
	});
	const provider = runtime.getProvider();
	const input = runtime.activate('shipping', 'Avenida Vitacura 1234');

	await provider.search('Avenida Vitacura 1234', 'CL');
	const firstPanel = runtime.appendSuggestions('shipping', {
		input: { bottom: 750, height: 50, top: 700 },
		list: { bottom: 994, height: 240, top: 754 },
		panel: { bottom: 1038, height: 284, top: 754 }
	});
	runtime.flushAnimationFrames();
	const container = runtime.getContainer('shipping');
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), true);

	runtime.setViewportHeight(1000);
	input.setRect({ bottom: 150, height: 50, top: 100 });
	firstPanel.setRect({ bottom: 438, height: 284, top: 154 });
	runtime.dispatchWindowEvent('resize');
	runtime.flushAnimationFrames();
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), false);
	assert.equal(container.classList.contains('akb-address-autocomplete-constrained'), false);

	input.dispatchEvent(new runtime.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
	assert.equal(runtime.getWindowListenerCount('resize'), 0);
	assert.equal(runtime.getWindowListenerCount('scroll'), 0);
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), false);

	container.removeChild(firstPanel);
	runtime.input('shipping', 'Avenida Vitacura 12345');
	await provider.search('Avenida Vitacura 12345', 'CL');
	runtime.setViewportHeight(900);
	runtime.appendSuggestions('shipping', {
		input: { bottom: 750, height: 50, top: 700 },
		list: { bottom: 994, height: 240, top: 754 },
		panel: { bottom: 1038, height: 284, top: 754 }
	});
	runtime.flushAnimationFrames();
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), true);
	assert.equal(runtime.getWindowListenerCount('resize'), 1);

	runtime.startBlur('shipping');
	runtime.flushTimeouts(0);
	assert.equal(
		container.classList.contains('akb-address-autocomplete-dropup'),
		true,
		'the real blur grace period must preserve the pointer target'
	);
	runtime.flushTimeouts(250);
	assert.equal(runtime.getWindowListenerCount('resize'), 0);
	assert.equal(runtime.getWindowListenerCount('scroll'), 0);
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), false);
	assert.equal(
		container.style.getPropertyValue('--akb-address-autocomplete-list-max-height'),
		''
	);
});

test('removes adaptive positioning for a short query and before suggestion selection', async () => {
	const runtime = bootProvider(undefined, {
		addressTypes: ['billing'],
		viewportHeight: 900
	});
	const provider = runtime.getProvider();
	runtime.activate('billing', 'Avenida Manquehue 1234');
	let predictions = await provider.search('Avenida Manquehue 1234', 'CL');
	let panel = runtime.appendSuggestions('billing', {
		input: { bottom: 750, height: 50, top: 700 },
		list: { bottom: 994, height: 240, top: 754 },
		panel: { bottom: 1038, height: 284, top: 754 }
	});
	runtime.flushAnimationFrames();
	const container = runtime.getContainer('billing');
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), true);

	runtime.input('billing', 'Av');
	await provider.search('Av', 'CL');
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), false);
	assert.equal(runtime.getWindowListenerCount('resize'), 0);

	container.removeChild(panel);
	runtime.input('billing', 'Avenida Manquehue 12345');
	predictions = await provider.search('Avenida Manquehue 12345', 'CL');
	panel = runtime.appendSuggestions('billing', {
		input: { bottom: 750, height: 50, top: 700 },
		list: { bottom: 994, height: 240, top: 754 },
		panel: { bottom: 1038, height: 284, top: 754 }
	});
	runtime.flushAnimationFrames();
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), true);

	const selection = provider.select(predictions[0].id);
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), false);
	assert.equal(runtime.getWindowListenerCount('resize'), 0);
	assert.equal(runtime.getWindowListenerCount('scroll'), 0);
	await selection;
	assert.equal(panel.parentNode, container, 'Woo owns panel removal; provider only resets its styles');
});

test('keeps a dropup stationary between WebKit pointer blur and suggestion click', async () => {
	const fields = deferred();
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		fieldsResponder: () => fields.promise,
		viewportHeight: 844
	});
	const provider = runtime.getProvider();
	const input = runtime.activate('shipping', 'Avenida Apoquindo 4501');
	const predictions = await provider.search('Avenida Apoquindo 4501', 'CL');
	runtime.appendSuggestions('shipping', {
		input: { bottom: 720, height: 50, top: 670 },
		list: { bottom: 964, height: 240, top: 724 },
		panel: { bottom: 1008, height: 284, top: 724 }
	});
	runtime.flushAnimationFrames();
	const container = runtime.getContainer('shipping');
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), true);

	// WebKit may run zero-delay timers after pointerdown/blur but before click.
	runtime.startBlur('shipping');
	runtime.flushTimeouts(0);
	assert.equal(input.getAttribute('aria-busy'), null);
	assert.equal(
		container.classList.contains('akb-address-autocomplete-dropup'),
		true,
		'the pointer target must not jump before WebKit dispatches click'
	);

	const selection = provider.select(predictions[0].id);
	assert.equal(runtime.fieldRequests.length, 1, 'the stable click must invoke Place Details once');
	fields.resolve();
	await selection;
	runtime.flushTimeouts();
	assert.equal(runtime.getWindowListenerCount('resize'), 0);
	assert.equal(runtime.getWindowListenerCount('scroll'), 0);
});

test('returns no suggestions when a delayed search settles after real blur', async () => {
	const requests = new Map();
	const runtime = bootProvider(undefined, {
		addressTypes: ['billing'],
		suggestionsResponder(request, response) {
			const pending = deferred();
			requests.set(request.input, { ...pending, response });
			return pending.promise;
		}
	});
	const provider = runtime.getProvider();
	runtime.activate('billing', 'Avenida Tobalaba 1234');
	const search = provider.search('Avenida Tobalaba 1234', 'CL');
	const pending = await waitForRequest(requests, 'Avenida Tobalaba 1234');

	runtime.startBlur('billing');
	runtime.flushTimeouts(0);
	pending.resolve(pending.response);

	assert.deepEqual(JSON.parse(JSON.stringify(await search)), []);
	runtime.flushTimeouts();
	assert.equal(runtime.getFeedback('billing').hidden, true);
	assert.equal(runtime.getWindowListenerCount('resize'), 0);
});

test('clears a recoverable inline search error on real blur', async () => {
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		suggestionsError: true
	});
	const provider = runtime.getProvider();
	const input = runtime.activate('shipping', 'Dirección sin conexión');

	await provider.search('Dirección sin conexión', 'CL');
	const feedback = runtime.getFeedback('shipping');
	assert.equal(feedback.hidden, false);
	assert.equal(feedback.getAttribute('role'), 'alert');

	runtime.startBlur('shipping');
	runtime.flushTimeouts(0);
	assert.equal(feedback.hidden, true);
	assert.equal(input.getAttribute('aria-describedby'), 'shipping-validation');
	runtime.flushTimeouts();
});

test('renders a visible no-results live status without replacing manual entry', async () => {
	const runtime = bootProvider(undefined, {
		addressTypes: ['billing'],
		suggestionsResponder: () => ({ suggestions: [] })
	});
	const provider = runtime.getProvider();
	const input = runtime.activate('billing', 'Dirección inventada 123');

	assert.deepEqual(
		JSON.parse(JSON.stringify(await provider.search('Dirección inventada 123', 'CL'))),
		[]
	);
	const feedback = runtime.getFeedback('billing');

	assert.equal(feedback.hidden, false);
	assert.equal(feedback.getAttribute('role'), 'status');
	assert.equal(feedback.getAttribute('aria-live'), 'polite');
	assert.match(feedback.textContent, /escribirla manualmente/);
	assert.match(input.getAttribute('aria-describedby'), /akb-address-autocomplete-feedback/);
});

test('shows an inline recoverable error and clears it on the next input', async () => {
	let requestCount = 0;
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		suggestionsResponder(request, response) {
			requestCount += 1;
			if (requestCount === 1) {
				throw new Error('offline');
			}
			return response;
		}
	});
	const provider = runtime.getProvider();
	const input = runtime.activate('shipping', 'Providencia 123');

	await provider.search('Providencia 123', 'CL');
	const feedback = runtime.getFeedback('shipping');

	assert.equal(feedback.hidden, false);
	assert.equal(feedback.getAttribute('role'), 'alert');
	assert.match(feedback.textContent, /escribir la dirección manualmente/);
	assert.equal(runtime.notices.length, 0, 'the inline alert must not be announced twice');

	runtime.input('shipping', 'Providencia 1234');
	assert.equal(feedback.hidden, true);
	assert.equal(input.getAttribute('aria-busy'), null);
	assert.equal(input.getAttribute('aria-describedby'), 'shipping-validation');

	const predictions = await provider.search('Providencia 1234', 'CL');
	assert.equal(predictions.length, 1);
	assert.equal(feedback.hidden, true);
});

test('keeps the latest query feedback when an older request settles later', async () => {
	const requests = new Map();
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		suggestionsResponder(request, response) {
			const pending = deferred();
			requests.set(request.input, { ...pending, response });
			return pending.promise;
		}
	});
	const provider = runtime.getProvider();

	runtime.activate('shipping', 'Avenida A 123');
	const first = provider.search('Avenida A 123', 'CL');
	await Promise.resolve();
	runtime.input('shipping', 'Avenida B 456');
	const second = provider.search('Avenida B 456', 'CL');
	const secondRequest = await waitForRequest(requests, 'Avenida B 456');

	secondRequest.resolve({ suggestions: [] });
	await second;
	const feedback = runtime.getFeedback('shipping');
	assert.match(feedback.textContent, /No encontramos direcciones/);

	const firstRequest = await waitForRequest(requests, 'Avenida A 123');
	firstRequest.resolve(firstRequest.response);
	await first;
	assert.equal(feedback.hidden, false);
	assert.match(feedback.textContent, /No encontramos direcciones/);
});

test('does not announce a stale failure after a newer query succeeds', async () => {
	const requests = new Map();
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		suggestionsResponder(request, response) {
			const pending = deferred();
			requests.set(request.input, { ...pending, response });
			return pending.promise;
		}
	});
	const provider = runtime.getProvider();

	runtime.activate('shipping', 'Avenida antigua 123');
	const staleSearch = provider.search('Avenida antigua 123', 'CL');
	const staleRequest = await waitForRequest(requests, 'Avenida antigua 123');
	runtime.input('shipping', 'Avenida vigente 456');
	const currentSearch = provider.search('Avenida vigente 456', 'CL');
	const currentRequest = await waitForRequest(requests, 'Avenida vigente 456');

	currentRequest.resolve(currentRequest.response);
	await currentSearch;
	staleRequest.reject(new Error('stale offline response'));
	await staleSearch;

	assert.equal(runtime.notices.length, 0);
	assert.equal(runtime.getFeedback('shipping').hidden, true);
});

// Un fallo que ya no es el vigente no cierra la sesión de Google: si lo hiciera,
// borraría las predicciones de la lista que el cliente tiene en pantalla y
// tocar una no completaría nada. Lo impide runSearch(), que descarta el fallo
// viejo antes de entregarlo (deliverSearch() ya no lo vuelve a mirar).
test('a stale failure that lands after a newer answer keeps the session: the list on screen still completes the address', async () => {
	const requests = new Map();
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		suggestionsResponder(request, response) {
			const pending = deferred();
			requests.set(request.input, { ...pending, response });
			return pending.promise;
		}
	});
	const provider = runtime.getProvider();

	runtime.activate('shipping', 'Avenida antigua 123');
	const staleSearch = provider.search('Avenida antigua 123', 'CL');
	const staleRequest = await waitForRequest(requests, 'Avenida antigua 123');
	runtime.input('shipping', 'Avenida vigente 456');
	const currentSearch = provider.search('Avenida vigente 456', 'CL');
	const currentRequest = await waitForRequest(requests, 'Avenida vigente 456');

	currentRequest.resolve(currentRequest.response);
	const current = await currentSearch;
	assert.equal(current.length, 1);
	staleRequest.reject(new Error('stale offline response'));
	assert.deepEqual(JSON.parse(JSON.stringify(await staleSearch)), []);

	const selected = await provider.select(current[0].id);
	assert.equal(selected.address_1, 'Providencia 123', 'la sugerencia en pantalla sigue completando la dirección');
	assert.deepEqual(JSON.parse(JSON.stringify(runtime.fieldRequests)), [{ fields: ['addressComponents'] }]);
});

test('isolates concurrent billing and shipping feedback by combobox', async () => {
	const requests = new Map();
	const runtime = bootProvider(undefined, {
		addressTypes: ['billing', 'shipping'],
		suggestionsResponder(request, response) {
			const pending = deferred();
			requests.set(request.input, { ...pending, response });
			return pending.promise;
		}
	});
	const provider = runtime.getProvider();

	runtime.activate('shipping', 'Avenida Providencia 1234');
	const shippingSearch = provider.search('Avenida Providencia 1234', 'CL');
	await Promise.resolve();
	runtime.activate('billing', 'Dirección sin coincidencias');
	const billingSearch = provider.search('Dirección sin coincidencias', 'CL');
	const billingRequest = await waitForRequest(requests, 'Dirección sin coincidencias');

	billingRequest.resolve({ suggestions: [] });
	await billingSearch;
	assert.match(runtime.getFeedback('billing').textContent, /No encontramos direcciones/);
	assert.equal(runtime.getInput('shipping').getAttribute('aria-busy'), 'true');

	const shippingRequest = await waitForRequest(requests, 'Avenida Providencia 1234');
	shippingRequest.resolve(shippingRequest.response);
	await shippingSearch;
	assert.equal(runtime.getFeedback('shipping').hidden, true);
	assert.equal(runtime.getFeedback('billing').hidden, false);
});

test('clears feedback for short queries and blur while preserving manual input', async () => {
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		suggestionsResponder: () => ({ suggestions: [] })
	});
	const provider = runtime.getProvider();

	runtime.activate('shipping', 'Sin resultados');
	await provider.search('Sin resultados', 'CL');
	assert.equal(runtime.getFeedback('shipping').hidden, false);

	runtime.input('shipping', 'Si');
	await provider.search('Si', 'CL');
	assert.equal(runtime.getFeedback('shipping').hidden, true);

	runtime.input('shipping', 'Otra dirección');
	await provider.search('Otra dirección', 'CL');
	runtime.blur('shipping');
	assert.equal(runtime.getFeedback('shipping').hidden, true);
});

test('reattaches visible feedback if a Woo React render removes the companion node', async () => {
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		suggestionsResponder: () => ({ suggestions: [] })
	});
	const provider = runtime.getProvider();

	runtime.activate('shipping', 'Dirección sin resultados');
	await provider.search('Dirección sin resultados', 'CL');
	const container = runtime.getContainer('shipping');
	const feedback = runtime.getFeedback('shipping');
	const suggestions = runtime.appendSuggestions('shipping');

	container.removeChild(feedback);
	await Promise.resolve();

	assert.equal(feedback.parentNode, container);
	assert.ok(
		[...container.children].indexOf(feedback) < [...container.children].indexOf(suggestions),
		'the live feedback must remain above Woo\'s absolutely positioned dropdown'
	);
	assert.equal(feedback.hidden, false);
	assert.match(feedback.textContent, /No encontramos direcciones/);
});

test('keeps search failure safe when checkout DOM is absent', async () => {
	const runtime = bootProvider(undefined, { suggestionsError: true });
	const provider = runtime.getProvider();

	assert.deepEqual(
		JSON.parse(JSON.stringify(await provider.search('Providencia 123', 'CL'))),
		[]
	);
	assert.equal(runtime.notices.length, 1);
	assert.match(runtime.notices[0].message, /manualmente/);
});

test('announces selection progress and clears it after the address is completed', async () => {
	const fields = deferred();
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		fieldsResponder: () => fields.promise
	});
	const provider = runtime.getProvider();
	const input = runtime.activate('shipping', 'Avenida Providencia 1234');
	const predictions = await provider.search('Avenida Providencia 1234', 'CL');

	const selection = provider.select(predictions[0].id);
	const feedback = runtime.getFeedback('shipping');
	assert.equal(input.getAttribute('aria-busy'), 'true');
	assert.match(feedback.textContent, /Completando dirección/);

	fields.resolve();
	await selection;
	assert.equal(input.getAttribute('aria-busy'), null);
	assert.equal(feedback.hidden, true);
});

test('uses one inline alert for a selection failure and keeps manual recovery available', async () => {
	const runtime = bootProvider(undefined, {
		addressTypes: ['billing'],
		fetchFieldsError: true
	});
	const provider = runtime.getProvider();
	runtime.activate('billing', 'Avenida Providencia 1234');
	const predictions = await provider.search('Avenida Providencia 1234', 'CL');

	assert.deepEqual(
		JSON.parse(JSON.stringify(await provider.select(predictions[0].id))),
		{}
	);
	const feedback = runtime.getFeedback('billing');
	assert.equal(feedback.getAttribute('role'), 'alert');
	assert.match(feedback.textContent, /escribir la dirección manualmente/);
	assert.equal(runtime.notices.length, 0, 'the inline alert must not be duplicated by a snackbar');
});

test('uses safe transient DOM feedback without HTML sinks or browser persistence', async () => {
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		suggestionsResponder: () => ({ suggestions: [] }),
		trackSecuritySinks: true
	});
	const provider = runtime.getProvider();
	runtime.activate('shipping', '<img src=x onerror=alert(1)>');
	await provider.search('<img src=x onerror=alert(1)>', 'CL');

	const feedback = runtime.getFeedback('shipping');
	assert.equal(feedback.hidden, false);
	assert.equal(feedback.children.length, 0, 'feedback stays plain text without child HTML nodes');
	assert.ok(feedback.textContent.length > 0);
	assert.deepEqual(runtime.htmlSinkCalls, [], 'feedback must not assign innerHTML or insertAdjacentHTML');
	assert.deepEqual(runtime.storageAccesses, [], 'autocomplete must not persist PII in Web Storage');
});

// FB-01: deliverSearch() debe comprobar vigencia tras levantar el dedo y antes de
// finishSearchFeedback(): si sigue vigente entrega resultados y limpia aria-busy;
// si el usuario canceló la lista mientras sostenía el dedo, responde [] sin
// reabrirla ni ensuciar una búsqueda posterior.
test('deliverSearch checks currency after pointer release and before finishSearchFeedback on real DOM', async () => {
	{
		const { requests, runtime } = listOpenFor();
		const { provider } = await openListFor(runtime, requests, 'Avenida Pro');

		runtime.input('billing', 'Avenida Prov');
		const currentSearch = provider.search('Avenida Prov', 'CL');
		const answer = await waitForRequest(requests, 'Avenida Prov');
		runtime.touchSuggestion('billing');
		answer.resolve(answer.response);

		assert.equal(await settlesNow(currentSearch), false, 'held while finger stays on suggestion');
		runtime.dispatchDocumentEvent('pointercancel');
		runtime.flushTimeouts(0);

		const results = await currentSearch;
		assert.deepEqual(results.map((suggestion) => suggestion.id), ['Avenida Prov-id']);
		assert.equal(runtime.getInput('billing').getAttribute('aria-busy'), null);
		assert.equal(runtime.getFeedback('billing').hidden, true);
	}
	{
		const { requests, runtime } = listOpenFor();
		const { provider } = await openListFor(runtime, requests, 'Avenida Pro');
		const input = runtime.getInput('billing');

		runtime.input('billing', 'Avenida Prov');
		const staleSearch = provider.search('Avenida Prov', 'CL');
		const answer = await waitForRequest(requests, 'Avenida Prov');
		runtime.touchSuggestion('billing');
		answer.resolve(answer.response);
		assert.equal(await settlesNow(staleSearch), false);

		input.dispatchEvent(new runtime.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
		runtime.dispatchDocumentEvent('pointercancel');
		runtime.flushTimeouts(0);

		assert.deepEqual(JSON.parse(JSON.stringify(await staleSearch)), []);
		assert.equal(input.getAttribute('aria-busy'), null);
	}
});

test('waits for importLibrary after the async Google loader load event', async () => {
	const runtime = bootProvider(undefined, { googleImportAfterLoaderOnload: true });
	const provider = runtime.getProvider();

	const predictions = await provider.search('Avenida Providencia 1234', 'CL');

	assert.equal(runtime.getLoaderAppendCount(), 1);
	assert.equal(predictions.length, 1);
	assert.equal(runtime.autocompleteRequests.length, 1);
	assert.equal(runtime.notices.length, 0);
});

test('retries after a rejected Google library import instead of caching the failure', async () => {
	const runtime = bootProvider(undefined, { importLibraryFailures: 1 });
	const provider = runtime.getProvider();

	assert.deepEqual(
		JSON.parse(JSON.stringify(await provider.search('Avenida Providencia 1234', 'CL'))),
		[]
	);
	const predictions = await provider.search('Avenida Providencia 1234', 'CL');

	assert.equal(runtime.getImportLibraryCallCount(), 2);
	assert.equal(predictions.length, 1);
	assert.equal(runtime.autocompleteRequests.length, 1);
});

test('uses the Google FormattableText contract for labels and exposes valid match metadata', async () => {
	const runtime = bootProvider(undefined, {
		predictionLabel: 'Avenida Providencia 1234, Providencia, Santiago'
	});
	const provider = runtime.getProvider();

	const predictions = await provider.search('Avenida Providencia 1234', 'CL');

	assert.equal(predictions[0].label, 'Avenida Providencia 1234, Providencia, Santiago');
	assert.deepEqual(JSON.parse(JSON.stringify(predictions[0].matchedSubstrings)), []);
});

test('falls back safely when Google exposes the legacy nested text shape', async () => {
	const runtime = bootProvider(undefined, {
		predictionText: { text: 'Providencia 123, Santiago' }
	});
	const provider = runtime.getProvider();

	const predictions = await provider.search('Providencia 123', 'CL');

	assert.equal(predictions[0].label, 'Providencia 123, Santiago');
});

test('reuses one Google Places session token until an address is selected', async () => {
	const runtime = bootProvider();
	const provider = runtime.getProvider();

	assert.ok(provider);
	const first = await provider.search('Providencia 1', 'CL');
	const second = await provider.search('Providencia 12', 'CL');

	assert.equal(runtime.getTokenCount(), 1);
	assert.equal(runtime.autocompleteRequests.length, 2);
	assert.equal(
		runtime.autocompleteRequests[0].sessionToken,
		runtime.autocompleteRequests[1].sessionToken
	);

	const selected = await provider.select(first[0].id);

	assert.deepEqual(
		JSON.parse(JSON.stringify(selected)),
		{
			address_1: 'Providencia 123',
			city: 'Santiago',
			country: 'CL',
			state: 'RM'
		},
		'el depto y el código postal NO viajan vacíos: los escribe el cliente y borrárselos al elegir una sugerencia era el hallazgo S-99d433c6'
	);
	assert.deepEqual(
		JSON.parse(JSON.stringify(runtime.fieldRequests)),
		[{ fields: ['addressComponents'] }]
	);
	assert.deepEqual(
		JSON.parse(JSON.stringify(await provider.select(second[0].id))),
		{},
		'selection clears stale predictions without returning an invalid null address'
	);

	await provider.search('Nueva Providencia 1', 'CL');
	assert.equal(runtime.getTokenCount(), 2, 'the next search starts a fresh billing session');
	assert.notEqual(
		runtime.autocompleteRequests[1].sessionToken,
		runtime.autocompleteRequests[2].sessionToken
	);
});

test('starts a fresh session token after an abandoned autocomplete session expires', async () => {
	const runtime = bootProvider();
	const provider = runtime.getProvider();

	await provider.search('Providencia 1', 'CL');
	runtime.advanceTime(3 * 60 * 1000);
	await provider.search('Providencia 12', 'CL');

	assert.equal(runtime.getTokenCount(), 2);
	assert.notEqual(
		runtime.autocompleteRequests[0].sessionToken,
		runtime.autocompleteRequests[1].sessionToken
	);
});

test('a failed search closes the Google session: the next search opens a new token, with or without the field on screen', async () => {
	for (const addressTypes of [['shipping'], undefined]) {
		const where = addressTypes ? 'con el campo en pantalla' : 'sin el DOM del checkout';
		let requestCount = 0;
		const runtime = bootProvider(undefined, {
			addressTypes,
			suggestionsResponder(request, response) {
				requestCount += 1;
				if (requestCount === 2) {
					throw new Error('offline');
				}
				return response;
			}
		});
		const provider = runtime.getProvider();
		const type = (value) => {
			if (addressTypes) {
				runtime.input('shipping', value);
			}
			return provider.search(value, 'CL');
		};

		assert.equal((await type('Providencia 1')).length, 1, where);
		assert.deepEqual(JSON.parse(JSON.stringify(await type('Providencia 12'))), [], where);
		assert.equal((await type('Providencia 123')).length, 1, where);

		const [first, failed, next] = runtime.autocompleteRequests;
		assert.equal(first.sessionToken, failed.sessionToken, where + ': el fallo iba en la misma sesión');
		assert.notEqual(failed.sessionToken, next.sessionToken, where + ': tras el fallo, sesión nueva');
		assert.equal(runtime.getTokenCount(), 2, where);
		assert.equal(runtime.notices.length, addressTypes ? 0 : 1, where + ': un solo aviso, en línea o en el snackbar');
	}
});

test('contains Place Details failures and leaves manual checkout recovery available', async () => {
	const runtime = bootProvider(undefined, { fetchFieldsError: true });
	const provider = runtime.getProvider();
	const predictions = await provider.search('Providencia 1', 'CL');

	const selected = await provider.select(predictions[0].id);

	assert.deepEqual(JSON.parse(JSON.stringify(selected)), {});
	assert.equal(runtime.notices.length, 1);
	assert.match(runtime.notices[0].message, /escribirla manualmente/);
	assert.equal(runtime.notices[0].settings.context, 'woocommerce/checkout');
});

test('announces search failures once per failure streak and preserves manual entry', async () => {
	const runtime = bootProvider(undefined, { suggestionsError: true });
	const provider = runtime.getProvider();

	assert.deepEqual(JSON.parse(JSON.stringify(await provider.search('Providencia 1', 'CL'))), []);
	assert.deepEqual(JSON.parse(JSON.stringify(await provider.search('Providencia 12', 'CL'))), []);
	assert.equal(runtime.notices.length, 1);
	assert.match(runtime.notices[0].message, /escribirla manualmente/);
	assert.equal(runtime.notices[0].settings.id, 'akibara-google-places-search');
	assert.equal(runtime.notices[0].settings.context, 'woocommerce/checkout');
});

test('chooses the single canonical commune independent of component iteration order', async () => {
	const runtime = bootProvider([
		{ types: ['administrative_area_level_2'], longText: 'Provincia primero' },
		{ types: ['locality'], longText: 'Ciudad no catalogada' },
		{ types: ['administrative_area_level_3'], longText: 'Providencia' },
		{ types: ['route'], longText: 'Los Leones' },
		{ types: ['street_number'], longText: '100' },
		{ types: ['administrative_area_level_1'], shortText: 'RM' },
		{ types: ['country'], shortText: 'CL' }
	]);
	const provider = runtime.getProvider();
	const predictions = await provider.search('Los Leones 100', 'CL');
	const selected = await provider.select(predictions[0].id);

	assert.equal(selected.city, 'Providencia');
	assert.equal(selected.address_1, 'Los Leones 100');
});

test('resolves Providencia when Google repeats Santiago as admin levels 3 and 2', async () => {
	const runtime = bootProvider([
		{ types: ['street_number'], shortText: '1234', longText: '1234' },
		{ types: ['route'], shortText: 'Av. Providencia', longText: 'Avenida Providencia' },
		{ types: ['locality', 'political'], shortText: 'Providencia', longText: 'Providencia' },
		{ types: ['administrative_area_level_3', 'political'], shortText: 'Santiago', longText: 'Santiago' },
		{ types: ['administrative_area_level_2', 'political'], shortText: 'Santiago', longText: 'Santiago' },
		{
			types: ['administrative_area_level_1', 'political'],
			shortText: 'Región Metropolitana',
			longText: 'Región Metropolitana'
		},
		{ types: ['country', 'political'], shortText: 'CL', longText: 'Chile' },
		{ types: ['postal_code'], shortText: '7500571', longText: '7500571' }
	], {
		communeRegions: { 'CL-RM': ['Las Condes', 'Providencia', 'Santiago'] },
		regionMap: { RM: 'CL-RM' }
	});
	const provider = runtime.getProvider();
	const predictions = await provider.search('Avenida Providencia 1234, Providencia', 'CL');
	const selected = await provider.select(predictions[0].id);

	assert.equal(selected.city, 'Providencia');
	assert.equal(selected.state, 'CL-RM');
});

test('resolves Las Condes when Google repeats Santiago as locality and admin level 2', async () => {
	const runtime = bootProvider([
		{ types: ['street_number'], shortText: '4501', longText: '4501' },
		{ types: ['route'], shortText: 'Av. Apoquindo', longText: 'Avenida Apoquindo' },
		{ types: ['locality', 'political'], shortText: 'Santiago', longText: 'Santiago' },
		{ types: ['administrative_area_level_3', 'political'], shortText: 'Las Condes', longText: 'Las Condes' },
		{ types: ['administrative_area_level_2', 'political'], shortText: 'Santiago', longText: 'Santiago' },
		{
			types: ['administrative_area_level_1', 'political'],
			shortText: 'Región Metropolitana',
			longText: 'Región Metropolitana'
		},
		{ types: ['country', 'political'], shortText: 'CL', longText: 'Chile' },
		{ types: ['postal_code'], shortText: '7580125', longText: '7580125' }
	], {
		communeRegions: { 'CL-RM': ['Las Condes', 'Providencia', 'Santiago'] },
		regionMap: { RM: 'CL-RM' }
	});
	const provider = runtime.getProvider();
	const predictions = await provider.search('Avenida Apoquindo 4501, Las Condes', 'CL');
	const selected = await provider.select(predictions[0].id);

	assert.equal(selected.city, 'Las Condes');
	assert.equal(selected.state, 'CL-RM');
});

test('leaves conflicting canonical commune candidates empty for manual selection', async () => {
	const runtime = bootProvider([
		{ types: ['route'], longText: 'Avenida Ambigua' },
		{ types: ['street_number'], longText: '100' },
		{ types: ['locality', 'political'], longText: 'Las Condes' },
		{ types: ['administrative_area_level_3', 'political'], longText: 'Providencia' },
		{ types: ['administrative_area_level_2', 'political'], longText: 'Santiago' },
		{ types: ['administrative_area_level_1', 'political'], shortText: 'RM' },
		{ types: ['country', 'political'], shortText: 'CL' }
	], {
		communeRegions: { 'CL-RM': ['Las Condes', 'Providencia', 'Santiago'] },
		regionMap: { RM: 'CL-RM' }
	});
	const provider = runtime.getProvider();
	const predictions = await provider.search('Avenida Ambigua 100', 'CL');
	const selected = await provider.select(predictions[0].id);

	assert.equal(selected.city, '');
});

test('deduplicates Santiago across locality and administrative levels', async () => {
	const runtime = bootProvider([
		{ types: ['route'], longText: 'Calle Compañía' },
		{ types: ['street_number'], longText: '1000' },
		{ types: ['locality', 'political'], longText: 'Santiago' },
		{ types: ['administrative_area_level_3', 'political'], longText: 'Santiago' },
		{ types: ['administrative_area_level_2', 'political'], longText: 'Santiago' },
		{ types: ['administrative_area_level_1', 'political'], shortText: 'RM' },
		{ types: ['country', 'political'], shortText: 'CL' }
	], {
		communeRegions: { 'CL-RM': ['Santiago'] },
		regionMap: { RM: 'CL-RM' }
	});
	const provider = runtime.getProvider();
	const predictions = await provider.search('Compañía 1000, Santiago', 'CL');
	const selected = await provider.select(predictions[0].id);

	assert.equal(selected.city, 'Santiago');
});

test('leaves an unknown Google commune empty for manual checkout selection', async () => {
	const runtime = bootProvider([
		{ types: ['route'], longText: 'Ruta desconocida' },
		{ types: ['locality', 'political'], longText: 'Villa Inventada' },
		{ types: ['administrative_area_level_3', 'political'], longText: 'Provincia Inventada' },
		{ types: ['administrative_area_level_2', 'political'], longText: 'Provincia Inventada' },
		{ types: ['administrative_area_level_1', 'political'], shortText: 'RM' },
		{ types: ['country', 'political'], shortText: 'CL' }
	], {
		communeRegions: { 'CL-RM': ['Las Condes', 'Providencia', 'Santiago'] },
		regionMap: { RM: 'CL-RM' }
	});
	const provider = runtime.getProvider();
	const predictions = await provider.search('Ruta desconocida', 'CL');
	const selected = await provider.select(predictions[0].id);

	assert.equal(selected.city, '');
});

test('leaves commune empty when the canonical catalog is unavailable', async () => {
	const runtime = bootProvider([
		{ types: ['route'], longText: 'Avenida Providencia' },
		{ types: ['street_number'], longText: '1234' },
		{ types: ['locality', 'political'], longText: 'Providencia' },
		{ types: ['administrative_area_level_3', 'political'], longText: 'Santiago' },
		{ types: ['administrative_area_level_2', 'political'], longText: 'Santiago' },
		{ types: ['administrative_area_level_1', 'political'], shortText: 'RM' },
		{ types: ['country', 'political'], shortText: 'CL' }
	], {
		communeRegions: null,
		regionMap: { RM: 'CL-RM' }
	});
	const provider = runtime.getProvider();
	const predictions = await provider.search('Avenida Providencia 1234', 'CL');
	const selected = await provider.select(predictions[0].id);

	assert.equal(selected.city, '');
});

test('returns the canonical accented commune name from the injected catalog', async () => {
	const runtime = bootProvider([
		{ types: ['route'], longText: 'Avenida Irarrázaval' },
		{ types: ['street_number'], longText: '3000' },
		{ types: ['locality', 'political'], longText: 'Nunoa' },
		{ types: ['administrative_area_level_3', 'political'], longText: 'Santiago' },
		{ types: ['administrative_area_level_2', 'political'], longText: 'Santiago' },
		{ types: ['administrative_area_level_1', 'political'], shortText: 'RM' },
		{ types: ['country', 'political'], shortText: 'CL' }
	], {
		communeRegions: { 'CL-RM': ['Ñuñoa', 'Santiago'] },
		regionMap: { RM: 'CL-RM' }
	});
	const provider = runtime.getProvider();
	const predictions = await provider.search('Avenida Irarrázaval 3000, Ñuñoa', 'CL');
	const selected = await provider.select(predictions[0].id);

	assert.equal(selected.city, 'Ñuñoa');
});

test('normalizes all Chilean Google region names through the configured WC region map', async () => {
	const cases = [
		['Región de Arica y Parinacota', 'AP', 'CL-AP'],
		['Región de Tarapacá', 'TA', 'CL-TA'],
		['Región de Antofagasta', 'AN', 'CL-AN'],
		['Región de Atacama', 'AT', 'CL-AT'],
		['Región de Coquimbo', 'CO', 'CL-CO'],
		['Región de Valparaíso', 'VS', 'CL-VS'],
		['Región Metropolitana', 'RM', 'CL-RM'],
		["Región del Libertador General Bernardo O'Higgins", 'LI', 'CL-LI'],
		['Región del Maule', 'ML', 'CL-ML'],
		['Región de Ñuble', 'NB', 'CL-NB'],
		['Región del Biobío', 'BI', 'CL-BI'],
		['Región de La Araucanía', 'AR', 'CL-AR'],
		['Región de Los Ríos', 'LR', 'CL-LR'],
		['Región de Los Lagos', 'LL', 'CL-LL'],
		['Región de Aysén del General Carlos Ibáñez del Campo', 'AI', 'CL-AI'],
		['Región de Magallanes y de la Antártica Chilena', 'MA', 'CL-MA']
	];
	const regionMap = Object.fromEntries(cases.map(([, alias, wcCode]) => [alias, wcCode]));

	for (const [googleName, , wcCode] of cases) {
		const runtime = bootProvider([
			{ types: ['route'], longText: 'Providencia' },
			{ types: ['street_number'], longText: '1234' },
			{
				types: ['administrative_area_level_1'],
				shortText: googleName,
				longText: googleName
			},
			{ types: ['country'], shortText: 'CL' }
		], { regionMap });
		const provider = runtime.getProvider();
		const predictions = await provider.search('Avenida Providencia 1234', 'CL');
		const selected = await provider.select(predictions[0].id);

		assert.equal(selected.state, wcCode, googleName);
	}
});

test('leaves an unknown Google region empty for manual checkout selection', async () => {
	const runtime = bootProvider([
		{ types: ['route'], longText: 'Ruta desconocida' },
		{
			types: ['administrative_area_level_1'],
			shortText: 'Región inventada',
			longText: 'Región inventada'
		},
		{ types: ['country'], shortText: 'CL' }
	], { regionMap: { RM: 'CL-RM' } });
	const provider = runtime.getProvider();
	const predictions = await provider.search('Ruta desconocida', 'CL');
	const selected = await provider.select(predictions[0].id);

	assert.equal(selected.state, '');
});


test('el depto y el código postal escritos por el cliente sobreviven a elegir una sugerencia', async () => {
	// Hallazgo S-99d433c6. Google entrega `subpremise` sólo en direcciones muy
	// completas y en Chile casi nunca manda código postal: si el proveedor los
	// devuelve vacíos, WooCommerce los escribe encima de lo que el cliente ya
	// había tecleado.
	const runtime = bootProvider();
	const provider = runtime.getProvider();

	const predictions = await provider.search('Providencia 123', 'CL');
	const selected = await provider.select(predictions[0].id);

	assert.equal('address_2' in selected, false, 'un campo que Google no conoce no puede viajar como cadena vacía');
	assert.equal('postcode' in selected, false, 'lo mismo para el código postal');
	assert.equal(selected.address_1, 'Providencia 123');
});

// INP de #billing-address_1 (el peor del sitio en el RUM de Cloudflare: 1.120 ms).
// WooCommerce 11 llama a search() en cada tecla; medido en el espejo, 24 teclas
// eran 22 peticiones a Google y cada tecla pintaba y borraba el aviso, recolocaba
// la lista y mantenía un MutationObserver sobre todo el documento.

test('coalesces a burst of keystrokes: the first search of the page at once, then one after 250 ms of quiet', async () => {
	const runtime = bootProvider(undefined, { addressTypes: ['billing'] });
	const provider = runtime.getRawProvider();
	const typed = ['Ave', 'Aven', 'Aveni', 'Avenid', 'Avenida'];
	const searches = [];
	const pendingDelays = [];
	for (const value of typed) {
		runtime.input('billing', value);
		searches.push(provider.search(value, 'CL'));
		pendingDelays.push(runtime.getPendingTimeouts().map((timeout) => timeout.delay));
		// Entre dos teclas pasan ~100-200 ms: corre lo que vence en 0 ms, no el debounce.
		runtime.flushTimeouts(0);
		await Promise.resolve();
	}

	assert.deepEqual(
		pendingDelays,
		[[0], [250], [250], [250], [250]],
		'the first search of the page warms Google at once; every later keystroke only keeps one 250 ms timer'
	);
	assert.deepEqual(
		runtime.autocompleteRequests.map((request) => request.input),
		['Ave'],
		'while typing, only the warm-up search reached Google'
	);

	runtime.flushTimeouts(250);
	const results = await Promise.all(searches);

	assert.deepEqual(runtime.autocompleteRequests.map((request) => request.input), ['Ave', 'Avenida']);
	assert.deepEqual(
		results.map((result) => result.length),
		[0, 0, 0, 0, 1],
		'the warm-up answer is stale by then and Woo never sees it'
	);
});

test('a keystroke writes nothing to the DOM and schedules no layout read', async () => {
	const runtime = bootProvider(undefined, { addressTypes: ['shipping'], viewportHeight: 900 });
	const provider = runtime.getRawProvider();
	const input = runtime.input('shipping', 'Avenida Providencia');

	const search = provider.search('Avenida Providencia', 'CL');

	assert.equal(runtime.getFeedback('shipping'), undefined, 'no live region is created on the keystroke');
	assert.equal(input.getAttribute('aria-busy'), null);
	assert.equal(runtime.getAnimationFrameCount(), 0, 'no layout read is scheduled on the keystroke');
	assert.equal(runtime.getWindowListenerCount('scroll'), 0);
	assert.equal(runtime.getMutationObservers().length, 0);

	runtime.flushTimeouts(250);
	assert.equal(input.getAttribute('aria-busy'), 'true', 'the loading status starts with the real request');
	assert.match(runtime.getFeedback('shipping').textContent, /Buscando direcciones/);
	assert.equal((await search).length, 1);
});

test('drops a pending search when the query falls below three characters or the field loses focus', async () => {
	const runtime = bootProvider(undefined, { addressTypes: ['billing'] });
	const provider = runtime.getRawProvider();

	runtime.input('billing', 'Ave');
	const importsAfterInitialFocus = runtime.getImportLibraryCallCount();
	assert.equal(importsAfterInitialFocus, 1, 'real DOM focusin warms the SDK once on entering the field');
	const shortened = provider.search('Ave', 'CL');
	// Con menos de tres caracteres Woo ya no llama a search(): sólo cambia el valor.
	runtime.input('billing', 'Av');
	runtime.flushTimeouts(250);
	assert.deepEqual(JSON.parse(JSON.stringify(await shortened)), []);

	runtime.input('billing', 'Avenida Matta');
	const abandoned = provider.search('Avenida Matta', 'CL');
	runtime.startBlur('billing');
	runtime.flushTimeouts(250);
	assert.deepEqual(JSON.parse(JSON.stringify(await abandoned)), []);

	assert.equal(runtime.autocompleteRequests.length, 0);
	assert.equal(runtime.getImportLibraryCallCount(), importsAfterInitialFocus, 'dropped searches trigger no extra SDK calls');
	assert.equal(runtime.getFeedback('billing'), undefined, 'nothing is announced for a search nobody waits for');
});

test('selecting a suggestion cancels the search still pending from the last keystroke', async () => {
	const fields = deferred();
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		fieldsResponder: () => fields.promise
	});
	const provider = runtime.getRawProvider();
	runtime.input('shipping', 'Avenida Providencia');
	const first = provider.search('Avenida Providencia', 'CL');
	runtime.flushTimeouts(250);
	const predictions = await first;

	runtime.input('shipping', 'Avenida Providencia 1');
	const pending = provider.search('Avenida Providencia 1', 'CL');
	const selection = provider.select(predictions[0].id);

	assert.deepEqual(JSON.parse(JSON.stringify(await pending)), []);
	runtime.flushTimeouts(250);
	assert.match(
		runtime.getFeedback('shipping').textContent,
		/Completando dirección/,
		'no late «Buscando direcciones…» replaces the selection status'
	);
	fields.resolve();
	await selection;

	assert.equal(runtime.autocompleteRequests.length, 1, 'the abandoned keystroke never reaches Google');
	assert.equal(runtime.getFeedback('shipping').hidden, true);
});

test('warms the Places library when the address field gains focus, not on other fields', async () => {
	const runtime = bootProvider(undefined, { addressTypes: ['billing'] });
	const [focusListener] = runtime.getDocumentListeners('focusin');
	assert.ok(focusListener, 'the provider listens for focus on the address field');
	assert.equal(focusListener.options?.passive, true);

	const nonAddressInput = runtime.document.createElement('input');
	nonAddressInput.type = 'email';
	runtime.document.body.appendChild(nonAddressInput);
	runtime.dispatchFocusIn(nonAddressInput);
	assert.equal(runtime.getImportLibraryCallCount(), 0, 'the email or name fields do not load Maps');

	runtime.dispatchFocusIn(runtime.getInput('billing'));
	assert.equal(runtime.getImportLibraryCallCount(), 1, 'the SDK is requested on focus, before the first search');
	assert.equal(runtime.autocompleteRequests.length, 0, 'warming never spends an autocomplete request');

	runtime.activate('billing', 'Avenida Providencia 1234');
	await runtime.getProvider().search('Avenida Providencia 1234', 'CL');
	assert.equal(runtime.getImportLibraryCallCount(), 1, 'the search reuses the warmed library');
});

test('watches only the autocomplete container while feedback is visible', async () => {
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		suggestionsResponder: () => ({ suggestions: [] })
	});
	runtime.activate('shipping', 'Dirección sin resultados');
	await runtime.getProvider().search('Dirección sin resultados', 'CL');

	const observers = runtime.getMutationObservers();
	assert.equal(observers.length, 1);
	assert.equal(observers[0].target, runtime.getContainer('shipping'));
	assert.notEqual(observers[0].options.subtree, true, 'not every checkout render');
});

test('keeps open suggestions anchored while typing and releases them below three characters', async () => {
	const runtime = bootProvider(undefined, { addressTypes: ['billing'], viewportHeight: 900 });
	runtime.activate('billing', 'Avenida Apoquindo 4501');
	await runtime.getProvider().search('Avenida Apoquindo 4501', 'CL');
	runtime.appendSuggestions('billing', {
		input: { bottom: 750, height: 50, top: 700 },
		list: { bottom: 994, height: 240, top: 754 },
		panel: { bottom: 1038, height: 284, top: 754 }
	});
	runtime.flushAnimationFrames();
	const container = runtime.getContainer('billing');
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), true);

	runtime.input('billing', 'Avenida Apoquindo 45012');
	assert.equal(
		container.classList.contains('akb-address-autocomplete-dropup'),
		true,
		'Woo keeps the same list until the next search: it must not jump on a keystroke'
	);
	assert.equal(runtime.getAnimationFrameCount(), 0, 'no layout read per keystroke');
	assert.equal(runtime.getWindowListenerCount('scroll'), 1, 'it keeps following scroll while open');

	runtime.input('billing', 'Av');
	assert.equal(container.classList.contains('akb-address-autocomplete-dropup'), false);
	assert.equal(runtime.getWindowListenerCount('scroll'), 0);
});

test('releases viewport tracking on a keystroke when no suggestion list is open', async () => {
	const pending = deferred();
	const runtime = bootProvider(undefined, {
		addressTypes: ['billing'],
		suggestionsResponder: () => pending.promise
	});
	runtime.activate('billing', 'Avenida Matta');
	const search = runtime.getProvider().search('Avenida Matta', 'CL');
	assert.equal(runtime.getWindowListenerCount('scroll'), 1, 'the loading search follows the viewport');

	// Sigue escribiendo antes de que conteste Google: no hay lista que mantener.
	runtime.input('billing', 'Avenida Matta 1');
	assert.equal(runtime.getWindowListenerCount('scroll'), 0);
	assert.equal(runtime.getFeedback('billing').hidden, true);

	pending.resolve({ suggestions: [] });
	assert.deepEqual(JSON.parse(JSON.stringify(await search)), []);
});

test('listens to keystrokes, Escape and blur with passive listeners', async () => {
	const runtime = bootProvider(undefined, { addressTypes: ['shipping'] });
	runtime.activate('shipping', 'Avenida Providencia 1234');
	await runtime.getProvider().search('Avenida Providencia 1234', 'CL');
	const input = runtime.getInput('shipping');

	for (const type of ['input', 'keydown', 'blur']) {
		const entries = runtime.getInputListeners('shipping', type);
		assert.equal(entries.length, 1, type);
		assert.equal(entries[0].options?.passive, true, type);
	}
});

// Revisión R-B, ronda 1 (2026-09-24). Tres defectos que el debounce trasladó a
// justo cuando el cliente deja de escribir y va a tocar una sugerencia.

test('with Woo\'s list open the loading notice is announced but takes no room, and shows once Woo closes the list', async () => {
	const requests = new Map();
	const runtime = bootProvider(undefined, {
		addressTypes: ['billing'],
		suggestionsResponder(request, response) {
			const pending = deferred();
			requests.set(request.input, { ...pending, response });
			return pending.promise;
		}
	});
	const provider = runtime.getProvider();
	const input = runtime.activate('billing', 'Avenida Pro');
	const container = runtime.getContainer('billing');
	const listOpen = 'akb-address-autocomplete-feedback--list-open';
	const busy = 'akb-address-autocomplete-busy';

	const first = provider.search('Avenida Pro', 'CL');
	const firstRequest = await waitForRequest(requests, 'Avenida Pro');
	const feedback = runtime.getFeedback('billing');
	assert.equal(feedback.classList.contains(listOpen), false, 'without a list the notice keeps its place');
	assert.equal(container.classList.contains(busy), false, 'and the field keeps its magnifier');
	firstRequest.resolve(firstRequest.response);
	assert.equal((await first).length, 1);
	const panel = runtime.appendSuggestions('billing');

	runtime.input('billing', 'Avenida Prov');
	const second = provider.search('Avenida Prov', 'CL');
	const secondRequest = await waitForRequest(requests, 'Avenida Prov');
	assert.equal(input.getAttribute('aria-busy'), 'true');
	assert.equal(feedback.hidden, false, 'still announced to screen readers');
	assert.equal(feedback.getAttribute('role'), 'status');
	assert.match(feedback.textContent, /Buscando direcciones/);
	assert.equal(feedback.classList.contains(listOpen), true, 'the notice must not push the open list under the finger');
	assert.equal(container.classList.contains(busy), true, 'the magnifier turns into the loading cue');

	secondRequest.resolve({ suggestions: [] });
	assert.deepEqual(JSON.parse(JSON.stringify(await second)), []);
	assert.match(feedback.textContent, /No encontramos direcciones/);
	assert.equal(container.classList.contains(busy), false);
	assert.equal(feedback.classList.contains(listOpen), true, 'Woo has not closed the old list yet');

	container.removeChild(panel);
	await Promise.resolve();
	assert.equal(feedback.classList.contains(listOpen), false, 'the no-results notice shows as soon as Woo closes the list');
	assert.equal(feedback.hidden, false);

	const reopened = runtime.appendSuggestions('billing');
	runtime.input('billing', 'Avenida Provi');
	const third = provider.search('Avenida Provi', 'CL');
	const thirdRequest = await waitForRequest(requests, 'Avenida Provi');
	assert.equal(container.classList.contains(busy), true);
	thirdRequest.resolve(thirdRequest.response);
	assert.equal((await third).length, 1);
	assert.equal(container.classList.contains(busy), false, 'the magnifier comes back with the new suggestions');
	assert.equal(feedback.hidden, true);
	assert.equal(reopened.parentNode, container);
});

test('Escape drops the pending search and the one already at Google, so Woo\'s closed list stays closed', async () => {
	const requests = new Map();
	const runtime = bootProvider(undefined, {
		addressTypes: ['billing'],
		suggestionsResponder(request, response) {
			const pending = deferred();
			requests.set(request.input, { ...pending, response });
			return pending.promise;
		}
	});
	const provider = runtime.getRawProvider();
	runtime.input('billing', 'Avenida Pro');
	const warm = provider.search('Avenida Pro', 'CL');
	runtime.flushTimeouts(0);
	const warmRequest = await waitForRequest(requests, 'Avenida Pro');
	warmRequest.resolve(warmRequest.response);
	assert.equal((await warm).length, 1);
	runtime.appendSuggestions('billing');
	const input = runtime.getInput('billing');

	// Antes de los 250 ms: la búsqueda todavía no salió.
	runtime.input('billing', 'Avenida Prov');
	const pending = provider.search('Avenida Prov', 'CL');
	input.dispatchEvent(new runtime.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
	assert.equal(await settlesNow(pending), true, 'Escape answers the pending search at once');
	assert.deepEqual(JSON.parse(JSON.stringify(await pending)), []);
	runtime.flushTimeouts(250);
	assert.equal(requests.has('Avenida Prov'), false, 'it never reaches Google');

	// Después: la búsqueda ya está en Google.
	runtime.input('billing', 'Avenida Provi');
	const inFlight = provider.search('Avenida Provi', 'CL');
	runtime.flushTimeouts(250);
	const request = await waitForRequest(requests, 'Avenida Provi');
	assert.equal(input.getAttribute('aria-busy'), 'true');
	input.dispatchEvent(new runtime.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
	assert.equal(input.getAttribute('aria-busy'), null);
	assert.equal(runtime.getFeedback('billing').hidden, true);
	request.resolve(request.response);
	assert.deepEqual(
		JSON.parse(JSON.stringify(await inFlight)),
		[],
		'Woo gets nothing to reopen the list the customer just closed'
	);
});

test('Escape does not abandon an address that is being completed', async () => {
	const fields = deferred();
	const runtime = bootProvider(undefined, {
		addressTypes: ['shipping'],
		fieldsResponder: () => fields.promise
	});
	const provider = runtime.getProvider();
	const input = runtime.activate('shipping', 'Avenida Providencia 1234');
	const predictions = await provider.search('Avenida Providencia 1234', 'CL');

	const selection = provider.select(predictions[0].id);
	input.dispatchEvent(new runtime.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
	assert.equal(input.getAttribute('aria-busy'), 'true');
	assert.match(runtime.getFeedback('shipping').textContent, /Completando dirección/);

	fields.resolve();
	assert.equal((await selection).address_1, 'Providencia 123');
	assert.equal(runtime.getFeedback('shipping').hidden, true);
});

test('a search that comes due while the mouse is down on a suggestion leaves Woo\'s list alone', async () => {
	const runtime = bootProvider(undefined, { addressTypes: ['billing'] });
	const provider = runtime.getRawProvider();
	runtime.input('billing', 'Avenida Pro');
	const warm = provider.search('Avenida Pro', 'CL');
	runtime.flushTimeouts(0);
	const predictions = await warm;
	runtime.appendSuggestions('billing');

	runtime.input('billing', 'Avenida Prov');
	const due = provider.search('Avenida Prov', 'CL');
	runtime.pressSuggestion('billing');
	runtime.flushTimeouts();

	assert.equal(
		await settlesNow(due),
		false,
		'an empty answer would make Woo unmount the list before the click'
	);
	assert.equal(runtime.autocompleteRequests.length, 1, 'nothing is asked to Google for it');
	const address = await provider.select(predictions[0].id);
	assert.equal(address.address_1, 'Providencia 123', 'the click on the pressed suggestion completes it');
});

test('a stale answer that arrives while the mouse is down on a suggestion leaves Woo\'s list alone', async () => {
	const requests = new Map();
	const runtime = bootProvider(undefined, {
		addressTypes: ['billing'],
		suggestionsResponder(request, response) {
			const pending = deferred();
			requests.set(request.input, { ...pending, response });
			return pending.promise;
		}
	});
	const provider = runtime.getProvider();
	runtime.activate('billing', 'Avenida Pro');
	const warm = provider.search('Avenida Pro', 'CL');
	const warmRequest = await waitForRequest(requests, 'Avenida Pro');
	warmRequest.resolve(warmRequest.response);
	await warm;
	runtime.appendSuggestions('billing');

	runtime.input('billing', 'Avenida Prov');
	const answered = provider.search('Avenida Prov', 'CL');
	const answer = await waitForRequest(requests, 'Avenida Prov');
	runtime.input('billing', 'Avenida Provi');
	const failed = provider.search('Avenida Provi', 'CL');
	const failure = await waitForRequest(requests, 'Avenida Provi');

	runtime.pressSuggestion('billing');
	runtime.flushTimeouts(0);
	answer.resolve(answer.response);
	failure.reject(new Error('offline'));

	assert.equal(await settlesNow(answered), false, 'no [] for a late answer during the press');
	assert.equal(await settlesNow(failed), false, 'nor for a late failure');
	assert.equal(runtime.notices.length, 0);
});

// Revisión R-B, ronda 2 (2026-09-24). Woo reemplaza la lista EN EL MISMO SITIO
// cuando contesta una búsqueda: con el dedo (el foco no se mueve hasta
// levantarlo) o con las flechas (el foco nunca sale del campo), el toque o el
// Enter completaban la sugerencia NUEVA de esa posición, no la que el cliente
// veía. El doble contesta según el texto: «Avenida Pro-id» y «Avenida Prov-id»
// son sugerencias distintas.

function listOpenFor(options = {}) {
	const requests = new Map();
	const runtime = bootProvider(undefined, {
		addressTypes: ['billing'],
		suggestionsResponder(request, response) {
			const pending = deferred();
			requests.set(request.input, { ...pending, response });
			return pending.promise;
		},
		...options
	});
	return { requests, runtime };
}

async function openListFor(runtime, requests, query) {
	const provider = runtime.getProvider();
	runtime.activate('billing', query);
	const first = provider.search(query, 'CL');
	const request = await waitForRequest(requests, query);
	request.resolve(request.response);
	const [shown] = await first;
	runtime.appendSuggestions('billing');
	return { provider, shown };
}

test('a Google answer that lands while a finger is on a suggestion waits for it, and the tap completes the one it touched', async () => {
	const { requests, runtime } = listOpenFor();
	const { provider, shown } = await openListFor(runtime, requests, 'Avenida Pro');
	assert.equal(shown.id, 'Avenida Pro-id');
	const input = runtime.getInput('billing');

	runtime.input('billing', 'Avenida Prov');
	const replacing = provider.search('Avenida Prov', 'CL');
	const answer = await waitForRequest(requests, 'Avenida Prov');
	runtime.touchSuggestion('billing');
	answer.resolve(answer.response);

	assert.equal(
		await settlesNow(replacing),
		false,
		'Woo would swap the list under the finger and the click would complete the new suggestion in that spot'
	);
	assert.equal(input.getAttribute('aria-busy'), 'true', 'for the customer it is still searching');

	// Al levantar el dedo: pointerup y touchend; el click trae select() de Woo.
	runtime.dispatchDocumentEvent('pointerup');
	runtime.dispatchDocumentEvent('touchend');
	runtime.dispatchDocumentEvent('click');
	const selection = provider.select(shown.id);
	runtime.flushTimeouts();

	assert.equal(await settlesNow(replacing), false, 'the tap chose: the late list never reaches Woo');
	assert.equal((await selection).address_1, 'Providencia 123');
});

test('a finger that leaves without choosing (a scroll takes the gesture, or it lifts off the list) gets the current answer', async () => {
	for (const release of ['pointercancel', 'pointerup']) {
		const { requests, runtime } = listOpenFor();
		const { provider } = await openListFor(runtime, requests, 'Avenida Pro');

		runtime.input('billing', 'Avenida Prov');
		const replacing = provider.search('Avenida Prov', 'CL');
		const answer = await waitForRequest(requests, 'Avenida Prov');
		runtime.touchSuggestion('billing');
		answer.resolve(answer.response);
		assert.equal(await settlesNow(replacing), false, release + ': held while the finger is down');

		runtime.dispatchDocumentEvent(release);
		runtime.flushTimeouts(0);
		if (release === 'pointerup') {
			assert.equal(await settlesNow(replacing), false, 'the click normally follows the pointerup: it gets a moment');
			runtime.flushTimeouts(350);
		}
		assert.deepEqual(
			(await replacing).map((suggestion) => suggestion.id),
			['Avenida Prov-id'],
			release + ': nothing was chosen, so the search the customer typed is delivered'
		);
		assert.equal(runtime.getInput('billing').getAttribute('aria-busy'), null);
	}
});

test('what the finger held back is not delivered if the customer closed the list meanwhile, and a press whose end never arrives does not hold the next keystroke', async () => {
	{
		const { requests, runtime } = listOpenFor();
		const { provider } = await openListFor(runtime, requests, 'Avenida Pro');
		const input = runtime.getInput('billing');
		runtime.input('billing', 'Avenida Prov');
		const replacing = provider.search('Avenida Prov', 'CL');
		const answer = await waitForRequest(requests, 'Avenida Prov');
		runtime.touchSuggestion('billing');
		answer.resolve(answer.response);
		assert.equal(await settlesNow(replacing), false, 'held under the finger');
		// Una tablet con teclado: Escape cierra la lista con el dedo aún encima.
		input.dispatchEvent(new runtime.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
		runtime.dispatchDocumentEvent('pointercancel');
		runtime.flushTimeouts(0);
		assert.deepEqual(JSON.parse(JSON.stringify(await replacing)), [], 'Woo gets nothing to reopen the list the customer closed');
	}
	{
		const { requests, runtime } = listOpenFor();
		const { provider } = await openListFor(runtime, requests, 'Avenida Pro');
		runtime.touchSuggestion('billing');
		// El navegador nunca avisó del final del toque, y el cliente vuelve a escribir.
		runtime.input('billing', 'Avenida Prov');
		const next = provider.search('Avenida Prov', 'CL');
		const answer = await waitForRequest(requests, 'Avenida Prov');
		answer.resolve(answer.response);
		assert.deepEqual((await next).map((suggestion) => suggestion.id), ['Avenida Prov-id']);
	}
});

test('a search that comes due while a finger is on a suggestion waits: nothing reaches Google unless the finger lifts without choosing', async () => {
	for (const outcome of ['chooses', 'leaves']) {
		const runtime = bootProvider(undefined, { addressTypes: ['billing'] });
		const provider = runtime.getRawProvider();
		runtime.input('billing', 'Avenida Pro');
		const warm = provider.search('Avenida Pro', 'CL');
		runtime.flushTimeouts(0);
		const [shown] = await warm;
		runtime.appendSuggestions('billing');
		const input = runtime.getInput('billing');

		runtime.input('billing', 'Avenida Prov');
		const due = provider.search('Avenida Prov', 'CL');
		runtime.touchSuggestion('billing');
		runtime.flushTimeouts(250);
		await Promise.resolve();

		assert.equal(runtime.autocompleteRequests.length, 1, outcome + ': nothing is asked to Google under the finger');
		assert.equal(input.getAttribute('aria-busy'), null, outcome + ': no «Buscando…» starts under the finger');
		assert.equal(await settlesNow(due), false);

		if (outcome === 'chooses') {
			runtime.dispatchDocumentEvent('touchend');
			runtime.dispatchDocumentEvent('click');
			const selection = provider.select(shown.id);
			runtime.flushTimeouts();
			assert.equal((await selection).address_1, 'Providencia 123');
			assert.deepEqual(JSON.parse(JSON.stringify(await due)), [], 'select() answers it with nothing, as it always did');
			assert.equal(runtime.autocompleteRequests.length, 1, 'and it never reaches Google');
			continue;
		}

		runtime.dispatchDocumentEvent('pointercancel');
		runtime.flushTimeouts(0);
		assert.deepEqual(
			(await due).map((suggestion) => suggestion.id),
			['Avenida Prov-id'],
			'the finger left without choosing: the search goes out then'
		);
		assert.deepEqual(runtime.autocompleteRequests.map((request) => request.input), ['Avenida Pro', 'Avenida Prov']);
	}
});

// Revisión R-B, ronda 3 (2026-09-25). Retener la respuesta mientras el cliente
// usaba las flechas congelaba la lista de una búsqueda anterior si pulsaba una
// flecha justo después de teclear (RB3-01): la búsqueda de lo escrito no salía,
// o su respuesta se tiraba, y Enter completaba una calle de la lista vieja. La
// respuesta ahora se entrega siempre; antes, el proveedor suelta el resaltado
// de Woo con su propio Escape, y la lista de lo escrito abre sin nada resaltado.

// Woo resalta con aria-activedescendant la POSICIÓN, no la sugerencia; su
// Escape, con la lista abierta, la vacía y suelta el resaltado (w([]) y L(-1)).
// Devuelve cada Escape que llega al campo, del cliente o del proveedor.
function wooHighlights(runtime, position) {
	const input = runtime.getInput('billing');
	const container = runtime.getContainer('billing');
	const escapes = [];
	if (position !== undefined) {
		input.setAttribute('aria-activedescendant', 'suggestion-item-billing-' + position);
	}
	input.addEventListener('keydown', (event) => {
		if (event.key !== 'Escape') {
			return;
		}
		const panel = container.querySelector('.wc-block-components-address-autocomplete-suggestions');
		escapes.push({
			cancelled: event.defaultPrevented,
			highlighted: input.getAttribute('aria-activedescendant'),
			listOpen: Boolean(panel)
		});
		if (panel) {
			container.removeChild(panel);
			input.removeAttribute('aria-activedescendant');
		}
	});
	return escapes;
}

test('arrows right after typing do not hold the search back: it reaches Google, Woo lets go of the highlight and the list of what was typed opens with nothing highlighted', async () => {
	for (const moment of ['before the search comes due', 'while the search is at Google']) {
		const { requests, runtime } = listOpenFor();
		await openListFor(runtime, requests, 'Avenida Pro');
		const provider = runtime.getRawProvider();
		const input = runtime.getInput('billing');
		let escapes = [];

		runtime.input('billing', 'Avenida Prov');
		const due = provider.search('Avenida Prov', 'CL');
		if (moment === 'before the search comes due') {
			input.dispatchEvent(new runtime.window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
			escapes = wooHighlights(runtime, 0);
		}
		runtime.flushTimeouts(250);
		const answer = await waitForRequest(requests, 'Avenida Prov');
		assert.deepEqual(
			runtime.autocompleteRequests.map((request) => request.input),
			['Avenida Pro', 'Avenida Prov'],
			moment + ': what the customer typed is asked to Google'
		);
		if (moment === 'while the search is at Google') {
			input.dispatchEvent(new runtime.window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
			escapes = wooHighlights(runtime, 0);
		}
		const escapesWhenDelivered = due.then(() => escapes.length);
		answer.resolve(answer.response);

		assert.equal(await settlesNow(due), true, moment + ': the answer is not thrown away');
		assert.deepEqual((await due).map((suggestion) => suggestion.id), ['Avenida Prov-id'], moment);
		assert.equal(await escapesWhenDelivered, 1, moment + ': Woo let go of the highlight BEFORE the new list reached it');
		assert.deepEqual(
			escapes,
			[{ cancelled: true, highlighted: 'suggestion-item-billing-0', listOpen: true }],
			moment + ': one Escape, cancelled so the theme menu does not take it for the customer\'s'
		);
		assert.equal(input.getAttribute('aria-activedescendant'), null, moment + ': the new list opens with nothing highlighted');
		assert.equal(input.getAttribute('aria-busy'), null, moment);
		assert.equal(runtime.getFeedback('billing').hidden, true, moment);
		assert.equal(runtime.getWindowListenerCount('scroll'), 1, moment + ': the new list is positioned');
	}
});

test('the Escape that lets go of the highlight discards nothing: a no-results answer keeps its notice', async () => {
	const { requests, runtime } = listOpenFor();
	await openListFor(runtime, requests, 'Avenida Pro');
	const provider = runtime.getRawProvider();
	const input = runtime.getInput('billing');

	runtime.input('billing', 'Avenida Prov');
	const due = provider.search('Avenida Prov', 'CL');
	runtime.flushTimeouts(250);
	const answer = await waitForRequest(requests, 'Avenida Prov');
	input.dispatchEvent(new runtime.window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
	const escapes = wooHighlights(runtime, 2);
	answer.resolve({ suggestions: [] });

	assert.equal(await settlesNow(due), true, 'the empty answer reaches Woo');
	assert.deepEqual(JSON.parse(JSON.stringify(await due)), []);
	assert.equal(escapes.length, 1, 'the empty answer closes the list too: the highlight must not outlive it');
	assert.equal(input.getAttribute('aria-activedescendant'), null);
	const feedback = runtime.getFeedback('billing');
	assert.equal(feedback.hidden, false, 'the provider\'s own Escape is not the customer\'s: the notice stays');
	assert.match(feedback.textContent, /No encontramos direcciones/);
});

test('Enter on a highlighted suggestion before the new answer lands completes that one, and the late answer lets nothing reopen', async () => {
	const { requests, runtime } = listOpenFor();
	const { provider, shown } = await openListFor(runtime, requests, 'Avenida Pro');
	const input = runtime.getInput('billing');

	runtime.input('billing', 'Avenida Prov');
	const replacing = provider.search('Avenida Prov', 'CL');
	const answer = await waitForRequest(requests, 'Avenida Prov');
	input.dispatchEvent(new runtime.window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
	const escapes = wooHighlights(runtime, 0);
	const selection = provider.select(shown.id);
	answer.resolve(answer.response);

	assert.equal((await selection).address_1, 'Providencia 123', 'Enter completes what was highlighted');
	assert.equal(await settlesNow(replacing), true);
	assert.deepEqual(JSON.parse(JSON.stringify(await replacing)), [], 'the search the selection overtook answers nothing');
	assert.equal(escapes.length, 0, 'and it sends no Escape: Woo closes the list itself after select()');
});

// Revisión F-B, ronda 1 (RV-FB-01). Con menos de tres caracteres Woo cierra la
// lista (w([])) sin soltar el resaltado, y sin lista ya no atiende el Escape.
// Chromium lo soltaba por el blur que dispara Woo al reinsertar el campo;
// WebKit no, y la lista de la calle siguiente abría con esa posición
// resaltada: Enter la completaba y rellenaba la comuna. Lo que el cliente
// teclea sobre un resaltado lo suelta en beforeinput, con la lista de Woo
// todavía abierta y el texto sin cambiar.
test('typing over a highlight lets go of it while Woo still has the list, so going under three characters leaves nothing highlighted for the next street', async () => {
	const { requests, runtime } = listOpenFor();
	const { provider } = await openListFor(runtime, requests, 'Avenida Pro');
	const input = runtime.getInput('billing');
	const escapes = wooHighlights(runtime, 1);

	// Cmd+A y retroceso: el Escape tiene que llegar ANTES que el texto nuevo.
	input.dispatchEvent(new runtime.window.InputEvent('beforeinput', { inputType: 'deleteContentBackward', bubbles: true }));
	assert.deepEqual(
		escapes,
		[{ cancelled: true, highlighted: 'suggestion-item-billing-1', listOpen: true }],
		'one Escape while Woo still has the list, cancelled so the theme menu does not take it for the customer\'s'
	);
	assert.equal(input.getAttribute('aria-activedescendant'), null, 'the highlight does not outlive the list');

	runtime.input('billing', '');
	assert.deepEqual(JSON.parse(JSON.stringify(await provider.search('', 'CL'))), []);

	input.dispatchEvent(new runtime.window.InputEvent('beforeinput', { inputType: 'insertText', bubbles: true }));
	runtime.input('billing', 'Los Leones 12');
	const next = provider.search('Los Leones 12', 'CL');
	const answer = await waitForRequest(requests, 'Los Leones 12');
	answer.resolve(answer.response);
	assert.deepEqual((await next).map((suggestion) => suggestion.id), ['Los Leones 12-id']);
	assert.equal(input.getAttribute('aria-activedescendant'), null, 'the next list opens with nothing highlighted: Enter completes nothing');
	assert.equal(escapes.length, 1, 'nothing else to let go of');
	assert.equal(input.getAttribute('aria-busy'), null);
});

test('the provider only lets go of a highlight on an open list', async () => {
	// Sin lista Woo no atiende teclas: un Escape no soltaría nada, ni al teclear
	// ni con la respuesta. Por eso se suelta antes de que Woo cierre la lista.
	{
		const { requests, runtime } = listOpenFor();
		const provider = runtime.getProvider();
		const input = runtime.activate('billing', 'Avenida Pro');
		const first = provider.search('Avenida Pro', 'CL');
		const request = await waitForRequest(requests, 'Avenida Pro');
		const escapes = wooHighlights(runtime, 1);
		input.dispatchEvent(new runtime.window.InputEvent('beforeinput', { inputType: 'insertText', bubbles: true }));
		request.resolve(request.response);
		assert.equal(await settlesNow(first), true);
		assert.deepEqual((await first).map((suggestion) => suggestion.id), ['Avenida Pro-id'], 'no list open');
		assert.equal(escapes.length, 0, 'no Escape without a list');
	}
	// Con lista pero sin nada resaltado: teclear no la cierra (sigue donde está
	// hasta que llega la búsqueda nueva) y se renueva sin Escape.
	{
		const { requests, runtime } = listOpenFor();
		const { provider } = await openListFor(runtime, requests, 'Avenida Pro');
		const input = runtime.getInput('billing');
		const escapes = wooHighlights(runtime);
		input.dispatchEvent(new runtime.window.InputEvent('beforeinput', { inputType: 'insertText', bubbles: true }));
		runtime.input('billing', 'Avenida Prov');
		const replacing = provider.search('Avenida Prov', 'CL');
		const answer = await waitForRequest(requests, 'Avenida Prov');
		assert.ok(
			runtime.getContainer('billing').querySelector('.wc-block-components-address-autocomplete-suggestions'),
			'typing with nothing highlighted leaves the list where it is'
		);
		input.dispatchEvent(new runtime.window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
		answer.resolve(answer.response);
		assert.equal(await settlesNow(replacing), true);
		assert.deepEqual((await replacing).map((suggestion) => suggestion.id), ['Avenida Prov-id'], 'nothing highlighted');
		assert.equal(escapes.length, 0, 'nothing to let go of');
	}
});

// RB3-02: tres minutos con la lista abierta. La búsqueda siguiente abre otra
// sesión de Google; si al rotarla se borraban las predicciones, la lista que
// seguía en pantalla (bajo el dedo, o elegida con Enter antes de que
// contestara la búsqueda) llamaba a select() con un id olvidado y no se
// completaba nada.
test('after three idle minutes a new Google session starts, and the list on screen still completes the address', async () => {
	for (const how of ['finger held while the answer lands', 'Enter before the answer lands', 'arrows while the answer lands']) {
		const { requests, runtime } = listOpenFor();
		const { provider, shown } = await openListFor(runtime, requests, 'Avenida Pro');
		const input = runtime.getInput('billing');
		runtime.advanceTime(3 * 60 * 1000);

		runtime.input('billing', 'Avenida Prov');
		const replacing = provider.search('Avenida Prov', 'CL');
		const answer = await waitForRequest(requests, 'Avenida Prov');
		assert.equal(runtime.getTokenCount(), 2, how + ': the idle session was rotated');
		assert.notEqual(
			runtime.autocompleteRequests[0].sessionToken,
			runtime.autocompleteRequests[1].sessionToken,
			how
		);

		if (how === 'finger held while the answer lands') {
			runtime.touchSuggestion('billing');
			answer.resolve(answer.response);
			assert.equal(await settlesNow(replacing), false, how + ': held under the finger');
			runtime.dispatchDocumentEvent('touchend');
			runtime.dispatchDocumentEvent('click');
			const selection = provider.select(shown.id);
			runtime.flushTimeouts();
			assert.equal((await selection).address_1, 'Providencia 123', how + ': the tapped suggestion completes');
			continue;
		}
		input.dispatchEvent(new runtime.window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
		wooHighlights(runtime, 0);
		if (how === 'Enter before the answer lands') {
			const selection = provider.select(shown.id);
			answer.resolve(answer.response);
			assert.equal((await selection).address_1, 'Providencia 123', how + ': the highlighted suggestion completes');
			continue;
		}
		answer.resolve(answer.response);
		assert.equal(await settlesNow(replacing), true, how + ': the new list is delivered');
		const [newest] = await replacing;
		assert.equal(newest.id, 'Avenida Prov-id');
		assert.equal((await provider.select(newest.id)).address_1, 'Providencia 123', how + ': the suggestion shown completes');
	}
});

test('listens to presses on the suggestion list on the document, in capture and passive', () => {
	const runtime = bootProvider(undefined, { addressTypes: ['billing'] });
	for (const type of ['pointerdown', 'touchstart', 'pointerup', 'touchend', 'click', 'pointercancel', 'touchcancel']) {
		const listeners = runtime.getDocumentListeners(type);
		assert.equal(listeners.length, 1, type);
		assert.equal(listeners[0].options?.passive, true, type);
		assert.equal(listeners[0].options?.capture, true, type);
	}
});
