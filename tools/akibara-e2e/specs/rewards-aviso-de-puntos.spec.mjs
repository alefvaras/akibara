// @ts-check
/**
 * El aviso «Gana N Puntos Akibara» de la ficha (akibara-rewards, PdpNotice).
 *
 * Lo que se fija aquí, y por qué:
 *
 *  - UC-4b (auditoría del tema, 2026-09-22): el aviso iba pegado al precio
 *    (prioridad 11 del resumen) y, en dos líneas, bajaba el botón de compra. En
 *    un portátil de 1366x768 el botón real de un tomo «a pedido» quedaba en
 *    707-752 px, debajo de la barra fija. Ahora va justo DEBAJO del formulario
 *    de compra, antes de los sellos de confianza, como la línea de despacho.
 *    Eso resuelve UC-4b SÓLO en los tomos a pedido sin valoraciones: en uno con
 *    valoraciones el botón sigue tapado por filas que no son de este plugin, y
 *    esa prueba queda marcada como pendiente, con dueño (ver la de 1366x768).
 *  - Donde no hay formulario (tomo agotado) el aviso sigue saliendo UNA vez, a
 *    la misma altura que antes.
 *  - «Ver programa» mide 24 px de alto o más (UM-15/AI-7: medía 90x17), y no
 *    se parte en dos líneas en ningún ancho de teléfono (QA-RW-2).
 *
 * `puntos.spec.mjs` mira la promesa en pesos en un tomo que se elige desde el
 * listado, como lo haría un cliente; aquí se mira dónde va el aviso.
 *
 * Todo es de lectura: los productos salen de la Store API, por su estado de
 * stock, y no hay slugs clavados.
 *
 * Un aviso que falta es un rojo, no un salto: el aviso apagado en el panel de
 * Puntos se DECLARA (`AKB_AVISO_PUNTOS=apagado`), y la declaración se comprueba
 * en la ficha (soporte/aviso-de-puntos.mjs). La página del programa se mira en
 * la propia página y se contrasta con lo que enlaza la ficha. Deducir las dos
 * cosas de que la ficha no pinte lo que se prueba dejaba en verde la regresión
 * que borra el aviso (REV-F-R-rev1-1).
 *
 * «Se ve» quiere decir a la vista del cliente (soporte/a-la-vista.mjs), no
 * sólo toBeVisible(): ése da por visible un aviso con opacity:0 o recortado a
 * 1x1 px (REV-F-R-rev2-1). Y se mide en los píxeles, no en el CSS: el aviso
 * entero y, aparte, la promesa, que va en su propio <strong> y se puede
 * esconder sola sin que la caja del aviso cambie (REV-F-R-3-1, REV-M-R-2-1 a
 * 2-3).
 */

import { expect, test } from '@playwright/test';
import { aLaVista } from './soporte/a-la-vista.mjs';
import { AVISO, FALTA_EL_AVISO, PROMESA, seLeeLaPromesa, siDeclaradoApagado } from './soporte/aviso-de-puntos.mjs';

/**
 * «Ver programa», contrastado con la página adonde lleva.
 *
 * El plugin decide si pinta el enlace con ProgramPage::page(), que la buscaba
 * con get_page_by_path(). Esa función no mira el estado: con la página en
 * borrador o privada, la ficha seguía enlazando a ?page_id=27519, que da 404 al
 * invitado (medido en el espejo, 2026-09-25). La prueba se saltaba justo
 * entonces, porque /akibara-puntos/ daba 404; y si la página se borraba y
 * /akibara-puntos/ redirigía a otra con 200, exigía un enlace que el plugin ya
 * no pinta (REV-F-R-rev2-2). ProgramPage::page() ya sólo cuenta la página
 * publicada, y aquí se contrasta la ficha con la página:
 *  - la página cuenta como publicada sólo si responde 200 sin seguir
 *    redirecciones;
 *  - publicada: el enlace está a la vista, dentro del aviso, y su href abre
 *    con 200 para el invitado y es /akibara-puntos/;
 *  - no publicada: la ficha no puede enlazar (rojo si enlaza), y sólo después
 *    se salta el resto.
 *
 * @param {import('@playwright/test').Page} page Página con la ficha abierta.
 * @param {import('@playwright/test').APIRequestContext} request Cliente HTTP.
 * @returns {Promise<import('@playwright/test').Locator>} El enlace, a la vista.
 */
async function verProgramaQueAbre( page, request ) {
	await aLaVista( page.locator( AVISO ).first(), FALTA_EL_AVISO );
	const enlace = page.locator( `${ AVISO } a` );

	const programa = ( await request.get( '/akibara-puntos/', { maxRedirects: 0 } ) ).status();
	if ( 200 !== programa ) {
		await expect( enlace, `la ficha no enlaza a una página del programa que el cliente no puede abrir (/akibara-puntos/ da ${ programa })` ).toHaveCount( 0 );
		test.skip( true, `este entorno no publica la página del programa (/akibara-puntos/ da ${ programa }) y la ficha, como debe, no enlaza a ella` );
	}

	await aLaVista( enlace, 'con la página del programa publicada, el aviso enlaza a ella', { dentroDe: AVISO } );
	const href = String( await enlace.getAttribute( 'href' ) );
	expect( ( await request.get( href, { maxRedirects: 0 } ) ).status(), `«Ver programa» (${ href }) abre para el invitado` ).toBe( 200 );
	// Y abre ESA página: el muro de /mi-cuenta/ y la portada también dan 200
	// al invitado (REV-F-R-3-2).
	expect( new URL( href, page.url() ).href, '«Ver programa» lleva a la página del programa' ).toBe( new URL( '/akibara-puntos/', page.url() ).href );
	return enlace;
}

/**
 * Ficha de un tomo simple con el estado pedido, de la Store API.
 *
 * `resenas` elige el caso: `true`, un tomo con al menos una valoración (se piden
 * ordenados por valoración, así que si hay alguno sale en la primera página);
 * `false`, uno sin ninguna; sin decirlo, el primero que haya.
 *
 * @param {import('@playwright/test').APIRequestContext} request Cliente HTTP.
 * @param {'instock'|'onbackorder'|'outofstock'} estado Estado de stock.
 * @param {{resenas?: boolean}} [filtro] Con o sin valoraciones.
 * @returns {Promise<string|null>} Ruta de la ficha, o null si no hay.
 */
async function fichaCon( request, estado, filtro = {} ) {
	const orden = true === filtro.resenas ? '&orderby=rating&order=desc' : '';
	const respuesta = await request.get( `/wp-json/wc/store/v1/products?stock_status=${ estado }&type=simple&per_page=20${ orden }` );
	if ( ! respuesta.ok() ) {
		return null;
	}
	/** @type {Array<{permalink:string,is_purchasable:boolean,review_count:number}>} */
	const productos = await respuesta.json();
	// Los tomos de prueba que siembran otros carriles (qa-…) no son el catálogo.
	const ficha = productos.find( ( p ) => p.is_purchasable && ! /\/qa-/.test( p.permalink )
		&& ( undefined === filtro.resenas || filtro.resenas === ( p.review_count > 0 ) ) );
	return ficha ? new URL( ficha.permalink ).pathname : null;
}

/**
 * Lo que queda de UC-4b, y de quién es.
 *
 * Medido en el espejo el 2026-09-24, a 1366x768, en Chromium y en WebKit: con
 * el aviso ya bajo el formulario, un tomo a pedido SIN valoraciones deja el
 * botón real en 663-708 px y la barra fija ni aparece. Uno CON valoraciones
 * (Frieren 10, el único tomo a pedido con reseña del espejo) lo deja en
 * 701-746 px, con la barra visible desde 683: tapado. Los 38 px de diferencia
 * son la fila de estrellas de business (`akb-pdp-rating`, PdpEnhancer); la caja
 * de fecha estimada de llegada (`akb-eta`, 89 px, EstimatedDelivery) está en
 * los dos casos, y la barra y cuándo aparece son del tema (sticky-atc.js).
 * Nada de eso es de akibara-rewards: bajar más el aviso no sube el botón.
 *
 * Dueño: el líder de «2026-09-22-tema-ux-rendimiento-mejoras» (carril de
 * business y tema). Opciones anotadas por la revisión: compactar esas filas a
 * 1366x768, o que el tema no muestre la barra mientras el botón real asome.
 */
const PENDIENTE_UC4B = 'PENDIENTE UC-4b — tomo a pedido CON valoraciones: la fila de estrellas y la caja de llegada (business) '
	+ 'bajan el botón real bajo la barra fija (tema). Dueño: líder de 2026-09-22-tema-ux-rendimiento-mejoras (business/tema).';

/**
 * Dónde queda cada pieza de la columna de compra, en orden del documento.
 *
 * @param {import('@playwright/test').Page} page Página.
 */
function columna( page ) {
	return page.evaluate( () => {
		const avisos = document.querySelectorAll( '.akb-rw-pdp-points' );
		const aviso = avisos[ 0 ] || null;
		const form = document.querySelector( '.summary form.cart' );
		const sellos = document.querySelector( '.summary .akb-product-trust-badges' );
		const precio = document.querySelector( '.summary .price' );
		const sigue = ( a, b ) => !! ( a && b && ( a.compareDocumentPosition( b ) & Node.DOCUMENT_POSITION_FOLLOWING ) );
		return {
			avisos: avisos.length,
			texto: aviso ? aviso.textContent.trim() : '',
			trasElFormulario: sigue( form, aviso ),
			antesDeLosSellos: sellos ? sigue( aviso, sellos ) : null,
			trasElPrecio: sigue( precio, aviso ),
			hayFormulario: !! form,
		};
	} );
}

test.describe( 'aviso de puntos en la ficha', () => {
	for ( const [ estado, nombre ] of /** @type {const} */ ( [ [ 'instock', 'disponible' ], [ 'onbackorder', 'a pedido' ] ] ) ) {
		test( `en un tomo ${ nombre } va bajo el botón de compra, una sola vez`, async ( { page, request } ) => {
			const ruta = await fichaCon( request, estado );
			test.skip( ! ruta, `sin tomos ${ nombre } en este entorno` );
			const respuesta = await page.goto( String( ruta ), { waitUntil: 'domcontentloaded' } );
			expect( respuesta?.status() ).toBe( 200 );
			await siDeclaradoApagado( page );

			// A la vista, no sólo en el DOM: un aviso con display:none, plegado,
			// transparente o fuera de la pantalla también «va bajo el botón»
			// (REV-F-R-rev1-2, REV-F-R-rev2-1). Y la promesa, legible.
			await aLaVista( page.locator( AVISO ).first(), FALTA_EL_AVISO );
			await seLeeLaPromesa( page );
			const c = await columna( page );

			expect( c.avisos, 'el aviso se pinta una sola vez aunque cuelgue de dos ganchos' ).toBe( 1 );
			expect( c.texto ).toMatch( PROMESA );
			expect( c.hayFormulario ).toBe( true );
			expect( c.trasElFormulario, 'UC-4b: el aviso ya no se interpone entre el precio y el botón' ).toBe( true );
			if ( null !== c.antesDeLosSellos ) {
				expect( c.antesDeLosSellos, 'pegado al botón: antes de los sellos de confianza' ).toBe( true );
			}
		} );
	}

	test( 'en un tomo agotado (sin formulario) sigue saliendo, una vez, tras el precio', async ( { page, request } ) => {
		const ruta = await fichaCon( request, 'outofstock' );
		test.skip( ! ruta, 'sin tomos agotados en este entorno' );
		await page.goto( String( ruta ), { waitUntil: 'domcontentloaded' } );
		await siDeclaradoApagado( page );

		await aLaVista( page.locator( AVISO ).first(), FALTA_EL_AVISO );
		await seLeeLaPromesa( page );
		const c = await columna( page );
		expect( c.hayFormulario, 'un agotado no pinta el formulario de compra' ).toBe( false );
		expect( c.avisos ).toBe( 1 );
		expect( c.trasElPrecio ).toBe( true );
	} );

	// Dos casos, porque la altura de la columna cambia con las valoraciones: la
	// prueba que sólo miraba el primer tomo a pedido de la Store API (uno sin
	// reseñas) salía en verde mientras el único con reseñas seguía tapado
	// (REV-R-R-2-1). El de con valoraciones es el peor caso y es PENDIENTE: ver
	// PENDIENTE_UC4B arriba.
	for ( const [ resenas, caso ] of /** @type {const} */ ( [ [ false, 'sin valoraciones' ], [ true, 'con valoraciones' ] ] ) ) {
		test( `en un portátil de 1366x768 el botón real de un tomo a pedido ${ caso } queda a la vista`, async ( { page, request }, info ) => {
			test.skip( 'escritorio' !== info.project.name, 'geometría de escritorio' );
			await page.setViewportSize( { width: 1366, height: 768 } );
			const ruta = await fichaCon( request, 'onbackorder', { resenas } );
			test.skip( ! ruta, `sin tomos a pedido ${ caso } en este entorno` );
			const respuesta = await page.goto( String( ruta ), { waitUntil: 'load' } );
			expect( respuesta?.status() ).toBe( 200 );
			await page.waitForTimeout( 500 );

			const m = await page.evaluate( () => {
				const boton = document.querySelector( 'form.cart .single_add_to_cart_button' );
				const real = boton ? boton.getBoundingClientRect() : null;
				const b = document.getElementById( 'akb-sticky-atc' );
				const barra = b && b.classList.contains( 'is-visible' ) ? b.getBoundingClientRect() : null;
				// Opacidad efectiva, la suya por la de cada ancestro: toBeVisible()
				// da por visible un botón con opacity:0 (REV-F-R-1-2).
				let opacidad = boton ? 1 : 0;
				for ( let el = boton; el; el = el.parentElement ) {
					opacidad *= Number( getComputedStyle( el ).opacity );
				}
				// Qué hay en el centro de la parte del botón que asoma en pantalla. La
				// barra fija se descarta sólo cuando está a la vista: ésa es la
				// geometría que mide la última comprobación. Si el botón no asoma
				// (null), no hay nada que tocar: lo decide esa misma comprobación.
				//
				// Se mira dos veces, porque elementsFromPoint se salta lo que tiene
				// pointer-events:none:
				//  - `toque`, tal cual, como un dedo: falla si el botón no recibe el
				//    toque, por pointer-events:none suyo o heredado (form.cart), o si
				//    otra capa se lo lleva (REV-F-R-2-1).
				//  - `capa`, con el puntero forzado en todo, también en ::before y
				//    ::after: falla si lo tapa una capa decorativa con
				//    pointer-events:none (REV-F-R-1-2). La regla va en una capa de
				//    cascada declarada la primera, y así su !important gana al de
				//    cualquier hoja, sea cual sea el selector (REV-F-R-2-2). No gana a
				//    un !important en línea (style="…"): esa capa no se ve. Lo que
				//    tiene visibility:hidden (la barra oculta) tampoco cuenta.
				//    Tampoco se ve lo `inert` (elementsFromPoint lo excluye y el
				//    puntero forzado no lo anula), y el tema lo usa en lo cerrado:
				//    se suelta mientras se mira y se devuelve en el acto
				//    (REV-F-R-rev1-3). Un botón inerte, o dentro de algo inerte, ya
				//    lo detecta `toque`.
				const tapa = barra ? b : null;
				let toque = null;
				let capa = null;
				if ( boton && real && real.width > 0 && real.top < innerHeight && real.bottom > 0 ) {
					const x = real.left + ( real.width / 2 );
					const y = ( Math.max( real.top, 0 ) + Math.min( real.bottom, innerHeight ) ) / 2;
					const queHay = () => {
						const el = document.elementsFromPoint( x, y ).find( ( e ) => ! ( tapa && tapa.contains( e ) ) );
						return el && boton.contains( el ) ? 'el botón' : ( el ? el.outerHTML.slice( 0, 120 ) : 'nada' );
					};
					toque = queHay();
					const hoja = document.createElement( 'style' );
					hoja.textContent = '@layer akb-sonda { *, *::before, *::after { pointer-events: auto !important; } }';
					document.head.prepend( hoja );
					const inertes = [ ...document.querySelectorAll( '[inert]' ) ].map( ( e ) => [ e, String( e.getAttribute( 'inert' ) ) ] );
					inertes.forEach( ( [ e ] ) => e.removeAttribute( 'inert' ) );
					capa = queHay();
					inertes.forEach( ( [ e, valor ] ) => e.setAttribute( 'inert', valor ) );
					hoja.remove();
				}
				return {
					hayBoton: !! real,
					opacidad,
					// La fila con valoraciones; la vacía («ESCRIBIR REVIEW») es opcional por producto.
					estrellas: !! document.querySelector( '.summary .akb-pdp-rating:not(.akb-pdp-rating--empty)' ),
					ancho: real ? real.width : 0,
					alto: real ? real.height : 0,
					toque,
					capa,
					top: real ? real.top : -1,
					bottom: real ? real.bottom : Infinity,
					techo: barra ? barra.top : innerHeight,
				};
			} );
			// Lo que no es la geometría falla de verdad, también en el caso pendiente:
			// la marca de abajo sólo cubre al botón tapado POR LA BARRA. Falla un
			// botón con display:none, visibility:hidden, opacidad 0 (suya o de un
			// ancestro) o sin tamaño; uno que no recibe el toque (pointer-events:none
			// suyo o de form.cart); y uno con otra capa encima en su centro, también
			// si esa capa tiene pointer-events:none, aunque sea !important desde una
			// hoja, o es `inert` (REV-R-R-3-1: con display:none medía 0-0 y salía en
			// verde; REV-F-R-1-2; REV-F-R-2-1; REV-F-R-2-2; REV-F-R-rev1-3). No mira
			// filtros ni recortes (filter, clip-path), ni una capa con
			// pointer-events:none !important en su atributo style.
			expect( m.hayBoton, 'la ficha pinta el botón de compra real' ).toBe( true );
			expect( m.estrellas, `el tomo elegido (${ ruta }) ${ resenas ? 'lleva' : 'no lleva' } la fila de estrellas` ).toBe( resenas );
			await expect( page.locator( 'form.cart .single_add_to_cart_button' ).first(), 'el botón real se ve (ni display:none ni visibility:hidden)' ).toBeVisible();
			expect( m.opacidad, 'el botón real no es transparente (opacity:0 suya o de un ancestro)' ).toBeGreaterThan( 0 );
			expect( m.ancho > 0 && m.alto > 0, `el botón real tiene tamaño (${ m.ancho }x${ m.alto })` ).toBe( true );
			if ( null !== m.toque ) {
				expect( m.toque, 'un toque en el centro del botón real llega al botón (ni pointer-events:none suyo o de form.cart, ni otra capa que se lo lleve)' ).toBe( 'el botón' );
				expect( m.capa, 'fuera de la barra fija, ninguna capa decorativa tapa el botón real (pointer-events:none, aunque sea !important desde una hoja, ni inert)' ).toBe( 'el botón' );
			}
			expect( m.top ).toBeGreaterThanOrEqual( 0 );

			// Antes del aviso bajo el botón: 707-752 con la barra en 683 (espejo, 2026-09-23).
			// Después, sin valoraciones: 663-708 y sin barra; con valoraciones: 701-746 y
			// la barra en 683 (espejo, Chromium y WebKit, 2026-09-24). `test.fail` corre
			// la prueba igual: el día que alguien lo arregle saldrá en rojo («se esperaba
			// que fallara») y habrá que quitar la marca.
			test.fail( resenas, PENDIENTE_UC4B );
			expect( m.bottom, `UC-4b: botón real en ${ Math.round( m.top ) }-${ Math.round( m.bottom ) }, techo en ${ Math.round( m.techo ) }` ).toBeLessThanOrEqual( m.techo );
		} );
	}

	test( '«Ver programa» tiene un área de toque de 24 px de alto o más', async ( { page, request } ) => {
		const ruta = await fichaCon( request, 'instock' );
		test.skip( ! ruta, 'sin tomos disponibles en este entorno' );
		await page.goto( String( ruta ), { waitUntil: 'domcontentloaded' } );
		await siDeclaradoApagado( page );

		const enlace = await verProgramaQueAbre( page, request );
		const caja = await enlace.evaluate( ( el ) => {
			const r = el.getBoundingClientRect();
			return { alto: r.height, ancho: r.width };
		} );
		expect( caja.alto, 'UM-15/AI-7: medía 17 px de alto' ).toBeGreaterThanOrEqual( 24 );
		expect( caja.ancho ).toBeGreaterThanOrEqual( 24 );
	} );

	// QA-RW-2 (2026-09-24): cuando el corte de línea caía DENTRO del enlace,
	// «Ver» quedaba solo al final de una línea (22x25) y «programa» en la
	// siguiente (64x25), solapados 3 px por el relleno. Pasaba en WebKit y en
	// Chromium: medido en el espejo en 14 a 21 de los 161 anchos entre 320 y
	// 480 px (p. ej. 376-389). Se barre cada ancho de teléfono.
	test( '«Ver programa» nunca se parte en dos líneas, en ningún ancho de teléfono', async ( { page, request }, info ) => {
		test.skip( 'movil' !== info.project.name, 'barrido de anchos de teléfono' );
		const ruta = await fichaCon( request, 'instock' );
		test.skip( ! ruta, 'sin tomos disponibles en este entorno' );
		await page.goto( String( ruta ), { waitUntil: 'load' } );
		await page.evaluate( () => document.fonts.ready );
		await siDeclaradoApagado( page );
		const enlace = await verProgramaQueAbre( page, request );

		const partidos = [];
		for ( let ancho = 320; ancho <= 480; ancho++ ) {
			await page.setViewportSize( { width: ancho, height: 844 } );
			const cajas = await enlace.evaluate( ( el ) =>
				[ ...el.getClientRects() ].map( ( r ) => [ Math.round( r.width ), Math.round( r.height ) ] )
			);
			if ( 1 !== cajas.length || cajas[ 0 ][ 0 ] < 24 || cajas[ 0 ][ 1 ] < 24 ) {
				partidos.push( `${ ancho } px: ${ JSON.stringify( cajas ) }` );
			}
		}
		expect( partidos, 'anchos donde el enlace se parte o baja de 24 px' ).toEqual( [] );
	} );
} );
