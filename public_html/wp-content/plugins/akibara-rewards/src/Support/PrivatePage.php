<?php
/**
 * PrivatePage — una página con el estado de UNA persona no se guarda en caché.
 *
 * Las dos páginas por enlace del plugin (el formulario de reseña y las
 * preferencias de correo) se defendían sólo con `nocache_headers()`, que son
 * cabeceras del origen y en esta tienda no deciden nada:
 *
 *   - LiteSpeed resuelve con sus propios ajustes y sólo se rinde ante
 *     `DONOTCACHEPAGE` o su hook `litespeed_control_set_nocache`.
 *   - Las reglas de caché HTML para invitados en CDN pueden ignorar `no-store`
 *     en rutas de contenido general.
 *
 * Para asegurar no-cache estricto, la defensa se basa en la ruta: estas páginas
 * se sirven bajo la URL canónica de Mi cuenta (`/mi-cuenta/`), que está excluida
 * permanentemente de la caché perimetral (DYNAMIC).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.9
 */
final class PrivatePage {

	/**
	 * URL base bajo la que el borde no cachea: la página de Mi cuenta.
	 *
	 * @return string Con barra final.
	 */
	public static function base_url(): string {
		$cuenta = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';

		return '' !== $cuenta ? $cuenta : home_url( '/mi-cuenta/' );
	}

	/**
	 * Apaga toda caché de página para la petición en curso.
	 *
	 * @param string $motivo Texto que LiteSpeed anota en su cabecera de depuración.
	 * @return void
	 */
	public static function no_cache( string $motivo ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante estándar de facto de los plugins de caché: prefijarla la dejaría inerte.
		}

		nocache_headers();

		do_action( 'litespeed_control_set_nocache', $motivo ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed Cache: el nombre lo fija ese plugin.
	}
}
