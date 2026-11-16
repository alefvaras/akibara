/**
 * esceptico-04-cumpleanos-acredita-hueco.mjs — CUBRE EL HUECO «CUMPLEAÑOS».
 *
 * El gate mide TRES cosas del cumpleaños y ninguna es la que importa:
 *   · agente-cliente guarda la meta `_akb_rewards_birthday_md` = 03-15;
 *   · agente-dueno enciende/apaga el interruptor y mira la COPIA del front;
 *   · agente-plugins apaga el carril y comprueba que la palabra desaparece.
 * Nadie ejecuta NUNCA `akb_rewards_birthday_daily`, así que la promesa
 * «1.000 puntos cada año» está publicada y sin verificar. La memoria de la
 * casa ya avisa de este patrón: «el estado que no acredita».
 *
 * Qué mide esta sonda, sobre el carril REAL (no un doble):
 *   1. Premisa: el usuario 229 arranca con saldo 0 y sin asientos.
 *   2. Con el cumpleaños puesto en la fecha de HOY, `do_action(HOOK)` acredita
 *      EXACTAMENTE los puntos de `Options::birthday_points()`.
 *   3. Queda UN asiento con `action='birthday'` y `event_key=birthday_{u}_{a}`.
 *   4. Se sella `_akb_rewards_birthday_paid_year` con el año en curso.
 *   5. Correr el cron OTRA VEZ el mismo día NO vuelve a pagar (idempotencia
 *      anual): el saldo no se mueve y el asiento sigue siendo uno.
 *   6. Control NEGATIVO que sí puede fallar: con el cumpleaños en una fecha
 *      LEJANA (y el sello del año borrado), el cron no acredita nada.
 *
 * SIEMBRA: usermeta del 229 y un asiento en `wp_akb_rewards_ledger`.
 * RESTAURA: todas las usermeta `_akb_rewards%` del 229 y la tabla del ledger
 * a su estado exacto; correos de Mailpit borrados POR ID.
 *
 * Uso (cwd = tools/akibara-releases/rewards-qa/):
 *   node gate-bordes/esceptico-04-cumpleanos-acredita-hueco.mjs
 */

import { execFileSync } from 'node:child_process';
import { crearMarcador, sql, sqlUno, evalPhp } from './lib-bordes.mjs';

const USUARIO = 229;
const MAILPIT = 'http://localhost:8025';
const REPO = '/Users/alefvaras/Documents/akibara';

const m = crearMarcador( 'esceptico-cumpleanos-acredita' );

const curl = ( args ) => execFileSync( 'curl', args, { cwd: REPO, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 } );

function mailpit() {
	const d = JSON.parse( curl( [ '-s', `${ MAILPIT }/api/v1/messages?limit=300` ] ) );
	return ( d.messages || [] ).map( ( x ) => ( {
		id: x.ID,
		asunto: String( x.Subject || '' ),
		para: ( x.To || [] ).map( ( t ) => t.Address ).join( ',' ),
	} ) );
}

const usermetaFoto = () =>
	sql( `SELECT meta_key, meta_value FROM wp_usermeta WHERE user_id=${ USUARIO } AND meta_key LIKE '\\_akb\\_rewards%' ORDER BY meta_key` );

const asientos = () =>
	sql( `SELECT id, delta, balance_after, action, event_key FROM wp_akb_rewards_ledger WHERE user_id=${ USUARIO } ORDER BY id` )
		.split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean );

const saldo = () => parseInt( evalPhp( `echo \\Akibara\\Rewards\\Points\\Ledger::balance( ${ USUARIO } );` ), 10 );

const ledgerTotal = () => parseInt( sqlUno( 'SELECT COUNT(*) FROM wp_akb_rewards_ledger' ), 10 );

/* --------------------------------------------------------------------- */

const usermetaAntes = usermetaFoto();
const ledgerTotalAntes = ledgerTotal();
const correosAntes = mailpit();
const idsAntes = new Set( correosAntes.map( ( c ) => c.id ) );

const hoy = evalPhp( "echo ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'm-d|Y' );" );
const [ MD_HOY, ANIO ] = hoy.split( '|' );
// La clase vive en `Akibara\Rewards\Support\Options` (src/Support/Options.php),
// no en `Akibara\Rewards\Options`: con el namespace equivocado `wp eval` fatalea
// y la sonda muere antes de medir nada (visto el 2026-09-01).
const PUNTOS = parseInt( evalPhp( 'echo \\Akibara\\Rewards\\Support\\Options::birthday_points();' ), 10 );

try {
	m.check(
		'premisa-el-carril-esta-encendido-y-vale-mas-que-cero',
		PUNTOS > 0 && /^\d{2}-\d{2}$/.test( MD_HOY ),
		`Options::birthday_points()=${ PUNTOS } · hoy en la zona de la tienda = ${ MD_HOY } del ${ ANIO }`
	);

	const saldoAntes = saldo();
	m.check(
		'premisa-el-229-arranca-en-cero-y-sin-asientos',
		saldoAntes === 0 && asientos().length === 0,
		`saldo=${ saldoAntes } asientos=${ asientos().length }`
	);

	// --- 2..4. Cumpleaños HOY: el cron paga. ---
	sql( `DELETE FROM wp_usermeta WHERE user_id=${ USUARIO } AND meta_key IN ('_akb_rewards_birthday_md','_akb_rewards_birthday_paid_year')` );
	evalPhp( `update_user_meta( ${ USUARIO }, '_akb_rewards_birthday_md', '${ MD_HOY }' ); echo 'ok';` );
	evalPhp( "do_action( 'akb_rewards_birthday_daily' ); echo 'ok';" );

	const saldoTras = saldo();
	const asientosTras = asientos();
	m.check(
		'el-cron-acredita-exactamente-los-puntos-configurados',
		saldoTras === saldoAntes + PUNTOS,
		`saldo ${ saldoAntes } -> ${ saldoTras } (esperado ${ saldoAntes + PUNTOS })`
	);

	const asientoCumple = asientosTras.filter( ( l ) => l.split( '\t' )[ 3 ] === 'birthday' );
	m.check(
		'queda-UN-asiento-birthday-con-su-event-key-anual',
		asientoCumple.length === 1 &&
			asientoCumple[ 0 ].split( '\t' )[ 1 ] === String( PUNTOS ) &&
			asientoCumple[ 0 ].split( '\t' )[ 4 ] === `birthday_${ USUARIO }_${ ANIO }`,
		`asientos=[${ asientosTras.join( ' || ' ) }]`
	);

	const selloAnio = sqlUno(
		`SELECT meta_value FROM wp_usermeta WHERE user_id=${ USUARIO } AND meta_key='_akb_rewards_birthday_paid_year'`
	);
	m.check(
		'se-sella-el-anio-pagado',
		selloAnio === ANIO,
		`_akb_rewards_birthday_paid_year = "${ selloAnio }" (esperado ${ ANIO })`
	);

	// --- 5. Idempotencia anual. ---
	evalPhp( "do_action( 'akb_rewards_birthday_daily' ); echo 'ok';" );
	const saldoRepe = saldo();
	const asientosRepe = asientos();
	m.check(
		'correr-el-cron-otra-vez-NO-vuelve-a-pagar',
		saldoRepe === saldoTras && asientosRepe.length === asientosTras.length,
		`saldo ${ saldoTras } -> ${ saldoRepe } · asientos ${ asientosTras.length } -> ${ asientosRepe.length }`
	);

	// --- 6. Control NEGATIVO: sin cumpleaños hoy, no se paga. ---
	sql( `DELETE FROM wp_akb_rewards_ledger WHERE user_id=${ USUARIO }` );
	sql( `DELETE FROM wp_usermeta WHERE user_id=${ USUARIO } AND meta_key IN ('_akb_rewards_birthday_paid_year','_akb_rewards_points','_akb_rewards_total_earned')` );
	// Una fecha que NO puede caer en la ventana de hoy/ayer: se elige
	// desplazando 6 meses, así el control no depende del día de la corrida.
	const mdLejano = evalPhp(
		"echo ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+180 days' )->format( 'm-d' );"
	);
	evalPhp( `update_user_meta( ${ USUARIO }, '_akb_rewards_birthday_md', '${ mdLejano }' ); echo 'ok';` );
	const saldoBase = saldo();
	evalPhp( "do_action( 'akb_rewards_birthday_daily' ); echo 'ok';" );
	const saldoNeg = saldo();
	m.check(
		'control-negativo-sin-cumpleanos-hoy-no-acredita',
		saldoNeg === saldoBase && asientos().length === 0,
		`cumpleaños puesto en ${ mdLejano } (hoy es ${ MD_HOY }) · saldo ${ saldoBase } -> ${ saldoNeg } · asientos=${ asientos().length }`
	);
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, `EXCEPCIÓN: ${ error && error.message ? error.message : error }` );
} finally {
	// ---- RESTAURACIÓN ----
	sql( `DELETE FROM wp_akb_rewards_ledger WHERE user_id=${ USUARIO }` );
	sql( `DELETE FROM wp_usermeta WHERE user_id=${ USUARIO } AND meta_key LIKE '\\_akb\\_rewards%'` );
	const previas = usermetaAntes.split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean );
	for ( const linea of previas ) {
		const [ clave, ...resto ] = linea.split( '\t' );
		const valor = resto.join( '\t' ).replace( /'/g, "''" );
		sql( `INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (${ USUARIO }, '${ clave }', '${ valor }')` );
	}
	const usermetaDespues = usermetaFoto();
	m.check(
		'restauracion-usermeta-rewards-antes-igual-despues',
		usermetaDespues === usermetaAntes,
		`antes=[${ usermetaAntes.replace( /\n/g, ' || ' ) || 'ninguna' }] despues=[${ usermetaDespues.replace( /\n/g, ' || ' ) || 'ninguna' }]`
	);

	const ledgerTotalDespues = ledgerTotal();
	m.check(
		'restauracion-ledger-antes-igual-despues',
		ledgerTotalDespues === ledgerTotalAntes && asientos().length === 0,
		`wp_akb_rewards_ledger antes=${ ledgerTotalAntes } despues=${ ledgerTotalDespues } · asientos del ${ USUARIO } = ${ asientos().length }`
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
		`antes=${ correosAntes.length } creados=${ aBorrar.length } despues=${ tras.length } · asuntos creados=[${ finales.filter( ( c ) => ! idsAntes.has( c.id ) ).map( ( c ) => c.asunto ).join( ' | ' ) }]`
	);
}

process.exit( m.cerrar() > 0 ? 1 : 0 );
