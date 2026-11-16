// @ts-check
/**
 * El foco de teclado se VE (lote T6, revisión REV-T6-2, 2026-09-23).
 *
 * T6 unificó el foco de la casa en un anillo amarillo hecho con box-shadow. Una
 * revisión independiente encontró dos casos en que ese anillo desaparecía, y
 * ninguna prueba los veía porque ninguna mueve el ratón ni pide alto contraste:
 *
 * - REV-T6-2-1: colores forzados (alto contraste de Windows) ANULAN box-shadow.
 *   Con el `outline: none` de la regla global, 84 de 91 paradas de Tab de
 *   /mi-cuenta/ y una ficha quedaban sin indicador (antes de T6, 41): cabecera,
 *   menú, pie, barra inferior, sellos y la barra fija de compra.
 * - REV-T6-2-2: con el puntero ENCIMA del control, un `:hover` que apagaba la
 *   sombra le ganaba al anillo: menú de escritorio, títulos del pie y, con
 *   movimiento reducido, los iconos de la cabecera.
 * - Colores forzados, deuda previa hallada al medir: las líneas de la
 *   hamburguesa son fondos y el botón del menú móvil quedaba vacío.
 *
 * Todo es LECTURA (tabular, mover el ratón): vale contra el espejo y, tras
 * desplegar, contra producción en la suite entera (`npm run test:prod:completa`,
 * sólo con permiso del dueño): no lleva la etiqueta `@prod` de la prueba de humo.
 */

import { expect, test } from '@playwright/test';
import { productoConBadge } from './soporte/tienda.mjs';

const AMARILLO = 'rgb(255, 215, 0)'; // --aki-yellow (accent)
/** El amarillo en sombra: el navegador lo serializa `rgb()` o `rgba(…, 1)` según el control. */
const ANILLO = /rgba?\(255, 215, 0(, 1)?\)/;
/** El botón de Joinchat (terceros) flota sobre el pie: se oculta para que el puntero caiga en el control. */
const SIN_CHAT = '.joinchat{display:none!important}';

/** Enfoca el control ANTERIOR en el orden de tabulación y pulsa Tab: foco de teclado de verdad (:focus-visible). */
async function tabularHasta( page, control ) {
	await control.evaluate( ( e ) => {
		const todos = [ ...document.querySelectorAll( 'a[href],button,input,select,textarea,summary,[tabindex]' ) ]
			.filter( ( x ) => ! x.disabled && x.tabIndex >= 0 && ( null !== x.offsetParent || 'fixed' === getComputedStyle( x ).position ) );
		const i = todos.indexOf( e );
		( todos[ i - 1 ] || document.body ).focus();
	} );
	await page.keyboard.press( 'Tab' );
	// Esperar a que TERMINEN las transiciones del control (el box-shadow del tema dura
	// 250 ms): un tiempo fijo igual a la duración medía el anillo al 99,6 % de opacidad.
	await control.evaluate( ( e ) => Promise.all( e.getAnimations().map( ( a ) => a.finished.catch( () => {} ) ) ) );
}

/** Estado de foco del elemento activo. */
const focoActivo = ( page ) => page.evaluate( () => {
	const e = document.activeElement;
	if ( ! e || e === document.body ) {
		return null;
	}
	const c = getComputedStyle( e );
	return {
		quien: `${ e.tagName.toLowerCase() }.${ String( e.className ).trim().split( /\s+/ ).slice( 0, 2 ).join( '.' ) } «${ ( e.getAttribute( 'aria-label' ) || e.textContent || '' ).trim().slice( 0, 24 ) }»`,
		teclado: e.matches( ':focus-visible' ),
		contorno: 'none' !== c.outlineStyle && '0px' !== c.outlineWidth,
		outline: `${ c.outlineStyle } ${ c.outlineWidth } ${ c.outlineColor }`,
		sombra: c.boxShadow,
	};
} );

/** Una ficha con barra fija de compra, o null. */
async function fichaConBarra( page ) {
	for ( const palabra of [ 'A pedido', 'Disponible' ] ) {
		const url = await productoConBadge( page, palabra );
		if ( ! url ) {
			continue;
		}
		await page.goto( url, { waitUntil: 'load' } );
		if ( await page.locator( '#akb-sticky-atc-btn' ).count() ) {
			return url;
		}
	}
	return null;
}

test.describe( 'foco visible — colores forzados (REV-T6-2-1)', () => {
	test( 'cabecera, menú y pie: cada parada de Tab lleva contorno', async ( { page } ) => {
		await page.emulateMedia( { forcedColors: 'active', reducedMotion: 'reduce' } );
		await page.goto( '/mi-cuenta/', { waitUntil: 'load' } );
		expect( await page.evaluate( () => matchMedia( '(forced-colors: active)' ).matches ), 'colores forzados activos' ).toBe( true );
		const sin = [];
		const vistos = new Set();
		for ( let i = 0; i < 60; i++ ) {
			await page.keyboard.press( 'Tab' );
			await page.waitForTimeout( 60 );
			const f = await focoActivo( page );
			if ( ! f || vistos.has( f.quien ) ) {
				continue;
			}
			vistos.add( f.quien );
			if ( f.teclado && ! f.contorno ) {
				sin.push( f.quien );
			}
		}
		expect( vistos.size, 'el recorrido pasa por la página' ).toBeGreaterThan( 20 );
		expect( sin, 'controles enfocados sin indicador en alto contraste' ).toEqual( [] );
	} );

	test( 'controles fijos: iconos, redes, títulos del pie, barra inferior y barra de compra', async ( { page }, testInfo ) => {
		await page.emulateMedia( { forcedColors: 'active', reducedMotion: 'reduce' } );
		await page.goto( '/mi-cuenta/', { waitUntil: 'load' } );
		// En móvil los enlaces de columna del pie van dentro del acordeón cerrado.
		const selectores = 'movil' === testInfo.project.name
			? [ 'button.hamburger', 'a.header-icon--cart', 'a.bottom-nav__item', 'a.footer-social__link', 'button.footer-column__title' ]
			: [ 'a.header-icon--account', 'a.header-icon--cart', 'a.mainnav__link', 'a.footer-social__link', 'button.footer-column__title', 'a.footer-column__link' ];
		const sin = [];
		for ( const sel of selectores ) {
			const control = page.locator( sel ).first();
			if ( ! ( await control.isVisible() ) ) {
				sin.push( `${ sel }: no visible` );
				continue;
			}
			await tabularHasta( page, control );
			const f = await focoActivo( page );
			if ( ! f || ! f.teclado || ! f.contorno ) {
				sin.push( `${ sel }: ${ f ? f.outline : 'sin foco' }` );
			}
		}
		expect( sin ).toEqual( [] );

		const ficha = await fichaConBarra( page );
		test.skip( ! ficha, 'no hay ficha con barra de compra' );
		await page.mouse.wheel( 0, 2200 );
		const boton = page.locator( '#akb-sticky-atc-btn' );
		await expect( boton ).toBeVisible();
		await tabularHasta( page, boton );
		const f = await focoActivo( page );
		expect( f && f.teclado, 'la barra de compra recibe el foco de teclado' ).toBe( true );
		expect( f.contorno, `barra de compra sin contorno (${ f.outline })` ).toBe( true );
		const sello = page.locator( 'a.akb-badge-trust__link' ).first();
		if ( await sello.count() ) {
			await sello.scrollIntoViewIfNeeded();
			await tabularHasta( page, sello );
			const s = await focoActivo( page );
			expect( s.contorno, `sello sin contorno (${ s.outline })` ).toBe( true );
		}
	} );
} );

/** Luminancias de un recorte de la pantalla (decodifica el PNG en una hoja aparte). */
async function rangoDeLuminancia( page, locator ) {
	const png = await locator.screenshot();
	const hoja = await page.context().newPage();
	const rango = await hoja.evaluate( async ( b64 ) => {
		const bin = atob( b64 );
		const u8 = Uint8Array.from( bin, ( c ) => c.charCodeAt( 0 ) );
		const bmp = await createImageBitmap( new Blob( [ u8 ], { type: 'image/png' } ) );
		const lienzo = new OffscreenCanvas( bmp.width, bmp.height );
		const g = lienzo.getContext( '2d' );
		g.drawImage( bmp, 0, 0 );
		const d = g.getImageData( 0, 0, bmp.width, bmp.height ).data;
		let min = 255;
		let max = 0;
		for ( let i = 0; i < d.length; i += 4 ) {
			const l = 0.2126 * d[ i ] + 0.7152 * d[ i + 1 ] + 0.0722 * d[ i + 2 ];
			min = Math.min( min, l );
			max = Math.max( max, l );
		}
		return max - min;
	}, png.toString( 'base64' ) );
	await hoja.close();
	return rango;
}

test.describe( 'botón del menú móvil — colores forzados', () => {
	test( 'las tres líneas se ven en alto contraste claro y oscuro', async ( { page }, testInfo ) => {
		test.skip( 'movil' !== testInfo.project.name, 'la hamburguesa sólo existe hasta 1024 px' );
		// Las líneas son fondos: el navegador los reemplazaba y el botón quedaba vacío
		// (rango de luminancia 0 en el recorte, medido el 2026-09-23).
		for ( const esquema of [ 'light', 'dark' ] ) {
			await page.emulateMedia( { forcedColors: 'active', colorScheme: esquema, reducedMotion: 'reduce' } );
			await page.goto( '/mi-cuenta/', { waitUntil: 'load' } );
			await page.mouse.move( 0, 0 );
			const boton = page.locator( 'button.hamburger' );
			await expect( boton ).toBeVisible();
			expect( await rangoDeLuminancia( page, boton ), `hamburguesa vacía en colores forzados (${ esquema })` ).toBeGreaterThan( 100 );
		}
	} );
} );

test.describe( 'foco visible — con el puntero encima (REV-T6-2-2)', () => {
	for ( const movimiento of [ 'no-preference', 'reduce' ] ) {
		test( `el anillo amarillo no se apaga por el hover (movimiento: ${ movimiento })`, async ( { page }, testInfo ) => {
			await page.emulateMedia( { reducedMotion: movimiento } );
			const casos = 'movil' === testInfo.project.name
				? [ [ '/mi-cuenta/', 'a.header-icon--cart' ], [ '/mi-cuenta/', 'button.hamburger' ], [ '/mi-cuenta/', 'button.footer-column__title' ] ]
				: [ [ '/tienda/', 'a.mainnav__link' ], [ '/tienda/', 'a.header-icon--account' ], [ '/tienda/', 'a.header-icon--cart' ], [ '/mi-cuenta/', 'button.footer-column__title' ] ];
			let ruta = '';
			const sin = [];
			for ( const [ url, sel ] of casos ) {
				if ( url !== ruta ) {
					await page.goto( url, { waitUntil: 'load' } );
					await page.addStyleTag( { content: SIN_CHAT } );
					ruta = url;
				}
				const control = page.locator( sel ).first();
				// Al centro: así enfocar el control anterior no desplaza la página bajo el puntero.
				await control.evaluate( ( e ) => e.scrollIntoView( { block: 'center' } ) );
				const caja = await control.boundingBox();
				await page.mouse.move( caja.x + Math.min( 20, caja.width / 2 ), caja.y + caja.height / 2 );
				await page.waitForTimeout( 150 );
				await tabularHasta( page, control );
				const f = await control.evaluate( ( e ) => {
					const c = getComputedStyle( e );
					return { hover: e.matches( ':hover' ), teclado: e.matches( ':focus-visible' ), sombra: c.boxShadow, outline: 'none' !== c.outlineStyle ? c.outlineColor : '' };
				} );
				expect( f.hover && f.teclado, `${ sel }: el caso exige puntero encima Y foco de teclado` ).toBe( true );
				if ( ! ANILLO.test( f.sombra ) && AMARILLO !== f.outline ) {
					sin.push( `${ sel }: sombra=${ f.sombra } outline=${ f.outline || 'none' }` );
				}
				await page.mouse.move( 1, 1 );
			}
			expect( sin, 'con el puntero encima, el foco de teclado desaparece' ).toEqual( [] );
		} );
	}

	test( 'sólo el puntero (sin teclado) no pinta marco', async ( { page }, testInfo ) => {
		test.skip( 'escritorio' !== testInfo.project.name, 'hover de ratón' );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		for ( const sel of [ 'a.mainnav__link', 'a.header-icon--account' ] ) {
			const control = page.locator( sel ).first();
			await control.hover();
			await page.waitForTimeout( 150 );
			const f = await control.evaluate( ( e ) => ( { hover: e.matches( ':hover' ), sombra: getComputedStyle( e ).boxShadow, outline: getComputedStyle( e ).outlineStyle } ) );
			expect( f.hover ).toBe( true );
			expect( f.sombra, `${ sel }: el hover no es un foco` ).toBe( 'none' );
			expect( f.outline ).toBe( 'none' );
		}
	} );
} );
