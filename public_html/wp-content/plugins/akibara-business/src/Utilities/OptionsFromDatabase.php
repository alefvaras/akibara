<?php
/**
 * Lee un lote de opciones directamente de wp_options.
 *
 * Las tarjetas que confirman un guardado necesitan observar las filas escritas,
 * sin mezclar un blob persistente de alloptions de otra petición con el estado
 * nuevo. La lectura agrupada comparte una fuente para TaxonomyDiscount y
 * SeriesBundles y usa una consulta por lote, no una consulta por clave.
 * No sustituye las estrategias de caché del frontend.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

final class OptionsFromDatabase {

	/**
	 * @param array<int, string> $option_names Nombres de option a leer. Vacío devuelve vacío sin consultar.
	 * @return array<string, string>|null `option_name => option_value` crudo,
	 *         SÓLO de las filas que existen (ausente = sin fila, igual que en la
	 *         base); `null` si `$wpdb` no está disponible, para que el llamador
	 *         caiga a `get_option()` en vez de fingir que la base está vacía.
	 */
	public static function read( array $option_names ): ?array {
		if ( array() === $option_names ) {
			return array();
		}

		global $wpdb;
		if ( ! self::usable( $wpdb ) ) {
			return null;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $option_names ), '%s' ) );
		$filas        = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- el nombre de tabla sale del propio $wpdb; la lista de placeholders se genera acá y los VALORES sí van preparados.
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ( {$placeholders} )",
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...$option_names
			),
			ARRAY_A
		);

		if ( ! is_array( $filas ) ) {
			return null;
		}

		$leidas = array();
		foreach ( $filas as $fila ) {
			if ( ! is_array( $fila ) || ! isset( $fila['option_name'], $fila['option_value'] ) ) {
				continue;
			}
			$leidas[ (string) $fila['option_name'] ] = (string) $fila['option_value'];
		}

		return $leidas;
	}

	/**
	 * @param mixed $wpdb El global, ausente en algunas pruebas y en WP-CLI temprano.
	 */
	private static function usable( $wpdb ): bool {
		return is_object( $wpdb )
			&& isset( $wpdb->options )
			&& method_exists( $wpdb, 'prepare' )
			&& method_exists( $wpdb, 'get_results' );
	}
}
