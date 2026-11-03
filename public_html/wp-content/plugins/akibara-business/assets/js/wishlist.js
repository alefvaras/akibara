/**
 * Akibara Wishlist — toggle + counter update sin jQuery.
 *
 * Eventos custom emitidos en document:
 *   - akb:wishlist:change → detail { state: 'added'|'removed', productId, count }
 *   - akb:wishlist:count  → detail { count }
 *
 * El header listener (theme/main.js) actualiza el badge `[data-akb-wishlist-count]`.
 *
 * @package Akibara\Business
 */

(function () {
	'use strict';

	if (typeof window.akbWishlist !== 'object') {
		return;
	}

	var cfg = window.akbWishlist;

	function emit(name, detail) {
		document.dispatchEvent(new CustomEvent(name, { detail: detail }));
	}

	// Live region propia (polite): sin ella el toggle es mudo para lectores de
	// pantalla — el corazón solo cambia aria-pressed, que no todos anuncian.
	var liveRegion = null;
	function announce(text) {
		if (!liveRegion) {
			liveRegion = document.createElement('div');
			liveRegion.className = 'screen-reader-text';
			liveRegion.setAttribute('aria-live', 'polite');
			document.body.appendChild(liveRegion);
		}
		// Reset para que anuncios consecutivos idénticos también se lean.
		liveRegion.textContent = '';
		window.setTimeout(function () { liveRegion.textContent = text; }, 30);
	}

	// Usar la plantilla de aria-label con %s cuando el botón trae data-name; conservar el label
	// genérico como fallback.
	function ariaFor(btn, saved) {
		var name = btn.getAttribute('data-name');
		var tpl = saved ? cfg.labels.savedAriaNamed : cfg.labels.addAriaNamed;
		if (name && tpl) {
			return tpl.replace('%s', name);
		}
		return saved ? cfg.labels.savedAria : cfg.labels.addAria;
	}

	function setButtonState(buttons, saved) {
		buttons.forEach(function (btn) {
			btn.setAttribute('aria-pressed', saved ? 'true' : 'false');
			btn.setAttribute('aria-label', ariaFor(btn, saved));
			btn.classList.toggle('is-saved', saved);
			var label = btn.querySelector('.akb-wl-toggle__label');
			if (label) {
				label.textContent = saved ? cfg.labels.saved : cfg.labels.save;
			}
		});
	}

	function buttonsFor(productId) {
		return document.querySelectorAll('.akb-wl-toggle[data-product-id="' + productId + '"]');
	}

	// Usar aria-disabled y el guard de onClick para conservar el foco durante el toggle. El
	// atributo disabled expulsaría el foco del botón y perdería la posición en la grilla.
	function lockButtons(buttons, locked) {
		buttons.forEach(function (btn) {
			if (locked) {
				btn.setAttribute('aria-disabled', 'true');
			} else {
				btn.removeAttribute('aria-disabled');
			}
			btn.classList.toggle('is-loading', locked);
		});
	}

	// Guardar la intención del invitado y completarla al volver al producto. El muro de acceso
	// propaga redirect_to al input redirect de ambos formularios y al filtro
	// rtcamp.google_default_redirect del botón de Google.
	var PENDING_KEY = 'akbWishlistPending';

	function rememberIntent(productId) {
		try {
			window.sessionStorage.setItem(PENDING_KEY, JSON.stringify({
				productId: productId,
				at: Date.now()
			}));
		} catch (err) {
			// Almacenamiento bloqueado (modo privado, cookies de terceros): sin
			// intención guardada el flujo degrada al de siempre, nunca falla.
		}
	}

	function takeIntent() {
		var raw = null;
		try {
			raw = window.sessionStorage.getItem(PENDING_KEY);
			window.sessionStorage.removeItem(PENDING_KEY);
		} catch (err) {
			return 0;
		}
		if (!raw) {
			return 0;
		}
		try {
			var data = JSON.parse(raw);
			// Media hora: pasado ese rato, completar un guardado que el cliente ya
			// no recuerda haber pedido es una sorpresa, no una ayuda.
			if (!data || !data.productId || (Date.now() - Number(data.at)) > 1800000) {
				return 0;
			}
			return parseInt(data.productId, 10) || 0;
		} catch (err) {
			return 0;
		}
	}

	// El nonce viaja horneado en HTML que LiteSpeed cachea hasta 7 días, o sea
	// mucho más de lo que vive un nonce (24 h). Cuando el servidor lo rechaza
	// devuelve uno fresco: se adopta y se reintenta UNA vez. Sin esto, una
	// pestaña vieja o una página servida desde caché dejaba el corazón muerto —
	// el cliente tocaba, leía «No pudimos guardar» y volvía a tocar.
	function adoptNonce(json) {
		if (json && json.data && json.data.nonce) {
			cfg.nonce = json.data.nonce;
			return true;
		}
		return false;
	}

	function loginHref() {
		var redirect = encodeURIComponent(window.location.href);
		return cfg.loginUrl + (cfg.loginUrl.indexOf('?') >= 0 ? '&' : '?') + 'redirect_to=' + redirect;
	}

	// El invitado decidió entrar: se anota el tomo ANTES de navegar (después es
	// una carrera que la navegación puede ganar) y se guarda solo al volver.
	function goToLogin(productId) {
		rememberIntent(productId);
		window.location.href = loginHref();
	}

	// El aviso de GuestPrompt permite entrar conservando la intención o seguir mirando sin
	// guardarla. Si no hay dialog nativo o el aviso está apagado, usar la navegación de acceso
	// habitual.
	var guestDialog = null;
	var guestTrigger = null;
	var guestProductId = 0;

	function isOutsideBox(el, e) {
		var r = el.getBoundingClientRect();
		return e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom;
	}

	function guestDialogSupported() {
		return !!cfg.guestPrompt
			&& typeof window.HTMLDialogElement === 'function'
			&& typeof window.HTMLDialogElement.prototype.showModal === 'function';
	}

	// Cargar por script la hoja del aviso sólo cuando aplica al invitado y está habilitado en el
	// panel. Evitar una hoja bloqueante en cada página que contiene corazones.
	function loadGuestStyle() {
		if (!cfg.guestStyle || cfg.isLogged || !guestDialogSupported() || document.getElementById('akb-wishlist-guest-css')) {
			return;
		}
		var link = document.createElement('link');
		link.id = 'akb-wishlist-guest-css';
		link.rel = 'stylesheet';
		link.href = cfg.guestStyle;
		document.head.appendChild(link);
	}

	function ensureGuestDialog() {
		if (guestDialog) {
			return guestDialog;
		}
		var labels = cfg.labels || {};
		guestDialog = document.createElement('dialog');
		guestDialog.className = 'akb-wl-login';
		guestDialog.setAttribute('aria-labelledby', 'akb-wl-login-title');
		guestDialog.setAttribute('aria-describedby', 'akb-wl-login-body');
		guestDialog.innerHTML =
			'<div class="akb-wl-login__head">' +
				'<h2 class="akb-wl-login__title" id="akb-wl-login-title"></h2>' +
				'<button type="button" class="akb-wl-login__close">' +
					'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true" focusable="false"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>' +
				'</button>' +
			'</div>' +
			'<p class="akb-wl-login__body" id="akb-wl-login-body"></p>' +
			'<div class="akb-wl-login__actions">' +
				'<a class="akb-wl-login__cta" href="#"></a>' +
				'<button type="button" class="akb-wl-login__dismiss"></button>' +
			'</div>';
		guestDialog.querySelector('.akb-wl-login__title').textContent = labels.guestTitle || '';
		guestDialog.querySelector('.akb-wl-login__close').setAttribute('aria-label', labels.guestClose || 'Cerrar');
		guestDialog.querySelector('.akb-wl-login__cta').textContent = labels.guestCta || '';
		guestDialog.querySelector('.akb-wl-login__dismiss').textContent = labels.guestDismiss || '';

		guestDialog.querySelector('.akb-wl-login__cta').addEventListener('click', function (e) {
			e.preventDefault();
			goToLogin(guestProductId);
		});
		guestDialog.querySelector('.akb-wl-login__close').addEventListener('click', function () {
			guestDialog.close();
		});
		guestDialog.querySelector('.akb-wl-login__dismiss').addEventListener('click', function () {
			guestDialog.close();
		});
		// Tocar el fondo lo cierra. El fondo no tiene nodo propio: el target ahí
		// es el <dialog>, pero también lo es su relleno —los márgenes junto al
		// texto, el hueco del safe-area en iPhone—, así que no basta mirar el
		// target: cierra sólo si el toque cae FUERA de la caja visible
		// (revisión B5-R3: tocar junto al texto cerraba el aviso).
		guestDialog.addEventListener('mousedown', function (e) {
			if (e.target === guestDialog && isOutsideBox(guestDialog, e)) {
				guestDialog.close();
			}
		});
		guestDialog.addEventListener('close', function () {
			document.documentElement.classList.remove('akb-wl-login-open');
			// El foco vuelve al corazón que lo abrió: el cliente de teclado sigue
			// donde estaba en la grilla.
			if (guestTrigger && document.contains(guestTrigger) && guestTrigger.focus) {
				guestTrigger.focus();
			}
			guestTrigger = null;
		});
		document.body.appendChild(guestDialog);
		return guestDialog;
	}

	function promptGuest(productId, trigger) {
		if (!guestDialogSupported()) {
			goToLogin(productId);
			return;
		}
		var labels = cfg.labels || {};
		var dialog = ensureGuestDialog();
		var name = trigger ? trigger.getAttribute('data-name') : '';
		var marker = labels.guestMarker || '{producto}';
		var body = labels.guestBody || '';
		// Marcador, no sprintf: el texto es del dueño y puede traer cualquier «%».
		// Todo corazón lleva data-name (Button.php); sin él, el marcador se quita.
		body = body.split(marker).join(name ? '«' + name + '»' : '').replace(/\s{2,}/g, ' ').trim();

		guestProductId = productId;
		guestTrigger = trigger || null;
		dialog.querySelector('.akb-wl-login__body').textContent = body;
		dialog.querySelector('.akb-wl-login__cta').setAttribute('href', loginHref());
		document.documentElement.classList.add('akb-wl-login-open');
		dialog.showModal();
		dialog.querySelector('.akb-wl-login__cta').focus();
	}

	function toggle(productId, cardToRemoveOnUnsave, isRetry, intent) {
		var buttons = buttonsFor(productId);
		var reintentando = false;
		lockButtons(buttons, true);

		var body = new URLSearchParams();
		body.set('action', 'akb_wishlist_toggle');
		body.set('nonce', cfg.nonce);
		body.set('product_id', String(productId));
		if (intent) {
			body.set('intent', intent);
		}

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		})
			.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, json: j }; }); })
			.then(function (res) {
				if (res.status === 401 && res.json && res.json.data && res.json.data.login_url) {
					window.location.href = res.json.data.login_url;
					return;
				}
				if (res.status === 403 && !isRetry && adoptNonce(res.json)) {
					// El botón sigue bloqueado hasta que termine el reintento: el
					// `finally` de esta primera vuelta lo respeta.
					reintentando = true;
					toggle(productId, cardToRemoveOnUnsave, true);
					return;
				}
				if (!res.ok || !res.json || !res.json.success) {
					// Comunicar el fallo y recuperar el botón, también cuando caduca el nonce de una pestaña
					// abierta; no dejar un toggle sin efecto ni explicación.
					announceFailure(buttons);
					return;
				}
				var data = res.json.data || {};
				var added = data.state === 'added';
				setButtonState(buttons, added);
				announceResult(buttons, added);
				updateLeadCount(typeof data.count === 'number' ? data.count : null);
				emit('akb:wishlist:change', {
					state: data.state,
					productId: productId,
					count: typeof data.count === 'number' ? data.count : null,
				});
				if (data.state === 'removed' && cardToRemoveOnUnsave) {
					removeCardAnimated(cardToRemoveOnUnsave);
				}
			})
			.catch(function () {
				// Fallo de red: mismo trato que arriba. Callar deja al cliente
				// creyendo que su favorito quedó guardado cuando no fue así.
				announceFailure(buttons);
			})
			.finally(function () {
				if (!reintentando) {
					lockButtons(buttons, false);
				}
			});
	}

	// Anuncia el resultado del toggle en la live region, con el nombre del
	// producto si el botón lo trae (data-name).
	// Un toggle que no se pudo aplicar TIENE que decirlo: el estado del corazón
	// no cambia, así que sin mensaje el gesto parece ignorado.
	function announceFailure(buttons) {
		var name = buttons.length ? buttons[0].getAttribute('data-name') : null;
		announce(
			name
				? 'No pudimos guardar «' + name + '» en tus favoritos. Vuelve a intentarlo.'
				: 'No pudimos actualizar tus favoritos. Vuelve a intentarlo.'
		);
	}

	function announceResult(buttons, added) {
		var name = buttons.length ? buttons[0].getAttribute('data-name') : null;
		var tpl = added ? cfg.labels.announceAdded : cfg.labels.announceRemoved;
		if (name && tpl) {
			announce(tpl.replace('%s', name));
			return;
		}
		announce(added ? (cfg.labels.announceAddedGeneric || 'Guardado en favoritos') : (cfg.labels.announceRemovedGeneric || 'Quitado de favoritos'));
	}

	// Actualiza el lead "X productos guardados" en /mi-cuenta/favoritos/.
	// Sin esto, el texto queda stale tras quitar un item (bug visual).
	function updateLeadCount(count) {
		if (count === null) {
			return;
		}
		var lead = document.querySelector('.akb-wl-grid__lead');
		if (!lead) {
			return;
		}
		lead.textContent = count === 1 ? '1 producto guardado' : count + ' productos guardados';
	}

	// Animar fade-out y desplazamiento antes de retirar la tarjeta; recargar tras la última para
	// mostrar el estado vacío. Si contiene el foco, moverlo antes al corazón siguiente, anterior o
	// al lead, siguiendo restorePoint de native-cart.js.
	function removeCardAnimated(card) {
		var hadFocus = card.contains(document.activeElement);
		card.classList.add('is-removing');
		var siblings = document.querySelectorAll('.akb-wl-grid .product:not(.is-removing)');
		var remaining = siblings.length;
		if (hadFocus) {
			var fallback = null;
			if (remaining > 0) {
				var cards = Array.prototype.slice.call(document.querySelectorAll('.akb-wl-grid .product'));
				var idx = cards.indexOf(card);
				var next = cards[idx + 1] || cards[idx - 1] || null;
				fallback = next ? next.querySelector('.akb-wl-toggle') : null;
			}
			if (!fallback) {
				fallback = document.querySelector('.akb-wl-grid__lead');
				if (fallback && !fallback.hasAttribute('tabindex')) {
					fallback.setAttribute('tabindex', '-1');
				}
			}
			if (fallback) {
				fallback.focus();
			}
		}
		setTimeout(function () {
			card.remove();
			if (remaining === 0) {
				window.location.reload();
			}
		}, 220); // matched con CSS transition duration
	}

	function onClick(e) {
		var btn = e.target.closest('.akb-wl-toggle');
		if (!btn) {
			return;
		}
		if (btn.getAttribute('aria-disabled') === 'true') {
			// Request en vuelo: el botón sigue enfocable (a propósito) pero
			// ignora clicks hasta que lockButtons lo libere.
			e.preventDefault();
			return;
		}
		var productId = parseInt(btn.getAttribute('data-product-id'), 10);
		if (!productId) {
			return;
		}
		e.preventDefault();

		if (!cfg.isLogged) {
			promptGuest(productId, btn);
			return;
		}

		// En /mi-cuenta/favoritos/ el botón corazón actúa como toggle "quitar":
		// al cambiar a estado removed, sacamos la card del DOM para feedback
		// instantáneo. La página recarga si queda vacía (para mostrar empty state).
		var onWishlistPage = !!btn.closest('.akb-wl-grid');
		toggle(productId, onWishlistPage ? btn.closest('.product') : null);
	}

	function resumePendingIntent() {
		if (!cfg.isLogged) {
			return;
		}
		var productId = takeIntent();
		if (!productId) {
			return;
		}
		var buttons = buttonsFor(productId);
		if (buttons.length && buttons[0].classList.contains('is-saved')) {
			return;
		}
		// Con intent 'add' garantizamos que si ya estaba guardado nunca se borra.
		toggle(productId, null, false, 'add');
	}

	var LS_KEY = 'akb_wl_ids';

	function isUserLoggedIn() {
		return Boolean(cfg.isLogged || cfg.isLoggedIn);
	}

	function getSavedSet() {
		if (!isUserLoggedIn()) {
			try {
				if (typeof window !== 'undefined' && window.localStorage) {
					window.localStorage.removeItem(LS_KEY);
				}
			} catch (e) {}
			return null;
		}
		try {
			if (typeof window === 'undefined' || !window.localStorage) {
				return null;
			}
			var raw = window.localStorage.getItem(LS_KEY);
			if (raw) {
				var arr = JSON.parse(raw);
				if (Array.isArray(arr)) {
					var set = {};
					for (var i = 0; i < arr.length; i++) {
						set[arr[i]] = true;
					}
					return set;
				}
			}
		} catch (e) {}
		return null;
	}

	function saveSavedSet(set) {
		if (!isUserLoggedIn()) {
			try {
				if (typeof window !== 'undefined' && window.localStorage) {
					window.localStorage.removeItem(LS_KEY);
				}
			} catch (e) {}
			return;
		}
		try {
			if (typeof window === 'undefined' || !window.localStorage) {
				return;
			}
			var arr = Object.keys(set).map(function (k) { return parseInt(k, 10); });
			window.localStorage.setItem(LS_KEY, JSON.stringify(arr));
		} catch (e) {}
	}

	function syncDomFromSavedSet() {
		if (!isUserLoggedIn()) {
			try {
				if (typeof window !== 'undefined' && window.localStorage) {
					window.localStorage.removeItem(LS_KEY);
				}
			} catch (e) {}
			return;
		}
		var set = getSavedSet();
		if (!set) {
			return;
		}
		var allButtons = document.querySelectorAll('.akb-wl-toggle[data-product-id]');
		allButtons.forEach(function (btn) {
			var pid = parseInt(btn.getAttribute('data-product-id'), 10);
			if (pid > 0) {
				var saved = !!set[pid];
				setButtonState([btn], saved);
			}
		});
		var count = Object.keys(set).length;
		var badges = document.querySelectorAll('[data-akb-wishlist-count]');
		badges.forEach(function (badge) {
			badge.setAttribute('data-akb-wishlist-count', String(count));
			badge.textContent = String(count);
		});
	}

	function onFilterTabClick(e) {
		var tab = e.target.closest('.akb-wl-filter-tab');
		if (!tab) {
			return;
		}
		e.preventDefault();
		var filter = tab.getAttribute('data-wl-filter') || 'all';
		var tabs = document.querySelectorAll('.akb-wl-filter-tab');
		tabs.forEach(function (t) { t.classList.toggle('is-active', t.getAttribute('data-wl-filter') === filter); });

		var cards = document.querySelectorAll('.akb-wl-grid .product');
		var visibleCount = 0;
		cards.forEach(function (card) {
			if (filter === 'all') {
				card.style.display = '';
				visibleCount++;
			} else if (filter === 'instock') {
				var match = card.classList.contains('instock');
				card.style.display = match ? '' : 'none';
				if (match) visibleCount++;
			} else if (filter === 'other') {
				var match = !card.classList.contains('instock');
				card.style.display = match ? '' : 'none';
				if (match) visibleCount++;
			}
		});

		var emptyMsg = document.querySelector('.akb-wl-empty-filter');
		if (emptyMsg) {
			emptyMsg.style.display = (visibleCount === 0 && cards.length > 0) ? 'block' : 'none';
		}
	}

	function start() {
		if (isUserLoggedIn() && Array.isArray(cfg.savedIds)) {
			var initialSet = {};
			for (var s = 0; s < cfg.savedIds.length; s++) {
				initialSet[cfg.savedIds[s]] = true;
			}
			saveSavedSet(initialSet);
		} else if (!isUserLoggedIn()) {
			try {
				if (typeof window !== 'undefined' && window.localStorage) {
					window.localStorage.removeItem(LS_KEY);
				}
			} catch (e) {}
		}

		document.addEventListener('click', onFilterTabClick);
		document.addEventListener('click', onClick);
		document.addEventListener('akb:wishlist:change', function (e) {
			var detail = e.detail || {};
			if (!isUserLoggedIn() || !detail.productId) {
				return;
			}
			var set = getSavedSet() || {};
			if (detail.state === 'added') {
				set[detail.productId] = true;
			} else if (detail.state === 'removed') {
				delete set[detail.productId];
			}
			saveSavedSet(set);
		});

		document.addEventListener('akb:search:swapped', syncDomFromSavedSet);
		if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
			window.addEventListener('pageshow', syncDomFromSavedSet);
		}

		loadGuestStyle();
		resumePendingIntent();
		syncDomFromSavedSet();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
