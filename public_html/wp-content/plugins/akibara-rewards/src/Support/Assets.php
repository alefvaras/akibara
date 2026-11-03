<?php
/**
 * Assets — el `?ver=` de los assets del plugin, con un solo criterio.
 *
 * El token combina versión y filemtime para invalidar assets modificados.
 * Si el fichero no existe, conserva la versión sin consultar su mtime.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.1
 */
final class Assets {

	/**
	 * URL pública de un asset del plugin.
	 *
	 * @param string $relative Ruta relativa a la raíz del plugin.
	 * @return string
	 */
	public static function url( string $relative ): string {
		return AKIBARA_REWARDS_URL . $relative;
	}

	/**
	 * Token de caché: versión del plugin + mtime del fichero.
	 *
	 * @param string $relative Ruta relativa a la raíz del plugin.
	 * @return string
	 */
	public static function version( string $relative ): string {
		$path  = AKIBARA_REWARDS_DIR . $relative;
		$mtime = file_exists( $path ) ? (int) filemtime( $path ) : 0;

		return AKIBARA_REWARDS_VERSION . ( $mtime > 0 ? '.' . $mtime : '' );
	}
}
