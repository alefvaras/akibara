/* Filtro en cliente del directorio de series.
 *
 * Las entradas ya vienen en el HTML: buscar no necesita red. El estado visible
 * se refleja en `?buscar=` con `history.replaceState()`, de modo que volver desde
 * una serie restaura la consulta sin crear una entrada de historial por tecla.
 * Los filtros rápidos por país, editorial y estado viven en `history.state` para
 * no abrir variantes de URL fuera de `?buscar=`. El fragmento alfabético se
 * conserva siempre.
 */
( function () {
	'use strict';

	const QUERY_PARAM = 'buscar';
	const root = document.querySelector( '[data-akb-series-directory]' );
	if ( ! root ) {
		return;
	}

	const filter = root.querySelector( '[data-akb-series-filter]' );
	const input = root.querySelector( '.akb-series-directory__input' );
	const items = Array.prototype.slice.call( root.querySelectorAll( '[data-akb-serie]' ) );

	if ( ! filter || ! input || ! items.length ) {
		return;
	}

	const count = root.querySelector( '.akb-series-directory__count' );
	const empty = root.querySelector( '[data-akb-series-empty]' );
	const groups = Array.prototype.slice.call( root.querySelectorAll( '[data-akb-series-group]' ) );
	const letters = Array.prototype.slice.call( root.querySelectorAll( '[data-akb-series-letter]' ) );
	const chipsPais = Array.prototype.slice.call( root.querySelectorAll( '[data-akb-filter-pais]' ) );
	const chipsEditorial = Array.prototype.slice.call( root.querySelectorAll( '[data-akb-filter-editorial]' ) );
	const chipsEstado = Array.prototype.slice.call( root.querySelectorAll( '[data-akb-filter-estado]' ) );
	const toggleFacetsBtn = root.querySelector( '[data-akb-toggle-facets]' );
	const facetsPanel = root.querySelector( '[data-akb-facets-panel]' );
	const filterBadge = root.querySelector( '[data-akb-filter-count]' );
	const quickChips = Array.prototype.slice.call( root.querySelectorAll( '[data-akb-quick-estado], [data-akb-quick-editorial]' ) );

	const total = items.length;
	let activePais = '';
	let activeEditorial = '';
	let activeEstado = '';

	// El buscador sólo existe si este script corrió: revelarlo acá es lo que
	// impide prometer una función que no está.
	filter.removeAttribute( 'hidden' );

	/**
	 * Minúsculas sin diacríticos, con degradación para motores sin normalize.
	 * @param {*} value
	 */
	function fold( value ) {
		let folded = String( value ).toLowerCase();
		if ( 'function' === typeof folded.normalize ) {
			folded = folded.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
		}
		return folded.trim();
	}

	/*
	 * El PHP compone el texto y el formato local de miles en los data-attrs. Sustituir sólo el
	 * número visible y conservar la frase del servidor.
	 */
	const labelAll = count ? count.getAttribute( 'data-akb-series-count-all' ) : '';
	const labelFiltered = count ? count.getAttribute( 'data-akb-series-count-filtered' ) : '';
	const locale = ( count && count.getAttribute( 'data-akb-series-locale' ) ) || 'es-CL';

	/**
	 * Agrupa miles con el locale del sitio; degrada al entero pelado.
	 * @param {*} value
	 */
	function formatCount( value ) {
		try {
			return new Intl.NumberFormat( locale ).format( value );
		} catch ( _error ) {
			return String( value );
		}
	}

	function hasActiveFilter() {
		return '' !== fold( input.value ) || '' !== activePais || '' !== activeEditorial || '' !== activeEstado;
	}

	function label( shown ) {
		if ( shown === total && ! hasActiveFilter() ) {
			return labelAll;
		}

		return labelFiltered.replace( '%s', formatCount( shown ) );
	}

	/**
	 * Sincroniza el estado visual y accesible de un grupo de chips.
	 * @param {*} chips
	 * @param {*} attr
	 * @param {*} activeValue
	 */
	function syncChips( chips, attr, activeValue ) {
		chips.forEach( function ( chip ) {
			const val = chip.getAttribute( attr ) || '';
			const isActive = val === activeValue;
			if ( isActive ) {
				chip.classList.add( 'is-active' );
				chip.setAttribute( 'aria-pressed', 'true' );
			} else {
				chip.classList.remove( 'is-active' );
				chip.setAttribute( 'aria-pressed', 'false' );
			}
		} );
	}

	function syncAllChips() {
		syncChips( chipsPais, 'data-akb-filter-pais', activePais );
		syncChips( chipsEditorial, 'data-akb-filter-editorial', activeEditorial );
		syncChips( chipsEstado, 'data-akb-filter-estado', activeEstado );

		quickChips.forEach( function ( qChip ) {
			const qEstado = qChip.getAttribute( 'data-akb-quick-estado' );
			const qEditorial = qChip.getAttribute( 'data-akb-quick-editorial' );
			let isQActive = false;
			if ( null !== qEstado ) {
				isQActive = ( qEstado === activeEstado );
			} else if ( null !== qEditorial ) {
				isQActive = ( qEditorial === activeEditorial );
			}
			if ( isQActive ) {
				qChip.classList.add( 'is-active' );
				qChip.setAttribute( 'aria-pressed', 'true' );
			} else {
				qChip.classList.remove( 'is-active' );
				qChip.setAttribute( 'aria-pressed', 'false' );
			}
		} );

		let activeCount = 0;
		if ( '' !== activePais ) {
			activeCount++;
		}
		if ( '' !== activeEditorial ) {
			activeCount++;
		}
		if ( '' !== activeEstado ) {
			activeCount++;
		}

		if ( filterBadge ) {
			if ( activeCount > 0 ) {
				filterBadge.textContent = String( activeCount );
				filterBadge.removeAttribute( 'hidden' );
			} else {
				filterBadge.setAttribute( 'hidden', '' );
			}
		}

		if ( toggleFacetsBtn ) {
			if ( activeCount > 0 ) {
				toggleFacetsBtn.classList.add( 'has-active-filters' );
			} else {
				toggleFacetsBtn.classList.remove( 'has-active-filters' );
			}
		}
	}

	/** Lee la consulta compartible; una URL inválida degrada a búsqueda vacía. */
	function queryFromUrl() {
		try {
			return new URL( window.location.href ).searchParams.get( QUERY_PARAM ) || '';
		} catch ( _error ) {
			return '';
		}
	}

	/**
	 * Sustituye sólo el parámetro del filtro y guarda las facetas en history.state.
	 * URL conserva `hash`, y replaceState evita que teclear «one piece» obligue a
	 * pulsar Atrás nueve veces.
	 */
	function syncUrl() {
		if ( ! window.history || 'function' !== typeof window.history.replaceState ) {
			return;
		}

		try {
			const url = new URL( window.location.href );
			const value = input.value.trim();

			if ( '' === value ) {
				url.searchParams.delete( QUERY_PARAM );
			} else {
				url.searchParams.set( QUERY_PARAM, value );
			}

			const prevState = ( window.history.state && 'object' === typeof window.history.state )
				? window.history.state
				: {};
			const nextState = Object.assign( {}, prevState, {
				akbSeriesPais: activePais,
				akbSeriesEditorial: activeEditorial,
				akbSeriesEstado: activeEstado
			} );

			window.history.replaceState(
				nextState,
				'',
				url.pathname + url.search + url.hash
			);
		} catch ( _error ) {
			// La búsqueda sigue funcionando aunque un navegador bloquee History API.
		}
	}

	/** Enlaces que siguen disponibles después del filtro, en orden de lectura. */
	function visibleLinks() {
		const links = [];

		items.forEach( function ( item ) {
			if ( item.hasAttribute( 'hidden' ) ) {
				return;
			}

			const link = item.querySelector( '.akb-series-directory__link' );
			if ( link ) {
				links.push( link );
			}
		} );

		return links;
	}

	/**
	 * Aplica filtro, grupos, estado vivo y opcionalmente la URL.
	 * @param {*} updateUrl
	 */
	function apply( updateUrl ) {
		const query = fold( input.value );
		let shown = 0;

		syncAllChips();

		items.forEach( function ( item ) {
			const haystack = item.getAttribute( 'data-akb-serie' ) || '';
			const itemPais = item.getAttribute( 'data-akb-pais' ) || '';
			const itemEditorial = item.getAttribute( 'data-akb-editorial' ) || '';
			const itemEstado = item.getAttribute( 'data-akb-estado' ) || '';

			const matchQuery = '' === query || -1 !== haystack.indexOf( query );
			const matchPais = '' === activePais || itemPais === activePais;
			const matchEditorial = '' === activeEditorial || itemEditorial === activeEditorial;
			const matchEstado = '' === activeEstado || itemEstado === activeEstado;

			// `hidden` también saca lo descartado del árbol de accesibilidad.
			if ( matchQuery && matchPais && matchEditorial && matchEstado ) {
				item.removeAttribute( 'hidden' );
				shown++;
			} else {
				item.setAttribute( 'hidden', '' );
			}
		} );

		groups.forEach( function ( group ) {
			const visible = group.querySelector( '[data-akb-serie]:not([hidden])' );
			if ( visible ) {
				group.removeAttribute( 'hidden' );
			} else {
				group.setAttribute( 'hidden', '' );
			}
		} );

		// Sin plantillas no se toca el nodo: dejar el texto del servidor es
		// mejor que vaciar el contador.
		if ( count && labelAll && labelFiltered ) {
			count.textContent = label( shown );
		}
		if ( empty ) {
			if ( 0 === shown ) {
				empty.removeAttribute( 'hidden' );
			} else {
				empty.setAttribute( 'hidden', '' );
			}
		}

		if ( updateUrl ) {
			syncUrl();
		}
	}

	/** La URL y history.state son la fuente de verdad al cargar, volver y avanzar. */
	function restoreFromUrl() {
		input.value = queryFromUrl();
		const state = ( window.history && window.history.state && 'object' === typeof window.history.state )
			? window.history.state
			: {};
		activePais = state.akbSeriesPais || '';
		activeEditorial = state.akbSeriesEditorial || '';
		activeEstado = state.akbSeriesEstado || '';
		apply( false );
	}

	function bindChipGroup( chips, attr, getCurrent, setCurrent ) {
		chips.forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				const clicked = chip.getAttribute( attr ) || '';
				// Clic sobre un filtro activo distinto de «Todos/Todas» lo desactiva.
				const next = ( '' !== clicked && clicked === getCurrent() ) ? '' : clicked;
				setCurrent( next );
				apply( true );
			} );
		} );
	}

	bindChipGroup(
		chipsPais,
		'data-akb-filter-pais',
		function () { return activePais; },
		function ( val ) { activePais = val; }
	);
	bindChipGroup(
		chipsEditorial,
		'data-akb-filter-editorial',
		function () { return activeEditorial; },
		function ( val ) { activeEditorial = val; }
	);
	bindChipGroup(
		chipsEstado,
		'data-akb-filter-estado',
		function () { return activeEstado; },
		function ( val ) { activeEstado = val; }
	);

	if ( toggleFacetsBtn && facetsPanel ) {
		toggleFacetsBtn.addEventListener( 'click', function () {
			const isExpanded = toggleFacetsBtn.getAttribute( 'aria-expanded' ) === 'true';
			if ( isExpanded ) {
				toggleFacetsBtn.setAttribute( 'aria-expanded', 'false' );
				facetsPanel.setAttribute( 'hidden', '' );
			} else {
				toggleFacetsBtn.setAttribute( 'aria-expanded', 'true' );
				facetsPanel.removeAttribute( 'hidden' );
			}
		} );
	}

	quickChips.forEach( function ( qChip ) {
		qChip.addEventListener( 'click', function () {
			const qEstado = qChip.getAttribute( 'data-akb-quick-estado' );
			const qEditorial = qChip.getAttribute( 'data-akb-quick-editorial' );
			if ( null !== qEstado ) {
				activeEstado = ( activeEstado === qEstado ) ? '' : qEstado;
			} else if ( null !== qEditorial ) {
				activeEditorial = ( activeEditorial === qEditorial ) ? '' : qEditorial;
			}
			apply( true );
		} );
	} );

	input.addEventListener( 'input', function () {
		apply( true );
	} );

	// Safari emite `search` al usar el botón nativo para limpiar el campo.
	input.addEventListener( 'search', function () {
		apply( true );
	} );

	input.addEventListener( 'keydown', function ( event ) {
		if ( event.isComposing ) {
			return;
		}

		if ( 'Escape' === event.key && hasActiveFilter() ) {
			event.preventDefault();
			input.value = '';
			activePais = '';
			activeEditorial = '';
			activeEstado = '';
			apply( true );
			return;
		}

		const links = visibleLinks();
		if ( 'ArrowDown' === event.key && links.length ) {
			event.preventDefault();
			links[0].focus();
			return;
		}

		if ( 'Enter' === event.key && 1 === links.length ) {
			event.preventDefault();
			links[0].click();
		}
	} );

	// Una letra debe volver a mostrar su grupo antes de resolver el fragmento.
	letters.forEach( function ( letter ) {
		letter.addEventListener( 'click', function () {
			if ( ! hasActiveFilter() ) {
				return;
			}

			input.value = '';
			activePais = '';
			activeEditorial = '';
			activeEstado = '';
			apply( true );
		} );
	} );

	window.addEventListener( 'popstate', restoreFromUrl );
	window.addEventListener( 'pageshow', restoreFromUrl );
	restoreFromUrl();
} )();
