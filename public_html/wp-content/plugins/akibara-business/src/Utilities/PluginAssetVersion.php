<?php
/**
 * PluginAssetVersion — cache-bust para los assets PROPIOS de akibara-business.
 *
 * Es el hermano de `ThemeAssetVersion`, que ya resolvía el caso de los archivos
 * del THEME que este plugin encola. Faltaba el caso mayoritario —los assets del
 * propio plugin— y por eso el cálculo estaba copiado a mano 23 veces en 13
 * archivos, siempre igual:
 *
 *     $path = AKIBARA_BUSINESS_DIR. 'assets/css/x.css';
 *     $ver  = file_exists( $path)
 *         ? AKIBARA_BUSINESS_VERSION. '.'. filemtime( $path)
 *: AKIBARA_BUSINESS_VERSION;
 *
 * Y otros 19 encolados pasaban `AKIBARA_BUSINESS_VERSION` PELADA, sin el
 * `filemtime` detrás. Ocho de esos eran de FRONT, y `.htaccess` los cachea un
 * año: editar uno de esos archivos sin bumpear la versión del plugin dejaba al
 * visitante recurrente con la copia vieja durante todo ese año.
 *
 * ── Por qué la versión va DELANTE y el mtime DETRÁS ──────────────────────────
 * El empaquetador (`tools/akibara-releases/package-runtime.sh`) congela todos
 * los mtimes a 1980-01-01 para que el ZIP sea reproducible byte a byte, así que
 * en producción `filemtime()` es una CONSTANTE: un `?ver=<filemtime>` pelado no
 * invalida nada, nunca, en silencio. Anteponer `AKIBARA_BUSINESS_VERSION` ata la
 * invalidación al release, que sí cambia con cada despliegue. El `filemtime`
 * detrás se conserva porque en local los mtimes son reales y le ahorra a quien
 * edita tener que bumpear la versión para ver su cambio.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Resuelve la versión de cache-bust de un asset del propio plugin.
 */
final class PluginAssetVersion {

	/**
	 * Versión para un archivo del plugin, relativa a su raíz.
	 *
	 * @param string $rel_path Ruta relativa al plugin, con o sin `/` inicial
	 *                         (ej. 'assets/js/native-cart.js').
	 * @return string Versión lista para el 4º argumento de wp_enqueue_script/style.
	 */
	public static function for_path( string $rel_path ): string {
		$absolute = AKIBARA_BUSINESS_DIR . ltrim( $rel_path, '/' );
		$modified = is_file( $absolute ) ? filemtime( $absolute ) : false;

		return false !== $modified
			? AKIBARA_BUSINESS_VERSION . '.' . $modified
			: AKIBARA_BUSINESS_VERSION;
	}
}
