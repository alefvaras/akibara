/**
 * BORDE 2 — token de reseña REUSADO.
 *
 * Emite un token real con la maquinaria de la casa
 * (`Invitations::issue_token()`) para el pedido completado del usuario 229,
 * y mide la SUPERFICIE SERVIDA de /resena/{token}:
 *
 *   GET  → 200 con formulario del pedido correcto.
 *   POST → 200 «¡Gracias!» y UNA reseña nueva.
 *   GET/POST con el MISMO token → «Este enlace ya no sirve» y CERO reseñas
 *   nuevas (se cuentan comments antes/después).
 *
 * Restaura: borra la reseña creada (y sus metas) y la fila sembrada en
 * wp_akb_rewards_review_forms; y borra de Mailpit SÓLO los mensajes que ella
 * misma provocó, identificados por diferencia de IDs contra la foto del buzón
 * tomada al empezar (nunca por patrón de asunto: eso se llevó un correo ajeno).
 *
 * Uso: node tools/akibara-releases/rewards-qa/gate-bordes/02-token-resena-reusado.mjs
 */

import { Gate, wpEval, field, http, mailpit, sh, MAILPIT } from './_lib.mjs';

const g = new Gate( 'BORDE 2 · token de reseña reusado' );

const USER = 229;

const MEDIR = `<?php
global $wpdb;
$orders = wc_get_orders( array( 'customer_id' => ${ USER }, 'limit' => 1, 'status' => 'completed' ) );
if ( array() === $orders ) { echo "ORDER=0\\n"; return; }
$o   = $orders[0];
$pid = 0;
foreach ( $o->get_items() as $it ) { $pid = (int) $it->get_product_id(); break; }
echo 'ORDER=' . (int) $o->get_id() . "\\n";
echo 'PID=' . $pid . "\\n";
echo 'FORMS=' . wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . \\Akibara\\Rewards\\Support\\Schema::review_forms_table() . ' ORDER BY id', ARRAY_A ) ) . "\\n";
echo 'COMMENTS_TOTAL=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" ) . "\\n";
echo 'COMMENTS_PID=' . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d", $pid ) ) . "\\n";
`;

const SEMBRAR = ( orderId ) => `<?php
$token = \\Akibara\\Rewards\\Reviews\\Invitations::issue_token( ${ orderId } );
echo 'TOKEN=' . $token . "\\n";
echo 'URL=' . \\Akibara\\Rewards\\Reviews\\ReviewForm::url_for( $token ) . "\\n";
`;

const CONTAR = ( pid, token ) => `<?php
global $wpdb;
$t = \\Akibara\\Rewards\\Support\\Schema::review_forms_table();
echo 'COMMENTS_TOTAL=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" ) . "\\n";
echo 'COMMENTS_PID=' . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d", ${ pid } ) ) . "\\n";
echo 'USED_AT=' . var_export( $wpdb->get_var( $wpdb->prepare( "SELECT used_at FROM $t WHERE token = %s", '${ token }' ) ), true ) . "\\n";
`;

const RESTAURAR = ( pid, token, comentariosPrevios ) => `<?php
global $wpdb;
$t   = \\Akibara\\Rewards\\Support\\Schema::review_forms_table();
$ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d", ${ pid } ) );
$previos = json_decode( '${ JSON.stringify( comentariosPrevios ) }', true );
foreach ( $ids as $cid ) {
	if ( in_array( (int) $cid, array_map( 'intval', (array) $previos ), true ) ) { continue; }
	wp_delete_comment( (int) $cid, true );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE token = %s", '${ token }' ) );
echo 'FORMS=' . wp_json_encode( $wpdb->get_results( "SELECT * FROM $t ORDER BY id", ARRAY_A ) ) . "\\n";
echo 'COMMENTS_TOTAL=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" ) . "\\n";
echo 'COMMENTS_PID=' . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d", ${ pid } ) ) . "\\n";
`;

const IDS_PREVIOS = ( pid ) => `<?php
global $wpdb;
echo 'IDS=' . wp_json_encode( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d", ${ pid } ) ) ) ) . "\\n";
`;

/**
 * Foto de los IDs del buzón. Mailpit devuelve los mensajes del más nuevo al más
 * viejo, así que con un tope holgado la diferencia entre dos fotos son
 * EXACTAMENTE los mensajes que nacieron entre medias.
 *
 * Por qué existe (medido 2026-09-01): la restauración borraba por PATRÓN de
 * asunto («Pendientes de moderación» + no leído) sobre los 20 últimos mensajes,
 * y se llevó por delante un correo AJENO que ya estaba en el buzón antes de la
 * sonda — el conteo pasó de 234 a 233 y el check «Mailpit restaurado» lo dio
 * por bueno porque sólo miraba el total. Una sonda no puede borrar lo que no
 * creó: ahora se borran los IDs NUEVOS y nada más.
 */
const TOPE_BUZON = 500;
const fotoBuzon = () => {
	const r = mailpit( `/api/v1/messages?limit=${ TOPE_BUZON }` );
	return { total: r.total, ids: ( r.messages || [] ).map( ( m ) => m.ID ) };
};

const antes = wpEval( 'b2-medir.php', MEDIR );
const orderId = Number( field( antes, 'ORDER' ) );
const pid = Number( field( antes, 'PID' ) );
const formsAntes = field( antes, 'FORMS' );
const totalAntes = Number( field( antes, 'COMMENTS_TOTAL' ) );
const pidAntes = Number( field( antes, 'COMMENTS_PID' ) );
const buzonAntes = fotoBuzon();
const mailAntes = buzonAntes.total;

g.check( orderId > 0 && pid > 0, `hay pedido completado del usuario ${ USER }`, `pedido=${ orderId } producto=${ pid }` );

const idsPrevios = JSON.parse( field( wpEval( 'b2-ids.php', IDS_PREVIOS( pid ) ), 'IDS' ) );

const semilla = wpEval( 'b2-sembrar.php', SEMBRAR( orderId ) );
const token = field( semilla, 'TOKEN' );
const url = field( semilla, 'URL' );
g.check( !! token && token.length >= 20, 'token emitido por la maquinaria real', `${ token.slice( 0, 8 ) }… (${ token.length } chars)` );

let restaurado = false;
try {
	// --- GET 1: formulario vivo ---
	const get1 = http( url );
	const nonce = ( get1.body.match( /name="_akbnonce" value="([^"]+)"/ ) || [] )[ 1 ] || '';
	g.check( get1.status === 200, 'GET del token válido responde 200', `http=${ get1.status }` );
	g.check( /<form method="post"/.test( get1.body ), 'la página SERVIDA trae el formulario', `forms=${ ( get1.body.match( /<form method="post"/g ) || [] ).length }` );
	g.check( get1.body.includes( `Tu reseña del pedido #${ orderId }` ), 'el formulario nombra el pedido correcto', `#${ orderId }` );
	g.check( get1.body.includes( `name="akb_rf_product" value="${ pid }"` ), 'el formulario ofrece el producto del pedido', `producto=${ pid }` );
	g.check( nonce !== '', 'el formulario trae nonce', nonce || 'AUSENTE' );

	// --- POST 1: reseña legítima ---
	const post1 = http( url, {
		method: 'POST',
		form: {
			_akbnonce: nonce,
			akb_rf_product: String( pid ),
			akb_rf_rating: '5',
			akb_rf_content: 'Sonda del gate de bordes: token de resena reusado. Texto de mas de diez caracteres.',
		},
	} );
	g.check( post1.status === 200 && /¡Gracias!/.test( post1.body ), 'el POST legítimo crea la reseña', `http=${ post1.status }` );

	const medio = wpEval( 'b2-contar.php', CONTAR( pid, token ) );
	const totalMedio = Number( field( medio, 'COMMENTS_TOTAL' ) );
	const pidMedio = Number( field( medio, 'COMMENTS_PID' ) );
	const usedAt = field( medio, 'USED_AT' );
	g.check( totalMedio === totalAntes + 1, 'se creó EXACTAMENTE una reseña', `comments ${ totalAntes } → ${ totalMedio }` );
	g.check( pidMedio === pidAntes + 1, 'la reseña quedó en el producto del pedido', `producto ${ pid }: ${ pidAntes } → ${ pidMedio }` );
	g.check( usedAt !== "NULL" && usedAt !== 'NULL', 'el token quedó QUEMADO (used_at escrito)', `used_at=${ usedAt }` );

	// --- REUSO ---
	const get2 = http( url );
	g.check( get2.status === 200, 'GET del token quemado responde 200 (no 404/500)', `http=${ get2.status }` );
	g.check( /Este enlace ya no sirve/.test( get2.body ), 'el GET reusado dice «ya no sirve»', 'texto presente' );
	g.check( ! /<form method="post"/.test( get2.body ), 'el GET reusado NO sirve formulario', `forms=${ ( get2.body.match( /<form method="post"/g ) || [] ).length }` );

	const post2 = http( url, {
		method: 'POST',
		form: {
			_akbnonce: nonce,
			akb_rf_product: String( pid ),
			akb_rf_rating: '1',
			akb_rf_content: 'Segundo envio con el MISMO token: no debe crear nada de nada.',
		},
	} );
	g.check( post2.status === 200, 'POST reusado responde 200 (no 500)', `http=${ post2.status }` );
	g.check( /Este enlace ya no sirve/.test( post2.body ), 'el POST reusado dice «ya no sirve»', 'texto presente' );

	const fin = wpEval( 'b2-contar.php', CONTAR( pid, token ) );
	g.check(
		Number( field( fin, 'COMMENTS_TOTAL' ) ) === totalMedio,
		'el reuso NO crea una segunda reseña',
		`comments ${ totalMedio } → ${ field( fin, 'COMMENTS_TOTAL' ) }`
	);
	g.check(
		field( fin, 'USED_AT' ) === usedAt,
		'el reuso no re-escribe used_at',
		`${ usedAt } → ${ field( fin, 'USED_AT' ) }`
	);
} finally {
	const despues = wpEval( 'b2-restaurar.php', RESTAURAR( pid, token, idsPrevios ) );
	restaurado = true;
	g.check( field( despues, 'FORMS' ) === formsAntes, 'wp_akb_rewards_review_forms restaurada IDÉNTICA', `filas antes=${ formsAntes.length }ch después=${ field( despues, 'FORMS' ).length }ch` );
	g.check( Number( field( despues, 'COMMENTS_TOTAL' ) ) === totalAntes, 'conteo de comments restaurado', `${ totalAntes } → ${ field( despues, 'COMMENTS_TOTAL' ) }` );
	g.check( Number( field( despues, 'COMMENTS_PID' ) ) === pidAntes, `conteo de reseñas del producto ${ pid } restaurado`, `${ pidAntes } → ${ field( despues, 'COMMENTS_PID' ) }` );

	// El alta de la reseña dispara el aviso de moderación al dueño: se limpia
	// POR ID, no por asunto. Los que se borran son los que no estaban en la
	// foto inicial del buzón — los que creó esta sonda y nadie más.
	const buzonDespues = fotoBuzon();
	const previos = new Set( buzonAntes.ids );
	const nuevos = buzonDespues.ids.filter( ( id ) => ! previos.has( id ) );
	if ( nuevos.length ) {
		sh( 'curl', [
			'-s', '-X', 'DELETE', `${ MAILPIT }/api/v1/messages`,
			'-H', 'Content-Type: application/json',
			'-d', JSON.stringify( { IDs: nuevos } ),
		] );
	}
	const mailDespues = mailpit( '/api/v1/messages?limit=1' ).total;
	g.check(
		mailDespues === mailAntes,
		'Mailpit restaurado al conteo previo',
		`${ mailAntes } → ${ mailDespues } (borrados ${ nuevos.length } mensaje(s) NUEVO(s) por ID: ${ nuevos.join( ', ' ) || 'ninguno' })`
	);
}

if ( ! restaurado ) {
	g.check( false, 'la restauración no llegó a correr', 'revisar a mano' );
}

g.fin();
