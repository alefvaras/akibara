'use strict';

/*
 * Arnés compartido de las pruebas del checkout de bloques.
 *
 * EJECUTA `assets/js/checkout-blocks.js` con dobles de wp/wc y un DOM mínimo,
 * y devuelve las dos caras que el plugin registra: la caja de canje
 * (ExperimentalDiscountsMeta) y el gancho crea-cuenta (ExperimentalOrderMeta).
 * Vive aparte desde 0.4.4, cuando la segunda prueba necesitó el mismo montaje:
 * duplicarlo deja dos arneses que se desincronizan del contrato de Woo.
 */

const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

const source = fs.readFileSync(
	require.resolve( '../../../assets/js/checkout-blocks.js' ),
	'utf8'
);

const INVITADO = { logged_in: false, teaser: true, welcome_bonus: 500, first_min: 0, earn_points: 2, earn_amount: 100 };
const WOO = { checkoutAllowsGuest: true, checkoutAllowsSignup: true, generatePassword: true };

function montar( opciones = {} ) {
	const cuenta = opciones.cuenta || INVITADO;
	const ajustes = opciones.ajustes || WOO;
	const temporizadores = [];

	const casilla = {
		checked: Boolean( opciones.marcada ),
		clics: 0,
		foco: null,
		click() {
			this.checked = ! this.checked;
			this.clics++;
		},
		focus( o ) {
			this.foco = o;
		},
	};
	const caja = {
		style: {},
		scroll: null,
		querySelector: ( s ) => ( s === 'input[type="checkbox"]' ? casilla : null ),
		scrollIntoView( o ) {
			this.scroll = o;
		},
	};

	let render = null;
	const reloj = { ahora: 1e12 };
	const usos = { useSelect: 0 };
	const canjeEstados = [];
	const solicitudesCanje = [];
	const campoCanje = { enfocado: false, focus() { this.enfocado = true; } };
	let cursorCanje = null;
	const sandbox = {
		Intl,
		Date: { now: () => reloj.ahora },
		document: {
			getElementById: ( id ) => id === 'akb-rw-canje' ? campoCanje : null,
			querySelector: ( s ) => ( false !== opciones.pintada && s === '.wc-block-checkout__create-account' ? caja : null ),
		},
		setTimeout: ( fn ) => temporizadores.push( fn ),
		clearTimeout: () => {},
		matchMedia: () => ( { matches: Boolean( opciones.reducido ) } ),
		wp: {
			element: { createElement: ( t, p, ...c ) => ( { t, p: p || {}, c } ), useState: ( v ) => { if ( cursorCanje === null ) { return [ v, () => {} ]; } const i = cursorCanje++; if ( ! ( i in canjeEstados ) ) { canjeEstados[ i ] = v; } return [ canjeEstados[ i ], ( value ) => { canjeEstados[ i ] = value; } ]; }, Fragment: 'Fragment' },
			data: {
				useSelect: ( fn ) => ( usos.useSelect++, fn )( ( store ) => ( 'wc/store/cart' === store
					? { getCartData: () => ( { extensions: { 'akibara-rewards': cuenta } } ) }
					: { getShouldCreateAccount: () => casilla.checked } ) ),
			},
			plugins: { registerPlugin: ( nombre, o ) => { render = o.render; } },
		},
		wc: {
			blocksCheckout: { ExperimentalDiscountsMeta: 'DiscountsMeta', ExperimentalOrderMeta: 'OrderMeta', extensionCartUpdate( request ) { solicitudesCanje.push( request.data.points ); return opciones.errorCanje ? Promise.reject( { message: opciones.errorCanje } ) : Promise.resolve(); } },
			wcSettings: opciones.sinAjustes ? undefined : { getSetting: ( k, d ) => ( k in ajustes ? ajustes[ k ] : d ) },
		},
	};
	sandbox.window = sandbox;
	vm.runInNewContext( source, sandbox );

	const cara = ( tipo ) => render().c.find( ( n ) => tipo === n.t );
	const pintar = () => cara( 'OrderMeta' ).c[ 0 ].t(
		{ context: 'contexto' in opciones ? opciones.contexto : 'woocommerce/checkout' }
	);
	// La caja de canje no recibe props: sale igual en el Carrito y en el Checkout.
	const pintarCanje = () => { cursorCanje = 0; try { return cara( 'DiscountsMeta' ).c[ 0 ].t(); } finally { cursorCanje = null; } };

	return { casilla, caja, temporizadores, pintar, pintarCanje, reloj, usos, solicitudesCanje, campoCanje };
}

function nodos( n ) {
	if ( Array.isArray( n ) ) {
		return n.flatMap( nodos );
	}
	if ( ! n || 'object' !== typeof n ) {
		return [];
	}
	return [ n, ...nodos( n.c ) ];
}

function texto( n ) {
	if ( Array.isArray( n ) ) {
		return n.map( texto ).join( ' ' );
	}
	if ( null === n || undefined === n || false === n ) {
		return '';
	}
	return 'object' === typeof n ? texto( n.c ) : String( n );
}


const boton = ( arbol ) => nodos( arbol ).find( ( n ) => 'button' === n.t );

module.exports = { source, INVITADO, WOO, montar, nodos, texto, boton };
