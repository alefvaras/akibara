/*
 * Series Bundles — JS de la experiencia de edición en wp-admin.
 *
 * Carga condicional desde Admin\AdminAssets, solo en la pestaña "Bundles de
 * Series" del panel Akibara. Da interactividad a la config de "arma tu pack por
 * serie" (BypConfigController):
 *
 *   1. Tramos dinámicos: añadir/quitar filas de la mini-tabla sin recargar.
 *   2. Filtro en vivo de la lista de series por nombre.
 *   3. Validación preventiva en el cliente (cantidades mínimas duplicadas).
 *
 * Todo degrada con elegancia: sin JS, los tramos quedan con la fila que
 * renderizó PHP y el guardado se valida igual en el servidor.
 */
( function ( $ ) {
	'use strict';

	/**
	 * 1. Tramos dinámicos. Cada tabla de tramos lleva data-base con el prefijo de
	 * name (ej. "series[12]"); las filas se reindexan al añadir/quitar para que
	 * PHP reciba un array contiguo.
	 */
	function reindexTiers( $body, base ) {
		$body.find( '[data-akb-sb-tier-row]' ).each( function ( idx ) {
			$( this ).find( 'input' ).each( function () {
				var $field = $( this );
				// Detecta si es min o pct por el atributo max (pct tiene max=15).
				var key = $field.attr( 'max' ) === '15' ? 'pct' : 'min';
				$field.attr( 'name', base + '[tiers][' + idx + '][' + key + ']' );
			} );
		} );
	}

	function tierRow( base, termId, idx ) {
		var minId = 'akb-sb-tier-min-' + termId + '-' + idx;
		var pctId = 'akb-sb-tier-pct-' + termId + '-' + idx;

		return '<tr data-akb-sb-tier-row>' +
			'<td><label class="screen-reader-text" for="' + minId + '">Cantidad mínima de tomos</label>' +
			'<input type="number" min="2" step="1" id="' + minId + '" class="akb-sb-tier-input akb-sb-tier-input--min" name="' + base + '[tiers][' + idx + '][min]" placeholder="Ej. 5"></td>' +
			'<td><label class="screen-reader-text" for="' + pctId + '">Porcentaje de descuento</label>' +
			'<input type="number" min="0" max="15" step="1" id="' + pctId + '" class="akb-sb-tier-input akb-sb-tier-input--pct" name="' + base + '[tiers][' + idx + '][pct]" placeholder="Ej. 10"></td>' +
			'<td class="akb-sb-tier-table__actions"><button type="button" class="button-link akb-sb-tier-remove" data-akb-sb-tier-remove aria-label="Quitar tramo">&times;</button></td>' +
			'</tr>';
	}

	function initDynamicTiers() {
		// Añadir tramo.
		$( document ).on( 'click', '[data-akb-sb-tier-add]', function ( e ) {
			e.preventDefault();
			var base = $( this ).data( 'base' );
			var $table = $( this ).closest( '.akb-accordion__body' ).find( '[data-akb-sb-tiers]' );
			var $body = $table.find( '[data-akb-sb-tier-body]' );
			var idx = $body.find( '[data-akb-sb-tier-row]' ).length;
			$body.append( tierRow( base, $table.data( 'term' ), idx ) );
		} );

		// Quitar tramo.
		$( document ).on( 'click', '[data-akb-sb-tier-remove]', function ( e ) {
			e.preventDefault();
			var $body = $( this ).closest( '[data-akb-sb-tier-body]' );
			var $table = $( this ).closest( '[data-akb-sb-tiers]' );
			var base = 'series[' + $table.data( 'term' ) + ']';
			$( this ).closest( '[data-akb-sb-tier-row]' ).remove();

			// Mantener al menos una fila.
			if ( $body.find( '[data-akb-sb-tier-row]' ).length === 0 ) {
				$body.append( tierRow( base, $table.data( 'term' ), 0 ) );
				return;
			}

			reindexTiers( $body, base );
		} );
	}

	/**
	 * 2. Filtro en vivo para la lista de series (Arma tu pack).
	 */
	function initBypFilter() {
		var $searchInput = $( '#akb-sb-byp-search' );
		var $seriesBlocks = $( '.akb-sb-series' );

		if ( ! $searchInput.length || ! $seriesBlocks.length ) {
			return;
		}

		$searchInput.on( 'input', function () {
			var term = $( this ).val().toLowerCase().trim();

			$seriesBlocks.each( function () {
				var $block = $( this );
				var title = $block.find( '.akb-accordion__title' ).text().toLowerCase();
				var meta = $block.find( '.akb-accordion__meta' ).text().toLowerCase();

				if ( '' === term || title.indexOf( term ) > -1 || meta.indexOf( term ) > -1 ) {
					$block.show();
				} else {
					$block.hide();
				}
			} );
		} );
	}

	function showTierError( $table, $inputs, message ) {
		$inputs.addClass( 'akb-sb-input-error' );
		$table.after( '<div class="akb-sb-tier-error-msg" role="alert">' + message + '</div>' );
		$table.closest( 'details' ).attr( 'open', true );
	}

	/**
	 * 3. Validación preventiva en el cliente, alineada con el servidor.
	 */
	function initBypValidation() {
		var $form = $( '.akb-sb-byp-form' );
		if ( ! $form.length ) {
			return;
		}

		$form.on( 'submit', function ( e ) {
			var hasErrors = false;

			// Limpiar errores previos
			$form.find( '.akb-sb-input-error' ).removeClass( 'akb-sb-input-error' );
			$form.find( '.akb-sb-tier-error-msg' ).remove();

			// Validar cada tabla activa
			$form.find( '.akb-sb-tier-table' ).each( function () {
				var $table = $( this );
				// Solo validar si el checkbox "Habilitar" está marcado
				var $enabledCheckbox = $table.closest( '.akb-accordion__body' ).find( 'input[type="checkbox"][name*="[enabled]"]' );
				if ( ! $enabledCheckbox.is( ':checked' ) ) {
					return;
				}

				var rows = [];

				$table.find( 'tbody tr' ).each( function () {
					var $minInput = $( this ).find( 'input[name$="[min]"]' );
					var $pctInput = $( this ).find( 'input[name$="[pct]"]' );
					var minRaw = $minInput.val();
					var pctRaw = $pctInput.val();

					if ( minRaw === '' && pctRaw === '' ) {
						return;
					}
					rows.push( {
						min: parseInt( minRaw, 10 ),
						pct: parseInt( pctRaw, 10 ),
						$min: $minInput,
						$pct: $pctInput
					} );
				} );

				if ( rows.length === 0 ) {
					hasErrors = true;
					showTierError( $table, $table.find( 'input' ), 'Agrega al menos un tramo antes de activar esta serie.' );
					return;
				}

				var invalidFields = $();
				rows.forEach( function ( row ) {
					if ( isNaN( row.min ) || row.min < 2 ) {
						invalidFields = invalidFields.add( row.$min );
					}
					if ( isNaN( row.pct ) || row.pct < 0 || row.pct > 15 ) {
						invalidFields = invalidFields.add( row.$pct );
					}
					if ( row.pct > 0 && row.min < 3 ) {
						invalidFields = invalidFields.add( row.$min ).add( row.$pct );
					}
				} );
				if ( invalidFields.length ) {
					hasErrors = true;
					showTierError( $table, invalidFields, 'Usa cantidades desde 2 tomos y descuentos entre 0% y 15%. Un descuento debe comenzar desde 3 tomos.' );
					return;
				}

				rows.sort( function ( a, b ) { return a.min - b.min; } );
				for ( var i = 1; i < rows.length; i++ ) {
					if ( rows[ i ].min === rows[ i - 1 ].min ) {
						hasErrors = true;
						showTierError( $table, rows[ i ].$min.add( rows[ i - 1 ].$min ), 'Cada tramo debe comenzar en una cantidad distinta.' );
						return;
					}
					if ( rows[ i ].pct === rows[ i - 1 ].pct ) {
						// Retirar antes del POST un tramo posterior con el mismo porcentaje: no cambia el precio
						// efectivo y el servidor aplica el mismo saneamiento.
						rows[ i ].$min.closest( 'tr' ).remove();
						rows.splice( i, 1 );
						i--;
						continue;
					}
					if ( rows[ i ].pct < rows[ i - 1 ].pct ) {
						hasErrors = true;
						showTierError( $table, rows[ i ].$pct.add( rows[ i - 1 ].$pct ), 'El descuento debe aumentar al subir la cantidad de tomos.' );
						return;
					}
				}
			} );

			if ( hasErrors ) {
				e.preventDefault();
				var firstError = $form.find( '.akb-sb-input-error' ).first().get( 0 );
				if ( firstError ) {
					var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
					firstError.scrollIntoView( { behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' } );
					firstError.focus();
				}
			}
		} );
	}

	function initConfirmations() {
		$( '[data-akb-confirm]' ).on( 'submit', function ( e ) {
			var message = $( this ).attr( 'data-akb-confirm' );
			if ( message && ! window.confirm( message ) ) {
				e.preventDefault();
			}
		} );
	}

	$( function () {
		initDynamicTiers();
		initBypFilter();
		initBypValidation();
		initConfirmations();
	} );
}( jQuery ) );
