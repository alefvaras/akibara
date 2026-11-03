/**
 * Plazo de entrega de Blue Express bajo cada opción de envío (checkout y
 * carrito de bloques).
 *
 * Lee `extensions.akibara_shipping_promise` del store `wc/store/cart` (lo
 * publica `ShippingPromise.php`) y escribe el texto de Blue —«Hasta 2 días
 * hábiles»— bajo la opción cuyo radio tiene ese `rate_id`. No calcula fechas:
 * el texto es el de Blue para esa comuna.
 *
 * Reglas:
 * - Sólo pinta tarifas presentes en el mapa. Una tarifa que sale del mapa
 *   (cambió la comuna, Blue no respondió) pierde su línea.
 * - Si Blue ya pintó su propio `.bluex-delivery-forecast` en esa opción, no
 *   duplica: el día que Blue arregle su plugin, esto se apaga solo.
 * - `pending_stock` → añade que el plazo corre cuando el pedido esté completo.
 * - `typical[rate_id]` (2.2.28) → «normalmente en X días · hasta N días
 *   hábiles». Sólo si X es menor que el N del texto de Blue; si no, el texto de
 *   Blue queda tal cual.
 * - React vuelve a dibujar la lista al cambiar de dirección o de método; un
 *   MutationObserver acotado re-aplica en el siguiente frame.
 */
(function (window, document) {
	'use strict';

	var NAMESPACE = 'akibara_shipping_promise';
	var VERSION = 1;
	var OWN_CLASS = 'akb-shipping-promise';
	var PENDING_SUFFIX = ', desde que tu pedido esté completo';
	var PICKUP_NOTE = 'Coordinación: te contactaremos en menos de 24 horas.';

	function readExtension(cartData) {
		var ext = cartData && cartData.extensions && cartData.extensions[NAMESPACE];
		if (!ext || typeof ext !== 'object' || Number(ext.version) !== VERSION) {
			return null;
		}
		return {
			rates: ext.rates && typeof ext.rates === 'object' ? ext.rates : {},
			typical: ext.typical && typeof ext.typical === 'object' ? ext.typical : {},
			pending: ext.pending_stock === true
		};
	}

	// Máximo de días del texto de Blue: el número tras «hasta» («Desde 5 Hasta
	// 7 días hábiles» → 7) o, si no lo hay, el último número.
	function maxDays(text) {
		var str = String(text || '');
		var m = str.match(/hasta\s+(\d+)/i);
		var all = str.match(/\d+/g);
		var n = m ? Number(m[1]) : (all ? Number(all[all.length - 1]) : 0);
		return n > 0 && n <= 60 ? n : 0;
	}

	function format(text, pending, typical) {
		var clean = String(text || '').replace(/\s+/g, ' ').trim();
		if (!clean) {
			return '';
		}
		// Blue escribe «Desde 5 Hasta 7 días hábiles»: en medio de la frase van en minúscula.
		var body = clean.replace(/\b(Desde|Hasta)\b/g, function (w) {
			return w.toLowerCase();
		});
		body = body.charAt(0).toLowerCase() + body.slice(1);
		var usual = Number(typical);
		var max = maxDays(clean);
		// Si Blue ya da un rango («desde … hasta …»), no se añade lo habitual.
		if (max && !/desde/i.test(clean) && usual >= 1 && usual === Math.floor(usual) && usual < max) {
			body = 'normalmente en ' + usual + (usual === 1 ? ' día' : ' días') + ' · ' + body;
		}
		var sentence = 'Entrega: ' + body;
		return pending ? sentence + PENDING_SUFFIX : sentence;
	}

	function optionFor(input) {
		if (input.closest) {
			return input.closest('.wc-block-components-radio-control__option') || input.parentNode;
		}
		return input.parentNode;
	}

	function apply(root, state) {
		if (!root || !root.querySelectorAll) {
			return 0;
		}
		var rates = state ? state.rates : {};
		var typical = state && state.typical ? state.typical : {};
		var pending = state ? state.pending : false;
		var painted = 0;
		var inputs = root.querySelectorAll('input[type="radio"]');

		for (var i = 0; i < inputs.length; i++) {
			var input = inputs[i];
			var option = optionFor(input);
			if (!option) {
				continue;
			}
			var own = option.querySelector('.' + OWN_CLASS);
			var isPickup = input.value && (input.value.indexOf('local_pickup') === 0 || input.value.indexOf('pickup_location') === 0);
			var text = '';
			if (isPickup) {
				text = PICKUP_NOTE;
			} else if (Object.prototype.hasOwnProperty.call(rates, input.value)) {
				text = format(
					rates[input.value],
					pending,
					Object.prototype.hasOwnProperty.call(typical, input.value) ? typical[input.value] : null
				);
			}
			var vendor = option.querySelector('.bluex-delivery-forecast:not(.' + OWN_CLASS + ')');

			if (!text || vendor) {
				if (own && own.parentNode) {
					own.parentNode.removeChild(own);
				}
				continue;
			}

			if (!own) {
				own = document.createElement('span');
				// `bluex-delivery-forecast` hereda el estilo ya aprobado en
				// cart-checkout-blocks.css (amarillo de marca, 13 px, AA sobre negro).
				own.className = OWN_CLASS + ' bluex-delivery-forecast';
				own.style.display = 'block';
				var host = option.querySelector('.wc-block-components-radio-control__option-layout') || option;
				host.appendChild(own);
			}
			if (own.textContent !== text) {
				own.textContent = text;
			}
			painted++;
		}
		return painted;
	}

	function currentState() {
		var data = window.wp && window.wp.data;
		if (!data || !data.select) {
			return null;
		}
		var store = data.select('wc/store/cart');
		if (!store || typeof store.getCartData !== 'function') {
			return null;
		}
		return readExtension(store.getCartData());
	}

	function roots() {
		return document.querySelectorAll(
			'.wc-block-components-shipping-rates-control, .wc-block-components-totals-shipping, .wc-block-components-local-pickup-select'
		);
	}

	var scheduled = false;
	function refresh() {
		if (scheduled) {
			return;
		}
		scheduled = true;
		var run = function () {
			scheduled = false;
			var state = currentState();
			var list = roots();
			for (var i = 0; i < list.length; i++) {
				apply(list[i], state);
			}
		};
		if (window.requestAnimationFrame) {
			window.requestAnimationFrame(run);
		} else {
			window.setTimeout(run, 16);
		}
	}

	function boot() {
		var data = window.wp && window.wp.data;
		if (data && data.subscribe) {
			data.subscribe(refresh);
		}
		if (window.MutationObserver && document.body) {
			var observer = new window.MutationObserver(function (mutations) {
				for (var i = 0; i < mutations.length; i++) {
					var target = mutations[i].target;
					if (target && target.classList && target.classList.contains(OWN_CLASS)) {
						continue;
					}
					refresh();
					return;
				}
			});
			observer.observe(document.body, { childList: true, subtree: true });
		}
		refresh();
	}

	window.akbShippingPromise = { readExtension: readExtension, format: format, apply: apply };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})(window, document);
