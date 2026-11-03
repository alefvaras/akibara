'use strict';

/*
 * Lote QF-gp «correcciones-qa» (QA integral del tema, 2026-09-24).
 *
 * Cada prueba falla con el árbol de antes del lote. Lo que se mide en un
 * navegador de verdad (desplazamiento al cerrar el menú, puente del mega-menú,
 * orden de Tab de la tarjeta, zoom al 200 %, toque temprano con red lenta,
 * carrito de bloques sin recarga) está en
 * tools/akibara-e2e/specs/tema-correcciones-qa-integral.spec.mjs.
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const raiz = path.resolve( __dirname, '..' );
const leer = ( rel ) => fs.readFileSync( path.join( raiz, rel ), 'utf8' );
const sinComentarios = ( css ) => css.replace( /\/\*[\s\S]*?\*\//g, '' );

/* ── QAM-2: toque temprano ───────────────────────────────────────────── */

/**
 * Ejecuta toque-temprano.js con un documento de mentira. `jquery` simula el
 * «ready» asíncrono de jQuery 3; `estado` es document.readyState al cargar;
 * `navegacion`, la Navigation API: Chromium dispara `navigateerror` al detener
 * una navegación, pero también con el POST de la ficha aún de camino (REV-ZT1o-1).
 */
function entornoToque( { jquery = false, estado = 'loading', navegacion = false } = {} ) {
	const oyentesDoc = {};
	// Los relojes, con su vencimiento en el reloj de mentira (`ahora`, en ms).
	let temporizadores = [];
	let ahora = 0;
	let ultimoReloj = 0;
	const listos = [];
	const nodos = [];
	const doc = {
		readyState: estado,
		addEventListener( tipo, fn, captura ) { ( oyentesDoc[ tipo ] = oyentesDoc[ tipo ] || [] ).push( { fn, captura: !! captura } ); },
		removeEventListener( tipo, fn ) { oyentesDoc[ tipo ] = ( oyentesDoc[ tipo ] || [] ).filter( ( o ) => o.fn !== fn ); },
		documentElement: { contains: ( n ) => ! n.fuera },
		// Sólo lo que pide toque-temprano.js: los controles marcados que siguen en la
		// página y los formularios de la ficha inertes.
		querySelectorAll( sel ) {
			if ( 'form.cart[inert]' === sel ) {
				return nodos.filter( ( n ) => ! n.fuera && n.matches( 'form.cart' ) && 'inert' in n.atributos );
			}
			assert.equal( sel, '.akb-toque-pendiente' );
			return nodos.filter( ( n ) => ! n.fuera && n.clases.has( 'akb-toque-pendiente' ) );
		},
	};
	const hacer = ( selectorPropio, atributos = {} ) => {
		const n = {
			clases: new Set(),
			atributos: Object.assign( {}, atributos ),
			clics: 0,
			disabled: false,
			fuera: false,
			classList: {
				add: ( c ) => n.clases.add( c ),
				remove: ( c ) => n.clases.delete( c ),
			},
			setAttribute( k, v ) { n.atributos[ k ] = String( v ); },
			getAttribute( k ) { return k in n.atributos ? n.atributos[ k ] : null; },
			removeAttribute( k ) { delete n.atributos[ k ]; },
			closest( sel ) { return sel.split( ',' ).map( ( s ) => s.trim() ).includes( selectorPropio ) ? n : null; },
			matches( sel ) { return sel.split( ',' ).map( ( s ) => s.trim() ).includes( selectorPropio ); },
			// Un clic «de verdad» pasa primero por los oyentes de captura del documento.
			click() { n.clics++; orden.push( n ); },
		};
		nodos.push( n );
		return n;
	};
	const orden = [];
	const oyentesWin = {};
	const oyentesNav = {};
	const win = {
		setTimeout: ( fn, ms = 0 ) => {
			temporizadores.push( { fn, ms, vence: ahora + ms, id: ++ultimoReloj } );
			return ultimoReloj;
		},
		clearTimeout: ( id ) => { temporizadores = temporizadores.filter( ( r ) => r.id !== id ); },
		addEventListener( tipo, fn ) { ( oyentesWin[ tipo ] = oyentesWin[ tipo ] || [] ).push( fn ); },
	};
	if ( jquery ) {
		win.jQuery = ( fn ) => { listos.push( fn ); };
	}
	if ( navegacion ) {
		win.navigation = { addEventListener( tipo, fn ) { ( oyentesNav[ tipo ] = oyentesNav[ tipo ] || [] ).push( fn ); } };
	}
	/** Corre, en orden de vencimiento, los relojes que `cuales` elige. */
	const correr = ( cuales ) => {
		const toca = temporizadores.filter( cuales ).sort( ( a, b ) => a.vence - b.vence );
		temporizadores = temporizadores.filter( ( r ) => ! toca.includes( r ) );
		toca.forEach( ( r ) => r.fn() );
	};
	vm.runInContext( leer( 'assets/js/toque-temprano.js' ), vm.createContext( { document: doc, window: win } ) );
	return {
		oyentesDoc,
		/** Los clics repetidos, en el orden en que se hicieron. */
		orden,
		boton: hacer( '.ajax_add_to_cart', { 'data-product_id': '30555' } ),
		boton2: hacer( '.ajax_add_to_cart', { 'data-product_id': '30534' } ),
		// Otro botón del MISMO tomo (p. ej. el carrusel y la rejilla de la portada).
		botonGemelo: hacer( '.ajax_add_to_cart', { 'data-product_id': '30555' } ),
		menu: hacer( '#menu-toggle' ),
		buscar: hacer( '#bottom-nav-search' ),
		otro: hacer( '.otra-cosa' ),
		// El formulario de la ficha y otro cualquiera (el buscador de la cabecera).
		ficha: hacer( 'form.cart' ),
		buscador: hacer( 'form.search-form' ),
		/**
		 * Un envío del formulario, como lo reparte el navegador: los oyentes de
		 * captura del documento, el del propio formulario (`conFetch`: el de
		 * pdp-ajax-atc.js ya enganchado, que lo previene y agrega por fetch) y los
		 * de burbuja del documento. Devuelve si salió un POST normal. Un
		 * formulario `inert` no recibe el toque: el navegador no llega a enviarlo.
		 */
		enviar( form, { conFetch = false } = {} ) {
			if ( 'inert' in form.atributos ) {
				return { post: false, inerte: true };
			}
			const e = { target: form, defaultPrevented: false, detenido: false, preventDefault() { this.defaultPrevented = true; }, stopPropagation() { this.detenido = true; } };
			const fase = ( captura ) => ( oyentesDoc.submit || [] ).filter( ( o ) => o.captura === captura && ! o.muerto ).forEach( ( o ) => { if ( ! e.detenido ) { o.fn( e ); } } );
			fase( true );
			if ( ! e.detenido && conFetch ) {
				e.preventDefault();
			}
			fase( false );
			return { post: ! e.defaultPrevented, inerte: false };
		},
		/** Volver a la página con «Atrás»: `persisted` si sale de la caché de ida y vuelta. */
		volver( persisted ) { ( oyentesWin.pageshow || [] ).forEach( ( fn ) => fn( { persisted } ) ); },
		/**
		 * WebKit detiene la carga con el POST de camino (VERIF-Z-1, medido en el espejo
		 * con webkit-2342): los oyentes del documento registrados hasta ahora dejan de
		 * oír; los relojes siguen, y quitar y volver a poner uno lo revive.
		 */
		detenerWebKit() { Object.values( oyentesDoc ).forEach( ( lista ) => lista.forEach( ( o ) => { o.muerto = true; } ) ); },
		/** Oyentes de `submit` del documento que todavía oyen. */
		oyentesDeEnvio: () => ( oyentesDoc.submit || [] ).filter( ( o ) => ! o.muerto ).length,
		/** Un `navigateerror` de Chromium: se detuvo la carga, o se abortó otra navegación y el POST sigue. */
		navegacionFallida() { ( oyentesNav.navigateerror || [] ).forEach( ( fn ) => fn( { error: { name: 'AbortError' } } ) ); },
		/** Los relojes con plazo que siguen armados (ms de cada uno). */
		plazos: () => temporizadores.filter( ( r ) => r.ms > 0 ).map( ( r ) => r.ms ),
		/** Pasa el tiempo: vencen los relojes con plazo que tocan. */
		avanzar( ms ) {
			ahora += ms;
			correr( ( r ) => r.ms > 0 && r.vence <= ahora );
		},
		/** Un toque: devuelve el evento para ver si se retuvo. */
		tocar( nodo ) {
			const e = { target: nodo, prevenido: false, detenido: false, preventDefault() { this.prevenido = true; }, stopPropagation() { this.detenido = true; } };
			( oyentesDoc.click || [] ).filter( ( o ) => o.captura ).forEach( ( o ) => o.fn( e ) );
			return e;
		},
		dcl() { ( oyentesDoc.DOMContentLoaded || [] ).forEach( ( o ) => o.fn() ); },
		ready() { listos.splice( 0 ).forEach( ( fn ) => fn() ); },
		/** Un turno del bucle de eventos: los `setTimeout( fn, 0 )`, no los que tienen plazo. */
		turno() { correr( ( r ) => ! r.ms ); },
	};
}

test( 'QAM-2: un toque en «Añadir» antes de arrancar se retiene, se marca y se repite UNA vez cuando WooCommerce ya enganchó', () => {
	const t = entornoToque( { jquery: true } );
	const e = t.tocar( t.boton );
	assert.equal( e.prevenido, true, 'antes el toque se perdía sin nada detrás' );
	assert.equal( e.detenido, true, 'y no llega a un manejador a medio enganchar (lo haría dos veces)' );
	assert.ok( t.boton.clases.has( 'akb-toque-pendiente' ) );
	assert.equal( t.boton.atributos[ 'aria-busy' ], 'true' );
	assert.equal( t.boton.clics, 0 );

	t.dcl();
	t.turno();
	assert.equal( t.boton.clics, 0, 'con jQuery se espera a su «ready», donde WooCommerce engancha add-to-cart.js' );
	t.ready();
	assert.equal( t.boton.clics, 0, 'y un turno más: jQuery 3 corre el ready en diferido' );
	t.turno();
	assert.equal( t.boton.clics, 1, 'se repite el toque' );
	assert.equal( t.boton.clases.has( 'akb-toque-pendiente' ), false );
	assert.equal( t.boton.atributos[ 'aria-busy' ], undefined );

	// Después el oyente se retira: ningún clic posterior se retiene.
	assert.equal( ( t.oyentesDoc.click || [] ).length, 0 );
	assert.equal( t.tocar( t.boton ).prevenido, false );
} );

test( 'QAM-2: sin jQuery (☰ y «Buscar», main.js) basta con DOMContentLoaded y un turno; de ☰ y «Buscar» sólo vale el último', () => {
	const t = entornoToque();
	t.tocar( t.menu );
	t.tocar( t.buscar );
	assert.equal( t.menu.clases.has( 'akb-toque-pendiente' ), false, 'abren el mismo cajón: el anterior deja de estar pendiente' );
	assert.equal( t.menu.atributos[ 'aria-busy' ], undefined );
	t.dcl();
	t.turno();
	assert.equal( t.buscar.clics, 1 );
	assert.equal( t.menu.clics, 0 );
} );

/*
 * Revisión RQF2-2: sólo se guardaba el ÚLTIMO toque, también entre controles
 * distintos. «Añadir» en el tomo A y después en el B (o ☰) antes de que cargara el
 * JS tiraba la compra de A sin avisar (medido: aria-busy [null, 'true'] y un único
 * add_to_cart, el de B).
 */
test( 'RQF2-2: «Añadir» en dos tomos distintos repite los DOS, en el orden de los toques', () => {
	const t = entornoToque( { jquery: true } );
	t.tocar( t.boton );
	t.tocar( t.boton2 );
	assert.equal( t.boton.atributos[ 'aria-busy' ], 'true', 'antes el primero se desmarcaba y se perdía' );
	assert.equal( t.boton2.atributos[ 'aria-busy' ], 'true' );
	t.dcl();
	t.ready();
	t.turno();
	assert.deepEqual( t.orden, [ t.boton, t.boton2 ] );
	assert.equal( t.boton.clases.has( 'akb-toque-pendiente' ), false );
	assert.equal( t.boton2.clases.has( 'akb-toque-pendiente' ), false );
} );

test( 'RQF2-2: dos toques en el mismo tomo (el mismo botón u otro del mismo producto) agregan UNO', () => {
	const t = entornoToque();
	t.tocar( t.boton );
	t.tocar( t.boton );
	t.tocar( t.botonGemelo );
	assert.equal( t.botonGemelo.atributos[ 'aria-busy' ], 'true', 'el toque en el gemelo se ve recibido' );
	t.dcl();
	t.turno();
	assert.equal( t.boton.clics, 1 );
	assert.equal( t.botonGemelo.clics, 0, 'dos toques seguidos no agregan dos tomos' );
	// Revisión RQF3-1: el gemelo no entraba en la cola y nadie lo desmarcaba:
	// quedaba atenuado y con aria-busy hasta recargar (medido en el espejo con la
	// tarjeta 30555 clonada, Chromium y WebKit; en producción la portada repite tomos).
	assert.equal( t.botonGemelo.clases.has( 'akb-toque-pendiente' ), false, 'el gemelo no queda atenuado' );
	assert.equal( t.botonGemelo.atributos[ 'aria-busy' ], undefined, 'ni ocupado para el lector de pantalla' );
	assert.equal( t.boton.clases.has( 'akb-toque-pendiente' ), false );
	assert.equal( t.boton.atributos[ 'aria-busy' ], undefined );
} );

test( 'RQF3-1: al soltar no queda ninguna marca, tampoco la de un control que ya no se repite', () => {
	// Un gemelo tocado ANTES que el botón que queda en la cola, con jQuery.
	const t = entornoToque( { jquery: true } );
	t.tocar( t.botonGemelo );
	t.tocar( t.boton );
	t.tocar( t.boton2 );
	t.dcl();
	t.ready();
	t.turno();
	assert.deepEqual( t.orden, [ t.botonGemelo, t.boton2 ], 'uno por tomo, el primero que se tocó' );
	for ( const n of [ t.botonGemelo, t.boton, t.boton2 ] ) {
		assert.equal( n.clases.has( 'akb-toque-pendiente' ), false );
		assert.equal( n.atributos[ 'aria-busy' ], undefined );
	}
} );

test( 'RQF2-2: un ☰ después de «Añadir» no tira la compra: se repiten los dos, la compra primero', () => {
	const t = entornoToque();
	t.tocar( t.boton );
	t.tocar( t.menu );
	assert.equal( t.boton.atributos[ 'aria-busy' ], 'true' );
	t.dcl();
	t.turno();
	assert.deepEqual( t.orden, [ t.boton, t.menu ] );
} );

test( 'RQF2-2: ☰ seguido de otro toque ya no vale (el cliente siguió de largo); la compra sí', () => {
	const t = entornoToque();
	t.tocar( t.menu );
	t.tocar( t.boton );
	assert.equal( t.menu.atributos[ 'aria-busy' ], undefined, 'se ve que ese toque ya no está pendiente' );
	t.dcl();
	t.turno();
	assert.deepEqual( t.orden, [ t.boton ], 'abrir el cajón encima taparía lo que está haciendo' );
} );

test( 'QAM-2: no retiene toques ajenos, ni repite sobre un nodo que ya no está o quedó deshabilitado', () => {
	const t = entornoToque();
	assert.equal( t.tocar( t.otro ).prevenido, false );

	t.tocar( t.boton );
	t.boton.fuera = true; // el buscador repintó el listado
	t.dcl();
	t.turno();
	assert.equal( t.boton.clics, 0 );

	const d = entornoToque();
	d.tocar( d.boton );
	d.boton.disabled = true;
	d.dcl();
	d.turno();
	assert.equal( d.boton.clics, 0 );
} );

/*
 * Deuda previa (verificación final del lote de mejoras del tema, 2026-09-25): antes
 * de que pdp-ajax-atc.js enganche su envío por fetch, «Añadir al carrito» de la
 * ficha envía el formulario en un POST normal, y cada envío es un alta. Un doble
 * toque mandaba dos: 2 de 34 dobles toques a ~400 ms dejaron el tomo con cantidad
 * 2 (medido en el espejo con el JS retrasado, Chromium, móvil y escritorio).
 */
test( 'ficha: dos envíos del formulario antes de que cargue pdp-ajax-atc.js mandan UN alta', () => {
	const t = entornoToque( { jquery: true } );
	assert.equal( t.enviar( t.ficha ).post, true, 'el primero sale: es la compra' );
	assert.equal( t.ficha.atributos.inert, '', 'con el POST de camino, el formulario queda inerte' );
	assert.deepEqual( t.enviar( t.ficha ), { post: false, inerte: true }, 'el segundo toque mandaba otro POST: el tomo quedaba con cantidad 2' );
	// La página arranca con el POST aún de camino: tampoco por fetch.
	t.dcl();
	t.ready();
	t.turno();
	assert.deepEqual( t.enviar( t.ficha, { conFetch: true } ), { post: false, inerte: true } );
	assert.equal( t.enviar( t.buscador ).post, true, 'los demás formularios, intactos' );
	assert.equal( 'inert' in t.buscador.atributos, false );
	assert.equal( t.enviar( t.buscador ).post, true );
} );

test( 'ficha: con pdp-ajax-atc.js enganchado nada queda inerte (su `busy` manda), y al volver con «Atrás» vuelve a comprar', () => {
	const t = entornoToque();
	t.dcl();
	t.turno();
	for ( let i = 0; i < 2; i++ ) {
		assert.deepEqual( t.enviar( t.ficha, { conFetch: true } ), { post: false, inerte: false }, 'un envío por fetch no deja la página' );
	}
	assert.equal( 'inert' in t.ficha.atributos, false );

	const v = entornoToque();
	assert.equal( v.enviar( v.ficha ).post, true );
	v.volver( false ); // una carga normal: nada que devolver
	assert.equal( v.ficha.atributos.inert, '' );
	v.volver( true ); // la misma página, desde la caché de ida y vuelta
	assert.equal( 'inert' in v.ficha.atributos, false, 'quedaba sin poder comprar hasta recargar' );
	assert.equal( v.enviar( v.ficha ).post, true );
	assert.deepEqual( v.enviar( v.ficha ), { post: false, inerte: true } );
} );

/*
 * REV-MT2-D1 (verificación de la micro-ronda M-T, 2026-09-25): si el cliente detenía
 * la carga (Esc, «Detener») con el POST de camino, la página se quedaba y el
 * formulario seguía inerte hasta recargar: «Añadir», la cantidad y el Tab muertos,
 * sin aviso. Medido en el espejo en Chromium y WebKit, a 1440 y a 390 táctil: a los
 * 15 s seguía inerte y el segundo toque no mandaba nada.
 *
 * Revisión 1 del lote Z-T (REV-ZT1o-2 / REV-ZT1-D2): el plazo de 12 s soltaba el
 * formulario con el POST aún de camino si el servidor tardaba más (bajo carga tardó
 * 7-8,5 s en el estrés del espejo), y el reintento a los 13 s era la segunda alta,
 * en Chromium y en WebKit. Límite conocido: quien detuvo la carga espera el plazo
 * (o recarga) para volver a comprar.
 */
test( 'REV-MT2-D1 / REV-ZT1o-2: el formulario vuelve al vencer el plazo, en todos los navegadores, y el plazo cubre un servidor lento (30-45 s)', () => {
	for ( const navegacion of [ false, true ] ) {
		const t = entornoToque( { jquery: true, navegacion } );
		assert.equal( t.enviar( t.ficha ).post, true );
		const [ plazo ] = t.plazos();
		assert.ok( plazo >= 30000 && plazo <= 45000, `plazo de ${ plazo } ms: entre 30 y 45 s` );
		// La página arranca con el POST de camino (sus `setTimeout( fn, 0 )` no lo sueltan).
		t.dcl();
		t.ready();
		t.turno();
		t.avanzar( 13000 );
		assert.deepEqual( t.enviar( t.ficha ), { post: false, inerte: true }, 'a los 13 s, con el POST de camino, salía la segunda alta' );
		t.avanzar( plazo - 13000 - 1 );
		assert.deepEqual( t.enviar( t.ficha ), { post: false, inerte: true }, 'dentro del plazo el doble toque no sale' );
		t.avanzar( 1 );
		assert.equal( 'inert' in t.ficha.atributos, false, 'quedaba inerte hasta recargar' );
		assert.equal( t.enviar( t.ficha ).post, true, 'el reintento sale' );
		assert.equal( t.ficha.atributos.inert, '' );
	}

	// Un plazo viejo no suelta un envío nuevo antes de hora: al volver con «Atrás» se
	// retira el que estaba armado, y el envío siguiente trae el suyo entero.
	const v = entornoToque();
	v.enviar( v.ficha );
	const [ plazo ] = v.plazos();
	v.avanzar( plazo / 2 );
	v.volver( true );
	assert.equal( v.enviar( v.ficha ).post, true );
	v.avanzar( plazo / 2 + 1 );
	assert.equal( v.ficha.atributos.inert, '', 'el plazo del primer envío soltaba el segundo a mitad de camino' );
	v.avanzar( plazo / 2 );
	assert.equal( 'inert' in v.ficha.atributos, false );
	assert.deepEqual( v.plazos(), [] );
} );

/*
 * REV-ZT1o-1 / REV-ZT1-D1 (revisión 1 del lote Z-T, 2026-09-25): el formulario volvía
 * con cualquier `navigateerror`, y Chromium lo dispara también con el POST de la
 * ficha aún de camino: por la navegación que el envío reemplaza (un enlace tocado
 * con la red lenta; llega en la misma tarea del submit y el `inert` se quitaba en el
 * mismo ms), por una navegación dentro del documento (un ancla, un replaceState: el
 * POST llegaba igual a los 8 s) y por el envío anterior que reemplaza un reintento.
 * El doble toque volvía a mandar dos altas (tres tras el plazo), medido en el espejo
 * en Chromium, escritorio y móvil; con la instantánea, una.
 */
test( 'REV-ZT1o-1: un `navigateerror` con el POST de camino no suelta el formulario: el doble toque sigue sin salir', () => {
	const t = entornoToque( { jquery: true, navegacion: true } );
	assert.equal( t.enviar( t.ficha ).post, true );
	const [ plazo ] = t.plazos();
	t.navegacionFallida(); // el enlace que el envío reemplaza, en la misma tarea
	assert.equal( t.ficha.atributos.inert, '', 'se quitaba en el mismo ms en que se ponía' );
	assert.deepEqual( t.enviar( t.ficha ), { post: false, inerte: true }, 'el doble toque mandaba la segunda alta' );
	t.avanzar( 800 );
	t.navegacionFallida(); // un ancla o un replaceState: el POST sigue y llega igual
	assert.deepEqual( t.enviar( t.ficha ), { post: false, inerte: true } );
	assert.deepEqual( t.plazos(), [ plazo ], 'el plazo sigue armado: no queda inerte para siempre' );

	// Vencido el plazo con el POST aún de camino, el reintento reemplaza al primero, y
	// el `navigateerror` de ése no deja sin protección al reintento.
	t.avanzar( plazo );
	assert.equal( t.enviar( t.ficha ).post, true );
	t.navegacionFallida();
	assert.deepEqual( t.enviar( t.ficha ), { post: false, inerte: true }, 'con el doble toque salía una tercera' );
} );

/*
 * VERIF-Z-1 (verificación previa al despliegue del 2026-09-26, sólo WebKit): si el
 * cliente detiene la carga con la página aún cargando y el POST de la ficha de camino,
 * WebKit deja de avisar a los oyentes registrados antes del stop, el de `submit` de
 * este guion incluido. El
 * plazo (un reloj, que sí sigue) devolvía el formulario, pero ya no había quien lo
 * volviera a poner inerte: el reintento en doble toque mandaba dos altas, 9 de 9 en
 * el espejo (escritorio, móvil y tableta, pausas de 150 y 600 ms). En Chromium el
 * oyente sigue vivo y volver a escuchar no lo duplica.
 */
test( 'VERIF-Z-1: detenida la carga en WebKit, el formulario vuelve protegido: el reintento en doble toque manda UN alta', () => {
	const t = entornoToque( { jquery: true } );
	assert.equal( t.enviar( t.ficha ).post, true );
	const [ plazo ] = t.plazos();
	t.detenerWebKit();
	assert.deepEqual( t.enviar( t.ficha ), { post: false, inerte: true }, 'dentro del plazo sigue inerte' );
	t.avanzar( plazo );
	assert.equal( 'inert' in t.ficha.atributos, false, 'el plazo lo devuelve igual: es un reloj' );
	assert.equal( t.enviar( t.ficha ).post, true, 'el reintento sale' );
	assert.equal( t.ficha.atributos.inert, '', 'sin volver a escuchar, nadie lo ponía inerte' );
	assert.deepEqual( t.enviar( t.ficha ), { post: false, inerte: true }, 'el segundo toque era la segunda alta' );

	// Si lo vuelve a detener, vuelve a volver protegido.
	const [ plazo2 ] = t.plazos();
	t.detenerWebKit();
	t.avanzar( plazo2 );
	assert.equal( t.enviar( t.ficha ).post, true );
	assert.deepEqual( t.enviar( t.ficha ), { post: false, inerte: true } );

	// Donde el oyente sigue vivo (Chromium), volver a escuchar no lo duplica.
	const c = entornoToque( { jquery: true } );
	c.enviar( c.ficha );
	c.avanzar( c.plazos()[ 0 ] );
	assert.equal( c.oyentesDeEnvio(), 1 );
	assert.equal( c.enviar( c.ficha ).post, true );
	assert.deepEqual( c.enviar( c.ficha ), { post: false, inerte: true } );
} );

/*
 * REV-ZT2o-2 (revisión 2 del lote Z-T): el selector de la barra fija iba con `:has()`
 * en la MISMA lista que el «esperando» del alta por fetch. Un navegador sin `:has()`
 * (Chrome < 105, Safari < 15.4, Firefox < 121) descarta la lista entera, y con ella
 * la opacidad y el `pointer-events: none` del botón real y del de la barra mientras
 * pdp-ajax-atc.js tiene la petición en vuelo, que ya existían antes del lote.
 */
test( 'REV-ZT1o-2 y REV-ZT2o-2: con el formulario inerte, el botón de la ficha y el de la barra se ven esperando; el de la barra, en regla aparte', () => {
	const css = sinComentarios( leer( 'assets/css/woocommerce.css' ) );
	const regla = /([^{}]*form\.cart\[inert\]\s+\.single_add_to_cart_button[^{}]*)\{([^}]*)\}/.exec( css );
	assert.ok( regla, 'sin ninguna señal, el cliente que esperaba volvía a tocar' );
	assert.match( regla[ 1 ], /\.single_add_to_cart_button\.is-adding\s*,/ );
	assert.doesNotMatch( regla[ 1 ], /:has\(/, 'sin `:has()`, el navegador descartaba también el «esperando» del alta por fetch' );
	assert.match( regla[ 2 ], /opacity:\s*0\.6/ );
	const barra = /(?:^|\})\s*body:has\(form\.cart\[inert\]\)\s+\.akb-sticky-atc__btn\s*\{([^}]*)\}/.exec( css );
	assert.ok( barra, 'y el de la barra fija, que manda el mismo formulario, en una regla sólo suya' );
	assert.match( barra[ 1 ], /opacity:\s*0\.6/ );
	assert.match( barra[ 1 ], /pointer-events:\s*none/ );
	// En la copia que se sirve en la ficha (woocommerce.css va partida por contexto).
	const ficha = leer( 'assets/css/woocommerce-ficha.min.css' );
	assert.match( ficha, /form\.cart\[inert\] \.single_add_to_cart_button[,{]/ );
	assert.match( ficha, /(?:^|\})body:has\(form\.cart\[inert\]\) \.akb-sticky-atc__btn\{/ );
} );

/**
 * Las listas de selectores de una hoja que mezclan alternativas con `:has()` y sin él.
 * Un `:has()` dentro de `:is()` o `:where()` no cuenta: esas listas toleran la
 * alternativa que el navegador no entiende y sólo descartan ésa (loop.css lo usa a
 * propósito). Dentro de `:not()`, sí: `:not()` no la tolera.
 */
function listasMixtasConHas( css ) {
	// Quita los grupos `:is( … )` y `:where( … )`, con sus paréntesis anidados.
	const sinTolerantes = ( sel ) => {
		let fuera = sel;
		for ( let m; ( m = /:(?:is|where)\(/.exec( fuera ) ); ) {
			let nivel = 0;
			let fin = m.index + m[ 0 ].length - 1;
			for ( ; fin < fuera.length; fin++ ) {
				nivel += '(' === fuera[ fin ] ? 1 : ')' === fuera[ fin ] ? -1 : 0;
				if ( 0 === nivel ) {
					break;
				}
			}
			fuera = fuera.slice( 0, m.index ) + fuera.slice( fin + 1 );
		}
		return fuera;
	};
	// Parte la lista por las comas de primer nivel (no las de `:is(a, b)` ni las de `[x="a,b"]`).
	const alternativas = ( lista ) => {
		const partes = [ '' ];
		let nivel = 0;
		let comilla = '';
		for ( const c of lista ) {
			if ( comilla ) {
				comilla = c === comilla ? '' : comilla;
			} else if ( '"' === c || "'" === c ) {
				comilla = c;
			} else if ( '(' === c || '[' === c ) {
				nivel++;
			} else if ( ')' === c || ']' === c ) {
				nivel--;
			} else if ( ',' === c && 0 === nivel ) {
				partes.push( '' );
				continue;
			}
			partes[ partes.length - 1 ] += c;
		}
		return partes;
	};
	const mixtas = [];
	for ( const [ , previo ] of sinComentarios( css ).matchAll( /([^{};]+)\{/g ) ) {
		const lista = previo.trim();
		if ( lista.startsWith( '@' ) ) {
			continue;
		}
		const conHas = alternativas( lista ).map( ( a ) => sinTolerantes( a ).includes( ':has(' ) );
		if ( conHas.includes( true ) && conHas.includes( false ) ) {
			mixtas.push( lista.replace( /\s+/g, ' ' ) );
		}
	}
	return mixtas;
}

test( 'REV-ZT2o-2: ninguna hoja del tema mete un `:has()` en una lista con alternativas que no lo usan', () => {
	// El detector ve el defecto, y no lo que no lo es.
	assert.deepEqual( listasMixtasConHas( '.a.is-adding,\nbody:has(form[inert]) .b { opacity: .6 }' ), [ '.a.is-adding, body:has(form[inert]) .b' ] );
	assert.deepEqual( listasMixtasConHas( '@media (max-height: 500px) { .a, .a:not(:has(.b)) { padding: 0 } }' ), [ '.a, .a:not(:has(.b))' ], 'dentro de `:not()` no se tolera' );
	assert.deepEqual( listasMixtasConHas( '.a:is(:hover, :has(:focus-visible)), .b { x: y }' ), [], '`:is()` tolera la alternativa que no entiende' );
	assert.deepEqual( listasMixtasConHas( 'body:has(.x) .a, body:has(.y) .a { x: y }' ), [], 'si todas lo usan, sin `:has()` no se pierde nada que no se perdiera igual' );
	assert.deepEqual( listasMixtasConHas( '[data-x="a,b"], .c { x: y }' ), [] );

	// Las fuentes y las copias que se sirven (el minificado no debe juntar reglas).
	const hojas = fs.readdirSync( path.join( raiz, 'assets/css' ) ).filter( ( f ) => f.endsWith( '.css' ) );
	assert.ok( hojas.includes( 'woocommerce-ficha.min.css' ) && hojas.includes( 'woocommerce.css' ) );
	const halladas = hojas.flatMap( ( f ) => listasMixtasConHas( leer( 'assets/css/' + f ) ).map( ( l ) => `${ f }: ${ l }` ) );
	assert.deepEqual( halladas, [], 'un navegador sin `:has()` descarta la lista entera, también las alternativas que no lo usan' );
} );

test( 'QAM-2: el captador va EN LÍNEA en el <head>, fuera de la optimización de LiteSpeed, con su copia servida', () => {
	const enqueue = leer( 'inc/enqueue.php' );
	assert.match( enqueue, /add_action\( 'wp_head', 'akibara_gp_print_toque_temprano', 2 \);/ );
	assert.match( enqueue, /akibara_gp_asset_servido\( 'assets\/js\/toque-temprano\.js' \)/ );
	assert.match( enqueue, /'data-no-optimize'\s*=> '1'/ );
	assert.match( enqueue, /'data-no-defer'\s*=> '1'/ );
	assert.match( sinComentarios( leer( 'assets/css/base.css' ) ), /\.akb-toque-pendiente\s*\{[^}]*opacity:/ );
} );

/* ── QAM-1: el foco vuelve sin desplazar la página ──────────────────── */

/**
 * Ejecuta el bloque real de main.js que vigila el foco de la cabecera, con una
 * ventana y un reloj de mentira.
 */
function entornoCabecera() {
	const main = leer( 'assets/js/main.js' );
	const i = main.indexOf( '/* ── El foco que cae en la cabecera fija no mueve la página (QAM-1)' );
	const j = main.indexOf( '/* ── Mobile drawer toggle', i );
	assert.ok( i > 0 && j > i, 'el bloque de la cabecera está en main.js' );
	const oyentes = {};
	const cabecera = { oyentes: {}, addEventListener( t, fn ) { this.oyentes[ t ] = fn; } };
	let ahora = 1000;
	const win = {
		pageXOffset: 0,
		pageYOffset: 1800,
		desplazamientos: [],
		addEventListener( t, fn ) { ( oyentes[ t ] = oyentes[ t ] || [] ).push( fn ); },
		scrollTo( x, y ) { this.desplazamientos.push( y ); this.pageYOffset = y; },
	};
	const ctx = vm.createContext( {
		doc: { querySelector: ( s ) => ( '.site-header' === s ? cabecera : null ) },
		window: win,
		Date: { now: () => ahora },
	} );
	vm.runInContext( main.slice( i, j ), ctx );
	const emitir = ( t ) => ( oyentes[ t ] || [] ).forEach( ( fn ) => fn( {} ) );
	return {
		win,
		/** El navegador desplaza a `y` y emite `scroll`. */
		desplazar( y ) { win.pageYOffset = y; emitir( 'scroll' ); },
		foco() { cabecera.oyentes.focusin( {} ); },
		gesto( t ) { emitir( t ); },
		pasar( ms ) { ahora += ms; },
	};
}

test( 'QAM-1: Chromium sube la página ANTES del focusin → se devuelve en el mismo focusin', () => {
	const c = entornoCabecera();
	c.win.pageYOffset = 1411; // focus() en el icono del carrito: ya subió 389 px
	c.foco();
	assert.equal( c.win.pageYOffset, 1800 );
} );

test( 'QAM-1: WebKit sube la página ~50 ms DESPUÉS del focusin → se devuelve en ese scroll', () => {
	const c = entornoCabecera();
	c.foco();
	assert.deepEqual( c.win.desplazamientos, [], 'nada que devolver todavía' );
	c.pasar( 50 );
	c.desplazar( 1733 );
	assert.equal( c.win.pageYOffset, 1800 );
} );

test( 'QAM-1: no le disputa al cliente su propio desplazamiento, ni uno hacia abajo, ni pasado el plazo', () => {
	const c = entornoCabecera();
	c.foco();
	c.gesto( 'wheel' );
	c.desplazar( 1500 );
	assert.equal( c.win.pageYOffset, 1500, 'la rueda termina la vigilancia' );

	const d = entornoCabecera();
	d.foco();
	d.desplazar( 1900 );
	assert.equal( d.win.pageYOffset, 1900, 'hacia abajo no es este fallo' );

	const e = entornoCabecera();
	e.foco();
	e.pasar( 500 );
	e.desplazar( 1200 );
	assert.equal( e.win.pageYOffset, 1200, 'pasados 400 ms ya no es el foco' );
	e.desplazar( 900 );
	assert.equal( e.win.pageYOffset, 900 );
} );

test( 'QAM-1: los focos propios hacia la cabecera y los campos a la vista van con preventScroll', () => {
	const main = leer( 'assets/js/main.js' );
	assert.match( main, /input\.focus\(\{ preventScroll: true \}\); \/\/ en la cabecera fija/, '⌘K subía la página 978 px' );
	assert.match( main, /clearBtn\.addEventListener\('click'[\s\S]*?input\.focus\(\{ preventScroll: true \}\);/ );
} );

test( 'QAM-1: el Escape del mega-menú devuelve el foco al botón de la cabecera sin desplazar', () => {
	const main = leer( 'assets/js/main.js' );
	assert.match( main, /if \(backTo\) backTo\.focus\(\{ preventScroll: true \}\);/ );
	assert.doesNotMatch( main, /backTo\.focus\(\);/ );
} );

/* ── QAM-5: la venta cruzada del carrito vacío usa el botón de compra ─ */

test( 'QAM-5: ya no hay variante de contorno para ul.products en el carrito (el clip-path la recortaba)', () => {
	const css = sinComentarios( leer( 'assets/css/woocommerce.css' ) );
	const reglas = [ ...css.matchAll( /([^{}]+)\{([^}]*)\}/g ) ].filter( ( m ) => /\.woocommerce-cart ul\.products li\.product \.(button|add_to_cart_button)/.test( m[ 1 ] ) );
	assert.ok( reglas.length > 0, 'la regla de tamaño se queda' );
	for ( const [ , , cuerpo ] of reglas ) {
		assert.doesNotMatch( cuerpo, /background:\s*transparent/, 'el contorno de 2 px quedaba cortado en dos trozos' );
		assert.doesNotMatch( cuerpo, /border:\s*2px solid/ );
	}
} );

/* ── QA-ESC-TECLADO-2: puente entre la fila y el panel ──────────────── */

test( 'QA-ESC-TECLADO-2: el ítem con submenú tiende un puente sobre el hueco del panel, activo sólo con el panel a la vista', () => {
	const css = sinComentarios( leer( 'assets/css/header.css' ) );
	const hueco = css.match( /\.mainnav__sub \{[^}]*top:\s*calc\(100% \+ (\d+)px\)/ );
	assert.ok( hueco, 'el panel sigue separado de la fila' );
	const puente = css.match( /\.mainnav__item--has-sub::after \{([^}]*)\}/ );
	assert.ok( puente, 'sin puente, con «reducir movimiento» el panel se cerraba al bajar el ratón' );
	assert.match( puente[ 1 ], /top:\s*100%/ );
	assert.match( puente[ 1 ], new RegExp( `height:\\s*${ hueco[ 1 ] }px` ), 'mide lo mismo que el hueco' );
	assert.match( puente[ 1 ], /pointer-events:\s*none/, 'cerrado, no abre nada' );
	assert.match( css, /\.mainnav__item--has-sub:hover::after,\s*\.mainnav__item--has-sub\.mainnav__flyout--open::after \{\s*pointer-events:\s*auto;/ );
	assert.match( css, /\.mainnav__item--has-sub\.mainnav__flyout--closed::after \{\s*pointer-events:\s*none;/ );
	assert.match( leer( 'assets/css/core.css' ), /\.mainnav__item--has-sub::after \{/, 'core.css (lo servido) regenerado' );
} );

/*
 * Revisión RQF2-1: el puente medía el ítem (o 220 px) desde su borde izquierdo;
 * el panel de Editoriales (`--auto`) está centrado y mide hasta 880 px + padding.
 * El puente repite la geometría de cada variante de panel, con su borde.
 */
test( 'RQF2-1: el puente cubre el ancho de cada variante de panel (normal, centrada y de columnas fijas)', () => {
	const css = sinComentarios( leer( 'assets/css/header.css' ) );
	const regla = ( sel ) => {
		const m = css.match( new RegExp( sel.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + ' \\{([^}]*)\\}' ) );
		assert.ok( m, `falta la regla ${ sel }` );
		return m[ 1 ];
	};
	const panel = regla( '.mainnav__sub' );
	const minimo = panel.match( /min-width:\s*(\d+)px/ )[ 1 ];
	assert.match( regla( '.mainnav__item--has-sub::after' ), new RegExp( `min-width:\\s*calc\\(${ minimo }px \\+ 2px\\)` ), 'el min-width del panel más su borde' );

	const auto = regla( '.mainnav__sub--auto' );
	const tope = auto.match( /max-width:\s*min\((\d+)px,\s*calc\(100vw - (\d+)px\)\)/ );
	assert.ok( tope, 'el panel centrado sigue con su tope' );
	assert.match( auto, /left:\s*50%/ );
	const puenteAuto = regla( '.mainnav__item--has-sub:has(> .mainnav__sub--auto)::after' );
	assert.match( puenteAuto, /left:\s*50%/ );
	assert.match( puenteAuto, /transform:\s*translateX\(-50%\)/, 'centrado en el ítem, como el panel' );
	assert.match( puenteAuto, new RegExp( `width:\\s*calc\\(min\\(${ tope[ 1 ] }px, 100vw - ${ tope[ 2 ] }px\\) \\+ 2 \\* var\\(--space-4\\) \\+ 2px\\)` ),
		'el máximo del panel + su padding lateral + su borde' );

	for ( const n of [ 2, 3, 4 ] ) {
		assert.match( css, new RegExp( `\\.mainnav__sub--cols-${ n } \\{ grid-template-columns: repeat\\(${ n }, minmax\\(160px, 1fr\\)\\); \\}` ) );
		assert.match( regla( `.mainnav__item--has-sub:has(> .mainnav__sub--cols-${ n })::after` ),
			new RegExp( `min-width:\\s*calc\\(${ n } \\* 160px \\+ ${ n + 1 } \\* var\\(--space-4\\) \\+ 2px\\)` ) );
	}
	assert.match( leer( 'assets/css/core.css' ), /\.mainnav__item--has-sub:has\(> \.mainnav__sub--auto\)::after \{/, 'core.css (lo servido) regenerado' );
} );

test( 'RQF2-1: un panel que se va (sin :hover ni abierto por JS) no atrapa el puntero mientras se funde; sin JS no se toca', () => {
	const css = sinComentarios( leer( 'assets/css/header.css' ) );
	assert.match( css, /\.mainnav--js \.mainnav__item--has-sub:not\(:hover\):not\(\.mainnav__flyout--open\) > \.mainnav__sub \{\s*pointer-events:\s*none;\s*\}/,
		'en los 180 ms del fundido sube 8 px sobre la fila y se reabría al pasar el ratón camino de otro ítem' );
	assert.match( css, /\.mainnav__sub \{[^}]*transform:\s*translateY\(-8px\)/, 'la razón: el panel cerrado sube 8 px' );
} );

test( 'RQF2-1: lo que el puente no cubre (salir del ítem por el costado) lo cubre la gracia de cierre de main.js, sólo en la fila', () => {
	const main = leer( 'assets/js/main.js' );
	assert.match( main, /var GRACIA_MS = \d+;/ );
	assert.match( main, /var conGracia = item\.classList\.contains\('mainnav__item--has-sub'\);/ );
	assert.match( main, /cierreDiferido = window\.setTimeout\(function \(\) \{[\s\S]*?item\.matches\(':hover'\)[\s\S]*?\}, GRACIA_MS\);/ );
} );

/* ── QA-ESC-TECLADO-4: salto a los filtros ──────────────────────────── */

test( 'QA-ESC-TECLADO-4: «Saltar a los filtros» va en la fila del total, apunta al formulario y main.js lo enfoca', () => {
	const php = leer( 'inc/flyout-button.php' );
	assert.match( php, /<a class="akb-skip-filtros" href="#akb-facets-drawer">Saltar a los filtros<\/a>/ );
	assert.match( php, /add_action\( 'woocommerce_before_shop_loop', 'akibara_gp_render_skip_to_filters', 6 \);/, 'después del total (5), antes del orden (30)' );
	assert.match( php, /add_action\( 'woocommerce_no_products_found', 'akibara_gp_render_skip_to_filters', 12 \);/ );
	assert.match( leer( 'assets/js/main.js' ), /closest\('a\.skip-link, a\.akb-skip-filtros'\)/ );
	const css = sinComentarios( leer( 'assets/css/woocommerce.css' ) );
	assert.match( css, /\.akb-skip-filtros \{[^}]*clip-path:\s*inset\(50%\)/, 'invisible hasta el foco' );
	assert.match( css, /@media \(max-width: 768px\) \{\s*\.akb-skip-filtros \{\s*display:\s*none;/, 'en el teléfono no existe' );
	assert.match( css, /\.akb-shop-toolbar \.akb-skip-filtros:focus \{[^}]*top:\s*0;[^}]*left:\s*0;/, 'con foco, encima del total: no mueve la fila' );
} );

/* ── QA-ESC-TECLADO-5: orden de Tab de la tarjeta ───────────────────── */

test( 'QA-ESC-TECLADO-5: en escritorio el corazón va encima del ojo, los dos abajo a la derecha (orden de Tab = visual)', () => {
	const css = sinComentarios( leer( 'assets/css/loop.css' ) );
	const bloque = css.match( /@media \(min-width: 769px\) \{\s*@supports selector\(:has\(\*\)\) \{([\s\S]*?)\n    \}\n\}/ );
	assert.ok( bloque, 'sin :has() se conserva la disposición de antes (no se pisan)' );
	assert.match( bloque[ 1 ], /li\.product:has\(> \.akb-qv-trigger\) > \.akb-qv-trigger \{[^}]*top:\s*0;[^}]*margin-top:\s*calc\(150% - 44px - var\(--space-2\)\);/ );
	assert.match( bloque[ 1 ], /li\.product:has\(> \.akb-qv-trigger\) \.akb-wl-toggle--loop \{[^}]*bottom:\s*calc\(44px \+ 2 \* var\(--space-2\)\);/ );
} );

/* ── QA-ESC-TECLADO-6: nombre del buscador = lo que se ve ───────────── */

test( 'QA-ESC-TECLADO-6: la etiqueta del buscador de la cabecera sale de la misma option que la pista', () => {
	const shell = leer( 'template-parts/header/shell.php' );
	assert.match( shell, /<label class="screen-reader-text" for="akb-search-input"><\?php echo esc_html\( akibara_gp_search_label\(\) \); \?><\/label>/ );
	assert.doesNotMatch( shell, /for="akb-search-input">Buscar productos</, 'decía «Buscar productos» con la pista «Buscar manga, series o autor…»' );
} );

/* ── QA-ESC-TECLADO-9: ventana baja ─────────────────────────────────── */

test( 'QA-ESC-TECLADO-9: con ≤ 500 px de alto se retiran el bottom-nav y la barra de compra, y lo que dependía de ellos', () => {
	const pie = sinComentarios( leer( 'assets/css/footer.css' ) );
	const bajo = pie.match( /@media \(max-width: 1024px\) and \(max-height: 500px\) \{([\s\S]*?)\n\}/ );
	assert.ok( bajo );
	assert.match( bajo[ 1 ], /\.bottom-nav \{\s*display:\s*none;/ );
	assert.match( bajo[ 1 ], /body \{\s*padding-bottom:\s*0;/ );
	assert.match( bajo[ 1 ], /html \{\s*scroll-padding-bottom:\s*var\(--space-2, 8px\);/ );
	assert.match( bajo[ 1 ], /html body \.joinchat,\s*html body\.single-product \.joinchat \{\s*bottom:\s*var\(--bottom, 20px\);/ );

	const wc = sinComentarios( leer( 'assets/css/woocommerce.css' ) );
	const ficha = wc.match( /@media \(max-height: 500px\) \{([\s\S]*?)\n\}/ );
	assert.ok( ficha );
	assert.match( ficha[ 1 ], /body\.single-product \.akb-sticky-atc \{\s*display:\s*none;/ );
	// Dos reglas: en una lista, un navegador sin `:has()` descartaba también la primera (REV-ZT2o-2).
	assert.match( ficha[ 1 ], /(?:^|\})\s*body\.single-product \{\s*padding-bottom:\s*0;/ );
	assert.match( ficha[ 1 ], /(?:^|\})\s*body\.single-product:not\(:has\(\.akb-sticky-atc\)\) \{\s*padding-bottom:\s*0;/ );
	// Va DESPUÉS de las reglas que la barra usa para mostrarse y reservar sitio.
	const bajoEn = wc.indexOf( '@media (max-height: 500px)' );
	for ( const regla of [ 'padding-bottom: max(var(--buy-bar-height', 'bottom: calc(var(--buy-bar-height', 'bottom: calc(var(--franja-inferior-peor' ] ) {
		assert.ok( bajoEn > wc.lastIndexOf( regla ), `la ventana baja tiene que ganarle en orden a «${ regla }»` );
	}
} );

/* ── QA-ESC-TECLADO-11 lo cubre WooCommerceHooksTest.php (PHP). ──────── */

/* ── QF-gp-R1: la caja de la paginación va en el hijo directo del <li> ─ */

test( 'QF-gp-R1: la caja de la paginación no alcanza a la flecha ni al nombre oculto que van dentro del enlace', () => {
	const pagina = ( css ) => [ ...sinComentarios( css ).matchAll( /([^{}]+)\{([^}]*)\}/g ) ]
		.flatMap( ( [ , selector, cuerpo ] ) => selector.split( /,(?![^(]*\))/ ).map( ( s ) => ( { s: s.trim(), cuerpo } ) ) )
		.filter( ( { s } ) => /\.woocommerce-pagination ul li\b/.test( s ) );

	const fuente = pagina( leer( 'assets/css/woocommerce.css' ) );
	assert.ok( fuente.length >= 8, 'las reglas de la paginación siguen ahí' );
	for ( const { s } of fuente ) {
		// `ul li span` sin `>` también pinta los <span> del enlace: una caja
		// dentro de otra, «→» a 100 px en vez de 72. (`ul li a` no tiene ese
		// problema: dentro del <li> no hay otro enlace.)
		assert.doesNotMatch( s, /ul li span\b/, `«${ s }»: sin «>» alcanza a los <span> interiores` );
	}
	const caja = fuente.filter( ( { cuerpo } ) => /border:\s*2px solid/.test( cuerpo ) ).map( ( { s } ) => s );
	assert.deepEqual( caja, [ '.woocommerce nav.woocommerce-pagination ul li > a', '.woocommerce nav.woocommerce-pagination ul li > span' ] );

	// WooCommerce les pone relleno y ancho mínimo con su propio `ul li span`: se devuelven a texto a secas.
	const interior = fuente.find( ( { s } ) => '.woocommerce nav.woocommerce-pagination ul li > :is(a, span) > span' === s );
	assert.ok( interior, 'sin esto la flecha llevaba el relleno de .5em de WooCommerce' );
	assert.match( interior.cuerpo, /min-width:\s*0;/ );
	assert.match( interior.cuerpo, /padding:\s*0;/ );

	// Lo servido (el listado lleva la paginación) está regenerado.
	const servido = leer( 'assets/css/woocommerce-listado.min.css' );
	assert.match( servido, /\.woocommerce nav\.woocommerce-pagination ul li>a,\.woocommerce nav\.woocommerce-pagination ul li>span\{display:inline-flex/ );
	assert.match( servido, /\.woocommerce nav\.woocommerce-pagination ul li>:is\(a,span\)>span\{min-width:0;padding:0\}/ );
	assert.doesNotMatch( servido, /woocommerce-pagination ul li span\b/ );
} );
