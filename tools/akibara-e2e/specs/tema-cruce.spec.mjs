// @ts-check
/**
 * Lote de cruce X1 (2026-09-24): lo que cruzaba del tema a akibara-business y
 * quedó sin hacer en la fase de implementación.
 *
 * - Canalón de las landings sin barra de filtros (/serie/*, /autor/*): de 769 a
 *   1439 px la columna —«N productos · Ordenar», la rejilla, la caja «Completa tu
 *   colección»— arrancaba en x = 0; y a cualquier ancho la fila «título + orden»
 *   del autor (página 2, con orden o filtro) y los chips de filtros iban sin
 *   margen. Ahora 20 px por lado hasta que la columna llega a 1400 px. En esa
 *   fila del autor el canalón no se suma entre el título y el total (revisión
 *   X1-1: llegaba a 558 px a 1920 y a 2560 partía la fila), y si un título largo
 *   manda el orden a la línea siguiente, éste conserva su canalón.
 * - T4: /contacto/ y /editoriales/ encolan desde business la copia `.min` de sus
 *   hojas y guiones del tema (antes siempre la fuente comentada).
 * - PC-4: Bebas Neue se declara una sola vez (theme.json).
 * - El botón de WhatsApp cede ante la bandeja «Completa tu colección» de la
 *   landing de serie en escritorio (main.js): al abrirla quedaba en reposo sobre
 *   «Agregar seleccionados» (1024, 1280, 1366, 1536) y sobre su «×» (1366).
 * - La cabecera compacta no hace temblar la página si el scroll se detiene justo
 *   pasado su umbral (main.js, histéresis 120/60).
 * - El refresco de fragments de WooCommerce no le quita el foco de teclado al
 *   icono del carrito (main.js lo devuelve al icono nuevo).
 *
 * Revisión X1-2 (ronda 2):
 * - D1: al cerrar el cajón después de un cambio dentro, el foco vuelve al icono
 *   del carrito (native-cart.js de akibara-business: el icono ya era otro nodo).
 * - D2 y D3: WhatsApp cede también ante «Elegir tomos» y ante el «Seleccionar» de
 *   las tarjetas con la bandeja abierta (main.js); y NO ante el «Agotado»
 *   deshabilitado, que no hace nada al pulsarlo (REV-X1-2b-D1).
 * - D4: la cabecera compacta encoge 26 px, no 8; el hueco entre umbrales los cubre
 *   al compactarse y al volver a crecer.
 *
 * Vale en el espejo y, con `AKB_SOLO_LECTURA=1`, en producción: lo que escribe
 * (el carrito de la prueba del cajón, los fragments por POST) se salta allí.
 */

import { expect, test } from '@playwright/test';
import { cortarEnviosDeContacto, erroresDePagina, soloLectura } from './soporte/tienda.mjs';
import { borrarSesionWcAlTerminar } from './soporte/tema-espejo.mjs';

// La prueba del cajón (REV-X1-2-D1) agrega y quita un tomo: la sesión de invitado
// que abre se quedaba en `wp_woocommerce_sessions` del espejo con el tomo en
// `removed_cart_contents` (medido el 2026-09-25: una fila por corrida). Se borra
// al terminar cada prueba del fichero, pase o falle.
borrarSesionWcAlTerminar();

/**
 * Bordes izquierdo/derecho (px CSS, redondeados) de lo que casa con cada selector.
 *
 * Espera a que TODAS las hojas estén aplicadas: el tema difiere las no críticas
 * (`media="print"` + `onload`) y, con la máquina cargada, medir en cuanto llega
 * el HTML daba el relleno de 30 px de GeneratePress en vez del del tema.
 */
async function bordes( page, selectores ) {
	await page.waitForFunction( () => [ ...document.querySelectorAll( 'link[rel="stylesheet"]' ) ].every( ( l ) => 'print' !== l.media && l.sheet ) );
	return page.evaluate( ( sel ) => {
		const vw = document.documentElement.clientWidth;
		const out = {};
		for ( const [ k, s ] of Object.entries( sel ) ) {
			const e = document.querySelector( s );
			if ( ! e || ! e.getBoundingClientRect().width ) {
				continue;
			}
			const r = e.getBoundingClientRect();
			out[ k ] = [ Math.round( r.left ), Math.round( vw - r.right ) ];
		}
		return { vw, sw: document.documentElement.scrollWidth, ...out };
	}, selectores );
}

/**
 * La fila «título + orden» del autor: rectángulos sin redondear, el hueco de la
 * fila (column-gap) y el relleno derecho de la cabecera.
 *
 * `titulo`: si se pasa, sustituye el H1 antes de medir (sólo en el DOM de la
 * pestaña: vale en producción) para forzar que la fila de orden baje de línea.
 */
async function filaDelAutor( page, titulo ) {
	await page.waitForFunction( () => [ ...document.querySelectorAll( 'link[rel="stylesheet"]' ) ].every( ( l ) => 'print' !== l.media && l.sheet ) );
	return page.evaluate( ( nuevo ) => {
		const h1 = document.querySelector( '.entry-content > .woocommerce-products-header > .page-title' );
		if ( nuevo && h1 ) {
			h1.textContent = nuevo;
		}
		const caja = ( s ) => {
			const e = document.querySelector( s );
			if ( ! e ) {
				return null;
			}
			const r = e.getBoundingClientRect();
			return { l: r.left, r: r.right, t: r.top, b: r.bottom };
		};
		const cabecera = document.querySelector( '.entry-content > .woocommerce-products-header' );
		return {
			vw: document.documentElement.clientWidth,
			sw: document.documentElement.scrollWidth,
			fila: getComputedStyle( document.querySelector( '.entry-content' ) ).display,
			hueco: parseFloat( getComputedStyle( document.querySelector( '.entry-content' ) ).columnGap ) || 0,
			relleno: cabecera ? parseFloat( getComputedStyle( cabecera ).paddingRight ) : 0,
			cabecera: caja( '.entry-content > .woocommerce-products-header' ),
			h1: caja( '.entry-content > .woocommerce-products-header > .page-title' ),
			orden: caja( '.entry-content > .akb-shop-toolbar' ),
			conteo: caja( '.entry-content > .akb-shop-toolbar .akb-result-count' ),
		};
	}, titulo || '' );
}

const COLUMNA = {
	toolbar: '.entry-content > .akb-shop-toolbar',
	rejilla: '.entry-content > ul.products',
	caja: '.entry-content > .akb-sb-landing',
	paginacion: '.entry-content > .woocommerce-pagination',
};

test.describe( 'canalón de las landings sin barra de filtros', () => {
	test( 'de 769 a 1600 px la columna de serie y autor deja 20 px por lado (o se centra en 1400)', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'el canalón es de escritorio; en móvil las portadas van a sangre a propósito' );
		test.setTimeout( 120_000 );
		for ( const ruta of [ '/serie/jujutsu-kaisen/', '/autor/eiichiro-oda/' ] ) {
			for ( const ancho of [ 769, 1024, 1366, 1440, 1600 ] ) {
				await page.setViewportSize( { width: ancho, height: 900 } );
				const r = await page.goto( ruta, { waitUntil: 'load' } );
				expect( r?.status(), ruta ).toBe( 200 );
				const b = await bordes( page, COLUMNA );
				const canal = Math.max( 20, Math.round( ( b.vw - 1400 ) / 2 ) );
				expect( b.sw, `${ ruta } ${ ancho }: sin desplazamiento lateral` ).toBeLessThanOrEqual( b.vw );
				for ( const k of [ 'toolbar', 'rejilla', 'caja', 'paginacion' ] ) {
					if ( b[ k ] ) {
						expect( b[ k ], `${ ruta } ${ ancho }: ${ k }` ).toEqual( [ canal, canal ] );
					}
				}
				expect( b.rejilla, `${ ruta } ${ ancho }: hay rejilla` ).toBeTruthy();
			}
		}
	} );

	test( 'la fila «título + orden» del autor (página 2) y los chips de filtros llevan el mismo canalón', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'la fila en línea es de ≥ 1025 px' );
		for ( const ancho of [ 1366, 1600 ] ) {
			await page.setViewportSize( { width: ancho, height: 900 } );
			await page.goto( '/autor/eiichiro-oda/page/2/', { waitUntil: 'load' } );
			const fila = await bordes( page, { migas: '.entry-content > .woocommerce-breadcrumb', titulo: '.entry-content > .woocommerce-products-header', orden: '.entry-content > .akb-shop-toolbar', rejilla: '.entry-content > ul.products' } );
			const canal = Math.max( 20, Math.round( ( fila.vw - 1400 ) / 2 ) );
			expect( fila.titulo[ 0 ], `${ ancho }: el título arranca en la columna` ).toBe( canal );
			expect( fila.orden[ 1 ], `${ ancho }: el orden termina en la columna` ).toBe( canal );
			expect( fila.rejilla ).toEqual( [ canal, canal ] );

			await page.goto( '/serie/jujutsu-kaisen/?_disponibilidad=instock', { waitUntil: 'load' } );
			const chips = await bordes( page, { chips: '.entry-content > .akb-facet-chips' } );
			test.skip( ! chips.chips, 'sin chips de filtros en esta serie' );
			expect( chips.chips, `${ ancho }: chips de filtros` ).toEqual( [ canal, canal ] );
		}
	} );

	// Revisión X1-1, D1: el canalón iba a los dos lados de la cabecera y de la fila
	// de orden, y en la fila en línea se sumaban entre el H1 y «N productos»: 78 px
	// de 1025 a 1440, 238 a 1600, 558 a 1920, y a 2560 la fila de orden bajaba de
	// línea. Página 2, con orden y sin resultados (?_disponibilidad=preventa: si el
	// autor llegara a tener preventas, la fila es la misma con otro total).
	test( 'en la fila «título + orden» del autor el total va junto al título a cualquier ancho', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'la fila en línea es de ≥ 1025 px' );
		test.setTimeout( 120_000 );
		for ( const ruta of [ '/autor/eiichiro-oda/page/2/', '/autor/eiichiro-oda/?orderby=price', '/autor/eiichiro-oda/?_disponibilidad=preventa' ] ) {
			for ( const ancho of [ 1366, 1440, 1600, 1920, 2560 ] ) {
				await page.setViewportSize( { width: ancho, height: 900 } );
				await page.goto( ruta, { waitUntil: 'load' } );
				const f = await filaDelAutor( page );
				const donde = `${ ruta } ${ ancho }`;
				expect( f.fila, `${ donde }: la cabecera va en una fila` ).toBe( 'flex' );
				expect( f.h1 && f.conteo && f.orden && f.cabecera, `${ donde }: título, total y orden` ).toBeTruthy();
				const centro = ( c ) => ( c.t + c.b ) / 2;
				expect( Math.abs( centro( f.orden ) - centro( f.cabecera ) ), `${ donde }: el orden comparte la línea del título` ).toBeLessThanOrEqual( 1 );
				const separacion = f.conteo.l - f.h1.r;
				expect( separacion, `${ donde }: el total no pisa el título` ).toBeGreaterThanOrEqual( f.hueco );
				expect( separacion, `${ donde }: entre el título y el total sólo el relleno de la cabecera y el hueco de la fila` ).toBeLessThanOrEqual( f.hueco + f.relleno + 1 );
				const canal = Math.max( 20, ( f.vw - 1400 ) / 2 );
				expect( Math.abs( f.vw - f.orden.r - canal ), `${ donde }: el orden termina en la columna` ).toBeLessThanOrEqual( 1 );
				expect( f.sw, `${ donde }: sin desplazamiento lateral` ).toBeLessThanOrEqual( f.vw );
			}
		}
	} );

	// Si el título no cabe junto al orden, éste baja de línea y arranca solo: tiene
	// que llevar su canalón (sin él, «N productos» quedaba pegado al borde de la
	// ventana), y la cabecera sola no puede salirse de la columna. A 1025 el título
	// ni siquiera cabe en una línea: es el caso en que la cabecera encoge.
	test( 'si el título es largo la fila de orden baja de línea y conserva el canalón', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'la fila en línea es de ≥ 1025 px' );
		for ( const ancho of [ 1025, 1366, 1920 ] ) {
			await page.setViewportSize( { width: ancho, height: 900 } );
			await page.goto( '/autor/eiichiro-oda/page/2/', { waitUntil: 'load' } );
			const f = await filaDelAutor( page, 'Un autor con un nombre larguísimo que no cabe junto al orden de la fila' );
			const canal = Math.max( 20, ( f.vw - 1400 ) / 2 );
			expect( f.orden.t, `${ ancho }: el orden bajó de línea` ).toBeGreaterThanOrEqual( f.cabecera.b );
			expect( Math.abs( f.orden.l - canal ), `${ ancho }: el orden arranca en la columna` ).toBeLessThanOrEqual( 1 );
			expect( Math.abs( f.cabecera.l - canal ), `${ ancho }: la cabecera arranca en la columna` ).toBeLessThanOrEqual( 1 );
			expect( f.vw - f.cabecera.r, `${ ancho }: la cabecera no se sale de la columna` ).toBeGreaterThanOrEqual( canal - 1 );
			expect( f.sw, `${ ancho }: sin desplazamiento lateral` ).toBeLessThanOrEqual( f.vw );
		}
	} );

	test( 'en móvil la rejilla de la serie sigue a sangre', async ( { page }, info ) => {
		test.skip( 'movil' !== info.project.name, 'sólo móvil' );
		await page.goto( '/serie/jujutsu-kaisen/', { waitUntil: 'load' } );
		const b = await bordes( page, { rejilla: '.entry-content > ul.products' } );
		expect( b.rejilla ).toEqual( [ 0, 0 ] );
		expect( b.sw ).toBeLessThanOrEqual( b.vw );
	} );

	test( 'el sizes de las portadas de la serie coincide con el ancho pintado', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'los tramos que cambiaron son de escritorio' );
		for ( const ancho of [ 1024, 1366 ] ) {
			await page.setViewportSize( { width: ancho, height: 900 } );
			await page.goto( '/serie/jujutsu-kaisen/', { waitUntil: 'load' } );
			const { sizes, ancho: pintado, vw } = await page.locator( '.entry-content > ul.products li.product img' ).first().evaluate( ( i ) => ( { sizes: i.getAttribute( 'sizes' ) || '', ancho: i.getBoundingClientRect().width, vw: document.documentElement.clientWidth } ) );
			// El tramo que aplica a este ancho: el primero cuyo max-width lo cubre.
			const tramo = sizes.replace( /^auto,\s*/, '' ).split( ', ' ).find( ( t ) => { const m = /^\(max-width: (\d+)px\)/.exec( t ); return ! m || ancho <= Number( m[ 1 ] ); } ) || '';
			const m = /calc\(\(100vw - (\d+)px\) \/ (\d+) - (\d+)px\)/.exec( tramo );
			expect( m, `${ ancho }: tramo ${ tramo }` ).toBeTruthy();
			const declarado = ( ancho - Number( m?.[ 1 ] ) ) / Number( m?.[ 2 ] ) - Number( m?.[ 3 ] );
			// Con barra de desplazamiento real el ancho pintado queda por debajo (la dirección segura).
			expect( declarado - pintado, `${ ancho }: declarado ${ declarado } / pintado ${ pintado } (vw ${ vw })` ).toBeGreaterThanOrEqual( -0.5 );
			expect( declarado - pintado, `${ ancho }: declarado ${ declarado } / pintado ${ pintado } (vw ${ vw })` ).toBeLessThanOrEqual( ancho - vw + 0.5 );
		}
	} );
} );

test.describe( 'T4: copias .min que encola akibara-business', () => {
	test( 'el directorio de editoriales sirve brands-filter.min.js fuera de LiteSpeed y su filtro funciona', async ( { page } ) => {
		const r = await page.goto( '/editoriales/', { waitUntil: 'load' } );
		expect( r?.status() ).toBe( 200 );
		const src = await page.locator( 'script#akibara-brands-filter-js' ).getAttribute( 'src' );
		expect( src ).toMatch( /\/assets\/js\/akibara-brands-filter\.min\.js\?ver=/ );
		await expect( page.locator( 'script#akibara-brands-filter-js' ) ).toHaveAttribute( 'data-no-optimize', '1' );
		const visibles = () => page.locator( '.akb-brand-card' ).evaluateAll( ( cs ) => cs.filter( ( c ) => null !== c.offsetParent ).length );
		const total = await visibles();
		await page.locator( '.akb-brands-filterbar__input' ).fill( 'zzzz-sin-editorial' );
		await expect.poll( visibles ).toBe( 0 );
		await expect( page.locator( '.akb-brands-empty' ) ).toBeVisible();
		await page.goto( '/editoriales/?region=argentina', { waitUntil: 'load' } );
		await expect( page.locator( '.akb-brands-chip--active' ).first() ).toHaveAttribute( 'data-akb-region', 'argentina' );
		expect( await visibles() ).toBeLessThan( total );
	} );

	test( 'el formulario de contacto carga su guion y valida sin enviar', async ( { page } ) => {
		const errores = erroresDePagina( page );
		const r = await page.goto( '/contacto/', { waitUntil: 'load' } );
		expect( r?.status() ).toBe( 200 );
		// Se pulsa el envío real: si akibara-forms.js pusiera `novalidate` y no llegara
		// a enganchar su `submit`, el envío nativo iría a admin-post.php sin validar, y
		// en sólo lectura, a producción (REV-SE-2). Cortado aquí, no sale nada.
		const cortados = await cortarEnviosDeContacto( page );
		// akibara-forms.js pone `novalidate` sólo si llegó a inicializar.
		await expect( page.locator( 'form[data-akb-form]' ).first() ).toHaveAttribute( 'novalidate', '' );
		await page.locator( 'form[data-akb-form] .akb-form__submit' ).first().click();
		await expect( page.locator( 'form[data-akb-form] [aria-invalid="true"]' ) ).not.toHaveCount( 0 );
		expect( errores ).toEqual( [] );
		expect( cortados, 'un formulario vacío no sale del navegador' ).toEqual( [] );
	} );
} );

test( 'PC-4: Bebas Neue se declara una sola vez y el título la pinta', async ( { page } ) => {
	await page.goto( '/', { waitUntil: 'load' } );
	const r = await page.evaluate( async () => {
		await document.fonts.ready;
		let caras = 0;
		for ( const s of document.styleSheets ) {
			let reglas = [];
			try {
				reglas = [ ...s.cssRules ];
			} catch ( e ) {
				continue;
			}
			caras += reglas.filter( ( x ) => 'CSSFontFaceRule' === x.constructor.name && /Bebas Neue/.test( x.style.getPropertyValue( 'font-family' ) ) ).length;
		}
		await document.fonts.load( '40px "Bebas Neue"' );
		return { caras, cargada: document.fonts.check( '40px "Bebas Neue"' ) };
	} );
	expect( r.caras, '@font-face de Bebas Neue en la página' ).toBe( 1 );
	expect( r.cargada ).toBe( true );
} );

test.describe( 'WhatsApp cede ante la bandeja «Completa tu colección» de la landing', () => {
	// En el teléfono la bandeja va fija y series-bundles.css esconde WhatsApp mientras
	// está abierta. En escritorio va en la página: al abrirla, el botón de WhatsApp
	// quedaba en reposo sobre «Agregar seleccionados» (y a 1366 sobre la «×») y un
	// clic en su borde derecho abría el chat. A 1024, 1280 y 1366 abrirla mueve el
	// scroll (a 1280 lo dejaba en 125 y la cabecera oscilaba: nunca llegaba el
	// reposo); a 1536 no, y sólo cambia el alto de la página.
	for ( const [ ancho, alto ] of [ [ 1024, 768 ], [ 1280, 720 ], [ 1366, 768 ], [ 1536, 864 ] ] ) {
		test( `a ${ ancho }×${ alto }, abierta sin tocar el scroll, un clic en los controles de la bandeja les llega`, async ( { page }, info ) => {
			test.skip( 'movil' === info.project.name, 'en el teléfono WhatsApp se esconde con la bandeja abierta' );
			if ( soloLectura ) {
				// Abrir la bandeja pide la colección por POST (admin-ajax): en producción sólo se mira.
				await page.route( '**/wp-admin/admin-ajax.php', ( ruta ) => ( 'GET' === ruta.request().method() ? ruta.continue() : ruta.abort() ) );
			}
			await page.setViewportSize( { width: ancho, height: alto } );
			expect( ( await page.goto( '/serie/jujutsu-kaisen/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
			test.skip( ! ( await page.locator( '.joinchat' ).count() ), 'sin Joinchat no hay nada que tapar' );
			const empezar = page.locator( '[data-akb-sb-landing-start]' ).first();
			test.skip( ! ( await empezar.count() ), 'esta serie no tiene bandeja de selección' );
			await page.locator( '.joinchat.joinchat--show' ).waitFor( { state: 'attached', timeout: 15_000 } );

			// Clic por coordenadas y ratón fuera: sin el scrollIntoView de Playwright.
			const caja = /** @type {{x:number,y:number,width:number,height:number}} */ ( await empezar.boundingBox() );
			await page.mouse.click( caja.x + caja.width / 2, caja.y + caja.height / 2 );
			await page.mouse.move( 5, 5 );
			await expect( page.locator( '[data-akb-sb-landing-panel]' ) ).toBeVisible();
			// main.js mira lo que hay debajo 150 ms después del último cambio.
			await page.waitForTimeout( 800 );

			const cruces = await page.evaluate( () => {
				const f = /** @type {Element} */ ( document.querySelector( '.joinchat__button' ) || document.querySelector( '.joinchat' ) ).getBoundingClientRect();
				const out = [];
				for ( const [ k, s ] of Object.entries( { agregar: '[data-akb-sb-landing-add]', limpiar: '[data-akb-sb-landing-clear]', cerrar: '[data-akb-sb-landing-close]' } ) ) {
					const e = document.querySelector( s );
					if ( ! e ) {
						continue;
					}
					const q = e.getBoundingClientRect();
					const ancho = Math.min( f.right, q.right ) - Math.max( f.left, q.left );
					const alto = Math.min( f.bottom, q.bottom ) - Math.max( f.top, q.top );
					// Una franja de menos de 4 px bajo el círculo no la mira main.js (muestrea
					// 4 px dentro del botón): no es donde se hace clic.
					if ( ancho <= 4 || alto <= 4 ) {
						continue;
					}
					const x = ( Math.max( f.left, q.left ) + Math.min( f.right, q.right ) ) / 2;
					const y = ( Math.max( f.top, q.top ) + Math.min( f.bottom, q.bottom ) ) / 2;
					const t = document.elementFromPoint( x, y );
					out.push( { k, llega: !! t && !! t.closest( s ), encima: t ? String( t.className || t.tagName ).slice( 0, 40 ) : '' } );
				}
				return out;
			} );
			test.skip( ! cruces.length, `a ${ ancho }×${ alto } el botón de WhatsApp no cae sobre la bandeja` );
			for ( const c of cruces ) {
				expect( c.llega, `${ c.k }: el clic caía en ${ c.encima }` ).toBe( true );
			}
		} );
	}
} );

test( 'la cabecera compacta no hace temblar la página si el scroll se detiene justo pasado 120 px', async ( { page }, info ) => {
	// La cabecera es sticky y compacta mide 26 px menos (130 → 104; revisión X1-2 D4).
	// Con un único umbral el anclaje de scroll de Chromium la devolvía bajo 120, crecía,
	// volvía a pasar… 60 eventos de scroll por segundo sin tocar nada, en 121-125 px
	// (lote X1). La compactación es de ≥ 1025 px.
	test.skip( 'movil' === info.project.name, 'la cabecera sólo se compacta en escritorio' );
	await page.setViewportSize( { width: 1280, height: 720 } );
	expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
	await page.evaluate( () => {
		// @ts-ignore
		window.__akbScrolls = 0;
		// @ts-ignore
		window.addEventListener( 'scroll', () => { window.__akbScrolls++; }, { passive: true } );
	} );
	for ( const y of [ 121, 122, 124, 125 ] ) {
		await page.evaluate( () => window.scrollTo( { top: 0, behavior: 'instant' } ) );
		await page.waitForTimeout( 150 );
		await page.evaluate( ( yy ) => window.scrollTo( { top: yy, behavior: 'instant' } ), y );
		// El primer ajuste del anclaje al compactarse es legítimo: se cuenta después.
		await page.waitForTimeout( 300 );
		// @ts-ignore
		const antes = await page.evaluate( () => window.__akbScrolls );
		await page.waitForTimeout( 1000 );
		// @ts-ignore
		const despues = await page.evaluate( () => window.__akbScrolls );
		expect( despues - antes, `detenido en ${ y } px: eventos de scroll en 1 s sin tocar nada` ).toBeLessThanOrEqual( 1 );
	}
	await expect( page.locator( '.site-header' ) ).toHaveClass( /\bis-compact\b/ );
} );

test( 'el refresco de fragments no le quita el foco de teclado al icono del carrito', async ( { page }, info ) => {
	// El icono es un fragment (inc/woocommerce.php): WooCommerce lo cambia por un nodo
	// nuevo y el foco caía al <body>. Por eso tema-foco-visible «el anillo amarillo no se
	// apaga por el hover» fallaba a veces en a.header-icon--cart con el espejo cargado:
	// el refresco de la carga llegaba justo después del Tab.
	test.skip( 'movil' === info.project.name, 'foco de teclado en la cabecera de escritorio' );
	test.skip( soloLectura, 'pide los fragments por POST: sólo contra el espejo' );
	expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
	// Que pase primero el refresco de la carga.
	await page.waitForLoadState( 'networkidle' );
	await page.locator( 'a.header-icon--wishlist' ).first().focus();
	await page.keyboard.press( 'Tab' );
	const carrito = page.locator( 'a.header-icon--cart' ).first();
	await expect( carrito ).toBeFocused();
	const viejo = await carrito.elementHandle();
	const refresco = page.waitForResponse( /wc-ajax=get_refreshed_fragments/ );
	await page.evaluate( () => {
		// @ts-ignore
		window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
	} );
	await refresco;
	await page.waitForTimeout( 300 );
	expect( await viejo?.evaluate( ( e ) => e.isConnected ), 'el refresco cambió el icono por uno nuevo' ).toBe( false );
	await expect( carrito, 'el foco caía al <body>' ).toBeFocused();
	expect( await carrito.evaluate( ( e ) => e.matches( ':focus-visible' ) ), 'sigue siendo foco de teclado (anillo visible)' ).toBe( true );
	// Y el siguiente Tab avanza: con el foco en el <body>, volvía a caer en el mismo icono.
	await page.keyboard.press( 'Tab' );
	await expect( carrito, 'el siguiente Tab avanza' ).not.toBeFocused();

	// Si el cliente soltó el foco él (un clic en el título), un refresco no se lo devuelve.
	await carrito.focus();
	const titulo = /** @type {{x:number,y:number,width:number,height:number}} */ ( await page.locator( 'h1' ).first().boundingBox() );
	await page.mouse.click( titulo.x + 5, titulo.y + titulo.height / 2 );
	expect( await page.evaluate( () => document.activeElement === document.body ) ).toBe( true );
	const otro = page.waitForResponse( /wc-ajax=get_refreshed_fragments/ );
	await page.evaluate( () => {
		// @ts-ignore
		window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
	} );
	await otro;
	await page.waitForTimeout( 300 );
	expect( await page.evaluate( () => document.activeElement === document.body ), 'el foco que soltó el cliente no vuelve al icono' ).toBe( true );
} );

test( 'REV-X1-2-D4: lo que encoge la cabecera cabe en el hueco entre umbrales, y al volver bajo 60 no tiembla', async ( { page }, info ) => {
	// Compacta mide 26 px menos (130 → 104) una vez terminadas las transiciones; los
	// 8 px que se decían eran el primer instante. El anclaje mueve el scroll eso en cada
	// cambio: al volver a crecer bajo 60 el scroll salta de 59 a 85. El hueco de 60 px
	// entre umbrales (120/60, main.js) tiene que seguir siendo mayor.
	test.skip( 'movil' === info.project.name, 'la cabecera sólo se compacta en escritorio' );
	for ( const ancho of [ 1280, 1440 ] ) {
		await page.setViewportSize( { width: ancho, height: 900 } );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const alto = () => page.evaluate( () => /** @type {Element} */ ( document.querySelector( '.site-header' ) ).getBoundingClientRect().height );
		await page.waitForTimeout( 800 );
		const entera = await alto();
		await page.evaluate( () => window.scrollTo( { top: 400, behavior: 'instant' } ) );
		await expect( page.locator( '.site-header' ) ).toHaveClass( /\bis-compact\b/ );
		await page.waitForTimeout( 900 );
		const encoge = entera - ( await alto() );
		expect( encoge, `${ ancho }: la cabecera compacta encoge` ).toBeGreaterThan( 0 );
		expect( encoge, `${ ancho }: lo que encoge (${ encoge } px) cabe en el hueco de 60 px entre umbrales` ).toBeLessThan( 60 );

		await page.evaluate( () => {
			// @ts-ignore
			window.__akbScrolls = 0;
			// @ts-ignore
			window.addEventListener( 'scroll', () => { window.__akbScrolls++; }, { passive: true } );
		} );
		await page.evaluate( () => window.scrollTo( { top: 59, behavior: 'instant' } ) );
		// El ajuste del anclaje al crecer es legítimo: se cuenta después.
		await page.waitForTimeout( 900 );
		// @ts-ignore
		const antes = await page.evaluate( () => window.__akbScrolls );
		await page.waitForTimeout( 1000 );
		// @ts-ignore
		const despues = await page.evaluate( () => window.__akbScrolls );
		expect( despues - antes, `${ ancho }: vuelta bajo 60, eventos de scroll en 1 s sin tocar nada` ).toBeLessThanOrEqual( 1 );
		await expect( page.locator( '.site-header' ) ).not.toHaveClass( /\bis-compact\b/ );
		const y = await page.evaluate( () => window.scrollY );
		expect( y, `${ ancho }: el anclaje sumó lo que creció la cabecera (${ y })` ).toBeLessThanOrEqual( 120 );
	}
} );

test( 'REV-X1-2-D1: al cerrar el cajón después de un cambio dentro, el foco vuelve al icono del carrito', async ( { page }, info ) => {
	// native-cart.js (akibara-business) pide `wc_fragment_refresh` tras cada cambio en
	// el cajón —cantidad, «Eliminar», recomendación agregada— y WooCommerce cambia el
	// icono de la cabecera por un nodo nuevo con el foco dentro del cajón. Al cerrarlo,
	// el foco volvía al icono viejo, que ya no estaba en el documento: caía al <body> y el
	// siguiente Tab iba a «Saltar al contenido» (medido en el espejo, Chromium y WebKit).
	test.skip( 'movil' === info.project.name, 'foco de teclado en la cabecera de escritorio' );
	test.skip( soloLectura, 'agrega y quita un tomo del carrito: sólo contra el espejo' );
	expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
	await page.waitForLoadState( 'networkidle' );
	// Un tomo en el carrito de esta sesión, para tener algo que quitar dentro del cajón.
	const alta = page.waitForResponse( /wc-ajax=add_to_cart/ );
	await page.locator( 'li.product .add_to_cart_button.ajax_add_to_cart' ).first().click();
	await alta;
	await page.waitForLoadState( 'networkidle' );
	const cajon = page.locator( '#akb-native-cart-drawer' );
	// Si el alta lo abrió solo, se cierra: la prueba empieza en el icono.
	if ( await cajon.evaluate( ( d ) => d.classList.contains( 'akb-open' ) ) ) {
		await page.keyboard.press( 'Escape' );
	}
	await expect( cajon ).not.toHaveClass( /\bakb-open\b/ );

	const carrito = page.locator( 'a.header-icon--cart' ).first();
	await carrito.focus();
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#akb-native-cart-drawer .akb-cart-close' ) ).toBeFocused();
	const viejo = await carrito.elementHandle();
	const quitar = page.locator( '#akb-native-cart-drawer .akb-cart-item-remove' ).first();
	await quitar.focus();
	const refresco = page.waitForResponse( /wc-ajax=get_refreshed_fragments/ );
	await page.keyboard.press( 'Enter' );
	await refresco;
	await expect.poll( () => viejo?.evaluate( ( e ) => e.isConnected ), { message: 'el refresco cambió el icono por uno nuevo' } ).toBe( false );

	await page.keyboard.press( 'Escape' );
	await expect( cajon ).not.toHaveClass( /\bakb-open\b/ );
	await expect( carrito, 'el foco caía al <body>' ).toBeFocused();
	expect( await carrito.evaluate( ( e ) => e.matches( ':focus-visible' ) ), 'sigue siendo foco de teclado (anillo visible)' ).toBe( true );
	await expect( carrito ).toHaveAttribute( 'aria-expanded', 'false' );
	await page.keyboard.press( 'Tab' );
	expect(
		await page.evaluate( () => document.activeElement === document.body || !! ( document.activeElement && document.activeElement.closest( '.skip-link' ) ) ),
		'el siguiente Tab volvía a «Saltar al contenido»'
	).toBe( false );
} );

/**
 * Un scroll en que el botón de WhatsApp tapa más de 4 × 4 px de un elemento visible
 * que cumple `sel` (`y`, o -1 si no lo hay), y el cruce en ese scroll
 * (`cruceConWhatsApp`, o null si no se quedó quieto). Mueve el scroll de la pestaña.
 *
 * Se elige con la página QUIETA, en el centro del primer tramo de scrolls con
 * cruce que deje al menos 10 px de margen a cada lado, y se vuelve a mirar 800 ms
 * después: main.js mira lo que hay debajo 150 ms después del último scroll. Elegido
 * el PRIMER scroll con cruce, el primer scroll con la bandeja abierta hacía crecer
 * su caja «Completa tu colección» (akibara-business) de 33 a 60 px entre 200 y
 * 1.200 ms después, la rejilla bajaba 27 px y a los 800 ms el «Seleccionar» ya no
 * estaba bajo WhatsApp: la prueba caía por ese reflujo y no por main.js (REV-FT2-D1,
 * /serie/jujutsu-kaisen/ a 1024x768: 3 de 3 en serie en Chromium; en WebKit, también
 * /serie/blue-lock/). Si aun así el cruce se fue, se vuelve a elegir, hasta tres
 * veces: la segunda vez la caja ya creció.
 */
async function cruceQuietoConWhatsApp( page, sel ) {
	let y = -1;
	for ( let vuelta = 0; vuelta < 3; vuelta++ ) {
		await esperarPaginaQuieta( page );
		y = await page.evaluate( ( s ) => {
			const boton = /** @type {Element} */ ( document.querySelector( '.joinchat__button' ) || document.querySelector( '.joinchat' ) );
			const tapa = ( /** @type {number} */ top ) => {
				window.scrollTo( { top, behavior: 'instant' } );
				const f = boton.getBoundingClientRect();
				for ( const e of document.querySelectorAll( s ) ) {
					const q = e.getBoundingClientRect();
					if ( Math.min( f.right, q.right ) - Math.max( f.left, q.left ) > 4 && Math.min( f.bottom, q.bottom ) - Math.max( f.top, q.top ) > 4 ) {
						return true;
					}
				}
				return false;
			};
			const max = document.documentElement.scrollHeight - window.innerHeight;
			for ( let top = 0; top <= max; top += 7 ) {
				if ( ! tapa( top ) ) {
					continue;
				}
				let ini = top;
				while ( ini > 0 && tapa( ini - 1 ) ) {
					ini--;
				}
				let fin = top;
				while ( fin < max && tapa( fin + 1 ) ) {
					fin++;
				}
				const centro = Math.round( ( ini + fin ) / 2 );
				if ( centro - ini >= 10 && fin - centro >= 10 ) {
					window.scrollTo( { top: centro, behavior: 'instant' } );
					return Math.round( window.scrollY );
				}
				top = fin;
			}
			return -1;
		}, sel );
		if ( y < 0 ) {
			return { y, c: null };
		}
		await page.waitForTimeout( 800 );
		const c = await cruceConWhatsApp( page, sel );
		if ( c ) {
			return { y, c };
		}
	}
	return { y, c: null };
}

/** Centro del cruce entre el botón de WhatsApp y el primer `sel` que tapa, y si un clic ahí le llega. */
async function cruceConWhatsApp( page, sel ) {
	return page.evaluate( ( s ) => {
		const f = /** @type {Element} */ ( document.querySelector( '.joinchat__button' ) || document.querySelector( '.joinchat' ) ).getBoundingClientRect();
		for ( const e of document.querySelectorAll( s ) ) {
			const q = e.getBoundingClientRect();
			const w = Math.min( f.right, q.right ) - Math.max( f.left, q.left );
			const h = Math.min( f.bottom, q.bottom ) - Math.max( f.top, q.top );
			if ( w > 4 && h > 4 ) {
				const x = ( Math.max( f.left, q.left ) + Math.min( f.right, q.right ) ) / 2;
				const y = ( Math.max( f.top, q.top ) + Math.min( f.bottom, q.bottom ) ) / 2;
				const t = document.elementFromPoint( x, y );
				return { x, y, llega: !! t && !! t.closest( s ), encima: t ? String( t.className || t.tagName ).slice( 0, 40 ) : '' };
			}
		}
		return null;
	}, sel );
}

test.describe( 'REV-X1-2-D2: WhatsApp cede ante «Elegir tomos» de la landing de serie', () => {
	// De 769 a unos 1000 px el botón de WhatsApp reposaba sobre la mitad derecha de
	// «Elegir tomos» —a 900x700 nada más cargar, a 800x600 con 110 px de scroll— y el
	// clic abría el chat en vez de la bandeja.
	for ( const [ ancho, alto ] of [ [ 800, 600 ], [ 900, 700 ] ] ) {
		test( `a ${ ancho }×${ alto }, donde WhatsApp cae sobre «Elegir tomos», el clic abre la bandeja`, async ( { page }, info ) => {
			test.skip( 'movil' === info.project.name, 'ancho de tableta horizontal con el proyecto de escritorio' );
			if ( soloLectura ) {
				// Abrir la bandeja pide la colección por POST (admin-ajax): en producción sólo se mira.
				await page.route( '**/wp-admin/admin-ajax.php', ( ruta ) => ( 'GET' === ruta.request().method() ? ruta.continue() : ruta.abort() ) );
			}
			await page.setViewportSize( { width: ancho, height: alto } );
			expect( ( await page.goto( '/serie/jujutsu-kaisen/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
			test.skip( ! ( await page.locator( '.joinchat' ).count() ), 'sin Joinchat no hay nada que tapar' );
			test.skip( ! ( await page.locator( '[data-akb-sb-landing-start]' ).count() ), 'esta serie no tiene bandeja de selección' );
			await page.locator( '.joinchat.joinchat--show' ).waitFor( { state: 'attached', timeout: 15_000 } );

			const { y, c } = await cruceQuietoConWhatsApp( page, '[data-akb-sb-landing-start]' );
			test.skip( y < 0, `a ${ ancho }×${ alto } WhatsApp no cae sobre «Elegir tomos»` );
			expect( c, `scroll ${ y }: el cruce no se quedó quieto bajo WhatsApp en tres intentos` ).toBeTruthy();
			expect( c?.llega, `scroll ${ y }: el clic caía en ${ c?.encima }` ).toBe( true );
			await page.mouse.click( /** @type {number} */ ( c?.x ), /** @type {number} */ ( c?.y ) );
			await page.mouse.move( 5, 5 );
			await expect( page.locator( '[data-akb-sb-landing-panel]' ) ).toBeVisible();
			await expect( page.locator( '.joinchat.joinchat--chatbox' ) ).toHaveCount( 0 );
		} );
	}
} );

test.describe( 'REV-X1-2-D3: con la bandeja abierta, WhatsApp cede ante «Seleccionar»', () => {
	// «Seleccionar» es el control que nombran las instrucciones de la bandeja. WhatsApp
	// reposaba sobre uno habilitado y el clic abría el chat sin elegir el tomo (en 44 de
	// 68 posiciones con cruce en /serie/blue-lock/ a 1366x768).
	for ( const [ ruta, ancho, alto ] of [ [ '/serie/blue-lock/', 1366, 768 ], [ '/serie/jujutsu-kaisen/', 1024, 768 ] ] ) {
		test( `${ ruta } a ${ ancho }×${ alto }: un clic en el «Seleccionar» bajo WhatsApp elige el tomo`, async ( { page }, info ) => {
			test.skip( 'movil' === info.project.name, 'en el teléfono WhatsApp se esconde con la bandeja abierta' );
			test.setTimeout( 90_000 );
			if ( soloLectura ) {
				await page.route( '**/wp-admin/admin-ajax.php', ( r ) => ( 'GET' === r.request().method() ? r.continue() : r.abort() ) );
			}
			await page.setViewportSize( { width: ancho, height: alto } );
			expect( ( await page.goto( ruta, { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
			test.skip( ! ( await page.locator( '.joinchat' ).count() ), 'sin Joinchat no hay nada que tapar' );
			const empezar = page.locator( '[data-akb-sb-landing-start]' ).first();
			test.skip( ! ( await empezar.count() ), 'esta serie no tiene bandeja de selección' );
			await page.locator( '.joinchat.joinchat--show' ).waitFor( { state: 'attached', timeout: 15_000 } );
			const caja = /** @type {{x:number,y:number,width:number,height:number}} */ ( await empezar.boundingBox() );
			await page.mouse.click( caja.x + caja.width / 2, caja.y + caja.height / 2 );
			await page.mouse.move( 5, 5 );
			await expect( page.locator( '[data-akb-sb-landing-panel]' ) ).toBeVisible();

			const SEL = '.akb-sb-card-select:not(.is-disabled):not([hidden])';
			const { y, c } = await cruceQuietoConWhatsApp( page, SEL );
			test.skip( y < 0, 'WhatsApp no cae sobre ningún «Seleccionar» habilitado' );
			expect( c, `scroll ${ y }: el cruce no se quedó quieto bajo WhatsApp en tres intentos` ).toBeTruthy();
			expect( c?.llega, `scroll ${ y }: el clic caía en ${ c?.encima }` ).toBe( true );
			const conteo = page.locator( '[data-akb-sb-landing-count]' ).first();
			const antes = ( await conteo.textContent() || '' ).trim();
			await page.mouse.click( /** @type {number} */ ( c?.x ), /** @type {number} */ ( c?.y ) );
			await page.mouse.move( 5, 5 );
			await expect( page.locator( '.joinchat.joinchat--chatbox' ) ).toHaveCount( 0 );
			await expect( conteo, 'el tomo no se elegía' ).not.toHaveText( antes );
		} );
	}
} );

/**
 * Espera a que la página esté quieta: el botón de WhatsApp a su tamaño final y el
 * alto del documento, iguales en dos lecturas seguidas.
 */
async function esperarPaginaQuieta( page ) {
	let antes = '';
	await expect.poll( async () => {
		const ahora = await page.evaluate( () => {
			const r = /** @type {Element} */ ( document.querySelector( '.joinchat__button' ) || document.querySelector( '.joinchat' ) ).getBoundingClientRect();
			return [ r.width, r.height, document.documentElement.scrollHeight ].join( ' ' );
		} );
		const quieta = ahora === antes;
		antes = ahora;
		return quieta;
	}, { message: 'la página no se quedó quieta', intervals: [ 250 ], timeout: 15_000 } ).toBe( true );
}

/**
 * Un scroll en que, en los puntos que mira main.js, bajo WhatsApp está el «Agotado» y
 * nada que se pueda pulsar; -1 si no lo hay. `aqui`: el de ahora, sin moverlo.
 * `elegir`: el centro del primer tramo de scrolls que cumplen con al menos 5 px de
 * margen a cada lado (en el espejo los tramos miden 15 px: ±7), y lo deja puesto.
 */
async function agotadoBajoWhatsApp( page, modo ) {
	return page.evaluate( ( m ) => {
		const boton = /** @type {Element} */ ( document.querySelector( '.joinchat__button' ) || document.querySelector( '.joinchat' ) );
		const PULSABLE = 'a[href], button, input, select, textarea, [role="button"], label.akb-sb-card-select:not(.is-disabled)';
		const cumple = () => {
			const r = boton.getBoundingClientRect();
			let agotado = false;
			let pulsable = false;
			for ( const x of [ r.left + 4, r.left + r.width / 2 ] ) {
				for ( const yy of [ r.top + 4, r.top + r.height / 2, r.bottom - 4 ] ) {
					for ( const el of document.elementsFromPoint( x, yy ) ) {
						if ( el.closest( '.joinchat' ) ) {
							continue;
						}
						// La casilla del «Agotado» va dentro de su etiqueta y tampoco hace nada.
						const enAgotado = !! el.closest( '.akb-sb-card-select.is-disabled' );
						agotado = agotado || enAgotado;
						pulsable = pulsable || ( ! enAgotado && !! el.closest( PULSABLE ) );
					}
				}
			}
			return agotado && ! pulsable;
		};
		if ( 'aqui' === m ) {
			return cumple() ? Math.round( window.scrollY ) : -1;
		}
		const max = document.documentElement.scrollHeight - window.innerHeight;
		const en = ( top ) => {
			window.scrollTo( { top, behavior: 'instant' } );
			return cumple();
		};
		for ( let top = 0; top <= max; top += 7 ) {
			if ( ! en( top ) ) {
				continue;
			}
			let ini = top;
			while ( ini > 0 && en( ini - 1 ) ) {
				ini--;
			}
			let fin = top;
			while ( fin < max && en( fin + 1 ) ) {
				fin++;
			}
			const centro = Math.round( ( ini + fin ) / 2 );
			if ( centro - ini >= 5 && fin - centro >= 5 ) {
				window.scrollTo( { top: centro, behavior: 'instant' } );
				return Math.round( window.scrollY );
			}
			top = fin;
		}
		return -1;
	}, modo );
}

test.describe( 'REV-X1-2b-D1: WhatsApp NO cede ante un «Seleccionar» deshabilitado («Agotado»)', () => {
	// La mitad negativa de REV-X1-2-D3 sólo estaba en una prueba unitaria que no
	// comprobaba nada. Un «Seleccionar» deshabilitado no hace nada al pulsarlo:
	// retirar WhatsApp ahí sólo lo esconde sin motivo (medido en el espejo en
	// /serie/jujutsu-kaisen/ a 1100x700 con la bandeja abierta y scroll 765: el botón
	// reposa sobre label.akb-sb-card-select.is-disabled).
	test( 'con la bandeja abierta, un «Agotado» bajo WhatsApp (y nada que se pueda pulsar) no lo retira', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'en el teléfono WhatsApp se esconde con la bandeja abierta' );
		test.setTimeout( 90_000 );
		if ( soloLectura ) {
			await page.route( '**/wp-admin/admin-ajax.php', ( r ) => ( 'GET' === r.request().method() ? r.continue() : r.abort() ) );
		}
		await page.setViewportSize( { width: 1100, height: 700 } );
		expect( ( await page.goto( '/serie/jujutsu-kaisen/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		test.skip( ! ( await page.locator( '.joinchat' ).count() ), 'sin Joinchat no hay nada que tapar' );
		const empezar = page.locator( '[data-akb-sb-landing-start]' ).first();
		test.skip( ! ( await empezar.count() ), 'esta serie no tiene bandeja de selección' );
		await page.locator( '.joinchat.joinchat--show' ).waitFor( { state: 'attached', timeout: 15_000 } );
		const caja = /** @type {{x:number,y:number,width:number,height:number}} */ ( await empezar.boundingBox() );
		await page.mouse.click( caja.x + caja.width / 2, caja.y + caja.height / 2 );
		await page.mouse.move( 5, 5 );
		await expect( page.locator( '[data-akb-sb-landing-panel]' ) ).toBeVisible();
		test.skip( ! ( await page.locator( '.akb-sb-card-select.is-disabled:not([hidden])' ).count() ), 'esta serie no tiene tomos agotados en la bandeja' );

		// Un scroll donde, en los puntos que mira main.js, bajo WhatsApp está el «Agotado»
		// y nada que se pueda pulsar. Se elige con la página QUIETA y en el centro de su
		// tramo, con margen a cada lado. Elegido mientras el botón de Joinchat aún
		// animaba (61 px en vez de 60) y el alto de la página se asentaba (7264 → 7246 o
		// 7273), a los 800 ms la rejilla había corrido unos px y debajo quedaba un
		// «Avísame» (que sí es compra): main.js cedía con razón y la prueba culpaba al
		// producto (REV-FT1-D2, una de cada seis corridas en paralelo). Si aun así lo de
		// debajo cambió cuando main.js ya miró, no hay nada que afirmar: se vuelve a elegir.
		let y = -1;
		for ( let vuelta = 0; vuelta < 3 && y < 0; vuelta++ ) {
			await esperarPaginaQuieta( page );
			const elegido = await agotadoBajoWhatsApp( page, 'elegir' );
			test.skip( elegido < 0, 'WhatsApp no cae sobre un «Agotado» sin otra cosa pulsable debajo' );
			// main.js mira lo que hay debajo 150 ms después del último scroll.
			await page.waitForTimeout( 800 );
			y = await agotadoBajoWhatsApp( page, 'aqui' );
		}
		test.skip( y < 0, 'la página no se quedó quieta bajo WhatsApp en tres intentos: no hay nada que afirmar' );
		await expect( page.locator( 'body' ), `scroll ${ y }: WhatsApp se retiraba ante un control que no hace nada` ).not.toHaveClass( /\bakb-fab-cede\b/ );
		await expect( page.locator( '.joinchat__button' ) ).toBeVisible();
	} );
} );
