'use strict';

/**
 * Filtros del catálogo: a dónde va el foco, cómo se mueve la página y qué dice
 * el botón «Ver N productos» (QA integral del 2026-09-24).
 *
 *   - QA-ESC-TECLADO-3: marcar una casilla con Espacio sacaba el foco a la
 *     rejilla (WCAG 3.2.2) y, con el formulario después de la rejilla en el
 *     DOM, sumar un segundo filtro obligaba a cruzar todas las tarjetas. Con
 *     teclado el foco se queda en la casilla; con el ratón, como siempre.
 *   - QA-ESC-TECLADO-8: tras cada filtro la página se deslizaba con
 *     `behavior: 'smooth'` aunque el cliente pidiera movimiento reducido.
 *   - QAM-7: con un resultado el botón del cajón decía «Ver 1 productos».
 *   - Hallado al medir el anterior: con movimiento permitido la subida al
 *     total no ocurría, porque el refresh de ScrollTrigger del tema la anulaba.
 *
 * Se ejercita el BUNDLE PUBLICADO (`.min`), que es el que sirve el sitio.
 */

const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const { join } = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );
const { h, mundo, crearDocumento, disparar, FormDataFalso } = require( './Support/mini-dom.js' );

const pluginRoot = join( __dirname, '..' );
const bundle = readFileSync( join( pluginRoot, 'assets/js/akb-search.min.js' ), 'utf8' );

/** Una casilla de faceta como la pinta `facets-render.php`. */
const casilla = ( valor, marcada ) => h(
	'li',
	{ class: 'akb-facet-item' },
	h( 'label', {}, h( 'input', { type: 'checkbox', name: '_disponibilidad[]', value: valor, ...( marcada ? { checked: '' } : {} ) } ), h( 'span', { class: 'akb-facet-label' }, valor ) )
);

/**
 * Página de /tienda/ en el orden medido en el espejo: cabecera y orden, chips,
 * rejilla y, DESPUÉS, el formulario de filtros.
 *
 * @param {object}   o
 * @param {number}   o.total    Productos.
 * @param {string[]} o.marcadas Valores marcados.
 * @param {string[]} o.opciones Valores que ofrece la faceta.
 */
function pagina( { total, marcadas = [], opciones = [ 'instock', 'agotado' ] } ) {
	const unidad = 1 === total ? 'producto' : 'productos';
	const root = h(
		'html',
		{},
		h(
			'body',
			{},
			h(
				'main',
				{},
				h( 'h1', { class: 'page-title' }, 'Tienda' ),
				h(
					'div',
					{ class: 'akb-shop-toolbar' },
					h( 'p', { class: 'akb-result-count' }, `${ total } ${ unidad }` ),
					h( 'form', { class: 'woocommerce-ordering', method: 'get', action: '/tienda/' }, h( 'select', { class: 'orderby', name: 'orderby', value: 'menu_order' } ) )
				),
				h( 'div', { class: 'akb-facet-chips', 'data-akb-chips': '' } ),
				h( 'div', { class: 'akb-empty-state', 'data-akb-empty': '', hidden: '' } ),
				h( 'ul', { class: 'products columns-4' }, ...Array.from( { length: total }, ( _, i ) => h( 'li', { class: 'product', id: `p-${ i + 1 }` } ) ) ),
				h( 'nav', { class: 'woocommerce-pagination' } ),
				h(
					'form',
					{ id: 'akb-facets-drawer', class: 'akb-facets', method: 'get', action: '/tienda/', 'data-akb-facets': '' },
					h( 'div', { class: 'akb-facets-head' }, h( 'button', { type: 'button', class: 'akb-facets-close', 'data-akb-drawer-close': '' } ) ),
					h(
						'div',
						{ class: 'akb-facets-body' },
						h( 'section', { class: 'akb-facet', 'data-facet': 'disponibilidad' }, h( 'ul', { class: 'akb-facet-list' }, ...opciones.map( ( v ) => casilla( v, marcadas.includes( v ) ) ) ) )
					),
					h(
						'div',
						{ class: 'akb-facets-footer' },
						h( 'button', { type: 'submit', class: 'akb-facets-apply', 'data-akb-apply': '' }, 'Ver ', h( 'span', { 'data-akb-apply-count': '' }, String( total ) ), ' ', h( 'span', { 'data-akb-apply-noun': '' }, unidad ) )
					)
				)
			),
			h( 'button', { type: 'button', class: 'akb-filter-trigger', 'data-akb-drawer-open': '' } )
		)
	);
	root.title = 'Tienda';
	return root;
}

/**
 * Arranca el bundle sobre /tienda/.
 *
 * @param {object}  o
 * @param {boolean} o.reducirMovimiento prefers-reduced-motion: reduce.
 * @param {boolean} o.movil             Ventana ≤ 768 px (cajón por lotes).
 * @param {object}  o.config            AKB_FACETS extra (lo que localiza PHP).
 */
function arrancar( { reducirMovimiento = false, movil = false, config, cuadros } = {} ) {
	mundo.reiniciar();
	const root = pagina( { total: 24 } );
	const document = crearDocumento( root, 'complete' );
	const estado = { respuesta: null, conteo: null, navegaciones: [], recargas: [] };
	const location = { href: 'https://akibara.test/tienda/', pathname: '/tienda/', search: '', assign: ( u ) => estado.recargas.push( u ) };
	const sandbox = {
		AbortController,
		Event,
		URL,
		URLSearchParams,
		Intl,
		FormData: FormDataFalso,
		AKB_FACETS: Object.assign( { countUrl: '/wp-json/akibara/v1/search/count', mobileMax: 768 }, config || {} ),
		localStorage: { getItem: () => null, setItem: () => {} },
		DOMParser: class {
			parseFromString() {
				return estado.respuesta;
			}
		},
		addEventListener: () => {},
		removeEventListener: () => {},
		clearTimeout: () => {},
		// Los temporizadores corren al instante: el debounce de 100 ms, el anuncio
		// a los 60 ms y el conteo del cajón a los 220 ms no cambian lo que se mide.
		setTimeout: ( fn ) => {
			fn();
			return 1;
		},
		matchMedia: ( q ) => ( { matches: /reduced-motion/.test( q ) ? reducirMovimiento : /max-width/.test( q ) ? movil : false } ),
		document,
		fetch: ( url ) => {
			if ( String( url ).startsWith( '/wp-json/' ) ) {
				return Promise.resolve( { ok: true, status: 200, headers: { get: () => null }, json: () => Promise.resolve( { total: estado.conteo } ) } );
			}
			estado.navegaciones.push( url );
			return Promise.resolve( {
				ok: true,
				status: 200,
				headers: { get: ( n ) => ( 'content-type' === n.toLowerCase() ? 'text/html; charset=UTF-8' : null ) },
				text: () => Promise.resolve( '<html></html>' ),
			} );
		},
		history: { pushState: () => {} },
		location,
	};
	if ( cuadros ) {
		// requestAnimationFrame con cola: la prueba decide cuándo corre cada cuadro.
		sandbox.requestAnimationFrame = ( fn ) => cuadros.push( fn );
	}
	sandbox.window = sandbox;
	vm.runInNewContext( bundle, sandbox );

	const esperar = async () => {
		for ( let i = 0; i < 20; i++ ) {
			await new Promise( ( r ) => setImmediate( r ) );
		}
	};

	return {
		root,
		document,
		estado,
		casilla: ( valor ) => root.querySelectorAll( '[data-akb-facets] input' ).find( ( i ) => i.getAttribute( 'value' ) === valor ),
		/** Marca una casilla como lo haría el cliente, y espera al swap. */
		async marcar( valor, { con, respuesta } ) {
			estado.respuesta = respuesta;
			const input = root.querySelectorAll( '[data-akb-facets] input' ).find( ( i ) => i.getAttribute( 'value' ) === valor );
			if ( 'teclado' === con ) {
				input.focus();
				disparar( document, input, 'keydown', { key: ' ' } );
			} else {
				disparar( document, input.closest( 'label' ), 'pointerdown', { button: 0, pointerType: 'mouse', clientX: 10, clientY: 10 } );
			}
			input.checked = ! input.checked;
			disparar( document, input, 'change' );
			await esperar();
		},
		/** Cambia el orden (el `select` de WooCommerce), y espera al swap. */
		async ordenar( { con, respuesta } ) {
			estado.respuesta = respuesta;
			const select = root.querySelector( '.woocommerce-ordering select.orderby' );
			if ( 'teclado' === con ) {
				select.focus();
				disparar( document, select, 'keydown', { key: 'ArrowDown' } );
			} else {
				disparar( document, select, 'pointerdown', { button: 0, pointerType: 'mouse' } );
			}
			select.setAttribute( 'value', 'price' );
			disparar( document, select, 'change' );
			await esperar();
		},
		esperar,
	};
}

// Las opciones de scrollIntoView nacen en el contexto del bundle (otro realm):
// se copian con `{ ... }` antes de compararlas, o deepEqual las ve distintas.

/** Qué quedó enfocado, legible en el mensaje de la aserción. */
const enfocado = ( doc ) => {
	const el = doc.activeElement;
	return `${ el.tagName }${ el.getAttribute( 'name' ) ? '[name=' + el.getAttribute( 'name' ) + ']' : '' }${ el.getAttribute( 'value' ) ? '[value=' + el.getAttribute( 'value' ) + ']' : '' }${ el.getAttribute( 'class' ) ? '.' + el.getAttribute( 'class' ) : '' }`;
};

test( 'con teclado, marcar una casilla deja el foco en ESA casilla (la nueva, tras el swap) y no mueve la página', async () => {
	const t = arrancar();
	const vieja = t.casilla( 'instock' );

	await t.marcar( 'instock', { con: 'teclado', respuesta: pagina( { total: 3, marcadas: [ 'instock' ] } ) } );

	assert.deepEqual( t.estado.navegaciones, [ '/tienda/?_disponibilidad=instock' ] );
	const nueva = t.casilla( 'instock' );
	assert.notEqual( nueva, vieja, 'El formulario se reemplazó: la casilla vieja ya no está en la página.' );
	assert.equal( t.document.activeElement, nueva, `El foco quedó en ${ enfocado( t.document ) }.` );
	assert.equal( nueva.checked, true );
	assert.deepEqual( mundo.desplazamientos, [], 'Nada sube la página al inicio de los resultados.' );
	assert.equal( t.document.getElementById( 'akb-live-region' ).textContent, '3 productos', 'El total se anuncia en la región viva.' );
	assert.deepEqual( t.estado.recargas, [] );
} );

test( 'con teclado, el segundo filtro también conserva el foco', async () => {
	const t = arrancar();

	await t.marcar( 'instock', { con: 'teclado', respuesta: pagina( { total: 3, marcadas: [ 'instock' ] } ) } );
	await t.marcar( 'agotado', { con: 'teclado', respuesta: pagina( { total: 5, marcadas: [ 'instock', 'agotado' ] } ) } );

	assert.deepEqual( t.estado.navegaciones, [ '/tienda/?_disponibilidad=instock', '/tienda/?_disponibilidad=instock%2Cagotado' ] );
	assert.equal( t.document.activeElement, t.casilla( 'agotado' ), `El foco quedó en ${ enfocado( t.document ) }.` );
} );

test( 'si la casilla ya no existe tras el swap, el foco va a la rejilla (no se pierde en <body>)', async () => {
	const t = arrancar();

	await t.marcar( 'instock', { con: 'teclado', respuesta: pagina( { total: 3, marcadas: [], opciones: [ 'agotado' ] } ) } );

	assert.equal( t.document.activeElement, t.root.querySelector( 'ul.products' ), `El foco quedó en ${ enfocado( t.document ) }.` );
	assert.equal( mundo.desplazamientos.length, 1 );
} );

test( 'con el ratón, como siempre: foco a la rejilla y la página sube al total', async () => {
	const t = arrancar();

	await t.marcar( 'instock', { con: 'raton', respuesta: pagina( { total: 3, marcadas: [ 'instock' ] } ) } );

	assert.equal( t.document.activeElement, t.root.querySelector( 'ul.products' ), `El foco quedó en ${ enfocado( t.document ) }.` );
	assert.equal( mundo.desplazamientos.length, 1 );
	assert.equal( mundo.desplazamientos[ 0 ].nodo, t.root.querySelector( '.akb-result-count' ) );
	assert.deepEqual( { ...mundo.desplazamientos[ 0 ].opts }, { block: 'start', behavior: 'smooth' } );
} );

test( 'la subida al total corre DESPUÉS del cuadro en que el tema refresca ScrollTrigger (si no, el refresh la anula)', async () => {
	// El tema (animations.js) responde a `akb:pjax` con ScrollTrigger.refresh()
	// en un requestAnimationFrame, y el refresh devuelve el scroll a donde
	// estaba: medido en el espejo, marcar un filtro con el ratón desde abajo
	// dejaba la página quieta. Aquí el «tema» anota cuántos desplazamientos
	// había cuando le tocó refrescar: tiene que ser ninguno.
	const cuadros = [];
	const t = arrancar( { cuadros } );
	const alRefrescar = [];
	t.document.addEventListener( 'akb:pjax', () => cuadros.push( () => alRefrescar.push( mundo.desplazamientos.length ) ) );

	await t.marcar( 'instock', { con: 'raton', respuesta: pagina( { total: 3, marcadas: [ 'instock' ] } ) } );
	assert.deepEqual( mundo.desplazamientos, [], 'Nada se desplaza antes del cuadro siguiente.' );
	while ( cuadros.length ) {
		cuadros.shift()();
	}

	assert.deepEqual( alRefrescar, [ 0 ], 'El refresh del tema corre antes de la subida.' );
	assert.equal( mundo.desplazamientos.length, 1 );
	assert.equal( mundo.desplazamientos[ 0 ].nodo, t.root.querySelector( '.akb-result-count' ) );
	assert.equal( t.document.activeElement, t.root.querySelector( 'ul.products' ), 'El foco no espera al cuadro: va a la rejilla en el acto.' );
} );

test( 'con movimiento reducido la página SALTA al total: behavior auto, nunca smooth', async () => {
	const t = arrancar( { reducirMovimiento: true } );

	await t.marcar( 'instock', { con: 'raton', respuesta: pagina( { total: 3, marcadas: [ 'instock' ] } ) } );

	assert.equal( mundo.desplazamientos.length, 1 );
	assert.deepEqual( { ...mundo.desplazamientos[ 0 ].opts }, { block: 'start', behavior: 'auto' } );
} );

test( 'cambiar el orden con teclado deja el foco en el selector de orden', async () => {
	const t = arrancar();

	await t.ordenar( { con: 'teclado', respuesta: pagina( { total: 24 } ) } );
	assert.equal( t.document.activeElement, t.root.querySelector( '.woocommerce-ordering select.orderby' ), `El foco quedó en ${ enfocado( t.document ) }.` );
	assert.deepEqual( mundo.desplazamientos, [] );

	const r = arrancar();
	await r.ordenar( { con: 'raton', respuesta: pagina( { total: 24 } ) } );
	assert.equal( r.document.activeElement, r.root.querySelector( 'ul.products' ), 'Con el ratón, a la rejilla como siempre.' );
} );

test( 'el botón del cajón dice «producto» con 1 y «productos» con 0 o con N', async () => {
	const t = arrancar( { movil: true, config: { noun: { one: 'producto', other: 'productos' } } } );
	disparar( t.document, t.root.querySelector( '[data-akb-drawer-open]' ), 'click' );
	const boton = () => t.root.querySelector( '.akb-facets-apply' ).textContent;

	for ( const [ total, texto ] of [ [ 1, 'Ver 1 producto' ], [ 0, 'Ver 0 productos' ], [ 1234, 'Ver 1.234 productos' ], [ 1, 'Ver 1 producto' ] ] ) {
		t.estado.conteo = total;
		const input = t.casilla( 'instock' );
		input.checked = ! input.checked;
		disparar( t.document, input, 'change' );
		await t.esperar();
		assert.equal( boton(), texto, `Con ${ total }.` );
	}
	assert.deepEqual( t.estado.navegaciones, [], 'En el cajón los cambios se acumulan: el conteo no recarga la rejilla.' );
} );

test( 'sin las dos formas en la configuración (HTML anterior) la palabra queda como la pintó el servidor', async () => {
	const t = arrancar( { movil: true } );
	disparar( t.document, t.root.querySelector( '[data-akb-drawer-open]' ), 'click' );

	t.estado.conteo = 1;
	const input = t.casilla( 'instock' );
	input.checked = true;
	disparar( t.document, input, 'change' );
	await t.esperar();

	assert.equal( t.root.querySelector( '.akb-facets-apply' ).textContent, 'Ver 1 productos' );
} );
