<?php
/**
 * Registro de adaptadores opcionales para OrderPreventa.
 *
 * Mantiene las dependencias de terceros fuera del núcleo del módulo. Otros
 * plugins pueden integrar capacidades de fulfillment usando los filtros y
 * acciones `akb_order_preventa_*` sin modificar OrderPreventa.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Integrations;

use Akibara\Business\Integrations\Bluex\OrderPreventaAdapter as BluexOrderPreventaAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Conecta adaptadores que están fuera del núcleo de preventa.
 *
 * @since 1.87.0
 */
final class OrderPreventaIntegrations {

	public function register(): void {
		( new BluexOrderPreventaAdapter() )->register();
	}
}