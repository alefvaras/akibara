(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var config = window.akbNativeCart || {};
		var overlay = document.getElementById('akb-native-cart-overlay');
		var drawer = document.getElementById('akb-native-cart-drawer');

		if (!overlay || !drawer || config.enabled === false) {
			return;
		}

		var closeBtn = drawer.querySelector('.akb-cart-close');
		var itemsContainer = drawer.querySelector('.akb-cart-items-container');
		var subtotalVal = drawer.querySelector('.akb-cart-subtotal-val');
		var discountRow = drawer.querySelector('.akb-cart-discount');
		var discountVal = drawer.querySelector('.akb-cart-discount-val');
		var totalRow = drawer.querySelector('.akb-cart-total');
		var totalVal = drawer.querySelector('.akb-cart-total-val');
		var shippingNotice = drawer.querySelector('.akb-cart-shipping-notice');
		var liveRegion = drawer.querySelector('.akb-cart-live');
		var statusContainer = drawer.querySelector('[data-akb-cart-status]');
		var footer = drawer.querySelector('.akb-cart-footer');
		var body = drawer.querySelector('.akb-cart-body');
		var progressSlot = drawer.querySelector('.akb-cart-progress-slot');
		var checkoutButton = drawer.querySelector('.akb-cart-checkout-btn');
		var checkoutLabel = checkoutButton ? checkoutButton.textContent : '';
		var lastFocused = null;
		var lastTriggerIndex = -1;
		var isOpen = false;
		var isMutating = false;
		var isCheckingOut = false;
		var checkoutAttempt = 0;
		var recommendationPending = false;
		var resumeTimer = null;
		var suppressNextFragmentRefresh = false;
		var memoryCache = null;
		var cartReadController = null;
		var cartReadSequence = 0;
		var bodyOverflow = '';
		var focusableSelector = [
			'a[href]',
			'button:not([disabled])',
			'input:not([disabled])',
			'select:not([disabled])',
			'textarea:not([disabled])',
			'[tabindex]:not([tabindex="-1"])'
		].join(',');

		if (config.reducedMotion) {
			drawer.classList.add('akb-reduced-motion');
			overlay.classList.add('akb-reduced-motion');
		}

		// Cada .akb-cart-trigger refleja el estado real del cajón, tanto en header como en bottom-nav.
		// Los fragments de WooCommerce sustituyen nodos con aria-expanded=false; reaplicar los
		// atributos a todos los disparadores tras cada reemplazo y cubrir temas que aún no los
		// declaran.
		function syncCartTriggersAria() {
			var expanded = isOpen ? 'true' : 'false';
			document.querySelectorAll('.akb-cart-trigger').forEach(function (trigger) {
				if (!trigger.hasAttribute('aria-haspopup')) {
					trigger.setAttribute('aria-haspopup', 'dialog');
				}
				trigger.setAttribute('aria-expanded', expanded);
			});
		}

		syncCartTriggersAria();

		// Restaurar el foco al disparador que ocupa la posición original. Los fragments de WooCommerce
		// sustituyen el icono de cabecera durante las mutaciones del carrito; el nodo que abrió el
		// cajón puede haber quedado desconectado.
		function focusReturnTarget() {
			if (!lastFocused || !lastFocused.focus) {
				return null;
			}
			if (document.contains(lastFocused)) {
				return lastFocused;
			}
			if (lastTriggerIndex < 0) {
				return null;
			}
			return document.querySelectorAll('.akb-cart-trigger')[lastTriggerIndex] || null;
		}

		// Mover el foco dentro del cajón sólo mientras está abierto. Una respuesta tardía de lectura o
		// mutación debe respetar el foco que close() devolvió al disparador.
		function focusInsideDrawer(target) {
			if (isOpen && target && target.focus) {
				target.focus();
			}
		}

		window.akbCart = {
			open: function (trigger) {
				if (isOpen) {
					return;
				}

				lastFocused = trigger && trigger.focus ? trigger : document.activeElement;
				lastTriggerIndex = Array.prototype.indexOf.call(document.querySelectorAll('.akb-cart-trigger'), lastFocused);
				isOpen = true;
				overlay.classList.add('akb-open');
				drawer.classList.add('akb-open');
				overlay.setAttribute('aria-hidden', 'false');
				drawer.setAttribute('aria-hidden', 'false');
				syncCartTriggersAria();
				bodyOverflow = document.body.style.overflow;
				document.body.style.overflow = 'hidden';
				document.addEventListener('keydown', onDocumentKeydown);
				fetchCart();

				focusInitialControl();

				// Evento de dominio para interop desacoplada (p.ej. el motor de
				// popup de marketing auto-suprime al abrirse el carrito). Aditivo y
				// backward-compatible: nadie más lo escuchaba antes.
				try { document.dispatchEvent(new CustomEvent('akb:cart:open')); } catch (e) {}
			},
			close: function () {
				if (!isOpen) {
					return;
				}

				isOpen = false;
				cancelCheckout();
				overlay.classList.remove('akb-open');
				drawer.classList.remove('akb-open');
				overlay.setAttribute('aria-hidden', 'true');
				drawer.setAttribute('aria-hidden', 'true');
				syncCartTriggersAria();
				document.body.style.overflow = bodyOverflow;
				document.removeEventListener('keydown', onDocumentKeydown);

				var returnTarget = focusReturnTarget();
				if (returnTarget) {
					returnTarget.focus();
				}

				try { document.dispatchEvent(new CustomEvent('akb:cart:close')); } catch (e) {}
			},
			toggle: function (trigger) {
				if (isOpen) {
					window.akbCart.close();
				} else {
					window.akbCart.open(trigger);
				}
			},
			refresh: function (forceRefresh) {
				if (isOpen && !isCheckingOut) {
					fetchCart(Boolean(forceRefresh));
				}
			}
		};

		overlay.addEventListener('click', window.akbCart.close);
		if (closeBtn) {
			closeBtn.addEventListener('click', window.akbCart.close);
		}

		bindConfiguredTriggers();
		if (checkoutButton) {
			checkoutButton.addEventListener('click', proceedToCheckout);
		}

		// Sólo reconciliar al volver a un cajón visible. focus y visibilitychange
		// suelen llegar juntos: una única lectura, sin sondeo ni trabajo en fondo.
		document.addEventListener('visibilitychange', scheduleResumeRefresh);
		window.addEventListener('focus', scheduleResumeRefresh);
		window.addEventListener('pageshow', function (event) {
			if (event.persisted) {
				cancelCheckout();
				scheduleResumeRefresh();
			}
		});

		function scheduleResumeRefresh() {
			clearTimeout(resumeTimer);
			if (!isOpen || document.visibilityState === 'hidden') {
				return;
			}
			resumeTimer = setTimeout(function () {
				if (isOpen && document.visibilityState !== 'hidden' && !isMutating && !isCheckingOut && !recommendationPending) {
					fetchCart(true);
				}
			}, 150);
		}

		function setCheckoutBusy(busy) {
			isCheckingOut = busy;
			if (checkoutButton) {
				checkoutButton.textContent = busy ? text('checkingCart', text('loading', 'Cargando...')) : checkoutLabel;
				checkoutButton.setAttribute('aria-busy', busy ? 'true' : 'false');
				checkoutButton.setAttribute('aria-disabled', busy ? 'true' : 'false');
			}
			syncBusyState();
		}

		function cancelCheckout() {
			if (isCheckingOut) {
				checkoutAttempt += 1;
				abortCartRead();
				setCheckoutBusy(false);
			}
		}

		async function proceedToCheckout(event) {
			// Conservar las acciones nativas del enlace (abrir en otra pestaña,
			// ventana o descargar). El flujo normal y Enter sí se comprueban aquí.
			if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || (event.button && event.button !== 0)) {
				return;
			}
			event.preventDefault();
			if (!isOpen || isCheckingOut) {
				return;
			}
			if (isMutating || recommendationPending) {
				var waitMessage = text('checkoutWait', text('loading', 'Cargando...'));
				if (statusContainer && !statusContainer.querySelector('[data-akb-cart-wait]')) {
					var notice = createText('akb-cart-status__item', waitMessage);
					notice.setAttribute('data-akb-cart-wait', '');
					statusContainer.appendChild(notice);
					statusContainer.hidden = false;
				}
				announce(waitMessage);
				return;
			}

			var attempt = ++checkoutAttempt;
			var navigating = false;
			setCheckoutBusy(true);
			try {
				// Nunca decidir con el DOM, fragments o cache: la cookie de sesión
				// puede haberse borrado mientras este mismo cajón seguía abierto.
				var fresh = await fetchCart(true);
				if (attempt !== checkoutAttempt || !isOpen || !fresh) {
					return;
				}
				if (memoryCache && memoryCache.items.length && Number(memoryCache.cart_count) > 0) {
					window.location.assign(checkoutButton.href);
					navigating = true;
				}
			} finally {
				if (attempt === checkoutAttempt && !navigating) {
					setCheckoutBusy(false);
				}
			}
		}

		// Reaplicar aria-expanded tras added_to_cart, wc_fragments_refreshed y wc_fragments_loaded:
		// los fragments crean nodos nuevos con false. Escuchar jQuery y su reemisión nativa akb:* de
		// wc-event-bridge.js, conservando los eventos propios que refrescan el carrito.
		['akb:wc_fragments_refreshed', 'akb:wc_fragments_loaded'].forEach(function (evt) {
			document.body.addEventListener(evt, syncCartTriggersAria);
		});
		if (window.jQuery) {
			window.jQuery(document.body).on('wc_fragments_refreshed wc_fragments_loaded', syncCartTriggersAria);
		}

		if (window.jQuery) {
			window.jQuery(document.body).on('added_to_cart updated_cart_totals wc_fragments_refreshed', function (event) {
				if (event.type === 'wc_fragments_refreshed' && suppressNextFragmentRefresh) {
					suppressNextFragmentRefresh = false;
					return;
				}

				// El carrito cambió fuera del drawer: la copia en memoria ya no
				// describe la verdad. Sin esto, open() pintaba primero el carrito
				// ANTERIOR (sin el producto recién agregado) y lo sustituía al
				// llegar la respuesta — el "parpadeo" al abrir.
				memoryCache = null;
				window.akbCart.refresh(true);
				if (config.autoOpenOnAdd && event.type === 'added_to_cart') {
					window.akbCart.open();
				}
			});
		}

		function bindConfiguredTriggers() {
			var selector = typeof config.triggerSelector === 'string' ? config.triggerSelector.trim() : '';

			if (!selector) {
				return;
			}

			// Delegado en document: el icono de carrito del header es un WC fragment
			// que se reemplaza en added_to_cart; un binding directo perdería el listener.
			document.addEventListener('click', function (event) {
				var btn;
				try {
					btn = event.target.closest(selector);
				} catch (error) {
					if (window.console && console.warn) {
						console.warn('Akibara NativeCart: selector inválido', error);
					}
					return;
				}
				if (!btn) {
					return;
				}
				event.preventDefault();
				window.akbCart.toggle(btn);
			});
		}

		function onDocumentKeydown(event) {
			if (!isOpen) {
				return;
			}

			if (event.key === 'Escape') {
				event.preventDefault();
				window.akbCart.close();
				return;
			}

			if (event.key !== 'Tab') {
				return;
			}

			trapFocus(event);
		}

		function focusInitialControl() {
			var target = closeBtn || drawer;

			window.setTimeout(function () {
				if (!isOpen) {
					return;
				}
				target.focus();
			}, 40);
			window.setTimeout(function () {
				if (isOpen && !drawer.contains(document.activeElement)) {
					target.focus();
				}
			}, config.reducedMotion ? 80 : 360);
		}

		function trapFocus(event) {
			var focusables = Array.prototype.slice.call(drawer.querySelectorAll(focusableSelector))
				.filter(function (el) {
					return el.getClientRects().length > 0 || el === document.activeElement;
				});

			if (!focusables.length) {
				event.preventDefault();
				drawer.focus();
				return;
			}

			var first = focusables[0];
			var last = focusables[focusables.length - 1];

			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		}

		function text(key, fallback) {
			return config.copy && config.copy[key] ? config.copy[key] : fallback;
		}

		function announce(message) {
			if (liveRegion) {
				liveRegion.textContent = message;
			}
		}

		function clear(el) {
			while (el && el.firstChild) {
				el.removeChild(el.firstChild);
			}
		}

		function createText(className, value, tagName) {
			var el = document.createElement(tagName || 'div');
			el.className = className;
			el.textContent = value || '';
			return el;
		}

		function appendTitleWithVolume(container, title, className) {
			var el = document.createElement('div');
			el.className = className;
			if (!title) {
				container.appendChild(el);
				return;
			}
			var match = title.match(/^(.*\S)\s*(?:[Nn]\.?[º°]|#)\s*(\d+)\s*(\([^()]*\))\s*$/) ||
				title.match(/^(.*\S)\s*(?:[Nn]\.?[º°]|#)\s*(\d+)\s*$/) ||
				title.match(/^(.*\S)\s+(\d+)\s*(\([^()]*\))\s*$/) ||
				title.match(/^(.*\S)\s+(\d+)\s*$/);
			if (match) {
				var name = match[1].replace(/\s*(?:[Nn]\.?[º°]|#)\s*$/, '');
				if (match[3]) {
					name += ' ' + match[3];
				}
				var nameSpan = document.createElement('span');
				nameSpan.className = 'akb-title-name';
				nameSpan.textContent = name.trim() + ' ';
				var volSpan = document.createElement('span');
				volSpan.className = 'akb-vol';
				volSpan.textContent = match[2];
				el.appendChild(nameSpan);
				el.appendChild(volSpan);
			} else {
				el.textContent = title;
			}
			container.appendChild(el);
		}

		// Defensa adicional para HTML de confianza generado por el servidor: subtotales WC, thumbnails
		// escapados y barra FSP filtrada con wp_kses. Retira script/style/iframe/object/embed,
		// handlers on* y javascript: en href/src. No es un sanitizador general: no cubre xlink:href,
		// formaction, data:text/html, <base> ni otros vectores. El HTML de usuarios o terceros debe
		// sanearse antes en el backend.
		function safeHtmlFragment(html) {
			var template = document.createElement('template');
			template.innerHTML = html || '';
			template.content.querySelectorAll('script, style, iframe, object, embed').forEach(function (node) {
				node.remove();
			});
			template.content.querySelectorAll('*').forEach(function (node) {
				Array.prototype.slice.call(node.attributes).forEach(function (attr) {
					if (/^on/i.test(attr.name)) {
						node.removeAttribute(attr.name);
					} else if ((attr.name === 'href' || attr.name === 'src') && /^\s*javascript:/i.test(attr.value)) {
						node.removeAttribute(attr.name);
					}
				});
			});
			return template.content.cloneNode(true);
		}

		function createSkeletonCard() {
			var item = document.createElement('div');
			item.className = 'akb-cart-skeleton-item';
			var thumb = document.createElement('div');
			thumb.className = 'akb-cart-skeleton-thumb';
			var details = document.createElement('div');
			details.className = 'akb-cart-skeleton-details';
			var t1 = document.createElement('div');
			t1.className = 'akb-cart-skeleton-line akb-cart-skeleton-line--title';
			var t2 = document.createElement('div');
			t2.className = 'akb-cart-skeleton-line akb-cart-skeleton-line--sub';
			var t3 = document.createElement('div');
			t3.className = 'akb-cart-skeleton-line akb-cart-skeleton-line--price';
			details.appendChild(t1);
			details.appendChild(t2);
			details.appendChild(t3);
			item.appendChild(thumb);
			item.appendChild(details);
			return item;
		}

		// Esqueleto de PRIMERA carga: sólo cuando todavía no hay carrito pintado.
		// Para las mutaciones existe setBusy(), que no destruye nada.
		function renderLoading() {
			clear(itemsContainer);
			var wrap = document.createElement('div');
			wrap.className = 'akb-cart-loading';
			wrap.setAttribute('role', 'status');
			wrap.setAttribute('aria-label', text('loading', 'Cargando carrito...'));
			var sr = document.createElement('span');
			sr.className = 'screen-reader-text';
			sr.textContent = text('loading', 'Cargando carrito...');
			wrap.appendChild(sr);
			var list = document.createElement('div');
			list.className = 'akb-cart-skeleton-list';
			list.setAttribute('aria-hidden', 'true');
			list.appendChild(createSkeletonCard());
			list.appendChild(createSkeletonCard());
			wrap.appendChild(list);
			itemsContainer.appendChild(wrap);
			if (footer) {
				footer.setAttribute('hidden', '');
			}
		}

		function hasRenderedCart() {
			return Boolean(itemsContainer && itemsContainer.querySelector('.akb-cart-item'));
		}

		/**
		 * Durante una mutación de cantidad o eliminación, conservar lista y footer para mantener
		 * scroll, foco y geometría del diálogo. Atenuar y bloquear la interacción con aria-busy, sin
		 * sustituir el contenido por el estado de carga inicial.
		 */
		function setBusy(busy) {
			isMutating = busy;
			syncBusyState();
		}

		function syncBusyState() {
			var busy = isMutating || isCheckingOut;
			drawer.classList.toggle('akb-cart--busy', busy);
			// aria-busy va en el contenedor de la lista, no en el diálogo: el
			// footer y el botón de cerrar siguen siendo contenido estable.
			itemsContainer.setAttribute('aria-busy', busy ? 'true' : 'false');
		}

		function captureRestorePoint() {
			var active = document.activeElement;
			var inCart = active && itemsContainer.contains(active);
			var row = inCart ? active.closest('.akb-cart-item') : null;
			var rows = Array.prototype.slice.call(itemsContainer.querySelectorAll('.akb-cart-item'));

			return {
				scrollTop: body ? body.scrollTop : 0,
				rowIndex: row ? rows.indexOf(row) : -1,
				controlClass: inCart ? controlSignature(active) : ''
			};
		}

		function controlSignature(el) {
			if (el.classList.contains('akb-qty-plus')) { return 'akb-qty-plus'; }
			if (el.classList.contains('akb-qty-minus')) { return 'akb-qty-minus'; }
			if (el.classList.contains('akb-cart-item-remove')) { return 'akb-cart-item-remove'; }
			return '';
		}

		/**
		 * Devuelve scroll y foco al mismo punto tras el re-render.
		 *
		 * renderCart() recrea todos los nodos, así que no basta con guardar la
		 * referencia al elemento: se guarda su coordenada (fila + tipo de control)
		 * y se busca el equivalente en el DOM nuevo. Si la fila desapareció
		 * (cantidad a 0), el foco cae al control más cercano que sí exista, nunca
		 * al <body>.
		 */
		function restorePoint(point) {
			if (!point) {
				return;
			}

			if (body) {
				body.scrollTop = point.scrollTop;
			}

			if (point.rowIndex < 0 || !point.controlClass) {
				return;
			}

			var rows = itemsContainer.querySelectorAll('.akb-cart-item');
			if (!rows.length) {
				focusInsideDrawer(closeBtn);
				return;
			}

			var row = rows[Math.min(point.rowIndex, rows.length - 1)];
			var target = row.querySelector('.' + point.controlClass);

			// El "+" puede quedar deshabilitado al tocar el tope de stock: en ese
			// caso el foco pasa al "−", que sigue siendo accionable.
			if (!target || target.disabled) {
				target = row.querySelector('.akb-qty-minus:not([disabled])')
					|| row.querySelector('.akb-cart-item-remove')
					|| closeBtn;
			}

			focusInsideDrawer(target);
		}

		function renderCart(data, announceMutation) {
			var settings = Object.assign({}, config, data.settings || {});
			// clear() destruye los nodos y el navegador colapsa el scroll a 0. Con
			// una lista larga, cualquier refresco devolvía al cliente al principio.
			var previousScroll = body ? body.scrollTop : 0;
			clear(itemsContainer);
			renderStatus(settings, data);

			if (!data.items || data.items.length === 0) {
				itemsContainer.appendChild(renderEmptyState(settings));
				if (subtotalVal) {
					subtotalVal.textContent = '$0';
				}
				if (discountRow) {
					discountRow.setAttribute('hidden', '');
				}
				if (discountVal) {
					clear(discountVal);
				}
				if (totalRow) {
					totalRow.setAttribute('hidden', '');
				}
				if (totalVal) {
					clear(totalVal);
				}
				if (shippingNotice) {
					shippingNotice.textContent = '';
				}
				if (progressSlot) {
					clear(progressSlot);
				}
				// Carrito vacío: ocultar el footer entero para no dejar "Pagar pedido"
				// activo sobre $0 ni la barra de envío congelada/mentirosa.
				if (footer) {
					footer.setAttribute('hidden', '');
				}
				// Vaciar por mutación elimina el control que tenía el foco (el botón
				// remove/− recién borrado), dejando el foco en <body> y rompiendo el
				// trap del drawer. Lo devolvemos al botón de cerrar, que siempre vive.
				focusInsideDrawer(closeBtn);
				if (announceMutation) {
					announce(text('empty', 'Tu carrito está vacío.'));
				}
				return;
			}

			if (footer) {
				footer.removeAttribute('hidden');
			}

			data.items.forEach(function (item) {
				itemsContainer.appendChild(renderItem(item, settings));
			});

			if (settings.showRecommendations && data.cross_sells && data.cross_sells.length) {
				itemsContainer.appendChild(renderRecommendations(data.cross_sells, settings));
			}

			if (subtotalVal) {
				clear(subtotalVal);
				subtotalVal.appendChild(safeHtmlFragment(data.subtotal_html || ''));
			}
			var showDiscountBreakdown = Boolean(data.has_discount && data.discount_html && data.total_html);
			if (discountRow) {
				discountRow.toggleAttribute('hidden', !showDiscountBreakdown);
			}
			if (discountVal) {
				clear(discountVal);
				if (showDiscountBreakdown) {
					discountVal.appendChild(safeHtmlFragment(data.discount_html));
				}
			}
			if (totalRow) {
				totalRow.toggleAttribute('hidden', !showDiscountBreakdown);
			}
			if (totalVal) {
				clear(totalVal);
				if (showDiscountBreakdown) {
					totalVal.appendChild(safeHtmlFragment(data.total_html));
				}
			}
			if (shippingNotice) {
				shippingNotice.textContent = data.shipping_notice || '';
			}
			// Aviso puntual de ESTA mutación (p. ej. una línea retirada porque el
			// producto se agotó). Va a la región viva para que el lector de pantalla
			// lo anuncie y el cliente no vea desaparecer algo sin explicación; pisa
			// al anuncio genérico de "carrito actualizado" porque dice más.
			if (data.notice) {
				announce(data.notice);
			}
			if (progressSlot) {
				clear(progressSlot);
				if (data.fsp_html) {
					progressSlot.appendChild(safeHtmlFragment(data.fsp_html));
				}
			}

			if (body) {
				// Clamp implícito del navegador: si la lista encogió, scrollTop se
				// ajusta solo al nuevo máximo.
				body.scrollTop = previousScroll;
			}

			if (announceMutation) {
				announce('Carrito actualizado.');
			}
			bindDynamicActions();
		}

		function renderEmptyState(settings) {
			var emptyState = document.createElement('div');
			emptyState.className = 'akb-cart-empty';

			emptyState.appendChild(
				createText(
					'akb-cart-empty__message',
					settings.copy && settings.copy.empty
						? settings.copy.empty
						: text('empty', 'Tu carrito está vacío.'),
					'p'
				)
			);

			var continueLink = document.createElement('a');
			continueLink.className = 'akb-cart-empty__cta';
			continueLink.href = settings.continueUrl || config.continueUrl || '/tienda/';
			continueLink.textContent = settings.copy && settings.copy.continue
				? settings.copy.continue
				: text('continue', 'Explorar catálogo');
			emptyState.appendChild(continueLink);

			return emptyState;
		}

		function renderStatus(settings, data) {
			if (!statusContainer) {
				return;
			}

			clear(statusContainer);
			statusContainer.hidden = true;

			if (!data.items || !data.items.length) {
				return;
			}

			// Píldora de Despacho Consolidado para compras mixtas (Stock + A Pedido)
			if (data.is_mixed) {
				var mixedPill = createText(
					'akb-cart-status__item akb-cart-status__item--mixed',
					'🚚 Despacho Consolidado: Tus tomos se enviarán juntos al llegar la reserva para ahorrarte costos de envío.'
				);
				mixedPill.style.background = '#eef7ff';
				mixedPill.style.border = '1px solid #b9dcff';
				mixedPill.style.color = '#0b4d8c';
				mixedPill.style.padding = '8px 12px';
				mixedPill.style.borderRadius = '6px';
				mixedPill.style.fontSize = '12px';
				mixedPill.style.lineHeight = '1.4';
				mixedPill.style.fontWeight = '500';
				statusContainer.appendChild(mixedPill);
			}

			statusContainer.hidden = !statusContainer.children.length;
		}

		function renderItem(item, settings) {
			var row = document.createElement('div');
			row.className = 'akb-cart-item';

			if (settings.showThumbnails) {
				var image = document.createElement('div');
				image.className = 'akb-cart-item-img';
				if (item.thumbnail_url) {
					var imgEl = document.createElement('img');
					imgEl.src = item.thumbnail_url;
					imgEl.alt = item.title || '';
					imgEl.loading = 'lazy';
					image.appendChild(imgEl);
				} else if (item.thumbnail) {
					image.appendChild(safeHtmlFragment(item.thumbnail));
				}
				row.appendChild(image);
			}

			var details = document.createElement('div');
			details.className = 'akb-cart-item-details';
			appendTitleWithVolume(details, item.title, 'akb-cart-item-title');
			if (item.stock_label) {
				details.appendChild(createText('akb-cart-item-availability', item.stock_label));
			}
			if (item.eta) {
				details.appendChild(createText('akb-cart-item-eta', 'Llegada estimada a Akibara: ' + item.eta));
			}


			var actions = document.createElement('div');
			actions.className = 'akb-cart-item-actions';

			var price = document.createElement('div');
			price.className = 'akb-cart-item-price';
			price.appendChild(safeHtmlFragment(item.price_html || ''));
			actions.appendChild(price);

			var qty = document.createElement('div');
			qty.className = 'akb-cart-item-qty';
			qty.appendChild(renderQuantityControl(item));
			actions.appendChild(qty);

			details.appendChild(actions);
			row.appendChild(details);

			var remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'akb-cart-item-remove';
			remove.dataset.keys = JSON.stringify(item.keys_to_remove || []);
			// Incluir el producto en el nombre accesible de cada botón de eliminar para distinguir líneas
			// en las listas de controles de lectores de pantalla, igual que las recomendaciones al
			// agregar.
			remove.setAttribute('aria-label', 'Eliminar ' + (item.title || 'producto') + ' del carrito');
			// Ícono de basurero (no "×"): el "×" era idéntico al de cerrar el drawer
			// → affordance ambigua. SVG estático (sin input de usuario) con currentColor
			// para heredar el color/hover del botón. aria-hidden: el aria-label ya nombra.
			remove.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>';
			row.appendChild(remove);

			return row;
		}

		function renderQuantityControl(item) {
			var control = document.createElement('div');
			var itemKey = item.keys_to_remove && item.keys_to_remove[0] ? item.keys_to_remove[0] : '';
			var current = Number(item.quantity);
			var max = (typeof item.max !== 'undefined') ? Number(item.max) : -1;
			// Identificar el producto en los controles de cantidad. El título recibido del servidor se
			// asigna con setAttribute, nunca como HTML.
			var itemTitle = item.title || 'producto';
			control.className = 'akb-cart-qty-ctrl';
			control.appendChild(quantityButton('akb-qty-minus', '-', itemKey, current - 1, 'Disminuir cantidad de ' + itemTitle));

			var input = document.createElement('input');
			input.type = 'number';
			input.readOnly = true;
			input.value = item.quantity;
			input.setAttribute('aria-label', 'Cantidad de ' + itemTitle);
			control.appendChild(input);

			// El máximo comprable admite tres valores: -1 sin límite, N como tope y 0 sin unidades
			// comprables. Deshabilitar el incremento también con 0; el servidor retira las líneas
			// agotadas.
			var atMax = (max === 0) || (max > 0 && current >= max);
			var plus = quantityButton('akb-qty-plus', '+', itemKey, atMax ? max : current + 1, 'Aumentar cantidad de ' + itemTitle);
			if (atMax) {
				plus.disabled = true;
				plus.setAttribute('aria-disabled', 'true');
			}
			control.appendChild(plus);
			return control;
		}

		function quantityButton(className, label, key, qty, ariaLabel) {
			var button = document.createElement('button');
			button.type = 'button';
			button.className = className;
			button.dataset.key = key;
			button.dataset.qty = String(qty);
			button.setAttribute('aria-label', ariaLabel);
			button.textContent = label;
			return button;
		}

		function renderRecommendations(items, settings) {
			var wrap = document.createElement('div');
			wrap.className = 'akb-cart-cross-sells';
			wrap.appendChild(createText('', settings.copy && settings.copy.recommendations ? settings.copy.recommendations : text('recommendations', 'También te puede gustar'), 'h4'));

			items.forEach(function (cs) {
				var row = document.createElement('div');
				row.className = 'akb-cs-item';

				if (settings.showThumbnails) {
					var image = document.createElement('div');
					image.className = 'akb-cs-img';
					if (cs.thumbnail_url) {
						var imgEl = document.createElement('img');
						imgEl.src = cs.thumbnail_url;
						imgEl.alt = cs.title || '';
						imgEl.loading = 'lazy';
						image.appendChild(imgEl);
					} else {
						image.appendChild(safeHtmlFragment(cs.thumbnail || ''));
					}
					row.appendChild(image);
				}

				var details = document.createElement('div');
				details.className = 'akb-cs-details';
				appendTitleWithVolume(details, cs.title, 'akb-cs-title');
				var price = document.createElement('div');
				price.className = 'akb-cs-price';
				price.appendChild(safeHtmlFragment(cs.price_html || ''));
				details.appendChild(price);
				row.appendChild(details);

				// Agregar al carrito es una acción, no navegación. Un botón evita
				// publicar otra URL rastreable con ?add-to-cart= en el drawer.
				var add = document.createElement('button');
				add.type = 'button';
				add.className = 'akb-cs-add-btn';
				add.dataset.product_id = cs.id;
				add.textContent = settings.copy && settings.copy.recommendationAdd ? settings.copy.recommendationAdd : text('recommendationAdd', 'Agregar');
				// El texto visible ("Agregar") se repite por cada recomendación: sin
				// contexto, un lector de pantalla lista N enlaces idénticos. El
				// aria-label nombra el producto concreto.
				add.setAttribute('aria-label', add.textContent + ' ' + (cs.title || '') + ' al carrito');
				row.appendChild(add);
				wrap.appendChild(row);
			});

			return wrap;
		}

		function bindDynamicActions() {
			itemsContainer.querySelectorAll('.akb-cart-item-remove').forEach(function (btn) {
				btn.addEventListener('click', function () {
					removeItems(JSON.parse(btn.dataset.keys || '[]'));
				});
			});

			itemsContainer.querySelectorAll('.akb-qty-minus, .akb-qty-plus').forEach(function (btn) {
				btn.addEventListener('click', function () {
					updateItemQty(btn.dataset.key, parseInt(btn.dataset.qty, 10));
				});
			});

			itemsContainer.querySelectorAll('.akb-cs-add-btn').forEach(function (btn) {
				btn.addEventListener('click', addRecommendedItem);
			});
		}

		async function addRecommendedItem(event) {
			event.preventDefault();
			if (isMutating || isCheckingOut || recommendationPending) {
				return;
			}
			recommendationPending = true;
			var btn = event.currentTarget;
			var pid = btn.dataset.product_id;
			var defaultLabel = btn.textContent;
			var recommendationButtons = Array.prototype.slice.call(
				itemsContainer.querySelectorAll('.akb-cs-add-btn')
			);
			var recommendationIndex = Math.max(0, recommendationButtons.indexOf(btn));

			btn.disabled = true;
			btn.setAttribute('aria-busy', 'true');
			btn.textContent = text('adding', 'Agregando…');

			try {
				var formData = new URLSearchParams();
				formData.append('product_id', pid);
				formData.append('quantity', '1');

				var response = await fetch(endpoint('add_to_cart'), {
					method: 'POST',
					body: formData,
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
				});

				if (!response.ok) {
					throw new Error('add to cart failed: HTTP ' + response.status);
				}

				var result = await response.json();
				if (result.error) {
					throw new Error('add to cart rejected');
				}

				btn.textContent = text('added', 'Agregado');
				await fetchCart(true);
				announce(text('added', 'Agregado al carrito.'));
				restoreFocusAfterRecommendation(recommendationIndex);
				if (window.jQuery) {
					window.jQuery(document.body).trigger('wc_fragment_refresh');
				}
			} catch (err) {
				var message = text('addError', 'No se pudo agregar. Intenta de nuevo.');
				if (statusContainer) {
					statusContainer.appendChild(createText('akb-cart-status__item akb-cart-status__item--error', message));
					statusContainer.hidden = false;
				}
				announce(message);
			} finally {
				recommendationPending = false;
				if (btn.isConnected) {
					btn.disabled = false;
					btn.removeAttribute('aria-busy');
					btn.textContent = defaultLabel;
				}
			}
		}

		function restoreFocusAfterRecommendation(previousIndex) {
			var nextButtons = itemsContainer.querySelectorAll('.akb-cs-add-btn');
			var fallback = closeBtn || drawer;
			var target = nextButtons.length
				? nextButtons[Math.min(previousIndex, nextButtons.length - 1)]
				: fallback;

			focusInsideDrawer(target);
		}

		async function fetchCart(forceRefresh) {
			abortCartRead();

			var requestId = cartReadSequence;
			var controller = typeof AbortController === 'function' ? new AbortController() : null;
			var requestOptions = { cache: 'no-store', credentials: 'same-origin' };
			var timedOut = false;
			var timeout = null;
			var hadItems = hasRenderedCart();
			if (controller) {
				requestOptions.signal = controller.signal;
				timeout = setTimeout(function () {
					timedOut = true;
					controller.abort();
				}, 10000);
			}
			cartReadController = controller;

			if (memoryCache && !forceRefresh) {
				renderCart(memoryCache, false);
				updateCounts(memoryCache.cart_count);
			} else if (!hasRenderedCart()) {
				// El esqueleto sólo tiene sentido cuando no hay nada que mirar. Con
				// un carrito ya pintado, sustituirlo por "Cargando..." apagaba el
				// footer entero (barra de envío, totales y "Pagar pedido") durante
				// todo el round-trip y lo devolvía después: un salto de ~248px.
				renderLoading();
			}

			try {
				var res = await fetch(endpoint('akb_get_cart'), requestOptions);
				if (!res.ok) {
					throw new Error('cart read failed: HTTP ' + res.status);
				}
				var json = await res.json();

				if (requestId !== cartReadSequence) {
					return false;
				}

				if (json.success === true && json.data && Array.isArray(json.data.items)
					&& Number.isFinite(json.data.cart_count) && json.data.cart_count >= 0
					&& (json.data.items.length ? json.data.cart_count > 0 : json.data.cart_count === 0)) {
					adoptNonce(json.data);
					memoryCache = json.data;
					// Capturar al recibir, no al iniciar: el cliente puede haber
					// movido el foco mientras esperaba. Mantener el mismo control
					// de producto/recomendación al reemplazar sus nodos.
					var active = document.activeElement;
					var focusedLabel = active && itemsContainer.contains(active) ? active.getAttribute('aria-label') : null;
					var point = focusedLabel ? captureRestorePoint() : null;
					// Una lectura inicial no es un cambio de estado: el contenido
					// visible ya comunica el carrito y repetirlo en aria-live haría
					// que lectores de pantalla oyeran el mismo copy dos veces.
					renderCart(json.data, false);
					updateCounts(json.data.cart_count);
					if (focusedLabel && json.data.items.length) {
						var replacement = Array.prototype.find.call(itemsContainer.querySelectorAll('button, input'), function (el) {
							return !el.disabled && el.getAttribute('aria-label') === focusedLabel;
						});
						if (replacement) {
							focusInsideDrawer(replacement);
						} else if (point.rowIndex >= 0) {
							restorePoint(point);
						} else {
							focusInsideDrawer(closeBtn);
						}
					}
					if (hadItems && !json.data.items.length) {
						var emptyMessage = text('cartEmptied', text('empty', 'Tu carrito está vacío.'));
						var emptyText = itemsContainer.querySelector('.akb-cart-empty__message');
						if (emptyText) {
							emptyText.textContent = emptyMessage;
						}
						announce(emptyMessage);
					}
					return true;
				}

				if (!memoryCache || forceRefresh) {
					renderError(text('error', 'Error al cargar el carrito.'));
				}
			} catch (error) {
				if (error && error.name === 'AbortError' && !timedOut) {
					return false;
				}
				if (requestId !== cartReadSequence) {
					return false;
				}
				if (!memoryCache || forceRefresh) {
					renderError(text('connection', 'Error de conexión.'));
				}
			} finally {
				clearTimeout(timeout);
				if (requestId === cartReadSequence) {
					cartReadController = null;
				}
			}

			return false;
		}

		function renderError(message) {
			memoryCache = null;
			clear(itemsContainer);
			var errorState = document.createElement('div');
			errorState.className = 'akb-cart-empty';
			errorState.appendChild(createText('akb-cart-empty__message', message, 'p'));

			var retryButton = document.createElement('button');
			retryButton.type = 'button';
			retryButton.className = 'akb-cart-empty__cta';
			retryButton.textContent = 'Reintentar';
			retryButton.addEventListener('click', function () {
				renderLoading();
				fetchCart(true);
			});
			errorState.appendChild(retryButton);
			itemsContainer.appendChild(errorState);
			if (footer) {
				footer.setAttribute('hidden', '');
			}
			announce(message);
			focusInsideDrawer(retryButton);
		}

		function abortCartRead() {
			cartReadSequence += 1;
			if (cartReadController) {
				cartReadController.abort();
				cartReadController = null;
			}
		}

		function endpoint(name) {
			return String(config.ajaxUrl || '').replace('%%endpoint%%', name);
		}

		// Los activadores que reciben el contador son los MISMOS que abren el
		// drawer: la clase por defecto más lo que el dueño haya configurado en
		// «Selector de activadores». Consultar sólo `.akb-cart-trigger` dejaba a un
		// activador con otra clase abriendo el carrito y sin mostrar nunca su
		// contador. El default va siempre unido porque el header del theme lo emite
		// aunque el ajuste apunte a otra cosa.
		function countTargetSelector() {
			var configured = typeof config.triggerSelector === 'string' ? config.triggerSelector.trim() : '';

			return configured && configured !== '.akb-cart-trigger'
				? '.akb-cart-trigger, ' + configured
				: '.akb-cart-trigger';
		}

		function updateCounts(count) {
			var val = String(count);
			var targets;

			// Mismo blindaje que bindConfiguredTriggers: un selector inválido
			// guardado por otra vía no puede tumbar el refresco del carrito.
			try {
				targets = document.querySelectorAll(countTargetSelector());
			} catch (error) {
				if (window.console && console.warn) {
					console.warn('Akibara NativeCart: selector inválido', error);
				}
				targets = document.querySelectorAll('.akb-cart-trigger');
			}

			targets.forEach(function (el) {
				el.setAttribute('data-akb-cart-count', val);
			});
		}

		// El nonce se adopta solo desde respuestas vivas, nunca desde cache.
		function adoptNonce(data) {
			if (data && data.nonce) {
				config.nonce = data.nonce;
			}
		}

		async function removeItems(keys) {
			await mutateWithRetry('akb_remove_cart_items', function () {
				var formData = new URLSearchParams();
				(keys || []).forEach(function (key) {
					formData.append('keys[]', key);
				});
				formData.append('nonce', config.nonce || '');
				return formData;
			});
		}

		async function updateItemQty(key, qty) {
			await mutateWithRetry('akb_update_cart_item', function () {
				var formData = new URLSearchParams();
				formData.append('key', key);
				formData.append('qty', qty);
				formData.append('nonce', config.nonce || '');
				return formData;
			});
		}

		/**
		 * Ejecuta la mutación con auto-sanado de nonce: ante un 403 (nonce vencido,
		 * típico de invitado con HTML cacheado 7 días), refresca el carrito — lo que
		 * adopta el nonce fresco del payload — y reintenta UNA vez (flag local: sin
		 * loop posible). Ambas mutaciones son idempotentes server-side (remove de una
		 * key ya removida es no-op; set_quantity re-aplica el mismo qty). Cualquier
		 * otro fallo, o el fallo del reintento, termina en fallo VISIBLE.
		 */
		async function mutateWithRetry(action, buildFormData) {
			// Una segunda mutación mientras la primera vuela dejaría dos escrituras
			// compitiendo por el mismo carrito y dos renders pisándose. La lista ya
			// está bloqueada por CSS; esto cubre además la activación por teclado.
			if (isMutating || isCheckingOut || recommendationPending) {
				return;
			}

			var point = captureRestorePoint();
			setBusy(true);
			try {
				await mutateCart(action, buildFormData(), point);
			} catch (error) {
				if (error && error.status === 403) {
					await fetchCart(true); // adopta nonce fresco si el server responde.
					try {
						await mutateCart(action, buildFormData(), point);
						return;
					} catch (retryError) {
						await showMutationError();
						return;
					}
				}
				await showMutationError();
			} finally {
				setBusy(false);
			}
		}

		// Fallo visible (antes: 100% silencioso): re-render veraz del carrito +
		// mensaje en la banda de estado + anuncio AT por la región aria-live.
		// El mensaje se limpia solo en el próximo render exitoso (renderStatus
		// vacía el contenedor en cada renderCart).
		async function showMutationError() {
			await fetchCart(true);
			var message = text('mutationError', 'No pudimos actualizar tu carrito. Intenta de nuevo.');
			if (statusContainer) {
				statusContainer.appendChild(createText('akb-cart-status__item akb-cart-status__item--error', message));
				statusContainer.hidden = false;
			}
			announce(message);
		}

		async function mutateCart(action, formData, point) {
			abortCartRead();

			var res = await fetch(endpoint(action), {
				method: 'POST',
				body: formData,
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded'
				}
			});

			// check_ajax_referer responde 403 con body "-1" (no-JSON): mirar el
			// status ANTES de res.json() o el parse revienta y el 403 se disfraza
			// de error genérico sin status (imposible de reintentar con criterio).
			if (!res.ok) {
				var httpError = new Error('cart mutation failed: HTTP ' + res.status);
				httpError.status = res.status;
				throw httpError;
			}

			var json = await res.json();

			if (!json.success) {
				throw new Error('cart mutation failed');
			}

			adoptNonce(json.data);
			memoryCache = json.data;
			// remove/update sí son mutaciones iniciadas por el usuario; sólo en ese
			// caso la región viva debe anunciar el nuevo estado.
			renderCart(json.data, true);
			updateCounts(json.data.cart_count);
			restorePoint(point);

			if (window.jQuery) {
				// El refresco de fragments de WC vuelve como `wc_fragments_refreshed`,
				// que este mismo módulo escucha. Sin la marca, ese eco disparaba un
				// segundo fetchCart(true) inmediatamente después de cada mutación:
				// otro round-trip y otro re-render sobre datos que ya eran los
				// buenos. Sólo interesa que el icono del header quede sincronizado.
				suppressNextFragmentRefresh = true;
				window.jQuery(document.body).trigger('wc_fragment_refresh');
			}
		}
	});
}());
