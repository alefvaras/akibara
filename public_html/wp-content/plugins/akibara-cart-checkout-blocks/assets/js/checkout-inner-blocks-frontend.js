(function (wp, wc) {
	'use strict';

	if (
		!wp
		|| !wp.element
		|| !wc
		|| !wc.blocksCheckout
		|| typeof wc.blocksCheckout.registerCheckoutBlock !== 'function'
	) {
		return;
	}

	var createElement = wp.element.createElement;
	var namespace = 'akibara_cart_checkout_blocks';
	var checkoutFields = 'woocommerce/checkout-fields-block';

	function metadata(name) {
		return {
			apiVersion: 3,
			name: name,
			parent: [checkoutFields],
			supports: {
				html: false,
				multiple: false,
				reusable: false
			}
		};
	}

	function extensionFromProps(props) {
		var extensions = props && props.extensions
			|| props && props.cart && props.cart.extensions
			|| {};
		var extension = extensions && extensions[namespace];

		return extension && Number(extension.schema_version) === 2
			? extension
			: null;
	}

	function PreventaSummary(props) {
		var extension = extensionFromProps(props);
		var preventa = extension && extension.preventa;
		var count = Number(preventa && preventa.count);
		if (!preventa || !isFinite(count) || count <= 0) {
			return null;
		}

		return createElement(
			'section',
			{
				className: 'akb-checkout-preventa'
					+ (preventa.has_tba ? ' akb-checkout-preventa--tba' : '')
			},
			createElement(
				'p',
				{ className: 'akb-checkout-preventa__heading' },
				createElement('strong', null, String(preventa.summary_label || 'Tu pedido incluye preventas')),
				createElement('span', null, String(preventa.summary_value || 'Te avisamos en cuanto tengamos fechas'))
			),
			preventa.explanation
				? createElement('p', { className: 'akb-checkout-preventa__copy' }, String(preventa.explanation))
				: null
		);
	}

	function icon(id) {
		var paths = {
			shipping: 'M3 6h11v9H3zM14 9h4l3 3v3h-7zM7 18a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
			installments: 'M3 5h18v14H3zM3 9h18M7 15h4',
			support: 'M5 13v-2a7 7 0 0 1 14 0v2M5 13H3v5h4v-5zm14 0h2v5h-4v-5zM17 18c0 2-2 3-5 3'
		};

		return createElement(
			'svg',
			{
				className: 'akb-blocks-trust__icon',
				viewBox: '0 0 24 24',
				'aria-hidden': 'true',
				focusable: 'false'
			},
			createElement('path', {
				d: paths[id] || paths.support,
				fill: 'none',
				stroke: 'currentColor',
				strokeWidth: 1.8,
				strokeLinecap: 'round',
				strokeLinejoin: 'round'
			})
		);
	}

	function trustList(props) {
		var extension = extensionFromProps(props);
		var signals = extension && Array.isArray(extension.trust_signals)
			? extension.trust_signals
			: [];
		if (!signals.length) {
			return null;
		}

		// La caja es el contenedor de consultas de los sellos (ver la hoja) y
		// lleva los márgenes del checkout (`akb-checkout-trust`).
		return createElement(
			'div',
			{ className: 'akb-blocks-trust-box akb-checkout-trust' },
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
						icon(String(signal.id || signal.icon || 'support')),
						createElement('span', null, String(signal.label))
					);
				}).filter(Boolean)
			)
		);
	}

	// El aviso de colección («comprar activa los avisos del siguiente tomo; lo
	// apagas en Mi cuenta») NO sale del payload del Store API: es una option del
	// panel que PHP inlina en `window.akbCheckoutInnerBlocks` antes del bundle.
	// Se lee en cada render y no al cargar el script, para que un checkout que
	// hidrata tarde el global no lo pierda. Vacío o sólo espacios = apagado,
	// mismo contrato que `shippingHint`. Este bundle NO decide para quién es
	// verdad el aviso: eso lo resuelve PHP al inlinar el global (interruptor
	// maestro `akb_sf_enabled` y baja global del cliente, ver
	// `Assets::collection_notice_applies()`), y cuando no aplica llega vacío.
	// El maestro es una option y la baja una usermeta: ninguno viaja al
	// navegador, así que gatear aquí sería adivinar.
	function collectionNotice() {
		var config = window.akbCheckoutInnerBlocks;
		var copy = config ? String(config.collectionNotice || '').trim() : '';
		if (!copy) {
			return null;
		}

		return createElement(
			'p',
			{ className: 'akb-checkout-collection-notice' },
			createElement('small', null, copy)
		);
	}

	// Un solo bloque en la página 9083 pinta las dos cosas: la lista de
	// señales (depende del Store API, schema_version 2) y el aviso de
	// colección (depende sólo de la option). Cada mitad falla cerrada por su
	// lado y el bloque sólo devuelve null cuando no hay ninguna de las dos.
	function TrustSignals(props) {
		var list = trustList(props);
		var notice = collectionNotice();
		if (!list && !notice) {
			return null;
		}

		return createElement(
			'div',
			{ className: 'akb-checkout-trust-wrap' },
			list,
			notice
		);
	}

	wc.blocksCheckout.registerCheckoutBlock({
		metadata: metadata('akibara/checkout-preventa-summary'),
		component: PreventaSummary
	});
	wc.blocksCheckout.registerCheckoutBlock({
		metadata: metadata('akibara/checkout-trust-signals'),
		component: TrustSignals
	});
})(window.wp, window.wc);
