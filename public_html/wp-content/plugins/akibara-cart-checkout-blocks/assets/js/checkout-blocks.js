(function (wp) {
	'use strict';

	const legacyAddressStorageKey = 'akb_checkout_blocks_address';
	let accessibilityQueued = false;

	function purgeAddressStorage(storage) {
		if (!storage) {
			return;
		}

		try {
			storage.removeItem(legacyAddressStorageKey);
		} catch (_error) {
			// Browser storage is optional and must never block checkout.
		}
	}

	function purgeLegacyAddressCaches() {
		try {
			purgeAddressStorage(window.localStorage);
		} catch (_error) {
			// Access to browser storage may be disabled.
		}

		try {
			purgeAddressStorage(window.sessionStorage);
		} catch (_error) {
			// Access to browser storage may be disabled.
		}
	}

	function enhancePaymentAccessibility() {
		const walletButton = document.getElementById('mp-wallet-button');
		if (walletButton && !walletButton.getAttribute('aria-label')) {
			walletButton.setAttribute('aria-label', 'Pagar con Mercado Pago');
		}

		document.querySelectorAll('.row-image-select img:not([alt])').forEach(function (image) {
			image.setAttribute('alt', '');
		});

		const frameTitles = {
			cardNumber: 'Número de tarjeta',
			expirationDate: 'Fecha de vencimiento',
			securityCode: 'Código de seguridad'
		};
		Object.keys(frameTitles).forEach(function (name) {
			const frame = document.querySelector('iframe[name="' + name + '"]');
			if (frame && !frame.title) {
				// eslint-disable-next-line security/detect-object-injection
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
