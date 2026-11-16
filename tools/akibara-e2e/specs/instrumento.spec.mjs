// @ts-check
/**
 * El instrumento mismo: las ayudas de `soporte/tienda.mjs` que deciden qué es un
 * rojo y qué no puede salir del navegador.
 *
 * Ninguna prueba de aquí toca la tienda: cada página y cada script se contesta
 * con `page.route`, en un origen inventado. Valen igual en el espejo y fuera.
 *
 *  - E2E-6 (verificación de producción, 2026-09-26): un error de gtag o de
 *    Clarity —«Cannot read properties of null (reading 'sequence')»— puso en rojo
 *    una prueba del canalón sin nada roto en la tienda, y sin pila para saber de
 *    quién era. `erroresDePagina` ignora lo que nace en esos dos dominios y anota
 *    la pila de todo.
 *  - E2E-2: las pruebas del formulario de contacto sólo no escribían mientras la
 *    validación funcionara. `cortarEnviosDeContacto` corta sus dos salidas.
 *  - REV-SE-1: el presupuesto de cargas (`soporte/presupuesto-de-cargas.mjs`)
 *    desaparecía sin aviso en cuanto la corrida pasaba `--reporter`, que reemplaza
 *    la lista de la config; cargado a mano por ahí quedaba «sin tope»; y un
 *    `AKB_TOPE_CARGAS` que no fuera un entero también lo apagaba. Se corre
 *    Playwright de verdad, con una selección vacía y contra un origen inventado:
 *    `soporte/destino.mjs` decide antes de cargar ninguna prueba.
 *  - REV-SE-3: cinco specs prometían correr en `npm run test:prod` sin ninguna
 *    prueba `@prod`, que es lo único que ese script corre.
 *  - REV2-SE-1: el detector sólo reconocía el método en mayúsculas, y Playwright
 *    titula el paso con el método como lo escribe la prueba: `{ method: 'post' }`
 *    salía como POST sin contar como escritura. Los títulos se arman aquí con el
 *    mismo formateador de Playwright, no a mano.
 *  - REV2-SE-2 y REV-SE-2f-1: una corrida rechazada ya había vaciado
 *    `test-results` y reescrito el informe HTML, y `--list` también lo reescribía.
 *  - REV-C-E-1o-1 / REV-CE-1f-3: `{ method: 'Trace' }` sale como TRACE y se
 *    titulaba como `tracing.group()`, así que no contaba; y un cambio del catálogo
 *    de títulos callaba al detector sin romper nada.
 *  - REV-C-E-1o-3 / REV-CE-1f-1 / REV-CE-1f-2: la línea de comandos se leía en
 *    crudo (`-- --list` y `--grep --list` pasaban por «en seco»), el `--list`
 *    fuera del espejo se rechazaba sin tocar el sitio, y `--reporter=` vacío se
 *    rechazaba aunque Playwright corriera con el presupuesto de la config.
 *  - REV-C-E-1o-2: `npm run report` abre la carpeta que escribe la config; la
 *    ruta está en dos ficheros y nada vigilaba que coincidieran.
 */

import { execFile } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { expect, test } from '@playwright/test';
import { renderTitleForCall } from 'playwright-core/lib/utils';
import { enSecoDeLaLinea, reportersDeLaLinea } from './soporte/destino.mjs';
import PresupuestoDeCargas, { NO_PIDEN } from './soporte/presupuesto-de-cargas.mjs';
import { cortarEnviosDeContacto, erroresDePagina } from './soporte/tienda.mjs';

const ORIGEN = 'https://tienda.akb-e2e.test';

/** Contesta una ruta con un cuerpo fijo, sin salir a la red. Los mensajes van sin tildes: WebKit lee el script en Latin-1 aunque se declare UTF-8. */
const contestar = ( page, url, tipo, body ) => page.route( url, ( r ) => r.fulfill( { contentType: `${ tipo }; charset=utf-8`, body } ) );

test.describe( 'instrumento', () => {
	test.beforeEach( ( {}, info ) => {
		test.skip( 'movil' === info.project.name, 'no depende del tamaño de pantalla' );
	} );

	test( 'un error que nace en gtag o en Clarity no cuenta; uno de la tienda sí, aunque lo llame gtag o nombre su URL', async ( { page } ) => {
		const errores = erroresDePagina( page );
		const propio = `${ ORIGEN }/wp-content/themes/akibara-gp/assets/js/akb-e2e-propio.js`;
		const gtag = 'https://www.googletagmanager.com/gtag/js?id=G-AKBE2E';
		const clarity = 'https://scripts.clarity.ms/0.8.70/clarity.js';

		await contestar( page, `${ ORIGEN }/`, 'text/html', `<!doctype html><title>e2e</title>
			<script src="${ propio }"></script><script src="${ gtag }"></script><script src="${ clarity }"></script>` );
		await contestar( page, propio, 'text/javascript', `
			window.akbE2ePropio = function () { throw new Error( 'de la tienda, llamado desde gtag' ); };
			setTimeout( function laTienda() { throw new Error( 'de la tienda' ); }, 0 );
			setTimeout( function nombraAGtag() { throw new Error( 'no cargo ${ gtag }' ); }, 0 );` );
		await contestar( page, gtag, 'text/javascript', `
			setTimeout( function gtagDeMentira() { var s = null; return s.sequence; }, 0 );
			setTimeout( function () { window.akbE2ePropio(); }, 0 );` );
		await contestar( page, clarity, 'text/javascript', 'setTimeout( function clarityDeMentira() { var s = null; return s.sequence; }, 0 );' );

		await page.goto( `${ ORIGEN }/` );
		const anotados = () => test.info().annotations.filter( ( a ) => a.type.startsWith( 'pageerror' ) );
		await expect.poll( () => anotados().length, { message: 'los cinco errores llegaron' } ).toBe( 5 );

		expect( errores ).toHaveLength( 3 );
		for ( const texto of [ 'de la tienda, llamado desde gtag', 'de la tienda —', `no cargo ${ gtag }` ] ) {
			const error = errores.find( ( e ) => e.includes( texto ) );
			expect( error, `«${ texto }» cuenta` ).toBeTruthy();
			expect( error, 'y dice dónde nació' ).toContain( propio );
		}
		const ignorados = anotados().filter( ( a ) => 'pageerror de terceros (ignorado)' === a.type ).map( ( a ) => String( a.description ) );
		expect( ignorados, 'los dos de terceros quedan anotados, con su pila' ).toHaveLength( 2 );
		expect( ignorados.some( ( p ) => p.includes( 'googletagmanager.com' ) ) ).toBe( true );
		expect( ignorados.some( ( p ) => p.includes( 'clarity.ms' ) ) ).toBe( true );
	} );

	test( 'el formulario de contacto no sale ni por el REST ni por el envío nativo a admin-post.php', async ( { page } ) => {
		const salidas = [];
		page.on( 'request', ( r ) => {
			if ( /akibara\/v1\/contacto|admin-post\.php/.test( r.url() ) ) {
				salidas.push( r.method() );
			}
		} );
		const cortados = await cortarEnviosDeContacto( page );
		await contestar( page, `${ ORIGEN }/contacto/`, 'text/html', `<!doctype html><title>e2e</title>
			<form method="post" action="${ ORIGEN }/wp-admin/admin-post.php"><input name="akb_nombre" value="QA"><button>Enviar</button></form>` );

		await page.goto( `${ ORIGEN }/contacto/` );
		// El camino del JS: un fetch al REST.
		await page.evaluate( ( u ) => fetch( u, { method: 'POST', body: '{}' } ).catch( () => {} ), `${ ORIGEN }/wp-json/akibara/v1/contacto` );
		// Y el de cuando ese JS no corrió: el envío nativo del formulario.
		await page.locator( 'button' ).click();

		await expect.poll( () => cortados.length ).toBe( 2 );
		expect( cortados[ 0 ] ).toBe( `POST ${ ORIGEN }/wp-json/akibara/v1/contacto` );
		expect( cortados[ 1 ] ).toBe( `POST ${ ORIGEN }/wp-admin/admin-post.php` );
		expect( salidas, 'las pruebas lo notan: el evento `request` sale igual' ).toEqual( [ 'POST', 'POST' ] );
	} );
} );

/** El directorio de la suite (donde está playwright.config.mjs). */
const SUITE = new URL( '../', import.meta.url ).pathname;

/**
 * Corre Playwright aparte, como desde la terminal en tools/akibara-e2e.
 *
 * Con un entorno limpio de lo de esta corrida (las `AKB_*` y lo que Playwright
 * pasa a sus workers) más lo pedido, y SIEMPRE con `--output` propio y con
 * `--reporter` propio sin html o el informe HTML desviado
 * (`PLAYWRIGHT_HTML_OUTPUT_DIR`): sin eso escribiría el informe HTML y borraría
 * `test-results` de la corrida que lo lanza. La línea se lee como la lee
 * Playwright: un `--reporter=` vacío deja los reporters de la config, html incluido.
 *
 * @param {string[]} argumentos Tras `playwright test`.
 * @param {Record<string, string>} entorno Variables de la corrida.
 * @returns {Promise<{ codigo: number, salida: string, stdout: string }>} Código de salida, todo lo impreso y sólo la salida estándar.
 */
function playwright( argumentos, entorno ) {
	const pedidos = reportersDeLaLinea( [ process.execPath, 'cli.js', 'test', ...argumentos ], SUITE );
	const informePropio = Boolean( entorno.PLAYWRIGHT_HTML_OUTPUT_DIR ) || ( undefined !== pedidos && ! pedidos.includes( 'html' ) );
	if ( ! informePropio || ! argumentos.includes( '--output' ) ) {
		throw new Error( 'playwright(): siempre con --output propio, y con --reporter propio sin html o PLAYWRIGHT_HTML_OUTPUT_DIR' );
	}
	const limpio = Object.fromEntries( Object.entries( process.env ).filter( ( [ k ] ) => ! /^(AKB_|TEST_|PW_|PWTEST_|PLAYWRIGHT_)/.test( k ) ) );
	return new Promise( ( listo ) => {
		execFile(
			process.execPath,
			[ 'node_modules/@playwright/test/cli.js', 'test', ...argumentos ],
			{ cwd: SUITE, env: { ...limpio, ...entorno }, timeout: 50_000, maxBuffer: 64 * 1024 * 1024 },
			( error, stdout, stderr ) => listo( { codigo: error ? Number( error.code ) || 1 : 0, salida: `${ stdout }${ stderr }`, stdout } )
		);
	} );
}

/**
 * El título de un paso de la API, armado como lo arma Playwright (el mismo
 * formateador que usa `playwright/lib/index.js` para los pasos `pw:api`).
 *
 * @param {string} tipo Canal («Frame», «APIRequestContext»…).
 * @param {string} metodo Método del canal («goto», «fetch»…).
 * @param {Record<string, string>} params Parámetros tal como los manda la prueba.
 * @returns {string} El título.
 */
const tituloDelPaso = ( tipo, metodo, params ) => renderTitleForCall( { type: tipo, method: metodo, params } );

/** `request.fetch( URL, { method } )`; sin `method`, como lo deja la prueba que no lo pone. */
const pedir = ( /** @type {string|undefined} */ method ) => tituloDelPaso( 'APIRequestContext', 'fetch', { url: `${ ORIGEN }/wp-json/akibara/v1/contacto`, ...( undefined === method ? {} : { method } ) } );

/** `page.goto( '/tienda/' )`. */
const NAVEGAR = tituloDelPaso( 'Frame', 'goto', { url: '/tienda/' } );

/**
 * Pasos de la API de una prueba, como los ve el reporter.
 *
 * @param {PresupuestoDeCargas} reporter
 * @param {number} cuantos Pasos.
 * @param {string} titulo Título del paso («Navigate to "/"», «POST "…"»).
 */
function pasos( reporter, cuantos, titulo ) {
	const prueba = /** @type {any} */ ( { parent: { project: () => ( { name: 'escritorio' } ) }, titlePath: () => [ '', 'escritorio', 'x.spec.mjs', 'd', 'a' ] } );
	for ( let i = 0; i < cuantos; i++ ) {
		reporter.onStepBegin( prueba, /** @type {any} */ ( null ), /** @type {any} */ ( { category: 'pw:api', title: titulo } ) );
	}
}

/**
 * El desenlace de una corrida con `cargas` navegaciones y `escrituras` POST, con
 * `AKB_TOPE_CARGAS` puesto como se pide (undefined: sin la variable).
 *
 * @param {string|undefined} tope
 * @param {number} cargas
 * @param {number} [escrituras]
 * @returns {{ desenlace: { status: 'failed' } | undefined, impreso: string }}
 */
function corrida( tope, cargas, escrituras = 0 ) {
	const antes = process.env.AKB_TOPE_CARGAS;
	const log = console.log;
	/** @type {string[]} */
	const impreso = [];
	try {
		if ( undefined === tope ) {
			delete process.env.AKB_TOPE_CARGAS;
		} else {
			process.env.AKB_TOPE_CARGAS = tope;
		}
		// Como llega por `--reporter`: sin opciones.
		const reporter = new PresupuestoDeCargas();
		pasos( reporter, cargas, NAVEGAR );
		pasos( reporter, escrituras, pedir( 'POST' ) );
		console.log = ( ...a ) => impreso.push( a.join( ' ' ) );
		return { desenlace: reporter.onEnd(), impreso: impreso.join( '\n' ) };
	} finally {
		console.log = log;
		if ( undefined === antes ) {
			delete process.env.AKB_TOPE_CARGAS;
		} else {
			process.env.AKB_TOPE_CARGAS = antes;
		}
	}
}

test.describe( 'instrumento: presupuesto de cargas (REV-SE-1)', () => {
	test.beforeEach( ( {}, info ) => {
		test.skip( 'movil' === info.project.name, 'no depende del tamaño de pantalla' );
	} );

	test( 'el tope sale de AKB_TOPE_CARGAS aunque el reporter llegue sin opciones', () => {
		expect( corrida( undefined, 120 ).desenlace, 'sin la variable, 120 cargas caben' ).toBeUndefined();
		const pasado = corrida( undefined, 121 );
		expect( pasado.desenlace, 'y 121 son un rojo' ).toEqual( { status: 'failed' } );
		expect( pasado.impreso ).toContain( 'Cargas pedidas al sitio: 121 (tope 120)' );

		expect( corrida( '130', 130 ).desenlace ).toBeUndefined();
		expect( corrida( '130', 131 ).desenlace ).toEqual( { status: 'failed' } );

		const sinTope = corrida( '0', 500 );
		expect( sinTope.desenlace, '0 quita el tope: el permiso del dueño' ).toBeUndefined();
		expect( sinTope.impreso ).toContain( '(sin tope: AKB_TOPE_CARGAS=0)' );

		const escribe = corrida( '0', 1, 1 );
		expect( escribe.desenlace, 'una petición que escribe es un rojo aun sin tope' ).toEqual( { status: 'failed' } );
		expect( escribe.impreso ).toContain( 'POST "/wp-json/akibara/v1/contacto"' );
	} );

	test( 'una petición cuenta como carga, y como escritura si no es GET/HEAD, escriba la prueba el método como lo escriba (REV2-SE-1)', () => {
		/**
		 * Lo que el reporter anota de UN paso.
		 *
		 * @param {string} paso Título.
		 * @returns {{ cargas: number, escrituras: string[] }}
		 */
		const anota = ( paso ) => {
			const reporter = new PresupuestoDeCargas();
			pasos( reporter, 1, paso );
			return { cargas: [ ...reporter.porPrueba.values() ].reduce( ( a, b ) => a + b, 0 ), escrituras: reporter.escrituras };
		};

		// El servidor de Playwright manda `method.toUpperCase()`, y sin método, GET (server/fetch.js).
		// `Trace` se titula como `tracing.group()` y aun así sale TRACE (REV-C-E-1o-1).
		for ( const [ method, sale ] of [
			[ 'POST', 'POST' ], [ 'post', 'POST' ], [ 'Delete', 'DELETE' ], [ 'patch', 'PATCH' ], [ 'Put', 'PUT' ],
			[ 'Trace', 'TRACE' ], [ 'TRACE', 'TRACE' ], [ 'trace', 'TRACE' ], [ 'Options', 'OPTIONS' ], [ 'connect', 'CONNECT' ],
		] ) {
			const { cargas, escrituras } = anota( pedir( method ) );
			expect( cargas, `${ method }: una carga` ).toBe( 1 );
			expect( escrituras, `${ method }: y una escritura` ).toHaveLength( 1 );
			expect( escrituras[ 0 ] ).toContain( pedir( method ) );
			if ( method !== sale ) {
				expect( escrituras[ 0 ], 'y dice cómo sale' ).toContain( `(sale como ${ sale })` );
			}
		}
		for ( const method of [ undefined, 'GET', 'get', 'HEAD', 'head' ] ) {
			expect( anota( pedir( method ) ), `${ String( method ) }: lee, una carga sin escritura` ).toEqual( { cargas: 1, escrituras: [] } );
		}
		expect( pedir( undefined ), 'sin método el título se queda con el molde' ).toMatch( /^\{method\} "/ );

		// Los pasos de la misma forma «Palabra "…"» que no piden nada: ni carga ni escritura.
		for ( const [ tipo, metodo, params ] of /** @type {[string, string, Record<string, string>][]} */ ( [
			[ 'Frame', 'fill', { value: 'hola', selector: '#a' } ],
			[ 'Frame', 'press', { key: 'Enter', selector: '#a' } ],
			[ 'Frame', 'type', { text: 'abc', selector: '#a' } ],
			[ 'Page', 'keyboardType', { text: 'abc' } ],
			[ 'Page', 'keyboardInsertText', { text: 'q' } ],
			[ 'Frame', 'dispatchEvent', { type: 'click', selector: 'button' } ],
			[ 'Frame', 'expect', { expression: 'to.be.visible' } ],
		] ) ) {
			const paso = tituloDelPaso( tipo, metodo, params );
			expect( anota( paso ), paso ).toEqual( { cargas: 0, escrituras: [] } );
		}

		// `tracing.group()` no pide nada, pero su título es el de un fetch con `method: 'Trace'`:
		// ante la duda, una escritura (una falsa alarma se ve; una escritura muda, no).
		const grupo = tituloDelPaso( 'Tracing', 'tracingGroup', { name: 'grupo' } );
		expect( grupo ).toBe( pedir( 'Trace' ).replace( /"[^"]*"/, '"grupo"' ) );
		expect( anota( grupo ).escrituras, 'tracing.group() cuenta como TRACE' ).toEqual( [ expect.stringContaining( '(sale como TRACE)' ) ] );
		expect( [ ...NO_PIDEN ].filter( ( p ) => /^(GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS|TRACE|CONNECT)$/i.test( p ) ), 'ninguna palabra de NO_PIDEN es un método de HTTP' ).toEqual( [] );
	} );

	test( 'si Playwright cambia los títulos con que cuenta el reporter, el reporter no carga (REV-C-E-1o-1)', async () => {
		/**
		 * Carga el reporter en un Node aparte, con el catálogo de Playwright tocado antes.
		 *
		 * @param {string} llamada Entrada del catálogo («APIRequestContext.fetch»…), o '' para no tocar nada.
		 * @param {string} titulo Título que se le pone.
		 * @returns {Promise<{ codigo: number, salida: string }>}
		 */
		const cargar = ( llamada, titulo ) => new Promise( ( listo ) => {
			const codigo = `import { methodMetainfo } from 'playwright-core/lib/utils';
				const [ llamada, titulo ] = JSON.parse( process.env.AKB_E2E_CATALOGO );
				if ( llamada ) { methodMetainfo.set( llamada, { ...methodMetainfo.get( llamada ), title: titulo } ); }
				await import( './specs/soporte/presupuesto-de-cargas.mjs' );
				console.log( 'cargó' );`;
			execFile(
				process.execPath,
				[ '--input-type=module', '-e', codigo ],
				{ cwd: SUITE, env: { ...process.env, AKB_E2E_CATALOGO: JSON.stringify( [ llamada, titulo ] ) }, timeout: 30_000 },
				( error, stdout, stderr ) => listo( { codigo: error ? Number( error.code ) || 1 : 0, salida: `${ stdout }${ stderr }` } )
			);
		} );

		const intacto = await cargar( '', '' );
		expect( intacto.codigo, intacto.salida.slice( -800 ) ).toBe( 0 );
		expect( intacto.salida ).toContain( 'cargó' );
		for ( const [ llamada, titulo ] of [
			[ 'APIRequestContext.fetch', 'Fetch "{url}"' ],
			[ 'Frame.goto', 'Go to "{url}"' ],
			[ 'Page.reload', 'Reload page' ],
		] ) {
			const roto = await cargar( llamada, titulo );
			expect( roto.codigo, `${ llamada } → ${ titulo }` ).not.toBe( 0 );
			expect( roto.salida ).toContain( `Playwright titula ${ llamada }` );
		}
	} );

	test( 'ninguna prueba pide con un método que se titula como un paso que no pide nada (REV-C-E-1o-1)', () => {
		const palabras = [ ...NO_PIDEN ].join( '|' );
		/**
		 * Lo que pide así en el código; los comentarios fuera, que ahí se explica el límite.
		 *
		 * @param {string} fuente
		 */
		const colisiones = ( fuente ) => fuente
			.replace( /\/\*[\s\S]*?\*\//g, '' )
			.replace( /(^|[^:'"`\\])\/\/.*$/gm, '$1' )
			.match( new RegExp( `\\bmethod\\s*:\\s*(['"\`])(${ palabras })\\1`, 'g' ) ) || [];
		// El control, armado por partes para que este fichero no se delate a sí mismo.
		const muestra = `request.fetch( url, { method: ${ "'" }Fill${ "'" } } )`;
		expect( colisiones( muestra ), 'la búsqueda ve un `method` con la grafía de un paso' ).toHaveLength( 1 );
		expect( colisiones( `const u = 'https://t.test/x'; ${ muestra }` ), 'también tras una URL en la misma línea' ).toHaveLength( 1 );
		expect( colisiones( muestra.replace( 'Fill', 'FILL' ) ), 'y no uno que sí se distingue (FILL cuenta como escritura)' ).toHaveLength( 0 );
		expect( colisiones( `// ${ muestra }\n/** ${ muestra } */` ), 'ni lo que sólo lo nombra en un comentario' ).toHaveLength( 0 );

		const fuentes = [ 'specs', 'specs/soporte' ].flatMap( ( carpeta ) => readdirSync( `${ SUITE }${ carpeta }` )
			.filter( ( f ) => f.endsWith( '.mjs' ) )
			.map( ( f ) => `${ carpeta }/${ f }` ) );
		expect( fuentes.length, 'se leen los specs y el soporte' ).toBeGreaterThan( 10 );
		const encontradas = fuentes.flatMap( ( f ) => colisiones( readFileSync( `${ SUITE }${ f }`, 'utf8' ) ).map( ( c ) => `${ f }: ${ c }` ) );
		expect( encontradas, 'el detector no las ve: escribir el método de HTTP de verdad' ).toEqual( [] );
	} );

	test( 'un AKB_TOPE_CARGAS que no es un entero ≥ 0 detiene la corrida en vez de quitar el tope', () => {
		for ( const malo of [ 'abc', '', ' ', '1.5', '-1', ' 120', '120 ', '12abc', '1e3', 'NaN', 'Infinity' ] ) {
			expect( () => corrida( malo, 1 ), JSON.stringify( malo ) ).toThrow( `AKB_TOPE_CARGAS=${ JSON.stringify( malo ) } no es un número de cargas` );
		}
	} );

	test( 'en sólo lectura, --reporter sin el presupuesto no deja correr; con él, sí', async ( {}, info ) => {
		// Una selección vacía: la guardia corre antes de cargar ninguna prueba, y con
		// su permiso Playwright termina sin haber pedido nada al origen inventado.
		const base = [ '--grep', 'ninguna-prueba-se-llama-asi', '--pass-with-no-tests', '--output', info.outputPath( 'sub' ) ];
		const soloLectura = { AKB_SOLO_LECTURA: '1', AKB_BASE_URL: 'https://tienda.akb-e2e.test' };

		const sin = await playwright( [ ...base, '--reporter=list,json' ], soloLectura );
		expect( sin.codigo, `--reporter=list,json no arranca en sólo lectura. Salida: ${ sin.salida.slice( -800 ) }` ).not.toBe( 0 );
		expect( sin.salida ).toContain( 'En sólo lectura la corrida lleva el presupuesto de cargas' );
		expect( sin.salida, 'y dice cómo añadirlo' ).toContain( '--reporter=list,json,./specs/soporte/presupuesto-de-cargas.mjs' );

		const con = await playwright( [ ...base, '--reporter=list,json,./specs/soporte/presupuesto-de-cargas.mjs' ], soloLectura );
		expect( con.codigo, `con el presupuesto en --reporter, sí. Salida: ${ con.salida.slice( -800 ) }` ).toBe( 0 );

		// Vacío, Playwright lo ignora y corre con la lista de la config, que en sólo lectura trae el presupuesto (REV-CE-1f-2).
		const vacio = await playwright( [ ...base, '--reporter=' ], { ...soloLectura, PLAYWRIGHT_HTML_OUTPUT_DIR: info.outputPath( 'informe' ) } );
		expect( vacio.codigo, `--reporter= deja la config, con el presupuesto. Salida: ${ vacio.salida.slice( -800 ) }` ).toBe( 0 );

		const mal = await playwright( [ ...base, '--reporter=list,./specs/soporte/presupuesto-de-cargas.mjs' ], { ...soloLectura, AKB_TOPE_CARGAS: 'abc' } );
		expect( mal.codigo, `AKB_TOPE_CARGAS=abc no arranca. Salida: ${ mal.salida.slice( -800 ) }` ).not.toBe( 0 );
		expect( mal.salida ).toContain( 'AKB_TOPE_CARGAS="abc" no es un número de cargas' );

		// Contra el espejo sin sólo lectura no hay presupuesto que exigir.
		const espejo = await playwright( [ ...base, '--reporter=list' ], { AKB_BASE_URL: 'http://localhost:8080' } );
		expect( espejo.codigo, `el espejo sin sólo lectura no exige presupuesto. Salida: ${ espejo.salida.slice( -800 ) }` ).toBe( 0 );
	} );

	test( 'la línea de comandos se lee como la lee Playwright: las dos formas de --reporter, el último gana, vacío no pide nada, nada tras «--»', () => {
		const cwd = '/suite';
		const linea = ( /** @type {string[]} */ ...a ) => reportersDeLaLinea( [ '/node', '/cli.js', 'test', ...a ], cwd );
		expect( linea( '--grep', '@prod' ) ).toBeUndefined();
		expect( linea( '--reporter=list,json' ) ).toEqual( [ 'list', 'json' ] );
		expect( linea( '--reporter', 'list,./specs/soporte/presupuesto-de-cargas.mjs' ) ).toEqual( [ 'list', '/suite/specs/soporte/presupuesto-de-cargas.mjs' ] );
		expect( linea( '--reporter=list,./a.mjs', '--reporter', 'dot' ), 'el último gana' ).toEqual( [ 'dot' ] );
		expect( linea( '--reporter=list', '--', '--reporter=json' ), 'lo de después de «--» no es una opción' ).toEqual( [ 'list' ] );
		expect( linea( '--reporter=' ), 'vacío: Playwright lo ignora y corre con la config (REV-CE-1f-2)' ).toBeUndefined();
		expect( linea( '--reporter', '' ), 'vacío, en dos palabras' ).toBeUndefined();
		expect( linea( '--grep', '--reporter=json' ), 'el valor de --grep no es una opción' ).toBeUndefined();
	} );

	test( '«en seco» es lo que Playwright lee como --list: ni tras «--» ni como valor de otra opción (REV-C-E-1o-3)', () => {
		const seco = ( /** @type {string[]} */ ...a ) => enSecoDeLaLinea( [ '/node', '/cli.js', 'test', ...a ] );
		for ( const a of [ [ '--list' ], [ '--list', '--grep', '@prod' ], [ '--grep', '@prod', '--list' ], [ 'catalogo', '--list' ], [ '--project', 'escritorio', 'movil', '--list' ], [ '-u', '--list' ] ] ) {
			expect( seco( ...a ), `${ a.join( ' ' ) }: en seco` ).toBe( true );
		}
		for ( const a of [ [], [ '--', '--list' ], [ '--reporter=list,json', '--', '--list' ], [ '--grep', '--list' ], [ '-g', '--list' ], [ '--grep-invert', '--list' ], [ '--project', '--list' ], [ '--output', '--list' ] ] ) {
			expect( seco( ...a ), `${ a.join( ' ' ) || '(nada)' }: de verdad` ).toBe( false );
		}
		// La interfaz y el modo vigilancia no miran --list: corren pruebas.
		for ( const a of [ [ '--list', '--ui' ], [ '--list', '--ui-port', '0' ], [ '--ui-host', 'localhost', '--list' ] ] ) {
			expect( seco( ...a ), `${ a.join( ' ' ) }: la interfaz corre pruebas` ).toBe( false );
		}
		const vigilancia = process.env.PWTEST_WATCH;
		try {
			process.env.PWTEST_WATCH = '1';
			expect( seco( '--list' ), 'PWTEST_WATCH: el modo vigilancia corre pruebas' ).toBe( false );
		} finally {
			if ( undefined === vigilancia ) {
				delete process.env.PWTEST_WATCH;
			} else {
				process.env.PWTEST_WATCH = vigilancia;
			}
		}
		expect( enSecoDeLaLinea( [ '/node', '/process.js' ] ), 'un worker no trae opciones' ).toBe( false );
		expect( enSecoDeLaLinea( [ '/node', '/cli.js', 'show-report', '--list' ] ), 'ni otra orden' ).toBe( false );
	} );
} );

test.describe( 'instrumento: una corrida rechazada o en seco no borra nada (REV2-SE-2, REV-SE-2f-1)', () => {
	test.beforeEach( ( {}, info ) => {
		test.skip( 'movil' === info.project.name, 'no depende del tamaño de pantalla' );
	} );

	/**
	 * Un `--output` con un testigo dentro, como el `test-results` de la última corrida de verdad.
	 *
	 * @param {string} carpeta
	 * @returns {string} La ruta del testigo.
	 */
	const conTestigo = ( carpeta ) => {
		mkdirSync( carpeta, { recursive: true } );
		const testigo = join( carpeta, 'error-context.md' );
		writeFileSync( testigo, 'la última corrida de verdad' );
		return testigo;
	};

	test( 'una corrida que la guardia rechaza no vacía test-results ni reescribe el informe HTML', async ( {}, info ) => {
		const salida = info.outputPath( 'sub' );
		const testigo = conTestigo( salida );
		const informe = info.outputPath( 'informe' );
		const base = [ '--grep', 'ninguna-prueba-se-llama-asi', '--pass-with-no-tests', '--output', salida ];

		// Sólo lectura con --reporter sin el presupuesto (REV2-SE-2): la corrida del orquestador.
		const sinPresupuesto = await playwright( [ ...base, '--reporter=list,json' ], { AKB_SOLO_LECTURA: '1', AKB_BASE_URL: ORIGEN } );
		expect( sinPresupuesto.codigo, sinPresupuesto.salida.slice( -800 ) ).not.toBe( 0 );
		expect( sinPresupuesto.salida ).toContain( 'En sólo lectura la corrida lleva el presupuesto de cargas' );
		expect( existsSync( testigo ), 'test-results sigue con lo de la última corrida' ).toBe( true );

		// Y con un «--list» que Playwright no lee como en seco (REV-C-E-1o-3): tras «--» es un
		// filtro de ficheros, y tras --grep, su valor. La corrida es de verdad; la guardia también.
		// (Sin ficheros que casen: un --grep «--list» elegiría las pruebas de aquí que lo nombran.)
		for ( const linea of [
			[ ...base, '--reporter=list,json', '--', '--list' ],
			[ 'ningun-fichero-se-llama-asi', '--grep', '--list', '--pass-with-no-tests', '--output', salida, '--reporter=list,json' ],
		] ) {
			const disfrazada = await playwright( linea, { AKB_SOLO_LECTURA: '1', AKB_BASE_URL: ORIGEN } );
			expect( disfrazada.codigo, `${ linea.join( ' ' ) }: ${ disfrazada.salida.slice( -800 ) }` ).not.toBe( 0 );
			expect( disfrazada.salida ).toContain( 'En sólo lectura la corrida lleva el presupuesto de cargas' );
			expect( existsSync( testigo ), `${ linea.join( ' ' ) }: test-results sigue con lo de la última corrida` ).toBe( true );
		}

		// Fuera del espejo sin sólo lectura, con los reporters de la config (REV-SE-2f-1).
		const fuera = await playwright( base, { AKB_BASE_URL: ORIGEN, PLAYWRIGHT_HTML_OUTPUT_DIR: informe } );
		expect( fuera.codigo, fuera.salida.slice( -800 ) ).not.toBe( 0 );
		expect( fuera.salida ).toContain( `${ ORIGEN } no es el espejo local` );
		expect( existsSync( testigo ), 'test-results sigue con lo de la última corrida' ).toBe( true );
		expect( existsSync( informe ), 'y el informe HTML no se reescribe con uno vacío' ).toBe( false );
	} );

	test( 'en seco (--list) el informe HTML no se toca, y en sólo lectura no pide el presupuesto', async ( {}, info ) => {
		const informe = info.outputPath( 'informe' );
		const soloLectura = { AKB_SOLO_LECTURA: '1', AKB_BASE_URL: ORIGEN };

		// `npm run test:prod -- --list`, tal cual: los reporters de la config.
		const enSeco = await playwright( [ '--list', '--grep', '@prod', '--output', info.outputPath( 'sub' ) ], { ...soloLectura, PLAYWRIGHT_HTML_OUTPUT_DIR: informe } );
		expect( enSeco.codigo, enSeco.salida.slice( -800 ) ).toBe( 0 );
		expect( enSeco.salida ).toMatch( /Total: \d+ tests? in \d+ files?/ );
		expect( existsSync( informe ), 'el informe de la última corrida sigue ahí' ).toBe( false );

		// Y con --reporter propio: en seco no corre ninguna prueba, no hace falta el presupuesto.
		const conLista = await playwright( [ '--list', '--grep', '@prod', '--reporter=list', '--output', info.outputPath( 'sub' ) ], soloLectura );
		expect( conLista.codigo, conLista.salida.slice( -800 ) ).toBe( 0 );

		// Ni importa a qué sitio apunte: listar no le pide nada (REV-CE-1f-1).
		const fuera = await playwright( [ '--list', '--grep', '@prod', '--output', info.outputPath( 'sub' ) ], { AKB_BASE_URL: ORIGEN, PLAYWRIGHT_HTML_OUTPUT_DIR: informe } );
		expect( fuera.codigo, `--list fuera del espejo sin AKB_SOLO_LECTURA. Salida: ${ fuera.salida.slice( -800 ) }` ).toBe( 0 );
		expect( fuera.salida ).toMatch( /Total: \d+ tests? in \d+ files?/ );
		expect( existsSync( informe ), 'y tampoco toca el informe' ).toBe( false );
	} );

	test( 'una corrida de verdad cuyo --grep es «--list» sí escribe su informe HTML (REV-C-E-1o-3)', async ( {}, info ) => {
		const informe = info.outputPath( 'informe' );
		// Contra el espejo y sin ficheros que casen: no le pide nada a ningún sitio.
		const corrida = await playwright( [ 'ningun-fichero-se-llama-asi', '--grep', '--list', '--pass-with-no-tests', '--output', info.outputPath( 'sub' ) ], { AKB_BASE_URL: 'http://localhost:8080', PLAYWRIGHT_HTML_OUTPUT_DIR: informe } );
		expect( corrida.codigo, corrida.salida.slice( -800 ) ).toBe( 0 );
		expect( existsSync( join( informe, 'index.html' ) ), 'la config no la tomó por «en seco»: lleva el reporter html' ).toBe( true );
	} );
} );

test.describe( 'instrumento: `npm run report` (C-E-DEUDA-1, REV-C-E-1o-2)', () => {
	test.beforeEach( ( {}, info ) => {
		test.skip( 'movil' === info.project.name, 'no depende del tamaño de pantalla' );
	} );

	test( 'abre la carpeta donde la config escribe el informe HTML', async () => {
		const { scripts } = JSON.parse( readFileSync( `${ SUITE }package.json`, 'utf8' ) );
		const [ , carpeta ] = /^playwright show-report (\S+)$/.exec( scripts.report ) || [];
		expect( carpeta, `sin carpeta, show-report busca ./playwright-report y dice «No report found»: ${ scripts.report }` ).toBeTruthy();

		// La config tal como la ve un worker: sin --list, con el reporter html.
		const { default: config } = await import( '../playwright.config.mjs' );
		const html = /** @type {[string, { outputFolder: string }][]} */ ( config.reporter ).find( ( [ nombre ] ) => 'html' === nombre );
		expect( html, 'la config escribe un informe HTML' ).toBeTruthy();
		// Las dos rutas son relativas a tools/akibara-e2e: npm corre el script ahí y ahí está la config.
		expect( resolve( SUITE, carpeta ), 'package.json y playwright.config.mjs nombran la misma carpeta' ).toBe( resolve( SUITE, html[ 1 ].outputFolder ) );
	} );
} );

test.describe( 'instrumento: lo que promete `npm run test:prod` (REV-SE-3)', () => {
	test.beforeEach( ( {}, info ) => {
		test.skip( 'movil' === info.project.name, 'no depende del tamaño de pantalla' );
	} );

	// Sin «@prod» en el título: `--grep @prod` también mira los títulos, y esta prueba
	// entraría en la selección que ella misma revisa.
	test( 'la marquesina corre en `npm run test:prod`, y ningún spec promete ese script sin pruebas etiquetadas', async ( {}, info ) => {
		// La selección que corre `npm run test:prod`, en seco (--list no toca el sitio).
		const lista = await playwright( [ '--list', '--grep', '@prod', '--reporter=json', '--output', info.outputPath( 'sub' ) ], {} );
		expect( lista.codigo, `--list. Salida: ${ lista.salida.slice( -800 ) }` ).toBe( 0 );
		/** @typedef {{ file: string, specs?: { title: string }[], suites?: Suite[] }} Suite */
		/** @type {{ suites: Suite[] }} */
		const informe = JSON.parse( lista.stdout );
		const conProd = new Set( informe.suites.map( ( s ) => s.file ) );
		/** @type {( s: Suite ) => string[]} */
		const titulos = ( s ) => [ ...( s.specs || [] ).map( ( p ) => p.title ), ...( s.suites || [] ).flatMap( titulos ) ];
		expect.soft(
			informe.suites.flatMap( titulos ).some( ( t ) => t.startsWith( 'con la marquesina en marcha' ) ),
			'la marquesina es la comprobación de después del despliegue (purgar LiteSpeed si falla)'
		).toBe( true );

		// Éste lo nombra para revisarlo, no porque corra ahí.
		const prometen = readdirSync( `${ SUITE }specs` )
			.filter( ( f ) => f.endsWith( '.spec.mjs' ) && basename( info.file ) !== f )
			.filter( ( f ) => /npm run test:prod(?!:)/.test( readFileSync( `${ SUITE }specs/${ f }`, 'utf8' ) ) );
		expect( prometen.filter( ( f ) => ! conProd.has( f ) ), 'prometen `npm run test:prod` sin ninguna prueba @prod: etiquetar o decir test:prod:completa' ).toEqual( [] );
	} );
} );
