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
 * @package
 */
( function () {
	'use strict';

	const ROOT_SELECTOR     = '.akb-brands-directory';
	const INPUT_SELECTOR    = '.akb-brands-filterbar__input';
	const CHIP_SELECTOR     = '.akb-brands-chip';
	const CHIP_ACTIVE_CLASS = 'akb-brands-chip--active';
	const CARD_SELECTOR     = '.akb-brand-card';
	const SECTION_SELECTOR  = '.akb-brands-section';
	const STATUS_SELECTOR      = '.akb-brands-filterbar__status';
	const STATUS_TEXT_SELECTOR  = '[data-akb-status-text]';
	const CLEAR_SELECTOR        = '[data-akb-clear-filters]';
	const EMPTY_SELECTOR        = '.akb-brands-empty';
	const DEBOUNCE_MS           = 80;
	const DEFAULT_STATUS_TEXT   = 'Busca por editorial o filtra por región.';

	function init( root ) {
		const input    = root.querySelector( INPUT_SELECTOR );
		const cards    = Array.prototype.slice.call( root.querySelectorAll( CARD_SELECTOR ) );

		if ( ! input || cards.length === 0 ) {
			return;
		}

		const chips    = Array.prototype.slice.call( root.querySelectorAll( CHIP_SELECTOR ) );
		const sections = Array.prototype.slice.call( root.querySelectorAll( SECTION_SELECTOR ) );
		const status      = root.querySelector( STATUS_SELECTOR );
		const statusText  = root.querySelector( STATUS_TEXT_SELECTOR );
		const clearButton = root.querySelector( CLEAR_SELECTOR );
		const empty       = root.querySelector( EMPTY_SELECTOR );

		function initialRegion() {
			const requested = new window.URLSearchParams( window.location.search ).get( 'region' ) || 'all';
			const valid     = chips.some( function ( chip ) {
				return ( chip.getAttribute( 'data-akb-region' ) || 'all' ) === requested;
			} );

			return valid ? requested : 'all';
		}

		function syncRegionUrl( region ) {
			if ( ! window.history || ! window.history.replaceState || ! window.URL ) {
				return;
			}

			const url = new window.URL( window.location.href );
			if ( region === 'all' ) {
				url.searchParams.delete( 'region' );
			} else {
				url.searchParams.set( 'region', region );
			}
			window.history.replaceState( null, '', url.toString() );
		}

		const state = {
			region: initialRegion(),
			query : ''
		};

		function syncChipState( region ) {
			chips.forEach( function ( chip ) {
				const isActive = ( chip.getAttribute( 'data-akb-region' ) || 'all' ) === region;

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
			let visible = 0;
			const q       = state.query;
			const isDefault = state.region === 'all' && q === '';

			cards.forEach( function ( card ) {
				const name      = card.getAttribute( 'data-akb-brand-name' ) || '';
				const region    = card.getAttribute( 'data-akb-brand-region' ) || '';
				const matchReg  = state.region === 'all' || region === state.region;
				const matchName = q === '' || name.indexOf( q ) !== -1;
				const show      = matchReg && matchName;

				card.hidden = ! show;
				if ( show ) {
					visible++;
				}
			} );

			// Ocultar secciones cuyos hijos están todos hidden — evita títulos
			// huérfanos cuando un filtro deja una región vacía.
			sections.forEach( function ( section ) {
				const anyVisible = section.querySelector(
					CARD_SELECTOR + ':not([hidden])'
				);
				section.hidden = ! anyVisible;
			} );

			// Status text. Con query y/o región distinta de 'all' damos detalle;
			// si todo es default, mostramos una guía breve para evitar una
			// caja vacía que compite con el contenido.
			if ( status ) {
				const countLabel = visible === 1 ? '1 editorial' : visible + ' editoriales';
				const statusMessage = isDefault ? DEFAULT_STATUS_TEXT : countLabel;

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
		let debounceTimer = null;
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
		const roots = document.querySelectorAll( ROOT_SELECTOR + '[data-akb-total]' );
		Array.prototype.forEach.call( roots, init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
