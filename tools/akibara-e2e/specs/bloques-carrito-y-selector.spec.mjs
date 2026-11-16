// @ts-check
/**
 * akibara-cart-checkout-blocks en un navegador de verdad: lo que la suite de
 * node no puede ver porque no pinta.
 *
 * - El botón de quitar de una línea con «Ahorra $X» (carrito móvil): la caja
 *   invisible del precio lo tapaba y el toque en su centro no hacía nada.
 * - El total de la línea en el carrito angosto: a 320 y 360 px se encimaba sobre
 *   el nombre del producto y le quitaba toques a su enlace. Su arreglo no puede
 *   hacer saltar la línea al cargar (CLS) ni sacar el botón de quitar de ella.
 * - El buscador de direcciones del checkout: una petición a Google por tecla era
 *   el peor INP real del sitio (#billing-address_1, 1.120 ms en el RUM).
 * - El selector de punto de retiro (PUDO): su capa sale de la escala del tema y
 *   nada de lo fijo de la página —ni los avisos de Woo ni la barra de
 *   administración de WordPress— le puede quedar encima.
 *
 * Todo escribe en el carrito: contra producción se salta entero.
 */

import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';

/**
 * El producto en oferta con el nombre más largo: con uno corto el nombre no
 * llega hasta el total de la línea y la prueba del carrito angosto pasaría con
 * el defecto puesto.
 *
 * @param {import('@playwright/test').APIRequestContext} request Cliente HTTP del contexto.
 * @returns {Promise<number|undefined>} ID.
 */
async function enOfertaConNombreLargo( request ) {
	const r = await request.get( '/wp-json/wc/store/v1/products?per_page=40&on_sale=true' );
	if ( ! r.ok() ) {
		return undefined;
	}
	const productos = ( await r.json() )
		.filter( ( p ) => p.is_purchasable && p.is_in_stock && 'simple' === p.type && p.on_sale )
		.sort( ( a, b ) => b.name.length - a.name.length );
	return productos[ 0 ]?.id;
}

/**
 * Instala `window.akbE2eQueSePinta( fn )`: corre `fn` preguntando qué se PINTA
 * en cada punto, no qué recibiría el puntero.
 *
 * `elementFromPoint` salta los nodos inertes y los `pointer-events: none`. Con
 * el selector abierto todo lo de detrás es inerte, así que la pregunta siempre
 * contestaba «el selector», estuviera lo que estuviera pintado encima (con el
 * selector en `z-index: 1` la prueba seguía en verde; medido 2026-09-23).
 * Mientras mide quita los dos filtros, y después deja todo como estaba. Cuenta
 * toda caja apilada encima, pinte o no: la lista de avisos de Woo vacía también
 * cuenta, porque es donde se pinta el primer aviso que llegue.
 *
 * @param {import('@playwright/test').Page} page Página.
 */
async function instalarQueSePinta( page ) {
	await page.evaluate( () => {
		window.akbE2eQueSePinta = ( fn ) => {
			const inertes = [ ...document.querySelectorAll( '[inert]' ) ];
			inertes.forEach( ( el ) => {
				el.inert = false;
			} );
			const estilo = document.createElement( 'style' );
			estilo.textContent = '*, *::before, *::after { pointer-events: auto !important; }';
			document.head.append( estilo );
			try {
				return fn();
			} finally {
				estilo.remove();
				inertes.forEach( ( el ) => {
					el.inert = true;
				} );
			}
		};
	} );
}

/**
 * Productos comprables del catálogo, en oferta o no, leídos de la Store API.
 *
 * @param {import('@playwright/test').APIRequestContext} request Cliente HTTP del contexto.
 * @param {boolean} enOferta Sólo los que llevan precio rebajado.
 * @returns {Promise<number[]>} IDs.
 */
async function comprables( request, enOferta ) {
	const r = await request.get( `/wp-json/wc/store/v1/products?per_page=40${ enOferta ? '&on_sale=true' : '' }` );
	if ( ! r.ok() ) {
		return [];
	}
	const productos = await r.json();
	return productos
		.filter( ( p ) => p.is_purchasable && p.is_in_stock && 'simple' === p.type )
		.filter( ( p ) => enOferta === Boolean( p.on_sale ) )
		.map( ( p ) => p.id );
}

/**
 * Agrega al carrito por la misma puerta que el botón de la tienda.
 *
 * @param {import('@playwright/test').Page} page Página.
 * @param {number} id Producto.
 */
async function agregar( page, id ) {
	const r = await page.request.post( '/?wc-ajax=add_to_cart', { form: { product_id: String( id ), quantity: '1' } } );
	expect( r.ok(), `agregar ${ id }` ).toBeTruthy();
}

test.describe( 'bloques: carrito, dirección y punto de retiro', () => {
	test.describe.configure( { mode: 'serial' } );

	// Con movimiento reducido (ajuste de accesibilidad del teléfono) el tema quita
	// el `will-change` de los botones; sin él, el botón deja de ser capa propia y
	// el envoltorio invisible del precio le queda ENCIMA (0 de 5 puntos, medido
	// 2026-09-23). Sin `reduce` la prueba pasaba igual con el defecto: no probaba nada.
	test.use( { contextOptions: { reducedMotion: 'reduce' } } );

	test( 'el botón de quitar de una línea con «Ahorra» responde en todo su centro', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		const [ oferta ] = await comprables( page.request, true );
		const [ normal ] = await comprables( page.request, false );
		test.skip( ! oferta, 'sin productos en oferta en este entorno' );

		await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
		await agregar( page, oferta );
		if ( normal ) {
			await agregar( page, normal );
		}
		const respuesta = await page.goto( '/carrito/', { waitUntil: 'domcontentloaded' } );
		expect( respuesta?.status() ).toBe( 200 );

		const fila = page.locator( '.wc-block-cart-items__row:has(.wc-block-components-sale-badge)' ).first();
		await expect( fila ).toBeVisible( { timeout: 20_000 } );
		// Woo mide el contenedor DESPUÉS de montar y recién ahí pasa a la rejilla
		// angosta (is-mobile/is-small/is-medium), que es donde estaba el defecto:
		// medir antes es medir la tabla de escritorio y pasar sin probar nada.
		await expect( page.locator( '.wc-block-cart' ) ).toHaveClass( /\bis-(mobile|small|medium|large)\b/ );
		const boton = fila.locator( '.wc-block-cart-item__remove-link' );
		await boton.scrollIntoViewIfNeeded();

		// Centro y cuatro puntos interiores: todos tienen que caer en el botón.
		const golpes = await boton.evaluate( ( b ) => {
			const r = b.getBoundingClientRect();
			return [ [ 0.5, 0.5 ], [ 0.2, 0.5 ], [ 0.8, 0.5 ], [ 0.5, 0.2 ], [ 0.5, 0.8 ] ].map( ( [ fx, fy ] ) => {
				const el = document.elementFromPoint( r.left + ( r.width * fx ), r.top + ( r.height * fy ) );
				return el === b || b.contains( el ) ? 'botón' : String( el?.className || el?.nodeName );
			} );
		} );
		expect( golpes, 'lo que recibe el toque en cada punto del botón' ).toEqual( [ 'botón', 'botón', 'botón', 'botón', 'botón' ] );

		const filas = page.locator( '.wc-block-cart-items__row' );
		const antes = await filas.count();
		const caja = await boton.boundingBox();
		if ( ! caja ) {
			throw new Error( 'el botón no tiene caja' );
		}
		if ( test.info().project.use.hasTouch ) {
			await page.touchscreen.tap( caja.x + ( caja.width / 2 ), caja.y + ( caja.height / 2 ) );
		} else {
			await page.mouse.click( caja.x + ( caja.width / 2 ), caja.y + ( caja.height / 2 ) );
		}
		await expect( filas, 'un toque en el centro quita la línea' ).toHaveCount( antes - 1, { timeout: 15_000 } );
	} );

	test( 'en el carrito angosto el total de la línea no pisa el nombre ni el botón de quitar, y la línea no se sale (306 a 390 px)', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		test.skip( 'movil' !== test.info().project.name, 'fija su propio ancho de pantalla: con una vez basta' );
		await context.clearCookies();
		const oferta = await enOfertaConNombreLargo( page.request );
		const [ normal ] = await comprables( page.request, false );
		test.skip( ! oferta, 'sin productos en oferta en este entorno' );

		await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
		await agregar( page, oferta );
		if ( normal ) {
			await agregar( page, normal );
		}
		const respuesta = await page.goto( '/carrito/', { waitUntil: 'domcontentloaded' } );
		expect( respuesta?.status() ).toBe( 200 );
		await expect( page.locator( '.wc-block-cart-items__row:has(.wc-block-components-sale-badge)' ).first() ).toBeVisible( { timeout: 20_000 } );

		// Woo arma la línea angosta como rejilla y deja el total en una columna
		// implícita que se queda con lo que sobra: 0 px a 320, 20 a 360. El total
		// (precio + «Ahorra $X», ~84 px) se desbordaba sobre el nombre.
		//
		// Y bajo 342 px la fila de la cantidad (selector de 130 + 12 de aire + 44
		// del botón de quitar) no cabe en la columna del producto (164 px a 320):
		// el botón se salía 22 px de la línea. 306 es el ancho que le queda a una
		// pantalla de 320 cuando la barra de desplazamiento ocupa sitio (WebKit de
		// escritorio: ahí la página se corría 6 px de lado; medido 2026-09-24).
		for ( const ancho of [ 306, 320, 340, 360, 390 ] ) {
			await page.setViewportSize( { width: ancho, height: 844 } );
			// Woo vuelve a medir tras cada cambio de tamaño y re-renderiza: se mide
			// cuando su clase ya dice «teléfono».
			await expect( page.locator( '.wc-block-cart' ) ).toHaveClass( /\bis-mobile\b/ );
			await page.evaluate( () => new Promise( ( listo ) => requestAnimationFrame( () => requestAnimationFrame( listo ) ) ) );

			const lineas = await page.locator( '.wc-block-cart-items__row' ).evaluateAll( ( filas ) => filas.map( ( fila ) => {
				// Al centro: la cabecera y la barra de «Finalizar compra» son fijas.
				fila.scrollIntoView( { block: 'center', behavior: 'instant' } );
				const nombre = fila.querySelector( '.wc-block-components-product-name' );
				const total = fila.querySelector( '.wc-block-cart-item__total-price-and-sale-badge-wrapper' );
				const quitar = fila.querySelector( '.wc-block-cart-item__remove-link' );
				const cruzan = ( a, b ) => Math.min( a.right, b.right ) - Math.max( a.left, b.left ) > 0.5
					&& Math.min( a.bottom, b.bottom ) - Math.max( a.top, b.top ) > 0.5;
				const recibe = ( el, x, y ) => {
					const tocado = document.elementFromPoint( x, y );
					return tocado === el || el.contains( tocado );
				};
				// Los renglones del texto del nombre, no la caja del enlace (que llega
				// hasta el borde aunque el texto no).
				const rango = document.createRange();
				rango.selectNodeContents( nombre );
				const renglones = [ ...rango.getClientRects() ].filter( ( r ) => r.width > 0 );
				const rTotal = total.getBoundingClientRect();
				const rQuitar = quitar.getBoundingClientRect();
				const rFila = fila.getBoundingClientRect();
				const rSelector = fila.querySelector( '.wc-block-components-quantity-selector' ).getBoundingClientRect();
				const dentro = ( r ) => r.left >= rFila.left - 1 && r.right <= rFila.right + 1;
				const toquesNombre = renglones.flatMap( ( r ) => [ 0.1, 0.5, 0.9 ].map( ( f ) => recibe( nombre, r.left + ( r.width * f ), r.top + ( r.height / 2 ) ) ) );
				const toquesQuitar = [ [ 0.5, 0.5 ], [ 0.2, 0.5 ], [ 0.8, 0.5 ], [ 0.5, 0.2 ], [ 0.5, 0.8 ] ]
					.map( ( [ fx, fy ] ) => recibe( quitar, rQuitar.left + ( rQuitar.width * fx ), rQuitar.top + ( rQuitar.height * fy ) ) );
				return {
					nombre: nombre.textContent.trim(),
					textoBajoElTotal: renglones.some( ( r ) => cruzan( r, rTotal ) ),
					toquesDelNombreQueSePierden: toquesNombre.filter( ( ok ) => ! ok ).length,
					toquesDeQuitarQueSePierden: toquesQuitar.filter( ( ok ) => ! ok ).length,
					// Que no se «arregle» escondiendo el total.
					totalALaVista: rTotal.width > 0 && rTotal.height > 0 && dentro( rTotal ),
					// El selector y el botón de quitar, dentro de su línea (y sin encimarse).
					selectorDentroDeLaLinea: dentro( rSelector ),
					quitarDentroDeLaLinea: dentro( rQuitar ),
					quitarPisaElSelector: cruzan( rQuitar, rSelector ),
				};
			} ) );

			expect( lineas.length ).toBeGreaterThan( 0 );
			for ( const linea of lineas ) {
				expect( linea, `${ ancho } px · «${ linea.nombre }»` ).toEqual( {
					nombre: linea.nombre,
					textoBajoElTotal: false,
					toquesDelNombreQueSePierden: 0,
					toquesDeQuitarQueSePierden: 0,
					totalALaVista: true,
					selectorDentroDeLaLinea: true,
					quitarDentroDeLaLinea: true,
					quitarPisaElSelector: false,
				} );
			}
			const desborde = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
			expect( desborde, `${ ancho } px: la página no se corre de lado` ).toBeLessThanOrEqual( 0 );
		}
	} );

	test( 'el carrito del teléfono no salta al cargar: la línea nace con su forma final (CLS ≤ 0,01 a 390 px, dos líneas)', async ( { page, context, browserName } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		test.skip( 'movil' !== test.info().project.name, 'el defecto es del ancho de teléfono' );
		test.skip( 'chromium' !== browserName, 'layout-shift sólo existe en Chromium' );
		await context.clearCookies();
		const oferta = await enOfertaConNombreLargo( page.request );
		const normales = await comprables( page.request, false );
		const lineas = [ oferta, ...normales ].filter( Boolean ).slice( 0, 2 );
		test.skip( lineas.length < 2, 'hacen falta dos productos comprables en este entorno' );

		// La rejilla del teléfono colgaba de la clase `is-mobile`, que el JS de Woo
		// pone un cuadro DESPUÉS de pintar las líneas: el primer cuadro salía con la
		// rejilla de Woo (total arriba, en una columna de 35 px) y el siguiente
		// bajaba el total a su fila y empujaba todo lo de abajo. CLS 0,146 a 390 px
		// con cuatro líneas y 0,141 a 360 con dos, en cada carga (medido
		// 2026-09-24); con la hoja de antes del lote, 0,0004. La prueba de al lado
		// no lo veía porque espera a `is-mobile` antes de medir, y el salto ocurre
		// justo en esa espera: aquí el observador se instala antes de cargar.
		await page.addInitScript( () => {
			window.akbE2eSaltos = [];
			new PerformanceObserver( ( lista ) => {
				for ( const e of lista.getEntries() ) {
					if ( ! e.hadRecentInput ) {
						window.akbE2eSaltos.push( {
							valor: e.value,
							fuentes: ( e.sources || [] ).map( ( s ) => `${ s.node?.nodeName }.${ String( s.node?.className || '' ).split( ' ' )[ 0 ] } y ${ Math.round( s.previousRect.y ) }→${ Math.round( s.currentRect.y ) }` ),
						} );
					}
				}
			} ).observe( { type: 'layout-shift', buffered: true } );
		} );
		await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
		for ( const id of lineas ) {
			await agregar( page, id );
		}
		const respuesta = await page.goto( '/carrito/', { waitUntil: 'load' } );
		expect( respuesta?.status() ).toBe( 200 );
		await expect( page.locator( '.wc-block-cart-items__row' ) ).toHaveCount( 2, { timeout: 20_000 } );
		await expect( page.locator( '.wc-block-cart' ) ).toHaveClass( /\bis-mobile\b/ );
		// Lo que tarde en llegar después de la clase también cuenta.
		await page.waitForTimeout( 1_500 );

		// El salto sólo se VE si el navegador alcanza a pintar un cuadro entre que
		// React monta las líneas y Woo pone su clase: casi siempre, pero no siempre
		// (de cuatro corridas con el defecto, dos midieron 0,0004). Lo que no falla
		// nunca es la causa: el primer cuadro es la línea SIN la clase de Woo. Se
		// mide la forma de cada línea sin ella y con ella, en el mismo instante:
		// tienen que ser la misma.
		const formas = await page.locator( '.wc-block-cart' ).evaluate( ( carrito ) => {
			const medir = () => [ ...carrito.querySelectorAll( '.wc-block-cart-items__row' ) ].map( ( fila ) => {
				const rFila = fila.getBoundingClientRect();
				const caja = ( selector ) => {
					const r = fila.querySelector( selector ).getBoundingClientRect();
					return [ r.left - rFila.left, r.top - rFila.top, r.width ].map( Math.round );
				};
				return {
					columnas: getComputedStyle( fila ).gridTemplateColumns,
					producto: caja( '.wc-block-cart-item__product' ),
					total: caja( '.wc-block-cart-item__total' ),
					quitar: caja( '.wc-block-cart-item__remove-link' ),
				};
			} );
			const clases = carrito.className.match( /\bis-(mobile|small|medium|large)\b/g ) || [];
			const conLaClase = medir();
			carrito.classList.remove( ...clases );
			const primerCuadro = medir();
			carrito.classList.add( ...clases );
			return { clases, conLaClase, primerCuadro };
		} );
		expect( formas.clases ).toEqual( [ 'is-mobile' ] );
		expect( formas.primerCuadro, 'cada línea antes de que Woo le ponga is-mobile, contra después' ).toEqual( formas.conLaClase );

		const saltos = await page.evaluate( () => window.akbE2eSaltos );
		const cls = saltos.reduce( ( suma, s ) => suma + s.valor, 0 );
		expect( cls, `CLS de /carrito/ al cargar: ${ JSON.stringify( saltos ) }` ).toBeLessThanOrEqual( 0.01 );

		// Que no se «arregle» quitando la forma del teléfono: el total sigue en su
		// propia fila, debajo del producto y alineado a la derecha.
		const forma = await page.locator( '.wc-block-cart-items__row' ).first().evaluate( ( fila ) => {
			const producto = fila.querySelector( '.wc-block-cart-item__product' ).getBoundingClientRect();
			const total = fila.querySelector( '.wc-block-cart-item__total' ).getBoundingClientRect();
			return { totalDebajo: total.top >= producto.bottom - 1, alineadoALaDerecha: Math.abs( total.right - producto.right ) <= 1 };
		} );
		expect( forma ).toEqual( { totalDebajo: true, alineadoALaDerecha: true } );
	} );

	test( 'teclear una dirección de corrido sólo pide a Google tras una pausa, no una vez por tecla, y el SDK llega al enfocar', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		const [ id ] = await comprables( page.request, false );
		test.skip( ! id, 'sin productos comprables en este entorno' );

		// Las búsquedas no llegan a Google: se cortan (no gastan cuota).
		await page.route( /places\.googleapis\.com/, ( route ) => route.abort() );
		// Los tiempos se toman DENTRO de la página, en el mismo reloj: cada tecla
		// (el `input` del campo) y cada petición a Places (el SDK la manda por XHR
		// con el texto buscado en el cuerpo). Así se juzga lo que hizo el proveedor
		// y no lo cargada que estaba la máquina: contar peticiones fallaba sin
		// defecto cuando una tecla tardaba 250 ms en procesarse.
		await page.addInitScript( () => {
			const registro = { teclas: [], busquedas: [] };
			window.akbE2eRegistro = registro;
			document.addEventListener( 'input', ( e ) => {
				if ( e.target && 'billing-address_1' === e.target.id ) {
					registro.teclas.push( { t: performance.now(), valor: e.target.value.trim() } );
				}
			}, true );
			const esPlaces = ( url ) => /places\.googleapis\.com/.test( String( url ) );
			const abrir = XMLHttpRequest.prototype.open;
			XMLHttpRequest.prototype.open = function ( metodo, url ) {
				this.akbE2eUrl = url;
				return abrir.apply( this, arguments );
			};
			const enviar = XMLHttpRequest.prototype.send;
			XMLHttpRequest.prototype.send = function ( cuerpo ) {
				if ( esPlaces( this.akbE2eUrl ) ) {
					registro.busquedas.push( { t: performance.now(), cuerpo: String( cuerpo ?? '' ) } );
				}
				return enviar.apply( this, arguments );
			};
			const traer = window.fetch;
			window.fetch = function ( recurso, opciones ) {
				if ( esPlaces( recurso?.url || recurso ) ) {
					registro.busquedas.push( { t: performance.now(), cuerpo: String( opciones?.body ?? '' ) } );
				}
				return traer.apply( this, arguments );
			};
		} );
		let sdkAntesDeTeclear = false;
		let tecleando = false;
		page.on( 'request', ( r ) => {
			if ( /maps\.googleapis\.com\/maps\/api\/js/.test( r.url() ) && ! tecleando ) {
				sdkAntesDeTeclear = true;
			}
		} );

		await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
		await agregar( page, id );
		await page.goto( '/checkout/', { waitUntil: 'domcontentloaded' } );
		const campo = page.locator( '#billing-address_1' );
		await expect( campo ).toBeVisible( { timeout: 20_000 } );
		test.skip( 'combobox' !== await campo.getAttribute( 'role' ), 'el autocompletado de direcciones está apagado en este entorno' );

		await campo.click();
		await page.waitForTimeout( 600 );
		expect( sdkAntesDeTeclear, 'el SDK de Maps se pide al entrar al campo' ).toBe( true );
		tecleando = true;
		const direccion = 'Avenida Providencia 1234';
		await page.keyboard.type( direccion, { delay: 60 } );
		await page.waitForTimeout( 1_200 );

		const { teclas, busquedas } = await page.evaluate( () => window.akbE2eRegistro );
		// El texto de cada petición: el más largo de lo tecleado que va entre comillas en el cuerpo.
		const tecleado = [ ...new Set( teclas.map( ( t ) => t.valor ) ) ].sort( ( a, b ) => b.length - a.length );
		const peticiones = busquedas.map( ( b ) => ( { t: b.t, texto: tecleado.find( ( v ) => b.cuerpo.includes( JSON.stringify( v ) ) ) } ) );
		const cronologia = JSON.stringify( {
			teclas: teclas.map( ( t ) => `${ Math.round( t.t ) } ${ t.valor }` ),
			peticiones: peticiones.map( ( p ) => `${ Math.round( p.t ) } ${ p.texto }` ),
		} );

		// 24 teclas de corrido: antes eran 22 peticiones. La primera de la página sale
		// en el acto (Google tarda ~2 s en la primera y ~0,2 s en las siguientes) y la
		// última lleva la dirección completa.
		expect( peticiones.length, `al menos la de calentamiento y la final: ${ cronologia }` ).toBeGreaterThanOrEqual( 2 );
		expect( peticiones.every( ( p ) => p.texto && p.texto.length >= 3 && direccion.startsWith( p.texto ) ), `cada petición lleva algo tecleado: ${ cronologia }` ).toBe( true );
		// Cada una de las demás, sólo tras 250 ms sin teclear: sale al menos 250 ms
		// después de la tecla que dejó ese texto, y la tecla siguiente llegó después de
		// esos 250 ms (si llegó antes, esa búsqueda tenía que descartarse). Con la
		// máquina cargada puede haber una pausa de más y con ella una petición de más:
		// es lo que el debounce tiene que hacer, y la prueba lo acepta.
		for ( const peticion of peticiones.slice( 1 ) ) {
			const i = teclas.findLastIndex( ( t ) => t.valor === peticion.texto && t.t <= peticion.t );
			expect( i, `«${ peticion.texto }» salió de algo que se tecleó: ${ cronologia }` ).toBeGreaterThanOrEqual( 0 );
			expect( peticion.t - teclas[ i ].t, `«${ peticion.texto }» esperó la pausa: ${ cronologia }` ).toBeGreaterThanOrEqual( 245 );
			if ( teclas[ i + 1 ] ) {
				expect( teclas[ i + 1 ].t - teclas[ i ].t, `«${ peticion.texto }» salió sin que el cliente dejara de teclear: ${ cronologia }` ).toBeGreaterThanOrEqual( 245 );
			}
		}
		expect( peticiones.at( -1 ).texto, cronologia ).toBe( direccion );
		expect( new Set( peticiones.map( ( p ) => p.texto ) ).size, `nunca dos veces el mismo texto: ${ cronologia }` ).toBe( peticiones.length );
		// Sin Google, el cliente sigue pudiendo escribir la dirección a mano.
		await expect( page.locator( '.akb-address-autocomplete-feedback:not([hidden])' ) ).toContainText( /manualmente/ );
		await expect( campo ).toHaveValue( 'Avenida Providencia 1234' );
	} );

	test( 'el selector de punto de retiro queda por encima de todo lo fijo de la página', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		const [ id ] = await comprables( page.request, false );
		test.skip( ! id, 'sin productos comprables en este entorno' );

		// El espejo no cotiza con Blue Express (credenciales saneadas): se marca en el
		// NAVEGADOR una tarifa `bluex-pudo` elegida en el carrito que lee el bloque,
		// sin tocar la base. El widget de Blue (iframe externo) se sirve con un doble.
		const tarifa = {
			rate_id: 'bluex-pudo:112', name: 'Retiro en Punto Blue Express', description: '', delivery_time: '',
			price: '5600', taxes: '0', instance_id: 112, method_id: 'bluex-pudo', meta_data: [], selected: true,
			currency_code: 'CLP', currency_symbol: '$', currency_minor_unit: 0, currency_decimal_separator: ',',
			currency_thousand_separator: '.', currency_prefix: '$', currency_suffix: '',
		};
		const conPudo = ( carrito ) => {
			for ( const paquete of carrito?.shipping_rates || [] ) {
				paquete.destination = { ...( paquete.destination || {} ), country: 'CL', state: 'CL-RM', city: 'San Miguel' };
				paquete.shipping_rates = ( paquete.shipping_rates || [] ).map( ( r ) => ( { ...r, selected: false } ) ).concat( [ { ...tarifa } ] );
			}
			return carrito;
		};
		await page.route( '**/checkout/', async ( route ) => {
			const r = await route.fetch();
			const html = ( await r.text() ).replace(
				/createPreloadingMiddleware\( JSON\.parse\( decodeURIComponent\( '([^']+)' \) \) \)/,
				( m, codificado ) => {
					const datos = JSON.parse( decodeURIComponent( codificado ) );
					Object.keys( datos ).filter( ( k ) => /\/wc\/store\/v1\/cart/.test( k ) ).forEach( ( k ) => conPudo( datos[ k ].body ) );
					return `createPreloadingMiddleware( JSON.parse( decodeURIComponent( '${ encodeURIComponent( JSON.stringify( datos ) ).replace( /'/g, '%27' ) }' ) ) )`;
				}
			);
			await route.fulfill( { response: r, body: html } );
		} );
		await page.route( /\/wp-json\/wc\/store\/v1\/(cart|batch)/, async ( route ) => {
			const r = await route.fetch();
			let cuerpo = await r.text();
			try {
				const json = JSON.parse( cuerpo );
				( Array.isArray( json.responses ) ? json.responses.map( ( x ) => x.body ) : [ json ] ).forEach( conPudo );
				cuerpo = JSON.stringify( json );
			} catch ( e ) {}
			await route.fulfill( { response: r, body: cuerpo } );
		} );
		await page.route( /widget-pudo\.blue\.cl/, ( route ) => route.fulfill( { status: 200, contentType: 'text/html', body: '<!doctype html><title>doble</title><p>Doble del widget</p>' } ) );

		await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
		await agregar( page, id );
		await page.goto( '/checkout/', { waitUntil: 'domcontentloaded' } );
		const abrir = page.locator( '.akb-pudo-button' ).first();
		await expect( abrir ).toBeVisible( { timeout: 20_000 } );

		// Quien entra como administrador o gestor de la tienda ve la barra de
		// WordPress, con z-index 99999 en la hoja de core. La prueba entra como
		// invitado: se monta la barra con esa hoja, la REAL, antes de abrir.
		await page.addStyleTag( { url: '/wp-includes/css/admin-bar.min.css' } );
		await page.evaluate( () => {
			const barra = document.createElement( 'div' );
			barra.id = 'wpadminbar';
			barra.textContent = 'Barra de administración (doble de la prueba)';
			document.body.append( barra );
		} );

		await abrir.click();
		const modal = page.locator( '.akb-pudo-modal' );
		await expect( modal ).toBeVisible();
		await page.waitForTimeout( 500 );
		await expect( page.locator( '#wpadminbar' ), 'lo de detrás del selector queda inerte' ).toHaveAttribute( 'inert', '' );
		await instalarQueSePinta( page );

		const medida = await modal.evaluate( ( m ) => window.akbE2eQueSePinta( () => {
			const W = window.innerWidth;
			const H = window.innerHeight;
			const fuera = [];
			for ( let i = 0; i <= 8; i++ ) {
				for ( let j = 0; j <= 8; j++ ) {
					const x = Math.min( W - 1, Math.round( ( W - 1 ) * i / 8 ) );
					const y = Math.min( H - 1, Math.round( ( H - 1 ) * j / 8 ) );
					const el = document.elementFromPoint( x, y );
					if ( ! el || ! m.contains( el ) ) {
						fuera.push( `${ x },${ y }: ${ el?.tagName }.${ el?.className }` );
					}
				}
			}
			// Todo lo fijo o pegajoso visible fuera del selector: en su centro manda el selector.
			const encima = [];
			for ( const el of document.querySelectorAll( 'body *' ) ) {
				if ( m.contains( el ) || el.contains( m ) ) {
					continue;
				}
				const s = getComputedStyle( el );
				if ( ! [ 'fixed', 'sticky' ].includes( s.position ) || 'none' === s.display || 'hidden' === s.visibility ) {
					continue;
				}
				const r = el.getBoundingClientRect();
				if ( r.width < 1 || r.height < 1 || r.bottom <= 0 || r.top >= H || r.right <= 0 || r.left >= W ) {
					continue;
				}
				const hit = document.elementFromPoint( Math.min( W - 1, Math.max( 0, r.left + ( r.width / 2 ) ) ), Math.min( H - 1, Math.max( 0, r.top + ( r.height / 2 ) ) ) );
				if ( hit && ! m.contains( hit ) ) {
					encima.push( `${ el.tagName }.${ el.className } z=${ s.zIndex }` );
				}
			}
			const token = getComputedStyle( m ).getPropertyValue( '--wp--custom--z--cart' ).trim();
			return { fuera, encima, z: getComputedStyle( m ).zIndex, token };
		} ) );

		expect( medida.fuera, 'puntos de la pantalla que no caen en el selector' ).toEqual( [] );
		expect( medida.encima, 'elementos fijos que quedan encima del selector' ).toEqual( [] );
		expect( medida.z, 'la capa sale de la escala del tema' ).toBe( medida.token || '10400' );

		// La barra de administración, nombrada: bajo 600 px la hoja de core la deja
		// `absolute` arriba del documento y el recorrido de arriba no la ve si la
		// página bajó hasta el botón. Se sube hasta ella y se pregunta ahí.
		const laBarraSePintaEncima = await page.evaluate( () => window.akbE2eQueSePinta( () => {
			window.scrollTo( { top: 0, behavior: 'instant' } );
			const r = document.getElementById( 'wpadminbar' ).getBoundingClientRect();
			const tocado = document.elementFromPoint( r.left + ( Math.min( r.width, window.innerWidth ) / 2 ), r.top + ( r.height / 2 ) );
			return ! document.querySelector( '.akb-pudo-modal' ).contains( tocado );
		} ) );
		expect( laBarraSePintaEncima, 'la barra de administración queda bajo el selector' ).toBe( false );

		// Un aviso flotante de Woo (su lista va con z-index 100000) tampoco se le pinta
		// encima: detrás del selector todo es inerte y ese aviso no se podría cerrar.
		await page.evaluate( () => window.wp.data.dispatch( 'core/notices' ).createNotice( 'error', 'Aviso de prueba mientras el selector está abierto', { type: 'snackbar', context: 'wc/checkout', id: 'akb-e2e-snackbar' } ) );
		const aviso = page.locator( '.wc-block-components-notice-snackbar-list .wc-block-components-notice-snackbar' ).first();
		await expect( aviso ).toBeVisible();
		const loPintaEncima = await aviso.evaluate( ( n ) => window.akbE2eQueSePinta( () => {
			const r = n.getBoundingClientRect();
			const tocado = document.elementFromPoint( r.left + ( r.width / 2 ), r.top + ( r.height / 2 ) );
			return ! document.querySelector( '.akb-pudo-modal' ).contains( tocado );
		} ) );
		expect( loPintaEncima, 'el aviso flotante queda bajo el selector' ).toBe( false );
	} );
} );
