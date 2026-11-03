/**
 * TOC scroll-spy: marca la sección activa en el índice según el scroll
 * (aria-current="true" en el link de la sección visible). Progressive enhancement:
 * sin JS el TOC sigue funcionando como índice de anclas. El smooth-scroll y el
 * offset de aterrizaje los maneja el CSS (scroll-margin-top + scroll-behavior).
 *
 * Cubre dos TOCs: `.akb-page__toc` (páginas legales/guías, bloque editorial)
 * y `#rank-math-toc` (bloque oficial de Rank Math en posts). Ambos enlazan
 * anclas `#id` a encabezados con ese id, así que el spy funciona igual.
 *
 * @package akibara-gp
 */
( function () {
	'use strict';

	var toc = document.querySelector( '.akb-page__toc, #rank-math-toc' );
	if ( ! toc || ! ( 'IntersectionObserver' in window ) ) {
		return;
	}

	var links = Array.prototype.slice.call( toc.querySelectorAll( 'a[href^="#"]' ) );
	if ( ! links.length ) {
		return;
	}

	var byId = {};
	var targets = [];
	links.forEach( function ( a ) {
		var id = decodeURIComponent( a.getAttribute( 'href' ).slice( 1 ) );
		var el = id ? document.getElementById( id ) : null;
		if ( el ) {
			byId[ id ] = a;
			targets.push( el );
		}
	} );
	if ( ! targets.length ) {
		return;
	}

	var current = null;
	function setCurrent( link ) {
		if ( link === current ) {
			return;
		}
		if ( current ) {
			current.removeAttribute( 'aria-current' );
		}
		if ( link ) {
			link.setAttribute( 'aria-current', 'true' );
		}
		current = link;
	}

	var io = new IntersectionObserver( function ( entries ) {
		// De las secciones que están intersectando la banda activa, marcar la más alta.
		var visible = entries.filter( function ( e ) { return e.isIntersecting; } );
		if ( ! visible.length ) {
			return;
		}
		visible.sort( function ( x, y ) {
			return x.boundingClientRect.top - y.boundingClientRect.top;
		} );
		setCurrent( byId[ visible[ 0 ].target.id ] );
	}, {
		// Banda activa: tiene que empezar POR ENCIMA de donde aterriza el título
		// tras pulsar el índice (94 px en móvil, 120 en escritorio: ver el
		// `scroll-margin-top` de blog.css). Con -130 la sección recién saltada
		// caía fuera de la banda y el índice seguía resaltando la anterior.
		rootMargin: '-90px 0px -65% 0px',
		threshold: 0
	} );

	targets.forEach( function ( t ) { io.observe( t ); } );
}() );
