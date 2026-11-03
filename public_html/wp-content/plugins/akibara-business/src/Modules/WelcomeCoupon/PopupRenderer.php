<?php
/**
 * PopupRenderer — renderiza y encola el modal de bienvenida (10% primera compra con Brevo)
 * o el modal de campaña promocional estática (Halloween, Cyber, etc.) en superficies elegibles.
 *
 * Superficies NUNCA permitidas:
 *   - Carrito (`/carrito/`), Checkout (`/finalizar-compra/`), Mi cuenta (`/mi-cuenta/`)
 *   - Entradas de blog / guías de lectura (`is_singular( 'post')`), página de contacto
 *   - Peticiones con `?akb_cupon=` en la URL (el cliente ya viene con código en curso)
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\WelcomeCoupon;

use Akibara\Business\Modules\Forms\Honeypot;
use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.120.0
 */
final class PopupRenderer {

	private CouponService $coupon_service;

	public function __construct( ?CouponService $coupon_service = null ) {
		$this->coupon_service = $coupon_service ?? new CouponService();
	}

	/**
	 * Conecta los hooks de front y UCSS.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'handle_preview_cookie' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_modal' ), 40 );
		add_filter( 'litespeed_ucss_whitelist', array( $this, 'allowlist_ucss_selectors' ), 20 );
	}

	/**
	 * Gestiona la cookie de vista previa si se proporciona en la URL.
	 */
	public function handle_preview_cookie(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ Settings::PREVIEW_QUERY_ARG ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$val = sanitize_key( (string) $_GET[ Settings::PREVIEW_QUERY_ARG ] );
			if ( '1' === $val || 'yes' === $val ) {
				if ( ! headers_sent() ) {
					setcookie( Settings::PREVIEW_COOKIE, '1', time() + 3600, '/', '', is_ssl(), false );
				}
				$_COOKIE[ Settings::PREVIEW_COOKIE ] = '1';
			} elseif ( '0' === $val || 'no' === $val ) {
				if ( ! headers_sent() ) {
					setcookie( Settings::PREVIEW_COOKIE, '', time() - 3600, '/', '', is_ssl(), false );
				}
				unset( $_COOKIE[ Settings::PREVIEW_COOKIE ] );
			}
		}
	}

	/**
	 * Modo activo del modal: 'promo' (campaña estática), 'welcome' (primera compra) o '' (ninguno).
	 */
	public function active_mode(): string {
		if ( Settings::is_promo_enabled() ) {
			return 'promo';
		}
		if ( Settings::is_enabled() ) {
			return 'welcome';
		}
		return '';
	}

	/**
	 * ¿Corresponde pintar y encolar el popup en la petición actual?
	 */
	public function should_render(): bool {
		$mode = $this->active_mode();
		if ( '' === $mode ) {
			return false;
		}

		if (
			( function_exists( 'is_admin' ) && is_admin() )
			|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( function_exists( 'is_feed' ) && is_feed() )
			|| ( function_exists( 'is_embed' ) && is_embed() )
		) {
			return false;
		}

		// Si ya viene desde un enlace con cupón (?akb_cupon=...), no interrumpir la navegación.
		// Se marca nocache para evitar que LiteSpeed guarde una copia pública sin modal.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET[ CouponService::QUERY_ARG_COUPON ] ) ) {
			do_action( 'litespeed_control_set_nocache', 'Welcome popup: parametro akb_cupon activo' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return false;
		}

		// Si llega mediante enlace de referido de Akibara Puntos (?ref=...) o cookie `akb_ref`,
		// no canibalizar el embudo de recomendación ni confundir con doble beneficio.
		// Se marca nocache para que un visitante con cookie akb_ref no contamine la caché pública.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['ref'] ) || ! empty( $_COOKIE['akb_ref'] ) ) {
			do_action( 'litespeed_control_set_nocache', 'Welcome popup: embudo de referido activo' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return false;
		}

		// Excluir superficies transaccionales o de cuenta.
		if (
			( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() )
			|| ( function_exists( 'is_404' ) && is_404() )
			|| ( function_exists( 'is_search' ) && is_search() )
		) {
			return false;
		}

		// Excluir artículos del blog/guías y páginas institucionales como Contacto o Akibara Puntos.
		if (
			( function_exists( 'is_singular' ) && is_singular( 'post' ) )
			|| ( function_exists( 'is_page' ) && is_page( array( 'contacto', 'terminos-y-condiciones', 'politica-de-privacidad', 'programa-de-puntos', 'akibara-puntos' ) ) )
		) {
			return false;
		}

		$is_logged_in = function_exists( 'is_user_logged_in' ) && is_user_logged_in();

		if ( 'promo' === $mode ) {
			if ( 'guests' === Settings::promo_audience() && $is_logged_in ) {
				return false;
			}

			if ( function_exists( 'is_front_page' ) && is_front_page() ) {
				return Settings::promo_show_on_home();
			}
			if ( function_exists( 'is_tax' ) && is_tax( 'pa_serie' ) ) {
				return Settings::promo_show_on_series();
			}
			if ( function_exists( 'is_product' ) && is_product() ) {
				return Settings::promo_show_on_product();
			}
			if (
				( function_exists( 'is_shop' ) && is_shop() )
				|| ( function_exists( 'is_product_category' ) && is_product_category() )
				|| ( function_exists( 'is_product_tag' ) && is_product_tag() )
				|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() )
				|| ( function_exists( 'is_page' ) && is_page( array( 'disponibles', 'preventas', 'editoriales' ) ) )
			) {
				return Settings::promo_show_on_catalog();
			}

			return false;
		}

		// Modo 'welcome' (primera compra).
		if ( $is_logged_in ) {
			$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			if ( $user_id > 0 && $this->coupon_service->has_completed_welcome_flow( $user_id ) ) {
				return false;
			}
		}

		if ( function_exists( 'is_front_page' ) && is_front_page() ) {
			return Settings::show_on_home();
		}
		if ( function_exists( 'is_tax' ) && is_tax( 'pa_serie' ) ) {
			return Settings::show_on_series();
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return Settings::show_on_product();
		}
		if (
			( function_exists( 'is_shop' ) && is_shop() )
			|| ( function_exists( 'is_product_category' ) && is_product_category() )
			|| ( function_exists( 'is_product_tag' ) && is_product_tag() )
			|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() )
			|| ( function_exists( 'is_page' ) && is_page( array( 'disponibles', 'preventas', 'editoriales' ) ) )
		) {
			return Settings::show_on_catalog();
		}

		return false;
	}

	/**
	 * Encola el CSS y JS del popup únicamente cuando la página es elegible.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->should_render() ) {
			return;
		}

		if ( Settings::is_preview() ) {
			do_action( 'litespeed_control_set_nocache', 'Akibara welcome: vista previa autorizada' );
		}
		$mode  = $this->active_mode();
		$epoch = Settings::cookie_epoch();

		wp_enqueue_style(
			'akibara-welcome-popup',
			AKIBARA_BUSINESS_URL . 'assets/css/welcome-popup.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/welcome-popup.css' )
		);

		wp_enqueue_script(
			'akibara-welcome-popup',
			AKIBARA_BUSINESS_URL . 'assets/js/welcome-popup.js',
			array(),
			PluginAssetVersion::for_path( 'assets/js/welcome-popup.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		if ( 'welcome' === $mode && Settings::turnstile_enabled() ) {
			wp_enqueue_script(
				'cloudflare-turnstile',
				'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
				array(),
				null,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}

		if ( 'promo' === $mode ) {
			$promo_code = Settings::promo_code();
			$shop_url   = '' !== $promo_code ? CouponService::auto_apply_url( $promo_code ) : Settings::promo_url();
			$cookie_tag = '' !== $promo_code ? strtolower( $promo_code ) : 'info';
			$localized  = array(
				'mode'         => 'promo',
				'preview'      => Settings::is_preview(),
				'promoCode'    => $promo_code,
				'audience'     => Settings::promo_audience(),
				'delaySeconds' => Settings::promo_delay_seconds(),
				'scrollPct'    => Settings::scroll_pct(),
				'dismissDays'  => Settings::promo_dismiss_days(),
				'exitIntent'   => Settings::exit_intent_enabled(),
				'launcher'     => Settings::launcher_enabled(),
				'cookieEpoch'  => $epoch,
				'cookieName'   => 'akb_welcome_promo_' . $cookie_tag . '_v' . $epoch,
				'shopUrl'      => esc_url_raw( $shop_url ),
			);
		} else {
			$localized = array(
				'mode'           => 'welcome',
				'preview'        => Settings::is_preview(),
				'endpoint'       => esc_url_raw( rest_url( RestController::REST_NAMESPACE . RestController::REST_ROUTE ) ),
				'delaySeconds'   => Settings::delay_seconds(),
				'scrollPct'      => Settings::scroll_pct(),
				'dismissDays'    => Settings::dismiss_days(),
				'exitIntent'     => Settings::exit_intent_enabled(),
				'launcher'       => Settings::launcher_enabled(),
				'cookieEpoch'    => $epoch,
				'cookieName'     => 'akb_welcome_dismissed_v' . $epoch,
				'doneCookieName' => CouponService::COOKIE_DONE,
				'discountPct'      => Settings::discount_pct(),
				'minAmountFmt'     => Settings::min_amount_formatted(),
				'validityDays'     => Settings::validity_days(),
				'turnstileEnabled' => Settings::turnstile_enabled(),
				'turnstileSitekey' => Settings::turnstile_sitekey(),
			);
		}

		wp_localize_script( 'akibara-welcome-popup', 'akbWelcomePopup', $localized );
	}

	/**
	 * Renderiza el HTML del popup en `wp_footer`.
	 */
	public function render_modal(): void {
		if ( ! $this->should_render() ) {
			return;
		}

		$mode       = $this->active_mode();
		$logo_url   = $this->resolve_brand_logo_url();
		$promo_code = 'promo' === $mode ? Settings::promo_code() : '';
		$pct        = 'promo' !== $mode ? Settings::discount_pct() : 0;

		if ( 'promo' === $mode ) {
			$copies   = Settings::promo_copies();
			$shop_url = '' !== $promo_code ? CouponService::auto_apply_url( $promo_code ) : Settings::promo_url();
			?>
			<div
				id="akb-welcome-modal"
				class="akb-welcome is-promo<?php echo '' === $promo_code ? ' is-informative' : ''; ?>"
				role="dialog"
				aria-modal="true"
				aria-labelledby="akb-welcome-title"
				aria-describedby="akb-welcome-desc"
				hidden
			>
				<div class="akb-welcome__backdrop" data-akb-welcome-close></div>
				<div class="akb-welcome__card" role="document">
					<div class="akb-welcome__drag-handle" aria-hidden="true"></div>
					<button
						type="button"
						class="akb-welcome__close"
						aria-label="Cerrar ventana promocional"
						data-akb-welcome-close
					>
						<span aria-hidden="true">&times;</span>
					</button>

					<div class="akb-welcome__header">
						<img
							src="<?php echo esc_url( $logo_url ); ?>"
							alt="Akibara"
							class="akb-welcome__logo"
							width="130"
							height="56"
							decoding="async"
							fetchpriority="auto"
						>
						<span class="akb-welcome__kicker"><?php echo esc_html( $copies['kicker'] ); ?></span>
					</div>

					<div class="akb-welcome__body" data-akb-welcome-step="promo">
						<h2 id="akb-welcome-title" class="akb-welcome__title">
							<?php echo esc_html( $copies['heading'] ); ?>
						</h2>

						<p id="akb-welcome-desc" class="akb-welcome__subtitle">
							<?php echo esc_html( $copies['subtitle'] ); ?>
						</p>

						<?php if ( '' !== $promo_code ) : ?>
						<div class="akb-welcome__code-box">
							<span class="akb-welcome__code-label">CÓDIGO PROMOCIONAL</span>
							<div class="akb-welcome__code-row">
								<code class="akb-welcome__code-value" data-akb-welcome-code><?php echo esc_html( $promo_code ); ?></code>
								<button type="button" class="akb-welcome__copy-btn" data-akb-welcome-copy>
									Copiar código
								</button>
							</div>
							<span class="akb-welcome__code-meta">
								<?php echo esc_html( $copies['legal'] ); ?>
							</span>
						</div>
						<?php elseif ( ! empty( $copies['legal'] ) ) : ?>
						<div class="akb-welcome__promo-badges">
							<span class="akb-welcome__promo-badge">✓ Descuento automático en el carrito</span>
							<span class="akb-welcome__promo-badge">✓ Sin necesidad de cupones</span>
						</div>
						<p class="akb-welcome__promo-meta">
							<?php echo esc_html( $copies['legal'] ); ?>
						</p>
						<?php endif; ?>

						<a href="<?php echo esc_url( $shop_url ); ?>" class="akb-welcome__shop-btn" data-akb-welcome-shop-link>
							<?php echo esc_html( $copies['cta'] ); ?> →
						</a>

						<button type="button" class="akb-welcome__dismiss" data-akb-welcome-close>
							Cerrar y seguir vitrineando
						</button>
					</div>
				</div>
			</div>
			<?php
		} else {
			// Modo bienvenida primera compra.
			$copies  = Settings::copies();
			$min_fmt = Settings::min_amount_formatted();
		$days     = Settings::validity_days();

		$prefill_name  = '';
		$prefill_email = '';
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			if ( $user instanceof \WP_User ) {
				$prefill_name  = ! empty( $user->first_name ) ? (string) $user->first_name : (string) $user->display_name;
				$prefill_email = (string) $user->user_email;
			}
		}
		?>
		<div
			id="akb-welcome-modal"
			class="akb-welcome"
			role="dialog"
			aria-modal="true"
			aria-labelledby="akb-welcome-title"
			aria-describedby="akb-welcome-desc"
			hidden
		>
			<div class="akb-welcome__backdrop" data-akb-welcome-close></div>
			<div class="akb-welcome__card" role="document">
				<div class="akb-welcome__drag-handle" aria-hidden="true"></div>
				<button
					type="button"
					class="akb-welcome__close"
					aria-label="Cerrar ventana de bienvenida"
					data-akb-welcome-close
				>
					<span aria-hidden="true">&times;</span>
				</button>

				<div class="akb-welcome__header">
					<img
						src="<?php echo esc_url( $logo_url ); ?>"
						alt="Akibara"
						class="akb-welcome__logo"
						width="130"
						height="56"
						decoding="async"
						fetchpriority="auto"
					>
					<span class="akb-welcome__kicker"><?php echo esc_html( $copies['kicker'] ); ?></span>
				</div>

				<div class="akb-welcome__body" data-akb-welcome-step="form">
					<div class="akb-welcome__badge-row" aria-hidden="true">
						<span class="akb-welcome__pct-badge">-<?php echo (int) $pct; ?>%</span>
						<span class="akb-welcome__pct-tag">CÓDIGO ÚNICO · <?php echo (int) $days; ?> <?php echo 1 === $days ? 'DÍA' : 'DÍAS'; ?></span>
					</div>

					<h2 id="akb-welcome-title" class="akb-welcome__title">
						<?php echo esc_html( $copies['heading'] ); ?>
					</h2>

					<p id="akb-welcome-desc" class="akb-welcome__subtitle">
						<?php echo esc_html( $copies['subtitle'] ); ?>
					</p>

					<form class="akb-welcome__form" data-akb-welcome-form novalidate>
						<?php echo Honeypot::field_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en Honeypot::field_html(). ?>

						<div class="akb-welcome__field">
							<label for="akb-welcome-name" class="akb-welcome__label">Tu nombre</label>
							<input
								id="akb-welcome-name"
								type="text"
								name="name"
								class="akb-welcome__input"
								placeholder="Ej: Alejandro"
								autocomplete="given-name"
								maxlength="60"
								value="<?php echo esc_attr( $prefill_name ); ?>"
								required
							>
						</div>

						<div class="akb-welcome__field">
							<label for="akb-welcome-correo" class="akb-welcome__label">Tu correo</label>
							<input
								id="akb-welcome-correo"
								type="email"
								name="email"
								class="akb-welcome__input"
								placeholder="tu@correo.cl"
								autocomplete="email"
								inputmode="email"
								maxlength="100"
								value="<?php echo esc_attr( $prefill_email ); ?>"
								required
							>
						</div>

						<?php if ( Settings::turnstile_enabled() ) : ?>
							<div class="akb-welcome__turnstile" data-akb-welcome-turnstile></div>
						<?php endif; ?>

						<div class="akb-welcome__msg" data-akb-welcome-msg role="status" aria-live="polite" hidden></div>

						<button type="submit" class="akb-welcome__submit" data-akb-welcome-submit>
							<span class="akb-welcome__submit-label"><?php echo esc_html( $copies['cta'] ); ?></span>
						</button>

						<p class="akb-welcome__trust-note">
							<?php echo esc_html( $copies['trust_note'] ); ?>
						</p>
					</form>

					<p class="akb-welcome__legal">
						<?php echo esc_html( $copies['legal'] ); ?>
					</p>
				</div>

				<div class="akb-welcome__success" data-akb-welcome-step="success" hidden>
					<div data-akb-welcome-issued-box>
						<span class="akb-welcome__success-badge">¡CÓDIGO ACTIVADO!</span>
						<h3 class="akb-welcome__success-title">
							Aquí tienes tu <?php echo (int) $pct; ?>% para tu primera compra
						</h3>
						<p class="akb-welcome__success-text" data-akb-welcome-success-msg>
							También te enviamos una copia a tu correo para que no se te pierda.
						</p>

						<div class="akb-welcome__code-box">
							<span class="akb-welcome__code-label">TU CÓDIGO PERSONAL</span>
							<div class="akb-welcome__code-row">
								<code class="akb-welcome__code-value" data-akb-welcome-code>HOLAAKIB</code>
								<button type="button" class="akb-welcome__copy-btn" data-akb-welcome-copy>
									Copiar código
								</button>
							</div>
							<span class="akb-welcome__code-meta">
								Compra mínima <?php echo esc_html( $min_fmt ); ?> · Vence el <strong data-akb-welcome-expires>—</strong>
							</span>
						</div>

						<a href="<?php echo esc_url( home_url( '/tienda/' ) ); ?>" class="akb-welcome__shop-btn" data-akb-welcome-shop-link>
							Aplicar descuento e ir a la tienda →
						</a>

						<button type="button" class="akb-welcome__dismiss" data-akb-welcome-close>
							Cerrar y seguir vitrineando
						</button>
					</div>

					<div class="akb-welcome__existing-buyer" data-akb-welcome-existing-buyer hidden>
						<span class="akb-welcome__success-badge">¡SUSCRIPCIÓN CONFIRMADA!</span>
						<h3 class="akb-welcome__success-title">
							¡Bienvenido a la comunidad Akibara!
						</h3>
						<p class="akb-welcome__success-text" data-akb-welcome-existing-buyer-msg>
							Ya registramos tu correo para recibir novedades, preventas exclusivas y campañas especiales.
						</p>
						<a href="<?php echo esc_url( home_url( '/mi-cuenta/' ) ); ?>" class="akb-welcome__shop-btn">
							Ver mis Akibara Puntos →
						</a>

						<button type="button" class="akb-welcome__dismiss" data-akb-welcome-close>
							Cerrar ventana
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
		}
		?>

		<?php if ( Settings::launcher_enabled() ) : ?>
		<aside
			id="akb-welcome-launcher"
			class="akb-welcome-launcher<?php echo 'promo' === $mode ? ' is-promo' : ''; ?>"
			aria-label="<?php echo 'promo' === $mode ? 'Promoción disponible' : 'Cupón de primera compra'; ?>"
			hidden
		>
			<button
				type="button"
				class="akb-welcome-launcher__btn"
				aria-label="<?php echo 'promo' === $mode ? 'Abrir promoción disponible' : 'Abrir cupón 10% primera compra'; ?>"
				data-akb-welcome-launcher
			>
				<span class="akb-welcome-launcher__badge" aria-hidden="true"><?php echo 'promo' === $mode ? '🏷️ PROMO' : '-' . (int) $pct . '%'; ?></span>
				<span class="akb-welcome-launcher__text"><?php echo 'promo' === $mode ? ( ! empty( $promo_code ) ? esc_html( $promo_code ) : 'VER OFERTAS' ) : 'CUPÓN PRIMERA COMPRA'; ?></span>
			</button>
			<button
				type="button"
				class="akb-welcome-launcher__close"
				data-akb-launcher-dismiss
				aria-label="Cerrar recordatorio"
				title="Cerrar recordatorio"
			>
				<span aria-hidden="true">&times;</span>
			</button>
		</aside>
		<?php endif; ?>
		<?php
	}

	/**
	 * Protege los selectores dinámicos frente a la poda UCSS de LiteSpeed.
	 *
	 * @param array<int, string> $selectors Selectores en lista blanca.
	 * @return array<int, string>
	 */
	public function allowlist_ucss_selectors( array $selectors ): array {
		$selectors[] = '.akb-welcome';
		$selectors[] = '.akb-welcome.is-open';
		$selectors[] = '.akb-welcome.is-promo';
		$selectors[] = '.akb-welcome__drag-handle';
		$selectors[] = '.akb-welcome__msg.is-error';
		$selectors[] = '.akb-welcome__msg.is-ok';
		$selectors[] = '.akb-welcome__submit.is-loading';
		$selectors[] = '.akb-welcome__copy-btn.is-copied';
		$selectors[] = '.akb-welcome__existing-buyer';
		$selectors[] = '.akb-welcome-launcher';
		$selectors[] = '.akb-welcome-launcher.is-visible';
		$selectors[] = '.akb-welcome-launcher.is-promo';
		$selectors[] = '.akb-welcome-launcher__btn';
		$selectors[] = '.akb-welcome-launcher__close';

		return array_values( array_unique( $selectors ) );
	}

	/**
	 * Resuelve el logo oficial de Akibara para el encabezado del popup.
	 */
	private function resolve_brand_logo_url(): string {
		$custom_logo_id = function_exists( 'get_theme_mod' ) ? (int) get_theme_mod( 'custom_logo' ) : 0;
		if ( $custom_logo_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
			$url = wp_get_attachment_image_url( $custom_logo_id, 'medium' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		if ( function_exists( 'get_stylesheet_directory_uri' ) && function_exists( 'get_stylesheet_directory' ) && file_exists( get_stylesheet_directory() . '/assets/img/logo-akibara.webp' ) ) {
			return get_stylesheet_directory_uri() . '/assets/img/logo-akibara.webp';
		}

		return 'https://akibara.cl/wp-content/uploads/2025/09/LOGOS-BLANCO-WEB.png';
	}
}
