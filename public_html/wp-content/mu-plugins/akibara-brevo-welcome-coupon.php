<?php
/**
 * Plugin Name: Akibara — Puente de Automatización de Marketing (Agnóstico)
 * Description: Puente desacoplado basado en eventos para sincronizar altas de bienvenida, cupones y recordatorios con plataformas de marketing automation (Brevo, Klaviyo o Webhooks) sin acoplar akibara-business al proveedor.
 * Version: 1.1.0
 * Author: Akibara
 *
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Contrato agnóstico para cualquier adaptador de marketing automation.
 *
 * Permite cambiar de Brevo a Klaviyo, Mailchimp, ActiveCampaign o Webhook HTTP
 * sin tocar una sola línea de código en akibara-business.
 */
interface AkibaraMarketingAdapterInterface {

	/**
	 * Dispara cuando se emite un cupón de bienvenida nuevo.
	 *
	 * @param array<string, mixed> $payload Datos del cupón y suscriptor.
	 */
	public function on_coupon_issued( array $payload ): void;

	/**
	 * Dispara cuando vence el plazo y corresponde enviar el recordatorio del último día.
	 *
	 * @param array<string, mixed> $payload Datos del cupón y suscriptor.
	 */
	public function on_reminder_due( array $payload ): void;

	/**
	 * Dispara cuando el cupón es canjeado en un pedido finalizado/procesando.
	 *
	 * @param array<string, mixed> $payload Datos del cupón y pedido.
	 */
	public function on_coupon_used( array $payload ): void;

	/**
	 * Dispara cuando un pedido con cupón de bienvenida falla o se cancela, restaurando el beneficio.
	 *
	 * @param array<string, mixed> $payload Datos del cupón y pedido.
	 */
	public function on_coupon_restored( array $payload ): void;

	/**
	 * Dispara cuando un comprador existente (con pedidos previos) deja su correo en el popup.
	 *
	 * @param array<string, mixed> $payload Datos del suscriptor.
	 */
	public function on_existing_buyer_subscribed( array $payload ): void;
}

/**
 * Adaptador concreto para Brevo Marketing Automation (Templates #21 y #22).
 */
final class AkibaraBrevoMarketingAdapter implements AkibaraMarketingAdapterInterface {

	public const ACTION_ASYNC       = 'akb_brevo_sync_welcome_event';
	private const TEMPLATE_WELCOME  = 21;
	private const TEMPLATE_REMINDER = 22;

	/**
	 * Obtiene la clave de Marketing Automation (ma-key) configurada por el conector oficial de Brevo.
	 */
	public static function get_ma_key(): string {
		$raw = get_option( 'sendinblue_woocommerce_user_connection_settings', '' );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) && ! empty( $decoded['marketingAutomationKey'] ) && is_string( $decoded['marketingAutomationKey'] ) ) {
				return trim( $decoded['marketingAutomationKey'] );
			}
		}

		return '';
	}

	/**
	 * Obtiene el ID de la lista del boletín/campañas en Brevo configurada por el conector oficial de WooCommerce.
	 * Por defecto retorna la lista 4 (la lista canónica en producción de preventas, reposiciones y boletín).
	 */
	public static function get_newsletter_list_id(): int {
		$raw = get_option( 'sendinblue_woocommerce_user_connection_settings', '' );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) && ! empty( $decoded['listId'] ) ) {
				return (int) $decoded['listId'];
			}
		}

		return 4;
	}

	/**
	 * ¿Debe enviarse el tráfico HTTP real hacia Brevo en este entorno?
	 *
	 * @param string $email Correo del suscriptor.
	 */
	public static function allow_remote( string $email ): bool {
		$es_tienda = function_exists( 'akibara_correo_es_la_tienda_de_verdad' )
			&& akibara_correo_es_la_tienda_de_verdad();

		return (bool) apply_filters( 'akibara_brevo_welcome_allow_remote', $es_tienda, $email );
	}

	/**
	 * Envía una petición bloqueante al endpoint de Brevo Marketing Automation.
	 *
	 * @param string               $endpoint URL completa del endpoint de Brevo.
	 * @param array<string, mixed> $payload  Cuerpo JSON.
	 * @param string               $ma_key   Clave de automatización.
	 * @return bool True si la respuesta fue HTTP 2xx.
	 */
	private static function blocking_post( string $endpoint, array $payload, string $ma_key ): bool {
		if ( '' === $ma_key ) {
			return false;
		}

		$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $body ) || '' === $body ) {
			return false;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'method'   => 'POST',
				'timeout'  => 5,
				'blocking' => true,
				'headers'  => array(
					'accept'       => 'application/json',
					'content-type' => 'application/json',
					'ma-key'       => $ma_key,
				),
				'body'     => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return $code >= 200 && $code < 300;
	}

	/**
	 * Despacha la sincronización remota hacia Brevo de forma asíncrona mediante Action Scheduler
	 * o de forma síncrona controlada como fallback.
	 *
	 * @param string                    $email            Correo del destinatario.
	 * @param array<string, mixed>|null $identify_payload Payload para /identify o null.
	 * @param array<string, mixed>      $event_payload    Payload para /trackEvent.
	 * @param int                       $coupon_id        ID del cupón para sellar sincronización.
	 * @param string                    $sync_meta        Clave meta de sincronización.
	 */
	private static function dispatch_remote( string $email, ?array $identify_payload, array $event_payload, int $coupon_id = 0, string $sync_meta = '' ): void {
		$ma_key = self::get_ma_key();
		if ( '' === $ma_key || ! self::allow_remote( $email ) ) {
			return;
		}

		$job_data = array(
			'email'            => $email,
			'identify_payload' => $identify_payload,
			'event_payload'    => $event_payload,
			'coupon_id'        => $coupon_id,
			'sync_meta'        => $sync_meta,
		);

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::ACTION_ASYNC,
				array( $job_data ),
				'akibara-marketing'
			);
		} else {
			self::process_async_job( $job_data );
		}
	}

	/**
	 * Procesa el trabajo asíncrono encolado en Action Scheduler:
	 *   1. Ejecuta /identify de forma secuencial y bloqueante (timeout 5s).
	 *   2. Verifica que /identify responda HTTP 2xx antes de continuar.
	 *   3. Ejecuta /trackEvent de forma secuencial y bloqueante.
	 *   4. Solo si ambas llamadas fueron 2xx, actualiza el timestamp de sincronización en el cupón.
	 *   5. Si alguna llamada falla (ej. 429 rate limit o 500 error), arroja excepción para que
	 *      Action Scheduler reintente automáticamente.
	 *
	 * @param array<string, mixed> $job_data Datos del trabajo.
	 * @throws \RuntimeException Si la llamada a Brevo falla.
	 */
	public static function process_async_job( array $job_data ): void {
		$ma_key = self::get_ma_key();
		if ( '' === $ma_key ) {
			return;
		}

		$email            = (string) ( $job_data['email'] ?? '' );
		$identify_payload = $job_data['identify_payload'] ?? null;
		$event_payload    = $job_data['event_payload'] ?? null;
		$coupon_id        = (int) ( $job_data['coupon_id'] ?? 0 );
		$sync_meta        = (string) ( $job_data['sync_meta'] ?? '' );

		if ( is_array( $identify_payload ) && ! empty( $identify_payload ) ) {
			$ok_identify = self::blocking_post( 'https://in-automate.brevo.com/api/v2/identify', $identify_payload, $ma_key );
			if ( ! $ok_identify ) {
				throw new \RuntimeException( 'Error en Brevo /identify para ' . $email );
			}
		}

		if ( is_array( $event_payload ) && ! empty( $event_payload ) ) {
			$ok_event = self::blocking_post( 'https://in-automate.brevo.com/api/v2/trackEvent', $event_payload, $ma_key );
			if ( ! $ok_event ) {
				throw new \RuntimeException( 'Error en Brevo /trackEvent para ' . $email );
			}
		}

		if ( $coupon_id > 0 && '' !== $sync_meta ) {
			$now = time();
			update_post_meta( $coupon_id, $sync_meta, $now );
			if ( '_akb_welcome_marketing_synced_at' === $sync_meta ) {
				update_post_meta( $coupon_id, '_akb_welcome_brevo_synced_at', $now );
			} elseif ( '_akb_welcome_marketing_reminder_synced_at' === $sync_meta ) {
				update_post_meta( $coupon_id, '_akb_welcome_brevo_reminder_synced_at', $now );
			}
		}
	}

	/**
	 * Guarda metadatos agnósticos (y retrocompatibles) en el post del cupón.
	 *
	 * @param int                  $coupon_id ID del cupón en wp_posts.
	 * @param string               $event     Nombre del evento emitido.
	 * @param array<string, mixed> $payload   Payload enviado.
	 * @param int                  $template  ID de plantilla opcional.
	 */
	private static function record_meta( int $coupon_id, string $event, array $payload, int $template = 0 ): void {
		if ( $coupon_id <= 0 ) {
			return;
		}

		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		// Metadatos agnósticos de marketing.
		update_post_meta( $coupon_id, '_akb_welcome_marketing_provider', 'brevo' );
		update_post_meta( $coupon_id, '_akb_welcome_marketing_last_event', $event );
		update_post_meta( $coupon_id, '_akb_welcome_marketing_payload', $json );
		if ( $template > 0 ) {
			update_post_meta( $coupon_id, '_akb_welcome_marketing_template_id', $template );
		}

		// Metadatos legacy para retrocompatibilidad con queries previas.
		update_post_meta( $coupon_id, '_akb_welcome_brevo_last_event', $event );
		update_post_meta( $coupon_id, '_akb_welcome_brevo_payload', $json );
		if ( $template > 0 ) {
			update_post_meta( $coupon_id, '_akb_welcome_brevo_template_id', $template );
		}
	}

	/**
	 * Emisión de cupón de bienvenida.
	 *
	 * @param array<string, mixed> $payload Datos del cupón emitido.
	 */
	public function on_coupon_issued( array $payload ): void {
		$coupon_id    = (int) ( $payload['coupon_id'] ?? 0 );
		$email        = strtolower( trim( (string) ( $payload['email'] ?? '' ) ) );
		$name         = trim( (string) ( $payload['name'] ?? '' ) );
		$code         = (string) ( $payload['coupon_code'] ?? $payload['code'] ?? '' );
		$expires_date = (string) ( $payload['expires_date'] ?? '' );
		$percent      = (int) ( $payload['discount_pct'] ?? $payload['percent'] ?? 10 );
		$min_amount   = (int) ( $payload['min_amount'] ?? 25000 );
		$expires_ts   = (int) ( $payload['expires_ts'] ?? $payload['expires_at'] ?? 0 );
		$clean_name   = '' !== $name ? $name : 'Nakama';
		$shop_url     = (string) ( $payload['shop_url'] ?? add_query_arg( 'akb_cupon', rawurlencode( $code ), home_url( '/tienda/' ) ) );

		if ( $coupon_id <= 0 || '' === $email || '' === $code ) {
			return;
		}

		$list_id = self::get_newsletter_list_id();

		$attributes = array(
			'FIRSTNAME'              => $clean_name,
			'NOMBRE'                 => $clean_name,
			'CUPON_BIENVENIDA'       => $code,
			'CUPON_BIENVENIDA_VENCE' => $expires_date,
			'CUPON_BIENVENIDA_USADO' => false,
			'CUPON_SHOP_URL'         => $shop_url,
			'CUPON_MIN_AMOUNT'       => $min_amount,
			'NEWSLETTER_OPT_IN'      => true,
			'ORIGEN'                 => 'Popup Primera Compra',
			'LISTA_BOLETIN'          => $list_id,
		);

		$identify_payload = array(
			'email'      => $email,
			'attributes' => $attributes,
		);

		$event_payload = array(
			'event'      => 'welcome_coupon_issued',
			'email'      => $email,
			'properties' => $attributes,
			'eventdata'  => array(
				'id'   => 'welcome-' . $coupon_id,
				'data' => array(
					'coupon_id'         => $coupon_id,
					'code'              => $code,
					'percent'           => $percent,
					'min_amount'        => $min_amount,
					'expires_at'        => $expires_ts,
					'expires_date'      => $expires_date,
					'shop_url'          => $shop_url,
					'template_id'       => self::TEMPLATE_WELCOME,
					'list_id'           => $list_id,
					'newsletter_opt_in' => true,
				),
			),
		);

		self::record_meta( $coupon_id, 'welcome_coupon_issued', $event_payload, self::TEMPLATE_WELCOME );

		self::dispatch_remote( $email, $identify_payload, $event_payload, $coupon_id, '_akb_welcome_marketing_synced_at' );
	}

	/**
	 * Recordatorio último día.
	 *
	 * @param array<string, mixed> $payload Datos del cupón emitido.
	 */
	public function on_reminder_due( array $payload ): void {
		$coupon_id    = (int) ( $payload['coupon_id'] ?? 0 );
		$email        = strtolower( trim( (string) ( $payload['email'] ?? '' ) ) );
		$name         = trim( (string) ( $payload['name'] ?? '' ) );
		$code         = (string) ( $payload['coupon_code'] ?? $payload['code'] ?? '' );
		$expires_date = (string) ( $payload['expires_date'] ?? '' );
		$percent      = (int) ( $payload['discount_pct'] ?? $payload['percent'] ?? 10 );
		$min_amount   = (int) ( $payload['min_amount'] ?? 25000 );
		$expires_ts   = (int) ( $payload['expires_ts'] ?? $payload['expires_at'] ?? 0 );
		$clean_name   = '' !== $name ? $name : 'Nakama';
		$shop_url     = (string) ( $payload['shop_url'] ?? add_query_arg( 'akb_cupon', rawurlencode( $code ), home_url( '/tienda/' ) ) );

		if ( $coupon_id <= 0 || '' === $email || '' === $code ) {
			return;
		}

		$event_payload = array(
			'event'      => 'welcome_coupon_reminder_due',
			'email'      => $email,
			'properties' => array(
				'FIRSTNAME'              => $clean_name,
				'NOMBRE'                 => $clean_name,
				'CUPON_BIENVENIDA'       => $code,
				'CUPON_BIENVENIDA_VENCE' => $expires_date,
				'CUPON_BIENVENIDA_USADO' => false,
			),
			'eventdata'  => array(
				'id'   => 'welcome-reminder-' . $coupon_id,
				'data' => array(
					'coupon_id'    => $coupon_id,
					'code'         => $code,
					'percent'      => $percent,
					'min_amount'   => $min_amount,
					'expires_at'   => $expires_ts,
					'expires_date' => $expires_date,
					'shop_url'     => $shop_url,
					'template_id'  => self::TEMPLATE_REMINDER,
				),
			),
		);

		self::record_meta( $coupon_id, 'welcome_coupon_reminder_due', $event_payload, self::TEMPLATE_REMINDER );

		self::dispatch_remote( $email, null, $event_payload, $coupon_id, '_akb_welcome_marketing_reminder_synced_at' );
	}

	/**
	 * Cupón canjeado en checkout.
	 *
	 * @param array<string, mixed> $payload Datos del cupón y pedido.
	 */
	public function on_coupon_used( array $payload ): void {
		$coupon_id = (int) ( $payload['coupon_id'] ?? 0 );
		$email     = strtolower( trim( (string) ( $payload['email'] ?? '' ) ) );
		$order_id  = (int) ( $payload['order_id'] ?? 0 );

		if ( $coupon_id <= 0 || '' === $email ) {
			return;
		}

		$identify_payload = array(
			'email'      => $email,
			'attributes' => array(
				'CUPON_BIENVENIDA_USADO' => true,
			),
		);

		$event_payload = array(
			'event'      => 'welcome_coupon_used',
			'email'      => $email,
			'properties' => array(
				'CUPON_BIENVENIDA_USADO' => true,
			),
			'eventdata'  => array(
				'id'   => 'welcome-used-' . $coupon_id . '-' . $order_id,
				'data' => array(
					'coupon_id' => $coupon_id,
					'order_id'  => $order_id,
				),
			),
		);

		self::record_meta( $coupon_id, 'welcome_coupon_used', $event_payload );

		self::dispatch_remote( $email, $identify_payload, $event_payload, $coupon_id );
	}

	/**
	 * Cupón restaurado tras fallo o cancelación del pedido.
	 *
	 * @param array<string, mixed> $payload Datos del cupón y pedido.
	 */
	public function on_coupon_restored( array $payload ): void {
		$coupon_id = (int) ( $payload['coupon_id'] ?? 0 );
		$email     = strtolower( trim( (string) ( $payload['email'] ?? '' ) ) );
		$order_id  = (int) ( $payload['order_id'] ?? 0 );

		if ( $coupon_id <= 0 || '' === $email ) {
			return;
		}

		$identify_payload = array(
			'email'      => $email,
			'attributes' => array(
				'CUPON_BIENVENIDA_USADO' => false,
			),
		);

		$event_payload = array(
			'event'      => 'welcome_coupon_restored',
			'email'      => $email,
			'properties' => array(
				'CUPON_BIENVENIDA_USADO' => false,
			),
			'eventdata'  => array(
				'id'   => 'welcome-restored-' . $coupon_id . '-' . $order_id,
				'data' => array(
					'coupon_id' => $coupon_id,
					'order_id'  => $order_id,
				),
			),
		);

		self::record_meta( $coupon_id, 'welcome_coupon_restored', $event_payload );

		self::dispatch_remote( $email, $identify_payload, $event_payload, $coupon_id );
	}

	/**
	 * Pivot: Comprador existente suscrito sin quemar cupón.
	 *
	 * @param array<string, mixed> $payload Datos del cliente (`email`, `name`).
	 */
	public function on_existing_buyer_subscribed( array $payload ): void {
		$email      = strtolower( trim( (string) ( $payload['email'] ?? '' ) ) );
		$name       = trim( (string) ( $payload['name'] ?? '' ) );
		$clean_name = '' !== $name ? $name : 'Nakama';

		if ( '' === $email ) {
			return;
		}

		$list_id = self::get_newsletter_list_id();

		$attributes = array(
			'FIRSTNAME'              => $clean_name,
			'NOMBRE'                 => $clean_name,
			'CUPON_BIENVENIDA_USADO' => true,
			'NEWSLETTER_OPT_IN'      => true,
			'ORIGEN'                 => 'Popup Primera Compra (Comprador Existente)',
			'LISTA_BOLETIN'          => $list_id,
		);

		$identify_payload = array(
			'email'      => $email,
			'attributes' => $attributes,
		);

		$event_payload = array(
			'event'      => 'newsletter_subscribed_existing_buyer',
			'email'      => $email,
			'properties' => $attributes,
			'eventdata'  => array(
				'id'   => 'existing-buyer-' . md5( $email ),
				'data' => array(
					'source'            => 'welcome_popup_pivot',
					'list_id'           => $list_id,
					'newsletter_opt_in' => true,
				),
			),
		);

		self::dispatch_remote( $email, $identify_payload, $event_payload );
	}
}

/**
 * Gestor agnóstico del puente de marketing.
 *
 * Registra los 4 eventos canónicos de Akibara y delega en el adaptador activo
 * (resoluble por filtro `akibara_marketing_adapter`).
 */
final class AkibaraMarketingBridge {

	private AkibaraMarketingAdapterInterface $adapter;

	public function __construct( ?AkibaraMarketingAdapterInterface $adapter = null ) {
		$this->adapter = $adapter ?? new AkibaraBrevoMarketingAdapter();
	}

	/**
	 * Conecta los hooks canónicos de Akibara al adaptador.
	 */
	public function register(): void {
		/**
		 * Filtro para sobreescribir o intercambiar el adaptador de marketing activo
		 * (ej. por KlaviyoMarketingAdapter o WebhookMarketingAdapter).
		 *
		 * @since 1.120.0
		 *
		 * @param AkibaraMarketingAdapterInterface $adapter Adaptador activo por defecto.
		 */
		$resolved = apply_filters( 'akibara_marketing_adapter', $this->adapter );
		if ( $resolved instanceof AkibaraMarketingAdapterInterface ) {
			$this->adapter = $resolved;
		}

		add_action( 'akb_welcome_coupon_issued', array( $this->adapter, 'on_coupon_issued' ), 10, 1 );
		add_action( 'akb_welcome_coupon_reminder_due', array( $this->adapter, 'on_reminder_due' ), 10, 1 );
		add_action( 'akb_welcome_coupon_used', array( $this->adapter, 'on_coupon_used' ), 10, 1 );
		add_action( 'akb_welcome_coupon_restored', array( $this->adapter, 'on_coupon_restored' ), 10, 1 );
		add_action( 'akb_welcome_existing_buyer_subscribed', array( $this->adapter, 'on_existing_buyer_subscribed' ), 10, 1 );
		add_action( AkibaraBrevoMarketingAdapter::ACTION_ASYNC, array( 'AkibaraBrevoMarketingAdapter', 'process_async_job' ), 10, 1 );
	}
}

/**
 * Inicializa el puente agnóstico.
 */
function akibara_registrar_puente_marketing_automation(): void {
	$bridge = new AkibaraMarketingBridge();
	$bridge->register();
}

add_action( 'plugins_loaded', 'akibara_registrar_puente_marketing_automation', 25 );

/**
 * Shims retrocompatibles para código legacy o llamadas funcionales directas.
 */
function akibara_brevo_welcome_ma_key(): string {
	return AkibaraBrevoMarketingAdapter::get_ma_key();
}

function akibara_brevo_welcome_puede_enviar_remoto( string $email ): bool {
	return AkibaraBrevoMarketingAdapter::allow_remote( $email );
}

function akibara_brevo_on_welcome_coupon_issued( array $payload ): void {
	( new AkibaraBrevoMarketingAdapter() )->on_coupon_issued( $payload );
}

function akibara_brevo_on_welcome_coupon_reminder_due( array $payload ): void {
	( new AkibaraBrevoMarketingAdapter() )->on_reminder_due( $payload );
}

function akibara_brevo_on_welcome_coupon_used( array $payload ): void {
	( new AkibaraBrevoMarketingAdapter() )->on_coupon_used( $payload );
}

function akibara_brevo_on_existing_buyer_subscribed( array $payload ): void {
	( new AkibaraBrevoMarketingAdapter() )->on_existing_buyer_subscribed( $payload );
}

function akibara_brevo_newsletter_list_id(): int {
	return AkibaraBrevoMarketingAdapter::get_newsletter_list_id();
}
