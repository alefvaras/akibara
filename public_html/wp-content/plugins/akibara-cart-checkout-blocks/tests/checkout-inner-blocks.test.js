const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const editorScript = fs.readFileSync(
	path.join(__dirname, '../assets/js/checkout-inner-blocks-editor.js'),
	'utf8'
);
const frontendScript = fs.readFileSync(
	path.join(__dirname, '../assets/js/checkout-inner-blocks-frontend.js'),
	'utf8'
);

function createElement(type, props, ...children) {
	const elementProps = { ...(props || {}) };
	if (children.length === 1) {
		[elementProps.children] = children;
	} else if (children.length > 1) {
		elementProps.children = children;
	}
	return { type, props: elementProps };
}

function textContent(element) {
	if (element === null || element === undefined || element === false) {
		return '';
	}
	if (typeof element === 'string' || typeof element === 'number') {
		return String(element);
	}
	const children = element.props && element.props.children;
	return (Array.isArray(children) ? children : [children]).map(textContent).join(' ');
}

test('editor registers both semantic blocks and allows Google only inside Checkout Fields', () => {
	const blockTypes = [];
	let filters;
	const wp = {
		blockEditor: {
			useBlockProps() {
				return {};
			}
		},
		blocks: {
			registerBlockType(metadata, settings) {
				blockTypes.push({ metadata, settings });
			}
		},
		element: { createElement },
		i18n: {}
	};
	wp.blockEditor.useBlockProps.save = function () {
		return {};
	};
	const wc = {
		blocksCheckout: {
			registerCheckoutFilters(name, nextFilters) {
				assert.equal(name, 'akibara-checkout-inner-blocks');
				filters = nextFilters;
			}
		}
	};

	vm.runInNewContext(editorScript, { window: { wp, wc } });

	assert.deepEqual(
		blockTypes.map(({ metadata }) => metadata.name),
		['akibara/checkout-preventa-summary', 'akibara/checkout-trust-signals']
	);
	blockTypes.forEach(({ metadata }) => {
		assert.equal(metadata.supports.multiple, false);
		assert.deepEqual(
			JSON.parse(JSON.stringify(metadata.attributes.lock.default)),
			{ remove: true, move: true }
		);
	});
	assert.deepEqual(
		Array.from(filters.additionalCartCheckoutInnerBlockTypes(
			['core/paragraph'],
			{},
			{ block: 'woocommerce/checkout-fields-block' }
		)),
		[
			'core/paragraph',
			'google-login/login-button',
			'akibara/checkout-preventa-summary',
			'akibara/checkout-trust-signals'
		]
	);
	assert.deepEqual(
		Array.from(filters.additionalCartCheckoutInnerBlockTypes(
			['core/paragraph'],
			{},
			{ block: 'woocommerce/checkout-totals-block' }
		)),
		['core/paragraph']
	);
});

test('frontend registers preventa and trust blocks and fails closed on stale data', () => {
	const registrations = [];
	const wp = { element: { createElement } };
	const wc = {
		blocksCheckout: {
			registerCheckoutBlock(definition) {
				registrations.push(definition);
			}
		}
	};

	vm.runInNewContext(frontendScript, { isFinite, window: { wp, wc } });
	assert.deepEqual(
		registrations.map(({ metadata }) => metadata.name),
		['akibara/checkout-preventa-summary', 'akibara/checkout-trust-signals']
	);

	const stale = registrations[0].component({
		extensions: {
			akibara_cart_checkout_blocks: {
				schema_version: 1,
				preventa: { count: 1 }
			}
		}
	});
	assert.equal(stale, null);

	const data = {
		schema_version: 2,
		preventa: {
			count: 1,
			summary_label: 'Tu pedido incluye preventa',
			summary_value: '27 jul – 2 ago',
			has_tba: false,
			explanation: 'Se despacha completo.'
		},
		trust_signals: [
			{ id: 'shipping', label: 'Envíos según cobertura' },
			{ id: 'installments', label: '3 cuotas sin interés' },
			{ id: 'support', label: 'Atención personalizada' }
		]
	};
	const preventa = registrations[0].component({
		extensions: { akibara_cart_checkout_blocks: data }
	});
	const trust = registrations[1].component({
		extensions: { akibara_cart_checkout_blocks: data }
	});

	assert.match(textContent(preventa), /27 jul – 2 ago/);
	assert.equal(preventa.props.role, undefined);
	assert.equal(preventa.props['aria-live'], undefined);
	assert.match(textContent(trust), /Atención personalizada/);
	assert.match(textContent(trust), /Envíos según cobertura/);
	assert.doesNotMatch(textContent(trust), /todo Chile|nacional/i);
	// Desde 2.2.17 el bloque devuelve un contenedor con la lista y el aviso de
	// colección; sin `window.akbCheckoutInnerBlocks` la segunda mitad es null y
	// la lista sigue teniendo sus 3 señales (antes se afirmaba sobre el `<ul>`).
	assert.equal(trust.type, 'div');
	assert.equal(trust.props.className, 'akb-checkout-trust-wrap');
	const [box, notice] = trust.props.children;
	// La lista va dentro de su propia caja: el contenedor de consultas que
	// decide la rejilla por el ancho real y lleva los márgenes del checkout.
	assert.equal(box.type, 'div');
	assert.equal(box.props.className, 'akb-blocks-trust-box akb-checkout-trust');
	const list = box.props.children;
	assert.equal(list.type, 'ul');
	assert.equal(list.props.className, 'akb-blocks-trust');
	assert.equal(list.props.children.length, 3);
	assert.equal(notice, null);
});

// Sandbox con el global mutable: el aviso se lee en tiempo de render, así que
// cada caso lo fija DESPUÉS de cargar el script, igual que WooCommerce hidrata
// tarde y PHP inlina el global antes del bundle.
function loadFrontend() {
	const registrations = [];
	const sandbox = {
		isFinite,
		window: {
			wp: { element: { createElement } },
			wc: {
				blocksCheckout: {
					registerCheckoutBlock(definition) {
						registrations.push(definition);
					}
				}
			}
		}
	};
	vm.runInNewContext(frontendScript, sandbox);
	return {
		sandbox,
		registrations,
		trustSignals: registrations[1].component
	};
}

const signalsData = {
	schema_version: 2,
	trust_signals: [
		{ id: 'shipping', label: 'Envíos según cobertura' },
		{ id: 'support', label: 'Atención personalizada' }
	]
};

function noticeOf(element) {
	if (!element || element.type !== 'div') {
		return null;
	}
	return element.props.children[1];
}

test('trust block paints the collection notice below the signals when PHP inlined a text', () => {
	const { sandbox, trustSignals, registrations } = loadFrontend();
	sandbox.window.akbCheckoutInnerBlocks = {
		collectionNotice: 'Con tu cuenta Akibara te avisamos por correo cuando salga el siguiente tomo.'
	};

	const trust = trustSignals({ extensions: { akibara_cart_checkout_blocks: signalsData } });

	assert.equal(trust.props.className, 'akb-checkout-trust-wrap');
	assert.equal(trust.props.children[0].props.children.type, 'ul');
	const notice = noticeOf(trust);
	assert.equal(notice.type, 'p');
	assert.equal(notice.props.className, 'akb-checkout-collection-notice');
	assert.equal(notice.props.children.type, 'small');
	assert.match(textContent(notice), /siguiente tomo/);
	assert.match(textContent(trust), /Atención personalizada/);
	// La lista de bloques registrados no cambia: el aviso vive DENTRO del
	// bloque de señales, no registra uno nuevo (la página 9083 no se edita).
	assert.deepEqual(
		registrations.map(({ metadata }) => metadata.name),
		['akibara/checkout-preventa-summary', 'akibara/checkout-trust-signals']
	);
});

test('trust block omits the notice when the text is empty, blank or the global is missing', () => {
	const props = { extensions: { akibara_cart_checkout_blocks: signalsData } };

	for (const config of [undefined, {}, { collectionNotice: '' }, { collectionNotice: '   \n\t ' }, { collectionNotice: null }]) {
		const { sandbox, trustSignals } = loadFrontend();
		if (config !== undefined) {
			sandbox.window.akbCheckoutInnerBlocks = config;
		}

		const trust = trustSignals(props);

		assert.equal(trust.type, 'div', `config ${JSON.stringify(config)}`);
		assert.equal(noticeOf(trust), null, `config ${JSON.stringify(config)}`);
		assert.doesNotMatch(textContent(trust), /siguiente tomo/);
		assert.equal(trust.props.children[0].props.children.props.children.length, 2);
	}
});

test('trust block still paints the notice without the Store API extension or with a stale schema', () => {
	for (const props of [
		{},
		{ extensions: {} },
		{ extensions: { akibara_cart_checkout_blocks: { schema_version: 1, trust_signals: signalsData.trust_signals } } },
		{ cart: { extensions: { otro_namespace: signalsData } } }
	]) {
		const { sandbox, trustSignals } = loadFrontend();
		sandbox.window.akbCheckoutInnerBlocks = { collectionNotice: 'Lo apagas cuando quieras en Mi cuenta, pestaña Colección.' };

		const trust = trustSignals(props);

		assert.equal(trust.type, 'div', JSON.stringify(props));
		assert.equal(trust.props.children[0], null, 'sin señales válidas no hay <ul>');
		assert.match(textContent(noticeOf(trust)), /pestaña Colección/);
		assert.doesNotMatch(textContent(trust), /Envíos según cobertura/, 'el esquema viejo sigue fallando cerrado');
	}
});

test('trust block returns null only when there are neither signals nor notice', () => {
	const { sandbox, trustSignals } = loadFrontend();

	assert.equal(trustSignals({}), null);
	assert.equal(trustSignals({ extensions: { akibara_cart_checkout_blocks: { schema_version: 2, trust_signals: [] } } }), null);

	sandbox.window.akbCheckoutInnerBlocks = { collectionNotice: '  ' };
	assert.equal(trustSignals({ extensions: { akibara_cart_checkout_blocks: { schema_version: 1 } } }), null);
});

test('inner-block frontend contains no HTML transport or sink', () => {
	assert.doesNotMatch(frontendScript, /dangerouslySetInnerHTML|\.innerHTML|_html\b/);
});
