<?php
/**
 * Define el estado comercial de los productos a partir del stock de WooCommerce.
 *
 * Modelo estrictamente binario:
 * - Disponible: instock en bodega.
 * - Agotado: outofstock no comprable.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Stock;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Resolvedor puro del estado comercial. Estático, sin hooks, testeable directo
 * (mismo carácter que StockStatus). El reloj es inyectable por parámetro para
 * que los tests de la degradación sean deterministas.
 *
 * @since 1.109.0
 * @api
 */
final class Availability {

	/* --- Estados comerciales --- */

	public const DISPONIBLE = 'disponible';
	public const AGOTADO    = 'agotado';

	public const LABELS = array(
		self::DISPONIBLE => 'Disponible',
		self::AGOTADO    => 'Agotado',
	);

	public const MAX_PALABRA = 24;

	public static function palabra( string $state ): string {
		return self::LABELS[ $state ] ?? self::LABELS[ self::AGOTADO ];
	}

	public static function of( WC_Product $product, ?int $now = null ): string {
		unset( $now );
		return StockStatus::IN_STOCK === StockStatus::of( $product ) ? self::DISPONIBLE : self::AGOTADO;
	}

	/**
	 * Palabra visible del estado comercial — la ÚNICA vía de presentación.
	 *
	 * El filtro `akb_availability_label` es el punto de override del vocabulario
	 * (heredero de `akb_stock_status_label`, que solo conocía el stock crudo y
	 * no podía distinguir a-pedido de preventa).
	 *
	 * @param WC_Product $product Producto Woo.
	 * @param int|null   $now     Reloj inyectable (tests).
	 * @return string Palabra visible.
	 */
	public static function label_of( WC_Product $product, ?int $now = null ): string {
		$state = self::of( $product, $now );
		$label = self::palabra( $state );

		/**
		 * Filtra la palabra visible de un estado comercial. Devolvé la palabra a
		 * mostrar para ese estado.
		 *
		 * @since 1.109.0
		 *
		 * @param string $label Palabra resuelta.
		 * @param string $state Estado comercial resuelto (constante de esta clase).
		 */
		return (string) apply_filters(
			'akb_availability_label',
			$label,
			$state
		);
	}
}
