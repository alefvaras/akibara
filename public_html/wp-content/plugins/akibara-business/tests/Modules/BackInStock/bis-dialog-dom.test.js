'use strict';
const assert = require( 'node:assert/strict' );
const { test } = require( 'node:test' );
const { createDOM } = require( '../../../../../../../tools/testing/dom-harness.js' );
const source = 'public_html/wp-content/plugins/akibara-business/assets/js/bis-form.js';
const tick = () => new Promise( ( resolve ) => setImmediate( resolve ) );
const response = ( code, status = 201 ) => ( { ok: status >= 200 && status < 300, status, text: async () => JSON.stringify( { code } ) } );

function start( t, fetch = async () => response( 'subscribed' ) ) {
	const dom = createDOM( { html: '<a href="/a/" class="akb-notify-link" data-product-id="101" data-product-name="Tomo 1">Aviso 1</a><a href="/b/" class="akb-notify-link" data-product-id="102" data-product-name="Tomo 2">Aviso 2</a>', globals: { akbBis: { endpoint: '/back-in-stock' } } } );
	t.after( () => dom.teardown() );
	dom.window.fetch = fetch;
	dom.loadScript( source );
	dom.click( '[data-product-id="101"]' );
	return { dom, dialog: dom.document.querySelector( 'dialog' ) };
}

function submit( dom, dialog ) {
	dom.fill( '.akb-bis-dialog__email', 'qa@example.test' );
	dom.dispatch( dialog.querySelector( 'form' ), 'submit' );
}

test( 'the shared dialog closes by its button and Escape and restores the trigger focus', ( t ) => {
	const { dom, dialog } = start( t );
	assert.equal( dialog.open, true );
	dom.click( '.akb-bis-dialog__close' );
	assert.equal( dialog.open, false );
	assert.equal( dom.document.activeElement.dataset.productId, '101' );
	dom.click( '[data-product-id="102"]' );
	dialog.dispatchEvent( new dom.window.Event( 'cancel', { cancelable: true } ) );
	assert.equal( dialog.open, false );
	assert.equal( dom.document.activeElement.dataset.productId, '102' );
} );

test( 'a late response after closing never marks a different product or hides its form', async ( t ) => {
	let finish;
	const { dom, dialog } = start( t, () => new Promise( ( resolve ) => { finish = resolve; } ) );
	submit( dom, dialog );
	dom.click( '.akb-bis-dialog__close' );
	dom.click( '[data-product-id="102"]' );
	finish( response( 'subscribed' ) );
	await tick();
	assert.equal( dialog.open, true );
	assert.equal( dialog.querySelector( '[name="product_id"]' ).value, '102' );
	assert.equal( dialog.querySelector( 'form' ).hidden, false );
	assert.equal( dom.document.querySelector( '[data-product-id="102"]' ).classList.contains( 'is-subscribed' ), false );
} );

test( 'closing after a card repaint restores focus to the same product rather than a detached trigger', ( t ) => {
	const { dom, dialog } = start( t );
	const original = dom.document.querySelector( '[data-product-id="101"]' );
	const replacement = original.cloneNode( true );
	original.replaceWith( replacement );
	dom.click( '.akb-bis-dialog__close' );
	assert.equal( dialog.open, false );
	assert.equal( dom.document.activeElement === replacement, true );
} );

test( 'a confirmed subscription updates the repainted card of its product only', async ( t ) => {
	let finish;
	const { dom, dialog } = start( t, () => new Promise( ( resolve ) => { finish = resolve; } ) );
	submit( dom, dialog );
	const original = dom.document.querySelector( '[data-product-id="101"]' );
	const replacement = original.cloneNode( true );
	original.replaceWith( replacement );
	finish( response( 'subscribed' ) );
	await tick();
	assert.equal( replacement.classList.contains( 'is-subscribed' ), true );
	assert.equal( dom.document.querySelector( '[data-product-id="102"]' ).classList.contains( 'is-subscribed' ), false );
} );

test( 'HTTP failure cannot impersonate success and an existing subscription is informative', async ( t ) => {
	const responses = [ response( 'subscribed', 500 ), response( 'already_subscribed', 409 ) ];
	const { dom, dialog } = start( t, async () => responses.shift() );
	submit( dom, dialog );
	await tick();
	const message = dialog.querySelector( '.akb-bis-dialog__msg' );
	assert.equal( message.getAttribute( 'role' ), 'alert' );
	assert.equal( dialog.querySelector( 'form' ).hidden, false );
	submit( dom, dialog );
	await tick();
	assert.equal( message.getAttribute( 'role' ), 'status' );
	assert.ok( message.textContent.includes( 'Ya tienes un aviso' ) );
} );

test( 'opening the dialog does not subscribe and repeat submit sends only one request', async ( t ) => {
	let calls = 0;
	const { dom, dialog } = start( t, async () => { calls++; return response( 'subscribed' ); } );
	assert.equal( calls, 0 );
	submit( dom, dialog );
	submit( dom, dialog );
	await tick();
	assert.equal( calls, 1 );
	assert.equal( dialog.querySelector( 'form' ).hidden, true );
	assert.equal( dom.document.querySelector( '[data-product-id="101"]' ).classList.contains( 'is-subscribed' ), true );
} );
