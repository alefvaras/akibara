<?php
/**
 * Plugin Name: Akibara — WooCommerce sólo trata como API las rutas que empiezan por /wp-json/
 * Description: Confirma la clasificación REST de WooCommerce sólo para rutas que empiezan por el prefijo REST de WordPress o incluyen rest_route. Las demás rutas conservan sesión y carrito al renderizar el tema, incluso si contienen wp-json en otro segmento.
 * Version: 1.0.0
 * Author: Akibara
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * ¿La ruta pedida es de la API REST del sitio? Sólo si empieza por el prefijo
 * (con o sin `index.php/` delante, por si los enlaces permanentes lo llevan).
 *
 * @param string $request_uri REQUEST_URI tal cual llegó.
 * @param string $home_path   Ruta del sitio dentro del dominio (vacía en la raíz).
 * @param string $prefix      Prefijo REST sin barras («wp-json»).
 * @return bool
 */
function akibara_woo_rest_ruta_es_de_la_api( string $request_uri, string $home_path, string $prefix ): bool {
	$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
	$home = rtrim( $home_path, '/' );
	$api  = trim( $prefix, '/' );

	foreach ( array( $home . '/' . $api, $home . '/index.php/' . $api ) as $base ) {
		if ( $path === $base || 0 === strpos( $path, $base . '/' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Confirma o desmiente la marca de WooCommerce.
 *
 * @param bool $is_rest Lo que decidió WooCommerce.
 * @return bool
 */
function akibara_woo_rest_solo_en_la_raiz( $is_rest ): bool {
	if ( ! $is_rest ) {
		return false;
	}
	// Misma lectura que hace WooCommerce: sólo se mira si viene.
	if ( ! empty( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- decide cómo se carga la petición, no procesa un formulario.
		return true;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';

	return akibara_woo_rest_ruta_es_de_la_api(
		$request_uri,
		(string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ),
		rest_get_url_prefix()
	);
}

add_filter( 'woocommerce_is_rest_api_request', 'akibara_woo_rest_solo_en_la_raiz' );
