<?php
/**
 * Detección del almacenamiento de pedidos de WooCommerce (HPOS vs CPT).
 *
 * Fuente ÚNICA del árbol para preguntar «¿los pedidos viven en las tablas
 * custom o en wp_posts?». Nació el  al deduplicar los detectores
 * de CustomerCollection y ReadyToShipEmail (célula de barrido): quien elija
 * rama de SQL por almacenamiento debe llamar aquí, nunca re-declarar el
 * class_exists + custom_orders_table_usage_is_enabled a mano.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 */
final class OrderStorage {

	/**
	 * ¿WooCommerce usa sus tablas custom de pedidos (HPOS)?
	 *
	 * Defensivo a propósito: sin WooCommerce cargada (suites, WP-CLI parcial)
	 * o ante un cambio de firma del tercero, la respuesta segura es la rama
	 * CPT — wp_posts existe siempre.
	 *
	 * @return bool
	 */
	public static function uses_hpos(): bool {
		if ( ! class_exists( OrderUtil::class ) || ! method_exists( OrderUtil::class, 'custom_orders_table_usage_is_enabled' ) ) {
			return false;
		}

		try {
			return OrderUtil::custom_orders_table_usage_is_enabled();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return false;
		}
	}
}
