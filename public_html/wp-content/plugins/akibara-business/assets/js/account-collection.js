/**
 * Akibara Account Collection — Marcar/desmarcar tomos en estantería personal.
 *
 * @package Akibara\Business
 */

(function () {
	'use strict';

	if (typeof window.akbAccountCollection !== 'object') {
		return;
	}

	var cfg = window.akbAccountCollection;

	function handleMarkClick(e) {
		var btn = e.target.closest('.akb-account-collection__mark-owned, .akb-account-collection__unmark-owned');
		if (!btn) {
			return;
		}
		e.preventDefault();

		if (btn.getAttribute('aria-disabled') === 'true') {
			return;
		}

		var productId = parseInt(btn.getAttribute('data-product-id'), 10);
		var action = btn.getAttribute('data-action') || 'add';
		var nonce = btn.getAttribute('data-nonce') || cfg.nonce;

		if (!productId) {
			return;
		}

		btn.setAttribute('aria-disabled', 'true');
		var origText = btn.textContent;
		btn.textContent = action === 'add' ? 'Añadiendo a tu estantería…' : 'Desmarcando…';

		var body = new URLSearchParams();
		body.set('action', 'akb_collection_mark_owned');
		body.set('nonce', nonce);
		body.set('product_id', String(productId));
		body.set('mark_action', action);

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		})
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (json && json.success) {
					btn.textContent = action === 'add' ? '✓ En tu estantería' : '✓ Desmarcado';
					try {
						if (window.sessionStorage) {
							for (var i = 0; i < window.sessionStorage.length; i++) {
								var k = window.sessionStorage.key(i);
								if (k && k.indexOf('akb_collection_owned_v1_') === 0) {
									window.sessionStorage.removeItem(k);
								}
							}
						}
					} catch (e) {}
					window.location.reload();
				} else {
					btn.removeAttribute('aria-disabled');
					btn.textContent = origText;
					alert(json && json.data && json.data.message ? json.data.message : 'No se pudo actualizar.');
				}
			})
			.catch(function () {
				btn.removeAttribute('aria-disabled');
				btn.textContent = origText;
				alert('Error de conexión al actualizar.');
			});
	}

	document.addEventListener('click', handleMarkClick);
})();
