(function (wp, wc) {
	'use strict';

	var config = window.akbCartCheckoutBlocks || {};
	var namespace = config.namespace || 'akibara_cart_checkout_blocks';

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
			var quantity = item && typeof item.quantity === 'number' ? item.quantity : 0;
			return total + (isFinite(quantity) && quantity > 0 ? quantity : 0);
		}, 0);
	}

	function getCartData() {
		if (!wp || !wp.data || typeof wp.data.select !== 'function') {
			return null;
		}

		var selector = wp.data.select('wc/store/cart');
		return selector && typeof selector.getCartData === 'function'
			? selector.getCartData()
			: null;
	}

	function rawExtensionFromMap(extensions) {
		return extensions && extensions[namespace] && typeof extensions[namespace] === 'object'
			? extensions[namespace]
			: {};
	}

	function structuredExtensionFromMap(extensions) {
		var extension = rawExtensionFromMap(extensions);
		return Number(extension.schema_version) === 2 ? extension : null;
	}

	function getAuthoritativeCartExtension(cart) {
		if (!cart || !cart.extensions || !Object.prototype.hasOwnProperty.call(cart.extensions, namespace)) {
			return null;
		}

		return structuredExtensionFromMap(cart.extensions);
	}

	/** Los filtros coupons, showApplyCouponNotice y showRemoveCouponNotice reciben extensions vacío desde applyCheckoutFilter. Su fuente es el snapshot del data store. structuredExtensionFromMap exige StoreApiIntegration::SCHEMA_VERSION: cualquier cambio de versión debe actualizar a los consumidores. */
	function filterExtension() {
		return getAuthoritativeCartExtension(getCartData());
	}

	function syncCartBadges(cart) {
		var count = getCartCount(cart);
		if (count === null) {
			return;
		}

		document.querySelectorAll('.akb-cart-trigger').forEach(function (trigger) {
			var value = String(count);
			if (trigger.getAttribute('data-akb-cart-count') !== value) {
				trigger.setAttribute('data-akb-cart-count', value);
			}
		});
	}

	function syncCartState() {
		var cart = getCartData();
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

	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment || 'div';
	var ExperimentalDiscountsMeta = wc.blocksCheckout.ExperimentalDiscountsMeta;
	var ExperimentalOrderMeta = wc.blocksCheckout.ExperimentalOrderMeta;
	var registerCheckoutFilters = wc.blocksCheckout.registerCheckoutFilters;

	function getExtension(props) {
		if (props && props.extensions) {
			return structuredExtensionFromMap(props.extensions);
		}
		if (props && props.cart && props.cart.extensions) {
			return structuredExtensionFromMap(props.cart.extensions);
		}
		return null;
	}

	function renderBundleNotices(extension) {
		var notices = extension && extension.bundle && Array.isArray(extension.bundle.notices)
			? extension.bundle.notices
			: [];

		if (!notices.length) {
			return null;
		}

		return createElement(
			'div',
			{
				className: 'akb-blocks-bundle-notices',
				role: 'status',
				'aria-live': 'polite',
				'aria-atomic': 'true'
			},
			notices.map(function (notice, index) {
				var tone = notice && notice.tone === 'warning' ? 'warning' : 'info';
				var title = notice && typeof notice.title === 'string' ? notice.title : '';
				var message = notice && typeof notice.message === 'string' ? notice.message : '';
				var badge = notice && typeof notice.badge === 'string' ? notice.badge : '';

				if (!title && !message) {
					return null;
				}

				return createElement(
					'section',
					{
						key: String((notice && notice.id) || index),
						className: 'akb-blocks-bundle-notice akb-blocks-bundle-notice--' + tone
					},
					badge
						? createElement('span', { className: 'akb-blocks-bundle-notice__badge' }, badge)
						: null,
					createElement(
						'div',
						{ className: 'akb-blocks-bundle-notice__content' },
						title ? createElement('strong', null, title) : null,
						message ? createElement('p', null, message) : null
					)
				);
			}).filter(Boolean)
		);
	}

	function renderFreeShipping(extension, context) {
		var freeShipping = extension && extension.free_shipping;
		if (!freeShipping || typeof freeShipping !== 'object') {
			return null;
		}

		var isCheckout = context === 'woocommerce/checkout';
		var visible = isCheckout
			? freeShipping.visible_on_checkout
			: freeShipping.visible_on_cart;
		var state = freeShipping.state;

		if (
			!visible
			|| ['progress', 'qualified'].indexOf(state) === -1
			|| (state === 'qualified' && !freeShipping.show_qualified)
		) {
			return null;
		}

		var progress = Math.max(0, Math.min(100, Number(freeShipping.progress_percent) || 0));
		var className = [
			'akb-blocks-free-shipping',
			'akb-blocks-free-shipping--' + state,
			freeShipping.almost_there ? 'akb-blocks-free-shipping--almost' : ''
		].filter(Boolean).join(' ');

		return createElement(
			'section',
			{ className: className },
			createElement(
				'div',
				{ className: 'akb-blocks-free-shipping__copy' },
				createElement('strong', null, String(freeShipping.title || 'Envío')),
				freeShipping.message
					? createElement('span', null, String(freeShipping.message))
					: null
			),
			state === 'progress'
				? createElement(
					'div',
					{
						className: 'akb-blocks-free-shipping__track',
						role: 'progressbar',
						'aria-label': String(freeShipping.title || 'Progreso para envío gratis'),
						'aria-valuemin': 0,
						'aria-valuemax': 100,
						'aria-valuenow': Math.round(progress)
					},
					createElement('span', {
						className: 'akb-blocks-free-shipping__value',
						style: { width: progress + '%' }
					})
				)
				: null
		);
	}

	/**
	 * Junta todas las tarifas visibles del carrito. El data store entrega los
	 * paquetes en `shippingRates` (camelCase) pero cada paquete conserva su
	 * forma del Store API (`shipping_rates`, snake_case); se aceptan ambas
	 * grafías a ambos niveles para no depender de esa asimetría.
	 */
	function collectShippingRates(cart) {
		var packages = cart && (cart.shippingRates || cart.shipping_rates);
		if (!Array.isArray(packages)) {
			return [];
		}

		return packages.reduce(function (rates, pkg) {
			var packageRates = pkg && (pkg.shipping_rates || pkg.shippingRates);
			return Array.isArray(packageRates) ? rates.concat(packageRates) : rates;
		}, []);
	}

	/**
	 * Paridad con el `akibara_gp_shipping_address_hint()` del carrito clásico
	 * (retirado del tema cuando /carrito/ pasó a bloques): si TODAS las
	 * tarifas visibles son retiro, el cliente probablemente no ha ingresado
	 * dirección y puede creer que no existe despacho a regiones. Solo carrito:
	 * en el checkout los campos de dirección están a la vista. A diferencia
	 * del original, la condición se reevalúa en vivo — al aparecer una tarifa
	 * de despacho el aviso se retira sin recargar.
	 */
	function renderShippingHint(context, cart) {
		var hint = typeof config.shippingHint === 'string' ? config.shippingHint.trim() : '';
		if (!hint || context === 'woocommerce/checkout') {
			return null;
		}

		var rates = collectShippingRates(cart || getCartData());
		if (!rates.length) {
			return null;
		}

		var pickupOnly = rates.every(function (rate) {
			var methodId = rate && (rate.method_id || rate.methodId);
			return methodId === 'local_pickup' || methodId === 'pickup_location';
		});

		return pickupOnly
			? createElement(
				'p',
				{ className: 'akb-blocks-shipping-hint' },
				createElement('small', null, hint)
			)
			: null;
	}

	function renderPreventaSummary(extension) {
		var preventa = extension && extension.preventa;
		var count = Number(preventa && preventa.count);
		if (!preventa || !isFinite(count) || count <= 0) {
			return null;
		}

		return createElement(
			'section',
			{
				className: 'akb-order-preventa-blocks-notice'
					+ (preventa.has_tba ? ' akb-order-preventa-blocks-notice--tba' : '')
			},
			createElement(
				'p',
				{ className: 'akb-order-preventa-blocks-heading' },
				createElement('strong', null, String(preventa.summary_label || 'Tu pedido incluye preventas')),
				createElement('span', null, String(preventa.summary_value || 'Te avisamos en cuanto tengamos fechas'))
			),
			preventa.explanation
				? createElement(
					'p',
					{ className: 'akb-order-preventa-blocks-copy' },
					String(preventa.explanation)
				)
				: null
		);
	}

	function trustIcon(id) {
		var paths = {
			shipping: 'M3 6h11v9H3zM14 9h4l3 3v3h-7zM7 18a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
			installments: 'M3 5h18v14H3zM3 9h18M7 15h4',
			support: 'M5 13v-2a7 7 0 0 1 14 0v2M5 13H3v5h4v-5zm14 0h2v5h-4v-5zM17 18c0 2-2 3-5 3'
		};
		var path = paths[id] || paths.support;

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
		var signals = extension && Array.isArray(extension.trust_signals)
			? extension.trust_signals
			: [];
		if (!signals.length) {
			return null;
		}

		// La caja es el contenedor de consultas: la rejilla de los sellos
		// depende del ancho de la barra lateral, no de la ventana (ver la hoja).
		return createElement(
			'div',
			{ className: 'akb-blocks-trust-box' },
			createElement(
				'ul',
				{
					className: 'akb-blocks-trust',
					'aria-label': 'Beneficios de comprar en Akibara'
				},
				signals.map(function (signal, index) {
					if (!signal || !signal.label) {
						return null;
					}

					return createElement(
						'li',
						{ key: String(signal.id || index), className: 'akb-blocks-trust__item' },
						trustIcon(String(signal.id || signal.icon || 'support')),
						createElement('span', null, String(signal.label))
					);
				}).filter(Boolean)
			)
		);
	}

	function CartCheckoutMeta(props) {
		// El aviso de despacho NO exige la extensión de akibara-business: lee
		// tarifas nativas del Store API y debe sobrevivir a un payload ausente.
		var extension = getExtension(props);
		var context = props && props.context;
		var isCheckout = context === 'woocommerce/checkout';
		var children = [
			renderBundleNotices(extension),
			renderFreeShipping(extension, context),
			renderShippingHint(context, props && props.cart),
			isCheckout ? null : renderPreventaSummary(extension),
			isCheckout ? null : renderTrustSignals(extension)
		].filter(Boolean);

		return children.length
			? createElement('div', { className: 'akb-cart-checkout-blocks-meta' }, children)
			: null;
	}

	function isBundleCoupon(couponCodeValue, extension) {
		var prefix = extension
			&& extension.bundle
			&& typeof extension.bundle.coupon_prefix === 'string'
			? extension.bundle.coupon_prefix
			: '';

		return Boolean(prefix)
			&& typeof couponCodeValue === 'string'
			&& couponCodeValue.indexOf(prefix) === 0;
	}

	function couponCode(coupon) {
		return String(coupon && (coupon.code || coupon.label) || '');
	}

	function BundleDiscountMeta(props) {
		var extension = getExtension(props);
		if (!extension) {
			return null;
		}

		var cart = props && props.cart || {};
		// Los fillProps de ExperimentalDiscountsMeta son el resto de
		// `useStoreCart()`, que expone `cartCoupons` (nadie entrega `coupons`, la
		// forma del Store API). Se miraba la segunda, así que el rótulo «Pack
		// {serie} · Aplicado automáticamente» no se pintaba nunca.
		var coupons = Array.isArray(cart.cartCoupons) ? cart.cartCoupons : [];
		var bundleCoupons = coupons.filter(function (coupon) {
			return isBundleCoupon(couponCode(coupon), extension);
		});
		if (!bundleCoupons.length) {
			return null;
		}

		var labels = extension.bundle && extension.bundle.coupon_labels || {};
		// El rótulo llega por el payload del Store API (`generic_coupon_label`).
		// El eslabón intermedio `config.genericCouponLabel` no lo escribía
		// nadie: el inline de Assets emite una sola clave, `namespace`.
		var genericLabel = extension.bundle && extension.bundle.generic_coupon_label
			|| 'Descuento de pack';

		return createElement(
			'div',
			{ className: 'akb-blocks-auto-discounts' },
				bundleCoupons.map(function (coupon) {
					var code = couponCode(coupon);

					return createElement(
						'div',
					{ key: code, className: 'akb-blocks-auto-discount' },
					createElement(
						'span',
						{ className: 'akb-blocks-auto-discount__label' },
							String(labels[code] || genericLabel),
							createElement('small', null, 'Aplicado automáticamente')
						)
					);
				})
			);
	}

	if (typeof registerCheckoutFilters === 'function') {
		registerCheckoutFilters('akibara-cart-checkout-blocks', {
			cartItemClass: function (defaultValue, extensions) {
				var extension = rawExtensionFromMap(extensions);
				var status = extension.stock_status;
				if (['instock', 'onbackorder', 'outofstock'].indexOf(status) === -1) {
					return defaultValue;
				}

				return [defaultValue, 'akb-stock--' + status, extension.reservation_price_label ? 'akb-price--reservation' : ''].filter(Boolean).join(' ');
			},
			cartItemPrice: function (defaultValue, extensions) {
				var extension = rawExtensionFromMap(extensions);
				return extension.reservation_price_label
					? extension.reservation_price_label + ': <price/>'
					: defaultValue;
			},
				coupons: function (coupons) {
					var extension = filterExtension();
					if (!extension) {
						return coupons;
					}

					return coupons.filter(function (coupon) {
						return !isBundleCoupon(couponCode(coupon), extension);
					});
				},
				showApplyCouponNotice: function (defaultValue, extensions, args) {
					var extension = filterExtension();
					return extension && args && isBundleCoupon(args.couponCode, extension) ? false : defaultValue;
				},
				showRemoveCouponNotice: function (defaultValue, extensions, args) {
					var extension = filterExtension();
					return extension && args && isBundleCoupon(args.couponCode, extension) ? false : defaultValue;
				}
		});
	}

	if (
		typeof ExperimentalOrderMeta !== 'function'
		|| typeof ExperimentalDiscountsMeta !== 'function'
		|| typeof wp.plugins.registerPlugin !== 'function'
	) {
		return;
	}

	wp.plugins.registerPlugin('akibara-cart-checkout-blocks', {
		render: function () {
			return createElement(
				Fragment,
				null,
				createElement(
					ExperimentalOrderMeta,
					null,
					createElement(CartCheckoutMeta, null)
				),
				createElement(
					ExperimentalDiscountsMeta,
					null,
					createElement(BundleDiscountMeta, null)
				)
			);
		},
		scope: 'woocommerce-checkout'
	});
})(window.wp, window.wc);
