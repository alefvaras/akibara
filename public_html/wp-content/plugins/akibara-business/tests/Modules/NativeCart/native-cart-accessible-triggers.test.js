'use strict';

/*
 * Dos fichas del mismo módulo, `assets/js/native-cart.js`, con el mismo
 * denominador: un lector de pantalla no puede distinguir un control de otro.
 *
 * R4-73231a05 (2026-09-19): con 2+ líneas en el cajón, cada botón de fila
 * (eliminar, −, +, el input de cantidad) llevaba el MISMO nombre accesible en
 * todas las filas ("Eliminar producto", "Disminuir cantidad", "Cantidad"...).
 * Un lector de pantalla que las liste (NVDA/JAWS "lista de botones") las
 * anuncia indistinguibles; el cliente puede tocar el control equivocado y
 * borrar o alterar la cantidad de un tomo distinto al que quería.
 *
 * R4-9c9fffc5, la mitad de business (2026-09-19): los disparadores del cajón
 * (icono de cabecera + tab "Carrito" del bottom-nav, ambos `.akb-cart-trigger`)
 * no llevaban `aria-expanded`, así que un lector de pantalla no sabía si el
 * cajón estaba abierto o cerrado. `native-cart.js` tiene que ponerlo en TODOS
 * los triggers al abrir/cerrar, reaplicarlo cuando WooCommerce reemplaza el
 * icono de cabecera por un fragment (que llega siempre con "false"), y
 * hacerlo aunque el tema todavía no declare esos atributos (tema viejo).
 *
 * No hay jsdom en el repo: este fichero monta el DOM mínimo a mano — el mismo
 * patrón que ya usan `AddressAutocomplete/attach-carries-existing-value` y
 * `AnnouncementBar/line-counter` — lo bastante completo para arrancar el
 * módulo de verdad (DOMContentLoaded incluido) y ejercer su comportamiento,
 * no su fuente.
 */

const assert = require( 'node:assert/strict' );

// --- DOM mínimo ---------------------------------------------------------

function FakeFragment() {
	this.children = [];
}
FakeFragment.prototype.querySelectorAll = function () {
	return [];
};
FakeFragment.prototype.cloneNode = function () {
	return new FakeFragment();
};

function parseSimpleSelector( sel ) {
	var notMatch = sel.match( /:not\(([^)]*)\)/ );
	var negative = null;
	var rest = sel;
	if ( notMatch ) {
		negative = notMatch[ 1 ];
		rest = sel.replace( notMatch[ 0 ], '' );
	}
	var tag = null;
	var classes = [];
	var id = null;
	var attrs = [];
	var re = /(^[a-zA-Z][\w-]*)|(\.[\w-]+)|(#[\w-]+)|(\[[^\]]+\])/g;
	var m;
	while ( ( m = re.exec( rest ) ) ) {
		if ( m[ 1 ] ) tag = m[ 1 ].toLowerCase();
		else if ( m[ 2 ] ) classes.push( m[ 2 ].slice( 1 ) );
		else if ( m[ 3 ] ) id = m[ 3 ].slice( 1 );
		else if ( m[ 4 ] ) {
			var body = m[ 4 ].slice( 1, -1 );
			var eq = body.indexOf( '=' );
			if ( eq === -1 ) attrs.push( { name: body, value: null } );
			else attrs.push( { name: body.slice( 0, eq ), value: body.slice( eq + 1 ).replace( /^["']|["']$/g, '' ) } );
		}
	}
	return { tag: tag, classes: classes, id: id, attrs: attrs, negative: negative };
}

function matchesSimple( node, parsed ) {
	if ( ! node || ! node.tagName ) return false;
	if ( parsed.tag && node.tagName.toLowerCase() !== parsed.tag ) return false;
	if ( parsed.id && node.getAttribute( 'id' ) !== parsed.id ) return false;
	for ( var i = 0; i < parsed.classes.length; i++ ) {
		if ( ! node.classList.contains( parsed.classes[ i ] ) ) return false;
	}
	for ( var j = 0; j < parsed.attrs.length; j++ ) {
		var a = parsed.attrs[ j ];
		if ( ! node.hasAttribute( a.name ) ) return false;
		if ( a.value !== null && node.getAttribute( a.name ) !== a.value ) return false;
	}
	if ( parsed.negative && matchesSimple( node, parseSimpleSelector( parsed.negative.trim() ) ) ) return false;
	return true;
}

function selectorMatches( node, selector ) {
	return selector.split( ',' ).some( function ( part ) {
		return matchesSimple( node, parseSimpleSelector( part.trim() ) );
	} );
}

function collect( node, selector, results ) {
	( node.children || [] ).forEach( function ( child ) {
		if ( selectorMatches( child, selector ) ) results.push( child );
		collect( child, selector, results );
	} );
}

function FakeClassList( el ) {
	this.el = el;
	this._set = new Set();
}
FakeClassList.prototype.add = function () {
	var self = this;
	Array.prototype.forEach.call( arguments, function ( c ) { self._set.add( c ); } );
};
FakeClassList.prototype.remove = function () {
	var self = this;
	Array.prototype.forEach.call( arguments, function ( c ) { self._set.delete( c ); } );
};
FakeClassList.prototype.contains = function ( c ) {
	return this._set.has( c );
};
FakeClassList.prototype.toggle = function ( c, force ) {
	if ( force === undefined ) {
		if ( this._set.has( c ) ) { this._set.delete( c ); return false; }
		this._set.add( c );
		return true;
	}
	if ( force ) this._set.add( c ); else this._set.delete( c );
	return force;
};

function FakeElement( tagName ) {
	this.tagName = String( tagName || 'div' ).toUpperCase();
	this._attrs = {};
	this.classList = new FakeClassList( this );
	this.children = [];
	this.parentNode = null;
	this.dataset = {};
	this.style = {};
	this._listeners = {};
	this._text = '';
	this.disabled = false;
	this.readOnly = false;
	if ( this.tagName === 'TEMPLATE' ) {
		this.content = new FakeFragment();
	}
}
Object.defineProperty( FakeElement.prototype, 'className', {
	get: function () { return Array.from( this.classList._set ).join( ' ' ); },
	set: function ( v ) { this.classList._set = new Set( String( v ).split( /\s+/ ).filter( Boolean ) ); },
} );
Object.defineProperty( FakeElement.prototype, 'innerHTML', {
	get: function () { return this._rawHtml || ''; },
	set: function ( v ) { this.children = []; this._rawHtml = v; },
} );
Object.defineProperty( FakeElement.prototype, 'textContent', {
	get: function () {
		if ( this.children.length ) {
			return this.children.map( function ( c ) { return typeof c.textContent === 'string' ? c.textContent : ''; } ).join( '' );
		}
		return this._text;
	},
	set: function ( v ) { this.children = []; this._text = v == null ? '' : String( v ); },
} );
Object.defineProperty( FakeElement.prototype, 'firstChild', {
	get: function () { return this.children[ 0 ] || null; },
} );
FakeElement.prototype.setAttribute = function ( name, value ) {
	this._attrs[ name ] = String( value );
	if ( name === 'class' ) this.className = value;
};
FakeElement.prototype.getAttribute = function ( name ) {
	return Object.prototype.hasOwnProperty.call( this._attrs, name ) ? this._attrs[ name ] : null;
};
FakeElement.prototype.hasAttribute = function ( name ) {
	return Object.prototype.hasOwnProperty.call( this._attrs, name );
};
FakeElement.prototype.removeAttribute = function ( name ) {
	delete this._attrs[ name ];
};
FakeElement.prototype.toggleAttribute = function ( name, force ) {
	var has = this.hasAttribute( name );
	var want = force === undefined ? ! has : force;
	if ( want ) this.setAttribute( name, '' ); else this.removeAttribute( name );
	return want;
};
FakeElement.prototype.appendChild = function ( child ) {
	this.children.push( child );
	if ( child ) child.parentNode = this;
	return child;
};
FakeElement.prototype.removeChild = function ( child ) {
	var i = this.children.indexOf( child );
	if ( i > -1 ) this.children.splice( i, 1 );
	return child;
};
FakeElement.prototype.addEventListener = function ( type, fn ) {
	( this._listeners[ type ] = this._listeners[ type ] || [] ).push( fn );
};
FakeElement.prototype.removeEventListener = function ( type, fn ) {
	var list = this._listeners[ type ] || [];
	var i = list.indexOf( fn );
	if ( i > -1 ) list.splice( i, 1 );
};
FakeElement.prototype.trigger = function ( type, evt ) {
	( this._listeners[ type ] || [] ).slice().forEach( function ( fn ) { fn( evt || { type: type } ); } );
};
// Registra el foco en el documento falso: la revisión REV-X1-2-D1 mira a
// quién se lo devuelve el cajón al cerrarse.
FakeElement.prototype.focus = function () {
	if ( global.document ) {
		global.document.activeElement = this;
	}
};
FakeElement.prototype.querySelector = function ( sel ) {
	var results = [];
	collect( this, sel, results );
	return results[ 0 ] || null;
};
FakeElement.prototype.querySelectorAll = function ( sel ) {
	var results = [];
	collect( this, sel, results );
	return results;
};
FakeElement.prototype.getElementById = function ( id ) {
	return this.querySelector( '#' + id );
};
FakeElement.prototype.createElement = function ( tag ) {
	return new FakeElement( tag );
};
FakeElement.prototype.closest = function ( sel ) {
	var node = this;
	while ( node ) {
		if ( selectorMatches( node, sel ) ) return node;
		node = node.parentNode;
	}
	return null;
};
FakeElement.prototype.contains = function ( node ) {
	var n = node;
	while ( n ) {
		if ( n === this ) return true;
		n = n.parentNode;
	}
	return false;
};
FakeElement.prototype.getClientRects = function () {
	return [ {} ];
};

// --- Documento: cabecera (icono), bottom-nav (tab) y el drawer ---------

var doc = new FakeElement( '#document' );
var docBody = new FakeElement( 'body' );
doc.appendChild( docBody );
doc.body = docBody;
doc.activeElement = null;

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
headerTrigger.setAttribute( 'aria-label', 'Carrito' );

var bottomNavTrigger = doc.createElement( 'a' );
bottomNavTrigger.classList.add( 'bottom-nav-tab', 'akb-cart-trigger' );
bottomNavTrigger.setAttribute( 'aria-label', 'Carrito' );

docBody.appendChild( overlay );
docBody.appendChild( drawer );
docBody.appendChild( headerTrigger );
docBody.appendChild( bottomNavTrigger );

global.document = doc;

// --- window, jQuery falso y fetch falso ---------------------------------

var jqueryBodyHandlers = {};
var windowEvents = new FakeElement( 'window' );
var navigations = [];

global.window = {
	addEventListener: windowEvents.addEventListener.bind( windowEvents ),
	location: { assign: function ( url ) { navigations.push( url ); } },
	akbNativeCart: {
		enabled: true,
		ajaxUrl: 'https://akibara.test/?wc-ajax=%%endpoint%%',
		copy: {
			checkingCart: 'Comprobando…',
			checkoutWait: 'Espera a que termine de actualizarse tu carrito.',
			cartEmptied: 'Tu carrito está vacío. Agrega nuevamente tus productos para continuar.',
		},
	},
	jQuery: function () {
		return {
			on: function ( events, handler ) {
				events.split( /\s+/ ).forEach( function ( evt ) {
					( jqueryBodyHandlers[ evt ] = jqueryBodyHandlers[ evt ] || [] ).push( handler );
				} );
			},
			trigger: function ( evt ) {
				( jqueryBodyHandlers[ evt ] || [] ).forEach( function ( fn ) { fn( { type: evt } ); } );
			},
		};
	},
	// Sin reloj real: los tests no verifican foco diferido, y un timer de
	// verdad obligaría a esperar en cada aserción.
	setTimeout: function ( fn ) { fn(); },
	console: console,
};

var cartPayload = {
	items: [
		{ title: 'Frieren 06', quantity: 1, keys_to_remove: [ 'key-frieren' ], max: -1, price_html: '' },
		{ title: 'Jujutsu Kaisen 12', quantity: 2, keys_to_remove: [ 'key-jjk' ], max: 5, price_html: '' },
	],
	cart_count: 2,
	subtotal_html: '',
	has_discount: false,
	discount_html: '',
	total_html: '',
	shipping_notice: '',
	nonce: 'test-nonce',
};

global.fetch = async function () {
	return {
		ok: true,
		json: async function () {
			return { success: true, data: cartPayload };
		},
	};
};

global.CustomEvent = global.CustomEvent || function ( type, params ) {
	this.type = type;
	this.detail = params && params.detail;
};

require( '../../../assets/js/native-cart.js' );

// El módulo entero cuelga de DOMContentLoaded: `document.addEventListener`
// arriba es el mismo objeto FakeElement, así que dispararlo a mano arranca
// el módulo tal como lo haría el navegador.
doc.trigger( 'DOMContentLoaded' );

function flush() {
	return new Promise( function ( resolve ) { setImmediate( resolve ); } );
}

( async function () {
	// --- R4-9c9fffc5: estado inicial, aunque el tema no traiga los atributos ---
	assert.equal( headerTrigger.getAttribute( 'aria-expanded' ), 'false', 'sin abrir el cajón, el trigger debe declarar aria-expanded="false" desde el arranque' );
	assert.equal( headerTrigger.getAttribute( 'aria-haspopup' ), 'dialog', 'un tema viejo sin aria-haspopup debe recibirlo igual' );
	assert.equal( bottomNavTrigger.getAttribute( 'aria-expanded' ), 'false', 'el tab del bottom-nav es OTRO trigger y también debe quedar sincronizado' );
	assert.equal( bottomNavTrigger.getAttribute( 'aria-haspopup' ), 'dialog' );

	// --- Abrir: TODOS los triggers pasan a expanded=true ---
	global.window.akbCart.open( headerTrigger );
	await flush();

	assert.equal( headerTrigger.getAttribute( 'aria-expanded' ), 'true', 'abrir el cajón debe marcar expanded=true en el trigger que lo abrió' );
	assert.equal(
		bottomNavTrigger.getAttribute( 'aria-expanded' ),
		'true',
		'el OTRO trigger (bottom-nav) no disparó la apertura pero controla el MISMO diálogo: también debe quedar en true'
	);

	// --- R4-73231a05: nombres accesibles por producto, no genéricos ---
	var rows = itemsContainer.querySelectorAll( '.akb-cart-item' );
	assert.equal( rows.length, 2, 'debieron pintarse las 2 líneas del carrito' );

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
	assert.equal( frieren.querySelector( '.akb-cart-item-remove' ).children.length, 0, 'aria-label es un atributo de texto, no marcado' );

	// --- R4-9c9fffc5: el fragment de WooCommerce reemplaza el icono con
	// aria-expanded="false" fijo; native-cart.js tiene que reaplicar el
	// estado real cuando eso pasa (vía el canal jQuery real Y su
	// re-emisión nativa `akb:*`, por si jQuery deja de estar disponible). ---
	headerTrigger.setAttribute( 'aria-expanded', 'false' ); // simula el fragment reemplazado
	docBody.trigger( 'akb:wc_fragments_refreshed' );
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
	global.window.akbCart.close();
	assert.equal( headerTrigger.getAttribute( 'aria-expanded' ), 'false' );
	assert.equal( bottomNavTrigger.getAttribute( 'aria-expanded' ), 'false', 'cerrar debe apagar el estado en AMBOS triggers' );
	assert.equal( doc.activeElement, headerTrigger, 'sin refresco de por medio, el foco vuelve al icono que abrió el cajón' );

	// --- REV-X1-2-D1: el cajón pide `wc_fragment_refresh` tras cada cambio
	// (cantidad, «Eliminar», recomendación agregada) y WooCommerce cambia el
	// icono de cabecera por un nodo NUEVO en el mismo lugar (jQuery
	// replaceWith). Al cerrar, el icono que abrió el cajón ya no estaba en el
	// documento y el foco caía al <body>: el siguiente Tab volvía a «Saltar al
	// contenido» (medido en el espejo en Chromium y WebKit). ---
	function reemplazarPorFragment( viejo ) {
		var nuevo = doc.createElement( 'a' );
		nuevo.className = viejo.className;
		nuevo.setAttribute( 'aria-label', 'Carrito' );
		nuevo.setAttribute( 'aria-expanded', 'false' );
		var puesto = docBody.children.indexOf( viejo );
		docBody.children.splice( puesto, 1, nuevo );
		nuevo.parentNode = docBody;
		viejo.parentNode = null;
		return nuevo;
	}

	headerTrigger.focus();
	global.window.akbCart.open( headerTrigger );
	await flush();
	assert.equal( doc.activeElement, closeBtn, 'al abrir, el foco entra al cajón' );
	var iconoNuevo = reemplazarPorFragment( headerTrigger );
	assert.equal( doc.contains( headerTrigger ), false );
	global.window.akbCart.close();
	assert.equal(
		doc.activeElement,
		iconoNuevo,
		'el foco debe volver al icono NUEVO que dejó el fragment, no perderse con el que ya no está en el documento'
	);
	assert.equal( iconoNuevo.getAttribute( 'aria-expanded' ), 'false' );

	// Abierto desde el tab del bottom-nav (que no es fragment) mientras el
	// icono de cabecera se refresca: el foco vuelve al tab, no al icono.
	bottomNavTrigger.focus();
	global.window.akbCart.open( bottomNavTrigger );
	await flush();
	reemplazarPorFragment( iconoNuevo );
	global.window.akbCart.close();
	assert.equal( doc.activeElement, bottomNavTrigger, 'se devuelve al disparador que abrió, no al primero de la lista' );

	// Abierto desde algo que no es un disparador (p. ej. el alta que lo abre
	// sola) y que desapareció: no se inventa un destino entre los disparadores.
	var boton = doc.createElement( 'button' );
	docBody.appendChild( boton );
	boton.focus();
	global.window.akbCart.open();
	await flush();
	docBody.removeChild( boton );
	boton.parentNode = null;
	global.window.akbCart.close();
	assert.equal(
		doc.querySelectorAll( '.akb-cart-trigger' ).indexOf( doc.activeElement ),
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
		global.fetch = async function () {
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
	global.window.akbCart.open( disparador );
	await flush();
	global.window.akbCart.close();
	assert.equal( doc.activeElement, disparador, 'cerrar devuelve el foco al disparador' );
	soltarLectura();
	await flush();
	await flush();
	assert.equal( itemsContainer.querySelectorAll( '.akb-cart-item' ).length, 0, 'la lectura tardía sí pintó el carrito vacío' );
	assert.equal(
		doc.activeElement,
		disparador,
		'la lectura que vuelve con el carrito vacío después de cerrar no puede mover el foco al botón de cerrar del cajón oculto'
	);

	// Lo mismo con una mutación: «Eliminar» sale, el cliente cierra antes de
	// que vuelva la respuesta, y restorePoint() buscaba el control equivalente
	// dentro del cajón ya cerrado.
	global.fetch = async function () {
		return { ok: true, json: async function () { return { success: true, data: cartPayload }; } };
	};
	global.window.akbCart.open( disparador );
	await flush();
	await flush();
	var eliminar = itemsContainer.querySelector( '.akb-cart-item-remove' );
	assert.ok( eliminar, 'con el carrito lleno hay un «Eliminar» que tocar' );
	eliminar.focus();
	var soltarMutacion = respuestaRetenida( carritoVacio );
	eliminar.trigger( 'click' );
	global.window.akbCart.close();
	assert.equal( doc.activeElement, disparador );
	soltarMutacion();
	await flush();
	await flush();
	assert.equal(
		doc.activeElement,
		disparador,
		'la respuesta de «Eliminar» que llega después de cerrar tampoco puede llevarse el foco al cajón oculto'
	);

	function responder( data ) {
		return { ok: true, json: async function () { return { success: true, data: data }; } };
	}

	// La otra rama de restorePoint(), la del carrito que sigue con filas
	// (REV-FN1-2): «+» sale, el cliente cierra antes de que vuelva, y el
	// foco iba al «+» equivalente de la fila recién pintada en el cajón oculto.
	global.fetch = async function () { return responder( cartPayload ); };
	global.window.akbCart.open( disparador );
	await flush();
	await flush();
	var mas = itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ];
	assert.equal( mas.disabled, false, 'Jujutsu Kaisen 12 lleva 2 de 5: su «+» se puede tocar' );
	mas.focus();
	var soltarMas = respuestaRetenida( cartPayload );
	mas.trigger( 'click' );
	global.window.akbCart.close();
	assert.equal( doc.activeElement, disparador );
	soltarMas();
	await flush();
	await flush();
	assert.equal( itemsContainer.querySelectorAll( '.akb-cart-item' ).length, 2, 'la respuesta tardía de «+» sí repintó las dos filas' );
	assert.equal(
		doc.activeElement,
		disparador,
		'la respuesta de «+» que llega después de cerrar, con el carrito aún lleno, no puede llevarse el foco a la fila del cajón oculto'
	);

	// Abierto, sigue lo de siempre: el foco vuelve al «+» de la misma fila.
	global.fetch = async function () { return responder( cartPayload ); };
	global.window.akbCart.open( disparador );
	await flush();
	await flush();
	itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ].focus();
	itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ].trigger( 'click' );
	await flush();
	await flush();
	assert.equal( doc.activeElement, itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ], 'abierto, tras «+» el foco queda en el «+» nuevo de la misma fila' );
	global.window.akbCart.close();

	// Lo mismo al agregar una recomendación (REV-FN1-2): «Agregar» sale, el
	// cliente cierra, y al volver add_to_cart y la lectura del carrito,
	// restoreFocusAfterRecommendation() enfocaba el «Agregar» del cajón oculto.
	var conRecomendacion = Object.assign( {}, cartPayload, {
		settings: { showRecommendations: true },
		cross_sells: [ { id: 77, title: 'Frieren 07', price_html: '' } ],
	} );
	global.fetch = async function () { return responder( conRecomendacion ); };
	global.window.akbCart.open( disparador );
	await flush();
	await flush();
	var agregar = itemsContainer.querySelector( '.akb-cs-add-btn' );
	assert.ok( agregar, 'con las recomendaciones encendidas hay un «Agregar» que tocar' );
	agregar.focus();
	var soltarAgregar;
	var agregarRetenido = new Promise( function ( resolve ) { soltarAgregar = resolve; } );
	global.fetch = async function ( url ) {
		if ( /wc-ajax=add_to_cart$/.test( url ) ) {
			await agregarRetenido;
			return { ok: true, json: async function () { return {}; } };
		}
		return responder( conRecomendacion );
	};
	liveRegion.textContent = '';
	agregar.trigger( 'click', { type: 'click', currentTarget: agregar, preventDefault: function () {} } );
	global.window.akbCart.close();
	assert.equal( doc.activeElement, disparador );
	soltarAgregar();
	await flush();
	await flush();
	await flush();
	assert.equal( liveRegion.textContent, 'Agregado al carrito.', 'la recomendación tardía sí terminó de agregarse' );
	assert.equal(
		doc.activeElement,
		disparador,
		'«Agregar» una recomendación que responde después de cerrar no puede llevarse el foco al cajón oculto'
	);

	// Abierto, el foco pasa al «Agregar» de la lista recién pintada.
	global.fetch = async function () { return responder( conRecomendacion ); };
	global.window.akbCart.open( disparador );
	await flush();
	await flush();
	itemsContainer.querySelector( '.akb-cs-add-btn' ).focus();
	itemsContainer.querySelector( '.akb-cs-add-btn' ).trigger( 'click', { type: 'click', currentTarget: itemsContainer.querySelector( '.akb-cs-add-btn' ), preventDefault: function () {} } );
	await flush();
	await flush();
	await flush();
	assert.equal( doc.activeElement, itemsContainer.querySelector( '.akb-cs-add-btn' ), 'abierto, tras agregar el foco queda en el «Agregar» nuevo' );
	global.window.akbCart.close();

	// Con el cajón ABIERTO el comportamiento de siempre sigue: vaciarlo por
	// mutación elimina el control enfocado y el foco va al botón de cerrar.
	global.fetch = async function () {
		return { ok: true, json: async function () { return { success: true, data: cartPayload }; } };
	};
	global.window.akbCart.open( disparador );
	await flush();
	await flush();
	itemsContainer.querySelector( '.akb-cart-item-remove' ).focus();
	var soltarAbierto = respuestaRetenida( carritoVacio );
	itemsContainer.querySelector( '.akb-cart-item-remove' ).trigger( 'click' );
	soltarAbierto();
	await flush();
	await flush();
	assert.equal( doc.activeElement, closeBtn, 'abierto, vaciar el carrito deja el foco en el botón de cerrar, no en el <body>' );
	global.window.akbCart.close();

	// El carrito pintado no autoriza el checkout: la sesión pudo desaparecer.
	function checkoutClick( overrides ) {
		var event = Object.assign( { button: 0, prevented: false, preventDefault: function () { this.prevented = true; } }, overrides );
		checkoutButton.trigger( 'click', event );
		return event;
	}
	async function showCart( payload ) {
		global.fetch = async function () { return responder( payload || cartPayload ); };
		if ( drawer.classList.contains( 'akb-open' ) ) global.window.akbCart.refresh( true );
		else global.window.akbCart.open( disparador );
		await flush();
		await flush();
	}
	await showCart();
	var reads = 0;
	global.fetch = async function ( url, options ) {
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
	var pendingCheckoutFetch = global.fetch;
	reads = 0;
	global.fetch = function () { reads++; return pendingCheckoutFetch(); };
	checkoutClick();
	checkoutClick();
	assert.equal( checkoutButton.textContent, 'Comprobando…' );
	itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ].trigger( 'click' );
	var blockedRecommendation = itemsContainer.querySelector( '.akb-cs-add-btn' );
	blockedRecommendation.trigger( 'click', { currentTarget: blockedRecommendation, preventDefault: function () {} } );
	windowEvents.trigger( 'focus' );
	doc.trigger( 'visibilitychange' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );
	assert.equal( reads, 1, 'ni doble click, ni mutación, ni regreso de pestaña interrumpen el checkout' );
	releaseCheckout();
	await flush();
	await flush();
	assert.deepEqual( navigations, [ checkoutButton.href ] );
	global.fetch = async function () { return responder( cartPayload ); };
	windowEvents.trigger( 'pageshow', { persisted: true } );
	assert.equal( checkoutButton.textContent, 'Pagar pedido', 'volver desde bfcache no deja el botón bloqueado' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );

	// Red caída: nunca continuar con cache y ofrecer recuperación por teclado.
	global.fetch = async function () { throw new Error( 'offline' ); };
	checkoutClick();
	await flush();
	assert.equal( navigations.length, 1 );
	assert.equal( footer.hasAttribute( 'hidden' ), true );
	var retry = itemsContainer.querySelector( 'button.akb-cart-empty__cta' );
	assert.equal( retry.textContent, 'Reintentar' );
	assert.equal( doc.activeElement, retry, 'el foco queda en una acción visible al fallar la lectura' );
	global.fetch = async function () { return responder( cartPayload ); };
	retry.trigger( 'click' );
	await flush();
	assert.equal( footer.hasAttribute( 'hidden' ), false );
	assert.equal( itemsContainer.querySelectorAll( '.akb-cart-item' ).length, 2 );

	// Una respuesta inválida tampoco se interpreta como autorización de pago.
	global.fetch = async function () { return responder( { items: cartPayload.items, cart_count: 'inventado' } ); };
	checkoutClick();
	await flush();
	assert.equal( navigations.length, 1 );
	assert.ok( itemsContainer.querySelector( 'button.akb-cart-empty__cta' ) );

	// Cerrar cancela la intención; una respuesta tardía no puede navegar.
	await showCart();
	var releaseClosed = respuestaRetenida( cartPayload );
	checkoutClick();
	global.window.akbCart.close();
	releaseClosed();
	await flush();
	assert.equal( navigations.length, 1 );
	assert.equal( doc.activeElement, disparador );
	assert.equal( checkoutButton.getAttribute( 'aria-busy' ), 'false' );

	// Pestaña cerrada/inactiva: sin consultas. Al volver: una sola y sin polling.
	reads = 0;
	global.fetch = async function () { reads++; return responder( carritoVacio ); };
	windowEvents.trigger( 'focus' );
	doc.trigger( 'visibilitychange' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );
	assert.equal( reads, 0 );
	await showCart();
	reads = 0;
	global.fetch = async function () { reads++; return responder( carritoVacio ); };
	doc.visibilityState = 'hidden';
	doc.trigger( 'visibilitychange' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );
	assert.equal( reads, 0 );
	doc.visibilityState = 'visible';
	doc.trigger( 'visibilitychange' );
	windowEvents.trigger( 'focus' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );
	assert.equal( reads, 1 );
	assert.equal( footer.hasAttribute( 'hidden' ), true );
	await new Promise( resolve => setTimeout( resolve, 350 ) );
	assert.equal( reads, 1, 'sin polling al mantener abierto el cajón' );

	// Volver a la pestaña no pierde el foco ni recupera uno que ya se movió.
	await showCart( conRecomendacion );
	itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ].focus();
	windowEvents.trigger( 'focus' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );
	assert.equal( doc.activeElement, itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ] );
	itemsContainer.querySelector( '.akb-cs-add-btn' ).focus();
	windowEvents.trigger( 'focus' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );
	assert.equal( doc.activeElement, itemsContainer.querySelector( '.akb-cs-add-btn' ) );
	var releaseFocus = respuestaRetenida( conRecomendacion );
	itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ].focus();
	windowEvents.trigger( 'focus' );
	await new Promise( resolve => setTimeout( resolve, 180 ) );
	closeBtn.focus();
	releaseFocus();
	await flush();
	assert.equal( doc.activeElement, closeBtn, 'una respuesta tardía respeta el foco movido al botón de cerrar' );

	// Si cantidad o recomendación siguen escribiéndose, Pagar espera.
	await showCart();
	var releaseQuantity = respuestaRetenida( cartPayload );
	itemsContainer.querySelectorAll( '.akb-qty-plus' )[ 1 ].trigger( 'click' );
	checkoutClick();
	assert.match( statusContainer.textContent, /Espera a que termine/ );
	releaseQuantity();
	await flush();
	assert.equal( navigations.length, 1 );
	await showCart( conRecomendacion );
	var releaseRecommendation = respuestaRetenida( conRecomendacion );
	var pendingAdd = itemsContainer.querySelector( '.akb-cs-add-btn' );
	pendingAdd.trigger( 'click', { currentTarget: pendingAdd, preventDefault: function () {} } );
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
	global.fetch = async function () { return responder( cartPayload ); };
	checkoutClick();
	await flush();
	assert.equal( navigations.length, 2 );
	global.window.akbCart.close();
	console.log( 'native-cart-accessible-triggers: ok (ARIA, foco, sesión, concurrencia y recuperación)' );
} )().catch( function ( err ) {
	console.error( err );
	process.exitCode = 1;
} );
