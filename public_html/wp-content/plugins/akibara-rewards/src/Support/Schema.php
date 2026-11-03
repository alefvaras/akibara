<?php
/**
 * Schema — tabla {prefix}akb_rewards_ledger.
 *
 * Mismo patrón que BackInStock\Install de akibara-business: activation hook +
 * guard de versión en cada boot (deploys por rsync no reactivan el plugin) y
 * sellado de la versión SOLO si la tabla quedó realmente creada — dbDelta
 * falla en silencio y sellar a ciegas envenena el módulo para siempre.
 *
 * Decisiones de schema:
 *   - `delta` FIRMADO + `balance_after`: el ledger de SPAR guardaba puntos sin
 *     signo y el sentido vivía en `type` add/remove; con el delta firmado la
 *     suma de la columna ES el saldo, y `balance_after` deja auditable cada
 *     movimiento sin reconstruir la historia.
 *   - `ref_type`/`ref_id`: referencia polimórfica (order, review, admin,
 *     migration, birthday) para reversas exactas por pedido.
 *   - `event_key` UNIQUE y NULLABLE: la red de seguridad FINAL contra la
 *     doble acreditación. Los movimientos de un solo disparo (acreditación,
 *     bono, captura de canje) llevan clave ('award_100'); un duplicado choca
 *     con el índice y se trata como ya-aplicado. Los ajustes que pueden
 *     repetirse legítimamente (reembolsos parciales) van con NULL — MySQL
 *     permite múltiples NULL en un índice UNIQUE.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

use Akibara\Rewards\Points\AccountRewards;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Schema {

	public const DB_VERSION = '1.3.0';
	public const OPTION_KEY = 'akb_rewards_db_version';

	/**
	 * Nombre completo de la tabla del ledger (con prefix del sitio).
	 *
	 * @return string
	 */
	public static function ledger_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_rewards_ledger';
	}

	/**
	 * Tabla de tokens del formulario de reseña por invitación (Fase 2b).
	 *
	 * @return string
	 */
	public static function review_forms_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_rewards_review_forms';
	}

	/**
	 * Higiene: borra tokens de invitación que vencieron hace más de 6 meses y
	 * nunca se usaron. Los usados se conservan (auditoría de qué reseña vino
	 * de qué pedido). La llama el cron diario; LIMIT para no bloquear.
	 *
	 * @return void
	 */
	public static function purge_stale_review_tokens(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia, mantenimiento.
		$wpdb->query( 'DELETE FROM ' . self::review_forms_table() . " WHERE used_at IS NULL AND expires_at < DATE_SUB(NOW(), INTERVAL 6 MONTH) LIMIT 500" );
	}

	/**
	 * Cuelga la migración en activación y en boot.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_activation_hook( AKIBARA_REWARDS_FILE, array( self::class, 'maybe_install' ) );
		add_action( 'init', array( self::class, 'maybe_install' ), 5 );
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
			Log::error(
				sprintf(
					'Migración %s falló: %s no existe tras dbDelta; no se sella %s. last_error=%s',
					self::DB_VERSION,
					self::ledger_table(),
					self::OPTION_KEY,
					(string) $wpdb->last_error
				)
			);
			return;
		}

		self::migrate_option_autoload();
		self::migrate_award_status();

		// autoload = true a propósito (ver migrate_option_autoload): esta
		// misma option se lee en CADA petición, en el guard de arriba.
		update_option( self::OPTION_KEY, self::DB_VERSION, true );
	}

	/**
	 * Options del programa que DEBEN viajar en `alloptions`.
	 *
	 * Son los 36 montos/interruptores de la pantalla de ajustes más las dos
	 * marcas de arranque. Todas se leen en el camino del cliente (ficha, Mi
	 * cuenta, carrito, correos) y son escalares diminutos: con autoload
	 * apagado, un render de Mi cuenta pagaba 17 SELECT sueltos y cada ficha
	 * de producto 6. Autoload existe exactamente para esto.
	 *
	 * @return array<int,string>
	 */
	public static function autoloaded_options(): array {
		return array_merge(
			SettingsPage::option_names(),
			array( self::OPTION_KEY, AccountRewards::FLUSH_OPTION )
		);
	}

	/**
	 * Pone en autoload las options del programa que ya existan.
	 *
	 * Cambiar el flag en la escritura NO reescribe las filas guardadas: sin
	 * esta pasada, una instalación viva se quedaba con las 36 en `off` para
	 * siempre. Corre una vez por versión de esquema.
	 *
	 * @return void
	 */
	private static function migrate_option_autoload(): void {
		if ( ! function_exists( 'wp_set_option_autoload_values' ) ) {
			return; // WP < 6.4: la escritura nueva ya nace con autoload.
		}

		wp_set_option_autoload_values( array_fill_keys( self::autoloaded_options(), true ) );
	}

	/**
	 * Mueve el estado que acredita al nuevo default, UNA sola vez (1.3.0).
	 *
	 * Cambiar el default no basta: el guardado del panel escribe todas las options,
	 * por lo que se migra explícitamente el valor previo para asegurar paridad.
	 *
	 * Sólo mueve el valor VIEJO de fábrica: si el dueño eligió otro estado a
	 * mano, se respeta. Y corre una vez por versión de esquema, así que
	 * volver a poner «Listo para enviar» desde el panel es definitivo.
	 *
	 * @return void
	 */
	private static function migrate_award_status(): void {
		$guardado = get_option( 'akb_rewards_award_status', '' );

		if ( 'shipping-progress' === (string) $guardado ) {
			update_option( 'akb_rewards_award_status', Options::DEFAULT_AWARD_STATUS, true );
		}
	}

	/**
	 * Ejecuta dbDelta con el schema vigente.
	 *
	 * @return void
	 */
	private static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::ledger_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			delta INT NOT NULL,
			balance_after INT NOT NULL,
			action VARCHAR(40) NOT NULL,
			ref_type VARCHAR(20) NOT NULL DEFAULT '',
			ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			event_key VARCHAR(191) DEFAULT NULL,
			note VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY user_created (user_id, created_at),
			KEY ref (ref_type, ref_id)
		) {$charset};";

		dbDelta( $sql );

		// Tokens de invitación a reseñar (Fase 2b): un enlace por pedido,
		// calcado del mecanismo del tercero (cr_local_forms) pero con
		// caducidad y un solo uso auditables. token único; el pedido puede
		// tener más de un token en su historia (re-invitación tras reversa).
		$forms = self::review_forms_table();

		$sql_forms = "CREATE TABLE {$forms} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			token VARCHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			used_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY order_id (order_id)
		) {$charset};";

		dbDelta( $sql_forms );
	}

	/**
	 * Comprueba que la tabla exista de verdad.
	 *
	 * @return bool
	 */
	private static function schema_ready(): bool {
		global $wpdb;

		foreach ( array( self::ledger_table(), self::review_forms_table() ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return false;
			}
		}

		return true;
	}
}
