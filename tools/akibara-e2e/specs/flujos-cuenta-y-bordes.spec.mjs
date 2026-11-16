// @ts-check
/**
 * Flujos de cuenta y páginas de borde, en sólo lectura.
 *
 * Lo que un cliente encuentra cuando se sale del camino feliz: una URL que no
 * existe, un endpoint de Mi cuenta sin sesión, un acceso con la clave mala, un
 * enlace de correo caducado. Ninguna de estas pruebas escribe nada: corren
 * igual contra el espejo y contra producción.
 */

import { expect, test } from '@playwright/test';
import { erroresDePagina } from './soporte/tienda.mjs';

test.describe( 'páginas de borde', () => {

	test( 'una URL que no existe responde 404 de verdad y ofrece salida', async ( { page } ) => {
		const r = await page.goto( '/esta-pagina-no-existe-qa-' + 'x'.repeat( 6 ) + '/', { waitUntil: 'domcontentloaded' } );

		expect( r?.status(), 'un 200 con cara de 404 envenena el índice de Google' ).toBe( 404 );
		await expect( page.locator( 'a[href*="/tienda"]:visible, form[role="search"]:visible, input[type="search"]:visible' ).first() ).toBeVisible();
	} );

	test( 'un producto que no existe no es un 500', async ( { request } ) => {
		const r = await request.get( '/producto-que-jamas-existio-qa/' );
		expect( [ 404, 410 ] ).toContain( r.status() );
	} );

	test( 'una página de catálogo fuera de rango no es un 500 ni un 200 vacío', async ( { request } ) => {
		const r = await request.get( '/tienda/page/9999/' );
		expect( [ 404, 301, 302 ], `respondió ${ r.status() }` ).toContain( r.status() );
	} );

	for ( const ruta of [ '/robots.txt', '/sitemap_index.xml', '/llms.txt' ] ) {
		test( `${ ruta } responde y no es HTML de error`, async ( { request } ) => {
			const r = await request.get( ruta );
			expect( r.status(), ruta ).toBe( 200 );
			expect( ( await r.text() ).slice( 0, 200 ).toLowerCase() ).not.toContain( '<!doctype html' );
		} );
	}
} );

test.describe( 'mi cuenta · sin sesión', () => {

	for ( const endpoint of [ 'orders', 'edit-address', 'edit-account', 'rewards', 'coleccion', 'favoritos' ] ) {
		test( `/mi-cuenta/${ endpoint }/ sin sesión pide acceso, no revienta ni filtra nada`, async ( { page } ) => {
			const r = await page.goto( `/mi-cuenta/${ endpoint }/`, { waitUntil: 'domcontentloaded' } );

			expect( r?.status() ).toBeLessThan( 500 );
			// El muro de acceso pliega sus dos formularios en acordeones: los
			// campos existen pero NO son visibles hasta abrirlos (medido con la
			// captura el 2026-09-19). Lo que el cliente ve es la puerta.
			await expect( page.getByText( /Iniciar sesión con correo y contraseña/i ).first() ).toBeVisible();
			await expect( page.locator( 'input[name="username"]' ).first(), 'el formulario está en la página, plegado' ).toBeAttached();

			const cuerpo = await page.locator( 'body' ).innerText();
			expect( cuerpo, 'sin sesión no se ve el dato de nadie' ).not.toMatch( /Tu saldo|Pedido n\.º|Dirección de facturación:/ );
		} );
	}

	test( 'un acceso con la clave mala no dice si el usuario existe', async ( { page } ) => {
		test.skip( !! process.env.AKB_SOLO_LECTURA, 'enviar un formulario de acceso escribe en los registros del sitio' );

		await page.goto( '/mi-cuenta/', { waitUntil: 'domcontentloaded' } );

		// El muro de acceso ofrece Google primero y deja el correo y la contraseña
		// PLEGADOS: el cliente abre ese acordeón antes de escribir, y la prueba
		// también (sin esto esperaba un minuto por un campo que no se ve).
		const usuario = page.locator( 'input[name="username"]' ).first();
		if ( ! await usuario.isVisible() ) {
			await page.getByText( 'Iniciar sesión con correo y contraseña' ).first().click();
		}
		await expect( usuario ).toBeVisible();
		await page.fill( 'input[name="username"]', 'qa-no-existe@akibara.test' );
		await page.fill( 'input[name="password"]', 'clave-equivocada-qa' );
		await page.locator( 'button[name="login"]' ).first().click();

		const aviso = await page.locator( '.woocommerce-error, .woocommerce-notices-wrapper' ).first().innerText();
		expect( aviso, 'distinguir «no existe» de «clave mala» deja enumerar clientes' ).not.toMatch( /no está registrad|no existe|unknown/i );
	} );

	test( 'el enlace de reseña con un token inventado lo dice sin dar un 500', async ( { page } ) => {
		const r = await page.goto( '/resena/TokenInventadoParaElQA0123456789abcd/', { waitUntil: 'domcontentloaded' } );

		expect( r?.status() ).toBeLessThan( 500 );
		expect( await page.locator( 'body' ).innerText() ).toMatch( /caduc|ya se usó|no sirve|no es válido|no encontr/i );
	} );
} );

test.describe( 'ninguna plantilla tira errores al cargar', () => {

	for ( const [ nombre, ruta ] of [
		[ 'portada', '/' ],
		[ 'tienda', '/tienda/' ],
		[ 'resultados de búsqueda', '/tienda/?_buscar=one' ],
		[ 'carrito vacío', '/carrito/' ],
		[ 'mi cuenta', '/mi-cuenta/' ],
		[ 'programa de puntos', '/akibara-puntos/' ],
		[ 'editoriales', '/editoriales/' ],
	] ) {
		test( `${ nombre }: sin errores de JavaScript ni recursos propios rotos`, async ( { page, baseURL } ) => {
			const errores = erroresDePagina( page );
			const rotos = [];
			const host = new URL( String( baseURL ) ).host;

			page.on( 'response', ( res ) => {
				const u = new URL( res.url() );
				if ( u.host === host && res.status() >= 400 && ! /\/wp-json\/|admin-ajax/.test( u.pathname ) ) {
					rotos.push( `${ res.status() } ${ u.pathname }` );
				}
			} );

			const r = await page.goto( ruta, { waitUntil: 'load' } );
			test.skip( 404 === r?.status(), `${ ruta } no existe en este entorno` );
			await page.waitForTimeout( 1_500 );

			expect( errores, 'un error de JS no capturado apaga todo lo que venía detrás' ).toEqual( [] );
			expect( rotos, 'un recurso propio en 404 es una imagen rota o un script que no cargó' ).toEqual( [] );
		} );
	}
} );
