(function (wp, wc) {
	'use strict';

	const config = window.akbCartCheckoutBlocks || {};
	const namespace = config.namespace || 'akibara_cart_checkout_blocks';

	function getCartCount(cart) {
		if (!cart || typeof cart !== 'object') {
			return null;
		}

		if (typeof cart.items_count === 'number' && isFinite(cart.items_count) && cart.items_count >= 0) {
			return Math.floor(cart.items_count);
		}
		if (typeof cart.itemsCount === 'number' && isFinite(cart.itemsCount) && cart.itemsCount >= 0) {
			return Math.floor(cart.itemsCount);
		}

		if (!Array.isArray(cart.items)) {
			return null;
		}

		return cart.items.reduce(function (total, item) {
			const quantity = item && typeof item.quantity === 'number' ? item.quantity : 0;
			return total + (isFinite(quantity) && quantity > 0 ? quantity : 0);
		}, 0);
	}

	function getCartData() {
		if (!wp || !wp.data || typeof wp.data.select !== 'function') {
			return null;
		}

		const selector = wp.data.select('wc/store/cart');
		return selector && typeof selector.getCartData === 'function'
			? selector.getCartData()
			: null;
	}

	function rawExtensionFromMap(extensions) {
		// eslint-disable-next-line security/detect-object-injection
		return extensions && extensions[namespace] && typeof extensions[namespace] === 'object'
			// eslint-disable-next-line security/detect-object-injection
			? extensions[namespace]
			: {};
	}

	function structuredExtensionFromMap(extensions) {
		const extension = rawExtensionFromMap(extensions);
		return Number(extension.schema_version) === 2 ? extension : null;
	}

	function syncCartBadges(cart) {
		const count = getCartCount(cart);
		if (count === null) {
			return;
		}

		document.querySelectorAll('.akb-cart-trigger').forEach(function (trigger) {
			const value = String(count);
			if (trigger.getAttribute('data-akb-cart-count') !== value) {
				trigger.setAttribute('data-akb-cart-count', value);
			}
		});
	}

	function syncCartState() {
		const cart = getCartData();
		syncCartBadges(cart);
	}

	function initializeCartSync() {
		if (!wp || !wp.data || typeof wp.data.subscribe !== 'function') {
			return;
		}

		syncCartState();
		wp.data.subscribe(syncCartState);
	}

	initializeCartSync();

	if (!wp || !wp.element || !wp.plugins || !wc || !wc.blocksCheckout) {
		return;
	}

	const createElement = wp.element.createElement;
	const Fragment = wp.element.Fragment || 'div';
	const ExperimentalOrderMeta = wc.blocksCheckout.ExperimentalOrderMeta;
	const registerCheckoutFilters = wc.blocksCheckout.registerCheckoutFilters;

	function getExtension(props) {
		if (props && props.extensions) {
			return structuredExtensionFromMap(props.extensions);
		}
		if (props && props.cart && props.cart.extensions) {
			return structuredExtensionFromMap(props.cart.extensions);
		}
		return null;
	}

	function renderFreeShipping(extension, context) {
		const freeShipping = extension && extension.free_shipping;
		if (!freeShipping || typeof freeShipping !== 'object') {
			return null;
		}

		const isCheckout = context === 'woocommerce/checkout';
		const visible = isCheckout
			? freeShipping.visible_on_checkout
			: freeShipping.visible_on_cart;
		const state = freeShipping.state;

		if (
			!visible
			|| ['progress', 'qualified'].indexOf(state) === -1
			|| (state === 'qualified' && !freeShipping.show_qualified)
		) {
			return null;
		}

		const progress = Math.max(0, Math.min(100, Number(freeShipping.progress_percent) || 0));
		const className = [
			'akb-blocks-free-shipping',
			'akb-blocks-free-shipping--' + state,
			freeShipping.almost_there ? 'akb-blocks-free-shipping--almost' : ''
		].filter(Boolean).join(' ');

		return createElement(
			'section',
			{ className },
			createElement(
				'div',
				{ className: 'akb-blocks-free-shipping__content' },
				createElement('strong', null, String(freeShipping.title || '')),
				createElement('p', null, String(freeShipping.message || ''))
			),
			state === 'progress'
				? createElement(
					'div',
					{
						className: 'akb-blocks-free-shipping__track',
						role: 'progressbar',
						'aria-valuenow': progress,
						'aria-valuemin': 0,
						'aria-valuemax': 100
					},
					createElement('span', {
						className: 'akb-blocks-free-shipping__bar',
						style: { width: progress + '%' }
					})
				)
				: null
		);
	}

	function isPickupRate(rate) {
		if (!rate || typeof rate !== 'object') {
			return false;
		}

		const methodId = String(rate.method_id || rate.methodId || '');
		const rateId = String(rate.rate_id || rate.rateId || '');

		return methodId === 'local_pickup'
			|| methodId === 'pickup_location'
			|| methodId.indexOf('pickup') !== -1
			|| rateId.indexOf('local_pickup') !== -1
			|| rateId.indexOf('pickup_location') !== -1;
	}

	function renderShippingHint(context, cart) {
		if (context !== 'woocommerce/cart') {
			return null;
		}

		const hint = typeof config.shippingHint === 'string'
			? config.shippingHint.trim()
			: '';
		if (!hint) {
			return null;
		}

		const packages = cart && (cart.shippingRates || cart.shipping_rates);
		if (!Array.isArray(packages) || !packages.length) {
			return null;
		}

		const pickupOnly = packages.every(function (pkg) {
			const rates = pkg && (pkg.shipping_rates || pkg.shippingRates);
			if (!Array.isArray(rates) || !rates.length) {
				return false;
			}

			return rates.every(isPickupRate);
		});

		return pickupOnly
			? createElement(
				'p',
				{ className: 'akb-blocks-shipping-hint' },
				createElement('small', null, hint)
			)
			: null;
	}

	function trustIcon(id) {
		const paths = {
			shipping: 'M3 6h11v9H3zM14 9h4l3 3v3h-7zM7 18a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
			installments: 'M3 5h18v14H3zM3 9h18M7 15h4',
			support: 'M5 13v-2a7 7 0 0 1 14 0v2M5 13H3v5h4v-5zm14 0h2v5h-4v-5zM17 18c0 2-2 3-5 3'
		};
		// eslint-disable-next-line security/detect-object-injection
		const path = paths[id] || paths.support;

		return createElement(
			'svg',
			{
				className: 'akb-blocks-trust__icon',
				viewBox: '0 0 24 24',
				'aria-hidden': 'true',
				focusable: 'false'
			},
			createElement('path', {
				d: path,
				fill: 'none',
				stroke: 'currentColor',
				strokeWidth: 1.8,
				strokeLinecap: 'round',
				strokeLinejoin: 'round'
			})
		);
	}

	function renderTrustSignals(extension) {
		const signals = extension && Array.isArray(extension.trust_signals)
			? extension.trust_signals
			: [];
		if (!signals.length) {
			return null;
		}

		return createElement(
			'div',
			{ className: 'akb-blocks-trust-box' },
			createElement(
				'ul',
				{ className: 'akb-blocks-trust' },
				signals.map(function (signal) {
					const label = String((signal && signal.label) || '');
					if (!label) {
						return null;
					}
					const id = String((signal && signal.id) || 'support');

					return createElement(
						'li',
						{ key: id, className: 'akb-blocks-trust__item' },
						trustIcon(id),
						createElement('span', { className: 'akb-blocks-trust__label' }, label)
					);
				}).filter(Boolean)
			)
		);
	}

	function CartCheckoutMeta(props) {
		const extension = getExtension(props);
		const context = props && props.context;
		const isCheckout = context === 'woocommerce/checkout';
		const children = [
			renderFreeShipping(extension, context),
			renderShippingHint(context, props && props.cart),
			isCheckout ? null : renderTrustSignals(extension)
		].filter(Boolean);

		return children.length
			? createElement('div', { className: 'akb-cart-checkout-blocks-meta' }, children)
			: null;
	}

	if (typeof registerCheckoutFilters === 'function') {
		registerCheckoutFilters('akibara-cart-checkout-blocks', {
			cartItemClass (defaultValue, extensions) {
				const extension = rawExtensionFromMap(extensions);
				const status = extension.stock_status;
				if (['instock', 'outofstock'].indexOf(status) === -1) {
					return defaultValue;
				}

				return [defaultValue, 'akb-stock--' + status].filter(Boolean).join(' ');
			}
		});
	}

	if (
		typeof ExperimentalOrderMeta !== 'function'
		|| typeof wp.plugins.registerPlugin !== 'function'
	) {
		return;
	}

	wp.plugins.registerPlugin('akibara-cart-checkout-blocks', {
		render () {
			return createElement(
				Fragment,
				null,
				createElement(
					ExperimentalOrderMeta,
					null,
					createElement(CartCheckoutMeta, null)
				)
			);
		},
		scope: 'woocommerce-checkout'
	});
})(window.wp, window.wc);
