'use strict';

/**
 * Un PJAX en vuelo no pisa la navegación del cliente (PS-S-1, revisión previa
 * al despliegue del 2026-09-26, sus correcciones REV-SS-2 / REV-S-S-1f-1, el
 * seguimiento C-S, sus correcciones C-S-c1 y C-S-c2, y el lote D-S).
 *
 * Con un filtro, un chip o la paginación todavía cargando, el cliente se va a
 * otra página: pulsa un enlace normal (el pie, el menú, una sugerencia), envía
 * el buscador o vuelve Atrás. Safari cancela en ese instante los fetch de la
 * página con un TypeError («Load failed»), no con AbortError, y el catch de
 * go() lo tomaba por un fallo de red: location.assign() a la URL del PJAX, y
 * el cliente terminaba en /tienda/page/2/ en vez de donde había pulsado.
 *
 * Qué señal llega y cuándo, medido en WebKit 2342 (sondas S-S-c1 y C-S):
 *  - enlace: `beforeunload` y el `navigate` de la Navigation API llegan ANTES
 *    del rechazo;
 *  - Atrás: el rechazo llega PRIMERO; `beforeunload` y `navigate`, 5-60 ms
 *    después;
 *  - Atrás a OTRO sitio (Google, Instagram): ni `navigate` ni `beforeunload`,
 *    con o sin Navigation API (REV-C-S-1f-1);
 *  - iPhone: nunca `beforeunload`, y Navigation API sólo desde iOS 26.2;
 *  - `pagehide` siempre, pero al confirmarse la página nueva.
 * Ningún respaldo automático tras un fallo de RED es seguro contra el Atrás
 * (C-S: el diferido perdía 26 de 26 en el iPhone viejo; C-S-c1: 10 de 10 hacia
 * otro sitio en Safari con la API). Un fallo de red no navega nunca solo:
 * espera por si la página se va y, si nadie se fue, lo dice con un aviso y su
 * «Reintentar», deja los controles como lo pintado (o, en el cajón móvil, lo
 * marcado pendiente) y el siguiente gesto del catálogo va sin PJAX
 * (REV-C-S-1o-1 y 1o-2). Cuánto espera (C-S-c2): 4 s con conexión —un Atrás
 * sin señal previa sólo avisa con pagehide, y con 1 s el aviso salía en la
 * página que se dejaba (REV-C-S-2f-1)—, 1 s sin conexión y, tras un clic o un
 * envío que se lleva la página, hasta pagehide con un tope de 8 s
 * (REV-C-S-2o-3). Y quien ve la pantalla lo ve sin perder el foco ni la
 * posición si siguió con el teclado o conserva el foco en su casilla: una
 * copia flota junto a su foco (D-S: REV-C-S-3o-1, 3o-2 y 3f-1), sigue a su
 * control también cuando se desplaza la lista que lo contiene, y no se pinta
 * mientras esa lista lo esconde o mientras el control es el buscador con sus
 * sugerencias abiertas (D-S-c1: REV-D-S-1o-1, 1o-2 y 1f-1).
 *
 * Se ejercita el BUNDLE PUBLICADO (`.min`), que es el que sirve el sitio.
 */

const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const { join } = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const { h, mundo, crearDocumento, disparar, FormDataFalso, MutationObserverFalso } = require( './Support/mini-dom.js' );

const bundle = readFileSync( join( __dirname, '..', 'assets/js/akb-search.min.js' ), 'utf8' );

/** El aviso de un fallo de red, tal como lo pinta `facets-render.php` en su `<template>`. */
const AVISO = 'No pudimos cargar los productos por un problema de conexión. Reintentar';

/** Lo que espera el aviso (ver esperaDelAviso() en akb-search.js). */
const ESPERA = 4_000;
const SIN_CONEXION = 1_000;
const SALIDA = 8_000;

/**
 * Página del catálogo con resultados, la barra de resultados (total, aviso de
 * conexión oculto y orden), un chip de búsqueda, paginación, el formulario de
 * filtros —con la búsqueda y una casilla sin marcar— y un pie con enlaces y el
 * buscador (un combobox con su lista de sugerencias, como lo deja
 * search-suggest).
 */
function pagina() {
	const root = h(
		'html',
		{},
		h(
			'body',
			{},
			h(
				'main',
				{},
				h( 'h1', { class: 'page-title' } ),
				h(
					'div',
					{ class: 'akb-shop-toolbar' },
					h( 'template', { 'data-akb-aviso-red': '' }, h( 'div', { class: 'woocommerce-notices-wrapper' }, h( 'p', { class: 'akb-aviso-red' }, 'No pudimos cargar los productos por un problema de conexión. ', h( 'button', { type: 'button', id: 'reintentar', class: 'akb-link', 'data-akb-reintentar': '' }, 'Reintentar' ) ) ) ),
					h( 'p', { class: 'akb-result-count' }, '1.625 productos' ),
					h( 'button', { type: 'button', id: 'abrir-filtros', 'data-akb-drawer-open': '' } ),
					h(
						'form',
						{ class: 'woocommerce-ordering', action: '/tienda/' },
						h(
							'select',
							{ id: 'orden', class: 'orderby', name: 'orderby', value: 'menu_order' },
							h( 'option', { id: 'orden-defecto', value: 'menu_order', selected: '' } ),
							h( 'option', { id: 'orden-precio', value: 'price' } )
						)
					)
				),
				h( 'div', { class: 'akb-facet-chips', 'data-akb-chips': '' }, h( 'button', { type: 'button', id: 'chip-buscar', class: 'akb-chip', 'data-facet': 'buscar', 'data-value': 'naruto' } ) ),
				h( 'div', { class: 'akb-empty-state', 'data-akb-empty': '', hidden: '' } ),
				h( 'ul', { class: 'products columns-4' }, h( 'li', { class: 'product' } ) ),
				h( 'nav', { class: 'woocommerce-pagination' }, h( 'a', { id: 'pagina-2', class: 'page-numbers', href: '/tienda/page/2/' } ) ),
				h(
					'form',
					{ id: 'filtros', class: 'akb-facets', action: '/tienda/', 'data-akb-facets': '' },
					h( 'input', { type: 'hidden', id: 'buscar', name: '_buscar', value: 'naruto' } ),
					h( 'label', {}, h( 'input', { type: 'checkbox', id: 'disponible', name: '_disponibilidad[]', value: 'instock' } ) ),
					h( 'button', { type: 'submit', class: 'akb-facets-apply', 'data-akb-apply': '' }, 'Ver ', h( 'span', { 'data-akb-apply-count': '' }, '1.027' ) )
				)
			),
			h(
				'footer',
				{},
				h( 'a', { id: 'series', href: '/series/' }, h( 'span', { id: 'series-texto' }, 'Series' ) ),
				h( 'a', { id: 'telefono', href: 'tel:+56900000000' } ),
				h( 'a', { id: 'correo', href: 'mailto:hola@akibara.test' } ),
				h( 'a', { id: 'catalogo-pdf', href: '/catalogo.pdf', download: '' } ),
				h( 'a', { id: 'instagram', href: 'https://instagram.test/akibara', target: '_blank' } ),
				h( 'a', { id: 'ancla', href: '#resultados' } ),
				h(
					'form',
					{ id: 'buscador', action: '/' },
					h( 'input', { type: 'search', id: 'akb-search-input', name: 's', role: 'combobox', 'aria-expanded': 'false', 'aria-controls': 'akb-suggest-1' } ),
					h( 'div', { id: 'akb-suggest-1', role: 'listbox', hidden: '' } )
				)
			)
		)
	);
	root.title = 'Tienda';
	return root;
}

const tick = async () => {
	for ( let i = 0; i < 10; i++ ) {
		await new Promise( ( r ) => setImmediate( r ) );
	}
};

/**
 * Arranca el bundle y devuelve los mandos: navegar, salir, responder, el reloj.
 *
 * @param {{ navigationApi?: boolean, movil?: boolean, enLinea?: boolean }} opciones
 *        `navigationApi: false` modela un Safari anterior a 26.2 (sin
 *        `window.navigation`); `movil: true`, una ventana de ≤ 768 px, donde
 *        los filtros van en el cajón y se aplican con «Ver N»; `enLinea:
 *        false`, un teléfono sin conexión (`navigator.onLine`).
 */
function arrancar( opciones = {} ) {
	mundo.reiniciar();
	const root = pagina();
	const document = crearDocumento( root, 'complete' );
	const ventana = new Map();
	const navegacion = new Map();
	const asignadas = [];
	const pendientes = [];
	const location = {
		href: 'https://akibara.test/tienda/',
		pathname: '/tienda/',
		search: '',
		assign: ( u ) => asignadas.push( u ),
	};

	// Reloj propio: los temporizadores esperan a que la prueba avance el tiempo.
	let ahora = 0;
	let secuencia = 0;
	let temporizadores = [];

	// Como en el DOM: un oyente de captura y uno de burbuja son registros
	// distintos, y removeEventListener sólo retira el de la MISMA fase.
	const enCaptura = ( opciones ) => true === opciones || !! ( opciones && opciones.capture );
	const oyentes = ( mapa ) => ( {
		addEventListener: ( type, fn, opciones ) => {
			const lista = mapa.get( type ) || [];
			const captura = enCaptura( opciones );
			if ( ! lista.some( ( o ) => o.fn === fn && o.captura === captura ) ) {
				lista.push( { fn, captura } );
			}
			mapa.set( type, lista );
		},
		removeEventListener: ( type, fn, opciones ) => {
			const captura = enCaptura( opciones );
			mapa.set( type, ( mapa.get( type ) || [] ).filter( ( o ) => ! ( o.fn === fn && o.captura === captura ) ) );
		},
	} );
	// Sin `fase`, un evento cuyo destino es la propia ventana: le llegan los
	// oyentes de las dos fases. Con `fase`, sólo los de esa.
	const avisar = ( mapa, type, e, fase ) => ( mapa.get( type ) || [] )
		.filter( ( o ) => undefined === fase || o.captura === fase )
		.forEach( ( o ) => o.fn( e ) );

	/**
	 * Despacho de un evento cuyo destino está DENTRO de la página: `window` lo
	 * ve primero en captura y al final en burbuja, salvo que alguien corte la
	 * burbuja por el camino (`detenido`: la lista de sugerencias).
	 */
	const despachar = ( type, e, { detenido } ) => {
		avisar( ventana, type, e, true );
		if ( ! detenido ) {
			avisar( ventana, type, e, false );
		}
		return e;
	};

	const sandbox = {
		AbortController,
		Event,
		URL,
		URLSearchParams,
		FormData: FormDataFalso,
		Date,
		Math,
		JSON,
		AKB_FACETS: {},
		localStorage: { getItem: () => null, setItem: () => {} },
		navigator: { onLine: false !== opciones.enLinea },
		DOMParser: class {
			parseFromString() {
				return pagina();
			}
		},
		...oyentes( ventana ),
		setTimeout: ( fn, ms ) => {
			secuencia += 1;
			temporizadores.push( { id: secuencia, fn, en: ahora + ( ms || 0 ) } );
			return secuencia;
		},
		clearTimeout: ( id ) => {
			temporizadores = temporizadores.filter( ( t ) => t.id !== id );
		},
		matchMedia: ( q ) => ( { matches: !! opciones.movil && /max-width/.test( q ) } ),
		// Sólo lo que lee el bundle: la franja fija del pie que mide el tema y
		// qué recorta lo que se le sale.
		getComputedStyle: ( nodo ) => ( {
			getPropertyValue: ( n ) => ( '--akb-franja-inferior' === n ? mundo.franja : '' ),
			overflowX: mundo.desborde( nodo ),
			overflowY: mundo.desborde( nodo ),
		} ),
		MutationObserver: MutationObserverFalso,
		document,
		// Cada PJAX queda en vuelo hasta que la prueba decide cómo termina; un
		// abort del controller lo rechaza como en el navegador.
		fetch: ( url, opts ) => new Promise( ( resolve, reject ) => {
			const p = { url, resolve, reject };
			pendientes.push( p );
			if ( opts && opts.signal ) {
				opts.signal.addEventListener( 'abort', () => {
					const e = new Error( 'aborted' );
					e.name = 'AbortError';
					reject( e );
				} );
			}
		} ),
		history: { pushState: () => {} },
		location,
	};
	if ( false !== opciones.navigationApi ) {
		sandbox.navigation = oyentes( navegacion );
	}
	sandbox.window = sandbox;
	vm.runInNewContext( bundle, sandbox );

	return {
		asignadas,
		/** Cuántos fetch del PJAX siguen esperando respuesta. */
		enVuelo: () => pendientes.length,
		/**
		 * «Página 2»: el clic recorre el documento y lo toma el oyente delegado
		 * del PJAX, como en el navegador. Con `teclado`, el enlace tiene el foco
		 * y lo activa Enter; con `dedo`, lo precede un `pointerdown` táctil.
		 */
		paginar( { teclado = false, dedo = false } = {} ) {
			const enlace = root.querySelector( '#pagina-2' );
			enlace.href = 'https://akibara.test/tienda/page/2/';
			if ( teclado ) {
				enlace.focus();
				disparar( document, enlace, 'keydown', { key: 'Enter' } );
			} else if ( dedo ) {
				disparar( document, enlace, 'pointerdown', { button: 0, pointerType: 'touch', clientX: 10, clientY: 10 } );
			}
			return disparar( document, enlace, 'click', { button: 0 } );
		},
		/** El elemento con el foco (`null`: el documento). */
		foco: () => mundo.activo,
		/**
		 * El cliente pulsa una tecla donde tiene el foco (Tab, Av Pág…). Con
		 * `a`, el foco pasa a ese control, como un Tab de verdad: `focusin`.
		 */
		tecla( key, a ) {
			disparar( document, mundo.activo || document.body, 'keydown', { key } );
			if ( a ) {
				root.querySelector( '#' + a ).focus();
				disparar( document, mundo.activo, 'focusin' );
			}
		},
		/** El cliente desplaza la página (`scroll`) o cambia el tamaño de la ventana (`resize`). */
		mover: ( type ) => avisar( ventana, type, { type } ),
		/**
		 * El cliente desplaza un contenedor de la página (la lista de una
		 * faceta): su `scroll` no burbujea, la ventana sólo lo ve en captura.
		 */
		desplazar: ( nodo ) => avisar( ventana, 'scroll', { type: 'scroll', target: nodo }, true ),
		/** La lista de sugerencias del buscador se abre o se cierra, como la deja search-suggest. */
		sugerencias( abierta ) {
			root.querySelector( '#akb-suggest-1' ).hidden = ! abierta;
			root.querySelector( '#akb-search-input' ).setAttribute( 'aria-expanded', abierta ? 'true' : 'false' );
		},
		/** Oyentes de la ventana de un tipo. */
		oyentesDe: ( type ) => ( ventana.get( type ) || [] ).length,
		/** La copia flotante del aviso (flotar()), si la hay. */
		flotante: () => root.querySelector( '[data-akb-aviso-flotante]' ),
		/** El foco llega a `el` (un Tab, un Mayús+Tab): `focusin`. */
		enfocar( el ) {
			el.focus();
			disparar( document, el, 'focusin' );
		},
		/** El ratón se apoya en `el`. */
		apoyar: ( el ) => disparar( document, el, 'pointerdown', { pointerType: 'mouse', button: 0 } ),
		/** Clic (o Enter) en `el`. */
		clic: ( el ) => disparar( document, el, 'click', { button: 0 } ),
		/** El último desplazamiento pedido: el nodo que la página sube a ver. */
		desplazado: () => ( mundo.desplazamientos.at( -1 ) || {} ).nodo || null,
		cargando: () => document.body.classList.contains( 'akb-pjax-loading' ),
		/**
		 * El aviso de conexión: ¿está en la página? (su `<template>` no cuenta),
		 * y lo que leyó la región viva.
		 */
		aviso: () => ( {
			visible: !! root.querySelector( '.akb-aviso-red' ),
			leido: ( root.querySelector( '#akb-live-region' ) || { textContent: '' } ).textContent,
			nodo: root.querySelector( '.akb-aviso-red' ),
		} ),
		/** Un control de la página, por su id. */
		control: ( id ) => root.querySelector( '#' + id ),
		/** La barra de resultados y el `<template>` del aviso que vive en ella. */
		barra: () => root.querySelector( '.akb-shop-toolbar' ),
		molde: () => root.querySelector( 'template[data-akb-aviso-red]' ),
		/**
		 * El cliente cambia una casilla del formulario de filtros: con el dedo
		 * (el `pointerdown` táctil no precarga), con el ratón (sí precarga al
		 * apoyarlo) o con el teclado.
		 */
		marcar( id, { teclado = false, raton = false } = {} ) {
			const casilla = root.querySelector( '#' + id );
			disparar( document, casilla, teclado ? 'keydown' : 'pointerdown', { button: 0, pointerType: raton ? 'mouse' : 'touch', key: ' ', clientX: 10, clientY: 10 } );
			casilla.checked = ! casilla.checked;
			disparar( document, casilla, 'change' );
			return casilla;
		},
		/** Elige otro orden en el desplegable. */
		ordenar( valor ) {
			const select = root.querySelector( '#orden' );
			select.querySelectorAll( 'option' ).forEach( ( o ) => {
				o.selected = o.getAttribute( 'value' ) === valor;
			} );
			select.setAttribute( 'value', valor );
			disparar( document, select, 'change' );
		},
		/** Toque en un botón o chip (`id`) que atiende el propio bundle. */
		tocar: ( id ) => disparar( document, root.querySelector( '#' + id ), 'click', { button: 0 } ),
		/** «Ver N productos» del cajón móvil: envía el formulario de filtros. */
		verN: () => disparar( document, root.querySelector( '#filtros' ), 'submit' ),
		/** Cuántos oyentes de salida hay puestos, de cualquier clase. */
		oyentesDeSalida: () => [ 'beforeunload', 'pagehide', 'click', 'submit' ].reduce( ( n, t ) => n + ( ventana.get( t ) || [] ).length, 0 )
			+ ( navegacion.get( 'navigate' ) || [] ).length,
		soloBeforeunload: () => ( ventana.get( 'beforeunload' ) || [] ).length,
		/** Avanza el reloj `ms` y corre, en orden, lo que venza. */
		avanzar( ms ) {
			const fin = ahora + ms;
			for ( ;; ) {
				const vencidos = temporizadores.filter( ( t ) => t.en <= fin ).sort( ( a, b ) => a.en - b.en || a.id - b.id );
				if ( ! vencidos.length ) {
					break;
				}
				const t = vencidos[ 0 ];
				temporizadores = temporizadores.filter( ( x ) => x !== t );
				ahora = t.en;
				t.fn();
			}
			ahora = fin;
		},
		/** Navegación PJAX (vía popstate) a `busqueda`. Queda en vuelo. */
		ir( busqueda ) {
			location.search = busqueda ? '?_buscar=' + busqueda : '';
			location.href = 'https://akibara.test/tienda/' + location.search;
			avisar( ventana, 'popstate', {} );
			return location.href;
		},
		/** El navegador avisa `beforeunload` (Safari del Mac, Chromium; nunca iPhone). */
		salir() {
			avisar( ventana, 'beforeunload', { type: 'beforeunload' } );
		},
		/** La página nueva se confirma: `pagehide`. */
		ocultar() {
			avisar( ventana, 'pagehide', { type: 'pagehide', persisted: false } );
		},
		/** `navigate` de la Navigation API. */
		navegar( destino, extra = {} ) {
			avisar( navegacion, 'navigate', Object.assign( { navigationType: 'push', destination: destino, downloadRequest: null }, extra ) );
		},
		/**
		 * Clic (o toque) en `id`: el navegador actuará si nadie lo canceló.
		 *
		 * @param {string} id    Elemento pulsado.
		 * @param {object} extra Propiedades del evento, más `detenido` (ver
		 *                       despachar()).
		 */
		pulsar( id, extra = {} ) {
			const { detenido, ...propias } = extra;
			const e = Object.assign( { type: 'click', target: root.querySelector( '#' + id ), button: 0, defaultPrevented: false }, propias );
			return despachar( 'click', e, { detenido } );
		},
		/** Envío del formulario `id` (mismos extras que pulsar()). */
		enviar( id, extra = {} ) {
			const { detenido, ...propias } = extra;
			const e = Object.assign( { type: 'submit', target: root.querySelector( '#' + id ), defaultPrevented: false }, propias );
			return despachar( 'submit', e, { detenido } );
		},
		/** Safari cancela el fetch en vuelo al empezar la navegación. */
		cancelaSafari() {
			pendientes.pop().reject( new TypeError( 'Load failed' ) );
		},
		/** El servidor responde con un error (5xx): no es la red. */
		responderError( status ) {
			pendientes.pop().resolve( { ok: false, status, headers: { get: () => null }, text: () => Promise.resolve( '' ) } );
		},
		responder() {
			pendientes.pop().resolve( {
				ok: true,
				status: 200,
				headers: { get: ( n ) => ( 'content-type' === n.toLowerCase() ? 'text/html; charset=UTF-8' : null ) },
				text: () => Promise.resolve( '<html></html>' ),
			} );
		},
	};
}

test( 'el cliente se va con un PJAX en vuelo: el TypeError de Safari no lo devuelve a la URL del PJAX', async () => {
	const pjax = arrancar();

	pjax.ir( 'naruto' );
	pjax.avanzar( 120 );
	assert.equal( pjax.cargando(), true, 'el atenuado de 120 ms está puesto' );
	pjax.salir();
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 5_000 );

	assert.deepEqual( pjax.asignadas, [], 'Nada de location.assign(): la navegación del cliente gana.' );
	assert.equal( pjax.cargando(), false, 'La página no queda atenuada si la salida no llega a ocurrir.' );
} );

test( 'REV-SS-2: Safari rechaza ANTES de beforeunload (el Atrás): tampoco hay assign', async () => {
	const pjax = arrancar();

	pjax.ir( 'naruto' );
	pjax.avanzar( 120 );
	pjax.cancelaSafari();
	await tick();
	// El hueco medido entre el rechazo y beforeunload: 5-60 ms.
	pjax.avanzar( 60 );
	assert.deepEqual( pjax.asignadas, [], 'el respaldo no sale en el acto' );
	pjax.salir();
	pjax.avanzar( 5_000 );

	assert.deepEqual( pjax.asignadas, [] );
	assert.equal( pjax.cargando(), false, 'la salida retira el atenuado del aviso que esperaba' );
	assert.equal( pjax.oyentesDeSalida(), 0, 'y sus oyentes' );
	assert.equal( pjax.aviso().visible, false, 'sin un «no pudimos cargar» que quedaría en el bfcache' );
} );

test( 'C-S-c1 / C-S-c2: un fallo de red de verdad, sin salida, no navega solo con ni sin Navigation API: a los 4 s lo avisa y el siguiente gesto va sin PJAX', async () => {
	for ( const navigationApi of [ true, false ] ) {
		const cual = `navigationApi=${ navigationApi }`;
		const pjax = arrancar( { navigationApi } );

		pjax.ir( 'naruto' );
		pjax.avanzar( 120 );
		pjax.cancelaSafari();
		await tick();

		pjax.avanzar( ESPERA - 1 );
		assert.deepEqual( pjax.asignadas, [], `${ cual }: mientras espera, nada` );
		assert.equal( pjax.cargando(), true, `${ cual }: la navegación sigue viva, el cliente ve que carga` );
		assert.ok( pjax.oyentesDeSalida() > 0, `${ cual }: y sigue escuchando la salida` );
		assert.equal( pjax.aviso().visible, false, `${ cual }: todavía sin aviso` );

		pjax.avanzar( 1 );
		assert.deepEqual( pjax.asignadas, [], `${ cual }: ningún location.assign() automático: le puede ganar al Atrás` );
		assert.equal( pjax.cargando(), false, `${ cual }: sin atenuado` );
		assert.equal( pjax.oyentesDeSalida(), 0, `${ cual }: y sin oyentes` );
		assert.equal( pjax.aviso().visible, true, `${ cual }: el cliente lee qué pasó` );
		pjax.avanzar( 60 );
		assert.equal( pjax.aviso().leido, AVISO, `${ cual }: y la región viva lo lee` );

		pjax.paginar();
		assert.deepEqual( pjax.asignadas, [ 'https://akibara.test/tienda/page/2/' ], `${ cual }: el siguiente toque navega como sin JS` );
		assert.equal( pjax.enVuelo(), 0, `${ cual }: sin otro fetch que pueda fallar` );
	}
} );

test( 'REV-C-S-1f-1 / 2f-1: con Navigation API, el Atrás a OTRO sitio (sin navigate ni beforeunload, pagehide a los 3,5 s) no termina en la URL del PJAX ni avisa', async () => {
	// Medido en WebKit 2342: hacia otro origen no se despacha ningún `navigate`
	// (tampoco el traverse) ni `beforeunload` antes de que esa página se
	// confirme. Con el respaldo de 1 s, el assign a la URL del PJAX salía antes
	// y le ganaba al Atrás 10 de 10 veces con la página anterior a 2-3 s.
	for ( const beforeunload of [ false, true ] ) {
		const pjax = arrancar();

		pjax.ir( 'naruto' );
		pjax.avanzar( 120 );
		pjax.cancelaSafari();
		await tick();
		pjax.avanzar( 3_500 );
		// REV-C-S-2f-1: con el aviso a 1 s, la página que se iba lo mostraba y
		// lo anunciaba.
		assert.equal( pjax.aviso().visible, false, `beforeunload=${ beforeunload }: sin aviso en la página que se va` );
		assert.equal( pjax.aviso().leido, '', `beforeunload=${ beforeunload }: ni anuncio` );
		if ( beforeunload ) {
			pjax.salir(); // Safari del Mac: el único beforeunload, al confirmarse.
		}
		pjax.ocultar();
		pjax.avanzar( 5_000 );

		assert.deepEqual( pjax.asignadas, [], `beforeunload=${ beforeunload }: el Atrás del cliente gana` );
		assert.equal( pjax.aviso().visible, false, `beforeunload=${ beforeunload }: ni en lo que guarda el bfcache` );
	}
} );

test( 'un error del SERVIDOR (5xx) no espera, con o sin Navigation API: al respaldo en el acto', async () => {
	for ( const navigationApi of [ true, false ] ) {
		const pjax = arrancar( { navigationApi } );

		const url = pjax.ir( 'naruto' );
		pjax.responderError( 500 );
		await tick();

		assert.deepEqual( pjax.asignadas, [ url ], `navigationApi=${ navigationApi }: sólo el fallo de red puede ser Safari yéndose` );
	}
} );

test( 'REV-S-S-1f-1: iPhone con Navigation API (iOS 26.2+) y sin beforeunload: el navigate de un enlace gana', async () => {
	const pjax = arrancar();

	pjax.ir( 'naruto' );
	pjax.navegar( { url: 'https://akibara.test/series/', sameDocument: false } );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 5_000 );

	assert.deepEqual( pjax.asignadas, [] );
} );

test( 'REV-S-S-1f-1: iPhone con Navigation API, Atrás: el navigate traverse llega después del rechazo y también gana', async () => {
	const pjax = arrancar();

	pjax.ir( 'naruto' );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 30 );
	pjax.navegar( { url: 'https://akibara.test/series/', sameDocument: false }, { navigationType: 'traverse' } );
	pjax.avanzar( 5_000 );

	assert.deepEqual( pjax.asignadas, [] );
} );

test( 'un navigate del MISMO documento (un ancla) o una descarga no es una salida: el fallo se avisa', async () => {
	for ( const [ destino, extra ] of [
		[ { url: 'https://akibara.test/tienda/#resultados', sameDocument: true }, {} ],
		[ { url: 'https://akibara.test/catalogo.pdf', sameDocument: false }, { downloadRequest: '' } ],
	] ) {
		const pjax = arrancar();
		pjax.ir( 'naruto' );
		pjax.navegar( destino, extra );
		pjax.cancelaSafari();
		await tick();
		pjax.avanzar( ESPERA );
		assert.equal( pjax.aviso().visible, true, JSON.stringify( destino ) );
		assert.deepEqual( pjax.asignadas, [], JSON.stringify( destino ) );
	}
} );

test( 'C-S: iPhone sin Navigation API (antes de iOS 26.2), Atrás con la página anterior lenta: el PJAX no navega solo, nunca', async () => {
	// Medido en WebKit: el rechazo llega al empezar el Atrás y `pagehide`, la
	// única señal de ese Safari, cuando la página anterior se confirma. Con esa
	// página a 1,1-2 s, el respaldo diferido 1 s se llevaba al cliente a la URL
	// del PJAX 26 de 26 veces. Aquí la página anterior no se confirma nunca.
	const pjax = arrancar( { navigationApi: false } );

	pjax.ir( 'naruto' );
	pjax.avanzar( 120 );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 60_000 );

	assert.deepEqual( pjax.asignadas, [], 'el Atrás del cliente gana' );
	assert.equal( pjax.cargando(), false, 'sin atenuado: si no era un Atrás, la página sigue usable' );
	assert.equal( pjax.oyentesDeSalida(), 0, 'y sin oyentes' );
} );

test( 'C-S: sin Navigation API, tras un fallo de red el siguiente gesto del catálogo navega sin PJAX', async () => {
	const pjax = arrancar( { navigationApi: false } );

	pjax.ir( 'naruto' );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 5_000 );
	assert.deepEqual( pjax.asignadas, [], 'el fallo no navega solo' );

	// El cliente vuelve a tocar «página 2»: esta vez, navegación nativa.
	const toque = pjax.paginar();
	assert.equal( toque.defaultPrevented, true, 'el PJAX sigue siendo quien atiende el toque' );
	assert.deepEqual( pjax.asignadas, [ 'https://akibara.test/tienda/page/2/' ], 'y lo lleva a su destino como sin JS' );
	assert.equal( pjax.enVuelo(), 0, 'sin un fetch que pueda volver a fallar' );
	assert.equal( pjax.cargando(), false );

	// Un Atrás dentro de la página (popstate) también: recarga lo que dice la barra.
	const url = pjax.ir( 'bleach' );
	assert.deepEqual( pjax.asignadas.slice( -1 ), [ url ] );
	assert.equal( pjax.enVuelo(), 0 );
} );

test( 'C-S: sin Navigation API, una salida que el navegador SÍ avisó (beforeunload) no degrada el siguiente filtro', async () => {
	// Safari del Mac anterior a 26.2: sin Navigation API pero con beforeunload.
	const pjax = arrancar( { navigationApi: false } );

	pjax.ir( 'naruto' );
	pjax.salir();
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 5_000 );
	assert.deepEqual( pjax.asignadas, [] );

	// La salida no llegó a ocurrir (un mailto:): el siguiente filtro va por PJAX.
	pjax.ir( 'bleach' );
	assert.equal( pjax.enVuelo(), 1 );
	assert.deepEqual( pjax.asignadas, [] );
} );

test( 'iPhone sin Navigation API: ningún gesto de salida termina en la URL del PJAX ni avisa en la página que deja (REV-C-S-2o-3 y 2f-1)', async () => {
	// Cuándo se confirma la página nueva: su `pagehide`, la única señal de ese
	// Safari. Con el aviso a 1 s, en todos estos casos la página que se iba
	// mostraba y anunciaba «No pudimos cargar…», revertía los controles y, con
	// el puntero, subía hasta él (medido en WebKit, 3 de 3 por gesto).
	const casos = [
		// Un clic o un envío a otro documento: espera a pagehide (tope 8 s).
		[ 'toque en un enlace del pie (en el texto del enlace), la página nueva a los 6 s', ( pjax ) => pjax.pulsar( 'series-texto' ), 6_000 ],
		[ 'toque en una sugerencia del buscador (su lista corta la burbuja), a los 6 s', ( pjax ) => pjax.pulsar( 'series-texto', { detenido: true } ), 6_000 ],
		[ 'el buscador de la cabecera enviado, a los 6 s', ( pjax ) => pjax.enviar( 'buscador' ), 6_000 ],
		[ 'el buscador enviado aunque alguien corte la burbuja, a los 6 s', ( pjax ) => pjax.enviar( 'buscador', { detenido: true } ), 6_000 ],
		// Sin señal previa: la espera con conexión (4 s).
		[ 'Atrás, con la página anterior a los 400 ms', () => {}, 400 ],
		[ 'Atrás, con la página anterior a los 3,5 s', () => {}, 3_500 ],
	];
	for ( const [ cual, gesto, confirmada ] of casos ) {
		const pjax = arrancar( { navigationApi: false } );

		pjax.ir( 'naruto' );
		pjax.avanzar( 120 );
		gesto( pjax );
		pjax.cancelaSafari();
		await tick();
		pjax.avanzar( confirmada );
		assert.equal( pjax.aviso().visible, false, `${ cual }: sin aviso en la página que se va` );
		assert.equal( pjax.aviso().leido, '', `${ cual }: sin anunciarlo` );
		assert.deepEqual( mundo.desplazamientos, [], `${ cual }: sin moverla` );
		assert.equal( pjax.cargando(), true, `${ cual }: mientras tanto, cargando` );

		pjax.ocultar();
		pjax.avanzar( SALIDA );
		assert.deepEqual( pjax.asignadas, [], cual );
		assert.equal( pjax.aviso().visible, false, `${ cual }: ni después, en lo que guarda el bfcache` );
		assert.equal( pjax.cargando(), false, `${ cual }: sin atenuado` );
		assert.equal( pjax.oyentesDeSalida(), 0, `${ cual }: y sin oyentes` );
	}
} );

test( 'REV-C-S-2o-3: un enlace pulsado que al final no navega (una respuesta 204) avisa igual, a los 8 s', async () => {
	const pjax = arrancar( { navigationApi: false } );

	pjax.ir( 'naruto' );
	pjax.pulsar( 'series-texto' );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( SALIDA - 1 );
	assert.equal( pjax.aviso().visible, false );
	pjax.avanzar( 1 );
	assert.equal( pjax.aviso().visible, true, 'nadie se fue: el cliente lee qué pasó' );
	assert.equal( pjax.oyentesDeSalida(), 0 );
	assert.deepEqual( pjax.asignadas, [] );
} );

test( 'REV-C-S-2o-3: el clic alarga la espera llegue antes o después del rechazo del fetch', async () => {
	for ( const antes of [ true, false ] ) {
		const cual = antes ? 'el clic se decide antes del rechazo' : 'el rechazo llega antes de que se decida el clic';
		const pjax = arrancar( { navigationApi: false } );

		pjax.ir( 'naruto' );
		pjax.pulsar( 'series-texto' );
		if ( antes ) {
			pjax.avanzar( 0 ); // la tarea que mira defaultPrevented tras el despacho
		}
		pjax.cancelaSafari();
		await tick();
		pjax.avanzar( ESPERA + 500 );
		assert.equal( pjax.aviso().visible, false, `${ cual }: a los 4,5 s sigue esperando la página nueva` );
		pjax.avanzar( SALIDA );
		assert.equal( pjax.aviso().visible, true, `${ cual }: sin pagehide, avisa` );
	}
} );

test( 'REV-C-S-2o-3: el clic de un PJAX anterior no alarga la espera del siguiente', async () => {
	const pjax = arrancar( { navigationApi: false } );

	// Un enlace pulsado con un PJAX en vuelo que al final no navegó (un 204), y
	// ese PJAX llega bien.
	pjax.ir( 'naruto' );
	pjax.pulsar( 'series-texto' );
	pjax.avanzar( 0 );
	pjax.responder();
	await tick();

	// El siguiente filtro falla por la red, sin ningún clic: la espera de siempre.
	pjax.ir( 'bleach' );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( ESPERA );
	assert.equal( pjax.aviso().visible, true );
} );

test( 'REV-C-S-2o-3: lo que no se lleva la página no alarga la espera', async () => {
	const casos = [
		[ 'tel:', ( pjax ) => pjax.pulsar( 'telefono' ) ],
		[ 'mailto:', ( pjax ) => pjax.pulsar( 'correo' ) ],
		[ 'una descarga (download)', ( pjax ) => pjax.pulsar( 'catalogo-pdf' ) ],
		[ 'otra pestaña (target=_blank)', ( pjax ) => pjax.pulsar( 'instagram' ) ],
		[ 'un ancla de esta misma página', ( pjax ) => pjax.pulsar( 'ancla' ) ],
		[ 'un enlace con Cmd (otra pestaña)', ( pjax ) => pjax.pulsar( 'series-texto', { metaKey: true } ) ],
		[ 'un clic que otro oyente canceló (preventDefault)', ( pjax ) => {
			const e = pjax.pulsar( 'series-texto' );
			e.defaultPrevented = true; // lo cancela un oyente de más abajo, tras la captura
		} ],
	];
	for ( const [ cual, gesto ] of casos ) {
		const pjax = arrancar( { navigationApi: false } );

		pjax.ir( 'naruto' );
		gesto( pjax );
		pjax.cancelaSafari();
		await tick();
		pjax.avanzar( ESPERA );
		assert.equal( pjax.aviso().visible, true, `${ cual }: a los 4 s, como un fallo sin gesto` );
	}
} );

test( 'C-S-c2: sin conexión (navigator.onLine en false) el aviso no espera a una página nueva: sale al segundo', async () => {
	for ( const navigationApi of [ true, false ] ) {
		const cual = `navigationApi=${ navigationApi }`;
		const pjax = arrancar( { navigationApi, enLinea: false } );

		pjax.ir( 'naruto' );
		pjax.cancelaSafari();
		await tick();
		pjax.avanzar( SIN_CONEXION - 1 );
		assert.equal( pjax.aviso().visible, false, `${ cual }: todavía deja llegar a navigate/beforeunload` );
		pjax.avanzar( 1 );
		assert.equal( pjax.aviso().visible, true, cual );
	}
} );

test( 'un filtro nuevo durante la espera del respaldo lo deja sin efecto', async () => {
	const pjax = arrancar();

	pjax.ir( 'naruto' );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 500 );
	// El cliente sigue en la página y toca otro filtro, que esta vez responde.
	pjax.ir( 'bleach' );
	pjax.responder();
	await tick();
	pjax.avanzar( 5_000 );

	assert.deepEqual( pjax.asignadas, [], 'el respaldo de «naruto» ya no se lleva al cliente' );
	assert.equal( pjax.cargando(), false );
	assert.equal( pjax.oyentesDeSalida(), 0 );
} );

test( 'un Atrás dentro de la página durante la espera también lo deja sin efecto', async () => {
	const pjax = arrancar();

	pjax.ir( 'naruto' );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 500 );
	// Atrás a lo que ya está pintado: cancelInflight() y ninguna navegación nueva.
	pjax.ir( '' );
	pjax.avanzar( 5_000 );

	assert.deepEqual( pjax.asignadas, [] );
	assert.equal( pjax.cargando(), false );
	assert.equal( pjax.oyentesDeSalida(), 0 );
} );

test( 'una salida que no llegó a ocurrir (un mailto: con beforeunload) no deja al siguiente PJAX sin aviso', async () => {
	const pjax = arrancar();

	pjax.ir( 'naruto' );
	pjax.salir();
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 5_000 );
	assert.deepEqual( pjax.asignadas, [] );
	assert.equal( pjax.aviso().visible, false, 'la página se iba: nada que avisar' );

	// El cliente sigue en la página y toca otro filtro, que esta vez falla.
	pjax.ir( 'bleach' );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( ESPERA );

	assert.equal( pjax.aviso().visible, true );
	assert.deepEqual( pjax.asignadas, [] );
} );

test( 'los oyentes de salida viven sólo con un PJAX en vuelo', async () => {
	const pjax = arrancar();
	assert.equal( pjax.oyentesDeSalida(), 0, 'En reposo la página no escucha la salida (bfcache).' );

	pjax.ir( 'naruto' );
	assert.equal( pjax.soloBeforeunload(), 1, 'Con el PJAX en vuelo, un único oyente de beforeunload.' );
	assert.equal( pjax.oyentesDeSalida(), 5, 'beforeunload, pagehide, navigate, y el clic y el envío que alargan la espera del aviso' );

	// Uno más nuevo reemplaza al anterior: no se acumulan.
	pjax.ir( 'bleach' );
	assert.equal( pjax.oyentesDeSalida(), 5 );

	pjax.responder();
	await tick();
	assert.equal( pjax.oyentesDeSalida(), 0, 'Terminado el PJAX, los oyentes se retiran.' );
	assert.deepEqual( pjax.asignadas, [] );

	const sinApi = arrancar( { navigationApi: false } );
	sinApi.ir( 'naruto' );
	assert.equal( sinApi.oyentesDeSalida(), 4, 'Sin Navigation API: beforeunload, pagehide, el clic y el envío.' );
} );

test( 'el Atrás que cancela un PJAX en vuelo también retira los oyentes', async () => {
	const pjax = arrancar();

	pjax.ir( 'naruto' );
	assert.equal( pjax.oyentesDeSalida(), 5 );

	// Atrás a lo que ya está pintado: cancelInflight() y ninguna navegación nueva.
	pjax.ir( '' );
	await tick();

	assert.equal( pjax.oyentesDeSalida(), 0 );
	assert.deepEqual( pjax.asignadas, [] );
} );

/*
 * REV-C-S-1o-1 y 1o-2: sin navegar solo, la interfaz no puede afirmar lo que no
 * llegó y el gesto natural del cliente tiene que aplicar lo que pidió.
 */
test( 'REV-C-S-1o-2: la casilla de escritorio vuelve a lo pintado y volver a tocarla aplica el filtro', async () => {
	for ( const navigationApi of [ true, false ] ) {
		const cual = `navigationApi=${ navigationApi }`;
		const pjax = arrancar( { navigationApi } );

		const casilla = pjax.marcar( 'disponible' );
		pjax.avanzar( 100 ); // el debounce del cambio
		assert.equal( pjax.enVuelo(), 1, `${ cual }: la casilla lanza un PJAX` );
		pjax.cancelaSafari();
		await tick();
		pjax.avanzar( ESPERA );

		assert.equal( casilla.checked, false, `${ cual }: la casilla no afirma un filtro que no está aplicado` );
		assert.equal( pjax.aviso().visible, true, `${ cual }: y el aviso dice por qué` );
		assert.equal( mundo.desplazamientos.at( -1 )?.nodo, pjax.control( 'reintentar' ).parentNode, `${ cual }: con el puntero la página sube hasta el aviso` );

		// El reintento natural: volver a tocar la misma casilla.
		pjax.marcar( 'disponible' );
		pjax.avanzar( 100 );
		assert.deepEqual( pjax.asignadas, [ '/tienda/?_buscar=naruto&_disponibilidad=instock' ], `${ cual }: la marca y navega con el filtro, no sin él` );
	}
} );

test( 'REV-C-S-1o-2: con el teclado, el foco se queda en su control y la página no se mueve', async () => {
	const pjax = arrancar();

	pjax.marcar( 'disponible', { teclado: true } );
	pjax.avanzar( 100 );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( ESPERA + 60 );

	assert.equal( pjax.aviso().visible, true );
	assert.equal( pjax.aviso().leido, AVISO, 'el lector de pantalla lo oye' );
	assert.deepEqual( mundo.desplazamientos, [], 'sin desplazar la página bajo el teclado' );
} );

test( 'REV-C-S-2o-2: la paginación con el teclado lleva el foco con la vista, a «Reintentar»', async () => {
	const pjax = arrancar();

	pjax.paginar( { teclado: true } );
	assert.equal( pjax.foco(), pjax.control( 'pagina-2' ), 'el enlace de la página 2 tiene el foco' );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( ESPERA );

	const aviso = pjax.aviso().nodo;
	assert.equal( pjax.desplazado(), aviso, 'la página sube hasta el aviso' );
	assert.equal( pjax.foco(), pjax.control( 'reintentar' ), 'y el foco va con ella: el siguiente Tab no devuelve la página al pie' );
	assert.equal( pjax.flotante(), null, 'con la vista en el aviso no hace falta copia' );
} );

test( 'REV-C-S-2o-2: con el puntero la página sube hasta el aviso y el foco no se toca', async () => {
	const pjax = arrancar();

	pjax.paginar( { dedo: true } );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( ESPERA );

	assert.equal( pjax.desplazado(), pjax.aviso().nodo );
	assert.equal( pjax.foco(), null, 'el toque no enfoca nada, y el aviso tampoco' );
	assert.equal( pjax.flotante(), null );
} );

test( 'REV-C-S-2o-2 / 3o-1: si durante la espera el foco se fue a otro sitio, el aviso no se lo lleva ni mueve la página', async () => {
	const pjax = arrancar();

	pjax.paginar( { teclado: true } );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 2_000 );
	pjax.control( 'orden' ).focus(); // el cliente sigue mientras carga (un lector de pantalla, sin teclas)
	pjax.avanzar( ESPERA );

	assert.equal( pjax.aviso().visible, true );
	assert.equal( pjax.foco(), pjax.control( 'orden' ) );
	assert.deepEqual( mundo.desplazamientos, [], 'subirla dejaba el foco fuera de la vista (REV-C-S-3o-1)' );
	assert.equal( pjax.control( 'orden' ).nextSibling, pjax.flotante(), 'el aviso flota junto al foco' );
} );

test( 'REV-C-S-2o-2: sin botón (el texto del panel sin corchetes) el foco va al aviso mismo', async () => {
	const pjax = arrancar();
	// El molde sin botón, como lo pinta el servidor con un texto sin corchetes.
	const molde = pjax.molde();
	const parrafo = molde.content.querySelector( '.akb-aviso-red' );
	parrafo.textContent = 'Sin conexión.';

	pjax.paginar( { teclado: true } );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( ESPERA );

	const aviso = pjax.aviso().nodo;
	assert.equal( pjax.foco(), aviso );
	assert.equal( aviso.getAttribute( 'tabindex' ), '-1', 'enfocable por script, fuera del orden del Tab' );
} );

/*
 * REV-C-S-3o-1, 3o-2 y 3f-1 (lote D-S): quien ve la pantalla ve el aviso, y
 * tiene «Reintentar» a una tecla, aunque el foco se quede donde está. Si el
 * cliente siguió con el teclado durante la espera (una tecla, aunque no mueva
 * el foco), o si su control conserva el foco (la casilla con Espacio), ni el
 * foco ni la página se mueven: subirla dejaba el foco 4.000 px más abajo y el
 * siguiente Tab la devolvía al pie. Si desde ahí no se ve el aviso de la
 * barra, una copia flota junto al foco.
 */

/**
 * Cajas de la ventana de 1440 × 900: la copia mide 416 × 60 y se pinta donde
 * dicen su `top` y su `left`, menos `desfase` (lo que WebKit corre un fijo con
 * la ventana visible más chica que la de maquetación); `otras` dice las demás.
 */
function geometria( otras, desfase = 0 ) {
	mundo.caja = ( n ) => ( n.matches( '.akb-aviso-flotante' )
		? { top: ( parseFloat( n.style.top ) || 0 ) - desfase, left: parseFloat( n.style.left ) || 0, width: 416, height: 60 }
		: otras.get( n ) || null );
}

test( 'REV-C-S-3o-1: con el teclado, si el cliente sigue tabulando durante la espera, ni la página ni el foco se mueven y el aviso flota bajo su foco, lo siguiente del Tab', async () => {
	const pjax = arrancar();
	const series = pjax.control( 'series' );
	geometria( new Map( [ [ series, { top: 500, left: 600, width: 60, height: 20 } ] ] ) );

	pjax.paginar( { teclado: true } );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 1_500 );
	pjax.tecla( 'Tab', 'series' );
	pjax.avanzar( ESPERA );

	assert.equal( pjax.aviso().leido, AVISO, 'la región viva lo lee, como siempre' );
	assert.deepEqual( mundo.desplazamientos, [], 'la página se queda donde el cliente la tiene' );
	assert.equal( pjax.foco(), series, 'y el foco donde lo llevó' );
	const copia = pjax.flotante();
	assert.equal( series.nextSibling, copia, 'en el DOM, justo después del foco: lo siguiente del Tab' );
	assert.equal( copia.getAttribute( 'popover' ), 'manual', 'en la capa superior, sin romper lo que la rodea' );
	assert.deepEqual( mundo.capaSuperior, [ copia ] );
	assert.equal( copia.textContent, AVISO, 'la frase del panel' );
	assert.ok( copia.querySelector( '[data-akb-reintentar]' ), 'con su «Reintentar»' );
	assert.equal( copia.style.top, '528px', 'debajo del foco (500 + 20 + 8)' );
	assert.equal( copia.style.left, '600px', 'alineada con él' );
} );

test( 'REV-C-S-3o-1: si el navegador pinta los fijos corridos respecto de sus cajas (WebKit, zoom de pellizco), la copia se corrige y queda donde se calculó', async () => {
	const pjax = arrancar();
	const series = pjax.control( 'series' );
	geometria( new Map( [ [ series, { top: 499, left: 211, width: 44, height: 44 } ] ] ), 11 );

	pjax.paginar( { teclado: true } );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 1_500 );
	pjax.tecla( 'Tab', 'series' );
	pjax.avanzar( ESPERA );

	const copia = pjax.flotante();
	assert.equal( copia.getBoundingClientRect().top, 551, 'se ve bajo el foco (499 + 44 + 8), no 11 px encima: lo tapaba' );
	assert.equal( copia.style.top, '562px' );
} );

test( 'REV-C-S-3o-1: una tecla que no mueve el foco (Av Pág) también es seguir: con el foco fuera de la ventana, la copia queda al pie, sobre la franja fija', async () => {
	const pjax = arrancar( { navigationApi: false } );
	const pagina2 = pjax.control( 'pagina-2' );
	// El foco sigue en el paginador, que Re Pág dejó 400 px bajo la ventana.
	geometria( new Map( [ [ pagina2, { top: 1_300, left: 1_300, width: 40, height: 40 } ] ] ) );
	mundo.franja = '66px'; // el bottom-nav del teléfono

	pjax.paginar( { teclado: true } );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 1_500 );
	pjax.tecla( 'PageUp' );
	pjax.avanzar( ESPERA );

	assert.deepEqual( mundo.desplazamientos, [], 'no la devuelve al aviso' );
	assert.equal( pjax.foco(), pagina2, 'ni le quita el foco' );
	const copia = pjax.flotante();
	assert.equal( pagina2.nextSibling, copia );
	assert.equal( copia.style.top, '758px', 'al pie de la ventana, sobre la franja (900 − 66 − 16 − 60)' );
	assert.equal( copia.style.left, '1008px', 'y dentro de ella (1440 − 416 − 16)' );
} );

test( 'REV-C-S-3o-2 / 3f-1: la casilla marcada con Espacio y la barra fuera de la vista: vuelve a lo pintado y el aviso flota junto a ella, encima si abajo no cabe, sin moverle el foco ni la página', async () => {
	for ( const navigationApi of [ true, false ] ) {
		const cual = `navigationApi=${ navigationApi }`;
		const pjax = arrancar( { navigationApi } );
		const casilla = pjax.control( 'disponible' );
		const etiqueta = casilla.parentNode;
		// La casilla al pie de la ventana, como la deja el navegador al enfocarla.
		geometria( new Map( [ [ etiqueta, { top: 850, left: 44, width: 280, height: 30 } ] ] ) );

		casilla.focus();
		pjax.marcar( 'disponible', { teclado: true } );
		pjax.avanzar( 100 );
		pjax.cancelaSafari();
		await tick();
		pjax.avanzar( ESPERA );

		assert.equal( casilla.checked, false, `${ cual }: vuelve a lo pintado` );
		assert.equal( pjax.foco(), casilla, `${ cual }: el foco sigue en la casilla (WCAG 3.2.2)` );
		assert.deepEqual( mundo.desplazamientos, [], `${ cual }: la página no se mueve` );
		const copia = pjax.flotante();
		assert.equal( etiqueta.nextSibling, copia, `${ cual }: la explicación, junto a la casilla y a un Tab` );
		assert.equal( copia.style.top, '782px', `${ cual }: encima de la casilla (850 − 8 − 60), porque debajo no cabe` );
		assert.ok( 782 + 60 <= 850, `${ cual }: sin taparla` );
		assert.equal( copia.style.left, '44px', cual );
	}
} );

test( 'con el aviso de la barra a la vista no hay copia; bajo la cabecera fija (otra cosa en su sitio), sí', async () => {
	for ( const { tapa, copia } of [ { tapa: false, copia: false }, { tapa: true, copia: true } ] ) {
		const pjax = arrancar();
		const casilla = pjax.control( 'disponible' );
		mundo.alPunto = () => ( tapa ? pjax.control( 'series' ) : pjax.barra().querySelector( '[data-akb-reintentar]' ) );

		casilla.focus();
		pjax.marcar( 'disponible', { teclado: true } );
		pjax.avanzar( 100 );
		pjax.cancelaSafari();
		await tick();
		pjax.avanzar( ESPERA );

		assert.equal( null !== pjax.flotante(), copia, tapa ? 'tapado no se ve' : 'si se ve, basta con él' );
		assert.equal( pjax.foco(), casilla );
	}
} );

/** Deja la copia flotando junto a la casilla marcada con Espacio. */
async function conCopiaEnLaCasilla( opciones ) {
	const pjax = arrancar( opciones );
	const casilla = pjax.control( 'disponible' );
	casilla.focus();
	pjax.marcar( 'disponible', { teclado: true } );
	pjax.avanzar( 100 );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( ESPERA );
	const copia = pjax.flotante();
	assert.ok( copia, 'la copia flota' );
	return { pjax, casilla, copia, boton: copia.querySelector( '[data-akb-reintentar]' ) };
}

test( 'REV-C-S-3o-2: la copia se queda mientras el cliente va de su control a «Reintentar», y se retira con lo siguiente: otro foco, un toque fuera, Escape o la salida', async () => {
	// Tab a «Reintentar», Mayús+Tab de vuelta a la casilla, o apoyar el ratón
	// en cualquiera de las dos: sigue ahí.
	let { pjax, casilla, copia, boton } = await conCopiaEnLaCasilla();
	for ( const a of [ boton, casilla, casilla.parentNode ] ) {
		pjax.enfocar( a );
		pjax.apoyar( a );
		assert.equal( pjax.flotante(), copia, 'ir y volver no es irse' );
	}

	// Escape con el foco en «Reintentar»: se retira y el foco vuelve a la casilla.
	pjax.enfocar( boton );
	pjax.tecla( 'Escape' );
	assert.equal( pjax.flotante(), null, 'Escape la retira' );
	assert.equal( pjax.foco(), casilla, 'y el foco vuelve a su control' );

	// Otro foco fuera (el siguiente Tab tras «Reintentar»).
	( { pjax, copia } = await conCopiaEnLaCasilla() );
	pjax.tecla( 'Tab', 'orden' );
	assert.equal( pjax.flotante(), null, 'otro foco la retira: no tapa lo siguiente que enfoque (WCAG 2.4.11)' );

	// Un toque fuera.
	( { pjax } = await conCopiaEnLaCasilla() );
	pjax.apoyar( pjax.control( 'series' ) );
	assert.equal( pjax.flotante(), null, 'un toque fuera la retira' );

	// La página se va: el bfcache no la guarda flotando.
	( { pjax } = await conCopiaEnLaCasilla() );
	pjax.ocultar();
	assert.equal( pjax.flotante(), null, 'pagehide la retira' );

	// Una tecla cualquiera no: es seguir leyendo.
	( { pjax, copia } = await conCopiaEnLaCasilla() );
	pjax.tecla( 'ArrowDown' );
	assert.equal( pjax.flotante(), copia );
} );

test( 'REV-C-S-3o-2: la copia sigue a su control si el cliente mueve la página o la ventana cambia, y al retirarse deja de oír', async () => {
	const pjax = arrancar();
	const series = pjax.control( 'series' );
	const cajas = new Map( [ [ series, { top: 500, left: 600, width: 60, height: 20 } ] ] );
	geometria( cajas );

	pjax.paginar( { teclado: true } );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 1_500 );
	pjax.tecla( 'Tab', 'series' );
	pjax.avanzar( ESPERA );
	const copia = pjax.flotante();
	assert.equal( copia.style.top, '528px' );

	// El cliente baja la página 30 px con las flechas (no la retiran): el control sube.
	cajas.set( series, { top: 470, left: 600, width: 60, height: 20 } );
	pjax.tecla( 'ArrowDown' );
	pjax.mover( 'scroll' );
	pjax.avanzar( 16 );
	assert.equal( copia.style.top, '498px', 'sube con su control: no se le queda encima' );

	// La ventana cambia (el teléfono gira) y el control queda fuera por arriba: la copia, en el borde.
	cajas.set( series, { top: -200, left: 600, width: 60, height: 20 } );
	pjax.mover( 'resize' );
	pjax.avanzar( 16 );
	assert.equal( copia.style.top, '16px', 'a la vista, en el borde más cercano' );

	pjax.tecla( 'Escape' );
	assert.equal( pjax.flotante(), null );
	assert.equal( pjax.oyentesDe( 'scroll' ), 0, 'ni scroll…' );
	assert.equal( pjax.oyentesDe( 'resize' ), 0, '…ni resize' );
} );

/*
 * D-S-c1 (REV-D-S-1o-2 y 1f-1): la lista de una faceta se desplaza por dentro
 * (`.akb-facet-list`: 260 px, `overflow-y: auto`). Su `scroll` no llegaba a la
 * copia, que se quedaba encima de su casilla, y con la casilla escondida por la
 * lista la copia acompañaba a un control que no se veía, encima de otros dos.
 */

/**
 * La casilla del filtro dentro de una lista que se desplaza por dentro, de
 * 300 a 560 (el formulario hace de lista). Devuelve las cajas para moverlas.
 */
function enUnaLista( pjax, casillaTop ) {
	const lista = pjax.control( 'filtros' );
	const etiqueta = pjax.control( 'disponible' ).parentNode;
	const cajas = new Map( [
		[ lista, { top: 300, left: 38, width: 292, height: 260 } ],
		[ etiqueta, { top: casillaTop, left: 38, width: 292, height: 38 } ],
	] );
	geometria( cajas );
	mundo.desborde = ( n ) => ( n === lista ? 'auto' : 'visible' );
	/** La lista mueve la casilla a `top` y avisa con su `scroll`. */
	const moverLaCasilla = ( top ) => {
		cajas.set( etiqueta, { top, left: 38, width: 292, height: 38 } );
		pjax.desplazar( lista );
		pjax.avanzar( 16 );
	};
	return { etiqueta, moverLaCasilla };
}

/** Marca la casilla con Espacio y deja vencer la espera del aviso. */
async function marcadaConEspacio( pjax ) {
	const casilla = pjax.control( 'disponible' );
	casilla.focus();
	pjax.marcar( 'disponible', { teclado: true } );
	pjax.avanzar( 100 );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( ESPERA );
	return casilla;
}

test( 'REV-D-S-1o-2 / 1f-1: la copia sigue a su casilla cuando el cliente desplaza la lista de la faceta, no sólo la página', async () => {
	const pjax = arrancar();
	const { moverLaCasilla } = enUnaLista( pjax, 350 );
	await marcadaConEspacio( pjax );
	const copia = pjax.flotante();
	assert.equal( copia.style.top, '396px', 'bajo la casilla (350 + 38 + 8)' );

	// ArrowUp (Chromium desplaza la lista con las flechas) o la rueda sobre ella.
	pjax.tecla( 'ArrowUp' );
	moverLaCasilla( 390 );
	assert.equal( pjax.flotante(), copia, 'ni las flechas ni la rueda la retiran' );
	assert.equal( copia.style.top, '436px', 'baja con su casilla: no se le queda encima (390 + 38 + 8)' );
	moverLaCasilla( 290 );
	assert.equal( copia.style.top, '336px', 'con la casilla medio escondida por arriba, bajo lo que se ve de ella (300…328 + 8)' );

	pjax.tecla( 'Escape' );
	assert.equal( pjax.oyentesDe( 'scroll' ), 0, 'al retirarse deja de oír el scroll, también el de captura' );
} );

test( 'REV-D-S-1f-1: con la casilla escondida por la lista la copia no se pinta encima de las otras, y vuelve con ella', async () => {
	const pjax = arrancar();
	const { moverLaCasilla } = enUnaLista( pjax, 350 );
	await marcadaConEspacio( pjax );
	const copia = pjax.flotante();

	moverLaCasilla( 250 ); // 250…288, sobre el borde de la lista (300)
	assert.equal( pjax.flotante(), copia, 'sigue en la página: el cliente puede volver a su casilla' );
	assert.equal( copia.style.visibility, 'hidden', 'pero no se pinta: no tapa las casillas que sí se ven' );
	moverLaCasilla( 580 ); // bajo el borde inferior (560)
	assert.equal( copia.style.visibility, 'hidden' );
	moverLaCasilla( 350 );
	assert.equal( copia.style.visibility, '', 'la casilla vuelve a la vista y la copia con ella' );
	assert.equal( copia.style.top, '396px' );

	// Con el foco en su «Reintentar», ocultarla le quitaría el foco: se queda.
	pjax.enfocar( copia.querySelector( '[data-akb-reintentar]' ) );
	moverLaCasilla( 250 );
	assert.equal( copia.style.visibility, '', 'con el foco en ella se sigue viendo' );
	assert.equal( copia.style.top, '296px', 'junto a su casilla, aunque la lista la esconda (250 + 38 + 8)' );
} );

test( 'REV-D-S-1f-1: si la lista escondió la casilla durante la espera, la copia nace oculta y sale cuando la casilla vuelve', async () => {
	const pjax = arrancar();
	const { moverLaCasilla } = enUnaLista( pjax, 350 );
	const casilla = pjax.control( 'disponible' );
	casilla.focus();
	pjax.marcar( 'disponible', { teclado: true } );
	pjax.avanzar( 100 );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( 800 );
	moverLaCasilla( 230 ); // la rueda sobre la lista, con el aviso esperando
	pjax.avanzar( ESPERA );

	const copia = pjax.flotante();
	assert.ok( copia, 'la copia está, junto a su casilla y a un Tab' );
	assert.equal( copia.style.visibility, 'hidden', 'sin pintarse junto a un control que no se ve' );
	assert.equal( pjax.foco(), casilla );
	moverLaCasilla( 350 );
	assert.equal( copia.style.visibility, '' );
	assert.equal( copia.style.top, '396px' );
} );

/*
 * D-S-c1 (REV-D-S-1o-1): si durante la espera el cliente se fue al buscador y
 * escribió, la copia flotaba bajo el campo, justo donde se abre la lista de
 * sugerencias, y en la capa superior: tapaba la primera —la activa tras
 * ArrowDown, porque el foco no sale del campo (`aria-activedescendant`)— y se
 * quedaba con su toque.
 */
test( 'REV-D-S-1o-1: con la lista de sugerencias del buscador abierta la copia no se pinta, y se ve bajo el campo mientras está cerrada', async () => {
	for ( const navigationApi of [ true, false ] ) {
		const cual = `navigationApi=${ navigationApi }`;
		const pjax = arrancar( { navigationApi } );
		const campo = pjax.control( 'akb-search-input' );
		geometria( new Map( [ [ campo, { top: 8, left: 487, width: 408, height: 44 } ] ] ) );

		pjax.paginar();
		pjax.cancelaSafari();
		await tick();
		pjax.avanzar( 1_000 );
		// Al buscador y a escribir: la lista se abre con lo escrito.
		pjax.apoyar( campo );
		pjax.enfocar( campo );
		[ 'o', 'n', 'e' ].forEach( ( k ) => pjax.tecla( k ) );
		pjax.sugerencias( true );
		pjax.avanzar( ESPERA );

		const copia = pjax.flotante();
		assert.equal( campo.nextSibling, copia, `${ cual }: junto al campo, a un Tab` );
		assert.equal( copia.style.visibility, 'hidden', `${ cual }: con la lista abierta no se pinta: no tapa sus sugerencias` );
		pjax.tecla( 'ArrowDown' );
		pjax.tecla( 'p' );
		assert.equal( copia.style.visibility, 'hidden', `${ cual }: ni al recorrerla ni al seguir escribiendo` );

		pjax.sugerencias( false );
		pjax.avanzar( 16 );
		assert.equal( copia.style.visibility, '', `${ cual }: con la lista cerrada se ve` );
		assert.equal( copia.style.top, '60px', `${ cual }: bajo el campo (8 + 44 + 8)` );
		pjax.sugerencias( true );
		pjax.avanzar( 16 );
		assert.equal( copia.style.visibility, 'hidden', `${ cual }: y se aparta cuando la lista vuelve a abrirse` );

		pjax.tecla( 'Escape' );
		assert.equal( pjax.flotante(), null, cual );
		assert.deepEqual( mundo.observadores, [], `${ cual }: al retirarse deja de mirar la lista` );
	}
} );

test( 'REV-C-S-3o-2: «Reintentar» de la copia carga lo que el cliente pidió, como el de la barra', async () => {
	const { pjax, boton } = await conCopiaEnLaCasilla( { navigationApi: false } );

	const toque = pjax.clic( boton );
	assert.equal( toque.defaultPrevented, true );
	assert.deepEqual( pjax.asignadas, [ '/tienda/?_buscar=naruto&_disponibilidad=instock' ] );
} );

test( 'sin popover (Safari anterior a 17) la copia va al final de <body>, junto al foco igual', async () => {
	const pjax = arrancar();
	mundo.sinPopover = true;
	const casilla = pjax.control( 'disponible' );
	geometria( new Map( [ [ casilla.parentNode, { top: 400, left: 44, width: 280, height: 30 } ] ] ) );

	casilla.focus();
	pjax.marcar( 'disponible', { teclado: true } );
	pjax.avanzar( 100 );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( ESPERA );

	const copia = pjax.flotante();
	const body = casilla.closest( 'body' );
	assert.equal( copia.parentNode, body );
	assert.equal( body.children.at( -1 ), copia, 'al final: fija, sin depender de sus ancestros' );
	assert.equal( copia.hasAttribute( 'popover' ), false );
	assert.deepEqual( mundo.capaSuperior, [] );
	assert.equal( copia.style.top, '438px', 'debajo de la casilla igual' );
} );

test( 'REV-C-S-2o-1: el aviso entra en la barra como un contenedor de avisos de la tienda, donde estaba su molde', async () => {
	const pjax = arrancar();
	const barra = pjax.barra();
	const posicion = barra.children.indexOf( pjax.molde() );

	pjax.paginar();
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( ESPERA );

	const contenedor = pjax.aviso().nodo.parentNode;
	assert.equal( contenedor.getAttribute( 'class' ), 'woocommerce-notices-wrapper', 'el tema le da su línea y apila la cabecera (T7-1 D1)' );
	assert.equal( contenedor.parentNode, barra, 'hijo directo de la barra de resultados' );
	assert.equal( barra.children.indexOf( contenedor ), posicion, 'antes del total y de los controles: el orden de lectura y de foco' );
	assert.equal( pjax.molde(), null, 'el molde se consumió' );
} );

test( 'REV-C-S-1o-2: «Reintentar» carga lo que el cliente pidió, como sin JS', async () => {
	const pjax = arrancar( { navigationApi: false } );

	pjax.marcar( 'disponible' );
	pjax.avanzar( 100 );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( ESPERA );

	const toque = pjax.tocar( 'reintentar' );
	assert.equal( toque.defaultPrevented, true );
	assert.deepEqual( pjax.asignadas, [ '/tienda/?_buscar=naruto&_disponibilidad=instock' ], 'la casilla ya se desmarcó, pero lo pedido era el filtro' );
	assert.equal( pjax.enVuelo(), 0 );
} );

test( 'el orden vuelve a lo pintado: volver a elegirlo dispara el cambio y navega', async () => {
	const pjax = arrancar();

	pjax.ordenar( 'price' );
	assert.equal( pjax.enVuelo(), 1, 'el orden lanza un PJAX' );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( ESPERA );

	assert.equal( pjax.control( 'orden-defecto' ).selected, true, 'el desplegable dice el orden que se ve' );
	assert.equal( pjax.control( 'orden-precio' ).selected, false );

	pjax.ordenar( 'price' );
	assert.deepEqual( pjax.asignadas.map( ( u ) => new URL( u ).search ), [ '?orderby=price' ] );
} );

test( 'el chip de la búsqueda que no llega por la red no se lleva la búsqueda del siguiente filtro', async () => {
	const pjax = arrancar();

	pjax.tocar( 'chip-buscar' );
	assert.equal( pjax.enVuelo(), 1, 'el chip lanza un PJAX' );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( ESPERA );
	assert.equal( pjax.control( 'buscar' ).disabled, false, 'la búsqueda que se ve vuelve al formulario' );

	pjax.marcar( 'disponible' );
	pjax.avanzar( 100 );
	assert.deepEqual( pjax.asignadas, [ '/tienda/?_buscar=naruto&_disponibilidad=instock' ] );
} );

test( 'REV-C-S-1o-1: en el cajón móvil lo marcado sigue pendiente y «Ver N» lo aplica', async () => {
	for ( const navigationApi of [ true, false ] ) {
		const cual = `navigationApi=${ navigationApi }`;
		const pjax = arrancar( { navigationApi, movil: true } );

		pjax.tocar( 'abrir-filtros' );
		const casilla = pjax.marcar( 'disponible' );
		pjax.avanzar( 300 );
		assert.equal( pjax.enVuelo(), 0, `${ cual }: en el cajón marcar no recarga` );
		pjax.verN();
		assert.equal( pjax.enVuelo(), 1, `${ cual }: «Ver N» lanza el PJAX` );
		pjax.cancelaSafari();
		await tick();
		pjax.avanzar( ESPERA );

		assert.equal( casilla.checked, true, `${ cual }: lo elegido sigue marcado, pendiente` );
		assert.equal( pjax.aviso().visible, true, `${ cual }: con su aviso` );
		assert.deepEqual( pjax.asignadas, [], `${ cual }: sin navegar solo` );

		// El cliente vuelve a abrir el cajón y pulsa «Ver N productos».
		pjax.tocar( 'abrir-filtros' );
		pjax.verN();
		assert.deepEqual( pjax.asignadas, [ '/tienda/?_buscar=naruto&_disponibilidad=instock' ], `${ cual }: el botón aplica el filtro, ya sin PJAX` );
		assert.equal( pjax.enVuelo(), 0, cual );
	}
} );

test( 'tras un fallo de red no se precarga nada: el siguiente gesto navega sin PJAX y sería otra petición a una red caída', async () => {
	const pjax = arrancar();

	pjax.marcar( 'disponible' );
	pjax.avanzar( 100 );
	pjax.cancelaSafari();
	await tick();
	pjax.avanzar( ESPERA );

	// Con el ratón, apoyar el botón sobre la casilla precarga su URL.
	pjax.marcar( 'disponible', { raton: true } );
	assert.equal( pjax.enVuelo(), 0, 'ni el prefetch del ratón' );
	pjax.avanzar( 100 );
	assert.deepEqual( pjax.asignadas, [ '/tienda/?_buscar=naruto&_disponibilidad=instock' ] );
} );
