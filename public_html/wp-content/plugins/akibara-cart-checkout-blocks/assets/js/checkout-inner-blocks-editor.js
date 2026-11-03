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

	var createElement = wp.element.createElement;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var registerBlockType = wp.blocks.registerBlockType;
	var registerCheckoutFilters = wc.blocksCheckout.registerCheckoutFilters;
	var checkoutFields = 'woocommerce/checkout-fields-block';
	var preventaName = 'akibara/checkout-preventa-summary';
	var trustName = 'akibara/checkout-trust-signals';
	var googleLoginName = 'google-login/login-button';

	function metadata(name, title, description) {
		return {
			apiVersion: 3,
			name: name,
			title: title,
			description: description,
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

	var preventaMetadata = metadata(
		preventaName,
		'Resumen de preventa Akibara',
		'Muestra la estimación de preventa calculada por Akibara Business.'
	);
	var trustMetadata = metadata(
		trustName,
		'Beneficios de compra Akibara',
		'Muestra envío, cuotas y atención en una grilla simétrica.'
	);

	function editorPlaceholder(className, title, description) {
		return function () {
			var props = useBlockProps({ className: className + ' akb-inner-block-editor' });
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

	registerBlockType(preventaMetadata, {
		edit: editorPlaceholder(
			'akb-checkout-preventa',
			'Resumen de preventa',
			'El contenido se actualiza automáticamente con el carrito.'
		),
		save: save
	});

	registerBlockType(trustMetadata, {
		edit: editorPlaceholder(
			'akb-checkout-trust',
			'Beneficios de comprar en Akibara',
			'Envío, cuotas y atención personalizada.'
		),
		save: save
	});

	if (typeof registerCheckoutFilters === 'function') {
		registerCheckoutFilters('akibara-checkout-inner-blocks', {
			additionalCartCheckoutInnerBlockTypes: function (defaultValue, extensions, args) {
				if (!args || args.block !== checkoutFields) {
					return defaultValue;
				}

				return Array.from(new Set(
					defaultValue.concat([googleLoginName, preventaName, trustName])
				));
			}
		});
	}
})(window.wp, window.wc);
