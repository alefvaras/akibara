'use strict';

/*
 * R3-787c75fa (2026-09-19): Mi Cuenta → Editar dirección entrega el input de
 * calle PRELLENADO (WooCommerce lo imprime con el value guardado). El widget
 * de Google reemplaza visualmente a ese input; si no hereda su valor al
 * crearse, el cliente ve el campo en blanco y puede guardar encima una
 * dirección a medio escribir, perdiendo la que ya tenía completa y correcta.
 *
 * Esta prueba dirige el módulo por su API pública (el callback que Google
 * dispara al terminar de cargar el SDK, `akbAddrAutoBoot`), no por funciones
 * internas: simula el DOM mínimo y un `PlaceAutocompleteElement` falso, y
 * comprueba que el widget nuevo queda con el mismo valor que el input que
 * tapa.
 */

const assert = require( 'node:assert/strict' );

// --- DOM mínimo -------------------------------------------------------
const shippingInput = {
	id: 'shipping_address_1',
	placeholder: '',
	value: 'Av. Siempre Viva 742',
	offsetParent: {}, // no-null: pasa el chequeo "está en el render tree".
	parentNode: { insertBefore() {} },
	style: {},
	setAttribute() {},
	tabIndex: null,
};

// Segundo input, sin valor previo: el fix no debe inventar un valor donde no
// lo había (guarda contra un `pac.value = input.value` que rompa con '' vs
// undefined, o que se dispare siempre sin condición).
const billingInput = {
	id: 'billing_address_1',
	placeholder: '',
	value: '',
	offsetParent: {},
	parentNode: { insertBefore() {} },
	style: {},
	setAttribute() {},
	tabIndex: null,
};

global.document = {
	readyState: 'complete',
	addEventListener() {},
	removeEventListener() {},
	querySelector() { return null; }, // bootstrap() cae a document.body.
	querySelectorAll( selector ) {
		if ( selector === '#shipping_address_1' ) return [ shippingInput ];
		if ( selector === '#billing_address_1' ) return [ billingInput ];
		return [];
	},
	body: {},
};

const pacsCreados = [];

class FakePlaceAutocompleteElement {
	constructor( options ) {
		this.options = options;
		this._listeners = {};
		pacsCreados.push( this );
	}
	setAttribute( name, value ) {
		this._attrs = this._attrs || {};
		this._attrs[ name ] = value;
	}
	addEventListener( evt, fn ) {
		( this._listeners[ evt ] = this._listeners[ evt ] || [] ).push( fn );
	}
}

global.google = { maps: { places: { PlaceAutocompleteElement: FakePlaceAutocompleteElement } } };
global.window = {
	akbAddrAuto: {
		loaderUrl: null,
		country: [ 'cl' ],
		targets: [
			{ input: '#billing_address_1',  prefix: 'billing' },
			{ input: '#shipping_address_1', prefix: 'shipping' },
		],
		regionMap: {},
	},
	google: global.google,
};
global.MutationObserver = function () {
	this.observe = function () {};
};

require( '../../../assets/js/address-autocomplete.js' );

// Simula el callback que Google llama cuando termina de cargar el SDK.
global.window.akbAddrAutoBoot();

// `akbAddrAutoBoot` resuelve `bootstrap()` en un microtask
// (`Promise.resolve().then(bootstrap)`): dos vueltas de microtask garantizan
// que ya corrió antes de comprobar.
Promise.resolve()
	.then( () => {} )
	.then( () => {
		assert.equal( pacsCreados.length, 2, 'debió crear un widget por cada target con input en el DOM' );

		// El input queda oculto tras el widget nuevo (comportamiento ya existente,
		// lo confirmamos para asegurar que attachOne() sí corrió sobre este input).
		assert.equal( shippingInput.style.display, 'none' );
		assert.equal( billingInput.style.display, 'none' );

		// El defecto: el widget que tapa la calle YA guardada debe heredarla, no
		// nacer vacío. Como los targets se procesan en orden (billing primero,
		// shipping después), el segundo pac creado es el de shipping.
		const pacDeShipping = pacsCreados[ 1 ];
		assert.equal(
			pacDeShipping.value,
			'Av. Siempre Viva 742',
			'el widget debe heredar la calle ya guardada del input que tapa'
		);

		// Y no debe inventar un valor para el input que llegó vacío.
		const pacDeBilling = pacsCreados[ 0 ];
		assert.ok(
			pacDeBilling.value === undefined || pacDeBilling.value === '',
			'un input sin valor previo no debe forzar un value en el widget'
		);

		console.log( 'attach-carries-existing-value: ok' );
	} )
	.catch( ( err ) => {
		console.error( err );
		process.exitCode = 1;
	} );
