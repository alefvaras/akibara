// @ts-check
/**
 * Ficha y recomendaciones, revisión independiente de la ronda 1 (lote B2,
 * 2026-09-22). Dos defectos que la suite de la ficha no veía:
 *
 *  - B2-R1: el pool al azar de «Productos relacionados» sólo llegaba cuando
 *    WooCommerce volvía a consultar, y el pool viejo vive un día en el
 *    transient `wc_related_{id}`. Medido en el espejo: 46 fichas con el pool
 *    viejo y Blue Lock 38 sacando, en cuatro visitas, las mismas seis series.
 *    Ahora business tira los pools al actualizarse (`akibara_business_upgraded`)
 *    y al cambiar el modo en el panel.
 *  - B2-R3: el título «También te recomendamos…» no se podía cambiar sin tocar
 *    código. Ahora se edita en Textos → «Recomendaciones de la ficha».
 *
 * Las dos pruebas ESCRIBEN (un transient de una ficha y dos options de copy)
 * y restauran en el `finally`, así que sólo corren en el espejo, en escritorio
 * y en serie. Leen y escriben por Docker, como `negocio-vitrina`.
 */

import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';

const ejecutar = promisify( execFile );
const RAIZ = new URL( '../../../', import.meta.url ).pathname;
const ESPEJO = /localhost/.test( process.env.AKB_BASE_URL || 'http://localhost:8080' );

/** `wp eval` en el contenedor; devuelve la última línea que sea JSON válido. */
async function wpEval( php ) {
	const { stdout } = await ejecutar( 'docker', [ 'compose', 'exec', '-T', 'cron', 'wp', 'eval', php ], { cwd: RAIZ, timeout: 120_000, maxBuffer: 16 * 1024 * 1024 } );
	for ( const linea of stdout.split( '\n' ).reverse() ) {
		try {
			return JSON.parse( linea );
		} catch {
			// Avisos de PHP o líneas vacías: se sigue buscando.
		}
	}
	throw new Error( `wp eval no devolvió JSON: ${ stdout.slice( -400 ) }` );
}

/** Serie a partir del título de una tarjeta (mismo criterio que negocio-recomendaciones). */
function serie( titulo ) {
	return titulo
		.replace( /\s+/g, ' ' )
		.trim()
		.replace( /\s*[([][^()[\]]*[)\]]\s*$/u, '' )
		.replace( /\s*(?:n[º°]|#|vol\.?)?\s*0*\d{1,4}\s*$/iu, '' )
		.trim()
		.toLocaleLowerCase( 'es' );
}

/** Series de las tarjetas de «Productos relacionados» de la página abierta. */
async function seriesRelacionadas( page ) {
	const titulos = await page.locator( '.related li.product .woocommerce-loop-product__title' ).allTextContents();
	return titulos.map( serie );
}

/**
 * Una ficha disponible LEJOS de la página 1 de los listados.
 *
 * `negocio-recomendaciones` elige sus fichas entre las primeras de
 * /disponibles/ y /tienda/ y mira sus relacionados. Si esta prueba le sembrara
 * el pool viejo a esa misma ficha mientras la otra corre en paralelo, le daría
 * un rojo que no es suyo: la primera corrida de las dos juntas dio rojo en «una
 * serie por hueco», que no se repitió en 18 corridas de esa prueba sola; con
 * la ficha lejos, tres corridas juntas en verde (2026-09-22).
 *
 * @returns {Promise<string|null>}
 */
async function fichaDisponibleLejos( page ) {
	for ( const ruta of [ '/tienda/page/3/', '/tienda/page/4/', '/disponibles/page/3/', '/disponibles/page/2/' ] ) {
		const r = await page.goto( ruta, { waitUntil: 'domcontentloaded' } ).catch( () => null );
		if ( ! r || r.status() >= 400 ) {
			continue;
		}
		const href = await page.locator( 'li.product.instock a.woocommerce-LoopProduct-link' ).first().getAttribute( 'href' ).catch( () => null );
		if ( href ) {
			return href;
		}
	}
	return null;
}

/** Id del producto de la ficha abierta (clase `postid-N` del body). */
async function idDeLaFicha( page ) {
	const clase = ( await page.locator( 'body' ).getAttribute( 'class' ) ) || '';
	const m = clase.match( /\bpostid-(\d+)\b/ );
	return m ? Number( m[ 1 ] ) : 0;
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'Recomendaciones de la ficha: pools y títulos (espejo)', () => {
	test.beforeEach( async ( {}, testInfo ) => {
		test.skip( soloLectura || ! ESPEJO, 'escribe en la base por Docker: sólo en el espejo' );
		test.skip( 'escritorio' !== testInfo.project.name, 'no depende del tamaño de pantalla y escribe: una sola vez' );
	} );

	test( 'un pool de relacionados guardado antes del despliegue no sobrevive a la actualización de business', async ( { page } ) => {
		// Varias vueltas por Docker (cada `wp eval` arranca WordPress) y fichas
		// sin caché de página en el espejo: el minuto por defecto no alcanza.
		test.setTimeout( 240_000 );
		const url = await fichaDisponibleLejos( page );
		test.skip( ! url, 'No hay tomos disponibles más allá de la página 1.' );

		await page.goto( url, { waitUntil: 'load' } );
		const id = await idDeLaFicha( page );
		expect( id, 'la ficha tiene que decir su id' ).toBeGreaterThan( 0 );
		test.skip( 0 === await page.locator( '.related li.product' ).count(), 'Esta ficha no pinta relacionados.' );

		try {
			// El pool «viejo»: tomos de UNA sola serie ajena, con las mismas
			// claves que WooCommerce acaba de guardar (límite + excluidos) y al
			// menos tantos ids como el límite, para que WooCommerce lo use tal cual.
			const viejo = await wpEval( `
				global $wpdb;
				$id = ${ id };
				$t  = get_transient( "wc_related_$id" );
				if ( ! is_array( $t ) || array() === $t ) { echo "\\n", wp_json_encode( array( "error" => "sin pool" ) ); return; }
				$mias  = wp_get_post_terms( $id, "pa_serie", array( "fields" => "ids" ) );
				$fuera = implode( ",", array_map( "intval", $mias ?: array( 0 ) ) );
				$serie = (int) $wpdb->get_var( "SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status = 'publish' WHERE tt.taxonomy = 'pa_serie' AND tt.term_id NOT IN ( $fuera ) GROUP BY tt.term_id HAVING COUNT(*) >= 8 ORDER BY COUNT(*) DESC LIMIT 1" );
				$ids   = array_map( "intval", $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id = %d AND tt.taxonomy = 'pa_serie' AND p.post_status = 'publish' LIMIT 24", $serie ) ) );
				foreach ( array_keys( $t ) as $k ) { $t[ $k ] = $ids; }
				set_transient( "wc_related_$id", $t, DAY_IN_SECONDS );
				echo "\\n", wp_json_encode( array( "serie" => get_term( $serie )->name, "ids" => count( $ids ) ) );
			` );
			expect( viejo.error, 'la ficha acaba de guardar su pool' ).toBeUndefined();

			await page.goto( url, { waitUntil: 'load' } );
			const conPoolViejo = await seriesRelacionadas( page );
			expect( new Set( conPoolViejo ).size, `Con el pool viejo sembrado (${ viejo.serie }) la ficha tiene que salir de él: ${ conPoolViejo.join( ' · ' ) }` ).toBe( 1 );

			// Sólo el oyente de las recomendaciones, sacado del WordPress
			// arrancado: los demás de `akibara_business_upgraded` recalculan la
			// vitrina y tocan ajustes, y el espejo es compartido.
			const corrida = await wpEval( `
				global $wp_filter;
				$hecho = null;
				foreach ( isset( $wp_filter["akibara_business_upgraded"] ) ? $wp_filter["akibara_business_upgraded"]->callbacks : array() as $cbs ) {
					foreach ( $cbs as $cb ) {
						$f = $cb["function"];
						if ( is_array( $f ) && "Akibara\\\\Business\\\\Modules\\\\Recommendations\\\\RelatedProducts" === ( is_object( $f[0] ) ? get_class( $f[0] ) : $f[0] ) ) { $hecho = call_user_func( $f ); }
					}
				}
				echo "\\n", wp_json_encode( array( "tirados" => $hecho, "queda" => false !== get_transient( "wc_related_${ id }" ) ) );
			` );
			expect( corrida.tirados, 'el oyente de RelatedProducts tiene que estar colgado de akibara_business_upgraded' ).toBeGreaterThan( 100 );
			expect( corrida.queda, 'el pool de la ficha ya no existe' ).toBe( false );

			await page.goto( url, { waitUntil: 'load' } );
			const conPoolNuevo = await seriesRelacionadas( page );
			expect( conPoolNuevo.length ).toBeGreaterThan( 1 );
			expect( new Set( conPoolNuevo ).size, `Tras la actualización, una serie por hueco: ${ conPoolNuevo.join( ' · ' ) }` ).toBe( conPoolNuevo.length );
		} finally {
			// Es caché: la ficha rearma el suyo en la próxima visita.
			await wpEval( `delete_transient( "wc_related_${ id }" ); echo "\\n", wp_json_encode( true );` );
		}
	} );

	test( 'dos fichas de la misma categoría no comparten el pool de relacionados', async () => {
		// Revisión RN1-D2 (2026-09-23): el pool se ordena por
		// MD5( «ID:ficha» ), y el id de la ficha sólo llega si el filtro de
		// WooCommerce se registra con dos argumentos. Con uno, llega 0 y toda la
		// categoría vuelve a sacar el MISMO pool de 24: la concentración de
		// enlaces internos que el orden por ficha vino a repartir. Se mira por la
		// ruta real (WordPress + WooCommerce, sin transient), sólo leyendo.
		test.setTimeout( 120_000 );
		const r = await wpEval( `
			$ajustes = "\\Akibara\\Business\\Modules\\Recommendations\\Settings";
			if ( $ajustes::RELATED_OTRA_SERIE !== $ajustes::current( $ajustes::OPTION_RELATED ) ) { echo "\\n", wp_json_encode( array( "modo" => "otro" ) ); return; }
			$cat = get_term_by( "slug", "manga", "product_cat" );
			if ( ! $cat ) { echo "\\n", wp_json_encode( array( "error" => "sin categoría manga" ) ); return; }
			$ids = get_posts( array( "post_type" => "product", "post_status" => "publish", "fields" => "ids", "posts_per_page" => 2, "orderby" => "ID", "order" => "ASC", "tax_query" => array( array( "taxonomy" => "product_cat", "field" => "term_id", "terms" => $cat->term_id ) ) ) );
			$ds  = WC_Data_Store::load( "product" );
			$pool = static fn( int $id ): array => array_map( "intval", $ds->get_related_products( array( $cat->term_id ), array(), array( 0, $id ), 14, $id ) );
			$a = (int) $ids[0];
			$b = (int) $ids[1];
			echo "\\n", wp_json_encode( array( "a" => $pool( $a ), "b" => $pool( $b ), "a2" => $pool( $a ) ) );
		` );
		test.skip( 'otro' === r.modo, 'Relacionados en el modo anterior: el pool es el de WooCommerce.' );
		expect( r.error ).toBeUndefined();
		expect( r.a.length, 'pool completo' ).toBe( 24 );
		expect( r.a2, 'la misma ficha, el mismo pool' ).toEqual( r.a );
		const compartidos = r.a.filter( ( id ) => r.b.includes( id ) ).length;
		// Medido en el espejo (1.596 tomos en «Manga»): con el id, dos fichas
		// comparten 0 o 1 de 24 (15 pares); con el filtro registrado a un solo
		// argumento, 24 de 24.
		expect( compartidos, `productos que comparten los pools de dos fichas de «Manga»: ${ compartidos } de 24` ).toBeLessThan( 12 );
	} );

	test( 'los títulos de los dos bloques son los de Textos, y vacío o sólo etiquetas vuelve al de siempre', async ( { page } ) => {
		// Varias vueltas por Docker (cada `wp eval` arranca WordPress) y fichas
		// sin caché de página en el espejo: el minuto por defecto no alcanza.
		test.setTimeout( 240_000 );
		const opciones = [ 'akb_copy_reco_upsells_heading', 'akb_copy_reco_related_heading' ];
		const leer = () => wpEval( `
			$o = array();
			foreach ( json_decode( ${ JSON.stringify( JSON.stringify( opciones ) ) }, true ) as $n ) { $o[ $n ] = get_option( $n, null ); }
			echo "\\n", wp_json_encode( $o );
		` );
		const antes = await leer();

		// Una ficha con los dos bloques: las landings de serie abren en el tomo 1,
		// que casi siempre tiene siguientes y relacionados.
		let url = '';
		for ( const slug of [ 'frieren', 'haikyuu', 'jujutsu-kaisen', 'one-piece', 'chainsaw-man' ] ) {
			const r = await page.goto( `/serie/${ slug }/`, { waitUntil: 'domcontentloaded' } ).catch( () => null );
			if ( ! r || r.status() >= 400 ) {
				continue;
			}
			for ( const href of ( await page.locator( 'li.product a.woocommerce-LoopProduct-link' ).evaluateAll( ( els ) => els.map( ( e ) => e.getAttribute( 'href' ) || '' ) ) ).slice( 0, 3 ) ) {
				await page.goto( href, { waitUntil: 'load' } );
				if ( await page.locator( '.up-sells > h2' ).count() && await page.locator( '.related > h2' ).count() ) {
					url = href;
					break;
				}
			}
			if ( url ) {
				break;
			}
		}
		test.skip( ! url, 'Ninguna ficha mirada pinta los dos bloques.' );

		const titulos = async () => ( {
			upsells: ( ( await page.locator( '.up-sells > h2' ).textContent() ) || '' ).trim(),
			related: ( ( await page.locator( '.related > h2' ).textContent() ) || '' ).trim(),
		} );
		const poner = ( upsells, related ) => wpEval( `
			update_option( "akb_copy_reco_upsells_heading", ${ JSON.stringify( upsells ) } );
			update_option( "akb_copy_reco_related_heading", ${ JSON.stringify( related ) } );
			echo "\\n", wp_json_encode( true );
		` );

		try {
			await poner( 'Sigue la serie (e2e)', 'Empieza otra serie (e2e)' );
			await page.goto( url, { waitUntil: 'load' } );
			expect( await titulos() ).toEqual( { upsells: 'Sigue la serie (e2e)', related: 'Empieza otra serie (e2e)' } );

			// Una fila escrita a mano (sin pasar por el panel) se lee con la regla del guardado.
			await poner( '   ', '<strong></strong>' );
			await page.goto( url, { waitUntil: 'load' } );
			expect( await titulos() ).toEqual( { upsells: 'También te recomendamos…', related: 'Productos relacionados' } );
		} finally {
			await wpEval( `
				$antes = json_decode( ${ JSON.stringify( JSON.stringify( antes ) ) }, true );
				foreach ( $antes as $n => $v ) { if ( null === $v ) { delete_option( $n ); } else { update_option( $n, $v ); } }
				echo "\\n", wp_json_encode( true );
			` );
		}

		expect( await leer(), 'las dos options vuelven a como estaban' ).toEqual( antes );
	} );
} );
