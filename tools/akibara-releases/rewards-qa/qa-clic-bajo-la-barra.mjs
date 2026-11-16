#!/usr/bin/env node
/**
 * qa-clic-bajo-la-barra.mjs — ¿un toque sobre un control parcialmente tapado
 * por la barra de compra llega al control?
 *
 * Complementa a qa-barras-fijas.mjs, que enfoca con `focus()` programático y
 * por eso NO ve este fallo: la red de seguridad de franja-inferior.js corre
 * en `focusin`, y un clic real la dispara ENTRE el mousedown y el mouseup. Si
 * la red desplaza ahí, el control se mueve bajo el dedo, el mouseup cae en
 * otro nodo y el navegador despacha el `click` en el ancestro común (el <li>
 * de la card, no el botón «Añadir al carrito»). Medido en el espejo el
 * 2026-09-11: con el desplazamiento, `click→LI.product`; sin él,
 * `click→BUTTON.button`. Desde entonces la red ignora el foco por puntero.
 *
 * Qué hace: carga la ficha, deja el primer botón de la grilla de relacionados
 * con ~22px bajo la barra, hace un clic REAL (mouse.down + mouse.up) sobre su
 * parte visible y comprueba que mousedown, mouseup y click caen en el MISMO
 * nodo y que la página no se movió. El click se para en captura para que
 * ningún handler de la tienda navegue: aquí sólo importa quién lo recibe.
 *
 * Sale con código 1 si el click no llega al botón o si la página se movió;
 * con 2 si los argumentos no valen o la URL no responde 2xx.
 *
 * Uso:
 *   node qa-clic-bajo-la-barra.mjs <url-de-ficha-con-relacionados> [ancho] [alto]
 */
import { chromium } from 'playwright';
import { ErrorDeUso, cargar, leerObjetivo, salirPorUso } from './lib/objetivo.mjs';

const USO = 'Uso: node qa-clic-bajo-la-barra.mjs <url-de-ficha-con-relacionados> [ancho] [alto]';

// Los argumentos se validan ANTES de abrir el navegador (véase lib/objetivo.mjs).
let url, W, H;
try {
	( { url, ancho: W, alto: H } = leerObjetivo( process.argv ) );
} catch ( error ) {
	if ( error instanceof ErrorDeUso ) salirPorUso( error, USO );
	throw error;
}

const navegador = await chromium.launch();
const contexto = await navegador.newContext( {
	viewport: { width: W, height: H },
	isMobile: true,
	hasTouch: true,
	deviceScaleFactor: 2,
	// El qlog es la voz del cliente: las sondas se marcan para no ensuciarlo.
	userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 AkibaraQA',
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

const objetivo = await pagina.evaluate( async () => {
	const barra = document.querySelector( '.akb-sticky-atc' );
	const boton = document.querySelector( 'ul.products .add_to_cart_button, ul.products button.button, .related .button' );
	if ( ! barra || ! boton ) return null;

	const techo = () => barra.getBoundingClientRect().top;
	window.scrollTo( 0, 0 );
	await new Promise( ( listo ) => setTimeout( listo, 300 ) );
	const y = boton.getBoundingClientRect().top + window.scrollY;
	// Deja el botón asomando: su fondo 22px por debajo del techo de la barra.
	window.scrollTo( 0, y - ( techo() - boton.getBoundingClientRect().height ) - 22 );
	await new Promise( ( listo ) => setTimeout( listo, 900 ) );

	const caja = boton.getBoundingClientRect();
	window.__eventos = [];
	for ( const tipo of [ 'mousedown', 'focusin', 'mouseup', 'click' ] ) {
		document.addEventListener( tipo, ( e ) => {
			window.__eventos.push( {
				tipo,
				nodo: e.target.tagName + '.' + ( e.target.className || '' ).toString().split( ' ' )[ 0 ],
				scrollY: Math.round( window.scrollY ),
			} );
			if ( tipo === 'click' ) {
				e.stopPropagation();
				e.preventDefault();
			}
		}, true );
	}

	return {
		x: caja.left + caja.width / 2,
		// Punto dentro de la parte VISIBLE del botón (2px por encima de la barra).
		y: caja.top + Math.min( caja.height / 2, Math.max( 1, techo() - caja.top - 2 ) ),
		tapado: Math.round( caja.bottom - techo() ),
		scrollY: Math.round( window.scrollY ),
		nodo: boton.tagName + '.' + ( boton.className || '' ).toString().split( ' ' )[ 0 ],
	};
} );

if ( ! objetivo ) {
	console.error( 'La página no tiene barra de compra o grilla de relacionados: elige otra ficha.' );
	await navegador.close();
	process.exit( 2 );
}

await pagina.mouse.move( objetivo.x, objetivo.y );
await pagina.mouse.down();
await pagina.mouse.up();
await pagina.waitForTimeout( 300 );

const eventos = await pagina.evaluate( () => window.__eventos );
await navegador.close();

console.log( `URL: ${url}` );
console.log( `Viewport: ${W}x${H} · objetivo ${objetivo.nodo} con ${objetivo.tapado}px bajo la barra · scrollY ${objetivo.scrollY}\n` );
for ( const e of eventos ) {
	console.log( `  ${e.tipo} → ${e.nodo} (scrollY ${e.scrollY})` );
}

const click = eventos.find( ( e ) => e.tipo === 'click' );
const seMovio = eventos.some( ( e ) => e.scrollY !== objetivo.scrollY );
const mal = ! click || click.nodo !== objetivo.nodo || seMovio;

console.log( `\n${mal ? '✗ EL TOQUE NO LLEGA AL CONTROL O LA PÁGINA SE MOVIÓ BAJO EL DEDO' : '✓ el click llega al control y la página no se movió'}` );
process.exit( mal ? 1 : 0 );
