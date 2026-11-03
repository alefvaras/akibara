<?php
/**
 * Calculator — la matemática del programa, PURA donde importa.
 *
 * La promesa del carrito y la acreditación del pedido usan LA MISMA base por
 * construcción: ítems (total tras descuentos + IVA), sin envío, menos lo
 * reembolsado y menos lo pagado con puntos. Así el «ganarás N» del checkout
 * coincide exacto con lo acreditado (requisito 4 de la spec; el plugin viejo
 * necesitó un mu-plugin entero para lograrlo).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Calculator {

	/**
	 * Puntos para una base en pesos. Función PURA.
	 *
	 * Redondeo estándar (configuración vigente de prod: rounding_mode=round).
	 *
	 * @param float $base        Base en pesos (IVA incluido), ya depurada.
	 * @param int   $earn_points Puntos por tramo.
	 * @param int   $earn_amount Tamaño del tramo en pesos.
	 * @return int
	 */
	public static function points_for_base( float $base, int $earn_points, int $earn_amount ): int {
		if ( $base <= 0 || $earn_points <= 0 || $earn_amount <= 0 ) {
			return 0;
		}

		return (int) round( $base / $earn_amount * $earn_points );
	}

	/**
	 * RAZÓN del programa: pesos devueltos por cada 100 pesos gastados.
	 * Función PURA.
	 *
	 * La devolución no la fija ningún campo por separado, la fijan los TRES a
	 * la vez: puntos por tramo ÷ tamaño del tramo × pesos por punto. Con la
	 * configuración vigente (2 puntos por cada $100, a $1 el punto) da 2,0.
	 * Con el mismo «2» y un tramo de $1 da 200,0, y ninguno de los dos campos
	 * se ve raro por su cuenta: por eso la baranda mira la razón y no el
	 * campo.
	 *
	 * Devuelve 0.0 si cualquiera de los tres es ≤ 0 — el tramo en 0 es el
	 * APAGADO documentado de la acumulación (Options::earn_amount) y tiene
	 * que seguir pasando la guarda.
	 *
	 * @param int $earn_points  Puntos por tramo.
	 * @param int $earn_amount  Tamaño del tramo en pesos.
	 * @param int $redeem_value Pesos de descuento por punto canjeado.
	 * @return float Porcentaje de devolución.
	 */
	public static function giveback_pct( int $earn_points, int $earn_amount, int $redeem_value ): float {
		if ( $earn_points <= 0 || $earn_amount <= 0 || $redeem_value <= 0 ) {
			return 0.0;
		}

		return $earn_points / $earn_amount * $redeem_value * 100;
	}

	/**
	 * Determina si la razón supera el porcentaje máximo permitido.
	 * Utiliza aritmética entera sin divisiones (puntos * pesos * 100 > techo * tramo)
	 * para evitar imprecisiones de coma flotante en múltiplos binarios.
	 *
	 * @param int $earn_points  Puntos por tramo.
	 * @param int $earn_amount  Tamaño del tramo en pesos.
	 * @param int $redeem_value Pesos de descuento por punto canjeado.
	 * @param int $max_pct      Techo en porcentaje.
	 * @return bool
	 */
	public static function giveback_exceeds( int $earn_points, int $earn_amount, int $redeem_value, int $max_pct ): bool {
		if ( $earn_points <= 0 || $earn_amount <= 0 || $redeem_value <= 0 ) {
			return false; // Cualquiera en 0 es el apagado documentado, no una razón.
		}

		return $earn_points * $redeem_value * 100 > $max_pct * $earn_amount;
	}

	/**
	 * Puntos para una base con la configuración vigente.
	 *
	 * @param float $base Base en pesos.
	 * @return int
	 */
	public static function points_for( float $base ): int {
		return self::points_for_base( $base, Options::earn_points(), Options::earn_amount() );
	}

	/**
	 * Base puntuable de un pedido: ítems conservados (tras reembolsos) menos
	 * el valor canjeado en puntos.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return float Base ≥ 0.
	 */
	public static function award_base_for_order( \WC_Order $order ): float {
		$base = self::conserved_items_base( $order );

		$base -= self::net_redeemed_value( $order );

		return max( 0.0, $base );
	}

	/**
	 * Canje NETO todavía aplicado a un pedido, en pesos: el descuento
	 * concedido menos lo legítimamente devuelto al cliente (reposición por reembolso).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return float ≥ 0.
	 */
	public static function net_redeemed_value( \WC_Order $order ): float {
		$redeemed = (float) $order->get_meta( Redemption::META_REDEEMED_VALUE );
		if ( $redeemed <= 0 ) {
			return 0.0;
		}

		$restored  = max( 0, (int) $order->get_meta( Redemption::META_RESTORED_POINTS ) );
		$unsettled = max( 0, (int) $order->get_meta( Redemption::META_UNSETTLED ) );
		$returned  = max( 0, $restored - $unsettled );

		// El valor por punto se deriva del propio pedido (valor canjeado / puntos canjeados)
		// para respetar la tasa original de la compra.
		$redeemed_points = max( 0, (int) $order->get_meta( Redemption::META_REDEEMED_POINTS ) );
		$per_point       = $redeemed_points > 0 ? $redeemed / $redeemed_points : Options::redeem_value();

		return max( 0.0, $redeemed - $returned * $per_point );
	}

	/**
	 * Valor conservado de los ítems del pedido: total tras descuentos + IVA,
	 * menos TODO lo reembolsado que toque mercadería.
	 *
	 * No usa $order->get_total(): ese total incluye el envío (que no puntúa) y
	 * ya pasó por max(0,…), y reconstruir sobre un total clampado regalaba
	 * puntos en compras de $0 (lección de integrity:200-204).
	 *
	 * Reembolsos por importe sin líneas explícitas se imputan a mercadería para asegurar
	 * un retiro conservador de puntos concedidos.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return float Valor conservado ≥ 0.
	 */
	public static function conserved_items_base( \WC_Order $order ): float {
		$base = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$base += (float) $item->get_total() + (float) $item->get_total_tax();
		}

		foreach ( $order->get_refunds() as $refund ) {
			$item_part  = 0.0;
			$other_part = 0.0;

			// En un reembolso los totales de línea vienen NEGATIVOS.
			foreach ( $refund->get_items( 'line_item' ) as $item ) {
				$item_part += abs( (float) $item->get_total() + (float) $item->get_total_tax() );
			}
			foreach ( array( 'shipping', 'fee' ) as $type ) {
				foreach ( $refund->get_items( $type ) as $item ) {
					$other_part += abs( (float) $item->get_total() + (float) $item->get_total_tax() );
				}
			}

			$amount   = abs( (float) $refund->get_amount() );
			$residual = $amount - $item_part - $other_part;

			$base -= $item_part + max( 0.0, $residual );
		}

		return max( 0.0, self::store_rounding( $base ) );
	}

	/**
	 * Redondeo contable alineado con los decimales oficiales de la moneda en la tienda (`wc_get_price_decimals()`).
	 *
	 * @param float $amount Suma cruda de líneas.
	 * @return float
	 */
	private static function store_rounding( float $amount ): float {
		return round( $amount, max( 0, (int) wc_get_price_decimals() ) );
	}

	/**
	 * Las líneas del pedido tal cual se vendieron (sin reembolsos), con el
	 * mismo redondeo que `conserved_items_base()`: es lo que se pone frente a
	 * ella para medir cuánta mercadería se devolvió. Mezclar una suma cruda con
	 * una redondeada inventaba «0,40 devueltos» sin reembolso (revisión H3).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return float
	 */
	public static function items_base( \WC_Order $order ): float {
		$base = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$base += (float) $item->get_total() + (float) $item->get_total_tax();
		}

		return max( 0.0, self::store_rounding( $base ) );
	}

	/**
	 * Base puntuable del carrito (la promesa), espejo exacto de la del pedido.
	 *
	 * @param \WC_Cart $cart           Carrito calculado.
	 * @param float    $redeemed_gross Valor bruto del canje activo en sesión.
	 * @return float Base ≥ 0.
	 */
	public static function award_base_for_cart( \WC_Cart $cart, float $redeemed_gross = 0.0 ): float {
		$base = 0.0;

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}
			$base += (float) ( $cart_item['line_total'] ?? 0 ) + (float) ( $cart_item['line_tax'] ?? 0 );
		}

		// Se redondea ANTES de restar el canje, como el total de WooCommerce.
		$base = self::store_rounding( $base );

		if ( $redeemed_gross > 0 ) {
			$base -= $redeemed_gross;
		}

		return max( 0.0, $base );
	}

	/**
	 * Tasa de impuesto efectiva de las líneas del carrito (0.19 con IVA
	 * chileno puro; menos con mixto exento). Copiada del mu-plugin de
	 * integridad, cuya matemática está probada en producción.
	 *
	 * @param \WC_Cart $cart Carrito calculado.
	 * @return float
	 */
	public static function cart_tax_rate( \WC_Cart $cart ): float {
		$net = 0.0;
		$tax = 0.0;

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}
			$net += (float) ( $cart_item['line_total'] ?? 0 );
			$tax += (float) ( $cart_item['line_tax'] ?? 0 );
		}

		if ( $net <= 0.0 || $tax <= 0.0 ) {
			return 0.0;
		}

		return $tax / $net;
	}

	/**
	 * Importe del fee de canje para que el descuento CON IVA sea exactamente
	 * el valor facial. Función PURA.
	 *
	 * WooCommerce reparte el IVA sobre los fees negativos aunque se declaren
	 * no imponibles (class-wc-cart-totals.php), así que el fee se escribe en
	 * NETO: bruto/(1+tasa). Con carrito exento (tasa 0) el fee es el bruto.
	 *
	 * Precisión, con honestidad: (a) la tasa se mide sobre las LÍNEAS y el
	 * reparto real de WooCommerce incluye el envío — con envío en otra clase
	 * de impuesto el descuento puede desviarse del facial (hoy todo va al 19%
	 * en la tienda); (b) con CLP a 0 decimales el redondeo final puede mover
	 * La captura cobra por el fee horneado en el pedido, garantizando sincronización
	 * exacta entre puntos debitados y descuento concedido.
	 *
	 * @param float $gross Descuento bruto pretendido (> 0).
	 * @param float $rate  Tasa efectiva del carrito.
	 * @return float Importe NEGATIVO del fee.
	 */
	public static function redemption_fee_amount( float $gross, float $rate ): float {
		if ( $gross <= 0 ) {
			return 0.0;
		}

		if ( $rate <= 0 ) {
			return -$gross;
		}

		return round( -( $gross / ( 1 + $rate ) ), 6 );
	}
}
