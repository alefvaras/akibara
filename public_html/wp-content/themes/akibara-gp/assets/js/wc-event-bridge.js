/*!
 * Akibara GP — wc-event-bridge.js
 *
 * Shim puente jQuery → CustomEvent. WooCommerce clásico dispara sus eventos con
 * `jQuery(document.body).trigger('added_to_cart', ...)`; un `addEventListener`
 * nativo NO los oye (jQuery no usa el bus de eventos del DOM). Este shim escucha
 * esos eventos jQuery una sola vez y los re-emite como CustomEvent nativos
 * `akb:<tipo>` sobre `document.body`, de modo que el resto del theme puede
 * reaccionar con `addEventListener` sin cargar jQuery en cada archivo.
 *
 * Los argumentos que jQuery pasa como parámetros del handler (p. ej.
 * `[fragments, cart_hash, $button]` en `added_to_cart`) viajan en `e.detail`
 * como array, en el mismo orden.
 *
 * NO re-emite `should_send_ajax_request.adding_to_cart`: ese es un FILTRO cuyo
 * valor de retorno lee WC vía `triggerHandler` (woocommerce/.../add-to-cart.js).
 * Un CustomEvent no tiene canal de retorno, así que ese contrato se queda en
 * jQuery (lo maneja atc-guard.js a propósito). Igual que pdp-ajax-atc.js sigue
 * DISPARANDO `added_to_cart` en jQuery porque el mini-cart de WC lo escucha por
 * jQuery.
 *
 * Integración con WooCommerce Blocks: En entornos donde el carrito o checkout
 * operan con bloques Gutenberg, los eventos clásicos basados en formularios
 * tradicionales no se disparan y se consultan vía Store API / wp.data. Este puente
 * re-emite los eventos AJAX de catálogo que sí continúan activos: `added_to_cart`,
 * `removed_from_cart`, `wc_fragments_refreshed` y `wc_fragments_loaded`.
 *
 * Caveat honesto: esto NO saca jQuery del sitio — WC core lo sigue cargando.
 * La ganancia es mantenibilidad (un solo punto de jQuery en el theme), no payload.
 *
 * @package akibara-gp
 */
(function () {
	'use strict';

	if (!window.jQuery) {
		return;
	}

	// Eventos de WC que el theme necesita oír en vanilla y que ALGÚN fichero de
	// WooCommerce dispara hoy en este sitio. Se re-emiten como `akb:<tipo>`
	// (mismo nombre base, sin namespace jQuery).
	var events = [
		'added_to_cart',
		'removed_from_cart',
		'wc_fragments_refreshed',
		'wc_fragments_loaded'
	].join(' ');

	window.jQuery(document.body).on(events, function (e) {
		// arguments[0] es el jQuery.Event; el resto son los datos del trigger.
		var args = Array.prototype.slice.call(arguments, 1);
		document.body.dispatchEvent(
			new CustomEvent('akb:' + e.type, { detail: args })
		);
	});
})();
