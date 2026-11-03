/*!
 * Announcement Bar — navegación manual ligera para múltiples promociones.
 */
(function () {
	'use strict';

	var bar = document.getElementById('akb-ann-bar');
	if (!bar) { return; }

	var messages = Array.prototype.slice.call(bar.querySelectorAll('.akb-ann-msg'));
	var nextButton = bar.querySelector('[data-akb-ann-next]');
	var count = bar.querySelector('[data-akb-ann-count]');
	var status = bar.querySelector('[data-akb-ann-status]');
	var current = 0;
	var touchStartX = null;
	var touchStartY = null;

	function setMessageActive(message, active) {
		var links = message.querySelectorAll('a[href]');
		if (active) {
			message.removeAttribute('aria-hidden');
			message.removeAttribute('inert');
			Array.prototype.forEach.call(links, function (link) {
				if (!link.hasAttribute('data-akb-ann-tabindex')) { return; }
				var previous = link.getAttribute('data-akb-ann-tabindex');
				if ('' === previous) {
					link.removeAttribute('tabindex');
				} else {
					link.setAttribute('tabindex', previous);
				}
				link.removeAttribute('data-akb-ann-tabindex');
			});
			return;
		}

		message.setAttribute('aria-hidden', 'true');
		message.setAttribute('inert', '');
		Array.prototype.forEach.call(links, function (link) {
			if (!link.hasAttribute('data-akb-ann-tabindex')) {
				link.setAttribute('data-akb-ann-tabindex', link.getAttribute('tabindex') || '');
			}
			link.setAttribute('tabindex', '-1');
		});
	}

	function messageText(message) {
		return (message.textContent || '').replace(/\s+/g, ' ').trim();
	}

	function syncNavigation(announce) {
		var position = current + 1;
		if (count) {
			count.textContent = position + '/' + messages.length;
		}
		if (nextButton) {
			nextButton.setAttribute(
				'aria-label',
				'Siguiente anuncio. ' + position + '/' + messages.length
			);
		}
		if (announce && status) {
			status.textContent = 'Anuncio ' + position + ' de ' + messages.length + ': ' + messageText(messages[current]);
		}
	}

	function goTo(index, announce) {
		var outgoing = messages[current];
		outgoing.classList.remove('akb-on');
		outgoing.classList.add('akb-out');
		setMessageActive(outgoing, false);

		current = (index + messages.length) % messages.length;
		messages[current].classList.remove('akb-out');
		messages[current].classList.add('akb-on');
		setMessageActive(messages[current], true);
		syncNavigation(announce);
	}

	messages.forEach(function (message, index) {
		setMessageActive(message, 0 === index);
	});

	if (messages.length < 2 || !nextButton) { return; }
	nextButton.disabled = false;

	// Rotación con ritmo de panel, seis segundos por defecto y data-rotate=0 para apagar. Se
	// detiene al tomar el cliente control mediante flecha, teclado o swipe; se pausa con hover,
	// foco o pestaña oculta. Con reduced-motion sólo permanece navegación manual.
	var ROTATE_MS = Math.max(3000, Math.min(30000, parseInt(bar.getAttribute('data-rotate-ms'), 10) || 6000));
	var rotateAllowed = '0' !== bar.getAttribute('data-rotate');
	var timer = null;
	var userTookOver = false;
	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function stopRotation() {
		if (null !== timer) {
			window.clearInterval(timer);
			timer = null;
		}
	}

	function startRotation() {
		if (!rotateAllowed || userTookOver || reduceMotion || null !== timer || document.hidden) { return; }
		timer = window.setInterval(function () {
			// `announce=false`: la región viva es para los cambios que el cliente
			// pide. Anunciar cada 6 s convertiría el lector de pantalla en un
			// metrónomo.
			goTo(current + 1, false);
		}, ROTATE_MS);
	}

	function surrenderToUser() {
		userTookOver = true;
		stopRotation();
	}

	bar.addEventListener('pointerenter', stopRotation);
	bar.addEventListener('pointerleave', startRotation);
	bar.addEventListener('focusin', stopRotation);
	bar.addEventListener('focusout', function (event) {
		if (bar.contains(event.relatedTarget)) { return; }
		startRotation();
	});
	document.addEventListener('visibilitychange', function () {
		if (document.hidden) { stopRotation(); } else { startRotation(); }
	});

	nextButton.addEventListener('click', function () {
		surrenderToUser();
		goTo(current + 1, true);
	});

	bar.addEventListener('keydown', function (event) {
		if ('ArrowRight' === event.key) {
			event.preventDefault();
			surrenderToUser();
			goTo(current + 1, true);
		} else if ('ArrowLeft' === event.key) {
			event.preventDefault();
			surrenderToUser();
			goTo(current - 1, true);
		}
	});

	// El deslizamiento vale también sobre un enlace. Un mensaje «Texto | /url/»
	// hace de la cinta entera un <a> (262 de 262 px en un celular de 390): si el
	// gesto que empieza en un enlace se ignorara, no quedaría dónde deslizar
	// (revisión B5-R4). Sólo la flecha queda fuera: es un botón con su propio
	// toque. Si el gesto sí fue un deslizamiento, el clic que algún navegador
	// pueda soltar al levantar el dedo no navega.
	var swipedAt = 0;
	var SWIPE_CLICK_GUARD_MS = 600;

	bar.addEventListener('touchstart', function (event) {
		if (event.target.closest('button') || !event.touches.length) { return; }
		touchStartX = event.touches[0].clientX;
		touchStartY = event.touches[0].clientY;
	}, { passive: true });

	bar.addEventListener('touchend', function (event) {
		if (null === touchStartX || null === touchStartY || !event.changedTouches.length) { return; }

		var deltaX = event.changedTouches[0].clientX - touchStartX;
		var deltaY = event.changedTouches[0].clientY - touchStartY;
		touchStartX = null;
		touchStartY = null;

		if (Math.abs(deltaX) < 40 || Math.abs(deltaX) <= Math.abs(deltaY) * 1.2) { return; }
		swipedAt = Date.now();
		surrenderToUser();
		goTo(deltaX < 0 ? current + 1 : current - 1, true);
	}, { passive: true });

	// Por marca de tiempo, no por un escuchador de un solo uso: si el navegador
	// no suelta ningún clic tras el gesto, uno de un solo uso se quedaría
	// esperando y se comería el siguiente toque de verdad.
	bar.addEventListener('click', function (event) {
		if (swipedAt && Date.now() - swipedAt < SWIPE_CLICK_GUARD_MS && event.target.closest('a')) {
			swipedAt = 0;
			event.preventDefault();
		}
	}, true);

	syncNavigation(false);
	startRotation();
}());
