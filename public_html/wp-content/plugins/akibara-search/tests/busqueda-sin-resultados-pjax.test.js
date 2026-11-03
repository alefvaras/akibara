'use strict';

/**
 * El PJAX del catálogo con la venta cruzada del tema DENTRO del aviso de «sin
 * resultados» (UC-1 / UM-8, auditoría del tema 2026-09-22).
 *
 * El tema pinta sus «Lo más vendido» en el hook `akibara_search_empty_after`,
 * dentro de `[data-akb-empty]`, con su parte compartida
 * `template-parts/empty/cross-sell.php`: una `ul.products`. Para el PJAX la
 * rejilla del catálogo era «la primera `ul.products` del documento», y en cero
 * resultados ésa pasaba a ser la venta cruzada:
 *
 *   - 0 → N: la rejilla nueva reemplazaba a la venta cruzada DENTRO del aviso y
 *     el swap siguiente reemplazaba el aviso por el vacío de la respuesta, así
 *     que la tienda mostraba cero productos teniendo resultados;
 *   - N → 0: la rejilla del catálogo se cambiaba por la venta cruzada, sacada
 *     de su sección, y el rótulo «Lo más vendido» quedaba huérfano en el aviso.
 *
 * Se ejercita el BUNDLE PUBLICADO (`.min`), que es el que sirve el sitio, sobre
 * un DOM mínimo con la misma forma que el HTML medido en el espejo.
 */

const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const { join } = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const pluginRoot = join( __dirname, '..' );

// El DOM mínimo (árbol, selectores, swap) vive en Support/mini-dom.js y lo
// comparte con facetas-foco-movimiento-y-conteo.test.js.
const { h, mundo, crearDocumento } = require( './Support/mini-dom.js' );

/** Tarjetas de producto: `li.product` con un id legible. */
const tarjetas = ( prefijo, n ) => Array.from( { length: n }, ( _, i ) => h( 'li', { class: 'product', id: `${ prefijo }-${ i + 1 }` } ) );

/** La venta cruzada tal como la imprime `template-parts/empty/cross-sell.php`. */
const ventaCruzada = () => h(
	'section',
	{ class: 'akb-empty__cross-sell woocommerce', id: 'venta-cruzada' },
	h( 'h2', { class: 'akb-empty__cross-sell-heading' } ),
	h( 'ul', { class: 'akb-empty__cross-sell-grid products columns-4' }, ...tarjetas( 'mas-vendido', 4 ) )
);

/** Página del catálogo, en el orden medido en el espejo. */
function pagina( { total, venta = true } ) {
	const main = h(
		'main',
		{},
		h( 'h1', { class: 'page-title' } ),
		h( 'div', { class: 'akb-shop-toolbar' }, h( 'p', { class: 'akb-result-count' } ) ),
		h( 'div', { class: 'akb-facet-chips', 'data-akb-chips': '' } ),
		total > 0
			? h( 'div', { class: 'akb-empty-state', 'data-akb-empty': '', hidden: '' } )
			: h( 'div', { class: 'akb-empty-state', 'data-akb-empty': '' }, h( 'p', { class: 'akb-empty-title' } ), ...( venta ? [ ventaCruzada() ] : [] ) )
	);
	if ( total > 0 ) {
		main.appendChild( h( 'ul', { class: 'products columns-4' }, ...tarjetas( 'resultado', total ) ) );
		main.appendChild( h( 'nav', { class: 'woocommerce-pagination' } ) );
	}
	const root = h( 'html', {}, h( 'body', {}, main ) );
	root.title = total > 0 ? 'Resultados' : 'Sin resultados';
	return root;
}

/** Arranca el bundle publicado sobre `inicial` y devuelve cómo navegar por PJAX. */
function arrancar( inicial ) {
	const root = inicial;
	const windowListeners = new Map();
	let respuesta = null;
	const location = { href: 'https://akibara.test/tienda/?_buscar=a', pathname: '/tienda/', search: '?_buscar=a' };
	// `loading`: el bundle no arranca (no hay formulario de facetas que ligar);
	// aquí sólo se mira el intercambio de bloques del PJAX.
	mundo.reiniciar();
	const document = crearDocumento( root, 'loading' );
	const sandbox = {
		AbortController,
		Event,
		URL,
		URLSearchParams,
		Date,
		Math,
		JSON,
		AKB_FACETS: {},
		localStorage: { getItem: () => null, setItem: () => {} },
		DOMParser: class {
			parseFromString() {
				return respuesta;
			}
		},
		addEventListener: ( type, fn ) => windowListeners.set( type, ( windowListeners.get( type ) || [] ).concat( fn ) ),
		removeEventListener: ( type, fn ) => windowListeners.set( type, ( windowListeners.get( type ) || [] ).filter( ( f ) => f !== fn ) ),
		clearTimeout: () => {},
		setTimeout: ( fn ) => {
			fn();
			return 1;
		},
		matchMedia: () => ( { matches: false } ),
		document,
		fetch: () => Promise.resolve( {
			ok: true,
			status: 200,
			headers: { get: ( n ) => ( 'content-type' === n.toLowerCase() ? 'text/html; charset=UTF-8' : null ) },
			text: () => Promise.resolve( '<html></html>' ),
		} ),
		history: { pushState: () => {} },
		location,
	};
	sandbox.window = sandbox;

	vm.runInNewContext( readFileSync( join( pluginRoot, 'assets/js/akb-search.min.js' ), 'utf8' ), sandbox );

	return {
		root,
		/** Navega por PJAX (vía popstate) y espera al swap. */
		async ir( destino, busqueda ) {
			respuesta = destino;
			location.search = '?_buscar=' + busqueda;
			location.href = 'https://akibara.test/tienda/' + location.search;
			windowListeners.get( 'popstate' ).forEach( ( fn ) => fn( {} ) );
			for ( let i = 0; i < 10; i++ ) {
				await new Promise( ( r ) => setImmediate( r ) );
			}
		},
	};
}

/** Lo que el cliente ve: rejillas por fuera del aviso, y el aviso. */
function foto( root ) {
	const aviso = root.querySelector( '[data-akb-empty]' );
	return {
		rejillaCatalogo: root.querySelectorAll( 'ul.products' ).filter( ( u ) => ! u.closest( '[data-akb-empty]' ) ).map( ( u ) => u.children.length ),
		avisoOculto: aviso.hidden,
		ventaCruzadaEnElAviso: aviso.querySelectorAll( 'li.product' ).length,
		rotulosVentaCruzada: root.querySelectorAll( '.akb-empty__cross-sell-heading' ).length,
		barraAntesDeLaRejilla: ( () => {
			const main = root.querySelector( 'main' );
			const orden = main.children.map( ( c ) => c.getAttribute( 'class' ) || '' );
			return orden.findIndex( ( k ) => k.includes( 'akb-shop-toolbar' ) ) < orden.findIndex( ( k ) => /^products\b/.test( k ) );
		} )(),
		paginacion: root.querySelectorAll( '.woocommerce-pagination' ).length,
	};
}

test( 'de cero a N resultados: aparece la rejilla del catálogo y la venta cruzada se va con el aviso', async () => {
	const pjax = arrancar( pagina( { total: 0 } ) );

	await pjax.ir( pagina( { total: 3 } ), 'one+piece' );

	assert.deepEqual( foto( pjax.root ), {
		rejillaCatalogo: [ 3 ],
		avisoOculto: true,
		ventaCruzadaEnElAviso: 0,
		rotulosVentaCruzada: 0,
		barraAntesDeLaRejilla: true,
		paginacion: 1,
	} );
} );

test( 'de N a cero: la rejilla del catálogo se va y la venta cruzada queda entera DENTRO del aviso', async () => {
	const pjax = arrancar( pagina( { total: 3 } ) );

	await pjax.ir( pagina( { total: 0 } ), 'gundam' );

	const f = foto( pjax.root );
	assert.deepEqual( f.rejillaCatalogo, [], 'Ninguna ul.products fuera del aviso.' );
	assert.equal( f.avisoOculto, false );
	assert.equal( f.ventaCruzadaEnElAviso, 4 );
	assert.equal( f.rotulosVentaCruzada, 1 );
	assert.equal( f.paginacion, 0 );
	assert.ok(
		pjax.root.querySelector( '#venta-cruzada ul.products' ),
		'La rejilla de la venta cruzada sigue dentro de su sección, no suelta en el sitio del catálogo.'
	);
} );

/**
 * Lo que el cliente tiene que ver tras cada navegación: con N resultados, la
 * rejilla del catálogo y el aviso oculto y vacío; con cero, ninguna rejilla fuera
 * del aviso y la venta cruzada entera dentro, en su sección.
 */
function esperado( total ) {
	return total > 0
		? { rejillaCatalogo: [ total ], avisoOculto: true, ventaCruzadaEnElAviso: 0, rotulosVentaCruzada: 0, barraAntesDeLaRejilla: true, paginacion: 1, ventaCruzadaEnSuSeccion: false }
		: { rejillaCatalogo: [], avisoOculto: false, ventaCruzadaEnElAviso: 4, rotulosVentaCruzada: 1, barraAntesDeLaRejilla: false, paginacion: 0, ventaCruzadaEnSuSeccion: true };
}

test( 'cero → N → cero → cero → N: ida y vuelta sin restos, mirado tras CADA paso', async () => {
	const pjax = arrancar( pagina( { total: 0 } ) );
	const mirar = () => ( { ...foto( pjax.root ), ventaCruzadaEnSuSeccion: !! pjax.root.querySelector( '#venta-cruzada ul.products' ) } );

	assert.deepEqual( mirar(), esperado( 0 ), 'La página de partida.' );

	// Sólo el estado final no basta: con el bundle anterior al arreglo el último
	// 0 → N dejaba la página limpia aunque los pasos intermedios estaban rotos
	// (revisión B4-R2).
	for ( const [ total, busqueda ] of [ [ 2, 'bleach' ], [ 0, 'gundam' ], [ 0, 'horimiya' ], [ 5, 'one+piece' ] ] ) {
		await pjax.ir( pagina( { total } ), busqueda );
		assert.deepEqual( mirar(), esperado( total ), `Tras navegar a «${ busqueda }» (${ total } resultados).` );
	}
} );

test( 'sin venta cruzada (tema sin el hook) el intercambio sigue igual que siempre', async () => {
	const pjax = arrancar( pagina( { total: 0, venta: false } ) );

	await pjax.ir( pagina( { total: 3 } ), 'one+piece' );
	assert.deepEqual( foto( pjax.root ).rejillaCatalogo, [ 3 ] );

	await pjax.ir( pagina( { total: 0, venta: false } ), 'gundam' );
	assert.deepEqual( foto( pjax.root ).rejillaCatalogo, [] );
	assert.equal( foto( pjax.root ).avisoOculto, false );
} );
