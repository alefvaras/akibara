/**
 * foto-229.mjs — foto reversible del cliente 229 SIN dejar nada servido.
 *
 * POR QUÉ EXISTE (incidente del 2026-09-01, gate del cutover)
 *   Tres sondas (`sonda-bordes-canje-limite`, `sonda-canje-latencia-checkout`,
 *   `sonda-superficies-micuenta-movil`) daban por hecho un
 *   `public_html/qa-bordes-snapshot.php` escrito a mano, que a su vez guardaba
 *   el estado en `public_html/.qa-bordes-229.json`. Los dos ficheros quedaron
 *   VIVOS en el docroot: `http://localhost:8080/qa-bordes-snapshot.php` y
 *   `/.qa-bordes-229.json` servían por HTTP el hash de contraseña y TODAS las
 *   usermeta del cliente 229 — datos personales de una persona real. Lo mismo
 *   con `qa-bordes-reparar-229.php`. Se borraron a mano; esto impide que
 *   vuelvan a nacer.
 *
 * CÓMO LO EVITA
 *   1. El único monte del contenedor es `public_html/`, así que el helper PHP
 *      TIENE que vivir ahí. Nace con un candado: sin `WP_CLI` responde 404 y
 *      muere antes de tocar la base — pedirlo por HTTP no devuelve nada.
 *   2. Vive lo que dura UNA llamada a `wp eval-file`: se escribe, se ejecuta y
 *      se borra en el `finally`. Además hay una red de seguridad en
 *      `process.on('exit')` para la sonda que muere a media faena.
 *   3. El estado NO se guarda en ningún fichero: viaja en memoria del proceso
 *      Node y se re-inyecta en el PHP de restauración. No hay `.json` que
 *      olvidar en el docroot.
 *   4. `residuosEnDocroot()` deja que cada sonda cierre con un check FINAL que
 *      demuestra que no quedó nada — incluidos los tres nombres del incidente.
 *
 * CONTRATO (idéntico al del helper viejo, las sondas no cambian su lógica):
 *   snapshot( 'verificar' ) → 'no existe la foto…' si aún no se guardó nada en
 *                             esta corrida; si ya se guardó, IDENTICO/DIFERENTE.
 *   snapshot( 'guardar' )   → GUARDADO (+ resumen).
 *   snapshot( 'restaurar' ) → RESTAURADO + IDENTICO/DIFERENTE.
 *
 * Qué guarda y repone del 229: el hash de la contraseña (por UPDATE directo —
 * `wp_set_password()` re-hashea y jamás daría el mismo valor), TODAS las
 * usermeta (clave y valor, en el mismo orden) y los asientos del ledger.
 */

import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, rmSync, existsSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const AQUI = path.dirname( fileURLToPath( import.meta.url ) );
export const RAIZ_REPO = path.resolve( AQUI, '../../../..' );
const DOCROOT = path.join( RAIZ_REPO, 'public_html' );
const DIR_HELPERS = path.join( DOCROOT, 'qa-gate-bordes' );
const NOMBRE = 'foto-229.php';
const RUTA_LOCAL = path.join( DIR_HELPERS, NOMBRE );
const RUTA_CONTENEDOR = `/var/www/html/qa-gate-bordes/${ NOMBRE }`;

export const USUARIO = 229;

/** Nombres que el incidente dejó servidos. Se comprueban aunque no los escriba nadie. */
const RESIDUOS_HISTORICOS = [
	'qa-bordes-snapshot.php',
	'qa-bordes-reparar-229.php',
	'.qa-bordes-229.json',
];

// El estado vive en memoria: nunca toca el disco del docroot.
let estado = null;

/** Borra el helper y, si queda vacío, su directorio. Nunca lanza. */
export function borrarHelpers() {
	try { rmSync( RUTA_LOCAL, { force: true } ); } catch ( e ) { /* nada que hacer */ }
	try {
		if ( existsSync( DIR_HELPERS ) && readdirSync( DIR_HELPERS ).length === 0 ) {
			rmSync( DIR_HELPERS, { recursive: true, force: true } );
		}
	} catch ( e ) { /* nada que hacer */ }
}

// Red de seguridad: aunque la sonda muera de excepción o la maten, el helper no
// sobrevive al proceso.
process.on( 'exit', borrarHelpers );
for ( const senal of [ 'SIGINT', 'SIGTERM' ] ) {
	process.on( senal, () => {
		borrarHelpers();
		process.exit( 1 );
	} );
}

/** Ficheros del incidente (o del helper) que siguen vivos en el docroot. */
export function residuosEnDocroot() {
	const vivos = [];
	for ( const n of RESIDUOS_HISTORICOS ) {
		if ( existsSync( path.join( DOCROOT, n ) ) ) { vivos.push( `public_html/${ n }` ); }
	}
	if ( existsSync( RUTA_LOCAL ) ) { vivos.push( `public_html/qa-gate-bordes/${ NOMBRE }` ); }
	if ( existsSync( DIR_HELPERS ) ) {
		for ( const n of readdirSync( DIR_HELPERS ) ) {
			vivos.push( `public_html/qa-gate-bordes/${ n }` );
		}
	}
	return [ ...new Set( vivos ) ];
}

const PHP = ( modo, estadoJson ) => `<?php
/**
 * Helper TEMPORAL del gate de bordes. Nace y muere dentro de UNA llamada de
 * wp eval-file: si esto se está sirviendo por HTTP, algo salió mal y lo único
 * correcto es no contestar nada.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	if ( ! headers_sent() ) { http_response_code( 404 ); }
	die();
}

$akb_modo = ${ JSON.stringify( modo ) };
$akb_uid  = ${ USUARIO };
$akb_json = <<<'AKBJSON'
${ estadoJson }
AKBJSON;
$akb_previo = json_decode( $akb_json, true );

global $wpdb;
$akb_ledger = $wpdb->prefix . 'akb_rewards_ledger';

/* Los valores viajan en base64: las usermeta traen PHP serializado y el hash
 * trae caracteres que no sobreviven a un viaje por JSON crudo. */
$akb_leer = static function () use ( $wpdb, $akb_uid, $akb_ledger ) {
	$pass  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", $akb_uid ) );
	$filas = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d ORDER BY meta_key, umeta_id", $akb_uid ), ARRAY_A );
	$metas = array();
	foreach ( $filas as $f ) {
		$metas[] = array( 'k' => (string) $f['meta_key'], 'v' => base64_encode( (string) $f['meta_value'] ) );
	}
	return array(
		'pass'   => base64_encode( $pass ),
		'metas'  => $metas,
		'ledger' => array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$akb_ledger} WHERE user_id = %d ORDER BY id", $akb_uid ) ) ),
	);
};

$akb_resumen = static function ( $e ) {
	if ( ! is_array( $e ) ) { return '(sin estado)'; }
	return 'pass=' . substr( md5( (string) $e['pass'] ), 0, 8 )
		. ' metas=' . count( $e['metas'] )
		. ' huella_metas=' . substr( md5( wp_json_encode( $e['metas'] ) ), 0, 8 )
		. ' ledger=' . count( $e['ledger'] )
		. ' ledger_max=' . ( $e['ledger'] ? max( $e['ledger'] ) : 0 );
};

if ( 'guardar' === $akb_modo ) {
	$e = $akb_leer();
	echo 'ESTADO=' . wp_json_encode( $e ) . "\\n";
	echo 'ANTES=' . $akb_resumen( $e ) . "\\n";
	echo "GUARDADO\\n";
	return;
}

if ( 'verificar' === $akb_modo ) {
	$ahora = $akb_leer();
	echo 'ANTES=' . $akb_resumen( $akb_previo ) . "\\n";
	echo 'AHORA=' . $akb_resumen( $ahora ) . "\\n";
	echo ( wp_json_encode( $akb_previo ) === wp_json_encode( $ahora ) ? "IDENTICO\\n" : "DIFERENTE\\n" );
	return;
}

if ( 'restaurar' === $akb_modo ) {
	// 1. Contraseña: UPDATE directo. wp_set_password() re-hashea y el hash
	//    resultante NUNCA sería el mismo, así que el check de identidad
	//    quedaría rojo para siempre.
	$wpdb->update( $wpdb->users, array( 'user_pass' => base64_decode( $akb_previo['pass'] ) ), array( 'ID' => $akb_uid ) );

	// 2. Asientos del ledger sembrados por la sonda: los que no estaban.
	$previos   = array_map( 'intval', (array) $akb_previo['ledger'] );
	$ahora_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$akb_ledger} WHERE user_id = %d", $akb_uid ) ) );
	$sobrantes = array_values( array_diff( $ahora_ids, $previos ) );
	if ( $sobrantes ) {
		$wpdb->query( "DELETE FROM {$akb_ledger} WHERE id IN (" . implode( ',', array_map( 'intval', $sobrantes ) ) . ")" );
	}

	// 3. Usermeta EXACTA: se borra el bloque del usuario y se reinserta la foto.
	//    Actualizar clave a clave dejaría vivas las que la sonda añadió.
	$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $akb_uid ) );
	foreach ( (array) $akb_previo['metas'] as $m ) {
		$wpdb->insert( $wpdb->usermeta, array(
			'user_id'    => $akb_uid,
			'meta_key'   => $m['k'],
			'meta_value' => base64_decode( $m['v'] ),
		) );
	}
	clean_user_cache( $akb_uid );
	wp_cache_delete( $akb_uid, 'user_meta' );

	$ahora = $akb_leer();
	echo 'RESTAURADO=' . count( (array) $akb_previo['metas'] ) . ' metas, ' . count( $sobrantes ) . " asiento(s) sobrante(s) borrado(s)\\n";
	echo 'ANTES=' . $akb_resumen( $akb_previo ) . "\\n";
	echo 'AHORA=' . $akb_resumen( $ahora ) . "\\n";
	echo ( wp_json_encode( $akb_previo ) === wp_json_encode( $ahora ) ? "IDENTICO\\n" : "DIFERENTE\\n" );
	return;
}

echo "MODO DESCONOCIDO: {$akb_modo}\\n";
`;

/** Ejecuta el helper: lo escribe, lo corre y lo borra SIEMPRE. */
function correr( modo, estadoJson ) {
	mkdirSync( DIR_HELPERS, { recursive: true } );
	writeFileSync( RUTA_LOCAL, PHP( modo, estadoJson ), 'utf8' );
	try {
		return execFileSync(
			'docker',
			[ 'compose', 'run', '--rm', '-T', 'cli', 'wp', 'eval-file', RUTA_CONTENEDOR, '--path=/var/www/html' ],
			{ cwd: RAIZ_REPO, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ], maxBuffer: 64 * 1024 * 1024 }
		);
	} finally {
		borrarHelpers();
	}
}

const campo = ( salida, clave ) => {
	const m = String( salida ).match( new RegExp( `^${ clave }=(.*)$`, 'm' ) );
	return m ? m[ 1 ] : null;
};

/**
 * `verificar` | `guardar` | `restaurar`. Devuelve el texto del helper, sin la
 * línea ESTADO= (que lleva el volcado completo y no tiene por qué acabar en un
 * log de la sonda).
 */
export function snapshot( modo ) {
	if ( 'verificar' === modo && null === estado ) {
		return 'no existe la foto del 229 en esta corrida (nada guardado todavía)';
	}

	const salida = correr( modo, estado === null ? '{}' : estado );

	if ( 'guardar' === modo ) {
		const capturado = campo( salida, 'ESTADO' );
		if ( ! capturado ) {
			return `${ salida }\nFALLO: el helper no devolvió ESTADO= (la foto NO se guardó)\n`;
		}
		estado = capturado;
	}

	// Sin la línea ESTADO= (el volcado completo no tiene por qué acabar en un
	// log) y sin el salto final: las sondas imprimen `.slice( -1 )[ 0 ]` como
	// evidencia y una línea vacía no dice nada.
	return String( salida )
		.split( '\n' )
		.filter( ( l ) => ! /^ESTADO=/.test( l ) )
		.join( '\n' )
		.trim();
}

/** ¿Hay foto en memoria? (para el que quiera decidir sin llamar al helper). */
export const hayFoto = () => null !== estado;
