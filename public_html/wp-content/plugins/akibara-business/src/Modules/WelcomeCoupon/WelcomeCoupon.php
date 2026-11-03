<?php
/**
 * WelcomeCoupon — orquestador del módulo de beneficio de primera compra (10%).
 *
 * Conecta los subcomponentes agnósticos:
 *   - `Settings`: ajustes del panel (`Akibara → Panel → Venta → Primera compra`)
 *   - `CouponService`: emisión de `WC_Coupon` único, best-wins, anti-abuso y auto-aplicación URL
 *   - `RestController`: endpoint público `POST /wp-json/akibara/v1/welcome-coupon`
 *   - `PopupRenderer`: renderizado y encolado en superficies elegibles
 *
 * Los envíos de correo y recordatorios son gestionados íntegramente por Brevo
 * Marketing Automation mediante el puente desacoplado en mu-plugins.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\WelcomeCoupon;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.120.0
 */
final class WelcomeCoupon {

	public const PANEL_TAB_ID = Settings::PANEL_TAB_ID;

	private Settings $settings;
	private CouponService $coupon_service;
	private RestController $rest_controller;
	private PopupRenderer $popup_renderer;

	public function __construct() {
		$this->settings        = new Settings();
		$this->coupon_service  = new CouponService();
		$this->rest_controller = new RestController( $this->coupon_service );
		$this->popup_renderer  = new PopupRenderer();
	}

	/**
	 * Registra todos los subcomponentes del módulo.
	 */
	public function register(): void {
		$this->settings->register();
		$this->coupon_service->register();
		$this->rest_controller->register();
		$this->popup_renderer->register();
	}

	/**
	 * Devuelve los campos de configuración para el `AdminPanel`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return $this->settings->get_settings();
	}

	/**
	 * Acceso público al servicio de cupones (para pruebas e integraciones).
	 */
	public function coupon_service(): CouponService {
		return $this->coupon_service;
	}

	/**
	 * Acceso público al controlador REST (para pruebas).
	 */
	public function rest_controller(): RestController {
		return $this->rest_controller;
	}

	/**
	 * Acceso público al renderizador del popup (para pruebas).
	 */
	public function popup_renderer(): PopupRenderer {
		return $this->popup_renderer;
	}

	/**
	 * Renderiza la tarjeta de resumen operativo en `Akibara → Panel → Venta → Primera compra`.
	 */
	public function render_admin_section(): void {
		$enabled = Settings::is_enabled();
		$pct     = Settings::discount_pct();
		$min_fmt = Settings::min_amount_formatted();
		$days    = Settings::validity_days();

		echo '<div class="wrap" style="max-width:920px;margin-bottom:16px;">';
		echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid ' . ( $enabled ? '#00a32a' : '#d63638' ) . ';padding:14px 18px;border-radius:4px;">';
		printf(
			'<p style="margin:0 0 6px 0;font-size:14px;"><strong>Estado actual:</strong> %s · <strong>Beneficio:</strong> %d%% sobre %s · <strong>Vigencia:</strong> %d %s · <strong>Automatización:</strong> Gestionada por Brevo Marketing Automation</p>',
			$enabled ? '<span style="color:#007017;font-weight:700;">Activo</span>' : '<span style="color:#b32d2e;font-weight:700;">Apagado</span>',
			(int) $pct,
			esc_html( $min_fmt ),
			(int) $days,
			1 === $days ? 'día' : 'días'
		);
		echo '<p style="margin:0;color:#50575e;font-size:13px;">';
		echo 'Política anti-abuso activa: 1 cupón único por correo, no acumulable sobre descuentos mayores (completa hasta el ' . (int) $pct . '% del precio normal) y validado en el checkout contra compras previas por <strong>RUT, celular y correo</strong>. Los correos de bienvenida y recordatorio son enviados por Brevo.';
		echo '</p>';
		echo '</div>';
		echo '</div>';
	}
}
