<?php
/**
 * Esquema de {prefix}akb_bis_subscribers. La activación y el guard de versión
 * permiten migraciones incrementales también con despliegues sin reactivación.
 * La tabla propia permite claims condicionales; UNIQUE(product_id,email)
 * deduplica altas concurrentes y UNIQUE(token) protege la baja.
 * notify_attempts acota reintentos y mueve a failed los que agotan MAX.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BackInStock;

use Akibara\Business\Utilities\Log;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.53.0
 */
final class Install {

	public const DB_VERSION = '2.0.0';
	public const OPTION_KEY = 'akb_bis_db_version';

	/**
	 * Nombre completo de la tabla (con prefix del sitio).
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_bis_subscribers';
	}

	/**
	 * Crea o migra idempotentemente y sella versión sólo si puede comprobar la
	 * tabla. dbDelta puede fallar sin excepción por permisos, engine o longitud
	 * de clave utf8mb4; sin sello, la siguiente petición vuelve a intentarlo.
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
				'Migración BIS %s falló: la tabla %s no existe tras dbDelta; no se sella %s. last_error=%s',
				self::DB_VERSION,
				self::table_name(),
				self::OPTION_KEY,
				(string) $wpdb->last_error
			);

			Log::warn( 'bis', $message );
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
	 * ¿La tabla existe realmente? Contrato mínimo schema↔versión (el mismo que
	 * akibara-search documenta en su Install: una db_version sellada no
	 * garantiza que la migración corrió).
	 *
	 * A propósito comprueba SOLO existencia y no columnas/índices: un chequeo
	 * más estricto en el camino caliente correría dbDelta en CADA request ante
	 * cualquier diferencia cosmética de una instalación vieja. Auditar
	 * columnas/índices es trabajo de admin/CLI, no del front.
	 *
	 * Es `public` (no private) porque el activation hook de akibara-business.php
	 * llama install() + update_option() por su cuenta, fuera de maybe_install():
	 * sin esta sonda sellaría la versión a ciegas y una activación con dbDelta
	 * fallido volvería a envenenar el módulo para siempre (maybe_install()
	 * haría early-return). Solo lectura: no crea ni migra nada.
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
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// email VARCHAR(100): límite del RFC efectivo en la práctica y permite
		// el UNIQUE compuesto sin pasar el límite de largo de key en utf8mb4.
		// status VARCHAR(20): ver Subscriber::STATUSES (ENUM real complica
		// dbDelta; el repositorio valida en escritura).
		// idx_product_status: count_waiting() y el lote del dispatcher.
		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			email VARCHAR(100) NOT NULL,
			user_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			token CHAR(64) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'subscribed',
			notify_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			notified_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_product_email (product_id, email),
			UNIQUE KEY uniq_token (token),
			KEY idx_product_status (product_id, status)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Drop completo. NO se llama en deactivation (preservamos suscripciones).
	 * Existe para uninstall.php o teardown manual por CLI.
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- el nombre de tabla sale de $wpdb->prefix más un literal de la clase, y prepare() no parametriza identificadores: un marcador en «DROP TABLE» no es SQL válido.
		delete_option( self::OPTION_KEY );
	}
}
