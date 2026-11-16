<?php
/**
 * Genera DE VERDAD los cuatro correos que el gate señaló y deja constancia de
 * lo único que hay que restaurar.
 *
 * Siembra reversible por diseño: tres de los cuatro correos se disparan con
 * objetos EN MEMORIA (un Subscriber que nunca toca la tabla, los `groups` del
 * digest armados a mano, un pedido ya existente que sólo se lee). El único
 * efectos persistentes: (1) la usermeta de «ya avisé este mes» del carril de
 * puntos y (2) la order_note que WooCommerce escribe en wp_comments al
 * disparar el correo de pedido — el bloque de LIMPIEZA del final repone
 * ambos (no hay «script hermano»: la restauración vive aquí mismo, para que
 * una corrida no pueda quedar a medias entre dos ficheros).
 *
 * Se ejecuta por `wp eval-file -` (STDIN): NADA se escribe en el docroot —la
 * lección de los helpers del gate servidos por HTTP.
 */

use Akibara\Business\Modules\BackInStock\InstockEmail;
use Akibara\Business\Modules\BackInStock\Subscriber;
use Akibara\Business\Modules\SeriesFollow\SeriesFollowEmail;
use Akibara\Business\Services\AkibaraEmail;
use Akibara\Rewards\Points\Emails as RewardsEmails;
use Akibara\Rewards\Points\Expiry;

$salida = array();

// ── Destinatario de pruebas ────────────────────────────────────────────────
$usuario = get_user_by( 'email', 'alejandro.fvaras@gmail.com' );
if ( ! $usuario instanceof WP_User ) {
	$usuario = get_users( array( 'number' => 1, 'role' => 'customer' ) )[0] ?? null;
}
if ( ! $usuario instanceof WP_User ) {
	WP_CLI::error( 'Sin usuario de pruebas.' );
}
$salida['user_id']    = (int) $usuario->ID;
$salida['user_email'] = (string) $usuario->user_email;

// ── Producto real del catálogo ─────────────────────────────────────────────
$ids     = wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'return' => 'ids' ) );
$product = wc_get_product( (int) $ids[0] );
$salida['product_id']   = $product->get_id();
$salida['product_name'] = $product->get_name();

AkibaraEmail::mailer_ready();

// ── 1. Aviso de serie (digest) ─────────────────────────────────────────────
$digest = AkibaraEmail::from_mailer( SeriesFollowEmail::class );
$grupos = array(
	array(
		'term_id'      => 0,
		'series_name'  => 'Serie de prueba QA',
		'volume'       => 42,
		'product_id'   => $product->get_id(),
		'title'        => $product->get_name(),
		'url'          => (string) get_permalink( $product->get_id() ),
		'price_html'   => $product->get_price_html(),
		'image_url'    => (string) wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_thumbnail' ),
		'cutoff'       => '',
		'availability' => 'Disponible',
	),
);
$salida['series_follow'] = $digest instanceof SeriesFollowEmail
	? (bool) $digest->trigger( (int) $usuario->ID, $grupos )
	: 'sin-email';

// ── 2. Volvió a stock (BIS) ────────────────────────────────────────────────
// Subscriber EN MEMORIA: no se inserta ni se marca ninguna fila.
$bis        = AkibaraEmail::from_mailer( InstockEmail::class );
$subscriber = new Subscriber(
	0,
	$product->get_id(),
	(string) $usuario->user_email,
	(int) $usuario->ID,
	str_repeat( 'a', 64 ),
	Subscriber::STATUS_SUBSCRIBED,
	0,
	gmdate( 'Y-m-d H:i:s' ),
	null
);
$salida['bis'] = $bis instanceof InstockEmail
	? (bool) $bis->trigger( $subscriber, $product )
	: 'sin-email';

// ── 3. Correo de compra (WooCommerce core, al CLIENTE) ─────────────────────
// Pedido EXISTENTE: sólo se lee y se reenvía su aviso.
$pedidos = wc_get_orders( array( 'limit' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'return' => 'ids' ) );
$pedido  = wc_get_order( (int) $pedidos[0] );
$pedido->set_billing_email( (string) $usuario->user_email );
$salida['order_id']            = $pedido->get_id();
$salida['order_billing_email'] = $pedido->get_billing_email();
$salida['order_customer_id']   = $pedido->get_customer_id();
$salida['order_view_url']      = $pedido->get_customer_id() > 0
	? $pedido->get_view_order_url()
	: $pedido->get_checkout_order_received_url();

// Foto de las notas del pedido ANTES de disparar: la limpieza del final solo
// puede borrar lo que ESTA corrida cree — borrar toda nota con «correo
// electr» arrasó 7 notas ajenas acumuladas y desfasó el sello del baseline
// (rojo 2 de la pasada 6).
$notas_antes = array_map(
	static fn( $n ) => (int) $n->id,
	wc_get_order_notes( array( 'order_id' => $pedido->get_id() ) )
);

$correos = WC()->mailer()->get_emails();
$compra  = $correos['WC_Email_Customer_Processing_Order'] ?? null;
if ( $compra ) {
	$compra->recipient = (string) $usuario->user_email;
	// trigger() de los correos core no devuelve señal (void): castearlo a bool
	// daba `false` con el correo YA en el buzón. La verdad la mide la sonda de
	// contraste contra Mailpit; aquí solo se declara el disparo.
	$compra->trigger( $pedido->get_id(), $pedido );
	$salida['compra'] = 'disparado';
} else {
	$salida['compra'] = 'sin-email';
}

// ── 4. Carril de puntos (aviso de vencimiento) ─────────────────────────────
// ÚNICO efecto persistente: la marca «ya avisé este mes». Se fotografía.
$salida['expiry_meta_antes'] = get_user_meta( (int) $usuario->ID, Expiry::META_WARNED, true );
( new RewardsEmails() )->send_expiry_warning( (int) $usuario->ID, 500, 30 );
$salida['expiry_meta_despues'] = get_user_meta( (int) $usuario->ID, Expiry::META_WARNED, true );

// ── 5. LIMPIEZA de los efectos persistentes de ESTA corrida ────────────────
// La usermeta del aviso: si antes NO existía, se borra; si existía, se repone.
if ( '' === (string) $salida['expiry_meta_antes'] ) {
	delete_user_meta( (int) $usuario->ID, Expiry::META_WARNED );
} else {
	update_user_meta( (int) $usuario->ID, Expiry::META_WARNED, $salida['expiry_meta_antes'] );
}
$salida['expiry_meta_repuesta'] = (string) get_user_meta( (int) $usuario->ID, Expiry::META_WARNED, true ) === (string) $salida['expiry_meta_antes'] ? 'IDENTICA' : 'DIFIERE';

// La order_note del correo de pedido: WooCommerce la escribe en wp_comments
// al disparar Processing (medido: +1 fila por corrida — así se ensuciaba el
// sello del baseline). Se borran SOLO las notas de correo de ESTE pedido QA.
// wc_get_order_notes y NO get_comments: WooCommerce cuelga
// WC_Comments::exclude_order_comments de comments_clauses y las order_note
// desaparecen de TODA consulta genérica — get_comments devolvía 0 siempre y
// la limpieza era un no-op (rojo 1 de la pasada 5, mecanismo medido).
$notas_borradas = 0;
foreach ( wc_get_order_notes( array( 'order_id' => $pedido->get_id() ) ) as $nota ) {
	if ( ! in_array( (int) $nota->id, $notas_antes, true ) ) {
		wc_delete_order_note( (int) $nota->id );
		++$notas_borradas;
	}
}
$salida['order_notes_borradas'] = $notas_borradas;

WP_CLI::log( wp_json_encode( $salida, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
