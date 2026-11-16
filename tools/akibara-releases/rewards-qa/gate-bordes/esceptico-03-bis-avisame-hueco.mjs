/**
 * esceptico-03-bis-avisame-hueco.mjs — CUBRE EL HUECO «BIS» del gate.
 *
 * Ninguna sonda del gate (ni los 4 agentes, ni las 6 de bordes, ni las 7 de
 * superficies, ni las 5 de resiliencia) toca el módulo Back-In-Stock, aunque
 * el enunciado del gate nombra explícitamente el correo «BIS» y el espejo
 * tiene 13 suscriptores vivos en `wp_akb_bis_subscribers`.
 *
 * Esta sonda recorre el carril entero sobre la SUPERFICIE SERVIDA:
 *   1. La ficha AGOTADA sirve el formulario «Avisarme» (positivo: si el
 *      formulario no estuviera, todo lo demás sería un no-op silencioso).
 *   2. El alta por el endpoint REST público crea UNA fila `subscribed`.
 *   3. Re-enviar el MISMO email no duplica la fila (idempotencia).
 *   4. El honeypot (`akb_hp_url` relleno) responde igual que un alta real
 *      pero NO crea fila — control negativo que sí puede fallar.
 *   5. Al reponer el stock (`outofstock` -> `instock`) sale el correo al
 *      suscriptor y la fila pasa a `notified` con `notified_at`.
 *   6. Volver a disparar el despacho NO manda un segundo correo (at-most-once).
 *
 * SIEMBRA: una fila en `wp_akb_bis_subscribers`, el estado de stock del
 * producto 13079 y los correos que eso genere.
 * RESTAURA: fila borrada por id, stock y postmeta `_akb_*` del producto
 * repuestos al valor leído antes, correos de Mailpit borrados POR ID.
 *
 * NO toca términos de taxonomía.
 *
 * Uso (cwd = tools/akibara-releases/rewards-qa/):
 *   node gate-bordes/esceptico-03-bis-avisame-hueco.mjs
 */

import { execFileSync } from 'node:child_process';
import { crearMarcador, sql, sqlUno, evalPhp, BASE } from './lib-bordes.mjs';

const PRODUCTO = 13079;                       // Chainsaw Man 01 — outofstock.
const SLUG = 'chainsaw-man-1-ivrea-argentina';
const EMAIL = 'qa.esceptico.bis@example.test';
const MAILPIT = 'http://localhost:8025';
const REPO = '/Users/alefvaras/Documents/akibara';

const m = crearMarcador( 'esceptico-bis-avisame' );

const curl = ( args ) => execFileSync( 'curl', args, { cwd: REPO, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 } );

function mailpit() {
	const d = JSON.parse( curl( [ '-s', `${ MAILPIT }/api/v1/messages?limit=300` ] ) );
	return ( d.messages || [] ).map( ( x ) => ( {
		id: x.ID,
		asunto: String( x.Subject || '' ),
		para: ( x.To || [] ).map( ( t ) => t.Address ).join( ',' ),
	} ) );
}

/** POST al endpoint REST público de alta. Devuelve {status, cuerpo}. */
function altaBis( extra = {} ) {
	const cuerpo = JSON.stringify( { product_id: PRODUCTO, email: EMAIL, ...extra } );
	const salida = curl( [
		'-s', '-o', '/dev/stdout', '-w', '\n__HTTP__%{http_code}',
		'-X', 'POST', `${ BASE }/wp-json/akibara/v1/back-in-stock`,
		'-H', 'Content-Type: application/json', '-d', cuerpo,
	] );
	const cod = /__HTTP__(\d+)/.exec( salida );
	return { status: cod ? Number( cod[ 1 ] ) : 0, cuerpo: salida.replace( /\n__HTTP__\d+/, '' ).slice( 0, 240 ) };
}

const filas = () =>
	sql( `SELECT id, status, IFNULL(notified_at,'NULL') FROM wp_akb_bis_subscribers WHERE product_id=${ PRODUCTO } AND email='${ EMAIL }'` )
		.split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean );

const totalFilas = () => parseInt( sqlUno( 'SELECT COUNT(*) FROM wp_akb_bis_subscribers' ), 10 );

const fotoStock = () => sqlUno(
	`SELECT CONCAT(IFNULL(stock_quantity,'NULL'),'|',stock_status) FROM wp_wc_product_meta_lookup WHERE product_id=${ PRODUCTO }`
);

const fotoMeta = () =>
	sql( `SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id=${ PRODUCTO } AND meta_key LIKE '\\_akb\\_%' ORDER BY meta_key` );

/* --------------------------------------------------------------------- */

const stockAntes = fotoStock();
const metaAntes = fotoMeta();
const filasTotalAntes = totalFilas();
const correosAntes = mailpit();
const idsAntes = new Set( correosAntes.map( ( c ) => c.id ) );

try {
	m.check(
		'premisa-el-producto-esta-agotado',
		/\|outofstock$/.test( stockAntes ),
		`wp_wc_product_meta_lookup(${ PRODUCTO }) = ${ stockAntes }`
	);

	// --- 1. La ficha SERVIDA trae el formulario «Avisarme». ---
	const ficha = curl( [ '-s', `${ BASE }/product/${ SLUG }/` ] );
	const tieneForm = /class="akb-bis__form"/.test( ficha );
	const tieneBoton = /data-idle-label="Avisarme"/.test( ficha );
	const tieneHp = /name="akb_hp_url"/.test( ficha );
	m.check(
		'la-ficha-agotada-sirve-el-formulario-avisarme',
		tieneForm && tieneBoton && tieneHp,
		`bytes=${ ficha.length } · form=${ tieneForm } botón «Avisarme»=${ tieneBoton } honeypot=${ tieneHp }`
	);

	// Punto de partida limpio para ESTE email.
	sql( `DELETE FROM wp_akb_bis_subscribers WHERE product_id=${ PRODUCTO } AND email='${ EMAIL }'` );

	// --- 4. HONEYPOT primero: si creara fila, el resto mediría basura. ---
	const trampa = altaBis( { akb_hp_url: 'https://spam.example/' } );
	const trasTrampa = filas();
	m.check(
		'el-honeypot-no-crea-fila-aunque-responda-como-un-alta',
		trasTrampa.length === 0,
		`HTTP ${ trampa.status } cuerpo=${ trampa.cuerpo } · filas para ${ EMAIL } = ${ trasTrampa.length }`
	);

	// --- 2. Alta real. ---
	const alta = altaBis();
	const trasAlta = filas();
	m.check(
		'el-alta-real-crea-UNA-fila-subscribed',
		alta.status >= 200 && alta.status < 300 && trasAlta.length === 1 && /\tsubscribed\t/.test( '\t' + trasAlta[ 0 ].split( '\t' ).join( '\t' ) + '\t' ),
		`HTTP ${ alta.status } cuerpo=${ alta.cuerpo } · filas=[${ trasAlta.join( ' || ' ) }]`
	);

	// --- 3. Idempotencia. ---
	const repe = altaBis();
	const trasRepe = filas();
	m.check(
		'reenviar-el-mismo-email-no-duplica',
		trasRepe.length === 1 && trasRepe[ 0 ].split( '\t' )[ 0 ] === trasAlta[ 0 ].split( '\t' )[ 0 ],
		`HTTP ${ repe.status } · filas=[${ trasRepe.join( ' || ' ) }] (id primero=${ trasAlta[ 0 ].split( '\t' )[ 0 ] })`
	);

	// El alta manda su PROPIO correo («Te anotamos en la lista de espera de…»).
	// Contar «cualquier correo a esa dirección» daba verde con ese acuse y sin
	// el aviso de restock: hay que distinguirlos POR ASUNTO (medido 2026-09-01).
	const esAcuse = ( c ) => /Te anotamos en la lista de espera/i.test( c.asunto );
	const esRestock = ( c ) => /está de vuelta en Akibara/i.test( c.asunto );

	const acuses = mailpit().filter( ( c ) => ! idsAntes.has( c.id ) && c.para.includes( EMAIL ) && esAcuse( c ) );
	m.check(
		'el-alta-manda-su-acuse-de-lista-de-espera',
		acuses.length === 1,
		`acuses «Te anotamos…» para ${ EMAIL } = ${ acuses.length } · asuntos=[${ acuses.map( ( c ) => c.asunto ).join( ' | ' ) }]`
	);

	// --- 5. Reposición: el hook real + el despacho del job.
	//     `set_stock_status('instock')` a secas NO basta: el producto gestiona
	//     existencias y Woo recalcula el estado desde la cantidad al guardar,
	//     así que se quedaba en outofstock y la reposición no ocurría (visto
	//     el 2026-09-01). Hay que subir también la cantidad.
	const correosPrevios = mailpit().length;
	evalPhp(
		`$p = wc_get_product( ${ PRODUCTO } ); $p->set_stock_quantity( 5 ); $p->set_stock_status( 'instock' ); $p->save(); do_action( 'akb_bis_notify_product', ${ PRODUCTO } ); echo 'ok';`
	);
	const stockTrasReponer = fotoStock();
	m.check(
		'la-reposicion-deja-el-producto-instock',
		/\|instock$/.test( stockTrasReponer ),
		`${ stockAntes } -> ${ stockTrasReponer }`
	);

	const correosTras = mailpit();
	const nuevos = correosTras.filter( ( c ) => ! idsAntes.has( c.id ) );
	const alSuscriptor = nuevos.filter( ( c ) => c.para.includes( EMAIL ) && esRestock( c ) );
	m.check(
		'el-suscriptor-recibe-EXACTAMENTE-un-aviso-de-vuelta-a-stock',
		alSuscriptor.length === 1,
		`correos ${ correosPrevios } -> ${ correosTras.length } · nuevos=[${ nuevos.map( ( c ) => `${ c.para }: ${ c.asunto }` ).join( ' | ' ) }] · con asunto «… está de vuelta en Akibara» a ${ EMAIL } = ${ alSuscriptor.length }`
	);

	const trasAviso = filas();
	m.check(
		'la-fila-queda-notified-con-fecha',
		trasAviso.length === 1 && trasAviso[ 0 ].split( '\t' )[ 1 ] === 'notified' && trasAviso[ 0 ].split( '\t' )[ 2 ] !== 'NULL',
		`fila=[${ trasAviso.join( ' || ' ) }]`
	);

	// --- 6. At-most-once: repetir el despacho no manda otro correo. ---
	const antesSegundo = mailpit().length;
	evalPhp( `do_action( 'akb_bis_notify_product', ${ PRODUCTO } ); echo 'ok';` );
	const despuesSegundo = mailpit();
	const alSuscriptor2 = despuesSegundo.filter( ( c ) => ! idsAntes.has( c.id ) && c.para.includes( EMAIL ) && esRestock( c ) );
	m.check(
		'un-segundo-despacho-no-reenvia-el-aviso',
		alSuscriptor2.length === 1 && despuesSegundo.length === antesSegundo,
		`correos ${ antesSegundo } -> ${ despuesSegundo.length } · avisos de restock a ${ EMAIL } acumulados = ${ alSuscriptor2.length }`
	);
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, `EXCEPCIÓN: ${ error && error.message ? error.message : error }` );
} finally {
	// ---- RESTAURACIÓN ----
	sql( `DELETE FROM wp_akb_bis_subscribers WHERE product_id=${ PRODUCTO } AND email='${ EMAIL }'` );
	const filasDespues = totalFilas();
	m.check(
		'restauracion-filas-bis-antes-igual-despues',
		filasDespues === filasTotalAntes,
		`wp_akb_bis_subscribers antes=${ filasTotalAntes } despues=${ filasDespues }`
	);

	const [ cantidad, estado ] = stockAntes.split( '|' );
	const setQty = 'NULL' === cantidad ? 'null' : String( parseInt( cantidad, 10 ) );
	evalPhp(
		`$p = wc_get_product( ${ PRODUCTO } ); $p->set_stock_quantity( ${ setQty } ); $p->set_stock_status( '${ estado }' ); $p->save(); echo 'ok';`
	);
	const stockDespues = fotoStock();
	m.check(
		'restauracion-stock-antes-igual-despues',
		stockDespues === stockAntes,
		`antes=${ stockAntes } despues=${ stockDespues }`
	);

	// Guardar el producto escribe metas propias (p. ej. `_akb_pdp_specs_generation`)
	// que NO existían: se borran las claves nuevas y se reponen las que cambiaron.
	const clavesAntes = new Map(
		metaAntes.split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean ).map( ( l ) => {
			const [ k, ...resto ] = l.split( '\t' );
			return [ k, resto.join( '\t' ) ];
		} )
	);
	const intermedia = fotoMeta().split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean ).map( ( l ) => {
		const [ k, ...resto ] = l.split( '\t' );
		return [ k, resto.join( '\t' ) ];
	} );
	const reparadas = [];
	for ( const [ k, v ] of intermedia ) {
		if ( ! clavesAntes.has( k ) ) {
			sql( `DELETE FROM wp_postmeta WHERE post_id=${ PRODUCTO } AND meta_key='${ k }'` );
			reparadas.push( `borrada ${ k }` );
		} else if ( clavesAntes.get( k ) !== v ) {
			sql( `UPDATE wp_postmeta SET meta_value='${ clavesAntes.get( k ).replace( /'/g, "''" ) }' WHERE post_id=${ PRODUCTO } AND meta_key='${ k }'` );
			reparadas.push( `repuesta ${ k }` );
		}
	}
	const metaDespues = fotoMeta();
	m.check(
		'restauracion-postmeta-akb-antes-igual-despues',
		metaDespues === metaAntes,
		metaDespues === metaAntes
			? `${ metaAntes.split( '\n' ).filter( Boolean ).length } filas _akb_* idénticas · reparadas=[${ reparadas.join( ', ' ) || 'ninguna' }]`
			: `ANTES=[${ metaAntes.replace( /\n/g, ' || ' ) }] AHORA=[${ metaDespues.replace( /\n/g, ' || ' ) }]`
	);

	const finales = mailpit();
	const aBorrar = finales.filter( ( c ) => ! idsAntes.has( c.id ) ).map( ( c ) => c.id );
	if ( aBorrar.length ) {
		curl( [ '-s', '-X', 'DELETE', `${ MAILPIT }/api/v1/messages`, '-H', 'Content-Type: application/json', '-d', JSON.stringify( { IDs: aBorrar } ) ] );
	}
	const tras = mailpit();
	m.check(
		'restauracion-mailpit-por-ID-antes-igual-despues',
		tras.length === correosAntes.length && tras.every( ( c ) => idsAntes.has( c.id ) ),
		`antes=${ correosAntes.length } creados=${ aBorrar.length } despues=${ tras.length }`
	);
}

process.exit( m.cerrar() > 0 ? 1 : 0 );
