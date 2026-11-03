'use strict';

/**
 * La salida del aviso cuando son los FILTROS los que dejan la página en cero
 * conserva lo que el cliente escribió (F-S-1, revisión del 2026-09-25).
 *
 * /tienda/?_buscar=frieren&_genero=terror: «frieren» tiene 15 tomos y el
 * género los deja en cero. El aviso ya decía «Ningún producto coincide con
 * estos filtros.», pero su botón era el mismo «limpia todo» de la búsqueda sin
 * resultados: desmarcaba los filtros Y quitaba `_buscar`, y el cliente
 * aterrizaba en /tienda/ con 1.625 productos y sin su búsqueda. Medido en el
 * espejo con Chromium y WebKit.
 *
 * Ahora ese aviso lleva `data-akb-clear="filtros"`: quita los filtros y deja la
 * búsqueda. El «limpia todo» de la búsqueda sin resultados y el «Limpiar todo»
 * de la barra de chips siguen quitándolo todo, con el formulario de filtros en
 * la página y sin él (los archivos de serie o autor no lo pintan).
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

/**
 * El aviso de cero como lo imprime `akibara_gp_akb_print_empty_state()`.
 *
 * @param {string|null} limpiar Valor de `data-akb-clear` del botón de la pista
 *                              (`''` = sin valor, el de «limpia todo»).
 */
const aviso = ( limpiar ) => h(
	'div',
	{ class: 'akb-empty-state', 'data-akb-empty': '' },
	h( 'p', { class: 'akb-empty-title' }, 'Ningún producto coincide con estos filtros.' ),
	h( 'p', { class: 'akb-empty-hint' }, h( 'button', { type: 'button', class: 'akb-link', 'data-akb-clear': limpiar }, 'el botón' ) )
);

/**
 * Página de un listado en cero: chips, aviso y, si `conFormulario`, el
 * formulario de filtros con la búsqueda y el orden en ocultos y el filtro
 * marcado.
 */
function pagina( { limpiar, conFormulario } ) {
	const main = h(
		'main',
		{},
		h( 'h1', { class: 'page-title' }, 'Tienda' ),
		h( 'div', { class: 'akb-shop-toolbar' }, h( 'p', { class: 'akb-result-count' }, '0 productos' ) ),
		h(
			'div',
			{ class: 'akb-facet-chips', 'data-akb-chips': '' },
			h( 'button', { type: 'button', class: 'akb-chip', 'data-facet': 'buscar', 'data-value': 'frieren' } ),
			h( 'button', { type: 'button', class: 'akb-chip', 'data-facet': 'genero', 'data-value': 'terror' } ),
			h( 'button', { type: 'button', class: 'akb-chip akb-chip--clear', 'data-akb-clear': '' }, 'Limpiar todo' )
		),
		aviso( limpiar )
	);
	if ( conFormulario ) {
		main.appendChild(
			h(
				'form',
				{ id: 'akb-facets-drawer', class: 'akb-facets', method: 'get', action: '/tienda/', 'data-akb-facets': '' },
				h( 'input', { type: 'hidden', name: '_buscar', value: 'frieren' } ),
				h( 'input', { type: 'hidden', name: 'orderby', value: 'price' } ),
				h(
					'div',
					{ class: 'akb-facets-body' },
					h(
						'section',
						{ class: 'akb-facet', 'data-facet': 'genero' },
						h(
							'ul',
							{ class: 'akb-facet-list' },
							h( 'li', { class: 'akb-facet-item' }, h( 'label', {}, h( 'input', { type: 'checkbox', name: '_genero[]', value: 'terror', checked: '' } ) ) ),
							h( 'li', { class: 'akb-facet-item' }, h( 'label', {}, h( 'input', { type: 'checkbox', name: '_genero[]', value: 'accion' } ) ) )
						)
					)
				)
			)
		);
	}
	const root = h( 'html', {}, h( 'body', {}, main ) );
	root.title = 'Tienda';
	return root;
}

/**
 * Arranca el bundle sobre la página y devuelve qué pidió al servidor al pulsar.
 *
 * @param {object} o
 * @param {string} o.ruta La barra de direcciones: ruta y consulta.
 */
function arrancar( { limpiar, conFormulario, ruta } ) {
	mundo.reiniciar();
	const root = pagina( { limpiar, conFormulario } );
	const document = crearDocumento( root, 'complete' );
	const url = new URL( ruta, 'https://akibara.test' );
	const estado = { navegaciones: [], historial: [], recargas: [] };
	const sandbox = {
		AbortController,
		Event,
		URL,
		URLSearchParams,
		Intl,
		FormData: FormDataFalso,
		AKB_FACETS: { countUrl: '/wp-json/akibara/v1/search/count', mobileMax: 768 },
		localStorage: { getItem: () => null, setItem: () => {} },
		// La respuesta del servidor: la misma página, ya sin nada que medir.
		DOMParser: class {
			parseFromString() {
				return pagina( { limpiar, conFormulario } );
			}
		},
		addEventListener: () => {},
		removeEventListener: () => {},
		clearTimeout: () => {},
		setTimeout: ( fn ) => {
			fn();
			return 1;
		},
		matchMedia: () => ( { matches: false } ),
		document,
		fetch: ( pedida ) => {
			estado.navegaciones.push( pedida );
			return Promise.resolve( {
				ok: true,
				status: 200,
				headers: { get: ( n ) => ( 'content-type' === n.toLowerCase() ? 'text/html; charset=UTF-8' : null ) },
				text: () => Promise.resolve( '<html></html>' ),
			} );
		},
		history: { pushState: ( _estado, _titulo, destino ) => estado.historial.push( destino ) },
		location: { href: url.href, pathname: url.pathname, search: url.search, assign: ( u ) => estado.recargas.push( u ) },
	};
	sandbox.window = sandbox;
	vm.runInNewContext( bundle, sandbox );

	return {
		estado,
		/** Pulsa un botón como el cliente y espera al intercambio. */
		async pulsar( selector ) {
			disparar( document, root.querySelector( selector ), 'click' );
			for ( let i = 0; i < 20; i++ ) {
				await new Promise( ( r ) => setImmediate( r ) );
			}
		},
	};
}

test( 'con el formulario en la página, el botón del aviso de «filtros» quita el filtro y conserva la búsqueda', async () => {
	const t = arrancar( { limpiar: 'filtros', conFormulario: true, ruta: '/tienda/?_buscar=frieren&_genero=terror&orderby=price' } );

	await t.pulsar( '[data-akb-empty] [data-akb-clear]' );

	assert.deepEqual( t.estado.navegaciones, [ '/tienda/?_buscar=frieren&orderby=price' ], 'Antes pedía /tienda/?orderby=price: la búsqueda se perdía.' );
	assert.deepEqual( t.estado.historial, [ '/tienda/?_buscar=frieren&orderby=price' ], 'La URL que queda en la barra (y a la que vuelve Adelante) conserva `_buscar`.' );
	assert.deepEqual( t.estado.recargas, [] );
} );

test( 'sin el formulario (archivo de serie o autor), el botón del aviso de «filtros» también conserva la búsqueda y el orden', async () => {
	const t = arrancar( { limpiar: 'filtros', conFormulario: false, ruta: '/serie/frieren/?_buscar=frieren&_genero=terror&_editorial=ivrea&orderby=price' } );

	await t.pulsar( '[data-akb-empty] [data-akb-clear]' );

	assert.deepEqual( t.estado.navegaciones, [ '/serie/frieren/?_buscar=frieren&orderby=price' ] );
} );

test( 'el «limpia todo» de la búsqueda sin resultados sigue quitándolo todo, con formulario y sin él', async () => {
	const conFormulario = arrancar( { limpiar: '', conFormulario: true, ruta: '/tienda/?_buscar=gundam&_genero=terror&orderby=price' } );
	await conFormulario.pulsar( '[data-akb-empty] [data-akb-clear]' );
	assert.deepEqual( conFormulario.estado.navegaciones, [ '/tienda/?orderby=price' ], 'Lo escrito no encuentra nada: conservarlo dejaría otro cero.' );

	const sinFormulario = arrancar( { limpiar: '', conFormulario: false, ruta: '/serie/frieren/?_buscar=gundam&_genero=terror&orderby=price' } );
	await sinFormulario.pulsar( '[data-akb-empty] [data-akb-clear]' );
	assert.deepEqual( sinFormulario.estado.navegaciones, [ '/serie/frieren/?orderby=price' ] );
} );

test( 'el «Limpiar todo» de la barra de chips quita también la búsqueda, aunque el aviso sea de «filtros»', async () => {
	const t = arrancar( { limpiar: 'filtros', conFormulario: true, ruta: '/tienda/?_buscar=frieren&_genero=terror&orderby=price' } );

	await t.pulsar( '[data-akb-chips] [data-akb-clear]' );

	assert.deepEqual( t.estado.navegaciones, [ '/tienda/?orderby=price' ], 'Su nombre promete TODO: la búsqueda es un chip más.' );
} );
