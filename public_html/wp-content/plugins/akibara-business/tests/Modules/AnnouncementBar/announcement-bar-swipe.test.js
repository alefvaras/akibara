'use strict';

/*
 * Barra de anuncios: deslizar cambia de anuncio también sobre un mensaje-enlace.
 *
 * Un mensaje «Texto | /url/» convierte la cinta entera en un <a> (262 de 262 px
 * en un celular de 390). Si el gesto que empieza en un enlace se ignora, en la
 * cinta no queda dónde deslizar (revisión B5-R4, 2026-09-23). Y el gesto no
 * puede terminar navegando: el clic que suelte el navegador tras deslizar se
 * cancela; un toque quieto sigue llevando a la página.
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const source = fs.readFileSync( require.resolve( '../../../assets/js/announcement-bar.js' ), 'utf8' );

function nodo( tag, extra = {} ) {
	const attrs = new Map();
	const clases = new Set( extra.clases || [] );
	const n = {
		tagName: tag.toUpperCase(),
		parent: null,
		textContent: extra.texto || '',
		disabled: true,
		classList: {
			add: ( c ) => clases.add( c ),
			remove: ( c ) => clases.delete( c ),
			contains: ( c ) => clases.has( c ),
		},
		getAttribute: ( k ) => ( attrs.has( k ) ? attrs.get( k ) : null ),
		setAttribute: ( k, v ) => attrs.set( k, String( v ) ),
		removeAttribute: ( k ) => attrs.delete( k ),
		hasAttribute: ( k ) => attrs.has( k ),
		querySelectorAll: () => [],
		// Sólo selectores de etiqueta separados por comas, que es lo que usa la barra.
		closest( sel ) {
			const etiquetas = sel.split( ',' ).map( ( s ) => s.trim().toUpperCase() );
			for ( let x = n; x; x = x.parent ) {
				if ( etiquetas.includes( x.tagName ) ) {
					return x;
				}
			}
			return null;
		},
	};
	return n;
}

function entorno() {
	const escuchas = {};
	const bar = nodo( 'div' );
	bar.addEventListener = ( tipo, fn ) => { ( escuchas[ tipo ] = escuchas[ tipo ] || [] ).push( fn ); };
	bar.contains = () => false;

	// Primer mensaje: la cinta entera es el enlace, como la pinta render_bar().
	const msg1 = nodo( 'span', { clases: [ 'akb-ann-msg', 'akb-on', 'akb-ann-msg--link' ], texto: 'Envío gratis' } );
	msg1.parent = bar;
	const enlace = nodo( 'a', { texto: 'Envío gratis' } );
	enlace.parent = msg1;
	const msg2 = nodo( 'span', { clases: [ 'akb-ann-msg' ], texto: 'Retiro sin costo' } );
	msg2.parent = bar;
	const siguiente = nodo( 'button' );
	siguiente.parent = bar;
	siguiente.addEventListener = () => {};
	const contador = nodo( 'span', { texto: '1/2' } );
	const estado = nodo( 'span' );

	bar.querySelectorAll = ( sel ) => ( '.akb-ann-msg' === sel ? [ msg1, msg2 ] : [] );
	bar.querySelector = ( sel ) => ( { '[data-akb-ann-next]': siguiente, '[data-akb-ann-count]': contador, '[data-akb-ann-status]': estado }[ sel ] || null );

	let ahora = 1_000_000;
	const ctx = {
		document: {
			hidden: false,
			getElementById: ( id ) => ( 'akb-ann-bar' === id ? bar : null ),
			addEventListener: () => {},
		},
		// Movimiento reducido: sin rotación automática que mueva el contador sola.
		matchMedia: () => ( { matches: true } ),
		setInterval: () => 1,
		clearInterval: () => {},
		Date: { now: () => ahora },
	};
	ctx.window = ctx;
	vm.runInNewContext( source, ctx );

	function disparar( tipo, evento ) {
		for ( const fn of escuchas[ tipo ] || [] ) {
			fn( evento );
		}
	}
	function deslizar( desde, dx ) {
		disparar( 'touchstart', { target: desde, touches: [ { clientX: 200, clientY: 22 } ] } );
		disparar( 'touchend', { target: desde, changedTouches: [ { clientX: 200 + dx, clientY: 24 } ] } );
	}
	function clic( sobre ) {
		let cancelado = false;
		disparar( 'click', { target: sobre, preventDefault: () => { cancelado = true; } } );
		return cancelado;
	}
	return { contador, enlace, siguiente, deslizar, clic, avanzar: ( ms ) => { ahora += ms; } };
}

test( 'deslizar sobre un mensaje-enlace cambia de anuncio', () => {
	const e = entorno();
	e.deslizar( e.enlace, -125 );
	assert.equal( e.contador.textContent, '2/2', 'la cinta entera es enlace: si el gesto se ignora ahí, no hay dónde deslizar' );
	e.deslizar( e.enlace, 125 );
	assert.equal( e.contador.textContent, '1/2', 'y hacia el otro lado, vuelve' );
} );

test( 'el clic que llegue tras deslizar no navega; un toque quieto sí', () => {
	const e = entorno();
	e.deslizar( e.enlace, -125 );
	assert.equal( e.clic( e.enlace ), true, 'el clic pegado al gesto se cancela' );
	assert.equal( e.clic( e.enlace ), false, 'sólo uno: el siguiente toque es del cliente' );

	const f = entorno();
	f.deslizar( f.enlace, -125 );
	f.avanzar( 5000 );
	assert.equal( f.clic( f.enlace ), false, 'si el navegador no soltó clic, la guarda caduca y no se come un toque posterior' );

	const g = entorno();
	g.deslizar( g.enlace, -10 );
	assert.equal( g.contador.textContent, '1/2', 'un toque casi quieto no es un deslizamiento' );
	assert.equal( g.clic( g.enlace ), false, 'y el toque lleva a la página' );
} );

test( 'el gesto que empieza en la flecha no cuenta como deslizamiento', () => {
	const e = entorno();
	e.deslizar( e.siguiente, -125 );
	assert.equal( e.contador.textContent, '1/2', 'la flecha tiene su propio toque' );
} );
