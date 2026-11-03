<?php
/**
 * TokenCleanup — limpieza diaria de los enlaces de reseña caducados.
 *
 * La acción tiene agenda propia y se registra aunque estén apagados el motor
 * de reseñas o el vencimiento de puntos. Los enlaces existentes deben seguir
 * caducando sin depender de esos interruptores comerciales.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.9
 */
final class TokenCleanup {

	public const HOOK = 'akb_rewards_review_tokens_cleanup';

	/**
	 * Memo del estado que quedó en la cola, para no consultar Action
	 * Scheduler en cada petición de admin.
	 */
	private const SCHEDULED_MEMO = 'akb_rewards_tokens_cleanup_scheduled';

	/**
	 * Cuelga el oyente y la agenda.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Borra los tokens caducados y nunca usados.
	 *
	 * @return void
	 */
	public function run(): void {
		Schema::purge_stale_review_tokens();
	}

	/**
	 * Agenda diaria, memoizada y sólo en admin/cron/CLI — el mismo patrón que
	 * `Points\Expiry`, para que el sitio del cliente no pague por ella.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( 'yes' === get_transient( self::SCHEDULED_MEMO ) ) {
			return;
		}

		if ( false === as_next_scheduled_action( self::HOOK ) ) {
			$action_id = as_schedule_recurring_action( time() + 3 * HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK );
			if ( ! is_int( $action_id ) || 0 >= $action_id ) {
				return; // Sin memo: se reintenta en la siguiente petición.
			}
		}

		set_transient( self::SCHEDULED_MEMO, 'yes', 12 * HOUR_IN_SECONDS );
	}
}
