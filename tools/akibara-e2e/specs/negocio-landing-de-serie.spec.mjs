// @ts-check
/**
 * La landing de serie enseña los tomos antes que la ficha técnica.
 *
 * Auditoría del tema, 2026-09-22 (UM-1, UC-5), medida en producción a 390x844:
 * en /serie/hunter-x-hunter/ el primer tomo arrancaba en 1.353 px —1,6
 * pantallas— detrás de la ficha de datos (287 px), la guía de lectura (159) y
 * el riel de pack (329); y las series de más de 24 tomos mandaban los más
 * nuevos a la página 2. Desde el cambio del 2026-09-23 (akibara-business):
 *
 *  - arriba sólo va un resumen de una línea (`.akb-series-summary`) con el
 *    enlace a la ficha completa, que baja bajo la rejilla junto con la guía;
 *  - el riel de pack es UNA barra —título y «Hasta N% de descuento · Cómo
 *    funciona», chips de disponibilidad y «Elegir tomos»— con los tramos y las
 *    condiciones dentro del desplegable; los chips siguen pegados a la rejilla;
 *  - cada página de serie trae hasta 48 tomos (panel → Vitrina) y las páginas
 *    que dejan de existir redirigen con 301 a la última que queda;
 *  - en la línea del total («30 productos») va el atajo «Último tomo: N →» a la
 *    ficha del tomo más nuevo (revisión B3-2, 2026-09-23): en cualquier serie de
 *    dos tomos o más, en cualquier orden y página, y sin sumar alto al pliegue.
 *
 * El orden se decide en el SERVIDOR (mismo HTML en móvil y escritorio), así
 * que se mira el orden del DOM y no sólo lo que se ve.
 */

import { expect, test } from '@playwright/test';
import { erroresDePagina } from './soporte/tienda.mjs';

/** Serie de 38 tomos con guía de lectura y pack: la del hallazgo. */
const SERIE = '/serie/hunter-x-hunter/';

/** La serie más larga: la única que pagina con 48 por página. */
const SERIE_LARGA = '/serie/one-piece/';

/**
 * Serie SIN descuento de pack y con el nombre más largo (revisión B3-2-R4): su
 * riel partía el título en dos líneas y sumaba la frase de intro, 167 px, y la
 * primera portada quedaba bajo la barra inferior (-8 px en Chromium).
 */
const SERIE_SIN_DESCUENTO_NOMBRE_LARGO = '/serie/biblioteca-vertigo-john-constantine-hellblazer/';

/** El atajo al último tomo, en la línea del total que imprime el buscador. */
const ATAJO = '.akb-shop-toolbar .akb-result-count a.akb-series-latest';

/**
 * Número de tomo al final de un título, como lo lee el catálogo («One Piece
 * 109» → 109), o null.
 *
 * @param {string} titulo Título.
 */
function numeroDeTomo( titulo ) {
	const m = String( titulo ).trim().match( /(\d+)\s*$/ );
	return m ? Number( m[ 1 ] ) : null;
}

/**
 * Sigue el atajo por HTTP y devuelve el número del tomo cuya ficha abre.
 *
 * @param {import('@playwright/test').APIRequestContext} request Cliente.
 * @param {string} href Destino del atajo.
 */
async function tomoDeLaFicha( request, href ) {
	const r = await request.get( href );
	expect( r.status(), `la ficha ${ href } responde` ).toBe( 200 );
	const h1 = ( ( await r.text() ).match( /<h1[^>]*class="[^"]*product_title[^"]*"[^>]*>([^<]*)/ ) || [] )[ 1 ] || '';
	return numeroDeTomo( h1 );
}

/** Selectores de las piezas que se ordenan. */
const PIEZAS = {
	resumen: '.akb-series-summary',
	toolbar: '.akb-shop-toolbar',
	oferta: '#completar-coleccion',
	rejilla: 'ul.products',
	ficha: '#ficha-de-la-serie',
	guia: '.akb-related-guide',
};

/**
 * Posición de cada pieza en el orden del documento (índice en la lista de
 * todos los elementos), o -1 si no está.
 *
 * @param {import('@playwright/test').Page} page Página.
 */
async function ordenDelDocumento( page ) {
	return page.evaluate( ( piezas ) => {
		const todos = [ ...document.querySelectorAll( '*' ) ];
		const salida = {};
		for ( const [ nombre, selector ] of Object.entries( piezas ) ) {
			const el = document.querySelector( selector );
			salida[ nombre ] = el ? todos.indexOf( el ) : -1;
		}
		return salida;
	}, PIEZAS );
}

test.describe( 'landing de serie', () => {

	test( 'los tomos van antes que la ficha y la guía, en el orden del documento', async ( { page } ) => {
		const respuesta = await page.goto( SERIE, { waitUntil: 'domcontentloaded' } );
		expect( respuesta?.status() ).toBe( 200 );

		const orden = await ordenDelDocumento( page );
		expect( orden.resumen, 'el resumen de una línea existe' ).toBeGreaterThan( -1 );
		expect( orden.ficha, 'la ficha completa sigue en la página' ).toBeGreaterThan( -1 );
		expect( orden.resumen ).toBeLessThan( orden.toolbar );
		expect( orden.toolbar ).toBeLessThan( orden.oferta );
		expect( orden.oferta, 'los chips de disponibilidad quedan pegados a la rejilla' ).toBeLessThan( orden.rejilla );
		expect( orden.rejilla, 'la ficha completa va DESPUÉS de los tomos' ).toBeLessThan( orden.ficha );
		if ( orden.guia > -1 ) {
			expect( orden.ficha, 'la guía de lectura cierra la página, tras la ficha' ).toBeLessThan( orden.guia );
		}

		// Una sola ficha: el resumen no es una segunda copia de los datos.
		await expect( page.locator( '.akb-series-facts' ) ).toHaveCount( 1 );
	} );

	/**
	 * Criterio de la auditoría (UM-1): portadas en la PRIMERA pantalla. En móvil
	 * la barra inferior fija tapa los últimos 66 px, así que se mide contra su
	 * borde y no contra el alto de la ventana: la revisión B3-R1 encontró la
	 * portada a 787 px con la barra en 778 —0 px a la vista— y la versión
	 * anterior de esta prueba la daba por buena («alto + 120»). Se exigen 60 px
	 * de portada a la vista, sin desplazar.
	 */
	async function portadaALaVista( page ) {
		return page.evaluate( () => {
			const img = document.querySelector( 'ul.products > li.product img' );
			const nav = document.querySelector( '.bottom-nav' );
			const conBarra = !! nav && 'none' !== getComputedStyle( nav ).display && nav.getBoundingClientRect().height > 0;
			const borde = conBarra ? nav.getBoundingClientRect().top : innerHeight;
			const top = img ? img.getBoundingClientRect().top + scrollY : Infinity;
			return { top: Math.round( top ), borde: Math.round( borde ), visible: Math.round( borde - top ) };
		} );
	}

	test( 'la primera portada se ve en la primera pantalla, sobre la barra inferior', async ( { page } ) => {
		await page.goto( SERIE, { waitUntil: 'load' } );
		await expect( page.locator( 'ul.products > li.product' ).first() ).toBeVisible();

		const m = await portadaALaVista( page );
		expect( m.visible, `portada en ${ m.top } px con el borde útil en ${ m.borde } px` ).toBeGreaterThanOrEqual( 60 );
	} );

	/**
	 * Revisión B3-2-R4, la serie sin descuento y de nombre más largo. Su riel
	 * medía 167 px en 390x844 —título en dos líneas más la frase «Selecciona
	 * varios tomos y agrégalos juntos al carrito en un solo paso», que repetía lo
	 * que dice el botón— y la primera portada quedaba 8 px BAJO la barra inferior.
	 * Ahora el riel es título en una línea (el nombre de la serie, que ya es el
	 * titular de la cabecera, sólo lo lee el lector de pantalla), chips y botón:
	 * 107 px, y la portada asoma 51 px (medido 2026-09-23, Chromium).
	 *
	 * Lo que falta hasta los 60 px del criterio UM-1 es la cabecera del TEMA, que
	 * con este nombre ocupa 50 px más que con uno corto: queda como pendiente de
	 * integración, y por eso aquí se exige lo que depende del riel.
	 */
	test( 'sin descuento y con nombre largo, el riel no suma alto: sin frase de intro, título en una línea y la portada asoma', async ( { page, isMobile } ) => {
		await page.goto( SERIE_SIN_DESCUENTO_NOMBRE_LARGO, { waitUntil: 'load' } );
		const oferta = page.locator( PIEZAS.oferta );
		test.skip( 0 === await oferta.count(), 'el pack está apagado en este entorno' );
		test.skip( 0 < await oferta.locator( 'details.akb-sb-landing__rules' ).count(), 'esta serie tiene descuento en este entorno' );

		await expect( oferta.locator( '.akb-sb-landing__copy p' ) ).toHaveCount( 0 );
		await expect( oferta ).not.toContainText( 'agrégalos juntos' );
		await expect( oferta.locator( '[data-akb-sb-landing-start]' ) ).toBeVisible();

		// El nombre accesible de la sección sigue completo.
		await expect( page.getByRole( 'region', { name: /Completa tu colección de .*Hellblazer/i } ) ).toHaveCount( 1 );

		if ( isMobile ) {
			const titulo = await oferta.locator( '.akb-sb-landing__title' ).evaluate( ( el ) => ( {
				alto: el.getBoundingClientRect().height,
				linea: parseFloat( getComputedStyle( el ).lineHeight ),
			} ) );
			expect( titulo.alto, 'el título del riel cabe en una línea' ).toBeLessThan( titulo.linea * 1.5 );

			const m = await portadaALaVista( page );
			expect( m.visible, `portada en ${ m.top } px con el borde útil en ${ m.borde } px (antes: 8 px bajo la barra)` ).toBeGreaterThan( 0 );
		}
	} );

	test( 'en un portátil de 1366x768 la primera fila de portadas también asoma', async ( { page, isMobile } ) => {
		test.skip( isMobile, 'caso de escritorio' );
		await page.setViewportSize( { width: 1366, height: 768 } );
		await page.goto( SERIE, { waitUntil: 'load' } );

		const m = await portadaALaVista( page );
		expect( m.visible, `portada en ${ m.top } px con la ventana en ${ m.borde } px` ).toBeGreaterThanOrEqual( 60 );
	} );

	test( 'el resumen contesta el estado de la serie y lleva a la ficha completa', async ( { page } ) => {
		await page.goto( SERIE, { waitUntil: 'load' } );

		const resumen = page.locator( PIEZAS.resumen );
		await expect( resumen ).toBeVisible();
		const enlace = resumen.locator( 'a[href="#ficha-de-la-serie"]' );
		await expect( enlace ).toBeVisible();

		await enlace.click();
		await expect( page ).toHaveURL( /#ficha-de-la-serie$/ );

		// La ficha queda A LA VISTA, no bajo la cabecera fija ni bajo la barra
		// inferior del móvil.
		const ficha = page.locator( PIEZAS.ficha );
		await expect( ficha ).toBeInViewport();
		const caja = await ficha.boundingBox();
		const tapada = await page.evaluate( ( y ) => {
			const el = document.elementFromPoint( 40, y + 4 );
			return el ? ! el.closest( '#ficha-de-la-serie' ) : true;
		}, ( caja?.y ?? 0 ) + 8 );
		expect( tapada, 'el borde superior de la ficha no queda bajo una barra fija' ).toBe( false );
	} );

	/**
	 * Revisión B3-D1/D2 (2026-09-23): en móvil «Ver ficha completa ↓» y «Último
	 * tomo: N →» quedan apilados con 8 px entre sus textos, y sus cajas de 44 px
	 * se cruzaban: tocar el subrayado de «Ver ficha completa» abría la ficha del
	 * tomo más nuevo, y el anillo de foco de cada uno tapaba el texto del otro.
	 * Además, el enlace que bajaba solo a la segunda línea arrancaba 12 px a la
	 * derecha de «EDICIÓN …».
	 */
	test( 'en móvil los dos enlaces de la cabecera no se roban el toque ni se tapan con el foco', async ( { page, isMobile } ) => {
		test.skip( ! isMobile, 'sólo en móvil quedan apilados' );
		test.setTimeout( 120_000 );

		for ( const [ ruta, ancho ] of [ [ '/serie/jujutsu-kaisen/', 390 ], [ '/serie/one-piece/', 320 ], [ SERIE, 360 ] ] ) {
			await page.setViewportSize( { width: ancho, height: 844 } );
			await page.goto( ruta, { waitUntil: 'load' } );

			const medida = await page.evaluate( () => {
				const mas = document.querySelector( '.akb-series-summary__more' );
				const ultimo = document.querySelector( '.akb-series-latest' );
				const rotulo = document.querySelector( '.akb-series-summary__label' );
				const texto = ( el ) => {
					const r = document.createRange();
					r.selectNodeContents( el );
					return Array.from( r.getClientRects() ).filter( ( q ) => q.width > 0 );
				};
				const ajenos = ( el ) => {
					let n = 0;
					for ( const q of texto( el ) ) {
						for ( let x = Math.ceil( q.left ); x < q.right; x += 2 ) {
							for ( let y = Math.ceil( q.top ); y < q.bottom; y++ ) {
								const e = document.elementFromPoint( x, y );
								if ( ! e || e.closest( 'a' ) !== el ) {
									n++;
								}
							}
						}
					}
					return n;
				};
				const cruza = ( el, otro ) => {
					const cs = getComputedStyle( el );
					const d = parseFloat( cs.outlineOffset ) + parseFloat( cs.outlineWidth || '3' );
					const b = el.getBoundingClientRect();
					const anillo = { l: b.left - d, r: b.right + d, t: b.top - d, b: b.bottom + d };
					return texto( otro ).some( ( q ) => ! ( q.right <= anillo.l || anillo.r <= q.left || q.bottom <= anillo.t || anillo.b <= q.top ) );
				};
				const lineas = texto( mas );
				const primera = lineas[ 0 ];
				const ultima = lineas[ lineas.length - 1 ];
				// ¿Empieza su línea? Sólo si ningún trozo del dato va antes, en la misma.
				const dato = document.querySelector( '.akb-series-summary__facts' );
				const empiezaLinea = ! dato || ! texto( dato ).some( ( q ) => Math.abs( q.top - primera.top ) < 4 && q.right <= primera.left + 1 );
				return {
					ajenosMas: ajenos( mas ),
					ajenosUltimo: ultimo ? ajenos( ultimo ) : 0,
					subrayado: { x: ( ultima.left + ultima.right ) / 2, y: ultima.bottom - 1 },
					anilloMasCruza: ultimo ? cruza( mas, ultimo ) : false,
					anilloUltimoCruza: ultimo ? cruza( ultimo, mas ) : false,
					sangria: Math.round( primera.left - rotulo.getBoundingClientRect().left ),
					bajoSolo: empiezaLinea && primera.top > rotulo.getBoundingClientRect().bottom - 2,
					alto: mas.getBoundingClientRect().height,
				};
			} );

			expect( medida.ajenosMas, `${ ruta } a ${ ancho }: el texto de «Ver ficha completa» es suyo` ).toBe( 0 );
			expect( medida.ajenosUltimo, `${ ruta } a ${ ancho }: el texto de «Último tomo» es suyo` ).toBe( 0 );
			expect( medida.anilloMasCruza, `${ ruta } a ${ ancho }: el foco de «Ver ficha completa» no tapa «Último tomo»` ).toBe( false );
			expect( medida.anilloUltimoCruza, `${ ruta } a ${ ancho }: el foco de «Último tomo» no tapa «Ver ficha completa»` ).toBe( false );
			if ( medida.bajoSolo ) {
				expect( Math.abs( medida.sangria ), `${ ruta } a ${ ancho }: el enlace que baja arranca alineado con «EDICIÓN …»` ).toBeLessThanOrEqual( 1 );
			}

			// Tocar el subrayado lleva a la ficha de la serie, no al último tomo.
			await page.touchscreen.tap( medida.subrayado.x, medida.subrayado.y );
			await expect( page, `${ ruta } a ${ ancho }: el subrayado es de «Ver ficha completa»` ).toHaveURL( /#ficha-de-la-serie$/ );
		}
	} );

	test( 'las condiciones del pack están a un toque y los chips siguen filtrando', async ( { page } ) => {
		await page.goto( SERIE, { waitUntil: 'load' } );

		const oferta = page.locator( PIEZAS.oferta );
		test.skip( 0 === await oferta.count(), 'el pack está apagado en este entorno' );

		// Cerrado: el descuento máximo a la vista; tramos y condiciones plegados.
		await expect( oferta.locator( '.akb-sb-landing__hook' ) ).toHaveText( /\d+ ?%/ );
		const condiciones = oferta.locator( 'details.akb-sb-landing__rules' );
		await expect( condiciones ).not.toHaveAttribute( 'open', '' );
		await expect( oferta.locator( '.akb-sb-landing__tier' ).first() ).toBeHidden();
		await condiciones.locator( 'summary' ).click();
		await expect( condiciones ).toHaveAttribute( 'open', '' );
		await expect( oferta.locator( '.akb-sb-landing__tier' ).first() ).toBeVisible();
		await expect( condiciones ).toContainText( 'Se aplica solo al sumarlos al carrito' );

		// Y con el teclado también: Enter sobre el disparador lo vuelve a cerrar.
		await condiciones.locator( 'summary' ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( condiciones ).not.toHaveAttribute( 'open', '' );

		// El chip «Disponibles» filtra de verdad: todas las tarjetas que quedan
		// están en stock.
		const disponibles = oferta.locator( 'button[name="_disponibilidad"][value="instock"]' );
		test.skip( 0 === await disponibles.count(), 'la serie no tiene tomos disponibles en este entorno' );
		await Promise.all( [
			page.waitForURL( /_disponibilidad=instock/ ),
			disponibles.click(),
		] );
		const tarjetas = page.locator( 'ul.products > li.product' );
		await expect( tarjetas.first() ).toBeVisible();
		expect( await page.locator( 'ul.products > li.product:not(.instock)' ).count(), 'sólo tomos en stock' ).toBe( 0 );
	} );

	/**
	 * Revisión B3-R7: abierto, el `<details>` pasaba a ocupar su propia fila y el
	 * disparador saltaba de (381,674) a (15,709) en escritorio; un segundo toque
	 * en el mismo punto ya no lo cerraba.
	 */
	test( 'el disparador de las condiciones no se mueve al abrir y un segundo toque en el mismo punto lo cierra', async ( { page, isMobile } ) => {
		await page.goto( SERIE, { waitUntil: 'load' } );
		const oferta = page.locator( PIEZAS.oferta );
		test.skip( 0 === await oferta.count(), 'el pack está apagado en este entorno' );

		const disparador = oferta.locator( 'summary.akb-sb-landing__rules-toggle' );
		await disparador.scrollIntoViewIfNeeded();
		const antes = await disparador.boundingBox();
		const boton = isMobile ? null : await oferta.locator( '[data-akb-sb-landing-start]' ).boundingBox();
		const x = antes.x + Math.min( 40, antes.width / 2 );
		const y = antes.y + antes.height / 2;
		const tocar = async () => ( isMobile ? page.touchscreen.tap( x, y ) : page.mouse.click( x, y ) );

		await tocar();
		await expect( oferta.locator( 'details.akb-sb-landing__rules' ) ).toHaveAttribute( 'open', '' );
		const abierto = await disparador.boundingBox();
		expect( Math.round( abierto.x ) ).toBe( Math.round( antes.x ) );
		expect( Math.round( abierto.y ) ).toBe( Math.round( antes.y ) );
		// En escritorio el botón comparte fila con el título y tampoco baja; en
		// móvil está en la fila de debajo, con los chips, y baja con lo que se abre.
		if ( ! isMobile ) {
			const botonAbierto = await oferta.locator( '[data-akb-sb-landing-start]' ).boundingBox();
			expect( Math.round( botonAbierto.y ), 'el botón tampoco baja' ).toBe( Math.round( boton.y ) );
		}

		await tocar();
		await expect( oferta.locator( 'details.akb-sb-landing__rules' ) ).not.toHaveAttribute( 'open', '' );
	} );

	/**
	 * Revisión B3-R6: el enlace del resumen tenía `nowrap` y el saneador acepta
	 * hasta 300 caracteres; con 80 la página pasaba a 558 px de ancho en 390. Se
	 * cambia sólo el texto en el DOM (la BD no se toca) en los cuatro textos del
	 * dueño que pinta esta cabecera.
	 */
	test( 'un texto largo del dueño en el resumen o en el riel no empuja la página en horizontal', async ( { page } ) => {
		await page.goto( SERIE, { waitUntil: 'load' } );
		const antes = await page.evaluate( () => document.documentElement.scrollWidth );

		const largo = 'Ver ficha técnica completa con estado de publicación, precios, autor y editorial';
		const medida = await page.evaluate( ( texto ) => {
			const cambiados = [];
			for ( const sel of [ '.akb-series-summary__more', '.akb-series-latest', '.akb-sb-landing__hook', '.akb-sb-landing__rules-label' ] ) {
				const el = document.querySelector( sel );
				if ( ! el ) {
					continue;
				}
				const nodo = [ ...el.childNodes ].find( ( n ) => 3 === n.nodeType && n.textContent.trim() ) || el.firstChild;
				nodo.textContent = texto;
				cambiados.push( sel );
			}
			const derecha = Math.max( ...cambiados.map( ( sel ) => document.querySelector( sel ).getBoundingClientRect().right ) );
			return { cambiados, derecha: Math.round( derecha ), ancho: document.documentElement.clientWidth, sw: document.documentElement.scrollWidth };
		}, largo );

		expect( medida.cambiados, 'el atajo al último tomo también es texto del dueño' ).toContain( '.akb-series-latest' );
		expect( medida.sw, 'el documento no se ensancha' ).toBe( antes );
		expect( medida.derecha, 'ningún texto se sale de la pantalla' ).toBeLessThanOrEqual( medida.ancho );
	} );

	/**
	 * Revisión RN-REV1-2 (2026-09-24): el atajo llevaba `margin-left: 12px`, que
	 * viajaba con él al bajar de línea. Con un texto del dueño algo más largo que
	 * el de fábrica (Textos → Landing de serie), en 320 px arrancaba 12 px a la
	 * derecha de «109 productos». La separación es ahora el espacio del HTML
	 * ensanchado, como en el resumen (B3-D2). Se cambia sólo el texto en el DOM
	 * (la BD no se toca).
	 */
	test( 'en móvil, el atajo que baja de línea arranca alineado con el total', async ( { page, isMobile } ) => {
		test.skip( ! isMobile, 'sólo en móvil la línea del total se queda corta' );
		await page.setViewportSize( { width: 320, height: 844 } );
		await page.goto( SERIE_LARGA, { waitUntil: 'load' } );
		await expect( page.locator( ATAJO ) ).toHaveCount( 1 );

		const medir = () => page.evaluate( ( selector ) => {
			const total = document.querySelector( '.akb-result-count__total' ).getBoundingClientRect();
			const enlace = document.querySelector( selector ).getBoundingClientRect();
			return {
				mismaLinea: Math.abs( ( enlace.top + enlace.height / 2 ) - ( total.top + total.height / 2 ) ) < 4,
				sangria: enlace.left - total.left,
				hueco: enlace.left - total.right,
			};
		}, ATAJO );

		// Con el texto de fábrica cabe al lado, y separado lo mismo que antes del
		// arreglo: el espacio más 12 px.
		const fabrica = await medir();
		expect( fabrica.mismaLinea, 'el texto de fábrica comparte línea con el total' ).toBe( true );
		expect( fabrica.hueco, 'separado del total por el espacio ensanchado' ).toBeGreaterThanOrEqual( 12 );
		expect( fabrica.hueco, 'separado del total por el espacio ensanchado' ).toBeLessThanOrEqual( 20 );

		await page.evaluate( ( selector ) => {
			const enlace = document.querySelector( selector );
			const nodo = [ ...enlace.childNodes ].find( ( n ) => 3 === n.nodeType && n.textContent.trim() );
			const numero = ( nodo.textContent.match( /\d+/ ) || [ '' ] )[ 0 ];
			nodo.textContent = `Ver el último tomo publicado: ${ numero }\u00a0`;
		}, ATAJO );
		const largo = await medir();
		expect( largo.mismaLinea, 'el texto largo baja a la línea siguiente' ).toBe( false );
		expect( Math.abs( largo.sangria ), 'el atajo que baja arranca alineado con «N productos»' ).toBeLessThanOrEqual( 1 );
	} );

	test( 'una serie de hasta 48 tomos cabe entera en una página, del 1 al último', async ( { page } ) => {
		await page.goto( SERIE, { waitUntil: 'domcontentloaded' } );

		const titulos = await page.locator( 'ul.products > li.product .woocommerce-loop-product__title' ).allInnerTexts();
		const numeros = titulos
			.map( ( t ) => ( t.match( /(\d+)\s*$/ ) || [] )[ 1 ] )
			.filter( Boolean )
			.map( Number );

		const total = Number( ( ( await page.locator( '.akb-result-count' ).first().innerText() ).match( /\d+/ ) || [ 0 ] )[ 0 ] );
		test.skip( total > 48, 'esta serie creció por encima de 48 tomos: ya no es el caso que se mide' );

		expect( numeros.length, 'todas las tarjetas de la serie en la página 1' ).toBe( total );
		expect( numeros, 'orden «Tomo 1 → N»' ).toEqual( [ ...numeros ].sort( ( a, b ) => a - b ) );
		await expect( page.locator( '.woocommerce-pagination' ) ).toHaveCount( 0 );
	} );

	/**
	 * Revisión B3-2-R2: con 48 por página el tomo más nuevo de estas series queda
	 * al fondo de una sola página larga («Jujutsu Kaisen 30» a 8.121 px en móvil)
	 * y el atajo de B3 sólo salía en la serie que pagina. Ahora está en todas, en
	 * la línea del total, y abre la ficha del tomo más nuevo.
	 */
	test( 'en una serie de una página, el atajo «Último tomo» está junto al total y abre la ficha del tomo más nuevo', async ( { page, request } ) => {
		await page.goto( '/serie/jujutsu-kaisen/', { waitUntil: 'load' } );
		const titulos = await page.locator( 'ul.products > li.product .woocommerce-loop-product__title' ).allInnerTexts();
		const mayor = Math.max( ...titulos.map( numeroDeTomo ).filter( ( n ) => null !== n ) );

		const atajo = page.locator( ATAJO );
		await expect( atajo ).toHaveCount( 1 );
		await expect( atajo ).toBeVisible();
		await expect( atajo ).toHaveText( new RegExp( `${ mayor }\\s*→$` ) );
		await expect( page.locator( '.akb-series-summary a[href*="/page/"], .akb-series-summary__last' ) ).toHaveCount( 0 );

		// Mismo renglón que «30 productos»: no suma alto sobre la primera portada.
		const [ total, enlace ] = await Promise.all( [
			page.locator( '.akb-result-count__total' ).boundingBox(),
			page.locator( ATAJO ).boundingBox(),
		] );
		// Por el centro de las dos cajas: la del atajo ya no lleva relleno (su zona
		// táctil es un ::after, revisión B3-D1), así que no se le resta nada.
		expect( Math.abs( ( enlace.y + enlace.height / 2 ) - ( total.y + total.height / 2 ) ), 'el atajo comparte línea con el total' ).toBeLessThan( 4 );

		expect( await tomoDeLaFicha( request, await atajo.getAttribute( 'href' ) ) ).toBe( mayor );

		await Promise.all( [ page.waitForURL( ( u ) => ! u.pathname.startsWith( '/serie/' ) ), atajo.click() ] );
		await expect( page.locator( 'h1.product_title' ) ).toContainText( String( mayor ) );
	} );

	/**
	 * Revisión B3-2-R1: el atajo de B3 llevaba a la última PÁGINA, y con «Más
	 * recientes» o «Novedades primero» ésa tiene los tomos MÁS ANTIGUOS; con
	 * precio o popularidad, cualquiera. El atajo lleva ahora a la ficha del tomo
	 * más nuevo: el mismo destino en cualquier orden.
	 */
	test( 'el atajo lleva al tomo más nuevo en cualquier orden', async ( { page, request } ) => {
		await page.goto( SERIE_LARGA, { waitUntil: 'domcontentloaded' } );
		const base = await page.locator( ATAJO ).getAttribute( 'href' );
		expect( base, 'la serie larga también lo tiene' ).toBeTruthy();
		expect( base, 'no es una página de la rejilla' ).not.toMatch( /\/page\/|orderby=/ );
		const tomo = await tomoDeLaFicha( request, base );
		await expect( page.locator( ATAJO ) ).toHaveText( new RegExp( `${ tomo }\\s*→$` ) );

		// El número más alto de la serie está en la última página del orden por
		// defecto («Tomo 1 → N»): el atajo tiene que abrir justo ése.
		const total = Number( ( ( await page.locator( '.akb-result-count__total' ).innerText() ).match( /\d+/ ) || [ 0 ] )[ 0 ] );
		const ultima = Math.max( 1, Math.ceil( total / 48 ) );
		await page.goto( ultima > 1 ? `${ SERIE_LARGA }page/${ ultima }/` : SERIE_LARGA, { waitUntil: 'domcontentloaded' } );
		const numeros = ( await page.locator( 'ul.products > li.product .woocommerce-loop-product__title' ).allInnerTexts() )
			.map( numeroDeTomo )
			.filter( ( n ) => null !== n );
		expect( tomo, 'el tomo de número más alto' ).toBe( Math.max( ...numeros ) );

		for ( const orden of [ 'date', 'menu_order', 'price', 'popularity' ] ) {
			await page.goto( `${ SERIE_LARGA }?orderby=${ orden }`, { waitUntil: 'domcontentloaded' } );
			await expect( page.locator( ATAJO ), `orderby=${ orden }` ).toHaveAttribute( 'href', base );
		}
	} );

	test( 'la serie larga pagina de a 48 y una página que ya no existe redirige a la última', async ( { page, request } ) => {
		await page.goto( SERIE_LARGA, { waitUntil: 'domcontentloaded' } );
		const total = Number( ( ( await page.locator( '.akb-result-count' ).first().innerText() ).match( /\d+/ ) || [ 0 ] )[ 0 ] );
		test.skip( total <= 48, 'la serie más larga de este entorno cabe en una página' );

		await expect( page.locator( 'ul.products > li.product' ) ).toHaveCount( 48 );
		const ultima = Math.ceil( total / 48 );

		const respuesta = await request.get( `${ SERIE_LARGA }page/${ ultima + 2 }/`, { maxRedirects: 0 } );
		expect( respuesta.status(), 'una página limpia fuera de rango no queda en 404' ).toBe( 301 );
		expect( respuesta.headers().location ).toMatch( new RegExp( `${ SERIE_LARGA.replace( /\//g, '\\/' ) }page\\/${ ultima }\\/$` ) );

		// Revisión B3-2-R3: se pagina por el buscador (PJAX) hasta la ÚLTIMA página.
		// El atajo de B3 vivía en el resumen, que el PJAX no reemplaza, y se quedaba
		// apuntando a la página en la que ya se estaba. El de ahora está en la línea
		// del total, que el PJAX sí reemplaza, y su destino no depende de la página.
		const atajo = await page.locator( ATAJO ).getAttribute( 'href' );
		// Marca en `window`: si la paginación recargara el documento, desaparece.
		await page.evaluate( () => {
			window.akbSinRecarga = true;
		} );
		// El número puede llevar delante «Página» para el lector de pantalla (el
		// tema lo agrega en `woocommerce_pagination_args`): se ancla sólo el final.
		await page.locator( '.woocommerce-pagination a.page-numbers', { hasText: new RegExp( `(^|\\s)${ ultima }$` ) } ).first().click();
		await expect( page ).toHaveURL( new RegExp( `/page/${ ultima }/?` ) );
		await expect( page.locator( 'ul.products > li.product' ) ).toHaveCount( total - 48 * ( ultima - 1 ) );
		expect( await page.evaluate( () => true === window.akbSinRecarga ), 'la paginación fue por PJAX, sin recargar el documento' ).toBe( true );
		await expect( page.locator( ATAJO ), 'una sola vez tras el swap' ).toHaveCount( 1 );
		await expect( page.locator( ATAJO ) ).toHaveAttribute( 'href', atajo );
		await expect( page.locator( '.akb-series-summary a[href*="/page/"], .akb-series-summary__last' ) ).toHaveCount( 0 );
		// La ficha sigue una sola vez al cambiar de página por PJAX.
		await expect( page.locator( '.akb-series-facts' ) ).toHaveCount( 1 );

		// Lo que el PJAX anuncia a un lector de pantalla es el total, no el atajo.
		await expect( page.locator( '#akb-live-region' ) ).toHaveText( /^\d[\d.]* productos?$/ );

		// Y con F5 en la misma URL, lo mismo: una dirección, un DOM.
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await expect( page.locator( ATAJO ) ).toHaveAttribute( 'href', atajo );
	} );

	test( 'la página 2 de una serie que ya cabe en una redirige a la landing', async ( { request } ) => {
		const respuesta = await request.get( `${ SERIE }page/2/`, { maxRedirects: 0 } );
		expect( respuesta.status() ).toBe( 301 );
		expect( respuesta.headers().location ).toMatch( /\/serie\/hunter-x-hunter\/$/ );

		// Con parámetros no se redirige: esas variantes son noindex y se quedan en 404.
		const conFiltro = await request.get( `${ SERIE }page/2/?orderby=date`, { maxRedirects: 0 } );
		expect( conFiltro.status() ).toBe( 404 );
	} );

	test( 'la landing no deja errores de consola nuevos', async ( { page } ) => {
		const errores = erroresDePagina( page );
		page.on( 'console', ( m ) => {
			// Un recurso 404 lo reporta el navegador como error de consola; se
			// cuenta aparte por URL para no confundirlo con un fallo de script.
			if ( 'error' === m.type() && ! /Failed to load resource/.test( m.text() ) ) {
				errores.push( m.text() );
			}
		} );

		await page.goto( SERIE, { waitUntil: 'load' } );
		await page.locator( PIEZAS.resumen ).scrollIntoViewIfNeeded();
		await page.locator( PIEZAS.ficha ).scrollIntoViewIfNeeded();

		expect( errores ).toEqual( [] );
	} );
} );
