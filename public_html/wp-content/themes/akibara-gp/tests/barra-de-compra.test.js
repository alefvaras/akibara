'use strict';

/*
 * Barra de compra fija de la ficha (sticky-atc.js), ejecutada de verdad.
 *
 * Se corre el guion en un contexto de `vm` con dobles mínimos del DOM y de los
 * observadores, y se le entregan las intersecciones que daría el navegador. Así
 * se prueba la DECISIÓN (cuándo se ve la barra) y no el texto del fichero:
 *
 *  - UM-7: al cargar, con el H1 bajo la franja de la barra, no se muestra.
 *  - UM-2: el armador en pantalla ya no la apaga; sólo cede ante su botón de
 *    envío a la vista Y habilitado.
 *  - UC-4: en escritorio la franja es sólo la barra (sin bottom-nav).
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const raiz = path.resolve( __dirname, '..' );
const fuente = fs.readFileSync( path.join( raiz, 'assets/js/sticky-atc.js' ), 'utf8' );
const plantilla = fs.readFileSync( path.join( raiz, 'template-parts/woocommerce/sticky-atc.php' ), 'utf8' );
const hoja = fs.readFileSync( path.join( raiz, 'assets/css/woocommerce.css' ), 'utf8' );

function entorno( { conTitulo = true, conPack = true, escritorio = false } = {} ) {
	const observadores = [];
	const mutaciones = [];

	class IO {
		constructor( cb, opciones ) {
			this.cb = cb;
			this.opciones = opciones || {};
			this.objetivos = [];
			this.muerto = false;
			observadores.push( this );
		}
		observe( t ) { this.objetivos.push( t ); }
		disconnect() { this.muerto = true; }
	}

	class MO {
		constructor( cb ) { this.cb = cb; mutaciones.push( this ); }
		observe( t, o ) { this.t = t; this.o = o; }
	}

	const clases = new Set();
	const titleEl = { textContent: 'Título original' };
	const priceEl = { innerHTML: '$10.000' };
	const barra = {
		classList: {
			toggle( c, si ) { if ( si ) { clases.add( c ); } else { clases.delete( c ); } },
			contains( c ) { return clases.has( c ); },
		},
		querySelector: ( s ) => {
			if ( '.akb-sticky-atc__title' === s ) return titleEl;
			if ( '.akb-sticky-atc__price' === s ) return priceEl;
			return null;
		},
	};
	const real = { clics: 0, click() { this.clics += 1; } };
	const form = {
		atributos: {},
		querySelector: ( s ) => ( '.single_add_to_cart_button' === s ? real : null ),
		hasAttribute( k ) { return k in this.atributos; },
	};
	const proxy = { textContent: 'Comprar ahora', addEventListener( ev, fn ) { this.fn = fn; } };
	const titulo = { nombre: 'h1' };

	const mql = { matches: escritorio, addEventListener( ev, fn ) { this.fn = fn; } };

	const document = {
		documentElement: {},
		getElementById: ( id ) => ( { 'akb-sticky-atc': barra, 'akb-sticky-atc-btn': proxy }[ id ] || null ),
		querySelector: ( s ) => {
			if ( 'form.cart' === s ) { return form; }
			if ( '.product_title' === s ) { return conTitulo ? titulo : null; }
			return null;
		},
	};

	const window = {
		IntersectionObserver: IO,
		MutationObserver: MO,
		innerHeight: 844,
		matchMedia: () => mql,
	};

	const ctx = vm.createContext( {
		window,
		document,
		IntersectionObserver: IO,
		MutationObserver: MO,
		getComputedStyle: () => ( {
			getPropertyValue: ( n ) => ( { '--bottom-nav-height': '66px', '--buy-bar-height': '68px' }[ n ] || '' ),
		} ),
	} );
	vm.runInContext( fuente, ctx );

	const vivos = ( objetivo ) => observadores.filter( ( o ) => ! o.muerto && o.objetivos.includes( objetivo ) );

	return {
		barra, real, proxy, mql, observadores, mutaciones,
		titleEl, priceEl,
		visible: () => barra.classList.contains( 'is-visible' ),
		// Entrega una intersección a todos los observadores vivos del objetivo.
		cruzar( objetivo, entrada ) {
			vivos( objetivo ).forEach( ( o ) => o.cb( [ entrada ] ) );
		},
		form, titulo,
		margenDe: ( objetivo ) => ( vivos( objetivo )[ 0 ] || { opciones: {} } ).opciones.rootMargin,
	};
}

// La franja móvil: bottom-nav 66 + barra 68 = 134 px; con 844 de alto, el techo
// de la barra (borde inferior de la raíz del observador) está en 710.
const RAIZ = { top: 0, bottom: 710 };
const fuera = { isIntersecting: false, boundingClientRect: { top: 1226, bottom: 1270 }, rootBounds: RAIZ };
// El título ya fuera por arriba: con la raíz estirada hacia arriba, intersecta entero.
const arriba = { isIntersecting: true, intersectionRatio: 1, boundingClientRect: { top: -80, bottom: -48 }, rootBounds: RAIZ };

test( 'UM-7: al cargar, con el H1 bajo la barra, la barra NO se muestra', () => {
	const e = entorno();
	e.cruzar( e.form, fuera );
	// Medido en producción: H1 en 693-726, barra desde 716.
	e.cruzar( e.titulo, { isIntersecting: true, intersectionRatio: 0.5, boundingClientRect: { top: 693, bottom: 726 }, rootBounds: RAIZ } );
	assert.equal( e.visible(), false, 'la barra taparía el nombre del tomo en el primer pantallazo' );

	// 16 px de scroll después el título queda entero por encima: ahora sí.
	e.cruzar( e.titulo, { isIntersecting: true, intersectionRatio: 1, boundingClientRect: { top: 677, bottom: 710 }, rootBounds: RAIZ } );
	assert.equal( e.visible(), true );

	// Y sigue cuando el título ya salió por arriba.
	e.cruzar( e.titulo, arriba );
	assert.equal( e.visible(), true );

	// Un título por DEBAJO de la raíz (no intersecta) nunca está libre.
	e.cruzar( e.titulo, { isIntersecting: false, intersectionRatio: 0, boundingClientRect: { top: 760, bottom: 792 }, rootBounds: RAIZ } );
	assert.equal( e.visible(), false );
} );

test( 'UM-7: la raíz del título no tiene techo, para que un SALTO de scroll siempre avise', () => {
	// Con la raíz del tamaño de la ventana, saltar de «bajo la barra» a «fuera
	// por arriba» (ancla, atrás, scrollTo) no cruza ningún umbral: el observador
	// no avisa y la barra se quedaba escondida. Medido en el espejo.
	const e = entorno();
	const margen = e.margenDe( e.titulo );
	const arribaPx = parseFloat( margen.split( ' ' )[ 0 ] );
	assert.ok( arribaPx >= 10000, `la raíz del título se estira hacia arriba (${ margen })` );
	assert.match( margen, / -134px 0px$/, 'y su borde inferior es el techo de la barra' );
} );

test( 'el form real a la vista retira la barra', () => {
	const e = entorno();
	e.cruzar( e.titulo, arriba );
	e.cruzar( e.form, fuera );
	assert.equal( e.visible(), true );
	e.cruzar( e.form, { isIntersecting: true, boundingClientRect: { top: 400, bottom: 444 }, rootBounds: RAIZ } );
	assert.equal( e.visible(), false );
} );

test( 'sin H1 se comporta como siempre: form fuera → barra', () => {
	const e = entorno( { conTitulo: false } );
	e.cruzar( e.form, fuera );
	assert.equal( e.visible(), true );
} );

test( 'el botón de la barra es un proxy del botón real', () => {
	const e = entorno();
	e.proxy.fn();
	assert.equal( e.real.clics, 1 );
} );

/*
 * Revisión de la corrección Z-T-c1 (2026-09-25): con el POST normal de la ficha de
 * camino (antes de que enganche pdp-ajax-atc.js) el formulario queda `inert`, pero
 * el click() por código del proxy lo saltaba: dos toques en la barra, dos POST y
 * dos altas (medido en el espejo en Chromium y WebKit a 390 táctil).
 */
test( 'con el formulario inerte (POST de camino) la barra no lo reenvía', () => {
	const e = entorno();
	e.form.atributos.inert = '';
	e.proxy.fn();
	e.proxy.fn();
	assert.equal( e.real.clics, 0, 'cada toque en la barra mandaba otra alta' );
	delete e.form.atributos.inert; // la página se quedó (plazo, «Atrás»): vuelve a comprar
	e.proxy.fn();
	assert.equal( e.real.clics, 1 );
} );

test( 'UC-4: en escritorio la franja es sólo la barra, y al cruzar 1024 px se recalcula', () => {
	const e = entorno( { escritorio: true } );
	assert.equal( e.margenDe( e.form ), '0px 0px -68px 0px', 'sin bottom-nav en escritorio' );
	assert.match( e.margenDe( e.titulo ), / -68px 0px$/ );

	e.mql.matches = false;
	e.mql.fn();
	assert.equal( e.margenDe( e.form ), '0px 0px -134px 0px', 'al pasar a ≤1024 px entra el bottom-nav' );
	// Los observadores viejos se desconectaron: nada queda duplicado.
	assert.equal( e.observadores.filter( ( o ) => ! o.muerto ).length, 2 );
} );

test( 'UC-4: la barra existe en escritorio, pegada abajo, con Joinchat por encima', () => {
	const match = /@media \(min-width: 1025px\)\s*\{\s*body\.single-product \.akb-sticky-atc/.exec( hoja );
	const escritorio = match ? hoja.slice( match.index ) : '';
	assert.ok( escritorio.length > 0, 'hay un bloque de escritorio para la barra' );
	assert.match( escritorio, /body\.single-product \.akb-sticky-atc\s*\{[^}]*display:\s*flex;[^}]*bottom:\s*0;/ );
	assert.match( escritorio, /\.akb-sticky-atc__title\s*\{[^}]*display:\s*block;/ );
	// El pie no puede quedar debajo de la barra al fondo de la ficha.
	assert.match( escritorio, /body\.single-product\s*\{[^}]*padding-bottom:\s*max\(var\(--buy-bar-height/ );
	assert.match( escritorio, /body\.single-product:has\(\.akb-sticky-atc\) \.joinchat\s*\{[^}]*bottom:/ );
	// En tablet, la custom CSS de Joinchat lo dejaba encima del botón de la barra.
	assert.match( hoja, /@media \(min-width: 768px\) and \(max-width: 1024px\)\s*\{\s*body\.single-product:has\(\.akb-sticky-atc\) \.joinchat/ );
} );

test( 'el nombre del tomo en la barra no duplica el H1 para el lector de pantalla', () => {
	assert.match( plantilla, /<span class="akb-sticky-atc__title" aria-hidden="true"><\?php echo esc_html\( \$product->get_name\(\) \); \?><\/span>/ );
	assert.match( hoja, /\.akb-sticky-atc__title\s*\{\s*display:\s*none;/, 'fuera de escritorio no se ve' );
	assert.doesNotMatch( plantilla, /en desktop nunca se muestra/, 'el comentario de intención ya no describe la barra de antes' );
} );


