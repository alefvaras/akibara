'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { createDOM } = require('../../../../../tools/testing/dom-harness.js');

const SCRIPT_PATH = 'public_html/wp-content/themes/akibara-gp/assets/js/main.js';

function createMatchMedia(reduced) {
	return function (query) {
		return {
			matches: query === '(prefers-reduced-motion: reduce)' ? reduced : false,
			media: query,
			addEventListener() {},
			removeEventListener() {},
			addListener() {},
			removeListener() {}
		};
	};
}

const LOGO_HTML = `
	<header class="site-header" id="site-header">
		<a href="https://akibara.cl/" class="logo akb-logo-katana" aria-label="Akibara, inicio">
			<svg class="logo__img akb-logo-svg" viewBox="0 0 960 380" role="img" aria-label="Akibara">
				<g mask="url(#akb-katana-mask)" aria-hidden="true">
					<g class="akb-katana-mover"></g>
				</g>
			</svg>
		</a>
	</header>
	<footer class="site-footer">
		<a href="https://akibara.cl/" class="footer-brand__logo akb-footer-logo" aria-label="Akibara, inicio">
			<svg class="footer-brand__svg akb-logo-svg" viewBox="0 0 960 380" role="img" aria-label="Akibara">
				<use class="akb-piece" data-piece="outline" href="#akb-outline"></use>
				<use class="akb-piece" data-piece="letter-1-a" href="#akb-letter-1-a"></use>
				<use class="akb-piece" data-piece="swoosh" href="#akb-swoosh"></use>
			</svg>
		</a>
	</footer>
`;

test('Footer SVG Logo: arma al iniciar, espera >=65% visible al bajar scroll y se re-arma al volver arriba', () => {
	let observerCallback = null;
	let observerOptions = null;
	const observed = [];
	let disconnected = 0;

	class MockIntersectionObserver {
		constructor(callback, options) {
			observerCallback = callback;
			observerOptions = options;
		}
		observe(el) {
			observed.push(el);
		}
		disconnect() {
			disconnected += 1;
		}
	}

	const dom = createDOM({
		html: LOGO_HTML,
		globals: {
			IntersectionObserver: MockIntersectionObserver,
			matchMedia: createMatchMedia(false),
			requestAnimationFrame: (fn) => {
				fn();
				return 1;
			},
			innerHeight: 900,
			innerWidth: 1440
		}
	});

	try {
		dom.loadScript(SCRIPT_PATH);

		const footerLogo = dom.document.querySelector('.akb-footer-logo');
		assert.ok(footerLogo, 'El contenedor .akb-footer-logo debe existir');
		assert.equal(observed.length, 1, 'Debe observar exactamente el logo del footer');
		assert.equal(observed[0], footerLogo);
		assert.deepEqual(Array.from(observerOptions.threshold), [0, 0.25, 0.65, 0.9]);
		assert.equal(footerLogo.classList.contains('akb-footer-armed'), true, 'Debe armarse mientras no entra en vista');
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), false, 'No debe revelarse antes de entrar en viewport');

		observerCallback([{ target: footerLogo, isIntersecting: true, intersectionRatio: 0.12 }]);
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), false, 'Con 12% visible en el borde no debe disparar aún');

		observerCallback([{ target: footerLogo, isIntersecting: true, intersectionRatio: 0.72 }]);
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), true, 'Debe revelar al alcanzar >=65% visible');
		assert.equal(disconnected, 0, 'Debe mantenerse observando para re-armarse al subir');

		observerCallback([{ target: footerLogo, isIntersecting: false, intersectionRatio: 0, boundingClientRect: { top: 960 } }]);
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), false, 'Al volver arriba y salir por debajo debe re-armarse');

		observerCallback([{ target: footerLogo, isIntersecting: true, intersectionRatio: 0.7 }]);
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), true, 'Al bajar nuevamente debe disparar otra vez');
	} finally {
		dom.teardown();
	}
});

test('Footer SVG Logo: en móvil exige salir por encima del bottom-nav salvo al llegar al fondo real', () => {
	class NoopObserver {
		observe() {}
		disconnect() {}
	}

	const dom = createDOM({
		html: LOGO_HTML + '<nav class="bottom-nav"></nav>',
		globals: {
			IntersectionObserver: NoopObserver,
			matchMedia: createMatchMedia(false),
			requestAnimationFrame: (fn) => {
				fn();
				return 1;
			},
			innerHeight: 812,
			innerWidth: 375
		}
	});

	try {
		const footerLogo = dom.document.querySelector('.akb-footer-logo');
		const bottomNav = dom.document.querySelector('.bottom-nav');
		let currentTop = 740;
		footerLogo.getBoundingClientRect = () => ({ top: currentTop, bottom: currentTop + 64, height: 64 });
		bottomNav.getBoundingClientRect = () => ({ top: 746, bottom: 812, height: 66 });

		let mockScrollY = 1200;
		Object.defineProperty(dom.window, 'scrollY', {
			get: () => mockScrollY,
			configurable: true
		});
		Object.defineProperty(dom.document.documentElement, 'clientHeight', { value: 812, configurable: true });
		Object.defineProperty(dom.document.documentElement, 'scrollHeight', { value: 4000, configurable: true });

		dom.loadScript(SCRIPT_PATH);
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), false, 'Tapado por bottom-nav no debe revelarse');

		currentTop = 660;
		dom.window.dispatchEvent(new dom.window.Event('scroll'));
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), true, 'Sobre bottom-nav con >=65% visible debe revelarse');

		currentTop = 840;
		dom.window.dispatchEvent(new dom.window.Event('scroll'));
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), false, 'Al subir por encima del footer vuelve a armarse');

		currentTop = 760;
		mockScrollY = 3180;
		dom.window.dispatchEvent(new dom.window.Event('scroll'));
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), true, 'Al tocar el piso real del documento debe revelar siempre');
	} finally {
		dom.teardown();
	}
});

test('Footer SVG Logo: página corta sin scroll (scrollHeight <= clientHeight) no queda oculta atrás del bottom-nav', () => {
	class NoopObserver {
		observe() {}
		disconnect() {}
	}

	const dom = createDOM({
		html: LOGO_HTML + '<nav class="bottom-nav"></nav>',
		globals: {
			IntersectionObserver: NoopObserver,
			matchMedia: createMatchMedia(false),
			requestAnimationFrame: (fn) => {
				fn();
				return 1;
			},
			innerHeight: 812,
			innerWidth: 375
		}
	});

	try {
		const footerLogo = dom.document.querySelector('.akb-footer-logo');
		const bottomNav = dom.document.querySelector('.bottom-nav');
		footerLogo.getBoundingClientRect = () => ({ top: 740, bottom: 804, height: 64 });
		bottomNav.getBoundingClientRect = () => ({ top: 746, bottom: 812, height: 66 });

		Object.defineProperty(dom.window, 'scrollY', { get: () => 0, configurable: true });
		Object.defineProperty(dom.document.documentElement, 'clientHeight', { value: 812, configurable: true });
		Object.defineProperty(dom.document.documentElement, 'scrollHeight', { value: 812, configurable: true });

		dom.loadScript(SCRIPT_PATH);
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), true, 'Sin más scroll posible debe revelar siempre');
	} finally {
		dom.teardown();
	}
});

test('Footer SVG Logo: respeta prefers-reduced-motion revelando inmediatamente sin observar', () => {
	let observerCreated = 0;

	class CountingObserver {
		constructor() {
			observerCreated += 1;
		}
		observe() {}
		disconnect() {}
	}

	const dom = createDOM({
		html: LOGO_HTML,
		globals: {
			IntersectionObserver: CountingObserver,
			matchMedia: createMatchMedia(true)
		}
	});

	try {
		dom.loadScript(SCRIPT_PATH);

		const footerLogo = dom.document.querySelector('.akb-footer-logo');
		assert.equal(footerLogo.classList.contains('akb-footer-armed'), false, 'Con reduced-motion nunca debe ocultarse en armed');
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), true, 'Con reduced-motion debe revelarse de inmediato');
		assert.equal(observerCreated, 0, 'Con reduced-motion no debe instanciar IntersectionObserver');

		const headerLogo = dom.document.querySelector('.akb-logo-katana');
		assert.equal(headerLogo.classList.contains('akb-katana-js'), false, 'Con reduced-motion no debe activar katana JS');
	} finally {
		dom.teardown();
	}
});

test('Footer SVG Logo: hace fallback inmediato si IntersectionObserver no está disponible', () => {
	const dom = createDOM({
		html: LOGO_HTML,
		globals: {
			matchMedia: createMatchMedia(false)
		}
	});

	try {
		delete dom.window.IntersectionObserver;
		dom.loadScript(SCRIPT_PATH);

		const footerLogo = dom.document.querySelector('.akb-footer-logo');
		assert.equal(footerLogo.classList.contains('akb-footer-armed'), false);
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), true, 'Sin IntersectionObserver debe revelarse por fallback');
	} finally {
		dom.teardown();
	}
});

test('Header SVG Logo: dispara Katana al cargar, en hover, foco, touch y cambio compact sin loop infinito', async () => {
	class NoopObserver {
		observe() {}
		disconnect() {}
	}

	const dom = createDOM({
		html: LOGO_HTML,
		globals: {
			IntersectionObserver: NoopObserver,
			matchMedia: createMatchMedia(false),
			requestAnimationFrame: (fn) => {
				fn();
				return 1;
			}
		}
	});

	try {
		dom.loadScript(SCRIPT_PATH);

		const headerLogo = dom.document.querySelector('.akb-logo-katana');
		const mover = dom.document.querySelector('.akb-katana-mover');
		const siteHeader = dom.document.querySelector('.site-header');
		assert.equal(headerLogo.classList.contains('akb-katana-js'), true, 'Debe marcar akb-katana-js al inicializar');

		await new Promise((resolve) => setTimeout(resolve, 340));
		assert.equal(headerLogo.classList.contains('akb-katana-run'), true, 'Debe disparar el destello Katana inicial al cargar');

		mover.dispatchEvent(new dom.window.Event('animationend'));
		assert.equal(headerLogo.classList.contains('akb-katana-run'), false, 'Debe limpiar akb-katana-run al terminar animationend');

		headerLogo.dispatchEvent(new dom.window.Event('mouseenter'));
		assert.equal(headerLogo.classList.contains('akb-katana-run'), true, 'Debe re-disparar en mouseenter');

		mover.dispatchEvent(new dom.window.Event('animationend'));
		assert.equal(headerLogo.classList.contains('akb-katana-run'), false);

		const evt = new dom.window.Event('pointerdown');
		evt.pointerType = 'touch';
		headerLogo.dispatchEvent(evt);
		assert.equal(headerLogo.classList.contains('akb-katana-run'), true, 'Debe disparar en touch/pointerdown móvil');

		// Mouseenter sintético inmediato tras tap táctil NO debe reiniciar ni cortar el barrido táctil
		headerLogo.dispatchEvent(new dom.window.Event('mouseenter'));
		assert.equal(headerLogo.classList.contains('akb-katana-run'), true, 'Mouseenter sintético post-touch no debe abortar');

		mover.dispatchEvent(new dom.window.Event('animationend'));
		assert.equal(headerLogo.classList.contains('akb-katana-run'), false);

		siteHeader.classList.add('is-compact');
		await new Promise((resolve) => setTimeout(resolve, 1150));
		siteHeader.classList.remove('is-compact');
		await new Promise((resolve) => setTimeout(resolve, 20));
		assert.equal(headerLogo.classList.contains('akb-katana-run'), true, 'Debe disparar al cambiar estado compact del header');
	} finally {
		dom.teardown();
	}
});

test('Header SVG Logo: si is-compact dispara antes de 300ms, el timer inicial NO corta el barrido en curso', async () => {
	class NoopObserver {
		observe() {}
		disconnect() {}
	}

	const dom = createDOM({
		html: LOGO_HTML,
		globals: {
			IntersectionObserver: NoopObserver,
			matchMedia: createMatchMedia(false),
			requestAnimationFrame: (fn) => {
				fn();
				return 1;
			}
		}
	});

	try {
		dom.loadScript(SCRIPT_PATH);

		const headerLogo = dom.document.querySelector('.akb-logo-katana');
		const siteHeader = dom.document.querySelector('.site-header');

		siteHeader.classList.add('is-compact');
		await new Promise((resolve) => setTimeout(resolve, 40));
		assert.equal(headerLogo.classList.contains('akb-katana-run'), true, 'Compact debe disparar barrido');

		await new Promise((resolve) => setTimeout(resolve, 320));
		assert.equal(headerLogo.classList.contains('akb-katana-run'), true, 'Timer de 300ms no debe haber cancelado el barrido activo');
	} finally {
		dom.teardown();
	}
});

test('Footer SVG Logo: no queda oculto en carga inicial parcialmente visible (/mi-cuenta/), respeta header sticky y sticky-atc, y libera .akb-is-settled para hover', async () => {
	class NoopObserver {
		observe() {}
		disconnect() {}
	}

	const dom = createDOM({
		html: LOGO_HTML + '<nav class="bottom-nav"></nav><div class="akb-sticky-atc is-visible"></div>',
		globals: {
			IntersectionObserver: NoopObserver,
			matchMedia: createMatchMedia(false),
			requestAnimationFrame: (fn) => {
				fn();
				return 1;
			},
			innerHeight: 900,
			innerWidth: 1440
		}
	});

	try {
		const footerLogo = dom.document.querySelector('.akb-footer-logo');
		footerLogo.getBoundingClientRect = () => ({ top: 838, bottom: 958, height: 120 });
		Object.defineProperty(dom.window, 'scrollY', { get: () => 0, configurable: true });
		Object.defineProperty(dom.document.documentElement, 'clientHeight', { value: 900, configurable: true });
		Object.defineProperty(dom.document.documentElement, 'scrollHeight', { value: 1371, configurable: true });

		dom.loadScript(SCRIPT_PATH);
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), true, 'Si ya asoma al cargar en y=0 debe revelarse y no dejar hueco negro');

		await new Promise((resolve) => setTimeout(resolve, 900));
		assert.equal(footerLogo.classList.contains('akb-is-settled'), true, 'Tras finalizar animación debe marcar .akb-is-settled para permitir hover');
	} finally {
		dom.teardown();
	}

	const domMobile = createDOM({
		html: LOGO_HTML + '<nav class="bottom-nav"></nav><div class="akb-sticky-atc is-visible"></div>',
		globals: {
			IntersectionObserver: NoopObserver,
			matchMedia: createMatchMedia(false),
			requestAnimationFrame: (fn) => {
				fn();
				return 1;
			},
			innerHeight: 812,
			innerWidth: 375
		}
	});

	try {
		const siteHeader = domMobile.document.querySelector('.site-header');
		const footerLogo = domMobile.document.querySelector('.akb-footer-logo');
		const bottomNav = domMobile.document.querySelector('.bottom-nav');
		const stickyAtc = domMobile.document.querySelector('.akb-sticky-atc');

		let logoTop = 1800;
		let scrollY = 0;
		siteHeader.getBoundingClientRect = () => ({ top: 0, bottom: 73, height: 73 });
		bottomNav.getBoundingClientRect = () => ({ top: 746, bottom: 812, height: 66 });
		stickyAtc.getBoundingClientRect = () => ({ top: 686, bottom: 746, height: 60 });
		footerLogo.getBoundingClientRect = () => ({ top: logoTop, bottom: logoTop + 64, height: 64 });
		Object.defineProperty(domMobile.window, 'scrollY', { get: () => scrollY, configurable: true });
		Object.defineProperty(domMobile.document.documentElement, 'clientHeight', { value: 812, configurable: true });
		Object.defineProperty(domMobile.document.documentElement, 'scrollHeight', { value: 4000, configurable: true });

		domMobile.loadScript(SCRIPT_PATH);
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), false);

		// Tapado por sticky-atc (top 686..746)
		scrollY = 2000;
		logoTop = 686;
		domMobile.window.dispatchEvent(new domMobile.window.Event('scroll'));
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), false, 'Tapado tras sticky-atc no debe revelarse');

		// Tapado detrás del header sticky (top -18..46 vs header bottom 73)
		scrollY = 3000;
		logoTop = -18;
		domMobile.window.dispatchEvent(new domMobile.window.Event('scroll'));
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), false, 'Tapado tras header sticky tampoco debe revelarse');

		// Descubierto en zona segura [77, 676]
		logoTop = 300;
		domMobile.window.dispatchEvent(new domMobile.window.Event('scroll'));
		assert.equal(footerLogo.classList.contains('akb-is-revealed'), true, 'Visible en banda segura entre header y sticky-atc debe revelar');
	} finally {
		domMobile.teardown();
	}
});

test('seguirAlFoco emite diagnóstico si matches() lanza error inesperado y evita fallo cuando :focus-visible no está soportado', () => {
	const warnings = [];
	const dom = createDOM({
		html: `
			<section class="akb-section">
				<div class="woocommerce">
					<ul class="products">
						<li class="product"><a href="/p1" id="p1-link">Tomo 1</a></li>
					</ul>
				</div>
			</section>
		`,
		url: 'https://akibara.cl/'
	});

	try {
		const row = dom.document.querySelector('.woocommerce ul.products');
		const link = dom.document.getElementById('p1-link');
		const card = link.closest('li.product');
		Object.defineProperty(row, 'scrollWidth', { value: 1200, configurable: true });
		Object.defineProperty(row, 'clientWidth', { value: 375, configurable: true });
		row.getBoundingClientRect = () => ({ left: 0, right: 375, top: 100, bottom: 400 });
		card.getBoundingClientRect = () => ({ left: 320, right: 480, top: 110, bottom: 390 });
		let scrollCalls = 0;
		card.scrollIntoView = () => {
			scrollCalls += 1;
		};
		Object.defineProperty(dom.window, 'CSS', {
			value: {
				supports: () => true
			},
			configurable: true
		});
		dom.window.console.warn = (...args) => {
			warnings.push(args);
		};
		link.matches = () => {
			throw new TypeError('fallo simulado');
		};

		dom.loadScript(SCRIPT_PATH);
		link.dispatchEvent(new dom.window.FocusEvent('focusin', { bubbles: true }));

		assert.equal(scrollCalls, 0);
		assert.equal(warnings.length, 1);
		assert.match(String(warnings[0][0]), /seguirAlFoco/);
	} finally {
		dom.teardown();
	}
});
