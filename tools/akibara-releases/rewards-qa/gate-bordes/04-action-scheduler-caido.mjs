/**
 * BORDE 4 — Action Scheduler CAÍDO.
 *
 * Dos mediciones, no una lectura de código:
 *
 *   A) CON Action Scheduler (el caso normal en WordPress real): las funciones
 *      `as_*` existen y `PublishListener::enqueue_scan()` agenda de verdad.
 *      Se comprueba con `as_has_scheduled_action` y contando filas de
 *      `wp_actionscheduler_actions` antes/después; luego se DESAGENDA lo
 *      sembrado y se verifica que el conteo vuelve.
 *
 *   B) SIN Action Scheduler: un arnés en un proceso PHP PELADO carga los
 *      ficheros REALES de `PublishListener` y `DigestRunner` con stubs mínimos
 *      y SIN definir ninguna `as_*`. Se mide que:
 *        - `enqueue_scan()` no fatalea y registra el fallback en `shutdown`
 *          (PublishListener.php:159-165),
 *        - al correr ese `shutdown` se dispara `akb_sf_scan_product(id, 0)`,
 *        - `DigestRunner::schedule_digest()` (DigestRunner.php:594-597) y
 *          `maybe_paginate()` (DigestRunner.php:562-564) salen sin fatal.
 *      Degradación documentada en DigestRunner.php:586-589: sin AS el módulo
 *      NO envía (ese entorno es el de las pruebas, no producción).
 *
 * Además verifica por regex sobre el código fuente que esas guardas siguen ahí:
 * si alguien las quita, la sonda falla.
 *
 * Uso: node tools/akibara-releases/rewards-qa/gate-bordes/04-action-scheduler-caido.mjs
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { Gate, REPO, wpEval, phpBare, field } from './_lib.mjs';

const g = new Gate( 'BORDE 4 · Action Scheduler caído' );

const SRC = join( REPO, 'public_html/wp-content/plugins/akibara-business/src/Modules/SeriesFollow' );

// ---------------------------------------------------------------- A) CON AS.
const CON_AS = `<?php
use Akibara\\Business\\Modules\\SeriesFollow\\PublishListener;
use Akibara\\Business\\Modules\\SeriesFollow\\SeriesFollow;
global $wpdb;

$faltan = array();
foreach ( array( 'as_schedule_single_action', 'as_has_scheduled_action', 'as_next_scheduled_action', 'as_unschedule_all_actions' ) as $f ) {
	if ( ! function_exists( $f ) ) { $faltan[] = $f; }
}
echo 'AS_FALTANTES=' . wp_json_encode( $faltan ) . "\\n";
if ( array() !== $faltan ) { return; }

$pid = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID DESC LIMIT 1" );
echo 'PID=' . $pid . "\\n";
$args = array( 'product_id' => $pid, 'cursor' => 0 );

echo 'ROWS_BEFORE=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = 'akb_sf_scan_product'" ) . "\\n";
echo 'HAS_BEFORE=' . var_export( as_has_scheduled_action( SeriesFollow::HOOK_SCAN, $args, SeriesFollow::AS_GROUP ), true ) . "\\n";

$r = new ReflectionMethod( PublishListener::class, 'enqueue_scan' );
$r->setAccessible( true );
$r->invoke( new PublishListener(), $pid );

echo 'HAS_AFTER=' . var_export( as_has_scheduled_action( SeriesFollow::HOOK_SCAN, $args, SeriesFollow::AS_GROUP ), true ) . "\\n";
echo 'ROWS_AFTER=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = 'akb_sf_scan_product'" ) . "\\n";

// Restauración: se quita SOLO lo sembrado (incluida la fila 'canceled').
as_unschedule_all_actions( SeriesFollow::HOOK_SCAN, $args, SeriesFollow::AS_GROUP );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND args = %s", SeriesFollow::HOOK_SCAN, wp_json_encode( $args ) ) );
echo 'HAS_RESTORED=' . var_export( as_has_scheduled_action( SeriesFollow::HOOK_SCAN, $args, SeriesFollow::AS_GROUP ), true ) . "\\n";
echo 'ROWS_RESTORED=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = 'akb_sf_scan_product'" ) . "\\n";
`;

const conAs = wpEval( 'b4-con-as.php', CON_AS );
g.check( field( conAs, 'AS_FALTANTES' ) === '[]', 'en el espejo las funciones as_* EXISTEN', field( conAs, 'AS_FALTANTES' ) );
g.check( field( conAs, 'HAS_BEFORE' ) === 'false', 'no había un scan pendiente para ese producto', `has_before=${ field( conAs, 'HAS_BEFORE' ) }` );
g.check( field( conAs, 'HAS_AFTER' ) === 'true', 'CON AS el listener agenda de verdad', `has_after=${ field( conAs, 'HAS_AFTER' ) }` );
g.check(
	Number( field( conAs, 'ROWS_AFTER' ) ) === Number( field( conAs, 'ROWS_BEFORE' ) ) + 1,
	'la fila entra en wp_actionscheduler_actions',
	`${ field( conAs, 'ROWS_BEFORE' ) } → ${ field( conAs, 'ROWS_AFTER' ) }`
);
g.check( field( conAs, 'HAS_RESTORED' ) === 'false', 'lo sembrado quedó desagendado', `has_restored=${ field( conAs, 'HAS_RESTORED' ) }` );
g.check(
	field( conAs, 'ROWS_RESTORED' ) === field( conAs, 'ROWS_BEFORE' ),
	'wp_actionscheduler_actions restaurada al conteo previo',
	`${ field( conAs, 'ROWS_BEFORE' ) } → ${ field( conAs, 'ROWS_RESTORED' ) }`
);

// ---------------------------------------------------------------- B) SIN AS.
const SIN_AS = `<?php
declare( strict_types = 1 );

namespace {
	define( 'ABSPATH', '/var/www/html/' );
	$GLOBALS['shutdowns'] = array();
	$GLOBALS['fired']     = array();
	function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
		if ( 'shutdown' === $hook ) { $GLOBALS['shutdowns'][] = $cb; }
		return true;
	}
	function do_action( $hook, ...$args ) { $GLOBALS['fired'][] = array( $hook, $args ); }
	function get_post_type( $id ) { return 'product'; }
	function get_post_status( $id ) { return 'publish'; }
}

namespace Akibara\\Business\\Modules\\SeriesFollow {
	final class SeriesFollow {
		public const HOOK_SCAN   = 'akb_sf_scan_product';
		public const HOOK_DIGEST = 'akb_sf_digest_run';
		public const AS_GROUP    = 'akibara-series-follow';
		public static function enabled(): bool { return true; }
		public static function digest_delay_seconds(): int { return 900; }
		public static function max_series_per_email(): int { return 5; }
		public static function weekly_cap_days(): int { return 7; }
	}
}

namespace {
	$base = '/var/www/html/wp-content/plugins/akibara-business/src/Modules/SeriesFollow/';
	$pre  = array();
	foreach ( array( 'as_schedule_single_action', 'as_has_scheduled_action', 'as_next_scheduled_action' ) as $f ) {
		if ( function_exists( $f ) ) { $pre[] = $f; }
	}
	echo 'PRECONDICION_AS_PRESENTES=' . json_encode( $pre ) . "\\n";

	require $base . 'PublishListener.php';
	$cls = 'Akibara\\\\Business\\\\Modules\\\\SeriesFollow\\\\PublishListener';
	$r   = new ReflectionMethod( $cls, 'enqueue_scan' );
	$r->setAccessible( true );
	$r->invoke( new $cls(), 27347 );

	echo 'SHUTDOWNS=' . count( $GLOBALS['shutdowns'] ) . "\\n";
	foreach ( $GLOBALS['shutdowns'] as $cb ) { $cb(); }
	echo 'FIRED=' . json_encode( $GLOBALS['fired'] ) . "\\n";

	require $base . 'DigestRunner.php';
	$d  = 'Akibara\\\\Business\\\\Modules\\\\SeriesFollow\\\\DigestRunner';
	$dr = ( new ReflectionClass( $d ) )->newInstanceWithoutConstructor();

	$m = new ReflectionMethod( $d, 'schedule_digest' );
	$m->setAccessible( true );
	$m->invoke( $dr, 0 );
	echo "SCHEDULE_DIGEST=sin-fatal\\n";

	$m2 = new ReflectionMethod( $d, 'maybe_paginate' );
	$m2->setAccessible( true );
	$m2->invoke( $dr, 27347, array_fill( 1, 200, array() ) );
	echo "MAYBE_PAGINATE=sin-fatal\\n";
}
`;

let sinAs = '';
try {
	sinAs = phpBare( 'b4-sin-as.php', SIN_AS );
} catch ( e ) {
	sinAs = String( e.stdout || '' ) + String( e.stderr || '' );
	g.check( false, 'el arnés SIN Action Scheduler no debe fatalear', sinAs.split( '\n' ).slice( -3 ).join( ' / ' ) );
}

g.check( field( sinAs, 'PRECONDICION_AS_PRESENTES' ) === '[]', 'el arnés corre SIN ninguna función as_*', field( sinAs, 'PRECONDICION_AS_PRESENTES' ) );
g.check( field( sinAs, 'SHUTDOWNS' ) === '1', 'SIN AS el listener registra el fallback en shutdown', `callbacks=${ field( sinAs, 'SHUTDOWNS' ) } (PublishListener.php:159-165)` );
g.check(
	field( sinAs, 'FIRED' ) === '[["akb_sf_scan_product",[27347,0]]]',
	'el fallback dispara el scan con los args correctos',
	field( sinAs, 'FIRED' )
);
g.check( field( sinAs, 'SCHEDULE_DIGEST' ) === 'sin-fatal', 'DigestRunner::schedule_digest degrada sin fatal', 'DigestRunner.php:594-597' );
g.check( field( sinAs, 'MAYBE_PAGINATE' ) === 'sin-fatal', 'DigestRunner::maybe_paginate degrada sin fatal', 'DigestRunner.php:562-564' );

// -------------------------------------------- C) las guardas siguen escritas.
const listener = readFileSync( join( SRC, 'PublishListener.php' ), 'utf8' ).split( '\n' );
const runner = readFileSync( join( SRC, 'DigestRunner.php' ), 'utf8' ).split( '\n' );

const linea = ( lineas, re ) => {
	const i = lineas.findIndex( ( l ) => re.test( l ) );
	return i === -1 ? null : i + 1;
};

const lFallback = linea( listener, /Fallback sin Action Scheduler/ );
const lGuardL = linea( listener, /if \( function_exists\( 'as_schedule_single_action' \) \) \{/ );
const lGuardPag = linea( runner, /if \( ! function_exists\( 'as_schedule_single_action' \) \) \{/ );
const lGuardDig = linea( runner, /if \( ! function_exists\( 'as_next_scheduled_action' \) \|\| ! function_exists\( 'as_schedule_single_action' \) \) \{/ );
const lDoc = linea( runner, /Sin Action Scheduler no hay ventana de acumulación posible/ );

g.check( lGuardL !== null, 'PublishListener guarda el camino de AS con function_exists', `PublishListener.php:${ lGuardL }` );
g.check( lFallback !== null, 'PublishListener documenta y ejecuta el fallback', `PublishListener.php:${ lFallback }` );
g.check( lGuardPag !== null, 'DigestRunner::maybe_paginate guarda con function_exists', `DigestRunner.php:${ lGuardPag }` );
g.check( lGuardDig !== null, 'DigestRunner::schedule_digest guarda con function_exists', `DigestRunner.php:${ lGuardDig }` );
g.check( lDoc !== null, 'la degradación sin AS está DOCUMENTADA en el código', `DigestRunner.php:${ lDoc }` );

g.nota( 'Sin Action Scheduler el scan SÍ corre (fallback en shutdown) y encola en la cola del digest, pero el ENVÍO no se agenda: DigestRunner.php:586-589 lo declara aceptado porque WooCommerce trae AS siempre.' );

g.fin();
