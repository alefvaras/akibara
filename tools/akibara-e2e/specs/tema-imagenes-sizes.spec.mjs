// @ts-check
/**
 * Las imágenes piden el archivo del tamaño en que se PINTAN.
 *
 * `sizes` le dice al navegador cuánto medirá la imagen antes de maquetar; con
 * él elige el candidato del `srcset`. Si declara de más, el cliente baja un
 * archivo más grande del que ve (el LCP de la ficha bajaba el ORIGINAL de 192 KB
 * para pintar 274 px en un Android); si declara de menos, la imagen sale blanda.
 * Las pruebas unitarias comparan la fórmula con anchos medidos; ésta mide en el
 * navegador de verdad, contra la plantilla servida (lote T1, 2026-09-22).
 *
 * Sólo lectura: mira fichas, el 404, el carrito vacío de un visitante nuevo y el
 * blog. Vale contra el espejo y contra producción.
 */

import { expect, test } from '@playwright/test';

/**
 * Ancho declarado por el `sizes` de la imagen (como lo resuelve el navegador) y
 * el hueco en que se dibuja, medido SIN pasar por el `sizes`: el ancho del
 * contenedor y, si la regla le pone techo de alto con `object-fit: contain`, el
 * techo por la proporción del archivo.
 *
 * @param {import('@playwright/test').Locator} img Imagen.
 * @returns {Promise<{declarado: number, hueco: number, sizes: string}>}
 */
async function medir( img ) {
	return img.evaluate( ( el ) => {
		const i = /** @type {HTMLImageElement} */ ( el );
		const tramos = ( s ) => {
			const out = [];
			let nivel = 0;
			let actual = '';
			for ( const c of s ) {
				if ( '(' === c ) nivel++;
				if ( ')' === c ) nivel--;
				if ( ',' === c && 0 === nivel ) { out.push( actual.trim() ); actual = ''; } else { actual += c; }
			}
			out.push( actual.trim() );
			return out.filter( ( t ) => 'auto' !== t );
		};
		const ancho = ( valor ) => {
			const d = document.createElement( 'div' );
			d.style.cssText = 'position:absolute;left:0;top:0;height:1px;visibility:hidden;width:' + valor;
			document.body.appendChild( d );
			const w = d.getBoundingClientRect().width;
			d.remove();
			return w;
		};
		const resolver = ( s ) => {
			for ( const t of tramos( s ) ) {
				if ( t.startsWith( '(' ) ) {
					let nivel = 0;
					let k = 0;
					for ( ; k < t.length; k++ ) {
						if ( '(' === t[ k ] ) nivel++;
						if ( ')' === t[ k ] && 0 === --nivel ) break;
					}
					if ( ! matchMedia( t.slice( 0, k + 1 ) ).matches ) continue;
					const w = ancho( t.slice( k + 1 ).trim() );
					if ( w > 0 ) return w; // Un valor que el navegador no entiende da 0: se salta, como hace él.
				} else {
					return ancho( t );
				}
			}
			return NaN;
		};
		const estilo = getComputedStyle( i );
		// Ancho de CONTENIDO del contenedor: el borde de la <figure> del blog no es hueco.
		const caja = /** @type {HTMLElement} */ ( i.parentElement ).clientWidth;
		const proporcion = Number( i.getAttribute( 'width' ) ) / Number( i.getAttribute( 'height' ) );
		const techo = 'contain' === estilo.objectFit && 'none' !== estilo.maxHeight ? parseFloat( estilo.maxHeight ) * proporcion : Infinity;
		const propia = i.getBoundingClientRect().width;
		// La portada de la tarjeta está posicionada sobre su caja 2:3 y la llena;
		// la de la ficha y el blog, al ancho de su contenedor.
		const hueco = 'absolute' === estilo.position ? propia : Math.min( caja, techo );
		return { declarado: resolver( i.getAttribute( 'sizes' ) || '' ), hueco, sizes: i.getAttribute( 'sizes' ) || '' };
	} );
}

/**
 * El `sizes` no puede declarar de menos (portada blanda) ni de más salvo la
 * barra de desplazamiento clásica de escritorio, que el `vw` no descuenta (14 px
 * en el tema; ×0,48 en la ficha).
 *
 * @param {{declarado: number, hueco: number, sizes: string}} m Medida.
 * @param {string} donde Superficie.
 */
function exigirAlPixel( m, donde ) {
	expect( m.declarado, `${ donde }: sizes «${ m.sizes }»` ).toBeGreaterThanOrEqual( m.hueco - 0.5 );
	expect( m.declarado, `${ donde }: sizes «${ m.sizes }» declara de más` ).toBeLessThanOrEqual( m.hueco + 16 );
}

test.describe( 'imágenes al tamaño en que se pintan', () => {

	test( 'la portada de la ficha declara su hueco y se baja UNA vez (preload y <img> eligen el mismo archivo)', async ( { page } ) => {
		await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
		const enlace = page.locator( 'li.product a.woocommerce-LoopProduct-link' ).first();
		await enlace.waitFor( { state: 'attached', timeout: 15_000 } );
		const ficha = await enlace.getAttribute( 'href' );
		expect( ficha ).toBeTruthy();

		const respuesta = await page.goto( String( ficha ), { waitUntil: 'load' } );
		expect( respuesta?.status() ).toBe( 200 );

		const portada = page.locator( '.woocommerce-product-gallery__image img.wp-post-image' ).first();
		await expect( portada ).toBeVisible();
		exigirAlPixel( await medir( portada ), 'ficha' );

		// El preload lleva el MISMO imagesizes que el <img>: si difirieran, el
		// escáner bajaría un candidato y el <img> otro (doble descarga, medida en 2026-08-17).
		const preload = page.locator( 'link[rel="preload"][as="image"][imagesrcset]' ).first();
		if ( await preload.count() ) {
			expect( await preload.getAttribute( 'imagesizes' ) ).toBe( await portada.getAttribute( 'sizes' ) );
		}

		const candidatos = await portada.evaluate( ( i ) => ( i.getAttribute( 'srcset' ) || '' ).split( ',' ).map( ( c ) => new URL( c.trim().split( /\s+/ )[ 0 ], location.href ).pathname ) );
		const bajados = await page.evaluate( ( lista ) => performance.getEntriesByType( 'resource' ).map( ( r ) => new URL( r.name ).pathname ).filter( ( p ) => lista.includes( p ) ), candidatos );
		expect( bajados, 'candidatos de la portada descargados' ).toHaveLength( 1 );
	} );

	test( 'la venta cruzada del 404 pide la portada del ancho de su celda', async ( { page } ) => {
		const respuesta = await page.goto( '/esta-pagina-no-existe-t1-sizes/', { waitUntil: 'load' } );
		expect( respuesta?.status() ).toBe( 404 );

		const portadas = page.locator( '.akb-empty__cross-sell-grid li.product img' );
		test.skip( 0 === await portadas.count(), 'sin productos populares no hay venta cruzada' );
		for ( let i = 0; i < await portadas.count(); i++ ) {
			exigirAlPixel( await medir( portadas.nth( i ) ), `404, portada ${ i + 1 }` );
		}
	} );

	test( 'la venta cruzada del carrito vacío pide la portada del ancho de su celda', async ( { page } ) => {
		await page.context().clearCookies();
		const respuesta = await page.goto( '/carrito/', { waitUntil: 'load' } );
		expect( respuesta?.status() ).toBe( 200 );

		const portadas = page.locator( '.akb-empty__cross-sell-grid li.product img' );
		test.skip( 0 === await portadas.count(), 'sin productos populares no hay venta cruzada' );
		for ( let i = 0; i < await portadas.count(); i++ ) {
			exigirAlPixel( await medir( portadas.nth( i ) ), `carrito vacío, portada ${ i + 1 }` );
		}
	} );

	test( 'el blog: la primera tarjeta y la cabecera de la entrada declaran su hueco', async ( { page } ) => {
		const respuesta = await page.goto( '/blog/', { waitUntil: 'load' } );
		expect( respuesta?.status() ).toBe( 200 );

		const tarjeta = page.locator( 'img.akb-blog-card__img' ).first();
		test.skip( 0 === await tarjeta.count(), 'el blog no tiene entradas con imagen' );
		exigirAlPixel( await medir( tarjeta ), 'tarjeta del blog' );

		const entrada = await page.locator( 'a.akb-blog-card__media' ).first().getAttribute( 'href' );
		const r2 = await page.goto( String( entrada ), { waitUntil: 'load' } );
		expect( r2?.status() ).toBe( 200 );
		const cabecera = page.locator( 'img.akb-post__img' );
		test.skip( 0 === await cabecera.count(), 'la entrada no tiene cabecera' );
		exigirAlPixel( await medir( cabecera ), 'cabecera de la entrada' );
	} );
} );
