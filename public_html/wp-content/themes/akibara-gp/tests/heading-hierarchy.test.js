'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const themeRoot = path.resolve( __dirname, '..' );
const titleParser = fs.readFileSync( path.join( themeRoot, 'inc/title-parser.php' ), 'utf8' );
const loopCss = fs.readFileSync( path.join( themeRoot, 'assets/css/loop.css' ), 'utf8' );

test( 'candado de fuente: el nivel del título de la card lo decide akb_loop_title_level() (h3 bajo sección h2, h2 en los archivos)', () => {
	// Medido en la portada el 2026-08-28: 1 h1 + 40 h2 + CERO h3 (el h2 era a la
	// vez sección y tomo). Y medido en /tienda/, /a-pedido/ y /manga/ el
	// 2026-09-20 (axe heading-order): h1 → h3 sin h2 en medio. Las dos cosas
	// se resuelven con un nivel por contexto (R12-listados-saltan-h1-h3).
	assert.match( titleParser, /function akb_loop_title_level\(\): string/ );
	assert.match( titleParser, /is_front_page\(\)/, 'portada: bajo sección h2' );
	assert.match( titleParser, /is_product\(\)/, 'ficha (relacionados): bajo sección h2' );
	assert.match( titleParser, /is_tax\( 'pa_serie' \)/, 'landing de serie: bajo sección h2' );
	assert.match( titleParser, /\? 'h3' : 'h2'/, 'h3 bajo sección, h2 en los archivos' );
	assert.doesNotMatch( titleParser, /<h[1-6] class="woocommerce-loop-product__title">/, 'ningún nivel fijo: el nivel lo decide akb_loop_title_level()' );
} );

test( 'ambas ramas del renderizador emiten el mismo nivel', () => {
	// Hay dos: la de producto con editorial y la de respaldo sin $product. Si
	// sólo se cambia una, el nivel depende de si el tomo tiene marca asignada.
	const emitidos = titleParser.match( /'<' \. \$nivel \. ' class="woocommerce-loop-product__title">'/g ) || [];

	assert.equal( emitidos.length, 2, 'Se esperan exactamente dos puntos de emisión, los dos con $nivel.' );
} );

test( 'ningún selector CSS depende del elemento del título', () => {
	// Es lo que hace seguro cambiar el nivel. Si alguien escribe
	// `h3.woocommerce-loop-product__title`, el siguiente cambio de nivel rompe
	// el estilo en silencio.
	assert.doesNotMatch(
		loopCss,
		/h[1-6]\.woocommerce-loop-product__title/,
		'El CSS tiene que enganchar por clase, nunca por elemento.'
	);
} );
