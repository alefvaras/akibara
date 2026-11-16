'use strict';
const assert = require( 'node:assert/strict' );
const { test } = require( 'node:test' );
const { execFileSync } = require( 'node:child_process' );
const { createDOM } = require( '../../../../../../tools/testing/dom-harness.js' );
const source = 'public_html/wp-content/plugins/akibara-business/assets/js/purchase-incentives.js';
const config = () => ( { rewards: { points: 2, amount: 100, multiplier: 1, program_url: '/puntos/' }, shipping: { enabled: true, threshold: 60000 } } );

function start( t ) {
	const dom = createDOM( { html: '<div id="preview" aria-live="polite"></div>' } );
	t.after( () => dom.teardown() );
	dom.window.fetch = () => { throw new Error( 'A selection preview cannot make a request.' ); };
	dom.loadScript( source );
	return { ...dom, api: dom.window.akbPurchaseIncentives };
}

test( 'point projection agrees with the real PHP Calculator and Tiers for both rounding stages', ( t ) => {
	const { api } = start( t );
	const cases = [];
	for ( const total of [ 0, 1, 24.5, 25, 26, 74, 75, 99, 101, 1234, 12674, 12690, 59751 ] ) {
		for ( const multiplier of [ 1, 1.2, 1.25, 1.5 ] ) {
			for ( const [ points, amount ] of [ [ 2, 100 ], [ 3, 77 ], [ 1, 1 ], [ 0, 100 ], [ 2, 0 ] ] ) {
				cases.push( { total, multiplier, points, amount } );
			}
		}
	}
	const oracle = JSON.parse( execFileSync( 'php', [ '-r', `
		define('ABSPATH', getcwd() . '/public_html/');
		require 'public_html/wp-content/plugins/akibara-rewards/src/Points/Calculator.php';
		require 'public_html/wp-content/plugins/akibara-rewards/src/Points/Tiers.php';
		$cases = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
		$results = array_map(static fn($case) => \\Akibara\\Rewards\\Points\\Tiers::apply_multiplier(
			\\Akibara\\Rewards\\Points\\Calculator::points_for_base((float)$case['total'], $case['points'], $case['amount']), (float)$case['multiplier']), $cases);
		echo json_encode($results, JSON_THROW_ON_ERROR);
	` ], { input: JSON.stringify( cases ), encoding: 'utf8' } ) );
	cases.forEach( ( item, index ) => {
		const settings = config();
		Object.assign( settings.rewards, item );
		const model = api.estimate( { config: settings, products: [ { id: 1, price: item.total } ] } );
		assert.equal( model.points, oracle.at( index ), JSON.stringify( item ) );
	} );
} );

test( 'fresh cart state excludes existing IDs and uses the resolved cart threshold and total', ( t ) => {
	const { api } = start( t );
	const model = api.estimate( {
		config: config(), products: [ { id: 1, price: 15000 }, { id: 2, price: 20000 }, { id: 2, price: 20000 } ],
		cartState: { product_ids: [ 1 ], rewards: { points: 650 }, shipping: { state: 'progress', reason: 'order_amount', threshold: 75000, total: 40000 } }
	} );
	assert.equal( model.total, 20000 );
	assert.equal( model.points, 400 );
	assert.equal( model.remaining, 15000 );
	assert.equal( model.percent, 80 );
	assert.ok( model.shipping_text.startsWith( 'Carrito + selección:' ) );
	assert.ok( model.cart_points_text.includes( '650 Puntos Akibara' ) );
} );

test( 'public selection scope stays explicit and reaching a reference never promises eligibility', ( t ) => {
	const { api, document } = start( t );
	const model = api.estimate( { config: config(), products: [ { id: 1, price: 65000 } ] } );
	api.renderPreview( document.getElementById( 'preview' ), model );
	assert.equal( model.remaining, 0 );
	assert.ok( model.shipping_text.startsWith( 'Esta selección:' ) );
	assert.ok( model.note.includes( 'según tu dirección y los métodos disponibles' ) );
	assert.equal( document.querySelector( 'progress' ).value, 100 );
	assert.equal( document.querySelector( 'a' ).getAttribute( 'href' ), 'https://akibara.cl/puntos/' );
	assert.equal( document.querySelector( 'details' ).open, false );
	assert.equal( document.querySelector( 'details summary' ).textContent, 'Ver condiciones' );
	assert.ok( document.body.textContent.includes( '≈ 1.300 Puntos Akibara' ) );
	assert.ok( ! document.body.textContent.includes( 'desbloqueado' ) );
} );

test( 'an invalid configured program URL does not interrupt selection rendering', ( t ) => {
	const { api, document } = start( t );
	const model = api.estimate( { config: config(), products: [ { id: 1, price: 10000 } ] } );
	model.program_url = 'https://[';
	assert.doesNotThrow( () => api.renderPreview( document.getElementById( 'preview' ), model ) );
	assert.equal( document.querySelectorAll( 'a' ).length, 0 );
	assert.ok( document.body.textContent.includes( '200 Puntos Akibara' ) );
} );

test( 'shipping is not projected through pickup, coupon-only or unavailable context', ( t ) => {
	const { api } = start( t );
	for ( const shipping of [ { state: 'hidden', reason: 'local_pickup' }, { state: 'qualified', reason: 'coupon' }, { state: 'hidden', reason: 'unavailable' } ] ) {
		const model = api.estimate( { config: config(), products: [ { id: 1, price: 65000 } ], cartState: { product_ids: [ 999 ], shipping } } );
		assert.equal( model.shipping_text, '' );
		assert.equal( model.percent, null );
	}
} );

test( 'an empty cart may show the public selection reference without inventing a resolved shipment', ( t ) => {
	const { api } = start( t );
	const model = api.estimate( {
		config: config(), products: [ { id: 1, price: 10000 } ],
		cartState: { product_ids: [], shipping: { state: 'hidden', reason: 'unavailable', total: 0 } }
	} );
	assert.equal( model.remaining, 50000 );
	assert.ok( model.shipping_text.startsWith( 'Esta selección:' ) );
	assert.ok( model.note.includes( 'se confirma según tu dirección' ) );
} );

test( 'disabled benefits and empty selections remove the preview without stale content', ( t ) => {
	const { api, document } = start( t );
	const container = document.getElementById( 'preview' );
	api.renderPreview( container, api.estimate( { config: config(), products: [ { id: 1, price: 10000 } ] } ) );
	assert.equal( container.hidden, false );
	const off = config();
	off.rewards.points = 0;
	off.shipping.enabled = false;
	api.renderPreview( container, api.estimate( { config: off, products: [ { id: 1, price: 10000 } ] } ) );
	assert.equal( container.hidden, true );
	assert.equal( container.textContent, '' );
	api.renderPreview( container, api.estimate( { config: config(), products: [] } ) );
	assert.equal( container.hidden, true );
} );

test( 'program links and text cannot inject script or markup into the shared renderer', ( t ) => {
	const { api, document } = start( t );
	const model = api.estimate( { config: config(), products: [ { id: 1, price: 10000 } ] } );
	model.points_text = '<img src=x onerror=alert(1)>';
	model.program_url = 'javascript:alert(1)';
	api.renderPreview( document.getElementById( 'preview' ), model );
	assert.equal( document.querySelectorAll( 'img,script,a' ).length, 0 );
	assert.ok( document.body.textContent.includes( '<img src=x onerror=alert(1)>' ) );
} );
