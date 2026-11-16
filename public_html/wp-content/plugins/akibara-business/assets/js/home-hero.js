/**
 * Akibara Home Hero — carrusel última generación (scroll-snap nativo + drag desktop).
 *
 * Patrón 2026 — el JS solo orquesta lo que CSS no hace nativamente:
 *   - El movimiento entre slides corre por `scroll-snap` (CSS). Sin transforms.
 *   - JS detecta el slide activo via `track.scrollLeft` debounced.
 *   - Drag-to-scroll desktop: mousedown/move/up convierte arrastre en scrollLeft.
 *   - Touch: scroll horizontal nativo del browser (sin handlers JS).
 *   - Autoplay: opt-in del owner. Cuando activo, el fill del segmento activo
 *     del indicador segmentado comunica el tiempo restante, y un botón
 *     dedicado [data-akb-progress] pausa/reanuda (WCAG 2.2.2).
 *   - Pausa automática cuando hover, drag, touch, focus interno, tab oculto.
 *
 * Solo se inicializa cuando hay 2+ slides. 1 slide = hero estático sin JS.
 *
 * @package
 */
( function () {
	'use strict';

	/**
	 * Recuperación progresiva si una excepción aborta la inicialización.
	 *
	 * No puede cubrir un archivo JS que nunca llegue a cargar (`<noscript>` solo
	 * aplica cuando scripting está deshabilitado), pero sí evita slides vacíos
	 * por una incompatibilidad o transformación defectuosa del optimizador. En
	 * el fallo total de red, el slide 0 SSR sigue siendo utilizable.
	 * @param {*} root
	 */
	function failOpenHero( root ) {
		if ( ! root ) { return; }

		const templates = root.querySelectorAll( 'template[data-akb-hero-media]' );
		Array.prototype.forEach.call( templates, function ( template ) {
			if ( ! template.content || ! template.parentNode ) { return; }
			const fragment = template.content.cloneNode( true );
			template.parentNode.insertBefore( fragment, template );
			template.parentNode.removeChild( template );
		} );

		const slides = root.querySelectorAll( '.akb-hero__slide' );
		Array.prototype.forEach.call( slides, function ( slide ) {
			slide.removeAttribute( 'aria-hidden' );
			const focusables = slide.querySelectorAll( '[data-akb-prev-tabindex]' );
			Array.prototype.forEach.call( focusables, function ( el ) {
				const previous = el.getAttribute( 'data-akb-prev-tabindex' );
				if ( '' === previous ) {
					el.removeAttribute( 'tabindex' );
				} else {
					el.setAttribute( 'tabindex', previous );
				}
				el.removeAttribute( 'data-akb-prev-tabindex' );
			} );
		} );

		const controls = root.querySelectorAll( '.akb-hero__progress,.akb-hero__arrow' );
		Array.prototype.forEach.call( controls, function ( control ) {
			control.setAttribute( 'hidden', '' );
		} );
		root.removeAttribute( 'data-akb-enhanced' );
		root.setAttribute( 'data-akb-fallback', '1' );
	}

	function initHero( root ) {
		if ( ! root || root.__akbHeroInit ) { return; }
		root.__akbHeroInit = true;

		const slides      = Array.prototype.slice.call( root.querySelectorAll( '.akb-hero__slide' ) );
		if ( slides.length < 2 ) { return; }

		const track       = root.querySelector( '.akb-hero__track' );
		const segs        = Array.prototype.slice.call( root.querySelectorAll( '.akb-hero__seg' ) );
		const prevBtn     = root.querySelector( '.akb-hero__arrow--prev' );
		const nextBtn     = root.querySelector( '.akb-hero__arrow--next' );
		const progress    = root.querySelector( '.akb-hero__progress' );
		const progressBtn = root.querySelector( '[data-akb-progress]' );
		const srStatus    = root.querySelector( '.akb-hero__sr-status' );

		let current     = 0;

		// El "reloj" es el fill del segmento ACTIVO (indicador segmentado):
		// se re-resuelve en cada cambio de slide, no es un nodo fijo.
		function activeFill() {
			// eslint-disable-next-line security/detect-object-injection
			const seg = segs[ current ];
			return seg ? seg.querySelector( '[data-akb-seg-fill]' ) : null;
		}

		const cfg = {
			autoplay:    root.getAttribute( 'data-autoplay' )    === '1',
			interval:    parseInt( root.getAttribute( 'data-interval' ), 10 ) || 6000,
			pauseHover:  root.getAttribute( 'data-pause-hover' ) === '1',
			pauseBlur:   root.getAttribute( 'data-pause-blur' )  === '1',
			loop:        root.getAttribute( 'data-loop' )        === '1'
		};

		const reduceMql = window.matchMedia( '(prefers-reduced-motion: reduce)' );
		const desktopAutoplayMql = window.matchMedia( '(min-width: 769px)' );
		const configuredAutoplay = cfg.autoplay;
		let reduced = reduceMql.matches;
		// La UI del módulo define el autoplay como comportamiento de escritorio.
		// En móvil los segmentos y el swipe siguen disponibles, pero no se rota ni
		// se precargan slides sin una intención explícita del usuario.
		cfg.autoplay = configuredAutoplay && ! reduced && desktopAutoplayMql.matches;
		let timerId     = null;
		let userPaused  = false;        // pausa manual del progress button (persistente)
		const pauseReasons = {};           // hover/touch/focus/drag pueden solaparse.
		let inViewport  = ! ( 'IntersectionObserver' in window );
		let remainingMs = cfg.interval;
		let timerDeadline = 0;
		let isDragging  = false;
		let dragStartX  = 0;
		let dragStartScroll = 0;
		let draggedPx   = 0;
		let snapAnimating = false;      // true durante smooth-scroll programático
		let snapTimer   = null;
		let mediaWarmTimer = null;      // hidrata el próximo slide poco antes del autoplay.

		/**
		 * Materializa una sola vez el `<picture>` diferido de un slide.
		 *
		 * El clone + insert es síncrono: dos señales de intención en el mismo
		 * frame no pueden duplicar la imagen. El `<noscript>` queda intacto como
		 * fallback real para navegación sin JS.
		 * @param {*} slide
		 */
		function hydrateSlideMedia( slide ) {
			if ( ! slide ) { return null; }

			const existing = slide.querySelector( '.akb-hero__picture' );
			if ( existing ) {
				slide.__akbHeroMediaReady = true;
				return existing;
			}
			if ( slide.__akbHeroMediaReady ) { return null; }

			const template = slide.querySelector( 'template[data-akb-hero-media]' );
			if ( ! template || ! template.content ) { return null; }

			const fragment = template.content.cloneNode( true );
			const image = fragment.querySelector( '.akb-hero__img' );
			if ( image ) {
				// La decisión de descarga ya ocurrió por intención/autoplay: desde
				// aquí eager evita un panel vacío en saltos directos 0→2. Nunca
				// hereda `high`; esa prioridad queda reservada al LCP (slide 0).
				image.setAttribute( 'loading', 'eager' );
				image.removeAttribute( 'fetchpriority' );
			}
			template.parentNode.insertBefore( fragment, template );
			template.parentNode.removeChild( template );
			slide.__akbHeroMediaReady = true;
			slide.setAttribute( 'data-akb-media-ready', '1' );
			if ( slides.indexOf( slide ) !== current ) {
				// Si en el futuro el template incorpora un link/control, no debe
				// entrar al tab order mientras su slide siga aria-hidden.
				setSlideFocusable( slide, false );
			}

			return slide.querySelector( '.akb-hero__picture' );
		}

		function adjacentIndex( delta ) {
			const idx = current + delta;
			if ( idx >= 0 && idx < slides.length ) { return idx; }
			if ( ! cfg.loop ) { return -1; }
			return idx < 0 ? slides.length - 1 : 0;
		}

		function physicalAdjacentIndex( delta ) {
			const idx = current + delta;
			return idx >= 0 && idx < slides.length ? idx : -1;
		}

		function hydrateAdjacentMedia() {
			// Un gesto físico no hace wrap: desde el slide 0 no se puede arrastrar
			// al último. Usar límites reales evita descargar ambos vecinos en el
			// primer touch/mousedown.
			const previous = physicalAdjacentIndex( -1 );
			const upcoming = physicalAdjacentIndex( 1 );
			// eslint-disable-next-line security/detect-object-injection
			if ( previous >= 0 ) { hydrateSlideMedia( slides[ previous ] ); }
			// eslint-disable-next-line security/detect-object-injection
			if ( upcoming >= 0 ) { hydrateSlideMedia( slides[ upcoming ] ); }
		}

		function autoplayTargetIndex() {
			return adjacentIndex( 1 );
		}

		function clearMediaWarmTimer() {
			if ( null !== mediaWarmTimer ) {
				window.clearTimeout( mediaWarmTimer );
				mediaWarmTimer = null;
			}
		}

		/**
		 * No descarga el siguiente arte al cargar la página. Lo materializa
		 * cerca del cambio automático para darle una ventana breve de red sin
		 * convertir todos los slides horizontales en candidatos "near viewport".
		 */
		function scheduleAutoplayMedia() {
			clearMediaWarmTimer();
			if ( ! canAutoplay() ) { return; }

			const targetIdx = autoplayTargetIndex();
			// eslint-disable-next-line security/detect-object-injection
			if ( targetIdx < 0 || slides[ targetIdx ].__akbHeroMediaReady ) { return; }

			const leadMs = Math.min( 1500, Math.max( 500, Math.round( cfg.interval * 0.2 ) ) );
			const delay  = Math.max( 0, remainingMs - leadMs );
			mediaWarmTimer = window.setTimeout( function () {
				mediaWarmTimer = null;
				// eslint-disable-next-line security/detect-object-injection
				hydrateSlideMedia( slides[ targetIdx ] );
			}, delay );
		}

		// ---- Render helpers ----
		function setSlideFocusable( slide, enabled ) {
			const focusables = slide.querySelectorAll( 'a[href], button, input, select, textarea, [tabindex]' );
			Array.prototype.forEach.call( focusables, function ( el ) {
				if ( enabled ) {
					if ( el.hasAttribute( 'data-akb-prev-tabindex' ) ) {
						const prevTabindex = el.getAttribute( 'data-akb-prev-tabindex' );
						if ( prevTabindex === '' ) {
							el.removeAttribute( 'tabindex' );
						} else {
							el.setAttribute( 'tabindex', prevTabindex );
						}
						el.removeAttribute( 'data-akb-prev-tabindex' );
					}
					return;
				}

				if ( ! el.hasAttribute( 'data-akb-prev-tabindex' ) ) {
					el.setAttribute( 'data-akb-prev-tabindex', el.getAttribute( 'tabindex' ) || '' );
				}
				el.setAttribute( 'tabindex', '-1' );
			} );
		}

		function setActive( idx, announce ) {
			current = idx;
			slides.forEach( function ( slide, i ) {
				if ( i === idx ) {
					slide.classList.add( 'is-active' );
					slide.removeAttribute( 'aria-hidden' );
					setSlideFocusable( slide, true );
				} else {
					slide.classList.remove( 'is-active' );
					slide.setAttribute( 'aria-hidden', 'true' );
					setSlideFocusable( slide, false );
				}
			} );
			segs.forEach( function ( seg, i ) {
				const on = i === idx;
				seg.classList.toggle( 'is-active', on );
				// Previos llenos (patrón stories). El fill "visto" es CLASE, no
				// inline: el reset animation:none del reloj no debe poder dejar
				// un transform stale en los previos. Al volver al slide 0 (wrap
				// del loop) nadie es "visto" y la tira se vacía.
				seg.classList.toggle( 'is-seen', i < idx );
				if ( on ) {
					seg.setAttribute( 'aria-current', 'true' );
				} else {
					seg.removeAttribute( 'aria-current' );
				}
			} );
			resetProgress();
			if ( announce && srStatus ) {
				// eslint-disable-next-line security/detect-object-injection
				srStatus.textContent = slides[ idx ].getAttribute( 'aria-label' )
					|| ( 'Slide ' + ( idx + 1 ) + ' de ' + slides.length );
			}
			root.dispatchEvent( new CustomEvent( 'akb:hero:slidechange', { detail: { index: idx } } ) );
		}

		function snapTo( idx ) {
			// eslint-disable-next-line security/detect-object-injection
			const target = slides[ idx ];
			if ( ! target ) { return; }
			hydrateSlideMedia( target );
			// Marca animation activa para que el scroll listener no revierta el
			// slide mid-smooth-scroll (race condition observada).
			snapAnimating = true;
			window.clearTimeout( snapTimer );
			snapTimer = window.setTimeout( function () {
				snapAnimating = false;
			}, reduced ? 50 : 700 );

			track.scrollTo( {
				left: target.offsetLeft,
				behavior: reduced ? 'auto' : 'smooth'
			} );
		}

		function goTo( idx, announce ) {
			if ( idx < 0 || idx >= slides.length || idx === current ) { return; }
			snapTo( idx );
			setActive( idx, !! announce );
			// Navegación MANUAL (announce=true: dots/flechas/teclado) reinicia el reloj
			// del autoplay. Sin esto el reloj corre descoordinado del input
			// y un click que cae sobre el borde del tick avanzaba +2 (salto doble).
			if ( announce && cfg.autoplay && ! userPaused ) {
				stopAutoplay( false );
				startAutoplay( true );
			}
		}

		function next( announce ) {
			let idx = current + 1;
			if ( idx >= slides.length ) {
				if ( ! cfg.loop ) { return; }
				idx = 0;
			}
			goTo( idx, announce );
		}

		function prev( announce ) {
			let idx = current - 1;
			if ( idx < 0 ) {
				if ( ! cfg.loop ) { return; }
				idx = slides.length - 1;
			}
			goTo( idx, announce );
		}

		// ---- Scroll-driven active detection (replaces transform manipulation) ----
		let scrollDebounce = null;
		track.addEventListener( 'wheel', function ( e ) {
			const shiftDelta = e.shiftKey ? e.deltaY : 0;
			const horizontalDelta = Math.abs( e.deltaX ) >= Math.abs( e.deltaY ) ? e.deltaX : shiftDelta;
			if ( 0 === horizontalDelta ) { return; }
			const idx = physicalAdjacentIndex( horizontalDelta > 0 ? 1 : -1 );
			// eslint-disable-next-line security/detect-object-injection
			if ( idx >= 0 ) { hydrateSlideMedia( slides[ idx ] ); }
		}, { passive: true } );

		track.addEventListener( 'scroll', function () {
			// El swipe/scroll nativo puede comenzar sin pasar por un botón. En
			// cuanto el gesto revela otro panel, materializar su media evita que
			// permanezca vacío aunque el navegador haya originado el movimiento.
			const liveWidth = track.clientWidth;
			if ( liveWidth > 0 ) {
				let visibleIdx = Math.round( track.scrollLeft / liveWidth );
				visibleIdx = Math.max( 0, Math.min( slides.length - 1, visibleIdx ) );
				// eslint-disable-next-line security/detect-object-injection
				hydrateSlideMedia( slides[ visibleIdx ] );
			}

			window.clearTimeout( scrollDebounce );
			scrollDebounce = window.setTimeout( function () {
				// Ignorar mientras snapTo() programático está animando — sino
				// el listener detecta posición intermedia y revierte el slide.
				if ( isDragging || snapAnimating ) { return; }
				const width = track.clientWidth;
				if ( width <= 0 ) { return; }
				let idx = Math.round( track.scrollLeft / width );
				idx = Math.max( 0, Math.min( slides.length - 1, idx ) );
				if ( idx !== current ) {
					setActive( idx, false );
					if ( cfg.autoplay && ! userPaused ) {
						stopAutoplay( false );
						startAutoplay( true );
					}
				}
			}, 120 );
		}, { passive: true } );

		// ---- Autoplay + reloj del segmento activo ----
		// El estado "visto" de los previos vive en la clase is-seen (CSS): al
		// limpiar animaciones inline los previos no pierden su fill.
		function clearFills() {
			segs.forEach( function ( seg ) {
				const fill = seg.querySelector( '[data-akb-seg-fill]' );
				if ( fill ) {
					fill.style.animation = '';
				}
			} );
		}

		function armActiveFill() {
			clearFills();
			const fill = activeFill();
			if ( ! fill ) { return; }
			fill.style.animation = 'none';
			// Force reflow para reiniciar la animación.
			void fill.offsetWidth;
			fill.style.animation = 'akb-hero-progress ' + cfg.interval + 'ms linear forwards';
			fill.style.animationPlayState = canAutoplay() ? 'running' : 'paused';
		}

		function hasPauseReason() {
			return Object.keys( pauseReasons ).some( function ( reason ) {
				// eslint-disable-next-line security/detect-object-injection
				return pauseReasons[ reason ];
			} );
		}

		function canAutoplay() {
			return cfg.autoplay
				&& ! userPaused
				&& ! hasPauseReason()
				&& inViewport
				&& ( ! cfg.pauseBlur || ! document.hidden );
		}

		function startAutoplay( resetClock ) {
			if ( resetClock ) {
				remainingMs = cfg.interval;
				armActiveFill();
			}
			if ( ! canAutoplay() || null !== timerId ) { return; }

			const fill = activeFill();
			if ( fill ) {
				fill.style.animationPlayState = 'running';
			}

			timerDeadline = Date.now() + remainingMs;
			scheduleAutoplayMedia();
			timerId = window.setTimeout( function () {
				const targetIdx = autoplayTargetIndex();
				if ( targetIdx >= 0 ) {
					// Garantía final: incluso si el warm-up fue cancelado por una
					// pausa/reanudación, el picture existe antes de navegar.
					// eslint-disable-next-line security/detect-object-injection
					hydrateSlideMedia( slides[ targetIdx ] );
				}
				const previous = current;
				timerId = null;
				remainingMs = cfg.interval;
				next( false );
				if ( current !== previous ) {
					startAutoplay( true );
				}
			}, remainingMs );
		}

		function stopAutoplay( preserveRemaining ) {
			clearMediaWarmTimer();
			if ( ! preserveRemaining ) {
				remainingMs = cfg.interval;
			}
			if ( timerId !== null ) {
				if ( preserveRemaining ) {
					remainingMs = Math.max( 80, timerDeadline - Date.now() );
				}
				window.clearTimeout( timerId );
				timerId = null;
			}
			const fill = activeFill();
			if ( fill ) {
				fill.style.animationPlayState = 'paused';
			}
		}

		function resetProgress() {
			if ( ! cfg.autoplay ) { return; }
			if ( userPaused ) {
				// Sin reloj mientras dura la pausa manual, pero una animación
				// inline pausada a medias NO puede quedar pisando la clase
				// is-seen del segmento que dejó de estar activo.
				clearFills();
				return;
			}
			armActiveFill();
		}

		function setPauseReason( reason, paused ) {
			// eslint-disable-next-line security/detect-object-injection
			pauseReasons[ reason ] = !! paused;
			if ( canAutoplay() ) {
				startAutoplay( false );
			} else {
				stopAutoplay( true );
			}
		}

		// Pause manual del usuario (click en progress bar) — persistente.
		function toggleUserPause() {
			userPaused = ! userPaused;
			if ( progressBtn ) {
				progressBtn.setAttribute( 'aria-pressed', userPaused ? 'true' : 'false' );
				progressBtn.setAttribute(
					'aria-label',
					userPaused ? 'Reanudar rotación automática' : 'Pausar rotación automática'
				);
				progressBtn.classList.toggle( 'is-paused', userPaused );
			}
			if ( userPaused ) {
				stopAutoplay( true );
			} else {
				startAutoplay( false );
			}
		}

		// ---- Events: segmentos, arrows, pause/play, keyboard ----
		segs.forEach( function ( seg, i ) {
			function warmSegmentMedia() {
				// eslint-disable-next-line security/detect-object-injection
				hydrateSlideMedia( slides[ i ] );
			}
			seg.addEventListener( 'mouseenter', warmSegmentMedia );
			seg.addEventListener( 'focus', warmSegmentMedia );
			seg.addEventListener( 'touchstart', warmSegmentMedia, { passive: true } );
			seg.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				goTo( i, true );
			} );
		} );

		if ( prevBtn ) {
			function warmPreviousMedia() {
				const idx = adjacentIndex( -1 );
				// eslint-disable-next-line security/detect-object-injection
				if ( idx >= 0 ) { hydrateSlideMedia( slides[ idx ] ); }
			}
			prevBtn.addEventListener( 'mouseenter', warmPreviousMedia );
			prevBtn.addEventListener( 'focus', warmPreviousMedia );
			prevBtn.addEventListener( 'touchstart', warmPreviousMedia, { passive: true } );
			prevBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				prev( true );
			} );
		}
		if ( nextBtn ) {
			function warmNextMedia() {
				const idx = adjacentIndex( 1 );
				// eslint-disable-next-line security/detect-object-injection
				if ( idx >= 0 ) { hydrateSlideMedia( slides[ idx ] ); }
			}
			nextBtn.addEventListener( 'mouseenter', warmNextMedia );
			nextBtn.addEventListener( 'focus', warmNextMedia );
			nextBtn.addEventListener( 'touchstart', warmNextMedia, { passive: true } );
			nextBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				next( true );
			} );
		}
		if ( progressBtn ) {
			progressBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				toggleUserPause();
			} );
		}

		root.addEventListener( 'keydown', function ( e ) {
			// No secuestrar las flechas desde CTAs o contenido del slide: al cambiar
			// de panel, el enlace enfocado quedaría dentro de aria-hidden. La
			// navegación por flechas pertenece a los controles del carrusel.
			const target = e.target;
			if (
				! target
				|| ! target.closest
				|| ! target.closest( '.akb-hero__seg, .akb-hero__arrow' )
			) {
				return;
			}
			if ( e.key === 'ArrowLeft' ) {
				e.preventDefault();
				prev( true );
			} else if ( e.key === 'ArrowRight' ) {
				e.preventDefault();
				next( true );
			}
		} );

		// ---- Pause on hover / focus / visibility ----
		if ( cfg.pauseHover ) {
			root.addEventListener( 'mouseenter', function () { setPauseReason( 'hover', true ); } );
			root.addEventListener( 'mouseleave', function () { setPauseReason( 'hover', false ); } );
		}

		// Pause on focus interno (a11y) — keyboard/screen reader users.
		root.addEventListener( 'focusin', function () { setPauseReason( 'focus', true ); } );
		root.addEventListener( 'focusout', function () {
			window.setTimeout( function () {
				setPauseReason( 'focus', root.contains( document.documentElement.ownerDocument.activeElement ) );
			}, 0 );
		} );

		if ( cfg.pauseBlur ) {
			document.addEventListener( 'visibilitychange', function () {
				if ( document.hidden ) {
					stopAutoplay( true );
				} else {
					startAutoplay( false );
				}
			} );
		}

		// IO autoplay solo cuando el hero está en viewport.
		if ( 'IntersectionObserver' in window ) {
			const io = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					inViewport = entry.isIntersecting;
					if ( inViewport ) { startAutoplay( false ); }
					else              { stopAutoplay( true ); }
				} );
			}, { threshold: 0.25 } );
			io.observe( root );
		}

		function syncMotionPreference() {
			reduced = reduceMql.matches;
			cfg.autoplay = configuredAutoplay && ! reduced && desktopAutoplayMql.matches;
			root.setAttribute( 'data-akb-autoplay-active', cfg.autoplay ? '1' : '0' );
			if ( progressBtn ) {
				progressBtn.hidden = ! cfg.autoplay;
			}
			if ( cfg.autoplay ) {
				startAutoplay( true );
			} else {
				stopAutoplay( false );
				clearFills();
			}
		}

		if ( typeof reduceMql.addEventListener === 'function' ) {
			reduceMql.addEventListener( 'change', syncMotionPreference );
		} else if ( typeof reduceMql.addListener === 'function' ) {
			reduceMql.addListener( syncMotionPreference );
		}
		if ( typeof desktopAutoplayMql.addEventListener === 'function' ) {
			desktopAutoplayMql.addEventListener( 'change', syncMotionPreference );
		} else if ( typeof desktopAutoplayMql.addListener === 'function' ) {
			desktopAutoplayMql.addListener( syncMotionPreference );
		}

		// ---- Drag-to-scroll desktop (mouse) ----
		// Touch swipe usa scroll horizontal nativo del browser. Mouse drag requiere
		// handlers explícitos. La regla `scroll-snap-type: x mandatory` en CSS
		// hace el snap al slide más cercano al soltar.
		track.addEventListener( 'mousedown', function ( e ) {
			if ( e.button !== 0 ) { return; } // solo click izquierdo
			hydrateAdjacentMedia();
			isDragging = true;
			draggedPx = 0;
			dragStartX = e.pageX;
			dragStartScroll = track.scrollLeft;
			track.style.scrollBehavior = 'auto';
			track.style.cursor = 'grabbing';
			setPauseReason( 'drag', true );
			e.preventDefault();
		} );

		document.addEventListener( 'mousemove', function ( e ) {
			if ( ! isDragging ) { return; }
			const dx = e.pageX - dragStartX;
			draggedPx = Math.abs( dx );
			track.scrollLeft = dragStartScroll - dx;
		} );

		function endDrag() {
			if ( ! isDragging ) { return; }
			isDragging = false;
			track.style.scrollBehavior = '';
			track.style.cursor = '';
			setPauseReason( 'drag', false );
		}

		document.addEventListener( 'mouseup', endDrag );
		document.addEventListener( 'mouseleave', endDrag );

		// Bloquear click si el usuario arrastró >5px (evita disparar CTAs por accidente).
		track.addEventListener( 'click', function ( e ) {
			if ( draggedPx > 5 ) {
				e.preventDefault();
				e.stopPropagation();
				draggedPx = 0;
			}
		}, true );

		// Touch: pausa visual durante el swipe (touch swipe es scroll horizontal nativo).
		track.addEventListener( 'touchstart',  function () {
			hydrateAdjacentMedia();
			setPauseReason( 'touch', true );
		}, { passive: true } );
		track.addEventListener( 'touchend',    function () { setPauseReason( 'touch', false ); }, { passive: true } );
		track.addEventListener( 'touchcancel', function () { setPauseReason( 'touch', false ); }, { passive: true } );

		// ---- Boot ----
		// El primer slide ya viene SSR directo; esta llamada solo sella el estado
		// idempotente para compartir el mismo camino que los slides diferidos.
		hydrateSlideMedia( slides[0] );
		setActive( 0, false );
		syncMotionPreference();
		// Los controles nacen `hidden` en SSR porque sin JS no ejecutan ninguna
		// acción. Se exponen solo después de instalar handlers y estado ARIA.
		if ( progress ) { progress.removeAttribute( 'hidden' ); }
		if ( prevBtn )  { prevBtn.removeAttribute( 'hidden' ); }
		if ( nextBtn )  { nextBtn.removeAttribute( 'hidden' ); }
		root.setAttribute( 'data-akb-enhanced', '1' );
		// La live region se siembra SERVER-SIDE con "Slide 1 de N" (Renderer.php): es
		// contenido INICIAL, así que los lectores de pantalla no lo anuncian. El JS solo
		// la actualiza al CAMBIAR de slide (setActive con announce=true). Mutarla acá en
		// el boot dispararía un anuncio en carga (vacío→texto ES un cambio de contenido),
		// justo el spam que queremos evitar.
		// syncMotionPreference arma el reloj; con IO espera a que el hero entre al
		// viewport y, sin IO, comienza de inmediato.
	}

	function bootAll() {
		const heroes = document.querySelectorAll( '[data-akb-hero]' );
		Array.prototype.forEach.call( heroes, function ( root ) {
			try {
				initHero( root );
			} catch ( error ) {
				failOpenHero( root );
				if ( window.console && typeof window.console.error === 'function' ) {
					window.console.error( 'Akibara HomeHero no pudo inicializarse.', error );
				}
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bootAll );
	} else {
		bootAll();
	}
}() );
