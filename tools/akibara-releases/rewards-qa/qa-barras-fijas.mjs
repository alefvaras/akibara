#!/usr/bin/env node
/**
 * qa-barras-fijas.mjs — ¿alguna barra fija tapa contenido o controles?
 *
 * Mide la ocupación REAL de la franja inferior (elementos fixed/sticky
 * PINTADOS y apilados) y la contrasta con el `scroll-padding-bottom` que la
 * página reserva. Después enfoca los controles del contenido —lo que hace el
 * usuario al tabular— y reporta cuáles quedan debajo de esa franja.
 *
 * Sale con código 1 si encuentra déficit o controles tapados, para poder
 * usarlo como puerta; con 2 si los argumentos no valen o la URL no responde
 * 2xx (una página de error no se mide).
 *
 * Uso:
 *   node qa-barras-fijas.mjs <url> [ancho] [alto] [--scroll=0.6]
 *
 * Ejemplos:
 *   node qa-barras-fijas.mjs https://akibara.cl/la-nobleza-de-las-flores-16-milky-way/ 375 812
 *   node qa-barras-fijas.mjs http://localhost:8080/wp-admin/admin.php?page=akibara-panel 375 812
 *
 * CUATRO TRAMPAS QUE ESTA SONDA YA ESQUIVA (las cuatro dieron falsos
 * positivos el 2026-09-11 y están aquí para que no se repitan):
 *
 *  1. Overlays CERRADOS que siguen midiendo la pantalla entera:
 *     `#akb-native-cart-overlay` y el cajón del carrito miden 812px con el
 *     carrito cerrado. Daban «55 controles tapados, peor 740px», todo falso.
 *     Por eso se exige que el elemento esté PINTADO (`elementsFromPoint` sobre
 *     su propio centro), no sólo presente en el DOM.
 *  2. Muestrear sólo el último píxel de la ventana: devuelve la barra de abajo
 *     y esconde la que va apilada encima. En la PDP móvil daba 66px cuando la
 *     ocupación real son 132px. Por eso el techo se calcula con el `top` MÍNIMO
 *     de todos los fijos pintados de la franja.
 *  3. Contenido dentro de un `<details>` plegado: mide miles de px y
 *     `offsetParent` no lo filtra.
 *  4. Controles `disabled`: no se tabulan, así que medir su caja tras un
 *     `focus()` que no hizo nada inventa fallos.
 */
import { chromium } from 'playwright';
import { ErrorDeUso, cargar, leerFraccion, leerObjetivo, salirPorUso } from './lib/objetivo.mjs';

const USO = 'Uso: node qa-barras-fijas.mjs <url> [ancho] [alto] [--scroll=0.6]';

// Los argumentos se validan ANTES de abrir el navegador: una URL con espacios
// o un viewport que no es entero producen números que parecen de verdad
// (véase lib/objetivo.mjs).
let url, W, H, posicion;
try {
	const objetivo = leerObjetivo( process.argv );
	( { url, ancho: W, alto: H } = objetivo );
	posicion = leerFraccion( objetivo.resto, '--scroll', 0.6 );
} catch ( error ) {
	if ( error instanceof ErrorDeUso ) salirPorUso( error, USO );
	throw error;
}
const esMovil = W < 768;

const navegador = await chromium.launch();
const contexto = await navegador.newContext( {
	viewport: { width: W, height: H },
	isMobile: esMovil,
	hasTouch: esMovil,
	deviceScaleFactor: esMovil ? 2 : 1,
	// El qlog es la voz del cliente: las sondas se marcan para no ensuciarlo.
	userAgent: esMovil
		? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 AkibaraQA'
		: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 AkibaraQA',
} );
const pagina = await contexto.newPage();
try {
	await cargar( pagina, url );
} catch ( error ) {
	await navegador.close();
	if ( error instanceof ErrorDeUso ) salirPorUso( error, USO );
	throw error;
}
await pagina.waitForTimeout( 1500 );

const medida = await pagina.evaluate( async ( fraccion ) => {
	const maximo = document.documentElement.scrollHeight - window.innerHeight;
	window.scrollTo( 0, Math.round( Math.max( 0, maximo ) * fraccion ) );
	await new Promise( ( listo ) => setTimeout( listo, 900 ) );

	const pintado = ( el ) => {
		const c = el.getBoundingClientRect();
		if ( c.height <= 0 || c.width <= 0 ) return false;
		const x = Math.min( Math.max( c.left + c.width / 2, 1 ), window.innerWidth - 1 );
		const y = Math.min( Math.max( c.top + c.height / 2, 1 ), window.innerHeight - 1 );
		return ( document.elementsFromPoint( x, y ) || [] ).some( ( n ) => n === el || el.contains( n ) );
	};

	const nombre = ( el ) => el.tagName.toLowerCase()
		+ ( el.id ? '#' + el.id : '' )
		+ ( typeof el.className === 'string' && el.className
			? '.' + el.className.trim().split( /\s+/ ).slice( 0, 2 ).join( '.' ) : '' );

	const fijos = [ ...document.querySelectorAll( 'body *' ) ].filter( ( el ) => {
		const cs = getComputedStyle( el );
		if ( cs.position !== 'fixed' && cs.position !== 'sticky' ) return false;
		const c = el.getBoundingClientRect();
		return c.height > 0 && c.width > 60
			&& c.top < window.innerHeight
			&& c.bottom > window.innerHeight - 220
			&& pintado( el );
	} ).map( ( el ) => {
		const c = el.getBoundingClientRect();
		return { sel: nombre( el ), alto: Math.round( c.height ), top: Math.round( c.top ) };
	} );

	const techo = fijos.length ? Math.min( ...fijos.map( ( f ) => f.top ) ) : window.innerHeight;
	const ocupado = Math.round( window.innerHeight - techo );
	const reservado = Math.round(
		parseFloat( getComputedStyle( document.documentElement ).scrollPaddingBottom ) || 0
	);

	const visible = ( el ) => {
		if ( el.offsetParent === null ) return false;
		for ( let a = el; a; a = a.parentElement ) {
			if ( a.tagName === 'DETAILS' && ! a.open ) return false;
		}
		return true;
	};

	const controles = [ ...document.querySelectorAll(
		'main a[href], main button, main input:not([type=hidden]), main select, main textarea,'
		+ '.site-content a[href], .site-content button,'
		+ '.akb-panel-content a[href], .akb-panel-content button,'
		+ '.akb-panel-content input:not([type=hidden]), .akb-panel-content select, .akb-panel-content textarea'
	) ].filter( ( c ) => visible( c ) && ! c.disabled
		&& c.getAttribute( 'aria-disabled' ) !== 'true' && c.tabIndex >= 0 );

	const tapados = [];
	let peor = 0;

	for ( const control of controles ) {
		control.focus();
		const c = control.getBoundingClientRect();
		if ( c.height === 0 || c.top >= window.innerHeight || c.bottom <= 0 ) continue;

		/* Se mide contra el techo GLOBAL de la franja (el `top` más alto de los
		 * fijos pintados), no contra lo que devuelva el último píxel de la
		 * ventana: ahí sólo aparece la barra de abajo y la que va apilada
		 * encima quedaría sin contar — trampa 2 de la cabecera. */
		const solape = Math.round( Math.max( 0, c.bottom - techo ) );
		if ( solape > 0 ) {
			peor = Math.max( peor, solape );
			if ( tapados.length < 8 ) {
				tapados.push( {
					control: ( control.id || control.name || control.textContent || control.tagName )
						.toString().trim().replace( /\s+/g, ' ' ).slice( 0, 40 ),
					solape,
				} );
			}
		}
	}

	return { fijos, ocupado, reservado, deficit: ocupado - reservado, controles: controles.length, tapados, peor };
}, posicion );

console.log( `URL: ${url}` );
console.log( `Viewport: ${W}x${H} · scroll a ${Math.round( posicion * 100 )}% de la página\n` );
console.log( 'Fijos pintados en la franja inferior:' );
for ( const f of medida.fijos ) {
	console.log( `  ${f.sel} — ${f.alto}px (top ${f.top})` );
}
if ( ! medida.fijos.length ) console.log( '  (ninguno)' );
console.log( `\nOcupado real: ${medida.ocupado}px · reservado por scroll-padding: ${medida.reservado}px · DÉFICIT: ${medida.deficit}px` );
console.log( `Controles enfocados: ${medida.controles} · tapados: ${medida.tapados.length ? medida.tapados.length + '+' : 0} · peor: ${medida.peor}px` );
for ( const t of medida.tapados ) {
	console.log( `  «${t.control}» tapado ${t.solape}px` );
}

await navegador.close();

const mal = medida.deficit > 0 || medida.peor > 0;
console.log( `\n${mal ? '✗ HAY DÉFICIT O CONTROLES TAPADOS' : '✓ nada tapado y la reserva cubre la franja'}` );
process.exit( mal ? 1 : 0 );
