<?php
/**
 * OrderStates — el vocabulario de estados de pedido que comparte el programa.
 *
 * Existe porque el conjunto «pedido caído» vivía triplicado como literal en
 * tres módulos que TIENEN que coincidir: si la tienda estrena un estado de
 * anulación y sólo se actualizan dos copias, un pedido muerto revierte los
 * puntos pero no repone el canje (o al revés), y la invitación a reseñar sale
 * igual. Un solo literal, tres consumidores.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.1
 */
final class OrderStates {

	/**
	 * Pedido CAÍDO (sin prefijo wc-): la venta no existe. Revierte la
	 * acreditación, repone el canje restante y cancela la invitación a
	 * reseñar.
	 *
	 * @var array<int,string>
	 */
	public const DOWN = array( 'cancelled', 'refunded', 'failed', 'trash' );

	/**
	 * Pedido VIVO: acá se reintenta una captura de canje pendiente y se
	 * re-cobra la deuda del revival.
	 *
	 * @var array<int,string>
	 */
	public const ALIVE = array( 'pending', 'on-hold', 'processing', 'shipping-progress', 'completed' );

	/**
	 * Pedido PAGADO: una compra de verdad. Lo comparten «¿ya compró antes?»
	 * (FirstPurchase) y la invitación a reseñar, que sale mientras el pedido
	 * siga pagado aunque haya avanzado más allá del estado disparador.
	 *
	 * @var array<int,string>
	 */
	public const PAID = array( 'processing', 'shipping-progress', 'completed' );

	/**
	 * ¿`$status` es el estado pagado `$ref`, o uno posterior?
	 *
	 * El orden es el de PAID: procesando → listo para enviar → completado.
	 * Existe porque la acreditación no puede depender de pisar EXACTAMENTE el
	 * estado que fija el panel: un pedido que salta directo a «Completado»
	 * —pago manual, retiro en tienda, o el dueño moviéndolo a mano— pasaba de
	 * largo y el cliente no veía sus puntos nunca. Cambiar el disparador en el
	 * panel dejaba el mismo hueco con los pedidos ya en curso.
	 *
	 * Acreditar de más no es un riesgo: `maybe_award()` es idempotente bajo
	 * candado de pedido y un pedido caído revierte entero.
	 *
	 * Con un `$ref` fuera de PAID (el dueño puede elegir «En espera») no hay
	 * orden que seguir: sólo coincide el propio estado.
	 *
	 * @param string $status Estado al que ENTRA el pedido, sin prefijo wc-.
	 * @param string $ref    Estado de referencia, sin prefijo wc-.
	 * @return bool
	 */
	public static function is_at_or_after( string $status, string $ref ): bool {
		$desde = array_search( $ref, self::PAID, true );
		$hasta = array_search( $status, self::PAID, true );

		if ( false === $desde || false === $hasta ) {
			return $status === $ref;
		}

		return $hasta >= $desde;
	}

	/**
	 * ¿Este estado es de pedido caído?
	 *
	 * @param string $status Estado sin prefijo wc-.
	 * @return bool
	 */
	public static function is_down( string $status ): bool {
		return in_array( $status, self::DOWN, true );
	}

	/**
	 * ¿Este estado es de pedido vivo?
	 *
	 * @param string $status Estado sin prefijo wc-.
	 * @return bool
	 */
	public static function is_alive( string $status ): bool {
		return in_array( $status, self::ALIVE, true );
	}
}
