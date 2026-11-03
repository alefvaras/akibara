/**
 * akibara-forms.js — vanilla submit handler para [akb_form_contacto].
 *
 * Hookea cualquier <form data-akb-form="..."> al endpoint REST
 * /wp-json/akibara/v1/{kind}, muestra feedback inline, marca el botón como
 * no disponible mientras procesa (sin soltar el foco) y limpia el form tras
 * submit exitoso.
 *
 * Config inyectada por wp_localize_script: AkibaraFormsConfig.
 */

(function () {
	'use strict';

	if (typeof window.AkibaraFormsConfig !== 'object') {
		return;
	}

	const CONFIG = window.AkibaraFormsConfig;

	// Mejora progresiva: novalidate se aplica dinámicamente con JS activo para
	// gestionar la validación accesible en cliente. Si el script no se ejecuta,
	// el navegador aplica su validación nativa HTML5 por defecto.
	document.querySelectorAll('[data-akb-form]').forEach(function (form) {
		form.setAttribute('novalidate', '');
	});

	/*
	 * Validación accesible en el navegador campo por campo.
	 *
	 * Previene el envío si hay campos inválidos, asignando aria-invalid y
	 * aria-describedby al mensaje específico correspondiente y enfocando el primer campo con error.
	 * Los mensajes se obtienen de AkibaraFormsConfig.messages por clave de ValidityState,
	 * con fallback a field.validationMessage etiquetado con lang del navegador.
	 */
	const VALIDITY_KEYS = [ 'valueMissing', 'typeMismatch', 'badInput', 'patternMismatch', 'tooShort', 'tooLong', 'rangeUnderflow', 'rangeOverflow', 'stepMismatch' ];

	function messageFor(field) {
		const own = (CONFIG.messages && typeof CONFIG.messages === 'object') ? CONFIG.messages : {};
		const validity = field.validity || {};
		for (let i = 0; i < VALIDITY_KEYS.length; i++) {
			const key = VALIDITY_KEYS[i];
			if (validity[key]) {
				const text = typeof own[key] === 'string' ? own[key].trim() : '';
				if (text !== '') return { text: text, lang: '' };
				break;
			}
		}
		const lang = (typeof navigator === 'object' && navigator && typeof navigator.language === 'string') ? navigator.language : '';
		return { text: field.validationMessage, lang: lang };
	}

	function errorIdFor(field) {
		return (field.id || field.name) + '-error';
	}

	function describedByTokens(field) {
		return (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
	}

	/**
	 * @param {HTMLElement} field
	 * @param {{text: string, lang: string}} [given] Mensaje ya decidido (un
	 *        error del servidor); sin él, el del motivo de ValidityState.
	 */
	function showFieldError(field, given) {
		const id = errorIdFor(field);
		let msg = document.getElementById(id);
		if (!msg) {
			// <span> y no <p>: el campo ya vive dentro de un <p class="form-row">.
			msg = document.createElement('span');
			msg.id = id;
			msg.className = 'akb-form__error';
			field.insertAdjacentElement('afterend', msg);
		}
		const message = given || messageFor(field);
		msg.textContent = message.text;
		if (message.lang) {
			msg.setAttribute('lang', message.lang);
		} else {
			msg.removeAttribute('lang');
		}
		field.setAttribute('aria-invalid', 'true');
		const tokens = describedByTokens(field);
		if (tokens.indexOf(id) === -1) {
			tokens.push(id);
			field.setAttribute('aria-describedby', tokens.join(' '));
		}
	}

	function clearFieldError(field) {
		const id = errorIdFor(field);
		const msg = document.getElementById(id);
		if (msg) msg.remove();
		field.removeAttribute('aria-invalid');
		const tokens = describedByTokens(field).filter(function (t) { return t !== id; });
		if (tokens.length) {
			field.setAttribute('aria-describedby', tokens.join(' '));
		} else {
			field.removeAttribute('aria-describedby');
		}
	}

	/** @returns {boolean} true si se puede enviar. */
	function validateForm(form) {
		let firstInvalid = null;
		form.querySelectorAll('input, textarea, select').forEach(function (field) {
			if (!field.willValidate) return;
			if (field.checkValidity()) {
				clearFieldError(field);
				return;
			}
			showFieldError(field);
			if (!firstInvalid) firstInvalid = field;
		});
		if (firstInvalid) {
			firstInvalid.focus();
			return false;
		}
		return true;
	}

	// Un campo ya marcado se re-evalúa mientras el cliente escribe: el mensaje
	// se va en cuanto el dato es válido (o cambia al nuevo motivo).
	document.addEventListener('input', function (event) {
		const field = event.target;
		if (!field || !field.form || !field.form.matches('[data-akb-form]')) return;
		if (field.getAttribute('aria-invalid') !== 'true') return;
		if (field.checkValidity()) {
			clearFieldError(field);
		} else {
			showFieldError(field);
		}
	});

	/*
	 * Mapeo de errores de validación del servidor al campo específico.
	 *
	 * Permite que validaciones semánticas del backend (formato de correo con dominio completo,
	 * cadenas con sólo espacios en blanco) se visualicen directamente asociadas a su input
	 * correspondiente con aria-invalid, mensaje localizado y foco de teclado accesible.
	 *
	 * Código del servidor → qué campos señala y con qué motivo de ValidityState.
	 */
	const SERVER_FIELD_ERRORS = {
		invalid_email: {
			key: 'typeMismatch',
			fields: function (form) {
				return Array.prototype.slice.call(form.querySelectorAll('input[type="email"]'));
			}
		},
		missing_fields: {
			key: 'valueMissing',
			fields: function (form) {
				return Array.prototype.filter.call(form.querySelectorAll('[required]'), function (field) {
					return typeof field.value === 'string' && field.value.trim() === '';
				});
			}
		}
	};

	/** @returns {boolean} true si el error del servidor quedó en sus campos. */
	function showServerFieldErrors(form, code, serverText) {
		const rule = Object.prototype.hasOwnProperty.call(SERVER_FIELD_ERRORS, code) ? SERVER_FIELD_ERRORS[code] : null;
		if (!rule) return false;
		const fields = rule.fields(form);
		if (!fields.length) return false;
		const own = (CONFIG.messages && typeof CONFIG.messages === 'object' && typeof CONFIG.messages[rule.key] === 'string') ? CONFIG.messages[rule.key].trim() : '';
		const text = own !== '' ? own : serverText;
		if (!text) return false;
		fields.forEach(function (field) {
			showFieldError(field, { text: text, lang: '' });
		});
		fields[0].focus();
		return true;
	}

	function setFeedback(form, message, kind) {
		const fb = form.querySelector('.akb-form__feedback');
		if (!fb) return;
		fb.textContent = message;
		fb.dataset.state = kind;
	}

	/*
	 * Estado de envío: el botón utiliza `aria-disabled` para preservar el foco
	 * activo de accesibilidad/teclado. `isSubmitting()` previene envíos concurrentes.
	 */
	function setSubmitting(form, submitting) {
		form.dataset.akbSending = submitting ? '1' : '';
		const btn = form.querySelector('.akb-form__submit');
		if (!btn) return;
		if (submitting) {
			btn.setAttribute('aria-disabled', 'true');
		} else {
			btn.removeAttribute('aria-disabled');
		}
		btn.dataset.loading = submitting ? '1' : '';
	}

	function isSubmitting(form) {
		return form.dataset.akbSending === '1';
	}

	async function submitForm(form) {
		const kind = form.dataset.akbForm;
		if (!kind) return;

		setFeedback(form, '', '');
		if (!validateForm(form)) return;

		const data = Object.fromEntries(new FormData(form).entries());

		setSubmitting(form, true);

		try {
			// El nonce se manda SOLO si el servidor lo entregó. Estos endpoints
			// son públicos y la página que los aloja se sirve desde caché, así
			// que un nonce horneado llega caducado a las pocas horas y WordPress
			// corta con 403 antes del handler. Enviar la cabecera vacía o con el
			// literal 'undefined' provoca exactamente ese 403.
			const headers = { 'Content-Type': 'application/json' };
			if (typeof CONFIG.nonce === 'string' && CONFIG.nonce !== '') {
				headers['X-WP-Nonce'] = CONFIG.nonce;
			}

			const response = await fetch(CONFIG.restUrl + kind, {
				method: 'POST',
				headers: headers,
				body: JSON.stringify(data)
			});

			const json = await response.json().catch(() => ({}));

			if (!response.ok) {
				// Los mensajes propios están escritos para el visitante; los de
				// WordPress no. «Ha fallado la comprobación de la cookie» no le
				// dice a nadie qué hacer, así que no se le muestra.
				const code = (json && typeof json.code === 'string') ? json.code : '';
				const own = (json && json.message) && code.indexOf('rest_') !== 0;
				if (own && showServerFieldErrors(form, code, String(json.message))) {
					return;
				}
				setFeedback(form, own ? json.message : 'No pudimos enviar el formulario. Intenta de nuevo o escríbenos a contacto@akibara.cl.', 'error');
				return;
			}

			setFeedback(form, json.message || 'Recibido. Te respondemos a la brevedad.', 'success');
			form.reset();
		} catch (err) {
			setFeedback(form, 'Error de red. Verifica tu conexión.', 'error');
		} finally {
			setSubmitting(form, false);
		}
	}

	document.addEventListener('submit', function (event) {
		const form = event.target;
		if (!(form instanceof HTMLFormElement)) return;
		if (!form.matches('[data-akb-form]')) return;
		event.preventDefault();
		if (isSubmitting(form)) return;
		submitForm(form);
	});
})();
