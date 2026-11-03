/**
 * Interruptor «avisarme del siguiente tomo» en /mi-cuenta/coleccion/.
 *
 * La suscripción es un hecho derivado de haber comprado la serie, así que este
 * botón sólo escribe la NEGACIÓN: activado = sin opt-out.
 *
 * Sin JavaScript el botón no hace nada y la tarjeta sigue siendo legible: la
 * colección se pinta entera en el servidor y esto es una preferencia, no una
 * compra.
 */
( function () {
	'use strict';

	var config = window.akbSeriesFollow || {};
	if ( ! config.root || ! config.nonce ) {
		return;
	}

	var LABEL_ON = 'Avisos activados';
	var LABEL_OFF = 'Avisarme del siguiente tomo';

	function paint( button, following ) {
		button.setAttribute( 'aria-pressed', following ? 'true' : 'false' );
		var label = button.querySelector( '.akb-series-follow__label' );
		if ( label ) {
			label.textContent = following ? LABEL_ON : LABEL_OFF;
		}
	}

	function announce( button, text, isError ) {
		var msg = button.parentNode
			? button.parentNode.querySelector( '.akb-series-follow__msg' )
			: null;
		if ( ! msg ) {
			return;
		}
		msg.textContent = text;
		msg.classList.toggle( 'is-error', !! isError );
	}

	function toggle( button ) {
		var termId = parseInt( button.getAttribute( 'data-term-id' ), 10 );
		if ( ! termId || button.disabled ) {
			return;
		}

		var next = 'true' !== button.getAttribute( 'aria-pressed' );

		button.disabled = true;

		window
			.fetch( config.root, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce
				},
				body: JSON.stringify( { term_id: termId, follow: next } )
			} )
			.then( function ( response ) {
				return response.json().then( function ( payload ) {
					return { ok: response.ok, payload: payload };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					throw new Error( 'rejected' );
				}
				// El servidor es la autoridad del estado, no lo que pidió el
				// clic: si otra pestaña ya lo cambió, la tarjeta se alinea.
				paint( button, true === result.payload.following );
				announce( button, result.payload.message || '', false );
			} )
			.catch( function () {
				// El nombre de la serie distingue QUÉ toggle falló cuando el
				// lector de pantalla anuncia el mensaje en una lista con varios.
				var seriesName = button.getAttribute( 'data-series-name' ) || '';
				announce(
					button,
					seriesName
						? 'No pudimos guardar el cambio para «' + seriesName + '». Inténtalo de nuevo.'
						: 'No pudimos guardar el cambio. Inténtalo de nuevo.',
					true
				);
			} )
			.then( function () {
				button.disabled = false;
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest
			? event.target.closest( '[data-akb-series-follow]' )
			: null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		toggle( button );
	} );
} )();
