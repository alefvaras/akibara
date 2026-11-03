'use strict';

/*
 * La cabecera compacta de escritorio no puede hacer temblar la página.
 *
 * Con un único umbral (`scrollTop > 120`) la página oscilaba sin fin si el
 * scroll se detenía justo encima de 120: la cabecera es sticky y al compactarse
 * encoge, el anclaje de scroll de Chromium lo compensa, el scroll queda bajo
 * 120, la cabecera crece, el anclaje lo devuelve… 60 eventos de scroll por
 * segundo (medido en el espejo, lote X1: en /tienda/ a 1280x720 y en la portada
 * a 1440x900, de 121 a 125 px). Lo destapó la bandeja de la landing de serie,
 * que al abrirse a 1280x720 dejaba el scroll en 125.
 *
 * Se ejecuta el bloque real de main.js en un `vm`: el scroll se mueve a mano,
 * como lo movería el anclaje, y se mira la clase de la cabecera.
 */

/*
 * Lo que encoge la cabecera, y por tanto lo que mueve el anclaje en cada cambio:
 * 26 px, de 130 a 104 (cabecera principal 79 → 61, menú 51 → 43), una vez
 * terminadas las transiciones. Los 8 px que decía esta prueba eran sólo el primer
 * instante (revisión REV-X1-2-D4, medido en el espejo en /tienda/ a 1280 y 1440,
 * con y sin movimiento reducido: de 59 el anclaje sube el scroll a 85). Con el
 * valor real la prueba guarda el margen que de verdad hay entre los umbrales.
 */
const ENCOGE = 26;

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const main = fs.readFileSync( path.resolve( __dirname, '..', 'assets/js/main.js' ), 'utf8' );
const inicio = main.indexOf( '/* ── Scroll progress bar' );
const fin = main.indexOf( '/* ── Desktop nav: submenús' );
const bloque = inicio < 0 || fin < inicio ? '' : main.slice( inicio, fin );

function entorno() {
	const clases = new Set();
	const cabecera = {
		classList: {
			contains: ( c ) => clases.has( c ),
			toggle( c, si ) {
				if ( si ) {
					clases.add( c );
				} else {
					clases.delete( c );
				}
			},
		},
	};
	const docEl = { scrollTop: 0, scrollHeight: 5000, clientHeight: 800 };
	const cuadros = [];
	const oyentes = {};
	const doc = {
		documentElement: docEl,
		getElementById: ( id ) => ( 'aki-scroll-progress' === id ? { style: {} } : null ),
		querySelector: ( s ) => ( '.site-header' === s ? cabecera : null ),
	};
	const window = {
		addEventListener: ( ev, fn ) => { oyentes[ ev ] = fn; },
		requestAnimationFrame: ( fn ) => { cuadros.push( fn ); },
	};
	vm.runInContext( '(function(){' + bloque + '})();', vm.createContext( { doc, window } ) );
	const pintar = () => { while ( cuadros.length ) { cuadros.shift()(); } };
	pintar();
	return {
		compacta: () => clases.has( 'is-compact' ),
		// Un scroll que se detiene en `y`, y el cuadro que lo pinta.
		scroll( y ) {
			docEl.scrollTop = y;
			oyentes.scroll();
			pintar();
		},
	};
}

test( 'el bloque existe en main.js', () => {
	assert.ok( bloque.length > 0 );
	assert.match( bloque, /classList\.toggle\(\s*'is-compact'/ );
} );

test( 'se compacta al pasar de 120 px', () => {
	const e = entorno();
	e.scroll( 120 );
	assert.equal( e.compacta(), false );
	e.scroll( 121 );
	assert.equal( e.compacta(), true );
} );

test( 'X1: el anclaje que devuelve el scroll bajo 120 no la vuelve a agrandar (la página temblaba)', () => {
	const e = entorno();
	for ( const y of [ 121, 124, 125 ] ) {
		e.scroll( 0 );
		e.scroll( y );
		assert.equal( e.compacta(), true, `${ y }: compacta` );
		// La cabecera encoge 26 px: el anclaje resta 26 al scroll.
		e.scroll( y - ENCOGE );
		assert.equal( e.compacta(), true, `${ y - ENCOGE }: antes volvía a crecer, el anclaje sumaba ${ ENCOGE } y otra vez` );
	}
} );

test( 'vuelve a su alto al subir por debajo de 60 px, y el anclaje no la compacta de nuevo', () => {
	const e = entorno();
	e.scroll( 400 );
	e.scroll( 60 );
	assert.equal( e.compacta(), true, 'a 60 px sigue compacta' );
	e.scroll( 59 );
	assert.equal( e.compacta(), false, 'bajo 60 px vuelve a su alto' );
	// Crece 26 px: el anclaje suma 26 (medido: de 59 a 85).
	e.scroll( 59 + ENCOGE );
	assert.equal( e.compacta(), false );
} );

test( 'REV-X1-2-D4: en cualquier punto entre los umbrales, el salto del anclaje no cambia el estado', () => {
	// Para cada scroll en que la cabecera cambia, el anclaje la lleva ENCOGE px más
	// allá; si ahí vuelve a cambiar, la página tiembla. Recorre los dos bordes.
	for ( let y = 121; y <= 121 + ENCOGE; y += 1 ) {
		const e = entorno();
		e.scroll( y );
		assert.equal( e.compacta(), true, `${ y }: compacta` );
		e.scroll( y - ENCOGE );
		assert.equal( e.compacta(), true, `compacta en ${ y }, el anclaje la deja en ${ y - ENCOGE }: no puede volver a crecer` );
	}
	for ( let y = 59; y >= 0; y -= 1 ) {
		const e = entorno();
		e.scroll( 400 );
		e.scroll( y );
		assert.equal( e.compacta(), false, `${ y }: entera` );
		e.scroll( y + ENCOGE );
		assert.equal( e.compacta(), false, `crece en ${ y }, el anclaje la deja en ${ y + ENCOGE }: no puede volver a compactarse` );
	}
} );
