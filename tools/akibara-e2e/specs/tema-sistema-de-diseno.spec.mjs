// @ts-check
/**
 * Sistema de diseño del tema (lote T6, 2026-09-23): lo que la suite unitaria no
 * puede ver porque depende de la CASCADA real (WooCommerce core carga en la
 * tienda y en la ficha y gana a las reglas flojas) y de estados (hover, foco).
 *
 * Todo es LECTURA: vale contra el espejo y, tras desplegar, contra producción en
 * la suite entera (`npm run test:prod:completa`, sólo con permiso del dueño): no
 * lleva la etiqueta `@prod` de la prueba de humo.
 *
 * - DS-7: «Quedan N unidades» en el ámbar de la paleta, no en el oliva de WC.
 * - DS-1: títulos de sección de la ficha por encima del párrafo y bajo el H1.
 * - DS-2: el CTA de «a pedido» es el MISMO rojo que el de compra normal.
 * - DS-4: la tarjeta, al pasar el ratón, deja una sombra que se ve (no #121212).
 * - T6-R2: un clic dentro de la tarjeta no la deja levantada; el teclado, sí.
 * - DS-5: el foco de teclado de la cabecera es amarillo, sin outline rojo.
 * - T6-R3 / T6-R4: la paginación enfoca con el anillo amarillo, y la página
 *   actual sigue como estaba (el color es de DS-3, que espera al dueño).
 * - UM-6: los sellos de la portada que no son enlace no llevan marco rojo.
 * - DS-15 / T6-R1: el título de las reseñas se ve tal cual lo lee el lector de
 *   pantalla y, en móvil, no repite la etiqueta del panel.
 * - T6-R5: con el carrito lateral abierto, «saltar al contenido» queda encima.
 */

import { expect, test } from '@playwright/test';
import { productoConBadge } from './soporte/tienda.mjs';

const ROJO_COMPRA = 'rgb(204, 0, 16)'; // --aki-buy-bg = --aki-red-readable
const AMARILLO = 'rgb(255, 215, 0)'; // --aki-yellow (accent)

test.describe( 'sistema de diseño', () => {
	test( 'ficha disponible: escasez en ámbar, títulos de sección con jerarquía y reseñas honestas', async ( { page } ) => {
		const url = await productoConBadge( page, 'Disponible' );
		test.skip( ! url, 'no hay ningún tomo disponible en este catálogo' );
		const r = await page.goto( /** @type {string} */ ( url ), { waitUntil: 'load' } );
		expect( r?.status() ).toBe( 200 );

		const escasez = page.locator( 'p.stock.akb-stock-low' );
		if ( await escasez.count() ) {
			await expect( escasez ).toHaveCSS( 'color', 'rgb(245, 158, 11)' );
		}

		const medidas = await page.evaluate( () => {
			const px = ( e ) => ( e ? parseFloat( getComputedStyle( e ).fontSize ) : null );
			const h1 = document.querySelector( 'h1.product_title' );
			const secciones = [ ...document.querySelectorAll( '.related > h2, .up-sells > h2, .section-header__title' ) ].map( px );
			// El texto corrido de la ficha hereda el tamaño del body (17 px a 1440).
			const parrafo = px( document.body );
			const titulo = document.querySelector( '#reviews #comments h2' );
			const panel = document.querySelector( '#tab-reviews' );
			const etiqueta = panel ? getComputedStyle( panel, '::before' ).content.replace( /^["']|["']$/g, '' ) : '';
			return {
				h1: px( h1 ),
				secciones,
				parrafo,
				resenas: titulo ? {
					vis: getComputedStyle( titulo ).visibility,
					despues: getComputedStyle( titulo, '::after' ).content,
					alto: titulo.getBoundingClientRect().height,
					texto: titulo.textContent.replace( /\s+/g, ' ' ).trim(),
					etiqueta: 'none' === etiqueta ? '' : etiqueta,
				} : null,
			};
		} );
		for ( const s of medidas.secciones ) {
			expect( s, 'un título de sección no puede medir como el párrafo' ).toBeGreaterThan( /** @type {number} */ ( medidas.parrafo ) + 4 );
			expect( s, 'ni más que el H1 de la ficha' ).toBeLessThan( /** @type {number} */ ( medidas.h1 ) );
		}
		if ( medidas.resenas ) {
			expect( medidas.resenas.vis ).toBe( 'visible' );
			expect( medidas.resenas.despues ).toBe( 'none' );
			// T6-R1: en móvil business rotula el panel («Valoraciones») con un ::before;
			// el <h2> visible debajo no puede decir lo mismo (salía dos veces seguidas).
			if ( medidas.resenas.etiqueta && medidas.resenas.alto > 0 ) {
				expect( medidas.resenas.texto.toLocaleLowerCase( 'es' ), 'el título de reseñas repite la etiqueta del panel' )
					.not.toBe( medidas.resenas.etiqueta.toLocaleLowerCase( 'es' ) );
			}
		}
	} );

	test( 'ficha a pedido: el CTA es el mismo rojo de compra, en el botón y en la barra', async ( { page } ) => {
		const url = await productoConBadge( page, 'A pedido' );
		test.skip( ! url, 'no hay ningún tomo a pedido en este catálogo' );
		await page.goto( /** @type {string} */ ( url ), { waitUntil: 'load' } );
		const atc = page.locator( 'form.cart .single_add_to_cart_button' );
		await expect( atc ).toHaveCSS( 'background-color', ROJO_COMPRA );
		const barra = page.locator( '#akb-sticky-atc-btn' );
		if ( await barra.count() ) {
			await expect( barra ).not.toHaveClass( /--preorder/ );
			await expect( barra ).toHaveCSS( 'background-color', ROJO_COMPRA );
		}
	} );

	test( 'tarjeta: con puntero, el salto deja una sombra que se ve', async ( { page }, testInfo ) => {
		test.skip( 'escritorio' !== testInfo.project.name, 'el hover sólo existe con puntero real' );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const li = page.locator( 'ul.products li.product' ).nth( 1 );
		await li.evaluate( ( e ) => e.scrollIntoView( { block: 'center' } ) );
		await page.waitForTimeout( 1200 );
		const t = await li.locator( '.woocommerce-loop-product__title' ).boundingBox();
		await page.mouse.move( ( t?.x || 0 ) + 20, ( t?.y || 0 ) + 5 );
		await page.waitForTimeout( 600 );
		const sombra = await li.evaluate( ( e ) => getComputedStyle( e ).boxShadow );
		const fondo = await page.evaluate( () => getComputedStyle( document.body ).backgroundColor );
		expect( sombra, 'la tarjeta tiene sombra al pasar el ratón' ).not.toBe( 'none' );
		expect( sombra.startsWith( fondo ), `la sombra (${ sombra }) no puede ser del color del fondo (${ fondo })` ).toBe( false );
	} );

	test( 'tarjeta: un clic dentro no la deja levantada; el foco de teclado sí la realza', async ( { page }, testInfo ) => {
		test.skip( 'escritorio' !== testInfo.project.name, 'el realce con puntero y teclado es de escritorio' );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const li = page.locator( 'ul.products li.product:has(.add_to_cart_button)' ).nth( 2 );
		await li.evaluate( ( e ) => e.scrollIntoView( { block: 'center' } ) );
		await page.waitForTimeout( 800 );
		const atc = li.locator( '.add_to_cart_button' );
		const bb = /** @type {{x:number,y:number,width:number,height:number}} */ ( await atc.boundingBox() );
		// Pulsar y soltar FUERA: no hay clic (nada se añade), pero el botón se queda
		// con el foco, que es lo que pasa tras un «Añadir» por AJAX o «Vista rápida».
		await page.mouse.move( bb.x + bb.width / 2, bb.y + bb.height / 2 );
		await page.mouse.down();
		await page.mouse.move( 5, 5, { steps: 4 } );
		await page.mouse.up();
		await page.waitForTimeout( 700 );
		const tras = await li.evaluate( ( e ) => ( { dentro: e.matches( ':focus-within' ), hover: e.matches( ':hover' ), sombra: getComputedStyle( e ).boxShadow, t: getComputedStyle( e ).transform } ) );
		test.skip( ! tras.dentro, 'este motor no enfoca el botón con el ratón (Safari): no hay nada que se quede pegado' );
		expect( tras.hover ).toBe( false );
		expect( tras.sombra, 'la tarjeta quedó levantada con la sombra roja tras un clic' ).toBe( 'none' );
		expect( tras.t ).toBe( 'none' );
		// Con teclado el realce sí tiene que estar.
		await page.keyboard.press( 'Tab' );
		await atc.focus();
		await page.waitForTimeout( 700 );
		const teclado = await li.evaluate( ( e ) => getComputedStyle( e ).boxShadow );
		expect( teclado, 'con el foco de teclado dentro, la tarjeta se realza' ).not.toBe( 'none' );
	} );

	test( 'foco de teclado de la cabecera: amarillo, sin outline rojo', async ( { page }, testInfo ) => {
		test.skip( 'escritorio' !== testInfo.project.name, 'el recorrido con Tab es de teclado físico' );
		// Sin transiciones: el anillo se lee ya pintado, no a medio aparecer.
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const vistos = [];
		for ( let i = 0; i < 12; i++ ) {
			await page.keyboard.press( 'Tab' );
			await page.waitForTimeout( 150 );
			const f = await page.evaluate( () => {
				const e = document.activeElement;
				if ( ! e || e === document.body || ! e.closest( '.site-header' ) ) {
					return null;
				}
				const c = getComputedStyle( e );
				return { clase: String( e.className ), outline: c.outlineStyle !== 'none' ? c.outlineColor : '', sombra: c.boxShadow };
			} );
			if ( f && ! /akibara-search__input/.test( f.clase ) ) {
				vistos.push( f );
			}
		}
		expect( vistos.length, 'la cabecera tiene controles enfocables' ).toBeGreaterThan( 2 );
		for ( const f of vistos ) {
			expect( f.outline, `${ f.clase }: outline rojo` ).not.toBe( 'rgb(230, 0, 18)' );
			expect( f.sombra.includes( AMARILLO ) || f.outline === AMARILLO, `${ f.clase }: sin foco amarillo (${ f.sombra })` ).toBe( true );
		}
	} );

	test( 'paginación: foco de teclado amarillo, distinto del hover; la página actual sin negrita falsa', async ( { page } ) => {
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const actual = page.locator( 'nav.woocommerce-pagination .page-numbers.current' );
		test.skip( ! ( await actual.count() ), 'el listado cabe en una página' );
		// DS-8: Bebas sólo existe en 400. El COLOR de la página actual no se mira
		// aquí: es de la regla de marca DS-3, que espera la decisión del dueño (T6-R4).
		await expect( actual ).toHaveCSS( 'font-weight', '400' );
		// T6-R3: el foco compartía la regla del hover (bloque rojo + sombra #121212
		// que tapaba el anillo global). Ahora es el anillo amarillo de la casa.
		const enlace = page.locator( 'nav.woocommerce-pagination a.page-numbers:not(.next):not(.prev)' ).first();
		await page.keyboard.press( 'Tab' );
		await enlace.focus();
		await page.waitForTimeout( 400 );
		const foco = await enlace.evaluate( ( e ) => ( { fv: e.matches( ':focus-visible' ), sombra: getComputedStyle( e ).boxShadow, fondo: getComputedStyle( e ).backgroundColor } ) );
		expect( foco.fv ).toBe( true );
		expect( foco.sombra, `sin anillo amarillo (${ foco.sombra })` ).toContain( AMARILLO );
		expect( foco.fondo, 'el foco de teclado no es el bloque rojo del hover' ).not.toBe( 'rgb(230, 0, 18)' );
	} );

	test( 'carrito lateral abierto: «saltar al contenido» se pinta encima', async ( { page }, testInfo ) => {
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const disparador = page.locator( 'movil' === testInfo.project.name ? '.bottom-nav__item--cart.akb-cart-trigger' : '.header-icon--cart.akb-cart-trigger' ).first();
		test.skip( ! ( await disparador.count() ), 'este entorno no tiene carrito lateral' );
		await disparador.click();
		await expect( page.locator( '.akb-cart-drawer' ) ).toHaveClass( /akb-open/ );
		// Con el carrito abierto el foco queda atrapado en el cajón: se pone el enlace
		// donde lo deja su :focus y se mira QUIÉN se pinta en ese punto (T6-R5: el
		// candado viejo comparaba contra un número copiado de akibara-business).
		await page.evaluate( () => {
			const s = /** @type {HTMLElement} */ ( document.querySelector( '.skip-link' ) );
			s.style.setProperty( 'left', '8px', 'important' );
			s.style.setProperty( 'top', '8px', 'important' );
		} );
		await page.waitForTimeout( 300 );
		const m = await page.evaluate( () => {
			const s = /** @type {HTMLElement} */ ( document.querySelector( '.skip-link' ) );
			const r = s.getBoundingClientRect();
			const arriba = document.elementFromPoint( r.left + r.width / 2, r.top + r.height / 2 );
			return {
				encima: !! arriba && ( arriba === s || s.contains( arriba ) ),
				zEnlace: +getComputedStyle( s ).zIndex,
				zCajon: +getComputedStyle( /** @type {Element} */ ( document.querySelector( '.akb-cart-drawer' ) ) ).zIndex,
			};
		} );
		expect( m.encima, `el carrito (${ m.zCajon }) tapa «saltar al contenido» (${ m.zEnlace })` ).toBe( true );
		expect( m.zEnlace ).toBeGreaterThan( m.zCajon );
	} );

	test( 'portada móvil: sólo el sello que es enlace lleva marco rojo, y se lee a 12 px', async ( { page }, testInfo ) => {
		test.skip( 'movil' !== testInfo.project.name, 'el marco de los sellos es de ≤768 px' );
		await page.goto( '/', { waitUntil: 'load' } );
		const sellos = await page.evaluate( () => [ ...document.querySelectorAll( '.akb-trust-item' ) ].map( ( e ) => ( {
			tag: e.tagName,
			borde: getComputedStyle( e ).borderTopColor,
			letras: [ ...e.querySelectorAll( 'strong, small' ) ].map( ( x ) => parseFloat( getComputedStyle( x ).fontSize ) ),
		} ) ) );
		test.skip( ! sellos.length, 'la portada de este entorno no trae sellos' );
		for ( const s of sellos ) {
			if ( 'A' !== s.tag ) {
				expect( s.borde, 'un sello que no es enlace no imita un botón' ).not.toBe( 'rgb(230, 0, 18)' );
			}
			for ( const px of s.letras ) {
				expect( px ).toBeGreaterThanOrEqual( 12 );
			}
		}
	} );
} );
