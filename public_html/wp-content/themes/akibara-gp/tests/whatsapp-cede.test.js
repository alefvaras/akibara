'use strict';

/*
 * UM-16: el botón flotante de WhatsApp (Joinchat, tercero) cede ante un botón
 * de compra que queda debajo con el scroll en reposo.
 *
 * Se ejecuta el bloque real de main.js en un `vm` con un `elementsFromPoint`
 * de mentira: lo que hay bajo el botón decide la clase del body.
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const raiz = path.resolve( __dirname, '..' );
const main = fs.readFileSync( path.join( raiz, 'assets/js/main.js' ), 'utf8' );
const footer = fs.readFileSync( path.join( raiz, 'assets/css/footer.css' ), 'utf8' );

const inicio = main.indexOf( '/* ── El botón de WhatsApp cede' );
const bloque = inicio < 0 ? '' : main.slice( inicio, main.lastIndexOf( '})();' ) );

function entorno( bajo ) {
	const clases = new Set();
	const oyentesBody = {};
	const oyentesDoc = {};
	const body = {
		classList: { toggle( c, si ) { if ( si ) { clases.add( c ); } else { clases.delete( c ); } } },
		addEventListener: ( ev, fn ) => { oyentesBody[ ev ] = fn; },
	};
	const boton = { getBoundingClientRect: () => ( { left: 332, top: 646, width: 44, height: 44, right: 376, bottom: 690 } ) };
	const fab = { querySelector: () => boton };
	const oyentes = {};
	const temporizadores = [];
	const doc = {
		querySelector: ( s ) => ( '.joinchat' === s ? fab : null ),
		elementsFromPoint: () => bajo.lista,
		// Botones con la petición en vuelo (pointer-events:none): elementsFromPoint
		// no los devuelve, se miran por su caja. Cada uno declara el selector que
		// cumple (por defecto, el `.loading` de la tarjeta) y sólo sale si el
		// guion lo pide.
		querySelectorAll: ( s ) => {
			const partes = s.split( ', ' );
			return ( bajo.enVuelo || [] )
				.filter( ( v ) => partes.includes( v.sel || 'li.product .button.loading' ) )
				.map( ( v ) => ( { getBoundingClientRect: () => v.rect || v } ) );
		},
		addEventListener: ( ev, fn ) => { oyentesDoc[ ev ] = fn; },
	};
	// Lo que observa cada ResizeObserver, con su aviso: `tamano.aviso( body )`
	// simula que ese elemento cambió de tamaño.
	const observados = new Map();
	const window = {
		addEventListener: ( ev, fn ) => { oyentes[ ev ] = fn; },
		setTimeout: ( fn ) => { temporizadores.push( fn ); return temporizadores.length; },
		clearTimeout: () => {},
		MutationObserver: class { observe() {} },
		ResizeObserver: class {
			constructor( fn ) { this.fn = fn; }
			observe( el ) { observados.set( el, this.fn ); }
		},
	};
	const ctx = vm.createContext( { doc, body, window, MutationObserver: window.MutationObserver } );
	vm.runInContext( '(function(){' + bloque + '})();', ctx );
	return {
		cede: () => clases.has( 'akb-fab-cede' ),
		reposo() { while ( temporizadores.length ) { temporizadores.shift()(); } },
		oyentes,
		oyentesBody,
		oyentesDoc,
		body,
		tamano: { observa: ( el ) => observados.has( el ), aviso: ( el ) => observados.get( el )( [] ) },
	};
}

/*
 * Lo que hay bajo el botón se describe como es en el HTML servido
 * (`etiqueta.clase[atributo]`, y el antepasado que importa delante con un
 * espacio), y `closest()` evalúa el selector de verdad: etiqueta, clases,
 * atributos, `:not()` y descendiente. Antes sólo se preguntaba si el texto del
 * selector aparecía en la lista de main.js, y la mitad negativa de REV-X1-2-D3
 * no comprobaba nada: añadir a COMPRA un selector que también atrapara el
 * «Agotado» deshabilitado dejaba todas las pruebas en verde (revisión
 * REV-X1-2b-D1).
 */
const COMPUESTO = /:not\([^)]*\)|\.[\w-]+|\[[\w-]+\]|^[a-z][a-z0-9]*/g;

function partesDe( compuesto ) {
	const partes = compuesto.match( COMPUESTO ) || [];
	assert.equal( partes.join( '' ), compuesto, `selector que el arnés no sabe leer: «${ compuesto }»` );
	return partes;
}

function cumpleCompuesto( el, compuesto ) {
	return partesDe( compuesto ).every( ( p ) => {
		if ( p.startsWith( ':not(' ) ) {
			return ! cumpleCompuesto( el, p.slice( 5, -1 ) );
		}
		if ( p.startsWith( '.' ) ) {
			return el.clases.includes( p.slice( 1 ) );
		}
		if ( p.startsWith( '[' ) ) {
			return el.atributos.includes( p.slice( 1, -1 ) );
		}
		return el.etiqueta === p;
	} );
}

/** ¿`el` cumple `selector` (compuestos separados por espacio = descendiente)? */
function cumple( el, selector ) {
	const cadena = selector.trim().split( /\s+/ );
	if ( ! cumpleCompuesto( el, cadena.pop() ) ) {
		return false;
	}
	for ( let n = el.padre; n && cadena.length; n = n.padre ) {
		if ( cumpleCompuesto( n, cadena[ cadena.length - 1 ] ) ) {
			cadena.pop();
		}
	}
	return 0 === cadena.length;
}

/** Un nodo descrito como el HTML servido: 'li.product button.button[data-product_id]'. */
function elemento( descripcion ) {
	let padre = null;
	for ( const compuesto of descripcion.trim().split( /\s+/ ) ) {
		const partes = partesDe( compuesto );
		const n = {
			etiqueta: /^[a-z]/.test( partes[ 0 ] ) ? partes[ 0 ] : 'div',
			clases: partes.filter( ( x ) => x.startsWith( '.' ) ).map( ( x ) => x.slice( 1 ) ),
			atributos: partes.filter( ( x ) => x.startsWith( '[' ) ).map( ( x ) => x.slice( 1, -1 ) ),
			padre,
			closest( lista ) {
				for ( let m = this; m; m = m.padre ) {
					if ( lista.split( ',' ).some( ( sel ) => cumple( m, sel ) ) ) {
						return m;
					}
				}
				return null;
			},
		};
		padre = n;
	}
	return padre;
}

const joinchat = elemento( 'div.joinchat button.joinchat__button' );
const texto = elemento( 'li.product h2.woocommerce-loop-product__title' );

// El HTML servido de cada control (espejo, 2026-09-25).
const HTML = {
	anadir: 'li.product button.button.product_type_simple.add_to_cart_button.ajax_add_to_cart[data-product_id]',
	motivo: 'li.product p.akb-tarjeta-motivo',
	ficha: 'form.cart button.single_add_to_cart_button.button.alt',
};

test( 'el bloque existe en main.js', () => {
	assert.ok( bloque.length > 0 );
	assert.match( bloque, /elementsFromPoint/ );
	assert.match( bloque, /akb-fab-cede/ );
} );

test( 'con un «Añadir al carrito» debajo, el botón de WhatsApp cede; sin él, vuelve', () => {
	const bajo = { lista: [ joinchat, elemento( HTML.anadir ) ] };
	const e = entorno( bajo );
	e.reposo();
	assert.equal( e.cede(), true );

	// Se hace scroll: nada cambia hasta que el scroll se detiene.
	bajo.lista = [ joinchat, texto ];
	e.oyentes.scroll();
	assert.equal( e.cede(), true, 'durante el scroll no parpadea' );
	e.reposo();
	assert.equal( e.cede(), false, 'en reposo, sin compra debajo, vuelve' );
} );

test( 'también cede ante el botón real de la ficha', () => {
	const e = entorno( { lista: [ joinchat, elemento( HTML.ficha ) ] } );
	e.reposo();
	assert.equal( e.cede(), true );
} );

test( 'RV2-T2-1: el aviso de rechazo de la tarjeta también cuenta como compra (su «Ver carrito»)', () => {
	const e = entorno( { lista: [ joinchat, elemento( HTML.motivo ) ] } );
	e.reposo();
	assert.equal( e.cede(), true );
} );

test( 'RV2-T2-1: un botón con la petición en vuelo (.loading) cuenta aunque elementsFromPoint no lo vea', () => {
	// Caja del botón de la tarjeta bajo el de WhatsApp (332-376 × 646-690).
	const bajo = { lista: [ joinchat, texto ], enVuelo: [ { left: 205, right: 375, top: 660, bottom: 704, width: 170, height: 44 } ] };
	const e = entorno( bajo );
	e.reposo();
	assert.equal( e.cede(), true, 'con un scroll a mitad de la petición, WhatsApp volvía encima' );

	// Uno en vuelo que NO está debajo no lo retira.
	bajo.enVuelo = [ { left: 16, right: 186, top: 660, bottom: 704, width: 170, height: 44 } ];
	e.oyentes.scroll();
	e.reposo();
	assert.equal( e.cede(), false );
} );

/*
 * RT-REV1-D1 (revisión del lote R-T): la ficha tiene la misma causa. Su botón real
 * lleva `.is-adding` con la petición en vuelo, y woocommerce.css le quita el puntero,
 * así que elementsFromPoint tampoco lo ve.
 */
test( 'RT-REV1-D1: el botón real de la ficha en vuelo (.is-adding) también cuenta', () => {
	const debajo = { left: 16, right: 374, top: 650, bottom: 700, width: 358, height: 50 };
	const arriba = { left: 16, right: 374, top: 100, bottom: 150, width: 358, height: 50 };
	const sel = '.single_add_to_cart_button.is-adding';
	const bajo = { lista: [ joinchat, texto ], enVuelo: [ { sel, rect: debajo } ] };
	const e = entorno( bajo );
	e.reposo();
	assert.equal( e.cede(), true, `${ sel }: con un scroll a mitad de la petición, WhatsApp volvía encima` );

	bajo.enVuelo = [ { sel, rect: arriba } ];
	e.oyentes.scroll();
	e.reposo();
	assert.equal( e.cede(), false, `${ sel }: lejos, no cede` );
} );

/*
 * RT-REV2-D2 (revisión 2 del lote R-T): el corazón de favoritos cuenta como compra
 * (QA-MOV-6) y tiene la misma causa. Con su petición en vuelo, wishlist.js
 * (lockButtons) le pone `.is-loading` y wishlist.css le quita el puntero, así que
 * elementsFromPoint no lo ve. Un scroll a mitad de la petición dejaba WhatsApp en
 * reposo encima del corazón —368 de 484 puntos en Chromium, 374 en WebKit, medido en
 * el espejo en /tienda/ a 390 px— y seguía ahí al terminar (wishlist.js no avisa):
 * el siguiente toque, para quitar el favorito, abría el chat.
 */
test( 'RT-REV2-D2: el corazón de favoritos en vuelo (.is-loading) también cuenta', () => {
	// Corazón de la columna derecha a 390 px, bajo el de WhatsApp (332-376 × 646-690).
	const debajo = { left: 336, right: 380, top: 643, bottom: 687, width: 44, height: 44 };
	const arriba = { left: 336, right: 380, top: 200, bottom: 244, width: 44, height: 44 };
	const bajo = { lista: [ joinchat, texto ], enVuelo: [ { sel: '.akb-wl-toggle.is-loading', rect: debajo } ] };
	const e = entorno( bajo );
	e.reposo();
	assert.equal( e.cede(), true, 'con un scroll a mitad de la petición, WhatsApp volvía encima del corazón' );

	bajo.enVuelo = [ { sel: '.akb-wl-toggle.is-loading', rect: arriba } ];
	e.oyentes.scroll();
	e.reposo();
	assert.equal( e.cede(), false, 'en vuelo pero lejos: no cede' );
} );

test( 'RV2-T2-1: se vuelve a mirar sin scroll: fin del alta, carrito que cambia, cajón que se cierra y aviso de la tarjeta', () => {
	const bajo = { lista: [ joinchat, texto ] };
	const e = entorno( bajo );
	e.reposo();
	assert.equal( e.cede(), false );

	const disparadores = [
		[ e.oyentesBody, 'akb:added_to_cart' ],
		[ e.oyentesBody, 'akb:removed_from_cart' ],
		[ e.oyentesBody, 'akb:wc_fragments_refreshed' ],
		[ e.oyentesDoc, 'akb:cart:close' ],
		[ e.oyentesDoc, 'akb:tarjeta-motivo' ],
	];
	for ( const [ donde, tipo ] of disparadores ) {
		assert.equal( typeof donde[ tipo ], 'function', `escucha ${ tipo }` );
		bajo.lista = [ joinchat, elemento( HTML.anadir ) ];
		donde[ tipo ]();
		e.reposo();
		assert.equal( e.cede(), true, `${ tipo } vuelve a mirar` );
		bajo.lista = [ joinchat, texto ];
		donde[ tipo ]();
		e.reposo();
		assert.equal( e.cede(), false );
	}
} );

test( 'lo que no es compra (texto, portada) no lo retira', () => {
	const e = entorno( { lista: [ joinchat, texto ] } );
	e.reposo();
	assert.equal( e.cede(), false );
} );

test( 'el CSS lo retira sólo con la clase, y nunca con el chat abierto o con foco dentro', () => {
	assert.match( footer, /body\.akb-fab-cede \.joinchat:not\(\.joinchat--chatbox\):not\(:focus-within\)\s*\{[^}]*visibility:\s*hidden;[^}]*pointer-events:\s*none;/ );
} );
