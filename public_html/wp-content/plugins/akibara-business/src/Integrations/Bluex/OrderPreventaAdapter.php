<?php
/**
 * Adaptador opcional de Bluex para OrderPreventa.
 *
 * Traduce la configuración, estado y tracking de Bluex al contrato neutral
 * FulfillmentCapabilities. El núcleo de preventa no conoce estos detalles.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Integrations\Bluex;

use Akibara\Business\Modules\OrderPreventa\CarrierHandoffMonitor;
use WC_Order;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Conecta Bluex for WooCommerce al contrato de preventa cuando está activo.
 *
 * @since 1.87.0
 */
final class OrderPreventaAdapter {

	private const API_HOST = 'eplin.api.blue.cl';
	private const LEGACY_HANDOFF_CHECK_HOOK = 'akb_order_preventa_verify_bluex_handoff';

	/**
	 * @var array<int, string>
	 */
	private const BLOCKED_PATHS = array(
		'/api/integr/woocommerce-wh/v1/order',
		'/api/ecommerce/custom/logs/v1',
	);

	private const LEGACY_ALERT_META = '_akb_eta_bluex_handoff_alerted';

	public function register(): void {
		if ( ! self::is_available() ) {
			return;
		}

		add_filter( 'akb_order_preventa_active_order_statuses', array( $this, 'append_operational_status' ), 10, 2 );
		add_filter( 'akb_order_preventa_carrier_handoff_statuses', array( $this, 'append_handoff_status' ), 10, 2 );
		add_filter( 'akb_order_preventa_tracking_references', array( $this, 'append_tracking_references' ), 10, 2 );
		add_filter( 'akb_order_preventa_carrier_handoff_alert_message', array( $this, 'alert_message' ), 10, 2 );
		add_filter( 'akb_order_preventa_carrier_handoff_alerted', array( $this, 'has_legacy_alert' ), 10, 2 );
		add_filter( 'pre_http_request', array( $this, 'block_local_side_effects' ), 10, 3 );
		add_action( self::LEGACY_HANDOFF_CHECK_HOOK, array( $this, 'verify_legacy_handoff' ) );
	}

	/**
	 * Atiende acciones Bluex ya en cola antes de la migración al contrato neutral.
	 *
	 * @param int $order_id ID de la orden.
	 * @return void
	 */
	public function verify_legacy_handoff( $order_id ): void {
		( new CarrierHandoffMonitor() )->verify_handoff( $order_id );
	}

	/**
	 * @param array<int, string> $statuses Estados registrados.
	 * @param WC_Order|mixed     $order    Orden bajo evaluación.
	 * @return array<int, string>
	 */
	public function append_operational_status( array $statuses, $order = null ): array {
		unset( $order );
		return $this->append_status( $statuses );
	}

	/**
	 * @param array<int, string> $statuses Estados registrados.
	 * @param WC_Order|mixed     $order    Orden bajo evaluación.
	 * @return array<int, string>
	 */
	public function append_handoff_status( array $statuses, $order = null ): array {
		unset( $order );
		return $this->append_status( $statuses );
	}

	/**
	 * @param array<int, string> $references Referencias conocidas.
	 * @param WC_Order|mixed     $order      Orden bajo evaluación.
	 * @return array<int, string>
	 */
	public function append_tracking_references( array $references, $order ): array {
		if ( ! $order instanceof WC_Order ) {
			return $references;
		}

		if ( function_exists( 'wc_correios_getTrackingCodes' ) ) {
			return array_merge( $references, (array) \wc_correios_getTrackingCodes( $order ) );
		}

		$legacy_value = (string) $order->get_meta( '_correios_tracking_code', true );
		return '' === trim( $legacy_value ) ? $references : array_merge( $references, explode( ',', $legacy_value ) );
	}

	/**
	 * @param string         $message Mensaje genérico del monitor.
	 * @param WC_Order|mixed $order   Orden bajo evaluación.
	 * @return string
	 */
	public function alert_message( $message, $order ): string {
		unset( $order );
		return '⚠️ Bluexpress aún no agregó tracking 2 horas después del handoff de esta preventa. Revisa primero el pedido en Bluexpress antes de reintentar: repetir el envío podría crear un despacho duplicado.';
	}

	/**
	 * Conserva la deduplicación de alertas escritas antes del contrato neutral.
	 *
	 * @param bool           $alerted Estado ya calculado.
	 * @param WC_Order|mixed $order   Orden bajo evaluación.
	 * @return bool
	 */
	public function has_legacy_alert( $alerted, $order ): bool {
		if ( $alerted || ! $order instanceof WC_Order ) {
			return (bool) $alerted;
		}

		return 'yes' === (string) $order->get_meta( self::LEGACY_ALERT_META, true );
	}

	/**
	 * Bloquea sólo efectos externos de Bluex cuando el sitio es local.
	 *
	 * @param false|array|WP_Error $preempt Respuesta preemptiva existente.
	 * @param array<string, mixed> $args    Argumentos HTTP.
	 * @param string               $url     URL solicitada.
	 * @return false|array|WP_Error
	 */
	public function block_local_side_effects( $preempt, $args, $url ) {
		unset( $args );

		if ( false !== $preempt || ! self::is_local_site() ) {
			return $preempt;
		}

		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( self::API_HOST !== $host || ! in_array( $path, self::BLOCKED_PATHS, true ) ) {
			return $preempt;
		}

		return new WP_Error(
			'akb_bluex_blocked_local',
			'Akibara bloqueó un despacho Bluexpress porque este sitio es local. Cambia el estado únicamente en producción para crear el envío real.'
		);
	}

	/**
	 * @param array<int, string> $statuses Estados actuales.
	 * @return array<int, string>
	 */
	private function append_status( array $statuses ): array {
		$status = $this->handoff_status();
		if ( ! in_array( $status, $statuses, true ) ) {
			$statuses[] = $status;
		}

		return $statuses;
	}

	/**
	 * @return string Estado configurado por Bluex sin prefijo wc-.
	 */
	private function handoff_status(): string {
		$settings = get_option( 'woocommerce_correios_settings', array() );
		$status   = is_array( $settings ) ? (string) ( $settings['noBlueStatus'] ?? 'wc-shipping-progress' ) : 'wc-shipping-progress';
		$status   = sanitize_key( $status );
		$status   = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

		return '' === $status ? 'shipping-progress' : $status;
	}

	/**
	 * @return bool
	 */
	private static function is_available(): bool {
		return defined( 'WC_CORREIOS_PLUGIN_FILE' ) || class_exists( 'WC_Correios' );
	}

	/**
	 * @return bool
	 */
	private static function is_local_site(): bool {
		if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
			return true;
		}

		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		return str_ends_with( $host, '.local' ) || str_ends_with( $host, '.test' );
	}
}
