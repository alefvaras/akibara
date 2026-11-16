// @ts-check
/**
 * El buscador cuando la respuesta todavía no llega (lote S-S, seguimiento del
 * despliegue del 2026-09-26). Cada prueba nace de un hallazgo medido y falla
 * sin su arreglo, en Chromium (el proyecto) y en WebKit (abierto desde la
 * prueba, como Safari del iPhone y del Mac):
 *
 *  - PROD-COMP-1 (medido en producción): Escape pulsado durante la espera de
 *    220 ms o con /search/suggest en vuelo cerraba la lista, y a los ~300 ms
 *    «Buscando…» y la respuesta la volvían a abrir solos. En la hoja móvil
 *    hacía falta un tercer Escape para cerrar la hoja.
 *  - PS-S-1 (revisión previa al despliegue): con un PJAX en vuelo (filtro,
 *    chip, paginación), pulsar un enlace normal terminaba en Safari en la URL
 *    del PJAX, no en la pulsada. WebKit cancela los fetch al empezar a navegar
 *    con un TypeError y el catch del PJAX lo tomaba por un fallo de red.
 *  - REV-SS-1 (corrección 1 del lote): la misma familia que PROD-COMP-1 por el
 *    camino de «Populares»: /search/popular llegaba después del Escape y
 *    volvía a abrir «Recientes · Populares».
 *  - REV-SS-2 y REV-S-S-1f-1 (corrección 1): PS-S-1 por el Atrás (en WebKit el
 *    rechazo llega ANTES que beforeunload) y en Safari del iPhone, que no
 *    despacha beforeunload nunca y trae la Navigation API sólo desde iOS 26.2.
 *    El iPhone se modela en el WebKit de Playwright quitándole esas dos
 *    señales antes de que cargue la página.
 *  - REV-S-S-2o-1 / REV-S-S-2f-1 (corrección 2): en ese iPhone, tocar una
 *    sugerencia del buscador —o elegirla con Enter— con un PJAX en vuelo
 *    terminaba en la URL del PJAX si la ficha tardaba más de un segundo: la
 *    lista corta la burbuja del clic y el PJAX no se enteraba de la salida.
 *  - REV-S-S-2o-2 (corrección 2): los escenarios de enlace frenan el documento
 *    de destino por encima de la espera del respaldo (1 s). Con un destino
 *    rápido, `pagehide` llegaba a tiempo y tapaba la falta de la señal del
 *    clic: la prueba pasaba aunque se quitaran sus oyentes.
 *  - C-S (seguimiento, bloqueo de la verificación): en ese iPhone anterior a
 *    iOS 26.2, el Atrás con la página anterior a más de 1 s terminaba en la
 *    URL del PJAX 26 de 26 veces (el respaldo diferido 1 s le ganaba), contra
 *    2 de 26 con 0.1.75. Sin Navigation API ningún respaldo automático es
 *    seguro: ahora un fallo de red no navega solo y el siguiente gesto del
 *    catálogo va sin PJAX. Los escenarios de Atrás frenan la página anterior
 *    2 s, y una prueba mide el fallo de red de verdad con y sin la API.
 *  - REV-C-S-1f-1 (corrección 1 de C-S): con la API tampoco se ve a tiempo el
 *    Atrás a OTRO sitio (Google, Instagram): ni `navigate` ni `beforeunload`
 *    antes de que esa página se confirme, y el respaldo de 1 s terminaba en la
 *    URL del PJAX 10 de 10 veces. Ahora un fallo de red no navega solo en
 *    ningún navegador; los escenarios de Atrás incluyen la página anterior en
 *    otro origen, servida y frenada con `page.route`.
 *  - REV-C-S-1o-1 y 1o-2 (misma corrección): sin navegar solo, el fallo se
 *    dice (aviso con «Reintentar», región viva) y el gesto natural aplica lo
 *    pedido: en el cajón móvil «Ver N» —antes sólo cerraba el cajón—, y en
 *    escritorio volver a tocar la casilla, que vuelve desmarcada —antes la
 *    desmarcaba y recargaba la tienda sin filtro—.
 *  - C-S-c2 (corrección 2 de C-S): el aviso esperaba 1 s, y en la página que
 *    el cliente DEJABA salía, se anunciaba, revertía los controles y la subía
 *    cuando el destino tardaba más (REV-C-S-2o-3: enlace o sugerencia en un
 *    iPhone anterior a iOS 26.2; REV-C-S-2f-1: cualquier Atrás en ese iPhone
 *    y el Atrás a otro sitio con la API). Ahora espera 4 s con conexión, 1 s
 *    sin ella, y tras un clic que se lleva la página, hasta `pagehide` (tope
 *    8 s): los escenarios de salida miran la página que se va hasta su
 *    `pagehide`. Además, desde 1025 px el aviso quedaba al lado del H1
 *    (REV-C-S-2o-1) y, con el teclado, la página subía al aviso pero el foco
 *    se quedaba en el paginador, al pie (REV-C-S-2o-2).
 *  - D-S (revisión de C-S-c2): si el cliente seguía con el teclado durante la
 *    espera, la página subía al aviso y el foco quedaba ~4.000 px más abajo:
 *    el siguiente Tab la devolvía al pie (REV-C-S-3o-1). Y con Espacio en una
 *    casilla lejos de la barra, la casilla se desmarcaba sola con el aviso
 *    fuera de la pantalla (REV-C-S-3o-2 y 3f-1). Ahora ni el foco ni la
 *    página se mueven, y el aviso flota junto al foco, con «Reintentar» a una
 *    tecla.
 *  - D-S-c1 (corrección 1 de D-S): la copia flotante tapaba la primera
 *    sugerencia del buscador si el cliente se iba a buscar durante la espera
 *    (REV-D-S-1o-1), y no seguía a su casilla cuando se desplazaba la lista de
 *    la faceta: le quedaba encima, o junto a una casilla que la lista ya
 *    había escondido (REV-D-S-1o-2 y 1f-1).
 *
 * Para que «antes de que llegue la respuesta» no dependa de la suerte, la red
 * se frena con `page.route`: la respuesta es la real del sitio, sólo que llega
 * tarde. Nada escribe en la tienda: vale igual contra producción.
 */

import { existsSync } from 'node:fs';
import { expect, test, webkit } from '@playwright/test';

/** WebKit ya instalado en esta máquina (ver negocio-correcciones-qa-2). */
const WEBKIT = process.env.AKB_WEBKIT || `${ process.env.HOME }/Library/Caches/ms-playwright/webkit-2342/pw_run.sh`;

const pausa = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

/**
 * Corre `prueba` en la página del proyecto (Chromium) y en una de WebKit con el
 * mismo tamaño, táctil y user-agent.
 *
 * @param {import('@playwright/test').Page} page Página del proyecto.
 * @param {string} baseURL Base del sitio.
 * @param {(p: import('@playwright/test').Page, motor: string) => Promise<void>} prueba Lo que se mide.
 */
async function enLosDosMotores( page, baseURL, prueba ) {
	await prueba( page, 'chromium' );

	expect( existsSync( WEBKIT ), `WebKit en ${ WEBKIT } (o la ruta en AKB_WEBKIT)` ).toBe( true );
	const navegador = await webkit.launch( { executablePath: WEBKIT } );
	try {
		const { use } = test.info().project;
		const contexto = await navegador.newContext( {
			baseURL,
			viewport: use.viewport,
			hasTouch: !! use.hasTouch,
			isMobile: !! use.isMobile,
			userAgent: use.userAgent,
			storageState: use.storageState,
		} );
		await prueba( await contexto.newPage(), 'webkit' );
	} finally {
		await navegador.close();
	}
}

/**
 * Como enLosDosMotores(), pero da a `prueba` una fábrica de contextos NUEVOS
 * del motor: cada escenario necesita sus propios scripts de arranque.
 *
 * @param {import('@playwright/test').Browser} browser Chromium del proyecto.
 * @param {string} baseURL Base del sitio.
 * @param {(nuevo: () => Promise<import('@playwright/test').BrowserContext>, motor: string) => Promise<void>} prueba Lo que se mide.
 */
async function conContextosNuevos( browser, baseURL, prueba ) {
	const { use } = test.info().project;
	const opciones = {
		baseURL,
		viewport: use.viewport,
		hasTouch: !! use.hasTouch,
		isMobile: !! use.isMobile,
		userAgent: use.userAgent,
		storageState: use.storageState,
	};
	await prueba( () => browser.newContext( opciones ), 'chromium' );

	expect( existsSync( WEBKIT ), `WebKit en ${ WEBKIT } (o la ruta en AKB_WEBKIT)` ).toBe( true );
	const navegador = await webkit.launch( { executablePath: WEBKIT } );
	try {
		await prueba( () => navegador.newContext( opciones ), 'webkit' );
	} finally {
		await navegador.close();
	}
}

/** Estado del combobox que el cliente ve y el lector de pantalla oye. */
function combobox( p, campo ) {
	return p.evaluate( ( sel ) => {
		const i = /** @type {HTMLInputElement} */ ( document.querySelector( sel ) );
		const lista = document.getElementById( i.getAttribute( 'aria-controls' ) || '' );
		const cajon = document.getElementById( 'mobile-drawer' );
		return {
			expandido: i.getAttribute( 'aria-expanded' ),
			listaOculta: !! lista && lista.hidden,
			valor: i.value,
			foco: document.activeElement ? document.activeElement.id : '',
			lupaGirando: !! i.closest( 'form' )?.classList.contains( 'is-fetching' ),
			hojaAbierta: !! cajon && cajon.classList.contains( 'open' ),
		};
	}, campo );
}

/**
 * Lo que espera el aviso de un fallo de red del PJAX (ver esperaDelAviso() en
 * akb-search.js): con conexión y sin ella. Tras un clic que se lleva la
 * página, hasta `pagehide` con un tope de 8 s.
 */
const ESPERA_AVISO = 4_000;
const ESPERA_SIN_CONEXION = 1_000;

/**
 * Lo que tarda la página a la que se vuelve Atrás: más que la espera del
 * respaldo que había (1 s) y que la del aviso de entonces (1 s), y menos que la
 * espera con conexión de ahora (C-S-c2).
 */
const DESTINO_LENTO = 2_000;

/**
 * Lo que tarda la página de un enlace o una sugerencia: más que la espera con
 * conexión (4 s), para que sólo la señal del clic (que la alarga hasta
 * `pagehide`) pueda salvarla del aviso, y menos que su tope (8 s).
 */
const ENLACE_LENTO = 5_000;

/**
 * Una página de OTRO sitio (de donde llega el cliente: Google, Instagram), con
 * `route.fulfill` en un host que no existe. Se frena DESTINO_LENTO desde que
 * se arma, como frenarDestino(); `veces` prueba que el freno se aplicó.
 *
 * @param {import('@playwright/test').Page} p Página.
 * @return {Promise<{ url: string, armada: boolean, veces: number }>}
 */
async function servirOtroSitio( p ) {
	const otro = { url: 'http://origen-externo.test/', armada: false, veces: 0 };
	await p.route( ( u ) => 'origen-externo.test' === u.hostname, async ( route ) => {
		if ( otro.armada && route.request().isNavigationRequest() ) {
			otro.veces += 1;
			await pausa( DESTINO_LENTO );
		}
		await route.fulfill( { status: 200, contentType: 'text/html; charset=utf-8', body: '<!doctype html><html lang="es"><title>Otro sitio</title><p>Otro sitio</p></html>' } ).catch( () => {} );
	} );
	return otro;
}

/**
 * Frena la petición de DOCUMENTO a `frenada.url` (se arma justo antes del
 * gesto) hasta `frenada.ms`; el resto pasa sin tocar. `veces` prueba que el
 * freno se aplicó. La página se pide al servidor MIENTRAS tanto: llega a los
 * `ms` y no a los `ms` más lo que tarde el espejo, que con cuatro pruebas a la
 * vez puede ser otro segundo y cruzar la espera del aviso.
 *
 * @param {import('@playwright/test').Page} p Página.
 * @return {Promise<{ url: string|null, veces: number, ms: number }>}
 */
async function frenarDestino( p ) {
	const frenada = { url: null, veces: 0, ms: DESTINO_LENTO };
	await p.route( ( u ) => null !== frenada.url && u.href === frenada.url, async ( route ) => {
		if ( route.request().isNavigationRequest() ) {
			frenada.veces += 1;
			const [ respuesta ] = await Promise.all( [ route.fetch().catch( () => null ), pausa( frenada.ms ) ] );
			if ( respuesta ) {
				await route.fulfill( { response: respuesta } ).catch( () => {} ); // la canceló otra navegación.
				return;
			}
		}
		await route.continue().catch( () => {} );
	} );
	return frenada;
}

/**
 * Mira la página de la TIENDA que el cliente deja (REV-C-S-2o-3 y 2f-1): cuándo
 * aparece el aviso de conexión, cuándo lo lee la región viva y cuándo llega su
 * `pagehide`. Lo cuenta por una función expuesta al contexto, que sigue
 * escuchando aunque el cliente termine en otro sitio (sessionStorage es de cada
 * origen). Antes de cargar la página.
 *
 * @param {import('@playwright/test').BrowserContext} contexto Contexto nuevo.
 * @param {string} tienda Origen de la tienda.
 * @return {Promise<{ eventos: Array<{ ev: string, t: number }>, antesDeIrse: () => string[] }>}
 */
async function mirarLaQueSeVa( contexto, tienda ) {
	const eventos = [];
	await contexto.exposeBinding( 'akbLaQueSeVa', ( fuente, ev ) => {
		eventos.push( Object.assign( { t: Date.now() }, ev ) );
	} );
	await contexto.addInitScript( ( origen ) => {
		if ( location.origin !== origen ) {
			return;
		}
		const contar = ( ev ) => {
			try {
				window.akbLaQueSeVa( ev );
			} catch ( e ) {}
		};
		let visto = false;
		let leido = '';
		new MutationObserver( () => {
			if ( ! visto && [ ...document.querySelectorAll( '.akb-aviso-red' ) ].some( ( a ) => ! a.closest( 'template' ) ) ) {
				visto = true;
				contar( { ev: 'aviso', ruta: location.pathname } );
			}
			const l = document.getElementById( 'akb-live-region' );
			const texto = l ? l.textContent.trim() : '';
			if ( texto && texto !== leido && /conexi/i.test( texto ) ) {
				leido = texto;
				contar( { ev: 'anuncio', ruta: location.pathname } );
			}
		} ).observe( document, { subtree: true, childList: true, characterData: true } );
		addEventListener( 'pagehide', () => contar( { ev: 'pagehide', ruta: location.pathname } ) );
	}, tienda );
	return {
		eventos,
		/** Lo que la página de la tienda mostró o anunció antes de su `pagehide`. */
		antesDeIrse() {
			const ida = eventos.find( ( e ) => 'pagehide' === e.ev && '/tienda/' === e.ruta );
			return eventos.filter( ( e ) => 'pagehide' !== e.ev && ( ! ida || e.t <= ida.t ) ).map( ( e ) => `${ e.ev } en ${ e.ruta }` );
		},
	};
}

/**
 * Modela las señales de salida de un Safari antes de que cargue la página, y
 * deja una traza de cada `navigate` en sessionStorage: sobrevive a la
 * navegación y se lee en la página de destino. Las del mismo documento (el
 * pushState del PJAX) llevan delante «mismo-»: sólo una navegación a otro
 * documento, como la de location.assign(), queda como «push <ruta>».
 *
 * @param {import('@playwright/test').BrowserContext} contexto Contexto nuevo.
 * @param {{ beforeunload: boolean, navigationApi: boolean }} senales Qué despacha.
 */
async function modelarSafari( contexto, senales ) {
	await contexto.addInitScript( ( { beforeunload, navigationApi } ) => {
		const traza = ( linea ) => {
			try {
				const lineas = JSON.parse( sessionStorage.getItem( 'akb-traza' ) || '[]' );
				lineas.push( linea );
				sessionStorage.setItem( 'akb-traza', JSON.stringify( lineas ) );
			} catch ( e ) {}
		};
		const navegacion = window.navigation;
		if ( navegacion ) {
			navegacion.addEventListener( 'navigate', ( e ) => {
				const u = new URL( e.destination.url );
				traza( ( e.destination.sameDocument ? 'mismo-' : '' ) + e.navigationType + ' ' + u.pathname + u.search );
			} );
		}
		if ( ! navigationApi ) {
			Object.defineProperty( window, 'navigation', { get: () => undefined, configurable: true } );
		}
		if ( ! beforeunload ) {
			const poner = window.addEventListener;
			window.addEventListener = function ( tipo, fn, opciones ) {
				return 'beforeunload' === tipo ? undefined : poner.call( this, tipo, fn, opciones );
			};
		}
	}, senales );
}

/**
 * La traza de modelarSafari(), leída en la página donde terminó el cliente. Si
 * una navegación tardía destruye la página mientras se lee, se vuelve a leer
 * en la que quedó: esa navegación también está en la traza.
 */
async function leerTraza( p ) {
	for ( let intento = 0; ; intento++ ) {
		try {
			await p.waitForLoadState( 'domcontentloaded' );
			return await p.evaluate( () => JSON.parse( sessionStorage.getItem( 'akb-traza' ) || '[]' ) );
		} catch ( e ) {
			if ( intento >= 2 || ! /context was destroyed/i.test( String( e ) ) ) {
				throw e;
			}
		}
	}
}

test.describe( 'negocio · búsqueda: Escape y salida con la respuesta en camino', () => {
	test.describe.configure( { timeout: 240_000 } );

	test( 'PROD-COMP-1: Escape a los 0 y a los 300 ms deja la lista cerrada y, en la hoja móvil, el segundo Escape la cierra', async ( { page, isMobile, baseURL } ) => {
		await enLosDosMotores( page, baseURL || '', async ( p, motor ) => {
			const campo = isMobile ? '#akb-mobile-search-input' : '#akb-search-input';
			for ( const espera of [ 0, 300 ] ) {
				const cual = `${ motor }, ${ isMobile ? 'móvil' : 'escritorio' }, Escape a los ${ espera } ms`;
				const pedidas = [];
				// La respuesta real, 700 ms tarde: el Escape cae seguro antes.
				await p.route( '**/search/suggest**', async ( route ) => {
					pedidas.push( new URL( route.request().url() ).searchParams.get( 'q' ) );
					await pausa( 700 );
					await route.continue().catch( () => {} ); // la abortó la página.
				} );

				await p.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
				test.skip( null === await p.locator( campo ).getAttribute( 'data-akb-suggest' ), 'sin sugerencias activas no hay lista que cerrar' );
				await p.locator( `${ campo }[data-akb-suggest="1"]` ).waitFor( { state: 'attached' } );
				if ( isMobile ) {
					await p.locator( '#bottom-nav-search' ).tap();
				} else {
					await p.locator( campo ).click();
				}
				await expect( p.locator( campo ), cual ).toBeFocused();

				// Primera consulta: la lista abierta con resultados de verdad.
				await p.keyboard.type( 'doro', { delay: 30 } );
				await expect( p.locator( campo ) ).toHaveAttribute( 'aria-expanded', 'true', { timeout: 15_000 } );
				await expect( p.locator( `#${ await p.locator( campo ).getAttribute( 'aria-controls' ) } .akb-suggest-item` ).first() ).toBeVisible( { timeout: 15_000 } );

				// Una consulta NUEVA (fuera del memo) y Escape antes de su respuesta.
				pedidas.length = 0;
				await p.keyboard.type( 'hed', { delay: 10 } );
				if ( espera ) {
					await pausa( espera );
				}
				await p.keyboard.press( 'Escape' );

				// Exigido a los 2 s: la respuesta (700 ms) ya habría llegado.
				await pausa( 2_000 );
				const tras = await combobox( p, campo );
				expect( tras.expandido, `${ cual }: la lista no vuelve a abrirse sola` ).toBe( 'false' );
				expect( tras.listaOculta, cual ).toBe( true );
				expect( tras.valor, `${ cual }: el primer Escape no borra lo escrito` ).toBe( 'dorohed' );
				expect( tras.foco, cual ).toBe( campo.slice( 1 ) );
				expect( tras.lupaGirando, `${ cual }: la lupa no se queda girando` ).toBe( false );
				if ( isMobile ) {
					expect( tras.hojaAbierta, `${ cual }: el primer Escape es de la lista, la hoja sigue abierta` ).toBe( true );
				}
				if ( 0 === espera ) {
					expect( pedidas, `${ cual }: la espera del debounce murió con el Escape` ).toEqual( [] );
				}
				await test.info().attach( `${ motor }-${ isMobile ? 'movil' : 'escritorio' }-${ espera }ms`, { body: await p.screenshot(), contentType: 'image/png' } );

				if ( isMobile ) {
					// Con la lista cerrada, el siguiente Escape es de la hoja.
					await p.keyboard.press( 'Escape' );
					await expect( p.locator( '#mobile-drawer' ), `${ cual }: dos Escape cierran la hoja` ).not.toHaveClass( /\bopen\b/ );
					await expect( p.locator( '#bottom-nav-search' ) ).toBeFocused();
				}
				await p.unroute( '**/search/suggest**' );
			}
		} );
	} );

	test( 'PS-S-1: un enlace pulsado con un PJAX en vuelo gana, no la URL del PJAX', async ( { page, baseURL } ) => {
		await enLosDosMotores( page, baseURL || '', async ( p, motor ) => {
			// El PJAX de la página 2 tarda 3 s: está en vuelo cuando se pulsa el enlace.
			// Cuenta por ronda: el PJAX cancelado de la ronda anterior termina su
			// pausa más tarde y no puede sumar en la siguiente.
			let ronda = { salieron: 0, respondidos: 0 };
			await p.route( ( u ) => /\/tienda\/page\/2\/?$/.test( u.pathname ), async ( route ) => {
				const suya = 'akb-pjax' === route.request().headers()[ 'x-requested-with' ] ? ronda : null;
				if ( suya ) {
					suya.salieron += 1;
					await pausa( 3_000 );
				}
				await route.continue().catch( () => {} ); // la canceló la navegación.
				if ( suya ) {
					suya.respondidos += 1;
				}
			} );
			const frenada = await frenarDestino( p );
			for ( const tras of [ 50, 400 ] ) {
				const cual = `${ motor }, enlace a los ${ tras } ms del PJAX`;
				frenada.url = null;
				frenada.veces = 0;
				await p.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
				const pagina2 = p.locator( '.woocommerce-pagination a.page-numbers:not(.current):not(.next):not(.prev)' ).first();
				await pagina2.waitFor( { state: 'attached' } );
				const destino = await p.evaluate( () => {
					const a = [ ...document.querySelectorAll( 'footer a[href]' ) ].find( ( x ) => {
						const u = new URL( /** @type {HTMLAnchorElement} */ ( x ).href );
						return u.origin === location.origin && '/series/' === u.pathname;
					} );
					return a ? /** @type {HTMLAnchorElement} */ ( a ).href : null;
				} );
				test.skip( ! destino, 'el pie no enlaza a /series/' );

				ronda = { salieron: 0, respondidos: 0 };
				await pagina2.click();
				// Sin esto la prueba pasaría sola: si el clic del pager fuera una
				// navegación normal, el enlace del pie ganaría igual.
				await expect.poll( () => ronda.salieron, { message: `${ cual }: el pager lanza un PJAX`, timeout: 5_000 } ).toBe( 1 );
				await pausa( tras );
				expect( ronda, `${ cual }: el PJAX sigue en vuelo al pulsar el enlace` ).toEqual( { salieron: 1, respondidos: 0 } );
				// La página nueva tarda más que la espera del respaldo (REV-S-S-2o-2).
				frenada.url = destino;
				// El pie móvil pliega sus listas: el enlace se activa como lo haría
				// el dedo. Lo que se mide es la carrera entre la navegación y el
				// PJAX, no el toque.
				await p.evaluate( ( h ) => /** @type {HTMLAnchorElement} */ ( [ ...document.querySelectorAll( 'footer a[href]' ) ].find( ( x ) => /** @type {HTMLAnchorElement} */ ( x ).href === h ) ).click(), destino );

				await p.waitForURL( ( u ) => '/series/' === u.pathname, { timeout: 15_000 } ).catch( () => {} );
				// Si el PJAX la pisara, el assign llega justo después: se deja margen.
				await pausa( 1_500 );
				expect( frenada.veces, `${ cual }: la página nueva se frenó` ).toBeGreaterThanOrEqual( 1 );
				expect( new URL( p.url() ).pathname, `${ cual }: termina donde el cliente pulsó` ).toBe( '/series/' );
			}
		} );
	} );

	test( 'REV-SS-1: Escape antes de que lleguen los Populares deja la lista cerrada y, en la hoja móvil, el segundo Escape la cierra', async ( { page, isMobile, baseURL } ) => {
		await enLosDosMotores( page, baseURL || '', async ( p, motor ) => {
			const campo = isMobile ? '#akb-mobile-search-input' : '#akb-search-input';
			// Sin Escape primero: prueba que la respuesta frenada SÍ abriría la
			// lista, para que el «sigue cerrada» de después no pase en vacío.
			for ( const caso of [ 'control-sin-escape', 'con-historial', 'sin-historial' ] ) {
				const cual = `${ motor }, ${ isMobile ? 'móvil' : 'escritorio' }, ${ caso }`;
				const popular = { salieron: 0, respondidas: 0, alResponder: 0 };
				// La respuesta real de Populares, 1,5 s tarde: el Escape cae seguro antes.
				await p.route( '**/search/popular**', async ( route ) => {
					popular.salieron += 1;
					await pausa( 1_500 );
					await route.continue().catch( () => {} );
					popular.respondidas += 1;
					popular.alResponder = Date.now();
				} );

				await p.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
				test.skip( null === await p.locator( campo ).getAttribute( 'data-akb-suggest' ), 'sin sugerencias activas no hay lista que cerrar' );
				await p.locator( `${ campo }[data-akb-suggest="1"]` ).waitFor( { state: 'attached' } );
				// El historial se lee al pintar, así que basta con dejarlo antes del foco.
				await p.evaluate( ( conHistorial ) => {
					localStorage.setItem( 'akb_search_history_v3', JSON.stringify( {
						v: 3,
						items: conHistorial ? [ { type: 'query', label: 'naruto', query: 'naruto', ts: Date.now() } ] : [],
					} ) );
				}, 'sin-historial' !== caso );

				if ( isMobile ) {
					await p.locator( '#bottom-nav-search' ).tap();
				} else {
					await p.locator( campo ).click();
				}
				await expect( p.locator( campo ), cual ).toBeFocused();
				await expect.poll( () => popular.salieron, { message: `${ cual }: el foco pide los Populares` } ).toBe( 1 );
				const lista = p.locator( `#${ await p.locator( campo ).getAttribute( 'aria-controls' ) }` );
				if ( 'sin-historial' === caso ) {
					expect( ( await combobox( p, campo ) ).expandido, `${ cual }: sin historial no hay nada que mostrar todavía` ).toBe( 'false' );
				} else {
					await expect( p.locator( campo ), cual ).toHaveAttribute( 'aria-expanded', 'true' );
					await expect( lista, cual ).toContainText( 'Recientes' );
				}
				expect( popular.respondidas, `${ cual }: los Populares siguen en camino` ).toBe( 0 );

				if ( 'control-sin-escape' === caso ) {
					await expect( lista, `${ cual }: la respuesta tardía pinta los Populares` ).toContainText( 'Populares', { timeout: 10_000 } );
					await p.unroute( '**/search/popular**' );
					continue;
				}

				await p.keyboard.press( 'Escape' );
				const alEscape = Date.now();
				await expect.poll( () => popular.respondidas, { message: `${ cual }: los Populares llegan`, timeout: 10_000 } ).toBe( 1 );
				expect( popular.alResponder, `${ cual }: llegaron después del Escape` ).toBeGreaterThan( alEscape );
				// Margen para que la página procese la respuesta.
				await pausa( 1_000 );

				const tras = await combobox( p, campo );
				expect( tras.expandido, `${ cual }: la lista no vuelve a abrirse sola` ).toBe( 'false' );
				expect( tras.listaOculta, cual ).toBe( true );
				await test.info().attach( `rev-ss-1-${ motor }-${ isMobile ? 'movil' : 'escritorio' }-${ caso }`, { body: await p.screenshot(), contentType: 'image/png' } );
				if ( isMobile && 'con-historial' === caso ) {
					expect( tras.hojaAbierta, `${ cual }: el primer Escape es de la lista, la hoja sigue abierta` ).toBe( true );
					await p.keyboard.press( 'Escape' );
					await expect( p.locator( '#mobile-drawer' ), `${ cual }: dos Escape cierran la hoja` ).not.toHaveClass( /\bopen\b/ );
				}
				await p.unroute( '**/search/popular**' );
			}
		} );
	} );

	/*
	 * Cada escenario es una combinación de señales que un navegador real
	 * despacha o no. La prueba mira la URL final y, además, el hecho que la
	 * produce: un `navigate` de tipo push a la URL del PJAX, que es lo que
	 * dispara location.assign(). Con el arreglo no sale nunca; sin él, sale en
	 * WebKit (Chromium no cancela el fetch al empezar a navegar y hace de
	 * control).
	 *
	 * El destino se frena siempre por encima de la espera que tenía el aviso
	 * (1 s): en el Atrás, la página anterior DESTINO_LENTO (C-S); en el
	 * enlace, la nueva ENLACE_LENTO, por encima también de la espera con
	 * conexión (4 s), para que sólo el clic la salve. Con la página rápida,
	 * `pagehide` llegaba a tiempo y la prueba pasaba sin el arreglo. La página
	 * anterior es de la tienda (/series/) o de otro sitio (REV-C-S-1f-1): hacia
	 * otro origen no hay `navigate` ni `beforeunload` antes de que se confirme,
	 * tampoco en Safari con la API. Y la página que se deja no puede mostrar
	 * ni anunciar el aviso de conexión antes de su `pagehide` (REV-C-S-2o-3 y
	 * 2f-1).
	 */
	const SALIDAS = [
		{ gesto: 'atras', anterior: 'tienda', beforeunload: true, navigationApi: true, cual: 'Atrás en Safari del Mac (el rechazo llega antes que beforeunload)' },
		{ gesto: 'enlace', beforeunload: false, navigationApi: true, cual: 'enlace en un iPhone con iOS 26.2+ (sin beforeunload)' },
		{ gesto: 'atras', anterior: 'tienda', beforeunload: false, navigationApi: true, cual: 'Atrás en un iPhone con iOS 26.2+' },
		{ gesto: 'enlace', beforeunload: false, navigationApi: false, cual: 'enlace en un iPhone anterior a iOS 26.2 (sin beforeunload ni Navigation API)' },
		{ gesto: 'atras', anterior: 'tienda', beforeunload: false, navigationApi: false, cual: 'Atrás en un iPhone anterior a iOS 26.2' },
		{ gesto: 'atras', anterior: 'otro-sitio', beforeunload: true, navigationApi: true, cual: 'Atrás a otro sitio en Safari del Mac' },
		{ gesto: 'atras', anterior: 'otro-sitio', beforeunload: false, navigationApi: true, cual: 'Atrás a otro sitio en un iPhone con iOS 26.2+' },
		{ gesto: 'atras', anterior: 'otro-sitio', beforeunload: false, navigationApi: false, cual: 'Atrás a otro sitio en un iPhone anterior a iOS 26.2' },
	];

	test( 'REV-SS-2 / REV-S-S-1f-1 / REV-C-S-1f-1 / REV-C-S-2o-3 / 2f-1: el Atrás —también a otro sitio— y el iPhone tampoco terminan en la URL del PJAX ni avisan en la página que dejan', async ( { browser, baseURL } ) => {
		test.setTimeout( 600_000 );
		await conContextosNuevos( browser, baseURL || '', async ( nuevo, motor ) => {
			for ( const escenario of SALIDAS ) {
				const cual = `${ motor }, ${ escenario.cual }`;
				const contexto = await nuevo();
				await modelarSafari( contexto, escenario );
				const seVa = await mirarLaQueSeVa( contexto, new URL( baseURL || '' ).origin );
				const p = await contexto.newPage();
				// El PJAX de la página 2 tarda 3 s: está en vuelo al salir.
				const ronda = { salieron: 0 };
				await p.route( ( u ) => /\/tienda\/page\/2\/?$/.test( u.pathname ), async ( route ) => {
					if ( 'akb-pjax' === route.request().headers()[ 'x-requested-with' ] ) {
						ronda.salieron += 1;
						await pausa( 3_000 );
					}
					await route.continue().catch( () => {} ); // la canceló la navegación.
				} );
				const frenada = await frenarDestino( p );
				const otro = await servirOtroSitio( p );
				const aOtroSitio = 'otro-sitio' === escenario.anterior;

				if ( aOtroSitio ) {
					await p.goto( otro.url, { waitUntil: 'load' } );
				} else if ( 'atras' === escenario.gesto ) {
					await p.goto( '/series/', { waitUntil: 'load' } );
				}
				await p.goto( '/tienda/', { waitUntil: 'load' } );
				await p.evaluate( () => sessionStorage.removeItem( 'akb-traza' ) );
				const pagina2 = p.locator( '.woocommerce-pagination a.page-numbers:not(.current):not(.next):not(.prev)' ).first();
				const urlDelPjax = new URL( /** @type {string} */ ( await pagina2.getAttribute( 'href' ) ), p.url() ).pathname;
				await pagina2.click();
				await expect.poll( () => ronda.salieron, { message: `${ cual }: el pager lanza un PJAX`, timeout: 5_000 } ).toBe( 1 );
				await pausa( 50 );

				if ( 'atras' === escenario.gesto ) {
					// La página anterior tarda más que la espera del aviso: sin
					// señal previa, `pagehide` ya no alcanza (C-S).
					if ( aOtroSitio ) {
						otro.armada = true;
					} else {
						frenada.url = new URL( '/series/', p.url() ).href;
					}
					await p.goBack( { waitUntil: 'commit', timeout: 15_000 } ).catch( () => {} );
				} else {
					const destino = await p.evaluate( () => {
						const a = [ ...document.querySelectorAll( 'footer a[href]' ) ].find( ( x ) => '/series/' === new URL( /** @type {HTMLAnchorElement} */ ( x ).href ).pathname );
						return a ? /** @type {HTMLAnchorElement} */ ( a ).href : null;
					} );
					test.skip( ! destino, 'el pie no enlaza a /series/' );
					// La página nueva tarda más que la espera con conexión: sin la
					// señal del clic, `pagehide` ya no alcanza (REV-S-S-2o-2,
					// REV-C-S-2o-3).
					frenada.ms = ENLACE_LENTO;
					frenada.url = destino;
					await p.evaluate( ( h ) => /** @type {HTMLAnchorElement} */ ( [ ...document.querySelectorAll( 'footer a[href]' ) ].find( ( x ) => /** @type {HTMLAnchorElement} */ ( x ).href === h ) ).click(), destino );
				}
				// Dónde quiso ir el cliente: /series/ o, hacia atrás, el otro sitio.
				const llegada = ( u ) => ( aOtroSitio ? u.href : u.pathname );
				const quiso = aOtroSitio ? otro.url : '/series/';
				await p.waitForURL( ( u ) => llegada( u ) === quiso, { timeout: 15_000 } ).catch( () => {} );
				// Si algo fuera a navegar solo tras llegar, ya lo hizo.
				await pausa( 2_000 );
				// En WebKit, donde vive el defecto, el freno tiene que haberse
				// aplicado: si la página anterior volviera del bfcache sin pedirse,
				// el Atrás no probaría nada. Chromium, el control, puede sacarla de
				// su bfcache.
				if ( 'enlace' === escenario.gesto || 'webkit' === motor ) {
					expect( aOtroSitio ? otro.veces : frenada.veces, `${ cual }: la página ${ 'atras' === escenario.gesto ? 'anterior' : 'nueva' } se frenó` ).toBeGreaterThanOrEqual( 1 );
				}

				// Hacia otro sitio la traza de la tienda no se puede leer desde allá
				// (sessionStorage es de cada origen): ahí manda la URL final, que
				// sería la del PJAX si el assign le hubiera ganado.
				const traza = await leerTraza( p );
				expect( traza.filter( ( t ) => t === `push ${ urlDelPjax }` ), `${ cual }: ningún location.assign() a la URL del PJAX (traza: ${ traza.join( ' > ' ) })` ).toEqual( [] );
				expect( llegada( new URL( p.url() ) ), `${ cual }: termina donde el cliente quiso ir` ).toBe( quiso );
				expect( seVa.antesDeIrse(), `${ cual }: la tienda que deja no muestra ni anuncia «No pudimos cargar…»` ).toEqual( [] );
				await contexto.close();
			}
		} );
	} );

	/*
	 * REV-S-S-2o-1 / REV-S-S-2f-1: la lista de sugerencias corta la burbuja del
	 * clic (stopPropagation, para que el click-fuera no la cierre), así que un
	 * PJAX que escuchara el clic en `window` no se enteraba del toque, y Enter
	 * navegaba con `location.href`, sin clic. Se mide en los dos iPhone, con la
	 * ficha frenada por encima de la espera del aviso con conexión (4 s). Desde
	 * C-S-c1 un fallo de red no navega solo en ningún navegador, así que no
	 * queda respaldo que pueda pisar la sugerencia; con Navigation API (iOS
	 * 26.2+) `navigate` avisa la salida, y sin ella el clic alarga la espera
	 * hasta `pagehide` (REV-C-S-2o-3): en los dos, la página se va sin aviso de
	 * conexión. Sin el arreglo, WebKit termina en la URL del PJAX; Chromium, que
	 * no cancela el fetch al navegar, hace de control.
	 */
	const SUGERENCIAS = [
		{ como: 'reciente', cual: 'Reciente de producto tocado' },
		{ como: 'producto', cual: 'producto sugerido tocado' },
		{ como: 'serie', cual: 'serie sugerida tocada' },
		{ como: 'ver-todos', cual: '«Ver los N resultados» tocado' },
		{ como: 'teclado', cual: 'producto sugerido elegido con las flechas y Enter' },
	];
	const OPCION = {
		reciente: 'a.akb-suggest-item',
		producto: 'a.akb-suggest-item:not(.akb-suggest-serie):not(.akb-suggest-more)',
		serie: 'a.akb-suggest-serie',
		'ver-todos': 'a.akb-suggest-more',
	};

	const IPHONES = [
		{ nombre: 'un iPhone anterior a iOS 26.2', corto: 'ios-viejo', senales: { beforeunload: false, navigationApi: false } },
		{ nombre: 'un iPhone con iOS 26.2+', corto: 'ios-nuevo', senales: { beforeunload: false, navigationApi: true } },
	];

	for ( const iphone of IPHONES ) test( `REV-S-S-2o-1 / REV-S-S-2f-1: una sugerencia tocada o elegida con Enter con un PJAX en vuelo gana en ${ iphone.nombre }`, async ( { browser, baseURL, isMobile } ) => {
		test.setTimeout( 360_000 );
		const campo = isMobile ? '#akb-mobile-search-input' : '#akb-search-input';
		await conContextosNuevos( browser, baseURL || '', async ( nuevo, motor ) => {
			for ( const escenario of SUGERENCIAS ) {
				const cual = `${ motor }, ${ isMobile ? 'móvil' : 'escritorio' }, ${ iphone.nombre }, ${ escenario.cual }`;
				const contexto = await nuevo();
				await modelarSafari( contexto, iphone.senales );
				const seVa = await mirarLaQueSeVa( contexto, new URL( baseURL || '' ).origin );
				const p = await contexto.newPage();
				// El PJAX de la página 2 tarda 20 s: sigue en vuelo mientras el
				// cliente abre el buscador, escribe y elige, aunque el espejo
				// esté cargado (con 8 s, a veces llegaba antes de elegir).
				const ronda = { salieron: 0, respondidos: 0 };
				await p.route( ( u ) => /\/tienda\/page\/2\/?$/.test( u.pathname ), async ( route ) => {
					const suyo = 'akb-pjax' === route.request().headers()[ 'x-requested-with' ];
					if ( suyo ) {
						ronda.salieron += 1;
						await pausa( 20_000 );
					}
					await route.continue().catch( () => {} ); // la canceló la navegación.
					if ( suyo ) {
						ronda.respondidos += 1;
					}
				} );
				const frenada = await frenarDestino( p );

				await p.goto( '/tienda/', { waitUntil: 'load' } );
				test.skip( null === await p.locator( campo ).getAttribute( 'data-akb-suggest' ), 'sin sugerencias activas no hay lista' );
				if ( 'reciente' === escenario.como ) {
					// Un Reciente de producto, como lo deja haber elegido una ficha.
					const ficha = await p.locator( 'ul.products a.woocommerce-LoopProduct-link' ).first().getAttribute( 'href' );
					await p.evaluate( ( url ) => localStorage.setItem( 'akb_search_history_v3', JSON.stringify( {
						v: 3,
						items: [ { type: 'product', label: 'Ficha de prueba', query: 'ficha', url, ts: Date.now() } ],
					} ) ), /** @type {string} */ ( ficha ) );
				}
				await p.evaluate( () => sessionStorage.removeItem( 'akb-traza' ) );
				const pagina2 = p.locator( '.woocommerce-pagination a.page-numbers:not(.current):not(.next):not(.prev)' ).first();
				const urlDelPjax = new URL( /** @type {string} */ ( await pagina2.getAttribute( 'href' ) ), p.url() ).pathname;
				await pagina2.click();
				await expect.poll( () => ronda.salieron, { message: `${ cual }: el pager lanza un PJAX`, timeout: 5_000 } ).toBe( 1 );

				if ( isMobile ) {
					await p.locator( '#bottom-nav-search' ).tap();
				} else {
					await p.locator( campo ).click();
				}
				await expect( p.locator( campo ), cual ).toBeFocused();
				if ( 'reciente' !== escenario.como ) {
					await p.keyboard.type( 'dorohedoro', { delay: 20 } );
				}
				const lista = p.locator( `#${ await p.locator( campo ).getAttribute( 'aria-controls' ) }` );
				let opcion;
				if ( 'teclado' === escenario.como ) {
					await expect( lista.locator( OPCION.producto ).first(), cual ).toBeVisible( { timeout: 15_000 } );
					// Con las flechas hasta el primer producto (la serie va antes).
					const hasta = await lista.locator( 'a.akb-suggest-item' ).evaluateAll( ( xs ) => xs.findIndex( ( x ) => ! x.classList.contains( 'akb-suggest-serie' ) ) );
					for ( let i = 0; i <= hasta; i++ ) {
						await p.keyboard.press( 'ArrowDown' );
					}
					opcion = p.locator( `#${ await p.locator( campo ).getAttribute( 'aria-activedescendant' ) }` );
				} else {
					opcion = lista.locator( OPCION[ escenario.como ] ).first();
					await expect( opcion, cual ).toBeVisible( { timeout: 15_000 } );
				}
				const destino = new URL( /** @type {string} */ ( await opcion.getAttribute( 'href' ) ), p.url() );
				// Sin esto la prueba pasaría sola: el PJAX tiene que seguir en vuelo.
				expect( ronda, `${ cual }: el PJAX sigue en vuelo al elegir` ).toEqual( { salieron: 1, respondidos: 0 } );
				await test.info().attach( `rev-s-s-2o-1-${ iphone.corto }-${ motor }-${ isMobile ? 'movil' : 'escritorio' }-${ escenario.como }-antes`, { body: await p.screenshot(), contentType: 'image/png' } );

				frenada.ms = ENLACE_LENTO;
				frenada.url = destino.href;
				if ( 'teclado' === escenario.como ) {
					await p.keyboard.press( 'Enter' );
				} else if ( isMobile ) {
					await opcion.tap();
				} else {
					await opcion.click();
				}

				const ruta = destino.pathname + destino.search;
				await p.waitForURL( ( u ) => u.pathname + u.search === ruta, { timeout: 20_000 } ).catch( () => {} );
				// Si algo fuera a navegar solo tras llegar, ya lo hizo.
				await pausa( 2_500 );

				const traza = await leerTraza( p );
				// Chromium es el control y no se puede frenar: al apoyar el dedo o
				// el ratón precarga la ficha por las speculation rules de WordPress
				// («conservative»), y esa precarga no pasa por page.route. En
				// WebKit, que no las tiene, la ficha llega tarde de verdad.
				if ( 'webkit' === motor ) {
					expect( frenada.veces, `${ cual }: la ficha se frenó por encima del respaldo` ).toBeGreaterThanOrEqual( 1 );
				}
				expect( traza.filter( ( t ) => t === `push ${ urlDelPjax }` ), `${ cual }: ningún location.assign() a la URL del PJAX (traza: ${ traza.join( ' > ' ) })` ).toEqual( [] );
				const final = new URL( p.url() );
				expect( final.pathname + final.search, `${ cual }: termina donde el cliente eligió` ).toBe( ruta );
				expect( seVa.antesDeIrse(), `${ cual }: la tienda que deja no muestra ni anuncia «No pudimos cargar…»` ).toEqual( [] );
				await test.info().attach( `rev-s-s-2o-1-${ iphone.corto }-${ motor }-${ isMobile ? 'movil' : 'escritorio' }-${ escenario.como }-despues`, { body: await p.screenshot(), contentType: 'image/png' } );
				await contexto.close();
			}
		} );
	} );

	/*
	 * C-S y su corrección C-S-c1: el precio de que el Atrás gane siempre. Un
	 * fallo de red de verdad del PJAX (la red se cae, no un cliente que se va) no
	 * se distingue a tiempo de un Atrás, con o sin Navigation API
	 * (REV-C-S-1f-1), así que no navega solo: la página queda como estaba, sin
	 * atenuado, con el aviso de conexión (y la región viva lo lee), y el
	 * cliente sigue con «Reintentar» o con el SIGUIENTE toque, que va sin PJAX.
	 * Se corta el PJAX con `route.abort('failed')`, que en los dos motores
	 * rechaza con el mismo TypeError que un corte.
	 */
	const FALLOS = [
		{ nombre: 'con Navigation API', corto: 'con-api', senales: { beforeunload: true, navigationApi: true } },
		{ nombre: 'en un iPhone anterior a iOS 26.2', corto: 'ios-viejo', senales: { beforeunload: false, navigationApi: false } },
	];

	/** Cómo sigue el cliente tras el aviso. */
	const SIGUE = [
		{ como: 'otra-vez', cual: 'vuelve a tocar lo mismo' },
		{ como: 'reintentar', cual: 'pulsa «Reintentar»' },
	];

	/**
	 * Corta con `route.abort('failed')` cada PJAX a las rutas que casan con
	 * `ruta`; los documentos llegan. Devuelve la cuenta de PJAX (y precargas,
	 * que llevan la misma cabecera) y una sonda de «sigue siendo el mismo
	 * documento»: una marca que sólo vive en esta página. No se cuentan las
	 * peticiones de documento porque Chromium precarga el enlace al apoyar el
	 * dedo (speculation rules de WordPress) y esa precarga no pasa por
	 * page.route.
	 *
	 * @param {import('@playwright/test').Page} p Página.
	 * @param {RegExp} ruta Rutas cuyo PJAX se corta.
	 */
	async function cortarElPjax( p, ruta ) {
		const cuenta = { pjax: 0 };
		await p.route( ( u ) => ruta.test( u.pathname ), async ( route ) => {
			if ( 'akb-pjax' === route.request().headers()[ 'x-requested-with' ] ) {
				cuenta.pjax += 1;
				await route.abort( 'failed' );
				return;
			}
			await route.continue().catch( () => {} );
		} );
		return {
			cuenta,
			marcar: () => p.evaluate( () => {
				sessionStorage.removeItem( 'akb-traza' );
				window.akbMarcaCS = 1;
			} ),
			mismoDocumento: () => p.evaluate( () => 1 === window.akbMarcaCS ).catch( () => false ),
		};
	}

	/**
	 * Lo que el cliente ve y oye tras el fallo: el aviso, su texto en la región
	 * viva, sin atenuado, en el mismo documento y sin location.assign().
	 */
	async function trasElFallo( p, corte, cual ) {
		// Lo pone el bundle desde su <template>: antes del fallo no está en la página.
		const aviso = p.locator( '.akb-aviso-red' );
		await expect( aviso, `${ cual }: el aviso dice qué pasó` ).toBeVisible( { timeout: 5_000 } );
		await expect( aviso.locator( '[data-akb-reintentar]' ), `${ cual }: con su botón` ).toBeVisible();
		const texto = ( await aviso.innerText() ).replace( /\s+/g, ' ' ).trim();
		await expect.poll( () => p.evaluate( () => ( document.getElementById( 'akb-live-region' )?.textContent || '' ).replace( /\s+/g, ' ' ).trim() ), { message: `${ cual }: la región viva lo lee` } ).toBe( texto );
		expect( texto, cual ).toMatch( /conexión/ );
		expect( await corte.mismoDocumento(), `${ cual }: ni recarga` ).toBe( true );
		expect( await leerTraza( p ), `${ cual }: ningún location.assign()` ).toEqual( [] );
		expect( await p.evaluate( () => document.body.classList.contains( 'akb-pjax-loading' ) ), `${ cual }: sin atenuado` ).toBe( false );
		await expect( p.locator( 'ul.products' ).first(), `${ cual }: la rejilla sigue a la vista` ).toBeVisible();
	}

	/** Espera la página nueva, de verdad: documento nuevo y sin otro PJAX. */
	async function llegaComoSinJs( p, corte, antes, cual, esperada ) {
		await p.waitForURL( esperada, { waitUntil: 'commit', timeout: 15_000 } );
		await p.waitForLoadState( 'domcontentloaded' );
		expect( corte.cuenta.pjax, `${ cual }: sin otro PJAX` ).toBe( antes );
		expect( await corte.mismoDocumento(), `${ cual }: documento nuevo` ).toBe( false );
		await expect( p.locator( 'ul.products' ).first(), cual ).toBeVisible();
	}

	test( 'C-S / REV-C-S-1f-1: un fallo de red de verdad con un PJAX en vuelo deja la tienda usable y lo avisa, con y sin Navigation API', async ( { browser, baseURL, isMobile } ) => {
		test.setTimeout( 360_000 );
		await conContextosNuevos( browser, baseURL || '', async ( nuevo, motor ) => {
			for ( const modelo of FALLOS ) {
				for ( const sigue of SIGUE ) {
					const cual = `${ motor }, ${ isMobile ? 'móvil' : 'escritorio' }, ${ modelo.nombre }, ${ sigue.cual }`;
					const contexto = await nuevo();
					await modelarSafari( contexto, modelo.senales );
					const p = await contexto.newPage();
					const corte = await cortarElPjax( p, /\/tienda\/page\/2\/?$/ );

					await p.goto( '/tienda/', { waitUntil: 'load' } );
					await corte.marcar();
					const pagina2 = p.locator( '.woocommerce-pagination a.page-numbers:not(.current):not(.next):not(.prev)' ).first();
					const urlDelPjax = new URL( /** @type {string} */ ( await pagina2.getAttribute( 'href' ) ), p.url() ).pathname;
					await pagina2.click();
					await expect.poll( () => corte.cuenta.pjax, { message: `${ cual }: el pager lanza un PJAX`, timeout: 5_000 } ).toBe( 1 );

					// Más que la espera del aviso con conexión (4 s): si fuera a
					// navegar solo, ya lo hizo.
					await pausa( ESPERA_AVISO + 500 );
					expect( new URL( p.url() ).pathname, `${ cual }: el fallo no se lleva al cliente a ninguna parte` ).toBe( '/tienda/' );
					await trasElFallo( p, corte, cual );
					await test.info().attach( `c-s-fallo-${ modelo.corto }-${ motor }-${ isMobile ? 'movil' : 'escritorio' }-${ sigue.como }-tras-el-fallo`, { body: await p.screenshot(), contentType: 'image/png' } );

					if ( 'reintentar' === sigue.como ) {
						await p.locator( '[data-akb-reintentar]' ).click();
					} else {
						await pagina2.click();
					}
					await llegaComoSinJs( p, corte, 1, cual, ( u ) => u.pathname === urlDelPjax );
					await test.info().attach( `c-s-fallo-${ modelo.corto }-${ motor }-${ isMobile ? 'movil' : 'escritorio' }-${ sigue.como }-despues`, { body: await p.screenshot(), contentType: 'image/png' } );
					await contexto.close();
				}
			}
		} );
	} );

	/*
	 * REV-C-S-1o-1: en el cajón móvil, un filtro marcado cuyo «Ver N» no llega
	 * por la red quedaba marcado, y al volver a pulsar «Ver N» el cajón sólo se
	 * cerraba: el filtro no se podía aplicar. Ahora sigue pendiente y «Ver N»
	 * (o «Reintentar») lo aplica sin PJAX. Se mide en los dos iPhone.
	 */
	const IPHONES_FILTRO = [
		{ nombre: 'un iPhone anterior a iOS 26.2', corto: 'ios-viejo', senales: { beforeunload: false, navigationApi: false } },
		{ nombre: 'un iPhone con iOS 26.2+', corto: 'ios-nuevo', senales: { beforeunload: false, navigationApi: true } },
	];
	const CASILLA = '.akb-facets input[name="_disponibilidad[]"][value="instock"]';
	const ETIQUETA = '.akb-facets label:has(input[name="_disponibilidad[]"][value="instock"])';
	const FILTRADA = ( u ) => '/tienda/' === u.pathname && 'instock' === u.searchParams.get( '_disponibilidad' );

	test( 'REV-C-S-1o-1: en el cajón móvil, un filtro que no llega por la red se aplica con «Ver N» o con «Reintentar»', async ( { browser, baseURL, isMobile } ) => {
		test.skip( ! isMobile, 'el cajón con «Ver N» es del teléfono' );
		test.setTimeout( 360_000 );
		await conContextosNuevos( browser, baseURL || '', async ( nuevo, motor ) => {
			for ( const iphone of IPHONES_FILTRO ) {
				for ( const sigue of [ { como: 'ver-n', cual: 'vuelve a pulsar «Ver N»' }, SIGUE[ 1 ] ] ) {
					const cual = `${ motor }, ${ iphone.nombre }, ${ sigue.cual }`;
					const contexto = await nuevo();
					await modelarSafari( contexto, iphone.senales );
					const p = await contexto.newPage();
					const corte = await cortarElPjax( p, /^\/tienda\/?$/ );

					await p.goto( '/tienda/', { waitUntil: 'load' } );
					await corte.marcar();
					const abrir = p.locator( '[data-akb-drawer-open]' ).first();
					const boton = p.locator( '.akb-facets-apply' );
					await abrir.tap();
					await p.locator( ETIQUETA ).tap();
					// El conteo del cajón no pasa por el PJAX: «Ver N» ya dice el filtrado.
					await expect( boton, cual ).not.toHaveClass( /is-loading/, { timeout: 20_000 } );
					await boton.tap();
					await expect.poll( () => corte.cuenta.pjax, { message: `${ cual }: «Ver N» lanza el PJAX`, timeout: 5_000 } ).toBeGreaterThanOrEqual( 1 );

					await pausa( ESPERA_AVISO + 500 );
					expect( new URL( p.url() ).search, `${ cual }: sin filtro todavía` ).toBe( '' );
					await trasElFallo( p, corte, cual );
					await expect( p.locator( '[data-akb-chips] .akb-chip[data-facet="disponibilidad"]' ), `${ cual }: sin chip de un filtro no aplicado` ).toHaveCount( 0 );
					const pjaxHastaAhora = corte.cuenta.pjax;
					await test.info().attach( `rev-c-s-1o-1-${ iphone.corto }-${ motor }-${ sigue.como }-tras-el-fallo`, { body: await p.screenshot(), contentType: 'image/png' } );

					if ( 'ver-n' === sigue.como ) {
						await abrir.tap();
						await expect( p.locator( CASILLA ), `${ cual }: lo elegido sigue marcado, pendiente` ).toBeChecked();
						await boton.tap();
					} else {
						await p.locator( '[data-akb-reintentar]' ).tap();
					}
					await llegaComoSinJs( p, corte, pjaxHastaAhora, cual, FILTRADA );
					await expect( p.locator( '[data-akb-chips] .akb-chip[data-facet="disponibilidad"]' ), `${ cual }: el filtro quedó aplicado` ).toHaveCount( 1 );
					await test.info().attach( `rev-c-s-1o-1-${ iphone.corto }-${ motor }-${ sigue.como }-despues`, { body: await p.screenshot(), contentType: 'image/png' } );
					await contexto.close();
				}
			}
		} );
	} );

	/*
	 * REV-C-S-1o-2: en escritorio, la casilla cuyo PJAX no llega por la red
	 * quedaba marcada sin estar aplicada, y el reintento natural —volver a
	 * pulsarla— la desmarcaba y recargaba la tienda sin filtro. Ahora vuelve a
	 * lo pintado, el aviso lo dice, y volver a pulsarla (o «Reintentar») aplica
	 * el filtro. Se mide en Safari del Mac con y sin Navigation API.
	 */
	const MACS = [
		{ nombre: 'Safari del Mac anterior a 26.2', corto: 'mac-viejo', senales: { beforeunload: true, navigationApi: false } },
		{ nombre: 'Safari del Mac 26.2+', corto: 'mac-nuevo', senales: { beforeunload: true, navigationApi: true } },
	];

	test( 'REV-C-S-1o-2: en escritorio, la casilla que no llega por la red no afirma el filtro y volver a pulsarla lo aplica', async ( { browser, baseURL, isMobile } ) => {
		test.skip( isMobile, 'en el teléfono las casillas van en el cajón (REV-C-S-1o-1)' );
		test.setTimeout( 360_000 );
		await conContextosNuevos( browser, baseURL || '', async ( nuevo, motor ) => {
			for ( const mac of MACS ) {
				for ( const sigue of [ { como: 'misma-casilla', cual: 'vuelve a pulsar la casilla' }, SIGUE[ 1 ] ] ) {
					const cual = `${ motor }, ${ mac.nombre }, ${ sigue.cual }`;
					const contexto = await nuevo();
					await modelarSafari( contexto, mac.senales );
					const p = await contexto.newPage();
					const corte = await cortarElPjax( p, /^\/tienda\/?$/ );

					await p.goto( '/tienda/', { waitUntil: 'load' } );
					await corte.marcar();
					await p.locator( ETIQUETA ).click();
					await expect.poll( () => corte.cuenta.pjax, { message: `${ cual }: la casilla lanza un PJAX`, timeout: 5_000 } ).toBeGreaterThanOrEqual( 1 );

					await pausa( ESPERA_AVISO + 500 );
					expect( new URL( p.url() ).search, `${ cual }: sin filtro todavía` ).toBe( '' );
					await trasElFallo( p, corte, cual );
					await expect( p.locator( CASILLA ), `${ cual }: la casilla no afirma un filtro que no está aplicado` ).not.toBeChecked();
					await expect( p.locator( '[data-akb-chips] .akb-chip[data-facet="disponibilidad"]' ), cual ).toHaveCount( 0 );
					const pjaxHastaAhora = corte.cuenta.pjax;
					await test.info().attach( `rev-c-s-1o-2-${ mac.corto }-${ motor }-${ sigue.como }-tras-el-fallo`, { body: await p.screenshot(), contentType: 'image/png' } );

					if ( 'misma-casilla' === sigue.como ) {
						await p.locator( ETIQUETA ).click();
					} else {
						await p.locator( '[data-akb-reintentar]' ).click();
					}
					await llegaComoSinJs( p, corte, pjaxHastaAhora, cual, FILTRADA );
					await expect( p.locator( CASILLA ), `${ cual }: el filtro quedó aplicado` ).toBeChecked();
					await expect( p.locator( '[data-akb-chips] .akb-chip[data-facet="disponibilidad"]' ), cual ).toHaveCount( 1 );
					await test.info().attach( `rev-c-s-1o-2-${ mac.corto }-${ motor }-${ sigue.como }-despues`, { body: await p.screenshot(), contentType: 'image/png' } );
					await contexto.close();
				}
			}
		} );
	} );

	/*
	 * REV-C-S-2o-1: desde 1025 px la cabecera del catálogo va en una fila (el
	 * H1 a la izquierda, la barra de orden a la derecha) y el tema la vuelve a
	 * apilar cuando la barra trae un aviso (T7-1 D1). El de conexión quedaba en
	 * la columna derecha, al lado del H1, y el título bajaba 27 px (1440, 1280 y
	 * 1100, en los dos motores). Ahora va en un contenedor de avisos de la
	 * tienda: a todo el ancho de la columna, bajo el H1, que no se mueve. En el
	 * teléfono, con el canalón del total y encima de él.
	 */
	test( 'REV-C-S-2o-1: el aviso de conexión va a todo el ancho bajo el título, y el título no se mueve', async ( { page, baseURL, isMobile } ) => {
		test.setTimeout( 240_000 );
		await enLosDosMotores( page, baseURL || '', async ( p, motor ) => {
			const corte = await cortarElPjax( p, /^\/tienda\/?$/ );
			for ( const ancho of isMobile ? [ 0 ] : [ 1440, 1280, 1100 ] ) {
				const cual = `${ motor }, ${ isMobile ? 'móvil' : `${ ancho } px` }`;
				if ( ancho ) {
					await p.setViewportSize( { width: ancho, height: 900 } );
				}
				await p.goto( '/tienda/', { waitUntil: 'load' } );
				const medir = () => p.evaluate( () => {
					const caja = ( el ) => {
						const b = el.getBoundingClientRect();
						return { x: Math.round( b.x ), y: Math.round( b.y + scrollY ), w: Math.round( b.width ), h: Math.round( b.height ) };
					};
					const aviso = [ ...document.querySelectorAll( '.akb-aviso-red' ) ].find( ( a ) => ! a.closest( 'template' ) );
					return {
						h1: caja( /** @type {Element} */ ( document.querySelector( '.woocommerce-products-header .page-title' ) ) ),
						rejilla: caja( /** @type {Element} */ ( document.querySelector( 'ul.products' ) ) ),
						total: caja( /** @type {Element} */ ( document.querySelector( '.akb-result-count' ) ) ),
						aviso: aviso ? caja( aviso ) : null,
					};
				} );
				const antes = await medir();
				const pjaxAntes = corte.cuenta.pjax;
				if ( isMobile ) {
					await p.locator( '[data-akb-drawer-open]' ).first().tap();
					await p.locator( ETIQUETA ).tap();
					await expect( p.locator( '.akb-facets-apply' ), cual ).not.toHaveClass( /is-loading/, { timeout: 20_000 } );
					await p.locator( '.akb-facets-apply' ).tap();
				} else {
					await p.locator( ETIQUETA ).click();
				}
				await expect.poll( () => corte.cuenta.pjax, { message: `${ cual }: el filtro lanza un PJAX`, timeout: 5_000 } ).toBeGreaterThan( pjaxAntes );
				await expect( p.locator( '.akb-aviso-red' ), cual ).toBeVisible( { timeout: ESPERA_AVISO + 5_000 } );
				// Se mide la disposición, arriba del todo como antes del filtro: con
				// el puntero la página sube hasta el aviso, y al desplazarse la
				// cabecera fija se pliega y todo sube en el documento.
				await pausa( 1_000 );
				await p.evaluate( () => window.scrollTo( { top: 0, behavior: 'instant' } ) );
				await pausa( 300 );
				const despues = await medir();
				await test.info().attach( `rev-c-s-2o-1-${ motor }-${ ancho || 'movil' }`, { body: await p.screenshot(), contentType: 'image/png' } );

				const aviso = /** @type {{ x: number, y: number, w: number, h: number }} */ ( despues.aviso );
				expect( Math.abs( despues.h1.y - antes.h1.y ), `${ cual }: el título no se mueve (antes ${ antes.h1.y }, después ${ despues.h1.y })` ).toBeLessThanOrEqual( 1 );
				expect( aviso.y, `${ cual }: el aviso va bajo el título` ).toBeGreaterThanOrEqual( despues.h1.y + despues.h1.h );
				expect( aviso.y + aviso.h, `${ cual }: y encima del total` ).toBeLessThanOrEqual( despues.total.y );
				// A lo ancho: el de la columna en escritorio (la rejilla), el del
				// total con su canalón en el teléfono (la rejilla va a sangre).
				const ancla = isMobile ? despues.total : despues.rejilla;
				expect( Math.abs( aviso.x - ancla.x ), `${ cual }: empieza donde la columna (aviso ${ aviso.x }, columna ${ ancla.x })` ).toBeLessThanOrEqual( 1 );
				expect( Math.abs( aviso.w - ancla.w ), `${ cual }: y ocupa su ancho (aviso ${ aviso.w }, columna ${ ancla.w })` ).toBeLessThanOrEqual( 1 );
			}
		} );
	} );

	/*
	 * REV-C-S-2o-2: con el teclado, si la paginación no llegaba por la red la
	 * página subía hasta el aviso pero el foco se quedaba en el enlace del
	 * paginador, ~4.000 px más abajo: el siguiente Tab devolvía la página al
	 * pie (medido en Chromium: scrollY 3.399). Ahora el foco va con la vista, a
	 * «Reintentar». En WebKit headless Tab no enfoca enlaces: ahí se mide el
	 * foco y la vista.
	 */
	test( 'REV-C-S-2o-2: con el teclado, la página que no llega por la red lleva el foco a «Reintentar», a la vista', async ( { page, baseURL, isMobile } ) => {
		test.skip( isMobile, 'el paginador con el teclado es de escritorio' );
		await enLosDosMotores( page, baseURL || '', async ( p, motor ) => {
			const corte = await cortarElPjax( p, /\/tienda\/page\/2\/?$/ );
			await p.goto( '/tienda/', { waitUntil: 'load' } );
			const pagina2 = p.locator( '.woocommerce-pagination a.page-numbers:not(.current):not(.next):not(.prev)' ).first();
			await pagina2.focus();
			await p.keyboard.press( 'Enter' );
			await expect.poll( () => corte.cuenta.pjax, { message: `${ motor }: Enter lanza el PJAX`, timeout: 5_000 } ).toBe( 1 );

			const reintentar = p.locator( '.akb-aviso-red [data-akb-reintentar]' );
			await expect( reintentar, `${ motor }: el foco va a «Reintentar»` ).toBeFocused( { timeout: ESPERA_AVISO + 5_000 } );
			await expect( reintentar, `${ motor }: y se ve` ).toBeInViewport();
			expect( new URL( p.url() ).pathname, motor ).toBe( '/tienda/' );
			if ( 'chromium' === motor ) {
				await p.keyboard.press( 'Tab' );
				await pausa( 500 );
				expect( await p.evaluate( () => scrollY ), `${ motor }: el siguiente Tab sigue junto al aviso, no en el pie` ).toBeLessThan( 900 );
			}
			await test.info().attach( `rev-c-s-2o-2-${ motor }`, { body: await p.screenshot(), contentType: 'image/png' } );
		} );
	} );

	/*
	 * D-S (REV-C-S-3o-1, 3o-2 y 3f-1): quien ve la pantalla ve el aviso y tiene
	 * «Reintentar» a una tecla, sin que se le muevan el foco ni la página si
	 * siguió con el teclado o conserva el foco en su casilla. En WebKit Tab no
	 * pasa por enlaces ni botones (el Safari de fábrica): ahí es Opción+Tab.
	 */
	const MODELOS_API = [
		{ nombre: 'con Navigation API', corto: 'con-api', senales: { beforeunload: true, navigationApi: true } },
		{ nombre: 'sin Navigation API', corto: 'sin-api', senales: { beforeunload: true, navigationApi: false } },
	];
	const TAB = ( motor ) => ( 'webkit' === motor ? 'Alt+Tab' : 'Tab' );

	/**
	 * La copia flotante del aviso: visible, dentro de la ventana, sin nada
	 * encima de su «Reintentar», sin tapar el foco del cliente y, en el
	 * teléfono, por encima del bottom-nav.
	 */
	async function copiaALaVista( p, cual ) {
		const copia = p.locator( '[data-akb-aviso-flotante] .akb-aviso-red' );
		await expect( copia, `${ cual }: el aviso flota` ).toBeVisible();
		await expect( copia, `${ cual }: dentro de la ventana` ).toBeInViewport( { ratio: 1 } );
		const g = await p.evaluate( () => {
			const caja = ( el ) => el.getBoundingClientRect();
			const boton = document.querySelector( '[data-akb-aviso-flotante] [data-akb-reintentar]' );
			const b = caja( boton );
			const encima = document.elementFromPoint( b.left + b.width / 2, b.top + b.height / 2 );
			const c = caja( document.querySelector( '[data-akb-aviso-flotante] .akb-aviso-red' ) );
			const f = document.activeElement;
			const fc = caja( f.closest( 'label' ) || f );
			const nav = document.querySelector( '.bottom-nav' );
			const navTop = nav && 'none' !== getComputedStyle( nav ).display ? caja( nav ).top : innerHeight;
			const r = ( x ) => [ x.top, x.bottom, x.left, x.right ].map( Math.round ).join( ',' );
			return {
				cajas: `copia ${ r( c ) } · foco ${ r( fc ) } (${ f.tagName } ${ ( f.textContent || '' ).trim().slice( 0, 20 ) }) · scrollY ${ Math.round( scrollY ) }`,
				libre: !! encima && boton.contains( encima ),
				tapaElFoco: c.left < fc.right && fc.left < c.right && c.top < fc.bottom && fc.top < c.bottom,
				sobreLaFranja: c.bottom <= navTop,
				texto: document.querySelector( '[data-akb-aviso-flotante]' ).textContent.replace( /\s+/g, ' ' ).trim(),
			};
		} );
		expect( g.libre, `${ cual }: nada tapa su «Reintentar» (${ g.cajas })` ).toBe( true );
		expect( g.tapaElFoco, `${ cual }: no tapa el foco del cliente, WCAG 2.4.11 (${ g.cajas })` ).toBe( false );
		expect( g.sobreLaFranja, `${ cual }: por encima del bottom-nav (${ g.cajas })` ).toBe( true );
		expect( g.texto, cual ).toMatch( /conexión/ );
		const leido = await p.evaluate( () => ( document.getElementById( 'akb-live-region' )?.textContent || '' ).replace( /\s+/g, ' ' ).trim() );
		expect( leido, `${ cual }: la región viva lo leyó (una vez: la copia no es otra región viva)` ).toBe( g.texto );
		expect( await p.locator( '[data-akb-aviso-flotante] [aria-live], [data-akb-aviso-flotante][aria-live]' ).count(), cual ).toBe( 0 );
	}

	test( 'REV-C-S-3o-1: con el teclado, si el cliente sigue durante la espera, ni la página ni el foco se mueven y el aviso flota junto a su foco, con «Reintentar» a una tecla', async ( { browser, baseURL, isMobile } ) => {
		test.setTimeout( 360_000 );
		await conContextosNuevos( browser, baseURL || '', async ( nuevo, motor ) => {
			for ( const modelo of MODELOS_API ) {
				const cual = `${ motor }, ${ isMobile ? 'móvil' : 'escritorio' }, ${ modelo.nombre }`;
				const contexto = await nuevo();
				await modelarSafari( contexto, modelo.senales );
				const p = await contexto.newPage();
				const corte = await cortarElPjax( p, /\/tienda\/page\/2\/?$/ );

				await p.goto( '/tienda/', { waitUntil: 'load' } );
				await corte.marcar();
				const pagina2 = p.locator( '.woocommerce-pagination a.page-numbers:not(.current):not(.next):not(.prev)' ).first();
				const urlDelPjax = new URL( /** @type {string} */ ( await pagina2.getAttribute( 'href' ) ), p.url() ).pathname;
				await pagina2.focus();
				await p.keyboard.press( 'Enter' );
				await expect.poll( () => corte.cuenta.pjax, { message: `${ cual }: Enter lanza el PJAX`, timeout: 5_000 } ).toBe( 1 );

				// Sigue con el teclado mientras carga: al siguiente enlace del paginador.
				await pausa( 1_500 );
				await p.keyboard.press( TAB( motor ) );
				const antes = await p.evaluate( () => {
					document.activeElement.setAttribute( 'data-e2e-foco', '' );
					return { scrollY: Math.round( scrollY ), foco: document.activeElement.textContent.trim() };
				} );
				expect( antes.foco, `${ cual }: el Tab lo llevó a otro enlace del paginador` ).not.toBe( '' );

				await pausa( ESPERA_AVISO + 500 );
				expect( new URL( p.url() ).pathname, cual ).toBe( '/tienda/' );
				const despues = await p.evaluate( () => Math.round( scrollY ) );
				expect( Math.abs( despues - antes.scrollY ), `${ cual }: la página no sube al aviso (antes ${ antes.scrollY }, después ${ despues })` ).toBeLessThanOrEqual( 30 );
				await expect( p.locator( '[data-e2e-foco]' ), `${ cual }: el foco sigue donde lo llevó` ).toBeFocused();
				await expect( p.locator( '[data-e2e-foco]' ), `${ cual }: y a la vista` ).toBeInViewport();
				await copiaALaVista( p, cual );
				await test.info().attach( `d-s-3o-1-${ modelo.corto }-${ motor }-${ isMobile ? 'movil' : 'escritorio' }`, { body: await p.screenshot(), contentType: 'image/png' } );

				// «Reintentar» es lo siguiente del Tab, y carga lo pedido como sin JS.
				await p.keyboard.press( TAB( motor ) );
				await expect( p.locator( '[data-akb-aviso-flotante] [data-akb-reintentar]' ), `${ cual }: a una tecla` ).toBeFocused();
				expect( Math.abs( await p.evaluate( () => Math.round( scrollY ) ) - antes.scrollY ), `${ cual }: sin mover la página` ).toBeLessThanOrEqual( 30 );
				await p.keyboard.press( 'Enter' );
				await llegaComoSinJs( p, corte, 1, cual, ( u ) => u.pathname === urlDelPjax );
				await contexto.close();
			}
		} );
	} );

	test( 'REV-C-S-3o-2 / 3f-1: la casilla marcada con Espacio lejos de la barra no se desmarca en silencio: el aviso flota junto a ella, sin moverle el foco ni la página', async ( { browser, baseURL, isMobile } ) => {
		test.skip( isMobile, 'en el teléfono las casillas van en el cajón (REV-C-S-1o-1)' );
		test.setTimeout( 360_000 );
		await conContextosNuevos( browser, baseURL || '', async ( nuevo, motor ) => {
			for ( const mac of MACS ) {
				for ( const facetas of [ 'plegadas', 'abiertas' ] ) {
					const cual = `${ motor }, ${ mac.nombre }, facetas ${ facetas }`;
					const contexto = await nuevo();
					await modelarSafari( contexto, mac.senales );
					const p = await contexto.newPage();
					const corte = await cortarElPjax( p, /^\/tienda\/?$/ );

					await p.goto( '/tienda/', { waitUntil: 'load' } );
					await corte.marcar();
					if ( 'abiertas' === facetas ) {
						await p.evaluate( () => document.querySelectorAll( '[data-akb-facets] [data-akb-facet-toggle][aria-expanded="false"]' ).forEach( ( b ) => /** @type {HTMLElement} */ ( b ).click() ) );
						await pausa( 500 );
					}
					// La última casilla a la vista del formulario: la más lejos de la barra.
					const pedido = await p.evaluate( () => {
						const cs = [ ...document.querySelectorAll( '[data-akb-facets] input[type="checkbox"]' ) ].filter( ( c ) => c.getBoundingClientRect().height > 0 );
						const c = /** @type {HTMLInputElement} */ ( cs[ cs.length - 1 ] );
						c.setAttribute( 'data-e2e-casilla', '' );
						return { nombre: c.name.replace( /\[\]$/, '' ), valor: c.value };
					} );
					const casilla = p.locator( '[data-e2e-casilla]' );
					if ( 'plegadas' === facetas ) {
						// Como la revisión: la página 400 px abajo, la casilla a la vista y la barra no.
						await p.evaluate( () => window.scrollTo( { top: 400, behavior: 'instant' } ) );
						await pausa( 300 );
						await casilla.evaluate( ( c ) => /** @type {HTMLElement} */ ( c ).focus( { preventScroll: true } ) );
					} else {
						await casilla.focus(); // el navegador la trae a la vista
					}
					await pausa( 300 );
					const antes = await p.evaluate( () => Math.round( scrollY ) );
					await p.keyboard.press( 'Space' );
					await expect.poll( () => corte.cuenta.pjax, { message: `${ cual }: Espacio lanza el PJAX`, timeout: 5_000 } ).toBeGreaterThanOrEqual( 1 );

					await pausa( ESPERA_AVISO + 500 );
					expect( new URL( p.url() ).search, `${ cual }: sin filtro` ).toBe( '' );
					await expect( casilla, `${ cual }: vuelve a lo pintado` ).not.toBeChecked();
					await expect( casilla, `${ cual }: con el foco en ella (WCAG 3.2.2)` ).toBeFocused();
					expect( Math.abs( await p.evaluate( () => Math.round( scrollY ) ) - antes ), `${ cual }: la página no se mueve` ).toBeLessThanOrEqual( 30 );
					// El escenario es el de la revisión: el aviso de la barra no se ve desde ahí.
					const barraVisible = await p.evaluate( () => {
						const b = document.querySelector( '.akb-shop-toolbar [data-akb-reintentar]' );
						const r = b.getBoundingClientRect();
						const encima = document.elementFromPoint( r.left + r.width / 2, r.top + r.height / 2 );
						return !! encima && b.contains( encima );
					} );
					expect( barraVisible, `${ cual }: el aviso de la barra queda fuera de la vista` ).toBe( false );
					await copiaALaVista( p, cual );
					await test.info().attach( `d-s-3o-2-${ mac.corto }-${ motor }-${ facetas }`, { body: await p.screenshot(), contentType: 'image/png' } );

					await p.keyboard.press( TAB( motor ) );
					const reintentar = p.locator( '[data-akb-aviso-flotante] [data-akb-reintentar]' );
					await expect( reintentar, `${ cual }: «Reintentar» a una tecla` ).toBeFocused();
					if ( 'plegadas' === facetas ) {
						await p.keyboard.press( 'Enter' );
						await llegaComoSinJs( p, corte, corte.cuenta.pjax, cual, ( u ) => '/tienda/' === u.pathname && pedido.valor === u.searchParams.get( pedido.nombre ) );
						await expect( p.locator( `[data-akb-facets] input[name="${ pedido.nombre }[]"][value="${ pedido.valor }"]` ), `${ cual }: el filtro quedó aplicado` ).toBeChecked();
					} else {
						// Seguir tabulando la retira: no tapa lo siguiente que enfoque.
						await p.keyboard.press( TAB( motor ) );
						await expect( p.locator( '[data-akb-aviso-flotante]' ), `${ cual }: el siguiente Tab la retira` ).toHaveCount( 0 );
						await expect( p.locator( '.akb-shop-toolbar .akb-aviso-red' ), `${ cual }: el de la barra se queda` ).toHaveCount( 1 );
					}
					await contexto.close();
				}
			}
		} );
	} );

	/*
	 * D-S-c1 (REV-D-S-1o-1): quien se va al buscador durante la espera y escribe
	 * tenía la copia encima de la primera sugerencia —la activa tras ArrowDown,
	 * porque el foco no sale del campo— y la copia se quedaba con su clic o su
	 * toque. Con la lista abierta la copia no se pinta; con la lista cerrada se
	 * ve bajo el campo. Sin Populares (una tienda que todavía no los tiene) el
	 * campo vacío deja la lista cerrada.
	 */
	test( 'REV-D-S-1o-1: con el buscador abierto durante la espera, la copia no tapa sus sugerencias ni se queda con su clic o su toque', async ( { browser, baseURL, isMobile } ) => {
		test.setTimeout( 360_000 );
		const campo = isMobile ? '#akb-mobile-search-input' : '#akb-search-input';
		await conContextosNuevos( browser, baseURL || '', async ( nuevo, motor ) => {
			for ( const modelo of MODELOS_API ) {
				const cual = `${ motor }, ${ isMobile ? 'móvil' : 'escritorio' }, ${ modelo.nombre }`;
				const contexto = await nuevo();
				await modelarSafari( contexto, modelo.senales );
				const p = await contexto.newPage();
				const corte = await cortarElPjax( p, /\/tienda\/page\/2\/?$/ );
				await p.route( ( u ) => /\/search\/popular$/.test( u.pathname ), ( route ) => route.fulfill( { status: 200, contentType: 'application/json', body: '{"items":[]}' } ) );

				await p.goto( '/tienda/', { waitUntil: 'load' } );
				await corte.marcar();
				const pagina2 = p.locator( '.woocommerce-pagination a.page-numbers:not(.current):not(.next):not(.prev)' ).first();
				if ( isMobile ) {
					await pagina2.scrollIntoViewIfNeeded();
					await pagina2.tap();
					await expect.poll( () => corte.cuenta.pjax, { message: `${ cual }: el toque lanza el PJAX`, timeout: 5_000 } ).toBe( 1 );
					await p.tap( '#menu-toggle' );
					await pausa( 500 );
					await p.tap( campo );
				} else {
					await pagina2.click();
					await expect.poll( () => corte.cuenta.pjax, { message: `${ cual }: el clic lanza el PJAX`, timeout: 5_000 } ).toBe( 1 );
					await pausa( 1_000 );
					await p.click( campo );
				}
				await p.keyboard.type( 'one', { delay: 60 } );
				// La lista abierta CON sugerencias: antes pasa por «Buscando…», sin opciones.
				const opciones = p.locator( `#${ await p.locator( campo ).getAttribute( 'aria-controls' ) } [role="option"]` );
				await expect( opciones.first(), `${ cual }: la lista se abre con sugerencias` ).toBeVisible( { timeout: 10_000 } );
				await pausa( ESPERA_AVISO + 500 );
				expect( new URL( p.url() ).pathname, cual ).toBe( '/tienda/' );

				/** La sugerencia activa (o la primera): ¿la ve y la alcanza el cliente? */
				const sugerencia = () => p.evaluate( ( sel ) => {
					const i = document.querySelector( sel );
					const lista = document.getElementById( i.getAttribute( 'aria-controls' ) || '' );
					const id = i.getAttribute( 'aria-activedescendant' );
					const o = id ? document.getElementById( id ) : lista && lista.querySelector( '[role="option"]' );
					const b = o.getBoundingClientRect();
					const x = Math.round( b.left + b.width / 2 );
					const y = Math.round( b.top + b.height / 2 );
					const encima = document.elementFromPoint( x, y );
					const copia = document.querySelector( '[data-akb-aviso-flotante]' );
					const c = copia ? copia.getBoundingClientRect() : null;
					return {
						x,
						y,
						activa: !! id,
						href: o.getAttribute( 'href' ),
						libre: !! encima && o.contains( encima ),
						cajas: `opción ${ Math.round( b.top ) }…${ Math.round( b.bottom ) } · copia ${ c ? Math.round( c.top ) + '…' + Math.round( c.bottom ) : '—' }`,
					};
				}, campo );

				// El aviso está (junto al campo y leído), pero no se pinta sobre la lista.
				await expect( p.locator( '[data-akb-aviso-flotante]' ), `${ cual }: la copia está` ).toHaveCount( 1 );
				await expect( p.locator( '[data-akb-aviso-flotante] .akb-aviso-red' ), `${ cual }: con la lista abierta no se pinta` ).toBeHidden();
				const primera = await sugerencia();
				expect( primera.libre, `${ cual }: nada tapa la primera sugerencia (${ primera.cajas })` ).toBe( true );
				if ( ! isMobile ) {
					await p.keyboard.press( 'ArrowDown' );
					const activa = await sugerencia();
					expect( activa.activa, `${ cual }: ArrowDown la activa` ).toBe( true );
					expect( activa.libre, `${ cual }: la opción activa se ve (${ activa.cajas })` ).toBe( true );
				}

				// Con el campo vacío la lista se cierra: la copia se ve bajo el campo.
				for ( let i = 0; i < 3; i++ ) {
					await p.keyboard.press( 'Backspace' );
				}
				await expect( p.locator( campo ), `${ cual }: la lista se cierra` ).toHaveAttribute( 'aria-expanded', 'false', { timeout: 5_000 } );
				await copiaALaVista( p, `${ cual }, lista cerrada` );
				await test.info().attach( `d-s-c1-1o-1-${ modelo.corto }-${ motor }-${ isMobile ? 'movil' : 'escritorio' }`, { body: await p.screenshot(), contentType: 'image/png' } );

				// Vuelve a escribir: la lista se abre y la copia se aparta; la sugerencia se alcanza.
				await p.keyboard.type( 'one', { delay: 60 } );
				await expect( opciones.first(), `${ cual }: la lista vuelve con sugerencias` ).toBeVisible( { timeout: 10_000 } );
				await expect( p.locator( '[data-akb-aviso-flotante] .akb-aviso-red' ), `${ cual }: se aparta de la lista` ).toBeHidden();
				const destino = await sugerencia();
				expect( destino.libre, `${ cual }: (${ destino.cajas })` ).toBe( true );
				if ( isMobile ) {
					await p.touchscreen.tap( destino.x, destino.y );
				} else {
					await p.mouse.click( destino.x, destino.y );
				}
				await p.waitForURL( ( u ) => u.pathname === new URL( destino.href, p.url() ).pathname, { timeout: 15_000 } );
				await contexto.close();
			}
		} );
	} );

	/*
	 * D-S-c1 (REV-D-S-1o-2 y 1f-1): la lista de una faceta se desplaza por dentro
	 * (260 px, `overflow-y: auto`). La copia no se enteraba y terminaba encima de
	 * su casilla —la que conserva el foco— con la rueda o, en Chromium, con las
	 * flechas; y si la lista escondía la casilla, se ponía junto a un control que
	 * no se veía, encima de otras casillas.
	 */
	test( 'REV-D-S-1o-2 / 1f-1: la copia sigue a su casilla cuando se desplaza la lista de la faceta, y no se pinta mientras la lista la esconde', async ( { browser, baseURL, isMobile } ) => {
		test.skip( isMobile, 'en el teléfono las casillas van en el cajón (REV-C-S-1o-1)' );
		test.setTimeout( 360_000 );
		await conContextosNuevos( browser, baseURL || '', async ( nuevo, motor ) => {
			for ( const modelo of MODELOS_API ) {
				const cual = `${ motor }, ${ modelo.nombre }`;
				const contexto = await nuevo();
				await modelarSafari( contexto, modelo.senales );
				const p = await contexto.newPage();
				const corte = await cortarElPjax( p, /^\/tienda\/?$/ );

				await p.goto( '/tienda/', { waitUntil: 'load' } );
				await corte.marcar();
				await p.evaluate( () => document.querySelectorAll( '[data-akb-facets] [data-akb-facet-toggle][aria-expanded="false"]' ).forEach( ( b ) => /** @type {HTMLElement} */ ( b ).click() ) );
				await pausa( 600 );
				// La lista con más recorrido (Género) y una casilla a 250 px de su
				// principio, a 100 px del borde de arriba; la lista a media ventana
				// y la barra fuera de la vista.
				const recorrido = await p.evaluate( () => {
					const listas = [ ...document.querySelectorAll( '[data-akb-facets] .akb-facet-list' ) ].filter( ( l ) => l.getBoundingClientRect().height > 0 );
					const l = listas.sort( ( a, b ) => ( b.scrollHeight - b.clientHeight ) - ( a.scrollHeight - a.clientHeight ) )[ 0 ];
					const casillas = [ ...l.querySelectorAll( 'input[type="checkbox"]' ) ];
					const c = casillas.find( ( x ) => x.closest( 'label' ).offsetTop - l.firstElementChild.offsetTop >= 250 ) || casillas[ casillas.length - 1 ];
					c.setAttribute( 'data-e2e-casilla', '' );
					l.setAttribute( 'data-e2e-lista', '' );
					l.scrollTop = c.closest( 'label' ).getBoundingClientRect().top - l.getBoundingClientRect().top - 100;
					window.scrollBy( { top: l.getBoundingClientRect().top - 250, behavior: 'instant' } );
					return l.scrollHeight - l.clientHeight;
				} );
				expect( recorrido, `${ cual }: una lista que se desplaza de sobra (la de Género)` ).toBeGreaterThanOrEqual( 550 );
				const casilla = p.locator( '[data-e2e-casilla]' );
				const lista = p.locator( '[data-e2e-lista]' );

				/** Casilla, copia y lista: el hueco entre casilla y copia, y qué tapa la copia. */
				const medir = () => p.evaluate( () => {
					const l = document.querySelector( '[data-e2e-lista]' );
					const et = document.querySelector( '[data-e2e-casilla]' ).closest( 'label' );
					const copia = document.querySelector( '[data-akb-aviso-flotante]' );
					const pintada = !! copia && 'hidden' !== getComputedStyle( copia ).visibility;
					const r = ( el ) => el.getBoundingClientRect();
					const bajoLaCopia = ( el ) => {
						const b = r( el );
						const encima = document.elementFromPoint( b.left + Math.min( 40, b.width / 2 ), b.top + b.height / 2 );
						return !! encima && !! copia && copia.contains( encima );
					};
					const lb = r( l );
					const eb = r( et );
					const c = copia ? r( copia ) : null;
					return {
						pintada,
						hueco: pintada ? Math.round( c.top - eb.bottom ) : null,
						casillaVisible: eb.bottom > lb.top && eb.top < lb.bottom,
						focoTapado: bajoLaCopia( et ),
						cajas: `lista ${ Math.round( lb.top ) }…${ Math.round( lb.bottom ) } st${ Math.round( l.scrollTop ) } · casilla ${ Math.round( eb.top ) }…${ Math.round( eb.bottom ) } · copia ${ c ? Math.round( c.top ) + '…' + Math.round( c.bottom ) : '—' }`,
					};
				} );
				const rueda = async ( dy ) => {
					const b = await lista.boundingBox();
					await p.mouse.move( b.x + 40, b.y + b.height / 2 );
					await p.mouse.wheel( 0, dy );
					await pausa( 500 );
				};

				await p.keyboard.press( 'Shift' );
				await casilla.evaluate( ( c ) => /** @type {HTMLElement} */ ( c ).focus( { preventScroll: true } ) );
				await p.keyboard.press( 'Space' );
				await expect.poll( () => corte.cuenta.pjax, { message: `${ cual }: Espacio lanza el PJAX`, timeout: 5_000 } ).toBeGreaterThanOrEqual( 1 );

				// 1f-1 (b): durante la espera, la rueda sobre la lista esconde la casilla.
				await pausa( 800 );
				await rueda( 400 );
				await pausa( ESPERA_AVISO + 500 );
				await expect( casilla, `${ cual }: con el foco en ella` ).toBeFocused();
				let m = await medir();
				expect( m.casillaVisible, `${ cual }: la lista la escondió (${ m.cajas })` ).toBe( false );
				await expect( p.locator( '[data-akb-aviso-flotante]' ), `${ cual }: la copia está` ).toHaveCount( 1 );
				expect( m.pintada, `${ cual }: pero no se pinta junto a un control que no se ve (${ m.cajas })` ).toBe( false );

				// La casilla vuelve a la vista, y la copia con ella.
				await rueda( -400 );
				m = await medir();
				expect( m.casillaVisible, `${ cual }: (${ m.cajas })` ).toBe( true );
				expect( m.pintada, `${ cual }: la copia sale con su casilla (${ m.cajas })` ).toBe( true );
				expect( Math.abs( m.hueco - 8 ), `${ cual }: 8 px bajo ella (${ m.cajas })` ).toBeLessThanOrEqual( 2 );
				await copiaALaVista( p, `${ cual }, de vuelta` );

				// 1f-1 (a) y 1o-2: la rueda y las flechas mueven la lista con la copia puesta.
				for ( const [ gesto, mover ] of [ [ 'rueda −40', () => rueda( -40 ) ], [ 'ArrowUp', async () => { await p.keyboard.press( 'ArrowUp' ); await pausa( 500 ); } ] ] ) {
					await mover();
					m = await medir();
					expect( m.focoTapado, `${ cual }, ${ gesto }: la copia no tapa la casilla con el foco, WCAG 2.4.11 (${ m.cajas })` ).toBe( false );
					expect( Math.abs( m.hueco - 8 ), `${ cual }, ${ gesto }: sigue 8 px bajo ella (${ m.cajas })` ).toBeLessThanOrEqual( 2 );
					await expect( casilla, `${ cual }, ${ gesto }` ).toBeFocused();
				}
				await test.info().attach( `d-s-c1-1o-2-${ modelo.corto }-${ motor }`, { body: await p.screenshot(), contentType: 'image/png' } );
				await contexto.close();
			}
		} );
	} );

	/*
	 * C-S-c2: sin conexión (`navigator.onLine` en false) no hay página nueva que
	 * esperar: el aviso sale al segundo, no a los 4 s de un fallo «en línea».
	 */
	test( 'C-S-c2: sin conexión el aviso sale al segundo', async ( { page, baseURL } ) => {
		await enLosDosMotores( page, baseURL || '', async ( p, motor ) => {
			await p.goto( '/tienda/', { waitUntil: 'load' } );
			await p.context().setOffline( true );
			try {
				expect( await p.evaluate( () => navigator.onLine ), `${ motor }: el navegador sabe que no hay conexión` ).toBe( false );
				const desde = Date.now();
				await p.evaluate( () => /** @type {HTMLElement} */ ( document.querySelector( '.woocommerce-pagination a.page-numbers:not(.current):not(.next):not(.prev)' ) ).click() );
				await expect( p.locator( '.akb-aviso-red' ), `${ motor }: el aviso` ).toBeVisible( { timeout: ESPERA_AVISO + 5_000 } );
				const tardo = Date.now() - desde;
				expect( tardo, `${ motor }: al segundo (${ tardo } ms), sin los 4 s de un fallo en línea` ).toBeLessThan( ESPERA_AVISO - 1_000 );
				expect( tardo, `${ motor }: pero deja llegar a navigate/beforeunload (${ tardo } ms)` ).toBeGreaterThanOrEqual( ESPERA_SIN_CONEXION - 100 );
				expect( new URL( p.url() ).pathname, motor ).toBe( '/tienda/' );
			} finally {
				await p.context().setOffline( false );
			}
		} );
	} );
} );
