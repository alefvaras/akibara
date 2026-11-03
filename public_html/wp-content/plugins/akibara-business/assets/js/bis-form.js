/**
 * bis-form.js — submit AJAX del form "Avísame cuando vuelva" (PDP agotado).
 *
 * El endpoint público se protege con honeypot y rate limit. No se usa un
 * time-trap: autocompletar y enviar rápido es un flujo humano válido y nunca
 * debe mostrar éxito si la suscripción no quedó persistida.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-akb-bis]' );
	if ( ! root || typeof window.akbBis === 'undefined' ) {
		return;
	}

	var form   = root.querySelector( '.akb-bis__form' );
	var email  = root.querySelector( '.akb-bis__email' );
	var submit = root.querySelector( '.akb-bis__submit' );
	var msg    = root.querySelector( '.akb-bis__msg' );
	var title  = root.querySelector( '.akb-bis__title' );
	var busy   = false;
	var idleLabel = submit.getAttribute( 'data-idle-label' ) || submit.textContent || 'Avisarme';

	// Deep-link a la lista de espera: si el PDP se abrió con el fragment del form
	// (#akb-bis-form) — desde el builder de packs o una card del loop —, aterriza
	// en el formulario, no arriba. El salto nativo del navegador puede caer mal
	// cuando hay imágenes lazyload arriba que reflowean tarde, así que
	// reposicionamos tras `load` (layout ya cerrado). Foco al título (heading
	// enfocable) para que AT anuncie el destino, sin abrir el teclado móvil
	// (no enfocamos el input) y sin doble scroll (preventScroll).
	function landOnForm( smooth ) {
		try {
			root.scrollIntoView( { behavior: smooth ? 'smooth' : 'auto', block: 'start' } );
		} catch ( _e ) {
			root.scrollIntoView();
		}
		var focusTarget = title || root;
		if ( ! focusTarget.hasAttribute( 'tabindex' ) ) {
			focusTarget.setAttribute( 'tabindex', '-1' );
		}
		try {
			focusTarget.focus( { preventScroll: true } );
		} catch ( _e2 ) {
			focusTarget.focus();
		}
	}

	if ( window.location.hash && window.location.hash.slice( 1 ) === root.id ) {
		var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		// Directo en `load` (layout ya cerrado, imágenes lazyload resueltas): sin
		// requestAnimationFrame, que un navegador con la pestaña en segundo plano
		// throttlea y podría no disparar nunca (gotcha del harness headless).
		var run = function () {
			landOnForm( ! reduce );
		};
		if ( 'complete' === document.readyState ) {
			run();
		} else {
			window.addEventListener( 'load', run, { once: true } );
		}
	}

	function show( text, ok ) {
		msg.textContent = text;
		msg.hidden = false;
		msg.classList.toggle( 'is-ok', !! ok );
		msg.classList.toggle( 'is-error', ! ok );
		msg.setAttribute( 'role', ok ? 'status' : 'alert' );
		msg.setAttribute( 'aria-live', ok ? 'polite' : 'assertive' );
		if ( ok ) {
			email.removeAttribute( 'aria-invalid' );
		} else {
			email.setAttribute( 'aria-invalid', 'true' );
		}
	}

	function clearMessage() {
		msg.hidden = true;
		msg.textContent = '';
		msg.classList.remove( 'is-ok', 'is-error' );
		msg.setAttribute( 'role', 'status' );
		msg.setAttribute( 'aria-live', 'polite' );
		email.removeAttribute( 'aria-invalid' );
	}

	function setBusy( isBusy ) {
		busy = isBusy;
		submit.disabled = isBusy;
		submit.classList.toggle( 'is-loading', isBusy );
		submit.textContent = isBusy ? 'Enviando…' : idleLabel;
		if ( isBusy ) {
			form.setAttribute( 'aria-busy', 'true' );
		} else {
			form.removeAttribute( 'aria-busy' );
		}
	}

	email.addEventListener( 'input', function () {
		if ( ! msg.hidden && msg.classList.contains( 'is-error' ) ) {
			clearMessage();
		}
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		if ( busy ) {
			return;
		}

		var value = ( email.value || '' ).trim();
		email.value = value;
		if ( ! value || value.length > 100 || ! email.checkValidity() ) {
			show( 'Ingresa un correo válido.', false );
			email.focus();
			return;
		}

		clearMessage();
		setBusy( true );

		var payload = {
			product_id: parseInt( form.querySelector( '[name="product_id"]' ).value, 10 ),
			email: value
		};

		// Honeypot: si el campo señuelo existe, viaja con su valor (vacío en humanos).
		var hp = form.querySelector( '[name="akb_hp_url"]' );
		if ( hp ) {
			payload.akb_hp_url = hp.value || '';
		}

		fetch( window.akbBis.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( payload )
		} )
			.then( function ( res ) {
				// Proxies/CDN pueden devolver HTML o un body vacío en errores.
				// Leer como texto primero evita que JSON.parse opaque el status HTTP
				// y permite mostrar un mensaje útil en vez de romper la cadena.
				return res.text().then( function ( raw ) {
					var body = {};
					if ( raw ) {
						try {
							body = JSON.parse( raw );
						} catch ( _parseError ) {
							body = {};
						}
					}
					return { status: res.status, ok: res.ok, body: body };
				} );
			} )
			.then( function ( r ) {
				var code = r.body && r.body.code ? r.body.code : '';
				var ok   = 'subscribed' === code || 'resubscribed' === code || 'already_subscribed' === code;
				var text = r.body && r.body.message
					? r.body.message
					: ( ok ? 'Listo. Te avisaremos cuando vuelva.' : 'No pudimos anotarte. Intenta de nuevo.' );

				show( text, ok );

				if ( ok ) {
					// Ocultar el form remueve del árbol al botón enfocado y el
					// foco caería a <body>: posicionar al usuario de teclado
					// junto a la confirmación (msg ya es role=status).
					form.hidden = true;
					msg.setAttribute( 'tabindex', '-1' );
					msg.focus();
				} else if ( 'invalid_email' === code ) {
					email.focus();
				}
			} )
			.catch( function () {
				show( 'No pudimos anotarte. Revisa tu conexión e intenta de nuevo.', false );
			} )
			.finally( function () {
				setBusy( false );
			} );
	} );
} )();
