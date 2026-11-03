<?php
/**
 * Apaga la campaña al terminar su vigencia.
 *
 * Settings deja de aplicar descuentos fuera de ventana. Este cron sincroniza
 * además el interruptor guardado y el estado visible del panel mediante
 * update_option, con registro operativo. La publicación de Search observa ese
 * mismo camino de escritura. Si un candado rechaza el cambio, se registra el
 * resultado y otra pasada puede reintentarlo sin publicar una suposición.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\TaxonomyDiscount;

use Akibara\Business\Utilities\Log;
use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.116.5
 */
final class CampaignExpiryCron {

	public const HOOK = 'akb_taxonomy_discount_expiry_sweep';

	/**
	 * Rastro del último apagado automático, para la tarjeta de estado del
	 * panel: `array{to: string, off_at: string}`. Vive 30 días: pasado eso el
	 * dueño ya lo vio o ya no importa.
	 */
	public const NOTICE_TRANSIENT = 'akibara_taxonomy_discount_auto_off';

	private const SCHEDULE = 'daily';

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'handle' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Auto-reparación en init (patrón PreventaVencidaCron): si alguien borra el
	 * evento, la próxima petición lo re-agenda. wp_next_scheduled() lee de
	 * alloptions — costo ~0.
	 */
	public function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * La corrida diaria.
	 *
	 * Lee la ventana de la BASE (no de la caché de options): es la misma
	 * lectura que usa la tarjeta de estado, y la única que no puede servir la
	 * foto de antes del último guardado.
	 *
	 * @param string|null $today Fecha `Y-m-d` de hoy en la zona de la tienda (tests). null = hoy.
	 * @return string 'off' si apagó; 'kept' si no había nada que apagar; 'rejected' si la escritura no entró.
	 */
	public function handle( ?string $today = null ): string {
		$estado = $this->settings->window_state_from_database();
		$to     = (string) $estado['to'];

		if ( 'yes' !== (string) $estado['enabled'] || '' === $to ) {
			return 'kept';
		}

		$hoy = null !== $today ? $today : StoreTime::today_ymd();
		if ( Settings::window_covers( '', $to, $hoy ) ) {
			return 'kept'; // El día «hasta» todavía es campaña; se apaga al siguiente.
		}

		if ( ! update_option( Settings::OPTION_ENABLED, 'no' ) ) {
			Log::warn(
				'descuentos',
				'La campaña terminó el ' . $to . ' pero el interruptor no se pudo apagar: la escritura de la option fue rechazada. Se reintenta mañana.'
			);

			return 'rejected';
		}

		set_transient(
			self::NOTICE_TRANSIENT,
			array(
				'to'     => $to,
				'off_at' => $hoy,
			),
			30 * DAY_IN_SECONDS
		);
		Log::info( 'descuentos', 'Campaña apagada sola el ' . $hoy . ': su vigencia terminó el ' . $to . '.' );

		return 'off';
	}
}
