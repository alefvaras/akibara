/**
 * sonda-superficies-red-lenta.mjs — CASO 4 del gate de SUPERFICIES Y CACHÉ.
 *
 * Carga portada, catálogo y ficha con la red estrangulada a Slow 3G por CDP
 * (`Network.emulateNetworkConditions`: 400 kbps, 400 ms de latencia) y mide:
 *   4.1 CERO excepciones JS y cero errores de consola ajenos.
 *   4.2 Contrato anti-CLS de las imágenes: `width`+`height` o aspect-ratio en
 *       todas; ninguna imagen VISIBLE del primer pliegue marcada `lazy`; las
 *       portadas de producto bajo el pliegue sí marcadas.
 *   4.3 CLS acumulado por PerformanceObserver < 0,1.
 *   4.4 La página SE ASIENTA: bajo throttling las portadas siguen llegando
 *       después del evento `load`, así que se sondea la firma de layout hasta
 *       que se repita — y sólo se declara fallo si sigue moviéndose al agotar
 *       el presupuesto. (Comparar una sola vez a los 2 s daba rojo por una
 *       imagen que aún viajaba: medido el 2026-09-01.)
 *   4.5 La jaula de la imagen rota: un `<img>` de portada cuyo `src` da 404 no
 *       puede inflar su tarjeta. Sin la clase `akb-cover--error` que pone
 *       `quick-view.js`, la regla del core
 *       `img[sizes^="auto,"]{contain-intrinsic-size:3000px 1500px}` estira la
 *       caja a 1500 px.
 *
 * NOTA DE ENTORNO (no es defecto del sitio): el espejo local no tiene todos los
 * derivados de `/wp-content/uploads/`. Se comprobó por HTTP contra producción
 * que los que aquí faltan SÍ existen allí (`frieren-1-…-450x675.avif` y
 * `atelier-…-206x300.avif` dan 200 en akibara.cl). Por eso los 404 bajo
 * `uploads/` se informan aparte y no tumban el check de recursos; cualquier 404
 * FUERA de `uploads/` sí lo tumba. Y ya que el espejo regala portadas rotas,
 * se usan como caso REAL para ejercitar la jaula de 4.5.
 *
 * La pestaña va en primer plano (Playwright no la esconde): un panel oculto
 * congela rAF y las animaciones parecerían muertas.
 *
 * Uso: node sonda-superficies-red-lenta.mjs   (exit != 0 si algo falla)
 */

import { BASE, MOVIL, abrir, crearMarcador } from './lib-bordes.mjs';

const m = crearMarcador( 'superficies-red-lenta' );

// Slow 3G de DevTools: 400 kbps simétricos, 400 ms de RTT.
const SLOW_3G = {
	offline: false,
	latency: 400,
	downloadThroughput: ( 400 * 1024 ) / 8,
	uploadThroughput: ( 400 * 1024 ) / 8,
};

const PAGINAS = [
	{ ruta: '/', nombre: 'portada' },
	{ ruta: '/tienda/', nombre: 'catalogo' },
	{ ruta: '/product/frieren-1-ivrea-argentina/', nombre: 'ficha' },
];

let navegador = null;

try {
	const sesion = await abrir( MOVIL );
	navegador = sesion.navegador;
	const { contexto, pagina } = sesion;

	// Observador de CLS antes de que pinte nada, en TODAS las navegaciones.
	await pagina.addInitScript( () => {
		window.__cls = 0;
		try {
			new PerformanceObserver( ( lista ) => {
				for ( const e of lista.getEntries() ) {
					if ( ! e.hadRecentInput ) {
						window.__cls += e.value;
					}
				}
			} ).observe( { type: 'layout-shift', buffered: true } );
		} catch ( e ) {
			window.__cls = -1;
		}
	} );

	const cdp = await contexto.newCDPSession( pagina );
	await cdp.send( 'Network.enable' );
	await cdp.send( 'Network.emulateNetworkConditions', SLOW_3G );
	m.info( 'throttling', `Network.emulateNetworkConditions ${ SLOW_3G.downloadThroughput } B/s (400 kbps), latencia ${ SLOW_3G.latency } ms` );

	for ( const { ruta, nombre } of PAGINAS ) {
		const excepciones = [];
		const consola = [];
		const fallos = [];

		const onError = ( e ) => excepciones.push( e.message.split( '\n' )[ 0 ] );
		const onConsole = ( msg ) => {
			if ( msg.type() === 'error' ) {
				consola.push( msg.text() );
			}
		};
		const onResponse = ( r ) => {
			if ( r.status() >= 400 ) {
				fallos.push( { status: r.status(), url: new URL( r.url() ).pathname, tipo: r.request().resourceType() } );
			}
		};
		pagina.on( 'pageerror', onError );
		pagina.on( 'console', onConsole );
		pagina.on( 'response', onResponse );

		const t0 = Date.now();
		await pagina.goto( BASE + ruta, { waitUntil: 'load', timeout: 180000 } );
		const carga = Date.now() - t0;

		/* --------------------------------------------------------------
		 * 4.4 Asentamiento con presupuesto: se sondea la firma hasta que
		 *     dos lecturas consecutivas coincidan.
		 * ----------------------------------------------------------- */
		const firma = () =>
			pagina.evaluate( () => {
				const nodos = [ ...document.querySelectorAll( 'header, main, footer, ul.products li.product' ) ].slice( 0, 30 );
				return {
					estado: document.readyState,
					cls: Math.round( ( window.__cls || 0 ) * 1000 ) / 1000,
					pendientes: [ ...document.querySelectorAll( 'img' ) ].filter( ( i ) => ! i.complete ).length,
					layout: nodos.map( ( n ) => {
						const r = n.getBoundingClientRect();
						return `${ Math.round( r.top ) }:${ Math.round( r.height ) }`;
					} ).join( ',' ),
				};
			} );

		let previa = await firma();
		let estable = false;
		let vueltas = 0;
		const MAX_VUELTAS = 12; // 12 x 2 s = 24 s de presupuesto tras `load`.
		while ( vueltas < MAX_VUELTAS ) {
			await pagina.waitForTimeout( 2000 );
			vueltas++;
			const ahora = await firma();
			// El criterio es la FIRMA DE LAYOUT, no «cero imágenes en vuelo»:
			// las `loading=lazy` de más abajo nunca se descargan mientras no se
			// haga scroll, así que `complete` sigue en false para siempre y la
			// espera agotaba el presupuesto en las tres páginas con el layout ya
			// quieto (medido el 2026-09-01). Las pendientes quedan como dato.
			if ( ahora.layout === previa.layout ) {
				estable = true;
				previa = ahora;
				break;
			}
			previa = ahora;
		}

		m.check(
			`${ nombre }-slow3g-sin-excepciones-js`,
			excepciones.length === 0,
			`pageerror=${ excepciones.length }${ excepciones.length ? ' :: ' + excepciones.slice( 0, 4 ).join( ' || ' ) : '' } · load en ${ carga } ms`
		);

		const fallosUploads = fallos.filter( ( f ) => f.url.startsWith( '/wp-content/uploads/' ) );
		const fallosReales = fallos.filter( ( f ) => ! f.url.startsWith( '/wp-content/uploads/' ) );
		m.check(
			`${ nombre }-slow3g-sin-recursos-rotos-fuera-de-uploads`,
			fallosReales.length === 0,
			`fuera de uploads: ${ fallosReales.length }${ fallosReales.length ? ' :: ' + fallosReales.slice( 0, 5 ).map( ( f ) => `${ f.status } ${ f.tipo } ${ f.url }` ).join( ' || ' ) : '' } · derivados de uploads que faltan SOLO en el espejo local: ${ fallosUploads.length }`
		);

		const consolaAjena = consola.filter( ( t ) => ! /Failed to load resource/.test( t ) );
		m.check(
			`${ nombre }-slow3g-consola-limpia`,
			consolaAjena.length === 0,
			`errores de consola no atribuibles a los 404 de uploads: ${ consolaAjena.length }${ consolaAjena.length ? ' :: ' + consolaAjena.slice( 0, 4 ).join( ' || ' ) : '' } (console.error total=${ consola.length })`
		);

		/* --------------------------------------------------------------
		 * 4.2 Contrato de las imágenes. Sólo cuentan las VISIBLES: el
		 *     drawer y los carruseles guardan copias con alto 0 que no
		 *     están «en el primer pliegue» aunque su rect diga top=0.
		 * ----------------------------------------------------------- */
		const imgs = await pagina.evaluate( () => {
			const H = window.innerHeight;
			const info = [ ...document.querySelectorAll( 'img' ) ].map( ( i ) => {
				const r = i.getBoundingClientRect();
				const cs = getComputedStyle( i );
				return {
					src: ( i.currentSrc || i.src || '' ).split( '/' ).pop(),
					lazy: i.getAttribute( 'loading' ) === 'lazy',
					dimension: !! ( i.getAttribute( 'width' ) && i.getAttribute( 'height' ) ) || cs.aspectRatio !== 'auto',
					top: Math.round( r.top + window.scrollY ),
					alto: Math.round( r.height ),
					visible: r.height > 0 && r.width > 0,
					portada: !! i.closest( '.akb-product-cover-wrap' ),
					rota: i.complete && i.naturalWidth === 0,
					display: cs.display,
					contain: cs.contain,
					cis: cs.containIntrinsicSize,
					errorClass: !! i.closest( '.akb-cover--error' ),
				};
			} );
			return {
				viewport: H,
				total: info.length,
				visibles: info.filter( ( i ) => i.visible ).length,
				sinDimension: info.filter( ( i ) => ! i.dimension ).map( ( i ) => i.src ),
				primerPliegueLazy: info.filter( ( i ) => i.visible && i.top < H && i.lazy ).map( ( i ) => i.src ),
				portadasAbajoSinLazy: info.filter( ( i ) => i.visible && i.portada && i.top > H * 1.5 && ! i.lazy ).map( ( i ) => i.src ),
				noPortadasAbajoSinLazy: info.filter( ( i ) => i.visible && ! i.portada && i.top > H * 1.5 && ! i.lazy ).map( ( i ) => i.src ),
				rotas: info.filter( ( i ) => i.rota && i.visible ),
			};
		} );

		m.check(
			`${ nombre }-imagenes-con-dimension-declarada`,
			imgs.sinDimension.length === 0 && imgs.total > 0,
			`${ imgs.total } imágenes (${ imgs.visibles } visibles), ${ imgs.sinDimension.length } sin width/height ni aspect-ratio${ imgs.sinDimension.length ? ' :: ' + imgs.sinDimension.slice( 0, 5 ).join( ', ' ) : '' }`
		);

		m.check(
			`${ nombre }-nada-lazy-en-el-primer-pliegue`,
			imgs.primerPliegueLazy.length === 0,
			`viewport=${ imgs.viewport }px · imágenes visibles del primer pliegue con loading=lazy: ${ imgs.primerPliegueLazy.length }${ imgs.primerPliegueLazy.length ? ' :: ' + imgs.primerPliegueLazy.slice( 0, 5 ).join( ', ' ) : '' }`
		);

		m.check(
			`${ nombre }-portadas-bajo-el-pliegue-con-lazy`,
			imgs.portadasAbajoSinLazy.length === 0,
			`portadas de producto a más de 1,5 pantallas sin loading=lazy: ${ imgs.portadasAbajoSinLazy.length }${ imgs.portadasAbajoSinLazy.length ? ' :: ' + imgs.portadasAbajoSinLazy.slice( 0, 5 ).join( ', ' ) : '' }`
		);

		m.info(
			`${ nombre }-otras-imagenes-bajo-el-pliegue-sin-lazy`,
			imgs.noPortadasAbajoSinLazy.length
				? `${ imgs.noPortadasAbajoSinLazy.length } (decorativas: ${ [ ...new Set( imgs.noPortadasAbajoSinLazy ) ].slice( 0, 6 ).join( ', ' ) })`
				: 'ninguna'
		);

		/* --------------------------------------------------------------
		 * 4.5 La jaula de la portada rota.
		 * ----------------------------------------------------------- */
		const portadasRotas = imgs.rotas.filter( ( i ) => i.portada );
		if ( portadasRotas.length ) {
			const infladas = portadasRotas.filter( ( i ) => i.alto > 800 );
			m.check(
				`${ nombre }-portada-rota-no-infla-la-tarjeta`,
				infladas.length === 0,
				`${ portadasRotas.length } portadas rotas (404 del espejo). Altos renderizados: ${ portadasRotas.slice( 0, 5 ).map( ( i ) => `${ i.src }=${ i.alto }px [contain=${ i.contain }, contain-intrinsic-size=${ i.cis }, akb-cover--error=${ i.errorClass }]` ).join( ' | ' ) } · umbral 800px`
			);
		} else {
			m.info( `${ nombre }-sin-portadas-rotas`, 'ninguna portada dio 404 en esta carga: la jaula no se pudo ejercitar aquí' );
		}

		/* --------------------------------------------------------------
		 * 4.3 CLS y 4.4 asentamiento.
		 * ----------------------------------------------------------- */
		m.check(
			`${ nombre }-cls-por-debajo-de-0.1`,
			previa.cls >= 0 && previa.cls < 0.1,
			`CLS acumulado = ${ previa.cls } (umbral 0.1)`
		);

		m.check(
			`${ nombre }-la-pagina-se-asienta`,
			estable && previa.estado === 'complete',
			`readyState=${ previa.estado } · layout ${ estable ? 'ESTABLE' : 'AÚN MOVIÉNDOSE' } tras ${ vueltas * 2 } s de sondeo (presupuesto ${ MAX_VUELTAS * 2 } s) · ${ previa.layout.split( ',' ).length } bloques medidos · imágenes sin descargar (lazy fuera de pantalla)=${ previa.pendientes }`
		);

		pagina.off( 'pageerror', onError );
		pagina.off( 'console', onConsole );
		pagina.off( 'response', onResponse );
	}
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, `EXCEPCIÓN: ${ error.message }` );
} finally {
	if ( navegador ) {
		await navegador.close();
	}
}

process.exit( m.cerrar() );
