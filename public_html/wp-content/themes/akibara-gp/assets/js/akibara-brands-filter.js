/**
 * Akibara — filtro client-side del directorio de editoriales.
 *
 * Escucha input + chips de región y muestra/oculta cards directamente en el
 * DOM, leyendo de cada tarjeta su `data-akb-brand-{slug,region,name}`. Cero
 * AJAX, cero dependencias y cero payload extra: el marcado ya trae todo lo que
 * el filtro necesita.
 *
 * Estado:
 *   - region activa (string | "all")
 *   - query (string trim+lowercase)
 *
 * Cards se identifican por:
 *   - data-akb-brand-slug
 *   - data-akb-brand-region
 *   - data-akb-brand-name (lowercase para match)
 *
 * UX:
 *   - Sections enteras se ocultan si ningún hijo matchea (evita títulos huérfanos)
 *   - Estado vivo en `#akb-brands-results` (aria-live polite)
 *   - Mensaje empty con link a /contacto/ si total visibles == 0
 *   - Debounce 80ms en el input para no thrashear el DOM
 *
 * @package akibara-gp
 */
( function () {
	'use strict';

	var ROOT_SELECTOR     = '.akb-brands-directory';
	var INPUT_SELECTOR    = '.akb-brands-filterbar__input';
	var CHIP_SELECTOR     = '.akb-brands-chip';
	var CHIP_ACTIVE_CLASS = 'akb-brands-chip--active';
	var CARD_SELECTOR     = '.akb-brand-card';
	var SECTION_SELECTOR  = '.akb-brands-section';
	var STATUS_SELECTOR      = '.akb-brands-filterbar__status';
	var STATUS_TEXT_SELECTOR  = '[data-akb-status-text]';
	var CLEAR_SELECTOR        = '[data-akb-clear-filters]';
	var EMPTY_SELECTOR        = '.akb-brands-empty';
	var DEBOUNCE_MS           = 80;
	var DEFAULT_STATUS_TEXT   = 'Busca por editorial o filtra por región.';

	function init( root ) {
		var input    = root.querySelector( INPUT_SELECTOR );
		var chips    = Array.prototype.slice.call( root.querySelectorAll( CHIP_SELECTOR ) );
		var cards    = Array.prototype.slice.call( root.querySelectorAll( CARD_SELECTOR ) );
		var sections = Array.prototype.slice.call( root.querySelectorAll( SECTION_SELECTOR ) );
		var status      = root.querySelector( STATUS_SELECTOR );
		var statusText  = root.querySelector( STATUS_TEXT_SELECTOR );
		var clearButton = root.querySelector( CLEAR_SELECTOR );
		var empty       = root.querySelector( EMPTY_SELECTOR );

		if ( ! input || cards.length === 0 ) {
			return;
		}

		function initialRegion() {
			var requested = new window.URLSearchParams( window.location.search ).get( 'region' ) || 'all';
			var valid     = chips.some( function ( chip ) {
				return ( chip.getAttribute( 'data-akb-region' ) || 'all' ) === requested;
			} );

			return valid ? requested : 'all';
		}

		function syncRegionUrl( region ) {
			if ( ! window.history || ! window.history.replaceState || ! window.URL ) {
				return;
			}

			var url = new window.URL( window.location.href );
			if ( region === 'all' ) {
				url.searchParams.delete( 'region' );
			} else {
				url.searchParams.set( 'region', region );
			}
			window.history.replaceState( null, '', url.toString() );
		}

		var state = {
			region: initialRegion(),
			query : ''
		};

		function syncChipState( region ) {
			chips.forEach( function ( chip ) {
				var isActive = ( chip.getAttribute( 'data-akb-region' ) || 'all' ) === region;

				chip.classList.toggle( CHIP_ACTIVE_CLASS, isActive );
				chip.setAttribute( 'aria-pressed', isActive ? 'true' : 'false' );
			} );
		}

		function setRegion( region, updateUrl ) {
			state.region = region;
			syncChipState( region );
			if ( updateUrl ) {
				syncRegionUrl( region );
			}
		}

		function apply() {
			var visible = 0;
			var q       = state.query;
			var isDefault = state.region === 'all' && q === '';

			cards.forEach( function ( card ) {
				var name      = card.getAttribute( 'data-akb-brand-name' ) || '';
				var region    = card.getAttribute( 'data-akb-brand-region' ) || '';
				var matchReg  = state.region === 'all' || region === state.region;
				var matchName = q === '' || name.indexOf( q ) !== -1;
				var show      = matchReg && matchName;

				card.hidden = ! show;
				if ( show ) {
					visible++;
				}
			} );

			// Ocultar secciones cuyos hijos están todos hidden — evita títulos
			// huérfanos cuando un filtro deja una región vacía.
			sections.forEach( function ( section ) {
				var anyVisible = section.querySelector(
					CARD_SELECTOR + ':not([hidden])'
				);
				section.hidden = ! anyVisible;
			} );

			// Status text. Con query y/o región distinta de 'all' damos detalle;
			// si todo es default, mostramos una guía breve para evitar una
			// caja vacía que compite con el contenido.
			if ( status ) {
				var statusMessage = isDefault
					? DEFAULT_STATUS_TEXT
					: ( visible === 1 ? '1 editorial' : visible + ' editoriales' );

				if ( statusText ) {
					statusText.textContent = statusMessage;
				} else {
					status.textContent = isDefault ? '' : statusMessage;
				}

				if ( clearButton ) {
					clearButton.hidden = isDefault;
				}
			}

			if ( empty ) {
				empty.hidden = visible !== 0;
			}
		}

		// Input con debounce — reduce thrash si owner pega texto largo.
		var debounceTimer = null;
		input.addEventListener( 'input', function () {
			window.clearTimeout( debounceTimer );
			debounceTimer = window.setTimeout( function () {
				state.query = input.value.trim().toLowerCase();
				apply();
			}, DEBOUNCE_MS );
		} );

		// Chips de región.
		chips.forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				setRegion( chip.getAttribute( 'data-akb-region' ) || 'all', true );
				apply();
			} );
		} );

		if ( clearButton ) {
			clearButton.addEventListener( 'click', function () {
				input.value = '';
				state.query = '';
				setRegion( 'all', true );
				apply();
				input.focus();
			} );
		}

		// ESC sobre el input limpia query — atajo común en buscadores.
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && input.value !== '' ) {
				input.value  = '';
				state.query  = '';
				apply();
			}
		} );

		syncChipState( state.region );
		apply();
	}

	function boot() {
		// `[data-akb-total]` marca un directorio ya renderizado por el servidor.
		// Antes se buscaba `[data-akb-brands]`, un JSON de 2,2 KB con la lista
		// completa de editoriales que este fichero NUNCA parseó: lo usaba sólo
		// como esto, un selector de presencia. El atributo se retiró.
		var roots = document.querySelectorAll( ROOT_SELECTOR + '[data-akb-total]' );
		Array.prototype.forEach.call( roots, init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
