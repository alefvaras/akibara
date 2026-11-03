(function (wp) {
	'use strict';

	var config = window.akbComunaData;
	var PREFIXES = ['shipping', 'billing'];
	var OPEN_CLASS = 'akb-comuna-listbox--open';
	var ABOVE_CLASS = 'akb-comuna-listbox--above';
	var ACTIVE_CLASS = 'akb-comuna-option--active';

	// El mismo hueco que el CSS deja entre el campo y la lista, y un respiro
	// contra el borde del viewport para que la lista no quede pegada al canto.
	var LISTBOX_GAP = 4;
	var VIEWPORT_GAP = 8;
	// Tope de diseño: el mismo max-height de comuna-combobox.css.
	var LISTBOX_MAX_HEIGHT = 264;
	// Suelo: con menos que esto la lista deja de ser una lista. Si ni arriba ni
	// abajo hay tanto, preferimos que asome antes que servir una rendija.
	var LISTBOX_MIN_HEIGHT = 120;

	/**
	 * Pure helpers exposed for the Node test harness. Everything that decides
	 * (normalization, ranking, stale-value policy) lives here without DOM.
	 */
	var helpers = {
		normalize: normalize,
		buildModel: buildModel,
		rank: rank,
		shouldClearCity: shouldClearCity,
		placeListbox: placeListbox,
		comunaIssue: comunaIssue
	};
	window.akbComunaCombobox = helpers;

	function normalize(value) {
		var text = String(value || '');

		if (typeof text.normalize === 'function') {
			text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		}

		return text
			.toLowerCase()
			.replace(/\s+/g, ' ')
			.replace(/^[\s.,;'"]+|[\s.,;'"]+$/g, '');
	}

	function buildModel(regions) {
		var entries = [];

		Object.keys(regions || {}).forEach(function (regionCode) {
			var names = regions[regionCode];
			if (!Array.isArray(names)) {
				return;
			}

			names.forEach(function (name) {
				if (typeof name !== 'string' || name === '') {
					return;
				}

				entries.push({
					name: name,
					norm: normalize(name),
					region: regionCode
				});
			});
		});

		entries.sort(function (a, b) {
			return a.norm < b.norm ? -1 : a.norm > b.norm ? 1 : 0;
		});

		return entries;
	}

	/**
	 * Filters and ranks entries for a query within an optional region.
	 * Prefix matches come before substring matches; both keep alphabetical
	 * order. An empty query returns the full (region) list.
	 */
	function rank(entries, query, regionCode) {
		var q = normalize(query);
		var starts = [];
		var contains = [];

		entries.forEach(function (entry) {
			if (regionCode && entry.region !== regionCode) {
				return;
			}

			if (q === '') {
				starts.push(entry);
				return;
			}

			var index = entry.norm.indexOf(q);
			if (index === 0) {
				starts.push(entry);
			} else if (index > 0) {
				contains.push(entry);
			}
		});

		return starts.concat(contains);
	}

	/**
	 * Classic-select parity: when the region changes, a commune that does not
	 * exist in the new region is stale and must be cleared. Communes are
	 * globally unique, so "exists in new region" is a plain name check.
	 */
	function shouldClearCity(entries, cityValue, newRegionCode) {
		var norm = normalize(cityValue);
		if (norm === '' || !newRegionCode) {
			return false;
		}

		var known = entries.some(function (entry) {
			return entry.norm === norm;
		});
		if (!known) {
			return false;
		}

		return !entries.some(function (entry) {
			return entry.norm === norm && entry.region === newRegionCode;
		});
	}

	/** Valida la comuna escrita con el mismo contrato que CheckoutFields::validate_chilean_comuna. No autocorrige tildes ni mayúsculas: el servidor las canoniza al actualizar el cliente.
 *
 * @param {Array} entries Modelo de comunas.
 * @param {string} cityValue Lo que escribió la persona.
 * @param {string} regionCode Región seleccionada (vacío = ninguna).
 * @return {{kind:string,name:string,suggestion:(string|null)}|null} null si no hay nada que avisar. */
	function comunaIssue(entries, cityValue, regionCode) {
		var norm = normalize(cityValue);
		if (norm === '') {
			// El «obligatorio» es de Blocks, no nuestro.
			return null;
		}

		var exact = null;
		var inRegion = false;
		entries.forEach(function (entry) {
			if (entry.norm !== norm) {
				return;
			}

			exact = exact || entry;
			if (entry.region === regionCode) {
				inRegion = true;
			}
		});

		if (exact) {
			if (regionCode === '' || inRegion) {
				return null;
			}

			return { kind: 'region', name: exact.name, suggestion: null };
		}

		// Sugerencia por contención en cualquiera de los dos sentidos: cubre
		// «Santiago Centro» (la comuna es prefijo de lo escrito) y «Providen»
		// (lo escrito es prefijo de la comuna). Se queda con la más larga, que
		// es la que más texto explica.
		var best = null;
		entries.forEach(function (entry) {
			if (regionCode !== '' && entry.region !== regionCode) {
				return;
			}

			if (norm.indexOf(entry.norm) !== 0 && entry.norm.indexOf(norm) !== 0) {
				return;
			}

			if (!best || entry.norm.length > best.norm.length) {
				best = entry;
			}
		});

		return {
			kind: 'unknown',
			name: String(cityValue || ''),
			suggestion: best ? best.name : null
		};
	}

	/** Decide la dirección de apertura y el alto de la lista según el espacio disponible en el viewport, incluido el teclado. Función pura comprobable sin DOM.
 *
 * @param {{inputTop:number,inputBottom:number,viewportTop:number,viewportHeight:number,naturalHeight:number}} m Medidas en coordenadas de cliente.
 * @return {{above:boolean,maxHeight:(number|null)}} maxHeight null si cabe con el tope de diseño. */
	function placeListbox(m) {
		var viewportHeight = Number(m && m.viewportHeight);
		var naturalHeight = Number(m && m.naturalHeight);
		if (!(viewportHeight > 0) || !(naturalHeight > 0)) {
			return { above: false, maxHeight: null };
		}

		var viewportTop = Number(m.viewportTop) || 0;
		var viewportBottom = viewportTop + viewportHeight;
		var below = Math.max(0, viewportBottom - Number(m.inputBottom || 0) - LISTBOX_GAP - VIEWPORT_GAP);
		var above = Math.max(0, Number(m.inputTop || 0) - viewportTop - LISTBOX_GAP - VIEWPORT_GAP);
		var wanted = Math.min(naturalHeight, LISTBOX_MAX_HEIGHT);
		var opensAbove = wanted > below && above > below;
		var available = opensAbove ? above : below;

		return {
			above: opensAbove,
			maxHeight: wanted > available
				? Math.max(LISTBOX_MIN_HEIGHT, Math.floor(available))
				: null
		};
	}

	if (!config || !config.regions || typeof document === 'undefined') {
		return;
	}

	var model = buildModel(config.regions);
	if (!model.length) {
		return;
	}

	var regionLabels = config.regionLabels || {};
	var strings = config.strings || {};
	var scanQueued = false;

	function setNativeValue(element, value) {
		var prototype = window.HTMLSelectElement && element instanceof window.HTMLSelectElement
			? window.HTMLSelectElement.prototype
			: window.HTMLInputElement && window.HTMLInputElement.prototype;
		var descriptor = prototype && Object.getOwnPropertyDescriptor(prototype, 'value');

		if (descriptor && descriptor.set) {
			descriptor.set.call(element, value);
		} else {
			element.value = value;
		}

		element.dispatchEvent(new Event('input', { bubbles: true }));
		element.dispatchEvent(new Event('change', { bubbles: true }));
	}

	/**
	 * Aplica placeListbox() sobre la lista ya renderizada.
	 *
	 * Siempre parte del estado natural (sin clase ni max-height inline): si se
	 * midiera sobre la restricción anterior, la lista se iría encogiendo a cada
	 * render hasta el suelo.
	 */
	function positionListbox(input, listbox) {
		listbox.classList.remove(ABOVE_CLASS);
		listbox.style.maxHeight = '';

		if (typeof input.getBoundingClientRect !== 'function') {
			return;
		}

		var rect = input.getBoundingClientRect();
		var visual = window.visualViewport;
		var visualHeight = visual ? Number(visual.height) : NaN;
		var viewportHeight = isFinite(visualHeight) && visualHeight > 0
			? visualHeight
			: (window.innerHeight
				|| (document.documentElement && document.documentElement.clientHeight)
				|| 0);
		var visualOffset = visual ? Number(visual.offsetTop) : NaN;

		var placement = placeListbox({
			inputTop: rect.top,
			inputBottom: rect.bottom,
			// offsetTop del visual viewport: con el teclado abierto la banda
			// visible no empieza en 0 dentro del viewport de maquetación.
			viewportTop: isFinite(visualOffset) ? visualOffset : 0,
			viewportHeight: viewportHeight,
			naturalHeight: listbox.scrollHeight
		});

		if (placement.above) {
			listbox.classList.add(ABOVE_CLASS);
		}
		if (null !== placement.maxHeight) {
			listbox.style.maxHeight = placement.maxHeight + 'px';
		}
	}

	/**
	 * Recoloca las listas abiertas cuando cambia el viewport.
	 *
	 * El teclado móvil aparece DESPUÉS del foco: si sólo se midiera al abrir,
	 * la decisión se tomaría con la geometría de antes del teclado.
	 */
	function repositionOpenListboxes() {
		PREFIXES.forEach(function (prefix) {
			var listbox = document.getElementById('akb-comuna-listbox-' + prefix);
			var input = document.getElementById(prefix + '-city');
			if (listbox && input && listbox.classList.contains(OPEN_CLASS)) {
				positionListbox(input, listbox);
			}
		});
	}

	function validationStore() {
		try {
			if (wp && wp.data && typeof wp.data.dispatch === 'function') {
				return wp.data.dispatch('wc/store/validation') || null;
			}
		} catch (error) {
			// Sin store no hay aviso en cliente; el servidor sigue siendo el juez.
		}

		return null;
	}

	function clearComunaError(prefix) {
		var store = validationStore();
		if (store && typeof store.clearValidationError === 'function') {
			store.clearValidationError(prefix + '_city');
		}
	}

	/** Publica el error de comuna inexistente o ajena a la región en la clave nativa billing_city o shipping_city. El mensaje queda anclado al campo con aria-describedby. */
	function validateComunaField(prefix) {
		var input = document.getElementById(prefix + '-city');
		if (!input || input.dataset.akbComunaEnhanced !== '1') {
			return null;
		}

		if (countryFor(prefix) !== 'CL') {
			// Cambiar de país no puede dejar colgado un aviso sobre comunas
			// chilenas bloqueando el pedido.
			clearComunaError(prefix);
			return null;
		}

		// Campo vacío: el error de esa clave es de Blocks («El campo comuna no
		// es válido»), no nuestro. Limpiarlo sería quitarle a Blocks su propio
		// «obligatorio», que vive en la MISMA clave del store.
		if (normalize(input.value) === '') {
			return null;
		}

		var issue = comunaIssue(model, input.value, currentRegion(prefix));
		if (!issue) {
			clearComunaError(prefix);
			return null;
		}

		var store = validationStore();
		if (!store || typeof store.setValidationErrors !== 'function') {
			return issue;
		}

		var message = issue.kind === 'region'
			? '"' + issue.name + '" no pertenece a la región seleccionada. Revisa la región o corrige la comuna.'
			: (issue.suggestion
				? 'No encontramos la comuna "' + issue.name + '". ¿Quisiste decir "' + issue.suggestion + '"?'
				: 'No encontramos la comuna "' + issue.name + '". Selecciónala de la lista de comunas.');

		var errors = {};
		errors[prefix + '_city'] = { message: message, hidden: false };
		store.setValidationErrors(errors);

		return issue;
	}

	function stateSelectFor(prefix) {
		var select = document.getElementById(prefix + '-state');
		return select && select.tagName === 'SELECT' ? select : null;
	}

	function currentRegion(prefix) {
		var select = stateSelectFor(prefix);
		return select ? select.value : '';
	}

	function countryFor(prefix) {
		var country = document.getElementById(prefix + '-country');
		return country && 'value' in country ? country.value : 'CL';
	}

	function enhance(input, prefix) {
		if (!input || input.dataset.akbComunaEnhanced === '1') {
			return;
		}

		input.dataset.akbComunaEnhanced = '1';

		var listboxId = 'akb-comuna-listbox-' + prefix;
		var listbox = document.createElement('div');
		listbox.id = listboxId;
		listbox.className = 'akb-comuna-listbox';
		listbox.setAttribute('role', 'listbox');
		listbox.setAttribute('aria-label', strings.listLabel || 'Comunas sugeridas');

		var live = document.createElement('div');
		live.className = 'akb-comuna-live screen-reader-text';
		live.setAttribute('aria-live', 'polite');

		var parent = input.parentElement;
		if (!parent) {
			return;
		}

		parent.classList.add('akb-comuna-host');
		parent.appendChild(listbox);
		parent.appendChild(live);

		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('aria-controls', listboxId);

		var state = {
			open: false,
			options: [],
			activeIndex: -1
		};

		function announce(count) {
			var template = strings.resultCount || '%d comunas disponibles';
			live.textContent = template.replace('%d', String(count));
		}

		function close() {
			if (!state.open) {
				return;
			}

			state.open = false;
			state.activeIndex = -1;
			listbox.classList.remove(OPEN_CLASS);
			listbox.classList.remove(ABOVE_CLASS);
			listbox.style.maxHeight = '';
			listbox.innerHTML = '';
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
		}

		function setActive(index) {
			var options = listbox.querySelectorAll('.akb-comuna-option');
			if (!options.length) {
				return;
			}

			var bounded = Math.max(0, Math.min(index, options.length - 1));
			if (state.activeIndex >= 0 && options[state.activeIndex]) {
				options[state.activeIndex].classList.remove(ACTIVE_CLASS);
				options[state.activeIndex].setAttribute('aria-selected', 'false');
			}

			state.activeIndex = bounded;
			var option = options[bounded];
			option.classList.add(ACTIVE_CLASS);
			option.setAttribute('aria-selected', 'true');
			input.setAttribute('aria-activedescendant', option.id);

			if (typeof option.scrollIntoView === 'function') {
				option.scrollIntoView({ block: 'nearest' });
			}
		}

		function select(entry) {
			setNativeValue(input, entry.name);
			clearComunaError(prefix);

			var region = currentRegion(prefix);
			if (entry.region !== region) {
				var stateSelect = stateSelectFor(prefix);
				if (stateSelect) {
					setNativeValue(stateSelect, entry.region);
				}
			}

			close();
			input.focus();
		}

		function render() {
			// Never open a list for an input the user is not interacting
			// with (e.g. a programmatic clear after a region change).
			if (document.activeElement !== input || countryFor(prefix) !== 'CL') {
				close();
				return;
			}

			var region = currentRegion(prefix);
			var results = rank(model, input.value, region);
			var showRegionHint = region === '';

			state.options = results;
			listbox.innerHTML = '';

			if (!results.length) {
				var empty = document.createElement('div');
				empty.className = 'akb-comuna-empty';
				empty.textContent = strings.noMatches || 'Sin coincidencias en esta región';
				listbox.appendChild(empty);
			}

			var fragment = document.createDocumentFragment();
			results.forEach(function (entry, index) {
				var option = document.createElement('div');
				option.className = 'akb-comuna-option';
				option.id = listboxId + '-option-' + index;
				option.setAttribute('role', 'option');
				option.setAttribute('aria-selected', 'false');
				option.dataset.index = String(index);

				var name = document.createElement('span');
				name.className = 'akb-comuna-option__name';
				name.textContent = entry.name;
				option.appendChild(name);

				if (showRegionHint) {
					var hint = document.createElement('span');
					hint.className = 'akb-comuna-option__region';
					hint.textContent = regionLabels[entry.region] || entry.region;
					option.appendChild(hint);
				}

				fragment.appendChild(option);
			});
			listbox.appendChild(fragment);

			state.open = true;
			state.activeIndex = -1;
			listbox.classList.add(OPEN_CLASS);
			// Colocar SÓLO con la lista ya visible: con display:none el
			// scrollHeight es 0 y la decisión se tomaría a ciegas.
			positionListbox(input, listbox);
			input.setAttribute('aria-expanded', 'true');
			input.removeAttribute('aria-activedescendant');
			announce(results.length);
		}

		listbox.addEventListener('mousedown', function (event) {
			// Keep focus on the input so blur does not close before click.
			event.preventDefault();
		});

		listbox.addEventListener('click', function (event) {
			var option = event.target.closest ? event.target.closest('.akb-comuna-option') : null;
			if (!option) {
				return;
			}

			var entry = state.options[Number(option.dataset.index)];
			if (entry) {
				select(entry);
			}
		});

		input.addEventListener('focus', render);
		input.addEventListener('click', function () {
			if (!state.open) {
				render();
			}
		});
		input.addEventListener('input', render);

		input.addEventListener('keydown', function (event) {
			if (event.isComposing) {
				return;
			}

			if (event.key === 'ArrowDown') {
				if (!state.open) {
					render();
				}
				setActive(state.activeIndex + 1);
				event.preventDefault();
			} else if (event.key === 'ArrowUp') {
				if (state.open) {
					setActive(state.activeIndex - 1);
					event.preventDefault();
				}
			} else if (event.key === 'Enter') {
				if (state.open && state.activeIndex >= 0 && state.options[state.activeIndex]) {
					select(state.options[state.activeIndex]);
					event.preventDefault();
				}
			} else if (event.key === 'Escape') {
				if (state.open) {
					close();
					event.stopPropagation();
				}
			} else if (event.key === 'Tab') {
				close();
			}
		});

		input.addEventListener('blur', function () {
			window.setTimeout(function () {
				if (document.activeElement !== input) {
					close();
				}
			}, 120);
		});
	}

	function handleRegionChange(prefix) {
		var input = document.getElementById(prefix + '-city');
		if (!input || input.dataset.akbComunaEnhanced !== '1') {
			return;
		}

		var region = currentRegion(prefix);
		if (shouldClearCity(model, input.value, region)) {
			setNativeValue(input, '');
		}
	}

	function scan() {
		PREFIXES.forEach(function (prefix) {
			var input = document.getElementById(prefix + '-city');
			if (input && input.tagName === 'INPUT') {
				enhance(input, prefix);
			}
		});
	}

	function queueScan() {
		if (scanQueued) {
			return;
		}

		scanQueued = true;
		window.requestAnimationFrame(function () {
			scanQueued = false;
			scan();
		});
	}

	function initialize() {
		try {
			scan();

			document.addEventListener('change', function (event) {
				var target = event.target;
				if (!target || !target.id) {
					return;
				}

				PREFIXES.forEach(function (prefix) {
					if (target.id === prefix + '-state' || target.id === prefix + '-country') {
						handleRegionChange(prefix);
						// La región manda: una comuna que ya no le pertenece
						// deja de ser un error en cuanto se limpia, y una que
						// sí le pertenece deja de estar marcada. Y cambiar de
						// país retira el aviso (lo hace validateComunaField).
						validateComunaField(prefix);
					}
				});
			});

			// A nivel de documento, como checkout-rut.js: el focusout aquí
			// corre DESPUÉS del handler de React, que si no borraría el aviso.
			document.addEventListener('focusout', function (event) {
				var target = event.target;
				if (!target || !target.id) {
					return;
				}

				PREFIXES.forEach(function (prefix) {
					if (target.id === prefix + '-city') {
						validateComunaField(prefix);
					}
				});
			});

			// Mientras se escribe el aviso estorba: se retira y se vuelve a
			// decidir al salir del campo.
			document.addEventListener('input', function (event) {
				var target = event.target;
				if (!target || !target.id) {
					return;
				}

				PREFIXES.forEach(function (prefix) {
					// Con el campo ya vacío no se toca: ese error es el
					// «obligatorio» de Blocks, en la misma clave del store.
					if (target.id === prefix + '-city' && normalize(target.value) !== '') {
						clearComunaError(prefix);
					}
				});
			});

			var observer = new MutationObserver(queueScan);
			observer.observe(document.body, { childList: true, subtree: true });

			// El teclado móvil no dispara 'resize' de window en iOS: lo que se
			// mueve es el visual viewport. Sin esto la lista se colocaría con
			// la geometría previa al teclado y volvería a quedar debajo.
			var repositionQueued = false;
			var reposition = function () {
				if (repositionQueued) {
					return;
				}

				repositionQueued = true;
				window.requestAnimationFrame(function () {
					repositionQueued = false;
					repositionOpenListboxes();
				});
			};

			window.addEventListener('resize', reposition);
			if (window.visualViewport) {
				window.visualViewport.addEventListener('resize', reposition);
				window.visualViewport.addEventListener('scroll', reposition);
			}

			if (wp && wp.data && typeof wp.data.subscribe === 'function') {
				wp.data.subscribe(queueScan);
			}
		} catch (error) {
			// The checkout must keep working with the plain text field.
			if (window.console && window.console.warn) {
				window.console.warn('akb-comuna-combobox disabled:', error);
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize, { once: true });
	} else {
		initialize();
	}
})(window.wp);
