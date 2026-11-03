/*!
 * Akibara GP — franja-inferior.js (todas las páginas con barras fijas abajo)
 *
 * Mide en vivo cuánto ocupa la franja inferior fija —el bottom-nav y, en la
 * ficha, la barra de compra apilada encima— y lo escribe en la custom property
 * `--akb-franja-inferior` del elemento raíz. base.css la usa como
 * `scroll-padding-bottom`, para que al tabular, con scrollIntoView, con un
 * ancla o con Ctrl+F el navegador nunca dé por «visible» lo que la franja tapa.
 *
 * Medición dinámica: La altura total depende de los elementos activos en cada pantalla
 * (bottom navigation + barra de compra condicional). La medición en vivo con
 * ResizeObserver garantiza que el espacio reservado se adapte con precisión.
 *
 * El valor medido incluye safe-area-inset-bottom cuando está activo en iOS.
 *
 * Consideraciones de interacción:
 *   - Barras apiladas: se acumula la altura combinada de los elementos fijados al pie.
 *   - Filtrado de overlays: modales y drawers de pantalla completa se excluyen del cálculo.
 *   - Enfoque por teclado: los controles enfocados se aseguran por encima de la franja.
 *   - Los eventos de puntero/toque no disparan desplazamientos forzados para evitar
 *     desalineación de coordenadas entre mousedown y mouseup.
 *
 * Sin dependencias, sin jQuery. Si el navegador no soporta ResizeObserver, el
 * valor de respaldo del CSS proporciona la reserva segura.
 */
(function () {
	'use strict';

	/* Las dos barras que hoy forman la franja. La barra de compra sólo cuenta
	 * cuando sticky-atc.js le pone `.is-visible`; oculta mide 0 y no suma. Si
	 * mañana se estrena otra barra basta añadirla aquí —y aunque no se añada,
	 * la red de seguridad de más abajo la detecta igual, porque mira lo que
	 * hay dibujado y no una lista de clases. */
	var BARRAS = '.bottom-nav, .akb-sticky-atc';
	var PROPIEDAD = '--akb-franja-inferior';

	/* Cuánto dura, tras un pointerdown, la sospecha de que el siguiente
	 * focusin viene del puntero. En un toque, el focus se sintetiza junto al
	 * mousedown como mucho unos cientos de ms después del pointerdown; un
	 * segundo lo cubre de sobra. Una tecla cierra la ventana al instante. */
	var VENTANA_PUNTERO = 1000;

	var raiz = document.documentElement;

	/* Último valor escrito en la propiedad, en px. Sirve para no reescribir
	 * la propiedad heredada por todo el documento cuando nada cambió, y para
	 * que aire() deduzca el aire del CSS restándolo de la reserva computada. */
	var escrito = -1;

	/* requestAnimationFrame pendiente: todas las señales de remedida (resize,
	 * clase, transición, ResizeObserver) se agrupan en una sola pasada por
	 * cuadro. En iOS Safari el colapso de la barra de URL emite `resize` de
	 * continuo DURANTE el scroll; medir en cada uno forzaba layout en el peor
	 * momento. */
	var pendiente = 0;

	/* Momento del último pointerdown/mousedown; 0 cuando lo cerró una tecla. */
	var ultimoPuntero = 0;

	/* Cuánto dura la vigilancia del control recién enfocado con teclado. La
	 * barra aparece uno o dos cuadros después del desplazamiento del foco
	 * (IntersectionObserver) y termina de entrar con su transición (150 ms,
	 * --transition-fast); 1,5 s lo cubre con holgura y no llega a pisar un
	 * desplazamiento que el cliente haga después: cualquier gesto o tecla
	 * termina la vigilancia antes (ver soltarVigilado). */
	var VIGILANCIA = 1500;

	/* Control enfocado con teclado cuya visibilidad se vigila mientras la
	 * franja crece: { control, hasta } o null. */
	var vigilado = null;

	/* ¿Está el nodo a medio aparecer o desaparecer? La barra de compra
	 * transiciona `visibility`, y esa propiedad computa `hidden` en el
	 * instante exacto en que arranca la transición (p=0) y `visible` durante
	 * todo el resto, en los dos sentidos. Medido en el espejo: el aviso del
	 * cambio de clase y hasta el primer rAF caen en ese instante, así que
	 * mirar sólo `visibility` daba 66px con la barra ya entrando. Si hay una
	 * transición corriendo sobre el nodo, está pintado: al terminar, el
	 * `transitionend` remide con el estado definitivo. Sin getAnimations()
	 * (navegadores viejos) queda el criterio de antes. */
	var enTransicion = function (nodo) {
		return typeof nodo.getAnimations === 'function' && nodo.getAnimations().length > 0;
	};

	/* Caja de una barra si está PINTADA y pegada abajo, o null. Con
	 * `display:none` no tapa nada; con `visibility:hidden` tampoco, salvo que
	 * esté a medio transicionar (ver enTransicion). Un fijo que no llega al
	 * borde inferior de la ventana no forma parte de la franja. El rect se
	 * lee UNA vez y se devuelve: los consumidores lo reutilizan en vez de
	 * volver a forzar layout sobre el mismo nodo. */
	var cajaPegada = function (nodo, techo, estilo) {
		estilo = estilo || window.getComputedStyle(nodo);

		if (estilo.display === 'none') {
			return null;
		}

		if (estilo.visibility === 'hidden' && !enTransicion(nodo)) {
			return null;
		}

		var caja = nodo.getBoundingClientRect();

		return caja.height > 0
			&& caja.width > 0
			&& caja.top < techo
			&& caja.bottom >= techo - 1
			? caja
			: null;
	};

	/* Un fijo que ocupa más de media ventana no es una barra: es el cajón del
	 * carrito, el drawer del menú o su overlay. Medidos con el carrito CERRADO
	 * siguen midiendo 812px, así que sin este descarte la red de seguridad
	 * creería que toda la pantalla está tapada. */
	var esOverlay = function (caja) {
		return caja.height > window.innerHeight / 2;
	};

	var medir = function () {
		var barras = document.querySelectorAll(BARRAS);
		var techo = window.innerHeight;
		var vueltas = 0;
		var muestra;
		var caja;
		var j;

		/* Se sube techo a techo, no de una pasada: la barra de compra se
		 * apoya SOBRE el bottom-nav y no toca el borde de la ventana, así que
		 * sólo cuenta como pegada respecto del techo que deja la de abajo.
		 * Medido al estrenar este fichero: de una pasada daba 66 con la
		 * franja en 132. Y se toma el `top` MÍNIMO, no la suma: una suma
		 * contaría dos veces el tramo en que se solapan. */
		do {
			muestra = techo;

			for (j = 0; j < barras.length; j++) {
				caja = cajaPegada(barras[j], muestra);

				if (caja) {
					techo = Math.min(techo, caja.top);
				}
			}
		} while (techo < muestra && ++vueltas < 4);

		var valor = Math.max(0, Math.ceil(window.innerHeight - techo));

		if (valor === escrito) {
			return;
		}

		var crecio = valor > escrito;

		escrito = valor;
		raiz.style.setProperty(PROPIEDAD, valor + 'px');

		/* La franja creció (la barra de compra entró) con un control recién
		 * enfocado con teclado: puede haber quedado debajo. */
		if (crecio && vigilado) {
			if (Date.now() < vigilado.hasta && document.activeElement === vigilado.control) {
				rematar(vigilado.control);
			} else {
				vigilado = null;
			}
		}
	};

	var programar = function () {
		if (pendiente) {
			return;
		}

		pendiente = window.requestAnimationFrame(function () {
			pendiente = 0;
			medir();
		});
	};

	/* Aire que el CSS deja entre el control y la franja, en px de verdad.
	 *
	 * No se lee `--space-2`: su valor computado es la cadena `0.5rem` (viene
	 * de un preset de theme.json) y un rem no se puede convertir aquí sin
	 * conocer la tipografía raíz. Lo que sí resuelve el navegador en px es el
	 * `scroll-padding-bottom` computado, que por construcción vale «franja
	 * escrita + aire»; restar lo escrito deja exactamente el aire del CSS,
	 * también cuando la raíz no mide 16px. Si la reserva no está fijada (en
	 * escritorio computa `auto`), 8px: el mismo respaldo de base.css. */
	var aire = function () {
		var reservado = parseFloat(window.getComputedStyle(raiz).scrollPaddingBottom);

		if (isNaN(reservado) || escrito < 0) {
			return 8;
		}

		return Math.max(0, reservado - escrito);
	};

	/* Sin animación, pase lo que pase: se corrige lo justo y la página no
	 * debe «viajar». Fijar `scroll-behavior:auto` en línea durante el salto
	 * respeta prefers-reduced-motion por construcción —no hay smooth que
	 * apagar— y no depende de que el navegador acepte `behavior:'instant'`. */
	var desplazar = function (px) {
		var previo = raiz.style.scrollBehavior;

		raiz.style.scrollBehavior = 'auto';
		window.scrollBy(0, px);
		raiz.style.scrollBehavior = previo;
	};

	/* ¿Está el control dentro de un fijo? Entonces se desplaza dentro de su
	 * contenedor (cajón del carrito, drawer), no con el documento, y la
	 * corrección de abajo movería la página de detrás sin arreglar nada. */
	var dentroDeFijo = function (control) {
		var nodo = control.parentElement;

		while (nodo && nodo !== document.body) {
			if (window.getComputedStyle(nodo).position === 'fixed') {
				return true;
			}
			nodo = nodo.parentElement;
		}

		return false;
	};

	/* Techo real de la franja según lo DIBUJADO, sin lista de selectores.
	 *
	 * Muestrea el borde inferior de la ventana y sube: cada fijo pegado que
	 * aparece baja el techo, y se vuelve a muestrear justo encima de él
	 * hasta que ya no hay nada pegado. Así la barra apilada sobre el
	 * bottom-nav —que no toca el último píxel de la ventana— también cuenta.
	 * El tope de vueltas evita un bucle si algo se pinta raro; cuatro barras
	 * apiladas es más de lo que el tema ha tenido nunca. */
	var techoDibujado = function (control, x) {
		var techo = window.innerHeight;
		var vueltas = 0;
		var muestra;
		var encontrado;
		var encima;
		var nodo;
		var estilo;
		var caja;
		var m;

		while (vueltas++ < 4 && techo > 0) {
			/* Todos los nodos de esta vuelta se juzgan contra el MISMO punto
			 * muestreado, no contra el techo que van rebajando entre ellos. */
			muestra = techo;
			encontrado = false;
			encima = document.elementsFromPoint(x, muestra - 1);

			for (m = 0; m < encima.length; m++) {
				nodo = encima[m];

				if (nodo === control || nodo.contains(control)) {
					continue;
				}

				estilo = window.getComputedStyle(nodo);

				if (estilo.position !== 'fixed' && estilo.position !== 'sticky') {
					continue;
				}

				caja = cajaPegada(nodo, muestra, estilo);

				if (!caja || esOverlay(caja)) {
					continue;
				}

				techo = Math.min(techo, caja.top);
				encontrado = true;
			}

			if (!encontrado) {
				break;
			}
		}

		return techo;
	};

	/* Red de seguridad para lo que el `scroll-padding` no alcanza.
	 *
	 * El navegador sólo desplaza al enfocar si considera que el control no
	 * está visible, y un control cuyo último píxel cae bajo la barra le parece
	 * visible: ahí no desplaza nada y el `scroll-padding` nunca llega a
	 * aplicarse. Mira lo que hay DIBUJADO sobre el borde inferior en vez de
	 * una lista de selectores, así que cubre también la barra que se estrene
	 * mañana. Corrige sólo cuando hay solape real y hay recorrido, con el
	 * desplazamiento mínimo y sin animación. La llaman el focusin (foco de
	 * teclado o programático) y medir(), cuando la franja crece con ese
	 * control todavía enfocado. */
	var rematar = function (control) {
		var caja = control.getBoundingClientRect();

		if (caja.height <= 0) {
			return;
		}

		if (!document.elementsFromPoint) {
			return;
		}

		var x = Math.min(Math.max(caja.left + caja.width / 2, 1), window.innerWidth - 1);
		var techo = techoDibujado(control, x);

		/* Sin nada pegado abajo no hay nada que rematar: del control que
		 * simplemente no cabe ya se encarga el navegador. */
		if (techo >= window.innerHeight) {
			return;
		}

		var tapado = caja.bottom - techo;

		if (tapado <= 0) {
			return;
		}

		var recorrido = raiz.scrollHeight - window.innerHeight - window.scrollY;

		desplazar(Math.min(Math.ceil(tapado + aire()), Math.max(0, recorrido)));
	};

	var soltarVigilado = function () {
		vigilado = null;
	};

	var marcarPuntero = function () {
		ultimoPuntero = Date.now();
	};

	var cerrarPuntero = function () {
		ultimoPuntero = 0;
	};

	/* Sólo remide cuando termina la transición de la PROPIA barra:
	 * `transitionend` burbujea, y los ítems del bottom-nav (color, transform
	 * al pulsar) y el botón de la barra (background) transicionan en cada
	 * hover o toque sin que la franja cambie de alto. */
	var alTerminarTransicion = function (evento) {
		if (evento.target === evento.currentTarget) {
			programar();
		}
	};

	var arrancar = function () {
		medir();

		var barras = document.querySelectorAll(BARRAS);
		var k;

		/* ResizeObserver, no un cálculo de una vez: la barra cambia de alto
		 * al rotar el móvil o cuando el precio envuelve en otro ancho, y pasa
		 * de 0 a su alto cuando se muestra. Se observa la caja de BORDE, que
		 * es la que tapa y la que lee getBoundingClientRect: la de contenido
		 * (la opción por defecto) no avisa si lo que cambia es el padding o
		 * el borde. Los navegadores que no entienden la opción la ignoran y
		 * observan el contenido, que es lo que había. */
		if (typeof window.ResizeObserver === 'function') {
			var observadorTamano = new window.ResizeObserver(programar);

			for (k = 0; k < barras.length; k++) {
				observadorTamano.observe(barras[k], { box: 'border-box' });
			}
		}

		/* La barra de compra entra con `.is-visible` y una transición de
		 * transform+visibility. El aviso del cambio de clase (y el rAF que
		 * agrupa) caen a p=0 de la transición: ahí la barra cuenta gracias a
		 * enTransicion(), pero con el `top` 8px más abajo porque el transform
		 * aún no llegó (124 en vez de 132, medido). El valor DEFINITIVO lo
		 * pone el `transitionend`: no es redundante, es el único que mide la
		 * barra en su sitio. */
		if (typeof window.MutationObserver === 'function') {
			var observadorClase = new window.MutationObserver(programar);

			for (k = 0; k < barras.length; k++) {
				observadorClase.observe(barras[k], { attributes: true, attributeFilter: ['class'] });
				barras[k].addEventListener('transitionend', alTerminarTransicion);
			}
		}

		window.addEventListener('resize', programar);
		window.addEventListener('orientationchange', programar);

		/* Origen del foco: un pointerdown/mousedown reciente marca el
		 * siguiente focusin como «por puntero»; una tecla cierra la marca. En
		 * captura, para verlo aunque un handler del control pare el evento. */
		document.addEventListener('pointerdown', marcarPuntero, true);
		document.addEventListener('mousedown', marcarPuntero, true);
		document.addEventListener('keydown', cerrarPuntero, true);

		/* Cada foco de teclado o programático pasa por la red de seguridad
		 * (rematar) y queda vigilado por si la franja crece encima. */
		document.addEventListener('focusin', function (evento) {
			var control = evento.target;

			if (!control || !control.getBoundingClientRect || !control.closest) {
				return;
			}

			/* Foco por toque o clic: no se desplaza (ver cabecera). El dedo
			 * ya está sobre el control; moverlo ahora pierde el toque. */
			if (ultimoPuntero && Date.now() - ultimoPuntero < VENTANA_PUNTERO) {
				vigilado = null;
				return;
			}

			if (control.closest(BARRAS) || dentroDeFijo(control)) {
				vigilado = null;
				return;
			}

			vigilado = { control: control, hasta: Date.now() + VIGILANCIA };
			rematar(control);
		});

		/* Cualquier gesto del cliente termina la vigilancia: a partir de ahí
		 * la página se mueve porque él la mueve, y devolverla al control
		 * enfocado sería pelearle el scroll. En captura, como las marcas del
		 * puntero. La tecla que mueve el foco (Tab) también la termina, pero
		 * el focusin que la sigue la vuelve a armar para el control nuevo. */
		['wheel', 'touchstart', 'pointerdown', 'keydown'].forEach(function (tipo) {
			document.addEventListener(tipo, soltarVigilado, { capture: true, passive: true });
		});
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', arrancar);
	} else {
		arrancar();
	}
}());
