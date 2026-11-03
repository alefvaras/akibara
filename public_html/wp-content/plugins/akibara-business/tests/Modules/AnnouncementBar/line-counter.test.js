'use strict';

// Contador de caracteres por línea de admin-panel.js: la lógica pura, sin DOM.
const assert = require( 'node:assert/strict' );

// El fichero engancha DOMContentLoaded al cargarse: en node hace falta un
// `document` mínimo que no haga nada.
global.document = {
	addEventListener() {},
	querySelectorAll() { return []; },
	getElementById() { return null; }
};
const { lineCounter } = require( '../../../assets/js/admin-panel.js' );

assert.deepEqual( lineCounter( '', 40 ), { lines: 0, longest: 0, over: 0, text: '' }, 'vacío: sin texto' );
assert.deepEqual( lineCounter( '  \n\n', 40 ), { lines: 0, longest: 0, over: 0, text: '' }, 'sólo espacios: sin líneas' );

const corta = lineCounter( 'Envío gratis sobre $60.000', 40 );
assert.equal( corta.lines, 1 );
assert.equal( corta.over, 0 );
assert.equal( corta.text, '1 línea · la más larga 26/40' );

const larga = lineCounter( 'Envío gratis sobre $60.000\n' + 'x'.repeat( 41 ) + '\n' + 'y'.repeat( 300 ), 40 );
assert.equal( larga.lines, 3 );
assert.equal( larga.longest, 300 );
assert.equal( larga.over, 2 );
assert.equal( larga.text, '3 líneas · la más larga 300/40 · 2 se cortan en móvil' );

const justo = lineCounter( 'x'.repeat( 40 ) + '\n' + 'z'.repeat( 41 ), 40 );
assert.equal( justo.over, 1, 'el límite es inclusivo: 40 cabe, 41 no' );
assert.equal( justo.text, '2 líneas · la más larga 41/40 · 1 se corta en móvil' );

// El enlace del final y las etiquetas no se ven en la cinta: no cuentan.
const enlazada = lineCounter( 'Retiro sin costo en Metro San Miguel | /envios/', 40 );
assert.equal( enlazada.longest, 36, 'la dirección tras la barra no ocupa la cinta' );
assert.equal( enlazada.over, 0 );
const conHtml = lineCounter( '<a href="https://akibara.cl/marca/ivrea-argentina/?_disponibilidad%5B%5D=instock">Este finde: 10% en Ivrea</a>', 40 );
assert.equal( conHtml.longest, 24, 'las etiquetas HTML no se ven' );
assert.equal( conHtml.over, 0 );
const noEsEnlace = lineCounter( 'Manga | Cómic', 40 );
assert.equal( noEsEnlace.longest, 13, 'una barra que no va seguida de una dirección es texto y cuenta' );

// QA-PANEL-1: las direcciones que la barra reconoce ahora (esquema en
// mayúsculas, sin esquema) tampoco se ven, así que tampoco cuentan.
assert.equal( lineCounter( 'Envío gratis | www.akibara.cl/envios/', 40 ).longest, 12, 'www sin esquema' );
assert.equal( lineCounter( 'Envío gratis | akibara.cl/envios/', 40 ).longest, 12, 'dominio sin www' );
assert.equal( lineCounter( 'Ivrea | HTTPS://akibara.cl/marca/ivrea/', 40 ).longest, 5, 'esquema en mayúsculas' );
assert.equal( lineCounter( 'Retiro | /retiro sin costo/', 40 ).longest, 27, 'una ruta con espacios no es una dirección: se ve, y cuenta' );
assert.equal( lineCounter( 'Ver más | envios', 40 ).longest, 16, 'una palabra suelta no es una dirección' );

console.log( 'line-counter: ok' );
