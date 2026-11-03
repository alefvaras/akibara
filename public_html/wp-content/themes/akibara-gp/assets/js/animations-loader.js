/*!
 * Akibara — cargador de las micro-animaciones y dueño de «agregar → cajón».
 *
 * 1) CAJÓN AL AGREGAR, SIEMPRE. Al oír `akb:added_to_cart` (el shim
 *    wc-event-bridge.js re-emite el `added_to_cart` jQuery de WooCommerce con
 *    `detail = [fragments, cart_hash, $button]`) abre el cajón lateral
 *    (window.akbCart.open) con la barra de envío gratis y las recomendaciones:
 *    el incentivo a llenar el carrito. Si hay movimiento permitido y
 *    animations.js está listo, antes pide el «vuela al carrito» y abre el cajón
 *    al aterrizar. Bajo reducir movimiento, con el menú hamburguesa abierto, si
 *    GSAP no llega o si tarda más de ESPERA_VUELO_MS, abre el cajón directo.
 *    Este fichero no depende de GSAP a propósito: mientras el listener vivía en
 *    animations.js, un GSAP caído dejaba al cliente sin cajón.
 *
 * 2) CARGA DIFERIDA de GSAP + ScrollTrigger + animations.js fuera de la portada.
 *    Optimiza el Largest Contentful Paint (LCP) difiriendo scripts de animación
 *    bajo el pliegue hasta el evento `load` + requestIdleCallback (o primera interacción
 *    del usuario). Las URLs se obtienen desde `data-akb-diferidos` (inc/enqueue.php).
 *    En la portada se encolan directamente en el HTML inicial para el intro del hero.
 *
 * Los tres ficheros se inyectan con `async = false`: se ejecutan en orden
 * (gsap → ScrollTrigger → animations) aunque lleguen desordenados. Si otro
 * script de la página ya trajo GSAP (el carrusel de reseñas de rewards lo
 * declara como dependencia), no se vuelve a inyectar: dos copias de GSAP se
 * pisarían `window.gsap`, que es contrato.
 */
(function () {
	'use strict';

	var doc = document;
	var body = doc.body;
	if (!body) {
		return;
	}

	// Cuánto se espera a animations.js antes de abrir el cajón sin vuelo. El
	// alta por AJAX ya tardó su ida y vuelta al servidor, y el toque que la
	// disparó empezó la descarga (ver ESCUCHAS): lo normal es que llegue antes.
	var ESPERA_VUELO_MS = 800;
	var ESCUCHAS = ['pointerdown', 'keydown', 'touchstart', 'scroll', 'wheel'];

	var reduceMql = window.matchMedia('(prefers-reduced-motion: reduce)');
	var diferidos = leerDiferidos(doc.currentScript);
	var estado = 'inicial'; // → 'cargando' → 'listo' | 'fallido'
	var esperando = [];

	function leerDiferidos(nodo) {
		if (!nodo) {
			return [];
		}
		try {
			var lista = JSON.parse(nodo.getAttribute('data-akb-diferidos') || '[]');
			return Array.isArray(lista)
				? lista.filter(function (url) { return 'string' === typeof url && '' !== url; })
				: [];
		} catch (err) {
			return [];
		}
	}

	function listas() {
		var anim = window.akbAnimations;
		return anim && 'function' === typeof anim.volarAlCarrito ? anim : null;
	}

	function avisar() {
		var cola = esperando;
		esperando = [];
		var anim = listas();
		cola.forEach(function (responder) { responder(anim); });
	}

	doc.addEventListener('akb:animaciones-listas', function () {
		estado = 'listo';
		avisar();
	});

	// ¿Ya está en la página? Por la variable global (ya se ejecutó) o por un
	// <script> con el mismo fichero (impreso por WordPress como dependencia de
	// otro, todavía sin ejecutar).
	function yaPresente(url) {
		var fichero = url.split('?')[0].split('/').pop();
		if ('gsap.min.js' === fichero && 'undefined' !== typeof window.gsap) {
			return true;
		}
		if ('ScrollTrigger.min.js' === fichero && 'undefined' !== typeof window.ScrollTrigger) {
			return true;
		}
		var scripts = doc.getElementsByTagName('script');
		for (var i = 0; i < scripts.length; i++) {
			var src = scripts[i].getAttribute('src') || '';
			if (src.split('?')[0].split('/').pop() === fichero) {
				return true;
			}
		}
		return false;
	}

	function fallar() {
		estado = 'fallido';
		avisar();
	}

	function quitarEscuchas() {
		ESCUCHAS.forEach(function (tipo) {
			window.removeEventListener(tipo, alInteractuar, true);
		});
	}

	function cargar() {
		if ('inicial' !== estado || !diferidos.length || listas()) {
			return;
		}
		estado = 'cargando';
		quitarEscuchas();
		diferidos.forEach(function (url) {
			if (yaPresente(url)) {
				return;
			}
			var script = doc.createElement('script');
			script.src = url;
			script.async = false;
			script.setAttribute('data-no-optimize', '1');
			script.onerror = fallar;
			body.appendChild(script);
		});
	}

	// Disparadores del cliente. Bajo reducir movimiento no se carga nada por
	// adelantado: animations.js no anima nada ahí y el vuelo no se pide.
	function alInteractuar() {
		if (!reduceMql.matches) {
			cargar();
		}
	}

	function alQuedarLibre() {
		var enReposo = window.requestIdleCallback
			? function (fn) { window.requestIdleCallback(fn, { timeout: 2000 }); }
			: function (fn) { window.setTimeout(fn, 200); };
		enReposo(alInteractuar);
	}

	if (diferidos.length) {
		ESCUCHAS.forEach(function (tipo) {
			window.addEventListener(tipo, alInteractuar, { capture: true, passive: true });
		});
		if ('complete' === doc.readyState) {
			alQuedarLibre();
		} else {
			window.addEventListener('load', alQuedarLibre, { once: true });
		}
	}

	// Entrega animations.js en cuanto esté, o null si no llega a tiempo.
	function cuandoListas(ms, cb) {
		var anim = listas();
		if (anim || 'fallido' === estado) {
			cb(anim);
			return;
		}
		var hecho = false;
		var reloj = window.setTimeout(function () { responder(null); }, ms);
		function responder(valor) {
			if (hecho) {
				return;
			}
			hecho = true;
			window.clearTimeout(reloj);
			cb(valor);
		}
		esperando.push(responder);
		cargar();
	}

	function abrirCajon() {
		if (window.akbCart && 'function' === typeof window.akbCart.open) {
			window.akbCart.open();
		}
	}

	body.addEventListener('akb:added_to_cart', function (e) {
		var abierto = false;
		function alAterrizar() {
			if (abierto) {
				return;
			}
			abierto = true;
			abrirCajon();
		}
		try {
			var detail = e.detail || [];
			var raw = detail[2]; // el botón: nodo DOM o wrapper jQuery ($button).
			var btn = raw && raw.nodeType ? raw : (raw && raw.length ? raw[0] : null);
			var hamburguesa = !!doc.querySelector('#mobile-drawer[aria-hidden="false"], .hamburger.active');
			if (reduceMql.matches || hamburguesa) {
				alAterrizar();
				return;
			}
			cuandoListas(ESPERA_VUELO_MS, function (anim) {
				if (!anim) {
					alAterrizar();
					return;
				}
				try {
					anim.volarAlCarrito(btn, alAterrizar);
				} catch (err) {
					alAterrizar();
				}
			});
		} catch (err) {
			alAterrizar();
		}
	});
})();
