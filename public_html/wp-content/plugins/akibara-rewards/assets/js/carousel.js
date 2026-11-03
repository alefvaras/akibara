/**
 * Navegación y animación del carrusel «Voces de Akibara» (akibara-rewards).
 *
 * Progressive enhancement accesible y seguro:
 *  - Navegación precisa por tarjeta (offsetLeft relativo), sin desalineaciones por padding o gap.
 *  - Control de fronteras (inicio/fin): los botones < y > se deshabilitan cuando no hay
 *    más elementos en esa dirección, impidiendo el overscroll al vacío.
 *  - Auto-hide dinámico: si todas las tarjetas caben sin scroll (como en grilla de escritorio),
 *    la botonera se oculta automáticamente.
 *  - Reveal GSAP seguro sobre el contenedor: nunca deja tarjetas con opacity: 0
 *    en un contenedor con desplazamiento horizontal.
 *  - Soporte de teclado (Flechas Izquierda / Derecha) en el track con tabindex.
 */
( function () {
	'use strict';

	function initNav() {
		var carousel = document.querySelector( '.akb-rw-carousel' );
		if ( ! carousel ) {
			return;
		}

		var track = carousel.querySelector( '.akb-rw-track' );
		var nav = carousel.querySelector( '.akb-rw-nav' );
		var prevBtn = carousel.querySelector( '[data-akb-rw-prev]' );
		var nextBtn = carousel.querySelector( '[data-akb-rw-next]' );

		if ( ! track || ! prevBtn || ! nextBtn ) {
			return;
		}

		function getCards() {
			return Array.prototype.slice.call( track.querySelectorAll( '.akb-rw-card' ) );
		}

		function getMaxScroll() {
			return Math.max( 0, track.scrollWidth - track.clientWidth );
		}

		function getCardPosition( card ) {
			if ( card.offsetParent === track ) {
				return card.offsetLeft;
			}
			return card.offsetLeft - track.offsetLeft;
		}

		function updateNavState() {
			var maxScroll = getMaxScroll();

			// Si todas las tarjetas entran en la pantalla sin scroll (ej. grid de escritorio)
			if ( maxScroll <= 6 ) {
				if ( nav ) {
					nav.setAttribute( 'data-hidden', 'true' );
				}
				prevBtn.disabled = true;
				nextBtn.disabled = true;
				prevBtn.setAttribute( 'aria-disabled', 'true' );
				nextBtn.setAttribute( 'aria-disabled', 'true' );
				return;
			}

			if ( nav ) {
				nav.removeAttribute( 'data-hidden' );
			}

			var currentScroll = track.scrollLeft;
			var atStart = currentScroll <= 6;
			var atEnd = currentScroll >= ( maxScroll - 6 );

			prevBtn.disabled = atStart;
			prevBtn.setAttribute( 'aria-disabled', atStart ? 'true' : 'false' );

			nextBtn.disabled = atEnd;
			nextBtn.setAttribute( 'aria-disabled', atEnd ? 'true' : 'false' );
		}

		function scrollToCard( direction ) {
			var cards = getCards();
			if ( ! cards.length ) {
				return;
			}

			var maxScroll = getMaxScroll();
			var currentScroll = track.scrollLeft;

			if ( direction > 0 ) {
				// Avanzar: buscar la primera tarjeta cuyo inicio relativo esté por delante del scroll
				var nextTarget = null;
				for ( var i = 0; i < cards.length; i++ ) {
					var pos = getCardPosition( cards[ i ] );
					if ( pos > currentScroll + 15 ) {
						nextTarget = pos;
						break;
					}
				}

				if ( null === nextTarget ) {
					return;
				}

				track.scrollTo( {
					left: Math.min( maxScroll, nextTarget ),
					behavior: 'smooth'
				} );
			} else {
				// Retroceder: buscar la última tarjeta cuyo inicio relativo esté por detrás del scroll
				var prevTarget = 0;
				for ( var j = cards.length - 1; j >= 0; j-- ) {
					var p = getCardPosition( cards[ j ] );
					if ( p < currentScroll - 15 ) {
						prevTarget = p;
						break;
					}
				}

				track.scrollTo( {
					left: Math.max( 0, prevTarget ),
					behavior: 'smooth'
				} );
			}
		}

		prevBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( prevBtn.disabled || prevBtn.getAttribute( 'aria-disabled' ) === 'true' ) {
				return;
			}
			scrollToCard( -1 );
		} );

		nextBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( nextBtn.disabled || nextBtn.getAttribute( 'aria-disabled' ) === 'true' ) {
				return;
			}
			scrollToCard( 1 );
		} );

		var scrollTicking = false;
		track.addEventListener( 'scroll', function () {
			if ( ! scrollTicking ) {
				window.requestAnimationFrame( function () {
					updateNavState();
					scrollTicking = false;
				} );
				scrollTicking = true;
			}
		}, { passive: true } );

		var resizeTimer = null;
		window.addEventListener( 'resize', function () {
			window.clearTimeout( resizeTimer );
			resizeTimer = window.setTimeout( updateNavState, 100 );
		}, { passive: true } );

		track.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowLeft' ) {
				e.preventDefault();
				scrollToCard( -1 );
			} else if ( e.key === 'ArrowRight' ) {
				e.preventDefault();
				scrollToCard( 1 );
			}
		} );

		updateNavState();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initNav );
	} else {
		initNav();
	}

	if ( typeof gsap === 'undefined' || typeof ScrollTrigger === 'undefined' ) {
		return;
	}

	var FOLD = 0.92;

	function init() {
		var carousel = document.querySelector( '.akb-rw-carousel' );
		if ( ! carousel ) {
			return;
		}

		gsap.registerPlugin( ScrollTrigger );

		gsap.matchMedia().add( '(prefers-reduced-motion: no-preference)', function () {
			var cards = carousel.querySelectorAll( '.akb-rw-card' );
			if ( ! cards.length ) {
				return;
			}

			function restoreAll() {
				gsap.set( cards, { clearProps: 'opacity,transform' } );
			}

			var trigger = null;
			try {
				gsap.set( cards, { opacity: 0, y: 12 } );
				trigger = ScrollTrigger.create( {
					trigger: carousel,
					start: 'top ' + Math.round( FOLD * 100 ) + '%',
					once: true,
					onEnter: function () {
						gsap.to( cards, {
							opacity: 1,
							y: 0,
							duration: 0.36,
							ease: 'power3.out',
							stagger: 0.06,
							clearProps: 'opacity,transform'
						} );
					}
				} );
			} catch ( e ) {
				restoreAll();
			}

			return function () {
				if ( trigger ) {
					trigger.kill();
				}
				restoreAll();
			};
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
