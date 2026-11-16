<?php
/**
 * Módulo principal Akibara Pricing & Backorder Matrix.
 *
 * Orquesta:
 * - Hooks reactivos de stock/precio y exclusión de metas internos en duplicación (`ReactiveStockPriceHooks`).
 * - Panel administrativo de la Matriz de Precios (`PricingMatrixAdminPage`).
 * - Comandos WP-CLI (`PricingMatrixCli` -> `wp akb-pricing`).
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 */
final class PricingMatrix {

	/**
	 * Registra todos los componentes del módulo.
	 *
	 * @return void
	 */
	public function register(): void {
		// Asegurar carga de clases con alias globales (\AKB_Batch_Guard).
		class_exists( BatchGuard::class );

		( new ReactiveStockPriceHooks() )->register();

		if ( is_admin() ) {
			( new PricingMatrixAdminPage() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new PricingMatrixCli() )->register();
		}
	}
}
