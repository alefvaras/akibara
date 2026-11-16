/*!
 * Akibara GP — sticky-atc.js (solo ficha)
 *
 * Barra fija precio + CTA (#akb-sticky-atc). En ≤1024 px va apilada sobre el
 * bottom-nav; en escritorio, pegada al borde inferior (no hay bottom-nav). El
 * botón es un PROXY que clickea el .single_add_to_cart_button real: cantidad,
 * estados y validaciones de WooCommerce quedan intactos.
 *
 * La barra se muestra sólo si se cumplen las DOS condiciones:
 *
 *  1. El form.cart real no asoma por encima de la franja fija (bottom-nav +
 *     barra, tokens de tokens.css). Si el cliente ve el botón de verdad, la
 *     barra sobra.
 *  2. El título (H1) ha salido de la zona de solape inferior o se encuentra por
 *     encima de la barra fija, evitando oclusiones sobre el encabezado del tomo.
 *
 * Todo con IntersectionObserver (sin oyentes de scroll).
 */
(function () {
	'use strict';

	const bar = document.getElementById('akb-sticky-atc');
	const form = document.querySelector('form.cart');
	if (!bar || !form || !('IntersectionObserver' in window)) return;

	const realBtn = form.querySelector('.single_add_to_cart_button');
	const proxyBtn = document.getElementById('akb-sticky-atc-btn');
	if (!realBtn || !proxyBtn) return;

	const titulo = document.querySelector('.product_title');

	proxyBtn.addEventListener('click', function () {
		// Con el POST normal de camino (antes de que enganche pdp-ajax-atc.js) el
		// formulario queda `inert` (toque-temprano.js), pero un click() por código
		// lo salta: cada toque en la barra mandaba otra alta (dos toques, dos POST,
		// medido en el espejo en Chromium y WebKit a 390 táctil).
		if (form.hasAttribute('inert')) return;
		realBtn.click();
	});

	let formFuera = false;
	// Sin H1 no hay título que tapar: la condición 2 se da por cumplida.
	let tituloLibre = !titulo;

	function syncVisibility() {
		bar.classList.toggle('is-visible', formFuera && tituloLibre);
	}

	// Alturas de la franja leídas de los tokens de tokens.css (que salen de
	// theme.json), no escritas a mano. Los tokens computan en px («66px»); si no
	// resolvieran, los mismos respaldos que usan las hojas.
	const px = function (nombre, respaldo) {
		const valor = getComputedStyle(document.documentElement).getPropertyValue(nombre).trim();
		const num = parseFloat(valor);
		return valor.endsWith('px') && Number.isFinite(num) && num >= 0 ? num : respaldo;
	};

	// Mismo corte que woocommerce.css y footer.css: el bottom-nav existe hasta
	// 1024 px; por encima la barra se apoya en el borde de la ventana.
	const escritorio = window.matchMedia ? window.matchMedia('(min-width: 1025px)') : null;
	let observadores = [];

	// Altura computada de la barra en runtime.
	// Se monitoriza vía ResizeObserver tras el renderizado para evitar layout forzado en la carga.
	let altoMedido = 0;

	// Cuánto se estira hacia arriba la raíz del observador del título (ver
	// observar()): más que cualquier ficha, para que «por encima» no tenga fin.
	const SIN_TECHO = 100000;

	function ultima(entries) {
		return entries[entries.length - 1];
	}

	function observar() {
		observadores.forEach(function (o) { o.disconnect(); });
		observadores = [];

		const altoNav = escritorio && escritorio.matches ? 0 : px('--bottom-nav-height', 66);
		const altoBarra = altoMedido || px('--buy-bar-height', 68);
		// rootMargin bottom = la franja fija entera: el form cuenta como
		// «visible» sólo cuando asoma POR ENCIMA de ella. El margen es constante,
		// así que no hay parpadeo en el borde al ocultar la barra.
		const margenFranja = '0px 0px -' + (altoNav + altoBarra) + 'px 0px';

		const ioForm = new IntersectionObserver(function (entries) {
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
			const ioTitulo = new IntersectionObserver(function (entries) {
				const e = ultima(entries);
				const techo = e.rootBounds ? e.rootBounds.bottom : window.innerHeight - altoNav - altoBarra;
				tituloLibre = e.isIntersecting && e.boundingClientRect.bottom <= techo + 1;
				syncVisibility();
			}, { rootMargin: SIN_TECHO + 'px 0px -' + (altoNav + altoBarra) + 'px 0px', threshold: [0, 1] });
			ioTitulo.observe(titulo);
			observadores.push(ioTitulo);
		}
	}

	observar();

	if ('ResizeObserver' in window) {
		new ResizeObserver(function (entries) {
			const e = ultima(entries);
			const caja = e.borderBoxSize && e.borderBoxSize[0];
			const alto = Math.round(caja ? caja.blockSize : bar.getBoundingClientRect().height);
			// Oculta en móvil sigue maquetada (visibility), así que mide siempre;
			// 0 sólo si la regla de la barra no aplica (display: none).
			if (alto > 0 && alto !== altoMedido) {
				altoMedido = alto;
				observar();
			}
		}).observe(bar);
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
