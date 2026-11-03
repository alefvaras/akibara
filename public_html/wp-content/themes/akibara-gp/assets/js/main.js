/*!
 * Akibara GP — main.js
 *
 * Solo UI behavior: hamburger toggle, scroll progress,
 * footer accordion mobile, keyboard ESC handling.
 * Cero llamadas a APIs, cero business logic, cero queries WP.
 */
(function () {
	'use strict';

	var doc = document;
	var body = doc.body;

	/* ── Skip-link: mover el FOCO al landmark, no solo el scroll ──
	 * El skip-link apunta a #main (el <main> que emite cada template child o el
	 * padre GeneratePress). Un ancla a un elemento sin tabindex scrollea pero en
	 * varios browsers/lectores de pantalla NO mueve el foco de teclado: el
	 * siguiente Tab reanudaría desde el header. Al activarlo le damos tabindex=-1
	 * (no entra al orden de tabulación) y lo enfocamos, así el foco entra al
	 * contenido y el SR anuncia el landmark (WCAG 2.4.1). Sin JS, el navegador
	 * hace el scroll nativo. Delegado en document → cubre todas las páginas
	 * (templates child y GeneratePress) sin acoplarse al markup.
	 *
	 * Idéntico principio para «Saltar a los filtros» del catálogo (inc/flyout-button.php):
	 * enfoca el formulario de filtros para que el siguiente Tab entre a la primera faceta.
	 * Delegado también sobrevive al PJAX del buscador al repintar la fila. */
	doc.addEventListener('click', function (e) {
		var link = e.target && e.target.closest ? e.target.closest('a.skip-link, a.akb-skip-filtros') : null;
		if (!link) return;
		var href = link.getAttribute('href') || '';
		if (href.charAt(0) !== '#' || href.length < 2) return;
		var target = doc.getElementById(href.slice(1));
		if (!target) return;
		if (!target.hasAttribute('tabindex')) {
			target.setAttribute('tabindex', '-1');
		}
		target.focus();
	});

	/* ── El foco que cae en la cabecera fija no mueve la página (QAM-1) ──
	 * La cabecera es sticky y html tiene scroll-padding-top para el contenido.
	 * Al enfocar elementos dentro de la cabecera fija, los navegadores pueden intentar
	 * desplazar el viewport asumiendo que el elemento está cubierto.
	 * Se restaura la posición de scroll previa si ocurre un salto no intencionado. */
	var cabeceraFija = doc.querySelector('.site-header');
	if (cabeceraFija) {
		var VIGILANCIA_FOCO_MS = 400;
		var yVista = window.pageYOffset;
		var vigilancia = null; // { y, hasta } mientras se vigila un foco recién llegado.
		var devolver = function () {
			if (vigilancia && window.pageYOffset < vigilancia.y) {
				window.scrollTo(window.pageXOffset, vigilancia.y);
				return true;
			}
			return false;
		};
		window.addEventListener('scroll', function () {
			if (vigilancia && Date.now() > vigilancia.hasta) {
				vigilancia = null;
			}
			if (vigilancia && devolver()) {
				return;
			}
			yVista = window.pageYOffset;
		}, { passive: true });
		cabeceraFija.addEventListener('focusin', function () {
			vigilancia = { y: yVista, hasta: Date.now() + VIGILANCIA_FOCO_MS };
			devolver();
		});
		['wheel', 'touchstart', 'keydown', 'pointerdown'].forEach(function (tipo) {
			window.addEventListener(tipo, function () {
				vigilancia = null;
			}, { capture: true, passive: true });
		});
	}

	/* ── Mobile drawer toggle ───────────────────────────────────── */
	var menuToggle = doc.getElementById('menu-toggle');
	var drawer = doc.getElementById('mobile-drawer');
	var overlay = doc.getElementById('mobile-overlay');
	var drawerClose = doc.getElementById('mobile-close');

	// Elementos del documento que quedan fuera del drawer cuando abre. Reciben
	// `inert` + `aria-hidden=true` para que SR/teclado no naveguen elementos
	// visualmente ocultos detrás del drawer (iconos del header, content, footer).
	// #main / .site-main NO van acá: son hijos de #main-content, así que el `inert`
	// que recibe #main-content ya los cubre por cascada (evita doble aplicación).
	var outsideSelectors = '.site-header, #main-content, .site-footer, .bottom-nav, #akb-ann-bar';
	var lastFocus = null;

	function getDrawerFocusable() {
		if (!drawer) return [];
		var sel = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
		return Array.prototype.slice.call(drawer.querySelectorAll(sel)).filter(function (el) {
			return el.offsetWidth > 0 || el.offsetHeight > 0;
		});
	}

	function setElementInert(el, state) {
		if (!el) return;
		if (state) {
			el.setAttribute('inert', '');
			el.setAttribute('aria-hidden', 'true');
		} else {
			el.removeAttribute('inert');
			el.removeAttribute('aria-hidden');
		}
	}

	function setOutsideInert(state) {
		doc.querySelectorAll(outsideSelectors).forEach(function (el) {
			if (drawer && el.contains(drawer)) {
				Array.prototype.slice.call(el.children).forEach(function (child) {
					if (child === drawer || child === overlay || child.contains(drawer) || child.contains(overlay)) return;
					setElementInert(child, state);
				});
				return;
			}
			setElementInert(el, state);
		});
	}

	function showDrawerSurfaces() {
		if (drawer) {
			drawer.style.display = 'block';
			drawer.style.transform = 'translateX(0)';
		}
		if (overlay) {
			overlay.style.display = 'block';
			overlay.style.opacity = '1';
			overlay.style.visibility = 'visible';
			overlay.style.pointerEvents = 'auto';
		}
	}

	/*
	 * Aplica `inert` y `aria-hidden` sobre el resto del documento en la tarea posterior
	 * al renderizado del primer cuadro visual (`afterNextPaint`), protegiendo el INP y la
	 * respuesta inmediata de la interacción al diferir el recálculo masivo de estilos.
	 * Evalúa el estado vigente en el momento de ejecución para evitar condiciones de carrera.
	 */
	function afterNextPaint(fn) {
		window.requestAnimationFrame(function () {
			window.setTimeout(fn, 0);
		});
	}

	function hideDrawerSurfaces() {
		if (drawer) {
			drawer.style.removeProperty('display');
			drawer.style.removeProperty('transform');
		}
		if (overlay) {
			overlay.style.removeProperty('display');
			overlay.style.removeProperty('opacity');
			overlay.style.removeProperty('visibility');
			overlay.style.removeProperty('pointer-events');
		}
	}

	function openDrawer(focusTarget) {
		if (!drawer || !overlay || !menuToggle) return;
		lastFocus = doc.activeElement;
		drawer.classList.add('open');
		drawer.setAttribute('aria-hidden', 'false');
		overlay.classList.add('open');
		overlay.setAttribute('aria-hidden', 'false');
		showDrawerSurfaces();
		menuToggle.classList.add('active');
		menuToggle.setAttribute('aria-expanded', 'true');
		menuToggle.setAttribute('aria-label', 'Cerrar menú');
		body.style.overflow = 'hidden';
		afterNextPaint(function () {
			if (drawer.classList.contains('open')) {
				setOutsideInert(true);
			}
		});
		// Foco al destino indicado (ej. el input de búsqueda cuando se abre desde
		// el tab "Buscar") o, por defecto, al primer focuseable del drawer (close).
		// setTimeout evita race con la transición; refuerza el foco síncrono de iOS.
		setTimeout(function () {
			var first = focusTarget || drawerClose || getDrawerFocusable()[0];
			if (first) first.focus();
		}, 50);
	}

	function closeDrawer() {
		if (!drawer || !overlay || !menuToggle) return;
		// Capturar ANTES de quitar clases: decide a qué trigger vuelve el foco.
		var closingSearchSheet = drawer.classList.contains('mobile-drawer--search');
		drawer.classList.remove('open');
		if (drawer.classList.contains('mobile-drawer--search')) {
			drawer.classList.remove('mobile-drawer--search');
			drawer.removeAttribute('aria-label');
			drawer.setAttribute('aria-labelledby', 'mobile-drawer-title');
		}
		var bottomSearchBtn = doc.getElementById('bottom-nav-search');
		if (bottomSearchBtn) bottomSearchBtn.setAttribute('aria-expanded', 'false');
		overlay.classList.remove('mobile-overlay--search');
		drawer.setAttribute('aria-hidden', 'true');
		overlay.classList.remove('open');
		overlay.setAttribute('aria-hidden', 'true');
		hideDrawerSurfaces();
		menuToggle.classList.remove('active');
		menuToggle.setAttribute('aria-expanded', 'false');
		menuToggle.setAttribute('aria-label', 'Abrir menú');
		body.style.overflow = '';
		// Restore focus al elemento activador previo con fallback defensivo al botón disparador.
		var wasSearchSheet = closingSearchSheet
			|| (lastFocus && lastFocus.id === 'bottom-nav-search');
		var fallback = wasSearchSheet ? doc.getElementById('bottom-nav-search') : menuToggle;
		var valid = lastFocus && lastFocus !== body && lastFocus !== doc.documentElement
			&& typeof lastFocus.focus === 'function';
		var target = valid ? lastFocus : fallback;
		lastFocus = null;
		// El foco vuelve DESPUÉS de soltar el `inert`: un elemento inerte no
		// acepta foco, y el focus() síncrono de antes forzaba ahí mismo el
		// recálculo de todo el documento dentro del toque (ver afterNextPaint).
		//
		// preventScroll: evita desplazamiento no deseado del viewport ya que el disparador
		// reside en un contenedor fijo o sticky con scroll-padding-top configurado.
		afterNextPaint(function () {
			if (drawer.classList.contains('open')) {
				return; // Se volvió a abrir: manda ese estado.
			}
			setOutsideInert(false);
			if (target) target.focus({ preventScroll: true });
		});
	}

	if (menuToggle) {
		menuToggle.addEventListener('click', function () {
			if (drawer && drawer.classList.contains('open')) {
				closeDrawer();
			} else {
				openDrawer();
			}
		});
	}

	if (drawerClose) drawerClose.addEventListener('click', closeDrawer);
	if (overlay) overlay.addEventListener('click', closeDrawer);

	// Focus trap: Tab/Shift+Tab rotan dentro del drawer cuando llegan al borde.
	if (drawer) {
		drawer.addEventListener('keydown', function (e) {
			if (e.key !== 'Tab') return;
			if (!drawer.classList.contains('open')) return;
			var focusable = getDrawerFocusable();
			if (!focusable.length) return;
			var first = focusable[0];
			var last = focusable[focusable.length - 1];
			if (e.shiftKey && doc.activeElement === first) {
				e.preventDefault();
				last.focus();
			} else if (!e.shiftKey && doc.activeElement === last) {
				e.preventDefault();
				first.focus();
			}
		});
	}

	/* ── Mobile drawer accordion (Manga / Cómics submenús) ──────── */
	var drawerToggles = doc.querySelectorAll('.mobile-drawer__toggle');

	function setDrawerAccordionState(btn, sub, isOpen) {
		sub.classList.toggle('open', isOpen);
		btn.classList.toggle('open', isOpen);
		btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
	}

	drawerToggles.forEach(function (btn) {
		var targetId = btn.getAttribute('data-target');
		var sub = targetId ? doc.getElementById(targetId) : null;
		if (!sub) return;

		// En archivos de editorial, abre de entrada la rama que contiene el
		// enlace actual. El item ya viene marcado por MenuInjector/walker; acá
		// sincronizamos el estado visual y ARIA para que la ubicación no quede
		// escondida al abrir el drawer.
		var isCurrentBranch = btn.classList.contains('mobile-drawer__link--active')
			|| btn.classList.contains('mobile-drawer__subtoggle--active');
		if (isCurrentBranch) {
			setDrawerAccordionState(btn, sub, true);
		}

		btn.addEventListener('click', function () {
			setDrawerAccordionState(btn, sub, !sub.classList.contains('open'));
		});
	});

	/* ── Footer column accordion (mobile only) ──────────────────── */
	var footerEl = doc.querySelector('.site-footer');
	var footerToggles = doc.querySelectorAll('.footer-column__toggle');
	// 600 px, no 768: desde ahí caben las tres columnas y plegarlas sólo
	// añade un toque para leer enlaces visibles. El mismo umbral está en
	// assets/css/footer.css; mover uno sin el otro descuadra el botón
	// respecto de lo que se ve.
	var footerAccordionMedia = window.matchMedia('(max-width: 600px)');

	function setFooterPanelA11y(list, isOpen) {
		if (!list) return;
		if (isOpen) {
			list.removeAttribute('aria-hidden');
			list.removeAttribute('inert');
		} else {
			// Se retira del árbol/foco inmediatamente; la visibilidad CSS puede
			// esperar 300ms para completar la animación de cierre.
			list.setAttribute('aria-hidden', 'true');
			list.setAttribute('inert', '');
		}
	}

	function syncFooterAccordionMode() {
		var isMobile = footerAccordionMedia.matches;
		footerToggles.forEach(function (btn) {
			var listId = btn.getAttribute('aria-controls');
			var list = listId ? doc.getElementById(listId) : null;
			if (!list) return;
			var isOpen = !isMobile || list.classList.contains('active');
			btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
			setFooterPanelA11y(list, isOpen);
		});
	}

	footerToggles.forEach(function (btn) {
		btn.addEventListener('click', function () {
			if (!footerAccordionMedia.matches) return;
			var listId = btn.getAttribute('aria-controls');
			if (!listId) return;
			var list = doc.getElementById(listId);
			if (!list) return;
			var isOpen = list.classList.toggle('active');
			btn.classList.toggle('active', isOpen);
			btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
			setFooterPanelA11y(list, isOpen);
		});
	});
	// Mejora progresiva: activa el colapso accesible en footer.css (<= 600px) solo
	// tras la inicialización exitosa de los controladores de acordeón.
	if (footerEl) {
		footerEl.classList.add('js-footer-accordion');
	}
	syncFooterAccordionMode();
	if (typeof footerAccordionMedia.addEventListener === 'function') {
		footerAccordionMedia.addEventListener('change', syncFooterAccordionMode);
	} else {
		footerAccordionMedia.addListener(syncFooterAccordionMode);
	}

	/* ── Bottom-nav buscar → abre drawer ENFOCANDO el input de búsqueda ──
	 * Antes llamaba openDrawer directo: el tab "Buscar" abría el menú completo
	 * con foco en la × (afordancia mentirosa). Ahora enfoca el input de búsqueda.
	 * El foco va SÍNCRONO dentro del gesto del tap porque iOS Safari solo abre
	 * el teclado nativo si el .focus() ocurre dentro del handler del usuario;
	 * el setTimeout interno de openDrawer solo lo refuerza. NOTA: no pasar
	 * openDrawer directo como listener — recibiría el Event como focusTarget. */
	var bottomSearch = doc.getElementById('bottom-nav-search');
	var searchCancel = doc.getElementById('akb-search-cancel');
	if (bottomSearch) {
		bottomSearch.addEventListener('click', function () {
			var input = doc.getElementById('akb-mobile-search-input');
			// SEARCH SHEET: el drawer pasa a full-screen con el input en la fila
			// superior y «Cancelar» (ver header.css). El scrim se vuelve
			// transparente porque ya no hay "fuera" al que tocar. Ambas clases se
			// retiran en closeDrawer para que el hamburger abra el menú clásico.
			if (drawer) {
				drawer.classList.add('mobile-drawer--search');
				// El título «Menú» queda display:none: el diálogo debe anunciarse
				// como búsqueda para lectores de pantalla.
				drawer.setAttribute('aria-label', 'Buscar productos');
				drawer.removeAttribute('aria-labelledby');
			}
			if (overlay) overlay.classList.add('mobile-overlay--search');
			bottomSearch.setAttribute('aria-expanded', 'true');
			openDrawer(input);
			if (input) {
				// focus() síncrono para garantizar apertura del teclado en dispositivos móviles.
				input.focus();
				// Emisión diferida en el siguiente tick para garantizar despliegue del panel de sugerencias.
				doc.defaultView.setTimeout(function () {
					if (doc.activeElement === input) {
						input.dispatchEvent(new Event('focus'));
					}
				}, 0);
			}
		});
	}
	if (searchCancel) {
		searchCancel.addEventListener('click', function () {
			// Cancelar = descartar la búsqueda: limpiar el campo (compartido con el
			// menú lateral; si no, el término reaparecía al abrir ☰) y avisar al
			// suggest para que cierre su panel y olvide lastQuery.
			var input = doc.getElementById('akb-mobile-search-input');
			if (input && input.value) {
				input.value = '';
				input.dispatchEvent(new Event('input'));
			}
			// closeDrawer() devuelve el foco al tab «Buscar» (cierre de la hoja de
			// búsqueda), una vez soltado el `inert` de la barra inferior.
			closeDrawer();
		});
	}
	// Estado ARIA del tab «Buscar»: controla el mismo diálogo que ☰.
	if (bottomSearch && drawer) {
		bottomSearch.setAttribute('aria-controls', drawer.id || 'mobile-drawer');
		bottomSearch.setAttribute('aria-expanded', 'false');
		bottomSearch.setAttribute('aria-haspopup', 'dialog');
	}

	/* ── Scroll progress bar ──────────────────────────────────────
	 * `#aki-scroll-progress` lo emite header.php SIN condición, así que la
	 * rama de respaldo que había aquí —un listener de scroll sin rAF que sólo
	 * compactaba el header cuando la barra faltaba— no podía registrarse
	 * nunca. Se retiró: la compactación vive dentro de updateProgress(), que
	 * sí corre en cada scroll y va con requestAnimationFrame. */
	var progress = doc.getElementById('aki-scroll-progress');
	var siteHeader = doc.querySelector('.site-header');
	/* Histéresis de compactación de cabecera:
	 * Se compacta al sobrepasar 120 px y se restablece únicamente por debajo de 60 px.
	 * La diferencia de umbral (60 px) supera la variación de altura (26 px, de 130 a 104 px),
	 * previniendo oscilaciones y bucles de scroll-anchoring en los navegadores. */
	var CABECERA_COMPACTA_DESDE = 120;
	var CABECERA_ENTERA_BAJO = 60;
	if (progress) {
		var ticking = false;
		// Transform scaleX para delegar la animación al compositor gráfico.
		function updateProgress() {
			var docEl = doc.documentElement;
			var scrollRange = Math.max(1, docEl.scrollHeight - docEl.clientHeight);
			var ratio = Math.min(1, Math.max(0, docEl.scrollTop / scrollRange));
			progress.style.transform = 'scaleX(' + ratio + ')';
			if (siteHeader) {
				siteHeader.classList.toggle('is-compact', siteHeader.classList.contains('is-compact')
					? docEl.scrollTop >= CABECERA_ENTERA_BAJO
					: docEl.scrollTop > CABECERA_COMPACTA_DESDE);
			}
			ticking = false;
		}
		function requestProgress() {
			if (!ticking) {
				window.requestAnimationFrame(updateProgress);
				ticking = true;
			}
		}
		window.addEventListener('scroll', requestProgress, { passive: true });
		// Lectura inicial de dimensiones diferida al siguiente frame para evitar
		// layout thrashing síncrono durante la fase de inicialización.
		requestProgress();
	}

	/* ── Desktop nav: submenús como «disclosure» accesible ──────────
	 * Cada elemento con submenú combina navegación directa con un botón
	 * accesorio (.mainnav__toggle) que gestiona aria-expanded y aria-controls.
	 *
	 * Modos de apertura:
	 *   - Interacción de puntero (:hover): se abre temporalmente y se cierra al salir.
	 *   - Fijado (data-pinned): activado mediante click/touch, persiste hasta Escape,
	 *     nuevo click en toggle o click externo.
	 *
	 * Filtra pointerType === 'touch' para evitar colisiones entre eventos táctiles y mouseenter.
	 * La clase `mainnav--js` activa los comportamientos dinámicos preservando :focus-within como fallback. */
	var FLYOUT_ITEMS = '.mainnav__item--has-sub, .mainnav__sub-item--has-sub';
	var desktopFlyouts = doc.querySelectorAll(FLYOUT_ITEMS);
	var mainnav = doc.querySelector('.mainnav');
	if (mainnav && mainnav.querySelector('.mainnav__toggle')) mainnav.classList.add('mainnav--js');

	function directChildWithClass(el, className) {
		if (!el || !el.children) return null;
		for (var i = 0; i < el.children.length; i++) {
			if (el.children[i].classList && el.children[i].classList.contains(className)) {
				return el.children[i];
			}
		}
		return null;
	}

	function getFlyoutLink(item) {
		return directChildWithClass(item, 'mainnav__link') || directChildWithClass(item, 'mainnav__sublink');
	}

	function getFlyoutToggle(item) {
		return directChildWithClass(item, 'mainnav__toggle');
	}

	function isFlyoutOpen(item) {
		return item.classList.contains('mainnav__flyout--open');
	}

	function isFlyoutPinned(item) {
		return item.hasAttribute('data-pinned');
	}

	// ¿El foco está en el panel del ítem (no en su enlace ni en su botón)?
	function focusInPanel(item) {
		var active = doc.activeElement;
		return !!active && item.contains(active)
			&& active !== getFlyoutLink(item) && active !== getFlyoutToggle(item);
	}

	function setFlyoutExpanded(item, isOpen) {
		var toggle = getFlyoutToggle(item);
		if (toggle) toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
		item.classList.toggle('mainnav__flyout--open', isOpen);
		if (isOpen) {
			item.classList.remove('mainnav__flyout--closed');
		} else {
			item.removeAttribute('data-pinned');
		}
	}

	// `suppressHover`: el cierre pedido por el cliente (Escape, o el botón con el
	// puntero encima) tiene que ganarle al :hover del CSS; se levanta al salir el
	// puntero o el foco del ítem.
	function closeFlyout(item, suppressHover) {
		setFlyoutExpanded(item, false);
		item.querySelectorAll(FLYOUT_ITEMS).forEach(function (child) {
			setFlyoutExpanded(child, false);
		});
		if (suppressHover) {
			item.classList.add('mainnav__flyout--closed');
		}
	}

	function openFlyout(item, pinned) {
		closeUnrelatedFlyouts(item);
		setFlyoutExpanded(item, true);
		if (pinned) item.setAttribute('data-pinned', '');
	}

	// Cierra explícitamente paneles no relacionados para evitar solapamientos
	// visuales cuando el foco o cursor activa otro ítem de navegación.
	function closeUnrelatedFlyouts(activeItem) {
		desktopFlyouts.forEach(function (item) {
			if (item === activeItem || item.contains(activeItem) || activeItem.contains(item)) return;
			closeFlyout(item, item.matches(':hover'));
		});
	}

	/* Gracia al salir el puntero de un ítem de la fila (WCAG 1.4.13 «hoverable»).
	 * Proporciona una ventana de gracia temporal (GRACIA_MS) para permitir el tránsito diagonal
	 * del cursor hacia paneles anchos o centrados sin cerrarse involuntariamente.
	 * La gracia aplica cuando el vector de movimiento del puntero se dirige hacia el panel.
	 * Si la salida es lateral o ascendente, el cierre se ejecuta de inmediato. */
	var GRACIA_MS = 300;
	var PUNTOS_DEL_TRAMO = 4;

	// ¿El tramo `desde` → salida, prolongado, entra en el panel por arriba?
	function vaHaciaElPanel(panel, desde, e) {
		if (!panel || !desde) return false;
		var dy = e.clientY - desde.y;
		var r = panel.getBoundingClientRect();
		if (dy <= 0 || e.clientY >= r.top) return false;
		var x = e.clientX + (r.top - e.clientY) * (e.clientX - desde.x) / dy;
		return x >= r.left && x <= r.right;
	}

	desktopFlyouts.forEach(function (item) {
		var link = getFlyoutLink(item);
		var toggle = getFlyoutToggle(item);
		var conGracia = item.classList.contains('mainnav__item--has-sub');
		var panel = directChildWithClass(item, 'mainnav__sub');
		var cierreDiferido = 0;
		var tramo = [];

		// Fijado, o con el foco de teclado dentro del panel: se queda.
		function seQueda() {
			return isFlyoutPinned(item) || focusInPanel(item);
		}

		item.addEventListener('pointerenter', function (e) {
			if (e.pointerType === 'touch' || window.innerWidth <= 1024) return;
			window.clearTimeout(cierreDiferido);
			if (isFlyoutOpen(item)) return;
			openFlyout(item, false);
		});

		if (conGracia) {
			item.addEventListener('pointermove', function (e) {
				tramo.push({ x: e.clientX, y: e.clientY });
				if (tramo.length > PUNTOS_DEL_TRAMO) tramo.shift();
			});
		}

		item.addEventListener('pointerleave', function (e) {
			var desde = tramo[0];
			tramo = [];
			if (e.pointerType === 'touch' || window.innerWidth <= 1024) return;
			// El cierre pedido (Escape, el botón) se levanta al salir el puntero.
			item.classList.remove('mainnav__flyout--closed');
			window.clearTimeout(cierreDiferido);
			if (conGracia && vaHaciaElPanel(panel, desde, e)) {
				cierreDiferido = window.setTimeout(function () {
					// Lo que pasó mientras tanto manda: fijado con el botón, el
					// foco dentro o el puntero de vuelta, se queda.
					if (seQueda() || item.matches(':hover')) return;
					closeFlyout(item, false);
				}, GRACIA_MS);
				return;
			}
			if (seQueda()) return;
			closeFlyout(item, false);
		});

		if (toggle) {
			toggle.addEventListener('click', function () {
				if (isFlyoutOpen(item) && isFlyoutPinned(item)) {
					closeFlyout(item, true);
				} else {
					openFlyout(item, true);
				}
			});
		}

		item.addEventListener('focusout', function () {
			if (window.innerWidth <= 1024) return;
			setTimeout(function () {
				if (item.contains(doc.activeElement)) return;
				// Con el ratón encima el panel sigue visible por :hover;
				// se libera la fijación data-pinned sin forzar un estado cerrado espurio.
				if (item.matches(':hover')) {
					item.removeAttribute('data-pinned');
					return;
				}
				closeFlyout(item, false);
				item.classList.remove('mainnav__flyout--closed');
			}, 0);
		});

		// CSS :hover no dispara en tap-only. Primer tap en el enlace abre el
		// submenú, segundo tap lo sigue. Sólo con el nav de escritorio visible.
		if (link) {
			link.addEventListener('touchend', function (e) {
				if (window.innerWidth <= 1024) return;
				if (!isFlyoutOpen(item)) {
					e.preventDefault();
					openFlyout(item, true);
				}
			}, { passive: false });
		}
	});

	function closeFlyoutsOutside(e) {
		if (e.target && e.target.closest && e.target.closest(FLYOUT_ITEMS)) return;
		desktopFlyouts.forEach(function (item) {
			if (isFlyoutOpen(item)) closeFlyout(item, false);
		});
	}
	doc.addEventListener('touchstart', closeFlyoutsOutside, { passive: true });
	// Un panel fijado no depende del puntero: un clic fuera lo cierra.
	doc.addEventListener('click', closeFlyoutsOutside);

	/* ── ESC: cierra el panel del menú o el cajón ─────────────────
	 * Respeta defaultPrevented para no interferir con comboboxes que consumen Escape primero.
	 * Cierra el panel más interno visible (abierto programáticamente o por :hover).
	 * Permite descartar contenido desplegado por hover sin mover el cursor (WCAG 1.4.13). */
	function isFlyoutShown(item) {
		return !item.classList.contains('mainnav__flyout--closed')
			&& (isFlyoutOpen(item) || item.matches(':hover'));
	}

	function flyoutToDismiss(active) {
		var item = active && active.closest ? active.closest(FLYOUT_ITEMS) : null;
		while (item) {
			if (isFlyoutShown(item)) return item;
			item = item.parentElement ? item.parentElement.closest(FLYOUT_ITEMS) : null;
		}
		for (var i = desktopFlyouts.length - 1; i >= 0; i--) {
			if (isFlyoutShown(desktopFlyouts[i])) return desktopFlyouts[i];
		}
		return null;
	}

	doc.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape' || e.defaultPrevented) return;
		var activeFlyout = flyoutToDismiss(doc.activeElement);
		if (activeFlyout) {
			e.preventDefault();
			var focusWasInPanel = focusInPanel(activeFlyout);
			closeFlyout(activeFlyout, true);
			// El foco vuelve al disparador si estaba dentro del panel que se oculta.
			// preventScroll evita desplazamiento involuntario en la cabecera sticky.
			if (focusWasInPanel) {
				var backTo = getFlyoutToggle(activeFlyout) || getFlyoutLink(activeFlyout);
				if (backTo) backTo.focus({ preventScroll: true });
			}
			return;
		}
		if (drawer && drawer.classList.contains('open')) {
			closeDrawer();
		}
	});

	/* ── Contador de filtros activos en el botón "Filtrar" ─────────
	 * Actualiza el badge "(N)" leyendo los parámetros `_<facet>=value` de la
	 * URL y refresca tras cada swap del buscador propio (evento akb:pjax).
	 *
	 * Parámetros reservados (_buscar, _paged) se excluyen del cómputo.
	 * Valores múltiples separados por coma se computan individualmente. */
	var FACET_RESERVED_PARAMS = ['_paged', '_buscar'];

	function updateFacetCount() {
		var btn = doc.querySelector('.akb-filter-toggle');
		if (!btn) return;
		var countEl = btn.querySelector('.akb-filter-toggle__count');
		if (!countEl) return;
		var n = 0;
		try {
			var params = new URLSearchParams(window.location.search);
			params.forEach(function (v, k) {
				if (!v || k.charAt(0) !== '_') return;
				if (FACET_RESERVED_PARAMS.indexOf(k) !== -1) return;
				v.split(',').forEach(function (value) {
					if (value.trim() !== '') n++;
				});
			});
		} catch (err) { return; }
		countEl.textContent = n > 0 ? String(n) : '';
		countEl.hidden = n === 0;
		// Estado visual: añade clase si existen filtros activos aplicados.
		btn.classList.toggle('akb-has-filters', n > 0);
	}
	updateFacetCount();
	// akb-search.js emite akb:pjax post-swap, con la URL ya vigente.
	doc.addEventListener('akb:pjax', updateFacetCount);

	/* ── Search input: botón limpiar (X) ──────────────────────────────
	 * Control de interfaz para vaciar el texto de búsqueda y sincronizar el estado. */
	doc.querySelectorAll('#akb-search-input, #akb-mobile-search-input').forEach(function (input) {
		var form = input.closest('form');
		var clearBtn = form && form.querySelector('.akibara-search__clear');
		if (!clearBtn) return;
		function syncClearBtn() {
			clearBtn.hidden = !input.value.length;
		}
		input.addEventListener('input', syncClearBtn);
		input.addEventListener('change', syncClearBtn);
		syncClearBtn();
		clearBtn.addEventListener('click', function () {
			input.value = '';
			input.dispatchEvent(new Event('input', { bubbles: true }));
			input.focus({ preventScroll: true });
		});
	});

	/* Atajo de teclado: ⌘K / Ctrl+K → foco en el buscador de la cabecera.
	 * Cumple WCAG 2.1.4 (A) al requerir tecla modificadora. Se anuncia con
	 * aria-keyshortcuts dinámicamente si el script está activo. Se inhabilita
	 * si el foco actual reside en un campo editable. */
	doc.addEventListener('keydown', function (e) {
		var ae = doc.activeElement;
		var inField = ae && (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA' || ae.tagName === 'SELECT' || ae.isContentEditable);
		if (inField) return;
		var cmdK = ((e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey && (e.key === 'k' || e.key === 'K'));
		if (!cmdK) return;
		var input = doc.querySelector('#akb-search-input') || doc.querySelector('#akb-mobile-search-input');
		if (!input || !input.offsetParent) return; // hidden via display:none
		e.preventDefault();
		input.focus({ preventScroll: true }); // en la cabecera fija
		input.select();
	});
	var headerSearchInput = doc.getElementById('akb-search-input');
	if (headerSearchInput) headerSearchInput.setAttribute('aria-keyshortcuts', 'Control+K Meta+K');

	/* ── Header icon feedback — breve "bump" al cambiar contadores ─ */
	function akbPrefersReducedMotion() {
		return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	function akbBumpHeaderIcons(selector) {
		if (akbPrefersReducedMotion()) return;
		doc.querySelectorAll(selector).forEach(function (icon) {
			icon.classList.remove('is-bumped');
			// Reflow deliberado para reiniciar la animación si llegan eventos seguidos.
			void icon.offsetWidth;
			icon.classList.add('is-bumped');
			window.setTimeout(function () {
				icon.classList.remove('is-bumped');
			}, 520);
		});
	}

	// Eventos WC re-emitidos como CustomEvent nativos por el shim
	// wc-event-bridge.js (`akb:<tipo>`). Escuchamos en vanilla, sin jQuery.
	['akb:added_to_cart', 'akb:removed_from_cart'].forEach(function (evt) {
		doc.body.addEventListener(evt, function () {
			window.setTimeout(function () {
				akbBumpHeaderIcons('.header-icon--cart, .bottom-nav__item--cart');
			}, 0);
		});
	});

	/* ── BlueX shipping selector — strip inline white bg ─────────
	 * El plugin `bluex-for-woocommerce` hardcodea
	 * `style="background-color: #fff; border: 1px solid #e0e0e0;"`
	 * en el div del selector de envío en checkout. Inline style gana
	 * sobre cualquier CSS sin !important → quedan labels invisibles
	 * sobre blanco en el dark theme.
	 *
	 * Stripeamos los properties hardcoded para que la regla CSS de
	 * `.woocommerce-checkout-section` tome control. WC checkout reemplaza
	 * fragments por AJAX, así que watcheamos con MutationObserver. */
	function stripBlueXInlineStyles() {
		var sels = '#bluex-shipping-selector-native, #bluex-shipping-selector-optimized';
		doc.querySelectorAll(sels).forEach(function (el) {
			el.style.removeProperty('background-color');
			el.style.removeProperty('border');
			el.style.removeProperty('border-color');
		});
	}

	stripBlueXInlineStyles();

	if (typeof MutationObserver !== 'undefined') {
		var checkout = doc.querySelector('form.woocommerce-checkout, .woocommerce-checkout');
		if (checkout) {
			new MutationObserver(stripBlueXInlineStyles).observe(checkout, {
				childList: true,
				subtree: true
			});
		}
	}

	/* ── Wishlist count badge (Akibara Wishlist module) ─────────────
	 * El módulo dispara `akb:wishlist:change` en document después de cada
	 * toggle, con `detail.count`. Reflejamos en el `data-akb-wishlist-count`
	 * del icono; la regla `[data-akb-wishlist-count="0"]::after { display:none }`
	 * oculta el badge cuando está vacío.
	 *
	 * Evento custom nativo — no requiere jQuery. */
	doc.addEventListener('akb:wishlist:change', function (e) {
		var count = e.detail && typeof e.detail.count === 'number' ? e.detail.count : null;
		if (count === null) {
			return;
		}
		var icons = doc.querySelectorAll('.header-icon--wishlist[data-akb-wishlist-count]');
		icons.forEach(function (icon) {
			icon.setAttribute('data-akb-wishlist-count', String(count));
		});
		akbBumpHeaderIcons('.header-icon--wishlist[data-akb-wishlist-count]');
	});

	/* ── Badge carrito en bottom-nav (V12) + página /carrito/ (V17) ────
	 * El header-icon--cart es WC fragment (se reemplaza con count fresco
	 * en cada op AJAX y en el refresh de cart-fragments contra páginas
	 * cacheadas). El tab Carrito no puede ser fragment sin perder su
	 * active state — copiamos el attr desde el header tras cada evento.
	 *
	 * PERO en la página /carrito/ cambiar cantidad o quitar una fila dispara
	 * `updated_wc_div` SIN refrescar el fragment del header (WC no corre
	 * cart-fragments ahí): el header quedaba stale y el copy propagaba el
	 * número viejo al bottom-nav. Cuando estamos en el cart-form leemos el
	 * conteo REAL del DOM (suma de las `qty` de `.cart_item`, = 0 si el
	 * carrito se vació) y lo escribimos primero en el header; el copy
	 * header→bottom-nav sigue igual. Fuera de la página del carrito el header
	 * sí es fresco vía fragment → devolvemos null y el flujo queda intacto.
	 * No toca el drawer nativo: se limita a `.header-icon--cart` y al tab. */
	function akbCartPageCount() {
		if (!doc.body.classList.contains('woocommerce-cart')) {
			return null;
		}
		// Cart Block mantiene el conteo en wc/store/cart. No inferir "0" desde la
		// ausencia del formulario clásico mientras React renderiza o actualiza.
		if (doc.querySelector('.wp-block-woocommerce-cart, .wc-block-cart')) {
			return null;
		}
		var rows = doc.querySelectorAll('.woocommerce-cart-form .cart_item');
		if (!rows.length) {
			return 0; // carrito vaciado → llega el template empty-cart, sin filas
		}
		var total = 0;
		rows.forEach(function (row) {
			var input = row.querySelector('input.qty');
			var n = input ? parseFloat(input.value) : NaN;
			// Sin input editable (producto sold-individually) = 1 unidad.
			total += (isFinite(n) && n >= 0) ? n : 1;
		});
		return total;
	}
	function syncBottomNavCartBadge() {
		var headerCart = doc.querySelector('.header-icon--cart');
		var pageCount = akbCartPageCount();
		if (headerCart && pageCount !== null) {
			headerCart.setAttribute('data-akb-cart-count', String(pageCount));
		}
		var navCart = doc.querySelector('.bottom-nav__item--cart');
		if (headerCart && navCart) {
			navCart.setAttribute('data-akb-cart-count', headerCart.getAttribute('data-akb-cart-count') || '0');
		}
	}
	// Mismo bus vanilla (`akb:*` del shim wc-event-bridge.js): incluye los
	// eventos de fragments para re-sincronizar tras el refresh contra caché.
	['akb:added_to_cart', 'akb:removed_from_cart', 'akb:wc_fragments_refreshed', 'akb:wc_fragments_loaded'].forEach(function (evt) {
		doc.body.addEventListener(evt, syncBottomNavCartBadge);
	});

	/* El icono del carrito de la cabecera es un fragment de WooCommerce
	 * (inc/woocommerce.php): cada refresco lo cambia por un nodo nuevo, y si
	 * tenía el foco, el foco caía al <body>: el anillo desaparecía, el lector de
	 * pantalla perdía el sitio y el siguiente Tab volvía a caer en el mismo icono
	 * en vez de avanzar. Pasa al cargar la página (el refresco de fragments
	 * llega a los ~1,5 s, más con la tienda cargada o la red lenta) y en cada
	 * cambio del carrito. Medido en el espejo en el lote X1: foco en el icono, un
	 * `wc_fragment_refresh` y `document.activeElement` era el body. Se devuelve
	 * al icono nuevo sólo si el foco se perdió así: si el cliente ya está en otro
	 * sitio (el cajón, un campo), no se toca. */
	var ultimoFoco = null;
	doc.addEventListener('focusin', function (event) {
		ultimoFoco = event.target;
	});
	// Si el cliente lo soltó él (un clic en la página), el foco en el <body> es
	// suyo y un refresco posterior no se lo devuelve al icono. Chromium también
	// avisa `focusout` al quitar del documento el nodo con foco, y todavía
	// conectado: se mira después, cuando el reemplazo ya terminó (y ya se avisó
	// `wc_fragments_refreshed`). Si sigue en el documento, lo soltó el cliente.
	doc.addEventListener('focusout', function (event) {
		var soltado = event.target;
		if (soltado !== ultimoFoco || event.relatedTarget) {
			return;
		}
		window.setTimeout(function () {
			if (ultimoFoco === soltado && soltado.isConnected && doc.activeElement === doc.body) {
				ultimoFoco = null;
			}
		}, 0);
	});
	function devolverFocoAlCarrito() {
		var activo = doc.activeElement;
		if (!ultimoFoco || (activo && activo !== doc.body) || doc.contains(ultimoFoco)
			|| !ultimoFoco.matches || !ultimoFoco.matches('.header-icon--cart')) {
			return;
		}
		var nuevo = doc.querySelector('.header-icon--cart');
		if (nuevo) {
			nuevo.focus({ preventScroll: true });
		}
	}
	['akb:wc_fragments_refreshed', 'akb:wc_fragments_loaded'].forEach(function (evt) {
		doc.body.addEventListener(evt, devolverFocoAlCarrito);
	});

	/* ── Steppers −/+ de cantidad (V15) ──────────────────────────────
	 * Botones inyectados por inc/woocommerce.php alrededor del input.qty.
	 * Delegado en document: no captura nodos, así que sobrevive a cualquier
	 * re-render. Dispara `change` para que el flujo de WooCommerce sea idéntico
	 * al de la edición manual del campo. */
	function syncQuantityStepper(wrap) {
		var input = wrap && wrap.querySelector('input.qty');
		if (!input) return;

		var min = input.min !== '' ? parseFloat(input.min) : 1;
		var max = input.max !== '' ? parseFloat(input.max) : Infinity;
		var value = parseFloat(input.value);
		if (!Number.isFinite(value)) value = min;

		var minus = wrap.querySelector('.akb-qty-btn--minus');
		var plus = wrap.querySelector('.akb-qty-btn--plus');

		[
			{ button: minus, disabled: input.disabled || input.readOnly || value <= min },
			{ button: plus, disabled: input.disabled || input.readOnly || value >= max }
		].forEach(function (state) {
			if (!state.button) return;
			state.button.disabled = state.disabled;
			state.button.setAttribute('aria-disabled', state.disabled ? 'true' : 'false');
		});
	}

	/**
	 * Anuncio accesible cuando la cantidad alcanza el stock máximo disponible.
	 * Se notifica al usuario mediante una región aria-live ('status').
	 */
	function announceQuantityLimit(wrap, value, max) {
		if (!wrap || !Number.isFinite(max) || value < max) return;

		var hint = wrap.parentElement && wrap.parentElement.querySelector('.akb-qty-hint');
		if (!hint) {
			hint = doc.createElement('p');
			hint.className = 'akb-qty-hint';
			hint.setAttribute('role', 'status');
			hint.setAttribute('aria-live', 'polite');
			// Se inserta después del botón de compra para evitar Cumulative Layout Shift (CLS).
			var parent = wrap.parentElement || wrap;
			var atc = parent.querySelector('.single_add_to_cart_button');
			if (atc && atc.parentElement === parent) {
				parent.insertBefore(hint, atc.nextSibling);
			} else {
				parent.appendChild(hint);
			}
		}
		hint.textContent = max === 1
			? 'Solo queda una unidad de este tomo.'
			: 'Es el máximo disponible: ' + max + ' unidades.';
	}

	function syncAllQuantitySteppers(root) {
		(root || doc).querySelectorAll('.quantity').forEach(syncQuantityStepper);
	}

	doc.addEventListener('click', function (e) {
		var btn = e.target.closest('.akb-qty-btn');
		if (!btn) return;
		if (btn.disabled || btn.getAttribute('aria-disabled') === 'true') return;
		var wrap = btn.closest('.quantity');
		var input = wrap && wrap.querySelector('input.qty');
		if (!input || input.disabled || input.readOnly) return;

		var step = parseFloat(input.step) || 1;
		var min = input.min !== '' ? parseFloat(input.min) : 1;
		var max = input.max !== '' ? parseFloat(input.max) : Infinity;
		var val = parseFloat(input.value) || min;

		val += btn.classList.contains('akb-qty-btn--plus') ? step : -step;
		val = Math.min(max, Math.max(min, val));
		if (String(val) === input.value) {
			syncQuantityStepper(wrap);
			return;
		}

		input.value = String(val);
		input.dispatchEvent(new Event('input', { bubbles: true }));
		input.dispatchEvent(new Event('change', { bubbles: true }));
		syncQuantityStepper(wrap);
		announceQuantityLimit(wrap, val, max);
	});

	doc.addEventListener('input', function (e) {
		if (e.target && e.target.matches('input.qty')) {
			syncQuantityStepper(e.target.closest('.quantity'));
		}
	});

	// Aquí se re-sincronizaban los steppers tras `updated_wc_div` /
	// `updated_cart_totals`: los re-renders AJAX del carrito CLÁSICO. El carrito
	// es de bloques, esos eventos no existen en este sitio y el shim dejó de
	// re-emitirlos. Los steppers de la ficha se sincronizan con el `input`
	// delegado de arriba y con la pasada inicial.

	syncAllQuantitySteppers(doc);

	/* ── Mi cuenta: posicionamiento inicial de pestañas de navegación ─
	 * - Dashboard: la navegación se sitúa al inicio (scrollLeft = 0) para visibilidad de accesos directos.
	 * - Subpáginas: si la pestaña activa sobrepasa el viewport, se realiza el desplazamiento mínimo necesario.
	 */
	(function () {
		var nav = doc.querySelector('.woocommerce-MyAccount-navigation');
		if (!nav) return;
		var ul = nav.querySelector('ul');
		var active = nav.querySelector('li.is-active');
		if (!ul || !active || ul.scrollWidth <= ul.clientWidth + 4) return;
		if (doc.body.classList.contains('woocommerce-dashboard')) {
			ul.scrollLeft = 0;
			return;
		}
		var ulRect = ul.getBoundingClientRect();
		var aRect = active.getBoundingClientRect();
		var contentX = (aRect.left - ulRect.left) + ul.scrollLeft;
		ul.scrollLeft = (contentX + aRect.width > ul.clientWidth)
			? contentX + aRect.width - ul.clientWidth + 12
			: 0;
	})();

	/* ── Gestión de errores de carga en portadas (.akb-cover--error) ──
	 * Delegación en CAPTURA: los eventos error/load de <img> no burbujean pero
	 * sí capturan, cubriendo imágenes del DOM inicial y de cargas dinámicas por PJAX.
	 * Aplica a tarjetas de catálogo (.akb-product-cover-wrap), jaulas de Mi Cuenta y packs
	 * (.akb-cover-cage) y galería de ficha (.woocommerce-product-gallery__image). */
	(function () {
		var COVER_WRAPS = '.akb-product-cover-wrap, .akb-cover-cage, .woocommerce-product-gallery__image';
		function coverOf(el) {
			return el && el.closest ? el.closest(COVER_WRAPS) : null;
		}
		doc.addEventListener('error', function (event) {
			var cover = coverOf(event.target);
			if (cover && event.target.tagName === 'IMG') {
				cover.classList.add('akb-cover--error');
			}
		}, true);
		doc.addEventListener('load', function (event) {
			var cover = coverOf(event.target);
			if (cover && event.target.tagName === 'IMG') {
				cover.classList.remove('akb-cover--error');
			}
		}, true);
		// Las que fallaron ANTES de instalar los listeners (script diferido).
		var imgs = doc.querySelectorAll(COVER_WRAPS.split(', ').map(function (s) { return s + ' img'; }).join(', '));
		for (var i = 0; i < imgs.length; i += 1) {
			if (imgs[i].complete && imgs[i].naturalWidth === 0) {
				coverOf(imgs[i]).classList.add('akb-cover--error');
			}
		}
	})();

	/* ── Vitrinas deslizables de la portada: soltar sus portadas al tocar la fila ──
	 * Optimización de rendimiento LCP y renderizado diferido:
	 * Al primer contacto con una fila (táctil, puntero, scroll o foco de teclado),
	 * se añade `akb-fila--activa` para renderizar el contenido con content-visibility.
	 *
	 * Sincronización de foco por teclado: cuando un elemento enfocado con
	 * `:focus-visible` desborda la fila horizontal con scroll-snap, se desplaza
	 * la tarjeta contenedora al inicio del viewport visible para evitar recortes. */
	(function () {
		var filas = doc.querySelectorAll('.akb-section .woocommerce ul.products, .akb-section .akb-home-series__grid');
		var TIPOS = ['touchstart', 'pointerdown', 'focusin', 'scroll'];
		var OPCIONES = { passive: true };

		function seguirAlFoco(fila, el) {
			if (!el || el === fila || typeof el.scrollIntoView !== 'function' || fila.scrollWidth <= fila.clientWidth) {
				return;
			}
			try {
				if (!el.matches(':focus-visible')) {
					return;
				}
			} catch (err) {
				return;
			}
			// Se evalúa la tarjeta contenedora completa para garantizar que ni el anillo
			// de foco ni los controles perimetrales queden recortados por el scroll-snap.
			var tarjeta = el.closest('li.product, .akb-home-series__item') || el;
			var r = tarjeta.getBoundingClientRect();
			var f = fila.getBoundingClientRect();
			if (r.left < f.left - 0.5 || r.right > f.right + 0.5) {
				tarjeta.scrollIntoView({ block: 'nearest', inline: 'start' });
			}
		}

		Array.prototype.forEach.call(filas, function (fila) {
			function soltar() {
				fila.classList.add('akb-fila--activa');
				TIPOS.forEach(function (tipo) {
					fila.removeEventListener(tipo, soltar, OPCIONES);
				});
			}
			TIPOS.forEach(function (tipo) {
				fila.addEventListener(tipo, soltar, OPCIONES);
			});
			fila.addEventListener('focusin', function (event) {
				seguirAlFoco(fila, event.target);
			}, OPCIONES);
		});
	})();

	/* ── El botón de WhatsApp cede ante un botón de compra o un control de la tarjeta ──
	 * Detecta si el widget flotante de WhatsApp oculta controles críticos de
	 * conversión o interacción (botones de añadir al carrito, avisos de stock,
	 * favoritos, vista rápida, selectores de packs).
	 *
	 * Cuando se detecta solapamiento en reposo (vía `elementsFromPoint` y cálculo
	 * de colisión rectangular para elementos con `pointer-events: none` en vuelo),
	 * se añade `akb-fab-cede` a <body> para desplazar o atenuar el botón flotante.
	 *
	 * Se evalúa con debounce al hacer scroll, resize, cambios de DOM y eventos de carrito. */
	(function () {
		var fab = doc.querySelector('.joinchat');
		if (!fab || typeof doc.elementsFromPoint !== 'function') {
			return;
		}
		var COMPRA = 'li.product .button, li.product .akb-tarjeta-motivo, .akb-wl-toggle, .akb-qv-trigger, .single_add_to_cart_button, [data-akb-sb-add], [data-akb-sb-landing-start], [data-akb-sb-landing-add], [data-akb-sb-landing-clear], [data-akb-sb-landing-close], .akb-sb-card-select:not(.is-disabled)';
		var EN_VUELO = 'li.product .button.loading, .single_add_to_cart_button.is-adding, [data-akb-sb-add].is-loading, .akb-wl-toggle.is-loading, [data-akb-sb-landing-add].is-loading';
		var CLASE = 'akb-fab-cede';
		var espera = 0;

		function cruza(a, b) {
			return b.width > 0 && b.left < a.right && b.right > a.left && b.top < a.bottom && b.bottom > a.top;
		}

		function tapaUnaCompra() {
			var boton = fab.querySelector('.joinchat__button') || fab;
			var r = boton.getBoundingClientRect();
			if (!r.width || !r.height) {
				return false;
			}
			// Borde izquierdo y centro, arriba, al medio y abajo: un botón de
			// compra de 44 px no pasa por debajo sin tocar alguno.
			var xs = [r.left + 4, r.left + r.width / 2];
			var ys = [r.top + 4, r.top + r.height / 2, r.bottom - 4];
			for (var i = 0; i < xs.length; i += 1) {
				for (var j = 0; j < ys.length; j += 1) {
					var pila = doc.elementsFromPoint(xs[i], ys[j]);
					for (var k = 0; k < pila.length; k += 1) {
						if (!pila[k].closest('.joinchat') && pila[k].closest(COMPRA)) {
							return true;
						}
					}
				}
			}
			var enVuelo = doc.querySelectorAll(EN_VUELO);
			for (var n = 0; n < enVuelo.length; n += 1) {
				if (cruza(r, enVuelo[n].getBoundingClientRect())) {
					return true;
				}
			}
			return false;
		}

		function evaluar() {
			espera = 0;
			body.classList.toggle(CLASE, tapaUnaCompra());
		}

		function programar() {
			if (espera) {
				window.clearTimeout(espera);
			}
			espera = window.setTimeout(evaluar, 150);
		}

		window.addEventListener('scroll', programar, { passive: true });
		window.addEventListener('resize', programar, { passive: true });
		// Joinchat muestra su botón a los 8 s (`joinchat--show`) y lo cambia de
		// tamaño por breakpoint: se vuelve a mirar cuando cambia su clase.
		if ('MutationObserver' in window) {
			new MutationObserver(programar).observe(fab, { attributes: true, attributeFilter: ['class', 'hidden'] });
		}
		// Sin scroll: el alta termina o el carrito cambia (wc-event-bridge.js),
		// el cajón se cierra (native-cart.js de akibara-business) y el aviso de
		// una tarjeta aparece o se va (atc-guard.js).
		['akb:added_to_cart', 'akb:removed_from_cart', 'akb:wc_fragments_refreshed'].forEach(function (tipo) {
			body.addEventListener(tipo, programar);
		});
		doc.addEventListener('akb:cart:close', programar);
		doc.addEventListener('akb:tarjeta-motivo', programar);
		// Y lo que cambia el alto de la página sin scroll: abrir o cerrar la
		// bandeja de la landing de serie, desplegar «Cómo funciona».
		if ('ResizeObserver' in window) {
			new window.ResizeObserver(programar).observe(body);
		}
		programar();
	})();
})();
