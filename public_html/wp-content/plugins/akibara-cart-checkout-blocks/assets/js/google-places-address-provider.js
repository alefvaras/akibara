(function () {
	'use strict';

	const config = window.akbCartCheckoutBlocksGoogle;
	if (!config?.loaderUrl || !config?.providerId) {
		return;
	}

	let placesLibraryPromise;
	let autocompleteSessionToken;
	let autocompleteSessionTouchedAt = 0;
	let searchErrorAnnounced = false;
	const predictions = new Map();
	const feedbackStates = new WeakMap();
	const suggestionPositionStates = new WeakMap();
	let feedbackId = 0;
	const autocompleteSessionIdleMs = 3 * 60 * 1000;
	const googleMapsReadyRetryMs = 25;
	const googleMapsReadyMaxAttempts = 80;
	const chileRegionAliases = Object.freeze({
		'arica y parinacota': 'AP',
		'tarapaca': 'TA',
		'antofagasta': 'AN',
		'atacama': 'AT',
		'coquimbo': 'CO',
		'valparaiso': 'VS',
		'metropolitana': 'RM',
		'metropolitana de santiago': 'RM',
		'libertador general bernardo ohiggins': 'LI',
		'libertador bernardo ohiggins': 'LI',
		'ohiggins': 'LI',
		'maule': 'ML',
		'nuble': 'NB',
		'biobio': 'BI',
		'la araucania': 'AR',
		'araucania': 'AR',
		'los rios': 'LR',
		'los lagos': 'LL',
		'aysen del general carlos ibanez del campo': 'AI',
		'aysen': 'AI',
		'magallanes y de la antartica chilena': 'MA',
		'magallanes y antartica chilena': 'MA',
		'magallanes': 'MA'
	});
	const comboboxSelector = '[role="combobox"][aria-autocomplete="list"]';
	const autocompleteContainerSelector = '.wc-block-components-address-autocomplete-container';
	const suggestionsSelector = '.wc-block-components-address-autocomplete-suggestions';
	const suggestionsListSelector = '.suggestions-list';
	const suggestionsDropupClass = 'akb-address-autocomplete-dropup';
	const suggestionsConstrainedClass = 'akb-address-autocomplete-constrained';
	// Con la lista de Woo abierta, el aviso se anuncia pero no ocupa sitio: la
	// lista cuelga del contenedor (top: 100%) y un aviso en el flujo la bajaba
	// 43 px justo cuando el cliente iba a tocar una sugerencia, y el toque
	// completaba la de ARRIBA. Mientras busca, la lupa del campo gira.
	const feedbackListOpenClass = 'akb-address-autocomplete-feedback--list-open';
	const containerBusyClass = 'akb-address-autocomplete-busy';
	const suggestionsListMaxHeightProperty = '--akb-address-autocomplete-list-max-height';
	const suggestionsPanelGap = 4;
	const suggestionsViewportGap = 8;
	const suggestionsPositionMaxFrames = 12;
	const suggestionsBlurCleanupMs = 250;
	/* Las búsquedas de dirección se agrupan hasta que el cliente deja de teclear; se descartan las que aún no se enviaron. La primera búsqueda sale inmediatamente para anticipar la inicialización de Google mientras continúa la escritura. */
	const searchDebounceMs = 250;
	const pendingSearches = new Map();
	let firstSearchSent = false;
	// Un dedo (o el botón del ratón) sobre la lista de sugerencias. Woo reemplaza
	// la lista EN EL MISMO SITIO cuando llega una búsqueda, y el click que llega
	// al levantar el dedo cae en la sugerencia nueva de esa posición, no en la
	// que el cliente tocó: se completaba otra dirección (revisión R-B, ronda 2).
	// Con el dedo el foco no se mueve hasta que se levanta, así que no basta con
	// mirar el foco. Mientras dura la pulsación ninguna búsqueda contesta; al
	// soltar, si no eligió nada, contesta la vigente. El click llega poco después
	// del pointerup; si no llega (arrastró fuera, pulsación larga), se suelta igual.
	const suggestionPressReleaseMs = 350;
	let suggestionPress = null;
	// Mientras el proveedor le pasa a Woo su propio Escape (ver releaseHighlight).
	let releasingHighlight = false;

	function autocompleteContainer(input) {
		if (!input || typeof input.matches !== 'function' || !input.matches(comboboxSelector)) {
			return null;
		}

		return typeof input.closest === 'function'
			? input.closest(autocompleteContainerSelector)
			: null;
	}

	function findSearchCombobox(query) {
		const normalizedQuery = String(query || '').trim();
		const activeInput = document.activeElement;
		if (
			autocompleteContainer(activeInput)
			&& String(activeInput.value || '').trim() === normalizedQuery
		) {
			return activeInput;
		}

		if (typeof document.querySelectorAll !== 'function') {
			return null;
		}

		const candidates = Array.from(
			document.querySelectorAll(autocompleteContainerSelector + ' ' + comboboxSelector)
		).filter(function (input) {
			return String(input.value || '').trim() === normalizedQuery;
		});

		return candidates.length === 1 ? candidates[0] : null;
	}

	function resetSuggestionsPositionStyles(container) {
		if (!container) {
			return;
		}

		container.classList.remove(suggestionsDropupClass, suggestionsConstrainedClass);
		container.style.removeProperty(suggestionsListMaxHeightProperty);
	}

	function stopSuggestionsPosition(input) {
		const state = suggestionPositionStates.get(input);
		if (!state) {
			return;
		}

		state.active = false;
		if (state.frameId !== null) {
			if (state.frameType === 'animation') {
				window.cancelAnimationFrame?.(state.frameId);
			} else {
				window.clearTimeout?.(state.frameId);
			}
			state.frameId = null;
		}
		window.removeEventListener?.('resize', state.onViewportChange);
		window.removeEventListener?.('scroll', state.onViewportChange, true);
		state.visualViewport?.removeEventListener('resize', state.onViewportChange);
		state.visualViewport?.removeEventListener('scroll', state.onViewportChange);
		resetSuggestionsPositionStyles(state.container);
		suggestionPositionStates.delete(input);
	}

	function rectHeight(rect) {
		const height = Number(rect?.height);
		if (Number.isFinite(height) && height > 0) {
			return height;
		}

		return Math.max(0, Number(rect?.bottom || 0) - Number(rect?.top || 0));
	}

	function positionSuggestions(state) {
		if (!state.active || !state.input.isConnected) {
			stopSuggestionsPosition(state.input);
			return true;
		}

		const nextContainer = autocompleteContainer(state.input);
		if (!nextContainer) {
			stopSuggestionsPosition(state.input);
			return true;
		}
		if (state.container !== nextContainer) {
			resetSuggestionsPositionStyles(state.container);
			state.container = nextContainer;
		}

		const panel = state.container.querySelector(suggestionsSelector);
		const list = panel?.querySelector(suggestionsListSelector);
		if (!panel || !list || typeof panel.getBoundingClientRect !== 'function') {
			return false;
		}

		// Always measure the natural panel before deciding. Removing and restoring
		// these styles within one animation frame avoids accumulating a stale
		// constraint after a resize or a checkout-column layout change.
		resetSuggestionsPositionStyles(state.container);
		const inputRect = state.input.getBoundingClientRect();
		const panelRect = panel.getBoundingClientRect();
		const listRect = list.getBoundingClientRect();
		const visualViewportHeight = Number(window.visualViewport?.height);
		const layoutViewportHeight = Number(window.innerHeight)
			|| Number(document.documentElement?.clientHeight)
			|| 0;
		const viewportHeight = Number.isFinite(visualViewportHeight) && visualViewportHeight > 0
			? visualViewportHeight
			: layoutViewportHeight;
		const visualViewportOffsetTop = Number(window.visualViewport?.offsetTop);
		const viewportTop = Number.isFinite(visualViewportOffsetTop)
			? visualViewportOffsetTop
			: 0;
		const viewportBottom = viewportTop + viewportHeight;
		const panelHeight = rectHeight(panelRect);
		const listHeight = rectHeight(listRect);
		if (viewportHeight <= 0 || panelHeight <= 0 || listHeight <= 0) {
			return false;
		}

		const fixedPanelHeight = Math.max(0, panelHeight - listHeight);
		const availableBelow = Math.max(
			0,
			viewportBottom - Number(inputRect.bottom || 0) - suggestionsPanelGap - suggestionsViewportGap
		);
		const availableAbove = Math.max(
			0,
			Number(inputRect.top || 0) - viewportTop - suggestionsPanelGap - suggestionsViewportGap
		);
		const opensAbove = panelHeight > availableBelow && availableAbove > availableBelow;
		const availableHeight = opensAbove ? availableAbove : availableBelow;

		if (opensAbove) {
			state.container.classList.add(suggestionsDropupClass);
		}
		if (panelHeight > availableHeight) {
			const listMaxHeight = Math.max(0, Math.floor(availableHeight - fixedPanelHeight));
			state.container.classList.add(suggestionsConstrainedClass);
			state.container.style.setProperty(
				suggestionsListMaxHeightProperty,
				listMaxHeight + 'px'
			);
		}

		return true;
	}

	function scheduleSuggestionsPosition(state, resetFrames) {
		if (!state.active) {
			return;
		}
		if (resetFrames) {
			state.framesRemaining = suggestionsPositionMaxFrames;
		}
		if (state.frameId !== null || state.framesRemaining <= 0) {
			return;
		}

		const onFrame = function () {
			state.frameId = null;
			state.framesRemaining -= 1;
			const positioned = positionSuggestions(state);
			if (!positioned && state.active && state.framesRemaining > 0) {
				scheduleSuggestionsPosition(state, false);
			}
		};

		if (typeof window.requestAnimationFrame === 'function') {
			state.frameType = 'animation';
			state.frameId = window.requestAnimationFrame(onFrame);
			return;
		}
		state.frameType = 'timeout';
		state.frameId = window.setTimeout(onFrame, 0);
	}

	function startSuggestionsPosition(input) {
		const container = autocompleteContainer(input);
		if (!container) {
			return;
		}

		stopSuggestionsPosition(input);
		const state = {
			active: true,
			container: container,
			frameId: null,
			frameType: '',
			framesRemaining: suggestionsPositionMaxFrames,
			input: input,
			onViewportChange: null,
			visualViewport: window.visualViewport || null
		};
		state.onViewportChange = function () {
			scheduleSuggestionsPosition(state, true);
		};
		suggestionPositionStates.set(input, state);
		window.addEventListener?.('resize', state.onViewportChange, { passive: true });
		window.addEventListener?.('scroll', state.onViewportChange, { capture: true, passive: true });
		state.visualViewport?.addEventListener('resize', state.onViewportChange, { passive: true });
		state.visualViewport?.addEventListener('scroll', state.onViewportChange, { passive: true });
		scheduleSuggestionsPosition(state, true);
	}

	function removeFeedbackDescription(input, feedback) {
		const describedBy = String(input.getAttribute('aria-describedby') || '')
			.split(/\s+/)
			.filter(function (id) {
				return id && id !== feedback.getAttribute('id');
			});

		if (describedBy.length) {
			input.setAttribute('aria-describedby', describedBy.join(' '));
			return;
		}
		input.removeAttribute('aria-describedby');
	}

	function addFeedbackDescription(input, feedback) {
		const ids = String(input.getAttribute('aria-describedby') || '')
			.split(/\s+/)
			.filter(Boolean);
		const feedbackIdentifier = feedback.getAttribute('id');
		if (!ids.includes(feedbackIdentifier)) {
			ids.push(feedbackIdentifier);
			input.setAttribute('aria-describedby', ids.join(' '));
		}
	}

	function ensureFeedbackAttached(state) {
		const nextContainer = autocompleteContainer(state.input);
		if (!nextContainer) {
			return false;
		}

		if (state.container !== nextContainer) {
			state.container.classList.remove(containerBusyClass);
			state.container = nextContainer;
			if (state.observer) {
				state.observer.disconnect();
				state.observer.observe(nextContainer, { childList: true });
			}
		}

		const suggestions = typeof state.container.querySelector === 'function'
			? state.container.querySelector(suggestionsSelector)
			: null;
		const children = Array.from(state.container.children || []);
		const feedbackIndex = children.indexOf(state.feedback);
		const suggestionsIndex = children.indexOf(suggestions);
		if (suggestions && (feedbackIndex < 0 || feedbackIndex > suggestionsIndex)) {
			state.container.insertBefore(state.feedback, suggestions);
		} else if (!state.container.contains(state.feedback)) {
			state.container.appendChild(state.feedback);
		}
		return true;
	}

	// Se decide cada vez que Woo abre o cierra la lista (el observador del
	// contenedor): un «No encontramos direcciones» que llega con la lista vieja
	// aún abierta se ve en cuanto Woo la cierra.
	function syncFeedbackWithList(state) {
		const listOpen = typeof state.container.querySelector === 'function'
			&& Boolean(state.container.querySelector(suggestionsSelector));
		if (listOpen) {
			state.feedback.classList.add(feedbackListOpenClass);
		} else {
			state.feedback.classList.remove(feedbackListOpenClass);
		}
		if (listOpen && state.tone === 'loading') {
			state.container.classList.add(containerBusyClass);
		} else {
			state.container.classList.remove(containerBusyClass);
		}
	}

	function observeFeedback(state) {
		if (state.observer || typeof window.MutationObserver !== 'function') {
			return;
		}

		state.observer = new window.MutationObserver(function () {
			if (!state.visible) {
				return;
			}
			if (!state.input.isConnected) {
				clearFeedback(state.input, state.token);
				return;
			}
			if (ensureFeedbackAttached(state)) {
				syncFeedbackWithList(state);
			}
		});
		// Sólo los hijos directos del contenedor: ahí vive el aviso y ahí lo
		// quitaría un render de Woo. Mirar todo document.body con subtree hacía
		// correr este callback con cada render del checkout mientras se buscaba.
		state.observer.observe(state.container, { childList: true });
	}

	function clearFeedback(input, expectedToken, preserveSuggestionsPosition) {
		const state = feedbackStates.get(input);
		if (!state || (expectedToken !== undefined && state.token !== expectedToken)) {
			return;
		}

		state.token += 1;
		state.query = '';
		if (!state.visible && state.input.getAttribute('aria-busy') === null) {
			// Ya está limpio: nada que escribir en el DOM en esta tecla.
			if (!preserveSuggestionsPosition) {
				stopSuggestionsPosition(input);
			}
			return;
		}
		state.visible = false;
		state.tone = '';
		state.container.classList.remove(containerBusyClass);
		state.feedback.hidden = true;
		state.feedback.className = 'akb-address-autocomplete-feedback';
		state.feedback.textContent = '';
		state.feedback.removeAttribute('role');
		state.feedback.removeAttribute('aria-live');
		state.input.removeAttribute('aria-busy');
		removeFeedbackDescription(state.input, state.feedback);
		state.observer?.disconnect();
		state.observer = null;
		if (!preserveSuggestionsPosition) {
			stopSuggestionsPosition(input);
		}
	}

	function feedbackState(input) {
		let state = feedbackStates.get(input);
		if (state) {
			return state;
		}

		const container = autocompleteContainer(input);
		if (!container || typeof document.createElement !== 'function') {
			return null;
		}

		const feedback = document.createElement('div');
		feedbackId += 1;
		feedback.className = 'akb-address-autocomplete-feedback';
		feedback.hidden = true;
		feedback.setAttribute('id', 'akb-address-autocomplete-feedback-' + feedbackId);
		feedback.setAttribute('aria-atomic', 'true');
		container.appendChild(feedback);

		state = {
			container: container,
			feedback: feedback,
			input: input,
			// 'search' o 'selection': qué pidió el aviso vigente (Escape sólo
			// descarta búsquedas; el «Completando dirección…» sigue).
			kind: '',
			observer: null,
			query: '',
			token: 0,
			tone: '',
			visible: false
		};
		feedbackStates.set(input, state);
		// Lo que el cliente teclea sobre un resaltado lo deja sin efecto, y hay
		// que soltarlo ANTES de que cambie el texto: con menos de tres
		// caracteres Woo cierra la lista sin soltarlo, y sin lista ya no atiende
		// el Escape. Chromium lo soltaba por el blur que dispara Woo al
		// reinsertar el campo; WebKit no, y la lista siguiente abría con esa
		// posición resaltada (RV-FB-01). En el evento input sería tarde: el
		// re-render del Escape devuelve el campo al texto anterior.
		input.addEventListener('beforeinput', function () {
			releaseHighlight(input);
		}, { passive: true });
		input.addEventListener('input', function () {
			// Quien teclea no tiene el dedo en la lista: si un toque quedó sin
			// cerrar (el navegador no avisó del final), no retiene más búsquedas.
			if (suggestionPress && suggestionPress.combobox === input) {
				endSuggestionPress(suggestionPress);
			}
			// Las sugerencias abiertas siguen donde están mientras el cliente
			// teclea: Woo no las cambia hasta que llega la búsqueda nueva, y
			// recolocarlas en cada tecla era un reflow por tecla. Sin lista
			// abierta, o con menos de tres caracteres (Woo la cierra), se suelta.
			clearFeedback(
				input,
				undefined,
				String(input.value || '').trim().length >= 3
					&& Boolean(autocompleteContainer(input)?.querySelector(suggestionsSelector))
			);
		}, { passive: true });
		input.addEventListener('keydown', function (event) {
			// El Escape con el que el proveedor suelta el resaltado de Woo
			// (releaseHighlight) no es del cliente: no descarta nada.
			if (event.key !== 'Escape' || releasingHighlight) {
				return;
			}
			// Woo cierra la lista con Escape pero no descarta la búsqueda: la
			// pendiente de la última tecla, o la que ya está en Google, la volvía
			// a abrir al contestar. Se descartan las dos (la en vuelo, al cambiar
			// el token del aviso: runSearch deja de ser la vigente).
			cancelPendingSearch(input);
			if (state.kind === 'search') {
				clearFeedback(input);
				return;
			}
			stopSuggestionsPosition(input);
		}, { passive: true });
		input.addEventListener('blur', function () {
			const blurredToken = state.token;
			window.setTimeout(function () {
				if (document.activeElement === input) {
					return;
				}

				// In WebKit a pointerdown can blur the combobox before click. Invalidate
				// pending feedback now, but keep a dropup stationary until Woo's 200ms
				// blur window has either dispatched the option click or closed the panel.
				clearFeedback(input, blurredToken, true);
				window.setTimeout(function () {
					const container = autocompleteContainer(input);
					const panel = container?.querySelector(suggestionsSelector);
					if (document.activeElement !== input || !panel) {
						stopSuggestionsPosition(input);
					}
				}, suggestionsBlurCleanupMs);
			}, 0);
		}, { passive: true });

		return state;
	}

	function renderFeedback(state, tone, message) {
		if (!ensureFeedbackAttached(state)) {
			return;
		}

		state.visible = true;
		state.tone = tone;
		state.feedback.hidden = false;
		state.feedback.className = 'akb-address-autocomplete-feedback akb-address-autocomplete-feedback--' + tone;
		state.feedback.setAttribute('role', tone === 'error' ? 'alert' : 'status');
		state.feedback.setAttribute('aria-live', tone === 'error' ? 'assertive' : 'polite');
		state.feedback.textContent = message;
		syncFeedbackWithList(state);
		addFeedbackDescription(state.input, state.feedback);
		observeFeedback(state);
	}

	function beginFeedback(input, query, message, kind) {
		const state = feedbackState(input);
		if (!state) {
			return null;
		}

		state.token += 1;
		state.kind = kind;
		state.query = String(query || '').trim();
		state.input.setAttribute('aria-busy', 'true');
		renderFeedback(state, 'loading', message);

		return {
			input: input,
			query: state.query,
			token: state.token
		};
	}

	function isCurrentFeedback(context) {
		if (!context) {
			return false;
		}

		const state = feedbackStates.get(context.input);
		return Boolean(
			state
			&& state.token === context.token
			&& state.query === context.query
			&& String(context.input.value || '').trim() === context.query
		);
	}

	function finishSearchFeedback(context, outcome) {
		if (!isCurrentFeedback(context)) {
			return;
		}

		const state = feedbackStates.get(context.input);
		state.input.removeAttribute('aria-busy');
		stopSuggestionsPosition(context.input);
		if (outcome === 'success') {
			clearFeedback(context.input, context.token);
			return;
		}
		if (outcome === 'empty') {
			renderFeedback(
				state,
				'empty',
				'No encontramos direcciones. Revisa lo escrito o puedes escribirla manualmente.'
			);
			return;
		}

		renderFeedback(
			state,
			'error',
			'No pudimos buscar direcciones. Revisa tu conexión e inténtalo de nuevo, o puedes escribir la dirección manualmente.'
		);
	}

	function finishSelectionFeedback(context, succeeded) {
		if (!isCurrentFeedback(context)) {
			return;
		}

		const state = feedbackStates.get(context.input);
		state.input.removeAttribute('aria-busy');
		stopSuggestionsPosition(context.input);
		if (succeeded) {
			clearFeedback(context.input, context.token);
			return;
		}

		renderFeedback(
			state,
			'error',
			'No pudimos completar esa dirección. Inténtalo de nuevo o puedes escribir la dirección manualmente.'
		);
	}

	function importPlacesLibraryWhenReady(attempt) {
		if (typeof window.google?.maps?.importLibrary === 'function') {
			return window.google.maps.importLibrary('places');
		}

		if (attempt >= googleMapsReadyMaxAttempts) {
			return Promise.reject(new Error('Google Places is unavailable.'));
		}

		return new Promise(function (resolve, reject) {
			window.setTimeout(function () {
				importPlacesLibraryWhenReady(attempt + 1).then(resolve, reject);
			}, googleMapsReadyRetryMs);
		});
	}

	function loadPlacesLibrary() {
		if (placesLibraryPromise) {
			return placesLibraryPromise;
		}

		placesLibraryPromise = new Promise(function (resolve, reject) {
			if (window.google?.maps?.importLibrary) {
				importPlacesLibraryWhenReady(0).then(resolve, reject);
				return;
			}

			const script = document.createElement('script');
			script.src = config.loaderUrl;
			script.async = true;
			script.onload = function () {
				importPlacesLibraryWhenReady(0).then(resolve, reject);
			};
			script.onerror = function () {
				reject(new Error('Google Places could not be loaded.'));
			};
			document.head.appendChild(script);
		});
		placesLibraryPromise = placesLibraryPromise.catch(function (error) {
			placesLibraryPromise = undefined;
			throw error;
		});

		return placesLibraryPromise;
	}

	function normalizeRegionName(value) {
		return String(value || '')
			.normalize('NFD')
			.replace(/[\u0300-\u036f]/g, '')
			.toLowerCase()
			.replace(/[’']/g, '')
			.replace(/[^a-z0-9]+/g, ' ')
			.trim()
			.replace(/^region(?: de| del)? /, '');
	}

	function regionCode(component) {
		const regionMap = config.regionMap || {};
		const configuredCodes = new Set(Object.values(regionMap));
		const names = [
			component?.shortText,
			component?.short_name,
			component?.longText,
			component?.long_name
		].filter(Boolean);

		for (const name of names) {
			const configuredKey = String(name).trim().toUpperCase();
			if (regionMap[configuredKey]) {
				return regionMap[configuredKey];
			}
			if (configuredCodes.has(name)) {
				return name;
			}

			const alias = chileRegionAliases[normalizeRegionName(name)];
			if (alias && regionMap[alias]) {
				return regionMap[alias];
			}
		}

		return '';
	}

	function formattableTextValue(value) {
		if (!value) {
			return '';
		}

		if (typeof value.toString === 'function') {
			try {
				const text = value.toString();
				if (text && text !== '[object Object]') {
					return String(text);
				}
			} catch {
				// Fall through to legacy response shapes.
			}
		}

		return value.text || '';
	}

	function predictionLabel(prediction, input) {
		return formattableTextValue(prediction?.text)
			|| formattableTextValue(prediction?.mainText)
			|| String(input);
	}

	function normalizeCommuneName(value) {
		return String(value || '')
			.normalize('NFD')
			.replace(/[\u0300-\u036f]/g, '')
			.toLowerCase()
			.replace(/[^a-z0-9]+/g, ' ')
			.trim();
	}

	function communeName(candidates, regionCode) {
		const regionCommunes = window.akbComunaData?.regions?.[regionCode];
		if (!Array.isArray(regionCommunes) || !regionCommunes.length) {
			return '';
		}

		const canonicalNames = new Map();
		regionCommunes.forEach(function (name) {
			const normalized = normalizeCommuneName(name);
			if (normalized && !canonicalNames.has(normalized)) {
				canonicalNames.set(normalized, name);
			}
		});

		const province = normalizeCommuneName(candidates.administrativeAreaLevel2);
		const matches = [];
		const seen = new Set();
		[
			candidates.administrativeAreaLevel3,
			candidates.locality,
			candidates.sublocality
		].forEach(function (candidate) {
			const normalized = normalizeCommuneName(candidate);
			if (!normalized || seen.has(normalized) || !canonicalNames.has(normalized)) {
				return;
			}

			seen.add(normalized);
			matches.push({
				name: canonicalNames.get(normalized),
				normalized: normalized
			});
		});

		const distinctFromProvince = matches.filter(function (match) {
			return !province || match.normalized !== province;
		});
		const resolved = distinctFromProvince.length ? distinctFromProvince : matches;
		return resolved.length === 1 ? resolved[0].name : '';
	}

	function parseAddressComponents(components) {
		const values = {
			address_1: '',
			address_2: '',
			city: '',
			country: 'CL',
			postcode: '',
			state: ''
		};
		const parts = {};
		const cityCandidates = {
			administrativeAreaLevel2: '',
			administrativeAreaLevel3: '',
			locality: '',
			sublocality: ''
		};

		(components || []).forEach(function (component) {
			const types = component.types || [];
			const longName = component.longText || component.long_name || '';
			if (types.includes('street_number')) {
				parts.streetNumber = longName;
			}
			if (types.includes('route')) {
				parts.route = longName;
			}
			if (types.includes('subpremise')) {
				values.address_2 = longName;
			}
			if (types.includes('administrative_area_level_3')) {
				cityCandidates.administrativeAreaLevel3 = longName;
			}
			if (types.includes('locality')) {
				cityCandidates.locality = longName;
			}
			if (types.includes('sublocality') || types.includes('sublocality_level_1')) {
				cityCandidates.sublocality = longName;
			}
			if (types.includes('administrative_area_level_2')) {
				cityCandidates.administrativeAreaLevel2 = longName;
			}
			if (types.includes('administrative_area_level_1')) {
				values.state = regionCode(component);
			}
			if (types.includes('postal_code')) {
				values.postcode = longName;
			}
			if (types.includes('country')) {
				values.country = component.shortText || component.short_name || values.country;
			}
		});

		values.city = communeName(cityCandidates, values.state);
		values.address_1 = [parts.route, parts.streetNumber].filter(Boolean).join(' ');

		/* Google no debe borrar un departamento ni un código postal ya escritos cuando la predicción no trae esos datos. Los valores vacíos se omiten. Comuna y región sí se vacían deliberadamente si el catálogo no las reconoce, para que el cliente elija una opción válida. */
		['address_2', 'postcode'].forEach(function (key) {
			if (values[key] === '') {
				delete values[key];
			}
		});

		return values;
	}

	function rememberPrediction(id, prediction, input) {
		predictions.set(id, { input: input, prediction: prediction });
		if (predictions.size <= 50) {
			return;
		}
		predictions.delete(predictions.keys().next().value);
	}

	function getAutocompleteSessionToken(places) {
		const now = Date.now();
		// Tras tres minutos sin buscar, la búsqueda siguiente abre otra sesión de
		// Google. Sólo cambia el token: la lista vieja puede seguir en pantalla
		// (bajo el dedo, o elegida con Enter antes de que conteste esta búsqueda)
		// y sus predicciones tienen que seguir completando la dirección.
		if (
			autocompleteSessionToken
			&& autocompleteSessionTouchedAt
			&& now - autocompleteSessionTouchedAt >= autocompleteSessionIdleMs
		) {
			autocompleteSessionToken = undefined;
		}

		if (!autocompleteSessionToken && typeof places.AutocompleteSessionToken === 'function') {
			autocompleteSessionToken = new places.AutocompleteSessionToken();
		}
		autocompleteSessionTouchedAt = now;
		return autocompleteSessionToken;
	}

	function resetAutocompleteSession() {
		autocompleteSessionToken = undefined;
		autocompleteSessionTouchedAt = 0;
		predictions.clear();
	}

	function showErrorNotice(message, id) {
		const notices = window.wp?.data?.dispatch?.('core/notices');
		if (typeof notices?.createErrorNotice !== 'function') {
			return;
		}

		notices.createErrorNotice(
			message,
			{
				context: 'woocommerce/checkout',
				id: id,
				type: 'snackbar'
			}
		);
	}

	function showSearchError() {
		if (searchErrorAnnounced) {
			return;
		}

		searchErrorAnnounced = true;
		showErrorNotice(
			'No pudimos buscar direcciones en este momento. Puedes escribirla manualmente y continuar.',
			'akibara-google-places-search'
		);
	}

	function showSelectionError() {
		showErrorNotice(
			'No pudimos completar esa dirección. Puedes intentarlo nuevamente o escribirla manualmente.',
			'akibara-google-places-selection'
		);
	}

	// Con el ratón, el mousedown sobre una sugerencia ya le quitó el foco al
	// campo (el <li> es enfocable) y el click llega al soltar. Si en ese rato
	// Woo recibe [], desmonta la lista y el clic se pierde: la dirección no se
	// completa. Si el foco está en la lista, esa búsqueda no contesta nunca:
	// Woo no bloquea nada esperándola y, en cuanto el cliente vuelve a teclear,
	// su contador sólo atiende la búsqueda nueva.
	function focusIsOnSuggestions(combobox) {
		const panel = autocompleteContainer(combobox)?.querySelector(suggestionsSelector);
		const active = document.activeElement;
		return Boolean(panel && active && active !== combobox && panel.contains(active));
	}

	// Una búsqueda que no contesta nunca: Woo no espera nada de ella y, con la
	// próxima tecla, su contador sólo atiende la búsqueda nueva.
	function neverAnswered() {
		return new Promise(function () {});
	}

	function unansweredWhilePressingSuggestion(combobox) {
		return focusIsOnSuggestions(combobox) ? neverAnswered() : [];
	}

	function suggestionsComboboxFor(target) {
		const panel = typeof target?.closest === 'function' ? target.closest(suggestionsSelector) : null;
		const container = typeof panel?.closest === 'function'
			? panel.closest(autocompleteContainerSelector)
			: null;
		return container?.querySelector(comboboxSelector) || null;
	}

	function startSuggestionPress(event) {
		const combobox = suggestionsComboboxFor(event.target);
		if (!combobox) {
			return;
		}
		if (suggestionPress && suggestionPress.combobox !== combobox) {
			endSuggestionPress(suggestionPress);
		}
		if (!suggestionPress) {
			suggestionPress = { combobox: combobox, selected: false, timer: null, waiters: [] };
		}
		window.clearTimeout(suggestionPress.timer);
		suggestionPress.timer = null;
	}

	function scheduleSuggestionPressEnd(delay) {
		const press = suggestionPress;
		if (!press) {
			return;
		}
		window.clearTimeout(press.timer);
		press.timer = window.setTimeout(function () {
			endSuggestionPress(press);
		}, delay);
	}

	function endSuggestionPress(press) {
		if (suggestionPress !== press) {
			return;
		}
		window.clearTimeout(press.timer);
		suggestionPress = null;
		press.waiters.splice(0).forEach(function (waiter) {
			waiter(!press.selected);
		});
	}

	function isPressingSuggestion(combobox) {
		return Boolean(combobox && suggestionPress && suggestionPress.combobox === combobox);
	}

	// Cuando el cliente suelta: true si no eligió ninguna sugerencia (la búsqueda
	// sigue en pie); false si el toque completó una (la búsqueda ya no importa).
	function suggestionPressEnded(combobox) {
		return new Promise(function (resolve) {
			if (!isPressingSuggestion(combobox)) {
				resolve(true);
				return;
			}
			suggestionPress.waiters.push(resolve);
		});
	}

	// Con las flechas Woo resalta una POSICIÓN de la lista (aria-activedescendant)
	// y la conserva cuando le llega una lista nueva: Enter completaría la
	// dirección nueva de ese sitio, y el lector de pantalla no anuncia nada
	// porque el id resaltado es el mismo. Antes de entregarle una respuesta, y
	// antes de que el cliente cambie el texto (beforeinput), se suelta el
	// resaltado con el Escape de Woo, que vacía la lista y deja el índice en
	// -1: la lista de lo escrito abre sin nada resaltado. Va cancelado
	// para que nadie más lo tome por un Escape del cliente (el menú del tema no
	// atiende un Escape cancelado).
	function releaseHighlight(combobox) {
		if (
			!combobox.getAttribute('aria-activedescendant')
			|| !autocompleteContainer(combobox)?.querySelector(suggestionsSelector)
		) {
			return;
		}
		const escape = new window.KeyboardEvent('keydown', { bubbles: true, cancelable: true, key: 'Escape' });
		escape.preventDefault();
		releasingHighlight = true;
		combobox.dispatchEvent(escape);
		releasingHighlight = false;
	}

	// Entrega a Woo lo que contestó Google. Con un dedo sobre la lista, espera a
	// que lo levante: si eligió una sugerencia, ya no entrega nada.
	// Con el campo en pantalla sólo llega aquí la búsqueda vigente: runSearch()
	// comprobó la vigencia tras contestar Google (o fallar), y aquí se vuelve a
	// comprobar tras la única espera, la del dedo. De ahí al final no hay otro await.
	async function deliverSearch(searchFeedback, outcome, results) {
		if (searchFeedback && isPressingSuggestion(searchFeedback.input)) {
			const stillSearching = await suggestionPressEnded(searchFeedback.input);
			if (!stillSearching) {
				return neverAnswered();
			}
			if (!isCurrentFeedback(searchFeedback)) {
				return unansweredWhilePressingSuggestion(searchFeedback.input);
			}
		}

		finishSearchFeedback(searchFeedback, outcome);
		if (searchFeedback) {
			releaseHighlight(searchFeedback.input);
		}
		if (outcome === 'error') {
			resetAutocompleteSession();
			if (!searchFeedback) {
				showSearchError();
			}
			return [];
		}
		if (searchFeedback && results.length) {
			startSuggestionsPosition(searchFeedback.input);
		}
		return results;
	}

	async function runSearch(query, combobox) {
		const searchFeedback = beginFeedback(combobox, query, 'Buscando direcciones…', 'search');
		if (searchFeedback) {
			startSuggestionsPosition(searchFeedback.input);
		}

		let outcome = 'error';
		let results = [];
		try {
			const places = await loadPlacesLibrary();
			if (!places.AutocompleteSuggestion?.fetchAutocompleteSuggestions) {
				finishSearchFeedback(searchFeedback, 'error');
				if (!searchFeedback) {
					showSearchError();
				}
				return [];
			}

			const request = {
				input: query,
				includedRegionCodes: ['cl'],
				language: 'es'
			};
			const sessionToken = getAutocompleteSessionToken(places);
			if (sessionToken) {
				request.sessionToken = sessionToken;
			}

			const response = await places.AutocompleteSuggestion.fetchAutocompleteSuggestions(request);
			if (searchFeedback && !isCurrentFeedback(searchFeedback)) {
				return unansweredWhilePressingSuggestion(searchFeedback.input);
			}
			searchErrorAnnounced = false;

			results = (response.suggestions || []).map(function (suggestion, index) {
				const prediction = suggestion.placePrediction;
				if (!prediction) {
					return null;
				}

				const id = String(prediction.placeId || [query, index, Date.now()].join(':'));
				rememberPrediction(id, prediction, searchFeedback?.input || null);
				return {
					id: id,
					label: predictionLabel(prediction, query),
					matchedSubstrings: []
				};
			}).filter(Boolean);
			outcome = results.length ? 'success' : 'empty';
		} catch (error) {
			if (searchFeedback && !isCurrentFeedback(searchFeedback)) {
				return unansweredWhilePressingSuggestion(searchFeedback.input);
			}
		}

		return deliverSearch(searchFeedback, outcome, results);
	}

	function cancelPendingSearch(combobox) {
		const pending = pendingSearches.get(combobox);
		if (!pending) {
			return;
		}

		pendingSearches.delete(combobox);
		window.clearTimeout(pending.timer);
		pending.resolve([]);
	}

	const provider = {
		id: config.providerId,
		canSearch: function (country) {
			return String(country || '').toUpperCase() === 'CL';
		},
		search: function (input, country) {
			const query = String(input || '').trim();
			const combobox = findSearchCombobox(query);
			cancelPendingSearch(combobox);
			if (!provider.canSearch(country) || query.length < 3) {
				if (combobox) {
					clearFeedback(combobox);
				}
				resetAutocompleteSession();
				return Promise.resolve([]);
			}

			// Nada de DOM ni de red en la tecla: sólo un temporizador. Toda búsqueda
			// que la reemplaza pasa antes por cancelPendingSearch(), que limpia este
			// temporizador y resuelve su promesa: si llega a dispararse, es la vigente.
			const wasFocused = Boolean(combobox) && document.activeElement === combobox;
			return new Promise(function (resolve) {
				const pending = { resolve: resolve, timer: null };
				const fire = function () {
					if (isPressingSuggestion(combobox)) {
						// Vence a mitad de un toque sobre la lista: espera a que suelte.
						// Si mientras tanto la despachó select() o una tecla nueva
						// (cancelPendingSearch), ya contestó.
						suggestionPressEnded(combobox).then(function (stillSearching) {
							if (pendingSearches.get(combobox) !== pending) {
								return;
							}
							if (!stillSearching) {
								pendingSearches.delete(combobox);
								return;
							}
							fire();
						});
						return;
					}
					pendingSearches.delete(combobox);
					// Si el cliente borró hasta quedar bajo tres caracteres (Woo ya
					// no llama) o se fue del campo, esta búsqueda ya no tiene a quién
					// mostrarle nada.
					if (combobox && String(combobox.value || '').trim() !== query) {
						resolve([]);
						return;
					}
					if (combobox && wasFocused && document.activeElement !== combobox) {
						// Si se fue a la lista, está pulsando una sugerencia: nada.
						if (!focusIsOnSuggestions(combobox)) {
							resolve([]);
						}
						return;
					}
					firstSearchSent = true;
					resolve(runSearch(query, combobox));
				};
				pending.timer = window.setTimeout(fire, firstSearchSent ? searchDebounceMs : 0);
				pendingSearches.set(combobox, pending);
			});
		},
		select: async function (id) {
			// El toque en curso eligió: lo que conteste Google ya no se entrega.
			if (suggestionPress) {
				suggestionPress.selected = true;
			}
			const remembered = predictions.get(String(id));
			if (!remembered) {
				return {};
			}
			if (remembered.input) {
				// Una búsqueda pendiente de la última tecla pisaría el «Completando
				// dirección…» con un «Buscando direcciones…» que ya nadie pidió.
				cancelPendingSearch(remembered.input);
				stopSuggestionsPosition(remembered.input);
			}
			const selectionFeedback = remembered.input
				? beginFeedback(
					remembered.input,
					String(remembered.input.value || '').trim(),
					'Completando dirección…',
					'selection'
				)
				: null;

			try {
				const place = remembered.prediction.toPlace();
				await place.fetchFields({ fields: ['addressComponents'] });
				finishSelectionFeedback(selectionFeedback, true);
				return parseAddressComponents(place.addressComponents);
			} catch (error) {
				finishSelectionFeedback(selectionFeedback, false);
				if (!selectionFeedback) {
					showSelectionError();
				}
				return {};
			} finally {
				resetAutocompleteSession();
			}
		}
	};

	function registerProvider(attempt) {
		const autocomplete = window.wc?.addressAutocomplete;
		const hasServerProvider = autocomplete?.serverProviders?.some(function (serverProvider) {
			return serverProvider?.id === config.providerId;
		});

		if (hasServerProvider && typeof autocomplete?.registerAddressAutocompleteProvider === 'function') {
			if (!autocomplete.providers?.[config.providerId]) {
				autocomplete.registerAddressAutocompleteProvider(provider);
			}
			return;
		}

		if (attempt < 200) {
			window.setTimeout(function () {
				registerProvider(attempt + 1);
			}, 25);
		}
	}

	registerProvider(0);

	// El SDK de Maps se pide cuando el cliente entra al campo de dirección, no en
	// la primera búsqueda: así la descarga corre mientras escribe las primeras
	// letras y la primera sugerencia no espera además al SDK. Quien nunca entra
	// al campo (por ejemplo, con la dirección ya guardada) no lo descarga.
	if (typeof document.addEventListener === 'function') {
		document.addEventListener('focusin', function (event) {
			if (autocompleteContainer(event.target)) {
				loadPlacesLibrary().catch(function () {
					// La búsqueda lo reintenta y avisa si sigue sin cargar.
				});
			}
		}, { passive: true });

		// La pulsación sobre la lista (ver suggestionPress), en captura: Woo
		// re-renderiza la lista y el contenedor, el documento no cambia. Con el
		// dedo llegan pointer y touch; con el ratón, sólo pointer.
		const pressListenerOptions = { capture: true, passive: true };
		['pointerdown', 'touchstart'].forEach(function (type) {
			document.addEventListener(type, startSuggestionPress, pressListenerOptions);
		});
		['pointerup', 'touchend'].forEach(function (type) {
			document.addEventListener(type, function () {
				scheduleSuggestionPressEnd(suggestionPressReleaseMs);
			}, pressListenerOptions);
		});
		// El click (y con él select()) corre entero antes que este temporizador;
		// un scroll que se lleva el toque (cancel) no trae click.
		['click', 'pointercancel', 'touchcancel'].forEach(function (type) {
			document.addEventListener(type, function () {
				scheduleSuggestionPressEnd(0);
			}, pressListenerOptions);
		});
	}
})();
