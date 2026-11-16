// @ts-check
/**
 * Accesibilidad de teclado y toque del tema (lote T5, 2026-09-23).
 *
 * Lo que fija, medido antes del cambio en el espejo (Chromium y WebKit):
 *  - AI-8: al tabular por la cabecera de escritorio cada padre abría su panel
 *    (focusin + :focus-within) y Tab recorría 19 enlaces de más. Ahora el padre
 *    es enlace + botón (patrón «disclosure»).
 *  - AI-4: en la hoja de búsqueda móvil el primer Escape cerraba la hoja entera
 *    aunque sólo se quería quitar la lista de sugerencias.
 *  - AI-12: «/» saltaba al buscador en cualquier página (WCAG 2.1.4).
 *  - AI-9: las preguntas frecuentes de la ficha salían con el anillo azul del
 *    navegador.
 *  - AI-7: migas de 16-18 px y etiquetas de la meta de 22 px de alto.
 *  - AI-11: `<nav role="dialog">` en el cajón, `aria-label` en un div sin rol en
 *    el pie, y spans vacíos de aria-describedby (ids repetidos) en la portada.
 *  - AI-5: el contacto iba al servidor con campos vacíos y volvía un aviso
 *    genérico, sin marcar ningún campo.
 *  - De paso: el «▶» decorativo de la cabecera entraba en el nombre de cada
 *    enlace («Inicio ▶»).
 *
 * Todo es de LECTURA: el formulario sólo se envía vacío o inválido, que ya no
 * sale del navegador; y si la validación fallara, sus dos salidas (REST y
 * admin-post) están cortadas en la prueba. Corre también contra producción.
 */

import { expect, test } from '@playwright/test';
import { cortarEnviosDeContacto } from './soporte/tienda.mjs';

/** Safari/WebKit en macOS: Tab sólo recorre campos; los enlaces van con Option+Tab. */
const tab = ( browserName ) => ( 'webkit' === browserName ? 'Alt+Tab' : 'Tab' );

/** Primera ficha del catálogo que tenga preguntas frecuentes y meta con enlaces. */
async function fichaConFaq( page ) {
	await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
	const enlaces = await page.locator( 'li.product a.woocommerce-LoopProduct-link' ).evaluateAll(
		( els ) => els.map( ( e ) => e.getAttribute( 'href' ) || '' ).filter( Boolean )
	);
	for ( const url of enlaces.slice( 0, 8 ) ) {
		const r = await page.goto( url, { waitUntil: 'load' } ).catch( () => null );
		if ( r && r.status() < 400 && await page.locator( '.product-faq__q' ).count() && await page.locator( '.product_meta a' ).count() ) {
			return url;
		}
	}
	return null;
}

/**
 * Alto de la franja que recibe el toque de un enlace, medido con
 * elementFromPoint (lo que cuenta es a quién llega el toque, no la caja).
 */
const altoDeToque = ( locator ) => locator.evaluate( ( a ) => {
	a.scrollIntoView( { block: 'center' } );
	const r = a.getBoundingClientRect();
	const x = r.left + Math.min( 8, r.width / 2 );
	let primero = null;
	let ultimo = null;
	for ( let dy = -24; dy <= r.height + 24; dy++ ) {
		const el = document.elementFromPoint( x, r.top + dy );
		if ( el && ( el === a || a.contains( el ) ) ) {
			if ( null === primero ) {
				primero = dy;
			}
			ultimo = dy;
		}
	}
	return null === primero ? 0 : ultimo - primero + 1;
} );

test.describe( 'teclado y toque (tema)', () => {

	test( 'AI-8: el menú de escritorio se abre con su botón, no al pasar el foco', async ( { page, isMobile, browserName } ) => {
		test.skip( isMobile, 'el menú de escritorio sólo existe desde 1025 px' );
		await page.goto( '/tienda/', { waitUntil: 'load' } );

		const item = page.locator( '.mainnav__item--has-sub' ).first();
		const enlace = item.locator( '> .mainnav__link' );
		const boton = item.locator( '> .mainnav__toggle' );
		const panel = item.locator( '> .mainnav__sub' );

		await expect( enlace ).not.toHaveAttribute( 'aria-haspopup', /.*/ );
		// El «▶» decorativo (::after) no entra en el nombre: antes era «Editoriales ▶».
		// textContent y no innerText: éste llega en MAYÚSCULAS por el text-transform.
		await expect( enlace ).toHaveAccessibleName( ( await enlace.textContent() ).trim() );
		await expect( boton ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( boton ).toHaveAttribute( 'aria-controls', await panel.getAttribute( 'id' ) || 'sin-id' );
		await expect( boton ).toHaveAccessibleName( /Submenú de/ );

		// Tab: enlace → botón, con el panel cerrado todo el rato.
		await enlace.focus();
		await expect( panel ).toBeHidden();
		await page.keyboard.press( tab( browserName ) );
		await expect( boton ).toBeFocused();
		await expect( panel ).toBeHidden();

		// Enter abre, Tab entra al panel, Escape cierra y devuelve el foco al botón.
		await page.keyboard.press( 'Enter' );
		await expect( boton ).toHaveAttribute( 'aria-expanded', 'true' );
		await expect( panel ).toBeVisible();
		await page.keyboard.press( tab( browserName ) );
		await expect( panel.locator( ':focus' ) ).toHaveCount( 1 );
		await page.keyboard.press( 'Escape' );
		await expect( panel ).toBeHidden();
		await expect( boton ).toBeFocused();
		await expect( boton ).toHaveAttribute( 'aria-expanded', 'false' );

		// El ratón sigue abriendo por hover.
		await page.mouse.move( 5, 600 );
		await enlace.hover();
		await expect( panel ).toBeVisible();
	} );

	test( 'T5-R1: con el ratón encima, el foco que sale deja el panel a la vista y abierto, y Escape lo oculta', async ( { page, isMobile, browserName } ) => {
		test.skip( isMobile, 'el menú de escritorio sólo existe desde 1025 px' );
		await page.goto( '/tienda/', { waitUntil: 'load' } );

		const item = page.locator( '.mainnav__item--has-sub' ).first();
		const enlace = item.locator( '> .mainnav__link' );
		const boton = item.locator( '> .mainnav__toggle' );
		const panel = item.locator( '> .mainnav__sub' );

		// Medido antes del arreglo (Chromium y WebKit): tras Shift+Tab el panel
		// seguía visible por :hover con aria-expanded="false", y Escape no lo cerraba.
		await page.mouse.move( 5, 600 );
		await enlace.hover();
		await expect( panel ).toBeVisible();
		await enlace.focus();
		await page.keyboard.press( 'webkit' === browserName ? 'Alt+Shift+Tab' : 'Shift+Tab' );
		await expect( enlace ).not.toBeFocused();
		await expect( panel ).toBeVisible();
		await expect( boton ).toHaveAttribute( 'aria-expanded', 'true' );

		await page.keyboard.press( 'Escape' );
		await expect( panel ).toBeHidden();
		await expect( boton ).toHaveAttribute( 'aria-expanded', 'false' );

		// Al salir el puntero se levanta el cierre a pedido: volver encima lo abre.
		await page.mouse.move( 5, 600 );
		await enlace.hover();
		await expect( panel ).toBeVisible();
	} );

	test( 'T5-R2: con el HTML cacheado de antes de los botones, el teclado conserva la apertura por foco', async ( { page, isMobile } ) => {
		test.skip( isMobile, 'el menú de escritorio sólo existe desde 1025 px' );
		// Lo que sirve LiteSpeed o Cloudflare hasta que se purga: el marcado del
		// walker anterior (aria-* en el <a>, sin <button>) con el main.js nuevo.
		await page.route( '**/tienda/', async ( route ) => {
			const res = await route.fetch();
			const html = ( await res.text() ).replace(
				/<a href="([^"]*)" class="mainnav__link([^"]*)"([^>]*)>([^<]*)<\/a><button type="button" class="mainnav__toggle" aria-expanded="false" aria-controls="([^"]+)" aria-label="[^"]*"><\/button>/g,
				'<a href="$1" class="mainnav__link$2"$3 aria-haspopup="true" aria-expanded="false" aria-controls="$5">$4</a>'
			);
			await route.fulfill( { response: res, body: html, headers: { ...res.headers(), 'content-length': undefined } } );
		} );
		await page.goto( '/tienda/', { waitUntil: 'load' } );

		await expect( page.locator( '.mainnav__toggle' ) ).toHaveCount( 0 );
		await expect( page.locator( 'nav.mainnav' ) ).not.toHaveClass( /\bmainnav--js\b/ );
		const item = page.locator( '.mainnav__item--has-sub' ).first();
		await page.mouse.move( 5, 600 );
		await item.locator( '> .mainnav__link' ).focus();
		await expect( item.locator( '> .mainnav__sub' ) ).toBeVisible();
	} );

	test( 'AI-12: «/» no es un atajo; Ctrl+K lleva al buscador', async ( { page, isMobile } ) => {
		test.skip( isMobile, 'el buscador de la cabecera es de escritorio' );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await page.locator( 'h1' ).first().click();
		await page.keyboard.press( '/' );
		await expect( page.locator( '#akb-search-input' ) ).not.toBeFocused();
		await page.keyboard.press( 'Control+k' );
		await expect( page.locator( '#akb-search-input' ) ).toBeFocused();
		await expect( page.locator( '#akb-search-input' ) ).toHaveAttribute( 'aria-keyshortcuts', 'Control+K Meta+K' );
	} );

	test( 'AI-4: en la hoja de búsqueda el primer Escape sólo cierra la lista', async ( { page, isMobile } ) => {
		test.skip( ! isMobile, 'la hoja de búsqueda es móvil' );
		await page.goto( '/', { waitUntil: 'load' } );
		const campo = page.locator( '#akb-mobile-search-input' );
		test.skip( null === await campo.getAttribute( 'data-akb-suggest' ), 'sin sugerencias activas no hay lista que cerrar' );

		await page.locator( '#bottom-nav-search' ).click();
		await expect( campo ).toBeFocused();
		await page.keyboard.type( 'one pi', { delay: 40 } );
		await expect( campo ).toHaveAttribute( 'aria-expanded', 'true', { timeout: 10_000 } );
		// Que termine de llegar la última sugerencia: una respuesta tardía reabre
		// la lista y el Escape de la prueba caería sobre la lista, no sobre la hoja.
		await page.waitForLoadState( 'networkidle' );

		await page.keyboard.press( 'Escape' );
		await expect( campo ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( page.locator( '#mobile-drawer' ) ).toHaveClass( /\bopen\b/ );
		await expect( campo ).toBeFocused();
		await expect( campo ).toHaveValue( 'one pi' );

		await page.keyboard.press( 'Escape' );
		await expect( page.locator( '#mobile-drawer' ) ).not.toHaveClass( /\bopen\b/ );
		await expect( page.locator( '#bottom-nav-search' ) ).toBeFocused();
	} );

	test( 'AI-11: el cajón es un diálogo con la navegación dentro', async ( { page, isMobile } ) => {
		test.skip( ! isMobile, 'el cajón es móvil' );
		await page.goto( '/', { waitUntil: 'load' } );
		const cajon = page.locator( '#mobile-drawer' );
		await expect( cajon ).toHaveJSProperty( 'tagName', 'DIV' );
		await expect( cajon ).toHaveAttribute( 'role', 'dialog' );
		await expect( cajon ).toHaveAttribute( 'aria-modal', 'true' );

		await page.locator( '#menu-toggle' ).click();
		await expect( cajon ).toHaveAccessibleName( 'Menú' );
		await expect( cajon.getByRole( 'navigation', { name: 'Menú principal' } ) ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( cajon ).not.toHaveClass( /\bopen\b/ );
		await expect( page.locator( '#menu-toggle' ) ).toBeFocused();
	} );

	test( 'AI-11: el bloque de datos del pie es un grupo con nombre y la portada no repite ids', async ( { page } ) => {
		await page.goto( '/', { waitUntil: 'load' } );
		await expect( page.locator( '.footer-nap' ) ).toHaveAttribute( 'role', 'group' );
		await expect( page.locator( '[id^="woocommerce_loop_add_to_cart_link_describedby_"]' ) ).toHaveCount( 0 );
		const repetidos = await page.evaluate( () => {
			const vistos = new Map();
			document.querySelectorAll( '[id]' ).forEach( ( el ) => vistos.set( el.id, ( vistos.get( el.id ) || 0 ) + 1 ) );
			return [ ...vistos ].filter( ( [ , n ] ) => n > 1 ).map( ( [ id ] ) => id );
		} );
		expect( repetidos, 'ids repetidos en la portada' ).toEqual( [] );
	} );

	test( 'AI-9 y AI-7: foco de marca en las preguntas y zonas táctiles de ≥24 px en la ficha', async ( { page, isMobile, browserName } ) => {
		const url = await fichaConFaq( page );
		test.skip( ! url, 'ninguna ficha de la primera página tiene preguntas frecuentes' );

		// Anillo de la casa (amarillo, hacia dentro) con foco de teclado.
		const pregunta = page.locator( '.product-faq__q' ).first();
		await pregunta.scrollIntoViewIfNeeded();
		await page.keyboard.press( tab( browserName ) );
		await pregunta.focus();
		const anillo = await pregunta.evaluate( ( el ) => getComputedStyle( el ).boxShadow );
		expect( anillo ).toMatch( /rgb\(255, 215, 0\)[^,]*inset/ );

		if ( isMobile ) {
			for ( const sel of [ '.woocommerce-breadcrumb a', '.product_meta a' ] ) {
				const enlaces = page.locator( sel );
				const total = Math.min( await enlaces.count(), 4 );
				for ( let i = 0; i < total; i++ ) {
					expect( await altoDeToque( enlaces.nth( i ) ), `${ sel } #${ i }` ).toBeGreaterThanOrEqual( 24 );
				}
			}
		}
	} );

	test( 'AI-5: el contacto no sale con campos vacíos y marca cada campo', async ( { page } ) => {
		const r = await page.goto( '/contacto/', { waitUntil: 'load' } );
		test.skip( ! r || r.status() >= 400 || ! await page.locator( 'form[data-akb-form="contacto"]' ).count(), 'sin formulario de contacto' );
		const cortados = await cortarEnviosDeContacto( page );

		const peticiones = [];
		page.on( 'request', ( req ) => {
			if ( req.url().includes( '/akibara/v1/contacto' ) || req.url().includes( 'admin-post.php' ) ) {
				peticiones.push( req.url() );
			}
		} );

		// Enviar con Enter desde el campo (envío implícito): es el camino del
		// teclado, y el botón no está «estable» para Playwright mientras corre su
		// animación de la casa.
		await page.locator( '#akb-c-email' ).fill( 'no-es-un-correo' );
		await page.locator( '#akb-c-email' ).press( 'Enter' );

		await expect( page.locator( '#akb-c-nombre' ) ).toBeFocused();
		for ( const id of [ 'akb-c-nombre', 'akb-c-email', 'akb-c-mensaje' ] ) {
			const campo = page.locator( `#${ id }` );
			await expect( campo ).toHaveAttribute( 'aria-invalid', 'true' );
			await expect( campo ).toHaveAttribute( 'aria-describedby', `${ id }-error` );
			await expect( page.locator( `#${ id }-error` ) ).not.toBeEmpty();
		}
		expect( peticiones, 'un formulario inválido no debe salir del navegador' ).toEqual( [] );
		expect( cortados ).toEqual( [] );

		// T5-R3: el texto nativo sale en el idioma de la interfaz del navegador,
		// no en el de la página: lleva lang. Un copy propio (AkibaraFormsConfig
		// .messages, de akibara-business) hereda el de la página.
		const idiomas = await page.evaluate( () => {
			const propios = Object.values( ( window.AkibaraFormsConfig && window.AkibaraFormsConfig.messages ) || {} );
			return [ ...document.querySelectorAll( '.akb-form__error' ) ].map( ( el ) => ( {
				id: el.id,
				propio: propios.includes( el.textContent ),
				lang: el.getAttribute( 'lang' ),
				navegador: navigator.language,
			} ) );
		} );
		expect( idiomas ).toHaveLength( 3 );
		for ( const m of idiomas ) {
			expect( m.lang, m.id ).toBe( m.propio ? null : m.navegador );
		}

		// Al corregir, el error de ese campo se va.
		await page.locator( '#akb-c-nombre' ).fill( 'Cliente de prueba' );
		await expect( page.locator( '#akb-c-nombre' ) ).not.toHaveAttribute( 'aria-invalid', /.*/ );
		await expect( page.locator( '#akb-c-nombre-error' ) ).toHaveCount( 0 );
	} );
} );
