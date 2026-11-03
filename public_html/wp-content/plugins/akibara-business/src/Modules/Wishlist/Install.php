<?php
/**
 * Install — schema de la tabla wp_akb_wishlist.
 *
 * Se ejecuta en register_activation_hook + en boot() con guard versión (option
 * akb_wishlist_db_version) para auto-migrar sin reactivar el plugin. dbDelta
 * tolera diffs incrementales.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Wishlist;

use Akibara\Business\Utilities\Log;

defined( 'ABSPATH' ) || exit;

final class Install {

	/** Una traza por hora mientras la tabla no se pueda crear. */
	private const LOG_THROTTLE = 'akb_wishlist_install_logged';

	public const DB_VERSION = '1.0.0';
	public const OPTION_KEY = 'akb_wishlist_db_version';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_wishlist';
	}

	/**
	 * ¿La tabla existe de verdad?
	 *
	 * `dbDelta()` falla EN SILENCIO —permisos, engine, largo de clave en
	 * utf8mb4— y sellar la versión sin comprobarlo deja el módulo roto para
	 * siempre: el guard de versión corta en la petición siguiente y nadie
	 * vuelve a instalar (). Sólo lectura, como en los dos módulos
	 * hermanos.
	 *
	 * @return bool
	 */
	public static function schema_ready(): bool {
		global $wpdb;

		$table = self::table_name();

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	/**
	 * Crea o actualiza la tabla cuando cambia la versión de su esquema.
	 *
	 * Se ejecuta al activar y durante boot() para cubrir despliegues que no reactivan
	 * el plugin. dbDelta sólo aplica diferencias y la versión se sella después de
	 * verificar la tabla. El guard de versión se consulta en cada petición; su
	 * opción debe pertenecer a alloptions. wp_load_alloptions() permite distinguir
	 * una fila ausente de una que necesita reparar autoload, sin repetir dbDelta
	 * ni inventar un cambio de esquema.
	 */
	public static function maybe_install(): void {
		$alloptions = wp_load_alloptions();
		$stored     = is_array( $alloptions ) && isset( $alloptions[ self::OPTION_KEY ] )
			? (string) $alloptions[ self::OPTION_KEY ]
			: null;

		if ( self::DB_VERSION === $stored ) {
			return;
		}

		// Instalación previa: la tabla ya está al día, sólo hay que devolver la
		// opción a alloptions. Este brazo corre UNA vez tras el despliegue.
		if ( null === $stored && get_option( self::OPTION_KEY ) === self::DB_VERSION ) {
			wp_set_option_autoload( self::OPTION_KEY, true );
			return;
		}

		self::install();
		if ( ! self::schema_ready() ) {
			// Sin sellar: la petición siguiente reintenta cuando se corrija la
			// causa. Pero NO en silencio: BackInStock y SeriesFollow ya dejaban
			// traza y Favoritos era el único módulo con tabla que fallaba mudo,
			// así que el aviso de la activación mandaba a buscar un error que
			// para este módulo no estaba escrito en ningún sitio.
			global $wpdb;
			$message = sprintf(
				'Migración Wishlist %s falló: la tabla %s no existe tras dbDelta; no se sella %s. last_error=%s',
				self::DB_VERSION,
				self::table_name(),
				self::OPTION_KEY,
				(string) $wpdb->last_error
			);

			// Limitar el aviso de tabla ausente a una vez por hora, tanto en registro
			// como en administración; maybe_install() puede ejecutarse en cada petición.

			if ( false !== get_transient( self::LOG_THROTTLE ) ) {
				return;
			}
			set_transient( self::LOG_THROTTLE, 1, HOUR_IN_SECONDS );

			Log::warn( 'wishlist', $message );
			if ( ! function_exists( 'wc_get_logger' ) ) {
				// Sin WooCommerce cargada el canal de la casa no existe y este
				// fallo NO puede quedar mudo.
				error_log( '[akibara-business] ' . $message );
			}

			return;
		}
		update_option( self::OPTION_KEY, self::DB_VERSION, true );
	}

	public static function install(): void {
		global $wpdb;
		// Misma guarda que `SeriesFollow\Install`: si `dbDelta()` ya existe —otro
		// plugin cargó `upgrade.php` por otra ruta, o la suite la tiene stubeada—
		// incluirlo de nuevo fatalea con «Cannot redeclare».
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// PRIMARY KEY (user_id, product_id) garantiza UNIQUE atómico — un mismo
		// usuario no puede tener el mismo producto dos veces, sin necesidad de
		// SELECT-before-INSERT (race-condition-safe bajo concurrencia AJAX).
		// idx_user_added: queries de listing ordenadas por reciente.
		// idx_product: cleanup cuando se borra producto (DELETE FROM... WHERE product_id=N).
		$sql = "CREATE TABLE {$table} (
			user_id BIGINT(20) UNSIGNED NOT NULL,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (user_id, product_id),
			KEY idx_user_added (user_id, added_at),
			KEY idx_product (product_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Drop completo. NO se llama en deactivation (preservamos datos). Existe
	 * para uninstall.php o CLI de teardown manual.
	 */
	public static function drop(): void {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB -- el nombre de tabla sale de $wpdb->prefix más un literal de la clase, y prepare() no parametriza identificadores: un marcador en «DROP TABLE» no es SQL válido.
		delete_option( self::OPTION_KEY );
	}
}
