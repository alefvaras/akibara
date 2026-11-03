<?php
/**
 * RestController — endpoint público LiteSpeed-safe para emitir el cupón de
 * primera compra.
 *
 * POST /wp-json/akibara/v1/welcome-coupon  { name, email, akb_hp_url, elapsed_ms, cf-turnstile-response }
 *
 * Capas anti-abuso:
 *   1. Cloudflare Turnstile (`verify_turnstile`) → desafío invisible contra bots.
 *   2. Honeypot (`Honeypot::tripped`) → responde 201 señuelo idéntico a un alta
 *      real para que los bots no puedan detectar el filtro.
 *   3. Tiempo mínimo de llenado (`elapsed_ms < 800`) → 201 señuelo ante envíos
 *      instantáneos automatizados.
 *   4. Validación de nombre y correo (`is_email`, longitud <= 100).
 *   5. Bloqueo de dominios desechables / temporales (`is_disposable_email`).
 *   6. Intercepción de typos tipográficos (`suggest_domain_correction`).
 *   7. Validación DNS MX en vivo (`has_valid_mail_domain`).
 *   8. Rechazo de usuarios con sesión iniciada o clientes con compras previas.
 *   9. `RateLimit::exceeded( 'welcome_coupon')` por IP (Cloudflare-aware).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\WelcomeCoupon;

use Akibara\Business\Modules\Forms\Honeypot;
use Akibara\Business\Utilities\RateLimit;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.120.0
 */
final class RestController {

	public const REST_NAMESPACE = 'akibara/v1';
	public const REST_ROUTE     = '/welcome-coupon';

	private const MIN_ELAPSED_MS = 800;

	private CouponService $coupon_service;

	public function __construct( CouponService $coupon_service ) {
		$this->coupon_service = $coupon_service;
	}

	/**
	 * Registra la ruta REST.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declara el endpoint público `POST /wp-json/akibara/v1/welcome-coupon`.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Adaptador HTTP: extrae parámetros planos y delega en `subscribe()`.
	 *
	 * @param WP_REST_Request $request Petición REST.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$params = (array) $request->get_params();

		if ( Honeypot::tripped( $params ) ) {
			return new WP_REST_Response( $this->decoy_response(), 201 );
		}

		if ( Settings::turnstile_enabled() ) {
			$turnstile_token = isset( $params['cf-turnstile-response'] )
				? (string) $params['cf-turnstile-response']
				: ( isset( $params['cf_turnstile_response'] ) ? (string) $params['cf_turnstile_response'] : '' );

			if ( ! $this->verify_turnstile( $turnstile_token ) ) {
				return new WP_REST_Response(
					array(
						'ok'      => false,
						'code'    => 'turnstile_failed',
						'message' => 'No pudimos verificar la seguridad de la conexión con Cloudflare. Por favor recarga e intenta de nuevo.',
					),
					400
				);
			}
			// Con Turnstile verificado criptográficamente por Cloudflare, se exonera `is_too_fast`
			// para permitir que usuarios legítimos con Autofill en móviles (<800ms) reciban su cupón real.
		} elseif ( $this->is_too_fast( $params ) ) {
			return new WP_REST_Response( $this->decoy_response(), 201 );
		}

		$name    = isset( $params['name'] ) && is_scalar( $params['name'] )
			? sanitize_text_field( wp_unslash( (string) $params['name'] ) )
			: '';
		$email   = isset( $params['email'] ) && is_scalar( $params['email'] )
			? sanitize_email( wp_unslash( (string) $params['email'] ) )
			: '';
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		$result = $this->subscribe( $name, $email, $user_id );
		$status = (int) ( $result['status'] ?? 200 );
		unset( $result['status'] );

		return new WP_REST_Response( $result, $status );
	}

	/**
	 * Núcleo del alta de bienvenida, independiente de `WP_REST_Request` para tests.
	 *
	 * @param string $name    Nombre ingresado.
	 * @param string $email   Correo ya pasado por `sanitize_email()`.
	 * @param int    $user_id Usuario en sesión (0 si es visitante).
	 * @return array<string, mixed>
	 */
	public function subscribe( string $name, string $email, int $user_id = 0 ): array {
		if ( ! Settings::is_enabled( false ) ) {
			return array(
				'ok'      => false,
				'code'    => 'disabled',
				'message' => 'Esta promoción de bienvenida no está disponible en este momento.',
				'status'  => 503,
			);
		}

		$name  = CouponService::clean_subscriber_name( $name );
		$email = strtolower( trim( $email ) );

		if ( strlen( $name ) < 2 ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_name',
				'message' => 'Ingresa tu nombre para generar tu código personalizado.',
				'status'  => 400,
			);
		}

		if ( '' === $email || strlen( $email ) > 100 || ! is_email( $email ) ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_email',
				'message' => 'Ingresa un correo válido.',
				'status'  => 400,
			);
		}

		if ( CouponService::is_disposable_email( $email ) ) {
			return array(
				'ok'      => false,
				'code'    => 'disposable_email',
				'message' => 'Ingresa un correo personal o de trabajo válido para recibir tu cupón.',
				'status'  => 400,
			);
		}

		$typo_suggestion = CouponService::suggest_domain_correction( $email );
		if ( null !== $typo_suggestion ) {
			return array(
				'ok'      => false,
				'code'    => 'email_domain_typo',
				'message' => sprintf( '¿Quisiste escribir @%s? Por favor revisa que tu correo esté bien escrito.', $typo_suggestion ),
				'status'  => 400,
			);
		}

		if ( ! CouponService::has_valid_mail_domain( $email ) ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_mail_domain',
				'message' => 'El dominio de este correo no parece recibir mensajes. Por favor revisa que esté bien escrito.',
				'status'  => 400,
			);
		}

		if ( RateLimit::exceeded( 'welcome_coupon' ) ) {
			return array(
				'ok'      => false,
				'code'    => 'rate_limited',
				'message' => 'Demasiados intentos desde tu conexión. Por favor vuelve a intentarlo en una hora.',
				'status'  => 429,
			);
		}

		return $this->coupon_service->issue_for_subscriber( $name, $email, $user_id );
	}

	/**
	 * ¿El envío ocurrió en menos de 800 ms desde que se abrió el modal?
	 *
	 * @param array<string, mixed> $params Parámetros del request.
	 */
	private function is_too_fast( array $params ): bool {
		if ( ! isset( $params['elapsed_ms'] ) || ! is_numeric( $params['elapsed_ms'] ) ) {
			return false;
		}

		return (int) $params['elapsed_ms'] > 0 && (int) $params['elapsed_ms'] < self::MIN_ELAPSED_MS;
	}

	/**
	 * Respuesta señuelo para bots que caen en el honeypot o disparo instantáneo.
	 *
	 * @return array<string, mixed>
	 */
	private function decoy_response(): array {
		$pct     = Settings::discount_pct();
		$expires = CouponService::calculate_expiration_timestamp( time(), Settings::validity_days() );

		return array(
			'ok'                   => true,
			'code'                 => 'issued',
			'message'              => '¡Listo! Generamos tu código único y también te lo enviamos a tu correo.',
			'coupon_code'          => 'HOLAAKIB9421',
			'discount_pct'         => $pct,
			'min_amount'           => Settings::min_amount(),
			'min_amount_formatted' => Settings::min_amount_formatted(),
			'expires_date'         => CouponService::format_date_dmy( $expires ),
			'shop_url'             => CouponService::auto_apply_url( 'HOLAAKIB9421' ),
		);
	}

	/**
	 * Verifica el token de Cloudflare Turnstile con el endpoint oficial siteverify.
	 *
	 * @param string $token Token de Turnstile enviado por el navegador.
	 */
	public function verify_turnstile( string $token ): bool {
		$token = trim( $token );
		if ( '' === $token ) {
			return false;
		}

		$is_prod = ( function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type() )
			|| ( function_exists( 'akibara_correo_es_la_tienda_de_verdad' ) && akibara_correo_es_la_tienda_de_verdad() );

		$secret = Settings::turnstile_secret();
		if ( '' === $secret ) {
			return false;
		}
		$is_local = function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type();

		// En tests automatizados o entorno local (nunca en producción), permitir tokens dummy de prueba:
		if ( $is_local && ! $is_prod && ( 'test-pass-token' === $token || '1x00000000000000000000AA' === $token ) ) {
			return true;
		}

		$override = apply_filters( 'akibara_turnstile_verify_result', null, $token );
		if ( $is_local && ! $is_prod && null !== $override ) {
			return (bool) $override;
		}

		// Si se usa la clave secreta de prueba de Cloudflare (solo fuera de producción):
		if ( $is_local && ! $is_prod && '1x0000000000000000000000000000000AA' === $secret ) {
			return true;
		}

		$ip   = RateLimit::client_ip();
		$body = array(
			'secret'   => $secret,
			'response' => $token,
		);
		if ( '' !== $ip ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 5,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Sin respuesta válida del proveedor no hay verificación ni emisión.
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return false;
		}

		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		return is_array( $json ) && ! empty( $json['success'] );
	}
}
