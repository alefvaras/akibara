/*!
 * Akibara GP — sticky-atc.js (solo ficha)
 *
 * Barra fija precio + CTA (#akb-sticky-atc). En ≤1024 px va apilada sobre el
 * bottom-nav; en escritorio, pegada al borde inferior (no hay bottom-nav). El
 * botón es un PROXY que clickea el .single_add_to_cart_button real: cantidad,
 * estados y validaciones de WooCommerce quedan intactos.
 *
 * La barra se muestra sólo si se cumplen las TRES condiciones:
 *
 *  1. El form.cart real no asoma por encima de la franja fija (bottom-nav +
 *     barra, tokens de tokens.css). Si el cliente ve el botón de verdad, la
 *     barra sobra.
 *  2. El título (H1) ha salido de la zona de solape inferior o se encuentra por
 *     encima de la barra fija, evitando oclusiones sobre el encabezado del tomo.
 *  3. El botón del configurador de packs cede la barra únicamente cuando el CTA
 *     del pack está visible Y habilitado en viewport (con tomos seleccionados).
 *
 * Todo con IntersectionObserver (sin oyentes de scroll) y un MutationObserver
 * sobre el `disabled` del botón del pack, que el armador cambia al elegir tomos.
 */
(function () {
	'use strict';

	var bar = document.getElementById('akb-sticky-atc');
	var form = document.querySelector('form.cart');
	if (!bar || !form || !('IntersectionObserver' in window)) return;

	var realBtn = form.querySelector('.single_add_to_cart_button');
	var proxyBtn = document.getElementById('akb-sticky-atc-btn');
	if (!realBtn || !proxyBtn) return;

	var titulo = document.querySelector('.product_title');
	var packBuilder = document.querySelector('[data-akb-sb-builder]');
	var packBtn = document.querySelector('[data-akb-sb-builder] [data-akb-sb-add]');
	var titleEl = bar.querySelector ? bar.querySelector('.akb-sticky-atc__title') : null;
	var priceEl = bar.querySelector ? bar.querySelector('.akb-sticky-atc__price') : null;
	var etaEl = bar.querySelector ? bar.querySelector('.akb-sticky-atc__eta') : null;
	var progressEl = document.getElementById('akb-sticky-atc-progress') || (bar.querySelector ? bar.querySelector('.akb-sticky-atc__progress') : null);
	var resetBtn = document.getElementById('akb-sticky-atc-reset') || (bar.querySelector ? bar.querySelector('.akb-sticky-atc__reset') : null);
	var badgeEl = document.getElementById('akb-sticky-atc-badge') || (bar.querySelector ? bar.querySelector('.akb-sticky-atc__badge') : null);
	var subtextEl = document.getElementById('akb-sticky-atc-subtext') || (bar.querySelector ? bar.querySelector('.akb-sticky-atc__subtext') : null);
	var infoEl = bar.querySelector ? bar.querySelector('.akb-sticky-atc__info') : null;

	var originalTitle = titleEl ? titleEl.textContent : '';
	var originalPriceHtml = priceEl ? priceEl.innerHTML : '';
	var originalBtnText = proxyBtn ? proxyBtn.textContent : '';

	function parseTiers(raw) {
		if (!raw) return [{ min: 5, pct: 8 }, { min: 7, pct: 10 }];
		try {
			var arr = JSON.parse(raw);
			return Array.isArray(arr) ? arr.map(function (t) {
				return { min: parseInt(t.min, 10) || 0, pct: parseInt(t.pct, 10) || 0 };
			}) : [{ min: 5, pct: 8 }, { min: 7, pct: 10 }];
		} catch (e) {
			return [{ min: 5, pct: 8 }, { min: 7, pct: 10 }];
		}
	}

	function resolvePct(tiers, qty) {
		var best = 0;
		var bestMin = -1;
		tiers.forEach(function (tier) {
			if (qty >= tier.min && tier.min > bestMin) {
				bestMin = tier.min;
				best = tier.pct;
			}
		});
		return best;
	}

	function getCheckedBoxes() {
		return packBuilder && packBuilder.querySelectorAll
			? Array.prototype.slice.call(packBuilder.querySelectorAll('[data-akb-sb-vol]:checked'))
			: [];
	}

	function isPackActive() {
		if (!packBtn || !packBuilder) return false;
		var checks = getCheckedBoxes();
		var minChecks = (window.matchMedia && window.matchMedia('(max-width: 1024px)').matches) ? 1 : 2;
		return !packBtn.disabled && checks.length >= minChecks;
	}

	function syncPackState() {
		if (!packBtn || !packBuilder) return;
		var checks = getCheckedBoxes();
		var countChecks = checks.length;
		var minChecks = (window.matchMedia && window.matchMedia('(max-width: 1024px)').matches) ? 1 : 2;
		var active = !packBtn.disabled && countChecks >= minChecks;
		bar.classList.toggle('is-pack-mode', active);

		if (active) {
			var serie = typeof packBuilder.getAttribute === 'function' ? (packBuilder.getAttribute('data-serie') || '') : '';
			var totalEl = packBuilder.querySelector ? packBuilder.querySelector('[data-akb-sb-total]') : null;
			var packTotal = '';
			var numericTotal = 0;
			if (totalEl) {
				var attrLabel = typeof totalEl.getAttribute === 'function' ? totalEl.getAttribute('data-akb-sb-total-label') : null;
				var attrVal = typeof totalEl.getAttribute === 'function' ? totalEl.getAttribute('data-akb-sb-total-value') : null;
				if (attrVal) {
					numericTotal = parseFloat(attrVal) || 0;
				}
				if (attrLabel) {
					packTotal = attrLabel.trim();
				} else if (totalEl.textContent) {
					packTotal = totalEl.textContent.replace(/^Total estimado:\s*/, '').trim();
				}
			}

			var inStockChecks = 0;
			var preorderChecks = 0;
			var preorderDates = [];
			for (var i = 0; i < checks.length; i++) {
				var c = checks[i];
				var isNotEligible = (c.getAttribute && c.getAttribute('data-discount-eligible') === '0');
				var hasDelayedEta = (c.getAttribute && c.getAttribute('data-akb-sb-preventa') === '1');
				if (hasDelayedEta) {
					var card = c.closest ? c.closest('.akb-sb-builder__card') : null;
					var etaNode = card && card.querySelector ? card.querySelector('.akb-sb-builder__vol-preventa') : null;
					var etaText = etaNode ? etaNode.textContent.trim().replace(/^Llega\s+/i, '') : '';
					if (etaText && preorderDates.indexOf(etaText) === -1) {
						preorderDates.push(etaText);
					}
				}
				if (isNotEligible) {
					preorderChecks++;
				} else {
					inStockChecks++;
				}
			}

			var rawTiers = typeof packBuilder.getAttribute === 'function' ? packBuilder.getAttribute('data-tiers') : '';
			var tiers = parseTiers(rawTiers);
			var freeShipThreshold = (typeof packBuilder.getAttribute === 'function' ? parseFloat(packBuilder.getAttribute('data-free-ship')) : 0) || 60000;
			var pct = resolvePct(tiers, inStockChecks);

			if (titleEl) {
				titleEl.textContent = 'Pack ' + (serie ? serie + ' ' : '') + '(' + countChecks + (countChecks === 1 ? ' tomo' : ' tomos') + ')';
			}
			if (priceEl && packTotal) {
				priceEl.innerHTML = '<span class="woocommerce-Price-amount amount">' + packTotal + '</span>';
			}
			if (etaEl) {
				etaEl.style.display = 'none';
			}

			if (badgeEl) {
				if (pct > 0) {
					badgeEl.textContent = '[-' + pct + '%]';
					badgeEl.hidden = false;
				} else {
					badgeEl.textContent = '';
					badgeEl.hidden = true;
				}
			}

			if (resetBtn) {
				resetBtn.hidden = false;
			}

			var subtext = '';
			var freeShipReached = numericTotal >= freeShipThreshold;

			if (inStockChecks > 0 && preorderChecks === 0) {
				if (inStockChecks < 5) {
					var falta = 5 - inStockChecks;
					subtext = 'Suma ' + falta + (falta === 1 ? ' más' : ' más') + ' en stock para 8% dto.';
				} else if (inStockChecks < 7) {
					var falta = 7 - inStockChecks;
					subtext = 'Suma ' + falta + (falta === 1 ? ' más' : ' más') + ' para 10% dto.' + (freeShipReached ? ' · ✓ Envío GRATIS' : ' + Envío GRATIS');
				} else {
					subtext = '✓ 10% dto. aplicado' + (freeShipReached ? ' · ✓ Envío GRATIS' : '');
				}
			} else if (inStockChecks > 0 && preorderChecks > 0) {
				var dateStr = preorderDates.length > 0 ? ' (' + preorderDates.join(', ') + ')' : '';
				if (inStockChecks < 5) {
					var falta = 5 - inStockChecks;
					subtext = inStockChecks + ' en stock + ' + preorderChecks + ' a pedido' + dateStr + ' · +' + falta + ' stock = 8% dto.';
				} else if (inStockChecks < 7) {
					var falta = 7 - inStockChecks;
					subtext = inStockChecks + ' en stock + ' + preorderChecks + ' a pedido' + dateStr + ' · +' + falta + ' stock = 10% dto.';
				} else {
					subtext = inStockChecks + ' en stock + ' + preorderChecks + ' a pedido' + dateStr + ' · ✓ 10% dto. aplicado';
				}
			} else if (inStockChecks === 0 && preorderChecks > 0) {
				var dateStr = preorderDates.length > 0 ? ' (' + preorderDates.join(', ') + ')' : '';
				subtext = preorderChecks + (preorderChecks === 1 ? ' tomo a pedido' : ' tomos a pedido') + dateStr + (freeShipReached ? ' · ✓ Envío GRATIS' : '');
			}

			if (subtextEl) {
				subtextEl.textContent = subtext;
				subtextEl.hidden = !subtext;
			}

			if (progressEl) {
				var progressPct = Math.min(100, Math.round((inStockChecks / 7) * 100));
				if (inStockChecks >= 7 || freeShipReached) {
					progressEl.style.width = '100%';
					progressEl.style.backgroundColor = '#16A34A';
				} else {
					progressEl.style.width = Math.max(14, progressPct) + '%';
					progressEl.style.backgroundColor = '#F59E0B';
				}
				progressEl.style.opacity = '1';
			}

			if (proxyBtn) {
				proxyBtn.textContent = 'Añadir pack (' + countChecks + ')';
			}

			if (infoEl) {
				infoEl.classList.remove('is-pulsing');
				void infoEl.offsetWidth;
				infoEl.classList.add('is-pulsing');
			}
		} else {
			if (titleEl) {
				titleEl.textContent = originalTitle;
			}
			if (priceEl) {
				priceEl.innerHTML = originalPriceHtml;
			}
			if (etaEl) {
				etaEl.style.display = '';
			}
			if (badgeEl) {
				badgeEl.textContent = '';
				badgeEl.hidden = true;
			}
			if (resetBtn) {
				resetBtn.hidden = true;
			}
			if (subtextEl) {
				subtextEl.textContent = '';
				subtextEl.hidden = true;
			}
			if (progressEl) {
				progressEl.style.width = '0%';
				progressEl.style.opacity = '0';
			}
			if (proxyBtn) {
				proxyBtn.textContent = originalBtnText;
			}
		}
	}

	if (resetBtn) {
		resetBtn.addEventListener('click', function (e) {
			if (e && typeof e.stopPropagation === 'function') {
				e.stopPropagation();
			}
			if (!packBuilder) return;
			var checked = packBuilder.querySelectorAll ? packBuilder.querySelectorAll('[data-akb-sb-vol]:checked') : [];
			for (var i = 0; i < checked.length; i++) {
				checked[i].checked = false;
			}
			var ev = document.createEvent ? document.createEvent('Event') : new Event('change', { bubbles: true });
			if (document.createEvent) {
				ev.initEvent('change', true, true);
			}
			packBuilder.dispatchEvent(ev);
			syncPackState();
			syncVisibility();
		});
	}

	proxyBtn.addEventListener('click', function () {
		if (isPackActive()) {
			packBtn.click();
			return;
		}
		// Con el POST normal de camino (antes de que enganche pdp-ajax-atc.js) el
		// formulario queda `inert` (toque-temprano.js), pero un click() por código
		// lo salta: cada toque en la barra mandaba otra alta (dos toques, dos POST,
		// medido en el espejo en Chromium y WebKit a 390 táctil).
		if (form.hasAttribute('inert')) return;
		realBtn.click();
	});

	var formFuera = false;
	// Sin H1 no hay título que tapar: la condición 2 se da por cumplida.
	var tituloLibre = !titulo;
	var packEnPantalla = false;

	function packManda() {
		return packEnPantalla && !packBtn.disabled;
	}

	function syncVisibility() {
		bar.classList.toggle('is-visible', formFuera && tituloLibre && !(packBtn && packManda()));
	}

	// Alturas de la franja leídas de los tokens de tokens.css (que salen de
	// theme.json), no escritas a mano. Los tokens computan en px («66px»); si no
	// resolvieran, los mismos respaldos que usan las hojas.
	var px = function (nombre, respaldo) {
		var valor = getComputedStyle(document.documentElement).getPropertyValue(nombre).trim();
		return /^\d+(\.\d+)?px$/.test(valor) ? parseFloat(valor) : respaldo;
	};

	// Mismo corte que woocommerce.css y footer.css: el bottom-nav existe hasta
	// 1024 px; por encima la barra se apoya en el borde de la ventana.
	var escritorio = window.matchMedia ? window.matchMedia('(min-width: 1025px)') : null;
	var observadores = [];

	// Altura computada de la barra en runtime. Para productos en reserva con metadatos
	// adicionales, la altura excede el valor base del token CSS (--buy-bar-height).
	// Se monitoriza vía ResizeObserver tras el renderizado para evitar layout forzado en la carga.
	var altoMedido = 0;

	// Cuánto se estira hacia arriba la raíz del observador del título (ver
	// observar()): más que cualquier ficha, para que «por encima» no tenga fin.
	var SIN_TECHO = 100000;

	function ultima(entries) {
		return entries[entries.length - 1];
	}

	function observar() {
		observadores.forEach(function (o) { o.disconnect(); });
		observadores = [];

		var altoNav = escritorio && escritorio.matches ? 0 : px('--bottom-nav-height', 66);
		var altoBarra = altoMedido || px('--buy-bar-height', 68);
		// rootMargin bottom = la franja fija entera: el form cuenta como
		// «visible» sólo cuando asoma POR ENCIMA de ella. El margen es constante,
		// así que no hay parpadeo en el borde al ocultar la barra.
		var margenFranja = '0px 0px -' + (altoNav + altoBarra) + 'px 0px';

		var ioForm = new IntersectionObserver(function (entries) {
			formFuera = !ultima(entries).isIntersecting;
			syncVisibility();
		}, { rootMargin: margenFranja });
		ioForm.observe(form);
		observadores.push(ioForm);

		if (titulo) {
			// El borde inferior de la raíz es el techo de la barra, y el superior
			// se estira hacia arriba sin límite práctico: así «el título está
			// entero por encima de la barra» (en pantalla o ya fuera por arriba)
			// es exactamente «intersección completa», y entrar o salir de ese
			// estado SIEMPRE cruza un umbral. Con la raíz del tamaño de la ventana
			// no: un salto de scroll de «bajo la barra» a «fuera por arriba» (un
			// ancla, volver atrás, scrollTo) no cruzaba nada, el observador no
			// avisaba y la barra se quedaba escondida (medido en el espejo al
			// saltar 1.300 px de golpe).
			var ioTitulo = new IntersectionObserver(function (entries) {
				var e = ultima(entries);
				var techo = e.rootBounds ? e.rootBounds.bottom : window.innerHeight - altoNav - altoBarra;
				tituloLibre = e.isIntersecting && e.boundingClientRect.bottom <= techo + 1;
				syncVisibility();
			}, { rootMargin: SIN_TECHO + 'px 0px -' + (altoNav + altoBarra) + 'px 0px', threshold: [0, 1] });
			ioTitulo.observe(titulo);
			observadores.push(ioTitulo);
		}

		if (packBtn) {
			// El botón del pack cuenta en cuanto asoma sobre el bottom-nav: la
			// barra se retira y lo deja a la vista entero.
			var ioPack = new IntersectionObserver(function (entries) {
				packEnPantalla = ultima(entries).isIntersecting;
				syncVisibility();
			}, { rootMargin: '0px 0px -' + altoNav + 'px 0px' });
			ioPack.observe(packBtn);
			observadores.push(ioPack);
		}
	}

	observar();

	if ('ResizeObserver' in window) {
		new ResizeObserver(function (entries) {
			var e = ultima(entries);
			var caja = e.borderBoxSize && e.borderBoxSize[0];
			var alto = Math.round(caja ? caja.blockSize : bar.getBoundingClientRect().height);
			// Oculta en móvil sigue maquetada (visibility), así que mide siempre;
			// 0 sólo si la regla de la barra no aplica (display: none).
			if (alto > 0 && alto !== altoMedido) {
				altoMedido = alto;
				observar();
			}
		}).observe(bar);
	}

	if (packBtn && 'MutationObserver' in window) {
		new MutationObserver(function () {
			syncPackState();
			syncVisibility();
		}).observe(packBtn, { attributes: true, attributeFilter: ['disabled'] });
	}

	if (packBuilder && typeof packBuilder.addEventListener === 'function') {
		packBuilder.addEventListener('change', function () {
			setTimeout(function () {
				syncPackState();
				syncVisibility();
			}, 20);
		});
	}

	if (typeof document.addEventListener === 'function') {
		document.addEventListener('akb-sb-refresh', function () {
			syncPackState();
			syncVisibility();
		});
	}

	// Al cruzar el corte de 1024 px cambia la franja (aparece o desaparece el
	// bottom-nav): los márgenes se recalculan con la geometría nueva.
	if (escritorio) {
		if (typeof escritorio.addEventListener === 'function') {
			escritorio.addEventListener('change', observar);
		} else if (typeof escritorio.addListener === 'function') {
			escritorio.addListener(observar);
		}
	}
})();
