/**
 * Akibara — Popup de bienvenida primera compra (10% descuento con Brevo)
 * y popup promocional estático (Halloween, Cyber, campañas especiales).
 *
 * Características avanzadas optimizadas para ultra-rendimiento:
 *   1. Elegibilidad LiteSpeed/Edge Cache segura: evaluada 100% en cliente sin penalizar TTFB.
 *   2. Multi-disparador: tiempo de espera (`delaySeconds`), profundidad de scroll (`scrollPct`)
 *      e intención de salida en escritorio (`exitIntent`: cursor hacia borde superior).
 *   3. Distintivo flotante de reapertura (`launcher`): si el usuario cierra el modal para
 *      seguir vitrineando, queda una pequeña pestaña discreta en la esquina para reabrirlo en 1 clic.
 *   4. Welcome Pivot: si el correo ya era comprador, se suscribe a Brevo sin emitir cupón.
 *   5. Copiado en 1 clic y auto-aplicación mediante enlace con parámetro `?akb_cupon=`.
 *   6. Accesibilidad total: foco accesible (trap en tabulador), escape para cerrar y retorno de foco.
 */
( function () {
	'use strict';

	var STORAGE_DONE = 'akb_welcome_done';
	var COOKIE_DONE = 'akb_welcome_done';

	var modal = document.getElementById( 'akb-welcome-modal' );
	if ( ! modal || typeof window.akbWelcomePopup !== 'object' ) {
		return;
	}

	var config = window.akbWelcomePopup;
	var mode = config.mode || 'welcome';
	var launcher = document.getElementById( 'akb-welcome-launcher' );
	var form = modal.querySelector( '[data-akb-welcome-form]' );
	var stepForm = modal.querySelector( '[data-akb-welcome-step="form"]' );
	var stepSuccess = modal.querySelector( '[data-akb-welcome-step="success"]' );
	var msgBox = modal.querySelector( '[data-akb-welcome-msg]' );
	var submitBtn = modal.querySelector( '[data-akb-welcome-submit]' );
	var codeEl = modal.querySelector( '[data-akb-welcome-code]' );
	var expiresEl = modal.querySelector( '[data-akb-welcome-expires]' );
	var shopLink = modal.querySelector( '[data-akb-welcome-shop-link]' );
	var successMsgEl = modal.querySelector( '[data-akb-welcome-success-msg]' );
	var copyBtn = modal.querySelector( '[data-akb-welcome-copy]' );

	var turnstileWidgetId = null;
	var turnstileToken = '';

	function initTurnstile() {
		if ( ! config.turnstileEnabled || ! config.turnstileSitekey || typeof window.turnstile === 'undefined' ) {
			return;
		}
		var slot = modal.querySelector( '[data-akb-welcome-turnstile]' );
		if ( ! slot || turnstileWidgetId !== null ) {
			return;
		}
		try {
			turnstileWidgetId = window.turnstile.render( slot, {
				sitekey: config.turnstileSitekey,
				theme: 'light',
				size: 'flexible',
				action: 'welcome_coupon',
				callback: function ( token ) {
					turnstileToken = token;
				},
				'expired-callback': function () {
					turnstileToken = '';
					if ( turnstileWidgetId !== null && window.turnstile ) {
						window.turnstile.reset( turnstileWidgetId );
					}
				},
				'error-callback': function () {
					turnstileToken = '';
				}
			} );
		} catch ( e ) {}
	}

	var openedAt = 0;
	var hasOpened = false;
	var lastFocused = null;
	var timerId = null;

	function hasCookiePrefix( prefix ) {
		var cookies = document.cookie ? document.cookie.split( ';' ) : [];
		for ( var i = 0; i < cookies.length; i++ ) {
			var c = cookies[ i ].trim();
			if ( c.indexOf( prefix ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	function setCookie( name, value, days ) {
		var maxAge = Math.max( 1, Math.floor( days * 86400 ) );
		document.cookie = name + '=' + encodeURIComponent( value ) + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
	}

	function getStorage( key ) {
		try {
			return window.localStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function setStorage( key, value ) {
		try {
			window.localStorage.setItem( key, value );
		} catch ( e ) {
			// Ignorar en modo privado estricto.
		}
	}

	( function captureUrlCoupon() {
		var match = window.location.search.match( /[?&]akb_cupon=([^&#]+)/i );
		if ( match && match[1] ) {
			var code = decodeURIComponent( match[1] ).trim();
			if ( code ) {
				setStorage( 'akb_pending_welcome_coupon', code );
				setCookie( 'akb_pending_cupon', code, 7 );
				setStorage( STORAGE_DONE, '1' );
				setCookie( COOKIE_DONE, '1', 365 );
			}
		}
	} )();

	function isPreviewRequested() {
		return config.preview === true;
	}

	function isEligibleVisitor() {
		if ( isPreviewRequested() ) {
			return true;
		}

		if ( window.location.search.indexOf( 'akb_cupon=' ) !== -1 ) {
			return false;
		}

		// Aislamiento del embudo de referidos de Akibara Puntos
		if ( window.location.search.indexOf( 'ref=' ) !== -1 || hasCookiePrefix( 'akb_ref=' ) ) {
			return false;
		}

		if ( mode === 'promo' ) {
			var isLogged = document.body.classList.contains( 'logged-in' ) || hasCookiePrefix( 'wordpress_logged_in_' );
			if ( config.audience === 'guests' && isLogged ) {
				return false;
			}
			var promoKey = config.cookieName || ( 'akb_welcome_promo_' + ( config.promoCode || 'campaign' ) );
			var promoDismissed = parseInt( getStorage( promoKey ) || '0', 10 );
			if ( promoDismissed > Date.now() || hasCookiePrefix( promoKey + '=' ) ) {
				return false;
			}
			return true;
		}

		// Modo bienvenida primera compra
		if ( getStorage( STORAGE_DONE ) === '1' || hasCookiePrefix( COOKIE_DONE + '=' ) || hasCookiePrefix( 'akb_wc_done=' ) ) {
			return false;
		}

		var dismissKey = config.cookieName || ( 'akb_welcome_dismissed_v' + ( config.cookieEpoch || 1 ) );
		var dismissedUntil = parseInt( getStorage( dismissKey ) || '0', 10 );
		if ( dismissedUntil > Date.now() || hasCookiePrefix( dismissKey + '=' ) ) {
			return false;
		}

		return true;
	}

	function isAlreadyDismissed() {
		var dismissKey = config.cookieName || ( mode === 'promo'
			? 'akb_welcome_promo_' + ( config.promoCode || 'campaign' ) + '_v' + ( config.cookieEpoch || 1 )
			: 'akb_welcome_dismissed_v' + ( config.cookieEpoch || 1 ) );
		var dismissedUntil = parseInt( getStorage( dismissKey ) || '0', 10 );
		return dismissedUntil > Date.now() || hasCookiePrefix( dismissKey + '=' );
	}

	function isCompleted() {
		return getStorage( STORAGE_DONE ) === '1' || hasCookiePrefix( COOKIE_DONE + '=' ) || hasCookiePrefix( 'akb_wc_done=' );
	}

	function isCampaignAudienceEligible() {
		return mode !== 'promo' || config.audience !== 'guests'
			|| ( ! document.body.classList.contains( 'logged-in' ) && ! hasCookiePrefix( 'wordpress_logged_in_' ) );
	}

	function isLauncherDismissed() {
		return getStorage( 'akb_launcher_dismissed' ) === '1';
	}

	function openModal( force ) {
		if ( ! modal.hidden ) {
			return;
		}
		if ( ! force && hasOpened ) {
			return;
		}
		hasOpened = true;
		openedAt = Date.now();
		cleanupTriggers();

		if ( launcher ) {
			launcher.hidden = true;
			launcher.classList.remove( 'is-visible' );
		}

		lastFocused = document.activeElement;
		modal.hidden = false;
		modal.classList.add( 'is-open' );
		document.documentElement.classList.add( 'akb-welcome-open' );

		initTurnstile();

		var nameInput = modal.querySelector( '#akb-welcome-name' );
		var promoCopy = modal.querySelector( '[data-akb-welcome-copy]' );
		var shopLink = modal.querySelector( '[data-akb-welcome-shop-link]' );
		if ( nameInput ) {
			window.setTimeout( function () {
				nameInput.focus();
			}, 40 );
		} else if ( promoCopy ) {
			window.setTimeout( function () {
				promoCopy.focus();
			}, 40 );
		} else if ( shopLink ) {
			window.setTimeout( function () {
				shopLink.focus();
			}, 40 );
		}
	}

	function closeModal( markDismissed ) {
		if ( modal.hidden ) {
			return;
		}

		modal.hidden = true;
		modal.classList.remove( 'is-open' );
		document.documentElement.classList.remove( 'akb-welcome-open' );

		if ( markDismissed ) {
			var days = Math.max( 1, parseInt( config.dismissDays || 30, 10 ) );
			var dismissKey = config.cookieName || ( mode === 'promo'
				? 'akb_welcome_promo_' + ( config.promoCode || 'campaign' ) + '_v' + ( config.cookieEpoch || 1 )
				: 'akb_welcome_dismissed_v' + ( config.cookieEpoch || 1 ) );
			setStorage( dismissKey, String( Date.now() + days * 86400 * 1000 ) );
			setCookie( dismissKey, '1', days );

			// Mostrar el distintivo flotante discreto para permitir reabrirlo si cambia de opinión
			if ( config.launcher && launcher && ! isCompleted() && ! isLauncherDismissed() ) {
				launcher.hidden = false;
				launcher.classList.add( 'is-visible' );
			}
		}

		if ( lastFocused && typeof lastFocused.focus === 'function' ) {
			try {
				lastFocused.focus();
			} catch ( e ) {}
		}
	}

	function onScrollCheck() {
		var scrollTop = window.pageYOffset || document.documentElement.scrollTop || 0;
		var docHeight = Math.max(
			document.body.scrollHeight,
			document.documentElement.scrollHeight
		) - window.innerHeight;

		if ( docHeight <= 80 ) {
			return;
		}

		var pct = ( scrollTop / docHeight ) * 100;
		var targetPct = Math.max( 10, parseInt( config.scrollPct || 45, 10 ) );
		if ( pct >= targetPct ) {
			openModal( false );
		}
	}

	function onMouseLeave( e ) {
		if ( e.clientY <= 10 ) {
			openModal( false );
		}
	}

	function cleanupTriggers() {
		if ( timerId ) {
			window.clearTimeout( timerId );
			timerId = null;
		}
		window.removeEventListener( 'scroll', onScrollCheck );
		document.removeEventListener( 'mouseleave', onMouseLeave );
	}

	function showMessage( text, isError ) {
		if ( ! msgBox ) {
			return;
		}
		msgBox.textContent = text;
		msgBox.classList.remove( 'is-error', 'is-ok' );
		msgBox.classList.add( isError ? 'is-error' : 'is-ok' );
		msgBox.hidden = false;
	}

	function clearMessage() {
		if ( ! msgBox ) {
			return;
		}
		msgBox.textContent = '';
		msgBox.hidden = true;
	}

	// Conectar botón del distintivo flotante (launcher)
	if ( launcher ) {
		launcher.addEventListener( 'click', function ( e ) {
			var dismissBtn = e.target.closest( '[data-akb-launcher-dismiss]' );
			if ( dismissBtn ) {
				e.preventDefault();
				e.stopPropagation();
				launcher.hidden = true;
				launcher.classList.remove( 'is-visible' );
				setStorage( 'akb_launcher_dismissed', '1' );
				return;
			}
			e.preventDefault();
			openModal( true );
		} );
	}

	// Eventos de cierre con delegación global (cubre el botón [X], su <span> interno,
	// el fondo oscuro backdrop y cualquier botón secundario de descarte).
	document.addEventListener( 'click', function ( e ) {
		if ( ! modal || modal.hidden ) {
			return;
		}
		var closeBtn = e.target.closest( '[data-akb-welcome-close]' );
		if ( closeBtn ) {
			e.preventDefault();
			closeModal( true );
		}
	} );

	var closeButtons = modal.querySelectorAll( '[data-akb-welcome-close]' );
	for ( var i = 0; i < closeButtons.length; i++ ) {
		closeButtons[ i ].addEventListener( 'click', function ( e ) {
			e.preventDefault();
			closeModal( true );
		} );
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( modal.hidden ) {
			return;
		}

		if ( e.key === 'Escape' ) {
			e.preventDefault();
			closeModal( true );
			return;
		}

		if ( e.key === 'Tab' ) {
			var focusables = modal.querySelectorAll(
				'button:not([disabled]), [href], input:not([disabled]):not([tabindex="-1"]), [tabindex]:not([tabindex="-1"])'
			);
			var visible = [];
			for ( var j = 0; j < focusables.length; j++ ) {
				if ( focusables[ j ].offsetParent !== null ) {
					visible.push( focusables[ j ] );
				}
			}
			if ( visible.length === 0 ) {
				return;
			}
			var first = visible[ 0 ];
			var last = visible[ visible.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}
	} );

	// Envío del formulario (modo bienvenida primera compra)
	if ( form && config.endpoint ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			clearMessage();

			var nameField = form.querySelector( 'input[name="name"]' );
			var emailField = form.querySelector( 'input[name="email"]' );
			var hpField = form.querySelector( 'input[name="akb_hp_url"]' );

			var nameVal = nameField ? nameField.value.trim() : '';
			var emailVal = emailField ? emailField.value.trim() : '';
			var hpVal = hpField ? hpField.value : '';

			if ( nameVal.length < 2 ) {
				showMessage( 'Ingresa tu nombre para personalizar tu código.', true );
				if ( nameField ) {
					nameField.focus();
				}
				return;
			}

			if ( ! emailVal || emailVal.indexOf( '@' ) === -1 || emailVal.indexOf( '.' ) === -1 ) {
				showMessage( 'Ingresa un correo válido.', true );
				if ( emailField ) {
					emailField.focus();
				}
				return;
			}

			if ( submitBtn ) {
				submitBtn.disabled = true;
				submitBtn.classList.add( 'is-loading' );
			}

			var elapsedMs = openedAt > 0 ? Math.max( 900, Date.now() - openedAt ) : 1200;
			var turnstileInput = form.querySelector( 'input[name="cf-turnstile-response"]' );
			var effectiveTurnstileToken = turnstileToken || ( turnstileInput ? turnstileInput.value : '' );

			window.fetch( config.endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				credentials: 'same-origin',
				body: JSON.stringify( {
					name: nameVal,
					email: emailVal,
					akb_hp_url: hpVal,
					elapsed_ms: elapsedMs,
					'cf-turnstile-response': effectiveTurnstileToken,
				} ),
			} )
				.then( function ( res ) {
					return res.json().then( function ( data ) {
						return { status: res.status, body: data };
					} );
				} )
				.then( function ( result ) {
					if ( submitBtn ) {
						submitBtn.disabled = false;
						submitBtn.classList.remove( 'is-loading' );
					}

					var data = result.body || {};
					if ( result.status >= 200 && result.status < 300 ) {
						if ( data.code === 'subscribed_existing_buyer' ) {
							setStorage( STORAGE_DONE, '1' );
							setCookie( COOKIE_DONE, '1', 365 );
							setCookie( 'akb_wc_done', '1', 365 );

							if ( launcher ) {
								launcher.hidden = true;
								launcher.classList.remove( 'is-visible' );
							}

							var existingBox = modal.querySelector( '[data-akb-welcome-existing-buyer]' );
							var issuedBox = modal.querySelector( '[data-akb-welcome-issued-box]' );
							var existingMsg = modal.querySelector( '[data-akb-welcome-existing-buyer-msg]' );

							if ( stepForm ) {
								stepForm.hidden = true;
							}
							if ( issuedBox ) {
								issuedBox.hidden = true;
							}
							if ( existingBox ) {
								if ( existingMsg && data.message ) {
									existingMsg.textContent = data.message;
								}
								existingBox.hidden = false;
							}
							if ( stepSuccess ) {
								stepSuccess.hidden = false;
							}
							return;
						}

						if ( data.coupon_code ) {
							setStorage( STORAGE_DONE, '1' );
							setCookie( COOKIE_DONE, '1', 365 );
							setCookie( 'akb_wc_done', '1', 365 );

							if ( launcher ) {
								launcher.hidden = true;
								launcher.classList.remove( 'is-visible' );
							}

							var existingBox2 = modal.querySelector( '[data-akb-welcome-existing-buyer]' );
							var issuedBox2 = modal.querySelector( '[data-akb-welcome-issued-box]' );

							if ( existingBox2 ) {
								existingBox2.hidden = true;
							}
							if ( issuedBox2 ) {
								issuedBox2.hidden = false;
							}
							if ( codeEl ) {
								codeEl.textContent = data.coupon_code;
							}
							if ( expiresEl && data.expires_date ) {
								expiresEl.textContent = data.expires_date;
							}
							if ( shopLink && data.shop_url ) {
								shopLink.setAttribute( 'href', data.shop_url );
							}
							if ( successMsgEl && data.message ) {
								successMsgEl.textContent = data.message;
							}
							if ( stepForm ) {
								stepForm.hidden = true;
							}
							if ( stepSuccess ) {
								stepSuccess.hidden = false;
							}
							if ( copyBtn ) {
								copyBtn.focus();
							}
							return;
						}
					}

					if ( window.turnstile && turnstileWidgetId !== null ) {
						window.turnstile.reset( turnstileWidgetId );
					}
					showMessage(
						data.message || 'No pudimos generar tu cupón en este momento. Intenta de nuevo.',
						true
					);
				} )
				.catch( function () {
					if ( submitBtn ) {
						submitBtn.disabled = false;
						submitBtn.classList.remove( 'is-loading' );
					}
					if ( window.turnstile && turnstileWidgetId !== null ) {
						window.turnstile.reset( turnstileWidgetId );
					}
					showMessage( 'Ocurrió un problema de conexión. Por favor intenta de nuevo.', true );
				} );
		} );
	}

	// Botón de copiar código en 1 clic (funciona en bienvenida y en promo estática)
	if ( copyBtn && codeEl ) {
		copyBtn.addEventListener( 'click', function () {
			var code = ( codeEl.textContent || config.promoCode || '' ).trim();
			if ( ! code ) {
				return;
			}

			var markCopied = function () {
				copyBtn.textContent = '¡Copiado!';
				copyBtn.classList.add( 'is-copied' );
				window.setTimeout( function () {
					copyBtn.textContent = 'Copiar código';
					copyBtn.classList.remove( 'is-copied' );
				}, 2500 );
			};

			if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
				navigator.clipboard.writeText( code ).then( markCopied ).catch( function () {} );
			} else {
				var temp = document.createElement( 'textarea' );
				temp.value = code;
				document.body.appendChild( temp );
				temp.select();
				try {
					document.execCommand( 'copy' );
					markCopied();
				} catch ( err ) {}
				document.body.removeChild( temp );
			}
		} );
	}

	// Si el visitante ya completó el flujo (tiene o usó cupón), nunca mostrar popup ni launcher.
	if ( mode === 'welcome' && isCompleted() && ! isPreviewRequested() ) {
		if ( launcher ) {
			launcher.hidden = true;
		}
		return;
	}
	if ( ! isPreviewRequested() && ! isCampaignAudienceEligible() ) {
		return;
	}

	// Si ya cerró el modal en una sesión previa y está dentro de los días de enfriamiento,
	// no mostramos el popup automático, pero si el launcher está habilitado, lo dejamos visible.
	if ( isAlreadyDismissed() && ! isPreviewRequested() ) {
		if ( config.launcher && launcher && ! isLauncherDismissed() ) {
			launcher.hidden = false;
			launcher.classList.add( 'is-visible' );
		}
		return;
	}

	if ( ! isEligibleVisitor() ) {
		return;
	}

	// Iniciar disparadores automáticos (preview, temporizador, scroll, intención de salida)
	if ( isPreviewRequested() ) {
		openModal( true );
	} else {
		var configuredDelay = parseInt( config.delaySeconds, 10 );
		var delayMs = Math.max( 0, isNaN( configuredDelay ) ? 20 : configuredDelay ) * 1000;
		timerId = window.setTimeout( function () {
			openModal( false );
		}, delayMs );
		window.addEventListener( 'scroll', onScrollCheck, { passive: true } );

		if ( config.exitIntent ) {
			document.addEventListener( 'mouseleave', onMouseLeave );
		}
	}
} )();
