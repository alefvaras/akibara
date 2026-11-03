import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import test from 'node:test';

/**
 * La venta cruzada de los estados vacíos se estiliza desde una hoja que está en
 * TODAS las superficies donde puede aparecer (revisión T4-R3, 2026-09-23).
 *
 * El tema la pinta también dentro del aviso `[data-akb-empty]` del buscador
 * (`akibara_search_empty_after`), y ese aviso entra por PJAX al filtrar a cero un
 * listado que se cargó CON resultados, sin empty-states.css (PC-15, inc/enqueue.php).
 * Con sus reglas en esa hoja, la venta cruzada llegaba sin estilo: título a 17 px
 * en vez de 28, sin filete, sin raya ni aire (medido en el espejo en /tienda/,
 * /manga/ y /serie/blue-lock/, Chromium y WebKit). loop.css va en todas las
 * superficies con rejilla, que son justo las que la pintan. El e2e
 * `tema-entrega-css-js.spec.mjs` lo mide en un navegador.
 */
const read = ( rel ) => readFileSync( new URL( `../${ rel }`, import.meta.url ), 'utf8' );
const sinComentarios = ( css ) => css.replace( /\/\*[\s\S]*?\*\//g, '' );

const plantilla = read( 'template-parts/empty/cross-sell.php' );
const loop = sinComentarios( read( 'assets/css/loop.css' ) );
const vacios = sinComentarios( read( 'assets/css/empty-states.css' ) );

/** Cuerpo de la regla cuyo selector es exactamente `selector`, o null. */
function regla( css, selector ) {
	const m = new RegExp( `(?:^|})\\s*${ selector.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) }\\s*\\{([^}]*)\\}` ).exec( css );
	return m ? m[ 1 ] : null;
}

test( 'la sección y su título se estilizan en loop.css, que también está en los listados con resultados', () => {
	const seccion = regla( loop, '.akb-empty__cross-sell' );
	assert.ok( seccion, 'loop.css: la regla de la sección' );
	for ( const d of [ /margin-top:\s*var\(--space-12\)/, /padding-top:\s*var\(--space-12\)/, /border-top:\s*1px solid var\(--aki-border\)/, /text-align:\s*left/ ] ) {
		assert.match( seccion, d );
	}
	const titulo = regla( loop, '.akb-empty__cross-sell-heading' );
	assert.ok( titulo, 'loop.css: la regla del título' );
	for ( const d of [ /font-family:\s*var\(--font-heading\)/, /font-size:\s*var\(--text-2xl\)/, /padding-left:\s*var\(--space-3\)/, /border-left:\s*4px solid var\(--aki-red\)/, /text-transform:\s*uppercase/ ] ) {
		assert.match( titulo, d );
	}

	// loop.css es la hoja de TODA superficie con rejilla, listados incluidos.
	const enqueue = read( 'inc/enqueue.php' );
	assert.match( enqueue, /\$has_loop = akibara_gp_surface_has_product_loop\(\);\s*if \( \$has_loop \) \{\s*list\( \$src, \$ver \) = akibara_gp_asset\( 'assets\/css\/loop\.css' \);/ );
} );

test( 'ninguna regla de lo que viaja dentro del aviso queda en empty-states.css', () => {
	// Esa hoja sólo va donde la página se SIRVE vacía: lo que llega por PJAX no puede depender de ella.
	assert.doesNotMatch( vacios, /\.akb-empty__cross-sell/, 'empty-states.css no se encola en un listado con resultados' );
} );

test( 'la rejilla de la venta cruzada es la del catálogo: la sección lleva .woocommerce y ninguna hoja le pone otra', () => {
	// `.woocommerce ul.products` (loop.css) pinta la rejilla porque la sección trae la
	// clase. Las reglas propias que tenía `.akb-empty__cross-sell-grid` perdían siempre
	// contra ésa (medido de 360 a 1440 px en el 404 y el carrito: borrarlas no cambiaba
	// ni un valor computado) y se retiraron. Si alguien quita `woocommerce` de la
	// sección, la rejilla se queda sin estilo: esta prueba lo avisa.
	assert.match( plantilla, /<section class="akb-empty__cross-sell woocommerce"/ );
	assert.match( plantilla, /<ul class="akb-empty__cross-sell-grid products columns-4">/ );
	assert.ok( regla( loop, '.woocommerce ul.products' ), 'loop.css: la rejilla del catálogo' );

	const hojas = readdirSync( new URL( '../assets/css/', import.meta.url ) ).filter( ( f ) => f.endsWith( '.css' ) && ! f.endsWith( '.min.css' ) );
	for ( const hoja of hojas ) {
		assert.doesNotMatch( sinComentarios( read( `assets/css/${ hoja }` ) ), /\.akb-empty__cross-sell-grid/, `${ hoja }: una regla muerta de la rejilla` );
	}
} );
