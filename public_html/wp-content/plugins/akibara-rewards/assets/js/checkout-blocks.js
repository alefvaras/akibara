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
			var switchPts = Math.max( 0, parseInt( data.switch_points, 10 ) || 0 );
			var botonSwitch = switchPts > 0 ? el( 'div', { style: { marginTop: '8px' } },
				el( 'button', {
					type: 'button',
					className: 'wc-block-components-button wp-element-button akb-switch-points-btn',
					disabled: enviando,
					style: { display: 'block', width: '100%', minHeight: '44px', padding: '10px 14px', whiteSpace: 'normal', lineHeight: 1.3, textAlign: 'center', boxSizing: 'border-box' },
					onClick: function () { return aplicar( switchPts ); },
				}, enviando ? 'Aplicando…' : 'Usar mis ' + enPesos( data, switchPts ) + ' en puntos en lugar del descuento de pack' )
			) : null;
			return el( 'div', { className: 'akb-rw-checkout-box', style: ESTILO_CAJA },
				el( 'p', { style: { margin: '0 0 6px', fontWeight: 600 } }, '⭐ Tus Puntos Akibara' ),
				el( 'p', { style: { margin: 0, fontSize: '13px' } },
					'Tienes ' + fmt( data.balance ) + ' puntos. ' + ( data.unavailable || ( data.missing > 0 ? 'Te faltan $' + fmt( data.missing ) + ' en productos para poder canjear.' : 'No hay un canje disponible en este carrito.' ) ) ),
				botonSwitch, vigente, avisoVisible, lineaGana( data ) );
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
