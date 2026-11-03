'use strict';

/*
 * El guion que LeanCart deja en el <head> del carrito para Safari (revisiones
 * C-N, 2026-09-26).
 *
 * La propiedad que importa: donde hay `prefetch` (Chromium, Firefox) no hace
 * nada, porque ya están las pistas del <head>. Donde no (Safari), EN EL ACTO —no
 * tras `load`: el cliente puede irse a pagar antes (REV-CN-2o-1)—, cada recurso
 * que el carrito dejó de cargar se pide UNA vez con un `preload`, que no ejecuta
 * ni aplica nada, con el `crossorigin` de la etiqueta de la página de pago y
 * prioridad baja. Y va dentro de un marco oculto, no en el carrito: WebKit avisa
 * en la consola de cada `preload` sin usar de un documento que ya tenía alguno
 * al terminar de cargar (REV-CN-2o-2). Nada de `<script>` ni de `fetch()`:
 * WebKit no reutiliza la descarga de un `fetch()` para un `<script>` (medido en
 * Playwright 1.56, WebKit 2342).
 *
 * El guion vive en `LeanCart::WARMUP_JS`, dentro del PHP: se lee de ahí.
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const php = fs.readFileSync( require.resolve( '../../../src/Modules/SiteSpeed/LeanCart.php' ), 'utf8' );
const encontrado = php.match( /public const WARMUP_JS = '((?:[^'\\]|\\.)*)';/ );
assert.ok( encontrado, 'LeanCart::WARMUP_JS en el PHP' );
const plantilla = encontrado[ 1 ].replace( /\\(['\\])/g, '$1' );

const LISTA = [
	[ 'https://sdk.mercadopago.com/js/v2?ver=8.9.3', 'script', 'anonymous' ],
	[ 'https://akibara.cl/wp-content/plugins/woocommerce-mercadopago/assets/js/session.min.js?ver=8.9.3', 'script', null ],
	[ 'https://akibara.cl/a.css?ver=1&v=2', 'style', '' ],
];

/** Un documento de mentira: apunta qué se crea y qué se inserta en su <head>. */
function documento( nombre, prefetch, creados ) {
	const head = { insertados: [], appendChild: ( e ) => head.insertados.push( e ) };
	return {
		head,
		createElement: ( etiqueta ) => {
			creados.push( `${ nombre }:${ etiqueta }` );
			if ( 'iframe' === etiqueta ) {
				return { etiqueta, contentDocument: documento( 'marco', prefetch, creados ) };
			}
			assert.equal( etiqueta, 'link', 'sólo crea <link> y el marco: nada que se ejecute' );
			return { etiqueta, relList: { supports: ( rel ) => 'prefetch' === rel && prefetch } };
		},
	};
}

function entorno( { prefetch } ) {
	const creados = [];
	const escuchas = [];
	const temporizadores = [];
	const document = documento( 'carrito', prefetch, creados );
	const window = {
		document,
		addEventListener: ( tipo ) => escuchas.push( tipo ),
		setTimeout: () => temporizadores.push( 'setTimeout' ),
		requestIdleCallback: () => temporizadores.push( 'requestIdleCallback' ),
		fetch: () => assert.fail( 'nada de fetch()' ),
	};
	window.window = window;
	document.addEventListener = ( tipo ) => escuchas.push( tipo );

	vm.runInNewContext( plantilla.replace( '__LISTA__', JSON.stringify( LISTA ) ), window );

	return { document, creados, escuchas, temporizadores };
}

test( 'con prefetch (Chromium, Firefox) no hace nada: van las pistas del <head>', () => {
	const env = entorno( { prefetch: true } );
	assert.deepEqual( env.creados, [ 'carrito:link' ], 'sólo mira si hay prefetch' );
	assert.deepEqual( env.document.head.insertados, [] );
	assert.deepEqual( env.escuchas, [] );
	assert.deepEqual( env.temporizadores, [] );
} );

test( 'sin prefetch (Safari), en el acto: un marco oculto con un preload por recurso, su crossorigin y prioridad baja', () => {
	const env = entorno( { prefetch: false } );
	assert.deepEqual( env.escuchas, [], 'no espera a load ni a nada' );
	assert.deepEqual( env.temporizadores, [], 'ni lo aplaza' );

	assert.equal( env.document.head.insertados.length, 1, 'en el carrito sólo entra el marco' );
	const [ marco ] = env.document.head.insertados;
	assert.equal( marco.etiqueta, 'iframe' );
	assert.equal( marco.hidden, true, 'oculto' );
	assert.equal( marco.src, undefined, 'vacío: su documento termina de cargar sin ningún preload' );

	assert.deepEqual(
		marco.contentDocument.head.insertados.map( ( e ) => ( { rel: e.rel, as: e.as, href: e.href, crossOrigin: e.crossOrigin, fetchPriority: e.fetchPriority } ) ),
		[
			{ rel: 'preload', as: 'script', href: LISTA[ 0 ][ 0 ], crossOrigin: 'anonymous', fetchPriority: 'low' },
			{ rel: 'preload', as: 'script', href: LISTA[ 1 ][ 0 ], crossOrigin: undefined, fetchPriority: 'low' },
			{ rel: 'preload', as: 'style', href: LISTA[ 2 ][ 0 ], crossOrigin: '', fetchPriority: 'low' },
		],
		'sin crossorigin en la etiqueta, sin crossorigin en el link: el mismo modo de petición que la página de pago'
	);
	assert.deepEqual( env.creados.filter( ( c ) => c.startsWith( 'marco:' ) ), [ 'marco:link', 'marco:link', 'marco:link' ], 'los links se crean en el documento del marco' );
} );
