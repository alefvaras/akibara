<?php
/**
 * Contrato compartido del proveedor ETA. OrderPreventa y EstimatedDelivery
 * dependen de esta interfaz, evitando una dependencia circular y permitiendo
 * dobles de prueba sin heredar de DateCalculator.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Contracts;

use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Provider de ETA, slug de país y display name de país para un producto.
 *
 * @since 1.14.0
 */
interface EtaProvider {

	/**
	 * Calcula la ETA del producto. Retorna null si no aplica (no onbackorder,
	 * disabled, etc.).
	 *
	 * @param WC_Product $product Producto.
	 * @return EtaResult|null
	 */
	public function calculate( WC_Product $product ): ?EtaResult;

	/**
	 * Devuelve el slug del término `pa_pais` del producto (e.g., `argentina`,
	 * `espana`) o null si el producto no tiene país asignado.
	 *
	 * @param WC_Product $product Producto.
	 * @return string|null
	 */
	public function resolve_country( WC_Product $product ): ?string;

	/**
	 * Devuelve el display name del país para un slug (e.g., 'Argentina',
	 * 'España'). Null si no se puede resolver. Source of truth: `$term->name`
	 * del taxonomy `pa_pais`.
	 *
	 * @param string $slug Slug del término.
	 * @return string|null
	 */
	public function get_country_name( string $slug ): ?string;
}
