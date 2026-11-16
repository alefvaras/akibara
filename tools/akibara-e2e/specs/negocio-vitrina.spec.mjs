// @ts-check
/**
 * La vitrina abre con lo que se despacha ya.
 *
 * Decisión del dueño (2026-09-22): «mostrar los disponibles primero». Medido ese
 * día en producción, `/tienda/` abría con 6 de 6 tomos «a pedido» (llegada en 5-7
 * semanas) y 8 de 24 en la página 1, con 1.096 de 1.643 productos en bodega. La
 * regla vive en business (CatalogOrder/Interleaver, pestaña «Vitrina» del panel)
 * y se aplica al recalcular `menu_order`; aquí se mira lo SERVIDO y, en el
 * espejo, también lo escrito en la base.
 *
 * Tolerancia: el recálculo corre de madrugada (y al publicar), no en cada venta.
 * Un tomo que se agota o pasa a «a pedido» durante el día queda en su sitio hasta
 * la corrida siguiente. Eso mueve UNA tarjeta, no el orden entero: por eso se
 * cuentan las tarjetas «fuera de su grupo» y no la primera que rompe la regla.
 *
 * Por qué el listado ENTERO y una prueba contra la base (revisión B1-R3,
 * 2026-09-22): con la página 1 y tolerancia 2, `/manga/` y `/comics/` pasaban
 * TAMBIÉN con la vitrina intercalada, así que la prueba no distinguía las dos
 * posiciones del interruptor. Medido en el espejo con el interruptor apagado,
 * ni siquiera dos páginas bastan: la 2 de `/manga/` sale entera disponible. El
 * listado completo sí separa (`/tienda/` 569 de 1.625 tarjetas fuera de su
 * grupo, `/manga/` 566 de 1.596, contra 0 con «disponibles primero»), y la
 * comparación con el plan de business mira los 1.626 productos a la vez (841
 * fuera de sitio contra 0). `/comics/` no se puede separar mirando tarjetas: en
 * el espejo tiene 24 disponibles, 0 «a pedido» y 2 agotados, y las dos
 * vitrinas difieren en UNA tarjeta — ahí manda la prueba contra la base.
 *
 * Las landings de serie NO siguen la vitrina: abren en «Tomo 1 → N», y su
 * «Novedades primero» es lo más nuevo arriba con lo agotado al final (B1-R1).
 */

import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';

const ejecutar = promisify( execFile );
const RAIZ = new URL( '../../../', import.meta.url ).pathname;

/** Tarjetas que pueden estar fuera de su grupo por ventas del día. */
const TOLERANCIA = 2;

/** Páginas renderizadas que se miran: lo primero que ve el cliente. */
const PAGINAS = 2;

/**
 * En el listado entero, fracción de tarjetas que puede estar fuera de su grupo
 * por las ventas del día (con un mínimo de TOLERANCIA). La vitrina intercalada
 * deja fuera de su grupo un 35 % (medido en el espejo, 2026-09-22).
 */
const TOLERANCIA_LISTADO = 0.02;

/**
 * Productos fuera de sitio que admite la base respecto del plan de la vitrina:
 * lo que mueven las ventas desde la última corrida (un tomo que cambia de grupo
 * puede arrastrar a su serie dentro del grupo). La vitrina contraria da cientos.
 */
const TOLERANCIA_PLAN = 25;

/** Por debajo de esto las dos vitrinas casi coinciden y la prueba no distingue nada. */
const DISTANCIA_MINIMA_ENTRE_VITRINAS = 100;

/** Grupo de cada tarjeta en el orden de la vitrina: 0 disponible, 1 a pedido, 2 agotado. */
async function grupos( page ) {
	return page.$$eval( 'ul.products > li.product', ( lis ) => lis.map( ( li ) => {
		if ( li.classList.contains( 'outofstock' ) ) {
			return 2;
		}
		return li.classList.contains( 'onbackorder' ) ? 1 : 0;
	} ) );
}

/**
 * Largo menos la subsecuencia creciente más larga: cuántos elementos habría que
 * quitar para que la secuencia quede en orden. `estricta` para posiciones (que
 * no se repiten); no estricta para grupos (que sí).
 *
 * @param {number[]} secuencia Valores en orden de pantalla.
 * @param {boolean} [estricta] Exigir estrictamente creciente.
 */
function fueraDeOrden( secuencia, estricta = false ) {
	const colas = [];
	for ( const g of secuencia ) {
		// Paciencia con búsqueda binaria.
		let lo = 0;
		let hi = colas.length;
		while ( lo < hi ) {
			const mid = ( lo + hi ) >> 1;
			if ( estricta ? colas[ mid ] < g : colas[ mid ] <= g ) {
				lo = mid + 1;
			} else {
				hi = mid;
			}
		}
		colas[ lo ] = g;
	}
	return secuencia.length - colas.length;
}

/**
 * Tarjetas de un listado con todas sus páginas, leídas del HTML servido.
 *
 * @param {import('@playwright/test').APIRequestContext} request Cliente HTTP.
 * @param {string} ruta Ruta con barra final y, si hace falta, query.
 * @returns {Promise<{id:number, grupo:number}[]>} Grupo: 0 disponible, 1 a pedido, 2 agotado.
 */
async function tarjetasDeTodasLasPaginas( request, ruta ) {
	const [ camino, query = '' ] = ruta.split( '?' );
	const tarjetas = [];
	for ( let pagina = 1; pagina < 120; pagina++ ) {
		const url = ( 1 === pagina ? camino : `${ camino }page/${ pagina }/` ) + ( query ? `?${ query }` : '' );
		const respuesta = await request.get( url );
		expect( respuesta.status(), url ).toBe( 200 );
		const html = await respuesta.text();
		for ( const [ , clases ] of html.matchAll( /<li class="([^"]*\btype-product\b[^"]*)"/g ) ) {
			const grupo = /\boutofstock\b/.test( clases ) ? 2 : ( /\bonbackorder\b/.test( clases ) ? 1 : 0 );
			tarjetas.push( { id: Number( /\bpost-(\d+)\b/.exec( clases )?.[ 1 ] ), grupo } );
		}
		if ( ! html.includes( 'class="next page-numbers"' ) ) {
			break;
		}
	}
	return tarjetas;
}

test.describe( 'negocio · vitrina', () => {

	for ( const ruta of [ '/tienda/', '/manga/', '/comics/' ] ) {
		test( `${ ruta } abre con lo disponible, después lo «a pedido» y al final lo agotado`, { tag: '@prod' }, async ( { page } ) => {
			const secuencia = [];
			for ( let pagina = 1; pagina <= PAGINAS; pagina++ ) {
				const respuesta = await page.goto( 1 === pagina ? ruta : `${ ruta }page/${ pagina }/`, { waitUntil: 'domcontentloaded' } );
				expect( respuesta?.status() ).toBe( 200 );
				secuencia.push( ...await grupos( page ) );
				if ( ! await page.locator( 'a.next.page-numbers' ).count() ) {
					break;
				}
			}
			expect( secuencia.length, 'un listado con 200 y pocas tarjetas no prueba nada' ).toBeGreaterThan( 3 );

			const texto = secuencia.map( ( g ) => 'ibo'[ g ] ).join( '' );
			expect(
				fueraDeOrden( secuencia ),
				`estados de las páginas 1 y 2 (i disponible, b a pedido, o agotado): ${ texto }`
			).toBeLessThanOrEqual( TOLERANCIA );
		} );
	}

	for ( const ruta of [ '/tienda/', '/manga/' ] ) {
		test( `${ ruta } entero, tal como se sirve, va por grupos de disponibilidad`, async ( { request }, testInfo ) => {
			test.skip( 'escritorio' !== testInfo.project.name, 'es el HTML servido: no depende del tamaño de pantalla' );
			// 68 páginas: ~12 s en el espejo.
			test.setTimeout( 180_000 );

			const secuencia = ( await tarjetasDeTodasLasPaginas( request, ruta ) ).map( ( t ) => t.grupo );
			expect( secuencia.length, 'un listado con pocas tarjetas no prueba nada' ).toBeGreaterThan( 100 );

			const fuera = fueraDeOrden( secuencia );
			const tope = Math.max( TOLERANCIA, Math.floor( secuencia.length * TOLERANCIA_LISTADO ) );
			const conteo = [ 0, 1, 2 ].map( ( g ) => secuencia.filter( ( x ) => x === g ).length );
			expect(
				fuera,
				`${ fuera } de ${ secuencia.length } tarjetas fuera de su grupo (disponibles ${ conteo[ 0 ] }, a pedido ${ conteo[ 1 ] }, agotados ${ conteo[ 2 ] })`
			).toBeLessThanOrEqual( tope );
		} );
	}

	test( 'la base tiene escrita la vitrina «disponibles primero», no la intercalada', async ( {}, testInfo ) => {
		test.skip( soloLectura || ! /localhost/.test( process.env.AKB_BASE_URL || 'http://localhost:8080' ), 'lee la base por Docker: sólo en el espejo' );
		test.skip( 'escritorio' !== testInfo.project.name, 'no depende del tamaño de pantalla' );

		// `plan()` es la misma función con la que el recálculo decide qué escribir,
		// y sólo lee. Se compara el ORDEN, no el número: un producto nuevo con
		// menu_order 0 o una venta del día mueven una tarjeta, no el resto.
		const php = 'use Akibara\\Business\\Modules\\CatalogOrder\\CatalogOrder;'
			+ '$m = new CatalogOrder();'
			+ '$d = $m->plan( "disponibles_primero" );'
			+ 'echo "\\n", wp_json_encode( array( "vitrina" => CatalogOrder::current_showcase(), "actual" => $d["current"], "disponibles_primero" => $d["orders"], "intercalado" => $m->plan( "intercalado" )["orders"] ) );';
		const { stdout } = await ejecutar( 'docker', [ 'compose', 'exec', '-T', 'cron', 'wp', 'eval', php ], { cwd: RAIZ, timeout: 120_000, maxBuffer: 16 * 1024 * 1024 } );
		const datos = JSON.parse( String( stdout.split( '\n' ).find( ( l ) => l.startsWith( '{' ) ) ) );

		const porPlan = ( plan ) => Object.keys( plan ).sort( ( a, b ) => plan[ a ] - plan[ b ] );
		const ids = Object.keys( datos.actual ).sort( ( a, b ) => datos.actual[ a ] - datos.actual[ b ] || Number( a ) - Number( b ) );
		expect( ids.length, 'un catálogo vacío no prueba nada' ).toBeGreaterThan( 100 );

		// Cuánto se parecen las dos vitrinas ENTRE SÍ: si casi coinciden, que la
		// base se parezca a una no dice nada sobre la otra.
		const entreVitrinas = fueraDeOrden( porPlan( datos.disponibles_primero ).map( ( id ) => datos.intercalado[ id ] ), true );
		expect( entreVitrinas, 'las dos vitrinas casi coinciden en este catálogo: esta prueba no distinguiría nada' ).toBeGreaterThanOrEqual( DISTANCIA_MINIMA_ENTRE_VITRINAS );

		const fuera = fueraDeOrden( ids.map( ( id ) => datos.disponibles_primero[ id ] ), true );
		expect(
			fuera,
			`vitrina elegida en el panel: ${ datos.vitrina } · ${ fuera } de ${ ids.length } productos fuera de sitio respecto de «disponibles primero» (las dos vitrinas distan ${ entreVitrinas })`
		).toBeLessThanOrEqual( TOLERANCIA_PLAN );
	} );

	test( 'desplegar business encola el recálculo: el gancho está puesto en el WordPress de verdad', async ( {}, testInfo ) => {
		test.skip( soloLectura || ! /localhost/.test( process.env.AKB_BASE_URL || 'http://localhost:8080' ), 'lee los hooks por Docker: sólo en el espejo' );
		test.skip( 'escritorio' !== testInfo.project.name, 'no depende del tamaño de pantalla' );

		// B1-REV2-1: «disponibles primero» llegó como valor por defecto y en
		// producción la fila de la opción no existe, así que desplegar no disparaba
		// ningún recálculo y la tienda seguía intercalada hasta las 00:05. La prueba
		// unitaria mira el módulo suelto; ésta, que el plugin arrancado de verdad lo
		// deja colgado del hook que `Plugin::maybe_upgrade()` dispara en cada
		// despliegue. Sólo lee: no simula un despliegue en el espejo compartido.
		const php = 'global $wp_filter; $v = array();'
			+ 'foreach ( isset( $wp_filter["akibara_business_upgraded"] ) ? $wp_filter["akibara_business_upgraded"]->callbacks : array() as $p => $cbs ) {'
			+ ' foreach ( $cbs as $cb ) { $f = $cb["function"]; if ( is_array( $f ) && is_object( $f[0] ) ) { $v[] = get_class( $f[0] ) . "::" . $f[1]; } } }'
			+ 'echo "\\n", wp_json_encode( $v );';
		const { stdout } = await ejecutar( 'docker', [ 'compose', 'exec', '-T', 'cron', 'wp', 'eval', php ], { cwd: RAIZ, timeout: 120_000 } );
		const ganchos = JSON.parse( String( stdout.split( '\n' ).find( ( l ) => l.startsWith( '[' ) ) ) );

		expect( ganchos, 'los listeners de akibara_business_upgraded' ).toContain( 'Akibara\\Business\\Modules\\CatalogOrder\\CatalogOrder::on_business_upgraded' );
	} );

	test( 'la landing de serie sigue abriendo en «Tomo 1 → N»', { tag: '@prod' }, async ( { page } ) => {
		const respuesta = await page.goto( '/serie/haikyuu/', { waitUntil: 'domcontentloaded' } );
		test.skip( 404 === respuesta?.status(), 'este entorno no tiene la serie de referencia' );
		expect( respuesta?.status() ).toBe( 200 );

		const tomos = ( await page.$$eval( 'ul.products > li.product .akb-vol', ( els ) => els.map( ( e ) => e.textContent || '' ) ) )
			.map( ( t ) => parseInt( t, 10 ) )
			.filter( ( n ) => Number.isFinite( n ) );

		expect( tomos.length ).toBeGreaterThan( 3 );
		expect( tomos, 'la vitrina «disponibles primero» no puede tocar el orden de lectura de la serie' ).toEqual( [ ...tomos ].sort( ( a, b ) => a - b ) );
	} );

	test( '«Novedades primero» en una landing de serie es lo más nuevo arriba y lo agotado al final', async ( { page, request } ) => {
		// Series que mezclan disponible, «a pedido» y agotado. Sin clavar una sola:
		// cada entorno tiene las suyas, y basta con que exista alguna.
		const candidatas = [ 'haikyuu', 'the-climber', 'maximum-berserk', 'el-verano-que-hikaru-murio', 'one-piece' ];
		let medidas = 0;

		for ( const slug of candidatas ) {
			const respuesta = await page.goto( `/serie/${ slug }/?orderby=menu_order`, { waitUntil: 'domcontentloaded' } );
			if ( 404 === respuesta?.status() ) {
				continue;
			}
			expect( respuesta?.status() ).toBe( 200 );
			await expect( page.locator( 'select.orderby option:checked' ).first(), 'el selector anuncia lo que el cliente eligió' ).toHaveText( 'Novedades primero' );

			// «Más recientes» es fecha DESC a secas: quitándole los agotados y
			// poniéndolos al final queda exactamente lo que «Novedades primero»
			// promete. Lo que no puede pasar es lo de la vitrina por grupos: en
			// Haikyuu, 44 → 36 y 07 → 01 antes de la novedad «a pedido».
			const novedades = await tarjetasDeTodasLasPaginas( request, `/serie/${ slug }/?orderby=menu_order` );
			const recientes = await tarjetasDeTodasLasPaginas( request, `/serie/${ slug }/?orderby=date` );
			const esperado = [ ...recientes.filter( ( t ) => 2 !== t.grupo ), ...recientes.filter( ( t ) => 2 === t.grupo ) ].map( ( t ) => t.id );

			expect( novedades.map( ( t ) => t.id ), `/serie/${ slug }/ con «Novedades primero»` ).toEqual( esperado );
			++medidas;
		}

		test.skip( 0 === medidas, 'este entorno no tiene ninguna de las series de referencia' );
	} );
} );
