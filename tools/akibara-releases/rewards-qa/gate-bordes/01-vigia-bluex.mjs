/**
 * BORDE 1 — «actualización de tercero con include roto».
 *
 * Siembra un fichero AÑADIDO (nunca edita nada del tercero) dentro de
 * bluex-for-woocommerce con un require a un fichero inexistente, corre
 * `akibara_bluex_integrity_check('qa-gate')` y mide en Mailpit:
 *
 *   1. llega la ALARMA y su cuerpo trae el REARME EXPRÉS (plugins.svn... +
 *      mu-plugins/bluex-fallback);
 *   2. al quitar el fichero, llega la BUENA NOTICIA una sola vez;
 *   3. una tercera corrida NO reenvía nada.
 *
 * Restaura las options akb_bluex_watch_baseline_seen / _resolved_notified al
 * estado exacto medido ANTES, y borra de Mailpit los dos correos que generó.
 *
 * Uso: node tools/akibara-releases/rewards-qa/gate-bordes/01-vigia-bluex.mjs
 */

import { writeFileSync, rmSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { Gate, REPO, wpEval, field, mailpit, sh } from './_lib.mjs';

const g = new Gate( 'BORDE 1 · vigía de integridad de Blue Express' );

const SONDA = join(
	REPO,
	'public_html/wp-content/plugins/bluex-for-woocommerce/qa-include-roto-temporal.php'
);
const CONTENIDO = "<?php require __DIR__ . '/no-existe-qa.php';\n";

const MEDIR = `<?php
global $wpdb;
echo 'ROWS=' . wp_json_encode( $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'akb_bluex_watch%'", ARRAY_A ) ) . "\\n";
echo 'BROKEN=' . wp_json_encode( akibara_bluex_broken_includes() ) . "\\n";
echo 'ADMIN=' . get_option( 'admin_email' ) . "\\n";
`;

const CORRER = `<?php
akibara_bluex_integrity_check( 'qa-gate' );
global $wpdb;
echo 'ROWS=' . wp_json_encode( $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'akb_bluex_watch%'", ARRAY_A ) ) . "\\n";
echo 'BROKEN=' . wp_json_encode( akibara_bluex_broken_includes() ) . "\\n";
`;

const RESTAURAR = ( rows ) => `<?php
global $wpdb;
delete_option( 'akb_bluex_watch_baseline_seen' );
delete_option( 'akb_bluex_watch_resolved_notified' );
$previas = json_decode( '${ rows.replace( /'/g, "\\'" ) }', true );
foreach ( (array) $previas as $fila ) {
	update_option( $fila['option_name'], $fila['option_value'], 'yes' === $fila['autoload'] );
}
echo 'ROWS=' . wp_json_encode( $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'akb_bluex_watch%'", ARRAY_A ) ) . "\\n";
`;

const nuevos = [];

try {
	// --- ANTES ---
	const antes = wpEval( 'b1-medir.php', MEDIR );
	const rowsAntes = field( antes, 'ROWS' );
	const brokenAntes = field( antes, 'BROKEN' );
	const total0 = mailpit( '/api/v1/messages?limit=1' ).total;

	g.check( brokenAntes === '[]', 'el árbol del tercero arranca SANO', `broken=${ brokenAntes }` );
	console.log( `      baseline options ANTES: ${ rowsAntes }` );

	// --- 1. sembrar el include roto (fichero AÑADIDO, cero ediciones al tercero) ---
	writeFileSync( SONDA, CONTENIDO, 'utf8' );
	const conRoto = wpEval( 'b1-correr.php', CORRER );
	g.check(
		field( conRoto, 'BROKEN' ).includes( 'qa-include-roto-temporal.php' ),
		'el vigía DETECTA el include roto',
		field( conRoto, 'BROKEN' )
	);

	const tras1 = mailpit( '/api/v1/messages?limit=3' );
	g.check( tras1.total === total0 + 1, 'la alarma se envió (exactamente 1 correo)', `${ total0 } → ${ tras1.total }` );

	const alarma = tras1.messages[ 0 ];
	nuevos.push( alarma.ID );
	g.check(
		alarma.Subject.includes( 'includes rotos' ),
		'el asunto es el de ALARMA',
		alarma.Subject
	);

	const cuerpo = mailpit( `/api/v1/message/${ alarma.ID }` );
	const texto = cuerpo.Text || cuerpo.HTML || '';
	g.check(
		texto.includes( 'plugins.svn.wordpress.org' ),
		'el cuerpo trae el REARME EXPRÉS (fuente SVN)',
		texto.includes( 'plugins.svn.wordpress.org' ) ? 'presente' : 'AUSENTE'
	);
	g.check(
		texto.includes( 'mu-plugins/bluex-fallback' ),
		'el cuerpo trae el REARME EXPRÉS (destino bluex-fallback)',
		texto.includes( 'mu-plugins/bluex-fallback' ) ? 'presente' : 'AUSENTE'
	);
	g.check(
		texto.includes( 'qa-include-roto-temporal.php:1' ),
		'el cuerpo nombra fichero:línea del include roto',
		texto.includes( 'qa-include-roto-temporal.php:1' ) ? 'presente' : 'AUSENTE'
	);

	// --- 2. quitar el fichero: BUENA NOTICIA una sola vez ---
	rmSync( SONDA, { force: true } );
	const sano = wpEval( 'b1-correr.php', CORRER );
	g.check( field( sano, 'BROKEN' ) === '[]', 'tras quitar la sonda el árbol vuelve a estar sano', field( sano, 'BROKEN' ) );

	const tras2 = mailpit( '/api/v1/messages?limit=3' );
	g.check( tras2.total === total0 + 2, 'la buena noticia se envió (1 correo más)', `${ tras1.total } → ${ tras2.total }` );
	const buena = tras2.messages[ 0 ];
	nuevos.push( buena.ID );
	g.check( buena.Subject.includes( 'arregló su paquete' ), 'el asunto es el de BUENA NOTICIA', buena.Subject );

	// --- 3. tercera corrida: NO reenvía ---
	wpEval( 'b1-correr.php', CORRER );
	const tras3 = mailpit( '/api/v1/messages?limit=1' ).total;
	g.check( tras3 === tras2.total, 'la tercera corrida NO reenvía la buena noticia', `${ tras2.total } → ${ tras3 }` );

	// --- RESTAURACIÓN ---
	const despues = wpEval( 'b1-restaurar.php', RESTAURAR( rowsAntes ) );
	const rowsDespues = field( despues, 'ROWS' );
	g.check( rowsDespues === rowsAntes, 'options akb_bluex_watch_* restauradas IDÉNTICAS', `antes=${ rowsAntes } después=${ rowsDespues }` );

	for ( const id of nuevos ) {
		sh( 'curl', [ '-s', '-X', 'DELETE', 'http://localhost:8025/api/v1/messages', '-H', 'Content-Type: application/json', '-d', JSON.stringify( { IDs: [ id ] } ) ] );
	}
	const totalFinal = mailpit( '/api/v1/messages?limit=1' ).total;
	g.check( totalFinal === total0, 'Mailpit restaurado al conteo previo', `antes=${ total0 } después=${ totalFinal }` );
} finally {
	if ( existsSync( SONDA ) ) {
		rmSync( SONDA, { force: true } );
		console.log( 'AVISO · se borró la sonda del tercero en el finally' );
	}
}

g.fin();
