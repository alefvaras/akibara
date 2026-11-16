/**
 * sonda-superficies-cards.mjs — CASO 1 del gate de SUPERFICIES Y CACHÉ.
 *
 * Cuenta CARDS SERVIDAS en el DOM real de cada listado. Un 200 con el título
 * perfecto puede servir CERO productos (regla de la casa: «verificar listados
 * es contar cards»), así que ningún check mira el status code.
 *
 * Listados medidos:
 *   /tienda/                         >= 24 cards
 *   /a-pedido/                       > 0
 *   /genero/accion/                  > 0 y el botón «Añadir al carrito» SUBE
 *                                    el contador del header (click real)
 *   /categoria-producto/manga/shonen/ > 0   (el «shonen» de la casa es
 *                                    product_cat, no pa_genero: /genero/shonen/
 *                                    es 404 legítimo — comprobado aquí)
 *   /autor/eiichiro-oda/             > 0
 *   /editoriales/                    grid con marcas + filtro que RESPONDE
 *   /series/                         directorio con contador de tomos
 *
 * Restaura: vacía el carrito de su propia sesión al terminar (contexto de
 * navegador efímero: no toca la base). No siembra NADA.
 *
 * Uso: node sonda-superficies-cards.mjs   (exit != 0 si algo falla)
 */

import {
	BASE, MOVIL, ESCRITORIO, abrir, crearMarcador, vaciarCarrito, contarCarrito,
} from './lib-bordes.mjs';

const m = crearMarcador( 'superficies-cards' );

/** Cuenta las cards del loop en el DOM SERVIDO (no en el HTML crudo). */
async function contarCards( pagina ) {
	return pagina.evaluate( () => {
		const items = document.querySelectorAll( 'ul.products li.product' );
		const titulos = document.querySelectorAll( '.woocommerce-loop-product__title' );
		const atc = document.querySelectorAll( 'button.add_to_cart_button, a.add_to_cart_button' );
		return {
			li: items.length,
			titulos: titulos.length,
			atc: atc.length,
			primero: titulos.length ? ( titulos[ 0 ].textContent || '' ).trim() : '',
			titulo: ( document.title || '' ).slice( 0, 60 ),
		};
	} );
}

let navegador = null;

try {
	const sesion = await abrir( ESCRITORIO );
	navegador = sesion.navegador;
	const pagina = sesion.pagina;

	/* ------------------------------------------------------------------
	 * 1.1 /tienda/ — el listado madre.
	 * --------------------------------------------------------------- */
	await pagina.goto( `${ BASE }/tienda/`, { waitUntil: 'domcontentloaded' } );
	const tienda = await contarCards( pagina );
	m.check(
		'tienda-sirve-al-menos-24-cards',
		tienda.li >= 24 && tienda.titulos >= 24,
		`li.product=${ tienda.li } titulos=${ tienda.titulos } atc=${ tienda.atc } primero="${ tienda.primero }"`
	);

	/* ------------------------------------------------------------------
	 * 1.2 /a-pedido/ — la faceta comercial.
	 * --------------------------------------------------------------- */
	await pagina.goto( `${ BASE }/a-pedido/`, { waitUntil: 'domcontentloaded' } );
	const aPedido = await contarCards( pagina );
	m.check(
		'a-pedido-sirve-productos',
		aPedido.li > 0,
		`li.product=${ aPedido.li } titulos=${ aPedido.titulos } primero="${ aPedido.primero }"`
	);

	/* ------------------------------------------------------------------
	 * 1.3 Género real: pa_genero tiene rewrite /genero. «shonen» NO es un
	 *     término de pa_genero (es product_cat), así que se miden los dos:
	 *     el género real sirve cards y el 404 de /genero/shonen/ se deja
	 *     documentado con su causa.
	 * --------------------------------------------------------------- */
	const resp404 = await pagina.goto( `${ BASE }/genero/shonen/`, { waitUntil: 'domcontentloaded' } );
	m.info( 'genero-shonen-404-esperado', `HTTP ${ resp404.status() } (shonen vive en product_cat, no en pa_genero)` );

	await pagina.goto( `${ BASE }/genero/accion/`, { waitUntil: 'domcontentloaded' } );
	const genero = await contarCards( pagina );
	m.check(
		'genero-accion-sirve-productos',
		genero.li > 0 && genero.atc > 0,
		`li.product=${ genero.li } botones-atc=${ genero.atc } primero="${ genero.primero }"`
	);

	/* ------------------------------------------------------------------
	 * 1.4 El botón del catálogo TIENE que funcionar: click real y el
	 *     contador del header (data-akb-cart-count) debe subir. Medir el
	 *     Store API además del atributo evita cantar victoria con un
	 *     contador optimista que no llegó a la base.
	 * --------------------------------------------------------------- */
	await vaciarCarrito( pagina );
	await pagina.goto( `${ BASE }/genero/accion/`, { waitUntil: 'domcontentloaded' } );
	const contadorAntes = await pagina.evaluate(
		() => document.querySelector( '[data-akb-cart-count]' )?.getAttribute( 'data-akb-cart-count' )
	);

	const boton = pagina.locator( 'ul.products li.product button.add_to_cart_button, ul.products li.product a.add_to_cart_button' ).first();
	const nombreProducto = await pagina
		.locator( 'ul.products li.product' ).first()
		.locator( '.woocommerce-loop-product__title' ).first()
		.textContent();
	await boton.click();

	// El contador se rehidrata por evento, no por navegación: se espera al
	// cambio del atributo, no a un `networkidle` que aquí nunca llega.
	await pagina
		.waitForFunction(
			( antes ) =>
				document.querySelector( '[data-akb-cart-count]' )?.getAttribute( 'data-akb-cart-count' ) !== antes,
			contadorAntes,
			{ timeout: 20000 }
		)
		.catch( () => {} );

	const contadorDespues = await pagina.evaluate(
		() => document.querySelector( '[data-akb-cart-count]' )?.getAttribute( 'data-akb-cart-count' )
	);
	const carritoReal = await contarCarrito( pagina );

	m.check(
		'boton-anadir-al-carrito-del-catalogo-funciona',
		Number( contadorDespues ) > Number( contadorAntes ) && carritoReal.lineas === 1,
		`producto="${ ( nombreProducto || '' ).trim() }" contador ${ contadorAntes }->${ contadorDespues } · store-api lineas=${ carritoReal.lineas } cantidad=${ carritoReal.cantidad } ids=${ carritoReal.ids.join( ',' ) }`
	);

	const vaciado = await vaciarCarrito( pagina );
	const tras = await contarCarrito( pagina );
	m.check( 'carrito-de-la-sonda-vaciado', tras.lineas === 0, `retiradas=${ vaciado } lineas_finales=${ tras.lineas }` );

	/* ------------------------------------------------------------------
	 * 1.5 Categoría shonen (la que existe de verdad).
	 * --------------------------------------------------------------- */
	await pagina.goto( `${ BASE }/categoria-producto/manga/shonen/`, { waitUntil: 'domcontentloaded' } );
	const shonen = await contarCards( pagina );
	m.check(
		'categoria-shonen-sirve-productos',
		shonen.li > 0,
		`li.product=${ shonen.li } primero="${ shonen.primero }"`
	);

	/* ------------------------------------------------------------------
	 * 1.6 Autor real.
	 * --------------------------------------------------------------- */
	await pagina.goto( `${ BASE }/autor/eiichiro-oda/`, { waitUntil: 'domcontentloaded' } );
	const autor = await contarCards( pagina );
	m.check(
		'autor-eiichiro-oda-sirve-productos',
		autor.li > 0,
		`li.product=${ autor.li } primero="${ autor.primero }" titulo="${ autor.titulo }"`
	);

	/* ------------------------------------------------------------------
	 * 1.7 /editoriales/ — grid de marcas y filtro VIVO.
	 * --------------------------------------------------------------- */
	await pagina.goto( `${ BASE }/editoriales/`, { waitUntil: 'domcontentloaded' } );
	const marcas = await pagina.evaluate( () => {
		const cards = [ ...document.querySelectorAll( '.akb-brand-card' ) ];
		const visible = ( n ) => !! ( n && ( n.offsetWidth || n.offsetHeight || n.getClientRects().length ) );
		return {
			total: cards.length,
			visibles: cards.filter( visible ).length,
			nombres: cards.slice( 0, 4 ).map( ( c ) => ( c.querySelector( '.akb-brand-card__name' )?.textContent || '' ).trim() ),
			conConteo: cards.filter( ( c ) => /\d/.test( c.querySelector( '.akb-brand-card__count' )?.textContent || '' ) ).length,
		};
	} );
	m.check(
		'editoriales-grid-con-marcas',
		marcas.visibles > 0 && marcas.conConteo === marcas.total,
		`cards=${ marcas.total } visibles=${ marcas.visibles } con_conteo=${ marcas.conConteo } nombres=[${ marcas.nombres.join( ' | ' ) }]`
	);

	// El filtro tiene que MOVER el grid, no sólo existir.
	await pagina.fill( '.akb-brands-filterbar__input', 'ivrea' );
	await pagina.waitForTimeout( 700 );
	const filtrado = await pagina.evaluate( () => {
		const visible = ( n ) => !! ( n && ( n.offsetWidth || n.offsetHeight || n.getClientRects().length ) );
		const cards = [ ...document.querySelectorAll( '.akb-brand-card' ) ];
		return {
			visibles: cards.filter( visible ).length,
			estado: ( document.querySelector( '.akb-brands-filterbar__status-text' )?.textContent || '' ).trim(),
		};
	} );
	m.check(
		'editoriales-filtro-responde',
		filtrado.visibles > 0 && filtrado.visibles < marcas.visibles,
		`«ivrea» deja ${ filtrado.visibles } de ${ marcas.visibles } visibles · estado="${ filtrado.estado }"`
	);

	await pagina.fill( '.akb-brands-filterbar__input', 'zzzxxx' );
	await pagina.waitForTimeout( 700 );
	const filtroVacio = await pagina.evaluate( () => {
		const visible = ( n ) => !! ( n && ( n.offsetWidth || n.offsetHeight || n.getClientRects().length ) );
		return {
			visibles: [ ...document.querySelectorAll( '.akb-brand-card' ) ].filter( visible ).length,
			estado: ( document.querySelector( '.akb-brands-filterbar__status-text' )?.textContent || '' ).trim(),
		};
	} );
	m.check(
		'editoriales-filtro-sin-coincidencias-avisa',
		filtroVacio.visibles === 0 && filtroVacio.estado.length > 0,
		`«zzzxxx» deja ${ filtroVacio.visibles } visibles · estado="${ filtroVacio.estado }"`
	);

	/* ------------------------------------------------------------------
	 * 1.8 /series/ — directorio con contador de tomos.
	 * --------------------------------------------------------------- */
	await pagina.goto( `${ BASE }/series/`, { waitUntil: 'domcontentloaded' } );
	const series = await pagina.evaluate( () => {
		const items = [ ...document.querySelectorAll( '.akb-series-directory__item' ) ];
		const conTomos = items.filter( ( i ) => /\d/.test( i.querySelector( '.akb-series-directory__tomos' )?.textContent || '' ) );
		return {
			total: items.length,
			conTomos: conTomos.length,
			muestra: items.slice( 0, 3 ).map( ( i ) =>
				`${ ( i.querySelector( '.akb-series-directory__name' )?.textContent || '' ).trim() }=${ ( i.querySelector( '.akb-series-directory__tomos' )?.textContent || '' ).trim() }`
			),
			letras: document.querySelectorAll( '.akb-series-directory__letter' ).length,
		};
	} );
	m.check(
		'series-directorio-con-contador',
		series.total > 0 && series.conTomos === series.total,
		`items=${ series.total } con_contador=${ series.conTomos } letras=${ series.letras } muestra=[${ series.muestra.join( ' | ' ) }]`
	);
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, `EXCEPCIÓN: ${ error.message }` );
} finally {
	if ( navegador ) {
		await navegador.close();
	}
}

process.exit( m.cerrar() );
