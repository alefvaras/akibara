<?php
/**
 * QueueHooks — los hooks de Action Scheduler de este plugin, para quien vigile la cola.
 *
 * Por qué existe
 * --------------
 * La tarjeta «Alertas del Sistema» de akibara-business vigila la cola de
 * Action Scheduler (`Utilities\QueueHealth`, 1.116.31), pero sólo conoce sus
 * propios hooks. Este plugin agenda once —cuatro diarios: caducidad,
 * cumpleaños, limpieza de enlaces de reseña y el barrido de acreditación— y
 * sin esto podían dejar de correr con la tarjeta en verde (revisión
 * independiente de la ronda 7, M4). Se suman por el filtro
 * `akibara_queue_health_hooks`; si business no está, el filtro no lo lee nadie
 * y no pasa nada.
 *
 * La lista se mantiene a mano y `uninstall.php` la repite (no puede cargar
 * clases); `LaColaQuedaLimpiaAlDesinstalarTest` y `QueueHooksTest` vigilan que
 * las tres fuentes —código, desinstalador y esta lista— coincidan.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.15
 */
final class QueueHooks {

	public const ALL = array(
		'akb_rewards_expiry_daily',
		'akb_rewards_expiry_batch',
		'akb_rewards_expiry_warn_async',
		'akb_rewards_birthday_daily',
		'akb_rewards_redemption_retry',
		'akb_rewards_accrual_sweep_daily',
		'akb_rewards_send_review_invite',
		'akb_rewards_order_email_retry',
		'akb_rewards_review_email_retry',
		'akb_rewards_referral_email_retry',
		'akb_rewards_review_tokens_cleanup',
		'akb_rewards_home_cache_retry',
	);

	/**
	 * @return void
	 */
	public function register(): void {
		add_filter( 'akibara_queue_health_hooks', array( $this, 'add_hooks' ) );
	}

	/**
	 * @param mixed $hooks Los que ya vigila business.
	 * @return array<int, string>
	 */
	public function add_hooks( $hooks ): array {
		$previos = is_array( $hooks ) ? array_map( 'strval', $hooks ) : array();

		return array_values( array_unique( array_merge( $previos, self::ALL ) ) );
	}
}
