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
 * @package akibara-business
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
	 */
	function failOpenHero( root ) {
		if ( ! root ) { return; }

		var templates = root.querySelectorAll( 'template[data-akb-hero-media]' );
		Array.prototype.forEach.call( templates, function ( template ) {
			if ( ! template.content || ! template.parentNode ) { return; }
			var fragment = template.content.cloneNode( true );
			template.parentNode.insertBefore( fragment, template );
			template.parentNode.removeChild( template );
		} );

		var slides = root.querySelectorAll( '.akb-hero__slide' );
		Array.prototype.forEach.call( slides, function ( slide ) {
			slide.removeAttribute( 'aria-hidden' );
			var focusables = slide.querySelectorAll( '[data-akb-prev-tabindex]' );
			Array.prototype.forEach.call( focusables, function ( el ) {
				var previous = el.getAttribute( 'data-akb-prev-tabindex' );
				if ( '' === previous ) {
					el.removeAttribute( 'tabindex' );
				} else {
					el.setAttribute( 'tabindex', previous );
				}
				el.removeAttribute( 'data-akb-prev-tabindex' );
			} );
		} );

		var controls = root.querySelectorAll( '.akb-hero__progress,.akb-hero__arrow' );
		Array.prototype.forEach.call( controls, function ( control ) {
			control.setAttribute( 'hidden', '' );
		} );
		root.removeAttribute( 'data-akb-enhanced' );
		root.setAttribute( 'data-akb-fallback', '1' );
	}

	function initHero( root ) {
		if ( ! root || root.__akbHeroInit ) { return; }
		root.__akbHeroInit = true;

		var track       = root.querySelector( '.akb-hero__track' );
		var slides      = Array.prototype.slice.call( root.querySelectorAll( '.akb-hero__slide' ) );
		var segs        = Array.prototype.slice.call( root.querySelectorAll( '.akb-hero__seg' ) );
		var prevBtn     = root.querySelector( '.akb-hero__arrow--prev' );
		var nextBtn     = root.querySelector( '.akb-hero__arrow--next' );
		var progress    = root.querySelector( '.akb-hero__progress' );
		var progressBtn = root.querySelector( '[data-akb-progress]' );
		var srStatus    = root.querySelector( '.akb-hero__sr-status' );

		// El "reloj" es el fill del segmento ACTIVO (indicador segmentado):
		// se re-resuelve en cada cambio de slide, no es un nodo fijo.
		function activeFill() {
			var seg = segs[ current ];
			return seg ? seg.querySelector( '[data-akb-seg-fill]' ) : null;
		}

		if ( slides.length < 2 ) { return; }

		var cfg = {
			autoplay:    root.getAttribute( 'data-autoplay' )    === '1',
			interval:    parseInt( root.getAttribute( 'data-interval' ), 10 ) || 6000,
			pauseHover:  root.getAttribute( 'data-pause-hover' ) === '1',
			pauseBlur:   root.getAttribute( 'data-pause-blur' )  === '1',
			loop:        root.getAttribute( 'data-loop' )        === '1'
		};

		var reduceMql = window.matchMedia( '(prefers-reduced-motion: reduce)' );
		var desktopAutoplayMql = window.matchMedia( '(min-width: 769px)' );
		var configuredAutoplay = cfg.autoplay;
		var reduced = reduceMql.matches;
		// La UI del módulo define el autoplay como comportamiento de escritorio.
		// En móvil los segmentos y el swipe siguen disponibles, pero no se rota ni
		// se precargan slides sin una intención explícita del usuario.
		cfg.autoplay = configuredAutoplay && ! reduced && desktopAutoplayMql.matches;

		var current     = 0;
		var timerId     = null;
		var userPaused  = false;        // pausa manual del progress button (persistente)
		var pauseReasons = {};           // hover/touch/focus/drag pueden solaparse.
		var inViewport  = ! ( 'IntersectionObserver' in window );
		var remainingMs = cfg.interval;
		var timerDeadline = 0;
		var isDragging  = false;
		var dragStartX  = 0;
		var dragStartScroll = 0;
		var draggedPx   = 0;
		var snapAnimating = false;      // true durante smooth-scroll programático
		var snapTimer   = null;
		var mediaWarmTimer = null;      // hidrata el próximo slide poco antes del autoplay.

		/**
		 * Materializa una sola vez el `<picture>` diferido de un slide.
		 *
		 * El clone + insert es síncrono: dos señales de intención en el mismo
		 * frame no pueden duplicar la imagen. El `<noscript>` queda intacto como
		 * fallback real para navegación sin JS.
		 */
		function hydrateSlideMedia( slide ) {
			if ( ! slide ) { return null; }

			var existing = slide.querySelector( '.akb-hero__picture' );
			if ( existing ) {
				slide.__akbHeroMediaReady = true;
				return existing;
			}
			if ( slide.__akbHeroMediaReady ) { return null; }

			var template = slide.querySelector( 'template[data-akb-hero-media]' );
			if ( ! template || ! template.content ) { return null; }

			var fragment = template.content.cloneNode( true );
			var image = fragment.querySelector( '.akb-hero__img' );
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
			var idx = current + delta;
			if ( idx >= 0 && idx < slides.length ) { return idx; }
			if ( ! cfg.loop ) { return -1; }
			return idx < 0 ? slides.length - 1 : 0;
		}

		function physicalAdjacentIndex( delta ) {
			var idx = current + delta;
			return idx >= 0 && idx < slides.length ? idx : -1;
		}

		function hydrateAdjacentMedia() {
			// Un gesto físico no hace wrap: desde el slide 0 no se puede arrastrar
			// al último. Usar límites reales evita descargar ambos vecinos en el
			// primer touch/mousedown.
			var previous = physicalAdjacentIndex( -1 );
			var upcoming = physicalAdjacentIndex( 1 );
			if ( previous >= 0 ) { hydrateSlideMedia( slides[ previous ] ); }
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

			var targetIdx = autoplayTargetIndex();
			if ( targetIdx < 0 || slides[ targetIdx ].__akbHeroMediaReady ) { return; }

			var leadMs = Math.min( 1500, Math.max( 500, Math.round( cfg.interval * 0.2 ) ) );
			var delay  = Math.max( 0, remainingMs - leadMs );
			mediaWarmTimer = window.setTimeout( function () {
				mediaWarmTimer = null;
				hydrateSlideMedia( slides[ targetIdx ] );
			}, delay );
		}

		// ---- Render helpers ----
		function setSlideFocusable( slide, enabled ) {
			var focusables = slide.querySelectorAll( 'a[href], button, input, select, textarea, [tabindex]' );
			Array.prototype.forEach.call( focusables, function ( el ) {
				if ( enabled ) {
					if ( el.hasAttribute( 'data-akb-prev-tabindex' ) ) {
						var prev = el.getAttribute( 'data-akb-prev-tabindex' );
						if ( prev === '' ) {
							el.removeAttribute( 'tabindex' );
						} else {
							el.setAttribute( 'tabindex', prev );
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
				var on = i === idx;
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
				srStatus.textContent = slides[ idx ].getAttribute( 'aria-label' )
					|| ( 'Slide ' + ( idx + 1 ) + ' de ' + slides.length );
			}
			root.dispatchEvent( new CustomEvent( 'akb:hero:slidechange', { detail: { index: idx } } ) );
		}

		function snapTo( idx ) {
			var target = slides[ idx ];
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
			var idx = current + 1;
			if ( idx >= slides.length ) {
				if ( ! cfg.loop ) { return; }
				idx = 0;
			}
			goTo( idx, announce );
		}

		function prev( announce ) {
			var idx = current - 1;
			if ( idx < 0 ) {
				if ( ! cfg.loop ) { return; }
				idx = slides.length - 1;
			}
			goTo( idx, announce );
		}

		// ---- Scroll-driven active detection (replaces transform manipulation) ----
		var scrollDebounce = null;
		track.addEventListener( 'wheel', function ( e ) {
			var horizontalDelta = Math.abs( e.deltaX ) >= Math.abs( e.deltaY )
				? e.deltaX
				: ( e.shiftKey ? e.deltaY : 0 );
			if ( 0 === horizontalDelta ) { return; }
			var idx = physicalAdjacentIndex( horizontalDelta > 0 ? 1 : -1 );
			if ( idx >= 0 ) { hydrateSlideMedia( slides[ idx ] ); }
		}, { passive: true } );

		track.addEventListener( 'scroll', function () {
			// El swipe/scroll nativo puede comenzar sin pasar por un botón. En
			// cuanto el gesto revela otro panel, materializar su media evita que
			// permanezca vacío aunque el navegador haya originado el movimiento.
			var liveWidth = track.clientWidth;
			if ( liveWidth > 0 ) {
				var visibleIdx = Math.round( track.scrollLeft / liveWidth );
				visibleIdx = Math.max( 0, Math.min( slides.length - 1, visibleIdx ) );
				hydrateSlideMedia( slides[ visibleIdx ] );
			}

			window.clearTimeout( scrollDebounce );
			scrollDebounce = window.setTimeout( function () {
				// Ignorar mientras snapTo() programático está animando — sino
				// el listener detecta posición intermedia y revierte el slide.
				if ( isDragging || snapAnimating ) { return; }
				var width = track.clientWidth;
				if ( width <= 0 ) { return; }
				var idx = Math.round( track.scrollLeft / width );
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
				var fill = seg.querySelector( '[data-akb-seg-fill]' );
				if ( fill ) {
					fill.style.animation = '';
				}
			} );
		}

		function armActiveFill() {
			clearFills();
			var fill = activeFill();
			if ( ! fill ) { return; }
			fill.style.animation = 'none';
			// Force reflow para reiniciar la animación.
			void fill.offsetWidth;
			fill.style.animation = 'akb-hero-progress ' + cfg.interval + 'ms linear forwards';
			fill.style.animationPlayState = canAutoplay() ? 'running' : 'paused';
		}

		function hasPauseReason() {
			return Object.keys( pauseReasons ).some( function ( reason ) {
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

			var fill = activeFill();
			if ( fill ) {
				fill.style.animationPlayState = 'running';
			}

			timerDeadline = Date.now() + remainingMs;
			scheduleAutoplayMedia();
			timerId = window.setTimeout( function () {
				var targetIdx = autoplayTargetIndex();
				if ( targetIdx >= 0 ) {
					// Garantía final: incluso si el warm-up fue cancelado por una
					// pausa/reanudación, el picture existe antes de navegar.
					hydrateSlideMedia( slides[ targetIdx ] );
				}
				var previous = current;
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
			var fill = activeFill();
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
				var idx = adjacentIndex( -1 );
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
				var idx = adjacentIndex( 1 );
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
			var target = e.target;
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
				setPauseReason( 'focus', root.contains( document.activeElement ) );
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
			var io = new IntersectionObserver( function ( entries ) {
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
			var dx = e.pageX - dragStartX;
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
		var heroes = document.querySelectorAll( '[data-akb-hero]' );
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
