<?php
/**
 * Plugin Name: Akibara — La nota «Mercado Pago: Pago aprobado.» no sale por correo al cliente
 * Description: Suprime únicamente el correo de nota al cliente cuyo texto corresponde a Mercado Pago. La nota sigue visible en Mi cuenta y las notas del dueño conservan su envío. Usa woocommerce_email_enabled_customer_note y permanece inerte sin Mercado Pago.
 * Version: 1.0.0
 * Author: Akibara
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

add_action( 'plugins_loaded', 'akibara_mp_nota_sin_correo_enganchar', 20 );

/**
 * Engancha el filtro sólo si Mercado Pago está cargado.
 */
function akibara_mp_nota_sin_correo_enganchar(): void {
	if ( ! defined( 'MP_PLUGIN_FILE' ) ) {
		return;
	}

	add_filter( 'woocommerce_email_enabled_customer_note', 'akibara_mp_nota_sin_correo_filtrar', 10, 3 );
}

/**
 * ¿La nota la escribió el plugin de Mercado Pago?
 *
 * En el flujo que corre en Chile (`src/Order/OrderStatus.php`, tarjeta y
 * transferencia) el plugin arma la nota como `'Mercado Pago: ' . $traduccion`,
 * con el prefijo FUERA de lo traducible. En el flujo Pix (Brasil,
 * `src/Gateways/PixGateway.php`) la nota es HTML y el prefijo va DENTRO de la
 * cadena traducida («Mercado Pago: Now you just need to pay with Pix…»): por
 * eso se quitan las etiquetas antes de mirar el prefijo, y el candado ejerce
 * los dos casos. Si un día el plugin dejara de prefijar, esa nota volvería a
 * salir por correo: el fallo es hacia lo que ya pasaba, no hacia callar al
 * dueño.
 *
 * @param mixed $nota Texto de la nota (puede traer HTML).
 */
function akibara_mp_nota_es_de_mercado_pago( $nota ): bool {
	if ( ! is_string( $nota ) ) {
		return false;
	}
	$texto = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $nota ) : strip_tags( $nota );

	return 0 === strpos( ltrim( $texto ), 'Mercado Pago: ' );
}

/**
 * Callback de `woocommerce_email_enabled_customer_note`.
 *
 * @param mixed $enabled Si el correo está habilitado.
 * @param mixed $order   Pedido (no se usa: la nota viaja en el correo).
 * @param mixed $email   Instancia de WC_Email_Customer_Note (o null desde el listado de correos del admin).
 * @return mixed
 */
function akibara_mp_nota_sin_correo_filtrar( $enabled, $order, $email ) {
	unset( $order );

	if ( ! $enabled || ! is_object( $email ) || ! isset( $email->customer_note ) ) {
		return $enabled;
	}

	return akibara_mp_nota_es_de_mercado_pago( $email->customer_note ) ? false : $enabled;
}
