// @ts-check
/**
 * Lote de integración del tema con akibara-business y akibara-search
 * (2026-09-23). Lo que cruza de plugin se mide aquí, en un navegador de verdad,
 * porque las pruebas unitarias de cada lado sólo ven su mitad:
 *
 *  - UM-8 = UC-1: una búsqueda sin resultados pinta «Lo más vendido» del tema
 *    DENTRO del aviso del buscador (`akibara_search_empty_after`), con el `sizes`
 *    de su celda, y el PJAX de 0 a N y de vuelta la trata como parte del aviso.
 *  - UM-1: en la landing de serie la primera fila va de entrada y la portada #1
 *    con `fetchpriority`; «Otras ediciones» ocupa una línea.
 *  - UM-7: la barra de compra de un tomo reservable dice cuándo llega (la fecha
 *    la redacta business).
 *  - AI-13 / AI-10 / UM-14: el carrusel de la portada (orden del Tab, foco de las
 *    flechas) y el alt de «Series más buscadas».
 *  - D5b: el rótulo de la vista rápida sale del panel y la hoja sólo aplica desde
 *    769 px.
 *  - AI-5: los avisos del formulario de contacto, en español.
 *
 * Todo es de LECTURA: vale contra el espejo y contra producción
 * (`AKB_BASE_URL=https://akibara.cl AKB_SOLO_LECTURA=1`).
 */

import { expect, test } from '@playwright/test';
import { cortarEnviosDeContacto, erroresDePagina, productoConBadge } from './soporte/tienda.mjs';

/** Búsqueda que no casa con nada del catálogo. */
const SIN_NADA = '/tienda/?_buscar=zzqxakbnohay';

/**
 * Ancho que declara un `sizes` para el viewport actual, evaluado como el
 * navegador: el primer tramo cuya condición se cumple.
 *
 * @param {import('@playwright/test').Locator} img Imagen.
 */
function anchoDeclarado( img ) {
	return img.evaluate( ( i ) => {
		const tramos = ( i.getAttribute( 'sizes' ) || '' ).replace( /^auto,\s*/, '' ).split( /,(?![^(]*\))/ ).map( ( t ) => t.trim() );
		const medir = ( valor ) => {
			const d = document.createElement( 'div' );
			d.style.width = valor;
			document.body.appendChild( d );
			const w = d.getBoundingClientRect().width;
			d.remove();
			return w;
		};
		for ( const tramo of tramos ) {
			const m = tramo.match( /^(\([^)]*\))\s+(.+)$/ );
			if ( ! m ) {
				return medir( tramo );
			}
			if ( matchMedia( m[ 1 ] ).matches ) {
				return medir( m[ 2 ] );
			}
		}
		return 0;
	} );
}

test.describe( 'búsqueda sin resultados con la venta cruzada del tema', () => {
	test( 'pinta «Lo más vendido» dentro del aviso, con el sizes de su celda y la #1 prioritaria', async ( { page } ) => {
		const errores = erroresDePagina( page );
		const respuesta = await page.goto( SIN_NADA, { waitUntil: 'load' } );
		expect( respuesta?.status() ).toBe( 200 );

		const aviso = page.locator( '[data-akb-empty]' );
		await expect( aviso ).toBeVisible();
		const seccion = aviso.locator( 'section.akb-empty__cross-sell' );
		await expect( seccion, 'UM-8: la venta cruzada va DENTRO del aviso' ).toHaveCount( 1 );
		await expect( seccion.locator( '#akb-search-empty-popular-title' ) ).toHaveText( /Lo más vendido/i );
		expect( await seccion.locator( 'li.product' ).count() ).toBeGreaterThan( 0 );
		expect( await page.evaluate( () => Array.from( document.querySelectorAll( 'ul.products' ) ).filter( ( u ) => ! u.closest( '[data-akb-empty]' ) ).length ), 'ninguna rejilla fuera del aviso' ).toBe( 0 );
		expect( await seccion.evaluate( ( s ) => getComputedStyle( s ).textAlign ), 'las tarjetas no heredan el centrado del aviso' ).toBe( 'left' );

		// La #1 es la candidata a LCP en escritorio (medido: 2,4 s frente a 2,8 s
		// difiriéndola); las de la fila siguiente, diferidas.
		const imgs = seccion.locator( 'li.product img' );
		await expect( imgs.first() ).toHaveAttribute( 'fetchpriority', 'high' );
		// El sizes declara el ancho pintado, nunca menos y a lo sumo 1 px más.
		const pintado = await imgs.first().evaluate( ( i ) => i.getBoundingClientRect().width );
		const declarado = await anchoDeclarado( imgs.first() );
		expect( declarado, `sizes ${ declarado } para una portada de ${ pintado }` ).toBeGreaterThanOrEqual( pintado - 0.5 );
		expect( declarado ).toBeLessThanOrEqual( pintado + 1.01 );
		expect( errores ).toEqual( [] );
	} );

	test( 'el PJAX de cero a N y de vuelta se lleva y trae la venta cruzada con el aviso', async ( { page } ) => {
		const errores = erroresDePagina( page );
		await page.goto( SIN_NADA, { waitUntil: 'load' } );
		await page.evaluate( () => { window.akbQaSinRecarga = true; } );

		const foto = () => page.evaluate( () => {
			const aviso = document.querySelector( '[data-akb-empty]' );
			return {
				rejillas: Array.from( document.querySelectorAll( 'ul.products' ) ).filter( ( u ) => ! u.closest( '[data-akb-empty]' ) ).map( ( u ) => u.querySelectorAll( 'li.product' ).length ),
				avisoVisible: !! aviso && ! aviso.hidden,
				ventaCruzada: document.querySelectorAll( '.akb-empty__cross-sell' ).length,
				sinRecarga: !! window.akbQaSinRecarga,
			};
		} );

		// 0 → N: «limpia todo».
		await Promise.all( [
			page.waitForURL( ( url ) => ! url.search.includes( '_buscar' ) ),
			page.locator( '[data-akb-empty] [data-akb-clear]' ).click(),
		] );
		await expect.poll( async () => ( await foto() ).rejillas.length ).toBe( 1 );
		const conResultados = await foto();
		expect( conResultados.rejillas[ 0 ], 'la tienda no se queda sin productos' ).toBeGreaterThan( 0 );
		expect( conResultados ).toMatchObject( { avisoVisible: false, ventaCruzada: 0, sinRecarga: true } );

		// N → 0: Atrás.
		await page.goBack();
		await expect.poll( async () => ( await foto() ).avisoVisible ).toBe( true );
		expect( await foto() ).toMatchObject( { rejillas: [], ventaCruzada: 1 } );
		expect( errores ).toEqual( [] );
	} );

	test( 'una rejilla vaciada sólo por filtros no repite los más vendidos', async ( { page } ) => {
		// Una editorial y una categoría que no comparten un solo tomo no es algo
		// que se pueda clavar; se prueba el contrato con una faceta inexistente,
		// que el buscador resuelve como filtros sin texto.
		await page.goto( '/tienda/?_editorial=zzqx-no-existe', { waitUntil: 'load' } );
		const aviso = page.locator( '[data-akb-empty]' );
		test.skip( ! ( await aviso.isVisible() ), 'el buscador no trató esta URL como filtro vacío' );
		await expect( aviso.locator( '.akb-empty__cross-sell' ) ).toHaveCount( 0 );
	} );
} );

test.describe( 'landing de serie', () => {
	test( 'la primera fila va de entrada y la portada #1 con prioridad (UM-1)', async ( { page } ) => {
		await page.goto( '/serie/one-piece/', { waitUntil: 'load' } );
		const portadas = page.locator( 'ul.products > li.product img.attachment-woocommerce_thumbnail, ul.products > li.product .akb-product-cover-wrap img' );
		await expect( portadas.first() ).toHaveAttribute( 'fetchpriority', 'high' );
		expect( await portadas.first().getAttribute( 'loading' ), 'la #1 no se difiere' ).not.toBe( 'lazy' );
		expect( await portadas.nth( 1 ).getAttribute( 'loading' ) ).not.toBe( 'lazy' );
		expect( await portadas.nth( 2 ).getAttribute( 'loading' ) ).toBe( 'lazy' );
	} );

	test( '«Otras ediciones» ocupa una línea y su enlace se puede tocar (B3-fix)', async ( { page } ) => {
		await page.goto( '/serie/one-piece/', { waitUntil: 'load' } );
		const bloque = page.locator( '.akb-series-editions' );
		test.skip( ! ( await bloque.count() ), 'la serie ya no tiene otras ediciones ligadas' );
		const medidas = await bloque.evaluate( ( b ) => {
			const enlace = b.querySelector( 'a' );
			const zona = getComputedStyle( enlace, '::after' );
			return { alto: b.getBoundingClientRect().height, lineas: Math.round( b.getBoundingClientRect().height / parseFloat( getComputedStyle( b ).lineHeight ) ), enlace: enlace.getBoundingClientRect().height, zonaArriba: parseFloat( zona.top ) };
		} );
		// Con el rótulo encima y la píldora debajo medía ~62 px (96 con márgenes).
		expect( medidas.alto, `alto del bloque (${ medidas.lineas } líneas)` ).toBeLessThan( 45 );
		expect( medidas.enlace - 2 * medidas.zonaArriba, 'zona táctil ≥ 24 px' ).toBeGreaterThanOrEqual( 24 );
	} );
} );

test( 'la barra de compra de un tomo reservable dice cuándo llega (UM-7)', async ( { page } ) => {
	test.setTimeout( 120_000 ); // buscar el tomo recorre dos listados
	const url = await productoConBadge( page, 'A pedido' );
	test.skip( ! url, 'no hay tomos a pedido en los listados' );
	await page.goto( /** @type {string} */ ( url ), { waitUntil: 'load' } );
	// La fecha la redacta business: sólo sale si el tomo tiene rango de fechas.
	const tieneFecha = await page.locator( '.akb-eta' ).count();
	test.skip( ! tieneFecha, 'este tomo no promete fecha (sin rango, o editorial en pausa)' );
	const y = await page.evaluate( () => Math.round( document.querySelector( 'form.cart' ).getBoundingClientRect().bottom + scrollY ) );
	await page.evaluate( ( destino ) => window.scrollTo( 0, destino ), y + 400 );
	const barra = page.locator( '#akb-sticky-atc.is-visible' );
	await expect( barra ).toHaveCount( 1 );
	const eta = barra.locator( '.akb-sticky-atc__eta' );
	await expect( eta ).toBeVisible();
	await expect( eta ).toHaveText( /\d/ );
	// La barra entra con una transición de 8 px: se mide ya asentada.
	const vista = page.viewportSize();
	await expect.poll( async () => {
		const caja = await barra.boundingBox();
		return !! caja && !! vista && caja.y + caja.height <= vista.height + 1;
	}, { message: 'la barra con la línea nueva sigue entera en pantalla' } ).toBe( true );
} );

test.describe( 'portada', () => {
	test( 'el Tab recorre el carrusel en el orden en que se ve (AI-13) y sus acciones no son un landmark', async ( { page } ) => {
		await page.goto( '/', { waitUntil: 'load' } );
		const orden = await page.evaluate( () => {
			const seg = document.querySelector( '.akb-hero__seg' );
			const cta = document.querySelector( '.akb-hero__action' );
			if ( ! seg || ! cta ) {
				return null;
			}
			return { segmentosAntes: !! ( seg.compareDocumentPosition( cta ) & Node.DOCUMENT_POSITION_FOLLOWING ), navs: document.querySelectorAll( 'nav.akb-hero__actions' ).length };
		} );
		test.skip( ! orden, 'la portada no tiene carrusel con acciones' );
		expect( orden?.segmentosAntes, 'los segmentos (arriba) van antes que el CTA (abajo)' ).toBe( true );
		expect( orden?.navs ).toBe( 0 );
	} );

	test( 'la flecha del carrusel enfocada lleva el anillo amarillo entero, no el rojo del hover (AI-10)', async ( { page, isMobile } ) => {
		test.skip( isMobile, 'en el teléfono el carrusel no tiene flechas' );
		await page.goto( '/', { waitUntil: 'load' } );
		const flecha = page.locator( '.akb-hero__arrow--next' ).first();
		test.skip( ! ( await flecha.count() ), 'sin flechas en este carrusel' );
		await flecha.focus();
		await page.keyboard.press( 'Shift+Tab' );
		await page.keyboard.press( 'Tab' );
		const estilo = await flecha.evaluate( ( b ) => {
			const cs = getComputedStyle( b );
			return { fv: b.matches( ':focus-visible' ), outline: cs.outlineStyle, ancho: parseFloat( cs.outlineWidth ), color: cs.outlineColor, clip: cs.clipPath, fondo: getComputedStyle( b, '::before' ).backgroundColor };
		} );
		expect( estilo.fv ).toBe( true );
		expect( estilo.outline ).toBe( 'solid' );
		expect( estilo.ancho ).toBeGreaterThanOrEqual( 3 );
		expect( estilo.color, 'amarillo de la casa' ).toMatch( /rgb\(255, 215, 0\)/ );
		expect( estilo.clip, 'el botón no recorta su propio anillo' ).toBe( 'none' );
		expect( estilo.fondo, 'el foco no es el rojo del hover' ).not.toMatch( /rgb\(230, 0, 18\)/ );
	} );

	test( 'las portadas de «Series más buscadas» se llaman como su serie (UM-14)', async ( { page } ) => {
		await page.goto( '/', { waitUntil: 'load' } );
		const enlaces = page.locator( '.akb-home-series__link' );
		test.skip( ! ( await enlaces.count() ), 'la portada no tiene la vitrina de series' );
		const datos = await enlaces.evaluateAll( ( as ) => as.map( ( a ) => ( { alt: a.querySelector( 'img' )?.getAttribute( 'alt' ) ?? null, nombre: a.querySelector( '.akb-home-series__name' )?.textContent.trim(), oculto: a.querySelector( '.akb-home-series__name' )?.getAttribute( 'aria-hidden' ) } ) ) );
		for ( const d of datos.filter( ( x ) => null !== x.alt ) ) {
			expect( d.alt, 'ni vacío ni el relleno de Rank Math' ).toBe( d.nombre );
			expect( d.oculto, 'el nombre visible no se lee dos veces' ).toBe( 'true' );
		}
	} );
} );

test( 'la vista rápida: rótulo del panel y hoja sólo desde 769 px (D5b)', async ( { page, isMobile } ) => {
	await page.goto( '/tienda/', { waitUntil: 'load' } );
	const hoja = page.locator( 'link#akb-quick-view-css' );
	if ( await hoja.count() ) {
		await expect( hoja ).toHaveAttribute( 'media', '(min-width: 769px)' );
	}
	const boton = page.locator( 'li.product > .akb-qv-trigger' ).first();
	test.skip( ! ( await boton.count() ), 'ningún tomo con sinopsis en la primera página' );
	const rotulo = await boton.getAttribute( 'data-akb-qv-label' );
	expect( rotulo, 'el rótulo viaja en el botón' ).toBeTruthy();
	expect( await boton.getAttribute( 'aria-label' ), 'label in name' ).toMatch( new RegExp( '^' + String( rotulo ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + ': ' ) );
	if ( isMobile ) {
		expect( await boton.getAttribute( 'hidden' ), 'en el teléfono el JS no lo revela' ).not.toBeNull();
		return;
	}
	await expect( boton ).toBeVisible();
	expect( await boton.evaluate( ( b ) => getComputedStyle( b, '::after' ).content ) ).toBe( JSON.stringify( rotulo ) );
} );

test( 'el formulario de contacto avisa en español qué campo falta, sin enviar (AI-5)', async ( { page } ) => {
	const respuesta = await page.goto( '/contacto/', { waitUntil: 'load' } );
	test.skip( ! respuesta || respuesta.status() >= 400, 'sin página de contacto' );
	const form = page.locator( 'form.akb-form, form#akb-contacto, form[data-akb-form]' ).first();
	test.skip( ! ( await form.count() ), 'sin formulario' );
	const cortados = await cortarEnviosDeContacto( page );
	const envios = [];
	page.on( 'request', ( r ) => { if ( /akibara\/v1\/contacto/.test( r.url() ) ) { envios.push( r.url() ); } } );
	await form.locator( 'button[type="submit"]' ).click();
	const nombre = page.locator( '#akb-c-nombre' );
	await expect( nombre ).toHaveAttribute( 'aria-invalid', 'true' );
	const aviso = page.locator( '#' + ( await nombre.getAttribute( 'aria-describedby' ) || 'x' ).split( ' ' )[ 0 ] );
	await expect( aviso ).toHaveText( /Completa este campo/ );
	expect( await aviso.getAttribute( 'lang' ), 'es el texto del dueño, en el idioma de la página' ).toBeNull();

	await nombre.fill( 'QA' );
	await page.locator( '#akb-c-email' ).fill( 'no-es-un-correo' );
	await form.locator( 'button[type="submit"]' ).click();
	const email = page.locator( '#akb-c-email' );
	await expect( email ).toHaveAttribute( 'aria-invalid', 'true' );
	await expect( page.locator( '#' + ( await email.getAttribute( 'aria-describedby' ) || 'x' ).split( ' ' )[ 0 ] ) ).toHaveText( /correo/i );
	expect( envios, 'con errores no sale ninguna petición' ).toEqual( [] );
	expect( cortados ).toEqual( [] );
} );
