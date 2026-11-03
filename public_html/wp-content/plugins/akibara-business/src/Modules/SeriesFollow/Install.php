<?php
/**
 * Define la cola durable de novedades de serie.
 *
 * La activación y el guard de akb_sf_db_version aplican dbDelta también tras
 * un despliegue sin reactivación. La versión sólo se sella al verificar la tabla.
 * La misma fila conserva evento y marca de notificado: uniq_event por usuario
 * y producto evita una segunda alta mediante restricción de base de datos.
 *
 * No se purgan filas terminales sent, dropped, expired, failed o coalesced:
 * liberar ese UNIQUE reabriría avisos ya tratados. No equivale a una lista de
 * espera donde la misma persona puede volver a suscribirse al mismo producto.
 * volume SMALLINT UNSIGNED cubre los cuatro dígitos de VolumeTitle; event_key
 * VARCHAR(64) cubre los identificadores de usuario/producto en utf8mb4.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

use Akibara\Business\Utilities\Log;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 */
final class Install {

	public const DB_VERSION = '1.0.0';
	public const OPTION_KEY = 'akb_sf_db_version';

	/**
	 * Nombre completo de la tabla (con prefix del sitio).
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_series_follow_queue';
	}

	/**
	 * Crea o migra la tabla si la versión guardada difiere. Idempotente.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( get_option( self::OPTION_KEY ) === self::DB_VERSION ) {
			return;
		}

		self::install();

		if ( ! self::schema_ready() ) {
			global $wpdb;
			$message = sprintf(
				'Migración SeriesFollow %s falló: la tabla %s no existe tras dbDelta; no se sella %s. last_error=%s',
				self::DB_VERSION,
				self::table_name(),
				self::OPTION_KEY,
				(string) $wpdb->last_error
			);

			Log::warn( 'series-follow', $message );
			if ( ! function_exists( 'wc_get_logger' ) ) {
				// Sin WooCommerce cargada el canal de la casa no existe y este
				// fallo NO puede quedar mudo.
				error_log( '[akibara-business] ' . $message );
			}

			return;
		}

		update_option( self::OPTION_KEY, self::DB_VERSION, false );
	}

	/**
	 * ¿La tabla existe realmente? Es `public` porque el activation hook de
	 * akibara-business.php llama `install()` + `update_option()` por su cuenta,
	 * fuera de `maybe_install()`. Solo lectura.
	 *
	 * @return bool
	 */
	public static function schema_ready(): bool {
		global $wpdb;

		$table = self::table_name();
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);

		return $found === $table;
	}

	/**
	 * dbDelta del schema completo.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		// El guard es sobre la FUNCIÓN y no sobre el fichero: `require_once`
		// dedupea por ruta resuelta, así que si `dbDelta()` ya está en memoria
		// —otro plugin la cargó, o la suite la tiene stubeada— volver a incluir
		// el fichero por otra ruta fatalea con «Cannot redeclare».
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// idx_user_status: el lote del digest por cliente.
		// idx_status_created: `pending_user_ids()` y la expiración por antigüedad.
		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			term_id BIGINT(20) UNSIGNED NOT NULL,
			volume SMALLINT UNSIGNED NOT NULL,
			event_key VARCHAR(64) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			sent_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_event (event_key),
			KEY idx_user_status (user_id, status),
			KEY idx_status_created (status, created_at)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Drop completo. NO se llama en deactivation: perder la tabla reabre la
	 * ventana de idempotencia. Existe para uninstall.php o teardown manual.
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- el nombre de tabla sale de $wpdb->prefix más un literal de la clase, y prepare() no parametriza identificadores: un marcador en «DROP TABLE» no es SQL válido.
		delete_option( self::OPTION_KEY );
	}
}
