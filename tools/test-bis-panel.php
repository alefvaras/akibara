<?php
/**
 * Pantalla «Lista de espera»: que los números digan la verdad.
 *
 * Los dos fallos que cubre, ambos medidos:
 *   - el conteo de PRODUCTOS incluía los ya avisados del todo (waiting = 0);
 *   - el conteo de PERSONAS salía de las filas pintadas, que vienen con LIMIT 50,
 *     así que a partir del producto 51 se quedaba corto en silencio.
 *
 * Se ejecuta contra el espejo Docker con MariaDB real: lo que se prueba es una
 * consulta agregada y el estado real de productos de WooCommerce, cosas que un
 * doble de $wpdb no modela.
 *
 *   cp tools/test-bis-panel.php public_html/_t.php
 *   docker compose --profile tools run --rm -T cli wp eval-file _t.php
 *   rm public_html/_t.php
 */

use Akibara\Business\Modules\BackInStock\BackInStock;
use Akibara\Business\Modules\BackInStock\Install;
use Akibara\Business\Modules\BackInStock\SubscriberRepository;

global $wpdb;
$tabla = Install::table_name();

// $GLOBALS explícito: `wp eval-file` ejecuta el fichero DENTRO de una función,
// así que las variables de este scope no son globales y un `global $fallos` en
// la función de abajo apuntaría a otra variable — el marcador salía «0/0».
$GLOBALS['akb_fallos']   = 0;
$GLOBALS['akb_corridas'] = 0;

function comprobar( string $caso, bool $ok, string $detalle = '' ): void {
	++$GLOBALS['akb_corridas'];
	if ( ! $ok ) {
		++$GLOBALS['akb_fallos'];
	}
	printf( "  %s %s%s\n", $ok ? 'OK  ' : 'FALLA', $caso, $detalle ? " — {$detalle}" : '' );
}

/** Alta de prueba. Devuelve el id insertado: la limpieza va por id, NUNCA por email. */
function sembrar( int $pid, string $slug, string $status ): int {
	global $wpdb;
	$wpdb->insert(
		Install::table_name(),
		array(
			'product_id'  => $pid,
			'email'       => "panel-qa-{$slug}@akibara.cl",
			'user_id'     => null,
			'token'       => wp_generate_password( 64, false, false ),
			'status'      => $status,
			'created_at'  => current_time( 'mysql', true ),
			'notified_at' => 'notified' === $status ? current_time( 'mysql', true ) : null,
		)
	);
	return (int) $wpdb->insert_id;
}

$sembrados = array();
$estado_previo = array();

// ── Semilla: los tres estados que importan para el titular.
$semilla = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' LIMIT 3" ) );
list( $disponible, $agotado, $ya_avisado ) = $semilla;

foreach ( $semilla as $pid ) {
	$estado_previo[ $pid ] = wc_get_product( $pid )->get_stock_status();
}

$p = wc_get_product( $disponible );
$p->set_manage_stock( false );
$p->set_stock_status( 'instock' );
$p->save();
foreach ( array( $agotado, $ya_avisado ) as $pid ) {
	$p = wc_get_product( $pid );
	$p->set_manage_stock( false );
	$p->set_stock_status( 'outofstock' );
	$p->save();
}

$sembrados[] = sembrar( $disponible, 'a', 'subscribed' );
$sembrados[] = sembrar( $disponible, 'b', 'subscribed' );
$sembrados[] = sembrar( $agotado, 'c', 'subscribed' );
$sembrados[] = sembrar( $ya_avisado, 'd', 'notified' ); // waiting = 0: NO cuenta como producto.

wp_set_current_user( 1 );
$modulo = new BackInStock();
$repo   = new SubscriberRepository( $wpdb, $tabla );

/** Renderiza el campo y devuelve el HTML. */
function pintar( BackInStock $modulo ): string {
	ob_start();
	$modulo->render_demand_field( array() );
	return (string) ob_get_clean();
}

$html = pintar( $modulo );

echo "── el titular cuadra con la base de datos ──\n";

$m = array();
preg_match( '#<strong>(\d+)</strong> personas? esperando en <strong>(\d+)</strong> productos?#u', $html, $m );
comprobar( 'el titular se pinta', 3 === count( $m ), $m ? '' : 'no se encontró el patrón' );

if ( 3 === count( $m ) ) {
	$personas  = (int) $m[1];
	$productos = (int) $m[2];

	$personas_reales  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tabla} WHERE status='subscribed'" );
	$productos_reales = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM {$tabla} WHERE status='subscribed'" );
	$con_filas        = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM {$tabla}" );

	comprobar( 'las personas cuadran', $personas === $personas_reales, "titular {$personas}, tabla {$personas_reales}" );
	comprobar( 'los productos cuadran', $productos === $productos_reales, "titular {$productos}, tabla {$productos_reales}" );
	comprobar(
		'el producto ya avisado NO infla el conteo',
		$con_filas > $productos_reales ? $productos < $con_filas : true,
		"con espera {$productos_reales}, con filas de cualquier tipo {$con_filas}"
	);
}

echo "\n── la columna Estado dice la verdad ──\n";

$fila_de = static function ( string $html, int $pid ): string {
	$titulo = get_the_title( $pid );
	$pos    = mb_strpos( $html, $titulo );
	if ( false === $pos ) {
		return '';
	}
	$fin = mb_strpos( $html, '</tr>', $pos );
	return false === $fin ? '' : mb_substr( $html, $pos, $fin - $pos );
};

comprobar( 'el disponible se marca Disponible', str_contains( $fila_de( $html, $disponible ), 'Disponible' ) );
comprobar( 'el agotado se marca Agotado', str_contains( $fila_de( $html, $agotado ), 'Agotado' ) );

// Despublicado y a pedido: se siembran y se miran de verdad.
$post_previo = get_post_status( $agotado );
wp_update_post( array( 'ID' => $agotado, 'post_status' => 'draft' ) );
clean_post_cache( $agotado );
comprobar( 'un producto despublicado se marca Despublicado', str_contains( pintar( $modulo ), 'Despublicado' ) );
wp_update_post( array( 'ID' => $agotado, 'post_status' => $post_previo ) );
clean_post_cache( $agotado );

$p = wc_get_product( $agotado );
$p->set_stock_status( 'onbackorder' );
$p->save();
comprobar( 'un producto en backorder se marca A pedido', str_contains( pintar( $modulo ), 'A pedido' ) );
$p = wc_get_product( $agotado );
$p->set_stock_status( 'outofstock' );
$p->save();

echo "\n── sin palancas manuales en la pantalla ──\n";
$html = pintar( $modulo );
comprobar( 'no hay botón de envío manual', ! str_contains( $html, 'Avisar ahora' ) );
comprobar( 'no hay columna Acción', ! str_contains( $html, '>Acción<' ) );
comprobar( 'no quedan enlaces a admin-post', ! str_contains( $html, 'akb_bis_notify_confirm' ) );

echo "\n── la promesa de retención está escrita ──\n";
comprobar( 'dice que no crece sin control', str_contains( $html, 'no crece sin control' ) );
comprobar( 'dice los 180 días', str_contains( $html, '180 días' ) );

echo "\n── a escala: por encima del techo de 50 ──\n";

// El caso que el LIMIT 50 rompía: más de 50 productos con gente esperando.
$muchos = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' LIMIT 60" ) );
foreach ( $muchos as $i => $pid ) {
	$sembrados[] = sembrar( $pid, "escala{$i}", 'subscribed' );
}

$html_escala = pintar( $modulo );
preg_match( '#<strong>(\d+)</strong> personas? esperando en <strong>(\d+)</strong> productos?#u', $html_escala, $m2 );

$personas_reales  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tabla} WHERE status='subscribed'" );
$productos_reales = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM {$tabla} WHERE status='subscribed'" );

comprobar( 'hay más de 50 productos (el caso que rompía)', $productos_reales > 50, "son {$productos_reales}" );
comprobar( 'las personas siguen cuadrando pasados los 50', (int) ( $m2[1] ?? -1 ) === $personas_reales, sprintf( 'titular %s, tabla %d', $m2[1] ?? '?', $personas_reales ) );
comprobar( 'los productos siguen cuadrando pasados los 50', (int) ( $m2[2] ?? -1 ) === $productos_reales, sprintf( 'titular %s, tabla %d', $m2[2] ?? '?', $productos_reales ) );
comprobar( 'avisa de que la tabla está cortada', str_contains( $html_escala, 'Mostrando los' ), '' );

echo "\n── reencolar sin techo ──\n";
$con_espera = $repo->products_with_waiting();
comprobar( 'products_with_waiting() los devuelve todos', count( $con_espera ) === $productos_reales, sprintf( 'devuelve %d, hay %d', count( $con_espera ), $productos_reales ) );
comprobar( 'pasa del techo viejo de 50', count( $con_espera ) > 50, sprintf( 'son %d', count( $con_espera ) ) );
comprobar( 'no incluye productos sin nadie esperando', ! in_array( $ya_avisado, $con_espera, true ) || in_array( $ya_avisado, $muchos, true ) );

// ── Restaurar: por ID, nunca por email (borrar por email se llevó filas reales).
$ids = implode( ',', array_map( 'intval', array_filter( $sembrados ) ) );
if ( '' !== $ids ) {
	$wpdb->query( "DELETE FROM {$tabla} WHERE id IN ({$ids})" );
}
foreach ( $estado_previo as $pid => $st ) {
	$p = wc_get_product( $pid );
	$p->set_stock_status( $st );
	$p->save();
}

$residuo = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tabla} WHERE email LIKE 'panel-qa-%'" );
comprobar( 'no queda residuo de la prueba', 0 === $residuo, "quedan {$residuo}" );

$fallos   = (int) $GLOBALS['akb_fallos'];
$corridas = (int) $GLOBALS['akb_corridas'];
printf( "\n%s  %d/%d comprobaciones\n", 0 === $fallos ? 'TODO EN VERDE' : "{$fallos} FALLOS", $corridas - $fallos, $corridas );
