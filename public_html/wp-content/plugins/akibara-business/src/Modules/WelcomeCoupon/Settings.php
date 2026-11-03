<?php
/**
 * Settings — configuración del banner/popup de primera compra (10% bienvenida)
 * y del modo promocional estático (campañas como Halloween, Cyber o Aniversario).
 *
 * Centraliza las opciones del módulo en `Akibara → Panel → Venta → Primera compra`:
 *   1. Captación Primera Compra (con correo y Brevo):
 *      - Interruptor maestro (`akb_welcome_coupon_enabled`)
 *      - Reglas comerciales: porcentaje (10%), compra mínima ($25.000 CLP), vigencia (3 días)
 *      - Disparadores y frecuencia: espera en segundos (20s), scroll (45%), enfriamiento tras cierre (30 días)
 *      - Superficies permitidas: portada, catálogo/tienda, ficha de producto, landings de serie
 *      - Textos editables con marcadores dinámicos `{pct}`, `{min}` y `{dias}`
 *   2. Popup Promocional Estático (sin pedir correo ni Brevo, para todo cliente):
 *      - Interruptor (`akb_welcome_promo_enabled`) y código estático (`akb_welcome_promo_code`)
 *      - Audiencia (`all` = todos los clientes incluidos recurrentes; `guests` = solo nuevos)
 *      - Superficies, espera, enfriamiento y textos propios con marcador `{codigo}`
 *   3. Control de cookies sin tocar código (`akb_welcome_coupon_cookie_epoch` / `akb_welcome_coupon_reset_cookies`)
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\WelcomeCoupon;

use Akibara\Business\Utilities\Text;
use Akibara\Business\Modules\DiscountCopy\DiscountCopy;
use Akibara\ProviderAccess\Access;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.120.0
 */
final class Settings {

	public const PANEL_TAB_ID = 'welcome_coupon';

	public const OPTION_ENABLED          = 'akb_welcome_coupon_enabled';
	public const OPTION_DISCOUNT_PCT     = 'akb_welcome_coupon_pct';
	public const OPTION_MIN_AMOUNT       = 'akb_welcome_coupon_min_amount';
	public const OPTION_VALIDITY_DAYS    = 'akb_welcome_coupon_validity_days';
	public const OPTION_DELAY_SECONDS    = 'akb_welcome_coupon_delay_seconds';
	public const OPTION_SCROLL_PCT       = 'akb_welcome_coupon_scroll_pct';
	public const OPTION_DISMISS_DAYS     = 'akb_welcome_coupon_dismiss_days';
	public const OPTION_EXIT_INTENT      = 'akb_welcome_coupon_exit_intent';
	public const OPTION_LAUNCHER         = 'akb_welcome_coupon_launcher';
	public const OPTION_SHOW_HOME        = 'akb_welcome_coupon_show_home';
	public const OPTION_SHOW_CATALOG     = 'akb_welcome_coupon_show_catalog';
	public const OPTION_SHOW_PRODUCT     = 'akb_welcome_coupon_show_product';
	public const OPTION_SHOW_SERIES      = 'akb_welcome_coupon_show_series';
	public const OPTION_COOKIE_EPOCH     = 'akb_welcome_coupon_cookie_epoch';
	public const OPTION_RESET_COOKIES    = 'akb_welcome_coupon_reset_cookies';

	public const OPTION_TURNSTILE_ENABLED = 'akb_welcome_turnstile_enabled';
	public const OPTION_TURNSTILE_SITEKEY = 'akb_welcome_turnstile_sitekey';
	public const OPTION_TURNSTILE_SECRET  = 'akb_welcome_turnstile_secret';

	public const OPTION_COPY_KICKER   = 'akb_copy_welcome_kicker';
	public const OPTION_COPY_HEADING  = 'akb_copy_welcome_heading';
	public const OPTION_COPY_SUBTITLE = 'akb_copy_welcome_subtitle';
	public const OPTION_COPY_CTA      = 'akb_copy_welcome_cta';
	public const OPTION_COPY_DISMISS  = 'akb_copy_welcome_dismiss';
	public const OPTION_COPY_LEGAL    = 'akb_copy_welcome_legal';

	public const OPTION_PROMO_ENABLED       = 'akb_welcome_promo_enabled';
	public const OPTION_PROMO_FOLLOW_DISCOUNT = 'akb_welcome_promo_follow_discount';
	public const OPTION_PROMO_CODE          = 'akb_welcome_promo_code';
	public const OPTION_PROMO_URL           = 'akb_welcome_promo_url';
	public const OPTION_PROMO_AUDIENCE      = 'akb_welcome_promo_audience';
	public const OPTION_PROMO_DELAY_SECONDS = 'akb_welcome_promo_delay_seconds';
	public const OPTION_PROMO_DISMISS_DAYS  = 'akb_welcome_promo_dismiss_days';
	public const OPTION_PROMO_SHOW_HOME     = 'akb_welcome_promo_show_home';
	public const OPTION_PROMO_SHOW_CATALOG  = 'akb_welcome_promo_show_catalog';
	public const OPTION_PROMO_SHOW_PRODUCT  = 'akb_welcome_promo_show_product';
	public const OPTION_PROMO_SHOW_SERIES   = 'akb_welcome_promo_show_series';

	public const DEFAULT_PROMO_URL = '/tienda/';

	public const OPTION_COPY_PROMO_KICKER   = 'akb_copy_welcome_promo_kicker';
	public const OPTION_COPY_PROMO_HEADING  = 'akb_copy_welcome_promo_heading';
	public const OPTION_COPY_PROMO_SUBTITLE = 'akb_copy_welcome_promo_subtitle';
	public const OPTION_COPY_PROMO_CTA      = 'akb_copy_welcome_promo_cta';
	public const OPTION_COPY_PROMO_LEGAL    = 'akb_copy_welcome_promo_legal';

	public const DEFAULT_DISCOUNT_PCT  = 10;
	public const DEFAULT_MIN_AMOUNT    = 25000;
	public const DEFAULT_VALIDITY_DAYS = 3;
	public const DEFAULT_DELAY_SECONDS = 20;
	public const DEFAULT_SCROLL_PCT    = 45;
	public const DEFAULT_DISMISS_DAYS  = 30;
	public const DEFAULT_PROMO_DELAY   = 10;
	public const DEFAULT_PROMO_DISMISS = 7;

	public const DEFAULT_COPY_KICKER     = 'BIENVENIDA AKIBARA · PRIMERA COMPRA';
	public const DEFAULT_COPY_HEADING    = '¿NUEVO EN AKIBARA? LLÉVATE UN {pct}% EN TU PRIMERA COMPRA';
	public const DEFAULT_COPY_SUBTITLE   = 'Válido en todo nuestro catálogo. Recibe tu código al instante y sé el primero en enterarte de promociones preferenciales y cuándo llega el próximo tomo de tu serie favorita.';
	public const DEFAULT_COPY_CTA        = 'Quiero mi {pct}% de descuento';
	public const DEFAULT_COPY_DISMISS    = 'No, gracias · prefiero pagar precio normal';
	public const DEFAULT_COPY_TRUST_NOTE = '🔒 Cero spam. Descuentos preferenciales y avisos de llegada de tus series favoritas. Cancela en 1 clic cuando quieras.';
	public const DEFAULT_COPY_LEGAL      = 'Compra mínima {min} en productos de todo el catálogo · Vigencia {dias} días · 1 uso por persona (RUT, celular y correo). Solo aplica sobre el precio base normal: si un tomo o pack ya supera el {pct}%, mantenemos el descuento que más te conviene.';

	public const OPTION_COPY_TRUST_NOTE  = 'akb_copy_welcome_trust_note';

	public const DEFAULT_COPY_PROMO_KICKER   = 'CAMPAÑA ESPECIAL AKIBARA';
	public const DEFAULT_COPY_PROMO_HEADING  = 'PROMOCIONES AKIBARA';
	public const DEFAULT_COPY_PROMO_SUBTITLE = 'Revisa los descuentos vigentes y sus condiciones en el catálogo. Las promociones automáticas no requieren código.';
	public const DEFAULT_COPY_PROMO_CTA      = 'Ver promociones';
	public const DEFAULT_COPY_PROMO_LEGAL    = 'Consulta el precio y la disponibilidad de cada producto. Los beneficios pueden variar según la campaña.';

	public const DEFAULT_TURNSTILE_ENABLED = 'yes';
	public const DEFAULT_TURNSTILE_SITEKEY = '0x4AAAAAADi6gqq-BooQ5DZ_';
	public const DEFAULT_TURNSTILE_SECRET  = '';

	/**
	 * Registra los sanitizadores de opciones del panel.
	 */
	public function register(): void {
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::OPTION_DISCOUNT_PCT,
			array( $this, 'sanitize_discount_pct' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::OPTION_MIN_AMOUNT,
			array( $this, 'sanitize_min_amount' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::OPTION_VALIDITY_DAYS,
			array( $this, 'sanitize_validity_days' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::OPTION_DELAY_SECONDS,
			array( $this, 'sanitize_delay_seconds' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::OPTION_SCROLL_PCT,
			array( $this, 'sanitize_scroll_pct' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::OPTION_DISMISS_DAYS,
			array( $this, 'sanitize_dismiss_days' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::OPTION_PROMO_CODE,
			array( $this, 'sanitize_promo_code' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::OPTION_PROMO_URL,
			array( $this, 'sanitize_promo_url' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::OPTION_PROMO_AUDIENCE,
			array( $this, 'sanitize_promo_audience' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::OPTION_PROMO_DELAY_SECONDS,
			array( $this, 'sanitize_delay_seconds' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::OPTION_PROMO_DISMISS_DAYS,
			array( $this, 'sanitize_dismiss_days' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::OPTION_RESET_COOKIES,
			array( $this, 'sanitize_reset_cookies' ),
			10,
			3
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::OPTION_TURNSTILE_SITEKEY,
			array( $this, 'sanitize_turnstile_key' ),
			10,
			3
		);

		foreach ( array_keys( self::copy_defaults() ) as $option ) {
			add_filter(
				'woocommerce_admin_settings_sanitize_option_' . $option,
				array( $this, 'sanitize_copy_option' ),
				10,
				3
			);
		}
	}

	/**
	 * Mapa de opciones de texto y sus valores de fábrica.
	 *
	 * @return array<string, string>
	 */
	public static function copy_defaults(): array {
		return array(
			self::OPTION_COPY_KICKER         => self::DEFAULT_COPY_KICKER,
			self::OPTION_COPY_HEADING        => self::DEFAULT_COPY_HEADING,
			self::OPTION_COPY_SUBTITLE       => self::DEFAULT_COPY_SUBTITLE,
			self::OPTION_COPY_CTA            => self::DEFAULT_COPY_CTA,
			self::OPTION_COPY_DISMISS        => self::DEFAULT_COPY_DISMISS,
			self::OPTION_COPY_LEGAL          => self::DEFAULT_COPY_LEGAL,
			self::OPTION_COPY_PROMO_KICKER   => self::DEFAULT_COPY_PROMO_KICKER,
			self::OPTION_COPY_PROMO_HEADING  => self::DEFAULT_COPY_PROMO_HEADING,
			self::OPTION_COPY_PROMO_SUBTITLE => self::DEFAULT_COPY_PROMO_SUBTITLE,
			self::OPTION_COPY_PROMO_CTA      => self::DEFAULT_COPY_PROMO_CTA,
			self::OPTION_COPY_PROMO_LEGAL    => self::DEFAULT_COPY_PROMO_LEGAL,
		);
	}

	public const PREVIEW_QUERY_ARG = 'akb_preview_welcome';
	public const PREVIEW_COOKIE    = 'akb_preview_welcome';

	/**
	 * ¿Está activa la vista previa privada para pruebas en producción?
	 */
	public static function is_preview(): bool {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['akb_preview_welcome'] ) || isset( $_GET['akb_welcome_preview'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$arg = (string) ( $_GET['akb_preview_welcome'] ?? $_GET['akb_welcome_preview'] );
			return '1' === $arg || 'yes' === $arg;
		}
		if ( isset( $_COOKIE['akb_preview_welcome'] ) || isset( $_COOKIE['akb_welcome_preview'] ) ) {
			$cookie = (string) ( $_COOKIE['akb_preview_welcome'] ?? $_COOKIE['akb_welcome_preview'] );
			return '1' === $cookie || 'yes' === $cookie;
		}
		return false;
	}

	/**
	 * ¿Está activo el popup/banner de primera compra?
	 *
	 * La vista previa requiere capacidad de gestión y sólo habilita la interfaz.
	 * Los consumidores que emiten cupones deben pasar allow_preview=false.
	 */
	public static function is_enabled( bool $allow_preview = true ): bool {
		if ( $allow_preview && self::is_preview() ) {
			return true;
		}
		$val = get_option( 'akb_welcome_coupon_enabled', 'yes' );
		return (bool) apply_filters( 'akb_welcome_coupon_enabled', 'yes' === $val );
	}

	/**
	 * ¿Está activo el popup promocional de campaña (informativo o con código, ej. Halloween / Cyber)?
	 */
	public static function is_promo_enabled(): bool {
		if ( 'yes' !== get_option( self::OPTION_PROMO_ENABLED, 'no' ) ) {
			return false;
		}
		// Los códigos heredados tienen su propia vigencia. La información automática
		// usa exactamente la misma autoridad que el precio y la franja superior.
		return '' !== self::promo_code() || ! self::promo_follows_discount() || DiscountCopy::campaign_active();
	}

	public static function promo_follows_discount(): bool {
		return 'yes' === get_option( self::OPTION_PROMO_FOLLOW_DISCOUNT, 'yes' );
	}

	/**
	 * Código promocional estático en mayúsculas (opcional: si está vacío, el popup es puramente informativo).
	 */
	public static function promo_code(): string {
		$raw  = (string) get_option( self::OPTION_PROMO_CODE, '' );
		$code = strtoupper( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', trim( $raw ) ) );
		return substr( $code, 0, 40 );
	}

	/**
	 * URL de destino del botón CTA en modo promocional (default /tienda/).
	 */
	public static function promo_url(): string {
		$raw = (string) get_option( self::OPTION_PROMO_URL, self::DEFAULT_PROMO_URL );
		$url = trim( $raw );
		return '' !== $url ? $url : self::DEFAULT_PROMO_URL;
	}

	/**
	 * Audiencia del popup promocional estático: `all` (todo cliente) o `guests` (solo sin compras).
	 */
	public static function promo_audience(): string {
		$raw = (string) get_option( 'akb_welcome_promo_audience', 'all' );
		return 'guests' === $raw ? 'guests' : 'all';
	}

	/**
	 * Segundos de espera del popup promocional estático (0..120, default 10).
	 */
	public static function promo_delay_seconds(): int {
		$raw = get_option( 'akb_welcome_promo_delay_seconds', self::DEFAULT_PROMO_DELAY );
		$sec = is_numeric( $raw ) ? (int) $raw : self::DEFAULT_PROMO_DELAY;
		return max( 0, min( 120, $sec ) );
	}

	/**
	 * Días de enfriamiento tras cerrar el popup promocional estático (1..365, default 7).
	 */
	public static function promo_dismiss_days(): int {
		$raw  = get_option( 'akb_welcome_promo_dismiss_days', self::DEFAULT_PROMO_DISMISS );
		$days = is_numeric( $raw ) ? (int) $raw : self::DEFAULT_PROMO_DISMISS;
		return max( 1, min( 365, $days ) );
	}

	/**
	 * Superficies habilitadas para el popup promocional estático.
	 */
	public static function promo_show_on_home(): bool {
		return 'yes' === get_option( 'akb_welcome_promo_show_home', 'yes' );
	}

	public static function promo_show_on_catalog(): bool {
		return 'yes' === get_option( 'akb_welcome_promo_show_catalog', 'yes' );
	}

	public static function promo_show_on_product(): bool {
		return 'yes' === get_option( 'akb_welcome_promo_show_product', 'yes' );
	}

	public static function promo_show_on_series(): bool {
		return 'yes' === get_option( 'akb_welcome_promo_show_series', 'yes' );
	}

	/**
	 * Versión de cookie (`cookie_epoch`) para invalidar cierres previos desde el admin.
	 */
	public static function cookie_epoch(): int {
		$raw   = get_option( 'akb_welcome_coupon_cookie_epoch', 1 );
		$epoch = is_numeric( $raw ) ? (int) $raw : 1;
		return max( 1, $epoch );
	}

	/**
	 * Porcentaje de descuento (1..50, default 10).
	 */
	public static function discount_pct(): int {
		$raw = get_option( 'akb_welcome_coupon_pct', self::DEFAULT_DISCOUNT_PCT );
		$pct = is_numeric( $raw ) ? (int) $raw : self::DEFAULT_DISCOUNT_PCT;
		return max( 1, min( 50, $pct ) );
	}

	/**
	 * Compra mínima en CLP (default 25000).
	 */
	public static function min_amount(): int {
		$raw = get_option( 'akb_welcome_coupon_min_amount', self::DEFAULT_MIN_AMOUNT );
		$min = is_numeric( $raw ) ? (int) $raw : self::DEFAULT_MIN_AMOUNT;
		return max( 0, min( 500000, $min ) );
	}

	/**
	 * Compra mínima formateada en pesos chilenos (ej. "$25.000").
	 */
	public static function min_amount_formatted(): string {
		$amount = self::min_amount();
		if ( function_exists( 'number_format_i18n' ) ) {
			return '$' . number_format_i18n( $amount, 0 );
		}
		return '$' . number_format( (float) $amount, 0, ',', '.' );
	}

	/**
	 * Días de vigencia del cupón (1..30, default 3).
	 */
	public static function validity_days(): int {
		$raw  = get_option( 'akb_welcome_coupon_validity_days', self::DEFAULT_VALIDITY_DAYS );
		$days = is_numeric( $raw ) ? (int) $raw : self::DEFAULT_VALIDITY_DAYS;
		return max( 1, min( 30, $days ) );
	}

	/**
	 * Segundos de espera antes de mostrar el popup (0..120, default 20).
	 */
	public static function delay_seconds(): int {
		$raw = get_option( 'akb_welcome_coupon_delay_seconds', self::DEFAULT_DELAY_SECONDS );
		$sec = is_numeric( $raw ) ? (int) $raw : self::DEFAULT_DELAY_SECONDS;
		return max( 0, min( 120, $sec ) );
	}

	/**
	 * Porcentaje de scroll para disparar el popup (10..95, default 45).
	 */
	public static function scroll_pct(): int {
		$raw = get_option( 'akb_welcome_coupon_scroll_pct', self::DEFAULT_SCROLL_PCT );
		$pct = is_numeric( $raw ) ? (int) $raw : self::DEFAULT_SCROLL_PCT;
		return max( 10, min( 95, $pct ) );
	}

	/**
	 * Días de enfriamiento tras cerrar el popup sin suscribirse (1..365, default 30).
	 */
	public static function dismiss_days(): int {
		$raw  = get_option( 'akb_welcome_coupon_dismiss_days', self::DEFAULT_DISMISS_DAYS );
		$days = is_numeric( $raw ) ? (int) $raw : self::DEFAULT_DISMISS_DAYS;
		return max( 1, min( 365, $days ) );
	}

	/**
	 * ¿Disparar el modal ante intención de salida (Exit Intent)?
	 */
	public static function exit_intent_enabled(): bool {
		return 'yes' === get_option( self::OPTION_EXIT_INTENT, 'yes' );
	}

	/**
	 * ¿Mostrar distintivo flotante discreto tras cerrar el popup?
	 */
	public static function launcher_enabled(): bool {
		return 'yes' === get_option( self::OPTION_LAUNCHER, 'yes' );
	}

	/**
	 * ¿Mostrar en la portada?
	 */
	public static function show_on_home(): bool {
		return 'yes' === get_option( 'akb_welcome_coupon_show_home', 'yes' );
	}

	/**
	 * ¿Mostrar en tienda, categorías y archivos del catálogo?
	 */
	public static function show_on_catalog(): bool {
		return 'yes' === get_option( 'akb_welcome_coupon_show_catalog', 'yes' );
	}

	/**
	 * ¿Mostrar en fichas de producto?
	 */
	public static function show_on_product(): bool {
		return 'yes' === get_option( 'akb_welcome_coupon_show_product', 'yes' );
	}

	/**
	 * ¿Mostrar en landings de serie (`pa_serie`)?
	 */
	public static function show_on_series(): bool {
		return 'yes' === get_option( 'akb_welcome_coupon_show_series', 'yes' );
	}

	/**
	 * ¿Está activa la protección Cloudflare Turnstile en el popup?
	 */
	public static function turnstile_enabled(): bool {
		$val = get_option( self::OPTION_TURNSTILE_ENABLED, self::DEFAULT_TURNSTILE_ENABLED );
		return 'yes' === $val || true === $val || 1 === $val || '1' === $val;
	}

	/**
	 * Clave pública de Turnstile (Sitekey).
	 */
	public static function turnstile_sitekey(): string {
		$saved = (string) get_option( self::OPTION_TURNSTILE_SITEKEY, '' );
		return '' !== $saved ? $saved : self::DEFAULT_TURNSTILE_SITEKEY;
	}

	/**
	 * Clave secreta de Turnstile (Secret Key).
	 */
	public static function turnstile_secret(): string {
		return class_exists( Access::class ) ? Access::get( 'welcome_turnstile_secret' ) : '';
	}

	/**
	 * Textos ya interpolados con `{pct}`, `{min}` y `{dias}` para Primera Compra.
	 *
	 * @return array{kicker:string,heading:string,subtitle:string,cta:string,dismiss:string,legal:string}
	 */
	public static function copies(): array {
		$pct  = (string) self::discount_pct();
		$min  = self::min_amount_formatted();
		$dias = (string) self::validity_days();

		$replace = static function ( string $text ) use ( $pct, $min, $dias ): string {
			return strtr(
				$text,
				array(
					'{pct}'  => $pct,
					'{min}'  => $min,
					'{dias}' => $dias,
				)
			);
		};

		return array(
			'kicker'     => $replace( Text::one_line_option( 'akb_copy_welcome_kicker', self::DEFAULT_COPY_KICKER ) ),
			'heading'    => $replace( Text::one_line_option( 'akb_copy_welcome_heading', self::DEFAULT_COPY_HEADING ) ),
			'subtitle'   => $replace( Text::one_line_option( 'akb_copy_welcome_subtitle', self::DEFAULT_COPY_SUBTITLE ) ),
			'cta'        => $replace( Text::one_line_option( 'akb_copy_welcome_cta', self::DEFAULT_COPY_CTA ) ),
			'dismiss'    => $replace( Text::one_line_option( 'akb_copy_welcome_dismiss', self::DEFAULT_COPY_DISMISS ) ),
			'trust_note' => $replace( Text::one_line_option( self::OPTION_COPY_TRUST_NOTE, self::DEFAULT_COPY_TRUST_NOTE ) ),
			'legal'      => $replace( Text::one_line_option( 'akb_copy_welcome_legal', self::DEFAULT_COPY_LEGAL ) ),
		);
	}

	/**
	 * Textos ya interpolados con `{codigo}` para el Popup Promocional Estático.
	 *
	 * @return array{kicker:string,heading:string,subtitle:string,cta:string,dismiss:string,legal:string}
	 */
	public static function promo_copies(): array {
		$code = self::promo_code();

		$defaults = array(
			'heading'  => '' !== $code ? '¡USA EL CÓDIGO {codigo} EN TU COMPRA!' : self::DEFAULT_COPY_PROMO_HEADING,
			'subtitle' => '' !== $code ? 'Activa el beneficio directo en tu carrito sin necesidad de registro adicional.' : self::DEFAULT_COPY_PROMO_SUBTITLE,
			'cta'      => '' !== $code ? 'Aplicar código a mi carrito' : self::DEFAULT_COPY_PROMO_CTA,
			'legal'    => '' !== $code ? 'Promoción por tiempo limitado sujeta a las condiciones del cupón en el carrito.' : self::DEFAULT_COPY_PROMO_LEGAL,
		);
		$legacy = array(
			'¡USA EL CÓDIGO {codigo} EN TU COMPRA!',
			'Activa el beneficio directo en tu carrito sin necesidad de registro adicional.',
			'Aplicar código a mi carrito',
			'Promoción por tiempo limitado sujeta a las condiciones del cupón en el carrito.',
			'¡DESCUENTOS AUTOMÁTICOS EN TODA LA TIENDA!',
			'Aprovecha precios especiales en mangas seleccionados. Los descuentos ya están aplicados en toda la tienda; no necesitas códigos ni cupones.',
			'Descuento directo aplicado en el carrito y fichas de producto · Válido por tiempo limitado.',
		);
		$result = array( 'kicker' => DiscountCopy::resolve_in( Text::one_line_option( self::OPTION_COPY_PROMO_KICKER, self::DEFAULT_COPY_PROMO_KICKER ) ) );
		foreach ( $defaults as $key => $fallback ) {
			$text = Text::one_line_option( 'akb_copy_welcome_promo_' . $key, $fallback );
			if ( '' === $code && in_array( $text, $legacy, true ) ) {
				$text = $fallback;
			}
			$result[ $key ] = DiscountCopy::resolve_in( strtr( $text, array( '{codigo}' => $code ) ) );
		}
		$result['dismiss'] = 'Cerrar y seguir vitrineando';
		return $result;
	}

	/**
	 * Sanitizadores de campos numéricos y de texto.
	 *
	 * @param mixed               $value     Valor preprocesado.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo.
	 */
	public function sanitize_discount_pct( $value, array $option = array(), $raw_value = null ): int {
		unset( $option );
		$candidate = null !== $raw_value ? $raw_value : $value;
		$pct       = is_numeric( $candidate ) ? (int) $candidate : self::DEFAULT_DISCOUNT_PCT;
		return max( 1, min( 50, $pct ) );
	}

	/**
	 * @param mixed               $value     Valor preprocesado.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo.
	 */
	public function sanitize_min_amount( $value, array $option = array(), $raw_value = null ): int {
		unset( $option );
		$candidate = null !== $raw_value ? $raw_value : $value;
		$clean     = is_scalar( $candidate ) ? preg_replace( '/[^\d]/', '', (string) $candidate ) : '';
		$min       = ( is_string( $clean ) && '' !== $clean ) ? (int) $clean : self::DEFAULT_MIN_AMOUNT;
		return max( 0, min( 500000, $min ) );
	}

	/**
	 * @param mixed               $value     Valor preprocesado.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo.
	 */
	public function sanitize_validity_days( $value, array $option = array(), $raw_value = null ): int {
		unset( $option );
		$candidate = null !== $raw_value ? $raw_value : $value;
		$days      = is_numeric( $candidate ) ? (int) $candidate : self::DEFAULT_VALIDITY_DAYS;
		return max( 1, min( 30, $days ) );
	}

	/**
	 * @param mixed               $value     Valor preprocesado.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo.
	 */
	public function sanitize_delay_seconds( $value, array $option = array(), $raw_value = null ): int {
		unset( $option );
		$candidate = null !== $raw_value ? $raw_value : $value;
		$sec       = is_numeric( $candidate ) ? (int) $candidate : self::DEFAULT_DELAY_SECONDS;
		return max( 0, min( 120, $sec ) );
	}

	/**
	 * @param mixed               $value     Valor preprocesado.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo.
	 */
	public function sanitize_scroll_pct( $value, array $option = array(), $raw_value = null ): int {
		unset( $option );
		$candidate = null !== $raw_value ? $raw_value : $value;
		$pct       = is_numeric( $candidate ) ? (int) $candidate : self::DEFAULT_SCROLL_PCT;
		return max( 10, min( 95, $pct ) );
	}

	/**
	 * @param mixed               $value     Valor preprocesado.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo.
	 */
	public function sanitize_dismiss_days( $value, array $option = array(), $raw_value = null ): int {
		unset( $option );
		$candidate = null !== $raw_value ? $raw_value : $value;
		$days      = is_numeric( $candidate ) ? (int) $candidate : self::DEFAULT_DISMISS_DAYS;
		return max( 1, min( 365, $days ) );
	}

	/**
	 * @param mixed               $value     Valor preprocesado.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo.
	 */
	public function sanitize_promo_code( $value, array $option = array(), $raw_value = null ): string {
		unset( $option );
		$candidate = is_scalar( null !== $raw_value ? $raw_value : $value ) ? (string) ( null !== $raw_value ? $raw_value : $value ) : '';
		$code      = strtoupper( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', trim( $candidate ) ) );
		return substr( $code, 0, 40 );
	}

	/**
	 * @param mixed               $value     Valor preprocesado.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo.
	 */
	public function sanitize_promo_url( $value, array $option = array(), $raw_value = null ): string {
		unset( $option );
		$candidate = is_scalar( null !== $raw_value ? $raw_value : $value ) ? (string) ( null !== $raw_value ? $raw_value : $value ) : self::DEFAULT_PROMO_URL;
		$url       = trim( $candidate );
		return '' !== $url ? sanitize_text_field( $url ) : self::DEFAULT_PROMO_URL;
	}

	/**
	 * @param mixed               $value     Valor preprocesado.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo.
	 */
	public function sanitize_promo_audience( $value, array $option = array(), $raw_value = null ): string {
		unset( $option );
		$candidate = is_scalar( null !== $raw_value ? $raw_value : $value ) ? (string) ( null !== $raw_value ? $raw_value : $value ) : 'all';
		return 'guests' === $candidate ? 'guests' : 'all';
	}

	/**
	 * Si el administrador marca «Reiniciar cookies de cierre», incrementa `cookie_epoch`
	 * y devuelve `'no'` para que la casilla actúe como acción de un clic.
	 *
	 * @param mixed               $value     Valor preprocesado.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo.
	 */
	public function sanitize_reset_cookies( $value, array $option = array(), $raw_value = null ): string {
		unset( $option );
		$candidate = null !== $raw_value ? $raw_value : $value;
		if ( 'yes' === $candidate || '1' === (string) $candidate || true === $candidate ) {
			update_option( 'akb_welcome_coupon_cookie_epoch', self::cookie_epoch() + 1, false );
		}
		return 'no';
	}

	/**
	 * @param mixed               $value     Valor preprocesado.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo.
	 */
	public function sanitize_turnstile_key( $value, array $option = array(), $raw_value = null ): string {
		unset( $option );
		$candidate = is_scalar( null !== $raw_value ? $raw_value : $value ) ? (string) ( null !== $raw_value ? $raw_value : $value ) : '';
		return trim( sanitize_text_field( $candidate ) );
	}

	/**
	 * @param mixed               $value     Valor preprocesado.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo.
	 */
	public function sanitize_copy_option( $value, array $option = array(), $raw_value = null ): string {
		$id       = (string) ( $option['id'] ?? '' );
		$fallback = self::copy_defaults()[ $id ] ?? '';
		return Text::one_line( null !== $raw_value ? $raw_value : $value, $fallback );
	}

	/**
	 * Definición declarativa de campos para `AdminPanel`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		$epoch = self::cookie_epoch();

		return array(
			array(
				'title' => '1. Banner / Popup de primera compra (Captación con correo)',
				'type'  => 'title',
				'id'    => 'akb_welcome_coupon_general_section',
				'desc'  => 'Configura el beneficio permanente de bienvenida para nuevos suscriptores. Genera un cupón único e intransferible por correo, sincroniza el contacto para campañas y verifica en el checkout que sea realmente la primera compra de la persona (RUT, celular y correo). Solo aplica sobre el precio base normal y nunca reemplaza un descuento mayor de Arma tu Pack.',
			),
			array(
				'title'    => 'Activar popup de primera compra',
				'id'       => self::OPTION_ENABLED,
				'type'     => 'checkbox',
				'desc'     => 'Mostrar el banner emergente de bienvenida a visitantes nuevos que aún no han comprado ni usado el beneficio',
				'desc_tip' => 'Si lo apagas, deja de mostrarse el popup y se cierra el alta de nuevos cupones, pero los cupones ya emitidos y vigentes siguen funcionando hasta su vencimiento.',
				'default'  => 'yes',
			),
			array(
				'title'             => 'Descuento (%)',
				'id'                => self::OPTION_DISCOUNT_PCT,
				'type'              => 'number',
				'desc'              => 'Porcentaje de descuento calculado exclusivamente sobre el precio normal base (best-wins: si un tomo tiene oferta menor, completa hasta este %; si lleva más tomos en Arma tu Pack y supera este %, se mantiene el descuento mayor del pack).',
				'default'           => (string) self::DEFAULT_DISCOUNT_PCT,
				'css'               => 'width: 90px;',
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 50,
					'step' => 1,
				),
			),
			array(
				'title'             => 'Compra mínima ($ CLP)',
				'id'                => self::OPTION_MIN_AMOUNT,
				'type'              => 'number',
				'desc'              => 'Subtotal mínimo en productos para poder aplicar el cupón (sin contar el envío).',
				'default'           => (string) self::DEFAULT_MIN_AMOUNT,
				'css'               => 'width: 130px;',
				'custom_attributes' => array(
					'min'  => 0,
					'max'  => 500000,
					'step' => 1000,
				),
			),
			array(
				'title'             => 'Días de vigencia',
				'id'                => self::OPTION_VALIDITY_DAYS,
				'type'              => 'number',
				'desc'              => 'Días desde la emisión hasta que caduca el código único (recomendado: 3 días).',
				'default'           => (string) self::DEFAULT_VALIDITY_DAYS,
				'css'               => 'width: 90px;',
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 30,
					'step' => 1,
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_welcome_coupon_general_section',
			),
			array(
				'title' => 'Cuándo, dónde y cookies de primera compra',
				'type'  => 'title',
				'id'    => 'akb_welcome_coupon_triggers_section',
				'desc'  => 'Nunca aparece a clientes con sesión iniciada que ya compraron, ni a quienes ya ocuparon su cupón, ni en Carrito, Finalizar compra, Mi cuenta o artículos del blog.',
			),
			array(
				'title'             => 'Espera antes de mostrar (segundos)',
				'id'                => self::OPTION_DELAY_SECONDS,
				'type'              => 'number',
				'desc'              => 'Tiempo de permanencia antes de abrir el popup si el visitante aún no ha hecho suficiente scroll.',
				'default'           => (string) self::DEFAULT_DELAY_SECONDS,
				'css'               => 'width: 90px;',
				'custom_attributes' => array(
					'min'  => 0,
					'max'  => 120,
					'step' => 1,
				),
			),
			array(
				'title'             => 'Disparo por scroll (%)',
				'id'                => self::OPTION_SCROLL_PCT,
				'type'              => 'number',
				'desc'              => 'Porcentaje de avance en la página que abre el popup aunque no se haya cumplido el tiempo.',
				'default'           => (string) self::DEFAULT_SCROLL_PCT,
				'css'               => 'width: 90px;',
				'custom_attributes' => array(
					'min'  => 10,
					'max'  => 95,
					'step' => 5,
				),
			),
			array(
				'title'             => 'Días sin volver a mostrar tras cerrar',
				'id'                => self::OPTION_DISMISS_DAYS,
				'type'              => 'number',
				'desc'              => 'Si el visitante cierra el popup sin suscribirse, no vuelve a aparecer durante estos días.',
				'default'           => (string) self::DEFAULT_DISMISS_DAYS,
				'css'               => 'width: 90px;',
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 365,
					'step' => 1,
				),
			),
			array(
				'title'   => 'Intención de salida (Exit Intent)',
				'id'      => self::OPTION_EXIT_INTENT,
				'type'    => 'checkbox',
				'desc'    => 'Abrir el popup cuando el cursor se mueva hacia arriba para abandonar la página o al subir rápidamente en el móvil.',
				'default' => 'yes',
			),
			array(
				'title'   => 'Distintivo flotante de reapertura',
				'id'      => self::OPTION_LAUNCHER,
				'type'    => 'checkbox',
				'desc'    => 'Mantener una pestaña discreta en la esquina inferior tras cerrar con la «X» para que el visitante pueda reabrir el beneficio si cambia de opinión.',
				'default' => 'yes',
			),
			array(
				'title'         => 'Superficies habilitadas',
				'desc'          => 'Portada (inicio)',
				'id'            => self::OPTION_SHOW_HOME,
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'start',
			),
			array(
				'desc'          => 'Catálogo, tienda, categorías y editoriales',
				'id'            => self::OPTION_SHOW_CATALOG,
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			),
			array(
				'desc'          => 'Fichas de producto',
				'id'            => self::OPTION_SHOW_PRODUCT,
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			),
			array(
				'desc'          => 'Páginas de serie (/serie/...)',
				'id'            => self::OPTION_SHOW_SERIES,
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'end',
			),
			array(
				'title'    => 'Reiniciar cookies de cierre',
				'id'       => self::OPTION_RESET_COOKIES,
				'type'     => 'checkbox',
				'desc'     => sprintf( 'Volver a mostrar el popup a quienes lo habían cerrado con la «X» (versión actual de cookie: v%d)', $epoch ),
				'desc_tip' => 'Marca esta casilla y guarda los cambios cuando actualices el diseño o lances una nueva campaña. No vuelve a mostrar el popup de primera compra a quienes ya se suscribieron o ya usaron su cupón.',
				'default'  => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_welcome_coupon_triggers_section',
			),
			array(
				'title' => 'Textos del popup de primera compra',
				'type'  => 'title',
				'id'    => 'akb_welcome_coupon_copies_section',
				'desc'  => 'Puedes usar los marcadores <code>{pct}</code> (porcentaje), <code>{min}</code> (compra mínima formateada) y <code>{dias}</code> (vigencia en días). Si vacías un campo, vuelve automáticamente al texto de fábrica.',
			),
			array(
				'title'   => 'Antetítulo (etiqueta superior)',
				'id'      => self::OPTION_COPY_KICKER,
				'type'    => 'text',
				'default' => self::DEFAULT_COPY_KICKER,
				'css'     => 'width: min(100%, 560px);',
			),
			array(
				'title'   => 'Título principal',
				'id'      => self::OPTION_COPY_HEADING,
				'type'    => 'text',
				'default' => self::DEFAULT_COPY_HEADING,
				'css'     => 'width: min(100%, 560px);',
			),
			array(
				'title'   => 'Bajada descriptiva',
				'id'      => self::OPTION_COPY_SUBTITLE,
				'type'    => 'text',
				'default' => self::DEFAULT_COPY_SUBTITLE,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'title'   => 'Texto del botón principal',
				'id'      => self::OPTION_COPY_CTA,
				'type'    => 'text',
				'default' => self::DEFAULT_COPY_CTA,
				'css'     => 'width: min(100%, 420px);',
			),
			array(
				'title'    => 'Nota de confianza (Anti-spam)',
				'id'       => self::OPTION_COPY_TRUST_NOTE,
				'type'     => 'text',
				'desc'     => 'Texto informativo que aparece bajo el botón principal para dar tranquilidad al cliente. Reemplaza el botón negativo de cierre; el visitante siempre puede cerrar tocando la «X» o fuera del modal.',
				'default'  => self::DEFAULT_COPY_TRUST_NOTE,
				'css'      => 'width: min(100%, 680px);',
			),
			array(
				'title'   => 'Condiciones al pie',
				'id'      => self::OPTION_COPY_LEGAL,
				'type'    => 'text',
				'default' => self::DEFAULT_COPY_LEGAL,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_welcome_coupon_copies_section',
			),
			array(
				'title' => 'Protección contra bots (Cloudflare Turnstile)',
				'type'  => 'title',
				'id'    => 'akb_welcome_coupon_turnstile_section',
				'desc'  => 'Protege la reputación de envío en Brevo evitando que bots automatizados inyecten correos basura en el formulario. Cloudflare Turnstile es 100% invisible para visitantes legítimos (sin captchas ni rompecabezas molestos).',
			),
			array(
				'title'    => 'Activar Cloudflare Turnstile',
				'id'       => self::OPTION_TURNSTILE_ENABLED,
				'type'     => 'checkbox',
				'desc'     => 'Verificar invisibilidad y legitimidad de la conexión antes de emitir el cupón y dar de alta el correo en Brevo',
				'desc_tip' => 'Recomendado activo para blindar el Single Opt-in contra ataques de bots y mantener la tasa de rebotes de Brevo en 0%.',
				'default'  => 'yes',
			),
			array(
				'title'   => 'Clave de sitio (Sitekey)',
				'id'      => self::OPTION_TURNSTILE_SITEKEY,
				'type'    => 'text',
				'default' => self::DEFAULT_TURNSTILE_SITEKEY,
				'desc'    => 'Clave pública de Turnstile para el navegador. La clave de servidor se configura mediante AKB_WELCOME_TURNSTILE_SECRET fuera del repositorio; sin ella no se emitirán cupones.',
				'css'     => 'width: min(100%, 420px);',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_welcome_coupon_turnstile_section',
			),
			array(
				'title' => '2. Popup Promocional de Campaña (Informativo o con cupón, ej. Cyber / Halloween)',
				'type'  => 'title',
				'id'    => 'akb_welcome_promo_section',
				'desc'  => 'Información de Cyber, Halloween o Navidad. Deja el código vacío para informar los descuentos automáticos vigentes. Úsalo para campañas puntuales (Cyber, Halloween, Navidad) sin borrar ni mezclar tu configuración de Primera Compra. Es 100% informativo y NO pide correo. Si dejas el código vacío, anuncia los descuentos automáticos de la web con un botón directo al catálogo; si escribes un código, incluye el botón para copiarlo.',
			),
			array(
				'title'    => 'Activar popup promocional',
				'id'       => self::OPTION_PROMO_ENABLED,
				'type'     => 'checkbox',
				'desc'     => 'Mostrar este popup promocional en lugar del de primera compra mientras dure la campaña',
				'desc_tip' => 'Mientras está activo, toma prioridad visual sobre el popup de primera compra. Al apagarlo al terminar la campaña, vuelve automáticamente el popup de primera compra sin perder ningún ajuste.',
				'default'  => 'no',
			),
			array(
				'title'   => 'Seguir campaña automática de descuentos',
				'id'      => self::OPTION_PROMO_FOLLOW_DISCOUNT,
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => 'En modo informativo, mostrar sólo cuando la campaña de Descuentos esté activa y dentro de sus fechas. Usa también el seguimiento de campaña en la franja superior.',
			),
			array(
				'title'       => 'Código promocional (Opcional)',
				'id'          => self::OPTION_PROMO_CODE,
				'type'        => 'text',
				'desc'        => '<strong>Opcional:</strong> Si tus promociones ya son automáticas en la tienda (descuentos por editorial o categorías), déjalo VACÍO para mostrar un popup puramente informativo. Si ingresas un código, mostrará el cajón para copiarlo.',
				'placeholder' => 'Vacío para modo informativo (descuentos automáticos)',
				'default'     => '',
				'css'         => 'width: min(100%, 320px); text-transform: uppercase; font-family: monospace;',
			),
			array(
				'title'       => 'URL de destino del botón (CTA)',
				'id'          => self::OPTION_PROMO_URL,
				'type'        => 'text',
				'desc'        => 'Dirección a la que se dirige el cliente al pulsar el botón (ej. <code>/tienda/</code> o <code>/marca/ivrea-argentina/</code>).',
				'placeholder' => '/tienda/',
				'default'     => self::DEFAULT_PROMO_URL,
				'css'         => 'width: min(100%, 360px);',
			),
			array(
				'title'   => 'A quién mostrar la campaña',
				'id'      => self::OPTION_PROMO_AUDIENCE,
				'type'    => 'select',
				'desc'    => 'En eventos como Halloween o Cyber conviene mostrarlo a todos los clientes (incluidos quienes ya compraron antes).',
				'default' => 'all',
				'options' => array(
					'all'    => 'Todos los clientes y visitantes (incluye registrados y compradores habituales)',
					'guests' => 'Solo visitantes sin sesión iniciada',
				),
				'css'     => 'width: min(100%, 480px);',
			),
			array(
				'title'             => 'Espera antes de mostrar (segundos)',
				'id'                => self::OPTION_PROMO_DELAY_SECONDS,
				'type'              => 'number',
				'desc'              => 'Segundos antes de abrir el popup promocional.',
				'default'           => (string) self::DEFAULT_PROMO_DELAY,
				'css'               => 'width: 90px;',
				'custom_attributes' => array(
					'min'  => 0,
					'max'  => 120,
					'step' => 1,
				),
			),
			array(
				'title'             => 'Días sin volver a mostrar tras cerrar',
				'id'                => self::OPTION_PROMO_DISMISS_DAYS,
				'type'              => 'number',
				'desc'              => 'Días de enfriamiento si el cliente cierra la campaña promocional.',
				'default'           => (string) self::DEFAULT_PROMO_DISMISS,
				'css'               => 'width: 90px;',
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 365,
					'step' => 1,
				),
			),
			array(
				'title'         => 'Superficies de la campaña',
				'desc'          => 'Portada (inicio)',
				'id'            => self::OPTION_PROMO_SHOW_HOME,
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'start',
			),
			array(
				'desc'          => 'Catálogo, tienda, categorías y editoriales',
				'id'            => self::OPTION_PROMO_SHOW_CATALOG,
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			),
			array(
				'desc'          => 'Fichas de producto',
				'id'            => self::OPTION_PROMO_SHOW_PRODUCT,
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => '',
			),
			array(
				'desc'          => 'Páginas de serie (/serie/...)',
				'id'            => self::OPTION_PROMO_SHOW_SERIES,
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => 'end',
			),
			array(
				'title'   => 'Antetítulo de la campaña',
				'id'      => self::OPTION_COPY_PROMO_KICKER,
				'type'    => 'text',
				'default' => self::DEFAULT_COPY_PROMO_KICKER,
				'css'     => 'width: min(100%, 560px);',
			),
			array(
				'title'   => 'Título de la campaña',
				'desc'    => 'Para precios vivos usa [akb_descuento_pct] y [akb_descuento_editorial], igual que en la franja superior. Un porcentaje escrito a mano no cambia al editar el descuento.',
				'id'      => self::OPTION_COPY_PROMO_HEADING,
				'type'    => 'text',
				'default' => self::DEFAULT_COPY_PROMO_HEADING,
				'css'     => 'width: min(100%, 560px);',
			),
			array(
				'title'   => 'Bajada de la campaña',
				'id'      => self::OPTION_COPY_PROMO_SUBTITLE,
				'type'    => 'text',
				'default' => self::DEFAULT_COPY_PROMO_SUBTITLE,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'title'   => 'Texto del botón de acción',
				'id'      => self::OPTION_COPY_PROMO_CTA,
				'type'    => 'text',
				'default' => self::DEFAULT_COPY_PROMO_CTA,
				'css'     => 'width: min(100%, 420px);',
			),
			array(
				'title'   => 'Condiciones al pie de la campaña',
				'id'      => self::OPTION_COPY_PROMO_LEGAL,
				'type'    => 'text',
				'default' => self::DEFAULT_COPY_PROMO_LEGAL,
				'css'     => 'width: min(100%, 680px);',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_welcome_promo_section',
			),
		);
	}
}
