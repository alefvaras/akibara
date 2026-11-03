'use strict';

/*
 * Aviso del corazón para invitados: tocar el fondo lo cierra, tocar DENTRO no.
 *
 * El fondo de un <dialog> modal no tiene nodo propio: el target de un toque ahí
 * es el <dialog>. Pero también lo es su relleno —los márgenes junto al texto, el
 * hueco del safe-area del iPhone—, así que mirar sólo el target cerraba el aviso
 * cuando el cliente tocaba junto al texto (revisión B5-R3, medido en Chromium y
 * WebKit: (8, 663) en móvil y (508, 422) en escritorio caían en el DIALOG).
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const source = fs.readFileSync( require.resolve( '../../../assets/js/wishlist.js' ), 'utf8' );

function elemento( tag ) {
	const escuchas = {};
	const attrs = new Map();
	return {
		tagName: tag.toUpperCase(),
		textContent: '',
		innerHTML: '',
		open: false,
		escuchas,
		addEventListener: ( tipo, fn ) => { ( escuchas[ tipo ] = escuchas[ tipo ] || [] ).push( fn ); },
		setAttribute: ( k, v ) => attrs.set( k, String( v ) ),
		getAttribute: ( k ) => ( attrs.has( k ) ? attrs.get( k ) : null ),
		focus() {},
	};
}

function entorno() {
	// La caja visible del aviso en escritorio (1440x900), medida en el espejo.
	const CAJA = { left: 500, top: 304, right: 940, bottom: 596 };
	const hijos = {};
	const dialogo = elemento( 'dialog' );
	dialogo.querySelector = ( sel ) => ( hijos[ sel ] = hijos[ sel ] || elemento( 'div' ) );
	dialogo.getBoundingClientRect = () => CAJA;
	dialogo.showModal = () => { dialogo.open = true; };
	dialogo.close = () => {
		dialogo.open = false;
		( dialogo.escuchas.close || [] ).forEach( ( fn ) => fn() );
	};

	const corazon = {
		getAttribute: ( k ) => ( { 'data-product-id': '30534', 'data-name': 'Homunculus 08' }[ k ] ?? null ),
		closest: ( sel ) => ( '.akb-wl-toggle' === sel ? corazon : null ),
		focus() {},
	};
	let alClic = null;
	const ctx = {
		akbWishlist: { isLogged: false, guestPrompt: true, loginUrl: '/mi-cuenta/', labels: {} },
		HTMLDialogElement: Object.assign( function () {}, { prototype: { showModal() {} } } ),
		location: { href: 'http://localhost:8080/homunculus-08-ivrea-argentina/' },
		sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		document: {
			readyState: 'complete',
			addEventListener: ( tipo, fn ) => { if ( 'click' === tipo ) { alClic = fn; } },
			createElement: ( tag ) => {
				assert.equal( tag, 'dialog' );
				return dialogo;
			},
			body: { appendChild() {} },
			documentElement: { classList: { add() {}, remove() {} } },
			contains: () => true,
		},
		encodeURIComponent,
	};
	ctx.window = ctx;
	vm.runInNewContext( source, ctx );

	function abrir() {
		alClic( { target: corazon, preventDefault() {} } );
		assert.equal( dialogo.open, true, 'el toque en el corazón abre el aviso' );
	}
	function tocar( x, y ) {
		( dialogo.escuchas.mousedown || [] ).forEach( ( fn ) => fn( { target: dialogo, clientX: x, clientY: y } ) );
	}
	return { dialogo, abrir, tocar };
}

test( 'tocar junto al texto, dentro de la caja, no cierra el aviso', () => {
	const e = entorno();
	e.abrir();
	for ( const [ x, y ] of [ [ 508, 422 ], [ 932, 422 ], [ 720, 396 ], [ 500, 304 ], [ 940, 596 ] ] ) {
		e.tocar( x, y );
		assert.equal( e.dialogo.open, true, `(${ x }, ${ y }) está dentro de la caja: el aviso sigue abierto` );
	}
} );

test( 'tocar el fondo, fuera de la caja, sí lo cierra', () => {
	for ( const [ x, y ] of [ [ 12, 40 ], [ 499, 400 ], [ 941, 400 ], [ 720, 303 ], [ 720, 597 ] ] ) {
		const e = entorno();
		e.abrir();
		e.tocar( x, y );
		assert.equal( e.dialogo.open, false, `(${ x }, ${ y }) es fondo: el aviso se cierra` );
	}
} );
