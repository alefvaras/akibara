/**
 * Akibara Home Hero — admin UI controller.
 *
 * Funcionalidades:
 *   1. Media picker (wp.media) por slot desktop/mobile.
 *   2. Validador de dimensiones al seleccionar imagen.
 *   3. Focal point picker (click en preview → object-position %).
 *   4. WP color pickers (título, lede).
 *   5. Repeater: añadir / eliminar / duplicar / mover (↑↓) slides.
 *   6. Repeater CTAs por slide.
 *   7. Visibility toggle de slide (is_active).
 *   8. Height preset radio cards (visual feedback + custom row toggle).
 *   9. "Editar imagen" abre attach edit en nueva pestaña.
 *
 * Config inyectado por PHP via wp_localize_script en `akbHeroAdmin`.
 *
 * @package akibara-business
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.akbHeroAdmin === 'undefined' ) { return; }
	if ( typeof window.wp === 'undefined' || typeof window.wp.media === 'undefined' ) { return; }

	var cfg = window.akbHeroAdmin;

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers de dimensiones
	// ─────────────────────────────────────────────────────────────────────────

	function ratio( w, h ) {
		if ( ! w || ! h ) { return 0; }
		return w / h;
	}

	function pctDiff( a, b ) {
		if ( ! b ) { return 100; }
		return Math.abs( ( a - b ) / b ) * 100;
	}

	function checkDimensions( kind, w, h ) {
		var recW = kind === 'desktop' ? cfg.rec.desktop_w : cfg.rec.mobile_w;
		var recH = kind === 'desktop' ? cfg.rec.desktop_h : cfg.rec.mobile_h;
		var tol  = cfg.rec.tolerance_pct;

		if ( ! w || ! h ) {
			return { level: 'warn', msg: 'No se pudo leer la dimensión de esta imagen.' };
		}

		var recRatio = ratio( recW, recH );
		var imgRatio = ratio( w, h );
		var diff     = pctDiff( imgRatio, recRatio );

		if ( w === recW && h === recH ) {
			return { level: 'ok', msg: cfg.i18n.okDims.replace( '%tw', w ).replace( '%th', h ) };
		}

		if ( diff <= tol ) {
			return {
				level: 'ok',
				msg: cfg.i18n.okDims.replace( '%tw', w ).replace( '%th', h ) +
					' Aspect ratio dentro de la tolerancia (±' + tol + ' %).'
			};
		}

		var ratioStr = ( Math.round( recRatio * 100 ) / 100 ) + ':1';
		return {
			level: 'warn',
			msg: cfg.i18n.warnDimsAspect
				.replace( '%ratio', ratioStr )
				.replace( '%w', recW ).replace( '%h', recH )
				.replace( '%tw', w ).replace( '%th', h )
		};
	}

	function setHint( $media, level, msg ) {
		var $hint = $media.find( '[data-media-hint]' ).first();
		$hint.removeClass( 'is-ok is-warn is-error' );
		if ( ! level ) { $hint.text( '' ); return; }
		$hint.addClass( 'is-' + level ).text( msg );
	}

	function refreshDimsHint( $media ) {
		var kind = $media.attr( 'data-media' );
		var w = parseInt( $media.find( '[data-media-dims-w]' ).val(), 10 ) || 0;
		var h = parseInt( $media.find( '[data-media-dims-h]' ).val(), 10 ) || 0;
		if ( ! w && ! h ) { setHint( $media, '', '' ); return; }
		var result = checkDimensions( kind, w, h );
		setHint( $media, result.level, result.msg );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Focal point picker
	// ─────────────────────────────────────────────────────────────────────────

	function updateFocalPin( $preview ) {
		var x = parseFloat( $preview.attr( 'data-focal-x' ) );
		var y = parseFloat( $preview.attr( 'data-focal-y' ) );
		if ( isNaN( x ) ) x = 50;
		if ( isNaN( y ) ) y = 50;
		var $pin = $preview.find( '[data-focal-pin]' );
		$pin.css( { left: x + '%', top: y + '%' } );
	}

	function setFocal( $preview, x, y ) {
		x = Math.max( 0, Math.min( 100, Math.round( x ) ) );
		y = Math.max( 0, Math.min( 100, Math.round( y ) ) );
		$preview.attr( 'data-focal-x', x );
		$preview.attr( 'data-focal-y', y );
		updateFocalPin( $preview );

		// Persist into hidden input. trigger('change') propaga al binding del
		// slide → la mini-preview re-encuadra al instante (sin él, .val() es
		// mudo y el focal solo se veía tras guardar).
		var $media = $preview.closest( '.akb-hero-media' );
		var $input = $media.find( '[data-objpos]' );
		if ( $input.length ) {
			$input.val( x + '% ' + y + '%' ).trigger( 'change' );
		}

		updateTabletHint( $media );
	}

	// Hint del encuadre tablet: visible si el focal mobile se capa (y > cap,
	// única fuente del número: PHP vía localize) o si hay override manual.
	// Solo COMPARA — la derivación real vive en Settings (PHP).
	function updateTabletHint( $media ) {
		if ( $media.attr( 'data-media' ) !== 'mobile' ) { return; }
		var $hint = $media.find( '[data-tablet-hint]' );
		if ( ! $hint.length ) { return; }

		var cap    = parseInt( cfg.tabletFocalYCap, 10 ) || 50;
		var y      = parseFloat( $media.find( '.akb-hero-media__preview' ).attr( 'data-focal-y' ) );
		if ( isNaN( y ) ) { y = 50; }
		var manual = ( $media.closest( '.akb-hero-slide' ).find( '[data-objpos-tablet]' ).val() || '' ).trim() !== '';

		$hint.text( $hint.attr( manual ? 'data-manual-text' : 'data-auto-text' ) || '' );
		$hint.prop( 'hidden', ! manual && y <= cap );
	}

	function bindFocalPicker( $preview ) {
		// Initial pin sync from data-attrs
		updateFocalPin( $preview );

		// Click handler — calcular % relativo
		$preview.off( 'click.akbfocal' ).on( 'click.akbfocal', function ( e ) {
			if ( $preview.hasClass( 'is-empty' ) ) { return; }
			// Ignore clicks on buttons / interactive children
			if ( e.target && e.target.tagName === 'BUTTON' ) { return; }

			var rect = this.getBoundingClientRect();
			var x = ( ( e.clientX - rect.left ) / rect.width ) * 100;
			var y = ( ( e.clientY - rect.top  ) / rect.height ) * 100;
			setFocal( $preview, x, y );
		} );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Media picker
	// ─────────────────────────────────────────────────────────────────────────

	function openPicker( $media ) {
		var kind  = $media.attr( 'data-media' );
		var title = kind === 'desktop' ? cfg.i18n.pickerTitleDesk : cfg.i18n.pickerTitleMob;

		var frame = wp.media( {
			title:    title,
			multiple: false,
			library:  { type: 'image' },
			button:   { text: cfg.i18n.pickImage }
		} );

		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			if ( ! att || ! att.id ) { return; }

			$media.find( '[data-media-input]' ).val( att.id );
			$media.find( '[data-media-dims-w]' ).val( att.width  || 0 );
			$media.find( '[data-media-dims-h]' ).val( att.height || 0 );

			var thumbUrl = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;

			var $preview = $media.find( '.akb-hero-media__preview' );
			$preview.removeClass( 'is-empty' );
			$preview.html(
				'<img src="' + thumbUrl + '" alt="" data-media-img>' +
				'<span class="akb-hero-media__focal" data-focal-pin aria-hidden="true"></span>'
			);
			updateFocalPin( $preview );

			$media.find( '[data-media-pick]' ).text( 'Cambiar' );

			// Show edit attachment link
			var $edit = $media.find( '[data-media-edit]' );
			$edit.removeAttr( 'hidden' );
			$edit.attr( 'href', cfg.editAttachmentUrl + '?post=' + att.id + '&action=edit' );

			refreshDimsHint( $media );

			// Capa 3: notificar a la mini-preview que cambió la imagen
			$media.closest( '.akb-hero-slide' ).trigger( 'akb:media-changed' );
		} );

		frame.open();
	}

	function clearMedia( $media ) {
		$media.find( '[data-media-input]' ).val( 0 );
		$media.find( '[data-media-dims-w]' ).val( 0 );
		$media.find( '[data-media-dims-h]' ).val( 0 );

		var $preview = $media.find( '.akb-hero-media__preview' );
		$preview.addClass( 'is-empty' );
		$preview.html( '<span class="akb-hero-media__placeholder" data-media-img>Sin imagen</span>' );

		$media.find( '[data-media-pick]' ).text( 'Seleccionar' );
		$media.find( '[data-media-edit]' ).attr( 'hidden', 'hidden' ).attr( 'href', '#' );

		setHint( $media, '', '' );

		// Capa 3: notificar a la mini-preview
		$media.closest( '.akb-hero-slide' ).trigger( 'akb:media-changed' );
	}

	function openAttachmentEdit( $media ) {
		var attId = parseInt( $media.find( '[data-media-input]' ).val(), 10 );
		if ( ! attId ) { return; }
		var url = cfg.editAttachmentUrl + '?post=' + attId + '&action=edit';
		window.open( url, '_blank', 'noopener' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Slide repeater
	// ─────────────────────────────────────────────────────────────────────────

	function reindexSlides( $container ) {
		var $slides = $container.find( '.akb-hero-slide' );
		$slides.each( function ( idx ) {
			var $slide = $( this );
			$slide.attr( 'data-slide-idx', idx );
			$slide.find( '[data-slide-number]' ).text( idx + 1 );

			$slide.find( 'input, select, textarea' ).each( function () {
				var $el  = $( this );
				var name = $el.attr( 'name' );
				if ( ! name ) { return; }
				var newName = name.replace( /^slides\[\d+\]/, 'slides[' + idx + ']' );
				newName     = newName.replace( /^slides\[__INDEX__\]/, 'slides[' + idx + ']' );
				$el.attr( 'name', newName );
			} );
		} );
		$( '[data-slide-count]' ).text( $slides.length );
	}

	function addSlide( payload ) {
		var $container = $( '[data-slides]' );
		var current    = $container.find( '.akb-hero-slide' ).length;
		if ( current >= cfg.maxSlides ) {
			window.alert( cfg.i18n.maxSlidesReached.replace( '%max', cfg.maxSlides ) );
			return null;
		}
		$container.find( '.akb-hero-admin__empty' ).remove();

		var html = cfg.slideTemplate.replace( /__INDEX__/g, current );
		$container.append( html );
		var $newSlide = $container.find( '.akb-hero-slide' ).last();

		reindexSlides( $container );
		bindNewSlide( $newSlide );

		return $newSlide;
	}

	function removeSlide( $slide ) {
		// Modal <dialog> nativo en lugar de window.confirm() — bloquea contenido
		// principal con backdrop, focus trap automático, ESC cierra. Si el browser
		// no soporta <dialog> caemos al confirm() viejo.
		var doRemove = function () {
			var $container = $slide.parent();
			$slide.remove();
			reindexSlides( $container );
			if ( $container.find( '.akb-hero-slide' ).length === 0 ) {
				$container.append( '<p class="akb-hero-admin__empty">No hay slides configurados todavía. Haz clic en "Agregar slide" para comenzar.</p>' );
			}
		};

		var modal = document.querySelector( '[data-confirm-modal]' );
		if ( ! modal || typeof modal.showModal !== 'function' ) {
			if ( window.confirm( cfg.i18n.confirmRemove ) ) { doRemove(); }
			return;
		}

		// Listener single-shot — se limpia tras close, sin acumular.
		var onClose = function () {
			modal.removeEventListener( 'close', onClose );
			if ( modal.returnValue === 'confirm' ) {
				doRemove();
			}
		};
		modal.addEventListener( 'close', onClose );
		modal.returnValue = '';
		modal.showModal();
	}

	function moveSlide( $slide, dir ) {
		var $container = $slide.parent();
		if ( dir === 'up' ) {
			var $prev = $slide.prev( '.akb-hero-slide' );
			if ( $prev.length ) { $slide.insertBefore( $prev ); }
		} else if ( dir === 'down' ) {
			var $next = $slide.next( '.akb-hero-slide' );
			if ( $next.length ) { $slide.insertAfter( $next ); }
		}
		reindexSlides( $container );
	}

	function duplicateSlide( $slide ) {
		// Snapshot separated by input type. Radios necesitan tratamiento especial:
		// múltiples inputs comparten key pero tienen distintos values, y queremos
		// preservar el `checked`, no el `value` attribute (este último identifica
		// el slot del radio group, no el dato del owner).
		var textValues = {}; // text, url, number, textarea, select — by key
		var checkboxes = {}; // checkboxes — by key, value=bool
		var radios     = {}; // radios — by key, value=string del radio activo

		$slide.find( 'input, select, textarea' ).each( function () {
			var $el  = $( this );
			var name = $el.attr( 'name' );
			if ( ! name ) { return; }
			var key  = name.replace( /^slides\[\d+\]/, '' );
			var type = $el.attr( 'type' );

			if ( type === 'radio' ) {
				if ( $el.is( ':checked' ) ) { radios[ key ] = $el.val(); }
			} else if ( type === 'checkbox' ) {
				checkboxes[ key ] = $el.is( ':checked' );
			} else {
				textValues[ key ] = $el.val();
			}
		} );

		// Also CTAs HTML structure
		var ctasHtml = $slide.find( '.akb-hero-ctas' ).html() || '';

		// Snapshot data-media-dims para que el preview del clon mantenga las dimensiones
		var mediaDims = {};
		$slide.find( '.akb-hero-media' ).each( function () {
			var kind = $( this ).attr( 'data-media' );
			mediaDims[ kind ] = {
				w: $( this ).find( '[data-media-dims-w]' ).val(),
				h: $( this ).find( '[data-media-dims-h]' ).val()
			};
		} );

		var $newSlide = addSlide();
		if ( ! $newSlide ) { return; }

		// Inject CTAs HTML
		if ( ctasHtml ) {
			$newSlide.find( '.akb-hero-ctas' ).html( ctasHtml );
			reindexCtas( $newSlide );
		}

		// Re-apply values con el tratamiento correcto por tipo
		$newSlide.find( 'input, select, textarea' ).each( function () {
			var $el  = $( this );
			var name = $el.attr( 'name' );
			if ( ! name ) { return; }
			var key  = name.replace( /^slides\[\d+\]/, '' );
			var type = $el.attr( 'type' );

			if ( type === 'radio' ) {
				if ( radios.hasOwnProperty( key ) ) {
					$el.prop( 'checked', $el.val() === radios[ key ] );
				}
			} else if ( type === 'checkbox' ) {
				if ( checkboxes.hasOwnProperty( key ) ) {
					$el.prop( 'checked', !! checkboxes[ key ] );
				}
			} else if ( textValues.hasOwnProperty( key ) ) {
				$el.val( textValues[ key ] );
			}
		} );

		// Re-bind / re-init dependent UI: focal pins, color pickers, dims hint
		$newSlide.find( '.akb-hero-media' ).each( function () {
			var $media   = $( this );
			var $preview = $media.find( '.akb-hero-media__preview' );
			var attId    = parseInt( $media.find( '[data-media-input]' ).val(), 10 );

			// If has attach ID, copy image preview from source slide
			if ( attId > 0 ) {
				var $srcMedia = $slide.find( '.akb-hero-media[data-media="' + $media.attr( 'data-media' ) + '"]' );
				var srcImgHtml = $srcMedia.find( '.akb-hero-media__preview' ).html();
				$preview.removeClass( 'is-empty' ).html( srcImgHtml );
				$media.find( '[data-media-edit]' ).removeAttr( 'hidden' ).attr(
					'href', cfg.editAttachmentUrl + '?post=' + attId + '&action=edit'
				);
				$media.find( '[data-media-pick]' ).text( 'Cambiar' );
			}

			// Si hay dims en el snapshot, restaurarlas para que no diga "0x0"
			var kind = $media.attr( 'data-media' );
			if ( mediaDims[ kind ] ) {
				$media.find( '[data-media-dims-w]' ).val( mediaDims[ kind ].w );
				$media.find( '[data-media-dims-h]' ).val( mediaDims[ kind ].h );
			}

			// Re-sync focal pin from input value
			var objpos = $media.find( '[data-objpos]' ).val() || '50% 50%';
			var coords = parseObjpos( objpos );
			$preview.attr( 'data-focal-x', coords[0] ).attr( 'data-focal-y', coords[1] );
			updateFocalPin( $preview );
			refreshDimsHint( $media );
			updateTabletHint( $media );
		} );

		// Re-init color pickers
		$newSlide.find( '.akb-color-picker' ).each( function () {
			initColorPicker( $( this ) );
		} );

		// Re-apply visibility class
		applyVisibilityState( $newSlide );

		// Trigger change en radios checked para que los handlers visuales sintetizen
		// la clase is-active sobre las cells del position grid, pills, etc.
		$newSlide.find( 'input[type="radio"]:checked' ).trigger( 'change' );

		// Scroll into view
		try { $newSlide[0].scrollIntoView( { behavior: 'smooth', block: 'center' } ); } catch ( _e ) {}
	}

	function parseObjpos( str ) {
		var tokens = ( str || '' ).split( /\s+/ );
		var kw = { left: 0, center: 50, right: 100, top: 0, middle: 50, bottom: 100 };
		var resolve = function ( token, def ) {
			token = ( token || '' ).toLowerCase().trim();
			if ( kw.hasOwnProperty( token ) ) return kw[ token ];
			var m = token.match( /^(\d+(?:\.\d+)?)%?$/ );
			if ( m ) return Math.max( 0, Math.min( 100, parseFloat( m[1] ) ) );
			return def;
		};
		return [ resolve( tokens[0], 50 ), resolve( tokens[1], 50 ) ];
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Visibility toggle
	// ─────────────────────────────────────────────────────────────────────────

	function applyVisibilityState( $slide ) {
		var $cb = $slide.find( '[data-slide-toggle]' );
		var on  = $cb.is( ':checked' );
		$slide.toggleClass( 'is-on', on ).toggleClass( 'is-off', ! on );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Color picker
	// ─────────────────────────────────────────────────────────────────────────

	function initColorPicker( $input ) {
		if ( typeof $input.wpColorPicker !== 'function' ) { return; }
		if ( $input.data( 'akbWpcInit' ) ) { return; }
		$input.data( 'akbWpcInit', true );

		var defaultColor = $input.data( 'default-color' ) || '';

		$input.wpColorPicker( {
			defaultColor: defaultColor,
			change: function () {
				$input.trigger( 'change' );
			},
			clear: function () {
				$input.trigger( 'change' );
			},
		} );

		// Si el campo está vacío, muestra default-color en el swatch para comunicar que el selector de
		// color sigue disponible.
		if ( ! $input.val() && defaultColor ) {
			var $swatch = $input.closest( '.wp-picker-container' ).find( '.wp-color-result' );
			if ( $swatch.length ) {
				$swatch.css( 'background-color', defaultColor );
				$swatch.find( '.wp-color-result-text' ).text( 'Cambiar color' );
			}
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Height presets
	// ─────────────────────────────────────────────────────────────────────────

	function bindHeightPresets() {
		var $radios = $( '[data-height-radio]' );
		$radios.on( 'change', function () {
			var val = $( this ).val();
			$radios.each( function () {
				$( this ).closest( '.akb-hero-height__option' ).toggleClass( 'is-active', $( this ).is( ':checked' ) );
			} );
			var $customRow = $( '.akb-hero-height__custom-row' );
			if ( 'custom' === val ) { $customRow.removeAttr( 'hidden' ); }
			else { $customRow.attr( 'hidden', 'hidden' ); }
		} );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Position grid + alignment pills + mobile layout pills (per slide)
	// ─────────────────────────────────────────────────────────────────────────

	function bindPositionPicker( $slide ) {
		// Grid 3×3: la radio activa actualiza la clase is-active de su cell.
		$slide.find( '[data-position-radio]' ).on( 'change.akbhero', function () {
			var $group = $( this ).closest( '.akb-position-grid' );
			$group.find( '.akb-position-grid__cell' ).removeClass( 'is-active' );
			$( this ).closest( '.akb-position-grid__cell' ).addClass( 'is-active' );
		} );

		// Click sobre la cell entera (no solo el dot) activa el radio.
		$slide.find( '.akb-position-grid__cell' ).on( 'click.akbhero', function ( e ) {
			if ( e.target.tagName !== 'INPUT' ) {
				var $input = $( this ).find( 'input' );
				if ( $input.length ) { $input.prop( 'checked', true ).trigger( 'change' ); }
			}
		} );

		// Pills (align + mobile layout): mismo patrón.
		$slide.find( '[data-align-radio], [data-mlayout-radio]' ).on( 'change.akbhero', function () {
			var $pill  = $( this ).closest( '.akb-hero-position__pill' );
			var $group = $pill.parent();
			$group.find( '.akb-hero-position__pill' ).removeClass( 'is-active' );
			$pill.addClass( 'is-active' );
		} );

		$slide.find( '.akb-hero-position__pill' ).on( 'click.akbhero', function ( e ) {
			if ( e.target.tagName !== 'INPUT' ) {
				var $input = $( this ).find( 'input' );
				if ( $input.length ) { $input.prop( 'checked', true ).trigger( 'change' ); }
			}
		} );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// CTA repeater
	// ─────────────────────────────────────────────────────────────────────────

	function reindexCtas( $slide ) {
		var slideIdx = parseInt( $slide.attr( 'data-slide-idx' ), 10 ) || 0;
		var $ctas    = $slide.find( '.akb-hero-cta' );
		$ctas.each( function ( cidx ) {
			var $cta = $( this );
			$cta.attr( 'data-cta-idx', cidx );
			$cta.find( 'input, select' ).each( function () {
				var $el  = $( this );
				var name = $el.attr( 'name' );
				if ( ! name ) { return; }
				var newName = name.replace( /^slides\[\d+\]\[ctas\]\[\d+\]/, 'slides[' + slideIdx + '][ctas][' + cidx + ']' );
				newName     = newName.replace( /^slides\[__SLIDE__\]\[ctas\]\[__CTA__\]/, 'slides[' + slideIdx + '][ctas][' + cidx + ']' );
				$el.attr( 'name', newName );
			} );
		} );
	}

	function addCta( $slide ) {
		var $ctas = $slide.find( '.akb-hero-ctas' );
		var count = $ctas.find( '.akb-hero-cta' ).length;
		if ( count >= cfg.maxCtas ) {
			window.alert( cfg.i18n.maxCtasReached.replace( '%max', cfg.maxCtas ) );
			return;
		}
		var slideIdx = parseInt( $slide.attr( 'data-slide-idx' ), 10 ) || 0;
		var html     = cfg.ctaTemplate
			.replace( /__SLIDE__/g, slideIdx )
			.replace( /__CTA__/g, count );
		$ctas.append( html );
		reindexCtas( $slide );
	}

	function removeCta( $cta ) {
		var $slide = $cta.closest( '.akb-hero-slide' );
		$cta.remove();
		reindexCtas( $slide );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Bindings per slide
	// ─────────────────────────────────────────────────────────────────────────

	function bindNewSlide( $slide ) {
		$slide.find( '.akb-hero-media' ).each( function () {
			var $media   = $( this );
			var $preview = $media.find( '.akb-hero-media__preview' );

			$media.find( '[data-media-pick]' ).off( 'click.akbhero' ).on( 'click.akbhero', function ( e ) {
				e.preventDefault();
				openPicker( $media );
			} );

			$media.find( '[data-media-clear]' ).off( 'click.akbhero' ).on( 'click.akbhero', function ( e ) {
				e.preventDefault();
				clearMedia( $media );
			} );

			$media.find( '[data-media-edit]' ).off( 'click.akbhero' ).on( 'click.akbhero', function ( e ) {
				e.preventDefault();
				openAttachmentEdit( $media );
			} );

			bindFocalPicker( $preview );
			refreshDimsHint( $media );
		} );

		// Visibility toggle
		$slide.find( '[data-slide-toggle]' ).off( 'change.akbhero' ).on( 'change.akbhero', function () {
			applyVisibilityState( $slide );
		} );
		applyVisibilityState( $slide );

		// Color pickers
		$slide.find( '.akb-color-picker' ).each( function () {
			initColorPicker( $( this ) );
		} );

		// Position grid + alignment + mobile layout pickers
		bindPositionPicker( $slide );

		// Mini-preview en vivo (capa 1)
		bindMiniPreview( $slide );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Mini-preview live update (sin AJAX, JS-only DOM manipulation)
	// ─────────────────────────────────────────────────────────────────────────

	function readField( $slide, key ) {
		var $el = $slide.find( '[name$="[' + key + ']"]' ).filter( ':not([type="radio"])' ).first();
		return $el.length ? String( $el.val() || '' ) : '';
	}

	// Heurística "color claro" para alertar de baja legibilidad sobre vignette
	// transparente. Cubre los inputs típicos: vacío (default render = #fff),
	// hex (#abc / #aabbcc / #aabbccdd) — calcula luminancia relativa simple — y
	// keywords frecuentes. Para functional notation y var() retorna false
	// (no warn, evitamos falsos positivos).
	function isLightColor( color ) {
		if ( ! color ) { return true; }
		var raw = String( color ).trim().toLowerCase();
		if ( '' === raw ) { return true; }
		if ( 'white' === raw || 'transparent' === raw ) { return true; }
		var m = raw.match( /^#([0-9a-f]{3,8})$/ );
		if ( m ) {
			var hex = m[1];
			if ( 3 === hex.length || 4 === hex.length ) {
				hex = hex.split( '' ).map( function ( c ) { return c + c; } ).join( '' );
			}
			if ( hex.length >= 6 ) {
				var r = parseInt( hex.substr( 0, 2 ), 16 );
				var g = parseInt( hex.substr( 2, 2 ), 16 );
				var b = parseInt( hex.substr( 4, 2 ), 16 );
				// Luminancia perceptual aproximada (Rec. 709).
				var lum = ( 0.2126 * r + 0.7152 * g + 0.0722 * b ) / 255;
				return lum > 0.6;
			}
		}
		return false;
	}

	// Muestra/oculta warning de legibilidad cuando vignette < 30 + título claro.
	// El cap 30 es heurístico: bajo ese umbral el gradient bottom-left aporta
	// poco contraste sobre imágenes claras (cielos, papel manga).
	function updateVignetteWarning( $slide, vignette, titleColor ) {
		var $warn = $slide.find( '[data-vignette-warning]' );
		if ( ! $warn.length ) { return; }

		var show = ( vignette < 30 ) && isLightColor( titleColor );
		if ( show ) {
			$warn.text( '⚠ Viñeta baja + título claro: si la imagen tiene zonas claras, el texto puede ser ilegible. Sube la viñeta a 30 o más, o usa un color de título más oscuro.' )
				.prop( 'hidden', false );
		} else {
			$warn.prop( 'hidden', true ).text( '' );
		}
	}

	// Ancho/alto simulado del iframe fiel por device. El wrap lo escala para
	// caber en la columna de preview (~290px). Alto generoso: el hero mobile/
	// tablet alto (imagen + texto) necesita ~800-900px para verse entero.
	var MINI_DEVICE_W = { desktop: 1280, tablet: 744, mobile: 390 };
	var MINI_DEVICE_H = { desktop: 700, tablet: 940, mobile: 840 };
	var miniTimers = {};

	/**
	 * Mini-preview FIEL: en lugar de imitar el hero con un card en JS, renderiza
	 * el slide con el MISMO motor que el sitio (ajax_preview → Settings::sanitize
	 * → Renderer::render_from_settings). Debounced para no pegarle al endpoint en
	 * cada tecla. El warning de legibilidad se mantiene instantáneo (no depende
	 * del render). Único source of truth = admin y front no pueden divergir.
	 */
	function updateMiniPreview( $slide ) {
		var vignette = parseInt( readField( $slide, 'vignette_opacity' ), 10 );
		if ( isNaN( vignette ) ) { vignette = 50; }
		updateVignetteWarning( $slide, vignette, readField( $slide, 'title_color' ) );

		var idx = $slide.attr( 'data-slide-idx' );
		if ( idx === undefined || idx === false ) { idx = String( $slide.index() ); }
		clearTimeout( miniTimers[ idx ] );
		miniTimers[ idx ] = setTimeout( function () { renderMiniPreviewFaithful( $slide ); }, 400 );
	}

	function applyMiniDevice( $slide, device ) {
		var $wrap  = $slide.find( '[data-preview-frame-wrap]' );
		var $frame = $slide.find( '[data-preview-frame]' );
		var w = MINI_DEVICE_W[ device ] || 1280;
		var h = MINI_DEVICE_H[ device ] || 700;
		var wrapW = $wrap.width() || 290;
		var scale = wrapW / w;
		$frame.css( {
			width:  w + 'px',
			height: h + 'px',
			transform: 'scale(' + scale + ')',
			transformOrigin: 'top left'
		} );
		// Reservar el alto escalado para que la columna no colapse.
		$wrap.css( 'height', Math.round( h * scale ) + 'px' );
	}

	function renderMiniPreviewFaithful( $slide ) {
		var $preview = $slide.find( '[data-slide-preview]' );
		var $frame   = $preview.find( '[data-preview-frame]' );
		if ( ! $frame.length ) { return; }
		var device = $preview.find( '[data-preview-device].is-active' ).attr( 'data-preview-device' ) || 'desktop';

		// Serializar SOLO este slide y remapear slides[N] -> slides[0]: el endpoint
		// renderiza un hero de un único slide (el que se edita).
		var data = $slide.find( 'input, select, textarea' ).serialize()
			.replace( /slides%5B\d+%5D/g, 'slides%5B0%5D' );
		// Forzar is_active=1: en edición se necesita ver el slide aunque esté en pausa.
		data = data.replace( /&?slides%5B0%5D%5Bis_active%5D=[^&]*/g, '' );
		data += '&slides%5B0%5D%5Bis_active%5D=1';
		// Un solo slide, sin autoplay (evita el toggle de pausa en la mini).
		data += '&carousel%5Bautoplay%5D=0';
		data += '&action=akb_hero_preview&_ajax_nonce=' + encodeURIComponent( cfg.previewNonce );

		applyMiniDevice( $slide, device );

		$.ajax( { url: cfg.previewAjaxUrl, method: 'POST', data: data, dataType: 'json', timeout: 12000 } )
			.done( function ( resp ) {
				if ( resp && resp.success && resp.data && resp.data.html ) {
					// Swap sin flash: revelar el iframe recién cuando cargó el srcdoc.
					$frame.one( 'load', function () {
						$preview.find( '[data-preview-skeleton]' ).prop( 'hidden', true );
						$frame.prop( 'hidden', false );
						applyMiniDevice( $slide, device );
					} );
					$frame.attr( 'srcdoc', resp.data.html );
				}
			} );
	}

	function bindMiniPreview( $slide ) {
		// Inputs/selects/textareas de este slide → update on input/change
		$slide.off( 'input.akbhero-preview change.akbhero-preview' )
			.on( 'input.akbhero-preview change.akbhero-preview', 'input, select, textarea', function () {
				updateMiniPreview( $slide );
			} );

		// Device toggle — resize inmediato del iframe (sin esperar el debounce)
		// + re-render fiel para que el object-position del device correcto aplique.
		$slide.find( '[data-preview-device]' ).off( 'click.akbhero-preview' ).on( 'click.akbhero-preview', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			$btn.siblings( '[data-preview-device]' )
				.removeClass( 'is-active' )
				.attr( 'aria-selected', 'false' );
			$btn.addClass( 'is-active' ).attr( 'aria-selected', 'true' );
			applyMiniDevice( $slide, $btn.attr( 'data-preview-device' ) );
			updateMiniPreview( $slide );
		} );

		// Custom event para cuando el media frame selecciona/cleara imagen (capa 3)
		$slide.off( 'akb:media-changed.akbhero-preview' )
			.on( 'akb:media-changed.akbhero-preview', function () {
				updateMiniPreview( $slide );
			} );

		// Initial render
		updateMiniPreview( $slide );
	}

	function bindGlobal() {
		var $doc = $( document );

		// Override manual del encuadre tablet → refrescar el hint del picker mobile.
		$doc.on( 'input.akbhero-tablet change.akbhero-tablet', '[data-objpos-tablet]', function () {
			var $media = $( this ).closest( '.akb-hero-slide' ).find( '.akb-hero-media[data-media="mobile"]' );
			if ( $media.length ) {
				updateTabletHint( $media );
			}
		} );

		$doc.on( 'click.akbhero', '[data-action="add-slide"]', function ( e ) {
			e.preventDefault();
			addSlide();
		} );

		$doc.on( 'click.akbhero', '[data-action="remove-slide"]', function ( e ) {
			e.preventDefault();
			removeSlide( $( this ).closest( '.akb-hero-slide' ) );
		} );

		$doc.on( 'click.akbhero', '[data-action="duplicate-slide"]', function ( e ) {
			e.preventDefault();
			duplicateSlide( $( this ).closest( '.akb-hero-slide' ) );
		} );

		$doc.on( 'click.akbhero', '[data-action="move-up"]', function ( e ) {
			e.preventDefault();
			moveSlide( $( this ).closest( '.akb-hero-slide' ), 'up' );
		} );

		$doc.on( 'click.akbhero', '[data-action="move-down"]', function ( e ) {
			e.preventDefault();
			moveSlide( $( this ).closest( '.akb-hero-slide' ), 'down' );
		} );

		$doc.on( 'click.akbhero', '[data-action="add-cta"]', function ( e ) {
			e.preventDefault();
			addCta( $( this ).closest( '.akb-hero-slide' ) );
		} );

		$doc.on( 'click.akbhero', '[data-action="remove-cta"]', function ( e ) {
			e.preventDefault();
			removeCta( $( this ).closest( '.akb-hero-cta' ) );
		} );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Capa 2: Modal full preview (iframe + AJAX render)
	// ─────────────────────────────────────────────────────────────────────────

	function openPreviewModal() {
		var modal = document.querySelector( '[data-preview-modal]' );
		if ( ! modal ) { return; }

		// <dialog> nativo: showModal() gestiona focus trap + ESC + backdrop click.
		if ( typeof modal.showModal === 'function' && ! modal.open ) {
			modal.showModal();
		} else {
			// Fallback browser sin <dialog>: simular con role+aria.
			modal.setAttribute( 'open', '' );
			modal.removeAttribute( 'hidden' );
		}
		document.body.classList.add( 'akb-hero-modal-open' );

		var $modal = $( modal );
		$modal.find( '[data-modal-loading]' ).text( 'Cargando vista previa…' ).show();
		$modal.find( '[data-modal-error]' ).attr( 'hidden', '' ).empty();
		$modal.find( '[data-modal-iframe]' ).attr( 'hidden', '' ).removeAttr( 'srcdoc' );

		loadPreview();
	}

	function closePreviewModal() {
		var modal = document.querySelector( '[data-preview-modal]' );
		if ( ! modal ) { return; }

		if ( typeof modal.close === 'function' && modal.open ) {
			modal.close();
		} else {
			modal.removeAttribute( 'open' );
			modal.setAttribute( 'hidden', '' );
		}
		document.body.classList.remove( 'akb-hero-modal-open' );
		$( modal ).find( '[data-modal-iframe]' ).removeAttr( 'srcdoc' );
	}

	function loadPreview() {
		var $modal   = $( '[data-preview-modal]' );
		var $form    = $( '.akb-hero-admin__form' );
		var $iframe  = $modal.find( '[data-modal-iframe]' );
		var $loading = $modal.find( '[data-modal-loading]' );
		var $error   = $modal.find( '[data-modal-error]' );

		$loading.show().text( 'Cargando vista previa…' );
		$error.attr( 'hidden', '' ).empty();
		$iframe.attr( 'hidden', '' );

		// Serializar form sin enviarlo realmente (no commit BD)
		var formData = $form.serialize();
		formData += '&action=akb_hero_preview&_ajax_nonce=' + encodeURIComponent( cfg.previewNonce );

		$.ajax( {
			url:    cfg.previewAjaxUrl,
			method: 'POST',
			data:   formData,
			dataType: 'json',
			timeout: 15000
		} ).done( function ( resp ) {
			if ( resp && resp.success && resp.data && resp.data.html ) {
				$iframe.attr( 'srcdoc', resp.data.html ).removeAttr( 'hidden' );
				$loading.hide();
				if ( resp.data.empty ) {
					// Iframe muestra el "no hay slides" — no es error pero hint
					$loading.hide();
				}
			} else {
				showError( resp && resp.data && resp.data.message ? resp.data.message : 'Respuesta inválida del servidor.' );
			}
		} ).fail( function ( jqXHR, textStatus, err ) {
			var msg = 'Error al cargar la vista previa.';
			if ( textStatus === 'timeout' ) { msg = 'Tiempo de espera agotado. Intenta recargar.'; }
			else if ( jqXHR.status === 403 ) { msg = 'Sin permisos. Inicia sesión otra vez.'; }
			else if ( jqXHR.status === 0 ) { msg = 'Sin conexión.'; }
			else if ( err ) { msg = msg + ' (' + err + ')'; }
			showError( msg );
		} );

		function showError( msg ) {
			$loading.hide();
			$iframe.attr( 'hidden', '' );
			$error.text( msg ).removeAttr( 'hidden' );
		}
	}

	function applyModalDevice( device ) {
		var $modal  = $( '[data-preview-modal]' );
		var $iframe = $modal.find( '[data-modal-iframe]' );
		var $btns   = $modal.find( '[data-modal-device]' );

		$btns.removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
		$btns.filter( '[data-modal-device="' + device + '"]' ).addClass( 'is-active' ).attr( 'aria-selected', 'true' );

		if ( device === 'mobile' ) {
			$iframe.css( { width: '390px', maxWidth: '100%', height: '844px', maxHeight: 'calc(100vh - 200px)' } );
		} else if ( device === 'tablet' ) {
			// 700×960 = viewport de la banda tablet (601-768). Acá se VERIFICA el
			// encuadre tablet derivado (--akb-hero-objpos-tablet) con el Renderer
			// real — el campo "Encuadre tablet" no tiene preview propia.
			$iframe.css( { width: '700px', maxWidth: '100%', height: '960px', maxHeight: 'calc(100vh - 200px)' } );
		} else {
			$iframe.css( { width: '100%', maxWidth: '100%', height: '700px', maxHeight: 'calc(100vh - 200px)' } );
		}
	}

	function bindModal() {
		var $doc = $( document );

		// Abrir modal
		$doc.on( 'click.akbhero-modal', '[data-action="open-preview-modal"]', function ( e ) {
			e.preventDefault();
			openPreviewModal();
		} );

		// Cerrar modal (overlay click o botón ✕)
		$doc.on( 'click.akbhero-modal', '[data-modal-close]', function ( e ) {
			e.preventDefault();
			closePreviewModal();
		} );

		// Recargar preview
		$doc.on( 'click.akbhero-modal', '[data-modal-reload]', function ( e ) {
			e.preventDefault();
			loadPreview();
		} );

		// Device toggle
		$doc.on( 'click.akbhero-modal', '[data-modal-device]', function ( e ) {
			e.preventDefault();
			applyModalDevice( $( this ).attr( 'data-modal-device' ) );
		} );

		// ESC para cerrar
		$doc.on( 'keydown.akbhero-modal', function ( e ) {
			if ( e.key === 'Escape' ) {
				var $modal = $( '[data-preview-modal]' );
				if ( ! $modal.attr( 'hidden' ) ) {
					closePreviewModal();
				}
			}
		} );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Capa 4: Detectar ?open_site=1 tras "Guardar y abrir en el sitio"
	// ─────────────────────────────────────────────────────────────────────────

	function maybeOpenSiteAfterSave() {
		if ( /[?&]open_site=1\b/.test( window.location.search ) ) {
			// Abrir tab nuevo con cache buster
			var url = '/?bust=' + Date.now();
			window.open( url, '_blank', 'noopener' );

			// Limpiar el query param sin reload (history API)
			if ( window.history && window.history.replaceState ) {
				var clean = window.location.search
					.replace( /([?&])open_site=1(&|$)/, '$1' )
					.replace( /[?&]$/, '' );
				window.history.replaceState(
					{},
					document.title,
					window.location.pathname + clean + window.location.hash
				);
			}
		}
	}

	$( function () {
		bindGlobal();
		bindHeightPresets();
		bindModal();
		$( '.akb-hero-slide' ).each( function () {
			bindNewSlide( $( this ) );
		} );
		maybeOpenSiteAfterSave();
	} );

}( window.jQuery ) );
