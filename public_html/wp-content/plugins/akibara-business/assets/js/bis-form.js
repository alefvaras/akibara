/**
 * bis-form.js — submit AJAX del form "Avísame cuando vuelva" (PDP agotado).
 *
 * El endpoint público se protege con honeypot y rate limit. No se usa un
 * time-trap: autocompletar y enviar rápido es un flujo humano válido y nunca
 * debe mostrar éxito si la suscripción no quedó persistida.
 */
( function () {
	'use strict';

	if ( typeof window.akbBis === 'undefined' ) {
		return;
	}

	function readResponse( response ) {
		return response.text().then( function ( raw ) {
			let body = {};
			try {
				const parsed = JSON.parse( raw );
				if ( parsed && typeof parsed === 'object' && ! Array.isArray( parsed ) ) {
					body = parsed;
				}
			} catch ( _parseError ) {}
			return { ok: response.ok, status: response.status, body };
		} );
	}

	function subscribed( response ) {
		return response.ok && [ 'subscribed', 'resubscribed' ].includes( response.body.code );
	}

	function alreadySubscribed( response ) {
		return response.status === 409 && response.body.code === 'already_subscribed';
	}

	function fallbackMessage( ok, informational ) {
		if ( informational ) {
			return 'Ya tienes un aviso para este tomo.';
		}
		return ok ? 'Listo. Te avisaremos cuando vuelva.' : 'No pudimos anotarte. Intenta de nuevo.';
	}

	const root = document.querySelector( '[data-akb-bis]' );
	if ( root ) {
		const form   = root.querySelector( '.akb-bis__form' );
		const email  = root.querySelector( '.akb-bis__email' );
		const submit = root.querySelector( '.akb-bis__submit' );
		const msg    = root.querySelector( '.akb-bis__msg' );
		const title  = root.querySelector( '.akb-bis__title' );
		let busy   = false;
		const idleLabel = submit.getAttribute( 'data-idle-label' ) || submit.textContent || 'Avisarme';

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
			const focusTarget = title || root;
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
			const reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
			// Directo en `load` (layout ya cerrado, imágenes lazyload resueltas): sin
			// requestAnimationFrame, que un navegador con la pestaña en segundo plano
			// throttlea y podría no disparar nunca (gotcha del harness headless).
			const run = function () {
				landOnForm( ! reduce );
			};
			if ( 'complete' === document.readyState ) {
				run();
			} else {
				window.addEventListener( 'load', run, { once: true } );
			}
		}

		function show( text, ok, informational ) {
			msg.textContent = text;
			msg.hidden = false;
			msg.classList.toggle( 'is-ok', !! ok );
			msg.classList.toggle( 'is-error', ! ok && ! informational );
			msg.classList.toggle( 'is-info', !! informational );
			msg.setAttribute( 'role', ok || informational ? 'status' : 'alert' );
			msg.setAttribute( 'aria-live', ok || informational ? 'polite' : 'assertive' );
			if ( ok || informational ) {
				email.removeAttribute( 'aria-invalid' );
			} else {
				email.setAttribute( 'aria-invalid', 'true' );
			}
		}

		function clearMessage() {
			msg.hidden = true;
			msg.textContent = '';
			msg.classList.remove( 'is-ok', 'is-error', 'is-info' );
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

			const value = ( email.value || '' ).trim();
			email.value = value;
			if ( ! value || value.length > 100 || ! email.checkValidity() ) {
				show( 'Ingresa un correo válido.', false );
				email.focus();
				return;
			}

			clearMessage();
			setBusy( true );

			const payload = {
				product_id: parseInt( form.querySelector( '[name="product_id"]' ).value, 10 ),
				email: value
			};

			// Honeypot: si el campo señuelo existe, viaja con su valor (vacío en humanos).
			const hp = form.querySelector( '[name="akb_hp_url"]' );
			if ( hp ) {
				payload.akb_hp_url = hp.value || '';
			}

			fetch( window.akbBis.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( payload )
			} )
				.then( readResponse )
				.then( function ( r ) {
					const code = r.body && r.body.code ? r.body.code : '';
					const ok   = subscribed( r );
					const informational = alreadySubscribed( r );
					const fallbackText = fallbackMessage( ok, informational );
					const text = r.body && r.body.message ? r.body.message : fallbackText;

					show( text, ok, informational );

					if ( ok ) {
						// Ocultar el form remueve del árbol al botón enfocado y el
						// foco caería a <body>: posicionar al usuario de teclado
						// junto a la confirmación (msg ya es role=status).
						form.hidden = true;
						msg.setAttribute( 'tabindex', '-1' );
						msg.focus();
						try {
							if ( window.sessionStorage ) {
								window.sessionStorage.setItem( 'akb_bis_last_email', value );
							}
						} catch ( _err ) {}
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
	}

	// ───── Captura fluida desde cards de catálogo / serie (sin salir de la página) ─────
	let loopDialog = null;
	let lastTrigger = null;
	let dialogBusy = false;
	let dialogSequence = 0;
	let dialogRequest = null;
	let dialogCloseTimer = null;

	function resetDialog() {
		dialogSequence++;
		clearTimeout( dialogCloseTimer );
		dialogCloseTimer = null;
		if ( dialogRequest ) {
			dialogRequest.abort();
			dialogRequest = null;
		}
		dialogBusy = false;
		loopDialog.querySelector( '.akb-bis-dialog__form' ).removeAttribute( 'aria-busy' );
		const submit = loopDialog.querySelector( '.akb-bis-dialog__submit' );
		submit.disabled = false;
		submit.textContent = 'Avisarme';
	}

	function currentTrigger( trigger ) {
		if ( ! trigger || trigger.isConnected ) {
			return trigger;
		}
		const productId = Number( trigger.getAttribute( 'data-product-id' ) );
		return Number.isSafeInteger( productId ) && productId > 0
			? document.querySelector( '.akb-notify-link[data-product-id="' + productId + '"]' )
			: null;
	}

	function closeDialog() {
		resetDialog();
		if ( loopDialog.open ) {
			loopDialog.close();
		}
		const trigger = currentTrigger( lastTrigger );
		if ( trigger ) {
			trigger.focus();
		}
		lastTrigger = null;
	}

	function getOrCreateDialog() {
		if ( loopDialog ) {
			return loopDialog;
		}
		loopDialog = document.getElementById( 'akb-bis-dialog' );
		if ( loopDialog ) {
			return loopDialog;
		}
		loopDialog = document.createElement( 'dialog' );
		loopDialog.id = 'akb-bis-dialog';
		loopDialog.className = 'akb-bis-dialog';
		loopDialog.setAttribute( 'aria-labelledby', 'akb-bis-dialog-title' );
		loopDialog.innerHTML =
			'<div class="akb-bis-dialog__header">' +
			'<h3 id="akb-bis-dialog-title" class="akb-bis-dialog__title">Avísame cuando vuelva</h3>' +
			'<button type="button" class="akb-bis-dialog__close" aria-label="Cerrar">&times;</button>' +
			'</div>' +
			'<p class="akb-bis-dialog__lede">Este tomo está agotado. Deja tu correo y te avisamos apenas vuelva a stock.</p>' +
			'<form class="akb-bis-dialog__form" novalidate>' +
			'  <input type="hidden" name="product_id" value="">' +
			'  <label for="akb-bis-dialog-email">Tu correo</label>' +
			'  <div class="akb-bis-dialog__row">' +
			'    <input type="email" id="akb-bis-dialog-email" name="email" class="akb-bis-dialog__email" placeholder="tu@correo.cl" autocomplete="email" inputmode="email" maxlength="100" aria-describedby="akb-bis-dialog-message" required>' +
			'    <button type="submit" class="button akb-bis-dialog__submit">Avisarme</button>' +
			'  </div>' +
			'</form>' +
			'<div id="akb-bis-dialog-message" class="akb-bis-dialog__msg" role="status" aria-live="polite" aria-atomic="true" hidden></div>';
		document.body.appendChild( loopDialog );

		loopDialog.querySelector( '.akb-bis-dialog__close' ).addEventListener( 'click', closeDialog );
		loopDialog.addEventListener( 'cancel', function ( event ) {
			event.preventDefault();
			closeDialog();
		} );

		loopDialog.addEventListener( 'close', function () {
			// A queued close event from a previous opening must not cancel a new one.
			if ( ! loopDialog.open ) {
				closeDialog();
			}
		} );

		loopDialog.addEventListener( 'click', function ( ev ) {
			if ( ev.target === loopDialog ) {
				closeDialog();
			}
		} );

		const dForm = loopDialog.querySelector( '.akb-bis-dialog__form' );
		const dEmail = loopDialog.querySelector( '.akb-bis-dialog__email' );
		const dSubmit = loopDialog.querySelector( '.akb-bis-dialog__submit' );
		const dMsg = loopDialog.querySelector( '.akb-bis-dialog__msg' );

		function showMessage( text, ok, informational ) {
			dMsg.textContent = text;
			dMsg.className = 'akb-bis-dialog__msg';
			dMsg.classList.toggle( 'is-info', !! informational );
			dMsg.classList.toggle( 'is-ok', !! ok );
			dMsg.classList.toggle( 'is-error', ! ok && ! informational );
			dMsg.setAttribute( 'role', ok || informational ? 'status' : 'alert' );
			dMsg.setAttribute( 'aria-live', ok || informational ? 'polite' : 'assertive' );
			dMsg.hidden = false;
		}

		dEmail.addEventListener( 'input', function () {
			dEmail.removeAttribute( 'aria-invalid' );
			if ( dMsg.classList.contains( 'is-error' ) ) {
				dMsg.hidden = true;
			}
		} );

		dForm.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			if ( dialogBusy ) {
				return;
			}
			const val = ( dEmail.value || '' ).trim();
			dEmail.value = val;
			if ( ! val || val.length > 100 || ! dEmail.checkValidity() ) {
				showMessage( 'Ingresa un correo válido.', false );
				dEmail.setAttribute( 'aria-invalid', 'true' );
				dEmail.focus();
				return;
			}

			dMsg.hidden = true;
			dEmail.removeAttribute( 'aria-invalid' );
			dialogBusy = true;
			dForm.setAttribute( 'aria-busy', 'true' );
			dSubmit.disabled = true;
			dSubmit.textContent = 'Enviando…';

			const pid = parseInt( dForm.querySelector( '[name="product_id"]' ).value, 10 );
			const sequence = dialogSequence;
			const trigger = lastTrigger;
			dialogRequest = new AbortController();
			fetch( window.akbBis.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { product_id: pid, email: val } ),
				signal: dialogRequest.signal
			} )
				.then( readResponse )
				.then( function ( response ) {
					if ( sequence !== dialogSequence ) {
						return;
					}
					const data = response.body;
					const ok = subscribed( response );
					const informational = alreadySubscribed( response );
					const fallbackText = fallbackMessage( ok, informational );
					showMessage( data.message || fallbackText, ok, informational );
					if ( ok ) {
						try {
							if ( window.sessionStorage ) {
								window.sessionStorage.setItem( 'akb_bis_last_email', val );
							}
						} catch ( _err ) {}
						dForm.hidden = true;
						dMsg.setAttribute( 'tabindex', '-1' );
						dMsg.focus();
						const current = currentTrigger( trigger );
						if ( current ) {
							current.textContent = '✓ Anotado';
							current.classList.add( 'is-subscribed' );
						}
						dialogCloseTimer = setTimeout( function () {
							if ( sequence === dialogSequence && loopDialog.open ) {
								closeDialog();
							}
						}, 2000 );
					} else if ( data.code === 'invalid_email' ) {
						dEmail.setAttribute( 'aria-invalid', 'true' );
						dEmail.focus();
					}
				} )
				.catch( function () {
					if ( sequence === dialogSequence ) {
						showMessage( 'No pudimos anotarte. Revisa tu conexión e intenta de nuevo.', false );
					}
				} )
				.finally( function () {
					if ( sequence === dialogSequence ) {
						dialogBusy = false;
						dialogRequest = null;
						dForm.removeAttribute( 'aria-busy' );
						dSubmit.disabled = false;
						dSubmit.textContent = 'Avisarme';
					}
				} );
		} );

		return loopDialog;
	}

	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest ? e.target.closest( '.akb-notify-link' ) : null;
		if ( ! btn || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
			return;
		}

		const pid = parseInt( btn.getAttribute( 'data-product-id' ) || btn.getAttribute( 'data-product_id' ), 10 );
		if ( ! pid ) {
			return;
		}

		// Si estamos en la propia ficha del producto agotado, dejamos que ocurra el scroll nativo al formulario
		if ( root && parseInt( root.querySelector( '[name="product_id"]' ) ? root.querySelector( '[name="product_id"]' ).value : 0, 10 ) === pid ) {
			return;
		}

		e.preventDefault();
		const qvDialog = btn.closest ? btn.closest( 'dialog.akb-qv' ) : null;
		if ( qvDialog && typeof qvDialog.close === 'function' ) {
			qvDialog.close();
		}
		const dlg = getOrCreateDialog();
		resetDialog();
		lastTrigger = btn;
		const dForm = dlg.querySelector( '.akb-bis-dialog__form' );
		const dPid = dlg.querySelector( '[name="product_id"]' );
		const dMsg = dlg.querySelector( '.akb-bis-dialog__msg' );
		const dEmail = dlg.querySelector( '.akb-bis-dialog__email' );
		const titleEl = dlg.querySelector( '#akb-bis-dialog-title' );
		const pName = btn.getAttribute( 'data-product-name' ) || '';

		if ( titleEl ) {
			titleEl.textContent = 'Avísame cuando vuelva' + ( pName ? ': ' + pName : '' );
		}
		dPid.value = pid;
		dForm.hidden = false;
		dMsg.hidden = true;
		dMsg.textContent = '';
		dEmail.removeAttribute( 'aria-invalid' );
		try {
			if ( ! dEmail.value && window.sessionStorage ) {
				const savedEmail = window.sessionStorage.getItem( 'akb_bis_last_email' );
				if ( savedEmail ) {
					dEmail.value = savedEmail;
				}
			}
		} catch ( _err ) {}
		if ( ! dlg.open ) {
			dlg.showModal();
		}
		dEmail.focus();
	} );
} )();
