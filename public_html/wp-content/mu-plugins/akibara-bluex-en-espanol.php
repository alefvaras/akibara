<?php
/**
 * Plugin Name: Akibara — Blue Express en español
 * Description: Traduce las cadenas de seguimiento de Blue Express visibles para el cliente mediante filtros de traducción. El número de seguimiento enlaza a la página pública de Blue con el parámetro n_seguimiento.
 * Version: 1.1.0
 * Author: Akibara
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Text domain del plugin de Blue Express.
 *
 * Es `woocommerce-correios` y no «bluex»: el plugin es un derivado del de
 * Correios de Brasil, y conserva el dominio original. Filtrar por él es lo que
 * mantiene acotado el efecto de este mu-plugin.
 */
const AKIBARA_BLUEX_TEXT_DOMAIN = 'woocommerce-correios';

/**
 * Lo que ve el CLIENTE, en español.
 *
 * Sólo las cadenas de las dos superficies públicas —el correo de seguimiento y
 * el bloque de Mi cuenta—, más la descripción del correo en el panel, que es lo
 * que el dueño lee para saber qué está encendido. Las llaves son los originales
 * exactos del plugin (`includes/emails/class-wc-correios-tracking-email.php`,
 * `templates/emails/`, `templates/myaccount/`): si el tercero cambia una, la
 * suya vuelve a salir en inglés en vez de romperse nada.
 *
 * Los marcadores `{site_title}`, `{order_number}`, `{tracking_code}` y `%s` se
 * conservan tal cual: los sustituye el propio plugin después de traducir.
 *
 * Las frases del correo son LAS MISMAS que ya estaban escritas a mano en los
 * ajustes del espejo (`woocommerce_correios_tracking_settings`) y que nunca se
 * llevaron a producción. Así los dos entornos dicen lo mismo, y si el dueño
 * edita ese correo desde WooCommerce → Ajustes → Correos, su texto manda sobre
 * esto (los ajustes guardados no pasan por el filtro de traducción).
 *
 * @return array<string, string>
 */
function akibara_bluex_traducciones(): array {
	return array(
		// Correo de seguimiento (asunto, encabezado y cuerpo).
		'Your order has been sent'                                                                  => 'Tu pedido ha sido despachado',
		'[{site_title}] Your order {order_number} has been sent by BlueX'                           => '[{site_title}] Tu pedido {order_number} ha sido despachado por Blue Express',
		'Hi there. Your recent order on {site_title} has been sent by BlueX.'                       => '¡Hola! Tu pedido en {site_title} ha sido despachado por Blue Express.',
		'To track your delivery, use the following the tracking code(s): {tracking_code}'           => 'Para rastrear tu envío, utiliza el siguiente número de seguimiento: {tracking_code}',
		'The delivery service is the responsibility of the BlueX, but if you have any questions, please contact us.' => 'El servicio de entrega es responsabilidad de Blue Express, pero si tienes alguna consulta no dudes en contactarnos.',
		'For your reference, your order details are shown below.'                                   => 'Aquí abajo tienes el detalle de tu pedido.',
		'This email is sent when configured a tracking code within an order.'                       => 'Se envía al cliente cuando se agrega un código de seguimiento al pedido.',

		// Bloque de seguimiento en Mi cuenta → detalle del pedido.
		'BlueX delivery tracking'                                                                   => 'Seguimiento de tu envío',
		'Query on BlueX'                                                                            => 'Ver el estado en Blue Express',
		'History for the tracking code:'                                                            => 'Historial del código de seguimiento:',
		'Date'                                                                                      => 'Fecha',
		'Location'                                                                                  => 'Lugar',
		'Status'                                                                                    => 'Estado',
		'In transit to %s'                                                                          => 'En tránsito a %s',
	);
}

/**
 * Plurales del bloque de Mi cuenta.
 *
 * `_n( 'Tracking code:', 'Tracking codes:', $n )`: un pedido puede viajar en
 * varios bultos y entonces hay más de un código.
 *
 * @return array<string, array{0:string,1:string}>
 */
function akibara_bluex_plurales(): array {
	return array(
		'Tracking code:' => array( 'Código de seguimiento:', 'Códigos de seguimiento:' ),
	);
}

/**
 * Página pública de seguimiento de Blue Express. El parámetro n_seguimiento
 * precarga el número y ejecuta la búsqueda.
 */
const AKIBARA_BLUEX_SEGUIMIENTO_URL = 'https://www.blue.cl/enviar/seguimiento';

/**
 * En el correo, el número de seguimiento lleva a Blue Express.
 *
 * El plugin lo enlaza a la página del pedido en Mi cuenta, que exige sesión
 * iniciada y sólo repite el número. Quien abre ese correo quiere una cosa: ver
 * dónde va su paquete. El enlace de Mi cuenta sigue existiendo en el propio
 * correo (el botón «Ver el pedido» de la plantilla de WooCommerce).
 *
 * @param string $html          Enlace que arma el plugin.
 * @param string $tracking_code Número de seguimiento.
 * @return string
 */
function akibara_bluex_enlace_del_correo( $html, $tracking_code ) {
	$codigo = trim( (string) $tracking_code );
	if ( '' === $codigo ) {
		return $html;
	}

	return sprintf(
		'<a href="%s" target="_blank" rel="nofollow noopener noreferrer">%s</a>',
		esc_url( add_query_arg( 'n_seguimiento', $codigo, AKIBARA_BLUEX_SEGUIMIENTO_URL ) ),
		esc_html( $codigo )
	);
}
add_filter( 'woocommerce_correios_email_tracking_core_url', 'akibara_bluex_enlace_del_correo', 10, 2 );

/**
 * Traduce una cadena simple del plugin de Blue Express.
 *
 * @param string $traduccion Traducción actual (el original, si no hay catálogo).
 * @param string $texto      Original en inglés.
 * @param string $dominio    Text domain.
 * @return string
 */
function akibara_bluex_gettext( $traduccion, $texto, $dominio ) {
	if ( AKIBARA_BLUEX_TEXT_DOMAIN !== $dominio ) {
		return $traduccion;
	}

	$mapa = akibara_bluex_traducciones();

	return $mapa[ $texto ] ?? $traduccion;
}
add_filter( 'gettext', 'akibara_bluex_gettext', 10, 3 );

/**
 * Traduce los plurales del plugin de Blue Express.
 *
 * @param string $traduccion Traducción actual.
 * @param string $singular   Original singular.
 * @param string $plural     Original plural.
 * @param int    $numero     Cantidad.
 * @param string $dominio    Text domain.
 * @return string
 */
function akibara_bluex_ngettext( $traduccion, $singular, $plural, $numero, $dominio ) {
	unset( $plural );

	if ( AKIBARA_BLUEX_TEXT_DOMAIN !== $dominio ) {
		return $traduccion;
	}

	$mapa = akibara_bluex_plurales();
	if ( ! isset( $mapa[ $singular ] ) ) {
		return $traduccion;
	}

	return 1 === (int) $numero ? $mapa[ $singular ][0] : $mapa[ $singular ][1];
}
add_filter( 'ngettext', 'akibara_bluex_ngettext', 10, 5 );
