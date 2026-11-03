const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const scriptPath = path.join(__dirname, '../assets/js/pudo-blocks.js');
const stylePath = path.join(__dirname, '../assets/css/pudo-blocks.css');
const script = fs.readFileSync(scriptPath, 'utf8');
const styles = fs.readFileSync(stylePath, 'utf8');

function bootUtilities(documentOverride = {}) {
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
	const window = {
		akbPudoBlocksTestMode: true,
		clearTimeout,
		crypto: {
			getRandomValues(bytes) {
				for (let index = 0; index < bytes.length; index += 1) {
					bytes[index] = index;
				}
				return bytes;
			}
		},
		setTimeout,
		wp: {
			data: {
				dispatch(storeName) {
					assert.equal(storeName, 'wc/store/validation');
					return validation;
				}
			}
		},
		wc: {}
	};

	vm.runInNewContext(script, {
		Error,
		JSON,
		Object,
		Promise,
		Array,
		String,
		document: documentOverride,
		encodeURIComponent,
		window
	});

	return {
		api: window.akbPudoBlocksTestApi,
		validation
	};
}

function fakeElement(name) {
	const attributes = new Map();

	return {
		children: [],
		inert: false,
		name,
		parentElement: null,
		getAttribute(attribute) {
			return attributes.has(attribute) ? attributes.get(attribute) : null;
		},
		hasAttribute(attribute) {
			return attributes.has(attribute);
		},
		removeAttribute(attribute) {
			attributes.delete(attribute);
		},
		setAttribute(attribute, value) {
			attributes.set(attribute, String(value));
		}
	};
}

function appendElement(parent, child) {
	parent.children.push(child);
	child.parentElement = parent;
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
	const body = fakeElement('body');
	const checkout = fakeElement('checkout');
	const modal = fakeElement('modal');
	appendElement(body, checkout);
	appendElement(body, modal);

	const { api } = bootUtilities({ body });
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

	await new Promise((resolve) => setTimeout(resolve, 15));
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
	const body = fakeElement('body');
	const header = fakeElement('header');
	const checkoutRoot = fakeElement('checkout-root');
	const footer = fakeElement('footer');
	const packageCard = fakeElement('package-card');
	const modal = fakeElement('modal');

	header.setAttribute('aria-hidden', 'false');
	appendElement(body, header);
	appendElement(body, checkoutRoot);
	appendElement(body, footer);
	appendElement(checkoutRoot, packageCard);
	appendElement(checkoutRoot, modal);

	const { api } = bootUtilities({ body });
	const restore = api.isolateDocumentBehindModal(modal);

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
	let plugin = null;
	function ExperimentalOrderShippingPackages() {}
	const wp = {
		data: {
			dispatch() {
				return {};
			}
		},
		element: {
			Fragment: Symbol('Fragment'),
			createElement(type, props, ...children) {
				return {
					type,
					props: {
						...(props || {}),
						children: children.length === 1 ? children[0] : children
					}
				};
			},
			useEffect() {},
			useRef(value) {
				return { current: value };
			},
			useState(value) {
				return [value, function () {}];
			}
		},
		plugins: {
			registerPlugin(name, settings) {
				plugin = { name, settings };
			}
		}
	};
	const window = {
		clearTimeout,
		setTimeout,
		wp,
		wc: {
			blocksCheckout: {
				ExperimentalOrderShippingPackages,
				extensionCartUpdate() {
					return Promise.resolve();
				}
			}
		}
	};

	vm.runInNewContext(script, {
		Error,
		JSON,
		Object,
		Promise,
		Array,
		String,
		document: {},
		encodeURIComponent,
		window
	});

	assert.equal(plugin.name, 'akibara-blocks-pudo');
	assert.equal(plugin.settings.scope, 'woocommerce-checkout');
	assert.equal(plugin.settings.render().type, ExperimentalOrderShippingPackages);
});

test('implements modal accessibility, timeout recovery and avoids vendor DOM scraping', () => {
	assert.match(script, /aria-modal': 'true'/);
	assert.match(script, /role: 'dialog'/);
	assert.match(script, /aria-describedby': 'akb-pudo-dialog-description'/);
	assert.match(script, /Mapa para seleccionar un Punto Blue Express/);
	assert.match(script, /event\.key === 'Escape'/);
	assert.ok(script.includes('iframe:not([tabindex="-1"])'));
	assert.match(script, /document\.addEventListener\('focusin', keepFocusInDialog, true\)/);
	assert.match(script, /isolateDocumentBehindModal\(modalRef\.current\)/);
	assert.match(script, /var enqueueCartUpdateOperation = createSerializedQueue\(\)/);
	assert.match(script, /await monitorTimeout/);
	assert.doesNotMatch(script, /await withTimeout/);
	assert.match(script, /var dismissible = canDismissOperation\(props\.saving, props\.timedOut\)/);
	assert.match(script, /event\.target === event\.currentTarget && dismissible/);
	assert.match(script, /if \(dismissible\) \{\s*props\.onClose\(\)/);
	assert.match(script, /disabled: !dismissible/);
	assert.match(script, /disabled: saving/);
	assert.match(script, /if \(savingRef\.current\) \{\s*return;/);
	assert.match(script, /timedOutRef\.current = true/);
	assert.match(script, /elegir otro método de envío o recargar la página/);
	assert.match(script, /FRAME_TIMEOUT_MS/);
	assert.match(script, /Reintentar/);
	assert.match(script, /aria-errormessage/);
	assert.match(script, /aria-invalid/);
	assert.match(script, /role: 'alert'/);

	// «Todavía no eligió punto» NO es un error del cliente: es el estado normal
	// del primer segundo tras elegir «Punto Blue Express» como método de envío.
	// Anunciarlo como alerta interrumpía al lector de pantalla para reprochar
	// algo que nadie había hecho, y el efecto arrancaba el foco de donde
	// estuviera la persona —a media dirección— hacia un botón sin pulsar.
	//
	// Se fija la propiedad, no el marcado: el recordatorio se anuncia sin
	// interrumpir, y el único estado que mueve el foco es el pedido dividido,
	// que el cliente no puede resolver desde aquí.
	const recordatorio = script.slice(
		script.indexOf('Falta seleccionar el punto de retiro') - 600,
		script.indexOf('Falta seleccionar el punto de retiro')
	);
	assert.doesNotMatch(recordatorio, /'aria-live': 'assertive'/);
	assert.doesNotMatch(recordatorio, /role: 'alert'/);
	assert.match(recordatorio, /'aria-live': 'polite'/);
	assert.match(script, /invalidKind === 'multi-package' && invalidFocusRef\.current !== invalidKind/);
	assert.doesNotMatch(
		script,
		/invalidKind === 'multi-package'\s*\?\s*multiPackageErrorRef\.current\s*:\s*selectionButtonRef\.current/
	);
	assert.match(script, /aria-valuetext/);
	assert.match(script, /tabIndex: interactionBlocked \? -1 : 0/);
	assert.doesNotMatch(script, /\.wc-block-components-/);
	assert.doesNotMatch(script, /shipping_address\s*:/);

	assert.match(styles, /height: 100dvh/);
	assert.match(styles, /safe-area-inset-bottom/);
	assert.match(styles, /min-height: 44px/);
	assert.match(styles, /\.akb-pudo-package__validation/);
	assert.match(styles, /\.akb-pudo-dialog__iframe\[aria-hidden="true"\]/);
	assert.match(styles, /pointer-events: none/);
	assert.match(styles, /@media \(prefers-reduced-motion: reduce\)/);
	assert.match(styles, /animation: none/);

	// La capa del selector sale de la escala del tema (theme.json → custom.z), no
	// de un literal suelto: con 100000 no había forma de saber qué quedaba encima.
	assert.match(
		styles,
		/\.akb-pudo-modal \{[^}]*?z-index: var\(--wp--custom--z--cart, 10400\);/
	);
	assert.doesNotMatch(styles, /z-index:\s*\d{3,}/, 'sin capas literales');
	// Detrás del selector todo es inerte: los avisos flotantes de Woo (z-index
	// 100000 en su hoja) y la barra de administración de WordPress (99999 en la
	// de core) bajan a la capa de avisos mientras está abierto. Lo que de verdad
	// queda debajo lo mide el navegador en bloques-carrito-y-selector.spec.mjs.
	const capaDeAvisos = styles
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.match(/([^{}]+)\{[^}]*z-index: var\(--wp--custom--z--toast, 500\);[^}]*\}/);
	assert.ok(capaDeAvisos, 'una regla baja lo de detrás a la capa de avisos');
	const bajados = capaDeAvisos[1].split(',').map((selector) => selector.trim());
	assert.ok(bajados.includes('body.akb-pudo-modal-open .wc-block-components-notice-snackbar-list'));
	assert.ok(bajados.includes('body.akb-pudo-modal-open #wpadminbar'));
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

test('paints exactly one focus ring and keeps the scope line', () => {
	// El canal `notice` existía solo para avisar de una región equivocada. Sin esa
	// restricción no queda nada que anunciar por ahí, así que el estado se eliminó:
	// un prop que jamás puede tener contenido es código muerto, no una extensión.
	assert.doesNotMatch(script, /setNotice|props\.notice|akb-pudo-dialog__notice/);
	assert.match(script, /var interactionBlocked = props\.loading \|\| props\.saving \|\| Boolean\(props\.error\)/);
	assert.match(script, /className: 'akb-pudo-dialog__scope'/);

	// The dialog takes programmatic focus so no control shows a keyboard ring on a
	// pointer-driven open.
	assert.match(script, /role: 'dialog',\s*tabIndex: -1/);
	assert.match(script, /function focusDialog\(\)/);
	assert.doesNotMatch(script, /querySelector\('\.akb-pudo-dialog__close'\)/);

	// The theme already owns --aki-focus-ring; a second yellow outline here stacked
	// into a solid block on the close button.
	assert.doesNotMatch(styles, /outline: 3px solid var\(--aki-yellow/);
	assert.match(styles, /\.akb-pudo-dialog:focus[\s\S]*?box-shadow: 0 24px 80px/);
	assert.match(styles, /--aki-focus-ring/);
	// aria-invalid is this button's default state; its ring must not swallow the focus one.
	assert.match(styles, /\.akb-pudo-button\[aria-invalid="true"\]:focus-visible/);
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
