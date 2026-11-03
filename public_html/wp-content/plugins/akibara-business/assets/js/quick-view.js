(function () {
	'use strict';

	// Sin <dialog> nativo no hay quick-view: los triggers quedan hidden y la
	// card navega a la ficha como siempre (degradación limpia, cero polyfill).
	if ( typeof HTMLDialogElement !== 'function' ) {
		return;
	}

	// Sólo desde 769 px (QuickView::MEDIA). En el teléfono el tema no pinta el
	// botón y la hoja no se aplica: revelarlo ahí sólo lo sacaba del `hidden`
	// para que el CSS lo volviera a esconder. Si la ventana cruza el corte (una
	// tablet que gira), se revelan al entrar.
	var escritorio = window.matchMedia ? window.matchMedia( '(min-width: 769px)' ) : null;

	var openTrigger = null;
	var dialog = null;
	var lockedScrollY = 0;
	var bodyStyleBeforeLock = null;

	function protectCovers( root ) {
		var covers = ( root || document ).querySelectorAll( '.akb-product-cover-wrap, .akb-qv__media' );
		for ( var i = 0; i < covers.length; i += 1 ) {
			var cover = covers[ i ];
			var image = cover.querySelector( 'img' );
			if ( ! image ) {
				cover.classList.add( 'akb-cover--error' );
				continue;
			}

			// El slot del modal se reutiliza entre aperturas. Sin reconciliar la
			// clase aquí, abrir primero un producto sin portada y luego uno válido
			// dejaba el segundo opaco aunque su imagen ya estuviera cargada.
			if ( image.complete ) {
				cover.classList.toggle( 'akb-cover--error', 0 === image.naturalWidth );
			} else {
				cover.classList.remove( 'akb-cover--error' );
			}
			// Propiedad de runtime, no atributo: cloneNode() no copia listeners ni
			// propiedades propias, así que la portada clonada del modal recibe su
			// guard independiente aunque la card ya estuviera protegida.
			if ( image.akbCoverGuarded ) {
				continue;
			}

			image.akbCoverGuarded = true;
			image.addEventListener( 'error', function ( event ) {
				var wrapper = event.currentTarget.closest( '.akb-product-cover-wrap, .akb-qv__media' );
				if ( wrapper ) {
					wrapper.classList.add( 'akb-cover--error' );
				}
			} );
			image.addEventListener( 'load', function ( event ) {
				var wrapper = event.currentTarget.closest( '.akb-product-cover-wrap, .akb-qv__media' );
				if ( wrapper ) {
					wrapper.classList.remove( 'akb-cover--error' );
				}
			} );
		}
	}

	function lockPageScroll() {
		if ( bodyStyleBeforeLock ) {
			return;
		}

		lockedScrollY = window.scrollY || window.pageYOffset || 0;
		bodyStyleBeforeLock = {
			position: document.body.style.position,
			top: document.body.style.top,
			width: document.body.style.width,
			overflow: document.body.style.overflow
		};
		document.documentElement.classList.add( 'akb-qv-open' );
		document.body.classList.add( 'akb-qv-open' );
		document.body.style.position = 'fixed';
		document.body.style.top = '-' + lockedScrollY + 'px';
		document.body.style.width = '100%';
		document.body.style.overflow = 'hidden';
	}

	function unlockPageScroll() {
		if ( ! bodyStyleBeforeLock ) {
			return;
		}

		document.documentElement.classList.remove( 'akb-qv-open' );
		document.body.classList.remove( 'akb-qv-open' );
		document.body.style.position = bodyStyleBeforeLock.position;
		document.body.style.top = bodyStyleBeforeLock.top;
		document.body.style.width = bodyStyleBeforeLock.width;
		document.body.style.overflow = bodyStyleBeforeLock.overflow;
		bodyStyleBeforeLock = null;
		window.scrollTo( 0, lockedScrollY );
	}

	function focusableElements() {
		if ( ! dialog ) {
			return [];
		}

		return Array.prototype.slice.call(
			dialog.querySelectorAll( 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])' )
		).filter( function ( element ) {
			return ! element.hasAttribute( 'hidden' ) && null !== element.offsetParent;
		} );
	}

	function trapFocus( event ) {
		if ( 'Tab' !== event.key || ! dialog || ! dialog.open ) {
			return;
		}

		var focusable = focusableElements();
		if ( 0 === focusable.length ) {
			event.preventDefault();
			dialog.focus();
			return;
		}

		var first = focusable[ 0 ];
		var last = focusable[ focusable.length - 1 ];
		if ( event.shiftKey && ( document.activeElement === first || document.activeElement === dialog ) ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	function revealTriggers( root ) {
		if ( ! escritorio || escritorio.matches ) {
			var triggers = ( root || document ).querySelectorAll( '.akb-qv-trigger[hidden]' );
			for ( var i = 0; i < triggers.length; i += 1 ) {
				triggers[ i ].removeAttribute( 'hidden' );
			}
		}
		// Las portadas rotas de las tarjetas se marcan en todos los anchos.
		protectCovers( root );
	}

	function ensureDialog() {
		if ( dialog ) {
			return dialog;
		}
		dialog = document.createElement( 'dialog' );
		dialog.className = 'akb-qv';
		dialog.setAttribute( 'aria-labelledby', 'akb-qv-title' );
		dialog.setAttribute( 'aria-describedby', 'akb-qv-synopsis' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'tabindex', '-1' );
		dialog.innerHTML =
			'<div class="akb-qv__head">' +
				'<h2 class="akb-qv__title" id="akb-qv-title"></h2>' +
				'<button type="button" class="akb-qv__close" aria-label="Cerrar vista rápida">' +
					'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true" focusable="false"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>' +
				'</button>' +
			'</div>' +
			'<div class="akb-qv__body">' +
				'<div class="akb-qv__media"></div>' +
				'<div class="akb-qv__info">' +
					'<div class="akb-qv__meta"></div>' +
					'<p class="akb-qv__synopsis" id="akb-qv-synopsis"></p>' +
					'<div class="akb-qv__actions"></div>' +
					'<a class="akb-qv__link" href="#">Ver ficha completa</a>' +
				'</div>' +
			'</div>';
		document.body.appendChild( dialog );

		dialog.querySelector( '.akb-qv__close' ).addEventListener( 'click', function () {
			dialog.close();
		} );

		// Tap/click en el backdrop cierra (el propio <dialog> es el target ahí).
		dialog.addEventListener( 'mousedown', function ( event ) {
			if ( event.target === dialog ) {
				dialog.close();
			}
		} );

		dialog.addEventListener( 'keydown', trapFocus );

		dialog.addEventListener( 'close', function () {
			unlockPageScroll();
			if ( openTrigger && typeof openTrigger.focus === 'function' ) {
				try {
					openTrigger.focus( { preventScroll: true } );
				} catch ( focusError ) {
					void focusError;
					openTrigger.focus();
				}
			}
			openTrigger = null;
		} );

		return dialog;
	}

	function fill( selector, node ) {
		var slot = dialog.querySelector( selector );
		slot.textContent = '';
		if ( node ) {
			slot.appendChild( node );
		}
		return slot;
	}

	function openFromTrigger( trigger ) {
		var card = trigger.closest( 'li.product' );
		if ( ! card ) {
			return;
		}

		var data;
		try {
			data = JSON.parse( trigger.getAttribute( 'data-akb-qv' ) || '{}' );
		} catch ( parseError ) {
			void parseError;
			return;
		}

		ensureDialog();

		dialog.querySelector( '.akb-qv__title' ).textContent = data.title || '';
		dialog.querySelector( '.akb-qv__synopsis' ).textContent = data.synopsis || '';

		var link = dialog.querySelector( '.akb-qv__link' );
		link.href = data.url || card.querySelector( 'a' ).href;

		// Cover: clon de la imagen ya cargada en la card (mismo srcset AVIF).
		var img = card.querySelector( '.akb-product-cover-wrap img' );
		fill( '.akb-qv__media', img ? img.cloneNode( true ) : null );
		protectCovers( dialog );

		// Clonar superficialmente badge, editorial, precio, disponibilidad y ETA desde la tarjeta. Los
		// selectores deben coincidir con los renderers: .akb-badge (LoopEnhancer),
		// .akb-editorial-eyebrow (theme inc/title-parser.php), .akb-eta-loop
		// (EstimatedDelivery\BannerRenderer) y .akb-avail-hint (LoopEnhancer). Excluir el placeholder
		// vacío para no reservar un hueco sin contenido dentro del sheet.
		var meta = fill( '.akb-qv__meta', null );
		var pieces = card.querySelectorAll(
			'.akb-badge, .akb-editorial-eyebrow, .price, .akb-avail-hint:not(.akb-avail-hint--empty), .akb-eta-loop'
		);
		for ( var i = 0; i < pieces.length; i += 1 ) {
			meta.appendChild( pieces[ i ].cloneNode( true ) );
		}

		// CTA: clon del botón/enlace de compra de la card. El add-to-cart AJAX
		// de Woo escucha por delegación en document, así que el clon compra
		// igual que el original; el sheet se cierra al confirmarse (evento
		// added_to_cart de Woo, ver abajo).
		//
		// .akb-notify-link agregado: en las cards agotadas el CTA es ese enlace
		// («Avísame cuando vuelva», LoopEnhancer::notify_link_html) y NO lleva
		// data-product_id, así que ninguno de los selectores previos lo alcanzaba —
		// el slot de acciones del sheet quedaba VACÍO justo en el estado donde el
		// único paso útil es apuntarse al aviso.
		var cta = card.querySelector( '.add_to_cart_button, .ajax_add_to_cart, .button[data-product_id], .akb-notify-link' );
		fill( '.akb-qv__actions', cta ? cta.cloneNode( true ) : null );

		openTrigger = trigger;
		lockPageScroll();
		dialog.showModal();
		dialog.querySelector( '.akb-qv__close' ).focus();
	}

	document.addEventListener( 'click', function ( event ) {
		var trigger = event.target.closest ? event.target.closest( '.akb-qv-trigger' ) : null;
		if ( ! trigger ) {
			return;
		}
		event.preventDefault();
		openFromTrigger( trigger );
	} );

	// Compra confirmada desde el sheet → cerrar para mostrar el drawer/aviso.
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'added_to_cart', function () {
			if ( dialog && dialog.open ) {
				dialog.close();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			revealTriggers( document );
		} );
	} else {
		revealTriggers( document );
	}

	// El catálogo filtra por PJAX (akb-search.js reemplaza la grilla y emite
	// `akb:pjax` post-swap — el mismo contrato que ya consumen main.js y
	// animations.js). Los triggers recién llegados vienen `hidden` del server;
	// sin esto quedaban invisibles tras CUALQUIER filtro/búsqueda y la feature
	// desaparecía en silencio (reproducido en Docker: 24 visibles → 0).
	document.addEventListener( 'akb:pjax', function () {
		revealTriggers( document );
	} );

	if ( escritorio ) {
		var alCruzar = function ( event ) {
			if ( event.matches ) {
				revealTriggers( document );
			}
		};
		if ( typeof escritorio.addEventListener === 'function' ) {
			escritorio.addEventListener( 'change', alCruzar );
		} else if ( typeof escritorio.addListener === 'function' ) {
			escritorio.addListener( alCruzar );
		}
	}
})();
