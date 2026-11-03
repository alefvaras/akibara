<?php
/**
 * Versiona assets del tema que son encolados desde el plugin.
 *
 * Forms, BrandsDirectory y HomeHero consumen archivos de akibara-gp. La versión
 * debe pertenecer al tema que distribuye esos bytes, no a AKIBARA_BUSINESS_VERSION.
 * filemtime aislado no basta para paquetes reproducibles con mtimes congelados.
 * Se delega en akibara_gp_asset_ver() y se ofrece un equivalente local cuando
 * el tema activo no proporciona el helper.
 *
 * served_path() comparte la selección de la copia minificada con el tema:
 * .min salvo SCRIPT_DEBUG y fuente cuando esa copia no existe. La versión se
 * calcula después sobre el fichero servido mediante for_path(served_path(...)).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Resuelve la versión de cache-bust de un asset que pertenece al theme activo.
 */
final class ThemeAssetVersion {

	/**
	 * Versión para un archivo del theme, relativa al directorio del stylesheet.
	 *
	 * @param string $rel_path Ruta relativa al theme, con o sin `/` inicial
	 *                         (ej. 'assets/js/akibara-forms.js').
	 * @return string Versión lista para el 4º argumento de wp_enqueue_script/style.
	 */
	public static function for_path( string $rel_path ): string {
		$rel_path = ltrim( $rel_path, '/' );

		// Vía preferida: el contrato del propio theme (versión + filemtime).
		if ( function_exists( 'akibara_gp_asset_ver' ) ) {
			return (string) akibara_gp_asset_ver( $rel_path );
		}

		// Fallback: mismo contrato, resuelto acá. La versión del theme es lo que
		// garantiza el bust; el filemtime detrás solo aporta en entornos donde los
		// mtimes son reales (local), y es inerte en producción a propósito.
		$version = '';
		if ( function_exists( 'wp_get_theme' ) ) {
			$version = trim( (string) wp_get_theme()->get( 'Version' ) );
		}
		if ( '' === $version ) {
			$version = '1';
		}

		$absolute = get_stylesheet_directory() . '/' . $rel_path;
		$modified = is_file( $absolute ) ? filemtime( $absolute ) : false;

		return false !== $modified ? $version . '.' . $modified : $version;
	}

	/**
	 * Ruta (relativa al theme) del fichero que se sirve para una fuente del theme.
	 *
	 * Vía preferida: `akibara_gp_asset_servido()`, el contrato del propio theme.
	 * Sin él (otro theme, o un akibara-gp anterior a las copias `.min`) la misma
	 * regla se resuelve acá: con SCRIPT_DEBUG, la fuente; si no, la `.min` hermana
	 * cuando existe; y la fuente cuando falta, para que un despliegue a medias
	 * sirva una página más pesada y nunca una sin estilos ni guion.
	 *
	 * @param string $rel_path Fuente relativa al theme, con o sin `/` inicial
	 *                         (ej. 'assets/css/forms.css').
	 * @return string Ruta servida, sin `/` inicial.
	 */
	public static function served_path( string $rel_path ): string {
		$rel_path = ltrim( $rel_path, '/' );

		if ( function_exists( 'akibara_gp_asset_servido' ) ) {
			return ltrim( (string) akibara_gp_asset_servido( $rel_path ), '/' );
		}

		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			return $rel_path;
		}

		$min_path = (string) preg_replace( '/\.(css|js)$/', '.min.$1', $rel_path );

		return ( $min_path !== $rel_path && is_file( get_stylesheet_directory() . '/' . $min_path ) )
			? $min_path
			: $rel_path;
	}
}
