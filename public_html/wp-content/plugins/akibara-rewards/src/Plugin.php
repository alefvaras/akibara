<?php
/**
 * Clase principal del plugin.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards;

use Akibara\Rewards\Points\AccountRewards;
use Akibara\Rewards\Points\Accrual;
use Akibara\Rewards\Points\Birthday;
use Akibara\Rewards\Points\Emails;
use Akibara\Rewards\Points\Expiry;
use Akibara\Rewards\Points\ProgramPage;
use Akibara\Rewards\Blocks\CheckoutIntegration;
use Akibara\Rewards\Points\AdminAdjust;
use Akibara\Rewards\Points\PdpNotice;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Points\Referrals;
use Akibara\Rewards\Points\WelcomeBonus;
use Akibara\Rewards\Reviews\AdminReviews;
use Akibara\Rewards\Reviews\Carousel;
use Akibara\Rewards\Reviews\Moderation;
use Akibara\Rewards\Reviews\Disclosure;
use Akibara\Rewards\Reviews\PhotoSurfaces;
use Akibara\Rewards\Reviews\ReviewNudge;
use Akibara\Rewards\Reviews\Invitations;
use Akibara\Rewards\Reviews\ReviewForm;
use Akibara\Rewards\Reviews\ReviewPoints;
use Akibara\Rewards\Reviews\Schema as ReviewsSchema;
use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\Schema;
use Akibara\Rewards\Support\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton que registra los módulos. Cada módulo expone `register()` y cuelga
 * sus propios hooks (patrón de akibara-business).
 */
final class Plugin {

	/**
	 * Instancia única.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Devuelve (creando si hace falta) la instancia única.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registra módulos y rutinas de upgrade.
	 *
	 * @return void
	 */
	public function boot(): void {
		Schema::register();
		( new SettingsPage() )->register();

		( new Accrual() )->register();
		( new \Akibara\Rewards\Support\QueueHooks() )->register();
		( new WelcomeBonus() )->register();
		( new Emails() )->register();
		( new Redemption() )->register();
		( new AccountRewards() )->register();
		( new PdpNotice() )->register();
		( new Birthday() )->register();
		( new Referrals() )->register();
		( new Expiry() )->register();
		( new ProgramPage() )->register();
		( new CheckoutIntegration() )->register();
		( new AdminAdjust() )->register();
		( new \Akibara\Rewards\Points\CustomersAdmin() )->register();
		( new \Akibara\Rewards\Points\CustomerPointsPage() )->register();
		( new \Akibara\Rewards\Reviews\InvitationReschedule() )->register();
		( new \Akibara\Rewards\Support\Privacy() )->register();
		( new \Akibara\Rewards\Support\Alerts() )->register();

		// La administración permanece disponible con CusRev. Las superficies
		// públicas esperan al motor propio para evitar premios y schema duplicados.
		( new AdminReviews() )->register();
		// El aviso de incentivo también SIEMPRE: sólo lee la meta de lo ya
		// pagado, y una reseña premiada no puede perderlo si el motor se duerme.
		( new Disclosure() )->register();

		// Expone la conversión configurada de puntos sin que otros plugins
		// necesiten cargar clases de Rewards.
		add_filter(
			'akb_rewards_points_label',
			static fn( $label, $points = 0 ): string => Options::points_label( (int) $points ),
			10,
			2
		);

		// Los enlaces de baja ya enviados siguen disponibles con el motor apagado.
		( new \Akibara\Rewards\Reviews\InviteOptOut() )->register();

		// La limpieza de tokens conserva agenda propia con el motor apagado.
		( new \Akibara\Rewards\Reviews\TokenCleanup() )->register();

		// Una sola autoridad para «¿está vivo el motor de reseñas?»: el mismo
		// accesor que consultan las superficies de copy, para que el filtro
		// documentado gobierne también qué módulos cuelgan.
		if ( Options::reviews_engine_on() ) {
			( new Moderation() )->register();
			( new ReviewPoints() )->register();
			( new Carousel() )->register();
			// Visualización de la foto adjunta en la ficha del tomo y en la pantalla de moderación.
			( new PhotoSurfaces() )->register();
			( new ReviewsSchema() )->register();
			( new ReviewNudge() )->register();
			( new Invitations() )->register();
			( new ReviewForm() )->register();
		} else {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-info"><p>';
					echo '<strong>Akibara Rewards</strong>: los módulos de reseñas (puntos, carrusel, schema, moderación) ';
					echo 'duermen mientras Customer Reviews siga activo — su cupón es el incentivo vigente. ';
					echo 'Para la moderación obligatoria mientras tanto, activar <code>ivole_enable_moderation</code>.';
					echo '</p></div>';
				}
			);
		}

		// El comando WP-CLI de migración se registra en el fichero principal,
		// FUERA del gate de convivencia: debe existir mientras SPAR siga
		// activo, que es exactamente cuando este boot no corre.
	}
}
