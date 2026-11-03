'use strict';

/*
 * La hoja del aviso del corazón para invitados la pide el script, y sólo si el
 * aviso existe para este visitante (QA integral 2026-09-24, QA-RC-4).
 *
 * Iba en wishlist.css, que se sirve bloqueante en cada página con corazones; el
 * aviso está apagado de fábrica y sólo lo ve un invitado que toca un corazón.
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const source = fs.readFileSync( require.resolve( '../../../assets/js/wishlist.js' ), 'utf8' );
const HOJA = 'https://akibara.test/wp-content/plugins/akibara-business/assets/css/wishlist-guest-prompt.css?ver=abc';

function arrancar( cfg, { conDialog = true } = {} ) {
	const enHead = [];
	const porId = {};
	const ctx = {
		akbWishlist: Object.assign( { isLogged: false, guestPrompt: true, loginUrl: '/mi-cuenta/', labels: {}, guestStyle: HOJA }, cfg ),
		HTMLDialogElement: conDialog ? Object.assign( function () {}, { prototype: { showModal() {} } } ) : undefined,
		location: { href: 'http://localhost:8080/tienda/' },
		sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		document: {
			readyState: 'complete',
			addEventListener() {},
			getElementById: ( id ) => porId[ id ] || null,
			createElement: ( tag ) => ( { tagName: tag.toUpperCase() } ),
			head: {
				appendChild: ( el ) => {
					enHead.push( el );
					porId[ el.id ] = el;
				},
			},
			body: { appendChild() {} },
			documentElement: { classList: { add() {}, remove() {} } },
		},
		encodeURIComponent,
	};
	ctx.window = ctx;
	vm.runInNewContext( source, ctx );
	return { enHead, ctx };
}

test( 'invitado con el aviso encendido: pide la hoja una vez, sin bloquear el pintado', () => {
	const { enHead } = arrancar( {} );
	assert.equal( enHead.length, 1 );
	assert.equal( enHead[ 0 ].tagName, 'LINK' );
	assert.equal( enHead[ 0 ].rel, 'stylesheet' );
	assert.equal( enHead[ 0 ].id, 'akb-wishlist-guest-css' );
	assert.equal( enHead[ 0 ].href, HOJA );
} );

test( 'sin aviso para este visitante no se pide nada', () => {
	assert.equal( arrancar( { guestPrompt: false } ).enHead.length, 0, 'aviso apagado (de fábrica)' );
	assert.equal( arrancar( { isLogged: true } ).enHead.length, 0, 'con sesión el corazón guarda directo' );
	assert.equal( arrancar( {}, { conDialog: false } ).enHead.length, 0, 'sin <dialog> se navega al acceso como siempre' );
	assert.equal( arrancar( { guestStyle: '' } ).enHead.length, 0, 'sin la dirección de la hoja no se inventa una' );
} );
