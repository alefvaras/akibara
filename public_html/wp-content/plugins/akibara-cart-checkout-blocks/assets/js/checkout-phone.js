(function (wp) {
	'use strict';
	/* La validación de celular en cliente comparte algoritmo y literales con ChileanPhone y CheckoutFields::validate_billing_phone. El focusout de document corre después del handler de React; wc/store/validation mantiene el error visible e impide el envío aunque la validación HTML5 pase. */
	var MESSAGES = {
		length: 'Ingresa tu número de celular (9 dígitos). Ejemplo: 9 8765 4321.',
		repeated: 'El celular ingresado no parece válido.',
		range: 'El celular no parece válido. Revisa el número.'
	};

	// Gramática aceptada: dígitos, espacios, paréntesis y guiones, con un «+»
	// inicial opcional. Ni guiones al principio o al final, ni dobles, y los
	// paréntesis balanceados, sin anidar y con al menos un dígito dentro.
	//
	// El control de paréntesis faltaba, y por eso «9(87654321» pasaba aquí y el
	// servidor lo rechazaba al pagar: exactamente el viaje de vuelta que este
	// gemelo vino a evitar. Es el bucle de ChileanPhone::has_supported_grammar(),
	// carácter a carácter, con el mismo estado (profundidad y «¿hubo dígito?»).
	function hasSupportedGrammar(phone) {
		if (!/^\+?[0-9\s()\-]+$/.test(phone)) {
			return false;
		}
		var withoutPlus = phone.charAt(0) === '+' ? phone.slice(1) : phone;
		if (
			withoutPlus === '' ||
			withoutPlus.charAt(0) === '-' ||
			withoutPlus.charAt(withoutPlus.length - 1) === '-' ||
			withoutPlus.indexOf('--') !== -1
		) {
			return false;
		}

		var depth = 0;
		var parenthesisDigit = false;
		for (var i = 0; i < withoutPlus.length; i++) {
			var ch = withoutPlus.charAt(i);
			if (ch === '(') {
				if (depth !== 0) {
					return false;
				}
				depth = 1;
				parenthesisDigit = false;
				continue;
			}
			if (ch === ')') {
				if (depth !== 1 || !parenthesisDigit) {
					return false;
				}
				depth = 0;
				continue;
			}
			if (depth === 1 && ch >= '0' && ch <= '9') {
				parenthesisDigit = true;
			}
		}

		return depth === 0;
	}

	// Devuelve los 8 dígitos del celular, o '' si el número no se entiende.
	function extractDigits(value) {
		var phone = String(value || '').trim();
		if (phone === '' || !hasSupportedGrammar(phone)) {
			return '';
		}
		var hasPlus = phone.charAt(0) === '+';
		var clean = phone.replace(/\D/g, '');
		var length = clean.length;
		if (hasPlus) {
			return length === 11 && clean.indexOf('569') === 0 ? clean.slice(3) : '';
		}
		if (length === 8) {
			return clean;
		}
		if (length === 9 && clean.charAt(0) === '9') {
			return clean.slice(1);
		}
		if (length === 11 && clean.indexOf('569') === 0) {
			return clean.slice(3);
		}
		return '';
	}

	// Null cuando el número sirve; si no, el mismo mensaje que devolvería PHP.
	function validationMessage(value) {
		var digits = extractDigits(value);
		if (digits.length !== 8) {
			return MESSAGES.length;
		}
		if (/^(\d)\1+$/.test(digits)) {
			return MESSAGES.repeated;
		}
		if (/^[01]/.test(digits)) {
			return MESSAGES.range;
		}
		return null;
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

	/* La clave de wc/store/validation deriva del id real del input con guiones bajos: billing-phone pasa a billing_phone. Así el error conserva aria-describedby y queda anclado al campo tanto en billing como en shipping. */
	function storeKeyFor(input) {
		var id = String((input && input.id) || '');
		return '' === id ? '' : id.replace(/-/g, '_');
	}

	function validatePhoneField(input) {
		var store = validationStore();
		var key = storeKeyFor(input);
		if (!store || !input || key === '') {
			return null;
		}
		var value = String(input.value || '').trim();
		if (value === '') {
			// «Obligatorio» lo gestiona Blocks con su propio mensaje, y vive en
			// ESTA misma clave: limpiarlo sería quitárselo.
			return null;
		}
		var message = validationMessage(value);
		if (message === null) {
			if (typeof store.clearValidationError === 'function') {
				store.clearValidationError(key);
			}
			return true;
		}
		if (typeof store.setValidationErrors === 'function') {
			var errors = {};
			errors[key] = { message: message, hidden: false };
			store.setValidationErrors(errors);
		}
		return false;
	}

	// El id del input lo pone Blocks y ha cambiado entre versiones, así que no se
	// cablea uno fijo: se reconoce por el sufijo `-phone`, que es de donde sale
	// además la clave del store.
	//
	// `type === 'tel'` a secas NO basta como criterio: marcaría cualquier campo
	// de teléfono del documento —el del bloque de Blue Express, por ejemplo— con
	// un mensaje sobre celulares chilenos y una clave de store que no le
	// corresponde. Por eso, si sólo tenemos el tipo, se exige además que el
	// campo pertenezca a uno de los dos bloques de dirección.
	function isPhoneInput(el) {
		if (!el || el.tagName !== 'INPUT') {
			return false;
		}
		var id = String(el.id || '');
		if (id.slice(-6) === '-phone') {
			return true;
		}

		return el.type === 'tel' && (id.indexOf('billing') === 0 || id.indexOf('shipping') === 0);
	}

	function handlePhoneFocusOut(event) {
		var target = event && event.target;
		if (isPhoneInput(target)) {
			validatePhoneField(target);
		}
	}

	window.akbCheckoutPhone = {
		extractDigits: extractDigits,
		validationMessage: validationMessage,
		validateField: validatePhoneField
	};

	function initialize() {
		document.addEventListener('focusout', handlePhoneFocusOut);
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize, { once: true });
	} else {
		initialize();
	}
})(window.wp);
