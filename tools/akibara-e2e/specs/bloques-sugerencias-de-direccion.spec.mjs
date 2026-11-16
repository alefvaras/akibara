// @ts-check
/**
 * Las sugerencias de dirección del checkout (akibara-cart-checkout-blocks, el
 * proveedor de Google Places) cuando el cliente deja de escribir y va a tocar
 * una. Desde que la búsqueda espera 250 ms de calma, todo lo que hace el
 * proveedor pasa JUSTO en ese momento (revisión R-B, ronda 1, 2026-09-24):
 *
 * - El «Buscando direcciones…» se metía encima de la lista abierta y la bajaba
 *   43 px: el toque completaba la sugerencia de ARRIBA. Una dirección de
 *   despacho equivocada.
 * - Escape cerraba la lista, pero la búsqueda pendiente o en vuelo la volvía a
 *   abrir al contestar.
 * - Con el ratón, si la búsqueda vencía entre el mousedown y el mouseup, Woo
 *   recibía [] y desmontaba la lista antes del click: la dirección no se
 *   completaba.
 *
 * Ronda 2: Woo reemplaza la lista EN EL MISMO SITIO cuando contesta una
 * búsqueda. Con el dedo (el foco no se mueve hasta levantarlo) o con las
 * flechas (el foco nunca sale del campo), el toque o el Enter completaban la
 * sugerencia NUEVA de esa posición, no la que el cliente veía.
 *
 * Ronda 3: retener la respuesta mientras se usaban las flechas congelaba la
 * lista vieja si la flecha llegaba justo después de teclear. La respuesta se
 * entrega siempre, después de soltar el resaltado de Woo.
 *
 * RV-FB-01 (2026-09-25): con menos de tres letras Woo cierra la lista sin
 * soltar el resaltado, y la lista de la calle siguiente abría con esa posición
 * resaltada: Enter la completaba. Sólo se ve en WebKit (Chromium lo soltaba por
 * su cuenta); la configuración del repo corre sólo Chromium, así que la prueba
 * del final distingue el arreglo únicamente con un proyecto WebKit.
 *
 * Google no se toca: un doble de `google.maps.importLibrary` contesta cuatro
 * sugerencias con la latencia que pida cada prueba, y todo lo que va a
 * googleapis.com se corta. Las sugerencias DEPENDEN DEL TEXTO (un doble que
 * contesta lo mismo a todo no puede ver que la lista cambió bajo el dedo):
 * «Avenida Pro» trae cuatro calles distintas y cualquier otro texto, «<texto>
 * 1000..4000»; el id es «<texto>#<posición>». Los tiempos se miden DENTRO de la
 * página, en el mismo reloj que el proveedor.
 *
 * Escribe en el carrito: contra producción se salta entero.
 */

import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';

const SUGERENCIA = '.wc-block-components-address-autocomplete-suggestion';
const CALLES = [ 'Avenida Providencia 1000', 'Avenida Pedro Montt 2000', 'Avenida Presidente Kennedy 3000', 'Avenida Portugal 4000' ];

/** Lo que contesta el doble para `texto` (ver la cabecera). */
function sugerenciasPara( texto ) {
	return 'Avenida Pro' === texto ? CALLES : [ 1, 2, 3, 4 ].map( ( n ) => `${ texto } ${ n }000` );
}

/**
 * Carrito con un producto comprable y el checkout abierto con el doble de Places.
 *
 * @param {import('@playwright/test').Page} page Página.
 * @param {import('@playwright/test').BrowserContext} context Contexto.
 * @param {number} latencia Lo que tarda el doble en contestar, en ms.
 * @returns {Promise<import('@playwright/test').Locator>} El campo de dirección.
 */
async function checkoutConDoble( page, context, latencia ) {
	await context.clearCookies();
	await page.route( /(places|maps)\.googleapis\.com/, ( route ) => route.abort() );
	await page.addInitScript( ( [ ms, calles ] ) => {
		// `retener`: el texto cuya respuesta espera al próximo mousedown o
		// touchstart (y sale 20 ms después), o a `soltar()`: así «Google contesta
		// durante la pulsación» no depende de lo cargada que esté la máquina.
		const doble = { latencia: ms, retener: '', pedidas: [], respuestas: [], elegidas: [] };
		window.akbE2eDoble = doble;
		const alPulsar = [];
		doble.soltar = () => alPulsar.splice( 0 ).forEach( ( fn ) => fn() );
		const places = {
			AutocompleteSessionToken: class {},
			AutocompleteSuggestion: {
				fetchAutocompleteSuggestions( pedido ) {
					doble.pedidas.push( { t: performance.now(), texto: pedido.input } );
					const retenida = doble.retener === pedido.input;
					const nombres = 'Avenida Pro' === pedido.input ? calles : [ 1, 2, 3, 4 ].map( ( n ) => `${ pedido.input } ${ n }000` );
					return new Promise( ( responder ) => ( retenida ? ( fn ) => alPulsar.push( () => setTimeout( fn, 20 ) ) : ( fn ) => setTimeout( fn, doble.latencia ) )( () => {
						doble.respuestas.push( { t: performance.now(), texto: pedido.input } );
						responder( {
							suggestions: nombres.map( ( nombre, i ) => ( {
								placePrediction: {
									placeId: `${ pedido.input }#${ i }`,
									text: { toString: () => nombre },
									toPlace() {
										return {
											addressComponents: [],
											async fetchFields() {
												doble.elegidas.push( `${ pedido.input }#${ i }` );
												const [ , calle, numero ] = nombre.match( /^(.*) (\d+)$/ );
												this.addressComponents = [
													{ types: [ 'route' ], longText: calle },
													{ types: [ 'street_number' ], longText: numero },
													{ types: [ 'locality' ], longText: 'Providencia' },
													{ types: [ 'administrative_area_level_1' ], shortText: 'RM', longText: 'Región Metropolitana' },
													{ types: [ 'country' ], shortText: 'CL' },
												];
											},
										};
									},
								},
							} ) ),
						} );
					} ) );
				},
			},
		};
		window.google = { maps: { importLibrary: async () => places } };
		// Teclas, pulsaciones y Escape del campo, en el reloj de la página. `bajo`:
		// la sugerencia que había bajo el dedo o el puntero al bajar.
		const marcas = { teclas: [], abajo: [], arriba: [], escape: [], toques: [], sueltas: [], bajo: [] };
		window.akbE2eMarcas = marcas;
		document.addEventListener( 'input', ( e ) => {
			if ( e.target && 'billing-address_1' === e.target.id ) {
				marcas.teclas.push( { t: performance.now(), valor: e.target.value } );
			}
		}, true );
		// La espera de 250 ms del proveedor, en el reloj de la página: cuándo corre
		// de verdad cada una. Con `marcas.retenerEspera` puesto, la que se cree
		// queda RETENIDA y corre 20 ms después del próximo mousedown: «vence la
		// espera durante la pulsación» sin depender de lo cargada que esté la
		// máquina. clearTimeout la sigue cancelando como siempre.
		marcas.esperas = [];
		marcas.retenerEspera = false;
		const programar = window.setTimeout;
		const cancelar = window.clearTimeout;
		const retenidas = new Map();
		window.setTimeout = function ( fn, ms, ...resto ) {
			if ( 250 !== ms || 'function' !== typeof fn ) {
				return programar.call( window, fn, ms, ...resto );
			}
			const creada = performance.now();
			const correr = () => {
				retenidas.delete( id );
				marcas.esperas.push( { creada, corre: performance.now() } );
				fn( ...resto );
			};
			const id = programar.call( window, correr, marcas.retenerEspera ? 600_000 : ms );
			if ( marcas.retenerEspera ) {
				retenidas.set( id, { correr, actual: id } );
			}
			return id;
		};
		window.clearTimeout = function ( id ) {
			const retenida = retenidas.get( id );
			retenidas.delete( id );
			return cancelar.call( window, retenida ? retenida.actual : id );
		};
		marcas.retenidas = () => retenidas.size;
		// Deja correr las esperas retenidas 20 ms después (el mousedown lo hace solo).
		marcas.soltarEsperas = () => {
			marcas.retenerEspera = false;
			retenidas.forEach( ( retenida ) => {
				cancelar.call( window, retenida.actual );
				retenida.actual = programar.call( window, retenida.correr, 20 );
			} );
		};
		// pointerdown: con el dedo llega al tocar (el mousedown de compatibilidad
		// llega al levantarlo, sobre lo que haya debajo ENTONCES).
		document.addEventListener( 'pointerdown', ( e ) => {
			marcas.bajo.push( e.target?.closest?.( '.wc-block-components-address-autocomplete-suggestion' )?.textContent ?? null );
		}, true );
		document.addEventListener( 'touchstart', () => {
			marcas.toques.push( performance.now() );
			alPulsar.splice( 0 ).forEach( ( fn ) => fn() );
		}, true );
		// pointerup y no touchend: si la lista cambia bajo el dedo, el touchend va
		// al <li> ya retirado del documento y no llega aquí.
		document.addEventListener( 'pointerup', ( e ) => {
			if ( 'touch' === e.pointerType ) {
				marcas.sueltas.push( performance.now() );
			}
		}, true );
		document.addEventListener( 'mousedown', () => {
			marcas.abajo.push( performance.now() );
			alPulsar.splice( 0 ).forEach( ( fn ) => fn() );
			if ( marcas.retenerEspera ) {
				marcas.soltarEsperas();
			}
		}, true );
		document.addEventListener( 'mouseup', () => marcas.arriba.push( performance.now() ), true );
		document.addEventListener( 'keydown', ( e ) => {
			if ( 'Escape' === e.key ) {
				marcas.escape.push( performance.now() );
			}
		}, true );
	}, [ latencia, CALLES ] );

	const r = await page.request.get( '/wp-json/wc/store/v1/products?per_page=40' );
	const id = r.ok()
		? ( await r.json() ).find( ( p ) => p.is_purchasable && p.is_in_stock && 'simple' === p.type )?.id
		: undefined;
	test.skip( ! id, 'sin productos comprables en este entorno' );
	await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
	const agregado = await page.request.post( '/?wc-ajax=add_to_cart', { form: { product_id: String( id ), quantity: '1' } } );
	expect( agregado.ok(), `agregar ${ id }` ).toBeTruthy();
	await page.goto( '/checkout/', { waitUntil: 'domcontentloaded' } );
	const campo = page.locator( '#billing-address_1' );
	await expect( campo ).toBeVisible( { timeout: 20_000 } );
	test.skip( 'combobox' !== await campo.getAttribute( 'role' ), 'el autocompletado de direcciones está apagado en este entorno' );
	return campo;
}

/**
 * Escribe «Avenida Pro» con el campo a media pantalla (la lista abre hacia
 * abajo, lo normal) y espera la lista con sus cuatro sugerencias ya quieta.
 *
 * @param {import('@playwright/test').Page} page Página.
 * @param {import('@playwright/test').Locator} campo Campo de dirección.
 */
async function abrirLista( page, campo ) {
	await campo.evaluate( ( el ) => el.scrollIntoView( { block: 'center' } ) );
	await campo.click();
	await page.keyboard.type( 'Avenida Pro', { delay: 80 } );
	await expect( page.locator( SUGERENCIA ) ).toHaveCount( 4 );
	await expect( page.locator( SUGERENCIA ).nth( 1 ), 'la lista es la de «Avenida Pro»' ).toHaveText( CALLES[ 1 ] );
	await expect( campo ).not.toHaveAttribute( 'aria-busy', 'true' );
	await expect( page.locator( '.akb-address-autocomplete-feedback:not([hidden])' ) ).toHaveCount( 0 );
	const caja = await page.locator( SUGERENCIA ).nth( 1 ).boundingBox();
	expect( caja ).not.toBeNull();
	return { x: caja.x + caja.width / 2, y: caja.y + caja.height / 2, top: caja.y };
}

test.describe( 'bloques: sugerencias de dirección al dejar de escribir', () => {
	test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
	test.use( { contextOptions: { reducedMotion: 'reduce' } } );
	// Cada prueba recarga el checkout varias veces (la del ratón, hasta 8): con
	// la suite en paralelo una pasó del minuto sin defecto (medido 2026-09-24).
	test.slow();

	test( 'buscar con la lista abierta no la mueve, y el toque tras la pausa elige la sugerencia tocada', async ( { page, context } ) => {
		const campo = await checkoutConDoble( page, context, 300 );
		const segunda = await abrirLista( page, campo );

		// Cuadro a cuadro, DESPUÉS de pintado (rAF → mensaje): dónde está la 2.ª
		// sugerencia y qué hace el aviso mientras busca.
		await page.evaluate( () => {
			const traza = [];
			window.akbE2eTraza = traza;
			const t0 = performance.now();
			const canal = new MessageChannel();
			const tras = () => canal.port2.postMessage( 0 );
			canal.port1.onmessage = () => {
				const li = document.querySelectorAll( '.wc-block-components-address-autocomplete-suggestion' )[ 1 ];
				const aviso = document.querySelector( '.akb-address-autocomplete-feedback' );
				const caja = aviso?.getBoundingClientRect();
				traza.push( {
					t: Math.round( performance.now() - t0 ),
					top: li ? Math.round( li.getBoundingClientRect().top * 10 ) / 10 : null,
					aviso: aviso && ! aviso.hidden ? aviso.textContent : '',
					avisoAlto: caja ? caja.height : 0,
					giraLaLupa: Boolean( document.querySelector( '.akb-address-autocomplete-busy .wc-block-components-address-autocomplete-icon' ) ),
				} );
				if ( performance.now() - t0 < 1_200 ) {
					requestAnimationFrame( tras );
				}
			};
			requestAnimationFrame( tras );
		} );
		await page.keyboard.type( 'v' );
		await expect.poll( () => page.evaluate( () => window.akbE2eDoble.respuestas.some( ( r ) => 'Avenida Prov' === r.texto ) ) ).toBe( true );
		await page.waitForTimeout( 300 );
		const traza = await page.evaluate( () => window.akbE2eTraza );
		const resumen = JSON.stringify( traza.filter( ( f, i ) => 0 === i || f.top !== traza[ i - 1 ].top || f.aviso !== traza[ i - 1 ].aviso ) );
		const buscando = traza.filter( ( f ) => /Buscando direcciones/.test( f.aviso ) );
		expect( buscando.length, `la prueba pasó por la búsqueda con la lista abierta: ${ resumen }` ).toBeGreaterThan( 0 );
		expect( buscando.every( ( f ) => f.avisoAlto <= 1 && f.giraLaLupa ), `el aviso se anuncia sin ocupar sitio y la lupa gira: ${ resumen }` ).toBe( true );
		expect( traza.every( ( f ) => Math.abs( f.top - segunda.top ) <= 0.5 ), `la 2.ª sugerencia no se mueve: ${ resumen }` ).toBe( true );

		// El toque 300 ms después de la tecla: la búsqueda sale a los 250 ms y el
		// doble contesta a los 550. Antes caía sobre la lista bajada 43 px.
		await page.keyboard.type( 'i' );
		await page.waitForTimeout( 300 );
		if ( test.info().project.use.hasTouch ) {
			await page.touchscreen.tap( segunda.x, segunda.y );
		} else {
			await page.mouse.click( segunda.x, segunda.y );
		}
		// Se completa la que estaba bajo el dedo: la de «Avenida Prov» (o, si la
		// máquina retrasó el toque más allá de la respuesta, la de «Avenida Provi»).
		const tocada = await page.evaluate( () => window.akbE2eMarcas.bajo.at( -1 ) );
		const texto = [ 'Avenida Prov', 'Avenida Provi' ].find( ( t ) => sugerenciasPara( t )[ 1 ] === tocada );
		expect( texto, `bajo el dedo: ${ tocada }` ).toBeTruthy();
		await expect( campo ).toHaveValue( tocada );
		expect( await page.evaluate( () => window.akbE2eDoble.elegidas ) ).toEqual( [ `${ texto }#1` ] );
	} );

	test( 'Escape cierra la lista y no vuelve a abrirse, ni con la búsqueda pendiente ni con la que ya está en Google', async ( { page, context } ) => {
		const campo = await checkoutConDoble( page, context, 600 );
		const lista = page.locator( SUGERENCIA );

		// 1) Escape antes de los 250 ms: la búsqueda ni sale.
		await abrirLista( page, campo );
		await page.keyboard.type( 'v' );
		await page.keyboard.press( 'Escape' );
		await expect( lista ).toHaveCount( 0 );
		await page.waitForTimeout( 1_000 );
		await expect( lista ).toHaveCount( 0 );
		await expect( campo ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( await page.evaluate( () => window.akbE2eDoble.pedidas.map( ( p ) => p.texto ) ), 'la tecla con Escape no llega a Google' ).not.toContain( 'Avenida Prov' );

		// 2) Escape con la búsqueda ya en Google (el doble tarda 600 ms).
		await page.keyboard.type( 'i' );
		await expect( lista ).toHaveCount( 4 );
		await expect( campo ).not.toHaveAttribute( 'aria-busy', 'true' );
		await page.keyboard.type( 'd' );
		await expect.poll( () => page.evaluate( () => window.akbE2eDoble.pedidas.some( ( p ) => 'Avenida Provid' === p.texto ) ) ).toBe( true );
		await page.keyboard.press( 'Escape' );
		await expect( lista ).toHaveCount( 0 );
		await expect.poll( () => page.evaluate( () => window.akbE2eDoble.respuestas.some( ( r ) => 'Avenida Provid' === r.texto ) ) ).toBe( true );
		const { escape, respuesta } = await page.evaluate( () => ( {
			escape: window.akbE2eMarcas.escape.at( -1 ),
			respuesta: window.akbE2eDoble.respuestas.find( ( r ) => 'Avenida Provid' === r.texto ).t,
		} ) );
		expect( respuesta, 'Google contestó DESPUÉS del Escape' ).toBeGreaterThan( escape );
		await page.waitForTimeout( 500 );
		await expect( lista ).toHaveCount( 0 );
		await expect( campo ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( campo ).not.toHaveAttribute( 'aria-busy', 'true' );
		await expect( page.locator( '.akb-address-autocomplete-feedback:not([hidden])' ) ).toHaveCount( 0 );
		await expect( campo ).toHaveValue( 'Avenida Provid' );
	} );

	test( 'un clic de ratón que dura lo que tarda la búsqueda en vencer completa la sugerencia pulsada', async ( { page, context } ) => {
		test.skip( Boolean( test.info().project.use.hasTouch ), 'con el dedo mousedown, blur y click llegan juntos al levantarlo' );
		const campo = await checkoutConDoble( page, context, 150 );

		// Dos momentos de riesgo por pausa: que VENZA la espera de 250 ms durante
		// la pulsación (1) o que CONTESTE Google durante la pulsación (2). Los dos
		// se fijan en la página, 20 ms después del mousedown (espera retenida o
		// respuesta retenida del doble). La pulsación tiene que durar menos de
		// 200 ms: más y Woo cierra la lista solo, por su propio blur (eso no es
		// del proveedor); si la máquina la estiró, se repite.
		for ( const caso of [ 'vence la espera', 'contesta Google' ] ) {
			let medido = null;
			const intentos = [];
			for ( let intento = 0; intento < 4 && ! medido; intento++ ) {
				await page.reload( { waitUntil: 'domcontentloaded' } );
				await expect( campo ).toBeVisible( { timeout: 20_000 } );
				await campo.fill( '' );
				const segunda = await abrirLista( page, campo );
				await page.mouse.move( segunda.x, segunda.y - 200 );
				if ( 'contesta Google' === caso ) {
					await page.evaluate( () => {
						window.akbE2eDoble.retener = 'Avenida Prov';
					} );
					await page.keyboard.type( 'v' );
					await expect.poll( () => page.evaluate( () => window.akbE2eDoble.pedidas.some( ( p ) => 'Avenida Prov' === p.texto ) ) ).toBe( true );
				} else {
					await page.evaluate( () => {
						window.akbE2eMarcas.retenerEspera = true;
					} );
					await page.keyboard.type( 'v' );
					await expect.poll( () => page.evaluate( () => window.akbE2eMarcas.retenidas() ), 'la tecla dejó su espera de 250 ms' ).toBeGreaterThanOrEqual( 1 );
				}
				await page.mouse.move( segunda.x, segunda.y );
				await page.mouse.down();
				await page.waitForTimeout( 60 );
				await page.mouse.up();
				const m = await page.evaluate( () => {
					const marcas = window.akbE2eMarcas;
					const tecla = marcas.teclas.at( -1 ).t;
					return {
						tecla,
						abajo: marcas.abajo.at( -1 ),
						arriba: marcas.arriba.at( -1 ),
						espera: marcas.esperas.find( ( e ) => e.creada >= tecla )?.corre ?? null,
						respuesta: window.akbE2eDoble.respuestas.find( ( r ) => 'Avenida Prov' === r.texto )?.t ?? null,
					};
				} );
				const riesgo = 'vence la espera' === caso ? m.espera : m.respuesta;
				const rel = ( t ) => ( null === t ? null : Math.round( t - m.tecla ) );
				intentos.push( { riesgo: rel( riesgo ), abajo: rel( m.abajo ), arriba: rel( m.arriba ) } );
				if ( null !== riesgo && riesgo > m.abajo && riesgo < m.arriba && m.arriba - m.abajo < 190 ) {
					medido = m;
				}
			}
			const detalle = `ms desde la tecla: ${ JSON.stringify( intentos ) }`;
			expect( medido, `«${ caso }» cayó dentro de una pulsación de menos de 190 ms (4 intentos); ${ detalle }` ).not.toBeNull();
			expect( await page.evaluate( () => window.akbE2eMarcas.bajo.at( -1 ) ), `${ caso }: bajo el puntero` ).toBe( CALLES[ 1 ] );
			await expect( campo, `${ caso }; ${ detalle }` ).toHaveValue( CALLES[ 1 ] );
			expect( await page.evaluate( () => window.akbE2eDoble.elegidas ), caso ).toEqual( [ 'Avenida Pro#1' ] );
		}
	} );
	test( 'con el dedo: si Google contesta durante el toque, la lista no cambia bajo el dedo y se completa la tocada', async ( { page, context } ) => {
		test.skip( ! test.info().project.use.hasTouch, 'toque real (CDP): sólo en el proyecto táctil' );
		const campo = await checkoutConDoble( page, context, 200 );
		const segunda = await abrirLista( page, campo );
		const cdp = await context.newCDPSession( page );

		// La respuesta de «Avenida Prov» sale 20 ms después del touchstart: cae
		// DENTRO de un toque de 100 ms, lo que la revisión midió con Google a
		// 200 ms y un toque a los 380 ms de la tecla.
		await page.evaluate( () => {
			window.akbE2eDoble.retener = 'Avenida Prov';
		} );
		await page.keyboard.type( 'v' );
		await expect.poll( () => page.evaluate( () => window.akbE2eDoble.pedidas.some( ( p ) => 'Avenida Prov' === p.texto ) ) ).toBe( true );
		await cdp.send( 'Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [ { x: segunda.x, y: segunda.y } ] } );
		await page.waitForTimeout( 100 );
		await cdp.send( 'Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] } );

		await expect.poll( () => page.evaluate( () => window.akbE2eDoble.elegidas.length ) ).toBe( 1 );
		const m = await page.evaluate( () => ( {
			toque: window.akbE2eMarcas.toques.at( -1 ),
			suelta: window.akbE2eMarcas.sueltas.at( -1 ),
			bajo: window.akbE2eMarcas.bajo.at( -1 ),
			respuesta: window.akbE2eDoble.respuestas.find( ( r ) => 'Avenida Prov' === r.texto )?.t ?? null,
		} ) );
		expect( m.bajo, 'bajo el dedo estaba la 2.ª de «Avenida Pro»' ).toBe( CALLES[ 1 ] );
		await expect( campo, 'se completa la que el cliente tocó' ).toHaveValue( CALLES[ 1 ] );
		expect( await page.evaluate( () => window.akbE2eDoble.elegidas ) ).toEqual( [ 'Avenida Pro#1' ] );
		expect( m.respuesta, `Google contestó con el dedo abajo: ${ JSON.stringify( m ) }` ).toBeGreaterThan( m.toque );
		expect( m.respuesta, `Google contestó con el dedo abajo: ${ JSON.stringify( m ) }` ).toBeLessThan( m.suelta );
	} );

	// Ronda 3: la ronda 2 retenía la respuesta mientras el cliente usaba las
	// flechas, y con una flecha justo después de teclear la lista se quedaba
	// para siempre en la de una búsqueda anterior (Enter completaba una calle de
	// esa lista). Ahora la respuesta llega siempre y, antes, el proveedor suelta
	// el resaltado de Woo: la lista de lo escrito abre sin nada resaltado.
	test( 'con el teclado: si Google contesta con una sugerencia resaltada, la lista nueva abre sin resaltado y Enter no completa otra dirección', async ( { page, context } ) => {
		test.skip( Boolean( test.info().project.use.hasTouch ), 'las flechas son del teclado de escritorio' );
		const campo = await checkoutConDoble( page, context, 200 );
		await abrirLista( page, campo );
		const resaltada = () => page.evaluate( () => {
			const id = document.getElementById( 'billing-address_1' ).getAttribute( 'aria-activedescendant' );
			return id ? document.getElementById( id )?.textContent ?? null : null;
		} );

		await page.evaluate( () => {
			window.akbE2eDoble.retener = 'Avenida Prov';
		} );
		await page.keyboard.type( 'v' );
		await expect.poll( () => page.evaluate( () => window.akbE2eDoble.pedidas.some( ( p ) => 'Avenida Prov' === p.texto ) ) ).toBe( true );
		await page.keyboard.press( 'ArrowDown' );
		await page.keyboard.press( 'ArrowDown' );
		await expect.poll( resaltada ).toBe( CALLES[ 1 ] );

		// Google contesta con la 2.ª resaltada. Antes de la ronda 2, Woo ponía la
		// lista nueva debajo con el MISMO id resaltado y Enter completaba
		// «Avenida Prov 2000»; en la ronda 2 la lista vieja quedaba congelada.
		await page.evaluate( () => window.akbE2eDoble.soltar() );
		await expect( page.locator( SUGERENCIA ), 'la lista es la de lo escrito' ).toHaveText( sugerenciasPara( 'Avenida Prov' ) );
		await expect( campo, 'sin resaltado heredado' ).not.toHaveAttribute( 'aria-activedescendant' );
		await expect( page.locator( `${ SUGERENCIA }.active` ) ).toHaveCount( 0 );
		await expect( campo ).not.toHaveAttribute( 'aria-busy', 'true' );
		await expect( page.locator( '.akb-address-autocomplete-busy' ) ).toHaveCount( 0 );

		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 300 );
		await expect( campo, 'Enter sin nada resaltado no completa nada' ).toHaveValue( 'Avenida Prov' );
		await expect( page.locator( SUGERENCIA ) ).toHaveCount( 4 );
		await page.keyboard.press( 'ArrowDown' );
		await page.keyboard.press( 'ArrowDown' );
		await page.keyboard.press( 'Enter' );
		await expect( campo ).toHaveValue( 'Avenida Prov 2000' );
		expect( await page.evaluate( () => window.akbE2eDoble.elegidas ) ).toEqual( [ 'Avenida Prov#1' ] );
	} );

	test( 'con el teclado: una flecha justo después de teclear no congela la lista vieja; llega la de lo escrito, sin nada resaltado', async ( { page, context } ) => {
		test.skip( Boolean( test.info().project.use.hasTouch ), 'las flechas son del teclado de escritorio' );
		const campo = await checkoutConDoble( page, context, 200 );
		await abrirLista( page, campo );
		const escrito = 'Avenida Providencia 2';

		// La espera de 250 ms de la última tecla queda retenida: la flecha llega
		// seguro ANTES de que venza (la revisión la pulsó a 120 ms de la tecla).
		await page.evaluate( () => {
			window.akbE2eMarcas.retenerEspera = true;
		} );
		await page.keyboard.type( 'videncia 2', { delay: 60 } );
		await expect.poll( () => page.evaluate( () => window.akbE2eMarcas.retenidas() ) ).toBe( 1 );
		await page.keyboard.press( 'ArrowDown' );
		await expect( campo ).toHaveAttribute( 'aria-activedescendant', 'suggestion-item-billing-0' );
		await expect( page.locator( SUGERENCIA ).first(), 'resalta en la lista vieja' ).toHaveText( CALLES[ 0 ] );
		await page.evaluate( () => window.akbE2eMarcas.soltarEsperas() );

		await expect( page.locator( SUGERENCIA ), 'la lista pasa a la de lo escrito' ).toHaveText( sugerenciasPara( escrito ) );
		expect( await page.evaluate( () => window.akbE2eDoble.pedidas.map( ( p ) => p.texto ) ), 'lo escrito llega a Google' ).toContain( escrito );
		await expect( campo, 'sin resaltado heredado' ).not.toHaveAttribute( 'aria-activedescendant' );
		await expect( page.locator( `${ SUGERENCIA }.active` ) ).toHaveCount( 0 );
		await expect( campo ).not.toHaveAttribute( 'aria-busy', 'true' );

		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 300 );
		await expect( campo, 'Enter sin nada resaltado no completa una calle de la lista vieja' ).toHaveValue( escrito );
		expect( await page.evaluate( () => window.akbE2eDoble.elegidas ) ).toEqual( [] );
		await page.keyboard.press( 'ArrowDown' );
		await page.keyboard.press( 'Enter' );
		await expect( campo ).toHaveValue( `${ escrito } 1000` );
		expect( await page.evaluate( () => window.akbE2eDoble.elegidas ) ).toEqual( [ `${ escrito }#0` ] );
	} );

	// RV-FB-01: con menos de tres caracteres Woo cierra la lista sin soltar el
	// resaltado. Chromium lo soltaba por el blur que dispara Woo al reinsertar el
	// campo; WebKit no, y la lista de la calle siguiente abría con esa posición
	// resaltada: Enter la completaba.
	test( 'con el teclado: borrar por debajo de tres letras con una sugerencia resaltada no deja resaltada la lista de la calle siguiente', async ( { page, context } ) => {
		test.skip( Boolean( test.info().project.use.hasTouch ), 'las flechas son del teclado de escritorio' );
		const campo = await checkoutConDoble( page, context, 200 );
		await abrirLista( page, campo );
		await page.keyboard.press( 'ArrowDown' );
		await page.keyboard.press( 'ArrowDown' );
		await expect( campo ).toHaveAttribute( 'aria-activedescendant', 'suggestion-item-billing-1' );

		await page.keyboard.press( 'ControlOrMeta+A' );
		await page.keyboard.press( 'Backspace' );
		await expect( campo ).toHaveValue( '' );
		await expect( page.locator( SUGERENCIA ) ).toHaveCount( 0 );
		await expect( campo, 'el resaltado no sobrevive a la lista' ).not.toHaveAttribute( 'aria-activedescendant' );

		const calle = 'Los Leones 12';
		await page.keyboard.type( calle, { delay: 60 } );
		await expect( page.locator( SUGERENCIA ) ).toHaveText( sugerenciasPara( calle ) );
		await expect( campo ).not.toHaveAttribute( 'aria-busy', 'true' );
		await expect( campo, 'la lista nueva abre sin resaltado' ).not.toHaveAttribute( 'aria-activedescendant' );
		await expect( page.locator( `${ SUGERENCIA }.active` ) ).toHaveCount( 0 );

		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 300 );
		await expect( campo, 'Enter sin nada resaltado no completa nada' ).toHaveValue( calle );
		expect( await page.evaluate( () => window.akbE2eDoble.elegidas ) ).toEqual( [] );
	} );
} );
