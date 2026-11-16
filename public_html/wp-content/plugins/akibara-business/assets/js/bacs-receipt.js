/**
 * Envío AJAX del comprobante de transferencia bancaria (BACS).
 *
 * Progresivo sobre `#akb_bacs_submit_receipt_form`: valida el N.º de operación,
 * evita envíos duplicados, comunica el resultado en la región live accesible y
 * recarga tras el éxito para reflejar el período de gracia extendido.
 */
( function () {
	'use strict';

	function initBacsReceiptForm() {
		const form = document.getElementById( 'akb_bacs_submit_receipt_form' );
		const msg = document.getElementById( 'akb_bacs_receipt_msg' );
		const input = document.getElementById( 'akb_bacs_ref_input' );

		if ( ! form || ! msg || ! input ) {
			return;
		}

		// Evita globos nativos inconsistentes cuando JS gestiona la validación accesible.
		form.noValidate = true;

		const config = window.akbBacsReceiptConfig || {};
		const submitBtn = form.querySelector( 'button[type="submit"]' );
		const defaultBtnText = config.submitLabel || ( submitBtn ? submitBtn.textContent.trim() : 'Ya transferí / Informar pago' );
		const submittingText = config.submittingLabel || 'Enviando...';
		const genericError = config.genericError || 'Error al enviar el comprobante. Inténtalo de nuevo.';
		const ajaxUrl = config.ajaxUrl || form.getAttribute( 'action' ) || '';
		const reloadDelayMs = typeof config.reloadDelayMs === 'number' ? config.reloadDelayMs : 1500;
		let isSubmitting = false;

		function showMessage( text, isError ) {
			msg.textContent = ( isError ? '✕ ' : '✓ ' ) + text;
			msg.hidden = false;
			msg.classList.toggle( 'akb-bacs-portal__msg--error', Boolean( isError ) );
			msg.classList.toggle( 'akb-bacs-portal__msg--ok', ! isError );
		}

		function setBusy( busy ) {
			isSubmitting = busy;
			if ( submitBtn ) {
				submitBtn.disabled = busy;
				submitBtn.textContent = busy ? submittingText : defaultBtnText;
			}
		}

		input.addEventListener( 'input', function () {
			if ( input.hasAttribute( 'aria-invalid' ) ) {
				input.removeAttribute( 'aria-invalid' );
			}
			if ( input.hasAttribute( 'aria-describedby' ) ) {
				input.removeAttribute( 'aria-describedby' );
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( isSubmitting ) {
				return;
			}

			const reference = input.value.trim();
			if ( ! reference ) {
				input.setAttribute( 'aria-invalid', 'true' );
				input.setAttribute( 'aria-describedby', msg.id );
				showMessage( 'Ingresa tu número de transferencia u operación.', true );
				input.focus();
				return;
			}

			if ( ! ajaxUrl ) {
				showMessage( genericError, true );
				return;
			}

			input.value = reference;
			input.removeAttribute( 'aria-invalid' );
			input.removeAttribute( 'aria-describedby' );
			msg.hidden = true;
			msg.textContent = '';

			setBusy( true );
			const formData = new window.FormData( form );

			window
				.fetch( ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData,
				} )
				.then( function ( response ) {
					if ( typeof response.text === 'function' ) {
						return response.text().then( function ( rawBody ) {
							let payload = null;
							if ( rawBody && rawBody.trim().startsWith( '{' ) ) {
								try {
									payload = JSON.parse( rawBody );
								} catch ( parseError ) {
									document.dispatchEvent(
										new window.CustomEvent( 'akb:bacs-receipt:parse-error', {
											detail: {
												message: parseError && parseError.message ? String( parseError.message ) : 'invalid-json',
											},
										} )
									);
									payload = null;
								}
							}
							return { ok: response.ok, payload };
						} );
					}
					return response.json().then( function ( payload ) {
						return { ok: response.ok, payload };
					} );
				} )
				.then( function ( result ) {
					const payload = result && result.payload ? result.payload : null;
					const serverMessage = payload && payload.data && payload.data.message ? String( payload.data.message ) : '';

					if ( ! result.ok || ! payload || ! payload.success ) {
						const failure = new Error( serverMessage || genericError );
						failure.akbUserMessage = true;
						throw failure;
					}

					form.setAttribute( 'data-akb-bacs-state', 'submitted' );
					showMessage( serverMessage || '¡Comprobante informado con éxito!', false );
					document.dispatchEvent(
						new window.CustomEvent( 'akb:bacs-receipt:submitted', {
							detail: { reference },
						} )
					);

					window.setTimeout( function () {
						window.location.reload();
					}, reloadDelayMs );
				} )
				.catch( function ( error ) {
					const message = error && error.akbUserMessage && error.message ? error.message : genericError;
					input.setAttribute( 'aria-invalid', 'true' );
					input.setAttribute( 'aria-describedby', msg.id );
					showMessage( message, true );
					setBusy( false );
				} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initBacsReceiptForm );
	} else {
		initBacsReceiptForm();
	}
} )();
