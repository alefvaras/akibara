'use strict';

/*
 * Cargador de GA4 y Clarity después de la carga (PM-2).
 *
 * La propiedad que importa: ninguna etiqueta se descarga mientras la página
 * carga, y cada una se descarga UNA vez, al quedar libre tras `load` o al primer
 * gesto del cliente — lo que llegue antes.
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const source = fs.readFileSync( require.resolve( '../../../assets/js/analytics-after-load.js' ), 'utf8' );

function entorno( { readyState = 'interactive', idle = true } = {} ) {
	const escuchas = new Map();
	const insertados = [];
	const colaIdle = [];
	const temporizadores = [];

	function placeholder( url ) {
		const attrs = new Map( [ [ 'data-akb-after-load', url ] ] );
		return {
			getAttribute: ( k ) => ( attrs.has( k ) ? attrs.get( k ) : null ),
			removeAttribute: ( k ) => attrs.delete( k ),
			hasAttribute: ( k ) => attrs.has( k ),
		};
	}
	const nodos = [
		placeholder( 'https://www.googletagmanager.com/gtag/js?id=G-X' ),
		placeholder( 'https://www.clarity.ms/tag/abc?ref=wordpress' ),
	];

	const head = { appendChild: ( s ) => insertados.push( s ) };
	const document = {
		readyState,
		head,
		body: head,
		querySelectorAll: ( sel ) => {
			assert.equal( sel, 'script[data-akb-after-load]' );
			return nodos.filter( ( n ) => n.hasAttribute( 'data-akb-after-load' ) );
		},
		createElement: ( tag ) => {
			assert.equal( tag, 'script' );
			const attrs = {};
			return { tag, async: false, src: '', setAttribute: ( k, v ) => { attrs[ k ] = v; }, attrs };
		},
	};
	const window = {
		addEventListener( tipo, fn, opts ) {
			escuchas.set( tipo, { fn, opts } );
		},
		removeEventListener( tipo, fn, capture ) {
			const e = escuchas.get( tipo );
			if ( e && e.fn === fn && ( true === capture ) === Boolean( e.opts && e.opts.capture ) ) {
				escuchas.delete( tipo );
			}
		},
		setTimeout( fn, ms ) {
			temporizadores.push( { fn, ms } );
		},
	};
	if ( idle ) {
		window.requestIdleCallback = ( fn, opts ) => colaIdle.push( { fn, opts } );
	}

	vm.runInNewContext( source, { window, document } );

	return {
		escuchas,
		insertados,
		colaIdle,
		temporizadores,
		disparar( tipo ) {
			const e = escuchas.get( tipo );
			if ( e ) {
				e.fn( { type: tipo } );
			}
		},
	};
}

test( 'mientras la página carga no se descarga nada', () => {
	const env = entorno();
	assert.equal( env.insertados.length, 0 );
	assert.equal( env.colaIdle.length, 0 );
	assert.ok( env.escuchas.has( 'load' ), 'espera a load' );
	for ( const tipo of [ 'pointerdown', 'keydown', 'touchstart', 'scroll', 'wheel' ] ) {
		const e = env.escuchas.get( tipo );
		assert.ok( e, `escucha ${ tipo }` );
		assert.equal( e.opts.passive, true, `${ tipo } pasivo: no puede frenar el desplazamiento` );
		assert.equal( e.opts.capture, true );
	}
} );

test( 'tras load, al quedar libre, descarga las dos etiquetas una sola vez', () => {
	const env = entorno();
	env.disparar( 'load' );
	assert.equal( env.insertados.length, 0, 'load solo agenda: no descarga dentro del mismo tick' );
	assert.equal( env.colaIdle.length, 1 );
	assert.equal( env.colaIdle[ 0 ].opts.timeout, 2000 );

	env.colaIdle[ 0 ].fn();
	assert.deepEqual( env.insertados.map( ( s ) => s.src ), [
		'https://www.googletagmanager.com/gtag/js?id=G-X',
		'https://www.clarity.ms/tag/abc?ref=wordpress',
	] );
	assert.ok( env.insertados.every( ( s ) => true === s.async && '1' === s.attrs[ 'data-no-optimize' ] ) );
	for ( const tipo of [ 'pointerdown', 'keydown', 'touchstart', 'scroll', 'wheel' ] ) {
		assert.ok( ! env.escuchas.has( tipo ), `suelta ${ tipo } tras cargar` );
	}

	env.colaIdle[ 0 ].fn();
	assert.equal( env.insertados.length, 2, 'una segunda llamada no duplica etiquetas' );
} );

test( 'el primer gesto del cliente adelanta la descarga', () => {
	const env = entorno();
	env.disparar( 'touchstart' );
	assert.equal( env.insertados.length, 2 );
	env.disparar( 'load' );
	env.colaIdle.forEach( ( x ) => x.fn() );
	assert.equal( env.insertados.length, 2, 'y load no la repite' );
} );

test( 'si el script llega con la página ya cargada, agenda de inmediato', () => {
	const env = entorno( { readyState: 'complete' } );
	assert.equal( env.colaIdle.length, 1 );
	assert.ok( ! env.escuchas.has( 'load' ) );
} );

test( 'sin requestIdleCallback (Safari) cae a un setTimeout corto', () => {
	const env = entorno( { idle: false } );
	env.disparar( 'load' );
	assert.equal( env.temporizadores.length, 1 );
	assert.ok( env.temporizadores[ 0 ].ms <= 500 );
	env.temporizadores[ 0 ].fn();
	assert.equal( env.insertados.length, 2 );
} );
