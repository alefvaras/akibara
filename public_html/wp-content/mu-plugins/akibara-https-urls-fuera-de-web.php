<?php
/**
 * Plugin Name: Akibara — URLs https también desde cron y WP-CLI
 * Description: Fuerza HTTPS para URLs de esquema implícito si la opción home usa HTTPS y la petición no tiene SSL, como cron y WP-CLI. Conserva los esquemas solicitados explícitamente por el llamador.
 * Version: 1.0.0
 * Author: Akibara
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

add_filter(
	'set_url_scheme',
	/**
	 * @param string      $url         URL ya procesada por WordPress.
	 * @param string      $scheme      Esquema aplicado ('http' o 'https').
	 * @param string|null $orig_scheme Esquema pedido por el llamador; null = «el que toque».
	 */
	static function ( $url, $scheme, $orig_scheme ) {
		if ( null !== $orig_scheme || 'http' !== $scheme || is_ssl() ) {
			return $url;
		}
		static $site_is_https = null;
		if ( null === $site_is_https ) {
			$site_is_https = 0 === strpos( (string) get_option( 'home' ), 'https://' );
		}
		if ( ! $site_is_https || ! is_string( $url ) || 0 !== strpos( $url, 'http://' ) ) {
			return $url;
		}

		return 'https://' . substr( $url, 7 );
	},
	10,
	3
);
