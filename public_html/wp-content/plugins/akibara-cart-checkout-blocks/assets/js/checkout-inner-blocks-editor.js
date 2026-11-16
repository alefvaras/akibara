(function (wp, wc) {
	'use strict';

	if (
		!wp
		|| !wp.blocks
		|| !wp.blockEditor
		|| !wp.element
		|| !wc
		|| !wc.blocksCheckout
	) {
		return;
	}

	const createElement = wp.element.createElement;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const registerBlockType = wp.blocks.registerBlockType;
	const registerCheckoutFilters = wc.blocksCheckout.registerCheckoutFilters;
	const checkoutFields = 'woocommerce/checkout-fields-block';
	const trustName = 'akibara/checkout-trust-signals';
	const googleLoginName = 'google-login/login-button';

	function metadata(name, title, description) {
		return {
			apiVersion: 3,
			name,
			title,
			description,
			category: 'woocommerce',
			parent: [checkoutFields],
			supports: {
				html: false,
				multiple: false,
				reusable: false
			},
			attributes: {
				lock: {
					type: 'object',
					default: {
						remove: true,
						move: true
					}
				}
			}
		};
	}

	const trustMetadata = metadata(
		trustName,
		'Beneficios de compra Akibara',
		'Muestra envío, cuotas y atención en una grilla simétrica.'
	);

	function editorPlaceholder(className, title, description) {
		return function () {
			const props = useBlockProps({ className: className + ' akb-inner-block-editor' });
			return createElement(
				'div',
				props,
				createElement('strong', null, title),
				createElement('p', null, description)
			);
		};
	}

	function save() {
		return createElement('div', useBlockProps.save());
	}

	registerBlockType(trustMetadata, {
		edit: editorPlaceholder(
			'akb-checkout-trust',
			'Beneficios de comprar en Akibara',
			'Envío, cuotas y atención personalizada.'
		),
		save
	});

	if (typeof registerCheckoutFilters === 'function') {
		registerCheckoutFilters('akibara-checkout-inner-blocks', {
			additionalCartCheckoutInnerBlockTypes (defaultValue, extensions, args) {
				if (!args || args.block !== checkoutFields) {
					return defaultValue;
				}

				return Array.from(new Set(
					defaultValue.concat([googleLoginName, trustName])
				));
			}
		});
	}
})(window.wp, window.wc);
