/**
 * BORDE 3 — enlace de BAJA de serie reusado / manipulado.
 *
 * Forja el enlace firmado con la misma clase que lo emite
 * (`UnfollowHandler::url_for`) para el usuario 229 y un término REAL de
 * `pa_serie` — solo LECTURA de términos: guardar uno dispararía el rebuild del
 * buscador (suggest en 503 ~6 min).
 *
 *   GET   → página de confirmación, CERO mutación (los link-scanners hacen GET).
 *   POST  → baja efectiva (usermeta escrita).
 *   POST 2 con el mismo token → «Listo» sin duplicar y sin 500.
 *   Token CORRUPTO (un carácter cambiado, sobre OTRA serie) → la MISMA página
 *   neutra byte a byte y CERO mutación.
 *
 * Restaura la usermeta `_akb_sf_*` del 229 al estado exacto medido ANTES.
 *
 * Uso: node tools/akibara-releases/rewards-qa/gate-bordes/03-baja-serie-reusada.mjs
 */

import { Gate, wpEval, field, http } from './_lib.mjs';

const g = new Gate( 'BORDE 3 · baja de serie reusada' );

const USER = 229;

const MEDIR = `<?php
global $wpdb;
echo 'META=' . wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE '_akb_sf%%' ORDER BY meta_key", ${ USER } ), ARRAY_A ) ) . "\\n";
echo 'OPTOUT=' . wp_json_encode( \\Akibara\\Business\\Modules\\SeriesFollow\\FollowRegistry::opted_out_terms( ${ USER } ) ) . "\\n";
echo 'OPTOUT_ALL=' . var_export( \\Akibara\\Business\\Modules\\SeriesFollow\\FollowRegistry::opted_out_of_everything( ${ USER } ), true ) . "\\n";
$terms = get_terms( array( 'taxonomy' => 'pa_serie', 'hide_empty' => false, 'number' => 2, 'orderby' => 'term_id' ) );
if ( is_wp_error( $terms ) || count( $terms ) < 2 ) { echo "TERM_A=0\\nTERM_B=0\\n"; return; }
echo 'TERM_A=' . (int) $terms[0]->term_id . "\\n";
echo 'TERM_B=' . (int) $terms[1]->term_id . "\\n";
echo 'URL_A=' . \\Akibara\\Business\\Modules\\SeriesFollow\\UnfollowHandler::url_for( ${ USER }, (int) $terms[0]->term_id ) . "\\n";
echo 'TOK_B=' . \\Akibara\\Business\\Modules\\SeriesFollow\\UnfollowHandler::token( ${ USER }, (int) $terms[1]->term_id ) . "\\n";
`;

const RESTAURAR = ( metaJson ) => `<?php
global $wpdb;
delete_user_meta( ${ USER }, '_akb_sf_optout' );
delete_user_meta( ${ USER }, '_akb_sf_optout_all' );
delete_user_meta( ${ USER }, '_akb_sf_last_sent' );
foreach ( (array) json_decode( '${ metaJson.replace( /'/g, "\\'" ) }', true ) as $fila ) {
	update_user_meta( ${ USER }, $fila['meta_key'], maybe_unserialize( $fila['meta_value'] ) );
}
echo 'META=' . wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE '_akb_sf%%' ORDER BY meta_key", ${ USER } ), ARRAY_A ) ) . "\\n";
`;

const antes = wpEval( 'b3-medir.php', MEDIR );
const metaAntes = field( antes, 'META' );
const optoutAntes = field( antes, 'OPTOUT' );
const termA = Number( field( antes, 'TERM_A' ) );
const termB = Number( field( antes, 'TERM_B' ) );
const urlA = field( antes, 'URL_A' );
const tokB = field( antes, 'TOK_B' );

g.check( termA > 0 && termB > 0 && termA !== termB, 'hay dos términos REALES de pa_serie (solo lectura)', `A=${ termA } B=${ termB }` );
console.log( `      usermeta ANTES: ${ metaAntes }` );

try {
	// --- GET: confirmación, sin mutar ---
	const get1 = http( urlA );
	g.check( get1.status === 200, 'GET del enlace firmado responde 200', `http=${ get1.status }` );
	g.check( /<h1>Dejar de recibir avisos<\/h1>/.test( get1.body ), 'el GET pinta la confirmación, no la baja', 'h1=Dejar de recibir avisos' );
	g.check( /<form method="post"/.test( get1.body ), 'la confirmación exige un POST', 'form method=post presente' );

	const trasGet = wpEval( 'b3-medir.php', MEDIR );
	g.check(
		field( trasGet, 'META' ) === metaAntes,
		'el GET NO muta nada (link-scanners a salvo)',
		`meta antes=${ metaAntes } después=${ field( trasGet, 'META' ) }`
	);

	// --- POST: baja efectiva ---
	const post1 = http( urlA, { method: 'POST' } );
	g.check( post1.status === 200 && /<h1>Listo<\/h1>/.test( post1.body ), 'el POST firmado responde la página «Listo»', `http=${ post1.status }` );

	const trasPost = wpEval( 'b3-medir.php', MEDIR );
	const optoutTrasPost = JSON.parse( field( trasPost, 'OPTOUT' ) );
	g.check( optoutTrasPost.includes( termA ), 'la baja quedó registrada en usermeta', `optout=${ JSON.stringify( optoutTrasPost ) }` );
	const metaTrasPost = field( trasPost, 'META' );

	// --- POST 2 con el MISMO token ---
	const post2 = http( urlA, { method: 'POST' } );
	g.check( post2.status === 200, 'el segundo POST NO revienta (sin 500)', `http=${ post2.status }` );
	g.check( /<h1>Listo<\/h1>/.test( post2.body ), 'el segundo POST responde la misma página «Listo»', 'h1=Listo' );
	g.check( post2.body === post1.body, 'la respuesta del segundo POST es IDÉNTICA a la del primero', `${ post1.body.length } vs ${ post2.body.length } bytes` );

	const trasPost2 = wpEval( 'b3-medir.php', MEDIR );
	g.check(
		field( trasPost2, 'META' ) === metaTrasPost,
		'el segundo POST NO duplica la baja',
		`meta ${ metaTrasPost } → ${ field( trasPost2, 'META' ) }`
	);

	// --- Token CORRUPTO sobre OTRA serie (si mutara, aparecería termB) ---
	const tokMalo = ( tokB[ 0 ] === '9' ? '8' : '9' ) + tokB.slice( 1 );
	const urlMala = `http://localhost:8080/?akb_sf_u=${ USER }&akb_sf_t=${ termB }&akb_sf_k=${ tokMalo }`;
	g.check( tokMalo !== tokB, 'el token corrupto difiere en un carácter', `${ tokB.slice( 0, 6 ) }… → ${ tokMalo.slice( 0, 6 ) }…` );

	const postMalo = http( urlMala, { method: 'POST' } );
	g.check( postMalo.status === 200, 'el POST con token corrupto responde 200 (no 403/500: no delata)', `http=${ postMalo.status }` );
	g.check( postMalo.body === post1.body, 'la página del token corrupto es IDÉNTICA a la del token válido', `${ post1.body.length } vs ${ postMalo.body.length } bytes` );

	const trasMalo = wpEval( 'b3-medir.php', MEDIR );
	const optoutFinal = JSON.parse( field( trasMalo, 'OPTOUT' ) );
	g.check( ! optoutFinal.includes( termB ), 'el token corrupto NO da de baja la otra serie', `optout=${ JSON.stringify( optoutFinal ) } (termB=${ termB })` );
	g.check(
		field( trasMalo, 'META' ) === metaTrasPost,
		'el token corrupto deja CERO mutación',
		`meta ${ metaTrasPost } → ${ field( trasMalo, 'META' ) }`
	);

	const getMalo = http( urlMala );
	g.check( getMalo.status === 200 && /<h1>Dejar de recibir avisos<\/h1>/.test( getMalo.body ), 'el GET con token corrupto tampoco delata', `http=${ getMalo.status }` );
} finally {
	const despues = wpEval( 'b3-restaurar.php', RESTAURAR( metaAntes ) );
	g.check(
		field( despues, 'META' ) === metaAntes,
		'usermeta _akb_sf_* del 229 restaurada IDÉNTICA',
		`antes=${ metaAntes } después=${ field( despues, 'META' ) }`
	);
	const verif = wpEval( 'b3-medir.php', MEDIR );
	g.check( field( verif, 'OPTOUT' ) === optoutAntes, 'opt-out efectivo restaurado', `${ optoutAntes } → ${ field( verif, 'OPTOUT' ) }` );
}

g.fin();
