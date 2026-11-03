'use strict';

/*
 * Lote T5 de la auditoría del tema (2026-09-22): teclado y validación.
 *
 * - AI-4: el primer Escape en la hoja de búsqueda móvil sólo cierra la lista de
 *   sugerencias (el combobox del plugin hace preventDefault); el cajón no se
 *   cierra con un Escape ya consumido.
 * - AI-12: sin atajo de UNA tecla («/», WCAG 2.1.4); ⌘K/Ctrl+K sigue.
 * - AI-8: el menú de escritorio es «disclosure» (enlace + botón), sin apertura
 *   por foco.
 * - AI-5: el formulario de contacto no sale al servidor con campos inválidos y
 *   marca cada campo (aria-invalid + mensaje enlazado por aria-describedby).
 *
 * Los bloques reales de main.js y akibara-forms.js se ejecutan en un `vm` con un
 * DOM mínimo de mentira: lo que se afirma es el comportamiento, no el texto.
 * El recorrido en navegador real está en tools/akibara-e2e/specs/tema-accesibilidad-teclado.spec.mjs.
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const raiz = path.resolve( __dirname, '..' );
const leer = ( rel ) => fs.readFileSync( path.join( raiz, rel ), 'utf8' );
const main = leer( 'assets/js/main.js' );
const sinComentarios = ( css ) => css.replace( /\/\*[\s\S]*?\*\//g, '' );

function tramo( desde, hasta ) {
	const i = main.indexOf( desde );
	const j = main.indexOf( hasta, i + 1 );
	assert.ok( i >= 0 && j > i, `no se encontró el bloque «${ desde }» en main.js` );
	return main.slice( i, j );
}

/* ── AI-4: Escape ─────────────────────────────────────────────────────── */

function entornoEscape( { cajonAbierto } ) {
	const oyentes = [];
	let cierres = 0;
	const ctx = vm.createContext( {
		doc: {
			activeElement: { closest: () => null },
			addEventListener: ( tipo, fn ) => { if ( 'keydown' === tipo ) oyentes.push( fn ); },
		},
		drawer: { classList: { contains: ( c ) => 'open' === c && cajonAbierto } },
		closeDrawer: () => { cierres++; },
		FLYOUT_ITEMS: '.mainnav__item--has-sub, .mainnav__sub-item--has-sub',
		desktopFlyouts: [],
		isFlyoutOpen: () => false,
		focusInPanel: () => false,
		closeFlyout: () => {},
		getFlyoutToggle: () => null,
		getFlyoutLink: () => null,
	} );
	vm.runInContext( tramo( '/* ── ESC: cierra el panel del menú o el cajón', '/* ── Contador de filtros' ), ctx );
	assert.equal( oyentes.length, 1 );
	return {
		pulsar( evento ) {
			const e = Object.assign( { key: 'Escape', defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } }, evento );
			oyentes[ 0 ]( e );
		},
		cierres: () => cierres,
	};
}

test( 'AI-4: un Escape que el combobox ya consumió (preventDefault) no cierra la hoja', () => {
	const e = entornoEscape( { cajonAbierto: true } );
	e.pulsar( { defaultPrevented: true } );
	assert.equal( e.cierres(), 0, 'el primer Escape sólo cierra la lista de sugerencias' );
	e.pulsar( {} );
	assert.equal( e.cierres(), 1, 'el siguiente Escape, ya sin lista, sí cierra la hoja' );
} );

test( 'AI-4: otras teclas no cierran el cajón, ni Escape con el cajón cerrado', () => {
	const abierto = entornoEscape( { cajonAbierto: true } );
	abierto.pulsar( { key: 'Enter' } );
	assert.equal( abierto.cierres(), 0 );
	const cerrado = entornoEscape( { cajonAbierto: false } );
	cerrado.pulsar( {} );
	assert.equal( cerrado.cierres(), 0 );
} );

/* ── AI-12: atajo ─────────────────────────────────────────────────────── */

function entornoAtajo( activo = { tagName: 'BODY' } ) {
	const oyentes = [];
	const campo = {
		offsetParent: {},
		enfocado: 0,
		atributos: {},
		focus() { this.enfocado++; },
		select() {},
		setAttribute( k, v ) { this.atributos[ k ] = v; },
	};
	const ctx = vm.createContext( {
		doc: {
			activeElement: activo,
			addEventListener: ( tipo, fn ) => { if ( 'keydown' === tipo ) oyentes.push( fn ); },
			querySelector: ( s ) => ( '#akb-search-input' === s ? campo : null ),
			getElementById: ( id ) => ( 'akb-search-input' === id ? campo : null ),
		},
	} );
	vm.runInContext( tramo( '/* Atajo de teclado', '/* ── Header icon feedback' ), ctx );
	return {
		campo,
		pulsar( tecla ) {
			let prevenido = false;
			oyentes.forEach( ( fn ) => fn( Object.assign( { metaKey: false, ctrlKey: false, altKey: false, shiftKey: false, preventDefault() { prevenido = true; } }, tecla ) ) );
			return prevenido;
		},
	};
}

test( 'AI-12: «/» ya no es un atajo (WCAG 2.1.4: una sola tecla, sin forma de apagarla)', () => {
	const e = entornoAtajo();
	assert.equal( e.pulsar( { key: '/' } ), false, '«/» debe llegar a la página tal cual' );
	assert.equal( e.campo.enfocado, 0 );
} );

test( 'AI-12: ⌘K / Ctrl+K sigue llevando al buscador y se anuncia con aria-keyshortcuts', () => {
	const e = entornoAtajo();
	assert.equal( e.pulsar( { key: 'k', ctrlKey: true } ), true );
	assert.equal( e.pulsar( { key: 'K', metaKey: true } ), true );
	assert.equal( e.campo.enfocado, 2 );
	assert.equal( e.campo.atributos[ 'aria-keyshortcuts' ], 'Control+K Meta+K' );
} );

test( 'AI-12: dentro de un campo editable (también un <select>) el atajo no actúa', () => {
	for ( const activo of [ { tagName: 'INPUT' }, { tagName: 'TEXTAREA' }, { tagName: 'SELECT' }, { tagName: 'DIV', isContentEditable: true } ] ) {
		const e = entornoAtajo( activo );
		assert.equal( e.pulsar( { key: 'k', ctrlKey: true } ), false, activo.tagName );
		assert.equal( e.campo.enfocado, 0, activo.tagName );
	}
} );

/* ── AI-8: menú de escritorio como «disclosure» ───────────────────────── */

test( 'AI-8: el foco ya no abre los paneles (ni por JS ni por CSS con JS activo)', () => {
	const nav = tramo( '/* ── Desktop nav: submenús como «disclosure»', '/* ── ESC: cierra el panel' );
	assert.doesNotMatch( nav, /addEventListener\('focusin'/, 'focusin abría el panel en cada padre al tabular' );
	assert.match( nav, /mainnav--js/ );
	assert.match( nav, /toggle\.addEventListener\('click'/, 'el panel lo abre el botón' );
	assert.match( nav, /pointerType === 'touch'/, 'el hover se filtra del toque: el mouseenter de compatibilidad lo cerraba' );

	const css = sinComentarios( leer( 'assets/css/header.css' ) );
	const conFoco = css.match( /[^{}]*:focus-within[^{]*\{/g ).filter( ( r ) => /mainnav/.test( r ) );
	assert.ok( conFoco.length >= 2 );
	for ( const regla of conFoco ) {
		for ( const selector of regla.replace( '{', '' ).split( ',' ) ) {
			if ( /mainnav/.test( selector ) && /:focus-within/.test( selector ) ) {
				assert.match( selector, /\.mainnav:not\(\.mainnav--js\)/, `con JS, :focus-within no debe abrir el panel: ${ selector.trim() }` );
			}
		}
	}
	assert.doesNotMatch( css + main, /touch-open/, 'la clase vieja no queda en ninguna parte (cero código muerto)' );
	assert.match( leer( 'assets/css/core.css' ), /\.mainnav__toggle\s*\{/, 'core.css es lo que se sirve: tiene que estar regenerado' );
} );

/*
 * T5-R1 / T5-R2 (revisión independiente del lote T5, 2026-09-23). Se ejecutan
 * los bloques REALES del menú y del Escape sobre un DOM de mentira con lo justo:
 * clases, atributos, closest/matches y un puntero que decide `:hover` (como en
 * CSS, el ítem está en :hover si el puntero está sobre él o sobre un hijo).
 */
function entornoMenu( { conBotones = true } = {} ) {
	const doc = { activeElement: null, oyentes: {}, puntero: null };
	const pendientes = [];
	let reloj = [];
	let ahora = 0;
	let ultimoId = 0;
	const coincide = ( el, sel ) => sel.split( ',' ).map( ( s ) => s.trim() ).some( ( s ) => {
		if ( ':hover' === s ) {
			return !! doc.puntero && ( el === doc.puntero || el.contains( doc.puntero ) );
		}
		return s.split( '.' ).filter( Boolean ).every( ( c ) => el.clases.has( c ) );
	} );
	class Nodo {
		constructor( clases, padre = null, nombre = '' ) {
			this.clases = new Set( clases.split( ' ' ).filter( Boolean ) );
			this.atributos = {};
			this.children = [];
			this.oyentes = {};
			this.nombre = nombre;
			this.parentElement = padre;
			if ( padre ) padre.children.push( this );
			const yo = this;
			this.classList = {
				contains: ( c ) => yo.clases.has( c ),
				add: ( c ) => yo.clases.add( c ),
				remove: ( c ) => yo.clases.delete( c ),
				toggle: ( c, si ) => ( si ? yo.clases.add( c ) : yo.clases.delete( c ) ),
			};
		}
		setAttribute( k, v ) { this.atributos[ k ] = String( v ); }
		getAttribute( k ) { return k in this.atributos ? this.atributos[ k ] : null; }
		hasAttribute( k ) { return k in this.atributos; }
		removeAttribute( k ) { delete this.atributos[ k ]; }
		getBoundingClientRect() { return this.caja || { top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 }; }
		contains( el ) { for ( let n = el; n; n = n.parentElement ) { if ( n === this ) return true; } return false; }
		matches( sel ) { return coincide( this, sel ); }
		closest( sel ) { for ( let n = this; n; n = n.parentElement ) { if ( coincide( n, sel ) ) return n; } return null; }
		querySelectorAll( sel ) {
			const out = [];
			const recorrer = ( n ) => n.children.forEach( ( h ) => { if ( coincide( h, sel ) ) out.push( h ); recorrer( h ); } );
			recorrer( this );
			return out;
		}
		querySelector( sel ) { return this.querySelectorAll( sel )[ 0 ] || null; }
		addEventListener( tipo, fn ) { ( this.oyentes[ tipo ] = this.oyentes[ tipo ] || [] ).push( fn ); }
		disparar( tipo, evento = {} ) { ( this.oyentes[ tipo ] || [] ).forEach( ( fn ) => fn( evento ) ); }
		focus() { doc.activeElement = this; }
	}

	// Inicio · Manga (enlace + botón + panel con un subítem de 3.er nivel) · Series
	const raiz = new Nodo( '' );
	const nav = new Nodo( 'mainnav', raiz );
	const inicio = new Nodo( 'mainnav__link', nav, 'Inicio' );
	const manga = new Nodo( 'mainnav__item mainnav__item--has-sub', nav, 'Manga' );
	const enlaceManga = new Nodo( 'mainnav__link', manga );
	const botonManga = conBotones ? new Nodo( 'mainnav__toggle', manga ) : null;
	const panel = new Nodo( 'mainnav__sub', manga );
	// Geometría del espejo a 1440: fila de 44 px (y 128-172) y el panel centrado,
	// más ancho que su ítem, 4 px por debajo (el hueco que cubre el puente).
	panel.caja = { top: 176, bottom: 476, left: 200, right: 1000, width: 800, height: 300 };
	const verTodo = new Nodo( 'mainnav__sublink mainnav__sublink--parent', panel );
	const sub = new Nodo( 'mainnav__sub-item mainnav__sub-item--has-sub', panel, 'Argentinas' );
	const enlaceSub = new Nodo( 'mainnav__sublink', sub );
	const botonSub = conBotones ? new Nodo( 'mainnav__toggle mainnav__toggle--sub', sub ) : null;
	const subsub = new Nodo( 'mainnav__subsub', sub );
	const marca = new Nodo( 'mainnav__subsublink', subsub );
	const series = new Nodo( 'mainnav__link', nav, 'Series' );
	const comics = new Nodo( 'mainnav__item mainnav__item--has-sub', nav, 'Cómics' );
	const enlaceComics = new Nodo( 'mainnav__link', comics );
	const botonComics = conBotones ? new Nodo( 'mainnav__toggle', comics ) : null;
	new Nodo( 'mainnav__sub', comics );
	[ botonManga, botonSub, botonComics ].forEach( ( b ) => b && b.setAttribute( 'aria-expanded', 'false' ) );

	let cierresCajon = 0;
	const ctx = vm.createContext( {
		doc: Object.assign( doc, {
			querySelector: ( s ) => raiz.querySelector( s ),
			querySelectorAll: ( s ) => raiz.querySelectorAll( s ),
			addEventListener: ( tipo, fn ) => { ( doc.oyentes[ tipo ] = doc.oyentes[ tipo ] || [] ).push( fn ); },
		} ),
		// Temporizadores de `window` con reloj de mentira: la gracia de cierre
		// de la fila (GRACIA_MS, RQF2-1) corre sólo cuando la prueba pasa el tiempo.
		window: {
			innerWidth: 1440,
			setTimeout: ( fn, ms ) => { reloj.push( { fn, en: ahora + ( ms || 0 ), id: ++ultimoId } ); return ultimoId; },
			clearTimeout: ( id ) => { reloj = reloj.filter( ( t ) => t.id !== id ); },
		},
		setTimeout: ( fn ) => { pendientes.push( fn ); },
		drawer: null,
		closeDrawer: () => { cierresCajon++; },
	} );
	vm.runInContext( tramo( '/* ── Desktop nav: submenús como «disclosure»', '/* ── Contador de filtros' ), ctx );

	const abierto = ( item ) => item.clases.has( 'mainnav__flyout--open' ) && ! item.clases.has( 'mainnav__flyout--closed' );
	return {
		doc, nav, inicio, manga, enlaceManga, botonManga, verTodo, sub, enlaceSub, botonSub, marca, series, comics, enlaceComics, botonComics,
		abierto,
		/** Lo que se ve: abierto por JS o por :hover, y no cerrado a pedido (header.css). */
		visible: ( item ) => ! item.clases.has( 'mainnav__flyout--closed' ) && ( item.clases.has( 'mainnav__flyout--open' ) || item.matches( ':hover' ) ),
		cierresCajon: () => cierresCajon,
		/** Pasa `ms` del reloj de `window` y corre lo que venció. */
		pasa( ms ) {
			ahora += ms;
			const vencidos = reloj.filter( ( t ) => t.en <= ahora );
			reloj = reloj.filter( ( t ) => t.en > ahora );
			vencidos.forEach( ( t ) => t.fn() );
		},
		puntero( el ) { doc.puntero = el; },
		/**
		 * El puntero sale de `item`: su último punto dentro y el primero fuera
		 * (clientX/Y). El ítem de Manga ocupa x 560-680 de la fila (y 128-172).
		 */
		salir( item, desde, hasta, pointerType = 'mouse' ) {
			item.disparar( 'pointermove', { pointerType, clientX: desde[ 0 ], clientY: desde[ 1 ] } );
			item.disparar( 'pointerleave', { pointerType, clientX: hasta[ 0 ], clientY: hasta[ 1 ] } );
		},
		foco( el ) { doc.activeElement = el; },
		/** El foco sale de `desde` hacia `hacia` (focusout burbujea por los ítems antepasados). */
		mover( desde, hacia ) {
			doc.activeElement = hacia;
			for ( let n = desde; n; n = n.parentElement ) n.disparar( 'focusout' );
			pendientes.splice( 0 ).forEach( ( fn ) => fn() );
		},
		escape() {
			const e = { key: 'Escape', defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
			( doc.oyentes.keydown || [] ).forEach( ( fn ) => fn( e ) );
			return e.defaultPrevented;
		},
	};
}

test( 'T5-R2: sin botones en el HTML (copia cacheada de antes) no se apaga el respaldo :focus-within', () => {
	assert.equal( entornoMenu().nav.clases.has( 'mainnav--js' ), true, 'con botones, el panel lo abre el botón' );
	assert.equal( entornoMenu( { conBotones: false } ).nav.clases.has( 'mainnav--js' ), false,
		'sin botones y con mainnav--js el teclado no tenía forma de abrir ningún submenú' );
} );

test( 'T5-R1: si el foco sale con el ratón encima, el panel sigue abierto de paso (aria-expanded dice lo que se ve)', () => {
	const m = entornoMenu();
	m.puntero( m.enlaceManga );
	m.manga.disparar( 'pointerenter', { pointerType: 'mouse' } );
	m.botonManga.focus();
	m.botonManga.disparar( 'click' );
	assert.equal( m.manga.hasAttribute( 'data-pinned' ), true, 'el botón lo fija' );

	m.mover( m.botonManga, m.series );
	assert.equal( m.visible( m.manga ), true, 'el :hover lo sigue mostrando' );
	assert.equal( m.botonManga.getAttribute( 'aria-expanded' ), 'true', 'antes quedaba en false con el panel a la vista' );
	assert.equal( m.manga.hasAttribute( 'data-pinned' ), false, 'ya no está fijado: lo cierra el pointerleave' );

	m.puntero( null );
	m.manga.disparar( 'pointerleave', { pointerType: 'mouse' } );
	m.pasa( 300 ); // la gracia de la fila (RQF2-1), si la salida apuntara al panel
	assert.equal( m.visible( m.manga ), false );
	assert.equal( m.botonManga.getAttribute( 'aria-expanded' ), 'false' );
} );

/*
 * Revisión R2-T5-C1: ratón quieto sobre Manga (panel abierto de paso) y, con el
 * teclado, se abre Cómics con su botón. closeUnrelatedFlyouts cerraba Manga sólo
 * en el JS: aria-expanded="false" y el :hover del CSS lo seguía pintando, encima
 * del de Cómics (medido en el espejo a 1440, Chromium y WebKit).
 */
test( 'R2-T5-C1: abrir otro panel con el teclado oculta el que está bajo el ratón, y el pointerleave lo libera', () => {
	const m = entornoMenu();
	m.puntero( m.enlaceManga );
	m.manga.disparar( 'pointerenter', { pointerType: 'mouse' } );
	assert.equal( m.visible( m.manga ), true );

	m.botonComics.focus();
	m.botonComics.disparar( 'click' );
	assert.equal( m.visible( m.comics ), true );
	assert.equal( m.botonComics.getAttribute( 'aria-expanded' ), 'true' );
	assert.equal( m.visible( m.manga ), false, 'antes seguía a la vista por el :hover, tapando al de Cómics' );
	assert.equal( m.botonManga.getAttribute( 'aria-expanded' ), 'false', 'y aria-expanded dice lo que se ve' );

	// El ratón sale de Manga: la marca se levanta y un hover nuevo lo vuelve a abrir.
	m.puntero( null );
	m.manga.disparar( 'pointerleave', { pointerType: 'mouse' } );
	assert.equal( m.manga.clases.has( 'mainnav__flyout--closed' ), false );
	m.puntero( m.enlaceManga );
	m.manga.disparar( 'pointerenter', { pointerType: 'mouse' } );
	assert.equal( m.visible( m.manga ), true );
} );

test( 'R2-T5-C1: sin ratón encima, el panel ajeno se cierra como antes (sin marca de «cerrado a pedido»)', () => {
	const m = entornoMenu();
	m.botonManga.focus();
	m.botonManga.disparar( 'click' );
	m.botonComics.focus();
	m.botonComics.disparar( 'click' );
	assert.equal( m.abierto( m.manga ), false );
	assert.equal( m.manga.clases.has( 'mainnav__flyout--closed' ), false, 'sin puntero no hay :hover que ganar' );
} );

test( 'T5-R1: sin ratón encima, el foco que sale cierra el panel como antes', () => {
	const m = entornoMenu();
	m.botonManga.focus();
	m.botonManga.disparar( 'click' );
	m.mover( m.botonManga, m.series );
	assert.equal( m.abierto( m.manga ), false );
	assert.equal( m.botonManga.getAttribute( 'aria-expanded' ), 'false' );
} );

test( 'T5-R1: Escape oculta el panel que se ve por :hover aunque el foco esté fuera del menú (WCAG 1.4.13)', () => {
	const m = entornoMenu();
	// Visible sólo por el CSS: el JS no lo marcó abierto (p. ej. el foco salió con el ratón encima).
	m.puntero( m.enlaceManga );
	m.foco( m.series );
	assert.equal( m.visible( m.manga ), true );
	assert.equal( m.escape(), true, 'el Escape es del menú' );
	assert.equal( m.visible( m.manga ), false, 'antes se quedaba a la vista' );
	assert.equal( m.doc.activeElement, m.series, 'el foco no se mueve si no estaba en el panel' );
	// Al salir el puntero se levanta el cierre a pedido: el hover vuelve a abrir.
	m.puntero( null );
	m.manga.disparar( 'pointerleave', { pointerType: 'mouse' } );
	m.puntero( m.enlaceManga );
	m.manga.disparar( 'pointerenter', { pointerType: 'mouse' } );
	assert.equal( m.visible( m.manga ), true );
} );

test( 'T5-R1: con el foco en un subítem de sub-submenú cerrado, Escape cierra el panel padre', () => {
	const m = entornoMenu();
	m.botonManga.focus();
	m.botonManga.disparar( 'click' );
	m.foco( m.enlaceSub );
	assert.equal( m.escape(), true );
	assert.equal( m.visible( m.manga ), false, 'antes el Escape se quedaba con el subítem cerrado y el panel fijado seguía abierto' );
	assert.equal( m.botonManga.getAttribute( 'aria-expanded' ), 'false' );
	assert.equal( m.doc.activeElement, m.botonManga, 'el foco estaba dentro del panel que se oculta: vuelve al botón' );
} );

test( 'T5-R1: de dentro hacia fuera — primero el sub-submenú, después el panel; uno cerrado a pedido no cuenta', () => {
	const m = entornoMenu();
	m.botonManga.focus();
	m.botonManga.disparar( 'click' );
	m.botonSub.focus();
	m.botonSub.disparar( 'click' );
	m.foco( m.marca );
	m.escape();
	assert.equal( m.visible( m.sub ), false );
	assert.equal( m.visible( m.manga ), true, 'un Escape cierra un nivel' );
	assert.equal( m.doc.activeElement, m.botonSub );

	// El subítem queda cerrado a pedido con el ratón encima: el siguiente Escape va al padre.
	m.puntero( m.enlaceSub );
	m.escape();
	assert.equal( m.visible( m.manga ), false );
	assert.equal( m.doc.activeElement, m.botonManga );
} );

test( 'T5-R1: con nada del menú a la vista, el Escape sigue siendo del cajón', () => {
	const m = entornoMenu();
	m.foco( m.inicio );
	assert.equal( m.escape(), false, 'no se consume un Escape que no cierra nada' );
} );

/*
 * Revisión RQF2-1: el panel de Editoriales está centrado y es mucho más ancho que
 * su ítem; el camino recto del enlace a su mitad izquierda sale del ítem por el
 * costado y pisa «A pedido» antes de llegar al panel. Con «reducir movimiento» el
 * pointerleave lo cerraba en el acto y no volvía (1100-1920 px, Chromium y WebKit).
 */
test( 'RQF2-1: al salir el puntero de un ítem de la fila el panel espera la gracia; si vuelve (puente o panel) sigue abierto', () => {
	const m = entornoMenu();
	m.puntero( m.enlaceManga );
	m.manga.disparar( 'pointerenter', { pointerType: 'mouse' } );
	assert.equal( m.visible( m.manga ), true );

	// Sale por el costado bajando hacia el panel: cruza un enlace vecino camino de él.
	m.puntero( m.inicio );
	m.salir( m.manga, [ 580, 158 ], [ 558, 164 ] );
	assert.equal( m.visible( m.manga ), true, 'antes se cerraba en el acto' );
	m.pasa( 150 );
	assert.equal( m.visible( m.manga ), true );

	// Llega al panel: vuelve a estar dentro del ítem y el cierre pendiente se anula.
	m.puntero( m.verTodo );
	m.manga.disparar( 'pointerenter', { pointerType: 'mouse' } );
	m.pasa( 1000 );
	assert.equal( m.abierto( m.manga ), true, 'el cierre de la primera salida no se lo lleva por delante' );

	// Sale otra vez hacia el panel pero no llega: se cierra al cumplirse la
	// gracia, y aria-expanded lo dice.
	m.puntero( null );
	m.salir( m.manga, [ 580, 158 ], [ 558, 164 ] );
	m.pasa( 299 );
	assert.equal( m.visible( m.manga ), true );
	m.pasa( 1 );
	assert.equal( m.visible( m.manga ), false );
	assert.equal( m.botonManga.getAttribute( 'aria-expanded' ), 'false' );
} );

test( 'RQF2-1: otro ítem con panel que recibe el puntero cierra el anterior EN EL ACTO (no conviven dos paneles)', () => {
	const m = entornoMenu();
	m.puntero( m.enlaceManga );
	m.manga.disparar( 'pointerenter', { pointerType: 'mouse' } );
	m.puntero( m.enlaceComics );
	m.manga.disparar( 'pointerleave', { pointerType: 'mouse' } );
	m.comics.disparar( 'pointerenter', { pointerType: 'mouse' } );
	assert.equal( m.visible( m.manga ), false );
	assert.equal( m.visible( m.comics ), true );
	m.pasa( 300 );
	assert.equal( m.visible( m.comics ), true, 'el cierre diferido de Manga no toca a Cómics' );
} );

test( 'RQF2-1: fijado con el botón o con el foco dentro durante la gracia, el temporizador no lo cierra', () => {
	const m = entornoMenu();
	m.puntero( m.enlaceManga );
	m.manga.disparar( 'pointerenter', { pointerType: 'mouse' } );
	m.puntero( m.series );
	m.salir( m.manga, [ 580, 158 ], [ 558, 164 ] );
	m.botonManga.focus();
	m.botonManga.disparar( 'click' );
	m.pasa( 300 );
	assert.equal( m.abierto( m.manga ), true );
	assert.equal( m.manga.hasAttribute( 'data-pinned' ), true );

	const f = entornoMenu();
	f.puntero( f.enlaceManga );
	f.manga.disparar( 'pointerenter', { pointerType: 'mouse' } );
	f.puntero( f.series );
	f.salir( f.manga, [ 580, 158 ], [ 558, 164 ] );
	f.foco( f.verTodo );
	f.pasa( 300 );
	assert.equal( f.abierto( f.manga ), true, 'el teclado está dentro del panel' );
} );

/*
 * Revisión RQF3-2: la gracia de RQF2-1 valía para CUALQUIER salida. El panel de
 * Editoriales (802 px, centrado) queda también debajo de «Series» y «A pedido»:
 * quien iba por la fila a «Series» y bajaba al contenido volvía a entrar en el
 * panel antes de los 300 ms, y el clic caía en el panel (medido en el espejo en
 * /tienda/ a 1440, Chromium y WebKit, con y sin «reducir movimiento»). Ahora
 * espera sólo la salida que, prolongada, entra en el panel por arriba.
 */
test( 'RQF3-2: salir por la fila, hacia arriba, desde el propio panel o sin apuntar al panel cierra en el acto', () => {
	for ( const [ caso, desde, hasta ] of [
		[ 'a lo largo de la fila, hacia «Series»', [ 670, 150 ], [ 682, 150 ] ],
		[ 'a lo largo de la fila, hacia la izquierda', [ 570, 150 ], [ 558, 150 ] ],
		[ 'hacia arriba', [ 620, 132 ], [ 620, 126 ] ],
		[ 'desde el panel, hacia abajo', [ 600, 470 ], [ 600, 478 ] ],
		[ 'desde el panel, por el costado', [ 995, 300 ], [ 1003, 302 ] ],
		[ 'bajando poco hacia la derecha: se pasa del borde del panel antes de llegar', [ 675, 160 ], [ 700, 161 ] ],
	] ) {
		const m = entornoMenu();
		m.puntero( m.enlaceManga );
		m.manga.disparar( 'pointerenter', { pointerType: 'mouse' } );
		m.puntero( m.series );
		m.salir( m.manga, desde, hasta );
		assert.equal( m.visible( m.manga ), false, `${ caso }: antes esperaba 300 ms y el panel se comía el clic` );
		assert.equal( m.botonManga.getAttribute( 'aria-expanded' ), 'false', caso );
	}

	// Sin un solo movimiento dentro del ítem no hay dirección que seguir: se cierra.
	const s = entornoMenu();
	s.puntero( s.enlaceManga );
	s.manga.disparar( 'pointerenter', { pointerType: 'mouse' } );
	s.puntero( null );
	s.manga.disparar( 'pointerleave', { pointerType: 'mouse', clientX: 558, clientY: 164 } );
	assert.equal( s.visible( s.manga ), false );
} );

test( 'RQF3-2: la dirección sale de varios pasos, no del último: 1 px de pulso no hace que una salida por la fila apunte al panel', () => {
	const m = entornoMenu();
	m.puntero( m.enlaceManga );
	m.manga.disparar( 'pointerenter', { pointerType: 'mouse' } );
	// Hacia «Series» en pasos de 10 px; el que sale baja 1 px (10 %, más que el 9 % que basta).
	for ( const x of [ 640, 650, 660, 670 ] ) {
		m.manga.disparar( 'pointermove', { pointerType: 'mouse', clientX: x, clientY: 150 } );
	}
	m.puntero( m.series );
	m.manga.disparar( 'pointerleave', { pointerType: 'mouse', clientX: 681, clientY: 151 } );
	assert.equal( m.visible( m.manga ), false, 'con sólo el último paso (670 → 681) apuntaba al panel' );

	// La diagonal hacia la mitad izquierda del panel (RQF2-1) sigue esperando.
	const d = entornoMenu();
	d.puntero( d.enlaceManga );
	d.manga.disparar( 'pointerenter', { pointerType: 'mouse' } );
	for ( const [ x, y ] of [ [ 620, 150 ], [ 600, 154 ], [ 580, 158 ], [ 562, 162 ], [ 561, 162 ] ] ) {
		d.manga.disparar( 'pointermove', { pointerType: 'mouse', clientX: x, clientY: y } );
	}
	d.puntero( d.inicio );
	d.manga.disparar( 'pointerleave', { pointerType: 'mouse', clientX: 558, clientY: 163 } );
	assert.equal( d.visible( d.manga ), true, 'un último paso casi plano (561 → 558) no le quita la gracia' );
	d.pasa( 300 );
	assert.equal( d.visible( d.manga ), false, 'y si no llega, se cierra a los 300 ms' );
} );

test( 'RQF2-1: la gracia es sólo de la fila; el sub-submenú de dentro del panel se cierra como antes', () => {
	const m = entornoMenu();
	m.puntero( m.enlaceSub );
	m.manga.disparar( 'pointerenter', { pointerType: 'mouse' } );
	m.sub.disparar( 'pointerenter', { pointerType: 'mouse' } );
	assert.equal( m.abierto( m.sub ), true );
	m.puntero( m.verTodo );
	m.sub.disparar( 'pointerleave', { pointerType: 'mouse' } );
	assert.equal( m.abierto( m.sub ), false );
	assert.equal( m.abierto( m.manga ), true );
} );

/* ── AI-5: formulario de contacto ─────────────────────────────────────── */

function entornoFormulario( { mensajes, idioma } = {} ) {
	const porId = new Map();
	const oyentes = {};
	const peticiones = [];
	let enfocado = null;

	class Elemento {
		constructor( props = {} ) {
			this.atributos = {};
			Object.assign( this, props );
			if ( this.id ) porId.set( this.id, this );
		}
		setAttribute( k, v ) { this.atributos[ k ] = String( v ); if ( 'id' === k ) { this.id = v; porId.set( v, this ); } }
		getAttribute( k ) { return k in this.atributos ? this.atributos[ k ] : null; }
		removeAttribute( k ) { delete this.atributos[ k ]; }
		remove() { porId.delete( this.id ); this.quitado = true; }
		insertAdjacentElement( _pos, el ) { porId.set( el.id, el ); return el; }
		focus() { enfocado = this; }
	}
	class HTMLFormElement extends Elemento {}

	const form = new HTMLFormElement( { dataset: { akbForm: 'contacto' } } );
	const campo = ( id, mensaje, motivo ) => new Elemento( {
		id, name: id.replace( 'akb-c-', '' ), form, willValidate: true, value: '', valido: false, validationMessage: mensaje,
		validity: { [ motivo ]: true },
		checkValidity() { return this.valido; },
	} );
	const campos = [ campo( 'akb-c-nombre', 'Completa este campo.', 'valueMissing' ), campo( 'akb-c-email', 'Incluye un signo «@».', 'typeMismatch' ), campo( 'akb-c-mensaje', 'Completa este campo.', 'valueMissing' ) ];
	const oculto = new Elemento( { name: 'action', willValidate: false, value: 'akb_contacto', form } );
	const aviso = new Elemento( { dataset: {}, textContent: '' } );
	const boton = new Elemento( { dataset: {}, disabled: false } );
	form.querySelector = ( s ) => ( '.akb-form__feedback' === s ? aviso : ( '.akb-form__submit' === s ? boton : null ) );
	form.querySelectorAll = () => [ oculto, ...campos ];
	form.matches = ( s ) => '[data-akb-form]' === s;
	form.reset = () => { form.vaciado = true; };

	const ctx = vm.createContext( {
		window: { AkibaraFormsConfig: Object.assign( { restUrl: '/wp-json/akibara/v1/', nonce: '' }, mensajes ? { messages: mensajes } : {} ) },
		...( idioma ? { navigator: { language: idioma } } : {} ),
		HTMLFormElement,
		FormData: class { constructor( f ) { this.f = f; } entries() { return f_entries( this.f ); } },
		fetch: async ( url, opts ) => { peticiones.push( { url, opts } ); return { ok: true, json: async () => ( { message: 'Recibido.' } ) }; },
		document: {
			querySelectorAll: ( s ) => ( '[data-akb-form]' === s ? [ form ] : [] ),
			addEventListener: ( tipo, fn ) => { oyentes[ tipo ] = fn; },
			getElementById: ( id ) => porId.get( id ) || null,
			createElement: () => new Elemento(),
		},
	} );
	function f_entries( f ) { return f.querySelectorAll().map( ( c ) => [ c.name, c.value ] ); }
	vm.runInContext( leer( 'assets/js/akibara-forms.js' ), ctx );

	return {
		form, campos, aviso, peticiones, porId,
		enfocado: () => enfocado,
		async enviar() {
			let prevenido = false;
			oyentes.submit( { target: form, preventDefault() { prevenido = true; } } );
			await new Promise( ( r ) => setImmediate( r ) );
			return prevenido;
		},
		escribir( c ) { oyentes.input( { target: c } ); },
	};
}

test( 'AI-5: con campos inválidos no hay petición; cada campo queda marcado y el foco va al primero', async () => {
	const e = entornoFormulario();
	assert.equal( e.form.atributos.novalidate, '', 'sigue apagando los globos nativos sólo si el script corre' );
	assert.equal( await e.enviar(), true, 'el envío nativo se intercepta igual' );
	assert.equal( e.peticiones.length, 0, 'un campo vacío no justifica un viaje al servidor' );
	assert.equal( e.enfocado(), e.campos[ 0 ] );
	for ( const c of e.campos ) {
		assert.equal( c.getAttribute( 'aria-invalid' ), 'true', c.id );
		assert.equal( c.getAttribute( 'aria-describedby' ), `${ c.id }-error`, c.id );
		const msg = e.porId.get( `${ c.id }-error` );
		assert.ok( msg, `falta el mensaje de ${ c.id }` );
		assert.equal( msg.textContent, c.validationMessage, 'el texto es el del navegador, el mismo que sin JS' );
		assert.equal( msg.className, 'akb-form__error' );
	}
} );

test( 'AI-5: al corregir un campo su error se va; con todo válido, se envía', async () => {
	const e = entornoFormulario();
	await e.enviar();
	const [ nombre, email, mensaje ] = e.campos;
	nombre.valido = true;
	e.escribir( nombre );
	assert.equal( nombre.getAttribute( 'aria-invalid' ), null );
	assert.equal( nombre.getAttribute( 'aria-describedby' ), null, 'no queda apuntando a un id que ya no existe' );
	assert.equal( e.porId.has( 'akb-c-nombre-error' ), false );
	assert.equal( email.getAttribute( 'aria-invalid' ), 'true', 'los demás siguen marcados' );

	// Otra vez inválido pero por otro motivo: el mensaje se actualiza, no se duplica.
	email.validationMessage = 'Falta el dominio.';
	e.escribir( email );
	assert.equal( e.porId.get( 'akb-c-email-error' ).textContent, 'Falta el dominio.' );
	assert.equal( email.getAttribute( 'aria-describedby' ), 'akb-c-email-error' );

	email.valido = true;
	mensaje.valido = true;
	await e.enviar();
	assert.equal( e.peticiones.length, 1, 'con todo válido sale al endpoint' );
	assert.match( e.peticiones[ 0 ].url, /contacto$/ );
	assert.equal( e.aviso.textContent, 'Recibido.' );
} );

/*
 * T5-R3: el `validationMessage` sale en el idioma de la INTERFAZ del navegador
 * («Please fill out this field.» en un Chrome en inglés), no en el de la página.
 * Sin copys propios se marca con su idioma; con los de akibara-business
 * (AkibaraFormsConfig.messages, por motivo de ValidityState) se usan ésos.
 */
test( 'T5-R3: el mensaje del navegador lleva lang con el idioma del navegador', async () => {
	const e = entornoFormulario( { idioma: 'en-US' } );
	await e.enviar();
	for ( const c of e.campos ) {
		const msg = e.porId.get( `${ c.id }-error` );
		assert.equal( msg.textContent, c.validationMessage );
		assert.equal( msg.getAttribute( 'lang' ), 'en-US', `${ c.id }: sin lang el lector lo lee con voz española` );
	}
} );

test( 'T5-R3: con los copys del panel se usan ésos, por motivo, y heredan el idioma de la página', async () => {
	const e = entornoFormulario( { idioma: 'en-US', mensajes: { valueMissing: 'Falta este dato.', typeMismatch: '  ', tooLong: 'Muy largo.' } } );
	await e.enviar();
	const [ nombre, email, mensaje ] = e.campos;
	for ( const c of [ nombre, mensaje ] ) {
		const msg = e.porId.get( `${ c.id }-error` );
		assert.equal( msg.textContent, 'Falta este dato.' );
		assert.equal( msg.getAttribute( 'lang' ), null, 'un copy del dueño está en el idioma de la página' );
	}
	// Copy vacío o ausente para ese motivo: el del navegador, marcado.
	const correo = e.porId.get( `${ email.id }-error` );
	assert.equal( correo.textContent, email.validationMessage );
	assert.equal( correo.getAttribute( 'lang' ), 'en-US' );

	// Al cambiar de motivo, el texto y el lang cambian con él (no queda un lang viejo).
	email.validity = { valueMissing: true };
	e.escribir( email );
	assert.equal( correo.textContent, 'Falta este dato.' );
	assert.equal( correo.getAttribute( 'lang' ), null );
} );
