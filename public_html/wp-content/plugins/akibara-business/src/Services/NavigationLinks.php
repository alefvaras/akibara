<?php
/**
 * NavigationLinks — resuelve URLs canónicas de páginas clave del sitio
 * (Mi cuenta, Contacto, Editoriales, etc.) a partir de:
 *   1. Settings/options del plugin que las posea (WooCommerce).
 *   2. Lookup por slug como fallback.
 *   3. Hardcoded path como último recurso.
 *
 * Existe para que el theme no haga lookups, queries ni dependa de slugs
 * cableados. Theme consume vía `\Akibara\Business\Services\NavigationLinks`,
 * sin lógica condicional propia.
 *
 * Filterable: cada método pasa por un filter `akibara_navigation_url_<key>`
 * que permite override programático sin tocar código del consumidor.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

use WP_Post;

defined( 'ABSPATH' ) || exit;

final class NavigationLinks {

	/**
	 * Slug de la home editable, hermana de la front page cuando el sitio sirve
	 * una portada estática. Es el mismo par que resuelve el theme en
	 * `akibara_gp_is_home_surface()`; acá vive en el plugin para que ninguna
	 * superficie tenga que cablear el ID de `wp_posts` de UN entorno.
	 */
	private const HOME_ALT_SLUG = 'home-1';

	/**
	 * ¿La petición actual pinta una superficie «home»?
	 *
	 * Existe porque el guard de Joinchat comparaba contra `is_page( 210)`: un
	 * autoincremento de `wp_posts` que además ES la front page, así que la rama
	 * no podía activarse nunca y una migración la habría apuntado a otro
	 * contenido en silencio.
	 */
	public static function is_home_surface(): bool {
		return is_front_page() || is_page( self::HOME_ALT_SLUG );
	}

	/**
	 * Construye la URL de acceso con retorno a la superficie de compra.
	 *
	 * WooCommerce traslada redirect_to al campo redirect del formulario. El destino
	 * sólo puede pertenecer al sitio y no estar bajo wp-admin, incluidos sus endpoints
	 * AJAX. Esto evita saltos abiertos y retornos a una ruta administrativa en lugar
	 * de la ficha o landing que originó el acceso.
	 *
	 * @param string $destino URL absoluta del sitio a la que volver.
	 */
	public static function account_url_returning_to( string $destino ): string {
		$cuenta  = self::account_url();
		$destino = self::safe_internal_destination( $destino );

		if ( '' === $destino || ! function_exists( 'add_query_arg' ) ) {
			return $cuenta;
		}

		return (string) add_query_arg( 'redirect_to', rawurlencode( $destino ), $cuenta );
	}

	/**
	 * El destino tal cual si es una página pública de este sitio; '' si no.
	 *
	 * Es la MISMA regla en los dos extremos del viaje: la que decide si se
	 * añade `redirect_to` al enlace y la que decide si el muro de acceso puede
	 * confiar en el `redirect_to` que le llega de vuelta. Vivía sólo en el
	 * extremo de ida, así que el de vuelta habría tenido que copiarla —y una
	 * copia es un sitio más donde arreglar el mismo agujero.
	 *
	 * @param string $destino URL candidata.
	 */
	public static function safe_internal_destination( string $destino ): string {
		$destino = trim( $destino );
		if ( '' === $destino || ! function_exists( 'home_url' ) ) {
			return '';
		}

		/*
             * Un carácter de control invalida el destino completo.
             * No se recorta: esc_url() podría reescribirlo y emitir una ruta distinta
             * de la validada. La comprobación y la URL recibida por el cliente deben
             * representar exactamente el mismo destino.
             */
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $destino ) ) {
			return '';
		}

		$inicio = (string) home_url( '/' );
		if ( 0 !== strpos( $destino, $inicio ) ) {
			return '';
		}

		$ruta = (string) wp_parse_url( $destino, PHP_URL_PATH );

		/*
		 * Se decide sobre la ruta DECODIFICADA, no sobre la cruda.
		 *
		 * `stripos( $ruta, '/wp-admin/')` sobre el texto tal cual dejaba pasar
		 * `/wp%2Dadmin/plugins.php`, que el servidor resuelve exactamente a
		 * `/wp-admin/plugins.php` — comprobado: las dos URLs devuelven el mismo
		 * 302 a `wp-login.php`. Una guarda que se salta codificando un guion no
		 * es una guarda.
		 *
		 * Se decodifica en bucle porque una sola pasada dejaría pasar `%252D`.
		 */
		$decodificada = self::fully_decoded( $ruta );
		$admin        = function_exists( 'admin_url' )
			? (string) wp_parse_url( (string) admin_url(), PHP_URL_PATH )
			: '/wp-admin/';
		$admin = '' === $admin ? '/wp-admin/' : trailingslashit( $admin );

		if ( '' !== $decodificada && false !== stripos( $decodificada, $admin ) ) {
			return '';
		}

		/*
		 * `wp-login.php` es de la misma familia que `/wp-admin/`: no es un sitio
		 * al que devolver a un comprador. Y `?action=logout` sería el peor final
		 * posible para el viaje — el cliente entra y lo primero que hace la
		 * tienda es echarlo.
		 */
		if ( '' !== $decodificada && false !== stripos( $decodificada, 'wp-login.php' ) ) {
			return '';
		}

		return $destino;
	}

	/**
	 * Decodifica hasta que la cadena deja de cambiar, con un tope.
	 *
	 * Una sola pasada de `rawurldecode()` deja pasar `%252D`, que el navegador
	 * y el servidor sí acaban resolviendo. El tope evita quedarse dando vueltas
	 * con una entrada construida a propósito.
	 *
	 * @param string $valor Cadena posiblemente codificada.
	 * @return string
	 */
	private static function fully_decoded( string $valor ): string {
		for ( $i = 0; $i < 5; $i++ ) {
			$siguiente = rawurldecode( $valor );
			if ( $siguiente === $valor ) {
				return $valor;
			}
			$valor = $siguiente;
		}

		return $valor;
	}

	/**
	 * URL de «Mi cuenta» que devuelve al cliente a la página que está viendo.
	 *
	 * Resuelve el destino aquí porque las dos superficies de SeriesBundles
	 * (la landing de serie y el builder de la ficha) tenían cada una su copia
	 * verbatim de este helper, y ambas alimentaban a
	 * {@see account_url_returning_to()}. Dos copias del mismo cálculo son dos
	 * sitios donde arreglar el mismo fallo, y de hecho una de ellas se
	 * ejecutaba también dentro de un handler AJAX, donde `REQUEST_URI` es
	 * `/wp-admin/admin-ajax.php` y no la página del cliente.
	 *
	 * Se descarta la cadena de consulta a propósito: puede traer facetas,
	 * `utm_*` o el `redirect_to` de un rebote anterior, y ninguno aporta al
	 * volver.
	 */
	public static function account_url_returning_here(): string {
		return self::account_url_returning_to( self::current_url() );
	}

	/** Ruta absoluta y limpia de la petición en curso, o '' si no se puede leer. */
	private static function current_url(): string {
		if ( ! function_exists( 'home_url' ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$uri  = wp_unslash( (string) $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se toma sólo el componente de ruta.
		$ruta = (string) wp_parse_url( $uri, PHP_URL_PATH );

		return '' === $ruta ? '' : (string) home_url( $ruta );
	}

	/**
	 * URL de "Mi cuenta" (WooCommerce myaccount endpoint).
	 *
	 * Resolución:
	 *   1. wc_get_page_permalink('myaccount') si WooCommerce está cargado.
	 *   2. Lookup por slug 'mi-cuenta'.
	 *   3. Fallback home_url('/mi-cuenta/').
	 */
	public static function account_url(): string {
		$url = '';

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$url = (string) wc_get_page_permalink( 'myaccount' );
		}

		if ( '' === $url ) {
			$url = self::permalink_by_slug( 'mi-cuenta', '/mi-cuenta/' );
		}

		return self::filter( 'account', $url );
	}

	/**
	 * URL de la página de Contacto.
	 *
	 * Resolución:
	 *   1. Lookup por slug 'contacto'.
	 *   2. Fallback home_url('/contacto/').
	 */
	public static function contact_url(): string {
		$url = self::permalink_by_slug( 'contacto', '/contacto/' );
		return self::filter( 'contact', $url );
	}

	/**
	 * URL de la página de Favoritos.
	 *
	 * Apunta al endpoint propio `/mi-cuenta/favoritos/` del módulo Wishlist
	 * akibara (login required). El plugin externo WPC Smart Wishlist fue
	 * reemplazado en la migración a módulo propio.
	 */
	public static function wishlist_url(): string {
		$url = class_exists( '\\Akibara\\Business\\Modules\\Wishlist\\Endpoint' )
			? \Akibara\Business\Modules\Wishlist\Endpoint::url()
			: trailingslashit( self::account_url() . 'favoritos' );

		return self::filter( 'wishlist', $url );
	}

	/**
	 * URL del Carrito (WooCommerce cart endpoint).
	 *
	 * Resolución:
	 *   1. wc_get_page_permalink('cart') si WooCommerce está cargado.
	 *   2. Lookup por slug 'carrito'.
	 *   3. Fallback home_url('/carrito/').
	 */
	public static function cart_url(): string {
		$url = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'cart' ) : '';
		if ( '' === $url ) {
			$url = self::permalink_by_slug( 'carrito', '/carrito/' );
		}
		return self::filter( 'cart', $url );
	}

	/**
	 * URL del shop principal (WooCommerce shop endpoint).
	 *
	 * Resolución:
	 *   1. wc_get_page_permalink('shop') si WooCommerce está cargado.
	 *   2. Lookup por slug 'tienda'.
	 *   3. Fallback home_url('/tienda/').
	 */
	public static function shop_url(): string {
		$url = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';
		if ( '' === $url ) {
			$url = self::permalink_by_slug( 'tienda', '/tienda/' );
		}
		return self::filter( 'shop', $url );
	}

	/**
	 * URL del home del sitio. Trivial pero expuesto acá para uniformidad
	 * de consumo desde templates (theme nunca llama a `home_url()` directo).
	 */
	public static function home_url(): string {
		return self::filter( 'home', (string) \home_url( '/' ) );
	}

	/**
	 * Lookup por slug con fallback a home_url + path hardcoded.
	 */
	private static function permalink_by_slug( string $slug, string $fallback_path ): string {
		$page = get_page_by_path( $slug );
		if ( $page instanceof WP_Post ) {
			return (string) get_permalink( $page );
		}
		return home_url( $fallback_path );
	}

	/**
	 * Aplica filter `akibara_navigation_url_<key>` para overrides externos.
	 */
	private static function filter( string $key, string $url ): string {
		/**
		 * Filtra una URL resuelta de NavigationLinks.
		 *
		 * @param string $url URL resuelta.
		 * @param string $key Identificador (account|contact|editoriales|...).
		 */
		return (string) apply_filters( 'akibara_navigation_url_' . $key, $url, $key );
	}
}
