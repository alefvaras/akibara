// @ts-check
/**
 * Correcciones de la QA integral del 2026-09-24 sobre akibara-business, segunda
 * ronda (lote QF-business). Cada prueba nace de un hallazgo medido y falla sin
 * su arreglo:
 *
 *  - QAM-4: tocar un chip de disponibilidad («Disponibles») abría el modo
 *    «Elegir tomos»: el formulario iba a `#completar-coleccion`, el fragmento
 *    que abre la selección. Quien sí estaba eligiendo tomos no lo pierde.
 *  - QA-BIZ-DIFF-4: `?paged=2` de una serie que ya cabe en una página daba 404
 *    mientras `/page/2/` daba 301.
 *  - QA-BIZ-DIFF-1: sin las reglas del tema para «Vista rápida» (el tema de
 *    producción 2.1.142 no tiene), el botón era uno nativo de 22 px.
 *  - QA2-PANEL-6: en el teléfono, el enlace de la barra de anuncios medía 22 px
 *    de alto en una barra de 44: un toque arriba o abajo de las letras no
 *    llevaba a ninguna parte. Y su primer arreglo, con relleno, dejaba ver la
 *    tercera línea de un mensaje largo bajo la segunda (REV-QFB-1).
 *    Con el foco del teclado la tercera línea volvía a asomar (REV-FN-1), en
 *    WebKit el subrayado de 2 px de un enlace tocado perdía una fila
 *    (REV-FN-2), y con colores forzados el borde que agranda el enlace se
 *    pintaba como barras sobre y bajo las letras. Las tildes de la tercera
 *    línea (TAMBIÉN, ENVÍO) seguían asomando, con enlace o sin él, en
 *    Chromium y en WebKit con densidad 2 (REV-FN1-1). Su arreglo, la línea de
 *    1.3, hacía crecer la cinta de dos líneas a 39,2 px y la barra le comía
 *    un tercio de la sombra roja y del anillo de foco (REV-FN2-1); con el
 *    relleno de 3px mide 37,2. Un emoji en la tercera línea todavía deja un
 *    puntito en el subrayado de la segunda: es un límite conocido, y se
 *    vigila que no suba a las letras (REV-FN2-2).
 *  - QAM-6: el motivo de «ya tienes todo en el carrito» decía «tenemos 1
 *    existencias»; ahora es un texto del dueño con el singular bien puesto.
 *  - QA2-PANEL-7 (REV-QFB-3): la pestaña Textos del panel medía 425 px de ancho
 *    en un teléfono de 390: el correo de Contacto no estaba topado.
 *
 * Escriben la del carrito (la sesión de la prueba, que se borra al terminar) y
 * la del panel (un token de sesión de un administrador de PRUEBAS, que se
 * destruye al terminar): las dos se saltan contra producción.
 */

import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { expect, test, webkit } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';
import { borrarSesionWcAlTerminar } from './soporte/tema-espejo.mjs';

const RAIZ = new URL( '../../../', import.meta.url ).pathname;

/**
 * PHP por STDIN dentro del espejo: nada se escribe en el docroot.
 *
 * @param {string} php Código PHP (con su `<?php`).
 * @returns {string} Lo que imprimió.
 */
function wpEval( php ) {
	return execFileSync(
		'docker',
		[ 'compose', '--profile', 'tools', 'run', '--rm', '-T', 'cli', 'wp', 'eval-file', '-' ],
		{ cwd: RAIZ, input: php, timeout: 180_000, encoding: 'utf8' }
	);
}

/** Serie de una sola página con 48 tomos por página y con tomos en varios estados. */
const SERIE = '/serie/jujutsu-kaisen/';

/** Texto de fábrica de `RejectionCopy::DEFAULT_YA_EN_CARRITO`. */
const YA_EN_CARRITO = 'Ya tienes en tu carrito todas las unidades que quedan de este tomo.';

/**
 * WebKit ya instalado en esta máquina, como el Chromium de la configuración:
 * el que pide esta versión de Playwright no está en la caché. Lo que sólo se
 * rompe en Safari se prueba abriéndolo desde la prueba. `AKB_WEBKIT` cambia la
 * ruta.
 */
const WEBKIT = process.env.AKB_WEBKIT || `${ process.env.HOME }/Library/Caches/ms-playwright/webkit-2342/pw_run.sh`;

/** Un mensaje-enlace que no cabe en dos líneas en ningún teléfono. */
const ANUNCIO_LARGO = 'Retiro sin costo en Metro San Miguel (Santiago), con día y hora coordinados por WhatsApp, '
	+ 'y también envío gratis a todo Chile por compras sobre el monto del panel';

/** Una tercera línea hecha de mayúsculas con tilde: lo que más sube de las letras. */
const TERCERA_CON_TILDES = 'Él ídolo ánimo ópalo último épico Ñuñoa índice órbita único Ícaro Ángel Óscar';

/** Una tercera línea con emoji a color, lo habitual en una cinta de promociones: suben más que una tilde. */
const TERCERA_CON_EMOJI = '🚚 🎁 ✨ 🔥 📦 ⭐ ❤️ 🇨🇱 ENVÍO';

/**
 * Cuánto de la segunda línea puede tocar un emoji de la tercera (REV-FN2-2):
 * la franja de su subrayado, que la tinta del emoji comparte. Medido: 1,6 px
 * en Chromium y 0,6 en WebKit con densidad 3. Más arriba ya son las letras.
 */
const FRANJA_DEL_SUBRAYADO = 2;

/** Alto de la cinta de dos líneas en el teléfono: 2 × 15,6 + 2 × 3 (REV-FN2-1; con 4px de relleno, 39,2). */
const CINTA_DOS_LINEAS = 37.2;

/**
 * Abre la portada y deja en la barra de anuncios un solo mensaje, convertido
 * en enlace con `texto`, sólo en el DOM de la prueba: lo que se mide es el
 * CSS. Con movimiento reducido la rotación no arranca: si no, a los 6 s pasaba
 * la posta a un mensaje ya quitado y el medido se apagaba entre dos capturas
 * de la misma medición. Con `enlace` falso, un mensaje que sólo informa.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} texto
 * @param {boolean} [enlace]
 * @returns {Promise<boolean>} Falso si la barra está apagada.
 */
async function anuncioEnlace( page, texto, enlace = true ) {
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	await page.goto( '/', { waitUntil: 'load' } );
	const activo = page.locator( '#akb-ann-bar .akb-ann-msg.akb-on' ).first();
	if ( 0 === ( await activo.count() ) ) {
		return false;
	}
	await activo.evaluate( ( msg, [ t, e ] ) => {
		document.querySelectorAll( '#akb-ann-bar .akb-ann-msg' ).forEach( ( x ) => x !== msg && x.remove() );
		msg.classList.toggle( 'akb-ann-msg--link', e );
		msg.innerHTML = e ? '<a class="akb-ann-msg-inner" href="#akb-e2e-anuncio"></a>' : '<span class="akb-ann-msg-inner"></span>';
		/** @type {HTMLElement} */ ( msg.firstChild ).textContent = t;
	}, /** @type {[string, boolean]} */ ( [ texto, enlace ] ) );
	return true;
}

/**
 * Dónde está el mensaje (enlace o no) de la barra, en px CSS desde el borde de
 * arriba de la barra. `fondo` y `techo` son los de la caja de contenido (las
 * líneas que se ven): se miden desde las letras y no desde el recorte que
 * resulte, que es justo lo que las pruebas tienen que vigilar.
 *
 * @param {import('@playwright/test').Page} page
 */
function geometriaDelAnuncio( page ) {
	return page.evaluate( () => {
		const a     = /** @type {HTMLElement} */ ( document.querySelector( '#akb-ann-bar .akb-ann-msg-inner' ) );
		const cs    = getComputedStyle( a );
		const barra = /** @type {HTMLElement} */ ( document.getElementById( 'akb-ann-bar' ) ).getBoundingClientRect();
		const r     = a.getBoundingClientRect();
		const m     = /** @type {HTMLElement} */ ( a.parentElement ).getBoundingClientRect();
		return {
			desborda: a.scrollHeight > a.clientHeight + 1,
			barra: { y: barra.top, alto: barra.height },
			fondo: r.bottom - parseFloat( cs.borderBottomWidth ) - parseFloat( cs.paddingBottom ) - barra.top,
			techo: r.top + parseFloat( cs.borderTopWidth ) + parseFloat( cs.paddingTop ) - barra.top,
			cinta: { x0: m.left, x1: m.right, y0: m.top - barra.top, y1: m.bottom - barra.top },
			enlace: { x0: r.left, x1: r.right },
			foco: a.matches( ':focus-visible' ),
			encima: a.matches( ':hover' ),
			grosor: cs.textDecorationThickness,
		};
	} );
}

/**
 * Captura la barra y la lee fila por fila entre `x.x0` y `x.x1`: los píxeles
 * claros (letras, subrayado, anillo), el tramo amarillo más largo (el
 * subrayado resaltado) y los que no son del color del fondo de la barra (su
 * esquina de arriba a la izquierda). `y` y `amarillo` en px CSS; `claros` y
 * `distintos` en píxeles de la captura.
 *
 * Con `cambiar`, se captura otra vez después de llamarla, y cada fila dice
 * también los claros de esa segunda captura (`clarosDespues`) y los píxeles
 * que cambian de una a otra, de cualquier color (`cambian`: un canal más de 24).
 *
 * @param {import('@playwright/test').Page} page
 * @param {{barra:{y:number,alto:number}}} caja
 * @param {{x0:number,x1:number}} x
 * @param {() => Promise<unknown>} [cambiar]
 * @returns {Promise<Array<{y:number,claros:number,amarillo:number,distintos:number,clarosDespues:number,cambian:number}>>}
 */
async function filasDeLaBarra( page, caja, x, cambiar ) {
	const vp       = /** @type {{width:number,height:number}} */ ( page.viewportSize() );
	const clip     = { x: 0, y: Math.round( caja.barra.y ), width: vp.width, height: Math.round( caja.barra.alto ) };
	const capturas = [ ( await page.screenshot( { clip } ) ).toString( 'base64' ) ];
	if ( cambiar ) {
		await cambiar();
		capturas.push( ( await page.screenshot( { clip } ) ).toString( 'base64' ) );
	}
	return page.evaluate( async ( { b64s, x0, x1, ancho } ) => {
		const datos = [];
		let escala = 1, xa = 0, xb = 0, alto = 0, f0 = new Uint8ClampedArray( 4 );
		for ( const b64 of b64s ) {
			const img = new Image();
			img.src   = 'data:image/png;base64,' + b64;
			await img.decode();
			escala = img.width / ancho;
			alto   = img.height;
			const lienzo = document.createElement( 'canvas' );
			lienzo.width  = img.width;
			lienzo.height = img.height;
			const ctx = /** @type {CanvasRenderingContext2D} */ ( lienzo.getContext( '2d' ) );
			ctx.drawImage( img, 0, 0 );
			xa = Math.max( 0, Math.ceil( x0 * escala ) );
			xb = Math.min( img.width, Math.floor( x1 * escala ) );
			datos.push( ctx.getImageData( xa, 0, xb - xa, img.height ).data );
			f0 = datos.length > 1 ? f0 : ctx.getImageData( 1, 1, 1, 1 ).data;
		}
		const claro = ( /** @type {Uint8ClampedArray} */ d, /** @type {number} */ i ) => 0.2126 * d[ i ] + 0.7152 * d[ i + 1 ] + 0.0722 * d[ i + 2 ] > 140;
		const [ d, e ] = datos;
		const filas = [];
		for ( let y = 0; y < alto; y++ ) {
			let claros = 0, amarillo = 0, seguido = 0, distintos = 0, clarosDespues = 0, cambian = 0;
			for ( let i = y * ( xb - xa ) * 4; i < ( y + 1 ) * ( xb - xa ) * 4; i += 4 ) {
				const [ r, g, b ] = [ d[ i ], d[ i + 1 ], d[ i + 2 ] ];
				distintos += Math.abs( r - f0[ 0 ] ) + Math.abs( g - f0[ 1 ] ) + Math.abs( b - f0[ 2 ] ) > 90 ? 1 : 0;
				claros    += claro( d, i ) ? 1 : 0;
				seguido    = r >= 90 && g >= 70 && b <= 60 && r > g && r - b > 70 ? seguido + 1 : 0;
				amarillo   = Math.max( amarillo, seguido );
				if ( e ) {
					clarosDespues += claro( e, i ) ? 1 : 0;
					cambian       += Math.max( Math.abs( r - e[ i ] ), Math.abs( g - e[ i + 1 ] ), Math.abs( b - e[ i + 2 ] ) ) > 24 ? 1 : 0;
				}
			}
			filas.push( { y: y / escala, claros, amarillo: amarillo / escala, distintos, clarosDespues, cambian } );
		}
		return filas;
	}, { b64s: capturas, x0: x.x0, x1: x.x1, ancho: vp.width } );
}

/**
 * @param {Array<Record<string, number>>} filas
 * @param {(f: Record<string, number>) => boolean} cuales
 * @param {string} campo
 */
function sumar( filas, cuales, campo ) {
	return filas.filter( cuales ).reduce( ( n, f ) => n + f[ campo ], 0 );
}

/**
 * Cuánto se ve de la TERCERA línea del anuncio, que el recorte a dos tiene que
 * esconder entera (REV-FN1-1). Desde su primera palabra, el texto pasa a un
 * <span>; con `tercera`, el span lleva ese texto tras un salto de línea, y las
 * dos primeras no cambian. Se captura la barra con el span visible y con
 * `color: transparent`, dentro de la cinta; lo que cambia entre las dos es lo
 * que asoma de la tercera, y sólo eso: el subrayado y el anillo de foco salen
 * en las dos y se restan solos.
 *
 * `claros`: los píxeles claros de más desde 1 px SOBRE el fondo de la segunda
 * línea hasta el borde de la barra (las letras). Contarlos desde fondo+1,5,
 * como antes, no veía las tildes: en Chromium empezaban 0,4 px sobre el fondo,
 * donde va el subrayado de la segunda. `cambian` y `sube`: los píxeles de
 * cualquier color que cambian en toda la barra (un canal más de 24) y cuánto
 * sube el más alto sobre ese fondo, en px CSS: un emoji no es claro, es de
 * color (REV-FN2-2).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string|null} tercera
 * @returns {Promise<{claros:number,cambian:number,sube:number}>} Píxeles de la captura, y `sube` en px CSS.
 */
async function loQueAsomaDeLaTercera( page, tercera ) {
	const partida = await page.evaluate( ( t ) => {
		if ( document.querySelector( '.akb-e2e-tercera' ) ) {
			return true;
		}
		const el    = /** @type {HTMLElement} */ ( document.querySelector( '#akb-ann-bar .akb-ann-msg-inner' ) );
		const texto = /** @type {Text} */ ( el.firstChild );
		const alto  = parseFloat( getComputedStyle( el ).lineHeight );
		const r     = document.createRange();
		/** @type {number|null} */
		let arriba = null;
		for ( let i = 0; i < texto.length; i++ ) {
			r.setStart( texto, i );
			r.setEnd( texto, i + 1 );
			const caja = r.getClientRects()[ 0 ];
			if ( ! caja || /\s/.test( texto.data[ i ] ) ) {
				continue;
			}
			arriba = null === arriba ? caja.top : arriba;
			if ( caja.top > arriba + 1.5 * alto ) {
				const resto = texto.splitText( i );
				const span  = document.createElement( 'span' );
				span.className   = 'akb-e2e-tercera';
				span.textContent = t || resto.data;
				if ( t ) {
					el.insertBefore( document.createElement( 'br' ), resto );
				}
				el.replaceChild( span, resto );
				return true;
			}
		}
		return false;
	}, tercera );
	expect( partida, 'el anuncio de la prueba tiene tercera línea' ).toBe( true );

	const caja   = await geometriaDelAnuncio( page );
	const pintar = ( /** @type {string} */ color ) => page.evaluate( ( c ) => {
		/** @type {HTMLElement} */ ( document.querySelector( '.akb-e2e-tercera' ) ).style.color = c;
	}, color );
	const filas = await filasDeLaBarra( page, caja, caja.cinta, () => pintar( 'transparent' ) );
	await pintar( '' );
	const conCambios = filas.filter( ( f ) => f.cambian );
	return {
		claros: filas.reduce( ( n, f ) => n + ( f.y >= caja.fondo - 1 ? Math.max( 0, f.claros - f.clarosDespues ) : 0 ), 0 ),
		cambian: conCambios.reduce( ( n, f ) => n + f.cambian, 0 ),
		sube: conCambios.reduce( ( m, f ) => Math.max( m, caja.fondo - f.y ), -Infinity ),
	};
}

/**
 * La cinta de dos líneas mide 37,2 px y bajo ella quedan libres 3,4 px de la
 * barra de 44 para la sombra roja de abajo, desplazada 4 px: la barra le
 * recorta 0,6 px (REV-FN2-1; con la cinta de 39,2 quedaban 2,4 y recortaba
 * 1,6). Con `reposo`, además, enfocar no mueve las letras: con el borde de
 * 2,8 px del intento descartado, que medía 2 con densidad 1, subían 0,4.
 *
 * @param {Awaited<ReturnType<typeof geometriaDelAnuncio>>} caja
 * @param {string} cual
 * @param {Awaited<ReturnType<typeof geometriaDelAnuncio>>} [reposo]
 */
function cintaDeDosLineas( caja, cual, reposo ) {
	expect( caja.cinta.y1 - caja.cinta.y0, `REV-FN2-1, ${ cual }: alto de la cinta de dos líneas` ).toBeCloseTo( CINTA_DOS_LINEAS, 0 );
	expect( caja.barra.alto - caja.cinta.y1, `REV-FN2-1, ${ cual }: lo libre bajo la cinta, donde va la sombra roja` ).toBeGreaterThanOrEqual( 3.3 );
	if ( reposo ) {
		expect( Math.abs( caja.techo - reposo.techo ), `${ cual }: enfocar no mueve las letras` ).toBeLessThan( 0.05 );
	}
}

/**
 * Lo que asoma de la tercera línea, según lo que lleve. Sin emoji, nada
 * (REV-FN1-1, REV-FN-1). Con emoji, las puntas quedan en la franja del
 * subrayado de la segunda y nunca en sus letras (REV-FN2-2): cuántos píxeles
 * son queda anotado en el informe de la prueba, a la vista.
 *
 * @param {{claros:number,cambian:number,sube:number}} asoma
 * @param {string|null} tercera
 * @param {string} cual
 */
function vigilar( asoma, tercera, cual ) {
	if ( TERCERA_CON_EMOJI !== tercera ) {
		expect( asoma.claros, `REV-FN1-1, ${ cual }: lo que asoma de la tercera línea` ).toBe( 0 );
		return;
	}
	test.info().annotations.push( { type: 'REV-FN2-2 (límite conocido)', description: `${ cual }: ${ asoma.cambian } px de la captura, hasta ${ asoma.cambian ? asoma.sube.toFixed( 2 ) : 0 } px sobre el fondo de la segunda línea` } );
	expect( asoma.sube, `REV-FN2-2, ${ cual }: las puntas del emoji no suben de la franja del subrayado` ).toBeLessThanOrEqual( FRANJA_DEL_SUBRAYADO );
}

test.describe( 'correcciones de la QA integral, segunda ronda (negocio)', () => {
	borrarSesionWcAlTerminar();

	test( 'un chip de disponibilidad filtra sin abrir «Elegir tomos»; con la selección abierta, la conserva', async ( { page } ) => {
		const r = await page.goto( SERIE, { waitUntil: 'load' } );
		expect( r?.status() ).toBe( 200 );
		test.skip( 0 === ( await page.locator( '#completar-coleccion' ).count() ), 'el pack está apagado en este entorno' );
		const chip = page.locator( '.akb-sb-landing__filter', { hasText: /disponibles/i } );
		test.skip( 0 === ( await chip.count() ), 'la serie no tiene tomos disponibles' );

		await Promise.all( [ page.waitForURL( /_disponibilidad=instock/ ), chip.click() ] );
		await page.waitForLoadState( 'load' );
		expect( new URL( page.url() ).hash, 'el ancla de los chips no es la que abre la selección' ).not.toBe( '#completar-coleccion' );
		await expect( page.locator( 'body' ), 'QAM-4: filtrar no abre el panel de elegir tomos' ).not.toHaveClass( /akb-sb-selection-active/ );
		await expect( page.locator( '[data-akb-sb-landing-panel]' ) ).toBeHidden();
		await expect( page.locator( '.akb-sb-landing__filter.is-active' ) ).toContainText( /disponibles/i );
		const cabecera = await page.locator( '.akb-sb-landing__head' ).boundingBox();
		expect( cabecera && cabecera.y < 400, 'y deja al cliente en la cabecera del riel, con los chips a la vista' ).toBe( true );

		// Con la selección abierta, cambiar de filtro la conserva.
		await page.locator( '[data-akb-sb-landing-start]' ).click();
		await expect( page.locator( 'body' ) ).toHaveClass( /akb-sb-selection-active/ );
		await Promise.all( [ page.waitForURL( ( u ) => ! u.search.includes( '_disponibilidad' ) ), page.locator( '.akb-sb-landing__filter', { hasText: /todos/i } ).click() ] );
		await page.waitForLoadState( 'load' );
		await expect( page.locator( 'body' ), 'quien estaba eligiendo tomos sigue eligiendo' ).toHaveClass( /akb-sb-selection-active/ );
		await expect( page.locator( '[data-akb-sb-landing-panel]' ) ).toBeVisible();
	} );

	test( '?paged=N fuera de rango redirige como /page/N/; con un orden sigue siendo 404', async ( { request } ) => {
		const directa = await request.get( SERIE + 'page/2/?utm_source=e2e', { maxRedirects: 0 } );
		test.skip( 301 !== directa.status(), 'la serie tiene más de una página en este entorno' );

		const conConsulta = await request.get( SERIE + '?paged=2&utm_source=e2e', { maxRedirects: 0 } );
		expect( conConsulta.status(), 'QA-BIZ-DIFF-4: la misma página, el mismo trato' ).toBe( 301 );
		const destino = new URL( conConsulta.headers().location );
		expect( destino.pathname ).toBe( SERIE );
		expect( destino.searchParams.get( 'paged' ), 'el destino no arrastra el número de página' ).toBeNull();
		expect( destino.searchParams.get( 'utm_source' ), 'la atribución viaja' ).toBe( 'e2e' );

		const conOrden = await request.get( SERIE + '?paged=2&orderby=price', { maxRedirects: 0 } );
		expect( conOrden.status(), 'una variante con orden no es la página limpia' ).toBe( 404 );
	} );

	test( 'sin las reglas del tema, «Vista rápida» conserva su contorno de 44 px', async ( { page, isMobile } ) => {
		test.skip( isMobile, 'en el teléfono no hay vista rápida' );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const boton = page.locator( 'li.product > .akb-qv-trigger:not([hidden])' ).first();
		test.skip( 0 === ( await boton.count() ), 'ningún tomo con sinopsis en la primera página' );

		const quitadas = await page.evaluate( () => {
			let n = 0;
			/** @param {CSSStyleSheet|CSSGroupingRule} lista */
			const barrer = ( lista ) => {
				for ( let i = lista.cssRules.length - 1; i >= 0; i-- ) {
					const regla = /** @type {CSSStyleRule & CSSGroupingRule} */ ( lista.cssRules[ i ] );
					if ( regla.cssRules && ! regla.selectorText ) {
						barrer( regla );
					} else if ( /li\.product\s*>\s*\.akb-qv-trigger/.test( regla.selectorText || '' ) ) {
						lista.deleteRule( i );
						n++;
					}
				}
			};
			for ( const hoja of Array.from( document.styleSheets ) ) {
				try {
					barrer( hoja );
				} catch ( e ) {
					// Hoja de otro origen: no es del tema.
				}
			}
			return n;
		} );
		// Con el tema nuevo se quitan sus reglas del botón; con uno que no las
		// trae (el 2.1.142 de producción) no hay nada que quitar y se mide igual.
		test.info().annotations.push( { type: 'reglas-del-tema-quitadas', description: String( quitadas ) } );

		// Quitar las reglas dispara la transición del fondo: se mide al terminar.
		// Transparente = alfa 0 (el navegador puede conservar los canales de color).
		await expect.poll( () => boton.evaluate( ( b ) => getComputedStyle( b ).backgroundColor ), { message: 'contorno, no botón relleno' } ).toMatch( /^(transparent|rgba\(\d+, \d+, \d+, 0\))$/ );
		const caja = await boton.evaluate( ( b ) => {
			const cs = getComputedStyle( b );
			return { alto: b.getBoundingClientRect().height, borde: cs.borderTopStyle + ' ' + cs.borderTopWidth };
		} );
		expect( caja.alto, 'QA-BIZ-DIFF-1: el botón nativo medía 22 px' ).toBeGreaterThanOrEqual( 44 );
		expect( caja.borde ).toBe( 'solid 1px' );
	} );

	test( 'en el teléfono, el enlace de la barra de anuncios se toca en todo su alto sin cambiar la cinta', async ( { page, isMobile } ) => {
		test.skip( ! isMobile, 'en escritorio el enlace mide 36 px en una barra de 48' );
		await page.goto( '/', { waitUntil: 'load' } );
		const activo = page.locator( '#akb-ann-bar .akb-ann-msg.akb-on' ).first();
		test.skip( 0 === ( await activo.count() ), 'la barra de anuncios está apagada' );

		// Si ningún mensaje lleva enlace (el dueño decide cuáles), se convierte el
		// activo en uno, sólo en el DOM de la prueba: lo que se mide es el CSS.
		const medida = await activo.evaluate( ( msg ) => {
			if ( ! msg.classList.contains( 'akb-ann-msg--link' ) ) {
				const interior = /** @type {HTMLElement} */ ( msg.querySelector( '.akb-ann-msg-inner' ) );
				const cinta    = msg.getBoundingClientRect();
				const enlace   = document.createElement( 'a' );
				enlace.className = 'akb-ann-msg-inner';
				enlace.href      = '#akb-e2e-anuncio';
				enlace.innerHTML = interior.innerHTML;
				msg.classList.add( 'akb-ann-msg--link' );
				interior.replaceWith( enlace );
				msg.setAttribute( 'data-cinta-sin-enlace', String( Math.round( cinta.height ) ) );
			}
			const barra = /** @type {HTMLElement} */ ( document.getElementById( 'akb-ann-bar' ) ).getBoundingClientRect();
			const a     = /** @type {HTMLElement} */ ( msg.querySelector( 'a.akb-ann-msg-inner' ) );
			const m     = msg.getBoundingClientRect();
			const x     = Math.round( m.left + m.width / 2 );
			const golpe = ( y ) => {
				const el = document.elementFromPoint( x, Math.round( y ) );
				return !! ( el && el.closest( 'a.akb-ann-msg-inner' ) === a );
			};
			return {
				barra: Math.round( barra.height ),
				cinta: Math.round( m.height ),
				arriba: golpe( barra.top + 3 ),
				abajo: golpe( barra.bottom - 3 ),
			};
		} );
		expect( medida.arriba, 'QA2-PANEL-6: un toque 3 px bajo el borde superior de la barra lleva al enlace' ).toBe( true );
		expect( medida.abajo, 'y 3 px sobre el borde inferior' ).toBe( true );
		expect( medida.cinta, 'la cinta dibujada no crece hasta la barra' ).toBeLessThan( medida.barra );
	} );

	test( 'en el teléfono, un anuncio de tres líneas o más no deja ver nada de la tercera bajo la segunda (ni sus tildes; de un emoji, sólo en el subrayado), con enlace o sin él, con el foco, en WebKit, y la cinta mide 37,2 px', async ( { page, isMobile, baseURL } ) => {
		test.skip( ! isMobile, 'en escritorio el mensaje va en una línea con «…»' );
		test.setTimeout( 240_000 );
		expect( existsSync( WEBKIT ), `WebKit en ${ WEBKIT } (o la ruta en AKB_WEBKIT)` ).toBe( true );
		const navegador = await webkit.launch( { executablePath: WEBKIT } );
		try {
			// El iPhone: WebKit con densidad 2. Ahí asomaba la tercera línea del
			// texto tal cual (4 px a 390) aunque en Chromium con densidad 1 no.
			const { use } = test.info().project;
			const contexto = await navegador.newContext( {
				baseURL,
				viewport: use.viewport,
				hasTouch: true,
				isMobile: true,
				deviceScaleFactor: 2,
				userAgent: use.userAgent,
				storageState: use.storageState,
			} );
			const paginas = { chromium: page, webkit: await contexto.newPage() };
			for ( const [ motor, p ] of Object.entries( paginas ) ) {
				for ( const enlace of [ true, false ] ) {
					for ( const tercera of [ null, TERCERA_CON_TILDES, TERCERA_CON_EMOJI ] ) {
						const cual = `${ motor }, ${ enlace ? 'enlace' : 'sin enlace' }, ${ { [ TERCERA_CON_TILDES ]: 'tercera línea con tildes', [ TERCERA_CON_EMOJI ]: 'tercera línea con emoji' }[ tercera ] || 'el texto de la prueba' }`;
						test.skip( ! ( await anuncioEnlace( p, ANUNCIO_LARGO, enlace ) ), 'la barra de anuncios está apagada' );
						const reposo = await geometriaDelAnuncio( p );
						expect( reposo.desborda, 'el texto de la prueba tiene que pasar de dos líneas para medir algo' ).toBe( true );
						cintaDeDosLineas( reposo, cual );
						await p.waitForTimeout( 100 );
						// Con 15 px de relleno abajo la tercera se veía casi entera (REV-QFB-1,
						// 93 px claros a 390); con 4, su tope (REV2-QFB-1, 83); con 1 y la
						// línea de 1.2, sus tildes (REV-FN1-1: 33 px en Chromium, 45 en WebKit).
						// Un emoji sube más que una tilde y deja un puntito en el subrayado de
						// la segunda (REV-FN2-2, límite conocido): se vigila que no pase de ahí.
						const asoma = await loQueAsomaDeLaTercera( p, tercera );
						vigilar( asoma, tercera, cual );
						if ( ! enlace ) {
							continue;
						}

						// Con el teclado (REV-FN-1). El foco devuelve a la cinta su caja de
						// siempre; si lo que le falta abajo vuelve a ser relleno, el recorte
						// baja con él y la tercera línea asoma otra vez: 328 px claros a 390.
						await p.evaluate( () => {
							const barra = /** @type {HTMLElement} */ ( document.getElementById( 'akb-ann-bar' ) );
							barra.tabIndex = -1;
							barra.focus();
						} );
						await p.keyboard.press( 'webkit' === motor ? 'Alt+Tab' : 'Tab' );
						const foco = await geometriaDelAnuncio( p );
						expect( foco.foco, `${ cual }: el enlace tiene el foco del teclado (:focus-visible)` ).toBe( true );
						cintaDeDosLineas( foco, `${ cual }, con el foco`, reposo );
						vigilar( await loQueAsomaDeLaTercera( p, tercera ), tercera, `${ cual }, con el foco` );
					}
				}
			}
		} finally {
			await navegador.close();
		}
	} );

	test( 'en el teléfono con colores forzados, el borde que agranda el enlace no se pinta', async ( { page, isMobile, browserName } ) => {
		test.skip( ! isMobile, 'el borde que agranda el enlace es del teléfono' );
		// Alto contraste de Windows (también con zoom a ≤768 px): el navegador
		// pinta los bordes transparentes con el color del enlace. Sin arreglo,
		// el de 12 + 15 px que agranda el área táctil salía como dos barras
		// sobre y bajo las letras, y el de 3 px del foco (REV-FN-1) como una
		// raya bajo la segunda línea.
		await page.emulateMedia( { forcedColors: 'active' } );
		test.skip( ! ( await anuncioEnlace( page, 'Envío gratis a todo Chile' ) ), 'la barra de anuncios está apagada' );
		await page.waitForTimeout( 100 );
		const reposo = await geometriaDelAnuncio( page );
		const enReposo = await filasDeLaBarra( page, reposo, reposo.enlace );
		expect(
			sumar( enReposo, ( f ) => f.y < reposo.techo - 4.5 || f.y > reposo.fondo + 1.5, 'distintos' ),
			'colores forzados: sobre y bajo las letras sólo el fondo, sin las barras del borde'
		).toBe( 0 );

		await page.evaluate( () => {
			const barra = /** @type {HTMLElement} */ ( document.getElementById( 'akb-ann-bar' ) );
			barra.tabIndex = -1;
			barra.focus();
		} );
		await page.keyboard.press( 'webkit' === browserName ? 'Alt+Tab' : 'Tab' );
		const foco = await geometriaDelAnuncio( page );
		expect( foco.foco, 'el enlace tiene el foco del teclado (:focus-visible)' ).toBe( true );
		const conFoco = await filasDeLaBarra( page, foco, foco.enlace );
		expect(
			sumar( conFoco, ( f ) => f.y >= foco.fondo + 1.5 && f.y <= foco.fondo + 3.5, 'distintos' ),
			'colores forzados con foco: entre las letras y el anillo, sólo el fondo'
		).toBe( 0 );
	} );

	test( 'en el teléfono, tocar el enlace de la barra deja entero su subrayado de 2 px, también en WebKit', async ( { page, isMobile, baseURL } ) => {
		test.skip( ! isMobile, 'el recorte a 1 px de las letras es del teléfono' );
		expect( existsSync( WEBKIT ), `WebKit en ${ WEBKIT } (o la ruta en AKB_WEBKIT)` ).toBe( true );
		const navegador = await webkit.launch( { executablePath: WEBKIT } );
		try {
			const { use } = test.info().project;
			const contexto = await navegador.newContext( {
				baseURL,
				viewport: use.viewport,
				hasTouch: true,
				isMobile: true,
				userAgent: use.userAgent,
				storageState: use.storageState,
			} );
			const paginas = { chromium: page, webkit: await contexto.newPage() };
			for ( const [ motor, p ] of Object.entries( paginas ) ) {
				for ( const texto of [ 'Envío gratis a todo Chile', ANUNCIO_LARGO ] ) {
					test.skip( ! ( await anuncioEnlace( p, texto ) ), 'la barra de anuncios está apagada' );
					// Tras un toque el enlace queda :hover (en iOS, hasta el siguiente):
					// amarillo y con el subrayado de 2 px. Cuántas filas de él se ven
					// en su última línea: WebKit recortaba la segunda (REV-FN-2).
					await p.locator( '#akb-ann-bar a.akb-ann-msg-inner' ).tap();
					await p.waitForTimeout( 100 );
					const caja = await geometriaDelAnuncio( p );
					expect( [ caja.encima, caja.grosor ], `${ motor }: el toque deja el enlace resaltado` ).toEqual( [ true, '2px' ] );
					const filas = await filasDeLaBarra( p, caja, caja.enlace );
					expect(
						filas.filter( ( f ) => f.y >= caja.fondo - 4 && f.y <= caja.fondo + 3 && f.amarillo >= 30 ).length,
						`REV-FN-2, ${ motor }, «${ texto.slice( 0, 25 ) }…»: filas del subrayado resaltado (WebKit dejaba 1 de 2)`
					).toBe( 2 );
				}
			}
		} finally {
			await navegador.close();
		}
	} );

	test( 'el motivo de «ya tienes todo en el carrito» es el texto del dueño, con el singular bien puesto', async ( { page, context, request } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		const r = await request.get( '/wp-json/wc/store/v1/products?per_page=100&stock_status=instock' );
		test.skip( ! r.ok(), 'sin Store API' );
		const lista = await r.json();
		const tomo = lista.find( ( x ) => 'simple' === x.type && x.is_purchasable && x.add_to_cart && 1 === x.add_to_cart.maximum && 1 === x.low_stock_remaining );
		test.skip( ! tomo, 'no hay un tomo simple con una sola unidad en la Store API' );

		await page.goto( new URL( tomo.permalink ).pathname, { waitUntil: 'load' } );
		const real = page.locator( 'form.cart .single_add_to_cart_button' );
		await real.scrollIntoViewIfNeeded();
		await real.click();
		const cajon = page.locator( '#akb-native-cart-drawer.akb-open' );
		await expect( cajon ).toHaveCount( 1 );
		await page.locator( '#akb-native-cart-drawer .akb-cart-close' ).click();
		await expect( cajon ).toHaveCount( 0 );
		await page.waitForTimeout( 400 );

		const motivo = page.waitForResponse( ( x ) => x.url().includes( 'akb_get_cart' ) && x.url().includes( 'drain_notices=1' ) );
		await real.click();
		const datos = ( await ( await motivo ).json() ).data;
		expect( datos.motivo ).toBe( 'ya_en_carrito' );
		expect( datos.errors[ 0 ], 'QAM-6: el texto del dueño, no «tenemos 1 existencias»' ).toBe( YA_EN_CARRITO );
		await expect( page.locator( '.akb-atc-motivo:visible, .akb-sticky-atc__motivo:visible' ).first() ).toHaveText( YA_EN_CARRITO );
	} );
} );

test.describe( 'panel en el teléfono (negocio)', () => {
	test.describe.configure( { mode: 'serial' } );
	test.skip( soloLectura, 'necesita una sesión de administrador: sólo el espejo' );

	/** @type {{uid:number,token:string,sin_escenario?:boolean,error?:string,cookies:Array<{name:string,value:string}>}} */
	let sesion;

	test.beforeAll( ( {}, info ) => {
		test.skip( 'movil' !== info.project.name, 'se mide a 390 px: una sola sesión, en el proyecto móvil' );
		// El administrador de pruebas de ID MÁS ALTO: `rewards-titulo-de-resenas`
		// usa el más bajo, y dos `WP_Session_Tokens::create()` a la vez sobre el
		// mismo usuario se pisan el meta `session_tokens`. Sólo escribe el token.
		const salida = wpEval( `<?php
$akb_uid = 0;
foreach ( get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'DESC' ) ) as $akb_admin ) {
	if ( preg_match( '/^(qa|test)/i', (string) $akb_admin->user_login ) ) {
		$akb_uid = (int) $akb_admin->ID;
		break;
	}
}
if ( 0 === $akb_uid ) {
	echo wp_json_encode( array( 'sin_escenario' => true, 'error' => 'El espejo no tiene un administrador de pruebas (qa* / test*).' ) );
	return;
}
$akb_exp   = time() + HOUR_IN_SECONDS;
$akb_token = WP_Session_Tokens::get_instance( $akb_uid )->create( $akb_exp );
echo wp_json_encode( array(
	'uid'     => $akb_uid,
	'token'   => $akb_token,
	'cookies' => array(
		array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $akb_uid, $akb_exp, 'auth', $akb_token ) ),
		array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $akb_uid, $akb_exp, 'logged_in', $akb_token ) ),
	),
) );
` );
		const linea = salida.split( '\n' ).find( ( l ) => l.startsWith( '{' ) );
		expect( linea, `wp eval-file no devolvió JSON: «${ salida.trim().slice( 0, 300 ) }»` ).toBeTruthy();
		sesion = JSON.parse( String( linea ) );
		test.skip( true === sesion.sin_escenario, String( sesion.error ) );
		expect( sesion.token, sesion.error ).toBeTruthy();
	} );

	test.afterAll( () => {
		// Lo sembrado se retira: el token de sesión, y nada más se escribió.
		if ( sesion?.token ) {
			wpEval( `<?php WP_Session_Tokens::get_instance( ${ Number( sesion.uid ) } )->destroy( ${ JSON.stringify( sesion.token ) } ); echo "ok\\n";` );
		}
	} );

	test( 'la pestaña Textos cabe en un teléfono de 390: el correo de Contacto no la ensancha', async ( { context, page, baseURL } ) => {
		const dominio = new URL( String( baseURL ) ).hostname;
		await context.addCookies( sesion.cookies.map( ( c ) => ( { ...c, domain: dominio, path: '/' } ) ) );

		const respuesta = await page.goto( '/wp-admin/admin.php?page=akibara-panel&tab=copys', { waitUntil: 'load' } );
		expect( respuesta?.status() ).toBe( 200 );
		expect( page.url(), 'la sesión sembrada no entró: acabó en el login' ).not.toContain( 'wp-login.php' );
		const correo = page.locator( '.akb-panel .form-table input[type="email"]' ).first();
		await expect( correo, 'la pestaña Textos lleva el correo que recibe los mensajes de Contacto' ).toHaveCount( 1 );

		// El ancho de la pantalla es el del viewport de la prueba: con `isMobile`,
		// `innerWidth` crece con lo que desborda (425 sin el tope) y no sirve.
		const medida = await page.evaluate( () => ( {
			vista: document.documentElement.clientWidth,
			pagina: document.documentElement.scrollWidth,
			correo: Math.round( /** @type {HTMLElement} */ ( document.querySelector( '.akb-panel .form-table input[type="email"]' ) ).getBoundingClientRect().right ),
		} ) );
		expect( medida.vista ).toBe( page.viewportSize()?.width );
		expect( medida.pagina, 'QA2-PANEL-7: sin el tope, la pestaña medía 425 px en un teléfono de 390' ).toBeLessThanOrEqual( medida.vista );
		expect( medida.correo, 'el campo termina dentro de la pantalla' ).toBeLessThanOrEqual( medida.vista );
	} );
} );
