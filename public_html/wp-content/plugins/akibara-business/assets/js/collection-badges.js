/**
 * CollectionBadges — Hidratación universal en cliente de la colección del usuario.
 *
 * Libera "✓ En tu colección" para que se vea en:
 * - Catálogo general (/catalogo/)
 * - Búsquedas y filtros facetados (Akibara Search)
 * - Archivos de editorial y categoría
 * - Ficha de producto (PDP) independientemente de SeriesBundles
 *
 * 100% cache-safe con LiteSpeed y Cloudflare: no inyecta datos privados en HTML público.
 */

(function () {
	'use strict';

	var cfg = window.akbCollectionBadges;
	if (!cfg || !cfg.isLogged || !cfg.userId) {
		// Usuario invitado: limpiar cualquier residuo de sesión previa
		try {
			if (window.sessionStorage && cfg && cfg.storageKey) {
				window.sessionStorage.removeItem(cfg.storageKey);
			}
		} catch (e) {}
		return;
	}

	// Si el usuario acaba de completar una compra, invalidamos la caché de sesión
	// para que el catálogo refleje de inmediato los nuevos tomos comprados.
	if (cfg.isOrderReceived) {
		try {
			if (window.sessionStorage && cfg.storageKey) {
				window.sessionStorage.removeItem(cfg.storageKey);
			}
		} catch (e) {}
	}

	var currentOwnedIds = [];

	function readCached() {
		try {
			if (!window.sessionStorage) {
				return null;
			}
			var raw = window.sessionStorage.getItem(cfg.storageKey);
			return raw ? JSON.parse(raw) : null;
		} catch (e) {
			return null;
		}
	}

	function writeCached(ids) {
		try {
			if (window.sessionStorage) {
				window.sessionStorage.setItem(cfg.storageKey, JSON.stringify(ids));
			}
		} catch (e) {}
	}

	function fetchOwned(callback, retried) {
		var body = new URLSearchParams();
		body.set('action', cfg.action);
		body.set('nonce', cfg.nonce);

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (json && !json.success && json.data && json.data.code === 'stale_nonce' && json.data.nonce && !retried) {
					cfg.nonce = json.data.nonce;
					fetchOwned(callback, true);
					return;
				}
				if (json && json.success && json.data && Array.isArray(json.data.owned_ids)) {
					var ids = json.data.owned_ids.map(function (id) { return parseInt(id, 10); });
					currentOwnedIds = ids;
					writeCached(ids);
					callback(ids);
				}
			})
			.catch(function () {});
	}

	function extractProductId(card) {
		// 1. Selector de wishlist (anclaje universal en Akibara)
		var wlToggle = card.querySelector('.akb-wl-toggle[data-product-id]');
		if (wlToggle) {
			var pid = parseInt(wlToggle.getAttribute('data-product-id'), 10);
			if (pid > 0) return pid;
		}

		// 2. data-product_id en botón o card
		var dataEl = card.querySelector('[data-product_id], [data-product-id]');
		if (dataEl) {
			var dpid = parseInt(dataEl.getAttribute('data-product_id') || dataEl.getAttribute('data-product-id'), 10);
			if (dpid > 0) return dpid;
		}

		// 3. Clase estándar de WordPress post-{ID}
		var match = card.className ? card.className.match(/\bpost-(\d+)\b/) : null;
		if (match && match[1]) {
			return parseInt(match[1], 10);
		}

		return 0;
	}

	function callMarkAjax(productId, action, btn, callback, retried) {
		if (!productId || !cfg.markNonce) return;

		var markAction = cfg.markAction || 'akb_collection_mark_owned';
		var body = new URLSearchParams();
		body.set('action', markAction);
		body.set('nonce', cfg.markNonce);
		body.set('product_id', String(productId));
		body.set('mark_action', action);

		var origText = btn ? btn.textContent : '';
		if (btn) {
			btn.disabled = true;
			btn.textContent = action === 'add' ? 'Añadiendo a tu estantería…' : 'Quitando…';
		}

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		})
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (json && !json.success && json.data && json.data.code === 'stale_nonce' && json.data.nonce && !retried) {
					cfg.markNonce = json.data.nonce;
					callMarkAjax(productId, action, btn, callback, true);
					return;
				}
				if (json && json.success) {
					if (callback) callback(null, json.data);
				} else {
					if (btn) {
						btn.disabled = false;
						btn.textContent = origText;
					}
					var err = (json && json.data && json.data.message) ? json.data.message : 'No se pudo actualizar.';
					alert(err);
				}
			})
			.catch(function () {
				if (btn) {
					btn.disabled = false;
					btn.textContent = origText;
				}
				alert('Error de conexión al actualizar.');
			});
	}

	function hydrate(ownedIds) {
		currentOwnedIds = ownedIds || [];
		var lookup = {};
		currentOwnedIds.forEach(function (id) {
			if (id > 0) lookup[id] = true;
		});

		// A. Hidratar rejillas de productos (Loop / Catálogo / Búsqueda)
		var cards = document.querySelectorAll('.products li.product, li.product');
		cards.forEach(function (card) {
			var pid = extractProductId(card);
			if (pid <= 0) return;

			var isOwned = !!lookup[pid];

			if (isOwned) {
				card.classList.add('akb-product-is-owned');

				// Si la tarjeta ya tiene el badge específico de SeriesBundles, no duplicar
				if (card.querySelector('.akb-sb-card-owned')) {
					return;
				}

				var badge = card.querySelector('.akb-card-collection-badge');
				if (!badge) {
					badge = document.createElement('span');
					badge.className = 'akb-card-collection-badge';
					badge.textContent = (cfg.labels && cfg.labels.badge) || '✓ En tu colección';

					var anchor = card.querySelector('.akb-wl-anchor') || card.querySelector('.akb-product-cover-wrap') || card.querySelector('.woocommerce-LoopProduct-link') || card;
					anchor.appendChild(badge);
				}
			} else {
				card.classList.remove('akb-product-is-owned');
				var existing = card.querySelector('.akb-card-collection-badge');
				if (existing) {
					existing.remove();
				}
			}
		});

		// B. Hidratar Ficha de Producto (PDP)
		var pdpBanner = document.querySelector('.akb-pdp-collection-banner[data-akb-pdp-collection-product]');
		var pdpShelve = document.querySelector('.akb-pdp-shelve-action[data-akb-pdp-shelve-product]');

		var pdpId = 0;
		if (pdpBanner) {
			pdpId = parseInt(pdpBanner.getAttribute('data-akb-pdp-collection-product'), 10);
		} else if (pdpShelve) {
			pdpId = parseInt(pdpShelve.getAttribute('data-akb-pdp-shelve-product'), 10);
		}

		if (pdpId > 0) {
			var isOwned = !!lookup[pdpId];

			if (isOwned) {
				if (pdpBanner) {
					pdpBanner.innerHTML = '<span class="akb-badge-collection">' +
						((cfg.labels && cfg.labels.badge) || '✓ En tu colección') +
						'</span> <span class="akb-pdp-collection-hint">' +
						((cfg.labels && cfg.labels.pdpNotice) || 'Ya tienes este tomo en tu estantería.') +
						'</span> <span class="akb-pdp-divider">·</span> <button type="button" class="akb-pdp-unmark-btn" data-product-id="' +
						pdpId + '">' +
						((cfg.labels && cfg.labels.unmarkAction) || '✕ Quitar de mi estantería') +
						'</button>';
					pdpBanner.hidden = false;
					pdpBanner.removeAttribute('hidden');
				}
				if (pdpShelve) {
					pdpShelve.innerHTML = '';
					pdpShelve.hidden = true;
					pdpShelve.setAttribute('hidden', '');
				}
			} else {
				if (pdpBanner) {
					pdpBanner.innerHTML = '';
					pdpBanner.hidden = true;
					pdpBanner.setAttribute('hidden', '');
				}
				if (pdpShelve) {
					pdpShelve.innerHTML = '<div class="akb-pdp-shelve-wrap">' +
						'<span class="akb-pdp-shelve-text">' +
						((cfg.labels && cfg.labels.shelvePrompt) || '📚 ¿Ya tienes este tomo en casa?') +
						'</span> <button type="button" class="akb-pdp-shelve-btn" data-product-id="' +
						pdpId + '">' +
						((cfg.labels && cfg.labels.shelveAction) || 'Márcalo en tu estantería') +
						'</button></div>';
					pdpShelve.hidden = false;
					pdpShelve.removeAttribute('hidden');
				}
			}
		}
	}

	function sync() {
		var cached = readCached();
		if (cached && Array.isArray(cached)) {
			currentOwnedIds = cached;
			hydrate(cached);
		} else {
			fetchOwned(function (ids) {
				hydrate(ids);
			});
		}
	}

	// Manejo de eventos en la PDP
	document.addEventListener('click', function (e) {
		var shelveBtn = e.target.closest('.akb-pdp-shelve-btn');
		if (shelveBtn) {
			e.preventDefault();
			var pid = parseInt(shelveBtn.getAttribute('data-product-id'), 10);
			if (!pid) return;

			callMarkAjax(pid, 'add', shelveBtn, function (err) {
				if (!err) {
					var nextIds = currentOwnedIds.slice();
					if (nextIds.indexOf(pid) === -1) {
						nextIds.push(pid);
						nextIds.sort(function (a, b) { return a - b; });
					}
					writeCached(nextIds);
					hydrate(nextIds);
					try {
						window.dispatchEvent(new CustomEvent('akb:collection:updated', { detail: { owned_ids: nextIds } }));
					} catch (evErr) {}
				}
			});
			return;
		}

		var unmarkBtn = e.target.closest('.akb-pdp-unmark-btn');
		if (unmarkBtn) {
			e.preventDefault();
			var pid = parseInt(unmarkBtn.getAttribute('data-product-id'), 10);
			if (!pid) return;

			callMarkAjax(pid, 'remove', unmarkBtn, function (err) {
				if (!err) {
					var nextIds = currentOwnedIds.filter(function (id) { return id !== pid; });
					writeCached(nextIds);
					hydrate(nextIds);
					try {
						window.dispatchEvent(new CustomEvent('akb:collection:updated', { detail: { owned_ids: nextIds } }));
					} catch (evErr) {}
				}
			});
		}
	});

	// Escuchar eventos de cambio dinámico
	document.addEventListener('akb:search:swapped', function () {
		var cached = readCached();
		if (cached) {
			hydrate(cached);
		} else {
			sync();
		}
	});

	// Si el usuario marca o desmarca en otra pestaña o en su cuenta
	window.addEventListener('akb:collection:updated', function (e) {
		if (e.detail && Array.isArray(e.detail.owned_ids)) {
			writeCached(e.detail.owned_ids);
			hydrate(e.detail.owned_ids);
		} else {
			try {
				window.sessionStorage.removeItem(cfg.storageKey);
			} catch (err) {}
			sync();
		}
	});

	if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
		window.addEventListener('pageshow', sync);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', sync);
	} else {
		sync();
	}
})();
