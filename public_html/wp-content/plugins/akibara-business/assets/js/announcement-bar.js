/*!
 * Announcement Bar — navegación manual ligera para múltiples promociones.
 */
(function () {
	'use strict';

	const bar = document.getElementById('akb-ann-bar');
	if (!bar) { return; }

	let messages = Array.prototype.slice.call(bar.querySelectorAll('.akb-ann-msg'));
	const nextButton = bar.querySelector('[data-akb-ann-next]');
	const count = bar.querySelector('[data-akb-ann-count]');
	const status = bar.querySelector('[data-akb-ann-status]');
	let current = 0;
	let touchStartX = null;
	let touchStartY = null;
	let stopRotation = function () {};
	let syncNavigation = function () {};

	function pruneExpiredPromos() {
		const now = Date.now();
		let removedCurrent = false;
		const remaining = [];

		messages.forEach(function (message, index) {
			const expiresRaw = message.getAttribute('data-akb-promo-expires-epoch');
			const expiresAt = expiresRaw ? parseInt(expiresRaw, 10) : 0;
			if (expiresAt > 0 && now > expiresAt) {
				message.hidden = true;
				message.classList.remove('akb-on');
				message.classList.add('akb-out');
				setMessageActive(message, false);
				if (index === current) {
					removedCurrent = true;
				}
				return;
			}
			remaining.push(message);
		});

		if (remaining.length === messages.length) {
			return false;
		}

		messages = remaining;

		if (0 === messages.length) {
			stopRotation();
			bar.classList.add('akb-hidden');
			bar.hidden = true;
			return true;
		}

		if (1 === messages.length) {
			stopRotation();
			bar.classList.remove('akb-ann-bar--multi');
			if (nextButton) {
				nextButton.disabled = true;
				nextButton.hidden = true;
			}
			current = 0;
			messages[0].classList.remove('akb-out');
			messages[0].classList.add('akb-on');
			setMessageActive(messages[0], true);
			return true;
		}

		if (current >= messages.length) {
			current = 0;
		}
		if (removedCurrent) {
			// eslint-disable-next-line security/detect-object-injection
			messages[current].classList.remove('akb-out');
			// eslint-disable-next-line security/detect-object-injection
			messages[current].classList.add('akb-on');
			// eslint-disable-next-line security/detect-object-injection
			setMessageActive(messages[current], true);
		}
		syncNavigation(false);
		return true;
	}

	function setMessageActive(message, active) {
		const links = message.querySelectorAll('a[href]');
		if (active) {
			message.removeAttribute('aria-hidden');
			message.removeAttribute('inert');
			Array.prototype.forEach.call(links, function (link) {
				if (!link.hasAttribute('data-akb-ann-tabindex')) { return; }
				const previous = link.getAttribute('data-akb-ann-tabindex');
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

	syncNavigation = function (announce) {
		const position = current + 1;
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
			// eslint-disable-next-line security/detect-object-injection
			status.textContent = 'Anuncio ' + position + ' de ' + messages.length + ': ' + messageText(messages[current]);
		}
	};

	function goTo(index, announce) {
		if (!messages.length) { return; }
		// eslint-disable-next-line security/detect-object-injection
		const outgoing = messages[current];
		if (outgoing) {
			outgoing.classList.remove('akb-on');
			outgoing.classList.add('akb-out');
			setMessageActive(outgoing, false);
		}

		current = (index + messages.length) % messages.length;
		// eslint-disable-next-line security/detect-object-injection
		messages[current].classList.remove('akb-out');
		// eslint-disable-next-line security/detect-object-injection
		messages[current].classList.add('akb-on');
		// eslint-disable-next-line security/detect-object-injection
		setMessageActive(messages[current], true);
		syncNavigation(announce);
	}

	messages.forEach(function (message, index) {
		setMessageActive(message, 0 === index);
	});
	pruneExpiredPromos();

	function initCountdowns() {
		const elements = Array.prototype.slice.call(bar.querySelectorAll('[data-akb-countdown-epoch]'));
		function hasPendingTimers() {
			const now = Date.now();
			const anyClock = elements.some(function (el) {
				const target = parseInt(el.getAttribute('data-akb-countdown-epoch'), 10);
				return !isNaN(target) && target > now;
			});
			const anyPromo = messages.some(function (message) {
				const exp = parseInt(message.getAttribute('data-akb-promo-expires-epoch') || '', 10);
				return !isNaN(exp) && exp > 0;
			});
			return anyClock || anyPromo;
		}
		if (!elements.length && !hasPendingTimers()) { return; }

		function pad(num) {
			return num < 10 ? '0' + num : '' + num;
		}

		let countdownTimer = null;
		function update() {
			pruneExpiredPromos();
			const now = Date.now();
			elements.forEach(function (el) {
				const target = parseInt(el.getAttribute('data-akb-countdown-epoch'), 10);
				if (!target || isNaN(target)) { return; }

				const digitsEl = el.querySelector('.akb-ann-countdown__digits');
				if (!digitsEl) { return; }

				const diffSec = Math.floor((target - now) / 1000);

				if (diffSec <= 0) {
					digitsEl.textContent = '00h 00m 00s';
					el.hidden = true;
					return;
				}
				el.hidden = false;

				const days = Math.floor(diffSec / 86400);
				const hours = Math.floor((diffSec % 86400) / 3600);
				const minutes = Math.floor((diffSec % 3600) / 60);
				const seconds = diffSec % 60;

				if (days > 0) {
					digitsEl.textContent = days + 'd ' + pad(hours) + 'h ' + pad(minutes) + 'm ' + pad(seconds) + 's';
				} else {
					digitsEl.textContent = pad(hours) + 'h ' + pad(minutes) + 'm ' + pad(seconds) + 's';
				}
			});
			if (null !== countdownTimer && !hasPendingTimers()) {
				window.clearInterval(countdownTimer);
				countdownTimer = null;
			}
		}

		update();
		if (hasPendingTimers()) {
			countdownTimer = window.setInterval(update, 1000);
		}
	}
	initCountdowns();

	if (messages.length < 2 || !nextButton) { return; }
	nextButton.disabled = false;

	// Rotación con ritmo de panel, seis segundos por defecto y data-rotate=0 para apagar. Se
	// detiene al tomar el cliente control mediante flecha, teclado o swipe; se pausa con hover,
	// foco o pestaña oculta. Con reduced-motion sólo permanece navegación manual.
	const ROTATE_MS = Math.max(3000, Math.min(30000, parseInt(bar.getAttribute('data-rotate-ms'), 10) || 6000));
	const rotateAllowed = '0' !== bar.getAttribute('data-rotate');
	let timer = null;
	let userTookOver = false;
	const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	stopRotation = function () {
		if (null !== timer) {
			window.clearInterval(timer);
			timer = null;
		}
	};

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
	let swipedAt = 0;
	const SWIPE_CLICK_GUARD_MS = 600;

	bar.addEventListener('touchstart', function (event) {
		if (event.target.closest('button') || !event.touches.length) { return; }
		touchStartX = event.touches[0].clientX;
		touchStartY = event.touches[0].clientY;
	}, { passive: true });

	bar.addEventListener('touchend', function (event) {
		if (null === touchStartX || null === touchStartY || !event.changedTouches.length) { return; }

		const deltaX = event.changedTouches[0].clientX - touchStartX;
		const deltaY = event.changedTouches[0].clientY - touchStartY;
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
