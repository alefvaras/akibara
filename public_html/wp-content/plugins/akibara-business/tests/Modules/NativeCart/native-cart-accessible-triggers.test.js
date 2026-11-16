'use strict';
// Accessibility, cart session and focus regressions executed on the official DOM harness.
const assert = require( 'node:assert/strict' );
const { createDOM } = require( '../../../../../../../tools/testing/dom-harness.js' );
const dom = createDOM( { url: 'https://akibara.test/' } );
const doc = dom.document;
const docBody = doc.body;
const cartStates = [];
const incentiveStates = [];
let storageAccesses = 0;
// Instrument the real Window surface: any attempt to use persistent cart data fails.
for ( const storage of [ 'localStorage', 'sessionStorage' ] ) {
 Object.defineProperty( dom.window, storage, { configurable: true, get() { storageAccesses++; throw new Error( 'Cart state belongs to the WooCommerce session.' ); } } );
}
doc.addEventListener( 'akb:cart:state', event => incentiveStates.push( event.detail.purchase_incentives ) );
doc.addEventListener( 'akb:cart:state', event => cartStates.push( Array.from( event.detail.product_ids ) ) );
Object.defineProperty( dom.window.HTMLElement.prototype, 'getClientRects', { value() { return this.hidden ? [] : [ { width: 44, height: 44 } ]; } } );
Object.defineProperty( doc, 'visibilityState', { configurable: true, writable: true, value: 'visible' } );
function trigger( target, type, options = {} ) {
 const event = type === 'click' ? new dom.window.MouseEvent( type, { bubbles: true, cancelable: true, ...options } ) : new dom.window.Event( type, { bubbles: true, cancelable: true } );
 if ( options.persisted ) Object.defineProperty( event, 'persisted', { value: true } );
 target.dispatchEvent( event );
 return event;
}
function withClass( cls, tag ) {
	var el = doc.createElement( tag || 'div' );
	el.classList.add( cls );
	return el;
}

var overlay = doc.createElement( 'div' );
overlay.setAttribute( 'id', 'akb-native-cart-overlay' );
var drawer = doc.createElement( 'div' );
drawer.setAttribute( 'id', 'akb-native-cart-drawer' );

var closeBtn = withClass( 'akb-cart-close', 'button' );
var itemsContainer = withClass( 'akb-cart-items-container' );
var subtotalVal = withClass( 'akb-cart-subtotal-val' );
var discountRow = withClass( 'akb-cart-discount' );
var discountVal = withClass( 'akb-cart-discount-val' );
var totalRow = withClass( 'akb-cart-total' );
var totalVal = withClass( 'akb-cart-total-val' );
var shippingNotice = withClass( 'akb-cart-shipping-notice' );
var liveRegion = withClass( 'akb-cart-live' );
var statusContainer = doc.createElement( 'div' );
statusContainer.setAttribute( 'data-akb-cart-status', '' );
var footer = withClass( 'akb-cart-footer' );
var checkoutButton = withClass( 'akb-cart-checkout-btn', 'a' );
checkoutButton.href = 'https://akibara.test/checkout/';
checkoutButton.textContent = 'Pagar pedido';
footer.appendChild( checkoutButton );
var drawerBody = withClass( 'akb-cart-body' );
var progressSlot = withClass( 'akb-cart-progress-slot' );

[ closeBtn, itemsContainer, subtotalVal, discountRow, discountVal, totalRow, totalVal, shippingNotice, liveRegion, statusContainer, footer, drawerBody, progressSlot ]
	.forEach( function ( el ) { drawer.appendChild( el ); } );

// Los dos disparadores REALES del cajón (contrato de la ficha R4-9c9fffc5):
// icono de la cabecera y tab del bottom-nav. A propósito SIN
// aria-haspopup/aria-expanded: simula un tema viejo que todavía no los trae.
var headerTrigger = doc.createElement( 'a' );
headerTrigger.classList.add( 'header-icon', 'akb-cart-trigger' );
headerTrigger.href = '/carrito/';
headerTrigger.setAttribute( 'aria-label', 'Carrito' );

var bottomNavTrigger = doc.createElement( 'a' );
bottomNavTrigger.classList.add( 'bottom-nav-tab', 'akb-cart-trigger' );
bottomNavTrigger.href = '/carrito/';
bottomNavTrigger.setAttribute( 'aria-label', 'Carrito' );

docBody.appendChild( overlay );
docBody.appendChild( drawer );
docBody.appendChild( headerTrigger );
docBody.appendChild( bottomNavTrigger );



// --- window, jQuery falso y fetch falso ---------------------------------

var jqueryBodyHandlers = {};
var windowEvents = dom.window;
var navigations = [];

Object.defineProperty( dom.window.location, 'assign', { value: function ( url ) { navigations.push( url ); } } );
dom.window.akbNativeCart = {
 enabled: true,
 ajaxUrl: 'https://akibara.test/?wc-ajax=%%endpoint%%',
 copy: { checkingCart: 'Comprobando…', checkoutWait: 'Espera a que termine de actualizarse tu carrito.', cartEmptied: 'Tu carrito está vacío. Agrega nuevamente tus productos para continuar.' }
};
dom.window.jQuery = function () {
 return {
  on: function ( events, handler ) { events.split( /\s+/ ).forEach( function ( event ) { ( jqueryBodyHandlers[ event ] = jqueryBodyHandlers[ event ] || [] ).push( handler ); } ); },
  trigger: function ( event ) { ( jqueryBodyHandlers[ event ] || [] ).forEach( function ( handler ) { handler( { type: event } ); } ); }
 };
};

var cartPayload = {
	items: [
		{ id: 101, title: 'Frieren 06', quantity: 1, keys_to_remove: [ 'key-frieren' ], max: -1, price_html: '' },
		{ id: 102, title: 'Jujutsu Kaisen 12', quantity: 2, keys_to_remove: [ 'key-jjk' ], max: 5, price_html: '' },
	],
	cart_count: 2,
	subtotal_html: '',
	has_discount: false,
	discount_html: '',
	total_html: '',
	shipping_notice: '',
	nonce: 'test-nonce',
	purchase_incentives: { product_ids: [ 101, 102 ], rewards: { points: 450 }, shipping: { state: 'hidden', reason: 'unavailable' } },
};

dom.window.fetch = async function () {
	return {
		ok: true,
		json: async function () {
			return { success: true, data: cartPayload };
		},
	};
};


async function flush() {
	for ( var i = 0; i < 10; i++ ) {
		await new Promise( function ( resolve ) { setImmediate( resolve ); } );
	}
}

async function expectFocus( expected, message ) {
	const target = typeof expected === 'function' ? expected : () => expected;
	const until = Date.now() + 500;
	while ( doc.activeElement !== target() && Date.now() < until ) {
		await new Promise( resolve => setTimeout( resolve, 10 ) );
	}
	assert.equal( doc.activeElement === target(), true, message || 'Focus must return to the expected visible control' );
}

( async function () {
	await flush();
	dom.loadScript( 'public_html/wp-content/plugins/akibara-business/assets/js/native-cart.js' );
	trigger( doc, 'DOMContentLoaded' );
	// --- R4-9c9fffc5: estado inicial, aunque el tema no traiga los atributos ---
	assert.equal( headerTrigger.getAttribute( 'aria-expanded' ), 'false', 'sin abrir el cajón, el trigger debe declarar aria-expanded="false" desde el arranque' );
	assert.equal( headerTrigger.getAttribute( 'aria-haspopup' ), 'dialog', 'un tema viejo sin aria-haspopup debe recibirlo igual' );
	assert.equal( bottomNavTrigger.getAttribute( 'aria-expanded' ), 'false', 'el tab del bottom-nav es OTRO trigger y también debe quedar sincronizado' );
	assert.equal( bottomNavTrigger.getAttribute( 'aria-haspopup' ), 'dialog' );

	// --- Abrir: TODOS los triggers pasan a expanded=true ---
	liveRegion.textContent = 'Sin cambios anunciados';
	dom.window.akbCart.open( headerTrigger );
	await flush();

	assert.equal( drawer.classList.contains( 'akb-open' ), true, 'el CSS anima carga solo con la clase real de apertura' );
	assert.equal( liveRegion.textContent, 'Sin cambios anunciados', 'leer un carrito lleno no anuncia una mutación inexistente' );
	assert.equal( headerTrigger.getAttribute( 'aria-expanded' ), 'true', 'abrir el cajón debe marcar expanded=true en el trigger que lo abrió' );
	assert.equal(
		bottomNavTrigger.getAttribute( 'aria-expanded' ),
		'true',
		'el OTRO trigger (bottom-nav) no disparó la apertura pero controla el MISMO diálogo: también debe quedar en true'
	);

	// --- R4-73231a05: nombres accesibles por producto, no genéricos ---
	var rows = itemsContainer.querySelectorAll( '.akb-cart-item' );
	assert.equal( rows.length, 2, 'debieron pintarse las 2 líneas del carrito' );
	assert.deepEqual( cartStates.at( -1 ), [ 101, 102 ], 'la lectura confirmada publica los IDs que consume colección' );
	assert.equal( incentiveStates.at( -1 ).rewards.points, 450, 'la lectura fresca publica el cálculo canónico del carrito' );

	var frieren = rows[ 0 ];
	var jjk = rows[ 1 ];

	assert.equal(
		frieren.querySelector( '.akb-cart-item-remove' ).getAttribute( 'aria-label' ),
		'Eliminar Frieren 06 del carrito',
		'el botón de eliminar debe nombrar SU producto, no un "Eliminar producto" genérico'
	);
	assert.equal(
		jjk.querySelector( '.akb-cart-item-remove' ).getAttribute( 'aria-label' ),
		'Eliminar Jujutsu Kaisen 12 del carrito'
	);
	assert.notEqual(
		frieren.querySelector( '.akb-cart-item-remove' ).getAttribute( 'aria-label' ),
		jjk.querySelector( '.akb-cart-item-remove' ).getAttribute( 'aria-label' ),
		'dos líneas no pueden compartir el mismo nombre accesible de "eliminar"'
	);

	assert.equal( frieren.querySelector( '.akb-qty-minus' ).getAttribute( 'aria-label' ), 'Disminuir cantidad de Frieren 06' );
	assert.equal( frieren.querySelector( '.akb-qty-plus' ).getAttribute( 'aria-label' ), 'Aumentar cantidad de Frieren 06' );
	assert.equal( frieren.querySelector( 'input' ).getAttribute( 'aria-label' ), 'Cantidad de Frieren 06' );
	assert.equal( jjk.querySelector( '.akb-qty-minus' ).getAttribute( 'aria-label' ), 'Disminuir cantidad de Jujutsu Kaisen 12' );
	assert.equal( jjk.querySelector( '.akb-qty-plus' ).getAttribute( 'aria-label' ), 'Aumentar cantidad de Jujutsu Kaisen 12' );
	assert.equal( jjk.querySelector( 'input' ).getAttribute( 'aria-label' ), 'Cantidad de Jujutsu Kaisen 12' );

	// El título viaja por setAttribute, nunca interpolado como HTML: un título
	// con "<" no debe crear ningún nodo hijo dentro del botón.
	assert.equal( frieren.querySelector( '.akb-cart-item-remove' ).querySelector( 'img,script' ), null, 'el título no genera marcado dentro del botón' );

	// --- R4-9c9fffc5: el fragment de WooCommerce reemplaza el icono con
	// aria-expanded="false" fijo; native-cart.js tiene que reaplicar el
	// estado real cuando eso pasa (vía el canal jQuery real Y su
	// re-emisión nativa `akb:*`, por si jQuery deja de estar disponible). ---
	headerTrigger.setAttribute( 'aria-expanded', 'false' ); // simula el fragment reemplazado
	trigger( docBody, 'akb:wc_fragments_refreshed' );
	assert.equal(
		headerTrigger.getAttribute( 'aria-expanded' ),
		'true',
		'el evento nativo akb:wc_fragments_refreshed debe reaplicar el estado real del cajón'
	);

	headerTrigger.setAttribute( 'aria-expanded', 'false' );
	jqueryBodyHandlers.wc_fragments_loaded.forEach( function ( fn ) { fn( { type: 'wc_fragments_loaded' } ); } );
	assert.equal(
		headerTrigger.getAttribute( 'aria-expanded' ),
		'true',
		'wc_fragments_loaded (jQuery, el canal real de WooCommerce) también debe reaplicar el estado'
	);

	// --- Cerrar: TODOS los triggers vuelven a expanded=false ---
	dom.window.akbCart.close();
	assert.equal( drawer.classList.contains( 'akb-open' ), false, 'cerrar retira la clase que permite el pulso CSS' );
	assert.equal( headerTrigger.getAttribute( 'aria-expanded' ), 'false' );
	assert.equal( bottomNavTrigger.getAttribute( 'aria-expanded' ), 'false', 'cerrar debe apagar el estado en AMBOS triggers' );
	await expectFocus( headerTrigger, 'sin refresco de por medio, el foco vuelve al icono que abrió el cajón' );

	// --- REV-X1-2-D1: el cajón pide `wc_fragment_refresh` tras cada cambio
	// (cantidad, «Eliminar», recomendación agregada) y WooCommerce cambia el
	// icono de cabecera por un nodo NUEVO en el mismo lugar (jQuery
	// replaceWith). Al cerrar, el icono que abrió el cajón ya no estaba en el
	// documento y el foco caía al <body>: el siguiente Tab volvía a «Saltar al
	// contenido» (medido en el espejo en Chromium y WebKit). ---
	function reemplazarPorFragment( viejo ) {
		var nuevo = doc.createElement( 'a' );
		nuevo.className = viejo.className;
		nuevo.href = viejo.href;
		nuevo.setAttribute( 'aria-label', 'Carrito' );
		nuevo.setAttribute( 'aria-expanded', 'false' );
		viejo.replaceWith( nuevo );
		return nuevo;
	}

	headerTrigger.focus();
	dom.window.akbCart.open( headerTrigger );
	await flush();
	await expectFocus( closeBtn, 'al abrir, el foco entra al cajón' );
	var iconoNuevo = reemplazarPorFragment( headerTrigger );
	assert.equal( doc.contains( headerTrigger ), false );
	dom.window.akbCart.close();
	await expectFocus( iconoNuevo,
		'el foco debe volver al icono NUEVO que dejó el fragment, no perderse con el que ya no está en el documento'
	);
	assert.equal( iconoNuevo.getAttribute( 'aria-expanded' ), 'false' );

	// Abierto desde el tab del bottom-nav (que no es fragment) mientras el
	// icono de cabecera se refresca: el foco vuelve al tab, no al icono.
	bottomNavTrigger.focus();
	dom.window.akbCart.open( bottomNavTrigger );
	await flush();
	reemplazarPorFragment( iconoNuevo );
	dom.window.akbCart.close();
	await expectFocus( bottomNavTrigger, 'se devuelve al disparador que abrió, no al primero de la lista' );

	// Abierto desde algo que no es un disparador (p. ej. el alta que lo abre
	// sola) y que desapareció: no se inventa un destino entre los disparadores.
	var boton = doc.createElement( 'button' );
	docBody.appendChild( boton );
	boton.focus();
	dom.window.akbCart.open();
	await flush();
	docBody.removeChild( boton );
	dom.window.akbCart.close();
	assert.equal(
		Array.from( doc.querySelectorAll( '.akb-cart-trigger' ) ).indexOf( doc.activeElement ),
		-1,
		'si lo que abrió no era un disparador, cerrar no enfoca ninguno'
	);

	// --- REV-X1-2b-D2: una respuesta que llega DESPUÉS de cerrar el cajón no
	// puede quitarle el foco al disparador. Abrir lanza una lectura del
	// carrito; si el cliente cierra (Escape) antes de que vuelva y el carrito
	// resulta vacío, renderCart() enfocaba el botón de cerrar de un cajón ya
	// cerrado: Chromium lo desenfoca al ocultarse y el foco acababa en el
	// <body> (el siguiente Tab, «Saltar al contenido»). Medido en el espejo. ---
	function respuestaRetenida( data ) {
		var soltar;
		var retenida = new Promise( function ( resolve ) { soltar = resolve; } );
		dom.window.fetch = async function () {
			await retenida;
			return {
				ok: true,
				json: async function () { return { success: true, data: data }; },
			};
		};
		return soltar;
	}
	var carritoVacio = Object.assign( {}, cartPayload, { items: [], cart_count: 0 } );
	var disparador = doc.querySelectorAll( '.akb-cart-trigger' )[ 0 ];

	var soltarLectura = respuestaRetenida( carritoVacio );
	disparador.focus();
	dom.window.akbCart.open( disparador );
	await flush();
	dom.window.akbCart.close();
	await expectFocus( disparador, 'cerrar devuelve el foco al disparador' );
	soltarLectura();
	await flush();
	await flush();
	assert.equal( itemsContainer.querySelectorAll( '.akb-cart-item' ).length, 0, 'la lectura tardía sí pintó el carrito vacío' );
	assert.equal( itemsContainer.querySelector( 'a.akb-cart-empty__cta' ).href, 'https://akibara.test/tienda/', 'el estado vacío mantiene una salida navegable al catálogo' );
	await expectFocus( disparador,
		'la lectura que vuelve con el carrito vacío después de cerrar no puede mover el foco al botón de cerrar del cajón oculto'
	);

	// Lo mismo con una mutación: «Eliminar» sale, el cliente cierra antes de
	// que vuelva la respuesta, y restorePoint() buscaba el control equivalente
	// dentro del cajón ya cerrado.
	dom.window.fetch = async function () {
		return { ok: true, json: async function () { return { success: true, data: cartPayload }; } };
	};
	dom.window.akbCart.open( disparador );
	await flush();
	await flush();
	var eliminar = itemsContainer.querySelector( '.akb-cart-item-remove' );
	assert.ok( eliminar, 'con el carrito lleno hay un «Eliminar» que tocar' );
	eliminar.focus();
	var soltarMutacion = respuestaRetenida( carritoVacio );
	trigger( eliminar, 'click' );
	dom.window.akbCart.close();
	await expectFocus( disparador );
	soltarMutacion();
	await flush();
	await flush();
	await expectFocus( disparador,
		'la respuesta de «Eliminar» que llega después de cerrar tampoco puede llevarse el foco al cajón oculto'
	);

	function responder( data ) {
		return { ok: true, json: async function () { return { success: true, data: data }; } };
	}

	// La otra rama de restorePoint(), la del carrito que sigue con filas
	// (REV-FN1-2): «+» sale, el cliente cierra antes de que vuelva, y el
	// foco iba al «+» equivalente de la fila recién pintada en el cajón oculto.
	dom.window.fetch = async function () { return responder( cartPayload ); };
	dom.window.akbCart.open( disparador );
	await flush();
	await flush();
	var mas = itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ];
	assert.equal( mas.disabled, false, 'Jujutsu Kaisen 12 lleva 2 de 5: su «+» se puede tocar' );
	mas.focus();
	var soltarMas = respuestaRetenida( cartPayload );
	trigger( mas, 'click' );
	dom.window.akbCart.close();
	await expectFocus( disparador );
	soltarMas();
	await flush();
	await flush();
	assert.equal( itemsContainer.querySelectorAll( '.akb-cart-item' ).length, 2, 'la respuesta tardía de «+» sí repintó las dos filas' );
	await expectFocus( disparador,
		'la respuesta de «+» que llega después de cerrar, con el carrito aún lleno, no puede llevarse el foco a la fila del cajón oculto'
	);

	// Abierto, sigue lo de siempre: el foco vuelve al «+» de la misma fila.
	dom.window.fetch = async function () { return responder( cartPayload ); };
	dom.window.akbCart.open( disparador );
	await flush();
	await flush();
	itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ].focus();
	liveRegion.textContent = '';
	trigger( itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ], 'click' );
	await flush();
	await flush();
	await expectFocus( () => itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ], 'abierto, tras «+» el foco queda en el «+» nuevo de la misma fila' );
	assert.equal( liveRegion.textContent, 'Carrito actualizado.', 'una mutación real sí anuncia el nuevo estado' );
	dom.window.akbCart.close();

	// Lo mismo al agregar una recomendación (REV-FN1-2): «Agregar» sale, el
	// cliente cierra, y al volver add_to_cart y la lectura del carrito,
	// restoreFocusAfterRecommendation() enfocaba el «Agregar» del cajón oculto.
	var conRecomendacion = Object.assign( {}, cartPayload, {
		settings: { showRecommendations: true },
		cross_sells: [ { id: 77, title: 'Frieren 07', price_html: '' } ],
	} );
	dom.window.fetch = async function () { return responder( conRecomendacion ); };
	dom.window.akbCart.open( disparador );
	await flush();
	await flush();
	var agregar = itemsContainer.querySelector( '.akb-cs-add-btn' );
	assert.ok( agregar, 'con las recomendaciones encendidas hay un «Agregar» que tocar' );
	agregar.focus();
	var soltarAgregar;
	var agregarRetenido = new Promise( function ( resolve ) { soltarAgregar = resolve; } );
	dom.window.fetch = async function ( url ) {
		if ( /wc-ajax=add_to_cart$/.test( url ) ) {
			await agregarRetenido;
			return { ok: true, json: async function () { return {}; } };
		}
		return responder( conRecomendacion );
	};
	liveRegion.textContent = '';
	trigger( agregar, 'click', { type: 'click', currentTarget: agregar, preventDefault: function () {} } );
	assert.equal( agregar.disabled, true, 'una recomendación no puede enviarse dos veces mientras está pendiente' );
	assert.equal( agregar.getAttribute( 'aria-busy' ), 'true' );
	dom.window.akbCart.close();
	await expectFocus( disparador );
	soltarAgregar();
	await flush();
	await flush();
	await flush();
	assert.equal( liveRegion.textContent, 'Agregado al carrito.', 'la recomendación tardía sí terminó de agregarse' );
	assert.equal( itemsContainer.querySelector( '.akb-cs-add-btn' ).disabled, false, 'la lista actual permite la siguiente compra al terminar' );
	await expectFocus( disparador,
		'«Agregar» una recomendación que responde después de cerrar no puede llevarse el foco al cajón oculto'
	);

	// Abierto, el foco pasa al «Agregar» de la lista recién pintada.
	dom.window.fetch = async function () { return responder( conRecomendacion ); };
	dom.window.akbCart.open( disparador );
	await flush();
	await flush();
	itemsContainer.querySelector( '.akb-cs-add-btn' ).focus();
	await new Promise( resolve => setTimeout( resolve, 60 ) );
	await expectFocus( () => itemsContainer.querySelector( '.akb-cs-add-btn' ), 'la apertura diferida no roba el foco a un control que el cliente ya eligió dentro del cajón' );
	trigger( itemsContainer.querySelector( '.akb-cs-add-btn' ), 'click', { type: 'click', currentTarget: itemsContainer.querySelector( '.akb-cs-add-btn' ), preventDefault: function () {} } );
	await flush();
	await flush();
	await flush();
	await expectFocus( () => itemsContainer.querySelector( '.akb-cs-add-btn' ), 'abierto, tras agregar el foco queda en el «Agregar» nuevo' );
	dom.window.akbCart.close();

	// Con el cajón ABIERTO el comportamiento de siempre sigue: vaciarlo por
	// mutación elimina el control enfocado y el foco va al botón de cerrar.
	dom.window.fetch = async function () {
		return { ok: true, json: async function () { return { success: true, data: cartPayload }; } };
	};
	dom.window.akbCart.open( disparador );
	await flush();
	await flush();
	itemsContainer.querySelector( '.akb-cart-item-remove' ).focus();
	var soltarAbierto = respuestaRetenida( carritoVacio );
	trigger( itemsContainer.querySelector( '.akb-cart-item-remove' ), 'click' );
	soltarAbierto();
	await flush();
	await flush();
	await expectFocus( closeBtn, 'abierto, vaciar el carrito deja el foco en el botón de cerrar, no en el <body>' );
	dom.window.akbCart.close();

	// El carrito pintado no autoriza el checkout: la sesión pudo desaparecer.
	function checkoutClick( overrides ) {
		const event = trigger( checkoutButton, 'click', overrides );
		return { prevented: event.defaultPrevented };
	}

	async function showCart( payload ) {
		dom.window.fetch = async function () { return responder( payload || cartPayload ); };
		if ( drawer.classList.contains( 'akb-open' ) ) dom.window.akbCart.refresh( true );
		else dom.window.akbCart.open( disparador );
		await flush();
		await flush();
	}
	await showCart();
	var reads = 0;
	dom.window.fetch = async function ( url, options ) {
		reads++;
		assert.equal( options.cache, 'no-store' );
		assert.equal( options.credentials, 'same-origin' );
		return responder( carritoVacio );
	};
	assert.equal( checkoutClick().prevented, true );
	await flush();
	assert.equal( reads, 1 );
	assert.equal( navigations.length, 0, 'una sesión vacía jamás navega con el carrito viejo' );
	assert.equal( footer.hasAttribute( 'hidden' ), true );
	assert.equal( disparador.getAttribute( 'data-akb-cart-count' ), '0' );
	assert.match( itemsContainer.textContent, /Agrega nuevamente tus productos/ );
	assert.match( liveRegion.textContent, /Agrega nuevamente tus productos/ );
	assert.equal( checkoutButton.getAttribute( 'aria-busy' ), 'false' );

	// Dos clicks durante una validación producen una lectura y una navegación.
	await showCart( conRecomendacion );
	var releaseCheckout = respuestaRetenida( cartPayload );
	var pendingCheckoutFetch = dom.window.fetch;
	reads = 0;
	dom.window.fetch = function () { reads++; return pendingCheckoutFetch(); };
	checkoutClick();
	checkoutClick();
	assert.equal( checkoutButton.textContent, 'Comprobando…' );
	trigger( itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ], 'click' );
	var blockedRecommendation = itemsContainer.querySelector( '.akb-cs-add-btn' );
	trigger( blockedRecommendation, 'click', { currentTarget: blockedRecommendation, preventDefault: function () {} } );
	trigger( windowEvents, 'focus' );
	trigger( doc, 'visibilitychange' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );
	assert.equal( reads, 1, 'ni doble click, ni mutación, ni regreso de pestaña interrumpen el checkout' );
	releaseCheckout();
	await flush();
	await flush();
	assert.deepEqual( navigations, [ checkoutButton.href ] );
	dom.window.fetch = async function () { return responder( cartPayload ); };
	trigger( windowEvents, 'pageshow', { persisted: true } );
	assert.equal( checkoutButton.textContent, 'Pagar pedido', 'volver desde bfcache no deja el botón bloqueado' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );

	// Red caída: nunca continuar con cache y ofrecer recuperación por teclado.
	dom.window.fetch = async function () { throw new Error( 'offline' ); };
	checkoutClick();
	await flush();
	assert.equal( navigations.length, 1 );
	assert.equal( footer.hasAttribute( 'hidden' ), true );
	var retry = itemsContainer.querySelector( 'button.akb-cart-empty__cta' );
	assert.equal( retry.textContent, 'Reintentar' );
	await expectFocus( retry, 'el foco queda en una acción visible al fallar la lectura' );
	dom.window.fetch = async function () { return responder( cartPayload ); };
	trigger( retry, 'click' );
	await flush();
	assert.equal( footer.hasAttribute( 'hidden' ), false );
	assert.equal( itemsContainer.querySelectorAll( '.akb-cart-item' ).length, 2 );

	// Una respuesta inválida tampoco se interpreta como autorización de pago.
	dom.window.fetch = async function () { return responder( { items: cartPayload.items, cart_count: 'inventado' } ); };
	checkoutClick();
	await flush();
	assert.equal( navigations.length, 1 );
	assert.ok( itemsContainer.querySelector( 'button.akb-cart-empty__cta' ) );

	// Cerrar cancela la intención; una respuesta tardía no puede navegar.
	await showCart();
	var releaseClosed = respuestaRetenida( cartPayload );
	checkoutClick();
	dom.window.akbCart.close();
	releaseClosed();
	await flush();
	assert.equal( navigations.length, 1 );
	await expectFocus( disparador );
	assert.equal( checkoutButton.getAttribute( 'aria-busy' ), 'false' );

	// Pestaña cerrada/inactiva: sin consultas. Al volver: una sola y sin polling.
	reads = 0;
	dom.window.fetch = async function () { reads++; return responder( carritoVacio ); };
	trigger( windowEvents, 'focus' );
	trigger( doc, 'visibilitychange' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );
	assert.equal( reads, 0 );
	await showCart();
	reads = 0;
	dom.window.fetch = async function () { reads++; return responder( carritoVacio ); };
	doc.visibilityState = 'hidden';
	trigger( doc, 'visibilitychange' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );
	assert.equal( reads, 0 );
	doc.visibilityState = 'visible';
	trigger( doc, 'visibilitychange' );
	trigger( windowEvents, 'focus' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );
	assert.equal( reads, 1 );
	assert.equal( footer.hasAttribute( 'hidden' ), true );
	await new Promise( resolve => setTimeout( resolve, 350 ) );
	assert.equal( reads, 1, 'sin polling al mantener abierto el cajón' );

	// Volver a la pestaña no pierde el foco ni recupera uno que ya se movió.
	await showCart( conRecomendacion );
	itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ].focus();
	trigger( windowEvents, 'focus' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );
	await expectFocus( () => itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ] );
	itemsContainer.querySelector( '.akb-cs-add-btn' ).focus();
	trigger( windowEvents, 'focus' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );
	await expectFocus( () => itemsContainer.querySelector( '.akb-cs-add-btn' ) );
	var releaseFocus = respuestaRetenida( conRecomendacion );
	itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ].focus();
	trigger( windowEvents, 'focus' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );
	closeBtn.focus();
	releaseFocus();
	await flush();
	await expectFocus( closeBtn, 'una respuesta tardía respeta el foco movido al botón de cerrar' );

	// Si cantidad o recomendación siguen escribiéndose, Pagar espera.
	await showCart();
	var releaseQuantity = respuestaRetenida( cartPayload );
	trigger( itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ], 'click' );
	checkoutClick();
	assert.match( statusContainer.textContent, /Espera a que termine/ );
	releaseQuantity();
	await flush();
	assert.equal( navigations.length, 1 );
	await showCart( conRecomendacion );
	var releaseRecommendation = respuestaRetenida( conRecomendacion );
	var pendingAdd = itemsContainer.querySelector( '.akb-cs-add-btn' );
	trigger( pendingAdd, 'click', { currentTarget: pendingAdd, preventDefault: function () {} } );
	checkoutClick();
	assert.match( statusContainer.textContent, /Espera a que termine/ );
	releaseRecommendation();
	await flush();
	await flush();
	await flush();
	assert.equal( navigations.length, 1 );
	assert.equal( checkoutClick( { ctrlKey: true } ).prevented, false, 'Ctrl+click conserva el comportamiento nativo del enlace' );
	assert.equal( checkoutClick( { metaKey: true } ).prevented, false );

	// Agregar otra vez o reintentar deja disponible un checkout normal.
	dom.window.fetch = async function () { return responder( cartPayload ); };
	checkoutClick();
	await flush();
	assert.equal( navigations.length, 2 );
	dom.window.akbCart.close();
	await showCart( Object.assign( {}, cartPayload, { items: [ Object.assign( {}, cartPayload.items[ 0 ], { title: '<img src=x onerror=alert(1)>' } ) ] } ) );
	assert.equal( itemsContainer.querySelector( '.akb-cart-item-remove' ).getAttribute( 'aria-label' ), 'Eliminar <img src=x onerror=alert(1)> del carrito' );
	assert.equal( itemsContainer.querySelector( '.akb-cart-item-remove img' ), null );
	// An obsolete read cannot overwrite a newer cart even if the transport ignores abort.
	await showCart();
	const pendingReads = [];
	dom.window.fetch = ( _url, options ) => new Promise( resolve => pendingReads.push( { resolve, signal: options.signal } ) );
	dom.window.akbCart.refresh( true );
	dom.window.akbCart.refresh( true );
	assert.equal( pendingReads.length, 2 );
	assert.equal( pendingReads.at( 0 ).signal.aborted, true, 'the replaced request is aborted' );
	assert.equal( pendingReads.at( 1 ).signal.aborted, false );
	const discounted = Object.assign( {}, cartPayload, { subtotal_html: '<span>$20.000</span>', has_discount: true, discount_html: '<span>-$2.000</span>', total_html: '<span>$18.000</span>' } );
	pendingReads.at( 1 ).resolve( responder( discounted ) );
	await flush();
	const freshEvents = cartStates.length;
	pendingReads.at( 0 ).resolve( responder( carritoVacio ) );
	await flush();
	assert.equal( itemsContainer.querySelectorAll( '.akb-cart-item' ).length, 2, 'an old empty response cannot erase the new rows' );
	assert.equal( cartStates.length, freshEvents, 'obsolete reads cannot publish an authoritative cart state' );
	assert.equal( discountRow.hidden, false );
	assert.equal( totalRow.hidden, false );
	assert.equal( subtotalVal.textContent, '$20.000' );
	assert.equal( discountVal.textContent, '-$2.000' );
	assert.equal( totalVal.textContent, '$18.000', 'totals display the server result without recalculating a discount' );
	await showCart( Object.assign( {}, discounted, { discount_html: '' } ) );
	assert.equal( discountRow.hidden, true, 'an incomplete breakdown cannot leave a visible discount' );
	assert.equal( totalRow.hidden, true );
	assert.equal( discountVal.textContent, '' );
	assert.equal( totalVal.textContent, '' );
	await showCart( Object.assign( {}, discounted, { has_discount: false } ) );
	assert.equal( discountRow.hidden, true, 'turning off a discount clears the old state' );
	assert.equal( totalRow.hidden, true );

	// Focus is clamped to the remaining recommendation if the previous list shrinks.
	const twoRecommendations = Object.assign( {}, conRecomendacion, { cross_sells: [ { id: 77, title: 'Frieren 07' }, { id: 78, title: 'Frieren 08' } ] } );
	await showCart( twoRecommendations );
	const lastRecommendation = itemsContainer.querySelectorAll( '.akb-cs-add-btn' ).item( 1 );
	lastRecommendation.focus();
	dom.window.fetch = async ( url ) => String( url ).endsWith( 'wc-ajax=add_to_cart' )
		? { ok: true, json: async () => ( {} ) }
		: responder( conRecomendacion );
	trigger( lastRecommendation, 'click' );
	await flush();
	await flush();
	await expectFocus( () => itemsContainer.querySelector( '.akb-cs-add-btn' ), 'the remaining recommendation receives focus after the list shrinks' );
	assert.equal( storageAccesses, 0, 'the entire cart flow uses session responses and memory, never persistent browser storage' );
	console.log( 'native-cart-accessible-triggers: ok (ARIA, foco, sesión, concurrencia y recuperación)' );
	await dom.teardown();
} )().catch( async function ( err ) {
	await dom.teardown();
	console.error( err );
	process.exitCode = 1;
} );
