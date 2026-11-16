'use strict';
const assert = require( 'node:assert/strict' );
const { test } = require( 'node:test' );
const { createDOM } = require( '../../../../../../../tools/testing/dom-harness.js' );
const source = 'public_html/wp-content/plugins/akibara-business/assets/js/batch-cart.js';
const args = ( productIds ) => ( { endpoint: '/wp-admin/admin-ajax.php', nonce: 'test-nonce', productIds } );
const reply = ( ids, overrides = {} ) => ( { ok: true, json: async () => ( { success: true, data: { added_count: ids.length, added_ids: ids, already_ids: [], failed_ids: [], ...overrides } } ) } );
const tick = () => new Promise( ( resolve ) => setImmediate( resolve ) );

function start( t, fetch ) {
	const dom = createDOM();
	t.after( () => dom.teardown() );
	dom.window.fetch = fetch;
	dom.loadScript( source );
	return dom;
}

test( 'shared purchase client sends unique IDs, one nonce and opens the existing cart once', async ( t ) => {
	const requests = [];
	const dom = start( t, async ( url, options ) => { requests.push( { url, options } ); return reply( [ 101, 102 ] ); } );
	let opened = 0;
	let invalidated = 0;
	dom.window.akbCart = { refresh: ( force ) => { assert.equal( force, true ); invalidated++; }, open: () => { opened++; } };
	await dom.window.akbBatchCart.add( args( [ 101, '102', 101 ] ) );
	const body = new URLSearchParams( requests[ 0 ].options.body );
	assert.deepEqual( body.getAll( 'product_ids[]' ), [ '101', '102' ] );
	assert.equal( body.get( 'nonce' ), 'test-nonce' );
	assert.equal( opened, 1 );
	assert.equal( invalidated, 1 );
} );

test( 'invalid or oversized selections never mutate the cart', async ( t ) => {
	let requests = 0;
	const dom = start( t, async () => { requests++; return reply( [] ); } );
	await assert.rejects( dom.window.akbBatchCart.add( args( [ -1 ] ) ) );
	await assert.rejects( dom.window.akbBatchCart.add( args( [] ) ) );
	await assert.rejects( dom.window.akbBatchCart.add( args( Array.from( { length: 151 }, ( _, i ) => i + 1 ) ) ) );
	assert.equal( requests, 0 );
} );

test( 'cart mutations are serialized and a failed request does not stop the next purchase', async ( t ) => {
	const requests = [];
	let rejectFirst;
	const dom = start( t, ( _url, options ) => {
		requests.push( new URLSearchParams( options.body ).getAll( 'product_ids[]' ) );
		return requests.length === 1 ? new Promise( ( _resolve, reject ) => { rejectFirst = reject; } ) : Promise.resolve( reply( [ 102 ] ) );
	} );
	const first = dom.window.akbBatchCart.add( args( [ 101 ] ) );
	const rejected = assert.rejects( first );
	const second = dom.window.akbBatchCart.add( args( [ 102 ] ) );
	await tick();
	assert.equal( requests.length, 1 );
	rejectFirst( new Error( 'offline' ) );
	await rejected;
	await second;
	assert.deepEqual( requests, [ [ '101' ], [ '102' ] ] );
} );

test( 'HTTP errors and incomplete acknowledgments never report cart success', async ( t ) => {
	const responses = [ { ok: false, json: async () => ( { success: true, data: { message: 'No disponible' } } ) }, reply( [] ) ];
	const dom = start( t, async () => responses.shift() );
	let updates = 0;
	dom.document.body.addEventListener( 'akb:cart:updated', () => { updates++; } );
	await assert.rejects( dom.window.akbBatchCart.add( args( [ 101 ] ) ), /No disponible/ );
	await assert.rejects( dom.window.akbBatchCart.add( args( [ 101 ] ) ), /incompleta/ );
	assert.equal( updates, 0 );
} );
