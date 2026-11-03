<?php
/**
 * Escrituras atómicas sobre una fila de `wp_options`, sin pasar por la caché.
 *
 * La API de options NO sirve para coordinar procesos:
 *
 * - `add_option()` hace `INSERT … ON DUPLICATE KEY UPDATE` (`wp-includes/option.php`):
 *   si la caché de objetos dice que la option no existe —y en producción hay caché
 *   persistente de LiteSpeed—, **pisa** el valor de otro proceso en vez de fallar.
 *   Devuelve `true` igual, así que quien la usa como candado cree que lo tomó.
 * - `get_option()` puede devolver el valor de hace un rato, que es justo lo que no
 *   se puede permitir al decidir si otro proceso sigue vivo.
 * - `update_option()` lee-compara-escribe sin transacción: dos procesos pasan la
 *   comprobación antes de que escriba el primero.
 *
 * Esta utilidad usa las tres formas que sí son atómicas en MariaDB, contra el
 * índice único de `option_name`, y limpia la caché de esa clave después de cada
 * escritura. `INSERT IGNORE` es el mismo patrón de `WP_Upgrader::create_lock()`.
 *
 * Las comparaciones van con `BINARY`: `wp_options` usa una intercalación que no
 * distingue mayúsculas ni acentos e ignora espacios finales, y un candado tiene que
 * coincidir byte a byte. El `UPDATE` no toca `autoload`: si lo hiciera, una fila
 * con el mismo valor y otro autoload contaría como «cambiada» sin haberlo sido.
 *
 * `INSERT IGNORE` también convierte en advertencia errores de modo estricto (un
 * nombre de más de 191 caracteres entraría truncado): los nombres que la usan son
 * constantes cortas.
 *
 * **Valores de PHP, no bytes:** cada método recibe el valor como lo daría
 * `get_option()` y lo serializa con `maybe_serialize()`. No se le pasa lo que
 * devolvió {@see read_raw()} para un array: WordPress vuelve a serializar una
 * cadena que ya parece serializada, así que la comparación nunca coincidiría (falla
 * segura: no escribe, pero tampoco toma el candado). Hoy los candados que la usan
 * guardan cadenas simples (`marca|token`), que viajan tal cual.
 *
 * **Filas cambiadas, no encontradas:** WordPress no activa `MYSQLI_CLIENT_FOUND_ROWS`,
 * así que un `UPDATE` que deja el mismo valor afecta 0 filas. Quien renueve un
 * candado tiene que escribir un valor distinto cada vez (p. ej. con un contador),
 * o dos renovaciones dentro del mismo segundo parecerán un robo.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

final class OptionRow {

	/**
	 * Inserta la fila sólo si no existe. Uno solo gana entre procesos simultáneos.
	 *
	 * @param string $option Nombre de la option.
	 * @param mixed  $value  Valor (se serializa como lo haría WordPress).
	 * @return bool true si la insertó ESTE proceso.
	 */
	public static function insert_if_absent( string $option, $value ): bool {
		global $wpdb;

		if ( ! self::usable( $wpdb ) ) {
			return false;
		}

		$insertado = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				$option,
				maybe_serialize( $value )
			)
		);
		if ( 1 === $insertado ) {
			self::forget( $option );
			self::unmark_missing( $option );
			return true;
		}

		return false;
	}

	/**
	 * Cambia el valor sólo si la fila sigue teniendo el que se leyó.
	 *
	 * @param string $option   Nombre de la option.
	 * @param mixed  $esperado Valor que se leyó.
	 * @param mixed  $nuevo    Valor a dejar.
	 * @return bool true si esta llamada hizo el cambio.
	 */
	public static function compare_and_swap( string $option, $esperado, $nuevo ): bool {
		global $wpdb;

		if ( ! self::usable( $wpdb ) ) {
			return false;
		}

		$cambiado = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s",
				maybe_serialize( $nuevo ),
				$option,
				maybe_serialize( $esperado )
			)
		);
		self::forget( $option );

		return 1 === $cambiado;
	}

	/**
	 * Borra la fila sólo si sigue teniendo ese valor (el candado propio).
	 *
	 * @param string $option Nombre de la option.
	 * @param mixed  $valor  Valor que debe tener.
	 */
	public static function delete_if_equals( string $option, $valor ): bool {
		global $wpdb;

		if ( ! self::usable( $wpdb ) ) {
			return false;
		}

		$borrado = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s",
				$option,
				maybe_serialize( $valor )
			)
		);
		self::forget( $option );

		return 1 === $borrado;
	}

	/**
	 * El valor de la fila leído de la base, no de la caché.
	 *
	 * @param string $option Nombre de la option.
	 * @return string|null Bytes guardados, o null si no hay fila.
	 */
	public static function read_raw( string $option ): ?string {
		global $wpdb;

		if ( ! self::usable( $wpdb ) ) {
			return null;
		}

		$valor = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
		);

		return null === $valor ? null : (string) $valor;
	}

	/**
	 * Tras escribir por SQL, la caché de esa clave miente: se retira.
	 *
	 * Sólo esa clave; `alloptions` no se toca: estas filas se crean con
	 * `autoload = 'off'` y no viajan en ese blob, y recomponerlo cuesta en TODA
	 * petición del sitio.
	 *
	 * @param string $option Nombre de la option.
	 */
	private static function forget( string $option ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option, 'options' );
		}
	}

	/**
	 * Tras crear la fila, la clave deja de figurar como inexistente en `notoptions`.
	 *
	 * Es lo que hace `add_option()` de core. Sin esto, quien lea la option con
	 * `get_option()` —el manifiesto `llms.txt` lee así su lease— recibe «no existe»
	 * de la caché aunque la fila esté escrita: tras desactivar y reactivar el plugin
	 * (que borra la option y la marca en `notoptions`), el lease quedaba sin poder
	 * soltarse ni volver a tomarse y el manifiesto se congelaba. Sólo escribe en la caché si la clave estaba marcada.
	 *
	 * @param string $option Nombre de la option.
	 */
	private static function unmark_missing( string $option ): void {
		if ( ! function_exists( 'wp_cache_get' ) || ! function_exists( 'wp_cache_set' ) ) {
			return;
		}
		$ausentes = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $ausentes ) && isset( $ausentes[ $option ] ) ) {
			unset( $ausentes[ $option ] );
			wp_cache_set( 'notoptions', $ausentes, 'options' );
		}
	}

	/**
	 * @param mixed $wpdb El global, que en algunas pruebas y en WP-CLI temprano no existe.
	 */
	private static function usable( $wpdb ): bool {
		return is_object( $wpdb )
			&& isset( $wpdb->options )
			&& method_exists( $wpdb, 'prepare' )
			&& method_exists( $wpdb, 'query' )
			&& method_exists( $wpdb, 'get_var' );
	}
}
