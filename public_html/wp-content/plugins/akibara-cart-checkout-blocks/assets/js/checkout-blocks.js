(function (wp) {
	'use strict';

	var legacyAddressStorageKey = 'akb_checkout_blocks_address';
	var accessibilityQueued = false;

	function purgeAddressStorage(storage) {
		if (!storage) {
			return;
		}

		try {
			storage.removeItem(legacyAddressStorageKey);
		} catch (error) {
			// Browser storage is optional and must never block checkout.
		}
	}

	function purgeLegacyAddressCaches() {
		try {
			purgeAddressStorage(window.localStorage);
		} catch (error) {
			// Access to browser storage may be disabled.
		}

		try {
			purgeAddressStorage(window.sessionStorage);
		} catch (error) {
			// Access to browser storage may be disabled.
		}
	}

	function enhancePaymentAccessibility() {
		var walletButton = document.getElementById('mp-wallet-button');
		if (walletButton && !walletButton.getAttribute('aria-label')) {
			walletButton.setAttribute('aria-label', 'Pagar con Mercado Pago');
		}

		document.querySelectorAll('.row-image-select img:not([alt])').forEach(function (image) {
			image.setAttribute('alt', '');
		});

		var frameTitles = {
			cardNumber: 'Número de tarjeta',
			expirationDate: 'Fecha de vencimiento',
			securityCode: 'Código de seguridad'
		};
		Object.keys(frameTitles).forEach(function (name) {
			var frame = document.querySelector('iframe[name="' + name + '"]');
			if (frame && !frame.title) {
				frame.title = frameTitles[name];
			}
		});
	}

	function queuePaymentAccessibility() {
		if (accessibilityQueued) {
			return;
		}

		accessibilityQueued = true;
		window.requestAnimationFrame(function () {
			accessibilityQueued = false;
			enhancePaymentAccessibility();
		});
	}

	function initialize() {
		purgeLegacyAddressCaches();
		enhancePaymentAccessibility();

		if (wp && wp.data && typeof wp.data.subscribe === 'function') {
			wp.data.subscribe(queuePaymentAccessibility);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize, { once: true });
	} else {
		initialize();
	}
})(window.wp);
