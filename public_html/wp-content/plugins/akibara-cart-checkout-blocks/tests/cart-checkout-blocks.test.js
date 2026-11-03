const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const script = fs.readFileSync(
	path.join(__dirname, '../assets/js/cart-checkout-blocks.js'),
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

function bootCartScript(initialCart, configOverrides = {}) {
	let cart = initialCart;
	let subscriber = null;
	let registeredPlugin = null;
	let registeredFilters = null;
	function ExperimentalOrderMeta() {}
	function ExperimentalDiscountsMeta() {}
	function Fragment() {}
	const triggers = [
		{
			value: '99',
			getAttribute() {
				return this.value;
			},
			setAttribute(name, value) {
				assert.equal(name, 'data-akb-cart-count');
				this.value = value;
			}
		},
		{
			value: '99',
			getAttribute() {
				return this.value;
			},
			setAttribute(name, value) {
				assert.equal(name, 'data-akb-cart-count');
				this.value = value;
			}
		}
	];
	const document = {
		querySelectorAll(selector) {
			assert.equal(selector, '.akb-cart-trigger');
			return triggers;
		}
	};
	const wp = {
		data: {
			select(storeName) {
				assert.equal(storeName, 'wc/store/cart');
				return {
					getCartData() {
						return cart;
					}
				};
			},
			subscribe(callback) {
				subscriber = callback;
				return function () {};
			}
		},
		element: { createElement, Fragment },
		plugins: {
			registerPlugin(name, settings) {
				registeredPlugin = { name, settings };
			}
		}
	};
	const window = {
			akbCartCheckoutBlocks: {
				namespace: 'akibara_cart_checkout_blocks',
				...configOverrides
			},
		wp,
		wc: {
			blocksCheckout: {
				ExperimentalDiscountsMeta,
				ExperimentalOrderMeta,
				registerCheckoutFilters(name, filters) {
					registeredFilters = { name, filters };
				}
			}
		}
	};

	vm.runInNewContext(script, { document, Intl, isFinite, window });

	function fill(index, fillProps) {
		assert.ok(registeredPlugin, 'the WooCommerce extension must be registered');
		const root = registeredPlugin.settings.render();
		const fills = Array.isArray(root.props.children) ? root.props.children : [root.props.children];
		const fillElement = fills[index];
		const child = fillElement.props.children;

		return child.type({ ...child.props, ...fillProps });
	}

	return {
		applyFilter(name, defaultValue, extensions = {}, args = {}) {
			assert.ok(registeredFilters, 'checkout filters must be registered');
			return registeredFilters.filters[name](defaultValue, extensions, args);
		},
		getRegisteredPlugin() {
			return registeredPlugin;
		},
		renderDiscountMeta(fillProps) {
			return fill(1, fillProps);
		},
		renderOrderMeta(fillProps) {
			return fill(0, fillProps);
		},
		setCart(nextCart) {
			cart = nextCart;
			subscriber();
		},
		triggers
	};
}

function extension(data) {
	return {
		akibara_cart_checkout_blocks: data
	};
}

function structured(overrides = {}) {
	return {
		schema_version: 2,
		bundle: {
			coupon_prefix: 'akb_sb_',
			coupon_labels: { akb_sb_byp_abc: 'Pack Chainsaw Man' },
			generic_coupon_label: 'Descuento de pack',
			notices: []
		},
		free_shipping: {
			state: 'hidden',
			reason: 'unavailable',
			visible_on_cart: false,
			visible_on_checkout: false,
			show_qualified: false,
			title: '',
			message: '',
			threshold: 0,
			total: 0,
			remaining: 0,
			progress_percent: 0,
			almost_there: false
		},
		preventa: {
			count: 0,
			is_mixed: false,
			summary_label: '',
			summary_value: '',
			has_tba: false,
			explanation: ''
		},
		trust_signals: [],
		...overrides
	};
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

function findElement(element, predicate) {
	if (!element || typeof element !== 'object') {
		return null;
	}
	if (predicate(element)) {
		return element;
	}
	const children = element.props && element.props.children;
	for (const child of Array.isArray(children) ? children : [children]) {
		const match = findElement(child, predicate);
		if (match) {
			return match;
		}
	}
	return null;
}

test('keeps both Akibara cart badges synchronized from Store API data', () => {
	const runtime = bootCartScript({ itemsCount: 2, items: [] });
	assert.deepEqual(runtime.triggers.map((trigger) => trigger.value), ['2', '2']);

	runtime.setCart({ itemsCount: 4, items: [] });
	assert.deepEqual(runtime.triggers.map((trigger) => trigger.value), ['4', '4']);
});

test('uses an empty Store API item list as an authoritative zero', () => {
	const runtime = bootCartScript({ items: [] });
	assert.deepEqual(runtime.triggers.map((trigger) => trigger.value), ['0', '0']);
});

test('fails closed when the structured contract is absent or not version 2', () => {
	const runtime = bootCartScript({ items: [] });

	assert.equal(
		runtime.renderOrderMeta({
			context: 'woocommerce/cart',
			extensions: extension({ schema_version: 1, free_shipping_html: '<p>unsafe</p>' })
		}),
		null
	);
});

test('renders bundle notices as React text without server HTML injection', () => {
	const runtime = bootCartScript({ items: [] });
	const data = structured({
		bundle: {
			coupon_prefix: 'akb_sb_',
			coupon_labels: {},
			generic_coupon_label: 'Descuento de pack',
			notices: [{
				id: 'next',
				kind: 'next_tier',
				tone: 'info',
				badge: 'Pack',
				series_name: 'Chainsaw Man',
				title: 'Completa tu pack',
				message: '<img src=x onerror=alert(1)>',
				missing_count: 1,
				discount_percent: 10
			}]
		}
	});
	const meta = runtime.renderOrderMeta({
		context: 'woocommerce/cart',
		extensions: extension(data)
	});

	assert.match(textContent(meta), /<img src=x onerror=alert\(1\)>/);
	assert.equal(findElement(meta, (element) => element.props && element.props.dangerouslySetInnerHTML), null);
	const liveRegion = findElement(
		meta,
		(element) => element.props && element.props.className === 'akb-blocks-bundle-notices'
	);
	assert.ok(liveRegion);
	assert.equal(liveRegion.props.role, 'status');
	assert.equal(liveRegion.props['aria-live'], 'polite');
	assert.equal(liveRegion.props['aria-atomic'], 'true');
});

test('renders bounded free-shipping progress with native progress semantics', () => {
	const runtime = bootCartScript({ items: [] });
	const data = structured({
		free_shipping: {
			state: 'progress',
			reason: 'order_amount',
			visible_on_cart: true,
			visible_on_checkout: false,
			show_qualified: true,
			title: 'Envío gratis',
			message: 'Te faltan $10.000',
			threshold: 55000,
			total: 45000,
			remaining: 10000,
			progress_percent: 125,
			almost_there: true
		}
	});
	const meta = runtime.renderOrderMeta({
		context: 'woocommerce/cart',
		extensions: extension(data)
	});
	const progress = findElement(meta, (element) => element.props && element.props.role === 'progressbar');

	assert.ok(progress);
	assert.equal(progress.props['aria-valuenow'], 100);
	assert.equal(progress.props.children.props.style.width, '100%');
});

test('keeps checkout preventa and trust in their dedicated inner blocks', () => {
	const runtime = bootCartScript({ items: [] });
	const data = structured({
		preventa: {
			count: 2,
			is_mixed: true,
			summary_label: 'Incluye preventas',
			summary_value: 'Fecha por confirmar',
			has_tba: true,
			explanation: 'Se despacha cuando todo esté disponible.'
		},
		trust_signals: [
			{ id: 'shipping', icon: 'shipping', label: 'Envíos según cobertura' },
			{ id: 'installments', icon: 'installments', label: '3 cuotas sin interés' },
			{ id: 'support', icon: 'support', label: 'Atención personalizada' }
		]
	});
	const cartMeta = runtime.renderOrderMeta({
		context: 'woocommerce/cart',
		extensions: extension(data)
	});
	const checkoutMeta = runtime.renderOrderMeta({
		context: 'woocommerce/checkout',
		extensions: extension(data)
	});

	assert.match(textContent(cartMeta), /Incluye preventas/);
	assert.match(textContent(cartMeta), /Envíos según cobertura/);
	assert.doesNotMatch(textContent(cartMeta), /todo Chile|nacional/i);
	assert.match(textContent(cartMeta), /Atención personalizada/);
	assert.equal(checkoutMeta, null);
});

test('hides only automatic pack coupons from the removable coupon list', () => {
	const runtime = bootCartScript({
		items: [],
		extensions: extension(structured())
	});
	const coupons = runtime.applyFilter('coupons', [
			{ code: 'akb_sb_byp_abc', label: 'akb_sb_byp_abc' },
			{ code: 'verano', label: 'Verano' }
		]);

	assert.deepEqual(JSON.parse(JSON.stringify(coupons)), [{ code: 'verano', label: 'Verano' }]);
		assert.equal(
			runtime.applyFilter('showApplyCouponNotice', true, {}, { couponCode: 'akb_sb_byp_abc' }),
			false
		);
	assert.equal(
		runtime.applyFilter('showRemoveCouponNotice', true, {}, { couponCode: 'verano' }),
			true
		);

		// Con el módulo en el carrito, WooCommerce invoca estos tres filtros SIN
		// `extensions` (llega `{}`): el snapshot del data store manda igual.
		assert.deepEqual(
			runtime.applyFilter('coupons', [{ code: 'akb_sb_byp_abc' }, { code: 'verano' }], {}),
			[{ code: 'verano' }]
		);
		assert.equal(
			runtime.applyFilter('showApplyCouponNotice', true, {}, { couponCode: 'akb_sb_byp_abc' }),
			false
		);
		assert.equal(
			runtime.applyFilter('showRemoveCouponNotice', true, {}, { couponCode: 'akb_sb_byp_abc' }),
			false
		);
	});

	test('no toca cupones ajenos cuando el módulo no está en el carrito', () => {
		// Sin extensión en el carrito ni en el argumento: los filtros se apartan.
		const runtime = bootCartScript({ items: [], extensions: {} });

		assert.deepEqual(
			runtime.applyFilter('coupons', [{ code: 'akb_sb_byp_abc' }, { code: 'verano' }], {}),
			[{ code: 'akb_sb_byp_abc' }, { code: 'verano' }]
		);
		assert.equal(
			runtime.applyFilter('showApplyCouponNotice', true, {}, { couponCode: 'akb_sb_byp_abc' }),
			true
		);
		assert.equal(
			runtime.applyFilter('showRemoveCouponNotice', true, {}, { couponCode: 'verano' }),
			true
		);
	});

	test('labels an automatic pack discount without duplicating WooCommerce totals', () => {
	const runtime = bootCartScript({
		items: [],
		extensions: extension(structured())
	});
	const discount = runtime.renderDiscountMeta({
		extensions: extension(structured()),
		cart: {
			// Forma de `useStoreCart()`, que es la que reciben los fillProps.
			cartCoupons: [{
				code: 'akb_sb_byp_abc',
				totals: {
					total_discount: '10000',
					total_discount_tax: '0',
					currency_code: 'CLP',
					currency_minor_unit: 0
				}
			}],
			totals: { currency_code: 'CLP', currency_minor_unit: 0 }
		}
	});

		assert.match(textContent(discount), /Pack Chainsaw Man/);
		assert.match(textContent(discount), /Aplicado automáticamente/);
		assert.doesNotMatch(textContent(discount), /10[.\s]000/);
		assert.equal(findElement(discount, (element) => element.type === 'strong'), null);
	});

test('adds the canonical stock class while preserving existing classes', () => {
	const runtime = bootCartScript({ items: [] });
	assert.equal(
		runtime.applyFilter(
			'cartItemClass',
			'gateway-owned',
			extension({ stock_status: 'onbackorder' })
		),
		'gateway-owned akb-stock--onbackorder'
	);
});

test('source contains no HTML transport or DOM HTML sink', () => {
	assert.doesNotMatch(script, /dangerouslySetInnerHTML|\.innerHTML|_html\b/);
});

test('paints exactly one brand focus ring across cart and checkout', () => {
	const styles = fs.readFileSync(
		path.join(__dirname, '../assets/css/cart-checkout-blocks.css'),
		'utf8'
	);

	// El tema ya pinta --aki-focus-ring (amarillo + negro). Un `outline` amarillo
	// propio se apilaba encima y dejaba DOS anillos amarillos sobre los enlaces y
	// botones del checkout, y sólo el outline suelto donde Woo Blocks gana con su
	// propia sombra. Una sola declaración, el token de la marca, para todos.
	assert.doesNotMatch(styles, /outline: 3px solid var\(--aki-yellow/);
	assert.match(
		styles,
		/:is\(a, button, input, select, textarea, \[role="button"\]\):focus-visible[\s\S]*?box-shadow: var\(--aki-focus-ring/
	);

	// box-shadow desaparece en alto contraste forzado: ahí el anillo vuelve como outline.
	assert.match(styles, /@media \(forced-colors: active\)/);

	// El corte angular del tema amputaba la X de 16px de los avisos, y con ella el
	// área táctil de 44x44 que cuelga del pseudo-elemento.
	assert.match(
		styles,
		/\.wc-block-components-notice-banner__dismiss[\s\S]*?clip-path: none/
	);
	assert.match(
		styles,
		/\.wc-block-components-notice-banner__dismiss::after[\s\S]*?width: 44px;\s*height: 44px/
	);

	// Amarillo de marca (#FFD700), no el #ffe600 huérfano, y sin tokens inexistentes.
	assert.doesNotMatch(styles, /#ffe600/i);
	assert.doesNotMatch(styles, /var\(--aki-green,/);
});

test('gives address suggestions and feedback an accessible scoped visual contract', () => {
	const styles = fs.readFileSync(
		path.join(__dirname, '../assets/css/cart-checkout-blocks.css'),
		'utf8'
	);

	assert.match(
		styles,
		/\.woocommerce-checkout [^{]*\.wc-block-components-address-autocomplete-suggestion\s*\{[\s\S]*?min-height:\s*48px/
	);
	assert.match(
		styles,
		/\.woocommerce-checkout [^{]*\.wc-block-components-address-autocomplete-suggestion\s*\{[\s\S]*?color:\s*var\(--aki-light, #e0e0e0\);[\s\S]*?background:\s*var\(--aki-surface-2, #1a1a1a\);/
	);
	assert.match(
		styles,
		/\.woocommerce-checkout [^{]*\.wc-block-components-address-autocomplete-suggestion\s*\{[\s\S]*?border-bottom:\s*1px solid var\(--aki-border, #2a2a2e\);/
	);
	assert.match(styles, /\.suggestions-list \.wc-block-components-address-autocomplete-suggestion/);
	assert.match(
		styles,
		/\.woocommerce-checkout \.wc-block-components-address-autocomplete-container > \.wc-block-components-address-autocomplete-suggestions\s*\{[\s\S]*?top:\s*calc\(100% \+ 4px\);[\s\S]*?bottom:\s*auto;/
	);
	assert.match(
		styles,
		/\.woocommerce-checkout \.wc-block-components-address-autocomplete-container\.akb-address-autocomplete-dropup > \.wc-block-components-address-autocomplete-suggestions\s*\{[\s\S]*?top:\s*auto;[\s\S]*?bottom:\s*calc\(100% \+ 4px\);/
	);
	assert.match(
		styles,
		/\.woocommerce-checkout \.wc-block-components-address-autocomplete-container\.akb-address-autocomplete-constrained[\s\S]*?\.suggestions-list\s*\{[\s\S]*?max-height:\s*var\(--akb-address-autocomplete-list-max-height\);[\s\S]*?overflow-y:\s*auto;/
	);
	assert.match(
		styles,
		/\.woocommerce-checkout \.akb-address-autocomplete-feedback[\s\S]*?overflow-wrap:\s*anywhere/
	);
	assert.match(styles, /\.akb-address-autocomplete-feedback--loading::before/);
	assert.match(
		styles,
		/@media \(prefers-reduced-motion: reduce\)[\s\S]*?animation:\s*none/
	);
	assert.match(
		styles,
		/@media \(forced-colors: active\)[\s\S]*?\.wc-block-components-address-autocomplete-suggestion\.active,[\s\S]*?color:\s*HighlightText;[\s\S]*?background:\s*Highlight;/
	);

	function luminance(hex) {
		const channels = hex.match(/[a-f\d]{2}/gi).map((channel) => {
			const value = parseInt(channel, 16) / 255;
			return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
		});
		return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
	}

	function contrast(foreground, background) {
		const values = [luminance(foreground), luminance(background)].sort((a, b) => b - a);
		return (values[0] + 0.05) / (values[1] + 0.05);
	}

	const themePath = path.join(__dirname, '../../../themes/akibara-gp/theme.json');
	const palette = fs.existsSync(themePath)
		? JSON.parse(fs.readFileSync(themePath, 'utf8')).settings.color.palette
		: [];
	// The isolated plugin package uses the same fallbacks as its stylesheet.
	const color = (slug, fallback) => palette.find((entry) => entry.slug === slug)?.color.slice(1) || fallback;
	assert.ok(contrast(color('gray-200', 'e0e0e0'), color('surface-2', '1a1a1a')) >= 4.5, 'suggestion text must meet WCAG AA');
	assert.ok(contrast(color('white', 'ffffff'), color('surface-3', '222222')) >= 4.5, 'active suggestion text must meet WCAG AA');
	assert.ok(contrast(color('primary-text', 'ff4756'), color('surface-2', '1a1a1a')) >= 4.5, 'error feedback must meet WCAG AA');
});

// Con la lista abierta, un aviso en el flujo empujaba la lista 43 px bajo el dedo
// y el toque completaba la sugerencia de arriba (revisión R-B, ronda 1).
test('with the address list open the feedback leaves the flow and the field magnifier spins in place', () => {
	const styles = fs.readFileSync(
		path.join(__dirname, '../assets/css/cart-checkout-blocks.css'),
		'utf8'
	);
	const hidden = styles.match(
		/\.woocommerce-checkout \.akb-address-autocomplete-feedback--list-open\s*\{([^}]*)\}/
	);
	assert.ok(hidden, 'the list-open feedback has its own rule');
	for (const declaration of [
		/position:\s*absolute;/,
		/width:\s*1px;/,
		/height:\s*1px;/,
		/min-height:\s*0;/,
		/margin:\s*-1px;/,
		/padding:\s*0;/,
		/overflow:\s*hidden;/,
		/clip-path:\s*inset\(50%\);/,
		/border:\s*0;/
	]) {
		assert.match(hidden[1], declaration);
	}
	assert.doesNotMatch(
		hidden[1],
		/display:\s*none|visibility:\s*hidden/,
		'screen readers still hear «Buscando direcciones…»'
	);

	const icon = styles.match(
		/\.woocommerce-checkout \.akb-address-autocomplete-busy \.wc-block-components-address-autocomplete-icon\s*\{([^}]*)\}/
	);
	assert.ok(icon, 'the loading cue lives in the field icon');
	assert.match(icon[1], /-webkit-mask-image:\s*url\("data:image\/svg\+xml,/);
	assert.match(icon[1], /(^|[^-])mask-image:\s*url\("data:image\/svg\+xml,/);
	assert.match(icon[1], /animation:\s*akb-address-autocomplete-icon-spin 700ms linear infinite;/);
	assert.match(
		styles,
		/@keyframes akb-address-autocomplete-icon-spin\s*\{\s*from\s*\{\s*transform:\s*translateY\(-50%\) rotate\(0deg\);\s*\}\s*to\s*\{\s*transform:\s*translateY\(-50%\) rotate\(360deg\);/,
		'Woo centres the icon with translateY(-50%): the spin must keep it'
	);
	assert.match(
		styles,
		/@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*\.woocommerce-checkout \.akb-address-autocomplete-busy \.wc-block-components-address-autocomplete-icon\s*\{\s*animation:\s*none;/
	);

	// En colores forzados el sistema pinta background-color de Canvas: la rueda
	// (una máscara sobre ese fondo) desaparecía y, con el aviso sólo anunciado,
	// no quedaba señal visible de búsqueda (revisión R-B, ronda 2).
	const forcedStart = styles.indexOf('@media (forced-colors: active)');
	assert.notEqual(forcedStart, -1);
	let depth = 0;
	let forcedEnd = styles.indexOf('{', forcedStart);
	do {
		if (styles[forcedEnd] === '{') {
			depth += 1;
		} else if (styles[forcedEnd] === '}') {
			depth -= 1;
		}
		forcedEnd += 1;
	} while (depth > 0 && forcedEnd < styles.length);
	const forced = styles.slice(forcedStart, forcedEnd);
	const forcedIcon = forced.match(
		/\.woocommerce-checkout \.akb-address-autocomplete-busy \.wc-block-components-address-autocomplete-icon\s*\{([^}]*)\}/
	);
	assert.ok(forcedIcon, 'the spinner keeps its own ink inside the forced-colors block');
	assert.match(forcedIcon[1], /forced-color-adjust:\s*none;/);
	assert.match(forcedIcon[1], /background-color:\s*CanvasText;/, 'a system colour, so it follows the chosen theme');
});

// ——— Aviso «¿No estás en Santiago?» (paridad con el hint del carrito clásico) ———

const HINT = '¿No estás en Santiago? Ingresa tu dirección para ver opciones de despacho a domicilio.';

function pickupOnlyCart() {
	return {
		items: [],
		shippingRates: [
			{
				shipping_rates: [
					{ method_id: 'local_pickup', rate_id: 'local_pickup:1' },
					{ method_id: 'pickup_location', rate_id: 'pickup_location:0' }
				]
			}
		]
	};
}

test('shows the Santiago hint on pickup-only carts even without the business payload', () => {
	const runtime = bootCartScript({ items: [] }, { shippingHint: HINT });
	const meta = runtime.renderOrderMeta({
		context: 'woocommerce/cart',
		cart: pickupOnlyCart(),
		extensions: {}
	});

	const hint = findElement(
		meta,
		(element) => element.props && element.props.className === 'akb-blocks-shipping-hint'
	);
	assert.ok(hint, 'the hint must render without the akibara-business extension');
	assert.match(textContent(hint), /No estás en Santiago/);
});

test('withdraws the Santiago hint as soon as a delivery rate exists', () => {
	const runtime = bootCartScript({ items: [] }, { shippingHint: HINT });
	const cart = pickupOnlyCart();
	cart.shippingRates[0].shipping_rates.push({ method_id: 'bluex', rate_id: 'bluex:3' });

	const meta = runtime.renderOrderMeta({
		context: 'woocommerce/cart',
		cart,
		extensions: {}
	});

	assert.doesNotMatch(textContent(meta), /No estás en Santiago/);
});

test('keeps the Santiago hint out of checkout and of rate-less carts', () => {
	const runtime = bootCartScript({ items: [] }, { shippingHint: HINT });

	const checkoutMeta = runtime.renderOrderMeta({
		context: 'woocommerce/checkout',
		cart: pickupOnlyCart(),
		extensions: {}
	});
	assert.doesNotMatch(textContent(checkoutMeta), /No estás en Santiago/);

	const emptyRatesMeta = runtime.renderOrderMeta({
		context: 'woocommerce/cart',
		cart: { items: [], shippingRates: [ { shipping_rates: [] } ] },
		extensions: {}
	});
	assert.doesNotMatch(textContent(emptyRatesMeta), /No estás en Santiago/);
});

test('an empty shippingHint config turns the Santiago hint off', () => {
	const runtime = bootCartScript({ items: [] }, { shippingHint: '   ' });
	const meta = runtime.renderOrderMeta({
		context: 'woocommerce/cart',
		cart: pickupOnlyCart(),
		extensions: {}
	});

	assert.equal(meta, null);
});

test('el area tactil del panel de cupones no se come el contenido', () => {
	// `margin-block: -13px` (arriba Y abajo) subia el contenido del panel esos
	// 13 px: al abrir «Añadir cupones» y enfocar el campo, su anillo de foco
	// (6 px de box-shadow) quedaba bajo el titulo — medido en el checkout,
	// solape de 16 px; con el negativo solo arriba, el anillo queda 5 px por
	// debajo del titulo (lo vio el dueno en produccion el 2026-09-17).
	const styles = fs.readFileSync(
		path.join(__dirname, '../assets/css/cart-checkout-blocks.css'),
		'utf8'
	);

	assert.match(
		styles,
		/\.wc-block-components-panel__button \{[\s\S]*?min-height: 44px;[\s\S]*?margin-block: -13px 0;/,
		'el negativo va solo arriba: abajo arrastraba el contenido bajo el titulo'
	);
	assert.doesNotMatch(
		styles,
		/\.wc-block-components-panel__button \{[\s\S]*?margin-block: -13px;/,
		'el negativo simetrico es el que provocaba el corte'
	);
});

test('el campo del cupon se ve igual en el carrito y en el checkout', () => {
	// El bloque Checkout lleva `has-dark-controls` de Woo y sus campos nacen
	// oscuros; el Carrito no, asi que el cupon se pintaba BLANCO sobre la
	// superficie negra (medido el 2026-09-17: #fff con texto #2b2d2f).
	const styles = fs.readFileSync(
		path.join(__dirname, '../assets/css/cart-checkout-blocks.css'),
		'utf8'
	);

	assert.match(
		styles,
		/\.woocommerce-cart \.wc-block-components-totals-coupon \.wc-block-components-text-input input\[type="text"\] \{[\s\S]*?background: rgb\(0 0 0 \/ 10%\);/,
		'el campo del carrito toma el mismo fondo oscuro que el del checkout'
	);
	assert.doesNotMatch(
		styles.replace(/\/\*[\s\S]*?\*\//g, '').match(/\.woocommerce-cart \.wc-block-components-totals-coupon \.wc-block-components-text-input input\[type="text"\] \{([^{}]*)\}/)[1],
		/!important/,
		'gana por especificidad, no a martillazos'
	);
});

test('en el carrito angosto el total de la línea tiene su propio espacio y no pisa el nombre ni el botón de quitar', () => {
	// Woo deja el total en una columna implícita que, con `min-width: 0` en las
	// celdas, mide 0 px a 320 y 20 a 360: el precio y la insignia «Ahorra $X» se
	// encimaban sobre el nombre y tapaban el botón de quitar (medido 2026-09-23).
	// El comportamiento lo mide el navegador en bloques-carrito-y-selector.spec.mjs
	// (306 a 390 px, y el CLS al cargar); esto sólo evita que las reglas
	// desaparezcan o vuelvan a colgar de las clases que pone el JS de Woo.
	const styles = fs
		.readFileSync(path.join(__dirname, '../assets/css/cart-checkout-blocks.css'), 'utf8')
		.replace(/\/\*[\s\S]*?\*\//g, '');
	// El cuerpo de un `@container <condición> { … }`, con sus llaves anidadas.
	const bloque = (condicion) => {
		const inicio = styles.indexOf(`@container ${condicion} {`);
		assert.notEqual(inicio, -1, `falta @container ${condicion}`);
		let nivel = 0;
		for (let i = styles.indexOf('{', inicio); i < styles.length; i++) {
			if ('{' === styles[i]) {
				nivel++;
			} else if ('}' === styles[i] && 0 === --nivel) {
				return styles.slice(styles.indexOf('{', inicio) + 1, i);
			}
		}
		assert.fail(`@container ${condicion} sin cerrar`);
	};
	const reglaCon = (selector, declaracion) => new RegExp(
		selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '[^{]*\\{[^}]*' + declaracion
	);
	const fila = '.woocommerce-cart .wc-block-cart table.wc-block-cart-items .wc-block-cart-items__row';

	// La rejilla angosta no puede colgar de `is-mobile`/`is-small`: el JS de Woo
	// las pone un cuadro DESPUÉS de pintar las líneas y el total saltaba de fila
	// en cada carga (CLS 0,146 a 390 px, medido 2026-09-24). Va en consultas de
	// contenedor, como la escribe Woo, con sus mismos cortes (400 y 520 px).
	assert.doesNotMatch(styles, /\.is-(?:mobile|small|medium)\b[^{]*\.wc-block-cart-item/);
	assert.doesNotMatch(styles, /\.is-(?:mobile|small|medium)\b[^{]*\.wc-block-cart-items__row/);

	// Teléfono: el total baja a una fila propia y el producto usa todo el ancho.
	const telefono = bloque('(width <= 400px)');
	assert.match(telefono, reglaCon(fila, 'grid-template-columns: 80px minmax\\(0, 1fr\\);'));
	assert.match(telefono, reglaCon(`${fila} .wc-block-cart-item__total`, 'grid-row: 2;'));
	assert.match(telefono, reglaCon(`${fila} .wc-block-cart-item__product,`, 'grid-column: 2 / -1;'));
	// El relleno de Woo en la cantidad alargaba la página 8 px a 320, y bajo
	// 342 px el selector (130) y el botón de quitar (44) no caben en una fila: el
	// botón baja en vez de salirse de la línea.
	assert.match(telefono, reglaCon(`${fila} .wc-block-cart-item__quantity`, 'padding-right: 0;'));
	assert.match(telefono, reglaCon(`${fila} .wc-block-cart-item__quantity`, 'flex-wrap: wrap;'));

	// Tableta angosta: el total en una columna propia del ancho de su contenido.
	const chica = bloque('(400px < width <= 520px)');
	assert.match(chica, reglaCon(fila, 'grid-template-columns: 80px minmax\\(0, 1fr\\) max-content;'));
	assert.match(chica, reglaCon(`${fila} .wc-block-cart-item__product`, 'grid-column: 2 / 3;'));
	assert.match(chica, reglaCon(`${fila} .wc-block-cart-item__total`, 'grid-column: 3;'));
});

// Cuerpo de un bloque `@media …` o `@container …` (con sus llaves anidadas) de
// la hoja sin comentarios, o null si no está.
function bloqueDeLaHoja(styles, cabecera) {
	const inicio = styles.indexOf(`${cabecera} {`);
	if (-1 === inicio) {
		return null;
	}
	const abre = styles.indexOf('{', inicio);
	let nivel = 0;
	for (let i = abre; i < styles.length; i++) {
		if ('{' === styles[i]) {
			nivel++;
		} else if ('}' === styles[i] && 0 === --nivel) {
			return styles.slice(abre + 1, i);
		}
	}
	return null;
}

function hojaSinComentarios() {
	return fs
		.readFileSync(path.join(__dirname, '../assets/css/cart-checkout-blocks.css'), 'utf8')
		.replace(/\/\*[\s\S]*?\*\//g, '');
}

test('los sellos de confianza van en una caja propia, que es la que decide su rejilla por su ancho', () => {
	// En la barra lateral del carrito (233 a 458 px en tableta y escritorio) las
	// tres columnas fijas dejaban el texto en vertical a 768 y partido a 1024 y
	// 1440 (medido 2026-09-24). El ancho real lo mide el navegador en
	// bloques-correcciones-qa.spec.mjs; esto fija la forma.
	const runtime = bootCartScript({ items: [] });
	const meta = runtime.renderOrderMeta({
		context: 'woocommerce/cart',
		extensions: extension(structured({
			trust_signals: [
				{ id: 'shipping', label: 'Envíos a todo Chile' },
				{ id: 'installments', label: '3 cuotas sin interés' },
				{ id: 'support', label: 'Atención personalizada' }
			]
		}))
	});
	const caja = findElement(meta, (element) => element.props && 'akb-blocks-trust-box' === element.props.className);

	assert.ok(caja, 'la lista va dentro de su caja');
	assert.equal(caja.type, 'div');
	assert.equal(caja.props.children.type, 'ul');
	assert.equal(caja.props.children.props.className, 'akb-blocks-trust');
	assert.equal(caja.props.children.props.children.length, 3);

	const styles = hojaSinComentarios();
	const regla = (selector) => {
		const m = styles.match(new RegExp(`(?:^|\\})\\s*${selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*\\{([^}]*)\\}`));
		return m ? m[1] : '';
	};

	// La caja es el contenedor; la lista, sin márgenes (los lleva la caja: un
	// contenedor no deja fundirse los de sus hijos con los de fuera).
	assert.match(regla('.akb-blocks-trust-box'), /container: akb-trust \/ inline-size;/);
	assert.match(regla('.akb-blocks-trust'), /grid-template-columns: minmax\(0, 1fr\);/);
	assert.match(regla('.akb-blocks-trust'), /margin: 0;/);

	// Ninguna consulta de VENTANA decide sus columnas.
	for (const media of styles.match(/@media[^{]*\{/g) || []) {
		const cuerpo = bloqueDeLaHoja(styles, media.slice(0, -1).trim()) || '';
		assert.doesNotMatch(cuerpo, /\.akb-blocks-trust\s*\{/, `${media} no toca la rejilla de los sellos`);
	}

	const media = bloqueDeLaHoja(styles, '@container akb-trust (380px <= width < 480px)');
	const ancha = bloqueDeLaHoja(styles, '@container akb-trust (width >= 480px)');
	assert.ok(media, 'falta la forma de 380 a 479 px');
	assert.ok(ancha, 'falta la forma desde 480 px');
	assert.match(media, /\.akb-blocks-trust\s*\{[^}]*grid-template-columns: repeat\(3, minmax\(0, 1fr\)\);/);
	assert.match(media, /\.akb-blocks-trust__item\s*\{[^}]*flex-direction: column;/);
	assert.match(ancha, /\.akb-blocks-trust\s*\{[^}]*grid-template-columns: repeat\(3, minmax\(0, 1fr\)\);/);

	// Una palabra que cabe no se parte.
	assert.doesNotMatch(regla('.akb-blocks-trust__item span'), /overflow-wrap: anywhere/);
});

test('«Finalizar compra» sigue en el flujo donde Woo no fija la barra, y la barra sólo reemplaza la navegación donde existe', () => {
	// Woo 11 pone `--sticky` al ÚNICO contenedor del botón mientras está bajo la
	// ventana y, desde 783 px (o con 500 px de alto o menos), lo oculta con
	// display: none: a 1366x768 no había botón ni en la vista ni en el orden de
	// Tab (medido 2026-09-24). Lo mide el navegador en bloques-correcciones-qa.
	const styles = hojaSinComentarios();
	const enFlujo = bloqueDeLaHoja(styles, '@media (min-width: 783px), (max-height: 500px)');
	const barra = bloqueDeLaHoja(styles, '@media (max-width: 782px) and (min-height: 501px)');
	const hasta1024 = bloqueDeLaHoja(styles, '@media (max-width: 1024px)');

	assert.ok(enFlujo, 'falta el bloque que deja el botón en el flujo');
	assert.match(
		enFlujo,
		/body\.woocommerce-cart \.wc-block-cart \.wc-block-cart__submit-container--sticky\s*\{[^}]*position: static;[^}]*display: block;[^}]*background-color: transparent !important;/
	);
	assert.match(enFlujo, /\.wc-block-cart__submit-container--sticky::before\s*\{[^}]*content: none;/);

	assert.ok(barra, 'falta el bloque de la barra fija');
	assert.match(barra, /body\.woocommerce-cart:has\(\.wc-block-cart__submit-container--sticky\) \.bottom-nav\s*\{[^}]*display: none !important;/);
	// De 783 a 1024 Woo no pinta la barra: esconder ahí la navegación dejaba al
	// cliente sin ninguna de las dos.
	assert.doesNotMatch(hasta1024 || '', /submit-container--sticky/);
});


test('reserva y preventa conservan su precio vigente sin cambiar ofertas reales', () => {
	const runtime = bootCartScript({ items: [] });
	for (const label of ['Precio de reserva', 'Precio de preventa']) {
		const data = extension({ stock_status: 'onbackorder', reservation_price_label: label });
		assert.equal(runtime.applyFilter('cartItemPrice', '<price/>', data), label + ': <price/>');
		assert.match(runtime.applyFilter('cartItemClass', 'third-party', data), /^third-party akb-stock--onbackorder akb-price--reservation$/);
	}
	for (const status of ['instock', 'onbackorder']) {
		const data = extension({ stock_status: status, reservation_price_label: '' });
		assert.equal(runtime.applyFilter('cartItemPrice', 'Oferta: <price/>', data), 'Oferta: <price/>');
		assert.doesNotMatch(runtime.applyFilter('cartItemClass', '', data), /akb-price--reservation/);
	}
	assert.equal(runtime.applyFilter('cartItemPrice', '<price/>', {}), '<price/>');
});
