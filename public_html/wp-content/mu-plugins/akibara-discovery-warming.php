<?php
/**
 * Plugin Name: Akibara Discovery Cache Policy
 * Description: Configura los headers de los sitemaps: el navegador no guarda XML y los CDN compartidos pueden conservarlo cuatro horas. La disponibilidad de bots y el rechazo de caché de errores se configuran en Cloudflare; este archivo no programa warming periódico.
 * Version: 1.6.0
 * @package Akibara
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

add_filter(
	'rank_math/sitemap/http_headers',
	static function ( $headers ) {
		if ( is_array( $headers ) ) {
			// El navegador no conserva XML. Los CDN compartidos pueden servir una
			// copia fresca por 4 h. La Cache Rule de Cloudflare usa el mismo TTL y
			// declara 4xx/5xx como no-store; esa configuración se valida fuera de
			// WordPress.
			$shared_cache = 'public, max-age=14400';

			$headers['Cache-Control']                = 'no-store, must-revalidate, max-age=0';
			$headers['Cloudflare-CDN-Cache-Control'] = $shared_cache;
			$headers['CDN-Cache-Control']            = $shared_cache;
		}

		return $headers;
	}
);
