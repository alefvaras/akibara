(function (wp) {
	'use strict';
	/* Los errores genéricos de campos vacíos usan la etiqueta visible después de hidratar el país, incluida la sigla RUT. Sólo se reescriben mensajes de la plantilla «El campo %s no es válido.»; se conservan los errores específicos, la visibilidad y el anclaje accesible del campo. */
	let queued = false;

	function template() {
		if (!wp || !wp.i18n || typeof wp.i18n.__ !== 'function') {
			return '';
		}
		// eslint-disable-next-line @wordpress/i18n-translator-comments
		const text = wp.i18n.__('Please enter a valid %s', 'woocommerce');
		return typeof text === 'string' && text.split('%s').length === 2 ? text : '';
	}

	function isTemplateMessage(message, text) {
		if (typeof message !== 'string') {
			return false;
		}
		const parts = text.split('%s');
		return message.length > parts[0].length + parts[1].length
			&& message.indexOf(parts[0]) === 0
			&& message.lastIndexOf(parts[1]) === message.length - parts[1].length;
	}

	// Como Woo, en minúscula; una sigla («RUT») se nombra tal como se ve.
	function nameInMessage(label) {
		const text = String(label || '').trim();
		const isAcronym = text !== ''
			&& text === text.toLocaleUpperCase()
			&& text !== text.toLocaleLowerCase();
		return isAcronym ? text : text.toLocaleLowerCase();
	}

	// La clave del error en `wc/store/validation` es el `name` del campo
	// (`billing_city`, `contact_akibara/rut`). Sin campo con ese nombre (el
	// correo se llama `contact_email` y su error `billing_email`) no se toca.
	function visibleLabel(key) {
		const fields = document.getElementsByName(key);
		for (let i = 0; i < fields.length; i++) {
			// eslint-disable-next-line security/detect-object-injection
			const labels = fields[i].labels;
			if (labels && labels.length && labels[0].textContent) {
				return labels[0].textContent.trim();
			}
		}
		return '';
	}

	function syncMessages() {
		queued = false;
		const text = template();
		if (!text || !wp.data || typeof wp.data.select !== 'function' || typeof wp.data.dispatch !== 'function') {
			return;
		}
		const select = wp.data.select('wc/store/validation');
		const dispatch = wp.data.dispatch('wc/store/validation');
		if (!select || !dispatch || typeof select.getValidationErrors !== 'function' || typeof dispatch.setValidationErrors !== 'function') {
			return;
		}

		const errors = select.getValidationErrors() || {};
		const fixes = {};
		let changed = false;
		Object.keys(errors).forEach(function (key) {
			// eslint-disable-next-line security/detect-object-injection
			const error = errors[key];
			if (!error || !isTemplateMessage(error.message, text)) {
				return;
			}
			const label = visibleLabel(key);
			if (label === '') {
				return;
			}
			const message = text.replace('%s', nameInMessage(label));
			if (message === error.message) {
				return;
			}
			// eslint-disable-next-line security/detect-object-injection
			fixes[key] = { message, hidden: Boolean(error.hidden) };
			changed = true;
		});

		if (changed) {
			dispatch.setValidationErrors(fixes);
		}
	}

	// Un cuadro después del cambio de store: para entonces React ya pintó la
	// etiqueta nueva, y el mensaje corregido sale en ese mismo cuadro.
	function queueSync() {
		if (queued) {
			return;
		}
		queued = true;
		window.requestAnimationFrame(syncMessages);
	}

	function initialize() {
		if (wp && wp.data && typeof wp.data.subscribe === 'function') {
			wp.data.subscribe(queueSync);
		}
		queueSync();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize, { once: true });
	} else {
		initialize();
	}
})(window.wp);
