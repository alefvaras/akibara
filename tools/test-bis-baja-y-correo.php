<?php
/**
 * Dos cosas que el cliente lee y que estaban a medias:
 *
 *  1. La pantalla de baja decía «este producto» sin nombrarlo. Quien espera
 *     varios tomos no sabía de cuál se estaba dando de baja — y con el tope de
 *     altas subido a 12, esperar varios pasó a ser lo normal.
 *  2. La mitad de texto plano del correo de restock se dejaba el precio, que la
 *     mitad HTML sí muestra.
 *
 *   cp tools/test-bis-baja-y-correo.php public_html/_t.php
 *   docker compose --profile tools run --rm -T cli wp eval-file _t.php
 *   rm public_html/_t.php
 */

use Akibara\Business\Modules\BackInStock\Install;
use Akibara\Business\Modules\BackInStock\UnsubscribeHandler;
use Akibara\Business\Modules\BackInStock\SubscriberRepository;

global $wpdb;
$tabla = Install::table_name();

$GLOBALS['akb_fallos']   = 0;
$GLOBALS['akb_corridas'] = 0;

function comprobar( string $caso, bool $ok, string $detalle = '' ): void {
	++$GLOBALS['akb_corridas'];
	if ( ! $ok ) {
		++$GLOBALS['akb_fallos'];
	}
	printf( "  %s %s%s\n", $ok ? 'OK  ' : 'FALLA', $caso, $detalle ? " — {$detalle}" : '' );
}

function sembrar( int $pid, string $slug, string $token ): int {
	global $wpdb;
	$wpdb->insert(
		Install::table_name(),
		array(
			'product_id' => $pid,
			'email'      => "baja-qa-{$slug}@akibara.cl",
			'user_id'    => null,
			'token'      => $token,
			'status'     => 'subscribed',
			'created_at' => current_time( 'mysql', true ),
		)
	);
	return (int) $wpdb->insert_id;
}

// ── Semilla: una persona esperando TRES tomos distintos.
$ids  = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' LIMIT 3" ) );
$toks = array();
$rows = array();
foreach ( $ids as $i => $pid ) {
	// bin2hex(random_bytes(32)): EXACTAMENTE como los genera
	// CaptureRestController. El handler exige /^[a-f0-9]{64}$/, así que un token
	// de wp_generate_password (que trae mayúsculas) se descarta por malformado y
	// la prueba mide el camino equivocado sin decirlo.
	$toks[ $i ] = bin2hex( random_bytes( 32 ) );
	$rows[]     = sembrar( $pid, 'multi', $toks[ $i ] );
}
// Los tres con el MISMO correo: es el caso del coleccionista.
$wpdb->query( "UPDATE {$tabla} SET email='baja-qa-multi@akibara.cl' WHERE id IN (" . implode( ',', $rows ) . ')' );

$repo    = new SubscriberRepository( $wpdb, $tabla );
$handler = new UnsubscribeHandler( $repo );

/** Ejecuta el handler capturando el HTML sin dejar que haga exit. */
function pantalla( UnsubscribeHandler $h, string $token, string $metodo ): string {
	$_GET['akb_bis_unsub']     = $token;
	$_SERVER['REQUEST_METHOD'] = $metodo;
	// El filtro decide si la página hace exit; devolviendo false se puede seguir
	// midiendo dentro del mismo proceso.
	$captura = static fn() => false;
	add_filter( 'akb_bis_unsub_should_exit', $captura, 1 );
	ob_start();
	$h->handle();
	$html = (string) ob_get_clean();
	remove_filter( 'akb_bis_unsub_should_exit', $captura, 1 );
	unset( $_GET['akb_bis_unsub'] );
	return $html;
}

$nombre_0 = get_the_title( $ids[0] );

echo "── la pantalla de confirmación nombra el tomo ──\n";
$html = pantalla( $handler, $toks[0], 'GET' );
comprobar( 'nombra el producto', str_contains( $html, $nombre_0 ), mb_substr( wp_strip_all_tags( $html ), 0, 90 ) );
comprobar( 'ya no dice «este producto» a secas', ! str_contains( $html, 'cuando este producto vuelva' ) );

// El GET no debe dar de baja a nadie: los escáneres de correo visitan enlaces.
$sigue = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tabla} WHERE token=%s AND status='subscribed'", $toks[0] ) );
comprobar( 'el GET NO da de baja (escáneres de correo)', 1 === $sigue );

echo "\n── al confirmar, dice de qué se dio de baja y qué le queda ──\n";
$html = pantalla( $handler, $toks[0], 'POST' );
comprobar( 'nombra el producto dado de baja', str_contains( $html, $nombre_0 ), mb_substr( wp_strip_all_tags( $html ), 0, 100 ) );
comprobar( 'avisa de que las otras esperas siguen', str_contains( $html, 'siguen activos' ), '' );
comprobar( 'dice cuántas otras quedan (2)', str_contains( $html, 'otros 2 productos' ), '' );

$estado = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tabla} WHERE token=%s", $toks[0] ) );
comprobar( 'el POST sí da de baja', 'unsubscribed' === $estado, $estado );

echo "\n── con una sola espera, no promete lo que no hay ──\n";
// Dar de baja la segunda: queda 1 → «otro producto» en singular.
$html = pantalla( $handler, $toks[1], 'POST' );
comprobar( 'singular correcto con una sola restante', str_contains( $html, 'otro producto' ) && ! str_contains( $html, 'otros 1' ), '' );

// Y la última: ya no queda ninguna → no debe prometer nada.
$html = pantalla( $handler, $toks[2], 'POST' );
comprobar( 'sin otras esperas, no menciona ninguna', ! str_contains( $html, 'siguen activos' ), '' );

echo "\n── el precio en la mitad de texto plano del correo ──\n";
$producto = wc_get_product( $ids[0] );
$plantilla = AKIBARA_BUSINESS_DIR . 'templates/emails/plain/customer-bis-instock.php';
comprobar( 'la plantilla plana existe', file_exists( $plantilla ) );

// Render directo con las mismas variables que le pasa InstockEmail.
$product            = $producto;
$product_name       = $producto->get_name();
$product_price_html = $producto->get_price_html();
$product_url        = get_permalink( $ids[0] );
$unsubscribe_url    = home_url( '/?akb_bis_unsub=x' );
$email_heading      = 'Volvió a stock';
$additional_content = '';
$plain_text         = true;
$email              = null;

ob_start();
include $plantilla;
$plano = (string) ob_get_clean();

comprobar( 'la versión plana incluye el precio', str_contains( $plano, 'Precio:' ), '' );
$sin_html = ! preg_match( '/<[a-z]/i', $plano );
comprobar( 'el texto plano no lleva etiquetas HTML', $sin_html, $sin_html ? '' : 'hay etiquetas sueltas' );
$linea = '';
foreach ( explode( "\n", $plano ) as $l ) {
	if ( str_starts_with( $l, 'Precio:' ) ) {
		$linea = $l;
		break;
	}
}
comprobar( 'el precio se lee limpio', '' !== $linea && ! str_contains( $linea, '&' ), $linea );

// ── Restaurar por ID.
$wpdb->query( 'DELETE FROM ' . $tabla . ' WHERE id IN (' . implode( ',', array_map( 'intval', $rows ) ) . ')' );
$residuo = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tabla} WHERE email LIKE 'baja-qa-%'" );
comprobar( 'no queda residuo', 0 === $residuo, "quedan {$residuo}" );

$fallos   = (int) $GLOBALS['akb_fallos'];
$corridas = (int) $GLOBALS['akb_corridas'];
printf( "\n%s  %d/%d comprobaciones\n", 0 === $fallos ? 'TODO EN VERDE' : "{$fallos} FALLOS", $corridas - $fallos, $corridas );
