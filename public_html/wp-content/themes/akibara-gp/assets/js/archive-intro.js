/**
 * Clamping progresivo de introducciones editoriales en archivos de producto.
 *
 * El texto completo permanece en el DOM. Cada control se vincula mediante
 * aria-controls, por lo que el script admite más de un intro sin depender de
 * la posición del markup. En desktop y sin JS siempre se ve completo.
 */
(function () {
	'use strict';

	// Las categorías conservan el HTML editorial de WooCommerce/SEO. Se mejora
	// ese mismo contenido; sin JavaScript permanece completo y legible.
	document.querySelectorAll('.tax-product_cat .woocommerce-products-header > .term-description').forEach(function (description, index) {
		if (description.hasAttribute('data-akb-archive-intro')) {
			return;
		}
		description.id = description.id || 'akb-category-intro-' + index;
		description.classList.add('akb-archive-intro__content');
		description.setAttribute('data-akb-archive-intro', '');
		var toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'akb-archive-intro__toggle';
		toggle.setAttribute('data-akb-archive-intro-toggle', '');
		toggle.setAttribute('aria-controls', description.id);
		toggle.setAttribute('aria-expanded', 'false');
		toggle.setAttribute('data-label-expand', 'Leer más');
		toggle.setAttribute('data-label-collapse', 'Leer menos');
		toggle.hidden = true;
		description.insertAdjacentElement('afterend', toggle);
	});

	var toggles = document.querySelectorAll('[data-akb-archive-intro-toggle]');

	if (!toggles.length || !window.matchMedia) {
		return;
	}

	var mobile = window.matchMedia('(max-width: 600px)');
	var resizeFrame = 0;

	function getDescription(toggle) {
		var id = toggle.getAttribute('aria-controls');
		return id ? document.getElementById(id) : null;
	}

	function updateControlLabel(toggle, expanded) {
		var label = expanded
			? toggle.getAttribute('data-label-collapse')
			: toggle.getAttribute('data-label-expand');

		toggle.textContent = label || (expanded ? 'Leer menos' : 'Leer más');
	}

	function syncToggle(toggle) {
		var description = getDescription(toggle);
		if (!description) {
			toggle.hidden = true;
			return;
		}

		var expanded = toggle.getAttribute('aria-expanded') === 'true';

		if (!mobile.matches) {
			description.classList.remove('is-collapsed');
			toggle.hidden = true;
			return;
		}

		if (expanded) {
			description.classList.remove('is-collapsed');
			toggle.hidden = false;
			return;
		}

		description.classList.add('is-collapsed');
		var overflows = description.scrollHeight > description.clientHeight + 1;
		description.classList.toggle('is-collapsed', overflows);
		toggle.hidden = !overflows;
	}

	function sync() {
		toggles.forEach(syncToggle);
	}

	toggles.forEach(function (toggle) {
		var description = getDescription(toggle);
		if (!description) {
			return;
		}

		toggle.addEventListener('click', function () {
			var expanded = toggle.getAttribute('aria-expanded') !== 'true';
			toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
			description.classList.toggle('is-collapsed', !expanded);
			updateControlLabel(toggle, expanded);
		});

		updateControlLabel(toggle, false);
	});

	function scheduleSync() {
		if (resizeFrame) {
			window.cancelAnimationFrame(resizeFrame);
		}
		resizeFrame = window.requestAnimationFrame(function () {
			resizeFrame = 0;
			sync();
		});
	}

	if (typeof mobile.addEventListener === 'function') {
		mobile.addEventListener('change', sync);
	} else {
		mobile.addListener(sync);
	}

	window.addEventListener('resize', scheduleSync, { passive: true });
	sync();

	if (document.fonts && document.fonts.ready) {
		document.fonts.ready.then(scheduleSync);
	}
}());
