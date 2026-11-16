

================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/assets/css/account-rewards.css
================================================================

.akb-rw-account {
	color: var(--aki-white, #ffffff);
}

.akb-rw-balance-card {
	background: var(--aki-surface-1, #121212);
	border: 2px solid var(--aki-black, #000000);
	border-top: 3px solid var(--aki-red, #E60012);
	box-shadow: 4px 4px 0 var(--aki-black, #000000);
	padding: 18px 20px;
	margin: 0 0 20px;
}

.akb-rw-balance-card__label {
	font-size: 0.78rem;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.08em;
	color: var(--aki-gray-300, #b3b3b3);
	margin: 0 0 4px;
}

.akb-rw-balance-card__value {
	font-family: var(--font-heading, "Bebas Neue", sans-serif);
	font-size: 2.5rem;
	font-weight: 400;
	font-synthesis: none;
	line-height: 1.05;
	color: var(--aki-yellow, #FFCC00);
	margin: 0 0 6px;
	font-variant-numeric: tabular-nums;
}

.akb-rw-balance-card__unit {
	font-family: var(--font-body, Inter, sans-serif);
	font-size: 1rem;
	font-weight: 600;
	color: var(--aki-white, #ffffff);
}

.akb-rw-balance-card__equiv {
	margin: 0;
	font-size: 0.9rem;
	color: var(--aki-gray-300, #b3b3b3);
}

.akb-rw-active-intent {
	background: var(--aki-surface-1, #121212);
	border: 2px solid var(--aki-black, #000000);
	border-left: 5px solid var(--aki-success, #4ADE80);
	box-shadow: 4px 4px 0 var(--aki-black, #000000);
	padding: 12px 16px;
	border-radius: 0;
	color: var(--aki-white, #ffffff);
	margin: 0 0 12px;
}

.akb-rw-active-intent__link {
	color: var(--aki-yellow, #FFCC00);
	font-weight: 700;
	text-decoration: underline;
}

.akb-rw-account .akb-rw-redeem {
	background: var(--aki-surface-1, #121212);
	border: 2px solid var(--aki-black, #000000);
	border-left: 4px solid var(--aki-red, #E60012);
	box-shadow: 4px 4px 0 var(--aki-black, #000000);
	padding: 18px 20px;
	margin: 0 0 24px;
}

.akb-rw-account .akb-rw-redeem label { display: block; margin-bottom: 6px; font-weight: 600; }
.akb-rw-account .akb-rw-redeem-controls,
.akb-rw-account .akb-rw-pages { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.akb-rw-account #akb-rw-points { min-height: 44px; width: 140px; max-width: 100%; background: var(--aki-surface-2, #1A1A1A); color: var(--aki-white, #ffffff); border: 2px solid var(--aki-input-border, #707070); border-radius: 0; padding: 0 10px; }
.akb-rw-account .akb-rw-redeem button,
.akb-rw-account .akb-rw-pages a { min-height: 44px; white-space: normal; }
.akb-rw-account .akb-rw-max[hidden] { display: none; }
.akb-rw-account #akb-rw-preview { margin: 10px 0 6px; font-weight: 600; }
.akb-rw-account #akb-rw-rules { margin: 0 0 12px; font-size: 14px; line-height: 1.6; color: var(--aki-gray-300, #b3b3b3); }
.akb-rw-account .akb-rw-redeem-submit { background: var(--aki-buy-bg, #CC0010); color: var(--aki-buy-fg, #FFFFFF); font-weight: 700; border-radius: 0; }
.akb-rw-account .akb-rw-redeem-submit:hover:not(:disabled) { background: var(--aki-buy-bg-hover, #99000C); color: var(--aki-buy-fg, #FFFFFF); }
.akb-rw-account .akb-rw-expiry { margin: 0 0 20px; line-height: 1.6; padding: 10px 14px; background: var(--aki-surface-1, #121212); border-left: 4px solid var(--aki-warning, #F59E0B); }
.akb-rw-account .akb-rw-pages { margin: 12px 0 20px; }
.akb-rw-account .akb-rw-pages a { display: inline-flex; align-items: center; }
.akb-rw-account .akb-rw-history-table { max-width: 100%; overflow-x: auto; border: 2px solid var(--aki-black, #000000); box-shadow: 4px 4px 0 var(--aki-black, #000000); background: var(--aki-surface-1, #121212); }
.akb-rw-account .akb-rw-history-table td:not(:last-child) { overflow-wrap: anywhere; }
.akb-rw-account .akb-rw-history-table th:last-child,
.akb-rw-account .akb-rw-history-table td:last-child { width: 1%; white-space: nowrap; overflow-wrap: normal; font-variant-numeric: tabular-nums; }
.akb-rw-account details { background: var(--aki-surface-1, #121212); border: 2px solid var(--aki-border, #2A2A2A); border-left: 4px solid var(--aki-red, #E60012); padding: 0 16px 12px; }
.akb-rw-account .akb-rw-redeem :focus-visible,
.akb-rw-account .akb-rw-pages a:focus-visible { outline: 2px solid var(--aki-white, #ffffff); outline-offset: 2px; }


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/assets/js/account-rewards.js
================================================================

/* El servidor resuelve el máximo aplicable; esta ayuda sólo presenta el importe. */
(function () {
    'use strict';
    function boot() {
        document.querySelectorAll('[data-akb-redeem]').forEach(function (form) {
            var input = form.querySelector('[name="akb_rw_points"]');
            var preview = form.querySelector('#akb-rw-preview');
            var maximum = form.querySelector('.akb-rw-max');
            var config;
            try { config = JSON.parse(form.dataset.akbRedeem); } catch (e) { return; }
            if (!input || !preview || !maximum || !Number.isSafeInteger(config.value) || config.value <= 0) { return; }
            if (!config.eligible || input.disabled || maximum.disabled) { maximum.hidden = false; return; }
            var pointFormat = new Intl.NumberFormat(document.documentElement.lang || 'es-CL');
            function money(amount) {
                var parts = amount.toFixed(config.decimals).split('.');
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, config.thousand);
                return config.format.replace('%1$s', config.symbol).replace('%2$s', parts.join(config.decimal));
            }
            function update() {
                var points = Number(input.value);
                if (input.value === '') {
                    preview.textContent = 'Escribe cuántos puntos quieres canjear para ver el descuento aplicable en este carrito.';
                } else if (!Number.isSafeInteger(points) || !Number.isSafeInteger(points * config.value) || points <= 0 || !input.validity.valid) {
                    preview.textContent = 'Ingresa un número entero de puntos entre ' + pointFormat.format(Number(input.min)) + ' y ' + pointFormat.format(Number(input.max)) + '.';
                } else {
                    preview.textContent = pointFormat.format(points) + ' puntos: ' + money(points * config.value) + ' de descuento aplicable en tu carrito actual.';
                }
            }
            maximum.hidden = false;
            maximum.addEventListener('click', function () {
                input.value = input.max;
                update();
                input.focus();
            });
            input.addEventListener('input', update);
            input.addEventListener('change', update);
            update();
        });

        /* Copiado y compartido del enlace de invitación a un amigo */
        if (typeof document.querySelectorAll === 'function') {
            try {
                var copyBtns = document.querySelectorAll('.akb-rw-copy-btn');
                if (copyBtns && copyBtns.forEach) {
                    copyBtns.forEach(function (btn) {
                        if (!btn || typeof btn.getAttribute !== 'function') { return; }
                        var url = btn.getAttribute('data-akb-copy-url');
                        if (!url || typeof btn.addEventListener !== 'function') { return; }
                        var originalHtml = btn.innerHTML;
                        btn.addEventListener('click', function () {
                            function feedback() {
                                btn.innerHTML = '✅ ¡Copiado!';
                                setTimeout(function () { btn.innerHTML = originalHtml; }, 2500);
                            }
                            if (typeof navigator !== 'undefined' && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                                navigator.clipboard.writeText(url).then(feedback).catch(function () {
                                    feedback();
                                });
                            } else {
                                feedback();
                            }
                        });
                    });
                }
            } catch (e) {}

            try {
                if (typeof navigator !== 'undefined' && typeof navigator.share === 'function') {
                    var shareBtns = document.querySelectorAll('.akb-rw-share-btn');
                    if (shareBtns && shareBtns.forEach) {
                        shareBtns.forEach(function (btn) {
                            if (!btn || typeof btn.getAttribute !== 'function' || typeof btn.addEventListener !== 'function') { return; }
                            btn.style.display = 'inline-flex';
                            btn.addEventListener('click', function () {
                                navigator.share({
                                    title: btn.getAttribute('data-akb-share-title') || 'Akibara Manga',
                                    text: btn.getAttribute('data-akb-share-text') || '',
                                    url: btn.getAttribute('data-akb-share-url') || (typeof window !== 'undefined' ? window.location.href : '')
                                }).catch(function () {});
                            });
                        });
                    }
                }
            } catch (e) {}
        }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
}());


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/assets/js/admin-customers.js
================================================================

/* WooCommerce Customers extension. Uses report hooks, never DOM patching. */
(function (hooks, element) {
    'use strict';
    if (!hooks || !element) return;
    var namespace = 'akibara-rewards/customers';

    hooks.addFilter('woocommerce_admin_customers_report_filters', namespace, function (filters) {
        return filters.concat([{
            label: 'Puntos Akibara', param: 'akb_points',
            staticParams: ['paged', 'per_page', 'filter'], showFilters: function () { return true; },
            filters: [
                { label: 'Todos los saldos', value: 'all' },
                { label: 'Con saldo', value: 'positive' },
                { label: 'Saldo cero (con cuenta)', value: 'zero' },
                { label: 'Sin cuenta', value: 'guest' }
            ]
        }]);
    });

    hooks.addFilter('woocommerce_admin_customers_report_advanced_filters', namespace, function (settings) {
        return Object.assign({}, settings, { filters: Object.assign({}, settings.filters, {
            akb_balance: {
                labels: { add: 'Saldo de puntos', remove: 'Quitar filtro de puntos', rule: 'Saldo de puntos <rule/> <filter/>' },
                rules: [
                    { value: 'min', label: 'Más de' },
                    { value: 'max', label: 'Menos de' },
                    { value: 'between', label: 'Entre (incluidos)' }
                ],
                input: { component: 'Number' }
            }
        }) });
    });

    hooks.addFilter('woocommerce_admin_report_table', namespace, function (table) {
        if (table.endpoint !== 'customers') return table;
        var customers = (table.items && table.items.data) || [];
        var headers = table.headers.concat([
            { label: 'Cuenta', key: 'akb_account' },
            { label: 'Puntos Akibara', key: 'akb_points', isNumeric: true },
            { label: 'Puntos', key: 'akb_manage' }
        ]);
        var rows = table.rows.map(function (row, index) {
            var rewards = (customers[index] && customers[index].akb_rewards) || {};
            var hasAccount = rewards.has_account === true;
            var balance = hasAccount ? Number(rewards.balance) : null;
            var action = hasAccount && rewards.manage_url ? element.createElement('a', { href: rewards.manage_url }, 'Gestionar puntos') : '—';
            return row.concat([
                { display: hasAccount ? 'Con cuenta' : 'Sin cuenta', value: hasAccount ? 'Con cuenta' : 'Sin cuenta' },
                { display: hasAccount ? balance.toLocaleString('es-CL') : '—', value: balance },
                { display: action, value: '' }
            ]);
        });
        return Object.assign({}, table, { headers: headers, rows: rows });
    });
})(window.wp && window.wp.hooks, window.wp && window.wp.element);


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/assets/js/admin-settings.js
================================================================

/** Explicit save feedback for the complete Rewards settings form. */
(function () {
    'use strict';
    const form = document.querySelector('[data-akb-rewards-settings]');
    if (!form) return;
    const status = form.querySelector('[data-akb-settings-status]');
    const snapshot = () => JSON.stringify(Array.from(new FormData(form).entries()));
    const saved = snapshot();
    let dirty = false;
    const update = () => {
        dirty = snapshot() !== saved;
        status.textContent = dirty
            ? 'Tienes cambios sin guardar. Pulsa Guardar ajustes para aplicarlos.'
            : 'Los cambios se aplican al guardar.';
    };
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (event) => {
        if (!dirty) return;
        event.preventDefault();
        event.returnValue = '';
    });
}());


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/assets/js/carousel.js
================================================================

/**
 * Navegación y animación del carrusel «Voces de Akibara» (akibara-rewards).
 *
 * Progressive enhancement accesible y seguro:
 *  - Navegación precisa por tarjeta (offsetLeft relativo), sin desalineaciones por padding o gap.
 *  - Control de fronteras (inicio/fin): los botones < y > se deshabilitan cuando no hay
 *    más elementos en esa dirección, impidiendo el overscroll al vacío.
 *  - Auto-hide dinámico: si todas las tarjetas caben sin scroll (como en grilla de escritorio),
 *    la botonera se oculta automáticamente.
 *  - Reveal GSAP seguro sobre el contenedor: nunca deja tarjetas con opacity: 0
 *    en un contenedor con desplazamiento horizontal.
 *  - Soporte de teclado (Flechas Izquierda / Derecha) en el track con tabindex.
 */
( function () {
	'use strict';

	function initNav() {
		var carousel = document.querySelector( '.akb-rw-carousel' );
		if ( ! carousel ) {
			return;
		}

		var track = carousel.querySelector( '.akb-rw-track' );
		var nav = carousel.querySelector( '.akb-rw-nav' );
		var prevBtn = carousel.querySelector( '[data-akb-rw-prev]' );
		var nextBtn = carousel.querySelector( '[data-akb-rw-next]' );

		if ( ! track || ! prevBtn || ! nextBtn ) {
			return;
		}

		function getCards() {
			return Array.prototype.slice.call( track.querySelectorAll( '.akb-rw-card' ) );
		}

		function getMaxScroll() {
			return Math.max( 0, track.scrollWidth - track.clientWidth );
		}

		function getCardPosition( card ) {
			if ( card.offsetParent === track ) {
				return card.offsetLeft;
			}
			return card.offsetLeft - track.offsetLeft;
		}

		function updateNavState() {
			var maxScroll = getMaxScroll();

			// Si todas las tarjetas entran en la pantalla sin scroll (ej. grid de escritorio)
			if ( maxScroll <= 6 ) {
				if ( nav ) {
					nav.setAttribute( 'data-hidden', 'true' );
				}
				prevBtn.disabled = true;
				nextBtn.disabled = true;
				prevBtn.setAttribute( 'aria-disabled', 'true' );
				nextBtn.setAttribute( 'aria-disabled', 'true' );
				return;
			}

			if ( nav ) {
				nav.removeAttribute( 'data-hidden' );
			}

			var currentScroll = track.scrollLeft;
			var atStart = currentScroll <= 6;
			var atEnd = currentScroll >= ( maxScroll - 6 );

			prevBtn.disabled = atStart;
			prevBtn.setAttribute( 'aria-disabled', atStart ? 'true' : 'false' );

			nextBtn.disabled = atEnd;
			nextBtn.setAttribute( 'aria-disabled', atEnd ? 'true' : 'false' );
		}

		function scrollToCard( direction ) {
			var cards = getCards();
			if ( ! cards.length ) {
				return;
			}

			var maxScroll = getMaxScroll();
			var currentScroll = track.scrollLeft;

			if ( direction > 0 ) {
				// Avanzar: buscar la primera tarjeta cuyo inicio relativo esté por delante del scroll
				var nextTarget = null;
				for ( var i = 0; i < cards.length; i++ ) {
					var pos = getCardPosition( cards[ i ] );
					if ( pos > currentScroll + 15 ) {
						nextTarget = pos;
						break;
					}
				}

				if ( null === nextTarget ) {
					return;
				}

				track.scrollTo( {
					left: Math.min( maxScroll, nextTarget ),
					behavior: 'smooth'
				} );
			} else {
				// Retroceder: buscar la última tarjeta cuyo inicio relativo esté por detrás del scroll
				var prevTarget = 0;
				for ( var j = cards.length - 1; j >= 0; j-- ) {
					var p = getCardPosition( cards[ j ] );
					if ( p < currentScroll - 15 ) {
						prevTarget = p;
						break;
					}
				}

				track.scrollTo( {
					left: Math.max( 0, prevTarget ),
					behavior: 'smooth'
				} );
			}
		}

		prevBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( prevBtn.disabled || prevBtn.getAttribute( 'aria-disabled' ) === 'true' ) {
				return;
			}
			scrollToCard( -1 );
		} );

		nextBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( nextBtn.disabled || nextBtn.getAttribute( 'aria-disabled' ) === 'true' ) {
				return;
			}
			scrollToCard( 1 );
		} );

		var scrollTicking = false;
		track.addEventListener( 'scroll', function () {
			if ( ! scrollTicking ) {
				window.requestAnimationFrame( function () {
					updateNavState();
					scrollTicking = false;
				} );
				scrollTicking = true;
			}
		}, { passive: true } );

		var resizeTimer = null;
		window.addEventListener( 'resize', function () {
			window.clearTimeout( resizeTimer );
			resizeTimer = window.setTimeout( updateNavState, 100 );
		}, { passive: true } );

		track.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowLeft' ) {
				e.preventDefault();
				scrollToCard( -1 );
			} else if ( e.key === 'ArrowRight' ) {
				e.preventDefault();
				scrollToCard( 1 );
			}
		} );

		updateNavState();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initNav );
	} else {
		initNav();
	}

	if ( typeof gsap === 'undefined' || typeof ScrollTrigger === 'undefined' ) {
		return;
	}

	var FOLD = 0.92;

	function init() {
		var carousel = document.querySelector( '.akb-rw-carousel' );
		if ( ! carousel ) {
			return;
		}

		gsap.registerPlugin( ScrollTrigger );

		gsap.matchMedia().add( '(prefers-reduced-motion: no-preference)', function () {
			var cards = carousel.querySelectorAll( '.akb-rw-card' );
			if ( ! cards.length ) {
				return;
			}

			function restoreAll() {
				gsap.set( cards, { clearProps: 'opacity,transform' } );
			}

			var trigger = null;
			try {
				gsap.set( cards, { opacity: 0, y: 12 } );
				trigger = ScrollTrigger.create( {
					trigger: carousel,
					start: 'top ' + Math.round( FOLD * 100 ) + '%',
					once: true,
					onEnter: function () {
						gsap.to( cards, {
							opacity: 1,
							y: 0,
							duration: 0.36,
							ease: 'power3.out',
							stagger: 0.06,
							clearProps: 'opacity,transform'
						} );
					}
				} );
			} catch ( e ) {
				restoreAll();
			}

			return function () {
				if ( trigger ) {
					trigger.kill();
				}
				restoreAll();
			};
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/assets/js/checkout-blocks.js
================================================================

/**
 * Caja de canje + gancho crea-cuenta en el checkout de bloques.
 *
 * JS PLANO a propósito (regla de UI liviana): cero build, cero framework
 * propio — solo los globals que las dependencias encoladas ya cargan
 * (wp.plugins, wp.element, wp.data, wc.blocksCheckout). El servidor manda:
 * los datos viajan por extensions['akibara-rewards'] del Store API y los
 * clamps viven en PHP; este archivo solo pinta y despacha.
 */
( function () {
	'use strict';

	if ( ! window.wp || ! window.wc || ! wc.blocksCheckout || ! wp.plugins || ! wp.element || ! wp.data ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var registerPlugin = wp.plugins.registerPlugin;
	var ExperimentalDiscountsMeta = wc.blocksCheckout.ExperimentalDiscountsMeta;
	var ExperimentalOrderMeta = wc.blocksCheckout.ExperimentalOrderMeta;
	var extensionCartUpdate = wc.blocksCheckout.extensionCartUpdate;

	var NS = 'akibara-rewards';

	function useRewardsData() {
		return useSelect( function ( select ) {
			var store = select( 'wc/store/cart' );
			var cart = store && store.getCartData ? store.getCartData() : null;
			return ( cart && cart.extensions && cart.extensions[ NS ] ) || null;
		}, [] );
	}

	function fmt( n ) {
		try {
			return new Intl.NumberFormat( 'es-CL' ).format( n );
		} catch ( e ) {
			return String( n );
		}
	}

	/** Un solo marco para todas las caras de la caja (eran tres copias del literal). */
	var ESTILO_CAJA = { border: '1px solid #333333', background: 'rgba(255,255,255,.03)', borderRadius: '0', padding: '12px 14px', margin: '8px 0' };

	/**
	 * Puntos en pesos, con el MISMO cambio con que se canjean.
	 *
	 * Decir «500 puntos» obliga al cliente a hacer una cuenta que no tiene por
	 * qué saber hacer; decir «= $500» se entiende de una lectura. El cambio
	 * viaja desde el panel (`redeem_value`): nunca se escribe aquí.
	 */
	function enPesos( data, puntos ) {
		return '$' + fmt( puntos * ( data && data.redeem_value > 0 ? data.redeem_value : 1 ) );
	}

	/**
	 * Lo que ESTE pedido suma, para quien ya tiene cuenta.
	 *
	 * El invitado veía el número y el registrado no: se enteraba de sus puntos
	 * después de pagar. Va en las cuatro caras de la caja —incluida la del saldo
	 * 0, que antes no se pintaba— porque es justo el cliente recién registrado
	 * el que necesita ver que acumula aunque todavía no pueda canjear.
	 *
	 * El número sale del servidor (`earn_now`, la misma función que acredita) y
	 * baja solo al aplicar un canje: no se ganan puntos sobre lo pagado con
	 * puntos.
	 */
	function lineaGana( data ) {
		if ( ! data || ! ( data.earn_now > 0 ) ) {
			return null;
		}

		return el(
			'p',
			{ className: 'akb-rw-gana', style: { margin: '8px 0 0', fontSize: '13px' } },
			'⭐ Este pedido suma ' + fmt( data.earn_now ) + ' puntos = ' + enPesos( data, data.earn_now )
				+ ' para tus próximas compras.'
		);
	}

	/** Canje explícito del carrito actual; el servidor resuelve todos los límites. */
	function CajaDeCanje() {
		var data = useRewardsData();
		var estado = useState( '' );
		var valor = estado[ 0 ];
		var setValor = estado[ 1 ];
		var ocupado = useState( false );
		var enviando = ocupado[ 0 ];
		var setEnviando = ocupado[ 1 ];
		var avisoEstado = useState( '' );
		var aviso = avisoEstado[ 0 ];
		var setAviso = avisoEstado[ 1 ];
		if ( ! data || ! data.logged_in ) {
			return null;
		}

		var aplicar = function ( puntos ) {
			setEnviando( true );
			setAviso( '' );
			return extensionCartUpdate( { namespace: NS, data: { points: puntos } } )
				.catch( function ( error ) {
					setAviso( error && error.message ? error.message : 'No pudimos confirmar el canje. Revisa el carrito e inténtalo de nuevo.' );
				} ).finally( function () { setEnviando( false ); } );
		};
		var botonQuitar = function () {
			return el( 'button', {
				type: 'button', disabled: enviando,
				style: { textDecoration: 'underline', minHeight: '44px', background: 'none', border: 0, cursor: 'pointer', padding: 0, font: 'inherit', color: 'inherit', display: 'inline-flex', alignItems: 'center' },
				onClick: function () { return aplicar( 0 ); },
			}, 'Quitar canje' );
		};

		// 0 es un máximo no aplicable. Nunca significa «usar todo el saldo».
		var min = Math.max( 1, data.min || 1 );
		var techo = Math.max( 0, parseInt( data.max, 10 ) || 0 );
		var efectivo = Math.max( 0, parseInt( data.effective, 10 ) || 0 );
		var vigente = data.intent > 0 ? el( 'p', { style: { margin: '8px 0 0', fontSize: '13px' } },
			efectivo > 0
				? 'Canje aplicado: ' + fmt( efectivo ) + ' puntos = ' + enPesos( data, efectivo ) + '. '
				: 'Tu canje guardado de ' + fmt( data.intent ) + ' puntos no se aplica en este carrito. ',
			botonQuitar()
		) : null;
		var avisoVisible = el( 'p', {
			role: 'status', 'aria-live': 'polite',
			style: aviso ? { margin: '8px 0 0', fontSize: '13px', fontWeight: 600 } : { margin: 0 },
		}, aviso );
		if ( techo < min ) {
			if ( data.balance <= 0 && ! ( data.intent > 0 ) ) {
				return lineaGana( data ) ? el( 'div', { className: 'akb-rw-checkout-box', style: ESTILO_CAJA },
					el( 'p', { style: { margin: '0 0 6px', fontWeight: 600 } }, '⭐ Tus Puntos Akibara' ), lineaGana( data ) ) : null;
			}
			return el( 'div', { className: 'akb-rw-checkout-box', style: ESTILO_CAJA },
				el( 'p', { style: { margin: '0 0 6px', fontWeight: 600 } }, '⭐ Tus Puntos Akibara' ),
				el( 'p', { style: { margin: 0, fontSize: '13px' } },
					'Tienes ' + fmt( data.balance ) + ' puntos. ' + ( data.unavailable || ( data.missing > 0 ? 'Te faltan $' + fmt( data.missing ) + ' en productos para poder canjear.' : 'No hay un canje disponible en este carrito.' ) ) ),
				vigente, avisoVisible, lineaGana( data ) );
		}

		var puntos = Number( valor );
		var valido = valor !== '' && Number.isSafeInteger( puntos ) && puntos >= min && puntos <= techo;
		var preview = valido ? fmt( puntos ) + ' puntos: ' + enPesos( data, puntos ) + ' de descuento en este carrito.'
			: valor === '' ? 'Elige cuántos puntos quieres usar; el canje requiere tu confirmación.'
				: 'Ingresa un número entero entre ' + fmt( min ) + ' y ' + fmt( techo ) + ' puntos.';
		return el( 'div', { className: 'akb-rw-checkout-box', style: ESTILO_CAJA },
			el( 'p', { style: { margin: '0 0 6px', fontWeight: 600 } }, '⭐ Tus Puntos Akibara' ),
			el( 'p', { style: { margin: '0 0 8px', fontSize: '13px' } },
				'Tienes ' + fmt( data.balance ) + ' puntos (1 punto = ' + enPesos( data, 1 ) + '). El canje parte desde ' + fmt( min ) + '.'
					+ ( data.min_cart > 0 ? ' Se canjean en compras desde $' + fmt( data.min_cart ) + ' en productos.' : '' )
					+ ' Máximo disponible en este carrito: ' + fmt( techo ) + ' puntos.' ),
			el( 'div', { style: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center' } },
				el( 'label', { htmlFor: 'akb-rw-canje', className: 'screen-reader-text' }, 'Puntos a canjear' ),
				el( 'input', {
					id: 'akb-rw-canje', type: 'number', min: min, max: techo, step: 1, value: valor,
					'aria-describedby': 'akb-rw-cart-preview', placeholder: String( min ),
					style: { width: '7em', minHeight: '44px' },
					onChange: function ( ev ) { setValor( ev.target.value ); setAviso( '' ); },
				} ),
				el( 'button', {
					type: 'button', className: 'wc-block-components-button wp-element-button', disabled: enviando,
					style: { minHeight: '44px' },
					onClick: function () {
						setValor( String( techo ) ); setAviso( '' );
						var input = document.getElementById( 'akb-rw-canje' );
						if ( input ) { input.focus(); }
					},
				}, 'Usar el máximo disponible' ),
				el( 'button', {
					type: 'button', className: 'wc-block-components-button wp-element-button',
					style: { minHeight: '44px' }, disabled: enviando || ! valido,
					onClick: function () { if ( valido ) { return aplicar( puntos ); } },
				}, enviando ? 'Aplicando…' : 'Confirmar canje' )
			),
			el( 'p', { id: 'akb-rw-cart-preview', role: 'status', 'aria-live': 'polite', style: { margin: '8px 0 0', fontSize: '13px' } }, preview ),
			avisoVisible, vigente, lineaGana( data ) );
	}

	/* El botón activa la casilla nativa de crear cuenta, la centra y le da foco. El clic sobre el input conserva el onChange y la validación de React; si aún no existe, no actúa. */
	var CASILLA_CUENTA = '.wc-block-checkout__create-account';
	var resalte = 0;
	var ultimaAccion = 0;

	/* CTA sólido dentro del panel de puntos. box-sizing incluye el padding en el ancho del 100 % para mantenerlo dentro de la caja. */
	var ESTILO_BOTON = { display: 'block', boxSizing: 'border-box', width: '100%', minHeight: '44px', margin: '14px 0 2px', padding: '11px 14px', border: 0, borderRadius: '0', background: '#E60012', color: '#ffffff', fontFamily: 'inherit', fontSize: '15px', fontWeight: 700, lineHeight: 1.3, textAlign: 'center', textTransform: 'none', letterSpacing: 'normal', cursor: 'pointer' };
	var ESTILO_ENLACE = { display: 'inline-block', minHeight: '44px', margin: '2px 0 0', padding: 0, border: 0, background: 'transparent', color: 'inherit', fontFamily: 'inherit', fontSize: '13px', fontWeight: 400, textDecoration: 'underline', textTransform: 'none', letterSpacing: 'normal', cursor: 'pointer' };

	function llevarACasillaDeCuenta( quiere ) {
		var caja = document.querySelector( CASILLA_CUENTA );
		var input = caja && caja.querySelector( 'input[type="checkbox"]' );
		if ( ! input ) {
			return;
		}
		// Un doble toque no puede marcar y desmarcar: tras el primero el botón
		// pasa a «Prefiero comprar sin cuenta» y el segundo caería sobre él.
		var ahora = Date.now();
		if ( ahora - ultimaAccion < 700 ) {
			return;
		}
		ultimaAccion = ahora;
		if ( input.checked !== quiere ) {
			input.click();
		}
		var quieto = !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
		caja.scrollIntoView( { behavior: quieto ? 'auto' : 'smooth', block: 'center' } );
		input.focus( { preventScroll: true } );

		// Tras un toque el navegador no dibuja el anillo de foco (no hubo
		// teclado): este resalte breve le dice al ojo dónde quedó la casilla.
		caja.style.outline = '3px solid #FFFFFF';
		caja.style.outlineOffset = '6px';
		clearTimeout( resalte );
		resalte = setTimeout( function () {
			caja.style.outline = '';
			caja.style.outlineOffset = '';
		}, 2400 );
	}

	/** Ajuste público del checkout de WooCommerce (wcSettings). */
	function ajusteDeWoo( clave ) {
		try {
			return !! ( wc.wcSettings && wc.wcSettings.getSetting( clave, false ) );
		} catch ( e ) {
			return false;
		}
	}

	/** Gancho crea-cuenta para INVITADOS: beneficios concretos, config viva. */
	function GanchoCreaCuenta( props ) {
		var data = useRewardsData();
		var marcada = useSelect( function ( select ) {
			var checkout = select( 'wc/store/checkout' );
			return !! ( checkout && checkout.getShouldCreateAccount && checkout.getShouldCreateAccount() );
		}, [] );

		if ( ! data || data.logged_in || ! data.teaser ) {
			return null;
		}

		var lineas = [ '📦 Sigue tu envío Blue Express desde Mi cuenta' ];
		if ( data.welcome_bonus > 0 ) {
			lineas.push(
				'🎁 ' + fmt( data.welcome_bonus ) + ' puntos de bienvenida = ' + enPesos( data, data.welcome_bonus )
					+ ( data.first_min > 0 ? ', en tu primera compra desde $' + fmt( data.first_min ) : ', en tu primera compra' )
					+ ' (uno por persona)'
			);
		}
		// Con carrito, lo CONCRETO: lo que suma este pedido. Sin carrito todavía
		// —el gancho también se pinta con el carrito vacío—, la regla general.
		if ( data.earn_now > 0 ) {
			/* La indicación «Para tus próximas compras» acompaña al saldo incluso cuando el bono de bienvenida está desactivado y no se muestra la línea total. */
			lineas.push(
				'⭐ Este pedido suma ' + fmt( data.earn_now ) + ' puntos = ' + enPesos( data, data.earn_now )
					+ ' para tus próximas compras'
			);
		} else if ( data.earn_points > 0 && data.earn_amount > 0 ) {
			lineas.push( '⭐ Acumulas ' + fmt( data.earn_points ) + ' puntos por cada $' + fmt( data.earn_amount ) + ' en cada compra' );
		}
		if ( data.earn_now > 0 && data.welcome_bonus > 0 ) {
			/* Los puntos se acreditan en el estado de pedido configurado, después del pago. «HASTA» refleja la elegibilidad individual del bono; «próximas compras» respeta los límites de canje por pedido y evita prometer un descuento inmediato. */
			lineas.push(
				'💰 En total hasta ' + enPesos( data, data.welcome_bonus + data.earn_now )
					+ ' para tus próximas compras'
					+ ( data.min_cart > 0 ? ' desde $' + fmt( data.min_cart ) : '' )
			);
		}

		// El bloque Carrito también monta este gancho (mismo `scope`), pero la
		// casilla sólo existe en el checkout, y allí sólo si WooCommerce admite
		// invitados Y registro: fuera de eso no hay botón que ofrecer.
		var enCheckout = ! props || ! props.context || 'woocommerce/checkout' === props.context;
		var conCasilla = enCheckout && ajusteDeWoo( 'checkoutAllowsGuest' ) && ajusteDeWoo( 'checkoutAllowsSignup' );
		var titulo = 'Con una cuenta en Akibara te llevas más:';
		if ( conCasilla ) {
			titulo = marcada ? '✓ Crearemos tu cuenta con este pedido' : 'Crea tu cuenta y llévate más:';
		}

		var hijos = [
			el( 'p', { key: 'titulo', style: { margin: '0 0 6px', fontWeight: 600 } }, titulo ),
			el(
				'ul',
				{ key: 'lineas', style: { margin: 0, paddingLeft: '18px', fontSize: '13px', lineHeight: 1.7 } },
				lineas.map( function ( texto, i ) {
					return el( 'li', { key: i }, texto );
				} )
			),
		];
		if ( conCasilla && marcada ) {
			// Con contraseña generada, WooCommerce manda el enlace para elegirla en
			// el correo de cuenta nueva; si no, pide el campo junto a la casilla.
			hijos.push(
				el(
					'p',
					{ key: 'clave', style: { margin: '8px 0 0', fontSize: '13px' } },
					ajusteDeWoo( 'generatePassword' ) ? 'Te llegará un correo para elegir tu contraseña.' : 'Elige tu contraseña junto a la casilla.'
				)
			);
		}
		if ( conCasilla ) {
			hijos.push(
				el(
					'button',
					{
						key: 'accion',
						type: 'button',
						className: 'akb-rw-guest-hook__action',
						style: marcada ? ESTILO_ENLACE : ESTILO_BOTON,
						onClick: function () {
							llevarACasillaDeCuenta( ! marcada );
						},
					},
					marcada ? 'Prefiero comprar sin cuenta' : 'Crear mi cuenta'
				)
			);
		} else if ( data.account_url ) {
			// En /carrito/ no hay casilla que marcar: el gancho prometía puntos y
			// no ofrecía NADA que pulsar. El enlace lleva a Mi cuenta —la página
			// que fija WooCommerce, no una ruta escrita aquí— y el carrito sigue
			// en la sesión al volver.
			hijos.push(
				el(
					'a',
					{
						key: 'accion',
						className: 'akb-rw-guest-hook__action',
						href: data.account_url,
						// El mismo botón sólido del checkout; como aquí es un enlace,
						// sólo hay que quitarle el subrayado del tema.
						style: Object.assign( {}, ESTILO_BOTON, { textDecoration: 'none' } ),
					},
					'Crear mi cuenta'
				)
			);
		}

		return el(
			'div',
			{ className: 'akb-rw-guest-hook', style: { border: '1px solid #333333', background: 'rgba(255,255,255,.03)', borderRadius: '0', padding: '12px 14px', margin: '8px 0' } },
			hijos
		);
	}

	/** Aviso discreto si el cliente viene con recomendación de un amigo */
	function AvisoReferido() {
		var data = useRewardsData();
		if ( ! data || ! data.referrer_name ) {
			return null;
		}

		return el(
			'div',
			{
				className: 'akb-rw-referral-checkout-notice',
				style: {
					border: '1px solid #333333',
					borderLeft: '4px solid #E60012',
					background: '#181818',
					color: '#f0f0f0',
					padding: '10px 14px',
					margin: '8px 0',
					fontSize: '13px',
					lineHeight: 1.4,
				},
			},
			'🤝 Estás comprando con la recomendación de ',
			el( 'strong', null, data.referrer_name ),
			'. ¡Ambos recibirán Puntos Akibara al completar tu pedido!'
		);
	}

	registerPlugin( 'akb-rewards-checkout', {
		scope: 'woocommerce-checkout',
		render: function () {
			return el(
				wp.element.Fragment,
				null,
				el( ExperimentalDiscountsMeta, null, el( CajaDeCanje ) ),
				el(
					ExperimentalOrderMeta,
					null,
					el( GanchoCreaCuenta ),
					el( AvisoReferido )
				)
			);
		},
	} );
}() );


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Blocks/CheckoutIntegration.php
================================================================

<?php
/**
 * CheckoutIntegration — integración de caja de canje y fidelización en el checkout de bloques.
 *
 * Tres piezas del contrato de Woo Blocks, sin build ni framework (JS plano
 * con los globals de wc-blocks — mismo patrón que akibara-cart-checkout-
 * blocks, regla de UI liviana):
 *
 *  1. endpoint_data en CartSchema (namespace `akibara-rewards`): saldo, tope
 *     por carrito, mínimo, intención vigente y los datos del gancho de
 *     invitado — TODO desde Options, nada hardcodeado, carriles apagados no
 *     prometen.
 *  2. update callback: recibe {points} de extensionCartUpdate, aplica los
 *     MISMOS clamps que Mi cuenta (mínimo configurable, saldo) y fija la
 *     intención en sesión; el fee existente de Redemption hace los totales.
 *  3. Encolado del JS en el checkout de bloques con las dependencias que
 *     exponen wc.blocksCheckout / wp.plugins / wp.element.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Blocks;

use Akibara\Rewards\Points\Accrual;
use Akibara\Rewards\Points\Calculator;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Support\Assets;
use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class CheckoutIntegration {

	public const IDENTIFIER = 'akibara-rewards';

	/**
	 * Cuelga los datos del Store API y el JS de los bloques Carrito y Checkout.
	 *
	 * El JS se registra con `scope: 'woocommerce-checkout'`, montado por los bloques
	 * de Carrito y Checkout para renderizar los slots de canje de puntos y avisos.
	 *
	 * @return void
	 */
	public function register(): void {
		// woocommerce_blocks_loaded dispara en plugins_loaded y puede ganarle
		// a nuestro boot: si ya pasó, registrar DIRECTO (mismo timing seguro
		// que el plugin hermano de bloques).
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api' ) );
		}
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_classic_referral_notice' ), 5 );
	}

	/**
	 * Datos y callback del Store API.
	 *
	 * @return void
	 */
	public function register_store_api(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' )
			|| ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => self::IDENTIFIER,
				'data_callback'   => array( $this, 'cart_data' ),
				'schema_callback' => array( $this, 'cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::IDENTIFIER,
				'callback'  => array( $this, 'handle_update' ),
			)
		);
	}

	/**
	 * Lo que el checkout necesita saber, desde la config viva.
	 *
	 * @return array<string,mixed>
	 */
	public function cart_data(): array {
		if ( function_exists( 'wp_prime_option_caches' ) ) {
			// Optimización de lecturas en Store API: precarga options requeridas en una sola consulta.
			wp_prime_option_caches(
				array(
					'akb_rewards_redeem_min_points',
					'akb_rewards_redeem_max_pct',
					'akb_rewards_redeem_max_amount',
					'akb_rewards_redeem_value',
					'akb_rewards_earn_enabled',
					'akb_rewards_earn_points',
					'akb_rewards_earn_amount',
					'akb_rewards_welcome_enabled',
					'akb_rewards_welcome_bonus',
					'akb_rewards_welcome_teaser_enabled',
					'akb_rewards_redeem_min_cart',
					'akb_rewards_first_purchase_min',
					'akb_rewards_program_start',
				)
			);
		}

		$user_id = get_current_user_id();
		$cart    = self::cart();
		// Store API ya calculó el carrito. Compartir estos límites evita otra
		// interpretación de mínimos, saldo y reservas en el bloque de compra.
		$limits  = Redemption::redemption_limits( $cart, $user_id );
		$balance = $limits['available'];
		$intent  = Redemption::intent_points();

		// Y para los PUNTOS hay que restar lo que ya se está pagando con puntos,
		// que es lo que hace `award_base_for_order()` al acreditar. Ojo: el canje
		// entra como FEE, no como línea, así que la base de arriba NO baja sola
		// al aplicarlo —recalcular el carrito no cambia esa base— y sin esta
		// resta el checkout prometería puntos sobre dinero que se paga con
		// puntos. Hoy no se ve (el aviso es para invitados y canjear exige
		// cuenta), pero la promesa viajaba inflada en el payload.
		$canje_bruto = self::cart_redeemed_gross();

		// ¿El programa rige HOY? Es la compuerta que aplica la acreditación
		// (`Accrual::in_program()`): con la fecha de inicio en el futuro —o
		// malformada, que es un campo de texto del panel— no acredita nadie, y
		// el aviso del checkout no puede prometer lo contrario.
		$vigente = Accrual::program_open_now();

		$ref_name = '';
		$code     = isset( $_COOKIE[ \Akibara\Rewards\Points\Referrals::COOKIE ] )
			? sanitize_key( (string) wp_unslash( $_COOKIE[ \Akibara\Rewards\Points\Referrals::COOKIE ] ) )
			: '';
		if ( '' !== $code ) {
			$ref_id = \Akibara\Rewards\Points\Referrals::owner_of( $code );
			if ( $ref_id > 0 && $ref_id !== $user_id ) {
				$padrino = get_user_by( 'id', $ref_id );
				if ( $padrino instanceof \WP_User ) {
					$fname    = trim( (string) $padrino->first_name );
					$ref_name = '' !== $fname ? $fname : (string) $padrino->display_name;
				}
			}
		}

		return array(
			'logged_in'     => $user_id > 0,
			'balance'       => $balance,
			'intent'        => $intent,
			'effective'     => Redemption::effective_points_for_limits( $intent, $limits ),
			'unavailable'   => Redemption::unavailable_message( $limits ),
			'min'           => $limits['min_points'],
			// Carrito mínimo para canjear y cuánto le falta a ESTE carrito: la
			// caja no puede calcularlo en el navegador sin duplicar la regla.
			'min_cart'      => Options::redeem_min_cart(),
			'missing'       => $limits['missing'],
			'first_min'     => Options::first_purchase_min(),
			// Tope del canje YA resuelto en puntos para ESTE carrito: la caja
			// no puede recalcularlo en el navegador sin duplicar la regla (y
			// el servidor vuelve a topar en `Redemption::effective_points()`,
			// así que esto es UX, no seguridad). 0 = no se puede canjear.
			'max'           => $limits['max_points'],
			// La regla general («acumulas 2 puntos por cada $100») es la línea de
			// reserva del aviso cuando todavía no hay carrito. Calla con el
			// programa fuera de vigencia por la misma razón que `earn_now`: ahí
			// no acredita nadie, y prometerlo sería mentir.
			'earn_points'   => $vigente ? Options::earn_points() : 0,
			'earn_amount'   => $vigente ? Options::earn_amount() : 0,
			// El bono también calla fuera de vigencia: cuelga del mismo abono que
			// `Accrual::award()` corta, y aquí se promete justo para ESTE pedido.
			'welcome_bonus' => $vigente ? Options::welcome_bonus() : 0,
			// Lo que ESTE carrito acumula, con la MISMA función que después
			// acredita: `points_for()` sobre `award_base_for_cart()`, al que se le
			// pasa el canje para que reste igual que `award_base_for_order()`. El
			// número no se recalcula en el navegador a propósito —una fórmula
			// paralela se desincroniza el día que el dueño cambie el tramo en el
			// panel, y el checkout prometería puntos que luego no se abonan—.
			// Cero sin carrito, y cero también si el programa todavía no rige o su
			// fecha de inicio quedó malformada: ahí no acredita nadie.
			'earn_now'      => $vigente ? Calculator::points_for( self::cart_award_base( $canje_bruto ) ) : 0,
			// Pesos por punto, para poder decir «500 puntos = $500» sin que la
			// caja se invente el cambio. Es el mismo valor con que se canjean.
			'redeem_value'  => Options::redeem_value(),
			// Dónde se crea la cuenta. En /carrito/ el gancho promete puntos y no
			// tenía NADA que pulsar: la casilla «Crear una cuenta» sólo existe en
			// el checkout. Sólo viaja para invitados —el registrado nunca lo lee—
			// y sólo si de verdad se puede crear una cuenta ahí.
			'account_url'   => $user_id > 0 ? '' : self::account_url(),
			'teaser'        => 'yes' === (string) get_option( 'akb_rewards_welcome_teaser_enabled', 'yes' ),
			'referrer_name' => $ref_name,
		);
	}

	/**
	 * Base puntuable del carrito vivo, con el canje ya restado.
	 *
	 * Delega el cálculo de base puntuable a `Calculator::award_base_for_cart()`.
	 *
	 * @param float $canje_bruto Pesos que este carrito paga con puntos.
	 * @return float
	 */
	private static function cart_award_base( float $canje_bruto ): float {
		$cart = self::cart();

		return $cart instanceof \WC_Cart ? Calculator::award_base_for_cart( $cart, $canje_bruto ) : 0.0;
	}

	/**
	 * Página donde el invitado puede CREAR su cuenta, o '' si no la hay.
	 *
	 * `wc_get_page_permalink()` cae a la portada cuando la página de Mi cuenta
	 * no existe, así que preguntar sólo por la URL nunca da vacío y el enlace
	 * del carrito llevaría al home. Y con el registro apagado en WooCommerce la
	 * página existe pero sólo deja entrar: prometer «Crear mi cuenta» ahí es
	 * mandar al cliente a una puerta cerrada.
	 *
	 * @return string
	 */
	private static function account_url(): string {
		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'wc_get_page_permalink' ) ) {
			return '';
		}

		if ( wc_get_page_id( 'myaccount' ) <= 0
			|| 'yes' !== (string) get_option( 'woocommerce_enable_myaccount_registration', 'no' ) ) {
			return '';
		}

		return (string) wc_get_page_permalink( 'myaccount' );
	}

	/**
	 * El carrito vivo, o null si todavía no hay (WooCommerce a medio arrancar).
	 *
	 * @return \WC_Cart|null
	 */
	private static function cart(): ?\WC_Cart {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$wc   = WC();
		$cart = is_object( $wc ) ? ( $wc->cart ?? null ) : null;

		return $cart instanceof \WC_Cart ? $cart : null;
	}

	/**
	 * Lo que este carrito ya está pagando con puntos, en pesos.
	 *
	 * Es la resta que `award_base_for_order()` aplica al acreditar: los puntos no
	 * se ganan sobre dinero pagado con puntos.
	 *
	 * @return float 0.0 si no hay carrito o no hay canje aplicado.
	 */
	private static function cart_redeemed_gross(): float {
		$cart = self::cart();
		if ( ! $cart instanceof \WC_Cart ) {
			return 0.0;
		}

		// Espejo exacto de `Redemption::granted_gross()`, que hace lo mismo sobre
		// el pedido: el fee del canje es el único negativo con ese nombre.
		foreach ( $cart->get_fees() as $fee ) {
			if ( ! is_object( $fee ) || Redemption::FEE_NAME !== ( $fee->name ?? '' ) ) {
				continue;
			}

			// Sólo `total` + `tax`: `amount` es el importe SIN IVA y restaría un
			// 19 % de menos, prometiendo puntos de más.
			$total = (float) ( $fee->total ?? 0 ) + (float) ( $fee->tax ?? 0 );
			if ( $total < 0 ) {
				return -$total;
			}
		}

		return 0.0;
	}


	/**
	 * Schema del bloque de datos.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function cart_schema(): array {
		$entero = array( 'type' => 'integer', 'readonly' => true );
		return array(
			'logged_in'     => array( 'type' => 'boolean', 'readonly' => true ),
			'balance'       => $entero,
			'intent'        => $entero,
			'effective'     => $entero,
			'unavailable'   => array( 'type' => 'string', 'readonly' => true ),
			'min'           => $entero,
			'max'           => $entero,
			'earn_points'   => $entero,
			'earn_amount'   => $entero,
			'welcome_bonus' => $entero,
			'earn_now'      => $entero,
			'redeem_value'  => $entero,
			'min_cart'      => $entero,
			'missing'       => $entero,
			'first_min'     => $entero,
			'account_url'   => array( 'type' => 'string', 'readonly' => true ),
			'teaser'        => array( 'type' => 'boolean', 'readonly' => true ),
			'referrer_name' => array( 'type' => 'string', 'readonly' => true ),
		);
	}

	/**
	 * Aviso discreto para checkout clásico si el cliente llega con cookie de referido.
	 *
	 * @return void
	 */
	public function render_classic_referral_notice(): void {
		$code = isset( $_COOKIE[ \Akibara\Rewards\Points\Referrals::COOKIE ] )
			? sanitize_key( (string) wp_unslash( $_COOKIE[ \Akibara\Rewards\Points\Referrals::COOKIE ] ) )
			: '';
		if ( '' === $code ) {
			return;
		}

		$user_id = get_current_user_id();
		$ref_id  = \Akibara\Rewards\Points\Referrals::owner_of( $code );
		if ( $ref_id <= 0 || $ref_id === $user_id ) {
			return;
		}

		$padrino = get_user_by( 'id', $ref_id );
		if ( ! $padrino instanceof \WP_User ) {
			return;
		}

		$fname = trim( (string) $padrino->first_name );
		$name  = '' !== $fname ? $fname : (string) $padrino->display_name;

		printf(
			'<div class="akb-rw-referral-checkout-notice" style="border:1px solid #333333;border-left:4px solid #E60012;background:#181818;color:#f0f0f0;padding:12px 16px;margin:12px 0;font-size:14px;line-height:1.4;">'
			. '🤝 Estás comprando con la recomendación de <strong>%s</strong>. ¡Ambos recibirán Puntos Akibara al completar tu pedido!'
			. '</div>',
			esc_html( $name )
		);
	}

	/**
	 * extensionCartUpdate({points}): misma validación explícita que Mi cuenta.
	 *
	 * @param array<string,mixed> $data Datos del cliente.
	 * @return void
	 */
	public function handle_update( array $data ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return; // El canje exige cuenta; un invitado no fija intención.
		}

		$points = filter_var( $data['points'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $points ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'akb_rewards_invalid_points', 'Ingresa un número entero de puntos.', 400 );
		}
		if ( 0 === $points ) {
			( new Redemption() )->clear_intent();
			$cart = self::cart();
			if ( null !== $cart ) {
				$cart->calculate_totals();
			}
			return;
		}
		$cart = self::cart();
		if ( null !== $cart ) {
			$cart->calculate_totals();
		}
		$limits = Redemption::redemption_limits( $cart, $user_id );
		$error  = Redemption::requested_error( $points, $limits );
		if ( '' !== $error ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'akb_rewards_unavailable', $error, 400 );
		}
		Redemption::set_intent( $points );
	}

	/**
	 * JS plano de la caja (sin build): mismas dependencias que expone el
	 * plugin hermano de bloques.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$rel = 'assets/js/checkout-blocks.js';

		wp_enqueue_script(
			'akb-rewards-checkout-blocks',
			Assets::url( $rel ),
			array( 'wc-blocks-checkout', 'wc-blocks-data-store', 'wc-settings', 'wp-data', 'wp-element', 'wp-plugins' ),
			Assets::version( $rel ),
			true
		);
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Plugin.php
================================================================

<?php
/**
 * Clase principal del plugin.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards;

use Akibara\Rewards\Points\AccountRewards;
use Akibara\Rewards\Points\Accrual;
use Akibara\Rewards\Points\Birthday;
use Akibara\Rewards\Points\Emails;
use Akibara\Rewards\Points\Expiry;
use Akibara\Rewards\Points\ProgramPage;
use Akibara\Rewards\Blocks\CheckoutIntegration;
use Akibara\Rewards\Points\AdminAdjust;
use Akibara\Rewards\Points\PdpNotice;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Points\Referrals;
use Akibara\Rewards\Points\WelcomeBonus;
use Akibara\Rewards\Reviews\AdminReviews;
use Akibara\Rewards\Reviews\Carousel;
use Akibara\Rewards\Reviews\Moderation;
use Akibara\Rewards\Reviews\Disclosure;
use Akibara\Rewards\Reviews\PhotoSurfaces;
use Akibara\Rewards\Reviews\ReviewNudge;
use Akibara\Rewards\Reviews\Invitations;
use Akibara\Rewards\Reviews\ReviewForm;
use Akibara\Rewards\Reviews\ReviewPoints;
use Akibara\Rewards\Reviews\Schema as ReviewsSchema;
use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\Schema;
use Akibara\Rewards\Support\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton que registra los módulos. Cada módulo expone `register()` y cuelga
 * sus propios hooks (patrón de akibara-business).
 */
final class Plugin {

	/**
	 * Instancia única.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Devuelve (creando si hace falta) la instancia única.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registra módulos y rutinas de upgrade.
	 *
	 * @return void
	 */
	public function boot(): void {
		Schema::register();
		( new SettingsPage() )->register();

		( new Accrual() )->register();
		( new \Akibara\Rewards\Support\QueueHooks() )->register();
		( new WelcomeBonus() )->register();
		( new Emails() )->register();
		( new Redemption() )->register();
		( new AccountRewards() )->register();
		( new PdpNotice() )->register();
		( new Birthday() )->register();
		( new Referrals() )->register();
		( new Expiry() )->register();
		( new ProgramPage() )->register();
		( new CheckoutIntegration() )->register();
		( new AdminAdjust() )->register();
		( new \Akibara\Rewards\Points\CustomersAdmin() )->register();
		( new \Akibara\Rewards\Points\CustomerPointsPage() )->register();
		( new \Akibara\Rewards\Reviews\InvitationReschedule() )->register();
		( new \Akibara\Rewards\Support\Privacy() )->register();
		( new \Akibara\Rewards\Support\Alerts() )->register();

		// La administración permanece disponible con CusRev. Las superficies
		// públicas esperan al motor propio para evitar premios y schema duplicados.
		( new AdminReviews() )->register();
		// El aviso de incentivo también SIEMPRE: sólo lee la meta de lo ya
		// pagado, y una reseña premiada no puede perderlo si el motor se duerme.
		( new Disclosure() )->register();

		// Expone la conversión configurada de puntos sin que otros plugins
		// necesiten cargar clases de Rewards.
		add_filter(
			'akb_rewards_points_label',
			static fn( $label, $points = 0 ): string => Options::points_label( (int) $points ),
			10,
			2
		);

		// Los enlaces de baja ya enviados siguen disponibles con el motor apagado.
		( new \Akibara\Rewards\Reviews\InviteOptOut() )->register();

		// La limpieza de tokens conserva agenda propia con el motor apagado.
		( new \Akibara\Rewards\Reviews\TokenCleanup() )->register();

		// Una sola autoridad para «¿está vivo el motor de reseñas?»: el mismo
		// accesor que consultan las superficies de copy, para que el filtro
		// documentado gobierne también qué módulos cuelgan.
		if ( Options::reviews_engine_on() ) {
			( new Moderation() )->register();
			( new ReviewPoints() )->register();
			( new Carousel() )->register();
			// Visualización de la foto adjunta en la ficha del tomo y en la pantalla de moderación.
			( new PhotoSurfaces() )->register();
			( new ReviewsSchema() )->register();
			( new ReviewNudge() )->register();
			( new Invitations() )->register();
			( new ReviewForm() )->register();
		} else {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-info"><p>';
					echo '<strong>Akibara Rewards</strong>: los módulos de reseñas (puntos, carrusel, schema, moderación) ';
					echo 'duermen mientras Customer Reviews siga activo — su cupón es el incentivo vigente. ';
					echo 'Para la moderación obligatoria mientras tanto, activar <code>ivole_enable_moderation</code>.';
					echo '</p></div>';
				}
			);
		}

		// El comando WP-CLI de migración se registra en el fichero principal,
		// FUERA del gate de convivencia: debe existir mientras SPAR siga
		// activo, que es exactamente cuando este boot no corre.
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/AccountRewards.php
================================================================

<?php
/**
 * AccountRewards — el endpoint `rewards` de Mi cuenta: saldo, historial y
 * canje (Fase 1b).
 *
 * Mismo slug que registraba SPAR a propósito: el dashboard de business y el
 * menú móvil (tercera posición, medida en 375px) ya lo enlazan — al cutover
 * nada queda en 404 (spec 37). La tarjeta del dashboard consulta directamente
 * `Ledger::balance()`.
 *
 * El canje se FIJA aquí (intención en sesión) y el fee aparece en el
 * carrito/checkout: el cliente ve el descuento antes de pagar. UI liviana:
 * HTML del servidor, ayuda progresiva de canje, targets 44px, es-CL.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Assets;
use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\Schema;
use Akibara\Rewards\Points\Expiry;
use Akibara\Rewards\Points\ProgramPage;
use Akibara\Rewards\Points\WelcomeBonus;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class AccountRewards {

	public const ENDPOINT      = 'rewards';
	public const REDEEM_ACTION = 'akb_rewards_redeem';
	/** Pública: la migración de autoload de Support\Schema la nombra. */
	public const FLUSH_OPTION   = 'akb_rewards_rewrite_version';

	/** Una reparación de reglas perdidas por hora, como mucho. */
	private const HEAL_TRANSIENT = 'akb_rewards_rewrite_heal';
	private const FLUSH_VERSION = '2';

	/**
	 * Cuelga endpoint, menú, render y el receptor del canje.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		// Persiste las reglas después de registrar los alias de catálogo del tema.
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 999 );
		// Añade el elemento antes del reordenador de MyAccountUx (prioridad 20).
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ), 5 );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'register_query_var' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'endpoint_title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_redeem' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

	}

	/** Ayuda progresiva sólo en la cuenta de puntos autenticada. */
	public function enqueue_assets(): void {
		if ( get_current_user_id() <= 0 || ! is_wc_endpoint_url( self::ENDPOINT ) ) {
			return;
		}
		wp_enqueue_style( 'akb-rw-account', Assets::url( 'assets/css/account-rewards.css' ), array(), Assets::version( 'assets/css/account-rewards.css' ) );
		wp_enqueue_script( 'akb-rw-account', Assets::url( 'assets/js/account-rewards.js' ), array(), Assets::version( 'assets/js/account-rewards.js' ), true );
	}

	/**
	 * Registra el endpoint y hace UN flush de rewrites al estrenarlo.
	 *
	 * @return void
	 */
	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Firma del RULESET, no un número suelto: las reglas de /resena/{token}
	 * sólo se registran cuando el motor propio de reseñas está despierto
	 * (duerme mientras CusRev viva). Con una versión fija, el candado se
	 * sellaba SIN esas reglas y, al despertar en el cutover, no había flush
	 * que las persistiera: todos los enlaces /resena/{token} ya enviados por
	 * correo caían en 404. Al cambiar el motor cambia la firma y entra UN
	 * flush más.
	 *
	 * @return string
	 */
	private static function rules_signature(): string {
		return self::FLUSH_VERSION . ( Options::reviews_engine_on() ? '-rf' : '' );
	}

	/**
	 * Valor con el que nace el campo «Puntos a canjear». Sólo se llama en la
	 * rama donde ya se probó `$disponible >= $minimo_canje`, acotando el
	 * valor inicial estrictamente al rango `[$minimo_canje, $disponible]`.
	 *
	 * @param int $disponible   Máximo del campo (`max`).
	 * @param int $minimo_canje Mínimo del campo (`min`).
	 * @param int $intent       Intención de canje guardada en la sesión.
	 * @return int
	 */
	private static function valor_inicial_de_canje( int $disponible, int $minimo_canje, int $intent ): int {
		return (int) min( $disponible, max( $minimo_canje, $intent ) );
	}

	/**
	 * Flush de rewrites UNA vez por firma de reglas, cuando ya todo el
	 * mundo (tema incluido) registró las suyas.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrites(): void {
		$signature = self::rules_signature();

		if ( $signature === (string) get_option( self::FLUSH_OPTION ) && ! self::rules_lost() ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::FLUSH_OPTION, $signature, true );
	}

	/**
	 * Valida si las reglas de reescritura activas perdieron los endpoints del programa.
	 *
	 * Permite regenerar dinámicamente las rutas si otra operación del ciclo de vida
	 * reescribió `rewrite_rules` sin incluir las de este módulo.
	 *
	 * @return bool
	 */
	private static function rules_lost(): bool {
		// No ejecuta en peticiones asíncronas ni REST para evitar sobrecarga en endpoints livianos.
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		$reglas = get_option( 'rewrite_rules' );
		if ( ! is_array( $reglas ) || array() === $reglas ) {
			return false; // Sin reglas guardadas WordPress las regenera él mismo, con las nuestras.
		}

		// Comprueba reglas de reescritura de raíz y subpáginas del endpoint.
		$faltan = ! isset( $reglas[ '(.?.+?)/' . self::ENDPOINT . '(/(.*))?/?$' ] )
			|| ! isset( $reglas[ self::ENDPOINT . '(/(.*))?/?$' ] )
			|| ( Options::reviews_engine_on() && ! isset( $reglas[ \Akibara\Rewards\Reviews\ReviewForm::RULE ] ) );

		if ( ! $faltan || false !== get_transient( self::HEAL_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::HEAL_TRANSIENT, 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Registra el endpoint en el query-vars de WooCommerce: así
	 * is_wc_endpoint_url('rewards') y el título del endpoint funcionan.
	 *
	 * @param array<string,string> $vars Query vars de WC.
	 * @return array<string,string>
	 */
	public function register_query_var( $vars ): array {
		$vars                    = is_array( $vars ) ? $vars : array();
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Título de la pantalla del endpoint.
	 *
	 * @return string
	 */
	public function endpoint_title(): string {
		return 'Puntos Akibara';
	}

	/**
	 * Ítem del menú de Mi cuenta, antes de «Salir».
	 *
	 * @param array<string,string> $items Ítems actuales.
	 * @return array<string,string>
	 */
	public function menu_item( $items ): array {
		$items = is_array( $items ) ? $items : array();

		$logout = null;
		if ( isset( $items['customer-logout'] ) ) {
			$logout = $items['customer-logout'];
			unset( $items['customer-logout'] );
		}

		$items[ self::ENDPOINT ] = 'Puntos Akibara';

		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	/**
	 * Render del endpoint: saldo, canje y últimos movimientos.
	 *
	 * @return void
	 */
	public function render(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$balance = Ledger::balance( $user_id );

		// Presenta el canje efectivo, limitado por saldo y productos del carrito.
		$cart = function_exists( 'WC' ) && ( WC()->cart ?? null ) instanceof \WC_Cart ? WC()->cart : null;
		if ( null !== $cart ) {
			$cart->calculate_totals();
		}
		$intent = Redemption::intent_points();
		$limits = Redemption::redemption_limits( $cart, $user_id );
		$vivo   = null !== $cart ? Redemption::effective_points( $cart ) : 0;

		echo '<div class="akb-rw-account" style="--akb-acc:var(--aki-red,#E60012)">';

		printf(
			'<div class="akb-rw-balance-card">
				<p class="akb-rw-balance-card__label">Tu saldo</p>
				<p class="akb-rw-balance-card__value">%s <span class="akb-rw-balance-card__unit">Puntos Akibara</span></p>
				<p class="akb-rw-balance-card__equiv">Equivalencia potencial: %s (1 punto = %s). El descuento aplicable depende de tu carrito.</p>
			</div>',
			esc_html( number_format_i18n( $balance ) ),
			wp_kses_post( wc_price( $balance * Options::redeem_value() ) ),
			esc_html( Options::redeem_label() )
		);

		// Notificación de puntos apartados en pedidos pendientes de pago.
		$disponible = $limits['available'];
		$usables    = $disponible - max( 0, $balance );
		$apartados  = max( $usables, Redemption::held_in_unpaid_orders( $user_id ) );
		if ( $apartados > 0 ) {
			$minutos = Redemption::unpaid_cancel_minutes();
			printf(
				'<p style="margin:0 0 8px;opacity:.85">Además tienes %s puntos apartados en un pedido sin pagar. %s</p>',
				esc_html( number_format_i18n( $apartados ) ),
				esc_html(
					( $usables >= $apartados
						? 'Su pago no se completó: puedes usarlos al volver a intentar esa compra, y si no la completas'
						: 'Si pagas ese pedido se usan ahí; si no' )
					. ( $minutos > 0
						? sprintf( ', vuelven solos a tu saldo en unos %d minutos.', $minutos )
						: ', vuelven a tu saldo cuando el pedido se anule.' )
				)
			);
		}

		if ( WelcomeBonus::is_pending_for( $user_id ) ) {
			printf(
				'<p style="margin:0 0 8px;padding:10px 12px;border-left:3px solid #E60012;background:rgba(230,0,18,.08)">🎁 Tienes <strong>%s Puntos Akibara esperándote</strong>: se activan con tu primera compra%s.</p>',
				esc_html( number_format_i18n( Promise::welcome_bonus() ) ),
				Options::first_purchase_min() > 0
					? esc_html( sprintf( ' desde $%s en productos', number_format_i18n( Options::first_purchase_min() ) ) )
					: ''
			);
		}

		$next_expiry = Expiry::next_expiry( $user_id );
		if ( null !== $next_expiry ) {
			$expiry_date = new \DateTimeImmutable( $next_expiry['date'], wp_timezone() );
			printf(
				'<p class="akb-rw-expiry">%s puntos pueden vencer desde el <time datetime="%s">%s</time> si no los usas.%s</p>',
				esc_html( number_format_i18n( $next_expiry['points'] ) ),
				esc_attr( $next_expiry['date'] ),
				esc_html( wp_date( 'j \d\e F \d\e Y', $expiry_date->getTimestamp(), wp_timezone() ) ),
				$next_expiry['pending'] ? ' Ya son elegibles; el vencimiento está pendiente de procesarse.' : ''
			);
		}

		if ( $intent > 0 ) {
			$tope = $vivo < $intent
				? sprintf( ' (tu carrito actual aplica %s puntos por %s de descuento)', esc_html( number_format_i18n( $vivo ) ), wp_kses_post( wc_price( $vivo * $limits['value'] ) ) )
				: '';

			printf(
				'<p class="akb-rw-active-intent">Tienes un canje guardado de <strong>%s puntos</strong>%s. <a href="%s" class="akb-rw-active-intent__link">Ir al carrito →</a></p>
				<form method="post" style="margin:0 0 16px">%s<input type="hidden" name="akb_rw_action" value="%s"><input type="hidden" name="akb_rw_points" value="0"><button type="submit" class="button" style="min-height:44px">Quitar canje</button></form>',
				esc_html( number_format_i18n( $intent ) ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tope ya sale escapado de arriba: su única parte dinámica pasa por esc_html() en el sprintf que lo construye, y el resto es texto literal de este fichero. Volver a escaparlo aquí sería doble escape.
				$tope,
				esc_url( wc_get_cart_url() ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() con $echo = false DEVUELVE el marcado que core ya genera seguro; el sniff sólo la reconoce cuando imprime ella misma. Escaparla dejaría el nonce visible como texto y tumbaría el formulario.
				wp_nonce_field( self::REDEEM_ACTION, '_akbnonce', true, false ),
				esc_attr( self::REDEEM_ACTION )
			);
		}

		$minimo_canje = $limits['min_points'];
		$maximum      = $limits['max_points'];
		$eligible     = $maximum > 0;
		if ( $disponible > 0 ) {
			$initial = $eligible ? self::valor_inicial_de_canje( $maximum, $minimo_canje, $intent ) : 0;
			$message = Redemption::unavailable_message( $limits );
			$preview = $eligible
				? sprintf( '%s puntos: %s de descuento aplicable en tu carrito actual.', number_format_i18n( $initial ), wp_strip_all_tags( wc_price( $initial * $limits['value'] ) ) )
				: sprintf( 'Descuento aplicable en tu carrito actual: %s. %s', wp_strip_all_tags( wc_price( 0 ) ), $message );
			$config  = array(
				'value'    => $limits['value'],
				'eligible' => $eligible,
				'decimals' => wc_get_price_decimals(),
				'decimal'  => wc_get_price_decimal_separator(),
				'thousand' => wc_get_price_thousand_separator(),
				'format'   => get_woocommerce_price_format(),
				'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			);
			$rules   = sprintf( 'Mínimo: %s puntos. Máximo aplicable en este carrito: %s puntos.', number_format_i18n( $minimo_canje ), number_format_i18n( $maximum ) );
			if ( Options::redeem_min_cart() > 0 ) {
				$rules .= ' Compra mínima en productos: ' . wp_strip_all_tags( wc_price( Options::redeem_min_cart() ) ) . '.';
			}
			$ceiling = Redemption::techo_humano();
			if ( '' !== $ceiling ) {
				$rules .= ' Tope por compra: ' . $ceiling . '.';
			}
			$rules .= ' Se calcula sobre productos tras cupones, con IVA y sin despacho. Se vuelve a comprobar al confirmar.';

			printf(
				'<form method="post" action="%s" class="akb-rw-redeem" data-akb-redeem="%s">
					%s
					<input type="hidden" name="akb_rw_action" value="%s">
					<label for="akb-rw-points">Puntos a canjear</label>
					<div class="akb-rw-redeem-controls">
						<input id="akb-rw-points" type="number" name="akb_rw_points" min="%d" max="%d" step="1" value="%d" required aria-describedby="akb-rw-preview akb-rw-rules"%s>
						<button type="button" class="button akb-rw-max" hidden%s>Usar el máximo disponible</button>
					</div>
					<p id="akb-rw-preview" role="status" aria-live="polite" aria-atomic="true">%s</p>
					<p id="akb-rw-rules">%s</p>
					<button type="submit" class="button akb-rw-redeem-submit"%s>Confirmar canje en este carrito</button>
				</form>',
				esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) ),
				esc_attr( wp_json_encode( $config ) ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- marcado del nonce generado por core.
				wp_nonce_field( self::REDEEM_ACTION, '_akbnonce', true, false ),
				esc_attr( self::REDEEM_ACTION ),
				(int) ( $eligible ? $minimo_canje : 0 ),
				(int) $maximum,
				(int) $initial,
				$eligible ? '' : ' disabled',
				$eligible ? '' : ' disabled',
				esc_html( $preview ),
				esc_html( $rules ),
				$eligible ? '' : ' disabled'
			);
			if ( ! $eligible ) {
				printf( '<p><a class="button" href="%s">Ir al carrito →</a></p>', esc_url( wc_get_cart_url() ) );
			}
		} elseif ( $apartados <= 0 ) {
			// Comparte con la ficha y la caja la promesa vigente del programa.
			$tramo  = Promise::rate();
			$compra = $tramo['points'] > 0 && $tramo['amount'] > 0
				? sprintf( ' se suman solos con cada compra (%s por cada $%s)', number_format_i18n( $tramo['points'] ), number_format_i18n( $tramo['amount'] ) )
				: '';
			$resena = Options::reviews_lane_on() ? ( '' !== $compra ? ' y puedes ganarlos con tus reseñas con foto aprobadas que cumplan los requisitos' : ' puedes ganarlos con tus reseñas con foto aprobadas que cumplan los requisitos' ) : '';
			$frase  = $compra . $resena;
			if ( '' === $frase ) {
				$frase = ' llegan con el programa Puntos Akibara';
			}
			printf( '<p>Todavía no tienes puntos:%s.</p>', esc_html( $frase ) );
		}

		$this->render_history( $user_id );
		if ( Options::reviews_lane_on() ) {
			printf( '<p>%s</p>', esc_html( 'Hay dos recorridos: la reseña voluntaria en la ficha del producto admite foto opcional y no genera puntos. La invitación por correo permite reseñar y solicitar puntos con foto obligatoria; puedes recuperar su enlace vigente desde Mis pedidos. ' . \Akibara\Rewards\Reviews\RewardMessage::text( false ) ) );
		}

		// Sección informativa de acumulación, canje y catálogo de beneficios.
		$coleccion_url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'coleccion' ) : '';

		// Anuncia únicamente los carriles activos y con beneficio positivo.
		$carriles = array();
		$tramo_vivo = Promise::rate();
		if ( $tramo_vivo['points'] > 0 && $tramo_vivo['amount'] > 0 ) {
			$carriles[] = sprintf( 'con cada compra (%s por cada $%s)', number_format_i18n( $tramo_vivo['points'] ), number_format_i18n( $tramo_vivo['amount'] ) );
		}
		// El bono y el premio al padrino cuelgan de la MISMA compuerta de
		// vigencia que la compra: fuera de ella tampoco se prometen.
		$bono_vivo = Promise::welcome_bonus();
		if ( $bono_vivo > 0 ) {
			$carriles[] = Options::first_purchase_min() > 0
				? sprintf( 'con tu primera compra desde $%s (bono de %s, uno por persona)', number_format_i18n( Options::first_purchase_min() ), number_format_i18n( $bono_vivo ) )
				: sprintf( 'con tu primera compra (bono de %s, uno por persona)', number_format_i18n( $bono_vivo ) );
		}
		if ( Options::reviews_lane_on() ) {
			$every      = max( 1, Options::review_every_n() );
			$carriles[] = $every > 1
				? sprintf( 'con tus reseñas con foto aprobadas (%s cada %d reseñas), si cumplen los requisitos', number_format_i18n( Options::review_points() ), $every )
				: sprintf( 'con tus reseñas con foto aprobadas (%s), si cumplen los requisitos', number_format_i18n( Options::review_points() ) );
		}
		if ( Promise::referral_points() > 0 ) {
			$carriles[] = sprintf( 'invitando amigos que reciban su primera compra (%s)', number_format_i18n( Promise::referral_points() ) );
		}
		if ( Options::birthday_points() > 0 ) {
			$carriles[] = sprintf( 'en tu cumpleaños (%s)', number_format_i18n( Options::birthday_points() ) );
		}

		$ultimo = array_pop( $carriles );
		$ganas  = null === $ultimo ? '' : ( array() === $carriles ? $ultimo : implode( ', ', $carriles ) . ' y ' . $ultimo );

		printf(
			'<details style="margin:20px 0"><summary style="cursor:pointer;font-weight:700;min-height:44px;display:flex;align-items:center">¿Cómo funcionan mis puntos? 📖</summary>
			<ul style="margin:10px 0 0;line-height:1.7">
				%s<li><strong>Gastas</strong> canjeándolos aquí arriba: cada punto es %s de descuento%s — ideal para el siguiente tomo de tu colección.</li>
				%s
			</ul>
			%s</details>',
			'' !== $ganas ? sprintf( '<li><strong>Ganas</strong> %s.</li>', esc_html( $ganas ) ) : '',
			esc_html( Options::redeem_label() ),
			Options::redeem_min_cart() > 0
				? esc_html( sprintf( ' en compras desde $%s en productos', number_format_i18n( Options::redeem_min_cart() ) ) )
				: ' en tu próxima compra',
			Expiry::months() > 0 ? sprintf( '<li><strong>Duran</strong> %d meses: los más antiguos se usan primero.</li>', (int) Expiry::months() ) : '',
			'' !== $coleccion_url ? sprintf( '<p style="margin:10px 0 0"><a href="%s" style="font-weight:600">Ver mi colección de mangas y cómics →</a> ¿Te falta un tomo? Tus puntos lo acercan.</p>', esc_url( $coleccion_url ) ) : ''
		);

		/**
		 * Bloques extra del endpoint (referidos, futuros niveles).
		 *
		 * @param int $user_id Usuario dueño de la pantalla.
		 */
		do_action( 'akb_rewards_account_extra', $user_id );

		$programa_url = ProgramPage::url();
		if ( '' !== $programa_url ) {
			printf(
				'<p style="margin-top:16px"><a href="%s" style="color:var(--aki-red-text, #ff4d5e);font-weight:600;text-decoration:underline">Cómo funciona el programa →</a></p>',
				esc_url( $programa_url )
			);
		}

		echo '</div>';
	}

	/**
	 * Historial privado, diez movimientos por página y orden estable.
	 *
	 * @param int $user_id Usuario.
	 * @return void
	 */
	private function render_history( int $user_id ): void {
		global $wpdb;

		// El COUNT privado permite acotar la página ANTES del OFFSET, incluso
		// con una URL enorme. No consulta movimientos de otras cuentas.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::ledger_table() . ' WHERE user_id = %d', $user_id ) );
		if ( $total <= 0 ) {
			echo '<p>Todavía no tienes movimientos de puntos.</p>';
			return;
		}
		$pages = (int) ceil( $total / 10 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET de lectura sin mutación.
		$raw   = $_GET['akb_rw_page'] ?? '1';
		$page  = is_scalar( $raw ) && preg_match( '/^[1-9][0-9]*$/D', (string) $raw ) ? min( $pages, (int) $raw ) : 1;
		$page  = max( 1, $page );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla interno.
				'SELECT delta, note, created_at FROM ' . Schema::ledger_table() . ' WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT 10 OFFSET %d',
				$user_id,
				( $page - 1 ) * 10
			)
		);

		echo '<h3 id="akb-rw-history" style="margin:0 0 8px">Historial de puntos</h3><div class="akb-rw-history-table"><table class="shop_table" style="font-size:14px"><thead><tr><th scope="col">Fecha</th><th scope="col">Motivo</th><th scope="col" style="text-align:right">Puntos</th></tr></thead><tbody>';
		foreach ( (array) $rows as $row ) {
			$delta = (int) $row->delta;
			printf(
				'<tr><td>%s</td><td>%s</td><td style="text-align:right;font-weight:700;color:%s">%s%s</td></tr>',
				esc_html( date_i18n( 'j M Y', strtotime( (string) $row->created_at ) ) ),
				esc_html( (string) $row->note ),
				$delta >= 0 ? '#8ce99a' : '#ffa8a8', // Tonos legibles sobre el dark del tema (AA).
				$delta >= 0 ? '+' : '−',
				esc_html( number_format_i18n( abs( $delta ) ) )
			);
		}
		echo '</tbody></table></div>';
		if ( $pages > 1 ) {
			$base = wc_get_account_endpoint_url( self::ENDPOINT );
			echo '<nav class="akb-rw-pages" aria-label="Páginas del historial de puntos">';
			if ( $page > 1 ) {
				printf( '<a class="button" rel="prev" href="%s">← Anterior</a>', esc_url( add_query_arg( 'akb_rw_page', $page - 1, $base ) . '#akb-rw-history' ) );
			}
			printf( '<span aria-current="page">Página %s de %s</span>', esc_html( number_format_i18n( $page ) ), esc_html( number_format_i18n( $pages ) ) );
			if ( $page < $pages ) {
				printf( '<a class="button" rel="next" href="%s">Siguiente →</a>', esc_url( add_query_arg( 'akb_rw_page', $page + 1, $base ) . '#akb-rw-history' ) );
			}
			echo '</nav>';
		}
	}

	/**
	 * Recibe el formulario de canje: valida y fija los puntos aplicables al carrito.
	 *
	 * @return void
	 */
	public function handle_redeem(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce se verifica dos líneas abajo.
		$accion = isset( $_POST['akb_rw_action'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_rw_action'] ) ) : '';
		if ( self::REDEEM_ACTION !== $accion ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		check_admin_referer( self::REDEEM_ACTION, '_akbnonce' );

		$raw = isset( $_POST['akb_rw_points'] ) ? wp_unslash( $_POST['akb_rw_points'] ) : null;
		$this->process_redeem_input( $user_id, $raw );

		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	/**
	 * Valida la entrada del formulario antes de convertirla en una solicitud.
	 *
	 * @param int   $user_id Usuario.
	 * @param mixed $raw     Entrada sin convertir recibida del formulario.
	 * @return int Puntos efectivamente fijados.
	 */
	public function process_redeem_input( int $user_id, $raw ): int {
		$requested = filter_var( $raw, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $requested ) {
			self::invalid_points_notice();
			return 0;
		}
		return $this->process_redeem( $user_id, $requested );
	}

	/**
	 * Rechazar una entrada inválida conserva el canje anterior.
	 *
	 * @return void
	 */
	private static function invalid_points_notice(): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( 'Ingresa un número entero de puntos mayor o igual a cero.', 'error' );
		}
	}

	/**
	 * Núcleo del canje, separado del wrapper (nonce/redirect/exit) para
	 * poder probarlo: vuelve a medir el carrito y el saldo, fija la intención y avisa.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $requested Puntos pedidos.
	 * @return int Puntos efectivamente fijados.
	 */
	public function process_redeem( int $user_id, int $requested ): int {
		if ( $requested < 0 ) {
			self::invalid_points_notice();
			return 0;
		}
		if ( 0 === $requested ) {
			Redemption::set_intent( 0 );
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( 'Canje retirado: tus puntos quedan intactos en tu saldo.', 'notice' );
			}
			return 0;
		}

		$cart = function_exists( 'WC' ) && ( WC()->cart ?? null ) instanceof \WC_Cart ? WC()->cart : null;
		// Totales de sesión pueden haber quedado anteriores a un precio o cupón.
		// Recalcular fuera del helper evita recursión desde calculate_fees.
		if ( null !== $cart ) {
			$cart->calculate_totals();
		}
		$limits = Redemption::redemption_limits( $cart, $user_id );
		$error  = Redemption::requested_error( $requested, $limits );
		if ( '' !== $error ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $error, 'error' );
			}
			return 0; // Carrito o saldo cambiados: conservar la intención previa.
		}

		Redemption::set_intent( $requested );
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				sprintf( 'Canje confirmado: %s puntos (%s de descuento) en tu carrito actual.', number_format_i18n( $requested ), wp_strip_all_tags( wc_price( $requested * $limits['value'] ) ) ),
				'success'
			);
		}
		return $requested;
	}

}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/Accrual.php
================================================================

<?php
/**
 * Accrual — acreditación y reversas por pedido.
 *
 * Reglas (spec 1-5, config real de prod):
 *   - Acredita al pasar al estado configurado en el panel («Procesando»,
 *     o sea con el pago confirmado) o a cualquier estado pagado posterior.
 *   - Solo pedidos creados a partir de la fecha de inicio de vigencia del programa: sin esta
 *     compuerta, mover un pedido viejo regalaría 2% + bono retroactivo.
 *   - Idempotencia en TRES capas: meta de pedido re-leída FRESCA bajo candado
 *     de pedido (`read_meta_data(true)` — la caché de pedidos es persistente
 *     en prod y wc_get_order puede servir una foto vieja), conciliación
 *     contra el LEDGER (si hay una acreditación viva sin sellar, se sella en
 *     vez de re-acreditar — ventana de caída), y `event_key` UNIQUE por
 *     ocurrencia en la tabla.
 *   - Cancelación/reembolso total/fallo deduce TODO lo acreditado (bono
 *     incluido — viaja dentro del earned a propósito). Un pedido REVIVIDO
 *     (cancelado → de nuevo listo) vuelve a acreditar: las claves de evento
 *     van por ocurrencia (award_100_2), no por pedido.
 *   - Reembolso parcial recalcula sobre lo conservado y retira la diferencia,
 *     preservando el bono. La clasificación parcial-vs-total mira la PLATA
 *     conservada (total − reembolsado): ni la base puntuable (el canje la
 *     tumba a 0 con un reembolso chico) ni los ítems conservados (un
 *     reembolso íntegro por importe con canje nunca los deja en 0) sirven.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Log;
use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Accrual {

	/** El programa rige hoy: se acredita y se puede prometer. */
	public const PROGRAMA_VIGENTE = 'vigente';

	/** La fecha de inicio es legible y está en el futuro: todavía no acredita nadie. */
	public const PROGRAMA_AGENDADO = 'agendado';

	/** La fecha de inicio guardada no se puede leer: no acredita nadie hasta corregirla. */
	public const PROGRAMA_ILEGIBLE = 'ilegible';

	public const META_EARNED            = '_akb_rewards_points_earned';
	public const META_WELCOME_BONUS     = '_akb_rewards_welcome_bonus';
	public const META_PRE_PROGRAM_NOTED = '_akb_rewards_pre_program_noted';
	public const META_RATE              = '_akb_rewards_rate';

	/** Claves y configuración de carencia (escrow de 7 días). */
	public const HOOK_RELEASE_ESCROW = 'akb_rewards_release_accrual_escrow';
	public const META_PENDING        = '_akb_rewards_points_pending';
	public const META_PENDING_STATUS = '_akb_rewards_pending_status';
	public const META_ESCROW_DUE     = '_akb_rewards_escrow_due';
	public const DEFAULT_ESCROW_DAYS = 7;

	private const LOCK_TIMEOUT = 3;

	/**
	 * Cuelga los hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		add_action( 'woocommerce_payment_complete', array( $this, 'maybe_award' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_award' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_award' ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'deduct_all' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'deduct_all' ), 20, 1 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'deduct_all' ), 20, 1 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 20, 2 );
		add_action( 'woocommerce_refund_deleted', array( $this, 'on_refund_deleted' ), 20, 2 );
		add_action( self::HOOK_RELEASE_ESCROW, array( $this, 'release_escrow' ), 10, 1 );

		// El barrido recupera pedidos elegibles pendientes de acreditación.
		add_action( self::HOOK_SWEEP, array( $this, 'sweep_unawarded' ) );
		add_action( 'init', array( $this, 'ensure_sweep_scheduled' ), 20 );
	}

	/** Acción diaria del barrido. */
	public const HOOK_SWEEP = 'akb_rewards_accrual_sweep_daily';

	/**
	 * Sello del barrido sobre un pedido que NO acredita y no volverá a entrar:
	 * su valor es el motivo (`invitado`, `vinculado_despues`, `sin_acreditar`).
	 * Evita que pedidos excluidos de forma permanente consuman capacidad de
	 * cada lote y retrasen la acreditación de pedidos nuevos.
	 */
	public const META_SWEEP_SKIPPED = '_akb_rewards_sweep_skipped';

	/** Pedidos por barrido; el resto espera al día siguiente. */
	private const SWEEP_BATCH = 100;

	/**
	 * Misma puerta de backend que `Expiry`, `Birthday` y `TokenCleanup`:
	 * `as_next_scheduled_action()` son dos consultas y el front no las paga.
	 *
	 * @return void
	 */
	public function ensure_sweep_scheduled(): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::HOOK_SWEEP ) ) {
			as_schedule_recurring_action( time() + 3 * HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_SWEEP );
		}
	}

	/**
	 * Acredita los pedidos pagados que se quedaron sin puntos.
	 *
	 * La acreditación se ejecuta en `woocommerce_order_status_changed`. Para garantizar
	 * consistencia ante fallos transitorios o transiciones manuales de pedidos, este proceso
	 * concilia pedidos pagados elegibles que no tengan `META_EARNED` ni marca previa.
	 * Los pedidos de invitado o cuentas creadas con posterioridad al pedido no acumulan puntos.
	 *
	 * @return void
	 */
	public function sweep_unawarded(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$ids = wc_get_orders(
			array(
				'status'       => OrderStates::PAID,
				'date_created' => '>=' . substr( Options::program_start(), 0, 10 ),
				'limit'        => self::SWEEP_BATCH,
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'return'       => 'ids',
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- barrido diario acotado a 100 pedidos, fuera de cualquier petición del cliente.
					'relation' => 'AND',
					array(
						'key'     => self::META_EARNED,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_PRE_PROGRAM_NOTED,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_SWEEP_SKIPPED,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( (array) $ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			if ( (int) $order->get_customer_id() <= 0 ) {
				$this->seal_sweep( $order, 'invitado' );
				continue;
			}
			if ( ! OrderStates::is_at_or_after( (string) $order->get_status(), Options::award_status() ) ) {
				continue; // Todavía no acredita: mañana se vuelve a mirar.
			}
			if ( $this->account_created_after( $order ) ) {
				$this->seal_sweep( $order, 'vinculado_despues' );
				continue;
			}

			$this->maybe_award( (int) $order_id );

			$order = $this->fresh_order( (int) $order_id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			if ( (int) $order->get_meta( self::META_PENDING ) > 0 ) {
				$order->add_order_note( 'Puntos Akibara: acreditación puesta en periodo de carencia por el barrido diario.' );
				continue;
			}
			if ( '' !== (string) $order->get_meta( self::META_EARNED ) ) {
				$order->add_order_note( 'Puntos Akibara: acreditación recuperada por el barrido diario (el pedido estaba en un estado que acredita y no tenía puntos).' );
				continue;
			}
			// `maybe_award()` decidió no acreditar (sin líneas, o el ledger no
			// respondió): se sella para no entrar a diario, y se dice. El
			// siguiente cambio de estado del pedido vuelve a intentarlo por la
			// vía normal, que no mira este sello.
			$this->seal_sweep( $order, 'sin_acreditar' );
			$order->add_order_note( 'Puntos Akibara: el barrido diario no pudo acreditar este pedido; lo reintentará el próximo cambio de estado.' );
		}
	}

	/**
	 * @param \WC_Order $order  Pedido.
	 * @param string    $motivo Por qué no acredita.
	 * @return void
	 */
	private function seal_sweep( \WC_Order $order, string $motivo ): void {
		$order->update_meta_data( self::META_SWEEP_SKIPPED, $motivo );
		$order->save_meta_data();
	}

	/**
	 * ¿La cuenta del pedido se creó DESPUÉS del pedido? Es la huella de un
	 * pedido de invitado vinculado a posteriori (la cuenta creada en la caja
	 * nace en el mismo segundo que el pedido; se dejan 60 s de margen).
	 *
	 * @param \WC_Order $order Pedido con cuenta.
	 * @return bool
	 */
	private function account_created_after( \WC_Order $order ): bool {
		if ( ! function_exists( 'get_userdata' ) ) {
			return false;
		}
		$user    = get_userdata( (int) $order->get_customer_id() );
		$creado  = $order->get_date_created();
		$alta    = is_object( $user ) && isset( $user->user_registered ) ? strtotime( (string) $user->user_registered . ' UTC' ) : false;
		$pedido  = $creado instanceof \DateTimeInterface ? $creado->getTimestamp() : false;

		return false !== $alta && false !== $pedido && $alta > $pedido + MINUTE_IN_SECONDS;
	}

	/**
	 * Transición de estado: acreditar o revertir según el destino.
	 *
	 * @param int       $order_id ID del pedido.
	 * @param string    $from     Estado anterior (sin wc-).
	 * @param string    $to       Estado nuevo (sin wc-).
	 * @param \WC_Order $order    Pedido.
	 * @return void
	 */
	public function on_status_changed( $order_id, $from, $to, $order ): void {
		unset( $from );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$to = (string) $to;

		if ( 'completed' === $to && Options::escrow_days() > 0 ) {
			$this->maybe_schedule_escrow_release( $order, Options::escrow_days() );
		}

		if ( OrderStates::is_at_or_after( $to, Options::award_status() ) ) {
			$this->maybe_award( (int) $order_id );
			return;
		}

		// Estado terminal: la venta no existe y la acreditación se revierte entera.
		if ( OrderStates::is_down( $to ) ) {
			$this->deduct_all( (int) $order_id );
		}
	}

	/**
	 * Acredita el pedido si corresponde. Idempotente bajo candado de pedido.
	 *
	 * @param int|mixed $order_id ID del pedido.
	 * @return void
	 */
	public function maybe_award( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}
		$this->with_order_lock(
			$order_id,
			function () use ( $order_id ): void {
				$order = $this->fresh_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}

				$user_id = (int) $order->get_customer_id();
				if ( $user_id <= 0 ) {
					return; // Invitado: los puntos requieren cuenta.
				}

				// Solo el sello POSITIVO corta: tras una reversa la meta queda
				// en 0 y un pedido REVIVIDO debe volver a acreditar (la
				// conciliación con el ledger de abajo decide si hay algo
				// pendiente de verdad).
				// Evalúa la compra descontando el bono de bienvenida para permitir
				// acumulación posterior si se habilita la asignación por compra.
				$bonus_sellado = max( 0, (int) $order->get_meta( self::META_WELCOME_BONUS ) );
				if ( (int) $order->get_meta( self::META_EARNED ) - $bonus_sellado > 0 ) {
					// Ya acreditado. Si el correo quedó pendiente, reintentar
					// el aviso es legítimo (el pedido es «reintentable»).
					do_action( 'akb_rewards_award_already_done', $order );
					return;
				}

				// Conciliación contra el ledger por suma de deltas para determinar
				// el saldo neto pendiente del pedido y sellar el valor real.
				$outstanding = Ledger::sum_movements(
					'order',
					$order_id,
					array( 'order', 'welcome_bonus', 'order_reversal', 'order_refund' )
				);
				// Igual que arriba: un pendiente que es sólo el bono no es una
				// acreditación de compra caída a medio sellar.
				if ( $outstanding - $bonus_sellado > 0 ) {
					$order->update_meta_data( self::META_EARNED, $outstanding );
					$order->save_meta_data();
					$order->add_order_note(
						sprintf( 'Puntos Akibara: acreditación reconciliada desde el ledger (%d puntos pendientes ya movidos).', $outstanding )
					);
					do_action( 'akb_rewards_award_already_done', $order );
					return;
				}

				if ( ! OrderStates::is_at_or_after( (string) $order->get_status(), Options::award_status() ) ) {
					return;
				}

				if ( ! $this->program_allows( $order ) ) {
					return;
				}

				$base   = Calculator::award_base_for_order( $order );
				$points = Calculator::points_for( $base );

				if ( $points <= 0 ) {
					// Pedidos sin ítems de línea se preservan sin sellar para
					// permitir re-evaluación cuando se incorporen productos.
					if ( array() === $order->get_items( 'line_item' ) ) {
						return;
					}
					// Sellar el cero UNA vez (meta aún virgen): transiciones
					// posteriores no re-escriben nada.
					if ( '' === (string) $order->get_meta( self::META_EARNED ) ) {
						$order->update_meta_data( self::META_EARNED, 0 );
					}
					// La tasa se sella también en cero: si el bono de bienvenida
					// entra a este pedido, un reembolso parcial posterior tiene
					// que recalcular con la tasa de ENTONCES (0), no con la
					// vigente, o el ajuste sale mudo y el earned inflado.
					if ( '' === (string) $order->get_meta( self::META_RATE ) ) {
						$order->update_meta_data( self::META_RATE, sprintf( '%d/%d', Options::earn_points(), Options::earn_amount() ) );
					}
					$order->save_meta_data();

					/**
					 * Notificación de evento para flujos posteriores (bonos y correos).
					 *
					 * @param \WC_Order $order   Pedido.
					 * @param int       $user_id Cliente.
					 * @param int       $points  Siempre 0 en esta rama.
					 */
					do_action( 'akb_rewards_after_order_award', $order, $user_id, 0 );
					return;
				}

				// Si los puntos ya están en carencia (pending), no duplicar.
				if ( (int) $order->get_meta( self::META_PENDING ) > 0 ) {
					return;
				}

				$escrow_days = Options::escrow_days();
				if ( $escrow_days > 0 ) {
					$order->update_meta_data( self::META_PENDING, $points );
					$order->update_meta_data( self::META_PENDING_STATUS, 'pending' );
					$order->update_meta_data( self::META_RATE, sprintf( '%d/%d', Options::earn_points(), Options::earn_amount() ) );
					$order->update_meta_data( '_akb_rewards_pending_escrow_points', $points );
					$order->save_meta_data();
					$order->add_order_note(
						sprintf( 'Puntos Akibara: %d puntos retenidos en periodo de carencia (%d días tras completarse el pedido).', $points, $escrow_days )
					);

					if ( 'completed' === (string) $order->get_status() ) {
						$this->maybe_schedule_escrow_release( $order, $escrow_days );
					}
					return;
				}

				$awards = Ledger::count_movements( 'order', $order_id, 'order' );

				$result = Ledger::credit(
					$user_id,
					$points,
					'order',
					'order',
					$order_id,
					sprintf( 'Compra #%d', $order_id ),
					true,
					sprintf( 'award_%d_%d', $order_id, $awards + 1 )
				);

				if ( ! is_int( $result ) ) {
					return; // Infraestructura: la próxima transición reintenta.
				}

				// Se SUMA a lo que ya hubiera: si este pedido cobró antes sólo el
				// bono (compra en cero), el earned lo lleva dentro y pisarlo lo
				// borraría de la cuenta de la reversa.
				$order->update_meta_data( self::META_EARNED, $points + max( 0, (int) $order->get_meta( self::META_EARNED ) ) );
				// La tasa aplicada viaja sellada en el pedido para permitir que
				// ajustes posteriores de reembolso utilicen la tasa histórica exacta.
				$order->update_meta_data( self::META_RATE, sprintf( '%d/%d', Options::earn_points(), Options::earn_amount() ) );
				$order->delete_meta_data( '_akb_rewards_pending_escrow_points' );
				$order->delete_meta_data( '_akb_rewards_escrow_noted' );
				$order->save_meta_data();
				$order->add_order_note(
					sprintf( 'Puntos Akibara: %d puntos acreditados por esta compra.', $points )
				);

				/**
				 * Acreditación de compra completada. El bono de bienvenida
				 * cuelga a prioridad 10 y el correo consolidado a 100.
				 *
				 * @param \WC_Order $order   Pedido acreditado.
				 * @param int       $user_id Cliente.
				 * @param int       $points  Puntos de la compra (sin bono).
				 */
				do_action( 'akb_rewards_after_order_award', $order, $user_id, $points );
			}
		);
	}

	/**
	 * Deduce TODO lo acreditado al pedido (anulación). Idempotente: la meta
	 * queda en 0 tras deducir.
	 *
	 * @param int|mixed $order_id ID del pedido.
	 * @return void
	 */
	public function deduct_all( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}
		$this->with_order_lock(
			$order_id,
			function () use ( $order_id ): void {
				$order = $this->fresh_order( $order_id );
				if ( $order instanceof \WC_Order ) {
					$this->deduct_all_locked( $order );
				}
			}
		);
	}

	/**
	 * Cuerpo de la reversa completa. Requiere el candado de pedido tomado y
	 * el pedido re-leído fresco.
	 *
	 * @param \WC_Order $order Pedido fresco bajo candado.
	 * @return void
	 */
	private function deduct_all_locked( \WC_Order $order ): void {
		$order_id = (int) $order->get_id();

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_RELEASE_ESCROW, array( $order_id ) );
		}

		$pending = (int) $order->get_meta( self::META_PENDING );
		if ( $pending > 0 ) {
			$order->update_meta_data( self::META_PENDING, 0 );
			$order->update_meta_data( self::META_PENDING_STATUS, 'revoked' );
			$order->delete_meta_data( '_akb_rewards_pending_escrow_points' );
			$order->delete_meta_data( self::META_ESCROW_DUE );
			$order->save_meta_data();
			$order->add_order_note(
				sprintf( 'Puntos Akibara: %d puntos pendientes revocados por anulación del pedido sin afectar saldo disponible previo.', $pending )
			);
		}

		// Reconcilia el monto a descontar utilizando el mayor entre la meta del pedido
		// y los movimientos registrados en el ledger.
		$earned = max(
			(int) $order->get_meta( self::META_EARNED ),
			Ledger::sum_movements( 'order', $order_id, array( 'order', 'welcome_bonus', 'order_reversal', 'order_refund' ) )
		);
		if ( $earned <= 0 ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$reversals = Ledger::count_movements( 'order', $order_id, 'order_reversal' );

		$deducted = Ledger::debit(
			$user_id,
			$earned,
			'order_reversal',
			'order',
			$order_id,
			sprintf( 'Anulación del pedido #%d', $order_id ),
			false,
			sprintf( 'reversal_%d_%d', $order_id, $reversals + 1 )
		);

		if ( ! is_int( $deducted ) ) {
			// Sin candado de usuario no hay reversa — y una reversa no tiene
			// «próxima transición»: dejar huella y alarma, no silencio.
			$order->add_order_note(
				'Puntos Akibara: la reversa de puntos NO pudo ejecutarse (candado ocupado). Reintentar guardando el pedido de nuevo.'
			);
			do_action( 'akb_rewards_reversal_failed', $order, $earned );
			return;
		}

		$order->update_meta_data( self::META_EARNED, 0 );
		$order->save_meta_data();
		$order->add_order_note(
			sprintf( 'Puntos Akibara: %d puntos retirados por la anulación del pedido.', $deducted )
		);

		/**
		 * Reversa completa aplicada. El bono de bienvenida escucha acá para
		 * decidir si devuelve la elegibilidad (solo si recuperó el bono).
		 *
		 * @param \WC_Order $order    Pedido revertido.
		 * @param int       $user_id  Cliente.
		 * @param int       $deducted Puntos efectivamente retirados (0 posible).
		 */
		do_action( 'akb_rewards_after_order_deduct', $order, $user_id, $deducted );
	}

	/**
	 * Reembolso (parcial o total encubierto): recalcular sobre lo conservado
	 * y retirar la diferencia, preservando el bono de bienvenida.
	 *
	 * @param int $order_id  ID del pedido.
	 * @param int $refund_id ID del reembolso (clave de idempotencia del ajuste).
	 * @return void
	 */
	public function on_order_refunded( $order_id, $refund_id ): void {
		$this->with_order_lock(
			(int) $order_id,
			function () use ( $order_id, $refund_id ): void {
				$order = $this->fresh_order( (int) $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}

				$pending = (int) $order->get_meta( self::META_PENDING );
				// Obtiene el saldo acreditado neto para determinar el margen de ajuste.
				$earned = max(
					(int) $order->get_meta( self::META_EARNED ),
					Ledger::sum_movements( 'order', (int) $order_id, array( 'order', 'welcome_bonus', 'order_reversal', 'order_refund' ) )
				);
				if ( $pending <= 0 && $earned <= 0 ) {
					return; // Nada acreditado ni pendiente: nada que ajustar.
				}

				$user_id = (int) $order->get_customer_id();
				if ( $user_id <= 0 ) {
					return;
				}

				// Identifica si el reembolso es total considerando tanto el importe monetario
				// remanente como el valor neto de puntos canjeados.
				$money_left = max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
				if ( $money_left <= 0.01 && Calculator::net_redeemed_value( $order ) <= 0.01 ) {
					$this->deduct_all_locked( $order );
					return;
				}

				$bonus  = max( 0, (int) $order->get_meta( self::META_WELCOME_BONUS ) );
				$target = $this->points_at_sealed_rate( $order, Calculator::award_base_for_order( $order ) ) + $bonus;

				// Si los puntos aún están retenidos en carencia (pending) y no han pasado al Ledger
				if ( $pending > 0 && $earned <= 0 ) {
					if ( $target < $pending ) {
						$diff = $pending - $target;
						$order->update_meta_data( self::META_PENDING, $target );
						$order->update_meta_data( '_akb_rewards_pending_escrow_points', $target );
						$order->save_meta_data();
						$order->add_order_note(
							sprintf( 'Puntos Akibara: %d puntos pendientes revocados por reembolso parcial en carencia (quedan %d puntos pendientes).', $diff, $target )
						);
					}
					return;
				}

				// Compara contra la base íntegra redondeada para retirar únicamente la diferencia de líneas devueltas.
				$target_full = $this->points_at_sealed_rate(
					$order,
					max( 0.0, Calculator::items_base( $order ) - Calculator::net_redeemed_value( $order ) )
				) + $bonus;
				$diff        = min( $earned, $target_full ) - $target;

				if ( $diff <= 0 ) {
					return; // Nunca re-acreditar por un ajuste de reembolso.
				}

				$deducted = Ledger::debit(
					$user_id,
					$diff,
					'order_refund',
					'order',
					(int) $order_id,
					sprintf( 'Reembolso parcial del pedido #%d', (int) $order_id ),
					false,
					sprintf( 'refund_%d_%d', (int) $order_id, (int) $refund_id )
				);

				// Idempotencia: si este reembolso ya se aplicó, el event_key previene
				// movimientos duplicados y el sellado actualiza el estado meta.
				if ( ! is_int( $deducted ) ) {
					$order->add_order_note(
						'Puntos Akibara: el ajuste por reembolso NO pudo ejecutarse (candado ocupado). Reintentar guardando el pedido.'
					);
					do_action( 'akb_rewards_reversal_failed', $order, $diff );
					return;
				}

				$order->update_meta_data( self::META_EARNED, $target );
				$order->save_meta_data();
				$order->add_order_note(
					sprintf( 'Puntos Akibara: %d puntos retirados por reembolso parcial (quedan %d).', $deducted, $target )
				);
			}
		);
	}

	/**
	 * Puntos para una base con la tasa SELLADA en el pedido; si el pedido es
	 * anterior al sellado de tasa, cae a la configuración vigente (lo que
	 * hacía siempre).
	 *
	 * @param \WC_Order $order Pedido.
	 * @param float     $base  Base puntuable en pesos.
	 * @return int
	 */
	private function points_at_sealed_rate( \WC_Order $order, float $base ): int {
		$sealed = (string) $order->get_meta( self::META_RATE );

		if ( 1 === preg_match( '#^(\d+)/(\d+)$#', $sealed, $m ) ) {
			return Calculator::points_for_base( $base, (int) $m[1], (int) $m[2] );
		}

		return Calculator::points_for( $base );
	}

	/**
	 * Registra nota administrativa si se elimina un reembolso previo sin revertir
	 * débitos automáticamente, preservando la trazabilidad del ledger.
	 *
	 * @param int $refund_id Reembolso borrado.
	 * @param int $order_id  Pedido.
	 * @return void
	 */
	public function on_refund_deleted( $refund_id, $order_id ): void {
		$order = $this->fresh_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( 0 === Ledger::count_movements( 'order', (int) $order_id, 'order_refund' ) ) {
			return; // Ningún reembolso descontó puntos: nada que revisar.
		}

		$order->add_order_note(
			sprintf(
				'Puntos Akibara: se borró el reembolso #%d. Los puntos descontados por reembolsos NO se devuelven solos: revisar el historial del cliente y corregir desde WooCommerce → Puntos y Reseñas → «Otorgar o quitar puntos a mano».',
				(int) $refund_id
			)
		);

		/**
		 * Un reembolso borrado dejó los puntos del pedido necesitando revisión
		 * humana.
		 *
		 * @param \WC_Order $order     Pedido afectado.
		 * @param int       $refund_id Reembolso borrado.
		 */
		do_action( 'akb_rewards_manual_review_needed', $order, (int) $refund_id );
	}

	/**
	 * Programa la liberación de puntos en carencia vía Action Scheduler.
	 *
	 * @param \WC_Order $order       Pedido.
	 * @param int       $escrow_days Días de carencia.
	 * @return void
	 */
	public function maybe_schedule_escrow_release( \WC_Order $order, int $escrow_days ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$order_id = (int) $order->get_id();
		$pending  = (int) $order->get_meta( self::META_PENDING );
		if ( $pending <= 0 ) {
			return;
		}

		if ( function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( self::HOOK_RELEASE_ESCROW, array( $order_id ) ) ) {
			return; // Ya programado un job único para este pedido.
		}

		$due = time() + max( 1, $escrow_days ) * DAY_IN_SECONDS;
		$job = as_schedule_single_action(
			$due,
			self::HOOK_RELEASE_ESCROW,
			array( $order_id ),
			'akibara-rewards',
			true
		);

		if ( $job ) {
			$order->update_meta_data( self::META_ESCROW_DUE, $due );
			$order->save_meta_data();
		}
	}

	/**
	 * Libera los puntos retenidos en periodo de carencia hacia el saldo disponible del cliente.
	 *
	 * @param int|mixed $order_id ID del pedido.
	 * @return void
	 */
	public function release_escrow( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}

		$this->with_order_lock(
			$order_id,
			function () use ( $order_id ): void {
				$order = $this->fresh_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}

				if ( OrderStates::is_down( (string) $order->get_status() ) ) {
					return; // Pedido anulado/reembolsado: no liberar.
				}

				$pending = (int) $order->get_meta( self::META_PENDING );
				if ( $pending <= 0 ) {
					return;
				}

				$user_id = (int) $order->get_customer_id();
				if ( $user_id <= 0 ) {
					return;
				}

				$awards = Ledger::count_movements( 'order', $order_id, 'order' );
				$result = Ledger::credit(
					$user_id,
					$pending,
					'order',
					'order',
					$order_id,
					sprintf( 'Compra #%d (liberación de carencia)', $order_id ),
					true,
					sprintf( 'award_%d_%d', $order_id, $awards + 1 )
				);

				if ( ! is_int( $result ) ) {
					return; // Reintenta en la siguiente ejecución si el candado está ocupado.
				}

				$order->update_meta_data( self::META_EARNED, $pending + max( 0, (int) $order->get_meta( self::META_EARNED ) ) );
				$order->update_meta_data( self::META_PENDING, 0 );
				$order->update_meta_data( self::META_PENDING_STATUS, 'released' );
				$order->delete_meta_data( '_akb_rewards_pending_escrow_points' );
				$order->delete_meta_data( self::META_ESCROW_DUE );
				$order->save_meta_data();
				$order->add_order_note(
					sprintf( 'Puntos Akibara: %d puntos liberados tras el periodo de carencia de 7 días.', $pending )
				);

				do_action( 'akb_rewards_after_order_award', $order, $user_id, $pending );
			}
		);
	}

	/**
	 * Re-lee el pedido FRESCO: wc_get_order puede servir la caché persistente
	 * de pedidos, y la idempotencia entera cuelga de estas metas.
	 * `read_meta_data(true)` fuerza la vuelta a la base (mismo mecanismo que
	 * usa SPAR bajo su candado de canje).
	 *
	 * @param int $order_id ID del pedido.
	 * @return \WC_Order|false
	 */
	private function fresh_order( int $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$order->read_meta_data( true );
		}

		return $order instanceof \WC_Order ? $order : false;
	}

	/**
	 * Compuerta de vigencia del programa. Falla CERRADA: si la fecha de
	 * inicio configurada no parsea, NO se acredita (y se grita) — fallar
	 * abierta acreditaría justo los pedidos retroactivos que la compuerta
	 * existe para bloquear.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	private function program_allows( \WC_Order $order ): bool {
		$allowed = self::in_program( $order );

		if ( ! $allowed && '' === (string) $order->get_meta( self::META_PRE_PROGRAM_NOTED ) ) {
			// Nota ÚNICA: sin el sello, cada transición repetiría el aviso.
			$order->update_meta_data( self::META_PRE_PROGRAM_NOTED, 1 );
			$order->save_meta_data();
			$order->add_order_note(
				sprintf(
					'Puntos Akibara: pedido anterior al inicio del programa (%s); no acredita puntos.',
					substr( Options::program_start(), 0, 10 )
				)
			);
		}

		return $allowed;
	}

	/**
	 * ¿El pedido es del programa? La misma vara para acreditar la compra y para
	 * pagar al padrino, que cuelga de su propio estado de pedido: sin ella, un
	 * pedido anterior al programa que se completara hoy pagaría el premio.
	 *
	 * Sin efectos: la nota de «pedido anterior al programa» la deja la
	 * acreditación, que es la que el dueño ve en el pedido.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	public static function in_program( \WC_Order $order ): bool {
		$created = $order->get_date_created();
		$start   = self::parse_program_start( Options::program_start() );

		if ( ! $start instanceof \DateTimeImmutable ) {
			Log::error( 'akb_rewards_program_start malformada; acreditación BLOQUEADA hasta corregirla.' );
			$allowed = false;
		} elseif ( $created instanceof \WC_DateTime ) {
			$allowed = $created->getTimestamp() >= $start->getTimestamp();
		} else {
			$allowed = true;
		}

		/**
		 * Permite forzar una acreditación manual de un pedido pre-programa.
		 *
		 * OJO: desde la 0.4.0 este filtro gobierna DOS pagos, no uno. El premio
		 * a quien invitó consulta la misma compuerta, así que forzar un pedido
		 * viejo también paga ese premio.
		 *
		 * @param bool      $allowed Si el programa permite acreditar.
		 * @param \WC_Order $order   Pedido evaluado.
		 */
		return (bool) apply_filters( 'akb_rewards_award_allowed', $allowed, $order );
	}

	/**
	 * Valida la vigencia activa del programa para cotización y presentación en carrito.
	 *
	 * @return bool
	 */
	public static function program_open_now(): bool {
		return self::PROGRAMA_VIGENTE === self::program_state();
	}

	/**
	 * Clasifica el estado del programa según la fecha de inicio configurada.
	 *
	 * @return string PROGRAMA_VIGENTE, PROGRAMA_AGENDADO o PROGRAMA_ILEGIBLE.
	 */
	public static function program_state(): string {
		$start = self::parse_program_start( Options::program_start() );

		if ( ! $start instanceof \DateTimeImmutable ) {
			return self::PROGRAMA_ILEGIBLE;
		}

		return time() >= $start->getTimestamp() ? self::PROGRAMA_VIGENTE : self::PROGRAMA_AGENDADO;
	}

	/**
	 * Validación canónica de la fecha de inicio de vigencia del programa.
	 *
	 * Exige formato estricto `Y-m-d H:i:s` en la zona horaria del sitio para prevenir
	 * desbordamientos de fecha en valores malformados.
	 *
	 * @param string $valor Texto guardado o tecleado, «AAAA-MM-DD HH:MM:SS».
	 * @return \DateTimeImmutable|null Null si no es una fecha que el motor entienda.
	 */
	public static function parse_program_start( string $valor ): ?\DateTimeImmutable {
		$fecha = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $valor, wp_timezone() );

		return $fecha instanceof \DateTimeImmutable && $fecha->format( 'Y-m-d H:i:s' ) === $valor ? $fecha : null;
	}

	/**
	 * Ejecuta $fn bajo el candado del pedido. El candado de pedido protege la
	 * idempotencia de la acreditación; el de usuario (dentro del Ledger, y
	 * reentrante) protege el saldo. Orden fijo pedido→usuario: sin abrazos
	 * mortales.
	 *
	 * Público a propósito: la captura de canje (Redemption) mueve dinero por
	 * pedido y usa EXACTAMENTE este candado.
	 *
	 * @param int      $order_id ID del pedido.
	 * @param callable $fn       Cuerpo a ejecutar.
	 * @return void
	 */
	public function with_order_lock( int $order_id, callable $fn ): void {
		global $wpdb;

		$lock = 'akb_rewards_order_' . $order_id;

		$acquired = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT )
		);

		if ( '1' !== $acquired ) {
			Log::error( sprintf( 'Candado %s no disponible; operación de pedido pospuesta.', $lock ) );
			return;
		}

		try {
			$fn();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/AdminAdjust.php
================================================================

<?php
/**
 * AdminAdjust — otorgar o quitar puntos a mano desde el panel administrativo:
 * compensaciones y correcciones con trazabilidad contable en el ledger.
 *
 * Controles de seguridad y auditoría:
 *  - Permisos restringidos a `manage_woocommerce` con verificación de nonce.
 *  - Motivo descriptivo obligatorio.
 *  - Identificación del administrador que ejecutó el ajuste.
 *  - Acotación estricta de débitos al saldo disponible del cliente.
 *  - Notificación opcional por correo transaccional.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class AdminAdjust {

	public const ACTION = 'akb_rewards_admin_adjust';

	/**
	 * Nombre único para el nonce del formulario para evitar colisiones en el DOM.
	 */
	private const NONCE_NAME = 'akb_adj_nonce';
	private const TRANSIENT_NOTICE = 'akb_rw_adjust_notice_';

	/**
	 * Tipo de referencia de un ajuste ligado a un pedido. NO es `order`: ése lo
	 * concilia Accrual contra lo acreditado por la compra, y un premio manual no
	 * puede entrar en esa cuenta.
	 */
	public const REF_PEDIDO = 'order_adjust';

	/** Confirmación pendiente: qué avisos y sobre qué valores. De un solo uso. */
	private const TRANSIENT_CONFIRMA = 'akb_rw_adjust_confirma_';

	/** Avisos que se pueden confirmar, uno por causa. */
	public const AVISO_AJENO    = 'ajeno';
	public const AVISO_REPETIDO = 'repetido';

	/**
	 * Huella de lo que el dueño está confirmando. Si cambia cualquiera de los
	 * cuatro valores, la confirmación deja de valer y los avisos se re-evalúan.
	 *
	 * @param string $email  Correo del cliente.
	 * @param int    $pedido Pedido.
	 * @param string $signo  mas|menos.
	 * @param int    $points Puntos.
	 * @return string
	 */
	public static function huella( string $email, int $pedido, string $signo, int $points ): string {
		return md5( strtolower( trim( $email ) ) . '|' . $pedido . '|' . $signo . '|' . $points );
	}

	/**
	 * Une avisos confirmados sin repetir y en orden estable.
	 *
	 * @param string ...$listas Listas separadas por coma.
	 * @return string
	 */
	public static function avisos_unidos( string ...$listas ): string {
		$avisos = array();
		foreach ( $listas as $lista ) {
			foreach ( explode( ',', $lista ) as $aviso ) {
				$aviso = sanitize_key( trim( $aviso ) );
				if ( in_array( $aviso, array( self::AVISO_AJENO, self::AVISO_REPETIDO ), true ) && ! in_array( $aviso, $avisos, true ) ) {
					$avisos[] = $aviso;
				}
			}
		}
		sort( $avisos, SORT_STRING );

		return implode( ',', $avisos );
	}

	/**
	 * Qué avisos quedan confirmados con esta petición, si es que alguno.
	 *
	 * Exige las tres cosas: que el POST traiga exactamente los avisos que esta
	 * pantalla dejó pendientes, que la huella del POST sea la que se guardó, y
	 * que esa huella siga describiendo los valores que se están enviando.
	 *
	 * @param string $de_post        Avisos que dice confirmar el POST.
	 * @param string $huella_post    Huella que trae el POST.
	 * @param string $avisos_guardados Avisos que el servidor dejó pendientes.
	 * @param string $huella_guardada  Huella que el servidor dejó pendiente.
	 * @param string $huella_actual    Huella de los valores enviados ahora.
	 * @return string Avisos confirmados, o '' si algo no cuadra.
	 */
	public static function confirmacion_valida( string $de_post, string $huella_post, string $avisos_guardados, string $huella_guardada, string $huella_actual ): string {
		$pedidos = self::avisos_unidos( $de_post );
		if ( '' === $pedidos || $pedidos !== self::avisos_unidos( $avisos_guardados ) ) {
			return '';
		}
		if ( '' === $huella_guardada || ! hash_equals( $huella_guardada, $huella_post ) || ! hash_equals( $huella_actual, $huella_post ) ) {
			return '';
		}

		return $pedidos;
	}

	/**
	 * Cuelga el receptor y el bloque en la pantalla de ajustes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		// WordPress supplies an empty string to argument-less actions unless
		// accepted_args is zero. The optional account ID is for direct calls.
		add_action( 'akb_rewards_settings_after', array( $this, 'render_box' ), 10, 0 );
	}

	/**
	 * El formulario: cliente por correo, puntos ±, motivo y aviso opcional.
	 *
	 * @return void
	 */
	public function render_box( int $user_id = 0 ): void {
		$aviso  = get_transient( self::TRANSIENT_NOTICE . get_current_user_id() );
		$campos = array();
		if ( is_array( $aviso ) && '' !== (string) ( $aviso['texto'] ?? '' ) ) {
			delete_transient( self::TRANSIENT_NOTICE . get_current_user_id() );
			$tipos = array(
				'success' => 'success',
				'warning' => 'warning',
			);
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $tipos[ (string) ( $aviso['tipo'] ?? '' ) ] ?? 'error' ),
				esc_html( (string) $aviso['texto'] )
			);
			// Cuando falta una confirmación, el formulario vuelve RELLENO: el dueño
			// sólo marca la casilla, no reescribe correo, puntos, motivo y pedido.
			$campos = is_array( $aviso['campos'] ?? null ) ? $aviso['campos'] : array();
		}
		$selected_user = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		if ( $user_id > 0 && ! $selected_user instanceof \WP_User ) {
			return;
		}
		if ( $selected_user instanceof \WP_User ) {
			$campos['email'] = (string) $selected_user->user_email;
		}
		$pide_confirmacion = ! empty( $campos['pide_confirmacion'] );
		$signo             = 'menos' === ( $campos['signo'] ?? '' ) ? 'menos' : 'mas';
		?>
		<hr style="margin:32px 0 24px">
		<h2>Otorgar o quitar puntos a mano</h2>
		<p class="description" style="max-width:640px">Para compensaciones o correcciones fuera del circuito automático —por ejemplo, un problema con un pedido—. El movimiento queda en el historial del cliente con tu nombre y el motivo. Si indicas el pedido, te avisamos cuando ya recibió puntos a mano, para no premiarlo dos veces.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px">
			<?php wp_nonce_field( self::ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php if ( $selected_user instanceof \WP_User ) : ?>
				<input type="hidden" name="akb_adj_user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="akb_adj_email">Correo del cliente</label></th>
					<td><input name="akb_adj_email" id="akb_adj_email" type="email" required class="regular-text" placeholder="cliente@correo.cl" value="<?php echo esc_attr( (string) ( $campos['email'] ?? '' ) ); ?>" <?php echo $selected_user instanceof \WP_User ? 'readonly' : ''; ?>></td>
				</tr>
				<tr>
					<th scope="row"><label for="akb_adj_points">Puntos</label></th>
					<td>
						<select name="akb_adj_signo" id="akb_adj_signo" style="vertical-align:baseline" aria-label="Sentido del movimiento: otorgar o quitar">
							<option value="mas" <?php selected( $signo, 'mas' ); ?>>Otorgar +</option>
							<option value="menos" <?php selected( $signo, 'menos' ); ?>>Quitar −</option>
						</select>
						<input name="akb_adj_points" id="akb_adj_points" type="number" min="1" step="1" required class="small-text" placeholder="400" value="<?php echo esc_attr( empty( $campos['points'] ) ? '' : (string) (int) $campos['points'] ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="akb_adj_motivo">Motivo (obligatorio)</label></th>
					<td><input name="akb_adj_motivo" id="akb_adj_motivo" type="text" required maxlength="180" class="large-text" placeholder="Compensación por demora del pedido" value="<?php echo esc_attr( (string) ( $campos['motivo'] ?? '' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="akb_adj_pedido">Pedido (opcional)</label></th>
					<td>
						<input name="akb_adj_pedido" id="akb_adj_pedido" type="text" inputmode="numeric" class="small-text" placeholder="1234" value="<?php echo esc_attr( empty( $campos['pedido'] ) ? '' : (string) (int) $campos['pedido'] ); ?>">
						<p class="description">El número del pedido al que corresponde el premio. Evita premiar dos veces el mismo pedido sin darte cuenta.</p>
					</td>
				</tr>
				<?php if ( $pide_confirmacion ) : ?>
				<tr>
					<th scope="row">Confirmación</th>
					<td>
						<label><input name="akb_adj_confirmar" type="checkbox" value="1"> Confirmo: lo revisé y quiero aplicarlo igual</label>
						<input type="hidden" name="akb_adj_confirmar_de" value="<?php echo esc_attr( (string) ( $campos['aviso'] ?? '' ) ); ?>">
						<input type="hidden" name="akb_adj_huella" value="<?php echo esc_attr( (string) ( $campos['huella'] ?? '' ) ); ?>">
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row">Avisar al cliente</th>
					<td><label><input name="akb_adj_avisar" type="checkbox" value="1" <?php checked( ! array_key_exists( 'avisar', $campos ) || ! empty( $campos['avisar'] ) ); ?>> Enviarle un correo con el movimiento y su saldo</label></td>
				</tr>
			</table>
			<p class="submit" style="padding-top:0"><button type="submit" class="button button-primary">Aplicar movimiento</button></p>
		</form>
		<?php
	}

	/**
	 * Aplica el ajuste: valida, asienta con auditoría y avisa.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( self::ACTION, self::NONCE_NAME );

		$email  = isset( $_POST['akb_adj_email'] ) ? sanitize_email( wp_unslash( $_POST['akb_adj_email'] ) ) : '';
		$points = isset( $_POST['akb_adj_points'] ) ? absint( $_POST['akb_adj_points'] ) : 0;
		$signo  = isset( $_POST['akb_adj_signo'] ) && 'menos' === $_POST['akb_adj_signo'] ? 'menos' : 'mas';
		$motivo = isset( $_POST['akb_adj_motivo'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['akb_adj_motivo'] ) ) ) : '';
		$avisar = ! empty( $_POST['akb_adj_avisar'] );
		$selected_id = isset( $_POST['akb_adj_user_id'] ) ? absint( $_POST['akb_adj_user_id'] ) : 0;
		$selected_user = $selected_id > 0 ? get_user_by( 'id', $selected_id ) : false;
		if ( isset( $_POST['akb_adj_user_id'] ) ) {
			// Resolve the identity from the selected account, never from an editable
			// email or an Analytics customer ID supplied by the browser.
			$email = $selected_user instanceof \WP_User ? (string) $selected_user->user_email : '';
		}
		// «#1234», «1234» o «pedido 1234 (2026)»: el PRIMER número, no todos los
		// dígitos pegados —«pedido 1234 (2026)» daba 12342026, que puede existir—.
		// Los puntos de millar se quitan antes: «1.234» es 1234.
		$crudo  = isset( $_POST['akb_adj_pedido'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_adj_pedido'] ) ) : '';
		$pedido = preg_match( '/\d+/', str_replace( array( '.', ' ' ), '', $crudo ), $digitos ) ? absint( $digitos[0] ) : 0;

		/*
		 * La confirmación valida el conjunto de avisos reconocidos, los valores auditados
		 * y se almacena en un transient de un solo uso para prevenir reenvíos desde el historial.
		 */
		$confirmado = '';
		$pendiente  = get_transient( self::TRANSIENT_CONFIRMA . get_current_user_id() );
		if ( ! empty( $_POST['akb_adj_confirmar'] ) && is_array( $pendiente ) ) {
			delete_transient( self::TRANSIENT_CONFIRMA . get_current_user_id() );
			$de     = isset( $_POST['akb_adj_confirmar_de'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_adj_confirmar_de'] ) ) : '';
			$huella = isset( $_POST['akb_adj_huella'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_adj_huella'] ) ) : '';
			$confirmado = self::confirmacion_valida(
				$de,
				$huella,
				(string) ( $pendiente['avisos'] ?? '' ),
				(string) ( $pendiente['huella'] ?? '' ),
				self::huella( $email, $pedido, $signo, $points )
			);
		}

		$user = isset( $_POST['akb_adj_user_id'] ) ? $selected_user : ( '' !== $email ? get_user_by( 'email', $email ) : false );

		$resultado                     = $this->apply( $user instanceof \WP_User ? (int) $user->ID : 0, $points, 'menos' === $signo ? -1 : 1, $motivo, $pedido, $confirmado );
		list( $ok, $texto, $aplicado ) = $resultado;
		$aviso_pendiente               = is_string( $resultado[3] ?? '' ) ? (string) ( $resultado[3] ?? '' ) : '';
		$pide_confirmacion             = '' !== $aviso_pendiente;
		// Lo ya confirmado MÁS lo que falta: así el segundo aviso no borra el primero.
		$avisos_a_confirmar            = $pide_confirmacion ? self::avisos_unidos( $confirmado, $aviso_pendiente ) : '';
		if ( $pide_confirmacion ) {
			set_transient(
				self::TRANSIENT_CONFIRMA . get_current_user_id(),
				array(
					'avisos' => $avisos_a_confirmar,
					'huella' => self::huella( $email, $pedido, $signo, $points ),
				),
				5 * MINUTE_IN_SECONDS
			);
		}

		// Notifica el delta asentado por el ledger. Si es cero no hubo movimiento.
		if ( $ok && $avisar && 0 !== $aplicado && $user instanceof \WP_User ) {
			$this->notify( $user, $aplicado, $motivo );
		}

		set_transient(
			self::TRANSIENT_NOTICE . get_current_user_id(),
			array(
				'tipo'   => $ok ? 'success' : ( $pide_confirmacion ? 'warning' : 'error' ),
				'texto'  => $texto,
				'campos' => $pide_confirmacion
					? array(
						'email'             => $email,
						'points'            => $points,
						'signo'             => $signo,
						'motivo'            => $motivo,
						'pedido'            => $pedido,
						'avisar'            => $avisar,
						'pide_confirmacion' => true,
						'aviso'             => $avisos_a_confirmar,
						'huella'            => self::huella( $email, $pedido, $signo, $points ),
					)
					: array(),
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( $selected_id > 0 ? CustomerPointsPage::url( $selected_id ) : admin_url( 'admin.php?page=' . \Akibara\Rewards\Support\SettingsPage::SLUG ) );
		exit;
	}

	/**
	 * Núcleo testeable del ajuste.
	 *
	 * @param int    $user_id Cliente (0 = no encontrado).
	 * @param int    $points  Puntos (positivos).
	 * @param int    $sign    1 otorgar, -1 quitar.
	 * @param string $motivo  Motivo obligatorio.
	 * Retorna el delta asentado real (acotado al saldo en débitos) y valida si requiere
	 * confirmación explícita cuando el pedido ya fue premiado o pertenece a otro cliente.
	 *
	 * @param int    $order_id   Pedido al que corresponde (0 = sin pedido).
	 * @param string $confirmado Avisos ya confirmados, separados por coma: '', `ajeno`, `repetido` o ambos.
	 * @return array{0:bool,1:string,2:int,3?:string} [ok, mensaje, delta asentado, aviso por confirmar].
	 */
	public function apply( int $user_id, int $points, int $sign, string $motivo, int $order_id = 0, string $confirmado = '' ) {
		if ( $user_id <= 0 ) {
			return array( false, 'No existe un cliente con ese correo.', 0 );
		}
		if ( $points <= 0 ) {
			return array( false, 'Los puntos deben ser un número mayor que cero.', 0 );
		}
		if ( '' === $motivo ) {
			return array( false, 'El motivo es obligatorio: es lo que audita el movimiento.', 0 );
		}

		$admin = wp_get_current_user();
		$login = $admin instanceof \WP_User && '' !== (string) $admin->user_login ? (string) $admin->user_login : 'admin';
		$nota  = self::nota( $login, $motivo, $order_id );

		// Clave determinista por contenido y minuto para prevenir reenvíos accidentales concurrentes.
		$key = sprintf(
			'adjust_%d_%d_%s',
			$user_id,
			$sign * $points,
			substr( md5( $login . '|' . $motivo . '|' . $order_id . '|' . gmdate( 'YmdHi' ) ), 0, 24 )
		);

		// Notifica al administrador si el evento exacto ya fue registrado previamente.
		if ( Ledger::event_applied( $key ) ) {
			return array( false, 'Este mismo ajuste acaba de asentarse (doble envío ignorado): revisa el saldo antes de repetirlo.', 0 );
		}

		$ref_type = 'user';
		$ref_id   = $user_id;
		if ( $order_id > 0 ) {
			$pedido = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
			if ( ! is_object( $pedido ) || ! method_exists( $pedido, 'get_id' ) ) {
				return array( false, sprintf( 'No existe el pedido #%d. Revisa el número.', $order_id ), 0 );
			}
			// Valida que el pedido sea una orden de compra estándar (shop_order) y no un reembolso o borrador.
			$es_compra = method_exists( $pedido, 'get_customer_id' ) && method_exists( $pedido, 'get_billing_email' )
				&& ( ! method_exists( $pedido, 'get_type' ) || 'shop_order' === $pedido->get_type() );
			if ( ! $es_compra ) {
				return array( false, sprintf( 'El #%d no es un pedido de compra (puede ser un reembolso o un borrador). Usa el número del pedido del cliente.', $order_id ), 0 );
			}
			$estado = method_exists( $pedido, 'get_status' ) ? (string) $pedido->get_status() : '';
			if ( 'trash' === $estado || 'checkout-draft' === $estado ) {
				return array(
					false,
					sprintf(
						'trash' === $estado
							? 'El pedido #%d está en la papelera. Restáuralo o usa otro número.'
							: 'El #%d es un borrador de caja, no un pedido. Usa el número del pedido del cliente.',
						$order_id
					),
					0,
				);
			}
			$confirmados = explode( ',', self::avisos_unidos( $confirmado ) );
			if ( ! in_array( self::AVISO_AJENO, $confirmados, true ) && ! $this->order_belongs_to( $pedido, $user_id ) ) {
				return array( false, sprintf( 'El pedido #%d no es de este cliente. Revisa el número; si igual quieres asociarlo, marca «Confirmo» y vuelve a aplicar.', $order_id ), 0, self::AVISO_AJENO );
			}
			if ( $sign > 0 && ! in_array( self::AVISO_REPETIDO, $confirmados, true ) ) {
				$previo = Ledger::last_credit( self::REF_PEDIDO, $order_id, 'admin_adjust' );
				if ( null !== $previo ) {
					// Motivo y fecha van como ARGUMENTOS del formato, nunca dentro: un
					// «%» escrito por el dueño en un formato es un fatal (ya pasó).
					return array(
						false,
						sprintf(
							'El pedido #%1$d ya recibió %2$s puntos a mano el %3$s (%4$s). Si es otro premio, marca «Confirmo» y vuelve a aplicar.',
							$order_id,
							number_format_i18n( $previo['delta'] ),
							'' !== $previo['created_at'] ? substr( $previo['created_at'], 0, 16 ) : 'antes',
							'' !== $previo['note'] ? $previo['note'] : 'sin nota'
						),
						0,
						self::AVISO_REPETIDO,
					);
				}
			}
			$ref_type = self::REF_PEDIDO;
			$ref_id   = $order_id;
		}

		if ( $sign >= 0 ) {
			/*
			 * `counts_as_earned = false`: los ajustes manuales del administrador no
			 * incrementan el histórico `total_earned` para evitar asimetrías contables.
			 */
			$res = Ledger::credit( $user_id, $points, 'admin_adjust', $ref_type, $ref_id, $nota, false, $key );
		} else {
			$res = Ledger::debit( $user_id, $points, 'admin_adjust', $ref_type, $ref_id, $nota, false, $key );
		}

		if ( ! is_int( $res ) ) {
			return array( false, 'El movimiento no se pudo asentar (candado ocupado o infraestructura): inténtalo de nuevo.', 0 );
		}

		// Lo asentado, no lo pedido. Al otorgar coinciden —no hay acotación al
		// alta—; al quitar, el ledger devuelve lo que de verdad pudo descontar.
		// Es el mismo criterio con el que ya se redacta el aviso del admin.
		$aplicado = $sign >= 0 ? $points : -$res;

		// Una baja sin saldo no genera movimiento ni correo al cliente.
		if ( $sign < 0 && 0 === $res ) {
			return array(
				true,
				'No se quitó nada: el cliente ya estaba en 0 puntos. No se le envió ningún aviso.',
				0,
			);
		}

		return array(
			true,
			sprintf(
				'%s %s puntos %s. Saldo actual: %s.',
				$sign >= 0 ? 'Otorgados' : 'Quitados',
				number_format_i18n( $sign >= 0 ? $points : $res ),
				$sign >= 0 ? 'al cliente' : 'al cliente (clampado a su saldo)',
				number_format_i18n( Ledger::balance( $user_id ) )
			),
			$aplicado,
		);
	}

	/**
	 * Nota de auditoría, recortada respetando límites de bytes y secuencias multibyte válidas.
	 *
	 * La columna en base de datos admite hasta 255 bytes; asegura que el sufijo de pedido
	 * se preserve y previene truncamientos a mitad de carácter UTF-8.
	 *
	 * @param string $login    Quién lo hace.
	 * @param string $motivo   Motivo del dueño.
	 * @param int    $order_id Pedido, o 0.
	 * @return string
	 */
	private static function nota( string $login, string $motivo, int $order_id ): string {
		$prefijo = sprintf( 'Ajuste manual por %s: ', $login );
		$sufijo  = $order_id > 0 ? sprintf( ' (pedido #%d)', $order_id ) : '';
		$sitio   = 255 - strlen( $prefijo ) - strlen( $sufijo );
		if ( strlen( $motivo ) > $sitio ) {
			$motivo = function_exists( 'mb_strcut' )
				? mb_strcut( $motivo, 0, max( 0, $sitio ), 'UTF-8' )
				// Sin mbstring: cortar a bytes y retirar la secuencia UTF-8 que
				// quedó a medias, o `wpdb::insert()` rechaza la fila entera.
				: (string) preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', substr( $motivo, 0, max( 0, $sitio ) ) );
		}

		return $prefijo . $motivo . $sufijo;
	}

	/**
	 * ¿El pedido es de este cliente? Por cuenta, o por el correo de facturación
	 * cuando se compró como invitado.
	 *
	 * @param object $pedido  Pedido de WooCommerce.
	 * @param int    $user_id Cliente.
	 * @return bool
	 */
	private function order_belongs_to( object $pedido, int $user_id ): bool {
		if ( method_exists( $pedido, 'get_customer_id' ) && (int) $pedido->get_customer_id() === $user_id ) {
			return true;
		}

		$user   = get_userdata( $user_id );
		$correo = method_exists( $pedido, 'get_billing_email' ) ? strtolower( trim( (string) $pedido->get_billing_email() ) ) : '';

		return is_object( $user ) && '' !== $correo && strtolower( trim( (string) ( $user->user_email ?? '' ) ) ) === $correo;
	}

	/**
	 * Correo brandeado del ajuste (misma disciplina: es un aviso, no sella nada).
	 *
	 * @param \WP_User $user   Cliente.
	 * @param int      $points Delta con signo.
	 * @param string   $motivo Motivo visible.
	 * @return void
	 */
	private function notify( \WP_User $user, int $points, string $motivo ): void {
		if ( '' === (string) $user->user_email || ! function_exists( 'WC' ) ) {
			return;
		}

		$body = sprintf(
			'<p style="margin:0 0 12px">%s <strong>%s Puntos Akibara</strong> en tu cuenta: %s.</p>
			<p style="margin:0 0 12px">Tu saldo actual es de <strong>%s puntos</strong> — cada punto vale %s en tu próxima compra.</p>%s',
			$points >= 0 ? 'Te regalamos' : 'Se descontaron',
			esc_html( number_format_i18n( abs( $points ) ) ),
			esc_html( $motivo ),
			esc_html( number_format_i18n( Ledger::balance( (int) $user->ID ) ) ),
			esc_html( Options::redeem_label() ),
			Emails::cta( 'Ver mis puntos', Emails::rewards_url() )
		);

		Emails::send_branded(
			(string) $user->user_email,
			$points >= 0
				? sprintf( '[Akibara] 🎁 Sumaste %s Puntos Akibara', number_format_i18n( abs( $points ) ) )
				: '[Akibara] Movimiento en tus Puntos Akibara',
			$points >= 0 ? '¡Sumaste Puntos Akibara!' : 'Movimiento en tus Puntos Akibara',
			$body,
			Emails::context( 'admin_adjust', (int) $user->ID )
		);
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/Birthday.php
================================================================

<?php
/**
 * Birthday — Acreditación automática de puntos por cumpleaños.
 *
 * Diseño:
 *   - El cliente registra SOLO día y mes en Mi cuenta (sin año: dato mínimo,
 *     opt-in). Se guarda como 'mm-dd'.
 *   - Cron diario por Action Scheduler (patrón memoizado del Reconciler de
 *     search: cero consultas AS por request). Acredita UNA vez por año por
 *     usuario: meta del año pagado + event_key `birthday_{user}_{year}`
 *     contra el UNIQUE del ledger — doble red.
 *   - 29 de febrero: en años no bisiestos se paga el 1 de marzo (nadie se
 *     queda sin regalo por el calendario).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Birthday {

	public const HOOK           = 'akb_rewards_birthday_daily';
	public const META_MD        = '_akb_rewards_birthday_md';
	public const META_PAID_YEAR = '_akb_rewards_birthday_paid_year';
	private const SCHEDULED_MEMO = 'akb_rewards_birthday_scheduled';

	/**
	 * Cuelga campo de cuenta, guardado y cron.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_edit_account_form', array( $this, 'render_field' ) );
		add_action( 'woocommerce_save_account_details', array( $this, 'save_field' ), 10, 1 );
		// Registro de cuenta en WooCommerce: el cumpleaños entra como campo
		// opcional y se guarda con el mismo validador.
		add_action( 'woocommerce_register_form', array( $this, 'render_field' ) );
		add_action( 'woocommerce_created_customer', array( $this, 'save_field' ), 10, 1 );
		add_action( 'woocommerce_account_dashboard', array( $this, 'render_dashboard_reminder' ), 15 );
		add_action( self::HOOK, array( $this, 'run_daily' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Agenda la corrida diaria si falta (memoizada 12 h, patrón Reconciler).
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		// Solo admin/cron/CLI: optimización de superficie para evitar
		// lecturas de transient innecesarias en el frontend.
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( 'yes' === get_transient( self::SCHEDULED_MEMO ) ) {
			return;
		}
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::HOOK ) ) {
			$action_id = as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK );
			if ( ! is_int( $action_id ) || 0 >= $action_id ) {
				return;
			}
		}
		set_transient( self::SCHEDULED_MEMO, 'yes', 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Campo día/mes en Mi cuenta → Detalles.
	 *
	 * @return void
	 */
	public function render_field(): void {
		if ( Options::birthday_points() <= 0 ) {
			return;
		}

		$user_id = (int) get_current_user_id();
		$md      = (string) get_user_meta( $user_id, self::META_MD, true );
		$dia     = 0;
		$mes     = 0;
		if ( preg_match( '/^(\d{2})-(\d{2})$/', $md, $m ) ) {
			$mes = (int) $m[1];
			$dia = (int) $m[2];
		}

		$meses = array( 1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre' );

		// Accesibilidad: agrupación semántica con fieldset + legend y
		// etiquetas vinculadas por ID para lectores de pantalla.
		echo '<fieldset class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide akb-rw-birthday" style="border:0;padding:0;margin:0 0 16px;min-width:0">';
		printf(
			'<legend style="padding:0;font-weight:700">Tu cumpleaños 🎂 <span style="font-weight:normal">(te regalamos %s Puntos Akibara cada año; el año no lo pedimos)</span></legend>',
			esc_html( number_format_i18n( Options::birthday_points() ) )
		);
		echo '<span style="display:flex;gap:10px;margin-top:6px">';
		echo '<label for="akb_rw_birthday_dia" class="screen-reader-text">Día de tu cumpleaños</label>';
		echo '<select name="akb_rw_birthday_dia" id="akb_rw_birthday_dia" style="min-height:44px"><option value="">Día</option>';
		for ( $d = 1; $d <= 31; $d++ ) {
			printf( '<option value="%1$d"%2$s>%1$d</option>', (int) $d, selected( $dia, $d, false ) );
		}
		echo '</select>';
		echo '<label for="akb_rw_birthday_mes" class="screen-reader-text">Mes de tu cumpleaños</label>';
		echo '<select name="akb_rw_birthday_mes" id="akb_rw_birthday_mes" style="min-height:44px"><option value="">Mes</option>';
		foreach ( $meses as $num => $nombre ) {
			printf( '<option value="%d"%s>%s</option>', (int) $num, selected( $mes, $num, false ), esc_html( $nombre ) );
		}
		echo '</select></span>';
		if ( $user_id > 0 ) {
			$anio_actual = (int) ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y' );
			if ( (int) get_user_meta( $user_id, self::META_PAID_YEAR, true ) >= $anio_actual ) {
				echo '<p class="akb-rw-birthday-note" style="margin:6px 0 0;font-size:13px;opacity:.8">Ya recibiste tu regalo de cumpleaños de este año; si cambias la fecha, aplicará desde el próximo.</p>';
			}
		}
		echo '</fieldset>';
	}

	/**
	 * Recordatorio limpio en el dashboard de Mi Cuenta para usuarios sin día de cumpleaños registrado.
	 *
	 * Permite a los usuarios registrados vía Google OAuth registrar su fecha para recibir
	 * sus 1.000 Puntos Akibara sin tener que buscar el campo en Detalles de la cuenta.
	 *
	 * @return void
	 */
	public function render_dashboard_reminder(): void {
		$points = Options::birthday_points();
		if ( $points <= 0 ) {
			return;
		}

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$md = (string) get_user_meta( $user_id, self::META_MD, true );
		if ( '' !== $md ) {
			return;
		}

		$edit_url = function_exists( 'wc_customer_edit_account_url' )
			? wc_customer_edit_account_url()
			: ( function_exists( 'wc_get_endpoint_url' ) ? wc_get_endpoint_url( 'edit-account' ) : '#' );
		?>
		<div class="akb-dashboard__birthday-banner" style="margin:20px 0;padding:16px 20px;background:#fff8eb;border:1px solid #ffe3b3;border-radius:8px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
			<div style="display:flex;align-items:center;gap:12px">
				<span style="font-size:26px;line-height:1" aria-hidden="true">🎂</span>
				<div>
					<p style="margin:0;font-weight:600;color:#1a1a1a;font-size:15px">
						<?php
						echo esc_html(
							sprintf(
								'Recibe %s Puntos Akibara de regalo por tu cumpleaños',
								number_format_i18n( $points )
							)
						);
						?>
					</p>
					<p style="margin:4px 0 0;color:#666;font-size:13px">
						Registra tu día y mes para recibir tus 1.000 Puntos Akibara ($1.000) cada año.
					</p>
				</div>
			</div>
			<a href="<?php echo esc_url( $edit_url ); ?>" class="button" style="white-space:nowrap;font-size:13px;padding:8px 16px">
				Registrar fecha
			</a>
		</div>
		<?php
	}

	/**
	 * Guarda día/mes del campo (el año se descarta a propósito).
	 *
	 * @param int $user_id Usuario.
	 * @return void
	 */
	public function save_field( $user_id ): void {
		if ( ! isset( $_POST['akb_rw_birthday_dia'] ) && ! isset( $_POST['akb_rw_birthday_mes'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- corre dentro del guardado nativo de Woo, que ya verificó su nonce.
			return;
		}

		$day   = isset( $_POST['akb_rw_birthday_dia'] ) ? (int) $_POST['akb_rw_birthday_dia'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$month = isset( $_POST['akb_rw_birthday_mes'] ) ? (int) $_POST['akb_rw_birthday_mes'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 0 === $day && 0 === $month ) {
			delete_user_meta( (int) $user_id, self::META_MD );
			return;
		}

		// Validación con año bisiesto de referencia: permite fechas válidas
		// como 29 de febrero y rechaza combinaciones inexistentes.
		if ( $month < 1 || $month > 12 || $day < 1 || ! checkdate( $month, $day, 2024 ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( 'El cumpleaños no se guardó: esa combinación de día y mes no existe.', 'error' );
			}
			return;
		}

		update_user_meta( (int) $user_id, self::META_MD, sprintf( '%02d-%02d', $month, $day ) );
	}

	/**
	 * Corrida diaria: paga a los cumpleañeros de HOY (hora del sitio).
	 *
	 * @return void
	 */
	/**
	 * Días hacia atrás que revisa cada corrida, además de hoy.
	 *
	 * @since 0.4.7
	 */
	private const RECOVERY_DAYS = 3;

	public function run_daily(): void {
		$points = Options::birthday_points();
		if ( $points <= 0 ) {
			return;
		}

		$now = new \DateTimeImmutable( 'now', wp_timezone() );

		// Ventana de recuperación: evalúa la fecha actual y los días anteriores
		// configurados. Protege contra retrasos en la ejecución de Action
		// Scheduler o indisponibilidad temporal del transporte SMTP. La meta
		// del año pagado y el event_key previenen duplicados.
		$dias = array();
		for ( $atras = 0; $atras <= self::RECOVERY_DAYS; $atras++ ) {
			$dia = $now->modify( sprintf( '-%d day', $atras ) );
			// Cada día lleva SU año: un 31-12 recuperado el 02-01 es el regalo
			// del año pasado, y sellarlo con el año nuevo le quitaría el de este.
			$dias[ $dia->format( 'm-d' ) ] = (int) $dia->format( 'Y' );
		}

		// 29-02 en año no bisiesto: se paga el 01-03 (o su recuperación).
		if ( isset( $dias['03-01'] ) && ! checkdate( 2, 29, $dias['03-01'] ) ) {
			$dias['02-29'] = $dias['03-01'];
		}

		foreach ( $dias as $md => $anio_del_regalo ) {
			$this->pay_birthdays( (string) $md, $anio_del_regalo, $points );
		}
	}

	/**
	 * Paga a los usuarios con cumpleaños $md que no cobraron este año.
	 *
	 * @param string $md     'mm-dd'.
	 * @param int    $year   Año en curso.
	 * @param int    $points Puntos del regalo.
	 * @return void
	 */
	private function pay_birthdays( string $md, int $year, int $points ): void {
		// Paginación por lotes para procesar la totalidad de usuarios registrados.
		$paged = 1;
		do {
			$users = get_users(
				array(
					'meta_key'   => self::META_MD, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $md, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'     => 'ids',
					'number'     => 200,
					'paged'      => $paged,
					// Mismo patrón que Expiry: por ID se sale del índice del
					// join (el default user_login ordena el conjunto COMPLETO
					// en cada página) y sin count_total se ahorra el
					// SQL_CALC_FOUND_ROWS que nadie lee. El orden es
					// irrelevante: el bucle recorre a todos.
					'orderby'     => 'ID',
					'count_total' => false,
				)
			);

			$this->pay_batch( (array) $users, $md, $year, $points );
			++$paged;
		} while ( count( (array) $users ) === 200 );
	}

	/**
	 * Paga un lote de cumpleañeros.
	 *
	 * @param array<int,int> $users  IDs.
	 * @param string         $md     'mm-dd'.
	 * @param int            $year   Año en curso.
	 * @param int            $points Puntos del regalo.
	 * @return void
	 */
	private function pay_batch( array $users, string $md, int $year, int $points ): void {
		unset( $md );

		foreach ( $users as $user_id ) {
			$user_id = (int) $user_id;

			if ( (int) get_user_meta( $user_id, self::META_PAID_YEAR, true ) >= $year ) {
				// Si los puntos ya fueron acreditados pero el correo de saludo falló
				// por indisponibilidad de transporte, se reintenta el despacho
				// durante la ventana de recuperación.
				if ( (int) get_user_meta( $user_id, Emails::META_BIRTHDAY_GREETED_YEAR, true ) < $year ) {
					/** Documented below (the same hook, re-fired for the greeting). */
					do_action( 'akb_rewards_birthday_paid', $user_id, $points, $year );
				}
				continue;
			}

			$result = Ledger::credit(
				$user_id,
				$points,
				'birthday',
				'user',
				$user_id,
				sprintf( 'Regalo de cumpleaños %d', $year ),
				true,
				sprintf( 'birthday_%d_%d', $user_id, $year )
			);

			if ( ! is_int( $result ) ) {
				continue; // Infraestructura: mañana reintenta (la meta no se sella).
			}

			update_user_meta( $user_id, self::META_PAID_YEAR, $year );

			/**
			 * Cumpleaños pagado. El correo de saludo escucha acá.
			 *
			 * @param int $user_id Usuario.
			 * @param int $points  Puntos regalados.
			 * @param int $year    Año.
			 */
			do_action( 'akb_rewards_birthday_paid', $user_id, $points, $year );
		}
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/BrandedEmail.php
================================================================

<?php
/**
 * BrandedEmail — un `WC_Email` con identidad para los correos del programa.
 *
 * Por qué existe
 * --------------
 * `Points\Emails::send_branded()` utiliza `BrandedEmail` para dotar a los correos del programa
 * de un identificador `akb_rewards_<correo>` en los registros de WooCommerce (`WC_Email::send()`),
 * garantizando trazabilidad y observabilidad homologada con los correos nativos de la tienda.
 *
 * Con esta clase el registro reporta `akb_rewards_<correo>` y el envío pasa por `WC_Email::send()`,
 * que es la misma vía que usan los correos estándar del sistema.
 *
 * No tiene pantalla de ajustes (los del programa viven en Akibara → Puntos) ni
 * plantilla: el cuerpo ya viene envuelto y la parte plana ya viene derivada.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.15
 */
final class BrandedEmail extends \WC_Email {

	private string $plain = '';

	/** @var array<string, self> Una instancia por correo y petición. */
	private static array $instances = array();

	/**
	 * Una instancia por identificador evita duplicar los hooks que registra el
	 * constructor de WC_Email y que send() no retira.
	 *
	 * @param string $key   Identificador del correo ('order', 'expiry', 'birthday', 'review', 'review_invite', 'referral', 'admin_adjust', 'admin_alert').
	 * @param string $plain Parte plana ya derivada del HTML de ESTE envío.
	 * @return self
	 */
	public static function for( string $key, string $plain ): self {
		$key = sanitize_key( $key );
		if ( ! isset( self::$instances[ $key ] ) ) {
			self::$instances[ $key ] = new self( $key );
		}
		self::$instances[ $key ]->plain = $plain;

		return self::$instances[ $key ];
	}

	/**
	 * @param string $key Identificador ya saneado.
	 */
	private function __construct( string $key ) {
		$this->id    = 'akb_rewards_' . $key;
		$this->title = 'Puntos Akibara: ' . $key;

		parent::__construct();

		// Después del padre: el constructor lee `email_type` de una option que
		// no existe y sin esto el tipo cae a «plain» y el HTML saldría como texto.
		$this->email_type = 'multipart';
	}

	/**
	 * Sin ajustes propios: nada que guardar en WooCommerce → Correos.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array();
	}

	/**
	 * La parte plana. OJO: `WC_Email::handle_multipart()` NO la usa desde
	 * `send()` (sale porque `$this->sending` sólo se enciende en
	 * `get_content()`); la escribe el hook propio de `Emails::send_branded()`.
	 * Se expone igual por el contrato de `WC_Email`.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return $this->plain;
	}

	/**
	 * El cuerpo se pasa ya envuelto a `send()`; esta clase no renderiza plantillas.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return '';
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/Calculator.php
================================================================

<?php
/**
 * Calculator — la matemática del programa, PURA donde importa.
 *
 * La promesa del carrito y la acreditación del pedido usan LA MISMA base por
 * construcción: ítems (total tras descuentos + IVA), sin envío, menos lo
 * reembolsado y menos lo pagado con puntos. Así el «ganarás N» del checkout
 * coincide exacto con lo acreditado (requisito 4 de la spec; el plugin viejo
 * necesitó un mu-plugin entero para lograrlo).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Calculator {

	/**
	 * Puntos para una base en pesos. Función PURA.
	 *
	 * Redondeo estándar (configuración vigente de prod: rounding_mode=round).
	 *
	 * @param float $base        Base en pesos (IVA incluido), ya depurada.
	 * @param int   $earn_points Puntos por tramo.
	 * @param int   $earn_amount Tamaño del tramo en pesos.
	 * @return int
	 */
	public static function points_for_base( float $base, int $earn_points, int $earn_amount ): int {
		if ( $base <= 0 || $earn_points <= 0 || $earn_amount <= 0 ) {
			return 0;
		}

		return (int) round( $base / $earn_amount * $earn_points );
	}

	/**
	 * RAZÓN del programa: pesos devueltos por cada 100 pesos gastados.
	 * Función PURA.
	 *
	 * La devolución no la fija ningún campo por separado, la fijan los TRES a
	 * la vez: puntos por tramo ÷ tamaño del tramo × pesos por punto. Con la
	 * configuración vigente (2 puntos por cada $100, a $1 el punto) da 2,0.
	 * Con el mismo «2» y un tramo de $1 da 200,0, y ninguno de los dos campos
	 * se ve raro por su cuenta: por eso la baranda mira la razón y no el
	 * campo.
	 *
	 * Devuelve 0.0 si cualquiera de los tres es ≤ 0 — el tramo en 0 es el
	 * APAGADO documentado de la acumulación (Options::earn_amount) y tiene
	 * que seguir pasando la guarda.
	 *
	 * @param int $earn_points  Puntos por tramo.
	 * @param int $earn_amount  Tamaño del tramo en pesos.
	 * @param int $redeem_value Pesos de descuento por punto canjeado.
	 * @return float Porcentaje de devolución.
	 */
	public static function giveback_pct( int $earn_points, int $earn_amount, int $redeem_value ): float {
		if ( $earn_points <= 0 || $earn_amount <= 0 || $redeem_value <= 0 ) {
			return 0.0;
		}

		return $earn_points / $earn_amount * $redeem_value * 100;
	}

	/**
	 * Determina si la razón supera el porcentaje máximo permitido.
	 * Utiliza aritmética entera sin divisiones (puntos * pesos * 100 > techo * tramo)
	 * para evitar imprecisiones de coma flotante en múltiplos binarios.
	 *
	 * @param int $earn_points  Puntos por tramo.
	 * @param int $earn_amount  Tamaño del tramo en pesos.
	 * @param int $redeem_value Pesos de descuento por punto canjeado.
	 * @param int $max_pct      Techo en porcentaje.
	 * @return bool
	 */
	public static function giveback_exceeds( int $earn_points, int $earn_amount, int $redeem_value, int $max_pct ): bool {
		if ( $earn_points <= 0 || $earn_amount <= 0 || $redeem_value <= 0 ) {
			return false; // Cualquiera en 0 es el apagado documentado, no una razón.
		}

		return $earn_points * $redeem_value * 100 > $max_pct * $earn_amount;
	}

	/**
	 * Puntos para una base con la configuración vigente.
	 *
	 * @param float $base Base en pesos.
	 * @return int
	 */
	public static function points_for( float $base ): int {
		return self::points_for_base( $base, Options::earn_points(), Options::earn_amount() );
	}

	/**
	 * Base puntuable de un pedido: ítems conservados (tras reembolsos) menos
	 * el valor canjeado en puntos.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return float Base ≥ 0.
	 */
	public static function award_base_for_order( \WC_Order $order ): float {
		$base = self::conserved_items_base( $order );

		$base -= self::net_redeemed_value( $order );

		return max( 0.0, $base );
	}

	/**
	 * Canje NETO todavía aplicado a un pedido, en pesos: el descuento
	 * concedido menos lo legítimamente devuelto al cliente (reposición por reembolso).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return float ≥ 0.
	 */
	public static function net_redeemed_value( \WC_Order $order ): float {
		$redeemed = (float) $order->get_meta( Redemption::META_REDEEMED_VALUE );
		if ( $redeemed <= 0 ) {
			return 0.0;
		}

		$restored  = max( 0, (int) $order->get_meta( Redemption::META_RESTORED_POINTS ) );
		$unsettled = max( 0, (int) $order->get_meta( Redemption::META_UNSETTLED ) );
		$returned  = max( 0, $restored - $unsettled );

		// El valor por punto se deriva del propio pedido (valor canjeado / puntos canjeados)
		// para respetar la tasa original de la compra.
		$redeemed_points = max( 0, (int) $order->get_meta( Redemption::META_REDEEMED_POINTS ) );
		$per_point       = $redeemed_points > 0 ? $redeemed / $redeemed_points : Options::redeem_value();

		return max( 0.0, $redeemed - $returned * $per_point );
	}

	/**
	 * Valor conservado de los ítems del pedido: total tras descuentos + IVA,
	 * menos TODO lo reembolsado que toque mercadería.
	 *
	 * No usa $order->get_total(): ese total incluye el envío (que no puntúa) y
	 * ya pasó por max(0,…), y reconstruir sobre un total clampado regalaba
	 * puntos en compras de $0 (lección de integrity:200-204).
	 *
	 * Reembolsos por importe sin líneas explícitas se imputan a mercadería para asegurar
	 * un retiro conservador de puntos concedidos.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return float Valor conservado ≥ 0.
	 */
	public static function conserved_items_base( \WC_Order $order ): float {
		$base = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$base += (float) $item->get_total() + (float) $item->get_total_tax();
		}

		foreach ( $order->get_refunds() as $refund ) {
			$item_part  = 0.0;
			$other_part = 0.0;

			// En un reembolso los totales de línea vienen NEGATIVOS.
			foreach ( $refund->get_items( 'line_item' ) as $item ) {
				$item_part += abs( (float) $item->get_total() + (float) $item->get_total_tax() );
			}
			foreach ( array( 'shipping', 'fee' ) as $type ) {
				foreach ( $refund->get_items( $type ) as $item ) {
					$other_part += abs( (float) $item->get_total() + (float) $item->get_total_tax() );
				}
			}

			$amount   = abs( (float) $refund->get_amount() );
			$residual = $amount - $item_part - $other_part;

			$base -= $item_part + max( 0.0, $residual );
		}

		return max( 0.0, self::store_rounding( $base ) );
	}

	/**
	 * Redondeo contable alineado con los decimales oficiales de la moneda en la tienda (`wc_get_price_decimals()`).
	 *
	 * @param float $amount Suma cruda de líneas.
	 * @return float
	 */
	private static function store_rounding( float $amount ): float {
		return round( $amount, max( 0, (int) wc_get_price_decimals() ) );
	}

	/**
	 * Las líneas del pedido tal cual se vendieron (sin reembolsos), con el
	 * mismo redondeo que `conserved_items_base()`: es lo que se pone frente a
	 * ella para medir cuánta mercadería se devolvió. Mezclar una suma cruda con
	 * una redondeada inventaba «0,40 devueltos» sin reembolso (revisión H3).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return float
	 */
	public static function items_base( \WC_Order $order ): float {
		$base = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$base += (float) $item->get_total() + (float) $item->get_total_tax();
		}

		return max( 0.0, self::store_rounding( $base ) );
	}

	/**
	 * Base puntuable del carrito (la promesa), espejo exacto de la del pedido.
	 *
	 * @param \WC_Cart $cart           Carrito calculado.
	 * @param float    $redeemed_gross Valor bruto del canje activo en sesión.
	 * @return float Base ≥ 0.
	 */
	public static function award_base_for_cart( \WC_Cart $cart, float $redeemed_gross = 0.0 ): float {
		$base = 0.0;

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}
			$base += (float) ( $cart_item['line_total'] ?? 0 ) + (float) ( $cart_item['line_tax'] ?? 0 );
		}

		// Se redondea ANTES de restar el canje, como el total de WooCommerce.
		$base = self::store_rounding( $base );

		if ( $redeemed_gross > 0 ) {
			$base -= $redeemed_gross;
		}

		return max( 0.0, $base );
	}

	/**
	 * Tasa de impuesto efectiva de las líneas del carrito (0.19 con IVA
	 * chileno puro; menos con mixto exento). Copiada del mu-plugin de
	 * integridad, cuya matemática está probada en producción.
	 *
	 * @param \WC_Cart $cart Carrito calculado.
	 * @return float
	 */
	public static function cart_tax_rate( \WC_Cart $cart ): float {
		$net = 0.0;
		$tax = 0.0;

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}
			$net += (float) ( $cart_item['line_total'] ?? 0 );
			$tax += (float) ( $cart_item['line_tax'] ?? 0 );
		}

		if ( $net <= 0.0 || $tax <= 0.0 ) {
			return 0.0;
		}

		return $tax / $net;
	}

	/**
	 * Importe del fee de canje para que el descuento CON IVA sea exactamente
	 * el valor facial. Función PURA.
	 *
	 * WooCommerce reparte el IVA sobre los fees negativos aunque se declaren
	 * no imponibles (class-wc-cart-totals.php), así que el fee se escribe en
	 * NETO: bruto/(1+tasa). Con carrito exento (tasa 0) el fee es el bruto.
	 *
	 * Precisión, con honestidad: (a) la tasa se mide sobre las LÍNEAS y el
	 * reparto real de WooCommerce incluye el envío — con envío en otra clase
	 * de impuesto el descuento puede desviarse del facial (hoy todo va al 19%
	 * en la tienda); (b) con CLP a 0 decimales el redondeo final puede mover
	 * La captura cobra por el fee horneado en el pedido, garantizando sincronización
	 * exacta entre puntos debitados y descuento concedido.
	 *
	 * @param float $gross Descuento bruto pretendido (> 0).
	 * @param float $rate  Tasa efectiva del carrito.
	 * @return float Importe NEGATIVO del fee.
	 */
	public static function redemption_fee_amount( float $gross, float $rate ): float {
		if ( $gross <= 0 ) {
			return 0.0;
		}

		if ( $rate <= 0 ) {
			return -$gross;
		}

		return round( -( $gross / ( 1 + $rate ) ), 6 );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/CustomerPointsPage.php
================================================================

<?php
/** Account-specific points management linked from WooCommerce Customers.
 *
 * @package Akibara\Rewards
 */
declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/** A customer detail, not another customer directory. */
final class CustomerPointsPage {

	public const SLUG = 'akb-customer-points';
	private const PAGE_SIZE = 20;

	/** Register the detail screen in the WooCommerce permission context. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 99 );
	}

	/** Register a hidden detail page reached from the Customers report. */
	public function add_page(): void {
		// An empty parent registers admin_page_{slug}; adding then removing a
		// WooCommerce submenu leaves a hook WordPress can no longer resolve.
		add_submenu_page( '', 'Gestionar puntos', 'Gestionar puntos', 'manage_woocommerce', self::SLUG, array( $this, 'render' ) );
	}

	/** Generate a detail URL using a WordPress account ID. */
	public static function url( int $user_id, int $page = 1 ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&user_id=' . max( 0, $user_id ) . '&points_page=' . max( 1, $page ) );
	}

	/** Read one bounded ledger page; counts use the existing user_created index. */
	public function history( int $user_id, int $page ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) || $user_id <= 0 ) {
			return array( 'rows' => array(), 'total' => 0, 'page' => 1, 'pages' => 1 );
		}
		global $wpdb;
		$table = Schema::ledger_table();
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$pages = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
		$page  = max( 1, min( $pages, $page ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, delta, balance_after, action, ref_type, ref_id, note, created_at FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $user_id, self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			ARRAY_A
		);
		return array( 'rows' => $rows ?? array(), 'total' => $total, 'page' => $page, 'pages' => $pages );
	}

	/** Show the selected account, adjustment form and paginated ledger. */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only account selection.
		$page    = isset( $_GET['points_page'] ) ? absint( $_GET['points_page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		echo '<div class="wrap"><h1>Gestionar puntos</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=wc-admin&path=%2Fcustomers' ) ) . '">← Volver a Clientes</a></p>';
		if ( ! $user instanceof \WP_User ) {
			echo '<div class="notice notice-warning inline"><p>Este cliente no tiene una cuenta disponible. No se puede ajustar un saldo sin cuenta.</p></div></div>';
			return;
		}
		$balance = Ledger::balance( $user_id );
		$held    = Redemption::held_in_unpaid_orders( $user_id );
		echo '<h2>' . esc_html( (string) $user->display_name ) . '</h2><p>' . esc_html( (string) $user->user_email ) . ' · Cuenta #' . esc_html( (string) $user_id ) . '</p>';
		echo '<p><strong>Saldo disponible: ' . esc_html( number_format_i18n( $balance ) ) . ' puntos</strong>.</p>';
		if ( $held > 0 ) {
			echo '<p class="description">Además hay ' . esc_html( number_format_i18n( $held ) ) . ' puntos apartados en pedidos pendientes, ya descontados de este saldo.</p>';
		}
		( new AdminAdjust() )->render_box( $user_id );
		$history = $this->history( $user_id, $page );
		echo '<h2>Historial de puntos</h2><p>' . esc_html( number_format_i18n( $history['total'] ) ) . ' movimientos · Página ' . esc_html( (string) $history['page'] ) . ' de ' . esc_html( (string) $history['pages'] ) . '.</p>';
		if ( ! $history['rows'] ) {
			echo '<p>Esta cuenta aún no tiene movimientos de puntos.</p></div>';
			return;
		}
		echo '<div style="overflow:auto" tabindex="0" role="region" aria-label="Historial de puntos"><table class="widefat striped"><thead><tr><th scope="col">Fecha</th><th scope="col">Movimiento</th><th scope="col">Saldo tras movimiento</th><th scope="col">Motivo</th></tr></thead><tbody>';
		foreach ( $history['rows'] as $row ) {
			$delta = (int) $row['delta'];
			echo '<tr><td>' . esc_html( (string) $row['created_at'] ) . '</td><td>' . esc_html( ( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['balance_after'] ) ) . '</td><td>' . esc_html( (string) $row['note'] ) . '</td></tr>';
		}
		echo '</tbody></table></div><p>';
		if ( $history['page'] > 1 ) {
			echo '<a class="button" href="' . esc_url( self::url( $user_id, $history['page'] - 1 ) ) . '">← Movimientos más recientes</a> ';
		}
		if ( $history['page'] < $history['pages'] ) {
			echo '<a class="button" href="' . esc_url( self::url( $user_id, $history['page'] + 1 ) ) . '">Movimientos anteriores →</a>';
		}
		echo '</p></div>';
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/CustomersAdmin.php
================================================================

<?php
/** WooCommerce Customers report integration, using its public extension hooks.
 *
 * @package Akibara\Rewards
 */
declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Assets;

defined( 'ABSPATH' ) || exit;

/** Admin-only balances and server-side report filtering. */
final class CustomersAdmin {

	/** @var array<string,mixed> Current REST request filters. */
	private array $filters = array();
	/** @var array<int,array<string,mixed>> Nested REST dispatch contexts. */
	private array $contexts = array();
	/** @var array<int,bool> Existing WordPress accounts on the current page. */
	private array $accounts = array();

	/** Register official report, REST and script hooks. */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'rest_endpoints', array( $this, 'register_parameters' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'begin_request' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'end_request' ), 10, 3 );
		add_filter( 'woocommerce_analytics_customers_query_args', array( $this, 'query_args' ) );
		add_filter( 'woocommerce_analytics_customers_stats_query_args', array( $this, 'query_args' ) );
		add_filter( 'woocommerce_analytics_clauses_where_customers_subquery', array( $this, 'where' ) );
		add_filter( 'woocommerce_analytics_clauses_where_customers_stats_subquery', array( $this, 'where' ) );
		add_filter( 'woocommerce_analytics_report_should_use_cache', array( $this, 'use_cache' ), 10, 2 );
		add_filter( 'woocommerce_analytics_customers_select_query', array( $this, 'prime_accounts' ) );
		add_filter( 'woocommerce_rest_prepare_report_customers', array( $this, 'customer_response' ), 10, 3 );
	}

	/** Load before the wc-admin application so lazy report modules see the filters. */
	public function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen selection.
		if ( ! current_user_can( 'manage_woocommerce' ) || 'wc-admin' !== $page ) {
			return;
		}
		$file = 'assets/js/admin-customers.js';
		wp_enqueue_script( 'akb-rewards-customers', Assets::url( $file ), array( 'wp-hooks', 'wp-element' ), Assets::version( $file ), false );
	}

	/** Declare validated custom REST parameters on Customers and its statistics. */
	public function register_parameters( array $endpoints ): array {
		foreach ( array( '/wc-analytics/customers', '/wc-analytics/reports/customers', '/wc-analytics/reports/customers/stats' ) as $route ) {
			if ( ! isset( $endpoints[ $route ] ) ) {
				continue;
			}
			foreach ( $endpoints[ $route ] as &$handler ) {
				if ( ! is_array( $handler ) || ! isset( $handler['callback'] ) ) {
					continue;
				}
				$handler['args']['akb_points'] = array( 'type' => 'string', 'enum' => array( 'all', 'positive', 'zero', 'guest' ), 'default' => 'all' );
				foreach ( array( 'min', 'max' ) as $bound ) {
					$handler['args'][ 'akb_balance_' . $bound ] = array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647 );
				}
				$handler['args']['akb_balance_between'] = array( 'type' => 'array', 'minItems' => 2, 'maxItems' => 2, 'items' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647 ) );
			}
			unset( $handler );
		}
		return $endpoints;
	}

	/** Scope SQL changes to an authenticated Customers request, including nested dispatch. */
	public function begin_request( $response, array $handler, \WP_REST_Request $request ) {
		unset( $handler );
		$this->contexts[] = $this->filters;
		$this->filters    = array();
		if ( current_user_can( 'manage_woocommerce' ) && in_array( $request->get_route(), array( '/wc-analytics/customers', '/wc-analytics/reports/customers', '/wc-analytics/reports/customers/stats' ), true ) ) {
			$this->filters = self::normalize_filters( $request->get_params() );
		}
		return $response;
	}

	/** Restore the previous dispatch context. */
	public function end_request( $response, array $handler, \WP_REST_Request $request ) {
		unset( $handler, $request );
		$this->filters = array_pop( $this->contexts ) ?? array();
		return $response;
	}

	/** Keep custom selectors in the report query and cache identity. */
	public function query_args( array $args ): array {
		return array_merge( $args, $this->filters );
	}

	/** Normalize Woo's numeric filters; between is inclusive, min/max are strict. */
	public static function normalize_filters( array $params ): array {
		$out = array();
		if ( in_array( $params['akb_points'] ?? '', array( 'positive', 'zero', 'guest' ), true ) ) {
			$out['akb_points'] = $params['akb_points'];
		}
		foreach ( array( 'min', 'max' ) as $bound ) {
			$key = 'akb_balance_' . $bound;
			if ( isset( $params[ $key ] ) && is_numeric( $params[ $key ] ) ) {
				$out[ $key ] = max( 0, min( 2147483647, (int) $params[ $key ] ) );
			}
		}
		$between = $params['akb_balance_between'] ?? null;
		if ( is_string( $between ) ) {
			$between = explode( ',', $between );
		}
		if ( is_array( $between ) && 2 === count( $between ) && is_numeric( $between[0] ) && is_numeric( $between[1] ) ) {
			$between = array_map( static fn( $value ): int => max( 0, min( 2147483647, (int) $value ) ), array_values( $between ) );
			sort( $between, SORT_NUMERIC );
			$out['akb_balance_between'] = $between;
		}
		return $out;
	}

	/** Filter the inner query shared by row retrieval, pagination counts and statistics. */
	public function where( array $clauses ): array {
		if ( array() === $this->filters ) {
			return $clauses;
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'wc_customer_lookup';
		$account = "EXISTS (SELECT 1 FROM {$wpdb->users} akb_account WHERE akb_account.ID = {$table}.user_id)";
		$mode    = $this->filters['akb_points'] ?? 'all';
		if ( 'guest' === $mode ) {
			$clauses[] = 'AND NOT ' . $account;
			if ( count( $this->filters ) > 1 ) {
				$clauses[] = 'AND 1 = 0'; // A guest cannot also satisfy a numeric account-balance filter.
			}
			return $clauses;
		}
		// A scalar indexed lookup avoids duplicating customer/order rows. Selecting
		// the first meta row mirrors get_user_meta when legacy duplicates exist.
		$balance = $wpdb->prepare(
			"GREATEST(0, COALESCE((SELECT CAST(akb_points.meta_value AS SIGNED) FROM {$wpdb->usermeta} akb_points WHERE akb_points.user_id = {$table}.user_id AND akb_points.meta_key = %s ORDER BY akb_points.umeta_id ASC LIMIT 1), 0))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress table names only.
			Ledger::META_BALANCE
		);
		$conditions = array( $account );
		if ( 'positive' === $mode ) {
			$conditions[] = $balance . ' > 0';
		} elseif ( 'zero' === $mode ) {
			$conditions[] = $balance . ' = 0';
		}
		foreach ( array( 'min' => '>', 'max' => '<' ) as $bound => $operator ) {
			if ( isset( $this->filters[ 'akb_balance_' . $bound ] ) ) {
				$conditions[] = $balance . ' ' . $operator . ' ' . (int) $this->filters[ 'akb_balance_' . $bound ];
			}
		}
		if ( isset( $this->filters['akb_balance_between'] ) ) {
			$conditions[] = $balance . ' BETWEEN ' . (int) $this->filters['akb_balance_between'][0] . ' AND ' . (int) $this->filters['akb_balance_between'][1];
		}
		$clauses[] = 'AND (' . implode( ' AND ', $conditions ) . ')';
		return $clauses;
	}

	/** Filtered balances must reflect a just-saved adjustment, not Analytics' hourly cache. */
	public function use_cache( bool $use, string $key ): bool {
		return array() !== $this->filters && in_array( $key, array( 'customers', 'customers_stats' ), true ) ? false : $use;
	}

	/** Batch load only the WordPress accounts on the visible page. */
	public function prime_accounts( $results ) {
		$this->accounts = array();
		if ( ! current_user_can( 'manage_woocommerce' ) || ! is_object( $results ) || ! isset( $results->data ) ) {
			return $results;
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', array_column( $results->data, 'user_id' ) ) ) ) );
		if ( $ids ) {
			$existing       = get_users( array( 'include' => $ids, 'fields' => 'ID', 'number' => count( $ids ), 'count_total' => false ) );
			$this->accounts = array_fill_keys( array_map( 'intval', $existing ), true );
			update_meta_cache( 'user', $existing );
		}
		return $results;
	}

	/** Add live balance and account-specific management URL, never using Analytics customer_id. */
	public function customer_response( $response, $report, $request ) {
		unset( $request );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $response;
		}
		$user_id = (int) ( $report['user_id'] ?? 0 );
		$exists  = isset( $this->accounts[ $user_id ] );
		$data    = $response->get_data();
		$data['akb_rewards'] = array(
			'has_account' => $exists,
			'balance'     => $exists ? Ledger::balance( $user_id ) : null,
			'manage_url'  => $exists ? CustomerPointsPage::url( $user_id ) : '',
		);
		$response->set_data( $data );
		return $response;
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/Emails.php
================================================================

<?php
/**
 * Emails — UN correo de puntos por pedido, sellado solo si el transporte
 * confirmó el envío.
 *
 * Contrato de envío:
 *   - Sale a prioridad 100, después del bono (prioridad 10), con el total de
 *     puntos del pedido y el bono explicado en un único aviso.
 *   - Sólo sella el envío cuando el transporte devuelve true. Un fallo deja
 *     nota en el pedido y permite reintentar; al agotar los intentos emite alerta.
 *   - Sin `<style>`/`<title>` como texto: el cuerpo va con estilos inline y
 *     se envuelve con la plantilla de WooCommerce (que ya hereda la cabecera
 *     de marca del tema).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Emails {

	public const META_EMAIL_SENT = '_akb_rewards_email_sent';

	/** Reintento del correo de puntos de un pedido: (pedido, intento). */
	public const HOOK_RETRY_ORDER = 'akb_rewards_order_email_retry';

	/** Reintento del correo de reseña premiada: (reseña, cliente, puntos, intento). */
	public const HOOK_RETRY_REVIEW = 'akb_rewards_review_email_retry';

	/** Reintento del correo al padrino: (padrino, invitado, puntos, pedido, intento). */
	public const HOOK_RETRY_REFERRAL = 'akb_rewards_referral_email_retry';

	/** Usermeta del INVITADO: pedido cuyo premio ya se le avisó al padrino. */
	public const META_REFERRAL_EMAIL_SENT = '_akb_rewards_referral_email_sent';

	/** Intentos de un correo al cliente antes de avisar al dueño. */
	private const MAX_ATTEMPTS = 3;

	/** Espera entre intentos: el transporte caído suele volver en horas. */
	private const RETRY_DELAY = 6 * HOUR_IN_SECONDS;

	/**
	 * URL ABSOLUTA del panel de puntos del cliente.
	 *
	 * Absoluta y no relativa porque en un correo no hay documento base contra
	 * el que resolver una ruta.
	 *
	 * @return string
	 */
	public static function rewards_url(): string {
		return (string) ( function_exists( 'wc_get_account_endpoint_url' )
			? wc_get_account_endpoint_url( 'rewards' )
			: home_url( '/mi-cuenta/rewards/' ) );
	}

	/**
	 * Botón de marca para los correos del carril de puntos.
	 *
	 * Diseñado como botón accesible con área táctil mínima de 44px.
	 *
	 * Dos detalles que no son cosméticos:
	 *
	 *   - La clase `akb-email-btn` es OBLIGATORIA. Estos correos pasan por
	 *     `WC_Emails::wrap_message()`, que aplica `wp_kses_post()` y DESNUDA el
	 *     `-webkit-text-fill-color` inline (no está en la lista blanca de
	 *     `safecss_filter_attr()`). Sin la clase, la hoja del wrapper —que
	 *     rellena de rojo todo `<a>` del cuerpo— dejaba el texto rojo sobre el
	 *     botón rojo: 1,23:1 en Apple Mail, la app de Gmail y Outlook móvil.
	 *   - 15px de padding vertical + 15px de línea = 45px de alto real, por
	 *     encima del mínimo táctil de 44px.
	 *
	 * @param string $label Texto del botón.
	 * @param string $url   Destino absoluto.
	 * @return string HTML del botón, '' si no hay destino.
	 */
	public static function cta( string $label, string $url ): string {
		if ( '' === trim( $url ) ) {
			return '';
		}

		return sprintf(
			'<p style="margin:24px 0 0"><a class="akb-email-btn" href="%s" style="display:inline-block;background-color:#E60012;background-image:linear-gradient(#E60012,#E60012);color:#ffffff;-webkit-text-fill-color:#ffffff;padding:15px 28px;font-weight:bold;font-size:15px;line-height:1;text-decoration:none;border-radius:0;text-transform:uppercase;letter-spacing:0.5px;">%s</a></p>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Contexto UNIFORME del filtro del cuerpo.
	 *
	 * El segundo argumento del filtro llegaba con cuatro tipos distintos según
	 * el correo (WC_Order, int, WP_Comment) mientras el docblock documentaba
	 * uno solo: un consumidor tenía que adivinar con qué le tocaba. Ahora
	 * siempre es este array, con las mismas claves.
	 *
	 * @param string           $email   Identificador del correo.
	 * @param int              $user_id Cliente destinatario (0 si no aplica).
	 * @param \WC_Order|null    $order   Pedido, si el correo cuelga de uno.
	 * @param \WP_Comment|null  $comment Reseña, si el correo cuelga de una.
	 * @return array{email:string,user_id:int,order:\WC_Order|null,comment:\WP_Comment|null}
	 */
	public static function context( string $email, int $user_id, $order = null, $comment = null ): array {
		return array(
			'email'   => $email,
			'user_id' => $user_id,
			'order'   => $order instanceof \WC_Order ? $order : null,
			'comment' => $comment instanceof \WP_Comment ? $comment : null,
		);
	}

	/**
	 * Cuerpo plano del correo en vuelo, o null si no hay ninguno en vuelo.
	 *
	 * Vive en una estática porque el punto donde PHPMailer acepta la parte
	 * alternativa (`phpmailer_init`) está fuera de la pila del emisor.
	 */
	private static ?string $alt_body = null;

	/**
	 * Envía un correo de puntos con sus dos partes: HTML de marca y texto plano (`AltBody`).
	 *
	 * Garantiza compatibilidad con clientes de correo en modo texto y optimiza entregabilidad.
	 *
	 * Estos correos replican la mecánica multipart de WooCommerce vía `phpmailer_init`.
	 * La parte plana se deriva automáticamente del HTML (`html_to_plain()`) para evitar discrepancias.
	 * es el defecto clásico del multipart escrito por duplicado.
	 *
	 * @param string $to      Destinatario.
	 * @param string $subject Asunto.
	 * @param string $heading Encabezado de marca.
	 * @param string $body    Cuerpo HTML sin envolver.
	 * @param array{email:string,user_id:int,order:\WC_Order|null,comment:\WP_Comment|null} $context Contexto del filtro.
	 * @param array<int,string>                                                              $extra   Cabeceras adicionales (p. ej. List-Unsubscribe).
	 * @return bool Si el transporte confirmó.
	 */
	public static function send_branded( string $to, string $subject, string $heading, string $body, array $context, array $extra = array() ): bool {
		$mailer  = WC()->mailer();
		$wrapped = $mailer->wrap_message( $heading, $body );

		/**
		 * Cuerpo final de CUALQUIER correo de puntos, ya envuelto con la
		 * plantilla WC. Lo disparan los seis emisores del programa; el
		 * contexto dice cuál y con qué.
		 *
		 * @param string $wrapped Cuerpo HTML completo.
		 * @param array{email:string,user_id:int,order:\WC_Order|null,comment:\WP_Comment|null} $context
		 *        `email` es el identificador del correo: 'order', 'expiry',
		 *        'birthday', 'review', 'review_invite', 'referral',
		 *        'admin_adjust' o 'admin_alert'.
		 */
		$wrapped = (string) apply_filters( 'akb_rewards_points_email_body', $wrapped, $context );

		self::$alt_body = self::html_to_plain( $wrapped );
		add_action( 'phpmailer_init', array( self::class, 'attach_plain_part' ) );

		try {
			// Las cabeceras extra van DESPUÉS del content-type y cada una con su
			// propio CRLF: WC las concatena tal cual en el header del mensaje.
			$headers = "Content-Type: multipart/alternative\r\n";
			foreach ( $extra as $linea ) {
				$linea = trim( (string) $linea );
				if ( '' !== $linea ) {
					$headers .= $linea . "\r\n";
				}
			}

			// La identidad WC_Email identifica el aviso en el registro de correo.
			// La parte plana depende del hook propio: send() no llama get_content(),
			// por lo que WC_Email::handle_multipart() no activa su contenido alternativo.
			$sent = (bool) BrandedEmail::for( (string) ( $context['email'] ?? 'puntos' ), (string) self::$alt_body )
				->send( $to, $subject, $wrapped, $headers, array() );
		} finally {
			// En `finally`: si el transporte lanza, el hook NO puede quedarse
			// vivo contaminando el siguiente correo del sitio.
			remove_action( 'phpmailer_init', array( self::class, 'attach_plain_part' ) );
			self::$alt_body = null;
		}

		return $sent;
	}

	/**
	 * Rellena la parte plana del correo en vuelo.
	 *
	 * @internal Callback de `phpmailer_init`.
	 *
	 * @param object $phpmailer Instancia de PHPMailer.
	 * @return void
	 */
	public static function attach_plain_part( $phpmailer ): void {
		if ( null === self::$alt_body || ! is_object( $phpmailer ) ) {
			return;
		}

		$phpmailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propiedad de PHPMailer.
	}

	/**
	 * Texto plano legible a partir del HTML del correo.
	 *
	 * Los ENLACES se conservan con su URL: un `wp_strip_all_tags()` a secas
	 * dejaría «Canjear mis puntos» sin decir a dónde, que es justo lo que hace
	 * inútil una parte plana.
	 *
	 * @param string $html Cuerpo HTML ya envuelto.
	 * @return string
	 */
	public static function html_to_plain( string $html ): string {
		// Fuera lo que no es contenido: la hoja y el preheader invisible.
		$texto = (string) preg_replace( '#<(style|script|title)\b[^>]*>.*?</\1>#is', '', $html );

		// Cada enlace se lleva su destino al lado.
		$texto = (string) preg_replace_callback(
			'#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
			static function ( array $m ): string {
				$etiqueta = trim( (string) wp_strip_all_tags( $m[2] ) );
				$url      = trim( (string) $m[1] );
				if ( '' === $etiqueta || 0 === strpos( $url, 'mailto:' ) ) {
					return $url;
				}
				return $etiqueta . ': ' . $url;
			},
			$texto
		);

		$texto = (string) preg_replace( '#<br\s*/?>#i', "\n", $texto );
		$texto = (string) preg_replace( '#</(p|div|tr|h[1-6]|li)>#i', "\n", $texto );
		$texto = wp_strip_all_tags( $texto );
		$texto = html_entity_decode( $texto, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// El preheader de la cabecera rellena su línea con caracteres
		// INVISIBLES (combining grapheme joiner + zero-width non-joiner) para
		// alargar la vista previa de la bandeja. En HTML no se ven; en la parte
		// plana salían como basura en la primera línea.
		$texto = (string) preg_replace( '/[\x{034F}\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $texto );
		$texto = str_replace( "\xC2\xA0", ' ', $texto );

		// Espacios de indentación de las plantillas + líneas en blanco de más.
		$texto = (string) preg_replace( '/[ \t]+/', ' ', $texto );
		$texto = (string) preg_replace( '/ ?\n ?/', "\n", $texto );
		$texto = (string) preg_replace( '/\n{3,}/', "\n\n", $texto );

		return trim( $texto );
	}

	/**
	 * Año del último saludo de cumpleaños ENVIADO (se sella sólo con
	 * transporte confirmado). Birthday lo lee para volver a pedir el saludo
	 * cuando el regalo ya se pagó pero el correo no salió.
	 */
	public const META_BIRTHDAY_GREETED_YEAR = '_akb_rewards_birthday_email_year';

	/**
	 * Cuelga los hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'akb_rewards_after_order_award', array( $this, 'send_order_email' ), 100, 3 );
		add_action( 'akb_rewards_award_already_done', array( $this, 'maybe_resend' ), 10, 1 );
		add_action( 'akb_rewards_review_points_paid', array( $this, 'send_review_email' ), 100, 3 );
		add_action( 'akb_rewards_birthday_paid', array( $this, 'send_birthday_email' ), 100, 3 );
		add_action( 'akb_rewards_expiry_warning', array( $this, 'send_expiry_warning' ), 100, 3 );
		add_action( 'akb_rewards_referral_paid', array( $this, 'send_referral_email' ), 100, 4 );
		add_action( self::HOOK_RETRY_ORDER, array( $this, 'retry_order_email' ), 10, 2 );
		add_action( self::HOOK_RETRY_REVIEW, array( $this, 'retry_review_email' ), 10, 4 );
		add_action( self::HOOK_RETRY_REFERRAL, array( $this, 'retry_referral_email' ), 10, 5 );
	}

	/**
	 * Aviso de piedad: «tienes N puntos por vencer — úsalos». Brandeado, con
	 * CTA de canje. El marcador anti-spam (un aviso/mes) se sella AQUÍ y solo
	 * con transporte confirmado — si el SMTP cae, mañana se reintenta.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $soon      Puntos que vencerán.
	 * @param int $warn_days Ventana del aviso.
	 * @return void
	 */
	public function send_expiry_warning( $user_id, $soon, $warn_days ): void {
		// Revisa el sello del mes para evitar duplicidad de avisos de vencimiento.
		$mes = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m' );
		if ( (string) get_user_meta( (int) $user_id, Expiry::META_WARNED, true ) === $mes ) {
			return;
		}

		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user instanceof \WP_User || '' === (string) $user->user_email ) {
			return;
		}

		// Destino: el panel de puntos, NO el checkout. El checkout con el
		// carrito vacío es un callejón sin salida —WooCommerce devuelve al
		// carrito con «tu carrito está vacío»— mientras que
		// `/mi-cuenta/rewards/` muestra el saldo y arma la intención de canje
		// para la compra siguiente, que es el paso que de verdad salva los
		// puntos.
		$body = sprintf(
			'<p style="margin:0 0 12px">Tienes <strong>%s Puntos Akibara</strong> que vencerán dentro de los próximos %d días.</p>
			<p style="margin:0 0 12px">Cada punto vale %s de descuento: úsalos en tu próximo tomo antes de que se pierdan.%s</p>%s',
			esc_html( number_format_i18n( (int) $soon ) ),
			(int) $warn_days,
			esc_html( Options::redeem_label() ),
			esc_html( self::redeem_hint() ),
			self::cta( 'Canjear mis puntos', self::rewards_url() )
		);

		$sent = self::send_branded(
			(string) $user->user_email,
			sprintf( '[Akibara] %s Puntos Akibara por vencer — úsalos antes', number_format_i18n( (int) $soon ) ),
			'Tus puntos están por vencer',
			$body,
			self::context( 'expiry', (int) $user_id )
		);

		if ( $sent ) {
			// Un aviso al mes, sellado SOLO con transporte confirmado: si el
			// SMTP está caído, la corrida de mañana reintenta.
			update_user_meta(
				(int) $user_id,
				Expiry::META_WARNED,
				( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m' )
			);
		}
	}

	/**
	 * Correo de felicitación y regalo de cumpleaños con enlace a Mi cuenta.
	 *
	 * @param int $user_id Usuario.
	 * @param int $points  Puntos regalados (el monto configurado).
	 * @param int $year    Año del regalo.
	 * @return void
	 */
	public function send_birthday_email( $user_id, $points, $year ): void {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user instanceof \WP_User || '' === (string) $user->user_email ) {
			return;
		}

		if ( (int) get_user_meta( (int) $user_id, self::META_BIRTHDAY_GREETED_YEAR, true ) >= (int) $year ) {
			return; // Ya saludado este año.
		}

		$balance = Ledger::balance( (int) $user_id );

		$body = sprintf(
			'<p style="margin:0 0 12px">¡Feliz cumpleaños! 🎂 Te regalamos <strong>%s Puntos Akibara</strong> por tu día.</p>
			<p style="margin:0 0 12px">Ya están en tu cuenta: tu saldo es de <strong>%s puntos</strong> y cada punto vale %s de descuento.%s</p>%s',
			esc_html( number_format_i18n( (int) $points ) ),
			esc_html( number_format_i18n( $balance ) ),
			esc_html( Options::redeem_label() ),
			esc_html( self::redeem_hint() ),
			self::cta( 'Canjear mis puntos', self::rewards_url() )
		);

		$sent = self::send_branded(
			(string) $user->user_email,
			sprintf( '[Akibara] 🎂 %s Puntos Akibara de regalo por tu cumpleaños', number_format_i18n( (int) $points ) ),
			'¡Feliz cumpleaños de parte de Akibara!',
			$body,
			self::context( 'birthday', (int) $user_id )
		);

		if ( $sent ) {
			update_user_meta( (int) $user_id, self::META_BIRTHDAY_GREETED_YEAR, (int) $year );
		}
	}

	/**
	 * Correo brandeado del premio de reseña: «tus %d puntos por la reseña».
	 * Sellado por comment-meta solo con envío confirmado (misma disciplina
	 * que el correo de pedido); el banner de marca llega por el filtro
	 * compartido akibara_email_logo_banner_url del ecosistema.
	 *
	 * @param \WP_Comment $comment Reseña premiada.
	 * @param int         $user_id Cliente.
	 * @param int         $points  Puntos acreditados.
	 * @return void
	 */
	public function send_review_email( $comment, $user_id, $points, $attempt = 1 ): void {
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		$comment_id = (int) $comment->comment_ID;
		if ( '' !== (string) get_comment_meta( $comment_id, '_akb_rewards_review_email_sent', true ) ) {
			return;
		}

		$user = get_user_by( 'id', (int) $user_id );
		$to   = $user instanceof \WP_User ? (string) $user->user_email : (string) $comment->comment_author_email;
		if ( '' === $to ) {
			return;
		}

		$balance = Ledger::balance( (int) $user_id );
		$titulo  = get_the_title( (int) $comment->comment_post_ID );

		// Este era el único correo del programa que llegaba SIN un solo enlace:
		// avisaba del premio y dejaba al cliente sin camino a su saldo.
		$body = sprintf(
			'<p style="margin:0 0 12px">¡Tu reseña de <strong>%s</strong> fue aprobada! Sumaste <strong>%s Puntos Akibara</strong> por compartirla con foto, equivalentes a <strong>%s CLP de descuento</strong>.</p>
			<p style="margin:0 0 12px">Tu saldo actual es de <strong>%s puntos</strong> — cada punto vale %s en tu próxima compra.%s</p>
			<p style="margin:0"><span style="color:#CC0010;font-weight:bold">Gracias por ayudar a otros coleccionistas a decidir.</span></p>%s',
			esc_html( (string) $titulo ),
			esc_html( number_format_i18n( (int) $points ) ),
			esc_html( Options::points_label( (int) $points ) ),
			esc_html( number_format_i18n( $balance ) ),
			esc_html( Options::redeem_label() ),
			esc_html( self::redeem_hint() ),
			self::cta( 'Ver mis puntos', self::rewards_url() )
		);

		$sent = self::send_branded(
			$to,
			sprintf( '[Akibara] Tu reseña sumó %s Puntos Akibara', number_format_i18n( (int) $points ) ),
			'Tu reseña sumó puntos',
			$body,
			self::context( 'review', (int) $user_id, null, $comment )
		);

		if ( $sent ) {
			update_comment_meta( $comment_id, '_akb_rewards_review_email_sent', time() );
			return;
		}

		// El correo se reintenta por separado del premio, que sólo se acredita una vez.
		self::retry_later( self::HOOK_RETRY_REVIEW, array( $comment_id, (int) $user_id, (int) $points ), (int) $attempt );
	}

	/**
	 * Reintento agendado del correo de reseña premiada.
	 *
	 * @param int $comment_id Reseña.
	 * @param int $user_id    Cliente.
	 * @param int $points     Puntos acreditados.
	 * @param int $attempt    Intento que corre.
	 * @return void
	 */
	public function retry_review_email( $comment_id, $user_id, $points, $attempt ): void {
		$comment = get_comment( (int) $comment_id );
		if ( $comment instanceof \WP_Comment ) {
			$this->send_review_email( $comment, (int) $user_id, (int) $points, (int) $attempt );
		}
	}

	/**
	 * Correo al padrino: su invitación le hizo ganar puntos.
	 *
	 * Comunica al usuario que su recomendación se concretó exitosamente, preservando
	 * la privacidad del invitado sin exponer detalles de su compra.
	 *
	 * Valida que el premio continúe vigente en el ledger antes del envío.
	 *
	 * @param int           $referrer Padrino.
	 * @param int           $invitee  Invitado.
	 * @param int           $points   Puntos pagados.
	 * @param \WC_Order|int $order    Pedido que pagó el premio (su id, en el reintento).
	 * @param int           $attempt  Intento que corre.
	 * @return void
	 */
	public function send_referral_email( $referrer, $invitee, $points, $order = 0, $attempt = 1 ): void {
		$referrer = (int) $referrer;
		$invitee  = (int) $invitee;
		$order_id = $order instanceof \WC_Order ? (int) $order->get_id() : (int) $order;

		if ( $order_id <= 0 || (int) get_user_meta( $invitee, Referrals::META_REFERRAL_ORDER, true ) !== $order_id ) {
			return; // El premio se revirtió (o no hay pedido): nada que avisar.
		}

		// Valida en el ledger que el crédito del referido continúe vigente antes de enviar.
		if ( Ledger::sum_movements( 'user', $invitee, array( 'referral', 'referral_reversal' ) ) < (int) $points ) {
			return;
		}

		if ( (int) get_user_meta( $invitee, self::META_REFERRAL_EMAIL_SENT, true ) === $order_id ) {
			return;
		}

		$user = get_user_by( 'id', $referrer );
		if ( ! $user instanceof \WP_User || '' === (string) $user->user_email ) {
			return;
		}

		$body = sprintf(
			'<p style="margin:0 0 12px">¡Buenas noticias! Tu amigo completó su primera compra y acreditamos <strong>%s Puntos Akibara</strong> en tu cuenta.</p>
			<p style="margin:0 0 12px">Tu saldo actual es de <strong>%s puntos</strong> — cada punto equivale a %s para descontar en tus próximos mangas y cómics.%s</p>
			<p style="margin:0;color:#888;font-size:13px">¡Muchas gracias por recomendar Akibara a tus amigos!</p>%s',
			esc_html( number_format_i18n( (int) $points ) ),
			esc_html( number_format_i18n( Ledger::balance( $referrer ) ) ),
			esc_html( Options::redeem_label() ),
			esc_html( self::redeem_hint() ),
			self::cta( 'Ver mis puntos', self::rewards_url() )
		);

		$sent = self::send_branded(
			(string) $user->user_email,
			sprintf( '[Akibara] ¡Tu amigo completó su primera compra! Acreditamos %s Puntos Akibara en tu saldo', number_format_i18n( (int) $points ) ),
			'¡Tu amigo completó su primera compra!',
			$body,
			self::context( 'referral', $referrer )
		);

		if ( $sent ) {
			update_user_meta( $invitee, self::META_REFERRAL_EMAIL_SENT, $order_id );
			return;
		}

		self::retry_later( self::HOOK_RETRY_REFERRAL, array( $referrer, $invitee, (int) $points, $order_id ), (int) $attempt );
	}

	/**
	 * Reintento agendado del correo al padrino.
	 *
	 * @param int $referrer Padrino.
	 * @param int $invitee  Invitado.
	 * @param int $points   Puntos pagados.
	 * @param int $order_id Pedido que pagó el premio.
	 * @param int $attempt  Intento que corre.
	 * @return void
	 */
	public function retry_referral_email( $referrer, $invitee, $points, $order_id, $attempt ): void {
		$this->send_referral_email( (int) $referrer, (int) $invitee, (int) $points, (int) $order_id, (int) $attempt );
	}

	/**
	 * Reintento agendado del correo de puntos de un pedido.
	 *
	 * @param int $order_id Pedido.
	 * @param int $attempt  Intento que corre.
	 * @return void
	 */
	public function retry_order_email( $order_id, $attempt ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->maybe_resend( $order, (int) $attempt );
		}
	}

	/**
	 * Agenda el siguiente intento de un correo al cliente, o avisa al dueño si
	 * ya no quedan.
	 *
	 * @param string           $hook    Acción del reintento.
	 * @param array<int,mixed> $args    Argumentos sin el intento.
	 * @param int              $attempt Intento que acaba de fallar.
	 * @return void
	 */
	private static function retry_later( string $hook, array $args, int $attempt ): void {
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			/**
			 * Un correo al cliente no salió tras todos sus intentos. Las
			 * alertas al dueño escuchan acá.
			 *
			 * @param string           $hook Reintento agotado.
			 * @param array<int,mixed> $args Sus argumentos, con el último intento al final.
			 */
			do_action( 'akb_rewards_email_gave_up', $hook, array_merge( $args, array( $attempt ) ) );
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::RETRY_DELAY, $hook, array_merge( $args, array( $attempt + 1 ) ) );
		}
	}

	/**
	 * Reintento: el pedido ya estaba acreditado pero el correo pudo haber
	 * fallado. Si no está sellado, se reenvía.
	 *
	 * @param \WC_Order $order Pedido ya acreditado.
	 * @return void
	 */
	public function maybe_resend( $order, $attempt = 1 ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_EMAIL_SENT ) ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();
		$earned  = (int) $order->get_meta( Accrual::META_EARNED );

		if ( $user_id > 0 && $earned > 0 ) {
			$bonus = (int) $order->get_meta( Accrual::META_WELCOME_BONUS );
			$this->deliver( $order, $user_id, $earned - $bonus, $bonus, (int) $attempt );
		}
	}

	/**
	 * Correo consolidado tras la acreditación (compra + bono si lo hubo).
	 *
	 * @param \WC_Order $order   Pedido acreditado.
	 * @param int       $user_id Cliente.
	 * @param int       $points  Puntos de la compra (sin bono).
	 * @return void
	 */
	public function send_order_email( $order, $user_id, $points ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_EMAIL_SENT ) ) {
			return;
		}

		$bonus = (int) $order->get_meta( Accrual::META_WELCOME_BONUS );

		// El aviso de acreditación se envía si hubo puntos por compra o bono de bienvenida.
		if ( (int) $points <= 0 && $bonus <= 0 ) {
			return;
		}

		$this->deliver( $order, (int) $user_id, (int) $points, $bonus );
	}

	/**
	 * La condición del canje, dicha donde se empuja a canjear.
	 *
	 * Los correos son la superficie que MANDA a actuar («úsalos antes de que se
	 * pierdan», «canjéalos hoy mismo»): callar el carrito mínimo manda al
	 * cliente a una pantalla donde no puede hacer lo que el correo le pidió.
	 *
	 * @return string Frase lista para concatenar, o '' si no hay mínimo.
	 */
	private static function redeem_hint(): string {
		$minimo = Options::redeem_min_cart();

		return $minimo > 0
			? sprintf( ' Se canjean en compras desde $%s en productos.', number_format_i18n( $minimo ) )
			: '';
	}

	/**
	 * Construye y envía el correo; sella la meta solo si el transporte
	 * confirmó.
	 *
	 * @param \WC_Order $order   Pedido.
	 * @param int       $user_id Cliente.
	 * @param int       $points  Puntos de la compra.
	 * @param int       $bonus   Bono de bienvenida incluido (0 si no hubo).
	 * @return void
	 */
	private function deliver( \WC_Order $order, int $user_id, int $points, int $bonus, int $attempt = 1 ): void {
		// El saldo pertenece a la cuenta: se envía a su correo, con facturación
		// como respaldo únicamente si la cuenta no tiene dirección.
		$cuenta = get_user_by( 'id', $user_id );
		$to     = $cuenta instanceof \WP_User ? (string) $cuenta->user_email : '';
		$propio = '' !== $to;

		if ( '' === $to ) {
			$to = (string) $order->get_billing_email();
		}

		if ( '' === $to ) {
			return;
		}

		$total   = $points + $bonus;
		$balance = Ledger::balance( $user_id );

		$subject = $points > 0
			? sprintf( '[Akibara] Sumaste %s Puntos Akibara con tu pedido', number_format_i18n( $total ) )
			: sprintf( '[Akibara] Te regalamos %s Puntos Akibara de bienvenida', number_format_i18n( $total ) );
		$heading = 'Tus Puntos Akibara';

		// Utiliza el nombre de facturación como respaldo si el usuario no tiene first_name asignado.
		$name     = $propio ? trim( (string) get_user_meta( $user_id, 'first_name', true ) ) : '';
		$es_misma = ! $propio || strtolower( $to ) === strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' === $name && $es_misma ) {
			$name = trim( (string) $order->get_billing_first_name() );
		}
		$hola = '' !== $name ? sprintf( '¡Hola, %s!', esc_html( $name ) ) : '¡Hola!';

		$lines = array();
		if ( $points > 0 ) {
			$lines[] = sprintf(
				'<p style="margin:0 0 12px;">%s Tu pedido <strong>#%d</strong> ya está confirmado y con él sumaste <strong>%s Puntos Akibara</strong>.</p>',
				$hola,
				(int) $order->get_id(),
				esc_html( number_format_i18n( $points ) )
			);

			if ( $bonus > 0 ) {
				$lines[] = sprintf(
					'<p style="margin:0 0 12px;">Además, por ser tu primera compra te regalamos un <strong>bono de bienvenida de %s puntos</strong>.</p>',
					esc_html( number_format_i18n( $bonus ) )
				);
			}
		} else {
			// Sólo el bono: nunca «sumaste 0 Puntos Akibara».
			$lines[] = sprintf(
				'<p style="margin:0 0 12px;">%s Tu pedido <strong>#%d</strong> ya está confirmado y, por ser tu primera compra, te regalamos un <strong>bono de bienvenida de %s puntos</strong>.</p>',
				$hola,
				(int) $order->get_id(),
				esc_html( number_format_i18n( $bonus ) )
			);
		}

		$lines[] = sprintf(
			'<p style="margin:0 0 12px;">Tu saldo actual es de <strong>%s puntos</strong> — cada punto vale %s.%s</p>',
			esc_html( number_format_i18n( $balance ) ),
			esc_html( Options::redeem_label() ),
			esc_html( self::redeem_hint() )
		);

		// El CTA manda: el botón lleva al saldo. La página del programa queda
		// como enlace secundario —es información, no acción— y sólo si existe.
		$lines[] = self::cta( 'Ver mis puntos', self::rewards_url() );

		$programa_url = ProgramPage::url();
		if ( '' !== $programa_url ) {
			$lines[] = sprintf(
				'<p style="margin:12px 0 0;font-size:13px;"><a href="%s" style="color:#CC0010;">Conoce cómo funciona el programa &rarr;</a></p>',
				esc_url( $programa_url )
			);
		}

		$body = implode( '', $lines );

		$sent = self::send_branded( $to, $subject, $heading, $body, self::context( 'order', $user_id, $order ) );

		if ( $sent ) {
			$order->update_meta_data( self::META_EMAIL_SENT, time() );
			$order->save_meta_data();
			return;
		}

		$order->add_order_note(
			$attempt < self::MAX_ATTEMPTS
				? sprintf( 'Puntos Akibara: el correo de puntos NO pudo enviarse (intento %d de %d); se reintentará solo en 6 horas.', $attempt, self::MAX_ATTEMPTS )
				: sprintf( 'Puntos Akibara: el correo de puntos NO pudo enviarse tras %d intentos.', self::MAX_ATTEMPTS )
		);

		self::retry_later( self::HOOK_RETRY_ORDER, array( (int) $order->get_id() ), $attempt );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/Expiry.php
================================================================

<?php
/**
 * Expiry — Vencimiento de puntos configurable según antigüedad en el programa.
 *
 * Contabilidad FIFO contra el ledger:
 *   vencible = max(0, ganado_antes_del_corte - total_debitado_historico)
 * Los débitos (canjes, reversas y vencimientos previos) consumen primero los puntos
 * más antiguos. La fórmula es autocorectiva y previene débitos redundantes.
 *
 * Incluye notificación por correo previo al vencimiento (configurable).
 * 0 meses = los puntos no vencen.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Expiry {

	public const HOOK             = 'akb_rewards_expiry_daily';
	public const HOOK_BATCH       = 'akb_rewards_expiry_batch';
	public const HOOK_WARN_ASYNC  = 'akb_rewards_expiry_warn_async';
	public const META_WARNED      = '_akb_rewards_expiry_warned';
	private const SCHEDULED_MEMO  = 'akb_rewards_expiry_scheduled';
	private const BATCH_SIZE      = 100;

	/**
	 * Meses de vida de los puntos. 0 = no vencen.
	 *
	 * @return int
	 */
	public static function months(): int {
		if ( 'yes' !== (string) get_option( 'akb_rewards_expiry_enabled', 'yes' ) ) {
			return 0; // Interruptor de un clic: apagado = los puntos no vencen.
		}
		return max( 0, (int) get_option( 'akb_rewards_expiry_months', 12 ) );
	}

	/**
	 * Días de anticipación del aviso. 0 = sin aviso.
	 *
	 * @return int
	 */
	public static function warn_days(): int {
		return max( 0, (int) get_option( 'akb_rewards_expiry_warn_days', 30 ) );
	}

	/**
	 * Cuelga los oyentes y la agenda.
	 *
	 * Los oyentes se registran permanentemente para evitar que acciones en cola
	 * de Action Scheduler queden huérfanas si la función se desactiva temporalmente.
	 * Con el carril inactivo, `run_daily()` retorna de inmediato y desprograma la acción.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_daily' ) );
		add_action( self::HOOK_BATCH, array( $this, 'run_batch' ), 10, 2 );
		add_action( self::HOOK_WARN_ASYNC, array( $this, 'fire_warning' ), 10, 3 );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Agenda diaria (patrón memoizado, solo admin/cron/CLI), en las DOS
	 * direcciones: con vencimiento la acción tiene que existir; sin él, no.
	 *
	 * El memo guarda el ESTADO que quedó en la cola ('yes' agendada, 'off'
	 * retirada), no sólo «ya miré»: así apagar el carril retira la acción en
	 * la siguiente petición de admin en vez de esperar a que caduque el memo,
	 * y encenderlo la vuelve a agendar igual de rápido.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		$estado = self::months() > 0 ? 'yes' : 'off';
		if ( $estado === get_transient( self::SCHEDULED_MEMO ) ) {
			return;
		}

		if ( 'off' === $estado ) {
			as_unschedule_all_actions( self::HOOK );
			set_transient( self::SCHEDULED_MEMO, 'off', 12 * HOUR_IN_SECONDS );
			return;
		}

		if ( false === as_next_scheduled_action( self::HOOK ) ) {
			$action_id = as_schedule_recurring_action( time() + 2 * HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK );
			if ( ! is_int( $action_id ) || 0 >= $action_id ) {
				return;
			}
		}
		set_transient( self::SCHEDULED_MEMO, 'yes', 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Resta meses SIN el desborde de fin de mes de PHP (31-08 − 6 meses NO es
	 * 03-03): va al día 1 del mes destino y recién ahí fija el día, recortado
	 * al largo real del mes. Monótona respecto de $from — la ventana del
	 * aviso nunca queda detrás del corte de hoy.
	 *
	 * @param \DateTimeImmutable $from   Fecha base.
	 * @param int                $months Meses a restar.
	 * @return \DateTimeImmutable
	 */
	public static function months_before( \DateTimeImmutable $from, int $months ): \DateTimeImmutable {
		$first = $from->modify( 'first day of this month' )->modify( sprintf( '-%d months', $months ) );
		$day   = min( (int) $from->format( 'j' ), (int) $first->format( 't' ) );

		return $first
			->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $day )
			->setTime( (int) $from->format( 'G' ), (int) $from->format( 'i' ), (int) $from->format( 's' ) );
	}

	/**
	 * Puntos del usuario que YA están vencidos según el corte de hoy.
	 * Función de LECTURA compartida por la corrida y por Mi cuenta.
	 *
	 * @param int    $user_id Usuario.
	 * @param string $cutoff  'Y-m-d H:i:s' del corte.
	 * @return int
	 */
	public static function expirable_for( int $user_id, string $cutoff, bool $fresh = false ): int {
		global $wpdb;

		// Los movimientos migrados de SPAR conservan su fecha original en el
		// historial, pero para vencer envejecen desde la ÉPOCA del cutover:
		// solo cuentan como «viejos» cuando la época misma quedó antes del
		// corte. Sin época sellada quedan protegidos (fallar hacia no quitar).
		$epoch = (string) get_option( 'akb_rewards_migration_epoch', '' );
		if ( '' === $epoch ) {
			$epoch = '9999-12-31 23:59:59';
		}

		$earned_old = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- el único interpolado es el nombre de nuestra tabla.
				'SELECT COALESCE(SUM(delta),0) FROM ' . Schema::ledger_table() . " WHERE user_id = %d AND delta > 0 AND ( created_at < %s AND action <> 'migration' OR action = 'migration' AND %s < %s )",
				$user_id,
				$cutoff,
				$epoch,
				$cutoff
			)
		);

		$debited = self::debited_for( $user_id );

		// $fresh: bajo candado el saldo se relee DIRECTO de la base — la caché
		// de usermeta puede traer la foto de antes de un canje concurrente.
		$balance = $fresh ? Ledger::raw_user_meta_int( $user_id, Ledger::META_BALANCE ) : Ledger::balance( $user_id );

		return max( 0, min( $balance, $earned_old - $debited ) );
	}

	/** Todos los débitos consumen antigüedad, incluido el propio vencimiento. */
	private static function debited_for( int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla interno.
				'SELECT COALESCE(SUM(-delta),0) FROM ' . Schema::ledger_table() . ' WHERE user_id = %d AND delta < 0',
				$user_id
			)
		);
	}

	/**
	 * Primera fecha con puntos aún consumibles, sin ejecutar el cron.
	 *
	 * El acumulado SQL sólo localiza el abono que sobrevive a los débitos.
	 * La cantidad se relee con expirable_for() y el primer día se busca con
	 * months_before(): sumar meses al abono no invierte su recorte mensual.
	 * MariaDB usa una sola agregación privada; no carga todo el historial ni
	 * hace consultas por cada día. Migraciones sin época quedan protegidas.
	 *
	 * @param int $user_id Usuario.
	 * @return array{date:string,points:int,pending:bool}|null
	 */
	public static function next_expiry( int $user_id ): ?array {
		global $wpdb;

		$months = self::months();
		if ( $user_id <= 0 || $months <= 0 || Ledger::balance( $user_id ) <= 0 ) {
			return null;
		}

		$epoch   = (string) get_option( 'akb_rewards_migration_epoch', '' );
		$debited = self::debited_for( $user_id );
		$earned  = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla interno, valores preparados.
				"SELECT effective_at FROM ( SELECT effective_at, SUM(points) OVER (ORDER BY effective_at) AS cumulative_points FROM ( SELECT CASE WHEN action = 'migration' THEN %s ELSE created_at END AS effective_at, SUM(delta) AS points FROM " . Schema::ledger_table() . " WHERE user_id = %d AND delta > 0 AND (action <> 'migration' OR %s <> '') GROUP BY effective_at ) AS credits ) AS fifo WHERE cumulative_points > %d ORDER BY effective_at LIMIT 1",
				$epoch,
				$user_id,
				$epoch,
				$debited
			)
		);
		if ( ! is_string( $earned ) || '' === $earned ) {
			return null;
		}

		$credit = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $earned, wp_timezone() );
		if ( false === $credit ) {
			return null;
		}

		// Días de calendario, no segundos: conserva la fecha del sitio en DST.
		$first = $credit->setTime( 0, 0 );
		$last  = $first->modify( 'first day of this month' )->modify( sprintf( '+%d months', $months + 1 ) );
		$lo    = 0;
		$hi    = (int) $first->diff( $last )->days;
		while ( $lo < $hi ) {
			$mid    = intdiv( $lo + $hi, 2 );
			$day    = $first->modify( sprintf( '+%d days', $mid ) )->setTime( 23, 59, 59 );
			$cutoff = self::months_before( $day, $months )->format( 'Y-m-d H:i:s' );
			if ( $earned < $cutoff ) {
				$hi = $mid;
			} else {
				$lo = $mid + 1;
			}
		}

		$day    = $first->modify( sprintf( '+%d days', $lo ) );
		$cutoff = self::months_before( $day->setTime( 23, 59, 59 ), $months )->format( 'Y-m-d H:i:s' );
		$points = self::expirable_for( $user_id, $cutoff );
		if ( $points <= 0 ) {
			return null;
		}

		$now = new \DateTimeImmutable( 'now', wp_timezone() );
		return array(
			'date'    => $day->format( 'Y-m-d' ),
			'points'  => $points,
			'pending' => $earned < self::months_before( $now, $months )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Lo que vencerá dentro de los próximos $days días (para el aviso y para
	 * la línea de Mi cuenta): mismo cálculo, con el corte adelantado.
	 *
	 * @param int $user_id Usuario.
	 * @param int $days    Días hacia adelante.
	 * @return int
	 */
	public static function expiring_soon( int $user_id, int $days ): int {
		$months = self::months();
		if ( $months <= 0 ) {
			return 0;
		}

		$now    = new \DateTimeImmutable( 'now', wp_timezone() );
		$cutoff = self::months_before( $now->modify( sprintf( '+%d days', $days ) ), $months )->format( 'Y-m-d H:i:s' );
		$hoy    = self::months_before( $now, $months )->format( 'Y-m-d H:i:s' );

		return self::expirable_for( $user_id, max( $hoy, $cutoff ) );
	}

	/**
	 * Corrida diaria: vence lo que corresponde y avisa lo que viene.
	 *
	 * @return void
	 */
	public function run_daily(): void {
		$months = self::months();
		if ( $months <= 0 ) {
			return;
		}

		$now    = new \DateTimeImmutable( 'now', wp_timezone() );
		$cutoff = self::months_before( $now, $months )->format( 'Y-m-d H:i:s' );

		// Dos fases a propósito: primero se RECOLECTAN todos los ids (solo
		// lectura) y recién después se procesan. Paginar con `saldo > 0`
		// mientras el propio vencimiento deja saldos en 0 encoge el conjunto
		// filtrado bajo los pies del paginador y saltaría usuarios.
		$ids   = array();
		$paged = 1;
		do {
			$users = get_users(
				array(
					'meta_key'     => Ledger::META_BALANCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_compare' => '>',
					'meta_value'   => 0, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'       => 'ids',
					'number'       => 200,
					'paged'        => $paged,
					// Orden por ID indexado para evitar filesorts sobre usuarios paginados.
					'orderby'      => 'ID',
					'count_total'  => false,
				)
			);

			foreach ( (array) $users as $user_id ) {
				$ids[] = (int) $user_id;
			}

			++$paged;
		} while ( count( (array) $users ) === 200 );

		$ids = array_values( array_unique( $ids ) );

		// Procesamiento por lotes en Action Scheduler para prevenir tiempos de espera agotados.
		if ( count( $ids ) > self::BATCH_SIZE && $this->use_batches() ) {
			foreach ( array_chunk( $ids, self::BATCH_SIZE ) as $lote ) {
				as_schedule_single_action( time(), self::HOOK_BATCH, array( $lote, $cutoff ) );
			}
			return;
		}

		$this->run_batch( $ids, $cutoff );
	}

	/**
	 * Procesa un lote de usuarios contra un corte ya calculado.
	 *
	 * @param array<int,int> $ids    Usuarios del lote.
	 * @param string         $cutoff Corte 'Y-m-d H:i:s' calculado por la corrida.
	 * @return void
	 */
	public function run_batch( $ids, $cutoff ): void {
		if ( self::months() <= 0 ) {
			return; // El interruptor pudo apagarse entre encolar y ejecutar.
		}

		$now = new \DateTimeImmutable( 'now', wp_timezone() );

		foreach ( (array) $ids as $user_id ) {
			$this->process_user( (int) $user_id, (string) $cutoff, $now );
		}
	}

	/**
	 * ¿Está disponible el troceo con Action Scheduler?
	 *
	 * @return bool
	 */
	private function use_batches(): bool {
		/**
		 * Permite forzar el modo del cron de vencimiento (los tests lo usan;
		 * en producción el default detecta Action Scheduler, que WooCommerce
		 * siempre trae).
		 *
		 * @param bool $available Si hay troceo disponible.
		 */
		return (bool) apply_filters(
			'akb_rewards_expiry_use_batches',
			function_exists( 'as_schedule_single_action' ) && function_exists( 'as_enqueue_async_action' )
		);
	}

	/**
	 * Re-dispara el hook público del aviso (el correo escucha ahí). Es el
	 * receptor de la acción asíncrona — y el camino directo sin AS.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $soon      Puntos por vencer.
	 * @param int $warn_days Ventana del aviso.
	 * @return void
	 */
	public function fire_warning( $user_id, $soon = null, $warn_days = null ): void {
		$user_id = (int) $user_id;

		// El monto y la ventana se recalculan AQUÍ, al momento de avisar. La
		// acción viaja sólo con el usuario para que el dedupe de Action
		// Scheduler funcione (ver `process_user()`), y de paso el cliente lee
		// la cifra de ahora y no la de cuando se encoló. Los dos argumentos
		// viejos se siguen aceptando: puede quedar alguna acción encolada con
		// la forma anterior cuando esto se despliegue.
		$warn_days = null === $warn_days ? self::warn_days() : (int) $warn_days;
		if ( $warn_days <= 0 ) {
			return;
		}

		$soon = null === $soon ? self::expiring_soon( $user_id, $warn_days ) : (int) $soon;
		if ( $soon <= 0 ) {
			return; // Ya no vence nada: canjeó, o el carril cambió.
		}

		/** Documented in process_user(). */
		do_action( 'akb_rewards_expiry_warning', $user_id, $soon, $warn_days );
	}

	/**
	 * Vence y/o avisa a un usuario.
	 *
	 * @param int                $user_id Usuario.
	 * @param string             $cutoff  Corte de vencimiento.
	 * @param \DateTimeImmutable $now     Ahora (tz del sitio).
	 * @return void
	 */
	private function process_user( int $user_id, string $cutoff, \DateTimeImmutable $now ): void {
		// El monto a vencer se procesa bajo el candado del usuario con saldo recalculado,
		// previniendo colisiones de concurrencia con canjes en checkout simultáneo.
		$moved = Ledger::with_user_lock(
			$user_id,
			function () use ( $user_id, $cutoff, $now ) {
				$to_expire = self::expirable_for( $user_id, $cutoff, true );

				if ( $to_expire <= 0 ) {
					return 0; // Nada vencido: sigue el aviso fuera del candado.
				}

				return Ledger::debit(
					$user_id,
					$to_expire,
					'expiry',
					'user',
					$user_id,
					sprintf( 'Vencimiento de puntos con más de %d meses', self::months() ),
					false,
					sprintf( 'expiry_%d_%s', $user_id, $now->format( 'Ymd' ) )
				);
			}
		);

		if ( 0 !== $moved ) {
			return; // Venció (o quedó para el reintento): sin aviso este ciclo.
		}

		// Aviso de piedad: si algo vence dentro de la ventana y no se ha
		// avisado este ciclo, se dispara el hook (el correo escucha ahí).
		$warn_days = self::warn_days();
		if ( $warn_days <= 0 ) {
			return;
		}

		$soon = self::expiring_soon( $user_id, $warn_days );
		if ( $soon <= 0 ) {
			return;
		}

		$marker = $now->format( 'Y-m' );
		if ( (string) get_user_meta( $user_id, self::META_WARNED, true ) === $marker ) {
			return; // Un aviso por mes, no spam.
		}
		// El marcador lo sella el envío tras confirmar el transporte seguro.

		if ( $this->use_batches() ) {
			// Envíos individuales en segundo plano para aislar la latencia del servidor de correos.
			// Se programa por usuario para recalcular el saldo pendiente al momento de la entrega.
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( self::HOOK_WARN_ASYNC, array( $user_id ) ) ) {
				return;
			}

			as_enqueue_async_action( self::HOOK_WARN_ASYNC, array( $user_id ) );
			return;
		}

		/**
		 * Puntos por vencer. El correo de aviso escucha acá.
		 *
		 * @param int $user_id   Usuario.
		 * @param int $soon      Puntos que vencerán.
		 * @param int $warn_days Ventana del aviso.
		 */
		do_action( 'akb_rewards_expiry_warning', $user_id, $soon, $warn_days );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/FirstPurchase.php
================================================================

<?php
/**
 * FirstPurchase — Validación de primera compra por identidad real de la persona.
 *
 * El bono de bienvenida y el beneficio de referido se otorgan en la primera
 * compra con al menos el monto mínimo en dinero pagado, y aplican una sola vez
 * por persona natural: si el RUT, teléfono o correo ya existen en una compra
 * pagada previa, se omite la acreditación duplicada.
 *
 * La identidad se contrasta entre cuentas y pedidos de invitado; crear otra
 * cuenta no renueva el derecho al beneficio.
 *
 * Qué cuenta como «compra anterior»: un pedido PAGADO (procesando, listo para
 * enviar o completado) creado antes que éste y desde el inicio del programa.
 * Un pedido anulado o reembolsado no cuenta —la venta no existió—, y uno
 * anterior al programa tampoco, que es lo que la página del programa promete:
 * «un pedido anterior no consume tu bono de bienvenida».
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.0
 */
final class FirstPurchase {

	/** Meta del RUT en el pedido, tal como la escribe el checkout de bloques. */
	public const META_RUT = '_billing_rut';

	/**
	 * ¿La compra paga el mínimo con DINERO?
	 *
	 * Se mide sobre la misma base con la que se acreditan los puntos: productos
	 * con IVA y tras cupones, sin despacho, menos lo reembolsado y menos lo
	 * pagado con puntos. Con el mínimo en 0 basta con que haya algo de dinero:
	 * una compra pagada entera con puntos no es una compra nueva.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	public static function meets_minimum( \WC_Order $order ): bool {
		$paid = Calculator::award_base_for_order( $order );
		$min  = Options::first_purchase_min();

		return $min > 0 ? $paid >= $min : $paid > 0;
	}

	/**
	 * ¿La misma persona ya compró antes, con este u otro nombre de cuenta?
	 *
	 * Una consulta por señal y se corta en la primera que encuentra algo. Cada
	 * una pide un solo ID: aquí sólo importa si existe.
	 *
	 * @param \WC_Order $order Pedido que se está evaluando.
	 * @return bool
	 */
	public static function has_prior_purchase( \WC_Order $order ): bool {
		$base = array(
			'type'    => 'shop_order',
			'status'  => OrderStates::PAID,
			'limit'   => 1,
			'return'  => 'ids',
			'exclude' => array( (int) $order->get_id() ),
		);

		$rango = self::date_range_before( $order );
		if ( '' !== $rango ) {
			$base['date_created'] = $rango;
		}

		$consultas = array();

		if ( $order->get_customer_id() > 0 ) {
			$consultas[] = array( 'customer_id' => (int) $order->get_customer_id() );
		}

		$senales = Options::first_purchase_checks();

		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' !== $email && in_array( 'email', $senales, true ) ) {
			$consultas[] = array( 'billing_email' => $email );
		}

		if ( in_array( 'phone', $senales, true ) ) {
			foreach ( self::phone_variants( (string) $order->get_billing_phone() ) as $telefono ) {
				$consultas[] = array( 'billing_phone' => $telefono );
			}
		}

		$ruts = in_array( 'rut', $senales, true ) ? self::rut_variants( (string) $order->get_meta( self::META_RUT ) ) : array();
		if ( array() !== $ruts ) {
			$consultas[] = array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- una fila como máximo, sólo en la primera compra de una cuenta.
					array(
						'key'     => self::META_RUT,
						'value'   => $ruts,
						'compare' => 'IN',
					),
				),
			);
		}

		$current_order_id = (int) $order->get_id();
		foreach ( $consultas as $consulta ) {
			$found_ids    = array_map( 'intval', (array) wc_get_orders( $base + $consulta ) );
			$filtered_ids = array_filter(
				$found_ids,
				static fn( int $id ): bool => $id > 0 && $id !== $current_order_id
			);
			if ( array() !== $filtered_ids ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * ¿El pedido es de la misma persona que la cuenta indicada?
	 *
	 * Lo usa el premio al padrino: invitarse a uno mismo con otra cuenta y otro
	 * correo, pero con el mismo RUT o el mismo teléfono, no es invitar a nadie.
	 *
	 * @param \WC_Order $order   Pedido del invitado.
	 * @param int       $user_id Cuenta con la que se compara (el padrino).
	 * @return bool
	 */
	public static function same_person( \WC_Order $order, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$senales = Options::first_purchase_checks();

		$email_pedido = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' !== $email_pedido && in_array( 'email', $senales, true ) ) {
			$usuario = get_user_by( 'id', $user_id );
			$correos = array(
				$usuario instanceof \WP_User ? strtolower( trim( (string) $usuario->user_email ) ) : '',
				strtolower( trim( (string) get_user_meta( $user_id, 'billing_email', true ) ) ),
			);
			if ( in_array( $email_pedido, $correos, true ) ) {
				return true;
			}
		}

		$telefono = in_array( 'phone', $senales, true ) ? self::normalize_phone( (string) $order->get_billing_phone() ) : '';
		if ( '' !== $telefono && self::normalize_phone( (string) get_user_meta( $user_id, 'billing_phone', true ) ) === $telefono ) {
			return true;
		}

		$rut = in_array( 'rut', $senales, true ) ? self::normalize_rut( (string) $order->get_meta( self::META_RUT ) ) : '';

		return '' !== $rut && self::normalize_rut( (string) get_user_meta( $user_id, 'billing_rut', true ) ) === $rut;
	}

	/**
	 * Normalización de teléfono chileno a dígitos nacionales (sin código de país +56 ni separadores).
	 *
	 * Requiere un mínimo de 8 dígitos para identificación confiable.
	 *
	 * @param string $raw Teléfono tal como vino.
	 * @return string '' si no sirve para identificar.
	 */
	public static function normalize_phone( string $raw ): string {
		if ( class_exists( '\Akibara\Business\Utilities\ChileanPhone' ) ) {
			$eight = \Akibara\Business\Utilities\ChileanPhone::extract_digits( $raw );
			if ( '' !== $eight ) {
				return '9' . $eight;
			}
		}

		$digitos = (string) preg_replace( '/\D+/', '', $raw );

		if ( strlen( $digitos ) >= 11 && 0 === strpos( $digitos, '56' ) ) {
			$digitos = substr( $digitos, 2 );
		}

		return strlen( $digitos ) >= 8 ? $digitos : '';
	}

	/**
	 * Las formas en que ese teléfono puede estar guardado en otro pedido.
	 *
	 * @param string $raw Teléfono.
	 * @return array<int,string>
	 */
	public static function phone_variants( string $raw ): array {
		$nacional = self::normalize_phone( $raw );
		if ( '' === $nacional ) {
			return array();
		}

		return array_values( array_unique( array( '+56' . $nacional, $nacional, '56' . $nacional ) ) );
	}

	/**
	 * RUT reducido a cuerpo + dígito verificador, sin puntos ni guion.
	 *
	 * @param string $raw RUT tal como vino.
	 * @return string '' si no tiene forma de RUT.
	 */
	public static function normalize_rut( string $raw ): string {
		$limpio = strtoupper( (string) preg_replace( '/[^0-9kK]/', '', $raw ) );
		if ( strlen( $limpio ) < 2 ) {
			return '';
		}

		$cuerpo = ltrim( substr( $limpio, 0, -1 ), '0' );
		if ( '' === $cuerpo || ! ctype_digit( $cuerpo ) ) {
			return '';
		}

		return $cuerpo . substr( $limpio, -1 );
	}

	/**
	 * Las formas en que ese RUT puede estar guardado. En producción siempre va
	 * con puntos y guion (`12.345.678-5`), que es lo que escribe el checkout;
	 * las otras dos cubren pedidos creados a mano o por la API.
	 *
	 * @param string $raw RUT.
	 * @return array<int,string>
	 */
	public static function rut_variants( string $raw ): array {
		$rut = self::normalize_rut( $raw );
		if ( '' === $rut ) {
			return array();
		}

		$cuerpo = substr( $rut, 0, -1 );
		$dv     = substr( $rut, -1 );
		$puntos = number_format( (float) $cuerpo, 0, '', '.' );

		$formas = array( $puntos . '-' . $dv, $cuerpo . '-' . $dv, $cuerpo . $dv );

		// Con un cero a la izquierda («012.345.678-5») el validador del checkout
		// acepta y GUARDA el cero —el módulo 11 lo ignora—, así que sin estas
		// formas esa persona cobraría un segundo bono con otra cuenta.
		// El cero va DELANTE de la forma con puntos: pasarlo por number_format
		// lo perdería (es un float) y la variante no existiría.
		$con_cero = '0' . $cuerpo;
		$formas[] = '0' . $puntos . '-' . $dv;
		$formas[] = $con_cero . '-' . $dv;
		$formas[] = $con_cero . $dv;

		if ( 'K' === $dv ) {
			$formas[] = $puntos . '-k';
			$formas[] = $cuerpo . '-k';
			$formas[] = '0' . $puntos . '-k';
			$formas[] = $con_cero . '-k';
		}

		return array_values( array_unique( $formas ) );
	}

	/**
	 * Rango de fechas de «compra anterior»: desde el inicio del programa hasta
	 * un segundo antes de este pedido.
	 *
	 * Sin fecha de creación se conserva el piso del programa: barrer toda la
	 * historia dejaría sin bono a quien compró como invitado antes de que el
	 * programa existiera, que es justo lo que la página promete que no pasa.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return string Rango en el formato de `wc_get_orders`.
	 */
	private static function date_range_before( \WC_Order $order ): string {
		$inicio = Accrual::parse_program_start( Options::program_start() );
		$desde  = $inicio instanceof \DateTimeImmutable ? $inicio->getTimestamp() : 0;

		$creado = $order->get_date_created();
		if ( ! $creado instanceof \WC_DateTime ) {
			return '>' . $desde;
		}

		return sprintf( '%d...%d', $desde, $creado->getTimestamp() - 1 );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/Ledger.php
================================================================

<?php
/**
 * Ledger — puerta ÚNICA de movimientos de puntos.
 *
 * Todo crédito y débito del programa pasa por acá: candado por usuario
 * (GET_LOCK de MySQL, reentrante en MySQL 5.7+/MariaDB 10.0.2+ — el candado
 * de pedido de Accrual puede anidar este sin abrazo mortal), re-lectura del
 * saldo BAJO candado (la caché de usermeta puede estar tibia entre dos
 * requests concurrentes del mismo cliente), fila de auditoría en
 * {prefix}akb_rewards_ledger y saldo/total-histórico en usermeta.
 *
 * Idempotencia en DOS capas: los flujos re-leen su meta bajo candado, y los
 * movimientos de un solo disparo llevan `event_key` contra el índice UNIQUE
 * de la tabla — si la fila ya existe, el movimiento se declara ya-aplicado y
 * NO se toca el saldo. Es la red final contra la doble acreditación cuando
 * una caché mintió más arriba.
 *
 * Contrato de retornos (dinero: los fallos se distinguen, no se confunden):
 *   - null  = infraestructura (candado no disponible, INSERT roto). El caller
 *     puede reintentar; JAMÁS debe leerse como «saldo insuficiente».
 *   - false = rechazo de negocio (argumentos inválidos; en débito estricto,
 *     saldo insuficiente).
 *   - int   = éxito (saldo resultante en crédito; puntos debitados en débito,
 *     0 incluido para el clamp o el duplicado).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Log;
use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Ledger {

	public const META_BALANCE      = '_akb_rewards_points';
	public const META_TOTAL_EARNED = '_akb_rewards_total_earned';

	public const ACTION_ORDER                = 'order';
	public const ACTION_WELCOME_BONUS        = 'welcome_bonus';
	public const ACTION_REDEMPTION           = 'redemption';
	public const ACTION_REDEMPTION_SHORT     = 'redemption_short';
	public const ACTION_REDEMPTION_ADJUST    = 'redemption_adjust';
	public const ACTION_REDEMPTION_RECAPTURE = 'redemption_recapture';
	public const ACTION_REDEEM_REFUND        = 'redeem_refund';
	public const ACTION_ORDER_REVERSAL       = 'order_reversal';
	public const ACTION_ORDER_REFUND         = 'order_refund';
	public const ACTION_ADMIN                = 'admin';
	public const ACTION_EXPIRY               = 'expiry';
	public const ACTION_BIRTHDAY             = 'birthday';
	public const ACTION_REFERRAL             = 'referral';
	public const ACTION_REFERRAL_REVERSAL    = 'referral_reversal';
	public const ACTION_REVIEW               = 'review';
	public const ACTION_REVIEW_PHOTO         = 'review_photo';

	private const LOCK_TIMEOUT = 3;

	/**
	 * Saldo actual del usuario (lectura normal, con caché).
	 *
	 * @param int $user_id Usuario.
	 * @return int
	 */
	public static function balance( int $user_id ): int {
		return max( 0, (int) get_user_meta( $user_id, self::META_BALANCE, true ) );
	}

	/**
	 * Total histórico ganado (nunca baja con los débitos).
	 *
	 * @param int $user_id Usuario.
	 * @return int
	 */
	public static function total_earned( int $user_id ): int {
		return max( 0, (int) get_user_meta( $user_id, self::META_TOTAL_EARNED, true ) );
	}

	/**
	 * Acredita puntos.
	 *
	 * @param int         $user_id          Usuario.
	 * @param int         $points           Puntos positivos a acreditar.
	 * @param string      $action           Acción de auditoría (order, welcome_bonus, redeem_refund, admin, migration…).
	 * @param string      $ref_type         Tipo de referencia (order, review…).
	 * @param int         $ref_id           ID de la referencia.
	 * @param string      $note             Nota corta de auditoría.
	 * @param bool        $counts_as_earned Si suma al total histórico (default sí).
	 * @param string|null $event_key        Clave única de idempotencia (movimientos de un solo disparo).
	 * @return int|false|null Saldo resultante; false argumentos; null infraestructura.
	 */
	public static function credit( int $user_id, int $points, string $action, string $ref_type = '', int $ref_id = 0, string $note = '', bool $counts_as_earned = true, ?string $event_key = null ) {
		/**
		 * Punto de ajuste o validación extensible antes de asentar un crédito.
		 * Permite a filtros de negocio ajustar o vetar el monto propuesto. Retornar <= 0 cancela el crédito.
		 *
		 * @param int    $points   Puntos propuestos.
		 * @param int    $user_id  Usuario.
		 * @param string $action   Acción del asiento.
		 * @param string $ref_type Tipo de referencia.
		 * @param int    $ref_id   ID de la referencia.
		 */
		$points = (int) apply_filters( 'akb_rewards_pre_credit_points', $points, $user_id, $action, $ref_type, $ref_id );

		if ( $user_id <= 0 || $points <= 0 ) {
			return false;
		}

		return self::with_user_lock(
			$user_id,
			static function () use ( $user_id, $points, $action, $ref_type, $ref_id, $note, $counts_as_earned, $event_key ) {
				if ( null !== $event_key && self::event_exists( $event_key ) ) {
					// Ya aplicado: éxito sin tocar nada.
					return self::balance_locked( $user_id );
				}

				$new_balance = self::apply_locked( $user_id, $points, $action, $ref_type, $ref_id, $note, $event_key );

				if ( is_int( $new_balance ) && $counts_as_earned ) {
					$total = self::total_earned_locked( $user_id ) + $points;
					update_user_meta( $user_id, self::META_TOTAL_EARNED, $total );
				}

				return $new_balance;
			}
		);
	}

	/**
	 * Debita puntos.
	 *
	 * Dos semánticas, según quién cobra:
	 *   - Reversa de pedido ($require_sufficient = false): debita hasta donde
	 *     alcanza el saldo (clamp en 0) y registra el delta REAL — el cliente
	 *     pudo haber gastado ya esos puntos y un saldo negativo no es cobrable.
	 *   - Captura de canje ($require_sufficient = true): o alcanza el saldo
	 *     completo o NO se debita nada — jamás financiar un descuento con
	 *     puntos que ya no existen (compra concurrente en otra pestaña).
	 *
	 * @param int         $user_id            Usuario.
	 * @param int         $points             Puntos positivos a debitar.
	 * @param string      $action             Acción de auditoría.
	 * @param string      $ref_type           Tipo de referencia.
	 * @param int         $ref_id             ID de la referencia.
	 * @param string      $note               Nota corta.
	 * @param bool        $require_sufficient Exigir saldo completo (captura de canje).
	 * @param string|null $event_key          Clave única de idempotencia.
	 * @return int|false|null Puntos debitados (0 con clamp o duplicado); false negocio; null infraestructura.
	 */
	public static function debit( int $user_id, int $points, string $action, string $ref_type = '', int $ref_id = 0, string $note = '', bool $require_sufficient = false, ?string $event_key = null ) {
		if ( $user_id <= 0 || $points <= 0 ) {
			return false;
		}

		return self::with_user_lock(
			$user_id,
			static function () use ( $user_id, $points, $action, $ref_type, $ref_id, $note, $require_sufficient, $event_key ) {
				if ( null !== $event_key && self::event_exists( $event_key ) ) {
					return 0; // Ya aplicado: éxito sin tocar nada.
				}

				$balance = self::balance_locked( $user_id );

				if ( $require_sufficient && $balance < $points ) {
					return false;
				}

				$to_debit = min( $points, $balance );
				if ( $to_debit <= 0 ) {
					return 0;
				}

				$new_balance = self::apply_locked( $user_id, -$to_debit, $action, $ref_type, $ref_id, $note, $event_key );

				return is_int( $new_balance ) ? $to_debit : null;
			}
		);
	}

	/**
	 * Ejecuta $fn con el candado del usuario tomado. Devuelve null si el
	 * candado no se consigue en LOCK_TIMEOUT segundos (infraestructura, no un
	 * false de negocio: la captura del canje distingue los dos).
	 *
	 * Público a propósito: los flujos con idempotencia propia (bono de
	 * bienvenida) necesitan re-leer SU meta bajo el mismo candado antes de
	 * decidir si acreditan.
	 *
	 * @param int      $user_id Usuario.
	 * @param callable $fn      Cuerpo a ejecutar bajo candado.
	 * @return mixed Null si no hubo candado; si no, lo que devuelva $fn.
	 */
	public static function with_user_lock( int $user_id, callable $fn ) {
		global $wpdb;

		$lock = 'akb_rewards_user_' . $user_id;

		$acquired = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT )
		);

		if ( '1' !== $acquired ) {
			Log::error( sprintf( 'Candado %s no disponible; movimiento rechazado.', $lock ) );
			return null;
		}

		try {
			return $fn();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Cuenta movimientos de una acción sobre una referencia. La usa Accrual
	 * para reconciliar la meta del pedido contra la VERDAD del ledger (ventana
	 * de caída entre acreditar y sellar) y para derivar claves de evento por
	 * ocurrencia («award_100_2» tras un revival del pedido).
	 *
	 * @param string $ref_type Tipo de referencia.
	 * @param int    $ref_id   ID de la referencia.
	 * @param string $action   Acción a contar.
	 * @return int
	 */
	public static function count_movements( string $ref_type, int $ref_id, string $action ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombre de tabla propio (Schema::ledger_table()); los datos van por sus placeholders.
				'SELECT COUNT(*) FROM ' . Schema::ledger_table() . ' WHERE ref_type = %s AND ref_id = %d AND action = %s',
				$ref_type,
				$ref_id,
				$action
			)
		);
	}

	/**
	 * Último CRÉDITO de una acción sobre una referencia, para avisar al dueño
	 * antes de repetir un premio manual sobre el mismo pedido: cuándo, cuánto y
	 * con qué nota. Sólo informa; no decide contabilidad (eso lo hacen
	 * `count_movements()` y `sum_movements()` con la verdad del ledger).
	 *
	 * Mira créditos y no «el último movimiento»: si después de premiar se quitó
	 * parte, el pedido igual ya fue premiado, y el aviso tiene que seguir saliendo.
	 *
	 * @param string $ref_type Tipo de referencia.
	 * @param int    $ref_id   ID de la referencia.
	 * @param string $action   Acción.
	 * @return array{delta:int,note:string,created_at:string}|null
	 */
	public static function last_credit( string $ref_type, int $ref_id, string $action ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombre de tabla propio (Schema::ledger_table()); los datos van por sus placeholders.
				'SELECT delta, note, created_at FROM ' . Schema::ledger_table() . ' WHERE ref_type = %s AND ref_id = %d AND action = %s AND delta > 0 ORDER BY id DESC LIMIT 1',
				$ref_type,
				$ref_id,
				$action
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'delta'      => (int) ( $row['delta'] ?? 0 ),
			'note'       => (string) ( $row['note'] ?? '' ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
		);
	}

	/**
	 * Suma de deltas de una referencia para un conjunto de acciones.
	 * Representa el saldo neto real de la referencia según el libro mayor.
	 *
	 * @param string            $ref_type Tipo de referencia.
	 * @param int               $ref_id   ID de la referencia.
	 * @param array<int,string> $actions  Acciones a sumar.
	 * @return int Neto firmado.
	 */
	public static function sum_movements( string $ref_type, int $ref_id, array $actions ): int {
		global $wpdb;

		if ( array() === $actions ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $actions ), '%s' ) );

		// Nombre de tabla propio (`Schema::ledger_table()`) y `{$placeholders}`
		// generado aquí arriba, uno por acción. Todos los datos viajan por sus
		// placeholders, en el array que `prepare()` recibe entero — una forma que
		// Core acepta y que el sniff cuenta mal.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(delta),0) FROM ' . Schema::ledger_table() . " WHERE ref_type = %s AND ref_id = %d AND action IN ({$placeholders})",
				array_merge( array( $ref_type, $ref_id ), $actions )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * Cuenta movimientos de una acción para un usuario y referencia específicos.
	 * Aísla registros por usuario cuando múltiples clientes comparten un mismo ref_id.
	 *
	 * @param int    $user_id  Usuario.
	 * @param string $ref_type Tipo de referencia.
	 * @param int    $ref_id   ID de la referencia.
	 * @param string $action   Acción a contar.
	 * @return int
	 */
	public static function count_movements_for_user( int $user_id, string $ref_type, int $ref_id, string $action ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombre de tabla propio (Schema::ledger_table()); los datos van por sus placeholders.
				'SELECT COUNT(*) FROM ' . Schema::ledger_table() . ' WHERE user_id = %d AND ref_type = %s AND ref_id = %d AND action = %s',
				$user_id,
				$ref_type,
				$ref_id,
				$action
			)
		);
	}

	/**
	 * Créditos vigentes de una acción para un usuario, agrupados por referencia:
	 * computa únicamente referencias cuyo saldo neto (créditos menos reversas) sea positivo.
	 *
	 * @param int    $user_id         Usuario.
	 * @param string $ref_type        Tipo de referencia agrupada.
	 * @param string $credit_action   Acción del crédito.
	 * @param string $reversal_action Acción de su reversa.
	 * @return int
	 */
	public static function count_live_credits( int $user_id, string $ref_type, string $credit_action, string $reversal_action ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombre de tabla propio (Schema::ledger_table()); los datos van por sus placeholders.
				'SELECT COUNT(*) FROM ( SELECT ref_id FROM ' . Schema::ledger_table() . ' WHERE user_id = %d AND ref_type = %s AND action IN (%s,%s) GROUP BY ref_id HAVING SUM(delta) > 0 ) AS vigentes',
				$user_id,
				$ref_type,
				$credit_action,
				$reversal_action
			)
		);
	}

	/**
	 * Premios de referido vigentes de un padrino: invitados cuyo balance neto entre
	 * premio y reversas se mantiene estrictamente positivo.
	 *
	 * @param int $referrer Padrino.
	 * @return int
	 */
	public static function count_live_referrals( int $referrer ): int {
		return self::count_live_credits( $referrer, 'user', 'referral', 'referral_reversal' );
	}

	/**
	 * Lectura PÚBLICA de cortesía: ¿esta clave ya se asentó? Solo para UX
	 * (avisar un doble envío al admin); el guardián real sigue siendo el
	 * UNIQUE de la tabla más la re-verificación bajo candado.
	 *
	 * @param string $event_key Clave de idempotencia.
	 * @return bool
	 */
	public static function event_applied( string $event_key ): bool {
		return self::event_exists( $event_key );
	}

	/**
	 * ¿Existe ya un movimiento con esta clave? SOLO bajo candado.
	 *
	 * @param string $event_key Clave de idempotencia.
	 * @return bool
	 */
	private static function event_exists( string $event_key ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombre de tabla propio (Schema::ledger_table()); los datos van por sus placeholders.
				'SELECT id FROM ' . Schema::ledger_table() . ' WHERE event_key = %s LIMIT 1',
				$event_key
			)
		);

		return null !== $found && '' !== (string) $found;
	}

	/**
	 * Inserta la fila del ledger y persiste el nuevo saldo. SOLO bajo candado.
	 *
	 * @param int         $user_id   Usuario.
	 * @param int         $delta     Delta firmado.
	 * @param string      $action    Acción.
	 * @param string      $ref_type  Tipo de referencia.
	 * @param int         $ref_id    ID de referencia.
	 * @param string      $note      Nota.
	 * @param string|null $event_key Clave única (null = movimiento repetible).
	 * @return int|null Nuevo saldo o null si el INSERT falló.
	 */
	private static function apply_locked( int $user_id, int $delta, string $action, string $ref_type, int $ref_id, string $note, ?string $event_key = null ) {
		global $wpdb;

		$new_balance = max( 0, self::balance_locked( $user_id ) + $delta );

		$row = array(
			'user_id'       => $user_id,
			'delta'         => $delta,
			'balance_after' => $new_balance,
			'action'        => substr( $action, 0, 40 ),
			'ref_type'      => substr( $ref_type, 0, 20 ),
			'ref_id'        => $ref_id,
			'note'          => substr( $note, 0, 255 ),
			'created_at'    => current_time( 'mysql' ),
		);
		$fmt = array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' );

		if ( null !== $event_key ) {
			$row['event_key'] = substr( $event_key, 0, 191 );
			$fmt[]            = '%s';
		}

		$inserted = $wpdb->insert( Schema::ledger_table(), $row, $fmt );

		if ( false === $inserted ) {
			if ( null !== $event_key && self::event_exists( $event_key ) ) {
				// Carrera de idempotencia: el registro ya fue asentado por otra conexión.
				return self::balance_locked( $user_id );
			}
			Log::error( sprintf( 'INSERT del ledger falló para user %d: %s', $user_id, (string) $wpdb->last_error ) );
			return null;
		}

		update_user_meta( $user_id, self::META_BALANCE, $new_balance );

		return $new_balance;
	}

	/**
	 * Saldo derivado del ledger: suma de todos los deltas del usuario en el libro mayor.
	 * Constituye la fuente de verdad contable contra la que se audita el balance en metadatos.
	 *
	 * @param int $user_id Usuario.
	 * @return int
	 */
	public static function balance_from_ledger( int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombre de tabla propio (Schema::ledger_table()); los datos van por sus placeholders.
				'SELECT COALESCE(SUM(delta),0) FROM ' . Schema::ledger_table() . ' WHERE user_id = %d',
				$user_id
			)
		);
	}

	/**
	 * Saldo leído DIRECTO de la base, saltándose la caché de usermeta. Solo
	 * tiene sentido bajo candado: es la re-lectura que hace segura la
	 * concurrencia de dos pedidos del mismo cliente.
	 *
	 * @param int $user_id Usuario.
	 * @return int
	 */
	private static function balance_locked( int $user_id ): int {
		return self::raw_user_meta_int( $user_id, self::META_BALANCE );
	}

	/**
	 * Total histórico leído directo de la base, bajo candado.
	 *
	 * @param int $user_id Usuario.
	 * @return int
	 */
	private static function total_earned_locked( int $user_id ): int {
		return self::raw_user_meta_int( $user_id, self::META_TOTAL_EARNED );
	}

	/**
	 * Lee un usermeta entero directo por SQL y refresca la caché para que las
	 * lecturas normales posteriores no vean un valor viejo.
	 *
	 * Público a propósito: los flujos con idempotencia propia (bono de
	 * bienvenida) re-leen su marca bajo el mismo candado.
	 *
	 * @param int    $user_id  Usuario.
	 * @param string $meta_key Meta a leer.
	 * @return int
	 */
	public static function raw_user_meta_int( int $user_id, string $meta_key ): int {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
				$user_id,
				$meta_key
			)
		);

		wp_cache_delete( $user_id, 'user_meta' );

		return max( 0, (int) $value );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/PdpNotice.php
================================================================

<?php
/**
 * PdpNotice — Aviso de puntos por compra bajo el formulario de compra en la ficha de producto.
 *
 * Ubicado de forma no intrusiva bajo el botón de adición al carrito y la línea de despacho.
 * El enlace dirige a la página pública del programa (/akibara-puntos/).
 * Para usuarios invitados, presenta de forma condicional el beneficio de bienvenida.
 *
 * La promesa utiliza el cálculo canónico de acreditación del programa sobre el precio con IVA.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class PdpNotice {

	/**
	 * Producto cuyo aviso ya salió en esta petición (null = ninguno).
	 *
	 * @var int|null
	 */
	private ?int $impreso = null;

	/**
	 * Cuelga el aviso de dos ganchos, y sale por el primero que llegue.
	 *
	 * - `woocommerce_after_add_to_cart_form` a 10: bajo el botón, después de la
	 *   línea de despacho (business, a 5) y antes de los sellos de confianza del
	 *   tema (a 15). Ese gancho sólo existe cuando WooCommerce pinta el
	 *   formulario, o sea, cuando hay algo que comprar hoy.
	 * - `woocommerce_single_product_summary` a 32: el respaldo de un tomo
	 *   agotado, que no tiene formulario.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'render' ), 10 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render' ), 32 );
	}

	/**
	 * Pinta el aviso, una sola vez por producto.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( 'yes' !== (string) get_option( 'akb_rewards_pdp_notice_enabled', 'yes' ) ) {
			return;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product() : false;
		if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() ) {
			return;
		}

		// El respaldo del resumen llega DESPUÉS del formulario: si el aviso ya
		// salió bajo el botón, no se repite.
		if ( $this->impreso === $product->get_id() ) {
			return;
		}

		$price = function_exists( 'wc_get_price_including_tax' )
			? (float) wc_get_price_including_tax( $product )
			: (float) $product->get_price();

		// Promise sólo anuncia puntos cuando el programa está vigente.
		$points = Promise::points_for( $price );
		if ( $points <= 0 ) {
			return;
		}

		// En un variable, wc_get_price_including_tax devuelve el precio
		// MÍNIMO del rango: la promesa dice «desde», nunca un número que la
		// variación cara no cumple.
		$desde = method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) ? 'desde ' : '';

		$programa_url = ProgramPage::url();

		// Expresión equivalente en pesos según la tasa de canje configurada en el panel.
		// El texto es único, conciso y uniforme entre todas las fichas (invitado y sesión
		// iniciada), evitando contaminación de caché en LiteSpeed/Cloudflare y cortes
		// ("creando tu ...") en extractos y pantallas estrechas.
		$texto = sprintf(
			'Gana <strong>%s%s Puntos Akibara = %s</strong> con este tomo.',
			$desde,
			number_format_i18n( $points ),
			Options::points_label( $points )
		);

		// El relleno vertical y white-space:nowrap proporcionan un blanco de toque accesible
		// (mínimo 24px) y previenen particiones huérfanas de línea en viewport móvil.
		$enlace = '' !== $programa_url
			? sprintf( ' <a href="%s" style="text-decoration:underline;padding:4px 0;white-space:nowrap">Ver programa</a>', esc_url( $programa_url ) )
			: '';

		$this->impreso = $product->get_id();

		// El margen de arriba es el de la línea de despacho (12 px), la vecina
		// de arriba bajo el botón. La estrella usa el rojo de texto sobre fondo
		// oscuro (5.6:1 contra #0e0e10; --aki-red daba 4.31:1, bajo el 4.5:1 AA).
		// data-nosnippet evita que buscadores indexen o trunquen el aviso de puntos
		// como descripción del producto en lugar de su sinopsis editorial.
		printf(
			'<p class="akb-rw-pdp-points" data-nosnippet style="margin:12px 0 0;font-size:14px;line-height:1.45;color:var(--aki-gray-200,#e0e0e0)"><span aria-hidden="true" style="color:var(--aki-red-text,#ff4756);margin-right:4px">★</span>%s%s</p>',
			wp_kses_post( $texto ),
			wp_kses_post( $enlace )
		);
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/ProgramPage.php
================================================================

<?php
/**
 * ProgramPage — shortcode `[akb_puntos_resumen]` para la página pública «Akibara Puntos».
 *
 * Renderiza la tabla de formas de ganar y la regla de vencimiento leyendo la configuración
 * viva para garantizar que la información pública permanezca siempre sincronizada con el panel.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class ProgramPage {

	public const SLUG = 'akibara-puntos';

	/**
	 * Memo por petición de la página pública: array{0:?\WP_Post} una vez
	 * resuelta, null mientras no se haya pedido.
	 *
	 * @var array{0:\WP_Post|null}|null
	 */
	private static ?array $memo = null;

	/**
	 * Registra el shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'akb_puntos_resumen', array( $this, 'render' ) );
	}

	/**
	 * La página pública /akibara-puntos/, resuelta UNA vez por petición.
	 *
	 * Vive acá porque esta es su clase dueña: la ficha de producto, Mi cuenta
	 * y el correo de puntos resolvían el slug por su cuenta, y Mi cuenta lo
	 * hacía además en la misma petición que la ficha.
	 *
	 * Solo considera la página cuando está publicada para evitar enlaces a páginas no accesibles.
	 *
	 * @return \WP_Post|null Null si la página no existe o no está publicada.
	 */
	public static function page(): ?\WP_Post {
		if ( null === self::$memo ) {
			$found      = get_page_by_path( self::SLUG );
			self::$memo = array( $found instanceof \WP_Post && 'publish' === $found->post_status ? $found : null );
		}

		return self::$memo[0];
	}

	/**
	 * URL de la página pública ('' si no existe).
	 *
	 * @return string
	 */
	public static function url(): string {
		$page = self::page();

		return $page instanceof \WP_Post ? (string) get_permalink( $page ) : '';
	}

	/**
	 * La frase del canje (mínimo vivo): `solo="canje"`.
	 *
	 * @return string
	 */
	public static function redeem_sentence(): string {
		$min = Options::redeem_min_points();

		// El techo del pedido se dice con los MISMOS números que aplica el
		// motor: `Redemption::techo_humano()` es la única voz de las dos
		// palancas, y si el dueño las pone en 0 la frase vuelve sola a la
		// versión sin tope.
		$limites = Redemption::techo_humano();
		$techo   = '' === $limites
			? ''
			: sprintf( ' En cada pedido puedes pagar con puntos hasta %s.', $limites );

		// El carrito mínimo es la condición requerida para habilitar la interfaz de canje.
		$carrito = Options::redeem_min_cart() > 0
			? sprintf( 'Los puntos se canjean en compras desde $%s en productos. ', number_format_i18n( Options::redeem_min_cart() ) )
			: '';

		// La duda que el cliente trae siempre, y que la página sólo dejaba
		// deducir de «se acreditan al quedar Listo para enviar»: los puntos de
		// una compra NO sirven para esa compra. Se dice sin nombrar el estado
		// que acredita, porque ése es configurable: «después de pagarla» es
		// cierto con cualquiera de los tres estados posibles.
		$mismo_pedido = ' Los puntos que ganas en una compra se acreditan después de pagarla, así que no se pueden usar en esa misma compra.'
			. ' Y lo que pagas con puntos no vuelve a darte puntos: esa parte del pedido no acumula.';

		if ( $min <= 0 ) {
			return $carrito . 'No hay un mínimo de puntos: usas desde 1 hasta el límite de tu saldo y de los productos elegibles.' . $techo . ' El despacho no se paga con puntos.' . $mismo_pedido;
		}

		return $carrito . sprintf(
			'El canje parte desde %s puntos y llega hasta el límite de tu saldo y de los productos elegibles.%s El despacho no se paga con puntos.',
			number_format_i18n( $min ),
			$techo
		) . $mismo_pedido;
	}

	/**
	 * «1 punto = $1», con el valor de canje configurado. `solo="valor_punto"`.
	 *
	 * Utilizado en la franja de garantías y elementos promocionales para asegurar
	 * equivalencia dinámica conforme a la opción configurada.
	 *
	 * @return string
	 */
	public static function point_value_sentence(): string {
		return sprintf( '1 punto = %s', Options::redeem_label() );
	}

	/**
	 * Nombre del estado que acredita, tal como lo ve el cliente en su pedido.
	 * `solo="estado_acreditacion"`.
	 *
	 * @return string
	 */
	public static function award_status_label(): string {
		$estado = Options::award_status();

		return function_exists( 'wc_get_order_status_name' )
			? (string) wc_get_order_status_name( $estado )
			: $estado;
	}

	/**
	 * Momento de acreditación de puntos en palabras. `solo="acreditacion"`.
	 *
	 * Deriva la explicación directamente a partir del estado configurado en el panel.
	 *
	 * @return string
	 */
	public static function award_sentence(): string {
		// Explicación orientada al cliente sobre el significado del estado terminal o de pago.
		$explica = array(
			'processing'        => ' Es el momento en que confirmamos tu pago.',
			'shipping-progress' => ' Es cuando ya verificamos tu pago y el pedido queda preparado, aunque todavía no salga.',
			'completed'         => ' Es cuando el pedido queda cerrado.',
		);
		$estado  = Options::award_status();

		// «O a un estado posterior» aplica para estados de pago; en otros casos
		// OrderStates::is_at_or_after() requiere el estado exacto.
		$o_posterior = in_array( $estado, OrderStates::PAID, true ) ? ', o a un estado posterior' : '';

		return sprintf(
			'Los puntos entran a tu cuenta cuando tu pedido llega a «%s»%s.%s',
			self::award_status_label(),
			$o_posterior,
			$explica[ $estado ] ?? ''
		);
	}

	/**
	 * El porcentaje de vuelta como etiqueta («2%», «2,5%»): puntos por monto,
	 * con el valor del punto en pesos. `solo="tasa"`.
	 *
	 * @return string
	 */
	public static function rate_label(): string {
		$points = Options::earn_points();
		$amount = Options::earn_amount();
		// Una fecha ilegible impide prometer la tasa. Un programa agendado puede
		// mostrarla junto a su fecha mediante `solo="desde"`.
		if ( $points <= 0 || $amount <= 0 || Accrual::PROGRAMA_ILEGIBLE === Accrual::program_state() ) {
			return '0%';
		}

		$pct = ( $points * max( 1, Options::redeem_value() ) * 100 ) / $amount;

		return number_format_i18n( $pct, ( 0.0 === fmod( $pct, 1.0 ) ) ? 0 : 1 ) . '%';
	}

	/**
	 * «2 puntos por cada $100». `solo="ratio"`.
	 *
	 * @return string
	 */
	public static function ratio_sentence(): string {
		if ( Accrual::PROGRAMA_ILEGIBLE === Accrual::program_state() ) {
			return '0 puntos'; // Ver rate_label(): hoy no acredita nadie.
		}

		return sprintf(
			'%s puntos por cada $%s',
			number_format_i18n( Options::earn_points() ),
			number_format_i18n( Options::earn_amount() )
		);
	}

	/**
	 * La fecha de inicio del programa en palabras («17 de agosto de 2026»),
	 * para la prosa. `solo="desde"`.
	 *
	 * @return string
	 */
	public static function start_label(): string {
		// La fecha guardada es hora DEL SITIO, no UTC: interpretarla con
		// strtotime() la corría un día atrás en Chile (medido: «17 de agosto»
		// salía como «16 de agosto»). Mismo criterio que el validador del
		// formulario de ajustes.
		$start = Accrual::parse_program_start( Options::program_start() );
		if ( ! $start instanceof \DateTimeImmutable ) {
			return '';
		}

		return (string) wp_date( 'j \d\e F \d\e Y', $start->getTimestamp() );
	}

	/**
	 * Tabla de ejemplos calculada desde la configuración viva.
	 * `solo="ejemplos"`. Los montos de ejemplo siguen el ticket real de la
	 * tienda y el umbral de envío gratis; los puntos y su equivalencia salen
	 * de `earn_points`/`earn_amount`/`redeem_value`.
	 *
	 * Con el programa agendado para inicio futuro, la tabla de ejemplos incorpora
	 * de forma autónoma la indicación de fecha de vigencia.
	 *
	 * @return string
	 */
	public static function examples_table(): string {
		$points = Options::earn_points();
		$amount = Options::earn_amount();
		$estado = Accrual::program_state();
		if ( $points <= 0 || $amount <= 0 || Accrual::PROGRAMA_ILEGIBLE === $estado ) {
			return '';
		}

		$value = max( 1, Options::redeem_value() );
		$html  = '<table class="akb-pts-table"><thead><tr><th scope="col">Si tu compra suma</th><th scope="col">Ganas</th><th scope="col">Que equivalen a</th></tr></thead><tbody>';
		foreach ( array( 10000, 19990, 35000, 55000 ) as $total ) {
			$earned = (int) round( ( $total / $amount ) * $points );
			$html  .= sprintf(
				'<tr><td>$%s</td><td>%s puntos</td><td>$%s</td></tr>',
				esc_html( number_format_i18n( $total ) ),
				esc_html( number_format_i18n( $earned ) ),
				esc_html( number_format_i18n( $earned * $value ) )
			);
		}
		$html .= '</tbody></table>';

		if ( Accrual::PROGRAMA_AGENDADO === $estado ) {
			$html .= sprintf( '<p class="akb-pts-note">Esta tabla rige desde el %s.</p>', esc_html( self::start_label() ) );
		}

		return $html;
	}

	public static function expiry_sentence(): string {
		$months = Expiry::months();
		if ( $months <= 0 ) {
			return 'Tus puntos no vencen: tu saldo se queda ahí hasta que decidas usarlo.';
		}

		$aviso = Expiry::warn_days() > 0
			? sprintf( ' Te avisamos por correo %d días antes de que algo venza.', Expiry::warn_days() )
			: '';

		return sprintf(
			'Tus puntos duran %d meses desde que los ganas; se usan primero los más antiguos.%s',
			$months,
			$aviso
		);
	}

	/**
	 * Tabla de formas de ganar + regla de vencimiento, desde la config viva.
	 *
	 * @param array<string,string>|string $atts Atributos del shortcode.
	 * @return string
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts( array( 'solo' => '' ), is_array( $atts ) ? $atts : array() );
		if ( 'vencimiento' === $atts['solo'] ) {
			return esc_html( self::expiry_sentence() );
		}
		if ( 'canje' === $atts['solo'] ) {
			return esc_html( self::redeem_sentence() );
		}
		// Fragmentos dinámicos para incrustar en el contenido editorial de la página,
		// garantizando que las cifras se actualicen automáticamente ante cambios de configuración.
		if ( 'valor_punto' === $atts['solo'] ) {
			return esc_html( self::point_value_sentence() );
		}
		if ( 'acreditacion' === $atts['solo'] ) {
			return esc_html( self::award_sentence() );
		}
		if ( 'estado_acreditacion' === $atts['solo'] ) {
			return esc_html( self::award_status_label() );
		}
		if ( 'tasa' === $atts['solo'] ) {
			return esc_html( self::rate_label() );
		}
		if ( 'ratio' === $atts['solo'] ) {
			return esc_html( self::ratio_sentence() );
		}
		if ( 'bienvenida' === $atts['solo'] ) {
			// Con la fecha ilegible no acredita nadie, y el bono cuelga de esa
			// misma acreditación: la cifra suelta sería una promesa sin dueño.
			return esc_html( number_format_i18n( Accrual::PROGRAMA_ILEGIBLE === Accrual::program_state() ? 0 : Options::welcome_bonus() ) );
		}
		// Las dos cifras que gobiernan las reglas nuevas, para que la prosa de
		// la página no las escriba a mano y caduque al primer cambio de panel.
		if ( 'minimo_primera_compra' === $atts['solo'] ) {
			return esc_html( Options::first_purchase_min() > 0 ? '$' . number_format_i18n( Options::first_purchase_min() ) : 'cualquier monto' );
		}
		if ( 'minimo_canje' === $atts['solo'] ) {
			return esc_html( Options::redeem_min_cart() > 0 ? '$' . number_format_i18n( Options::redeem_min_cart() ) : 'cualquier monto' );
		}
		if ( 'desde' === $atts['solo'] ) {
			return esc_html( self::start_label() );
		}
		if ( 'ejemplos' === $atts['solo'] ) {
			return self::examples_table();
		}
		$filas = array();

		// Una fecha futura se anuncia; una fecha ilegible omite la promesa.
		$estado            = Accrual::program_state();
		$se_puede_prometer = Accrual::PROGRAMA_ILEGIBLE !== $estado;
		$desde_cuando      = Accrual::PROGRAMA_AGENDADO === $estado ? ', desde el ' . self::start_label() : '';
		if ( Options::earn_points() > 0 && Options::earn_amount() > 0 && $se_puede_prometer ) {
			$regla = sprintf(
				'%s puntos por cada $%s de productos',
				number_format_i18n( Options::earn_points() ),
				number_format_i18n( Options::earn_amount() )
			);
			$filas[] = array( 'Cada compra elegible', $regla . $desde_cuando );
		}

		// Bono y referido comparten la vigencia de la compra: se anuncia la fecha
		// futura y se omite la promesa si la fecha guardada no se puede interpretar.
		if ( Options::welcome_bonus() > 0 && $se_puede_prometer ) {
			$filas[] = array(
				Options::first_purchase_min() > 0
					? sprintf( 'Tu primera compra con cuenta, desde $%s en productos', number_format_i18n( Options::first_purchase_min() ) )
					: 'Tu primera compra con cuenta',
				sprintf(
					'+%s puntos ($%s) de bienvenida (uno por persona)',
					number_format_i18n( Options::welcome_bonus() ),
					number_format_i18n( Options::welcome_bonus() * max( 1, Options::redeem_value() ) )
				) . $desde_cuando,
			);
		}

		if ( Options::reviews_lane_on() ) {
			$every  = max( 1, Options::review_every_n() );
			$premio = $every > 1
				? sprintf( '%s puntos cada %d reseñas', number_format_i18n( Options::review_points() ), $every )
				: sprintf(
					'%s puntos por reseña ($%s)',
					number_format_i18n( Options::review_points() ),
					number_format_i18n( Options::review_points() * max( 1, Options::redeem_value() ) )
				);

			// El tope por cliente estaba sólo en el panel: quien escribía su
			// sexta reseña se enteraba cuando no le llegaron los puntos.
			$tope_resenas = Options::review_max_per_user();
			if ( $tope_resenas > 0 ) {
				$premio .= sprintf(
					' (hasta %s premiadas = $%s)',
					number_format_i18n( $tope_resenas ),
					number_format_i18n( $tope_resenas * Options::review_points() * max( 1, Options::redeem_value() ) )
				);
			}

			$filas[] = array( 'Reseña con foto elegible', $premio );
		}

		if ( Options::referral_points() > 0 && $se_puede_prometer ) {
			// Los dos límites que el cliente sólo descubría al toparse con ellos:
			// cuántos invitados le pagan y cuánto dura la memoria del enlace.
			$detalles = array();
			if ( Options::referral_max_per_user() > 0 ) {
				$detalles[] = sprintf( 'hasta %s invitados', number_format_i18n( Options::referral_max_per_user() ) );
			}
			if ( Options::referral_cookie_days() > 0 ) {
				$detalles[] = sprintf( 'el enlace recuerda %s días', number_format_i18n( Options::referral_cookie_days() ) );
			}

			$filas[] = array(
				'Invitar a un amigo: cuando recibe su primera compra',
				sprintf(
					'%s puntos por invitado ($%s)',
					number_format_i18n( Options::referral_points() ),
					number_format_i18n( Options::referral_points() * max( 1, Options::redeem_value() ) )
				)
					. ( array() === $detalles ? '' : ' (' . implode( '; ', $detalles ) . ')' )
					. $desde_cuando,
			);
		}

		if ( Options::birthday_points() > 0 ) {
			$filas[] = array(
				'Tu cumpleaños',
				sprintf(
					'%s puntos cada año ($%s)',
					number_format_i18n( Options::birthday_points() ),
					number_format_i18n( Options::birthday_points() * max( 1, Options::redeem_value() ) )
				),
			);
		}

		$html = '<div class="akb-pts-tablewrap"><table class="akb-pts-table">'
			. '<thead><tr><th scope="col">Cómo</th><th scope="col">Ganas</th></tr></thead><tbody>';
		foreach ( $filas as $fila ) {
			$html .= sprintf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $fila[0] ), esc_html( $fila[1] ) );
		}
		$html .= '</tbody></table></div>';

		if ( Options::reviews_lane_on() && ! empty( $atts['con_resenas_acordeon'] ) ) {
			$html .= '<details><summary>Condiciones de los puntos por reseñas</summary><p>'
				. esc_html( 'En el formulario de invitación puedes elegir si solicitas puntos y adjuntar una foto. También puedes opinar sin solicitarlos. ' . \Akibara\Rewards\Reviews\RewardMessage::text( false ) )
				. '</p></details>';
		}

		$html .= sprintf( '<p class="akb-pts-note">%s</p>', esc_html( self::expiry_sentence() ) );

		return $html;
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/Promise.php
================================================================

<?php
/**
 * Lo que la tienda puede PROMETER hoy sobre acumular puntos.
 *
 * La acreditación posee una compuerta de vigencia (`Accrual::in_program()`):
 * si la fecha de inicio del programa está en el futuro o no es válida, no se
 * acreditan puntos.
 *
 * Esta clase es la puerta unificada por la que cualquier superficie pública consulta
 * qué puntos pueden prometerse actualmente, garantizando que nunca se prometan
 * puntos que el motor de acreditación rechazaría.
 *
 * Esta clase es la ÚNICA puerta por la que una superficie pregunta «¿cuánto
 * prometo?». La regla de vigencia sigue viviendo en `Accrual`; aquí sólo se
 * decide que, fuera de vigencia, la respuesta es cero.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

final class Promise {

	/**
	 * Puntos que se pueden prometer HOY por una base en pesos.
	 *
	 * @param float $base Base en pesos (IVA incluido).
	 * @return int 0 si el programa no rige ahora o el carril está apagado.
	 */
	public static function points_for( float $base ): int {
		return Accrual::program_open_now() ? Calculator::points_for( $base ) : 0;
	}

	/**
	 * El tramo que se puede anunciar HOY («2 puntos por cada $100»).
	 *
	 * @return array{points:int,amount:int} Ceros si no hay nada que prometer.
	 */
	public static function rate(): array {
		if ( ! Accrual::program_open_now() ) {
			return array(
				'points' => 0,
				'amount' => 0,
			);
		}

		return array(
			'points' => Options::earn_points(),
			'amount' => Options::earn_amount(),
		);
	}

	/**
	 * El bono de bienvenida que se puede anunciar HOY.
	 *
	 * El bono de bienvenida se acredita tras la primera compra elegible.
	 * Si el programa no se encuentra activo o vigente, retorna 0 para no
	 * prometer beneficios no aplicables.
	 *
	 * @return int 0 si el programa no rige ahora o el carril está apagado.
	 */
	public static function welcome_bonus(): int {
		return Accrual::program_open_now() ? Options::welcome_bonus() : 0;
	}

	/**
	 * El premio al padrino que se puede anunciar HOY.
	 *
	 * Misma compuerta: `Referrals::reason_not_to_pay()` rechaza el pedido del
	 * invitado que no está `in_program()`.
	 *
	 * @return int 0 si el programa no rige ahora o el carril está apagado.
	 */
	public static function referral_points(): int {
		return Accrual::program_open_now() ? Options::referral_points() : 0;
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/Redemption.php
================================================================

<?php
/**
 * Redemption — Canje de puntos en carrito y checkout.
 *
 * Reglas operativas y contratos contables:
 *   - 1 punto = $1 CLP y el descuento aplicado es exactamente el valor facial
 *     IVA incluido. WooCommerce reparte impuesto sobre los fees negativos
 *     aunque se declaren no imponibles, por lo que el fee se calcula en valor neto.
 *   - La captura cobra lo que el fee del pedido concedió, bajo candado de
 *     pedido, metadatos actualizados y event_key UNIQUE en el ledger. Si el candado
 *     está ocupado, se reintenta vía cola asíncrona.
 *   - Contabilidad de reposición en dos cuentas:
 *       RESTORED: acumulado devuelto al cliente.
 *       REVIVAL_DEBT: porción devuelta por anulación de pedido, re-cobrable al revivir.
 *     La reposición proporcional de un reembolso parcial es definitiva.
 *   - Anulación (cancelled/refunded/failed/trash) repone el saldo pendiente.
 *     (redeemed − RESTORED); el revival re-cobra la deuda y DESCUENTA de
 *     RESTORED solo lo COBRADO — un re-cobro clampado en 0 no re-arma la
 *     reposición (el bucle failed→pending ya no acuña puntos).
 *   - La proporción del reembolso parcial se mide sobre los ÍTEMS: un
 *     reembolso solo de envío no devuelve canje.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Log;
use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Redemption {

	public const SESSION_KEY          = 'akb_rewards_redemption';
	public const FEE_NAME             = 'Puntos Akibara';
	public const META_REDEEMED_POINTS = '_akb_rewards_redeemed_points';
	public const META_REDEEMED_VALUE  = '_akb_rewards_redeemed_value';
	public const META_RESTORED_POINTS = '_akb_rewards_redeemed_restored';
	public const META_REVIVAL_DEBT    = '_akb_rewards_redeemed_revival_debt';
	public const META_UNSETTLED       = '_akb_rewards_redeemed_unsettled';

	/**
	 * Devolución de puntos que se le DEBE al cliente y todavía no se pudo asentar.
	 *
	 * JSON `{points, key}`: cuántos, y la clave de idempotencia con la que se
	 * intentó. Existe porque el ajuste a la baja de un canje reutilizado
	 * (`reconcile_reused()`), cuando el ledger no respondía, dejaba una nota «se
	 * reintenta solo» y agendaba un reintento que NO podía rehacer la devolución:
	/**
	 * Registra puntos pendientes de reintegro en el ledger para conciliación posterior.
	 */
	public const META_PENDING_GIVEBACK = '_akb_rewards_redeemed_pending_giveback';

	/**
	 * Pedidos impagos de la misma compra que este pedido reemplaza y que aún
	 * retenían canje al crearlo. Vive en el pedido, no en memoria: si la caja
	 * falla después de crear el pedido nuevo, el siguiente intento reutiliza
	 * ese borrador sin volver a avisar que se creó.
	 */
	public const META_SUPERSEDES = '_akb_rewards_supersedes';

	/**
	 * Marca de rechazo de pago por la pasarela para pedidos en estado pendiente.
	 * Permite reasignar los puntos en un nuevo intento del cliente sin duplicar cobros.
	 */
	public const META_PAYMENT_REJECTED = '_akb_rewards_payment_rejected';

	/**
	 * Hasta cuándo un pedido fallido re-cobrado en «pagar pedido» puede seguir
	 * en la pasarela. Mientras tanto, sólo la revisión agendada para eso
	 * devuelve sus puntos: un reintento de 5 minutos que ya estaba en la cola
	 * los soltaba con el cliente pagando.
	 */
	public const META_PAY_WINDOW = '_akb_rewards_pay_window_until';

	/**
	 * La intención de canje (su `set_at`) que la caja horneó en este pedido.
	 * Una intención con otro `set_at` se fijó después y es para la compra
	 * siguiente.
	 */
	public const META_INTENT_SET_AT = '_akb_rewards_intent_set_at';

	/** Acción de la cola que reintenta una operación de canje: (pedido). */
	public const HOOK_RETRY = 'akb_rewards_redemption_retry';

	/** Reintentos ya agendados para el pedido. */
	public const META_RETRIES = '_akb_rewards_redemption_retries';

	private const MAX_RETRIES = 5;

	/** Motivo de la revisión que espera la ventana de pago de un pedido impago. */
	private const RETRY_IMPAGO = 'impago';

	/**
	 * Errores de la Store API que significan «la pasarela no cobró»: el
	 * rechazo con mensaje, la excepción de la pasarela y un resultado inválido.
	 * Los demás (dirección, stock, el propio saldo) ocurren antes de pagar.
	 */
	private const PAYMENT_ERROR_CODES = array(
		'woocommerce_rest_payment_error',
		'woocommerce_rest_checkout_process_payment_error',
		'woocommerce_rest_checkout_invalid_payment_result',
	);

	/** Resultados de un cobro de canje hecho donde todavía se puede detener el pago. */
	private const COBRO_OK        = 'ok';
	private const COBRO_SIN_SALDO = 'sin_saldo';
	private const COBRO_OCUPADO   = 'ocupado';

	/**
	 * Mientras corre un reintento: Action Scheduler cuenta la acción EN CURSO
	 * como agendada, así que desde dentro «¿ya hay uno en la cola?» siempre
	 * diría que sí y el siguiente no se agendaría nunca.
	 */
	private static bool $in_retry = false;

	/** Si la corrida de reintento en curso volvió a fallar (y ya agendó o agotó). */
	private static bool $retry_failed = false;

	/**
	 * Pedidos que salieron del borrador de la caja de bloques en ESTA petición.
	 *
	 * @var array<int,bool>
	 */
	private static array $left_draft = array();

	/**
	 * Pedidos cuya marca de rechazo borró ESTA petición para pagarlos y cuyo
	 * desenlace todavía no se conoce (ver await_outcome()).
	 *
	 * @var array<int,bool>
	 */
	private static array $awaiting_outcome = array();

	/** @var bool Si el cierre de la petición ya vigila esos pedidos. */
	private static bool $shutdown_hooked = false;

	/** Lo que lee quien compra cuando la caja se detiene. */
	private const MSG_CAJA = 'Tu saldo de Puntos Akibara cambió mientras comprabas y ya no alcanza para el descuento que elegiste. Revisa el total y vuelve a confirmar tu compra.';

	/** Lo que lee quien intenta pagar un pedido ya creado que el saldo no cubre. */
	private const MSG_PEDIDO = 'Tu saldo de Puntos Akibara ya no cubre el descuento de este pedido, así que no se puede pagar. Vuelve a armar tu compra desde el carrito.';

	/** Lo que lee quien compra cuando otra operación tenía tomados sus puntos. */
	private const MSG_OCUPADO = 'No pudimos confirmar tus Puntos Akibara en este momento. Espera unos segundos y vuelve a confirmar tu compra.';

	private const LOCK_TIMEOUT = 3;

	/**
	 * Cuelga los hooks.
	 *
	 * Prioridad 20 en el cálculo de fees: después de cualquier fee de
	 * terceros y antes de que WC_Cart_Totals lea la lista. La reposición va a
	 * prioridad 10, ANTES que el recálculo de Accrual (20): la base puntuable
	 * resta el canje NETO y necesita RESTORED al día.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_fee' ), 20 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_created', array( $this, 'remember_superseded' ), 10, 1 );
		// La caja clásica comparte `available_points()`, registrando pedidos reemplazados.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'remember_superseded' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture_from_order' ), 20, 1 );
		add_action( 'woocommerce_cart_emptied', array( $this, 'clear_intent' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 2 );
		add_action( self::HOOK_RETRY, array( $this, 'run_retry' ), 10, 2 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'guard_order_pay' ), 10, 1 );
		add_action( 'woocommerce_after_pay_action', array( $this, 'after_order_pay' ), 10, 1 );
		add_filter( 'rest_post_dispatch', array( $this, 'watch_checkout_response' ), 10, 3 );
		add_filter( 'woocommerce_payment_successful_result', array( $this, 'forget_rejection_on_success' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'disallow_coupon_with_points' ), 10, 2 );
		add_filter( 'woocommerce_shipping_free_shipping_is_available', array( $this, 'filter_free_shipping_availability' ), 20, 3 );
	}

	/**
	 * Fija la intención de canje en sesión. 0 la borra.
	 *
	 * @param int $points Puntos a canjear.
	 * @return void
	 */
	public static function set_intent( int $points ): void {
		$session = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		if ( null === $session ) {
			return;
		}

		if ( $points <= 0 ) {
			$session->set( self::SESSION_KEY, null );
			return;
		}

		$session->set(
			self::SESSION_KEY,
			array(
				'points' => $points,
				'set_at' => time(),
			)
		);
	}

	/**
	 * Cuándo se fijó la intención vigente (0 si no hay, o si viene de una
	 * sesión anterior a que se anotara).
	 *
	 * @return int
	 */
	private static function intent_set_at(): int {
		$session = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		$data    = null === $session ? null : $session->get( self::SESSION_KEY );

		return is_array( $data ) ? max( 0, (int) ( $data['set_at'] ?? 0 ) ) : 0;
	}

	/**
	 * Puntos de la intención vigente en sesión (sin validar contra saldo).
	 *
	 * @return int
	 */
	public static function intent_points(): int {
		$session = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		if ( null === $session ) {
			return 0;
		}

		$data = $session->get( self::SESSION_KEY );
		if ( ! is_array( $data ) || empty( $data['points'] ) ) {
			return 0;
		}

		return max( 0, (int) $data['points'] );
	}

	/**
	 * Borra la intención (carrito vaciado, canje retirado).
	 *
	 * @return void
	 */
	public function clear_intent(): void {
		self::set_intent( 0 );
	}

	/**
	 * Puntos disponibles para esta compra: saldo actual más los retenidos
	 * en pedidos de la misma sesión cuyo pago fue rechazado.
	 *
	 * Permite reutilizar el canje en un reintento de compra sin duplicar deducciones.
	 *
	 * @param int $user_id Cliente.
	 * @return int
	 */
	public static function available_points( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		return max( 0, Ledger::balance( $user_id ) ) + self::releasable_for_session( $user_id );
	}

	/**
	 * Canje cobrado y no devuelto en TODOS los pedidos pendientes del cliente,
	 * estén o no en la compra en curso. Es lo que Mi cuenta muestra como
	 * «apartado»: un pedido pagado con otra compra sale de la sesión y sus
	 * puntos desaparecían de la vista hasta que WooCommerce lo anulaba.
	 *
	 * @param int $user_id Cliente.
	 * @return int
	 */
	public static function held_in_unpaid_orders( int $user_id ): int {
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$pedidos = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'pending' ),
				'limit'       => 50,
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- acotada por cliente y estado; la meta sólo existe en pedidos con canje.
					array(
						'key'     => self::META_REDEEMED_POINTS,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$retenidos = 0;
		foreach ( (array) $pedidos as $pedido ) {
			if ( $pedido instanceof \WC_Order && (int) $pedido->get_customer_id() === $user_id ) {
				$retenidos += self::held_by( $pedido );
			}
		}

		return $retenidos;
	}

	/**
	 * Minutos que WooCommerce espera a un pedido impago antes de anularlo; 0 si
	 * no lo anula nunca (sin gestión de inventario o sin «Mantener en
	 * inventario»). Es la promesa de «vuelven solos a tu saldo».
	 *
	 * @return int
	 */
	public static function unpaid_cancel_minutes(): int {
		if ( 'yes' !== (string) get_option( 'woocommerce_manage_stock', 'yes' ) ) {
			return 0;
		}

		return max( 0, (int) get_option( 'woocommerce_hold_stock_minutes', 60 ) );
	}

	/**
	 * El pedido que WooCommerce tiene anotado para la compra en curso, si es de
	 * este cliente.
	 *
	 * @param int $user_id Cliente.
	 * @return \WC_Order|null
	 */
	private static function session_order( int $user_id ): ?\WC_Order {
		$session = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		if ( null === $session || $user_id <= 0 ) {
			return null;
		}

		// El mismo orden que WooCommerce al revisar el stock reservado.
		$order_id = absint( $session->get( 'order_awaiting_payment' ) );
		if ( $order_id <= 0 ) {
			$order_id = absint( $session->get( 'store_api_draft_order' ) );
		}
		if ( $order_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof \WC_Order && (int) $order->get_customer_id() === $user_id ? $order : null;
	}

	/**
	 * El borrador que la caja de bloques anotó en la sesión, si es de este
	 * cliente.
	 *
	 * @param int $user_id Cliente.
	 * @return \WC_Order|null
	 */
	private static function draft_order_of_session( int $user_id ): ?\WC_Order {
		$session  = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		$order_id = null === $session || $user_id <= 0 ? 0 : absint( $session->get( 'store_api_draft_order' ) );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

		return $order instanceof \WC_Order && (int) $order->get_customer_id() === $user_id ? $order : null;
	}

	/**
	 * Canje que la compra en curso puede volver a usar: el del pedido de la
	 * sesión y el de los que reemplazó, si su pago fue rechazado. Un pedido
	 * fallido ya los devolvió al saldo.
	 *
	 * @param int $user_id Cliente.
	 * @return int
	 */
	private static function releasable_for_session( int $user_id ): int {
		$order = self::session_order( $user_id );
		if ( null === $order ) {
			return 0;
		}

		$held = self::releasable( $order );
		foreach ( self::superseded_orders( $order ) as $anterior ) {
			$held += self::releasable( $anterior );
		}

		return $held;
	}

	/**
	 * Lo retenido por un pedido que otra compra puede usar: sólo si su último
	 * intento de pago fue rechazado.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return int
	 */
	private static function releasable( \WC_Order $order ): int {
		return (int) $order->get_meta( self::META_PAYMENT_REJECTED ) > 0 ? self::held_by( $order ) : 0;
	}

	/**
	 * Canje cobrado y no devuelto de un pedido que sigue esperando pago.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return int
	 */
	private static function held_by( \WC_Order $order ): int {
		if ( ! $order->has_status( 'pending' ) || ! $order->needs_payment() ) {
			return 0;
		}

		return max( 0, (int) $order->get_meta( self::META_REDEEMED_POINTS ) - (int) $order->get_meta( self::META_RESTORED_POINTS ) );
	}

	/**
	 * Los pedidos impagos de la misma compra que este pedido reemplaza, del
	 * mismo cliente, en el orden en que se crearon.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<int,\WC_Order>
	 */
	private static function superseded_orders( \WC_Order $order ): array {
		$ids = $order->get_meta( self::META_SUPERSEDES );
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$pedidos = array();
		foreach ( $ids as $id ) {
			$anterior = (int) $id > 0 && (int) $id !== (int) $order->get_id() ? wc_get_order( (int) $id ) : false;
			if ( $anterior instanceof \WC_Order && (int) $anterior->get_customer_id() === (int) $order->get_customer_id() ) {
				$pedidos[] = $anterior;
			}
		}

		return $pedidos;
	}

	/**
	 * La caja de bloques creó un pedido nuevo: se anota a qué pedido impago de
	 * la misma compra reemplaza, porque WooCommerce pisa la sesión enseguida.
	 *
	 * @param \WC_Order $order Pedido recién creado.
	 * @return void
	 */
	public function remember_superseded( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$anterior = self::session_order( (int) $order->get_customer_id() );
		if ( null === $anterior || $anterior->get_id() === $order->get_id() ) {
			return;
		}

		$vigentes = array();
		foreach ( array_merge( self::superseded_orders( $anterior ), array( $anterior ) ) as $pedido ) {
			if ( self::releasable( $pedido ) > 0 ) {
				$vigentes[] = (int) $pedido->get_id();
			}
		}

		if ( array() !== $vigentes ) {
			$order->update_meta_data( self::META_SUPERSEDES, array_values( array_unique( $vigentes ) ) );
			$order->save_meta_data();
		}
	}

	/**
	 * Si el pedido de la compra en curso ya se pagó, la intención de canje se
	 * gastó en él.
	 *
	 * Con una pasarela que redirige, quien paga y cierra la pestaña no pasa por
	 * «pedido recibido» y WooCommerce no vacía el carrito: sin esto, la compra
	 * siguiente heredaba el descuento sin que el cliente lo pidiera.
	 *
	 * @return void
	 */
	private static function forget_spent_intent(): void {
		if ( self::intent_points() <= 0 ) {
			return;
		}

		$order = self::session_order( get_current_user_id() );
		if (
			null === $order
			|| (int) $order->get_meta( self::META_REDEEMED_POINTS ) <= 0
			|| ! OrderStates::is_alive( (string) $order->get_status() )
			|| $order->needs_payment()
		) {
			return;
		}

		// Verifica si la intención almacenada corresponde al pedido procesado.
		$horneada = (int) $order->get_meta( self::META_INTENT_SET_AT );
		if ( $horneada > 0 && self::intent_set_at() !== $horneada ) {
			return;
		}

		self::set_intent( 0 );
	}

	/**
	 * Anota en el pedido qué intención de canje se horneó en su descuento.
	 *
	 * @param \WC_Order $order Pedido que sale por la caja.
	 * @return void
	 */
	private static function remember_intent( \WC_Order $order ): void {
		$set_at = self::intent_set_at();
		if ( $set_at <= 0 || self::granted_gross( $order ) <= 0 || (int) $order->get_meta( self::META_INTENT_SET_AT ) === $set_at ) {
			return;
		}

		$order->update_meta_data( self::META_INTENT_SET_AT, $set_at );
		$order->save_meta_data();
	}

	/**
	 * Puntos EFECTIVOS a canjear para un carrito: intención topada por el
	 * saldo real y por el total de las líneas (jamás descontar más que el
	 * carrito). Función de lectura, sin efectos.
	 *
	 * @param \WC_Cart $cart Carrito calculado.
	 * @return int
	 */
	public static function effective_points( \WC_Cart $cart ): int {
		$intent = self::intent_points();
		if ( $intent <= 0 ) {
			return 0;
		}
		$limits = self::redemption_limits( $cart, get_current_user_id() );
		return self::effective_points_for_limits( $intent, $limits );
	}

	/**
	 * Máximo aplicable al carrito actual, sin depender de una intención ni
	 * modificar sesión, pedidos o saldo. Mi cuenta y el motor usan esta misma
	 * base tras cupones, con IVA, sin despacho ni el fee del propio canje.
	 *
	 * Un máximo inferior al mínimo de puntos no es un canje válido.
	 *
	 * @param \WC_Cart|null $cart    Carrito calculado, o null si no existe.
	 * @param int           $user_id Dueño del saldo y de la reserva reutilizable.
	 * @return array{cart_present:bool,base:float,missing:int,available:int,min_points:int,max_points:int,value:int}
	 */
	public static function redemption_limits( ?\WC_Cart $cart, int $user_id ): array {
		$present           = null !== $cart && array() !== $cart->get_cart();
		$base              = $present ? Calculator::award_base_for_cart( $cart ) : 0.0;
		$has_coupons       = $present && $cart instanceof \WC_Cart && method_exists( $cart, 'get_applied_coupons' ) && count( $cart->get_applied_coupons() ) > 0;
		$missing           = self::missing_for_minimum( $base );
		$available         = $user_id > 0 ? self::available_points( $user_id ) : 0;
		$minimum           = max( 1, Options::redeem_min_points() );
		$value             = Options::redeem_value();

		// Tramos oficiales de canje escalonado sobre productos elegibles (sin envío):
		// - Subtotal < $25.000 CLP: Canje bloqueado (0 puntos).
		// - Tramo 1 ($25.000 a $49.999 CLP): Tope de 2.000 puntos ($2.000 CLP), sin superar 50%.
		// - Tramo 2 (desde $50.000 CLP): Tope según Options::redeem_max_amount() ($5.000 CLP), sin superar 50%.
		$tier2_min = function_exists( 'get_option' ) ? (float) get_option( 'akb_rewards_redeem_tier2_min_cart', 50000 ) : 50000.0;
		$tier1_cap = function_exists( 'get_option' ) ? (int) get_option( 'akb_rewards_redeem_tier1_max_amount', 2000 ) : 2000;
		$tier2_cap = function_exists( 'get_option' ) ? (int) get_option( 'akb_rewards_redeem_max_amount', Options::DEFAULT_REDEEM_MAX_AMOUNT ) : Options::DEFAULT_REDEEM_MAX_AMOUNT;

		$gross_max = (int) floor( self::redeemable_gross( $base ) / $value );
		$tramo_cap = Options::redeem_max_amount() > 0
			? ( ( $base < $tier2_min && Options::redeem_min_cart() < $tier2_min ) ? $tier1_cap : $tier2_cap )
			: $gross_max;
		$capped    = min( $gross_max, $tramo_cap );

		// El umbral de Envío Gratis ($60.000 CLP por defecto) se evalúa dinámicamente
		// consultando la opción oficial de Akibara Business ('akibara_fsp_carrier_threshold').
		// Los Puntos Akibara son un fee, no un cupón, por lo que canjear puntos en un
		// carrito >= umbral conserva el Envío Gratis sin recortar el tramo de compra.
		$free_shipping_threshold = function_exists( 'get_option' )
			? (float) get_option( 'akibara_fsp_carrier_threshold', 60000.0 )
			: 60000.0;
		if ( $free_shipping_threshold <= 0.0 ) {
			$free_shipping_threshold = 60000.0;
		}
		if ( class_exists( '\Akibara\Business\Modules\ShippingCopy\ShippingCopy' ) ) {
			$dyn_threshold = (float) \Akibara\Business\Modules\ShippingCopy\ShippingCopy::threshold();
			if ( $dyn_threshold > 0.0 ) {
				$free_shipping_threshold = $dyn_threshold;
			}
		}

		$maximum = $present && 0 === $missing && ! $has_coupons
			? max( 0, min( $available, $capped ) )
			: 0;

		return array(
			'cart_present'              => $present,
			'base'                      => $base,
			'missing'                   => $missing,
			'available'                 => $available,
			'min_points'                => $minimum,
			'max_points'                => $maximum >= $minimum ? $maximum : 0,
			'value'                     => $value,
			'has_coupons'               => $has_coupons,
			'free_shipping_threshold'   => $free_shipping_threshold,
		);
	}

	/**
	 * Aplica los límites ya calculados sin otra lectura del carrito o saldo.
	 *
	 * @param int   $intent Puntos confirmados anteriormente.
	 * @param array $limits Resultado de redemption_limits().
	 * @return int
	 */
	public static function effective_points_for_limits( int $intent, array $limits ): int {
		$points = max( 0, min( $intent, $limits['max_points'] ) );
		return $points >= $limits['min_points'] ? $points : 0;
	}

	/**
	 * Preserva el envío gratis evaluando el subtotal pre-descuento frente al umbral comercial.
	 *
	 * Certifica la paridad transversal entre la regla nativa de cupones y el canje de recompensas.
	 *
	 * @param float|null $subtotal Subtotal opcional a evaluar. Si es null, usa el carrito actual.
	 * @return bool True si el subtotal califica para envío gratis conservado.
	 */
	public static function preserve_free_shipping( ?float $subtotal = null ): bool {
		$threshold = function_exists( 'get_option' )
			? (float) get_option( 'akibara_fsp_carrier_threshold', 60000.0 )
			: 60000.0;
		if ( $threshold <= 0.0 ) {
			$threshold = 60000.0;
		}
		if ( class_exists( '\Akibara\Business\Modules\ShippingCopy\ShippingCopy' ) ) {
			$dyn_threshold = (float) \Akibara\Business\Modules\ShippingCopy\ShippingCopy::threshold();
			if ( $dyn_threshold > 0.0 ) {
				$threshold = $dyn_threshold;
			}
		}

		if ( null === $subtotal ) {
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return false;
			}
			$subtotal = (float) WC()->cart->get_displayed_subtotal();
		}

		return $subtotal >= $threshold;
	}

	/**
	 * Filtra la disponibilidad del método de envío gratuito de WooCommerce
	 * para asegurar que no se pierda si el subtotal califica antes del fee de canje.
	 *
	 * @param bool  $is_available Estado de disponibilidad del método.
	 * @param array $package      Paquete de envío.
	 * @param mixed $method       Instancia del método de envío.
	 * @return bool
	 */
	public function filter_free_shipping_availability( $is_available, array $package = array(), $method = null ): bool {
		unset( $package, $method );
		if ( self::preserve_free_shipping() ) {
			return true;
		}
		return (bool) $is_available;
	}

	/**
	 * Valida una nueva solicitud; nunca la reduce silenciosamente.
	 *
	 * @param int   $requested Puntos solicitados.
	 * @param array $limits    Resultado de redemption_limits().
	 * @return string Vacío cuando se puede confirmar.
	 */
	public static function requested_error( int $requested, array $limits ): string {
		$error = self::unavailable_message( $limits );
		if ( '' !== $error ) {
			return $error;
		}
		if ( $requested < $limits['min_points'] ) {
			return sprintf( 'El canje parte desde %s puntos.', number_format_i18n( $limits['min_points'] ) );
		}
		if ( $requested > $limits['max_points'] ) {
			return sprintf( 'El máximo aplicable en tu carrito actual es %s puntos. Revisa el importe y confirma de nuevo.', number_format_i18n( $limits['max_points'] ) );
		}
		return '';
	}

	/**
	 * Explica por qué el máximo canónico no permite confirmar un canje.
	 *
	 * @param array{cart_present:bool,base:float,missing:int,available:int,min_points:int,max_points:int,value:int} $limits Límites del servidor.
	 * @return string Vacío cuando se puede canjear.
	 */
	public static function unavailable_message( array $limits ): string {
		if ( ! $limits['cart_present'] ) {
			return 'Añade productos al carrito para calcular cuánto puedes canjear.';
		}
		if ( ! empty( $limits['has_coupons'] ) ) {
			return 'Puedes elegir entre usar un cupón de descuento o canjear tus Puntos Akibara (no se pueden combinar en un mismo pedido).';
		}
		if ( $limits['missing'] > 0 ) {
			return sprintf( 'Te faltan %s en productos para poder canjear; el despacho no cuenta.', html_entity_decode( wp_strip_all_tags( wc_price( $limits['missing'] ) ), ENT_QUOTES, 'UTF-8' ) );
		}
		if ( $limits['available'] < $limits['min_points'] ) {
			return sprintf( 'Te faltan %s puntos para poder canjear: el mínimo por canje es %s.', number_format_i18n( $limits['min_points'] - $limits['available'] ), number_format_i18n( $limits['min_points'] ) );
		}
		if ( ! empty( $limits['free_shipping_warning'] ) && 0 === $limits['max_points'] ) {
			return (string) $limits['free_shipping_warning'];
		}
		if ( 0 === $limits['max_points'] ) {
			return sprintf( 'Los topes de esta compra no permiten alcanzar el mínimo de %s puntos por canje.', number_format_i18n( $limits['min_points'] ) );
		}
		return '';
	}

	/**
	 * Cuánto le falta al carrito, en pesos de productos, para poder canjear.
	 *
	 * Se mide sobre la misma base que acredita puntos (líneas con IVA y tras
	 * cupones, sin despacho). La devuelve entera y hacia arriba: si falta medio
	 * peso, falta un peso.
	 *
	 * @param float $cart_gross Productos del carrito.
	 * @return int 0 si ya puede canjear o no hay mínimo.
	 */
	public static function missing_for_minimum( float $cart_gross ): int {
		$min = Options::redeem_min_cart();
		if ( $min <= 0 ) {
			return 0;
		}

		return max( 0, (int) ceil( $min - $cart_gross ) );
	}

	/**
	 * Parte del pedido que se puede pagar con puntos, en pesos.
	 *
	 * El tope lo fija el dueño en WooCommerce → Puntos y Reseñas; 0 significa
	 * sin tope y devuelve el bruto entero (comportamiento anterior). Se aplica
	 * sobre la MISMA base con la que se acreditan los puntos —líneas tras
	 * cupones y con IVA, `Calculator::award_base_for_cart()`— para que lo que
	 * el cliente puede canjear y lo que gana hablen del mismo número; el
	 * despacho queda fuera de esa base y por eso nunca se paga con puntos.
	 *
	 * @param float $cart_gross Bruto de las líneas del carrito.
	 * @return float
	 */
	public static function redeemable_gross( float $cart_gross ): float {
		$cart_gross = max( 0.0, $cart_gross );
		$techo      = $cart_gross;

		$pct = Options::redeem_max_pct();
		if ( $pct > 0 && $pct < 100 ) {
			$techo = min( $techo, floor( $cart_gross * $pct / 100 ) );
		}

		// El tope en pesos manda si es el más estricto: los dos son palancas
		// del panel y ninguna puede saltarse a la otra.
		$monto = Options::redeem_max_amount();
		if ( $monto > 0 ) {
			$techo = min( $techo, (float) $monto );
		}

		return max( 0.0, $techo );
	}

	/**
	 * Los MISMOS topes de arriba, dichos en palabras.
	 *
	 * Fuente común para la página del programa y Mi cuenta. Incluye el límite
	 * porcentual y el límite en pesos; el motor aplica el más estricto.
	 *
	 * @since 0.4.9
	 *
	 * @return string P. ej. «el 20% de los productos, y como máximo $5.000»; '' si no hay tope.
	 */
	public static function techo_humano(): string {
		$limites = array();

		$pct = Options::redeem_max_pct();
		if ( $pct > 0 && $pct < 100 ) {
			$limites[] = sprintf( 'el %s%% de los productos', number_format_i18n( $pct ) );
		}

		$monto = Options::redeem_max_amount();
		if ( $monto > 0 ) {
			$limites[] = sprintf( '$%s', number_format_i18n( $monto ) );
		}

		return array() === $limites ? '' : implode( ', y como máximo ', $limites );
	}

	/**
	 * Añade el fee del canje al carrito.
	 *
	 * @param \WC_Cart $cart Carrito en cálculo.
	 * @return void
	 */
	public function apply_fee( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		if ( method_exists( $cart, 'get_applied_coupons' ) && count( $cart->get_applied_coupons() ) > 0 ) {
			return;
		}

		self::forget_spent_intent();

		$points = self::effective_points( $cart );
		if ( $points <= 0 ) {
			return;
		}

		$gross = $points * Options::redeem_value();
		$rate  = Calculator::cart_tax_rate( $cart );
		$fee   = Calculator::redemption_fee_amount( $gross, $rate );

		if ( $fee >= 0 ) {
			return;
		}

		$cart->add_fee( self::FEE_NAME, $fee, false );
	}

	/**
	 * Bloquea la aplicación de cupones si el cliente tiene un canje de Puntos Akibara aplicado.
	 *
	 * @param bool       $is_valid Estado de validez del cupón.
	 * @param \WC_Coupon $coupon   Cupón que se intenta aplicar.
	 * @return bool
	 * @throws \Exception Si hay canje de puntos activo.
	 */
	public function disallow_coupon_with_points( $is_valid, $coupon ): bool {
		if ( ! $is_valid ) {
			return false;
		}

		if ( self::intent_points() > 0 ) {
			throw new \Exception( 'Ya tienes un descuento de Puntos Akibara en tu pedido. Puedes quitar los puntos si prefieres usar este cupón.' );
		}

		return true;
	}

	/**
	 * Descuento bruto que el fee de canje CONCEDIÓ en un pedido: |total +
	 * IVA| del fee nuestro. Es la única verdad de lo que hay que cobrar.
	 *
	 * El fee se localiza por el nombre-constante (jamás traducido — candado
	 * en GuardrailsTest).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return float Bruto concedido (≥ 0).
	 */
	public static function granted_gross( \WC_Order $order ): float {
		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( $item->get_name() !== self::FEE_NAME ) {
				continue;
			}
			$total = (float) $item->get_total() + (float) $item->get_total_tax();
			if ( $total < 0 ) {
				return -$total;
			}
		}

		return 0.0;
	}

	/**
	 * Captura clásica (checkout por shortcode): recibe el ID del pedido.
	 *
	 * @param int $order_id Pedido recién creado.
	 * @return void
	 */
	public function capture( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		self::remember_intent( $order );
		$this->release_superseded( $order );
		// La caja clásica también reutiliza un pedido fallido: sin saldar su
		// deuda antes, la conciliación cobraba la diferencia y el paso a pagado
		// la volvía a cobrar.
		if ( $this->settle_debt_at_checkout( $order, 'classic' ) ) {
			$this->capture_order( $order, 'classic' );
		}
	}

	/**
	 * Captura de la caja de bloques (`woocommerce_store_api_checkout_order_processed`).
	 *
	 * @param \WC_Order $order Pedido recién creado.
	 * @return void
	 */
	public function capture_from_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		try {
			self::remember_intent( $order );
			$this->release_superseded( $order );
			if ( $this->settle_debt_at_checkout( $order, 'store_api' ) ) {
				$this->capture_order( $order, 'store_api' );
			}

			// Limpia la marca de rechazo antes del procesamiento de pago para evitar
			// condiciones de carrera concurrentes.
			$estaba_marcado = '' !== (string) $order->get_meta( self::META_PAYMENT_REJECTED );
			self::forget_rejection( $order );
			if ( $estaba_marcado ) {
				$this->await_outcome( (int) $order->get_id() );
			}
		} finally {
			// La marca vale para ESTA caja: un proceso largo no puede confundir
			// después un pedido existente con uno recién salido del borrador.
			unset( self::$left_draft[ (int) $order->get_id() ] );
		}
	}

	/**
	 * El cliente armó otra compra tras un pago rechazado: los puntos que
	 * retiene el pedido rechazado vuelven enteros al saldo, justo antes de
	 * cobrar el nuevo.
	 *
	 * Enteros, y no sólo lo que al nuevo le falta, porque un pago rechazado no
	 * se completa solo en la pasarela: si después el cliente paga ese pedido
	 * desde Mi cuenta, «pagar pedido» vuelve a cobrar su deuda completa o no
	 * deja pagarlo (settle_debt_at_checkout()). Así sus puntos no quedan
	 * invisibles hasta que WooCommerce lo anula. Un pedido que redirigió a la
	 * pasarela no se libera nunca desde aquí (restore()).
	 *
	 * @param \WC_Order $order Pedido nuevo.
	 * @return void
	 */
	private function release_superseded( \WC_Order $order ): void {
		foreach ( self::superseded_orders( $order ) as $anterior ) {
			$this->restore( (int) $anterior->get_id(), true, (int) $order->get_id() );
		}
	}

	/**
	 * Valida y cobra la deuda de reactivación antes de autorizar el pago del pedido.
	 *
	 * Asegura que el pedido cuente con respaldo de saldo antes de remitir a la pasarela.
	 *
	 * @param \WC_Order $order    Pedido que se va a pagar.
	 * @param string    $checkout 'store_api' u 'order_pay'.
	 * @return bool False si el pago se detuvo (en 'order_pay'; en la caja lanza).
	 * @throws \Exception En la caja, si el saldo no cubre la deuda.
	 */
	private function settle_debt_at_checkout( \WC_Order $order, string $checkout ): bool {
		if ( (int) $order->get_meta( self::META_REVIVAL_DEBT ) <= 0 ) {
			return true;
		}

		$resultado = $this->recapture_after_revival( (int) $order->get_id(), true );
		if ( self::COBRO_OK === $resultado ) {
			return true;
		}

		// Si el cobro no pudo completarse por candado ocupado o falta de saldo, se detiene el flujo.
		$this->stop_without_balance( $order, $checkout, self::COBRO_OCUPADO === $resultado );

		return false;
	}

	/**
	 * Pagar un pedido ya creado (Mi cuenta -> Pedidos -> Pagar).
	 *
	 * Valida que los pedidos pendientes mantengan saldo suficiente para respaldar
	 * el descuento aplicado. En caso contrario, se detiene el pago.
	 *
	 * @param \WC_Order $order Pedido que se va a pagar.
	 * @return void
	 */
	public function guard_order_pay( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Un pedido fallido con deuda de revival: sus puntos volvieron al saldo
		// al fallar. Se re-cobran completos antes de cobrar el pago.
		if ( (int) $order->get_meta( self::META_REVIVAL_DEBT ) > 0 ) {
			if ( ! $this->settle_debt_at_checkout( $order, 'order_pay' ) ) {
				return;
			}

			// Si la pasarela vuelve a rechazar sin cambiar de estado, se agenda
			// revisión para cuando cierre la ventana de pago.
			if ( $order->has_status( 'failed' ) ) {
				$order->update_meta_data( self::META_PAY_WINDOW, time() + self::unpaid_window() );
				$order->save_meta_data();
				self::schedule_retry( (int) $order->get_id(), self::unpaid_window(), self::RETRY_IMPAGO );
			}
		}

		// Limpia la marca de rechazo previa para prevenir reutilización concurrente.
		self::forget_rejection( $order );

		if ( self::granted_gross( $order ) <= 0 ) {
			return;
		}

		// También con la captura hecha: la caja pudo dejar el pedido con un fee
		// distinto del canje cobrado (una conciliación que se detuvo), y aquí no
		// se miraba, así que se pagaba con la diferencia sin cobrar.
		$this->capture_order( $order, 'order_pay' );
	}

	/**
	 * «Pagar pedido» terminó sin llegar a la pasarela: WooCommerce sólo sigue
	 * hasta aquí si no redirigió (`WC_Form_Handler::pay_action`).
	 *
	 * Un pedido fallido cuyo pago se rechazó otra vez devuelve ya lo que se le
	 * re-cobró: esperar a la revisión de una hora dejaba esos puntos fuera de
	 * la vista del cliente. Uno pendiente queda marcado como rechazado.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return void
	 */
	public function after_order_pay( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( $order->has_status( 'failed' ) ) {
			if ( '' !== (string) $order->get_meta( self::META_PAY_WINDOW ) ) {
				$order->delete_meta_data( self::META_PAY_WINDOW );
				$order->save_meta_data();
			}
			$this->restore( (int) $order->get_id(), true );
			return;
		}

		if ( $order->has_status( 'pending' ) ) {
			self::record_rejection( $order );
		}
	}

	/**
	 * Lee la respuesta de la caja de bloques: si la pasarela rechazó el pago,
	 * marca el pedido; si lo aceptó o redirigió, borra la marca.
	 *
	 * La Store API no avisa por ningún gancho de cómo terminó el pago: lo dice
	 * sólo su respuesta (`payment_result`, o el error cuando la pasarela
	 * rechaza con mensaje).
	 *
	 * @param mixed $response Respuesta REST.
	 * @param mixed $server   Servidor REST (no usado).
	 * @param mixed $request  Petición REST.
	 * @return mixed La respuesta, intacta.
	 */
	public function watch_checkout_response( $response, $server, $request ) {
		unset( $server );

		if (
			! $response instanceof \WP_REST_Response
			|| ! $request instanceof \WP_REST_Request
			|| 'POST' !== $request->get_method()
			|| 1 !== preg_match( '#^/wc/store(?:/v\d+)?/checkout/?$#', (string) $request->get_route() )
		) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$pago = is_array( $data['payment_result'] ?? null ) ? (string) ( $data['payment_result']['payment_status'] ?? '' ) : '';

		// Sin `order_id` (las respuestas de error no lo traen) manda el borrador
		// que ESTA ruta acaba de fijar en la sesión, no `order_awaiting_payment`,
		// que puede ser un pedido viejo de la caja clásica: marcarlo dejaba
		// prestables los puntos del pedido equivocado (segunda revisión).
		$order = (int) ( $data['order_id'] ?? 0 ) > 0
			? wc_get_order( (int) $data['order_id'] )
			: self::draft_order_of_session( get_current_user_id() );
		if ( ! $order instanceof \WC_Order ) {
			// Sin pedido que leer tampoco hay desenlace: lo desmarcado vuelve.
			$this->restore_unsettled_marks();
			return $response;
		}

		if ( in_array( $pago, array( 'failure', 'error' ), true ) || in_array( (string) ( $data['code'] ?? '' ), self::PAYMENT_ERROR_CODES, true ) ) {
			unset( self::$awaiting_outcome[ (int) $order->get_id() ] );
			self::record_rejection( $order );
		} elseif ( in_array( $pago, array( 'success', 'pending' ), true ) ) {
			unset( self::$awaiting_outcome[ (int) $order->get_id() ] );
			self::forget_rejection( $order );
		} else {
			// Ni aceptado ni rechazado por la pasarela: la caja se detuvo por otra
			// cosa. Lo que esta petición borró vuelve a su sitio ya, sin esperar
			// al cierre.
			$this->restore_unsettled_marks();
		}

		return $response;
	}

	/**
	 * Anota que ESTA petición borró la marca de rechazo de un pedido para
	 * pagarlo, y que todavía no se sabe cómo terminó.
	 *
	 * La marca se borra antes de llamar a la pasarela y la respuesta de la caja
	 * la repone si rechaza. Pero entre las dos cosas la caja puede detenerse sin
	 * que la pasarela llegue a contestar: otra extensión lanza en
	 * `woocommerce_store_api_checkout_order_processed`, la Store API responde
	 * `woocommerce_rest_unknown_server_error`, o PHP muere. Ninguno de esos
	 * códigos es de pago; la restauración permite recuperar los puntos del
	 * pedido pendiente al reintentar después de ese fallo.
	 *
	 * No vale marcar ante CUALQUIER error de la caja: una respuesta de error
	 * puede venir de otra pestaña mientras el pedido de la sesión está en la
	 * pasarela con redirección, y marcarlo prestaría sus puntos con el cliente
	 * pagando. Sólo se repone lo que esta misma petición borró: es volver al
	 * estado de antes, no decidir uno nuevo.
	 *
	 * @param int $order_id Pedido cuya marca se acaba de borrar.
	 * @return void
	 */
	private function await_outcome( int $order_id ): void {
		self::$awaiting_outcome[ $order_id ] = true;

		if ( ! self::$shutdown_hooked ) {
			self::$shutdown_hooked = true;
			// `shutdown` también corre tras un fatal o un tiempo agotado.
			add_action( 'shutdown', array( $this, 'restore_unsettled_marks' ) );
		}
	}

	/**
	 * Repone la marca de rechazo de los pedidos que esta petición desmarcó y
	 * cuyo pago no llegó a ningún desenlace. `record_rejection()` sólo marca un
	 * pedido que sigue pendiente y reteniendo canje: si entretanto se pagó, no
	 * hace nada.
	 *
	 * @return void
	 */
	public function restore_unsettled_marks(): void {
		foreach ( array_keys( self::$awaiting_outcome ) as $order_id ) {
			unset( self::$awaiting_outcome[ $order_id ] );
			$order = wc_get_order( (int) $order_id );
			if ( $order instanceof \WC_Order ) {
				self::record_rejection( $order );
			}
		}
	}

	/**
	 * Un pago que llegó a la pasarela (caja clásica y «pagar pedido») borra la
	 * marca de rechazo.
	 *
	 * @param mixed $result   Resultado de la pasarela.
	 * @param mixed $order_id Pedido.
	 * @return mixed El resultado, intacto.
	 */
	public function forget_rejection_on_success( $result, $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			self::forget_rejection( $order );
		}

		return $result;
	}

	/**
	 * Marca un pedido pendiente que retiene canje como rechazado por la pasarela.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return void
	 */
	private static function record_rejection( \WC_Order $order ): void {
		if ( self::held_by( $order ) <= 0 ) {
			return;
		}

		$order->update_meta_data( self::META_PAYMENT_REJECTED, time() );
		$order->save_meta_data();
	}

	/**
	 * Borra la marca de rechazo: el último intento llegó a la pasarela.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return void
	 */
	private static function forget_rejection( \WC_Order $order ): void {
		if ( '' === (string) $order->get_meta( self::META_PAYMENT_REJECTED ) ) {
			return;
		}

		$order->delete_meta_data( self::META_PAYMENT_REJECTED );
		$order->save_meta_data();
	}

	/**
	 * Captura del canje: en la caja (bloques y clásica) y en los reintentos (un
	 * candado ocupado al crear no puede dejar el descuento regalado).
	 *
	 * Cobra los puntos que respaldan el descuento HORNEADO en el pedido, bajo
	 * candado de pedido, con meta releída fresca y event_key único.
	 *
	 * Si el saldo ya no alcanza —dos compras a la vez con el mismo saldo, o
	 * Si el saldo no cubre el descuento del pedido al momento del pago, se detiene la compra.
	 * En la caja o en orden_pay, previene que la pasarela autorice una venta sin respaldo de saldo.
	 * En reintentos de fondo sobre pedidos existentes, cobra lo disponible y genera registro.
	 *
	 * Un candado ocupado no es saldo insuficiente. Donde todavía se puede
	 * detener el pago, lo detiene con otro aviso («vuelve a intentar»): seguir
	 * dejaba ir a la pasarela un descuento sin cobrar. En los reintentos deja
	 * nota y agenda otro.
	 *
	 * @param \WC_Order $order    Pedido (el MISMO objeto que maneja WooCommerce).
	 * @param string    $checkout 'store_api' o 'classic' desde la caja, 'order_pay' al pagar un pedido ya creado; '' en reintentos.
	 * @return void
	 * @throws \Exception En la caja, si el saldo ya no respalda el descuento.
	 */
	private function capture_order( \WC_Order $order, string $checkout ): void {
		$resultado = $this->lock_and_capture( (int) $order->get_id(), $checkout );

		if ( self::COBRO_OK !== $resultado ) {
			$this->stop_without_balance( $order, $checkout, self::COBRO_OCUPADO === $resultado );
		}
	}

	/**
	 * El cobro bajo el candado del pedido, con la meta releída fresca.
	 *
	 * @param int    $order_id Pedido.
	 * @param string $checkout Contexto (ver capture_order()).
	 * @return string COBRO_*: en los reintentos ('' ) siempre COBRO_OK, porque
	 *                no hay pago que detener.
	 */
	private function lock_and_capture( int $order_id, string $checkout ): string {
		// Si el candado del pedido no se consigue, el cuerpo no corre: en la caja
		// eso es «no se pudo cobrar», no «no había nada que cobrar».
		$resultado = '' === $checkout ? self::COBRO_OK : self::COBRO_OCUPADO;

		$this->with_order_lock(
			$order_id,
			function () use ( $order_id, $checkout, &$resultado ): void {
				$resultado = self::COBRO_OK;

				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}
				$order->read_meta_data( true );

				// La intención se preserva hasta que el carrito se vacía o se paga el pedido.
				if ( '' !== (string) $order->get_meta( self::META_REDEEMED_POINTS ) ) {
					// Ya capturado previamente. Reconciliar si el pedido reutilizado modificó su monto.
					if ( '' !== $checkout && $order->needs_payment() ) {
						$resultado = $this->reconcile_reused( $order );
					}
					return;
				}

				$gross = self::granted_gross( $order );
				if ( $gross <= 0 ) {
					return; // Sin fee nuestro no hay nada que cobrar.
				}

				$user_id = (int) $order->get_customer_id();
				if ( $user_id <= 0 ) {
					return;
				}

				$points = (int) round( $gross / Options::redeem_value() );
				if ( $points <= 0 ) {
					return;
				}

				$attempt  = (int) $order->get_meta( '_akb_redeem_attempt' );
				$restores = Ledger::count_movements( 'order', $order_id, 'redeem_refund' );
				if ( $restores > 0 && $attempt <= $restores ) {
					$attempt = $restores + 1;
				}
				if ( $attempt > 0 ) {
					$order->update_meta_data( '_akb_redeem_attempt', $attempt );
					$order->save_meta_data();
				}
				$event_key = $attempt > 1 ? sprintf( 'redeem_%d_%d', $order_id, $attempt ) : sprintf( 'redeem_%d', $order_id );

				$debited = Ledger::debit(
					$user_id,
					$points,
					'redemption',
					'order',
					$order_id,
					sprintf( 'Canje en el pedido #%d', $order_id ),
					true,
					$event_key
				);

				if ( null === $debited && '' !== $checkout ) {
					// Infraestructura (candado de usuario/insert) con el pago aún
					// por hacer: se detiene sin cobrar y el cliente reintenta.
					$resultado = self::COBRO_OCUPADO;
					return;
				}

				if ( null === $debited ) {
					// Infraestructura en un reintento. El pedido queda SIN
					// capturar a propósito: el siguiente reintento de la cola
					// vuelve a intentar esta misma captura.
					$order->add_order_note(
						'Puntos Akibara: la captura del canje no pudo ejecutarse (candado ocupado); se reintentará en 5 minutos.'
					);
					self::schedule_retry( $order_id );
					return;
				}

				if ( false === $debited && '' !== $checkout ) {
					$resultado = self::COBRO_SIN_SALDO;
					return; // Se detiene fuera del candado, sobre el pedido de WooCommerce.
				}

				if ( false === $debited ) {
					// Saldo insuficiente: cobrar lo disponible y alarmar.
					$partial = Ledger::debit(
						$user_id,
						$points,
						'redemption_short',
						'order',
						$order_id,
						sprintf( 'Canje con saldo insuficiente en el pedido #%d', $order_id ),
						false,
						$event_key
					);
					$debited   = is_int( $partial ) ? $partial : 0;
					$shortfall = $points - $debited;

					$order->add_order_note(
						sprintf(
							'Puntos Akibara: el canje pedía %d puntos pero el saldo solo cubrió %d (faltaron %d). Revisar con el cliente.',
							$points,
							$debited,
							$shortfall
						)
					);

					/**
					 * Canje capturado con saldo insuficiente. Las alertas del
					 * panel escuchan acá.
					 *
					 * @param \WC_Order $order     Pedido afectado.
					 * @param int       $points    Puntos pedidos.
					 * @param int       $debited   Puntos realmente cobrados.
					 * @param int       $shortfall Puntos no respaldados.
					 */
					do_action( 'akb_rewards_redemption_shortfall', $order, $points, $debited, $shortfall );
				} else {
					$order->add_order_note(
						sprintf( 'Puntos Akibara: canje de %d puntos (descuento de %s).', (int) $debited, wc_price( $gross ) )
					);
				}

				// Un 0 con fee vivo puede corresponder a un asiento ya aplicado cuya
				// meta no se guardó. Recupera el débito del ledger para no duplicarlo.
				if ( 0 === (int) $debited ) {
					$debited = abs( Ledger::sum_movements( 'order', $order_id, array( 'redemption', 'redemption_short' ) ) );
				}

				// REDEEMED_VALUE guarda el descuento CONCEDIDO (el fee que
				// redujo el pedido): la base puntuable debe restar lo que el
				// cliente no pagó con plata, hubiera o no saldo suficiente.
				$order->update_meta_data( self::META_REDEEMED_POINTS, (int) $debited );
				$order->update_meta_data( self::META_REDEEMED_VALUE, (float) $gross );
				$order->save_meta_data();
			}
		);

		return $resultado;
	}

	/**
	 * Concilia los puntos cobrados con el descuento que el pedido lleva AHORA.
	 * Corre bajo el candado del pedido, con las metas releídas.
	 *
	 * @param \WC_Order $order Pedido releído.
	 * @return string COBRO_*: distinto de COBRO_OK, la caja se detiene.
	 */
	private function reconcile_reused( \WC_Order $order ): string {
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return self::COBRO_OK;
		}

		// La deuda de revival se salda en la caja ANTES de conciliar
		// (settle_debt_at_checkout()). Si sigue viva, conciliar cobraría como
		// diferencia lo que el paso a pagado vuelve a cobrar como deuda.
		if ( (int) $order->get_meta( self::META_REVIVAL_DEBT ) > 0 ) {
			return self::COBRO_OCUPADO;
		}

		$order_id = (int) $order->get_id();
		$gross    = self::granted_gross( $order );
		$pide     = $gross > 0 ? (int) round( $gross / Options::redeem_value() ) : 0;
		$redeemed = (int) $order->get_meta( self::META_REDEEMED_POINTS );
		$cobrado  = max( 0, $redeemed - (int) $order->get_meta( self::META_RESTORED_POINTS ) );

		if ( $pide === $cobrado ) {
			return self::COBRO_OK;
		}

		$nota  = sprintf( 'Ajuste del canje en el pedido #%d', $order_id );
		$clave = sprintf( 'redeem_adjust_%d_%d', $order_id, Ledger::count_movements( 'order', $order_id, 'redemption_adjust' ) + 1 );

		if ( $pide > $cobrado ) {
			$diferencia = $pide - $cobrado;
			$debitado   = Ledger::debit( $user_id, $diferencia, 'redemption_adjust', 'order', $order_id, $nota, true, $clave );
			if ( null === $debitado ) {
				return self::COBRO_OCUPADO; // Sin candado: la caja se detiene y el cliente reintenta.
			}
			if ( ! is_int( $debitado ) ) {
				return self::COBRO_SIN_SALDO;
			}
			$order->update_meta_data( self::META_REDEEMED_POINTS, $redeemed + $diferencia );
			$order->add_order_note( sprintf( 'Puntos Akibara: el canje de este pedido cambió de %d a %d puntos al volver a pagarlo; se cobraron %d más.', $cobrado, $pide, $diferencia ) );
		} else {
			$diferencia = $cobrado - $pide;
			if ( ! is_int( Ledger::credit( $user_id, $diferencia, 'redemption_adjust', 'order', $order_id, $nota, false, $clave ) ) ) {
				// La compra continúa y la devolución pendiente se reintenta; si se
				// agotan los intentos, avisa al dueño.
				$order->add_order_note( sprintf( 'Puntos Akibara: el canje de este pedido bajó de %d a %d puntos y los %d de diferencia no se pudieron devolver todavía; se reintenta solo.', $cobrado, $pide, $diferencia ) );
				// La deuda queda ESCRITA, con la misma clave del intento: es lo que
				// hace que «se reintenta solo» sea verdad (`drain_pending_giveback()`)
				// y que, si el crédito sí llegó a asentarse, no se pague dos veces.
				// META_REDEEMED_POINTS no se toca: sigue diciendo lo cobrado de
				// verdad, para que una anulación reponga todo.
				$order->update_meta_data(
					self::META_PENDING_GIVEBACK,
					(string) wp_json_encode(
						array(
							'points' => $diferencia,
							'key'    => $clave,
						)
					)
				);
				$order->save_meta_data();
				self::schedule_retry( $order_id );
				return self::COBRO_OK;
			}
			$order->update_meta_data( self::META_REDEEMED_POINTS, $redeemed - $diferencia );
			$order->add_order_note( sprintf( 'Puntos Akibara: el canje de este pedido cambió de %d a %d puntos al volver a pagarlo; se devolvieron %d.', $cobrado, $pide, $diferencia ) );
		}

		$order->update_meta_data( self::META_REDEEMED_VALUE, (float) $gross );
		$order->save_meta_data();

		return self::COBRO_OK;
	}

	/**
	 * Transiciones de estado: reponer en anulaciones; en estados vivos,
	 * reintentar capturas pendientes y re-cobrar la deuda de revival.
	 *
	 * @param int       $order_id ID del pedido.
	 * @param string    $from     Estado anterior.
	 * @param string    $to       Estado nuevo.
	 * @param \WC_Order $order    Pedido.
	 * @return void
	 */
	public function on_status_changed( $order_id, $from, $to, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Muerte del pedido: se repone TODO el canje restante.
		if ( OrderStates::is_down( (string) $to ) ) {
			$this->restore( (int) $order_id, true );
			return;
		}

		// Pedido vivo: se reintenta la captura pendiente y se re-cobra la deuda.
		if ( OrderStates::is_alive( (string) $to ) ) {
			// Primero el cobro pendiente de la creación (candado ocupado);
			// después la deuda de revival, si la hay. Ambos con pre-filtro en
			// memoria: la inmensa mayoría de las transiciones de la tienda no
			// deben pagar candado + relectura para descubrir que no hay nada.
			//
			// Salvo cuando el borrador de la caja de bloques pasa a «pendiente»:
			// WooCommerce lo hace justo antes de avisar que el pedido está listo
			// para el pago, y es ESE aviso el que puede detener la compra si el
			// saldo ya no alcanza. Se anota que el pedido salió del borrador en
			// esta petición para poder devolverlo ahí (stop_without_balance()).
			if ( 'checkout-draft' === (string) $from && 'pending' === (string) $to ) {
				self::$left_draft[ (int) $order_id ] = true;
			} elseif ( 'pending' !== (string) $to && '' === (string) $order->get_meta( self::META_REDEEMED_POINTS ) && self::granted_gross( $order ) > 0 ) {
				// Volver a «pendiente» no es pagar: cobrar recortado ahí dejaba
				// pasar la caja con el canje ya dado por cobrado.
				$this->capture_order( $order, '' );
			}
			// La deuda de revival se re-cobra cuando el pedido avanza hacia el
			// pago o el despacho, no al volver a «pendiente»: ahí todavía no se
			// pagó, y re-cobrar recortado dejaba seguir un pago con el descuento
			// sin respaldo. Mientras está pendiente, la caja y «pagar pedido» la
			// re-cobran completa o detienen el pago (settle_debt_at_checkout()).
			// Con fee nuestro se relee bajo candado aunque la copia en memoria no
			// muestre deuda: quien cambia el estado (un webhook) pudo cargar el
			// pedido antes de que otra caja le liberara los puntos.
			if ( 'pending' !== (string) $to && ( (int) $order->get_meta( self::META_REVIVAL_DEBT ) > 0 || self::granted_gross( $order ) > 0 ) ) {
				$this->recapture_after_revival( (int) $order_id );
			}
		}
	}

	/**
	 * Reembolso parcial: reposición proporcional (semántica SPAR) medida
	 * sobre los ÍTEMS.
	 *
	 * @param int $order_id  ID del pedido.
	 * @param int $refund_id ID del reembolso (no usado).
	 * @return void
	 */
	public function on_order_refunded( $order_id, $refund_id ): void {
		unset( $refund_id );
		$this->restore( (int) $order_id, false );
	}

	/**
	 * Repone puntos canjeados. Idempotente por la meta acumulada RESTORED.
	 *
	 * Solo la reposición por MUERTE alimenta la deuda de revival: la
	 * proporcional de un reembolso parcial es definitiva (el pedido sigue
	 * vivo y su próximo avance de fulfillment NO debe robarla).
	 *
	 * @param int  $order_id    ID del pedido.
	 * @param bool $full        True = anulación (todo lo restante); false =
	 *                          evento de reembolso (proporcional por ítems).
	 * @param int  $replaced_by Pedido nuevo de la misma compra que reemplaza a
	 *                          este rechazado (release_superseded()); 0 si no.
	 * @return void
	 */
	private function restore( int $order_id, bool $full, int $replaced_by = 0 ): void {
		$this->with_order_lock(
			$order_id,
			function () use ( $order_id, $full, $replaced_by ): void {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}
				$order->read_meta_data( true );

				if ( $replaced_by > 0 && ( ! $order->has_status( 'pending' ) || (int) $order->get_meta( self::META_PAYMENT_REJECTED ) <= 0 ) ) {
					// Se pagó, se anuló o volvió a la pasarela entretanto: nada que
					// liberar. Releído bajo el candado, porque «pagar pedido» en
					// otra pestaña pudo borrar la marca de rechazo.
					return;
				}
				if ( $full && 0 === $replaced_by && ! OrderStates::is_down( (string) $order->get_status() ) ) {
					return; // Revivió entretanto (un reintento que llega tarde): no se devuelve.
				}

				$redeemed = (int) $order->get_meta( self::META_REDEEMED_POINTS );
				if ( $redeemed <= 0 ) {
					return;
				}

				$restored  = (int) $order->get_meta( self::META_RESTORED_POINTS );
				$remaining = max( 0, $redeemed - $restored );
				if ( $remaining <= 0 ) {
					return;
				}

				if ( $full ) {
					$to_restore = $remaining;
				} else {
					// Proporción por ÍTEMS: un reembolso solo de envío no
					// devuelve canje (el descuento sigue íntegro sobre la
					// mercadería).
					// Misma regla de redondeo que la base conservada, o la resta
					// inventa décimas devueltas.
					$items_total = Calculator::items_base( $order );
					if ( $items_total <= 0 ) {
						return;
					}

					$refunded_items = max( 0.0, $items_total - Calculator::conserved_items_base( $order ) );
					$ratio          = max( 0.0, min( 1.0, $refunded_items / $items_total ) );
					$target         = (int) floor( $redeemed * $ratio );
					$to_restore     = max( 0, min( $remaining, $target - $restored ) );
					if ( $to_restore <= 0 ) {
						return;
					}
				}

				$user_id = (int) $order->get_customer_id();
				if ( $user_id <= 0 ) {
					return;
				}

				$restores = Ledger::count_movements( 'order', $order_id, 'redeem_refund' );

				$result = Ledger::credit(
					$user_id,
					$to_restore,
					'redeem_refund',
					'order',
					$order_id,
					sprintf( 'Devolución de puntos canjeados (pedido #%d)', $order_id ),
					false, // No es ganancia nueva: no infla el total histórico.
					sprintf( 'redeem_refund_%d_%d', $order_id, $restores + 1 )
				);

				if ( ! is_int( $result ) ) {
					if ( $replaced_by > 0 ) {
						// Sin liberar, el pedido nuevo no tiene saldo y la caja se
						// detiene: el siguiente intento del cliente vuelve a probar.
						Log::error( sprintf( 'No se pudieron liberar los puntos del pedido #%d para el #%d.', $order_id, $replaced_by ) );
						return;
					}
					// Un pedido anulado no vuelve a cambiar de estado: esperar
					// «la próxima transición» dejaba al cliente sin sus puntos.
					$order->add_order_note(
						'Puntos Akibara: la devolución del canje no pudo ejecutarse (candado ocupado); se reintentará en 5 minutos.'
					);
					self::schedule_retry( $order_id );
					return;
				}

				$order->update_meta_data( self::META_RESTORED_POINTS, $restored + $to_restore );
				if ( $full ) {
					$debt = (int) $order->get_meta( self::META_REVIVAL_DEBT );
					$order->update_meta_data( self::META_REVIVAL_DEBT, $debt + $to_restore );
					// La reposición total ya devolvió TODO lo cobrado, también lo
					// que se debía de un ajuste a la baja: esa deuda queda saldada
					// por aquí, y un reintento que llegue después no puede
					// acreditarla otra vez.
					$order->delete_meta_data( self::META_PENDING_GIVEBACK );
				}
				$order->save_meta_data();
				$order->add_order_note(
					$replaced_by > 0
						? sprintf( 'Puntos Akibara: el pago de este pedido fue rechazado y el cliente armó otra compra (pedido #%d); %d puntos de su canje vuelven a su saldo. Si este pedido se paga igual, se vuelven a cobrar antes del pago.', $replaced_by, $to_restore )
						: sprintf( 'Puntos Akibara: %d puntos del canje devueltos al saldo.', $to_restore )
				);
			}
		);
	}

	/**
	 * Re-cobra la DEUDA DE REVIVAL: solo lo repuesto por muerte del pedido,
	 * nunca lo devuelto por reembolsos parciales (esa parte es del cliente) —
	 * y nunca en pedidos históricos migrados (entran sin deuda).
	 *
	 * RESTORED decrementa únicamente por lo efectivamente cobrado, manteniendo la integridad contable.
	 *
	 * @param int  $order_id ID del pedido.
	 * @param bool $strict   Exigir la deuda completa (en la caja): si no
	 *                       alcanza, o si no se puede cobrar, no se cobra nada.
	 * @return string COBRO_*; fuera del modo estricto, siempre COBRO_OK.
	 */
	private function recapture_after_revival( int $order_id, bool $strict = false ): string {
		// En la caja, un candado de pedido que no se consigue es «no se pudo».
		$resultado = $strict ? self::COBRO_OCUPADO : self::COBRO_OK;

		$this->with_order_lock(
			$order_id,
			function () use ( $order_id, $strict, &$resultado ): void {
				$resultado = self::COBRO_OK;

				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}
				$order->read_meta_data( true );

				$debt = (int) $order->get_meta( self::META_REVIVAL_DEBT );
				if ( $debt <= 0 ) {
					return;
				}

				$user_id = (int) $order->get_customer_id();
				if ( $user_id <= 0 ) {
					return;
				}

				$recaptures = Ledger::count_movements( 'order', $order_id, 'redemption_recapture' );
				$event_key  = sprintf( 'redeem_recapture_%d_%d', $order_id, $recaptures + 1 );

				$debited = Ledger::debit(
					$user_id,
					$debt,
					'redemption_recapture',
					'order',
					$order_id,
					sprintf( 'Re-cobro del canje al revivir el pedido #%d', $order_id ),
					$strict,
					$event_key
				);

				if ( $strict && false === $debited ) {
					$resultado = self::COBRO_SIN_SALDO;
					return; // Se detiene fuera del candado.
				}

				if ( $strict && ! is_int( $debited ) ) {
					// Nada quedó cobrado y la caja se detiene: el siguiente
					// intento del cliente vuelve a probar.
					$resultado = self::COBRO_OCUPADO;
					return;
				}

				if ( ! is_int( $debited ) ) {
					$order->add_order_note(
						'Puntos Akibara: el re-cobro del canje no pudo ejecutarse (candado ocupado); se reintentará en 5 minutos.'
					);
					self::schedule_retry( $order_id );
					return;
				}

				$restored = (int) $order->get_meta( self::META_RESTORED_POINTS );
				$order->update_meta_data( self::META_RESTORED_POINTS, max( 0, $restored - $debited ) );
				$order->update_meta_data( self::META_REVIVAL_DEBT, 0 );
				if ( $debited < $debt ) {
					// La deuda no recuperada conserva puntos y descuento. UNSETTLED
					// excluye esa porción de la base puntuable del pedido reactivado.
					$unsettled = (int) $order->get_meta( self::META_UNSETTLED );
					$order->update_meta_data( self::META_UNSETTLED, $unsettled + ( $debt - $debited ) );
				}
				$order->save_meta_data();

				if ( $debited < $debt ) {
					$order->add_order_note(
						sprintf(
							'Puntos Akibara: el pedido revivió con canje de %d puntos pero el saldo solo cubrió %d. Revisar con el cliente.',
							$debt,
							$debited
						)
					);
					do_action( 'akb_rewards_redemption_shortfall', $order, $debt, $debited, $debt - $debited );
				} else {
					$order->add_order_note(
						sprintf( 'Puntos Akibara: canje de %d puntos re-cobrado al revivir el pedido.', $debited )
					);
				}
			}
		);

		return $resultado;
	}

	/**
	 * Candado de pedido — MISMO nombre que el de Accrual a propósito: la
	 * acreditación, la reversa y el canje del mismo pedido se serializan
	 * entre sí (GET_LOCK es reentrante dentro de una misma conexión). El
	 * fallo se grita por hook: un cobro de canje saltado no puede quedar mudo.
	 *
	 * @param int      $order_id ID del pedido.
	 * @param callable $fn       Cuerpo a ejecutar.
	 * @return void
	 */
	private function with_order_lock( int $order_id, callable $fn ): void {
		global $wpdb;

		$lock = 'akb_rewards_order_' . $order_id;

		$acquired = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT )
		);

		if ( '1' !== $acquired ) {
			Log::error( sprintf( 'Candado %s no disponible; operación de canje reagendada.', $lock ) );
			self::schedule_retry( $order_id );
			return;
		}

		try {
			$fn();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Reintento agendado de una operación de canje que no pudo tomar su
	 * candado o escribir en el libro.
	 *
	 * Decide por el estado ACTUAL del pedido, no por el que tenía al fallar: si
	 * entretanto se anuló, devuelve; si sigue vivo, captura y re-cobra lo
	 * pendiente. Cada operación es idempotente por sus metas, así que un
	 * reintento de más no mueve nada.
	 *
	 * @param int    $order_id Pedido.
	 * @param string $motivo   RETRY_IMPAGO para la revisión de un pedido que
	 *                         pudo seguir en la pasarela; '' para el resto.
	 * @return void
	 */
	public function run_retry( $order_id, $motivo = '' ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		self::$in_retry     = true;
		self::$retry_failed = false;
		try {
			$status = (string) $order->get_status();

			if ( OrderStates::is_down( $status ) ) {
				if ( self::RETRY_IMPAGO === (string) $motivo ) {
					// La ventana pudo renovarse: un segundo intento de pago la
					// Respeta la ventana de pago activa para evitar liberar puntos mientras el cliente opera en la pasarela.
					$ventana = (int) $order->get_meta( self::META_PAY_WINDOW );
					if ( $ventana > time() ) {
						self::schedule_retry( (int) $order_id, max( 60, $ventana - time() ), self::RETRY_IMPAGO );
						return;
					}
					$order->delete_meta_data( self::META_PAY_WINDOW );
					$order->save_meta_data();
				} elseif ( 'failed' === $status && (int) $order->get_meta( self::META_PAY_WINDOW ) > time() ) {
					// El cliente puede seguir pagando este pedido en la pasarela; se evalúa al cierre de ventana.
					return;
				}
				$this->restore( (int) $order_id, true );
				return;
			}

			if ( ! OrderStates::is_alive( $status ) ) {
				return;
			}

			// La devolución pendiente se procesa en cualquier estado activo.
			$this->drain_pending_giveback( (int) $order_id );

			// Pedidos no completados aún no cobran en firme; el cobro completo ocurre al pasar a pagado.
			if ( 'pending' !== $status ) {
				if ( '' === (string) $order->get_meta( self::META_REDEEMED_POINTS ) && self::granted_gross( $order ) > 0 ) {
					$this->capture_order( $order, '' );
				}
				if ( (int) $order->get_meta( self::META_REVIVAL_DEBT ) > 0 ) {
					$this->recapture_after_revival( (int) $order_id );
				}
			}
			if ( (float) $order->get_total_refunded() > 0 ) {
				$this->restore( (int) $order_id, false );
			}
		} finally {
			// Una corrida que sale bien devuelve el cupo: el tope es para un
			// problema que no se arregla, no para toda la vida del pedido.
			if ( ! self::$retry_failed && (int) $order->get_meta( self::META_RETRIES ) > 0 ) {
				$order->update_meta_data( self::META_RETRIES, 0 );
				$order->save_meta_data();
			}
			self::$in_retry = false;
		}
	}

	/**
	 * Paga la devolución pendiente de un ajuste a la baja, si la hay.
	 *
	 * Bajo el candado del pedido y releyendo sus metas: la caja, «pagar pedido»
	 * u otro reintento pueden estar tocándolo. El crédito viaja con la clave
	 * guardada, así que si el primer intento sí llegó a asentarse —respuesta
	 * perdida, no crédito perdido— el ledger contesta «ya aplicado» y aquí sólo
	 * se cierra la cuenta. Si vuelve a fallar, `schedule_retry()` gasta un cupo
	 * de verdad: a los MAX_RETRIES suena `akb_rewards_redemption_stuck`.
	 *
	 * @param int $order_id Pedido.
	 * @return void
	 */
	private function drain_pending_giveback( int $order_id ): void {
		$this->with_order_lock(
			$order_id,
			function () use ( $order_id ): void {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					return;
				}
				$order->read_meta_data( true );

				$cruda = (string) $order->get_meta( self::META_PENDING_GIVEBACK );
				if ( '' === $cruda ) {
					return; // Lo normal: no hay nada pendiente.
				}

				$deuda   = json_decode( $cruda, true );
				$puntos  = is_array( $deuda ) ? (int) ( $deuda['points'] ?? 0 ) : 0;
				$clave   = is_array( $deuda ) ? (string) ( $deuda['key'] ?? '' ) : '';
				$user_id = (int) $order->get_customer_id();

				// Una deuda que no se puede leer, o un pedido que ya no tiene
				// cliente, no se va a saldar sola por más que se reintente: callar
				// dejaba una promesa («se devuelve sola») que nadie cumplía. Se
				// retira la meta y el caso queda escrito en el pedido, con la cifra,
				// para que lo resuelva una persona.
				if ( $puntos <= 0 || '' === $clave || $user_id <= 0 ) {
					$order->delete_meta_data( self::META_PENDING_GIVEBACK );
					$order->add_order_note(
						sprintf(
							'Puntos Akibara: había una devolución de puntos pendiente en este pedido y no se pudo completar sola (%s). Revísala a mano en Akibara Puntos → Clientes. Dato guardado: %s',
							$user_id <= 0 ? 'el pedido ya no tiene cliente' : 'el dato guardado no se puede leer',
							$cruda
						)
					);
					$order->save_meta_data();
					return;
				}

				$acreditado = Ledger::credit(
					$user_id,
					$puntos,
					'redemption_adjust',
					'order',
					$order_id,
					sprintf( 'Ajuste del canje en el pedido #%d', $order_id ),
					false,
					$clave
				);
				if ( ! is_int( $acreditado ) ) {
					self::schedule_retry( $order_id );
					return;
				}

				$redeemed = (int) $order->get_meta( self::META_REDEEMED_POINTS );
				$order->delete_meta_data( self::META_PENDING_GIVEBACK );
				$order->update_meta_data( self::META_REDEEMED_POINTS, max( 0, $redeemed - $puntos ) );
				$order->update_meta_data( self::META_REDEEMED_VALUE, (float) self::granted_gross( $order ) );
				$order->add_order_note( sprintf( 'Puntos Akibara: se devolvieron los %d puntos que habían quedado pendientes del ajuste del canje.', $puntos ) );
				$order->save_meta_data();
			}
		);
	}

	/**
	 * Agenda un reintento en 5 minutos, uno a la vez y hasta MAX_RETRIES por
	 * pedido; agotados, deja nota y avisa al dueño.
	 *
	 * @param int    $order_id Pedido.
	 * @param int    $delay    Segundos hasta el reintento.
	 * @param string $motivo   '' o RETRY_IMPAGO. Van en los argumentos de la
	 *                         acción: con los mismos, un reintento de 5 minutos
	 *                         ya en la cola impedía agendar la revisión de la
	 *                         ventana de pago.
	 * @return void
	 */
	private static function schedule_retry( int $order_id, int $delay = 5 * MINUTE_IN_SECONDS, string $motivo = '' ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$args = '' === $motivo ? array( $order_id ) : array( $order_id, $motivo );

		if ( self::$in_retry ) {
			// Una corrida puede fallar en captura, re-cobro y devolución a la
			// vez: agenda UN reintento y gasta UN cupo.
			if ( self::$retry_failed ) {
				return;
			}
			self::$retry_failed = true;
		} elseif ( false !== as_next_scheduled_action( self::HOOK_RETRY, $args ) ) {
			return; // Ya hay uno en la cola.
		}

		$tries = (int) $order->get_meta( self::META_RETRIES );
		if ( $tries >= self::MAX_RETRIES ) {
			$order->add_order_note(
				sprintf( 'Puntos Akibara: tras %d reintentos no se pudo completar el movimiento de puntos canjeados de este pedido. Revisa el historial de puntos del cliente.', self::MAX_RETRIES )
			);

			/**
			 * Una operación de canje agotó sus reintentos. Las alertas al dueño
			 * escuchan acá.
			 *
			 * @param \WC_Order $order Pedido afectado.
			 */
			do_action( 'akb_rewards_redemption_stuck', $order );

			// Avisado el dueño, un fallo NUEVO y futuro vuelve a tener cupo: el
			// tope es para no insistir sobre el mismo problema, no para dejar el
			// pedido sin reintentos para siempre.
			$order->update_meta_data( self::META_RETRIES, 0 );
			$order->save_meta_data();
			return;
		}

		$order->update_meta_data( self::META_RETRIES, $tries + 1 );
		$order->save_meta_data();
		as_schedule_single_action( time() + $delay, self::HOOK_RETRY, $args );
	}

	/**
	 * Lo que WooCommerce espera a un pedido impago antes de anularlo
	 * (WooCommerce → Ajustes → Productos → Inventario → «Mantener en
	 * inventario»), con un piso de una hora.
	 *
	 * @return int Segundos.
	 */
	private static function unpaid_window(): int {
		return max( 60, (int) get_option( 'woocommerce_hold_stock_minutes', 60 ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Detiene la compra de un pedido cuyo descuento el saldo ya no cubre.
	 *
	 * Actúa sobre el MISMO objeto de pedido que maneja WooCommerce, porque tras
	 * la excepción la caja de bloques guarda ese objeto tal como está:
	 *   - Si el pedido salió de borrador en esta petición, retorna a borrador.
	 *   - En pedidos existentes se preserva el estado y se detiene el procesamiento de pago.
	 *
	 * @param \WC_Order $order    Pedido de WooCommerce.
	 * @param string    $checkout Contexto (ver capture_order()).
	 * @param bool      $ocupado  No faltó saldo: otra operación tenía tomados
	 *                            los puntos y el cliente sólo debe reintentar.
	 * @return void
	 * @throws \Exception Salvo en 'order_pay', donde el aviso de error basta.
	 */
	private function stop_without_balance( \WC_Order $order, string $checkout, bool $ocupado = false ): void {
		$borrador = 'store_api' === $checkout && isset( self::$left_draft[ (int) $order->get_id() ] );

		if ( $borrador ) {
			$order->update_status( 'checkout-draft' );
		} else {
			$order->add_order_note(
				$ocupado
					? 'Puntos Akibara: se detuvo un pago porque no se pudo confirmar el canje de puntos (otra operación los tenía tomados); el cliente puede volver a intentarlo.'
					: 'Puntos Akibara: se detuvo un pago porque el saldo del cliente ya no cubre el descuento de puntos de este pedido.'
			);
		}

		if ( $ocupado ) {
			$mensaje = self::MSG_OCUPADO;
		} else {
			$mensaje = $borrador || 'classic' === $checkout ? self::MSG_CAJA : self::MSG_PEDIDO;
		}

		if ( 'order_pay' === $checkout ) {
			wc_add_notice( esc_html( $mensaje ), 'error' );
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- literal fijo de la clase, escapado dentro de balance_changed().
		throw self::balance_changed( $checkout, $mensaje, $borrador, $ocupado ? 'akb_rewards_busy' : 'akb_rewards_balance_changed' );
	}

	/**
	 * El error que ve el cliente cuando su saldo ya no respalda el canje.
	 *
	 * En la caja de bloques viaja como error de la Store API y, si el cliente
	 * vuelve a la caja, con el carrito recalculado: el bloque lo recibe y pinta
	 * el total nuevo sin recargar.
	 *
	 * @param string $checkout    'store_api' o 'classic'.
	 * @param string $mensaje     Aviso para el cliente (literal de la clase).
	 * @param bool   $con_carrito Adjuntar el carrito recalculado.
	 * @param string $codigo      Código del error de la Store API.
	 * @return \Exception
	 */
	private static function balance_changed( string $checkout, string $mensaje, bool $con_carrito, string $codigo ): \Exception {
		$mensaje         = esc_html( $mensaje );
		$route_exception = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
		if ( 'store_api' !== $checkout || ! class_exists( $route_exception ) ) {
			return new \Exception( $mensaje );
		}

		return new $route_exception( $codigo, $mensaje, 409, $con_carrito ? self::fresh_cart_response() : array() );
	}

	/**
	 * El carrito recalculado con el saldo real, para la respuesta de error.
	 *
	 * @return array<string,mixed> ['cart' => …], o vacío si no se pudo armar.
	 */
	private static function fresh_cart_response(): array {
		try {
			if ( ! function_exists( 'WC' ) || ! isset( WC()->cart ) ) {
				return array();
			}

			// El débito releyó el saldo bajo candado, pero el carrito lo lee
			// por la caché de usermeta de ESTA petición, que aún tiene el viejo.
			wp_cache_delete( get_current_user_id(), 'user_meta' );
			WC()->cart->calculate_totals();

			$schema = \Automattic\WooCommerce\StoreApi\StoreApi::container()
				->get( \Automattic\WooCommerce\StoreApi\SchemaController::class )
				->get( 'cart' );

			return array( 'cart' => $schema->get_item_response( WC()->cart ) );
		} catch ( \Throwable $e ) {
			Log::error( 'No se pudo recalcular el carrito para el aviso de saldo: ' . $e->getMessage() );
			return array();
		}
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/Referrals.php
================================================================

<?php
/**
 * Referrals — Sistema de referidos («invita a un amigo»).
 *
 * Flujo:
 *   1. Cada cliente tiene un CÓDIGO estable (usermeta, generado una vez) y
 *     su enlace vive en Mi cuenta → Puntos Akibara.
 *   2. El invitado llega con ?ref=CODIGO → su navegador guarda la cookie
 *     (días configurables). La escribe un script, no PHP: ver enqueue_capture().
 *   3. Cuando el invitado crea cuenta, la cookie se sella en SU usermeta
 *     (la cookie muere, el vínculo queda — resistente a cambios de equipo).
 *   4. Cuando la PRIMERA compra del invitado ACREDITA (el mismo instante del
 *     bono de bienvenida: venta real, no registro), el que invitó cobra N
 *     puntos (configurable).
 *
 * Anti-abuso: no acredita por registrarse; impide
 * auto-referirse (código propio y mismo email se rechazan), UN premio por
 * invitado en la historia (event_key `referral_{invitado}` contra el UNIQUE
 * del ledger) y tope opcional de premios por padrino.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Referrals {

	public const META_CODE               = '_akb_rewards_ref_code';
	public const META_REFERRED_BY        = '_akb_rewards_referred_by';
	public const META_REFERRAL_ORDER     = '_akb_rewards_referral_order';
	public const META_REF_CODE           = '_akb_ref_code';
	public const COOKIE                  = 'akb_ref';

	public const AKB_REF_AMOUNT          = 2000;
	public const COUPON_PREFIX           = 'refreferido-';
	public const COUPON_PREFIX_SHORT     = 'ref-';

	/** Usermeta del invitado: motivos de premio no pagado que ya se anotaron. */
	private const META_NOT_PAID_NOTED = '_akb_rewards_referral_not_paid_noted';

	/**
	 * Cuelga captura, sellado y pago.
	 *
	 * @return void
	 */
	public function register(): void {
		// Pago y REVERSA cuelgan SIEMPRE, y el interruptor se consulta DENTRO
		// de maybe_pay_referrer: apagar el carril con premios ya pagados
		// desenganchaba también la reversa y dejaba al padrino con los puntos
		// de una venta anulada, para siempre. El pago apagado es un no-op
		// (reward <= 0); la reversa devuelve lo que este mismo carril pagó.
		//
		// El premio se asocia al estado del pedido pagado y se revierte en caso de anulación.
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 30, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 30, 2 );

		// Cupón virtual bilateral de referido ($2.000 desc. en compra >= $30.000)
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'fabricate_referral_coupon' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_referral_coupon' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_referral_checkout_antifraud' ), 10, 2 );

		$priority = function_exists( 'get_option' ) ? (int) get_option( 'akb_referral_auto_apply_priority', 1100 ) : 1100;
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'auto_apply_referral_coupon' ), $priority );

		if ( Options::referral_points() <= 0 ) {
			return; // Apagado: no se capta la visita, no se sella, no se promete.
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_capture' ) );
		add_action( 'woocommerce_created_customer', array( $this, 'seal_referred' ), 10, 1 );
		add_action( 'user_register', array( $this, 'seal_referred' ), 10, 1 );
		add_action( 'wp_login', array( $this, 'maybe_seal_on_login' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'seal_order_referral' ), 10, 1 );
		add_action( 'akb_rewards_account_extra', array( $this, 'render_invite_block' ) );
	}

	/**
	 * Código estable del cliente (se genera la primera vez que se pide).
	 *
	 * @param int $user_id Usuario.
	 * @return string
	 */
	public static function code_for( int $user_id ): string {
		$code = (string) get_user_meta( $user_id, self::META_CODE, true );
		if ( '' !== $code ) {
			return $code;
		}

		$code = strtolower( substr( wp_hash( 'akb-ref-' . $user_id . wp_salt( 'nonce' ) ), 0, 10 ) );
		update_user_meta( $user_id, self::META_CODE, $code );

		return $code;
	}

	/**
	 * ¿De quién es este código?
	 *
	 * @param string $code Código.
	 * @return int 0 si no existe.
	 */
	public static function owner_of( string $code ): int {
		if ( '' === $code ) {
			return 0;
		}

		$users = get_users(
			array(
				'meta_key'   => self::META_CODE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ids',
				'number'     => 1,
			)
		);

		return isset( $users[0] ) ? (int) $users[0] : 0;
	}

	/**
	 * Visita con ?ref= -> cookie, escrita en el navegador mediante JavaScript.
	 *
	 * Se ejecuta del lado del cliente para asegurar persistencia efectiva ante
	 * cachés de borde o CDN en la primera visita.
	 *
	 * La cookie no almacena secretos y se valida en el servidor en el momento
	 * de la creación de la cuenta del usuario referido.
	 *
	 * @return void
	 */
	public function enqueue_capture(): void {
		wp_register_script( 'akb-rewards-ref', false, array(), AKIBARA_REWARDS_VERSION, false );
		wp_enqueue_script( 'akb-rewards-ref' );
		wp_add_inline_script( 'akb-rewards-ref', self::capture_script( Options::referral_cookie_days() ) );
	}

	/**
	 * El script de captura: si la dirección trae `ref=` con un código posible,
	 * lo guarda en la cookie que lee el sellado.
	 *
	 * El patrón es el mismo filtro que aplicaba PHP (`sanitize_key` y hasta
	 * 20 caracteres): lo que no podría ser un código no se escribe.
	 *
	 * @param int $days Días que se recuerda la visita.
	 * @return string JavaScript autocontenido.
	 */
	public static function capture_script( int $days ): string {
		$min_cart = (int) get_option( 'akb_referral_min_cart_amount', get_option( 'akb_rewards_referral_min_order', 30000 ) );
		$min_fmt  = number_format_i18n( $min_cart );

		return '(function(){try{'
			. 'var m=/[?&]ref=([a-z0-9_-]{1,20})(?:&|$)/i.exec(window.location.search);'
			. 'if(!m){return;}'
			. 'document.cookie="' . self::COOKIE . '="+m[1].toLowerCase()+"; max-age=' . ( max( 1, $days ) * DAY_IN_SECONDS ) . '; path=/; SameSite=Lax"+("https:"===window.location.protocol?"; Secure":"");'
			. 'function toast(){'
			. 'if(typeof document.createElement!=="function"||!document.body||typeof window.sessionStorage==="undefined"||sessionStorage.getItem("akb_ref_toast")){return;}'
			. 'var t=document.createElement("div");t.className="akb-ref-toast";'
			. 't.innerHTML="<span>\u{1F91D} <strong>\u00A1Bienvenido a Akibara!</strong> Tu amigo te invit\u00F3 con $2.000 de regalo en tu primera compra desde $' . $min_fmt . '.</span> <a href=\"/mi-cuenta/\" style=\"margin-left:8px;color:#ffffff;text-decoration:underline;font-weight:700\">Crear cuenta</a> <button type=\"button\" style=\"background:none;border:none;color:#fff;font-size:18px;cursor:pointer;margin-left:6px;min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;line-height:1\" aria-label=\"Cerrar\">\u00D7</button>";'
			. 't.style.cssText="position:fixed;bottom:calc(var(--akb-franja-inferior, 66px) + 16px);right:20px;z-index:var(--z-toast, 500);background:#181818;border:2px solid #121212;border-left:4px solid #E60012;color:#fff;padding:8px 12px 8px 16px;border-radius:0;box-shadow:4px 4px 0 #121212;font-size:13px;display:flex;align-items:center;line-height:1.4;max-width:calc(100% - 40px);font-family:inherit;";'
			. 't.querySelector("button").onclick=function(){t.remove();try{sessionStorage.setItem("akb_ref_toast","1");}catch(e){}};'
			. 'document.body.appendChild(t);'
			. '}'
			. 'if(typeof document!=="undefined"){'
			. 'if(document.readyState==="loading"){'
			. 'if(typeof document.addEventListener==="function"){document.addEventListener("DOMContentLoaded",toast);}'
			. '}else{toast();}'
			. '}'
			. '}catch(e){}})();';
	}


	/**
	 * Al crear la cuenta, la cookie se sella en la usermeta del invitado.
	 * La cookie es efímera; el vínculo, permanente.
	 *
	 * @param int $user_id Invitado recién creado.
	 * @return void
	 */
	public function seal_referred( $user_id ): void {
		$user_id = (int) $user_id;

		if ( '' !== (string) get_user_meta( $user_id, self::META_REFERRED_BY, true ) ) {
			return; // Ya sellado (created_customer y user_register pueden co-disparar).
		}

		$code = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		$referrer = self::owner_of( $code );
		if ( $referrer <= 0 || $referrer === $user_id ) {
			return; // Código inexistente o auto-referencia.
		}

		$invitado = get_user_by( 'id', $user_id );
		$padrino  = get_user_by( 'id', $referrer );
		if ( $invitado instanceof \WP_User && $padrino instanceof \WP_User
			&& '' !== (string) $invitado->user_email
			&& strtolower( (string) $invitado->user_email ) === strtolower( (string) $padrino->user_email ) ) {
			return; // Mismo email: auto-referencia disfrazada.
		}

		update_user_meta( $user_id, self::META_REFERRED_BY, $referrer );
	}

	/**
	 * Si un usuario que ya tenía cuenta pero NUNCA ha comprado llega con cookie de referido y se loguea,
	 * queda sellado con su padrino para no perder la atribución.
	 *
	 * @param string   $user_login Login.
	 * @param \WP_User $user       Usuario autenticado.
	 * @return void
	 */
	public function maybe_seal_on_login( $user_login, $user ): void {
		unset( $user_login );
		if ( ! $user instanceof \WP_User || (int) $user->ID <= 0 ) {
			return;
		}

		$user_id = (int) $user->ID;
		if ( '' !== (string) get_user_meta( $user_id, self::META_REFERRED_BY, true ) ) {
			return; // Ya sellado anteriormente.
		}

		if ( ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return; // Sin cookie de referido activa.
		}

		// Si ya compró antes, no es un invitado nuevo
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'limit'       => 1,
					'return'      => 'ids',
				)
			);
			if ( ! empty( $orders ) ) {
				return;
			}
		}

		$this->seal_referred( $user_id );
	}

	/**
	 * Sella el padrino en los metadatos del pedido al momento del checkout si la cookie está activa.
	 *
	 * @param \WC_Order $order Pedido recién creado.
	 * @return void
	 */
	public function seal_order_referral( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$code = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		$referrer = self::owner_of( $code );
		if ( $referrer <= 0 ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id === $referrer ) {
			return; // Auto-referencia.
		}

		if ( $customer_id > 0 && '' === (string) get_user_meta( $customer_id, self::META_REFERRED_BY, true ) ) {
			$this->seal_referred( $customer_id );
		}

		$order->update_meta_data( self::META_REFERRED_BY, $referrer );
		$order->update_meta_data( self::META_REF_CODE, $code );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * Fabrica el cupón virtual de referido ($2.000 CLP, compra mínima $25.000).
	 *
	 * @param array|false $data        Datos de cupón existentes o false.
	 * @param string      $coupon_code Código de cupón ingresado.
	 * @return array|false
	 */
	public function fabricate_referral_coupon( $data, $coupon_code ) {
		if ( ! is_string( $coupon_code ) || '' === trim( $coupon_code ) ) {
			return $data;
		}

		$raw_code = strtolower( trim( $coupon_code ) );
		$ref_code = '';

		if ( 0 === strpos( $raw_code, self::COUPON_PREFIX ) ) {
			$ref_code = substr( $raw_code, strlen( self::COUPON_PREFIX ) );
		} elseif ( 0 === strpos( $raw_code, self::COUPON_PREFIX_SHORT ) ) {
			$ref_code = substr( $raw_code, strlen( self::COUPON_PREFIX_SHORT ) );
		} else {
			$owner = self::owner_of( $raw_code );
			if ( $owner > 0 ) {
				$ref_code = $raw_code;
			}
		}

		if ( '' === $ref_code ) {
			return $data;
		}

		$referrer_id = self::owner_of( $ref_code );
		if ( $referrer_id <= 0 ) {
			return $data;
		}

		$amount    = function_exists( 'get_option' ) ? (float) get_option( 'akb_referral_guest_discount_amount', self::AKB_REF_AMOUNT ) : (float) self::AKB_REF_AMOUNT;
		$min_order = function_exists( 'get_option' ) ? (float) get_option( 'akb_referral_min_cart_amount', 30000 ) : 30000.0;

		return array(
			'id'                         => -1,
			'code'                       => $raw_code,
			'amount'                     => $amount,
			'discount_type'              => 'fixed_cart',
			'description'                => 'Descuento de bienvenida por recomendación de amigo en Akibara',
			'free_shipping'              => false,
			'minimum_amount'             => $min_order,
			'maximum_amount'             => 0,
			'individual_use'             => true,
			'exclude_sale_items'         => false,
			'product_ids'                => array(),
			'exclude_product_ids'        => array(),
			'product_categories'         => array(),
			'exclude_product_categories' => array(),
			'usage_limit'                => 0,
			'usage_limit_per_user'       => 1,
			'limit_usage_to_x_items'     => null,
			'usage_count'                => 0,
			'expiry_date'                => null,
		);
	}

	/**
	 * Valida que el cupón de referido no se combine con puntos, no sea auto-referencia y sea primera compra.
	 *
	 * @param bool       $is_valid Cupón válido hasta ahora.
	 * @param \WC_Coupon $coupon   Cupón.
	 * @return bool
	 * @throws \Exception Si incumple alguna condición.
	 */
	public function validate_referral_coupon( $is_valid, $coupon ): bool {
		if ( ! $is_valid || ! $coupon instanceof \WC_Coupon ) {
			return (bool) $is_valid;
		}

		$raw_code = strtolower( $coupon->get_code() );
		$ref_code = '';
		if ( 0 === strpos( $raw_code, self::COUPON_PREFIX ) ) {
			$ref_code = substr( $raw_code, strlen( self::COUPON_PREFIX ) );
		} elseif ( 0 === strpos( $raw_code, self::COUPON_PREFIX_SHORT ) ) {
			$ref_code = substr( $raw_code, strlen( self::COUPON_PREFIX_SHORT ) );
		} else {
			$owner = self::owner_of( $raw_code );
			if ( $owner > 0 ) {
				$ref_code = $raw_code;
			}
		}

		if ( '' === $ref_code ) {
			return true;
		}

		$referrer_id = self::owner_of( $ref_code );
		if ( $referrer_id <= 0 ) {
			return true;
		}

		// 1. Incompatibilidad con canje de puntos
		if ( Redemption::intent_points() > 0 ) {
			throw new \Exception( 'Puedes elegir entre usar tu cupón de referido o canjear tus Puntos Akibara (no se pueden combinar en un mismo pedido).' );
		}

		$user_id = get_current_user_id();

		// 2. Auto-referencia
		if ( $user_id > 0 && $user_id === $referrer_id ) {
			throw new \Exception( '¡Ese es tu propio código de invitación! No puedes usar tu propio código de referido. Compártelo con tus amigos para que ellos reciban su descuento.' );
		}

		// 3. Solo usuarios sin pedidos previos
		if ( $user_id > 0 && function_exists( 'wc_get_customer_order_count' ) ) {
			if ( wc_get_customer_order_count( $user_id ) > 0 ) {
				throw new \Exception( 'Este cupón de invitación es exclusivo para nuevos lectores en su primera compra.' );
			}
		}

		// 4. Validar suma de líneas elegibles antes de descuento del propio cupón:
		$min_order = function_exists( 'get_option' ) ? (float) get_option( 'akb_referral_min_cart_amount', 30000 ) : 30000.0;

		if ( function_exists( 'WC' ) && WC()->cart && method_exists( WC()->cart, 'get_cart' ) ) {
			$eligible_sum = self::eligible_cart_subtotal( WC()->cart );

			if ( $eligible_sum < $min_order ) {
				$diff = $min_order - $eligible_sum;
				throw new \Exception( sprintf( 'El cupón de invitación requiere un mínimo de $%s CLP en productos elegibles (te faltan $%s CLP).', number_format( $min_order, 0, ',', '.' ), number_format( $diff, 0, ',', '.' ) ) );
			}
		}

		return true;
	}

	/**
	 * Calcula el subtotal elegible del carrito (pre-cupón) para evaluar el piso del cupón de referido.
	 *
	 * Usa `line_subtotal` (+ impuesto) o `precio * cantidad` para que aplicar el propio cupón de $2.000
	 * no reduzca la base por debajo de $30.000 en el segundo pase de `calculate_totals()`.
	 *
	 * @param object $cart Carrito (WC_Cart).
	 * @return float
	 */
	public static function eligible_cart_subtotal( object $cart ): float {
		if ( ! method_exists( $cart, 'get_cart' ) ) {
			return 0.0;
		}

		$eligible_sum = 0.0;
		foreach ( $cart->get_cart() as $cart_item ) {
			$p = $cart_item['data'] ?? null;
			if ( ! $p instanceof \WC_Product ) {
				continue;
			}

			$qty = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
			if ( isset( $cart_item['line_subtotal'] ) ) {
				$line_sub = (float) $cart_item['line_subtotal'] + (float) ( $cart_item['line_subtotal_tax'] ?? 0.0 );
			} elseif ( isset( $cart_item['line_total'] ) ) {
				$line_sub = (float) $cart_item['line_total'] + (float) ( $cart_item['line_tax'] ?? 0.0 );
			} else {
				$line_sub = (float) $p->get_price() * $qty;
			}

			$is_sale = method_exists( $p, 'is_on_sale' ) && $p->is_on_sale();

			if ( ! $is_sale ) {
				$eligible_sum += $line_sub;
			}
		}

		return $eligible_sum;
	}

	/**
	 * Validación antifraude estricta en el checkout (RUT, teléfono, correo contra el padrino y pedidos previos).
	 *
	 * @param array     $data   Datos del checkout.
	 * @param \WP_Error $errors Objeto de errores.
	 * @return void
	 */
	public function validate_referral_checkout_antifraud( array $data, $errors ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! $errors instanceof \WP_Error ) {
			return;
		}

		$has_ref_coupon = false;
		$referrer_id    = 0;
		if ( method_exists( WC()->cart, 'get_applied_coupons' ) ) {
			foreach ( WC()->cart->get_applied_coupons() as $coupon_code ) {
				$raw_code = strtolower( $coupon_code );
				$ref_code = '';
				if ( 0 === strpos( $raw_code, self::COUPON_PREFIX ) ) {
					$ref_code = substr( $raw_code, strlen( self::COUPON_PREFIX ) );
				} elseif ( 0 === strpos( $raw_code, self::COUPON_PREFIX_SHORT ) ) {
					$ref_code = substr( $raw_code, strlen( self::COUPON_PREFIX_SHORT ) );
				} else {
					$owner = self::owner_of( $raw_code );
					if ( $owner > 0 ) {
						$ref_code = $raw_code;
					}
				}
				if ( '' !== $ref_code ) {
					$owner = self::owner_of( $ref_code );
					if ( $owner > 0 ) {
						$has_ref_coupon = true;
						$referrer_id    = $owner;
						break;
					}
				}
			}
		}

		if ( ! $has_ref_coupon || $referrer_id <= 0 ) {
			return;
		}

		$padrino = get_user_by( 'id', $referrer_id );
		if ( ! $padrino instanceof \WP_User ) {
			return;
		}

		$email = strtolower( trim( (string) ( $data['billing_email'] ?? '' ) ) );
		$phone = preg_replace( '/\D+/', '', (string) ( $data['billing_phone'] ?? '' ) );
		$rut   = preg_replace( '/[^0-9kK]/', '', (string) ( $data['billing_rut'] ?? ( $data['_billing_rut'] ?? '' ) ) );

		// Comprobar cruce con el padrino
		$padrino_email = strtolower( trim( (string) $padrino->user_email ) );
		$padrino_phone = preg_replace( '/\D+/', '', (string) get_user_meta( $referrer_id, 'billing_phone', true ) );
		$padrino_rut   = preg_replace( '/[^0-9kK]/', '', (string) ( get_user_meta( $referrer_id, 'billing_rut', true ) ?: get_user_meta( $referrer_id, '_billing_rut', true ) ) );

		if ( '' !== $email && $email === $padrino_email ) {
			$errors->add( 'akb_ref_self_email', 'El cupón de invitación no se puede aplicar con el correo de quien invita. ¡Compártelo con un amigo!' );
			return;
		}
		if ( '' !== $phone && '' !== $padrino_phone && $phone === $padrino_phone ) {
			$errors->add( 'akb_ref_self_phone', 'El cupón de invitación no se puede aplicar con el teléfono de quien invita. ¡Compártelo con un amigo!' );
			return;
		}
		if ( '' !== $rut && '' !== $padrino_rut && strtolower( $rut ) === strtolower( $padrino_rut ) ) {
			$errors->add( 'akb_ref_self_rut', 'El cupón de invitación no se puede aplicar con el RUT de quien invita. ¡Compártelo con un amigo!' );
			return;
		}

		// Comprobar si esta persona ya registra compras previas en la base de datos (por email, teléfono o RUT)
		if ( function_exists( 'wc_get_orders' ) ) {
			if ( '' !== $email ) {
				$orders = wc_get_orders( array( 'billing_email' => $email, 'limit' => 1, 'return' => 'ids' ) );
				if ( ! empty( $orders ) ) {
					$errors->add( 'akb_ref_prior_purchase', 'Este cupón de invitación es exclusivo para nuevos lectores en su primera compra.' );
					return;
				}
			}
			if ( '' !== $phone && strlen( $phone ) >= 8 ) {
				$orders = wc_get_orders( array( 'billing_phone' => (string) ( $data['billing_phone'] ?? '' ), 'limit' => 1, 'return' => 'ids' ) );
				if ( ! empty( $orders ) ) {
					$errors->add( 'akb_ref_prior_purchase_phone', 'Este cupón de invitación es exclusivo para nuevos lectores en su primera compra.' );
					return;
				}
			}
			if ( '' !== $rut ) {
				$raw_rut = trim( (string) ( $data['billing_rut'] ?? ( $data['_billing_rut'] ?? '' ) ) );
				$orders  = wc_get_orders(
					array(
						'meta_key'   => '_billing_rut',
						'meta_value' => $raw_rut,
						'limit'      => 1,
						'return'     => 'ids',
					)
				);
				if ( ! empty( $orders ) ) {
					$errors->add( 'akb_ref_prior_purchase_rut', 'Este cupón de invitación es exclusivo para nuevos lectores en su primera compra.' );
					return;
				}
			}
		}
	}

	/**
	 * Auto-aplica el cupón de referido si el cliente tiene la cookie akb_ref,
	 * cumple el mínimo en productos elegibles y no tiene otros cupones ni canje.
	 *
	 * @param \WC_Cart $cart Carrito.
	 * @return void
	 */
	public function auto_apply_referral_coupon( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		$cookie_val = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';

		if ( '' === $cookie_val ) {
			return;
		}

		$code = $cookie_val;

		if ( '' === $code ) {
			return;
		}

		$referrer_id = self::owner_of( $code );
		if ( $referrer_id <= 0 ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 && $user_id === $referrer_id ) {
			return;
		}

		if ( $user_id > 0 && function_exists( 'wc_get_customer_order_count' ) && wc_get_customer_order_count( $user_id ) > 0 ) {
			return;
		}

		if ( method_exists( $cart, 'get_applied_coupons' ) && ! empty( $cart->get_applied_coupons() ) ) {
			return;
		}

		if ( Redemption::intent_points() > 0 ) {
			return;
		}

		$coupon_code = self::COUPON_PREFIX . $code;
		if ( method_exists( $cart, 'has_discount' ) && ( $cart->has_discount( $coupon_code ) || $cart->has_discount( self::COUPON_PREFIX_SHORT . $code ) ) ) {
			return;
		}

		$min_order    = function_exists( 'get_option' ) ? (float) get_option( 'akb_referral_min_cart_amount', 30000 ) : 30000.0;
		$eligible_sum = self::eligible_cart_subtotal( $cart );

		if ( $eligible_sum >= $min_order && method_exists( $cart, 'apply_coupon' ) ) {
			$cart->apply_coupon( $coupon_code );
		}
	}

	/**
	 * Transición de estado de un pedido: pagar al padrino o revertirle.
	 *
	 * @param int       $order_id ID del pedido.
	 * @param string    $from     Estado anterior (sin wc-).
	 * @param string    $to       Estado nuevo (sin wc-).
	 * @param \WC_Order $order    Pedido.
	 * @return void
	 */
	public function on_status_changed( $order_id, $from, $to, $order ): void {
		unset( $order_id, $from );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Aplica al estado configurado o a cualquier estado pagado posterior.
		if ( OrderStates::is_at_or_after( (string) $to, Options::referral_status() ) ) {
			$this->maybe_pay_referrer( $order, (int) $order->get_customer_id() );
			return;
		}

		if ( OrderStates::is_down( (string) $to ) ) {
			$this->maybe_reverse_referrer( $order, (int) $order->get_customer_id() );
		}
	}

	/**
	 * Un reembolso que devuelve TODO el dinero anula la compra que pagó el
	 * premio: se revierte igual que una cancelación. Uno parcial no toca el
	 * premio, como tampoco toca el bono de bienvenida.
	 *
	 * @param int $order_id  ID del pedido.
	 * @param int $refund_id ID del reembolso (no usado).
	 * @return void
	 */
	public function on_order_refunded( $order_id, $refund_id ): void {
		unset( $refund_id );

		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( (float) $order->get_total() - (float) $order->get_total_refunded() > 0.01 ) {
			return;
		}

		$this->maybe_reverse_referrer( $order, (int) $order->get_customer_id() );
	}

	/**
	 * La primera compra del invitado paga al padrino cuando entra al estado configurado.
	 *
	 * Aplica una sola vez por persona: si el RUT, teléfono o correo del invitado
	 * coinciden con compras previas o con los del padrino, no se acredita.
	 *
	 * @param \WC_Order $order   Pedido del invitado.
	 * @param int       $user_id Invitado.
	 * @return void
	 */
	public function maybe_pay_referrer( $order, $user_id ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$user_id  = (int) $user_id;
		$referrer = $user_id > 0 ? (int) get_user_meta( $user_id, self::META_REFERRED_BY, true ) : 0;
		if ( $referrer <= 0 ) {
			return;
		}

		$reward = Options::referral_points();
		if ( $reward <= 0 ) {
			return; // Carril apagado (o monto en 0): nada que pagar.
		}

		// Premio VIVO: este invitado ya pagó uno y no se ha revertido (o la
		// reversa quedó corta y la marca se conservó a propósito). Sin esta
		// puerta, la clave por ocurrencia de más abajo pagaría otro premio
		// sobre la misma relación.
		if ( (int) get_user_meta( $user_id, self::META_REFERRAL_ORDER, true ) > 0 ) {
			return;
		}

		$motivo = self::reason_not_to_pay( $order, $referrer );
		if ( null !== $motivo ) {
			self::note_not_paid( $order, $referrer, $motivo[0], $motivo[1] );
			return;
		}

		$max          = Options::referral_max_per_user();
		$moved_before = Ledger::count_movements( 'user', $user_id, 'referral' );

		// Clave por OCURRENCIA: si el pedido que pagó el premio se cayó y la
		// reversa lo retiró, la SIGUIENTE compra acreditada del invitado
		// vuelve a ser una «primera compra real» y re-paga (mismo patrón que
		// award_{order}_{n} en Accrual).
		$reversals = Ledger::count_movements( 'user', $user_id, 'referral_reversal' );
		$event_key = $reversals > 0
			? sprintf( 'referral_%d_r%d', $user_id, $reversals )
			: sprintf( 'referral_%d', $user_id );

		// El tope se cuenta BAJO EL CANDADO del padrino, junto al crédito: dos
		// invitados suyos completando a la vez leían los dos «9 de 10» y pagaban
		// los dos (en producción el tope vale 10). Cuenta los premios VIGENTES:
		// uno que se le descontó porque el invitado anuló la compra no ocupa cupo.
		$result = null;
		$topado = false;
		Ledger::with_user_lock(
			$referrer,
			static function () use ( $referrer, $reward, $user_id, $order, $event_key, $max, &$result, &$topado ): void {
				if ( $max > 0 && Ledger::count_live_referrals( $referrer ) >= $max ) {
					$topado = true;
					return; // El padrino alcanzó su tope de premios por invitar.
				}

				$result = Ledger::credit(
					$referrer,
					$reward,
					'referral',
					'user',
					$user_id,
					sprintf( 'Invitado que compró (pedido #%d)', (int) $order->get_id() ),
					true,
					$event_key
				);
			}
		);

		if ( $topado ) {
			self::note_not_paid( $order, $referrer, 'tope', sprintf( 'ya tiene %d premios por invitar, el tope del programa', $max ) );
			return;
		}

		if ( ! is_int( $result ) ) {
			/**
			 * El premio no se pudo acreditar (candado ocupado o fallo del libro).
			 * Un pedido Completado casi nunca vuelve a cambiar de estado: sin
			 * este aviso el premio se perdía en silencio. Las alertas escuchan acá.
			 *
			 * @param \WC_Order $order    Pedido del invitado.
			 * @param int       $referrer Padrino.
			 * @param int       $reward   Puntos que debía cobrar.
			 */
			do_action( 'akb_rewards_referral_pay_failed', $order, $referrer, $reward );
			return;
		}

		if ( Ledger::count_movements( 'user', $user_id, 'referral' ) <= $moved_before ) {
			return; // Duplicado (revival/re-disparo): sin movimiento no hay hook.
		}

		// Vinculación del pedido que originó el beneficio para trazabilidad y eventuales reversas.
		update_user_meta( $user_id, self::META_REFERRAL_ORDER, (int) $order->get_id() );
		$order->add_order_note(
			sprintf( 'Puntos Akibara: se pagaron %d Puntos Akibara a quien invitó a este cliente (cuenta #%d).', $reward, $referrer )
		);

		/**
		 * Referido pagado. El correo al padrino escucha acá.
		 *
		 * @param int       $referrer Padrino premiado.
		 * @param int       $user_id  Invitado que compró.
		 * @param int       $reward   Puntos pagados.
		 * @param \WC_Order $order    Pedido que justificó el premio.
		 */
		do_action( 'akb_rewards_referral_paid', $referrer, $user_id, $reward, $order );
	}

	/**
	 * Por qué esta compra no paga el premio al padrino, en palabras del dueño.
	 *
	 * @param \WC_Order $order    Pedido del invitado.
	 * @param int       $referrer Padrino.
	 * @return array{0:string,1:string}|null [clave, motivo], o null si nada lo impide.
	 */
	private static function reason_not_to_pay( \WC_Order $order, int $referrer ): ?array {
		if ( ! Accrual::in_program( $order ) ) {
			return array( 'programa', 'el pedido es anterior al inicio del programa de puntos' );
		}

		if ( ! FirstPurchase::meets_minimum( $order ) ) {
			$minimo = Options::first_purchase_min();
			return array(
				'minimo',
				$minimo > 0
					? sprintf( 'la compra no llega al mínimo de $%s en productos', number_format_i18n( $minimo ) )
					: 'el pedido no tuvo pago con dinero en productos',
			);
		}

		if ( FirstPurchase::same_person( $order, $referrer ) ) {
			return array( 'misma_persona', 'el cliente comparte RUT, teléfono o correo con quien lo invitó' );
		}

		if ( FirstPurchase::has_prior_purchase( $order ) ) {
			return array( 'no_primera', 'no es la primera compra de esta persona: su RUT, teléfono o correo ya aparece en una compra anterior' );
		}

		return null;
	}

	/**
	 * Registra nota explicativa en el pedido ante rechazo de acreditación,
	 * deduplicando por motivo e invitado para evitar redundancia en compras posteriores.
	 *
	 * @param \WC_Order $order    Pedido del invitado.
	 * @param int       $referrer Padrino.
	 * @param string    $clave    Motivo, como clave estable.
	 * @param string    $motivo   Motivo legible, en minúscula y sin punto final.
	 * @return void
	 */
	private static function note_not_paid( \WC_Order $order, int $referrer, string $clave, string $motivo ): void {
		$invitado = (int) $order->get_customer_id();
		$anotados = get_user_meta( $invitado, self::META_NOT_PAID_NOTED, true );
		$anotados = is_array( $anotados ) ? $anotados : array();
		if ( in_array( $clave, $anotados, true ) ) {
			return;
		}

		$order->add_order_note(
			sprintf( 'Puntos Akibara: no se pagó el premio a quien invitó a este cliente (cuenta #%d) porque %s.', $referrer, $motivo )
		);
		$anotados[] = $clave;
		update_user_meta( $invitado, self::META_NOT_PAID_NOTED, $anotados );
	}

	/**
	 * Si el pedido que PAGÓ el premio de referido se anula por completo, el
	 * premio del padrino se revierte (débito clampado) y el invitado vuelve a
	 * ser elegible: venta real o nada, también hacia atrás.
	 *
	 * @param \WC_Order $order   Pedido anulado del invitado.
	 * @param int       $user_id Invitado.
	 * @return void
	 */
	public function maybe_reverse_referrer( $order, $user_id ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$user_id = (int) $user_id;
		if ( (int) get_user_meta( $user_id, self::META_REFERRAL_ORDER, true ) !== (int) $order->get_id() ) {
			return; // Este pedido no fue el que pagó el premio.
		}

		$referrer = (int) get_user_meta( $user_id, self::META_REFERRED_BY, true );
		if ( $referrer <= 0 ) {
			return;
		}

		// Neto vivo del premio (créditos − reversas anteriores): nunca se
		// revierte dos veces ni más de lo pagado.
		$net = (int) Ledger::sum_movements( 'user', $user_id, array( 'referral', 'referral_reversal' ) );
		if ( $net <= 0 ) {
			return;
		}

		$reversals = Ledger::count_movements( 'user', $user_id, 'referral_reversal' );
		$result    = Ledger::debit(
			$referrer,
			$net,
			'referral_reversal',
			'user',
			$user_id,
			sprintf( 'Reversa de referido: el pedido #%d del invitado se anuló', (int) $order->get_id() ),
			false,
			sprintf( 'referral_reversal_%d_%d', $user_id, $reversals )
		);

		if ( ! is_int( $result ) ) {
			return; // Infraestructura: la próxima transición del pedido reintenta.
		}

		if ( $result < $net ) {
			// Débito CLAMPADO: el padrino ya se gastó los puntos y sólo se
			// recuperó parte. Devolver la elegibilidad aquí pagaría el premio
			// otra vez con una clave de ocurrencia nueva, y la tienda acabaría
			// regalando más de lo que recuperó por una venta que no existió
			// (mismo cierre que WelcomeBonus::maybe_restore_eligibility).
			$order->add_order_note(
				sprintf(
					'Puntos Akibara: el premio al padrino (%d) no se recuperó completo en la reversa (sólo %d); el invitado NO vuelve a ser elegible.',
					$net,
					$result
				)
			);

			/**
			 * Una reversa de referido quedó corta. Las alertas del panel escuchan acá.
			 *
			 * @param \WC_Order $order    Pedido anulado del invitado.
			 * @param int       $faltante Puntos que no se pudieron recuperar.
			 */
			do_action( 'akb_rewards_reversal_failed', $order, $net - $result );
			return;
		}

		// El invitado queda elegible otra vez: su próxima compra acreditada
		// vuelve a pagar (clave de ocurrencia nueva).
		delete_user_meta( $user_id, self::META_REFERRAL_ORDER );
	}

	/**
	 * Bloque «Invita a un amigo» en el endpoint de Mi cuenta.
	 *
	 * @param int $user_id Usuario dueño de la pantalla.
	 * @return void
	 */
	public function render_invite_block( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		// Fuera de vigencia la compra del invitado no paga al padrino
		// (`reason_not_to_pay()`): la invitación no se ofrece hasta que rija.
		$premio = Promise::referral_points();
		if ( $premio <= 0 ) {
			return;
		}

		$code    = self::code_for( $user_id );
		$enlace  = add_query_arg( 'ref', $code, home_url( '/' ) );
		$pagados = Ledger::count_live_referrals( $user_id );

		$min_cart   = (int) get_option( 'akb_rewards_referral_min_order', 30000 );
		$min_fmt    = number_format_i18n( $min_cart );
		$premio_fmt = number_format_i18n( $premio );
		$premio_clp = number_format_i18n( $premio * Options::redeem_value() );

		$wa_text = sprintf(
			'¡Hola! Te comparto mi invitación a Akibara Manga. Tienes $2.000 de regalo en tu primera compra desde $%s en mangas y cómics con este enlace: %s',
			$min_fmt,
			$enlace
		);
		$wa_url = 'https://api.whatsapp.com/send?text=' . rawurlencode( $wa_text );

		printf(
			'<div class="akb-rw-invite-card" style="margin:28px 0;padding:20px;border:1px solid #333;border-radius:4px;background:#181818;color:#f0f0f0">
				<h3 style="margin:0 0 8px;font-size:18px;color:#fff">Invita a tus amigos lectores 🤝</h3>
				<p style="margin:0 0 12px;font-size:14px;line-height:1.5;color:#ccc">Comparte tu enlace: tu amigo recibe <strong>$2.000 de regalo</strong> en su primera compra (desde $%s en mangas a precio normal) y tú ganas <strong>%s Puntos Akibara ($%s CLP)</strong> cuando reciba su pedido%s. Válido para amigos nuevos (no aplica para la misma persona, RUT, teléfono o correo).</p>
				<div class="akb-rw-ref-box" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
					<input type="text" class="akb-rw-ref-input" readonly value="%s" style="flex:1 1 280px;min-height:40px;font-family:monospace;font-size:13px;background:#101010;color:#fff;padding:0 12px;border:1px solid #444;border-radius:3px" aria-label="Tu enlace de referido" onfocus="this.select()">
					<button type="button" class="button button-primary akb-rw-copy-btn" data-akb-copy-url="%s" style="background:var(--aki-buy-bg,#CC0010);border-color:var(--aki-buy-bg,#CC0010);color:#fff;min-height:40px;display:inline-flex;align-items:center;gap:6px;font-weight:700">📋 Copiar enlace</button>
					<a class="button button-primary akb-rw-wa-btn" style="background:#075E54;border-color:#128C7E;color:#FFFFFF;min-height:40px;display:inline-flex;align-items:center;gap:6px;font-weight:700;text-decoration:none" href="%s" target="_blank" rel="noopener noreferrer">💬 Compartir por WhatsApp</a>
					<button type="button" class="button button-secondary akb-rw-share-btn" data-akb-share-url="%s" data-akb-share-title="Akibara Manga" data-akb-share-text="%s" style="display:none;min-height:40px">🔗 Más opciones...</button>
				</div>
				<p style="margin:0;font-size:12px;color:#888"><code style="user-select:all;display:none">%s</code>Tu código de invitación: <strong style="color:#fff">%s</strong>%s</p>
			</div>',
			esc_html( $min_fmt ),
			esc_html( $premio_fmt ),
			esc_html( $premio_clp ),
			$pagados > 0 ? esc_html( sprintf( ' (ya llevas %d)', $pagados ) ) : '',
			esc_attr( $enlace ),
			esc_attr( $enlace ),
			esc_url( $wa_url ),
			esc_attr( $enlace ),
			esc_attr( $wa_text ),
			esc_url( $enlace ),
			esc_html( $code ),
			$pagados > 0 ? sprintf( ' · <strong style="color:#fff">%d</strong> amigos ya compraron con tu enlace', (int) $pagados ) : ''
		);

	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Points/WelcomeBonus.php
================================================================

<?php
/**
 * WelcomeBonus — bono de bienvenida pagado sobre la PRIMERA compra acreditada.
 *
 * Diseño heredado de akibara-points-welcome.php (probado en prod):
 *   - NUNCA por registro: el signup bonus gratuito lo reclamaban
 *     retroactivamente las 58 cuentas existentes ($29.000 sin venta).
 *   - Pagado al acreditar la primera compra: el costo aparece tras venta real
 *     y el saldo empuja la SEGUNDA compra.
 *   - Idempotencia: usermeta con el pedido pagador, RE-LEÍDA bajo el candado
 *     de usuario (dos pedidos concurrentes del mismo cliente) + event_key
 *     único por usuario-pedido en el ledger.
 *   - El bono SE SUMA a la meta earned del pedido a propósito: así las
 *     anulaciones lo arrastran sin lógica extra.
 *   - Si el pedido pagador se anula, el cliente recupera la elegibilidad
 *     únicamente si la reversa recuperó efectivamente el monto del bono acreditado,
 *     previniendo doble beneficio si el saldo ya había sido consumido previamente.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class WelcomeBonus {

	public const META_WELCOME_PAID = '_akb_rewards_welcome_paid';

	/** Sello de «ya se dejó dicho en el pedido por qué no hubo bono». */
	public const META_SKIP_NOTED = '_akb_rewards_welcome_skip_noted';

	/**
	 * Cuelga los hooks alrededor de la acreditación.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'akb_rewards_after_order_award', array( $this, 'maybe_pay' ), 10, 3 );
		add_action( 'akb_rewards_after_order_deduct', array( $this, 'maybe_restore_eligibility' ), 10, 3 );
		// La ventana de caída del bono (crédito insertado, marca sin escribir)
		// solo puede sanarse si la conciliación VUELVE a correr — y
		// after_order_award no se re-dispara en pedidos ya acreditados. El
		// camino already_done sí (cada re-transición al estado de
		// acreditación). Prio 5: antes del reenvío de correo (10), que lee la
		// meta earned que esta conciliación repara.
		add_action( 'akb_rewards_award_already_done', array( $this, 'reconcile_from_already_done' ), 5, 1 );
		add_action( 'woocommerce_register_form', array( $this, 'render_register_teaser' ), 25 );
	}

	/**
	 * Reintento de conciliación para pedidos ya acreditados: si el crédito
	 * del bono quedó huérfano en el ledger (fatal antes de sellar la marca),
	 * maybe_pay lo detecta y sella sin re-crédito.
	 *
	 * @param \WC_Order $order Pedido ya acreditado.
	 * @return void
	 */
	public function reconcile_from_already_done( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->maybe_pay( $order, (int) $order->get_customer_id(), 0 );
	}

	/**
	 * Paga el bono si esta es la primera compra acreditada del cliente.
	 *
	 * @param \WC_Order $order   Pedido recién acreditado.
	 * @param int       $user_id Cliente.
	 * @param int       $points  Puntos de la compra (no usados).
	 * @return void
	 */
	public function maybe_pay( $order, $user_id, $points ): void {
		unset( $points );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$bonus = Options::welcome_bonus();
		if ( $bonus <= 0 ) {
			return;
		}

		$user_id  = (int) $user_id;
		$order_id = (int) $order->get_id();

		Ledger::with_user_lock(
			$user_id,
			static function () use ( $order, $user_id, $order_id, $bonus ): void {
				// Re-lectura BAJO candado: dos pedidos concurrentes del mismo
				// cliente no pueden pagar el bono dos veces.
				if ( Ledger::raw_user_meta_int( $user_id, self::META_WELCOME_PAID ) > 0 ) {
					return;
				}

				// Conciliación de estados en el ledger: si el crédito del bono de
				// este pedido ya existe y no ha sido revertido, se sincronizan las metas.
				// Para pedidos re-activados, la clave incorpora el índice de ocurrencia.
				$welcome_credits = Ledger::count_movements( 'order', $order_id, 'welcome_bonus' );
				$reversal_rows   = Ledger::count_movements( 'order', $order_id, 'order_reversal' );

				if ( $welcome_credits > $reversal_rows ) {
					update_user_meta( $user_id, self::META_WELCOME_PAID, $order_id );
					$earned = (int) $order->get_meta( Accrual::META_EARNED );
					$order->update_meta_data( Accrual::META_EARNED, $earned + $bonus );
					$order->update_meta_data( Accrual::META_WELCOME_BONUS, $bonus );
					$order->save_meta_data();
					$order->add_order_note(
						'Puntos Akibara: bono de bienvenida reconciliado desde el ledger (ya estaba acreditado).'
					);
					return;
				}

				// Validación de elegibilidad de primera compra por persona y monto en dinero.
				// Se evalúa después de la conciliación para permitir re-sello si ya existía crédito.
				$motivo = '';
				if ( ! FirstPurchase::meets_minimum( $order ) ) {
					$motivo = Options::first_purchase_min() > 0
						? sprintf( 'la compra no llega al mínimo de $%s pagados con dinero en productos', number_format_i18n( Options::first_purchase_min() ) )
						: 'la compra se pagó entera con puntos';
				} elseif ( FirstPurchase::has_prior_purchase( $order ) ) {
					$motivo = 'la cuenta, el RUT, el teléfono o el correo de este pedido ya aparecen en una compra anterior';
				} elseif ( self::has_referral_benefit( $order ) ) {
					$motivo = 'el pedido utilizó cupón o beneficio de referido (exclusión de doble bono)';
				} elseif ( self::has_welcome_coupon( $order ) ) {
					$motivo = 'el pedido utilizó cupón de bienvenida (exclusión de doble bono)';
				}

				if ( '' !== $motivo ) {
					// Una sola nota por pedido: cada re-transición volvería a
					// escribirla.
					if ( '' === (string) $order->get_meta( self::META_SKIP_NOTED ) ) {
						$order->update_meta_data( self::META_SKIP_NOTED, 1 );
						$order->save_meta_data();
						$order->add_order_note( sprintf( 'Puntos Akibara: sin bono de bienvenida — %s.', $motivo ) );
					}
					return;
				}

				$result = Ledger::credit(
					$user_id,
					$bonus,
					'welcome_bonus',
					'order',
					$order_id,
					sprintf( 'Bono de bienvenida (pedido #%d)', $order_id ),
					true,
					sprintf( 'welcome_%d_%d_%d', $user_id, $order_id, $welcome_credits + 1 )
				);

				if ( ! is_int( $result ) ) {
					return;
				}

				update_user_meta( $user_id, self::META_WELCOME_PAID, $order_id );

				// El bono viaja DENTRO del earned del pedido: la anulación lo
				// arrastra sin lógica extra (ver Accrual::deduct_all).
				$earned = (int) $order->get_meta( Accrual::META_EARNED );
				$order->update_meta_data( Accrual::META_EARNED, $earned + $bonus );
				$order->update_meta_data( Accrual::META_WELCOME_BONUS, $bonus );
				$order->save_meta_data();
				$order->add_order_note(
					sprintf( 'Puntos Akibara: bono de bienvenida de %d puntos por la primera compra.', $bonus )
				);
			}
		);
	}

	/**
	 * Teaser informativo en el formulario de registro: comunica el beneficio
	 * que se activa tras la primera compra elegible.
	 *
	 * @return void
	 */
	public function render_register_teaser(): void {
		// Por `Promise`: fuera de vigencia la primera compra no paga el bono, y
		// lo que no se va a pagar no se promete.
		$bonus = Promise::welcome_bonus();
		if ( $bonus <= 0 || 'yes' !== (string) get_option( 'akb_rewards_welcome_teaser_enabled', 'yes' ) ) {
			return;
		}

		printf(
			'<p style="margin:12px 0;padding:10px 12px;border-left:3px solid #E60012;background:rgba(230,0,18,.08)">🎁 Al crear tu cuenta te esperan <strong>%s Puntos Akibara</strong>: se activan con tu primera compra%s. Son uno por persona.</p>',
			esc_html( number_format_i18n( $bonus ) ),
			Options::first_purchase_min() > 0
				? esc_html( sprintf( ' desde $%s en productos', number_format_i18n( Options::first_purchase_min() ) ) )
				: ''
		);
	}

	/**
	 * ¿Le debemos aún el bono a este usuario? Lo usa Mi cuenta para el
	 * banner «esperándote» (solo lectura, sin candado: es informativo).
	 *
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public static function is_pending_for( int $user_id ): bool {
		// Lector CACHEADO a propósito: la re-lectura cruda del Ledger existe
		// para decidir bajo candado, y de paso VACÍA la caché de usermeta del
		// cliente — un precio que un banner informativo no tiene por qué
		// pagar (ni obligar a re-leer todo lo demás de esa página).
		return Promise::welcome_bonus() > 0
			&& 'yes' === (string) get_option( 'akb_rewards_welcome_teaser_enabled', 'yes' )
			&& (int) get_user_meta( $user_id, self::META_WELCOME_PAID, true ) <= 0;
	}

	/**
	 * Decide la elegibilidad tras la anulación del pedido pagador.
	 *
	 * Solo devuelve la elegibilidad si la reversa recuperó al menos el bono:
	 * un débito clampado (saldo ya gastado) que la devolviera crearía 500
	 * puntos de la nada, en bucle.
	 *
	 * @param \WC_Order $order    Pedido anulado.
	 * @param int       $user_id  Cliente.
	 * @param int       $deducted Puntos que la reversa recuperó de verdad.
	 * @return void
	 */
	public function maybe_restore_eligibility( $order, $user_id, $deducted = 0 ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$user_id = (int) $user_id;
		$paid_by = (int) get_user_meta( $user_id, self::META_WELCOME_PAID, true );

		if ( $paid_by !== (int) $order->get_id() || $paid_by <= 0 ) {
			return;
		}

		$bonus = (int) $order->get_meta( Accrual::META_WELCOME_BONUS );

		if ( $bonus <= 0 ) {
			// Meta ausente o en 0 con la marca puesta: anomalía. Sin saber
			// cuánto era el bono, devolver la elegibilidad reabriría el ciclo
			// farmeable — la marca se queda y se deja huella.
			$order->add_order_note(
				'Puntos Akibara: la marca del bono apunta a este pedido pero la meta del bono falta; la elegibilidad NO se devuelve (revisar).'
			);
			return;
		}

		if ( (int) $deducted < $bonus ) {
			// El bono no se recuperó: la marca se queda y el cliente no
			// vuelve a ser elegible. Huella para soporte.
			$order->add_order_note(
				sprintf(
					'Puntos Akibara: el bono de bienvenida (%d) no se recuperó completo en la reversa (solo %d); la elegibilidad NO se devuelve.',
					$bonus,
					(int) $deducted
				)
			);
			return;
		}

		delete_user_meta( $user_id, self::META_WELCOME_PAID );
		$order->update_meta_data( Accrual::META_WELCOME_BONUS, 0 );
		$order->save_meta_data();
	}

	/**
	 * ¿El pedido utilizó cupón de referido o tiene sellada la atribución de un amigo?
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	public static function has_referral_benefit( \WC_Order $order ): bool {
		if ( '' !== (string) $order->get_meta( '_akb_ref_code' ) ) {
			return true;
		}
		if ( '' !== (string) $order->get_meta( Referrals::META_REFERRED_BY ) ) {
			return true;
		}
		if ( method_exists( $order, 'get_coupon_codes' ) ) {
			foreach ( $order->get_coupon_codes() as $code ) {
				$code_lower = strtolower( trim( (string) $code ) );
				if ( 0 === strpos( $code_lower, 'ref-' ) || 0 === strpos( $code_lower, 'refreferido-' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * ¿El pedido utilizó cupón de bienvenida del popup (código hola* o meta _akb_welcome_coupon)?
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	public static function has_welcome_coupon( \WC_Order $order ): bool {
		if ( '1' === (string) $order->get_meta( '_akb_welcome_coupon' ) ) {
			return true;
		}
		if ( method_exists( $order, 'get_coupon_codes' ) ) {
			foreach ( $order->get_coupon_codes() as $code ) {
				$code_lower = strtolower( trim( (string) $code ) );
				if ( 0 === strpos( $code_lower, 'hola' ) ) {
					return true;
				}
				if ( function_exists( 'wc_get_coupon_id_by_code' ) ) {
					$coupon_id = wc_get_coupon_id_by_code( $code );
					if ( $coupon_id > 0 && '1' === (string) get_post_meta( $coupon_id, '_akb_welcome_coupon', true ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/AccountReviewLink.php
================================================================

<?php
/** Recupera la invitación ya enviada desde los pedidos de su titular. */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

final class AccountReviewLink {

	/** Registra los accesos en la lista y el detalle de pedidos. */
	public function register(): void {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'order_actions' ), 20, 2 );
		add_action( 'woocommerce_view_order', array( $this, 'view_order' ), 20 );
	}

	/** Añade el acceso únicamente si el cliente puede recuperar su invitación. */
	public function order_actions( array $actions, $order ): array {
		$url = $this->url_for_order( $order );
		if ( '' !== $url ) {
			$actions['akb_review_invitation'] = array( 'url' => $url, 'name' => $this->label() );
		}
		return $actions;
	}

	/** Muestra el mismo acceso en el detalle del pedido propio. */
	public function view_order( $order_id ): void {
		$url = $this->url_for_order( wc_get_order( (int) $order_id ) );
		if ( '' !== $url ) {
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $url ), esc_html( $this->label() ) );
		}
	}

	/** Lee un token vigente sin emitir enlaces ni modificar el pedido. */
	public function url_for_order( $order ): string {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 || ! $order instanceof \WC_Order
			|| $user_id !== (int) $order->get_customer_id()
			|| '' === (string) $order->get_meta( Invitations::META_SENT ) ) {
			return '';
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT order_id, token, expires_at, used_at FROM ' . Schema::review_forms_table()
				. ' WHERE order_id = %d AND used_at IS NULL AND expires_at >= %s ORDER BY id DESC LIMIT 1',
				(int) $order->get_id(),
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || (int) ( $row['order_id'] ?? 0 ) !== (int) $order->get_id()
			|| null !== ( $row['used_at'] ?? null )
			|| (string) ( $row['expires_at'] ?? '' ) < gmdate( 'Y-m-d H:i:s' )
			|| ! preg_match( '/^[A-Za-z0-9]{20,64}$/', (string) ( $row['token'] ?? '' ) ) ) {
			return '';
		}

		return ReviewForm::url_for( (string) $row['token'] );
	}

	/** Texto coherente con el carril vigente. */
	private function label(): string {
		return Options::reviews_lane_on() ? 'Reseñar y obtener puntos' : 'Escribir mi reseña';
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/AdminOverview.php
================================================================

<?php
/**
 * Read-only overview of review moderation, rewards and invitations.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\SettingsPage;

defined( 'ABSPATH' ) || exit;

/** Review operations at the top of the program settings. */
final class AdminOverview {

	private const SAMPLE_LIMIT = 10;

	/**
	 * Read bounded operational data without querying the order storage directly.
	 *
	 * @internal
	 * @return array<string,mixed>
	 */
	public function snapshot(): array {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return array();
		}

		$data = array( 'pending' => 0, 'approved' => 0, 'queue' => null, 'next' => null, 'orders' => array() );
		foreach ( array( 'pending' => 'hold', 'approved' => 'approve' ) as $key => $status ) {
			$data[ $key ] = (int) get_comments(
				array(
					'post_type' => 'product',
					'type__in'  => array( 'review', 'comment' ),
					'parent'    => 0,
					'status'    => $status,
					'count'     => true,
				)
			);
		}

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => Invitations::HOOK,
					'status'   => 'pending',
					'per_page' => self::SAMPLE_LIMIT + 1,
					'orderby'  => 'date',
					'order'    => 'ASC',
				)
			);
			$data['queue'] = count( $actions );
			foreach ( $actions as $action ) {
				$date = $action->get_schedule()->get_date();
				if ( $date instanceof \DateTimeInterface ) {
					$data['next'] = $date->getTimestamp();
					break;
				}
			}
		}

		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( array( 'type' => 'shop_order', 'limit' => self::SAMPLE_LIMIT, 'orderby' => 'date', 'order' => 'DESC' ) );
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				$next = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( Invitations::HOOK, array( (int) $order->get_id() ) ) : false;
				$data['orders'][] = array(
					'id'        => (int) $order->get_id(),
					'url'       => $order->get_edit_order_url(),
					'status'    => (string) $order->get_status(),
					'scheduled' => (string) $order->get_meta( Invitations::META_SCHEDULED ),
					'sent'      => (string) $order->get_meta( Invitations::META_SENT ),
					'failures'  => max( 0, (int) $order->get_meta( Invitations::META_ATTEMPTS ) ),
					'next'      => is_int( $next ) ? $next : null,
					'running'   => true === $next,
					'reason'    => self::order_reason( $order ),
				);
			}
		}

		return $data;
	}

	/**
	 * Render the current workflow and read-only invitation sample.
	 *
	 * @internal
	 */
	public function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		$data       = $this->snapshot();
		$engine     = Options::reviews_engine_on();
		$lane       = Options::reviews_lane_on();
		$invites    = $engine && Invitations::enabled();
		$moderation = 'yes' === (string) get_option( 'akb_rewards_moderate_reviews', 'yes' );
		$labels     = SettingsPage::order_status_choices();
		$status     = $labels[ Invitations::trigger_status() ] ?? Invitations::trigger_status();
		$pickup     = $labels[ Invitations::pickup_status() ] ?? Invitations::pickup_status();
		$reviews    = admin_url( 'edit.php?post_type=product&page=product-reviews' );
		$queue_url  = admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=' . rawurlencode( Invitations::HOOK ) );

		echo '<section aria-labelledby="akb-review-overview" class="akb-review-overview">';
		echo '<style>.akb-review-overview{background:#fff;border:1px solid #c3c4c7;padding:16px;margin:20px 0}.akb-review-overview .akb-review-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:20px}.akb-review-overview h3{margin:0 0 10px}.akb-review-overview .akb-review-links{display:flex;flex-wrap:wrap;gap:8px}.akb-review-overview .akb-review-table{overflow:auto}.akb-review-overview th,.akb-review-overview td{vertical-align:top}.akb-review-overview .akb-review-table table{min-width:620px}</style>';
		echo '<h2 id="akb-review-overview">Gestión de reseñas</h2>';
		if ( ! $engine ) {
			echo '<div class="notice notice-warning inline"><p><strong>El motor propio de reseñas está inactivo.</strong> CusRev, cuando está activo, lo desactiva para evitar duplicados; también puede desactivarse mediante una integración. Los ajustes de invitaciones y premios de Akibara no se ejecutan mientras este motor esté inactivo.</p></div>';
		}
		echo '<p><strong>' . esc_html( number_format_i18n( $data['pending'] ) ) . ' pendientes de revisión</strong> · ' . esc_html( number_format_i18n( $data['approved'] ) ) . ' publicadas. Se cuentan reseñas de productos, sin las respuestas del hilo.</p>';
		echo '<p class="akb-review-links"><a class="button button-primary" href="' . esc_url( $reviews . '&comment_status=moderated' ) . '">Revisar pendientes</a> <a class="button" href="' . esc_url( $reviews ) . '">Todas las valoraciones</a>';
		if ( $engine ) {
			echo ' <a class="button" href="' . esc_url( admin_url( 'edit-comments.php?page=akb-rewards-new-review' ) ) . '">Transcribir una reseña recibida</a>';
		}
		echo '</p><div class="akb-review-steps"><div><h3>1. Cuándo se invita</h3>';
		echo '<p><strong>' . esc_html( $invites ? 'Invitaciones automáticas activas.' : 'Invitaciones automáticas inactivas.' ) . '</strong> Despacho: ' . esc_html( (string) Invitations::delay_days() ) . ' días corridos desde «' . esc_html( $status ) . '». Entrega directa o retiro: ' . esc_html( (string) Invitations::pickup_delay_days() ) . ' días corridos desde «' . esc_html( $pickup ) . '». Una sola invitación por pedido; pasar por ambos estados no reinicia el plazo. El enlace dura ' . esc_html( (string) Invitations::token_days() ) . ' días.</p>';
		echo '<p>Se exige pago confirmado. Antes de enviar se comprueba otra vez el pedido y la baja voluntaria del cliente.</p><p>Guardar ajustes conserva las fechas de la cola y no invita retroactivamente a pedidos anteriores. El recordatorio en correos del pedido es un ajuste aparte.</p></div>';
		echo '<div><h3>2. Dónde revisar y aprobar</h3><p><strong>' . esc_html( $engine && $moderation ? 'Moderación obligatoria activa.' : ( $engine ? 'Moderación obligatoria inactiva.' : 'Moderación propia inactiva.' ) ) . '</strong> ';
		echo esc_html( $engine ? 'Las reseñas por invitación siempre quedan pendientes. Este interruptor controla además la moderación de los formularios nativos de las fichas.' : 'Comprueba las reglas del motor de reseñas que esté activo.' );
		echo '</p><p>Abre Productos → Valoraciones, revisa el texto, la puntuación y la foto, y pulsa Aprobar. Una reseña pendiente aún no aparece en la tienda ni cobra puntos.</p><p>La transcripción es para una opinión que el cliente ya compartió y autorizó publicar: queda identificada como publicada por la tienda y no genera premios.</p></div>';
		echo '<div><h3>3. Cuándo se acreditan</h3>';
		if ( $lane ) {
			$every = Options::review_every_n();
			$max   = Options::review_max_per_user();
			echo '<p><strong>' . esc_html( number_format_i18n( Options::review_points() ) ) . ' puntos ' . esc_html( 1 === $every ? 'por cada reseña que cumple los requisitos.' : 'cada ' . $every . ' reseñas que cumplen los requisitos.' ) . '</strong> ' . esc_html( $max > 0 ? 'Hasta ' . $max . ' premios vigentes por cliente.' : 'Sin tope de premios por cliente.' ) . '</p>';
			echo '<p>La invitación por correo es para solicitar puntos y exige una foto real de la compra. Para acreditar el premio se evalúan al aprobar: foto real, cuenta del cliente' . ( 'yes' === (string) get_option( 'akb_rewards_review_require_verified', 'yes' ) ? ', compra verificada' : '' ) . ' y producto publicado. Las nuevas reseñas voluntarias de la ficha admiten foto opcional y no generan puntos, aunque lleven foto. Las reseñas anteriores conservan su política vigente. Las estrellas no deciden el premio. Sin cuenta, el premio solicitado espera a que la cree y verifique su correo.</p><p>Retirar la aprobación revierte los puntos acreditados. El historial se consulta en Clientes → Gestionar puntos, y el cliente lo ve en Mi cuenta → Puntos Akibara.</p>';
		} else {
			echo '<p><strong>Los premios por reseña están inactivos.</strong> Revisa el interruptor, el monto del premio y el estado del motor antes de ofrecer puntos a un cliente.</p>';
		}
		echo '</div></div>';

		echo '<h3>Cola de invitaciones</h3><p><a class="button" href="' . esc_url( InvitationReschedule::url() ) . '">Reprogramar pendientes</a> Revisa las nuevas fechas antes de aplicar cambios.</p><p>';
		if ( null === $data['queue'] ) {
			echo 'No se pudo consultar Action Scheduler.';
		} else {
			echo esc_html( $data['queue'] > self::SAMPLE_LIMIT ? 'Más de ' . self::SAMPLE_LIMIT : (string) $data['queue'] ) . ' acciones pendientes.';
			if ( null !== $data['next'] ) {
				echo ' Primera fecha programada: <strong>' . esc_html( $this->format_timestamp( $data['next'] ) ) . '</strong>.';
				if ( $data['next'] < time() ) {
					echo ' La fecha ya pasó: revisa la cola y su ejecución.';
				}
			}
		}
		echo ' <a href="' . esc_url( $queue_url ) . '">Ver acciones y errores de invitaciones</a>.</p>';
		if ( ! $invites && ! empty( $data['queue'] ) ) {
			echo '<p><strong>Hay acciones pendientes, pero las invitaciones están inactivas: estar en cola no garantiza que se envíen.</strong></p>';
		}
		echo '<details><summary><strong>Invitaciones en los últimos 10 pedidos</strong></summary><p>Es una muestra de pedidos recientes, no el historial completo. «Alta en cola» registra cuándo se agendó; la fecha de ejecución se lee de la cola actual. Las fechas usan la zona horaria de la tienda.</p>';
		$this->render_orders( $data['orders'], $labels );
		echo '<p class="description">«Enviada al transporte» significa que el correo fue aceptado por el sistema de envío; la recepción se confirma en la bandeja. «Fallos de envío» cuenta intentos fallidos, no todos los intentos. Para revisar un caso, abre el pedido y sus notas.</p></details>';
		echo '<p class="akb-review-links"><a href="#akb-rewards-section-reviews">Ajustar reseñas e invitaciones</a> · <a href="#akb-rewards-section-carousel">Ajustar carrusel</a> · <a href="#akb-rewards-section-points">Ajustar puntos por compra</a></p></section>';
	}

	/** Render order evidence without exposing recipient data or invitation tokens. */
	private function render_orders( array $orders, array $labels ): void {
		if ( array() === $orders ) {
			echo '<p>No hay pedidos en esta muestra.</p>';
			return;
		}
		echo '<div class="akb-review-table" tabindex="0" role="region" aria-label="Invitaciones por pedido"><table class="widefat striped"><thead><tr><th scope="col">Pedido</th><th scope="col">Estado actual</th><th scope="col">Invitación</th><th scope="col">Fechas</th><th scope="col">Fallos de envío</th></tr></thead><tbody>';
		foreach ( $orders as $order ) {
			$state = $order['reason'];
			if ( '' !== $order['sent'] ) {
				$state = 'Enviada al transporte';
			} elseif ( $order['running'] ) {
				$state = 'En ejecución';
			} elseif ( null !== $order['next'] ) {
				$state = $order['failures'] > 0 ? 'Reintento programado' : 'Programada';
			} elseif ( $order['failures'] > 0 ) {
				$state = 'Con fallos, sin próximo envío';
			} elseif ( '' !== $order['scheduled'] ) {
				$state = $order['reason'];
			}
			echo '<tr><th scope="row"><a href="' . esc_url( $order['url'] ) . '">#' . esc_html( (string) $order['id'] ) . '</a></th><td>' . esc_html( $labels[ $order['status'] ] ?? $order['status'] ) . '</td><td>' . esc_html( $state ) . '</td><td>';
			$dates = array();
			if ( '' !== $order['scheduled'] ) {
				$dates[] = 'Alta en cola: ' . $order['scheduled'];
			}
			if ( null !== $order['next'] ) {
				$dates[] = 'Próximo envío: ' . $this->format_timestamp( $order['next'] );
			}
			if ( '' !== $order['sent'] ) {
				$dates[] = 'Enviada: ' . $order['sent'];
			}
			echo implode( '<br>', array_map( 'esc_html', $dates ) ) . '</td><td>' . esc_html( (string) $order['failures'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Format scheduler timestamps in the store timezone. */
	private function format_timestamp( int $timestamp ): string {
		return wp_date( 'd-m-Y H:i', $timestamp, wp_timezone() );
	}

	/** Motivo actual, leído sin alterar el pedido ni su cola. */
	public static function order_reason( \WC_Order $order ): string {
		if ( ! Invitations::enabled() ) {
			return 'Invitaciones automáticas apagadas.';
		}
		$reason = Invitations::waiting_reason( $order );
		if ( '' !== $reason ) {
			return $reason;
		}
		$recorded = (string) $order->get_meta( Invitations::META_REASON );
		return '' !== $recorded ? $recorded : 'Esperando despacho al transportista o entrega directa.';
	}

	/** Evidencia de invitación dentro del pedido oficial (HPOS o clásico). */
	public function render_order( $order ): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) || ! $order instanceof \WC_Order ) {
			return;
		}
		$sent    = (string) $order->get_meta( Invitations::META_SENT );
		$next    = as_next_scheduled_action( Invitations::HOOK, array( (int) $order->get_id() ) );
		$labels  = SettingsPage::order_status_choices();
		$trigger = (string) $order->get_meta( Invitations::META_TRIGGER );
		$message = self::order_reason( $order );
		if ( '' !== $sent ) {
			$message = 'Invitación enviada al sistema de correo: ' . $sent . '.';
		} elseif ( is_int( $next ) ) {
			$message = 'Invitación prevista para ' . $this->format_timestamp( $next ) . ', desde «' . ( $labels[ $trigger ] ?? $trigger ) . '».';
		} elseif ( true === $next ) {
			$message = 'La invitación se está procesando.';
		} elseif ( (int) $order->get_meta( Invitations::META_ATTEMPTS ) >= 3 ) {
			$message = 'No enviada: tres fallos. Revisa la conexión de correo y las notas del pedido.';
		}
		echo '<div class="order_data_column" style="clear:both;width:100%"><h3>Invitación a reseñar</h3><p>' . esc_html( $message ) . '</p><p><a href="' . esc_url( admin_url( 'admin.php?page=akb-rewards-settings#akb-rewards-section-reviews' ) ) . '">Ajustar plazos y estados</a></p></div>';
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/AdminReviews.php
================================================================

<?php
/**
 * AdminReviews — Creación y transcripción de reseñas con foto desde el panel de administración.
 *
 * El motor (`create_review`) clona el commentdata de la ruta de reseñas
 * (ingeniería inversa) para que TODAS las superficies existentes — schema,
 * carrusel, contadores de WooCommerce, mu-plugin del home — la vean como una
 * reseña más:
 *   - comment_type 'review', meta rating INT vía commentdata, verified 0/1.
 *   - `do_action('comment_post')` a mano tras wp_insert_comment (así lo hace
 *     CusRev; sin eso WooCommerce no escribe su meta 'verified').
 *   - `wp_update_comment_count()` explícito: es el ÚNICO disparador del
 *     recálculo de _wc_average_rating/_wc_review_count (ingeniería inversa
 *     de WC_Comments) y wp_insert_comment no lo llama por sí solo.
 *   - Marca interna `_akb_rewards_admin_created`: estas reseñas JAMÁS pagan
 *     puntos (las escribe la tienda) y el badge de autoría queda auditable.
 *
 * A diferencia del tercero, acá el rating SÍ se valida 1..5 (CusRev acepta
 * cualquier entero — trampa documentada).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class AdminReviews {

	public const META_ADMIN_CREATED = '_akb_rewards_admin_created';

	/** La divulgación de que la tienda escribió la reseña a nombre del cliente. */
	public const TEXTO_TRANSCRITA = 'Reseña compartida con la tienda y publicada por Akibara a nombre del cliente.';
	public const CAPABILITY         = 'manage_woocommerce';
	public const ACTION             = 'akb_rewards_create_review';
	public const ACTION_RECHECK     = 'akb_rewards_recheck_review_points';
	private const NOTICE_KEY        = 'akb_rw_review_recheck_';

	/**
	 * Cuelga la pantalla y el receptor del formulario.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_form' ) );
		add_action( 'admin_post_' . self::ACTION_RECHECK, array( $this, 'handle_recheck' ) );
		add_action( 'admin_notices', array( $this, 'recheck_notice' ) );
		add_filter( 'comment_row_actions', array( $this, 'points_row_action' ), 20, 2 );

		// Divulgación pública obligatoria de transcripción para cumplimiento normativo
		// sobre transparencia comercial y procedencia de reseñas.
		add_filter( 'comment_text', array( $this, 'transcription_badge' ), 20, 2 );
		add_filter( 'comment_text', array( $this, 'cadence_badge' ), 22, 2 );
		add_filter( 'comment_text', array( $this, 'points_badge' ), 23, 2 );
	}

	/**
	 * Acción disponible en la tabla nativa de reseñas de WooCommerce.
	 *
	 * @param array<string,string> $actions Acciones existentes.
	 * @param \WP_Comment         $comment Reseña de la fila.
	 * @return array<string,string>
	 */
	public function points_row_action( array $actions, $comment ): array {
		if ( ! current_user_can( self::CAPABILITY ) || ! self::can_recheck( $comment ) ) {
			return $actions;
		}
		$id = (int) $comment->comment_ID;
		$url = wp_nonce_url(
			add_query_arg( array( 'action' => self::ACTION_RECHECK, 'comment_id' => $id ), admin_url( 'admin-post.php' ) ),
			self::ACTION_RECHECK . '_' . $id
		);
		$actions['akb_review_points'] = '<a href="' . esc_url( $url ) . '">Revisar puntos</a>';
		return $actions;
	}

	/** @param mixed $comment Comentario a comprobar. @return bool */
	private static function can_recheck( $comment ): bool {
		return $comment instanceof \WP_Comment
			&& 'review' === (string) $comment->comment_type
			&& 0 === (int) $comment->comment_parent
			&& '1' === (string) $comment->comment_approved
			&& 'product' === get_post_type( (int) $comment->comment_post_ID )
			&& Options::reviews_lane_on();
	}

	/**
	 * Reevalúa con el motor normal, sin cambiar publicación ni saltar reglas.
	 *
	 * @param int $comment_id Reseña elegida por el administrador.
	 * @return array{type:string,message:string} Resultado verificable.
	 */
	public function recheck_points( int $comment_id ): array {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return array( 'type' => 'error', 'message' => 'No tienes permisos para revisar puntos.' );
		}
		$comment = get_comment( $comment_id );
		if ( ! self::can_recheck( $comment ) ) {
			return array( 'type' => 'error', 'message' => 'Sólo se revisan reseñas de producto aprobadas con el programa de reseñas activo.' );
		}
		$user_id = (int) $comment->user_id;
		if ( $user_id <= 0 ) {
			$user = get_user_by( 'email', (string) $comment->comment_author_email );
			$user_id = $user instanceof \WP_User ? (int) $user->ID : 0;
		}
		$product_id = (int) $comment->comment_post_ID;
		$before = $user_id > 0 ? Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review' ) : 0;
		$public_before = self::public_reward_state( $comment_id );
		( new ReviewPoints() )->maybe_pay( $comment );
		if ( $public_before !== self::public_reward_state( $comment_id ) ) {
			// La reseña sigue aprobada: no hay transición que retire del caché
			// su antigua insignia de compra o el criterio de reseña incentivada.
			delete_transient( Carousel::TRANSIENT );
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mismo hook de la acreditación tardía por cuenta verificada.
		}
		$after = $user_id > 0 ? Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review' ) : 0;
		$paid = (int) get_comment_meta( $comment_id, ReviewPoints::META_POINTS_PAID, true );
		if ( $after > $before && $paid > 0 ) {
			return array( 'type' => 'success', 'message' => sprintf( 'Reseña #%d: se acreditaron %s Puntos Akibara. La reseña sigue publicada.', $comment_id, number_format_i18n( $paid ) ) );
		}
		return array( 'type' => 'info', 'message' => sprintf( 'Reseña #%d: no se acreditaron puntos nuevos. %s', $comment_id, self::points_status( $comment ) ) );
	}

	/** @param int $comment_id Reseña. @return array<int,string> Metas que cambian la presentación pública. */
	private static function public_reward_state( int $comment_id ): array {
		return array(
			(string) get_comment_meta( $comment_id, 'verified', true ),
			(string) get_comment_meta( $comment_id, ReviewPoints::META_POINTS_PAID, true ),
			(string) get_comment_meta( $comment_id, ReviewPoints::META_COUNTED, true ),
		);
	}

	/** Procesa la acción autenticada y vuelve siempre a la pantalla oficial. */
	public function handle_recheck(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Sin permisos.' );
		}
		$comment_id = isset( $_GET['comment_id'] ) ? absint( $_GET['comment_id'] ) : 0;
		check_admin_referer( self::ACTION_RECHECK . '_' . $comment_id );
		$result = $this->recheck_points( $comment_id );
		set_transient( self::NOTICE_KEY . get_current_user_id(), $result, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=product-reviews' ) );
		exit;
	}

	/** Muestra una vez el resultado real, guardado sólo para este administrador. */
	public function recheck_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo elige dónde pintar un aviso guardado en servidor.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! current_user_can( self::CAPABILITY ) || 'product-reviews' !== $page ) {
			return;
		}
		$key = self::NOTICE_KEY . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$type = in_array( $notice['type'], array( 'success', 'info', 'error' ), true ) ? $notice['type'] : 'info';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $notice['message'] ) );
	}

	/**
	 * Estado del premio en la lista de moderación, sin recalcular ni acreditar.
	 *
	 * @param string           $text Texto de la reseña.
	 * @param \WP_Comment|null $comment Reseña.
	 * @return string
	 */
	public function points_badge( $text, $comment = null ): string {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) || ! $comment instanceof \WP_Comment
			|| 'review' !== (string) $comment->comment_type || (int) $comment->comment_parent > 0
			|| 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return (string) $text;
		}
		// El avance ya lo muestra cadence_badge en esta misma superficie.
		if ( '' !== (string) get_comment_meta( (int) $comment->comment_ID, ReviewPoints::META_PROGRESS, true ) ) {
			return (string) $text;
		}
		return (string) $text . '<p class="akb-rw-puntos-estado" style="margin:8px 0 0;font-size:12px">' . esc_html( self::points_status( $comment ) ) . '</p>';
	}

	/** @param \WP_Comment $comment Reseña. @return string Explicación del estado guardado. */
	private static function points_status( \WP_Comment $comment ): string {
		$id = (int) $comment->comment_ID;
		$seal = (string) get_comment_meta( $id, ReviewPoints::META_POINTS_PAID, true );
		if ( (int) $seal > 0 ) {
			return sprintf( '%s Puntos Akibara ya acreditados.', number_format_i18n( (int) $seal ) );
		}
		if ( '0' === (string) get_comment_meta( $id, ReviewPoints::META_POINTS_REQUESTED, true ) ) {
			return 'El cliente no solicitó puntos por esta reseña.';
		}
		if ( ReviewPoints::SEAL_PENDING_USER === $seal ) {
			return 'Premio pendiente de crear y verificar la cuenta del cliente.';
		}
		if ( '' !== (string) get_comment_meta( $id, self::META_ADMIN_CREATED, true ) ) {
			return 'La reseña transcrita por la tienda no recibe puntos.';
		}
		if ( '1' !== (string) $comment->comment_approved ) {
			return 'Los puntos se revisan cuando apruebes la reseña.';
		}
		$progress = (string) get_comment_meta( $id, ReviewPoints::META_PROGRESS, true );
		if ( 1 === preg_match( '/^(\d+)\/(\d+)$/', $progress, $parts ) ) {
			return sprintf( 'Avance: %d de %d reseñas para el próximo premio.', (int) $parts[1], (int) $parts[2] );
		}
		if ( ! Options::reviews_lane_on() ) {
			return 'El programa de puntos por reseñas está apagado.';
		}
		if ( '0' === $seal ) {
			return 'Evaluada sin premio adicional: revisa el límite por cliente, el producto ya premiado o un incentivo previo.';
		}
		return 'Sin premio acreditado: revisa foto, compra verificada, cuenta y requisitos del programa.';
	}

	/**
	 * Submenú bajo Comentarios.
	 *
	 * Ojo: desde WooCommerce 8 las reseñas de producto YA NO se listan en
	 * Comentarios (`ReviewsCommentsOverrides` las excluye); se moderan en
	 * Productos › Reseñas. Este submenú sólo aloja el formulario de
	 * transcripción; los enlaces «ver la reseña» apuntan a la pantalla de
	 * WooCommerce, no a Comentarios.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_comments_page(
			'Crear reseña (Akibara)',
			'Crear reseña',
			self::CAPABILITY,
			'akb-rewards-new-review',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Insignia visible en la reseña transcrita: el lector sabe que la
	 * publicó la tienda a nombre del cliente.
	 *
	 * @param string           $text    Texto del comentario.
	 * @param \WP_Comment|null $comment Comentario.
	 * @return string
	 */
	/**
	 * Indicador de avance de cadencia visible exclusivamente en la moderación del panel de administración.
	 *
	 * Cuando opera la política de puntos por cadencia de reseñas, visualiza el progreso
	 * hacia el siguiente umbral de recompensa.
	 *
	 * @internal Callback de `comment_text`.
	 *
	 * @param mixed            $text    Texto ya filtrado.
	 * @param \WP_Comment|null $comment Comentario, cuando el llamador lo pasa.
	 * @return string
	 */
	public function cadence_badge( $text, $comment = null ): string {
		$text = (string) $text;

		if ( ! is_admin() || ! $comment instanceof \WP_Comment ) {
			return $text;
		}

		$avance = (string) get_comment_meta( (int) $comment->comment_ID, ReviewPoints::META_PROGRESS, true );
		if ( '' === $avance || 1 !== preg_match( '/^(\d+)\/(\d+)$/', $avance, $partes ) ) {
			return $text;
		}

		return $text . '<p class="akb-rw-cadencia" style="margin:8px 0 0;font-size:12px;opacity:.75">'
			. esc_html( sprintf( 'Va %d de %d para el próximo premio de Puntos Akibara.', (int) $partes[1], (int) $partes[2] ) )
			. '</p>';
	}

	public function transcription_badge( $text, $comment = null ): string {
		if ( ! $comment instanceof \WP_Comment ) {
			return (string) $text;
		}

		$aviso = self::transcription_notice( (int) $comment->comment_ID );
		if ( '' === $aviso ) {
			return (string) $text;
		}

		return (string) $text
			. '<p class="akb-rw-transcrita" style="margin:8px 0 0;font-size:12px;opacity:.75">📩 ' . esc_html( $aviso ) . '</p>';
	}

	/**
	 * El aviso de transcripción de una reseña; '' si la escribió su autor.
	 *
	 * Fuente común de texto sin HTML para la ficha, el carrusel y el JSON-LD.
	 * Las dos últimas superficies no pasan por el filtro `comment_text`.
	 *
	 * @since 0.4.9
	 *
	 * @param int $comment_id Reseña.
	 * @return string
	 */
	public static function transcription_notice( int $comment_id ): string {
		return 1 === (int) get_comment_meta( $comment_id, self::META_ADMIN_CREATED, true )
			? self::TEXTO_TRANSCRITA
			: '';
	}

	/**
	 * Crea una reseña con la forma de CusRev. Motor puro de la pantalla y de
	 * cualquier import futuro.
	 *
	 * @param array{product_id:int, author:string, rating:int, content:string, attachment_id:int, title?:string, email?:string, source?:string, approved?:bool} $args Datos.
	 * @return int|\WP_Error ID del comentario creado.
	 */
	public static function create_review( array $args ) {
		$product_id = (int) ( $args['product_id'] ?? 0 );
		if ( 'product' !== get_post_type( $product_id ) ) {
			return new \WP_Error( 'akb_rw_bad_product', 'El producto no existe.' );
		}

		$rating = (int) ( $args['rating'] ?? 0 );
		if ( $rating < 1 || $rating > 5 ) {
			return new \WP_Error( 'akb_rw_bad_rating', 'La valoración debe estar entre 1 y 5.' );
		}

		$author = trim( (string) ( $args['author'] ?? '' ) );
		if ( '' === $author ) {
			return new \WP_Error( 'akb_rw_bad_author', 'La reseña necesita un nombre.' );
		}

		$content = trim( (string) ( $args['content'] ?? '' ) );
		if ( '' === $content ) {
			return new \WP_Error( 'akb_rw_bad_content', 'La reseña necesita un texto.' );
		}

		// FOTO OBLIGATORIA: sin imagen real no hay reseña de admin.
		$attachment_id = (int) ( $args['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 || ! wp_attachment_is( 'image', $attachment_id ) ) {
			return new \WP_Error( 'akb_rw_photo_required', 'La reseña del admin exige una foto (imagen real).' );
		}

		// Email opcional del cliente: enlaza a una cuenta existente si coincide,
		// o registra como reseña directa de tienda si se omite.
		$email   = sanitize_email( (string) ( $args['email'] ?? '' ) );
		$user    = '' !== $email ? get_user_by( 'email', $email ) : false;
		$user_id = $user instanceof \WP_User ? (int) $user->ID : 0;

		$commentdata = array(
			'comment_author'       => $author,
			'comment_author_email' => $email,
			'comment_author_url'   => '',
			'user_id'              => $user_id,
			'comment_content'      => $content,
			'comment_post_ID'      => $product_id,
			'comment_type'         => 'review',
			'comment_approved'     => ! empty( $args['approved'] ?? true ) ? 1 : 0,
			'comment_meta'         => array(
				'rating'                  => $rating,
				// La marca debe existir antes de que wp_insert_comment dispare ReviewPoints.
				self::META_ADMIN_CREATED  => 1,
			),
		);

		$comment_id = wp_insert_comment( wp_slash( $commentdata ) );
		if ( ! $comment_id ) {
			return new \WP_Error( 'akb_rw_insert_failed', 'WordPress rechazó la reseña.' );
		}

		// Completa los metadatos y la foto antes de notificar a comment_post.
		$title = trim( (string) ( $args['title'] ?? '' ) );
		if ( '' !== $title ) {
			update_comment_meta( $comment_id, 'cr_rev_title', $title );
		}
		Media::attach_photo( $comment_id, $attachment_id, $product_id );

		// comment_post a mano, clonado de CusRev — pero SIN los correos de
		// core («nuevo comentario» al dueño por su propia reseña): esos dos
		// oyentes se retiran solo para esta request de admin.
		remove_action( 'comment_post', 'wp_new_comment_notify_moderator' );
		remove_action( 'comment_post', 'wp_new_comment_notify_postauthor' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- no es un hook nuestro: es el de WP core, y lo disparamos a mano porque insertamos el comentario sin pasar por wp_new_comment(). Con otro nombre, Woo no estamparía «compra verificada» y nuestro motor no vería la reseña.
		do_action( 'comment_post', $comment_id, $commentdata['comment_approved'], $commentdata );
		// Restablece las notificaciones para los demás comentarios de la petición.
		add_action( 'comment_post', 'wp_new_comment_notify_moderator' );
		add_action( 'comment_post', 'wp_new_comment_notify_postauthor' );

		// La insignia de compra verificada se calcula programáticamente contra el
		// historial de compras del cliente y producto en WooCommerce para asegurar veracidad.
		$verified = ( '' !== $email || $user_id > 0 ) && function_exists( 'wc_customer_bought_product' )
			? (bool) wc_customer_bought_product( $email, $user_id, $product_id )
			: false;
		update_comment_meta( $comment_id, 'verified', (int) $verified );

		// Registro de procedencia y trazabilidad de consentimiento.
		$source = trim( (string) ( $args['source'] ?? '' ) );
		if ( '' !== $source ) {
			update_comment_meta( $comment_id, '_akb_rewards_source_note', $source );
		}

		// El único disparador del recálculo de ratings de WooCommerce.
		wp_update_comment_count( $product_id );

		return (int) $comment_id;
	}

	/**
	 * Receptor del formulario (admin-post, nonce + capability + subida).
	 *
	 * @return void
	 */
	public function handle_form(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( self::ACTION );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = 0;
		if ( ! empty( $_FILES['akb_rw_photo']['name'] ) ) {
			$uploaded = media_handle_upload( 'akb_rw_photo', 0 );
			if ( is_wp_error( $uploaded ) ) {
				// Conserva el motivo específico del rechazo de la foto.
				wp_safe_redirect(
					add_query_arg(
						array( 'akb_rw_error' => rawurlencode( 'Foto rechazada: ' . $uploaded->get_error_message() ) ),
						admin_url( 'edit-comments.php?page=akb-rewards-new-review' )
					)
				);
				exit;
			}
			$attachment_id = (int) $uploaded;
		}

		$result = self::create_review(
			array(
				'product_id'    => isset( $_POST['akb_rw_product'] ) ? (int) $_POST['akb_rw_product'] : 0,
				'author'        => isset( $_POST['akb_rw_author'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_rw_author'] ) ) : '',
				'rating'        => isset( $_POST['akb_rw_rating'] ) ? (int) $_POST['akb_rw_rating'] : 0,
				'title'         => isset( $_POST['akb_rw_title'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_rw_title'] ) ) : '',
				'content'       => isset( $_POST['akb_rw_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['akb_rw_content'] ) ) : '',
				'attachment_id' => $attachment_id,
				'email'         => isset( $_POST['akb_rw_email'] ) ? sanitize_email( wp_unslash( $_POST['akb_rw_email'] ) ) : '',
				'source'        => isset( $_POST['akb_rw_source'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_rw_source'] ) ) : '',
			)
		);

		if ( is_wp_error( $result ) && $attachment_id > 0 ) {
			// Sin reseña no queda adjunto huérfano en la Media Library (el
			// residuo sin GC que le reprochamos a CusRev).
			wp_delete_attachment( $attachment_id, true );
		}

		$arg = is_wp_error( $result )
			? array( 'akb_rw_error' => rawurlencode( $result->get_error_message() ) )
			: array( 'akb_rw_created' => (int) $result );

		wp_safe_redirect( add_query_arg( $arg, admin_url( 'edit-comments.php?page=akb-rewards-new-review' ) ) );
		exit;
	}

	/**
	 * Pantalla mínima y liviana (HTML plano del admin, sin JS propio).
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap"><h1>Crear reseña</h1>';

		if ( isset( $_GET['akb_rw_created'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo pinta un aviso.
			printf(
				'<div class="notice notice-success"><p>Reseña #%d creada. <a href="%s">Ver en Productos › Reseñas →</a></p></div>',
				(int) $_GET['akb_rw_created'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				// Enlace directo a la vista de reseñas de productos de WooCommerce.
				esc_url( admin_url( 'edit.php?post_type=product&page=product-reviews' ) )
			);
		}
		if ( isset( $_GET['akb_rw_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- aviso de interfaz, sin efecto.
				esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['akb_rw_error'] ) ) ) )
			);
		}

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="akb_rw_product">ID del producto</label></th><td><input name="akb_rw_product" id="akb_rw_product" type="number" required class="regular-text"><p class="description">El ID de la ficha (visible en Productos → pasar el mouse).</p></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_author">Nombre a mostrar</label></th><td><input name="akb_rw_author" id="akb_rw_author" type="text" required class="regular-text"></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_rating">Valoración</label></th><td><select name="akb_rw_rating" id="akb_rw_rating">';
		for ( $i = 5; $i >= 1; $i-- ) {
			printf( '<option value="%1$d">%1$d ★</option>', (int) $i );
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_title">Título (opcional)</label></th><td><input name="akb_rw_title" id="akb_rw_title" type="text" class="regular-text"></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_content">Reseña</label></th><td><textarea name="akb_rw_content" id="akb_rw_content" rows="5" required class="large-text"></textarea></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_photo">Foto (obligatoria)</label></th><td><input name="akb_rw_photo" id="akb_rw_photo" type="file" accept="image/jpeg,image/png,image/webp" required></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_email">Email del cliente (opcional)</label></th><td><input name="akb_rw_email" id="akb_rw_email" type="email" class="regular-text"><p class="description">Si el cliente existe y compró el producto, la insignia «compra verificada» se calcula sola contra sus pedidos reales. Nunca se marca a mano.</p></td></tr>';
		echo '<tr><th scope="row"><label for="akb_rw_source">¿Dónde te la compartió?</label></th><td><input name="akb_rw_source" id="akb_rw_source" type="text" class="regular-text" placeholder="Instagram DM 30-08, WhatsApp…"><p class="description">Procedencia y consentimiento del cliente (queda en la reseña como registro interno).</p></td></tr>';
		echo '</table>';
		submit_button( 'Crear reseña' );
		echo '</form></div>';
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/Carousel.php
================================================================

<?php
/**
 * Carousel — «Voces del Distrito» en la portada, first-party y LIVIANO.
 *
 * Reemplaza al mu-plugin akibara-home-reviews (que envolvía el slider Slick
 * de CusRev: ~188 KB de CSS+JS muertos en la home). Este carrusel es CSS
 * scroll-snap PURO: cero JavaScript, imágenes lazy, y cada perilla en una
 * option (regla del dueño: UI configurable y liviana).
 *
 * Compuertas de calidad:
 *   - Solo aparece cuando las reseñas elegibles sobrepasan el mínimo configurado
 *     (option `akb_rewards_carousel_min`, por defecto 8).
 *   - Elegible = aprobada, de producto publicado, ≥ 40 caracteres, y no
 *     incentivada (meta cr_coupon_code NOT EXISTS, la clave heredada).
 *
 * El shortcode se llama IGUAL que el del mu-plugin a propósito: los
 * mu-plugins cargan antes y este add_shortcode lo pisa — el post 210 de la
 * portada no se toca en el cutover.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Assets;
use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Carousel {

	public const SHORTCODE = 'akibara_home_reviews';
	public const TRANSIENT = 'akb_rewards_carousel_v7';

	/** El <style> sale una vez por petición (una instancia registra el shortcode). */
	private bool $style_printed = false;

	/**
	 * Cuelga shortcode y purgas.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		HomeCache::register();

		// Invalida el transient y el HTML de portada al cambiar las reseñas.
		add_action( 'transition_comment_status', array( $this, 'flush_on_transition' ), 10, 3 );
		add_action( 'edit_comment', array( $this, 'flush_on_comment' ), 10, 1 );
		add_action( 'wp_insert_comment', array( $this, 'flush_on_insert' ), 10, 2 );
		add_action( 'deleted_comment', array( $this, 'flush_on_insert' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'flush_for_product' ), 10, 3 );
		// Borrar EN FIRME un producto publicado no pasa por
		// transition_post_status: sin esto la portada servía hasta una hora
		// una tarjeta que enlazaba a la nada.
		add_action( 'before_delete_post', array( $this, 'flush_on_delete' ), 10, 2 );
		foreach ( array( 'added_comment_meta', 'updated_comment_meta', 'deleted_comment_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'flush_on_media' ), 10, 3 );
		}
	}

	/**
	 * Cambiar una foto también puede cambiar la elegibilidad del carrusel.
	 *
	 * @param int|int[] $meta_id    Meta modificada.
	 * @param int       $comment_id Reseña.
	 * @param string    $meta_key   Clave modificada.
	 * @return void
	 */
	public function flush_on_media( $meta_id, $comment_id, $meta_key ): void {
		unset( $meta_id );
		if ( in_array( (string) $meta_key, array( Media::META_LOCAL_IMAGE, Media::META_REMOTE_IMAGE ), true ) ) {
			$this->flush_if_review( null, (int) $comment_id );
		}
	}

	/**
	 * Tira el caché de tarjetas y purga la portada servida.
	 *
	 * @return void
	 */
	public function flush(): void {
		delete_transient( self::TRANSIENT );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- no es un hook nuestro: es el de LiteSpeed, y el nombre exacto es la única forma de que su plugin escuche y purgue la portada. Prefijarlo lo dejaría sin oyente.
		do_action( 'litespeed_purge_url', home_url( '/' ) );
		HomeCache::queue_purge();
	}

	/**
	 * ¿Este comentario toca al carrusel? Solo las RESEÑAS de PRODUCTO: un
	 * comentario del blog o un spam de Akismet no invalidan la portada.
	 *
	 * @param \WP_Comment|null $comment Comentario (o null para resolver por id).
	 * @param int              $id      ID de respaldo.
	 * @return void
	 */
	private function flush_if_review( $comment, int $id = 0 ): void {
		if ( ! $comment instanceof \WP_Comment && $id > 0 ) {
			$comment = get_comment( $id );
		}
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		if ( 'review' !== (string) $comment->comment_type ) {
			return;
		}
		if ( 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return;
		}

		$this->flush();
	}

	/**
	 * Transición de estado de comentario.
	 *
	 * @param string           $new_status Estado nuevo.
	 * @param string           $old_status Estado anterior.
	 * @param \WP_Comment      $comment    Comentario.
	 * @return void
	 */
	public function flush_on_transition( $new_status, $old_status, $comment ): void {
		unset( $new_status, $old_status );
		$this->flush_if_review( $comment instanceof \WP_Comment ? $comment : null );
	}

	/**
	 * Edición de comentario (llega solo el id).
	 *
	 * @param int $comment_id ID.
	 * @return void
	 */
	public function flush_on_comment( $comment_id ): void {
		$this->flush_if_review( null, (int) $comment_id );
	}

	/**
	 * Inserción o borrado (id + objeto).
	 *
	 * @param int              $comment_id ID.
	 * @param \WP_Comment|null $comment    Comentario.
	 * @return void
	 */
	public function flush_on_insert( $comment_id, $comment = null ): void {
		$this->flush_if_review( $comment instanceof \WP_Comment ? $comment : null, (int) $comment_id );
	}

	/**
	 * Invalida ante un cambio efectivo de estado de producto o adjunto.
	 * transition_post_status también se dispara al guardar sin cambiar estado.
	 *
	 * @param string   $new_status Estado nuevo.
	 * @param string   $old_status Estado anterior.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public function flush_for_product( $new_status, $old_status, $post ): void {
		if ( (string) $new_status === (string) $old_status ) {
			return;
		}

		if ( is_object( $post ) && in_array( (string) ( $post->post_type ?? '' ), array( 'product', 'attachment' ), true ) ) {
			$this->flush();
		}
	}

	/**
	 * Borrado en firme: si el producto estaba PUBLICADO, sus tarjetas quedan
	 * enlazando a la nada.
	 *
	 * @param int           $post_id ID.
	 * @param \WP_Post|null $post    Post que se va (WP 6.6+ lo pasa).
	 * @return void
	 */
	public function flush_on_delete( $post_id, $post = null ): void {
		if ( ! is_object( $post ) ) {
			$post = get_post( (int) $post_id );
		}

		if ( is_object( $post ) && in_array( (string) ( $post->post_type ?? '' ), array( 'product', 'attachment' ), true ) ) {
			$this->flush();
		}
	}

	/**
	 * Render del shortcode. Devuelve '' bajo el umbral: la portada no muestra
	 * una sección a medio llenar.
	 *
	 * @return string
	 */
	public function render(): string {
		if ( ! Options::carousel_on() ) {
			return '';
		}

		$data = $this->data();

		if ( (int) $data['eligible'] < Options::carousel_min() ) {
			return '';
		}

		$this->enqueue_animation();

		return $this->markup( $data['cards'] );
	}

	/**
	 * Reseñas elegibles que ve HOY el carrusel, del MISMO caché con que pinta
	 * la portada. Topadas en el umbral (ver build_data): responde «¿llega al
	 * mínimo?» y, bajo el mínimo, es el número exacto. Lo lee el panel para
	 * decir si la portada muestra el título que el dueño está editando.
	 *
	 * @return int
	 */
	public function eligible_seen(): int {
		return (int) $this->data()['eligible'];
	}

	/**
	 * Tarjetas y umbral: del transient, o armados y guardados una hora.
	 *
	 * @return array{eligible:int, cards:array<int,array<string,mixed>>}
	 */
	private function data(): array {
		$data = get_transient( self::TRANSIENT );
		if ( ! is_array( $data ) ) {
			$data = $this->build_data();
			set_transient( self::TRANSIENT, $data, HOUR_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * Reveal GSAP alineado con el sistema de animaciones del tema.
	 * Solo si el tema ya registró gsap/gsap-scrolltrigger en esta página: el
	 * carrusel jamás carga la librería por su cuenta, y sin ella las tarjetas
	 * se presentan con CSS nativo.
	 *
	 * @return void
	 */
	private function enqueue_animation(): void {
		if ( ! function_exists( 'wp_script_is' ) || ! wp_script_is( 'gsap', 'registered' ) || ! wp_script_is( 'gsap-scrolltrigger', 'registered' ) ) {
			return;
		}

		$rel = 'assets/js/carousel.js';

		wp_enqueue_script(
			'akb-rewards-carousel',
			Assets::url( $rel ),
			array( 'gsap', 'gsap-scrolltrigger' ),
			Assets::version( $rel ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Arma las tarjetas y resuelve el umbral con UNA sola consulta.
	 *
	 * Antes eran dos con el mismo WHERE de 12 líneas copiado, una de ellas
	 * sólo para contar. El umbral no necesita un total exacto —es un «¿llega
	 * a N?»—, así que se piden tantas filas como la mayor de las dos perillas
	 * y se cuenta sobre el resultado: si vuelven menos que el mínimo, la
	 * portada no pinta la sección.
	 *
	 * @return array{eligible:int, cards:array<int,array<string,mixed>>}
	 *         `eligible` son las filas elegibles VISTAS, topadas en ese
	 *         límite: sirve para el umbral, no como total del catálogo.
	 */
	private function build_data(): array {
		global $wpdb;

		// El dueño puede excluir reseñas que cobraron puntos o contaron para
		// un premio. Disclosure resuelve el aviso incluido en cada tarjeta.
		$excluir_premiadas = '';
		if ( 'yes' === (string) get_option( 'akb_rewards_carousel_exclude_rewarded', 'no' ) ) {
			$excluir_premiadas = "AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->commentmeta} pr
				WHERE pr.comment_id = c.comment_ID AND pr.meta_key IN ('_akb_rewards_review_points', '_akb_rewards_review_counted')
					AND CAST(pr.meta_value AS UNSIGNED) > 0
			)";
		}

		// El filtro de foto comprueba el adjunto igual que los premios: un adjunto
		// borrado, un video o una portada de producto no son una foto de reseña.
		// Las fotos remotas heredadas sólo aceptan URLs HTTP(S) seguras.
		// Se filtra ANTES del mínimo/límite; una página sin fotos no puede
		// ocultar fotos válidas que existan más atrás.
		$mode   = Options::carousel_media();
		$needed = max( Options::carousel_cards(), Options::carousel_min() );
		$batch  = 'mixed' === $mode ? $needed : max( 50, $needed );
		$offset = 0;
		$rows   = array();
		do {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fragmento fijo, sin entrada de usuario.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$page = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.comment_ID, c.comment_post_ID, c.comment_author, c.comment_content, c.comment_date
					FROM {$wpdb->comments} c
					INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID AND p.post_type = 'product' AND p.post_status = 'publish'
					WHERE c.comment_approved = '1' AND c.comment_type = 'review' AND c.comment_parent = 0
						AND CHAR_LENGTH(c.comment_content) >= %d
						AND EXISTS (
							SELECT 1 FROM {$wpdb->commentmeta} r
							WHERE r.comment_id = c.comment_ID AND r.meta_key = 'rating'
								AND CAST(r.meta_value AS UNSIGNED) BETWEEN 1 AND 5
						)
						AND NOT EXISTS (
							SELECT 1 FROM {$wpdb->commentmeta} m
							WHERE m.comment_id = c.comment_ID AND m.meta_key = 'cr_coupon_code'
						)
						{$excluir_premiadas}
					ORDER BY c.comment_date_gmt DESC, c.comment_ID DESC
					LIMIT %d OFFSET %d",
					Options::carousel_min_chars(),
					$batch,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( array() !== $page && function_exists( 'update_meta_cache' ) ) {
				update_meta_cache( 'comment', array_map( static fn( $row ): int => (int) $row->comment_ID, $page ) );
			}
			foreach ( $page as $row ) {
				$image = self::review_photo( (int) $row->comment_ID );
				$has_photo = $image['id'] > 0 || '' !== $image['url'];
				if ( 'mixed' !== $mode && ( 'photos' === $mode ) !== $has_photo ) {
					continue;
				}
				$rows[] = $row;
				if ( count( $rows ) >= $needed ) {
					break;
				}
			}
			$offset += $batch;
		} while ( 'mixed' !== $mode && count( $page ) === $batch && count( $rows ) < $needed );

		$eligible = count( $rows );
		$cards    = array();

		if ( $eligible > 0 ) {
			// Del lote pedido para el umbral sólo se pintan las tarjetas
			// configuradas.
			$rows = array_slice( $rows, 0, Options::carousel_cards() );


			foreach ( $rows as $row ) {
				$comment_id = (int) $row->comment_ID;
				$rating     = (int) get_comment_meta( $comment_id, 'rating', true );
				$title      = (string) get_comment_meta( $comment_id, 'cr_rev_title', true );

				$image = self::review_photo( $comment_id );

				$cards[] = array(
					'author'    => (string) $row->comment_author,
					'text'      => (string) $row->comment_content,
					'rating'    => $rating,
					'title'     => $title,
					'photo_id'  => $image['id'],
					'photo_url' => $image['url'],
					'comment'   => $comment_id,
					'cover_id'  => (int) get_post_thumbnail_id( (int) $row->comment_post_ID ),
					'product'   => (int) $row->comment_post_ID,
					'aviso'     => Disclosure::visible_notice_for( $comment_id ),
					// El aviso de transcripción es independiente del de incentivo.
					'origen'    => AdminReviews::transcription_notice( $comment_id ),
				);
			}
		}

		return array(
			'eligible' => $eligible,
			'cards'    => $cards,
		);
	}

	/**
	 * Foto que se puede mostrar; nunca confunde una portada con foto del cliente.
	 *
	 * @param int $comment_id Reseña.
	 * @return array{id:int,url:string}
	 */
	private static function review_photo( int $comment_id ): array {
		foreach ( (array) get_comment_meta( $comment_id, Media::META_LOCAL_IMAGE ) as $attachment_id ) {
			if ( (int) $attachment_id > 0 && wp_attachment_is( 'image', (int) $attachment_id ) ) {
				return array( 'id' => (int) $attachment_id, 'url' => '' );
			}
		}
		foreach ( (array) get_comment_meta( $comment_id, Media::META_REMOTE_IMAGE ) as $remote ) {
			if ( is_array( $remote ) && ! empty( $remote['url'] ) ) {
				$url = esc_url( (string) $remote['url'], array( 'https', 'http' ) );
				if ( '' !== $url ) {
					return array( 'id' => 0, 'url' => $url );
				}
			}
		}
		return array( 'id' => 0, 'url' => '' );
	}

	/**
	 * HTML del carrusel: scroll-snap puro, sin JS, imágenes lazy, tokens de
	 * marca con fallback.
	 *
	 * @param array<int,array<string,mixed>> $cards Tarjetas.
	 * @return string
	 */
	private function markup( array $cards ): string {
		if ( array() === $cards ) {
			return '';
		}

		// Precalentamiento de caché para los productos referenciados por las tarjetas,
		// evitando consultas SQL N+1 en get_permalink() y get_the_title().
		$product_ids = array_values( array_unique( array_map( static fn( array $c ): int => (int) $c['product'], $cards ) ) );
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $product_ids, false, false );
		}

		// Tokens del tema; fotos y portadas tienen tratamientos distintos.
		// Las tarjetas se estiran a la misma altura y el pie permanece alineado.
		$css = '.akb-rw-carousel{--akb-rw-bg:var(--aki-surface-2,#1c1c1c);--akb-rw-fg:var(--aki-text,#f5f5f5);--akb-rw-accent:var(--aki-red-readable,#FF4756);box-sizing:border-box;width:100%;max-width:1280px;margin:clamp(32px,6vw,72px) auto;padding:0 16px}'
			. '.akb-rw-carousel .akb-rw-header{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:8px}'
			. '.akb-rw-carousel h2{margin:0 0 4px;width:fit-content;font-family:var(--font-heading,inherit);font-size:clamp(26px,3.5vw,40px);line-height:1.15;letter-spacing:.04em;text-transform:uppercase;color:var(--aki-white,#fff);overflow-wrap:anywhere}'
			. '.akb-rw-carousel .akb-rw-nav{display:flex;align-items:center;gap:8px}'
			. '.akb-rw-carousel .akb-rw-nav[hidden],.akb-rw-carousel .akb-rw-nav[data-hidden="true"]{display:none!important}'
			. '.akb-rw-carousel .akb-rw-nav-btn{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;background:var(--akb-rw-bg);color:var(--aki-white,#fff);border:1px solid var(--aki-border,rgba(255,255,255,.18));cursor:pointer;transition:background-color 150ms ease,border-color 150ms ease,transform 150ms ease,opacity 150ms ease;padding:0}'
			. '.akb-rw-carousel .akb-rw-nav-btn:hover:not(:disabled){background:var(--akb-rw-accent);border-color:var(--aki-white,#fff);transform:translateY(-2px)}'
			. '.akb-rw-carousel .akb-rw-nav-btn:active:not(:disabled){transform:translateY(0)}'
			. '.akb-rw-carousel .akb-rw-nav-btn:focus-visible{outline:2px solid var(--akb-rw-accent);outline-offset:2px}'
			. '.akb-rw-carousel .akb-rw-nav-btn:disabled,.akb-rw-carousel .akb-rw-nav-btn[aria-disabled="true"]{opacity:.3!important;cursor:not-allowed!important;pointer-events:none!important;transform:none!important;border-color:var(--aki-border,rgba(255,255,255,.12))!important;background:var(--akb-rw-bg)!important}'
			. '.akb-rw-carousel .akb-rw-track{display:flex;align-items:stretch;gap:20px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;padding:8px 4px 20px;-webkit-overflow-scrolling:touch;list-style:none;margin:0}'
			. '.akb-rw-carousel .akb-rw-track:focus-visible{outline:2px solid var(--akb-rw-accent);outline-offset:-2px}'
			. '.akb-rw-carousel .akb-rw-track::-webkit-scrollbar{height:4px}'
			. '.akb-rw-carousel .akb-rw-track::-webkit-scrollbar-track{background:rgba(255,255,255,.06)}'
			. '.akb-rw-carousel .akb-rw-track::-webkit-scrollbar-thumb{background:var(--akb-rw-accent)}'
			. '.akb-rw-carousel .akb-rw-card{box-sizing:border-box;position:relative;display:flex;flex-direction:column;scroll-snap-align:start;flex:0 0 min(360px,86vw);max-width:min(360px,86vw);min-height:410px;background:var(--akb-rw-bg);color:var(--akb-rw-fg);border:1px solid var(--aki-border,rgba(255,255,255,.16));border-top:3px solid var(--akb-rw-accent);border-radius:0;padding:22px;margin:0;overflow:hidden;transition:transform .22s cubic-bezier(.16,1,.3,1),box-shadow .22s ease,border-color .22s ease}'
			. '.akb-rw-carousel .akb-rw-card:hover{transform:translateY(-4px);box-shadow:0 14px 34px rgba(0,0,0,.75),4px 4px 0 var(--akb-rw-accent);border-color:rgba(255,255,255,.28)}'
			. '.akb-rw-carousel .akb-rw-stars{color:var(--akb-rw-fg);letter-spacing:3px;font-size:18px;line-height:1.5}'
			. '.akb-rw-carousel .akb-rw-title{display:block;margin:0 0 6px}'
			. '.akb-rw-carousel .akb-rw-text{margin:10px 0 14px;font-size:15.5px;line-height:1.6;overflow-wrap:anywhere;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:5;overflow:hidden}'
			. '.akb-rw-carousel .akb-rw-card:not(:has(.akb-rw-media)) .akb-rw-text{font-size:16px;line-height:1.65;-webkit-line-clamp:7}'
			. '.akb-rw-carousel .akb-rw-meta{font-size:14px;line-height:1.5;color:var(--akb-rw-fg);margin:0 0 12px}'
			. '.akb-rw-carousel .akb-rw-author{font-weight:600;margin:0 0 10px;overflow-wrap:anywhere}'
			. '.akb-rw-carousel .akb-rw-verified-pill{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--aki-green-bright,#86efac);background:rgba(20,83,45,.35);border:1px solid rgba(134,239,172,.3);padding:3px 8px;margin-bottom:12px;width:fit-content}'
			. '.akb-rw-carousel .akb-rw-media{position:relative;display:block;width:100%;height:180px;margin:6px 0 14px;background:#0d0d10;border:1px solid var(--aki-border,rgba(255,255,255,.14));box-shadow:2px 2px 0 #000;overflow:hidden;text-decoration:none}'
			. '.akb-rw-carousel .akb-rw-photo{display:block;width:100%!important;height:100%!important;object-fit:cover!important;object-position:center;margin:0;transition:transform .35s cubic-bezier(.16,1,.3,1)}'
			. '.akb-rw-carousel .akb-rw-card:hover .akb-rw-photo{transform:scale(1.04)}'
			. '.akb-rw-carousel .akb-rw-photo-tag{position:absolute;top:8px;left:8px;z-index:2;display:inline-flex;align-items:center;gap:5px;padding:3px 8px;background:rgba(10,10,12,.92);border:1px solid rgba(255,255,255,.2);color:var(--aki-white,#fff);font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase}'
			. '.akb-rw-carousel .akb-rw-disclosure{margin:0 0 12px;padding:8px 10px;border-left:2px solid var(--akb-rw-accent);background:var(--aki-surface,rgba(255,255,255,.06));font-size:12px;line-height:1.5}'
			. '.akb-rw-carousel .akb-rw-product{display:flex;align-items:center;gap:14px;margin-top:auto;padding-top:16px;min-height:64px;border-top:1px solid var(--aki-border,rgba(255,255,255,.16));font-size:14px;line-height:1.45;color:var(--akb-rw-fg);text-decoration:none;overflow-wrap:anywhere}'
			. '.akb-rw-carousel .akb-rw-product:hover .akb-rw-product-name{text-decoration:underline}'
			. '.akb-rw-carousel .akb-rw-product:focus-visible{outline:2px solid var(--akb-rw-accent);outline-offset:3px;border-radius:4px}'
			. '.akb-rw-carousel .akb-rw-cover{width:44px!important;height:66px!important;object-fit:contain;flex:none;margin:0}'
			. '.akb-rw-carousel .akb-rw-product-label{display:block;font-size:12px;font-weight:400;margin-bottom:3px}'
			. '.akb-rw-carousel .akb-rw-product-name{display:block;font-weight:600}'
			. '.akb-rw-carousel .akb-rw-read{display:block;margin-top:5px;font-size:13px;text-decoration:underline;text-underline-offset:3px}'
			. '@media(min-width:768px){.akb-rw-carousel{padding-inline:24px}.akb-rw-carousel .akb-rw-track[data-count="1"],.akb-rw-carousel .akb-rw-track[data-count="2"],.akb-rw-carousel .akb-rw-track[data-count="3"]{display:grid;grid-template-columns:repeat(var(--akb-rw-count),minmax(0,1fr))}.akb-rw-carousel:has(.akb-rw-track[data-count="1"]) .akb-rw-nav,.akb-rw-carousel:has(.akb-rw-track[data-count="2"]) .akb-rw-nav,.akb-rw-carousel:has(.akb-rw-track[data-count="3"]) .akb-rw-nav{display:none!important}.akb-rw-carousel .akb-rw-track[data-count="1"]{max-width:640px}.akb-rw-carousel .akb-rw-track[data-count="1"] .akb-rw-card,.akb-rw-carousel .akb-rw-track[data-count="2"] .akb-rw-card,.akb-rw-carousel .akb-rw-track[data-count="3"] .akb-rw-card{max-width:none}}'
			. '@media(min-width:1024px){.akb-rw-carousel .akb-rw-track[data-count="4"]{display:grid;grid-template-columns:repeat(4,minmax(0,1fr))}.akb-rw-carousel:has(.akb-rw-track[data-count="4"]) .akb-rw-nav{display:none!important}.akb-rw-carousel .akb-rw-track[data-count="4"] .akb-rw-card{max-width:none}}'
			. '@media(prefers-reduced-motion:reduce){.akb-rw-carousel .akb-rw-card,.akb-rw-carousel .akb-rw-photo,.akb-rw-carousel .akb-rw-nav-btn{transition:none!important;transform:none!important}}';

		$items = '';
		foreach ( $cards as $card ) {
			$stars = str_repeat( '★', max( 0, min( 5, (int) $card['rating'] ) ) ) . str_repeat( '☆', 5 - max( 0, min( 5, (int) $card['rating'] ) ) );

			$photo = '';
			$photo_alt = sprintf( 'Foto de la reseña de %s', (string) $card['author'] );
			if ( (int) $card['photo_id'] > 0 ) {
				$photo = wp_get_attachment_image( (int) $card['photo_id'], 'medium', false, array( 'class' => 'akb-rw-photo', 'loading' => 'lazy', 'alt' => $photo_alt ) );
			} elseif ( '' !== (string) ( $card['photo_url'] ?? '' ) ) {
				$photo = '<img class="akb-rw-photo" src="' . esc_url( (string) $card['photo_url'] ) . '" alt="' . esc_attr( $photo_alt ) . '" loading="lazy" width="80" height="96">';
			}
			$has_photo = '' !== $photo;
			$comment_link = (string) get_permalink( (int) $card['product'] ) . '#comment-' . (int) ( $card['comment'] ?? 0 );
			$photo_html = $has_photo
				? '<figure class="akb-rw-media">'
					. '<a class="akb-rw-photo-link" href="' . esc_url( $comment_link ) . '" title="Ver reseña y foto completa en el tomo">'
					. $photo
					. '<span class="akb-rw-photo-tag" aria-hidden="true">📷 Foto del lector</span>'
					. '</a>'
				  . '</figure>'
				: '';

			$product_name = (string) get_the_title( (int) $card['product'] );
			$cover = (int) ( $card['cover_id'] ?? 0 ) > 0
				? wp_get_attachment_image( (int) $card['cover_id'], 'thumbnail', false, array( 'class' => 'akb-rw-cover', 'loading' => 'lazy', 'alt' => sprintf( 'Portada de %s', $product_name ) ) )
				: '';

			$title_html = '' !== (string) $card['title']
				? '<strong class="akb-rw-title">' . esc_html( (string) $card['title'] ) . '</strong>'
				: '';

			$verified_html = ! $has_photo
				? '<span class="akb-rw-verified-pill" aria-hidden="true">✓ Opinión verificada</span>'
				: '';

			$items .= '<li class="akb-rw-card">'
				. '<span class="akb-rw-stars" aria-hidden="true">' . esc_html( $stars ) . '</span>'
				. '<span class="screen-reader-text">' . esc_html( sprintf( '%d de 5 estrellas', (int) $card['rating'] ) ) . '</span>'
				. '<p class="akb-rw-text">' . $title_html . esc_html( self::excerpt( (string) $card['text'] ) ) . '</p>'
				. '<p class="akb-rw-meta akb-rw-author">' . esc_html( (string) $card['author'] ) . '</p>'
				. $verified_html
				. $photo_html
				. ( Options::carousel_disclosure_on() && '' !== (string) ( $card['aviso'] ?? '' ) ? '<p class="akb-rw-disclosure">' . esc_html( (string) $card['aviso'] ) . '</p>' : '' )
				. ( '' !== (string) ( $card['origen'] ?? '' ) ? '<p class="akb-rw-disclosure">' . esc_html( (string) $card['origen'] ) . '</p>' : '' )
				. '<a class="akb-rw-product" href="' . esc_url( $comment_link ) . '">'
				. $cover . '<span><span class="akb-rw-product-label">Sobre este producto</span><span class="akb-rw-product-name">' . esc_html( $product_name ) . '</span><span class="akb-rw-read">Leer la reseña completa →</span></span></a>'
				. '</li>';
		}

		// La pista con overflow es alcanzable por teclado (WCAG 2.1.1) y cuenta
		// con flechas de desplazamiento suave para escritorio.
		return '<section class="akb-rw-carousel" aria-label="Reseñas de la comunidad">'
			. $this->style_once( $css )
			. '<div class="akb-rw-header">'
				. '<div class="akb-rw-header-text">'
					. '<h2>' . esc_html( Options::carousel_title() ) . '</h2>'
					. '<p class="akb-rw-meta">La comunidad Akibara opina</p>'
				. '</div>'
				. '<div class="akb-rw-nav" aria-label="Navegación del carrusel">'
					. '<button type="button" class="akb-rw-nav-btn akb-rw-prev" data-akb-rw-prev aria-label="Ver reseñas anteriores" disabled aria-disabled="true">'
						. '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>'
					. '</button>'
					. '<button type="button" class="akb-rw-nav-btn akb-rw-next" data-akb-rw-next aria-label="Ver reseñas siguientes"' . ( count( $cards ) <= 1 ? ' disabled aria-disabled="true"' : '' ) . '>'
						. '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>'
					. '</button>'
				. '</div>'
			. '</div>'
			. '<ul class="akb-rw-track" data-count="' . count( $cards ) . '" style="--akb-rw-count:' . count( $cards ) . '" tabindex="0" aria-label="Reseñas, lista desplazable">' . $items . '</ul>'
			. '</section>';
	}

	/**
	 * Recorta a ~220 caracteres respetando límites de palabras con fallback seguro
	 * si mbstring no estuviese disponible.
	 *
	 * @param string $text Texto completo de la reseña.
	 * @return string
	 */
	public static function excerpt( string $text ): string {
		$limit = 220;
		$mb    = function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' );

		$len = $mb ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		if ( $len <= $limit ) {
			return $text;
		}

		$cut   = $mb ? mb_substr( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit );
		$space = $mb && function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ', 0, 'UTF-8' ) : strrpos( $cut, ' ' );

		// Cortar en el último espacio solo si no sacrifica media card: una
		// cadena sin espacios se recorta dura (mejor que desbordar).
		if ( false !== $space && $space > 120 ) {
			$cut = $mb ? mb_substr( $cut, 0, (int) $space, 'UTF-8' ) : substr( $cut, 0, (int) $space );
		}

		return rtrim( $cut, " \t\n\r.,;:" ) . '…';
	}

	/**
	 * El bloque <style> sale UNA vez por petición aunque el shortcode se
	 * pegue dos veces en la misma página.
	 *
	 * @param string $css CSS del carrusel.
	 * @return string
	 */
	private function style_once( string $css ): string {
		if ( $this->style_printed ) {
			return '';
		}
		$this->style_printed = true;

		return '<style>' . $css . '</style>';
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/Disclosure.php
================================================================

<?php
/**
 * Disclosure — una reseña premiada dice que fue premiada.
 *
 * Resuelve el aviso de incentivo para las reseñas que cobraron puntos o
 * contaron para un premio. La presentación responde a la opción del panel.
 *
 * Va por `comment_text` a prioridad 24: después de la insignia de reseña
 * transcrita (20) y antes de la foto (26), así el orden es texto → insignia →
 * aviso → foto.
 *
 * La visualización del aviso responde a la opción `akb_rewards_disclosure_enabled`.
 * El criterio para determinar si una reseña fue incentivada permanece activo
 * independientemente del interruptor visual, dado que es requerido por filtros
 * de exclusión y datos estructurados.
 *
 * Es la única autoridad de «¿esta reseña fue incentivada?»: la usan también el
 * carrusel del home y el JSON-LD. Cuenta la que cobró puntos y, con «premiar
 * cada N reseñas», la intermedia que sumó para el premio sin cobrar.
 *
 * Se registra fuera del motor de reseñas: si Customer Reviews vuelve y el motor
 * se duerme, las reseñas ya pagadas no pueden perder el aviso.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.1
 */
final class Disclosure {

	/** La reseña cobró puntos. */
	public const TEXTO_PREMIADA = 'Quien escribió esta reseña ganó Puntos Akibara por publicarla con foto.';

	/** «Premiar cada N reseñas»: no cobró, pero cuenta para el premio. */
	public const TEXTO_SUMA = 'Quien escribió esta reseña sumó para un premio de Puntos Akibara.';

	public function register(): void {
		add_filter( 'comment_text', array( $this, 'append_notice' ), 24, 2 );
	}

	/**
	 * El aviso de una reseña, si el dueño lo tiene encendido.
	 *
	 * @param int $comment_id Reseña.
	 * @return string
	 */
	public static function visible_notice_for( int $comment_id ): string {
		return Options::disclosure_on() ? self::notice_for( $comment_id ) : '';
	}

	/**
	 * El aviso que corresponde a una reseña; '' si no fue incentivada.
	 *
	 * El sello `pending_account` (invitado que todavía no cobró) castea a 0:
	 * aún no es un premio.
	 *
	 * @param int $comment_id Reseña.
	 * @return string
	 */
	public static function notice_for( int $comment_id ): string {
		if ( $comment_id <= 0 ) {
			return '';
		}

		if ( (int) get_comment_meta( $comment_id, ReviewPoints::META_POINTS_PAID, true ) > 0 ) {
			return self::TEXTO_PREMIADA;
		}

		return (int) get_comment_meta( $comment_id, ReviewPoints::META_COUNTED, true ) > 0 ? self::TEXTO_SUMA : '';
	}

	/**
	 * Añade el aviso al final del texto de una reseña premiada.
	 *
	 * @param string           $text    Texto ya filtrado.
	 * @param \WP_Comment|null $comment Comentario, cuando el llamador lo pasa.
	 * @return string
	 */
	public function append_notice( $text, $comment = null ): string {
		$text = (string) $text;

		if ( ! $comment instanceof \WP_Comment ) {
			return $text;
		}
		if ( 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return $text;
		}
		$aviso = self::visible_notice_for( (int) $comment->comment_ID );
		if ( '' === $aviso ) {
			return $text;
		}

		// «Clara y prominente» es la vara de Google para una reseña incentivada:
		// sin opacidad, con su propio recuadro y a tamaño de lectura. En gris
		// claro se leía como letra chica, que es justo lo que la guía descarta.
		return $text . '<p class="akb-rw-disclosure" style="margin:8px 0 0;padding:6px 10px;border-left:3px solid var(--aki-red,#E60012);background:rgba(230,0,18,.06);font-size:0.9em;line-height:1.4">'
			. esc_html( $aviso )
			. '</p>';
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/HomeCache.php
================================================================

<?php
/** Invalida exclusivamente las copias de la portada en Cloudflare. @package Akibara\Rewards */
declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Log;

defined( 'ABSPATH' ) || exit;

final class HomeCache {

	public const TAG  = 'akb_reviews_home_v1';
	public const HOOK = 'akb_rewards_home_cache_retry';
	private static bool $queued = false;

	public static function register(): void {
		add_filter( 'wp_headers', array( self::class, 'tag_headers' ) );
		add_action( self::HOOK, array( self::class, 'purge' ), 10, 1 );
	}

	/** @param array<string,string> $headers Cabeceras de WordPress. @return array<string,string> */
	public static function tag_headers( array $headers ): array {
		if ( is_front_page() ) {
			$headers['Cache-Tag'] = isset( $headers['Cache-Tag'] ) ? $headers['Cache-Tag'] . ',' . self::TAG : self::TAG;
		}
		return $headers;
	}

	/** Una sola purga por petición, después de invalidar el origen. */
	public static function queue_purge(): void {
		if ( self::$queued || null === self::credentials() ) {
			return;
		}
		self::$queued = true;
		add_action( 'shutdown', array( self::class, 'purge' ), 30 );
	}

	/** @return array{zone:string,key:string,email:string}|null */
	private static function credentials(): ?array {
		// El espejo puede contener opciones copiadas: nunca debe llamar al borde real.
		if ( 'https://akibara.cl' !== rtrim( (string) get_option( 'home', '' ), '/' ) || 'production' !== wp_get_environment_type()
			|| ! get_option( 'litespeed.conf.cdn-cloudflare', false ) ) {
			return null;
		}
		$zone = (string) get_option( 'litespeed.conf.cdn-cloudflare_zone', '' );
		$key  = (string) get_option( 'litespeed.conf.cdn-cloudflare_key', '' );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $zone ) || '' === $key ) {
			return null;
		}
		return array( 'zone' => $zone, 'key' => $key, 'email' => (string) get_option( 'litespeed.conf.cdn-cloudflare_email', '' ) );
	}

	/** @param int|string $attempt Reintento acotado; sin URL ni credencial en la cola. */
	public static function purge( int|string $attempt = 0 ): void {
		$attempt = (int) $attempt;
		$access = self::credentials();
		if ( null === $access ) {
			return;
		}
		$global = 0 === strpos( $access['key'], 'cfk_' ) || 1 === preg_match( '/^[0-9a-f]{37,45}$/', $access['key'] );
		$headers = array( 'Content-Type' => 'application/json' );
		if ( $global ) {
			$headers['X-Auth-Email'] = $access['email'];
			$headers['X-Auth-Key']   = $access['key'];
		} else {
			$headers['Authorization'] = 'Bearer ' . $access['key'];
		}
		$response = wp_remote_post(
			'https://api.cloudflare.com/client/v4/zones/' . $access['zone'] . '/purge_cache',
			array( 'timeout' => 8, 'headers' => $headers, 'body' => (string) wp_json_encode( array( 'tags' => array( self::TAG ) ) ) )
		);
		$body = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) && ! empty( $body['success'] ) ) {
			return;
		}
		// No registrar la respuesta externa: puede contener datos de autenticación.
		Log::error( 'No se pudo actualizar la caché pública de reseñas de la portada.' );
		if ( $attempt < 2 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 75, self::HOOK, array( $attempt + 1 ), 'akibara-rewards', true );
		}
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/InvitationReschedule.php
================================================================

<?php
/** Explicit, previewed rescheduling of pending review invitations.
 *
 * @package Akibara\Rewards
 */
declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

/** Keeps settings changes separate from existing queue changes. */
final class InvitationReschedule {
	public const SLUG = 'akb-review-reschedule';
	private const PREVIEW = 'akb_reviews_reschedule_preview';
	private const APPLY = 'akb_reviews_reschedule_apply';
	private const LIMIT = 50;
	private const TRANSIENT = 'akb_review_reschedule_';
	/** @var object|null Scheduler store; injectable for isolated queue tests. */
	private $store;

	/** Accept a scheduler store for tests; production resolves the official store. */
	public function __construct( $store = null ) {
		$this->store = $store;
	}

	/** Register the hidden review detail and explicit POST operations. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 99 );
		add_action( 'admin_post_' . self::PREVIEW, array( $this, 'handle_preview' ) );
		add_action( 'admin_post_' . self::APPLY, array( $this, 'handle_apply' ) );
	}

	/** Register the page; the overview provides its navigation link. */
	public function add_page(): void {
		add_submenu_page( '', 'Reprogramar invitaciones', 'Reprogramar invitaciones', 'manage_woocommerce', self::SLUG, array( $this, 'render' ) );
	}

	/** Return the stable admin URL. */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/** Store a preview for this administrator without modifying orders or actions. */
	public function handle_preview(): void {
		$this->authorize( self::PREVIEW );
		$offset = isset( $_POST['preview_offset'] ) ? absint( $_POST['preview_offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the operation nonce above.
		$plan = $this->preview( $offset );
		$plan['token'] = wp_generate_uuid4();
		set_transient( self::TRANSIENT . get_current_user_id(), $plan, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Consume exactly the server-side preview that the administrator approved. */
	public function handle_apply(): void {
		$this->authorize( self::APPLY );
		$key   = self::TRANSIENT . get_current_user_id();
		$plan  = get_transient( $key );
		$token = isset( $_POST['preview_token'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the operation nonce above.
		if ( ! is_array( $plan ) || '' === $token || ! hash_equals( (string) ( $plan['token'] ?? '' ), $token ) ) {
			wp_die( 'La vista previa venció o ya fue utilizada. Genera otra antes de aplicar.' );
		}
		delete_transient( $key );
		$result = $this->apply( $plan );
		set_transient( $key . '_result', $result, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Read at most fifty candidates and freeze both the old and proposed dates. */
	public function preview( int $offset = 0 ): array {
		$plan = array( 'rows' => array(), 'skipped' => 0, 'more' => false, 'offset' => max( 0, $offset ), 'policy' => $this->policy(), 'created' => time() );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Invitations::enabled() || ! Options::reviews_engine_on() ) {
			return $plan;
		}
		$ids = $this->scheduler()->query_actions( array( 'hook' => Invitations::HOOK, 'status' => 'pending', 'claimed' => false, 'per_page' => self::LIMIT + 1, 'offset' => $plan['offset'], 'orderby' => 'date', 'order' => 'ASC' ) );
		$plan['more'] = count( $ids ) > self::LIMIT;
		foreach ( array_slice( $ids, 0, self::LIMIT ) as $id ) {
			$row = $this->candidate( (int) $id );
			if ( null === $row ) {
				++$plan['skipped'];
				continue;
			}
			$days = $row['trigger'] === Invitations::trigger_status() ? Invitations::delay_days() : Invitations::pickup_delay_days();
			$base = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $row['scheduled'], wp_timezone() );
			if ( false === $base || $base->format( 'Y-m-d H:i:s' ) !== $row['scheduled'] ) {
				++$plan['skipped'];
				continue;
			}
			$row['new_due'] = max( $plan['created'] + 5 * MINUTE_IN_SECONDS, $base->getTimestamp() + $days * DAY_IN_SECONDS );
			if ( $row['new_due'] === $row['old_due'] ) {
				++$plan['skipped'];
				continue;
			}
			$plan['rows'][] = $row;
		}
		return $plan;
	}

	/** Apply frozen dates only after claiming and revalidating the original actions. */
	public function apply( array $plan ): array {
		$result = array( 'changed' => 0, 'skipped' => 0, 'errors' => 0 );
		$rows   = array_slice( (array) ( $plan['rows'] ?? array() ), 0, self::LIMIT );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Invitations::enabled() || ! Options::reviews_engine_on() || ( $plan['policy'] ?? array() ) !== $this->policy() || (int) ( $plan['created'] ?? 0 ) < time() - 10 * MINUTE_IN_SECONDS ) {
			$result['skipped'] = count( $rows );
			return $result;
		}
		if ( ! $rows ) {
			return $result;
		}
		$groups = array();
		foreach ( $rows as $row ) {
			$groups[ (string) ( $row['group'] ?? '' ) ][] = $row;
		}
		if ( count( $groups ) > 1 ) {
			foreach ( $groups as $group_rows ) {
				$partial = $this->apply( array_merge( $plan, array( 'rows' => $group_rows ) ) );
				foreach ( $result as $key => $value ) {
					$result[ $key ] = $value + $partial[ $key ];
				}
			}
			return $result;
		}
		$store = $this->scheduler();
		$claim = null;
		$approved_ids = implode( ',', array_map( 'intval', array_column( $rows, 'action_id' ) ) );
		$claim_order = static function ( string $sql, $claim_id, array $hooks ) use ( $approved_ids ): string {
			unset( $claim_id );
			return array( Invitations::HOOK ) === $hooks ? 'ORDER BY CASE WHEN action_id IN (' . $approved_ids . ') THEN 0 ELSE 1 END, scheduled_date_gmt ASC, action_id ASC' : $sql;
		};
		$old_filters = array();
		if ( method_exists( $store, 'get_claim_filter' ) ) {
			foreach ( array( 'hooks', 'group', 'exclude-groups' ) as $filter ) {
				$old_filters[ $filter ] = $store->get_claim_filter( $filter );
			}
		}
		try {
			add_filter( 'action_scheduler_claim_actions_order_by', $claim_order, 10, 3 );
			$claim = $store->stake_claim( count( $rows ), new \DateTime( '@' . max( array_column( $rows, 'old_due' ) ) ), array( Invitations::HOOK ), (string) array_key_first( $groups ) );
			$claimed = array_map( 'intval', $claim->get_actions() );
			foreach ( $rows as $row ) {
				$id = (int) $row['action_id'];
				if ( ! in_array( $id, $claimed, true ) || (int) $row['new_due'] <= time() + 30 ) {
					++$result['skipped'];
					continue;
				}
				$current = $this->candidate( $id, (int) $claim->get_id() );
				$expected = $row;
				unset( $expected['new_due'] );
				if ( $current !== $expected ) {
					++$result['skipped'];
					continue;
				}
				$new_id = 0;
				try {
					$action = $store->fetch_action( $id );
					// Create the future replacement first: on write failure the old
					// invitation remains intact. The claimed old job cannot run.
					$new_id = (int) as_schedule_single_action( (int) $row['new_due'], Invitations::HOOK, array( (int) $row['order_id'] ), $action->get_group(), false, $action->get_priority() );
					if ( $new_id <= 0 ) {
						++$result['errors'];
						continue;
					}
					$store->cancel_action( $id );
					if ( 'canceled' !== $store->get_status( $id ) ) {
						throw new \RuntimeException( 'Original action was not canceled.' );
					}
				} catch ( \Throwable $error ) {
					// A cancellation hook can throw after the original was already
					// canceled. Keep its replacement in that case.
					if ( $new_id <= 0 || 'canceled' !== $store->get_status( $id ) ) {
						if ( $new_id > 0 ) {
							$store->cancel_action( $new_id );
						}
						++$result['errors'];
						continue;
					}
				}
				++$result['changed'];
				$order = wc_get_order( (int) $row['order_id'] );
				if ( $order instanceof \WC_Order ) {
					$order->update_meta_data( Invitations::META_DUE, (int) $row['new_due'] );
					$order->save_meta_data();
				}
			}
		} catch ( \Throwable $error ) {
			++$result['errors'];
		} finally {
			remove_filter( 'action_scheduler_claim_actions_order_by', $claim_order, 10 );
			if ( null !== $claim ) {
				$store->release_claim( $claim );
			}
			if ( method_exists( $store, 'set_claim_filter' ) ) {
				foreach ( $old_filters as $filter => $value ) {
					$store->set_claim_filter( $filter, $value );
				}
			}
		}
		return $result;
	}

	/** Render preview controls and exact dates, without performing queue mutations. */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$key = self::TRANSIENT . get_current_user_id();
		$plan = get_transient( $key );
		$result = get_transient( $key . '_result' );
		echo '<div class="wrap"><h1>Reprogramar invitaciones pendientes</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=akb-rewards-settings' ) ) . '">← Volver a Puntos y Reseñas</a></p>';
		echo '<p>Aplica los plazos actuales desde la fecha original de despacho o retiro guardada al agendar. Se revisan hasta 50 acciones por tanda. Las enviadas, en ejecución, con fallos de envío o sin un pedido elegible se conservan.</p><p>Si la nueva fecha ya pasó, la vista previa propone cinco minutos desde ahora. Aplicar no envía correos directamente: cambia la fecha para que la cola los procese. Los cambios de ajustes por sí solos no reprograman.</p>';
		if ( is_array( $result ) ) {
			delete_transient( $key . '_result' );
			echo '<div class="notice notice-info inline"><p>Reprogramadas: ' . esc_html( (string) $result['changed'] ) . '. Omitidas por cambios o vencimiento: ' . esc_html( (string) $result['skipped'] ) . '. Errores: ' . esc_html( (string) $result['errors'] ) . '.</p></div>';
		}
		if ( ! Invitations::enabled() || ! Options::reviews_engine_on() ) {
			echo '<p>Activa el motor y las invitaciones antes de preparar una reprogramación.</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::PREVIEW );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::PREVIEW ) . '"><button class="button">Preparar vista previa</button></form>';
		if ( is_array( $plan ) ) {
			echo '<h2>Vista previa: ' . esc_html( (string) count( $plan['rows'] ) ) . ' invitaciones</h2><p>Sin cambios o no elegibles en esta tanda: ' . esc_html( (string) $plan['skipped'] ) . '. Fechas en la zona horaria de la tienda. La vista previa vence en diez minutos.</p>';
			if ( $plan['more'] ) {
				echo '<p>Hay más acciones pendientes. Puedes revisar otra tanda sin aplicar ésta.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::PREVIEW );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::PREVIEW ) . '"><input type="hidden" name="preview_offset" value="' . esc_attr( (string) ( $plan['offset'] + self::LIMIT ) ) . '"><button class="button">Ver siguientes 50 pendientes</button></form>';
			}
			if ( $plan['rows'] ) {
				echo '<table class="widefat striped"><thead><tr><th scope="col">Pedido</th><th scope="col">Fecha actual</th><th scope="col">Nueva fecha</th></tr></thead><tbody>';
				foreach ( $plan['rows'] as $row ) {
					echo '<tr><td>#' . esc_html( (string) $row['order_id'] ) . '</td><td>' . esc_html( wp_date( 'd-m-Y H:i', $row['old_due'] ) ) . '</td><td>' . esc_html( wp_date( 'd-m-Y H:i', $row['new_due'] ) ) . '</td></tr>';
				}
				echo '</tbody></table><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::APPLY );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::APPLY ) . '"><input type="hidden" name="preview_token" value="' . esc_attr( $plan['token'] ) . '"><p><button class="button button-primary">Aplicar estas fechas a ' . esc_html( (string) count( $plan['rows'] ) ) . ' invitaciones</button></p></form>';
			}
		}
		echo '</div>';
	}

	/** Validate a specific original action against its live order and scheduling evidence. */
	private function candidate( int $id, int $claim_id = 0 ): ?array {
		$store = $this->scheduler();
		if ( 'pending' !== $store->get_status( $id ) || (int) $store->get_claim_id( $id ) !== $claim_id ) {
			return null;
		}
		$action = $store->fetch_action( $id );
		$args   = $action->get_args();
		if ( Invitations::HOOK !== $action->get_hook() || count( $args ) !== 1 || ! isset( $args[0] ) || $action->get_schedule()->is_recurring() ) {
			return null;
		}
		$order = wc_get_order( (int) $args[0] );
		if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( Invitations::META_SENT ) || (int) $order->get_meta( Invitations::META_ATTEMPTS ) > 0 || '' !== Invitations::waiting_reason( $order ) ) {
			return null;
		}
		$trigger = (string) $order->get_meta( Invitations::META_TRIGGER );
		$status  = (string) $order->get_status();
		if ( ! Invitations::is_trigger( $trigger ) || ( ! OrderStates::is_at_or_after( $status, $trigger ) && $status !== Invitations::pickup_status() ) ) {
			return null;
		}
		$jobs = $store->query_actions( array( 'hook' => Invitations::HOOK, 'args' => array( (int) $order->get_id() ), 'status' => array( 'pending', 'in-progress' ), 'per_page' => 2 ) );
		if ( count( $jobs ) !== 1 || (int) $jobs[0] !== $id ) {
			return null;
		}
		return array( 'action_id' => $id, 'order_id' => (int) $order->get_id(), 'old_due' => $action->get_schedule()->get_date()->getTimestamp(), 'status' => $status, 'trigger' => $trigger, 'scheduled' => (string) $order->get_meta( Invitations::META_SCHEDULED ), 'group' => $action->get_group() );
	}

	/** Resolve the official scheduler storage. */
	private function scheduler() {
		return $this->store ?? \ActionScheduler::store();
	}

	/** Reject previews made against a different set of route settings. */
	private function policy(): array {
		return array( Invitations::trigger_status(), Invitations::delay_days(), Invitations::pickup_status(), Invitations::pickup_delay_days() );
	}

	/** Require both administrator capability and the operation's nonce. */
	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( $action );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/Invitations.php
================================================================

<?php
/**
 * Invitations — invitación a reseñar por correo con enlace de TOKEN (Fase 2b).
 *
 * Diseño sellado (00-DISENO §Flujo de invitación):
 *   - Dos rutas configurables: despacho y entrega directa o retiro.
 *     La primera agenda UN solo job de Action Scheduler por pedido.
 *   - Token por pedido en {prefix}akb_rewards_review_forms: caducidad
 *     configurable (30 días), un solo uso, re-invitación posible tras
 *     reversa (tokens históricos quedan auditablemente en la tabla).
 *   - Correo brandeado sellado SOLO con transporte confirmado (disciplina
 *     de Emails.php); en fallo, reintento acotado vía el mismo scheduler.
 *   - Interruptor global de activación inmediata en un solo clic.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;

use Akibara\Rewards\Points\Emails;
use Akibara\Rewards\Support\Log;
use Akibara\Rewards\Support\OrderStates;
use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class Invitations {

	public const HOOK           = 'akb_rewards_send_review_invite';
	public const META_SCHEDULED = '_akb_rewards_invite_scheduled';
	/**
	 * Estado con el que se agendó ESTA invitación.
	 *
	 * Preserva el disparador de la invitación en cola aunque el dueño cambie
	 * posteriormente el estado configurado para las nuevas invitaciones.
	 */
	public const META_TRIGGER   = '_akb_rewards_invite_trigger';
	public const META_SENT      = '_akb_rewards_invite_sent';
	public const META_ATTEMPTS  = '_akb_rewards_invite_attempts';
	public const META_DUE       = '_akb_rewards_invite_due';
	public const META_REASON    = '_akb_rewards_invite_reason';
	private const MAX_ATTEMPTS  = 3;

	/**
	 * ¿El carril está encendido? (clic del dueño).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return 'yes' === (string) get_option( 'akb_rewards_invite_enabled', 'yes' );
	}

	/**
	 * Estado del pedido que dispara la invitación.
	 *
	 * @return string
	 */
	public static function trigger_status(): string {
		$status = sanitize_key( (string) get_option( 'akb_rewards_invite_status', 'completed' ) );
		return '' !== $status ? $status : 'completed';
	}

	/**
	 * Días de espera tras el estado disparador.
	 *
	 * @return int
	 */
	public static function delay_days(): int {
		return max( 0, (int) get_option( 'akb_rewards_invite_delay_days', 3 ) );
	}

	/** Estado alternativo para una entrega directa o retiro. */
	public static function pickup_status(): string {
		return sanitize_key( (string) get_option( 'akb_rewards_invite_pickup_status', 'completed' ) );
	}

	/** Días corridos de la ruta de entrega directa; hereda el plazo existente. */
	public static function pickup_delay_days(): int {
		return max( 0, (int) get_option( 'akb_rewards_invite_pickup_delay_days', self::delay_days() ) );
	}

	/** Una ruta configurada, independiente de la empresa de transporte. */
	public static function is_trigger( string $status ): bool {
		return in_array( $status, array( self::trigger_status(), self::pickup_status() ), true );
	}

	/**
	 * Requisitos comunes, también comprobados cuando llega el día de envío.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return string Motivo para esperar; vacío si puede seguir.
	 */
	public static function waiting_reason( \WC_Order $order ): string {
		$status = (string) $order->get_status();
		if ( OrderStates::is_down( $status ) ) {
			return 'Pedido cancelado, fallido o reembolsado.';
		}
		if ( in_array( $status, array( 'pending', 'on-hold', 'checkout-draft' ), true ) || ! $order->get_date_paid() ) {
			return 'Esperando confirmación del pago.';
		}
		return '';
	}

	/** Una transición posterior no invalida la ruta con la que se agendó. */
	private static function still_dispatched( \WC_Order $order ): bool {
		$status  = (string) $order->get_status();
		$trigger = (string) $order->get_meta( self::META_TRIGGER );
		return '' !== $trigger
			? ( OrderStates::is_at_or_after( $status, $trigger ) || $status === self::pickup_status() )
			: self::is_trigger( $status );
	}

	/**
	 * Vida del token en días.
	 *
	 * @return int
	 */
	public static function token_days(): int {
		return max( 1, (int) get_option( 'akb_rewards_invite_token_days', 30 ) );
	}

	/**
	 * Cuelga agenda, desagenda y el job.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 3 );
		add_action( self::HOOK, array( $this, 'send_invite' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( new AdminOverview(), 'render_order' ) );
	}

	/**
	 * Agenda al entrar al estado disparador; desagenda si el pedido se cae.
	 *
	 * @param int    $order_id   Pedido.
	 * @param string $old_status Estado anterior (sin usar).
	 * @param string $new_status Estado nuevo.
	 * @return void
	 */
	public function on_status_changed( $order_id, $old_status, $new_status ): void {
		unset( $old_status );

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$order_id   = (int) $order_id;
		$new_status = (string) $new_status;

		if ( OrderStates::is_down( $new_status ) ) {
			as_unschedule_all_actions( self::HOOK, array( $order_id ) );
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_SENT ) ) {
			return; // Ya invitado en la historia del pedido.
		}

		$reason = self::waiting_reason( $order );
		if ( '' !== $reason || ( ! self::is_trigger( $new_status ) && ! self::still_dispatched( $order ) ) ) {
			as_unschedule_all_actions( self::HOOK, array( $order_id ) );
			$order->update_meta_data( self::META_REASON, '' !== $reason ? $reason : 'Esperando despacho al transportista o entrega directa.' );
			$order->save_meta_data();
			return;
		}
		if ( ! self::enabled() || ! self::is_trigger( $new_status ) ) {
			return;
		}

		if ( false !== as_next_scheduled_action( self::HOOK, array( $order_id ) ) ) {
			return; // Un solo job por pedido.
		}

		$days = $new_status === self::trigger_status() ? self::delay_days() : self::pickup_delay_days();
		$due  = time() + $days * DAY_IN_SECONDS;
		$job  = as_schedule_single_action(
			$due,
			self::HOOK,
			array( $order_id ),
			'',
			true
		);
		if ( ! $job ) {
			$order->update_meta_data( self::META_REASON, 'No se pudo programar la invitación. Revisa la cola de acciones.' );
			$order->save_meta_data();
			return;
		}
		$order->update_meta_data( self::META_DUE, $due );
		$order->update_meta_data( self::META_REASON, '' );
		$order->update_meta_data( self::META_SCHEDULED, current_time( 'mysql' ) );
		// Guarda la ruta elegida para respetar la cola si cambian los ajustes.
		$order->update_meta_data( self::META_TRIGGER, $new_status );
		$order->save_meta_data();
	}

	/**
	 * El job: crea el token y manda el correo. Sella SOLO con transporte
	 * confirmado; en fallo re-agenda con tope de intentos.
	 *
	 * @param int $order_id Pedido.
	 * @return void
	 */
	public function send_invite( $order_id ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_SENT ) ) {
			return; // Idempotente: job duplicado o reintento tardío.
		}
		if ( (int) $order->get_meta( self::META_ATTEMPTS ) >= self::MAX_ATTEMPTS ) {
			return; // Un job duplicado no vuelve a enviar tras agotar los intentos.
		}

		// Revalida pago, disponibilidad y avance sin cambiar la política
		// guardada cuando se creó la invitación.
		$reason = self::waiting_reason( $order );
		if ( '' !== $reason || ! self::still_dispatched( $order ) ) {
			$reason = '' !== $reason ? $reason : 'El pedido volvió a un estado anterior al despacho o retiro.';
			$order->update_meta_data( self::META_REASON, $reason );
			$order->save_meta_data();
			$order->add_order_note(
				'Invitación a reseñar detenida: ' . $reason
			);
			return;
		}

		$to = (string) $order->get_billing_email();
		if ( '' === $to ) {
			$order->update_meta_data( self::META_REASON, 'No enviada: el pedido no tiene correo de contacto.' );
			$order->save_meta_data();
			return;
		}

		// Comprueba la baja al enviar: puede haberse solicitado después de agendar.
		if ( InviteOptOut::opted_out( $to ) ) {
			$order->update_meta_data( self::META_REASON, 'No enviada: el cliente pidió no recibir invitaciones.' );
			$order->save_meta_data();
			return;
		}

		$token = self::issue_token( (int) $order->get_id() );
		if ( '' === $token ) {
			return; // Infraestructura de DB: la nota queda en el log.
		}

		$sent = $this->deliver( $order, $to, $token );

		if ( $sent ) {
			$order->update_meta_data( self::META_SENT, current_time( 'mysql' ) );
			$order->save_meta_data();
			return;
		}

		$attempts = (int) $order->get_meta( self::META_ATTEMPTS ) + 1;
		$order->update_meta_data( self::META_ATTEMPTS, $attempts );
		$order->save_meta_data();

		if ( $attempts < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 6 * HOUR_IN_SECONDS, self::HOOK, array( (int) $order->get_id() ) );
		} elseif ( self::MAX_ATTEMPTS === $attempts ) {
			$order->add_order_note( 'La invitación a reseñar no salió tras tres intentos. Revisa la dirección del cliente y la conexión de correo de la tienda.' );
			/**
			 * El circuito de alertas también cubre la invitación agotada.
			 *
			 * @param string           $hook Trabajo agotado.
			 * @param array<int,mixed> $args Pedido e intento final.
			 */
			do_action( 'akb_rewards_email_gave_up', self::HOOK, array( (int) $order->get_id(), $attempts ) );
		}
	}

	/**
	 * Emite un token nuevo para el pedido.
	 *
	 * @param int $order_id Pedido.
	 * @return string '' si el insert falló.
	 */
	public static function issue_token( int $order_id ): string {
		global $wpdb;

		$token = wp_generate_password( 40, false, false );

		$ok = $wpdb->insert(
			Schema::review_forms_table(),
			array(
				'order_id'   => $order_id,
				'token'      => $token,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::token_days() * DAY_IN_SECONDS ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( false === $ok ) {
			Log::error( sprintf( 'Token de invitación no emitido para el pedido #%d: %s', $order_id, (string) $wpdb->last_error ) );
			return '';
		}

		return $token;
	}

	/**
	 * Correo brandeado de invitación con el enlace por token.
	 *
	 * @param \WC_Order $order Pedido.
	 * @param string    $to    Destinatario.
	 * @param string    $token Token emitido.
	 * @return bool True si el transporte confirmó.
	 */
	private function deliver( \WC_Order $order, string $to, string $token ): bool {
		$nombre = trim( (string) $order->get_billing_first_name() );
		$url    = ReviewForm::url_for( $token );

		$rewards = Options::reviews_lane_on();
		$premio = $rewards
			? '<p style="margin:0 0 12px">Cuéntanos tu experiencia con una foto real de tu compra para solicitar puntos. La reseña y el premio quedan pendientes de revisión.</p>'
			: '<p style="margin:0 0 12px">Cuéntanos tu experiencia en una reseña voluntaria sin puntos; la foto es opcional.</p>';
		$reglas = RewardMessage::text();
		if ( '' !== $reglas ) {
			$premio .= '<p style="margin:0 0 12px">' . esc_html( $reglas ) . '</p>';
		}

		// Este correo PIDE algo; los demás del programa cuentan un hecho. Por
		// eso —y sólo aquí— lleva su salida: un enlace visible al pie y la
		// cabecera que pinta el «Cancelar suscripción» nativo de Gmail.
		// Con firma propia, NO con el token del formulario: ese secreto publica
		// reseñas y acredita puntos, y esta URL viaja en una cabecera que leen
		// pasarelas de correo y servicios de baja masiva.
		$baja = InviteOptOut::url_for( $order );

		$dias_token = self::token_days();
		$plazo      = 1 === $dias_token ? '1 día' : sprintf( '%d días', $dias_token );

		$body = sprintf(
			'<p style="margin:0 0 12px">%s¿Qué te pareció tu pedido? Tu opinión ayuda a otros coleccionistas a decidir.</p>
			%s
			%s
			<p style="margin:12px 0 0;font-size:12px;opacity:.7">El enlace es personal y vence en %s.</p>
			<p style="margin:8px 0 0;font-size:12px;opacity:.7">¿Prefieres que no te pidamos reseñas? <a href="%s" style="color:inherit">Dínoslo aquí</a> y dejamos de hacerlo; los correos de tus pedidos y tus puntos seguirán llegando.</p>',
			'' !== $nombre ? esc_html( $nombre ) . ', ' : '',
			$premio,
			Emails::cta( $rewards ? 'Reseñar y obtener puntos' : 'Escribir mi reseña', $url ),
			esc_html( $plazo ),
			esc_url( $baja )
		);

		return Emails::send_branded(
			$to,
			'[Akibara] ¿Qué te pareció tu pedido?',
			'Cuéntanos qué te pareció',
			$body,
			Emails::context( 'review_invite', (int) $order->get_customer_id(), $order ),
			array(
				'List-Unsubscribe: <' . $baja . '>',
				'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
			)
		);
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/InviteOptOut.php
================================================================

<?php
/**
 * InviteOptOut — la salida del cliente que no quiere que le pidan reseñas.
 *
 * La preferencia del cliente se aplica a las invitaciones para reseñar. Los
 * avisos transaccionales del pedido y del saldo conservan su propio flujo.
 *
 * Decisiones que explican la forma de este fichero:
 *
 *   - **La baja es por CORREO, no por cuenta.** La mitad de los pedidos son de
 *     invitado; una preferencia guardada en `user_meta` no existiría para
 *     ellos. La lista guarda el SHA-256 del correo normalizado: basta para
 *     reconocer a quien pidió la baja y no deja una lista de direcciones
 *     legible en la tabla de options.
 *   - **El enlace no da de baja por sí solo.** Un GET que cambia estado lo
 *     dispara cualquier prefetch de cliente de correo o antivirus de enlaces:
 *     el enlace abre una página con un botón, y la baja viaja por POST. La
 *     excepción es el «un clic» del RFC 8058, que Gmail manda como POST con
 *     su propio campo y por eso sí se atiende de inmediato.
 *   - **Nunca es un callejón.** La misma página deja volver a activarlas: una
 *     baja por error sin vuelta atrás obliga a escribir, que es justo lo que
 *     este fichero vino a evitar.
 *
 * El enlace lleva el número del pedido y una firma HMAC de ese número con la
 * sal del sitio. La dirección se resuelve desde el pedido al validar, por lo
 * que corregir su correo no invalida un enlace ya enviado. No reutiliza el
 * token del formulario de reseña:
 *
 *   - El token de reseña expira y se purga con el tiempo; la firma HMAC es permanente
 *     y garantiza que la desuscripción funcione indefinidamente.
 *   - Principio de mínimo privilegio: la cabecera `List-Unsubscribe` es leída por
 *     filtros antispam automáticos y no debe otorgar permisos para enviar reseñas ni acreditar puntos.
 *
 * La firma no vive en ninguna tabla (no caduca ni se purga), no abre nada más
 * que esta página y no expone el correo. Deja de valer en dos casos, y en los
 * dos la página responde 404 con el contacto a mano: si el sitio rota sus
 * sales, o si el pedido ya no existe.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\PrivatePage;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.9
 */
final class InviteOptOut {

	public const QUERY_VAR = 'akb_rw_baja';

	/**
	 * Lista de bajas: array<string hash, int timestamp>.
	 */
	private const OPTION = 'akb_rewards_invite_optout';

	/**
	 * Enlace de baja para el pedido que recibe la invitación.
	 *
	 * Query string y no una ruta bonita a propósito: una `rewrite rule` nueva
	 * necesita un flush que un despliegue por ZIP no dispara, y la baja daría
	 * 404 justo en el correo que la ofrece.
	 *
	 * @param \WC_Order $order Pedido invitado.
	 * @return string '' si el pedido no tiene correo.
	 */
	public static function url_for( \WC_Order $order ): string {
		$ref = self::ref_for( (int) $order->get_id(), (string) $order->get_billing_email() );

		// Bajo la URL de Mi cuenta y no en la portada: el borde de la tienda
		// cachea diez minutos cualquier HTML de invitado aunque diga
		// `no-store`, y esa ruta es de las que deja pasar. Ver PrivatePage.
		return '' === $ref ? '' : add_query_arg( self::QUERY_VAR, $ref, PrivatePage::base_url() );
	}

	/**
	 * «<pedido>-<firma>»: lo que viaja en el enlace.
	 *
	 * @param int    $order_id Pedido.
	 * @param string $email    Correo de facturación del pedido.
	 * @return string '' si falta alguno de los dos.
	 */
	private static function ref_for( int $order_id, string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( $order_id <= 0 || '' === $email ) {
			return '';
		}

		// La firma permanece válida al corregir el correo de facturación;
		// la validación obtiene la dirección actual desde el pedido firmado.
		return $order_id . '-' . substr( hash_hmac( 'sha256', 'akb_rw_baja|' . $order_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * ¿Este correo pidió no recibir más invitaciones?
	 *
	 * @param string $email Dirección del pedido.
	 * @return bool
	 */
	public static function opted_out( string $email ): bool {
		$clave = self::clave( $email );

		return '' !== $clave && isset( self::lista()[ $clave ] );
	}

	/**
	 * Cuántos clientes pidieron la baja (para el panel del dueño).
	 *
	 * @return int
	 */
	public static function total(): int {
		return count( self::lista() );
	}

	/**
	 * Cuelga la página pública.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		// Prioridad 1, como las demás bajas de la tienda: antes de que la
		// página de Mi cuenta decida pintar su formulario de acceso.
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	/**
	 * @param array<int,string> $vars Query vars públicas.
	 * @return array<int,string>
	 */
	public function register_query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Pinta la página de baja y termina la petición.
	 *
	 * Sólo la cáscara con efectos: el `exit` no deja probar nada, así que la
	 * decisión entera vive en `responder()`.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		$token = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $token ) {
			return;
		}

		PrivatePage::no_cache( 'Akibara Rewards: preferencias de correo' );

		$metodo = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- el token del enlace es el secreto y viaja en el propio POST; ver responder().
		$post = (array) wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$respuesta = self::responder( $token, $metodo, $post );

		// Un enlace que no se reconoce NO puede responder 200: el «Cancelar
		// suscripción» de Gmail es un POST sin pantalla, lee cualquier 2xx como
		// «hecho» y se lo dice al cliente — que seguiría recibiendo correos
		// creyendo que ya se dio de baja.
		status_header( $respuesta['status'] );

		echo $respuesta['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML propio, escapado por partes.
		exit;
	}

	/**
	 * La página entera para un token, un método y un POST dados.
	 *
	 * El token ES el secreto del enlace y viaja en el cuerpo del POST; un nonce
	 * de invitado no añadiría nada (depende de una sesión que un comprador sin
	 * cuenta no tiene) y sí rompería la baja de quien abre el correo semanas
	 * después.
	 *
	 * @param string              $token  Token del enlace.
	 * @param string              $metodo Método HTTP.
	 * @param array<string,mixed> $post   Cuerpo del POST.
	 * @return array{status:int,html:string} Código HTTP y página completa.
	 */
	public static function responder( string $token, string $metodo, array $post = array() ): array {
		$titulo = 'Preferencias de correo — Akibara';
		$email  = self::email_of_ref( $token );

		if ( '' === $email ) {
			// Enlace inválido: se dice sin filtrar si el pedido existe.
			return array(
				'status' => 404,
				'html'   => ReviewForm::shell( self::cuerpo_invalido(), $titulo ),
			);
		}

		if ( 'POST' !== strtoupper( $metodo ) ) {
			return array(
				'status' => 200,
				'html'   => ReviewForm::shell( self::cuerpo_confirmar( $token, self::opted_out( $email ) ), $titulo ),
			);
		}

		$accion = isset( $post['akb_rw_pref'] ) ? sanitize_key( (string) $post['akb_rw_pref'] ) : '';

		// «Un clic» del RFC 8058: Gmail y Outlook mandan este POST sin abrir la
		// página, y el correo lo anuncia en su cabecera List-Unsubscribe-Post.
		if ( isset( $post['List-Unsubscribe'] ) ) {
			$accion = 'baja';
		}

		if ( 'alta' === $accion ) {
			self::alta( $email );
			return array(
				'status' => 200,
				'html'   => ReviewForm::shell( self::cuerpo_hecho( $token, false ), $titulo ),
			);
		}

		self::baja( $email );

		return array(
			'status' => 200,
			'html'   => ReviewForm::shell( self::cuerpo_hecho( $token, true ), $titulo ),
		);
	}

	/**
	 * Correo del pedido al que pertenece un enlace, si la firma es la suya.
	 *
	 * @param string $ref «<pedido>-<firma>» del enlace.
	 * @return string '' si el enlace no es válido o el pedido ya no está.
	 */
	private static function email_of_ref( string $ref ): string {
		if ( 1 !== preg_match( '/^(\d{1,12})-([a-f0-9]{32})$/', $ref, $m ) || ! function_exists( 'wc_get_order' ) ) {
			return '';
		}

		$order = wc_get_order( (int) $m[1] );
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}

		$email = (string) $order->get_billing_email();

		// Comparación en tiempo constante contra la firma recalculada: quien
		// conoce un número de pedido no puede dar de baja a su dueño.
		return hash_equals( self::ref_for( (int) $m[1], $email ), $ref ) ? $email : '';
	}

	/**
	 * Huella estable de una dirección.
	 *
	 * @param string $email Dirección.
	 * @return string '' si no hay dirección.
	 */
	private static function clave( string $email ): string {
		$email = strtolower( trim( $email ) );

		return '' === $email ? '' : hash( 'sha256', $email );
	}

	/**
	 * @return array<string,int>
	 */
	private static function lista(): array {
		$lista = get_option( self::OPTION, array() );

		return is_array( $lista ) ? $lista : array();
	}

	/**
	 * @param string $email Dirección que pide la baja.
	 * @return void
	 */
	private static function baja( string $email ): void {
		$clave = self::clave( $email );
		if ( '' === $clave ) {
			return;
		}

		$lista           = self::lista();
		$lista[ $clave ] = time();

		update_option( self::OPTION, $lista, false );
	}

	/**
	 * @param string $email Dirección que vuelve a aceptarlas.
	 * @return void
	 */
	private static function alta( string $email ): void {
		$clave = self::clave( $email );
		$lista = self::lista();

		if ( '' === $clave || ! isset( $lista[ $clave ] ) ) {
			return;
		}

		unset( $lista[ $clave ] );

		update_option( self::OPTION, $lista, false );
	}

	/**
	 * @param string $token Token del enlace.
	 * @param bool   $baja  Si el botón da de baja (o vuelve a activar).
	 * @return string
	 */
	private static function formulario( string $token, bool $baja ): string {
		return sprintf(
			'<form method="post" style="margin:16px 0 0">
				<input type="hidden" name="akb_rw_pref" value="%s">
				<input type="hidden" name="%s" value="%s">
				<button type="submit">%s</button>
			</form>',
			$baja ? 'baja' : 'alta',
			esc_attr( self::QUERY_VAR ),
			esc_attr( $token ),
			$baja ? 'No quiero recibirlas' : 'Volver a recibirlas'
		);
	}

	/**
	 * @param string $token Token del enlace.
	 * @param bool   $ya    Si ya estaba dado de baja.
	 * @return string
	 */
	private static function cuerpo_confirmar( string $token, bool $ya ): string {
		if ( $ya ) {
			return '<h1>Ya no te pedimos reseñas</h1>'
				. '<p>No vamos a enviarte más invitaciones a reseñar. Los correos de tus pedidos y de tus Puntos Akibara siguen llegando: son parte de la compra.</p>'
				. self::formulario( $token, false );
		}

		return '<h1>¿Dejamos de pedirte reseñas?</h1>'
			. '<p>Puedes decirnos que no te enviemos más invitaciones a reseñar tus compras. Los correos de tus pedidos y de tus Puntos Akibara seguirán llegando: son parte de la compra.</p>'
			. self::formulario( $token, true );
	}

	/**
	 * @param string $token Token del enlace.
	 * @param bool   $baja  Si acaba de darse de baja.
	 * @return string
	 */
	private static function cuerpo_hecho( string $token, bool $baja ): string {
		if ( $baja ) {
			return '<h1>Listo</h1>'
				. '<p>No volveremos a pedirte reseñas. Si fue sin querer, puedes revertirlo aquí mismo.</p>'
				. self::formulario( $token, false )
				. sprintf( '<p class="akb-rf-nota" style="margin-top:24px"><a href="%s" style="color:#f5f5f5">Volver a la tienda</a></p>', esc_url( home_url( '/' ) ) );
		}

		return '<h1>Volvimos a activarlas</h1>'
			. '<p>Te escribiremos de nuevo después de tus próximos pedidos para pedirte tu opinión.</p>'
			. self::formulario( $token, true )
			. sprintf( '<p class="akb-rf-nota" style="margin-top:24px"><a href="%s" style="color:#f5f5f5">Volver a la tienda</a></p>', esc_url( home_url( '/' ) ) );
	}

	/**
	 * @return string
	 */
	private static function cuerpo_invalido(): string {
		return '<h1>Ese enlace ya no sirve</h1>'
			. '<p class="akb-rf-aviso">No pudimos reconocer el enlace. Escríbenos a <a href="mailto:contacto@akibara.cl" style="color:#f5f5f5">contacto@akibara.cl</a> y lo hacemos nosotros.</p>'
			. sprintf( '<p class="akb-rf-nota" style="margin-top:24px"><a href="%s" style="color:#f5f5f5">Volver a la tienda</a></p>', esc_url( home_url( '/' ) ) );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/Media.php
================================================================

<?php
/**
 * Media — detección y validación de fotos de reseña.
 *
 * Compatibilidad por ingeniería inversa de CusRev (04-cusrev-ingenieria-
 * inversa.md): las fotos locales viven en `ivole_review_image2` como UNA
 * FILA DE COMMENT_META POR ADJUNTO (int, unique=false) — NO un array — y las
 * remotas en `ivole_review_image` (array con 'url').
 *
 * Esas DOS son las únicas claves con lectores vivos: la local la escribe
 * `attach_photo()` y la leen el carrusel del home, las superficies de foto,
 * el exportador de privacidad y el premio; la remota sólo tiene lectores,
 * ningún escritor propio (la escribía el tercero, hoy desinstalado). Las
 * metas de video y el contador agregado de CusRev (`ivole_media_count`) se
 * retiraron: nadie del árbol propio las escribía ni las leía.
 *
 * La foto REAL se valida con `wp_attachment_is('image')` — un video no
 * cuenta para el premio (decisión del dueño, heredada del gate del cupón).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Media {

	public const META_LOCAL_IMAGE  = 'ivole_review_image2';
	public const META_REMOTE_IMAGE = 'ivole_review_image';

	/**
	 * ¿La reseña tiene al menos UNA foto real?
	 *
	 * Locales: cada fila es un ID de adjunto que debe seguir siendo imagen
	 * (el adjunto pudo borrarse). Remotas: basta la fila con 'url' no vacía
	 * (heredado del gate del cupón del mu-plugin).
	 *
	 * @param int $comment_id Reseña.
	 * @return bool
	 */
	public static function has_real_photo( int $comment_id ): bool {
		foreach ( (array) get_comment_meta( $comment_id, self::META_LOCAL_IMAGE ) as $attachment_id ) {
			if ( (int) $attachment_id > 0 && wp_attachment_is( 'image', (int) $attachment_id ) ) {
				return true;
			}
		}

		foreach ( (array) get_comment_meta( $comment_id, self::META_REMOTE_IMAGE ) as $remote ) {
			if ( is_array( $remote ) && ! empty( $remote['url'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adjunta una foto local a una reseña con las claves compatibles y
	 * reparentado al producto (mismo comportamiento que la ruta local de
	 * CusRev: add_comment_meta unique=false + wp_update_post post_parent).
	 *
	 * @param int $comment_id    Reseña.
	 * @param int $attachment_id Adjunto (debe ser imagen).
	 * @param int $product_id    Producto para el reparentado.
	 * @return bool False si el adjunto no es una imagen.
	 */
	public static function attach_photo( int $comment_id, int $attachment_id, int $product_id ): bool {
		if ( $attachment_id <= 0 || ! wp_attachment_is( 'image', $attachment_id ) ) {
			return false;
		}

		add_comment_meta( $comment_id, self::META_LOCAL_IMAGE, $attachment_id, false );

		if ( $product_id > 0 ) {
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_parent' => $product_id,
				)
			);
		}

		return true;
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/Moderation.php
================================================================

<?php
/**
 * Moderation — retención de reseñas de producto hasta su aprobación.
 *
 * Fuerza `comment_approved = 0` para toda reseña de producto que entre por
 * `wp_new_comment` cuando el interruptor de moderación está activo.
 * Límites de la integración:
 *   - `wp_insert_comment` directo NO pasa por `pre_comment_approved`: las
 *     reseñas de AdminReviews las aprueba el propio dueño al crearlas, y las
 *     de CusRev (mientras viva) se moderan encendiendo su propia opción
 *     `ivole_enable_moderation` — paso manual del runbook pre-cutover.
 *   - El filtro corre después de la aprobación automática de WordPress para
 *     autores conocidos, por lo que las reseñas también quedan retenidas.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Moderation {

	/**
	 * Cuelga el filtro de pre-aprobación.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pre_comment_approved', array( $this, 'hold_product_reviews' ), 50, 2 );
	}

	/**
	 * Toda reseña de producto queda EN ESPERA hasta que el dueño la acepte.
	 *
	 * @param int|string|\WP_Error $approved    Veredicto previo de WordPress.
	 * @param array<string,mixed>  $commentdata Datos del comentario.
	 * @return int|string|\WP_Error
	 */
	public function hold_product_reviews( $approved, $commentdata ) {
		if ( 'yes' !== (string) get_option( 'akb_rewards_moderate_reviews', 'yes' ) ) {
			return $approved;
		}

		if ( $approved instanceof \WP_Error || 'spam' === $approved ) {
			return $approved; // Jamás rescatar spam ni pisar un error.
		}

		// REST/XML-RPC pueden enviar los tipos '' o 'comment'. Toda reseña
		// top-level de producto se modera; pingbacks y trackbacks quedan fuera.
		$type = (string) ( $commentdata['comment_type'] ?? '' );
		if ( ! in_array( $type, array( 'review', 'comment', '' ), true ) ) {
			return $approved;
		}

		if ( (int) ( $commentdata['comment_parent'] ?? 0 ) > 0 ) {
			return $approved; // Las respuestas del hilo no son reseñas.
		}

		$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
		if ( 'product' !== get_post_type( $post_id ) ) {
			return $approved;
		}

		return 0;
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/PhotoSurfaces.php
================================================================

<?php
/**
 * Renderizado de la fotografía de la reseña en las superficies necesarias.
 *
 * Muestra la foto adjunta tanto en la ficha de producto (PDP) como en la
 * pantalla de moderación de comentarios en el escritorio de administración.
 *
 * Utiliza el filtro `comment_text` porque es el punto común que procesa ambas
 * superficies (listado de reseñas en el front y tabla de moderación administrativa).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

final class PhotoSurfaces {

	/** Ancho máximo de la foto en la ficha, en píxeles. */
	private const ANCHO_FICHA = 240;

	/** Ancho de la miniatura en la pantalla de moderación, en píxeles. */
	private const ANCHO_ADMIN = 96;

	public function register(): void {
		// Prioridad 26: después de la insignia de reseña transcrita (20), para
		// mantener el orden secuencial texto -> insignia -> foto.
		add_filter( 'comment_text', array( $this, 'append_photo' ), 26, 2 );
	}

	/**
	 * Añade la foto de la reseña al final de su texto.
	 *
	 * @param string           $text    Texto del comentario ya filtrado.
	 * @param \WP_Comment|null $comment Comentario, cuando el llamador lo pasa.
	 * @return string
	 */
	public function append_photo( $text, $comment = null ): string {
		$text = (string) $text;

		if ( ! $comment instanceof \WP_Comment ) {
			return $text;
		}

		// Sólo reseñas de producto: los comentarios del blog no llevan foto.
		if ( 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return $text;
		}

		$foto = self::foto( (int) $comment->comment_ID );
		if ( array() === $foto ) {
			return $text;
		}

		$autor = trim( (string) $comment->comment_author );
		$alt   = '' !== $autor
			? sprintf( 'Foto de la reseña de %s', $autor )
			: 'Foto de la reseña';

		return $text . self::figura( $foto, $alt );
	}

	/**
	 * La foto de una reseña: primero la local (adjunto), luego la remota.
	 *
	 * Misma vara que `Media::has_real_photo()` y que el carrusel: el adjunto
	 * tiene que EXISTIR y ser imagen. Una meta huérfana no puede colar un hueco
	 * donde iba la foto.
	 *
	 * @param int $comment_id Reseña.
	 * @return array{id:int,url:string}|array{} Vacío si no hay foto utilizable.
	 */
	private static function foto( int $comment_id ): array {
		foreach ( (array) get_comment_meta( $comment_id, Media::META_LOCAL_IMAGE ) as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( $attachment_id > 0 && wp_attachment_is( 'image', $attachment_id ) ) {
				return array(
					'id'  => $attachment_id,
					'url' => (string) wp_get_attachment_image_url( $attachment_id, 'large' ),
				);
			}
		}

		foreach ( (array) get_comment_meta( $comment_id, Media::META_REMOTE_IMAGE ) as $remota ) {
			if ( ! is_array( $remota ) || empty( $remota['url'] ) || ! is_string( $remota['url'] ) ) {
				continue;
			}

			// En entornos HTTPS, asegurar esquema https para evitar bloqueo de contenido mixto.
			// En entornos locales o sin SSL, preservar el esquema original.
			$url = is_ssl() ? set_url_scheme( $remota['url'], 'https' ) : $remota['url'];

			// Sólo web: una meta manipulada no puede colar un esquema raro.
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}

			return array(
				'id'  => 0,
				'url' => $url,
			);
		}

		return array();
	}

	/**
	 * Estilo en línea de la foto: tope de ancho contenido que respeta su contenedor responsivo.
	 *
	 * Utiliza min(ancho, 100%) para evitar desbordes en contenedores estrechos en vista móvil.
	 *
	 * @param int $ancho Tope en píxeles.
	 * @return string
	 */
	private static function estilo( int $ancho ): string {
		return sprintf( 'max-width:min(%dpx,100%%);height:auto;border-radius:4px;display:block;', $ancho );
	}

	/**
	 * El marcado de la foto, distinto según quién mira.
	 *
	 * En el escritorio va una miniatura: la tabla de moderación tiene una fila
	 * por reseña y una foto grande la vuelve ilegible. En la ficha va la imagen
	 * a tamaño de lectura, perezosa, porque puede haber muchas reseñas abajo.
	 *
	 * @param array{id:int,url:string} $foto Foto resuelta.
	 * @param string                   $alt  Texto alternativo.
	 * @return string
	 */
	private static function figura( array $foto, string $alt ): string {
		$en_admin = is_admin();
		$ancho    = $en_admin ? self::ANCHO_ADMIN : self::ANCHO_FICHA;

		if ( $foto['id'] > 0 ) {
			$img = wp_get_attachment_image(
				$foto['id'],
				$en_admin ? 'thumbnail' : 'medium',
				false,
				array(
					'class'   => 'akb-rw-foto__img',
					'alt'     => $alt,
					'loading' => 'lazy',
					'style'   => self::estilo( $ancho ),
				)
			);
		} else {
			$img = sprintf(
				'<img src="%s" alt="%s" class="akb-rw-foto__img" loading="lazy" style="%s">',
				esc_url( $foto['url'] ),
				esc_attr( $alt ),
				esc_attr( self::estilo( $ancho ) )
			);
		}

		if ( '' === $img ) {
			return '';
		}

		// En el escritorio la miniatura enlaza a la imagen completa: el dueño
		// aprueba mirando el detalle, no un sello de 96 píxeles.
		if ( $en_admin && '' !== $foto['url'] ) {
			$img = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $foto['url'] ),
				$img
			);
		}

		return sprintf(
			'<figure class="akb-rw-foto" style="margin:10px 0 0;">%s</figure>',
			$img
		);
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/ReviewForm.php
================================================================

<?php
/**
 * ReviewForm — el formulario de reseña por TOKEN (Fase 2b) + ruta legacy.
 *
 * El cliente llega desde el correo de invitación con /resena/{token}: una
 * página propia, liviana (HTML del servidor, cero JS, 44px, es-CL), que
 * lista los productos de su pedido y solicita calificación más texto. El enlace abre la
 * oferta de puntos con foto requerida. La reseña voluntaria con foto opcional vive en la ficha.
 * La reseña nace PENDIENTE (moderación previa antes de publicar) y vinculada al pedido.
 *
 * Ruta legacy /cusrev/{formId}: los 3 enlaces del tercero ya enviados por
 * correo caen en una página amable que lleva a Mi cuenta (la tabla del
 * tercero muere en el cleanup; el enlace no puede validar, pero el cliente
 * no ve un 404).
 *
 * Los núcleos render_page()/process() son testeables (devuelven string /
 * resultado); el `exit` vive solo en el wrapper.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\PrivatePage;
use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class ReviewForm {

	public const QUERY_VAR        = 'akb_review_token';

	/** Regla de /resena/{token}; AccountRewards comprueba que siga en las reglas guardadas. */
	public const RULE = '^resena/([A-Za-z0-9]{20,64})/?$';
	public const LEGACY_QUERY_VAR = 'akb_review_legacy';
	public const META_ORDER       = '_akb_rewards_review_order';
	private bool $points_requested = false;
	/** @var array<string,mixed> Campos del último POST con nonce válido. */
	private array $submitted = array();

	/**
	 * URL pública del formulario para un token.
	 *
	 * @param string $token Token emitido.
	 * @return string
	 */
	public static function url_for( string $token ): string {
		// Bajo la URL de Mi cuenta para asegurar que el borde CDN respete el no-store
		// y no sirva respuestas cacheadas a invitados (ver Support\PrivatePage).
		// La ruta antigua sigue viva para retrocompatibilidad mediante redirección.
		return add_query_arg( self::QUERY_VAR, rawurlencode( $token ), PrivatePage::base_url() );
	}

	/**
	 * ¿La petición llegó por la ruta antigua `/resena/<token>/`?
	 *
	 * @return bool
	 */
	private static function es_ruta_antigua(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sólo se compara contra un prefijo literal.

		return 1 === preg_match( '#^/resena/#', (string) wp_parse_url( $uri, PHP_URL_PATH ) );
	}

	/**
	 * Cuelga rutas y render.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_routes' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'woocommerce_product_review_comment_form_args', array( $this, 'native_form_notice' ) );
		( new VoluntaryReviewForm() )->register();
		( new AccountReviewLink() )->register();
		// Prioridad 1: antes de que la página de Mi cuenta pinte su acceso.
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	/**
	 * Explica la reseña voluntaria de la ficha sin prometer un premio.
	 *
	 * @param array<string,mixed> $args Formulario de WooCommerce.
	 * @return array<string,mixed>
	 */
	public function native_form_notice( array $args ): array {
		$notice = 'Esta reseña es voluntaria: puedes añadir una foto y no solicita puntos.';
		if ( 'yes' === (string) get_option( 'akb_rewards_moderate_reviews', 'yes' ) ) {
			$notice .= ' Tu reseña quedará pendiente de revisión.';
		}
		if ( Options::reviews_lane_on() ) {
			$notice .= ' Para solicitar puntos con una foto real de tu compra, usa el enlace de reseña de tu pedido.';
		}
		$args['comment_field'] = '<p class="akb-review-photo-notice">' . esc_html( $notice ) . '</p>'
			. (string) ( $args['comment_field'] ?? '' );
		return $args;
	}

	/**
	 * Reglas de rewrite propias + legacy.
	 *
	 * @return void
	 */
	public function add_routes(): void {
		add_rewrite_rule( self::RULE, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		add_rewrite_rule( '^cusrev/([^/]+)/?$', 'index.php?' . self::LEGACY_QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * @param array<int,string> $vars Query vars públicas.
	 * @return array<int,string>
	 */
	public function register_query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		$vars[] = self::LEGACY_QUERY_VAR;
		return $vars;
	}

	/**
	 * Wrapper con exit: decide y pinta. El trabajo vive en los núcleos.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		$token  = (string) get_query_var( self::QUERY_VAR );
		$legacy = (string) get_query_var( self::LEGACY_QUERY_VAR );

		if ( '' === $token && '' === $legacy ) {
			return;
		}

		$metodo = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		// Un enlace de un correo ya enviado: se lleva a la URL que el borde no
		// cachea. Sólo el GET —un POST en vuelo se atiende donde llegó— y con
		// 302, porque el destino depende de dónde viva Mi cuenta.
		if ( '' !== $token && 'GET' === $metodo && self::es_ruta_antigua() ) {
			wp_safe_redirect( self::url_for( $token ), 302, 'Akibara Rewards' );
			exit;
		}

		PrivatePage::no_cache( 'Akibara Rewards: formulario de reseña' );

		if ( '' !== $legacy ) {
			echo self::shell( $this->legacy_body() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML propio, escapado por partes.
			exit;
		}

		$notice = '';
		if ( 'POST' === $metodo ) {
			$resultado = $this->process( $token );
			if ( true === $resultado ) {
				echo self::shell( $this->thanks_body() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;
			}
			$notice = (string) $resultado;
		}

		echo self::shell( $this->render_page( $token, $notice ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Valida un token contra la tabla.
	 *
	 * @param string $token Token del enlace.
	 * @return array{order_id:int,expires_at:string}|null Null si no vale (inexistente/caducado/usado).
	 */
	public static function validate_token( string $token ) {
		global $wpdb;

		if ( ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $token ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- el único interpolado es el nombre de nuestra tabla.
				'SELECT order_id, expires_at, used_at FROM ' . Schema::review_forms_table() . ' WHERE token = %s',
				$token
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || null !== $row['used_at'] ) {
			return null;
		}

		if ( gmdate( 'Y-m-d H:i:s' ) > (string) $row['expires_at'] ) {
			return null;
		}

		return array( 'order_id' => (int) $row['order_id'], 'expires_at' => (string) $row['expires_at'] );
	}

	/**
	 * Núcleo del POST: valida todo y crea la reseña PENDIENTE.
	 *
	 * @param string $token Token del enlace.
	 * @return true|string True si quedó creada; si no, el aviso a mostrar.
	 */
	public function process( string $token ) {
		$valid = self::validate_token( $token );
		if ( null === $valid ) {
			return 'Este enlace ya se usó o caducó.';
		}

		if ( ! isset( $_POST['_akbnonce'] ) || ! is_string( $_POST['_akbnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_akbnonce'] ) ), 'akb_review_' . $token ) ) {
			return 'La sesión del formulario caducó: vuelve a intentarlo.';
		}

		$order = wc_get_order( $valid['order_id'] );
		if ( ! $order instanceof \WC_Order ) {
			return 'No encontramos el pedido de este enlace.';
		}

		$this->submitted = array(
			'mode'      => isset( $_POST['akb_rf_mode'] ) && 'voluntary' === $_POST['akb_rf_mode'] ? 'voluntary' : 'reward',
			'product'   => isset( $_POST['akb_rf_product'] ) && is_scalar( $_POST['akb_rf_product'] ) ? (int) $_POST['akb_rf_product'] : 0,
			'rating'    => isset( $_POST['akb_rf_rating'] ) && is_scalar( $_POST['akb_rf_rating'] ) ? (int) $_POST['akb_rf_rating'] : 0,
			'content'   => isset( $_POST['akb_rf_content'] ) && is_string( $_POST['akb_rf_content'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['akb_rf_content'] ) ) ) : '',
			'requested' => isset( $_POST['akb_rf_points_requested'] ) && is_string( $_POST['akb_rf_points_requested'] ) && '1' === sanitize_key( wp_unslash( $_POST['akb_rf_points_requested'] ) ),
		);

		$product_id = (int) $this->submitted['product'];
		if ( ! self::order_has_product( $order, $product_id ) ) {
			return 'Elige uno de los productos de tu pedido.';
		}

		if ( ! VerifiedPurchase::order_confirms( $order, $product_id, (string) $order->get_billing_email(), (int) $order->get_customer_id() ) ) {
			return 'Este producto ya no corresponde a una compra válida para reseñar.';
		}

		$rating = (int) $this->submitted['rating'];
		if ( $rating < 1 || $rating > 5 ) {
			return 'Elige una calificación de 1 a 5 estrellas.';
		}

		$content = (string) $this->submitted['content'];
		if ( mb_strlen( $content ) < 10 ) {
			return 'Cuéntanos un poco más: al menos 10 caracteres.';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce se verificó al entrar en process().
		$requested = Options::reviews_lane_on();
		if ( $requested && ( 'voluntary' === $this->submitted['mode'] || ! $this->submitted['requested'] ) ) {
			return 'Este enlace solicita puntos y requiere una foto de tu compra. Vuelve a abrir el formulario y confirma el envío.';
		}
		$has_photo = self::photo_is_present( isset( $_FILES ) && is_array( $_FILES ) ? $_FILES : array() );
		if ( $requested && ! $has_photo ) {
			return 'Para solicitar puntos necesitas una foto de tu compra. Adjunta una foto real de tu compra y vuelve a enviarla.';
		}

		$attachment_id = 0;
		$file = $_FILES['akb_rf_photo'] ?? array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- estructura validada por upload_photo(), después del nonce.
		$upload_attempted = $has_photo || ! is_array( $file ) || ( isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== $file['error'] ) || ! empty( $file['name'] );
		if ( $upload_attempted ) {
			$subida = $this->handle_upload( $product_id );
			if ( is_wp_error( $subida ) ) {
				return 'La foto no se pudo subir: ' . $subida->get_error_message();
			}
			$attachment_id = (int) $subida;
		}

		$commentdata = array(
			'comment_post_ID'      => $product_id,
			'comment_author'       => trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() ),
			'comment_author_email' => (string) $order->get_billing_email(),
			'comment_author_url'   => '',
			'comment_content'      => $content,
			'comment_type'         => 'review',
			'comment_parent'       => 0,
			'comment_author_IP'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'comment_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 254 ) : '',
			'user_id'              => (int) $order->get_customer_id(),
			'comment_approved'     => 0,
			'comment_meta'         => array(
				'rating' => $rating,
				ReviewPoints::META_POINTS_REQUESTED => (int) $requested,
			),
		);

		// El token se quema ANTES de crear, con escritura CONDICIONAL: dos
		// envíos casi simultáneos pasaban las dos validaciones y creaban dos
		// reseñas (y dos premios). El UPDATE ... WHERE used_at IS NULL deja
		// pasar a UNO solo — la carrera la resuelve la base, no el orden de
		// llegada. Marcar usado después era la ventana.
		if ( ! self::claim_token( $token ) ) {
			// La foto ya está en disco: se subió arriba, antes de saber quién
			// ganaba la carrera. Ese orden es deliberado —si la subida falla, el
			// token sigue vivo y el cliente reintenta—, pero deja al perdedor con
			// un adjunto sin reseña, sin `post_parent` y con URL pública. El caso
			// corriente no es un atacante sino el doble clic en «Enviar mi
			// reseña» con foto. `AdminReviews::handle_form()` ya limpia en el
			// caso equivalente («el residuo sin GC que le reprochamos a CusRev»):
			// esta ruta es la que se saltaba la convención.
			self::discard_orphan_photo( $attachment_id );
			return 'Este enlace ya se usó o caducó.';
		}

		// El orden del núcleo es escapar, filtrar e insertar. wp_insert_comment()
		// espera datos escapados para conservar las barras escritas por el cliente.
		$comment_id = wp_insert_comment( wp_filter_comment( wp_slash( $commentdata ) ) );
		if ( ! $comment_id ) {
			// Nada que premiar: el enlace vuelve a servir para reintentar, y la
			// foto del intento fallido tampoco se queda (el reintento sube la
			// suya). Sin esto, un INSERT que falle de forma repetible —un cuerpo
			// que MySQL rechaza, por ejemplo— fuga un adjunto por vuelta, porque
			// `release_token()` devuelve el enlace al ruedo.
			self::release_token( $token );
			self::discard_orphan_photo( $attachment_id );
			return 'No pudimos guardar tu reseña; inténtalo otra vez.';
		}

		// Vínculo auditable reseña↔pedido (spec 2b: «guarda el vínculo al
		// pedido»); Woo escribe 'verified' en el comment_post de abajo.
		update_comment_meta( $comment_id, self::META_ORDER, (int) $order->get_id() );
		// Woo sólo reconoce processing/completed al verificar compras. El
		// pedido del token permite verificar también Listo para enviar pagado.
		// Se escribe antes de comment_post: el add_comment_meta unique de Woo
		// conserva esta prueba y la moderación muestra la insignia correcta.
		update_comment_meta(
			$comment_id,
			'verified',
			(int) VerifiedPurchase::order_confirms( $order, $product_id, (string) $order->get_billing_email(), (int) $order->get_customer_id() )
		);

		if ( $attachment_id > 0 ) {
			Media::attach_photo( (int) $comment_id, $attachment_id, $product_id );
		}

		/** Woo escucha comment_post para 'verified'; nuestro motor, para el premio (que espera la APROBACIÓN). */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- no es un hook nuestro: es el de WP core, y el nombre exacto es lo que hace que Woo y nuestro motor escuchen. Prefijarlo los dejaría sordos.
		do_action( 'comment_post', (int) $comment_id, 0, $commentdata );

		$this->points_requested = $requested;
		return true;
	}

	/**
	 * ¿El producto pertenece al pedido?
	 *
	 * @param \WC_Order $order      Pedido.
	 * @param int       $product_id Producto elegido.
	 * @return bool
	 */
	private static function order_has_product( \WC_Order $order, int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) || (int) $item->get_product_id() !== $product_id ) {
				continue;
			}
			if ( self::fue_devuelto( $order, (int) $item_id, $item ) ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * ¿Esta línea se devolvió entera?
	 *
	 * Sin esto, un tomo reembolsado sigue contando como comprado: el formulario lo
	 * ofrecía para reseñar y WooCommerce marcaba esa reseña como «compra
	 * verificada». Pasó de verdad — pedido #27782, Frieren 06: la editorial no
	 * tenía el tomo, se devolvió el dinero, y el formulario iba a pedirle al
	 * cliente que opinara de un libro que nunca tuvo en las manos.
	 *
	 * `get_qty_refunded_for_item()` devuelve la cantidad en NEGATIVO (-1 = una
	 * unidad devuelta), de ahí el valor absoluto.
	 *
	 * @since 0.3.6
	 *
	 * @param \WC_Order $order   Pedido.
	 * @param int       $item_id Id de la línea.
	 * @param mixed     $item    La línea.
	 * @return bool
	 */
	private static function fue_devuelto( \WC_Order $order, int $item_id, $item ): bool {
		if ( ! method_exists( $item, 'get_quantity' ) ) {
			return false;
		}

		$compradas  = (int) $item->get_quantity();
		$devueltas  = abs( (int) $order->get_qty_refunded_for_item( $item_id ) );

		return $compradas > 0 && $devueltas >= $compradas;
	}

	/**
	 * ¿Viene la foto en el envío?
	 *
	 * Separado de `handle_upload()` a propósito: esa carga
	 * `wp-admin/includes/media.php` y no se puede ejercer en la suite, mientras
	 * que esta comprobación —la que decide si la reseña se acepta— sí tiene que
	 * poder probarse.
	 *
	 * @param array<string, mixed> $files El `$_FILES` del envío.
	 */
	private static function photo_is_present( array $files ): bool {
		$foto = $files['akb_rf_photo'] ?? null;

		return is_array( $foto ) && is_string( $foto['name'] ?? null ) && '' !== trim( $foto['name'] );
	}

	/**
	 * Sube la foto del formulario (5 MB máx, solo imagen).
	 *
	 * Los `require_once` van guardados por `function_exists`: cargar de nuevo lo
	 * que ya está cargado no aporta nada en producción, y en la suite permite
	 * que los tests que prueban OTRA cosa —la carrera del doble clic, la
	 * devolución del token— sustituyan `media_handle_upload()` para llegar a lo
	 * suyo. Desde que la foto es obligatoria, TODO envío pasa por aquí.
	 *
	 * @param int $product_id Producto para el reparentado.
	 * @return int|\WP_Error ID del adjunto.
	 */
	private function handle_upload( int $product_id ) {
		unset( $product_id ); // Media::attach_photo() enlaza la imagen tras crear la reseña.
		return VoluntaryReviewForm::upload_photo( 'akb_rf_photo' );
	}

	/**
	 * Toma el token para ESTE envío: lo marca usado sólo si seguía libre.
	 *
	 * @param string $token Token.
	 * @return bool True si este envío se quedó con el token.
	 */
	private static function claim_token( string $token ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia; el WHERE es el candado.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- el único interpolado es el nombre de nuestra tabla.
				'UPDATE ' . Schema::review_forms_table() . ' SET used_at = %s WHERE token = %s AND used_at IS NULL',
				gmdate( 'Y-m-d H:i:s' ),
				$token
			)
		);

		return (int) $claimed > 0;
	}

	/**
	 * Borra una foto que se subió para una reseña que al final no nació.
	 *
	 * Se llama sólo en las salidas posteriores a la subida y anteriores a
	 * `Media::attach_photo()`, o sea cuando el adjunto es con certeza huérfano:
	 * todavía no tiene reseña ni `post_parent`. El `true` fuerza el borrado sin
	 * pasar por la papelera — los adjuntos no la usan de todos modos, y el punto
	 * es no dejar el fichero en `uploads/`.
	 *
	 * @param int $attachment_id Adjunto recién creado, o 0 si no hubo foto.
	 * @return void
	 */
	private static function discard_orphan_photo( int $attachment_id ): void {
		if ( $attachment_id > 0 && function_exists( 'wp_delete_attachment' ) ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Devuelve el token al ruedo: sólo cuando la reseña NO llegó a nacer.
	 *
	 * @param string $token Token.
	 * @return void
	 */
	private static function release_token( string $token ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- el único interpolado es el nombre de nuestra tabla.
				'UPDATE ' . Schema::review_forms_table() . ' SET used_at = NULL WHERE token = %s',
				$token
			)
		);
	}

	/**
	 * Núcleo del GET: el formulario (o el aviso de enlace inválido).
	 *
	 * @param string $token  Token del enlace.
	 * @param string $notice Aviso de un POST fallido.
	 * @return string
	 */
	public function render_page( string $token, string $notice = '' ): string {
		$valid = self::validate_token( $token );
		if ( null === $valid ) {
			return $this->invalid_body();
		}

		$order = wc_get_order( $valid['order_id'] );
		if ( ! $order instanceof \WC_Order ) {
			return $this->invalid_body();
		}

		$productos = '';
		$n         = 0;
		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			// Un tomo devuelto no se ofrece para reseñar: el cliente nunca lo tuvo.
			if ( ! VerifiedPurchase::order_confirms( $order, (int) $item->get_product_id(), (string) $order->get_billing_email(), (int) $order->get_customer_id() ) ) {
				continue;
			}
			++$n;
			$productos .= sprintf(
				'<label class="akb-rf-prod"><input type="radio" name="akb_rf_product" value="%d" required%s> <span>%s</span></label>',
				(int) $item->get_product_id(),
				( isset( $this->submitted['product'] ) ? (int) $item->get_product_id() === (int) $this->submitted['product'] : 1 === $n ) ? ' checked' : '',
				esc_html( (string) $item->get_name() )
			);
		}

		// Pedido entero devuelto: no queda nada que reseñar. Sin esto el
		// formulario se pintaba vacío, con un radio `required` que era imposible
		// de marcar — el cliente veía un formulario que no se puede enviar.
		if ( 0 === $n ) {
			return $this->invalid_body();
		}

		$estrellas = '';
		for ( $i = 5; $i >= 1; $i-- ) {
			$estrellas .= sprintf(
				'<input type="radio" id="akb-rf-star%1$d" name="akb_rf_rating" value="%1$d" required%2$s><label for="akb-rf-star%1$d" aria-label="%1$d estrellas">★</label>',
				$i,
				$i === (int) ( $this->submitted['rating'] ?? 0 ) ? ' checked' : ''
			);
		}

		$reward = Options::reviews_lane_on();
		$premio = $reward
			? '<div class="akb-rf-premio"><strong>Reseña con puntos</strong><p>' . esc_html( RewardMessage::text() ) . '</p></div>'
			: '<p>Reseña voluntaria sin puntos. La foto es opcional; adjuntarla no solicita un premio.</p>';
		return sprintf(
			'<h1>Tu reseña del pedido #%d</h1>
			%s
			%s
			<details class="akb-rf-campo"><summary>Todavía no lo recibí</summary><p>Espera a recibir tu compra para contar tu experiencia. Guarda este enlace y vuelve cuando la tengas; este enlace vence el %s (UTC). Abrir este aviso no envía una reseña ni usa tu enlace.</p></details>
			<form method="post" enctype="multipart/form-data">
				%s
				<input type="hidden" name="akb_rf_mode" value="%s">
				<input type="hidden" name="akb_rf_points_requested" value="%d">
				<fieldset class="akb-rf-campo"><legend>¿Qué producto reseñas?</legend>%s</fieldset>
				<fieldset class="akb-rf-campo"><legend>Tu calificación</legend><div class="akb-rf-stars">%s</div></fieldset>
				<label class="akb-rf-campo" for="akb_rf_content">Tu reseña<textarea id="akb_rf_content" name="akb_rf_content" rows="5" required minlength="10" placeholder="¿Qué te pareció? Edición, papel, traducción, envío…">%s</textarea></label>
				<label class="akb-rf-campo" for="akb_rf_photo">Foto de tu compra (%s)<input type="file" id="akb_rf_photo" name="akb_rf_photo" accept="image/jpeg,image/png,image/webp,image/heic"%s aria-describedby="akb-rf-photo-help"><span id="akb-rf-photo-help" class="akb-rf-ayuda">%s Máximo 5 MB; JPG, PNG, WebP o HEIC.</span></label>
				<button type="submit">Enviar mi reseña</button>
				<p class="akb-rf-nota">Tu reseña se publica cuando la revisemos. ¡Gracias por ayudar a otros coleccionistas!</p>
			</form>',
			(int) $order->get_id(),
			$premio,
			'' !== $notice ? sprintf( '<p class="akb-rf-aviso" role="alert">%s</p>', esc_html( $notice ) ) : '',
			esc_html( $valid['expires_at'] ),
			wp_nonce_field( 'akb_review_' . $token, '_akbnonce', true, false ),
			$reward ? 'reward' : 'voluntary',
			(int) $reward,
			$productos,
			$estrellas,
			esc_html( (string) ( $this->submitted['content'] ?? '' ) ),
			$reward ? 'obligatoria para solicitar puntos' : 'opcional',
			$reward ? ' required' : '',
			$reward ? 'Adjunta una foto real de tu compra para solicitar puntos.' : 'Puedes enviarla sin foto. Esta reseña no solicita puntos.'
		);
	}

	/**
	 * Cuerpo del agradecimiento post-envío.
	 *
	 * @return string
	 */
	private function thanks_body(): string {
		return sprintf(
			'<h1>¡Gracias! 🎉</h1>
			<p>Tu reseña quedó en revisión: la publicamos apenas la aprobemos.</p>
			%s
			<p><a class="akb-rf-btn" href="%s">Volver a la tienda</a></p>',
			$this->points_requested
				? '<p>Recompensa pendiente de revisión: solicitaste puntos por esta reseña. ' . esc_html( RewardMessage::text( false ) ) . '</p>'
				: '<p>No solicitaste puntos. Tu reseña se revisará con normalidad.</p>',
			esc_url( home_url( '/' ) )
		);
	}

	/**
	 * Cuerpo del enlace inválido.
	 *
	 * @return string
	 */
	private function invalid_body(): string {
		return sprintf(
			'<h1>Este enlace ya no sirve</h1>
			<p>El enlace de reseña ya se usó o venció. Puedes escribir tu reseña desde la ficha del producto cuando quieras.</p>
			<p><a class="akb-rf-btn" href="%s">Ir a mis pedidos</a></p>',
			esc_url( function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : home_url( '/mi-cuenta/' ) )
		);
	}

	/**
	 * Cuerpo de la ruta legacy /cusrev/{formId}.
	 *
	 * @return string
	 */
	private function legacy_body(): string {
		return sprintf(
			'<h1>Cambiamos de sistema de reseñas</h1>
			<p>Este enlace venía de nuestro sistema anterior y ya no funciona, pero tu opinión nos sigue importando: puedes reseñar desde la ficha del producto o desde tus pedidos.</p>
			<p><a class="akb-rf-btn" href="%s">Ir a mis pedidos</a></p>',
			esc_url( function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : home_url( '/mi-cuenta/' ) )
		);
	}

	/**
	 * Cáscara HTML propia: liviana, brand, mobile-first, cero JS.
	 *
	 * Pública desde la 0.4.9 porque la página de preferencias de correo
	 * (`InviteOptOut`) es la misma pantalla para el cliente: dos carcasas
	 * distintas para dos enlaces del mismo correo se ven como dos sitios.
	 *
	 * @param string $body  Cuerpo ya escapado por partes.
	 * @param string $title Título de la pestaña.
	 * @return string
	 */
	public static function shell( string $body, string $title = 'Tu reseña — Akibara' ): string {
		return '<!doctype html><html lang="es-CL"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html( $title ) . '</title><style>'
			. ':root{color-scheme:dark}'
			. 'body{margin:0;background:#0d0d0d;color:#f5f5f5;font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}'
			. '.akb-rf{max-width:560px;margin:0 auto;padding:24px 16px 64px}'
			. '.akb-rf-logo{display:block;margin:0 0 24px;font-weight:800;font-size:22px;color:#E60012;text-decoration:none}'
			. 'h1{font-size:26px;margin:0 0 16px}'
			. '.akb-rf-campo{display:block;border:1px solid #2b2b2b;border-radius:12px;padding:16px;margin:0 0 16px;background:#171717}'
			. 'legend{padding:0 6px;font-weight:700}'
			. 'textarea,input[type=file]{width:100%;box-sizing:border-box;margin-top:8px;background:#0d0d0d;color:#f5f5f5;border:1px solid #2b2b2b;border-radius:8px;padding:12px;font:inherit}'
			. '.akb-rf-prod{display:flex;gap:10px;align-items:center;min-height:44px}'
			. '.akb-rf-stars{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:4px}'
			. '.akb-rf-stars input{position:absolute;opacity:0;width:1px;height:1px}'
			. '.akb-rf-stars label{font-size:34px;line-height:44px;min-width:44px;text-align:center;color:#3a3a3a;cursor:pointer}'
			. '.akb-rf-stars input:checked ~ label,.akb-rf-stars label:hover,.akb-rf-stars label:hover ~ label{color:#FFD700}'
			. '.akb-rf-stars input:focus-visible + label{outline:2px solid #fff;outline-offset:2px;border-radius:6px}'
			. 'button,.akb-rf-btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:12px 28px;border:0;border-radius:8px;background:#E60012;color:#fff;font-weight:700;font-size:16px;cursor:pointer;text-decoration:none}'
			. '.akb-rf-premio{border-left:3px solid #E60012;background:rgba(230,0,18,.08);padding:10px 12px;border-radius:0 8px 8px 0}'
			. '.akb-rf-aviso{border-left:3px solid #E60012;background:rgba(230,0,18,.1);padding:10px 12px;border-radius:0 8px 8px 0}'
			. '.akb-rf-nota{font-size:13px;opacity:.7}'
			. '</style></head><body><main class="akb-rf">'
			. '<a class="akb-rf-logo" href="' . esc_url( home_url( '/' ) ) . '">AKIBARA</a>'
			. $body
			. '</main></body></html>';
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/ReviewNudge.php
================================================================

<?php
/**
 * ReviewNudge — Aviso de puntos por reseña con foto en el correo de entrega.
 *
 * Superficie complementaria en el correo de pedido completado: un párrafo con la
 * promesa exacta del programa de recompensas. Solo opera cuando el motor de
 * reseñas está habilitado.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class ReviewNudge {

	/**
	 * Cuelga el párrafo en los detalles del correo.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_email_order_details', array( $this, 'render' ), 25, 4 );
	}

	/**
	 * Pinta el empujón solo en el correo de pedido COMPLETADO del cliente.
	 *
	 * @param \WC_Order $order         Pedido.
	 * @param bool      $sent_to_admin Si va al admin.
	 * @param bool      $plain_text    Si es texto plano.
	 * @param object    $email         Instancia del correo.
	 * @return void
	 */
	public function render( $order, $sent_to_admin, $plain_text, $email = null ): void {

		// El dueño selecciona los correos que incluyen el aviso.
		$ids = array_filter( array_map( 'trim', explode( ',', (string) get_option( 'akb_rewards_nudge_email_ids', 'customer_completed_order' ) ) ) );

		if ( $sent_to_admin || null === $email || ! in_array( (string) ( $email->id ?? '' ), $ids, true ) ) {
			return;
		}

		$reglas = RewardMessage::text();
		if ( '' === $reglas || ! Invitations::enabled() || 'yes' !== (string) get_option( 'akb_rewards_review_nudge_enabled', 'yes' ) ) {
			return;
		}

		// Respetar la preferencia de baja del cliente (InviteOptOut) para no incluir
		// recordatorios de reseña en los correos transaccionales del pedido.
		if ( $order instanceof \WC_Order && InviteOptOut::opted_out( (string) $order->get_billing_email() ) ) {
			return;
		}

		$sent = $order instanceof \WC_Order && '' !== (string) $order->get_meta( Invitations::META_SENT );
		if ( $sent ) {
			$texto = 'Busca nuestro correo «¿Qué te pareció tu pedido?»: lleva tu enlace personal para reseñar y solicitar puntos, con foto obligatoria.';
		} else {
			$texto = 'La invitación para escribir tu reseña llega en un correo separado. ';
			$texto .= sprintf(
				'Despacho: %s días después de «%s». Retiro o entrega directa: %s días después de «%s».',
				number_format_i18n( Invitations::delay_days() ),
				wc_get_order_status_name( Invitations::trigger_status() ),
				number_format_i18n( Invitations::pickup_delay_days() ),
				wc_get_order_status_name( Invitations::pickup_status() )
			);
			$texto .= ' Escribe tu opinión cuando hayas recibido la compra.';
		}
		$texto .= ' ' . $reglas;

		// El enlace personal se emite al enviar la invitación, nunca al pintar
		// un correo de Procesando. No se dirige al formulario nativo sin foto.
		if ( $plain_text ) {
			echo "\n" . esc_html( '📸 ' . $texto ) . "\n";
			return;
		}

		printf(
			'<p class="akb-email-callout" style="margin:18px 0;padding:14px 18px;background:#fbf8f0;border:1px solid #141416;border-left:4px solid #E60012;border-radius:0;color:#2b2b2b;-webkit-text-fill-color:#2b2b2b;font-size:14px;line-height:1.5;">📸 %s</p>',
			esc_html( $texto )
		);
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/ReviewPoints.php
================================================================

<?php
/**
 * ReviewPoints — Puntos por reseña con foto tras moderación y aprobación administrativa.
 *
 * Disparadores: la aprobación puede llegar por transición de estado o por inserción directa.
 * Se escuchan ambos eventos garantizando idempotencia mediante metadatos y event_key.
 *
 * Compuertas de validación:
 *   - Compra verificada obligatoria en WooCommerce.
 *   - Un pago por cliente-producto en toda la historia (índice UNIQUE en el ledger).
 *   - Exclusión de reseñas previas con incentivos de terceros.
 *   - Producto publicado, fotografía real y exclusión de reseñas de administración.
 *   - Usuarios invitados: retención con sello pendiente hasta validación de cuenta.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class ReviewPoints {

	public const META_POINTS_PAID   = '_akb_rewards_review_points';
	/** 1 = solicitó premio; 0 = reseña sin premio. Ausente conserva el trato anterior. */
	public const META_POINTS_REQUESTED = '_akb_rewards_review_points_requested';
	public const SEAL_PENDING_USER  = 'pending_account';

	/**
	 * Sello: pagada y luego REVERTIDA (rechazo, papelera, spam o borrado tras
	 * haber cobrado). REVERSIBLE: si se re-aprueba después, `maybe_pay()`
	 * la vuelve a evaluar desde cero.
	 */
	public const SEAL_REVERSED = 'reversed';

	/**
	 * Posición de la reseña en la cadencia «premiar cada N» (1, 2, 3…). Una
	 * reseña con avance y sin puntos igual cuenta para un premio.
	 */
	public const META_COUNTED       = '_akb_rewards_review_counted';

	/** Avance en la cadencia «premiar cada N» («2/3»), visible en moderación. */
	public const META_PROGRESS      = '_akb_rewards_review_progress';

	/**
	 * Cuelga los disparadores.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_comment_status', array( $this, 'on_status_transition' ), 10, 3 );
		add_action( 'comment_post', array( $this, 'on_comment_born' ), 20, 2 );
		add_action( 'wp_insert_comment', array( $this, 'on_comment_inserted' ), 20, 2 );
		// El vínculo invitado→cuenta exige verificar el correo antes de pagar.
		add_action( 'woocommerce_customer_email_verified', array( $this, 'on_email_verified' ), 20, 1 );
		// 'delete_comment' se ejecuta antes de modificar la base de datos, asegurando
		// que META_POINTS_PAID aún exista y pueda efectuarse la reversa de puntos
		// antes de que wp_delete_comment() elimine los metadatos asociados.
		add_action( 'delete_comment', array( $this, 'on_comment_deleting' ), 10, 2 );
	}

	/**
	 * Transición de estado de comentario: pagar al APROBAR, revertir en
	 * cualquier otro destino (rechazo, papelera, spam, o el pseudo-estado
	 * 'delete' de un borrado en firme —cubierto también, sin daño, por
	 * `on_comment_deleting()`, que corre primero con la meta intacta).
	 *
	 * @param string      $new_status Estado nuevo ('approved', 'unapproved'…).
	 * @param string      $old_status Estado anterior.
	 * @param \WP_Comment $comment    Comentario.
	 * @return void
	 */
	public function on_status_transition( $new_status, $old_status, $comment ): void {
		unset( $old_status );

		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		if ( 'approved' === (string) $new_status ) {
			$this->maybe_pay( $comment );
			return;
		}

		$this->maybe_reverse( $comment );
	}

	/**
	 * `delete_comment`: el punto MÁS TEMPRANO de `wp_delete_comment()`, con
	 * la commentmeta todavía intacta. Ver el comentario de `register()`.
	 *
	 * @param string           $comment_id ID del comentario (string en el hook nativo).
	 * @param \WP_Comment|null $comment    Comentario a borrar.
	 * @return void
	 */
	public function on_comment_deleting( $comment_id, $comment ): void {
		unset( $comment_id );

		if ( $comment instanceof \WP_Comment ) {
			$this->maybe_reverse( $comment );
		}
	}

	/**
	 * Nacimiento vía wp_new_comment (dispara comment_post con el veredicto).
	 *
	 * @param int        $comment_id ID del comentario.
	 * @param int|string $approved   1/0/'spam'.
	 * @return void
	 */
	public function on_comment_born( $comment_id, $approved ): void {
		if ( 1 !== (int) $approved ) {
			return;
		}

		$comment = get_comment( (int) $comment_id );
		if ( $comment instanceof \WP_Comment ) {
			$this->maybe_pay( $comment );
		}
	}

	/**
	 * Nacimiento vía wp_insert_comment directo (CusRev, imports): el hook
	 * comment_post no corre ahí; este sí, siempre.
	 *
	 * @param int         $comment_id ID.
	 * @param \WP_Comment $comment    Comentario recién insertado.
	 * @return void
	 */
	public function on_comment_inserted( $comment_id, $comment ): void {
		unset( $comment_id );

		if ( $comment instanceof \WP_Comment && '1' === (string) $comment->comment_approved ) {
			$this->maybe_pay( $comment );
		}
	}

	/**
	 * El gancho invitado→cuenta, tras VERIFICAR el email: sus reseñas
	 * aprobadas con sello pendiente cobran (el event_key impide dobles).
	 *
	 * El sello NO se borra antes: maybe_pay acepta `pending_account` y lo
	 * resuelve; si una compuerta posterior corta, la huella sobrevive.
	 *
	 * @param int $user_id Usuario con email verificado.
	 * @return void
	 */
	public function on_email_verified( $user_id ): void {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user instanceof \WP_User || '' === (string) $user->user_email ) {
			return;
		}

		$comments = get_comments(
			array(
				'type'         => 'review',
				'status'       => 'approve',
				'author_email' => (string) $user->user_email,
				'meta_key'     => self::META_POINTS_PAID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => self::SEAL_PENDING_USER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$pagadas = 0;
		foreach ( (array) $comments as $comment ) {
			if ( $comment instanceof \WP_Comment ) {
				$this->maybe_pay( $comment );
				// Comprueba si la reseña califica como incentivada para invalidar cachés.
				if ( '' !== Disclosure::notice_for( (int) $comment->comment_ID ) ) {
					++$pagadas;
				}
			}
		}

		// Si hubo reseñas que pasaron a incentivadas, purgar el transient del carrusel
		// y la caché de página para reflejar los avisos actualizados.
		if ( $pagadas > 0 ) {
			delete_transient( Carousel::TRANSIENT ); // La tarjeta del home también cambia de criterio.
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed (tercero).
		}
	}

	/**
	 * Paga los puntos de una reseña aprobada si cumple todas las compuertas.
	 *
	 * @param \WP_Comment $comment Reseña aprobada.
	 * @return void
	 */
	public function maybe_pay( \WP_Comment $comment ): void {
		$points = Options::review_points();
		if ( $points <= 0 ) {
			return; // Programa apagado.
		}

		if ( 'review' !== (string) $comment->comment_type ) {
			return;
		}

		if ( (int) $comment->comment_parent > 0 ) {
			return; // Una RESPUESTA jamás cobra ni quema el event_key (2a).
		}

		$comment_id = (int) $comment->comment_ID;
		$product_id = (int) $comment->comment_post_ID;
		$requested = (string) get_comment_meta( $comment_id, self::META_POINTS_REQUESTED, true );
		if ( '' !== $requested && '1' !== $requested ) {
			return; // Publicar una reseña no inscribe al cliente en los premios.
		}

		if ( 'product' !== get_post_type( $product_id ) || 'publish' !== get_post_status( $product_id ) ) {
			return;
		}

		$seal = (string) get_comment_meta( $comment_id, self::META_POINTS_PAID, true );
		if ( '' !== $seal && self::SEAL_PENDING_USER !== $seal && self::SEAL_REVERSED !== $seal ) {
			return; // Ya resuelta (pagada o descartada con huella).
		}

		if ( '' !== (string) get_comment_meta( $comment_id, AdminReviews::META_ADMIN_CREATED, true ) ) {
			return; // Las reseñas escritas por la tienda no se premian a sí mismas.
		}

		if ( '' !== (string) get_comment_meta( $comment_id, 'cr_coupon_code', true ) ) {
			// Ya cobró el cupón del tercero: una recompensa por acción.
			update_comment_meta( $comment_id, self::META_POINTS_PAID, 0 );
			return;
		}

		if ( ! Media::has_real_photo( $comment_id ) ) {
			return; // Sin foto real no hay premio (video y texto no cuentan).
		}

		$user_id = self::resolve_user( $comment );
		if ( $user_id <= 0 ) {
			// Sin cuenta verificada todavía: el hook de verificación re-evalúa.
			update_comment_meta( $comment_id, self::META_POINTS_PAID, self::SEAL_PENDING_USER );
			return;
		}

		if ( ! self::verified_purchase( $comment, $user_id, $product_id ) ) {
			return; // Sin compra verificada no hay premio (interruptor).
		}

		// Una retirada puede haber encontrado ocupado el candado. Completar
		// esa limpieza antes de volver a contar la misma reseña restaurada.
		if ( self::SEAL_REVERSED === $seal && ! self::release_cadence_slot( $user_id, $comment_id ) ) {
			do_action( 'akb_rewards_review_pay_failed', $comment, $user_id, $points );
			return;
		}

		// Política de frecuencia: tope histórico por cliente y cadencia «cada N reseñas».
		// Ambas se validan contra el ledger. El tope cuenta productos con crédito
		// vigente (neto de reversas) para asegurar que reseñas revertidas liberen el cupo.
		$max = Options::review_max_per_user();
		if ( $max > 0 && Ledger::count_live_credits( $user_id, 'review', 'review', 'review_reversal' ) >= $max ) {
			update_comment_meta( $comment_id, self::META_POINTS_PAID, 0 );
			return; // El cliente ya alcanzó su tope histórico de premios.
		}

		$every = Options::review_every_n();
		if ( $every > 1 ) {
			$locked = Ledger::with_user_lock(
				$user_id,
				static function () use ( $user_id, $comment_id ): int {
					// Idempotencia por reseña: asegura que llamadas concurrentes
					// no consuman posiciones duplicadas en la cadencia.
					$pinned = (int) get_comment_meta( $comment_id, self::META_COUNTED, true );
					if ( $pinned > 0 ) {
						return $pinned;
					}

					$n = Ledger::raw_user_meta_int( $user_id, '_akb_rewards_review_qualifying' ) + 1;
					update_user_meta( $user_id, '_akb_rewards_review_qualifying', $n );
					update_comment_meta( $comment_id, self::META_COUNTED, $n );
					return $n;
				}
			);

			if ( null === $locked ) {
				// Candado ocupado: sin sellar y sin contar para reintentar en el próximo evento.
				do_action( 'akb_rewards_review_pay_failed', $comment, $user_id, $points );
				return;
			}

			$qualifying = (int) $locked;
			// Retirar una intermedia puede devolver el contador al mismo múltiplo
			// que ya pagó. Los créditos aún vigentes consumen esos ciclos: no
			// basta con volver a alcanzar N para pagar el mismo grupo dos veces.
			if ( 0 === $qualifying % $every && Ledger::count_live_credits( $user_id, 'review', 'review', 'review_reversal' ) >= intdiv( $qualifying, $every ) ) {
				update_comment_meta( $comment_id, self::META_POINTS_PAID, 0 );
				delete_comment_meta( $comment_id, self::META_PROGRESS );
				return;
			}
			if ( 0 !== $qualifying % $every ) {
				// Calificada pero aún no toca premio: sellada con 0 y registro de progreso.
				update_comment_meta( $comment_id, self::META_POINTS_PAID, 0 );
				update_comment_meta( $comment_id, self::META_PROGRESS, sprintf( '%d/%d', $qualifying % $every, $every ) );
				return;
			}
		}

		// Clave de evento por ocurrencia: si el cliente ya tuvo una reversa para este producto,
		// la clave incorpora el índice de reversa para garantizar unicidad e idempotencia en el ledger.
		$reversals = Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review_reversal' );
		$event_key = $reversals > 0
			? sprintf( 'review_u%d_p%d_r%d', $user_id, $product_id, $reversals )
			: sprintf( 'review_u%d_p%d', $user_id, $product_id );

		$moved_before = Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review' );

		$result = Ledger::credit(
			$user_id,
			$points,
			'review',
			'review',
			$product_id,
			sprintf(
				'Reseña con foto aprobada (#%d)%s',
				$comment_id,
				get_current_user_id() > 0 ? sprintf( ' por %s', wp_get_current_user()->user_login ) : ''
			),
			true,
			$event_key
		);

		if ( ! is_int( $result ) ) {
			// Infraestructura: huella + alarma; la próxima aprobación (o el
			// nacimiento re-procesado) reintenta.
			do_action( 'akb_rewards_review_pay_failed', $comment, $user_id, $points );
			return;
		}

		// El duplicado por cliente-producto vuelve como éxito sin movimiento
		// (saldo intacto): se sella con 0 y huella — re-reseñar no re-paga.
		// Y el hook de abajo solo se dispara si ESTE llamado movió saldo: un
		// sello perdido no puede duplicar el correo de aviso (2a).
		$moved_now = Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review' ) > $moved_before;
		update_comment_meta( $comment_id, self::META_POINTS_PAID, $moved_now ? $points : 0 );

		if ( ! $moved_now ) {
			return;
		}

		/**
		 * Reseña premiada con puntos. El correo de aviso escucha acá.
		 *
		 * @param \WP_Comment $comment Reseña.
		 * @param int         $user_id Cliente premiado.
		 * @param int         $points  Puntos acreditados.
		 */
		do_action( 'akb_rewards_review_points_paid', $comment, $user_id, $points );
	}

	/**
	 * Revierte los puntos de una reseña que YA COBRÓ y deja de estar aprobada
	 * (rechazo, papelera, spam) o se borra en firme. Mismo patrón que las
	 * demás reversas del programa —
	 * `Accrual::deduct_all_locked()` para pedidos anulados y
	 * `Referrals::maybe_reverse_referrer()` para el premio del padrino—:
	 * débito con clave de evento PROPIA e idempotente, topado al saldo
	 * disponible (`Ledger::debit()` con `$require_sufficient = false` nunca
	 * deja el saldo negativo), y si no se recuperó todo, la misma alarma que
	 * usan esas otras reversas.
	 *
	 * Sólo debita si el sello guarda puntos. Las reseñas intermedias de una
	 * cadencia también liberan su posición, aunque aún no hayan cobrado.
	 * Un segundo disparo no repite ni el débito ni el decremento del avance.
	 *
	 * La reversa descuenta del saldo sin enviar correo transaccional automático.
	 *
	 * @param \WP_Comment $comment Reseña que deja de estar aprobada, o se borra.
	 * @return void
	 */
	private function maybe_reverse( \WP_Comment $comment ): void {
		if ( 'review' !== (string) $comment->comment_type || (int) $comment->comment_parent > 0 ) {
			return;
		}

		$comment_id = (int) $comment->comment_ID;
		$points     = (int) get_comment_meta( $comment_id, self::META_POINTS_PAID, true );
		if ( $points <= 0 ) {
			// Una reseña intermedia no pagó, pero sí consumió una posición.
			// Su retirada también debe liberar ese avance. El sello permite
			// reintentar si el candado está ocupado, incluso al reaprobársela.
			if ( (int) get_comment_meta( $comment_id, self::META_COUNTED, true ) > 0 ) {
				$user_id = self::resolve_user( $comment );
				if ( $user_id > 0 ) {
					update_comment_meta( $comment_id, self::META_POINTS_PAID, self::SEAL_REVERSED );
					self::release_cadence_slot( $user_id, $comment_id );
				}
			}
			return;
		}

		$user_id = self::resolve_user( $comment );
		if ( $user_id <= 0 ) {
			return;
		}

		$product_id = (int) $comment->comment_post_ID;

		// Clave por OCURRENCIA, igual que Referrals::maybe_reverse_referrer:
		// si esta reseña ya se había revertido, se re-aprobó y volvió a
		// pagar, la SEGUNDA reversa necesita una clave distinta o el UNIQUE
		// la trataría como «ya aplicada» y no descontaría nada la segunda vez.
		$reversals = Ledger::count_movements_for_user( $user_id, 'review', $product_id, 'review_reversal' );

		$deducted = Ledger::debit(
			$user_id,
			$points,
			'review_reversal',
			'review',
			$product_id,
			'Reseña retirada',
			false,
			sprintf( 'review_reversal_u%d_p%d_r%d', $user_id, $product_id, $reversals )
		);

		if ( ! is_int( $deducted ) ) {
			// Candado ocupado: sin sellar, el próximo disparo reintenta con la
			// MISMA clave (no se insertó nada; `$reversals` no cambió).
			return;
		}

		// Sello REVERSIBLE: si el dueño la re-aprueba después, maybe_pay() la
		// vuelve a evaluar desde cero (mismo trato que pending_account).
		update_comment_meta( $comment_id, self::META_POINTS_PAID, self::SEAL_REVERSED );

		if ( $deducted < $points ) {
			// El cliente ya se había gastado los puntos: no hay saldo
			// negativo que cobrarle, y el dueño necesita verlo (mismo patrón
			// que Accrual::deduct_all_locked() y
			// Referrals::maybe_reverse_referrer()).
			do_action( 'akb_rewards_reversal_failed', $comment, $points - $deducted );
		}

		// Cadencia «cada N reseñas»: liberar la posición consumida bajo el candado
		// del usuario para que la siguiente reseña calificada pueda utilizarla.
		// Se eliminan META_COUNTED y META_PROGRESS para que, en caso de una posterior
		// re-aprobación, se calcule una posición limpia a partir del estado actual del contador.
		self::release_cadence_slot( $user_id, $comment_id );
	}

	/**
	 * Libera una posición una sola vez, conservando las marcas si el candado falla.
	 *
	 * @param int $user_id Cliente.
	 * @param int $comment_id Reseña retirada.
	 * @return bool Si la limpieza terminó o no quedaba posición que liberar.
	 */
	private static function release_cadence_slot( int $user_id, int $comment_id ): bool {
		if ( (int) get_comment_meta( $comment_id, self::META_COUNTED, true ) <= 0 ) {
			return true;
		}
		return true === Ledger::with_user_lock(
			$user_id,
			static function () use ( $user_id, $comment_id ): bool {
				if ( (int) get_comment_meta( $comment_id, self::META_COUNTED, true ) <= 0 ) {
					return true;
				}
				$current = Ledger::raw_user_meta_int( $user_id, '_akb_rewards_review_qualifying' );
				update_user_meta( $user_id, '_akb_rewards_review_qualifying', max( 0, $current - 1 ) );
				delete_comment_meta( $comment_id, self::META_COUNTED );
				delete_comment_meta( $comment_id, self::META_PROGRESS );
				return true;
			}
		);
	}

	/**
	 * Compra verificada, con el cálculo perezoso clonado de CusRev: si la
	 * meta `verified` falta, se computa con wc_customer_bought_product y se
	 * PERSISTE (mismo efecto de escritura que el tercero).
	 *
	 * @param \WP_Comment $comment    Reseña.
	 * @param int         $user_id    Cliente resuelto.
	 * @param int         $product_id Producto.
	 * @return bool
	 */
	private static function verified_purchase( \WP_Comment $comment, int $user_id, int $product_id ): bool {
		if ( 'yes' !== (string) get_option( 'akb_rewards_review_require_verified', 'yes' ) ) {
			return true;
		}

		// El formulario conoce el pedido exacto. Su prueba incluye el estado
		// propio Listo para enviar y se revalida aunque Woo dejara verified=0,
		// o aunque un verified=1 anterior preceda a una anulación/devolución.
		$linked = VerifiedPurchase::for_review( $comment, $user_id );
		if ( null !== $linked ) {
			update_comment_meta( (int) $comment->comment_ID, 'verified', (int) $linked );
			return $linked;
		}

		$verified = (string) get_comment_meta( (int) $comment->comment_ID, 'verified', true );

		if ( '' === $verified && function_exists( 'wc_customer_bought_product' ) ) {
			$bought = wc_customer_bought_product( (string) $comment->comment_author_email, $user_id, $product_id );
			add_comment_meta( (int) $comment->comment_ID, 'verified', (int) $bought, true );
			// Usa el valor persistido, incluso si otro proceso ganó la inserción única.
			$verified = (string) get_comment_meta( (int) $comment->comment_ID, 'verified', true );
		}

		return '1' === $verified;
	}

	/**
	 * Resuelve el usuario dueño de la reseña: user_id directo, o el email del
	 * autor contra la tabla de usuarios (los invitados de CusRev quedan con
	 * user_id 0 — ingeniería inversa). Resolver por email exige que Woo
	 * confirme su verificación: crear una cuenta ajena no prueba identidad.
	 *
	 * @param \WP_Comment $comment Reseña.
	 * @return int 0 si no hay cuenta.
	 */
	private static function resolve_user( \WP_Comment $comment ): int {
		if ( (int) $comment->user_id > 0 ) {
			return (int) $comment->user_id;
		}

		$email = (string) $comment->comment_author_email;
		if ( '' === $email ) {
			return 0;
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user instanceof \WP_User || strtolower( (string) $user->user_email ) !== strtolower( $email ) || ! function_exists( 'wc_get_container' ) ) {
			return 0;
		}
		$verification = wc_get_container()->get( \Automattic\WooCommerce\Internal\CustomerEmailVerification\EmailVerificationService::class );
		return $verification->is_verified( (int) $user->ID ) ? (int) $user->ID : 0;
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/RewardMessage.php
================================================================

<?php
/**
 * Condiciones del premio compartidas por la invitación y el formulario.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

final class RewardMessage {

	/** Importe de la oferta o de los puntos efectivamente acreditados. */
	public static function amount_label( ?int $points = null ): string {
		$points = $points ?? Options::review_points();
		return sprintf( '%s Puntos Akibara = %s CLP de descuento', number_format_i18n( $points ), Options::points_label( $points ) );
	}

	/**
	 * Describe la política vigente sin prometer un crédito incondicional.
	 *
	 * @param bool $include_choice Explicar cómo optar en el formulario.
	 * @return string Texto plano, vacío si no hay programa de reseñas.
	 */
	public static function text( bool $include_choice = true ): string {
		if ( ! Options::reviews_lane_on() ) {
			return '';
		}

		$every = Options::review_every_n();
		$parts = $include_choice
			? array( 'La invitación es para reseñar y solicitar puntos: debes adjuntar una foto real de tu compra.' )
			: array();
		$parts = array_merge( $parts, array(
			1 === $every
				? sprintf( 'Puedes obtener %s por una reseña con foto aprobada que cumpla los requisitos.', self::amount_label() )
				: sprintf( 'Puedes obtener %s por cada %s reseñas con foto aprobadas que cumplan los requisitos; las intermedias suman avance.', self::amount_label(), number_format_i18n( $every ) ),
			'Un premio por producto y cliente.',
		) );
		if ( 'yes' === (string) get_option( 'akb_rewards_review_require_verified', 'yes' ) ) {
			$parts[] = 'La compra debe estar verificada.';
		}
		$max = Options::review_max_per_user();
		if ( $max > 0 ) {
			$parts[] = sprintf( 'Tope de %s premios por cliente.', number_format_i18n( $max ) );
		}
		$parts[] = 'Necesitas una cuenta con el correo de tu compra. Si aún no tienes cuenta, el premio queda pendiente hasta que la crees y verifiques el correo.';
		return implode( ' ', $parts );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/Schema.php
================================================================

<?php
/**
 * Schema — el JSON-LD de reseñas con la forma EXACTA del plugin de terceros
 * (orden explícita del dueño), entregado DENTRO del Product de Rank Math.
 *
 * Ingeniería inversa de CR_StructuredData (04-cusrev-ingenieria-inversa.md):
 *   - aggregateRating con EXACTAMENTE 3 claves: @type, ratingValue (el string
 *     crudo de 2 decimales de WooCommerce, sin redondear) y reviewCount. SIN
 *     bestRating/worstRating/ratingCount a ese nivel.
 *   - review[] = las 5 MÁS RECIENTES con rating > 0; cada nodo con 5 claves
 *     en este orden: @type, reviewRating {@type Rating, bestRating '5',
 *     ratingValue crudo, worstRating '1'}, author {Person, name}, reviewBody
 *     (comment_content SIN filtrar: crudo, sin truncar y sin HTML) y
 *     datePublished (ISO-8601 local, formato 'c').
 *   - Gate: get_rating_count() > 0 — sin ratings, ni una clave se toca.
 *   - El associatedMedia de CusRev es CÓDIGO MUERTO en esta instalación (el
 *     filtro de Woo que lo emitiría no se dispara jamás): el schema real
 *     servido no lo lleva, así que replicarlo sería inventar.
 *
 * Por qué Rank Math y no wp_footer: CusRev emitiría un SEGUNDO nodo Product
 * suelto junto al @graph de Rank Math (entidad duplicada a la primera
 * reseña). Acá las mismas claves entran a la entidad Product,Book existente
 * vía `rank_math/snippet/rich_snippet_product_entity`, reemplazando la forma
 * propia de Rank Math (aggregateRating de 4 claves + review[] SIN límite),
 * que es distinta de la del plugin de terceros.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Schema {

	/**
	 * Prioridad tardía: los enriquecedores de akibara-business (Book, GTIN)
	 * corren antes y esto solo pisa las claves de reseñas.
	 */
	private const PRIORITY = 50;

	/**
	 * Cuelga el filtro de Rank Math.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rank_math/snippet/rich_snippet_product_entity', array( $this, 'inject' ), self::PRIORITY );
	}

	/**
	 * Inyecta aggregateRating y review[] con la forma CusRev en la entidad.
	 *
	 * @param array<string,mixed> $entity Entidad Product de Rank Math.
	 * @return array<string,mixed>
	 */
	public function inject( $entity ) {
		if ( ! is_array( $entity ) ) {
			return $entity;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product() : false;
		if ( ! $product instanceof \WC_Product ) {
			return $entity;
		}

		// El gate del tercero: sin ratings no se toca nada (y se retira lo
		// que Rank Math hubiera puesto por su cuenta, para no publicar una
		// forma ajena a la del plugin que replicamos).
		if ( (int) $product->get_rating_count() <= 0 ) {
			unset( $entity['aggregateRating'], $entity['review'] );
			return $entity;
		}

		$entity['aggregateRating'] = array(
			'@type'       => 'AggregateRating',
			'ratingValue' => $product->get_average_rating(),
			'reviewCount' => $product->get_review_count(),
		);

		$reviews = self::latest_reviews( (int) $product->get_id() );
		if ( array() !== $reviews ) {
			$entity['review'] = $reviews;
		} else {
			unset( $entity['review'] );
		}

		return $entity;
	}

	/**
	 * Las 5 reseñas más recientes con rating > 0, con los nodos calcados.
	 *
	 * Query clonada del tercero: number 5, status approve, post publicado,
	 * top-level, meta rating NUMERIC > 0, sin orderby explícito (WordPress
	 * cae a comment_date_gmt DESC).
	 *
	 * El interruptor de exclusión omite de `review[]` las reseñas que cobraron
	 * puntos o contaron para un premio. `aggregateRating` conserva la nota y
	 * el total visibles en la ficha. Disclosure resuelve el aviso de incentivo;
	 * su visualización tiene un interruptor independiente.
	 *
	 * @param int $product_id Producto.
	 * @return array<int,array<string,mixed>>
	 */
	private static function latest_reviews( int $product_id ): array {
		$meta = array(
			array(
				'key'     => 'rating',
				'type'    => 'NUMERIC',
				'compare' => '>',
				'value'   => 0,
			),
		);

		if ( self::excludes_rewarded() ) {
			// `pending_account` (el sello de quien reseñó sin cuenta y aún no
			// cobró) castea a 0: todavía no es una reseña premiada. Mismo
			// criterio que Disclosure::notice_for().
			foreach ( array( ReviewPoints::META_POINTS_PAID, ReviewPoints::META_COUNTED ) as $clave ) {
				$meta[] = array(
					'relation' => 'OR',
					array(
						'key'     => $clave,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => $clave,
						'type'    => 'NUMERIC',
						'compare' => '<=',
						'value'   => 0,
					),
				);
			}
		}

		$comments = get_comments(
			array(
				'number'      => 5,
				'post_id'     => $product_id,
				'status'      => 'approve',
				'post_status' => 'publish',
				'post_type'   => 'product',
				'parent'      => 0,
				'meta_query'  => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- forma clonada del tercero.
			)
		);

		$nodes = array();
		foreach ( (array) $comments as $comment ) {
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}

			// reviewBody sale del comment_content CRUDO: get_comment_text()
			// pasa por el filtro `comment_text` (wpautop + make_clickable) y
			// metía <p> y <br /> dentro del JSON-LD, contra lo que el docblock
			// de este módulo promete.
			$nodes[] = array(
				'@type'        => 'Review',
				'reviewRating' => array(
					'@type'       => 'Rating',
					'bestRating'  => '5',
					'ratingValue' => get_comment_meta( (int) $comment->comment_ID, 'rating', true ),
					'worstRating' => '1',
				),
				'author'       => array(
					'@type' => 'Person',
					'name'  => get_comment_author( $comment ),
				),
				// Incluye en el cuerpo el aviso de transcripción de AdminReviews.
				'reviewBody'   => trim(
					(string) $comment->comment_content
					. ' ' . AdminReviews::transcription_notice( (int) $comment->comment_ID )
				),
				'datePublished' => get_comment_date( 'c', $comment ),
			);
		}

		return $nodes;
	}

	/**
	 * ¿El dueño pidió dejar las reseñas premiadas fuera de los datos
	 * estructurados?
	 *
	 * @return bool
	 */
	private static function excludes_rewarded(): bool {
		return 'yes' === (string) get_option( 'akb_rewards_schema_exclude_rewarded', 'no' );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/TokenCleanup.php
================================================================

<?php
/**
 * TokenCleanup — limpieza diaria de los enlaces de reseña caducados.
 *
 * La acción tiene agenda propia y se registra aunque estén apagados el motor
 * de reseñas o el vencimiento de puntos. Los enlaces existentes deben seguir
 * caducando sin depender de esos interruptores comerciales.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.9
 */
final class TokenCleanup {

	public const HOOK = 'akb_rewards_review_tokens_cleanup';

	/**
	 * Memo del estado que quedó en la cola, para no consultar Action
	 * Scheduler en cada petición de admin.
	 */
	private const SCHEDULED_MEMO = 'akb_rewards_tokens_cleanup_scheduled';

	/**
	 * Cuelga el oyente y la agenda.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Borra los tokens caducados y nunca usados.
	 *
	 * @return void
	 */
	public function run(): void {
		Schema::purge_stale_review_tokens();
	}

	/**
	 * Agenda diaria, memoizada y sólo en admin/cron/CLI — el mismo patrón que
	 * `Points\Expiry`, para que el sitio del cliente no pague por ella.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( 'yes' === get_transient( self::SCHEDULED_MEMO ) ) {
			return;
		}

		if ( false === as_next_scheduled_action( self::HOOK ) ) {
			$action_id = as_schedule_recurring_action( time() + 3 * HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK );
			if ( ! is_int( $action_id ) || 0 >= $action_id ) {
				return; // Sin memo: se reintenta en la siguiente petición.
			}
		}

		set_transient( self::SCHEDULED_MEMO, 'yes', 12 * HOUR_IN_SECONDS );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/VerifiedPurchase.php
================================================================

<?php
/**
 * Prueba de compra del pedido que emitió el enlace personal de reseña.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

final class VerifiedPurchase {

	/**
	 * Revalida la compra de una reseña creada desde el enlace de un pedido.
	 *
	 * @param \WP_Comment $comment Reseña.
	 * @param int         $user_id Cuenta que recibiría los puntos.
	 * @return bool|null Null si no procede del formulario propio.
	 */
	public static function for_review( \WP_Comment $comment, int $user_id ): ?bool {
		$order_id = (int) get_comment_meta( (int) $comment->comment_ID, ReviewForm::META_ORDER, true );
		if ( $order_id <= 0 ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order && self::order_confirms( $order, (int) $comment->comment_post_ID, (string) $comment->comment_author_email, $user_id );
	}

	/**
	 * Comprueba identidad, pago y una unidad conservada del producto.
	 *
	 * WooCommerce no incluye los estados propios de entrega en su consulta.
	 * Sólo sirven como prueba con fecha de pago y una ruta autorizada.
	 *
	 * @param \WC_Order $order      Pedido enlazado por el token.
	 * @param int       $product_id Producto reseñado.
	 * @param string    $email      Correo del autor.
	 * @param int       $user_id    Cuenta del autor, o 0 para un invitado.
	 * @return bool
	 */
	public static function order_confirms( \WC_Order $order, int $product_id, string $email, int $user_id ): bool {
		$status = (string) $order->get_status();
		if ( $product_id <= 0 || OrderStates::is_down( $status ) || in_array( $status, array( 'pending', 'on-hold', 'checkout-draft' ), true ) ) {
			return false;
		}
		if ( ! in_array( $status, array( 'processing', 'completed' ), true ) ) {
			$authorized = in_array( $status, OrderStates::PAID, true ) || Invitations::is_trigger( $status ) || $status === (string) $order->get_meta( Invitations::META_TRIGGER );
			if ( ! $authorized || ! $order->get_date_paid() ) {
				return false;
			}
		}
		$billing_email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' === $billing_email || $billing_email !== strtolower( trim( $email ) ) ) {
			return false;
		}
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 && $customer_id !== $user_id ) {
			return false;
		}
		if ( 0 === $customer_id && $user_id > 0 ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user instanceof \WP_User || strtolower( trim( (string) $user->user_email ) ) !== $billing_email ) {
				return false;
			}
		}
		$total = (float) $order->get_total();
		if ( $total > 0 && (float) $order->get_total_refunded() >= $total ) {
			return false;
		}
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( (int) $item->get_product_id() !== $product_id ) {
				continue;
			}
			$quantity = (float) $item->get_quantity();
			if ( $quantity > 0 && abs( (float) $order->get_qty_refunded_for_item( (int) $item_id ) ) < $quantity ) {
				return true;
			}
		}
		return false;
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Reviews/VoluntaryReviewForm.php
================================================================

<?php
/**
 * Foto opcional para la reseña voluntaria nativa de WooCommerce.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

/** Mantiene la reseña de la ficha voluntaria, verificada y pendiente. */
final class VoluntaryReviewForm {

	private const PHOTO_FIELD = 'akb_vr_photo';
	private const NONCE_FIELD = '_akb_vr_nonce';
	private const MAX_UPLOAD  = 5242880;
	/** @var int Foto recién subida que se elimina si Core no crea la reseña. */
	private int $attachment_id = 0;
	/** @var int Producto del envío cuya foto espera insertarse. */
	private int $product_id = 0;
	/** @var int Nivel del buffer propio para no cerrar un buffer ajeno. */
	private int $buffer_level = 0;

	/** Conecta la presentación y la validación antes de insertar o premiar. */
	public function register(): void {
		add_filter( 'woocommerce_product_review_comment_form_args', array( $this, 'handle_form_args' ), 20 );
		add_action( 'comment_form_before', array( $this, 'handle_form_before' ), 0 );
		add_action( 'comment_form_after', array( $this, 'handle_form_after' ), PHP_INT_MAX );
		add_filter( 'preprocess_comment', array( $this, 'handle_preprocess_comment' ), 20 );
		add_filter( 'pre_comment_approved', array( $this, 'handle_pre_comment_approved' ), PHP_INT_MAX, 2 );
		add_action( 'wp_insert_comment', array( $this, 'handle_inserted_comment' ), 1, 2 );
		add_action( 'shutdown', array( $this, 'discard_orphan_photo' ) );
	}

	/**
	 * Añade foto y nonce al formulario real de Woo sin JavaScript.
	 *
	 * @param array<string,mixed> $args Argumentos del formulario.
	 * @return array<string,mixed>
	 */
	public function handle_form_args( array $args ): array {
		$product_id = (int) get_the_ID();
		if ( get_current_user_id() <= 0 || 'product' !== get_post_type( $product_id ) ) {
			return $args;
		}
		$args['comment_field'] = (string) ( $args['comment_field'] ?? '' )
			. wp_nonce_field( 'akb_voluntary_review_' . $product_id, self::NONCE_FIELD, true, false )
			. '<input type="hidden" name="akb_rf_points_requested" value="0">'
			. '<p class="comment-form-photo"><label for="akb_vr_photo">Foto de tu compra (opcional)</label><input type="file" id="akb_vr_photo" name="akb_vr_photo" accept="image/jpeg,image/png,image/webp,image/heic" aria-describedby="akb-vr-photo-help"><span id="akb-vr-photo-help">Máximo 5 MB; JPG, PNG, WebP o HEIC. Adjuntar una foto no solicita puntos.</span></p>';
		return $args;
	}

	/** Captura sólo el formulario del producto para añadir el enctype que Core no expone. */
	public function handle_form_before(): void {
		if ( ! is_product() || get_current_user_id() <= 0 || $this->buffer_level > 0 ) {
			return;
		}
		ob_start();
		$this->buffer_level = ob_get_level();
	}

	/** Devuelve el formulario nativo con una codificación multipart real. */
	public function handle_form_after(): void {
		if ( 0 === $this->buffer_level || ob_get_level() !== $this->buffer_level ) {
			return;
		}
		$html               = (string) ob_get_clean();
		$this->buffer_level = 0;
		$html               = (string) preg_replace( '/(<form\b[^>]*)(>)/i', '$1 enctype="multipart/form-data"$2', $html, 1 );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado y escapado por comment_form().
	}

	/**
	 * Valida compra, nonce y foto; toda reseña nativa es voluntaria.
	 *
	 * @param array<string,mixed> $data Datos sin insertar.
	 * @return array<string,mixed>
	 */
	public function handle_preprocess_comment( array $data ): array {
		$path = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH );
		if ( 1 !== preg_match( '#(?:^|/)wp-comments-post\.php$#', $path ) ) {
			return $data;
		}
		$product_id = (int) ( $data['comment_post_ID'] ?? 0 );
		if ( 'product' !== get_post_type( $product_id ) || (int) ( $data['comment_parent'] ?? 0 ) > 0 ) {
			return $data;
		}
		$user_id = get_current_user_id();
		$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		if ( ! $user instanceof \WP_User || (int) ( $data['user_id'] ?? $data['user_ID'] ?? 0 ) !== $user_id ) {
			wp_die(
				'Inicia sesión con la cuenta de tu compra para enviar esta reseña. Si compraste como invitado, usa el enlace de reseña de tu pedido.',
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- se verifica justo debajo.
		if ( ! wp_verify_nonce( $nonce, 'akb_voluntary_review_' . $product_id ) ) {
			wp_die(
				'La sesión del formulario caducó. Vuelve a la ficha y reinténtalo.',
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		$order_id = self::verified_order_id( $product_id, $user_id, (string) $user->user_email );
		if ( 0 === $order_id ) {
			wp_die(
				'Solo puedes reseñar un producto de una compra verificada que no hayas devuelto.',
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		$file = $_FILES[ self::PHOTO_FIELD ] ?? array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- estructura validada por upload_photo(), después del nonce.
		if ( ! is_array( $file ) || ( isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== $file['error'] ) || ! empty( $file['name'] ) ) {
			$upload = self::upload_photo( self::PHOTO_FIELD );
			if ( is_wp_error( $upload ) ) {
				wp_die(
					esc_html( 'La foto no se pudo subir: ' . $upload->get_error_message() ),
					'',
					array(
						'response'  => 400,
						'back_link' => true,
					)
				);
			}
			$this->attachment_id = $upload;
			$this->product_id    = $product_id;
		}
		$data['comment_type']                                        = 'review';
		$data['comment_meta']                                        = is_array( $data['comment_meta'] ?? null ) ? $data['comment_meta'] : array();
		$data['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ] = 0;
		$data['comment_meta'][ ReviewForm::META_ORDER ]              = $order_id;
		$data['comment_meta']['verified']                            = 1;
		return $data;
	}

	/**
	 * Respeta el interruptor nativo y conserva spam, papelera y errores del núcleo.
	 *
	 * @param int|string|\WP_Error $approved Veredicto previo.
	 * @param array<string,mixed>  $data Datos de reseña.
	 * @return int|string|\WP_Error
	 */
	public function handle_pre_comment_approved( $approved, array $data ) {
		if ( 'yes' !== (string) get_option( 'akb_rewards_moderate_reviews', 'yes' ) ) {
			return $approved;
		}
		if ( isset( $data['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ] )
			&& 0 === $data['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ]
			&& 'product' === get_post_type( (int) ( $data['comment_post_ID'] ?? 0 ) )
			&& ! is_wp_error( $approved ) && ! in_array( $approved, array( 'spam', 'trash' ), true ) ) {
			return 0;
		}
		return $approved;
	}

	/**
	 * Enlaza la foto antes del motor; Core ya guardó la meta explícita.
	 *
	 * @param int         $comment_id Reseña creada.
	 * @param \WP_Comment $comment Reseña creada por Core.
	 */
	public function handle_inserted_comment( int $comment_id, \WP_Comment $comment ): void {
		if ( $this->attachment_id <= 0 || (int) $comment->comment_post_ID !== $this->product_id
			|| '0' !== (string) get_comment_meta( $comment_id, ReviewPoints::META_POINTS_REQUESTED, true ) ) {
			return;
		}
		if ( ! Media::attach_photo( $comment_id, $this->attachment_id, $this->product_id ) ) {
			$this->discard_orphan_photo();
		}
		$this->attachment_id = 0;
		$this->product_id    = 0;
	}

	/** Limpia la subida si WordPress rechazó la reseña por duplicado, flood o INSERT fallido. */
	public function discard_orphan_photo(): void {
		if ( $this->attachment_id > 0 ) {
			wp_delete_attachment( $this->attachment_id, true );
			$this->attachment_id = 0;
			$this->product_id    = 0;
		}
	}

	/**
	 * Busca compras de la cuenta y del mismo correo en páginas de 50 pedidos.
	 *
	 * @param int    $product_id Producto reseñado.
	 * @param int    $user_id Cuenta del comprador.
	 * @param string $email Correo de la cuenta.
	 * @return int Pedido que prueba una unidad conservada, o cero.
	 */
	private static function verified_order_id( int $product_id, int $user_id, string $email ): int {
		foreach ( array( array( 'customer_id' => $user_id ), array( 'billing_email' => $email ) ) as $identity ) {
			$page = 1;
			do {
				$orders = wc_get_orders(
					$identity + array(
						'limit'   => 50,
						'page'    => $page,
						'orderby' => 'ID',
						'order'   => 'DESC',
						'return'  => 'objects',
					)
				);
				foreach ( $orders as $order ) {
					if ( $order instanceof \WC_Order && VerifiedPurchase::order_confirms( $order, $product_id, $email, $user_id ) ) {
						return (int) $order->get_id();
					}
				}
				$count = count( $orders );
				++$page;
			} while ( 50 === $count );
		}
		return 0;
	}

	/**
	 * Valida error, tamaño y MIME real antes de subir mediante Core.
	 *
	 * @param string $field Campo de archivo del formulario con nonce validado.
	 * @return int|\WP_Error Adjunto creado o error de validación/subida.
	 */
	public static function upload_photo( string $field ) {
		$file = $_FILES[ $field ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se validan cada tipo, error, tamaño y MIME antes de usar la estructura.
		if ( ! is_array( $file ) || ! is_string( $file['name'] ?? null ) || ! is_string( $file['tmp_name'] ?? null )
			|| ! is_scalar( $file['size'] ?? null ) || ! is_scalar( $file['error'] ?? null )
			|| UPLOAD_ERR_OK !== (int) $file['error'] || '' === $file['name'] || '' === $file['tmp_name'] ) {
			return new \WP_Error( 'akb_review_upload', 'elige una imagen válida y vuelve a adjuntarla.' );
		}
		if ( (int) $file['size'] > self::MAX_UPLOAD || (int) $file['size'] <= 0 ) {
			return new \WP_Error( 'akb_review_size', 'la imagen debe pesar más de 0 bytes y un máximo de 5 MB.' );
		}
		if ( ! in_array( wp_get_image_mime( $file['tmp_name'] ), array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ), true ) ) {
			return new \WP_Error( 'akb_review_type', 'usa una foto JPG, PNG, WebP o HEIC.' );
		}
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		return media_handle_upload(
			$field,
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
					'heic'         => 'image/heic',
				),
			)
		);
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/Alerts.php
================================================================

<?php
/**
 * Alerts — el dueño se entera cuando algo del programa necesita una mano.
 *
 * Notifica proactivamente al administrador cuando un evento del programa requiere intervención manual.
 *
 * Avisa sólo lo que NO se arregla solo. Lo que se reintenta —un candado
 * ocupado, un correo que no salió— avisa recién cuando agota sus intentos.
 * Cada correo dice qué pasó, con cuánto, qué hacer, y trae el enlace directo.
 * El mismo aviso no se repite en el día: varias reversas corren por dos
 * caminos a la vez (el reembolso y el cambio de estado).
 *
 * El interruptor y los destinatarios viven en WooCommerce → Puntos y Reseñas
 * → Alertas.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

use Akibara\Rewards\Points\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.1
 */
final class Alerts {

	/** Prefijo del transient que evita repetir el mismo aviso en el día. */
	private const TRANSIENT = 'akb_rw_alerta_';

	/**
	 * Cuelga los oyentes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'akb_rewards_redemption_shortfall', array( $this, 'on_redemption_shortfall' ), 10, 4 );
		add_action( 'akb_rewards_reversal_failed', array( $this, 'on_reversal_failed' ), 10, 2 );
		add_action( 'akb_rewards_manual_review_needed', array( $this, 'on_manual_review_needed' ), 10, 2 );
		add_action( 'akb_rewards_review_pay_failed', array( $this, 'on_review_pay_failed' ), 10, 3 );
		add_action( 'akb_rewards_redemption_stuck', array( $this, 'on_redemption_stuck' ), 10, 1 );
		add_action( 'akb_rewards_email_gave_up', array( $this, 'on_email_gave_up' ), 10, 2 );
		add_action( 'akb_rewards_referral_pay_failed', array( $this, 'on_referral_pay_failed' ), 10, 3 );
	}

	/**
	 * El premio a quien invitó no se pudo acreditar (sistema ocupado).
	 *
	 * Un pedido Completado casi nunca vuelve a cambiar de estado, así que
	 * esperar «la próxima transición» dejaba al padrino sin su premio.
	 *
	 * @param \WC_Order $order    Pedido del invitado.
	 * @param int       $referrer Padrino.
	 * @param int       $reward   Puntos que debía cobrar.
	 * @return void
	 */
	public function on_referral_pay_failed( $order, $referrer, $reward ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->send(
			'referral_' . $order->get_id(),
			sprintf( 'Un premio de referido no se acreditó (pedido #%d)', $order->get_id() ),
			sprintf(
				'<p style="margin:0 0 12px">La compra del pedido <strong>#%d</strong> debía pagar %s Puntos Akibara a quien invitó a ese cliente (cuenta #%d), pero el sistema estaba ocupado y no se acreditaron.</p><p style="margin:0">Para reintentar, cambia el pedido a otro estado y vuelve a marcarlo como %s. El premio no se paga dos veces.</p>',
				(int) $order->get_id(),
				esc_html( number_format_i18n( (int) $reward ) ),
				(int) $referrer,
				// El estado que paga el premio lo elige el dueño en el panel.
				esc_html( wc_get_order_status_name( Options::referral_status() ) )
			),
			$order->get_edit_order_url(),
			'Abrir el pedido',
			$order
		);
	}

	/**
	 * Un pedido quedó con descuento de puntos que el saldo no cubría.
	 *
	 * @param \WC_Order $order     Pedido.
	 * @param int       $points    Puntos del descuento.
	 * @param int       $debited   Puntos que sí se cobraron.
	 * @param int       $shortfall Puntos sin respaldo.
	 * @return void
	 */
	public function on_redemption_shortfall( $order, $points, $debited, $shortfall ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->send(
			'shortfall_' . $order->get_id(),
			sprintf( 'Canje sin saldo suficiente en el pedido #%d', $order->get_id() ),
			sprintf(
				'<p style="margin:0 0 12px">El pedido <strong>#%d</strong> lleva un descuento de %s Puntos Akibara, pero al cobrarlos el saldo del cliente sólo cubría %s. Faltaron <strong>%s puntos</strong>, que la tienda descontó sin respaldo.</p><p style="margin:0">Pasa cuando el cobro no ocurre en la caja (por ejemplo, al revivir un pedido anulado después de que el cliente gastó los puntos devueltos). Revisa el pedido y decide con el cliente si se ajusta.</p>',
				(int) $order->get_id(),
				esc_html( number_format_i18n( (int) $points ) ),
				esc_html( number_format_i18n( (int) $debited ) ),
				esc_html( number_format_i18n( (int) $shortfall ) )
			),
			$order->get_edit_order_url(),
			'Abrir el pedido',
			$order
		);
	}

	/**
	 * Una devolución o reversa de puntos no se pudo cobrar completa.
	 *
	 * El disparador es un pedido (anulación/reembolso) o una reseña con foto retirada
	 * tras haber pagado: utiliza el hook unificado `akb_rewards_reversal_failed`.
	 *
	 * @param \WC_Order|\WP_Comment $sujeto   Pedido o reseña que disparó la reversa.
	 * @param int                    $faltante Puntos que no se recuperaron.
	 * @return void
	 */
	public function on_reversal_failed( $sujeto, $faltante ): void {
		if ( $sujeto instanceof \WC_Order ) {
			$this->send(
				'reversal_' . $sujeto->get_id() . '_' . (int) $faltante,
				sprintf( 'Puntos que no se pudieron descontar en el pedido #%d', $sujeto->get_id() ),
				sprintf(
					'<p style="margin:0 0 12px">Al anular o devolver el pedido <strong>#%d</strong> había que descontar Puntos Akibara que ese pedido había dado, y faltaron <strong>%s puntos</strong>: la cuenta ya los había gastado.</p><p style="margin:0">El detalle está en las notas del pedido. Si corresponde, corrígelo en WooCommerce → Puntos y Reseñas → «Otorgar o quitar puntos a mano».</p>',
					(int) $sujeto->get_id(),
					esc_html( number_format_i18n( (int) $faltante ) )
				),
				$sujeto->get_edit_order_url(),
				'Abrir el pedido',
				$sujeto
			);
			return;
		}

		if ( $sujeto instanceof \WP_Comment ) {
			$id = (int) $sujeto->comment_ID;
			$this->send(
				'review_reversal_' . $id . '_' . (int) $faltante,
				sprintf( 'Puntos que no se pudieron descontar al retirar la reseña #%d', $id ),
				sprintf(
					'<p style="margin:0 0 12px">Al dejar de aprobar o borrar la reseña <strong>#%d</strong> había que descontar Puntos Akibara que había pagado, y faltaron <strong>%s puntos</strong>: la cuenta ya los había gastado.</p><p style="margin:0">Si corresponde, corrígelo en WooCommerce → Puntos y Reseñas → «Otorgar o quitar puntos a mano».</p>',
					$id,
					esc_html( number_format_i18n( (int) $faltante ) )
				),
				admin_url( 'comment.php?action=editcomment&c=' . $id ),
				'Abrir la reseña',
				null,
				$sujeto
			);
		}
	}

	/**
	 * Se borró un reembolso que ya había descontado puntos.
	 *
	 * @param \WC_Order $order     Pedido.
	 * @param int       $refund_id Reembolso borrado.
	 * @return void
	 */
	public function on_manual_review_needed( $order, $refund_id ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->send(
			'refund_deleted_' . $order->get_id() . '_' . (int) $refund_id,
			sprintf( 'Se borró un reembolso con puntos en el pedido #%d', $order->get_id() ),
			sprintf(
				'<p style="margin:0 0 12px">Se borró el reembolso <strong>#%d</strong> del pedido <strong>#%d</strong>. Los Puntos Akibara que ese reembolso había descontado <strong>no se devuelven solos</strong>.</p><p style="margin:0">Revisa el historial de puntos del cliente y corrígelo en WooCommerce → Puntos y Reseñas → «Otorgar o quitar puntos a mano».</p>',
				(int) $refund_id,
				(int) $order->get_id()
			),
			$order->get_edit_order_url(),
			'Abrir el pedido',
			$order
		);
	}

	/**
	 * Una reseña aprobada no acreditó sus puntos.
	 *
	 * @param \WP_Comment $comment Reseña.
	 * @param int         $user_id Cliente.
	 * @param int         $points  Puntos que debía cobrar.
	 * @return void
	 */
	public function on_review_pay_failed( $comment, $user_id, $points ): void {
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		$id = (int) $comment->comment_ID;
		$this->send(
			'review_' . $id,
			sprintf( 'Una reseña aprobada no acreditó sus puntos (#%d)', $id ),
			sprintf(
				'<p style="margin:0 0 12px">La reseña <strong>#%d</strong> quedó aprobada, pero sus %s Puntos Akibara no se acreditaron a la cuenta #%d: el sistema estaba ocupado en ese momento.</p><p style="margin:0">Para reintentar, desapruébala y vuelve a aprobarla en Productos → Valoraciones. El premio no se paga dos veces.</p>',
				$id,
				esc_html( number_format_i18n( (int) $points ) ),
				(int) $user_id
			),
			admin_url( 'comment.php?action=editcomment&c=' . $id ),
			'Abrir la reseña',
			null,
			$comment
		);
	}

	/**
	 * Una operación de canje agotó sus reintentos.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return void
	 */
	public function on_redemption_stuck( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->send(
			'stuck_' . $order->get_id(),
			sprintf( 'Puntos canjeados sin resolver en el pedido #%d', $order->get_id() ),
			sprintf(
				'<p style="margin:0 0 12px">El pedido <strong>#%d</strong> tiene un movimiento de puntos canjeados que no se pudo completar tras varios reintentos: devolver los puntos de un pedido anulado, o cobrar el canje de uno vigente.</p><p style="margin:0">Revisa el historial de puntos del cliente y las notas del pedido, y corrígelo en WooCommerce → Puntos y Reseñas → «Otorgar o quitar puntos a mano» si hace falta.</p>',
				(int) $order->get_id()
			),
			$order->get_edit_order_url(),
			'Abrir el pedido',
			$order
		);
	}

	/**
	 * Un correo al cliente no salió tras todos sus intentos.
	 *
	 * @param string           $hook Reintento agotado.
	 * @param array<int,mixed> $args Sus argumentos.
	 * @return void
	 */
	public function on_email_gave_up( $hook, $args ): void {
		$args = array_values( (array) $args );
		$id   = (int) ( $args[0] ?? 0 );

		switch ( (string) $hook ) {
			case \Akibara\Rewards\Reviews\Invitations::HOOK:
				$que    = sprintf( 'la invitación a reseñar del pedido #%d', $id );
				$order  = wc_get_order( $id );
				$enlace = $order instanceof \WC_Order ? $order->get_edit_order_url() : admin_url( 'admin.php?page=wc-orders' );
				break;
			case Emails::HOOK_RETRY_ORDER:
				$que    = sprintf( 'el correo de puntos del pedido #%d', $id );
				$enlace = admin_url( 'post.php?post=' . $id . '&action=edit' );
				break;
			case Emails::HOOK_RETRY_REVIEW:
				$que    = sprintf( 'el correo del premio de la reseña #%d', $id );
				$enlace = admin_url( 'comment.php?action=editcomment&c=' . $id );
				break;
			case Emails::HOOK_RETRY_REFERRAL:
				$que    = sprintf( 'el correo a quien invitó (cuenta #%d)', $id );
				$enlace = admin_url( 'user-edit.php?user_id=' . $id );
				break;
			default:
				return;
		}

		$this->send(
			'email_' . md5( (string) $hook . '|' . implode( '|', array_map( 'strval', array_slice( $args, 0, -1 ) ) ) ),
			'Un correo de Puntos Akibara no salió',
			sprintf(
				'<p style="margin:0 0 12px">Tras tres intentos en doce horas no salió %s.</p><p style="margin:0">Si también fallan otros correos de la tienda, revisa el correo del sitio (la conexión de envío). Si no, revisa que la dirección del cliente esté bien escrita.</p>',
				esc_html( $que )
			),
			$enlace,
			'Abrir'
		);
	}

	/**
	 * Envía el aviso a los destinatarios del panel, una vez al día por clave.
	 *
	 * @param string           $key     Qué aviso es (para no repetirlo).
	 * @param string           $subject Asunto sin prefijo.
	 * @param string           $body    Cuerpo HTML.
	 * @param string           $url     Enlace del botón.
	 * @param string           $label   Texto del botón.
	 * @param \WC_Order|null   $order   Pedido del aviso, si lo hay.
	 * @param \WP_Comment|null $comment Reseña del aviso, si la hay.
	 * @return void
	 */
	private function send( string $key, string $subject, string $body, string $url, string $label, $order = null, $comment = null ): void {
		// Al registro de WooCommerce SIEMPRE, antes que el interruptor y la marca
		// del día: el correo que no sale suele ser la causa de la alerta, y con
		// las alertas apagadas el registro es la única traza.
		Log::error( 'Alerta para el dueño: ' . $subject );

		if ( ! Options::alerts_on() || ! function_exists( 'WC' ) ) {
			return;
		}

		$destinatarios = Options::alerts_recipients();
		if ( array() === $destinatarios ) {
			return;
		}

		$marca = self::TRANSIENT . md5( $key );
		if ( false !== get_transient( $marca ) ) {
			return;
		}

		$enviado = Emails::send_branded(
			implode( ', ', $destinatarios ),
			'[Akibara Puntos] ' . $subject,
			'Puntos Akibara: revisar',
			$body . Emails::cta( $label, $url ),
			Emails::context( 'admin_alert', 0, $order, $comment )
		);

		if ( $enviado ) {
			set_transient( $marca, 1, DAY_IN_SECONDS );
		}
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/Assets.php
================================================================

<?php
/**
 * Assets — el `?ver=` de los assets del plugin, con un solo criterio.
 *
 * El token combina versión y filemtime para invalidar assets modificados.
 * Si el fichero no existe, conserva la versión sin consultar su mtime.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.1
 */
final class Assets {

	/**
	 * URL pública de un asset del plugin.
	 *
	 * @param string $relative Ruta relativa a la raíz del plugin.
	 * @return string
	 */
	public static function url( string $relative ): string {
		return AKIBARA_REWARDS_URL . $relative;
	}

	/**
	 * Token de caché: versión del plugin + mtime del fichero.
	 *
	 * @param string $relative Ruta relativa a la raíz del plugin.
	 * @return string
	 */
	public static function version( string $relative ): string {
		$path  = AKIBARA_REWARDS_DIR . $relative;
		$mtime = file_exists( $path ) ? (int) filemtime( $path ) : 0;

		return AKIBARA_REWARDS_VERSION . ( $mtime > 0 ? '.' . $mtime : '' );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/Audit.php
================================================================

<?php
/**
 * Audit — reconciliación saldo-cacheado vs ledger.
 *
 * El saldo vive cacheado en usermeta y el ledger es la verdad contable.
 * Esta auditoría reconcilia desviaciones eventuales garantizando consistencia.
 *
 * La reparación NO escribe asientos: el ledger ya es correcto (el INSERT
 * ocurrió); lo que quedó viejo es la caché. Reparar = re-derivar la meta de
 * la suma, bajo el candado del usuario. Asentar «la diferencia» — lo que
 * hace el set_points_balance del oficial — movería la verdad para igualar
 * la caché: la dirección equivocada.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

use Akibara\Rewards\Points\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class Audit {

	/**
	 * Usuarios cuya meta de saldo no cuadra con la suma del ledger.
	 *
	 * @return array<int,array{user_id:int,meta:int,ledger:int}>
	 */
	public static function mismatches(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.user_id, COALESCE(m.meta_value, 0) + 0 AS meta, COALESCE(SUM(l.delta), 0) AS ledger'
				. ' FROM ' . Schema::ledger_table() . ' l' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia.
				. " LEFT JOIN {$wpdb->usermeta} m ON m.user_id = l.user_id AND m.meta_key = %s"
				// GREATEST(0, ...): la meta no baja de 0; ledgers con saldo negativo se reportan por separado.
				. ' GROUP BY l.user_id, m.meta_value HAVING meta <> GREATEST(0, ledger)',
				Ledger::META_BALANCE
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'user_id' => (int) $row['user_id'],
				'meta'    => (int) $row['meta'],
				'ledger'  => (int) $row['ledger'],
			);
		}

		return $out;
	}

	/**
	 * Repara la meta de UN usuario re-derivándola del ledger, bajo candado y
	 * con la suma releída dentro (la foto de fuera puede ser vieja).
	 *
	 * @param int $user_id Usuario.
	 * @return int|null Saldo reparado, o null sin candado.
	 */
	/**
	 * Usuarios cuyo ledger suma valor negativo. Se reporta para inspección contable independiente.
	 *
	 * @return array<int, array{user_id: int, ledger: int}>
	 */
	public static function negatives(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT user_id, COALESCE(SUM(delta), 0) AS ledger FROM ' . Schema::ledger_table() // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia, sin entrada de usuario.
			. ' GROUP BY user_id HAVING ledger < 0',
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'user_id' => (int) $row['user_id'],
				'ledger'  => (int) $row['ledger'],
			);
		}

		return $out;
	}

	public static function repair( int $user_id ) {
		return Ledger::with_user_lock(
			$user_id,
			static function () use ( $user_id ): int {
				$truth = max( 0, Ledger::balance_from_ledger( $user_id ) );
				update_user_meta( $user_id, Ledger::META_BALANCE, $truth );
				return $truth;
			}
		);
	}

	/**
	 * Comando `wp akb-rewards audit [--fix]`.
	 *
	 * @return void
	 */
	public static function register_cli(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command(
			'akb-rewards audit',
			static function ( $args, $assoc ): void {
				unset( $args );
				foreach ( self::negatives() as $row ) {
					\WP_CLI::log( sprintf(
						'user %d: el ledger suma %d (negativo). No es una desviación: el saldo visible es 0. Le falta el movimiento de arranque de la migración; se corrige con un ajuste manual, no con --fix.',
						$row['user_id'],
						$row['ledger']
					) );
				}
				$bad = self::mismatches();

				if ( array() === $bad ) {
					\WP_CLI::success( 'Ledger y saldos cuadran: 0 desviaciones.' );
					return;
				}

				foreach ( $bad as $row ) {
					\WP_CLI::log( sprintf(
						'user %d: meta=%d ledger=%d (dif %+d)',
						$row['user_id'],
						$row['meta'],
						$row['ledger'],
						$row['ledger'] - $row['meta']
					) );

					if ( isset( $assoc['fix'] ) ) {
						$fixed = self::repair( $row['user_id'] );
						\WP_CLI::log( null === $fixed
							? sprintf( '  → SIN candado para user %d; reintentar.', $row['user_id'] )
							: sprintf( '  → meta reparada a %d (derivada del ledger).', $fixed ) );
					}
				}

				if ( ! isset( $assoc['fix'] ) ) {
					\WP_CLI::warning( sprintf( '%d desviaciones. Repite con --fix para reparar las metas desde el ledger.', count( $bad ) ) );
				}
			}
		);
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/Log.php
================================================================

<?php
/**
 * Log — la bitácora del programa, en el sitio donde el dueño puede leerla.
 *
 * Los siete eventos críticos del carril de DINERO (candado no disponible,
 * INSERT del ledger caído, configuración de vigencia rota, invitación no
 * emitida, migración de esquema fallida) iban a `error_log()`: un fichero que
 * hay que abrir por SSH, que rota sin avisar y que mezcla el ruido de todos
 * los plugins. WooCommerce ya trae la bitácora con pantalla propia
 * (WooCommerce → Estado → Registros), retención y filtro por origen, y es el
 * estándar del plugin hermano.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.1
 */
final class Log {

	/**
	 * Origen del registro: el desplegable de la pantalla de Woo filtra por él.
	 */
	public const SOURCE = 'akibara-rewards';

	/**
	 * Registra un evento crítico de dinero.
	 *
	 * @param string $message Mensaje ya formateado.
	 * @return void
	 */
	public static function error( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => self::SOURCE ) );
			return;
		}

		// Sin el logger de Woo (activación a medias, arranque roto) el evento
		// NO puede quedar mudo: es la única traza de un movimiento de puntos
		// rechazado.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- último recurso; ver arriba.
		error_log( '[' . self::SOURCE . '] ' . $message );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/Options.php
================================================================

<?php
/**
 * Options — lectura tipada de la configuración del programa.
 *
 * Los valores por defecto replican las reglas base del programa de recompensas:
 * 2 puntos por cada $100 sobre subtotal tras descuentos con IVA, sin incluir envío;
 * canje 1 punto = $1 CLP; bono de bienvenida de 500 puntos; estado de acreditación
 * «Procesando» (ver `award_status()`).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Options {

	/**
	 * Tope por defecto del canje, en porcentaje del pedido. Es SOLO el valor
	 * inicial del campo del panel: el dueño lo cambia en WooCommerce → Puntos
	 * y Reseñas sin tocar código (regla de la casa).
	 */
	public const DEFAULT_REDEEM_MAX_PCT = 50;

	/**
	 * Tope por defecto del canje en pesos por pedido. Igual que el porcentual:
	 * es sólo el valor inicial del campo; el dueño manda desde el panel.
	 * El mínimo de carrito se configura por separado mediante redeem_min_cart().
	 */
	public const DEFAULT_REDEEM_MAX_AMOUNT = 5000;

	/**
	 * Estado de pedido que acredita, de fábrica. Es sólo el valor inicial del
	 * selector del panel; el dueño manda desde WooCommerce → Puntos y Reseñas.
	 *
	 * «Procesando» es, en Akibara, el estado al que llega el pedido cuando la
	 * pasarela confirma el pago.
	 */
	public const DEFAULT_AWARD_STATUS = 'processing';

	/**
	 * Días de carencia por defecto antes de liberar los puntos de compra a saldo disponible.
	 */
	public const DEFAULT_ESCROW_DAYS = 7;

	/**
	 * Techo de fábrica de la RAZÓN del programa: cuántos pesos puede devolver
	 * la tienda por cada 100 gastados, contando las tres patas a la vez
	 * (puntos por tramo ÷ tamaño del tramo × pesos por punto). Hoy la razón
	 * vigente es 2 % (2 puntos por cada $100, a $1 el punto), así que 15 es
	 * 7,5 veces lo que la tienda regala: es el umbral del ABSURDO, no el
	 * óptimo.
	 *
	 * El número es el MISMO 15 del techo de descuento de la tienda
	 * (Akibara\Business\Utilities\DiscountCeiling::DEFAULT_PCT) porque un
	 * punto regalado es margen que se va igual que un descuento. Y como allá,
	 * es un AJUSTE del panel («Techo de devolución del programa»), no un muro
	 * en el código: la regla de la casa es que todo se opere sin programar.
	 * Lo que sí vive en código es HARD_MAX_GIVEBACK_PCT, el límite del campo.
	 *
	 * DECISIÓN EXPLÍCITA: el 15 se COPIA, no se enlaza con
	 * `akb_discount_max_pct`. Si esto lo leyera en vivo, una campaña que suba
	 * el techo de descuento al 30 % desbloquearía en silencio un programa de
	 * puntos al 30 %, y rewards cambiaría de conducta si business se desactiva.
	 */
	public const DEFAULT_MAX_GIVEBACK_PCT = 15;

	/**
	 * Límite del campo del techo. Devolver la mitad de cada compra en puntos
	 * ya no es un programa de fidelidad, es una rebaja disfrazada: es la
	 * baranda contra el tecleo (150, 1500), como los `max` del catálogo.
	 *
	 * Las dos constantes viven acá porque las consume la fila del catálogo
	 * (`SettingsPage::FIELDS['akb_rewards_max_giveback_pct']`, 'd' y 'max'), y
	 * ese catálogo es el ÚNICO que lee el techo: es una baranda del GUARDADO
	 * —el panel no deja persistir una razón que lo supere—, no una regla que
	 * el motor de puntos consulte en cada compra. Quien lea la option en
	 * runtime tiene que acotarla como lo hace `SettingsPage::clamp_int()`, que
	 * aplica el mismo min/max de la fila; no hay un segundo acotador aparte.
	 */
	public const HARD_MAX_GIVEBACK_PCT = 50;

	/**
	 * Puntos otorgados por cada tramo de $earn_amount().
	 *
	 * @return int
	 */
	public static function earn_points(): int {
		if ( ! self::lane_on( 'akb_rewards_earn_enabled' ) ) {
			return 0; // Interruptor del carril: apagado = nadie acumula.
		}
		return max( 0, (int) get_option( 'akb_rewards_earn_points', 2 ) );
	}

	/**
	 * Interruptor de UN CLIC por carril (todo activable/desactivable
	 * «ahora o después» por administración).
	 * Vive en el ACCESSOR a propósito: apagarlo aquí apaga a la vez el motor,
	 * los correos, Mi cuenta, la ficha y la página pública — nadie más tiene
	 * que preguntar. Los montos guardados se conservan para re-encender.
	 *
	 * @param string $option Option yes/no del carril.
	 * @return bool
	 */
	private static function lane_on( string $option ): bool {
		return 'yes' === (string) get_option( $option, 'yes' );
	}

	/**
	 * ¿El carrusel de la portada está encendido? El clic del panel manda.
	 *
	 * @return bool
	 */
	public static function carousel_on(): bool {
		return self::lane_on( 'akb_rewards_carousel_enabled' );
	}

	/**
	 * Tamaño del tramo en pesos sobre el que se otorgan earn_points().
	 *
	 * @return int
	 */
	public static function earn_amount(): int {
		// 0 APAGA la acumulación (la guarda de Calculator devuelve 0 puntos).
		// Evita que un campo vacío se convierta indebidamente en tramo de $1.
		return max( 0, (int) get_option( 'akb_rewards_earn_amount', 100 ) );
	}

	/**
	 * Estado de pedido que dispara la acreditación (sin prefijo wc-).
	 *
	 * Invariante de negocio: «Procesando» es el estado al que llega el pedido
	 * cuando la pasarela confirma el PAGO. El cliente que acaba de pagar ve sus
	 * puntos en el acto, en vez de esperar a despacho físico; lo que se acredita
	 * de más si la venta se cae lo devuelve la reversa (cancelado, fallido o
	 * reembolsado deduce todo).
	 *
	 * El panel lo gobierna vía `akb_rewards_award_status`.
	 *
	 * @return string
	 */
	public static function award_status(): string {
		$status = (string) get_option( 'akb_rewards_award_status', self::DEFAULT_AWARD_STATUS );
		return '' !== $status ? $status : self::DEFAULT_AWARD_STATUS;
	}

	/**
	 * Días de carencia para liberación de puntos ganados por compra.
	 *
	 * 7 días exactos tras completarse el pedido (status = completed), para prevenir
	 * arbitraje y devoluciones con puntos ya canjeados.
	 * En entorno de tests o si se configura 0, se acreditan de inmediato.
	 *
	 * @return int
	 */
	public static function escrow_days(): int {
		$raw = get_option( 'akb_rewards_accrual_escrow_days', null );
		if ( null === $raw || false === $raw ) {
			$days = defined( 'PHPUNIT_COMPOSER_INSTALL' ) ? 0 : self::DEFAULT_ESCROW_DAYS;
		} else {
			$days = (int) $raw;
		}

		return (int) apply_filters( 'akb_rewards_accrual_escrow_days', max( 0, $days ) );
	}

	/**
	 * Compra mínima, en pesos pagados con dinero, para el bono de bienvenida y
	 * el premio a quien invitó.
	 *
	 * Política de negocio: Sin compra mínima para el bono de bienvenida.
	 * El freno contra el farmeo lo pone el carrito mínimo del canje: el bono
	 * sólo se gasta dentro de una compra estándar completa.
	 * Con 0 basta con que haya dinero: una compra pagada entera con puntos nunca cuenta.
	 *
	 * @return int
	 */
	public static function first_purchase_min(): int {
		return max( 0, (int) get_option( 'akb_rewards_first_purchase_min', 0 ) );
	}

	/**
	 * Con qué señales se reconoce a la misma persona en una compra anterior.
	 *
	 * Señales antifraude: RUT, teléfono y correo electrónico. Cada señal
	 * tiene su interruptor por si alguna da falsos positivos (un teléfono
	 * familiar compartido, por ejemplo); la cuenta del cliente se compara
	 * siempre.
	 *
	 * @return array<int,string> Subconjunto de rut, phone, email.
	 */
	public static function first_purchase_checks(): array {
		$senales = array(
			'rut'   => 'akb_rewards_first_purchase_by_rut',
			'phone' => 'akb_rewards_first_purchase_by_phone',
			'email' => 'akb_rewards_first_purchase_by_email',
		);

		$activas = array();
		foreach ( $senales as $senal => $opcion ) {
			if ( 'yes' === (string) get_option( $opcion, 'yes' ) ) {
				$activas[] = $senal;
			}
		}

		return $activas;
	}

	/**
	 * Carrito mínimo, en pesos de productos, para poder canjear puntos.
	 *
	 * Carrito mínimo estándar: $50.000. Es lo que permite regalar puntos
	 * (bienvenida sin mínimo, cumpleaños, reseñas, referidos) sin riesgo de
	 * farmeo: sólo se gastan dentro de una compra sustancial. 0 = sin mínimo.
	 *
	 * @return int
	 */
	public static function redeem_min_cart(): int {
		return max( 0, (int) get_option( 'akb_rewards_redeem_min_cart', 50000 ) );
	}

	/**
	 * Estado del pedido del invitado que paga el premio a quien invitó.
	 *
	 * Estado de activación de referidos: «Completado». El premio se otorga
	 * cuando la compra ya se entregó; si posteriormente se anula o reembolsa
	 * por completo, la reversa descuenta el crédito.
	 *
	 * @return string Estado sin prefijo wc-.
	 */
	public static function referral_status(): string {
		$status = (string) get_option( 'akb_rewards_referral_status', 'completed' );
		return '' !== $status ? $status : 'completed';
	}

	/**
	 * Inicio de vigencia del programa (hora local del sitio).
	 *
	 * Pedidos CREADOS antes de esta fecha no acreditan aunque cambien de
	 * estado después: sin esta compuerta, mover un pedido antiguo a «Listo
	 * para enviar» regalaría el 2% + bono retroactivo (lección del 22-08).
	 *
	 * @return string Formato Y-m-d H:i:s.
	 */
	public static function program_start(): string {
		$start = (string) get_option( 'akb_rewards_program_start', '2026-08-17 00:00:00' );
		return '' !== $start ? $start : '2026-08-17 00:00:00';
	}

	/**
	 * Bono de bienvenida en puntos. 0 = desactivado.
	 *
	 * @return int
	 */
	public static function welcome_bonus(): int {
		if ( ! self::lane_on( 'akb_rewards_welcome_enabled' ) ) {
			return 0;
		}

		/**
		 * Permite ajustar el bono por código (campañas).
		 *
		 * @param int $bonus Puntos del bono.
		 */
		return max( 0, (int) apply_filters( 'akb_rewards_welcome_bonus', (int) get_option( 'akb_rewards_welcome_bonus', 500 ) ) );
	}

	/**
	 * «1 punto = $N» para las superficies (correo, Mi cuenta): SIEMPRE
	 * derivado de redeem_value — el «$1» escrito a mano contradecía la
	 * config en la misma frase.
	 *
	 * @return string
	 */
	public static function redeem_label(): string {
		return self::points_label( 1 );
	}

	/**
	 * Cuánto valen N puntos, en pesos y ya formateado («$480»).
	 *
	 * La equivalencia es el gancho: «480 puntos» obliga al cliente a hacer una
	 * cuenta que no tiene por qué saber hacer, «= $480» se entiende de una
	 * lectura directa en la ficha de producto. Vive aquí, junto al cambio,
	 * para que ninguna superficie lo multiplique por su cuenta: si cambia
	 * la conversión del punto, se actualizan todas a la vez.
	 *
	 * @param int $points Puntos a convertir.
	 * @return string
	 */
	public static function points_label( int $points ): string {
		return '$' . number_format_i18n( max( 0, $points ) * self::redeem_value() );
	}

	/**
	 * ¿El MOTOR propio de reseñas está despierto? Autoridad ÚNICA de la
	 * pregunta: la consultan el arranque (qué módulos cuelgan) y las siete
	 * superficies de copy. Con dos autoridades, el filtro documentado sólo lo
	 * obedecía una y el arranque decidía por su cuenta con un class_exists
	 * crudo.
	 *
	 * OJO: el arranque la llama en `plugins_loaded`, así que un filtro
	 * registrado más tarde no cambia qué módulos cuelgan — sólo el copy.
	 *
	 * @return bool
	 */
	public static function reviews_engine_on(): bool {
		/**
		 * Permite forzar el estado del motor de reseñas (pruebas, cutover).
		 *
		 * @param bool $on Despierto si CusRev no está activo.
		 */
		return (bool) apply_filters( 'akb_rewards_reviews_engine_on', ! class_exists( '\\CR_Reviews' ) );
	}

	/**
	 * ¿El carril de reseñas-con-puntos está VIVO? Exige monto > 0 y que el
	 * motor propio esté despierto (duerme mientras CusRev viva): ninguna
	 * superficie puede prometer un premio que ningún código puede pagar.
	 *
	 * @return bool
	 */
	public static function reviews_lane_on(): bool {
		return self::review_points() > 0 && self::reviews_engine_on();
	}

	/**
	 * Mínimo de puntos por canje (patrón estándar de fidelización).
	 * 0 = sin mínimo. Vive aquí para que el motor, Mi cuenta y la
	 * página pública obedezcan al unísono.
	 *
	 * @return int
	 */
	public static function redeem_min_points(): int {
		return max( 0, (int) get_option( 'akb_rewards_redeem_min_points', 100 ) );
	}

	/**
	 * Porcentaje MÁXIMO del pedido que se puede pagar con puntos.
	 *
	 * Sin tope, el canje podría cubrir el 100 % de las líneas: un cliente con
	 * saldo se llevaría productos sin flujo de caja. El tope porcentual
	 * protege los márgenes operativos de la tienda.
	 *
	 * 0 = sin tope (comportamiento anterior). Se acota a 100 porque más no
	 * significa nada: el canje jamás puede superar las líneas del carrito.
	 *
	 * @return int Porcentaje entre 0 y 100.
	 */
	public static function redeem_max_pct(): int {
		$pct = (int) get_option( 'akb_rewards_redeem_max_pct', self::DEFAULT_REDEEM_MAX_PCT );

		return max( 0, min( 100, $pct ) );
	}

	/**
	 * Tope del canje en PESOS por pedido. 0 = sin tope en pesos.
	 *
	 * Convive con el porcentual y rige el más restrictivo de los dos. Un tope
	 * nominal en pesos («$5.000 máximo») evita que el comprador tenga que
	 * calcular porcentajes mentales para saber cuánto puede descontar.
	 *
	 * @return int
	 */
	public static function redeem_max_amount(): int {
		return max( 0, (int) get_option( 'akb_rewards_redeem_max_amount', self::DEFAULT_REDEEM_MAX_AMOUNT ) );
	}

	/**
	 * Pesos descontados por punto canjeado (valor facial, IVA incluido).
	 *
	 * ENTERO: la tienda cobra en CLP, que no tiene centavos, y la pantalla de
	 * ajustes declara el campo como int. Devolverlo float sostenía una rama de
	 * dos decimales en redeem_label() que ningún valor guardable alcanzaba.
	 *
	 * @return int
	 */
	public static function redeem_value(): int {
		$value = (int) get_option( 'akb_rewards_redeem_value', 1 );
		return $value > 0 ? $value : 1;
	}

	/**
	 * Referidos: el interruptor y el monto del carril «invita a un amigo».
	 * Vive acá como los demás carriles — Referrals leía sus cuatro ajustes con
	 * get_option crudo y se saltaba la fuente única del programa.
	 *
	 * @return int
	 */
	public static function referral_points(): int {
		if ( ! self::lane_on( 'akb_rewards_referral_enabled' ) ) {
			return 0;
		}
		return max( 0, (int) get_option( 'akb_rewards_referral_points', 500 ) );
	}

	/**
	 * Días que la visita con ?ref= se recuerda antes de crear la cuenta.
	 *
	 * @return int
	 */
	public static function referral_cookie_days(): int {
		return max( 1, min( 90, (int) get_option( 'akb_rewards_referral_cookie_days', 30 ) ) );
	}

	/**
	 * Tope de premios por padrino EN LA HISTORIA. 0 = sin tope.
	 *
	 * @return int
	 */
	public static function referral_max_per_user(): int {
		return max( 0, (int) get_option( 'akb_rewards_referral_max_per_user', 0 ) );
	}

	/**
	 * Puntos por reseña con foto APROBADA. 0 = programa apagado.
	 *
	 * La reseña con foto aprobada otorga PUNTOS (no cupones de un solo uso),
	 * acreditados tras su moderación exitosa (500 puntos por defecto).
	 *
	 * @return int
	 */
	public static function review_points(): int {
		if ( ! self::lane_on( 'akb_rewards_reviews_enabled' ) ) {
			return 0;
		}
		return max( 0, (int) get_option( 'akb_rewards_review_points', 500 ) );
	}

	/**
	 * Puntos por cumpleaños (una vez al año). 0 = apagado.
	 *
	 * Puntos por cumpleaños: 1.000 de fábrica (superior al bono de bienvenida
	 * para premiar lealtad anual).
	 *
	 * @return int
	 */
	public static function birthday_points(): int {
		if ( ! self::lane_on( 'akb_rewards_birthday_enabled' ) ) {
			return 0;
		}
		return max( 0, (int) get_option( 'akb_rewards_birthday_points', 1000 ) );
	}

	/**
	 * Largo mínimo del texto para que una reseña sea elegible del carrusel.
	 *
	 * @return int
	 */
	public static function carousel_min_chars(): int {
		return max( 1, (int) get_option( 'akb_rewards_carousel_min_chars', 40 ) );
	}

	/**
	 * Máximo de tarjetas pintadas en el carrusel.
	 *
	 * @return int
	 */
	public static function carousel_cards(): int {
		return max( 1, min( 20, (int) get_option( 'akb_rewards_carousel_cards', 8 ) ) );
	}

	/**
	 * Tipo de reseñas elegido en el panel; la portada admite ambas por defecto.
	 *
	 * @return string mixed|photos|text.
	 */
	public static function carousel_media(): string {
		$mode = (string) get_option( 'akb_rewards_carousel_media', 'mixed' );
		return in_array( $mode, array( 'mixed', 'photos', 'text' ), true ) ? $mode : 'mixed';
	}

	/** Permite mostrar el aviso de puntos en el carrusel sin cambiar el de las fichas. */
	public static function carousel_disclosure_on(): bool {
		return 'yes' === (string) get_option( 'akb_rewards_carousel_disclosure_enabled', 'no' );
	}

	/**
	 * ¿Se pinta el aviso de incentivo en las reseñas premiadas?
	 *
	 * Estado del aviso de incentivo: desactivado por defecto. Permite activar
	 * el aviso de divulgación con un clic en el panel administrativo según
	 * las pautas de datos estructurados de motores de búsqueda.
	 *
	 * @return bool
	 */
	public static function disclosure_on(): bool {
		return 'yes' === (string) get_option( 'akb_rewards_disclosure_enabled', 'no' );
	}

	/**
	 * Título de las reseñas: el del carrusel de la portada y, en el tema, el de
	 * la sección de reseñas de cada ficha (regla F: configurable). En el panel,
	 * «Título de las reseñas (portada y fichas)».
	 *
	 * @return string
	 */
	public static function carousel_title(): string {
		$title = (string) get_option( 'akb_rewards_carousel_title', 'Voces del Distrito' );
		return '' !== $title ? $title : 'Voces del Distrito';
	}

	/**
	 * Cada cuántas reseñas calificadas se paga el premio. 1 = cada reseña.
	 *
	 * Frecuencia de acreditación: configurable para otorgar puntos en cada
	 * reseña o cada N reseñas aprobadas.
	 *
	 * @return int
	 */
	public static function review_every_n(): int {
		return max( 1, (int) get_option( 'akb_rewards_review_every_n', 1 ) );
	}

	/**
	 * Máximo de premios de reseña por cliente EN LA HISTORIA. 0 = sin tope.
	 *
	 * @return int
	 */
	public static function review_max_per_user(): int {
		return max( 0, (int) get_option( 'akb_rewards_review_max_per_user', 0 ) );
	}

	/**
	 * Mínimo de reseñas elegibles para que el carrusel del home aparezca.
	 *
	 * Decisión del dueño: «cuando sobrepase las 7» → 8 por defecto.
	 *
	 * @return int
	 */
	public static function carousel_min(): int {
		return max( 1, (int) get_option( 'akb_rewards_carousel_min', 8 ) );
	}

	/**
	 * ¿Avisar al dueño cuando algo del programa necesita revisión?
	 *
	 * @return bool
	 */
	public static function alerts_on(): bool {
		return 'yes' === (string) get_option( 'akb_rewards_alerts_enabled', 'yes' );
	}

	/**
	 * Destinatarios de las alertas: los del panel o, si está vacío, el correo
	 * de administración del sitio.
	 *
	 * @return array<int,string>
	 */
	public static function alerts_recipients(): array {
		$lista = self::email_list( (string) get_option( 'akb_rewards_alerts_email', '' ) );

		return array() !== $lista ? $lista : self::email_list( (string) get_option( 'admin_email', '' ) );
	}

	/**
	 * Correos válidos de un texto separado por comas, puntos y comas o espacios.
	 *
	 * La MISMA lectura para guardar y para enviar: lo que el panel acepta es
	 * exactamente lo que recibe el aviso.
	 *
	 * @param string            $texto       Lo escrito.
	 * @param array<int,string> $descartados Por referencia: lo que no era un correo.
	 * @return array<int,string> Correos válidos, sin repetir, en su orden.
	 */
	public static function email_list( string $texto, array &$descartados = array() ): array {
		$validos = array();

		foreach ( (array) preg_split( '/[\s,;]+/', $texto, -1, PREG_SPLIT_NO_EMPTY ) as $pieza ) {
			$correo = sanitize_email( (string) $pieza );
			if ( '' === $correo || false === strpos( $correo, '@' ) ) {
				$descartados[] = (string) $pieza;
				continue;
			}
			if ( ! in_array( $correo, $validos, true ) ) {
				$validos[] = $correo;
			}
		}

		return $validos;
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/OrderStates.php
================================================================

<?php
/**
 * OrderStates — el vocabulario de estados de pedido que comparte el programa.
 *
 * Existe porque el conjunto «pedido caído» vivía triplicado como literal en
 * tres módulos que TIENEN que coincidir: si la tienda estrena un estado de
 * anulación y sólo se actualizan dos copias, un pedido muerto revierte los
 * puntos pero no repone el canje (o al revés), y la invitación a reseñar sale
 * igual. Un solo literal, tres consumidores.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.1
 */
final class OrderStates {

	/**
	 * Pedido CAÍDO (sin prefijo wc-): la venta no existe. Revierte la
	 * acreditación, repone el canje restante y cancela la invitación a
	 * reseñar.
	 *
	 * @var array<int,string>
	 */
	public const DOWN = array( 'cancelled', 'refunded', 'failed', 'trash' );

	/**
	 * Pedido VIVO: acá se reintenta una captura de canje pendiente y se
	 * re-cobra la deuda del revival.
	 *
	 * @var array<int,string>
	 */
	public const ALIVE = array( 'pending', 'on-hold', 'processing', 'shipping-progress', 'completed' );

	/**
	 * Pedido PAGADO: una compra de verdad. Lo comparten «¿ya compró antes?»
	 * (FirstPurchase) y la invitación a reseñar, que sale mientras el pedido
	 * siga pagado aunque haya avanzado más allá del estado disparador.
	 *
	 * @var array<int,string>
	 */
	public const PAID = array( 'processing', 'shipping-progress', 'completed' );

	/**
	 * ¿`$status` es el estado pagado `$ref`, o uno posterior?
	 *
	 * El orden es el de PAID: procesando → listo para enviar → completado.
	 * Existe porque la acreditación no puede depender de pisar EXACTAMENTE el
	 * estado que fija el panel: un pedido que salta directo a «Completado»
	 * —pago manual, retiro en tienda, o el dueño moviéndolo a mano— pasaba de
	 * largo y el cliente no veía sus puntos nunca. Cambiar el disparador en el
	 * panel dejaba el mismo hueco con los pedidos ya en curso.
	 *
	 * Acreditar de más no es un riesgo: `maybe_award()` es idempotente bajo
	 * candado de pedido y un pedido caído revierte entero.
	 *
	 * Con un `$ref` fuera de PAID (el dueño puede elegir «En espera») no hay
	 * orden que seguir: sólo coincide el propio estado.
	 *
	 * @param string $status Estado al que ENTRA el pedido, sin prefijo wc-.
	 * @param string $ref    Estado de referencia, sin prefijo wc-.
	 * @return bool
	 */
	public static function is_at_or_after( string $status, string $ref ): bool {
		$desde = array_search( $ref, self::PAID, true );
		$hasta = array_search( $status, self::PAID, true );

		if ( false === $desde || false === $hasta ) {
			return $status === $ref;
		}

		return $hasta >= $desde;
	}

	/**
	 * ¿Este estado es de pedido caído?
	 *
	 * @param string $status Estado sin prefijo wc-.
	 * @return bool
	 */
	public static function is_down( string $status ): bool {
		return in_array( $status, self::DOWN, true );
	}

	/**
	 * ¿Este estado es de pedido vivo?
	 *
	 * @param string $status Estado sin prefijo wc-.
	 * @return bool
	 */
	public static function is_alive( string $status ): bool {
		return in_array( $status, self::ALIVE, true );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/Privacy.php
================================================================

<?php
/**
 * Privacy — qué pasa con los puntos cuando una CUENTA se borra o pide sus
 * datos.
 *
 * Preservación de trazabilidad contable en el ledger al eliminar usuarios:
 * congela la identidad en la fila del asiento para mantener la inteligibilidad contable.
 * Integra con el sistema estándar de privacidad de WordPress (Herramientas -> Exportar/Borrar datos personales).
 *
 * El exportador entrega saldo, historial, cumpleaños, código de padrino,
 * vínculo de invitación y fotos adjuntas a las reseñas de la persona.
 *
 * El borrador elimina lo que sólo describe a la persona —el
 * día de su cumpleaños, su código de padrino y quién la invitó— y RETIENE,
 * diciéndolo, dos cosas distintas: el historial de puntos, que es registro
 * contable (respaldo de descuentos ya cobrados), y los sellos de «ya se pagó»
 * del bono, del cumpleaños y del premio de referido, que no llevan identidad y
 * sin los cuales el mismo premio se volvería a pagar.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

use Akibara\Rewards\Points\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class Privacy {

	/**
	 * Cuelga los hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'delete_user', array( $this, 'stamp_identity' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Congela la identidad del cliente en sus filas del ledger ANTES de que
	 * WordPress lo borre (delete_user corre antes del borrado real).
	 *
	 * @param int $user_id Usuario a borrar.
	 * @return void
	 */
	public function stamp_identity( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( false === $user || '' === (string) $user->user_email ) {
			return;
		}

		// Si el usuario solicitó supresión formal de datos, no se estampa el correo en el historial.
		$marca = '' !== (string) get_user_meta( (int) $user_id, self::META_SUPRESION, true )
			? ' [cuenta borrada a petición de su titular]'
			: ' [cuenta borrada: ' . (string) $user->user_email . ']';

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia; corre una vez, al borrar la cuenta.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::ledger_table() . ' SET note = SUBSTRING(CONCAT(note, %s), 1, 255) WHERE user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia.
				$marca,
				(int) $user_id
			)
		);
	}

	/**
	 * Registra el exportador del flujo de privacidad del core.
	 *
	 * @param array<string,array<string,mixed>> $exporters Exportadores.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( $exporters ): array {
		$exporters['akibara-rewards'] = array(
			'exporter_friendly_name' => 'Puntos Akibara',
			'callback'               => array( $this, 'export' ),
		);
		return (array) $exporters;
	}

	/**
	 * Registra el borrador del flujo de privacidad del core.
	 *
	 * @param array<string,array<string,mixed>> $erasers Borradores.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_eraser( $erasers ): array {
		$erasers['akibara-rewards'] = array(
			'eraser_friendly_name' => 'Puntos Akibara',
			'callback'             => array( $this, 'erase' ),
		);
		return (array) $erasers;
	}

	/**
	 * Movimientos por página del exportador. El core vuelve a llamar con
	 * $page+1 mientras `done` sea false.
	 */
	private const PER_PAGE = 200;

	public const CUMPLEANOS = '_akb_rewards_birthday_md';

	/**
	 * Sello de «este titular pidió la supresión de sus datos» (fecha Unix).
	 *
	 * No describe a la persona: existe para que `stamp_identity()` cumpla lo
	 * que el informe de borrado prometió si después se elimina la cuenta.
	 */
	public const META_SUPRESION = '_akb_rewards_privacy_erased';

	/**
	 * Lo que el programa guarda SOBRE la persona, dicho como lo diría ella.
	 *
	 * Lista común al exportador y al borrador para mantener la cobertura de
	 * datos personales en ambos flujos.
	 *
	 * @var array<string,string>
	 */
	private const CAMPOS_PERSONALES = array(
		self::CUMPLEANOS             => 'Día de cumpleaños registrado',
		'_akb_rewards_ref_code'      => 'Tu código para invitar amigos',
	);

	/**
	 * Sellos de «ya se pagó»: se RETIENEN y se declaran.
	 *
	 * No llevan identidad —son un año, un id de pedido o un sí— y son lo único
	 * que impide que el mismo bono, el mismo regalo de cumpleaños o el mismo
	 * premio de referido se vuelvan a pagar. Borrarlos con la solicitud de
	 * borrado convertiría el derecho del cliente en una palanca de fraude.
	 *
	 * @var array<int,string>
	 */
	private const SELLOS = array(
		'_akb_rewards_welcome_paid',
		'_akb_rewards_birthday_paid_year',
		'_akb_rewards_referral_order',
	);

	/**
	 * Exporta saldo e historial de puntos del correo solicitado.
	 *
	 * PAGINADO de verdad: cortar en 200 y declarar `done = true` entregaba un
	 * historial incompleto en silencio, que es justo lo que un derecho de
	 * acceso no admite. El saldo va sólo en la primera página.
	 *
	 * @param string $email Correo de la solicitud.
	 * @param int    $page  Página (1-based) que pide el core.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( $email, $page = 1 ): array {
		$page = max( 1, (int) $page );
		$user = get_user_by( 'email', (string) $email );
		if ( false === $user ) {
			// Compradores invitados pueden tener fotos asociadas a sus reseñas aunque no tengan cuenta activa.
			return array(
				'data' => 1 === $page ? self::items_de_fotos( (string) $email, 0 ) : array(),
				'done' => true,
			);
		}

		global $wpdb;

		$offset = ( $page - 1 ) * self::PER_PAGE;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT created_at, delta, note FROM ' . Schema::ledger_table() . ' WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia.
				(int) $user->ID,
				self::PER_PAGE,
				$offset
			),
			ARRAY_A
		);

		$data = array();

		if ( 1 === $page ) {
			$perfil = array(
				array(
					'name'  => 'Saldo de puntos',
					'value' => (string) Ledger::balance( (int) $user->ID ),
				),
			);

			// El resto de lo que el programa guarda SOBRE la persona. Se
			// nombra en lenguaje de persona, no con la meta_key: quien pide
			// sus datos no sabe qué es `_akb_rewards_ref_code`.
			foreach ( self::CAMPOS_PERSONALES as $meta => $etiqueta ) {
				$valor = (string) get_user_meta( (int) $user->ID, $meta, true );
				if ( '' === $valor ) {
					continue;
				}

				$perfil[] = array(
					'name'  => $etiqueta,
					'value' => self::CUMPLEANOS === $meta ? self::cumpleanos_legible( $valor ) : $valor,
				);
			}

			// A quién invitó esta persona no se lista: son terceros, y su
			// identidad no es dato de quien pregunta. Sí va el vínculo
			// inverso —quién la invitó a ella—, que sí la describe.
			$padrino = (int) get_user_meta( (int) $user->ID, '_akb_rewards_referred_by', true );
			if ( $padrino > 0 ) {
				$perfil[] = array(
					'name'  => 'Llegó invitado por',
					'value' => self::nombre_de( $padrino ),
				);
			}

			$data[] = array(
				'group_id'    => 'akibara-rewards',
				'group_label' => 'Puntos Akibara',
				'item_id'     => 'akb-rewards-balance',
				'data'        => $perfil,
			);

			$data = array_merge( $data, self::items_de_fotos( (string) $email, (int) $user->ID ) );
		}

		foreach ( $rows as $i => $row ) {
			$data[] = array(
				'group_id'    => 'akibara-rewards',
				'group_label' => 'Puntos Akibara',
				'item_id'     => 'akb-rewards-mov-' . ( $offset + (int) $i ),
				'data'        => array(
					array(
						'name'  => 'Movimiento',
						'value' => sprintf( '%s: %+d — %s', (string) $row['created_at'], (int) $row['delta'], (string) $row['note'] ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Borra lo que sólo describe a la persona y declara lo que se retiene.
	 *
	 * @param string $email Correo de la solicitud.
	 * @param int    $page  Página.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );

		$user = get_user_by( 'email', (string) $email );

		// Las fotos de las reseñas se declaran SIEMPRE, con cuenta o sin ella:
		// el núcleo anonimiza el comentario pero no toca sus adjuntos, y una
		// respuesta que calla lo que se queda es la que este fichero vino a
		// retirar. Si se borran o no es una decisión del dueño (la foto es
		// requisito de la reseña publicada); mientras tanto se dice la verdad
		// y se da el camino.
		$fotos = count( self::fotos_de( (string) $email, false === $user ? 0 : (int) $user->ID ) );
		$aviso_fotos = $fotos > 0
			? sprintf(
				1 === $fotos
					? 'Se conserva %d foto publicada junto a una reseña tuya: si quieres que la retiremos, escríbenos a contacto@akibara.cl.'
					: 'Se conservan %d fotos publicadas junto a tus reseñas: si quieres que las retiremos, escríbenos a contacto@akibara.cl.',
				$fotos
			)
			: '';

		if ( false === $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => '' !== $aviso_fotos,
				'messages'       => '' !== $aviso_fotos ? array( $aviso_fotos ) : array(),
				'done'           => true,
			);
		}

		// El sello va ANTES de borrar nada: si después se elimina la cuenta,
		// `stamp_identity()` lo lee para no estampar el correo en el historial.
		update_user_meta( (int) $user->ID, self::META_SUPRESION, time() );

		$borrados = array();
		foreach ( self::CAMPOS_PERSONALES as $meta => $etiqueta ) {
			if ( '' !== (string) get_user_meta( (int) $user->ID, $meta, true ) ) {
				delete_user_meta( (int) $user->ID, $meta );
				$borrados[] = $etiqueta;
			}
		}

		// El vínculo con quien la invitó describe una relación entre dos
		// personas. Se va; el SELLO del premio (`_akb_rewards_referral_order`)
		// se queda, y con él la imposibilidad de volver a cobrarlo.
		if ( '' !== (string) get_user_meta( (int) $user->ID, '_akb_rewards_referred_by', true ) ) {
			delete_user_meta( (int) $user->ID, '_akb_rewards_referred_by' );
			$borrados[] = 'quién la invitó';
		}

		$mensajes = array();

		if ( array() !== $borrados ) {
			$mensajes[] = 'Se borraron de Puntos Akibara: ' . implode( ', ', $borrados ) . '.';
		}

		$retenido = array();

		if ( array() !== $this->has_rows( (int) $user->ID ) ) {
			$retenido[] = 'el historial de puntos, que es registro contable (respaldo de descuentos ya aplicados) y queda sin tu correo: si la cuenta se elimina, tus movimientos no llevan ningún dato que te identifique';
		}

		foreach ( self::SELLOS as $meta ) {
			if ( '' !== (string) get_user_meta( (int) $user->ID, $meta, true ) ) {
				$retenido[] = 'la marca de que ya se pagó un premio del programa (sin ningún dato de identidad), para que no se pague dos veces';
				break;
			}
		}

		if ( array() !== $retenido ) {
			$mensajes[] = 'Se conservan ' . implode( ', y ', $retenido ) . '.';
		}

		if ( '' !== $aviso_fotos ) {
			$mensajes[] = $aviso_fotos;
		}

		return array(
			'items_removed'  => array() !== $borrados,
			'items_retained' => array() !== $retenido || '' !== $aviso_fotos,
			'messages'       => $mensajes,
			'done'           => true,
		);
	}

	/**
	 * «03-14» → «14 de marzo». El formato interno no le dice nada a nadie.
	 *
	 * @param string $md Mes-día guardado.
	 * @return string
	 */
	private static function cumpleanos_legible( string $md ): string {
		if ( ! preg_match( '/^(\d{2})-(\d{2})$/', $md, $m ) ) {
			return $md;
		}

		$meses = array( 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
		$mes   = (int) $m[1];

		return isset( $meses[ $mes - 1 ] ) ? sprintf( '%d de %s', (int) $m[2], $meses[ $mes - 1 ] ) : $md;
	}

	/**
	 * Nombre público de la persona que invitó, sin filtrar su correo.
	 *
	 * @param int $user_id Padrino.
	 * @return string
	 */
	private static function nombre_de( int $user_id ): string {
		$padrino = get_userdata( $user_id );

		return false === $padrino ? 'otro cliente' : (string) $padrino->display_name;
	}

	/**
	 * Las fotos de reseña, ya con la forma que pide el exportador del núcleo.
	 *
	 * @param string $email   Correo de la solicitud.
	 * @param int    $user_id Cuenta, si la hay (0 = comprador invitado).
	 * @return array<int,array<string,mixed>>
	 */
	private static function items_de_fotos( string $email, int $user_id ): array {
		$items = array();

		foreach ( self::fotos_de( $email, $user_id ) as $i => $url ) {
			$items[] = array(
				'group_id'    => 'akibara-rewards',
				'group_label' => 'Puntos Akibara',
				'item_id'     => 'akb-rewards-foto-' . $i,
				'data'        => array(
					array(
						'name'  => 'Foto que subiste con una reseña',
						'value' => $url,
					),
				),
			);
		}

		return $items;
	}

	/**
	 * URLs de las fotos que esta persona subió con sus reseñas.
	 *
	 * Complementa la exportación de comentarios del core con las fotos de las
	 * reseñas asociadas al correo solicitado.
	 *
	 * @param string $email   Correo de la solicitud.
	 * @param int    $user_id Cuenta, si la hay.
	 * @return array<int,string>
	 */
	private static function fotos_de( string $email, int $user_id ): array {
		$comentarios = get_comments(
			array(
				'author_email' => $email,
				// 'status' => 'any' incluye cualquier estado (aprobado, pendiente, spam, papelera).
				'status'       => 'any',
				'number'       => 200,
				'fields'       => 'ids',
			)
		);

		if ( $user_id > 0 ) {
			$comentarios = array_unique(
				array_merge(
					(array) $comentarios,
					(array) get_comments(
						array(
							'user_id' => $user_id,
							'status'  => 'any',
							'number'  => 200,
							'fields'  => 'ids',
						)
					)
				)
			);
		}

		$urls = array();
		foreach ( (array) $comentarios as $comment_id ) {
			foreach ( (array) get_comment_meta( (int) $comment_id, \Akibara\Rewards\Reviews\Media::META_LOCAL_IMAGE ) as $attachment_id ) {
				$url = wp_get_attachment_url( (int) $attachment_id );
				if ( is_string( $url ) && '' !== $url ) {
					$urls[] = $url;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * ¿El usuario tiene filas en el ledger?
	 *
	 * @param int $user_id Usuario.
	 * @return array<int,string> Ids encontrados (vacío = ninguno).
	 */
	private function has_rows( int $user_id ): array {
		global $wpdb;

		return (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . Schema::ledger_table() . ' WHERE user_id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia.
				$user_id
			)
		);
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/PrivatePage.php
================================================================

<?php
/**
 * PrivatePage — una página con el estado de UNA persona no se guarda en caché.
 *
 * Las dos páginas por enlace del plugin (el formulario de reseña y las
 * preferencias de correo) se defendían sólo con `nocache_headers()`, que son
 * cabeceras del origen y en esta tienda no deciden nada:
 *
 *   - LiteSpeed resuelve con sus propios ajustes y sólo se rinde ante
 *     `DONOTCACHEPAGE` o su hook `litespeed_control_set_nocache`.
 *   - Las reglas de caché HTML para invitados en CDN pueden ignorar `no-store`
 *     en rutas de contenido general.
 *
 * Para asegurar no-cache estricto, la defensa se basa en la ruta: estas páginas
 * se sirven bajo la URL canónica de Mi cuenta (`/mi-cuenta/`), que está excluida
 * permanentemente de la caché perimetral (DYNAMIC).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.9
 */
final class PrivatePage {

	/**
	 * URL base bajo la que el borde no cachea: la página de Mi cuenta.
	 *
	 * @return string Con barra final.
	 */
	public static function base_url(): string {
		$cuenta = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';

		return '' !== $cuenta ? $cuenta : home_url( '/mi-cuenta/' );
	}

	/**
	 * Apaga toda caché de página para la petición en curso.
	 *
	 * @param string $motivo Texto que LiteSpeed anota en su cabecera de depuración.
	 * @return void
	 */
	public static function no_cache( string $motivo ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante estándar de facto de los plugins de caché: prefijarla la dejaría inerte.
		}

		nocache_headers();

		do_action( 'litespeed_control_set_nocache', $motivo ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed Cache: el nombre lo fija ese plugin.
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/QueueHooks.php
================================================================

<?php
/**
 * QueueHooks — los hooks de Action Scheduler de este plugin, para quien vigile la cola.
 *
 * Por qué existe
 * --------------
 * La tarjeta «Alertas del Sistema» de akibara-business vigila la cola de
 * Action Scheduler (`Utilities\QueueHealth`, 1.116.31), pero sólo conoce sus
 * propios hooks. Este plugin agenda once —cuatro diarios: caducidad,
 * cumpleaños, limpieza de enlaces de reseña y el barrido de acreditación— y
 * sin esto podían dejar de correr con la tarjeta en verde (revisión
 * independiente de la ronda 7, M4). Se suman por el filtro
 * `akibara_queue_health_hooks`; si business no está, el filtro no lo lee nadie
 * y no pasa nada.
 *
 * La lista se mantiene a mano y `uninstall.php` la repite (no puede cargar
 * clases); `LaColaQuedaLimpiaAlDesinstalarTest` y `QueueHooksTest` vigilan que
 * las tres fuentes —código, desinstalador y esta lista— coincidan.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.15
 */
final class QueueHooks {

	public const ALL = array(
		'akb_rewards_expiry_daily',
		'akb_rewards_expiry_batch',
		'akb_rewards_expiry_warn_async',
		'akb_rewards_birthday_daily',
		'akb_rewards_redemption_retry',
		'akb_rewards_accrual_sweep_daily',
		'akb_rewards_release_accrual_escrow',
		'akb_rewards_send_review_invite',
		'akb_rewards_order_email_retry',
		'akb_rewards_review_email_retry',
		'akb_rewards_referral_email_retry',
		'akb_rewards_review_tokens_cleanup',
		'akb_rewards_home_cache_retry',
	);

	/**
	 * @return void
	 */
	public function register(): void {
		add_filter( 'akibara_queue_health_hooks', array( $this, 'add_hooks' ) );
	}

	/**
	 * @param mixed $hooks Los que ya vigila business.
	 * @return array<int, string>
	 */
	public function add_hooks( $hooks ): array {
		$previos = is_array( $hooks ) ? array_map( 'strval', $hooks ) : array();

		return array_values( array_unique( array_merge( $previos, self::ALL ) ) );
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/Schema.php
================================================================

<?php
/**
 * Schema — tabla {prefix}akb_rewards_ledger.
 *
 * Mismo patrón que BackInStock\Install de akibara-business: activation hook +
 * guard de versión en cada boot (deploys por rsync no reactivan el plugin) y
 * sellado de la versión SOLO si la tabla quedó realmente creada — dbDelta
 * falla en silencio y sellar a ciegas envenena el módulo para siempre.
 *
 * Decisiones de schema:
 *   - `delta` FIRMADO + `balance_after`: el ledger de SPAR guardaba puntos sin
 *     signo y el sentido vivía en `type` add/remove; con el delta firmado la
 *     suma de la columna ES el saldo, y `balance_after` deja auditable cada
 *     movimiento sin reconstruir la historia.
 *   - `ref_type`/`ref_id`: referencia polimórfica (order, review, admin,
 *     migration, birthday) para reversas exactas por pedido.
 *   - `event_key` UNIQUE y NULLABLE: la red de seguridad FINAL contra la
 *     doble acreditación. Los movimientos de un solo disparo (acreditación,
 *     bono, captura de canje) llevan clave ('award_100'); un duplicado choca
 *     con el índice y se trata como ya-aplicado. Los ajustes que pueden
 *     repetirse legítimamente (reembolsos parciales) van con NULL — MySQL
 *     permite múltiples NULL en un índice UNIQUE.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

use Akibara\Rewards\Points\AccountRewards;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Schema {

	public const DB_VERSION = '1.3.0';
	public const OPTION_KEY = 'akb_rewards_db_version';

	/**
	 * Nombre completo de la tabla del ledger (con prefix del sitio).
	 *
	 * @return string
	 */
	public static function ledger_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_rewards_ledger';
	}

	/**
	 * Tabla de tokens del formulario de reseña por invitación (Fase 2b).
	 *
	 * @return string
	 */
	public static function review_forms_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_rewards_review_forms';
	}

	/**
	 * Higiene: borra tokens de invitación que vencieron hace más de 6 meses y
	 * nunca se usaron. Los usados se conservan (auditoría de qué reseña vino
	 * de qué pedido). La llama el cron diario; LIMIT para no bloquear.
	 *
	 * @return void
	 */
	public static function purge_stale_review_tokens(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia, mantenimiento.
		$wpdb->query( 'DELETE FROM ' . self::review_forms_table() . " WHERE used_at IS NULL AND expires_at < DATE_SUB(NOW(), INTERVAL 6 MONTH) LIMIT 500" );
	}

	/**
	 * Cuelga la migración en activación y en boot.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_activation_hook( AKIBARA_REWARDS_FILE, array( self::class, 'maybe_install' ) );
		add_action( 'init', array( self::class, 'maybe_install' ), 5 );
	}

	/**
	 * Crea o migra la tabla si la versión guardada difiere. Idempotente.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( get_option( self::OPTION_KEY ) === self::DB_VERSION ) {
			return;
		}

		self::install();

		if ( ! self::schema_ready() ) {
			global $wpdb;
			Log::error(
				sprintf(
					'Migración %s falló: %s no existe tras dbDelta; no se sella %s. last_error=%s',
					self::DB_VERSION,
					self::ledger_table(),
					self::OPTION_KEY,
					(string) $wpdb->last_error
				)
			);
			return;
		}

		self::migrate_option_autoload();
		self::migrate_award_status();

		// autoload = true a propósito (ver migrate_option_autoload): esta
		// misma option se lee en CADA petición, en el guard de arriba.
		update_option( self::OPTION_KEY, self::DB_VERSION, true );
	}

	/**
	 * Options del programa que DEBEN viajar en `alloptions`.
	 *
	 * Son los 36 montos/interruptores de la pantalla de ajustes más las dos
	 * marcas de arranque. Todas se leen en el camino del cliente (ficha, Mi
	 * cuenta, carrito, correos) y son escalares diminutos: con autoload
	 * apagado, un render de Mi cuenta pagaba 17 SELECT sueltos y cada ficha
	 * de producto 6. Autoload existe exactamente para esto.
	 *
	 * @return array<int,string>
	 */
	public static function autoloaded_options(): array {
		return array_merge(
			SettingsPage::option_names(),
			array( self::OPTION_KEY, AccountRewards::FLUSH_OPTION )
		);
	}

	/**
	 * Pone en autoload las options del programa que ya existan.
	 *
	 * Cambiar el flag en la escritura NO reescribe las filas guardadas: sin
	 * esta pasada, una instalación viva se quedaba con las 36 en `off` para
	 * siempre. Corre una vez por versión de esquema.
	 *
	 * @return void
	 */
	private static function migrate_option_autoload(): void {
		if ( ! function_exists( 'wp_set_option_autoload_values' ) ) {
			return; // WP < 6.4: la escritura nueva ya nace con autoload.
		}

		wp_set_option_autoload_values( array_fill_keys( self::autoloaded_options(), true ) );
	}

	/**
	 * Mueve el estado que acredita al nuevo default, UNA sola vez (1.3.0).
	 *
	 * Cambiar el default no basta: el guardado del panel escribe todas las options,
	 * por lo que se migra explícitamente el valor previo para asegurar paridad.
	 *
	 * Sólo mueve el valor VIEJO de fábrica: si el dueño eligió otro estado a
	 * mano, se respeta. Y corre una vez por versión de esquema, así que
	 * volver a poner «Listo para enviar» desde el panel es definitivo.
	 *
	 * @return void
	 */
	private static function migrate_award_status(): void {
		$guardado = get_option( 'akb_rewards_award_status', '' );

		if ( 'shipping-progress' === (string) $guardado ) {
			update_option( 'akb_rewards_award_status', Options::DEFAULT_AWARD_STATUS, true );
		}
	}

	/**
	 * Ejecuta dbDelta con el schema vigente.
	 *
	 * @return void
	 */
	private static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::ledger_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			delta INT NOT NULL,
			balance_after INT NOT NULL,
			action VARCHAR(40) NOT NULL,
			ref_type VARCHAR(20) NOT NULL DEFAULT '',
			ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			event_key VARCHAR(191) DEFAULT NULL,
			note VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY user_created (user_id, created_at),
			KEY ref (ref_type, ref_id)
		) {$charset};";

		dbDelta( $sql );

		// Tokens de invitación a reseñar (Fase 2b): un enlace por pedido,
		// calcado del mecanismo del tercero (cr_local_forms) pero con
		// caducidad y un solo uso auditables. token único; el pedido puede
		// tener más de un token en su historia (re-invitación tras reversa).
		$forms = self::review_forms_table();

		$sql_forms = "CREATE TABLE {$forms} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			token VARCHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			used_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY order_id (order_id)
		) {$charset};";

		dbDelta( $sql_forms );
	}

	/**
	 * Comprueba que la tabla exista de verdad.
	 *
	 * @return bool
	 */
	private static function schema_ready(): bool {
		global $wpdb;

		foreach ( array( self::ledger_table(), self::review_forms_table() ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return false;
			}
		}

		return true;
	}
}


================================================================
### ARCHIVO: public_html/wp-content/plugins/akibara-rewards/src/Support/SettingsPage.php
================================================================

<?php
/**
 * SettingsPage — Panel de configuración de montos y superficies del
 * programa de fidelización y reseñas.
 *
 * Una sola pantalla bajo WooCommerce, en el idioma de la tienda: cada campo
 * es una option con default (los defaults replican la config de prod de los
 * terceros que reemplazamos), guardado por admin-post con nonce+capability y
 * sanitización por tipo. Sin JS, sin frameworks: liviana.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

use Akibara\Rewards\Points\Calculator;
use Akibara\Rewards\Reviews\AdminOverview;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class SettingsPage {

	public const CAPABILITY = 'manage_woocommerce';
	public const ACTION     = 'akb_rewards_save_settings';
	public const SLUG       = 'akb-rewards-settings';

	/**
	 * Avisos de un minuto del último guardado, POR USUARIO y con payload
	 * (`[ ['tipo' => 'error'|'warning', 'texto' => '…'], … ]`), como los de
	 * AdminAdjust. La marca global anterior se comía el aviso del otro cuando
	 * dos administradores guardaban a la vez.
	 */
	public const TRANSIENT_AVISOS = 'akb_rw_avisos_';

	/**
	 * Vara del ejemplo en pesos de los avisos.
	 *
	 * Es una cifra redonda de referencia: un valor de $10.000 deja el cálculo
	 * transparente e independiente de variaciones en el catálogo.
	 */
	private const EJEMPLO_COMPRA = 10000;

	/**
	 * Ajustes que se leen dentro del HTML de CADA ficha y le dicen algo al
	 * cliente que tiene que ser verdad: cambiar uno lanza la purga TOTAL.
	 *
	 * La copia de invitado de una ficha vive en DOS sitios: LiteSpeed en el
	 * origen y Cloudflare en el borde (hasta 10 minutos; ver EdgePurge en
	 * akibara-business). En esta zona sólo `litespeed_purge_all` llega al borde:
	 * es la única llamada de LiteSpeed a Cloudflare (`purge_everything`,
	 * dentro de `Purge::_purge_all()`), y producción la tiene encendida.
	 * Su precio es alto —vacía también el CSS/JS minificado, la caché de objetos
	 * y OPcache, y enfría el borde entero—, así que se paga sólo por una promesa:
	 *
	 * - El aviso «Gana N Puntos Akibara» y todo lo que su promesa lee: con la
	 *   tasa cambiada en el panel y la ficha sin purgar, la ficha prometía una
	 *   cifra y la acreditación daba otra. Incluye los interruptores de los
	 *   carriles: con «Dar puntos por compra» apagado la promesa es cero y el
	 *   aviso no sale. La suite pinta el aviso y exige que todo lo que lee del
	 *   panel esté aquí, y no en FORMA_DE_CADA_FICHA.
	 * - El aviso de divulgación en el listado de reseñas: le dice al comprador
	 *   que la reseña ganó puntos.
	 */
	private const PROMESA_EN_CADA_FICHA = array(
		'akb_rewards_disclosure_enabled',
		'akb_rewards_pdp_notice_enabled',
		'akb_rewards_earn_enabled',
		'akb_rewards_earn_points',
		'akb_rewards_earn_amount',
		'akb_rewards_redeem_value',
		'akb_rewards_welcome_enabled',
		'akb_rewards_welcome_bonus',
		'akb_rewards_first_purchase_min',
		'akb_rewards_program_start',
	);

	/**
	 * Ajustes de presentación compartidos por las fichas. Un cambio invalida
	 * el HTML de origen mediante `litespeed_purge` con `*`, sin vaciar assets,
	 * caché de objetos ni OPcache. La portada se invalida con Carousel::flush().
	 */
	private const FORMA_DE_CADA_FICHA = array(
		'akb_rewards_schema_exclude_rewarded',
		'akb_rewards_carousel_title',
	);

	/**
	 * Catálogo de campos: option → [tipo, default, etiqueta, ayuda, sección].
	 * ÚNICA fuente de la pantalla y del guardado — añadir una option nueva es
	 * añadir UNA fila acá. Opcionales: `min`/`max` de un entero y `largo` de un
	 * texto; cada uno pone el tope en el formulario y en el guardado a la vez.
	 *
	 * @var array<string,array{t:string,d:mixed,l:string,h:string,s:string,min?:int,max?:int,largo?:int}>
	 */
	private const FIELDS = array(
		'akb_rewards_earn_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Dar puntos por compra',
			'h' => 'Enciéndelo o apágalo y guarda los ajustes: el cambio se aplica al guardar en la tienda, los correos y Mi cuenta. Los montos guardados se conservan para cuando lo vuelvas a encender.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_earn_points'              => array(
			't'   => 'int',
			'd'   => 2,
			// 10x lo vigente (2). Con el tramo de $100 eso devuelve el 20 %, que
			// sólo entra si el techo de devolución de abajo lo permite: quien
			// frena antes es la guarda de razón. Este tope es sólo la baranda
			// contra el tecleo (200, 2000).
			'max' => 20,
			'l'   => 'Puntos por tramo de compra',
			'h'   => 'Con 2 puntos por cada $100, una compra de $10.000 gana 200. El techo de devolución de más abajo manda sobre este máximo.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_earn_amount'              => array(
			't'   => 'int',
			'd'   => 100,
			// El peligro de este campo va hacia ABAJO (tramo de $1 = 200 % de
			// devolución) y lo cubre la guarda de razón, no un max. Hacia
			// arriba, lo absurdo es un tramo mayor que cualquier compra de la
			// tienda (el tomo más caro publicado son $83.000). Para apagar la
			// acumulación está el 0 y el interruptor del carril.
			'max' => 1000000,
			'l'   => 'Tamaño del tramo ($)',
			'h'   => 'Tramo en pesos de la acumulación. 0 APAGA los puntos por compra (nadie acredita).',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_redeem_min_points' => array(
			't'   => 'int',
			'd'   => 100,
			// Derivado, no inventado: 5.000 puntos a $1 son los $5.000 del tope
			// de descuento por pedido. El saldo más alto de la tienda hoy son
			// 2.658 puntos.
			'max' => 5000,
			'l'   => 'Mínimo de puntos por canje',
			'h'   => 'El cliente no puede canjear menos que esto de una vez. 0 = sin mínimo.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_redeem_max_pct' => array(
			't' => 'int',
			'd' => \Akibara\Rewards\Support\Options::DEFAULT_REDEEM_MAX_PCT,
			// Es un porcentaje: el propio formulario impide guardar fuera de
			// rango, no sólo el accessor.
			'min' => 0,
			'max' => 100,
			'l' => 'Máximo del pedido que se paga con puntos (%)',
			'h' => 'Cuánto de un pedido puede cubrir el cliente con puntos. Con 50, en una compra de $40.000 puede usar hasta $20.000 en puntos y el resto lo paga. 0 = sin tope (podría pagar el pedido entero con puntos). El despacho nunca se paga con puntos.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_redeem_max_amount' => array(
			't'   => 'int',
			'd'   => \Akibara\Rewards\Support\Options::DEFAULT_REDEEM_MAX_AMOUNT,
			'min' => 0,
			// 10x el vigente. Con el tomo promedio en $14.334, $50.000 ya paga
			// un carrito de tres tomos entero.
			'max' => 50000,
			'l'   => 'Máximo de descuento por pedido ($)',
			'h'   => 'Máximo descuento en pesos que el cliente puede canjear por compra. Si pones los dos topes, manda el más estricto. 0 = sin tope en pesos.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_redeem_min_cart'          => array(
			't'   => 'int',
			'd'   => 50000,
			'min' => 0,
			// 10x lo decidido: por encima ya casi ninguna compra de la tienda
			// podría canjear (la primera compra más alta medida fueron $227.700).
			'max' => 500000,
			'l'   => 'Carrito mínimo para canjear ($)',
			'h'   => 'El canje de puntos se activa cuando los productos del carrito suman al menos este monto (con IVA y cupones, sin despacho). Es lo que permite regalar puntos sin que se farmeen: sólo se gastan dentro de una compra de verdad. 0 = sin mínimo.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_redeem_value'             => array(
			't'   => 'int',
			'd'   => 1,
			'min' => 1,
			// 10x lo vigente. Con la acumulación de hoy (2 por cada $100) un
			// punto a $10 devuelve el 20 %: lo frena la guarda de razón salvo
			// que el techo de abajo lo permita.
			'max' => 10,
			'l'   => 'Pesos de descuento por punto canjeado',
			'h'   => '1 = un peso por punto (IVA incluido, exacto en el total). El techo de devolución de abajo manda sobre este máximo.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_max_giveback_pct'         => array(
			't'   => 'int',
			'd'   => \Akibara\Rewards\Support\Options::DEFAULT_MAX_GIVEBACK_PCT,
			'min' => 1,
			'max' => \Akibara\Rewards\Support\Options::HARD_MAX_GIVEBACK_PCT,
			'l'   => 'Techo de devolución del programa (%)',
			'h'   => 'Cuánto de cada compra puede volver al cliente en puntos, juntando los tres campos de arriba (puntos por tramo ÷ tamaño del tramo × pesos por punto). Hoy la tienda devuelve el 2 %. Un guardado que supere este techo no entra: se conserva lo que había y se explica en pesos.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_welcome_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Dar bono de bienvenida (primera compra)',
			'h' => 'Enciéndelo o apágalo y guarda los ajustes: el cambio se aplica al guardar en la tienda, los correos y Mi cuenta. Los montos guardados se conservan para cuando lo vuelvas a encender.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_welcome_bonus'            => array(
			't'   => 'int',
			'd'   => 500,
			// 10x lo vigente, y justo el máximo canjeable por pedido: un bono
			// por encima del techo promete una compra que el carrito no deja
			// pagar con puntos.
			'max' => 5000,
			'l'   => 'Bono de bienvenida',
			'h'   => 'Se paga con la PRIMERA compra de la persona, aunque «Dar puntos por compra» esté apagado. Uno por persona (ver los interruptores de abajo). Nunca por solo registrarse. 0 lo apaga.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_first_purchase_min'       => array(
			't'   => 'int',
			'd'   => 0,
			'min' => 0,
			// 10x la mediana de la primera compra medida en producción ($24.000):
			// por encima, el bono deja de ser de bienvenida.
			'max' => 240000,
			'l'   => 'Compra mínima para bono y referido ($)',
			'h'   => 'Si pones un monto, la primera compra tiene que sumar al menos eso en productos pagados con dinero (con IVA y cupones; sin despacho ni puntos) para dar el bono de bienvenida y el premio a quien invitó. 0 = sin mínimo (recomendado): basta con que no se pague entera con puntos; el freno contra el abuso lo pone el carrito mínimo para canjear.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_first_purchase_by_rut'    => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Uno por persona: mirar el RUT',
			'h' => 'Si el RUT del pedido ya aparece en una compra anterior (desde el inicio del programa), no hay bono ni premio a quien invitó, aunque sea otra cuenta.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_first_purchase_by_phone'  => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Uno por persona: mirar el teléfono',
			'h' => 'Igual, con el teléfono (se compara el número sin +56 ni espacios). Apágalo si ves falsos positivos, por ejemplo un teléfono familiar compartido.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_first_purchase_by_email'  => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Uno por persona: mirar el correo',
			'h' => 'Igual, con el correo del pedido. La cuenta del cliente se compara siempre.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_welcome_teaser_enabled'   => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Mostrar «puntos esperándote»',
			'h' => 'Promete el bono en el registro y en Mi cuenta hasta que se active con la primera compra. Nada se paga por solo registrarse.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_award_status'             => array(
			't' => 'status',
			'd' => Options::DEFAULT_AWARD_STATUS,
			'l' => 'Estado del pedido que acredita',
			'h' => 'Los puntos se pagan cuando el pedido llega a este estado o a uno posterior. «Procesando» = la pasarela confirmó el pago, así el cliente ve sus puntos apenas paga; «Listo para enviar» espera a que prepares el despacho. Si la venta se cae (cancelado, fallido o reembolsado), los puntos se retiran solos.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_accrual_escrow_days'      => array(
			't'   => 'number',
			'min' => 0,
			'max' => 60,
			'd'   => Options::DEFAULT_ESCROW_DAYS,
			'l'   => 'Días de carencia para liberación de puntos',
			'h'   => 'Tiempo en días que los puntos ganados por compra permanecen retenidos antes de pasar a saldo disponible tras completarse el pedido. 0 = acreditación inmediata.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_program_start'            => array(
			't' => 'text',
			'd' => '2026-08-17 00:00:00',
			'l' => 'Inicio de vigencia del programa',
			'h' => 'Pedidos creados antes de esta fecha no acreditan (formato AAAA-MM-DD HH:MM:SS).',
			's' => 'Puntos por compra',
		),
		'akb_rewards_expiry_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Hacer vencer los puntos',
			'h' => 'Apagado: los puntos duran para siempre. Encendido: duran los meses de abajo y se avisa por correo antes. El cambio se aplica al guardar.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_expiry_months'            => array(
			't'   => 'int',
			'd'   => 12,
			// 10x lo vigente: diez años. Para «no vencen jamás» están el 0 y el
			// interruptor.
			'max' => 120,
			'l'   => 'Meses de vida de los puntos',
			'h'   => 'Cuánto tiempo tiene el cliente para gastarlos. 0 = no vencen jamás. Vencen primero los más antiguos (FIFO).',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_expiry_warn_days'         => array(
			't'   => 'int',
			'd'   => 30,
			// 12x lo vigente y un año redondo. Avisar con más anticipación que
			// la vida de los puntos manda el correo el día de la compra; la
			// guarda de coherencia lo frena antes de llegar acá.
			'max' => 365,
			'l'   => 'Aviso previo al vencimiento (días)',
			'h'   => 'Correo «tienes N puntos por vencer» con esta anticipación (máx. uno al mes por cliente). 0 = sin aviso.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_invite_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Invitar a reseñar por correo',
			'h' => 'Un correo con enlace personal por pedido pagado, después del despacho o retiro. Cambiar ajustes conserva las fechas ya programadas y no reenvía invitaciones anteriores.',
			's' => 'Reseñas',
		),
		'akb_rewards_invite_status' => array(
			't' => 'status',
			'd' => 'completed',
			'l' => 'Despacho: estado que inicia la espera',
			'h' => 'Elige el estado que usas al entregar el pedido al transportista, por ejemplo «Listo para enviar». Puedes elegir el estado de despacho que use tu operación, con cualquier transportista. «Procesando» sólo confirma pago, no despacho.',
			's' => 'Reseñas',
		),
		'akb_rewards_invite_delay_days' => array(
			't'   => 'int',
			'd'   => 3,
			// 10x lo vigente y la vida por defecto del enlace: invitar a
			// reseñar más de un mes después del pedido no lo lee nadie.
			'max' => 30,
			'l'   => 'Despacho: días corridos antes de invitar',
			'h'   => 'Se cuentan desde la entrada al estado de despacho, no desde la compra ni el pago. Es un plazo estimado de recepción. Las invitaciones ya programadas conservan su fecha.',
			's'   => 'Reseñas',
		),
		'akb_rewards_invite_pickup_status' => array(
			't' => 'status',
			'd' => 'completed',
			'l' => 'Entrega directa o retiro: estado que inicia la espera',
			'h' => 'Por ejemplo «Completado» cuando entregas personalmente el pedido o lo retiran. No necesita pasar por el estado de despacho. Si ya había una invitación en cola, conserva su fecha y no crea otra. Si eliges el mismo estado en ambas rutas, se usa el plazo de despacho.',
			's' => 'Reseñas',
		),
		'akb_rewards_invite_pickup_delay_days' => array(
			't' => 'int',
			'd' => 6,
			'max' => 30,
			'l' => 'Entrega directa o retiro: días corridos antes de invitar',
			'h' => 'Espera después de la entrega o retiro. Sólo se aplica si ésta fue la primera ruta que programó la invitación.',
			's' => 'Reseñas',
		),
		'akb_rewards_invite_token_days' => array(
			't'   => 'int',
			'd'   => 30,
			// 12x lo vigente. Un enlace personal que vive más de un año es un
			// token eterno en la bandeja del cliente.
			'max' => 365,
			'l'   => 'Vida del enlace de reseña (días)',
			'h'   => 'Pasado este plazo el enlace personal vence.',
			's'   => 'Reseñas',
		),
		'akb_rewards_reviews_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Dar puntos por reseña con foto',
			'h' => 'Enciéndelo o apágalo y guarda los ajustes: el cambio se aplica al guardar en la tienda, los correos y Mi cuenta. Los montos guardados se conservan para cuando lo vuelvas a encender.',
			's' => 'Reseñas',
		),
		'akb_rewards_review_points'            => array(
			't'   => 'int',
			'd'   => 500,
			// 10x lo vigente y el máximo canjeable por pedido: una reseña no
			// puede pagar más que el techo de un canje.
			'max' => 5000,
			'l'   => 'Puntos por reseña con foto aprobada',
			'h'   => 'La invitación por correo solicita puntos y exige foto. Se acreditan al aprobar una reseña elegible. Las nuevas reseñas voluntarias de la ficha admiten foto opcional y no generan puntos; las anteriores conservan su política vigente. 0 apaga el programa de reseñas.',
			's'   => 'Reseñas',
		),
		'akb_rewards_review_every_n'           => array(
			't'   => 'int',
			'd'   => 1,
			// El min 1 es el que FALTABA: el panel dejaba guardar 0 y
			// Options::review_every_n() leía 1 — la pantalla mostraba un
			// número que no regía. El max 10: premiar una de cada diez ya es
			// no premiar, y para apagarlo está «Puntos por reseña» en 0.
			'min' => 1,
			'max' => 10,
			'l'   => 'Premiar cada cuántas reseñas',
			'h'   => '1 = se premia cada reseña elegible. 3 = se premia la tercera, la sexta… (las intermedias marcan su avance). La reseña de invitación debe tener foto y cumplir los requisitos. Las nuevas reseñas voluntarias de la ficha no generan puntos; las anteriores conservan su política vigente.',
			's'   => 'Reseñas',
		),
		'akb_rewards_review_max_per_user'      => array(
			't'   => 'int',
			'd'   => 0,
			// 10x el valor VIVO en producción (5), no el default del catálogo
			// (0 = sin tope): medido contra la fila real para que el primer
			// guardado no le baje el número al dueño.
			'max' => 50,
			'l'   => 'Tope de premios por cliente',
			'h'   => '0 = sin tope. Con 5, un cliente puede conservar hasta 5 premios de reseña. Retirar una reseña premiada revierte sus puntos y libera ese cupo.',
			's'   => 'Reseñas',
		),
		'akb_rewards_review_require_verified'  => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Exigir compra verificada',
			'h' => 'Solo premia reseñas de quien compró ese producto en la tienda.',
			's' => 'Reseñas',
		),
		'akb_rewards_moderate_reviews'         => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Moderación obligatoria',
			'h' => 'Exige aprobación en el formulario nativo de las fichas. Las reseñas recibidas por invitación siempre quedan pendientes. Se revisan en Productos → Valoraciones.',
			's' => 'Reseñas',
		),
		'akb_rewards_review_nudge_enabled'     => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Invitación a reseñar en el correo',
			'h' => 'Un recordatorio informativo en los correos elegidos. La invitación con enlace personal se envía aparte, después del plazo de despacho o retiro.',
			's' => 'Reseñas',
		),
		'akb_rewards_nudge_email_ids'          => array(
			't' => 'emails',
			'd' => 'customer_completed_order',
			'l' => 'Correos que llevan la invitación',
			'h' => 'Marca los correos que llevan el recordatorio informativo de reseñas. Sin ninguno marcado, no aparece; esto no apaga la invitación automática con enlace personal.',
			's' => 'Reseñas',
		),
		'akb_rewards_disclosure_enabled'       => array(
			't' => 'yesno',
			'd' => 'no',
			'l' => 'Avisar en la reseña que ganó puntos',
			'h' => 'Añade bajo la reseña premiada «Quien escribió esta reseña ganó Puntos Akibara por publicarla con foto» en la ficha. El carrusel tiene un control adicional para mostrar u ocultar este aviso sólo en la portada. Permite que quien la lea sepa que recibió un incentivo, independientemente de las estrellas que haya dado.',
			's' => 'Reseñas',
		),
		'akb_rewards_schema_exclude_rewarded'  => array(
			't' => 'yesno',
			'd' => 'no',
			'l' => 'Sacar de Google el texto de las reseñas premiadas',
			// El filtro de JSON-LD es independiente del aviso visible en la ficha.
			'h' => 'Este interruptor no cambia lo que se ve en la ficha: sólo decide si el texto de las reseñas premiadas viaja en los datos que Google lee de cada ficha. La nota media y el total de reseñas las siguen contando, porque son los mismos números que se ven en la ficha. Por defecto viaja.',
			's' => 'Reseñas',
		),
		'akb_rewards_referral_enabled'         => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Programa de referidos',
			'h' => 'Cada cliente tiene su enlace en Mi cuenta → Puntos Akibara.',
			's' => 'Referidos',
		),
		'akb_rewards_referral_points'          => array(
			't'   => 'int',
			'd'   => 500,
			// 10x lo vigente y el máximo canjeable por pedido.
			'max' => 5000,
			'l'   => 'Puntos para quien invita',
			'h'   => 'Se pagan cuando la primera compra del invitado llega al estado de abajo. Jamás por solo registrarse, y nunca si el invitado es la misma persona que invita (mismo RUT, teléfono o correo). 0 lo apaga.',
			's'   => 'Referidos',
		),
		'akb_rewards_referral_status'          => array(
			't' => 'status',
			'd' => 'completed',
			'l' => 'Estado que paga a quien invitó',
			'h' => 'El premio se paga cuando la primera compra del invitado ENTRA a este estado. Usa «Completado» si así registras una entrega o retiro. Si después se anula o se reembolsa por completo, el premio se descuenta.',
			's' => 'Referidos',
		),
		'akb_rewards_referral_cookie_days'     => array(
			't'   => 'int',
			'd'   => 30,
			// No es umbral del absurdo: es el rango que
			// Options::referral_cookie_days() YA impone y que la ayuda del
			// campo ya promete. El panel aceptaba 0 o 9999 y mostraba un
			// valor que el motor ignoraba.
			'min' => 1,
			'max' => 90,
			'l'   => 'Días de memoria del enlace',
			'h'   => 'Cuántos días vale la visita con el enlace antes de crear la cuenta (1-90).',
			's'   => 'Referidos',
		),
		'akb_rewards_referral_max_per_user'    => array(
			't'   => 'int',
			'd'   => 0,
			// 10x el valor VIVO en producción (10).
			'max' => 100,
			'l'   => 'Tope de premios por padrino',
			'h'   => '0 = sin tope. Cuentan los premios que conserva: si el pedido de un invitado se anula y el premio se descuenta, ese cupo vuelve a quedar libre.',
			's'   => 'Referidos',
		),
		'akb_rewards_birthday_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Dar regalo de cumpleaños',
			'h' => 'Enciéndelo o apágalo y guarda los ajustes: el cambio se aplica al guardar en la tienda, los correos y Mi cuenta. Los montos guardados se conservan para cuando lo vuelvas a encender.',
			's' => 'Cumpleaños',
		),
		'akb_rewards_birthday_points'          => array(
			't'   => 'int',
			'd'   => 1000,
			// 10x lo vigente.
			'max' => 10000,
			'l'   => 'Regalo de cumpleaños',
			'h'   => 'Puntos una vez al año, con el día y mes que el cliente registre en Mi cuenta. 0 lo apaga.',
			's'   => 'Cumpleaños',
		),
		'akb_rewards_carousel_enabled'         => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Carrusel del home',
			'h' => 'La sección de reseñas de la portada.',
			's' => 'Carrusel del home',
		),
		'akb_rewards_carousel_disclosure_enabled' => array(
			't' => 'yesno',
			'd' => 'no',
			'l' => 'Mostrar también en el carrusel el aviso de puntos',
			'h' => 'Si el aviso de puntos está activado en Reseñas, lo muestra también bajo las reseñas premiadas de la portada. Apagar esta casilla sólo lo oculta en el carrusel: conserva el aviso en las fichas y las etiquetas de reseñas transcritas.',
			's' => 'Carrusel del home',
		),
		'akb_rewards_carousel_min'             => array(
			't'   => 'int',
			'min' => 1,
			'd'   => 8,
			// 10x lo vigente. Un umbral alto deja la sección apagada para
			// siempre sin decirlo; para apagarla está el interruptor.
			'max' => 80,
			'l'   => 'Mínimo de reseñas para aparecer',
			'h'   => 'Se cuenta después de aplicar el tipo de reseña elegido. Bajo este mínimo, la sección no aparece. Usa 1 para mostrarla desde la primera reseña elegible.',
			's'   => 'Carrusel del home',
		),
		'akb_rewards_carousel_media'           => array(
			't'       => 'select',
			'd'       => 'mixed',
			'l'       => 'Reseñas que se muestran',
			'h'       => 'Se muestran primero las más recientes aprobadas. «Con foto» se refiere a una foto adjunta a la reseña; la portada del producto no cuenta como foto del cliente. Publicar una reseña aquí no le otorga puntos.',
			'choices' => array(
				'mixed'  => 'Mixto: con y sin fotos',
				'photos' => 'Sólo reseñas con foto',
				'text'   => 'Sólo reseñas sin foto',
			),
			's'       => 'Carrusel del home',
		),
		'akb_rewards_carousel_min_chars'       => array(
			't'   => 'int',
			'min' => 1,
			'd'   => 40,
			// 10x lo vigente. 400 caracteres es un ensayo: deja el carrusel
			// vacío.
			'max' => 400,
			'l'   => 'Largo mínimo del texto',
			'h'   => 'Caracteres mínimos para que una reseña sea elegible.',
			's'   => 'Carrusel del home',
		),
		'akb_rewards_carousel_cards'           => array(
			't'   => 'int',
			'min' => 1,
			'max' => 20,
			'd'   => 8,
			'l' => 'Tarjetas máximas',
			'h' => 'Cuántas reseñas se pintan (1-20).',
			's' => 'Carrusel del home',
		),
		'akb_rewards_carousel_title'           => array(
			't'     => 'text',
			'd'     => 'Voces del Distrito',
			// El encabezado admite 60 caracteres; se limita en el formulario y
			// en el guardado, que informa si hubo recorte.
			'largo' => 60,
			// Comparte el título con la sección de reseñas de cada ficha.
			'l'     => 'Título de las reseñas (portada y fichas)',
			'h'     => 'Corto: es el encabezado de la sección de reseñas de cada ficha de producto y el título del carrusel de reseñas de la portada. El carrusel sólo sale en la portada cuando hay al menos tantas reseñas como pide «Mínimo de reseñas para aparecer», más arriba. Vacío = «Voces del Distrito».',
			's'     => 'Carrusel del home',
		),
		'akb_rewards_carousel_exclude_rewarded' => array(
			't' => 'yesno',
			'd' => 'no',
			'l' => 'Excluir reseñas premiadas con puntos',
			'h' => 'Por defecto entran (tú ya las curaste al aprobarlas).',
			's' => 'Carrusel del home',
		),
		'akb_rewards_pdp_notice_enabled'       => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Aviso «Gana N puntos» en la ficha',
			// PdpNotice usa el formulario de compra y, si no existe, el resumen.
			'h' => 'Bajo el botón de compra; en un tomo agotado, que no tiene botón, bajo el precio. Con la matemática real del programa.',
			's' => 'Ficha de producto',
		),
		'akb_rewards_alerts_enabled'           => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Avisarme cuando algo necesite revisión',
			'h' => 'Un correo cuando algo del programa no se arregla solo: un canje cobrado sin saldo suficiente, una devolución de puntos que quedó corta, un reembolso borrado, una reseña aprobada que no acreditó, o un correo al cliente que no salió tras tres intentos. Cada aviso dice qué hacer y trae el enlace directo. El mismo aviso no se repite en el día.',
			's' => 'Alertas',
		),
		'akb_rewards_alerts_email'             => array(
			't' => 'recipients',
			'd' => '',
			'l' => 'Correos que reciben las alertas',
			'h' => 'Separados por coma. Vacío = el correo de administración del sitio.',
			's' => 'Alertas',
		),
	);

	/**
	 * Correos de la tienda para elegir: id → título, tal como los lista
	 * WooCommerce → Ajustes → Correos electrónicos.
	 *
	 * @return array<string,string>
	 */
	public static function email_choices(): array {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) ) {
			return array();
		}

		$lista = array();
		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( ! is_object( $email ) || empty( $email->id ) ) {
				continue;
			}
			$titulo                        = isset( $email->title ) ? trim( (string) $email->title ) : '';
			$lista[ (string) $email->id ] = '' !== $titulo ? $titulo : (string) $email->id;
		}

		return $lista;
	}

	/**
	 * Los nombres de option del catálogo. Los consume la migración de
	 * autoload de Schema: una option nueva acá entra sola.
	 *
	 * @return array<int,string>
	 */
	public static function option_names(): array {
		return array_keys( self::FIELDS );
	}

	/**
	 * Cuelga menú y guardado.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Load editing feedback only on this settings screen.
	 *
	 * @param string $hook Current admin screen.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'woocommerce_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script( 'akb-rewards-admin-settings', Assets::url( 'assets/js/admin-settings.js' ), array(), Assets::version( 'assets/js/admin-settings.js' ), true );
	}

	/**
	 * Submenú bajo WooCommerce.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			'Puntos y Reseñas (Akibara)',
			'Puntos y Reseñas',
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Pantalla: secciones con sus campos, del catálogo único.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap"><h1>Puntos y Reseñas</h1>';

		// Los rechazos del último guardado, antes del formulario: si una
		// baranda revierte un campo sin que se vea, es peor que no tenerla. Y
		// con un rechazo en pantalla el aviso verde cambia de frase: el resto
		// del formulario sí se guardó, pero «Ajustes guardados.» a secas debajo
		// de un «No se guardó…» era una contradicción (refutación 2026-09-12).
		$hubo_rechazo = false;
		$avisos       = get_transient( self::TRANSIENT_AVISOS . get_current_user_id() );
		if ( is_array( $avisos ) ) {
			delete_transient( self::TRANSIENT_AVISOS . get_current_user_id() );
			foreach ( $avisos as $aviso ) {
				if ( ! is_array( $aviso ) || '' === (string) ( $aviso['texto'] ?? '' ) ) {
					continue;
				}
				$hubo_rechazo = $hubo_rechazo || 'error' === ( $aviso['tipo'] ?? '' );
				// Por CONCATENACIÓN, no con printf: estos textos llevan
				// porcentajes y montos que escribe el programa, y la regla de
				// la casa mantiene los copys del dueño lejos de cualquier
				// formateador con «%».
				echo '<div class="notice notice-' . esc_attr( 'error' === ( $aviso['tipo'] ?? '' ) ? 'error' : 'warning' ) . '"><p>'
					. esc_html( (string) $aviso['texto'] ) . '</p></div>';
			}
		}
		if ( isset( $_GET['akb_rw_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo pinta un aviso.
			echo '<div class="notice notice-success"><p>' . ( $hubo_rechazo ? 'Se guardó el resto de los ajustes.' : 'Ajustes guardados.' ) . '</p></div>';
		}
		( new AdminOverview() )->render();
		echo '<p class="description">Configura aquí los montos y las opciones del programa. Los cambios se aplican al guardar; las validaciones de seguridad siguen vigentes. Los cortes de pedidos por país se configuran en Productos → Atributos → País, y por editorial en Productos → Marcas.</p>';
		echo '<nav aria-label="Secciones de ajustes"><p>Ir a: ';
		$links = array();
		foreach ( array_unique( array_column( self::FIELDS, 's' ) ) as $section ) {
			$links[] = '<a href="#' . esc_attr( self::section_anchor( $section ) ) . '">' . esc_html( $section ) . '</a>';
		}
		echo implode( ' · ', $links ) . '</p></nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links escaped individually.

		echo '<form data-akb-rewards-settings method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';

		submit_button( 'Guardar ajustes', 'primary', 'submit-top' );
		echo '<p data-akb-settings-status role="status" aria-live="polite">Los cambios se aplican al guardar.</p>';

		$seccion_abierta = '';
		foreach ( self::FIELDS as $option => $campo ) {
			if ( $campo['s'] !== $seccion_abierta ) {
				if ( '' !== $seccion_abierta ) {
					echo '</table>';
				}
				printf( '<h2 id="%s">%s</h2><table class="form-table" role="presentation">', esc_attr( self::section_anchor( $campo['s'] ) ), esc_html( $campo['s'] ) );
				$seccion_abierta = $campo['s'];
			}

			$valor = get_option( $option, 'akb_rewards_invite_pickup_delay_days' === $option ? \Akibara\Rewards\Reviews\Invitations::pickup_delay_days() : $campo['d'] );

			printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>', esc_attr( $option ), esc_html( $campo['l'] ) );

			if ( 'yesno' === $campo['t'] ) {
				printf(
					'<label><input type="checkbox" name="%1$s" id="%1$s" value="yes"%2$s> Activado</label>',
					esc_attr( $option ),
					checked( 'yes', (string) $valor, false )
				);
			} elseif ( 'select' === $campo['t'] ) {
				printf( '<select name="%1$s" id="%1$s">', esc_attr( $option ) );
				foreach ( $campo['choices'] as $clave => $etiqueta ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $clave ), selected( (string) $valor, $clave, false ), esc_html( $etiqueta ) );
				}
				echo '</select>';
			} elseif ( 'status' === $campo['t'] ) {
				// Selector de estados REALES de pedido (incluye estados custom
				// si algún día existen). Las claves viajan sin el prefijo wc-,
				// que es como las entrega woocommerce_order_status_changed.
				printf( '<select name="%1$s" id="%1$s">', esc_attr( $option ) );
				$estados = self::order_status_choices();
				// Un estado guardado que la tienda hoy no registra (el plugin que
				// lo aporta, apagado) se muestra en vez de perderse: sin esta
				// opción el navegador enviaba la primera de la lista y el guardado
				// movía la acreditación en silencio, sin llegar nunca a la rama que
				// conserva lo guardado y avisa. Lo mismo hace la lista de correos.
				if ( '' !== (string) $valor && ! array_key_exists( (string) $valor, $estados ) ) {
					$estados[ (string) $valor ] = (string) $valor . ' (este estado ya no está disponible)';
				}
				foreach ( $estados as $clave => $etiqueta ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $clave ),
						selected( (string) $valor, $clave, false ),
						esc_html( $etiqueta )
					);
				}
				echo '</select>';
			} elseif ( 'int' === $campo['t'] ) {
				printf(
					'<input type="number" name="%1$s" id="%1$s" value="%2$s" min="%3$s"%4$s step="1" class="small-text">',
					esc_attr( $option ),
					esc_attr( (string) (int) $valor ),
					esc_attr( (string) (int) ( $campo['min'] ?? 0 ) ),
					isset( $campo['max'] ) ? ' max="' . esc_attr( (string) (int) $campo['max'] ) . '"' : ''
				);
			} elseif ( 'emails' === $campo['t'] ) {
				// Casillas con los correos REALES de la tienda: nadie tiene que
				// saber que «Pedido completado» se llama customer_completed_order.
				$marcados = array_filter( array_map( 'trim', explode( ',', (string) $valor ) ) );
				$opciones = self::email_choices();
				// Un correo guardado que la tienda ya no registra (un plugin
				// apagado) se muestra en vez de borrarse en silencio al guardar.
				foreach ( $marcados as $guardado ) {
					if ( ! isset( $opciones[ $guardado ] ) ) {
						$opciones[ $guardado ] = $guardado . ' (este correo ya no está disponible)';
					}
				}
				// El vacío viaja siempre: dice «el formulario llegó», para no
				// confundir «ninguna casilla» con «no se pintó la lista».
				printf( '<fieldset><input type="hidden" name="%s[]" value="">', esc_attr( $option ) );
				foreach ( $opciones as $id => $titulo ) {
					printf(
						'<label style="display:block;margin:0 0 4px"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> %4$s</label>',
						esc_attr( $option ),
						esc_attr( $id ),
						checked( in_array( $id, $marcados, true ), true, false ),
						esc_html( $titulo )
					);
				}
				echo '</fieldset>';
			} elseif ( 'recipients' === $campo['t'] ) {
				printf(
					'<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" inputmode="email">',
					esc_attr( $option ),
					esc_attr( (string) $valor ),
					esc_attr( (string) get_option( 'admin_email', '' ) )
				);
			} else {
				// La fecha de vigencia lleva patrón y placeholder: el navegador
				// frena el formato incorrecto antes de que el servidor lo rechace.
				$extra = 'akb_rewards_program_start' === $option
					? ' placeholder="AAAA-MM-DD HH:MM:SS" pattern="\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}" inputmode="numeric"'
					: '';
				// El tope de largo de la fila, en las dos capas a la vez, como el
				// `max` de los enteros: el navegador no deja escribir más y el
				// guardado recorta lo que llegue por otra vía.
				if ( isset( $campo['largo'] ) ) {
					$extra .= ' maxlength="' . (int) $campo['largo'] . '"';
				}
				printf(
					'<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text"%3$s>',
					esc_attr( $option ),
					esc_attr( (string) $valor ),
					$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literales fijos de la casa y un entero, sin datos de usuario.
				);
			}

			if ( '' !== $campo['h'] ) {
				// El tope se dice con el MISMO número que aplica el guardado.
				printf(
					'<p class="description">%s</p>',
					esc_html( $campo['h'] . ( isset( $campo['largo'] ) ? ' Hasta ' . (int) $campo['largo'] . ' caracteres.' : '' ) )
				);

				// Explica si el umbral de reseñas permite mostrar el carrusel.
				if ( 'akb_rewards_carousel_title' === $option ) {
					$encendido = Options::carousel_on();
					$evidencia = self::carousel_evidence_text(
						$encendido,
						$encendido ? ( new \Akibara\Rewards\Reviews\Carousel() )->eligible_seen() : 0,
						Options::carousel_min()
					);
					if ( '' !== $evidencia ) {
						printf( '<p class="description" style="color:#996800"><strong>%s</strong></p>', esc_html( $evidencia ) );
					}
				}

				// Contrasta el estado elegido con la actividad real de los pedidos.
				if ( 'status' === (string) $campo['t'] ) {
					$evidencia = self::status_evidence_text( self::status_counts_90d( (string) $valor ), (string) $valor, self::order_status_choices(), $option );
					if ( '' !== $evidencia ) {
						printf( '<p class="description" style="color:#996800"><strong>%s</strong></p>', esc_html( $evidencia ) );
					}
				}

				// Advierte en ambos campos si el aviso está activo sin correos elegidos.
				if ( in_array( $option, array( 'akb_rewards_review_nudge_enabled', 'akb_rewards_nudge_email_ids' ), true )
					&& 'yes' === (string) get_option( 'akb_rewards_review_nudge_enabled', 'yes' )
					&& array() === array_filter( array_map( 'trim', explode( ',', (string) get_option( 'akb_rewards_nudge_email_ids', 'customer_completed_order' ) ) ) ) ) {
					printf(
						'<p class="description" style="color:#996800"><strong>%s</strong></p>',
						esc_html( 'Ojo: está activado, pero no hay ningún correo marcado abajo, así que el párrafo no sale en ninguno. Marca al menos uno, o apaga el interruptor.' )
					);
				}

				// Explica la fecha futura o ilegible que suspende la acumulación.
				if ( 'akb_rewards_program_start' === $option ) {
					$estado_programa = \Akibara\Rewards\Points\Accrual::program_state();
					if ( \Akibara\Rewards\Points\Accrual::PROGRAMA_AGENDADO === $estado_programa ) {
						printf(
							'<p class="description" style="color:#996800"><strong>%s</strong></p>',
							esc_html( sprintf( 'Ojo: el programa empieza el %s. Hasta entonces nadie acumula puntos por comprar, y la tienda no los promete en la ficha, en Mi cuenta ni en la caja.', \Akibara\Rewards\Points\ProgramPage::start_label() ) )
						);
					} elseif ( \Akibara\Rewards\Points\Accrual::PROGRAMA_ILEGIBLE === $estado_programa ) {
						printf(
							'<p class="description" style="color:#b32d2e"><strong>%s</strong></p>',
							esc_html( 'La fecha guardada no se puede leer, así que HOY nadie está acumulando puntos y la tienda dejó de prometerlos. Escríbela de nuevo con el formato AAAA-MM-DD HH:MM:SS y guarda.' )
						);
					}
				}

				// Muestra el estado del aviso visible junto al filtro de JSON-LD.
				if ( 'akb_rewards_schema_exclude_rewarded' === $option
					&& 'yes' !== (string) get_option( 'akb_rewards_disclosure_enabled', 'no' ) ) {
					printf(
						'<p class="description" style="color:#996800"><strong>%s</strong></p>',
						esc_html( 'Ojo: hoy las reseñas premiadas se leen en la ficha SIN aviso de que ganaron puntos. El aviso se enciende en el interruptor de arriba.' )
					);
				}

				// Expone al dueño cuántos clientes pidieron dejar de recibir invitaciones.
				if ( 'akb_rewards_invite_enabled' === $option ) {
					$bajas = \Akibara\Rewards\Reviews\InviteOptOut::total();
					printf(
						'<p class="description">%s</p>',
						esc_html(
							0 === $bajas
								? 'Cada invitación lleva su propio enlace para que el cliente pida no recibir más. Nadie lo ha usado todavía.'
								: sprintf(
									1 === $bajas
										? '%s cliente pidió no recibir más invitaciones: a esa dirección ya no se le escribe.'
										: '%s clientes pidieron no recibir más invitaciones: a esas direcciones ya no se les escribe.',
									number_format_i18n( $bajas )
								)
						)
					);
				}
			}
			echo '</td></tr>';
		}
		echo '</table>';

		submit_button( 'Guardar ajustes' );
		echo '</form>';

		/**
		 * Bloques extra de la pantalla de ajustes (ajuste manual de puntos,
		 * futuros paneles). Dentro del .wrap, fuera del form de settings.
		 *
		 * @since 0.3.0
		 */
		do_action( 'akb_rewards_settings_after' );

		echo '</div>';
	}

	/**
	 * Guardado: sanea por tipo desde el MISMO catálogo.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce se verifica en la línea de arriba.
		$this->save_fields( (array) wp_unslash( $_POST ) );

		// Invalida el transient del carrusel y el HTML de portada tras guardar.
		( new \Akibara\Rewards\Reviews\Carousel() )->flush();

		wp_safe_redirect( add_query_arg( 'akb_rw_saved', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Núcleo del guardado: sanea por tipo desde el MISMO catálogo, aplica las
	 * barandas que miran a VARIOS campos a la vez, y recién entonces persiste.
	 *
	 * Sanea y valida todos los campos antes de persistirlos en el orden del
	 * catálogo. Las restricciones entre campos reciben la configuración completa.
	 *
	 * Separado del wrapper (capability, nonce, purga, redirect y `exit`) para
	 * poder invocarlo sin terminar la petición.
	 *
	 * @param array<string,mixed> $input Datos del formulario, ya des-escapados.
	 * @return array<string,mixed> Lo que quedó guardado, por option.
	 */
	public function save_fields( array $input ): array {
		$avisos   = array();
		$acotados = array();
		$valores  = $this->sanitize_fields( $input, $avisos, $acotados );

		$valores = $this->guard_giveback( $valores, $avisos );
		$valores = $this->guard_expiry_warning( $valores, $avisos );

		foreach ( $acotados as $option => $acotado ) {
			if ( (int) $valores[ $option ] === $acotado ) {
				$min      = (int) ( self::FIELDS[ $option ]['min'] ?? 0 );
				$max      = (int) self::FIELDS[ $option ]['max'];
				$avisos[] = array(
					'tipo'  => 'warning',
					'texto' => self::etiqueta( $option ) . ' admite entre ' . number_format_i18n( $min ) . ' y ' . number_format_i18n( $max ) . ': se guardó en ' . number_format_i18n( $acotado ) . '.',
				);
			}
		}

		// Captura las opciones incluidas en el HTML de las fichas para emitir
		// una sola invalidación si cambia cualquiera de ellas.
		$ficha_antes = array();
		foreach ( array_merge( self::PROMESA_EN_CADA_FICHA, self::FORMA_DE_CADA_FICHA ) as $option ) {
			$ficha_antes[ $option ] = (string) get_option( $option, (string) self::FIELDS[ $option ]['d'] );
		}
		// Las opciones del carrusel y el aviso de divulgación gobiernan su
		// contenido cacheado; la lista se obtiene del catálogo del panel.
		$resenas_antes = array(
			'akb_rewards_disclosure_enabled' => (string) get_option( 'akb_rewards_disclosure_enabled', 'no' ),
		);
		foreach ( array_keys( self::FIELDS ) as $option ) {
			if ( 0 === strpos( $option, 'akb_rewards_carousel_' ) ) {
				$resenas_antes[ $option ] = (string) get_option( $option, (string) self::FIELDS[ $option ]['d'] );
			}
		}

		foreach ( array_keys( self::FIELDS ) as $option ) {
			// autoload = true: son escalares diminutos que se leen en el camino
			// del cliente (ficha, Mi cuenta, carrito, correos). Ver
			// Schema::autoloaded_options().
			update_option( $option, $valores[ $option ], true );
		}

		// La purga total ya incluye la del origen: si cambió una promesa y
		// además la forma, basta con ella.
		if ( self::cambio_alguno( self::PROMESA_EN_CADA_FICHA, $ficha_antes, $valores ) ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed (tercero).
		} elseif ( self::cambio_alguno( self::FORMA_DE_CADA_FICHA, $ficha_antes, $valores ) ) {
			do_action( 'litespeed_purge', '*' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed (tercero).
		}

		foreach ( $resenas_antes as $option => $antes ) {
			if ( $antes !== (string) $valores[ $option ] ) {
				// Invalida tanto el transient como el HTML de portada.
				( new \Akibara\Rewards\Reviews\Carousel() )->flush();
				break;
			}
		}

		if ( array() !== $avisos ) {
			set_transient( self::TRANSIENT_AVISOS . get_current_user_id(), $avisos, MINUTE_IN_SECONDS );
		}

		return $valores;
	}

	/**
	 * ¿Cambió en este guardado alguno de estos ajustes?
	 *
	 * @param array<int,string>    $options Ajustes a mirar.
	 * @param array<string,string> $antes   Lo que había, por option.
	 * @param array<string,mixed>  $valores Lo que quedó guardado, por option.
	 * @return bool
	 */
	private static function cambio_alguno( array $options, array $antes, array $valores ): bool {
		foreach ( $options as $option ) {
			if ( $antes[ $option ] !== (string) $valores[ $option ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Primera pasada: el valor de cada campo del catálogo, saneado por tipo.
	 * NO escribe nada.
	 *
	 * @param array<string,mixed>                                $input    Formulario.
	 * @param array<int,array{tipo:string,texto:string}>         $avisos   Avisos para el dueño, por referencia.
	 * @param array<string,int>                                  $acotados Enteros acotados al rango del catálogo, por referencia.
	 * @return array<string,mixed> option => valor saneado.
	 */
	private function sanitize_fields( array $input, array &$avisos, array &$acotados = array() ): array {
		$valores = array();

		foreach ( self::FIELDS as $option => $campo ) {
			if ( 'yesno' === $campo['t'] ) {
				// Un checkbox apagado NO viaja en el POST: su ausencia es el
				// «no». Por eso se recorre el catálogo y no lo recibido.
				$valor = isset( $input[ $option ] ) && 'yes' === $input[ $option ] ? 'yes' : 'no';
			} elseif ( 'select' === $campo['t'] ) {
				$valor = isset( $input[ $option ] ) && is_string( $input[ $option ] ) ? $input[ $option ] : (string) get_option( $option, $campo['d'] );
				if ( ! array_key_exists( $valor, $campo['choices'] ) ) {
					$guardado = (string) get_option( $option, $campo['d'] );
					$valor    = array_key_exists( $guardado, $campo['choices'] ) ? $guardado : $campo['d'];
					$avisos[] = array( 'tipo' => 'warning', 'texto' => '«' . $campo['l'] . '» no contiene una opción válida: se conservó la selección anterior.' );
				}
			} elseif ( 'status' === $campo['t'] ) {
				// Rechaza estados nuevos desconocidos. Conserva un estado ya guardado
				// si falta temporalmente el plugin que lo registra, y avisa al dueño.
				$valor    = sanitize_key( (string) ( $input[ $option ] ?? '' ) );
				$guardado = (string) get_option( $option, (string) $campo['d'] );
				if ( ! array_key_exists( $valor, self::order_status_choices() ) ) {
					if ( '' !== $valor && $valor === $guardado ) {
						// Con la forma que la pantalla lee ({tipo, texto}): como
						// texto suelto, `render()` lo descartaba y el dueño nunca
						// veía por qué el estado no cambió.
						$avisos[] = array(
							'tipo'  => 'warning',
							'texto' => '«' . $campo['l'] . '» se quedó como estaba (' . $valor . '): ese estado no existe hoy en la tienda, seguramente porque el plugin que lo registra está desactivado.',
						);
					} else {
						$valor = $guardado !== '' && array_key_exists( $guardado, self::order_status_choices() )
							? $guardado
							: (string) $campo['d'];
					}
				}
			} elseif ( 'int' === $campo['t'] ) {
				$crudo = isset( $input[ $option ] ) ? (int) $input[ $option ] : (int) $campo['d'];
				$valor = self::clamp_int( $option, $crudo );
				if ( isset( $input[ $option ] ) && $valor !== $crudo ) {
					$acotados[ $option ] = $valor;
				}
			} elseif ( 'emails' === $campo['t'] ) {
				// Ninguna casilla marcada no viaja en el POST: es «ninguno».
				// Lo marcado se cruza con los correos que la tienda tiene de
				// verdad, en el orden de su lista.
				$valor = '';
				if ( isset( $input[ $option ] ) ) {
					$guardados = array_filter( array_map( 'trim', explode( ',', (string) get_option( $option, $campo['d'] ) ) ) );
					$opciones  = self::email_choices();
					if ( array() === $opciones ) {
						$valor = implode( ',', $guardados ); // Sin la lista no se puede juzgar: se conserva.
					} else {
						// Valen los correos de la tienda y los que ya estaban guardados
						// (el panel los muestra aunque su plugin esté apagado).
						$validos = array_values( array_unique( array_merge( array_keys( $opciones ), $guardados ) ) );
						$valor   = implode( ',', array_values( array_intersect( $validos, array_map( 'strval', (array) $input[ $option ] ) ) ) );
					}
				}
			} elseif ( 'recipients' === $campo['t'] ) {
				$descartados = array();
				$valor       = implode( ', ', Options::email_list( sanitize_text_field( (string) ( $input[ $option ] ?? '' ) ), $descartados ) );
				if ( array() !== $descartados ) {
					$avisos[] = array(
						'tipo'  => 'warning',
						'texto' => 'En «' . $campo['l'] . '» se descartó lo que no es un correo: ' . implode( ', ', $descartados ) . '.',
					);
				}
			} else {
				$valor = isset( $input[ $option ] ) ? sanitize_text_field( (string) $input[ $option ] ) : (string) $campo['d'];
				// Recorta por caracteres visibles sin rechazar el resto del formulario.
				// El aviso concatena el texto del dueño sin interpretarlo como formato.
				if ( isset( $campo['largo'] ) ) {
					$letras = self::letras_visibles( $valor );
					if ( count( $letras ) > (int) $campo['largo'] ) {
						$valor    = rtrim( implode( '', array_slice( $letras, 0, (int) $campo['largo'] ) ) );
						$avisos[] = array(
							'tipo'  => 'warning',
							'texto' => '«' . $campo['l'] . '» admite hasta ' . (int) $campo['largo'] . ' caracteres: se guardó recortado, como «' . $valor . '».',
						);
					}
				}
				// La fecha de vigencia la lee Accrual::program_allows() con
				// createFromFormat: un formato mal escrito BLOQUEABA toda la
				// acreditación en silencio (QA 2026-09-01). Se rechaza al
				// guardar, se conserva la vigente y se avisa en pantalla.
				if ( 'akb_rewards_program_start' === $option ) {
					// La misma lectura con la que el motor la va a interpretar: una
					// sola función, para que el panel no acepte lo que el motor rechaza.
					if ( null === \Akibara\Rewards\Points\Accrual::parse_program_start( $valor ) ) {
						$valor    = (string) get_option( $option, $campo['d'] );
						$avisos[] = array(
							'tipo'  => 'warning',
							'texto' => 'La fecha de «Inicio de vigencia del programa» no tiene el formato AAAA-MM-DD HH:MM:SS: se conservó la anterior. Con una fecha inválida nadie acreditaría puntos.',
						);
					}
				}
			}

			$valores[ $option ] = $valor;
		}

		return $valores;
	}

	/**
	 * Un texto ya saneado, partido en lo que el cliente ve como UN carácter.
	 *
	 * Las entidades HTML reconocidas se cuentan como un carácter y nunca se
	 * parten. El resto se cuenta por carácter Unicode, no por byte, para
	 * aplicar el límite sobre el texto visible en el formulario.
	 *
	 * @param string $texto Texto saneado, tal como se guardaría.
	 * @return string[]
	 */
	private static function letras_visibles( string $texto ): array {
		$letras = array();
		foreach ( preg_split( '/(&#?[a-z0-9]+;)/i', $texto, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) ?: array() as $trozo ) {
			if ( '&' === $trozo[0] && html_entity_decode( $trozo, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) !== $trozo ) {
				$letras[] = $trozo;
				continue;
			}
			array_push( $letras, ...mb_str_split( $trozo, 1, 'UTF-8' ) );
		}

		return $letras;
	}

	/**
	 * Acota un entero al rango que declara su fila del catálogo. Es la MISMA
	 * cuenta que hace el navegador con los atributos `min`/`max` del campo:
	 * añadir el dato a la fila enciende las dos capas de una vez, y el
	 * recorrido de PHP defiende del POST hecho con curl o desde las devtools.
	 *
	 * @param string $option Option del catálogo.
	 * @param int    $valor  Valor pretendido.
	 * @return int
	 */
	private static function clamp_int( string $option, int $valor ): int {
		$campo = self::FIELDS[ $option ];
		$valor = max( (int) ( $campo['min'] ?? 0 ), $valor );
		if ( isset( $campo['max'] ) ) {
			$valor = min( (int) $campo['max'], $valor );
		}
		if ( 'akb_rewards_redeem_value' === $option && $valor <= 0 ) {
			$valor = 1; // El motor cae a 1: la pantalla no puede mostrar un 0 que no rige.
		}

		return $valor;
	}

	/**
	 * BARANDA DE LA RAZÓN: cuántos pesos devuelve la tienda por cada 100
	 * gastados. No la fija ningún campo por separado sino los TRES a la vez
	 * (puntos por tramo ÷ tamaño del tramo × pesos por punto), y por eso
	 * ningún `max` por campo la cubre: con el «2» de siempre y un tramo de $1
	 * la devolución es del 200 % sin que ningún número se vea raro.
	 *
	 * El techo entra en la misma baranda como cuarto campo: bajarlo por
	 * debajo de la razón vigente es tan contradictorio como subir la razón por
	 * encima de él, y se conserva el techo que había. La comparación es en
	 * ENTEROS (Calculator::giveback_exceeds): en float, el 15 % clavado a veces
	 * «superaba» al 15.
	 *
	 * @param array<string,mixed>                        $valores Valores saneados.
	 * @param array<int,array{tipo:string,texto:string}> $avisos  Avisos, por referencia.
	 * @return array<string,mixed>
	 */
	private function guard_giveback( array $valores, array &$avisos ): array {
		$campos = array( 'akb_rewards_earn_points', 'akb_rewards_earn_amount', 'akb_rewards_redeem_value', 'akb_rewards_max_giveback_pct' );

		return $this->aplicar_baranda(
			$valores,
			$avisos,
			$campos,
			static function ( array $v ): bool {
				return Calculator::giveback_exceeds(
					(int) $v['akb_rewards_earn_points'],
					(int) $v['akb_rewards_earn_amount'],
					(int) $v['akb_rewards_redeem_value'],
					(int) $v['akb_rewards_max_giveback_pct']
				);
			},
			static function ( array $v ): string {
				$puntos = Calculator::points_for_base(
					(float) self::EJEMPLO_COMPRA,
					(int) $v['akb_rewards_earn_points'],
					(int) $v['akb_rewards_earn_amount']
				);
				$pct = Calculator::giveback_pct(
					(int) $v['akb_rewards_earn_points'],
					(int) $v['akb_rewards_earn_amount'],
					(int) $v['akb_rewards_redeem_value']
				);

				return str_replace(
					array( '{compra}', '{puntos}', '{pesos}', '{pct}', '{techo}' ),
					array(
						self::pesos_texto( (float) self::EJEMPLO_COMPRA ),
						number_format_i18n( $puntos ),
						self::pesos_texto( (float) $puntos * (int) $v['akb_rewards_redeem_value'] ),
						self::pct_texto( $pct ),
						self::pct_texto( (float) (int) $v['akb_rewards_max_giveback_pct'] ),
					),
					'Con ese valor, una compra de {compra} devolvería {puntos} puntos, que valen {pesos} de descuento: el {pct} de la compra, sobre el techo de {techo} del programa.'
				);
			},
			'akb_rewards_earn_enabled'
		);
	}

	/**
	 * BARANDA DE COHERENCIA DEL VENCIMIENTO: no se puede avisar con más
	 * anticipación que la vida de los puntos.
	 *
	 * `Expiry::expiring_soon()` mira una ventana de N días: si N cubre la vida
	 * entera, el correo «tienes puntos por vencer» sale el día mismo en que se
	 * ganan.
	 *
	 * @param array<string,mixed>                        $valores Valores saneados.
	 * @param array<int,array{tipo:string,texto:string}> $avisos  Avisos, por referencia.
	 * @return array<string,mixed>
	 */
	private function guard_expiry_warning( array $valores, array &$avisos ): array {
		$campos = array( 'akb_rewards_expiry_warn_days', 'akb_rewards_expiry_months' );

		return $this->aplicar_baranda(
			$valores,
			$avisos,
			$campos,
			static function ( array $v ): bool {
				$meses = (int) $v['akb_rewards_expiry_months'];
				$dias  = (int) $v['akb_rewards_expiry_warn_days'];

				// 0 meses = no vencen jamás, y entonces el aviso no rige.
				return $meses > 0 && $dias > 0 && $dias > $meses * 30;
			},
			static function ( array $v ): string {
				return str_replace(
					array( '{dias}', '{meses}', '{vida}' ),
					array(
						number_format_i18n( (int) $v['akb_rewards_expiry_warn_days'] ),
						number_format_i18n( (int) $v['akb_rewards_expiry_months'] ),
						number_format_i18n( (int) $v['akb_rewards_expiry_months'] * 30 ),
					),
					'Avisar con {dias} días de anticipación cuando los puntos viven {meses} meses (unos {vida} días) manda el correo «tienes puntos por vencer» el mismo día en que el cliente los gana.'
				);
			},
			'akb_rewards_expiry_enabled'
		);
	}

	/**
	 * El mecanismo común de las dos barandas: si el conjunto de campos viola
	 * la regla, se REVIERTEN sólo los que cambiaron en este envío y el resto
	 * del formulario se guarda normal.
	 *
	 * Revertir sólo los cambiados es lo que hace que el aviso diga la verdad
	 * («no se guardó ESTO») en vez de deshacer trabajo que el dueño no tocó. Y
	 * si NINGUNO cambió —valor heredado escrito por WP-CLI o SQL, o una
	 * instalación anterior a la baranda— se guarda igual y sólo se advierte:
	 * si no, un valor heredado fuera de rango bloquearía para siempre
	 * cualquier otro guardado del panel, que es peor que el problema.
	 *
	 * Con el interruptor del carril APAGADO la regla no rige hoy (el accessor
	 * devuelve 0 y nadie acumula ni vence), así que tampoco se revierte: se
	 * guarda y se advierte, diciendo que no rige y que hay que corregirlo
	 * antes de encender. Rechazar ahí era afirmar una consecuencia que no
	 * ocurre (refutación 2026-09-12).
	 *
	 * @param array<string,mixed>                        $valores      Valores saneados.
	 * @param array<int,array{tipo:string,texto:string}> $avisos       Avisos, por referencia.
	 * @param array<int,string>                          $involucrados Options que forman la regla.
	 * @param callable                                   $viola        fn(array):bool — ¿se rompe la regla?
	 * @param callable                                   $diagnostico  fn(array):string — la consecuencia, en pesos.
	 * @param string                                     $carril       Option yes/no del interruptor que hace regir la regla.
	 * @return array<string,mixed>
	 */
	private function aplicar_baranda( array $valores, array &$avisos, array $involucrados, callable $viola, callable $diagnostico, string $carril ): array {
		if ( ! $viola( $valores ) ) {
			return $valores;
		}

		$diagnostico_intentado = (string) $diagnostico( $valores );

		if ( 'yes' !== (string) ( $valores[ $carril ] ?? 'yes' ) ) {
			$avisos[] = array(
				'tipo'  => 'warning',
				'texto' => 'Ojo: ' . $diagnostico_intentado . ' Hoy no rige, porque ' . self::etiqueta( $carril ) . ' está apagado; corrige ' . self::lista_etiquetas( $involucrados, 'o' ) . ' antes de encenderlo.',
			);

			return $valores;
		}

		$previos   = array();
		$cambiados = array();
		foreach ( $involucrados as $option ) {
			$previos[ $option ] = self::clamp_int( $option, (int) get_option( $option, self::FIELDS[ $option ]['d'] ) );
			if ( $previos[ $option ] !== (int) $valores[ $option ] ) {
				$cambiados[] = $option;
			}
		}

		if ( array() === $cambiados ) {
			$avisos[] = array(
				'tipo'  => 'warning',
				'texto' => $diagnostico_intentado . ' Este guardado no cambió esos campos, así que quedaron como estaban. Corrige ' . self::lista_etiquetas( $involucrados, 'o' ) . '.',
			);

			return $valores;
		}

		$conservado = array();
		foreach ( $cambiados as $option ) {
			$valores[ $option ] = $previos[ $option ];
			$conservado[]       = self::etiqueta( $option ) . ' en ' . number_format_i18n( $previos[ $option ] );
		}

		$texto = 'No se guardó ' . self::lista_etiquetas( $cambiados, 'ni' ) . '. '
			. $diagnostico_intentado
			. ' Se conservó lo que había: ' . implode( ', ', $conservado ) . '.';

		if ( $viola( $valores ) ) {
			$texto .= ' Ojo: lo que quedó guardado sigue teniendo el mismo problema.';
		}

		$avisos[] = array(
			'tipo'  => 'error',
			'texto' => $texto,
		);

		return $valores;
	}

	/**
	 * La etiqueta que el dueño ve en pantalla, entrecomillada.
	 *
	 * @param string $option Option del catálogo.
	 * @return string
	 */
	private static function etiqueta( string $option ): string {
		return '«' . self::FIELDS[ $option ]['l'] . '»';
	}

	/**
	 * Enumeración legible de etiquetas: «A», «B» y/o «C».
	 *
	 * @param array<int,string> $options Options del catálogo.
	 * @param string            $nexo    Conector final («o», «ni», «y»).
	 * @return string
	 */
	private static function lista_etiquetas( array $options, string $nexo ): string {
		$etiquetas = array_map( array( self::class, 'etiqueta' ), array_values( $options ) );
		if ( count( $etiquetas ) < 2 ) {
			return (string) ( $etiquetas[0] ?? '' );
		}

		$ultima = array_pop( $etiquetas );

		return implode( ', ', $etiquetas ) . ' ' . $nexo . ' ' . $ultima;
	}

	/**
	 * Pesos con separador de miles, para los avisos.
	 *
	 * @param float $monto Monto.
	 * @return string
	 */
	private static function pesos_texto( float $monto ): string {
		return '$' . number_format_i18n( round( $monto ) );
	}

	/**
	 * Porcentaje para los avisos. Se arma por CONCATENACIÓN, jamás con
	 * sprintf: un `%` en un texto que lee el dueño es un fatal esperando (y la
	 * regla de la casa lo prohíbe).
	 *
	 * @param float $pct Porcentaje.
	 * @return string
	 */
	private static function pct_texto( float $pct ): string {
		$redondeado = round( $pct, 1 );
		$decimales  = (float) (int) $redondeado === $redondeado ? 0 : 1;

		return number_format_i18n( $redondeado, $decimales ) . '%';
	}

	/**
	 * Estados de pedido elegibles para disparar la invitación, SIN el
	 * prefijo `wc-` (así los entrega `woocommerce_order_status_changed`,
	 * que es contra lo que compara Invitations::trigger_status()).
	 *
	 * Con WooCommerce vivo la lista es la REAL del sitio (estados custom
	 * incluidos); sin ella (suite), el trío estándar para que la pantalla
	 * no quede vacía ni el sanitizado deje pasar cualquier cosa.
	 *
	 * @return array<string,string> clave => etiqueta.
	 */
	public static function order_status_choices(): array {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$choices = array();
			foreach ( wc_get_order_statuses() as $clave => $etiqueta ) {
				$clave = str_starts_with( (string) $clave, 'wc-' ) ? substr( (string) $clave, 3 ) : (string) $clave;
				// Estados terminales-negativos fuera: invitar a reseñar un
				// pedido cancelado/reembolsado/fallido no tiene sentido.
				if ( in_array( $clave, array( 'cancelled', 'refunded', 'failed', 'checkout-draft' ), true ) ) {
					continue;
				}
				$choices[ $clave ] = (string) $etiqueta;
			}

			if ( array() !== $choices ) {
				return $choices;
			}
		}

		return array(
			'processing' => 'Procesando',
			'completed'  => 'Completado',
			'on-hold'    => 'En espera',
		);
	}

	/**
	 * Cuántos pedidos están HOY en cada estado pagado —más el elegido, si es
	 * otro (p. ej. «En espera»)— de los creados en los últimos 90 días. Tres o
	 * cuatro consultas ligeras, sólo en la pantalla del panel.
	 *
	 * @param string $current El estado elegido en el campo.
	 * @return array<string, int> estado => pedidos.
	 */
	private static function status_counts_90d( string $current = '' ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$estados = OrderStates::PAID;
		if ( '' !== $current && ! in_array( $current, $estados, true ) ) {
			$estados[] = $current;
		}

		$desde = gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS );
		$out   = array();
		foreach ( $estados as $estado ) {
			$resultado      = wc_get_orders(
				array(
					'status'       => $estado,
					'date_created' => '>=' . $desde,
					'limit'        => 1,
					'paginate'     => true,
					'return'       => 'ids',
				)
			);
			$out[ $estado ] = is_object( $resultado ) && isset( $resultado->total ) ? (int) $resultado->total : 0;
		}

		return $out;
	}

	/** Stable destinations for the overview and settings navigation. */
	private static function section_anchor( string $section ): string {
		$anchors = array( 'Reseñas' => 'reviews', 'Carrusel del home' => 'carousel', 'Puntos por compra' => 'points' );
		return 'akb-rewards-section-' . ( $anchors[ $section ] ?? md5( $section ) );
	}

	/**
	 * Describe current order counts without inferring their transition history.
	 *
	 * @param array<string, int>    $counts  estado => pedidos (90 días).
	 * @param string                $current Estado elegido.
	 * @param array<string, string> $labels  estado => etiqueta.
	 * @param string                $option Campo de estado que muestra el aviso.
	 * @return string '' si no hay datos.
	 */
	public static function status_evidence_text( array $counts, string $current, array $labels, string $option = 'akb_rewards_invite_status' ): string {
		if ( array() === $counts || array_sum( $counts ) <= 0 ) {
			return '';
		}

		$partes = array();
		foreach ( $counts as $estado => $n ) {
			$partes[] = ( $labels[ $estado ] ?? $estado ) . ' ' . number_format_i18n( $n );
		}
		$texto = 'Pedidos de los últimos 90 días, por estado actual: ' . implode( ' · ', $partes ) . '.';

		// El estado más frecuente hoy no demuestra por qué estados pasó cada
		// pedido ni cuál será su estado final.
		$terminal = '';
		$maximo   = 0;
		foreach ( OrderStates::PAID as $estado ) {
			if ( (int) ( $counts[ $estado ] ?? 0 ) > $maximo ) {
				$maximo   = (int) $counts[ $estado ];
				$terminal = $estado;
			}
		}

		$actual = (int) ( $counts[ $current ] ?? 0 );
		if ( '' !== $terminal && $terminal !== $current && $actual < $maximo && OrderStates::is_at_or_after( $current, $terminal ) ) {
			$nombre_actual   = $labels[ $current ] ?? $current;
			$nombre_terminal = $labels[ $terminal ] ?? $terminal;
			$contextos = array(
				'akb_rewards_invite_status'   => 'las invitaciones',
				'akb_rewards_invite_pickup_status' => 'las invitaciones de entrega directa o retiro',
				'akb_rewards_referral_status' => 'los premios de referidos',
			);
			$contexto = $contextos[ $option ] ?? 'la acreditación de puntos';
			$texto   .= ' Ojo: hoy hay ' . number_format_i18n( $actual ) . ' pedidos en «' . $nombre_actual . '» y ' . number_format_i18n( $maximo ) . ' en «' . $nombre_terminal . '». Revisa que el estado elegido para ' . $contexto . ' coincida con tu flujo. Estos conteos no indican por qué estados pasó cada pedido ni prueban que el proceso haya fallado.';
		}

		return $texto;
	}

	/**
	 * Función pura: bajo el título de las reseñas, si HOY la portada muestra
	 * el carrusel que la etiqueta nombra. Lo que se promete contra lo que se
	 * ve: con menos reseñas elegibles que el mínimo, el carrusel no se pinta y
	 * ese título sólo se lee en las fichas.
	 *
	 * @param bool $encendido ¿Está encendido el carrusel?
	 * @param int  $elegibles Reseñas elegibles que ve el carrusel (topadas en el umbral).
	 * @param int  $minimo    Mínimo de reseñas para aparecer.
	 * @return string '' cuando el carrusel sí sale.
	 */
	public static function carousel_evidence_text( bool $encendido, int $elegibles, int $minimo ): string {
		if ( ! $encendido ) {
			return 'Hoy el carrusel de la portada está apagado (casilla «Carrusel del home», más arriba): este título sólo se ve en las fichas.';
		}
		if ( $elegibles >= $minimo ) {
			return '';
		}

		if ( $elegibles <= 0 ) {
			$cuantas = 'ninguna reseña cumple';
		} elseif ( 1 === $elegibles ) {
			$cuantas = 'sólo 1 reseña cumple';
		} else {
			$cuantas = 'sólo ' . number_format_i18n( $elegibles ) . ' reseñas cumplen';
		}

		return 'Hoy el carrusel no sale en la portada: ' . $cuantas . ' las condiciones de esta sección y el mínimo es ' . number_format_i18n( $minimo ) . '. Mientras tanto, este título sólo se ve en las fichas.';
	}
}


================================================================
### ARCHIVO: public_html/wp-content/themes/akibara-gp/assets/css/reviews-p5-v4.css
================================================================

/* ==========================================================================
   Reseñas de la ficha — estética Persona 5 / manga (WooCommerce #reviews)

   Arquitectura:
   - Especificidad deliberada sin !important: reglas acotadas con la cadena de
     WooCommerce (.woocommerce #reviews #comments ol.commentlist li.review) para
     prevalecer limpiamente sobre el CSS base.
   - Ámbito estricto a #reviews para prevenir colisiones con .star-rating en
     fichas de producto o tarjetas de catálogo.
   - Textos y títulos semánticos respetando el árbol de accesibilidad (H2 real
     sin superposición artificial con pseudo-elementos).
   ========================================================================== */

/* Lista y tarjetas */
.woocommerce #reviews #comments ol.commentlist {
    padding: 0;
    margin: 0;
    list-style: none;
}

.woocommerce #reviews #comments ol.commentlist li.review {
    padding: 0;
    margin: 0 0 40px;
    border: none;
    width: 100%;
    box-sizing: border-box;
}

#reviews .commentlist li.review .comment_container {
    background-color: var(--aki-surface-2);
    border: 2px solid var(--aki-border-light);
    border-left: 5px solid var(--aki-red);
    border-radius: 0;
    box-shadow: 5px 5px 0 var(--aki-black);
    padding: 24px;
    margin: 10px 0 24px;
    position: relative;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
    width: 100%;
    max-width: 100%;
}

#reviews .commentlist li.review:nth-child(even) .comment_container {
    border-color: var(--aki-red-bright);
    border-left: 5px solid var(--aki-white);
}

/* Sólo con puntero real: en un teléfono el :hover queda pegado tras el toque. */
@media (hover: hover) {
    #reviews .commentlist li.review .comment_container:hover {
        transform: translateY(-4px);
    }
}

/* Autor (estilo nota de rescate) */
#reviews .commentlist li.review .woocommerce-review__author {
    font-family: var(--font-heading);
    font-size: 1.25rem;
    font-weight: 400;
    font-synthesis: none;
    text-transform: uppercase;
    background: var(--aki-white);
    color: var(--aki-black);
    padding: 4px 10px;
    display: inline-block;
    transform: rotate(2deg);
    margin-bottom: 16px;
    box-shadow: 3px 3px 0 var(--aki-red-bright);
    line-height: 1;
}

/* Avatar anguloso. WooCommerce: (2,2,4) → aquí (2,3,4). */
.woocommerce #reviews #comments ol.commentlist li.review img.avatar {
    border-radius: 0;
    clip-path: polygon(10% 0, 100% 10%, 90% 100%, 0 90%);
    border: 2px solid var(--aki-red-bright);
    background: var(--aki-surface);
    padding: 2px;
    width: 60px;
    height: 60px;
    position: absolute;
    top: -15px;
    left: -15px;
    z-index: 3;
    box-shadow: 4px 4px 0 rgba(0, 0, 0, 0.5);
}

/* WooCommerce: (2,3,2) → aquí (2,4,2). */
.woocommerce #reviews #comments ol.commentlist li.review .comment-text {
    margin-left: 50px;
    border: none;
    padding: 0;
}

/* Estrellas de la reseña.
 * `--aki-red-text` y no `--aki-red-bright`: #FF0A1D sobre la tarjeta (#1A1A1A)
 * da 4,40:1 y reprueba AA para texto chico; el token de rojo legible sí pasa
 * (R12-resena-contraste). */
#reviews .commentlist li.review .star-rating span::before {
    color: var(--aki-red-text);
    text-shadow: 1px 1px 0 #000;
}

#reviews .star-rating {
    font-size: 1.2rem;
    transform: skewX(-10deg);
}

/* Las metas que WooCommerce escribe encima de la tarjeta oscura (fecha y
 * «(propietario verificado)») traían su gris de tema claro: #767676 sobre
 * #1A1A1A = 3,83:1. Gris del sistema oscuro, ≥ 8:1. */
#reviews .commentlist li.review .woocommerce-review__published-date,
#reviews .commentlist li.review .woocommerce-review__dash,
#reviews .commentlist li.review .woocommerce-review__verified {
    color: var(--aki-gray-300);
}

/* Texto de la reseña */
.woocommerce #reviews #comments ol.commentlist li.review .description {
    font-family: var(--font-body);
    font-size: 1rem;
    color: var(--aki-white);
    line-height: 1.6;
    font-style: italic;
    border-left: 4px solid var(--aki-red);
    padding-left: 16px;
    margin-top: 10px;
}
