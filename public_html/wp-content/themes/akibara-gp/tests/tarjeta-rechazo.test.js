'use strict';

/*
 * Alta desde una tarjeta (atc-guard.js): doble disparo, rechazo del servidor
 * (UM-4) y red caída (RT-REV1-D2).
 *
 * WooCommerce (add-to-cart.js) hace `window.location = product_url` ante
 * cualquier `response.error`: un tomo de una unidad que ya está en el carrito
 * expulsaba al cliente del listado a la ficha. atc-guard.js envuelve el
 * `success` y el `error` de la petición de cada intento con un
 * `$.ajaxPrefilter` y pinta el motivo en la tarjeta.
 *
 * El arnés reproduce WooCommerce 11.1 (add-to-cart.js) y no sólo la forma de
 * su llamada (revisión RT-REV3-D3): el clic quita `added` y pone `loading`,
 * pregunta al filtro `should_send_ajax_request.adding_to_cart` (si dice que no,
 * `ajax_request_not_sent` le quita el `loading`), dispara `adding_to_cart` con
 * el botón y el MISMO objeto `data` que lleva la petición, y la ENCOLA: la
 * siguiente no sale —no pasa por los prefiltros— hasta que la anterior termina
 * (addRequest/run). Cada clic trae un envoltorio jQuery nuevo del botón, como
 * `$( this )`. Hay varios botones por tomo: otra tarjeta del mismo producto y el
 * clon de la vista rápida, que va fuera de `li.product` (quick-view.js de
 * akibara-business). El arnés de antes lanzaba cada clic en el acto, con un solo
 * botón: probaba un orden que WooCommerce no produce y no vio RT-REV3-D1 ni D2.
 * Se comprueba el comportamiento, no el texto.
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const raiz = path.resolve( __dirname, '..' );
const fuente = fs.readFileSync( path.join( raiz, 'assets/js/atc-guard.js' ), 'utf8' );
const loop = fs.readFileSync( path.join( raiz, 'assets/css/loop.css' ), 'utf8' );

function nodo( tag ) {
	return {
		tagName: tag,
		className: '',
		hidden: false,
		hijos: [],
		atributos: {},
		style: {},
		// Caja por defecto; las pruebas de geometría la fijan.
		rect: { top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 },
		getBoundingClientRect() { return this.rect; },
		// Cada vez que se pidió desplazar la página o el diálogo para dejarlo a la vista.
		desplazamientos: [],
		scrollIntoView( op ) { this.desplazamientos.push( op ); },
		_texto: '',
		get textContent() { return this._texto + this.hijos.map( ( h ) => h.textContent ).join( '' ); },
		set textContent( v ) { this._texto = v; this.hijos = []; },
		setAttribute( k, v ) { this.atributos[ k ] = v; },
		appendChild( h ) { this.hijos.push( h ); h.parentNode = this; return h; },
		replaceChild( nuevo, viejo ) {
			this.hijos[ this.hijos.indexOf( viejo ) ] = nuevo;
			nuevo.parentNode = this;
			viejo.parentNode = null;
			return viejo;
		},
		querySelector( sel ) {
			const clase = sel.replace( /^\./, '' );
			return this.hijos.find( ( h ) => ( ' ' + h.className + ' ' ).includes( ' ' + clase + ' ' ) ) || null;
		},
	};
}

function entorno( { respuestaCarrito, fetchFalla = false } = {} ) {
	const manejadores = {};
	const prefiltros = [];
	const pedidos = [];
	const navegaciones = [];
	const eventos = [];
	const tarjetas = [];
	// Donde puede ir un aviso: las tarjetas y las acciones de la vista rápida.
	const contenedores = [];
	const botones = [];

	const body = nodo( 'body' );
	const document = {
		body,
		activeElement: null,
		createElement: ( t ) => {
			const n = nodo( t );
			// El «Ver carrito» ocupa el sitio del botón de agregar.
			if ( 'a' === t ) {
				n.rect = { top: 404, bottom: 448, left: 215, right: 376, width: 161, height: 44 };
			}
			return n;
		},
		createTextNode: ( t ) => ( { textContent: t } ),
		// Sólo lo que pide atc-guard.js: los avisos a la vista (de las tarjetas o de
		// todas partes) y los «Añadir» de WooCommerce que siguen en la página.
		querySelectorAll: ( sel ) => {
			const avisos = ( donde ) => donde.flatMap( ( c ) => c.hijos.filter( ( h ) => 'akb-tarjeta-motivo' === h.className && ! h.hidden ) );
			if ( 'li.product .akb-tarjeta-motivo:not([hidden])' === sel ) {
				return avisos( tarjetas );
			}
			if ( '.akb-tarjeta-motivo:not([hidden])' === sel ) {
				return avisos( contenedores );
			}
			assert.equal( sel, '.ajax_add_to_cart' );
			return botones.filter( ( b ) => b.parentNode && b.clases.has( 'ajax_add_to_cart' ) );
		},
		dispatchEvent: ( ev ) => { eventos.push( ev.type ); return true; },
	};

	/** Una tarjeta de la rejilla. Geometría medida en el espejo (/serie/dan-da-dan/, 390 px): borde de 2 px y el botón de 44 px al pie, 10 px sobre el borde interior. */
	function crearTarjeta() {
		const t = nodo( 'li' );
		t.className = 'product';
		t.rect = { top: 0, bottom: 460, left: 203, right: 388, width: 185, height: 460 };
		tarjetas.push( t );
		contenedores.push( t );
		return t;
	}

	/** Donde quick-view.js pone el clon del «Añadir», dentro del <dialog>. */
	function crearAccionesDeLaVistaRapida() {
		const a = nodo( 'div' );
		a.className = 'akb-qv__actions';
		contenedores.push( a );
		return a;
	}

	/**
	 * Un «Añadir» de WooCommerce (`<button type="button">`). Sin tarjeta es el
	 * clon de la vista rápida: va en el diálogo, fuera de `li.product`.
	 */
	function crearBoton( { id = 42, enTarjeta = true } = {} ) {
		const padre = enTarjeta ? crearTarjeta() : crearAccionesDeLaVistaRapida();
		const boton = {
			tagName: 'BUTTON',
			id,
			clases: new Set( [ 'button', 'product_type_simple', 'add_to_cart_button', 'ajax_add_to_cart' ] ),
			get className() { return [ ...this.clases ].join( ' ' ); },
			set className( v ) { this.clases = new Set( String( v ).split( /\s+/ ).filter( Boolean ) ); },
			get classList() { return { remove: ( c ) => this.clases.delete( c ) }; },
			getAttribute: ( k ) => ( 'data-product_id' === k ? String( id ) : null ),
			parentNode: null,
			closest( s ) {
				return 'li.product' === s && this.parentNode && 'product' === this.parentNode.className ? this.parentNode : null;
			},
			// Al pie de la tarjeta, donde lo pinta WooCommerce (la misma caja que el «Ver carrito»).
			getBoundingClientRect: () => ( { top: 404, bottom: 448, left: 215, right: 376, width: 161, height: 44 } ),
			focus() { document.activeElement = this; },
		};
		padre.appendChild( boton );
		botones.push( boton );
		return boton;
	}

	/** `$( this )` de WooCommerce: un envoltorio nuevo en cada clic. */
	function envolver( boton ) {
		return {
			length: 1,
			0: boton,
			data: ( k ) => ( 'product_id' === k ? boton.id : undefined ),
			attr: ( k ) => ( 'data-product_id' === k ? String( boton.id ) : undefined ),
			removeClass( c ) { boton.clases.delete( c ); return this; },
			addClass( c ) { boton.clases.add( c ); return this; },
		};
	}

	/** trigger / triggerHandler de jQuery: todos los oyentes, y el resultado del último que devolvió algo. */
	function disparar( evento, ...args ) {
		let resultado;
		( manejadores[ evento ] || [] ).slice().forEach( ( fn ) => {
			const r = fn( { type: evento }, ...args );
			if ( undefined !== r ) {
				resultado = r;
			}
		} );
		return resultado;
	}

	const $ = () => ( {
		on( nombres, fn ) {
			nombres.split( ' ' ).forEach( ( ev ) => { ( manejadores[ ev ] = manejadores[ ev ] || [] ).push( fn ); } );
			return this;
		},
	} );
	$.ajaxPrefilter = ( fn ) => prefiltros.push( fn );

	let temporizadores = [];
	let ultimoTemporizador = 0;
	const window = {
		CustomEvent: class { constructor( type ) { this.type = type; } },
		jQuery: $,
		wc_add_to_cart_params: { wc_ajax_url: '/?wc-ajax=%%endpoint%%' },
		getComputedStyle: ( n ) => ( { borderBottomWidth: 'product' === n.className ? '2px' : '0px' } ),
		setTimeout: ( fn, ms ) => { temporizadores.push( { fn, ms, id: ++ultimoTemporizador } ); return ultimoTemporizador; },
		clearTimeout: ( id ) => { temporizadores = temporizadores.filter( ( t ) => t.id !== id ); },
		fetch: ( url ) => {
			pedidos.push( url );
			if ( fetchFalla ) { return Promise.reject( new Error( 'red' ) ); }
			return Promise.resolve( { json: () => Promise.resolve( respuestaCarrito ) } );
		},
	};
	window.window = window;

	// atc-guard.js es `defer`: corre antes que el `jQuery( function )` de add-to-cart.js.
	vm.runInContext( fuente, vm.createContext( { window, document } ) );

	/* ── WooCommerce 11.1, add-to-cart.js ────────────────────────────────── */

	// updateButton: con `ajax_request_not_sent` y con `added_to_cart`. Termina
	// avisando con `wc_cart_button_updated`, con el botón.
	const actualizarBoton = ( ev, fragments, cartHash, $b ) => {
		if ( $b ) {
			$b.removeClass( 'loading' );
			if ( fragments ) {
				$b.addClass( 'added' );
			}
			disparar( 'wc_cart_button_updated', $b );
		}
	};
	( manejadores[ 'ajax_request_not_sent.adding_to_cart' ] = manejadores[ 'ajax_request_not_sent.adding_to_cart' ] || [] ).push( actualizarBoton );
	( manejadores.added_to_cart = manejadores.added_to_cart || [] ).push( actualizarBoton );

	const cola = [];
	let enVuelo = null;

	/** jQuery.ajax: los prefiltros reciben las opciones y las originales (el objeto de WooCommerce). */
	function ajax( peticion ) {
		const s = Object.assign( {}, peticion );
		prefiltros.forEach( ( p ) => p( s, peticion ) );
		peticion.salio = true;
		enVuelo = { s, peticion };
	}

	function run() {
		const original = cola[ 0 ].complete;
		cola[ 0 ].complete = function () {
			if ( 'function' === typeof original ) {
				original();
			}
			cola.shift();
			if ( cola.length > 0 ) {
				run();
			}
		};
		ajax( cola[ 0 ] );
	}

	function addRequest( peticion ) {
		cola.push( peticion );
		if ( 1 === cola.length ) {
			run();
		}
	}

	const botonPorDefecto = crearBoton();

	/**
	 * Un clic en `boton` (onAddToCart). Devuelve el intento: `responder` y
	 * `fallar` sólo valen con SU petición en vuelo, porque la cola no lanza la
	 * siguiente hasta que termina la anterior.
	 */
	function pulsar( boton = botonPorDefecto, { error } = {} ) {
		const $b = envolver( boton );
		$b.removeClass( 'added' );
		$b.addClass( 'loading' );
		if ( false === disparar( 'should_send_ajax_request.adding_to_cart', $b ) ) {
			disparar( 'ajax_request_not_sent.adding_to_cart', false, false, $b );
			return { enviado: false };
		}
		const data = { product_id: boton.id, quantity: 1 };
		disparar( 'adding_to_cart', $b, data );
		const exitos = [];
		const peticion = {
			type: 'POST',
			url: '/?wc-ajax=add_to_cart',
			data,
			success( response ) {
				exitos.push( response );
				if ( ! response ) {
					return;
				}
				if ( response.error && response.product_url ) {
					navegaciones.push( response.product_url );
					return;
				}
				disparar( 'added_to_cart', response.fragments, response.cart_hash, $b );
			},
			dataType: 'json',
		};
		// WooCommerce 11.1 no pone `error`; sólo si la prueba lo pasa (un tercero).
		if ( error ) {
			peticion.error = error;
		}
		addRequest( peticion );
		const enSuTurno = ( que ) => {
			assert.ok( enVuelo && enVuelo.peticion === peticion, `${ que }: esta petición no está en vuelo (la cola de WooCommerce la lanza cuando termina la anterior)` );
			const { s } = enVuelo;
			enVuelo = null;
			return s;
		};
		return {
			enviado: true,
			exitos,
			salio: () => !! peticion.salio,
			responder( r ) {
				const s = enSuTurno( 'responder' );
				s.success( r );
				s.complete();
			},
			// Lo que hace jQuery sin respuesta: `error( jqXHR, textStatus, errorThrown )`.
			fallar( estado = 'error' ) {
				const s = enSuTurno( 'fallar' );
				if ( 'function' === typeof s.error ) {
					s.error( {}, estado, '' );
				}
				s.complete();
			},
		};
	}

	return {
		pulsar,
		crearBoton,
		tarjeta: botonPorDefecto.parentNode,
		boton: botonPorDefecto,
		body,
		pedidos,
		navegaciones,
		document,
		eventos,
		/** Otro oyente del filtro de WooCommerce (un tercero), registrado después de atc-guard.js. */
		filtroDeTercero: ( fn ) => manejadores[ 'should_send_ajax_request.adding_to_cart' ].push( fn ),
		/** El carrito cambia (native-cart.js / mini-carrito de WooCommerce). */
		carritoCambia: ( ev = 'wc_fragment_refresh' ) => disparar( ev ),
		/** Vence el temporizador de `ms` (la red de seguridad de 2,5 s o el aviso de 6 s). */
		vencer( ms ) {
			const vencidos = temporizadores.filter( ( t ) => t.ms === ms );
			assert.ok( vencidos.length, `no hay temporizador de ${ ms } ms pendiente` );
			temporizadores = temporizadores.filter( ( t ) => t.ms !== ms );
			vencidos.forEach( ( t ) => t.fn() );
		},
		pendientes: ( ms ) => temporizadores.filter( ( t ) => t.ms === ms ).length,
		motivoDe: ( boton ) => ( boton.parentNode ? boton.parentNode.querySelector( '.akb-tarjeta-motivo' ) : null ),
	};
}

const esperarPromesas = () => new Promise( ( r ) => setImmediate( r ) );
const girando = ( boton ) => boton.clases.has( 'loading' );

test( 'con el tomo ya en el carrito, el cliente se queda en el listado y ve el motivo en la tarjeta', async () => {
	const e = entorno( { respuestaCarrito: { data: { errors: [ 'Ya tienes la única unidad en tu carrito.' ], cart_url: '/carrito/' } } } );

	const clic = e.pulsar();
	assert.equal( clic.enviado, true );
	assert.equal( girando( e.boton ), true, 'WooCommerce pone `loading` al lanzar' );
	clic.responder( { error: true, product_url: '/blue-lock-7/' } );
	await esperarPromesas();

	assert.deepEqual( e.navegaciones, [], 'el success de WooCommerce (que navega a la ficha) no se llama' );
	assert.equal( clic.exitos.length, 0 );
	assert.deepEqual( e.pedidos, [ '/?wc-ajax=akb_get_cart&drain_notices=1&product_id=42&quantity=1' ], 'se pide el motivo al mismo endpoint que usa la ficha, diciendo qué se intentó agregar' );

	const motivo = e.tarjeta.querySelector( '.akb-tarjeta-motivo' );
	assert.ok( motivo, 'el motivo se pinta en la tarjeta' );
	assert.equal( motivo.hidden, false );
	assert.match( motivo.textContent, /Ya tienes la única unidad en tu carrito\.Ver carrito/ );
	assert.equal( motivo.hijos[ motivo.hijos.length - 1 ].href, '/carrito/' );
	assert.equal( girando( e.boton ), false, 'el botón deja de girar' );

	// Se anuncia en una región de estado que ya existía antes del rechazo.
	const region = e.body.hijos.find( ( h ) => 'status' === h.atributos.role );
	assert.ok( region );
	assert.equal( region.textContent, 'Ya tienes la única unidad en tu carrito.' );

	// Y se va solo, como en la ficha.
	e.vencer( 6000 );
	assert.equal( motivo.hidden, true );
} );

test( 'un alta correcta sigue el camino de WooCommerce intacto', async () => {
	const e = entorno( { respuestaCarrito: {} } );
	const clic = e.pulsar();
	const ok = { fragments: { '.x': '<b/>' }, cart_hash: 'h' };
	clic.responder( ok );
	await esperarPromesas();
	assert.deepEqual( clic.exitos, [ ok ] );
	assert.equal( e.pedidos.length, 0 );
	assert.equal( girando( e.boton ), false );
	assert.equal( e.boton.clases.has( 'added' ), true, 'la ✓ de WooCommerce' );
	assert.equal( e.pendientes( 2500 ), 0, 'el alta suelta el candado del tomo' );
} );

test( 'sin motivo que mostrar, vuelve al camino de WooCommerce (la ficha enseña el aviso)', async () => {
	const e = entorno( { respuestaCarrito: { data: { errors: [] } } } );
	const clic = e.pulsar();
	const r = { error: true, product_url: '/blue-lock-7/' };
	clic.responder( r );
	await esperarPromesas();
	assert.deepEqual( e.navegaciones, [ '/blue-lock-7/' ] );

	const e2 = entorno( { fetchFalla: true } );
	e2.pulsar().responder( r );
	await esperarPromesas();
	assert.deepEqual( e2.navegaciones, [ '/blue-lock-7/' ], 'con la red caída, igual' );
} );

test( 'un error SIN product_url no se convierte en un falso éxito', async () => {
	// WooCommerce con `product_url` vacío caía a `added_to_cart` sin fragments:
	// abría el cajón como si hubiera agregado (verificador de UM-4).
	const e = entorno( { respuestaCarrito: { data: { errors: [] } } } );
	const clic = e.pulsar();
	clic.responder( { error: true, product_url: '' } );
	await esperarPromesas();
	assert.equal( clic.exitos.length, 0 );
	assert.equal( girando( e.boton ), false );
} );

test( 'el motivo de la tarjeta no mueve la rejilla y no se arma con innerHTML', () => {
	// Sin comentarios: el propio aviso «nunca innerHTML» del fichero no es código.
	const codigo = fuente.replace( /\/\*[\s\S]*?\*\//g, '' ).replace( /^\s*\/\/.*$/gm, '' );
	assert.doesNotMatch( codigo, /innerHTML/ );
	assert.match( loop, /li\.product > \.akb-tarjeta-motivo\s*\{[^}]*position:\s*absolute;[^}]*bottom:\s*0;[^}]*z-index:\s*3;/ );
	assert.match( loop, /li\.product > \.akb-tarjeta-motivo\[hidden\][^{]*\{\s*display:\s*none;/ );
} );

/*
 * UM-3 (lote de integración): el servidor devuelve además el CÓDIGO del
 * rechazo. Con `ya_en_carrito` el botón de la tarjeta pasa a ser un enlace
 * «Ver carrito» —otro toque sólo repetiría el rechazo— y deja de ser un
 * «Añadir» de WooCommerce. Se decide por el código, no por el texto.
 */
test( 'con el código ya_en_carrito, el botón de la tarjeta pasa a «Ver carrito» y deja de agregar', async () => {
	const e = entorno( { respuestaCarrito: { data: { errors: [ 'Ya tienes la única unidad en tu carrito.' ], cart_url: '/carrito/', motivo: 'ya_en_carrito' } } } );

	const clic = e.pulsar();
	clic.responder( { error: true, product_url: '/blue-lock-7/' } );
	await esperarPromesas();

	const enlace = e.tarjeta.hijos.find( ( h ) => 'a' === h.tagName );
	assert.ok( enlace, 'el <button> se reemplaza por un <a>: un botón con href no navega' );
	assert.equal( e.tarjeta.hijos.includes( e.boton ), false );
	assert.equal( enlace.textContent, 'Ver carrito' );
	assert.equal( enlace.href, '/carrito/' );
	assert.equal( enlace.className, 'button product_type_simple akb-ver-carrito', 'conserva el aspecto y pierde las clases que WooCommerce escucha' );

	const motivo = e.tarjeta.querySelector( '.akb-tarjeta-motivo' );
	assert.equal( motivo.textContent, 'Ya tienes la única unidad en tu carrito.', 'el motivo sin su propio enlace: el botón ya lo es' );
} );

/*
 * QA-MOV-2 (QA integral, 2026-09-24): con «Ver carrito» el motivo tapaba el
 * botón entero durante 6 s —caja opaca de 185x112 sobre el botón de 161x44,
 * medido en el espejo en /serie/dan-da-dan/— y el toque caía en el párrafo:
 * no llevaba al carrito. Ahora termina justo ENCIMA del botón.
 */
test( 'QA-MOV-2: con «Ver carrito», el motivo termina encima del botón y lo deja tocable', async () => {
	const e = entorno( { respuestaCarrito: { data: { errors: [ 'Ya tienes la única unidad en tu carrito.' ], cart_url: '/carrito/', motivo: 'ya_en_carrito' } } } );
	e.pulsar().responder( { error: true, product_url: '/dan-da-dan-02/' } );
	await esperarPromesas();

	const motivo = e.tarjeta.querySelector( '.akb-tarjeta-motivo' );
	// Pie interior de la tarjeta en 458 (460 - 2 de borde); el botón empieza en
	// 404; 4 px de aire: el motivo termina 58 px por encima del pie.
	assert.equal( motivo.style.bottom, '58px' );

	// Un rechazo posterior SIN «Ver carrito» vuelve a tapar el botón (bottom de
	// la hoja): ahí el motivo trae su propio enlace.
	e.carritoCambia();
	const e2 = entorno( { respuestaCarrito: { data: { errors: [ 'Sin stock.' ], cart_url: '/carrito/', motivo: 'sin_stock' } } } );
	e2.pulsar().responder( { error: true, product_url: '/x/' } );
	await esperarPromesas();
	assert.equal( e2.tarjeta.querySelector( '.akb-tarjeta-motivo' ).style.bottom, '' );
} );

test( 'sin código (o con otro), el botón sigue siendo el de agregar', async () => {
	const e = entorno( { respuestaCarrito: { data: { errors: [ 'Lo sentimos, no hay stock.' ], cart_url: '/carrito/', motivo: 'sin_stock' } } } );

	const clic = e.pulsar();
	clic.responder( { error: true, product_url: '/x/' } );
	await esperarPromesas();

	assert.ok( e.tarjeta.hijos.includes( e.boton ), 'el botón de agregar sigue ahí' );
	assert.equal( e.tarjeta.hijos.find( ( h ) => 'a' === h.tagName ), undefined );
	assert.match( e.tarjeta.querySelector( '.akb-tarjeta-motivo' ).textContent, /Ver carrito$/ );
} );

/*
 * R-I1-4 (revisión del lote I1): el cambio a «Ver carrito» era irreversible en
 * la página. Si el cliente quitaba el tomo desde el cajón, la tarjeta seguía
 * diciendo «Ver carrito» y llevaba a un carrito que ya no lo tenía. La ficha sí
 * se restauraba: ahora las dos superficies escuchan los mismos avisos.
 */
test( 'R-I1-4: cuando el carrito cambia, el «Ver carrito» de la tarjeta vuelve a ser el botón de agregar', async () => {
	const e = entorno( { respuestaCarrito: { data: { errors: [ 'Ya tienes la única unidad en tu carrito.' ], cart_url: '/carrito/', motivo: 'ya_en_carrito' } } } );

	const clic = e.pulsar();
	// WooCommerce dejó `added` en el botón en el alta anterior; quedó al lanzar ésta.
	e.boton.className += ' added';
	clic.responder( { error: true, product_url: '/blue-lock-7/' } );
	await esperarPromesas();
	const enlace = e.tarjeta.hijos.find( ( h ) => 'a' === h.tagName );
	assert.ok( enlace, 'primero pasa a «Ver carrito»' );
	e.document.activeElement = enlace; // el cliente llegó con el teclado

	e.carritoCambia( 'wc_fragment_refresh' );

	assert.ok( e.tarjeta.hijos.includes( e.boton ), 'vuelve el MISMO botón: el clic delegado de WooCommerce funciona otra vez' );
	assert.equal( e.tarjeta.hijos.includes( enlace ), false, 'y el enlace se va' );
	const clases = e.boton.className.split( /\s+/ );
	assert.ok( clases.includes( 'ajax_add_to_cart' ) && clases.includes( 'add_to_cart_button' ), 'con las clases que WooCommerce escucha' );
	assert.equal( clases.includes( 'added' ), false, 'sin la ✓ de «agregado» de un tomo que se acaba de quitar' );
	assert.equal( clases.includes( 'loading' ), false );
	assert.equal( e.document.activeElement, e.boton, 'el foco no se pierde en el body' );

	// Un segundo aviso no hace nada, y un nuevo rechazo vuelve a «Ver carrito».
	e.carritoCambia( 'removed_from_cart' );
	assert.ok( e.tarjeta.hijos.includes( e.boton ) );
	e.pulsar().responder( { error: true, product_url: '/blue-lock-7/' } );
	await esperarPromesas();
	assert.ok( e.tarjeta.hijos.find( ( h ) => 'a' === h.tagName ), 'el tomo seguía entero en el carrito: otra vez «Ver carrito»' );
} );

/*
 * Revisión RV-I1-2-1: cuando el carrito cambia, los avisos de las tarjetas se
 * retiran todos, pasara o no su botón a «Ver carrito». Con el carrito ya vacío
 * el aviso seguía diciendo «has añadido 1 en tu carrito» hasta 6 s, y en WebKit
 * quedaba ENCIMA del botón devuelto: el toque caía en el aviso y no agregaba.
 */
test( 'RV-I1-2-1: cuando el carrito cambia se retira el aviso de la tarjeta, también sin «Ver carrito»', async () => {
	const e = entorno( { respuestaCarrito: { data: { errors: [ 'No puedes añadir esa cantidad — has añadido 1 en tu carrito.' ], cart_url: '/carrito/', motivo: '' } } } );
	e.pulsar().responder( { error: true, product_url: '/blue-lock-7/' } );
	await esperarPromesas();
	const caja = e.tarjeta.querySelector( '.akb-tarjeta-motivo' );
	assert.equal( caja.hidden, false, 'el motivo se pinta' );
	assert.ok( e.tarjeta.hijos.includes( e.boton ), 'sin código, el botón sigue siendo el de agregar' );

	e.carritoCambia();
	assert.equal( caja.hidden, true, 'hablaba del carrito de antes: se retira ya, no a los 6 s' );
	assert.ok( e.tarjeta.hijos.includes( e.boton ) );
} );

test( 'RV-I1-2-1: con «Ver carrito», el botón vuelve Y el aviso se va (no queda encima del botón)', async () => {
	const e = entorno( { respuestaCarrito: { data: { errors: [ 'Ya tienes la única unidad en tu carrito.' ], cart_url: '/carrito/', motivo: 'ya_en_carrito' } } } );
	e.pulsar().responder( { error: true, product_url: '/blue-lock-7/' } );
	await esperarPromesas();
	const caja = e.tarjeta.querySelector( '.akb-tarjeta-motivo' );
	assert.equal( caja.hidden, false );

	e.carritoCambia( 'removed_from_cart' );
	assert.ok( e.tarjeta.hijos.includes( e.boton ), 'vuelve el botón de agregar' );
	assert.equal( caja.hidden, true, 'y el aviso ya no lo tapa' );
} );

/*
 * Revisión RV2-T2-1: el aviso cambia lo que hay bajo el botón de WhatsApp sin
 * que nadie haga scroll. main.js (UM-16) vuelve a mirar al oír este evento.
 */
test( 'RV2-T2-1: el aviso avisa al aparecer, al irse solo y al retirarse con el carrito', async () => {
	const e = entorno( { respuestaCarrito: { data: { errors: [ 'Sin stock.' ], cart_url: '/carrito/', motivo: '' } } } );
	e.pulsar().responder( { error: true, product_url: '/blue-lock-7/' } );
	await esperarPromesas();
	assert.deepEqual( e.eventos, [ 'akb:tarjeta-motivo' ], 'al aparecer' );

	e.vencer( 6000 );
	assert.deepEqual( e.eventos, [ 'akb:tarjeta-motivo', 'akb:tarjeta-motivo' ], 'y al irse' );

	// Sin avisos a la vista, un cambio del carrito (o un intento nuevo) no avisa de nada.
	e.carritoCambia();
	assert.equal( e.eventos.length, 2 );

	e.pulsar().responder( { error: true, product_url: '/blue-lock-7/' } );
	await esperarPromesas();
	e.carritoCambia();
	assert.equal( e.eventos.length, 4, 'aparecer y retirarse con el carrito' );
} );

/*
 * RT-REV1-D2 (revisión del lote R-T; defecto de antes, base 2.1.142): WooCommerce no
 * maneja el error del alta y sólo quita `loading` en `added_to_cart`. Con la red caída
 * el botón de la tarjeta se quedaba en `.loading` (sin puntero en loop.css) para
 * siempre: atenuado, sin aviso y sin reintento posible sin recargar. Medido en el
 * espejo en /tienda/, Chromium y WebKit. La ficha, en cambio, pasa a «Reintentar».
 */
test( 'RT-REV1-D2: sin respuesta, el botón deja de girar, se puede reintentar y el aviso queda encima', async () => {
	const e = entorno( { respuestaCarrito: {} } );
	const clic = e.pulsar();
	clic.fallar( 'error' );
	await esperarPromesas();

	assert.equal( girando( e.boton ), false, 'el botón quedaba en .loading para siempre' );
	assert.equal( e.pedidos.length, 0, 'no se pide el motivo al servidor: no respondió' );
	assert.equal( clic.exitos.length, 0, 'nunca un falso éxito' );

	const motivo = e.tarjeta.querySelector( '.akb-tarjeta-motivo' );
	assert.ok( motivo, 'se explica en la tarjeta' );
	assert.equal( motivo.hidden, false );
	assert.equal( motivo.textContent, 'No se pudo conectar, reintenta', 'el mismo aviso que la ficha, sin enlace' );
	assert.equal( motivo.style.bottom, '58px', 'termina encima del botón (pie interior en 458, botón en 404, 4 de aire): no lo tapa' );
	assert.ok( e.tarjeta.hijos.includes( e.boton ), 'el botón sigue siendo el de agregar' );
	assert.deepEqual( e.eventos, [ 'akb:tarjeta-motivo' ], 'main.js vuelve a mirar el botón de WhatsApp' );
	const region = e.body.hijos.find( ( h ) => 'status' === h.atributos.role );
	assert.equal( region.textContent, 'No se pudo conectar, reintenta', 'y se anuncia' );

	// El candado del producto se suelta ya, no a los 2,5 s: el reintento sale.
	const otro = e.pulsar();
	assert.equal( otro.enviado, true, 'el segundo toque lanza la petición' );
	assert.equal( otro.salio(), true );
} );

test( 'RT-REV1-D2: una petición cancelada devuelve el botón sin aviso, y el error de un tercero se sigue llamando', async () => {
	const llamadas = [];
	const e = entorno( { respuestaCarrito: {} } );
	const clic = e.pulsar( e.boton, { error( jqXHR, estado ) { llamadas.push( estado ); } } );
	clic.fallar( 'abort' );
	await esperarPromesas();
	assert.equal( girando( e.boton ), false );
	assert.equal( e.tarjeta.querySelector( '.akb-tarjeta-motivo' ), null, 'nada que explicar' );
	assert.deepEqual( llamadas, [ 'abort' ], 'el error que ya traía la petición se sigue llamando' );
	assert.equal( e.pulsar().enviado, true );
} );

/*
 * El doble disparo: con el alta del tomo en vuelo, un segundo clic no sale.
 * WooCommerce le quita el `loading` al botón (`ajax_request_not_sent`), y un clic en
 * OTRO tomo no se descarta: espera en la cola de WooCommerce.
 */
test( 'doble disparo: el segundo clic del mismo tomo no sale; el de otro tomo espera su turno en la cola', async () => {
	const e = entorno( { respuestaCarrito: {} } );
	const otroTomo = e.crearBoton( { id: 7 } );
	const primero = e.pulsar();
	assert.equal( primero.salio(), true );
	assert.equal( e.pulsar().enviado, false, 'mismo tomo en vuelo: no sale' );
	const b = e.pulsar( otroTomo );
	assert.equal( b.enviado, true, 'otro tomo no se descarta' );
	assert.equal( b.salio(), false, 'pero no sale hasta que termina la anterior (addRequest/run)' );
	assert.throws( () => b.responder( {} ), /no está en vuelo/, 'no puede responder antes de salir' );

	primero.responder( { fragments: {}, cart_hash: 'h' } );
	assert.equal( b.salio(), true, 'terminada la primera, sale la segunda' );
	b.responder( { fragments: {}, cart_hash: 'h' } );
	assert.equal( otroTomo.clases.has( 'added' ), true );
} );

/*
 * RT-REV2-D1 (revisión 2 del lote R-T; nació con el arreglo de RT-REV1-D2): tras la
 * red caída el aviso «No se pudo conectar, reintenta» deja el botón libre para
 * reintentar, pero si el reintento SALE nada lo retiraba. Quedaba 6 s encima de un
 * botón ya marcado `added` (✓), también tras cerrar el cajón, pidiendo reintentar:
 * y otro toque agregaba una SEGUNDA unidad (medido en el espejo en /tienda/,
 * Chromium y WebKit, móvil y escritorio).
 */
test( 'RT-REV2-D1: tras la red caída, el reintento retira el aviso de la tarjeta y avisa a main.js', async () => {
	const e = entorno( { respuestaCarrito: {} } );
	e.pulsar().fallar( 'error' );
	await esperarPromesas();
	const motivo = e.tarjeta.querySelector( '.akb-tarjeta-motivo' );
	assert.equal( motivo.hidden, false, 'primero el aviso de la red caída' );
	assert.deepEqual( e.eventos, [ 'akb:tarjeta-motivo' ] );

	const reintento = e.pulsar();
	assert.equal( reintento.enviado, true, 'el reintento sale' );
	assert.equal( motivo.hidden, true, 'el aviso se retira al reintentar: ya no dice la verdad' );
	assert.deepEqual( e.eventos, [ 'akb:tarjeta-motivo', 'akb:tarjeta-motivo' ], 'main.js vuelve a mirar el botón de WhatsApp' );

	const ok = { fragments: { '.x': '<b/>' }, cart_hash: 'h' };
	reintento.responder( ok );
	await esperarPromesas();
	assert.deepEqual( reintento.exitos, [ ok ], 'el éxito sigue el camino de WooCommerce' );
	assert.equal( e.boton.clases.has( 'added' ), true );
	assert.equal( motivo.hidden, true, 'y no vuelve sobre el botón ya agregado' );
	assert.equal( e.eventos.length, 2, 'sin avisos de más' );
} );

/*
 * Otro botón del mismo tomo (una tarjeta gemela) pulsado pasada la red de
 * seguridad de 2,5 s deja su intento en la cola detrás del primero. El primero,
 * si falla, no habla: pintaba «reintenta» y soltaba el candado del segundo, que
 * puede salir bien. Con el MISMO botón esto era el teclado sobre la tarjeta que
 * gira; ya no pasa: un botón con su alta pendiente no manda otra (REV-FT4-D1).
 */
test( 'RT-REV2-D1: el fallo de un intento ya reemplazado no habla ni le suelta el candado al que espera', async () => {
	const e = entorno( { respuestaCarrito: {} } );
	const gemelo = e.crearBoton();
	const primero = e.pulsar();
	assert.equal( e.eventos.length, 0, 'sin aviso a la vista, un intento no avisa de nada' );
	e.vencer( 2500 );
	const segundo = e.pulsar( gemelo );
	assert.equal( segundo.enviado, true );
	assert.equal( segundo.salio(), false, 'espera en la cola' );

	primero.fallar( 'timeout' );
	await esperarPromesas();
	assert.equal( e.motivoDe( e.boton ), null, 'el primero ya no habla por la tarjeta' );
	assert.equal( girando( gemelo ), true, 'el segundo, que sale ahora, sigue girando' );
	assert.equal( segundo.salio(), true );
	assert.equal( e.pulsar().enviado, false, 'y conserva su candado' );

	// Y el segundo sí habla: su fallo se ve y devuelve el botón.
	segundo.fallar( 'error' );
	assert.equal( girando( gemelo ), false );
	assert.equal( e.motivoDe( gemelo ).hidden, false );

	// Un rechazo del reemplazado tampoco: ni motivo, ni ficha (el success de WooCommerce navega).
	const e2 = entorno( { respuestaCarrito: { data: { errors: [ 'Sin stock.' ], cart_url: '/carrito/' } } } );
	const gemelo2 = e2.crearBoton();
	const uno = e2.pulsar();
	e2.vencer( 2500 );
	e2.pulsar( gemelo2 );
	uno.responder( { error: true, product_url: '/x/' } );
	await esperarPromesas();
	assert.equal( e2.pedidos.length, 0, 'no se pide el motivo de un intento reemplazado' );
	assert.deepEqual( e2.navegaciones, [], 'ni se navega a la ficha' );
	assert.equal( e2.motivoDe( e2.boton ), null );
	assert.equal( girando( gemelo2 ), true, 'el que sale ahora sigue girando' );
} );

/*
 * RT-REV3-D3: el «orden inverso» que describía el punto 6 (el segundo intento
 * falla y DESPUÉS agrega el primero, que seguía en vuelo) no existe: la cola de
 * WooCommerce no lanza el segundo hasta que el primero termina.
 */
test( 'RT-REV3-D3: con la cola de WooCommerce el segundo intento no puede terminar antes que el primero', () => {
	const e = entorno( { respuestaCarrito: {} } );
	const gemelo = e.crearBoton();
	const primero = e.pulsar();
	e.vencer( 2500 );
	const segundo = e.pulsar( gemelo );
	assert.throws( () => segundo.fallar( 'error' ), /no está en vuelo/ );
	primero.responder( { fragments: {}, cart_hash: 'h' } );
	assert.equal( segundo.salio(), true );
} );

test( 'RT-REV2-D1: el motivo de un rechazo que llega con otro intento ya en vuelo no se pinta ni convierte el botón', async () => {
	// Rechazo → se pide el motivo → el cliente vuelve a tocar antes de que llegue.
	const e = entorno( { respuestaCarrito: { data: { errors: [ 'Ya tienes la única unidad en tu carrito.' ], cart_url: '/carrito/', motivo: 'ya_en_carrito' } } } );
	const primero = e.pulsar();
	primero.responder( { error: true, product_url: '/blue-lock-7/' } );
	assert.equal( e.pedidos.length, 1, 'se pidió el motivo' );
	const segundo = e.pulsar();
	assert.equal( segundo.salio(), true, 'la cola está libre: sale en el acto' );
	await esperarPromesas();
	assert.equal( e.tarjeta.querySelector( '.akb-tarjeta-motivo' ), null, 'el motivo del primero ya no es del botón que vuela' );
	assert.ok( e.tarjeta.hijos.includes( e.boton ), 'el botón en vuelo no pasa a «Ver carrito» a mitad de su petición' );
	assert.equal( girando( e.boton ), true );
	assert.deepEqual( e.navegaciones, [], 'ni se navega a la ficha' );

	// El segundo habla por la tarjeta.
	segundo.responder( { error: true, product_url: '/blue-lock-7/' } );
	await esperarPromesas();
	assert.ok( e.tarjeta.hijos.find( ( h ) => 'a' === h.tagName ), 'el rechazo del último sí pasa a «Ver carrito»' );
	assert.equal( e.tarjeta.querySelector( '.akb-tarjeta-motivo' ).hidden, false );
} );

test( 'RT-REV2-D1: sin motivo (red caída al pedirlo) y con otro intento ya en vuelo, tampoco se navega a la ficha', async () => {
	const e = entorno( { fetchFalla: true } );
	const primero = e.pulsar();
	primero.responder( { error: true, product_url: '/blue-lock-7/' } );
	e.pulsar();
	await esperarPromesas();
	assert.deepEqual( e.navegaciones, [], 'el cliente, que ya volvió a tocar, se queda en el listado' );
} );

/*
 * RT-REV3-D1 (revisión 3 del lote R-T; regresión del arreglo de RT-REV2-D1): el
 * «reemplazado» se decidía por TOMO y no por botón. Si el intento más nuevo del mismo
 * tomo salía de OTRO botón —el clon de la vista rápida, que el ratón alcanza aunque
 * la tarjeta esté en `.loading`, u otra tarjeta del mismo tomo—, el fallo o el
 * rechazo del anterior no le quitaba el `loading` a SU botón, y nadie más lo hacía:
 * la tarjeta quedaba atenuada y sin puntero hasta recargar, sin aviso (medido en el
 * espejo en /tienda/ a 1440, Chromium y WebKit). Ahora cada botón lo devuelve su
 * último intento.
 */
for ( const [ desenlace, terminar ] of [
	[ 'sin respuesta', ( i ) => i.fallar( 'error' ) ],
	[ 'rechazado', ( i ) => i.responder( { error: true, product_url: '/rurouni-kenshin-10/' } ) ],
] ) {
	test( `RT-REV3-D1: reemplazado por la vista rápida (${ desenlace }), el botón de la tarjeta vuelve; habla el de la vista rápida`, async () => {
		const e = entorno( { respuestaCarrito: { data: { errors: [ 'Sin stock.' ], cart_url: '/carrito/' } } } );
		const vistaRapida = e.crearBoton( { enTarjeta: false } );
		const tarjeta = e.pulsar();
		e.vencer( 2500 );
		const clon = e.pulsar( vistaRapida );
		assert.equal( clon.enviado, true );
		assert.equal( clon.salio(), false, 'la cola de WooCommerce lo lanza cuando termine el de la tarjeta' );

		terminar( tarjeta );
		await esperarPromesas();
		assert.equal( girando( e.boton ), false, 'la tarjeta quedaba en .loading, sin puntero, hasta recargar' );
		assert.equal( e.tarjeta.querySelector( '.akb-tarjeta-motivo' ), null, 'sin aviso: habla el intento que sale ahora' );
		assert.equal( e.pedidos.length, 0 );
		assert.deepEqual( e.navegaciones, [], 'ni a la ficha' );
		assert.equal( girando( vistaRapida ), true, 'el clon sigue girando: es su petición la que sale' );
		assert.equal( clon.salio(), true );
		assert.equal( e.pulsar().enviado, false, 'el candado del tomo es del que vuela' );

		clon.responder( { fragments: { '.x': '<b/>' }, cart_hash: 'h' } );
		assert.equal( vistaRapida.clases.has( 'added' ), true );
		assert.equal( girando( e.boton ), false );
	} );
}

test( 'RT-REV3-D1: con otra tarjeta del mismo tomo, cada tarjeta recupera su botón y el aviso va a la del último intento', async () => {
	const e = entorno( { respuestaCarrito: { data: { errors: [ 'Sin stock.' ], cart_url: '/carrito/' } } } );
	const gemelo = e.crearBoton();
	const a = e.pulsar();
	e.vencer( 2500 );
	const g = e.pulsar( gemelo );
	a.fallar( 'error' );
	assert.equal( girando( e.boton ), false );
	assert.equal( e.motivoDe( e.boton ), null );
	assert.equal( girando( gemelo ), true );

	g.fallar( 'error' );
	assert.equal( girando( gemelo ), false );
	assert.equal( e.motivoDe( gemelo ).textContent, 'No se pudo conectar, reintenta', 'el último intento habla en SU tarjeta' );
	assert.equal( e.motivoDe( e.boton ), null );
	assert.equal( e.pulsar().enviado, true, 'y soltó el candado' );
} );

test( 'RT-REV3-D1: pulsados A → gemelo → A, la segunda de A no sale (su alta vuela) y cada botón vuelve con la suya', async () => {
	const e = entorno( { respuestaCarrito: {} } );
	const gemelo = e.crearBoton();
	const a = e.pulsar();
	e.vencer( 2500 );
	const g = e.pulsar( gemelo );
	e.vencer( 2500 );
	assert.equal( e.pulsar().enviado, false, 'con el teclado: A tiene su alta en vuelo (REV-FT4-D1)' );
	assert.equal( girando( e.boton ), true, 'y sigue girando' );
	a.fallar( 'error' );
	assert.equal( girando( e.boton ), false, 'terminada su alta, A vuelve' );
	assert.equal( e.motivoDe( e.boton ), null, 'sin aviso: habla el último, el del gemelo' );
	assert.equal( girando( gemelo ), true );
	g.fallar( 'error' );
	assert.equal( girando( gemelo ), false );
	assert.equal( e.motivoDe( gemelo ).hidden, false );
} );

/*
 * Antes el intento nacía en el filtro `should_send_ajax_request.adding_to_cart`.
 * Si otro oyente de ese filtro vetaba el alta DESPUÉS, contaba un intento que no
 * salió: el que volaba quedaba «reemplazado» por un fantasma, no hablaba y su botón
 * se quedaba en `.loading`. Ahora nace en `adding_to_cart`, cuando WooCommerce ya
 * decidió enviar.
 */
test( 'un alta vetada por otro oyente del filtro no cuenta como intento: el que vuela sigue hablando', async () => {
	const e = entorno( { respuestaCarrito: {} } );
	const gemelo = e.crearBoton();
	const primero = e.pulsar();
	e.vencer( 2500 );
	e.filtroDeTercero( () => false );
	assert.equal( e.pulsar( gemelo ).enviado, false, 'el tercero la veta' );
	primero.fallar( 'error' );
	assert.equal( girando( e.boton ), false );
	assert.equal( e.tarjeta.querySelector( '.akb-tarjeta-motivo' ).hidden, false, 'el fallo del que volaba se explica' );
} );

/*
 * El prefiltro reconocía la petición por el id del tomo («el último botón que
 * pulsó ese tomo»). Con la cola, cuando la petición salía ese botón podía ser otro:
 * A (tomo P) vuela, B (tomo Q) y el gemelo de A esperan, y otro botón del tomo P
 * se pulsa después. La petición del gemelo se atribuía al último y la del último
 * salía sin envolver: su rechazo mandaba a la ficha. Ahora cada petición lleva su
 * intento. (A mismo, con su alta en vuelo, ya no manda otra: REV-FT4-D1.)
 */
test( 'cada petición es de su botón aunque la cola la lance segundos después', async () => {
	const e = entorno( { respuestaCarrito: { data: { errors: [ 'Sin stock.' ], cart_url: '/carrito/' } } } );
	const otroTomo = e.crearBoton( { id: 7 } );
	const gemelo = e.crearBoton();
	const tercero = e.crearBoton();
	const a = e.pulsar();
	const b = e.pulsar( otroTomo );
	e.vencer( 2500 );
	const g = e.pulsar( gemelo );
	e.vencer( 2500 );
	assert.equal( e.pulsar().enviado, false, 'A con el teclado: su alta sigue pendiente' );
	const t = e.pulsar( tercero );

	a.fallar( 'error' );
	assert.equal( girando( e.boton ), false, 'A no tiene otro intento: vuelve' );
	b.responder( { fragments: {}, cart_hash: 'h' } );
	g.responder( { error: true, product_url: '/p/' } );
	await esperarPromesas();
	assert.equal( girando( gemelo ), false, 'el rechazo del gemelo devuelve SU botón' );
	assert.equal( e.pedidos.length, 0, 'y no habla: el tercero es más nuevo' );
	assert.equal( girando( tercero ), true, 'el tercero sigue esperando el suyo' );

	t.responder( { error: true, product_url: '/p/' } );
	await esperarPromesas();
	assert.deepEqual( e.navegaciones, [], 'la del tercero también va envuelta: no manda a la ficha' );
	assert.equal( girando( tercero ), false );
	assert.match( e.motivoDe( tercero ).textContent, /^Sin stock\./ );
} );

/*
 * RT-REV3-D2: el intento nuevo y el alta sólo retiraban el aviso de la tarjeta de SU
 * botón. Si el tomo se agregaba desde la vista rápida (u otra tarjeta), el
 * «reintenta» de la tarjeta que falló seguía 6 s a la vista, también con el cajón
 * cerrado, pidiendo reintentar un tomo que ya estaba en el carrito (medido en el
 * espejo en /tienda/ a 1440, Chromium y WebKit).
 */
test( 'RT-REV3-D2: un intento del tomo desde la vista rápida retira el «reintenta» de la tarjeta; el de otro tomo, no', async () => {
	const e = entorno( { respuestaCarrito: {} } );
	const vistaRapida = e.crearBoton( { enTarjeta: false } );
	const otroTomo = e.crearBoton( { id: 7 } );
	e.pulsar( otroTomo ).fallar( 'error' );
	e.pulsar().fallar( 'error' );
	const motivo = e.motivoDe( e.boton );
	const ajeno = e.motivoDe( otroTomo );
	assert.equal( motivo.hidden, false );
	assert.equal( ajeno.hidden, false );

	const clon = e.pulsar( vistaRapida );
	assert.equal( motivo.hidden, true, 'seguía pidiendo reintentar un tomo que se está agregando' );
	assert.equal( ajeno.hidden, false, 'el aviso de otro tomo sigue siendo verdad' );
	clon.responder( { fragments: { '.x': '<b/>' }, cart_hash: 'h' } );
	assert.equal( motivo.hidden, true );
	assert.equal( ajeno.hidden, false );
} );

test( 'RT-REV3-D2: el alta del tomo desde otra tarjeta retira el aviso de todas las tarjetas del tomo', async () => {
	const e = entorno( { respuestaCarrito: {} } );
	const gemelo = e.crearBoton();
	const a = e.pulsar();
	e.vencer( 2500 );
	const g = e.pulsar( gemelo );
	// El gemelo sale después de que A falle: la tarjeta de A, sin aviso (habla el gemelo).
	a.fallar( 'error' );
	g.fallar( 'error' );
	assert.equal( e.motivoDe( gemelo ).hidden, false );
	e.pulsar().responder( { fragments: {}, cart_hash: 'h' } );
	assert.equal( e.motivoDe( gemelo ).hidden, true, 'el tomo ya está en el carrito: «reintenta» no es verdad en ninguna tarjeta' );
} );

/*
 * REV-FT1-D1 (revisión 1 de la ronda final F-T): si fallaba la red al agregar desde
 * la vista rápida, el cliente no recibía ningún aviso. El clon no está en una
 * tarjeta, así que sinRespuesta() sólo le devolvía el botón: nada pintado y nada en
 * la región de estado. Y el «reintenta» que la tarjeta ya tuviera lo retiraba ese
 * mismo intento al nacer (RT-REV3-D2), así que la segunda caída borraba el único
 * aviso verdadero (medido en el espejo en /tienda/ a 1440, Chromium y WebKit).
 */
const regionDe = ( e ) => e.body.hijos.find( ( h ) => 'status' === h.atributos.role );

test( 'REV-FT1-D1: la tarjeta cayó, el reintento de la vista rápida también cae: el aviso vuelve a la tarjeta, sale en el diálogo y se anuncia', async () => {
	const e = entorno( { respuestaCarrito: {} } );
	const vistaRapida = e.crearBoton( { enTarjeta: false } );
	e.pulsar().fallar( 'error' );
	const enTarjeta = e.motivoDe( e.boton );
	assert.equal( enTarjeta.hidden, false, 'primero la tarjeta dice «reintenta»' );
	const region = regionDe( e );
	region.textContent = ''; // lo que se escriba desde aquí es de la segunda caída

	const clon = e.pulsar( vistaRapida );
	assert.equal( enTarjeta.hidden, true, 'al nacer el intento del tomo se retira (RT-REV3-D2)' );
	clon.fallar( 'error' );

	assert.equal( girando( vistaRapida ), false, 'el clon vuelve' );
	const enDialogo = e.motivoDe( vistaRapida );
	assert.ok( enDialogo, 'el cliente mira el diálogo: el aviso va junto a su botón' );
	assert.equal( enDialogo.hidden, false );
	assert.equal( enDialogo.textContent, 'No se pudo conectar, reintenta' );
	assert.equal( enDialogo.atributos.role, 'alert', 'bajo el diálogo modal la región del body queda inerte: el aviso se anuncia solo' );
	assert.equal( enDialogo.style.bottom, '', 'en el flujo, detrás del botón: no se posiciona como en la tarjeta' );
	// REV-ZT2o-1: deslizar el diálogo hasta el aviso subía el botón 56 px (teléfono en horizontal), y el
	// reintento en el mismo sitio caía en «Ver ficha completa» o en el aviso. El aviso va a la altura del
	// botón (loop.css): se ve siempre que se ve él, y nada se mueve.
	assert.deepEqual( enDialogo.desplazamientos, [], 'el diálogo no se mueve: el reintento cae en el mismo botón' );
	assert.equal( enTarjeta.hidden, false, 'y vuelve a la tarjeta, que es lo que queda al cerrar el diálogo' );
	assert.equal( enTarjeta.textContent, 'No se pudo conectar, reintenta' );
	assert.equal( enTarjeta.style.bottom, '58px', 'encima del botón de la tarjeta, sin taparlo' );
	assert.deepEqual( enTarjeta.desplazamientos, [], 'el de la tarjeta, detrás del diálogo, no mueve la página' );
	assert.equal( region.textContent, 'No se pudo conectar, reintenta', 'y la región de estado se reescribe' );
	assert.equal( e.pulsar().enviado, true, 'el candado del tomo se soltó: la tarjeta reintenta' );
} );

test( 'REV-FT1-D1: la vista rápida sola, sin respuesta: aviso junto al clon y en la tarjeta del tomo; no en la de otro tomo; nada si se canceló', async () => {
	const e = entorno( { respuestaCarrito: {} } );
	const vistaRapida = e.crearBoton( { enTarjeta: false } );
	const otroTomo = e.crearBoton( { id: 7 } );
	e.pulsar( vistaRapida ).fallar( 'timeout' );
	assert.equal( e.motivoDe( vistaRapida ).hidden, false );
	assert.equal( e.motivoDe( e.boton ).hidden, false );
	assert.equal( e.motivoDe( otroTomo ), null, 'el aviso es del tomo que no se agregó' );
	assert.equal( regionDe( e ).textContent, 'No se pudo conectar, reintenta' );
	assert.equal( e.pedidos.length, 0, 'no se pide el motivo al servidor: no respondió' );

	// Un reintento desde el diálogo retira los dos avisos, y el alta no los devuelve.
	const reintento = e.pulsar( vistaRapida );
	assert.equal( e.motivoDe( vistaRapida ).hidden, true );
	assert.equal( e.motivoDe( e.boton ).hidden, true );
	reintento.responder( { fragments: { '.x': '<b/>' }, cart_hash: 'h' } );
	assert.equal( e.motivoDe( vistaRapida ).hidden, true );
	assert.equal( e.motivoDe( e.boton ).hidden, true );

	const e2 = entorno( { respuestaCarrito: {} } );
	const qv2 = e2.crearBoton( { enTarjeta: false } );
	e2.pulsar( qv2 ).fallar( 'abort' );
	assert.equal( e2.motivoDe( qv2 ), null, 'cancelada por el propio código: nada que explicar' );
	assert.equal( e2.motivoDe( e2.boton ), null );
	assert.equal( girando( qv2 ), false );
} );

/*
 * REV-FT1-D3: si la vista rápida se abría con la petición de la tarjeta en vuelo,
 * cloneNode (quick-view.js de akibara-business) copiaba el `loading` de la tarjeta al
 * clon. Ese `loading` no era de ningún intento, así que nadie lo quitaba: la tarjeta
 * terminaba y volvía, y el «Añadir» del diálogo seguía atenuado como si procesara.
 */
for ( const [ desenlace, terminar ] of [
	[ 'sin respuesta', ( i ) => i.fallar( 'error' ) ],
	[ 'rechazado', ( i ) => i.responder( { error: true, product_url: '/rurouni-kenshin-10/' } ) ],
] ) {
	test( `REV-FT1-D3: el clon que copió el \`loading\` de la tarjeta en vuelo vuelve cuando termina el último intento del tomo (${ desenlace })`, async () => {
		const e = entorno( { respuestaCarrito: { data: { errors: [ 'Sin stock.' ], cart_url: '/carrito/' } } } );
		const otroTomo = e.crearBoton( { id: 7 } );
		const gemelo = e.crearBoton();
		const tarjeta = e.pulsar();
		e.vencer( 2500 );
		const segundo = e.pulsar( gemelo ); // otra tarjeta del tomo: espera en la cola detrás del primero
		const espera = e.pulsar( otroTomo ); // otro tomo, también en la cola
		// La vista rápida se abre ahora: el clon nace con el `loading` de la tarjeta.
		const clon = e.crearBoton( { enTarjeta: false } );
		clon.clases.add( 'loading' );

		terminar( tarjeta );
		await esperarPromesas();
		assert.equal( girando( clon ), true, 'el tomo sigue en vuelo (su segundo intento sale ahora): el clon lo dice' );

		terminar( segundo );
		await esperarPromesas();
		assert.equal( girando( clon ), false, 'el clon se quedaba atenuado para siempre' );
		assert.equal( girando( e.boton ), false );
		assert.equal( girando( gemelo ), false );
		assert.equal( girando( otroTomo ), true, 'el botón de otro tomo que espera en la cola no se toca' );
		espera.responder( { fragments: {}, cart_hash: 'h' } );
		assert.equal( girando( otroTomo ), false );
	} );
}

/*
 * REV-FT2-D2 (revisión 2 de la ronda final F-T): el aviso junto al clon de la vista
 * rápida va en el flujo y alarga el diálogo, que va centrado: al aparecer lo sube
 * 19 px (a 1440) y, cuando se iba solo a los 6 s, lo bajaba otra vez con el
 * «Añadir» bajo el puntero, sin que el cliente hiciera nada (un desplazamiento de
 * diseño sin interacción, medido en el espejo en /tienda/, Chromium y WebKit).
 */
test( 'REV-FT2-D2: el aviso junto al clon no se va solo; el de la tarjeta, posicionado, sí; el siguiente intento los retira', () => {
	const e = entorno( { respuestaCarrito: {} } );
	const vistaRapida = e.crearBoton( { enTarjeta: false } );
	e.pulsar( vistaRapida ).fallar( 'error' );
	const enDialogo = e.motivoDe( vistaRapida );
	const enTarjeta = e.motivoDe( e.boton );
	assert.equal( e.pendientes( 6000 ), 1, 'sólo el aviso de la tarjeta lleva reloj' );
	e.vencer( 6000 );
	assert.equal( enTarjeta.hidden, true, 'el de la tarjeta no mueve nada: se va solo, como en la ficha' );
	assert.equal( enDialogo.hidden, false, 'el del diálogo sigue: al irse bajaba el diálogo entero' );
	e.pulsar( vistaRapida );
	assert.equal( enDialogo.hidden, true, 'lo retira el siguiente intento del tomo' );
} );

/*
 * REV-FT3-D1: quitarle el reloj no bastaba. En el flujo, el aviso alargaba el
 * diálogo también al APARECER: con un fallo que tarda más de medio segundo (un
 * 5xx, un timeout), el diálogo subía 19 px con «Añadir» bajo el puntero, un
 * desplazamiento de diseño sin interacción (0,0134 a 1440 y 0,0208 a 820 en
 * Chromium). Ahora va posicionado, como en las tarjetas. REV-FT4-D3: encima del
 * botón y opaco tapaba la sinopsis, y entera si era de una línea (Blue Lock 02).
 * REV-ZT2o-1: debajo, junto a «Ver ficha completa», quedaba fuera de la vista con el
 * diálogo deslizado hasta el botón, y deslizarlo hasta el aviso movía el botón bajo
 * el dedo que reintentaba. Va a la izquierda del botón y a su altura, sobre la
 * columna de la portada. La geometría se mide en el navegador
 * (tema-remanentes.spec.mjs).
 */
test( 'REV-FT3-D1, REV-FT4-D3 y REV-ZT2o-1: el aviso de la vista rápida va posicionado a la izquierda del botón, a su altura', () => {
	const hoja = loop.replace( /\/\*[\s\S]*?\*\//g, '' );
	const regla = ( sel, donde = hoja ) => {
		const m = new RegExp( '(?:^|\\})\\s*' + sel.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '\\s*\\{([^}]*)\\}', 'm' ).exec( donde );
		assert.ok( m, `falta la regla ${ sel }` );
		return m[ 1 ];
	};
	assert.match( regla( '.akb-qv__actions' ), /position:\s*relative;/, 'las acciones son la caja de referencia del aviso' );
	const aviso = regla( '.akb-qv__actions > .akb-tarjeta-motivo', bloqueConElAviso( hoja ) );
	assert.match( aviso, /position:\s*absolute;/, 'fuera del flujo: no cambia el alto del diálogo' );
	assert.match( aviso, /(?:^|;)\s*top:\s*0;/, 'a la altura del botón: se ve siempre que se ve él' );
	assert.match( aviso, /min-height:\s*100%;/, 'toda la franja del botón' );
	assert.match( aviso, /right:\s*calc\(100% \+ 22px\);/, 'a la izquierda, en la columna de la portada (el hueco entre columnas de quick-view.css)' );
	assert.match( aviso, /width:\s*196px;/, 'lo que mide la portada en quick-view.css' );
	assert.match( aviso, /(?:^|;)\s*margin:\s*0;/, 'posicionado, un margen lo sacaría de la franja del botón' );
	assert.doesNotMatch( aviso, /(?:^|;)\s*(?:left|bottom):/, 'ni se estira hacia la sinopsis ni fuera de la franja' );
	assert.match( regla( '.akb-qv__actions > .akb-tarjeta-motivo' ), /background:\s*var\(--aki-surface-2\);/, 'opaco, el fondo del diálogo: se lee encima de la portada' );
	assert.match( hoja, /\.akb-qv__actions > \.akb-tarjeta-motivo\[hidden\]\s*\{\s*display:\s*none;/, 'con `display: flex`, el [hidden] no lo esconde solo' );
} );

/*
 * VERIF-Z-2 (verificación previa al despliegue del 2026-09-26): la columna de la
 * portada sólo existe con la hoja de la vista rápida, que va con
 * media="(min-width: 769px)". Con la vista rápida abierta y la ventana pasando a
 * < 769 px antes de que fallara la red (girar la tableta, media pantalla), la regla
 * sin corte dejaba el aviso en x −199…−3: 0 px a la vista, en Chromium y WebKit. La
 * geometría servida se mide en
 * tools/akibara-e2e/specs/tema-seguimiento-sellos-y-avisos.spec.mjs.
 */
test( 'VERIF-Z-2: la posición a la izquierda del botón va sólo con la hoja de la vista rápida; sin ella, el aviso queda en el flujo, a la vista', () => {
	const hoja = loop.replace( /\/\*[\s\S]*?\*\//g, '' );
	const bloque = bloqueConElAviso( hoja );
	assert.ok( bloque, 'la posición va dentro de un @media' );
	const media = /^@media\s*([^{]+)\{/.exec( bloque )[ 1 ].trim();
	const quickView = path.join( raiz, '../../plugins/akibara-business/src/Modules/QuickView/QuickView.php' );
	const corte = fs.existsSync( quickView ) ? /const MEDIA = '([^']+)'/.exec( fs.readFileSync( quickView, 'utf8' ) )[ 1 ] : '(min-width: 769px)';
	assert.equal( media, corte, 'el mismo corte con que akibara-business encola quick-view.css' );
	// Fuera de ese @media, ninguna regla del aviso lo saca del flujo.
	const fuera = hoja.replace( bloque, '' );
	for ( const m of fuera.matchAll( /([^{}]*\.akb-qv__actions\s*>\s*\.akb-tarjeta-motivo[^{}]*)\{([^}]*)\}/g ) ) {
		assert.doesNotMatch( m[ 2 ], /position:\s*(absolute|fixed)|(?:^|;)\s*(right|left|top|bottom):|(?:^|;)\s*width:/, `${ m[ 1 ].trim() }: sin la hoja de la vista rápida quedaba en x −199…−3, fuera de la pantalla` );
	}
} );

/*
 * REV-ST-1f-D1: en el flujo (< 769 px) el aviso sólo llevaba margen arriba, y el aire
 * que separa las acciones de «Ver ficha completa» vive en quick-view.css, que ahí no
 * se aplica: quedaba a 2-3 px del enlace (medido en el espejo, Chromium y WebKit). La
 * distancia servida se mide en tools/akibara-e2e/specs/tema-seguimiento-sellos-y-avisos.spec.mjs.
 */
test( 'REV-ST-1f-D1: en el flujo, el aviso de la vista rápida lleva margen también debajo', () => {
	const hoja = loop.replace( /\/\*[\s\S]*?\*\//g, '' );
	const fuera = hoja.replace( bloqueConElAviso( hoja ), '' );
	const m = /(?:^|\})\s*\.akb-qv__actions\s*>\s*\.akb-tarjeta-motivo\s*\{([^}]*)\}/.exec( fuera );
	assert.ok( m, 'falta la regla general del aviso' );
	const margen = /(?:^|;)\s*margin:\s*([^;]+);/.exec( m[ 1 ] );
	assert.ok( margen, 'la regla general fija el margen' );
	// Abajo según el atajo: 1 o 2 valores → el primero; 3 o 4 → el tercero.
	const v = margen[ 1 ].trim().match( /var\([^)]*\)|\S+/g );
	const abajo = v.length <= 2 ? v[ 0 ] : v[ 2 ];
	assert.doesNotMatch( abajo, /^0(?:px)?$/, `margin: ${ margen[ 1 ].trim() } deja el aviso pegado a «Ver ficha completa»` );
	assert.doesNotMatch( m[ 1 ], /margin-bottom:\s*0/, 'ni se anula aparte' );
} );

/** El bloque @media (con llaves) que contiene la regla del aviso de la vista rápida, o null. */
function bloqueConElAviso( hoja ) {
	for ( const m of hoja.matchAll( /@media[^{]*\{/g ) ) {
		let prof = 1;
		let k = m.index + m[ 0 ].length;
		while ( k < hoja.length && prof > 0 ) {
			prof += '{' === hoja[ k ] ? 1 : '}' === hoja[ k ] ? -1 : 0;
			k++;
		}
		const bloque = hoja.slice( m.index, k );
		if ( /\.akb-qv__actions\s*>\s*\.akb-tarjeta-motivo\s*\{/.test( bloque ) ) {
			return bloque;
		}
	}
	return null;
}

/*
 * REV-FT3-D1, lo que quedaba al medirlo con el fallo tardío: el aviso de la
 * tarjeta se medía YA metido en ella, así que nacía con el `bottom: 0` de
 * loop.css y pasaba al suyo después. Con movimiento reducido (transiciones de
 * 0,01 ms) ese paso es un desplazamiento de diseño sin interacción: 0,0006 a
 * 1440 y 0,0013 a 820 en Chromium, con la tarjeta del tomo detrás del diálogo.
 */
/*
 * REV-FT3-D1, el clon en vuelo: su giro era el de WooCommerce, en el ::after (el
 * del barrido), que al terminar cambiaba de caja (0,0003 a 1440 en Chromium), y
 * donde la hoja de WooCommerce no carga no giraba nada. Ahora gira como la
 * tarjeta. Y la regla del hover, que pesa lo mismo y va después, le devolvía el
 * rótulo blanco encima del giro: al clon con el ratón encima, y a la tarjeta en
 * el móvil, donde el toque deja el hover puesto.
 */
test( 'REV-FT3-D1: el clon en vuelo gira como la tarjeta, y el hover no les devuelve el rótulo', () => {
	const cuerpo = ( sel ) => {
		const m = new RegExp( '(?:^|\\})\\s*' + sel.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '\\s*\\{([^}]*)\\}', 'm' ).exec( loop.replace( /\/\*[\s\S]*?\*\//g, '' ) );
		assert.ok( m, `falta la regla ${ sel }` );
		return m[ 1 ];
	};
	assert.match( cuerpo( '.woocommerce ul.products li.product .button.loading,\n.woocommerce ul.products li.product .button.loading:hover' ), /color:\s*transparent;/ );
	const clon = cuerpo( '.akb-qv__actions .button.button.loading,\n.akb-qv__actions .button.button.loading:hover' );
	assert.match( clon, /color:\s*transparent;/ );
	assert.match( clon, /opacity:\s*1;/, 'sin el 25 % de WooCommerce' );
	assert.match( clon, /padding-right:\s*var\(--space-5\);/, 'sin el relleno de 2,618em de WooCommerce' );
	assert.doesNotMatch( clon, /pointer-events/, 'el clon sigue pulsable en vuelo (atc-guard.js, punto 6)' );
	assert.match( cuerpo( '.akb-qv__actions .button.button.loading::after' ), /display:\s*none;/, 'sin el giro de WooCommerce en el ::after del barrido' );
	assert.match( cuerpo( '.woocommerce ul.products li.product .button.loading::before,\n.akb-qv__actions .button.loading::before' ), /animation:\s*akb-atc-spin/ );
} );

test( 'REV-FT3-D1: el aviso de la tarjeta se mide antes de entrar en ella y nace ya en su sitio', () => {
	const e = entorno( { respuestaCarrito: {} } );
	const conAviso = [];
	const medir = e.tarjeta.getBoundingClientRect;
	e.tarjeta.getBoundingClientRect = function () {
		conAviso.push( this.hijos.some( ( h ) => 'akb-tarjeta-motivo' === h.className ) );
		return medir.call( this );
	};
	e.pulsar().fallar( 'error' );
	assert.deepEqual( conAviso, [ false ], 'se mide una vez, sin el aviso dentro' );
	assert.equal( e.motivoDe( e.boton ).style.bottom, '58px' );
} );

/*
 * REV-FT2-D3: en un alta que sale bien, WooCommerce (updateButton) le quita
 * `loading` y le pone `added` al botón de ESE intento aunque el botón tenga otro en
 * la cola; y el oyente de `added_to_cart` soltaba el candado del tomo, que era del
 * intento nuevo. El botón mostraba la ✓, sin giro y con puntero, con su segunda alta
 * en vuelo, y otra pulsación mandaba una tercera (medido en el espejo en /tienda/ a
 * 1440 y 390, Chromium y WebKit). Con el mismo botón (el teclado sobre la tarjeta
 * que gira) ya no hay segunda en la cola: con su alta pendiente no manda otra
 * (REV-FT4-D1). Queda el caso de otro botón del tomo.
 */
test( 'REV-FT2-D3: A pulsado otra vez con su alta en vuelo no deja una segunda en la cola: la ✓ es de la única', () => {
	const e = entorno( { respuestaCarrito: {} } );
	const ok = { fragments: { '.x': '<b/>' }, cart_hash: 'h' };
	const primero = e.pulsar();
	e.vencer( 2500 );
	assert.equal( e.pulsar().enviado, false, 'con el teclado, pasada la red de seguridad: antes esperaba en la cola' );
	assert.equal( girando( e.boton ), true );
	primero.responder( ok );
	assert.deepEqual( primero.exitos, [ ok ], 'el éxito sigue el camino de WooCommerce' );
	assert.equal( girando( e.boton ), false );
	assert.equal( e.boton.clases.has( 'added' ), true, 'la ✓ de su única alta' );
	assert.equal( e.pulsar().enviado, true );
} );

test( 'REV-FT2-D3: tarjeta → gemelo en cola → la tarjeta sale bien: su ✓ es suya, el gemelo sigue girando y el candado es del gemelo', () => {
	const e = entorno( { respuestaCarrito: {} } );
	const gemelo = e.crearBoton();
	const ok = { fragments: {}, cart_hash: 'h' };
	const a = e.pulsar();
	e.vencer( 2500 );
	const g = e.pulsar( gemelo );
	a.responder( ok );
	assert.equal( e.boton.clases.has( 'added' ), true, 'la tarjeta agregó: su ✓' );
	assert.equal( girando( e.boton ), false );
	assert.equal( girando( gemelo ), true );
	assert.equal( e.pendientes( 2500 ), 1, 'el candado es del gemelo, que vuela' );
	g.responder( ok );
	assert.equal( gemelo.clases.has( 'added' ), true );
	assert.equal( e.pendientes( 2500 ), 0 );
} );

/*
 * REV-FT3-D2: una pulsación que el candado del tomo bloquea tampoco envía, pero
 * WooCommerce (`ajax_request_not_sent` → updateButton) le quita el `loading` al
 * botón aunque su propia alta siga en vuelo. El botón quedaba sin giro y con
 * puntero, y pasada la red de seguridad de 2,5 s un clic o un toque mandaba otra
 * alta: con un Enter de más se volvía a los tres pedidos (medido en el espejo en
 * /tienda/ a 1440 y 390, Chromium y WebKit).
 */
test( 'REV-FT3-D2: una pulsación bloqueada no le quita el giro al botón cuya alta vuela', () => {
	const e = entorno( { respuestaCarrito: {} } );
	const ok = { fragments: { '.x': '<b/>' }, cart_hash: 'h' };
	const primero = e.pulsar();
	assert.equal( e.pulsar().enviado, false, 'el candado bloquea la segunda pulsación' );
	assert.equal( girando( e.boton ), true, 'su alta sigue en vuelo: el botón sigue girando (y sin puntero)' );
	assert.equal( e.boton.clases.has( 'added' ), false );
	e.vencer( 2500 ); // la red de seguridad: el botón sigue girando, así que el clic no lo alcanza
	assert.equal( girando( e.boton ), true );
	primero.responder( ok );
	assert.equal( girando( e.boton ), false, 'lo devuelve su alta, y sólo ésa' );
	assert.equal( e.boton.clases.has( 'added' ), true, 'con la ✓' );
} );

test( 'REV-FT3-D2: el que termina sin alta ya no gira: una pulsación bloqueada después lo deja quieto', () => {
	const e = entorno( { respuestaCarrito: {} } );
	const gemelo = e.crearBoton();
	const a = e.pulsar();
	// El gemelo no tiene alta propia: WooCommerce lo devuelve, como siempre.
	assert.equal( e.pulsar( gemelo ).enviado, false );
	assert.equal( girando( gemelo ), false, 'un botón sin alta propia no se queda girando' );
	assert.equal( girando( e.boton ), true );
	a.fallar( 'error' );
	assert.equal( girando( e.boton ), false );
	const g = e.pulsar( gemelo );
	assert.equal( e.pulsar().enviado, false, 'el candado es del gemelo' );
	assert.equal( girando( e.boton ), false, 'su alta ya terminó: no vuelve a girar' );
	assert.equal( girando( gemelo ), true );
	g.fallar( 'abort' );
	assert.equal( girando( gemelo ), false );
} );

/*
 * REV-FT4-D1 (revisión 4 de la ronda final F-T): el candado del tomo vence a los
 * 2,5 s, y un botón con SU alta todavía en vuelo mandaba otra. En la tarjeta,
 * `.loading` le quita el puntero y sólo llegaba el teclado; el clon de la vista
 * rápida sigue pulsable en vuelo, así que bastaba un segundo clic: la primera
 * cerraba el diálogo y la segunda sumaba otra unidad sin avisar, o, con una
 * sola unidad, WooCommerce la rechazaba y el cliente acababa en la ficha (medido
 * en el espejo en /tienda/ a 1440 con altas de 4 s, Chromium y WebKit: 2 altas).
 */
test( 'REV-FT4-D1: el clon con su propia alta en vuelo no manda otra pasada la red de 2,5 s', () => {
	const e = entorno( { respuestaCarrito: {} } );
	const vistaRapida = e.crearBoton( { enTarjeta: false } );
	const ok = { fragments: { '.x': '<b/>' }, cart_hash: 'h' };
	const primera = e.pulsar( vistaRapida );
	e.vencer( 2500 );
	assert.equal( e.pulsar( vistaRapida ).enviado, false, 'a los 2,7 s salía una segunda alta del mismo tomo' );
	assert.equal( girando( vistaRapida ), true, 'y sigue girando: su alta vuela' );
	assert.equal( vistaRapida.clases.has( 'added' ), false );
	primera.responder( ok );
	assert.equal( girando( vistaRapida ), false, 'lo devuelve su alta' );
	assert.equal( vistaRapida.clases.has( 'added' ), true );
	assert.equal( e.pulsar( vistaRapida ).enviado, true, 'terminada su alta, vuelve a agregar' );
} );

test( 'REV-FT4-D1: la tarjeta con su alta en vuelo tampoco manda otra con el teclado; la vista rápida sigue siendo otro camino', async () => {
	const e = entorno( { respuestaCarrito: {} } );
	const vistaRapida = e.crearBoton( { enTarjeta: false } );
	const primera = e.pulsar();
	e.vencer( 2500 );
	assert.equal( e.pulsar().enviado, false, 'Enter sobre la tarjeta que gira' );
	assert.equal( girando( e.boton ), true );
	const clon = e.pulsar( vistaRapida );
	assert.equal( clon.enviado, true, 'otro botón del tomo no tiene alta propia: espera en la cola' );
	primera.fallar( 'error' );
	await esperarPromesas();
	assert.equal( girando( e.boton ), false );
	assert.equal( e.motivoDe( e.boton ), null, 'habla el del clon' );
	clon.responder( { fragments: {}, cart_hash: 'h' } );
	assert.equal( vistaRapida.clases.has( 'added' ), true );
} );

/*
 * REV-FT4-D2: abierta la vista rápida con la tarjeta en vuelo, el clon copia su
 * `loading` y gira. Un clic sobre él dentro del candado no sale, y WooCommerce
 * (`ajax_request_not_sent` → updateButton) le quitaba el `loading`: el clon
 * quedaba en reposo, con el rótulo, mientras el alta del tomo seguía en vuelo
 * (medido en el espejo en /tienda/ a 1440, Chromium y WebKit).
 */
test( 'REV-FT4-D2: el clon que copió el `loading` de la tarjeta en vuelo sigue girando tras una pulsación bloqueada', () => {
	const e = entorno( { respuestaCarrito: {} } );
	const gemelo = e.crearBoton();
	const tarjeta = e.pulsar();
	const clon = e.crearBoton( { enTarjeta: false } );
	clon.clases.add( 'loading' ); // cloneNode, en quick-view.js de akibara-business
	assert.equal( e.pulsar( clon ).enviado, false, 'dentro del candado del tomo' );
	assert.equal( girando( clon ), true, 'quedaba en reposo con el alta del tomo en vuelo' );
	assert.equal( e.pulsar( gemelo ).enviado, false );
	assert.equal( girando( gemelo ), false, 'una tarjeta sin alta propia no gira: nadie se lo quitaría al terminar bien' );
	tarjeta.fallar( 'error' );
	assert.equal( girando( clon ), false, 'el tomo terminó sin alta: vuelve' );
	assert.equal( e.pulsar( clon ).enviado, true );
} );

/*
 * Lo que quedaba de REV-FT4-D2 con ALTA: la tarjeta salía bien, el diálogo se
 * cerraba (quick-view.js) y el clon, que guarda el diálogo cerrado, seguía con el
 * giro copiado: un «Añadir» en `.loading` en la página, fuera de la vista (lo
 * contaba el estrés de la verificación, Chromium y WebKit a 1440). Pasaba también
 * sin pulsarlo (REV-FT1-D3 sólo lo devolvía cuando el tomo terminaba sin alta).
 */
for ( const pulsado of [ false, true ] ) {
	test( `REV-FT4-D2: con el alta de la tarjeta, el clon que copió su giro deja de girar (${ pulsado ? 'tras una pulsación bloqueada' : 'sin pulsarlo' })`, () => {
		const e = entorno( { respuestaCarrito: {} } );
		const otroTomo = e.crearBoton( { id: 7 } );
		const tarjeta = e.pulsar();
		const espera = e.pulsar( otroTomo ); // otro tomo, en la cola
		const clon = e.crearBoton( { enTarjeta: false } );
		clon.clases.add( 'loading' );
		if ( pulsado ) {
			assert.equal( e.pulsar( clon ).enviado, false );
			assert.equal( girando( clon ), true );
		}
		tarjeta.responder( { fragments: { '.x': '<b/>' }, cart_hash: 'h' } );
		assert.equal( girando( clon ), false, 'quedaba girando en el diálogo cerrado' );
		assert.equal( e.boton.clases.has( 'added' ), true );
		assert.equal( girando( otroTomo ), true, 'el de otro tomo, con su alta en la cola, sigue' );
		espera.responder( { fragments: {}, cart_hash: 'h' } );
		assert.equal( girando( otroTomo ), false );
	} );
}
