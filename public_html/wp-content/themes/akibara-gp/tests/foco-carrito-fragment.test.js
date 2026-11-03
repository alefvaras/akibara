'use strict';

/*
 * El icono del carrito de la cabecera es un fragment de WooCommerce: cada
 * refresco lo cambia por un nodo nuevo. Si tenía el foco, el foco caía al
 * <body> (medido en el espejo, lote X1): sin anillo, y el siguiente Tab volvía a
 * caer en el mismo icono. main.js lo devuelve al icono nuevo, y sólo en ese caso.
 *
 * Se ejecuta el bloque real de main.js en un `vm` con un documento de mentira.
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const main = fs.readFileSync( path.resolve( __dirname, '..', 'assets/js/main.js' ), 'utf8' );
const inicio = main.indexOf( '/* El icono del carrito de la cabecera es un fragment' );
const cierre = 'doc.body.addEventListener(evt, devolverFocoAlCarrito);';
const fin = main.indexOf( cierre, inicio );
const bloque = inicio < 0 || fin < 0 ? '' : main.slice( inicio, main.indexOf( '});', fin ) + 3 );

function entorno() {
	const enDocumento = new Set();
	const nodo = ( clase ) => {
		const n = {
			clase,
			get isConnected() { return enDocumento.has( n ); },
			enfocado: null,
			matches: ( s ) => '.header-icon--cart' === s && 'carrito' === clase,
			focus( opciones ) { estado.activo = n; n.enfocado = opciones; },
		};
		enDocumento.add( n );
		return n;
	};
	const oyentesDoc = {};
	const oyentesBody = {};
	const body = { addEventListener: ( ev, fn ) => { oyentesBody[ ev ] = fn; } };
	const estado = { activo: body, carrito: null };
	const doc = {
		body,
		get activeElement() { return estado.activo; },
		addEventListener: ( ev, fn ) => { oyentesDoc[ ev ] = fn; },
		contains: ( n ) => enDocumento.has( n ),
		querySelector: ( s ) => ( '.header-icon--cart' === s ? estado.carrito : null ),
	};
	const tareas = [];
	const window = { setTimeout: ( fn ) => { tareas.push( fn ); return tareas.length; } };
	const vaciarTareas = () => { while ( tareas.length ) { tareas.shift()(); } };
	vm.runInContext( '(function(){' + bloque + '})();', vm.createContext( { doc, window } ) );
	estado.carrito = nodo( 'carrito' );
	return {
		estado,
		nodo,
		// El usuario lleva el foco a `n` (Tab o clic).
		enfocar( n ) {
			if ( estado.activo !== body ) {
				oyentesDoc.focusout( { target: estado.activo, relatedTarget: n } );
			}
			estado.activo = n;
			oyentesDoc.focusin( { target: n } );
		},
		// Un clic en una zona sin foco de la página: el foco pasa al body.
		soltar() {
			const antes = estado.activo;
			estado.activo = body;
			oyentesDoc.focusout( { target: antes, relatedTarget: null } );
			vaciarTareas();
		},
		// WooCommerce cambia el icono por uno nuevo (jQuery replaceWith). Como
		// Chromium 152, medido en el espejo: si tenía el foco, el foco cae al body y
		// avisa `focusout` (sin relatedTarget) con el nodo TODAVÍA conectado; después
		// sale del documento y WooCommerce avisa el refresco, todo en la misma tarea.
		refrescar( evento = 'akb:wc_fragments_refreshed' ) {
			const viejo = estado.carrito;
			if ( estado.activo === viejo ) {
				estado.activo = body;
				oyentesDoc.focusout( { target: viejo, relatedTarget: null } );
			}
			enDocumento.delete( viejo );
			estado.carrito = nodo( 'carrito' );
			oyentesBody[ evento ]();
			vaciarTareas();
		},
		body,
	};
}

test( 'el bloque existe en main.js', () => {
	assert.ok( bloque.length > 0 );
	assert.match( bloque, /devolverFocoAlCarrito/ );
} );

test( 'X1: con el foco en el icono, el refresco de fragments lo devuelve al icono nuevo', () => {
	for ( const evento of [ 'akb:wc_fragments_refreshed', 'akb:wc_fragments_loaded' ] ) {
		const e = entorno();
		const viejo = e.estado.carrito;
		e.enfocar( viejo );
		e.refrescar( evento );
		assert.notEqual( e.estado.carrito, viejo );
		assert.equal( e.estado.activo, e.estado.carrito, `${ evento }: el foco caía al body` );
		assert.deepEqual( { ...e.estado.carrito.enfocado }, { preventScroll: true } );
	}
} );

test( 'si el foco estaba en otro sitio, el refresco no lo mueve', () => {
	const e = entorno();
	const campo = e.nodo( 'campo' );
	e.enfocar( e.estado.carrito );
	e.enfocar( campo );
	e.refrescar();
	assert.equal( e.estado.activo, campo );
} );

test( 'sin foco previo (nadie tocó nada), el refresco al cargar no enfoca nada', () => {
	const e = entorno();
	e.refrescar();
	assert.equal( e.estado.activo, e.body );
} );

test( 'si el cliente soltó el foco él (clic en la página), un refresco posterior no lo devuelve al icono', () => {
	const e = entorno();
	e.enfocar( e.estado.carrito );
	e.soltar();
	e.refrescar();
	assert.equal( e.estado.activo, e.body );
} );
