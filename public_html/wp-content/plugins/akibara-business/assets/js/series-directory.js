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

	var QUERY_PARAM = 'buscar';
	var root = document.querySelector( '[data-akb-series-directory]' );
	if ( ! root ) {
		return;
	}

	var filter = root.querySelector( '[data-akb-series-filter]' );
	var input = root.querySelector( '.akb-series-directory__input' );
	var count = root.querySelector( '.akb-series-directory__count' );
	var empty = root.querySelector( '[data-akb-series-empty]' );
	var groups = Array.prototype.slice.call( root.querySelectorAll( '[data-akb-series-group]' ) );
	var items = Array.prototype.slice.call( root.querySelectorAll( '[data-akb-serie]' ) );
	var letters = Array.prototype.slice.call( root.querySelectorAll( '[data-akb-series-letter]' ) );
	var chipsPais = Array.prototype.slice.call( root.querySelectorAll( '[data-akb-filter-pais]' ) );
	var chipsEditorial = Array.prototype.slice.call( root.querySelectorAll( '[data-akb-filter-editorial]' ) );
	var chipsEstado = Array.prototype.slice.call( root.querySelectorAll( '[data-akb-filter-estado]' ) );

	if ( ! filter || ! input || ! items.length ) {
		return;
	}

	var total = items.length;
	var activePais = '';
	var activeEditorial = '';
	var activeEstado = '';

	// El buscador sólo existe si este script corrió: revelarlo acá es lo que
	// impide prometer una función que no está.
	filter.removeAttribute( 'hidden' );

	/** Minúsculas sin diacríticos, con degradación para motores sin normalize. */
	function fold( value ) {
		var folded = String( value ).toLowerCase();
		if ( 'function' === typeof folded.normalize ) {
			folded = folded.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
		}
		return folded.trim();
	}

	/*
	 * El PHP compone el texto y el formato local de miles en los data-attrs. Sustituir sólo el
	 * número visible y conservar la frase del servidor.
	 */
	var labelAll = count ? count.getAttribute( 'data-akb-series-count-all' ) : '';
	var labelFiltered = count ? count.getAttribute( 'data-akb-series-count-filtered' ) : '';
	var locale = ( count && count.getAttribute( 'data-akb-series-locale' ) ) || 'es-CL';

	/** Agrupa miles con el locale del sitio; degrada al entero pelado. */
	function formatCount( value ) {
		try {
			return new Intl.NumberFormat( locale ).format( value );
		} catch ( error ) {
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

	/** Sincroniza el estado visual y accesible de un grupo de chips. */
	function syncChips( chips, attr, activeValue ) {
		chips.forEach( function ( chip ) {
			var val = chip.getAttribute( attr ) || '';
			var isActive = val === activeValue;
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
	}

	/** Lee la consulta compartible; una URL inválida degrada a búsqueda vacía. */
	function queryFromUrl() {
		try {
			return new URL( window.location.href ).searchParams.get( QUERY_PARAM ) || '';
		} catch ( error ) {
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
			var url = new URL( window.location.href );
			var value = input.value.trim();

			if ( '' === value ) {
				url.searchParams.delete( QUERY_PARAM );
			} else {
				url.searchParams.set( QUERY_PARAM, value );
			}

			var prevState = ( window.history.state && 'object' === typeof window.history.state )
				? window.history.state
				: {};
			var nextState = Object.assign( {}, prevState, {
				akbSeriesPais: activePais,
				akbSeriesEditorial: activeEditorial,
				akbSeriesEstado: activeEstado
			} );

			window.history.replaceState(
				nextState,
				'',
				url.pathname + url.search + url.hash
			);
		} catch ( error ) {
			// La búsqueda sigue funcionando aunque un navegador bloquee History API.
		}
	}

	/** Enlaces que siguen disponibles después del filtro, en orden de lectura. */
	function visibleLinks() {
		var links = [];

		items.forEach( function ( item ) {
			if ( item.hasAttribute( 'hidden' ) ) {
				return;
			}

			var link = item.querySelector( '.akb-series-directory__link' );
			if ( link ) {
				links.push( link );
			}
		} );

		return links;
	}

	/** Aplica filtro, grupos, estado vivo y opcionalmente la URL. */
	function apply( updateUrl ) {
		var query = fold( input.value );
		var shown = 0;

		syncAllChips();

		items.forEach( function ( item ) {
			var haystack = item.getAttribute( 'data-akb-serie' ) || '';
			var itemPais = item.getAttribute( 'data-akb-pais' ) || '';
			var itemEditorial = item.getAttribute( 'data-akb-editorial' ) || '';
			var itemEstado = item.getAttribute( 'data-akb-estado' ) || '';

			var matchQuery = '' === query || -1 !== haystack.indexOf( query );
			var matchPais = '' === activePais || itemPais === activePais;
			var matchEditorial = '' === activeEditorial || itemEditorial === activeEditorial;
			var matchEstado = '' === activeEstado || itemEstado === activeEstado;

			// `hidden` también saca lo descartado del árbol de accesibilidad.
			if ( matchQuery && matchPais && matchEditorial && matchEstado ) {
				item.removeAttribute( 'hidden' );
				shown++;
			} else {
				item.setAttribute( 'hidden', '' );
			}
		} );

		groups.forEach( function ( group ) {
			var visible = group.querySelector( '[data-akb-serie]:not([hidden])' );
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
		var state = ( window.history && window.history.state && 'object' === typeof window.history.state )
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
				var clicked = chip.getAttribute( attr ) || '';
				// Clic sobre un filtro activo distinto de «Todos/Todas» lo desactiva.
				var next = ( '' !== clicked && clicked === getCurrent() ) ? '' : clicked;
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

		var links = visibleLinks();
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
