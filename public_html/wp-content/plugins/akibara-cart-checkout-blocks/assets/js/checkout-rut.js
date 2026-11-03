(function (wp) {
	'use strict';
	/* La validación de RUT comparte algoritmo y mensaje con RutValidator y CheckoutFields::validate_rut. ValidatedTextInput limpia setCustomValidity antes de checkValidity; el focusout posterior de document publica el error en wc/store/validation e impide el envío. El id del input y la clave del store se resuelven por separado. */
	var RUT_INPUT_ID = 'contact-akibara-rut';
	var RUT_STORE_KEY = 'contact_akibara/rut';
	var RUT_MESSAGE = 'El RUT ingresado no es válido. Verifica el formato y el dígito verificador.';
	function cleanRut(value) {
		return String(value || '').replace(/[\s.\-]/g, '').toUpperCase();
	}
	function rutCheckDigit(body) {
		var sum = 0;
		var factor = 2;
		for (var i = body.length - 1; i >= 0; i--) {
			sum += parseInt(body.charAt(i), 10) * factor;
			factor = factor === 7 ? 2 : factor + 1;
		}
		var remainder = 11 - (sum % 11);
		if (remainder === 11) {
			return '0';
		}
		return remainder === 10 ? 'K' : String(remainder);
	}
	function isValidRut(value) {
		var clean = cleanRut(value);
		if (clean.length < 7 || clean.length > 10) {
			return false;
		}
		var body = clean.slice(0, -1);
		var given = clean.slice(-1);
		if (!/^\d+$/.test(body) || /^(\d)\1+$/.test(body) || !/^[0-9K]$/.test(given)) {
			return false;
		}
		return rutCheckDigit(body) === given;
	}
	function validationStore() {
		try {
			if (wp && wp.data && typeof wp.data.dispatch === 'function') {
				return wp.data.dispatch('wc/store/validation') || null;
			}
		} catch (error) {
			// Sin store no hay validación en cliente; el servidor sigue siendo el juez.
		}
		return null;
	}
	function validateRutField(input) {
		var store = validationStore();
		if (!store || !input) {
			return null;
		}
		var value = String(input.value || '').trim();
		if (value === '') {
			// «Obligatorio» lo gestiona Blocks con su propio mensaje.
			return null;
		}
		if (isValidRut(value)) {
			if (typeof store.clearValidationError === 'function') {
				store.clearValidationError(RUT_STORE_KEY);
			}
			return true;
		}
		if (typeof store.setValidationErrors === 'function') {
			var errors = {};
			errors[RUT_STORE_KEY] = { message: RUT_MESSAGE, hidden: false };
			store.setValidationErrors(errors);
		}
		return false;
	}
	function handleRutFocusOut(event) {
		var target = event && event.target;
		if (target && target.id === RUT_INPUT_ID) {
			validateRutField(target);
		}
	}
	window.akbCheckoutRut = {
		clean: cleanRut,
		checkDigit: rutCheckDigit,
		isValid: isValidRut,
		validateField: validateRutField
	};
	function initialize() {
		document.addEventListener('focusout', handleRutFocusOut);
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize, { once: true });
	} else {
		initialize();
	}
})(window.wp);
