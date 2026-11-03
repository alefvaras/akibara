'use strict';

/*
 * Candados del autocomplete (assets/js/search-suggest.js).
 *
 * Van por INVARIANTE, no por literal de versión ni por número de línea: lo que
 * se fija es (1) que el panel no le prometa al lector de pantalla una opción
 * que no existe, y (2) que volver sobre un texto ya consultado no repita la
 * petición.
 *
 * Se prueba el FUENTE y no `search-suggest.min.js` a propósito: el bundle se
 * regenera con esbuild y su contenido es una derivada, no una decisión. El
 * candado del bundle publicado ya existe para el PJAX en
 * `search-runtime-contract.test.js`; duplicarlo acá pondría esta suite en rojo
 * ante cualquier orden de trabajo que toque el fuente antes de reempaquetar.
 */

const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const { join } = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const pluginRoot = join( __dirname, '..' );
const fuente = readFileSync( join( pluginRoot, 'assets/js/search-suggest.js' ), 'utf8' );

test( 'el estado vacío se anuncia, no se ofrece como opción del listbox', () => {
	// El nodo del vacío es inerte: no entra en state.items, no tiene id ni
	// data-idx, y setActive() sólo recorre `.akb-suggest-item`. Con
	// role="option" el lector anunciaba una opción que Enter jamás activa.
	assert.match( fuente, /class="akb-suggest-empty" role="status"/ );
	assert.doesNotMatch( fuente, /class="akb-suggest-empty" role="option"/ );

	// Las otras dos líneas inertes del panel ya usaban el mismo rol: el vacío
	// no puede volver a ser la excepción.
	assert.match( fuente, /class="akb-suggest-loading" role="status"/ );
	assert.match( fuente, /class="akb-suggest-correction" role="status"/ );

	// Y el listbox tiene nombre accesible (hay más de un combobox por página).
	assert.match( fuente, /panel\.setAttribute\( 'aria-label', '[^']+' \)/ );

	// La salida con teclado del vacío sigue siendo el submit nativo: sin
	// options el keydown sale antes de tocar las flechas o Enter.
	assert.match( fuente, /if \( ! n \) \{\s*\n\s*return;/ );
} );

/**
 * Respuesta HTTP no-200 para la cola de `montar()`. Un cuerpo string modela una
 * página HTML (su `.json()` falla, como el de un 503 del hosting o del borde).
 *
 * @param {number} status    Código HTTP.
 * @param {object|string} cuerpo Payload JSON o texto.
 * @param {object} cabeceras Cabeceras de la respuesta.
 * @return {object}
 */
function http( status, cuerpo, cabeceras ) {
	return { __http: true, status: status, cuerpo: cuerpo, cabeceras: cabeceras || {} };
}

function respuestaHttp( r ) {
	return {
		ok: r.status >= 200 && r.status < 300,
		status: r.status,
		headers: { get: ( k ) => ( k in r.cabeceras ? String( r.cabeceras[ k ] ) : null ) },
		json: () => ( 'string' === typeof r.cuerpo ? Promise.reject( new SyntaxError( 'no es JSON' ) ) : Promise.resolve( r.cuerpo ) ),
	};
}

/**
 * Monta el módulo en un DOM mínimo y devuelve los mandos del test.
 *
 * @param {Array<object>} respuestas    Payloads que devolverá /suggest, en orden.
 * @param {object}        almacenPrevio Contenido inicial de localStorage.
 * @param {object}        config        Extras de window.AKB_SEARCH (lo que PHP localiza).
 * @return {object} handlers y contadores.
 */
function montar( respuestas, almacenPrevio, config ) {
	const peticiones = [];
	const temporizadores = [];
	let secuencia = 0;
	const oyentes = new Map();

	function nodo( extra ) {
		const n = Object.assign( {
			id: '',
			hidden: true,
			innerHTML: '',
			style: {},
			dataset: {},
			atributos: {},
			clases: new Set(),
			// La lupa del campo es `.is-fetching` en el form: se registra de
			// verdad para poder exigir que quede apagada.
			classList: {
				add: ( c ) => n.clases.add( c ),
				remove: ( c ) => n.clases.delete( c ),
				toggle: ( c, on ) => ( ( undefined === on ? ! n.clases.has( c ) : on ) ? n.clases.add( c ) : n.clases.delete( c ) ),
				contains: ( c ) => n.clases.has( c ),
			},
			setAttribute( k, v ) { this.atributos[ k ] = v; },
			removeAttribute( k ) { delete this.atributos[ k ]; },
			getAttribute( k ) { return k in this.atributos ? this.atributos[ k ] : null; },
			hijos: [],
			appendChild( hijo ) { this.hijos.push( hijo ); },
			querySelectorAll() { return []; },
			closest() { return null; },
			addEventListener( tipo, fn ) { oyentes.set( tipo, ( oyentes.get( tipo ) || [] ).concat( fn ) ); },
			dispatchEvent( e ) { ( oyentes.get( e.type ) || [] ).forEach( ( fn ) => fn( e ) ); },
		}, extra || {} );
		return n;
	}

	const form = nodo();
	const input = nodo( { value: '', closest: () => form } );
	const panel = nodo();

	const sandbox = {
		AbortController,
		Event,
		console,
		document: {
			readyState: 'complete',
			activeElement: input,
			addEventListener() {},
			createElement: () => panel,
			querySelectorAll: () => [ input ],
		},
		localStorage: {
			datos: {},
			getItem( k ) { return k in this.datos ? this.datos[ k ] : null; },
			setItem( k, v ) { this.datos[ k ] = String( v ); },
			removeItem( k ) { delete this.datos[ k ]; },
		},
		fetch( url, opciones ) {
			peticiones.push( url );
			const siguiente = respuestas.shift();
			if ( siguiente && siguiente.__pendiente ) {
				// En vuelo hasta que la prueba la suelte con `responder()`; un
				// abort del controller la rechaza, como en el navegador.
				return new Promise( ( resolve, reject ) => {
					siguiente.responder = () => resolve( { ok: true, status: 200, headers: { get: () => null }, json: () => Promise.resolve( siguiente.cuerpo ) } );
					// /popular sale sin controller: su promesa es de la página
					// entera y nadie la aborta.
					if ( opciones && opciones.signal ) {
						opciones.signal.addEventListener( 'abort', () => {
							siguiente.abortada = true;
							const e = new Error( 'aborted' );
							e.name = 'AbortError';
							reject( e );
						} );
					}
				} );
			}
			if ( siguiente && siguiente.__http ) {
				return Promise.resolve( respuestaHttp( siguiente ) );
			}
			const cuerpo = siguiente || { items: [], total: 0 };
			return Promise.resolve( { ok: true, status: 200, headers: { get: () => null }, json: () => Promise.resolve( cuerpo ) } );
		},
		// Temporizadores con identidad y cancelación REALES: el reintento se corta
		// con clearTimeout al abortar, y un doble que lo ignora lo aprobaría sin
		// haber probado nada.
		setTimeout( fn, ms ) {
			secuencia += 1;
			temporizadores.push( { id: secuencia, fn: fn, ms: ms, vivo: true } );
			return secuencia;
		},
		clearTimeout( id ) {
			temporizadores.forEach( ( t ) => {
				if ( t.id === id ) {
					t.vivo = false;
				}
			} );
		},
	};
	sandbox.window = sandbox;
	sandbox.AKB_SEARCH = Object.assign( { rest: '/wp-json/akibara/v1/search/' }, config || {} );
	Object.assign( sandbox.localStorage.datos, almacenPrevio || {} );

	vm.runInNewContext( fuente, sandbox );

	return {
		peticiones: peticiones,
		form: form,
		input: input,
		panel: panel,
		// La ventana del módulo: la prueba le pone lo que quiera espiar.
		ventana: sandbox,
		almacen: sandbox.localStorage.datos,
		// Dispara el handler de `input` y corre SOLO el temporizador del
		// debounce (el de "Buscando…" es cosmético y no debe alterar el conteo).
		escribir( texto ) {
			input.value = texto;
			( oyentes.get( 'input' ) || [] ).forEach( ( fn ) => fn() );
			const debounce = temporizadores.filter( ( t ) => t.vivo && t.ms === 220 );
			debounce.forEach( ( t ) => {
				t.vivo = false;
				t.fn();
			} );
		},
		// Tecleo SIN correr el debounce: la petición queda esperando sus 220 ms.
		teclearSinEsperar( texto ) {
			input.value = texto;
			( oyentes.get( 'input' ) || [] ).forEach( ( fn ) => fn() );
		},
		// El campo toma el foco (clic, Tab o el `focus` que re-emite el tema).
		enfocar() {
			sandbox.document.activeElement = input;
			( oyentes.get( 'focus' ) || [] ).forEach( ( fn ) => fn() );
		},
		// El foco sale a otra parte de la página (no al panel).
		desenfocar() {
			sandbox.document.activeElement = null;
			( oyentes.get( 'focusout' ) || [] ).forEach( ( fn ) => fn( { relatedTarget: null } ) );
		},
		// Clic en la opción `idx` de la lista (el panel conserva el foco en el campo).
		elegir( idx ) {
			const opcion = { getAttribute: () => String( idx ) };
			( oyentes.get( 'click' ) || [] ).forEach( ( fn ) => fn( { stopPropagation() {}, target: { closest: () => opcion } } ) );
		},
		// Pulsa una tecla en el campo y devuelve el evento.
		tecla( key ) {
			const e = { key: key, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
			( oyentes.get( 'keydown' ) || [] ).forEach( ( fn ) => fn( e ) );
			return e;
		},
		// Corre lo que siga vivo del debounce (220 ms) y del «Buscando…» (150 ms).
		correrPendientes() {
			temporizadores.filter( ( t ) => t.vivo && t.ms < 1000 ).forEach( ( t ) => {
				t.vivo = false;
				t.fn();
			} );
		},
		lupaGirando: () => form.clases.has( 'is-fetching' ),
		// Esperas de reintento vivas (≥ 1 s): el backoff, no el debounce ni el
		// «Buscando…» de 150 ms.
		esperas() {
			return temporizadores.filter( ( t ) => t.vivo && t.ms >= 1000 ).map( ( t ) => t.ms );
		},
		correrEsperas() {
			temporizadores.filter( ( t ) => t.vivo && t.ms >= 1000 ).forEach( ( t ) => {
				t.vivo = false;
				t.fn();
			} );
		},
	};
}

test( 'el combobox apunta desde el arranque a un listbox que YA está en la página, sin aria-haspopup explícito', () => {
	// QA-ESC-TECLADO-12 (2026-09-24): axe dejaba `aria-controls` en revisión
	// manual en todas las páginas. El listbox sí existía, oculto; lo que le
	// impedía comprobarlo era el `aria-haspopup` explícito (con un popup
	// declarado axe no mira el id). En un combobox ya vale «listbox» por
	// defecto (ARIA 1.2) y el patrón APG no lo declara.
	const m = montar( [] );

	assert.equal( m.input.getAttribute( 'role' ), 'combobox' );
	assert.equal( m.input.getAttribute( 'aria-expanded' ), 'false' );
	assert.equal( m.input.getAttribute( 'aria-haspopup' ), null );
	assert.ok( m.panel.id, 'el listbox tiene id' );
	assert.equal( m.input.getAttribute( 'aria-controls' ), m.panel.id );
	assert.equal( m.panel.getAttribute( 'role' ), 'listbox' );
	assert.ok( m.form.hijos.includes( m.panel ), 'el listbox se inserta al arrancar, antes de escribir nada' );
	assert.equal( m.peticiones.length, 0 );
} );

test( 'volver sobre un texto ya consultado no repite la petición', async () => {
	const uno = { items: [ { title: 'Naruto 1', url: '/p/1', thumb: '/i.jpg', price_html: '$1', badge: 'Nuevo', badge_tone: 'ok' } ], total: 1 };
	const dos = { items: [ { title: 'Narut', url: '/p/2', thumb: '/i.jpg', price_html: '$1', badge: 'Nuevo', badge_tone: 'ok' } ], total: 1 };
	const app = montar( [ uno, dos ] );

	app.escribir( 'naruto' );
	await new Promise( ( r ) => setImmediate( r ) );
	assert.equal( app.peticiones.length, 1, 'la primera consulta sale a la red' );

	app.escribir( 'narut' );
	await new Promise( ( r ) => setImmediate( r ) );
	assert.equal( app.peticiones.length, 2, 'un texto distinto sí consulta' );

	// El gesto que motiva el memo: borrar y reescribir la última letra.
	app.escribir( 'naruto' );
	await new Promise( ( r ) => setImmediate( r ) );
	assert.equal( app.peticiones.length, 2, 'el backtracking se sirve del memo' );

	app.escribir( 'narut' );
	await new Promise( ( r ) => setImmediate( r ) );
	assert.equal( app.peticiones.length, 2, 'el memo guarda más de una entrada' );
} );

test( 'el memo caduca y está capado — no es una caché indefinida', () => {
	// El origen manda `no-store`: esta caché tiene que traer su propia
	// caducidad explícita y un tope de entradas, o sobreviviría a una purga.
	assert.match( fuente, /SUGGEST_MEMO_TTL = (\d+)/ );
	const ttl = Number( /SUGGEST_MEMO_TTL = (\d+)/.exec( fuente )[ 1 ] );
	assert.ok( ttl > 0 && ttl <= 300000, 'el TTL vive dentro del techo de staleness del borde (300 s)' );

	assert.match( fuente, /SUGGEST_MEMO_MAX = (\d+)/ );
	const max = Number( /SUGGEST_MEMO_MAX = (\d+)/.exec( fuente )[ 1 ] );
	assert.ok( max > 0 && max <= 200, 'el memo está capado' );

	// Desalojo del menos usado: el primer elemento del Map.
	assert.match( fuente, /SUGGEST_MEMO\.delete\( SUGGEST_MEMO\.keys\(\)\.next\(\)\.value \)/ );

	// Un error NO se memoiza (si no, un 429 se volvería permanente por el TTL).
	assert.match( fuente, /function memoSet[\s\S]*?'object' !== typeof data[\s\S]*?return;/ );

	// La clave incluye el contexto del archive: la misma consulta en /tienda/ y
	// dentro de una categoría son respuestas distintas.
	assert.match( fuente, /memoKey = q \+ '\\n' \+ ctxParams\(\)/ );
} );

test( 'la migración del historial lee TODOS los stores viejos antes de borrarlos', () => {
	// Leía dos y borraba tres: quien todavía tuviera `akb-search-recent-v1`
	// perdía sus Recientes en la primera carga con v3, en silencio y sin
	// vuelta atrás.
	const app = montar( [], {
		akibara_search_history_v2: JSON.stringify( [
			{ type: 'query', label: 'berserk', query: 'berserk', ts: Date.now() },
		] ),
		akb_search_history: JSON.stringify( [ 'naruto' ] ),
		'akb-search-recent-v1': JSON.stringify( [ 'chainsaw man' ] ),
	} );

	const envelope = JSON.parse( app.almacen.akb_search_history_v3 );
	const queries = envelope.items.map( ( item ) => item.query ).sort();

	assert.deepEqual( queries, [ 'berserk', 'chainsaw man', 'naruto' ] );

	// Y los tres stores viejos quedan barridos, no sólo los leídos.
	[ 'akibara_search_history_v2', 'akb_search_history', 'akb-search-recent-v1' ].forEach(
		( key ) => assert.equal( key in app.almacen, false, key + ' debe quedar borrado' )
	);
} );

test( 'la pista de «sin resultados» sale de la configuración, no del bundle', async () => {
	// Producción sirve el `.min` de este archivo: con la frase escrita acá
	// dentro, cambiar «prueba con el nombre de la serie» por «escríbenos y te lo
	// encargamos» exigía regenerar el minificado y desplegar el plugin.
	const app = montar( [ { items: [], total: 0 } ], {}, { emptyHint: 'Escríbenos y te lo encargamos.' } );

	app.escribir( 'inexistente' );
	await new Promise( ( r ) => setImmediate( r ) );

	assert.match( app.panel.innerHTML, /Sin resultados para «inexistente»\. Escríbenos y te lo encargamos\./ );
	assert.doesNotMatch( app.panel.innerHTML, /Prueba con el nombre de la serie/ );
} );

test( 'sin configuración el desplegable dice exactamente lo de siempre', async () => {
	const app = montar( [ { items: [], total: 0 } ], {}, {} );

	app.escribir( 'inexistente' );
	await new Promise( ( r ) => setImmediate( r ) );

	assert.match( app.panel.innerHTML, /Sin resultados para «inexistente»\. Prueba con el nombre de la serie\./ );
} );

test( 'la pista configurada se escapa: llega desde una option, no desde el código', async () => {
	const app = montar( [ { items: [], total: 0 } ], {}, { emptyHint: '<img src=x onerror=alert(1)>' } );

	app.escribir( 'inexistente' );
	await new Promise( ( r ) => setImmediate( r ) );

	assert.doesNotMatch( app.panel.innerHTML, /<img/ );
	assert.match( app.panel.innerHTML, /&lt;img/ );
} );

/*
 * Editorial equivocada: el desplegable tiene que decir por qué está mostrando
 * tomos de otra editorial. Sin la frase, el cliente que buscó «jujutsu kaisen
 * ivrea» ve resultados de Panini sin explicación, que es peor que el cero que
 * había antes del arreglo (medido en producción el 2026-09-05).
 */
test( 'el aviso de editorial soltada se pinta con lo que manda el servidor', async () => {
	const app = montar( [ {
		items: [ { id: 1, title: 'Jujutsu Kaisen 01', title_html: 'Jujutsu Kaisen 01', url: '/p/1', thumb: '/t.jpg', price_html: '$9.990', sku: 'X', badge: 'Disponible', badge_tone: 'ok' } ],
		total: 31,
		relaxed: { ignored: [ 'ivrea' ], kept: 'jujutsu kaisen', notice: 'No tenemos jujutsu kaisen de Ivrea. Esto es lo que tenemos de jujutsu kaisen.' },
	} ] );

	app.escribir( 'jujutsu kaisen ivrea' );
	await new Promise( ( r ) => setImmediate( r ) );

	assert.match( app.panel.innerHTML, /No tenemos jujutsu kaisen de Ivrea\./ );
	assert.match( app.panel.innerHTML, /role="status" data-akb-relaxed/ );
	// Inerte, como la corrección: no es una opción del listbox.
	assert.doesNotMatch( app.panel.innerHTML, /data-akb-relaxed[^>]*role="option"/ );
} );

test( 'el aviso llega del servidor y se escapa: el bundle no escribe la frase', async () => {
	const app = montar( [ {
		items: [ { id: 1, title: 'T', title_html: 'T', url: '/p/1', thumb: '/t.jpg', price_html: '$1', sku: 'X', badge: 'Disponible', badge_tone: 'ok' } ],
		total: 1,
		relaxed: { ignored: [ 'ivrea' ], kept: 'x', notice: '<img src=x onerror=alert(1)>' },
	} ] );

	app.escribir( 'x ivrea' );
	await new Promise( ( r ) => setImmediate( r ) );

	assert.doesNotMatch( app.panel.innerHTML, /<img src=x/ );
	assert.match( app.panel.innerHTML, /&lt;img/ );
	// La frase no está escrita en el fuente: la edita el dueño en Textos.
	assert.doesNotMatch( fuente, /No tenemos .* de \{editorial\}/ );
} );

test( '«Ver los N» apunta a lo que de verdad se buscó, no a la editorial soltada', () => {
	// El DOM del test no monta el form de la tienda (searchUrl sale por su
	// guard), así que el candado va sobre la decisión en el fuente: el footer
	// tiene que preferir `relaxed.kept` a la query cruda, igual que ya prefiere
	// `corrected.fix`. Sin esto, «Ver los 31 resultados» llevaba a la búsqueda
	// con la editorial equivocada, o sea, a una página vacía.
	assert.match( fuente, /searchUrl\(\s*corrected \? corrected\.fix : \(\s*relaxed && relaxed\.kept \? relaxed\.kept : q\s*\)\s*\)/ );
} );

test( 'el énfasis del aviso lo decide el servidor, no el bundle', async () => {
	// `notice_html` viaja ya escapado con las dos piezas en <strong> (mismo
	// trato que title_html): el bundle no sabe qué parte es la editorial.
	const app = montar( [ {
		items: [ { id: 1, title: 'T', title_html: 'T', url: '/p/1', thumb: '/t.jpg', price_html: '$1', sku: 'X', badge: 'Disponible', badge_tone: 'ok' } ],
		total: 1,
		relaxed: {
			ignored: [ 'ivrea' ],
			kept: 'jujutsu kaisen',
			notice: 'No tenemos jujutsu kaisen de Ivrea.',
			notice_html: 'No tenemos <strong>jujutsu kaisen</strong> de <strong class="akb-relaxed__brand">Ivrea</strong>.',
		},
	} ] );

	app.escribir( 'jujutsu kaisen ivrea' );
	await new Promise( ( r ) => setImmediate( r ) );

	assert.match( app.panel.innerHTML, /<strong class="akb-relaxed__brand">Ivrea<\/strong>/ );
	assert.doesNotMatch( app.panel.innerHTML, /&lt;strong/, 'el HTML del servidor no se re-escapa' );
} );

test( 'sin aviso del servidor el panel no inventa ninguno', async () => {
	const app = montar( [ {
		items: [ { id: 1, title: 'T', title_html: 'T', url: '/p/1', thumb: '/t.jpg', price_html: '$1', sku: 'X', badge: 'Disponible', badge_tone: 'ok' } ],
		total: 1,
	} ] );

	app.escribir( 'jujutsu kaisen' );
	await new Promise( ( r ) => setImmediate( r ) );

	assert.doesNotMatch( app.panel.innerHTML, /data-akb-relaxed/ );
} );

/*
 * Reintento del 503 TRANSITORIO [0.1.64]. Medido en el espejo el 2026-09-14:
 * guardar un ajuste editorial deja 2 de 105 búsquedas en 503
 * `index_unavailable` entre +2 y +3 s, y el recibo queda sellado a +4 s. El
 * cliente no puede pintar «No pudimos cargar sugerencias» por eso.
 */
const tick = () => new Promise( ( r ) => setImmediate( r ) );
const indisponible = ( cabeceras ) => http( 503, { items: [], code: 'index_unavailable' }, cabeceras || { 'Retry-After': '1' } );
const naruto = { items: [ { title: 'Naruto 1', url: '/p/1', thumb: '/i.jpg', price_html: '$1', badge: 'Nuevo', badge_tone: 'ok' } ], total: 1 };

test( 'un 503 transitorio sigue en «Buscando…» y el reintento pinta los resultados', async () => {
	const app = montar( [ indisponible(), naruto ] );

	app.escribir( 'naruto' );
	await tick();
	assert.equal( app.peticiones.length, 1 );
	assert.match( app.panel.innerHTML, /Buscando…/, 'el 503 no es una respuesta: el panel sigue cargando' );
	assert.doesNotMatch( app.panel.innerHTML, /No pudimos cargar/ );
	assert.deepEqual( app.esperas(), [ 1000 ], 'primer reintento a 1 s (Retry-After: 1)' );

	app.correrEsperas();
	await tick();
	assert.equal( app.peticiones.length, 2 );
	assert.match( app.panel.innerHTML, /href="\/p\/1"/, 'se pinta el producto (el título va resaltado con <mark>)' );
	assert.doesNotMatch( app.panel.innerHTML, /No pudimos cargar/ );
} );

test( 'cuatro 503 seguidos: 1 s, 2 s, 4 s y sólo entonces el error', async () => {
	const app = montar( [ indisponible(), indisponible(), indisponible(), indisponible() ] );

	app.escribir( 'naruto' );
	const esperas = [];
	for ( let i = 0; i < 3; i++ ) {
		await tick();
		assert.doesNotMatch( app.panel.innerHTML, /No pudimos cargar/, 'antes del último intento no hay error' );
		esperas.push( ...app.esperas() );
		app.correrEsperas();
	}
	await tick();

	assert.deepEqual( esperas, [ 1000, 2000, 4000 ] );
	assert.equal( app.peticiones.length, 4, 'un intento y tres reintentos' );
	assert.match( app.panel.innerHTML, /No pudimos cargar sugerencias/ );
	assert.deepEqual( app.esperas(), [], 'no queda ningún reintento colgado' );
} );

test( 'un 429 no se reintenta', async () => {
	const app = montar( [ http( 429, { items: [], code: 'rate_limited' } ) ] );

	app.escribir( 'naruto' );
	await tick();

	assert.equal( app.peticiones.length, 1 );
	assert.deepEqual( app.esperas(), [] );
	assert.match( app.panel.innerHTML, /No pudimos cargar sugerencias/ );
} );

test( 'un 503 ajeno (sin code index_unavailable) no se reintenta', async () => {
	const app = montar( [ http( 503, '<html>Service Unavailable</html>' ) ] );

	app.escribir( 'naruto' );
	await tick();

	assert.equal( app.peticiones.length, 1 );
	assert.deepEqual( app.esperas(), [] );
	assert.match( app.panel.innerHTML, /No pudimos cargar sugerencias/ );
} );

test( 'cambiar la consulta a mitad del reintento lo cancela', async () => {
	const bleach = { items: [ { title: 'Bleach 1', url: '/p/9', thumb: '/i.jpg', price_html: '$1', badge: 'Nuevo', badge_tone: 'ok' } ], total: 1 };
	const app = montar( [ indisponible(), bleach ] );

	app.escribir( 'naruto' );
	await tick();
	assert.deepEqual( app.esperas(), [ 1000 ] );

	app.escribir( 'bleach' );
	await tick();
	assert.deepEqual( app.esperas(), [], 'el abort del tipeo nuevo cancela la espera (clearTimeout)' );
	app.correrEsperas();
	await tick();

	assert.deepEqual( app.peticiones.map( ( u ) => /q=([^&]+)/.exec( u )[ 1 ] ), [ 'naruto', 'bleach' ], 'naruto no se vuelve a pedir' );
	assert.match( app.panel.innerHTML, /href="\/p\/9"/ );
	assert.doesNotMatch( app.panel.innerHTML, /No pudimos cargar/ );
} );

test( 'la base del backoff sale de Retry-After del servidor', async () => {
	const app = montar( [ indisponible( { 'Retry-After': '2' } ), naruto ] );

	app.escribir( 'naruto' );
	await tick();

	assert.deepEqual( app.esperas(), [ 2000 ] );
} );

test( 'un 503 no se memoiza; el éxito tras reintentar sí', async () => {
	const app = montar( [ indisponible(), naruto, http( 500, 'x' ) ] );

	app.escribir( 'naruto' );
	await tick();
	app.correrEsperas();
	await tick();
	assert.equal( app.peticiones.length, 2 );

	app.escribir( 'narut' );
	await tick();
	app.escribir( 'naruto' );
	await tick();
	assert.equal( app.peticiones.length, 3, 'sólo «narut» sale a la red: «naruto» se sirve del memo del éxito' );
	assert.match( app.panel.innerHTML, /href="\/p\/1"/, 'se pinta el producto (el título va resaltado con <mark>)' );
} );

/*
 * Escape con una respuesta todavía por llegar (PROD-COMP-1, medido en
 * producción el 2026-09-26 en Chromium y WebKit, escritorio y móvil). close()
 * sólo ocultaba la lista: el debounce y el fetch seguían vivos y, con el campo
 * enfocado y el mismo texto, «Buscando…» y la respuesta la volvían a abrir
 * ~300 ms después. En la hoja móvil hacía falta un tercer Escape.
 */
const doro = { items: [ { title: 'Dorohedoro 1', url: '/p/d1', thumb: '/i.jpg', price_html: '$1', badge: 'Disponible', badge_tone: 'ok' } ], total: 1 };
const pendiente = ( cuerpo ) => ( { __pendiente: true, cuerpo: cuerpo } );

test( 'Escape durante la espera del debounce: la petición no sale y la lista no se reabre', async () => {
	const app = montar( [ doro, doro ] );

	app.escribir( 'doro' );
	await tick();
	assert.equal( app.panel.hidden, false, 'la primera consulta deja la lista abierta' );

	app.teclearSinEsperar( 'dorohed' );
	const e = app.tecla( 'Escape' );
	assert.equal( e.defaultPrevented, true, 'el primer Escape es de la lista, no borra lo escrito' );
	assert.equal( app.panel.hidden, true );

	app.correrPendientes();
	await tick();
	app.correrPendientes();
	await tick();

	assert.equal( app.peticiones.length, 1, 'la espera del debounce murió con el Escape' );
	assert.equal( app.panel.hidden, true, 'la lista sigue cerrada' );
	assert.equal( app.input.getAttribute( 'aria-expanded' ), 'false' );
	assert.equal( app.input.value, 'dorohed', 'lo escrito se conserva' );
} );

test( 'Escape con la petición en vuelo: se aborta, la lupa se apaga y la respuesta no reabre la lista', async () => {
	const lenta = pendiente( doro );
	const app = montar( [ doro, lenta, doro ] );

	app.escribir( 'doro' );
	await tick();
	app.escribir( 'dorohed' );
	app.correrPendientes(); // «Buscando…» a los 150 ms: la lista está abierta.
	assert.match( app.panel.innerHTML, /Buscando…/ );
	assert.equal( app.lupaGirando(), true );

	app.tecla( 'Escape' );
	assert.equal( lenta.abortada, true, 'la petición en vuelo se aborta' );
	assert.equal( app.lupaGirando(), false, 'la lupa no se queda girando' );

	lenta.responder();
	app.correrPendientes();
	await tick();
	assert.equal( app.panel.hidden, true, 'la respuesta tardía no reabre la lista' );
	assert.equal( app.input.getAttribute( 'aria-expanded' ), 'false' );
	assert.equal( app.panel.innerHTML, '' );

	// Cerrar no deja el buscador tieso: seguir escribiendo vuelve a sugerir.
	app.escribir( 'dorohedo' );
	await tick();
	assert.equal( app.peticiones.length, 3 );
	assert.equal( app.panel.hidden, false );
	assert.match( app.panel.innerHTML, /href="\/p\/d1"/ );
} );

/*
 * REV-SS-1 (revisión del lote S-S, medido en el espejo el 2026-09-26): la misma
 * familia de PROD-COMP-1, por el camino de «Populares». /search/popular sale
 * una vez por página y tarda hasta 3,6 s; su aviso sólo miraba que el campo
 * siguiera enfocado y vacío, así que llegaba después del Escape y volvía a
 * abrir «Recientes · Populares». En la hoja móvil, el segundo Escape volvía a
 * cerrar sólo la lista.
 */
const HISTORIAL = () => ( {
	akb_search_history_v3: JSON.stringify( { v: 3, items: [ { type: 'query', label: 'naruto', query: 'naruto', ts: Date.now() } ] } ),
} );
const POPULARES = { items: [ { q: 'berserk' }, { q: 'chainsaw man' } ] };

test( 'REV-SS-1: con historial, Escape antes de que lleguen los Populares: la lista no se reabre', async () => {
	const populares = pendiente( POPULARES );
	const app = montar( [ populares ], HISTORIAL() );

	app.enfocar();
	assert.equal( app.panel.hidden, false, 'el foco en el campo vacío abre Recientes' );
	assert.match( app.panel.innerHTML, /Recientes/ );
	assert.match( app.peticiones[ 0 ], /popular$/ );

	const e = app.tecla( 'Escape' );
	assert.equal( e.defaultPrevented, true );
	assert.equal( app.panel.hidden, true );

	populares.responder();
	await tick();
	assert.equal( app.panel.hidden, true, 'la respuesta tardía de Populares no reabre la lista' );
	assert.equal( app.input.getAttribute( 'aria-expanded' ), 'false' );
	assert.equal( app.panel.innerHTML, '' );
} );

test( 'REV-SS-1: sin historial, el Escape cae con la lista oculta y los Populares tampoco la abren', async () => {
	const populares = pendiente( POPULARES );
	const app = montar( [ populares ] );

	app.enfocar();
	assert.equal( app.panel.hidden, true, 'sin historial no hay nada que mostrar todavía' );

	const e = app.tecla( 'Escape' );
	assert.equal( e.defaultPrevented, false, 'con la lista cerrada el Escape sigue siendo el nativo (la hoja móvil lo usa)' );

	populares.responder();
	await tick();
	assert.equal( app.panel.hidden, true, 'los Populares no abren la lista después del Escape' );
	assert.equal( app.input.getAttribute( 'aria-expanded' ), 'false' );
} );

test( 'REV-SS-1: sin Escape, los Populares que llegan tarde sí se pintan', async () => {
	const populares = pendiente( POPULARES );
	const app = montar( [ populares ], HISTORIAL() );

	app.enfocar();
	populares.responder();
	await tick();

	assert.equal( app.panel.hidden, false );
	assert.match( app.panel.innerHTML, /Recientes[\s\S]*Populares[\s\S]*berserk/ );
} );

test( 'REV-SS-1: la hoja móvil (foco, cierre por el clic, foco re-emitido) sigue pintando los Populares', async () => {
	// El tema enfoca SÍNCRONO dentro del toque en «Buscar», el mismo clic llega
	// a `document` y cierra la lista, y el tema re-emite `focus` en el tick
	// siguiente (main.js). El primer aviso queda anulado; el segundo, no.
	const populares = pendiente( POPULARES );
	const app = montar( [ populares ] );

	app.enfocar();
	app.desenfocar();
	app.enfocar();
	populares.responder();
	await tick();

	assert.equal( app.panel.hidden, false );
	assert.match( app.panel.innerHTML, /Populares[\s\S]*berserk/ );
	assert.equal( app.peticiones.length, 1, 'una sola petición de Populares por página' );
} );

test( 'REV-SS-1: «Borrar historial» antes de que lleguen los Populares oculta la lista, pero los Populares la vuelven a llenar', async () => {
	// Aquí el cliente no cerró nada: el estado vacío se quedó sin qué mostrar
	// y lo que falta son justamente los Populares.
	const populares = pendiente( POPULARES );
	const app = montar( [ populares ], HISTORIAL() );

	app.enfocar();
	assert.match( app.panel.innerHTML, /Borrar historial/ );
	app.elegir( 1 ); // [0] «naruto», [1] «Borrar historial».
	assert.equal( app.panel.hidden, true, 'sin historial ni Populares todavía, no hay lista' );

	populares.responder();
	await tick();
	assert.equal( app.panel.hidden, false );
	assert.match( app.panel.innerHTML, /Populares[\s\S]*berserk/ );
	assert.doesNotMatch( app.panel.innerHTML, /Recientes/ );
} );

/*
 * REV-S-S-2o-1 (corrección 2 del lote S-S, medido en el espejo el 2026-09-26):
 * Enter sobre una sugerencia navegaba con `location.href` por un camino propio,
 * aparte del toque. Ahora Enter PULSA la opción: el <a> navega solo y el
 * Reciente se guarda en el mismo manejador de clic que el toque. Que un PJAX
 * en vuelo no pise esa salida ya no depende del clic (seguimiento C-S, ver
 * pjax-no-pisa-la-salida.test.js).
 */

/**
 * Opciones pintadas, como las recorre setActive(). Pulsar una hace lo que el
 * navegador: el clic sube hasta el panel (elegir()) y, si es un <a>, navega.
 *
 * @param {object} app   Mandos de montar().
 * @param {number} n     Cuántas opciones.
 * @param {Array}  pulsadas Registro de las opciones pulsadas.
 */
function opcionesPintadas( app, n, pulsadas ) {
	const opciones = Array.from( { length: n }, ( _, idx ) => ( {
		id: 'op-' + idx,
		classList: { add() {}, remove() {} },
		setAttribute() {},
		scrollIntoView() {},
		click() {
			pulsadas.push( idx );
			app.elegir( idx );
		},
	} ) );
	app.panel.querySelectorAll = ( sel ) => ( '.akb-suggest-item' === sel ? opciones : [] );
}

/** location espía: registra cada asignación de href. */
function espiarLocation( app ) {
	const asignadas = [];
	app.ventana.location = {
		origin: 'https://akibara.test',
		get href() { return 'https://akibara.test/tienda/'; },
		set href( v ) { asignadas.push( v ); },
		assign: ( v ) => asignadas.push( v ),
	};
	return asignadas;
}

test( 'REV-S-S-2o-1: Enter sobre un producto sugerido pulsa su opción (el <a> navega solo), no asigna location', async () => {
	const app = montar( [ doro ] );
	app.escribir( 'doro' );
	await tick();
	assert.match( app.panel.innerHTML, /href="\/p\/d1"/, 'la sugerencia es un enlace a la ficha' );

	const pulsadas = [];
	opcionesPintadas( app, 1, pulsadas );
	const asignadas = espiarLocation( app );

	app.tecla( 'ArrowDown' );
	const e = app.tecla( 'Enter' );

	assert.equal( e.defaultPrevented, true, 'Enter no envía el formulario' );
	assert.deepEqual( pulsadas, [ 0 ], 'Enter pulsa la opción activa, una vez' );
	assert.deepEqual( asignadas, [], 'y no navega por location: lo hace el <a>' );
	const recientes = JSON.parse( app.almacen.akb_search_history_v3 ).items.filter( ( x ) => '/p/d1' === x.url );
	assert.equal( recientes.length, 1, 'la ficha elegida entra UNA vez en Recientes' );
} );

test( 'REV-S-S-2o-1: Enter sobre un Reciente de consulta lo sigue escribiendo en el campo, por el mismo clic', async () => {
	const app = montar( [], HISTORIAL() );
	app.enfocar();
	assert.match( app.panel.innerHTML, /naruto/ );

	const pulsadas = [];
	opcionesPintadas( app, 2, pulsadas ); // [0] «naruto», [1] «Borrar historial».
	const asignadas = espiarLocation( app );

	app.tecla( 'ArrowDown' );
	app.tecla( 'Enter' );
	await tick();

	assert.deepEqual( pulsadas, [ 0 ] );
	assert.equal( app.input.value, 'naruto', 'el Reciente llena el campo' );
	assert.ok( app.peticiones.some( ( u ) => /suggest\?q=naruto/.test( u ) ), 'y pide sus sugerencias' );
	assert.deepEqual( asignadas, [] );
} );

test( 'separa resultados en «En Bodega · Entrega Inmediata» y «Disponibles a Pedido»', async () => {
	const mixto = {
		items: [
			{ id: 101, title: 'Tomo Disponible', url: '/p/disp', thumb: '/d.jpg', price_html: '$1', badge: 'Disponible', badge_tone: 'ok', availability: 'instock' },
			{ id: 102, title: 'Tomo a Pedido', url: '/p/ped', thumb: '/p.jpg', price_html: '$1', badge: 'A pedido', badge_tone: 'order', availability: 'a_pedido' },
			{ id: 103, title: 'Tomo Agotado', url: '/p/out', thumb: '/o.jpg', price_html: '', badge: 'Agotado', badge_tone: 'out', availability: 'outofstock' }
		],
		total: 3
	};
	const app = montar( [ mixto ] );
	app.escribir( 'tomo' );
	await tick();

	assert.match( app.panel.innerHTML, /En Bodega · Entrega Inmediata/, 'muestra cabecera de bodega' );
	assert.match( app.panel.innerHTML, /Disponibles a Pedido/, 'muestra cabecera de a pedido' );
	assert.match( app.panel.innerHTML, /data-akb-encargo="1"/, 'marca el item de encargo' );
	assert.match( app.panel.innerHTML, /akb-suggest-reservar-tag/, 'incluye tag reservar para tomos con precio' );
	assert.match( app.panel.innerHTML, /akb-suggest-encargo-tag/, 'incluye tag solicitar para tomos agotados' );
	assert.match( app.panel.innerHTML, /Solicitar a Pedido »/, 'incluye pie homologado a pedido' );
} );

test( 'el estado vacío ofrece botón para solicitar el tomo a pedido', async () => {
	const app = montar( [ { items: [], total: 0 } ] );
	app.escribir( 'inuyasha' );
	await tick();

	assert.match( app.panel.innerHTML, /class="akb-suggest-empty" role="status"/ );
	assert.match( app.panel.innerHTML, /data-akb-request-q="inuyasha"/, 'ofrece botón con la query para solicitar a pedido' );
	assert.match( app.panel.innerHTML, /Solicitar «<strong>inuyasha<\/strong>» a pedido/ );
} );
