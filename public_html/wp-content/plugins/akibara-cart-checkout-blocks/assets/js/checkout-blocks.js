(function (wp) {
	'use strict';

	const legacyAddressStorageKey = 'akb_checkout_blocks_address';
	let accessibilityQueued = false;

	function warnCheckoutIssue(context, error) {
		if (typeof window !== 'undefined' && window.console && typeof window.console.warn === 'function') {
			window.console.warn('akb-checkout-blocks: ' + context + ':', error);
		}
	}

	function removeLegacyStorageEntry(storageName, resolveStorage) {
		try {
			const storage = resolveStorage();
			if (storage && typeof storage.removeItem === 'function') {
				storage.removeItem(legacyAddressStorageKey);
			}
		} catch (error) {
			warnCheckoutIssue('no se pudo limpiar caché obsoleta en ' + storageName, error);
		}
	}

	function purgeLegacyAddressCaches() {
		removeLegacyStorageEntry('localStorage', function () {
			return window.localStorage;
		});
		removeLegacyStorageEntry('sessionStorage', function () {
			return window.sessionStorage;
		});
	}

	function enhancePaymentAccessibility() {
		const walletButton = document.getElementById('mp-wallet-button');
		if (walletButton && !walletButton.getAttribute('aria-label')) {
			walletButton.setAttribute('aria-label', 'Pagar con Mercado Pago');
		}

		document.querySelectorAll('.row-image-select img:not([alt])').forEach(function (image) {
			image.setAttribute('alt', '');
		});

		const frameTitles = [
			['cardNumber', 'Número de tarjeta'],
			['expirationDate', 'Fecha de vencimiento'],
			['securityCode', 'Código de seguridad']
		];
		frameTitles.forEach(function (entry) {
			const name = entry[0];
			const title = entry[1];
			const frame = document.querySelector('iframe[name="' + name + '"]');
			if (frame && !frame.title) {
				frame.title = title;
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
