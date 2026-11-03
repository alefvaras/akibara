<?php
/**
 * Contrato de capacidades de fulfillment para OrderPreventa.
 *
 * El núcleo conoce estados operativos, retiro local, handoff de carrier y
 * referencias de tracking; cada integración externa declara sus detalles vía
 * filtros sin introducir nombres de plugins, metas o estados en el núcleo.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Punto de extensión para integraciones de fulfillment.
 *
 * @since 1.87.0
 */
final class FulfillmentCapabilities {

	/**
	 * Estados en que una preventa puede seguir siendo operada.
	 *
	 * @param WC_Order|null $order Orden bajo evaluación cuando aplica.
	 * @return array<int, string>
	 */
	public static function active_order_statuses( ?WC_Order $order = null ): array {
		$statuses = apply_filters(
			'akb_order_preventa_active_order_statuses',
			array( 'processing' ),
			$order
		);

		return self::normalize_statuses( $statuses );
	}

	/**
	 * Estados donde siguen siendo pertinentes comunicaciones de espera.
	 *
	 * @param WC_Order|null $order Orden bajo evaluación cuando aplica.
	 * @return array<int, string>
	 */
	public static function waiting_order_statuses( ?WC_Order $order = null ): array {
		$statuses = apply_filters(
			'akb_order_preventa_waiting_order_statuses',
			array( 'processing' ),
			$order
		);

		return self::normalize_statuses( $statuses );
	}

	/**
	 * Estados cuyas fechas se recalculan aunque no corresponda avisar al cliente.
	 *
	 * Incluye los estados de espera y on-hold para actualizar metas actuales,
	 * resumen y firmas antes de una confirmación de transferencia. El envío de
	 * avisos sigue limitado por waiting_order_statuses(): recalcular un pedido
	 * sin pago confirmado no autoriza notificar un cambio.
	 *
	 * @param WC_Order|null $order Orden bajo evaluación cuando aplica.
	 * @return array<int, string>
	 */
	public static function recompute_order_statuses( ?WC_Order $order = null ): array {
		$statuses = apply_filters(
			'akb_order_preventa_recompute_order_statuses',
			array_merge( self::waiting_order_statuses( $order ), array( 'on-hold', 'pending' ) ),
			$order
		);

		return self::normalize_statuses( $statuses );
	}

	/**
	 * Estados válidos para enviar el email "preventa lista".
	 *
	 * @param WC_Order $order Orden recién marcada lista.
	 * @return array<int, string>
	 */
	public static function ready_email_order_statuses( WC_Order $order ): array {
		$statuses = apply_filters(
			'akb_order_preventa_ready_email_order_statuses',
			self::active_order_statuses( $order ),
			$order
		);

		/*
		 * Un pedido que YA salió no puede recibir «tu pedido ya llegó a Akibara ·
		 * despachamos en 1-3 días hábiles» (). El cartel de la ficha y
		 * de Mi cuenta callan en esos estados desde 1.114.16
		 * (`CustomerVisibility::order_left()`) y el correo decía lo contrario: la
		 * integración de Blue Express declara su estado operativo como ACTIVO, así
		 * que entraba por la lista de arriba.
		 *
		 * Se resta después del filtro a propósito: es una regla del negocio, no un
		 * valor por defecto. Una integración que de verdad necesite avisar ahí
		 * tiene el filtro `akb_order_preventa_can_send_ready_email`.
		 */
		return array_values( array_diff( self::normalize_statuses( $statuses ), self::departed_order_statuses( $order ) ) );
	}

	/**
	 * Estados en que el pedido YA salió de la tienda: completado o entregado al
	 * transportista según su adaptador de fulfillment.
	 *
	 * Una sola lista para las dos preguntas que dependen de ella: qué estados
	 * restan del aviso «ya llegó» y si un aviso suprimido lo fue por eso.
	 *
	 * @since 1.116.9
	 *
	 * @param WC_Order $order Orden bajo evaluación.
	 * @return array<int, string>
	 */
	public static function departed_order_statuses( WC_Order $order ): array {
		return self::normalize_statuses( array_merge( array( 'completed' ), self::carrier_handoff_statuses( $order ) ) );
	}

	/**
	 * Determina si la orden está en un estado operativo de preventa.
	 *
	 * @param WC_Order $order Orden a evaluar.
	 * @return bool
	 */
	public static function is_active_order( WC_Order $order ): bool {
		return $order->has_status( self::active_order_statuses( $order ) );
	}

	/**
	 * Determina si la orden admite el email "preventa lista".
	 *
	 * @param WC_Order $order Orden a evaluar.
	 * @return bool
	 */
	public static function can_send_ready_email( WC_Order $order ): bool {
		$allowed = $order->has_status( self::ready_email_order_statuses( $order ) );

		return (bool) apply_filters( 'akb_order_preventa_can_send_ready_email', $allowed, $order );
	}

	/**
	 * Determina si todos los envíos de la orden son retiro local.
	 *
	 * Las integraciones pueden registrar su método en el filtro nativo
	 * `woocommerce_local_pickup_methods`, o resolver casos especiales mediante
	 * `akb_order_preventa_is_local_pickup_order`.
	 *
	 * @param WC_Order $order Orden a evaluar.
	 * @return bool
	 */
	public static function is_local_pickup_order( WC_Order $order ): bool {
		$shipping_items = $order->get_items( 'shipping' );
		$method_ids     = self::normalize_method_ids(
			apply_filters( 'woocommerce_local_pickup_methods', array( 'local_pickup' ) ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro de WooCommerce core: se re-aplica con su nombre original para que los callbacks de terceros sigan viéndolo.
		);
		$is_local_pickup = ! empty( $shipping_items );

		foreach ( $shipping_items as $shipping_item ) {
			if ( ! is_object( $shipping_item ) || ! is_callable( array( $shipping_item, 'get_method_id' ) ) ) {
				$is_local_pickup = false;
				break;
			}

			if ( ! in_array( (string) $shipping_item->get_method_id(), $method_ids, true ) ) {
				$is_local_pickup = false;
				break;
			}
		}

		return (bool) apply_filters(
			'akb_order_preventa_is_local_pickup_order',
			$is_local_pickup,
			$order,
			$method_ids
		);
	}

	/**
	 * Determina si la orden requiere seguimiento de un carrier.
	 *
	 * @param WC_Order $order Orden a evaluar.
	 * @return bool
	 */
	public static function requires_carrier_fulfillment( WC_Order $order ): bool {
		$requires_carrier = ! empty( $order->get_items( 'shipping' ) ) && ! self::is_local_pickup_order( $order );

		return (bool) apply_filters(
			'akb_order_preventa_requires_carrier_fulfillment',
			$requires_carrier,
			$order
		);
	}

	/**
	 * Estados que indican un handoff asíncrono a un carrier.
	 *
	 * El core no presupone ninguno. Cada integración declara los suyos.
	 *
	 * @param WC_Order $order Orden bajo evaluación.
	 * @return array<int, string>
	 */
	public static function carrier_handoff_statuses( WC_Order $order ): array {
		$statuses = apply_filters(
			'akb_order_preventa_carrier_handoff_statuses',
			array(),
			$order
		);

		return self::normalize_statuses( $statuses );
	}

	/**
	 * Comprueba si un estado representa handoff de carrier para una orden.
	 *
	 * @param string   $status Estado WooCommerce sin o con prefijo wc-.
	 * @param WC_Order $order  Orden bajo evaluación.
	 * @return bool
	 */
	public static function is_carrier_handoff_status( string $status, WC_Order $order ): bool {
		return in_array(
			self::normalize_status( $status ),
			self::carrier_handoff_statuses( $order ),
			true
		);
	}

	/**
	 * Referencias de tracking conocidas para una orden.
	 *
	 * @param WC_Order $order Orden bajo evaluación.
	 * @return array<int, string>
	 */
	public static function tracking_references( WC_Order $order ): array {
		$references = apply_filters( 'akb_order_preventa_tracking_references', array(), $order );
		if ( ! is_array( $references ) ) {
			$references = array( $references );
		}

		$out = array();
		foreach ( $references as $reference ) {
			if ( ! is_scalar( $reference ) ) {
				continue;
			}

			$reference = trim( (string) $reference );
			if ( '' !== $reference && ! in_array( $reference, $out, true ) ) {
				$out[] = $reference;
			}
		}

		return $out;
	}

	/**
	 * Normaliza una lista de estados WooCommerce.
	 *
	 * @param mixed $statuses Lista a normalizar.
	 * @return array<int, string>
	 */
	private static function normalize_statuses( $statuses ): array {
		if ( ! is_array( $statuses ) ) {
			return array();
		}

		$out = array();
		foreach ( $statuses as $status ) {
			if ( ! is_scalar( $status ) ) {
				continue;
			}

			$status = self::normalize_status( (string) $status );
			if ( '' !== $status && ! in_array( $status, $out, true ) ) {
				$out[] = $status;
			}
		}

		return $out;
	}

	/**
	 * Normaliza un estado WooCommerce al formato sin prefijo wc-.
	 *
	 * @param string $status Estado a normalizar.
	 * @return string
	 */
	private static function normalize_status( string $status ): string {
		$status = sanitize_key( $status );

		return str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * Normaliza IDs de métodos de envío.
	 *
	 * @param mixed $method_ids Lista a normalizar.
	 * @return array<int, string>
	 */
	private static function normalize_method_ids( $method_ids ): array {
		if ( ! is_array( $method_ids ) ) {
			return array();
		}

		$out = array();
		foreach ( $method_ids as $method_id ) {
			if ( ! is_scalar( $method_id ) ) {
				continue;
			}

			$method_id = sanitize_key( (string) $method_id );
			if ( '' !== $method_id && ! in_array( $method_id, $out, true ) ) {
				$out[] = $method_id;
			}
		}

		return $out;
	}

	private function __construct() {}
}