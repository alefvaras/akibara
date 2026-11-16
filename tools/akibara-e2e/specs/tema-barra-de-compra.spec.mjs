// @ts-check
/**
 * Barra de compra fija de la ficha y botón de WhatsApp (lote T2, 2026-09-22).
 *
 * Lo que fija, medido antes del cambio en el espejo y en producción:
 *  - UM-7: al cargar la ficha en 390x844 la barra tapaba el H1 (H1 693-726,
 *    barra desde 716).
 *  - UM-2: con «Arma tu pack» en pantalla la barra se apagaba y quedaban
 *    ~1.800 px sin ningún CTA usable (el del pack, deshabilitado).
 *  - UC-4: en 1366x768 el botón de la ficha a pedido quedaba bajo el pliegue y
 *    en escritorio no había barra.
 *  - UM-16: el botón de WhatsApp caía sobre «Añadir al carrito» en los listados.
 *
 * Todo es de LECTURA: elegir tomos en el armador no envía nada al servidor, así
 * que vale también contra producción, en la suite entera
 * (`npm run test:prod:completa`, sólo con permiso del dueño): no lleva la
 * etiqueta `@prod` de la prueba de humo.
 */

import { expect, test } from '@playwright/test';
import { fichaConArmador } from './soporte/tienda.mjs';

const barraVisible = ( page ) => page.evaluate( () => {
	const b = document.getElementById( 'akb-sticky-atc' );
	return !! b && b.classList.contains( 'is-visible' ) && 'hidden' !== getComputedStyle( b ).visibility;
} );

/** ¿La barra (visible) pisa el H1? */
const tapaElTitulo = ( page ) => page.evaluate( () => {
	const b = document.getElementById( 'akb-sticky-atc' );
	const h = document.querySelector( '.product_title' );
	if ( ! b || ! h || ! b.classList.contains( 'is-visible' ) ) {
		return false;
	}
	const rb = b.getBoundingClientRect();
	const rh = h.getBoundingClientRect();
	return rh.bottom > rb.top && rh.top < rb.bottom;
} );

const irA = async ( page, y ) => {
	await page.evaluate( ( yy ) => window.scrollTo( 0, yy ), y );
	await page.waitForTimeout( 350 );
};

test.describe( 'barra de compra de la ficha', () => {
	test( 'al cargar no tapa el título, y aparece en cuanto el título la deja atrás', async ( { page } ) => {
		test.skip( ! await fichaConArmador( page ), 'ninguna de las primeras fichas del catálogo tiene armador' );

		await page.waitForTimeout( 500 );
		expect( await tapaElTitulo( page ), 'UM-7: la barra tapaba el nombre del tomo en el primer pantallazo' ).toBe( false );

		// Bajar hasta que el botón real salga de la vista: la barra tiene que estar.
		const yForm = await page.evaluate( () => Math.round( document.querySelector( 'form.cart' ).getBoundingClientRect().bottom + scrollY ) );
		await irA( page, yForm + 50 );
		expect( await barraVisible( page ) ).toBe( true );
		expect( await tapaElTitulo( page ) ).toBe( false );
	} );

	test( 'en un portátil de 1366x768 la compra está en el primer pantallazo', async ( { page }, info ) => {
		test.skip( 'escritorio' !== info.project.name, 'geometría de escritorio' );
		await page.setViewportSize( { width: 1366, height: 768 } );
		test.skip( ! await fichaConArmador( page ), 'ninguna de las primeras fichas del catálogo tiene armador' );
		await page.waitForTimeout( 500 );

		const primerPantallazo = await page.evaluate( () => {
			const real = document.querySelector( 'form.cart .single_add_to_cart_button' ).getBoundingClientRect();
			const b = document.getElementById( 'akb-sticky-atc' );
			const barra = b.classList.contains( 'is-visible' ) ? b.getBoundingClientRect() : null;
			const techo = barra ? barra.top : innerHeight;
			return { realEntero: real.top >= 0 && real.bottom <= techo, barra: !! barra };
		} );
		expect( primerPantallazo.realEntero || primerPantallazo.barra, 'UC-4: ni el botón real ni la barra a la vista' ).toBe( true );
		expect( await tapaElTitulo( page ) ).toBe( false );

		// Al fondo, la barra está (el form quedó arriba) y el pie no queda debajo.
		await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight ) );
		await page.waitForTimeout( 400 );
		const fondo = await page.evaluate( () => {
			const b = document.getElementById( 'akb-sticky-atc' ).getBoundingClientRect();
			const pie = document.querySelector( '.site-footer' ).getBoundingClientRect();
			return { visible: document.getElementById( 'akb-sticky-atc' ).classList.contains( 'is-visible' ), barTop: b.top, pieBottom: pie.bottom };
		} );
		expect( fondo.visible ).toBe( true );
		expect( fondo.pieBottom, 'el final del pie no puede quedar bajo la barra' ).toBeLessThanOrEqual( fondo.barTop + 1 );
	} );
} );

test.describe( 'botón de WhatsApp en los listados', () => {
	test( 'en reposo nunca queda encima de un «Añadir al carrito»', async ( { page }, info ) => {
		test.skip( 'movil' !== info.project.name, 'el choque medido es en móvil' );
		test.setTimeout( 120_000 );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		// Joinchat aparece a los 8 s.
		await page.mouse.move( 10, 300 );
		const fab = page.locator( '.joinchat.joinchat--show' );
		await fab.waitFor( { state: 'attached', timeout: 20_000 } ).catch( () => {} );
		test.skip( ! ( await fab.count() ), 'Joinchat no se muestra en este entorno' );

		const alto = await page.evaluate( () => document.documentElement.scrollHeight - innerHeight );
		const tapadas = [];
		for ( let y = 0; y < Math.min( alto, 4000 ); y += 100 ) {
			await irA( page, y );
			const tapa = await page.evaluate( () => {
				const botones = Array.from( document.querySelectorAll( 'li.product .button' ) );
				return botones.some( ( b ) => {
					const r = b.getBoundingClientRect();
					if ( r.bottom < 0 || r.top > innerHeight ) {
						return false;
					}
					return [ 4, 12, 24 ].some( ( dx ) => {
						const el = document.elementFromPoint( r.right - dx, r.top + r.height / 2 );
						return !! ( el && el.closest( '.joinchat' ) );
					} );
				} );
			} );
			if ( tapa ) {
				tapadas.push( y );
			}
		}
		expect( tapadas, 'UM-16: posiciones de scroll con el botón de WhatsApp sobre una compra' ).toEqual( [] );
	} );
} );
