<?php
/**
 * Inicializa plazos de pa_pais desde options legacy, después desde
 * Settings::DEFAULT_LEAD_TIMES para Argentina/España. Los demás países
 * se completan desde el panel. No sobrescribe metas existentes.
 * El sello akibara_eda_pa_pais_migrated_at evita repetir la migración;
 * el upgrade la ejecuta en init:999, con la taxonomía registrada.
 * Para repetirla se retira expresamente el sello y se ejecuta la migración.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\EstimatedDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * Migration entry point.
 *
 * @since 1.14.0
 */
final class Migration {

	public const FLAG_OPTION = 'akibara_eda_pa_pais_migrated_at';
	public const VERSION     = '1.14.0';
	private const TAXONOMY   = 'pa_pais';

	/**
	 * Activation hook handler. Ejecuta install() forzando attempt incluso si
	 * el flag existe (defensive — owner puede haber bumpeado plugin pero
	 * cambiado config en el medio). install() internamente sigue siendo idempotente.
	 *
	 * @return void
	 */
	public static function on_activation(): void {
		self::run( false );
	}

	/**
	 * Lazy trigger durante boot. Solo corre si el flag NO existe — caso fresh
	 * install donde el plugin se carga sin haber pasado por activate_plugin().
	 *
	 * Idempotente por construcción: si el flag existe, no hace nada.
	 *
	 * @return void
	 */
	/** Memo de la red de seguridad: como mucho dos intentos al día. */
	private const RETRY_MEMO = 'akibara_eda_pa_pais_retry';

	/**
	 * Reintenta migraciones sin sello desde administración, cron o CLI, como
	 * máximo dos veces al día. No añade consultas al camino del visitante.
	 *
	 * @return void
	 */
	public static function maybe_retry_in_admin(): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( '' !== (string) get_transient( self::RETRY_MEMO ) ) {
			return;
		}

		set_transient( self::RETRY_MEMO, 'visto', 12 * HOUR_IN_SECONDS );
		self::maybe_run_lazy();
	}

	public static function maybe_run_lazy(): void {
		if ( '' !== (string) get_option( self::FLAG_OPTION, '' ) ) {
			return;
		}
		self::run( true );
	}

	/**
	 * Ejecuta la migración.
	 *
	 * @param bool $skip_if_flagged Si true Y el flag existe, no hace nada.
	 *                              False = correr siempre (modo activation).
	 * @return void
	 */
	public static function run( bool $skip_if_flagged = true ): void {
		if ( $skip_if_flagged && '' !== (string) get_option( self::FLAG_OPTION, '' ) ) {
			return;
		}

		if ( ! function_exists( 'get_terms' ) || ! function_exists( 'taxonomy_exists' ) ) {
			return;
		}

		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			// Taxonomy no registrada (WooCommerce apagado): sin `pa_pais` no hay
			// nada que migrar. Vuelve a intentarse en la próxima activación o
			// en el próximo bump de versión.
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			if ( ! isset( $term->term_id, $term->slug ) ) {
				continue;
			}
			self::migrate_term( (int) $term->term_id, (string) $term->slug );
		}

		update_option(
			self::FLAG_OPTION,
			gmdate( 'c' ) . ' v' . self::VERSION,
			false
		);
	}

	/**
	 * Migra un término desde la option legacy o desde DEFAULT_LEAD_TIMES del
	 * slug. Sin una fuente aplicable no escribe. Conserva el term-meta existente
	 * si ya contiene un valor válido.
	 *
	 * @param int    $term_id ID del término.
	 * @param string $slug    Slug del término.
	 * @return void
	 */
	private static function migrate_term( int $term_id, string $slug ): void {
		$existing_min = (int) get_term_meta( $term_id, Settings::TERM_META_MIN_DAYS, true );
		$existing_max = (int) get_term_meta( $term_id, Settings::TERM_META_MAX_DAYS, true );

		if ( $existing_min >= 1 && $existing_max >= $existing_min && $existing_max <= 365 ) {
			// Term-meta ya válido. Owner pudo haberlo seteado manual — respect it.
			return;
		}

		// 1) Option legacy.
		$legacy_map = array(
			'argentina' => 'ar',
			'espana'    => 'es',
		);
		if ( isset( $legacy_map[ $slug ] ) ) {
			$code_lc = $legacy_map[ $slug ];
			$min     = (int) get_option( "akibara_eda_lt_{$code_lc}_min", 0 );
			$max     = (int) get_option( "akibara_eda_lt_{$code_lc}_max", 0 );
			if ( $min >= 1 && $max >= $min && $max <= 365 ) {
				update_term_meta( $term_id, Settings::TERM_META_MIN_DAYS, $min );
				update_term_meta( $term_id, Settings::TERM_META_MAX_DAYS, $max );
				return;
			}
		}

		// 2) Constant fallback.
		if ( isset( Settings::DEFAULT_LEAD_TIMES[ $slug ] ) ) {
			$defaults = Settings::DEFAULT_LEAD_TIMES[ $slug ];
			update_term_meta( $term_id, Settings::TERM_META_MIN_DAYS, $defaults['min'] );
			update_term_meta( $term_id, Settings::TERM_META_MAX_DAYS, $defaults['max'] );
			return;
		}

		// 3) Slug nuevo sin defaults → no escribir, owner debe llenar manual.
	}
}
