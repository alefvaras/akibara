<?php
/**
 * CouponService — emisión, validación anti-abuso, best-wins (precio base + Arma tu Pack)
 * y auto-aplicación del cupón único de primera compra y campañas promocionales.
 *
 * Reglas de negocio (inspiradas en damemimanga y endurecidas para Akibara):
 *   1. Código único por suscriptor (`HOLA` + prefijo de nombre + 4 caracteres),
 *      `usage_limit = 1`, `usage_limit_per_user = 1`, `individual_use = true`,
 *      restringido al correo suscrito, compra mínima configurable ($25.000 CLP)
 *      y vigencia configurable (3 días, hasta las 23:59:59 del último día).
 *   2. Descuento exclusivamente sobre el precio base (`regular_price`) y regla
 *      Best-Wins frente a descuentos editoriales (`TaxonomyDiscount`), rebajas
 *      manuales (`_sale_price`) y tramos por volumen de Arma tu Pack (`SeriesBundles`):
 *      - Si un tomo tiene oferta menor al % de bienvenida, el cupón completa solo
 *        la diferencia hasta el % sobre `regular_price`.
 *      - Si un tomo ya tiene oferta >= al % de bienvenida, aporta $0 en esa línea.
 *      - Si el cliente lleva más tomos y el descuento de Arma tu Pack (`SeriesBundles`)
 *        iguala o supera el ahorro del cupón de bienvenida, el cupón de bienvenida
 *        NO se aplica (o cede su lugar) para que el cliente reciba el descuento
 *        mayor que le conviene y conserve su cupón sin quemarlo.
 *   3. Diferenciación entre "usuario registrado con 0 compras" y "comprador previo
 *      que no marcó el opt-in de Brevo en el checkout" (Welcome Pivot de Klaviyo/Brevo):
 *      - Un usuario registrado en la web (ej. Login con Google) con 0 compras SÍ es
 *        elegible para su primera compra y recibe su cupón + alta en Brevo.
 *      - Un comprador con >= 1 pedido previo que llena el formulario porque no estaba
 *        en Brevo SÍ se suscribe a Brevo (`akb_welcome_existing_buyer_subscribed`),
 *        pero NO recibe cupón de primera compra (protección de margen sin perder el lead).
 *   4. Anti-abuso por identidad en carrito y checkout: verifica usuario, correo,
 *      celular chileno (normalizado) y RUT (normalizado).
 *   5. Cookie de completado (`akb_wc_done=1`) al aplicar o consumir el cupón para
 *      que el popup jamás vuelva a aparecer a quien ya lo ocupó.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\WelcomeCoupon;

use Akibara\Business\Modules\SeriesBundles\CartSeriesCollector;
use Akibara\Business\Modules\SeriesBundles\DiscountEngine;
use Akibara\Business\Modules\SeriesBundles\EligibilityGuard;
use Akibara\Business\Modules\SeriesBundles\Settings as SeriesBundlesSettings;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.120.0
 */
final class CouponService {

	public const COUPON_PREFIX = 'hola';

	public const META_IS_WELCOME        = '_akb_welcome_coupon';
	public const META_RECIPIENT_EMAIL   = '_akb_welcome_recipient_email';
	public const META_NORMALIZED_EMAIL  = '_akb_welcome_normalized_email';
	public const META_RECIPIENT_NAME    = '_akb_welcome_recipient_name';
	public const META_RECIPIENT_USER_ID = '_akb_welcome_recipient_user_id';
	public const META_ISSUED_AT         = '_akb_welcome_issued_at';
	public const META_EXPIRES_TS        = '_akb_welcome_expires_ts';
	public const META_REMINDER_SENT     = '_akb_welcome_reminder_sent';
	public const META_USED_NOTIFIED     = '_akb_welcome_used_notified';
	public const META_USED_ORDER_ID     = '_akb_welcome_used_order_id';

	public const SESSION_PENDING_COUPON = 'akb_pending_welcome_coupon';
	public const QUERY_ARG_COUPON       = 'akb_cupon';
	public const COOKIE_DONE            = 'akb_welcome_done';

	public const GC_HOOK  = 'akb_welcome_coupon_gc_expired';
	public const GC_GROUP = 'akibara-welcome';

	/**
	 * Whitelist en memoria de dominios de correo de alta confianza en Chile.
	 *
	 * @var array<string, bool>
	 */
	private const TRUSTED_MAIL_DOMAINS = array(
		'gmail.com'          => true,
		'googlemail.com'     => true,
		'hotmail.com'        => true,
		'hotmail.cl'         => true,
		'outlook.com'        => true,
		'outlook.cl'         => true,
		'live.com'           => true,
		'live.cl'            => true,
		'msn.com'            => true,
		'yahoo.com'          => true,
		'yahoo.es'           => true,
		'yahoo.cl'           => true,
		'icloud.com'         => true,
		'me.com'             => true,
		'mac.com'            => true,
		'proton.me'          => true,
		'protonmail.com'     => true,
		'uc.cl'              => true,
		'puc.cl'             => true,
		'uchile.cl'          => true,
		'usach.cl'           => true,
		'udec.cl'            => true,
		'uai.cl'             => true,
		'udp.cl'             => true,
		'uft.cl'             => true,
		'unab.cl'            => true,
		'uss.cl'             => true,
	);

	/**
	 * Dominios de proveedores de correos temporales o desechables comunes.
	 *
	 * @var array<int, string>
	 */
	private const DISPOSABLE_EMAIL_DOMAINS = array(
		'10minutemail.com',
		'10minutemail.net',
		'burnermail.io',
		'crazymailing.com',
		'dispostable.com',
		'emailondeck.com',
		'fakemailgenerator.com',
		'getnada.com',
		'guerrillamail.biz',
		'guerrillamail.com',
		'guerrillamail.net',
		'guerrillamail.org',
		'mailinator.com',
		'mohmal.com',
		'mytemp.email',
		'sharklasers.com',
		'temp-mail.io',
		'temp-mail.org',
		'tempmail.com',
		'throwawaymail.com',
		'trashmail.com',
		'yopmail.com',
		'yopmail.fr',
		'yopmail.net',
	);

	/**
	 * Errores comunes de tipeo en dominios de correo (Gmail, Hotmail, Outlook, Yahoo).
	 *
	 * @var array<string, string>
	 */
	private const COMMON_DOMAIN_TYPOS = array(
		'gmai.com'     => 'gmail.com',
		'gamil.com'    => 'gmail.com',
		'gmial.com'    => 'gmail.com',
		'gmaill.com'   => 'gmail.com',
		'gmaio.com'    => 'gmail.com',
		'hotmial.com'  => 'hotmail.com',
		'hotmaill.com' => 'hotmail.com',
		'otlook.com'   => 'outlook.com',
		'outlok.com'   => 'outlook.com',
		'yaho.com'     => 'yahoo.com',
		'yahooo.com'   => 'yahoo.com',
	);

	/**
	 * Estados de pedido que cuentan como compra realizada (bloquean primera compra).
	 *
	 * @var array<int, string>
	 */
	private const PRIOR_ORDER_STATUSES = array(
		'processing',
		'completed',
		'on-hold',
		'shipping-progress',
		'ready-to-ship',
	);

	/**
	 * Registra los hooks de WooCommerce para validación, best-wins, URL y uso.
	 */
	public function register(): void {
		// Permitir que el cupón de bienvenida conviva en carritos con descuento
		// editorial; el filtro de descuento de abajo aplica la regla best-wins.
		add_filter( 'akibara_taxonomy_discount_ignored_coupon_prefixes', array( $this, 'whitelist_coupon_prefix' ) );

		// Regla best-wins: completa hasta el % de bienvenida exclusivamente sobre regular_price.
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'filter_discount_amount_best_wins' ), 20, 5 );

		// Validación anti-abuso y conveniencia (frente a Arma tu Pack) al aplicar el cupón y en checkout.
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_coupon_is_valid' ), 20, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic_checkout' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'validate_store_api_checkout' ), 10, 2 );

		// Auto-aplicación desde el enlace del correo (?akb_cupon=CODIGO) y reconciliación con Arma tu Pack.
		// Prioridad 25 en `woocommerce_before_calculate_totals` para correr ANTES de `BundleCouponEngine` (prio 1000).
		add_action( 'wp_loaded', array( $this, 'capture_coupon_from_url' ), 20 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'maybe_apply_pending_coupon' ), 20 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'maybe_apply_pending_coupon_on_totals' ), 25, 1 );

		// Marcar uso del cupón EXCLUSIVAMENTE cuando el pedido ha sido realmente pagado.
		// Transbank Webpay, MercadoPago y Flow pasan a `processing` tras confirmar el pago.
		// Las transferencias electrónicas quedan en `on-hold` hasta que el dueño verifica el abono
		// y las pasa a `processing`. Luego avanzan a `shipping-progress` (Listo para enviar) o `completed`.
		// Nunca quemar en checkout_order_processed ni on-hold: si Webpay rechaza la tarjeta o la
		// transferencia no se realiza, el cliente no debe perder su cupón.
		add_action( 'woocommerce_order_status_processing', array( $this, 'mark_order_coupon_used' ), 20, 1 );
		add_action( 'woocommerce_order_status_shipping-progress', array( $this, 'mark_order_coupon_used' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'mark_order_coupon_used' ), 20, 1 );

		// Restaurar y des-quemar el cupón si el pago falla en Webpay o el pedido se cancela/falla/reembolsa.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'restore_order_coupon' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'restore_order_coupon' ), 20, 1 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'restore_order_coupon' ), 20, 1 );

		// Desbloqueo de cupones retenidos en pedidos `pending` de MercadoPago y Flow al reintentar el pago.
		add_filter( 'woocommerce_coupon_get_usage_count', array( $this, 'filter_coupon_usage_count' ), 20, 2 );

		// Recolector de basura periódico para cupones vencidos sin uso en Action Scheduler.
		add_action( self::GC_HOOK, array( $this, 'run_garbage_collector' ) );
		add_action( 'init', array( $this, 'maybe_schedule_garbage_collector' ), 20 );
	}

	/**
	 * Aporta el prefijo `hola` para que `TaxonomyDiscount\CouponInteraction` no
	 * rechace el carrito completo cuando hay un tomo con descuento editorial.
	 *
	 * @param array<int, string> $prefixes Prefijos permitidos.
	 * @return array<int, string>
	 */
	public function whitelist_coupon_prefix( array $prefixes ): array {
		$prefixes[] = self::COUPON_PREFIX;
		return array_values( array_unique( $prefixes ) );
	}

	/**
	 * ¿Este `WC_Coupon` es un cupón de bienvenida emitido por este módulo?
	 */
	public static function is_welcome_coupon( \WC_Coupon $coupon ): bool {
		if ( '1' === (string) $coupon->get_meta( self::META_IS_WELCOME, true ) ) {
			return true;
		}
		$code = strtolower( trim( (string) $coupon->get_code() ) );
		return '' !== $code && 0 === strpos( $code, self::COUPON_PREFIX ) && strlen( $code ) >= 8;
	}

	/**
	 * Emite (o recupera si sigue vigente) el cupón de bienvenida para un suscriptor,
	 * o ejecuta el "Welcome Pivot" (alta en Brevo sin cupón) si el correo ya tiene
	 * compras previas en Akibara pero no se había suscrito al boletín.
	 *
	 * @param string $name    Nombre de pila o alias.
	 * @param string $email   Correo ya validado y en minúsculas.
	 * @param int    $user_id ID de usuario en sesión (0 si es visitante).
	 * @return array{
	 *   ok: bool,
	 *   code: string,
	 *   status: int,
	 *   message: string,
	 *   coupon_id?: int,
	 *   coupon_code?: string,
	 *   discount_pct?: int,
	 *   min_amount?: int,
	 *   min_amount_formatted?: string,
	 *   expires_ts?: int,
	 *   expires_date?: string,
	 *   is_new?: bool
	 * }
	 */
	public function issue_for_subscriber( string $name, string $email, int $user_id = 0 ): array {
		$email = strtolower( trim( $email ) );
		$name  = self::clean_subscriber_name( $name );

		if ( self::is_disposable_email( $email ) ) {
			return array(
				'ok'      => false,
				'code'    => 'disposable_email',
				'status'  => 400,
				'message' => 'Ingresa un correo personal o de trabajo válido para recibir tu cupón.',
			);
		}

		// 1. Verificar si ya tiene COMPRAS previas en la tienda (no basta con estar
		// registrado con 0 pedidos: un usuario registrado sin compras SÍ tiene derecho
		// a su primera compra; quien ya compró y puso "no" en el checkout se suscribe
		// a Brevo mediante el "Welcome Pivot" sin emitir otro cupón de primera compra).
		if ( $this->email_belongs_to_existing_customer( $email, $user_id ) ) {
			$this->set_done_cookie();

			/**
			 * Dispara cuando un cliente que ya había comprado antes (pero no estaba
			 * suscrito en Brevo porque no marcó la casilla en el checkout) deja su
			 * correo en el popup. Permite suscribirlo en Brevo sin regalar un cupón
			 * de primera compra.
			 *
			 * @since 1.120.0
			 *
			 * @param array{email:string,name:string} $payload Datos del cliente.
			 */
			do_action(
				'akb_welcome_existing_buyer_subscribed',
				array(
					'email' => $email,
					'name'  => '' !== $name ? $name : 'Nakama',
				)
			);

			return array(
				'ok'          => true,
				'code'        => 'subscribed_existing_buyer',
				'status'      => 200,
				'message'     => '¡Bienvenido de vuelta! Ya suscribimos tu correo a nuestras novedades, preventas y campañas exclusivas. Como ya realizaste compras antes en Akibara, el cupón de primera compra no aplica, ¡pero recuerda que acumulas Akibara Puntos en cada pedido!',
				'coupon_code' => '',
				'is_new'      => false,
			);
		}

		// 2. Verificar si ya se emitió un cupón de bienvenida para este correo.
		$existing = $this->find_existing_coupon_for_email( $email );
		if ( null !== $existing ) {
			$this->set_done_cookie();

			if ( $existing['used'] ) {
				return array(
					'ok'      => false,
					'code'    => 'already_used',
					'status'  => 409,
					'message' => 'Este correo ya utilizó su cupón de bienvenida de primera compra.',
				);
			}

			if ( $existing['expired'] ) {
				return array(
					'ok'      => false,
					'code'    => 'already_expired',
					'status'  => 409,
					'message' => 'Este correo ya solicitó un cupón de bienvenida anteriormente y su plazo de vigencia venció.',
				);
			}

			// Sigue vigente y sin uso: respuesta idempotente con el mismo código.
			return array(
				'ok'                   => true,
				'code'                 => 'existing_coupon',
				'status'               => 200,
				'message'              => 'Ya tenías un cupón de bienvenida activo. Aquí tienes tu código para usarlo en tu primera compra.',
				'coupon_id'            => $existing['coupon_id'],
				'coupon_code'          => $existing['coupon_code'],
				'discount_pct'         => $existing['discount_pct'],
				'min_amount'           => $existing['min_amount'],
				'min_amount_formatted' => self::format_clp( $existing['min_amount'] ),
				'expires_ts'           => $existing['expires_ts'],
				'expires_date'         => self::format_date_dmy( $existing['expires_ts'] ),
				'shop_url'             => self::auto_apply_url( $existing['coupon_code'] ),
				'is_new'               => false,
			);
		}

		$pct           = Settings::discount_pct();
		$min_amount    = Settings::min_amount();
		$validity_days = Settings::validity_days();
		$now           = time();
		$expires_ts    = self::calculate_expiration_timestamp( $now, $validity_days );
		$expires_date  = self::format_date_dmy( $expires_ts );
		$coupon_code   = $this->generate_unique_code( $name );

		$coupon = new \WC_Coupon();
		$coupon->set_code( $coupon_code );
		$coupon->set_description(
			sprintf(
				'Cupón de bienvenida primera compra (%d%%) para %s <%s>',
				$pct,
				'' !== $name ? $name : 'Suscriptor',
				$email
			)
		);
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( (float) $pct );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_minimum_amount( $min_amount > 0 ? (string) $min_amount : '' );
		$coupon->set_date_expires( $expires_ts );
		$email_restrictions = array_values( array_unique( array_filter( array( $email, self::normalize_email( $email ) ) ) ) );
		$coupon->set_email_restrictions( $email_restrictions );

		$coupon->update_meta_data( self::META_IS_WELCOME, '1' );
		$coupon->update_meta_data( self::META_RECIPIENT_EMAIL, $email );
		$coupon->update_meta_data( self::META_NORMALIZED_EMAIL, self::normalize_email( $email ) );
		$coupon->update_meta_data( self::META_RECIPIENT_NAME, $name );
		if ( $user_id > 0 ) {
			$coupon->update_meta_data( self::META_RECIPIENT_USER_ID, (string) $user_id );
		}
		$coupon->update_meta_data( self::META_ISSUED_AT, (string) $now );
		$coupon->update_meta_data( self::META_EXPIRES_TS, (string) $expires_ts );

		$coupon_id = (int) $coupon->save();
		if ( $coupon_id <= 0 ) {
			return array(
				'ok'      => false,
				'code'    => 'storage_error',
				'status'  => 500,
				'message' => 'No pudimos generar tu cupón en este momento. Por favor intenta de nuevo.',
			);
		}

		$this->set_done_cookie();

		$payload = array(
			'coupon_id'            => $coupon_id,
			'coupon_code'          => $coupon_code,
			'email'                => $email,
			'name'                 => '' !== $name ? $name : 'Nakama',
			'discount_pct'         => $pct,
			'min_amount'           => $min_amount,
			'min_amount_formatted' => self::format_clp( $min_amount ),
			'validity_days'        => $validity_days,
			'issued_ts'            => $now,
			'expires_ts'           => $expires_ts,
			'expires_date'         => $expires_date,
			'shop_url'             => self::auto_apply_url( $coupon_code ),
		);

		/**
		 * Dispara cuando se emite un nuevo cupón de bienvenida.
		 *
		 * @since 1.120.0
		 *
		 * @param array<string, mixed> $payload Datos del cupón y suscriptor.
		 */
		do_action( 'akb_welcome_coupon_issued', $payload );

		return array(
			'ok'                   => true,
			'code'                 => 'issued',
			'status'               => 201,
			'message'              => '¡Listo! Generamos tu código único y también te lo enviamos a tu correo.',
			'coupon_id'            => $coupon_id,
			'coupon_code'          => $coupon_code,
			'discount_pct'         => $pct,
			'min_amount'           => $min_amount,
			'min_amount_formatted' => self::format_clp( $min_amount ),
			'expires_ts'           => $expires_ts,
			'expires_date'         => $expires_date,
			'shop_url'             => self::auto_apply_url( $coupon_code ),
			'is_new'               => true,
		);
	}

	/**
	 * URL de la tienda con el parámetro de auto-aplicación del cupón.
	 */
	public static function auto_apply_url( string $coupon_code ): string {
		$base = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';
		if ( '' === $base || ! filter_var( $base, FILTER_VALIDATE_URL ) ) {
			$base = function_exists( 'home_url' ) ? (string) home_url( '/tienda/' ) : 'https://akibara.cl/tienda/';
		}

		return (string) add_query_arg(
			self::QUERY_ARG_COUPON,
			rawurlencode( strtoupper( trim( $coupon_code ) ) ),
			$base
		);
	}

	/**
	 * Calcula el timestamp de vencimiento al final del día N (23:59:59 hora tienda).
	 */
	public static function calculate_expiration_timestamp( int $now, int $validity_days ): int {
		$days = max( 1, $validity_days );
		$dt   = ( new \DateTimeImmutable( '@' . $now ) )
			->setTimezone( StoreTime::zone() )
			->modify( '+' . $days . ' days' )
			->setTime( 23, 59, 59 );

		return $dt->getTimestamp();
	}

	/**
	 * Formatea un timestamp como `d/m/Y` en la zona de la tienda.
	 */
	public static function format_date_dmy( int $timestamp ): string {
		return ( new \DateTimeImmutable( '@' . $timestamp ) )
			->setTimezone( StoreTime::zone() )
			->format( 'd/m/Y' );
	}

	/**
	 * Formatea un monto entero en CLP (ej. `$25.000`).
	 */
	public static function format_clp( int $amount ): string {
		if ( function_exists( 'number_format_i18n' ) ) {
			return '$' . number_format_i18n( $amount, 0 );
		}
		return '$' . number_format( (float) $amount, 0, ',', '.' );
	}

	/**
	 * Limpia el nombre del suscriptor para mostrarlo y derivar el prefijo del código.
	 */
	public static function clean_subscriber_name( string $raw ): string {
		$clean = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $raw ) : strip_tags( $raw );
		$clean = trim( (string) preg_replace( '/\s+/u', ' ', $clean ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $clean, 0, 60, 'UTF-8' );
		}
		return substr( $clean, 0, 60 );
	}

	/**
	 * Genera un código único `HOLA` + hasta 5 letras del nombre + 4 dígitos/letras.
	 */
	public function generate_unique_code( string $name ): string {
		$ascii = $this->ascii_letters_only( $name );
		if ( strlen( $ascii ) < 3 ) {
			$ascii = 'AKIB';
		} else {
			$ascii = substr( $ascii, 0, 5 );
		}

		$alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
		$max_idx  = strlen( $alphabet ) - 1;

		for ( $attempt = 0; $attempt < 15; ++$attempt ) {
			$suffix = '';
			for ( $i = 0; $i < 4; ++$i ) {
				$suffix .= $alphabet[ random_int( 0, $max_idx ) ];
			}
			$candidate = strtoupper( self::COUPON_PREFIX . $ascii . $suffix );
			if ( ! $this->coupon_code_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return strtoupper( self::COUPON_PREFIX . $ascii . substr( strtoupper( bin2hex( random_bytes( 3 ) ) ), 0, 5 ) );
	}

	/**
	 * Calcula el descuento unitario best-wins para un producto exclusivamente sobre su
	 * precio base (`regular_price`).
	 *
	 * @param float $regular_price Precio normal base (`regular_price`).
	 * @param float $current_price Precio actual en el carrito (ya con rebaja o taxonomía).
	 * @param float $pct           Porcentaje del cupón de bienvenida (ej. 10).
	 * @return float Descuento unitario en pesos que le corresponde aportar al cupón.
	 */
	public static function unit_welcome_discount_on_base_price( float $regular_price, float $current_price, float $pct ): float {
		if ( $pct <= 0.0 || $current_price <= 0.0 ) {
			return 0.0;
		}

		$base = $regular_price > 0.0 ? $regular_price : $current_price;
		if ( $current_price > $base ) {
			$base = $current_price;
		}

		$target_price = $base * ( 1.0 - ( $pct / 100.0 ) );
		if ( $current_price <= $target_price ) {
			return 0.0;
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 0;
		return max( 0.0, round( $current_price - $target_price, $decimals ) );
	}

	/**
	 * Calcula el descuento total que el cupón de bienvenida aportaría al carrito actual
	 * exclusivamente sobre el precio base (`regular_price`).
	 */
	public function calculate_cart_welcome_discount( \WC_Cart $cart, float $pct ): float {
		if ( $pct <= 0.0 ) {
			return 0.0;
		}

		$total = 0.0;
		foreach ( $cart->get_cart() as $item ) {
			if ( ! is_array( $item ) || empty( $item['data'] ) || ! $item['data'] instanceof \WC_Product ) {
				continue;
			}
			$product = $item['data'];
			$qty     = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$regular = (float) $product->get_regular_price( 'edit' );
			$current = (float) $product->get_price();

			$total += self::unit_welcome_discount_on_base_price( $regular, $current, $pct ) * $qty;
		}

		return $total;
	}

	/**
	 * Calcula cuánto descuento aporta automáticamente Arma tu Pack (`SeriesBundles`)
	 * para el carrito actual. Si el cliente lleva suficientes tomos para que el pack
	 * supere o iguale el 10% de bienvenida, el cupón de bienvenida no debe aplicarse
	 * porque perjudicaría al cliente.
	 */
	public function calculate_cart_byp_discount( \WC_Cart $cart ): float {
		if ( ! class_exists( SeriesBundlesSettings::class )
			|| ! class_exists( EligibilityGuard::class )
			|| ! class_exists( CartSeriesCollector::class )
			|| ! class_exists( VolumeTierResolver::class )
			|| ! class_exists( DiscountEngine::class ) ) {
			return 0.0;
		}

		$byp_settings = new SeriesBundlesSettings();
		if ( ! $byp_settings->is_enabled() ) {
			return 0.0;
		}

		$guard     = new EligibilityGuard( $byp_settings );
		$collector = new CartSeriesCollector( $byp_settings, $guard );
		$engine    = new DiscountEngine( new VolumeTierResolver() );

		$total = 0.0;
		foreach ( $collector->collect( $cart ) as $data ) {
			if ( empty( $data['items'] ) || empty( $data['tiers'] ) ) {
				continue;
			}
			$total += $engine->calc_byp_discount( array_values( $data['items'] ), $data['tiers'] );
		}

		return $total;
	}

	/**
	 * Regla best-wins: el descuento del cupón de bienvenida se calcula SIEMPRE sobre
	 * el precio base (`regular_price`). Si el producto ya tiene rebaja manual o
	 * descuento editorial menor al %, sólo completa la diferencia hasta alcanzar su %
	 * sobre `regular_price`. Si ya tiene un descuento >= al del cupón, devuelve 0.
	 *
	 * @param mixed $discount           Descuento calculado por WooCommerce.
	 * @param mixed $discounting_amount Monto base que WooCommerce está descontando.
	 * @param mixed $cart_item          Ítem de carrito o línea de orden.
	 * @param mixed $single             True si es unitario.
	 * @param mixed $coupon             Instancia del cupón.
	 * @return float|mixed
	 */
	public function filter_discount_amount_best_wins( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		unset( $single, $discounting_amount );

		if ( ! $coupon instanceof \WC_Coupon || ! self::is_welcome_coupon( $coupon ) ) {
			return $discount;
		}

		$base_discount = (float) $discount;
		if ( $base_discount <= 0.0 ) {
			return 0.0;
		}

		$product = null;
		if ( is_array( $cart_item ) && isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product ) {
			$product = $cart_item['data'];
		} elseif ( is_object( $cart_item ) && method_exists( $cart_item, 'get_product' ) ) {
			$candidate = $cart_item->get_product();
			if ( $candidate instanceof \WC_Product ) {
				$product = $candidate;
			}
		}

		if ( ! $product instanceof \WC_Product ) {
			return $base_discount;
		}

		$pct = (float) $coupon->get_amount();
		if ( $pct <= 0.0 ) {
			return 0.0;
		}

		$regular = (float) $product->get_regular_price( 'edit' );
		$current = (float) $product->get_price();

		if ( $regular <= 0.0 || $current <= 0.0 || $current >= $regular ) {
			return $base_discount;
		}

		$needed_unit = self::unit_welcome_discount_on_base_price( $regular, $current, $pct );
		if ( $needed_unit <= 0.0 ) {
			// El tomo ya tiene un descuento igual o superior al % de bienvenida sobre su precio base.
			return 0.0;
		}

		// Proporción exacta del complemento respecto del descuento porcentual plano sobre $current.
		$raw_percent_unit = $current * ( $pct / 100.0 );
		if ( $raw_percent_unit <= 0.0 ) {
			return 0.0;
		}

		$scaled   = $base_discount * ( $needed_unit / $raw_percent_unit );
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 0;

		return min( $base_discount, max( 0.0, round( $scaled, $decimals ) ) );
	}

	/**
	 * Valida en `woocommerce_coupon_is_valid` que:
	 *   1. Quien usa el cupón sea un comprador de primera compra y coincida con el correo.
	 *   2. El cupón realmente convenga al cliente frente a ofertas existentes o Arma tu Pack
	 *      (si lleva más tomos y el pack supera o iguala el 10% de bienvenida, no se aplica).
	 *
	 * @param bool       $valid     Validez actual.
	 * @param \WC_Coupon $coupon    Cupón evaluado.
	 * @param mixed      $discounts Objeto `WC_Discounts`.
	 * @return bool
	 * @throws \Exception Cuando el cupón infringe las reglas o no le conviene al cliente.
	 */
	public function validate_coupon_is_valid( bool $valid, \WC_Coupon $coupon, $discounts = null ): bool {
		if ( ! $valid || ! self::is_welcome_coupon( $coupon ) ) {
			return $valid;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$signals = $this->collect_current_checkout_signals( $user_id );

		$this->assert_coupon_allowed_for_signals( $coupon, $signals, 0 );

		// Solo evaluar conveniencia de carrito vivo cuando no estamos recalculando una orden histórica.
		$is_order_context = is_object( $discounts )
			&& method_exists( $discounts, 'get_object' )
			&& $discounts->get_object() instanceof \WC_Order;

		if ( ! $is_order_context && function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart && ! WC()->cart->is_empty() ) {
			$this->assert_welcome_coupon_benefits_cart( WC()->cart, $coupon );
		}

		return true;
	}

	/**
	 * Verifica que aplicar el cupón de bienvenida realmente beneficie al cliente en el carrito:
	 *   - Si todos los tomos ya tienen un descuento >= al % sobre el precio base, arroja excepción.
	 *   - Si el cliente lleva más tomos y el descuento automático de Arma tu Pack (`SeriesBundles`)
	 *     iguala o supera el descuento de bienvenida, arroja excepción para mantener el descuento
	 *     mayor del pack y no hacerle perder dinero ni quemar su cupón.
	 *
	 * @throws \Exception Cuando el carrito ya tiene un descuento igual o superior.
	 */
	public function assert_welcome_coupon_benefits_cart( \WC_Cart $cart, \WC_Coupon $coupon ): void {
		$pct              = (float) $coupon->get_amount();
		$welcome_discount = $this->calculate_cart_welcome_discount( $cart, $pct );

		if ( $welcome_discount <= 0.0 ) {
			throw new \Exception( 'Los productos de tu carrito ya tienen un descuento igual o mayor al de tu cupón de bienvenida sobre su precio normal. Mantenemos tu oferta actual y tu código sigue disponible.' );
		}

		$byp_discount = $this->calculate_cart_byp_discount( $cart );
		if ( $byp_discount > 0.0 && $byp_discount >= $welcome_discount ) {
			throw new \Exception( 'Por la cantidad de tomos que llevas, tu descuento automático de Arma tu Pack ya iguala o supera el beneficio de bienvenida. Mantenemos el descuento mayor que más te conviene y tu cupón sigue disponible.' );
		}
	}

	/**
	 * Validación anti-abuso en el checkout clásico de WooCommerce.
	 *
	 * @param array<string, mixed> $data   Datos enviados.
	 * @param \WP_Error            $errors Bolsa de errores.
	 */
	public function validate_classic_checkout( array $data, \WP_Error $errors ): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$signals = array(
			'user_id' => $user_id,
			'email'   => strtolower( trim( (string) ( $data['billing_email'] ?? '' ) ) ),
			'phone'   => trim( (string) ( $data['billing_phone'] ?? '' ) ),
			'rut'     => trim( (string) ( $data['billing_rut'] ?? '' ) ),
		);

		foreach ( $cart->get_applied_coupons() as $code ) {
			$coupon = new \WC_Coupon( $code );
			if ( ! self::is_welcome_coupon( $coupon ) ) {
				continue;
			}

			try {
				$this->assert_coupon_allowed_for_signals( $coupon, $signals, 0 );
			} catch ( \Exception $e ) {
				$cart->remove_coupon( (string) $code );
				$errors->add( 'akb_welcome_coupon_invalid', $e->getMessage() );
			}
		}
	}

	/**
	 * Validación anti-abuso en WooCommerce Checkout Blocks (Store API).
	 *
	 * @param \WC_Order $order   Orden borrador actualizada con los datos del request.
	 * @param mixed     $request Request de WP_REST_Request.
	 * @throws \Exception Si el comprador ya tiene compras previas o el correo no coincide.
	 */
	public function validate_store_api_checkout( \WC_Order $order, $request = null ): void {
		unset( $request );

		$coupons = $order->get_coupon_codes();
		$cart    = function_exists( 'WC' ) ? WC()->cart : null;
		if ( empty( $coupons ) && $cart instanceof \WC_Cart ) {
			$coupons = $cart->get_applied_coupons();
		}
		if ( empty( $coupons ) ) {
			return;
		}

		$rut = trim( (string) $order->get_meta( '_billing_rut' ) );
		if ( '' === $rut ) {
			$rut = trim( (string) $order->get_meta( '_wc_other/akibara/rut' ) );
		}

		$signals = array(
			'user_id' => (int) $order->get_customer_id() > 0
				? (int) $order->get_customer_id()
				: ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ),
			'email'   => strtolower( trim( (string) $order->get_billing_email() ) ),
			'phone'   => trim( (string) $order->get_billing_phone() ),
			'rut'     => $rut,
		);

		foreach ( $coupons as $code ) {
			$coupon = new \WC_Coupon( (string) $code );
			if ( ! self::is_welcome_coupon( $coupon ) ) {
				continue;
			}

			try {
				$this->assert_coupon_allowed_for_signals( $coupon, $signals, (int) $order->get_id() );
			} catch ( \Exception $e ) {
				if ( $cart instanceof \WC_Cart ) {
					$cart->remove_coupon( (string) $code );
				}
				if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
					throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
						'akb_welcome_coupon_invalid',
						wp_strip_all_tags( $e->getMessage() ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- mensaje plano para StoreApi.
						400
					);
				}
				throw $e;
			}
		}
	}

	/**
	 * Comprueba que el cupón pueda ser utilizado por las señales de identidad dadas.
	 *
	 * @param \WC_Coupon                                              $coupon           Cupón de bienvenida.
	 * @param array{user_id:int,email:string,phone:string,rut:string} $signals          Señales recolectadas.
	 * @param int                                                     $exclude_order_id Pedido actual a excluir.
	 * @throws \Exception Si alguna regla de titularidad o primera compra falla.
	 */
	public function assert_coupon_allowed_for_signals( \WC_Coupon $coupon, array $signals, int $exclude_order_id = 0 ): void {
		$email = strtolower( trim( $signals['email'] ?? '' ) );

		// 1. Verificar que el correo coincida con el destinatario del cupón.
		$allowed_emails = array_map( 'strtolower', array_map( 'trim', (array) $coupon->get_email_restrictions() ) );
		$meta_recipient = strtolower( trim( (string) $coupon->get_meta( self::META_RECIPIENT_EMAIL, true ) ) );
		if ( '' !== $meta_recipient && ! in_array( $meta_recipient, $allowed_emails, true ) ) {
			$allowed_emails[] = $meta_recipient;
		}

		$meta_norm = strtolower( trim( (string) $coupon->get_meta( self::META_NORMALIZED_EMAIL, true ) ) );
		if ( '' === $meta_norm && '' !== $meta_recipient ) {
			$meta_norm = self::normalize_email( $meta_recipient );
		}

		$norm_email    = self::normalize_email( $email );
		$email_matches = in_array( $email, $allowed_emails, true )
			|| ( '' !== $meta_recipient && $email === $meta_recipient )
			|| ( '' !== $meta_norm && $norm_email === $meta_norm );

		if ( '' !== $email && ! empty( $allowed_emails ) && ! $email_matches ) {
			throw new \Exception( 'Este cupón de bienvenida es personal e intransferible: debes ingresar el mismo correo con el que lo solicitaste.' );
		}

		// 2. Verificar que la persona (cuenta, correo, celular o RUT) no tenga compras previas.
		if ( $this->has_prior_orders_for_identity(
			$email,
			(string) ( $signals['phone'] ?? '' ),
			(string) ( $signals['rut'] ?? '' ),
			(int) ( $signals['user_id'] ?? 0 ),
			$exclude_order_id
		) ) {
			throw new \Exception( 'El cupón de bienvenida es exclusivo para tu primera compra en Akibara (1 uso por persona: RUT, celular y correo).' );
		}
	}

	/**
	 * ¿Esta identidad (correo, celular, RUT o usuario) ya tiene un pedido pagado/activo?
	 */
	public function has_prior_orders_for_identity(
		string $email,
		string $phone = '',
		string $rut = '',
		int $user_id = 0,
		int $exclude_order_id = 0
	): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$base = array(
			'type'   => 'shop_order',
			'status' => self::PRIOR_ORDER_STATUSES,
			'limit'  => 1,
			'return' => 'ids',
		);
		if ( $exclude_order_id > 0 ) {
			$base['exclude'] = array( $exclude_order_id );
		}

		$queries = array();

		if ( $user_id > 0 ) {
			$queries[] = array( 'customer_id' => $user_id );
		}

		$email = strtolower( trim( $email ) );
		if ( '' !== $email ) {
			$queries[] = array( 'billing_email' => $email );
			$norm_email = self::normalize_email( $email );
			if ( $norm_email !== $email ) {
				$queries[] = array( 'billing_email' => $norm_email );
			}
		}

		foreach ( self::phone_variants( $phone ) as $variant ) {
			$queries[] = array( 'billing_phone' => $variant );
		}

		$rut_variants = self::rut_variants( $rut );
		if ( ! empty( $rut_variants ) ) {
			$queries[] = array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- consulta acotada a limit=1 durante validación de cupón.
					'relation' => 'OR',
					array(
						'key'     => '_billing_rut',
						'value'   => $rut_variants,
						'compare' => 'IN',
					),
					array(
						'key'     => '_wc_other/akibara/rut',
						'value'   => $rut_variants,
						'compare' => 'IN',
					),
				),
			);
		}

		foreach ( $queries as $q ) {
			$found = (array) wc_get_orders( $base + $q );
			if ( ! empty( $found ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * ¿El correo pertenece a un cliente que YA realizó al menos una compra en Akibara?
	 *
	 * Nota de arquitectura: un usuario que sólo creó cuenta en la web (p. ej. con
	 * Login with Google) pero tiene 0 pedidos NO es todavía comprador; sí puede
	 * recibir su descuento de primera compra y suscribirse a Brevo.
	 */
	public function email_belongs_to_existing_customer( string $email, int $user_id = 0 ): bool {
		$email = strtolower( trim( $email ) );

		if ( $user_id > 0 && function_exists( 'user_can' ) && user_can( $user_id, 'manage_woocommerce' ) ) {
			// Administrador probando en QA: evaluamos únicamente el correo proporcionado para permitir pruebas repetibles.
			$user_id = 0;
		}

		if ( $user_id <= 0 && '' !== $email && function_exists( 'get_user_by' ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user instanceof \WP_User && (int) $user->ID > 0 ) {
				$user_id = (int) $user->ID;
			}
		}

		return $this->has_prior_orders_for_identity( $email, '', '', $user_id );
	}

	/**
	 * ¿Este navegador o usuario ya completó o utilizó el flujo de bienvenida?
	 */
	public function has_completed_welcome_flow( int $user_id = 0 ): bool {
		if ( ! empty( $_COOKIE[ self::COOKIE_DONE ] ) ) {
			return true;
		}

		if ( $user_id > 0 ) {
			if ( $this->has_prior_orders_for_identity( '', '', '', $user_id ) ) {
				return true;
			}
			if ( function_exists( 'get_userdata' ) ) {
				$user = get_userdata( $user_id );
				if ( $user instanceof \WP_User && '' !== (string) $user->user_email ) {
					$email = strtolower( trim( (string) $user->user_email ) );
					if ( $this->has_prior_orders_for_identity( $email, '', '', $user_id ) ) {
						return true;
					}
					if ( null !== $this->find_existing_coupon_for_email( $email ) ) {
						return true;
					}
				}
			}
		}

		if ( function_exists( 'WC' ) && WC()->customer instanceof \WC_Customer ) {
			$billing_email = strtolower( trim( (string) WC()->customer->get_billing_email() ) );
			if ( '' !== $billing_email ) {
				if ( $this->has_prior_orders_for_identity( $billing_email ) ) {
					return true;
				}
				if ( null !== $this->find_existing_coupon_for_email( $billing_email ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Captura `?akb_cupon=CODIGO` al entrar desde el correo o desde el popup promocional
	 * y lo guarda en la sesión de WooCommerce.
	 */
	public function capture_coupon_from_url(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- enlace público desde el correo o popup.
		if ( empty( $_GET[ self::QUERY_ARG_COUPON ] ) || ! is_string( $_GET[ self::QUERY_ARG_COUPON ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw  = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG_COUPON ] ) );
		$code = function_exists( 'wc_format_coupon_code' ) ? wc_format_coupon_code( $raw ) : strtolower( trim( $raw ) );
		if ( '' === $code ) {
			return;
		}

		$coupon          = new \WC_Coupon( $code );
		$active_promo    = strtolower( Settings::promo_code() );
		$is_promo_coupon = '' !== $active_promo && strtolower( $code ) === $active_promo && (int) $coupon->get_id() > 0;

		if ( (int) $coupon->get_id() <= 0 || ( ! self::is_welcome_coupon( $coupon ) && ! $is_promo_coupon ) ) {
			return;
		}

		if ( self::is_welcome_coupon( $coupon ) ) {
			$this->set_done_cookie();
		}

		// Guardar en cookie no intrusiva de 7 días para no forzar sesión de WooCommerce ni bypass de caché en visitas estáticas
		if ( ! headers_sent() ) {
			setcookie( 'akb_pending_cupon', $code, time() + 7 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
		}
		$_COOKIE['akb_pending_cupon'] = $code;

		// Solo persistir en WC_Session si el carrito tiene productos o la sesión ya existía previamente
		if ( function_exists( 'WC' ) && null !== WC()->session ) {
			$has_active_cart = WC()->cart instanceof \WC_Cart && ! WC()->cart->is_empty();
			if ( WC()->session->has_session() || $has_active_cart ) {
				if ( ! WC()->session->has_session() && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
					WC()->session->set_customer_session_cookie( true );
				}
				WC()->session->set( self::SESSION_PENDING_COUPON, $code );
			}
		}

		$this->maybe_apply_pending_coupon();
	}

	/**
	 * Aplica el cupón pendiente en sesión o cookie si el carrito ya cumple el mínimo y le conviene al cliente.
	 */
	public function maybe_apply_pending_coupon(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return;
		}

		$cart = WC()->cart;
		if ( $cart->is_empty() ) {
			return;
		}

		$pending = '';
		if ( null !== WC()->session ) {
			$pending = (string) WC()->session->get( self::SESSION_PENDING_COUPON, '' );
		}
		if ( '' === $pending && ! empty( $_COOKIE['akb_pending_cupon'] ) && is_string( $_COOKIE['akb_pending_cupon'] ) ) {
			$pending = sanitize_text_field( wp_unslash( $_COOKIE['akb_pending_cupon'] ) );
		}
		if ( '' === $pending ) {
			return;
		}

		if ( $cart->has_discount( $pending ) ) {
			if ( null !== WC()->session ) {
				WC()->session->set( self::SESSION_PENDING_COUPON, null );
			}
			if ( ! headers_sent() ) {
				setcookie( 'akb_pending_cupon', '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
			}
			unset( $_COOKIE['akb_pending_cupon'] );
			return;
		}

		$coupon          = new \WC_Coupon( $pending );
		$active_promo    = strtolower( Settings::promo_code() );
		$is_promo_coupon = '' !== $active_promo && strtolower( $pending ) === $active_promo && (int) $coupon->get_id() > 0;

		if ( (int) $coupon->get_id() <= 0 || ( ! self::is_welcome_coupon( $coupon ) && ! $is_promo_coupon ) ) {
			if ( null !== WC()->session ) {
				WC()->session->set( self::SESSION_PENDING_COUPON, null );
			}
			if ( ! headers_sent() ) {
				setcookie( 'akb_pending_cupon', '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
			}
			unset( $_COOKIE['akb_pending_cupon'] );
			return;
		}

		$min_amount = (float) $coupon->get_minimum_amount();
		$subtotal   = (float) $cart->get_displayed_subtotal();
		if ( $min_amount > 0 && $subtotal > 0 && $subtotal < $min_amount ) {
			return;
		}

		if ( self::is_welcome_coupon( $coupon ) ) {
			try {
				$this->assert_welcome_coupon_benefits_cart( $cart, $coupon );
			} catch ( \Exception $e ) {
				// El carrito ya tiene un descuento mejor (p. ej. Arma tu Pack > 10%):
				// conservamos el código en sesión sin aplicarlo encima.
				return;
			}
		}

		$cart->apply_coupon( $pending );
		if ( null !== WC()->session ) {
			WC()->session->set( self::SESSION_PENDING_COUPON, null );
		}
		if ( ! headers_sent() ) {
			setcookie( 'akb_pending_cupon', '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
		}
		unset( $_COOKIE['akb_pending_cupon'] );
	}

	/**
	 * Hook en `woocommerce_before_calculate_totals` (prioridad 25, antes de `BundleCouponEngine` en 1000):
	 *   1. Si un cupón de bienvenida YA estaba aplicado en el carrito pero el cliente agregó más
	 *      tomos y ahora Arma tu Pack (`SeriesBundles`) iguala o supera el 10% de bienvenida,
	 *      retira el cupón de bienvenida y lo devuelve a sesión para que `BundleCouponEngine`
	 *      aplique el descuento mayor del pack en esta misma pasada.
	 *   2. Si hay un cupón pendiente en sesión y el carrito ya alcanza el mínimo y le conviene,
	 *      lo aplica automáticamente.
	 *
	 * @param \WC_Cart $cart Carrito actual.
	 */
	public function maybe_apply_pending_coupon_on_totals( \WC_Cart $cart ): void {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return;
		}

		static $applying = false;
		if ( $applying ) {
			return;
		}

		// 1. Verificar si un cupón de bienvenida ya aplicado dejó de convenir frente a Arma tu Pack.
		foreach ( $cart->get_applied_coupons() as $applied_code ) {
			$applied_coupon = new \WC_Coupon( (string) $applied_code );
			if ( ! self::is_welcome_coupon( $applied_coupon ) ) {
				continue;
			}

			$pct              = (float) $applied_coupon->get_amount();
			$welcome_discount = $this->calculate_cart_welcome_discount( $cart, $pct );
			$byp_discount     = $this->calculate_cart_byp_discount( $cart );

			if ( $welcome_discount <= 0.0 || ( $byp_discount > 0.0 && $byp_discount >= $welcome_discount ) ) {
				$applying = true;
				try {
					$cart->remove_coupon( (string) $applied_code );
					WC()->session->set( self::SESSION_PENDING_COUPON, (string) $applied_code );
					if ( function_exists( 'wc_add_notice' ) ) {
						wc_add_notice(
							'Por la cantidad de tomos que llevas, activamos automáticamente tu descuento mayor de Arma tu Pack y guardamos tu cupón de bienvenida.',
							'notice'
						);
					}
				} finally {
					$applying = false;
				}
				return;
			}
		}

		// 2. Auto-aplicar cupón pendiente en sesión si corresponde y conviene.
		$pending = (string) WC()->session->get( self::SESSION_PENDING_COUPON, '' );
		if ( '' === $pending || $cart->is_empty() || $cart->has_discount( $pending ) ) {
			return;
		}

		$coupon          = new \WC_Coupon( $pending );
		$active_promo    = strtolower( Settings::promo_code() );
		$is_promo_coupon = '' !== $active_promo && strtolower( $pending ) === $active_promo && (int) $coupon->get_id() > 0;

		if ( (int) $coupon->get_id() <= 0 || ( ! self::is_welcome_coupon( $coupon ) && ! $is_promo_coupon ) ) {
			WC()->session->set( self::SESSION_PENDING_COUPON, null );
			return;
		}

		$min_amount = (float) $coupon->get_minimum_amount();
		$subtotal   = 0.0;
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
				$subtotal += (float) $item['data']->get_price() * max( 1, (int) ( $item['quantity'] ?? 1 ) );
			}
		}

		if ( $min_amount > 0 && $subtotal < $min_amount ) {
			return;
		}

		if ( self::is_welcome_coupon( $coupon ) ) {
			$pct              = (float) $coupon->get_amount();
			$welcome_discount = $this->calculate_cart_welcome_discount( $cart, $pct );
			$byp_discount     = $this->calculate_cart_byp_discount( $cart );
			if ( $welcome_discount <= 0.0 || ( $byp_discount > 0.0 && $byp_discount >= $welcome_discount ) ) {
				return;
			}
		}

		$applying = true;
		try {
			$cart->apply_coupon( $pending );
			WC()->session->set( self::SESSION_PENDING_COUPON, null );
		} finally {
			$applying = false;
		}
	}

	/**
	 * Marca el cupón de bienvenida como usado cuando entra un pedido y notifica a los listeners.
	 *
	 * @param int|\WC_Order $order_or_id Pedido o su ID.
	 */
	public function mark_order_coupon_used( $order_or_id ): void {
		$order = $order_or_id instanceof \WC_Order
			? $order_or_id
			: ( function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_or_id ) : null );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		foreach ( $order->get_coupon_codes() as $code ) {
			$coupon = new \WC_Coupon( (string) $code );
			$id     = (int) $coupon->get_id();
			if ( $id <= 0 || ! self::is_welcome_coupon( $coupon ) ) {
				continue;
			}

			$this->set_done_cookie();

			if ( '1' === (string) $coupon->get_meta( self::META_USED_NOTIFIED, true ) ) {
				continue;
			}

			$coupon->update_meta_data( self::META_USED_NOTIFIED, '1' );
			$coupon->update_meta_data( self::META_USED_ORDER_ID, (string) $order->get_id() );
			$coupon->save();

			$email = strtolower( trim( (string) $coupon->get_meta( self::META_RECIPIENT_EMAIL, true ) ) );
			if ( '' === $email ) {
				$email = strtolower( trim( (string) $order->get_billing_email() ) );
			}

			/**
			 * Dispara la primera vez que un cupón de bienvenida se consume en un pedido.
			 *
			 * @since 1.120.0
			 *
			 * @param array<string, mixed> $payload Datos del cupón usado.
			 */
			do_action(
				'akb_welcome_coupon_used',
				array(
					'coupon_id'   => $id,
					'coupon_code' => strtoupper( (string) $coupon->get_code() ),
					'email'       => $email,
					'order_id'    => (int) $order->get_id(),
				)
			);
		}
	}

	/**
	 * Restaura el cupón de bienvenida si un pedido falla, se cancela o se reembolsa.
	 *
	 * Permite que el cliente que sufrió un rechazo de tarjeta en Webpay o canceló
	 * una transferencia no pierda su beneficio de bienvenida y pueda volver a ocuparlo.
	 *
	 * @param int|\WC_Order $order_or_id Pedido cancelado/fallido.
	 */
	public function restore_order_coupon( $order_or_id ): void {
		$order = $order_or_id instanceof \WC_Order
			? $order_or_id
			: ( function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_or_id ) : null );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		foreach ( $order->get_coupon_codes() as $code ) {
			$coupon = new \WC_Coupon( (string) $code );
			$id     = (int) $coupon->get_id();
			if ( $id <= 0 || ! self::is_welcome_coupon( $coupon ) ) {
				continue;
			}

			// Limpiar metas de uso previo en el cupón
			$coupon->delete_meta_data( self::META_USED_NOTIFIED );
			$coupon->delete_meta_data( self::META_USED_ORDER_ID );

			// Si WooCommerce no redujo el usage_count o quedó positivo, asegurar que se libere
			$current_usage = (int) $coupon->get_usage_count();
			if ( $current_usage > 0 ) {
				$coupon->set_usage_count( max( 0, $current_usage - 1 ) );
			}
			$coupon->save();

			$this->clear_done_cookie();

			$email = strtolower( trim( (string) $coupon->get_meta( self::META_RECIPIENT_EMAIL, true ) ) );
			if ( '' === $email ) {
				$email = strtolower( trim( (string) $order->get_billing_email() ) );
			}

			/**
			 * Dispara cuando un cupón de bienvenida se restaura tras fallo o cancelación del pedido.
			 *
			 * @since 1.120.0
			 *
			 * @param array<string, mixed> $payload Datos del cupón restaurado.
			 */
			do_action(
				'akb_welcome_coupon_restored',
				array(
					'coupon_id'   => $id,
					'coupon_code' => strtoupper( (string) $coupon->get_code() ),
					'email'       => $email,
					'order_id'    => (int) $order->get_id(),
				)
			);
		}
	}

	/**
	 * Busca si existe un cupón de bienvenida emitido para el correo dado.
	 *
	 * @return array{coupon_id:int,coupon_code:string,discount_pct:int,min_amount:int,expires_ts:int,used:bool,expired:bool}|null
	 */
	public function find_existing_coupon_for_email( string $email ): ?array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! function_exists( 'get_posts' ) ) {
			return null;
		}

		$norm_email = self::normalize_email( $email );

		$posts = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- consulta puntual al suscribirse (protegida por RateLimit).
					'relation' => 'AND',
					array(
						'key'   => self::META_IS_WELCOME,
						'value' => '1',
					),
					array(
						'relation' => 'OR',
						array(
							'key'   => self::META_RECIPIENT_EMAIL,
							'value' => $email,
						),
						array(
							'key'   => self::META_NORMALIZED_EMAIL,
							'value' => $norm_email,
						),
						array(
							'key'   => self::META_RECIPIENT_EMAIL,
							'value' => $norm_email,
						),
					),
				),
			)
		);

		if ( empty( $posts ) ) {
			return null;
		}

		$coupon_id = (int) $posts[0];
		$coupon    = new \WC_Coupon( $coupon_id );
		if ( (int) $coupon->get_id() <= 0 ) {
			return null;
		}

		$expires_obj = $coupon->get_date_expires();
		$expires_ts  = $expires_obj instanceof \WC_DateTime
			? $expires_obj->getTimestamp()
			: (int) $coupon->get_meta( self::META_EXPIRES_TS, true );

		$used = (int) $coupon->get_usage_count() > 0
			|| '1' === (string) $coupon->get_meta( self::META_USED_NOTIFIED, true );

		$expired = $expires_ts > 0 && $expires_ts < time();

		return array(
			'coupon_id'    => $coupon_id,
			'coupon_code'  => strtoupper( (string) $coupon->get_code() ),
			'discount_pct' => (int) round( (float) $coupon->get_amount() ),
			'min_amount'   => (int) round( (float) $coupon->get_minimum_amount() ),
			'expires_ts'   => $expires_ts,
			'used'         => $used,
			'expired'      => $expired,
		);
	}

	/**
	 * Normaliza un correo para prevenir evasión de cupones mediante alias (puntos y tags).
	 *
	 * Reglas:
	 *   - En Gmail: remueve puntos de la casilla (j.u.a.n = juan) y sub-addressing (+tag).
	 *   - En cualquier proveedor: remueve sub-addressing (+tag).
	 *   - Homologa dominios equivalentes (googlemail.com -> gmail.com).
	 *
	 * @param string $email Correo electrónico.
	 * @return string Correo normalizado en minúsculas.
	 */
	public static function normalize_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || strpos( $email, '@' ) === false ) {
			return $email;
		}

		$parts  = explode( '@', $email, 2 );
		$local  = $parts[0];
		$domain = $parts[1];

		if ( 'googlemail.com' === $domain ) {
			$domain = 'gmail.com';
		}

		if ( 'gmail.com' === $domain ) {
			$local = str_replace( '.', '', $local );
		}

		$plus_pos = strpos( $local, '+' );
		if ( false !== $plus_pos ) {
			$local = substr( $local, 0, $plus_pos );
		}

		return $local . '@' . $domain;
	}

	/**
	 * Comprueba si el correo pertenece a un proveedor de casillas temporales o desechables.
	 *
	 * @param string $email Correo electrónico.
	 * @return bool True si es desechable.
	 */
	public static function is_disposable_email( string $email ): bool {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || strpos( $email, '@' ) === false ) {
			return false;
		}

		$domain  = (string) substr( strrchr( $email, '@' ) ?: '', 1 );
		$domains = (array) apply_filters( 'akb_welcome_coupon_disposable_domains', self::DISPOSABLE_EMAIL_DOMAINS );

		return in_array( $domain, $domains, true );
	}

	/**
	 * Sugerencia de corrección para un dominio con error de tipeo común.
	 *
	 * @param string $email Correo a evaluar.
	 * @return string|null Dominio sugerido si coincide con un error frecuente, o null si está bien.
	 */
	public static function suggest_domain_correction( string $email ): ?string {
		$domain = strtolower( (string) substr( strrchr( $email, '@' ) ?: '', 1 ) );
		return self::COMMON_DOMAIN_TYPOS[ $domain ] ?? null;
	}

	/**
	 * Verifica si el dominio de correo tiene registros de correo activos (MX o A).
	 *
	 * @param string $email Correo a evaluar.
	 * @return bool True si el dominio puede recibir correos o si no se pudo comprobar DNS.
	 */
	public static function has_valid_mail_domain( string $email ): bool {
		$domain = strtolower( (string) substr( strrchr( $email, '@' ) ?: '', 1 ) );
		if ( '' === $domain ) {
			return false;
		}

		// Whitelist en memoria O(1) de los dominios más frecuentes en Chile (Gmail, Hotmail, Outlook, etc.)
		// Evita latencia de resolución DNS (100-500 ms) en el 99.5% de los visitantes reales.
		if ( isset( self::TRUSTED_MAIL_DOMAINS[ $domain ] ) ) {
			return true;
		}

		if ( ! function_exists( 'checkdnsrr' ) ) {
			return true;
		}

		return checkdnsrr( $domain, 'MX' ) || checkdnsrr( $domain, 'A' );
	}

	/**
	 * Variantes de teléfono chileno para cruce de identidad.
	 *
	 * @return array<int, string>
	 */
	public static function phone_variants( string $raw ): array {
		if ( class_exists( '\Akibara\Rewards\Points\FirstPurchase' ) ) {
			return \Akibara\Rewards\Points\FirstPurchase::phone_variants( $raw );
		}

		$digits = (string) preg_replace( '/\D+/', '', $raw );
		if ( strlen( $digits ) >= 11 && 0 === strpos( $digits, '56' ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( strlen( $digits ) < 8 ) {
			return array();
		}

		return array_values( array_unique( array( '+56' . $digits, $digits, '56' . $digits ) ) );
	}

	/**
	 * Variantes de RUT chileno para cruce de identidad.
	 *
	 * @return array<int, string>
	 */
	public static function rut_variants( string $raw ): array {
		if ( class_exists( '\Akibara\Rewards\Points\FirstPurchase' ) ) {
			return \Akibara\Rewards\Points\FirstPurchase::rut_variants( $raw );
		}

		$clean = strtoupper( (string) preg_replace( '/[^0-9kK]/', '', $raw ) );
		if ( strlen( $clean ) < 2 ) {
			return array();
		}

		$body = ltrim( substr( $clean, 0, -1 ), '0' );
		$dv   = substr( $clean, -1 );
		if ( '' === $body || ! ctype_digit( $body ) ) {
			return array();
		}

		$dotted = number_format( (float) $body, 0, '', '.' );
		return array_values(
			array_unique(
				array(
					$dotted . '-' . $dv,
					$body . '-' . $dv,
					$body . $dv,
					'0' . $dotted . '-' . $dv,
					'0' . $body . '-' . $dv,
				)
			)
		);
	}

	/**
	 * Sella la cookie `akb_wc_done=1` por 1 año cuando el cliente ya obtuvo o usó su cupón.
	 */
	private function set_done_cookie(): void {
		$_COOKIE[ self::COOKIE_DONE ] = '1';
		if ( ! headers_sent() ) {
			$secure = function_exists( 'is_ssl' ) && is_ssl();
			setcookie( self::COOKIE_DONE, '1', time() + YEAR_IN_SECONDS, '/', '', $secure, false );
		}
	}

	/**
	 * Limpia la cookie `akb_wc_done` para permitir que el cliente vuelva a ver su beneficio si su compra falló.
	 */
	private function clear_done_cookie(): void {
		unset( $_COOKIE[ self::COOKIE_DONE ] );
		if ( ! headers_sent() ) {
			$secure = function_exists( 'is_ssl' ) && is_ssl();
			setcookie( self::COOKIE_DONE, '', time() - HOUR_IN_SECONDS, '/', '', $secure, false );
		}
	}

	/**
	 * Recolecta las señales de identidad disponibles en la sesión/request actual.
	 *
	 * @return array{user_id:int,email:string,phone:string,rut:string}
	 */
	private function collect_current_checkout_signals( int $user_id ): array {
		$email = '';
		$phone = '';
		$rut   = '';

		if ( $user_id > 0 && function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof \WP_User ) {
				$email = (string) $user->user_email;
			}
			if ( function_exists( 'get_user_meta' ) ) {
				$phone = (string) get_user_meta( $user_id, 'billing_phone', true );
				$rut   = (string) get_user_meta( $user_id, 'billing_rut', true );
			}
		}

		if ( function_exists( 'WC' ) && WC()->customer instanceof \WC_Customer ) {
			$cust_email = (string) WC()->customer->get_billing_email();
			$cust_phone = (string) WC()->customer->get_billing_phone();
			$cust_rut   = (string) WC()->customer->get_meta( '_billing_rut' );
			if ( '' !== $cust_email ) {
				$email = $cust_email;
			}
			if ( '' !== $cust_phone ) {
				$phone = $cust_phone;
			}
			if ( '' !== $cust_rut ) {
				$rut = $cust_rut;
			}
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- lectura defensiva de señales durante validación de cupón.
		if ( ! empty( $_POST['billing_email'] ) && is_string( $_POST['billing_email'] ) ) {
			$email = sanitize_email( wp_unslash( $_POST['billing_email'] ) );
		}
		if ( ! empty( $_POST['billing_phone'] ) && is_string( $_POST['billing_phone'] ) ) {
			$phone = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) );
		}
		if ( ! empty( $_POST['billing_rut'] ) && is_string( $_POST['billing_rut'] ) ) {
			$rut = sanitize_text_field( wp_unslash( $_POST['billing_rut'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array(
			'user_id' => $user_id,
			'email'   => strtolower( trim( $email ) ),
			'phone'   => trim( $phone ),
			'rut'     => trim( $rut ),
		);
	}

	/**
	 * Extrae letras ASCII en mayúsculas desde el nombre para armar el código.
	 */
	private function ascii_letters_only( string $name ): string {
		$trans = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$base  = is_string( $trans ) && '' !== $trans ? $trans : $name;
		return strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', $base ) );
	}

	/**
	 * ¿Ya existe un cupón con este código en WooCommerce?
	 */
	private function coupon_code_exists( string $code ): bool {
		if ( function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return (int) wc_get_coupon_id_by_code( $code ) > 0;
		}
		return false;
	}

	/**
	 * Desbloquea el conteo de uso para cupones retenidos en pedidos `pending`, `failed` o `cancelled`
	 * (típico de clientes que regresan de MercadoPago o Flow para reintentar el pago tras abandono o rechazo).
	 *
	 * @param int        $usage_count Conteo reportado por WooCommerce.
	 * @param \WC_Coupon $coupon      Instancia del cupón.
	 * @return int Conteo efectivo (0 si sólo está en órdenes pendientes sin pagar).
	 */
	public function filter_coupon_usage_count( int $usage_count, \WC_Coupon $coupon ): int {
		if ( $usage_count <= 0 || ! self::is_welcome_coupon( $coupon ) ) {
			return $usage_count;
		}

		if ( '1' === (string) $coupon->get_meta( self::META_USED_NOTIFIED, true ) ) {
			return max( 1, $usage_count );
		}

		if ( $this->coupon_has_paid_order( $coupon ) ) {
			return $usage_count;
		}

		return 0;
	}

	/**
	 * Verifica si el cupón está asociado a algún pedido con estado de compra pagada.
	 */
	private function coupon_has_paid_order( \WC_Coupon $coupon ): bool {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$code = strtolower( trim( (string) $coupon->get_code() ) );
		if ( '' === $code ) {
			return false;
		}

		$paid_statuses = array( 'wc-processing', 'wc-completed', 'wc-shipping-progress', 'wc-ready-to-ship' );
		$placeholders  = implode( ',', array_fill( 0, count( $paid_statuses ), '%s' ) );

		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders_table = function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore' )
				? \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name()
				: $wpdb->prefix . 'wc_orders';

			$sql = $wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items oi
				 INNER JOIN {$orders_table} o ON oi.order_id = o.id
				 WHERE oi.order_item_type = 'coupon'
				   AND LOWER(oi.order_item_name) = %s
				   AND o.status IN ($placeholders)
				 LIMIT 1",
				array_merge( array( $code ), $paid_statuses )
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items oi
				 INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
				 WHERE oi.order_item_type = 'coupon'
				   AND LOWER(oi.order_item_name) = %s
				   AND p.post_status IN ($placeholders)
				 LIMIT 1",
				array_merge( array( $code ), $paid_statuses )
			);
		}

		try {
			return (int) $wpdb->get_var( $sql ) === 1;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Agenda la tarea recurrente semanal de Action Scheduler para depurar cupones vencidos sin uso.
	 */
	public function maybe_schedule_garbage_collector(): void {
		$is_backend = ( function_exists( 'is_admin' ) && is_admin() )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
		if ( ! $is_backend ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( false === as_next_scheduled_action( self::GC_HOOK, array(), self::GC_GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, WEEK_IN_SECONDS, self::GC_HOOK, array(), self::GC_GROUP );
		}
	}

	/**
	 * Limpia cupones de bienvenida vencidos hace más de 15 días que jamás fueron utilizados.
	 * Protege el margen y la base de datos limitando a lotes de 50 ítems.
	 * NUNCA borra cupones que tengan `_akb_welcome_used_notified = 1` ni pedidos asociados.
	 *
	 * @return int Cantidad de cupones eliminados.
	 */
	public function run_garbage_collector(): int {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return 0;
		}

		$cutoff = time() - ( 15 * DAY_IN_SECONDS );

		$sql = $wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm_exp ON p.ID = pm_exp.post_id AND pm_exp.meta_key = %s
			 INNER JOIN {$wpdb->postmeta} pm_wel ON p.ID = pm_wel.post_id AND pm_wel.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} pm_used ON p.ID = pm_used.post_id AND pm_used.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} pm_usage ON p.ID = pm_usage.post_id AND pm_usage.meta_key = 'usage_count'
			 WHERE p.post_type = 'shop_coupon'
			   AND pm_wel.meta_value = '1'
			   AND CAST(pm_exp.meta_value AS UNSIGNED) < %d
			   AND (pm_used.meta_value IS NULL OR pm_used.meta_value != '1')
			   AND (pm_usage.meta_value IS NULL OR CAST(pm_usage.meta_value AS UNSIGNED) = 0)
			 LIMIT 50",
			self::META_EXPIRES_TS,
			self::META_IS_WELCOME,
			self::META_USED_NOTIFIED,
			$cutoff
		);

		try {
			$coupon_ids = $wpdb->get_col( $sql );
		} catch ( \Throwable $e ) {
			return 0;
		}

		if ( empty( $coupon_ids ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $coupon_ids as $cid ) {
			$cid = (int) $cid;
			if ( $cid <= 0 ) {
				continue;
			}
			$coupon = new \WC_Coupon( $cid );
			if ( (int) $coupon->get_usage_count() > 0 || '1' === (string) $coupon->get_meta( self::META_USED_NOTIFIED, true ) ) {
				continue;
			}
			if ( function_exists( 'wp_delete_post' ) ) {
				wp_delete_post( $cid, true );
				$deleted++;
			}
		}

		return $deleted;
	}
}
