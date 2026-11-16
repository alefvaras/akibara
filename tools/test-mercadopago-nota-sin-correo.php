<?php
/**
 * Candado del mu-plugin `akibara-mercadopago-nota-sin-correo.php`. No carga
 * WordPress ni abre red: `php tools/test-mercadopago-nota-sin-correo.php`.
 *
 * INVARIANTES QUE FIJA (R7-nota-mercado-pago, 2026-09-20):
 *   1. Sin Mercado Pago cargado (`MP_PLUGIN_FILE` sin definir) no engancha nada.
 *   2. Con Mercado Pago, el correo «Nota para el cliente» se deshabilita SÓLO
 *      cuando la nota empieza por «Mercado Pago: ».
 *   3. Una nota escrita por el dueño sigue saliendo; un correo ya deshabilitado
 *      sigue deshabilitado; sin objeto de correo (listado del admin) no se toca.
 *
 * @package Akibara\Tools
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );

/** @var array<string, array<int, array{0: callable, 1: int, 2: int}>> */
$akibara_test_hooks = array();

/** @param callable|string $callback */
function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $akibara_test_hooks;
	$akibara_test_hooks[ $hook ][] = array( $callback, $priority, $accepted_args );
}

/** @param callable|string $callback */
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	add_action( $hook, $callback, $priority, $accepted_args );
}

require dirname( __DIR__ ) . '/public_html/wp-content/mu-plugins/akibara-mercadopago-nota-sin-correo.php';

$fallos = 0;
$casos  = 0;

/**
 * @param mixed $esperado Valor esperado.
 * @param mixed $real     Valor obtenido.
 */
function caso( string $nombre, $esperado, $real ): void {
	global $fallos, $casos;
	++$casos;
	if ( $esperado === $real ) {
		echo "  ok   $nombre\n";
		return;
	}
	++$fallos;
	echo "  FALLA $nombre: esperado " . var_export( $esperado, true ) . ', obtenido ' . var_export( $real, true ) . "\n";
}

/**
 * Doble de WC_Email_Customer_Note: la nota es propiedad pública.
 *
 * @return object
 */
function correo_con_nota( ?string $nota ): object {
	$email = new stdClass();
	if ( null !== $nota ) {
		$email->customer_note = $nota;
	}
	return $email;
}

echo "1. Se engancha en plugins_loaded y muere con Mercado Pago\n";
caso( 'escucha plugins_loaded a prioridad 20', 20, $akibara_test_hooks['plugins_loaded'][0][1] ?? null );
akibara_mp_nota_sin_correo_enganchar();
caso( 'sin MP_PLUGIN_FILE no engancha el filtro', false, isset( $akibara_test_hooks['woocommerce_email_enabled_customer_note'] ) );

define( 'MP_PLUGIN_FILE', '/plugins/woocommerce-mercadopago/woocommerce-mercadopago.php' );
akibara_mp_nota_sin_correo_enganchar();
caso( 'con Mercado Pago engancha el filtro con 3 argumentos', 3, $akibara_test_hooks['woocommerce_email_enabled_customer_note'][0][2] ?? null );

echo "2. Qué correos se callan\n";
caso( '«Mercado Pago: Pago aprobado.» no sale', false, akibara_mp_nota_sin_correo_filtrar( true, null, correo_con_nota( 'Mercado Pago: Pago aprobado.' ) ) );
caso( 'con espacio delante también', false, akibara_mp_nota_sin_correo_filtrar( true, null, correo_con_nota( '  Mercado Pago: Pago pendiente.' ) ) );
caso( 'otra nota del plugin (rechazo) tampoco sale', false, akibara_mp_nota_sin_correo_filtrar( true, null, correo_con_nota( 'Mercado Pago: El pago fue rechazado.' ) ) );
caso( 'la nota HTML de Pix (prefijo dentro de la traducción, tras un <div>) tampoco sale', false, akibara_mp_nota_sin_correo_filtrar( true, null, correo_con_nota( "\n                <div style='text-align: justify;'><p>Mercado Pago: Now you just need to pay with Pix to finalize your purchase.</p></div>" ) ) );

echo "3. Qué correos siguen saliendo\n";
caso( 'nota del dueño sale', true, akibara_mp_nota_sin_correo_filtrar( true, null, correo_con_nota( 'Tu pedido sale mañana por Blue Express.' ) ) );
caso( 'mencionar Mercado Pago en medio no es prefijo', true, akibara_mp_nota_sin_correo_filtrar( true, null, correo_con_nota( 'Pagaste con Mercado Pago: gracias.' ) ) );
caso( 'ya deshabilitado sigue deshabilitado', false, akibara_mp_nota_sin_correo_filtrar( false, null, correo_con_nota( 'Tu pedido sale mañana.' ) ) );
caso( 'sin objeto de correo (listado del admin) no se toca', true, akibara_mp_nota_sin_correo_filtrar( true, null, null ) );
caso( 'correo sin nota cargada no se toca', true, akibara_mp_nota_sin_correo_filtrar( true, null, correo_con_nota( null ) ) );
caso( 'nota vacía no se toca', true, akibara_mp_nota_sin_correo_filtrar( true, null, correo_con_nota( '' ) ) );
caso( 'nota que no es string no se toca', true, akibara_mp_nota_sin_correo_filtrar( true, null, (object) array( 'customer_note' => 42 ) ) );

echo "\n$casos casos, $fallos fallos\n";
exit( 0 === $fallos ? 0 : 1 );
