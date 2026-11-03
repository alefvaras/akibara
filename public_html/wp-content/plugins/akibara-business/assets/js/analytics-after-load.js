/* Akibara: GA4 y Clarity después de la carga (AnalyticsAfterLoad.php). */
(function (w, d) {
	'use strict';

	var ESCUCHAS = ['pointerdown', 'keydown', 'touchstart', 'scroll', 'wheel'];
	var hecho = false;

	function cargar() {
		if (hecho) {
			return;
		}
		hecho = true;
		ESCUCHAS.forEach(function (tipo) {
			w.removeEventListener(tipo, cargar, true);
		});
		var nodos = d.querySelectorAll('script[data-akb-after-load]');
		for (var i = 0; i < nodos.length; i++) {
			var url = nodos[i].getAttribute('data-akb-after-load');
			nodos[i].removeAttribute('data-akb-after-load');
			if (!url) {
				continue;
			}
			var s = d.createElement('script');
			s.async = true;
			s.src = url;
			s.setAttribute('data-no-optimize', '1');
			(d.head || d.body).appendChild(s);
		}
	}

	function alQuedarLibre() {
		if (w.requestIdleCallback) {
			w.requestIdleCallback(cargar, { timeout: 2000 });
		} else {
			w.setTimeout(cargar, 200);
		}
	}

	ESCUCHAS.forEach(function (tipo) {
		w.addEventListener(tipo, cargar, { capture: true, passive: true });
	});
	if ('complete' === d.readyState) {
		alQuedarLibre();
	} else {
		w.addEventListener('load', alQuedarLibre, { once: true });
	}
})(window, document);
