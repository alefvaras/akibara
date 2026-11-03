<?php
/**
 * Forms — módulo de formularios públicos (Contacto).
 *
 * Bootstrap del módulo. Registra:
 *   - Shortcode: [akb_form_contacto].
 *   - REST endpoint: /akibara/v1/contacto.
 *   - Enqueue del JS handler + CSS minimal solo cuando el shortcode
 *     está presente en la página.
 *
 * Diseño:
 *   - Markup en theme (template-parts/forms/) → respeta separación
 *     theme = UI, plugin = lógica.
 *   - Notificación al owner por wp_mail(). La entrega se delega al transporte
 *     SMTP del sitio; Business no administra transporte ni credenciales.
 *   - Cada mensaje se guarda en `ContactoLog` antes de intentar el correo, y
 *     los que no llegaron a salir se muestran en las alertas del panel: el
 *     fire-and-forget original los perdía enteros cuando `wp_mail()` fallaba.
 *   - Este módulo NO sincroniza submissions de Contacto a ningún CRM externo.
 *
 * Histórico:
 *   1.6.25 — removido sub-feature Encargos (CPT akb_encargo + shortcode
 *            [akb_form_encargo] + REST /akibara/v1/encargo). Política de
 *            títulos no listados queda cubierta en /faq/. Página /encargos/
 *            (ID 22637) movida a trash; sin redirect — Google la
 *            des-indexa naturalmente.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Forms;

use Akibara\Business\Utilities\ThemeAssetVersion;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap del módulo Forms. Idempotente.
 *
 * @since 1.3.0
 */
final class Forms {

	/** Acción de `admin-post.php` que descarta los mensajes no entregados. */
	public const DISMISS_ACTION = 'akb_contacto_dismiss';

	private ContactoForm $contacto_form;
	private ContactoSubmit $contacto_submit;

	public function __construct() {
		$this->contacto_form   = new ContactoForm();
		$this->contacto_submit = new ContactoSubmit();
	}

	/**
	 * Conecta los hooks del módulo. Llamado por Plugin::register_modules().
	 *
	 * @return void
	 */
	public function register(): void {
		$this->contacto_form->register();
		$this->contacto_submit->register();
		( new PrivacyHandler() )->register();

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss_undelivered' ) );

		// Registra el respaldo admin-post del formulario para invitados y clientes
		// autenticados. El POST nativo usa el mismo handler cuando JavaScript no
		// intercepta el envío; no transporta el mensaje mediante GET.
		add_action( 'admin_post_nopriv_' . ContactoSubmit::ADMIN_POST_ACTION, array( $this->contacto_submit, 'handle_admin_post' ) );
		add_action( 'admin_post_' . ContactoSubmit::ADMIN_POST_ACTION, array( $this->contacto_submit, 'handle_admin_post' ) );
	}

	/**
	 * Descarta los mensajes que el panel muestra como no entregados.
	 *
	 * Sin esta salida la alerta sería permanente, y una alerta que no se puede
	 * cerrar enseña a ignorar la tarjeta entera.
	 *
	 * @return void
	 */
	public function handle_dismiss_undelivered(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.', '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::DISMISS_ACTION );
		ContactoLog::forget_undelivered();

		wp_safe_redirect( admin_url( 'admin.php?page=akibara-panel' ) );
		exit;
	}

	/**
	 * Encola JS + CSS solo si la página actual contiene alguno de los
	 * shortcodes — evita pagar el costo en homepage/shop.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post || ! is_string( $post->post_content ) ) {
			return;
		}

		$has_contacto = has_shortcode( $post->post_content, ContactoForm::SHORTCODE );

		if ( ! $has_contacto ) {
			return;
		}

		$theme_url = get_stylesheet_directory_uri();
		$theme_dir = get_stylesheet_directory();

		// La copia `.min` del build del theme salvo SCRIPT_DEBUG, versionada por
		// el fichero servido (ThemeAssetVersion::served_path(), T4).
		$js_path = 'assets/js/akibara-forms.js';
		if ( file_exists( $theme_dir . '/' . $js_path ) ) {
			$js_served = ThemeAssetVersion::served_path( $js_path );
			wp_enqueue_script(
				'akibara-forms',
				$theme_url . '/' . $js_served,
				array(),
				ThemeAssetVersion::for_path( $js_served ),
				true
			);

			// El endpoint es público y no requiere nonce por visitante. Un nonce
			// incrustado en HTML cacheado puede caducar antes del envío y provocar
			// un rechazo REST anterior al handler. Las defensas son origen, honeypot
			// y cuotas de intentos y envíos por IP.
			wp_localize_script(
				'akibara-forms',
				'AkibaraFormsConfig',
				array(
					'restUrl'  => esc_url_raw( rest_url( 'akibara/v1/' ) ),
					// Texto de cada error de validación, por motivo; se editan en
					// Akibara → Textos → «Formulario de contacto» (AI-5).
					'messages' => Settings::messages(),
				)
			);
		}

		$css_path = 'assets/css/forms.css';
		if ( file_exists( $theme_dir . '/' . $css_path ) ) {
			$css_served = ThemeAssetVersion::served_path( $css_path );
			wp_enqueue_style(
				'akibara-forms',
				$theme_url . '/' . $css_served,
				array(),
				ThemeAssetVersion::for_path( $css_served )
			);
		}
	}
}
