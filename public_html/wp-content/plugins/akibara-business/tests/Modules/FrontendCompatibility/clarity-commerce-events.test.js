'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const assetPath = require.resolve( '../../../assets/js/clarity-commerce-events.js' );
const source = fs.readFileSync( assetPath, 'utf8' );
const storageKey = 'akb_ai_attribution';
const allowedSources = [ 'chatgpt', 'copilot', 'perplexity', 'claude', 'gemini' ];

function createClock( initial = 1000000 ) {
	let value = initial;

	return {
		advance( milliseconds ) {
			value += milliseconds;
			return value;
		},
		now() {
			return value;
		},
		set( next ) {
			value = next;
		}
	};
}

function createEventTarget() {
	const listeners = new Map();

	return {
		addEventListener( type, callback, options ) {
			const entries = listeners.get( type ) || [];
			entries.push( { callback, once: Boolean( options && options.once ) } );
			listeners.set( type, entries );
		},
		dispatch( type, event = {} ) {
			const entries = ( listeners.get( type ) || [] ).slice();
			for ( const entry of entries ) {
				entry.callback( { type, ...event } );
				if ( entry.once ) {
					const current = listeners.get( type ) || [];
					listeners.set( type, current.filter( ( item ) => item !== entry ) );
				}
			}
		},
		listenerCount( type ) {
			return ( listeners.get( type ) || [] ).length;
		},
		totalListenerCount() {
			return Array.from( listeners.values() )
				.reduce( ( total, entries ) => total + entries.length, 0 );
		}
	};
}

function createStorage( options = {} ) {
	const values = new Map();
	const writes = [];
	const removals = [];
	const blocked = Boolean( options.blocked );
	const reads = [];
	const getBlocked = blocked || Boolean( options.getBlocked );
	const setBlocked = blocked || Boolean( options.setBlocked );
	const removeBlocked = blocked || Boolean( options.removeBlocked );

	if ( Object.prototype.hasOwnProperty.call( options, 'raw' ) ) {
		values.set( storageKey, options.raw );
	}

	return {
		getItem( key ) {
			reads.push( key );
			if ( getBlocked ) {
				throw new Error( 'storage blocked' );
			}
			return values.has( key ) ? values.get( key ) : null;
		},
		setItem( key, value ) {
			if ( setBlocked ) {
				throw new Error( 'storage blocked' );
			}
			values.set( key, value );
			writes.push( [ key, value ] );
		},
		removeItem( key ) {
			if ( removeBlocked ) {
				throw new Error( 'storage blocked' );
			}
			values.delete( key );
			removals.push( key );
		},
		raw() {
			return values.get( storageKey );
		},
		record() {
			const raw = values.get( storageKey );
			return raw ? JSON.parse( raw ) : null;
		},
		reads,
		removals,
		writes
	};
}

/**
 * Doble del registro `wp.data` con los dos stores de WooCommerce Blocks que lee
 * el guion (S-b9250562, 2026-09-19). Modela lo que hace WooCommerce 11.1.0 en
 * `wc-blocks-data.js`, no lo que sería cómodo:
 *
 *  - `applyCoupon` despacha `receiveApplyingCoupon(code)`, espera a la Store
 *    API, despacha `receiveCartContents(respuesta)` si fue bien —o
 *    `receiveError()` si no— y SIEMPRE cierra con `receiveApplyingCoupon('')`.
 *    Cada despacho es un aviso a los suscriptores.
 *  - El reductor del checkout pone `hasError: false` al entrar en
 *    BEFORE_PROCESSING y en PROCESSING; el procesador lo pone a true cuando el
 *    intento falla. Medido en el espejo con el formulario vacío, un solo clic
 *    en «Realizar el pedido» recorre `before_processing:false → idle:true →
 *    processing:false → idle:true` en ~40 ms: DOS subidas de `hasError` por
 *    intento, no una.
 *  - `select()` de un store sin registrar devuelve `undefined`.
 */
function createBlocksRegistry( options = {} ) {
	const listeners = [];
	const stores = {};
	const state = { applyingCoupon: '', coupons: [], hasError: false };
	const notify = () => listeners.slice().forEach( ( listener ) => listener() );
	const registry = {
		data: {
			select: ( name ) => stores[ name ],
			subscribe( listener ) {
				listeners.push( listener );
				return () => {};
			}
		},
		listenerCount: () => listeners.length,
		registerStores() {
			stores[ 'wc/store/cart' ] = {
				getCartData: () => ( { coupons: state.coupons } ),
				isApplyingCoupon: () => Boolean( state.applyingCoupon )
			};
			stores[ 'wc/store/checkout' ] = { hasError: () => state.hasError };
			notify();
		},
		dispatch( patch ) {
			Object.assign( state, patch );
			notify();
		},
		applyCoupon( code, accepted, replacesOthers = false ) {
			registry.dispatch( { applyingCoupon: code } );
			registry.dispatch(
				accepted
					? { coupons: ( replacesOthers ? [] : state.coupons ).concat( { code } ) }
					: {}
			);
			registry.dispatch( { applyingCoupon: '' } );
		},
		failPlaceOrder() {
			registry.dispatch( { hasError: false } );
			registry.dispatch( { hasError: true } );
			registry.dispatch( { hasError: false } );
			registry.dispatch( { hasError: true } );
		}
	};

	if ( options.registered !== false ) {
		registry.registerStores();
	}

	return registry;
}

function boot( options = {} ) {
	const calls = options.calls || [];
	const clock = options.clock || createClock();
	const storage = options.storage || createStorage();
	const timers = [];
	const body = createEventTarget();
	const documentEvents = createEventTarget();
	const location = new URL( options.url || 'https://akibara.cl/tienda/' );
	const navigationType = options.navigationType || 'navigate';
	const document = {
		body,
		readyState: options.readyState || 'complete',
		referrer: options.referrer || '',
		addEventListener: documentEvents.addEventListener
	};
	const window = {
		URL,
		URLSearchParams,
		akibaraClarityCommerce: { initialEvent: options.initialEvent || '', ...options.config },
		location,
		performance: {
			getEntriesByType( type ) {
				return type === 'navigation' ? [ { type: navigationType } ] : [];
			},
			navigation: { type: navigationType === 'reload' ? 1 : 0 }
		},
		sessionStorage: storage,
		setTimeout( callback ) {
			timers.push( callback );
			return timers.length;
		}
	};
	const installClarity = ( implementation ) => {
		window.clarity = implementation || ( ( ...args ) => calls.push( args ) );
	};

	if ( options.clarityReady !== false ) {
		installClarity( options.clarity );
	}

	if ( options.wpData ) {
		window.wp = { data: options.wpData };
	}

	vm.runInNewContext( source, {
		Date: { now: clock.now },
		document,
		window
	}, { filename: assetPath } );

	return {
		body,
		calls,
		clock,
		documentEvents,
		installClarity,
		storage,
		timers,
		runNextTimer() {
			if ( ! timers.length ) {
				return false;
			}
			timers.shift()();
			return true;
		},
		runTimers( limit = 100 ) {
			let executed = 0;
			while ( timers.length && executed < limit ) {
				timers.shift()();
				executed++;
			}
			return executed;
		}
	};
}

function expectedLandingCalls( sourceName, evidence = 'utm' ) {
	return [
		[ 'set', 'akb_ai_source', sourceName ],
		[ 'set', 'akb_ai_evidence', evidence ],
		[ 'event', 'ai_referral_landing' ]
	];
}

function assertExactRecord( record ) {
	assert.deepEqual(
		Object.keys( record ).sort(),
		[ 'evidence', 'landingAt', 'landingSentAt', 'lastActivityAt', 'source' ]
	);
	assert.ok( allowedSources.includes( record.source ) );
	assert.ok( [ 'referrer', 'utm' ].includes( record.evidence ) );
}

test( 'uses closed source and evidence cardinalities with safe host boundaries', () => {
	const utmAliases = {
		chatgpt: [ 'chatgpt', 'chatgpt.com' ],
		copilot: [ 'copilot', 'copilot.microsoft.com' ],
		perplexity: [ 'perplexity', 'perplexity.ai' ],
		claude: [ 'claude', 'claude.ai' ],
		gemini: [ 'gemini', 'gemini.google.com' ]
	};
	const hosts = {
		chatgpt: 'chatgpt.com',
		copilot: 'copilot.microsoft.com',
		perplexity: 'perplexity.ai',
		claude: 'claude.ai',
		gemini: 'gemini.google.com'
	};
	const observedEvidence = new Set();

	assert.deepEqual( Object.keys( utmAliases ), allowedSources );
	assert.match(
		source,
		/var aiSources = \[ 'chatgpt', 'copilot', 'perplexity', 'claude', 'gemini' \];/
	);

	for ( const sourceName of allowedSources ) {
		for ( const alias of utmAliases[ sourceName ] ) {
			const harness = boot( {
				url: `https://akibara.cl/?utm_source=${ encodeURIComponent( alias.toUpperCase() ) }`
			} );
			assert.deepEqual( harness.calls, expectedLandingCalls( sourceName ) );
			observedEvidence.add( harness.calls[1][2] );
			assertExactRecord( harness.storage.record() );
		}

		for ( const host of [ hosts[ sourceName ], `share.${ hosts[ sourceName ] }` ] ) {
			const harness = boot( { referrer: `https://${ host }/answer` } );
			assert.deepEqual( harness.calls, expectedLandingCalls( sourceName, 'referrer' ) );
			observedEvidence.add( harness.calls[1][2] );
			assert.equal( harness.storage.record().evidence, 'referrer' );
		}
	}
	assert.deepEqual( [ ...observedEvidence ].sort(), [ 'referrer', 'utm' ] );

	for ( const candidate of [
		{ referrer: 'https://evilchatgpt.com/answer' },
		{ referrer: 'https://chatgpt.com.evil.test/answer' },
		{ referrer: 'https://openai.com/chatgpt' },
		{ referrer: 'https://google.com/gemini' },
		{ url: 'https://akibara.cl/?utm_source=chatgpt-newsletter' },
		{ url: 'https://akibara.cl/?utm_source=constructor' },
		{ url: 'https://akibara.cl/?utm_source=ai' }
	] ) {
		const harness = boot( candidate );
		assert.deepEqual( harness.calls, [] );
		assert.equal( harness.storage.raw(), undefined );
	}
} );

test( 'official referrer wins a conflicting allowlisted UTM', () => {
	const harness = boot( {
		url: 'https://akibara.cl/?utm_source=chatgpt',
		referrer: 'https://claude.ai/share/answer'
	} );
	const record = harness.storage.record();

	assert.deepEqual( harness.calls, expectedLandingCalls( 'claude', 'referrer' ) );
	assert.equal( record.source, 'claude' );
	assert.equal( record.evidence, 'referrer' );
	assert.equal( record.landingSentAt, record.landingAt );
} );

test( 'normal traffic installs no AI activity listeners and performs no storage read', () => {
	const storage = createStorage();
	const harness = boot( {
		storage,
		url: 'https://akibara.cl/?utm_source=google',
		referrer: 'https://www.google.com/search?q=manga'
	} );

	assert.deepEqual( harness.calls, [] );
	assert.deepEqual( storage.reads, [] );
	for ( const eventName of [ 'pointerdown', 'keydown', 'touchstart', 'scroll' ] ) {
		assert.equal( harness.documentEvents.listenerCount( eventName ), 0 );
	}
} );

test( 'reload with UTM across documents reuses the landing without another event', () => {
	const clock = createClock( 2000000 );
	const storage = createStorage();
	const first = boot( {
		clock,
		storage,
		url: 'https://akibara.cl/?utm_source=chatgpt.com'
	} );
	const firstRecord = storage.record();

	assert.deepEqual( first.calls, expectedLandingCalls( 'chatgpt' ) );
	clock.advance( 5000 );

	const reload = boot( {
		clock,
		storage,
		url: 'https://akibara.cl/?utm_source=chatgpt.com',
		referrer: 'https://chatgpt.com/share/original',
		navigationType: 'reload'
	} );

	assert.deepEqual( reload.calls, [
		[ 'set', 'akb_ai_source', 'chatgpt' ],
		[ 'set', 'akb_ai_evidence', 'utm' ]
	] );
	assert.equal( storage.record().landingAt, firstRecord.landingAt );
	assert.equal( storage.record().landingSentAt, firstRecord.landingSentAt );
	assert.equal( storage.record().lastActivityAt, clock.now() );
} );

test( 'pending landing survives internal navigation and late Clarity without duplicates', () => {
	const calls = [];
	const clock = createClock( 3000000 );
	const storage = createStorage();
	const landing = boot( {
		calls,
		clarityReady: false,
		clock,
		storage,
		url: 'https://akibara.cl/?utm_source=perplexity'
	} );
	const pendingRecord = storage.record();

	assert.equal( pendingRecord.landingSentAt, 0 );
	assert.equal( landing.timers.length, 1 );
	clock.advance( 10000 );

	const internal = boot( {
		calls,
		clarityReady: false,
		clock,
		storage,
		url: 'https://akibara.cl/producto/manga/',
		referrer: 'https://akibara.cl/tienda/'
	} );
	assert.equal( storage.record().landingAt, pendingRecord.landingAt );

	internal.installClarity();
	internal.runTimers();
	assert.deepEqual( calls, expectedLandingCalls( 'perplexity' ) );
	assert.equal( storage.record().landingSentAt, storage.record().landingAt );

	const callCount = calls.length;
	landing.installClarity();
	landing.runTimers();
	assert.equal( calls.length, callCount, 'the old pending document must observe the shared acknowledgement' );
} );

test( 'an old retry cannot duplicate a landing sent by a newer document', () => {
	const calls = [];
	const clock = createClock( 4000000 );
	const storage = createStorage();
	const oldDocument = boot( {
		calls,
		clarityReady: false,
		clock,
		storage,
		url: 'https://akibara.cl/?utm_source=chatgpt'
	} );
	const oldLandingAt = storage.record().landingAt;

	clock.advance( 2000 );
	const newerDocument = boot( {
		calls,
		clock,
		storage,
		referrer: 'https://gemini.google.com/app'
	} );
	const newerRecord = storage.record();

	assert.deepEqual( newerDocument.calls, expectedLandingCalls( 'gemini', 'referrer' ) );
	assert.notEqual( newerRecord.landingAt, oldLandingAt );
	assert.equal( newerRecord.landingSentAt, newerRecord.landingAt );

	const callsAfterNewDocument = calls.slice();
	oldDocument.installClarity();
	oldDocument.runTimers();
	assert.deepEqual( calls, callsAfterNewDocument );
} );

test( '29 minutes plus real activity stays fresh while 31 inactive minutes expires', () => {
	const activeClock = createClock( 5000000 );
	const activeStorage = createStorage();
	const activeLanding = boot( {
		clock: activeClock,
		storage: activeStorage,
		url: 'https://akibara.cl/?utm_source=claude'
	} );
	const initialWrites = activeStorage.writes.length;
	const initialReads = activeStorage.reads.length;

	for ( let index = 0; index < 100; index++ ) {
		activeLanding.documentEvents.dispatch( 'scroll' );
	}
	activeClock.advance( 59000 );
	activeLanding.documentEvents.dispatch( 'pointerdown' );
	assert.equal( activeStorage.reads.length, initialReads );
	assert.equal( activeStorage.writes.length, initialWrites );

	activeClock.set( 5000000 + 29 * 60 * 1000 );
	activeLanding.documentEvents.dispatch( 'pointerdown' );
	assert.equal( activeStorage.record().lastActivityAt, activeClock.now() );
	assert.equal( activeStorage.writes.length, initialWrites + 1 );

	activeClock.advance( 30000 );
	activeLanding.documentEvents.dispatch( 'keydown' );
	assert.equal(
		activeStorage.writes.length,
		initialWrites + 1,
		'activity writes are throttled to at most one per minute'
	);

	activeClock.set( 5000000 + 31 * 60 * 1000 );
	const activeInternal = boot( {
		clock: activeClock,
		storage: activeStorage,
		referrer: 'https://akibara.cl/tienda/'
	} );
	assert.deepEqual( activeInternal.calls, [
		[ 'set', 'akb_ai_source', 'claude' ],
		[ 'set', 'akb_ai_evidence', 'utm' ]
	] );
	assert.equal( activeStorage.record().lastActivityAt, activeClock.now() );

	const inactiveClock = createClock( 6000000 );
	const inactiveStorage = createStorage();
	boot( {
		clock: inactiveClock,
		storage: inactiveStorage,
		url: 'https://akibara.cl/?utm_source=copilot'
	} );
	inactiveClock.advance( 31 * 60 * 1000 );
	const inactiveInternal = boot( {
		clock: inactiveClock,
		storage: inactiveStorage,
		referrer: 'https://akibara.cl/tienda/'
	} );

	assert.deepEqual( inactiveInternal.calls, [] );
	assert.equal( inactiveStorage.raw(), undefined );
	assert.ok( inactiveStorage.removals.includes( storageKey ) );

	const cutoffClock = createClock( 6500000 );
	const cutoffStorage = createStorage();
	boot( {
		clock: cutoffClock,
		storage: cutoffStorage,
		url: 'https://akibara.cl/?utm_source=gemini'
	} );
	cutoffClock.advance( 30 * 60 * 1000 );
	const exactCutoff = boot( {
		clock: cutoffClock,
		storage: cutoffStorage,
		referrer: 'https://akibara.cl/tienda/'
	} );
	assert.deepEqual( exactCutoff.calls, [] );
	assert.equal( cutoffStorage.raw(), undefined, 'exactly 30 inactive minutes is expired' );
} );

test( 'new official referrals and a new external UTM each create a landing', () => {
	const clock = createClock( 7000000 );
	const storage = createStorage();

	const first = boot( {
		clock,
		storage,
		referrer: 'https://chatgpt.com/share/first'
	} );
	const firstLandingAt = storage.record().landingAt;
	assert.deepEqual( first.calls, expectedLandingCalls( 'chatgpt', 'referrer' ) );

	clock.advance( 1000 );
	const sameSource = boot( {
		clock,
		storage,
		referrer: 'https://chatgpt.com/share/second'
	} );
	assert.deepEqual( sameSource.calls, expectedLandingCalls( 'chatgpt', 'referrer' ) );
	assert.ok( storage.record().landingAt > firstLandingAt );

	clock.advance( 1000 );
	const differentSource = boot( {
		clock,
		storage,
		url: 'https://akibara.cl/?utm_source=claude',
		referrer: 'https://gemini.google.com/app'
	} );
	assert.deepEqual( differentSource.calls, expectedLandingCalls( 'gemini', 'referrer' ) );
	assert.equal( storage.record().evidence, 'referrer' );

	clock.advance( 1000 );
	const newUtm = boot( {
		clock,
		storage,
		url: 'https://akibara.cl/?utm_source=perplexity.ai',
		referrer: 'https://www.google.com/search?q=manga'
	} );
	assert.deepEqual( newUtm.calls, expectedLandingCalls( 'perplexity' ) );
	assert.equal( storage.record().evidence, 'utm' );
} );

test( 'rejects future, non-finite, relationally invalid, and PII-tampered records', () => {
	const now = 8000000;
	const valid = {
		source: 'chatgpt',
		evidence: 'utm',
		lastActivityAt: now - 1000,
		landingAt: now - 1000,
		landingSentAt: now - 1000
	};
	const rawCases = [
		JSON.stringify( { ...valid, lastActivityAt: now + 1 } ),
		JSON.stringify( { ...valid, landingSentAt: valid.landingAt + 1 } ),
		JSON.stringify( { ...valid, lastActivityAt: valid.landingAt - 1 } ),
		JSON.stringify( { ...valid, source: 'evilchatgpt.com' } ),
		JSON.stringify( { ...valid, evidence: 'query' } ),
		JSON.stringify( { ...valid, email: 'person@example.test' } ),
		'{"source":"chatgpt","evidence":"utm","lastActivityAt":1e309,"landingAt":7999000,"landingSentAt":7999000}',
		'not-json'
	];

	for ( const raw of rawCases ) {
		const storage = createStorage( { raw } );
		const harness = boot( {
			clock: createClock( now ),
			storage,
			referrer: 'https://akibara.cl/tienda/'
		} );
		assert.deepEqual( harness.calls, [] );
		assert.equal( storage.raw(), undefined );
		assert.ok( storage.removals.includes( storageKey ) );
	}
} );

test( 'blocked storage keeps a private in-memory pending record until Clarity arrives', () => {
	const calls = [];
	const harness = boot( {
		calls,
		clarityReady: false,
		storage: createStorage( { blocked: true } ),
		url: 'https://akibara.cl/?utm_source=gemini'
	} );

	assert.equal( harness.timers.length, 1 );
	harness.installClarity();
	harness.runTimers();
	assert.deepEqual( calls, expectedLandingCalls( 'gemini' ) );
	assert.equal( harness.timers.length, 0 );
} );

test( 'absent Clarity stops after the bounded retry window and leaves the landing pending', () => {
	const storage = createStorage();
	const harness = boot( {
		clarityReady: false,
		storage,
		url: 'https://akibara.cl/?utm_source=copilot'
	} );

	assert.equal( harness.runTimers(), 20 );
	assert.equal( harness.timers.length, 0 );
	assert.deepEqual( harness.calls, [] );
	assert.notEqual( storage.record().landingSentAt, storage.record().landingAt );
} );

test( 'a failed set with readable empty storage retains current-page attribution', () => {
	const storage = createStorage( { setBlocked: true } );
	const harness = boot( {
		storage,
		url: 'https://akibara.cl/?utm_source=claude'
	} );

	assert.deepEqual( harness.calls, expectedLandingCalls( 'claude' ) );
	assert.equal( storage.raw(), undefined );
	assert.ok( storage.reads.length >= 2, 'retries and acknowledgement re-read the empty shared storage' );
} );

test( 'marks a landing only after a successful Clarity event call', () => {
	const calls = [];
	const storage = createStorage();
	let shouldFail = true;
	const harness = boot( {
		calls,
		clarityReady: false,
		storage,
		url: 'https://akibara.cl/?utm_source=chatgpt'
	} );

	harness.installClarity( ( ...args ) => {
		if ( args[0] === 'event' && shouldFail ) {
			shouldFail = false;
			throw new Error( 'Clarity queue unavailable' );
		}
		calls.push( args );
	} );
	harness.runNextTimer();
	assert.equal( storage.record().landingSentAt, 0 );
	assert.equal( harness.timers.length, 1 );

	harness.runTimers();
	assert.deepEqual( calls, expectedLandingCalls( 'chatgpt' ) );
	assert.equal( storage.record().landingSentAt, storage.record().landingAt );
} );

test( 'preserves commerce events and exposes no URL, query, PII, IDs, amount, identify, or GA call', () => {
	const storage = createStorage();
	const registry = createBlocksRegistry();
	const harness = boot( {
		config: { orderEvents: [ 'purchase' ], orderToken: 'a'.repeat( 64 ) },
		storage,
		wpData: registry.data,
		url: 'https://akibara.cl/?utm_source=chatgpt.com&email=person%40example.test&order_id=987&amount=49990',
		referrer: 'https://chatgpt.com/share/private-customer-id?prompt=secret'
	} );

	harness.body.dispatch( 'akb:added_to_cart' );
	harness.body.dispatch( 'wc-blocks_added_to_cart' );
	harness.body.dispatch( 'akb:removed_from_cart' );
	registry.applyCoupon( 'SECRETO10', true );
	registry.failPlaceOrder();
	harness.documentEvents.dispatch( 'akb:wishlist:change', { detail: { state: 'added' } } );
	harness.documentEvents.dispatch( 'change', {
		target: {
			matches: ( selector ) => selector === 'input[name="payment_method"]'
		}
	} );

	assert.deepEqual( harness.calls, [
		[ 'set', 'akb_ai_source', 'chatgpt' ],
		[ 'set', 'akb_ai_evidence', 'referrer' ],
		[ 'event', 'ai_referral_landing' ],
		[ 'event', 'purchase' ],
		[ 'upgrade', 'purchase' ],
		[ 'event', 'add_to_cart' ],
		[ 'event', 'remove_from_cart' ],
		[ 'event', 'coupon_applied' ],
		[ 'event', 'checkout_error' ],
		[ 'event', 'wishlist_add' ],
		[ 'event', 'payment_method_selected' ]
	] );

	const boundaryOutput = JSON.stringify( {
		calls: harness.calls,
		storage: storage.record()
	} );
	assertExactRecord( storage.record() );
	for ( const forbidden of [
		'person',
		'example.test',
		'987',
		'49990',
		'private-customer-id',
		'secret',
		'SECRETO10',
		'https://'
	] ) {
		assert.doesNotMatch( boundaryOutput, new RegExp( forbidden ) );
	}
	assert.doesNotMatch( source, /\b(?:gtag|ga)\s*\(|dataLayer|clarity\s*\(\s*['"]identify['"]/ );
} );

// S-b9250562 (2026-09-19). El carrito y el checkout son BLOQUES: no disparan
// `applied_coupon` ni `checkout_error` por jQuery, el tema dejó de re-emitirlos
// a propósito y los tres oyentes que había aquí llevaban sordos desde entonces.
// Las dos señales se leen ahora de `wp.data`, que es donde los bloques las tienen.

test( 'mide el cupón que el cliente aplica en el carrito o el checkout de bloques', () => {
	const registry = createBlocksRegistry();
	const harness = boot( { wpData: registry.data } );

	registry.applyCoupon( 'BIENVENIDA', true );

	assert.deepEqual( harness.calls, [ [ 'event', 'coupon_applied' ] ] );
	assert.doesNotMatch( JSON.stringify( harness.calls ), /BIENVENIDA/ );
} );

test( 'un cupón de uso individual que reemplaza al que había también cuenta', () => {
	const registry = createBlocksRegistry();
	const harness = boot( { wpData: registry.data } );

	registry.applyCoupon( 'BIENVENIDA', true );
	harness.clock.advance( 5000 );
	// WooCommerce retira los demás cupones al aceptar uno de uso individual: el
	// carrito termina con UNO, igual que empezó. Contar cupones lo perdía.
	registry.applyCoupon( 'SOLOESTE', true, true );

	assert.deepEqual( harness.calls, [
		[ 'event', 'coupon_applied' ],
		[ 'event', 'coupon_applied' ]
	] );
	assert.doesNotMatch( JSON.stringify( harness.calls ), /BIENVENIDA|SOLOESTE/ );
} );

test( 'un cupón rechazado no cuenta como cupón aplicado', () => {
	const registry = createBlocksRegistry();
	const harness = boot( { wpData: registry.data } );

	registry.applyCoupon( 'NOEXISTE', false );

	assert.deepEqual( harness.calls, [] );
} );

test( 'el cupón de pack que pone el servidor sin que el cliente lo pida no cuenta', () => {
	const registry = createBlocksRegistry();
	const harness = boot( { wpData: registry.data } );

	// Llega en una respuesta cualquiera del carrito: nadie llamó a `applyCoupon`,
	// así que `isApplyingCoupon()` no se mueve.
	registry.dispatch( { coupons: [ { code: 'akb_sb_byp_d709f38e' } ] } );

	assert.deepEqual( harness.calls, [] );

	// Y con ese cupón ya puesto, el que sí teclea el cliente se sigue midiendo.
	registry.applyCoupon( 'BIENVENIDA', true );
	assert.deepEqual( harness.calls, [ [ 'event', 'coupon_applied' ] ] );
} );

test( 'cada intento fallido de «Realizar el pedido» es un checkout_error, sin repetirlo mientras dura', () => {
	const registry = createBlocksRegistry();
	const harness = boot( { wpData: registry.data } );

	registry.failPlaceOrder();
	// El error sigue en pantalla mientras el cliente corrige, y cada tecla avisa
	// a los suscriptores. El reloj AVANZA entre avisos: sin eso el antirrebote de
	// 750 ms de `track()` se traga las repeticiones y la prueba no distingue un
	// flanco de un nivel.
	harness.clock.advance( 2000 );
	registry.dispatch( {} );
	harness.clock.advance( 2000 );
	registry.dispatch( {} );
	assert.deepEqual( harness.calls, [ [ 'event', 'checkout_error' ] ] );

	harness.clock.advance( 5000 );
	registry.failPlaceOrder();
	assert.deepEqual( harness.calls, [
		[ 'event', 'checkout_error' ],
		[ 'event', 'checkout_error' ]
	] );
} );

test( 'los stores de bloques registrados después de arrancar el guion también se miden', () => {
	const registry = createBlocksRegistry( { registered: false } );
	const harness = boot( { wpData: registry.data } );

	// Con `wp.data` cargado y los stores de WooCommerce aún sin registrar, un
	// aviso de cualquier otro store no puede romper ni medir nada.
	registry.dispatch( {} );
	assert.deepEqual( harness.calls, [] );

	registry.registerStores();
	registry.applyCoupon( 'BIENVENIDA', true );
	harness.clock.advance( 5000 );
	registry.failPlaceOrder();

	assert.deepEqual( harness.calls, [
		[ 'event', 'coupon_applied' ],
		[ 'event', 'checkout_error' ]
	] );
} );

test( 'no quedan oyentes de los eventos clásicos que ningún fichero emite', () => {
	const registry = createBlocksRegistry();
	const harness = boot( { wpData: registry.data } );

	for ( const muerto of [
		'akb:applied_coupon',
		'akb:applied_coupon_in_checkout',
		'akb:checkout_error'
	] ) {
		assert.equal( harness.body.listenerCount( muerto ), 0, `${ muerto } no lo emite nadie.` );
		harness.body.dispatch( muerto );
	}

	assert.deepEqual( harness.calls, [] );
	// Una sola suscripción: dos duplicarían cada evento.
	assert.equal( registry.listenerCount(), 1 );
} );

test( 'sin wp.data (ficha, portada, tienda) no se suscribe a nada ni falla', () => {
	const harness = boot( { initialEvent: 'view_product' } );

	assert.deepEqual( harness.calls, [ [ 'event', 'view_product' ] ] );
} );


test( 'receipt events distinguish created from paid and deduplicate each transition across reloads', () => {
	const storage = createStorage();
	const calls = [];
	const orderToken = 'a'.repeat( 64 );
	boot( { storage, calls, config: { orderEvents: [ 'order_created' ], orderToken } } );
	boot( { storage, calls, config: { orderEvents: [ 'order_created' ], orderToken } } );
	boot( { storage, calls, config: { orderEvents: [ 'order_created', 'purchase' ], orderToken } } );
	boot( { storage, calls, config: { orderEvents: [ 'order_created', 'purchase' ], orderToken } } );
	assert.deepEqual( calls, [ [ 'event', 'order_created' ], [ 'event', 'purchase' ], [ 'upgrade', 'purchase' ] ] );
	assert.doesNotMatch( JSON.stringify( calls ), new RegExp( orderToken ) );
	boot( { storage, calls, config: { orderEvents: [ 'purchase' ], orderToken: 'b'.repeat( 64 ) } } );
	assert.equal( calls.filter( ( item ) => item[0] === 'event' && item[1] === 'purchase' ).length, 2 );
} );

test( 'invalid receipt tokens, arbitrary events and legacy URL-only purchase config never convert', () => {
	for ( const orderToken of [ undefined, '', '123', 'wc_order_private_key', '<script>', 17 ] ) {
		assert.deepEqual( boot( { initialEvent: 'purchase', config: { orderToken, orderEvents: [ 'purchase' ] } } ).calls, [] );
	}
	assert.deepEqual( boot( { config: { orderToken: 'a'.repeat(64), orderEvents: [ 'email@example.test' ] } } ).calls, [] );
} );

test( 'QA and staff emit only a closed classification tag, even with a purchase and AI referrer', () => {
	for ( const internalTraffic of [ 'qa', 'staff' ] ) {
		const harness = boot( {
			config: { internalTraffic, orderEvents: [ 'purchase' ], orderToken: 'a'.repeat(64) },
			referrer: 'https://chatgpt.com/', initialEvent: 'begin_checkout', clarityReady: false
		} );
		harness.body.dispatch( 'akb:added_to_cart' );
		harness.installClarity();
		harness.runTimers();
		assert.deepEqual( harness.calls, [ [ 'set', 'akb_traffic_type', internalTraffic ] ] );
		assert.equal( harness.body.totalListenerCount(), 0 );
	}
} );

test( 'unrecognized traffic classifications never become Clarity tag values', () => {
	for ( const internalTraffic of [ 'email@example.test', 'QA', true, { type: 'qa' } ] ) {
		const harness = boot( { initialEvent: 'view_product', config: { internalTraffic } } );
		assert.deepEqual( harness.calls, [ [ 'event', 'view_product' ] ] );
	}
} );

test( 'two delayed documents sharing an order token do not duplicate receipt events', () => {
	const storage = createStorage();
	const calls = [];
	const config = { orderEvents: [ 'order_created', 'purchase' ], orderToken: 'a'.repeat( 64 ) };
	const first = boot( { storage, calls, config, clarityReady: false } );
	const second = boot( { storage, calls, config } );
	first.installClarity();
	first.runTimers();
	second.runTimers();
	assert.deepEqual( calls, [ [ 'event', 'order_created' ], [ 'event', 'purchase' ], [ 'upgrade', 'purchase' ] ] );
} );

test( 'receipt deduplication is recorded only after Clarity accepts the event', () => {
	const storage = createStorage();
	const config = { orderEvents: [ 'order_created', 'purchase' ], orderToken: 'a'.repeat(64) };
	const harness = boot( { storage, config, clarityReady: false } );
	harness.runTimers();
	assert.equal( storage.writes.length, 0 );
	harness.installClarity();
	const retry = boot( { storage, config } );
	assert.deepEqual( retry.calls, [ [ 'event', 'order_created' ], [ 'event', 'purchase' ], [ 'upgrade', 'purchase' ] ] );
	assert.deepEqual( boot( { storage, config } ).calls, [] );
} );

test( 'a throwing tracker retries receipt events but a failed recording upgrade cannot duplicate purchase', () => {
	let failures = 1;
	const calls = [];
	const harness = boot( {
		config: { orderEvents: [ 'purchase' ], orderToken: 'a'.repeat(64) },
		clarity( ...args ) {
			if ( failures-- > 0 || args[0] === 'upgrade' ) { throw new Error('tracker failure'); }
			calls.push( args );
		}
	} );
	harness.runTimers();
	assert.deepEqual( calls, [ [ 'event', 'purchase' ] ] );
} );

test( 'blocked sessionStorage cannot break commerce and duplicate entries emit once per document', () => {
	const harness = boot( {
		storage: createStorage( { blocked: true } ),
		config: { orderEvents: [ 'order_created', 'purchase', 'purchase' ], orderToken: 'a'.repeat(64) }
	} );
	harness.body.dispatch( 'akb:added_to_cart' );
	assert.deepEqual( harness.calls, [ [ 'event', 'order_created' ], [ 'event', 'purchase' ], [ 'upgrade', 'purchase' ], [ 'event', 'add_to_cart' ] ] );
} );
