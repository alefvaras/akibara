const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

// Contrato EJECUTABLE de assets/js/animations-loader.js (PC-8/PM-11 + el cajón
// al agregar). El tema no tiene harness de DOM, así que se arma uno mínimo con
// EventTarget y un reloj falso: basta para correr el fichero de verdad y
// afirmar sobre lo que hace, no sobre cómo está escrito.

const themeRoot = path.resolve(__dirname, '..');
const fuente = fs.readFileSync(path.join(themeRoot, 'assets/js/animations-loader.js'), 'utf8');

const URLS = [
	'https://akibara.test/wp-content/themes/akibara-gp/assets/js/vendor/gsap.min.js?ver=1',
	'https://akibara.test/wp-content/themes/akibara-gp/assets/js/vendor/ScrollTrigger.min.js?ver=1',
	'https://akibara.test/wp-content/themes/akibara-gp/assets/js/animations.js?ver=1',
];

function montar({ diferidos = URLS, reduce = false, readyState = 'complete', conRic = true, globales = {} } = {}) {
	let ahora = 0;
	let siguiente = 1;
	const timers = new Map();
	const reloj = {
		setTimeout(fn, ms) { const id = siguiente++; timers.set(id, { fn, at: ahora + (ms || 0) }); return id; },
		clearTimeout(id) { timers.delete(id); },
		avanzar(ms) {
			const fin = ahora + ms;
			for (;;) {
				let proximo = null;
				for (const [id, t] of timers) { if (t.at <= fin && (!proximo || t.at < proximo[1].at)) { proximo = [id, t]; } }
				if (!proximo) { break; }
				timers.delete(proximo[0]);
				ahora = proximo[1].at;
				proximo[1].fn();
			}
			ahora = fin;
		},
	};

	const inyectados = [];
	const body = new EventTarget();
	body.appendChild = (nodo) => { inyectados.push(nodo); return nodo; };

	const document = new EventTarget();
	document.body = body;
	document.readyState = readyState;
	document.currentScript = { getAttribute: (n) => ('data-akb-diferidos' === n && diferidos ? JSON.stringify(diferidos) : null) };
	document.getElementsByTagName = () => inyectados.slice();
	document.querySelector = () => null;
	document.createElement = (tag) => {
		const attrs = {};
		return { tagName: tag.toUpperCase(), async: true, src: '', onerror: null, setAttribute(k, v) { attrs[k] = String(v); }, getAttribute(k) { return 'src' === k ? this.src : (attrs[k] ?? null); } };
	};

	const cajon = { aperturas: 0 };
	const idle = [];
	const window = new EventTarget();
	Object.assign(window, {
		matchMedia: () => ({ matches: reduce }),
		setTimeout: reloj.setTimeout,
		clearTimeout: reloj.clearTimeout,
		akbCart: { open() { cajon.aperturas++; } },
	}, globales);
	if (conRic) {
		window.requestIdleCallback = (fn) => { idle.push(fn); };
	}

	const contexto = vm.createContext({ window, document, JSON, Array, CustomEvent, Event });
	vm.runInContext(fuente, contexto);

	return {
		window, document, body, inyectados, cajon, reloj,
		correrIdle() { while (idle.length) { idle.shift()(); } },
		agregar(boton = { nodeType: 1 }) { body.dispatchEvent(new CustomEvent('akb:added_to_cart', { detail: [{}, 'hash', boton] })); },
		animacionesListas(volar) { window.akbAnimations = { volarAlCarrito: volar }; document.dispatchEvent(new CustomEvent('akb:animaciones-listas')); },
	};
}

test('fuera de la portada inyecta gsap → ScrollTrigger → animations tras load+idle, en orden', () => {
	const dom = montar();
	assert.equal(dom.inyectados.length, 0, 'nada viaja antes del reposo: es la ventana del LCP');
	dom.correrIdle();
	assert.deepEqual(dom.inyectados.map((s) => s.src), URLS);
	for (const s of dom.inyectados) {
		assert.equal(s.async, false, 'async=false: se ejecutan en orden aunque lleguen desordenados');
		assert.equal(s.getAttribute('data-no-optimize'), '1');
	}
});

test('sin requestIdleCallback (Safari) espera a load y usa setTimeout', () => {
	const dom = montar({ readyState: 'loading', conRic: false });
	dom.reloj.avanzar(5000);
	assert.equal(dom.inyectados.length, 0, 'antes de load no se inyecta nada');
	dom.window.dispatchEvent(new Event('load'));
	dom.reloj.avanzar(250);
	assert.equal(dom.inyectados.length, 3);
});

test('la primera interacción adelanta la carga y la hace UNA sola vez', () => {
	const dom = montar({ readyState: 'loading' });
	dom.window.dispatchEvent(new Event('pointerdown'));
	assert.equal(dom.inyectados.length, 3);
	dom.window.dispatchEvent(new Event('scroll'));
	dom.window.dispatchEvent(new Event('load'));
	dom.correrIdle();
	assert.equal(dom.inyectados.length, 3, 'ni el scroll, ni load, ni el reposo vuelven a inyectar');
});

test('con reducir movimiento no se descarga GSAP por adelantado', () => {
	const dom = montar({ reduce: true });
	dom.correrIdle();
	dom.window.dispatchEvent(new Event('pointerdown'));
	assert.equal(dom.inyectados.length, 0);
});

test('en la portada (sin data-akb-diferidos) no inyecta nada', () => {
	const dom = montar({ diferidos: null });
	dom.correrIdle();
	dom.window.dispatchEvent(new Event('pointerdown'));
	assert.equal(dom.inyectados.length, 0);
});

test('no duplica GSAP si otro script ya lo trajo (window.gsap es contrato)', () => {
	const dom = montar({ globales: { gsap: {}, ScrollTrigger: {} } });
	dom.window.gsap = {};
	dom.window.ScrollTrigger = {};
	// El contexto vm expone las globales por `window`, que es lo que lee el cargador.
	dom.correrIdle();
	assert.deepEqual(dom.inyectados.map((s) => s.src), [URLS[2]]);
});

test('agregar con las animaciones listas: vuela y abre el cajón UNA vez al aterrizar', () => {
	const dom = montar();
	const vuelos = [];
	dom.animacionesListas((btn, alAterrizar) => { vuelos.push(btn); alAterrizar(); alAterrizar(); });
	const boton = { nodeType: 1, id: 'comprar' };
	dom.agregar(boton);
	assert.equal(vuelos.length, 1);
	assert.equal(vuelos[0], boton, 'el vuelo sale de la portada del botón que agregó');
	assert.equal(dom.cajon.aperturas, 1);
});

test('el wrapper jQuery del botón ($button) también se entiende', () => {
	const dom = montar();
	const vuelos = [];
	dom.animacionesListas((btn, alAterrizar) => { vuelos.push(btn); alAterrizar(); });
	const nodo = { nodeType: 1 };
	dom.agregar({ length: 1, 0: nodo });
	assert.equal(vuelos[0], nodo);
});

test('agregar ANTES de que llegue GSAP: pide la carga, espera y vuela en el primer clic', () => {
	const dom = montar({ readyState: 'loading' });
	const vuelos = [];
	dom.agregar();
	assert.equal(dom.inyectados.length, 3, 'el clic de compra dispara la descarga si nada lo hizo antes');
	assert.equal(dom.cajon.aperturas, 0, 'todavía no: se espera al vuelo');
	dom.reloj.avanzar(300);
	dom.animacionesListas((btn, alAterrizar) => { vuelos.push(btn); alAterrizar(); });
	assert.equal(vuelos.length, 1, 'llegó a tiempo: hay vuelo');
	assert.equal(dom.cajon.aperturas, 1);
	dom.reloj.avanzar(2000);
	assert.equal(dom.cajon.aperturas, 1, 'el plazo vencido después no reabre el cajón');
});

test('si GSAP no llega a tiempo, el cajón abre igual (el incentivo nunca se pierde)', () => {
	const dom = montar();
	dom.agregar();
	dom.reloj.avanzar(799);
	assert.equal(dom.cajon.aperturas, 0);
	dom.reloj.avanzar(1);
	assert.equal(dom.cajon.aperturas, 1);
});

test('si la descarga falla, el cajón abre sin esperar el plazo', () => {
	const dom = montar();
	dom.agregar();
	dom.inyectados[0].onerror();
	assert.equal(dom.cajon.aperturas, 1);
	dom.agregar();
	assert.equal(dom.cajon.aperturas, 2, 'tras un fallo, los siguientes altas abren directo');
});

test('con reducir movimiento o el menú abierto, cajón directo y sin vuelo', () => {
	const dom = montar({ reduce: true });
	let vuelos = 0;
	dom.animacionesListas(() => { vuelos++; });
	dom.agregar();
	assert.equal(vuelos, 0);
	assert.equal(dom.cajon.aperturas, 1);

	const otro = montar();
	otro.document.querySelector = (sel) => (/mobile-drawer|hamburger/.test(sel) ? {} : null);
	otro.animacionesListas(() => { vuelos++; });
	otro.agregar();
	assert.equal(vuelos, 0);
	assert.equal(otro.cajon.aperturas, 1);
});

test('un vuelo que lanza no deja al cliente sin cajón', () => {
	const dom = montar();
	dom.animacionesListas(() => { throw new Error('gsap roto'); });
	dom.agregar();
	assert.equal(dom.cajon.aperturas, 1);
});

test('animations.js ya no escucha akb:added_to_cart: un solo dueño del cajón', () => {
	const js = fs.readFileSync(path.join(themeRoot, 'assets/js/animations.js'), 'utf8');
	assert.doesNotMatch(js, /addEventListener\('akb:added_to_cart'/, 'dos oyentes abrirían el cajón dos veces');
	assert.match(js, /window\.akbAnimations = \{ volarAlCarrito: volarAlCarrito \};/);
	assert.match(js, /dispatchEvent\(new CustomEvent\('akb:animaciones-listas'\)\)/);
});
