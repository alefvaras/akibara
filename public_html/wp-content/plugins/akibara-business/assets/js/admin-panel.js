(function () {
	'use strict';

	/* Único JS propio de esta pantalla: el <select> de secciones que el panel
	 * pinta para viewports angostos (el nav lateral se oculta ahí).
	 *
	 * Lo demás que vivía en este fichero —poll del rebuild, picker de facetas,
	 * reordenar facetas, y el confirm de [data-akb-confirm]— era copia literal de
	 * assets/js/admin-search.js, que el buscador escribió PARA reemplazarla. Ese
	 * marcado vive en la pantalla del buscador (submenú propio) y este fichero
	 * sólo se encola en toplevel_page_akibara-panel, así que no podía alcanzarlo
	 * nunca. El confirm sí llegaba, y de más: duplicaba el de
	 * series-bundles-admin.js —dueño real, porque su módulo emite el atributo— y
	 * «Activar packs sugeridos» pedía confirmación dos veces. */
	document.addEventListener('DOMContentLoaded', function () {
		var selector = document.getElementById('akb-panel-section-select');

		if (!selector) {
			return;
		}

		selector.addEventListener('change', function () {
			if (selector.value) {
				window.location.href = selector.value;
			}
		});
	});

	/*
	 * Publica la mayor altura de las barras de guardado en --akb-panel-barra-alto. ResizeObserver
	 * sigue sus cambios de tamaño y visibilidad; sin soporte se conserva la medida inicial y el
	 * respaldo CSS. La reserva de scroll incluye el aire definido por CSS.
	 */
	var BARRAS = '.akb-panel .submit, .akb-panel .akb-sticky-save-bar';

	document.addEventListener('DOMContentLoaded', function () {
		var raiz = document.documentElement;
		var panel = document.querySelector('.akb-panel');

		if (!panel) {
			return;
		}

		var pegada = function (caja) {
			return caja.height > 0
				&& caja.bottom <= window.innerHeight + 1
				&& caja.top < window.innerHeight;
		};

		var medir = function () {
			var barras = document.querySelectorAll(BARRAS);
			var alto = 0;
			var j;

			for (j = 0; j < barras.length; j++) {
				if (window.getComputedStyle(barras[j]).position === 'sticky') {
					alto = Math.max(alto, Math.ceil(barras[j].getBoundingClientRect().height));
				}
			}

			raiz.style.setProperty('--akb-panel-barra-alto', alto + 'px');
		};

		medir();

		if (typeof window.ResizeObserver === 'function') {
			var observador = new window.ResizeObserver(medir);
			var iniciales = document.querySelectorAll(BARRAS);
			var k;

			for (k = 0; k < iniciales.length; k++) {
				observador.observe(iniciales[k]);
			}
		}

		/*
		 * Comprueba el solape real dibujado sobre el borde inferior del control enfocado. Si
		 * scroll-padding no provoca desplazamiento, corrige sólo la parte tapada, dentro del recorrido
		 * disponible y sin animación; no depende de una lista cerrada de barras.
		 */
		document.addEventListener('focusin', function (evento) {
			var control = evento.target;

			if (!control || !control.getBoundingClientRect || !control.closest) {
				return;
			}

			if (!control.closest('.akb-panel-content') || control.closest(BARRAS)) {
				return;
			}

			var caja = control.getBoundingClientRect();

			if (caja.height <= 0) {
				return;
			}

			/* Se muestrea el borde INFERIOR de la ventana, no el del control:
			 * ahí está siempre lo que esté pegado abajo, también cuando el
			 * control se sale de la pantalla —que es justo cuando el navegador
			 * lo deja a medias y hay que rematar. */
			var x = Math.min(Math.max(caja.left + caja.width / 2, 1), window.innerWidth - 1);
			var encima = document.elementsFromPoint
				? document.elementsFromPoint(x, window.innerHeight - 1)
				: [];
			var techo = window.innerHeight;
			var m;

			for (m = 0; m < encima.length; m++) {
				var nodo = encima[m];

				if (nodo === control || nodo.contains(control)) {
					continue;
				}

				var posicion = window.getComputedStyle(nodo).position;

				if (posicion !== 'sticky' && posicion !== 'fixed') {
					continue;
				}

				var cajaNodo = nodo.getBoundingClientRect();

				if (pegada(cajaNodo)) {
					techo = Math.min(techo, cajaNodo.top);
				}
			}

			/* Sin nada pegado abajo no hay nada que rematar: del control que
			 * simplemente no cabe ya se encarga el navegador. */
			if (techo >= window.innerHeight) {
				return;
			}

			var tapado = caja.bottom - techo;

			if (tapado <= 0) {
				return;
			}

			var margen = parseFloat(
				window.getComputedStyle(raiz).getPropertyValue('--akb-panel-barra-aire')
			) || 0;
			var recorrido = raiz.scrollHeight - window.innerHeight - window.scrollY;

			window.scrollBy({
				top: Math.min(Math.ceil(tapado + margen), Math.max(0, recorrido)),
				behavior: 'auto'
			});
		});
	});

	/*
	 * Contador por línea para data-akb-line-max. Informa cantidad de líneas, longitud máxima y
	 * excesos sin impedir guardar. Cuenta el texto visible, excluyendo HTML y el sufijo de enlace
	 * que no aparece en la cinta; no equivale a maxlength global.
	 */
	/*
	 * Comparte con AnnouncementBar::link_target las rutas internas, HTTPS sin distinguir caja y
	 * dominios sin esquema.
	 */
	function akbVisibleLine(line) {
		return line
			.replace(/^(.*\S)\s*\|\s*(\/(?!\/)\S*|https?:\/\/\S+|(?:[a-z0-9-]+\.)+[a-z]{2,}(?:[\/?#]\S*)?)\s*$/i, '$1')
			.replace(/<[^>]*>/g, '')
			.trim();
	}

	function akbLineCounter(value, max) {
		var lines = String(value || '').split('\n').map(function (line) {
			return akbVisibleLine(line.trim());
		}).filter(Boolean);
		var longest = lines.reduce(function (acc, line) {
			return Math.max(acc, line.length);
		}, 0);
		var over = lines.filter(function (line) {
			return line.length > max;
		}).length;
		var text = '';

		if (lines.length) {
			text = lines.length + (1 === lines.length ? ' línea' : ' líneas')
				+ ' · la más larga ' + longest + '/' + max;
			if (over) {
				text += ' · ' + over + (1 === over ? ' se corta en móvil' : ' se cortan en móvil');
			}
		}

		return { lines: lines.length, longest: longest, over: over, text: text };
	}

	if (typeof document !== 'undefined') {
		document.addEventListener('DOMContentLoaded', function () {
			Array.prototype.forEach.call(document.querySelectorAll('textarea[data-akb-line-max]'), function (area) {
				var max = parseInt(area.getAttribute('data-akb-line-max'), 10);
				var out;

				if (!max) {
					return;
				}
				out = document.createElement('p');
				out.className = 'akb-line-counter description';
				out.setAttribute('aria-live', 'polite');
				area.insertAdjacentElement('afterend', out);

				var render = function () {
					var medida = akbLineCounter(area.value, max);
					out.textContent = medida.text;
					out.classList.toggle('is-over', medida.over > 0);
				};
				area.addEventListener('input', render);
				render();
			});
		});
	}

	/*
	 * Asigna nombre accesible al input de búsqueda generado por selectWoo/Select 2: usa su
	 * placeholder y, si falta, el título del select asociado.
	 */
	function akbLabelSelect2Search(root) {
		var labelled = 0;
		Array.prototype.forEach.call(root.querySelectorAll('.select2-search__field:not([aria-label])'), function (input) {
			var name = input.getAttribute('placeholder') || '';
			var container = input.closest ? input.closest('.select2-container') : null;
			var select = container && container.previousElementSibling && 'SELECT' === container.previousElementSibling.tagName
				? container.previousElementSibling
				: null;
			var label = select && select.id ? root.querySelector('label[for="' + select.id + '"]') : null;

			if (!name && label) {
				name = (label.textContent || '').trim();
			}
			if (!name) {
				return;
			}
			input.setAttribute('aria-label', name);
			labelled += 1;
		});

		return labelled;
	}

	if (typeof document !== 'undefined' && typeof MutationObserver !== 'undefined') {
		document.addEventListener('DOMContentLoaded', function () {
			var panel = document.querySelector('.akb-panel');

			if (!panel) {
				return;
			}
			akbLabelSelect2Search(panel);
			// selectWoo monta su <input> después de DOMContentLoaded y lo vuelve
			// a crear al abrir el desplegable: se vigila el árbol, no un instante.
			new MutationObserver(function () {
				akbLabelSelect2Search(panel);
			}).observe(panel, { childList: true, subtree: true });
		});
	}

	/* Buscador rápido de ajustes y precarga predictiva en hover (0ms lag). */
	if (typeof document !== 'undefined') {
		document.addEventListener('DOMContentLoaded', function () {
			var searchInput = document.getElementById('akb-panel-search-input');
			var nav = document.querySelector('.akb-panel-nav');

			if (searchInput) {
				var links = document.querySelectorAll('.akb-panel-nav__link');
				var groups = document.querySelectorAll('.akb-panel-nav__group');

				searchInput.addEventListener('input', function () {
					var query = (searchInput.value || '').toLowerCase().trim();

					Array.prototype.forEach.call(links, function (link) {
						var text = (link.textContent || '').toLowerCase();
						var tab = (link.getAttribute('data-tab') || '').toLowerCase();
						var match = !query || text.indexOf(query) !== -1 || tab.indexOf(query) !== -1;
						link.classList.toggle('is-hidden', !match);
					});

					Array.prototype.forEach.call(groups, function (group) {
						var groupName = group.getAttribute('data-nav-group');
						if (!groupName) {
							return;
						}
						var visibleInGroup = false;
						Array.prototype.forEach.call(links, function (l) {
							if (l.getAttribute('data-nav-group') === groupName && !l.classList.contains('is-hidden')) {
								visibleInGroup = true;
							}
						});
						group.classList.toggle('is-hidden', !visibleInGroup);
					});
				});

				searchInput.addEventListener('keydown', function (e) {
					if (e.key === 'Escape') {
						searchInput.value = '';
						searchInput.dispatchEvent(new Event('input'));
						searchInput.blur();
					}
				});
			}

			if (nav) {
				// Precarga predictiva en hover para que el cambio de tab sea instantáneo
				var prefetched = new Set();
				nav.addEventListener('pointerenter', function (e) {
					var link = e.target && e.target.closest ? e.target.closest('.akb-panel-nav__link') : null;
					if (!link || !link.href || prefetched.has(link.href) || link.href === window.location.href) {
						return;
					}
					prefetched.add(link.href);
					var pre = document.createElement('link');
					pre.rel = 'prefetch';
					pre.href = link.href;
					document.head.appendChild(pre);
				}, true);
			}
		});
	}

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = { lineCounter: akbLineCounter, labelSelect2Search: akbLabelSelect2Search };
	}
}());
